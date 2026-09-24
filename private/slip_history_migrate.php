<?php
/**
 * One-time/deployment bank-slip historical ledger migration.
 *
 * Run this file from CLI on EACH website after deploying the compatible shared
 * hub/client code. It never credits a wallet and cannot force the readiness
 * marker; the marker is published only after both local slip cursors reach 0.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ignore_user_abort(true);
@set_time_limit(0);
require_once dirname(__DIR__) . '/public_html/includes/automation.php';

$maxBatches = 100;
$timeBudget = 55;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--max-batches=(\d+)$/', (string) $arg, $m)) $maxBatches = max(1, min(500, (int) $m[1]));
    if (preg_match('/^--time-budget=(\d+)$/', (string) $arg, $m)) $timeBudget = max(5, min(300, (int) $m[1]));
}
$result = automationDrainSharedDepositHistory($maxBatches, $timeBudget, 250, 'deploy_drain');
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
fwrite(STDOUT, (is_string($json) ? $json : '{}') . PHP_EOL);
exit(($result['success'] ?? false) !== false ? 0 : 1);
