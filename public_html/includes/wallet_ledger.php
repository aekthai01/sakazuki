<?php
/**
 * Wallet balance audit ledger.
 *
 * This append-only ledger is evidence for changes to users.balance. It does not
 * replace transactions/orders. New money mutations write both their business
 * record and a deterministic wallet movement in the same DB transaction.
 *
 * Privacy: public_note is owner-safe. admin_note/reference_code are admin-only.
 * Never place API keys, passwords, raw provider payloads, or full redemption
 * secrets in this table.
 */

require_once __DIR__ . '/db.php';

if (!function_exists('walletLedgerRuntimeSchemaReady')) {
    /**
     * Cheap checkout-time readiness probe. Normal web requests may call this,
     * but they must never issue CREATE/ALTER statements.
     */
    function walletLedgerRuntimeSchemaReady(bool $refresh = false): bool
    {
        global $conn;
        static $ready = null;
        if ($refresh) $ready = null;
        if ($ready !== null) return $ready;
        if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

        $columns = [
            'id','event_key','user_id','direction','amount','delta_amount',
            'balance_before','balance_after','source_type','source_id',
            'transaction_id','actor_user_id','public_note','admin_note',
            'reference_code','affects_reconciliation','created_at',
        ];
        $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        try {
            $result = $conn->query('SELECT ' . implode(',', $quoted) . ' FROM `wallet_balance_ledger` LIMIT 0');
            if ($result instanceof mysqli_result) $result->free();
            return $ready = ($result !== false);
        } catch (Throwable $e) {
            return $ready = false;
        }
    }
}

if (!function_exists('walletLedgerMigrationColumnExists')) {
    /** Maintenance-only metadata helper. */
    function walletLedgerMigrationColumnExists(string $column): bool
    {
        global $conn;
        if (preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_balance_ledger' AND COLUMN_NAME=?"
            );
            if (!$stmt) return false;
            $stmt->bind_param('s', $column);
            if (!$stmt->execute()) { $stmt->close(); return false; }
            $stmt->bind_result($count);
            $found = $stmt->fetch();
            $stmt->close();
            return $found && (int) $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('walletLedgerEnsureMigrationColumn')) {
    /** Add one missing column from the maintenance runner only. */
    function walletLedgerEnsureMigrationColumn(string $column, string $definition): bool
    {
        global $conn;
        if (walletLedgerMigrationColumnExists($column)) return true;
        if (preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        try {
            if ($conn->query('ALTER TABLE `wallet_balance_ledger` ADD COLUMN `' . $column . '` ' . $definition)) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('Wallet ledger column migration failed [' . $column . ']: ' . $e->getMessage());
        }
        // Another maintenance request may have added it while this request was
        // waiting on the metadata lock. Re-check before declaring failure.
        return walletLedgerMigrationColumnExists($column);
    }
}

if (!function_exists('ensureWalletLedgerSchema')) {
    function ensureWalletLedgerSchema(): bool
    {
        global $conn;
        static $ready = null;
        if ($ready === true) return true;
        if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
        if (walletLedgerRuntimeSchemaReady()) return $ready = true;

        // Only CLI or automation_runner.php?mode=maintenance may change schema.
        // Customer/admin checkout requests fail safely instead of doing DDL.
        $migrationsAllowed = defined('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS')
            ? SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS === true
            : PHP_SAPI === 'cli';
        if (!$migrationsAllowed) return $ready = false;

        $sql = "CREATE TABLE IF NOT EXISTS wallet_balance_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(191) NOT NULL,
            user_id INT NOT NULL,
            direction VARCHAR(12) NOT NULL,
            amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            delta_amount DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            balance_before DECIMAL(16,2) NULL,
            balance_after DECIMAL(16,2) NULL,
            source_type VARCHAR(64) NOT NULL,
            source_id BIGINT UNSIGNED NULL,
            transaction_id BIGINT UNSIGNED NULL,
            actor_user_id INT NULL,
            public_note VARCHAR(500) NOT NULL DEFAULT '',
            admin_note VARCHAR(1000) NOT NULL DEFAULT '',
            reference_code VARCHAR(191) NULL,
            affects_reconciliation TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wallet_ledger_event (event_key),
            KEY idx_wallet_ledger_user_time (user_id, created_at, id),
            KEY idx_wallet_ledger_transaction (transaction_id),
            KEY idx_wallet_ledger_source (source_type, source_id),
            KEY idx_wallet_ledger_actor (actor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            if (!$conn->query($sql)) {
                error_log('Wallet ledger schema setup failed: ' . $conn->error);
                return $ready = false;
            }

            // CREATE TABLE IF NOT EXISTS does not upgrade an older table. Repair
            // every column checkout currently writes, without dropping any data.
            $columnDefinitions = [
                'event_key' => "VARCHAR(191) NULL",
                'user_id' => "INT NULL",
                'direction' => "VARCHAR(12) NOT NULL DEFAULT 'neutral'",
                'amount' => "DECIMAL(16,2) NOT NULL DEFAULT 0.00",
                'delta_amount' => "DECIMAL(16,2) NOT NULL DEFAULT 0.00",
                'balance_before' => "DECIMAL(16,2) NULL",
                'balance_after' => "DECIMAL(16,2) NULL",
                'source_type' => "VARCHAR(64) NOT NULL DEFAULT 'legacy'",
                'source_id' => "BIGINT UNSIGNED NULL",
                'transaction_id' => "BIGINT UNSIGNED NULL",
                'actor_user_id' => "INT NULL",
                'public_note' => "VARCHAR(500) NOT NULL DEFAULT ''",
                'admin_note' => "VARCHAR(1000) NOT NULL DEFAULT ''",
                'reference_code' => "VARCHAR(191) NULL",
                'affects_reconciliation' => "TINYINT(1) NOT NULL DEFAULT 1",
                'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ];
            foreach ($columnDefinitions as $column => $definition) {
                if (!walletLedgerEnsureMigrationColumn($column, $definition)) {
                    error_log('Wallet ledger schema is missing required column: ' . $column);
                    return $ready = false;
                }
            }
        } catch (Throwable $e) {
            error_log('Wallet ledger schema setup failed: ' . $e->getMessage());
            return $ready = false;
        }

        return $ready = walletLedgerRuntimeSchemaReady(true);
    }
}

if (!function_exists('walletLedgerBindParams')) {
    function walletLedgerBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '') return true;
        if (strlen($types) !== count($params)) return false;
        $args = [$types];
        foreach ($params as $i => $_) $args[] = &$params[$i];
        return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('walletLedgerNormalizeMoney')) {
    function walletLedgerNormalizeMoney($value): ?float
    {
        if (!is_numeric($value)) return null;
        $number = round((float) $value, 2);
        if (!is_finite($number) || abs($number) > 1000000000) return null;
        return $number;
    }
}

if (!function_exists('walletLedgerCleanText')) {
    function walletLedgerCleanText($value, int $limit): string
    {
        if (!is_scalar($value)) return '';
        $text = trim((string) $value);
        if ($text === '') return '';
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }
}

if (!function_exists('walletLedgerReadBalance')) {
    function walletLedgerReadBalance(int $userId, bool $forUpdate = false): ?float
    {
        global $conn;
        if ($userId < 1) return null;
        $sql = 'SELECT balance FROM users WHERE id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $stmt->bind_result($balance);
        $found = $stmt->fetch();
        $stmt->close();
        return $found ? round((float) $balance, 2) : null;
    }
}

if (!function_exists('walletLedgerInsertBaseline')) {
    function walletLedgerInsertBaseline(int $userId, float $balance): bool
    {
        global $conn;
        if ($userId < 1 || !ensureWalletLedgerSchema()) return false;
        $balance = round($balance, 2);
        $eventKey = 'baseline:user:' . $userId;
        $direction = 'baseline';
        $sourceType = 'legacy_baseline';
        $publicNote = 'ยอดตั้งต้นก่อนระบบบันทึกยอดเงินแบบละเอียด';
        $adminNote = 'Legacy opening balance anchor. Earlier movements may not have complete before/after evidence.';
        $zero = 0.0;
        $affects = 1;

        $stmt = $conn->prepare(
            'INSERT IGNORE INTO wallet_balance_ledger '
            . '(event_key,user_id,direction,amount,delta_amount,balance_before,balance_after,source_type,public_note,admin_note,affects_reconciliation) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$stmt) return false;
        $stmt->bind_param('sisddddsssi', $eventKey, $userId, $direction, $zero, $zero, $balance, $balance, $sourceType, $publicNote, $adminNote, $affects);
        $ok = $stmt->execute();
        $inserted = $ok && (int) $stmt->affected_rows === 1;
        $stmt->close();
        if (!$ok) return false;

        $check = $conn->prepare('SELECT user_id,balance_before,balance_after FROM wallet_balance_ledger WHERE event_key=? LIMIT 1');
        if (!$check) return false;
        $check->bind_param('s', $eventKey);
        if (!$check->execute()) { $check->close(); return false; }
        $check->bind_result($storedUser, $storedBefore, $storedAfter);
        $found = $check->fetch();
        $check->close();
        if (!$found || (int) $storedUser !== $userId) return false;
        if (!$inserted) return true;
        return abs((float) $storedBefore - $balance) < 0.009
            && abs((float) $storedAfter - $balance) < 0.009;
    }
}

if (!function_exists('walletLedgerEnsureBaselineForUser')) {
    function walletLedgerEnsureBaselineForUser(int $userId): bool
    {
        global $conn;
        if ($userId < 1 || !ensureWalletLedgerSchema()) return false;
        $check = $conn->prepare('SELECT 1 FROM wallet_balance_ledger WHERE user_id=? LIMIT 1');
        if (!$check) return false;
        $check->bind_param('i', $userId);
        if (!$check->execute()) { $check->close(); return false; }
        $check->store_result();
        $hasRows = $check->num_rows > 0;
        $check->close();
        if ($hasRows) return true;
        $balance = walletLedgerReadBalance($userId, false);
        return $balance !== null && walletLedgerInsertBaseline($userId, $balance);
    }
}

if (!function_exists('walletLedgerRecordMovement')) {
    function walletLedgerRecordMovement(
        int $userId,
        float $deltaAmount,
        float $balanceBefore,
        float $balanceAfter,
        string $sourceType,
        string $eventKey,
        ?int $sourceId = null,
        ?int $transactionId = null,
        ?int $actorUserId = null,
        string $publicNote = '',
        string $adminNote = '',
        ?string $referenceCode = null,
        bool $affectsReconciliation = true
    ): bool {
        global $conn;
        if ($userId < 1 || !ensureWalletLedgerSchema()) return false;
        $delta = walletLedgerNormalizeMoney($deltaAmount);
        $before = walletLedgerNormalizeMoney($balanceBefore);
        $after = walletLedgerNormalizeMoney($balanceAfter);
        if ($delta === null || $before === null || $after === null || abs(($before + $delta) - $after) > 0.011) {
            error_log('Wallet ledger rejected inconsistent movement for user #' . $userId);
            return false;
        }
        $sourceType = strtolower(walletLedgerCleanText($sourceType, 64));
        $eventKey = walletLedgerCleanText($eventKey, 191);
        if ($sourceType === '' || $eventKey === '') return false;
        if ($sourceId !== null && $sourceId < 1) $sourceId = null;
        if ($transactionId !== null && $transactionId < 1) $transactionId = null;
        if ($actorUserId !== null && $actorUserId < 1) $actorUserId = null;
        $publicNote = walletLedgerCleanText($publicNote, 500);
        $adminNote = walletLedgerCleanText($adminNote, 1000);
        $referenceCode = $referenceCode === null ? null : walletLedgerCleanText($referenceCode, 191);
        if ($referenceCode === '') $referenceCode = null;
        $direction = $delta > 0.00001 ? 'credit' : ($delta < -0.00001 ? 'debit' : 'neutral');
        $amount = abs($delta);
        $affects = $affectsReconciliation ? 1 : 0;

        if (!walletLedgerInsertBaseline($userId, $before)) return false;
        $insert = $conn->prepare(
            'INSERT INTO wallet_balance_ledger '
            . '(event_key,user_id,direction,amount,delta_amount,balance_before,balance_after,source_type,source_id,transaction_id,actor_user_id,public_note,admin_note,reference_code,affects_reconciliation) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        if (!$insert) return false;
        $insert->bind_param('sisddddsiiisssi', $eventKey, $userId, $direction, $amount, $delta, $before, $after, $sourceType, $sourceId, $transactionId, $actorUserId, $publicNote, $adminNote, $referenceCode, $affects);
        $ok = $insert->execute();
        $duplicate = !$ok && (int) $insert->errno === 1062;
        $insert->close();
        if ($ok) return true;
        if (!$duplicate) return false;

        $check = $conn->prepare(
            'SELECT user_id,delta_amount,balance_before,balance_after,source_type,COALESCE(source_id,0),COALESCE(transaction_id,0) '
            . 'FROM wallet_balance_ledger WHERE event_key=? LIMIT 1'
        );
        if (!$check) return false;
        $check->bind_param('s', $eventKey);
        if (!$check->execute()) { $check->close(); return false; }
        $check->bind_result($storedUser, $storedDelta, $storedBefore, $storedAfter, $storedSource, $storedSourceId, $storedTxId);
        $found = $check->fetch();
        $check->close();
        return $found
            && (int) $storedUser === $userId
            && abs((float) $storedDelta - $delta) < 0.009
            && abs((float) $storedBefore - $before) < 0.009
            && abs((float) $storedAfter - $after) < 0.009
            && hash_equals((string) $storedSource, $sourceType)
            && (int) $storedSourceId === (int) ($sourceId ?? 0)
            && (int) $storedTxId === (int) ($transactionId ?? 0);
    }
}

if (!function_exists('walletLedgerGetRows')) {
    function walletLedgerGetRows(int $userId, int $limit = 50): array
    {
        global $conn;
        $limit = max(1, min(200, $limit));
        if ($userId < 1 || !ensureWalletLedgerSchema()) return [];
        $stmt = $conn->prepare(
            'SELECT l.*,a.username AS actor_username FROM wallet_balance_ledger l '
            . 'LEFT JOIN users a ON a.id=l.actor_user_id WHERE l.user_id=? '
            . 'ORDER BY l.created_at DESC,l.id DESC LIMIT ' . $limit
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        foreach ($rows as &$row) $row['_legacy_evidence'] = 0;
        unset($row);
        return $rows;
    }
}

if (!function_exists('walletLedgerRowsByTransactionIds')) {
    function walletLedgerRowsByTransactionIds(array $transactionIds): array
    {
        global $conn;
        if (!ensureWalletLedgerSchema()) return [];
        $ids = [];
        foreach ($transactionIds as $id) {
            $id = (int) $id;
            if ($id > 0) $ids[$id] = $id;
            if (count($ids) >= 500) break;
        }
        if (!$ids) return [];
        $idList = implode(',', array_map('intval', array_values($ids)));
        $result = $conn->query(
            "SELECT l.*,a.username AS actor_username FROM wallet_balance_ledger l "
            . "LEFT JOIN users a ON a.id=l.actor_user_id WHERE l.transaction_id IN ({$idList}) ORDER BY l.id ASC"
        );
        if (!$result) return [];
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $txId = (int) ($row['transaction_id'] ?? 0);
            if ($txId > 0) $rows[$txId][] = $row;
        }
        $result->free();
        return $rows;
    }
}

if (!function_exists('walletLedgerTableExists')) {
    function walletLedgerTableExists(string $table): bool
    {
        global $conn;
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        if (preg_match('/^[a-z0-9_]+$/i', $table) !== 1) return false;
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
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

if (!function_exists('walletLedgerLegacyRefundEvidence')) {
    function walletLedgerLegacyRefundEvidence(int $userId, int $limit = 100): array
    {
        global $conn;
        $limit = max(1, min(300, $limit));
        if ($userId < 1) return [];
        $rows = [];
        $hasTracked = static function (string $eventKey): bool {
            global $conn;
            if (!ensureWalletLedgerSchema()) return false;
            $stmt = $conn->prepare('SELECT 1 FROM wallet_balance_ledger WHERE event_key=? LIMIT 1');
            if (!$stmt) return false;
            $stmt->bind_param('s', $eventKey);
            if (!$stmt->execute()) { $stmt->close(); return false; }
            $stmt->store_result();
            $found = $stmt->num_rows > 0;
            $stmt->close();
            return $found;
        };

        if (walletLedgerTableExists('cgo_orders')) {
            $stmt = $conn->prepare("SELECT id,total_price_base,status,transaction_id,external_ref,COALESCE(updated_at,created_at) evidence_at FROM cgo_orders WHERE user_id=? AND status IN ('refunded','refunded_conflict') ORDER BY id DESC LIMIT " . $limit);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $orderId = (int) $row['id'];
                        if ($hasTracked('cgo_refund:' . $orderId)) continue;
                        $amount = round((float) $row['total_price_base'], 2);
                        if ($amount <= 0) continue;
                        $rows[] = [
                            'id'=>0,'event_key'=>'legacy:cgo_refund:'.$orderId,'user_id'=>$userId,'direction'=>'credit','amount'=>$amount,'delta_amount'=>$amount,
                            'balance_before'=>null,'balance_after'=>null,'source_type'=>'cgo_refund_legacy','source_id'=>$orderId,'transaction_id'=>(int)($row['transaction_id']??0),
                            'actor_user_id'=>null,'public_note'=>'คืนเงินคำสั่งซื้อ CGO (ข้อมูลย้อนหลัง)','admin_note'=>'Reconstructed from authoritative refunded order status; historical before/after balance is unavailable.',
                            'reference_code'=>(string)($row['external_ref']??''),'affects_reconciliation'=>0,'created_at'=>(string)($row['evidence_at']??''),'actor_username'=>null,'_legacy_evidence'=>1,
                        ];
                    }
                }
                $stmt->close();
            }
        }
        if (walletLedgerTableExists('supplier_orders')) {
            $stmt = $conn->prepare("SELECT id,total_price_base,status,transaction_id,external_ref,COALESCE(updated_at,created_at) evidence_at FROM supplier_orders WHERE user_id=? AND status='refunded' ORDER BY id DESC LIMIT " . $limit);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $orderId = (int) $row['id'];
                        if ($hasTracked('supplier_refund:' . $orderId)) continue;
                        $amount = round((float) $row['total_price_base'], 2);
                        if ($amount <= 0) continue;
                        $rows[] = [
                            'id'=>0,'event_key'=>'legacy:supplier_refund:'.$orderId,'user_id'=>$userId,'direction'=>'credit','amount'=>$amount,'delta_amount'=>$amount,
                            'balance_before'=>null,'balance_after'=>null,'source_type'=>'supplier_refund_legacy','source_id'=>$orderId,'transaction_id'=>(int)($row['transaction_id']??0),
                            'actor_user_id'=>null,'public_note'=>'คืนเงินคำสั่งซื้อ Supplier (ข้อมูลย้อนหลัง)','admin_note'=>'Reconstructed from authoritative refunded order status; historical before/after balance is unavailable.',
                            'reference_code'=>(string)($row['external_ref']??''),'affects_reconciliation'=>0,'created_at'=>(string)($row['evidence_at']??''),'actor_username'=>null,'_legacy_evidence'=>1,
                        ];
                    }
                }
                $stmt->close();
            }
        }
        usort($rows, static fn(array $a, array $b): int => (strtotime((string)($b['created_at']??''))?:0) <=> (strtotime((string)($a['created_at']??''))?:0));
        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('walletLedgerLegacyTransactionEvidence')) {
    function walletLedgerLegacyTransactionEvidence(int $userId, int $limit = 100): array
    {
        global $conn;
        $limit = max(1, min(300, $limit));
        if ($userId < 1 || !walletLedgerTableExists('transactions')) return [];
        $tracked = [];
        if (ensureWalletLedgerSchema()) {
            $trackedStmt = $conn->prepare('SELECT event_key,transaction_id FROM wallet_balance_ledger WHERE user_id=?');
            if ($trackedStmt) {
                $trackedStmt->bind_param('i', $userId);
                if ($trackedStmt->execute()) {
                    $trackedResult = $trackedStmt->get_result();
                    while ($trackedRow = $trackedResult ? $trackedResult->fetch_assoc() : null) {
                        if (!$trackedRow) break;
                        $id = (int) ($trackedRow['transaction_id'] ?? 0);
                        if ($id > 0) $tracked[$id] = true;
                        $eventKey = (string) ($trackedRow['event_key'] ?? '');
                        if (preg_match('/\Alocal_purchase_group:(\d+):(\d+)\z/', $eventKey, $m) === 1) {
                            $first = (int) $m[1]; $last = (int) $m[2];
                            if ($first > 0 && $last >= $first && ($last - $first) <= 500) {
                                for ($i=$first; $i<=$last; $i++) $tracked[$i] = true;
                            }
                        }
                    }
                }
                $trackedStmt->close();
            }
        }

        $stmt = $conn->prepare("SELECT id,type,amount,status,description,reference_id,created_at FROM transactions WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT " . max($limit * 3, 60));
        if (!$stmt) return [];
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $txId = (int) $row['id'];
            if ($txId < 1 || isset($tracked[$txId])) continue;
            $status = strtolower(trim((string) $row['status']));
            if (!in_array($status, ['completed','success'], true)) continue;
            $type = strtolower(trim((string) $row['type']));
            $description = trim((string) $row['description']);
            if ($type === '') {
                $lower = strtolower($description);
                if (strpos($lower,'slip verification')===0 || strpos($lower,'truemoney angpao gross ')===0 || strpos($lower,'binance gift card:')===0 || strpos($lower,'binance usdt deposit:')===0) $type='deposit';
                elseif (strpos($lower,'redeemed top-up code ')===0) $type='redeem_code';
                elseif ($lower==='opening balance added by admin' || $lower==='balance added by admin') $type='manual_add';
                elseif ($lower==='balance deducted by admin') $type='manual_deduct';
                elseif (strpos($lower,'monthly rank bonus ')===0) $type='rank_bonus';
                elseif (strpos($lower,'store api reseller wallet order ')===0) $type='store_api_purchase';
                elseif (strpos($lower,'purchased ')===0 && strpos($lower,' key: ')!==false) $type='purchase';
            }
            $credits = ['deposit','redeem_code','manual_add','rank_bonus'];
            $debits = ['purchase','cgo_purchase','supplier_purchase','store_api_purchase','manual_deduct'];
            if (!in_array($type, array_merge($credits,$debits), true)) continue;
            $amount = round(abs((float)$row['amount']),2);
            if (!is_finite($amount) || $amount > 100000000) continue;
            $credit = in_array($type,$credits,true);
            $labels = [
                'deposit'=>'เติมเงิน (ข้อมูลย้อนหลัง)','redeem_code'=>'เติมเงินด้วยโค้ด (ข้อมูลย้อนหลัง)','manual_add'=>'ผู้ดูแลระบบเพิ่มยอด (ข้อมูลย้อนหลัง)',
                'manual_deduct'=>'ผู้ดูแลระบบหักยอด (ข้อมูลย้อนหลัง)','rank_bonus'=>'โบนัสแรงค์ (ข้อมูลย้อนหลัง)','purchase'=>'ซื้อสินค้า (ข้อมูลย้อนหลัง)',
                'cgo_purchase'=>'ซื้อสินค้าผ่าน CGO (ข้อมูลย้อนหลัง)','supplier_purchase'=>'ซื้อสินค้าผ่าน Supplier (ข้อมูลย้อนหลัง)',
                'store_api_purchase'=>'ซื้อผ่าน Store API ด้วยยอดตัวแทน (ข้อมูลย้อนหลัง)',
            ];
            $rows[] = [
                'id'=>0,'event_key'=>'legacy:transaction:'.$txId,'user_id'=>$userId,'direction'=>$credit?'credit':'debit','amount'=>$amount,'delta_amount'=>$credit?$amount:-$amount,
                'balance_before'=>null,'balance_after'=>null,'source_type'=>$type.'_legacy','source_id'=>(int)($row['reference_id']??0),'transaction_id'=>$txId,'actor_user_id'=>null,
                'public_note'=>$labels[$type]??'รายการยอดเงินย้อนหลัง','admin_note'=>'Legacy transaction evidence; historical before/after balance is unavailable.','reference_code'=>null,
                'affects_reconciliation'=>0,'created_at'=>(string)$row['created_at'],'actor_username'=>null,'_legacy_evidence'=>1,
            ];
            if (count($rows) >= $limit) break;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('walletLedgerOwnerSafeRow')) {
    function walletLedgerOwnerSafeRow(array $row): array
    {
        return [
            'id'=>(int)($row['id']??0),
            'direction'=>(string)($row['direction']??''),
            'amount'=>(float)($row['amount']??0),
            'delta_amount'=>(float)($row['delta_amount']??0),
            'balance_before'=>$row['balance_before']===null?null:(float)$row['balance_before'],
            'balance_after'=>$row['balance_after']===null?null:(float)$row['balance_after'],
            'source_type'=>(string)($row['source_type']??''),
            'public_note'=>(string)($row['public_note']??''),
            'created_at'=>(string)($row['created_at']??''),
            '_legacy_evidence'=>!empty($row['_legacy_evidence'])?1:0,
        ];
    }
}

if (!function_exists('walletLedgerGetUserTimeline')) {
    function walletLedgerGetUserTimeline(int $userId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        if ($userId < 1) return [];
        walletLedgerEnsureBaselineForUser($userId);
        $rows = array_merge(
            walletLedgerGetRows($userId, $limit),
            walletLedgerLegacyRefundEvidence($userId, min(50,$limit)),
            walletLedgerLegacyTransactionEvidence($userId, min(80,$limit*2))
        );
        usort($rows, static function(array $a,array $b): int {
            $ta = strtotime((string)($a['created_at']??'')) ?: 0;
            $tb = strtotime((string)($b['created_at']??'')) ?: 0;
            if ($ta !== $tb) return $tb <=> $ta;
            return (int)($b['id']??0) <=> (int)($a['id']??0);
        });
        $rows = array_slice($rows,0,$limit);
        return array_map('walletLedgerOwnerSafeRow',$rows);
    }
}

if (!function_exists('walletLedgerGetSummary')) {
    function walletLedgerGetSummary(int $userId): array
    {
        global $conn;
        $empty=['available'=>false,'current_balance'=>null,'coverage_start'=>null,'opening_balance'=>null,'last_recorded_balance'=>null,'tracked_net'=>0.0,'movement_count'=>0,'chain_gap_count'=>0,'discrepancy'=>null,'status'=>'unavailable'];
        if ($userId < 1 || !ensureWalletLedgerSchema() || !walletLedgerEnsureBaselineForUser($userId)) return $empty;
        $current = walletLedgerReadBalance($userId,false);
        if ($current === null) return $empty;
        $stmt=$conn->prepare('SELECT source_type,balance_before,balance_after,created_at FROM wallet_balance_ledger WHERE user_id=? AND affects_reconciliation=1 ORDER BY id ASC');
        if(!$stmt)return $empty;
        $stmt->bind_param('i',$userId);
        if(!$stmt->execute()){ $stmt->close(); return $empty; }
        $result=$stmt->get_result();
        $opening=null;$coverage=null;$last=null;$prev=null;$moves=0;$gaps=0;
        while($row=$result?$result->fetch_assoc():null){
            if(!$row)break;
            $before=$row['balance_before']===null?null:round((float)$row['balance_before'],2);
            $after=$row['balance_after']===null?null:round((float)$row['balance_after'],2);
            if($opening===null && $before!==null){$opening=$before;$coverage=(string)$row['created_at'];}
            if($prev!==null && $before!==null && abs($prev-$before)>0.009)$gaps++;
            if($after!==null){$prev=$after;$last=$after;}
            if((string)$row['source_type']!=='legacy_baseline')$moves++;
        }
        $stmt->close();
        if($last===null)$last=$current;
        if($opening===null)$opening=$last;
        $diff=round($current-$last,2);
        $status=$gaps>0?'chain_gap':(abs($diff)>0.009?'discrepancy':'ok');
        return ['available'=>true,'current_balance'=>$current,'coverage_start'=>$coverage,'opening_balance'=>$opening,'last_recorded_balance'=>$last,'tracked_net'=>round($last-$opening,2),'movement_count'=>$moves,'chain_gap_count'=>$gaps,'discrepancy'=>$diff,'status'=>$status];
    }
}

if (!function_exists('walletLedgerGetRefundRows')) {
    function walletLedgerGetRefundRows(int $userId = 0, int $limit = 100, string $search = ''): array
    {
        global $conn;
        $limit=max(1,min(500,$limit));
        if(!ensureWalletLedgerSchema())return [];
        $sql="SELECT l.*,u.username,a.username actor_username FROM wallet_balance_ledger l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN users a ON a.id=l.actor_user_id WHERE l.source_type IN ('cgo_refund','supplier_refund')";
        $params=[];$types='';
        if($userId>0){$sql.=' AND l.user_id=?';$types.='i';$params[]=$userId;}
        if($search!==''){
            $like='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $sql.=" AND (u.username LIKE ? ESCAPE '!' OR l.reference_code LIKE ? ESCAPE '!' OR l.public_note LIKE ? ESCAPE '!' OR l.admin_note LIKE ? ESCAPE '!')";
            $types.='ssss'; array_push($params,$like,$like,$like,$like);
        }
        $sql.=' ORDER BY l.created_at DESC,l.id DESC LIMIT '.$limit;
        $stmt=$conn->prepare($sql); if(!$stmt)return [];
        if($types!=='' && !walletLedgerBindParams($stmt,$types,$params)){ $stmt->close(); return []; }
        if(!$stmt->execute()){ $stmt->close(); return []; }
        $result=$stmt->get_result(); $rows=$result?$result->fetch_all(MYSQLI_ASSOC):[]; $stmt->close();
        foreach($rows as &$row)$row['_legacy_evidence']=0; unset($row);
        return $rows;
    }
}

if (!function_exists('walletLedgerGetLegacyRefundRowsForAdmin')) {
    /**
     * Reconstruct historical refund evidence for Admin without inventing old
     * before/after balances. Rows already represented by the detailed wallet
     * ledger are excluded so the same refund never appears twice.
     */
    function walletLedgerGetLegacyRefundRowsForAdmin(int $userId = 0, int $limit = 200, string $search = ''): array
    {
        global $conn;
        $limit = max(1, min(500, $limit));
        if (!ensureWalletLedgerSchema()) return [];

        $rows = [];
        $search = trim($search);
        $appendSource = static function (string $table, string $sourcePrefix, array $statuses, string $sourceType, string $publicNote) use (&$rows, $conn, $userId, $limit, $search): void {
            if (!walletLedgerTableExists($table)) return;
            $statusSql = implode(',', array_map(static fn(string $value): string => "'" . $conn->real_escape_string($value) . "'", $statuses));
            $sql = "SELECT o.id,o.user_id,o.total_price_base,o.transaction_id,o.external_ref,"
                . "COALESCE(o.updated_at,o.created_at) evidence_at,u.username "
                . "FROM `{$table}` o LEFT JOIN users u ON u.id=o.user_id "
                . "LEFT JOIN wallet_balance_ledger wl ON wl.event_key=CONCAT(?,o.id) "
                . "WHERE o.status IN ({$statusSql}) AND wl.id IS NULL";
            $params = [$sourcePrefix];
            $types = 's';
            if ($userId > 0) {
                $sql .= ' AND o.user_id=?';
                $types .= 'i';
                $params[] = $userId;
            }
            if ($search !== '') {
                $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $sql .= " AND (COALESCE(u.username,'') LIKE ? ESCAPE '!' OR COALESCE(o.external_ref,'') LIKE ? ESCAPE '!' OR CAST(o.id AS CHAR) LIKE ? ESCAPE '!')";
                $types .= 'sss';
                array_push($params, $like, $like, $like);
            }
            $sql .= ' ORDER BY evidence_at DESC,o.id DESC LIMIT ' . $limit;
            $stmt = $conn->prepare($sql);
            if (!$stmt) return;
            if (!walletLedgerBindParams($stmt, $types, $params) || !$stmt->execute()) {
                $stmt->close();
                return;
            }
            $result = $stmt->get_result();
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $amount = round((float) ($row['total_price_base'] ?? 0), 2);
                if ($amount <= 0) continue;
                $orderId = (int) ($row['id'] ?? 0);
                $rows[] = [
                    'id' => 0,
                    'event_key' => 'legacy:' . $sourcePrefix . $orderId,
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                    'direction' => 'credit',
                    'amount' => $amount,
                    'delta_amount' => $amount,
                    'balance_before' => null,
                    'balance_after' => null,
                    'source_type' => $sourceType,
                    'source_id' => $orderId,
                    'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                    'actor_user_id' => null,
                    'public_note' => $publicNote,
                    'admin_note' => 'Historical refund reconstructed from authoritative refunded order status. Old before/after balance was not stored.',
                    'reference_code' => (string) ($row['external_ref'] ?? ''),
                    'affects_reconciliation' => 0,
                    'created_at' => (string) ($row['evidence_at'] ?? ''),
                    'actor_username' => null,
                    '_legacy_evidence' => 1,
                ];
            }
            $stmt->close();
        };

        $appendSource('cgo_orders', 'cgo_refund:', ['refunded', 'refunded_conflict'], 'cgo_refund_legacy', 'คืนเงินคำสั่งซื้อ CGO (ข้อมูลย้อนหลัง)');
        $appendSource('supplier_orders', 'supplier_refund:', ['refunded'], 'supplier_refund_legacy', 'คืนเงินคำสั่งซื้อ Supplier (ข้อมูลย้อนหลัง)');
        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });
        return array_slice($rows, 0, $limit);
    }
}
