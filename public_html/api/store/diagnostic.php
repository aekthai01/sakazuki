<?php
/**
 * One-time/short-lived diagnostic probe endpoint.
 * A reseller generates the token from reseller/api_store.php and then calls
 * this URL from the ACTUAL backend server that will use Store API.
 */
require_once __DIR__ . '/../../includes/store_bridge.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try { $requestId = 'probe_req_' . substr(bin2hex(random_bytes(10)), 0, 20); }
catch (Throwable $e) { $requestId = 'probe_req_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 20); }
header('X-Request-ID: ' . $requestId);
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo '{"success":false,"code":"method_not_allowed","message":"Use GET or HEAD"}';
    exit;
}
$token = isset($_GET['token']) && is_scalar($_GET['token']) ? trim((string) $_GET['token']) : '';
if (!storeBridgeEnsureSchema()) {
    http_response_code(503);
    echo '{"success":false,"code":"diagnostic_unavailable","message":"Store API schema is unavailable"}';
    exit;
}
$result = storeBridgeConsumeDiagnosticProbe($token, $requestId);
$httpCode = (int) ($result['http_code'] ?? (empty($result['success']) ? 400 : 200));
unset($result['http_code']);
http_response_code($httpCode);
if ($method !== 'HEAD') {
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo is_string($json) ? $json : '{"success":false,"code":"encoding_failed"}';
}
