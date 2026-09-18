<?php
/**
 * Transaction type integrity and maintenance helpers.
 *
 * This file deliberately separates read-only inference from destructive repair.
 * Storefront requests may use the read-only helpers, while schema/data repair is
 * available only through the explicit admin maintenance page.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('transactionIntegrityKnownTypes')) {
    /** @return string[] */
    function transactionIntegrityKnownTypes(): array
    {
        return [
            'purchase',
            'cgo_purchase',
            'supplier_purchase',
            'store_api_purchase',
            'deposit',
            'redeem_code',
            'manual_add',
            'manual_deduct',
            'rank_bonus',
        ];
    }
}

if (!function_exists('transactionIntegrityNormalizeType')) {
    function transactionIntegrityNormalizeType($type): string
    {
        return strtolower(trim((string) $type));
    }
}

if (!function_exists('transactionIntegrityTableColumns')) {
    /** @return array<string,array<string,mixed>> */
    function transactionIntegrityTableColumns(string $table): array
    {
        global $conn;
        static $cache = [];
        $allowed = [
            'transactions', 'keys', 'cgo_orders', 'supplier_orders', 'store_api_orders',
            'transaction_type_repair_batches', 'transaction_type_repair_log',
        ];
        if (!in_array($table, $allowed, true)) return [];
        if (array_key_exists($table, $cache)) return $cache[$table];

        $columns = [];
        try {
            $result = $conn->query("SHOW FULL COLUMNS FROM `{$table}`");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $name = strtolower(trim((string) ($row['Field'] ?? '')));
                    if ($name !== '') $columns[$name] = $row;
                }
                $result->free();
            }
        } catch (Throwable $e) {
            error_log('Transaction integrity column inspection failed for ' . $table . ': ' . $e->getMessage());
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('transactionIntegrityResetSchemaCache')) {
    function transactionIntegrityResetSchemaCache(): void
    {
        // The column helper intentionally uses a static cache. A request that
        // performs ALTER TABLE must verify using a direct SHOW query instead of
        // trying to mutate that cache from the outside.
        unset($GLOBALS['__transaction_type_column_info']);
    }
}

if (!function_exists('transactionIntegrityTypeColumnInfo')) {
    /** @return array<string,mixed> */
    function transactionIntegrityTypeColumnInfo(bool $refresh = false): array
    {
        global $conn;
        if (!$refresh && isset($GLOBALS['__transaction_type_column_info']) && is_array($GLOBALS['__transaction_type_column_info'])) {
            return $GLOBALS['__transaction_type_column_info'];
        }

        $info = [];
        try {
            $result = $conn->query("SHOW FULL COLUMNS FROM transactions LIKE 'type'");
            if ($result) {
                $row = $result->fetch_assoc();
                if (is_array($row)) $info = $row;
                $result->free();
            }
        } catch (Throwable $e) {
            error_log('Transaction type column inspection failed: ' . $e->getMessage());
        }
        return $GLOBALS['__transaction_type_column_info'] = $info;
    }
}

if (!function_exists('transactionIntegrityTypeColumnSupports')) {
    function transactionIntegrityTypeColumnSupports(string $wantedType, ?array $columnInfo = null): bool
    {
        $wantedType = transactionIntegrityNormalizeType($wantedType);
        if ($wantedType === '') return false;
        $columnInfo = $columnInfo ?? transactionIntegrityTypeColumnInfo();
        $columnType = strtolower(trim((string) ($columnInfo['Type'] ?? '')));
        if ($columnType === '') return false;

        if (preg_match('/^(?:var)?char\((\d+)\)/i', $columnType, $matches) === 1) {
            return (int) $matches[1] >= strlen($wantedType);
        }
        if (preg_match('/^(?:tiny|medium|long)?text\b/i', $columnType) === 1) return true;
        if (strpos($columnType, 'enum(') === 0 || strpos($columnType, 'set(') === 0) {
            $escaped = preg_quote($wantedType, '/');
            return preg_match("/(?:^|\\(|,)\\s*'{$escaped}'\\s*(?:,|\\))/i", $columnType) === 1;
        }
        return false;
    }
}

if (!function_exists('transactionIntegrityTypeColumnReady')) {
    function transactionIntegrityTypeColumnReady(?array $columnInfo = null): bool
    {
        $columnInfo = $columnInfo ?? transactionIntegrityTypeColumnInfo();
        foreach (transactionIntegrityKnownTypes() as $type) {
            if (!transactionIntegrityTypeColumnSupports($type, $columnInfo)) return false;
        }
        return true;
    }
}

if (!function_exists('transactionIntegrityDescriptionType')) {
    function transactionIntegrityDescriptionType(string $description): string
    {
        $description = trim($description);
        if ($description === '') return '';

        if (stripos($description, 'Redeemed top-up code ') === 0) return 'redeem_code';
        if (stripos($description, 'Purchased ') === 0 && stripos($description, ' key: ') !== false) return 'purchase';
        if (stripos($description, 'Store API reseller wallet order ') === 0) return 'store_api_purchase';
        if (stripos($description, 'Slip verification') === 0) return 'deposit';
        if (stripos($description, 'TrueMoney Angpao gross ') === 0) return 'deposit';
        if (stripos($description, 'Binance Gift Card:') === 0) return 'deposit';
        if (stripos($description, 'Binance USDT deposit:') === 0) return 'deposit';
        if (strcasecmp($description, 'Opening balance added by admin') === 0) return 'manual_add';
        if (strcasecmp($description, 'Balance added by admin') === 0) return 'manual_add';
        if (strcasecmp($description, 'Balance deducted by admin') === 0) return 'manual_deduct';
        if (stripos($description, 'Monthly rank bonus ') === 0) return 'rank_bonus';
        return '';
    }
}

if (!function_exists('transactionIntegrityEffectiveType')) {
    /**
     * Read-only inference used by history pages. Authoritative order links win;
     * otherwise a valid stored type wins, followed by strong description/key
     * evidence. It never writes to the database.
     */
    function transactionIntegrityEffectiveType(array $row): string
    {
        $apiSource = transactionIntegrityNormalizeType($row['api_source'] ?? $row['_api_source'] ?? '');
        if ($apiSource === 'cgo') return 'cgo_purchase';
        if ($apiSource === 'supplier') return 'supplier_purchase';

        $stored = transactionIntegrityNormalizeType($row['type'] ?? $row['tx_type'] ?? '');
        if (in_array($stored, transactionIntegrityKnownTypes(), true)) return $stored;

        $descriptionType = transactionIntegrityDescriptionType((string) ($row['description'] ?? ''));
        if ($descriptionType !== '') return $descriptionType;

        if (!empty($row['key_code']) || !empty($row['product_id'])) return 'purchase';
        return $stored;
    }
}

if (!function_exists('transactionIntegrityInferenceCaseSql')) {
    function transactionIntegrityInferenceCaseSql(string $alias = 't'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid transaction SQL alias');
        }

        $cgoCols = transactionIntegrityTableColumns('cgo_orders');
        $supplierCols = transactionIntegrityTableColumns('supplier_orders');
        $storeApiCols = transactionIntegrityTableColumns('store_api_orders');
        $keyCols = transactionIntegrityTableColumns('keys');
        $parts = ['CASE'];

        if (!empty($cgoCols['transaction_id'])) {
            $parts[] = "WHEN EXISTS (SELECT 1 FROM cgo_orders cio WHERE cio.transaction_id={$alias}.id) THEN 'cgo_purchase'";
        }
        if (!empty($supplierCols['transaction_id'])) {
            $parts[] = "WHEN EXISTS (SELECT 1 FROM supplier_orders sio WHERE sio.transaction_id={$alias}.id) THEN 'supplier_purchase'";
        }
        if (!empty($storeApiCols['billing_transaction_id'])) {
            $parts[] = "WHEN EXISTS (SELECT 1 FROM store_api_orders sao WHERE sao.billing_transaction_id={$alias}.id) THEN 'store_api_purchase'";
        }
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'redeemed top-up code %' THEN 'redeem_code'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'slip verification%' THEN 'deposit'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'truemoney angpao gross %' THEN 'deposit'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'binance gift card:%' THEN 'deposit'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'binance usdt deposit:%' THEN 'deposit'";
        $parts[] = "WHEN LOWER(TRIM(COALESCE({$alias}.description,''))) IN ('opening balance added by admin','balance added by admin') THEN 'manual_add'";
        $parts[] = "WHEN LOWER(TRIM(COALESCE({$alias}.description,'')))='balance deducted by admin' THEN 'manual_deduct'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'monthly rank bonus %' THEN 'rank_bonus'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'store api reseller wallet order %' THEN 'store_api_purchase'";
        $parts[] = "WHEN LOWER(COALESCE({$alias}.description,'')) LIKE 'purchased % key: %' THEN 'purchase'";
        if (!empty($keyCols['id'])) {
            // Reference IDs are not globally namespaced. Use this weakest
            // fallback only after every strongly identifying description.
            $parts[] = "WHEN COALESCE({$alias}.reference_id,0)>0 AND EXISTS (SELECT 1 FROM `keys` tik WHERE tik.id={$alias}.reference_id) THEN 'purchase'";
        }
        $parts[] = "ELSE '' END";
        return implode(' ', $parts);
    }
}

if (!function_exists('transactionIntegritySchemaSnapshot')) {
    /** @return array<string,mixed> */
    function transactionIntegritySchemaSnapshot(): array
    {
        global $conn;
        $triggers = [];
        try {
            $result = $conn->query('SHOW TRIGGERS');
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (strcasecmp((string) ($row['Table'] ?? ''), 'transactions') !== 0) continue;
                    $triggers[] = [
                        'trigger' => (string) ($row['Trigger'] ?? ''),
                        'event' => (string) ($row['Event'] ?? ''),
                        'timing' => (string) ($row['Timing'] ?? ''),
                        'statement_hash' => hash('sha256', (string) ($row['Statement'] ?? '')),
                    ];
                }
                $result->free();
            }
        } catch (Throwable $e) {
            error_log('Transaction trigger inspection failed: ' . $e->getMessage());
        }

        return [
            'type_column' => transactionIntegrityTypeColumnInfo(true),
            'triggers' => $triggers,
        ];
    }
}

if (!function_exists('transactionIntegrityRecentActiveOrders')) {
    /** @return array{count:int,sources:array<string,int>,warnings:string[]} */
    function transactionIntegrityRecentActiveOrders(int $minutes = 5): array
    {
        global $conn;
        $minutes = max(1, min(60, $minutes));
        $sources = [];
        $warnings = [];
        foreach (['cgo_orders', 'supplier_orders', 'store_api_orders'] as $table) {
            $columns = transactionIntegrityTableColumns($table);
            if (empty($columns['status'])) continue;
            $timeColumn = '';
            foreach (['updated_at', 'created_at', 'requested_at'] as $candidate) {
                if (!empty($columns[$candidate])) { $timeColumn = $candidate; break; }
            }
            if ($timeColumn === '') {
                $warnings[] = $table . ': no timestamp column for active-order safety check';
                continue;
            }
            try {
                $sql = "SELECT COUNT(*) AS c FROM `{$table}` WHERE LOWER(TRIM(COALESCE(status,''))) IN ('pending','processing','submitting') AND `{$timeColumn}` >= (NOW() - INTERVAL {$minutes} MINUTE)";
                $result = $conn->query($sql);
                $row = $result ? $result->fetch_assoc() : null;
                if ($result) $result->free();
                $sources[$table] = (int) ($row['c'] ?? 0);
            } catch (Throwable $e) {
                $warnings[] = $table . ': ' . $e->getMessage();
            }
        }
        return ['count' => array_sum($sources), 'sources' => $sources, 'warnings' => $warnings];
    }
}

if (!function_exists('transactionIntegrityPreview')) {
    /** @return array<string,mixed> */
    function transactionIntegrityPreview(int $sampleLimit = 50): array
    {
        global $conn;
        $sampleLimit = max(1, min(200, $sampleLimit));
        $column = transactionIntegrityTypeColumnInfo(true);
        $case = transactionIntegrityInferenceCaseSql('t');
        $knownSql = "'" . implode("','", array_map([$conn, 'real_escape_string'], transactionIntegrityKnownTypes())) . "'";
        $candidateWhere = "({$case})<>'' AND LOWER(TRIM(COALESCE(t.type,'')))<>({$case})";

        $summary = [];
        $samples = [];
        $unknownCount = 0;
        $blankCount = 0;
        $maxTransactionId = 0;
        $candidateFingerprint = '';
        $queryError = '';
        try {
            $countSql = "SELECT inferred_type,COUNT(*) AS total FROM (SELECT {$case} AS inferred_type,t.type FROM transactions t) q WHERE inferred_type<>'' AND LOWER(TRIM(COALESCE(type,'')))<>inferred_type GROUP BY inferred_type ORDER BY inferred_type";
            $result = $conn->query($countSql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $summary[(string) ($row['inferred_type'] ?? '')] = (int) ($row['total'] ?? 0);
                }
                $result->free();
            }

            $sampleSql = "SELECT t.id,t.user_id,COALESCE(t.type,'') AS old_type,{$case} AS inferred_type,COALESCE(t.amount,0) AS amount,COALESCE(t.status,'') AS status,LEFT(COALESCE(t.description,''),240) AS description,COALESCE(t.reference_id,0) AS reference_id,COALESCE(t.created_at,'') AS created_at FROM transactions t WHERE {$candidateWhere} ORDER BY t.id DESC LIMIT {$sampleLimit}";
            $result = $conn->query($sampleSql);
            if ($result) {
                $samples = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
            }

            $result = $conn->query("SELECT COUNT(*) AS c FROM transactions WHERE TRIM(COALESCE(type,''))=''");
            if ($result && ($row = $result->fetch_assoc())) $blankCount = (int) ($row['c'] ?? 0);
            if ($result) $result->free();

            $result = $conn->query("SELECT COUNT(*) AS c FROM transactions WHERE TRIM(COALESCE(type,''))<>'' AND LOWER(TRIM(type)) NOT IN ({$knownSql})");
            if ($result && ($row = $result->fetch_assoc())) $unknownCount = (int) ($row['c'] ?? 0);
            if ($result) $result->free();

            $fingerprintSql = "SELECT COALESCE(MAX(t.id),0) AS max_id,COALESCE(SUM(CASE WHEN ({$case})<>'' AND LOWER(TRIM(COALESCE(t.type,'')))<>({$case}) THEN CRC32(CONCAT_WS('|',t.id,COALESCE(t.type,''),({$case}))) ELSE 0 END),0) AS fp FROM transactions t";
            $result = $conn->query($fingerprintSql);
            if ($result && ($row = $result->fetch_assoc())) {
                $maxTransactionId = (int) ($row['max_id'] ?? 0);
                $candidateFingerprint = (string) ($row['fp'] ?? '0');
            }
            if ($result) $result->free();
        } catch (Throwable $e) {
            $queryError = $e->getMessage();
            error_log('Transaction integrity preview failed: ' . $e->getMessage());
        }

        $activeOrders = transactionIntegrityRecentActiveOrders(5);
        return [
            'checked_at' => date('Y-m-d H:i:s'),
            'column' => $column,
            'recent_active_orders' => $activeOrders,
            'schema_ready' => transactionIntegrityTypeColumnReady($column),
            'blank_type_count' => $blankCount,
            'unknown_type_count' => $unknownCount,
            'max_transaction_id' => $maxTransactionId,
            'candidate_fingerprint' => $candidateFingerprint,
            'repairable_by_type' => $summary,
            'repairable_count' => array_sum($summary),
            'samples' => $samples,
            'query_error' => $queryError,
            'preview_hash' => hash('sha256', json_encode([$column, $summary, $blankCount, $unknownCount, $maxTransactionId, $candidateFingerprint], JSON_UNESCAPED_SLASHES)),
        ];
    }
}

if (!function_exists('transactionIntegrityEnsureRepairTables')) {
    function transactionIntegrityEnsureRepairTables(): bool
    {
        global $conn;
        $batchSql = "CREATE TABLE IF NOT EXISTS transaction_type_repair_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            admin_user_id INT UNSIGNED NULL,
            schema_before LONGTEXT NULL,
            schema_after LONGTEXT NULL,
            candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
            repaired_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'started',
            error_message VARCHAR(1000) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transaction_type_batch (batch_id),
            KEY idx_transaction_type_batch_status (status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $logSql = "CREATE TABLE IF NOT EXISTS transaction_type_repair_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            transaction_id BIGINT UNSIGNED NOT NULL,
            old_type VARCHAR(100) NOT NULL DEFAULT '',
            new_type VARCHAR(50) NOT NULL,
            reason VARCHAR(80) NOT NULL,
            description_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transaction_type_repair_row (batch_id,transaction_id),
            KEY idx_transaction_type_repair_tx (transaction_id),
            KEY idx_transaction_type_repair_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            return (bool) $conn->query($batchSql) && (bool) $conn->query($logSql);
        } catch (Throwable $e) {
            error_log('Transaction repair table setup failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('transactionIntegrityUuid')) {
    function transactionIntegrityUuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-' . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3)
            . '-' . substr($hex, 20, 12);
    }
}

if (!function_exists('transactionIntegrityAcquireLock')) {
    function transactionIntegrityAcquireLock(int $timeoutSeconds = 8): bool
    {
        global $conn;
        $timeoutSeconds = max(0, min(30, $timeoutSeconds));
        try {
            $result = $conn->query("SELECT GET_LOCK('sakazuki_transaction_type_repair',{$timeoutSeconds}) AS acquired");
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) $result->free();
            return (int) ($row['acquired'] ?? 0) === 1;
        } catch (Throwable $e) {
            error_log('Transaction repair lock failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('transactionIntegrityReleaseLock')) {
    function transactionIntegrityReleaseLock(): void
    {
        global $conn;
        try { $conn->query("SELECT RELEASE_LOCK('sakazuki_transaction_type_repair')"); } catch (Throwable $ignored) {}
    }
}

if (!function_exists('transactionIntegrityAlterTypeColumn')) {
    /** @return array{changed:bool,before:array,after:array} */
    function transactionIntegrityAlterTypeColumn(): array
    {
        global $conn;
        $before = transactionIntegrityTypeColumnInfo(true);
        if (!$before) throw new RuntimeException('transactions.type column was not found');
        if (transactionIntegrityTypeColumnReady($before)) {
            return ['changed' => false, 'before' => $before, 'after' => $before];
        }

        $nullable = strtoupper((string) ($before['Null'] ?? 'NO')) === 'YES';
        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        $defaultSql = '';
        if ($nullable && !array_key_exists('Default', $before)) {
            $defaultSql = ' DEFAULT NULL';
        } elseif ($nullable && ($before['Default'] ?? null) === null) {
            $defaultSql = ' DEFAULT NULL';
        } elseif (($before['Default'] ?? null) !== null) {
            $defaultSql = " DEFAULT '" . $conn->real_escape_string((string) $before['Default']) . "'";
        }

        try { $conn->query('SET SESSION lock_wait_timeout=5'); } catch (Throwable $ignored) {}
        $sql = "ALTER TABLE transactions MODIFY COLUMN type VARCHAR(50) {$nullSql}{$defaultSql}";
        if (!$conn->query($sql)) {
            throw new RuntimeException('Unable to migrate transactions.type: ' . $conn->error);
        }
        $after = transactionIntegrityTypeColumnInfo(true);
        if (!transactionIntegrityTypeColumnReady($after)) {
            throw new RuntimeException('transactions.type migration completed but verification failed');
        }
        return ['changed' => true, 'before' => $before, 'after' => $after];
    }
}

if (!function_exists('transactionIntegrityApplyRepair')) {
    /** @return array<string,mixed> */
    function transactionIntegrityApplyRepair(int $adminUserId, string $expectedPreviewHash): array
    {
        global $conn;
        $adminUserId = max(0, $adminUserId);
        $expectedPreviewHash = trim($expectedPreviewHash);
        $result = [
            'success' => false,
            'batch_id' => '',
            'schema_changed' => false,
            'candidate_count' => 0,
            'repaired_count' => 0,
            'message' => '',
        ];

        if (!transactionIntegrityAcquireLock()) {
            $result['message'] = 'maintenance_lock_busy';
            return $result;
        }

        $batchId = transactionIntegrityUuid();
        $result['batch_id'] = $batchId;
        $batchInserted = false;
        try {
            $preview = transactionIntegrityPreview(25);
            if ($expectedPreviewHash === '' || !hash_equals((string) ($preview['preview_hash'] ?? ''), $expectedPreviewHash)) {
                throw new RuntimeException('preview_changed');
            }
            if ((int) (($preview['recent_active_orders']['count'] ?? 0)) > 0) {
                throw new RuntimeException('active_orders_detected');
            }
            if (!transactionIntegrityEnsureRepairTables()) {
                throw new RuntimeException('repair_log_tables_unavailable');
            }

            $schemaBefore = transactionIntegritySchemaSnapshot();
            $schemaBeforeJson = json_encode($schemaBefore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($schemaBeforeJson)) $schemaBeforeJson = '{}';
            $candidateCount = (int) ($preview['repairable_count'] ?? 0);
            $batchStmt = $conn->prepare('INSERT INTO transaction_type_repair_batches (batch_id,admin_user_id,schema_before,candidate_count,status) VALUES (?,?,?,?,\'started\')');
            if (!$batchStmt) throw new RuntimeException('batch_prepare_failed');
            $batchStmt->bind_param('sisi', $batchId, $adminUserId, $schemaBeforeJson, $candidateCount);
            if (!$batchStmt->execute()) {
                $error = $batchStmt->error;
                $batchStmt->close();
                throw new RuntimeException('batch_insert_failed: ' . $error);
            }
            $batchStmt->close();
            $batchInserted = true;

            $alter = transactionIntegrityAlterTypeColumn();
            $result['schema_changed'] = !empty($alter['changed']);

            $case = transactionIntegrityInferenceCaseSql('t');
            $candidateWhere = "({$case})<>'' AND LOWER(TRIM(COALESCE(t.type,'')))<>({$case})";
            $reasonExpr = "CASE ({$case}) WHEN 'cgo_purchase' THEN 'authoritative_cgo_order' WHEN 'supplier_purchase' THEN 'authoritative_supplier_order' WHEN 'store_api_purchase' THEN 'authoritative_store_api_order' WHEN 'redeem_code' THEN 'redeem_description' WHEN 'purchase' THEN 'local_key_evidence' WHEN 'deposit' THEN 'deposit_description' WHEN 'manual_add' THEN 'admin_credit_description' WHEN 'manual_deduct' THEN 'admin_debit_description' WHEN 'rank_bonus' THEN 'ranking_description' ELSE 'inferred' END";

            $conn->begin_transaction();
            $escapedBatch = $conn->real_escape_string($batchId);
            $backupSql = "INSERT INTO transaction_type_repair_log (batch_id,transaction_id,old_type,new_type,reason,description_hash) SELECT '{$escapedBatch}',t.id,COALESCE(t.type,''),({$case}),{$reasonExpr},SHA2(COALESCE(t.description,''),256) FROM transactions t WHERE {$candidateWhere}";
            if (!$conn->query($backupSql)) {
                throw new RuntimeException('repair_backup_failed: ' . $conn->error);
            }
            $backedUp = (int) $conn->affected_rows;

            $updateSql = "UPDATE transactions t SET t.type=({$case}) WHERE {$candidateWhere}";
            if (!$conn->query($updateSql)) {
                throw new RuntimeException('repair_update_failed: ' . $conn->error);
            }
            $repaired = (int) $conn->affected_rows;
            if ($repaired !== $backedUp) {
                throw new RuntimeException('repair_count_mismatch');
            }
            $conn->commit();

            $schemaAfterJson = json_encode(transactionIntegritySchemaSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($schemaAfterJson)) $schemaAfterJson = '{}';
            $done = $conn->prepare("UPDATE transaction_type_repair_batches SET schema_after=?,repaired_count=?,status='completed',completed_at=NOW() WHERE batch_id=?");
            if ($done) {
                $done->bind_param('sis', $schemaAfterJson, $repaired, $batchId);
                $done->execute();
                $done->close();
            }

            $result['success'] = true;
            $result['candidate_count'] = $candidateCount;
            $result['repaired_count'] = $repaired;
            $result['message'] = 'completed';
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $message = substr($e->getMessage(), 0, 1000);
            $result['message'] = $message;
            error_log('Transaction type maintenance failed [' . $batchId . ']: ' . $message);
            if ($batchInserted) {
                try {
                    $failed = $conn->prepare("UPDATE transaction_type_repair_batches SET status='failed',error_message=?,completed_at=NOW() WHERE batch_id=?");
                    if ($failed) {
                        $failed->bind_param('ss', $message, $batchId);
                        $failed->execute();
                        $failed->close();
                    }
                } catch (Throwable $ignored) {}
            }
        } finally {
            transactionIntegrityReleaseLock();
        }
        return $result;
    }
}


if (!function_exists('transactionIntegrityValidBatchId')) {
    function transactionIntegrityValidBatchId(string $batchId): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', trim($batchId)) === 1;
    }
}

if (!function_exists('transactionIntegrityEnsureDebugTable')) {
    function transactionIntegrityEnsureDebugTable(): bool
    {
        global $conn;
        static $ready = null;
        if ($ready === true) return true;
        $sql = "CREATE TABLE IF NOT EXISTS transaction_integrity_debug_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id CHAR(32) NOT NULL,
            batch_id CHAR(36) NOT NULL DEFAULT '',
            admin_user_id INT UNSIGNED NULL,
            action VARCHAR(32) NOT NULL,
            stage VARCHAR(48) NOT NULL DEFAULT '',
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            code VARCHAR(80) NOT NULL DEFAULT '',
            message VARCHAR(1000) NOT NULL DEFAULT '',
            db_errno INT NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_transaction_integrity_debug_created (created_at),
            KEY idx_transaction_integrity_debug_batch (batch_id,created_at),
            KEY idx_transaction_integrity_debug_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            $ready = (bool) $conn->query($sql);
            return $ready;
        } catch (Throwable $e) {
            error_log('Transaction integrity debug table setup failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('transactionIntegrityWriteDebugLog')) {
    /** @param array<string,mixed> $context */
    function transactionIntegrityWriteDebugLog(
        string $requestId,
        string $batchId,
        int $adminUserId,
        string $action,
        string $stage,
        string $level,
        string $code,
        string $message,
        int $durationMs = 0,
        array $context = []
    ): void {
        global $conn;
        $requestId = substr(preg_replace('/[^a-f0-9]/i', '', $requestId) ?: '', 0, 32);
        $batchId = transactionIntegrityValidBatchId($batchId) ? strtolower($batchId) : '';
        $action = substr(trim($action), 0, 32);
        $stage = substr(trim($stage), 0, 48);
        $level = in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info';
        $code = substr(trim($code), 0, 80);
        $message = substr(trim($message), 0, 1000);
        $durationMs = max(0, min(2147483647, $durationMs));
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($contextJson)) $contextJson = '{}';
        try {
            if (!transactionIntegrityEnsureDebugTable()) return;
            $stmt = $conn->prepare('INSERT INTO transaction_integrity_debug_logs (request_id,batch_id,admin_user_id,action,stage,level,code,message,db_errno,duration_ms,context_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            if (!$stmt) return;
            $dbErrno = (int) ($conn->errno ?? 0);
            $stmt->bind_param('ssisssssiis', $requestId, $batchId, $adminUserId, $action, $stage, $level, $code, $message, $dbErrno, $durationMs, $contextJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignored) {
            error_log('Transaction integrity debug write failed: ' . $ignored->getMessage());
        }
    }
}

if (!function_exists('transactionIntegrityGetBatch')) {
    /** @return array<string,mixed>|null */
    function transactionIntegrityGetBatch(string $batchId): ?array
    {
        global $conn;
        if (!transactionIntegrityValidBatchId($batchId)) return null;
        try {
            $stmt = $conn->prepare('SELECT batch_id,admin_user_id,candidate_count,repaired_count,status,error_message,created_at,completed_at FROM transaction_type_repair_batches WHERE batch_id=? LIMIT 1');
            if (!$stmt) return null;
            $stmt->bind_param('s', $batchId);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('Transaction integrity batch lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('transactionIntegritySetBatchState')) {
    function transactionIntegritySetBatchState(string $batchId, string $status, string $errorMessage = '', int $repairedDelta = 0, bool $complete = false, ?string $schemaAfterJson = null): bool
    {
        global $conn;
        if (!transactionIntegrityValidBatchId($batchId)) return false;
        $status = substr(trim($status), 0, 24);
        $errorMessage = substr(trim($errorMessage), 0, 1000);
        $repairedDelta = max(0, $repairedDelta);
        try {
            $completedSql = $complete ? ',completed_at=NOW()' : '';
            if ($schemaAfterJson !== null) {
                $stmt = $conn->prepare("UPDATE transaction_type_repair_batches SET status=?,error_message=?,repaired_count=repaired_count+?,schema_after=?{$completedSql} WHERE batch_id=?");
                if (!$stmt) return false;
                $stmt->bind_param('ssiss', $status, $errorMessage, $repairedDelta, $schemaAfterJson, $batchId);
            } else {
                $stmt = $conn->prepare("UPDATE transaction_type_repair_batches SET status=?,error_message=?,repaired_count=repaired_count+?{$completedSql} WHERE batch_id=?");
                if (!$stmt) return false;
                $stmt->bind_param('ssis', $status, $errorMessage, $repairedDelta, $batchId);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('Transaction integrity batch state update failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('transactionIntegrityRemainingCandidateCount')) {
    function transactionIntegrityRemainingCandidateCount(): int
    {
        global $conn;
        $case = transactionIntegrityInferenceCaseSql('t');
        $sql = "SELECT COUNT(*) AS c FROM (SELECT COALESCE(t.type,'') AS old_type,({$case}) AS inferred_type FROM transactions t) q WHERE q.inferred_type<>'' AND LOWER(TRIM(q.old_type))<>q.inferred_type";
        $result = $conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        return max(0, (int) ($row['c'] ?? 0));
    }
}

if (!function_exists('transactionIntegrityCreateBatch')) {
    /** @return array<string,mixed> */
    function transactionIntegrityCreateBatch(int $adminUserId, string $expectedPreviewHash, string $batchId): array
    {
        global $conn;
        $adminUserId = max(0, $adminUserId);
        $expectedPreviewHash = trim($expectedPreviewHash);
        $batchId = strtolower(trim($batchId));
        if (!transactionIntegrityValidBatchId($batchId)) {
            return ['success' => false, 'code' => 'invalid_batch_id', 'batch_id' => ''];
        }
        if (!transactionIntegrityEnsureRepairTables()) {
            return ['success' => false, 'code' => 'repair_log_tables_unavailable', 'batch_id' => $batchId];
        }
        $existing = transactionIntegrityGetBatch($batchId);
        if ($existing) {
            return ['success' => true, 'code' => 'batch_exists', 'batch_id' => $batchId, 'batch' => $existing];
        }
        if (!transactionIntegrityAcquireLock(2)) {
            return ['success' => false, 'code' => 'maintenance_lock_busy', 'batch_id' => $batchId];
        }
        try {
            $preview = transactionIntegrityPreview(25);
            if ($expectedPreviewHash === '' || !hash_equals((string) ($preview['preview_hash'] ?? ''), $expectedPreviewHash)) {
                return ['success' => false, 'code' => 'preview_changed', 'batch_id' => $batchId];
            }
            if ((int) ($preview['recent_active_orders']['count'] ?? 0) > 0) {
                return ['success' => false, 'code' => 'active_orders_detected', 'batch_id' => $batchId, 'active_orders' => $preview['recent_active_orders']];
            }
            $other = $conn->query("SELECT batch_id,status,created_at FROM transaction_type_repair_batches WHERE status IN ('initialized','schema_migration','repairing') AND created_at >= (NOW() - INTERVAL 20 MINUTE) ORDER BY id DESC LIMIT 1");
            $otherRow = $other ? $other->fetch_assoc() : null;
            if ($other) $other->free();
            if ($otherRow && !hash_equals(strtolower((string) $otherRow['batch_id']), $batchId)) {
                return ['success' => false, 'code' => 'another_active_batch', 'batch_id' => $batchId, 'active_batch' => $otherRow];
            }
            $snapshot = transactionIntegritySchemaSnapshot();
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($snapshotJson)) $snapshotJson = '{}';
            $candidateCount = (int) ($preview['repairable_count'] ?? 0);
            $stmt = $conn->prepare("INSERT INTO transaction_type_repair_batches (batch_id,admin_user_id,schema_before,candidate_count,repaired_count,status,error_message) VALUES (?,?,?,?,0,'initialized','')");
            if (!$stmt) throw new RuntimeException('batch_prepare_failed: ' . $conn->error);
            $stmt->bind_param('sisi', $batchId, $adminUserId, $snapshotJson, $candidateCount);
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                throw new RuntimeException('batch_insert_failed: ' . $message);
            }
            $stmt->close();
            return [
                'success' => true,
                'code' => 'initialized',
                'batch_id' => $batchId,
                'candidate_count' => $candidateCount,
                'schema_ready' => !empty($preview['schema_ready']),
            ];
        } finally {
            transactionIntegrityReleaseLock();
        }
    }
}

if (!function_exists('transactionIntegrityConfigureMetadataLockTimeout')) {
    function transactionIntegrityConfigureMetadataLockTimeout(int $seconds = 5): void
    {
        global $conn;
        $seconds = max(1, min(15, $seconds));
        if (!$conn->query('SET SESSION lock_wait_timeout=' . $seconds)) {
            throw new RuntimeException('metadata_lock_timeout_setup_failed: ' . $conn->error);
        }
        try { $conn->query('SET SESSION innodb_lock_wait_timeout=' . $seconds); } catch (Throwable $ignored) {}
        $result = $conn->query("SHOW SESSION VARIABLES LIKE 'lock_wait_timeout'");
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $actual = (int) ($row['Value'] ?? 0);
        if ($actual < 1 || $actual > $seconds) {
            throw new RuntimeException('metadata_lock_timeout_verification_failed');
        }
    }
}

if (!function_exists('transactionIntegrityMigrateBatchSchema')) {
    /** @return array<string,mixed> */
    function transactionIntegrityMigrateBatchSchema(string $batchId): array
    {
        global $conn;
        $batchId = strtolower(trim($batchId));
        $batch = transactionIntegrityGetBatch($batchId);
        if (!$batch) return ['success' => false, 'code' => 'batch_not_found', 'batch_id' => $batchId];
        if ((string) ($batch['status'] ?? '') === 'completed') {
            return ['success' => true, 'code' => 'completed', 'batch_id' => $batchId, 'schema_ready' => transactionIntegrityTypeColumnReady(transactionIntegrityTypeColumnInfo(true))];
        }
        $active = transactionIntegrityRecentActiveOrders(5);
        if ((int) ($active['count'] ?? 0) > 0) {
            return ['success' => false, 'code' => 'active_orders_detected', 'batch_id' => $batchId, 'active_orders' => $active];
        }
        if (!transactionIntegrityAcquireLock(2)) {
            return ['success' => false, 'code' => 'maintenance_lock_busy', 'batch_id' => $batchId];
        }
        try {
            @ignore_user_abort(true);
            @set_time_limit(45);
            transactionIntegritySetBatchState($batchId, 'schema_migration');
            $before = transactionIntegrityTypeColumnInfo(true);
            if (transactionIntegrityTypeColumnReady($before)) {
                transactionIntegritySetBatchState($batchId, 'repairing');
                return ['success' => true, 'code' => 'schema_already_ready', 'batch_id' => $batchId, 'schema_changed' => false, 'schema_ready' => true];
            }
            transactionIntegrityConfigureMetadataLockTimeout(5);
            $alter = transactionIntegrityAlterTypeColumn();
            $afterSnapshot = transactionIntegritySchemaSnapshot();
            $afterJson = json_encode($afterSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($afterJson)) $afterJson = '{}';
            transactionIntegritySetBatchState($batchId, 'repairing', '', 0, false, $afterJson);
            return [
                'success' => true,
                'code' => 'schema_ready',
                'batch_id' => $batchId,
                'schema_changed' => !empty($alter['changed']),
                'schema_ready' => true,
                'column' => $alter['after'] ?? [],
            ];
        } catch (Throwable $e) {
            $message = substr($e->getMessage(), 0, 1000);
            transactionIntegritySetBatchState($batchId, 'failed', $message, 0, true);
            throw $e;
        } finally {
            transactionIntegrityReleaseLock();
        }
    }
}

if (!function_exists('transactionIntegrityRepairBatchChunk')) {
    /** @return array<string,mixed> */
    function transactionIntegrityRepairBatchChunk(string $batchId, int $limit = 40): array
    {
        global $conn;
        $batchId = strtolower(trim($batchId));
        $limit = max(1, min(100, $limit));
        $batch = transactionIntegrityGetBatch($batchId);
        if (!$batch) return ['success' => false, 'code' => 'batch_not_found', 'batch_id' => $batchId];
        if ((string) ($batch['status'] ?? '') === 'completed') {
            return ['success' => true, 'code' => 'completed', 'batch_id' => $batchId, 'remaining' => 0, 'repaired_count' => (int) ($batch['repaired_count'] ?? 0)];
        }
        if (!transactionIntegrityTypeColumnReady(transactionIntegrityTypeColumnInfo(true))) {
            return ['success' => false, 'code' => 'schema_not_ready', 'batch_id' => $batchId];
        }
        if (!transactionIntegrityAcquireLock(2)) {
            return ['success' => false, 'code' => 'maintenance_lock_busy', 'batch_id' => $batchId];
        }
        try {
            @ignore_user_abort(true);
            @set_time_limit(30);
            transactionIntegritySetBatchState($batchId, 'repairing');
            $case = transactionIntegrityInferenceCaseSql('t');
            $sql = "SELECT q.id,q.old_type,q.inferred_type,q.description_hash FROM (SELECT t.id,COALESCE(t.type,'') AS old_type,({$case}) AS inferred_type,SHA2(COALESCE(t.description,''),256) AS description_hash FROM transactions t) q WHERE q.inferred_type<>'' AND LOWER(TRIM(q.old_type))<>q.inferred_type ORDER BY q.id ASC LIMIT {$limit}";
            $query = $conn->query($sql);
            if (!$query) throw new RuntimeException('candidate_query_failed: ' . $conn->error);
            $rows = $query->fetch_all(MYSQLI_ASSOC);
            $query->free();
            if (!$rows) {
                $afterJson = json_encode(transactionIntegritySchemaSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (!is_string($afterJson)) $afterJson = '{}';
                transactionIntegritySetBatchState($batchId, 'completed', '', 0, true, $afterJson);
                $doneBatch = transactionIntegrityGetBatch($batchId);
                return ['success' => true, 'code' => 'completed', 'batch_id' => $batchId, 'remaining' => 0, 'repaired_count' => (int) ($doneBatch['repaired_count'] ?? 0), 'processed' => 0];
            }

            $reasonMap = [
                'cgo_purchase' => 'authoritative_cgo_order',
                'supplier_purchase' => 'authoritative_supplier_order',
                'store_api_purchase' => 'authoritative_store_api_order',
                'redeem_code' => 'redeem_description',
                'purchase' => 'local_key_evidence',
                'deposit' => 'deposit_description',
                'manual_add' => 'admin_credit_description',
                'manual_deduct' => 'admin_debit_description',
                'rank_bonus' => 'ranking_description',
            ];
            $insert = $conn->prepare('INSERT IGNORE INTO transaction_type_repair_log (batch_id,transaction_id,old_type,new_type,reason,description_hash) VALUES (?,?,?,?,?,?)');
            $update = $conn->prepare("UPDATE transactions SET type=? WHERE id=? AND LOWER(TRIM(COALESCE(type,'')))<>?");
            if (!$insert || !$update) throw new RuntimeException('chunk_prepare_failed: ' . $conn->error);
            $conn->begin_transaction();
            $updated = 0;
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $oldType = (string) ($row['old_type'] ?? '');
                $newType = transactionIntegrityNormalizeType($row['inferred_type'] ?? '');
                if ($id < 1 || $newType === '' || !in_array($newType, transactionIntegrityKnownTypes(), true)) continue;
                $reason = $reasonMap[$newType] ?? 'inferred';
                $hash = substr((string) ($row['description_hash'] ?? ''), 0, 64);
                $insert->bind_param('sissss', $batchId, $id, $oldType, $newType, $reason, $hash);
                if (!$insert->execute()) throw new RuntimeException('chunk_backup_failed: ' . $insert->error);
                $update->bind_param('sis', $newType, $id, $newType);
                if (!$update->execute()) throw new RuntimeException('chunk_update_failed: ' . $update->error);
                $updated += max(0, (int) $update->affected_rows);
            }
            $insert->close();
            $update->close();
            $conn->commit();

            $remaining = transactionIntegrityRemainingCandidateCount();
            $complete = $remaining === 0;
            transactionIntegritySetBatchState($batchId, $complete ? 'completed' : 'repairing', '', $updated, $complete);
            $fresh = transactionIntegrityGetBatch($batchId);
            return [
                'success' => true,
                'code' => $complete ? 'completed' : 'chunk_repaired',
                'batch_id' => $batchId,
                'processed' => count($rows),
                'updated' => $updated,
                'remaining' => $remaining,
                'candidate_count' => (int) ($fresh['candidate_count'] ?? 0),
                'repaired_count' => (int) ($fresh['repaired_count'] ?? 0),
                'status' => (string) ($fresh['status'] ?? ''),
            ];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $message = substr($e->getMessage(), 0, 1000);
            transactionIntegritySetBatchState($batchId, 'failed', $message, 0, true);
            throw $e;
        } finally {
            transactionIntegrityReleaseLock();
        }
    }
}

if (!function_exists('transactionIntegrityBatchStatus')) {
    /** @return array<string,mixed> */
    function transactionIntegrityBatchStatus(string $batchId): array
    {
        $batch = transactionIntegrityGetBatch($batchId);
        if (!$batch) return ['success' => false, 'code' => 'batch_not_found', 'batch_id' => $batchId];
        $schemaInfo = transactionIntegrityTypeColumnInfo(true);
        $schemaReady = transactionIntegrityTypeColumnReady($schemaInfo);
        $remaining = null;
        try {
            if ($schemaReady && (string) ($batch['status'] ?? '') !== 'completed') {
                $remaining = transactionIntegrityRemainingCandidateCount();
                if ($remaining === 0 && (string) ($batch['status'] ?? '') !== 'failed') {
                    transactionIntegritySetBatchState($batchId, 'completed', '', 0, true);
                    $batch = transactionIntegrityGetBatch($batchId) ?? $batch;
                }
            }
        } catch (Throwable $e) {
            error_log('Transaction integrity status count failed: ' . $e->getMessage());
        }
        return [
            'success' => true,
            'code' => 'status',
            'batch_id' => $batchId,
            'batch' => $batch,
            'schema_ready' => $schemaReady,
            'column_type' => (string) ($schemaInfo['Type'] ?? ''),
            'remaining' => $remaining,
            'server_time' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('transactionIntegrityVerifyInsertedRow')) {
    /**
     * Verify the values MariaDB actually stored. This catches the non-strict
     * ENUM behaviour where execute() succeeds but an unsupported value becomes
     * an empty string. Callers already wrap financial changes in transactions,
     * so returning false causes their existing rollback path to run.
     */
    function transactionIntegrityVerifyInsertedRow(int $transactionId, int $userId, string $type, float $amount, string $status, ?int $referenceId): bool
    {
        global $conn;
        if ($transactionId < 1) return false;
        $stmt = $conn->prepare('SELECT user_id,type,amount,status,reference_id FROM transactions WHERE id=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('i', $transactionId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return false;

        $storedReference = $row['reference_id'] === null ? null : (int) $row['reference_id'];
        $expectedReference = $referenceId === null ? null : (int) $referenceId;
        $baseFieldsOk = (int) ($row['user_id'] ?? 0) === $userId
            && abs((float) ($row['amount'] ?? 0) - $amount) <= 0.00001
            && hash_equals(transactionIntegrityNormalizeType($status), transactionIntegrityNormalizeType($row['status'] ?? ''))
            && $storedReference === $expectedReference;
        $expectedType = transactionIntegrityNormalizeType($type);
        $storedType = transactionIntegrityNormalizeType($row['type'] ?? '');
        $typeOk = hash_equals($expectedType, $storedType);
        if ($baseFieldsOk && !$typeOk) {
            $columnInfo = transactionIntegrityTypeColumnInfo();
            if (!transactionIntegrityTypeColumnReady($columnInfo)
                && !transactionIntegrityTypeColumnSupports($expectedType, $columnInfo)) {
                // Deployment remains available until the admin runs the explicit
                // migration page. The UI can infer these legacy rows, and the
                // repair batch will back them up and correct them afterward.
                error_log('Transaction type verification deferred for legacy schema; TX #' . $transactionId . '; expected_type=' . $expectedType . '; stored_type=' . $storedType);
                return true;
            }
        }
        $ok = $baseFieldsOk && $typeOk;
        if (!$ok) {
            error_log('Transaction post-insert verification failed for TX #' . $transactionId . '; expected_type=' . $expectedType . '; stored_type=' . $storedType);
        }
        return $ok;
    }
}
