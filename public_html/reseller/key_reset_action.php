<?php
/**
 * Reseller key-reset endpoint.
 *
 * V11 deliberately keeps the response JSON-only for asynchronous requests.
 * Unexpected PHP output is discarded and logged instead of corrupting JSON,
 * which was the cause of the misleading "page connection interrupted" banner.
 */

ob_start();
ini_set('display_errors', '0');

$wantsJson = (
    (isset($_POST['response_format']) && is_string($_POST['response_format']) && $_POST['response_format'] === 'json')
    || (!empty($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);
$clientRequestId = isset($_POST['client_request_id']) && is_string($_POST['client_request_id'])
    ? strtolower(trim($_POST['client_request_id']))
    : '';
if (preg_match('/^[a-f0-9]{32}$/D', $clientRequestId) !== 1) {
    try {
        $clientRequestId = bin2hex(random_bytes(16));
    } catch (Throwable $ignored) {
        $clientRequestId = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);
    }
}

$sendJson = static function (array $payload, int $statusCode = 200) use ($clientRequestId): void {
    $noise = '';
    if (ob_get_level() > 0) {
        $noise = (string) ob_get_clean();
    }
    if (trim($noise) !== '') {
        error_log(
            'Key reset endpoint discarded unexpected output ref=' . $clientRequestId .
            ' bytes=' . strlen($noise) . ' sha256=' . hash('sha256', $noise)
        );
        $payload['response_recovered'] = true;
    }
    $payload['request_id'] = substr((string) ($payload['request_id'] ?? $clientRequestId), 0, 32);
    $payload['server_time'] = (string) ($payload['server_time'] ?? date('Y-m-d H:i:s'));
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($clientRequestId): bool {
    if (!(error_reporting() & $severity)) return false;
    error_log(
        'Key reset PHP warning ref=' . $clientRequestId . ' severity=' . $severity .
        ' file=' . basename($file) . ':' . $line . ' message=' . $message
    );
    // Prevent warnings/notices from being printed before the JSON document.
    return true;
});

register_shutdown_function(static function () use ($wantsJson, $clientRequestId): void {
    $error = error_get_last();
    if (!$wantsJson || !$error || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) ob_end_clean();
    error_log(
        'Key reset fatal ref=' . $clientRequestId . ' type=' . (int) $error['type'] .
        ' file=' . basename((string) $error['file']) . ':' . (int) $error['line'] .
        ' message=' . (string) $error['message']
    );
    if (function_exists('xchetosWriteSystemLog')) {
        xchetosWriteSystemLog('endpoint_fatal', [
            'error_type' => (int) $error['type'],
            'file' => basename((string) $error['file']),
            'line' => (int) $error['line'],
            'message' => (string) $error['message'],
        ], 'error', 'shutdown', $clientRequestId, (int) ($_SESSION['user_id'] ?? 0), 'reseller');
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'success' => false,
        'status' => 'failed',
        'code' => 'server_error',
        'request_id' => $clientRequestId,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

xchetosWriteSystemLog('endpoint_received', [
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'json_mode' => $wantsJson,
    'content_length' => max(0, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0)),
], 'info', 'request', $clientRequestId, (int) ($_SESSION['user_id'] ?? 0), 'reseller');

if ($wantsJson) {
    $authenticated = isLoggedIn();
    if (!$authenticated && function_exists('attemptRememberedLogin')) {
        $authenticated = attemptRememberedLogin();
    }
    if (!$authenticated || (function_exists('authValidateCurrentSession') && !authValidateCurrentSession(true))) {
        xchetosWriteSystemLog('session_expired', [], 'warning', 'authentication', $clientRequestId, (int) ($_SESSION['user_id'] ?? 0), 'reseller');
        $sendJson([
            'success' => false,
            'status' => 'failed',
            'code' => 'session_expired',
            'request_id' => $clientRequestId,
        ], 401);
    }
} else {
    requireLogin();
}

if (!isReseller()) {
    if ($wantsJson) {
        $sendJson(['success' => false, 'status' => 'failed', 'code' => 'forbidden', 'request_id' => $clientRequestId], 403);
    }
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        $sendJson(['success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => $clientRequestId], 405);
    }
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

if ($wantsJson) {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($csrf) || !validateCsrfToken($csrf)) {
        xchetosWriteSystemLog('csrf_failed', [], 'warning', 'csrf_validation', $clientRequestId, (int) ($_SESSION['user_id'] ?? 0), 'reseller');
        $sendJson(['success' => false, 'status' => 'failed', 'code' => 'csrf_failed', 'request_id' => $clientRequestId], 403);
    }
} else {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($csrf) || !validateCsrfToken($csrf)) {
        while (ob_get_level() > 0) ob_end_clean();
        $_SESSION['key_reset_flash'] = [
            'success' => false,
            'found' => true,
            'status' => 'failed',
            'code' => 'csrf_failed',
            'request_stage' => 'csrf_validation',
            'provider_attempted' => false,
            'wait_seconds' => 0,
            'key_masked' => '',
            'product_name' => '',
            'request_id' => $clientRequestId,
            'daily_used' => 0,
            'daily_limit' => 0,
            'daily_remaining' => 0,
            'daily_reset_at' => '',
            'key_duration_days' => 0,
            'key_reset_count' => 0,
            'key_reset_limit' => 0,
            'key_reset_remaining' => 0,
            'server_time' => date('Y-m-d H:i:s'),
        ];
        header('Location: key_resets.php', true, 303);
        exit();
    }
}

$actionValue = $_POST['reset_action'] ?? $_POST['action'] ?? '';
$action = is_string($actionValue) ? trim($actionValue) : '';
$provider = isset($_POST['provider']) && is_string($_POST['provider']) ? trim($_POST['provider']) : 'xchetos';
$licenseKey = isset($_POST['license_key']) && is_string($_POST['license_key']) ? $_POST['license_key'] : '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$normalizedForLog = function_exists('xchetosNormalizeLicenseKey') ? xchetosNormalizeLicenseKey($licenseKey) : trim($licenseKey);
xchetosWriteSystemLog('reset_action_received', [
    'action' => $action,
    'provider' => $provider,
    'key_masked' => $normalizedForLog !== '' && function_exists('xchetosMaskKey') ? xchetosMaskKey($normalizedForLog) : '',
    'key_length' => strlen($normalizedForLog),
], 'info', 'dispatch', $clientRequestId, $userId, 'reseller');

try {
    if ($action === 'reset_key' && trim($licenseKey) !== '') {
        $result = keyResetResellerKey($userId, $provider, $licenseKey, $clientRequestId);
    } elseif ($action === 'reset_key') {
        // Compatibility with the older per-order reset buttons.
        $source = isset($_POST['source']) && is_string($_POST['source']) ? trim($_POST['source']) : '';
        $recordId = $_POST['record_id'] ?? '';
        if (!is_scalar($recordId)) {
            $result = ['success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => $clientRequestId];
        } else {
            $result = keyResetOwnedKey($userId, $provider, $source, (string) $recordId);
        }
    } else {
        $result = ['success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => $clientRequestId];
    }
} catch (Throwable $error) {
    error_log(
        'Key reset action exception ref=' . $clientRequestId .
        ' type=' . get_class($error) . ' message=' . $error->getMessage() .
        ' file=' . basename($error->getFile()) . ':' . $error->getLine()
    );
    xchetosWriteSystemLog('endpoint_exception', [
        'exception_type' => get_class($error),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
        'message' => $error->getMessage(),
    ], 'error', 'dispatch', $clientRequestId, $userId, 'reseller');
    $result = [
        'success' => false,
        'status' => 'failed',
        'code' => 'server_error',
        'request_id' => $clientRequestId,
        'provider_attempted' => false,
    ];
}

$dailyUsed = max(0, (int) ($result['daily_used'] ?? 0));
$dailyLimit = max(0, (int) ($result['daily_limit'] ?? 0));
$dailyRemaining = array_key_exists('daily_remaining', $result)
    ? max(0, (int) $result['daily_remaining'])
    : ($dailyLimit > 0 ? max(0, $dailyLimit - $dailyUsed) : 0);
$keyResetCount = max(0, (int) ($result['key_reset_count'] ?? 0));
$keyResetLimit = max(0, (int) ($result['key_reset_limit'] ?? 0));
$keyResetRemaining = array_key_exists('key_reset_remaining', $result)
    ? max(0, (int) $result['key_reset_remaining'])
    : ($keyResetLimit > 0 ? max(0, $keyResetLimit - $keyResetCount) : 0);

$payload = [
    'success' => !empty($result['success']),
    'found' => array_key_exists('found', $result) ? !empty($result['found']) : true,
    'status' => substr((string) ($result['status'] ?? 'failed'), 0, 16),
    'code' => substr((string) ($result['code'] ?? 'provider_unknown'), 0, 64),
    'request_stage' => substr((string) ($result['request_stage'] ?? ''), 0, 64),
    'provider_attempted' => !empty($result['provider_attempted']),
    'wait_seconds' => max(0, (int) ($result['wait_seconds'] ?? 0)),
    'key_masked' => substr((string) ($result['key_masked'] ?? ''), 0, 128),
    'product_name' => substr((string) ($result['product_name'] ?? ''), 0, 255),
    'request_id' => substr((string) ($result['request_id'] ?? $clientRequestId), 0, 32),
    'daily_used' => $dailyUsed,
    'daily_limit' => $dailyLimit,
    'daily_remaining' => $dailyRemaining,
    'daily_reset_at' => substr((string) ($result['daily_reset_at'] ?? ''), 0, 32),
    'key_duration_days' => max(0, (int) ($result['key_duration_days'] ?? 0)),
    'key_reset_count' => $keyResetCount,
    'key_reset_limit' => $keyResetLimit,
    'key_reset_remaining' => $keyResetRemaining,
    'server_time' => date('Y-m-d H:i:s'),
];

xchetosWriteSystemLog('endpoint_response', [
    'success' => $payload['success'],
    'status' => $payload['status'],
    'result_code' => $payload['code'],
    'request_stage' => $payload['request_stage'],
    'provider_attempted' => $payload['provider_attempted'],
], $payload['success'] ? 'info' : ($payload['status'] === 'unknown' ? 'warning' : 'error'),
    $payload['request_stage'] !== '' ? $payload['request_stage'] : 'response', $clientRequestId, $userId, 'reseller');

if ($wantsJson) {
    $httpCode = $payload['status'] === 'processing' ? 202 : ($payload['code'] === 'server_error' ? 500 : 200);
    $sendJson($payload, $httpCode);
}

while (ob_get_level() > 0) ob_end_clean();
$_SESSION['key_reset_flash'] = $payload;
header('Location: key_resets.php', true, 303);
exit();
