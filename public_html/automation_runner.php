<?php
/**
 * Reliable single-site automation runner for shared hosting / external cron.
 *
 * One request executes ONE website only. It never calls another website or
 * recursively calls this runner again.
 *
 * Ready-to-copy URL modes:
 *   ?token=<64-hex>&mode=critical     every 1 minute (legacy credential unchanged)
 *   ?token=<64-hex>&mode=maintenance  every 15 minutes (maintenance HMAC v2)
 *
 * Header/Bearer transport compatibility is kept for both modes:
 *   X-Sakazuki-Automation-Token: <64-hex>
 *   Authorization: Bearer <64-hex>
 */

$isCli = PHP_SAPI === 'cli';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
$modeRaw = $isCli ? ($argv[1] ?? 'critical') : ($_GET['mode'] ?? ($_POST['mode'] ?? 'critical'));
$mode = strtolower(trim(is_scalar($modeRaw) ? (string) $modeRaw : 'critical'));
if (!in_array($mode, ['critical', 'maintenance'], true)) {
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        http_response_code(400);
    }
    echo '{"success":false,"health":"failed","code":"invalid_mode"}' . ($isCli ? PHP_EOL : '');
    exit;
}

// Critical every-minute traffic must never perform CREATE/ALTER migrations.
// Maintenance (or CLI) may prepare schema so the site can self-heal after a
// deployment without making the stock loop pay the migration cost every minute.
if (!defined('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS')) {
    define('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS', $isCli || $mode === 'maintenance');
}

require_once __DIR__ . '/includes/automation.php';
if ($mode === 'maintenance') {
    require_once __DIR__ . '/includes/binance.php';
    require_once __DIR__ . '/includes/maintenance_diagnostics.php';
}

function automationDirectJson(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) ? $json : '{"success":false,"health":"failed","code":"encode_failed"}';
}

function automationDirectFatalReason(array $last): string
{
    $message = strtolower((string) ($last['message'] ?? ''));
    $type = (int) ($last['type'] ?? 0);
    if (strpos($message, 'maximum execution time') !== false) return 'time_limit';
    if (strpos($message, 'allowed memory size') !== false || strpos($message, 'out of memory') !== false) return 'memory_limit';
    if (in_array($type, [E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) return 'compile_fatal';
    return 'runtime_fatal';
}

function automationDirectPublicResult(array $result, bool $critical, bool $verboseMaintenance = false): array
{
    $success = ($result['success'] ?? true) !== false;
    $busy = !empty($result['busy']);
    $skipped = !empty($result['skipped']);
    $status = !$success
        ? ($critical ? 'failed' : 'warning')
        : ($busy ? 'busy' : ($skipped ? 'skipped' : 'ok'));
    $public = [
        'status' => $status,
        'severity' => $critical ? 'critical' : 'maintenance',
        'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
    ];
    // Keep the one-minute critical response compact and unchanged. Maintenance
    // is the diagnostic surface: expose only safe scalar counters/messages so
    // operators can identify a failed subsystem without opening five functions.
    if ($verboseMaintenance && !$critical) {
        if (isset($result['message']) && is_scalar($result['message'])) {
            $message = trim((string) $result['message']);
            if ($message !== '') $public['message'] = substr($message, 0, 500);
        }
        foreach (['attempted','processed','succeeded','updated','published','reconciled','failed','deleted','skipped','scanned','converted'] as $key) {
            if (array_key_exists($key, $result) && is_scalar($result[$key])) {
                $public[$key] = $result[$key];
            }
        }
    }
    return $public;
}

if (!$isCli) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        echo '{"success":false,"health":"failed","code":"method_not_allowed"}';
        exit;
    }

    $expectedTokenHash = strtolower(trim((string) getSetting('automation_web_token_hash', '')));
    $legacyExpectedToken = strtolower(trim((string) getSetting('automation_web_token', '')));
    $providedToken = '';

    $customHeader = $_SERVER['HTTP_X_SAKAZUKI_AUTOMATION_TOKEN'] ?? '';
    if (is_scalar($customHeader)) {
        $candidate = strtolower(trim((string) $customHeader));
        if (preg_match('/^[a-f0-9]{64}$/D', $candidate) === 1) $providedToken = $candidate;
    }
    if ($providedToken === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/D', $authorization, $matches) === 1) {
            $providedToken = strtolower($matches[1]);
        }
    }
    if ($providedToken === '') {
        $candidate = $method === 'POST'
            ? ($_POST['token'] ?? ($_GET['token'] ?? ''))
            : ($_GET['token'] ?? '');
        if (is_scalar($candidate)) {
            $candidate = strtolower(trim((string) $candidate));
            if (preg_match('/^[a-f0-9]{64}$/D', $candidate) === 1) $providedToken = $candidate;
        }
    }

    $credentialValid = false;
    if (preg_match('/^[a-f0-9]{64}$/D', $providedToken) === 1) {
        if ($mode === 'maintenance') {
            // Maintenance v2 is intentionally compartmentalized from the
            // every-minute critical URL. The critical validator below remains
            // byte-for-byte compatible with existing cron configuration.
            $schedulerHash = preg_match('/^[a-f0-9]{64}$/D', $expectedTokenHash) === 1
                ? $expectedTokenHash
                : (preg_match('/^[a-f0-9]{64}$/D', $legacyExpectedToken) === 1
                    ? hash('sha256', $legacyExpectedToken)
                    : '');
            if ($schedulerHash !== '' && function_exists('automationMaintenanceWebTokenCandidates')) {
                foreach (automationMaintenanceWebTokenCandidates($schedulerHash) as $expectedMaintenanceToken) {
                    if (hash_equals($expectedMaintenanceToken, $providedToken)) {
                        $credentialValid = true;
                        break;
                    }
                }
            }
        } else {
            if (preg_match('/^[a-f0-9]{64}$/D', $expectedTokenHash) === 1) {
                $credentialValid = hash_equals($expectedTokenHash, hash('sha256', $providedToken));
            } elseif (preg_match('/^[a-f0-9]{64}$/D', $legacyExpectedToken) === 1) {
                $credentialValid = hash_equals($legacyExpectedToken, $providedToken);
            }
        }
    }
    if (!$credentialValid) {
        // Deliberately indistinguishable from a missing endpoint.
        http_response_code(404);
        echo '{"success":false,"health":"failed","code":"not_found"}';
        exit;
    }

    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    $GLOBALS['sakazuki_direct_finished'] = false;
    $GLOBALS['sakazuki_direct_stage'] = 'startup';
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['sakazuki_direct_finished'])) return;
        $last = error_get_last();
        if (!is_array($last)) return;
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($last['type'] ?? 0), $fatalTypes, true)) return;
        $allowedStages = [
            'startup','schema','pending_orders','slip_reconciliation','cgo_inventory','supplier_catalog',
            'cgo_catalog','shared_history','shared_binance_history','commerce_center','history_cleanup','product_image_cleanup','product_image_optimizer','admin_transaction_indexes',
            'history_schema','commerce_schema','checkout_health','binance_clock','maintenance_diagnostics','unknown_job'
        ];
        $stage = (string) ($GLOBALS['sakazuki_direct_stage'] ?? 'unknown_job');
        if (!in_array($stage, $allowedStages, true)) $stage = 'unknown_job';
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo automationDirectJson([
            'success' => false,
            'health' => 'failed',
            'code' => 'runner_fatal',
            'stage' => $stage,
            'reason' => automationDirectFatalReason($last),
        ]);
    });
}

ignore_user_abort(true);
// Leave a small margin before cron-job.org's external timeout. Individual API
// clients still keep their own shorter connect/request timeouts.
@set_time_limit($isCli ? 0 : 27);

$runner = $isCli ? 'cron' : 'web_cron';
$started = microtime(true);
$startedAt = gmdate('c');
$maintenanceRunId = '';
if ($mode === 'maintenance') {
    try {
        $maintenanceRunId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $maintenanceRunId = gmdate('YmdHis') . '-' . substr(hash('sha256', microtime(true) . '|' . getmypid()), 0, 12);
    }
    $dbFingerprint = function_exists('automationDatabaseFingerprint') ? automationDatabaseFingerprint() : 'unknown';
    error_log('[maintenance][run] id=' . $maintenanceRunId . '; event=start; db=' . $dbFingerprint
        . '; auth=maintenance_hmac_sha256_v2');
}
$GLOBALS['sakazuki_direct_stage'] = 'schema';

if ($mode === 'maintenance') {
    try {
        $schema = automationPrepareStorefrontSchemas();
    } catch (Throwable $e) {
        $schema = [
            'success' => false,
            'message' => 'Storefront schema preparation threw an exception',
            'error' => substr($e->getMessage(), 0, 700),
        ];
        error_log('[maintenance][schema] failed: ' . substr($e->getMessage(), 0, 700));
    }
} else {
    // Cheap readiness probe only. No storefront migrations in the one-minute loop.
    try {
        $schema = ['success' => automationEnsureSchema()];
    } catch (Throwable $e) {
        $schema = ['success' => false];
    }
}

$schemaReady = ($schema['success'] ?? false) !== false;
$checkoutHealth = null;
$binanceClockHealth = null;
$adminTransactionIndexHealth = null;
$historySchemaHealth = null;
$commerceSchemaHealth = null;
if ($mode === 'maintenance' && function_exists('localHistoryAuditSchemaRepair')) {
    $GLOBALS['sakazuki_direct_stage'] = 'history_schema';
    try {
        $historySchemaHealth = localHistoryAuditSchemaRepair();
    } catch (Throwable $e) {
        $historySchemaHealth = [
            'success' => false,
            'changed' => false,
            'skipped' => false,
            'busy' => false,
            'message' => 'History schema maintenance threw an exception',
            'error' => substr($e->getMessage(), 0, 700),
            'steps' => [],
            'duration_ms' => 0,
        ];
        error_log('[maintenance][history_schema] uncaught exception: ' . substr($e->getMessage(), 0, 700));
    }
}
if ($mode === 'maintenance' && function_exists('commerceCenterMaintenanceSchemaRepair')) {
    $GLOBALS['sakazuki_direct_stage'] = 'commerce_schema';
    try {
        $commerceSchemaHealth = commerceCenterMaintenanceSchemaRepair();
    } catch (Throwable $e) {
        $commerceSchemaHealth = [
            'success' => false,
            'changed' => false,
            'skipped' => false,
            'busy' => false,
            'recovery_due' => false,
            'message' => 'Commerce Center schema maintenance threw an exception',
            'error' => substr($e->getMessage(), 0, 700),
            'steps' => [],
            'duration_ms' => 0,
        ];
        error_log('[maintenance][commerce_schema] uncaught exception: ' . substr($e->getMessage(), 0, 700));
    }
}
if ($mode === 'maintenance' && function_exists('automationEnsureAdminTransactionIndexes')) {
    $GLOBALS['sakazuki_direct_stage'] = 'admin_transaction_indexes';
    try {
        $rawAdminTxIndexes = automationEnsureAdminTransactionIndexes();
        $adminTransactionIndexHealth = [
            'status' => !empty($rawAdminTxIndexes['success']) ? 'ok' : (!empty($rawAdminTxIndexes['skipped']) ? 'skipped' : 'warning'),
            'duration_ms' => max(0, (int) ($rawAdminTxIndexes['duration_ms'] ?? 0)),
            'added' => array_values((array) ($rawAdminTxIndexes['added'] ?? [])),
            'present' => array_values((array) ($rawAdminTxIndexes['present'] ?? [])),
        ];
    } catch (Throwable $e) {
        $adminTransactionIndexHealth = ['status' => 'warning', 'duration_ms' => 0, 'added' => [], 'present' => []];
        error_log('[maintenance][admin_transaction_indexes] failed: ' . substr($e->getMessage(), 0, 700));
    }
}
if ($mode === 'maintenance' && function_exists('binanceClockDiagnostic')) {
    $GLOBALS['sakazuki_direct_stage'] = 'binance_clock';
    try {
        $rawBinanceClock = binanceClockDiagnostic(true);
        $binanceClockHealth = [
            'status' => !empty($rawBinanceClock['success']) ? 'ok' : 'failed',
            'offset_ms' => (int) ($rawBinanceClock['offset_ms'] ?? 0),
            'applied_offset_ms' => (int) ($rawBinanceClock['applied_offset_ms'] ?? ($rawBinanceClock['offset_ms'] ?? 0)),
            'rtt_ms' => max(0, (int) ($rawBinanceClock['rtt_ms'] ?? 0)),
            'php_int_bits' => max(0, (int) ($rawBinanceClock['php_int_bits'] ?? (PHP_INT_SIZE * 8))),
            'server_time_ms' => (string) ($rawBinanceClock['server_time_ms'] ?? ''),
            'local_midpoint_ms' => (string) ($rawBinanceClock['local_midpoint_ms'] ?? ''),
            'message' => isset($rawBinanceClock['message']) && is_scalar($rawBinanceClock['message'])
                ? substr((string) $rawBinanceClock['message'], 0, 120)
                : '',
            'http_code' => max(0, (int) ($rawBinanceClock['http_code'] ?? 0)),
            'curl_errno' => max(0, (int) ($rawBinanceClock['curl_errno'] ?? 0)),
            'oversized' => !empty($rawBinanceClock['oversized']),
        ];
    } catch (Throwable $e) {
        $binanceClockHealth = [
            'status' => 'failed',
            'offset_ms' => 0,
            'applied_offset_ms' => 0,
            'rtt_ms' => 0,
            'php_int_bits' => PHP_INT_SIZE * 8,
            'server_time_ms' => '',
            'local_midpoint_ms' => '',
            'message' => 'clock_diagnostic_exception',
            'http_code' => 0,
            'curl_errno' => 0,
            'oversized' => false,
        ];
        error_log('[maintenance][binance_clock] failed: ' . substr($e->getMessage(), 0, 700));
    }
}
if ($mode === 'maintenance' && function_exists('localCheckoutRuntimeHealth')) {
    $GLOBALS['sakazuki_direct_stage'] = 'checkout_health';
    try {
        $rawCheckoutHealth = localCheckoutRuntimeHealth(true);
        $checkoutCoreReady = !empty($rawCheckoutHealth['core_ready']);
        $checkoutHistoryReady = !empty($rawCheckoutHealth['history_ready']);
        $checkoutHealth = [
            'status' => !$checkoutCoreReady ? 'failed' : ($checkoutHistoryReady ? 'ok' : 'warning'),
            'core_ready' => $checkoutCoreReady,
            'wallet_ready' => !empty($rawCheckoutHealth['wallet_ready']),
            'history_ready' => $checkoutHistoryReady,
            'history_identity' => (array) ($rawCheckoutHealth['history_identity'] ?? []),
            'history_failed_checks' => array_values((array) ($rawCheckoutHealth['history_failed_checks'] ?? [])),
            'purchase_type_supported' => $rawCheckoutHealth['purchase_type_supported'] ?? null,
            'failed_column_checks' => array_values((array) ($rawCheckoutHealth['failed_column_checks'] ?? [])),
            'failed_prepare_checks' => array_values((array) ($rawCheckoutHealth['failed_prepare_checks'] ?? [])),
            'non_transactional_tables' => array_values((array) ($rawCheckoutHealth['non_transactional_tables'] ?? [])),
        ];
    } catch (Throwable $e) {
        $checkoutHealth = ['status' => 'failed', 'core_ready' => false];
        error_log('[maintenance][checkout_health] failed: ' . substr($e->getMessage(), 0, 700));
    }
}
if ($schemaReady) automationRecordCronHeartbeat($runner);

$definitions = automationJobDefinitions();
$criticalJobs = ['pending_orders','slip_reconciliation','cgo_inventory','supplier_catalog'];
$maintenanceJobs = ['cgo_catalog','shared_history','shared_binance_history','commerce_center','history_cleanup','product_image_cleanup','product_image_optimizer'];
$allowedJobs = $mode === 'critical' ? $criticalJobs : $maintenanceJobs;
$budgetSeconds = $isCli ? 0 : ($mode === 'critical' ? 21 : 22);
$results = [];
$criticalFailures = [];
$warnings = [];
if ($mode === 'maintenance' && is_array($historySchemaHealth)) {
    $historyBusy = !empty($historySchemaHealth['busy']);
    if (!$historyBusy && empty($historySchemaHealth['success'])) {
        $warnings[] = 'history_schema';
    }
}
if ($mode === 'maintenance' && is_array($commerceSchemaHealth)) {
    $commerceBusy = !empty($commerceSchemaHealth['busy']);
    if (!$commerceBusy && empty($commerceSchemaHealth['success'])) {
        $warnings[] = 'commerce_schema';
    }
}
if ($mode === 'maintenance' && is_array($adminTransactionIndexHealth)
    && ($adminTransactionIndexHealth['status'] ?? '') === 'warning') {
    $warnings[] = 'admin_transaction_indexes';
}
$binanceFeatureEnabled = $mode === 'maintenance'
    && (getSetting('binance_enabled', '0') === '1' || getSetting('binance_giftcard_enabled', '0') === '1');
if ($binanceFeatureEnabled && is_array($binanceClockHealth) && ($binanceClockHealth['status'] ?? '') !== 'ok') {
    $warnings[] = 'binance_clock';
}
if ($mode === 'maintenance' && is_array($checkoutHealth) && empty($checkoutHealth['history_ready'])) {
    $warnings[] = 'history_health';
}

if ($schemaReady) {
    foreach ($allowedJobs as $jobName) {
        if (!isset($definitions[$jobName])) continue;
        if ($budgetSeconds > 0 && microtime(true) - $started >= $budgetSeconds) break;
        $GLOBALS['sakazuki_direct_stage'] = $jobName;
        $definition = $definitions[$jobName];
        $critical = $mode === 'critical' && !empty($definition['critical']);
        try {
            $forceMaintenanceRecovery = $mode === 'maintenance'
                && $jobName === 'commerce_center'
                && is_array($commerceSchemaHealth)
                && !empty($commerceSchemaHealth['success'])
                && !empty($commerceSchemaHealth['recovery_due']);
            $result = automationRunJob(
                $jobName,
                (int) ($definition['interval'] ?? 60),
                $definition['callback'],
                $runner,
                $forceMaintenanceRecovery
            );
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'duration_ms' => 0,
                'message' => $mode === 'maintenance'
                    ? ('Unhandled job exception: ' . substr($e->getMessage(), 0, 500))
                    : '',
            ];
            if ($mode === 'maintenance') {
                error_log('[maintenance][' . $jobName . '] uncaught exception: ' . substr($e->getMessage(), 0, 700));
            }
        }
        $results[$jobName] = automationDirectPublicResult($result, $critical, $mode === 'maintenance');
        if ($mode === 'maintenance' && function_exists('maintenancePublicJobDiagnostics')) {
            $jobDiagnostics = maintenancePublicJobDiagnostics($jobName, $result);
            if ($jobDiagnostics !== []) $results[$jobName]['diagnostics'] = $jobDiagnostics;
        }
        if ($mode === 'maintenance') {
            $publicJob = $results[$jobName];
            $jobMessage = isset($publicJob['message']) && is_scalar($publicJob['message'])
                ? substr(str_replace(["\r", "\n"], ' ', (string) $publicJob['message']), 0, 500)
                : '';
            error_log('[maintenance][job] id=' . $maintenanceRunId
                . '; name=' . $jobName
                . '; status=' . (string) ($publicJob['status'] ?? 'unknown')
                . '; duration_ms=' . (int) ($publicJob['duration_ms'] ?? 0)
                . ($jobMessage !== '' ? '; message=' . $jobMessage : ''));
        }
        if (($result['success'] ?? true) === false) {
            if ($critical) $criticalFailures[] = $jobName;
            else $warnings[] = $jobName;
        }
    }
}

if ($schemaReady) automationRecordCronHeartbeat($runner);

$maintenanceDiagnostics = null;
if ($mode === 'maintenance' && function_exists('maintenanceCollectDiagnostics')) {
    $GLOBALS['sakazuki_direct_stage'] = 'maintenance_diagnostics';
    try {
        $maintenanceDiagnostics = maintenanceCollectDiagnostics(
            array_merge($criticalJobs, $maintenanceJobs),
            is_array($binanceClockHealth) ? $binanceClockHealth : null
        );
        foreach ((array) ($maintenanceDiagnostics['issues'] ?? []) as $issue) {
            if (!is_string($issue) || $issue === '') continue;
            if (strpos($issue, 'commerce_center_') === 0) {
                $warnings[] = 'commerce_center';
            } elseif (strpos($issue, 'job_consecutive_failures:') === 0 || $issue === 'critical_cron_heartbeat_stale') {
                $warnings[] = 'automation_state';
            } elseif ($issue === 'binance_clock_unhealthy') {
                $warnings[] = 'binance_clock';
            } elseif ($issue === 'diagnostic_probe_failures') {
                $warnings[] = 'maintenance_diagnostics';
            } else {
                $warnings[] = 'payment_rails';
            }
        }
        error_log('[maintenance][diagnostics] id=' . $maintenanceRunId
            . '; status=' . (string) ($maintenanceDiagnostics['status'] ?? 'unknown')
            . '; issues=' . (((array) ($maintenanceDiagnostics['issues'] ?? [])) === []
                ? 'none'
                : implode(',', array_slice((array) $maintenanceDiagnostics['issues'], 0, 30))));
    } catch (Throwable $e) {
        $warnings[] = 'maintenance_diagnostics';
        $maintenanceDiagnostics = [
            'status' => 'failed',
            'issues' => ['diagnostic_collection_failed'],
            'message' => 'Maintenance diagnostics could not be collected',
        ];
        error_log('[maintenance][diagnostics] id=' . $maintenanceRunId . '; failed=' . substr($e->getMessage(), 0, 700));
    }
}

// A due critical job that could not even start inside the web budget is a real
// degraded stock/order loop, not a successful run.
if ($mode === 'critical' && $schemaReady) {
    foreach ($criticalJobs as $jobName) {
        if (isset($results[$jobName]) || !isset($definitions[$jobName])) continue;
        if (!automationJobIsDue($jobName)) continue;
        $results[$jobName] = ['status' => 'deferred', 'severity' => 'critical', 'duration_ms' => 0];
        $criticalFailures[] = $jobName;
    }
}

$criticalFailures = array_values(array_unique($criticalFailures));
$warnings = array_values(array_unique($warnings));
if ($mode === 'critical') {
    $success = $schemaReady && $criticalFailures === [];
    $health = $success ? 'ok' : 'failed';
} else {
    // Maintenance warnings are visible but do not pretend the shop is offline.
    $success = $schemaReady;
    $health = !$success ? 'failed' : ($warnings !== [] ? 'degraded' : 'ok');
}

$payload = [
    'success' => $success,
    'health' => $health,
    'runner' => 'single_site_' . $mode,
    'schema_ready' => $schemaReady,
    'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
    'critical_failures' => $mode === 'critical' ? $criticalFailures : [],
    'warnings' => $mode === 'maintenance' ? $warnings : [],
    'jobs' => $results,
];
if ($mode === 'maintenance') {
    $payload['run_id'] = $maintenanceRunId;
    $payload['started_at'] = $startedAt;
    $payload['finished_at'] = gmdate('c');
    $payload['auth_scheme'] = 'maintenance_hmac_sha256_v2';
    $payload['maintenance_version'] = '2.2.0';
    $payload['diagnostics_schema'] = 'maintenance_observability_v1';
    $payload['database_fingerprint'] = function_exists('automationDatabaseFingerprint')
        ? automationDatabaseFingerprint()
        : '';
    $payload['schema_maintenance'] = [
        'status' => $schemaReady ? (!empty($schema['skipped']) ? 'skipped' : 'ok') : 'failed',
        'message' => isset($schema['message']) && is_scalar($schema['message'])
            ? substr((string) $schema['message'], 0, 500)
            : '',
        'failed' => array_values((array) ($schema['failed'] ?? [])),
        'checks' => (array) ($schema['checks'] ?? []),
    ];
    if (isset($schema['error']) && is_scalar($schema['error'])) {
        $payload['schema_maintenance']['error'] = substr((string) $schema['error'], 0, 700);
    }
}
if ($mode === 'maintenance' && is_array($historySchemaHealth)) {
    $payload['history_schema'] = $historySchemaHealth;
}
if ($mode === 'maintenance' && is_array($commerceSchemaHealth)) {
    $payload['commerce_schema'] = $commerceSchemaHealth;
}
if ($mode === 'maintenance' && is_array($adminTransactionIndexHealth)) {
    $payload['admin_transaction_indexes'] = $adminTransactionIndexHealth;
}
if ($mode === 'maintenance' && is_array($checkoutHealth)) {
    $payload['checkout_health'] = $checkoutHealth;
}
if ($mode === 'maintenance' && is_array($binanceClockHealth)) {
    $payload['binance_clock'] = $binanceClockHealth;
}
if ($mode === 'maintenance' && is_array($maintenanceDiagnostics)) {
    $payload['diagnostics'] = $maintenanceDiagnostics;
}

if ($mode === 'maintenance') {
    error_log('[maintenance][run] id=' . $maintenanceRunId
        . '; event=finish; health=' . $health
        . '; duration_ms=' . (int) ($payload['duration_ms'] ?? 0)
        . '; warnings=' . ($warnings === [] ? 'none' : implode(',', $warnings)));
}

if (!$isCli) {
    $GLOBALS['sakazuki_direct_finished'] = true;
    http_response_code($success ? 200 : 503);
}
echo automationDirectJson($payload) . ($isCli ? PHP_EOL : '');
