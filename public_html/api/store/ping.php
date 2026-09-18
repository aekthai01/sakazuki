<?php
/**
 * Store API network ping.
 *
 * Intentionally does NOT connect to MySQL and does NOT require an API key.
 * If this endpoint itself times out, the failure is before Store API auth/order
 * logic (DNS/TCP/TLS/Cloudflare/WAF/origin routing/PHP availability).
 */
require_once __DIR__ . '/../../includes/security.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo '{"success":false,"code":"method_not_allowed","message":"Use GET or HEAD"}';
    exit;
}

try { $requestId = 'ping_' . substr(bin2hex(random_bytes(10)), 0, 20); }
catch (Throwable $e) { $requestId = 'ping_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 20); }
header('X-Request-ID: ' . $requestId);

$remote = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
$trusted = isTrustedProxyAddress($remote);
$clientIp = substr(trim((string) getClientIp()), 0, 64);
$cfConnecting = $trusted ? substr(trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')), 0, 64) : '';
$forwardedFor = $trusted ? substr(trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), 0, 500) : '';
$xRealIp = $trusted ? substr(trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')), 0, 64) : '';
$requestUri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
$requestPath = @parse_url($requestUri, PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') $requestPath = '/api/store/ping.php';
$payload = [
    'success' => true,
    'diagnostic_version' => 2,
    'stage' => 'php_reached',
    'request_id' => $requestId,
    'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'request' => [
        'method' => $method,
        'path' => substr($requestPath, 0, 500),
        'host' => substr(trim((string) ($_SERVER['HTTP_HOST'] ?? '')), 0, 255),
        'http_protocol' => substr(trim((string) ($_SERVER['SERVER_PROTOCOL'] ?? '')), 0, 30),
        'https' => requestIsHttps(),
        'user_agent' => substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
        'accept' => substr(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 0, 255),
    ],
    'network' => [
        'remote_addr' => $remote,
        'detected_client_ip' => $clientIp,
        'remote_addr_is_trusted_proxy' => $trusted,
        'trusted_proxy_source' => trustedProxyConfigurationSource(),
        'trusted_proxy_rule_count' => count(trustedProxyRules()),
        'cf_connecting_ip' => $cfConnecting,
        'x_forwarded_for' => $forwardedFor,
        'x_real_ip' => $xRealIp,
        'cf_ray' => substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100),
    ],
    'interpretation' => [
        'php_reached' => true,
        'store_api_auth_tested' => false,
        'database_tested' => false,
        'note' => 'Receiving this JSON proves the request reached PHP. It does not prove API-key authentication or database/order availability.',
    ],
];

if ($method !== 'HEAD') {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo is_string($json) ? $json : '{"success":false,"code":"encoding_failed"}';
}
