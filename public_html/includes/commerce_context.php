<?php
require_once __DIR__ . '/commerce_center.php';
/**
 * Central read model for commerce data.
 *
 * This file does not copy or synchronize mutable business rows. It resolves a
 * single, stable context from the authoritative source tables so audit logs,
 * transaction pages, API pages, and public activity feeds all use the same
 * naming and ownership rules.
 */

if (!function_exists('commerceContextTableColumns')) {
    /** @return array<string,bool> */
    function commerceContextTableColumns(string $table): array
    {
        global $conn;
        static $cache = [];
        $table = strtolower(trim($table));
        $allowed = [
            'users', 'transactions', 'keys', 'products', 'product_variants',
            'cgo_orders', 'cgo_order_keys', 'cgo_products',
            'supplier_orders', 'supplier_order_keys', 'supplier_products',
            'store_api_orders', 'store_api_order_keys', 'store_api_clients',
            'purchase_activity_events',
            'commerce_orders', 'commerce_order_items', 'commerce_deliveries',
            'transaction_type_repair_batches', 'transaction_type_repair_log',
        ];
        if (!in_array($table, $allowed, true)) return [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        if (!isset($conn) || !($conn instanceof mysqli)) return $cache[$table] = [];

        $columns = [];
        try {
            $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $name = strtolower(trim((string) ($row['Field'] ?? '')));
                    if ($name !== '') $columns[$name] = true;
                }
                $result->free();
            }
        } catch (Throwable $e) {
            error_log('Commerce context schema inspection failed for ' . $table . ': ' . $e->getMessage());
        }
        return $cache[$table] = $columns;
    }
}

if (!function_exists('commerceContextHasColumns')) {
    /** @param array<string,bool> $columns */
    function commerceContextHasColumns(array $columns, array $required): bool
    {
        foreach ($required as $column) {
            if (empty($columns[strtolower((string) $column)])) return false;
        }
        return true;
    }
}


if (!function_exists('commerceNormalizeSource')) {
    /**
     * Convert every historical source label into one unambiguous namespace.
     *
     * `store_api` used to mean the supplier-consumer side in My Keys/History,
     * while the provider side also owns tables named store_api_*.  New code uses
     * `supplier` for consumer orders and `store_api_client` for provider orders.
     */
    function commerceNormalizeSource(string $source, string $recordId = ''): string
    {
        $source = strtolower(trim($source));
        $recordId = strtolower(trim($recordId));
        if ($source === 'local') return 'local';
        if (in_array($source, ['api', 'cgo', 'cheatgame'], true)) return 'cgo';
        if (in_array($source, ['supplier', 'supplier_api', 'store_bridge'], true)) return 'supplier';
        if (in_array($source, ['store_api_client', 'api_client', 'provider_api'], true)) return 'store_api_client';
        if ($source === 'store_api') {
            // All historical user-key rows emitted by supplierBridge used this
            // value. Provider-order contexts are emitted with store_api_client
            // from this version onward.
            if (strpos($recordId, 'store-api-') === 0 || strpos($recordId, 'client-') === 0) {
                return 'store_api_client';
            }
            return 'supplier';
        }
        return '';
    }
}

if (!function_exists('commerceSuccessfulOrderStatuses')) {
    /** @return string[] */
    function commerceSuccessfulOrderStatuses(): array
    {
        return ['success', 'completed'];
    }
}

if (!function_exists('commerceOrderStatusIsSuccessful')) {
    function commerceOrderStatusIsSuccessful(string $status): bool
    {
        return in_array(strtolower(trim($status)), commerceSuccessfulOrderStatuses(), true);
    }
}

if (!function_exists('commerceSuccessfulStatusSql')) {
    /** Build a static, injection-safe success predicate for a trusted SQL alias. */
    function commerceSuccessfulStatusSql(string $alias = 'o'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) $alias = 'o';
        return "LOWER(TRIM(COALESCE({$alias}.status, ''))) IN ('success','completed')";
    }
}

if (!function_exists('commerceContextBindParams')) {
    function commerceContextBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '') return true;
        if (strlen($types) !== count($params)) return false;
        $args = [$types];
        foreach ($params as $index => $_value) $args[] = &$params[$index];
        return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('commerceContextQueryFirst')) {
    function commerceContextQueryFirst(string $sql, string $types = '', array $params = []): ?array
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return null;
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return null;
            if ($types !== '' && !commerceContextBindParams($stmt, $types, $params)) {
                $stmt->close();
                return null;
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) $result->free();
            $stmt->close();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('Commerce context query failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('commerceNormalizeDuration')) {
    function commerceNormalizeDuration(string $duration): string
    {
        $duration = trim(preg_replace('/\s+/u', ' ', $duration) ?? $duration);
        if ($duration === '' || strcasecmp($duration, 'standard') === 0 || strcasecmp($duration, 'default') === 0) {
            return '';
        }
        if (function_exists('mb_substr')) return mb_substr($duration, 0, 120, 'UTF-8');
        return substr($duration, 0, 120);
    }
}

if (!function_exists('commerceComposeProductLabel')) {
    function commerceComposeProductLabel(string $name, string $duration): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $duration = commerceNormalizeDuration($duration);
        if ($name === '') $name = function_exists('getAppLang') && getAppLang() === 'en' ? 'Product' : 'สินค้า';
        if ($duration === '') return $name;

        $haystack = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($duration, 'UTF-8') : strtolower($duration);
        if ($needle !== '' && strpos($haystack, $needle) !== false) return $name;
        return $name . ' - ' . $duration;
    }
}

if (!function_exists('commerceContextEmpty')) {
    function commerceContextEmpty(): array
    {
        return [
            'resolved' => false,
            'conflict' => false,
            'match_count' => 0,
            'matches' => [],
            'source' => '',
            'key_record_id' => 0,
            'source_key_id' => 0,
            'owner_kind' => '',
            'owner_user_id' => 0,
            'owner_username' => '',
            'owner_email' => '',
            'owner_role' => '',
            'owner_display' => '',
            'product_name' => '',
            'product_duration' => '',
            'product_display_name' => '',
            'transaction_id' => 0,
            'order_id' => 0,
            'purchased_at' => '',
            'external_ref' => '',
            'order_uuid' => '',
            'owner_site_id' => '',
            'owner_external_user_id' => '',
            'customer_ref' => '',
            'ownership_status' => '',
            'total_price' => 0.0,
            'currency' => '',
        ];
    }
}

if (!function_exists('commerceContextFinalize')) {
    function commerceContextFinalize(array $row, string $source): array
    {
        $context = commerceContextEmpty();
        $context['resolved'] = true;
        $context['match_count'] = 1;
        $context['source'] = $source;
        $context['key_record_id'] = max(0, (int) ($row['key_record_id'] ?? 0));
        $context['source_key_id'] = max(0, (int) ($row['source_key_id'] ?? 0));
        $context['owner_kind'] = trim((string) ($row['owner_kind'] ?? 'user'));
        $context['owner_user_id'] = max(0, (int) ($row['owner_user_id'] ?? 0));
        $context['owner_username'] = trim((string) ($row['owner_username'] ?? ''));
        $context['owner_email'] = trim((string) ($row['owner_email'] ?? ''));
        $context['owner_role'] = trim((string) ($row['owner_role'] ?? ''));
        $ownerDisplay = trim((string) ($row['owner_display'] ?? ''));
        if ($ownerDisplay === '') $ownerDisplay = $context['owner_username'];
        if ($ownerDisplay === '' && $context['owner_email'] !== '') $ownerDisplay = $context['owner_email'];
        if ($ownerDisplay === '' && $context['owner_user_id'] > 0) $ownerDisplay = 'user#' . $context['owner_user_id'];
        $context['owner_display'] = $ownerDisplay;
        $context['product_name'] = trim((string) ($row['product_name'] ?? ''));
        $context['product_duration'] = commerceNormalizeDuration((string) ($row['product_duration'] ?? ''));
        $context['product_display_name'] = commerceComposeProductLabel($context['product_name'], $context['product_duration']);
        $context['transaction_id'] = max(0, (int) ($row['transaction_id'] ?? 0));
        $context['order_id'] = max(0, (int) ($row['order_id'] ?? 0));
        $context['purchased_at'] = trim((string) ($row['purchased_at'] ?? ''));
        $context['external_ref'] = trim((string) ($row['external_ref'] ?? ''));
        $context['order_uuid'] = trim((string) ($row['order_uuid'] ?? ''));
        $context['owner_site_id'] = trim((string) ($row['owner_site_id'] ?? ''));
        $context['owner_external_user_id'] = trim((string) ($row['owner_external_user_id'] ?? ''));
        $context['customer_ref'] = trim((string) ($row['customer_ref'] ?? ''));
        $context['ownership_status'] = trim((string) ($row['ownership_status'] ?? ''));
        $context['total_price'] = round((float) ($row['total_price'] ?? 0), 2);
        $context['currency'] = trim((string) ($row['currency'] ?? ''));
        return $context;
    }
}

if (!function_exists('commerceContextWhere')) {
    /** @return array{0:string,1:string,2:array} */
    function commerceContextWhere(string $alias, array $columns, string $source, string $recordId, string $fullKey, string $keyHash): array
    {
        $id = ctype_digit($recordId) ? (int) $recordId : 0;
        $conditions = [];
        $types = '';
        $params = [];

        if ($id > 0 && $source !== '') {
            $conditions[] = "{$alias}.id = ?";
            $types .= 'i';
            $params[] = $id;

            // A stored record ID is only a hint. When a hash/key is available,
            // verify it too so a stale source/id cannot resolve another owner's
            // key merely because numeric IDs overlap between tables.
            if ($fullKey !== '' && !empty($columns['key_code'])) {
                $conditions[] = "CAST(TRIM({$alias}.key_code) AS BINARY) = CAST(? AS BINARY)";
                $types .= 's';
                $params[] = $fullKey;
            } elseif ($keyHash !== '') {
                if (!empty($columns['key_hash'])) {
                    $conditions[] = "CAST({$alias}.key_hash AS BINARY) = CAST(? AS BINARY)";
                    $types .= 's';
                    $params[] = $keyHash;
                } elseif (!empty($columns['key_code'])) {
                    $conditions[] = "CAST(SHA2(TRIM({$alias}.key_code), 256) AS BINARY) = CAST(? AS BINARY)";
                    $types .= 's';
                    $params[] = $keyHash;
                }
            }
            return [implode(' AND ', $conditions), $types, $params];
        }

        if ($fullKey !== '' && !empty($columns['key_code'])) {
            return ["CAST(TRIM({$alias}.key_code) AS BINARY) = CAST(? AS BINARY)", 's', [$fullKey]];
        }
        if ($keyHash !== '') {
            if (!empty($columns['key_hash'])) {
                return ["CAST({$alias}.key_hash AS BINARY) = CAST(? AS BINARY)", 's', [$keyHash]];
            }
            if (!empty($columns['key_code'])) {
                return ["CAST(SHA2(TRIM({$alias}.key_code), 256) AS BINARY) = CAST(? AS BINARY)", 's', [$keyHash]];
            }
        }
        return ['1 = 0', '', []];
    }
}

if (!function_exists('commerceResolveStoreApiContext')) {
    function commerceResolveStoreApiContext(string $recordId, string $fullKey, string $keyHash, string $source = ''): ?array
    {
        $keyCols = commerceContextTableColumns('store_api_order_keys');
        $orderCols = commerceContextTableColumns('store_api_orders');
        $clientCols = commerceContextTableColumns('store_api_clients');
        if (!commerceContextHasColumns($keyCols, ['id', 'order_id', 'key_code'])
            || !commerceContextHasColumns($orderCols, ['id', 'client_id', 'status'])) return null;

        [$where, $types, $params] = commerceContextWhere('ok', $keyCols, $source, $recordId, $fullKey, $keyHash);
        $clientJoin = commerceContextHasColumns($clientCols, ['id', 'name'])
            ? 'LEFT JOIN store_api_clients c ON c.id = o.client_id'
            : '';
        $productJoin = commerceContextHasColumns(commerceContextTableColumns('products'), ['id', 'name'])
            && !empty($orderCols['source_product_id'])
            ? 'LEFT JOIN products p ON p.id = o.source_product_id'
            : '';
        $variantJoin = commerceContextHasColumns(commerceContextTableColumns('product_variants'), ['id', 'duration'])
            && !empty($orderCols['source_variant_id'])
            ? 'LEFT JOIN product_variants pv ON pv.id = o.source_variant_id'
            : '';

        $recipientName = !empty($orderCols['customer_name']) ? "NULLIF(o.customer_name, '')" : "''";
        $clientName = $clientJoin !== '' ? "NULLIF(c.name, '')" : "NULL";
        // The commercial owner is the API client. customer_name/customer_email
        // describe the client's recipient and must not silently replace the owner.
        $ownerName = "COALESCE({$clientName}, CONCAT('API client#', o.client_id), {$recipientName}, 'API client')";
        $ownerEmail = "''";
        $productName = $productJoin !== '' ? "COALESCE(NULLIF(p.name, ''), '')" : "''";
        $duration = $variantJoin !== '' ? "COALESCE(NULLIF(pv.duration, ''), " . (!empty($orderCols['duration']) ? "NULLIF(o.duration, '')" : "''") . ", '')"
            : (!empty($orderCols['duration']) ? "COALESCE(o.duration, '')" : "''");
        $timeParts = [];
        foreach (['completed_at', 'created_at'] as $column) if (!empty($orderCols[$column])) $timeParts[] = 'o.' . $column;
        if (!empty($keyCols['created_at'])) $timeParts[] = 'ok.created_at';
        $time = $timeParts ? 'COALESCE(' . implode(', ', $timeParts) . ')' : "''";
        $externalRef = !empty($orderCols['external_ref']) ? "COALESCE(o.external_ref, '')" : "''";
        $sourceKeyId = !empty($keyCols['source_key_id']) ? 'COALESCE(ok.source_key_id, 0)' : '0';

        $row = commerceContextQueryFirst(
            "SELECT ok.id AS key_record_id,
                    {$sourceKeyId} AS source_key_id,
                    'api_client' AS owner_kind,
                    0 AS owner_user_id,
                    {$ownerName} AS owner_username,
                    {$ownerEmail} AS owner_email,
                    'api_client' AS owner_role,
                    {$ownerName} AS owner_display,
                    {$productName} AS product_name,
                    {$duration} AS product_duration,
                    0 AS transaction_id,
                    o.id AS order_id,
                    {$time} AS purchased_at,
                    {$externalRef} AS external_ref
             FROM store_api_order_keys ok
             JOIN store_api_orders o ON o.id = ok.order_id
             {$clientJoin}
             {$productJoin}
             {$variantJoin}
             WHERE {$where}
               AND " . commerceSuccessfulStatusSql('o') . "
             ORDER BY o.id DESC LIMIT 1",
            $types,
            $params
        );
        return $row ? commerceContextFinalize($row, 'store_api_client') : null;
    }
}

if (!function_exists('commerceResolveLocalContext')) {
    function commerceResolveLocalContext(string $recordId, string $fullKey, string $keyHash, string $source = ''): ?array
    {
        $keyCols = commerceContextTableColumns('keys');
        if (!commerceContextHasColumns($keyCols, ['id', 'key_code'])) return null;
        [$where, $types, $params] = commerceContextWhere('k', $keyCols, $source, $recordId, $fullKey, $keyHash);

        $txCols = commerceContextTableColumns('transactions');
        $hasTx = commerceContextHasColumns($txCols, ['id', 'reference_id', 'user_id']);
        $txConditions = ['t2.reference_id = k.id'];
        if (!empty($txCols['type'])) $txConditions[] = "LOWER(TRIM(COALESCE(t2.type, ''))) = 'purchase'";
        if (!empty($txCols['status'])) $txConditions[] = "LOWER(TRIM(COALESCE(t2.status, ''))) = 'completed'";
        $txJoin = $hasTx
            ? "LEFT JOIN transactions t ON t.id = (
                    SELECT MAX(t2.id) FROM transactions t2
                    WHERE " . implode("
                      AND ", $txConditions) . "
               )"
            : '';

        $ownerParts = [];
        if ($hasTx) $ownerParts[] = 'NULLIF(t.user_id, 0)';
        if (!empty($keyCols['purchased_by'])) $ownerParts[] = 'NULLIF(k.purchased_by, 0)';
        if (!empty($keyCols['assigned_to'])) $ownerParts[] = 'NULLIF(k.assigned_to, 0)';
        $ownerExpr = $ownerParts ? 'COALESCE(' . implode(', ', $ownerParts) . ', 0)' : '0';
        $userCols = commerceContextTableColumns('users');
        $userJoin = commerceContextHasColumns($userCols, ['id', 'username'])
            ? "LEFT JOIN users u ON u.id = {$ownerExpr}"
            : '';
        $username = $userJoin !== '' ? "COALESCE(u.username, '')" : "''";
        $email = $userJoin !== '' && !empty($userCols['email']) ? "COALESCE(u.email, '')" : "''";
        $role = $userJoin !== '' && !empty($userCols['role']) ? "COALESCE(u.role, '')" : "''";

        $productCols = commerceContextTableColumns('products');
        $productJoin = !empty($keyCols['product_id']) && commerceContextHasColumns($productCols, ['id', 'name'])
            ? 'LEFT JOIN products p ON p.id = k.product_id'
            : '';
        $productName = $productJoin !== '' ? "COALESCE(p.name, '')" : "''";

        $variantCols = commerceContextTableColumns('product_variants');
        $variantJoin = !empty($keyCols['variant_id']) && commerceContextHasColumns($variantCols, ['id', 'duration'])
            ? 'LEFT JOIN product_variants pv ON pv.id = k.variant_id'
            : '';
        $durationParts = [];
        if ($variantJoin !== '') $durationParts[] = "NULLIF(pv.duration, '')";
        if (!empty($keyCols['duration'])) $durationParts[] = "NULLIF(k.duration, '')";
        $duration = $durationParts ? 'COALESCE(' . implode(', ', $durationParts) . ", '')" : "''";
        $transactionId = $hasTx ? 'COALESCE(t.id, 0)' : '0';
        $timeParts = [];
        if ($hasTx && !empty($txCols['created_at'])) $timeParts[] = 't.created_at';
        if (!empty($keyCols['sold_at'])) $timeParts[] = 'k.sold_at';
        $time = $timeParts ? 'COALESCE(' . implode(', ', $timeParts) . ')' : "''";

        $row = commerceContextQueryFirst(
            "SELECT k.id AS key_record_id,
                    'user' AS owner_kind,
                    {$ownerExpr} AS owner_user_id,
                    {$username} AS owner_username,
                    {$email} AS owner_email,
                    {$role} AS owner_role,
                    {$username} AS owner_display,
                    {$productName} AS product_name,
                    {$duration} AS product_duration,
                    {$transactionId} AS transaction_id,
                    0 AS order_id,
                    {$time} AS purchased_at,
                    '' AS external_ref
             FROM `keys` k
             {$txJoin}
             {$userJoin}
             {$productJoin}
             {$variantJoin}
             WHERE {$where}
             ORDER BY k.id DESC LIMIT 1",
            $types,
            $params
        );
        return $row ? commerceContextFinalize($row, 'local') : null;
    }
}

if (!function_exists('commerceResolveCgoContext')) {
    function commerceResolveCgoContext(string $recordId, string $fullKey, string $keyHash, string $source = ''): ?array
    {
        $keyCols = commerceContextTableColumns('cgo_order_keys');
        $orderCols = commerceContextTableColumns('cgo_orders');
        if (!commerceContextHasColumns($keyCols, ['id', 'order_id', 'key_code'])
            || !commerceContextHasColumns($orderCols, ['id', 'user_id', 'status'])) return null;
        [$where, $types, $params] = commerceContextWhere('ok', $keyCols, $source, $recordId, $fullKey, $keyHash);

        $userCols = commerceContextTableColumns('users');
        $userJoin = commerceContextHasColumns($userCols, ['id', 'username']) ? 'LEFT JOIN users u ON u.id = o.user_id' : '';
        $username = $userJoin !== '' ? "COALESCE(u.username, '')" : "''";
        $email = $userJoin !== '' && !empty($userCols['email']) ? "COALESCE(u.email, '')" : "''";
        $role = $userJoin !== '' && !empty($userCols['role']) ? "COALESCE(u.role, '')" : "''";

        $localProductCols = commerceContextTableColumns('products');
        $localProductJoin = !empty($orderCols['local_product_id']) && commerceContextHasColumns($localProductCols, ['id', 'name'])
            ? 'LEFT JOIN products lp ON lp.id = o.local_product_id' : '';
        $cgoProductCols = commerceContextTableColumns('cgo_products');
        $cgoProductJoin = !empty($orderCols['cgo_product_id']) && commerceContextHasColumns($cgoProductCols, ['id', 'name'])
            ? 'LEFT JOIN cgo_products cp ON cp.id = o.cgo_product_id' : '';
        $nameParts = [];
        if ($localProductJoin !== '') $nameParts[] = "NULLIF(lp.name, '')";
        if ($cgoProductJoin !== '') {
            if (!empty($cgoProductCols['brand'])) {
                $nameParts[] = "NULLIF(CASE WHEN COALESCE(NULLIF(cp.brand, ''), '') <> '' THEN CONCAT(cp.brand, ' - ', cp.name) ELSE cp.name END, '')";
            } else $nameParts[] = "NULLIF(cp.name, '')";
        }
        $productName = $nameParts ? 'COALESCE(' . implode(', ', $nameParts) . ", '')" : "''";

        $variantCols = commerceContextTableColumns('product_variants');
        $variantJoin = !empty($orderCols['local_variant_id']) && commerceContextHasColumns($variantCols, ['id', 'duration'])
            ? 'LEFT JOIN product_variants pv ON pv.id = o.local_variant_id' : '';
        $durationParts = [];
        if ($variantJoin !== '') $durationParts[] = "NULLIF(pv.duration, '')";
        if ($cgoProductJoin !== '' && !empty($cgoProductCols['duration'])) $durationParts[] = "NULLIF(cp.duration, '')";
        $duration = $durationParts ? 'COALESCE(' . implode(', ', $durationParts) . ", '')" : "''";

        $transactionId = !empty($orderCols['transaction_id']) ? 'COALESCE(o.transaction_id, 0)' : '0';
        $timeParts = [];
        foreach (['completed_at', 'created_at'] as $column) if (!empty($orderCols[$column])) $timeParts[] = 'o.' . $column;
        if (!empty($keyCols['created_at'])) $timeParts[] = 'ok.created_at';
        $time = $timeParts ? 'COALESCE(' . implode(', ', $timeParts) . ')' : "''";
        $externalRef = !empty($orderCols['external_ref']) ? "COALESCE(o.external_ref, '')" : "''";

        $row = commerceContextQueryFirst(
            "SELECT ok.id AS key_record_id,
                    'user' AS owner_kind,
                    o.user_id AS owner_user_id,
                    {$username} AS owner_username,
                    {$email} AS owner_email,
                    {$role} AS owner_role,
                    {$username} AS owner_display,
                    {$productName} AS product_name,
                    {$duration} AS product_duration,
                    {$transactionId} AS transaction_id,
                    o.id AS order_id,
                    {$time} AS purchased_at,
                    {$externalRef} AS external_ref
             FROM cgo_order_keys ok
             JOIN cgo_orders o ON o.id = ok.order_id
             {$userJoin}
             {$localProductJoin}
             {$cgoProductJoin}
             {$variantJoin}
             WHERE {$where}
               AND " . commerceSuccessfulStatusSql('o') . "
             ORDER BY o.id DESC LIMIT 1",
            $types,
            $params
        );
        return $row ? commerceContextFinalize($row, 'cgo') : null;
    }
}

if (!function_exists('commerceResolveSupplierContext')) {
    function commerceResolveSupplierContext(string $recordId, string $fullKey, string $keyHash, string $source = ''): ?array
    {
        $keyCols = commerceContextTableColumns('supplier_order_keys');
        $orderCols = commerceContextTableColumns('supplier_orders');
        if (!commerceContextHasColumns($keyCols, ['id', 'order_id', 'key_code'])
            || !commerceContextHasColumns($orderCols, ['id', 'user_id', 'status'])) return null;
        [$where, $types, $params] = commerceContextWhere('ok', $keyCols, $source, $recordId, $fullKey, $keyHash);

        $userCols = commerceContextTableColumns('users');
        $userJoin = commerceContextHasColumns($userCols, ['id', 'username']) ? 'LEFT JOIN users u ON u.id = o.user_id' : '';
        $username = $userJoin !== '' ? "COALESCE(u.username, '')" : "''";
        $email = $userJoin !== '' && !empty($userCols['email']) ? "COALESCE(u.email, '')" : "''";
        $role = $userJoin !== '' && !empty($userCols['role']) ? "COALESCE(u.role, '')" : "''";

        $productCols = commerceContextTableColumns('products');
        $productJoin = !empty($orderCols['local_product_id']) && commerceContextHasColumns($productCols, ['id', 'name'])
            ? 'LEFT JOIN products p ON p.id = o.local_product_id' : '';
        $supplierProductCols = commerceContextTableColumns('supplier_products');
        $supplierProductJoin = !empty($orderCols['supplier_product_id']) && commerceContextHasColumns($supplierProductCols, ['id', 'name'])
            ? 'LEFT JOIN supplier_products sp ON sp.id = o.supplier_product_id' : '';
        $nameParts = [];
        if ($productJoin !== '') $nameParts[] = "NULLIF(p.name, '')";
        if ($supplierProductJoin !== '') $nameParts[] = "NULLIF(sp.name, '')";
        $productName = $nameParts ? 'COALESCE(' . implode(', ', $nameParts) . ", '')" : "''";

        $variantCols = commerceContextTableColumns('product_variants');
        $variantJoin = !empty($orderCols['local_variant_id']) && commerceContextHasColumns($variantCols, ['id', 'duration'])
            ? 'LEFT JOIN product_variants pv ON pv.id = o.local_variant_id' : '';
        $durationParts = [];
        if ($variantJoin !== '') $durationParts[] = "NULLIF(pv.duration, '')";
        if ($supplierProductJoin !== '' && !empty($supplierProductCols['duration'])) $durationParts[] = "NULLIF(sp.duration, '')";
        $duration = $durationParts ? 'COALESCE(' . implode(', ', $durationParts) . ", '')" : "''";

        $transactionId = !empty($orderCols['transaction_id']) ? 'COALESCE(o.transaction_id, 0)' : '0';
        $timeParts = [];
        foreach (['completed_at', 'created_at'] as $column) if (!empty($orderCols[$column])) $timeParts[] = 'o.' . $column;
        if (!empty($keyCols['created_at'])) $timeParts[] = 'ok.created_at';
        $time = $timeParts ? 'COALESCE(' . implode(', ', $timeParts) . ')' : "''";
        $externalRef = !empty($orderCols['external_ref']) ? "COALESCE(o.external_ref, '')" : "''";

        $row = commerceContextQueryFirst(
            "SELECT ok.id AS key_record_id,
                    'user' AS owner_kind,
                    o.user_id AS owner_user_id,
                    {$username} AS owner_username,
                    {$email} AS owner_email,
                    {$role} AS owner_role,
                    {$username} AS owner_display,
                    {$productName} AS product_name,
                    {$duration} AS product_duration,
                    {$transactionId} AS transaction_id,
                    o.id AS order_id,
                    {$time} AS purchased_at,
                    {$externalRef} AS external_ref
             FROM supplier_order_keys ok
             JOIN supplier_orders o ON o.id = ok.order_id
             {$userJoin}
             {$productJoin}
             {$supplierProductJoin}
             {$variantJoin}
             WHERE {$where}
               AND " . commerceSuccessfulStatusSql('o') . "
             ORDER BY o.id DESC LIMIT 1",
            $types,
            $params
        );
        return $row ? commerceContextFinalize($row, 'supplier') : null;
    }
}


if (!function_exists('commerceResolveCentralContext')) {
    function commerceResolveCentralContext(string $fullKey, string $keyHash): ?array
    {
        if (!function_exists('commerceCenterResolveKeyHash')) return null;
        if ($keyHash === '' && $fullKey !== '') $keyHash = hash('sha256', trim($fullKey));
        if (!preg_match('/^[a-f0-9]{64}$/D', $keyHash)) return null;
        $row = commerceCenterResolveKeyHash($keyHash);
        if (!$row) return null;
        if ((int) ($row['central_match_count'] ?? 1) > 1) {
            $conflict = commerceContextEmpty();
            $conflict['conflict'] = true;
            $conflict['match_count'] = (int) $row['central_match_count'];
            return $conflict;
        }

        $siteId = trim((string) ($row['delivered_to_site_id'] ?? $row['origin_site_id'] ?? ''));
        $externalUserId = trim((string) ($row['delivered_to_user_id'] ?? $row['origin_user_id'] ?? ''));
        $localUserId = max(0, (int) ($row['local_user_id'] ?? 0));
        $ownerKind = $localUserId > 0 ? 'user' : ($siteId !== '' && $externalUserId !== '' ? 'external_user' : 'api_client');
        $ownerDisplay = trim((string) ($row['delivered_name_snapshot'] ?? $row['buyer_name_snapshot'] ?? ''));
        if ($ownerDisplay === '' && $siteId !== '' && $externalUserId !== '') $ownerDisplay = $siteId . ':user:' . $externalUserId;
        if ($ownerDisplay === '' && trim((string) ($row['commercial_buyer_id'] ?? '')) !== '') {
            $ownerDisplay = trim((string) ($row['commercial_buyer_type'] ?? 'buyer')) . '#' . trim((string) $row['commercial_buyer_id']);
        }
        $sourceMap = [
            'local_purchase' => 'local',
            'cgo_purchase' => 'cgo',
            'supplier_purchase' => 'supplier',
            'store_api_sale' => 'store_api_client',
        ];
        $sourceType = trim((string) ($row['order_source_type'] ?? ''));
        $source = $sourceMap[$sourceType] ?? $sourceType;
        return commerceContextFinalize([
            'key_record_id' => ctype_digit((string) ($row['source_key_record_id'] ?? '')) ? (int) $row['source_key_record_id'] : 0,
            'source_key_id' => ctype_digit((string) ($row['source_inventory_key_id'] ?? '')) ? (int) $row['source_inventory_key_id'] : 0,
            'owner_kind' => $ownerKind,
            'owner_user_id' => $localUserId,
            'owner_username' => $ownerDisplay,
            'owner_email' => (string) ($row['delivered_email_snapshot'] ?? $row['buyer_email_snapshot'] ?? ''),
            'owner_role' => $ownerKind,
            'owner_display' => $ownerDisplay,
            'product_name' => (string) ($row['product_name_snapshot'] ?? ''),
            'product_duration' => (string) ($row['duration_snapshot'] ?? ''),
            'transaction_id' => (int) ($row['transaction_id'] ?? 0),
            'order_id' => ctype_digit((string) ($row['source_record_id'] ?? '')) ? (int) $row['source_record_id'] : 0,
            'purchased_at' => (string) ($row['delivered_at'] ?? $row['source_completed_at'] ?? $row['source_created_at'] ?? ''),
            'external_ref' => (string) ($row['external_ref'] ?? ''),
            'order_uuid' => (string) ($row['order_uuid'] ?? ''),
            'owner_site_id' => $siteId,
            'owner_external_user_id' => $externalUserId,
            'customer_ref' => (string) ($row['customer_ref'] ?? $row['order_customer_ref'] ?? ''),
            'ownership_status' => (string) ($row['ownership_status'] ?? ''),
            'total_price' => (float) ($row['total'] ?? 0),
            'currency' => (string) ($row['currency'] ?? ''),
        ], $source);
    }
}

if (!function_exists('commerceResolveKeyContext')) {
    /**
     * Resolve one key to a unified owner/order/product context.
     *
     * No business row is changed. Callers may snapshot the returned fields in
     * their own audit/event table when historical stability is required.
     */
    function commerceResolveKeyContext(array $input): array
    {
        static $cache = [];
        $recordIdRaw = trim((string) ($input['key_record_id'] ?? $input['record_id'] ?? ''));
        $source = commerceNormalizeSource(
            (string) ($input['key_source'] ?? $input['source'] ?? ''),
            $recordIdRaw
        );
        $recordId = $recordIdRaw;
        foreach (['cgo-' => 'cgo', 'supplier-' => 'supplier', 'store-api-' => 'store_api_client'] as $prefix => $prefixSource) {
            if (strpos(strtolower($recordId), $prefix) === 0) {
                $recordId = substr($recordId, strlen($prefix));
                if ($source === '') $source = $prefixSource;
                break;
            }
        }

        $fullKey = trim((string) ($input['full_key'] ?? $input['key_code'] ?? ''));
        $keyHash = strtolower(trim((string) ($input['key_hash'] ?? '')));
        if ($keyHash === '' && $fullKey !== '') $keyHash = hash('sha256', $fullKey);
        if (!preg_match('/^[a-f0-9]{64}$/D', $keyHash)) $keyHash = '';

        $cacheKey = implode('|', [$source, $recordId, $keyHash, hash('sha256', $fullKey)]);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        $resolvers = [
            'local' => 'commerceResolveLocalContext',
            'cgo' => 'commerceResolveCgoContext',
            'supplier' => 'commerceResolveSupplierContext',
            'store_api_client' => 'commerceResolveStoreApiContext',
        ];

        // Preserve explicit legacy source lookups because reset/history callers may
        // pass a concrete record ID. Merge the central snapshot only when it refers
        // to the same source, preventing a provider delivery from hijacking an
        // explicitly requested local-key lookup.
        if ($source !== '' && isset($resolvers[$source])) {
            $resolved = $resolvers[$source]($recordId, $fullKey, $keyHash, $source);
            if (is_array($resolved) && !empty($resolved['resolved'])) {
                $central = commerceResolveCentralContext($fullKey, $keyHash);
                if (is_array($central) && !empty($central['resolved']) && (string) ($central['source'] ?? '') === $source) {
                    $resolved = array_merge($resolved, $central);
                }
                return $cache[$cacheKey] = $resolved;
            }
            return $cache[$cacheKey] = commerceContextEmpty();
        }

        // With no explicit source, prefer the stable central delivery snapshot and
        // fall back to legacy source tables while historic rows are backfilled.
        $central = commerceResolveCentralContext($fullKey, $keyHash);
        if (is_array($central) && (!empty($central['resolved']) || !empty($central['conflict']))) {
            return $cache[$cacheKey] = $central;
        }

        $matches = [];
        foreach (['local', 'cgo', 'supplier', 'store_api_client'] as $candidate) {
            $resolved = $resolvers[$candidate]('', $fullKey, $keyHash, '');
            if (is_array($resolved) && !empty($resolved['resolved'])) $matches[$candidate] = $resolved;
        }
        if (count($matches) === 1) return $cache[$cacheKey] = reset($matches);

        // A Store API provider order contains a copy of a local source key. That
        // is a known relationship, not a random duplicate: the API client is the
        // commercial owner after the provider order succeeds.
        if (isset($matches['local'], $matches['store_api_client'])) {
            $localId = (int) ($matches['local']['key_record_id'] ?? 0);
            $sourceKeyId = (int) ($matches['store_api_client']['source_key_id'] ?? 0);
            if ($localId > 0 && $sourceKeyId === $localId && count($matches) === 2) {
                return $cache[$cacheKey] = $matches['store_api_client'];
            }
        }

        if ($matches !== []) {
            $conflict = commerceContextEmpty();
            $conflict['conflict'] = true;
            $conflict['match_count'] = count($matches);
            $conflict['matches'] = array_map(static function (array $match): array {
                return [
                    'source' => (string) ($match['source'] ?? ''),
                    'key_record_id' => (int) ($match['key_record_id'] ?? 0),
                    'owner_display' => (string) ($match['owner_display'] ?? ''),
                    'order_id' => (int) ($match['order_id'] ?? 0),
                    'transaction_id' => (int) ($match['transaction_id'] ?? 0),
                ];
            }, array_values($matches));
            return $cache[$cacheKey] = $conflict;
        }
        return $cache[$cacheKey] = commerceContextEmpty();
    }
}

if (!function_exists('commerceResolveKeyContextsBatch')) {
    /**
     * Request-scoped batch facade. It deduplicates identical lookups and uses the
     * resolver cache, allowing list pages to pre-resolve once and reuse results.
     * Source-specific SQL remains isolated so an optional table cannot break the
     * other sources.
     *
     * @param array<int|string,array> $inputs
     * @return array<int|string,array>
     */
    function commerceResolveKeyContextsBatch(array $inputs): array
    {
        $results = [];
        $deduplicated = [];
        foreach ($inputs as $index => $input) {
            if (!is_array($input)) {
                $results[$index] = commerceContextEmpty();
                continue;
            }
            $fingerprint = hash('sha256', json_encode([
                (string) ($input['key_source'] ?? $input['source'] ?? ''),
                (string) ($input['key_record_id'] ?? $input['record_id'] ?? ''),
                (string) ($input['key_hash'] ?? ''),
                hash('sha256', (string) ($input['full_key'] ?? $input['key_code'] ?? '')),
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            if (!array_key_exists($fingerprint, $deduplicated)) {
                $deduplicated[$fingerprint] = commerceResolveKeyContext($input);
            }
            $results[$index] = $deduplicated[$fingerprint];
        }
        return $results;
    }
}

