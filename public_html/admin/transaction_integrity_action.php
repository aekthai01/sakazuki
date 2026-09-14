<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/transaction_integrity.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$startedAt = microtime(true);
$requestId = substr(bin2hex(random_bytes(16)), 0, 32);
$responseSent = false;
$action = '';
$batchId = '';
$adminId = (int) ($_SESSION['user_id'] ?? 0);

$emit = static function (array $payload, int $httpStatus = 200) use (&$responseSent, $requestId, $startedAt): void {
    if ($responseSent) return;
    $responseSent = true;
    http_response_code($httpStatus);
    $payload['request_id'] = $requestId;
    $payload['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $payload['server_time'] = date('Y-m-d H:i:s');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

register_shutdown_function(static function () use (&$responseSent, &$action, &$batchId, $adminId, $requestId, $startedAt): void {
    $last = error_get_last();
    if (!$last || !in_array((int) ($last['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $message = substr((string) ($last['message'] ?? 'fatal_error'), 0, 900);
    transactionIntegrityWriteDebugLog(
        $requestId,
        $batchId,
        $adminId,
        $action,
        'shutdown',
        'error',
        'fatal_error',
        $message,
        (int) round((microtime(true) - $startedAt) * 1000),
        ['file' => basename((string) ($last['file'] ?? '')), 'line' => (int) ($last['line'] ?? 0)]
    );
    if (!$responseSent && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'code' => 'fatal_error',
            'message' => $message,
            'request_id' => $requestId,
            'batch_id' => $batchId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $emit(['success' => false, 'code' => 'method_not_allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($csrf) || !validateCsrfToken($csrf)) {
    transactionIntegrityWriteDebugLog($requestId, '', $adminId, 'unknown', 'csrf', 'warning', 'csrf_failed', 'CSRF validation failed');
    $emit(['success' => false, 'code' => 'csrf_failed'], 403);
}

$action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';
$batchId = isset($_POST['batch_id']) && is_scalar($_POST['batch_id']) ? strtolower(trim((string) $_POST['batch_id'])) : '';
$previewHash = isset($_POST['preview_hash']) && is_scalar($_POST['preview_hash']) ? trim((string) $_POST['preview_hash']) : '';

transactionIntegrityWriteDebugLog(
    $requestId,
    $batchId,
    $adminId,
    $action,
    'request',
    'info',
    'received',
    'Transaction repair action received',
    0,
    ['has_preview_hash' => $previewHash !== '', 'php_version' => PHP_VERSION]
);

try {
    switch ($action) {
        case 'initialize':
            $result = transactionIntegrityCreateBatch($adminId, $previewHash, $batchId);
            break;

        case 'migrate_schema':
            $result = transactionIntegrityMigrateBatchSchema($batchId);
            break;

        case 'repair_chunk':
            $limit = isset($_POST['limit']) && is_scalar($_POST['limit']) ? (int) $_POST['limit'] : 40;
            $result = transactionIntegrityRepairBatchChunk($batchId, $limit);
            break;

        case 'status':
            $result = transactionIntegrityBatchStatus($batchId);
            break;

        default:
            $result = ['success' => false, 'code' => 'invalid_action', 'batch_id' => $batchId];
            break;
    }

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $success = !empty($result['success']);
    transactionIntegrityWriteDebugLog(
        $requestId,
        $batchId,
        $adminId,
        $action,
        'response',
        $success ? 'info' : 'warning',
        (string) ($result['code'] ?? ($success ? 'ok' : 'failed')),
        $success ? 'Action completed' : (string) ($result['message'] ?? $result['code'] ?? 'Action failed'),
        $durationMs,
        [
            'status' => (string) ($result['status'] ?? ($result['batch']['status'] ?? '')),
            'candidate_count' => (int) ($result['candidate_count'] ?? ($result['batch']['candidate_count'] ?? 0)),
            'repaired_count' => (int) ($result['repaired_count'] ?? ($result['batch']['repaired_count'] ?? 0)),
            'remaining' => $result['remaining'] ?? null,
            'schema_ready' => $result['schema_ready'] ?? null,
        ]
    );
    $emit($result, $success ? 200 : 409);
} catch (Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $message = substr($e->getMessage(), 0, 1000);
    $dbErrno = (int) ($conn->errno ?? 0);
    $dbError = substr((string) ($conn->error ?? ''), 0, 1000);
    error_log('Transaction integrity action failed ref=' . $requestId . ' action=' . $action . ' batch=' . $batchId . ' message=' . $message);
    transactionIntegrityWriteDebugLog(
        $requestId,
        $batchId,
        $adminId,
        $action,
        'exception',
        'error',
        'server_exception',
        $message,
        $durationMs,
        ['exception' => get_class($e), 'db_errno' => $dbErrno, 'db_error' => $dbError]
    );
    $emit([
        'success' => false,
        'code' => 'server_exception',
        'message' => $message,
        'batch_id' => $batchId,
        'db_errno' => $dbErrno,
        'db_error' => $dbError,
    ], 500);
}
