<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';
require_once __DIR__ . '/../includes/wallet_ledger.php';
requireAdmin();

global $conn;
$adminTxPerfStarted = microtime(true);
$adminTxCoreQueryMs = 0;

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string {
    return $isTh ? $th : $en;
};

if (!function_exists('adminTxBindParams')) {
    function adminTxBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '') return true;
        if (strlen($types) !== count($params)) return false;

        $bindArgs = [$types];
        foreach ($params as $index => $_value) {
            $bindArgs[] = &$params[$index];
        }
        return (bool) call_user_func_array([$stmt, 'bind_param'], $bindArgs);
    }
}

if (!function_exists('adminTxNormalizeStatus')) {
    function adminTxNormalizeStatus($status): string
    {
        return strtolower(trim((string) $status));
    }
}

if (!function_exists('adminTxEscapeLike')) {
    /**
     * Escape LIKE wildcard characters so the admin search is literal. Bound
     * parameters prevent injection, but unescaped % and _ would still behave as
     * wildcards and could return unrelated transactions.
     */
    function adminTxEscapeLike(string $value): string
    {
        return strtr($value, [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
        ]);
    }
}

if (!function_exists('adminTxSearchRows')) {
    /**
     * Execute one bounded search query and return rows. Search fan-out is kept
     * outside the main transaction query so each integration table is scanned
     * at most once instead of once per candidate transaction.
     *
     * @return array<int,array<string,mixed>>
     */
    function adminTxSearchRows(mysqli $conn, string $sql, string $types, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Admin transaction search prepare failed: ' . substr((string) $conn->error, 0, 300));
            return [];
        }
        if ($types !== '' && !adminTxBindParams($stmt, $types, $params)) {
            $stmt->close();
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Admin transaction search execute failed: ' . substr((string) $stmt->error, 0, 300));
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('adminTxMaskSensitiveAccount')) {
    function adminTxMaskSensitiveAccount($value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $compact = preg_replace('/\s+/', '', $value) ?? $value;
        $length = function_exists('mb_strlen') ? mb_strlen($compact, 'UTF-8') : strlen($compact);
        if ($length <= 4) return str_repeat('•', max(4, $length));
        $tail = function_exists('mb_substr') ? mb_substr($compact, -4, 4, 'UTF-8') : substr($compact, -4);
        return str_repeat('•', min(8, max(4, $length - 4))) . $tail;
    }
}

if (!function_exists('adminTxFormatAuditTime')) {
    function adminTxFormatAuditTime($value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $timestamp = strtotime($value);
        return $timestamp === false ? substr($value, 0, 80) : date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('adminTxHumanDuration')) {
    function adminTxHumanDuration($from, $to, bool $isTh): string
    {
        $start = strtotime(trim((string) $from));
        $end = strtotime(trim((string) $to));
        if ($start === false || $end === false || $end < $start) return '';
        $seconds = $end - $start;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        $parts = [];
        if ($hours > 0) $parts[] = $hours . ($isTh ? ' ชม.' : 'h');
        if ($minutes > 0 || $hours > 0) $parts[] = $minutes . ($isTh ? ' นาที' : 'm');
        if ($hours === 0) $parts[] = $remaining . ($isTh ? ' วินาที' : 's');
        return implode(' ', $parts);
    }
}

if (!function_exists('adminTxNonRefundWalletRows')) {
    function adminTxNonRefundWalletRows(array $rows): array
    {
        return array_values(array_filter($rows, static function (array $row): bool {
            return !in_array((string) ($row['source_type'] ?? ''), ['cgo_refund', 'supplier_refund'], true);
        }));
    }
}

if (!function_exists('adminTxPurchaseBalanceSummary')) {
    /**
     * Compact purchase balance evidence for the card header. Prefer immutable
     * wallet-ledger rows, then fall back to the Store API order snapshot. Never
     * fabricate a remaining balance for legacy rows that did not record one.
     */
    function adminTxPurchaseBalanceSummary(
        array $walletRows,
        ?float $fallbackBefore = null,
        ?float $fallbackAfter = null,
        ?float $fallbackDebit = null
    ): array {
        $before = null;
        $after = null;
        $debit = 0.0;
        $hasDebit = false;

        foreach ($walletRows as $row) {
            $delta = isset($row['delta_amount']) && is_numeric($row['delta_amount'])
                ? (float) $row['delta_amount']
                : 0.0;
            if ($delta < -0.00001) {
                $debit += abs($delta);
                $hasDebit = true;
            }
            if ($before === null && array_key_exists('balance_before', $row) && $row['balance_before'] !== null && is_numeric($row['balance_before'])) {
                $before = (float) $row['balance_before'];
            }
            if (array_key_exists('balance_after', $row) && $row['balance_after'] !== null && is_numeric($row['balance_after'])) {
                $after = (float) $row['balance_after'];
            }
        }

        if ($before === null && $fallbackBefore !== null) $before = $fallbackBefore;
        if ($after === null && $fallbackAfter !== null) $after = $fallbackAfter;
        if (!$hasDebit && $fallbackDebit !== null && $fallbackDebit >= 0) {
            $debit = $fallbackDebit;
            $hasDebit = true;
        }

        return [
            'available' => $hasDebit && $after !== null,
            'deducted' => $hasDebit ? round($debit, 2) : null,
            'balance_before' => $before === null ? null : round($before, 2),
            'balance_after' => $after === null ? null : round($after, 2),
        ];
    }
}

if (!function_exists('adminTxLocalPurchaseFallback')) {
    function adminTxLocalPurchaseFallback(string $description): array
    {
        $description = trim($description);
        $prefix = 'Purchased ';
        $marker = ' key: ';
        if ($description === '' || stripos($description, $prefix) !== 0) {
            return ['product_name' => '', 'key_code' => ''];
        }

        $markerPos = strripos($description, $marker);
        if ($markerPos === false) {
            return ['product_name' => '', 'key_code' => ''];
        }

        $productName = trim(substr($description, strlen($prefix), $markerPos - strlen($prefix)));
        $keyCode = trim(substr($description, $markerPos + strlen($marker)));
        if (strlen($productName) > 255) $productName = substr($productName, 0, 255);
        if (strlen($keyCode) > 5000) $keyCode = '';

        return ['product_name' => $productName, 'key_code' => $keyCode];
    }
}

if (!function_exists('adminTxStatusLabel')) {
    function adminTxStatusLabel(string $status, bool $isTh): string
    {
        $labels = [
            'completed' => ['สำเร็จ', 'Completed'],
            'success' => ['สำเร็จ', 'Success'],
            'pending' => ['รอดำเนินการ', 'Pending'],
            'processing' => ['กำลังประมวลผล', 'Processing'],
            'submitting' => ['กำลังส่งคำสั่งซื้อ', 'Submitting'],
            'manual_review' => ['ต้องตรวจสอบ', 'Manual review'],
            'unknown' => ['สถานะไม่แน่ชัด', 'Unknown'],
            'failed' => ['ล้มเหลว', 'Failed'],
            'refunded' => ['คืนเงินแล้ว', 'Refunded'],
            'refunded_conflict' => ['คืนเงินแล้วแต่พบข้อมูลขัดแย้ง', 'Refunded with conflict'],
            'cancelled' => ['ยกเลิก', 'Cancelled'],
            'canceled' => ['ยกเลิก', 'Cancelled'],
        ];
        if (isset($labels[$status])) return $labels[$status][$isTh ? 0 : 1];

        return ucfirst(str_replace('_', ' ', $status !== '' ? $status : 'unknown'));
    }
}


if (!function_exists('adminTxTableColumns')) {
    /**
     * Read-only schema inspection for optional integration tables. Load all
     * supported table metadata in one INFORMATION_SCHEMA round trip instead of
     * issuing twenty-plus SHOW COLUMNS queries on every page request.
     *
     * @return array<string, bool>
     */
    function adminTxTableColumns(mysqli $conn, string $table): array
    {
        static $cache = [];
        static $loaded = false;
        static $fallbackAttempted = [];
        $allowed = [
            'transactions', 'users', 'keys', 'products', 'product_variants',
            'cgo_orders', 'cgo_products', 'cgo_order_keys', 'cgo_order_api_attempts',
            'supplier_orders', 'supplier_order_keys', 'supplier_connections', 'supplier_products',
            'store_api_orders', 'store_api_order_keys', 'store_api_clients', 'store_api_balance_ledger',
            'store_api_request_logs', 'store_api_request_diagnostics',
            'purchase_activity_events', 'slip_deposits', 'slip_verification_jobs', 'wallet_balance_ledger',
        ];
        if (!in_array($table, $allowed, true)) return [];

        if (!$loaded) {
            $loaded = true;
            foreach ($allowed as $allowedTable) $cache[$allowedTable] = [];
            $quoted = implode(',', array_map(static fn(string $name): string => "'" . $name . "'", $allowed));
            try {
                $result = $conn->query(
                    "SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$quoted}) "
                    . 'ORDER BY TABLE_NAME,ORDINAL_POSITION'
                );
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $tableName = strtolower(trim((string) ($row['TABLE_NAME'] ?? '')));
                        $columnName = strtolower(trim((string) ($row['COLUMN_NAME'] ?? '')));
                        if (isset($cache[$tableName]) && $columnName !== '') $cache[$tableName][$columnName] = true;
                    }
                    $result->free();
                }
            } catch (Throwable $e) {
                error_log('Admin transaction schema batch inspection failed: ' . $e->getMessage());
            }
        }

        // Conservative fallback for hosts that restrict INFORMATION_SCHEMA.
        if (empty($cache[$table]) && empty($fallbackAttempted[$table])) {
            $fallbackAttempted[$table] = true;
            try {
                $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $name = strtolower(trim((string) ($row['Field'] ?? '')));
                        if ($name !== '') $cache[$table][$name] = true;
                    }
                    $result->free();
                }
            } catch (Throwable $e) {
                error_log('Admin transaction schema inspection failed for ' . $table . ': ' . $e->getMessage());
            }
        }
        return $cache[$table] ?? [];
    }
}

if (!function_exists('adminTxHasColumns')) {
    /** @param array<string, bool> $columns */
    function adminTxHasColumns(array $columns, array $required): bool
    {
        foreach ($required as $column) {
            if (empty($columns[strtolower((string) $column)])) return false;
        }
        return true;
    }
}


if (!function_exists('adminTxStatusMatchesFilter')) {
    function adminTxStatusMatchesFilter(string $status, string $filter): bool
    {
        $status = adminTxNormalizeStatus($status);
        if ($filter === 'all') return true;
        if ($filter === 'completed') return in_array($status, ['completed', 'success'], true);
        if ($filter === 'pending') return in_array($status, ['pending', 'processing', 'submitting'], true);
        if ($filter === 'review') return in_array($status, ['unknown', 'manual_review', 'refunded_conflict'], true);
        if ($filter === 'refunded') return in_array($status, ['refunded', 'refunded_conflict'], true);
        if ($filter === 'cancelled') return in_array($status, ['cancelled', 'canceled'], true);
        return $status === 'failed';
    }
}

if (!function_exists('adminTxExtractApiExternalRef')) {
    function adminTxExtractApiExternalRef(string $description): string
    {
        if (preg_match('/\bCHEATGAME\s+order\s+([A-Za-z0-9][A-Za-z0-9_-]{2,119})\s*:/i', $description, $matches) !== 1) {
            return '';
        }
        return substr((string) $matches[1], 0, 120);
    }
}

if (!function_exists('adminTxExtractStoreBridgeExternalRef')) {
    function adminTxExtractStoreBridgeExternalRef(string $description): string
    {
        if (preg_match('/\bStore\s+Bridge\s+order\s+([A-Za-z0-9][A-Za-z0-9_-]{2,119})\s*:/i', $description, $matches) !== 1) {
            return '';
        }
        return substr((string) $matches[1], 0, 120);
    }
}

if (!function_exists('adminTxExtractResellerApiExternalRef')) {
    function adminTxExtractResellerApiExternalRef(string $description): string
    {
        if (preg_match('/\bStore\s+API\s+reseller\s+wallet\s+order\s+([A-Za-z0-9][A-Za-z0-9._:-]{2,119})\b/i', $description, $matches) !== 1) {
            return '';
        }
        return substr((string) $matches[1], 0, 120);
    }
}

if (!function_exists('adminTxApiSource')) {
    function adminTxApiSource(array $row): string
    {
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $description = (string) ($row['description'] ?? '');
        if ($type === 'store_api_purchase' || adminTxExtractResellerApiExternalRef($description) !== '') return 'reseller_api';
        if ($type === 'supplier_purchase' || adminTxExtractStoreBridgeExternalRef($description) !== '') return 'supplier';
        if ($type === 'cgo_purchase' || adminTxExtractApiExternalRef($description) !== '') return 'cgo';
        return '';
    }
}

if (!function_exists('adminTxEffectiveType')) {
    function adminTxEffectiveType(array $row): string
    {
        if (function_exists('transactionIntegrityEffectiveType')) {
            return transactionIntegrityEffectiveType($row);
        }
        return strtolower(trim((string) ($row['type'] ?? '')));
    }
}

if (!function_exists('adminTxLooksLikeApiPurchase')) {
    function adminTxLooksLikeApiPurchase(array $row): bool
    {
        return adminTxApiSource($row) !== '';
    }
}

if (!function_exists('adminTxSafeReturnQuery')) {
    function adminTxSafeReturnQuery($raw): string
    {
        if (!is_string($raw) || $raw === '') return '';
        $parsed = [];
        parse_str($raw, $parsed);
        $allowed = ['user_id', 'type', 'source', 'status', 'search', 'page'];
        $clean = [];
        foreach ($allowed as $key) {
            if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
            $value = substr(trim((string) $parsed[$key]), 0, 180);
            if ($value === '') continue;
            $clean[$key] = $value;
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}


if (!function_exists('adminTxAuditJsonResponse')) {
    function adminTxAuditJsonResponse(array $payload, int $statusCode = 200): void
    {
        $GLOBALS['adminTxAuditResponseSent'] = true;
        $bufferBase = isset($GLOBALS['adminTxAuditBufferBase']) ? (int) $GLOBALS['adminTxAuditBufferBase'] : -1;
        if ($bufferBase >= 0) {
            while (ob_get_level() > $bufferBase) @ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}

if (!function_exists('adminTxAuditIdList')) {
    /** @return int[] */
    function adminTxAuditIdList($raw, int $limit = 100): array
    {
        if (!is_scalar($raw)) return [];
        $ids = [];
        foreach (preg_split('/[^0-9]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
            $id = (int) $value;
            if ($id > 0) $ids[$id] = $id;
            if (count($ids) >= $limit) break;
        }
        return array_values($ids);
    }
}

if (!function_exists('adminTxAuditDecodeJson')) {
    function adminTxAuditDecodeJson($raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return [
                'present' => false,
                'valid_json' => null,
                'length' => 0,
                'sha256' => null,
                'parsed' => null,
                'raw' => null,
                'json_error' => null,
            ];
        }

        $decoded = json_decode($raw, true);
        $valid = json_last_error() === JSON_ERROR_NONE;
        return [
            'present' => true,
            'valid_json' => $valid,
            'length' => strlen($raw),
            'sha256' => hash('sha256', $raw),
            'parsed' => $valid ? $decoded : null,
            // Preserve invalid/non-JSON provider responses because Cloudflare,
            // proxies and upstream errors frequently return useful plain text/HTML.
            'raw' => $valid ? null : $raw,
            'json_error' => $valid ? null : json_last_error_msg(),
        ];
    }
}

if (!function_exists('adminTxAuditAttemptDiagnostics')) {
    function adminTxAuditAttemptDiagnostics($raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['_decode_error' => json_last_error_msg()];
    }
}

if (!function_exists('adminTxAuditPublicCgoAttempt')) {
    function adminTxAuditPublicCgoAttempt(array $row): array
    {
        $diagnostics = adminTxAuditAttemptDiagnostics($row['diagnostics_json'] ?? '');
        unset($row['diagnostics_json']);
        foreach (['id','order_id','http_code','transport_error','curl_errno','delivered_key_count',
                  'request_started_at_ms','request_finished_at_ms','namelookup_time_ms','connect_time_ms',
                  'appconnect_time_ms','pretransfer_time_ms','starttransfer_time_ms','total_time_ms'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int) $row[$key];
        }
        $row['diagnostics'] = $diagnostics;
        return $row;
    }
}

if (!function_exists('adminTxAuditSyncDiagnostics')) {
    /**
     * Build a read-only snapshot explaining how the locally cached supplier
     * product compares with the raw provider payload captured by the last sync.
     * This never contacts a provider and never mutates stock or prices.
     */
    function adminTxAuditSyncDiagnostics(string $source, ?array $productRow, ?array $payloadEvidence, ?array $localVariant): ?array
    {
        if (!is_array($productRow) && !is_array($localVariant)) return null;
        $parsed = is_array($payloadEvidence) && !empty($payloadEvidence['valid_json']) && is_array($payloadEvidence['parsed'] ?? null)
            ? $payloadEvidence['parsed']
            : null;

        $providerStock = is_array($parsed) && array_key_exists('stock', $parsed) && is_numeric($parsed['stock'])
            ? (int) $parsed['stock']
            : null;
        $localRemoteStock = is_array($productRow) && array_key_exists('remote_stock', $productRow) && is_numeric($productRow['remote_stock'])
            ? (int) $productRow['remote_stock']
            : (is_array($productRow) && array_key_exists('stock', $productRow) && is_numeric($productRow['stock']) ? (int) $productRow['stock'] : null);
        $providerStatus = is_array($parsed) && array_key_exists('status', $parsed) ? (string) $parsed['status'] : null;
        $localRemoteStatus = is_array($productRow)
            ? (array_key_exists('remote_status', $productRow) ? (string) $productRow['remote_status'] : (array_key_exists('status', $productRow) ? (string) $productRow['status'] : null))
            : null;

        $diag = [
            'source' => $source,
            'snapshot_only' => true,
            'network_request_performed' => false,
            'product_identity' => [
                'local_cache_id' => is_array($productRow) && isset($productRow['id']) ? (int) $productRow['id'] : null,
                'remote_product_id' => is_array($productRow) ? ($productRow['remote_product_id'] ?? null) : null,
                'name' => is_array($productRow) ? ($productRow['name'] ?? null) : null,
            ],
            'stock' => [
                'local_cached_remote_stock' => $localRemoteStock,
                'raw_payload_stock' => $providerStock,
                'delta_local_minus_payload' => ($localRemoteStock !== null && $providerStock !== null) ? $localRemoteStock - $providerStock : null,
                'note' => 'A non-zero delta is diagnostic evidence, not automatically a sync defect; accepted orders may adjust the local estimate before the next full provider payload refresh.',
            ],
            'status' => [
                'local_cached_remote_status' => $localRemoteStatus,
                'raw_payload_status' => $providerStatus,
                'matches' => ($localRemoteStatus === null || $providerStatus === null) ? null : strtolower(trim($localRemoteStatus)) === strtolower(trim($providerStatus)),
            ],
            'pricing' => [
                'local_cost_base' => is_array($productRow) ? ($productRow['cost_base'] ?? ($productRow['cost_price'] ?? null)) : null,
                'local_user_price_base' => is_array($productRow) ? ($productRow['user_price_base'] ?? null) : null,
                'provider_price' => is_array($parsed) ? ($parsed['price'] ?? null) : null,
                'provider_price_usd' => is_array($parsed) ? ($parsed['price_usd'] ?? null) : null,
                'provider_price_idr' => is_array($parsed) ? ($parsed['price_idr'] ?? null) : null,
                'provider_exchange_rate' => is_array($parsed) ? ($parsed['exchange_rate'] ?? null) : null,
                'local_variant_price_user' => is_array($localVariant) ? ($localVariant['price_user'] ?? null) : null,
                'local_variant_price_reseller' => is_array($localVariant) ? ($localVariant['price_reseller'] ?? null) : null,
                'local_variant_cost_price' => is_array($localVariant) ? ($localVariant['cost_price'] ?? null) : null,
            ],
            'freshness' => [
                'product_row_updated_at' => is_array($productRow) ? ($productRow['updated_at'] ?? null) : null,
                'last_full_sync_at' => is_array($productRow) ? ($productRow['last_synced_at'] ?? null) : null,
                'last_provider_inventory_checked_at' => is_array($productRow) ? ($productRow['inventory_checked_at'] ?? null) : null,
                'local_inventory_adjusted_at' => is_array($productRow) ? ($productRow['local_inventory_adjusted_at'] ?? null) : null,
                'supplier_removed_at' => is_array($productRow) ? ($productRow['supplier_removed_at'] ?? null) : null,
                'local_variant_updated_at' => is_array($localVariant) ? ($localVariant['updated_at'] ?? null) : null,
                'semantics' => 'Provider inventory checks and local post-purchase stock adjustments are tracked separately; a local adjustment does not claim that a provider network refresh occurred.',
            ],
            'payload' => [
                'present' => is_array($payloadEvidence) ? (bool) ($payloadEvidence['present'] ?? false) : false,
                'valid_json' => is_array($payloadEvidence) ? ($payloadEvidence['valid_json'] ?? null) : null,
                'sha256' => is_array($payloadEvidence) ? ($payloadEvidence['sha256'] ?? null) : null,
            ],
        ];
        return $diag;
    }
}

if (!function_exists('adminTxAuditElapsedMs')) {
    function adminTxAuditElapsedMs($start, $end): ?int
    {
        $startTs = strtotime(trim((string) $start));
        $endTs = strtotime(trim((string) $end));
        if ($startTs === false || $endTs === false || $endTs < $startTs) return null;
        return ($endTs - $startTs) * 1000;
    }
}

if (!function_exists('adminTxAuditEpochMsToIso')) {
    function adminTxAuditEpochMsToIso($epochMs): ?string
    {
        if (!is_numeric($epochMs) || (int) $epochMs <= 0) return null;
        $ms = (int) $epochMs;
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;
        return date('Y-m-d\TH:i:s', $seconds) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT) . date('P', $seconds);
    }
}

if (!function_exists('adminTxAuditBuildCgoTimeline')) {
    function adminTxAuditBuildCgoTimeline(?array $order, array $attempts, array $transactions, array $walletRows): array
    {
        $events = [];
        $orderCreatedAt = is_array($order) ? (string) ($order['created_at'] ?? '') : '';
        $checkoutStartedAtMs = null;
        foreach ($attempts as $attempt) {
            $diag = is_array($attempt['diagnostics'] ?? null) ? $attempt['diagnostics'] : [];
            $transport = is_array($diag['transport'] ?? null) ? $diag['transport'] : [];
            $checkoutTiming = is_array($transport['checkout_timing'] ?? null) ? $transport['checkout_timing'] : [];
            $candidate = isset($checkoutTiming['checkout_started_at_ms']) && is_numeric($checkoutTiming['checkout_started_at_ms'])
                ? (int) $checkoutTiming['checkout_started_at_ms'] : 0;
            if ($candidate > 0 && ($checkoutStartedAtMs === null || $candidate < $checkoutStartedAtMs)) $checkoutStartedAtMs = $candidate;
        }
        if ($checkoutStartedAtMs !== null) {
            $events[] = [
                'at' => adminTxAuditEpochMsToIso($checkoutStartedAtMs),
                'at_epoch_ms' => $checkoutStartedAtMs,
                'kind' => 'checkout_started',
                'timing_precision' => 'exact_ms',
                'elapsed_from_checkout_ms' => 0,
                '_sort_ms' => $checkoutStartedAtMs,
            ];
        }
        if ($orderCreatedAt !== '') {
            $events[] = [
                'at' => $orderCreatedAt,
                'kind' => 'order_created',
                'reservation_state' => 'local_order_created',
                'external_ref' => (string) ($order['external_ref'] ?? ''),
                'elapsed_from_order_ms' => 0,
                'timing_precision' => 'database_seconds',
                '_sort_ms' => (strtotime($orderCreatedAt) ?: 0) * 1000,
            ];
        }
        foreach ($attempts as $attempt) {
            $createdAt = (string) ($attempt['created_at'] ?? '');
            $requestStartMs = max(0, (int) ($attempt['request_started_at_ms'] ?? 0));
            $requestFinishMs = max(0, (int) ($attempt['request_finished_at_ms'] ?? 0));
            $diag = is_array($attempt['diagnostics'] ?? null) ? $attempt['diagnostics'] : [];
            $transport = is_array($diag['transport'] ?? null) ? $diag['transport'] : [];
            $checkoutTiming = is_array($transport['checkout_timing'] ?? null) ? $transport['checkout_timing'] : null;
            if ($requestStartMs < 1) $requestStartMs = max(0, (int) ($transport['request_started_at_ms'] ?? 0));
            if ($requestFinishMs < 1) $requestFinishMs = max(0, (int) ($transport['request_finished_at_ms'] ?? 0));
            $at = $requestFinishMs > 0 ? adminTxAuditEpochMsToIso($requestFinishMs) : $createdAt;
            $events[] = [
                'at' => $at,
                'at_epoch_ms' => $requestFinishMs > 0 ? $requestFinishMs : null,
                'request_started_at' => $requestStartMs > 0 ? adminTxAuditEpochMsToIso($requestStartMs) : null,
                'request_finished_at' => $requestFinishMs > 0 ? adminTxAuditEpochMsToIso($requestFinishMs) : null,
                'kind' => 'supplier_api_' . ((string) ($attempt['phase'] ?? 'attempt')),
                'lookup_mode' => $attempt['lookup_mode'] ?? null,
                'http_code' => (int) ($attempt['http_code'] ?? 0),
                'curl_errno' => (int) ($attempt['curl_errno'] ?? 0),
                'transport_error' => !empty($attempt['transport_error']),
                'provider_error_code' => $attempt['provider_error_code'] ?? null,
                'provider_status' => $attempt['provider_status'] ?? null,
                'echoed_external_ref' => $attempt['echoed_external_ref'] ?? null,
                'discovered_supplier_order_id' => $attempt['discovered_supplier_order_id'] ?? null,
                'delivered_key_count' => (int) ($attempt['delivered_key_count'] ?? 0),
                'request_duration_ms' => (int) ($attempt['total_time_ms'] ?? 0),
                'decision' => $attempt['decision'] ?? null,
                'checkout_timing' => $checkoutTiming,
                'timing_precision' => $requestFinishMs > 0 ? 'exact_ms' : 'database_seconds',
                'elapsed_from_checkout_ms' => ($checkoutStartedAtMs !== null && $requestFinishMs > 0) ? max(0, $requestFinishMs - $checkoutStartedAtMs) : null,
                'elapsed_from_order_ms' => $orderCreatedAt !== '' && $createdAt !== '' ? adminTxAuditElapsedMs($orderCreatedAt, $createdAt) : null,
                '_sort_ms' => $requestFinishMs > 0 ? $requestFinishMs : ((strtotime($createdAt) ?: 0) * 1000),
            ];
        }
        foreach ($walletRows as $walletRow) {
            $at = (string) ($walletRow['created_at'] ?? '');
            $events[] = [
                'at' => $at,
                'kind' => 'wallet_' . ((string) ($walletRow['direction'] ?? 'movement')),
                'source_type' => (string) ($walletRow['source_type'] ?? ''),
                'delta_amount' => isset($walletRow['delta_amount']) ? (string) $walletRow['delta_amount'] : null,
                'balance_before' => $walletRow['balance_before'] ?? null,
                'balance_after' => $walletRow['balance_after'] ?? null,
                'transaction_id' => isset($walletRow['transaction_id']) ? (int) $walletRow['transaction_id'] : null,
                'elapsed_from_order_ms' => $orderCreatedAt !== '' ? adminTxAuditElapsedMs($orderCreatedAt, $at) : null,
                'timing_precision' => 'database_seconds',
                '_sort_ms' => (strtotime($at) ?: 0) * 1000,
            ];
        }
        foreach ($transactions as $tx) {
            $at = (string) ($tx['updated_at'] ?? ($tx['created_at'] ?? ''));
            if ($at === '') continue;
            $events[] = [
                'at' => $at,
                'kind' => 'transaction_state',
                'transaction_id' => (int) ($tx['id'] ?? 0),
                'type' => (string) ($tx['type'] ?? ''),
                'status' => (string) ($tx['status'] ?? ''),
                'amount' => $tx['amount'] ?? null,
                'elapsed_from_order_ms' => $orderCreatedAt !== '' ? adminTxAuditElapsedMs($orderCreatedAt, $at) : null,
                'timing_precision' => 'database_seconds',
                '_sort_ms' => (strtotime($at) ?: 0) * 1000,
            ];
        }
        if (is_array($order) && !empty($order['updated_at']) && (string) $order['updated_at'] !== $orderCreatedAt) {
            $at = (string) $order['updated_at'];
            $events[] = [
                'at' => $at,
                'kind' => 'order_final_state',
                'status' => (string) ($order['status'] ?? ''),
                'supplier_order_id' => $order['supplier_order_id'] ?? null,
                'error_message' => $order['error_message'] ?? null,
                'elapsed_from_order_ms' => $orderCreatedAt !== '' ? adminTxAuditElapsedMs($orderCreatedAt, $at) : null,
                'timing_precision' => 'database_seconds',
                '_sort_ms' => (strtotime($at) ?: 0) * 1000,
            ];
        }
        usort($events, static function (array $a, array $b): int {
            $ta = (int) ($a['_sort_ms'] ?? 0);
            $tb = (int) ($b['_sort_ms'] ?? 0);
            if ($ta === $tb) return strcmp((string) ($a['kind'] ?? ''), (string) ($b['kind'] ?? ''));
            return $ta <=> $tb;
        });
        foreach ($events as &$event) unset($event['_sort_ms']);
        unset($event);
        return array_values($events);
    }
}

if (!function_exists('adminTxAuditPublicConnection')) {
    function adminTxAuditPublicConnection(array $row): array
    {
        // Deliberately allowlist diagnostics. Never leak encrypted API keys or
        // authorization material into the copyable evidence JSON.
        $allowed = [
            'id', 'name', 'provider_type', 'status', 'endpoint_url', 'priority',
            'currency', 'last_balance', 'last_sync_at', 'last_success_at',
            'last_error', 'connect_timeout', 'request_timeout', 'created_at', 'updated_at',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $row)) $safe[$key] = $row[$key];
        }
        if (!empty($safe['endpoint_url']) && is_string($safe['endpoint_url'])) {
            $parts = parse_url($safe['endpoint_url']);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $endpoint = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
                if (!empty($parts['port'])) $endpoint .= ':' . (int) $parts['port'];
                $endpoint .= (string) ($parts['path'] ?? '');
                // Query strings, fragments and URL user-info can contain tokens.
                $safe['endpoint_url'] = $endpoint;
            } else {
                $safe['endpoint_url'] = '[invalid/redacted endpoint URL]';
            }
        }
        return $safe;
    }
}

if (!function_exists('adminTxAuditPublicStoreClient')) {
    function adminTxAuditPublicStoreClient(array $row): array
    {
        // Admin evidence needs identity/billing context, but authentication
        // material still does not belong in a copyable JSON bundle.
        $allowed = [
            'id', 'name', 'status', 'client_type', 'billing_mode', 'linked_user_id',
            'website_name', 'website_url', 'balance', 'currency',
            'price_tier', 'price_multiplier', 'allowed_ips', 'rate_limit_per_minute',
            'order_rate_limit_per_minute', 'max_order_amount', 'daily_spend_limit',
            'last_used_at', 'created_at', 'updated_at', 'deleted_at',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $row)) $safe[$key] = $row[$key];
        }
        foreach (['website_url'] as $urlKey) {
            if (empty($safe[$urlKey]) || !is_string($safe[$urlKey])) continue;
            $parts = parse_url($safe[$urlKey]);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                $safe[$urlKey] = '[invalid URL]';
                continue;
            }
            $url = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
            if (!empty($parts['port'])) $url .= ':' . (int) $parts['port'];
            $url .= (string) ($parts['path'] ?? '');
            $safe[$urlKey] = $url;
        }
        return $safe;
    }
}

if (!function_exists('adminTxAuditStoreApiEvidence')) {
    /**
     * Build a read-only, admin-only evidence bundle for a reseller-facing
     * Store API order. No provider/network request is performed here.
     */
    function adminTxAuditStoreApiEvidence(mysqli $conn, int $orderId): array
    {
        $warnings = [];
        $orderColumns = adminTxTableColumns($conn, 'store_api_orders');
        $keyColumns = adminTxTableColumns($conn, 'store_api_order_keys');
        $clientColumns = adminTxTableColumns($conn, 'store_api_clients');
        $apiLedgerColumns = adminTxTableColumns($conn, 'store_api_balance_ledger');
        $requestLogColumns = adminTxTableColumns($conn, 'store_api_request_logs');
        $requestDiagColumns = adminTxTableColumns($conn, 'store_api_request_diagnostics');
        $transactionColumns = adminTxTableColumns($conn, 'transactions');
        $userColumns = adminTxTableColumns($conn, 'users');
        $walletColumns = adminTxTableColumns($conn, 'wallet_balance_ledger');
        $productColumns = adminTxTableColumns($conn, 'products');
        $variantColumns = adminTxTableColumns($conn, 'product_variants');

        $base = [
            'success' => false,
            'evidence_version' => 7,
            'generated_at' => date(DATE_ATOM),
            'source' => 'reseller_store_api',
            'request' => ['order_id' => $orderId],
            'warnings' => &$warnings,
            'privacy_note' => 'Admin-only audit evidence. API keys, key hashes used for authentication, signatures, passwords and secret tokens are intentionally excluded. Delivered product keys are included because they are authoritative sale evidence for administrators.',
        ];

        if ($orderId < 1 || !adminTxHasColumns($orderColumns, ['id', 'client_id'])) {
            $warnings[] = $orderId < 1 ? 'invalid_order_id' : 'store_api_orders_unavailable';
            return $base;
        }

        $order = null;
        $stmt = $conn->prepare('SELECT * FROM store_api_orders WHERE id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $orderId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $order = $result ? $result->fetch_assoc() : null;
            }
            $stmt->close();
        }
        if (!$order) {
            $warnings[] = 'store_api_order_not_found';
            return $base;
        }

        $clientId = (int) ($order['client_id'] ?? 0);
        $transactionId = (int) ($order['billing_transaction_id'] ?? 0);
        $billingUserId = (int) ($order['billing_user_id'] ?? 0);
        $expectedQuantity = max(0, (int) ($order['quantity'] ?? 0));
        $totalPrice = round((float) ($order['total_price'] ?? 0), 2);
        $balanceBefore = !array_key_exists('balance_before', $order) || $order['balance_before'] === null
            ? null : round((float) $order['balance_before'], 2);
        $balanceAfter = !array_key_exists('balance_after', $order) || $order['balance_after'] === null
            ? null : round((float) $order['balance_after'], 2);

        $client = null;
        if ($clientId > 0 && adminTxHasColumns($clientColumns, ['id'])) {
            $stmt = $conn->prepare('SELECT * FROM store_api_clients WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $clientId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $client = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }

        $billingUser = null;
        if ($billingUserId > 0 && adminTxHasColumns($userColumns, ['id', 'username'])) {
            $select = ['id', 'username'];
            foreach (['email', 'role', 'status', 'balance', 'created_at', 'updated_at'] as $column) {
                if (!empty($userColumns[$column])) $select[] = '`' . $column . '`';
            }
            $stmt = $conn->prepare('SELECT ' . implode(',', $select) . ' FROM users WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $billingUserId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $billingUser = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }

        $transaction = null;
        if ($transactionId > 0 && adminTxHasColumns($transactionColumns, ['id', 'user_id', 'type', 'amount', 'status'])) {
            $stmt = $conn->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $transactionId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $transaction = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }

        $localProduct = null;
        $productId = (int) ($order['source_product_id'] ?? 0);
        if ($productId > 0 && adminTxHasColumns($productColumns, ['id'])) {
            $stmt = $conn->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $productId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $localProduct = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }

        $localVariant = null;
        $variantId = (int) ($order['source_variant_id'] ?? 0);
        if ($variantId > 0 && adminTxHasColumns($variantColumns, ['id'])) {
            $stmt = $conn->prepare('SELECT * FROM product_variants WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $variantId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $localVariant = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }

        $deliveredKeys = [];
        $sourceKeyIds = [];
        if (adminTxHasColumns($keyColumns, ['order_id', 'key_code'])) {
            $select = ['id', 'order_id', 'key_code'];
            foreach (['source_key_id', 'key_hash', 'created_at'] as $column) {
                if (!empty($keyColumns[$column])) $select[] = '`' . $column . '`';
            }
            $stmt = $conn->prepare('SELECT ' . implode(',', $select) . ' FROM store_api_order_keys WHERE order_id=? ORDER BY id ASC');
            if ($stmt) {
                $stmt->bind_param('i', $orderId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $deliveredKeys = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                }
                $stmt->close();
            }
            foreach ($deliveredKeys as $keyRow) {
                $sourceKeyId = (int) ($keyRow['source_key_id'] ?? 0);
                if ($sourceKeyId > 0) $sourceKeyIds[$sourceKeyId] = $sourceKeyId;
            }
        } else {
            $warnings[] = 'store_api_order_keys_unavailable';
        }

        $sourceKeyRows = [];
        $keyTableColumns = adminTxTableColumns($conn, 'keys');
        if ($sourceKeyIds && adminTxHasColumns($keyTableColumns, ['id'])) {
            $idList = implode(',', array_map('intval', array_values($sourceKeyIds)));
            $result = $conn->query("SELECT * FROM `keys` WHERE id IN ({$idList}) ORDER BY id ASC");
            if ($result) $sourceKeyRows = $result->fetch_all(MYSQLI_ASSOC);
        }

        $walletRows = [];
        if (adminTxHasColumns($walletColumns, ['id', 'event_key', 'user_id', 'delta_amount'])) {
            $where = ['event_key=?'];
            $eventKey = 'store_api_order:' . $orderId;
            if ($transactionId > 0 && !empty($walletColumns['transaction_id'])) $where[] = 'transaction_id=' . $transactionId;
            if (!empty($walletColumns['source_type']) && !empty($walletColumns['source_id'])) {
                $where[] = "(source_type='store_api_purchase' AND source_id=" . $orderId . ')';
            }
            $stmt = $conn->prepare('SELECT * FROM wallet_balance_ledger WHERE ' . implode(' OR ', $where) . ' ORDER BY created_at ASC,id ASC');
            if ($stmt) {
                $stmt->bind_param('s', $eventKey);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $walletRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                }
                $stmt->close();
            }
        }

        $apiBalanceLedger = [];
        if (adminTxHasColumns($apiLedgerColumns, ['id', 'client_id', 'order_id', 'entry_type', 'amount', 'balance_after'])) {
            $stmt = $conn->prepare('SELECT * FROM store_api_balance_ledger WHERE order_id=? ORDER BY created_at ASC,id ASC');
            if ($stmt) {
                $stmt->bind_param('i', $orderId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $apiBalanceLedger = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                }
                $stmt->close();
            }
        }

        $requestEvidence = [];
        $requestCandidateCount = 0;
        $externalRef = trim((string) ($order['external_ref'] ?? ''));
        if (
            $clientId > 0
            && adminTxHasColumns($requestLogColumns, ['id', 'client_id', 'request_id', 'action', 'created_at'])
            && adminTxHasColumns($requestDiagColumns, ['request_id', 'detail_json'])
        ) {
            $createdAt = trim((string) ($order['created_at'] ?? ''));
            $completedAt = trim((string) (($order['completed_at'] ?? '') ?: ($order['updated_at'] ?? '')));
            if ($createdAt !== '') {
                $startAt = date('Y-m-d H:i:s', max(0, (strtotime($createdAt) ?: time()) - 180));
                $endBase = strtotime($completedAt !== '' ? $completedAt : $createdAt) ?: time();
                $endAt = date('Y-m-d H:i:s', $endBase + 180);
                $diagSelect = [];
                foreach (['result_code','stage','duration_ms','remote_addr','detected_client_ip','trusted_proxy','cf_ray','http_protocol','content_type','content_length','user_agent','request_path','detail_json'] as $column) {
                    if (!empty($requestDiagColumns[$column])) $diagSelect[] = 'd.`' . $column . '`';
                }
                $stmt = $conn->prepare(
                    'SELECT r.*' . ($diagSelect ? ',' . implode(',', $diagSelect) : '')
                    . ' FROM store_api_request_logs r LEFT JOIN store_api_request_diagnostics d ON d.request_id=r.request_id '
                    . "WHERE r.client_id=? AND r.action='order' AND r.created_at BETWEEN ? AND ? ORDER BY r.id DESC LIMIT 50"
                );
                if ($stmt) {
                    $stmt->bind_param('iss', $clientId, $startAt, $endAt);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        while ($candidate = $result ? $result->fetch_assoc() : null) {
                            if (!$candidate) break;
                            $requestCandidateCount++;
                            $rawDetail = trim((string) ($candidate['detail_json'] ?? ''));
                            $decodedDetail = $rawDetail !== '' ? json_decode($rawDetail, true) : null;
                            $matchesOrder = false;
                            if (is_array($decodedDetail)) {
                                $requestSummary = (array) ($decodedDetail['request']['summary'] ?? []);
                                $responseSummary = (array) ($decodedDetail['result']['response_summary'] ?? []);
                                $operationEvidence = (array) ($decodedDetail['operation_diagnostic']['order_evidence'] ?? []);
                                $refCandidates = [
                                    $requestSummary['external_ref'] ?? null,
                                    $responseSummary['external_ref'] ?? null,
                                    $operationEvidence['external_ref'] ?? null,
                                ];
                                foreach ($refCandidates as $candidateRef) {
                                    if ($externalRef !== '' && is_scalar($candidateRef) && hash_equals($externalRef, trim((string) $candidateRef))) {
                                        $matchesOrder = true;
                                        break;
                                    }
                                }
                                if (!$matchesOrder) {
                                    foreach ([$requestSummary['order_id'] ?? null, $responseSummary['order_id'] ?? null, $responseSummary['id'] ?? null, $operationEvidence['order_id'] ?? null] as $candidateOrderId) {
                                        if (is_numeric($candidateOrderId) && (int) $candidateOrderId === $orderId) {
                                            $matchesOrder = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            if (!$matchesOrder) continue;
                            $candidate['diagnostic'] = is_array($decodedDetail) ? $decodedDetail : [];
                            unset($candidate['detail_json']);
                            $requestEvidence[] = $candidate;
                            if (count($requestEvidence) >= 10) break;
                        }
                    }
                    $stmt->close();
                }
            }
        }
        if ($requestCandidateCount > 0 && $requestEvidence === []) {
            $warnings[] = 'request_logs_exist_but_no_exact_order_match';
        }

        $keyHashesValid = true;
        foreach ($deliveredKeys as $keyRow) {
            $keyCode = (string) ($keyRow['key_code'] ?? '');
            $storedHash = strtolower(trim((string) ($keyRow['key_hash'] ?? '')));
            if ($storedHash !== '' && !hash_equals($storedHash, hash('sha256', $keyCode))) {
                $keyHashesValid = false;
                break;
            }
        }
        $balanceMathValid = ($balanceBefore !== null && $balanceAfter !== null)
            ? abs(($balanceBefore - $totalPrice) - $balanceAfter) <= 0.01
            : null;

        $transactionMatches = null;
        if (is_array($transaction)) {
            $transactionMatches = strtolower(trim((string) ($transaction['type'] ?? ''))) === 'store_api_purchase'
                && (int) ($transaction['user_id'] ?? 0) === $billingUserId
                && abs((float) ($transaction['amount'] ?? 0) - $totalPrice) <= 0.01
                && (int) ($transaction['reference_id'] ?? 0) === $orderId;
        }

        $walletMatches = null;
        if ($walletRows !== []) {
            $walletMatches = false;
            foreach ($walletRows as $walletRow) {
                $delta = (float) ($walletRow['delta_amount'] ?? 0);
                $before = !array_key_exists('balance_before', $walletRow) || $walletRow['balance_before'] === null ? null : (float) $walletRow['balance_before'];
                $after = !array_key_exists('balance_after', $walletRow) || $walletRow['balance_after'] === null ? null : (float) $walletRow['balance_after'];
                if (
                    abs($delta + $totalPrice) <= 0.01
                    && ($balanceBefore === null || $before === null || abs($before - $balanceBefore) <= 0.01)
                    && ($balanceAfter === null || $after === null || abs($after - $balanceAfter) <= 0.01)
                ) {
                    $walletMatches = true;
                    break;
                }
            }
        }

        $apiLedgerMatches = null;
        if ($apiBalanceLedger !== []) {
            $apiLedgerMatches = false;
            foreach ($apiBalanceLedger as $ledgerRow) {
                if (
                    strtolower(trim((string) ($ledgerRow['entry_type'] ?? ''))) === 'order_debit'
                    && abs((float) ($ledgerRow['amount'] ?? 0) + $totalPrice) <= 0.01
                    && ($balanceAfter === null || abs((float) ($ledgerRow['balance_after'] ?? 0) - $balanceAfter) <= 0.01)
                ) {
                    $apiLedgerMatches = true;
                    break;
                }
            }
        }

        $timeline = [];
        $pushTimeline = static function (array &$timeline, string $at, string $kind, array $extra = []): void {
            if (trim($at) === '') return;
            $timeline[] = array_merge(['at' => $at, 'kind' => $kind], $extra);
        };
        $pushTimeline($timeline, (string) ($order['created_at'] ?? ''), 'store_api_order_created', ['order_id' => $orderId, 'status' => 'processing']);
        if (is_array($transaction)) $pushTimeline($timeline, (string) ($transaction['created_at'] ?? ''), 'wallet_transaction_created', ['transaction_id' => (int) ($transaction['id'] ?? 0), 'status' => (string) ($transaction['status'] ?? '')]);
        foreach ($walletRows as $walletRow) {
            $pushTimeline($timeline, (string) ($walletRow['created_at'] ?? ''), 'wallet_' . (string) ($walletRow['direction'] ?? 'movement'), [
                'delta_amount' => $walletRow['delta_amount'] ?? null,
                'balance_before' => $walletRow['balance_before'] ?? null,
                'balance_after' => $walletRow['balance_after'] ?? null,
            ]);
        }
        foreach ($apiBalanceLedger as $ledgerRow) {
            $pushTimeline($timeline, (string) ($ledgerRow['created_at'] ?? ''), 'api_balance_' . (string) ($ledgerRow['entry_type'] ?? 'movement'), [
                'amount' => $ledgerRow['amount'] ?? null,
                'balance_after' => $ledgerRow['balance_after'] ?? null,
            ]);
        }
        foreach ($requestEvidence as $requestRow) {
            $pushTimeline($timeline, (string) ($requestRow['created_at'] ?? ''), 'store_api_request', [
                'request_id' => (string) ($requestRow['request_id'] ?? ''),
                'http_code' => (int) ($requestRow['http_code'] ?? 0),
                'result_code' => (string) ($requestRow['result_code'] ?? ''),
            ]);
        }
        $pushTimeline($timeline, (string) ($order['completed_at'] ?? ''), 'store_api_order_completed', ['status' => (string) ($order['status'] ?? '')]);
        usort($timeline, static fn(array $a, array $b): int => (strtotime((string) ($a['at'] ?? '')) ?: 0) <=> (strtotime((string) ($b['at'] ?? '')) ?: 0));

        $base['success'] = true;
        $base['order'] = $order;
        $base['client'] = is_array($client) ? adminTxAuditPublicStoreClient($client) : null;
        $base['billing_user'] = $billingUser;
        $base['linked_transaction'] = $transaction;
        $base['product'] = [
            'local_product' => $localProduct,
            'local_variant' => $localVariant,
            'remote_product_id' => (string) ($order['remote_product_id'] ?? ''),
        ];
        $base['delivery'] = [
            'expected_quantity' => $expectedQuantity,
            'delivered_key_count' => count($deliveredKeys),
            'keys' => $deliveredKeys,
            'source_key_rows' => $sourceKeyRows,
        ];
        $base['billing_evidence'] = [
            'mode' => (string) ($order['billing_mode'] ?? ''),
            'amount_debited' => $totalPrice,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'wallet_balance_ledger' => $walletRows,
            'store_api_balance_ledger' => $apiBalanceLedger,
        ];
        $base['request_evidence'] = [
            'exact_matches' => $requestEvidence,
            'candidate_count_in_time_window' => $requestCandidateCount,
            'matching_rule' => 'Only request diagnostics whose structured JSON exactly matches this external_ref or order_id are attached to avoid falsely attributing a nearby API request.',
            'retention_note' => 'Order rows, delivered-key evidence and billing snapshots are persistent records. Operational request diagnostics follow the Store API log-retention policy and may be pruned later.',
        ];
        $base['integrity'] = [
            'order_found' => true,
            'delivered_key_count_matches_expected' => count($deliveredKeys) === $expectedQuantity,
            'delivered_key_hashes_match_plaintext' => $deliveredKeys === [] ? null : $keyHashesValid,
            'balance_math_valid' => $balanceMathValid,
            'linked_transaction_matches_order' => $transactionMatches,
            'wallet_ledger_matches_order' => $walletMatches,
            'api_balance_ledger_matches_order' => $apiLedgerMatches,
            'client_current_billing_mode_matches_order_snapshot' => !is_array($client) ? null : strtolower(trim((string) ($client['billing_mode'] ?? ''))) === strtolower(trim((string) ($order['billing_mode'] ?? ''))),
            'request_fingerprint_present' => preg_match('/^[a-f0-9]{64}$/i', trim((string) ($order['request_fingerprint'] ?? ''))) === 1,
            'completed_timestamp_present' => trim((string) ($order['completed_at'] ?? '')) !== '',
        ];
        $base['timeline'] = $timeline;
        return $base;
    }
}

$actionMessage = '';
$actionError = '';
if (isset($_SESSION['admin_transactions_flash']) && is_array($_SESSION['admin_transactions_flash'])) {
    $flash = $_SESSION['admin_transactions_flash'];
    unset($_SESSION['admin_transactions_flash']);
    $actionMessage = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $actionError = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $returnQuery = adminTxSafeReturnQuery($_POST['return_query'] ?? '');
    $anchor = '';

    if ($action === 'reconcile_api_order' || $action === 'reconcile_store_order') {
        $orderId = isset($_POST['order_id']) && is_scalar($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $sourceSlug = $action === 'reconcile_store_order' ? 'supplier' : 'cgo';
        $anchor = $orderId > 0 ? 'api-order-' . $sourceSlug . '-' . $orderId : '';
        if ($orderId < 1) {
            $actionError = $t('เลขคำสั่งซื้อ API ไม่ถูกต้อง', 'The API order ID is invalid.');
        } else {
            $result = $action === 'reconcile_store_order'
                ? supplierBridgeReconcileOrder($orderId)
                : cgoReconcileOrder($orderId);
            $returnedKeys = isset($result['keys']) && is_array($result['keys']) ? count($result['keys']) : 0;
            if (!empty($result['success'])) {
                $actionMessage = (string) ($result['message'] ?? $t('ตรวจสอบคำสั่งซื้อแล้ว', 'The order was checked.'));
                if ($returnedKeys > 0) {
                    $actionMessage .= $t(' พบคีย์ ', ' Keys found: ') . $returnedKeys;
                }
            } else {
                $actionError = (string) ($result['message'] ?? $t('ไม่สามารถตรวจสอบคำสั่งซื้อได้', 'The order could not be checked.'));
            }
        }
    } else {
        $actionError = $t('คำสั่งที่ส่งมาไม่รองรับ', 'The requested action is not supported.');
    }

    $_SESSION['admin_transactions_flash'] = [
        'message' => substr($actionMessage, 0, 1000),
        'error' => substr($actionError, 0, 1000),
    ];
    $location = 'transactions.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    if ($anchor !== '') $location .= '#' . rawurlencode($anchor);
    header('Location: ' . $location, true, 303);
    exit;
}

$filterUserId = isset($_GET['user_id']) && is_scalar($_GET['user_id'])
    ? max(0, (int) $_GET['user_id'])
    : 0;
$searchStr = isset($_GET['search']) && is_scalar($_GET['search'])
    ? substr(trim((string) $_GET['search']), 0, 180)
    : '';
$filterType = isset($_GET['type']) && is_scalar($_GET['type'])
    ? strtolower(trim((string) $_GET['type']))
    : 'all';
$filterSource = isset($_GET['source']) && is_scalar($_GET['source'])
    ? strtolower(trim((string) $_GET['source']))
    : 'all';
// Historical links used `store_api` for the supplier-consumer side. Normalize
// them to the unambiguous `supplier` namespace without breaking bookmarks.
if ($filterSource === 'store_api') $filterSource = 'supplier';
$filterStatus = isset($_GET['status']) && is_scalar($_GET['status'])
    ? strtolower(trim((string) $_GET['status']))
    : 'all';
$transactionPage = isset($_GET['page']) && is_scalar($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;
$transactionGroupsPerPage = 50;

$allowedTypes = ['all', 'purchase', 'deposit', 'refund', 'adjustment', 'bonus', 'other'];
$allowedSources = ['all', 'local', 'api', 'cgo', 'supplier', 'reseller_api'];
$allowedStatuses = ['all', 'completed', 'pending', 'review', 'failed', 'refunded', 'cancelled'];

if (!in_array($filterType, $allowedTypes, true)) $filterType = 'all';
if (!in_array($filterSource, $allowedSources, true)) $filterSource = 'all';
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = 'all';

$filterNotice = '';
if ($filterSource !== 'all' && $filterType === 'all') {
    $filterType = 'purchase';
} elseif ($filterSource !== 'all' && $filterType !== 'purchase') {
    $filterSource = 'all';
    $filterNotice = $t(
        'ตัวกรองแหล่งคีย์ถูกยกเลิก เพราะใช้ได้เฉพาะรายการซื้อสินค้า',
        'The key-source filter was cleared because it applies only to purchases.'
    );
}

$currentReturnQuery = http_build_query(array_filter([
    'user_id' => $filterUserId > 0 ? $filterUserId : null,
    'type' => $filterType !== 'all' ? $filterType : null,
    'source' => $filterSource !== 'all' ? $filterSource : null,
    'status' => $filterStatus !== 'all' ? $filterStatus : null,
    'search' => $searchStr !== '' ? $searchStr : null,
    'page' => $transactionPage > 1 ? $transactionPage : null,
], static fn($value): bool => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

$isAuditJsonRequest = isset($_GET['audit_json']) && (string) $_GET['audit_json'] === '1';
if ($isAuditJsonRequest) {
    $GLOBALS['adminTxAuditResponseSent'] = false;
    $GLOBALS['adminTxAuditBufferBase'] = ob_get_level();
    ob_start();
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['adminTxAuditResponseSent'])) return;
        $error = error_get_last();
        if (!is_array($error)) return;
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) return;
        $bufferBase = isset($GLOBALS['adminTxAuditBufferBase']) ? (int) $GLOBALS['adminTxAuditBufferBase'] : 0;
        while (ob_get_level() > $bufferBase) @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode([
            'success' => false,
            'evidence_version' => 6,
            'error' => 'Fatal error while building transaction evidence.',
            'fatal' => [
                'type' => (int) ($error['type'] ?? 0),
                'message' => (string) ($error['message'] ?? ''),
                'file' => basename((string) ($error['file'] ?? '')),
                'line' => (int) ($error['line'] ?? 0),
            ],
            'generated_at' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    });
}

$transactionColumns = adminTxTableColumns($conn, 'transactions');
$userColumns = adminTxTableColumns($conn, 'users');
$cgoOrderColumns = adminTxTableColumns($conn, 'cgo_orders');
$cgoProductColumns = adminTxTableColumns($conn, 'cgo_products');
$cgoOrderKeyColumns = adminTxTableColumns($conn, 'cgo_order_keys');
$cgoApiAttemptColumns = adminTxTableColumns($conn, 'cgo_order_api_attempts');
$supplierOrderColumns = adminTxTableColumns($conn, 'supplier_orders');
$supplierOrderKeyColumns = adminTxTableColumns($conn, 'supplier_order_keys');
$supplierConnectionColumns = adminTxTableColumns($conn, 'supplier_connections');
$supplierProductColumns = adminTxTableColumns($conn, 'supplier_products');
$productColumns = adminTxTableColumns($conn, 'products');
$variantColumns = adminTxTableColumns($conn, 'product_variants');
$purchaseActivityColumns = adminTxTableColumns($conn, 'purchase_activity_events');
$slipDepositColumns = adminTxTableColumns($conn, 'slip_deposits');
$slipJobColumns = adminTxTableColumns($conn, 'slip_verification_jobs');
$walletLedgerColumns = adminTxTableColumns($conn, 'wallet_balance_ledger');
$storeApiOrderColumns = adminTxTableColumns($conn, 'store_api_orders');
$storeApiOrderKeyColumns = adminTxTableColumns($conn, 'store_api_order_keys');
$storeApiClientColumns = adminTxTableColumns($conn, 'store_api_clients');
$storeApiBalanceLedgerColumns = adminTxTableColumns($conn, 'store_api_balance_ledger');
$storeApiRequestLogColumns = adminTxTableColumns($conn, 'store_api_request_logs');
$storeApiRequestDiagnosticColumns = adminTxTableColumns($conn, 'store_api_request_diagnostics');

$cgoOrdersReadable = adminTxHasColumns($cgoOrderColumns, ['id']);
$cgoOrderKeysReadable = adminTxHasColumns($cgoOrderKeyColumns, ['order_id', 'key_code']);
$cgoApiAttemptsReadable = adminTxHasColumns($cgoApiAttemptColumns, ['id', 'order_id', 'phase', 'created_at']);
$cgoProductsReadable = adminTxHasColumns($cgoProductColumns, ['id']);
$supplierOrdersReadable = adminTxHasColumns($supplierOrderColumns, ['id', 'transaction_id', 'external_ref']);
$supplierOrderKeysReadable = adminTxHasColumns($supplierOrderKeyColumns, ['order_id', 'key_code']);
$supplierConnectionsReadable = adminTxHasColumns($supplierConnectionColumns, ['id', 'name']);
$supplierProductsReadable = adminTxHasColumns($supplierProductColumns, ['id', 'name']);
$purchaseActivityReadable = adminTxHasColumns($purchaseActivityColumns, [
    'id', 'user_id', 'source', 'first_transaction_id', 'last_transaction_id', 'quantity'
]);
$slipDepositsReadable = adminTxHasColumns($slipDepositColumns, [
    'id', 'user_id', 'transaction_ref', 'amount', 'sender_name', 'sender_account', 'bank_code', 'transfer_date', 'verified_at'
]);
$slipJobsReadable = adminTxHasColumns($slipJobColumns, [
    'user_id', 'transaction_ref', 'provider_verified_at', 'slip_deposit_id', 'deposit_transaction_id', 'completed_at'
]);
$walletLedgerReadable = adminTxHasColumns($walletLedgerColumns, ['id', 'user_id', 'direction', 'delta_amount', 'source_type', 'created_at']);
$storeApiOrdersReadable = adminTxHasColumns($storeApiOrderColumns, ['id', 'client_id', 'external_ref', 'source_product_id', 'quantity', 'status']);
$storeApiOrderKeysReadable = adminTxHasColumns($storeApiOrderKeyColumns, ['order_id', 'key_code']);
$storeApiClientsReadable = adminTxHasColumns($storeApiClientColumns, ['id', 'name']);
$storeApiBalanceLedgerReadable = adminTxHasColumns($storeApiBalanceLedgerColumns, ['id', 'client_id', 'order_id']);
$storeApiRequestLogsReadable = adminTxHasColumns($storeApiRequestLogColumns, ['id', 'client_id', 'request_id', 'action', 'created_at']);
$storeApiRequestDiagnosticsReadable = adminTxHasColumns($storeApiRequestDiagnosticColumns, ['request_id', 'detail_json']);
$cgoReady = $cgoOrdersReadable;
$storeBridgeReady = $supplierOrdersReadable;

// Copyable, admin-only evidence endpoint used by the purchase detail modal.
// It intentionally reads current DB evidence without running provider requests
// or mutating/reconciling an order, so opening the modal can never change state.
if (isset($_GET['audit_json']) && (string) $_GET['audit_json'] === '1') {
    $auditSource = isset($_GET['audit_source']) && is_scalar($_GET['audit_source'])
        ? strtolower(trim((string) $_GET['audit_source']))
        : 'transaction';
    if ($auditSource === 'store_api') $auditSource = 'supplier';
    if (!in_array($auditSource, ['local', 'transaction', 'supplier', 'cgo', 'reseller_api'], true)) {
        adminTxAuditJsonResponse(['success' => false, 'error' => 'Invalid audit source.'], 400);
    }

    $auditOrderId = isset($_GET['order_id']) && is_scalar($_GET['order_id'])
        ? max(0, (int) $_GET['order_id'])
        : 0;
    if ($auditSource === 'reseller_api') {
        $payload = adminTxAuditStoreApiEvidence($conn, $auditOrderId);
        adminTxAuditJsonResponse($payload, !empty($payload['success']) ? 200 : ($auditOrderId > 0 ? 404 : 400));
    }
    $auditTransactionIds = adminTxAuditIdList($_GET['tx_ids'] ?? '', 100);
    $warnings = [];
    $order = null;
    $providerResponse = adminTxAuditDecodeJson('');
    $deliveredKeys = [];
    $supplierProduct = null;
    $supplierProductPayload = null;
    $cgoProduct = null;
    $cgoProductPayload = null;
    $connection = null;
    $localProduct = null;
    $localVariant = null;
    $cgoApiAttemptsEvidence = [];
    $walletLedgerEvidence = [];
    $diagnosticTimeline = [];
    $resolutionEvidence = null;
    $syncDiagnostics = null;

    if ($auditSource === 'supplier' && $auditOrderId > 0) {
        if (!$supplierOrdersReadable) {
            $warnings[] = 'supplier_orders is unavailable on this installation.';
        } else {
            $stmt = $conn->prepare('SELECT * FROM supplier_orders WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $auditOrderId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $order = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
            if (!$order) {
                $warnings[] = 'Store Bridge order #' . $auditOrderId . ' was not found.';
            }
        }
    } elseif ($auditSource === 'cgo' && $auditOrderId > 0) {
        if (!$cgoOrdersReadable) {
            $warnings[] = 'cgo_orders is unavailable on this installation.';
        } else {
            $stmt = $conn->prepare('SELECT * FROM cgo_orders WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $auditOrderId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $order = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
            if (!$order) {
                $warnings[] = 'CGO order #' . $auditOrderId . ' was not found.';
            }
        }
    }

    if (is_array($order)) {
        $linkedTransactionId = (int) ($order['transaction_id'] ?? 0);
        if ($linkedTransactionId > 0) {
            $auditTransactionIds[$linkedTransactionId] = $linkedTransactionId;
            $auditTransactionIds = array_values(array_unique(array_map('intval', $auditTransactionIds)));
        }

        $providerResponse = adminTxAuditDecodeJson($order['response_json'] ?? '');
        // Keep the order object readable and avoid duplicating a potentially
        // large provider response; the exact payload is represented below.
        unset($order['response_json']);

        if ($auditSource === 'supplier') {
            if ($supplierOrderKeysReadable) {
                $selectColumns = [];
                foreach (['id', 'order_id', 'key_code', 'key_hash', 'created_at'] as $columnName) {
                    if (!empty($supplierOrderKeyColumns[$columnName])) $selectColumns[] = '`' . $columnName . '`';
                }
                $orderBy = !empty($supplierOrderKeyColumns['id']) ? '`id` ASC' : '`key_code` ASC';
                $stmt = $conn->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM supplier_order_keys WHERE order_id=? ORDER BY ' . $orderBy);
                if ($stmt) {
                    $stmt->bind_param('i', $auditOrderId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $deliveredKeys = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                    }
                    $stmt->close();
                }
            } else {
                $warnings[] = 'supplier_order_keys is unavailable; delivered-key evidence may be incomplete.';
            }

            $supplierProductId = (int) ($order['supplier_product_id'] ?? 0);
            if ($supplierProductId > 0 && $supplierProductsReadable) {
                $stmt = $conn->prepare('SELECT * FROM supplier_products WHERE id=? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $supplierProductId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $supplierProduct = $result ? $result->fetch_assoc() : null;
                    }
                    $stmt->close();
                }
                if (is_array($supplierProduct)) {
                    $supplierProductPayload = adminTxAuditDecodeJson($supplierProduct['raw_json'] ?? '');
                    unset($supplierProduct['raw_json']);
                }
            }

            $connectionId = (int) ($order['connection_id'] ?? 0);
            if ($connectionId > 0 && $supplierConnectionsReadable) {
                $stmt = $conn->prepare('SELECT * FROM supplier_connections WHERE id=? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $connectionId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $row = $result ? $result->fetch_assoc() : null;
                        if (is_array($row)) $connection = adminTxAuditPublicConnection($row);
                    }
                    $stmt->close();
                }
            }
        } elseif ($auditSource === 'cgo') {
            if ($cgoOrderKeysReadable) {
                $selectColumns = [];
                foreach (['id', 'order_id', 'key_code', 'key_hash', 'created_at'] as $columnName) {
                    if (!empty($cgoOrderKeyColumns[$columnName])) $selectColumns[] = '`' . $columnName . '`';
                }
                $orderBy = !empty($cgoOrderKeyColumns['id']) ? '`id` ASC' : '`key_code` ASC';
                $stmt = $conn->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM cgo_order_keys WHERE order_id=? ORDER BY ' . $orderBy);
                if ($stmt) {
                    $stmt->bind_param('i', $auditOrderId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $deliveredKeys = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                    }
                    $stmt->close();
                }
            } else {
                $warnings[] = 'cgo_order_keys is unavailable; delivered-key evidence may be incomplete.';
            }

            $cgoProductId = (int) ($order['cgo_product_id'] ?? 0);
            if ($cgoProductId > 0 && $cgoProductsReadable) {
                $stmt = $conn->prepare('SELECT * FROM cgo_products WHERE id=? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $cgoProductId);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $cgoProduct = $result ? $result->fetch_assoc() : null;
                    }
                    $stmt->close();
                }
                if (is_array($cgoProduct)) {
                    $cgoProductPayload = adminTxAuditDecodeJson($cgoProduct['raw_json'] ?? '');
                    unset($cgoProduct['raw_json']);
                }
            }
        }

        $localProductId = (int) ($order['local_product_id'] ?? 0);
        if ($localProductId > 0 && adminTxHasColumns($productColumns, ['id'])) {
            $stmt = $conn->prepare('SELECT * FROM products WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $localProductId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $localProduct = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }
        $localVariantId = (int) ($order['local_variant_id'] ?? 0);
        if ($localVariantId > 0 && adminTxHasColumns($variantColumns, ['id'])) {
            $stmt = $conn->prepare('SELECT * FROM product_variants WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $localVariantId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    $localVariant = $result ? $result->fetch_assoc() : null;
                }
                $stmt->close();
            }
        }
    }

    $transactionsEvidence = [];
    if ($auditTransactionIds) {
        $idList = implode(',', array_map('intval', $auditTransactionIds));
        $result = $conn->query("SELECT * FROM transactions WHERE id IN ({$idList}) ORDER BY id ASC");
        if ($result) $transactionsEvidence = $result->fetch_all(MYSQLI_ASSOC);
    }

    $linkedOrderEvidence = ['cgo' => [], 'supplier' => []];
    if ($auditTransactionIds) {
        $idList = implode(',', array_map('intval', $auditTransactionIds));
        if ($cgoOrdersReadable && !empty($cgoOrderColumns['transaction_id'])) {
            try {
                $result = $conn->query("SELECT id,external_ref,status,supplier_order_id,transaction_id,user_id,cgo_product_id,quantity,error_message,created_at,updated_at,completed_at FROM cgo_orders WHERE transaction_id IN ({$idList}) ORDER BY id ASC");
                if ($result) $linkedOrderEvidence['cgo'] = $result->fetch_all(MYSQLI_ASSOC);
            } catch (Throwable $e) {
                $warnings[] = 'CGO transaction-link evidence query failed: ' . $e->getMessage();
            }
        }
        if ($supplierOrdersReadable) {
            try {
                $result = $conn->query("SELECT id,external_ref,status,supplier_order_id,transaction_id,user_id,connection_id,supplier_product_id,quantity,error_message,created_at,updated_at,completed_at FROM supplier_orders WHERE transaction_id IN ({$idList}) ORDER BY id ASC");
                if ($result) $linkedOrderEvidence['supplier'] = $result->fetch_all(MYSQLI_ASSOC);
            } catch (Throwable $e) {
                $warnings[] = 'Store Bridge transaction-link evidence query failed: ' . $e->getMessage();
            }
        }
    }

    $cgoOrderIdsForEvidence = [];
    if ($auditSource === 'cgo' && $auditOrderId > 0) $cgoOrderIdsForEvidence[$auditOrderId] = $auditOrderId;
    foreach ((array) ($linkedOrderEvidence['cgo'] ?? []) as $linkedCgoRow) {
        $linkedId = (int) ($linkedCgoRow['id'] ?? 0);
        if ($linkedId > 0) $cgoOrderIdsForEvidence[$linkedId] = $linkedId;
    }
    if ($cgoApiAttemptsReadable && $cgoOrderIdsForEvidence) {
        $idList = implode(',', array_map('intval', array_values($cgoOrderIdsForEvidence)));
        try {
            $result = $conn->query("SELECT * FROM cgo_order_api_attempts WHERE order_id IN ({$idList}) ORDER BY created_at ASC, id ASC");
            if ($result) {
                while ($row = $result->fetch_assoc()) $cgoApiAttemptsEvidence[] = adminTxAuditPublicCgoAttempt($row);
            }
        } catch (Throwable $e) {
            $warnings[] = 'CGO API-attempt evidence query failed: ' . $e->getMessage();
        }
    } elseif ($auditSource === 'cgo' && $auditOrderId > 0) {
        $warnings[] = 'cgo_order_api_attempts is unavailable; supplier request timeline may be incomplete.';
    }

    if ($walletLedgerReadable) {
        $walletWhere = [];
        if ($auditTransactionIds) {
            $txIdList = implode(',', array_map('intval', $auditTransactionIds));
            $walletWhere[] = "transaction_id IN ({$txIdList})";
        }
        if ($cgoOrderIdsForEvidence) {
            $orderIdList = implode(',', array_map('intval', array_values($cgoOrderIdsForEvidence)));
            $walletWhere[] = "(source_type IN ('cgo_purchase','cgo_refund') AND source_id IN ({$orderIdList}))";
        }
        $supplierOrderIdsForEvidence = [];
        if ($auditSource === 'supplier' && $auditOrderId > 0) $supplierOrderIdsForEvidence[$auditOrderId] = $auditOrderId;
        foreach ((array) ($linkedOrderEvidence['supplier'] ?? []) as $linkedSupplierRow) {
            $linkedId = (int) ($linkedSupplierRow['id'] ?? 0);
            if ($linkedId > 0) $supplierOrderIdsForEvidence[$linkedId] = $linkedId;
        }
        if ($supplierOrderIdsForEvidence) {
            $supplierIdList = implode(',', array_map('intval', array_values($supplierOrderIdsForEvidence)));
            $walletWhere[] = "(source_type IN ('supplier_purchase','supplier_refund') AND source_id IN ({$supplierIdList}))";
        }
        if ($walletWhere) {
            try {
                $result = $conn->query('SELECT * FROM wallet_balance_ledger WHERE ' . implode(' OR ', $walletWhere) . ' ORDER BY created_at ASC, id ASC');
                if ($result) $walletLedgerEvidence = $result->fetch_all(MYSQLI_ASSOC);
            } catch (Throwable $e) {
                $warnings[] = 'Wallet ledger evidence query failed: ' . $e->getMessage();
            }
        }
    }

    $purchaseActivityEvidence = [];
    if ($purchaseActivityReadable && $auditTransactionIds) {
        $idList = implode(',', array_map('intval', $auditTransactionIds));
        try {
            $result = $conn->query(
                "SELECT DISTINCT e.*
                 FROM purchase_activity_events e
                 INNER JOIN transactions t
                    ON t.id IN ({$idList})
                   AND t.user_id=e.user_id
                   AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                 WHERE LOWER(TRIM(COALESCE(e.source,'')))='local'
                 ORDER BY e.id ASC"
            );
            if ($result) $purchaseActivityEvidence = $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $e) {
            $warnings[] = 'Purchase activity evidence query failed: ' . $e->getMessage();
        }
    }

    $userIds = [];
    foreach ($transactionsEvidence as $transactionEvidence) {
        $uid = (int) ($transactionEvidence['user_id'] ?? 0);
        if ($uid > 0) $userIds[$uid] = $uid;
    }
    if (is_array($order)) {
        $uid = (int) ($order['user_id'] ?? 0);
        if ($uid > 0) $userIds[$uid] = $uid;
    }

    $usersEvidence = [];
    if ($userIds) {
        $safeUserFields = [];
        foreach (['id', 'username', 'role', 'balance', 'status', 'created_at', 'updated_at'] as $field) {
            if (!empty($userColumns[$field])) $safeUserFields[] = '`' . $field . '`';
        }
        if ($safeUserFields) {
            $idList = implode(',', array_map('intval', array_values($userIds)));
            $result = $conn->query('SELECT ' . implode(',', $safeUserFields) . " FROM users WHERE id IN ({$idList}) ORDER BY id ASC");
            if ($result) $usersEvidence = $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    $localKeyEvidence = [];
    if (in_array($auditSource, ['local', 'transaction'], true) && $transactionsEvidence) {
        $localReferenceIds = [];
        foreach ($transactionsEvidence as $transactionEvidence) {
            $effectiveType = adminTxEffectiveType($transactionEvidence);
            $referenceId = (int) ($transactionEvidence['reference_id'] ?? 0);
            if ($effectiveType === 'purchase' && $referenceId > 0) $localReferenceIds[$referenceId] = $referenceId;
        }
        if ($localReferenceIds) {
            $idList = implode(',', array_map('intval', array_values($localReferenceIds)));
            try {
                $result = $conn->query(
                    "SELECT k.*, p.name AS product_name
                     FROM `keys` k
                     LEFT JOIN products p ON p.id=k.product_id
                     WHERE k.id IN ({$idList})
                     ORDER BY k.id ASC"
                );
                if ($result) $localKeyEvidence = $result->fetch_all(MYSQLI_ASSOC);
            } catch (Throwable $e) {
                $warnings[] = 'Local key evidence query failed: ' . $e->getMessage();
            }
        }
    }

    $dbClock = null;
    try {
        $clockResult = $conn->query("SELECT NOW() AS now_local, UTC_TIMESTAMP() AS now_utc, @@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone");
        if ($clockResult) $dbClock = $clockResult->fetch_assoc();
    } catch (Throwable $e) {
        $warnings[] = 'Database clock could not be read.';
    }

    $expectedQuantity = is_array($order) ? max(0, (int) ($order['quantity'] ?? 0)) : 0;
    $orderTransactionId = is_array($order) ? (int) ($order['transaction_id'] ?? 0) : 0;
    $txIdsFound = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $transactionsEvidence);
    $linkedOrderCounts = [];
    foreach (['cgo', 'supplier'] as $linkedSource) {
        foreach ((array) ($linkedOrderEvidence[$linkedSource] ?? []) as $linkedOrderRow) {
            $linkedTxId = (int) ($linkedOrderRow['transaction_id'] ?? 0);
            if ($linkedTxId < 1) continue;
            if (!isset($linkedOrderCounts[$linkedTxId])) $linkedOrderCounts[$linkedTxId] = 0;
            $linkedOrderCounts[$linkedTxId]++;
        }
    }
    $transactionOrderLinkConflicts = [];
    foreach ($linkedOrderCounts as $linkedTxId => $linkedCount) {
        if ($linkedCount > 1) $transactionOrderLinkConflicts[] = (int) $linkedTxId;
    }

    $diagnosticTimeline = adminTxAuditBuildCgoTimeline(
        $auditSource === 'cgo' && is_array($order) ? $order : null,
        $cgoApiAttemptsEvidence,
        $transactionsEvidence,
        $walletLedgerEvidence
    );
    if ($auditSource === 'cgo' && is_array($order)) {
        $finalStatus = strtolower(trim((string) ($order['status'] ?? '')));
        $createdAt = (string) ($order['created_at'] ?? '');
        $updatedAt = (string) ($order['updated_at'] ?? '');
        $completedAt = (string) ($order['completed_at'] ?? '');
        $finalAt = $completedAt !== '' ? $completedAt : $updatedAt;
        $elapsedMs = ($createdAt !== '' && $finalAt !== '') ? adminTxAuditElapsedMs($createdAt, $finalAt) : null;
        $refundLedgerRows = array_values(array_filter($walletLedgerEvidence, static fn(array $row): bool => strtolower((string) ($row['source_type'] ?? '')) === 'cgo_refund'));
        $submitAttempts = array_values(array_filter($cgoApiAttemptsEvidence, static fn(array $row): bool => strtolower((string) ($row['phase'] ?? '')) === 'submit'));
        $statusAttempts = array_values(array_filter($cgoApiAttemptsEvidence, static fn(array $row): bool => strtolower((string) ($row['phase'] ?? '')) === 'status'));
        $cancelAttempts = array_values(array_filter($cgoApiAttemptsEvidence, static fn(array $row): bool => strtolower((string) ($row['phase'] ?? '')) === 'cancel'));
        $externalRefStatusAttempts = array_values(array_filter($statusAttempts, static fn(array $row): bool => strtolower((string) ($row['lookup_mode'] ?? '')) === 'external_ref'));
        $expectedExternalRefUpper = strtoupper(trim((string) ($order['external_ref'] ?? '')));
        $externalRefEchoMatches = 0;
        $bareOrUnverifiedNotFound = 0;
        $legacyStrongNotFoundConfirmations = 0;
        $providerNotFoundNonFinalCount = 0;
        $cancelSealSuccessCount = 0;
        $cancelRejectedOrderExistsCount = 0;
        $providerFinalFailureCount = 0;
        $submitNotDeliveredCount = 0;
        $webhookMatchedCount = 0;
        $supplierIdentityObserved = trim((string) ($order['supplier_order_id'] ?? '')) !== '';
        foreach ($cgoApiAttemptsEvidence as $attemptRow) {
            $echoed = strtoupper(trim((string) ($attemptRow['echoed_external_ref'] ?? '')));
            if ($expectedExternalRefUpper !== '' && $echoed !== '' && hash_equals($expectedExternalRefUpper, $echoed)) $externalRefEchoMatches++;
            $decisionText = strtolower(trim((string) ($attemptRow['decision'] ?? '')));
            if ($decisionText === 'status_external_ref_unverified') $bareOrUnverifiedNotFound++;
            if (strpos($decisionText, 'status_strong_not_found_') === 0) $legacyStrongNotFoundConfirmations++;
            if ($decisionText === 'status_not_found_nonfinal') $providerNotFoundNonFinalCount++;
            if ($decisionText === 'cancel_sealed_safe_to_refund') $cancelSealSuccessCount++;
            if ($decisionText === 'cancel_rejected_order_exists') $cancelRejectedOrderExistsCount++;
            if (in_array($decisionText, ['status_failed_final', 'submit_failed_final'], true)) $providerFinalFailureCount++;
            if ($decisionText === 'submit_not_delivered') $submitNotDeliveredCount++;
            if ($decisionText === 'webhook_order_success_matched') $webhookMatchedCount++;
            if (trim((string) ($attemptRow['discovered_supplier_order_id'] ?? '')) !== '') $supplierIdentityObserved = true;
        }
        $lastAttempt = $cgoApiAttemptsEvidence ? end($cgoApiAttemptsEvidence) : null;
        $checkoutTiming = null;
        foreach ($submitAttempts as $submitAttempt) {
            $diag = is_array($submitAttempt['diagnostics'] ?? null) ? $submitAttempt['diagnostics'] : [];
            $transport = is_array($diag['transport'] ?? null) ? $diag['transport'] : [];
            if (isset($transport['checkout_timing']) && is_array($transport['checkout_timing'])) {
                $checkoutTiming = $transport['checkout_timing'];
                break;
            }
        }
        $exactCheckoutMs = is_array($checkoutTiming) && isset($checkoutTiming['total_checkout_ms']) && is_numeric($checkoutTiming['total_checkout_ms'])
            ? max(0, (int) $checkoutTiming['total_checkout_ms']) : null;
        $resolvedElapsedMs = $exactCheckoutMs !== null ? $exactCheckoutMs : $elapsedMs;
        $targetDeadlineMs = function_exists('cgoOrderCustomerDeadlineSeconds') ? cgoOrderCustomerDeadlineSeconds() * 1000 : null;
        $refundAuthority = null;
        if ($refundLedgerRows !== []) {
            if ($cancelSealSuccessCount > 0) {
                $refundAuthority = 'provider_cancel_seal';
            } elseif ($providerFinalFailureCount > 0) {
                $refundAuthority = 'provider_failed_final';
            } elseif ($submitNotDeliveredCount > 0) {
                $refundAuthority = 'pre_delivery_transport_proof';
            } else {
                foreach ($refundLedgerRows as $refundLedgerRow) {
                    $adminNote = strtolower((string) ($refundLedgerRow['admin_note'] ?? ''));
                    if (preg_match('/automatic cgo refund authority:\s*([a-z0-9_]+)/', $adminNote, $m) === 1) {
                        $candidate = (string) ($m[1] ?? '');
                        if (in_array($candidate, ['provider_cancel_seal', 'provider_failed_final', 'pre_delivery_transport_proof'], true)) {
                            $refundAuthority = $candidate;
                            break;
                        }
                    }
                }
                if ($refundAuthority === null) $refundAuthority = 'legacy_or_unclassified';
            }
        }
        $refundSafe = $refundLedgerRows === [] ? null : in_array($refundAuthority, ['provider_cancel_seal', 'provider_failed_final', 'pre_delivery_transport_proof'], true);
        $resolutionEvidence = [
            'final_status' => $finalStatus,
            'supplier_order_id' => $order['supplier_order_id'] ?? null,
            'error_message' => $order['error_message'] ?? null,
            'elapsed_to_final_state_ms' => $resolvedElapsedMs,
            'timing_precision' => $exactCheckoutMs !== null ? 'exact_checkout_ms' : 'database_seconds_approx',
            'database_timestamp_elapsed_ms_approx' => $elapsedMs,
            'checkout_timing' => $checkoutTiming,
            'target_customer_deadline_ms' => $targetDeadlineMs,
            'within_customer_deadline' => $resolvedElapsedMs === null || $targetDeadlineMs === null ? null : $resolvedElapsedMs <= $targetDeadlineMs,
            'submit_attempt_count' => count($submitAttempts),
            'status_lookup_count' => count($statusAttempts),
            'refund_ledger_count' => count($refundLedgerRows),
            'refund_ledger_present' => $refundLedgerRows !== [],
            'refund_authority' => $refundAuthority,
            'refund_safe' => $refundSafe,
            'order_cancel_attempt_count' => count($cancelAttempts),
            'last_supplier_decision' => is_array($lastAttempt) ? ($lastAttempt['decision'] ?? null) : null,
            'last_supplier_http_code' => is_array($lastAttempt) ? (int) ($lastAttempt['http_code'] ?? 0) : null,
            'last_supplier_curl_errno' => is_array($lastAttempt) ? (int) ($lastAttempt['curl_errno'] ?? 0) : null,
            'api_contract_observations' => [
                'external_ref_status_attempt_count' => count($externalRefStatusAttempts),
                'external_ref_echo_match_count' => $externalRefEchoMatches,
                'external_ref_lookup_support_observed' => count($externalRefStatusAttempts) < 1
                    ? null
                    : ($externalRefEchoMatches > 0 || $supplierIdentityObserved || $providerNotFoundNonFinalCount > 0),
                'unverified_external_ref_response_count' => $bareOrUnverifiedNotFound,
                'provider_not_found_nonfinal_count' => $providerNotFoundNonFinalCount,
                'cancel_seal_success_count' => $cancelSealSuccessCount,
                'cancel_rejected_order_exists_count' => $cancelRejectedOrderExistsCount,
                'provider_final_failure_count' => $providerFinalFailureCount,
                'legacy_strong_not_found_confirmation_count' => $legacyStrongNotFoundConfirmations,
                'supplier_order_identity_observed' => $supplierIdentityObserved,
                'webhook_order_success_matched_count' => $webhookMatchedCount,
                'contract_policy' => [
                    'external_ref_idempotent' => true,
                    'order_not_found_is_final' => false,
                    'refund_requires_provider_finality_or_cancel_seal' => true,
                    'order_cancel_requires_exact_external_ref_binding' => true,
                    'order_cancel_success_requires_final_fence' => true,
                    'refund_authority_is_fail_closed' => true,
                    'terminal_order_state_downgrade_protected' => true,
                ],
            ],
        ];
    }

    if ($auditSource === 'cgo') {
        $syncDiagnostics = adminTxAuditSyncDiagnostics('cgo', is_array($cgoProduct) ? $cgoProduct : null, is_array($cgoProductPayload) ? $cgoProductPayload : null, is_array($localVariant) ? $localVariant : null);
    } elseif ($auditSource === 'supplier') {
        $syncDiagnostics = adminTxAuditSyncDiagnostics('supplier', is_array($supplierProduct) ? $supplierProduct : null, is_array($supplierProductPayload) ? $supplierProductPayload : null, is_array($localVariant) ? $localVariant : null);
    }

    $integrity = [
        'source' => $auditSource,
        'order_found' => is_array($order),
        'transaction_count' => count($transactionsEvidence),
        'requested_transaction_ids' => array_values(array_map('intval', $auditTransactionIds)),
        'order_transaction_id' => $orderTransactionId,
        'order_transaction_present' => $orderTransactionId < 1 || in_array($orderTransactionId, $txIdsFound, true),
        'expected_quantity' => $expectedQuantity,
        'stored_delivered_key_count' => count($deliveredKeys),
        'delivered_key_count_matches_expected' => $expectedQuantity < 1 ? null : count($deliveredKeys) === $expectedQuantity,
        'provider_response_present' => (bool) ($providerResponse['present'] ?? false),
        'provider_response_valid_json' => $providerResponse['valid_json'] ?? null,
        'api_attempt_count' => count($cgoApiAttemptsEvidence),
        'wallet_ledger_row_count' => count($walletLedgerEvidence),
        'wallet_ledger_available' => $walletLedgerReadable,
        'transaction_order_link_conflict' => $transactionOrderLinkConflicts !== [],
        'conflicting_transaction_ids' => $transactionOrderLinkConflicts,
    ];

    adminTxAuditJsonResponse([
        'success' => true,
        'evidence_version' => 6,
        'generated_at' => date(DATE_ATOM),
        'php_timezone' => date_default_timezone_get(),
        'database_clock' => $dbClock,
        'request' => [
            'source' => $auditSource,
            'order_id' => $auditOrderId,
            'transaction_ids' => array_values(array_map('intval', $auditTransactionIds)),
        ],
        'integrity' => $integrity,
        'warnings' => $warnings,
        'users' => $usersEvidence,
        'transactions' => $transactionsEvidence,
        'order' => $order,
        'provider_response' => $providerResponse,
        'api_attempts' => $cgoApiAttemptsEvidence,
        'diagnostic_timeline' => $diagnosticTimeline,
        'resolution' => $resolutionEvidence,
        'sync_diagnostics' => $syncDiagnostics,
        'wallet_ledger' => $walletLedgerEvidence,
        'delivered_keys' => $deliveredKeys,
        'connection' => $connection,
        'supplier_product' => $supplierProduct,
        'supplier_product_payload' => $supplierProductPayload,
        'cgo_product' => $cgoProduct,
        'cgo_product_payload' => $cgoProductPayload,
        'local_product' => $localProduct,
        'local_variant' => $localVariant,
        'local_key_records' => $localKeyEvidence,
        'purchase_activity_events' => $purchaseActivityEvidence,
        'transaction_order_links' => $linkedOrderEvidence,
        'privacy_note' => 'API credentials, authentication secrets, signatures and tokens are intentionally excluded. Supplier API-attempt logs redact delivered license keys; authoritative delivered keys remain in delivered_keys because this endpoint is admin-only.',
    ]);
}

// The full user list is only needed by the visible page filters. Keep it out
// of the audit JSON request path so opening evidence stays lightweight.
$usersRes = $conn->query("SELECT id, username, role FROM users ORDER BY username ASC");
$users = $usersRes ? $usersRes->fetch_all(MYSQLI_ASSOC) : [];

$selectedWalletSummary = $filterUserId > 0 && function_exists('walletLedgerGetSummary')
    ? walletLedgerGetSummary($filterUserId)
    : ['available' => false, 'status' => 'unavailable'];

// A transaction may be linked to an API order in two ways:
// 1) cgo_orders.transaction_id -> transactions.id (authoritative), or
// 2) transactions.reference_id -> cgo_orders.id for old records, but only when
//    the transaction itself carries an API marker. Without this guard, a local
//    key ID can collide with an API order ID and make an unrelated local sale
//    appear in API-key/product/order searches.
$apiCandidateSql = "(LOWER(TRIM(COALESCE(t.type, ''))) = 'cgo_purchase'"
    . " OR LOWER(COALESCE(t.description, '')) LIKE '%cheatgame order %:%')";
$apiOrderLinkSql = static function (string $orderAlias) use ($cgoOrderColumns, $apiCandidateSql): string {
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $orderAlias) !== 1) {
        throw new InvalidArgumentException('Invalid SQL alias');
    }

    $links = [];
    if (!empty($cgoOrderColumns['transaction_id'])) {
        $links[] = $orderAlias . '.transaction_id = t.id';
    }
    $links[] = '(' . $apiCandidateSql . ' AND ' . $orderAlias . '.id = t.reference_id)';

    return '(' . implode(' OR ', $links) . ')';
};


$storeApiCandidateSql = "(LOWER(TRIM(COALESCE(t.type, ''))) = 'supplier_purchase'"
    . " OR LOWER(COALESCE(t.description, '')) LIKE '%store bridge order %:%')";
$storeOrderLinkSql = static function (string $orderAlias) use ($supplierOrderColumns, $storeApiCandidateSql): string {
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $orderAlias) !== 1) {
        throw new InvalidArgumentException('Invalid SQL alias');
    }

    $links = [];
    if (!empty($supplierOrderColumns['transaction_id'])) {
        $links[] = $orderAlias . '.transaction_id = t.id';
    }
    $links[] = '(' . $storeApiCandidateSql . ' AND ' . $orderAlias . '.id = t.reference_id)';
    return '(' . implode(' OR ', $links) . ')';
};


$resellerApiCandidateSql = "(LOWER(TRIM(COALESCE(t.type, ''))) = 'store_api_purchase'"
    . " OR LOWER(COALESCE(t.description, '')) LIKE 'store api reseller wallet order %')";
$resellerApiOrderLinkSql = static function (string $orderAlias) use ($storeApiOrderColumns, $resellerApiCandidateSql): string {
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $orderAlias) !== 1) {
        throw new InvalidArgumentException('Invalid SQL alias');
    }

    $links = [];
    if (!empty($storeApiOrderColumns['billing_transaction_id'])) {
        $links[] = $orderAlias . '.billing_transaction_id = t.id';
    }
    $links[] = '(' . $resellerApiCandidateSql . ' AND ' . $orderAlias . '.id = t.reference_id)';
    return '(' . implode(' OR ', $links) . ')';
};

$userRoleSelect = !empty($userColumns['role'])
    ? "COALESCE(u.role, 'deleted')"
    : "'unknown'";

// Keep the core history query independent from every optional API table. API
// metadata is loaded after the core transaction rows have been found.
$sql = "SELECT
            t.*,
            COALESCE(u.username, CONCAT('Deleted user #', t.user_id)) AS username,
            {$userRoleSelect} AS role,
            k.key_code,
            k.product_id,
            p.name AS product_name,
            k.duration
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN `keys` k
            ON t.reference_id = k.id
           AND (
                LOWER(TRIM(COALESCE(t.type,''))) = 'purchase'
                OR LOWER(COALESCE(t.description,'')) LIKE 'purchased % key: %'
           )
        LEFT JOIN products p ON p.id = k.product_id
        WHERE 1=1";

$params = [];
$types = '';
$appendParam = static function (string $type, $value) use (&$types, &$params): void {
    $types .= $type;
    $params[] = $value;
};

if ($filterUserId > 0) {
    $sql .= ' AND t.user_id = ?';
    $appendParam('i', $filterUserId);
}

// Apply only prefilters that are guaranteed to be a superset of the final
// hydrated result. Final type/source/status checks still run below, so legacy
// records keep the same visible semantics while obvious non-matches never enter
// the expensive 5,000-row hydration path.
$storedTypeSql = "LOWER(TRIM(COALESCE(t.type,'')))";
$knownTypes = function_exists('transactionIntegrityKnownTypes')
    ? transactionIntegrityKnownTypes()
    : ['purchase','cgo_purchase','supplier_purchase','store_api_purchase','deposit','redeem_code','manual_add','manual_deduct','rank_bonus'];
$knownTypes = array_values(array_filter(array_map('strval', $knownTypes), static fn(string $value): bool => preg_match('/^[a-z0-9_]+$/D', $value) === 1));
$knownTypeSql = $knownTypes ? "'" . implode("','", $knownTypes) . "'" : "''";
$storedUnknownSql = "{$storedTypeSql} NOT IN ({$knownTypeSql})";

if ($filterType === 'refund') {
    // Refund cards are sourced from wallet_balance_ledger below. The old path
    // scanned and hydrated up to 5,000 transaction rows only to discard every
    // one of them during the final filter.
    $sql .= ' AND 1=0';
} elseif ($filterType === 'deposit') {
    $sql .= " AND ({$storedTypeSql} IN ('deposit','redeem_code') OR ({$storedUnknownSql} AND ("
        . "LOWER(COALESCE(t.description,'')) LIKE 'redeemed top-up code %' OR "
        . "LOWER(COALESCE(t.description,'')) LIKE 'slip verification%' OR "
        . "LOWER(COALESCE(t.description,'')) LIKE 'truemoney angpao gross %' OR "
        . "LOWER(COALESCE(t.description,'')) LIKE 'binance gift card:%' OR "
        . "LOWER(COALESCE(t.description,'')) LIKE 'binance usdt deposit:%')) )";
} elseif ($filterType === 'adjustment') {
    $sql .= " AND ({$storedTypeSql} IN ('manual_add','manual_deduct') OR ({$storedUnknownSql} AND LOWER(TRIM(COALESCE(t.description,''))) IN ("
        . "'opening balance added by admin','balance added by admin','balance deducted by admin')))";
} elseif ($filterType === 'bonus') {
    $sql .= " AND ({$storedTypeSql}='rank_bonus' OR ({$storedUnknownSql} AND LOWER(COALESCE(t.description,'')) LIKE 'monthly rank bonus %'))";
} elseif ($filterType === 'purchase') {
    $purchaseCandidates = [
        "{$storedTypeSql} IN ('purchase','cgo_purchase','supplier_purchase','store_api_purchase')",
        $apiCandidateSql,
        $storeApiCandidateSql,
        $resellerApiCandidateSql,
        "({$storedUnknownSql} AND LOWER(COALESCE(t.description,'')) LIKE 'purchased % key: %')",
    ];
    if (adminTxHasColumns($cgoOrderColumns, ['transaction_id'])) {
        $purchaseCandidates[] = 'EXISTS (SELECT 1 FROM cgo_orders cgo_type_filter WHERE cgo_type_filter.transaction_id=t.id)';
    }
    if (adminTxHasColumns($supplierOrderColumns, ['transaction_id'])) {
        $purchaseCandidates[] = 'EXISTS (SELECT 1 FROM supplier_orders supplier_type_filter WHERE supplier_type_filter.transaction_id=t.id)';
    }
    if (adminTxHasColumns($storeApiOrderColumns, ['billing_transaction_id'])) {
        $purchaseCandidates[] = 'EXISTS (SELECT 1 FROM store_api_orders reseller_type_filter WHERE reseller_type_filter.billing_transaction_id=t.id)';
    }
    if (adminTxHasColumns(adminTxTableColumns($conn, 'keys'), ['id'])) {
        $purchaseCandidates[] = "({$storedUnknownSql} AND COALESCE(t.reference_id,0)>0 AND EXISTS (SELECT 1 FROM `keys` purchase_key_filter WHERE purchase_key_filter.id=t.reference_id))";
    }
    $sql .= ' AND (' . implode(' OR ', $purchaseCandidates) . ')';
}

if ($filterSource !== 'all') {
    $sourceCandidates = [];
    if (in_array($filterSource, ['api', 'cgo'], true)) {
        $cgoSource = [$apiCandidateSql];
        if (adminTxHasColumns($cgoOrderColumns, ['transaction_id'])) {
            $cgoSource[] = 'EXISTS (SELECT 1 FROM cgo_orders cgo_source_filter WHERE cgo_source_filter.transaction_id=t.id)';
        }
        $sourceCandidates[] = '(' . implode(' OR ', $cgoSource) . ')';
    }
    if (in_array($filterSource, ['api', 'supplier'], true)) {
        $supplierSource = [$storeApiCandidateSql];
        if (adminTxHasColumns($supplierOrderColumns, ['transaction_id'])) {
            $supplierSource[] = 'EXISTS (SELECT 1 FROM supplier_orders supplier_source_filter WHERE supplier_source_filter.transaction_id=t.id)';
        }
        $sourceCandidates[] = '(' . implode(' OR ', $supplierSource) . ')';
    }
    if (in_array($filterSource, ['api', 'reseller_api'], true)) {
        $resellerSource = [$resellerApiCandidateSql];
        if (adminTxHasColumns($storeApiOrderColumns, ['billing_transaction_id'])) {
            $resellerSource[] = 'EXISTS (SELECT 1 FROM store_api_orders reseller_source_filter WHERE reseller_source_filter.billing_transaction_id=t.id)';
        }
        $sourceCandidates[] = '(' . implode(' OR ', $resellerSource) . ')';
    }
    if ($sourceCandidates) $sql .= ' AND (' . implode(' OR ', $sourceCandidates) . ')';
}

if ($filterStatus !== 'all') {
    $statusMap = [
        'completed' => ['completed', 'success'],
        'pending' => ['pending', 'processing', 'submitting'],
        'review' => ['unknown', 'manual_review', 'refunded_conflict'],
        'failed' => ['failed'],
        'refunded' => ['refunded', 'refunded_conflict'],
        'cancelled' => ['cancelled', 'canceled'],
    ];
    $statusValues = $statusMap[$filterStatus] ?? [];
    if ($statusValues) {
        $statusSql = "'" . implode("','", $statusValues) . "'";
        $statusCandidates = ["LOWER(TRIM(COALESCE(t.status,''))) IN ({$statusSql})"];
        if (adminTxHasColumns($cgoOrderColumns, ['transaction_id', 'status'])) {
            $statusCandidates[] = "EXISTS (SELECT 1 FROM cgo_orders cgo_status_filter WHERE cgo_status_filter.transaction_id=t.id AND LOWER(TRIM(COALESCE(cgo_status_filter.status,''))) IN ({$statusSql}))";
            $statusCandidates[] = $apiCandidateSql; // legacy link resolved after hydration
        }
        if (adminTxHasColumns($supplierOrderColumns, ['transaction_id', 'status'])) {
            $statusCandidates[] = "EXISTS (SELECT 1 FROM supplier_orders supplier_status_filter WHERE supplier_status_filter.transaction_id=t.id AND LOWER(TRIM(COALESCE(supplier_status_filter.status,''))) IN ({$statusSql}))";
            $statusCandidates[] = $storeApiCandidateSql;
        }
        if (adminTxHasColumns($storeApiOrderColumns, ['billing_transaction_id', 'status'])) {
            $statusCandidates[] = "EXISTS (SELECT 1 FROM store_api_orders reseller_status_filter WHERE reseller_status_filter.billing_transaction_id=t.id AND LOWER(TRIM(COALESCE(reseller_status_filter.status,''))) IN ({$statusSql}))";
            $statusCandidates[] = $resellerApiCandidateSql;
        }
        $sql .= ' AND (' . implode(' OR ', array_unique($statusCandidates)) . ')';
    }
}

$searchCandidateTruncated = false;
if ($searchStr !== '') {
    $numericSearch = null;
    $numericKind = '';
    if (preg_match('/\A(?:(tx|order)\s*)?#?\s*(\d{1,18})\z/i', $searchStr, $matches)) {
        $numericKind = strtolower((string) ($matches[1] ?? ''));
        $numericSearch = (int) $matches[2];
    }

    $searchConditions = [];
    $like = '%' . adminTxEscapeLike($searchStr) . '%';

    // Explicit TX/Order searches are exact indexed lookups. Do not also run the
    // broad text search, which previously evaluated dozens of LIKE/EXISTS
    // predicates even when the administrator supplied a precise identifier.
    if ($numericSearch !== null && $numericSearch > 0 && $numericKind === 'tx') {
        $searchConditions[] = 't.id = ?';
        $appendParam('i', $numericSearch);
    } elseif ($numericSearch !== null && $numericSearch > 0 && $numericKind === 'order') {
        if ($cgoOrdersReadable) {
            $searchConditions[] = "EXISTS (SELECT 1 FROM cgo_orders co_id_search WHERE co_id_search.id=? AND " . $apiOrderLinkSql('co_id_search') . ')';
            $appendParam('i', $numericSearch);
        }
        if ($supplierOrdersReadable) {
            $searchConditions[] = "EXISTS (SELECT 1 FROM supplier_orders so_id_search WHERE so_id_search.id=? AND " . $storeOrderLinkSql('so_id_search') . ')';
            $appendParam('i', $numericSearch);
        }
        if ($storeApiOrdersReadable) {
            $searchConditions[] = "EXISTS (SELECT 1 FROM store_api_orders rao_id_search WHERE rao_id_search.id=? AND " . $resellerApiOrderLinkSql('rao_id_search') . ')';
            $appendParam('i', $numericSearch);
        }
        if (!$searchConditions) $searchConditions[] = '0=1';
    } else {
        foreach (['u.username', 't.description', 'k.key_code', 'p.name'] as $field) {
            $searchConditions[] = $field . " LIKE ? ESCAPE '!'";
            $appendParam('s', $like);
        }
        if (!empty($userColumns['email'])) {
            $searchConditions[] = "u.email LIKE ? ESCAPE '!'";
            $appendParam('s', $like);
        }

        if ($slipDepositsReadable) {
            $slipSearchParts = [];
            foreach (['transaction_ref', 'sender_name', 'bank_code'] as $column) {
                if (!empty($slipDepositColumns[$column])) {
                    $slipSearchParts[] = 'sd_search.`' . $column . "` LIKE ? ESCAPE '!'";
                    $appendParam('s', $like);
                }
            }
            if ($slipSearchParts) {
                $searchConditions[] = "EXISTS (
                    SELECT 1 FROM slip_deposits sd_search
                    WHERE sd_search.id=t.reference_id
                      AND sd_search.user_id=t.user_id
                      AND LOWER(COALESCE(t.description,'')) LIKE 'slip verification%'
                      AND (" . implode(' OR ', $slipSearchParts) . ")
                )";
            }
        }

        // Search each external order namespace once, then feed the matched IDs
        // into the core transaction query. The previous implementation ran a
        // correlated EXISTS for every search surface against every transaction,
        // turning one search into thousands of repeated provider-table probes.
        $candidateLimit = 5001;
        $candidateTxIds = [];
        $candidateLegacy = ['cgo' => [], 'supplier' => [], 'reseller_api' => []];
        $collectCandidates = static function (array $rows, string $txColumn, string $source) use (&$candidateTxIds, &$candidateLegacy, &$searchCandidateTruncated, $candidateLimit): void {
            if (count($rows) >= $candidateLimit) $searchCandidateTruncated = true;
            foreach (array_slice($rows, 0, $candidateLimit - 1) as $row) {
                $orderId = (int) ($row['id'] ?? 0);
                $txId = (int) ($row[$txColumn] ?? 0);
                if ($txId > 0) $candidateTxIds[$txId] = $txId;
                if ($orderId > 0 && isset($candidateLegacy[$source])) $candidateLegacy[$source][$orderId] = $orderId;
            }
        };

        if ($cgoOrdersReadable) {
            $parts = [];
            $candidateParams = [];
            $candidateTypes = '';
            $add = static function (string $expr) use (&$parts, &$candidateParams, &$candidateTypes, $like): void {
                $parts[] = $expr;
                $candidateTypes .= 's';
                $candidateParams[] = $like;
            };
            foreach (['external_ref', 'supplier_order_id', 'remote_product_id', 'error_message'] as $column) {
                if (!empty($cgoOrderColumns[$column])) $add('co.`' . $column . "` LIKE ? ESCAPE '!'");
            }
            if ($cgoOrderKeysReadable) $add("EXISTS (SELECT 1 FROM cgo_order_keys cok WHERE cok.order_id=co.id AND cok.key_code LIKE ? ESCAPE '!')");
            if ($cgoProductsReadable && !empty($cgoOrderColumns['cgo_product_id'])) {
                $productParts = [];
                foreach (['name', 'brand', 'duration'] as $column) {
                    if (!empty($cgoProductColumns[$column])) {
                        $productParts[] = 'cp.`' . $column . "` LIKE ? ESCAPE '!'";
                        $candidateTypes .= 's';
                        $candidateParams[] = $like;
                    }
                }
                if ($productParts) $parts[] = 'EXISTS (SELECT 1 FROM cgo_products cp WHERE cp.id=co.cgo_product_id AND (' . implode(' OR ', $productParts) . '))';
            }
            if (adminTxHasColumns($productColumns, ['id', 'name']) && !empty($cgoOrderColumns['local_product_id'])) {
                $add("EXISTS (SELECT 1 FROM products lp WHERE lp.id=co.local_product_id AND lp.name LIKE ? ESCAPE '!')");
            }
            if (adminTxHasColumns($variantColumns, ['id', 'duration']) && !empty($cgoOrderColumns['local_variant_id'])) {
                $add("EXISTS (SELECT 1 FROM product_variants pv WHERE pv.id=co.local_variant_id AND pv.duration LIKE ? ESCAPE '!')");
            }
            if ($parts) {
                $txSelect = !empty($cgoOrderColumns['transaction_id']) ? 'COALESCE(co.transaction_id,0)' : '0';
                $rows = adminTxSearchRows($conn, 'SELECT co.id,' . $txSelect . ' AS transaction_id FROM cgo_orders co WHERE (' . implode(' OR ', $parts) . ') ORDER BY co.id DESC LIMIT ' . $candidateLimit, $candidateTypes, $candidateParams);
                $collectCandidates($rows, 'transaction_id', 'cgo');
            }
        }

        if ($supplierOrdersReadable) {
            $parts = [];
            $candidateParams = [];
            $candidateTypes = '';
            $add = static function (string $expr) use (&$parts, &$candidateParams, &$candidateTypes, $like): void {
                $parts[] = $expr;
                $candidateTypes .= 's';
                $candidateParams[] = $like;
            };
            foreach (['external_ref', 'supplier_order_id', 'error_message'] as $column) {
                if (!empty($supplierOrderColumns[$column])) $add('so.`' . $column . "` LIKE ? ESCAPE '!'");
            }
            if ($supplierOrderKeysReadable) $add("EXISTS (SELECT 1 FROM supplier_order_keys sok WHERE sok.order_id=so.id AND sok.key_code LIKE ? ESCAPE '!')");
            if ($supplierProductsReadable && !empty($supplierOrderColumns['supplier_product_id'])) {
                $productParts = [];
                foreach (['name', 'duration', 'remote_product_id', 'remote_source_product_id'] as $column) {
                    if (!empty($supplierProductColumns[$column])) {
                        $productParts[] = 'sp.`' . $column . "` LIKE ? ESCAPE '!'";
                        $candidateTypes .= 's';
                        $candidateParams[] = $like;
                    }
                }
                if ($productParts) $parts[] = 'EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.id=so.supplier_product_id AND (' . implode(' OR ', $productParts) . '))';
            }
            if ($supplierConnectionsReadable && !empty($supplierOrderColumns['connection_id'])) $add("EXISTS (SELECT 1 FROM supplier_connections sc WHERE sc.id=so.connection_id AND sc.name LIKE ? ESCAPE '!')");
            if (adminTxHasColumns($productColumns, ['id', 'name']) && !empty($supplierOrderColumns['local_product_id'])) $add("EXISTS (SELECT 1 FROM products slp WHERE slp.id=so.local_product_id AND slp.name LIKE ? ESCAPE '!')");
            if (adminTxHasColumns($variantColumns, ['id', 'duration']) && !empty($supplierOrderColumns['local_variant_id'])) $add("EXISTS (SELECT 1 FROM product_variants spv WHERE spv.id=so.local_variant_id AND spv.duration LIKE ? ESCAPE '!')");
            if ($parts) {
                $rows = adminTxSearchRows($conn, 'SELECT so.id,COALESCE(so.transaction_id,0) AS transaction_id FROM supplier_orders so WHERE (' . implode(' OR ', $parts) . ') ORDER BY so.id DESC LIMIT ' . $candidateLimit, $candidateTypes, $candidateParams);
                $collectCandidates($rows, 'transaction_id', 'supplier');
            }
        }

        if ($storeApiOrdersReadable) {
            $parts = [];
            $candidateParams = [];
            $candidateTypes = '';
            $add = static function (string $expr) use (&$parts, &$candidateParams, &$candidateTypes, $like): void {
                $parts[] = $expr;
                $candidateTypes .= 's';
                $candidateParams[] = $like;
            };
            foreach (['external_ref', 'remote_product_id', 'duration', 'customer_name', 'customer_email', 'origin_site_id', 'origin_user_id', 'customer_ref', 'error_message'] as $column) {
                if (!empty($storeApiOrderColumns[$column])) $add('rao.`' . $column . "` LIKE ? ESCAPE '!'");
            }
            if ($storeApiOrderKeysReadable) $add("EXISTS (SELECT 1 FROM store_api_order_keys raok WHERE raok.order_id=rao.id AND raok.key_code LIKE ? ESCAPE '!')");
            if ($storeApiClientsReadable) {
                $clientParts = [];
                foreach (['name', 'website_name', 'website_url'] as $column) {
                    if (!empty($storeApiClientColumns[$column])) {
                        $clientParts[] = 'sac.`' . $column . "` LIKE ? ESCAPE '!'";
                        $candidateTypes .= 's';
                        $candidateParams[] = $like;
                    }
                }
                if ($clientParts) $parts[] = 'EXISTS (SELECT 1 FROM store_api_clients sac WHERE sac.id=rao.client_id AND (' . implode(' OR ', $clientParts) . '))';
            }
            if (adminTxHasColumns($productColumns, ['id', 'name']) && !empty($storeApiOrderColumns['source_product_id'])) $add("EXISTS (SELECT 1 FROM products rap WHERE rap.id=rao.source_product_id AND rap.name LIKE ? ESCAPE '!')");
            if (adminTxHasColumns($variantColumns, ['id', 'duration']) && !empty($storeApiOrderColumns['source_variant_id'])) $add("EXISTS (SELECT 1 FROM product_variants rav WHERE rav.id=rao.source_variant_id AND rav.duration LIKE ? ESCAPE '!')");
            if ($parts) {
                $txSelect = !empty($storeApiOrderColumns['billing_transaction_id']) ? 'COALESCE(rao.billing_transaction_id,0)' : '0';
                $rows = adminTxSearchRows($conn, 'SELECT rao.id,' . $txSelect . ' AS billing_transaction_id FROM store_api_orders rao WHERE (' . implode(' OR ', $parts) . ') ORDER BY rao.id DESC LIMIT ' . $candidateLimit, $candidateTypes, $candidateParams);
                $collectCandidates($rows, 'billing_transaction_id', 'reseller_api');
            }
        }

        if ($candidateTxIds) {
            $searchConditions[] = 't.id IN (' . implode(',', array_map('intval', array_values($candidateTxIds))) . ')';
        }
        if ($candidateLegacy['cgo']) {
            $searchConditions[] = '(' . $apiCandidateSql . ' AND t.reference_id IN (' . implode(',', array_map('intval', array_values($candidateLegacy['cgo']))) . '))';
        }
        if ($candidateLegacy['supplier']) {
            $searchConditions[] = '(' . $storeApiCandidateSql . ' AND t.reference_id IN (' . implode(',', array_map('intval', array_values($candidateLegacy['supplier']))) . '))';
        }
        if ($candidateLegacy['reseller_api']) {
            $searchConditions[] = '(' . $resellerApiCandidateSql . ' AND t.reference_id IN (' . implode(',', array_map('intval', array_values($candidateLegacy['reseller_api']))) . '))';
        }

        // A bare number retains the old dual meaning: text plus transaction/order
        // ID. Prefixing TX or ORDER above selects the cheap exact path.
        if ($numericSearch !== null && $numericSearch > 0) {
            $searchConditions[] = 't.id = ?';
            $appendParam('i', $numericSearch);
            if ($cgoOrdersReadable) {
                $searchConditions[] = "EXISTS (SELECT 1 FROM cgo_orders co_id_search WHERE co_id_search.id=? AND " . $apiOrderLinkSql('co_id_search') . ')';
                $appendParam('i', $numericSearch);
            }
            if ($supplierOrdersReadable) {
                $searchConditions[] = "EXISTS (SELECT 1 FROM supplier_orders so_id_search WHERE so_id_search.id=? AND " . $storeOrderLinkSql('so_id_search') . ')';
                $appendParam('i', $numericSearch);
            }
            if ($storeApiOrdersReadable) {
                $searchConditions[] = "EXISTS (SELECT 1 FROM store_api_orders rao_id_search WHERE rao_id_search.id=? AND " . $resellerApiOrderLinkSql('rao_id_search') . ')';
                $appendParam('i', $numericSearch);
            }
        }
    }

    if ($searchConditions) $sql .= ' AND (' . implode(' OR ', $searchConditions) . ')';
}

$resultLimit = 500;
$needsExtendedScan = $filterStatus !== 'all'
    || $filterSource !== 'all'
    || $filterType !== 'all';
$scanLimit = $needsExtendedScan ? 5000 : ($resultLimit + 1);
$sql .= ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . $scanLimit;

$queryError = '';
$queryTechnicalError = '';
$rawTransactions = [];
$adminTxCoreQueryStarted = microtime(true);
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $queryError = $t('ไม่สามารถเตรียมคำสั่งค้นหาธุรกรรมได้', 'Unable to prepare the transaction query.');
    $queryTechnicalError = substr((string) $conn->error, 0, 800);
    error_log('Admin transaction prepare failed: ' . $queryTechnicalError . ' | SQL hash: ' . substr(hash('sha256', $sql), 0, 16));
} elseif (!adminTxBindParams($stmt, $types, $params)) {
    $queryError = $t('ตัวกรองมีรูปแบบไม่ถูกต้อง', 'The filter parameters could not be bound.');
    $queryTechnicalError = 'Parameter count/type mismatch';
    error_log('Admin transaction parameter binding failed');
    $stmt->close();
} elseif (!$stmt->execute()) {
    $queryError = $t('ไม่สามารถโหลดประวัติธุรกรรมได้', 'Unable to load transaction history.');
    $queryTechnicalError = substr((string) $stmt->error, 0, 800);
    error_log('Admin transaction query failed: ' . $queryTechnicalError);
    $stmt->close();
} else {
    $result = $stmt->get_result();
    $rawTransactions = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}
$adminTxCoreQueryMs = (int) round((microtime(true) - $adminTxCoreQueryStarted) * 1000);

$rawScannedCount = count($rawTransactions);
$resultTruncated = false;

// Hydrate API metadata separately. The authoritative link is
// cgo_orders.transaction_id -> transactions.id. reference_id and the external
// reference in the description are retained only as compatibility fallbacks.
$apiOrdersById = [];
$apiOrdersByTransactionId = [];
$apiOrderTransactionConflicts = [];
$apiOrdersByExternalRef = [];
$visibleTransactionIds = [];
$hintOrderIds = [];
$hintExternalRefs = [];
foreach ($rawTransactions as $row) {
    $txId = (int) ($row['id'] ?? 0);
    if ($txId > 0) $visibleTransactionIds[$txId] = $txId;

    if (adminTxApiSource($row) !== 'cgo') continue;
    $referenceId = (int) ($row['reference_id'] ?? 0);
    if ($referenceId > 0) $hintOrderIds[$referenceId] = $referenceId;
    $externalRef = adminTxExtractApiExternalRef((string) ($row['description'] ?? ''));
    if ($externalRef !== '') $hintExternalRefs[$externalRef] = $externalRef;
}

$walletRowsByTransactionId = $visibleTransactionIds && function_exists('walletLedgerRowsByTransactionIds')
    ? walletLedgerRowsByTransactionIds(array_values($visibleTransactionIds))
    : [];

// Admin-only bank-slip evidence. Deliberately select only the fields needed
// for operational review; raw provider payloads/images/API responses stay out
// of the transaction list.
$slipEvidenceByTransactionId = [];
$slipTransactionMap = [];
$slipDepositIds = [];
if ($slipDepositsReadable) {
    foreach ($rawTransactions as $row) {
        $txId = (int) ($row['id'] ?? 0);
        $depositId = (int) ($row['reference_id'] ?? 0);
        $description = strtolower(trim((string) ($row['description'] ?? '')));
        if ($txId < 1 || $depositId < 1 || strpos($description, 'slip verification') !== 0) continue;
        $slipTransactionMap[$depositId] = [
            'transaction_id' => $txId,
            'user_id' => (int) ($row['user_id'] ?? 0),
            'credited_at' => (string) ($row['created_at'] ?? ''),
        ];
        $slipDepositIds[$depositId] = $depositId;
    }
    if ($slipDepositIds) {
        $idList = implode(',', array_map('intval', array_values($slipDepositIds)));
        $slipResult = $conn->query(
            "SELECT id,user_id,transaction_ref,amount,sender_name,sender_account,bank_code,transfer_date,verified_at "
            . "FROM slip_deposits WHERE id IN ({$idList})"
        );
        if ($slipResult) {
            while ($slipRow = $slipResult->fetch_assoc()) {
                $depositId = (int) ($slipRow['id'] ?? 0);
                $map = $slipTransactionMap[$depositId] ?? null;
                if (!$map || (int) ($map['user_id'] ?? 0) !== (int) ($slipRow['user_id'] ?? 0)) continue;
                $txId = (int) ($map['transaction_id'] ?? 0);
                if ($txId < 1) continue;
                $slipRow['credited_at'] = (string) ($map['credited_at'] ?? '');
                $slipRow['provider_verified_at'] = null;
                $slipRow['job_completed_at'] = null;
                $slipRow['credit_amount'] = null;
                $slipRow['bonus_amount'] = null;
                $slipRow['total_credited'] = null;
                $slipEvidenceByTransactionId[$txId] = $slipRow;
            }
            $slipResult->free();
        }
    }
}

if ($slipJobsReadable && $slipEvidenceByTransactionId) {
    $txIds = implode(',', array_map('intval', array_keys($slipEvidenceByTransactionId)));
    $depositIds = implode(',', array_map('intval', array_values($slipDepositIds)));
    $selectJobFields = [
        'user_id', 'transaction_ref', 'provider_verified_at', 'slip_deposit_id',
        'deposit_transaction_id', 'completed_at'
    ];
    foreach (['credit_amount', 'bonus_amount', 'total_credited'] as $optionalColumn) {
        if (!empty($slipJobColumns[$optionalColumn])) $selectJobFields[] = $optionalColumn;
    }
    $jobSql = 'SELECT ' . implode(',', $selectJobFields) . ' FROM slip_verification_jobs WHERE '
        . "deposit_transaction_id IN ({$txIds}) OR slip_deposit_id IN ({$depositIds})";
    $jobResult = $conn->query($jobSql);
    if ($jobResult) {
        while ($job = $jobResult->fetch_assoc()) {
            $txId = (int) ($job['deposit_transaction_id'] ?? 0);
            if ($txId < 1 && (int) ($job['slip_deposit_id'] ?? 0) > 0) {
                $map = $slipTransactionMap[(int) $job['slip_deposit_id']] ?? null;
                $txId = (int) ($map['transaction_id'] ?? 0);
            }
            if ($txId < 1 || !isset($slipEvidenceByTransactionId[$txId])) continue;
            if ((int) ($job['user_id'] ?? 0) !== (int) ($slipEvidenceByTransactionId[$txId]['user_id'] ?? 0)) continue;
            $slipEvidenceByTransactionId[$txId]['provider_verified_at'] = $job['provider_verified_at'] ?? null;
            $slipEvidenceByTransactionId[$txId]['job_completed_at'] = $job['completed_at'] ?? null;
            foreach (['credit_amount', 'bonus_amount', 'total_credited'] as $field) {
                if (array_key_exists($field, $job)) $slipEvidenceByTransactionId[$txId][$field] = $job[$field];
            }
        }
        $jobResult->free();
    }
}

if ($cgoOrdersReadable && ($visibleTransactionIds || $hintOrderIds || $hintExternalRefs)) {
    $orderClauses = [];
    if (!empty($cgoOrderColumns['transaction_id']) && $visibleTransactionIds) {
        $idList = implode(',', array_map('intval', array_values($visibleTransactionIds)));
        $orderClauses[] = "transaction_id IN ({$idList})";
    }
    if ($hintOrderIds) {
        $idList = implode(',', array_map('intval', array_values($hintOrderIds)));
        $orderClauses[] = "id IN ({$idList})";
    }
    if (!empty($cgoOrderColumns['external_ref']) && $hintExternalRefs) {
        $quotedRefs = [];
        foreach ($hintExternalRefs as $externalRef) {
            $quotedRefs[] = "'" . $conn->real_escape_string($externalRef) . "'";
        }
        if ($quotedRefs) $orderClauses[] = 'external_ref IN (' . implode(',', $quotedRefs) . ')';
    }

    if ($orderClauses) {
        $orderResult = $conn->query('SELECT * FROM cgo_orders WHERE ' . implode(' OR ', $orderClauses));
        if ($orderResult) {
            while ($order = $orderResult->fetch_assoc()) {
                $orderId = (int) ($order['id'] ?? 0);
                if ($orderId < 1) continue;
                $apiOrdersById[$orderId] = $order;
                $transactionId = (int) ($order['transaction_id'] ?? 0);
                if ($transactionId > 0) {
                    if (isset($apiOrdersByTransactionId[$transactionId])
                        && (int) ($apiOrdersByTransactionId[$transactionId]['id'] ?? 0) !== $orderId) {
                        unset($apiOrdersByTransactionId[$transactionId]);
                        $apiOrderTransactionConflicts[$transactionId] = true;
                    } elseif (empty($apiOrderTransactionConflicts[$transactionId])) {
                        $apiOrdersByTransactionId[$transactionId] = $order;
                    }
                }
                $externalRef = trim((string) ($order['external_ref'] ?? ''));
                if ($externalRef !== '') $apiOrdersByExternalRef[$externalRef] = $order;
            }
        } else {
            error_log('Admin API order hydration failed: ' . $conn->error);
        }
    }
}

$storeOrdersById = [];
$storeOrdersByTransactionId = [];
$storeOrderTransactionConflicts = [];
$storeOrdersByExternalRef = [];
// transaction_id is authoritative evidence. Search Store Bridge orders for ALL
// visible transactions, not only rows whose legacy ENUM/description already
// happens to identify them as supplier purchases.
$storeTransactionIds = $visibleTransactionIds;
$storeHintOrderIds = [];
$storeHintExternalRefs = [];
foreach ($rawTransactions as $row) {
    if (adminTxApiSource($row) !== 'supplier') continue;
    $referenceId = (int) ($row['reference_id'] ?? 0);
    if ($referenceId > 0) $storeHintOrderIds[$referenceId] = $referenceId;
    $externalRef = adminTxExtractStoreBridgeExternalRef((string) ($row['description'] ?? ''));
    if ($externalRef !== '') $storeHintExternalRefs[$externalRef] = $externalRef;
}

if ($supplierOrdersReadable && ($storeTransactionIds || $storeHintOrderIds || $storeHintExternalRefs)) {
    $clauses = [];
    if ($storeTransactionIds) {
        $idList = implode(',', array_map('intval', array_values($storeTransactionIds)));
        $clauses[] = "so.transaction_id IN ({$idList})";
    }
    if ($storeHintOrderIds) {
        $idList = implode(',', array_map('intval', array_values($storeHintOrderIds)));
        $clauses[] = "so.id IN ({$idList})";
    }
    if ($storeHintExternalRefs) {
        $quotedRefs = [];
        foreach ($storeHintExternalRefs as $externalRef) {
            $quotedRefs[] = "'" . $conn->real_escape_string($externalRef) . "'";
        }
        if ($quotedRefs) $clauses[] = 'so.external_ref IN (' . implode(',', $quotedRefs) . ')';
    }

    if ($clauses) {
        $select = ['so.*'];
        $joins = [];
        if ($supplierConnectionsReadable) {
            $select[] = 'sc.name AS connection_name';
            $joins[] = 'LEFT JOIN supplier_connections sc ON sc.id=so.connection_id';
        }
        if ($supplierProductsReadable) {
            $select[] = 'sp.name AS supplier_product_name';
            $select[] = !empty($supplierProductColumns['duration']) ? 'sp.duration AS supplier_duration' : "'' AS supplier_duration";
            $select[] = !empty($supplierProductColumns['remote_product_id']) ? 'sp.remote_product_id AS supplier_remote_product_id' : "'' AS supplier_remote_product_id";
            $joins[] = 'LEFT JOIN supplier_products sp ON sp.id=so.supplier_product_id';
        }
        if (adminTxHasColumns($productColumns, ['id', 'name'])) {
            $select[] = 'slp.name AS local_product_name';
            $joins[] = 'LEFT JOIN products slp ON slp.id=so.local_product_id';
        }
        if (adminTxHasColumns($variantColumns, ['id'])) {
            $select[] = !empty($variantColumns['duration']) ? 'spv.duration AS local_duration' : "'' AS local_duration";
            $joins[] = 'LEFT JOIN product_variants spv ON spv.id=so.local_variant_id';
        }
        $storeSql = 'SELECT ' . implode(',', $select)
            . ' FROM supplier_orders so ' . implode(' ', $joins)
            . ' WHERE ' . implode(' OR ', $clauses);
        $storeResult = $conn->query($storeSql);
        if ($storeResult) {
            while ($order = $storeResult->fetch_assoc()) {
                $orderId = (int) ($order['id'] ?? 0);
                if ($orderId < 1) continue;
                $storeOrdersById[$orderId] = $order;
                $transactionId = (int) ($order['transaction_id'] ?? 0);
                if ($transactionId > 0) {
                    if (isset($storeOrdersByTransactionId[$transactionId])
                        && (int) ($storeOrdersByTransactionId[$transactionId]['id'] ?? 0) !== $orderId) {
                        unset($storeOrdersByTransactionId[$transactionId]);
                        $storeOrderTransactionConflicts[$transactionId] = true;
                    } elseif (empty($storeOrderTransactionConflicts[$transactionId])) {
                        $storeOrdersByTransactionId[$transactionId] = $order;
                    }
                }
                $externalRef = trim((string) ($order['external_ref'] ?? ''));
                if ($externalRef !== '') $storeOrdersByExternalRef[$externalRef] = $order;
            }
        } else {
            error_log('Admin Store Bridge order hydration failed: ' . $conn->error);
        }
    }
}

$resellerApiOrdersById = [];
$resellerApiOrdersByTransactionId = [];
$resellerApiOrderTransactionConflicts = [];
$resellerApiOrdersByExternalRef = [];
$resellerApiHintOrderIds = [];
$resellerApiHintExternalRefs = [];
foreach ($rawTransactions as $row) {
    if (adminTxApiSource($row) !== 'reseller_api') continue;
    $referenceId = (int) ($row['reference_id'] ?? 0);
    if ($referenceId > 0) $resellerApiHintOrderIds[$referenceId] = $referenceId;
    $externalRef = adminTxExtractResellerApiExternalRef((string) ($row['description'] ?? ''));
    if ($externalRef !== '') $resellerApiHintExternalRefs[$externalRef] = $externalRef;
}

if ($storeApiOrdersReadable && ($visibleTransactionIds || $resellerApiHintOrderIds || $resellerApiHintExternalRefs)) {
    $clauses = [];
    if ($visibleTransactionIds && !empty($storeApiOrderColumns['billing_transaction_id'])) {
        $idList = implode(',', array_map('intval', array_values($visibleTransactionIds)));
        $clauses[] = "rao.billing_transaction_id IN ({$idList})";
    }
    if ($resellerApiHintOrderIds) {
        $idList = implode(',', array_map('intval', array_values($resellerApiHintOrderIds)));
        $clauses[] = "rao.id IN ({$idList})";
    }
    if ($resellerApiHintExternalRefs) {
        $quotedRefs = [];
        foreach ($resellerApiHintExternalRefs as $externalRef) {
            $quotedRefs[] = "'" . $conn->real_escape_string($externalRef) . "'";
        }
        if ($quotedRefs) $clauses[] = 'rao.external_ref IN (' . implode(',', $quotedRefs) . ')';
    }

    if ($clauses) {
        $select = ['rao.*'];
        $joins = [];
        if ($storeApiClientsReadable) {
            $select[] = 'sac.name AS client_name';
            $select[] = !empty($storeApiClientColumns['website_name']) ? "COALESCE(sac.website_name,'') AS website_name" : "'' AS website_name";
            $select[] = !empty($storeApiClientColumns['client_type']) ? "COALESCE(sac.client_type,'') AS client_type" : "'' AS client_type";
            $joins[] = 'LEFT JOIN store_api_clients sac ON sac.id=rao.client_id';
        }
        if (adminTxHasColumns($productColumns, ['id', 'name']) && !empty($storeApiOrderColumns['source_product_id'])) {
            $select[] = 'rap.name AS local_product_name';
            $joins[] = 'LEFT JOIN products rap ON rap.id=rao.source_product_id';
        }
        if (adminTxHasColumns($variantColumns, ['id']) && !empty($storeApiOrderColumns['source_variant_id'])) {
            $select[] = !empty($variantColumns['duration']) ? "rav.duration AS local_duration" : "'' AS local_duration";
            $joins[] = 'LEFT JOIN product_variants rav ON rav.id=rao.source_variant_id';
        }
        $resellerSql = 'SELECT ' . implode(',', $select)
            . ' FROM store_api_orders rao ' . implode(' ', $joins)
            . ' WHERE ' . implode(' OR ', $clauses);
        $result = $conn->query($resellerSql);
        if ($result) {
            while ($order = $result->fetch_assoc()) {
                $orderId = (int) ($order['id'] ?? 0);
                if ($orderId < 1) continue;
                $resellerApiOrdersById[$orderId] = $order;
                $transactionId = (int) ($order['billing_transaction_id'] ?? 0);
                if ($transactionId > 0) {
                    if (isset($resellerApiOrdersByTransactionId[$transactionId])
                        && (int) ($resellerApiOrdersByTransactionId[$transactionId]['id'] ?? 0) !== $orderId) {
                        unset($resellerApiOrdersByTransactionId[$transactionId]);
                        $resellerApiOrderTransactionConflicts[$transactionId] = true;
                    } elseif (empty($resellerApiOrderTransactionConflicts[$transactionId])) {
                        $resellerApiOrdersByTransactionId[$transactionId] = $order;
                    }
                }
                $externalRef = trim((string) ($order['external_ref'] ?? ''));
                if ($externalRef !== '') $resellerApiOrdersByExternalRef[$externalRef] = $order;
            }
        } else {
            error_log('Admin reseller Store API order hydration failed: ' . $conn->error);
        }
    }
}

$apiOrderIds = [];
foreach ($apiOrdersById as $orderId => $_order) {
    $orderId = (int) $orderId;
    if ($orderId > 0) $apiOrderIds[$orderId] = $orderId;
}

$cgoProductsById = [];
$localProductsById = [];
$variantsById = [];
$cgoProductIds = [];
$localProductIds = [];
$variantIds = [];
foreach ($apiOrdersById as $order) {
    $id = (int) ($order['cgo_product_id'] ?? 0);
    if ($id > 0) $cgoProductIds[$id] = $id;
    $id = (int) ($order['local_product_id'] ?? 0);
    if ($id > 0) $localProductIds[$id] = $id;
    $id = (int) ($order['local_variant_id'] ?? 0);
    if ($id > 0) $variantIds[$id] = $id;
}

if ($cgoProductsReadable && $cgoProductIds) {
    $idList = implode(',', array_map('intval', array_values($cgoProductIds)));
    $result = $conn->query("SELECT * FROM cgo_products WHERE id IN ({$idList})");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $cgoProductsById[$id] = $row;
        }
    }
}
if (adminTxHasColumns($productColumns, ['id', 'name']) && $localProductIds) {
    $idList = implode(',', array_map('intval', array_values($localProductIds)));
    $result = $conn->query("SELECT * FROM products WHERE id IN ({$idList})");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $localProductsById[$id] = $row;
        }
    }
}
if (adminTxHasColumns($variantColumns, ['id']) && $variantIds) {
    $idList = implode(',', array_map('intval', array_values($variantIds)));
    $result = $conn->query("SELECT * FROM product_variants WHERE id IN ({$idList})");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $variantsById[$id] = $row;
        }
    }
}

foreach ($rawTransactions as &$row) {
    $txId = (int) ($row['id'] ?? 0);
    $referenceId = (int) ($row['reference_id'] ?? 0);
    $apiSource = adminTxApiSource($row);
    $hasCgoTransactionLink = isset($apiOrdersByTransactionId[$txId]);
    $hasStoreTransactionLink = isset($storeOrdersByTransactionId[$txId]);
    $hasResellerApiTransactionLink = isset($resellerApiOrdersByTransactionId[$txId]);
    $apiLinkConflict = !empty($apiOrderTransactionConflicts[$txId])
        || !empty($storeOrderTransactionConflicts[$txId])
        || !empty($resellerApiOrderTransactionConflicts[$txId]);

    $linkedSourceCount = (int) $hasCgoTransactionLink + (int) $hasStoreTransactionLink + (int) $hasResellerApiTransactionLink;
    if ($linkedSourceCount > 1) {
        // A transaction cannot safely represent two provider/order namespaces.
        // Flag it instead of silently choosing one and hiding an integrity issue.
        $apiLinkConflict = true;
    } elseif ($hasResellerApiTransactionLink) {
        $apiSource = 'reseller_api';
    } elseif ($hasStoreTransactionLink) {
        $apiSource = 'supplier';
    } elseif ($hasCgoTransactionLink) {
        $apiSource = 'cgo';
    }

    $row['_api_link_conflict'] = $apiLinkConflict ? 1 : 0;
    $order = null;
    $linkMethod = '';
    $externalRefHint = '';

    if ($apiSource === 'reseller_api') {
        $externalRefHint = adminTxExtractResellerApiExternalRef((string) ($row['description'] ?? ''));
        $order = $resellerApiOrdersByTransactionId[$txId] ?? null;
        $linkMethod = $order ? 'billing_transaction_id' : '';
        if (!$order && $referenceId > 0) {
            $order = $resellerApiOrdersById[$referenceId] ?? null;
            if ($order) $linkMethod = 'reference_id';
        }
        if (!$order && $externalRefHint !== '') {
            $order = $resellerApiOrdersByExternalRef[$externalRefHint] ?? null;
            if ($order) $linkMethod = 'external_ref';
        }
    } elseif ($apiSource === 'supplier') {
        $externalRefHint = adminTxExtractStoreBridgeExternalRef((string) ($row['description'] ?? ''));
        $order = $storeOrdersByTransactionId[$txId] ?? null;
        $linkMethod = $order ? 'transaction_id' : '';
        if (!$order && $referenceId > 0) {
            $order = $storeOrdersById[$referenceId] ?? null;
            if ($order) $linkMethod = 'reference_id';
        }
        if (!$order && $externalRefHint !== '') {
            $order = $storeOrdersByExternalRef[$externalRefHint] ?? null;
            if ($order) $linkMethod = 'external_ref';
        }
    } elseif ($apiSource === 'cgo') {
        $externalRefHint = adminTxExtractApiExternalRef((string) ($row['description'] ?? ''));
        $order = $apiOrdersByTransactionId[$txId] ?? null;
        $linkMethod = $order ? 'transaction_id' : '';
        if (!$order && $referenceId > 0) {
            $order = $apiOrdersById[$referenceId] ?? null;
            if ($order) $linkMethod = 'reference_id';
        }
        if (!$order && $externalRefHint !== '') {
            $order = $apiOrdersByExternalRef[$externalRefHint] ?? null;
            if ($order) $linkMethod = 'external_ref';
        }
    }

    $isApiPurchase = $apiSource !== '' || is_array($order);
    $row['_is_api_purchase'] = $isApiPurchase ? 1 : 0;
    $row['_api_source'] = $apiSource;
    $row['_api_link_method'] = $linkMethod;
    if (!$isApiPurchase) {
        $row['_effective_type'] = adminTxEffectiveType($row);
        continue;
    }

    $order = is_array($order) ? $order : [];
    $orderId = (int) ($order['id'] ?? ($referenceId > 0 ? $referenceId : 0));
    $apiProductName = '';
    $apiDuration = '';
    $connectionName = '';

    if ($apiSource === 'reseller_api') {
        $apiProductName = trim((string) ($order['local_product_name'] ?? ''));
        $apiDuration = trim((string) ($order['local_duration'] ?? $order['duration'] ?? ''));
        $clientName = trim((string) ($order['client_name'] ?? ''));
        $websiteName = trim((string) ($order['website_name'] ?? ''));
        $connectionName = $clientName !== '' && $websiteName !== '' ? $clientName . ' · ' . $websiteName : ($clientName ?: $websiteName);
        if ($apiProductName === '') {
            $description = (string) ($row['description'] ?? '');
            if (preg_match('/\s-\s(.+?)(?:\s+x\d+)?\s*$/u', $description, $matches) === 1) {
                $apiProductName = trim((string) $matches[1]);
            }
        }
    } elseif ($apiSource === 'supplier') {
        $apiProductName = trim((string) ($order['local_product_name'] ?? $order['supplier_product_name'] ?? ''));
        $apiDuration = trim((string) ($order['local_duration'] ?? $order['supplier_duration'] ?? ''));
        $connectionName = trim((string) ($order['connection_name'] ?? ''));
        if ($apiProductName === '') {
            $description = (string) ($row['description'] ?? '');
            if (preg_match('/:\s*(.+?)(?:\s+x\d+)?\s*$/u', $description, $matches) === 1) {
                $apiProductName = trim((string) $matches[1]);
            }
        }
    } else {
        $cgoProduct = $cgoProductsById[(int) ($order['cgo_product_id'] ?? 0)] ?? [];
        $localProduct = $localProductsById[(int) ($order['local_product_id'] ?? 0)] ?? [];
        $variant = $variantsById[(int) ($order['local_variant_id'] ?? 0)] ?? [];
        $apiProductName = trim((string) ($localProduct['name'] ?? ''));
        if ($apiProductName === '') {
            $remoteName = trim((string) ($cgoProduct['name'] ?? ''));
            $brand = trim((string) ($cgoProduct['brand'] ?? ''));
            $apiProductName = $brand !== '' && $remoteName !== '' ? $brand . ' - ' . $remoteName : $remoteName;
        }
        $apiDuration = trim((string) ($variant['duration'] ?? ''));
        if ($apiDuration === '') $apiDuration = trim((string) ($cgoProduct['duration'] ?? ''));
        $connectionName = 'CGO';
    }

    if ($apiProductName === '') $apiProductName = $apiSource === 'reseller_api' ? 'Store API' : ($apiSource === 'supplier' ? 'Store Bridge API' : 'CGO API');
    $orderKey = ($apiSource !== '' ? $apiSource : 'api') . ':' . $orderId;
    $row['api_source'] = $apiSource !== '' ? $apiSource : 'cgo';
    $row['api_order_key'] = $orderKey;
    $row['api_order_id'] = $orderId;
    $row['api_external_ref'] = (string) ($order['external_ref'] ?? $externalRefHint);
    $row['api_quantity'] = isset($order['quantity']) ? (int) $order['quantity'] : 1;
    $row['api_order_status'] = (string) ($order['status'] ?? ($row['status'] ?? ''));
    $row['api_supplier_order_id'] = (string) ($order['supplier_order_id'] ?? '');
    $row['api_connection_name'] = $connectionName;
    $row['api_local_product_id'] = $apiSource === 'reseller_api'
        ? (int) ($order['source_product_id'] ?? 0)
        : (isset($order['local_product_id']) ? (int) $order['local_product_id'] : 0);
    $row['api_total_price'] = $apiSource === 'reseller_api'
        ? (float) ($order['total_price'] ?? ($row['amount'] ?? 0))
        : (isset($order['total_price_base']) ? (float) $order['total_price_base'] : (float) ($row['amount'] ?? 0));
    $row['api_product_name'] = $apiProductName;
    $row['api_duration'] = $apiDuration;
    $row['api_client_id'] = (int) ($order['client_id'] ?? 0);
    $row['api_client_name'] = (string) ($order['client_name'] ?? '');
    $row['api_website_name'] = (string) ($order['website_name'] ?? '');
    $row['api_client_type'] = (string) ($order['client_type'] ?? '');
    $row['api_billing_mode'] = (string) ($order['billing_mode'] ?? '');
    $row['api_balance_before'] = array_key_exists('balance_before', $order) && $order['balance_before'] !== null ? (float) $order['balance_before'] : null;
    $row['api_balance_after'] = array_key_exists('balance_after', $order) && $order['balance_after'] !== null ? (float) $order['balance_after'] : null;
    $row['api_origin_site_id'] = (string) ($order['origin_site_id'] ?? '');
    $row['api_origin_user_id'] = (string) ($order['origin_user_id'] ?? '');
    $row['api_customer_ref'] = (string) ($order['customer_ref'] ?? '');
    $row['api_customer_name'] = (string) ($order['customer_name'] ?? '');
    $row['api_customer_email'] = (string) ($order['customer_email'] ?? '');
    $row['_effective_type'] = adminTxEffectiveType($row);
}
unset($row);

$apiKeysByOrder = [];
$apiKeyRecoverySource = [];
$apiExpectedByOrder = [];

foreach ($rawTransactions as $row) {
    if (empty($row['_is_api_purchase'])) continue;
    $orderId = (int) ($row['api_order_id'] ?? $row['reference_id'] ?? 0);
    if ($orderId < 1) continue;
    $orderKey = (string) ($row['api_order_key'] ?? (($row['api_source'] ?? 'cgo') . ':' . $orderId));
    $apiExpectedByOrder[$orderKey] = max(1, (int) ($row['api_quantity'] ?? 1));
}

if ($cgoOrderKeysReadable && $apiOrderIds) {
    $idList = implode(',', array_map('intval', array_values($apiOrderIds)));
    $orderBy = !empty($cgoOrderKeyColumns['id']) ? 'order_id ASC, id ASC' : 'order_id ASC';
    $keysResult = $conn->query(
        "SELECT order_id, key_code
         FROM cgo_order_keys
         WHERE order_id IN ({$idList})
         ORDER BY {$orderBy}"
    );
    if ($keysResult) {
        while ($keyRow = $keysResult->fetch_assoc()) {
            $orderId = (int) ($keyRow['order_id'] ?? 0);
            $keyCode = trim((string) ($keyRow['key_code'] ?? ''));
            if ($orderId < 1 || $keyCode === '') continue;
            $orderKey = 'cgo:' . $orderId;
            if (!isset($apiKeysByOrder[$orderKey])) $apiKeysByOrder[$orderKey] = [];
            $apiKeysByOrder[$orderKey][] = $keyCode;
            $apiKeyRecoverySource[$orderKey] = 'stored_keys';
        }
    } else {
        error_log('Admin API key lookup failed: ' . $conn->error);
    }
}

// Historical delivery data can be reconstructed from the saved supplier
// response. Account products are normalized to one structured account item,
// rather than counting each label/value line as another delivered key.
if (function_exists('cgoExtractDeliveryItems')) {
    foreach ($apiOrdersById as $orderId => $order) {
        $orderKey = 'cgo:' . (int) $orderId;
        $expected = $apiExpectedByOrder[$orderKey] ?? max(1, (int) ($order['quantity'] ?? 1));
        $existing = $apiKeysByOrder[$orderKey] ?? [];
        $productContext = $cgoProductsById[(int) ($order['cgo_product_id'] ?? 0)] ?? [];

        $looksLikeAccount = function_exists('cgoProductLooksLikeAccount')
            && cgoProductLooksLikeAccount($productContext);
        if (!$looksLikeAccount && function_exists('cgoDeliveryValueIsAccount')) {
            foreach ($existing as $storedValue) {
                if (cgoDeliveryValueIsAccount((string) $storedValue)) {
                    $looksLikeAccount = true;
                    break;
                }
            }
        }

        $responseJson = trim((string) ($order['response_json'] ?? ''));
        if ($responseJson === '') continue;
        if (!$looksLikeAccount && count($existing) >= $expected) continue;

        $decoded = json_decode($responseJson, true);
        if (!is_array($decoded)) continue;
        $deliveryItems = cgoExtractDeliveryItems($decoded);
        if (!$deliveryItems) continue;
        $recovered = array_values(array_filter(array_map(
            static fn(array $item): string => trim((string) ($item['value'] ?? '')),
            $deliveryItems
        ), static fn(string $value): bool => $value !== ''));
        if (!$recovered) continue;

        $hasAccount = false;
        foreach ($deliveryItems as $deliveryItem) {
            if ((string) ($deliveryItem['type'] ?? '') === 'account') {
                $hasAccount = true;
                break;
            }
        }

        // A complete, structured response is more authoritative than legacy
        // rows that were produced by splitting the same account into lines.
        if (count($recovered) === $expected && ($hasAccount || $looksLikeAccount || count($existing) !== $expected)) {
            $apiKeysByOrder[$orderKey] = $recovered;
            $apiKeyRecoverySource[$orderKey] = $existing
                ? 'normalized_saved_response'
                : 'saved_response';
            continue;
        }

        if (count($existing) < $expected) {
            $beforeCount = count($existing);
            foreach ($recovered as $keyCode) {
                if (!in_array($keyCode, $existing, true)) $existing[] = $keyCode;
            }
            $apiKeysByOrder[$orderKey] = $existing;
            if (count($existing) > $beforeCount) {
                $apiKeyRecoverySource[$orderKey] = $beforeCount > 0 ? 'stored_and_response' : 'saved_response';
            }
        }
    }
}

$storeOrderIds = [];
foreach ($storeOrdersById as $orderId => $_order) {
    $orderId = (int) $orderId;
    if ($orderId > 0) $storeOrderIds[$orderId] = $orderId;
}

if ($supplierOrderKeysReadable && $storeOrderIds) {
    $idList = implode(',', array_map('intval', array_values($storeOrderIds)));
    $orderBy = !empty($supplierOrderKeyColumns['id']) ? 'order_id ASC, id ASC' : 'order_id ASC';
    $keysResult = $conn->query(
        "SELECT order_id,key_code FROM supplier_order_keys WHERE order_id IN ({$idList}) ORDER BY {$orderBy}"
    );
    if ($keysResult) {
        while ($keyRow = $keysResult->fetch_assoc()) {
            $orderId = (int) ($keyRow['order_id'] ?? 0);
            $keyCode = trim((string) ($keyRow['key_code'] ?? ''));
            if ($orderId < 1 || $keyCode === '') continue;
            $orderKey = 'supplier:' . $orderId;
            if (!isset($apiKeysByOrder[$orderKey])) $apiKeysByOrder[$orderKey] = [];
            $apiKeysByOrder[$orderKey][] = $keyCode;
            $apiKeyRecoverySource[$orderKey] = 'stored_keys';
        }
    } else {
        error_log('Admin Store Bridge key lookup failed: ' . $conn->error);
    }
}

if (function_exists('supplierBridgeExtractKeys')) {
    foreach ($storeOrdersById as $orderId => $order) {
        $orderKey = 'supplier:' . (int) $orderId;
        $expected = $apiExpectedByOrder[$orderKey] ?? max(1, (int) ($order['quantity'] ?? 1));
        $existing = $apiKeysByOrder[$orderKey] ?? [];
        if (count($existing) >= $expected) continue;
        $responseJson = trim((string) ($order['response_json'] ?? ''));
        if ($responseJson === '') continue;
        $decoded = json_decode($responseJson, true);
        if (!is_array($decoded)) continue;
        $recovered = supplierBridgeExtractKeys($decoded);
        if (!$recovered) continue;
        $beforeCount = count($existing);
        foreach ($recovered as $keyCode) {
            $keyCode = trim((string) $keyCode);
            if ($keyCode !== '' && !in_array($keyCode, $existing, true)) $existing[] = $keyCode;
        }
        $apiKeysByOrder[$orderKey] = $existing;
        if (count($existing) > $beforeCount) {
            $apiKeyRecoverySource[$orderKey] = $beforeCount > 0 ? 'stored_and_response' : 'saved_response';
        }
    }
}

$resellerApiOrderIds = [];
foreach ($resellerApiOrdersById as $orderId => $_order) {
    $orderId = (int) $orderId;
    if ($orderId > 0) $resellerApiOrderIds[$orderId] = $orderId;
}
if ($storeApiOrderKeysReadable && $resellerApiOrderIds) {
    $idList = implode(',', array_map('intval', array_values($resellerApiOrderIds)));
    $orderBy = !empty($storeApiOrderKeyColumns['id']) ? 'order_id ASC,id ASC' : 'order_id ASC';
    $result = $conn->query("SELECT order_id,key_code FROM store_api_order_keys WHERE order_id IN ({$idList}) ORDER BY {$orderBy}");
    if ($result) {
        while ($keyRow = $result->fetch_assoc()) {
            $orderId = (int) ($keyRow['order_id'] ?? 0);
            $keyCode = trim((string) ($keyRow['key_code'] ?? ''));
            if ($orderId < 1 || $keyCode === '') continue;
            $orderKey = 'reseller_api:' . $orderId;
            if (!isset($apiKeysByOrder[$orderKey])) $apiKeysByOrder[$orderKey] = [];
            $apiKeysByOrder[$orderKey][] = $keyCode;
            $apiKeyRecoverySource[$orderKey] = 'stored_keys';
        }
    } else {
        error_log('Admin reseller Store API key lookup failed: ' . $conn->error);
    }
}

foreach ($apiKeysByOrder as $orderId => $keys) {
    $apiKeysByOrder[$orderId] = array_values(array_unique(array_filter(
        array_map('strval', $keys),
        static fn(string $key): bool => trim($key) !== ''
    )));
}

if ($filterType !== 'all' || $filterSource !== 'all' || $filterStatus !== 'all') {
    $rawTransactions = array_values(array_filter(
        $rawTransactions,
        static function (array $row) use ($filterType, $filterSource, $filterStatus): bool {
            $txType = strtolower(trim((string) ($row['_effective_type'] ?? adminTxEffectiveType($row))));
            $isApi = !empty($row['_is_api_purchase']);

            $typeMatches = true;
            if ($filterType === 'purchase') {
                $typeMatches = $isApi || in_array($txType, ['purchase', 'store_api_purchase'], true);
            } elseif ($filterType === 'deposit') {
                $typeMatches = in_array($txType, ['deposit', 'redeem_code'], true);
            } elseif ($filterType === 'refund') {
                // Refunds are first-class wallet evidence appended below, not a
                // renamed failed purchase transaction.
                $typeMatches = false;
            } elseif ($filterType === 'adjustment') {
                $typeMatches = in_array($txType, ['manual_add', 'manual_deduct'], true);
            } elseif ($filterType === 'bonus') {
                $typeMatches = $txType === 'rank_bonus';
            } elseif ($filterType === 'other') {
                $typeMatches = !$isApi && !in_array($txType, [
                    'purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_purchase', 'deposit', 'redeem_code',
                    'manual_add', 'manual_deduct', 'rank_bonus',
                ], true);
            }
            if (!$typeMatches) return false;

            $apiSource = (string) ($row['api_source'] ?? $row['_api_source'] ?? '');
            if ($filterSource === 'api' && !$isApi) return false;
            if ($filterSource === 'cgo' && (!$isApi || $apiSource !== 'cgo')) return false;
            if ($filterSource === 'supplier' && (!$isApi || $apiSource !== 'supplier')) return false;
            if ($filterSource === 'reseller_api' && (!$isApi || $apiSource !== 'reseller_api')) return false;
            if ($filterSource === 'local' && ($isApi || $txType !== 'purchase')) return false;

            $status = (string) ($row['status'] ?? '');
            if ($isApi) {
                $apiStatus = trim((string) ($row['api_order_status'] ?? ''));
                if ($apiStatus !== '') $status = $apiStatus;
            }
            return adminTxStatusMatchesFilter($status, $filterStatus);
        }
    ));
}

$resultTruncated = $searchCandidateTruncated
    || count($rawTransactions) > $resultLimit
    || ($needsExtendedScan && $rawScannedCount >= $scanLimit);
if (count($rawTransactions) > $resultLimit) {
    $rawTransactions = array_slice($rawTransactions, 0, $resultLimit);
}

// New local purchases write an explicit activity event containing the exact
// first/last transaction IDs for one checkout. Use that order boundary when it
// exists instead of guessing that every same-second purchase belongs together.
$localActivityByTransactionId = [];
$localActivityConflicts = [];
if ($purchaseActivityReadable && $rawTransactions) {
    $activityVisibleIds = [];
    foreach ($rawTransactions as $transaction) {
        $txId = (int) ($transaction['id'] ?? 0);
        if ($txId > 0) $activityVisibleIds[$txId] = $txId;
    }
    if ($activityVisibleIds) {
        $idList = implode(',', array_map('intval', array_values($activityVisibleIds)));
        try {
            $activityResult = $conn->query(
                "SELECT t.id AS transaction_id,e.*
                 FROM transactions t
                 INNER JOIN purchase_activity_events e
                    ON e.user_id=t.user_id
                   AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                   AND LOWER(TRIM(COALESCE(e.source,'')))='local'
                 WHERE t.id IN ({$idList})
                 ORDER BY e.id ASC"
            );
            if ($activityResult) {
                while ($activityRow = $activityResult->fetch_assoc()) {
                    $transactionId = (int) ($activityRow['transaction_id'] ?? 0);
                    $eventId = (int) ($activityRow['id'] ?? 0);
                    if ($transactionId < 1 || $eventId < 1) continue;
                    unset($activityRow['transaction_id']);
                    if (isset($localActivityByTransactionId[$transactionId])
                        && (int) ($localActivityByTransactionId[$transactionId]['id'] ?? 0) !== $eventId) {
                        unset($localActivityByTransactionId[$transactionId]);
                        $localActivityConflicts[$transactionId] = true;
                    } elseif (empty($localActivityConflicts[$transactionId])) {
                        $localActivityByTransactionId[$transactionId] = $activityRow;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Admin local purchase activity hydration failed: ' . $e->getMessage());
        }
    }
}

foreach ($rawTransactions as &$transaction) {
    $txId = (int) ($transaction['id'] ?? 0);
    $activity = $localActivityByTransactionId[$txId] ?? null;
    $transaction['_local_activity_event_id'] = is_array($activity) ? (int) ($activity['id'] ?? 0) : 0;
    $transaction['_local_activity_conflict'] = !empty($localActivityConflicts[$txId]) ? 1 : 0;
}
unset($transaction);

$groupedItems = [];
$stats = [
    'records' => count($rawTransactions),
    'purchase_total' => 0.0,
    'api_orders' => 0,
    'attention' => 0,
];

foreach ($rawTransactions as $transaction) {
    $txType = strtolower(trim((string) ($transaction['_effective_type'] ?? adminTxEffectiveType($transaction))));
    $isApiPurchase = !empty($transaction['_is_api_purchase']);
    $txStatus = adminTxNormalizeStatus($transaction['status'] ?? '');
    $amount = round((float) ($transaction['amount'] ?? 0), 2);

    $effectivePurchaseStatus = $txStatus;
    if ($isApiPurchase) {
        $apiStatusForTotal = adminTxNormalizeStatus($transaction['api_order_status'] ?? '');
        if ($apiStatusForTotal !== '') $effectivePurchaseStatus = $apiStatusForTotal;
    }

    if (
        (in_array($txType, ['purchase', 'store_api_purchase'], true) || $isApiPurchase)
        && in_array($effectivePurchaseStatus, ['completed', 'success'], true)
    ) {
        $stats['purchase_total'] = round($stats['purchase_total'] + $amount, 2);
    }

    if ($isApiPurchase) {
        $stats['api_orders']++;
        $apiLinkConflict = !empty($transaction['_api_link_conflict']);

        $apiSource = (string) ($transaction['api_source'] ?? $transaction['_api_source'] ?? 'cgo');
        if (!in_array($apiSource, ['cgo', 'supplier', 'reseller_api'], true)) $apiSource = 'cgo';
        $orderId = (int) ($transaction['api_order_id'] ?? $transaction['reference_id'] ?? 0);
        $orderKey = (string) ($transaction['api_order_key'] ?? ($apiSource . ':' . $orderId));
        $orderStatus = adminTxNormalizeStatus($transaction['api_order_status'] ?? '');
        if ($orderStatus === '') $orderStatus = $txStatus;

        $keys = $orderId > 0 ? ($apiKeysByOrder[$orderKey] ?? []) : [];
        $rawQuantity = $transaction['api_quantity'] ?? null;
        $quantity = max(
            1,
            is_numeric($rawQuantity)
                ? (int) $rawQuantity
                : (count($keys) > 0 ? count($keys) : 1)
        );

        $missingDeliveredKeys = in_array($orderStatus, ['success', 'completed'], true)
            && count($keys) < $quantity;

        if (
            in_array(
                $orderStatus,
                ['unknown', 'manual_review', 'processing', 'submitting', 'refunded_conflict'],
                true
            )
            || in_array($txStatus, ['pending', 'failed'], true)
            || $missingDeliveredKeys
            || $apiLinkConflict
        ) {
            $stats['attention']++;
        }

        $productName = trim((string) ($transaction['api_product_name'] ?? ''));
        $duration = trim((string) ($transaction['api_duration'] ?? ''));

        if ($productName === '') {
            $productName = trim((string) ($transaction['description'] ?? 'API purchase'));
        }

        if (
            $duration !== ''
            && strcasecmp($duration, 'Standard') !== 0
            && stripos($productName, $duration) === false
        ) {
            $productName .= ' ' . $duration;
        }

        $localProductId = (int) ($transaction['api_local_product_id'] ?? 0);
        $downloadUrl = '';

        if ($localProductId > 0) {
            $download = getProductDownloadUrl($localProductId);
            $downloadUrl = is_array($download) ? (string) ($download['url'] ?? '') : '';
        }

        $keyHistoryState = 'available';
        if (!$keys) {
            if ($apiSource === 'reseller_api') {
                if (!$storeApiOrdersReadable) {
                    $keyHistoryState = 'api_table_unavailable';
                } elseif (!isset($resellerApiOrdersById[$orderId])) {
                    $keyHistoryState = 'order_record_missing';
                } elseif (!$storeApiOrderKeysReadable) {
                    $keyHistoryState = 'key_storage_unavailable';
                } else {
                    $keyHistoryState = 'no_historical_key_data';
                }
            } else {
                $ordersReadable = $apiSource === 'supplier' ? $supplierOrdersReadable : $cgoOrdersReadable;
                $keysReadable = $apiSource === 'supplier' ? $supplierOrderKeysReadable : $cgoOrderKeysReadable;
                $sourceOrders = $apiSource === 'supplier' ? $storeOrdersById : $apiOrdersById;
                if (!$ordersReadable) {
                    $keyHistoryState = 'api_table_unavailable';
                } elseif (!isset($sourceOrders[$orderId])) {
                    $keyHistoryState = 'order_record_missing';
                } else {
                    $savedResponse = trim((string) ($sourceOrders[$orderId]['response_json'] ?? ''));
                    if (!$keysReadable && $savedResponse === '') {
                        $keyHistoryState = 'key_storage_unavailable';
                    } elseif ($savedResponse === '') {
                        $keyHistoryState = 'no_historical_key_data';
                    } else {
                        $keyHistoryState = 'no_recognized_key_in_response';
                    }
                }
            }
        }

        $groupedItems['api_' . $apiSource . '_' . (int) ($transaction['id'] ?? 0)] = [
            'type' => 'purchase_group',
            'source' => $apiSource,
            'api_source' => $apiSource,
            'username' => (string) ($transaction['username'] ?? ''),
            'role' => (string) ($transaction['role'] ?? ''),
            'user_id' => (int) ($transaction['user_id'] ?? 0),
            'transaction_ids' => [(int) ($transaction['id'] ?? 0)],
            'product_name' => $productName,
            'display_date' => date('Y-m-d H:i:s', strtotime((string) $transaction['created_at'])),
            'keys' => $keys,
            'key_recovery_source' => (string) ($apiKeyRecoverySource[$orderKey] ?? ''),
            'key_history_state' => $keyHistoryState,
            'expected_quantity' => $quantity,
            'total_price' => $amount,
            'price_per_item' => $quantity > 0 ? round($amount / $quantity, 2) : $amount,
            'raw_date' => (string) $transaction['created_at'],
            'download_url' => $downloadUrl,
            'status' => $orderStatus,
            'tx_status' => $txStatus,
            'order_id' => $orderId,
            'external_ref' => (string) ($transaction['api_external_ref'] ?? ''),
            'supplier_order_id' => (string) ($transaction['api_supplier_order_id'] ?? ''),
            'connection_name' => (string) ($transaction['api_connection_name'] ?? ''),
            'client_id' => (int) ($transaction['api_client_id'] ?? 0),
            'client_name' => (string) ($transaction['api_client_name'] ?? ''),
            'website_name' => (string) ($transaction['api_website_name'] ?? ''),
            'client_type' => (string) ($transaction['api_client_type'] ?? ''),
            'billing_mode' => (string) ($transaction['api_billing_mode'] ?? ''),
            'balance_before' => $transaction['api_balance_before'] ?? null,
            'balance_after' => $transaction['api_balance_after'] ?? null,
            'origin_site_id' => (string) ($transaction['api_origin_site_id'] ?? ''),
            'origin_user_id' => (string) ($transaction['api_origin_user_id'] ?? ''),
            'customer_ref' => (string) ($transaction['api_customer_ref'] ?? ''),
            'customer_name' => (string) ($transaction['api_customer_name'] ?? ''),
            'customer_email' => (string) ($transaction['api_customer_email'] ?? ''),
            'api_link_conflict' => $apiLinkConflict ? 1 : 0,
            'api_link_method' => (string) ($transaction['_api_link_method'] ?? ''),
            'description' => (string) ($transaction['description'] ?? ''),
            'wallet_rows' => adminTxNonRefundWalletRows($walletRowsByTransactionId[(int) ($transaction['id'] ?? 0)] ?? []),
        ];

        continue;
    }

    if ($txType === 'purchase') {
        $description = (string) ($transaction['description'] ?? '');
        $fallback = adminTxLocalPurchaseFallback($description);

        $keyCode = trim((string) ($transaction['key_code'] ?? ''));
        if ($keyCode === '') $keyCode = (string) ($fallback['key_code'] ?? '');

        $productId = (int) ($transaction['product_id'] ?? 0);
        $createdAt = (string) ($transaction['created_at'] ?? '');
        $timeKey = date('Y-m-d H:i:s', strtotime($createdAt));
        $storedProductName = trim((string) ($transaction['product_name'] ?? ''));
        $fallbackProductName = trim((string) ($fallback['product_name'] ?? ''));
        $durationForGroup = trim((string) ($transaction['duration'] ?? ''));
        $groupIdentity = strtolower(
            ($productId > 0 ? 'product:' . $productId : 'name:' . ($storedProductName ?: $fallbackProductName))
            . '|duration:' . $durationForGroup
        );

        $activityEventId = (int) ($transaction['_local_activity_event_id'] ?? 0);
        $activityConflict = !empty($transaction['_local_activity_conflict']);
        if ($activityConflict) {
            // Never guess across an overlapping/corrupt activity range. Keep the
            // transaction isolated and make the anomaly visible to diagnostics.
            $groupKey = 'purchase_activity_conflict_tx_' . (int) ($transaction['id'] ?? 0);
            $stats['attention']++;
        } elseif ($activityEventId > 0) {
            $groupKey = 'purchase_event_' . $activityEventId;
        } else {
            // Compatibility fallback for purchases created before precise
            // purchase_activity_events existed.
            $groupKey = implode('_', [
                'purchase',
                $timeKey,
                (int) ($transaction['user_id'] ?? 0),
                substr(hash('sha256', $groupIdentity), 0, 16),
                number_format($amount, 2, '.', ''),
                $txStatus,
            ]);
        }

        if (!isset($groupedItems[$groupKey])) {
            $productName = $storedProductName;
            $duration = $durationForGroup;

            if ($productName === '') {
                $productName = trim((string) ($fallback['product_name'] ?? ''));
            }

            if ($productName === '') {
                $productName = trim($description) !== ''
                    ? trim($description)
                    : $t('ซื้อคีย์ภายในร้าน', 'Local key purchase');
            }

            if (
                $duration !== ''
                && strcasecmp($duration, 'Standard') !== 0
                && stripos($productName, $duration) === false
            ) {
                $productName .= ' ' . $duration;
            }

            $downloadUrl = '';
            if ($productId > 0) {
                $download = getProductDownloadUrl($productId);
                $downloadUrl = is_array($download) ? (string) ($download['url'] ?? '') : '';
            }

            $groupedItems[$groupKey] = [
                'type' => 'purchase_group',
                'source' => 'local',
                'username' => (string) ($transaction['username'] ?? ''),
                'role' => (string) ($transaction['role'] ?? ''),
                'user_id' => (int) ($transaction['user_id'] ?? 0),
                'transaction_ids' => [],
                'product_name' => $productName,
                'display_date' => $timeKey,
                'keys' => [],
                'key_recovery_source' => (
                    $keyCode !== ''
                    && trim((string) ($transaction['key_code'] ?? '')) === ''
                ) ? 'transaction_description' : 'stored_key',
                'expected_quantity' => 0,
                'total_price' => 0.0,
                'price_per_item' => $amount,
                'raw_date' => $createdAt,
                'download_url' => $downloadUrl,
                'status' => $txStatus,
                'tx_status' => $txStatus,
                'order_id' => 0,
                'external_ref' => '',
                'supplier_order_id' => '',
                'activity_event_id' => $activityEventId,
                'activity_conflict' => $activityConflict ? 1 : 0,
                'description' => $description,
                'wallet_rows' => [],
            ];
        }

        if ($keyCode !== '') {
            $groupedItems[$groupKey]['keys'][] = $keyCode;
            if (trim((string) ($transaction['key_code'] ?? '')) === '') {
                $groupedItems[$groupKey]['key_recovery_source'] = 'transaction_description';
            }
        }

        $currentTxId = (int) ($transaction['id'] ?? 0);
        $groupedItems[$groupKey]['transaction_ids'][] = $currentTxId;
        if (!empty($walletRowsByTransactionId[$currentTxId])) {
            foreach (adminTxNonRefundWalletRows($walletRowsByTransactionId[$currentTxId]) as $walletRow) {
                $groupedItems[$groupKey]['wallet_rows'][] = $walletRow;
            }
        }
        $groupedItems[$groupKey]['total_price'] = round(
            (float) $groupedItems[$groupKey]['total_price'] + $amount,
            2
        );
        $groupedItems[$groupKey]['expected_quantity']++;

        continue;
    }

    $groupedItems['tx_' . (int) ($transaction['id'] ?? 0)] = [
        'type' => 'single_tx',
        'username' => (string) ($transaction['username'] ?? ''),
        'role' => (string) ($transaction['role'] ?? ''),
        'user_id' => (int) ($transaction['user_id'] ?? 0),
        'transaction_id' => (int) ($transaction['id'] ?? 0),
        'tx_type' => $txType,
        'stored_tx_type' => transactionIntegrityNormalizeType($transaction['type'] ?? ''),
        'type_inferred' => $txType !== transactionIntegrityNormalizeType($transaction['type'] ?? ''),
        'status' => $txStatus,
        'amount' => $amount,
        'description' => (string) ($transaction['description'] ?? ''),
        'display_date' => date('Y-m-d H:i:s', strtotime((string) $transaction['created_at'])),
        'raw_date' => (string) $transaction['created_at'],
        'wallet_rows' => adminTxNonRefundWalletRows($walletRowsByTransactionId[(int) ($transaction['id'] ?? 0)] ?? []),
        'slip_evidence' => $slipEvidenceByTransactionId[(int) ($transaction['id'] ?? 0)] ?? null,
    ];
}

// Refunds are explicit money movements. This fixes the historical ambiguity
// where an API purchase was merely marked failed while the balance silently
// increased again. Legacy refunds are shown as evidence without fabricated
// before/after balances.
$showRefundCards = in_array($filterType, ['all', 'refund'], true)
    && in_array($filterStatus, ['all', 'completed', 'refunded'], true)
    && in_array($filterSource, ['all', 'cgo', 'supplier'], true);
if ($showRefundCards && function_exists('walletLedgerGetRefundRows')) {
    $refundRows = walletLedgerGetRefundRows($filterUserId, 300, $searchStr);
    if (function_exists('walletLedgerGetLegacyRefundRowsForAdmin')) {
        $refundRows = array_merge($refundRows, walletLedgerGetLegacyRefundRowsForAdmin($filterUserId, 300, $searchStr));
    }
    foreach ($refundRows as $refundRow) {
        $sourceType = (string) ($refundRow['source_type'] ?? '');
        $source = strpos($sourceType, 'supplier_') === 0 ? 'supplier' : 'cgo';
        if ($filterSource !== 'all' && $filterSource !== $source) continue;
        $groupedItems['refund_' . (string) ($refundRow['event_key'] ?? uniqid('', true))] = [
            'type' => 'refund',
            'source' => $source,
            'username' => (string) ($refundRow['username'] ?? ''),
            'user_id' => (int) ($refundRow['user_id'] ?? 0),
            'amount' => abs((float) ($refundRow['delta_amount'] ?? $refundRow['amount'] ?? 0)),
            'balance_before' => $refundRow['balance_before'] === null ? null : (float) $refundRow['balance_before'],
            'balance_after' => $refundRow['balance_after'] === null ? null : (float) $refundRow['balance_after'],
            'order_id' => (int) ($refundRow['source_id'] ?? 0),
            'transaction_id' => (int) ($refundRow['transaction_id'] ?? 0),
            'reference_code' => (string) ($refundRow['reference_code'] ?? ''),
            'public_note' => (string) ($refundRow['public_note'] ?? ''),
            'admin_note' => (string) ($refundRow['admin_note'] ?? ''),
            'legacy' => !empty($refundRow['_legacy_evidence']),
            'display_date' => adminTxFormatAuditTime($refundRow['created_at'] ?? ''),
            'raw_date' => (string) ($refundRow['created_at'] ?? ''),
        ];
    }
}

$groupedItems = array_values($groupedItems);

usort($groupedItems, static function (array $a, array $b): int {
    $timeCompare = strtotime((string) ($b['raw_date'] ?? ''))
        <=> strtotime((string) ($a['raw_date'] ?? ''));

    if ($timeCompare !== 0) return $timeCompare;

    $aIds = array_map('intval', $a['transaction_ids'] ?? [$a['transaction_id'] ?? 0]);
    $bIds = array_map('intval', $b['transaction_ids'] ?? [$b['transaction_id'] ?? 0]);

    $aId = $aIds ? max($aIds) : 0;
    $bId = $bIds ? max($bIds) : 0;

    return $bId <=> $aId;
});

// Page the already-grouped cards, never the raw transaction rows. One local
// checkout/API order/refund therefore remains atomic and cannot be split across
// two pages. The underlying 500/5000-record evidence window is intentionally
// unchanged in Phase A so filtering semantics and audit hydration stay stable.
$transactionLoadedGroupCount = count($groupedItems);
$transactionLoadedPages = max(1, (int) ceil($transactionLoadedGroupCount / $transactionGroupsPerPage));
if ($transactionPage > $transactionLoadedPages && $transactionLoadedGroupCount > 0) {
    $transactionPage = $transactionLoadedPages;
}
$transactionGroupOffset = ($transactionPage - 1) * $transactionGroupsPerPage;
$transactionPageHasPrevious = $transactionPage > 1;
$transactionPageHasNext = ($transactionGroupOffset + $transactionGroupsPerPage) < $transactionLoadedGroupCount;
$groupedItems = array_slice($groupedItems, $transactionGroupOffset, $transactionGroupsPerPage);
$adminTxTotalMs = (int) round((microtime(true) - $adminTxPerfStarted) * 1000);
if ($adminTxTotalMs >= 750) {
    error_log('Admin transactions slow request; total_ms=' . $adminTxTotalMs
        . '; core_query_ms=' . $adminTxCoreQueryMs
        . '; raw_scanned=' . $rawScannedCount
        . '; loaded_groups=' . $transactionLoadedGroupCount
        . '; search=' . ($searchStr !== '' ? '1' : '0')
        . '; type=' . $filterType
        . '; source=' . $filterSource
        . '; status=' . $filterStatus);
}
$transactionPageUrl = static function (int $page) use ($filterUserId, $filterType, $filterSource, $filterStatus, $searchStr): string {
    $query = array_filter([
        'user_id' => $filterUserId > 0 ? $filterUserId : null,
        'type' => $filterType !== 'all' ? $filterType : null,
        'source' => $filterSource !== 'all' ? $filterSource : null,
        'status' => $filterStatus !== 'all' ? $filterStatus : null,
        'search' => $searchStr !== '' ? $searchStr : null,
        'page' => max(1, $page),
    ], static fn($value): bool => $value !== null && $value !== '');
    return 'transactions.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};

$statusClasses = [
    'completed' => 'bg-green-500/15 text-green-300 border-green-500/30',
    'success' => 'bg-green-500/15 text-green-300 border-green-500/30',
    'pending' => 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30',
    'processing' => 'bg-blue-500/15 text-blue-300 border-blue-500/30',
    'submitting' => 'bg-blue-500/15 text-blue-300 border-blue-500/30',
    'manual_review' => 'bg-orange-500/15 text-orange-300 border-orange-500/30',
    'unknown' => 'bg-orange-500/15 text-orange-300 border-orange-500/30',
    'failed' => 'bg-red-500/15 text-red-300 border-red-500/30',
    'refunded' => 'bg-purple-500/15 text-purple-300 border-purple-500/30',
    'refunded_conflict' => 'bg-red-500/15 text-red-300 border-red-500/30',
    'cancelled' => 'bg-gray-500/15 text-gray-300 border-gray-500/30',
    'canceled' => 'bg-gray-500/15 text-gray-300 border-gray-500/30',
];
$transactionTypeSchemaReady = function_exists('transactionIntegrityTypeColumnReady')
    ? transactionIntegrityTypeColumnReady()
    : true;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($t('ธุรกรรมทั้งหมด', 'All Transactions'), ENT_QUOTES, 'UTF-8'); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .hidden-keys { display: none; }
        .show-keys .hidden-keys { display: flex; }
        input:focus, select:focus { outline: none; }

        /* Keep the audit modal outside utility-class/z-index surprises on mobile.
           A dedicated backdrop layer prevents Android Chromium from compositing
           the dialog behind its own backdrop-filter. */
        body.audit-modal-open { overflow: hidden !important; overscroll-behavior: none; }
        #detailModalOverlay {
            position: fixed;
            inset: 0;
            z-index: 2147483000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: max(12px, env(safe-area-inset-top, 0px))
                     max(12px, env(safe-area-inset-right, 0px))
                     max(12px, env(safe-area-inset-bottom, 0px))
                     max(12px, env(safe-area-inset-left, 0px));
            isolation: isolate;
            overflow-y: auto;
        }
        #detailModalOverlay.is-open { display: flex; }
        #detailModalBackdrop {
            position: fixed;
            inset: 0;
            z-index: 0;
            border: 0;
            margin: 0;
            padding: 0;
            background: rgba(0, 0, 0, .78);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
        }
        #detailModalPanel {
            position: relative;
            z-index: 1;
            width: min(100%, 960px);
            max-height: min(92dvh, 920px);
            overflow: auto;
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 16px;
            background: #1b2336;
            box-shadow: 0 28px 90px rgba(0,0,0,.62);
            color: #f3f4f6;
            -webkit-overflow-scrolling: touch;
        }
        #detailModalPanel:focus { outline: 2px solid rgba(34,211,238,.45); outline-offset: 2px; }
        .audit-json-box {
            min-height: 140px;
            max-height: 46dvh;
            overflow: auto;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .slip-evidence-toggle {
            list-style: none;
            -webkit-tap-highlight-color: transparent;
        }
        .slip-evidence-toggle::-webkit-details-marker { display: none; }
        .slip-evidence-toggle .slip-label-hide { display: none; }
        details[open] > .slip-evidence-toggle .slip-label-show { display: none; }
        details[open] > .slip-evidence-toggle .slip-label-hide { display: inline; }
        details[open] > .slip-evidence-toggle .slip-chevron { transform: rotate(180deg); }
        @media (max-width: 640px) {
            #detailModalOverlay { align-items: flex-start; padding-top: max(10px, env(safe-area-inset-top, 0px)); }
            #detailModalPanel { max-height: calc(100dvh - 20px - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px)); border-radius: 14px; }
        }
        @media (prefers-reduced-motion: no-preference) {
            #detailModalPanel { animation: auditModalIn .14s ease-out; }
            @keyframes auditModalIn {
                from { opacity: .7; transform: translateY(8px) scale(.985); }
                to { opacity: 1; transform: none; }
            }
        }
    </style>
</head>
<body class="bg-[#0b101e] text-gray-100 min-h-screen pb-10">
<?php include 'nav.php'; ?>
<main class="max-w-6xl mx-auto p-4 space-y-5 pt-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="bg-[#6366f1]/20 text-[#818cf8] p-2.5 rounded-lg"><i class="bi bi-receipt-cutoff text-xl"></i></div>
            <div>
                <h1 class="text-white font-bold text-2xl"><?php echo htmlspecialchars($t('ประวัติธุรกรรมและคีย์', 'Transactions and License History'), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($t('ตรวจสอบการซื้อภายในร้านและคำสั่งซื้อผ่าน API ในหน้าเดียว', 'Audit local purchases and API orders in one place.'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="commerce_ledger.php" class="inline-flex items-center gap-2 rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-xs font-bold text-sky-200 hover:bg-sky-500/20"><i class="bi bi-journal-text"></i><?php echo htmlspecialchars($t('เปิดบัญชีธุรกรรมกลาง', 'Open Central Ledger'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php if (!$cgoReady || !$storeBridgeReady): ?>
            <div class="rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-3 py-2 text-xs text-yellow-300">
                <i class="bi bi-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($t('ตารางประวัติ API บางส่วนไม่พร้อมใช้งาน รายการจากผู้ให้บริการที่ขาดตารางอาจแสดงข้อมูลไม่ครบ', 'Some API history tables are unavailable, so records from the affected provider may be incomplete.'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <?php if (!$transactionTypeSchemaReady): ?>
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><i class="bi bi-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($t('ฐานข้อมูลยังใช้ชนิด Transaction รุ่นเก่า หน้านี้กู้ชื่อรายการจากหลักฐานให้ชั่วคราว แต่ควร Backup และซ่อม Schema', 'The database still uses an old transaction type schema. This page recovers labels from evidence temporarily, but the schema should be backed up and repaired.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><a href="transaction_integrity.php" class="shrink-0 rounded-lg bg-amber-400 px-3 py-2 text-xs font-bold text-black hover:bg-amber-300"><i class="bi bi-wrench-adjustable-circle mr-1"></i><?php echo htmlspecialchars($t('เปิดหน้าซ่อม', 'Open repair'), ENT_QUOTES, 'UTF-8'); ?></a></div>
    <?php endif; ?>

    <?php if ($actionMessage !== ''): ?>
        <div class="rounded-xl border border-green-500/30 bg-green-500/10 p-4 text-sm text-green-200"><i class="bi bi-check-circle mr-1"></i><?php echo htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($actionError !== ''): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-200"><i class="bi bi-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($actionError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div id="transactionFilterPanel" data-instant-panel data-instant-targets="#transactionFilterPanel,#transactionResultsPanel" class="space-y-4">
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-[#161c2d] border border-white/5 rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo htmlspecialchars($t('รายการที่โหลด', 'Loaded records'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-2xl font-bold mt-1"><?php echo number_format($stats['records']); ?><?php echo $resultTruncated ? '+' : ''; ?></div></div>
        <div class="bg-[#161c2d] border border-white/5 rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo htmlspecialchars($t('ยอดซื้อสำเร็จ', 'Completed purchases'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xl font-bold text-green-400 mt-1"><?php echo formatCurrency($stats['purchase_total']); ?></div></div>
        <div class="bg-[#161c2d] border border-white/5 rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo htmlspecialchars($t('คำสั่งซื้อ API', 'API orders'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-2xl font-bold text-cyan-300 mt-1"><?php echo number_format($stats['api_orders']); ?></div></div>
        <div class="bg-[#161c2d] border border-white/5 rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo htmlspecialchars($t('ต้องตรวจสอบ', 'Needs attention'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-2xl font-bold <?php echo $stats['attention'] > 0 ? 'text-orange-300' : 'text-gray-300'; ?> mt-1"><?php echo number_format($stats['attention']); ?></div></div>
    </section>

    <section class="bg-[#161c2d] border border-white/5 rounded-xl p-4 shadow-md">
        <form method="GET" action="transactions.php" id="transactionFilterForm" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
            <div>
                <label class="text-gray-400 text-xs mb-1 block"><?php echo htmlspecialchars($t('บัญชี', 'Account'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select name="user_id" class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 px-3 text-sm text-white">
                    <option value=""><?php echo htmlspecialchars($t('ทุกบัญชี', 'All accounts'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int) $user['id']; ?>" <?php echo $filterUserId === (int) $user['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $user['username'] . ' [' . (string) $user['role'] . ']', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block"><?php echo htmlspecialchars($t('ประเภทรายการ', 'Transaction type'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select name="type" id="transactionTypeFilter" class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 px-3 text-sm text-white">
                    <?php foreach ([
                        'all' => $t('ทั้งหมด', 'All'),
                        'purchase' => $t('การซื้อสินค้า', 'Purchases'),
                        'deposit' => $t('เติมเงินและเติมโค้ด', 'Deposits and top-up codes'),
                        'refund' => $t('เงินคืน', 'Refunds'),
                        'adjustment' => $t('ปรับยอดโดยแอดมิน', 'Admin adjustments'),
                        'bonus' => $t('โบนัสแรงค์', 'Rank bonuses'),
                        'other' => $t('อื่น ๆ', 'Other'),
                    ] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $filterType === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block"><?php echo htmlspecialchars($t('แหล่งคีย์', 'Key source'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select name="source" id="transactionSourceFilter" class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 px-3 text-sm text-white disabled:opacity-50 disabled:cursor-not-allowed">
                    <option value="all" <?php echo $filterSource === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ทุกแหล่ง', 'All sources'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="local" <?php echo $filterSource === 'local' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('คีย์ในเว็บไซต์', 'Local inventory'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="api" <?php echo $filterSource === 'api' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ซื้อผ่าน API ทั้งหมด', 'All API purchases'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="reseller_api" <?php echo $filterSource === 'reseller_api' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('Store API ตัวแทน', 'Reseller Store API'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="supplier" <?php echo $filterSource === 'supplier' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('Store Bridge API', 'Store Bridge API'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="cgo" <?php echo $filterSource === 'cgo' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('CGO API', 'CGO API'), ENT_QUOTES, 'UTF-8'); ?></option>
                </select>
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block"><?php echo htmlspecialchars($t('สถานะ', 'Status'), ENT_QUOTES, 'UTF-8'); ?></label>
                <select name="status" class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 px-3 text-sm text-white">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ทุกสถานะ', 'All statuses'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('สำเร็จ', 'Completed'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('รอดำเนินการ', 'Pending'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="review" <?php echo $filterStatus === 'review' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ต้องตรวจสอบ', 'Needs review'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ล้มเหลว', 'Failed'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="refunded" <?php echo $filterStatus === 'refunded' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('คืนเงินแล้ว', 'Refunded'), ENT_QUOTES, 'UTF-8'); ?></option>
                    <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>><?php echo htmlspecialchars($t('ยกเลิก', 'Cancelled'), ENT_QUOTES, 'UTF-8'); ?></option>
                </select>
            </div>
            <div>
                <label class="text-gray-400 text-xs mb-1 block"><?php echo htmlspecialchars($t('ค้นหา', 'Search'), ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="flex gap-2">
                    <div class="relative flex-1"><i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i><input type="search" name="search" value="<?php echo htmlspecialchars($searchStr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 pl-9 pr-3 text-sm" placeholder="<?php echo htmlspecialchars($t('คีย์ สินค้า ผู้ใช้ ตัวแทน/เว็บไซต์ ผู้โอน สลิป TX หรือ Order', 'Key, product, user, slip sender/ref, TX, or order'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                    <button type="submit" class="bg-[#6366f1] hover:bg-indigo-500 rounded-lg px-4 text-sm font-medium"><i class="bi bi-funnel"></i></button>
                </div>
            </div>
        </form>
        <?php if ($filterUserId || $searchStr !== '' || $filterType !== 'all' || $filterSource !== 'all' || $filterStatus !== 'all'): ?>
            <div class="mt-3 text-right"><a href="transactions.php" class="text-xs text-gray-400 hover:text-white"><i class="bi bi-x-circle mr-1"></i><?php echo htmlspecialchars($t('ล้างตัวกรอง', 'Clear filters'), ENT_QUOTES, 'UTF-8'); ?></a></div>
        <?php endif; ?>
        <?php if ($filterNotice !== ''): ?>
            <div class="mt-3 rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-3 py-2 text-xs text-yellow-200"><i class="bi bi-info-circle mr-1"></i><?php echo htmlspecialchars($filterNotice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($resultTruncated): ?>
            <div class="mt-3 rounded-lg border border-blue-500/30 bg-blue-500/10 px-3 py-2 text-xs text-blue-200"><i class="bi bi-info-circle mr-1"></i><?php echo htmlspecialchars($t('แสดงเฉพาะ 500 รายการล่าสุดตามตัวกรอง กรุณาค้นหาให้เจาะจงขึ้นเมื่อต้องการข้อมูลเก่ากว่านี้', 'Showing the latest 500 matching records. Use a more specific search for older history.'), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </section>

    <?php if ($filterUserId > 0): ?>
        <?php $walletAuditStatus = (string) ($selectedWalletSummary['status'] ?? 'unavailable'); ?>
        <section class="rounded-xl border <?php echo $walletAuditStatus === 'ok' ? 'border-emerald-500/25 bg-emerald-500/[0.06]' : 'border-amber-500/30 bg-amber-500/[0.07]'; ?> p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-bold text-white"><i class="bi bi-activity mr-1"></i><?php echo htmlspecialchars($t('ตรวจสมการยอดเงินบัญชีนี้', 'Balance audit for this account'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="mt-1 text-xs <?php echo $walletAuditStatus === 'ok' ? 'text-emerald-300' : 'text-amber-300'; ?>">
                        <?php if (empty($selectedWalletSummary['available'])): ?>
                            <?php echo htmlspecialchars($t('ยังไม่สามารถเปิดหลักฐานยอดเงินแบบละเอียดได้', 'Detailed balance evidence is not available yet.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php elseif ($walletAuditStatus === 'ok'): ?>
                            <?php echo htmlspecialchars($t('ยอดปัจจุบันตรงกับปลายทางของ Ledger ใหม่', 'Current balance matches the end of the detailed ledger.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php elseif ($walletAuditStatus === 'chain_gap'): ?>
                            <?php echo htmlspecialchars($t('พบช่วงขาดในลำดับยอดเงินใหม่ ต้องตรวจสอบก่อนสรุปยอด', 'A gap exists in the detailed balance chain and requires review.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($t('พบส่วนต่างระหว่างยอดจริงกับ Ledger ใหม่', 'A difference exists between the live balance and the detailed ledger.'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($selectedWalletSummary['available'])): ?>
                    <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                        <div class="rounded-lg bg-black/20 p-2"><div class="text-gray-500"><?php echo $t('ยอดจริง', 'Live'); ?></div><div class="font-bold text-white"><?php echo formatCurrency((float) $selectedWalletSummary['current_balance']); ?></div></div>
                        <div class="rounded-lg bg-black/20 p-2"><div class="text-gray-500"><?php echo $t('ปลาย Ledger', 'Ledger end'); ?></div><div class="font-bold text-white"><?php echo formatCurrency((float) $selectedWalletSummary['last_recorded_balance']); ?></div></div>
                        <div class="rounded-lg bg-black/20 p-2"><div class="text-gray-500"><?php echo $t('ส่วนต่าง', 'Difference'); ?></div><div class="font-bold <?php echo abs((float) $selectedWalletSummary['discrepancy']) > 0.009 ? 'text-amber-300' : 'text-emerald-300'; ?>"><?php echo formatCurrency((float) $selectedWalletSummary['discrepancy']); ?></div></div>
                        <div class="rounded-lg bg-black/20 p-2"><div class="text-gray-500"><?php echo $t('ช่วงขาด', 'Chain gaps'); ?></div><div class="font-bold text-white"><?php echo (int) $selectedWalletSummary['chain_gap_count']; ?></div></div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-2 text-[10px] text-gray-500"><?php echo htmlspecialchars($t('ข้อมูลก่อนเริ่ม Ledger ใหม่จะถูกระบุเป็น LEGACY และจะไม่สร้างยอดก่อน/หลังย้อนหลังขึ้นมาเอง', 'Records before detailed ledger coverage are marked LEGACY; historical before/after balances are never invented.'), ENT_QUOTES, 'UTF-8'); ?></div>
        </section>
    <?php endif; ?>
    </div>

    <section id="transactionResultsPanel" data-instant-panel data-instant-targets="#transactionFilterPanel,#transactionResultsPanel" class="space-y-3">
        <?php if ($queryError !== ''): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-5 text-red-200">
                <div class="font-bold"><i class="bi bi-database-exclamation mr-1"></i><?php echo htmlspecialchars($t('โหลดประวัติไม่สำเร็จ', 'Transaction history could not be loaded'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1 text-xs text-red-200/80"><?php echo htmlspecialchars($queryError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php if ($queryTechnicalError !== ''): ?>
                    <details class="mt-3 rounded-lg border border-red-400/20 bg-black/20 p-3 text-xs">
                        <summary class="cursor-pointer font-medium text-red-100"><?php echo htmlspecialchars($t('รายละเอียดทางเทคนิคสำหรับแอดมิน', 'Technical details for the administrator'), ENT_QUOTES, 'UTF-8'); ?></summary>
                        <code class="mt-2 block whitespace-pre-wrap break-all text-red-100/80"><?php echo htmlspecialchars($queryTechnicalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                    </details>
                <?php endif; ?>
            </div>
        <?php elseif (!$groupedItems): ?>
            <div class="text-center py-14 text-gray-500 bg-[#161c2d] border border-white/5 rounded-xl"><i class="bi bi-inbox text-4xl mb-3 block"></i><?php echo htmlspecialchars($t('ไม่พบรายการตามตัวกรอง', 'No transactions matched the filters.'), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php foreach ($groupedItems as $item): ?>
            <?php if ($item['type'] === 'refund'): ?>
                <?php
                $refundSource = (string) ($item['source'] ?? 'cgo');
                $refundSourceLabel = $refundSource === 'supplier' ? 'Store Bridge / Supplier' : 'CGO';
                ?>
                <article class="rounded-xl border border-emerald-500/20 bg-[#161c2d] p-4 shadow-sm">
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-emerald-300"><i class="bi bi-arrow-counterclockwise mr-1"></i><?php echo htmlspecialchars($t('คืนเงิน', 'Refund'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="rounded-full border border-cyan-500/25 bg-cyan-500/10 px-2 py-1 text-[10px] text-cyan-200"><?php echo htmlspecialchars($refundSourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($item['legacy'])): ?><span class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-1 text-[10px] font-bold text-amber-300">LEGACY</span><?php endif; ?>
                            </div>
                            <div class="mt-2 text-xs text-gray-300"><i class="bi bi-person mr-1"></i><?php echo htmlspecialchars((string) ($item['username'] ?: ('User #' . (int) $item['user_id'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div class="mt-1 text-xs text-gray-400"><?php echo htmlspecialchars((string) $item['public_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-500">
                                <span><i class="bi bi-calendar3 mr-1"></i><?php echo htmlspecialchars((string) $item['display_date'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ((int) $item['order_id'] > 0): ?><span>Order #<?php echo (int) $item['order_id']; ?></span><?php endif; ?>
                                <?php if ((int) $item['transaction_id'] > 0): ?><span>TX #<?php echo (int) $item['transaction_id']; ?></span><?php endif; ?>
                                <?php if ((string) $item['reference_code'] !== ''): ?><span class="break-all"><?php echo htmlspecialchars((string) $item['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span><?php endif; ?>
                            </div>
                            <?php if ($item['balance_before'] !== null && $item['balance_after'] !== null): ?>
                                <div class="mt-2 text-xs text-gray-400"><?php echo $t('ยอดก่อน/หลัง', 'Balance before/after'); ?>: <span class="text-white"><?php echo formatCurrency((float) $item['balance_before']); ?></span> → <span class="text-emerald-300"><?php echo formatCurrency((float) $item['balance_after']); ?></span></div>
                            <?php else: ?>
                                <div class="mt-2 text-[11px] text-amber-300"><?php echo htmlspecialchars($t('ข้อมูลย้อนหลังนี้พิสูจน์การคืนเงินได้ แต่ระบบเก่ายังไม่ได้เก็บยอดก่อน/หลัง', 'This historical record proves the refund, but the old system did not store before/after balances.'), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if ((string) $item['admin_note'] !== ''): ?><div class="mt-2 text-[10px] text-gray-600"><?php echo htmlspecialchars((string) $item['admin_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                        </div>
                        <div class="whitespace-nowrap text-xl font-bold text-emerald-400">+<?php echo formatCurrency((float) $item['amount']); ?></div>
                    </div>
                </article>
            <?php elseif ($item['type'] === 'purchase_group'): ?>
                <?php
                $keys = array_values(array_filter(array_map('strval', $item['keys']), static fn(string $key): bool => trim($key) !== ''));
                $deliveredCount = count($keys);
                $expectedCount = max((int) ($item['expected_quantity'] ?? 0), $deliveredCount);
                $status = (string) ($item['status'] ?? '');
                $statusClass = $statusClasses[$status] ?? 'bg-gray-500/15 text-gray-300 border-gray-500/30';
                $itemSource = (string) ($item['source'] ?? '');
                $sourceIsApi = in_array($itemSource, ['cgo', 'supplier', 'reseller_api'], true);
                $sourceIsStoreBridge = $itemSource === 'supplier';
                $sourceIsResellerApi = $itemSource === 'reseller_api';
                if ($sourceIsResellerApi) {
                    $clientLabel = trim((string) ($item['client_name'] ?? ''));
                    $websiteLabel = trim((string) ($item['website_name'] ?? ''));
                    $sourceLabel = $t('Store API ตัวแทน', 'Reseller Store API');
                    if ($clientLabel !== '') $sourceLabel .= ': ' . $clientLabel;
                    if ($websiteLabel !== '' && strcasecmp($websiteLabel, $clientLabel) !== 0) $sourceLabel .= ' · ' . $websiteLabel;
                    $sourceClass = 'bg-violet-500/15 text-violet-300 border-violet-500/30';
                } elseif ($sourceIsStoreBridge) {
                    $providerName = trim((string) ($item['connection_name'] ?? ''));
                    $sourceLabel = $providerName !== ''
                        ? $t('Store Bridge: ', 'Store Bridge: ') . $providerName
                        : $t('ซื้อผ่าน Store Bridge API', 'Store Bridge API');
                    $sourceClass = 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
                } elseif ($sourceIsApi) {
                    $sourceLabel = $t('ซื้อผ่าน CGO API', 'CGO API supplier');
                    $sourceClass = 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30';
                } else {
                    $sourceLabel = $t('คีย์ในเว็บไซต์', 'Local inventory');
                    $sourceClass = 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30';
                }
                $missingKeyMessage = $t('ไม่พบคีย์ที่เชื่อมกับธุรกรรมนี้', 'No local key is linked to this transaction.');
                if ($sourceIsApi) {
                    $historyState = (string) ($item['key_history_state'] ?? '');
                    if ($historyState === 'order_record_missing') {
                        $missingKeyMessage = $t('พบธุรกรรมซื้อผ่าน API แต่ไม่พบแถวคำสั่งซื้อเดิม จึงไม่สามารถดึงคีย์ย้อนหลังจากฐานข้อมูลได้', 'The API transaction exists, but its original order row is missing, so the historical key cannot be recovered from this database.');
                    } elseif ($historyState === 'no_historical_key_data') {
                        $missingKeyMessage = $t('พบคำสั่งซื้อเดิม แต่ฐานข้อมูลไม่ได้เก็บคีย์หรือคำตอบ API ที่มีคีย์ไว้ จึงไม่สามารถกู้คีย์ย้อนหลังได้', 'The original order exists, but neither a delivered key nor a saved API response containing it is available, so the historical key cannot be recovered.');
                    } elseif ($historyState === 'no_recognized_key_in_response') {
                        $missingKeyMessage = $t('พบคำตอบ API เดิม แต่ไม่พบข้อมูลคีย์ในรูปแบบที่ระบบบันทึกไว้ กรุณาตรวจเลขอ้างอิงกับผู้ให้บริการ', 'A saved API response exists, but it contains no recognized key data. Check the supplier reference.');
                    } elseif ($historyState === 'api_table_unavailable' || $historyState === 'key_storage_unavailable') {
                        $missingKeyMessage = $t('ตารางประวัติคีย์ API ไม่พร้อมใช้งาน จึงยังตรวจคีย์ย้อนหลังจากหน้านี้ไม่ได้', 'The API key-history tables are unavailable, so historical keys cannot currently be checked on this page.');
                    } else {
                        $missingKeyMessage = $t('คำสั่งซื้อนี้ยังไม่มีคีย์ที่บันทึกในระบบ กรุณาตรวจสถานะและเลขอ้างอิงก่อนเคลม', 'No delivered key is stored for this API order. Check its status and references before handling a claim.');
                    }
                }
                $transactionRef = implode(', ', array_map(static fn($id): string => '#' . (int) $id, $item['transaction_ids'] ?? []));
                $metaLines = [
                    $t('แหล่งคีย์', 'Source') . ': ' . $sourceLabel,
                    $t('สถานะ', 'Status') . ': ' . adminTxStatusLabel($status, $isTh),
                    $t('Transaction', 'Transaction') . ': ' . ($transactionRef !== '' ? $transactionRef : '-'),
                ];
                if ($sourceIsApi) {
                    $metaLines[] = $t('เลขคำสั่งซื้อภายใน', 'Internal order') . ': #' . (int) ($item['order_id'] ?? 0);
                    $metaLines[] = $t('รหัสอ้างอิงร้าน', 'Store reference') . ': ' . ((string) ($item['external_ref'] ?? '') !== '' ? (string) $item['external_ref'] : '-');
                    if ($sourceIsResellerApi) {
                        $metaLines[] = $t('API Client', 'API client') . ': #' . (int) ($item['client_id'] ?? 0) . ' ' . ((string) ($item['client_name'] ?? '') !== '' ? (string) $item['client_name'] : '-');
                        if ((string) ($item['website_name'] ?? '') !== '') $metaLines[] = $t('เว็บไซต์ตัวแทน', 'Reseller website') . ': ' . (string) $item['website_name'];
                        if ((string) ($item['billing_mode'] ?? '') !== '') $metaLines[] = $t('โหมดหักเงิน', 'Billing mode') . ': ' . (string) $item['billing_mode'];
                        if ((string) ($item['origin_site_id'] ?? '') !== '') $metaLines[] = 'origin_site_id: ' . (string) $item['origin_site_id'];
                        if ((string) ($item['origin_user_id'] ?? '') !== '') $metaLines[] = 'origin_user_id: ' . (string) $item['origin_user_id'];
                        if ((string) ($item['customer_ref'] ?? '') !== '') $metaLines[] = 'customer_ref: ' . (string) $item['customer_ref'];
                    } else {
                        $metaLines[] = $t('เลขคำสั่งซื้อผู้ให้บริการ', 'Supplier order') . ': ' . ((string) ($item['supplier_order_id'] ?? '') !== '' ? (string) $item['supplier_order_id'] : '-');
                    }
                    $metaLines[] = $t('จำนวนสั่ง/ส่งมอบ', 'Ordered/delivered') . ': ' . $expectedCount . '/' . $deliveredCount;
                    $keyRecoverySource = (string) ($item['key_recovery_source'] ?? '');
                    if (in_array($keyRecoverySource, ['saved_response', 'stored_and_response', 'normalized_saved_response'], true)) {
                        $metaLines[] = $t('แหล่งข้อมูลคีย์', 'Key evidence') . ': ' . $t('กู้คืนจากคำตอบ API ที่บันทึกไว้', 'Recovered from the saved API response');
                    }
                } elseif ((string) ($item['key_recovery_source'] ?? '') === 'transaction_description') {
                    $metaLines[] = $t('แหล่งข้อมูลคีย์', 'Key evidence') . ': ' . $t('กู้คืนจากรายละเอียดธุรกรรมเดิม', 'Recovered from the original transaction description');
                }
                if (!$sourceIsApi && (int) ($item['activity_event_id'] ?? 0) > 0) {
                    $metaLines[] = $t('ขอบเขตคำสั่งซื้อ', 'Checkout boundary') . ': Activity #' . (int) $item['activity_event_id'];
                }
                if (!empty($item['activity_conflict'])) {
                    $metaLines[] = $t('คำเตือนความถูกต้อง', 'Integrity warning') . ': ' . $t('ช่วง Transaction ของ Purchase Activity ซ้อนกัน รายการนี้จึงไม่ถูกรวมกับรายการอื่น', 'Purchase Activity transaction ranges overlap, so this transaction was kept isolated');
                }
                if (!empty($item['api_link_conflict'])) {
                    $metaLines[] = $t('คำเตือนความถูกต้อง', 'Integrity warning') . ': ' . $t('พบคำสั่งซื้อ API มากกว่าหนึ่งรายการเชื่อมกับ Transaction เดียวกัน', 'Multiple API orders are linked to the same transaction');
                }
                foreach ((array) ($item['wallet_rows'] ?? []) as $walletRow) {
                    if ($walletRow['balance_before'] === null || $walletRow['balance_after'] === null) continue;
                    $metaLines[] = $t('ยอดก่อน/หลัง', 'Balance before/after') . ': '
                        . formatCurrency((float) $walletRow['balance_before']) . ' → ' . formatCurrency((float) $walletRow['balance_after']);
                }
                $purchaseBalance = adminTxPurchaseBalanceSummary(
                    (array) ($item['wallet_rows'] ?? []),
                    isset($item['balance_before']) && is_numeric($item['balance_before']) ? (float) $item['balance_before'] : null,
                    isset($item['balance_after']) && is_numeric($item['balance_after']) ? (float) $item['balance_after'] : null,
                    (float) ($item['total_price'] ?? 0)
                );
                if (!empty($purchaseBalance['available'])) {
                    $metaLines[] = $t('หักเงิน/คงเหลือ', 'Debited/remaining') . ': '
                        . formatCurrency((float) $purchaseBalance['deducted']) . ' / ' . formatCurrency((float) $purchaseBalance['balance_after']);
                }
                $metaText = implode("\n", $metaLines);
                $auditSource = $sourceIsResellerApi ? 'reseller_api' : ($sourceIsStoreBridge ? 'supplier' : ($sourceIsApi ? 'cgo' : 'local'));
                $auditTransactionIds = array_values(array_filter(array_map('intval', (array) ($item['transaction_ids'] ?? []))));
                $auditUrl = 'transactions.php?' . http_build_query([
                    'audit_json' => 1,
                    'audit_source' => $auditSource,
                    'order_id' => $sourceIsApi ? (int) ($item['order_id'] ?? 0) : 0,
                    'tx_ids' => implode(',', $auditTransactionIds),
                ], '', '&', PHP_QUERY_RFC3986);
                $basicAudit = [
                    'success' => true,
                    'evidence_version' => 7,
                    'scope' => 'page_snapshot',
                    'generated_at' => date(DATE_ATOM),
                    'source' => $auditSource,
                    'product' => (string) ($item['product_name'] ?? ''),
                    'status' => $status,
                    'status_label' => adminTxStatusLabel($status, $isTh),
                    'display_date' => (string) ($item['display_date'] ?? ''),
                    'total_price' => (float) ($item['total_price'] ?? 0),
                    'transaction_ids' => $auditTransactionIds,
                    'internal_order_id' => $sourceIsApi ? (int) ($item['order_id'] ?? 0) : 0,
                    'external_ref' => $sourceIsApi ? (string) ($item['external_ref'] ?? '') : '',
                    'supplier_order_id' => $sourceIsApi && !$sourceIsResellerApi ? (string) ($item['supplier_order_id'] ?? '') : '',
                    'reseller_store_api' => $sourceIsResellerApi ? [
                        'client_id' => (int) ($item['client_id'] ?? 0),
                        'client_name' => (string) ($item['client_name'] ?? ''),
                        'website_name' => (string) ($item['website_name'] ?? ''),
                        'billing_mode' => (string) ($item['billing_mode'] ?? ''),
                        'origin_site_id' => (string) ($item['origin_site_id'] ?? ''),
                        'origin_user_id' => (string) ($item['origin_user_id'] ?? ''),
                        'customer_ref' => (string) ($item['customer_ref'] ?? ''),
                        'customer_name' => (string) ($item['customer_name'] ?? ''),
                        'customer_email' => (string) ($item['customer_email'] ?? ''),
                    ] : null,
                    'balance_evidence' => $purchaseBalance,
                    'expected_quantity' => $expectedCount,
                    'delivered_key_count' => $deliveredCount,
                    'delivered_keys' => $keys,
                    'key_history_state' => (string) ($item['key_history_state'] ?? ''),
                    'key_recovery_source' => (string) ($item['key_recovery_source'] ?? ''),
                    'integrity' => [
                        'api_link_conflict' => !empty($item['api_link_conflict']),
                        'activity_conflict' => !empty($item['activity_conflict']),
                        'activity_event_id' => (int) ($item['activity_event_id'] ?? 0),
                    ],
                    'audit_endpoint' => $auditUrl,
                    'note' => 'This page snapshot is shown immediately. The modal then requests the full read-only database evidence.',
                ];
                $basicAuditJson = json_encode(
                    $basicAudit,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
                if (!is_string($basicAuditJson) || $basicAuditJson === '') $basicAuditJson = '{}';
                ?>
                <article id="api-order-<?php echo htmlspecialchars($sourceIsResellerApi ? 'reseller_api' : ($sourceIsStoreBridge ? 'supplier' : ($sourceIsApi ? 'cgo' : 'local')), ENT_QUOTES, 'UTF-8'); ?>-<?php echo (int) ($item['order_id'] ?? 0); ?>" class="bg-[#161c2d] border border-white/5 rounded-xl p-4 shadow-sm">
                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="w-6 h-6 rounded-full bg-white/10 grid place-items-center text-xs font-bold"><?php echo htmlspecialchars(strtoupper(substr((string) $item['username'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="text-sm font-medium text-gray-200"><?php echo htmlspecialchars((string) $item['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <span class="rounded-full border px-2 py-1 text-[10px] font-bold <?php echo $sourceClass; ?>"><?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="rounded-full border px-2 py-1 text-[10px] font-bold <?php echo $statusClass; ?>"><?php echo htmlspecialchars(adminTxStatusLabel($status, $isTh), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (empty($item['wallet_rows']) && empty($purchaseBalance['available'])): ?><span class="rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-1 text-[10px] font-bold text-amber-300">LEGACY</span><?php endif; ?>
                                <?php if (!empty($item['api_link_conflict'])): ?><span class="rounded-full border border-red-500/30 bg-red-500/10 px-2 py-1 text-[10px] font-bold text-red-300"><i class="bi bi-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($t('ลิงก์คำสั่งซื้อขัดแย้ง', 'Order-link conflict'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if (!empty($item['activity_conflict'])): ?><span class="rounded-full border border-red-500/30 bg-red-500/10 px-2 py-1 text-[10px] font-bold text-red-300"><i class="bi bi-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($t('ช่วงคำสั่งซื้อขัดแย้ง', 'Checkout-range conflict'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            </div>
                            <h2 class="font-bold text-white text-base break-words"><?php echo htmlspecialchars((string) $item['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><?php if ($expectedCount > 1): ?><span class="ml-2 text-xs text-indigo-300">x<?php echo $expectedCount; ?></span><?php endif; ?></h2>
                            <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-x-3 gap-y-1">
                                <span><i class="bi bi-calendar3 mr-1"></i><?php echo htmlspecialchars((string) $item['display_date'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($transactionRef !== ''): ?><span><i class="bi bi-hash mr-1"></i>TX <?php echo htmlspecialchars($transactionRef, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if ($sourceIsApi): ?><span><i class="bi bi-receipt mr-1"></i>Order #<?php echo (int) ($item['order_id'] ?? 0); ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($purchaseBalance['available'])): ?>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 text-[11px] leading-4">
                                    <span class="text-rose-300/90"><?php echo htmlspecialchars($t('หัก', 'Debited'), ENT_QUOTES, 'UTF-8'); ?> <?php echo formatCurrency((float) $purchaseBalance['deducted']); ?></span>
                                    <span class="text-gray-700">•</span>
                                    <span class="text-cyan-300/90"><?php echo htmlspecialchars($t('คงเหลือ', 'Remaining'), ENT_QUOTES, 'UTF-8'); ?> <?php echo formatCurrency((float) $purchaseBalance['balance_after']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-lg bg-green-500/10 px-3 py-2 text-green-400 font-bold whitespace-nowrap"><?php echo formatCurrency((float) $item['total_price']); ?></div>
                    </div>

                    <?php if ($keys): ?>
                        <div class="key-container mt-4">
                            <?php foreach ($keys as $keyIndex => $keyCode): ?>
                                <?php
                                $accountItem = function_exists('cgoParseAccountDeliveryText')
                                    ? cgoParseAccountDeliveryText((string) $keyCode)
                                    : null;
                                $accountFields = is_array($accountItem)
                                    ? (array) ($accountItem['payload']['fields'] ?? [])
                                    : [];
                                ?>
                                <?php if ($accountFields): ?>
                                    <div class="<?php echo $keyIndex > 0 ? 'hidden-keys mt-3' : ''; ?> rounded-xl border border-cyan-400/20 bg-cyan-400/[0.045] p-3">
                                        <div class="mb-3 flex items-center justify-between gap-3">
                                            <div class="flex items-center gap-2 text-sm font-bold text-cyan-200">
                                                <i class="bi bi-person-badge"></i>
                                                <?php echo htmlspecialchars($t('ข้อมูลบัญชีเกม', 'Game account details'), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <button type="button" onclick="Lang.copy(<?php echo htmlJsArg((string) $keyCode); ?>)" class="rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-xs text-cyan-200 hover:bg-cyan-400/20">
                                                <i class="bi bi-copy mr-1"></i><?php echo htmlspecialchars($t('คัดลอกทั้งหมด', 'Copy all'), ENT_QUOTES, 'UTF-8'); ?>
                                            </button>
                                        </div>
                                        <div class="grid gap-2 md:grid-cols-2">
                                            <?php foreach ($accountFields as $field): ?>
                                                <?php
                                                $fieldLabel = trim((string) ($field['label'] ?? $field['name'] ?? 'field'));
                                                $fieldValue = (string) ($field['value'] ?? '');
                                                $wideField = in_array((string) ($field['name'] ?? ''), ['notes', 'url'], true)
                                                    || strpos($fieldValue, "\n") !== false
                                                    || strlen($fieldValue) > 90;
                                                ?>
                                                <div class="<?php echo $wideField ? 'md:col-span-2' : ''; ?> rounded-lg border border-white/5 bg-[#0b101e] p-2.5">
                                                    <div class="mb-1 flex items-center justify-between gap-2">
                                                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500"><?php echo htmlspecialchars($fieldLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                        <?php if ($fieldValue !== ''): ?>
                                                            <button type="button" onclick="Lang.copy(<?php echo htmlJsArg($fieldValue); ?>)" class="text-xs text-indigo-300 hover:text-indigo-200" title="<?php echo htmlspecialchars($t('คัดลอก', 'Copy'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-copy"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="select-all whitespace-pre-wrap break-words font-mono text-xs text-gray-200"><?php echo $fieldValue !== '' ? htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '<span class="font-sans text-gray-600">-</span>'; ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="<?php echo $keyIndex > 0 ? 'hidden-keys mt-2' : ''; ?> flex items-center gap-2">
                                        <div class="flex-1 bg-[#0b101e] border border-white/5 rounded-lg px-3 h-10 flex items-center font-mono text-xs text-gray-300 overflow-hidden"><span class="truncate select-all"><?php echo htmlspecialchars($keyCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></div>
                                        <button type="button" onclick="Lang.copy(<?php echo htmlJsArg($keyCode); ?>)" class="bg-[#6366f1] hover:bg-indigo-500 rounded-lg px-3 h-10 text-xs"><i class="bi bi-copy"></i></button>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($deliveredCount > 1): ?><button type="button" class="show-more-key mt-2 text-xs text-indigo-300 hover:underline">+<?php echo $deliveredCount - 1; ?> <?php echo htmlspecialchars($t('รายการส่งมอบเพิ่มเติม', 'more delivered items'), ENT_QUOTES, 'UTF-8'); ?></button><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="mt-4 rounded-lg border border-dashed <?php echo $sourceIsApi ? 'border-orange-500/30 bg-orange-500/5 text-orange-300' : 'border-red-500/30 bg-red-500/5 text-red-300'; ?> p-3 text-xs">
                            <i class="bi bi-exclamation-circle mr-1"></i><?php echo htmlspecialchars($missingKeyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" onclick="openDetailModal(<?php echo htmlJsArg((string) $item['product_name']); ?>, <?php echo htmlJsArg((string) $item['display_date']); ?>, <?php echo htmlJsArg(implode("\n", $keys)); ?>, <?php echo htmlJsArg(formatCurrency((float) $item['total_price'])); ?>, <?php echo htmlJsArg((string) $item['download_url']); ?>, <?php echo htmlJsArg($metaText); ?>, <?php echo htmlJsArg($auditUrl); ?>, <?php echo htmlJsArg($basicAuditJson); ?>)" class="bg-[#1b233d] hover:bg-white/10 border border-white/5 rounded-lg px-3 py-2 text-xs"><i class="bi bi-eye mr-1"></i><?php echo htmlspecialchars($t('ดูหลักฐาน / JSON', 'View evidence / JSON'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php if ($keys): ?><button type="button" onclick="Lang.copy(this.dataset.keys)" data-keys="<?php echo htmlspecialchars(implode("\n", $keys), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="bg-green-600/15 hover:bg-green-600/25 text-green-300 border border-green-500/20 rounded-lg px-3 py-2 text-xs"><i class="bi bi-copy mr-1"></i><?php echo htmlspecialchars($t('คัดลอกทั้งหมด', 'Copy all'), ENT_QUOTES, 'UTF-8'); ?></button><?php endif; ?>
                        <?php
                        $canReconcileReference = $sourceIsStoreBridge
                            ? trim((string) ($item['external_ref'] ?? '')) !== ''
                            : trim((string) ($item['supplier_order_id'] ?? '')) !== '';
                        ?>
                        <?php if (!$keys && $sourceIsApi && !$sourceIsResellerApi && (int) ($item['order_id'] ?? 0) > 0 && $canReconcileReference && !in_array($status, ['refunded', 'refunded_conflict', 'cancelled', 'canceled'], true)): ?>
                            <form method="POST" action="transactions.php" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="<?php echo $sourceIsStoreBridge ? 'reconcile_store_order' : 'reconcile_api_order'; ?>">
                                <input type="hidden" name="order_id" value="<?php echo (int) ($item['order_id'] ?? 0); ?>">
                                <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($currentReturnQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="bg-orange-600/15 hover:bg-orange-600/25 text-orange-300 border border-orange-500/20 rounded-lg px-3 py-2 text-xs"><i class="bi bi-arrow-repeat mr-1"></i><?php echo htmlspecialchars($t('ดึงคีย์จาก API อีกครั้ง', 'Check the API for keys again'), ENT_QUOTES, 'UTF-8'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php else: ?>
                <?php
                $txType = (string) $item['tx_type'];
                $status = (string) $item['status'];
                $statusClass = $statusClasses[$status] ?? 'bg-gray-500/15 text-gray-300 border-gray-500/30';
                $credit = in_array($txType, ['deposit', 'redeem_code', 'manual_add', 'rank_bonus'], true);
                $typeLabels = [
                    'deposit' => $t('เติมเงิน', 'Deposit'),
                    'redeem_code' => $t('เติมเงินด้วยโค้ด', 'Top-up code'),
                    'manual_add' => $t('เพิ่มยอดโดยแอดมิน', 'Admin credit'),
                    'manual_deduct' => $t('หักยอดโดยแอดมิน', 'Admin deduction'),
                    'rank_bonus' => $t('โบนัสแรงค์', 'Rank bonus'),
                    'supplier_purchase' => $t('ซื้อผ่าน Store Bridge API', 'Store Bridge API purchase'),
                    'store_api_purchase' => $t('หักยอดตัวแทนผ่าน Store API', 'Reseller wallet Store API debit'),
                    'cgo_purchase' => $t('ซื้อผ่าน CGO API', 'CGO API purchase'),
                ];
                $typeLabel = $typeLabels[$txType] ?? ucfirst(str_replace('_', ' ', $txType !== '' ? $txType : 'unknown'));
                ?>
                <?php
                $walletRows = (array) ($item['wallet_rows'] ?? []);
                $walletPrimary = $walletRows[0] ?? null;
                $slipEvidence = is_array($item['slip_evidence'] ?? null) ? $item['slip_evidence'] : null;
                $slipTransferAt = $slipEvidence ? (string) ($slipEvidence['transfer_date'] ?? '') : '';
                $slipProviderAt = $slipEvidence ? (string) ($slipEvidence['provider_verified_at'] ?? '') : '';
                $slipCreditedAt = $slipEvidence ? (string) ($slipEvidence['credited_at'] ?? $item['raw_date'] ?? '') : '';
                $slipDelay = $slipEvidence ? adminTxHumanDuration($slipTransferAt, $slipCreditedAt, $isTh) : '';
                $senderAccount = $slipEvidence ? trim((string) ($slipEvidence['sender_account'] ?? '')) : '';
                ?>
                <article class="bg-[#161c2d] border border-white/5 rounded-xl p-4">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-gray-100 text-sm"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <span class="rounded-full border px-2 py-1 text-[10px] font-bold <?php echo $statusClass; ?>"><?php echo htmlspecialchars(adminTxStatusLabel($status, $isTh), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($item['type_inferred'])): ?><span class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2 py-1 text-[10px] font-bold text-amber-300"><?php echo htmlspecialchars($t('กู้ประเภทจากข้อมูลเดิม', 'Recovered type'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if (empty($walletRows)): ?><span class="rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-1 text-[10px] font-bold text-amber-300">LEGACY</span><?php endif; ?>
                                <span class="text-[10px] text-gray-600">TX #<?php echo (int) $item['transaction_id']; ?></span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1 break-words"><i class="bi bi-person mr-1"></i><?php echo htmlspecialchars((string) $item['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) $item['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <div class="text-[11px] text-gray-500 mt-1"><i class="bi bi-calendar3 mr-1"></i><?php echo htmlspecialchars((string) $item['display_date'], ENT_QUOTES, 'UTF-8'); ?></div>

                            <?php if (is_array($walletPrimary)): ?>
                                <div class="mt-3 rounded-lg border border-indigo-500/15 bg-indigo-500/[0.04] p-3 text-xs">
                                    <div class="font-bold text-indigo-200"><i class="bi bi-wallet2 mr-1"></i><?php echo htmlspecialchars($t('หลักฐานยอดเงิน', 'Balance evidence'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ($walletPrimary['balance_before'] !== null && $walletPrimary['balance_after'] !== null): ?>
                                        <div class="mt-1 text-gray-400"><?php echo $t('ยอดก่อน', 'Before'); ?> <span class="text-white"><?php echo formatCurrency((float) $walletPrimary['balance_before']); ?></span> → <?php echo $t('ยอดหลัง', 'After'); ?> <span class="text-white"><?php echo formatCurrency((float) $walletPrimary['balance_after']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($walletPrimary['actor_username'] ?? '')) !== ''): ?><div class="mt-1 text-gray-500"><?php echo $t('ดำเนินการโดย', 'Actor'); ?>: <?php echo htmlspecialchars((string) $walletPrimary['actor_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                    <?php if (trim((string) ($walletPrimary['reference_code'] ?? '')) !== ''): ?><div class="mt-1 text-gray-500 break-all"><?php echo $t('อ้างอิง', 'Reference'); ?>: <?php echo htmlspecialchars((string) $walletPrimary['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                    <?php if (trim((string) ($walletPrimary['admin_note'] ?? '')) !== ''): ?><div class="mt-1 text-[10px] text-gray-600"><?php echo htmlspecialchars((string) $walletPrimary['admin_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($slipEvidence): ?>
                                <details class="mt-3">
                                    <summary class="slip-evidence-toggle cursor-pointer select-none rounded-lg border border-cyan-500/25 bg-cyan-500/[0.06] px-3 py-2.5 text-xs text-cyan-200 hover:bg-cyan-500/[0.10] focus:outline-none focus:ring-2 focus:ring-cyan-400/30">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="inline-flex min-w-0 items-center gap-2 font-bold">
                                                <i class="bi bi-bank shrink-0"></i>
                                                <span class="slip-label-show"><?php echo htmlspecialchars($t('ดูรายละเอียดสลิป', 'View slip details'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="slip-label-hide"><?php echo htmlspecialchars($t('ซ่อนรายละเอียดสลิป', 'Hide slip details'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="hidden sm:inline text-[10px] font-normal text-cyan-300/60"><?php echo htmlspecialchars($t('เฉพาะ Admin', 'Admin only'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </span>
                                            <span class="inline-flex shrink-0 items-center gap-2">
                                                <span class="text-[10px] text-gray-500">Slip #<?php echo (int) ($slipEvidence['id'] ?? 0); ?></span>
                                                <i class="slip-chevron bi bi-chevron-down text-[10px] text-gray-500"></i>
                                            </span>
                                        </span>
                                    </summary>
                                    <div class="mt-2 rounded-xl border border-cyan-500/20 bg-cyan-500/[0.04] p-3">
                                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                                            <div class="rounded-lg bg-black/20 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('ชื่อผู้โอน', 'Sender name'); ?></div><div class="mt-1 text-white break-words"><?php echo htmlspecialchars((string) (($slipEvidence['sender_name'] ?? '') ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                            <div class="rounded-lg bg-black/20 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('บัญชีต้นทาง', 'Sender account'); ?></div><div class="mt-1 flex items-center gap-2"><span class="sensitive-account font-mono text-white break-all" data-masked="<?php echo htmlspecialchars(adminTxMaskSensitiveAccount($senderAccount), ENT_QUOTES, 'UTF-8'); ?>" data-full="<?php echo htmlspecialchars($senderAccount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?php echo htmlspecialchars(adminTxMaskSensitiveAccount($senderAccount), ENT_QUOTES, 'UTF-8'); ?></span><?php if ($senderAccount !== ''): ?><button type="button" class="toggle-sensitive-account text-[10px] text-cyan-300 hover:underline" data-show-label="<?php echo htmlspecialchars($t('ดู', 'Show'), ENT_QUOTES, 'UTF-8'); ?>" data-hide-label="<?php echo htmlspecialchars($t('ซ่อน', 'Hide'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t('ดู', 'Show'), ENT_QUOTES, 'UTF-8'); ?></button><?php endif; ?></div></div>
                                            <div class="rounded-lg bg-black/20 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('ธนาคารต้นทาง', 'Sender bank'); ?></div><div class="mt-1 text-white"><?php echo htmlspecialchars((string) (($slipEvidence['bank_code'] ?? '') ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                            <div class="rounded-lg bg-black/20 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('เลขอ้างอิงสลิป', 'Slip reference'); ?></div><div class="mt-1 text-white break-all"><?php echo htmlspecialchars((string) (($slipEvidence['transaction_ref'] ?? '') ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                        </div>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-3 text-xs">
                                            <div class="rounded-lg border border-white/5 bg-black/15 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('เวลาที่โอนจริงบนสลิป', 'Transfer time on slip'); ?></div><div class="mt-1 text-amber-200"><?php echo htmlspecialchars(adminTxFormatAuditTime($slipTransferAt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                            <div class="rounded-lg border border-white/5 bg-black/15 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('เวลาผู้ให้บริการยืนยัน', 'Provider verified'); ?></div><div class="mt-1 text-cyan-200"><?php echo htmlspecialchars(adminTxFormatAuditTime($slipProviderAt ?: ($slipEvidence['verified_at'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                            <div class="rounded-lg border border-white/5 bg-black/15 p-2.5"><div class="text-[10px] text-gray-500"><?php echo $t('เวลาเว็บไซต์เครดิตเงิน', 'Website credited'); ?></div><div class="mt-1 text-emerald-200"><?php echo htmlspecialchars(adminTxFormatAuditTime($slipCreditedAt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div></div>
                                        </div>
                                        <?php if ($slipDelay !== ''): ?><div class="mt-2 text-[11px] text-gray-400"><i class="bi bi-clock-history mr-1"></i><?php echo $t('ระยะจากเวลาโอนถึงเครดิตเว็บ', 'Transfer-to-credit gap'); ?>: <span class="text-white"><?php echo htmlspecialchars($slipDelay, ENT_QUOTES, 'UTF-8'); ?></span></div><?php endif; ?>
                                        <?php if ($slipEvidence['credit_amount'] !== null || $slipEvidence['bonus_amount'] !== null || $slipEvidence['total_credited'] !== null): ?>
                                            <div class="mt-2 text-[11px] text-gray-500"><?php echo $t('รายละเอียดเครดิต', 'Credit detail'); ?>: <?php echo $t('เงินต้น', 'base'); ?> <?php echo formatCurrency((float) ($slipEvidence['credit_amount'] ?? $item['amount'])); ?> · <?php echo $t('โบนัส', 'bonus'); ?> <?php echo formatCurrency((float) ($slipEvidence['bonus_amount'] ?? 0)); ?> · <?php echo $t('รวม', 'total'); ?> <?php echo formatCurrency((float) ($slipEvidence['total_credited'] ?? $item['amount'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                        <div class="font-bold text-lg <?php echo $credit ? 'text-green-400' : 'text-red-400'; ?> whitespace-nowrap"><?php echo $credit ? '+' : '-'; ?><?php echo formatCurrency((float) $item['amount']); ?></div>
                    </div>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($transactionPageHasPrevious || $transactionPageHasNext): ?>
            <nav class="flex items-center justify-between gap-3 pt-2" aria-label="<?php echo htmlspecialchars($t('หน้าธุรกรรม', 'Transaction pages'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($transactionPageHasPrevious): ?>
                    <a href="<?php echo htmlspecialchars($transactionPageUrl($transactionPage - 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                       class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-[#161c2d] px-3 py-2 text-xs text-gray-200 hover:bg-[#1b233d]">
                        <i class="bi bi-chevron-left"></i><?php echo htmlspecialchars($t('ก่อนหน้า', 'Previous'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <span class="text-xs text-gray-500"><?php echo htmlspecialchars(($isTh ? 'หน้า ' : 'Page ') . number_format($transactionPage) . ' / ' . number_format($transactionLoadedPages), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($transactionPageHasNext): ?>
                    <a href="<?php echo htmlspecialchars($transactionPageUrl($transactionPage + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                       class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-[#161c2d] px-3 py-2 text-xs text-gray-200 hover:bg-[#1b233d]">
                        <?php echo htmlspecialchars($t('ถัดไป', 'Next'), ENT_QUOTES, 'UTF-8'); ?><i class="bi bi-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>

<div id="detailModalOverlay" aria-hidden="true">
    <button type="button" id="detailModalBackdrop" aria-label="<?php echo htmlspecialchars($t('ปิดหน้าต่างหลักฐาน', 'Close evidence dialog'), ENT_QUOTES, 'UTF-8'); ?>"></button>
    <section id="detailModalPanel" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle" tabindex="-1">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-white/10 bg-[#1b2336]/95 p-4 backdrop-blur">
            <div class="min-w-0">
                <h5 id="detailModalTitle" class="truncate font-bold text-white text-base"><?php echo htmlspecialchars($t('หลักฐานการซื้อและ JSON สำหรับตรวจสอบ', 'Purchase Evidence and Diagnostic JSON'), ENT_QUOTES, 'UTF-8'); ?></h5>
                <div id="modalAuditStatus" class="mt-0.5 text-[10px] text-gray-400"><?php echo htmlspecialchars($t('พร้อมตรวจสอบ', 'Ready'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <button type="button" onclick="closeDetailModal()" class="shrink-0 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-gray-300 hover:bg-white/10 hover:text-white" aria-label="<?php echo htmlspecialchars($t('ปิด', 'Close'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="space-y-4 p-4 sm:p-5">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-white/5 bg-[#111827] p-3 sm:col-span-2"><div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"><?php echo htmlspecialchars($t('สินค้า', 'Product'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-white text-sm break-words" id="modalProductName">-</div></div>
                <div class="rounded-lg border border-white/5 bg-[#111827] p-3"><div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"><?php echo htmlspecialchars($t('วันที่ซื้อ', 'Purchase date'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-white text-sm" id="modalDate">-</div></div>
                <div class="rounded-lg border border-white/5 bg-[#111827] p-3"><div class="text-gray-500 text-[10px] mb-1 uppercase tracking-wide"><?php echo htmlspecialchars($t('ยอดที่จ่าย', 'Amount paid'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-green-400 font-bold" id="modalPrice">-</div></div>
            </div>

            <div>
                <div class="mb-1 text-xs font-semibold text-gray-300"><?php echo htmlspecialchars($t('หลักฐานอ้างอิง', 'Audit references'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="rounded-lg border border-white/5 bg-[#0b101e] p-3 text-xs text-gray-300 whitespace-pre-wrap break-all" id="modalMeta">-</div>
            </div>

            <div>
                <div class="mb-1 text-xs font-semibold text-gray-300"><?php echo htmlspecialchars($t('คีย์ที่ส่งมอบ', 'Delivered keys'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="max-h-56 overflow-y-auto rounded-lg border border-white/5 bg-[#0b101e] p-3 text-gray-300 font-mono text-sm break-all" id="modalKeys"></div>
            </div>

            <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/[0.04] p-3">
                <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="text-xs font-bold text-cyan-200"><i class="bi bi-braces mr-1"></i><?php echo htmlspecialchars($t('JSON หลักฐานแบบละเอียด', 'Detailed evidence JSON'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-1 text-[10px] text-gray-500"><?php echo htmlspecialchars($t('แสดงข้อมูลพื้นฐานทันที แล้วโหลดหลักฐานฉบับเต็มจากฐานข้อมูลแบบอ่านอย่างเดียว', 'Shows basic evidence immediately, then loads the full read-only database evidence.'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="#" target="_blank" rel="noopener noreferrer" data-no-page-loader id="modalOpenJsonBtn" class="hidden rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-white/10"><i class="bi bi-box-arrow-up-right mr-1"></i><?php echo htmlspecialchars($t('เปิด JSON ตรง', 'Open JSON'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <button type="button" onclick="copyModalAuditJson()" class="hidden rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-3 py-2 text-xs font-medium text-cyan-200 hover:bg-cyan-400/20" id="modalCopyJsonBtn"><i class="bi bi-copy mr-1"></i><?php echo htmlspecialchars($t('คัดลอก JSON', 'Copy JSON'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </div>
                </div>
                <pre id="modalAuditJson" class="audit-json-box rounded-lg border border-white/5 bg-[#070b14] p-3 text-[11px] leading-relaxed text-gray-300">-</pre>
            </div>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button type="button" onclick="copyModalKeys()" class="w-full bg-green-600 hover:bg-green-500 rounded-lg py-2.5 text-sm font-medium" id="modalCopyBtn"><i class="bi bi-copy mr-1"></i><?php echo htmlspecialchars($t('คัดลอกคีย์ทั้งหมด', 'Copy all keys'), ENT_QUOTES, 'UTF-8'); ?></button>
                <a href="#" target="_blank" rel="noopener noreferrer" id="modalDownloadBtn" class="hidden w-full items-center justify-center gap-2 rounded-lg bg-orange-600 py-2.5 text-sm font-medium hover:bg-orange-500"><i class="bi bi-download"></i><?php echo htmlspecialchars($t('ดาวน์โหลด', 'Download'), ENT_QUOTES, 'UTF-8'); ?></a>
                <button type="button" onclick="closeDetailModal()" class="sm:col-span-2 w-full bg-[#2a3454] hover:bg-[#323d60] rounded-lg py-2.5 text-sm"><?php echo htmlspecialchars($t('ปิด', 'Close'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
    </section>
</div>

<script>

function syncTransactionSourceFilter() {
    const transactionTypeFilter = document.getElementById('transactionTypeFilter');
    const transactionSourceFilter = document.getElementById('transactionSourceFilter');
    if (!transactionTypeFilter || !transactionSourceFilter) return;
    const sourceIsApplicable = transactionTypeFilter.value === 'all'
        || transactionTypeFilter.value === 'purchase';

    transactionSourceFilter.disabled = !sourceIsApplicable;
    if (!sourceIsApplicable) transactionSourceFilter.value = 'all';
}

document.addEventListener('change', function (event) {
    if (event.target && event.target.id === 'transactionTypeFilter') syncTransactionSourceFilter();
});
document.addEventListener('instantfilter:updated', syncTransactionSourceFilter);
syncTransactionSourceFilter();

document.addEventListener('click', function (event) {
    const button = event.target && event.target.closest ? event.target.closest('.toggle-sensitive-account') : null;
    if (!button) return;
    const container = button.parentElement;
    const value = container ? container.querySelector('.sensitive-account') : null;
    if (!value) return;
    const masked = value.dataset.masked || '';
    const full = value.dataset.full || '';
    const currentlyMasked = value.textContent === masked;
    value.textContent = currentlyMasked ? full : masked;
    button.textContent = currentlyMasked
        ? (button.dataset.hideLabel || 'Hide')
        : (button.dataset.showLabel || 'Show');
});

window.currentModalKeys = '';
window.currentModalAuditJson = '';
window.currentModalAuditController = null;
window.currentModalAuditTimeout = 0;
const auditEvidenceCache = new Map();

function auditPrettyJson(raw, fallbackValue) {
    const text = String(raw || '').trim();
    if (text === '') return String(fallbackValue || '');
    try {
        return JSON.stringify(JSON.parse(text), null, 2);
    } catch (_error) {
        return text;
    }
}

function auditSetStatus(message, tone) {
    const status = document.getElementById('modalAuditStatus');
    if (!status) return;
    status.textContent = message || '';
    status.className = 'mt-0.5 text-[10px] ' + (
        tone === 'ok' ? 'text-emerald-400'
        : tone === 'error' ? 'text-red-400'
        : tone === 'warn' ? 'text-amber-300'
        : 'text-gray-400'
    );
}

function auditShowJson(text) {
    const jsonBox = document.getElementById('modalAuditJson');
    const copyJsonBtn = document.getElementById('modalCopyJsonBtn');
    const normalized = String(text || '').trim();
    window.currentModalAuditJson = normalized;
    jsonBox.textContent = normalized || <?php echo json_encode($t('ไม่มีข้อมูล JSON สำหรับรายการนี้', 'No JSON evidence is available for this record.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    copyJsonBtn.classList.toggle('hidden', normalized === '');
}

function auditBuildFailureSnapshot(basicJson, message, auditUrl, httpStatus) {
    let payload = {};
    try {
        const parsed = JSON.parse(String(basicJson || '{}'));
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) payload = parsed;
    } catch (_error) {
        payload = { page_snapshot_raw: String(basicJson || '') };
    }
    payload.full_evidence_fetch = {
        success: false,
        error: String(message || 'Unknown error'),
        http_status: Number.isFinite(Number(httpStatus)) ? Number(httpStatus) : null,
        audit_endpoint: String(auditUrl || ''),
        failed_at: new Date().toISOString()
    };
    return JSON.stringify(payload, null, 2);
}

function ensureAuditModalAtBodyRoot() {
    const overlay = document.getElementById('detailModalOverlay');
    if (overlay && overlay.parentElement !== document.body) document.body.appendChild(overlay);
    return overlay;
}

async function openDetailModal(productName, date, keys, price, downloadUrl, meta, auditUrl, basicJson) {
    const overlay = ensureAuditModalAtBodyRoot();
    const panel = document.getElementById('detailModalPanel');
    if (!overlay || !panel) return;

    document.getElementById('modalProductName').textContent = productName || '-';
    document.getElementById('modalDate').textContent = date || '-';
    document.getElementById('modalPrice').textContent = price || '-';
    document.getElementById('modalMeta').textContent = meta || '-';
    window.currentModalKeys = keys || '';

    const keyBox = document.getElementById('modalKeys');
    keyBox.replaceChildren();
    const list = String(keys || '').split('\n').filter(value => value.trim() !== '');
    if (!list.length) {
        const empty = document.createElement('div');
        empty.className = 'text-gray-500 font-sans';
        empty.textContent = <?php echo json_encode($t('ยังไม่มีคีย์ที่บันทึก', 'No delivered key is stored'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        keyBox.appendChild(empty);
    } else {
        list.forEach(value => {
            const row = document.createElement('div');
            row.className = 'mb-2 last:mb-0 select-all';
            row.textContent = value;
            keyBox.appendChild(row);
        });
    }

    document.getElementById('modalCopyBtn').classList.toggle('hidden', !list.length);

    const downloadBtn = document.getElementById('modalDownloadBtn');
    if (downloadUrl && String(downloadUrl).trim() !== '') {
        downloadBtn.href = String(downloadUrl);
        downloadBtn.classList.remove('hidden');
        downloadBtn.classList.add('flex');
    } else {
        downloadBtn.removeAttribute('href');
        downloadBtn.classList.add('hidden');
        downloadBtn.classList.remove('flex');
    }

    const openJsonBtn = document.getElementById('modalOpenJsonBtn');
    if (auditUrl && String(auditUrl).trim() !== '') {
        openJsonBtn.href = String(auditUrl);
        openJsonBtn.classList.remove('hidden');
    } else {
        openJsonBtn.removeAttribute('href');
        openJsonBtn.classList.add('hidden');
    }

    // Never show a blank modal. A page-rendered snapshot is available
    // immediately even if the follow-up database request fails or times out.
    const immediateJson = auditPrettyJson(
        basicJson,
        JSON.stringify({
            success: true,
            scope: 'page_snapshot',
            product: String(productName || ''),
            date: String(date || ''),
            price: String(price || ''),
            audit_endpoint: String(auditUrl || '')
        }, null, 2)
    );
    auditShowJson(immediateJson);
    auditSetStatus(
        auditUrl
            ? <?php echo json_encode($t('แสดงข้อมูลพื้นฐานแล้ว กำลังโหลดหลักฐานฉบับเต็ม...', 'Basic evidence is visible. Loading full database evidence...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            : <?php echo json_encode($t('แสดงข้อมูลพื้นฐานจากหน้าปัจจุบัน', 'Showing the page evidence snapshot'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        auditUrl ? 'loading' : 'warn'
    );

    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('audit-modal-open');
    window.requestAnimationFrame(() => {
        panel.scrollTop = 0;
        try { panel.focus({ preventScroll: true }); } catch (_error) { panel.focus(); }
    });

    if (window.currentModalAuditController) window.currentModalAuditController.abort();
    if (window.currentModalAuditTimeout) window.clearTimeout(window.currentModalAuditTimeout);
    window.currentModalAuditController = null;
    window.currentModalAuditTimeout = 0;

    if (!auditUrl) return;

    const cacheKey = String(auditUrl);
    if (auditEvidenceCache.has(cacheKey)) {
        auditShowJson(auditEvidenceCache.get(cacheKey));
        auditSetStatus(<?php echo json_encode($t('โหลดหลักฐานฉบับเต็มจากแคชแล้ว', 'Full evidence loaded from cache'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, 'ok');
        return;
    }

    const controller = new AbortController();
    window.currentModalAuditController = controller;
    let timedOut = false;
    window.currentModalAuditTimeout = window.setTimeout(() => {
        timedOut = true;
        try { controller.abort(); } catch (_error) {}
    }, 15000);

    try {
        const response = await fetch(auditUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
        });
        const rawText = await response.text();
        if (controller !== window.currentModalAuditController) return;

        const trimmed = String(rawText || '').trim();
        if (trimmed === '') {
            const failure = auditBuildFailureSnapshot(
                basicJson,
                <?php echo json_encode($t('เซิร์ฟเวอร์คืนคำตอบว่างเปล่า', 'The server returned an empty response'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                auditUrl,
                response.status
            );
            auditShowJson(failure);
            auditSetStatus(<?php echo json_encode($t('หลักฐานฉบับเต็มว่างเปล่า จึงคงข้อมูลพื้นฐานไว้', 'Full evidence was empty; the page snapshot was preserved'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, 'warn');
            return;
        }

        let prettyText = trimmed;
        let parsed = null;
        try {
            parsed = JSON.parse(trimmed);
            prettyText = JSON.stringify(parsed, null, 2);
        } catch (_error) {
            // Keep HTML/plain-text diagnostics verbatim. Cloudflare and PHP
            // errors can be more useful than replacing them with a generic error.
        }

        if (!response.ok) {
            prettyText = auditBuildFailureSnapshot(
                basicJson,
                'HTTP ' + response.status + (prettyText ? '\n' + prettyText.slice(0, 200000) : ''),
                auditUrl,
                response.status
            );
            auditShowJson(prettyText);
            auditSetStatus(<?php echo json_encode($t('โหลดหลักฐานฉบับเต็มไม่สำเร็จ แต่ข้อมูลพื้นฐานยังคัดลอกได้', 'Full evidence failed to load, but the page snapshot remains copyable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, 'error');
            return;
        }

        // Cap only rendering/copy payload at a generous size to keep mobile UI
        // responsive if a provider accidentally saved a huge response.
        if (prettyText.length > 500000) {
            prettyText = prettyText.slice(0, 500000) + '\n\n[TRUNCATED FOR ADMIN UI: response exceeded 500000 characters]';
        }
        auditEvidenceCache.set(cacheKey, prettyText);
        auditShowJson(prettyText);
        auditSetStatus(<?php echo json_encode($t('โหลดหลักฐานฉบับเต็มจากฐานข้อมูลแล้ว', 'Full database evidence loaded'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, 'ok');
    } catch (error) {
        if (controller !== window.currentModalAuditController) return;
        const message = timedOut
            ? <?php echo json_encode($t('หมดเวลารอหลักฐาน 15 วินาที', 'Evidence request timed out after 15 seconds'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            : (error && error.message ? error.message : String(error || 'Unknown error'));
        const failure = auditBuildFailureSnapshot(basicJson, message, auditUrl, null);
        auditShowJson(failure);
        auditSetStatus(
            timedOut
                ? <?php echo json_encode($t('โหลดเกินเวลา แต่ข้อมูลพื้นฐานยังอยู่และคัดลอกได้', 'Timed out, but the page snapshot remains available and copyable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                : <?php echo json_encode($t('โหลดหลักฐานฉบับเต็มไม่สำเร็จ แต่ข้อมูลพื้นฐานยังอยู่', 'Full evidence could not be loaded; the page snapshot remains available'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            timedOut ? 'warn' : 'error'
        );
    } finally {
        if (window.currentModalAuditTimeout) window.clearTimeout(window.currentModalAuditTimeout);
        window.currentModalAuditTimeout = 0;
        if (controller === window.currentModalAuditController) window.currentModalAuditController = null;
    }
}

function closeDetailModal() {
    if (window.currentModalAuditController) {
        try { window.currentModalAuditController.abort(); } catch (_error) {}
        window.currentModalAuditController = null;
    }
    if (window.currentModalAuditTimeout) {
        window.clearTimeout(window.currentModalAuditTimeout);
        window.currentModalAuditTimeout = 0;
    }
    const overlay = document.getElementById('detailModalOverlay');
    if (overlay) {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('audit-modal-open');
}

function copyModalKeys() {
    if (window.currentModalKeys) {
        if (window.Lang && typeof Lang.copy === 'function') Lang.copy(window.currentModalKeys);
        else if (navigator.clipboard) navigator.clipboard.writeText(window.currentModalKeys);
    }
}

function copyModalAuditJson() {
    if (window.currentModalAuditJson) {
        if (window.Lang && typeof Lang.copy === 'function') Lang.copy(window.currentModalAuditJson);
        else if (navigator.clipboard) navigator.clipboard.writeText(window.currentModalAuditJson);
    }
}

document.getElementById('detailModalBackdrop').addEventListener('click', closeDetailModal);
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && document.getElementById('detailModalOverlay')?.classList.contains('is-open')) {
        closeDetailModal();
    }
});
document.addEventListener('click', function (event) {
    const button = event.target.closest('.show-more-key');
    if (!button) return;
    const container = button.closest('.key-container');
    if (container) container.classList.add('show-keys');
    button.remove();
});
</script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
