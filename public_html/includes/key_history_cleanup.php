<?php
/**
 * Bounded automatic cleanup for key-sale detail older than one year.
 * Deposit/top-up transactions are intentionally excluded.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!defined('KEY_HISTORY_RETENTION_DAYS')) define('KEY_HISTORY_RETENTION_DAYS', 365);
if (!defined('KEY_HISTORY_BATCH_SIZE')) define('KEY_HISTORY_BATCH_SIZE', 500);
if (!defined('KEY_HISTORY_TIME_BUDGET')) define('KEY_HISTORY_TIME_BUDGET', 4.0);

function keyHistoryTableExists(string $table): bool
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table)) return false;
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $cache[$table] = ((int) $count > 0);
}

function keyHistoryColumnExists(string $table, string $column): bool
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count > 0;
}


function keyHistoryEnsureSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    $sql = "CREATE TABLE IF NOT EXISTS key_sales_archive_daily (
        sale_date DATE NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        sale_type VARCHAR(50) NOT NULL,
        transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (sale_date, user_id, sale_type),
        KEY idx_key_sales_archive_user (user_id, sale_date),
        KEY idx_key_sales_archive_date (sale_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('Key history archive schema error: ' . $conn->error);
        return $ready = false;
    }
    return $ready = true;
}

function keyHistoryArchiveAndDeleteTransactionBatch(int $limit): array
{
    global $conn;
    if (!keyHistoryEnsureSchema()) return ['success' => false, 'deleted' => 0, 'more' => false];
    $limit = max(1, min(2000, $limit));
    $sql = "SELECT id, user_id, type, amount, DATE(created_at) AS sale_date
            FROM transactions
            WHERE type IN ('purchase','cgo_purchase','supplier_purchase')
              AND status = 'completed'
              AND created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
            ORDER BY created_at ASC, id ASC LIMIT {$limit}";
    $result = $conn->query($sql);
    if (!$result) {
        error_log('Key transaction archive read failed: ' . $conn->error);
        return ['success' => false, 'deleted' => 0, 'more' => false];
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return ['success' => true, 'deleted' => 0, 'more' => false];

    $groups = [];
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $userId = (int) ($row['user_id'] ?? 0);
        $type = (string) ($row['type'] ?? '');
        $date = (string) ($row['sale_date'] ?? '');
        if ($id < 1 || $userId < 1 || $date === '' || !in_array($type, ['purchase','cgo_purchase','supplier_purchase'], true)) continue;
        $ids[] = $id;
        $key = $date . '|' . $userId . '|' . $type;
        if (!isset($groups[$key])) {
            $groups[$key] = ['date' => $date, 'user_id' => $userId, 'type' => $type, 'count' => 0, 'amount' => 0.0];
        }
        $groups[$key]['count']++;
        $groups[$key]['amount'] += round((float) ($row['amount'] ?? 0), 2);
    }
    if (!$ids) return ['success' => false, 'deleted' => 0, 'more' => false];

    $conn->begin_transaction();
    try {
        $archive = $conn->prepare(
            "INSERT INTO key_sales_archive_daily (sale_date,user_id,sale_type,transaction_count,total_amount)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                transaction_count = transaction_count + VALUES(transaction_count),
                total_amount = total_amount + VALUES(total_amount),
                archived_at = NOW()"
        );
        if (!$archive) throw new RuntimeException('archive prepare failed');
        foreach ($groups as $group) {
            $count = (int) $group['count'];
            $amount = round((float) $group['amount'], 2);
            $archive->bind_param('sisid', $group['date'], $group['user_id'], $group['type'], $count, $amount);
            if (!$archive->execute()) throw new RuntimeException('archive insert failed');
        }
        $archive->close();

        $idList = implode(',', array_map('intval', $ids));
        if (!$conn->query("DELETE FROM transactions WHERE id IN ({$idList})")) {
            throw new RuntimeException('transaction delete failed');
        }
        $deleted = (int) $conn->affected_rows;
        $conn->commit();
        return ['success' => true, 'deleted' => $deleted, 'more' => count($rows) >= $limit];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Key transaction archive failed: ' . $e->getMessage());
        return ['success' => false, 'deleted' => 0, 'more' => false];
    }
}

function keyHistoryDeleteSimpleBatch(string $sql): int
{
    global $conn;
    if (!$conn->query($sql)) {
        throw new RuntimeException('Key history cleanup query failed: ' . $conn->error);
    }
    return max(0, (int) $conn->affected_rows);
}

function keyHistoryCleanupLockName(): string
{
    global $conn;
    $database = '';
    $result = $conn->query('SELECT DATABASE() AS db_name');
    if ($result) {
        $row = $result->fetch_assoc();
        $database = (string) ($row['db_name'] ?? '');
    }
    if ($database === '') $database = 'default';
    // MySQL named locks are server-wide, not database-scoped. Include a stable
    // database fingerprint so the two rebranded sites do not block each other.
    return 'key_history_' . substr(hash('sha256', $database), 0, 40);
}

function keyHistoryCleanupRun(bool $force = false): array
{
    global $conn;
    $summary = [
        'success' => true,
        'deleted' => 0,
        'archived_transactions' => 0,
        'more' => false,
        'skipped' => false,
    ];
    $now = time();
    $nextRun = (int) getSetting('key_history_cleanup_next_run', '0');
    if (!$force && $nextRun > $now) {
        $summary['skipped'] = true;
        $summary['message'] = 'Not due';
        return $summary;
    }
    if (!keyHistoryEnsureSchema()) {
        return ['success' => false, 'deleted' => 0, 'more' => false, 'skipped' => false, 'error_code' => 'history_cleanup_schema_unavailable', 'message' => 'History cleanup archive schema is unavailable'];
    }

    $lockName = keyHistoryCleanupLockName();
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS locked');
    if (!$lockStmt) {
        return ['success' => false, 'deleted' => 0, 'more' => false, 'skipped' => false, 'error_code' => 'history_cleanup_lock_prepare_failed', 'message' => 'History cleanup lock could not be prepared'];
    }
    $lockStmt->bind_param('s', $lockName);
    if (!$lockStmt->execute()) {
        $error = (string) $lockStmt->error;
        $lockStmt->close();
        return ['success' => false, 'deleted' => 0, 'more' => false, 'skipped' => false, 'error_code' => 'history_cleanup_lock_execute_failed', 'error_detail' => $error, 'message' => 'History cleanup lock could not be acquired'];
    }
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();
    if ((int) ($lockRow['locked'] ?? 0) !== 1) {
        $summary['skipped'] = true;
        $summary['message'] = 'Already running';
        return $summary;
    }

    $started = microtime(true);
    $limit = KEY_HISTORY_BATCH_SIZE;
    try {
        $archived = keyHistoryArchiveAndDeleteTransactionBatch($limit);
        if (empty($archived['success'])) throw new RuntimeException('Unable to archive old key-sale transactions');
        $summary['archived_transactions'] += (int) $archived['deleted'];
        $summary['deleted'] += (int) $archived['deleted'];
        $summary['more'] = !empty($archived['more']);

        if (microtime(true) - $started < KEY_HISTORY_TIME_BUDGET) {
            $deleted = keyHistoryDeleteSimpleBatch(
                "DELETE FROM transactions
                 WHERE type IN ('purchase','cgo_purchase','supplier_purchase')
                   AND status NOT IN ('completed','pending')
                   AND created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
                 ORDER BY created_at ASC, id ASC LIMIT {$limit}"
            );
            $summary['deleted'] += $deleted;
            if ($deleted >= $limit) $summary['more'] = true;
        }

        // Local sold keys. Deposit and balance records are not touched.
        if (keyHistoryTableExists('keys') && microtime(true) - $started < KEY_HISTORY_TIME_BUDGET) {
            $deleted = keyHistoryDeleteSimpleBatch(
                "DELETE FROM `keys`
                 WHERE status = 'sold' AND sold_at IS NOT NULL
                   AND sold_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
                 ORDER BY sold_at ASC, id ASC LIMIT {$limit}"
            );
            $summary['deleted'] += $deleted;
            if ($deleted >= $limit) $summary['more'] = true;
        }

        $orderSets = [
            ['orders' => 'cgo_orders', 'keys' => 'cgo_order_keys'],
            ['orders' => 'supplier_orders', 'keys' => 'supplier_order_keys'],
            ['orders' => 'store_api_orders', 'keys' => 'store_api_order_keys'],
        ];
        foreach ($orderSets as $set) {
            if (microtime(true) - $started >= KEY_HISTORY_TIME_BUDGET) {
                $summary['more'] = true;
                break;
            }
            if (!keyHistoryTableExists($set['orders'])) continue;
            $orders = $set['orders'];
            $keys = $set['keys'];
            $eligible = "status NOT IN ('processing','pending','unknown','manual_review') AND created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)";

            if (keyHistoryTableExists($keys)) {
                $deleted = keyHistoryDeleteSimpleBatch(
                    "DELETE FROM `{$keys}` WHERE order_id IN (
                        SELECT id FROM (
                            SELECT id FROM `{$orders}` WHERE {$eligible} ORDER BY id ASC LIMIT {$limit}
                        ) AS old_orders
                    )"
                );
                $summary['deleted'] += $deleted;
            }
            $deletedOrders = keyHistoryDeleteSimpleBatch(
                "DELETE FROM `{$orders}` WHERE {$eligible} ORDER BY id ASC LIMIT {$limit}"
            );
            $summary['deleted'] += $deletedOrders;
            if ($deletedOrders >= $limit) $summary['more'] = true;
        }

        if (keyHistoryTableExists('store_api_request_logs') && microtime(true) - $started < KEY_HISTORY_TIME_BUDGET) {
            $deleted = keyHistoryDeleteSimpleBatch(
                "DELETE FROM store_api_request_logs
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
                 LIMIT {$limit}"
            );
            $summary['deleted'] += $deleted;
            if ($deleted >= $limit) $summary['more'] = true;
        }

        // Keep API credit additions/top-ups, but discard old order-debit detail.
        if (keyHistoryTableExists('store_api_balance_ledger') && microtime(true) - $started < KEY_HISTORY_TIME_BUDGET) {
            $deleted = keyHistoryDeleteSimpleBatch(
                "DELETE FROM store_api_balance_ledger
                 WHERE entry_type = 'order_debit'
                   AND created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
                 LIMIT {$limit}"
            );
            $summary['deleted'] += $deleted;
            if ($deleted >= $limit) $summary['more'] = true;
        }

        // Local purchase audit messages duplicate the transaction/key detail.
        // Administrative, login, reset, and deposit history remains untouched.
        if (keyHistoryTableExists('history') && keyHistoryColumnExists('history', 'created_at')
            && microtime(true) - $started < KEY_HISTORY_TIME_BUDGET) {
            $deleted = keyHistoryDeleteSimpleBatch(
                "DELETE FROM history
                 WHERE action = 'key_purchase'
                   AND created_at < DATE_SUB(NOW(), INTERVAL " . KEY_HISTORY_RETENTION_DAYS . " DAY)
                 LIMIT {$limit}"
            );
            $summary['deleted'] += $deleted;
            if ($deleted >= $limit) $summary['more'] = true;
        }

        $next = $summary['more'] ? ($now + 300) : ($now + 86400);
        upsertSetting('key_history_cleanup_next_run', (string) $next);
        upsertSetting('key_history_cleanup_last_run', date('Y-m-d H:i:s', $now));
        upsertSetting('key_history_cleanup_last_deleted', (string) $summary['deleted']);
    } catch (Throwable $e) {
        $summary['success'] = false;
        $summary['error_code'] = 'history_cleanup_failed';
        $summary['error_detail'] = $e->getMessage();
        $summary['message'] = 'Automatic key history cleanup failed';
        error_log('Automatic key history cleanup failed: ' . $e->getMessage());
        upsertSetting('key_history_cleanup_next_run', (string) ($now + 1800));
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) {
            $release->bind_param('s', $lockName);
            $release->execute();
            $release->close();
        }
    }
    if (!isset($summary['message'])) {
        $summary['message'] = $summary['more'] ? 'Cleanup batch completed; more work remains' : 'Cleanup completed';
    }
    return $summary;
}

function keyHistoryScheduleAutoCleanup(): void
{
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli' || (defined('SKIP_KEY_HISTORY_AUTO_CLEANUP') && SKIP_KEY_HISTORY_AUTO_CLEANUP)) return;
    $scheduled = true;
    register_shutdown_function(static function (): void {
        try {
            // Let PHP-FPM finish the response first so maintenance does not make
            // a customer wait after opening a page or completing a purchase.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            keyHistoryCleanupRun(false);
        } catch (Throwable $e) {
            error_log('Scheduled key history cleanup failed: ' . $e->getMessage());
        }
    });
}
