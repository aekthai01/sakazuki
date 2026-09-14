<?php
/**
 * Compatibility endpoint for old bookmarks/forms.
 * New reseller UI lives at key_resets.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

requireLogin();
if (!isReseller()) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: key_resets.php', true, 303);
    exit();
}

requireCsrfToken('key_resets.php');

$action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
$source = isset($_POST['source']) && is_string($_POST['source']) ? trim($_POST['source']) : '';
$recordId = $_POST['record_id'] ?? '';

if ($action !== 'reset_hwid' || !is_scalar($recordId)) {
    $result = ['success' => false, 'code' => 'invalid_request'];
} else {
    $result = keyResetOwnedKey((int) ($_SESSION['user_id'] ?? 0), 'xchetos', $source, (string) $recordId);
}

$_SESSION['key_reset_flash'] = [
    'success' => !empty($result['success']),
    'status' => substr((string) ($result['status'] ?? 'failed'), 0, 16),
    'code' => substr((string) ($result['code'] ?? 'provider_unknown'), 0, 64),
    'wait_seconds' => max(0, (int) ($result['wait_seconds'] ?? 0)),
    'key_masked' => substr((string) ($result['key_masked'] ?? ''), 0, 128),
    'product_name' => substr((string) ($result['product_name'] ?? ''), 0, 255),
    'request_id' => substr((string) ($result['request_id'] ?? ''), 0, 32),
];

header('Location: key_resets.php', true, 303);
exit();
