<?php
/**
 * Read-only commerce consistency scanner.
 *
 * It never creates, alters, updates, or deletes business data. Every check is
 * bounded and each optional integration is isolated so one unavailable table
 * cannot hide the condition of the other sources.
 */

require_once __DIR__ . '/commerce_context.php';
require_once __DIR__ . '/transaction_integrity.php';
require_once __DIR__ . '/cheatgame.php';

if (!function_exists('commerceConsistencyAllowedTable')) {
    function commerceConsistencyAllowedTable(string $table): bool
    {
        return in_array(strtolower(trim($table)), [
            'users', 'transactions', 'keys', 'products', 'product_variants',
            'cgo_orders', 'cgo_order_keys', 'cgo_products',
            'supplier_orders', 'supplier_order_keys', 'supplier_products',
            'store_api_orders', 'store_api_order_keys', 'store_api_clients', 'wallet_balance_ledger',
            'purchase_activity_events',
            'transaction_type_repair_batches', 'transaction_type_repair_log',
        ], true);
    }
}

if (!function_exists('commerceConsistencyColumns')) {
    /** @return array<string,bool> */
    function commerceConsistencyColumns(string $table): array
    {
        if (!commerceConsistencyAllowedTable($table)) return [];
        return commerceContextTableColumns(strtolower(trim($table)));
    }
}

if (!function_exists('commerceConsistencyQueryRows')) {
    /** @return array{ok:bool,rows:array,error:string,errno:int} */
    function commerceConsistencyQueryRows(string $sql): array
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) {
            return ['ok' => false, 'rows' => [], 'error' => 'database_unavailable', 'errno' => 0];
        }
        try {
            $result = $conn->query($sql);
            if (!$result) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'error' => substr((string) $conn->error, 0, 1000),
                    'errno' => (int) $conn->errno,
                ];
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return ['ok' => true, 'rows' => $rows, 'error' => '', 'errno' => 0];
        } catch (Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => substr($e->getMessage(), 0, 1000), 'errno' => (int) $e->getCode()];
        }
    }
}

if (!function_exists('commerceConsistencyAddCheck')) {
    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $definition
     */
    function commerceConsistencyAddCheck(array &$report, array $definition): void
    {
        $code = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($definition['code'] ?? 'check')));
        $severity = in_array((string) ($definition['severity'] ?? ''), ['error', 'warning', 'notice'], true)
            ? (string) $definition['severity'] : 'warning';
        $check = [
            'code' => $code,
            'severity' => $severity,
            'title_th' => (string) ($definition['title_th'] ?? $code),
            'title_en' => (string) ($definition['title_en'] ?? $code),
            'help_th' => (string) ($definition['help_th'] ?? ''),
            'help_en' => (string) ($definition['help_en'] ?? ''),
            'status' => 'ok',
            'count' => 0,
            'rows' => [],
            'error' => '',
            'db_errno' => 0,
        ];

        if (!empty($definition['skip'])) {
            $check['status'] = 'skipped';
            $check['error'] = (string) ($definition['skip_reason'] ?? 'required_schema_unavailable');
            $report['summary']['skipped']++;
            $report['checks'][] = $check;
            return;
        }

        $report['summary']['checks_run']++;
        $countResult = commerceConsistencyQueryRows((string) ($definition['count_sql'] ?? 'SELECT 0 AS c'));
        if (!$countResult['ok']) {
            $check['status'] = 'query_error';
            $check['error'] = (string) $countResult['error'];
            $check['db_errno'] = (int) $countResult['errno'];
            $report['summary']['query_errors']++;
            $report['checks'][] = $check;
            return;
        }
        $check['count'] = max(0, (int) ($countResult['rows'][0]['c'] ?? 0));
        if ($check['count'] > 0) {
            $rowsResult = commerceConsistencyQueryRows((string) ($definition['rows_sql'] ?? 'SELECT 1 WHERE 0'));
            if (!$rowsResult['ok']) {
                $check['status'] = 'query_error';
                $check['error'] = (string) $rowsResult['error'];
                $check['db_errno'] = (int) $rowsResult['errno'];
                $report['summary']['query_errors']++;
                $report['checks'][] = $check;
                return;
            }
            $check['status'] = 'issue';
            $check['rows'] = $rowsResult['rows'];
            $report['summary']['issues'] += $check['count'];
            $report['summary'][$severity . 's'] += $check['count'];
        }
        $report['checks'][] = $check;
    }
}


if (!function_exists('commerceConsistencyAddComputedCheck')) {
    /**
     * Add a check whose rows were evaluated in PHP, for cases such as account
     * deliveries where one purchased item contains several labelled fields.
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $definition
     * @param array<int,array<string,mixed>> $rows
     */
    function commerceConsistencyAddComputedCheck(array &$report, array $definition, array $rows): void
    {
        $severity = in_array((string) ($definition['severity'] ?? ''), ['error', 'warning', 'notice'], true)
            ? (string) $definition['severity'] : 'warning';
        $count = isset($definition['count']) ? max(0, (int) $definition['count']) : count($rows);
        $check = [
            'code' => preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($definition['code'] ?? 'check'))),
            'severity' => $severity,
            'title_th' => (string) ($definition['title_th'] ?? ''),
            'title_en' => (string) ($definition['title_en'] ?? ''),
            'help_th' => (string) ($definition['help_th'] ?? ''),
            'help_en' => (string) ($definition['help_en'] ?? ''),
            'status' => $count > 0 ? 'issue' : 'ok',
            'count' => $count,
            'rows' => array_values($rows),
            'error' => (string) ($definition['error'] ?? ''),
            'db_errno' => (int) ($definition['db_errno'] ?? 0),
        ];
        $report['summary']['checks_run']++;
        if ($count > 0) {
            $report['summary']['issues'] += $count;
            $report['summary'][$severity . 's'] += $count;
        }
        $report['checks'][] = $check;
    }
}

if (!function_exists('commerceConsistencyRunCgoDeliveryCount')) {
    function commerceConsistencyRunCgoDeliveryCount(
        array &$report,
        string $successSql,
        string $txExpr,
        string $keyCountSql,
        string $externalExpr,
        string $timeExpr,
        int $sampleLimit
    ): void {
        global $conn;
        $sql = "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status,
                       COALESCE(o.quantity,0) AS quantity,{$keyCountSql} AS key_count,
                       {$externalExpr} AS external_ref,{$timeExpr} AS order_time,
                       COALESCE(o.response_json,'') AS response_json,
                       COALESCE(p.name,'') AS product_name,
                       COALESCE(p.brand,'') AS product_brand,
                       COALESCE(p.category,'') AS product_category,
                       COALESCE(p.platform,'') AS product_platform,
                       COALESCE(p.description,'') AS product_description
                FROM cgo_orders o
                LEFT JOIN cgo_products p ON p.id=o.cgo_product_id
                WHERE {$successSql} AND {$keyCountSql}<>COALESCE(o.quantity,0)
                ORDER BY o.id DESC
                LIMIT 2000";
        $result = commerceConsistencyQueryRows($sql);
        if (!$result['ok']) {
            commerceConsistencyAddComputedCheck($report, [
                'code' => 'cgo_delivered_key_count_mismatch',
                'severity' => 'error',
                'title_th' => 'CGO จำนวนรายการส่งมอบไม่ตรงกับจำนวนสั่งซื้อ',
                'title_en' => 'CGO delivered item count mismatch',
                'help_th' => 'ตรวจจำนวนหน่วยที่ส่งมอบจริง โดยสินค้าบัญชีหนึ่งบัญชีอาจมีหลายฟิลด์แต่ต้องนับเป็นหนึ่งรายการ',
                'help_en' => 'Checks delivered units. One account may contain many fields but must count as one item.',
                'error' => (string) $result['error'],
                'db_errno' => (int) $result['errno'],
            ], []);
            return;
        }

        $realMismatch = [];
        $fragmentedAccounts = [];
        foreach ($result['rows'] as $row) {
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            $decoded = json_decode((string) ($row['response_json'] ?? ''), true);
            $items = is_array($decoded) && function_exists('cgoExtractDeliveryItems')
                ? cgoExtractDeliveryItems($decoded)
                : [];
            $accountCount = 0;
            foreach ($items as $item) {
                if ((string) ($item['type'] ?? '') === 'account') $accountCount++;
            }

            $publicRow = $row;
            unset($publicRow['response_json'], $publicRow['product_description']);
            $publicRow['normalized_item_count'] = count($items);
            $publicRow['normalized_account_count'] = $accountCount;

            if ($quantity > 0 && count($items) === $quantity && $accountCount > 0) {
                $fragmentedAccounts[] = $publicRow;
            } else {
                $realMismatch[] = $publicRow;
            }
        }

        commerceConsistencyAddComputedCheck($report, [
            'code' => 'cgo_delivered_key_count_mismatch',
            'severity' => 'error',
            'title_th' => 'CGO จำนวนรายการส่งมอบไม่ตรงกับจำนวนสั่งซื้อ',
            'title_en' => 'CGO delivered item count mismatch',
            'help_th' => 'รายการนี้ยังไม่สามารถตีความเป็นจำนวนหน่วยที่สั่งได้อย่างปลอดภัย ต้องตรวจคำตอบ Provider ก่อนแก้ข้อมูล',
            'help_en' => 'These responses still cannot be safely normalized to the ordered quantity and require review.',
            'count' => count($realMismatch),
        ], array_slice($realMismatch, 0, $sampleLimit));

        commerceConsistencyAddComputedCheck($report, [
            'code' => 'cgo_fragmented_account_delivery',
            'severity' => 'warning',
            'title_th' => 'CGO บัญชีเกมถูกระบบเก่าแยกเป็นหลายคีย์',
            'title_en' => 'CGO game accounts stored as fragmented key rows',
            'help_th' => 'ข้อมูลไม่สูญหาย ระบบใหม่จะแสดงเป็นหนึ่งบัญชีและนับหนึ่งรายการจากคำตอบ API เดิม โดยไม่ลบบรรทัดเก่าอัตโนมัติ',
            'help_en' => 'No data is lost. The new reader presents one structured account from the saved API response without deleting legacy rows automatically.',
            'count' => count($fragmentedAccounts),
        ], array_slice($fragmentedAccounts, 0, $sampleLimit));
    }
}

if (!function_exists('commerceConsistencyRunRemoteSource')) {
    /** @param array<string,mixed> $config */
    function commerceConsistencyRunRemoteSource(array &$report, array $config, int $sampleLimit): void
    {
        $orders = (string) $config['orders'];
        $keys = (string) $config['keys'];
        $source = (string) $config['source'];
        $orderCols = commerceConsistencyColumns($orders);
        $keyCols = commerceConsistencyColumns($keys);
        $txCols = commerceConsistencyColumns('transactions');
        $userCols = commerceConsistencyColumns('users');
        $ready = commerceContextHasColumns($orderCols, ['id', 'user_id', 'status'])
            && commerceContextHasColumns($keyCols, ['order_id'])
            && commerceContextHasColumns($txCols, ['id', 'user_id', 'status', 'type', 'reference_id']);
        $success = commerceSuccessfulStatusSql('o');
        $keyCount = "(SELECT COUNT(*) FROM `{$keys}` ok WHERE ok.order_id=o.id)";
        $legacyType = preg_replace('/[^a-z0-9_]+/', '', strtolower((string) ($config['transaction_type'] ?? '')));
        $legacyTxExpr = $legacyType !== ''
            ? "COALESCE((SELECT MAX(tl.id) FROM transactions tl WHERE tl.reference_id=o.id AND tl.user_id=o.user_id AND LOWER(TRIM(COALESCE(tl.type,'')))='{$legacyType}' AND LOWER(TRIM(COALESCE(tl.status,'')))='completed'),0)"
            : '0';
        $txExpr = !empty($orderCols['transaction_id'])
            ? "COALESCE(NULLIF(o.transaction_id,0),{$legacyTxExpr})"
            : $legacyTxExpr;
        $quantityExpr = !empty($orderCols['quantity']) ? 'COALESCE(o.quantity,0)' : '0';
        $timeExpr = !empty($orderCols['completed_at'])
            ? "COALESCE(o.completed_at," . (!empty($orderCols['created_at']) ? 'o.created_at' : "''") . ')'
            : (!empty($orderCols['created_at']) ? 'o.created_at' : "''");
        $externalExpr = !empty($orderCols['external_ref']) ? "COALESCE(o.external_ref,'')" : "''";
        $scopeCondition = '1=1';
        if (!empty($config['exclude_store_api_procurement'])
            && !empty($orderCols['source_kind']) && !empty($orderCols['source_order_id'])) {
            $scopeCondition = "NOT (LOWER(TRIM(COALESCE(o.source_kind,'storefront')))='store_api' AND COALESCE(o.source_order_id,0)>0)";
        }

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_invalid_transaction_link',
            'severity' => 'error',
            'title_th' => $config['label_th'] . ' สำเร็จแต่เชื่อม Transaction ไม่ถูกต้อง',
            'title_en' => $config['label_en'] . ' successful orders with an invalid transaction link',
            'help_th' => 'Order สำเร็จต้องเชื่อมกับ Transaction ของผู้ซื้อเดียวกันและ Transaction ต้อง completed',
            'help_en' => 'A successful order must link to a completed transaction owned by the same user.',
            'skip' => !$ready,
            'skip_reason' => 'orders_keys_or_transactions_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o LEFT JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND ({$txExpr}<1 OR t.id IS NULL OR t.user_id<>o.user_id OR LOWER(TRIM(COALESCE(t.status,'')))<>'completed')",
            'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status AS order_status,COALESCE(t.user_id,0) AS transaction_user_id,COALESCE(t.status,'') AS transaction_status,COALESCE(t.type,'') AS transaction_type,{$quantityExpr} AS quantity,{$keyCount} AS key_count,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o LEFT JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND ({$txExpr}<1 OR t.id IS NULL OR t.user_id<>o.user_id OR LOWER(TRIM(COALESCE(t.status,'')))<>'completed') ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_unexpected_transaction_type',
            'severity' => 'warning',
            'title_th' => $config['label_th'] . ' เชื่อม Transaction คนละประเภท',
            'title_en' => $config['label_en'] . ' orders linked to an unexpected transaction type',
            'help_th' => 'ลิงก์ยังใช้ได้ แต่ประเภท Transaction ที่ไม่ตรงแหล่งอาจทำให้รายงานรุ่นเก่าหรือการค้นหาแบบ fallback ตีความผิด',
            'help_en' => 'The link can still work, but an unexpected transaction type can confuse legacy reports and fallback lookups.',
            'skip' => !$ready || $legacyType === '',
            'skip_reason' => 'transaction_type_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND LOWER(TRIM(COALESCE(t.type,'')))<>'{$legacyType}'",
            'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status AS order_status,COALESCE(t.type,'') AS transaction_type,COALESCE(t.status,'') AS transaction_status,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND LOWER(TRIM(COALESCE(t.type,'')))<>'{$legacyType}' ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_payment_amount_mismatch',
            'severity' => 'error',
            'title_th' => $config['label_th'] . ' ยอด Transaction ไม่ตรงกับยอด Order',
            'title_en' => $config['label_en'] . ' order and transaction amount mismatch',
            'help_th' => 'ยอดที่หักจากผู้ใช้ควรตรงกับ total_price_base ของ Order ต่างกันเกิน 0.01 อาจทำให้ History และกำไรคลาดเคลื่อน',
            'help_en' => 'The charged amount should match order total_price_base. A difference above 0.01 can corrupt history and profit reports.',
            'skip' => !$ready || empty($orderCols['total_price_base']) || empty($txCols['amount']),
            'skip_reason' => 'order_or_transaction_amount_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND ABS(COALESCE(t.amount,0)-COALESCE(o.total_price_base,0))>0.01",
            'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status AS order_status,COALESCE(o.total_price_base,0) AS order_total,COALESCE(t.amount,0) AS transaction_amount,ROUND(COALESCE(t.amount,0)-COALESCE(o.total_price_base,0),2) AS difference,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o JOIN transactions t ON t.id={$txExpr} WHERE {$scopeCondition} AND {$success} AND ABS(COALESCE(t.amount,0)-COALESCE(o.total_price_base,0))>0.01 ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        if ($source === 'cgo' && $ready && !empty($orderCols['quantity'])
            && function_exists('cgoExtractDeliveryItems')) {
            commerceConsistencyRunCgoDeliveryCount(
                $report,
                $success,
                $txExpr,
                $keyCount,
                $externalExpr,
                $timeExpr,
                $sampleLimit
            );
        } else {
            commerceConsistencyAddCheck($report, [
                'code' => $source . '_delivered_key_count_mismatch',
                'severity' => 'error',
                'title_th' => $config['label_th'] . ' จำนวนรายการส่งมอบไม่ตรงกับจำนวนสั่งซื้อ',
                'title_en' => $config['label_en'] . ' delivered item count mismatch',
                'help_th' => 'Order สำเร็จต้องมีจำนวนหน่วยที่ส่งมอบตรงกับ quantity',
                'help_en' => 'A successful order must have exactly the requested number of delivered items.',
                'skip' => !$ready || empty($orderCols['quantity']),
                'skip_reason' => 'quantity_or_key_schema_unavailable',
                'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o WHERE {$scopeCondition} AND {$success} AND {$keyCount}<>COALESCE(o.quantity,0)",
                'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status,COALESCE(o.quantity,0) AS quantity,{$keyCount} AS key_count,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o WHERE {$scopeCondition} AND {$success} AND {$keyCount}<>COALESCE(o.quantity,0) ORDER BY o.id DESC LIMIT {$sampleLimit}",
            ]);
        }

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_keys_on_unsuccessful_order',
            'severity' => 'warning',
            'title_th' => $config['label_th'] . ' มีคีย์แล้วแต่สถานะยังไม่สำเร็จ',
            'title_en' => $config['label_en'] . ' keys attached to non-successful orders',
            'help_th' => 'อาจเป็น Order ที่ Provider ส่งคีย์แล้ว แต่สถานะหรือ Transaction ยังไม่ถูกปิดงาน',
            'help_en' => 'The provider may have delivered keys while the order or transaction was never finalized.',
            'skip' => !$ready,
            'skip_reason' => 'orders_or_keys_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o WHERE {$scopeCondition} AND NOT ({$success}) AND {$keyCount}>0",
            'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status,{$quantityExpr} AS quantity,{$keyCount} AS key_count,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o WHERE {$scopeCondition} AND NOT ({$success}) AND {$keyCount}>0 ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_duplicate_transaction_link',
            'severity' => 'error',
            'title_th' => $config['label_th'] . ' หลาย Order ใช้ Transaction เดียวกัน',
            'title_en' => $config['label_en'] . ' duplicate transaction links',
            'help_th' => 'Transaction หนึ่งรายการควรเป็นของ Order เดียว มิฉะนั้นยอดเงินและประวัติจะซ้ำกัน',
            'help_en' => 'One transaction should belong to one order; duplicates can double-count payment history.',
            'skip' => !$ready || empty($orderCols['transaction_id']),
            'skip_reason' => 'transaction_id_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM (SELECT o.transaction_id FROM `{$orders}` o WHERE {$scopeCondition} AND COALESCE(o.transaction_id,0)>0 GROUP BY o.transaction_id HAVING COUNT(*)>1) duplicate_links",
            'rows_sql' => "SELECT o.transaction_id,COUNT(*) AS order_count,GROUP_CONCAT(o.id ORDER BY o.id SEPARATOR ',') AS order_ids FROM `{$orders}` o WHERE {$scopeCondition} AND COALESCE(o.transaction_id,0)>0 GROUP BY o.transaction_id HAVING COUNT(*)>1 ORDER BY o.transaction_id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => $source . '_missing_owner',
            'severity' => 'error',
            'title_th' => $config['label_th'] . ' สำเร็จแต่ไม่พบเจ้าของบัญชี',
            'title_en' => $config['label_en'] . ' successful orders with a missing owner',
            'help_th' => 'Order อ้าง user_id ที่ไม่มีในตาราง users ทำให้ My Keys, Logs และ History หาเจ้าของไม่ได้',
            'help_en' => 'The order references a user that no longer exists, so ownership cannot be resolved.',
            'skip' => !$ready || !commerceContextHasColumns($userCols, ['id']),
            'skip_reason' => 'users_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o LEFT JOIN users u ON u.id=o.user_id WHERE {$scopeCondition} AND {$success} AND u.id IS NULL",
            'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.status,{$quantityExpr} AS quantity,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o LEFT JOIN users u ON u.id=o.user_id WHERE {$scopeCondition} AND {$success} AND u.id IS NULL ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        $productTable = (string) ($config['product_table'] ?? '');
        $productColumn = (string) ($config['product_column'] ?? '');
        $productCols = $productTable !== '' ? commerceConsistencyColumns($productTable) : [];
        $productReady = $ready && !empty($orderCols[$productColumn]) && commerceContextHasColumns($productCols, ['id']);
        if ($productTable !== '' && $productColumn !== '') {
            commerceConsistencyAddCheck($report, [
                'code' => $source . '_missing_product',
                'severity' => 'warning',
                'title_th' => $config['label_th'] . ' สำเร็จแต่ไม่พบข้อมูลสินค้า',
                'title_en' => $config['label_en'] . ' successful orders with missing product data',
                'help_th' => 'คีย์ยังอาจใช้งานได้ แต่ชื่อ รูป ระยะเวลา และรายงานกำไรจะดึงข้อมูลไม่ครบ',
                'help_en' => 'The key may still work, but product name, image, duration, and reporting can be incomplete.',
                'skip' => !$productReady,
                'skip_reason' => 'product_schema_unavailable',
                'count_sql' => "SELECT COUNT(*) AS c FROM `{$orders}` o LEFT JOIN `{$productTable}` p ON p.id=o.`{$productColumn}` WHERE {$scopeCondition} AND {$success} AND p.id IS NULL",
                'rows_sql' => "SELECT o.id AS order_id,{$txExpr} AS transaction_id,o.user_id,o.`{$productColumn}` AS product_id,o.status,{$externalExpr} AS external_ref,{$timeExpr} AS order_time FROM `{$orders}` o LEFT JOIN `{$productTable}` p ON p.id=o.`{$productColumn}` WHERE {$scopeCondition} AND {$success} AND p.id IS NULL ORDER BY o.id DESC LIMIT {$sampleLimit}",
            ]);
        }
    }
}

if (!function_exists('commerceConsistencyRun')) {
    /** @return array<string,mixed> */
    function commerceConsistencyRun(int $sampleLimit = 50): array
    {
        global $conn;
        $sampleLimit = max(10, min(100, $sampleLimit));
        $report = [
            'checked_at' => date('Y-m-d H:i:s'),
            'database_connected' => isset($conn) && $conn instanceof mysqli,
            'database_name' => '',
            'server_version' => '',
            'sample_limit' => $sampleLimit,
            'summary' => [
                'checks_run' => 0,
                'skipped' => 0,
                'query_errors' => 0,
                'issues' => 0,
                'errors' => 0,
                'warnings' => 0,
                'notices' => 0,
            ],
            'tables' => [],
            'checks' => [],
        ];
        if (!$report['database_connected']) return $report;

        try {
            $db = $conn->query('SELECT DATABASE() AS database_name, VERSION() AS server_version');
            $dbRow = $db ? $db->fetch_assoc() : null;
            if ($db) $db->free();
            $report['database_name'] = (string) ($dbRow['database_name'] ?? '');
            $report['server_version'] = (string) ($dbRow['server_version'] ?? '');
        } catch (Throwable $ignored) {}

        foreach ([
            'users', 'transactions', 'keys', 'products', 'product_variants',
            'cgo_orders', 'cgo_order_keys', 'cgo_products',
            'supplier_orders', 'supplier_order_keys', 'supplier_products',
            'store_api_orders', 'store_api_order_keys', 'store_api_clients', 'wallet_balance_ledger',
            'purchase_activity_events',
            'transaction_type_repair_batches', 'transaction_type_repair_log',
        ] as $table) {
            $columns = commerceConsistencyColumns($table);
            $report['tables'][$table] = ['exists' => $columns !== [], 'columns' => count($columns)];
        }

        $typeColumn = transactionIntegrityTypeColumnInfo(true);
        $schemaReady = transactionIntegrityTypeColumnReady($typeColumn);
        $columnTypeLiteral = $conn->real_escape_string((string) ($typeColumn['Type'] ?? 'missing'));
        commerceConsistencyAddCheck($report, [
            'code' => 'transactions_type_schema_unsafe',
            'severity' => 'error',
            'title_th' => 'transactions.type ไม่รองรับชนิด Transaction ปัจจุบัน',
            'title_en' => 'transactions.type cannot store all current transaction types',
            'help_th' => 'ENUM รุ่นเก่าอาจรับ INSERT สำเร็จแต่เก็บชนิดที่ไม่รู้จักเป็นค่าว่าง ทำให้หน้า Transactions แสดง Unknown และตีเครื่องหมายยอดเงินผิด',
            'help_en' => 'An old ENUM can accept INSERT but store unsupported values as an empty string, causing Unknown labels and incorrect debit/credit display.',
            'count_sql' => $schemaReady ? 'SELECT 0 AS c' : 'SELECT 1 AS c',
            'rows_sql' => "SELECT '{$columnTypeLiteral}' AS current_column_type,'VARCHAR(50)' AS required_column_type,'transaction_integrity.php' AS repair_page",
        ]);

        $txSchemaReady = commerceContextHasColumns(commerceConsistencyColumns('transactions'), ['id', 'type', 'description']);
        $knownTypesEscaped = array_map([$conn, 'real_escape_string'], transactionIntegrityKnownTypes());
        $knownTypesSql = "'" . implode("','", $knownTypesEscaped) . "'";
        commerceConsistencyAddCheck($report, [
            'code' => 'transactions_blank_type',
            'severity' => 'error',
            'title_th' => 'Transaction มีประเภทว่าง',
            'title_en' => 'Transactions with a blank type',
            'help_th' => 'ค่าว่างมักเกิดจาก ENUM ไม่รองรับค่าที่ PHP ส่งมา รายการเหล่านี้ควร Backup และซ่อมจากหลักฐานต้นทาง',
            'help_en' => 'Blank values commonly come from an ENUM that did not support the PHP value. These rows should be backed up and repaired from authoritative evidence.',
            'skip' => !$txSchemaReady,
            'skip_reason' => 'transactions_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM transactions WHERE TRIM(COALESCE(type,''))=''",
            'rows_sql' => "SELECT id AS transaction_id,user_id,COALESCE(type,'') AS stored_type,COALESCE(amount,0) AS amount,COALESCE(status,'') AS status,LEFT(COALESCE(description,''),240) AS description,COALESCE(reference_id,0) AS reference_id,COALESCE(created_at,'') AS created_at FROM transactions WHERE TRIM(COALESCE(type,''))='' ORDER BY id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'transactions_unrecognized_type',
            'severity' => 'warning',
            'title_th' => 'Transaction ใช้ประเภทที่ระบบไม่รู้จัก',
            'title_en' => 'Transactions with an unrecognized type',
            'help_th' => 'ชนิดที่ไม่อยู่ในรายการกลางอาจเป็นส่วนเสริมใหม่หรือข้อมูลสะกดผิด ควรตรวจแทนการแก้อัตโนมัติ',
            'help_en' => 'A type outside the central list may be a new integration or a typo and should be reviewed rather than automatically rewritten.',
            'skip' => !$txSchemaReady,
            'skip_reason' => 'transactions_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM transactions WHERE TRIM(COALESCE(type,''))<>'' AND LOWER(TRIM(type)) NOT IN ({$knownTypesSql})",
            'rows_sql' => "SELECT id AS transaction_id,user_id,COALESCE(type,'') AS stored_type,COALESCE(amount,0) AS amount,COALESCE(status,'') AS status,LEFT(COALESCE(description,''),240) AS description,COALESCE(created_at,'') AS created_at FROM transactions WHERE TRIM(COALESCE(type,''))<>'' AND LOWER(TRIM(type)) NOT IN ({$knownTypesSql}) ORDER BY id DESC LIMIT {$sampleLimit}",
        ]);

        if ($txSchemaReady) {
            $inferenceCase = transactionIntegrityInferenceCaseSql('t');
            commerceConsistencyAddCheck($report, [
                'code' => 'transactions_type_repair_candidates',
                'severity' => 'warning',
                'title_th' => 'Transaction มีหลักฐานว่าประเภทที่เก็บไว้ไม่ถูกต้อง',
                'title_en' => 'Transactions whose stored type conflicts with strong evidence',
                'help_th' => 'Order transaction_id, คีย์ต้นทาง หรือรูปแบบคำอธิบายเฉพาะสามารถระบุชนิดที่ถูกต้องได้ รายการนี้ซ่อมผ่านหน้า Transaction Repair แบบมี Backup',
                'help_en' => 'Authoritative order links, source keys, or strong description patterns identify the correct type. Use Transaction Repair to back up and repair these rows.',
                'count_sql' => "SELECT COUNT(*) AS c FROM transactions t WHERE ({$inferenceCase})<>'' AND LOWER(TRIM(COALESCE(t.type,'')))<>({$inferenceCase})",
                'rows_sql' => "SELECT t.id AS transaction_id,t.user_id,COALESCE(t.type,'') AS stored_type,({$inferenceCase}) AS inferred_type,COALESCE(t.amount,0) AS amount,COALESCE(t.status,'') AS status,LEFT(COALESCE(t.description,''),240) AS description,COALESCE(t.reference_id,0) AS reference_id,COALESCE(t.created_at,'') AS created_at FROM transactions t WHERE ({$inferenceCase})<>'' AND LOWER(TRIM(COALESCE(t.type,'')))<>({$inferenceCase}) ORDER BY t.id DESC LIMIT {$sampleLimit}",
            ]);
        }

        $batchCols = commerceConsistencyColumns('transaction_type_repair_batches');
        commerceConsistencyAddCheck($report, [
            'code' => 'transaction_type_failed_repair_batches',
            'severity' => 'error',
            'title_th' => 'มี Batch ซ่อมชนิด Transaction ที่ล้มเหลว',
            'title_en' => 'Failed transaction type repair batches',
            'help_th' => 'ตรวจ error_message และ Batch ID ก่อนลองซ้ำ ระบบจะไม่ลบ Backup Log เดิม',
            'help_en' => 'Review the error message and batch ID before retrying. Existing backup logs are preserved.',
            'skip' => !commerceContextHasColumns($batchCols, ['batch_id', 'status', 'error_message']),
            'skip_reason' => 'repair_batch_table_not_created_yet',
            'count_sql' => "SELECT COUNT(*) AS c FROM transaction_type_repair_batches WHERE LOWER(TRIM(COALESCE(status,'')))='failed'",
            'rows_sql' => "SELECT batch_id,admin_user_id,candidate_count,repaired_count,status,error_message,created_at,completed_at FROM transaction_type_repair_batches WHERE LOWER(TRIM(COALESCE(status,'')))='failed' ORDER BY id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyRunRemoteSource($report, [
            'source' => 'cgo',
            'label_th' => 'CGO',
            'label_en' => 'CGO',
            'orders' => 'cgo_orders',
            'keys' => 'cgo_order_keys',
            'product_table' => 'cgo_products',
            'product_column' => 'cgo_product_id',
            'transaction_type' => 'cgo_purchase',
        ], $sampleLimit);
        commerceConsistencyRunRemoteSource($report, [
            'source' => 'supplier',
            'label_th' => 'Supplier / Store Bridge',
            'label_en' => 'Supplier / Store Bridge',
            'orders' => 'supplier_orders',
            'keys' => 'supplier_order_keys',
            'product_table' => 'supplier_products',
            'product_column' => 'supplier_product_id',
            'transaction_type' => 'supplier_purchase',
            'exclude_store_api_procurement' => true,
        ], $sampleLimit);

        $txCols = commerceConsistencyColumns('transactions');
        $keyCols = commerceConsistencyColumns('keys');
        $userCols = commerceConsistencyColumns('users');
        $productCols = commerceConsistencyColumns('products');
        $variantCols = commerceConsistencyColumns('product_variants');
        $localReady = commerceContextHasColumns($txCols, ['id', 'type', 'status', 'reference_id', 'user_id'])
            && commerceContextHasColumns($keyCols, ['id']);
        $localTxAmount = !empty($txCols['amount']) ? 'COALESCE(t.amount,0)' : '0';
        $localTxTime = !empty($txCols['created_at']) ? "COALESCE(t.created_at,'')" : "''";

        commerceConsistencyAddCheck($report, [
            'code' => 'local_transaction_missing_key',
            'severity' => 'warning',
            'title_th' => 'Transaction เก่าหาคีย์ต้นทางไม่พบ',
            'title_en' => 'Legacy local transactions whose source key is no longer present',
            'help_th' => 'มักเกิดจากคีย์ที่ใช้ไม่ได้ถูกลบในระบบรุ่นเก่า Transaction และยอดเงินยังเป็นหลักฐานจริง จึงไม่ควรสร้างคีย์ย้อนหลังแบบเดา',
            'help_en' => 'This commonly reflects keys intentionally removed by an older build. The transaction remains authoritative and the key must not be recreated from guesses.',
            'skip' => !$localReady,
            'skip_reason' => 'local_transaction_or_key_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM transactions t LEFT JOIN `keys` k ON k.id=t.reference_id WHERE LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed' AND COALESCE(t.reference_id,0)>0 AND k.id IS NULL",
            'rows_sql' => "SELECT t.id AS transaction_id,t.user_id,t.reference_id,{$localTxAmount} AS amount,t.status,{$localTxTime} AS created_at FROM transactions t LEFT JOIN `keys` k ON k.id=t.reference_id WHERE LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed' AND COALESCE(t.reference_id,0)>0 AND k.id IS NULL ORDER BY t.id DESC LIMIT {$sampleLimit}",
        ]);

        $ownerParts = [];
        if (!empty($keyCols['purchased_by'])) $ownerParts[] = 'NULLIF(k.purchased_by,0)';
        if (!empty($keyCols['assigned_to'])) $ownerParts[] = 'NULLIF(k.assigned_to,0)';
        $ownerExpr = $ownerParts ? 'COALESCE(' . implode(',', $ownerParts) . ',0)' : '0';
        $soldConditions = [];
        if ($ownerExpr !== '0') $soldConditions[] = $ownerExpr . '>0';
        if (!empty($keyCols['sold_at'])) $soldConditions[] = 'k.sold_at IS NOT NULL';
        $soldWhere = $soldConditions ? '(' . implode(' OR ', $soldConditions) . ')' : '1=0';

        // A local inventory key sold to an external Store API client is expected
        // to have no user transaction and no assigned user. Exclude that factual
        // provider-sale path so the checker does not manufacture false warnings.
        $providerOrderColsForLocal = commerceConsistencyColumns('store_api_orders');
        $providerKeyColsForLocal = commerceConsistencyColumns('store_api_order_keys');
        $providerSoldExclusion = '';
        if (commerceContextHasColumns($providerOrderColsForLocal, ['id', 'status'])
            && commerceContextHasColumns($providerKeyColsForLocal, ['order_id', 'source_key_id'])) {
            $providerSuccessForLocal = commerceSuccessfulStatusSql('provider_order');
            $providerSoldExclusion = " AND NOT EXISTS (
                SELECT 1 FROM store_api_order_keys provider_key
                JOIN store_api_orders provider_order ON provider_order.id=provider_key.order_id
                WHERE provider_key.source_key_id=k.id AND {$providerSuccessForLocal}
            )";
        }

        commerceConsistencyAddCheck($report, [
            'code' => 'local_sold_key_missing_transaction',
            'severity' => 'warning',
            'title_th' => 'คีย์ถูกขายแล้วแต่ไม่พบ Transaction ซื้อสำเร็จ',
            'title_en' => 'Sold local keys with no completed purchase transaction',
            'help_th' => 'อาจเป็นข้อมูลเก่าก่อนมี Transaction Logs หรือเป็นรายการที่ถูกลบ/เชื่อมผิด',
            'help_en' => 'This can be legacy data from before transaction logging, or a deleted/mislinked transaction.',
            'skip' => !$localReady || $soldWhere === '1=0',
            'skip_reason' => 'local_owner_or_sold_marker_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `keys` k WHERE {$soldWhere} AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.reference_id=k.id AND LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed'){$providerSoldExclusion}",
            'rows_sql' => "SELECT k.id AS key_id,{$ownerExpr} AS owner_user_id," . (!empty($keyCols['product_id']) ? 'COALESCE(k.product_id,0)' : '0') . " AS product_id," . (!empty($keyCols['variant_id']) ? 'COALESCE(k.variant_id,0)' : '0') . " AS variant_id," . (!empty($keyCols['sold_at']) ? "COALESCE(k.sold_at,'')" : "''") . " AS sold_at FROM `keys` k WHERE {$soldWhere} AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.reference_id=k.id AND LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed'){$providerSoldExclusion} ORDER BY k.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'local_key_missing_owner',
            'severity' => 'error',
            'title_th' => 'คีย์ถูกขายแล้วแต่ไม่พบเจ้าของบัญชี',
            'title_en' => 'Sold local keys with a missing owner account',
            'help_th' => 'คีย์อ้าง User ID ที่ไม่มี ทำให้ My Keys, Logs และการตรวจสิทธิ์หาเจ้าของไม่ได้',
            'help_en' => 'The key references a missing user, preventing ownership and entitlement checks.',
            'skip' => !$localReady || $ownerExpr === '0' || !commerceContextHasColumns($userCols, ['id']),
            'skip_reason' => 'local_owner_or_users_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `keys` k LEFT JOIN users u ON u.id={$ownerExpr} WHERE {$ownerExpr}>0 AND u.id IS NULL",
            'rows_sql' => "SELECT k.id AS key_id,{$ownerExpr} AS owner_user_id," . (!empty($keyCols['product_id']) ? 'COALESCE(k.product_id,0)' : '0') . " AS product_id," . (!empty($keyCols['sold_at']) ? "COALESCE(k.sold_at,'')" : "''") . " AS sold_at FROM `keys` k LEFT JOIN users u ON u.id={$ownerExpr} WHERE {$ownerExpr}>0 AND u.id IS NULL ORDER BY k.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'local_variant_product_mismatch',
            'severity' => 'error',
            'title_th' => 'คีย์อ้าง Variant ของสินค้าคนละรายการ',
            'title_en' => 'Local keys linked to a variant from another product',
            'help_th' => 'ทำให้ชื่อสินค้า ระยะเวลา ราคา และรายงานต่างหน้าดึงข้อมูลไม่ตรงกัน',
            'help_en' => 'This causes product name, duration, price, and reporting to disagree between pages.',
            'skip' => !$localReady || !commerceContextHasColumns($keyCols, ['product_id', 'variant_id']) || !commerceContextHasColumns($variantCols, ['id', 'product_id']),
            'skip_reason' => 'variant_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM `keys` k JOIN product_variants pv ON pv.id=k.variant_id WHERE COALESCE(k.variant_id,0)>0 AND pv.product_id<>k.product_id",
            'rows_sql' => "SELECT k.id AS key_id,k.product_id,k.variant_id,pv.product_id AS variant_product_id FROM `keys` k JOIN product_variants pv ON pv.id=k.variant_id WHERE COALESCE(k.variant_id,0)>0 AND pv.product_id<>k.product_id ORDER BY k.id DESC LIMIT {$sampleLimit}",
        ]);

        $eventCols = commerceConsistencyColumns('purchase_activity_events');
        $eventReady = commerceContextHasColumns($eventCols, ['id', 'user_id', 'source', 'first_transaction_id', 'last_transaction_id', 'quantity'])
            && commerceContextHasColumns($txCols, ['id', 'user_id', 'type', 'status']);
        $eventTime = !empty($eventCols['created_at']) ? "COALESCE(e.created_at,'')" : "''";
        commerceConsistencyAddCheck($report, [
            'code' => 'purchase_event_invalid_transaction_range',
            'severity' => 'error',
            'title_th' => 'Purchase Activity Event อ้างช่วง Transaction ไม่ถูกต้อง',
            'title_en' => 'Purchase activity events with an invalid transaction range',
            'help_th' => 'Feed และ History ใช้ช่วง Transaction นี้รวมการซื้อหนึ่งครั้ง หากช่วงผิด รายการอาจหายหรือรวมผิด',
            'help_en' => 'Feed and History use this range to identify one checkout; an invalid range can hide or merge orders.',
            'skip' => !$eventReady,
            'skip_reason' => 'purchase_activity_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM purchase_activity_events e LEFT JOIN transactions first_tx ON first_tx.id=e.first_transaction_id LEFT JOIN transactions last_tx ON last_tx.id=e.last_transaction_id WHERE LOWER(TRIM(COALESCE(e.source,'')))='local' AND (e.first_transaction_id<1 OR e.last_transaction_id<e.first_transaction_id OR first_tx.id IS NULL OR last_tx.id IS NULL OR first_tx.user_id<>e.user_id OR last_tx.user_id<>e.user_id)",
            'rows_sql' => "SELECT e.id AS event_id,e.user_id,e.first_transaction_id,e.last_transaction_id,e.quantity,COALESCE(first_tx.user_id,0) AS first_transaction_user,COALESCE(last_tx.user_id,0) AS last_transaction_user,{$eventTime} AS created_at FROM purchase_activity_events e LEFT JOIN transactions first_tx ON first_tx.id=e.first_transaction_id LEFT JOIN transactions last_tx ON last_tx.id=e.last_transaction_id WHERE LOWER(TRIM(COALESCE(e.source,'')))='local' AND (e.first_transaction_id<1 OR e.last_transaction_id<e.first_transaction_id OR first_tx.id IS NULL OR last_tx.id IS NULL OR first_tx.user_id<>e.user_id OR last_tx.user_id<>e.user_id) ORDER BY e.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'purchase_event_overlapping_transaction_range',
            'severity' => 'error',
            'title_th' => 'Purchase Activity Event หลายรายการอ้าง Transaction ซ้ำกัน',
            'title_en' => 'Purchase activity events with overlapping transaction ranges',
            'help_th' => 'Transaction เดียวไม่ควรถูกนับเป็นสอง Checkout เพราะจะทำให้ Feed และ History แสดงยอดหรือจำนวนคีย์ซ้ำ',
            'help_en' => 'A transaction must not belong to two checkouts, or the feed and history can double-count keys and amounts.',
            'skip' => !$eventReady,
            'skip_reason' => 'purchase_activity_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM purchase_activity_events e1 JOIN purchase_activity_events e2 ON e1.user_id=e2.user_id AND e1.id<e2.id AND LOWER(TRIM(COALESCE(e1.source,'')))='local' AND LOWER(TRIM(COALESCE(e2.source,'')))='local' AND e1.first_transaction_id<=e2.last_transaction_id AND e2.first_transaction_id<=e1.last_transaction_id",
            'rows_sql' => "SELECT e1.id AS first_event_id,e2.id AS second_event_id,e1.user_id,e1.first_transaction_id AS first_start,e1.last_transaction_id AS first_end,e2.first_transaction_id AS second_start,e2.last_transaction_id AS second_end FROM purchase_activity_events e1 JOIN purchase_activity_events e2 ON e1.user_id=e2.user_id AND e1.id<e2.id AND LOWER(TRIM(COALESCE(e1.source,'')))='local' AND LOWER(TRIM(COALESCE(e2.source,'')))='local' AND e1.first_transaction_id<=e2.last_transaction_id AND e2.first_transaction_id<=e1.last_transaction_id ORDER BY e2.id DESC,e1.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'purchase_event_quantity_mismatch',
            'severity' => 'warning',
            'title_th' => 'Purchase Activity Event มีจำนวนไม่ตรงกับ Transaction จริง',
            'title_en' => 'Purchase activity event quantity mismatch',
            'help_th' => 'อาจทำให้ Feed แสดง ×จำนวน และ History รวมคีย์ไม่ตรงกับการซื้อจริง',
            'help_en' => 'This can make the feed quantity and grouped history disagree with the real checkout.',
            'skip' => !$eventReady,
            'skip_reason' => 'purchase_activity_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM purchase_activity_events e WHERE LOWER(TRIM(COALESCE(e.source,'')))='local' AND (SELECT COUNT(*) FROM transactions t WHERE t.user_id=e.user_id AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id AND LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed')<>e.quantity",
            'rows_sql' => "SELECT e.id AS event_id,e.user_id,e.first_transaction_id,e.last_transaction_id,e.quantity,(SELECT COUNT(*) FROM transactions t WHERE t.user_id=e.user_id AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id AND LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed') AS transaction_count,{$eventTime} AS created_at FROM purchase_activity_events e WHERE LOWER(TRIM(COALESCE(e.source,'')))='local' AND (SELECT COUNT(*) FROM transactions t WHERE t.user_id=e.user_id AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id AND LOWER(TRIM(COALESCE(t.type,'')))='purchase' AND LOWER(TRIM(COALESCE(t.status,'')))='completed')<>e.quantity ORDER BY e.id DESC LIMIT {$sampleLimit}",
        ]);

        $providerOrderCols = commerceConsistencyColumns('store_api_orders');
        $providerKeyCols = commerceConsistencyColumns('store_api_order_keys');
        $providerReady = commerceContextHasColumns($providerOrderCols, ['id', 'client_id', 'status', 'quantity'])
            && commerceContextHasColumns($providerKeyCols, ['id', 'order_id', 'source_key_id']);
        $providerSourceAware = $providerReady
            && commerceContextHasColumns($providerKeyCols, ['source_type', 'source_order_id']);
        $cgoOrderColsForProvider = commerceConsistencyColumns('cgo_orders');
        $providerCgoSourceReady = $providerSourceAware
            && commerceContextHasColumns($cgoOrderColsForProvider, ['id', 'source_kind', 'source_order_id']);
        $supplierOrderColsForProvider = commerceConsistencyColumns('supplier_orders');
        $providerSupplierSourceReady = $providerSourceAware
            && commerceContextHasColumns($supplierOrderColsForProvider, ['id', 'source_kind', 'source_order_id']);
        $providerSuccess = commerceSuccessfulStatusSql('o');
        $providerKeyCount = '(SELECT COUNT(*) FROM store_api_order_keys ok WHERE ok.order_id=o.id)';
        $providerExternal = !empty($providerOrderCols['external_ref']) ? "COALESCE(o.external_ref,'')" : "''";
        $providerTimeParts = [];
        if (!empty($providerOrderCols['completed_at'])) $providerTimeParts[] = 'o.completed_at';
        if (!empty($providerOrderCols['created_at'])) $providerTimeParts[] = 'o.created_at';
        $providerTime = $providerTimeParts ? 'COALESCE(' . implode(',', $providerTimeParts) . ')' : "''";
        $providerKeyTime = !empty($providerKeyCols['created_at']) ? "COALESCE(ok.created_at,'')" : "''";
        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_client_key_count_mismatch',
            'severity' => 'error',
            'title_th' => 'Store API Client จำนวนคีย์ไม่ตรงกับ Order',
            'title_en' => 'Store API client delivered key count mismatch',
            'help_th' => 'Order ที่เว็บอื่นซื้อจากเรา ต้องได้รับคีย์ครบตาม quantity',
            'help_en' => 'An external client order must receive exactly the requested number of keys.',
            'skip' => !$providerReady,
            'skip_reason' => 'store_api_provider_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_orders o WHERE {$providerSuccess} AND {$providerKeyCount}<>o.quantity",
            'rows_sql' => "SELECT o.id AS order_id,o.client_id,{$providerExternal} AS external_ref,o.status,o.quantity,{$providerKeyCount} AS key_count,{$providerTime} AS order_time FROM store_api_orders o WHERE {$providerSuccess} AND {$providerKeyCount}<>o.quantity ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_client_missing_source_key',
            'severity' => 'error',
            'title_th' => 'Store API Local Key อ้างคีย์ต้นทางที่ไม่มีแล้ว',
            'title_en' => 'Store API local deliveries with a missing source key',
            'help_th' => 'ตรวจเฉพาะคีย์ที่ source_type=local; คีย์จาก CGO ใช้ source_order_id และไม่ควรถูกนับเป็นคีย์ Local ที่หาย',
            'help_en' => 'Only local deliveries require source_key_id. CGO deliveries use source_order_id and must not be reported as missing local keys.',
            'skip' => !$providerSourceAware || !commerceContextHasColumns($keyCols, ['id']),
            'skip_reason' => 'store_api_source_or_local_key_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_order_keys ok LEFT JOIN `keys` k ON k.id=ok.source_key_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'local')))='local' AND (ok.source_key_id IS NULL OR k.id IS NULL)",
            'rows_sql' => "SELECT ok.id AS order_key_id,ok.order_id,ok.source_type,ok.source_key_id,{$providerKeyTime} AS created_at FROM store_api_order_keys ok LEFT JOIN `keys` k ON k.id=ok.source_key_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'local')))='local' AND (ok.source_key_id IS NULL OR k.id IS NULL) ORDER BY ok.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_client_invalid_cgo_source_link',
            'severity' => 'error',
            'title_th' => 'Store API CGO Key เชื่อม Order ต้นทางไม่ถูกต้อง',
            'title_en' => 'Store API CGO deliveries with an invalid source-order link',
            'help_th' => 'คีย์จาก CGO ต้องอ้าง cgo_orders ผ่าน source_order_id และ cgo_orders ต้องชี้กลับมายัง Store API Order เดียวกัน',
            'help_en' => 'A CGO delivery must reference cgo_orders through source_order_id, and that CGO order must point back to the same Store API order.',
            'skip' => !$providerCgoSourceReady,
            'skip_reason' => 'store_api_or_cgo_source_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_order_keys ok LEFT JOIN cgo_orders co ON co.id=ok.source_order_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'')))='cgo' AND (ok.source_order_id IS NULL OR co.id IS NULL OR LOWER(TRIM(COALESCE(co.source_kind,'')))<>'store_api' OR COALESCE(co.source_order_id,0)<>ok.order_id)",
            'rows_sql' => "SELECT ok.id AS order_key_id,ok.order_id,ok.source_type,ok.source_order_id,COALESCE(co.id,0) AS cgo_order_id,COALESCE(co.source_kind,'') AS cgo_source_kind,COALESCE(co.source_order_id,0) AS cgo_parent_order_id,{$providerKeyTime} AS created_at FROM store_api_order_keys ok LEFT JOIN cgo_orders co ON co.id=ok.source_order_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'')))='cgo' AND (ok.source_order_id IS NULL OR co.id IS NULL OR LOWER(TRIM(COALESCE(co.source_kind,'')))<>'store_api' OR COALESCE(co.source_order_id,0)<>ok.order_id) ORDER BY ok.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_client_invalid_supplier_source_link',
            'severity' => 'error',
            'title_th' => 'Store API Supplier Key เชื่อม Order ต้นทางไม่ถูกต้อง',
            'title_en' => 'Store API supplier deliveries with an invalid source-order link',
            'help_th' => 'คีย์จาก Store Bridge Supplier ต้องอ้าง supplier_orders ผ่าน source_order_id และ supplier order ต้องชี้กลับ Store API Order เดียวกัน',
            'help_en' => 'A Store Bridge supplier delivery must reference supplier_orders through source_order_id, and that supplier order must point back to the same Store API order.',
            'skip' => !$providerSupplierSourceReady,
            'skip_reason' => 'store_api_or_supplier_source_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_order_keys ok LEFT JOIN supplier_orders so ON so.id=ok.source_order_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'')))='supplier' AND (ok.source_order_id IS NULL OR so.id IS NULL OR LOWER(TRIM(COALESCE(so.source_kind,'')))<>'store_api' OR COALESCE(so.source_order_id,0)<>ok.order_id)",
            'rows_sql' => "SELECT ok.id AS order_key_id,ok.order_id,ok.source_type,ok.source_order_id,COALESCE(so.id,0) AS supplier_order_id,COALESCE(so.source_kind,'') AS supplier_source_kind,COALESCE(so.source_order_id,0) AS supplier_parent_order_id,{$providerKeyTime} AS created_at FROM store_api_order_keys ok LEFT JOIN supplier_orders so ON so.id=ok.source_order_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'')))='supplier' AND (ok.source_order_id IS NULL OR so.id IS NULL OR LOWER(TRIM(COALESCE(so.source_kind,'')))<>'store_api' OR COALESCE(so.source_order_id,0)<>ok.order_id) ORDER BY ok.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_client_missing_client',
            'severity' => 'error',
            'title_th' => 'Store API Order ไม่พบ API Client เจ้าของรายการ',
            'title_en' => 'Store API orders with a missing client',
            'help_th' => 'Order อ้าง client_id ที่ไม่มี ทำให้ยอดคงเหลือและเจ้าของคำสั่งซื้อเชื่อมไม่ได้',
            'help_en' => 'The order references a missing client, breaking owner and balance attribution.',
            'skip' => !$providerReady || !commerceContextHasColumns(commerceConsistencyColumns('store_api_clients'), ['id']),
            'skip_reason' => 'store_api_client_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_orders o LEFT JOIN store_api_clients c ON c.id=o.client_id WHERE c.id IS NULL",
            'rows_sql' => "SELECT o.id AS order_id,o.client_id,{$providerExternal} AS external_ref,o.status,o.quantity,{$providerTime} AS created_at FROM store_api_orders o LEFT JOIN store_api_clients c ON c.id=o.client_id WHERE c.id IS NULL ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        $providerBillingReady = $providerReady
            && commerceContextHasColumns($providerOrderCols, ['billing_mode', 'billing_user_id', 'billing_transaction_id', 'total_price', 'balance_before', 'balance_after'])
            && commerceContextHasColumns($txCols, ['id', 'user_id', 'type', 'amount', 'status'])
            && commerceContextHasColumns($userCols, ['id']);
        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_reseller_wallet_invalid_transaction_link',
            'severity' => 'error',
            'title_th' => 'Store API แบบหักบัญชีตัวแทนเชื่อม Transaction ไม่ครบ',
            'title_en' => 'Reseller-wallet Store API orders with invalid transaction evidence',
            'help_th' => 'Order ที่สำเร็จต้องมี Transaction เพียงหนึ่งรายการของบัญชีผู้จ่าย ยอดต้องตรง สถานะ completed และชนิดเป็น store_api_purchase',
            'help_en' => 'A successful reseller-wallet order must link to one completed store_api_purchase transaction owned by the payer for the exact order total.',
            'skip' => !$providerBillingReady,
            'skip_reason' => 'store_api_billing_or_transaction_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_orders o LEFT JOIN transactions t ON t.id=o.billing_transaction_id LEFT JOIN users u ON u.id=o.billing_user_id WHERE {$providerSuccess} AND LOWER(TRIM(COALESCE(o.billing_mode,'')))='reseller_wallet' AND (COALESCE(o.billing_user_id,0)<1 OR COALESCE(o.billing_transaction_id,0)<1 OR u.id IS NULL OR t.id IS NULL OR t.user_id<>o.billing_user_id OR LOWER(TRIM(COALESCE(t.status,'')))<>'completed' OR LOWER(TRIM(COALESCE(t.type,'')))<>'store_api_purchase' OR ABS(COALESCE(t.amount,0)-COALESCE(o.total_price,0))>0.01)",
            'rows_sql' => "SELECT o.id AS order_id,o.client_id,o.billing_user_id,o.billing_transaction_id,{$providerExternal} AS external_ref,o.total_price,COALESCE(t.user_id,0) AS transaction_user_id,COALESCE(t.type,'') AS transaction_type,COALESCE(t.status,'') AS transaction_status,COALESCE(t.amount,0) AS transaction_amount,{$providerTime} AS order_time FROM store_api_orders o LEFT JOIN transactions t ON t.id=o.billing_transaction_id LEFT JOIN users u ON u.id=o.billing_user_id WHERE {$providerSuccess} AND LOWER(TRIM(COALESCE(o.billing_mode,'')))='reseller_wallet' AND (COALESCE(o.billing_user_id,0)<1 OR COALESCE(o.billing_transaction_id,0)<1 OR u.id IS NULL OR t.id IS NULL OR t.user_id<>o.billing_user_id OR LOWER(TRIM(COALESCE(t.status,'')))<>'completed' OR LOWER(TRIM(COALESCE(t.type,'')))<>'store_api_purchase' OR ABS(COALESCE(t.amount,0)-COALESCE(o.total_price,0))>0.01) ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        $walletCols = commerceConsistencyColumns('wallet_balance_ledger');
        $providerWalletAuditReady = $providerBillingReady && commerceContextHasColumns($walletCols, [
            'id', 'event_key', 'user_id', 'delta_amount', 'balance_before', 'balance_after', 'source_type', 'source_id', 'transaction_id',
        ]);
        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_reseller_wallet_missing_audit_ledger',
            'severity' => 'error',
            'title_th' => 'Store API แบบหักบัญชีตัวแทนมี Wallet Ledger ไม่ตรง Order',
            'title_en' => 'Reseller-wallet Store API orders with inconsistent wallet ledger evidence',
            'help_th' => 'Wallet Ledger ต้องผูก event_key/order/transaction/user เดียวกัน และ before + delta = after โดย delta ต้องเท่ากับ -ยอด Order',
            'help_en' => 'Wallet Ledger must link the same order, transaction and payer, with before + delta = after and delta equal to the negative order total.',
            'skip' => !$providerWalletAuditReady,
            'skip_reason' => 'wallet_ledger_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM store_api_orders o LEFT JOIN wallet_balance_ledger w ON w.event_key=CONCAT('store_api_order:',o.id) WHERE {$providerSuccess} AND LOWER(TRIM(COALESCE(o.billing_mode,'')))='reseller_wallet' AND (w.id IS NULL OR w.user_id<>o.billing_user_id OR LOWER(TRIM(COALESCE(w.source_type,'')))<>'store_api_purchase' OR COALESCE(w.source_id,0)<>o.id OR COALESCE(w.transaction_id,0)<>COALESCE(o.billing_transaction_id,0) OR ABS(COALESCE(w.delta_amount,0)+COALESCE(o.total_price,0))>0.01 OR ABS(COALESCE(w.balance_before,0)-COALESCE(o.balance_before,0))>0.01 OR ABS(COALESCE(w.balance_after,0)-COALESCE(o.balance_after,0))>0.01 OR ABS((COALESCE(w.balance_before,0)+COALESCE(w.delta_amount,0))-COALESCE(w.balance_after,0))>0.01)",
            'rows_sql' => "SELECT o.id AS order_id,o.billing_user_id,o.billing_transaction_id,{$providerExternal} AS external_ref,o.total_price,o.balance_before AS order_balance_before,o.balance_after AS order_balance_after,COALESCE(w.id,0) AS wallet_ledger_id,COALESCE(w.user_id,0) AS wallet_user_id,COALESCE(w.source_type,'') AS wallet_source_type,COALESCE(w.source_id,0) AS wallet_source_id,COALESCE(w.transaction_id,0) AS wallet_transaction_id,COALESCE(w.delta_amount,0) AS wallet_delta,w.balance_before AS wallet_balance_before,w.balance_after AS wallet_balance_after,{$providerTime} AS order_time FROM store_api_orders o LEFT JOIN wallet_balance_ledger w ON w.event_key=CONCAT('store_api_order:',o.id) WHERE {$providerSuccess} AND LOWER(TRIM(COALESCE(o.billing_mode,'')))='reseller_wallet' AND (w.id IS NULL OR w.user_id<>o.billing_user_id OR LOWER(TRIM(COALESCE(w.source_type,'')))<>'store_api_purchase' OR COALESCE(w.source_id,0)<>o.id OR COALESCE(w.transaction_id,0)<>COALESCE(o.billing_transaction_id,0) OR ABS(COALESCE(w.delta_amount,0)+COALESCE(o.total_price,0))>0.01 OR ABS(COALESCE(w.balance_before,0)-COALESCE(o.balance_before,0))>0.01 OR ABS(COALESCE(w.balance_after,0)-COALESCE(o.balance_after,0))>0.01 OR ABS((COALESCE(w.balance_before,0)+COALESCE(w.delta_amount,0))-COALESCE(w.balance_after,0))>0.01) ORDER BY o.id DESC LIMIT {$sampleLimit}",
        ]);

        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_reseller_wallet_duplicate_transaction_link',
            'severity' => 'error',
            'title_th' => 'Store API หลาย Order ใช้ Transaction ตัวแทนรายการเดียวกัน',
            'title_en' => 'Multiple Store API orders share one reseller-wallet transaction',
            'help_th' => 'หนึ่ง billing_transaction_id ต้องเป็นหลักฐานของ Store API Order เดียวเท่านั้น',
            'help_en' => 'One billing_transaction_id must belong to exactly one Store API order.',
            'skip' => !$providerBillingReady,
            'skip_reason' => 'store_api_billing_schema_unavailable',
            'count_sql' => "SELECT COUNT(*) AS c FROM (SELECT billing_transaction_id FROM store_api_orders WHERE LOWER(TRIM(COALESCE(billing_mode,'')))='reseller_wallet' AND COALESCE(billing_transaction_id,0)>0 GROUP BY billing_transaction_id HAVING COUNT(*)>1) duplicates",
            'rows_sql' => "SELECT billing_transaction_id,COUNT(*) AS order_count,GROUP_CONCAT(id ORDER BY id SEPARATOR ',') AS order_ids FROM store_api_orders WHERE LOWER(TRIM(COALESCE(billing_mode,'')))='reseller_wallet' AND COALESCE(billing_transaction_id,0)>0 GROUP BY billing_transaction_id HAVING COUNT(*)>1 ORDER BY billing_transaction_id DESC LIMIT {$sampleLimit}",
        ]);

        $providerOwnershipReady = $providerReady && commerceContextHasColumns($keyCols, ['id', 'assigned_to', 'purchased_by']);
        commerceConsistencyAddCheck($report, [
            'code' => 'store_api_exported_key_has_local_owner',
            'severity' => 'warning',
            'title_th' => 'คีย์ที่ขายผ่าน Store API ยังมี Local Owner',
            'title_en' => 'Store API exported keys still have a local owner',
            'help_th' => 'คีย์ที่ส่งออกผ่าน Store API ไม่ควรถูกเพิ่มซ้ำใน My Keys ของผู้จ่าย เพราะจะทำให้สิทธิ์และรายงานตีความเป็นการซื้อในเว็บอีกครั้ง',
            'help_en' => 'An externally delivered Store API key should not also be owned as a local My Keys entitlement, or the same delivery can be interpreted twice.',
            'skip' => !$providerOwnershipReady,
            'skip_reason' => 'store_api_or_local_key_ownership_schema_unavailable',
            'count_sql' => $providerSourceAware
                ? "SELECT COUNT(*) AS c FROM store_api_order_keys ok JOIN `keys` k ON k.id=ok.source_key_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'local')))='local' AND (k.assigned_to IS NOT NULL OR k.purchased_by IS NOT NULL)"
                : "SELECT COUNT(*) AS c FROM store_api_order_keys ok JOIN `keys` k ON k.id=ok.source_key_id WHERE k.assigned_to IS NOT NULL OR k.purchased_by IS NOT NULL",
            'rows_sql' => $providerSourceAware
                ? "SELECT ok.id AS order_key_id,ok.order_id,ok.source_type,ok.source_key_id,k.assigned_to,k.purchased_by,{$providerKeyTime} AS exported_at FROM store_api_order_keys ok JOIN `keys` k ON k.id=ok.source_key_id WHERE LOWER(TRIM(COALESCE(ok.source_type,'local')))='local' AND (k.assigned_to IS NOT NULL OR k.purchased_by IS NOT NULL) ORDER BY ok.id DESC LIMIT {$sampleLimit}"
                : "SELECT ok.id AS order_key_id,ok.order_id,ok.source_key_id,k.assigned_to,k.purchased_by,{$providerKeyTime} AS exported_at FROM store_api_order_keys ok JOIN `keys` k ON k.id=ok.source_key_id WHERE k.assigned_to IS NOT NULL OR k.purchased_by IS NOT NULL ORDER BY ok.id DESC LIMIT {$sampleLimit}",
        ]);

        $report['healthy'] = $report['summary']['errors'] === 0 && $report['summary']['query_errors'] === 0;
        return $report;
    }
}
