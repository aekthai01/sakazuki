<?php
/**
 * VIPSTORE provider adapter for Store Bridge.
 *
 * The encrypted supplier credential contains JSON only, for example:
 * {"email":"account@example.com","password":"...","currency":"USDT","local_rate":33.25}
 * or, when the supplier API price unit differs from the local Store Bridge
 * currency, include an explicit local_rate (local currency per supplier unit).
 *
 * Credentials, Bearer tokens and PHPSESSID values are intentionally never
 * returned to Store Bridge diagnostics or written to application logs.
 */

function supplierBridgeVipstoreParseCredential(string $secret): array
{
    $decoded = json_decode($secret, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'code' => 'vipstore_credential_invalid', 'message' => 'VIPSTORE credential must be valid JSON'];
    }
    $email = trim((string) ($decoded['email'] ?? ''));
    $password = (string) ($decoded['password'] ?? '');
    $currency = strtoupper(trim((string) ($decoded['currency'] ?? '')));
    $localRateRaw = $decoded['local_rate'] ?? null;
    if ($email === '' || strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'code' => 'vipstore_email_invalid', 'message' => 'VIPSTORE credential email is invalid'];
    }
    if ($password === '' || strlen($password) > 500) {
        return ['success' => false, 'code' => 'vipstore_password_invalid', 'message' => 'VIPSTORE credential password is invalid'];
    }
    if (!preg_match('/^[A-Z]{3,5}$/', $currency)) {
        return ['success' => false, 'code' => 'vipstore_currency_required', 'message' => 'VIPSTORE credential must declare a 3-5 letter currency code'];
    }
    $localCurrency = function_exists('storeBridgeCurrency') ? strtoupper(trim(storeBridgeCurrency())) : '';
    if ($localCurrency === '') $localCurrency = $currency;
    $localRate = 1.0;
    if (!hash_equals($localCurrency, $currency)) {
        if (!is_numeric($localRateRaw) || !is_finite((float) $localRateRaw) || (float) $localRateRaw <= 0 || (float) $localRateRaw > 1000000000) {
            return [
                'success' => false,
                'code' => 'vipstore_conversion_rate_required',
                'message' => 'VIPSTORE currency differs from the website currency; a positive local_rate is required',
            ];
        }
        $localRate = (float) $localRateRaw;
    }
    return [
        'success' => true,
        'email' => $email,
        'password' => $password,
        'source_currency' => $currency,
        'currency' => $localCurrency,
        'local_rate' => $localRate,
    ];
}

function supplierBridgeVipstoreResolveEndpoint(string $endpoint): array
{
    $normalized = function_exists('storeBridgeNormalizeExternalHttpsUrl')
        ? storeBridgeNormalizeExternalHttpsUrl($endpoint, 'VIPSTORE endpoint')
        : ['success' => false, 'message' => 'Endpoint validation unavailable'];
    if (empty($normalized['success'])) {
        return ['success' => false, 'code' => 'endpoint_invalid', 'message' => 'VIPSTORE endpoint must be a public HTTPS URL'];
    }
    $value = (string) ($normalized['value'] ?? '');
    $parts = parse_url($value);
    if (!is_array($parts)) return ['success' => false, 'code' => 'endpoint_invalid', 'message' => 'VIPSTORE endpoint is invalid'];
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($scheme !== 'https' || $port !== 443 || !in_array($host, ['vipstore.web.id', 'www.vipstore.web.id'], true)) {
        return ['success' => false, 'code' => 'vipstore_endpoint_host_invalid', 'message' => 'VIPSTORE provider is restricted to vipstore.web.id over HTTPS'];
    }
    $resolved = function_exists('storeBridgeResolvePublicHttpsTarget')
        ? storeBridgeResolvePublicHttpsTarget($value, 'VIPSTORE endpoint')
        : ['success' => false, 'message' => 'DNS validation unavailable'];
    if (empty($resolved['success'])) {
        return [
            'success' => false,
            'code' => (string) ($resolved['code'] ?? 'endpoint_dns_invalid'),
            'message' => (string) ($resolved['message'] ?? 'VIPSTORE endpoint DNS validation failed'),
        ];
    }
    $ips = array_values(array_filter(
        (array) ($resolved['ips'] ?? []),
        static fn($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
    ));
    if ($ips === []) return ['success' => false, 'code' => 'dns_resolution_failed', 'message' => 'VIPSTORE endpoint has no usable public IP'];
    return [
        'success' => true,
        'origin' => 'https://' . $host,
        'host' => $host,
        'ip' => $ips[0],
    ];
}

function supplierBridgeVipstoreMessage(array $data, string $fallback = ''): string
{
    foreach (['message', 'error', 'detail'] as $field) {
        if (!isset($data[$field]) || !is_scalar($data[$field])) continue;
        $value = trim((string) $data[$field]);
        if ($value !== '') {
            return function_exists('storeBridgeDiagnosticSanitizeMessage')
                ? storeBridgeDiagnosticSanitizeMessage($value, 500)
                : substr($value, 0, 500);
        }
    }
    return $fallback;
}

function supplierBridgeVipstoreRawRequest(
    $ch,
    string $url,
    string $method,
    ?array $body,
    array $headers,
    string $resolveEntry,
    int $connectTimeout,
    int $requestTimeout
): array {
    $response = '';
    $tooLarge = false;
    $method = strtoupper($method);
    $headers = array_values(array_filter(array_map('strval', $headers), static fn(string $v): bool => trim($v) !== ''));
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => max(2, min(30, $connectTimeout)),
        CURLOPT_TIMEOUT => max(3, min(120, $requestTimeout)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROXY => '',
        CURLOPT_HEADER => false,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_RESOLVE => [$resolveEntry],
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    if ($method === 'POST') {
        $json = json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return ['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'request_encoding_failed', 'error' => 'Unable to encode VIPSTORE request', 'data' => null];
        }
        $options[CURLOPT_HTTPGET] = false;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    } else {
        $options[CURLOPT_POST] = false;
        $options[CURLOPT_HTTPGET] = true;
        $options[CURLOPT_POSTFIELDS] = null;
    }
    curl_setopt_array($ch, $options);
    if (function_exists('configureBoundedCurlResponse')) {
        configureBoundedCurlResponse($ch, $response, $tooLarge, defined('STORE_BRIDGE_MAX_RESPONSE') ? STORE_BRIDGE_MAX_RESPONSE : 2097152);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $executed = curl_exec($ch);
    if (!function_exists('configureBoundedCurlResponse')) {
        $response = is_string($executed) ? $executed : '';
        $executed = $executed !== false;
    }
    $curlNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $info = curl_getinfo($ch);
    $httpCode = is_array($info) ? max(0, (int) ($info['http_code'] ?? 0)) : 0;
    if ($executed === false || $curlNo !== 0 || $tooLarge) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'transport_error' => true,
            'error_code' => $tooLarge ? 'response_too_large' : 'transport_error',
            'error' => $tooLarge ? 'VIPSTORE response exceeded limit' : ('VIPSTORE network error ' . $curlNo . ': ' . $curlError),
            'data' => null,
        ];
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $httpCode, 'transport_error' => false, 'error_code' => 'invalid_json', 'error' => 'VIPSTORE returned invalid JSON', 'data' => null];
    }
    $declaredFailure = (($data['success'] ?? null) === false) || (($data['ok'] ?? null) === false);
    $ok = $httpCode >= 200 && $httpCode < 300 && !$declaredFailure;
    $code = '';
    if (isset($data['code']) && is_scalar($data['code'])) $code = strtolower(trim((string) $data['code']));
    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'transport_error' => false,
        'error_code' => $ok ? '' : ($code !== '' ? $code : 'vipstore_rejected'),
        'error' => $ok ? '' : supplierBridgeVipstoreMessage($data, 'VIPSTORE rejected the request'),
        'data' => $data,
    ];
}

function supplierBridgeVipstoreExtractProducts(array $data): array
{
    $candidates = [];
    if (isset($data['products']) && is_array($data['products'])) $candidates[] = $data['products'];
    if (isset($data['data']['products']) && is_array($data['data']['products'])) $candidates[] = $data['data']['products'];
    if (isset($data['data']) && is_array($data['data'])) $candidates[] = $data['data'];
    $candidates[] = $data;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $isList = function_exists('storeBridgeArrayIsList') ? storeBridgeArrayIsList($candidate) : array_keys($candidate) === range(0, count($candidate) - 1);
        if ($isList) return $candidate;
    }
    return [];
}

function supplierBridgeVipstoreFindProduct(array $products, string $productId): ?array
{
    foreach ($products as $item) {
        if (!is_array($item)) continue;
        $id = trim((string) ($item['id'] ?? $item['product_id'] ?? $item['remote_product_id'] ?? ''));
        if ($id !== '' && hash_equals($productId, $id)) return $item;
    }
    return null;
}

function supplierBridgeVipstoreNormalizeProducts(array $products, string $currency, float $localRate = 1.0): array
{
    $normalized = [];
    $localRate = max(0.00000001, $localRate);
    foreach ($products as $item) {
        if (!is_array($item)) continue;
        $id = trim((string) ($item['id'] ?? $item['product_id'] ?? ''));
        if ($id === '') continue;
        $row = $item;
        $row['product_id'] = $id;
        $row['remote_product_id'] = $id;

        $rawName = trim((string) ($row['name'] ?? $row['product_name'] ?? ''));
        $explicitDuration = trim((string) ($row['duration'] ?? $row['variant'] ?? ''));
        $explicitGroup = trim((string) ($row['source_product_id'] ?? $row['group_id'] ?? $row['parent_id'] ?? ''));
        if ($explicitDuration === '' && $rawName !== ''
            && preg_match('/^(.*?)[\s\-_]+(\d+(?:\.\d+)?\s*(?:minutes?|mins?|hours?|hrs?|days?|weeks?|months?|years?)|lifetime)$/iu', $rawName, $m)) {
            $baseName = trim((string) ($m[1] ?? ''));
            $derivedDuration = trim((string) ($m[2] ?? ''));
            if ($baseName !== '' && $derivedDuration !== '') {
                $row['name'] = $baseName;
                $row['duration'] = $derivedDuration;
                $row['variant'] = $derivedDuration;
                if ($explicitGroup === '') {
                    $row['source_product_id'] = 'name:' . substr(hash('sha256', function_exists('mb_strtolower') ? mb_strtolower($baseName, 'UTF-8') : strtolower($baseName)), 0, 24);
                }
            }
        }
        if (!isset($row['source_product_id']) || trim((string) $row['source_product_id']) === '') {
            $row['source_product_id'] = $explicitGroup !== '' ? $explicitGroup : $id;
        }
        if (!isset($row['variant']) && isset($row['duration'])) $row['variant'] = $row['duration'];
        foreach (['price','api_cost','unit_price','suggested_user_price','source_price_user','suggested_reseller_price','source_price_reseller'] as $priceField) {
            if (isset($row[$priceField]) && is_numeric($row[$priceField])) {
                $row[$priceField] = round((float) $row[$priceField] * $localRate, 2);
            }
        }

        // Current VIPSTORE product-list payloads expose sellable key stock as
        // availableCodes (for example AORUS 6 hours / id 336). Normalize that
        // supplier-native field into Store Bridge's generic stock names so both
        // catalogue sync and exact inventory checks consume the same snapshot.
        $stock = supplierBridgeVipstoreExtractStock($row);
        if ($stock !== null) {
            $row['stock'] = $stock;
            $row['available_stock'] = $stock;
        }

        $row['currency'] = $currency;
        $normalized[] = $row;
    }
    return $normalized;
}

function supplierBridgeVipstoreExtractStock(array $data): ?int
{
    $payloads = [$data];
    if (isset($data['data']) && is_array($data['data'])) $payloads[] = $data['data'];
    foreach ($payloads as $payload) {
        foreach ([
            'availableCodes',
            'available_codes',
            'stock',
            'availableStock',
            'available_stock',
            'stockCount',
            'stock_count',
            'quantity',
            'count',
        ] as $field) {
            if (isset($payload[$field]) && is_numeric($payload[$field])) return max(0, (int) $payload[$field]);
        }
    }
    return null;
}

function supplierBridgeVipstoreExtractCodes(array $data): array
{
    $candidates = [$data['codes'] ?? null, $data['data']['codes'] ?? null, $data['keys'] ?? null, $data['data']['keys'] ?? null];
    $out = [];
    foreach ($candidates as $candidate) {
        if (is_string($candidate)) $candidate = preg_split('/\r\n|\r|\n/', $candidate) ?: [];
        if (!is_array($candidate)) continue;
        foreach ($candidate as $value) {
            if (is_array($value)) $value = $value['code'] ?? $value['key'] ?? $value['key_code'] ?? null;
            if (!is_scalar($value)) continue;
            $value = trim((string) $value);
            if ($value !== '' && strlen($value) <= 5000 && !in_array($value, $out, true)) $out[] = $value;
        }
        if ($out !== []) break;
    }
    return $out;
}

function supplierBridgeVipstoreLocalUserIsAdmin(int $userId): bool
{
    global $conn;
    if ($userId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $stmt = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return strtolower(trim((string) ($row['role'] ?? ''))) === 'admin';
}

function supplierBridgeVipstoreDefinitiveFailure(string $code, string $message, int $httpCode = 409): array
{
    return [
        'ok' => false,
        'http_code' => $httpCode,
        'transport_error' => false,
        'order_not_created_proven' => true,
        'error_code' => $code,
        'error' => $message,
        'data' => [
            'success' => false,
            'order_created' => false,
            'definitive_failure' => true,
            'code' => $code,
            'message' => $message,
        ],
    ];
}

function supplierBridgeVipstoreNoOrderFailureFrom(array $result): array
{
    // This marker is private to the provider adapter. It is added only before
    // /purchase/create is attempted, including preflight transport failures.
    $result['order_not_created_proven'] = true;
    return $result;
}

function supplierBridgeVipstoreApiRequest(
    array $connection,
    string $action,
    string $method,
    array $payload,
    string $secret,
    int $connectTimeout,
    int $requestTimeout
): array {
    if ($action === 'order_status') {
        return ['ok' => false, 'http_code' => 501, 'transport_error' => false, 'error_code' => 'vipstore_reconciliation_unavailable', 'error' => 'VIPSTORE purchase history endpoint has not been verified; this order requires manual review', 'data' => null];
    }
    if (!function_exists('curl_init')) {
        return supplierBridgeVipstoreDefinitiveFailure('curl_unavailable', 'PHP cURL is unavailable');
    }
    $credential = supplierBridgeVipstoreParseCredential($secret);
    if (empty($credential['success'])) {
        return supplierBridgeVipstoreDefinitiveFailure(
            (string) ($credential['code'] ?? 'vipstore_credential_invalid'),
            (string) ($credential['message'] ?? 'VIPSTORE credential is invalid')
        );
    }
    $endpoint = supplierBridgeVipstoreResolveEndpoint((string) ($connection['endpoint_url'] ?? ''));
    if (empty($endpoint['success'])) {
        return supplierBridgeVipstoreDefinitiveFailure(
            (string) ($endpoint['code'] ?? 'endpoint_invalid'),
            (string) ($endpoint['message'] ?? 'VIPSTORE endpoint is invalid')
        );
    }
    $origin = (string) $endpoint['origin'];
    $host = (string) $endpoint['host'];
    $ip = (string) $endpoint['ip'];
    $resolveIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
    $resolveEntry = $host . ':443:' . $resolveIp;
    $currency = (string) $credential['currency'];
    $sourceCurrency = (string) ($credential['source_currency'] ?? $currency);
    $localRate = (float) ($credential['local_rate'] ?? 1.0);

    $ch = curl_init();
    if ($ch === false) {
        return supplierBridgeVipstoreNoOrderFailureFrom([
            'ok' => false,
            'http_code' => 0,
            'transport_error' => true,
            'error_code' => 'curl_init_failed',
            'error' => 'Unable to initialize VIPSTORE request',
            'data' => null,
        ]);
    }

    $baseHeaders = ['Accept: application/json', 'User-Agent: Blackup-StoreBridge/' . (defined('STORE_BRIDGE_VERSION') ? STORE_BRIDGE_VERSION : '1')];
    $login = supplierBridgeVipstoreRawRequest(
        $ch,
        $origin . '/backend/api/auth/login',
        'POST',
        ['email' => $credential['email'], 'password' => $credential['password']],
        array_merge($baseHeaders, ['Content-Type: application/json']),
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 12)
    );
    if (empty($login['ok']) || !is_array($login['data'] ?? null)) {
        curl_close($ch);
        return supplierBridgeVipstoreNoOrderFailureFrom([
            'ok' => false,
            'http_code' => (int) ($login['http_code'] ?? 0),
            'transport_error' => !empty($login['transport_error']),
            'error_code' => (string) ($login['error_code'] ?? 'vipstore_login_failed'),
            'error' => (string) ($login['error'] ?? 'VIPSTORE login failed'),
            'data' => ['success' => false, 'code' => 'vipstore_login_failed', 'message' => 'VIPSTORE login failed'],
        ]);
    }
    $loginData = $login['data'];
    $user = isset($loginData['user']) && is_array($loginData['user']) ? $loginData['user'] : [];
    $supplierUserId = max(0, (int) ($user['id'] ?? 0));
    $bearer = trim((string) ($user['apiKey'] ?? ''));
    if ($supplierUserId < 1 || $bearer === '' || !empty($user['isSuspended'])) {
        curl_close($ch);
        $message = !empty($user['isSuspended']) ? 'VIPSTORE account is suspended' : 'VIPSTORE login response is missing account authorization data';
        return supplierBridgeVipstoreDefinitiveFailure(
            !empty($user['isSuspended']) ? 'vipstore_account_suspended' : 'vipstore_login_response_invalid',
            $message,
            (int) ($login['http_code'] ?? 200)
        );
    }
    $authHeaders = array_merge($baseHeaders, ['Authorization: Bearer ' . $bearer]);
    $finish = static function (array $result) use ($ch, $origin, $authHeaders, $resolveEntry, $connectTimeout): array {
        try {
            supplierBridgeVipstoreRawRequest($ch, $origin . '/backend/api/auth/logout.php', 'POST', [], array_merge($authHeaders, ['Content-Type: application/json']), $resolveEntry, $connectTimeout, 3);
        } catch (Throwable $ignored) {
        }
        curl_close($ch);
        return $result;
    };

    $accountRequest = static function () use ($ch, $origin, $supplierUserId, $authHeaders, $resolveEntry, $connectTimeout, $requestTimeout, $currency, $sourceCurrency, $localRate): array {
        $api = supplierBridgeVipstoreRawRequest(
            $ch,
            $origin . '/backend/api/users/get.php?user_id=' . rawurlencode((string) $supplierUserId),
            'GET',
            null,
            $authHeaders,
            $resolveEntry,
            $connectTimeout,
            min($requestTimeout, 8)
        );
        if (empty($api['ok']) || !is_array($api['data'] ?? null)) return $api;
        $data = $api['data'];
        $account = isset($data['user']) && is_array($data['user']) ? $data['user'] : (isset($data['data']) && is_array($data['data']) ? $data['data'] : $data);
        $id = max(0, (int) ($account['id'] ?? 0));
        if ($id !== $supplierUserId) {
            return ['ok' => false, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_account_mismatch', 'error' => 'VIPSTORE account response does not match the logged-in account', 'data' => null];
        }
        if (!empty($account['isSuspended'])) {
            return ['ok' => false, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_account_suspended', 'error' => 'VIPSTORE account is suspended', 'data' => null];
        }
        $balance = $account['balance'] ?? null;
        if (!is_numeric($balance)) {
            return ['ok' => false, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_balance_invalid', 'error' => 'VIPSTORE account balance is missing or invalid', 'data' => null];
        }
        return ['ok' => true, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => '', 'error' => '', 'data' => ['success' => true, 'user_id' => $supplierUserId, 'balance' => round((float) $balance * $localRate, 2), 'currency' => $currency, 'source_currency' => $sourceCurrency, 'local_rate' => $localRate, 'is_suspended' => false]];
    };

    $productsRequest = static function () use ($ch, $origin, $authHeaders, $resolveEntry, $connectTimeout, $requestTimeout, $currency, $sourceCurrency, $localRate): array {
        $api = supplierBridgeVipstoreRawRequest(
            $ch,
            $origin . '/backend/api/products/list?lean=1',
            'GET',
            null,
            $authHeaders,
            $resolveEntry,
            $connectTimeout,
            min($requestTimeout, 10)
        );
        if (empty($api['ok']) || !is_array($api['data'] ?? null)) return $api;
        $products = supplierBridgeVipstoreExtractProducts($api['data']);
        if ($products === []) {
            return ['ok' => false, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_products_missing', 'error' => 'VIPSTORE product catalogue is empty or unrecognized', 'data' => null];
        }
        return ['ok' => true, 'http_code' => (int) ($api['http_code'] ?? 200), 'transport_error' => false, 'error_code' => '', 'error' => '', 'data' => ['success' => true, 'products' => supplierBridgeVipstoreNormalizeProducts($products, $currency, $localRate), 'currency' => $currency, 'source_currency' => $sourceCurrency, 'local_rate' => $localRate]];
    };

    if ($action === 'balance' || $action === 'account') return $finish($accountRequest());
    if ($action === 'products') return $finish($productsRequest());

    if ($action === 'inventory') {
        $productId = trim((string) ($payload['product_id'] ?? ''));
        if ($productId === '') return $finish(['ok' => false, 'http_code' => 400, 'transport_error' => false, 'error_code' => 'invalid_product_id', 'error' => 'VIPSTORE product id is required', 'data' => null]);
        $catalogue = $productsRequest();
        if (empty($catalogue['ok']) || !is_array($catalogue['data']['products'] ?? null)) return $finish($catalogue);
        $product = supplierBridgeVipstoreFindProduct($catalogue['data']['products'], $productId);
        if (!$product) return $finish(['ok' => false, 'http_code' => 404, 'transport_error' => false, 'error_code' => 'product_not_found', 'error' => 'VIPSTORE product was not found', 'data' => null]);
        $price = $product['price'] ?? $product['api_cost'] ?? $product['unit_price'] ?? null;
        // The lean catalogue is authoritative for current price, but stock is
        // intentionally fetched from the dedicated api-stock endpoint below.
        // Do not require stock to be present in the lean product shape.
        if (!is_numeric($price)) {
            return $finish(['ok' => false, 'http_code' => 200, 'transport_error' => false, 'error_code' => 'vipstore_price_invalid', 'error' => 'VIPSTORE product is missing current price', 'data' => null]);
        }
        $stock = supplierBridgeVipstoreExtractStock($product);
        if ($stock === null) {
            return $finish([
                'ok' => false,
                'http_code' => 200,
                'transport_error' => false,
                'error_code' => 'vipstore_stock_response_invalid',
                'error' => 'VIPSTORE product list is missing availableCodes/stock',
                'data' => null,
            ]);
        }
        $status = trim((string) ($product['status'] ?? ($stock > 0 ? 'available' : 'out_of_stock')));
        return $finish([
            'ok' => true,
            'http_code' => 200,
            'transport_error' => false,
            'error_code' => '',
            'error' => '',
            'data' => [
                'success' => true,
                'product_id' => $productId,
                'stock' => $stock,
                'status' => $status,
                'price' => round((float) $price, 2),
                'currency' => $currency,
                'revision' => substr(hash('sha256', $productId . '|' . $stock . '|' . round((float) $price, 4) . '|' . $status), 0, 40),
            ],
        ]);
    }

    if ($action !== 'order' || strtoupper($method) !== 'POST') {
        return $finish(['ok' => false, 'http_code' => 400, 'transport_error' => false, 'error_code' => 'invalid_api_action', 'error' => 'Unsupported VIPSTORE action', 'data' => null]);
    }

    $purchaseMode = strtolower(trim((string) ($connection['purchase_mode'] ?? 'disabled')));
    if (!in_array($purchaseMode, ['disabled', 'test', 'live'], true)) $purchaseMode = 'disabled';
    if ($purchaseMode === 'disabled') {
        return $finish(supplierBridgeVipstoreDefinitiveFailure('vipstore_purchase_disabled', 'VIPSTORE purchasing is disabled for this connection'));
    }
    $originUserId = max(0, (int) ($payload['origin_user_id'] ?? 0));
    if ($purchaseMode === 'test' && !supplierBridgeVipstoreLocalUserIsAdmin($originUserId)) {
        return $finish(supplierBridgeVipstoreDefinitiveFailure('vipstore_admin_test_only', 'VIPSTORE is in admin test mode'));
    }
    $productId = trim((string) ($payload['product_id'] ?? ''));
    $quantity = max(1, min(100, (int) ($payload['quantity'] ?? 0)));
    $maxSupplierCost = $payload['_max_supplier_cost'] ?? null;
    if ($productId === '') return $finish(supplierBridgeVipstoreDefinitiveFailure('invalid_product_id', 'VIPSTORE product id is required', 400));
    if (!is_numeric($maxSupplierCost) || (float) $maxSupplierCost <= 0) {
        return $finish(supplierBridgeVipstoreDefinitiveFailure('vipstore_cost_guard_missing', 'VIPSTORE max supplier cost is required before purchase'));
    }
    $maxSupplierCost = round((float) $maxSupplierCost, 2);

    $account = $accountRequest();
    if (empty($account['ok']) || !is_array($account['data'] ?? null)) {
        $code = (string) ($account['error_code'] ?? 'vipstore_account_unavailable');
        $message = (string) ($account['error'] ?? 'VIPSTORE account is unavailable');
        if (!empty($account['transport_error'])) return $finish(supplierBridgeVipstoreNoOrderFailureFrom($account));
        return $finish(supplierBridgeVipstoreDefinitiveFailure($code, $message, max(400, (int) ($account['http_code'] ?? 409))));
    }

    $catalogue = $productsRequest();
    if (empty($catalogue['ok']) || !is_array($catalogue['data']['products'] ?? null)) {
        if (!empty($catalogue['transport_error'])) return $finish(supplierBridgeVipstoreNoOrderFailureFrom($catalogue));
        return $finish(supplierBridgeVipstoreDefinitiveFailure((string) ($catalogue['error_code'] ?? 'vipstore_catalogue_unavailable'), (string) ($catalogue['error'] ?? 'VIPSTORE catalogue is unavailable'), max(400, (int) ($catalogue['http_code'] ?? 409))));
    }
    $product = supplierBridgeVipstoreFindProduct($catalogue['data']['products'], $productId);
    if (!$product) return $finish(supplierBridgeVipstoreDefinitiveFailure('product_not_found', 'VIPSTORE product was not found', 404));
    $price = $product['price'] ?? $product['api_cost'] ?? $product['unit_price'] ?? null;
    if (!is_numeric($price)) return $finish(supplierBridgeVipstoreDefinitiveFailure('vipstore_price_invalid', 'VIPSTORE current price is missing or invalid'));
    $price = round((float) $price, 2);
    if ($price > $maxSupplierCost + 0.00001) {
        return $finish(supplierBridgeVipstoreDefinitiveFailure('supplier_cost_guard_exceeded', 'VIPSTORE current price exceeds max supplier cost'));
    }
    $status = strtolower(trim((string) ($product['status'] ?? '')));
    $explicitDisabled = !empty($product['disabled']) || (array_key_exists('enabled', $product) && !$product['enabled']) || in_array($status, ['disabled', 'inactive', 'unavailable', 'removed'], true);
    if ($explicitDisabled) return $finish(supplierBridgeVipstoreDefinitiveFailure('product_unavailable', 'VIPSTORE product is disabled or unavailable'));

    // VIPSTORE's current list endpoint already carries the authoritative
    // sellable key count as availableCodes. Reuse the same freshly fetched
    // product snapshot for the pre-purchase stock guard instead of calling a
    // legacy/nonexistent api-stock route.
    $stock = supplierBridgeVipstoreExtractStock($product);
    if ($stock === null) {
        return $finish(supplierBridgeVipstoreDefinitiveFailure(
            'vipstore_stock_response_invalid',
            'VIPSTORE product list is missing availableCodes/stock'
        ));
    }
    if ($stock < $quantity) return $finish(supplierBridgeVipstoreDefinitiveFailure('out_of_stock', 'VIPSTORE stock is lower than requested quantity'));
    $supplierBalance = (float) ($account['data']['balance'] ?? 0);
    $expectedTotal = round($price * $quantity, 2);
    if ($supplierBalance + 0.00001 < $expectedTotal) {
        return $finish(supplierBridgeVipstoreDefinitiveFailure('insufficient_supplier_balance', 'VIPSTORE supplier balance is insufficient'));
    }

    // The only non-idempotent upstream request is below. From this point onward
    // no failure is labelled definitive unless a future verified history API can
    // prove the purchase did not commit.
    $purchase = supplierBridgeVipstoreRawRequest(
        $ch,
        $origin . '/backend/api/purchase/create',
        'POST',
        ['user_id' => $supplierUserId, 'product_id' => ctype_digit($productId) ? (int) $productId : $productId, 'quantity' => $quantity],
        array_merge($authHeaders, ['Content-Type: application/json']),
        $resolveEntry,
        $connectTimeout,
        min($requestTimeout, 12)
    );
    if (empty($purchase['ok']) || !is_array($purchase['data'] ?? null)) {
        return $finish($purchase);
    }
    $purchaseData = $purchase['data'];
    $codes = supplierBridgeVipstoreExtractCodes($purchaseData);
    if ($codes !== []) $purchaseData['keys'] = $codes;
    $priceUsed = $purchaseData['price_used'] ?? $purchaseData['data']['price_used'] ?? null;
    if (!is_numeric($priceUsed)) {
        return $finish(['ok' => false, 'http_code' => (int) ($purchase['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_price_used_missing', 'error' => 'VIPSTORE purchase response is missing price_used', 'data' => $purchaseData]);
    }
    $sourcePriceUsed = (float) $priceUsed;
    $priceUsed = round($sourcePriceUsed * $localRate, 2);
    if ($priceUsed < 0 || $priceUsed > $maxSupplierCost + 0.00001) {
        return $finish(['ok' => false, 'http_code' => (int) ($purchase['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'supplier_cost_guard_postpurchase', 'error' => 'VIPSTORE price_used is outside the configured max supplier cost; automatic refund is blocked', 'data' => $purchaseData]);
    }
    $totalDeducted = $purchaseData['total_deducted'] ?? $purchaseData['data']['total_deducted'] ?? null;
    $localTotalDeducted = is_numeric($totalDeducted) ? round((float) $totalDeducted * $localRate, 2) : null;
    if ($localTotalDeducted !== null && $localTotalDeducted > round($maxSupplierCost * $quantity, 2) + 0.00001) {
        return $finish(['ok' => false, 'http_code' => (int) ($purchase['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'supplier_total_guard_postpurchase', 'error' => 'VIPSTORE total_deducted exceeds the configured purchase ceiling; automatic refund is blocked', 'data' => $purchaseData]);
    }
    if (count($codes) !== $quantity) {
        return $finish(['ok' => false, 'http_code' => (int) ($purchase['http_code'] ?? 200), 'transport_error' => false, 'error_code' => 'vipstore_code_count_mismatch', 'error' => 'VIPSTORE delivered code count does not match requested quantity', 'data' => $purchaseData]);
    }
    $purchaseData['success'] = true;
    $purchaseData['status'] = 'success';
    $purchaseData['currency'] = $currency;
    $purchaseData['source_currency'] = $sourceCurrency;
    $purchaseData['local_rate'] = $localRate;
    $purchaseData['price_used'] = $priceUsed;
    if ($localTotalDeducted !== null) $purchaseData['total_deducted'] = $localTotalDeducted;
    if (isset($purchaseData['new_balance']) && is_numeric($purchaseData['new_balance'])) $purchaseData['new_balance'] = round((float) $purchaseData['new_balance'] * $localRate, 2);
    return $finish(['ok' => true, 'http_code' => (int) ($purchase['http_code'] ?? 200), 'transport_error' => false, 'error_code' => '', 'error' => '', 'data' => $purchaseData]);
}
