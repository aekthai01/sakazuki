<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

requireAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: key_resets.php', true, 303);
    exit();
}
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($csrf) || !validateCsrfToken($csrf)) {
    if (function_exists('xchetosWriteSystemLog')) {
        xchetosWriteSystemLog('admin_endpoint_csrf_failed', [], 'warning', 'csrf_validation', '', (int) ($_SESSION['user_id'] ?? 0), 'admin');
    }
    $_SESSION['key_reset_admin_flash'] = [
        'success' => false,
        'status' => 'failed',
        'code' => 'csrf_failed',
        'request_id' => '',
        'request_stage' => 'csrf_validation',
        'provider_attempted' => false,
        'key_masked' => '',
        'product_name' => '',
        'wait_seconds' => 0,
        'daily_used' => 0,
        'daily_limit' => 0,
        'daily_remaining' => 0,
        'daily_reset_at' => '',
        'key_reset_count' => 0,
        'key_reset_limit' => 0,
        'key_reset_remaining' => 0,
        'server_time' => date('Y-m-d H:i:s'),
    ];
    header('Location: key_resets.php', true, 303);
    exit();
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
$provider = isset($_POST['provider']) && is_string($_POST['provider']) ? trim($_POST['provider']) : 'xchetos';
if ($provider === '') $provider = 'xchetos';
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$result = ['success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => ''];
if (function_exists('xchetosWriteSystemLog')) {
    xchetosWriteSystemLog('admin_endpoint_received', ['action' => $action, 'provider' => $provider], 'info', 'request_received', '', $adminId, 'admin');
}

try {
    if ($action === 'filter_logs') {
        $status = isset($_POST['status']) && is_string($_POST['status']) ? trim($_POST['status']) : '';
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? trim($_POST['operation']) : '';
        $search = isset($_POST['q']) && is_string($_POST['q']) ? substr(trim($_POST['q']), 0, 255) : '';
        $query = [];
        if ($search !== '') $query['q'] = $search;
        if (in_array($status, ['processing', 'success', 'failed', 'unknown'], true)) $query['status'] = $status;
        if (in_array($operation, ['reset_owned', 'reset_admin', 'diagnostic_login', 'clear_token', 'settings_update'], true)) $query['operation'] = $operation;
        $location = 'key_resets.php' . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '') . '#reset-logs';
        header('Location: ' . $location, true, 303);
        exit();
    }

    if ($action === 'clear_filters') {
        unset($_SESSION['key_reset_log_filters']);
        header('Location: key_resets.php#reset-logs', true, 303);
        exit();
    }

    if ($action === 'repair_logs') {
        $result = keyResetRepairStorage($adminId);
    } elseif ($action === 'rebuild_search_index') {
        $batchLimit = isset($_POST['batch_limit']) && is_scalar($_POST['batch_limit']) ? (int) $_POST['batch_limit'] : 1000;
        $result = keyResetRebuildSearchIndex($adminId, max(50, min(5000, $batchLimit)));
    } elseif ($action === 'diagnostic_login') {
        $result = keyResetRunDiagnostic($adminId, $provider);
    } elseif ($action === 'clear_token') {
        $result = keyResetClearProviderToken($adminId, $provider);
    } elseif ($action === 'save_limits') {
        $dailyLimit = isset($_POST['daily_limit']) && is_scalar($_POST['daily_limit']) ? (int) $_POST['daily_limit'] : 0;
        if ($dailyLimit < 1 || $dailyLimit > 5000) {
            $result = ['success' => false, 'status' => 'failed', 'code' => 'invalid_request', 'request_id' => ''];
        } else {
            $result = keyResetUpdatePolicy($adminId, $provider, $dailyLimit, 2);
        }
    } elseif ($action === 'reset_manual') {
        $licenseKey = isset($_POST['license_key']) && is_string($_POST['license_key']) ? $_POST['license_key'] : '';
        $result = keyResetAdminKey($adminId, $provider, $licenseKey);
    }
} catch (Throwable $error) {
    $requestId = '';
    try {
        $requestId = function_exists('xchetosRequestId') ? xchetosRequestId() : bin2hex(random_bytes(16));
    } catch (Throwable $ignored) {
        $requestId = substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);
    }
    error_log(
        'Admin key reset action exception ref=' . $requestId .
        ' type=' . get_class($error) . ' message=' . $error->getMessage() .
        ' file=' . basename($error->getFile()) . ':' . $error->getLine()
    );
    if (function_exists('xchetosWriteSystemLog')) {
        xchetosWriteSystemLog('admin_endpoint_exception', [
            'exception_type' => get_class($error),
            'message' => $error->getMessage(),
            'file' => basename($error->getFile()),
            'line' => $error->getLine(),
            'action' => $action,
        ], 'error', 'exception', $requestId, $adminId, 'admin');
    }
    $result = [
        'success' => false,
        'status' => 'failed',
        'code' => 'server_error',
        'request_id' => $requestId,
        'provider_attempted' => false,
    ];
}

if (!empty($result['diagnostics']) && is_array($result['diagnostics'])) {
    $_SESSION['key_reset_admin_diagnostic_report'] = $result['diagnostics'];
}
if (isset($result['processed']) || isset($result['updated']) || isset($result['pending'])) {
    $_SESSION['key_reset_admin_index_report'] = [
        'processed' => max(0, (int) ($result['processed'] ?? 0)),
        'updated' => max(0, (int) ($result['updated'] ?? 0)),
        'pending' => !empty($result['pending']),
        'error' => substr((string) ($result['error'] ?? ''), 0, 128),
    ];
}

if (function_exists('xchetosWriteSystemLog')) {
    xchetosWriteSystemLog('admin_endpoint_response', [
        'action' => $action,
        'success' => !empty($result['success']),
        'status' => (string) ($result['status'] ?? 'failed'),
        'code' => (string) ($result['code'] ?? 'provider_unknown'),
        'provider_attempted' => !empty($result['provider_attempted']),
    ], !empty($result['success']) ? 'info' : 'warning', 'response', (string) ($result['request_id'] ?? ''), $adminId, 'admin');
}

$dailyUsed = max(0, (int) ($result['daily_used'] ?? 0));
$dailyLimit = max(0, (int) ($result['daily_limit'] ?? 0));
$keyUsed = max(0, (int) ($result['key_reset_count'] ?? 0));
$keyLimit = max(0, (int) ($result['key_reset_limit'] ?? 0));

$_SESSION['key_reset_admin_flash'] = [
    'success' => !empty($result['success']),
    'status' => substr((string) ($result['status'] ?? 'failed'), 0, 16),
    'code' => substr((string) ($result['code'] ?? 'provider_unknown'), 0, 64),
    'request_id' => substr((string) ($result['request_id'] ?? ''), 0, 32),
    'request_stage' => substr((string) ($result['request_stage'] ?? ''), 0, 64),
    'provider_attempted' => !empty($result['provider_attempted']),
    'key_masked' => substr((string) ($result['key_masked'] ?? ''), 0, 128),
    'product_name' => substr((string) ($result['product_name'] ?? ''), 0, 255),
    'wait_seconds' => max(0, (int) ($result['wait_seconds'] ?? 0)),
    'daily_used' => $dailyUsed,
    'daily_limit' => $dailyLimit,
    'daily_remaining' => array_key_exists('daily_remaining', $result)
        ? max(0, (int) $result['daily_remaining'])
        : ($dailyLimit > 0 ? max(0, $dailyLimit - $dailyUsed) : 0),
    'daily_reset_at' => substr((string) ($result['daily_reset_at'] ?? ''), 0, 32),
    'key_reset_count' => $keyUsed,
    'key_reset_limit' => $keyLimit,
    'key_reset_remaining' => array_key_exists('key_reset_remaining', $result)
        ? max(0, (int) $result['key_reset_remaining'])
        : ($keyLimit > 0 ? max(0, $keyLimit - $keyUsed) : 0),
    'server_time' => date('Y-m-d H:i:s'),
];

header('Location: key_resets.php', true, 303);
exit();
