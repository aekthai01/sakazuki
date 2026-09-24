<?php
/**
 * Lightweight server-side automation.
 *
 * A one-minute hosting cron calls the private worker. Database due-times and
 * MySQL named locks ensure that expensive supplier work is never repeated by
 * every visitor or by two overlapping cron processes.
 */

require_once __DIR__ . '/cheatgame.php';
require_once __DIR__ . '/ranking.php';
require_once __DIR__ . '/key_history_cleanup.php';
require_once __DIR__ . '/commerce_center.php';
require_once __DIR__ . '/wallet_ledger.php';
require_once __DIR__ . '/account_verification.php';

function automationEnsureSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    if (function_exists('sakazukiTableReady') && sakazukiTableReady('automation_job_state')) {
        return $ready = true;
    }
    if (function_exists('sakazukiSchemaMigrationsAllowed') && !sakazukiSchemaMigrationsAllowed()) {
        return $ready = false;
    }
    $sql = "CREATE TABLE IF NOT EXISTS automation_job_state (
        job_name VARCHAR(64) NOT NULL,
        last_started_at DATETIME NULL,
        last_finished_at DATETIME NULL,
        last_success_at DATETIME NULL,
        next_run_at DATETIME NULL,
        consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
        last_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        last_runner VARCHAR(20) NOT NULL DEFAULT '',
        last_message VARCHAR(1000) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (job_name),
        KEY idx_automation_next_run (next_run_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('Automation schema failed: ' . $conn->error);
        return $ready = false;
    }
    return $ready = true;
}


/**
 * Best-effort indexes for the admin transaction history read path. These are
 * deliberately maintained by the maintenance runner, never by an admin page
 * request, so customer/admin traffic cannot trigger DDL or metadata locks.
 *
 * @return array<string,mixed>
 */
function automationEnsureAdminTransactionIndexes(): array
{
    global $conn;
    $started = microtime(true);
    $result = [
        'success' => false,
        'skipped' => false,
        'added' => [],
        'present' => [],
        'duration_ms' => 0,
    ];
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $result['message'] = 'Database connection unavailable';
        return $result;
    }
    if (!function_exists('sakazukiSchemaMigrationsAllowed') || !sakazukiSchemaMigrationsAllowed()) {
        $result['skipped'] = true;
        $result['message'] = 'Schema migrations are disabled';
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $result;
    }

    try {
        $columns = [];
        $columnResult = $conn->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions'"
        );
        if ($columnResult) {
            while ($row = $columnResult->fetch_assoc()) {
                $name = strtolower(trim((string) ($row['COLUMN_NAME'] ?? '')));
                if ($name !== '') $columns[$name] = true;
            }
            $columnResult->free();
        }
        foreach (['id', 'user_id', 'created_at'] as $required) {
            if (empty($columns[$required])) {
                $result['message'] = 'transactions table is missing required columns';
                $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                return $result;
            }
        }

        $existing = [];
        $indexResult = $conn->query('SHOW INDEX FROM transactions');
        if ($indexResult) {
            while ($row = $indexResult->fetch_assoc()) {
                $name = (string) ($row['Key_name'] ?? '');
                $column = strtolower(trim((string) ($row['Column_name'] ?? '')));
                $seq = (int) ($row['Seq_in_index'] ?? 0);
                if ($name === '' || $column === '' || $seq < 1) continue;
                $existing[$name][$seq] = $column;
            }
            $indexResult->free();
        }
        foreach ($existing as &$indexColumns) {
            ksort($indexColumns);
            $indexColumns = array_values($indexColumns);
        }
        unset($indexColumns);

        $desired = [
            'idx_transactions_created_id' => ['created_at', 'id'],
            'idx_transactions_user_created_id' => ['user_id', 'created_at', 'id'],
        ];
        $missing = [];
        foreach ($desired as $name => $wantedColumns) {
            $found = false;
            foreach ($existing as $actualColumns) {
                if (array_slice($actualColumns, 0, count($wantedColumns)) === $wantedColumns) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $result['present'][] = $name;
            } else {
                $safeName = isset($existing[$name]) ? $name . '_v2' : $name;
                $missing[$safeName] = $wantedColumns;
            }
        }

        if ($missing) {
            $clauses = [];
            foreach ($missing as $name => $indexColumns) {
                $columnSql = implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $indexColumns));
                $clauses[] = 'ADD INDEX `' . $name . '` (' . $columnSql . ')';
            }
            if (!$conn->query('ALTER TABLE `transactions` ' . implode(', ', $clauses))) {
                $result['message'] = 'Unable to create transaction history indexes';
                $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                error_log('Admin transaction index migration failed: ' . $conn->error);
                return $result;
            }
            $result['added'] = array_keys($missing);
        }

        $result['success'] = true;
        $result['message'] = $missing ? 'Transaction history indexes prepared' : 'Transaction history indexes already ready';
    } catch (Throwable $e) {
        $result['message'] = 'Transaction history index maintenance failed';
        error_log('Admin transaction index maintenance failed: ' . $e->getMessage());
    }
    $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
    return $result;
}


/**
 * Apply storefront-critical schema migrations only from the private CLI worker.
 * Browser traffic is deliberately excluded by sakazukiSchemaMigrationsAllowed().
 */
function automationPrepareStorefrontSchemas(): array
{
    global $conn;
    if (!function_exists('sakazukiSchemaMigrationsAllowed') || !sakazukiSchemaMigrationsAllowed()) {
        return ['success' => false, 'skipped' => true, 'message' => 'Schema migrations are CLI-only'];
    }
    if (!automationEnsureSchema()) {
        return ['success' => false, 'message' => 'Automation schema is unavailable'];
    }

    $marker = function_exists('sakazukiStorefrontSchemaMarker')
        ? sakazukiStorefrontSchemaMarker()
        : 'schema_storefront_20260904_store_api_cgo_fallback_v1';
    $markerCheck = $conn->prepare(
        'SELECT 1 FROM automation_job_state WHERE job_name = ? AND last_success_at IS NOT NULL LIMIT 1'
    );
    if ($markerCheck) {
        $markerPresent = false;
        $markerCheck->bind_param('s', $marker);
        if ($markerCheck->execute()) {
            $markerCheck->store_result();
            $markerPresent = $markerCheck->num_rows > 0;
        }
        $markerCheck->close();
        if ($markerPresent) {
            // A marker is only a deployment optimization. Validate the
            // storefront-critical runtime schemas before skipping so the CLI
            // worker can repair a partial restore/deploy even if the marker
            // table survived intact.
            $storeBridgeReady = function_exists('storeBridgeCgoDeliverySchemaReady')
                ? storeBridgeCgoDeliverySchemaReady(true)
                : (function_exists('storeBridgeRuntimeSchemaReady') ? storeBridgeRuntimeSchemaReady(true) : false);
            $cgoReady = function_exists('cgoRuntimeSchemaReady') ? cgoRuntimeSchemaReady(true) : false;
            $walletLedgerReady = function_exists('walletLedgerRuntimeSchemaReady')
                ? walletLedgerRuntimeSchemaReady(true)
                : false;
            $localCheckoutHealth = function_exists('localCheckoutRuntimeHealth')
                ? localCheckoutRuntimeHealth(true)
                : ['core_ready' => false];
            $localCheckoutReady = !empty($localCheckoutHealth['core_ready']);
            $transactionTypesReady = function_exists('transactionIntegrityTypeColumnInfo')
                && function_exists('transactionIntegrityTypeColumnReady')
                ? transactionIntegrityTypeColumnReady(transactionIntegrityTypeColumnInfo(true))
                : false;
            $commerceCenterReady = function_exists('commerceCenterEnsureSchema')
                ? commerceCenterEnsureSchema()
                : false;
            $rateLimitReady = function_exists('ensureRateLimitSchema')
                ? ensureRateLimitSchema()
                : false;
            $accountVerificationReady = function_exists('accountVerificationRuntimeSchemaReady')
                ? accountVerificationRuntimeSchemaReady(true)
                : false;
            if ($storeBridgeReady && $cgoReady && $walletLedgerReady && $localCheckoutReady && $transactionTypesReady && $commerceCenterReady && $rateLimitReady && $accountVerificationReady) {
                return ['success' => true, 'skipped' => true, 'message' => 'Storefront schema version is already prepared'];
            }
            // Fall through: the maintenance runner independently repairs any
            // missing checkout schema, including an older wallet ledger table.
        }
    }

    $checks = [
        'automation' => true,
        'announcements' => (bool) ensureAnnouncementTable(),
        'store_branding' => (bool) ensureStoreBrandingTable(),
        'category_metadata' => (bool) ensureCategoriesTable(),
        'categories' => (bool) ensureProductCategoryLinksTable(),
        'platforms' => ensureProductPlatformsTable(),
        'archives' => ensureProductArchiveTables(),
        'special_prices' => (bool) ensureResellerVariantPricesTable(),
        'profit_columns' => (bool) ensureProfitColumns(),
        'purchase_activity' => (bool) purchaseActivityEnsureEventTable(),
        'rate_limits' => function_exists('ensureRateLimitSchema') ? (bool) ensureRateLimitSchema() : false,
        'account_verification' => function_exists('accountVerificationEnsureSchema') ? (bool) accountVerificationEnsureSchema() : false,
        'commerce_center' => function_exists('commerceCenterEnsureSchema') ? (bool) commerceCenterEnsureSchema() : false,
        'wallet_ledger' => (bool) ensureWalletLedgerSchema(),
        'local_checkout' => function_exists('localCheckoutEnsureAdditiveSchema')
            ? (bool) localCheckoutEnsureAdditiveSchema()
            : false,
        'store_bridge' => storeBridgeEnsureSchema(),
        'cgo' => cgoEnsureTables(),
    ];
    $failed = array_keys(array_filter($checks, static fn($ok): bool => !$ok));
    if ($failed === []) {
        $message = 'Storefront schema version prepared';
        $stmt = $conn->prepare(
            "INSERT INTO automation_job_state
             (job_name,last_started_at,last_finished_at,last_success_at,next_run_at,
              consecutive_failures,last_duration_ms,last_runner,last_message)
             VALUES (?,NOW(),NOW(),NOW(),NULL,0,0,'cron',?)
             ON DUPLICATE KEY UPDATE last_started_at=NOW(),last_finished_at=NOW(),last_success_at=NOW(),
               next_run_at=NULL,consecutive_failures=0,last_duration_ms=0,last_runner='cron',last_message=VALUES(last_message)"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $marker, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
    return [
        'success' => $failed === [],
        'checks' => $checks,
        'failed' => $failed,
        'message' => $failed === [] ? 'Storefront schema is ready' : 'Storefront schema preparation failed',
    ];
}

/** Record each real hosting-cron invocation, even when no individual job is due. */
function automationRecordCronHeartbeat(string $runner = 'cron'): bool
{
    global $conn;
    if (!automationEnsureSchema()) return false;
    $runner = substr(strtolower(trim($runner)), 0, 20);
    if ($runner === '') $runner = 'cron';
    $jobName = 'cron_heartbeat';
    $stmt = $conn->prepare(
        "INSERT INTO automation_job_state
         (job_name, last_started_at, last_finished_at, last_success_at, next_run_at,
          consecutive_failures, last_duration_ms, last_runner, last_message)
         VALUES (?, NOW(), NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 2 MINUTE), 0, 0, ?, 'Hosting cron heartbeat')
         ON DUPLICATE KEY UPDATE
           last_started_at=NOW(), last_finished_at=NOW(), last_success_at=NOW(),
           next_run_at=DATE_ADD(NOW(), INTERVAL 2 MINUTE), consecutive_failures=0,
           last_duration_ms=0, last_runner=VALUES(last_runner), last_message=VALUES(last_message)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $jobName, $runner);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * True when the private hosting cron has checked in recently.
 * Web fallback uses this to avoid supplier network work while cron is healthy.
 */
function automationCronIsHealthy(int $maxAgeSeconds = 180): bool
{
    global $conn;
    $maxAgeSeconds = max(90, min(900, $maxAgeSeconds));
    if (!automationEnsureSchema()) return false;
    $jobName = 'cron_heartbeat';
    $stmt = $conn->prepare(
        'SELECT last_finished_at, last_runner FROM automation_job_state WHERE job_name = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $jobName);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return false;
    $lastRunner = strtolower(trim((string) ($row['last_runner'] ?? '')));
    // Shared hosting is inconsistent: some panels can execute a private PHP
    // CLI script, while others only provide an HTTPS URL scheduler. Both are
    // authoritative background runners because neither depends on a customer
    // keeping the storefront open.
    if (!in_array($lastRunner, ['cron', 'web_cron'], true)) return false;
    $ts = strtotime((string) ($row['last_finished_at'] ?? ''));
    return $ts !== false && $ts >= time() - $maxAgeSeconds;
}


/**
 * Encrypt the external-scheduler token for convenient Admin copy buttons.
 * Authentication still uses the SHA-256 hash; this encrypted copy exists only
 * so an authenticated administrator can reconstruct the ready-to-paste URLs.
 */
function automationEncryptSchedulerToken(string $token): ?string
{
    $token = strtolower(trim($token));
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
        || !function_exists('openssl_encrypt')
        || !function_exists('storeBridgeEncryptionKey')) {
        return null;
    }
    try {
        $key = storeBridgeEncryptionKey(true);
        if (!is_string($key) || strlen($key) !== 32) return null;
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $token,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'automation.scheduler.web_token'
        );
        if (!is_string($cipher) || strlen($tag) !== 16) return null;
        $blob = $nonce . $tag . $cipher;
        return 'v1.' . rtrim(strtr(base64_encode($blob), '+/', '-_'), '=');
    } catch (Throwable $e) {
        error_log('Automation scheduler token encryption failed');
        return null;
    }
}

/** Decrypt the Admin-only scheduler token copy without logging plaintext. */
function automationDecryptSchedulerToken(string $encoded): ?string
{
    $encoded = trim($encoded);
    if (strncmp($encoded, 'v1.', 3) !== 0 || strlen($encoded) > 512
        || !function_exists('openssl_decrypt')) {
        return null;
    }
    $b64 = strtr(substr($encoded, 3), '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $blob = base64_decode($b64, true);
    if (!is_string($blob) || strlen($blob) < 29) return null;
    $nonce = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $cipher = substr($blob, 28);

    $keys = [];
    if (function_exists('storeBridgeDecryptionKeys')) {
        foreach ((array) storeBridgeDecryptionKeys() as $candidate) {
            if (is_string($candidate) && strlen($candidate) === 32) {
                $keys[hash('sha256', $candidate)] = $candidate;
            }
        }
    } elseif (function_exists('storeBridgeEncryptionKey')) {
        $candidate = storeBridgeEncryptionKey(false);
        if (is_string($candidate) && strlen($candidate) === 32) {
            $keys[hash('sha256', $candidate)] = $candidate;
        }
    }

    foreach ($keys as $key) {
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'automation.scheduler.web_token'
        );
        if (is_string($plain) && preg_match('/^[a-f0-9]{64}$/D', $plain) === 1) {
            return strtolower($plain);
        }
    }
    error_log('Automation scheduler token decryption failed');
    return null;
}

/**
 * Derive a maintenance-only web credential from the scheduler hash and the
 * site's private encryption key. The one-minute critical credential is never
 * changed or reused directly for maintenance after v2.
 *
 * The returned credential is a mode-bound HMAC, not reversible encryption.
 * A leaked critical URL therefore does not reveal the maintenance credential
 * unless the server-side encryption key is also compromised.
 */
function automationDeriveMaintenanceWebToken(string $schedulerHash, ?string $key = null): ?string
{
    $schedulerHash = strtolower(trim($schedulerHash));
    if (preg_match('/^[a-f0-9]{64}$/D', $schedulerHash) !== 1) return null;

    if ($key === null) {
        if (!function_exists('storeBridgeEncryptionKey')) return null;
        $key = storeBridgeEncryptionKey(false);
    }
    if (!is_string($key) || strlen($key) !== 32) return null;

    $scope = 'sakazuki.automation.maintenance.v2|' . automationDatabaseFingerprint();
    return hash_hmac('sha256', $scope . '|' . $schedulerHash, $key);
}

/**
 * Build accepted maintenance credentials for the current and retained legacy
 * encryption keys. This keeps a maintenance URL valid across planned key
 * rotation while the old key remains in the configured decryption key ring.
 *
 * @return string[]
 */
function automationMaintenanceWebTokenCandidates(string $schedulerHash): array
{
    $schedulerHash = strtolower(trim($schedulerHash));
    if (preg_match('/^[a-f0-9]{64}$/D', $schedulerHash) !== 1) return [];

    $keys = [];
    if (function_exists('storeBridgeDecryptionKeys')) {
        foreach ((array) storeBridgeDecryptionKeys() as $candidate) {
            if (is_string($candidate) && strlen($candidate) === 32) {
                $keys[hash('sha256', $candidate)] = $candidate;
            }
        }
    } elseif (function_exists('storeBridgeEncryptionKey')) {
        $candidate = storeBridgeEncryptionKey(false);
        if (is_string($candidate) && strlen($candidate) === 32) {
            $keys[hash('sha256', $candidate)] = $candidate;
        }
    }

    $tokens = [];
    foreach ($keys as $key) {
        $token = automationDeriveMaintenanceWebToken($schedulerHash, $key);
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1) {
            $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

function automationDatabaseFingerprint(): string
{
    $database = defined('DB_NAME') ? (string) DB_NAME : 'default';
    return substr(hash('sha256', $database), 0, 20);
}

function automationReadJobState(string $jobName): ?array
{
    global $conn;
    if (!automationEnsureSchema()) return null;
    $stmt = $conn->prepare('SELECT * FROM automation_job_state WHERE job_name = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $jobName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function automationJobIsDue(string $jobName): bool
{
    $state = automationReadJobState($jobName);
    if (!$state || empty($state['next_run_at'])) return true;
    $next = strtotime((string) $state['next_run_at']);
    return $next === false || $next <= time();
}

function automationResultMessage(array $result): string
{
    if (isset($result['message']) && is_scalar($result['message'])) {
        $message = trim((string) $result['message']);
        if ($message !== '') return substr($message, 0, 1000);
    }
    $summary = [];
    foreach (['attempted', 'processed', 'succeeded', 'updated', 'published', 'reconciled', 'failed', 'deleted', 'skipped'] as $key) {
        if (!array_key_exists($key, $result) || !is_scalar($result[$key])) continue;
        $summary[] = $key . '=' . (string) $result[$key];
    }
    return substr(implode(', ', $summary), 0, 1000);
}

function automationRunJob(
    string $jobName,
    int $successInterval,
    callable $callback,
    string $runner = 'cron',
    bool $force = false
): array {
    global $conn;
    $jobName = strtolower(trim($jobName));
    $successInterval = max(30, min(86400, $successInterval));
    $runner = substr(strtolower(trim($runner)), 0, 20);
    if (!preg_match('/^[a-z0-9_]{2,64}$/D', $jobName) || !automationEnsureSchema()) {
        return ['success' => false, 'job' => $jobName, 'message' => 'Automation storage is unavailable'];
    }
    if (!$force && !automationJobIsDue($jobName)) {
        return ['success' => true, 'job' => $jobName, 'skipped' => true, 'message' => 'Not due'];
    }

    // Stock-only and full-catalog CGO jobs write the same product rows. Give
    // them one shared lock so two overlapping cron processes cannot race.
    $lockScope = in_array($jobName, ['cgo_inventory', 'cgo_catalog'], true)
        ? 'cgo_sync'
        : $jobName;

    // Both copied websites use the same CHEATGAME API account. Catalogue and
    // inventory refreshes share one server-wide lock across database 010/005 so
    // they do not hammer the same provider endpoint at the same instant. The
    // second site waits briefly and then updates its OWN database, rather than
    // being permanently starved by whichever cron process wins first.
    //
    // pending_orders deliberately stays database-scoped: each site owns a
    // different cgo_orders table and both must be allowed to reconcile their own
    // backlog. Mutating order POSTs are serialized separately by the shared
    // account-level checkout lock in cheatgame.php.
    $sharedCgoSync = in_array($jobName, ['cgo_inventory', 'cgo_catalog'], true);
    if ($sharedCgoSync) {
        $apiFingerprint = substr(hash('sha256', (string) (cgoConfig()['api_key'] ?? '')), 0, 20);
        $lockName = 'auto:cgo:' . $apiFingerprint . ':' . $lockScope;
        // A real cron may wait briefly for the other site's shared provider lock.
        // Customer-triggered fallback must never sit behind that wait, especially
        // on LiteSpeed/shared hosting where fastcgi_finish_request() may not exist.
        $lockWaitSeconds = $runner === 'cron' ? 35 : 0;
    } else {
        $lockName = 'auto:' . automationDatabaseFingerprint() . ':' . $lockScope;
        $lockWaitSeconds = 0;
    }
    $lock = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    if (!$lock) return ['success' => false, 'job' => $jobName, 'message' => 'Automation lock is unavailable'];
    $lock->bind_param('si', $lockName, $lockWaitSeconds);
    $lock->execute();
    $result = $lock->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $lock->close();
    if ((int) ($row['acquired'] ?? 0) !== 1) {
        return ['success' => true, 'job' => $jobName, 'skipped' => true, 'busy' => true, 'message' => 'Already running'];
    }

    $started = microtime(true);
    try {
        if (!$force && !automationJobIsDue($jobName)) {
            return ['success' => true, 'job' => $jobName, 'skipped' => true, 'message' => 'Not due'];
        }

        $start = $conn->prepare(
            "INSERT INTO automation_job_state
             (job_name, last_started_at, next_run_at, last_runner)
             VALUES (?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_runner = VALUES(last_runner)"
        );
        if (!$start) {
            return ['success' => false, 'job' => $jobName, 'message' => 'Automation state could not be prepared'];
        }
        $start->bind_param('ss', $jobName, $runner);
        $startOk = $start->execute();
        $start->close();
        if (!$startOk) {
            return ['success' => false, 'job' => $jobName, 'message' => 'Automation state could not be started'];
        }

        try {
            $jobResult = $callback();
            if (!is_array($jobResult)) $jobResult = ['success' => (bool) $jobResult];
        } catch (Throwable $e) {
            $jobResult = ['success' => false, 'message' => $e->getMessage()];
        }
        $success = ($jobResult['success'] ?? true) !== false;
        $durationMs = max(0, min(2147483647, (int) round((microtime(true) - $started) * 1000)));
        $previous = automationReadJobState($jobName);
        $failures = $success ? 0 : min(20, max(0, (int) ($previous['consecutive_failures'] ?? 0)) + 1);
        $requestedNextInterval = isset($jobResult['next_interval_seconds'])
            ? max(30, min(86400, (int) $jobResult['next_interval_seconds']))
            : $successInterval;
        $retrySeconds = $success
            ? $requestedNextInterval
            : min(900, max(60, 60 * (2 ** min(4, max(0, $failures - 1)))));
        $nextRun = date('Y-m-d H:i:s', time() + $retrySeconds);
        $message = automationResultMessage($jobResult);
        if (!$success) {
            error_log('Automation job failed: ' . $jobName . ($message !== '' ? '; ' . $message : ''));
        }

        $finish = $conn->prepare(
            "UPDATE automation_job_state
             SET last_finished_at = NOW(),
                 last_success_at = IF(?, NOW(), last_success_at),
                 next_run_at = ?, consecutive_failures = ?,
                 last_duration_ms = ?, last_runner = ?, last_message = ?
             WHERE job_name = ?"
        );
        if ($finish) {
            $successInt = $success ? 1 : 0;
            $finish->bind_param(
                'isiisss',
                $successInt,
                $nextRun,
                $failures,
                $durationMs,
                $runner,
                $message,
                $jobName
            );
            $finishOk = $finish->execute();
            $finish->close();
            if (!$finishOk) error_log('Automation state could not be finalized: ' . $jobName);
        } else {
            error_log('Automation state finalization could not be prepared: ' . $jobName);
        }
        $jobResult['success'] = $success;
        $jobResult['job'] = $jobName;
        $jobResult['duration_ms'] = $durationMs;
        $jobResult['next_run_at'] = $nextRun;
        return $jobResult;
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) {
            $release->bind_param('s', $lockName);
            $release->execute();
            $release->close();
        }
    }
}

function automationTableExists(string $table): ?bool
{
    global $conn;
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/iD', $table)) return false;
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) return null;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $cache[$table] = ((int) $count > 0);
}

function automationCanonicalHistoricReference(string $namespace, string $reference): string
{
    $reference = trim($reference);
    if ($namespace !== 'binance_tx') return $reference;
    if (preg_match('/^[a-f0-9]{64}$/iD', $reference) === 1) return strtolower($reference);
    if (preg_match('/^off[\s-]*chain[\s-]*transfer[\s:]+([A-Za-z0-9][A-Za-z0-9_-]{5,100})$/iD', $reference, $match) === 1) {
        return 'Off-chain transfer ' . $match[1];
    }
    return $reference;
}

/**
 * Register a few pre-upgrade deposits per run. Only one-way hashes leave this
 * website because sharedLedgerBegin hashes the reference before a remote call.
 */

function automationPublishSlipHistoryReadiness(): array
{
    $transactionReady = trim((string) getSetting('shared_ledger_backfill_slip_cursor', '')) === '0';
    $imageReady = trim((string) getSetting('shared_ledger_backfill_slip_image_cursor', '')) === '0';
    if (!$transactionReady || !$imageReady) {
        return [
            'success' => true,
            'ready' => false,
            'transaction_ready' => $transactionReady,
            'image_ready' => $imageReady,
        ];
    }
    if (!function_exists('sharedLedgerCurrentSiteId')) {
        return ['success' => false, 'ready' => false, 'message' => 'Canonical shared-ledger site identity helper is unavailable'];
    }
    $siteId = sharedLedgerCurrentSiteId();
    if ($siteId === '' || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) {
        return ['success' => false, 'ready' => false, 'message' => 'Canonical site identity for slip-history readiness is unavailable'];
    }
    $marker = sharedLedgerBegin('slip_history_ready', $siteId, 30, 'history-ready:' . $siteId);
    if (!empty($marker['success'])) {
        $completed = sharedLedgerComplete((array) ($marker['lease'] ?? []));
        if (empty($completed['success'])) {
            return ['success' => false, 'ready' => false, 'site_id' => $siteId, 'message' => 'Slip-history readiness marker could not be finalized'];
        }
    } elseif (!(!empty($marker['duplicate']) && strtolower((string) ($marker['status'] ?? '')) === 'completed')) {
        return ['success' => false, 'ready' => false, 'site_id' => $siteId, 'message' => 'Slip-history readiness marker is unavailable'];
    }
    return ['success' => true, 'ready' => true, 'site_id' => $siteId];
}

function automationBackfillSharedDepositHistory(int $batchPerStream = 100): array
{
    global $conn;
    $batchPerStream = max(10, min(250, $batchPerStream));
    // Some older deployments predate the slip_hash column. Run the idempotent
    // slip schema migration before querying historic image hashes so backfill
    // cannot stall forever on an otherwise healthy legacy database.
    if (function_exists('ensureSlipDepositsTable') && !ensureSlipDepositsTable()) {
        return ['success' => false, 'processed' => 0, 'message' => 'Slip history schema is unavailable'];
    }
    $summary = ['success' => true, 'processed' => 0, 'completed_streams' => 0, 'streams' => []];
    $streams = [
        [
            'name' => 'slip',
            'table' => 'slip_deposits',
            'reference_column' => 'transaction_ref',
            'namespace' => 'slip_transaction',
            'where' => "transaction_ref IS NOT NULL AND TRIM(transaction_ref) <> ''",
            'bulk' => true,
        ],
        [
            'name' => 'slip_image',
            'table' => 'slip_deposits',
            'reference_column' => 'slip_hash',
            'namespace' => 'slip_image',
            'where' => "slip_hash IS NOT NULL AND slip_hash REGEXP '^[0-9A-Fa-f]{64}$'",
            'bulk' => true,
        ],
    ];

    foreach ($streams as $stream) {
        $streamName = (string) $stream['name'];
        $table = (string) $stream['table'];
        $tableExists = automationTableExists($table);
        if ($tableExists === null) {
            return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history storage could not be inspected'];
        }
        if (!$tableExists) {
            $summary['completed_streams']++;
            $summary['streams'][$streamName] = ['completed' => true, 'remaining' => 0, 'cursor' => 0];
            continue;
        }

        $settingKey = 'shared_ledger_backfill_' . $streamName . '_cursor';
        $storedCursor = trim((string) getSetting($settingKey, ''));
        if ($storedCursor === '0') {
            $summary['completed_streams']++;
            $summary['streams'][$streamName] = ['completed' => true, 'remaining' => 0, 'cursor' => 0];
            continue;
        }
        if ($storedCursor === '' || !ctype_digit($storedCursor)) {
            $maxResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM `{$table}`");
            if (!$maxResult) {
                return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history starting point could not be read'];
            }
            $maxRow = $maxResult->fetch_assoc();
            $maxResult->free();
            $maxId = max(0, (int) ($maxRow['max_id'] ?? 0));
            if ($maxId < 1) {
                upsertSetting($settingKey, '0');
                $summary['completed_streams']++;
                $summary['streams'][$streamName] = ['completed' => true, 'remaining' => 0, 'cursor' => 0];
                continue;
            }
            $cursor = $maxId + 1;
            if (!upsertSetting($settingKey, (string) $cursor)) {
                return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history cursor could not be created'];
            }
        } else {
            $cursor = max(1, (int) $storedCursor);
        }

        $referenceColumn = (string) $stream['reference_column'];
        $where = (string) $stream['where'];
        // Slip history uses one HMAC-authenticated bulk request. Binance keeps its
        // older per-reference path and remains intentionally small so a large slip
        // migration cannot multiply unrelated supplier traffic.
        $queryLimit = !empty($stream['bulk']) ? $batchPerStream : min(5, $batchPerStream);
        $sql = "SELECT id, `{$referenceColumn}` AS reference_value
                FROM `{$table}`
                WHERE id < ? AND {$where}
                ORDER BY id DESC LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared history could not be read'];
        }
        $stmt->bind_param('ii', $cursor, $queryLimit);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared history query failed'];
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared history result is unavailable'];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!empty($stream['bulk'])) {
            // Prepare the whole descending page first, but do NOT advance the
            // durable cursor yet. If the hub reports a conflict for an earlier
            // row, advancing past a later invalid row here would permanently skip
            // the blocked financial identifier and could publish a false-ready
            // marker. Cursor movement therefore happens only in row order below.
            $preparedRows = [];
            $references = [];
            foreach ($rows as $row) {
                $rowId = max(1, (int) ($row['id'] ?? 0));
                $reference = automationCanonicalHistoricReference(
                    (string) $stream['namespace'],
                    (string) ($row['reference_value'] ?? '')
                );
                $preparedRows[] = ['id' => $rowId, 'reference' => $reference];
                if ($reference !== '') $references[] = $reference;
            }

            $itemMap = [];
            if ($references !== []) {
                if (!function_exists('sharedLedgerImportHistoryBatch')) {
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history bulk import helper is unavailable'];
                }
                $siteId = function_exists('sharedLedgerCurrentSiteId') ? sharedLedgerCurrentSiteId() : '';
                $import = sharedLedgerImportHistoryBatch((string) $stream['namespace'], $references, $siteId);
                if (empty($import['success'])) {
                    $summary['streams'][$streamName] = [
                        'completed' => false,
                        'cursor' => $cursor,
                        'last_error' => (string) ($import['message'] ?? 'Historical import failed'),
                    ];
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => (string) ($import['message'] ?? 'Shared history connection is unavailable')];
                }
                foreach ((array) ($import['items'] ?? []) as $item) {
                    if (!is_array($item)) continue;
                    $hash = strtolower(trim((string) ($item['reference_hash'] ?? '')));
                    if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) $itemMap[$hash] = $item;
                }
            }

            foreach ($preparedRows as $preparedRow) {
                $rowId = (int) $preparedRow['id'];
                $reference = (string) $preparedRow['reference'];
                if ($reference !== '') {
                    $hash = hash('sha256', (string) $stream['namespace'] . "\0" . $reference);
                    $item = $itemMap[$hash] ?? null;
                    $status = is_array($item) ? strtolower(trim((string) ($item['status'] ?? ''))) : '';
                    if ($status !== 'completed') {
                        $summary['streams'][$streamName] = [
                            'completed' => false,
                            'cursor' => $cursor,
                            'blocked_row_id' => $rowId,
                            'blocked_status' => $status !== '' ? $status : 'unknown',
                        ];
                        return [
                            'success' => false,
                            'processed' => $summary['processed'],
                            'streams' => $summary['streams'],
                            'message' => 'A historic reference is not terminal in the shared ledger yet',
                        ];
                    }
                }

                if (!upsertSetting($settingKey, (string) $rowId)) {
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history cursor could not be saved'];
                }
                $cursor = $rowId;
                if ($reference !== '') $summary['processed']++;
            }
        } else {
            foreach ($rows as $row) {
                $rowId = max(1, (int) ($row['id'] ?? 0));
                $reference = automationCanonicalHistoricReference(
                    (string) $stream['namespace'],
                    (string) ($row['reference_value'] ?? '')
                );
                if ($reference === '') {
                    if (!upsertSetting($settingKey, (string) $rowId)) {
                        return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history cursor could not be saved'];
                    }
                    $cursor = $rowId;
                    continue;
                }
                $ownerScope = 'historic:' . (defined('DB_NAME') ? (string) DB_NAME : 'db') . ':' . $table . ':' . $rowId;
                $claim = sharedLedgerBegin((string) $stream['namespace'], $reference, 300, $ownerScope);
                if (empty($claim['success'])) {
                    $claimStatus = strtolower(trim((string) ($claim['status'] ?? '')));
                    if (!empty($claim['duplicate']) && $claimStatus === 'completed') {
                        if (!upsertSetting($settingKey, (string) $rowId)) {
                            return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history cursor could not be saved'];
                        }
                        $cursor = $rowId;
                        $summary['processed']++;
                        continue;
                    }
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => !empty($claim['duplicate']) ? 'A historic reference is not terminal in the shared ledger yet' : 'Shared history connection is unavailable'];
                }
                $completed = sharedLedgerComplete($claim['lease']);
                if (empty($completed['success'])) {
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Historic reference could not be finalized'];
                }
                if (!upsertSetting($settingKey, (string) $rowId)) {
                    return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history cursor could not be saved'];
                }
                $cursor = $rowId;
                $summary['processed']++;
            }
        }

        if (count($rows) < $queryLimit) {
            if (!upsertSetting($settingKey, '0')) {
                return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => 'Shared-history completion could not be saved'];
            }
            $cursor = 0;
            $summary['completed_streams']++;
        }

        $remaining = 0;
        if ($cursor > 0) {
            $countStmt = $conn->prepare("SELECT COUNT(*) AS remaining FROM `{$table}` WHERE id < ? AND {$where}");
            if ($countStmt) {
                $countStmt->bind_param('i', $cursor);
                if ($countStmt->execute()) {
                    $countResult = $countStmt->get_result();
                    $countRow = $countResult ? $countResult->fetch_assoc() : null;
                    $remaining = max(0, (int) ($countRow['remaining'] ?? 0));
                }
                $countStmt->close();
            }
        }
        $summary['streams'][$streamName] = [
            'completed' => $cursor === 0,
            'cursor' => $cursor,
            'remaining' => $remaining,
            'batch_size' => $queryLimit,
        ];

        // Publish bank-slip readiness immediately after the second slip stream.
        // Binance history is an unrelated payment rail and cannot be allowed to
        // block the marker that releases verified bank slips into their normal
        // cross-site financial fencing path.
        if ($streamName === 'slip_image') {
            $readiness = automationPublishSlipHistoryReadiness();
            if (empty($readiness['success'])) {
                return [
                    'success' => false,
                    'processed' => $summary['processed'],
                    'streams' => $summary['streams'],
                    'message' => (string) ($readiness['message'] ?? 'Slip-history readiness marker could not be published'),
                ];
            }
            $summary['slip_history_ready'] = !empty($readiness['ready']);
            $summary['slip_history_site_id'] = (string) ($readiness['site_id'] ?? '');
        }
    }

    // Bank-slip readiness is deliberately independent from Binance history. A
    // stalled Binance migration must never keep normal bank-slip deposits behind
    // a global readiness gate. The marker still cannot be published until BOTH
    // bank-slip cursors are durably complete.
    $readiness = automationPublishSlipHistoryReadiness();
    if (empty($readiness['success'])) {
        return ['success' => false, 'processed' => $summary['processed'], 'streams' => $summary['streams'], 'message' => (string) ($readiness['message'] ?? 'Slip-history readiness marker could not be published')];
    }
    $summary['slip_history_ready'] = !empty($readiness['ready']);
    $summary['slip_history_site_id'] = (string) ($readiness['site_id'] ?? '');
    $summary['next_interval_seconds'] = $summary['completed_streams'] >= count($streams) ? 3600 : 30;
    return $summary;
}

/**
 * Binance history has a separate lifecycle from bank slips. Keeping it in its
 * own job prevents a stale Binance reference or provider outage from delaying
 * `slip_history_ready` and blocking every bank-slip deposit.
 */
/**
 * Deployment/admin drain for bank-slip history only. Each iteration still goes
 * through automationRunJob(), so the same named lock used by cron/web fallback
 * serializes cursor updates. The loop is time/batch bounded and never forces a
 * readiness marker; the normal backfill publishes it only after both cursors hit 0.
 */
function automationDrainSharedDepositHistory(int $maxBatches = 40, int $timeBudgetSeconds = 25, int $batchSize = 250, string $runner = 'admin_drain'): array
{
    $maxBatches = max(1, min(500, $maxBatches));
    $timeBudgetSeconds = max(3, min(60, $timeBudgetSeconds));
    $batchSize = max(10, min(250, $batchSize));
    $started = microtime(true);
    $runs = [];
    $processed = 0;
    $readyLocal = false;
    for ($i = 0; $i < $maxBatches; $i++) {
        if ($i > 0 && microtime(true) - $started >= $timeBudgetSeconds) break;
        $result = automationRunJob(
            'shared_history',
            30,
            static function () use ($batchSize): array { return automationBackfillSharedDepositHistory($batchSize); },
            $runner,
            true
        );
        $runs[] = $result;
        $processed += max(0, (int) ($result['processed'] ?? 0));
        if (($result['success'] ?? false) === false) {
            return [
                'success' => false,
                'processed' => $processed,
                'batches' => count($runs),
                'slip_history_ready_local' => false,
                'last_result' => $result,
                'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
                'message' => (string) ($result['message'] ?? 'Shared-history drain failed'),
            ];
        }
        $readyLocal = !empty($result['slip_history_ready']);
        if ($readyLocal) break;
        if (!empty($result['skipped']) && !empty($result['busy'])) break;
        if ((int) ($result['processed'] ?? 0) === 0) break;
    }
    $global = function_exists('sharedLedgerHistoryReady') ? sharedLedgerHistoryReady() : ['success' => false, 'ready' => false, 'message' => 'Shared-history status unavailable'];
    return [
        'success' => true,
        'processed' => $processed,
        'batches' => count($runs),
        'slip_history_ready_local' => $readyLocal,
        'global_history_ready' => !empty($global['ready']),
        'global_history_status' => $global,
        'last_result' => $runs !== [] ? $runs[count($runs) - 1] : null,
        'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        'time_budget_exhausted' => !$readyLocal && microtime(true) - $started >= $timeBudgetSeconds,
    ];
}

function automationBackfillSharedBinanceHistory(int $batch = 5): array
{
    global $conn;
    $batch = max(1, min(20, $batch));
    $table = 'binance_deposits';
    $exists = automationTableExists($table);
    if ($exists === null) return ['success' => false, 'processed' => 0, 'message' => 'Binance history storage could not be inspected'];
    if (!$exists) return ['success' => true, 'processed' => 0, 'completed' => true, 'remaining' => 0, 'next_interval_seconds' => 3600];

    $where = "tx_id IS NOT NULL AND TRIM(tx_id) <> '' AND binance_status = 1 AND processing = 0 AND user_id > 0";
    $settingKey = 'shared_ledger_backfill_binance_cursor';
    $storedCursor = trim((string) getSetting($settingKey, ''));
    if ($storedCursor === '') {
        $maxResult = $conn->query("SELECT MAX(id) AS max_id FROM `{$table}` WHERE {$where}");
        $maxRow = $maxResult ? $maxResult->fetch_assoc() : null;
        if ($maxResult) $maxResult->free();
        $cursor = max(0, (int) ($maxRow['max_id'] ?? 0));
        if (!upsertSetting($settingKey, (string) $cursor)) return ['success' => false, 'processed' => 0, 'message' => 'Binance history cursor could not be initialized'];
    } else {
        $cursor = max(0, (int) $storedCursor);
    }
    if ($cursor === 0) return ['success' => true, 'processed' => 0, 'completed' => true, 'remaining' => 0, 'next_interval_seconds' => 3600];

    $stmt = $conn->prepare("SELECT id, tx_id AS reference_value FROM `{$table}` WHERE id <= ? AND {$where} ORDER BY id DESC LIMIT ?");
    if (!$stmt) return ['success' => false, 'processed' => 0, 'message' => 'Binance history query could not be prepared'];
    $stmt->bind_param('ii', $cursor, $batch);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'processed' => 0, 'message' => 'Binance history query failed']; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $processed = 0;
    foreach ($rows as $row) {
        $rowId = max(1, (int) ($row['id'] ?? 0));
        $reference = automationCanonicalHistoricReference('binance_tx', (string) ($row['reference_value'] ?? ''));
        if ($reference !== '') {
            $ownerScope = 'historic:' . (defined('DB_NAME') ? (string) DB_NAME : 'db') . ':' . $table . ':' . $rowId;
            $claim = sharedLedgerBegin('binance_tx', $reference, 300, $ownerScope);
            if (empty($claim['success'])) {
                $status = strtolower(trim((string) ($claim['status'] ?? '')));
                if (!(!empty($claim['duplicate']) && $status === 'completed')) {
                    return ['success' => false, 'processed' => $processed, 'cursor' => $cursor, 'blocked_row_id' => $rowId, 'blocked_status' => $status, 'message' => !empty($claim['duplicate']) ? 'A historic Binance reference is not terminal yet' : 'Shared Binance history connection is unavailable'];
                }
            } else {
                $completed = sharedLedgerComplete((array) ($claim['lease'] ?? []));
                if (empty($completed['success'])) return ['success' => false, 'processed' => $processed, 'cursor' => $cursor, 'message' => 'Historic Binance reference could not be finalized'];
            }
        }
        if (!upsertSetting($settingKey, (string) $rowId)) return ['success' => false, 'processed' => $processed, 'cursor' => $cursor, 'message' => 'Binance history cursor could not be saved'];
        $cursor = $rowId;
        $processed++;
    }

    if (count($rows) < $batch) {
        if (!upsertSetting($settingKey, '0')) return ['success' => false, 'processed' => $processed, 'cursor' => $cursor, 'message' => 'Binance history completion could not be saved'];
        $cursor = 0;
    }
    $remaining = 0;
    if ($cursor > 0) {
        $count = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE id < ? AND {$where}");
        if ($count) {
            $count->bind_param('i', $cursor);
            if ($count->execute()) {
                $r = $count->get_result();
                $rr = $r ? $r->fetch_assoc() : null;
                $remaining = max(0, (int) ($rr['c'] ?? 0));
            }
            $count->close();
        }
    }
    return ['success' => true, 'processed' => $processed, 'completed' => $cursor === 0, 'cursor' => $cursor, 'remaining' => $remaining, 'next_interval_seconds' => $cursor === 0 ? 3600 : 60];
}

function automationReconcilePendingOrders(int $cgoLimit = 3, int $supplierLimit = 3): array
{
    global $conn;
    $cgoLimit = max(1, min(10, $cgoLimit));
    $supplierLimit = max(1, min(10, $supplierLimit));
    $summary = ['success' => true, 'attempted' => 0, 'reconciled' => 0, 'failed' => 0];

    if (cgoEnsureTables()) {
        // Reconcile remote orders quickly enough for the customer-facing 30-60s
        // window. cgoReconcileOrder() itself enforces the not-found grace period,
        // read-only lookup, and refund safety checks, so the scheduler can start
        // checking early without ever repeating action=order.
        $result = $conn->query(
            "SELECT id,source_kind,source_order_id FROM cgo_orders
             WHERE (
                    (status IN ('submitting','unknown','pending','processing')
                     AND (
                          (supplier_order_id IS NOT NULL AND supplier_order_id <> ''
                           AND updated_at < DATE_SUB(NOW(), INTERVAL 10 SECOND))
                          OR
                          ((supplier_order_id IS NULL OR supplier_order_id = '')
                           AND updated_at < DATE_SUB(NOW(), INTERVAL 10 SECOND))
                     ))
                    OR
                    (status='manual_review'
                     AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                   )
             ORDER BY updated_at ASC, id ASC LIMIT " . (int) $cgoLimit
        );
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $summary['attempted']++;
            $cgoOrderId = (int) $row['id'];
            $reconciled = cgoReconcileOrder($cgoOrderId);
            if (strtolower(trim((string) ($row['source_kind'] ?? ''))) === 'store_api'
                && (int) ($row['source_order_id'] ?? 0) > 0
                && function_exists('storeBridgeApplyCgoOrderState')) {
                // Finalize/refund the parent Store API order even when its client
                // is not actively polling order_status. This never resubmits CGO.
                storeBridgeApplyCgoOrderState((int) $row['source_order_id'], $cgoOrderId);
            }
            if (!empty($reconciled['success'])) $summary['reconciled']++; else $summary['failed']++;
        }
        if ($result) $result->free();
    }

    if (storeBridgeEnsureSchema()) {
        $result = $conn->query(
            "SELECT so.id,so.source_kind,so.source_order_id FROM supplier_orders so
             JOIN supplier_connections sc ON sc.id=so.connection_id
             WHERE (
                    (so.status IN ('submitting','unknown','pending','processing')
                     AND so.updated_at < DATE_SUB(NOW(), INTERVAL 10 SECOND))
                    OR
                    (so.status='manual_review'
                     AND sc.provider_type NOT IN ('vipstore_v1','starkmods_v1')
                     AND so.updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                     AND so.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                   )
             ORDER BY so.updated_at ASC, so.id ASC LIMIT " . (int) $supplierLimit
        );
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $summary['attempted']++;
            $supplierOrderId = (int) $row['id'];
            $reconciled = supplierBridgeReconcileOrder($supplierOrderId);
            if (strtolower(trim((string) ($row['source_kind'] ?? ''))) === 'store_api'
                && (int) ($row['source_order_id'] ?? 0) > 0
                && function_exists('storeBridgeApplySupplierOrderState')) {
                // Keep the Store API parent in sync even when the reseller is
                // not actively polling order_status. Supplier reconciliation
                // never resubmits protected VIP/Stark purchases.
                storeBridgeApplySupplierOrderState((int) $row['source_order_id'], $supplierOrderId);
            }
            if (!empty($reconciled['success'])) $summary['reconciled']++; else $summary['failed']++;
        }
        if ($result) $result->free();
    }
    return $summary;
}

function automationJobDefinitions(): array
{
    return [
        'pending_orders' => [
            'critical' => true,
            // Keep the scheduler frequent, while each order is independently
            // throttled by updated_at in automationReconcilePendingOrders().
            'interval' => 15,
            'callback' => static function (): array {
                return automationReconcilePendingOrders(5, 3);
            },
        ],
        'slip_reconciliation' => [
            'critical' => true,
            'interval' => 30,
            'callback' => static function (): array {
                return reconcileSlipVerificationJobs(25);
            },
        ],
        'cgo_inventory' => [
            'critical' => true,
            'interval' => 45,
            'callback' => static function (): array {
                return cgoRefreshRemoteInventory(false, 0, 45);
            },
        ],
        'supplier_catalog' => [
            'critical' => true,
            // Full Bridge catalogue refresh. Storefront-visible variants also
            // receive targeted 30-second inventory checks in cgo_inventory.php.
            'interval' => 60,
            'callback' => static function (): array {
                return supplierBridgeRefreshAllEnabled(false);
            },
        ],
        'cgo_catalog' => [
            'critical' => false,
            'interval' => 600,
            'callback' => static function (): array {
                return cgoSyncProducts();
            },
        ],
        'shared_history' => [
            'critical' => false,
            'interval' => 30,
            'callback' => static function (): array {
                return automationBackfillSharedDepositHistory(100);
            },
        ],
        'shared_binance_history' => [
            'critical' => false,
            'interval' => 60,
            'callback' => static function (): array {
                return automationBackfillSharedBinanceHistory(5);
            },
        ],
        // Read-model repair runs after operational stock/catalog jobs so a
        // historic backfill can never delay customer-facing inventory refresh.
        'commerce_center' => [
            'critical' => false,
            'interval' => 120,
            'callback' => static function (): array {
                return commerceCenterReconcile(40);
            },
        ],
        'rate_limit_cleanup' => [
            'critical' => false,
            'interval' => 3600,
            'callback' => static function (): array {
                $ok = function_exists('cleanupRateLimitRows') && cleanupRateLimitRows(2, 5000);
                return [
                    'success' => $ok,
                    'message' => $ok ? 'Expired rate-limit rows cleaned' : 'Rate-limit cleanup failed',
                ];
            },
        ],
        'history_cleanup' => [
            'critical' => false,
            'interval' => 3600,
            'callback' => static function (): array {
                return keyHistoryCleanupRun(false);
            },
        ],
        'product_image_cleanup' => [
            'critical' => false,
            // Reconcile filesystem uploads against database references. A 48h
            // grace period prevents races with uploads and delayed sync work.
            'interval' => 3600,
            'callback' => static function (): array {
                return function_exists('sakazukiCleanupOrphanedProductImages')
                    ? sakazukiCleanupOrphanedProductImages(20, 172800)
                    : ['success' => true, 'skipped' => true, 'message' => 'Product image cleanup is unavailable'];
            },
        ],
        'product_image_optimizer' => [
            'critical' => false,
            // Convert only a few legacy images per run so shared hosting never
            // gets a CPU/memory spike from rebuilding the entire catalogue.
            'interval' => 60,
            'callback' => static function (): array {
                return function_exists('sakazukiOptimizeLegacyProductImages')
                    ? sakazukiOptimizeLegacyProductImages(3)
                    : ['success' => true, 'skipped' => true, 'message' => 'Image optimizer is unavailable'];
            },
        ],
    ];
}

/**
 * Run supplier maintenance from normal storefront traffic when hosting cron is
 * delayed or unavailable. Database due-times and locks keep this cheap under
 * many simultaneous visitors. Unknown remote product IDs force one catalogue
 * sync instead of waiting for an administrator button.
 */
function automationRunStorefrontInventoryMaintenance(
    string $runner = 'web_fallback',
    int $timeBudgetSeconds = 25,
    bool $forceCatalog = false
): array {
    $timeBudgetSeconds = max(5, min(45, $timeBudgetSeconds));
    $started = microtime(true);
    $definitions = automationJobDefinitions();
    $results = [];

    // Pending order recovery is more important than stock freshness. In the
    // previous build this job existed but the storefront fallback never invoked it,
    // so an ambiguous CGO order could remain unknown forever on hosting without a
    // working cron runner. Run a tiny bounded batch first. The MySQL named lock and
    // per-order updated_at thresholds prevent duplicate recovery calls.
    if (automationJobIsDue('pending_orders')) {
        $pendingDefinition = $definitions['pending_orders'];
        $results['pending_orders'] = automationRunJob(
            'pending_orders',
            (int) $pendingDefinition['interval'],
            static function (): array {
                return automationReconcilePendingOrders(2, 2);
            },
            $runner,
            false
        );
    }

    if (microtime(true) - $started < $timeBudgetSeconds) {
        $inventoryDefinition = $definitions['cgo_inventory'];
        $results['cgo_inventory'] = automationRunJob(
            'cgo_inventory',
            (int) $inventoryDefinition['interval'],
            $inventoryDefinition['callback'],
            $runner,
            false
        );
    } else {
        $results['cgo_inventory'] = [
            'success' => true,
            'skipped' => true,
            'budget_exhausted' => true,
            'message' => 'Pending-order recovery used the storefront maintenance budget',
        ];
    }

    // Bridge catalogue refresh is customer-facing inventory work, so do it
    // before the heavier CGO catalogue repair. Previously a slow CGO catalogue
    // refresh could consume the whole web-fallback budget and starve Bridge for
    // another cycle even when its job was already due.
    if (microtime(true) - $started < $timeBudgetSeconds && automationJobIsDue('supplier_catalog')) {
        $supplierDefinition = $definitions['supplier_catalog'];
        $results['supplier_catalog'] = automationRunJob(
            'supplier_catalog',
            (int) $supplierDefinition['interval'],
            $supplierDefinition['callback'],
            $runner,
            false
        );
    }

    $catalogForce = $forceCatalog || !empty($results['cgo_inventory']['catalog_refresh_needed']);
    if (microtime(true) - $started < $timeBudgetSeconds
        && ($catalogForce || automationJobIsDue('cgo_catalog'))) {
        $catalogDefinition = $definitions['cgo_catalog'];
        $results['cgo_catalog'] = automationRunJob(
            'cgo_catalog',
            (int) $catalogDefinition['interval'],
            $catalogDefinition['callback'],
            $runner,
            $catalogForce
        );
    }

    // Historical slip safety must not depend exclusively on hosting cron. Run a
    // bulk, lock-protected batch from normal maintenance traffic when enough web
    // budget remains. The bulk importer sends only hashes and performs at most
    // one shared request per slip stream, so this no longer creates hundreds of
    // round-trips or blocks every visitor.
    if (microtime(true) - $started < max(1, $timeBudgetSeconds - 6) && automationJobIsDue('shared_history')) {
        $historyDefinition = $definitions['shared_history'];
        $results['shared_history'] = automationRunJob(
            'shared_history',
            (int) $historyDefinition['interval'],
            static function (): array { return automationBackfillSharedDepositHistory(100); },
            $runner,
            false
        );
    }

    $success = false;
    foreach ($results as $result) {
        if (is_array($result) && ($result['success'] ?? false) !== false) {
            $success = true;
            break;
        }
    }
    return [
        'success' => $success,
        'results' => $results,
        'catalog_changed' => !empty($results['supplier_catalog']['published']),
        'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
    ];
}

function automationRunDueJobs(
    string $runner = 'cron',
    ?array $allowedJobs = null,
    int $timeBudgetSeconds = 0
): array {
    $allowed = null;
    if (is_array($allowedJobs)) {
        $allowed = [];
        foreach ($allowedJobs as $job) $allowed[(string) $job] = true;
    }
    $started = microtime(true);
    $results = [];
    foreach (automationJobDefinitions() as $jobName => $definition) {
        if ($allowed !== null && !isset($allowed[$jobName])) continue;
        if ($timeBudgetSeconds > 0 && microtime(true) - $started >= $timeBudgetSeconds) break;
        $results[$jobName] = automationRunJob(
            $jobName,
            (int) $definition['interval'],
            $definition['callback'],
            $runner,
            false
        );
    }
    return $results;
}
