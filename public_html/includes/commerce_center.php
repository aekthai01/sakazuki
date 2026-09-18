<?php
/**
 * Commerce Center
 *
 * Additive, idempotent read model for orders, item snapshots and delivered-key
 * ownership. Authoritative business tables remain unchanged and continue to own
 * checkout, wallet and stock state. A failed center sync must never fail a sale.
 */

require_once __DIR__ . '/db.php';

if (!defined('COMMERCE_CENTER_VERSION')) define('COMMERCE_CENTER_VERSION', '1.4');

if (!function_exists('commerceCenterText')) {
    function commerceCenterText($value, int $max = 1000): string
    {
        if (!is_scalar($value) && $value !== null) return '';
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? (string) $value);
        if ($max < 1) return '';
        if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
        return substr($value, 0, $max);
    }
}

if (!function_exists('commerceCenterSiteId')) {
    function commerceCenterSiteId(): string
    {
        $raw = defined('APP_SITE_ID') ? (string) APP_SITE_ID : (defined('DB_NAME') ? (string) DB_NAME : 'site');
        $raw = strtolower(commerceCenterText($raw, 100));
        $raw = preg_replace('/[^a-z0-9._:-]+/', '-', $raw) ?? '';
        $raw = trim($raw, '-.');
        return $raw !== '' ? $raw : 'site-' . substr(hash('sha256', defined('DB_NAME') ? (string) DB_NAME : 'default'), 0, 12);
    }
}

if (!function_exists('commerceCenterSiteLabel')) {
    function commerceCenterSiteLabel(): string
    {
        $label = defined('APP_SITE_LABEL') ? commerceCenterText((string) APP_SITE_LABEL, 190) : '';
        if ($label !== '') return $label;
        if (function_exists('getSetting')) {
            $label = commerceCenterText(getSetting('site_name', ''), 190);
            if ($label !== '') return $label;
        }
        return commerceCenterSiteId();
    }
}

if (!function_exists('commerceCenterCustomerRef')) {
    function commerceCenterCustomerRef(string $siteId, string $userId): string
    {
        $siteId = commerceCenterText($siteId, 100);
        $userId = commerceCenterText($userId, 190);
        return ($siteId !== '' && $userId !== '') ? commerceCenterText($siteId . ':user:' . $userId, 255) : '';
    }
}

if (!function_exists('commerceCenterUuid')) {
    function commerceCenterUuid(): string
    {
        try { $bytes = random_bytes(16); }
        catch (Throwable $e) { $bytes = hash('sha256', uniqid('', true) . mt_rand(), true); }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}

if (!function_exists('commerceCenterTableExists')) {
    function commerceCenterTableExists(string $table): bool
    {
        global $conn;
        static $cache = [];
        $table = strtolower(trim($table));
        if (!preg_match('/^[a-z0-9_]+$/D', $table) || !isset($conn) || !($conn instanceof mysqli)) return false;
        if (array_key_exists($table, $cache)) return $cache[$table];
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            if (!$stmt) return $cache[$table] = false;
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            return $cache[$table] = ((int) $count > 0);
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    }
}

if (!function_exists('commerceCenterColumns')) {
    /** @return array<string,bool> */
    function commerceCenterColumns(string $table): array
    {
        global $conn;
        static $cache = [];
        $table = strtolower(trim($table));
        if (!preg_match('/^[a-z0-9_]+$/D', $table) || !isset($conn) || !($conn instanceof mysqli)) return [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        $columns = [];
        try {
            $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $name = strtolower((string) ($row['Field'] ?? ''));
                if ($name !== '') $columns[$name] = true;
            }
            if ($result) $result->free();
        } catch (Throwable $e) {}
        return $cache[$table] = $columns;
    }
}

if (!function_exists('commerceCenterEnsureColumn')) {
    function commerceCenterEnsureColumn(string $table, string $column, string $definition): bool
    {
        global $conn;
        if (!preg_match('/^[a-z0-9_]+$/D', $table) || !preg_match('/^[a-z0-9_]+$/D', $column)) return false;
        $columns = commerceCenterColumns($table);
        if (!empty($columns[$column])) return true;
        try {
            return (bool) $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) {
            error_log('Commerce Center optional column migration failed: ' . $table . '.' . $column . '; ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('commerceCenterEnsureIndex')) {
    function commerceCenterEnsureIndex(string $table, string $index, string $columns): bool
    {
        global $conn;
        if (!preg_match('/^[a-z0-9_]+$/D', $table) || !preg_match('/^[a-z0-9_]+$/D', $index)
            || !preg_match('/^[a-z0-9_`,() ]+$/Di', $columns)) return false;
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            if (!$stmt) return false;
            $stmt->bind_param('ss', $table, $index);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            if ((int) $count > 0) return true;
            return (bool) $conn->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        } catch (Throwable $e) {
            error_log('Commerce Center optional index migration failed: ' . $table . '.' . $index . '; ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('commerceCenterBind')) {
    function commerceCenterBind(mysqli_stmt $stmt, array &$params): bool
    {
        if ($params === []) return true;
        $types = '';
        foreach ($params as $value) {
            if (is_int($value)) $types .= 'i';
            elseif (is_float($value)) $types .= 'd';
            else $types .= 's';
        }
        $args = [$types];
        foreach ($params as $i => $_value) $args[] = &$params[$i];
        return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('commerceCenterSetLastSqlError')) {
    function commerceCenterSetLastSqlError(string $message): void
    {
        $GLOBALS['__commerce_center_last_sql_error'] = commerceCenterText($message, 1000);
    }
}

if (!function_exists('commerceCenterLastSqlError')) {
    function commerceCenterLastSqlError(): string
    {
        return trim((string) ($GLOBALS['__commerce_center_last_sql_error'] ?? ''));
    }
}

if (!function_exists('commerceCenterSqlFailure')) {
    function commerceCenterSqlFailure(string $fallback): string
    {
        $detail = commerceCenterLastSqlError();
        return $detail !== '' ? commerceCenterText($fallback . ': ' . $detail, 1000) : $fallback;
    }
}

if (!function_exists('commerceCenterExecute')) {
    function commerceCenterExecute(string $sql, array $params = []): ?mysqli_stmt
    {
        global $conn;
        commerceCenterSetLastSqlError('');
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                commerceCenterSetLastSqlError((string) ($conn->error ?? 'SQL prepare failed'));
                return null;
            }
            if (!commerceCenterBind($stmt, $params)) {
                commerceCenterSetLastSqlError((string) ($stmt->error ?: 'SQL parameter binding failed'));
                $stmt->close();
                return null;
            }
            if (!$stmt->execute()) {
                commerceCenterSetLastSqlError((string) ($stmt->error ?: 'SQL execution failed'));
                $stmt->close();
                return null;
            }
            return $stmt;
        } catch (Throwable $e) {
            commerceCenterSetLastSqlError($e->getMessage());
            error_log('Commerce Center SQL failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('commerceCenterQueryFirst')) {
    function commerceCenterQueryFirst(string $sql, array $params = []): ?array
    {
        $stmt = commerceCenterExecute($sql, $params);
        if (!$stmt) return null;
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('commerceCenterEnsureSchema')) {
    function commerceCenterEnsureSchema(): bool
    {
        global $conn;
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

        $requiredTables = [
            'commerce_orders',
            'commerce_order_items',
            'commerce_deliveries',
            'commerce_sync_failures',
            'commerce_order_financials',
            'commerce_ledger_entries',
        ];
        $runtimeReady = function_exists('sakazukiTableReady');
        if ($runtimeReady) {
            foreach ($requiredTables as $table) {
                if (!sakazukiTableReady($table)) {
                    $runtimeReady = false;
                    break;
                }
            }
        }
        if ($runtimeReady) {
            try {
                $columnProbe = @$conn->query('SELECT source_inventory_key_id FROM commerce_deliveries LIMIT 0');
                if ($columnProbe instanceof mysqli_result) $columnProbe->free();
                $runtimeReady = $columnProbe !== false;
            } catch (Throwable $e) {
                $runtimeReady = false;
            }
        }

        $migrationsAllowed = function_exists('sakazukiSchemaMigrationsAllowed')
            ? sakazukiSchemaMigrationsAllowed()
            : PHP_SAPI === 'cli';
        if ($runtimeReady && !$migrationsAllowed) return $ready = true;
        if (!$migrationsAllowed) {
            error_log('Commerce Center schema is unavailable; deployment migration is required.');
            return $ready = false;
        }

        $queries = [
            "CREATE TABLE IF NOT EXISTS commerce_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_uuid CHAR(36) NOT NULL,
                source_site_id VARCHAR(100) NOT NULL,
                source_type VARCHAR(40) NOT NULL,
                source_record_id VARCHAR(190) NOT NULL,
                status VARCHAR(40) NOT NULL,
                source_updated_at DATETIME NULL,
                origin_site_id VARCHAR(100) NULL,
                origin_user_id VARCHAR(190) NULL,
                customer_ref VARCHAR(255) NULL,
                local_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                commercial_buyer_type VARCHAR(40) NOT NULL DEFAULT '',
                commercial_buyer_id VARCHAR(190) NOT NULL DEFAULT '',
                buyer_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                buyer_email_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                currency CHAR(3) NOT NULL DEFAULT 'THB',
                subtotal DECIMAL(16,2) NOT NULL DEFAULT 0,
                total DECIMAL(16,2) NOT NULL DEFAULT 0,
                cost_total DECIMAL(16,2) NOT NULL DEFAULT 0,
                transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                external_ref VARCHAR(190) NOT NULL DEFAULT '',
                item_count INT UNSIGNED NOT NULL DEFAULT 0,
                delivery_count INT UNSIGNED NOT NULL DEFAULT 0,
                source_created_at DATETIME NULL,
                source_completed_at DATETIME NULL,
                snapshot_json LONGTEXT NULL,
                last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_order_uuid (order_uuid),
                UNIQUE KEY uq_commerce_order_source (source_site_id, source_type, source_record_id),
                KEY idx_commerce_order_status (status, source_completed_at),
                KEY idx_commerce_order_customer (origin_site_id, origin_user_id),
                KEY idx_commerce_order_local_user (local_user_id, source_created_at),
                KEY idx_commerce_order_external_ref (external_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS commerce_order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                item_no INT UNSIGNED NOT NULL DEFAULT 1,
                local_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                local_variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                remote_product_id VARCHAR(190) NOT NULL DEFAULT '',
                product_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
                duration_snapshot VARCHAR(120) NOT NULL DEFAULT '',
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price DECIMAL(16,2) NOT NULL DEFAULT 0,
                unit_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
                total_price DECIMAL(16,2) NOT NULL DEFAULT 0,
                total_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
                snapshot_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_order_item (order_id, item_no),
                KEY idx_commerce_item_product (local_product_id, local_variant_id),
                KEY idx_commerce_item_remote (remote_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS commerce_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_site_id VARCHAR(100) NOT NULL,
                source_type VARCHAR(40) NOT NULL,
                source_key_record_id VARCHAR(190) NOT NULL,
                source_inventory_key_id VARCHAR(190) NOT NULL DEFAULT '',
                key_hash CHAR(64) NOT NULL,
                key_mask VARCHAR(190) NOT NULL DEFAULT '',
                delivered_to_site_id VARCHAR(100) NULL,
                delivered_to_user_id VARCHAR(190) NULL,
                customer_ref VARCHAR(255) NULL,
                delivered_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                delivered_email_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                ownership_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
                delivered_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_delivery_source (source_site_id, source_type, source_key_record_id),
                KEY idx_commerce_delivery_order (order_id, id),
                KEY idx_commerce_delivery_hash (key_hash),
                KEY idx_commerce_delivery_owner (delivered_to_site_id, delivered_to_user_id),
                KEY idx_commerce_delivery_customer_ref (customer_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS commerce_sync_failures (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_site_id VARCHAR(100) NOT NULL,
                source_type VARCHAR(40) NOT NULL,
                source_record_id VARCHAR(190) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 1,
                error_code VARCHAR(80) NOT NULL DEFAULT 'sync_failed',
                error_message VARCHAR(1000) NOT NULL DEFAULT '',
                first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_sync_failure (source_site_id, source_type, source_record_id),
                KEY idx_commerce_sync_unresolved (resolved_at, last_failed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS commerce_order_financials (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'THB',
                sale_total DECIMAL(16,2) NOT NULL DEFAULT 0,
                cost_total DECIMAL(16,2) NOT NULL DEFAULT 0,
                fee_total DECIMAL(16,2) NOT NULL DEFAULT 0,
                refund_total DECIMAL(16,2) NOT NULL DEFAULT 0,
                net_revenue DECIMAL(16,2) NOT NULL DEFAULT 0,
                profit_total DECIMAL(16,2) NULL,
                margin_percent DECIMAL(10,4) NULL,
                financial_status VARCHAR(40) NOT NULL DEFAULT 'pending',
                cost_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
                calculation_version VARCHAR(20) NOT NULL DEFAULT '1.0',
                snapshot_json LONGTEXT NULL,
                last_calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_financial_order (order_id),
                KEY idx_commerce_financial_status (financial_status, last_calculated_at),
                KEY idx_commerce_financial_currency (currency, financial_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS commerce_ledger_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entry_uuid CHAR(36) NOT NULL,
                entry_key CHAR(64) NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_site_id VARCHAR(100) NOT NULL,
                source_type VARCHAR(60) NOT NULL,
                source_record_id VARCHAR(190) NOT NULL,
                source_event_id VARCHAR(190) NOT NULL DEFAULT '',
                entry_type VARCHAR(60) NOT NULL,
                account_type VARCHAR(60) NOT NULL DEFAULT '',
                account_id VARCHAR(190) NOT NULL DEFAULT '',
                account_label_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                counterparty_type VARCHAR(60) NOT NULL DEFAULT '',
                counterparty_id VARCHAR(190) NOT NULL DEFAULT '',
                counterparty_label_snapshot VARCHAR(190) NOT NULL DEFAULT '',
                direction VARCHAR(10) NOT NULL,
                amount DECIMAL(16,2) NOT NULL DEFAULT 0,
                reporting_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'THB',
                balance_before DECIMAL(16,2) NULL,
                balance_after DECIMAL(16,2) NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'completed',
                description VARCHAR(1000) NOT NULL DEFAULT '',
                occurred_at DATETIME NULL,
                snapshot_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_commerce_ledger_uuid (entry_uuid),
                UNIQUE KEY uq_commerce_ledger_key (entry_key),
                KEY idx_commerce_ledger_order (order_id, id),
                KEY idx_commerce_ledger_source (source_site_id, source_type, source_record_id),
                KEY idx_commerce_ledger_account (account_type, account_id, occurred_at),
                KEY idx_commerce_ledger_type (entry_type, status, occurred_at),
                KEY idx_commerce_ledger_occurred (occurred_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        foreach ($queries as $sql) {
            try {
                if (!$conn->query($sql)) {
                    error_log('Commerce Center schema failed: ' . $conn->error);
                    return $ready = false;
                }
            } catch (Throwable $e) {
                error_log('Commerce Center schema exception: ' . $e->getMessage());
                return $ready = false;
            }
        }
        // Additive migration for installations that briefly ran an earlier build.
        // Failure leaves checkout untouched; only the read model remains unavailable.
        if (!commerceCenterEnsureColumn('commerce_deliveries', 'source_inventory_key_id', "VARCHAR(190) NOT NULL DEFAULT '' AFTER source_key_record_id")) {
            error_log('Commerce Center schema is missing commerce_deliveries.source_inventory_key_id');
            return $ready = false;
        }
        if (!commerceCenterEnsureIndex('commerce_orders', 'idx_commerce_order_updated', '`updated_at`,`id`')) {
            error_log('Commerce Center schema is missing commerce_orders.idx_commerce_order_updated');
            return $ready = false;
        }
        return $ready = true;
    }
}

if (!function_exists('commerceCenterMaintenanceColumnStatus')) {
    /** @return array<string,mixed> */
    function commerceCenterMaintenanceColumnStatus(string $table, string $column): array
    {
        global $conn;
        $status = [
            'exists' => false,
            'data_type' => '',
            'column_type' => '',
            'max_length' => null,
            'nullable' => null,
            'default' => null,
            'charset' => '',
            'collation' => '',
        ];
        if (!isset($conn) || !($conn instanceof mysqli)
            || preg_match('/^[a-z0-9_]+$/D', $table) !== 1
            || preg_match('/^[a-z0-9_]+$/D', $column) !== 1) {
            return $status;
        }
        try {
            $stmt = $conn->prepare(
                'SELECT DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME '
                . 'FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
            );
            if (!$stmt) return $status;
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!is_array($row)) return $status;
            return [
                'exists' => true,
                'data_type' => strtolower((string) ($row['DATA_TYPE'] ?? '')),
                'column_type' => strtolower((string) ($row['COLUMN_TYPE'] ?? '')),
                'max_length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
                'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'YES',
                'default' => $row['COLUMN_DEFAULT'],
                'charset' => strtolower((string) ($row['CHARACTER_SET_NAME'] ?? '')),
                'collation' => strtolower((string) ($row['COLLATION_NAME'] ?? '')),
            ];
        } catch (Throwable $e) {
            commerceCenterSetLastSqlError($e->getMessage());
            return $status;
        }
    }
}

if (!function_exists('commerceCenterMaintenanceSchemaRepair')) {
    /**
     * Maintenance-only repair for the Commerce Center read model.
     *
     * Authoritative checkout/transaction tables are intentionally not altered.
     * We only normalize the Commerce Center key_mask column and run a rolled-back
     * Unicode write probe. Ledger collation compatibility is handled in the read
     * query itself so a reporting repair can never rewrite the transactions table.
     *
     * @return array<string,mixed>
     */
    function commerceCenterMaintenanceSchemaRepair(): array
    {
        global $conn;
        $started = microtime(true);
        $result = [
            'success' => false,
            'changed' => false,
            'skipped' => false,
            'busy' => false,
            'recovery_due' => false,
            'steps' => [],
            'duration_ms' => 0,
        ];
        $addStep = static function (string $step, string $status, string $message = '', array $meta = []) use (&$result): void {
            $entry = ['step' => $step, 'status' => $status];
            if ($message !== '') $entry['message'] = substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);
            foreach ($meta as $key => $value) {
                if (is_scalar($value) || $value === null || is_array($value)) $entry[$key] = $value;
            }
            $result['steps'][] = $entry;
            $encoded = $meta === [] ? '' : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            error_log('[maintenance][commerce_schema][' . preg_replace('/[^a-z0-9_\-]/i', '_', $step) . ']'
                . ' status=' . preg_replace('/[^a-z0-9_\-]/i', '_', $status)
                . ($message !== '' ? '; message=' . substr(str_replace(["\r", "\n"], ' ', $message), 0, 500) : '')
                . (is_string($encoded) && $encoded !== '' ? '; meta=' . substr($encoded, 0, 1200) : ''));
        };
        $finish = static function () use (&$result, $started): array {
            $result['duration_ms'] = max(0, (int) round((microtime(true) - $started) * 1000));
            return $result;
        };

        if (!function_exists('sakazukiSchemaMigrationsAllowed') || !sakazukiSchemaMigrationsAllowed()) {
            $result['skipped'] = true;
            $result['message'] = 'Schema migrations are disabled';
            $addStep('permission', 'skipped', 'Schema migrations are disabled');
            return $finish();
        }
        if (!isset($conn) || !($conn instanceof mysqli)) {
            $result['message'] = 'Database connection unavailable';
            $addStep('database', 'failed', 'Database connection unavailable');
            return $finish();
        }

        $lockName = 'schema:commerce:' . substr(hash('sha256', defined('DB_NAME') ? (string) DB_NAME : 'db'), 0, 20);
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        if (!$lockStmt) {
            $result['message'] = 'Commerce schema lock unavailable';
            $addStep('lock', 'failed', 'Unable to prepare schema lock');
            return $finish();
        }
        $lockStmt->bind_param('s', $lockName);
        $lockStmt->execute();
        $lockResult = $lockStmt->get_result();
        $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
        if ($lockResult instanceof mysqli_result) $lockResult->free();
        $lockStmt->close();
        if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
            $result['success'] = true;
            $result['skipped'] = true;
            $result['busy'] = true;
            $result['message'] = 'Commerce schema maintenance is already running';
            $addStep('lock', 'busy', 'Another maintenance process owns the schema lock');
            return $finish();
        }
        $addStep('lock', 'ok', 'Exclusive Commerce Center schema lock acquired');

        try {
            if (!commerceCenterEnsureSchema()) {
                $result['message'] = 'Commerce Center base schema is unavailable';
                $addStep('base_schema', 'failed', commerceCenterSqlFailure('Commerce Center base schema is unavailable'));
                return $finish();
            }

            $beforeMask = commerceCenterMaintenanceColumnStatus('commerce_deliveries', 'key_mask');
            $beforeLedgerStatus = commerceCenterMaintenanceColumnStatus('commerce_ledger_entries', 'status');
            $result['before'] = [
                'key_mask' => $beforeMask,
                'ledger_status' => $beforeLedgerStatus,
            ];
            $addStep('inspect_before', 'ok', 'Commerce Center text schema inspected', [
                'key_mask_charset' => (string) ($beforeMask['charset'] ?? ''),
                'key_mask_collation' => (string) ($beforeMask['collation'] ?? ''),
                'ledger_status_collation' => (string) ($beforeLedgerStatus['collation'] ?? ''),
            ]);

            if (empty($beforeMask['exists'])) {
                $result['message'] = 'commerce_deliveries.key_mask is missing';
                $addStep('key_mask_schema', 'failed', 'commerce_deliveries.key_mask is missing');
                return $finish();
            }

            $maskNeedsRepair = ($beforeMask['charset'] ?? '') !== 'utf8mb4'
                || ($beforeMask['collation'] ?? '') !== 'utf8mb4_unicode_ci';
            if ($maskNeedsRepair) {
                $dataType = (string) ($beforeMask['data_type'] ?? '');
                $maxLength = (int) ($beforeMask['max_length'] ?? 0);
                $nullable = !empty($beforeMask['nullable']);
                $default = $beforeMask['default'] ?? null;
                if ($dataType !== 'varchar' || $maxLength < 1 || $maxLength > 1000 || $nullable || ($default !== '' && $default !== null)) {
                    $result['blocked'] = true;
                    $result['message'] = 'Unexpected key_mask definition requires manual review';
                    $addStep('key_mask_schema', 'blocked', 'Unexpected key_mask definition requires manual review', ['column' => $beforeMask]);
                    return $finish();
                }
                $safeLength = max(190, $maxLength);
                if (!$conn->query(
                    'ALTER TABLE `commerce_deliveries` MODIFY COLUMN `key_mask` VARCHAR(' . $safeLength . ') '
                    . "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''"
                )) {
                    throw new RuntimeException('commerce_key_mask_charset_failed: ' . $conn->error);
                }
                $result['changed'] = true;
                $addStep('key_mask_schema', 'ok', 'Normalized commerce_deliveries.key_mask to utf8mb4_unicode_ci', [
                    'length' => $safeLength,
                    'previous_charset' => (string) ($beforeMask['charset'] ?? ''),
                    'previous_collation' => (string) ($beforeMask['collation'] ?? ''),
                ]);
            } else {
                $addStep('key_mask_schema', 'ok', 'key_mask charset already ready');
            }

            $afterMask = commerceCenterMaintenanceColumnStatus('commerce_deliveries', 'key_mask');
            $result['after'] = [
                'key_mask' => $afterMask,
                'ledger_status' => commerceCenterMaintenanceColumnStatus('commerce_ledger_entries', 'status'),
            ];
            if (($afterMask['charset'] ?? '') !== 'utf8mb4' || ($afterMask['collation'] ?? '') !== 'utf8mb4_unicode_ci') {
                $result['message'] = 'key_mask charset remains unhealthy after maintenance';
                $addStep('inspect_after', 'failed', 'key_mask charset remains unhealthy after maintenance', ['column' => $afterMask]);
                return $finish();
            }
            $addStep('inspect_after', 'ok', 'Commerce Center text schema validation passed');

            // Roll a real multibyte mask through the actual column and then
            // rollback. This catches both column charset drift and accidental
            // byte-splitting in commerceCenterMaskKey() without changing data.
            $probeRow = commerceCenterQueryFirst('SELECT id FROM commerce_deliveries ORDER BY id ASC LIMIT 1');
            if (is_array($probeRow) && (int) ($probeRow['id'] ?? 0) > 0) {
                $probeId = (int) $probeRow['id'];
                $probeValue = commerceCenterMaskKey('測試-ทดสอบ-🔐-ABCDE12345');
                $conn->begin_transaction();
                try {
                    $probe = $conn->prepare('UPDATE commerce_deliveries SET key_mask=? WHERE id=?');
                    if (!$probe) throw new RuntimeException('commerce_unicode_probe_prepare_failed: ' . $conn->error);
                    $probe->bind_param('si', $probeValue, $probeId);
                    if (!$probe->execute()) {
                        $message = (string) ($probe->error ?: 'Unicode key mask probe failed');
                        $probe->close();
                        throw new RuntimeException('commerce_unicode_probe_execute_failed: ' . $message);
                    }
                    $probe->close();
                    $conn->rollback();
                    $addStep('runtime_unicode_probe', 'ok', 'Rolled-back Unicode key_mask write succeeded');
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    throw $e;
                }
            } else {
                $addStep('runtime_unicode_probe', 'skipped', 'No delivery row exists for a rolled-back write probe');
            }

            $unresolved = 0;
            if (commerceCenterTableExists('commerce_sync_failures')) {
                $row = commerceCenterQueryFirst('SELECT COUNT(*) AS unresolved FROM commerce_sync_failures WHERE resolved_at IS NULL');
                $unresolved = max(0, (int) ($row['unresolved'] ?? 0));
            }
            $result['unresolved_before_recovery'] = $unresolved;
            $result['recovery_due'] = $result['changed'] || $unresolved > 0;
            $result['success'] = true;
            $result['message'] = $result['changed']
                ? 'Commerce Center schema repaired and recovery queued'
                : ($result['recovery_due'] ? 'Commerce Center schema ready; unresolved recovery queued' : 'Commerce Center schema already ready');
            return $finish();
        } catch (Throwable $e) {
            $result['message'] = 'Commerce Center schema maintenance failed';
            $result['error'] = commerceCenterText($e->getMessage(), 700);
            $addStep('exception', 'failed', $e->getMessage());
            return $finish();
        } finally {
            try {
                $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
                if ($release) {
                    $release->bind_param('s', $lockName);
                    $release->execute();
                    $release->close();
                }
            } catch (Throwable $ignored) {}
        }
    }
}

if (!function_exists('commerceCenterMaskKey')) {
    function commerceCenterMaskKey(string $key): string
    {
        $key = commerceCenterText($key, 500);
        if ($key === '') return '****';

        // Never split a UTF-8 code point while masking. The old byte-based
        // substr() could cut a multibyte character in half and then MySQL quite
        // correctly rejected the resulting invalid UTF-8 for key_mask.
        if (preg_match('//u', $key) !== 1) {
            return '****-' . substr(hash('sha256', $key), 0, 8);
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $length = mb_strlen($key, 'UTF-8');
            if ($length <= 8) return str_repeat('*', max(4, $length));
            return mb_substr($key, 0, 4, 'UTF-8')
                . str_repeat('*', min(16, max(4, $length - 8)))
                . mb_substr($key, -4, 4, 'UTF-8');
        }

        $chars = [];
        if (preg_match_all('/./us', $key, $matches) === false) {
            return '****-' . substr(hash('sha256', $key), 0, 8);
        }
        $chars = (array) ($matches[0] ?? []);
        $length = count($chars);
        if ($length <= 8) return str_repeat('*', max(4, $length));
        return implode('', array_slice($chars, 0, 4))
            . str_repeat('*', min(16, max(4, $length - 8)))
            . implode('', array_slice($chars, -4));
    }
}

if (!function_exists('commerceCenterJson')) {
    function commerceCenterJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('commerceCenterAcquireLock')) {
    function commerceCenterAcquireLock(string $sourceType, string $recordId): ?string
    {
        global $conn;
        $fingerprint = substr(hash('sha256', defined('DB_NAME') ? (string) DB_NAME : 'db'), 0, 16);
        $name = substr('commerce:' . $fingerprint . ':' . preg_replace('/[^a-z0-9_:-]/i', '', $sourceType) . ':' . hash('sha256', $recordId), 0, 64);
        $row = commerceCenterQueryFirst('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
        return (int) ($row['acquired'] ?? 0) === 1 ? $name : null;
    }
}

if (!function_exists('commerceCenterReleaseLock')) {
    function commerceCenterReleaseLock(?string $name): void
    {
        if ($name === null || $name === '') return;
        $stmt = commerceCenterExecute('SELECT RELEASE_LOCK(?)', [$name]);
        if ($stmt) $stmt->close();
    }
}

if (!function_exists('commerceCenterRecordFailure')) {
    function commerceCenterRecordFailure(string $sourceType, string $recordId, string $message, string $code = 'sync_failed'): void
    {
        if (!commerceCenterEnsureSchema()) return;
        $params = [commerceCenterSiteId(), commerceCenterText($sourceType, 40), commerceCenterText($recordId, 190), commerceCenterText($code, 80), commerceCenterText($message, 1000)];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_sync_failures
             (source_site_id,source_type,source_record_id,error_code,error_message)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE attempts=attempts+1,error_code=VALUES(error_code),error_message=VALUES(error_message),last_failed_at=NOW(),resolved_at=NULL",
            $params
        );
        if ($stmt) $stmt->close();
    }
}

if (!function_exists('commerceCenterResolveFailure')) {
    function commerceCenterResolveFailure(string $sourceType, string $recordId): void
    {
        if (!commerceCenterEnsureSchema()) return;
        $stmt = commerceCenterExecute(
            'UPDATE commerce_sync_failures SET resolved_at=NOW() WHERE source_site_id=? AND source_type=? AND source_record_id=? AND resolved_at IS NULL',
            [commerceCenterSiteId(), commerceCenterText($sourceType, 40), commerceCenterText($recordId, 190)]
        );
        if ($stmt) $stmt->close();
    }
}

if (!function_exists('commerceCenterUpsertOrder')) {
    function commerceCenterUpsertOrder(array $data): int
    {
        global $conn;
        $uuid = commerceCenterText((string) ($data['order_uuid'] ?? ''), 36);
        if ($uuid === '') $uuid = commerceCenterUuid();
        $params = [
            commerceCenterSiteId(), commerceCenterText($data['source_type'] ?? '', 40), commerceCenterText($data['source_record_id'] ?? '', 190),
            $uuid, commerceCenterText($data['status'] ?? '', 40), $data['source_updated_at'] ?? null,
            commerceCenterText($data['origin_site_id'] ?? '', 100), commerceCenterText($data['origin_user_id'] ?? '', 190), commerceCenterText($data['customer_ref'] ?? '', 255),
            max(0, (int) ($data['local_user_id'] ?? 0)), commerceCenterText($data['commercial_buyer_type'] ?? '', 40), commerceCenterText($data['commercial_buyer_id'] ?? '', 190),
            commerceCenterText($data['buyer_name_snapshot'] ?? '', 190), commerceCenterText($data['buyer_email_snapshot'] ?? '', 190), strtoupper(commerceCenterText($data['currency'] ?? 'THB', 3)),
            round((float) ($data['subtotal'] ?? 0), 2), round((float) ($data['total'] ?? 0), 2), round((float) ($data['cost_total'] ?? 0), 2),
            max(0, (int) ($data['transaction_id'] ?? 0)), commerceCenterText($data['external_ref'] ?? '', 190),
            $data['source_created_at'] ?? null, $data['source_completed_at'] ?? null, commerceCenterJson((array) ($data['snapshot'] ?? [])),
        ];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_orders
             (source_site_id,source_type,source_record_id,order_uuid,status,source_updated_at,origin_site_id,origin_user_id,customer_ref,
              local_user_id,commercial_buyer_type,commercial_buyer_id,buyer_name_snapshot,buyer_email_snapshot,currency,
              subtotal,total,cost_total,transaction_id,external_ref,source_created_at,source_completed_at,snapshot_json,last_synced_at,last_error)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NULL)
             ON DUPLICATE KEY UPDATE
              id=LAST_INSERT_ID(id),status=VALUES(status),source_updated_at=VALUES(source_updated_at),origin_site_id=VALUES(origin_site_id),
              origin_user_id=VALUES(origin_user_id),customer_ref=VALUES(customer_ref),local_user_id=VALUES(local_user_id),
              commercial_buyer_type=VALUES(commercial_buyer_type),commercial_buyer_id=VALUES(commercial_buyer_id),
              buyer_name_snapshot=VALUES(buyer_name_snapshot),buyer_email_snapshot=VALUES(buyer_email_snapshot),currency=VALUES(currency),
              subtotal=VALUES(subtotal),total=VALUES(total),cost_total=VALUES(cost_total),transaction_id=VALUES(transaction_id),
              external_ref=VALUES(external_ref),source_created_at=VALUES(source_created_at),source_completed_at=VALUES(source_completed_at),
              snapshot_json=VALUES(snapshot_json),last_synced_at=NOW(),last_error=NULL",
            $params
        );
        if (!$stmt) return 0;
        $stmt->close();
        return (int) $conn->insert_id;
    }
}

if (!function_exists('commerceCenterUpsertItem')) {
    function commerceCenterUpsertItem(int $orderId, array $data): int
    {
        global $conn;
        $params = [
            $orderId, max(1, (int) ($data['item_no'] ?? 1)), max(0, (int) ($data['local_product_id'] ?? 0)), max(0, (int) ($data['local_variant_id'] ?? 0)),
            commerceCenterText($data['remote_product_id'] ?? '', 190), commerceCenterText($data['product_name_snapshot'] ?? '', 255), commerceCenterText($data['duration_snapshot'] ?? '', 120),
            max(1, (int) ($data['quantity'] ?? 1)), round((float) ($data['unit_price'] ?? 0), 2), round((float) ($data['unit_cost'] ?? 0), 2),
            round((float) ($data['total_price'] ?? 0), 2), round((float) ($data['total_cost'] ?? 0), 2), commerceCenterJson((array) ($data['snapshot'] ?? [])),
        ];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_order_items
             (order_id,item_no,local_product_id,local_variant_id,remote_product_id,product_name_snapshot,duration_snapshot,quantity,unit_price,unit_cost,total_price,total_cost,snapshot_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),local_product_id=VALUES(local_product_id),local_variant_id=VALUES(local_variant_id),
              remote_product_id=VALUES(remote_product_id),product_name_snapshot=VALUES(product_name_snapshot),duration_snapshot=VALUES(duration_snapshot),
              quantity=VALUES(quantity),unit_price=VALUES(unit_price),unit_cost=VALUES(unit_cost),total_price=VALUES(total_price),total_cost=VALUES(total_cost),snapshot_json=VALUES(snapshot_json)",
            $params
        );
        if (!$stmt) return 0;
        $stmt->close();
        return (int) $conn->insert_id;
    }
}

if (!function_exists('commerceCenterUpsertDelivery')) {
    function commerceCenterUpsertDelivery(int $orderId, int $itemId, array $data): bool
    {
        $key = commerceCenterText($data['key_code'] ?? '', 2000);
        $hash = strtolower(commerceCenterText($data['key_hash'] ?? '', 64));
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) $hash = $key !== '' ? hash('sha256', trim($key)) : '';
        if ($hash === '') return false;
        $params = [
            $orderId, $itemId, commerceCenterSiteId(), commerceCenterText($data['source_type'] ?? '', 40), commerceCenterText($data['source_key_record_id'] ?? '', 190),
            commerceCenterText($data['source_inventory_key_id'] ?? '', 190), $hash, commerceCenterMaskKey($key), commerceCenterText($data['delivered_to_site_id'] ?? '', 100), commerceCenterText($data['delivered_to_user_id'] ?? '', 190),
            commerceCenterText($data['customer_ref'] ?? '', 255), commerceCenterText($data['delivered_name_snapshot'] ?? '', 190), commerceCenterText($data['delivered_email_snapshot'] ?? '', 190),
            commerceCenterText($data['ownership_status'] ?? 'unknown', 40), $data['delivered_at'] ?? null,
        ];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_deliveries
             (order_id,order_item_id,source_site_id,source_type,source_key_record_id,source_inventory_key_id,key_hash,key_mask,delivered_to_site_id,delivered_to_user_id,customer_ref,
              delivered_name_snapshot,delivered_email_snapshot,ownership_status,delivered_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE order_id=VALUES(order_id),order_item_id=VALUES(order_item_id),source_inventory_key_id=VALUES(source_inventory_key_id),key_hash=VALUES(key_hash),key_mask=VALUES(key_mask),
              delivered_to_site_id=VALUES(delivered_to_site_id),delivered_to_user_id=VALUES(delivered_to_user_id),customer_ref=VALUES(customer_ref),
              delivered_name_snapshot=VALUES(delivered_name_snapshot),delivered_email_snapshot=VALUES(delivered_email_snapshot),
              ownership_status=VALUES(ownership_status),delivered_at=VALUES(delivered_at)",
            $params
        );
        if (!$stmt) return false;
        $stmt->close();
        return true;
    }
}

if (!function_exists('commerceCenterFinalizeCounts')) {
    function commerceCenterFinalizeCounts(int $orderId): bool
    {
        $stmt = commerceCenterExecute(
            "UPDATE commerce_orders SET
              item_count=(SELECT COUNT(*) FROM commerce_order_items WHERE order_id=?),
              delivery_count=(SELECT COUNT(*) FROM commerce_deliveries WHERE order_id=?),
              last_synced_at=NOW(),last_error=NULL
             WHERE id=?",
            [$orderId, $orderId, $orderId]
        );
        if (!$stmt) return false;
        $stmt->close();
        return true;
    }
}


if (!function_exists('commerceCenterLedgerEntryKey')) {
    function commerceCenterLedgerEntryKey(array $data): string
    {
        $eventId = commerceCenterText($data['source_event_id'] ?? '', 190);
        if ($eventId === '') {
            $eventId = commerceCenterText(($data['entry_type'] ?? '') . ':' . ($data['account_type'] ?? '') . ':' . ($data['account_id'] ?? ''), 190);
        }
        // The idempotency key uses immutable source identity only. Entry labels,
        // account snapshots and inferred transaction types may be corrected by
        // later reconciliation without producing a duplicate ledger row.
        $parts = [
            commerceCenterSiteId(),
            commerceCenterText($data['source_type'] ?? '', 60),
            commerceCenterText($data['source_record_id'] ?? '', 190),
            $eventId,
        ];
        return hash('sha256', implode("\0", $parts));
    }
}

if (!function_exists('commerceCenterUpsertLedgerEntry')) {
    function commerceCenterUpsertLedgerEntry(array $data): bool
    {
        if (!commerceCenterEnsureSchema()) return false;
        $direction = strtolower(commerceCenterText($data['direction'] ?? '', 10));
        if (!in_array($direction, ['debit', 'credit'], true)) return false;
        $amount = round(abs((float) ($data['amount'] ?? 0)), 2);
        if (!is_finite($amount)) return false;
        $entryKey = strtolower(commerceCenterText($data['entry_key'] ?? '', 64));
        if (!preg_match('/^[a-f0-9]{64}$/D', $entryKey)) $entryKey = commerceCenterLedgerEntryKey($data);
        $entryUuid = commerceCenterText($data['entry_uuid'] ?? '', 36);
        if ($entryUuid === '') $entryUuid = commerceCenterUuid();
        $balanceBefore = array_key_exists('balance_before', $data) && $data['balance_before'] !== null ? round((float) $data['balance_before'], 2) : null;
        $balanceAfter = array_key_exists('balance_after', $data) && $data['balance_after'] !== null ? round((float) $data['balance_after'], 2) : null;
        $params = [
            $entryUuid, $entryKey, max(0, (int) ($data['order_id'] ?? 0)), commerceCenterSiteId(),
            commerceCenterText($data['source_type'] ?? '', 60), commerceCenterText($data['source_record_id'] ?? '', 190), commerceCenterText($data['source_event_id'] ?? '', 190),
            commerceCenterText($data['entry_type'] ?? '', 60), commerceCenterText($data['account_type'] ?? '', 60), commerceCenterText($data['account_id'] ?? '', 190),
            commerceCenterText($data['account_label_snapshot'] ?? '', 190), commerceCenterText($data['counterparty_type'] ?? '', 60), commerceCenterText($data['counterparty_id'] ?? '', 190),
            commerceCenterText($data['counterparty_label_snapshot'] ?? '', 190), $direction, $amount, round((float) ($data['reporting_amount'] ?? 0), 2),
            strtoupper(commerceCenterText($data['currency'] ?? 'THB', 3)), $balanceBefore, $balanceAfter, commerceCenterText($data['status'] ?? 'completed', 40),
            commerceCenterText($data['description'] ?? '', 1000), $data['occurred_at'] ?? null, commerceCenterJson((array) ($data['snapshot'] ?? [])),
        ];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_ledger_entries
             (entry_uuid,entry_key,order_id,source_site_id,source_type,source_record_id,source_event_id,entry_type,account_type,account_id,
              account_label_snapshot,counterparty_type,counterparty_id,counterparty_label_snapshot,direction,amount,reporting_amount,currency,
              balance_before,balance_after,status,description,occurred_at,snapshot_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),order_id=VALUES(order_id),source_site_id=VALUES(source_site_id),source_type=VALUES(source_type),
              source_record_id=VALUES(source_record_id),source_event_id=VALUES(source_event_id),entry_type=VALUES(entry_type),account_type=VALUES(account_type),
              account_id=VALUES(account_id),account_label_snapshot=VALUES(account_label_snapshot),counterparty_type=VALUES(counterparty_type),
              counterparty_id=VALUES(counterparty_id),counterparty_label_snapshot=VALUES(counterparty_label_snapshot),direction=VALUES(direction),amount=VALUES(amount),
              reporting_amount=VALUES(reporting_amount),currency=VALUES(currency),balance_before=VALUES(balance_before),balance_after=VALUES(balance_after),
              status=VALUES(status),description=VALUES(description),occurred_at=VALUES(occurred_at),snapshot_json=VALUES(snapshot_json)",
            $params
        );
        if (!$stmt) return false;
        $stmt->close();
        return true;
    }
}

if (!function_exists('commerceCenterOrderFinancialStatus')) {
    function commerceCenterOrderFinancialStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['success', 'completed'], true)) return 'recognized';
        if ($status === 'refunded') return 'refunded';
        if (in_array($status, ['refunded_conflict', 'manual_review', 'unknown'], true)) return 'review';
        if (in_array($status, ['failed', 'rejected', 'cancelled', 'canceled', 'void'], true)) return 'void';
        return 'pending';
    }
}

if (!function_exists('commerceCenterCalculateOrderFinancials')) {
    /**
     * Pure financial classification used by both live sync and regression tests.
     * Unknown local inventory cost is deliberately kept as NULL profit instead
     * of being promoted to a fictional zero cost.
     *
     * @return array<string,mixed>
     */
    function commerceCenterCalculateOrderFinancials(string $sourceType, string $sourceStatus, float $orderTotal, float $rawCost, int $deliveryCount): array
    {
        $sourceType = strtolower(trim($sourceType));
        $sourceStatus = strtolower(trim($sourceStatus));
        $financialStatus = commerceCenterOrderFinancialStatus($sourceStatus);
        $orderTotal = round(max(0, $orderTotal), 2);
        $rawCost = round(max(0, $rawCost), 2);
        $deliveryCount = max(0, $deliveryCount);
        $costKnown = in_array($sourceType, ['cgo_purchase', 'supplier_purchase'], true) || $rawCost > 0;

        $saleTotal = 0.0;
        $costTotal = 0.0;
        $refundTotal = 0.0;
        $netRevenue = 0.0;
        $profitTotal = null;
        $marginPercent = null;
        if ($financialStatus === 'recognized') {
            $saleTotal = $orderTotal;
            $costTotal = $rawCost;
            $netRevenue = $saleTotal;
            if ($costKnown) {
                $profitTotal = round($netRevenue - $costTotal, 2);
                $marginPercent = $saleTotal > 0 ? round(($profitTotal / $saleTotal) * 100, 4) : null;
            }
        } elseif (in_array($financialStatus, ['refunded', 'review'], true) && in_array($sourceStatus, ['refunded', 'refunded_conflict'], true)) {
            $saleTotal = $orderTotal;
            $refundTotal = $orderTotal;
            $costTotal = $deliveryCount > 0 ? $rawCost : 0.0;
            if ($costKnown) $profitTotal = round(-$costTotal, 2);
        }

        return [
            'financial_status' => $financialStatus,
            'cost_status' => $costKnown ? 'known' : 'unknown',
            'cost_known' => $costKnown,
            'sale_total' => $saleTotal,
            'cost_total' => $costTotal,
            'refund_total' => $refundTotal,
            'net_revenue' => $netRevenue,
            'profit_total' => $profitTotal,
            'margin_percent' => $marginPercent,
        ];
    }
}

if (!function_exists('commerceCenterSyncOrderFinancials')) {
    function commerceCenterSyncOrderFinancials(int $orderId): bool
    {
        if ($orderId < 1 || !commerceCenterEnsureSchema()) return false;
        $row = commerceCenterQueryFirst(
            "SELECT o.*,COALESCE(i.variant_count,0) AS variant_count,COALESCE(i.item_cost_total,0) AS item_cost_total
             FROM commerce_orders o
             LEFT JOIN (
                SELECT order_id,SUM(total_cost) AS item_cost_total,SUM(CASE WHEN local_variant_id>0 THEN 1 ELSE 0 END) AS variant_count
                FROM commerce_order_items WHERE order_id=? GROUP BY order_id
             ) i ON i.order_id=o.id
             WHERE o.id=? LIMIT 1",
            [$orderId, $orderId]
        );
        if (!$row) return false;

        $sourceType = strtolower(trim((string) $row['source_type']));
        $sourceStatus = strtolower(trim((string) $row['status']));
        $currency = strtoupper(commerceCenterText($row['currency'] ?? 'THB', 3));
        $orderTotal = round(max(0, (float) $row['total']), 2);
        $rawCost = round(max(0, (float) $row['cost_total']), 2);
        $calculation = commerceCenterCalculateOrderFinancials(
            $sourceType,
            $sourceStatus,
            $orderTotal,
            $rawCost,
            (int) ($row['delivery_count'] ?? 0)
        );
        $financialStatus = (string) $calculation['financial_status'];
        $costStatus = (string) $calculation['cost_status'];
        $costKnown = !empty($calculation['cost_known']);
        $saleTotal = (float) $calculation['sale_total'];
        $costTotal = (float) $calculation['cost_total'];
        $refundTotal = (float) $calculation['refund_total'];
        $netRevenue = (float) $calculation['net_revenue'];
        $profitTotal = $calculation['profit_total'];
        $marginPercent = $calculation['margin_percent'];

        $params = [
            $orderId, $currency, $saleTotal, $costTotal, 0.0, $refundTotal, $netRevenue, $profitTotal, $marginPercent,
            $financialStatus, $costStatus, '1.1', commerceCenterJson([
                'source_status' => $sourceStatus,
                'order_total_snapshot' => $orderTotal,
                'order_cost_snapshot' => $rawCost,
                'delivery_count' => (int) ($row['delivery_count'] ?? 0),
                'cost_known' => $costKnown,
            ]),
        ];
        $stmt = commerceCenterExecute(
            "INSERT INTO commerce_order_financials
             (order_id,currency,sale_total,cost_total,fee_total,refund_total,net_revenue,profit_total,margin_percent,financial_status,cost_status,calculation_version,snapshot_json,last_calculated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE currency=VALUES(currency),sale_total=VALUES(sale_total),cost_total=VALUES(cost_total),fee_total=VALUES(fee_total),
              refund_total=VALUES(refund_total),net_revenue=VALUES(net_revenue),profit_total=VALUES(profit_total),margin_percent=VALUES(margin_percent),
              financial_status=VALUES(financial_status),cost_status=VALUES(cost_status),calculation_version=VALUES(calculation_version),
              snapshot_json=VALUES(snapshot_json),last_calculated_at=NOW()",
            $params
        );
        if (!$stmt) return false;
        $stmt->close();

        $buyerType = $sourceType === 'store_api_sale' ? 'api_client' : 'local_user';
        $buyerId = $sourceType === 'store_api_sale' ? (string) $row['commercial_buyer_id'] : (string) $row['local_user_id'];
        $buyerLabel = trim((string) $row['buyer_name_snapshot']);
        if ($sourceType === 'store_api_sale') {
            $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
            if (is_array($snapshot) && trim((string) ($snapshot['client_name'] ?? '')) !== '') $buyerLabel = (string) $snapshot['client_name'];
        }
        $occurredAt = $row['source_completed_at'] ?: $row['source_created_at'];
        $expectedEvents = [];

        if ($saleTotal > 0) {
            if (!commerceCenterUpsertLedgerEntry([
                'order_id' => $orderId, 'source_type' => 'commerce_order', 'source_record_id' => (string) $orderId, 'source_event_id' => 'sale_revenue',
                'entry_type' => 'sale_revenue', 'account_type' => 'sales_revenue', 'account_id' => commerceCenterSiteId(), 'account_label_snapshot' => commerceCenterSiteLabel(),
                'counterparty_type' => $buyerType, 'counterparty_id' => $buyerId, 'counterparty_label_snapshot' => $buyerLabel,
                'direction' => 'credit', 'amount' => $saleTotal, 'reporting_amount' => $saleTotal, 'currency' => $currency,
                'status' => $financialStatus, 'description' => 'Recognized sale for ' . $row['source_type'] . ' #' . $row['source_record_id'], 'occurred_at' => $occurredAt,
                'snapshot' => ['order_uuid' => $row['order_uuid'], 'external_ref' => $row['external_ref']],
            ])) return false;
            $expectedEvents[] = 'sale_revenue';
        }
        if ($costKnown && $costTotal > 0) {
            if (!commerceCenterUpsertLedgerEntry([
                'order_id' => $orderId, 'source_type' => 'commerce_order', 'source_record_id' => (string) $orderId, 'source_event_id' => 'cost_of_goods',
                'entry_type' => 'cost_of_goods', 'account_type' => 'inventory_cost', 'account_id' => commerceCenterSiteId(), 'account_label_snapshot' => commerceCenterSiteLabel(),
                'counterparty_type' => $sourceType, 'counterparty_id' => (string) $row['source_record_id'], 'counterparty_label_snapshot' => $sourceType,
                'direction' => 'debit', 'amount' => $costTotal, 'reporting_amount' => -$costTotal, 'currency' => $currency,
                'status' => $financialStatus, 'description' => 'Cost of goods for ' . $row['source_type'] . ' #' . $row['source_record_id'], 'occurred_at' => $occurredAt,
                'snapshot' => ['cost_status' => $costStatus],
            ])) return false;
            $expectedEvents[] = 'cost_of_goods';
        }
        if ($refundTotal > 0) {
            if (!commerceCenterUpsertLedgerEntry([
                'order_id' => $orderId, 'source_type' => 'commerce_order', 'source_record_id' => (string) $orderId, 'source_event_id' => 'refund_reversal',
                'entry_type' => 'refund_reversal', 'account_type' => 'sales_returns', 'account_id' => commerceCenterSiteId(), 'account_label_snapshot' => commerceCenterSiteLabel(),
                'counterparty_type' => $buyerType, 'counterparty_id' => $buyerId, 'counterparty_label_snapshot' => $buyerLabel,
                'direction' => 'debit', 'amount' => $refundTotal, 'reporting_amount' => -$refundTotal, 'currency' => $currency,
                'status' => $financialStatus, 'description' => 'Refund reversal for ' . $row['source_type'] . ' #' . $row['source_record_id'], 'occurred_at' => $row['source_updated_at'] ?: $occurredAt,
                'snapshot' => ['source_status' => $sourceStatus],
            ])) return false;
            $expectedEvents[] = 'refund_reversal';
        }

        // Delete stale derived events only after every expected replacement was
        // written successfully. This prevents a temporary reporting gap if one
        // insert fails outside the source-order transaction.
        if ($expectedEvents === []) {
            $cleanup = commerceCenterExecute("DELETE FROM commerce_ledger_entries WHERE order_id=? AND source_type='commerce_order'", [$orderId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($expectedEvents), '?'));
            $cleanup = commerceCenterExecute(
                "DELETE FROM commerce_ledger_entries WHERE order_id=? AND source_type='commerce_order' AND source_event_id NOT IN ({$placeholders})",
                array_merge([$orderId], $expectedEvents)
            );
        }
        if (!$cleanup) return false;
        $cleanup->close();
        return true;
    }
}

if (!function_exists('commerceCenterTransactionDirection')) {
    function commerceCenterTransactionDirection(string $type, float $rawAmount): string
    {
        $type = strtolower(trim($type));
        if (in_array($type, ['purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_purchase', 'manual_deduct'], true)) return 'debit';
        if (in_array($type, ['deposit', 'redeem_code', 'manual_add', 'rank_bonus'], true)) return 'credit';
        return $rawAmount < 0 ? 'debit' : 'credit';
    }
}

if (!function_exists('commerceCenterSyncTransactionEntry')) {
    function commerceCenterSyncTransactionEntry(int $transactionId): bool
    {
        if ($transactionId < 1 || !commerceCenterTableExists('transactions') || !commerceCenterEnsureSchema()) return false;
        $row = commerceCenterQueryFirst(
            "SELECT t.*,COALESCE(u.username,CONCAT('User#',t.user_id)) AS username,COALESCE(u.email,'') AS email,
                    COALESCE((SELECT c.id FROM commerce_orders c WHERE c.source_site_id=? AND c.transaction_id=t.id ORDER BY c.id DESC LIMIT 1),0) AS commerce_order_id
             FROM transactions t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1",
            [commerceCenterSiteId(), $transactionId]
        );
        if (!$row) return false;
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        if (function_exists('transactionIntegrityEffectiveType')) $type = transactionIntegrityEffectiveType($row);
        $knownTypes = function_exists('transactionIntegrityKnownTypes') ? transactionIntegrityKnownTypes() : [
            'purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_purchase', 'deposit', 'redeem_code', 'manual_add', 'manual_deduct', 'rank_bonus',
        ];
        // Some legacy transaction ENUMs silently stored an empty type for API
        // purchases. Resolve those from authoritative order links instead of
        // labeling them as an unexplained generic transaction.
        if (!in_array($type, $knownTypes, true)) {
            if (commerceCenterTableExists('cgo_orders') && !empty(commerceCenterColumns('cgo_orders')['transaction_id'])
                && commerceCenterQueryFirst('SELECT id FROM cgo_orders WHERE transaction_id=? LIMIT 1', [$transactionId])) {
                $type = 'cgo_purchase';
            } elseif (commerceCenterTableExists('supplier_orders') && !empty(commerceCenterColumns('supplier_orders')['transaction_id'])
                && commerceCenterQueryFirst('SELECT id FROM supplier_orders WHERE transaction_id=? LIMIT 1', [$transactionId])) {
                $type = 'supplier_purchase';
            } elseif (commerceCenterTableExists('store_api_orders') && !empty(commerceCenterColumns('store_api_orders')['billing_transaction_id'])
                && commerceCenterQueryFirst('SELECT id FROM store_api_orders WHERE billing_transaction_id=? LIMIT 1', [$transactionId])) {
                $type = 'store_api_purchase';
            }
        }
        if ($type === '') $type = 'transaction';
        $rawAmount = (float) ($row['amount'] ?? 0);
        $direction = commerceCenterTransactionDirection($type, $rawAmount);
        return commerceCenterUpsertLedgerEntry([
            'order_id' => (int) ($row['commerce_order_id'] ?? 0), 'source_type' => 'transaction', 'source_record_id' => (string) $transactionId, 'source_event_id' => 'wallet_entry',
            'entry_type' => $type, 'account_type' => 'local_user_wallet', 'account_id' => (string) ((int) $row['user_id']), 'account_label_snapshot' => (string) $row['username'],
            'counterparty_type' => 'site', 'counterparty_id' => commerceCenterSiteId(), 'counterparty_label_snapshot' => commerceCenterSiteLabel(),
            'direction' => $direction, 'amount' => abs($rawAmount), 'reporting_amount' => 0, 'currency' => function_exists('getSetting') ? strtoupper((string) (getSetting('currency_name', 'THB') ?: 'THB')) : 'THB',
            'status' => (string) ($row['status'] ?? ''), 'description' => (string) ($row['description'] ?? ''), 'occurred_at' => $row['created_at'] ?? null,
            'snapshot' => ['reference_id' => (int) ($row['reference_id'] ?? 0), 'email' => (string) $row['email'], 'stored_type' => (string) ($row['type'] ?? '')],
        ]);
    }
}

if (!function_exists('commerceCenterSyncStoreApiBalanceEntry')) {
    function commerceCenterSyncStoreApiBalanceEntry(int $ledgerId): bool
    {
        if ($ledgerId < 1 || !commerceCenterTableExists('store_api_balance_ledger') || !commerceCenterEnsureSchema()) return false;
        $row = commerceCenterQueryFirst(
            "SELECT l.*,COALESCE(c.name,CONCAT('API client#',l.client_id)) AS client_name,COALESCE(c.currency,'THB') AS currency,
                    COALESCE((SELECT o.id FROM commerce_orders o WHERE o.source_site_id=? AND o.source_type='store_api_sale' AND o.source_record_id=CONVERT(CAST(l.order_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1),0) AS commerce_order_id
             FROM store_api_balance_ledger l LEFT JOIN store_api_clients c ON c.id=l.client_id WHERE l.id=? LIMIT 1",
            [commerceCenterSiteId(), $ledgerId]
        );
        if (!$row) return false;
        $rawAmount = round((float) ($row['amount'] ?? 0), 2);
        $balanceAfter = round((float) ($row['balance_after'] ?? 0), 2);
        $balanceBefore = round($balanceAfter - $rawAmount, 2);
        return commerceCenterUpsertLedgerEntry([
            'order_id' => (int) ($row['commerce_order_id'] ?? 0), 'source_type' => 'store_api_balance_ledger', 'source_record_id' => (string) $ledgerId, 'source_event_id' => 'api_wallet_entry',
            'entry_type' => commerceCenterText($row['entry_type'] ?? 'api_balance', 60), 'account_type' => 'api_client_wallet', 'account_id' => (string) ((int) $row['client_id']),
            'account_label_snapshot' => (string) $row['client_name'], 'counterparty_type' => 'site', 'counterparty_id' => commerceCenterSiteId(), 'counterparty_label_snapshot' => commerceCenterSiteLabel(),
            'direction' => $rawAmount < 0 ? 'debit' : 'credit', 'amount' => abs($rawAmount), 'reporting_amount' => 0, 'currency' => (string) $row['currency'],
            'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter, 'status' => 'completed', 'description' => (string) ($row['note'] ?? ''), 'occurred_at' => $row['created_at'] ?? null,
            'snapshot' => ['order_id' => (int) ($row['order_id'] ?? 0), 'admin_id' => (int) ($row['admin_id'] ?? 0), 'raw_amount' => $rawAmount],
        ]);
    }
}

if (!function_exists('commerceCenterSyncLinkedLedgerSourcesForOrder')) {
    function commerceCenterSyncLinkedLedgerSourcesForOrder(int $orderId): bool
    {
        $order = commerceCenterQueryFirst('SELECT source_type,source_record_id,transaction_id FROM commerce_orders WHERE id=? LIMIT 1', [$orderId]);
        if (!$order) return false;

        // Linked wallet/credit rows enrich the read model, but their absence must
        // never roll back a valid central order. Record a repair job and let the
        // reconciler retry independently.
        $transactionId = (int) ($order['transaction_id'] ?? 0);
        if ($transactionId > 0) {
            if (commerceCenterSyncTransactionEntry($transactionId)) {
                commerceCenterResolveFailure('ledger_transaction', (string) $transactionId);
            } else {
                commerceCenterRecordFailure('ledger_transaction', (string) $transactionId, commerceCenterSqlFailure('Unable to sync linked transaction ledger'), 'ledger_link_failed');
            }
        }

        if ((string) $order['source_type'] === 'store_api_sale' && commerceCenterTableExists('store_api_balance_ledger')) {
            $sourceId = (int) $order['source_record_id'];
            $stmt = commerceCenterExecute('SELECT id FROM store_api_balance_ledger WHERE order_id=? ORDER BY id ASC', [$sourceId]);
            if (!$stmt) {
                commerceCenterRecordFailure('ledger_api_order', (string) $sourceId, commerceCenterSqlFailure('Unable to enumerate API balance entries'), 'ledger_link_failed');
                return true;
            }
            commerceCenterResolveFailure('ledger_api_order', (string) $sourceId);
            $result = $stmt->get_result();
            $ids = [];
            while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
            if ($result) $result->free();
            $stmt->close();
            foreach ($ids as $id) {
                if (commerceCenterSyncStoreApiBalanceEntry($id)) {
                    commerceCenterResolveFailure('ledger_api_balance', (string) $id);
                } else {
                    commerceCenterRecordFailure('ledger_api_balance', (string) $id, commerceCenterSqlFailure('Unable to sync linked API balance ledger'), 'ledger_link_failed');
                }
            }
        }
        return true;
    }
}

if (!function_exists('commerceCenterReconcileLedger')) {
    function commerceCenterReconcileLedger(int $limit = 120): array
    {
        $limit = max(6, min(600, $limit));
        if (!commerceCenterEnsureSchema()) return ['success' => false, 'attempted' => 0, 'succeeded' => 0, 'failed' => 1, 'message' => 'Ledger schema is unavailable', 'errors' => [['stage' => 'schema', 'code' => 'ledger_schema_unavailable', 'detail' => commerceCenterSqlFailure('Ledger schema is unavailable')]]];
        $perType = max(2, intdiv($limit, 3));
        $summary = ['success' => true, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'financials' => 0, 'transactions' => 0, 'api_ledger' => 0, 'errors' => []];

        $stmt = commerceCenterExecute(
            "SELECT o.id FROM commerce_orders o LEFT JOIN commerce_order_financials f ON f.order_id=o.id
             WHERE f.id IS NULL OR o.updated_at>f.last_calculated_at ORDER BY o.updated_at ASC,o.id ASC LIMIT {$perType}"
        );
        if ($stmt) {
            $result = $stmt->get_result(); $ids = [];
            while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
            if ($result) $result->free(); $stmt->close();
            foreach ($ids as $id) {
                $summary['attempted']++;
                $ok = commerceCenterSyncOrderFinancials($id) && commerceCenterSyncLinkedLedgerSourcesForOrder($id);
                if ($ok) { $summary['succeeded']++; $summary['financials']++; } else { $summary['failed']++; commerceCenterRecordFailure('ledger_order', (string) $id, commerceCenterSqlFailure('Unable to sync order financials')); }
            }
        } else {
            $summary['failed']++;
            $summary['errors'][] = ['stage' => 'financial_query', 'code' => 'ledger_financial_query_failed', 'detail' => commerceCenterSqlFailure('Unable to enumerate order financials')];
        }

        if (commerceCenterTableExists('transactions')) {
            $siteId = commerceCenterSiteId();
            $stmt = commerceCenterExecute(
                "SELECT t.id FROM transactions t LEFT JOIN commerce_ledger_entries l ON l.source_site_id=? AND l.source_type='transaction'
                 AND l.source_record_id=CONVERT(CAST(t.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND l.source_event_id='wallet_entry'
                 WHERE l.id IS NULL
                    OR (LOWER(TRIM(CONVERT(COALESCE(l.status,'') USING utf8mb4))) COLLATE utf8mb4_unicode_ci
                        <> LOWER(TRIM(CONVERT(COALESCE(t.status,'') USING utf8mb4))) COLLATE utf8mb4_unicode_ci)
                    OR EXISTS (SELECT 1 FROM commerce_sync_failures f
                               WHERE f.source_type='ledger_transaction' AND f.resolved_at IS NULL
                                 AND CONVERT(f.source_record_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                     = CONVERT(CAST(t.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                 ORDER BY CASE WHEN EXISTS (SELECT 1 FROM commerce_sync_failures f2
                                            WHERE f2.source_type='ledger_transaction' AND f2.resolved_at IS NULL
                                              AND CONVERT(f2.source_record_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                                  = CONVERT(CAST(t.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci)
                               THEN 0 ELSE 1 END, t.id DESC LIMIT {$perType}",
                [$siteId]
            );
            if ($stmt) {
                $result = $stmt->get_result(); $ids = [];
                while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
                if ($result) $result->free(); $stmt->close();
                foreach ($ids as $id) {
                    $summary['attempted']++;
                    if (commerceCenterSyncTransactionEntry($id)) { $summary['succeeded']++; $summary['transactions']++; commerceCenterResolveFailure('ledger_transaction', (string) $id); }
                    else { $summary['failed']++; commerceCenterRecordFailure('ledger_transaction', (string) $id, commerceCenterSqlFailure('Unable to sync transaction ledger')); }
                }
            } else {
                $summary['failed']++;
                $summary['errors'][] = ['stage' => 'transaction_query', 'code' => 'ledger_transaction_query_failed', 'detail' => commerceCenterSqlFailure('Unable to enumerate transaction ledger')];
            }
        }

        if (commerceCenterTableExists('store_api_balance_ledger')) {
            $siteId = commerceCenterSiteId();
            $stmt = commerceCenterExecute(
                "SELECT s.id FROM store_api_balance_ledger s LEFT JOIN commerce_ledger_entries l ON l.source_site_id=? AND l.source_type='store_api_balance_ledger'
                 AND l.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND l.source_event_id='api_wallet_entry'
                 WHERE l.id IS NULL ORDER BY s.id DESC LIMIT {$perType}",
                [$siteId]
            );
            if ($stmt) {
                $result = $stmt->get_result(); $ids = [];
                while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
                if ($result) $result->free(); $stmt->close();
                foreach ($ids as $id) {
                    $summary['attempted']++;
                    if (commerceCenterSyncStoreApiBalanceEntry($id)) { $summary['succeeded']++; $summary['api_ledger']++; commerceCenterResolveFailure('ledger_api_balance', (string) $id); }
                    else { $summary['failed']++; commerceCenterRecordFailure('ledger_api_balance', (string) $id, commerceCenterSqlFailure('Unable to sync API balance ledger')); }
                }
            } else {
                $summary['failed']++;
                $summary['errors'][] = ['stage' => 'api_ledger_query', 'code' => 'ledger_api_query_failed', 'detail' => commerceCenterSqlFailure('Unable to enumerate API balance ledger')];
            }
        }
        $summary['success'] = $summary['failed'] === 0;
        $summary['message'] = 'ledger attempted=' . $summary['attempted'] . ', succeeded=' . $summary['succeeded'] . ', failed=' . $summary['failed'];
        return $summary;
    }
}

if (!function_exists('commerceCenterListLedger')) {
    function commerceCenterListLedger(array $filters = [], int $limit = 300, int $offset = 0): array
    {
        if (!commerceCenterEnsureSchema()) return [];
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $where = ['1=1']; $params = [];
        $source = commerceCenterText($filters['source_type'] ?? '', 60);
        $orderSources = ['local_purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_sale'];
        if (in_array($source, $orderSources, true)) {
            $where[] = "l.source_type='commerce_order' AND o.source_type=?";
            $params[] = $source;
        } elseif ($source !== '') {
            $where[] = 'l.source_type=?';
            $params[] = $source;
        }
        foreach (['entry_type' => 60, 'account_type' => 60, 'status' => 40] as $key => $max) {
            $value = commerceCenterText($filters[$key] ?? '', $max);
            if ($value !== '') { $where[] = 'l.' . $key . '=?'; $params[] = $value; }
        }
        $search = commerceCenterText($filters['search'] ?? '', 190);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(l.description LIKE ? OR l.account_label_snapshot LIKE ? OR l.counterparty_label_snapshot LIKE ? OR l.source_record_id LIKE ? OR o.order_uuid LIKE ? OR o.external_ref LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $dateFrom = commerceCenterText($filters['date_from'] ?? '', 10);
        $dateTo = commerceCenterText($filters['date_to'] ?? '', 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateFrom)) { $where[] = 'l.occurred_at>=?'; $params[] = $dateFrom . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateTo)) { $where[] = 'l.occurred_at<?'; $params[] = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day')); }
        $stmt = commerceCenterExecute(
            "SELECT l.*,o.order_uuid,o.source_type AS order_source_type,o.source_record_id AS order_source_record_id,o.external_ref,
                    o.buyer_name_snapshot,o.customer_ref,i.product_name_snapshot,i.duration_snapshot
             FROM commerce_ledger_entries l
             LEFT JOIN commerce_orders o ON o.id=l.order_id
             LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
             WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(l.occurred_at,l.created_at) DESC,l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        if (!$stmt) return [];
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) $result->free(); $stmt->close();
        return $rows;
    }
}

if (!function_exists('commerceCenterListFinancials')) {
    function commerceCenterListFinancials(array $filters = [], int $limit = 300, int $offset = 0): array
    {
        if (!commerceCenterEnsureSchema()) return [];
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $where = ['1=1']; $params = [];
        $source = commerceCenterText($filters['source_type'] ?? '', 40);
        $status = commerceCenterText($filters['financial_status'] ?? '', 40);
        $search = commerceCenterText($filters['search'] ?? '', 190);
        if ($source !== '') { $where[] = 'o.source_type=?'; $params[] = $source; }
        if ($status !== '') { $where[] = 'f.financial_status=?'; $params[] = $status; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(o.order_uuid LIKE ? OR o.external_ref LIKE ? OR o.buyer_name_snapshot LIKE ? OR o.customer_ref LIKE ? OR i.product_name_snapshot LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $dateFrom = commerceCenterText($filters['date_from'] ?? '', 10);
        $dateTo = commerceCenterText($filters['date_to'] ?? '', 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateFrom)) {
            $where[] = 'COALESCE(o.source_completed_at,o.source_created_at)>=?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateTo)) {
            $where[] = 'COALESCE(o.source_completed_at,o.source_created_at)<?';
            $params[] = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day'));
        }
        $stmt = commerceCenterExecute(
            "SELECT f.*,o.order_uuid,o.source_type,o.source_record_id,o.status AS order_status,o.buyer_name_snapshot,o.customer_ref,o.external_ref,
                    o.source_created_at,o.source_completed_at,i.product_name_snapshot,i.duration_snapshot,i.quantity
             FROM commerce_order_financials f JOIN commerce_orders o ON o.id=f.order_id
             LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
             WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(o.source_completed_at,o.source_created_at) DESC,o.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        if (!$stmt) return [];
        $result = $stmt->get_result(); $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) $result->free(); $stmt->close();
        return $rows;
    }
}

if (!function_exists('commerceCenterProfitReport')) {
    /**
     * Build one canonical profit report from Commerce Center financial snapshots.
     * Summary/product aggregates always cover the full filtered range; only the
     * detail list is bounded. This keeps report math in one place and prevents
     * Store API / supplier / CGO sales from drifting into separate calculators.
     *
     * Supported filters: date_from, date_to, source_type, financial_status.
     * financial_status defaults to recognized; pass "all" to include every state.
     *
     * @return array<string,mixed>
     */
    function commerceCenterProfitReport(array $filters = [], int $detailLimit = 500): array
    {
        $empty = [
            'success' => false,
            'message' => '',
            'filters' => ['date_from' => '', 'date_to' => '', 'source_type' => '', 'financial_status' => 'recognized'],
            'summary' => ['orders' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'unknown_cost_orders' => 0, 'profit_complete' => true],
            'by_product' => [],
            'details' => [],
            'detail_limit' => max(0, min(1000, $detailLimit)),
            'details_truncated' => false,
        ];
        if (!commerceCenterEnsureSchema()) {
            $empty['message'] = 'Commerce Center financial data is unavailable';
            return $empty;
        }

        $normalizeDate = static function ($value): string {
            if (!is_scalar($value) && $value !== null) return '';
            $value = trim((string) $value);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return '';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0))) return '';
            return $date->format('Y-m-d') === $value ? $value : '';
        };

        $dateFrom = $normalizeDate($filters['date_from'] ?? '');
        $dateTo = $normalizeDate($filters['date_to'] ?? '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            $empty['message'] = 'Profit report start date must not be after end date';
            $empty['filters']['date_from'] = $dateFrom;
            $empty['filters']['date_to'] = $dateTo;
            return $empty;
        }

        $sourceType = strtolower(commerceCenterText($filters['source_type'] ?? '', 40));
        $allowedSources = ['', 'local_purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_sale'];
        if (!in_array($sourceType, $allowedSources, true)) $sourceType = '';
        $financialStatus = strtolower(commerceCenterText($filters['financial_status'] ?? 'recognized', 40));
        $allowedStatuses = ['all', 'recognized', 'refunded', 'review', 'void', 'pending'];
        if (!in_array($financialStatus, $allowedStatuses, true)) $financialStatus = 'recognized';
        $detailLimit = max(0, min(1000, $detailLimit));

        $where = ['1=1'];
        $params = [];
        if ($financialStatus !== 'all') {
            $where[] = 'f.financial_status=?';
            $params[] = $financialStatus;
        }
        if ($sourceType !== '') {
            $where[] = 'o.source_type=?';
            $params[] = $sourceType;
        }
        if ($dateFrom !== '') {
            $where[] = 'COALESCE(o.source_completed_at,o.source_created_at)>=?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            $where[] = 'COALESCE(o.source_completed_at,o.source_created_at)<?';
            $params[] = $dateToExclusive;
        }
        $whereSql = implode(' AND ', $where);

        $summaryStmt = commerceCenterExecute(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(f.net_revenue),0) AS revenue,
                    COALESCE(SUM(f.cost_total),0) AS cost,
                    COALESCE(SUM(CASE WHEN f.profit_total IS NOT NULL THEN f.profit_total ELSE 0 END),0) AS profit,
                    COALESCE(SUM(CASE WHEN f.profit_total IS NULL AND f.financial_status IN ('recognized','refunded','review') THEN 1 ELSE 0 END),0) AS unknown_cost_orders
             FROM commerce_order_financials f
             JOIN commerce_orders o ON o.id=f.order_id
             WHERE {$whereSql}",
            $params
        );
        if (!$summaryStmt) {
            $empty['message'] = commerceCenterSqlFailure('Unable to calculate profit summary');
            return $empty;
        }
        $summaryResult = $summaryStmt->get_result();
        $summaryRow = $summaryResult ? $summaryResult->fetch_assoc() : null;
        if ($summaryResult) $summaryResult->free();
        $summaryStmt->close();
        if (!is_array($summaryRow)) {
            $empty['message'] = 'Unable to read profit summary';
            return $empty;
        }

        $productStmt = commerceCenterExecute(
            "SELECT COALESCE(NULLIF(i.product_name_snapshot,''),'(Unknown Product)') AS product_name,
                    COALESCE(SUM(CASE WHEN i.quantity>0 THEN i.quantity ELSE 1 END),0) AS quantity,
                    COALESCE(SUM(f.net_revenue),0) AS revenue,
                    COALESCE(SUM(f.cost_total),0) AS cost,
                    COALESCE(SUM(CASE WHEN f.profit_total IS NOT NULL THEN f.profit_total ELSE 0 END),0) AS profit,
                    COALESCE(SUM(CASE WHEN f.profit_total IS NULL THEN 1 ELSE 0 END),0) AS unknown_cost_orders
             FROM commerce_order_financials f
             JOIN commerce_orders o ON o.id=f.order_id
             LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
             WHERE {$whereSql}
             GROUP BY COALESCE(NULLIF(i.product_name_snapshot,''),'(Unknown Product)')
             ORDER BY profit DESC,revenue DESC,product_name ASC",
            $params
        );
        if (!$productStmt) {
            $empty['message'] = commerceCenterSqlFailure('Unable to calculate profit by product');
            return $empty;
        }
        $productResult = $productStmt->get_result();
        $productRows = $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : [];
        if ($productResult) $productResult->free();
        $productStmt->close();

        $byProduct = [];
        foreach ($productRows as $row) {
            $name = trim((string) ($row['product_name'] ?? ''));
            if ($name === '') $name = '(Unknown Product)';
            $byProduct[$name] = [
                'revenue' => round((float) ($row['revenue'] ?? 0), 2),
                'cost' => round((float) ($row['cost'] ?? 0), 2),
                'profit' => round((float) ($row['profit'] ?? 0), 2),
                'count' => max(0, (int) ($row['quantity'] ?? 0)),
                'unknown_cost_orders' => max(0, (int) ($row['unknown_cost_orders'] ?? 0)),
            ];
        }

        $details = [];
        if ($detailLimit > 0) {
            $detailStmt = commerceCenterExecute(
                "SELECT o.id AS order_id,o.source_type,o.source_record_id,o.commercial_buyer_type,o.commercial_buyer_id,
                        o.buyer_name_snapshot,o.snapshot_json,COALESCE(o.source_completed_at,o.source_created_at) AS created_at,
                        COALESCE(u.username,'') AS local_username,COALESCE(u.role,'') AS local_role,
                        COALESCE(NULLIF(i.product_name_snapshot,''),'(Unknown Product)') AS product_name,
                        COALESCE(NULLIF(i.duration_snapshot,''),'-') AS duration,COALESCE(i.quantity,1) AS quantity,
                        f.net_revenue AS revenue,f.cost_total AS cost,f.profit_total,f.cost_status
                 FROM commerce_order_financials f
                 JOIN commerce_orders o ON o.id=f.order_id
                 LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
                 LEFT JOIN users u ON u.id=o.local_user_id
                 WHERE {$whereSql}
                 ORDER BY COALESCE(o.source_completed_at,o.source_created_at) DESC,o.id DESC
                 LIMIT {$detailLimit}",
                $params
            );
            if (!$detailStmt) {
                $empty['message'] = commerceCenterSqlFailure('Unable to load profit details');
                return $empty;
            }
            $detailResult = $detailStmt->get_result();
            $detailRows = $detailResult ? $detailResult->fetch_all(MYSQLI_ASSOC) : [];
            if ($detailResult) $detailResult->free();
            $detailStmt->close();

            foreach ($detailRows as $row) {
                $source = strtolower(trim((string) ($row['source_type'] ?? '')));
                $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $username = trim((string) ($row['local_username'] ?? ''));
                $role = trim((string) ($row['local_role'] ?? ''));
                if ($source === 'store_api_sale') {
                    $clientName = trim((string) ($snapshot['client_name'] ?? ''));
                    if ($clientName !== '') $username = $clientName;
                    if ($username === '') $username = trim((string) ($row['buyer_name_snapshot'] ?? ''));
                    if ($username === '') $username = 'API client #' . max(0, (int) ($row['commercial_buyer_id'] ?? 0));
                    $role = 'api_client';
                } else {
                    if ($username === '') $username = trim((string) ($row['buyer_name_snapshot'] ?? ''));
                    if ($role === '') $role = trim((string) ($row['commercial_buyer_type'] ?? ''));
                }
                if ($username === '') $username = '-';
                if ($role === '') $role = '-';
                $profitKnown = $row['profit_total'] !== null && $row['profit_total'] !== '';
                $details[] = [
                    'tx_id' => max(0, (int) ($row['order_id'] ?? 0)),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'amount' => round((float) ($row['revenue'] ?? 0), 2),
                    'username' => $username,
                    'role' => $role,
                    'product_name' => (string) ($row['product_name'] ?? '(Unknown Product)'),
                    'duration' => (string) ($row['duration'] ?? '-'),
                    'cost_price' => round((float) ($row['cost'] ?? 0), 2),
                    'profit' => $profitKnown ? round((float) $row['profit_total'], 2) : null,
                    'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                    'source_type' => $source,
                    'cost_status' => (string) ($row['cost_status'] ?? 'unknown'),
                ];
            }
        }

        $unknownCostOrders = max(0, (int) ($summaryRow['unknown_cost_orders'] ?? 0));
        $orderCount = max(0, (int) ($summaryRow['orders'] ?? 0));
        return [
            'success' => true,
            'message' => '',
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'source_type' => $sourceType,
                'financial_status' => $financialStatus,
            ],
            'summary' => [
                'orders' => $orderCount,
                'revenue' => round((float) ($summaryRow['revenue'] ?? 0), 2),
                'cost' => round((float) ($summaryRow['cost'] ?? 0), 2),
                'profit' => round((float) ($summaryRow['profit'] ?? 0), 2),
                'unknown_cost_orders' => $unknownCostOrders,
                'profit_complete' => $unknownCostOrders === 0,
            ],
            'by_product' => $byProduct,
            'details' => $details,
            'detail_limit' => $detailLimit,
            'details_truncated' => $detailLimit > 0 && $orderCount > $detailLimit,
        ];
    }
}

if (!function_exists('commerceCenterFinancialStats')) {
    function commerceCenterFinancialStats(): array
    {
        if (!commerceCenterEnsureSchema()) return [];
        $row = commerceCenterQueryFirst(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(sale_total),0) AS gross_sales,
                    COALESCE(SUM(cost_total),0) AS total_cost,
                    COALESCE(SUM(refund_total),0) AS refunds,
                    COALESCE(SUM(net_revenue),0) AS net_revenue,
                    COALESCE(SUM(CASE WHEN profit_total IS NOT NULL THEN profit_total ELSE 0 END),0) AS known_profit,
                    SUM(CASE WHEN cost_status='unknown' AND financial_status IN ('recognized','refunded','review') THEN 1 ELSE 0 END) AS unknown_cost_orders,
                    SUM(CASE WHEN financial_status='review' THEN 1 ELSE 0 END) AS review_orders,
                    (SELECT COUNT(*) FROM commerce_ledger_entries) AS ledger_entries
             FROM commerce_order_financials"
        );
        return $row ?: ['orders' => 0, 'gross_sales' => 0, 'total_cost' => 0, 'refunds' => 0, 'net_revenue' => 0, 'known_profit' => 0, 'unknown_cost_orders' => 0, 'review_orders' => 0, 'ledger_entries' => 0];
    }
}


if (!function_exists('commerceCenterSyncStoreApiOrder')) {
    function commerceCenterSyncStoreApiOrder(int $sourceId): array
    {
        global $conn;
        if ($sourceId < 1 || !commerceCenterEnsureSchema() || !commerceCenterTableExists('store_api_orders')) return ['success' => false, 'message' => 'Store API order source is unavailable'];
        $cols = commerceCenterColumns('store_api_orders');
        $originSite = !empty($cols['origin_site_id']) ? "COALESCE(o.origin_site_id,'')" : "''";
        $originUser = !empty($cols['origin_user_id']) ? "COALESCE(o.origin_user_id,'')" : "''";
        $customerRef = !empty($cols['customer_ref']) ? "COALESCE(o.customer_ref,'')" : "''";
        $billingTransaction = !empty($cols['billing_transaction_id']) ? 'COALESCE(o.billing_transaction_id,0)' : '0';
        $billingMode = !empty($cols['billing_mode']) ? "COALESCE(o.billing_mode,'api_balance')" : "'api_balance'";
        $billingUser = !empty($cols['billing_user_id']) ? 'COALESCE(o.billing_user_id,0)' : '0';
        $balanceBefore = !empty($cols['balance_before']) ? 'o.balance_before' : 'NULL';
        $balanceAfter = !empty($cols['balance_after']) ? 'o.balance_after' : 'NULL';
        $procurementCost = !empty($cols['procurement_cost']) ? 'o.procurement_cost' : 'NULL';
        $fulfillmentSource = !empty($cols['fulfillment_source']) ? "COALESCE(o.fulfillment_source,'local')" : "'local'";
        $upstreamOrderId = !empty($cols['upstream_order_id']) ? 'COALESCE(o.upstream_order_id,0)' : '0';
        $upstreamReference = !empty($cols['upstream_reference']) ? "COALESCE(o.upstream_reference,'')" : "''";
        $row = commerceCenterQueryFirst(
            "SELECT o.*,{$originSite} AS cc_origin_site_id,{$originUser} AS cc_origin_user_id,{$customerRef} AS cc_customer_ref,
                    {$billingTransaction} AS cc_billing_transaction_id,{$billingMode} AS cc_billing_mode,{$billingUser} AS cc_billing_user_id,
                    {$balanceBefore} AS cc_balance_before,{$balanceAfter} AS cc_balance_after,{$procurementCost} AS cc_procurement_cost,
                    {$fulfillmentSource} AS cc_fulfillment_source,{$upstreamOrderId} AS cc_upstream_order_id,{$upstreamReference} AS cc_upstream_reference,
                    COALESCE(c.name,CONCAT('API client#',o.client_id)) AS client_name,COALESCE(c.currency,'THB') AS currency,
                    COALESCE(p.name,'') AS product_name,COALESCE(pv.duration,o.duration,'') AS product_duration,COALESCE(pv.cost_price,0) AS unit_cost
             FROM store_api_orders o
             LEFT JOIN store_api_clients c ON c.id=o.client_id
             LEFT JOIN products p ON p.id=o.source_product_id
             LEFT JOIN product_variants pv ON pv.id=o.source_variant_id
             WHERE o.id=? LIMIT 1",
            [$sourceId]
        );
        if (!$row) return ['success' => false, 'message' => 'Store API order not found'];
        $recordId = (string) $sourceId;
        $lock = commerceCenterAcquireLock('store_api_sale', $recordId);
        if ($lock === null) return ['success' => true, 'skipped' => true, 'message' => 'Commerce sync is already running'];
        try {
            $conn->begin_transaction();
            $originSiteId = commerceCenterText($row['cc_origin_site_id'] ?? '', 100);
            $originUserId = commerceCenterText($row['cc_origin_user_id'] ?? '', 190);
            $customerRefValue = commerceCenterText($row['cc_customer_ref'] ?? '', 255);
            if ($customerRefValue === '') $customerRefValue = commerceCenterCustomerRef($originSiteId, $originUserId);
            $ownership = ($originSiteId !== '' && $originUserId !== '') ? 'verified_external_user'
                : ((trim((string) ($row['customer_name'] ?? '')) !== '' || trim((string) ($row['customer_email'] ?? '')) !== '') ? 'customer_snapshot_only' : 'api_client_only');
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $fallbackCostTotal = round((float) ($row['unit_cost'] ?? 0) * $quantity, 2);
            $costTotal = $row['cc_procurement_cost'] === null ? $fallbackCostTotal : max(0.0, round((float) $row['cc_procurement_cost'], 2));
            $unitCostEffective = round($costTotal / $quantity, 2);
            $orderId = commerceCenterUpsertOrder([
                'source_type' => 'store_api_sale', 'source_record_id' => $recordId, 'status' => (string) $row['status'],
                'source_updated_at' => $row['updated_at'] ?? null, 'origin_site_id' => $originSiteId, 'origin_user_id' => $originUserId,
                'customer_ref' => $customerRefValue, 'local_user_id' => 0, 'commercial_buyer_type' => 'api_client',
                'commercial_buyer_id' => (string) $row['client_id'], 'buyer_name_snapshot' => (string) ($row['customer_name'] ?? ''),
                'buyer_email_snapshot' => (string) ($row['customer_email'] ?? ''), 'currency' => (string) $row['currency'],
                'subtotal' => (float) $row['total_price'], 'total' => (float) $row['total_price'],
                'cost_total' => $costTotal,
                // A linked reseller-wallet transaction is payment evidence for
                // this same Store API sale, not a second/local purchase order.
                'transaction_id' => (int) ($row['cc_billing_transaction_id'] ?? 0),
                'external_ref' => (string) $row['external_ref'], 'source_created_at' => $row['created_at'] ?? null,
                'source_completed_at' => $row['completed_at'] ?? null,
                'snapshot' => [
                    'client_id' => (int) $row['client_id'],
                    'client_name' => (string) $row['client_name'],
                    'remote_product_id' => (string) $row['remote_product_id'],
                    'billing_mode' => (string) ($row['cc_billing_mode'] ?? 'api_balance'),
                    'billing_user_id' => (int) ($row['cc_billing_user_id'] ?? 0),
                    'billing_transaction_id' => (int) ($row['cc_billing_transaction_id'] ?? 0),
                    'balance_before' => $row['cc_balance_before'] === null ? null : (float) $row['cc_balance_before'],
                    'balance_after' => $row['cc_balance_after'] === null ? null : (float) $row['cc_balance_after'],
                    'fulfillment_source' => (string) ($row['cc_fulfillment_source'] ?? 'local'),
                    'upstream_order_id' => (int) ($row['cc_upstream_order_id'] ?? 0),
                    'upstream_reference' => (string) ($row['cc_upstream_reference'] ?? ''),
                    'procurement_cost' => $costTotal,
                ],
            ]);
            if ($orderId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central Store API order'));
            $itemId = commerceCenterUpsertItem($orderId, [
                'local_product_id' => (int) $row['source_product_id'], 'local_variant_id' => (int) $row['source_variant_id'],
                'remote_product_id' => (string) $row['remote_product_id'], 'product_name_snapshot' => (string) $row['product_name'],
                'duration_snapshot' => (string) $row['product_duration'], 'quantity' => (int) $row['quantity'],
                'unit_price' => (float) $row['unit_price'], 'unit_cost' => $unitCostEffective,
                'total_price' => (float) $row['total_price'], 'total_cost' => $costTotal,
            ]);
            if ($itemId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central Store API item'));
            $keysStmt = commerceCenterExecute('SELECT id,source_type,source_key_id,source_order_id,key_code,key_hash,created_at FROM store_api_order_keys WHERE order_id=? ORDER BY id ASC', [$sourceId]);
            if (!$keysStmt) throw new RuntimeException(commerceCenterSqlFailure('Unable to read central Store API deliveries'));
            {
                $keysResult = $keysStmt->get_result();
                while ($keyRow = $keysResult ? $keysResult->fetch_assoc() : null) {
                    if (!$keyRow) break;
                    if (!commerceCenterUpsertDelivery($orderId, $itemId, [
                        'source_type' => 'store_api_order_key', 'source_key_record_id' => (string) $keyRow['id'],
                        'source_inventory_key_id' => (string) ($keyRow['source_key_id'] ?? ''), 'key_code' => (string) $keyRow['key_code'], 'key_hash' => (string) $keyRow['key_hash'],
                        'snapshot' => ['fulfillment_source' => (string) ($keyRow['source_type'] ?? 'local'), 'source_order_id' => (int) ($keyRow['source_order_id'] ?? 0)],
                        'delivered_to_site_id' => $originSiteId, 'delivered_to_user_id' => $originUserId, 'customer_ref' => $customerRefValue,
                        'delivered_name_snapshot' => (string) ($row['customer_name'] ?? ''), 'delivered_email_snapshot' => (string) ($row['customer_email'] ?? ''),
                        'ownership_status' => $ownership, 'delivered_at' => $keyRow['created_at'] ?? ($row['completed_at'] ?? null),
                    ])) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central Store API delivery'));
                }
                if ($keysResult) $keysResult->free();
                $keysStmt->close();
            }
            if (!commerceCenterFinalizeCounts($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to finalize central Store API counts'));
            if (!commerceCenterSyncOrderFinancials($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync Store API financials'));
            if (!commerceCenterSyncLinkedLedgerSourcesForOrder($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync Store API ledger sources'));
            $conn->commit();
            commerceCenterResolveFailure('store_api_sale', $recordId);
            return ['success' => true, 'order_id' => $orderId];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            commerceCenterRecordFailure('store_api_sale', $recordId, $e->getMessage());
            return ['success' => false, 'failure_recorded' => true, 'message' => $e->getMessage()];
        } finally { commerceCenterReleaseLock($lock); }
    }
}

if (!function_exists('commerceCenterSyncSupplierOrder')) {
    function commerceCenterSyncSupplierOrder(int $sourceId): array
    {
        global $conn;
        if ($sourceId < 1 || !commerceCenterEnsureSchema() || !commerceCenterTableExists('supplier_orders')) return ['success' => false, 'message' => 'Supplier order source is unavailable'];
        $row = commerceCenterQueryFirst(
            "SELECT o.*,COALESCE(u.username,'') AS username,COALESCE(u.email,'') AS email,COALESCE(sc.name,'') AS connection_name,
                    COALESCE(p.name,sp.name,'') AS product_name,COALESCE(pv.duration,sp.duration,'') AS product_duration,
                    COALESCE(sp.remote_product_id,'') AS remote_product_id
             FROM supplier_orders o
             LEFT JOIN users u ON u.id=o.user_id
             LEFT JOIN supplier_connections sc ON sc.id=o.connection_id
             LEFT JOIN supplier_products sp ON sp.id=o.supplier_product_id
             LEFT JOIN products p ON p.id=o.local_product_id
             LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
             WHERE o.id=? LIMIT 1",
            [$sourceId]
        );
        if (!$row) return ['success' => false, 'message' => 'Supplier order not found'];
        if (strtolower(trim((string)($row['source_kind'] ?? 'storefront'))) === 'store_api'
            && (int)($row['source_order_id'] ?? 0) > 0) {
            // Store API is the commercial sale/billing authority. The supplier
            // child is procurement evidence only and must not become a second
            // Commerce Center sale. Clear any stale supplier-level failure from
            // an earlier/partial run, then sync the owning Store API sale only.
            commerceCenterResolveFailure('supplier_purchase', (string) $sourceId);
            return commerceCenterSyncStoreApiOrder((int)$row['source_order_id']);
        }
        $recordId = (string) $sourceId;
        $lock = commerceCenterAcquireLock('supplier_purchase', $recordId);
        if ($lock === null) return ['success' => true, 'skipped' => true];
        try {
            $conn->begin_transaction();
            $siteId = commerceCenterSiteId();
            $userId = (string) ((int) $row['user_id']);
            $customerRef = commerceCenterCustomerRef($siteId, $userId);
            $orderId = commerceCenterUpsertOrder([
                'source_type' => 'supplier_purchase', 'source_record_id' => $recordId, 'status' => (string) $row['status'],
                'source_updated_at' => $row['updated_at'] ?? null, 'origin_site_id' => $siteId, 'origin_user_id' => $userId,
                'customer_ref' => $customerRef, 'local_user_id' => (int) $row['user_id'], 'commercial_buyer_type' => 'local_user',
                'commercial_buyer_id' => $userId, 'buyer_name_snapshot' => (string) $row['username'], 'buyer_email_snapshot' => (string) $row['email'],
                'currency' => function_exists('storeBridgeCurrency') ? storeBridgeCurrency() : 'THB', 'subtotal' => (float) $row['total_price_base'],
                'total' => (float) $row['total_price_base'], 'cost_total' => (float) $row['total_cost_base'], 'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                'external_ref' => (string) $row['external_ref'], 'source_created_at' => $row['created_at'] ?? null, 'source_completed_at' => $row['completed_at'] ?? null,
                'snapshot' => ['connection_id' => (int) $row['connection_id'], 'connection_name' => (string) $row['connection_name'], 'supplier_order_id' => (string) ($row['supplier_order_id'] ?? '')],
            ]);
            if ($orderId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central supplier order'));
            $itemId = commerceCenterUpsertItem($orderId, [
                'local_product_id' => (int) $row['local_product_id'], 'local_variant_id' => (int) $row['local_variant_id'],
                'remote_product_id' => (string) $row['remote_product_id'], 'product_name_snapshot' => (string) $row['product_name'],
                'duration_snapshot' => (string) $row['product_duration'], 'quantity' => (int) $row['quantity'],
                'unit_price' => (float) $row['unit_price_base'], 'unit_cost' => (float) $row['unit_cost_base'],
                'total_price' => (float) $row['total_price_base'], 'total_cost' => (float) $row['total_cost_base'],
            ]);
            if ($itemId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central supplier item'));
            $keysStmt = commerceCenterExecute('SELECT id,key_code,key_hash,created_at FROM supplier_order_keys WHERE order_id=? ORDER BY id ASC', [$sourceId]);
            if (!$keysStmt) throw new RuntimeException(commerceCenterSqlFailure('Unable to read central supplier deliveries'));
            {
                $result = $keysStmt->get_result();
                while ($keyRow = $result ? $result->fetch_assoc() : null) {
                    if (!$keyRow) break;
                    if (!commerceCenterUpsertDelivery($orderId, $itemId, [
                        'source_type' => 'supplier_order_key', 'source_key_record_id' => (string) $keyRow['id'], 'key_code' => (string) $keyRow['key_code'],
                        'key_hash' => (string) $keyRow['key_hash'], 'delivered_to_site_id' => $siteId, 'delivered_to_user_id' => $userId,
                        'customer_ref' => $customerRef, 'delivered_name_snapshot' => (string) $row['username'], 'delivered_email_snapshot' => (string) $row['email'],
                        'ownership_status' => 'verified_local_user', 'delivered_at' => $keyRow['created_at'] ?? ($row['completed_at'] ?? null),
                    ])) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central supplier delivery'));
                }
                if ($result) $result->free();
                $keysStmt->close();
            }
            if (!commerceCenterFinalizeCounts($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to finalize central supplier counts'));
            if (!commerceCenterSyncOrderFinancials($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync supplier financials'));
            if (!commerceCenterSyncLinkedLedgerSourcesForOrder($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync supplier ledger sources'));
            $conn->commit();
            commerceCenterResolveFailure('supplier_purchase', $recordId);
            return ['success' => true, 'order_id' => $orderId];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            commerceCenterRecordFailure('supplier_purchase', $recordId, $e->getMessage());
            return ['success' => false, 'failure_recorded' => true, 'message' => $e->getMessage()];
        } finally { commerceCenterReleaseLock($lock); }
    }
}

if (!function_exists('commerceCenterSyncCgoOrder')) {
    function commerceCenterSyncCgoOrder(int $sourceId): array
    {
        global $conn;
        if ($sourceId < 1 || !commerceCenterEnsureSchema() || !commerceCenterTableExists('cgo_orders')) return ['success' => false, 'message' => 'CGO order source is unavailable'];
        $row = commerceCenterQueryFirst(
            "SELECT o.*,COALESCE(u.username,o.customer_name,'') AS username,COALESCE(u.email,o.customer_email,'') AS email,
                    COALESCE(p.name,cp.name,'') AS product_name,COALESCE(pv.duration,cp.duration,'') AS product_duration
             FROM cgo_orders o
             LEFT JOIN users u ON u.id=o.user_id
             LEFT JOIN cgo_products cp ON cp.id=o.cgo_product_id
             LEFT JOIN products p ON p.id=o.local_product_id
             LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
             WHERE o.id=? LIMIT 1",
            [$sourceId]
        );
        if (!$row) return ['success' => false, 'message' => 'CGO order not found'];
        // CGO can act only as the procurement engine for a Store API order.
        // In that mode the Store API order is the commercial sale and this CGO
        // row must not be imported as a second sale/profit record.
        if (strtolower(trim((string) ($row['source_kind'] ?? 'storefront'))) === 'store_api' && (int) ($row['source_order_id'] ?? 0) > 0) {
            return commerceCenterSyncStoreApiOrder((int) $row['source_order_id']);
        }
        $recordId = (string) $sourceId;
        $lock = commerceCenterAcquireLock('cgo_purchase', $recordId);
        if ($lock === null) return ['success' => true, 'skipped' => true];
        try {
            $conn->begin_transaction();
            $siteId = commerceCenterSiteId();
            $userId = (string) ((int) $row['user_id']);
            $customerRef = commerceCenterCustomerRef($siteId, $userId);
            $orderId = commerceCenterUpsertOrder([
                'source_type' => 'cgo_purchase', 'source_record_id' => $recordId, 'status' => (string) $row['status'],
                'source_updated_at' => $row['updated_at'] ?? null, 'origin_site_id' => $siteId, 'origin_user_id' => $userId,
                'customer_ref' => $customerRef, 'local_user_id' => (int) $row['user_id'], 'commercial_buyer_type' => 'local_user',
                'commercial_buyer_id' => $userId, 'buyer_name_snapshot' => (string) $row['username'], 'buyer_email_snapshot' => (string) $row['email'],
                'currency' => function_exists('cgoGetBaseCurrency') ? (cgoGetBaseCurrency() ?: 'THB') : 'THB', 'subtotal' => (float) $row['total_price_base'],
                'total' => (float) $row['total_price_base'], 'cost_total' => (float) $row['total_cost_base'], 'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                'external_ref' => (string) $row['external_ref'], 'source_created_at' => $row['created_at'] ?? null, 'source_completed_at' => $row['completed_at'] ?? null,
                'snapshot' => ['cgo_product_id' => (int) $row['cgo_product_id'], 'supplier_order_id' => (string) ($row['supplier_order_id'] ?? '')],
            ]);
            if ($orderId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central CGO order'));
            $itemId = commerceCenterUpsertItem($orderId, [
                'local_product_id' => (int) ($row['local_product_id'] ?? 0), 'local_variant_id' => (int) ($row['local_variant_id'] ?? 0),
                'remote_product_id' => (string) $row['remote_product_id'], 'product_name_snapshot' => (string) $row['product_name'],
                'duration_snapshot' => (string) $row['product_duration'], 'quantity' => (int) $row['quantity'],
                'unit_price' => (float) $row['unit_price_base'], 'unit_cost' => (float) $row['unit_cost_base'],
                'total_price' => (float) $row['total_price_base'], 'total_cost' => (float) $row['total_cost_base'],
            ]);
            if ($itemId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central CGO item'));
            $keysStmt = commerceCenterExecute('SELECT id,key_code,key_hash,created_at FROM cgo_order_keys WHERE order_id=? ORDER BY id ASC', [$sourceId]);
            if (!$keysStmt) throw new RuntimeException(commerceCenterSqlFailure('Unable to read central CGO deliveries'));
            {
                $result = $keysStmt->get_result();
                while ($keyRow = $result ? $result->fetch_assoc() : null) {
                    if (!$keyRow) break;
                    if (!commerceCenterUpsertDelivery($orderId, $itemId, [
                        'source_type' => 'cgo_order_key', 'source_key_record_id' => (string) $keyRow['id'], 'key_code' => (string) $keyRow['key_code'],
                        'key_hash' => (string) $keyRow['key_hash'], 'delivered_to_site_id' => $siteId, 'delivered_to_user_id' => $userId,
                        'customer_ref' => $customerRef, 'delivered_name_snapshot' => (string) $row['username'], 'delivered_email_snapshot' => (string) $row['email'],
                        'ownership_status' => 'verified_local_user', 'delivered_at' => $keyRow['created_at'] ?? ($row['completed_at'] ?? null),
                    ])) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central CGO delivery'));
                }
                if ($result) $result->free();
                $keysStmt->close();
            }
            if (!commerceCenterFinalizeCounts($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to finalize central CGO counts'));
            if (!commerceCenterSyncOrderFinancials($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync CGO financials'));
            if (!commerceCenterSyncLinkedLedgerSourcesForOrder($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync CGO ledger sources'));
            $conn->commit();
            commerceCenterResolveFailure('cgo_purchase', $recordId);
            return ['success' => true, 'order_id' => $orderId];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            commerceCenterRecordFailure('cgo_purchase', $recordId, $e->getMessage());
            return ['success' => false, 'failure_recorded' => true, 'message' => $e->getMessage()];
        } finally { commerceCenterReleaseLock($lock); }
    }
}

if (!function_exists('commerceCenterSyncLocalTransaction')) {
    function commerceCenterSyncLocalTransaction(int $transactionId): array
    {
        global $conn;
        if ($transactionId < 1 || !commerceCenterEnsureSchema() || !commerceCenterTableExists('transactions')) return ['success' => false, 'message' => 'Local transaction source is unavailable'];
        $row = commerceCenterQueryFirst(
            "SELECT t.*,k.id AS key_id,k.key_code,k.product_id,k.variant_id,k.duration AS key_duration,k.sold_at,
                    COALESCE(u.username,'') AS username,COALESCE(u.email,'') AS email,COALESCE(p.name,'') AS product_name,
                    COALESCE(pv.duration,k.duration,'') AS product_duration,COALESCE(k.cost_price,pv.cost_price,0) AS unit_cost
             FROM transactions t
             JOIN `keys` k ON k.id=t.reference_id
             LEFT JOIN users u ON u.id=t.user_id
             LEFT JOIN products p ON p.id=k.product_id
             LEFT JOIN product_variants pv ON pv.id=k.variant_id
             WHERE t.id=? AND LOWER(TRIM(t.type))='purchase' LIMIT 1",
            [$transactionId]
        );
        if (!$row) return ['success' => false, 'message' => 'Local purchase transaction not found'];
        $recordId = (string) $transactionId;
        $lock = commerceCenterAcquireLock('local_purchase', $recordId);
        if ($lock === null) return ['success' => true, 'skipped' => true];
        try {
            $conn->begin_transaction();
            $siteId = commerceCenterSiteId();
            $userId = (string) ((int) $row['user_id']);
            $customerRef = commerceCenterCustomerRef($siteId, $userId);
            $amount = (float) $row['amount'];
            $orderId = commerceCenterUpsertOrder([
                'source_type' => 'local_purchase', 'source_record_id' => $recordId, 'status' => (string) $row['status'],
                'source_updated_at' => $row['created_at'] ?? null, 'origin_site_id' => $siteId, 'origin_user_id' => $userId,
                'customer_ref' => $customerRef, 'local_user_id' => (int) $row['user_id'], 'commercial_buyer_type' => 'local_user',
                'commercial_buyer_id' => $userId, 'buyer_name_snapshot' => (string) $row['username'], 'buyer_email_snapshot' => (string) $row['email'],
                'currency' => function_exists('getSetting') ? strtoupper((string) (getSetting('currency_name', 'THB') ?: 'THB')) : 'THB', 'subtotal' => $amount, 'total' => $amount,
                'cost_total' => (float) $row['unit_cost'], 'transaction_id' => $transactionId, 'external_ref' => 'TX-' . $transactionId,
                'source_created_at' => $row['created_at'] ?? null, 'source_completed_at' => $row['created_at'] ?? null,
                'snapshot' => ['key_id' => (int) $row['key_id'], 'description' => (string) ($row['description'] ?? '')],
            ]);
            if ($orderId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central local order'));
            $itemId = commerceCenterUpsertItem($orderId, [
                'local_product_id' => (int) $row['product_id'], 'local_variant_id' => (int) ($row['variant_id'] ?? 0),
                'remote_product_id' => '', 'product_name_snapshot' => (string) $row['product_name'], 'duration_snapshot' => (string) $row['product_duration'],
                'quantity' => 1, 'unit_price' => $amount, 'unit_cost' => (float) $row['unit_cost'], 'total_price' => $amount, 'total_cost' => (float) $row['unit_cost'],
            ]);
            if ($itemId < 1) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central local item'));
            if (!commerceCenterUpsertDelivery($orderId, $itemId, [
                'source_type' => 'local_key', 'source_key_record_id' => (string) $row['key_id'], 'source_inventory_key_id' => (string) $row['key_id'], 'key_code' => (string) $row['key_code'],
                'delivered_to_site_id' => $siteId, 'delivered_to_user_id' => $userId, 'customer_ref' => $customerRef,
                'delivered_name_snapshot' => (string) $row['username'], 'delivered_email_snapshot' => (string) $row['email'],
                'ownership_status' => 'verified_local_user', 'delivered_at' => $row['sold_at'] ?? ($row['created_at'] ?? null),
            ])) throw new RuntimeException(commerceCenterSqlFailure('Unable to upsert central local delivery'));
            if (!commerceCenterFinalizeCounts($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to finalize central local counts'));
            if (!commerceCenterSyncOrderFinancials($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync local financials'));
            if (!commerceCenterSyncLinkedLedgerSourcesForOrder($orderId)) throw new RuntimeException(commerceCenterSqlFailure('Unable to sync local ledger sources'));
            $conn->commit();
            commerceCenterResolveFailure('local_purchase', $recordId);
            return ['success' => true, 'order_id' => $orderId];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            commerceCenterRecordFailure('local_purchase', $recordId, $e->getMessage());
            return ['success' => false, 'failure_recorded' => true, 'message' => $e->getMessage()];
        } finally { commerceCenterReleaseLock($lock); }
    }
}

if (!function_exists('commerceCenterSyncSafe')) {
    function commerceCenterSyncSafe(string $sourceType, int $sourceId): array
    {
        try {
            if ($sourceType === 'store_api_sale') $result = commerceCenterSyncStoreApiOrder($sourceId);
            elseif ($sourceType === 'supplier_purchase') $result = commerceCenterSyncSupplierOrder($sourceId);
            elseif ($sourceType === 'cgo_purchase') $result = commerceCenterSyncCgoOrder($sourceId);
            elseif ($sourceType === 'local_purchase') $result = commerceCenterSyncLocalTransaction($sourceId);
            else $result = ['success' => false, 'message' => 'Unsupported commerce source'];
            if (empty($result['success']) && empty($result['failure_recorded'])) {
                commerceCenterRecordFailure($sourceType, (string) $sourceId, (string) ($result['message'] ?? 'Commerce sync failed'));
            }
            return $result;
        } catch (Throwable $e) {
            commerceCenterRecordFailure($sourceType, (string) $sourceId, $e->getMessage());
            error_log('Commerce Center safe sync failed: ' . $sourceType . '#' . $sourceId . '; ' . $e->getMessage());
            return ['success' => false, 'failure_recorded' => true, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('commerceCenterReconcile')) {
    function commerceCenterReconcile(int $limit = 80): array
    {
        global $conn;
        $limit = max(4, min(400, $limit));
        if (!commerceCenterEnsureSchema()) return ['success' => false, 'message' => 'Commerce Center schema is unavailable'];
        $perSource = max(1, intdiv($limit, 4));
        $summary = ['success' => true, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'source_query_failures' => 0, 'sources' => []];
        $siteId = commerceCenterSiteId();
        $definitions = [
            'store_api_sale' => [
                'table' => 'store_api_orders',
                'sql' => "SELECT s.id FROM store_api_orders s LEFT JOIN commerce_orders c ON c.source_site_id=? AND c.source_type='store_api_sale' AND c.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          WHERE c.id IS NULL OR c.source_updated_at IS NULL OR s.updated_at>c.source_updated_at
                             OR c.delivery_count<(SELECT COUNT(*) FROM store_api_order_keys k WHERE k.order_id=s.id)
                             OR EXISTS (SELECT 1 FROM commerce_sync_failures f WHERE f.source_site_id=? AND f.source_type='store_api_sale' AND f.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND f.resolved_at IS NULL)
                          ORDER BY s.updated_at ASC,s.id ASC LIMIT {$perSource}",
            ],
            'supplier_purchase' => [
                'table' => 'supplier_orders',
                'sql' => "SELECT s.id FROM supplier_orders s LEFT JOIN commerce_orders c ON c.source_site_id=? AND c.source_type='supplier_purchase' AND c.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          WHERE NOT (LOWER(TRIM(COALESCE(s.source_kind,'storefront')))='store_api' AND COALESCE(s.source_order_id,0)>0)
                            AND (c.id IS NULL OR c.source_updated_at IS NULL OR s.updated_at>c.source_updated_at
                             OR c.delivery_count<(SELECT COUNT(*) FROM supplier_order_keys k WHERE k.order_id=s.id)
                             OR EXISTS (SELECT 1 FROM commerce_sync_failures f WHERE f.source_site_id=? AND f.source_type='supplier_purchase' AND f.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND f.resolved_at IS NULL))
                          ORDER BY s.updated_at ASC,s.id ASC LIMIT {$perSource}",
            ],
            'cgo_purchase' => [
                'table' => 'cgo_orders',
                'sql' => "SELECT s.id FROM cgo_orders s LEFT JOIN commerce_orders c ON c.source_site_id=? AND c.source_type='cgo_purchase' AND c.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          WHERE NOT (LOWER(TRIM(COALESCE(s.source_kind,'storefront')))='store_api' AND COALESCE(s.source_order_id,0)>0)
                            AND (c.id IS NULL OR c.source_updated_at IS NULL OR s.updated_at>c.source_updated_at
                             OR c.delivery_count<(SELECT COUNT(*) FROM cgo_order_keys k WHERE k.order_id=s.id)
                             OR EXISTS (SELECT 1 FROM commerce_sync_failures f WHERE f.source_site_id=? AND f.source_type='cgo_purchase' AND f.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND f.resolved_at IS NULL))
                          ORDER BY s.updated_at ASC,s.id ASC LIMIT {$perSource}",
            ],
            'local_purchase' => [
                'table' => 'transactions',
                'sql' => "SELECT s.id FROM transactions s
                          LEFT JOIN commerce_orders c ON c.source_site_id=? AND c.source_type='local_purchase' AND c.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                          LEFT JOIN commerce_order_financials cf ON cf.order_id=c.id
                          WHERE LOWER(TRIM(s.type))='purchase' AND LOWER(TRIM(s.status))='completed' AND (c.id IS NULL OR cf.id IS NULL OR cf.calculation_version<>'1.1'
                             OR EXISTS (SELECT 1 FROM commerce_sync_failures f WHERE f.source_site_id=? AND f.source_type='local_purchase' AND f.source_record_id=CONVERT(CAST(s.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AND f.resolved_at IS NULL))
                          ORDER BY s.id DESC LIMIT {$perSource}",
            ],
        ];
        foreach ($definitions as $sourceType => $definition) {
            if (!commerceCenterTableExists((string) $definition['table'])) continue;
            $stmt = commerceCenterExecute((string) $definition['sql'], [$siteId, $siteId]);
            if (!$stmt) {
                $summary['failed']++;
                $summary['source_query_failures']++;
                $summary['sources'][$sourceType] = [
                    'attempted' => 0,
                    'succeeded' => 0,
                    'failed' => 1,
                    'error_code' => 'source_query_failed',
                    'error_detail' => commerceCenterSqlFailure('Unable to enumerate commerce source'),
                ];
                continue;
            }
            $result = $stmt->get_result();
            $ids = [];
            while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
            if ($result) $result->free();
            $stmt->close();
            $sourceSummary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];
            foreach ($ids as $id) {
                $sourceSummary['attempted']++;
                $summary['attempted']++;
                $sync = commerceCenterSyncSafe($sourceType, $id);
                if (!empty($sync['success'])) { $sourceSummary['succeeded']++; $summary['succeeded']++; }
                else { $sourceSummary['failed']++; $summary['failed']++; }
            }
            $summary['sources'][$sourceType] = $sourceSummary;
        }
        $summary['source_attempted'] = (int) $summary['attempted'];
        $summary['source_succeeded'] = (int) $summary['succeeded'];
        $summary['source_failed'] = (int) $summary['failed'];
        $ledgerSummary = commerceCenterReconcileLedger(max(24, $limit));
        $summary['ledger'] = $ledgerSummary;
        $summary['attempted'] += (int) ($ledgerSummary['attempted'] ?? 0);
        $summary['succeeded'] += (int) ($ledgerSummary['succeeded'] ?? 0);
        $summary['failed'] += (int) ($ledgerSummary['failed'] ?? 0);
        $summary['success'] = $summary['failed'] === 0;
        $summary['message'] = 'sources attempted=' . $summary['source_attempted']
            . ', succeeded=' . $summary['source_succeeded']
            . ', failed=' . $summary['source_failed']
            . ', query_failures=' . $summary['source_query_failures']
            . '; ' . (string) ($ledgerSummary['message'] ?? '')
            . '; total_failures=' . $summary['failed'];
        return $summary;
    }
}

if (!function_exists('commerceCenterResolveKeyHash')) {
    function commerceCenterResolveKeyHash(string $keyHash): ?array
    {
        $keyHash = strtolower(trim($keyHash));
        if (!preg_match('/^[a-f0-9]{64}$/D', $keyHash) || !commerceCenterEnsureSchema()) return null;
        return commerceCenterQueryFirst(
            "SELECT d.*,o.order_uuid,o.source_type AS order_source_type,o.source_record_id,o.status AS order_status,o.origin_site_id,o.origin_user_id,
                    o.customer_ref AS order_customer_ref,o.local_user_id,o.commercial_buyer_type,o.commercial_buyer_id,o.buyer_name_snapshot,o.buyer_email_snapshot,
                    o.currency,o.total,o.cost_total,o.transaction_id,o.external_ref,o.source_created_at,o.source_completed_at,
                    i.product_name_snapshot,i.duration_snapshot,i.local_product_id,i.local_variant_id,
                    (SELECT COUNT(*) FROM commerce_deliveries dx WHERE dx.key_hash=d.key_hash) AS central_match_count
             FROM commerce_deliveries d
             JOIN commerce_orders o ON o.id=d.order_id
             LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
             WHERE d.key_hash=? ORDER BY COALESCE(d.delivered_at,o.source_completed_at,o.source_created_at) DESC,d.id DESC LIMIT 1",
            [$keyHash]
        );
    }
}

if (!function_exists('commerceCenterListOrders')) {
    function commerceCenterListOrders(array $filters = [], int $limit = 100): array
    {
        global $conn;
        if (!commerceCenterEnsureSchema()) return [];
        $limit = max(1, min(500, $limit));
        $where = ['1=1'];
        $params = [];
        $source = commerceCenterText($filters['source_type'] ?? '', 40);
        $status = commerceCenterText($filters['status'] ?? '', 40);
        $search = commerceCenterText($filters['search'] ?? '', 190);
        if ($source !== '') { $where[] = 'o.source_type=?'; $params[] = $source; }
        if ($status !== '') { $where[] = 'o.status=?'; $params[] = $status; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(o.order_uuid LIKE ? OR o.external_ref LIKE ? OR o.buyer_name_snapshot LIKE ? OR o.customer_ref LIKE ? OR i.product_name_snapshot LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = "SELECT o.*,i.product_name_snapshot,i.duration_snapshot,i.quantity,i.unit_price,i.unit_cost
                FROM commerce_orders o LEFT JOIN commerce_order_items i ON i.order_id=o.id AND i.item_no=1
                WHERE " . implode(' AND ', $where) . " ORDER BY o.id DESC LIMIT {$limit}";
        $stmt = commerceCenterExecute($sql, $params);
        if (!$stmt) return [];
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) $result->free();
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('commerceCenterStats')) {
    function commerceCenterStats(): array
    {
        if (!commerceCenterEnsureSchema()) return ['orders' => 0, 'deliveries' => 0, 'failures' => 0];
        $row = commerceCenterQueryFirst(
            "SELECT (SELECT COUNT(*) FROM commerce_orders) AS orders,
                    (SELECT COUNT(*) FROM commerce_deliveries) AS deliveries,
                    (SELECT COUNT(*) FROM commerce_sync_failures WHERE resolved_at IS NULL) AS failures,
                    (SELECT COALESCE(SUM(total),0) FROM commerce_orders WHERE LOWER(status) IN ('success','completed')) AS total_sales,
                    (SELECT COUNT(*) FROM commerce_ledger_entries) AS ledger_entries,
                    (SELECT COALESCE(SUM(net_revenue),0) FROM commerce_order_financials) AS net_revenue,
                    (SELECT COALESCE(SUM(CASE WHEN profit_total IS NOT NULL THEN profit_total ELSE 0 END),0) FROM commerce_order_financials) AS known_profit,
                    (SELECT COUNT(*) FROM commerce_order_financials WHERE cost_status='unknown' AND financial_status IN ('recognized','refunded','review')) AS unknown_cost_orders"
        );
        return $row ?: ['orders' => 0, 'deliveries' => 0, 'failures' => 0, 'total_sales' => 0, 'ledger_entries' => 0, 'net_revenue' => 0, 'known_profit' => 0, 'unknown_cost_orders' => 0];
    }
}
