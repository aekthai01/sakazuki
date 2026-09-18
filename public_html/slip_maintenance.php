<?php
/**
 * Cheap same-origin safety maintenance for the bank-slip page.
 *
 * This endpoint never verifies a slip and never credits money. It only advances
 * the idempotent historical shared-ledger import when the hosting cron is late.
 * Database due-times + MySQL named locks prevent a page-refresh stampede.
 */
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_bridge.php';
require_once __DIR__ . '/includes/automation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

requireLogin();
requireActive();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
requireCsrfToken();

$userId = max(0, (int) ($_SESSION['user_id'] ?? 0));
if (!checkRateLimit('slip_maintenance_' . $userId, 6, 300)) {
    jsonResponse(['success' => true, 'skipped' => true, 'message' => 'Maintenance rate limited'], 200);
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
ignore_user_abort(true);
@set_time_limit(12);

try {
    // Drain several idempotent batches inside a short, bounded window. The same
    // MySQL named lock used by cron/admin migration means many visitors cannot
    // create parallel hub traffic; at most one request advances the cursor while
    // the rest return quickly as busy/skipped. This lets a missed hosting cron
    // recover automatically instead of leaving all verified slips blocked for
    // hours at a five-row-per-minute migration rate.
    $result = automationDrainSharedDepositHistory(10, 8, 250, 'slip_web_fallback');
    $lastResult = is_array($result['last_result'] ?? null) ? $result['last_result'] : [];
    // Deliberately expose only operational booleans/counts to normal users.
    jsonResponse([
        'success' => ($result['success'] ?? false) !== false,
        'skipped' => !empty($lastResult['skipped']),
        'busy' => !empty($lastResult['busy']),
        'processed' => max(0, (int) ($result['processed'] ?? 0)),
        'slip_history_ready' => !empty($result['slip_history_ready_local']),
        'global_history_ready' => !empty($result['global_history_ready']),
    ], 200);
} catch (Throwable $e) {
    error_log('Slip maintenance fallback failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Maintenance unavailable'], 503);
}
