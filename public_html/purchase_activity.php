<?php
// Live polling must not schedule the heavy one-year history cleanup on every
// request. Normal page loads and order requests still schedule it as before.
if (!defined('SKIP_KEY_HISTORY_AUTO_CLEANUP')) define('SKIP_KEY_HISTORY_AUTO_CLEANUP', true);
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isLoggedIn() || !authValidateCurrentSession(false)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'authentication_required']);
    exit;
}

if (!isAdmin() && !isReseller() && !isUser()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

// Admin-only diagnostics make production verification possible without exposing
// usernames, emails, keys, SQL text, or API responses.
$diagnosticsRequested = isAdmin()
    && isset($_GET['diagnostics'])
    && is_scalar($_GET['diagnostics'])
    && hash_equals('1', (string) $_GET['diagnostics']);

// The feed is read-only. Releasing the session lock prevents this lightweight
// poll from delaying an order submission in another tab.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$items = getPublicRecentPurchaseActivity(10, $diagnosticsRequested);
$encodedItems = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encodedItems === false) $encodedItems = '[]';
$signature = hash('sha256', $encodedItems);
$etag = '"' . $signature . '"';
header('ETag: ' . $etag);

// The browser sends the previous feed signature. Returning 304 avoids sending
// and parsing the same JSON every poll while still validating the session.
$clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if (!$diagnosticsRequested && $clientEtag !== '' && hash_equals($etag, $clientEtag)) {
    http_response_code(304);
    exit;
}

$payload = [
    'success' => true,
    'items' => $items,
    'signature' => $signature,
    'generated_at' => date('c'),
];

if ($diagnosticsRequested) {
    $diagnostics = $GLOBALS['purchase_activity_diagnostics'] ?? [];
    $payload['diagnostics'] = is_array($diagnostics) ? $diagnostics : [];
}

http_response_code(200);
echo json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
