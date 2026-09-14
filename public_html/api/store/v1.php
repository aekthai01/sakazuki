<?php
require_once __DIR__ . '/../../includes/store_bridge.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestId = '';
try {
    $requestId = 'req_' . substr(bin2hex(random_bytes(12)), 0, 24);
} catch (Throwable $e) {
    $requestId = 'req_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 24);
}
header('X-Request-ID: ' . $requestId);

$requestStartedAt = microtime(true);
$requestTimeline = [];
storeBridgeTimelineMark($requestTimeline, 'request_received', $requestStartedAt, ['method' => $method]);
$requestCompleted = false;
$logClientId = null;
$logAction = 'bootstrap';
$logRequestPayload = [];

register_shutdown_function(static function () use (&$requestCompleted, &$logClientId, &$logAction, &$logRequestPayload, &$requestTimeline, $requestId, $method, $requestStartedAt): void {
    if ($requestCompleted) return;
    $error = error_get_last();
    if (!is_array($error)) return;
    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) return;
    $durationMs = (int) round((microtime(true) - $requestStartedAt) * 1000);
    storeBridgeTimelineMark($requestTimeline, 'php_fatal', $requestStartedAt);
    $ip = function_exists('getClientIp') ? getClientIp() : trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    storeBridgeLogRequest($logClientId, $requestId, $logAction, $ip, $method, 500, [
        'result_code' => 'php_fatal',
        'stage' => 'application_failure',
        'duration_ms' => $durationMs,
        'request_payload' => $logRequestPayload,
        'fatal_error' => $error,
        'timeline' => $requestTimeline,
    ]);
});

$send = static function (array $payload, int $httpCode, ?int $clientId = null, string $action = '', array $operationDiagnostic = []) use ($requestId, $method, $requestStartedAt, &$requestCompleted, &$logClientId, &$logAction, &$logRequestPayload, &$requestTimeline): void {
    $payload['request_id'] = $requestId;
    http_response_code($httpCode);
    $ip = function_exists('getClientIp') ? getClientIp() : trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $effectiveClientId = $clientId !== null && $clientId > 0 ? $clientId : $logClientId;
    $effectiveAction = trim($action) !== '' ? $action : $logAction;
    $resultCode = isset($payload['code']) && is_scalar($payload['code'])
        ? substr(trim((string) $payload['code']), 0, 80)
        : ($httpCode < 400 ? 'ok' : 'http_' . $httpCode);
    $stage = isset($payload['stage']) && is_scalar($payload['stage'])
        ? substr(trim((string) $payload['stage']), 0, 80)
        : storeBridgeRequestDiagnosticStage($effectiveAction, $httpCode, $resultCode);
    storeBridgeTimelineMark($requestTimeline, 'response_ready', $requestStartedAt, [
        'http_code' => $httpCode,
        'result_code' => $resultCode,
        'stage' => $stage,
    ]);
    $durationMs = (int) round((microtime(true) - $requestStartedAt) * 1000);
    $requestCompleted = true;
    storeBridgeLogRequest($effectiveClientId, $requestId, $effectiveAction, $ip, $method, $httpCode, [
        'result_code' => $resultCode,
        'stage' => $stage,
        'duration_ms' => $durationMs,
        'request_payload' => $logRequestPayload,
        'response_payload' => $payload,
        'operation_diagnostic' => $operationDiagnostic,
        'timeline' => $requestTimeline,
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo is_string($json) ? $json : '{"success":false,"message":"Response encoding failed"}';
    exit;
};

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Method not allowed'], 405, null, 'invalid_method');
}

// Read a GET diagnostic action before authentication only so an API client with
// a valid key but a wrong source IP can learn the exact IP this server observed.
// No allowlist rules, key material or account data are exposed in this failure path.
$preAuthAction = '';
if ($method === 'GET' && isset($_GET['action']) && is_scalar($_GET['action'])) {
    $preAuthAction = substr(strtolower(trim((string) $_GET['action'])), 0, 40);
}
if ($preAuthAction !== '') $logAction = $preAuthAction;

storeBridgeTimelineMark($requestTimeline, 'authentication_started', $requestStartedAt);
$auth = storeBridgeAuthenticateRequest();
if (empty($auth['success'])) {
    $authClientId = (int) ($auth['client_id'] ?? 0);
    $authCode = (string) ($auth['code'] ?? 'authentication_failed');
    storeBridgeTimelineMark($requestTimeline, 'authentication_failed', $requestStartedAt, ['code' => $authCode, 'client_id' => $authClientId]);
    $payload = [
        'success' => false,
        'code' => $authCode,
        'message' => (string) ($auth['message'] ?? 'Authentication failed'),
    ];
    if (array_key_exists('order_created', $auth)) $payload['order_created'] = (bool) $auth['order_created'];
    if (array_key_exists('definitive_failure', $auth)) $payload['definitive_failure'] = (bool) $auth['definitive_failure'];

    if ($preAuthAction === 'diagnostic' && $authCode === 'ip_not_allowed' && $authClientId > 0) {
        $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $trusted = function_exists('isTrustedProxyAddress') && isTrustedProxyAddress($remoteAddr);
        $detectedIp = function_exists('getClientIp') ? getClientIp() : $remoteAddr;
        $payload['api_version'] = STORE_BRIDGE_VERSION;
        $payload['stage'] = 'ip_allowlist';
        $payload['authentication'] = [
            // storeBridgeAuthenticateRequest() can only reach ip_not_allowed after
            // the API-key hash matched an active client.
            'api_key_valid' => true,
            'ip_allowlist_match' => false,
        ];
        $payload['network'] = [
            'remote_addr' => $remoteAddr,
            'detected_client_ip' => $detectedIp,
            'remote_addr_is_trusted_proxy' => $trusted,
            'trusted_proxy_source' => function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'unknown',
            'trusted_proxy_rule_count' => function_exists('trustedProxyRules') ? count(trustedProxyRules()) : 0,
            'ip_allowlist_configured' => true,
            'ip_allowlist_match' => false,
            'cf_ray' => substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100),
        ];
        $payload['server_time'] = date(DATE_ATOM);
    }

    $send($payload, (int) ($auth['http_code'] ?? 401), $authClientId > 0 ? $authClientId : null, $preAuthAction === 'diagnostic' ? 'diagnostic' : 'auth');
}
storeBridgeTimelineMark($requestTimeline, 'authentication_completed', $requestStartedAt, ['client_id' => (int) (($auth['client']['id'] ?? 0))]);
$client = $auth['client'];
$clientId = (int) $client['id'];
$logClientId = $clientId;

$input = [];
$rawBody = '';
$contentType = '';
if ($method === 'POST') {
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
        ? max(0, (int) $_SERVER['CONTENT_LENGTH'])
        : 0;
    if ($contentLength > 1024 * 1024) {
        // Reject an oversized declared body before buffering it into PHP memory.
        // Keep the post-read length check below for chunked/missing-length bodies.
        $send(['success' => false, 'code' => 'request_body_too_large', 'message' => 'Request body is too large'], 413, $clientId, 'invalid_body');
    }
    if ($contentType === 'application/json') {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || strlen($rawBody) > 1024 * 1024) {
            $send(['success' => false, 'code' => 'request_body_too_large', 'message' => 'Request body is invalid or too large'], 413, $clientId, 'invalid_body');
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $send(['success' => false, 'code' => 'invalid_json', 'message' => 'Request body must be valid JSON'], 400, $clientId, 'invalid_json');
        }
        $input = $decoded;
    } else {
        $input = is_array($_POST) ? $_POST : [];
    }
}

$rawAction = $method === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? $_GET['action'] ?? '');
$action = is_scalar($rawAction) ? strtolower(trim((string) $rawAction)) : '';
$logAction = $action !== '' ? substr($action, 0, 40) : 'missing_action';
$logRequestPayload = $method === 'GET' ? (is_array($_GET) ? $_GET : []) : $input;
storeBridgeTimelineMark($requestTimeline, 'request_parsed', $requestStartedAt, ['action' => $logAction]);

$actionRate = storeBridgeCheckActionRateLimit($client, $action);
if (empty($actionRate['success'])) {
    storeBridgeTimelineMark($requestTimeline, 'rate_limit_rejected', $requestStartedAt, ['action' => $logAction]);
    $send([
        'success' => false,
        'code' => (string) ($actionRate['code'] ?? 'rate_limited'),
        'message' => (string) ($actionRate['message'] ?? 'Rate limit exceeded'),
    ], (int) ($actionRate['http_code'] ?? 429), $clientId, $action === '' ? 'missing_action' : $action);
}

storeBridgeTimelineMark($requestTimeline, 'rate_limit_checked', $requestStartedAt, ['action' => $logAction]);
storeBridgeTimelineMark($requestTimeline, 'action_dispatch_started', $requestStartedAt, ['action' => $logAction]);

// Cross-site financial fencing is privileged internal traffic. A normal Store
// API key authenticates a reseller client but is intentionally NOT sufficient
// to mutate/read the shared financial ledger. Require an additional HMAC over
// the exact JSON body + action + short-lived timestamp before dispatching any
// shared_* operation. This fails closed if the private shared secret is missing.
$sharedInternalActions = ['shared_claim', 'shared_reserve', 'shared_complete', 'shared_release', 'shared_history_status', 'shared_history_import'];
if (in_array($action, $sharedInternalActions, true)) {
    if ($method !== 'POST') {
        $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Shared ledger requires POST'], 405, $clientId, $action);
    }
    if ($contentType !== 'application/json' || $rawBody === '') {
        $send(['success' => false, 'code' => 'signed_json_required', 'message' => 'Shared ledger requires signed JSON'], 415, $clientId, $action);
    }
    if (!sharedLedgerVerifyHttpSignature($action, $rawBody, (string) ($client['key_hash'] ?? ''))) {
        storeBridgeTimelineMark($requestTimeline, 'shared_auth_failed', $requestStartedAt, ['action' => $logAction]);
        $send(['success' => false, 'code' => 'shared_auth_failed', 'message' => 'Shared ledger authentication failed'], 403, $clientId, $action);
    }
    storeBridgeTimelineMark($requestTimeline, 'shared_auth_checked', $requestStartedAt, ['action' => $logAction]);
}

if ($action === 'products') {
    if ($method !== 'GET') $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Products requires GET'], 405, $clientId, $action);
    try {
        $products = storeBridgeCatalogue($client);
    } catch (Throwable $e) {
        error_log('Store API catalogue failed: ' . $e->getMessage());
        $send(['success' => false, 'code' => 'catalogue_unavailable', 'message' => 'Product catalogue is temporarily unavailable'], 503, $clientId, $action);
    }
    $send([
        'success' => true,
        'api_version' => STORE_BRIDGE_VERSION,
        'currency' => (string) $client['currency'],
        'billing_mode' => storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance'),
        'products' => $products,
        'count' => count($products),
        'generated_at' => date(DATE_ATOM),
    ], 200, $clientId, $action);
}

if ($action === 'inventory') {
    if ($method !== 'GET') $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Inventory requires GET'], 405, $clientId, $action);
    $rawProductId = $_GET['product_id'] ?? $_GET['remote_product_id'] ?? '';
    $productId = is_scalar($rawProductId) ? trim((string) $rawProductId) : '';
    if ($productId === '' || strlen($productId) > 190) {
        $send(['success' => false, 'code' => 'invalid_product_id', 'message' => 'A valid product_id is required'], 422, $clientId, $action);
    }
    try {
        $inventory = storeBridgeProviderInventory($client, $productId);
    } catch (Throwable $e) {
        error_log('Store API inventory failed: ' . $e->getMessage());
        $send(['success' => false, 'code' => 'inventory_unavailable', 'message' => 'Inventory is temporarily unavailable'], 503, $clientId, $action);
    }
    if (empty($inventory['success'])) {
        $payload = [
            'success' => false,
            'code' => (string) ($inventory['code'] ?? 'inventory_unavailable'),
            'message' => (string) ($inventory['message'] ?? 'Inventory is temporarily unavailable'),
        ];
        $send($payload, (int) ($inventory['http_code'] ?? 503), $clientId, $action);
    }
    $send([
        'success' => true,
        'api_version' => STORE_BRIDGE_VERSION,
        'data' => (array) ($inventory['data'] ?? []),
    ], 200, $clientId, $action);
}

if ($action === 'diagnostic') {
    if ($method !== 'GET') $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Diagnostic requires GET'], 405, $clientId, $action);
    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $trusted = function_exists('isTrustedProxyAddress') && isTrustedProxyAddress($remoteAddr);
    $detectedIp = function_exists('getClientIp') ? getClientIp() : $remoteAddr;
    $allowedIps = trim((string) ($client['allowed_ips'] ?? ''));
    $send([
        'success' => true,
        'api_version' => STORE_BRIDGE_VERSION,
        'stage' => 'authenticated',
        'client' => [
            'id' => $clientId,
            'name' => (string) ($client['name'] ?? ''),
            'website_name' => (string) ($client['website_name'] ?? ''),
            'website_url' => (string) ($client['website_url'] ?? ''),
            'status' => (string) ($client['status'] ?? 'active'),
            'billing_mode' => storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance'),
        ],
        'authentication' => [
            'api_key_valid' => true,
            'ip_allowlist_match' => true,
        ],
        'network' => [
            'remote_addr' => $remoteAddr,
            'detected_client_ip' => $detectedIp,
            'remote_addr_is_trusted_proxy' => $trusted,
            'trusted_proxy_source' => function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'unknown',
            'trusted_proxy_rule_count' => function_exists('trustedProxyRules') ? count(trustedProxyRules()) : 0,
            'ip_allowlist_configured' => $allowedIps !== '',
            'ip_allowlist_match' => true,
            'cf_ray' => substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100),
        ],
        'server_time' => date(DATE_ATOM),
    ], 200, $clientId, $action);
}

if ($action === 'balance') {
    if ($method !== 'GET') $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Balance requires GET'], 405, $clientId, $action);
    $balance = storeBridgeClientCurrentBalance($client, false);
    if ($balance === null) {
        $send(['success' => false, 'code' => 'account_inactive', 'message' => 'Billing account is unavailable'], 403, $clientId, $action);
    }
    $send([
        'success' => true,
        'billing_mode' => storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance'),
        'balance' => round((float) $balance, 2),
        'currency' => (string) $client['currency'],
    ], 200, $clientId, $action);
}

if ($action === 'order') {
    if ($method !== 'POST') $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Order requires POST'], 405, $clientId, $action);
    storeBridgeTimelineMark($requestTimeline, 'order_handler_started', $requestStartedAt);
    $result = storeBridgeCreateProviderOrder($client, $input);
    storeBridgeTimelineMark($requestTimeline, 'order_handler_completed', $requestStartedAt, ['success' => !empty($result['success'])]);
    if (empty($result['success'])) {
        $payload = ['success' => false, 'message' => (string) ($result['message'] ?? 'Order failed')];
        if (!empty($result['code'])) $payload['code'] = (string) $result['code'];
        if (array_key_exists('order_created', $result)) $payload['order_created'] = (bool) $result['order_created'];
        if (array_key_exists('definitive_failure', $result)) $payload['definitive_failure'] = (bool) $result['definitive_failure'];
        $send($payload, (int) ($result['http_code'] ?? 500), $clientId, $action, isset($result['diagnostic']) && is_array($result['diagnostic']) ? $result['diagnostic'] : []);
    }
    $send(
        ['success' => true, 'data' => $result['data']],
        (int) ($result['http_code'] ?? 200),
        $clientId,
        $action,
        isset($result['diagnostic']) && is_array($result['diagnostic']) ? $result['diagnostic'] : []
    );
}

if ($action === 'order_status') {
    if (!in_array($method, ['GET', 'POST'], true)) $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Invalid method'], 405, $clientId, $action);
    $payload = $method === 'GET' ? $_GET : $input;
    $result = storeBridgeProviderOrderStatus($client, is_array($payload) ? $payload : []);
    if (empty($result['success'])) {
        $send(['success' => false, 'code' => (string) ($result['code'] ?? 'order_not_found'), 'message' => (string) ($result['message'] ?? 'Order not found')], (int) ($result['http_code'] ?? 404), $clientId, $action);
    }
    $send(['success' => true, 'data' => $result['data']], 200, $clientId, $action);
}

if (in_array($action, ['shared_claim', 'shared_reserve', 'shared_complete', 'shared_release'], true)) {
    if ($method !== 'POST') {
        $send(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Shared ledger requires POST'], 405, $clientId, $action);
    }
    $operation = substr($action, strlen('shared_'));
    $result = storeBridgeSharedClaimAction($clientId, $operation, $input);
    $httpCode = (int) ($result['http_code'] ?? 500);
    $payload = [
        'success' => !empty($result['success']),
        'status' => (string) ($result['status'] ?? ''),
    ];
    if (!empty($result['duplicate'])) $payload['duplicate'] = true;
    if (array_key_exists('new', $result)) $payload['new'] = !empty($result['new']);
    if (array_key_exists('same_owner', $result)) $payload['same_owner'] = !empty($result['same_owner']);
    if (empty($result['success'])) {
        $payload['message'] = (string) ($result['message'] ?? 'Shared ledger request failed');
    }
    $send($payload, $httpCode, $clientId, $action);
}

if ($action === 'shared_history_import') {
    if ($method !== 'POST') {
        $send(['success' => false, 'message' => 'Shared history import requires POST'], 405, $clientId, $action);
    }
    $result = storeBridgeSharedHistoryImportAction($clientId, $input);
    $httpCode = (int) ($result['http_code'] ?? 500);
    $send([
        'success' => !empty($result['success']),
        'namespace' => (string) ($result['namespace'] ?? ''),
        'site_id' => (string) ($result['site_id'] ?? ''),
        'received' => (int) ($result['received'] ?? 0),
        'imported' => (int) ($result['imported'] ?? 0),
        'already_completed' => (int) ($result['already_completed'] ?? 0),
        'recovered_expired_processing' => (int) ($result['recovered_expired_processing'] ?? 0),
        'conflicts' => (int) ($result['conflicts'] ?? 0),
        'items' => (array) ($result['items'] ?? []),
        'message' => (string) ($result['message'] ?? ''),
    ], $httpCode, $clientId, $action);
}

if ($action === 'shared_history_status') {
    if ($method !== 'POST') {
        $send(['success' => false, 'message' => 'Shared history status requires POST'], 405, $clientId, $action);
    }
    $result = storeBridgeSharedHistoryStatusAction($input);
    $httpCode = (int) ($result['http_code'] ?? 500);
    $send([
        'success' => !empty($result['success']),
        'ready' => !empty($result['ready']),
        'site_ids' => (array) ($result['site_ids'] ?? []),
        'completed_site_ids' => (array) ($result['completed_site_ids'] ?? []),
        'missing_site_ids' => (array) ($result['missing_site_ids'] ?? []),
        'message' => (string) ($result['message'] ?? ''),
    ], $httpCode, $clientId, $action);
}

$send(['success' => false, 'code' => $action === '' ? 'missing_action' : 'invalid_action', 'message' => 'Invalid API action'], 400, $clientId, $action === '' ? 'missing_action' : $action);
