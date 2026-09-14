<?php
/** Read-only status endpoint used after an interrupted AJAX response. */
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

$send = static function (array $payload, int $statusCode = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $payload['server_time'] = (string) ($payload['server_time'] ?? date('Y-m-d H:i:s'));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$authenticated = isLoggedIn();
if (!$authenticated && function_exists('attemptRememberedLogin')) {
    $authenticated = attemptRememberedLogin();
}
if (!$authenticated || (function_exists('authValidateCurrentSession') && !authValidateCurrentSession(true))) {
    $send(['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'session_expired'], 401);
}
if (!isReseller()) {
    $send(['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'forbidden'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $send(['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'invalid_request'], 405);
}

$requestId = isset($_GET['request_id']) && is_string($_GET['request_id'])
    ? strtolower(trim($_GET['request_id']))
    : '';
$provider = isset($_GET['provider']) && is_string($_GET['provider']) ? trim($_GET['provider']) : 'xchetos';
if (preg_match('/^[a-f0-9]{32}$/D', $requestId) !== 1) {
    $send(['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => ''], 400);
}

try {
    $result = keyResetGetResellerRequestStatus((int) ($_SESSION['user_id'] ?? 0), $provider, $requestId);
} catch (Throwable $error) {
    error_log(
        'Key reset status exception ref=' . $requestId . ' type=' . get_class($error) .
        ' message=' . $error->getMessage() . ' file=' . basename($error->getFile()) . ':' . $error->getLine()
    );
    $send(['found' => false, 'success' => false, 'status' => 'failed', 'code' => 'server_error', 'request_id' => $requestId], 500);
}

$payload = [
    'found' => !empty($result['found']),
    'success' => !empty($result['success']),
    'status' => substr((string) ($result['status'] ?? 'failed'), 0, 16),
    'code' => substr((string) ($result['code'] ?? 'request_not_found'), 0, 64),
    'request_id' => substr((string) ($result['request_id'] ?? $requestId), 0, 32),
    'key_masked' => substr((string) ($result['key_masked'] ?? ''), 0, 128),
    'product_name' => substr((string) ($result['product_name'] ?? ''), 0, 255),
    'key_duration_days' => max(0, (int) ($result['key_duration_days'] ?? 0)),
    'key_reset_count' => max(0, (int) ($result['key_reset_count'] ?? 0)),
    'key_reset_limit' => max(0, (int) ($result['key_reset_limit'] ?? 0)),
    'key_reset_remaining' => max(0, (int) ($result['key_reset_remaining'] ?? 0)),
    'daily_used' => max(0, (int) ($result['daily_used'] ?? 0)),
    'daily_limit' => max(0, (int) ($result['daily_limit'] ?? 0)),
    'daily_remaining' => max(0, (int) ($result['daily_remaining'] ?? 0)),
    'daily_reset_at' => substr((string) ($result['daily_reset_at'] ?? ''), 0, 32),
    'created_at' => substr((string) ($result['created_at'] ?? ''), 0, 32),
    'completed_at' => substr((string) ($result['completed_at'] ?? ''), 0, 32),
];

$send($payload, !empty($payload['found']) ? ($payload['status'] === 'processing' ? 202 : 200) : 404);
