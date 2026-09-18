<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cheatgame.php';
require_once __DIR__ . '/includes/automation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

requireLogin();
if (!isUser() && !isReseller() && !isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'Access denied'], 403);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET') {
    // Same-origin token renewal keeps long-open storefront tabs working after
    // the normal CSRF lifetime without exposing credentials or supplier data.
    if ((string) ($_GET['action'] ?? '') !== 'token') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    jsonResponse(['success' => true, 'csrf_token' => getCsrfToken()], 200);
}
if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireCsrfToken();
$scope = isset($_POST['scope']) && is_string($_POST['scope'])
    ? strtolower(trim($_POST['scope']))
    : '';
$isAdminRequest = isAdmin();
$storefrontSnapshotRequest = $scope === 'storefront';
$storefrontRefreshRequest = $scope === 'storefront_refresh';
$adminDiagnosticsRequest = $isAdminRequest && !$storefrontSnapshotRequest && !$storefrontRefreshRequest;
if (!$adminDiagnosticsRequest && !$storefrontSnapshotRequest && !$storefrontRefreshRequest) {
    jsonResponse(['success' => false, 'message' => 'Invalid inventory scope'], 400);
}

$role = isReseller() ? 'reseller' : 'user';
$userId = (int) ($_SESSION['user_id'] ?? 0);
// The private hosting cron is authoritative. Customer traffic only wakes a
// bounded fallback when the cron heartbeat has actually disappeared.
$cronHealthy = automationCronIsHealthy(180);

$normalizeIds = static function ($raw, int $limit = 300): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $raw = $decoded;
    }
    if (!is_array($raw)) return [];
    $ids = [];
    foreach ($raw as $id) {
        if (!is_scalar($id)) continue;
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
        if (count($ids) >= $limit) break;
    }
    return array_values($ids);
};

$variantIds = $adminDiagnosticsRequest ? [] : $normalizeIds($_POST['variant_ids'] ?? []);
$directProductIds = $adminDiagnosticsRequest ? [] : $normalizeIds($_POST['product_ids'] ?? []);

$directSnapshot = static function (array $ids): array {
    if ($ids === []) return [];
    $allowed = array_fill_keys(array_map('strval', $ids), true);
    $rows = cgoGetEnabledInventorySnapshot();
    $snapshot = [];
    foreach ($rows as $id => $row) {
        if (isset($allowed[(string) $id])) $snapshot[(string) $id] = $row;
    }
    return $snapshot;
};

$storefrontPayload = static function (bool $catalogChanged = false) use (
    $variantIds,
    $directProductIds,
    $role,
    $userId,
    $directSnapshot,
    $cronHealthy
): array {
    $maintenanceDue = cgoGetStaleStorefrontInventoryProductIds(
            $variantIds,
            $directProductIds,
            cgoInventoryCacheTtlSeconds(),
            1
        ) !== []
        || !cgoInventoryCacheIsFresh(cgoInventoryCacheTtlSeconds())
        || supplierBridgeStorefrontInventoryRefreshNeeded(
            $variantIds,
            supplierBridgeInventoryCacheTtlSeconds()
        )
        || automationJobIsDue('pending_orders')
        || automationJobIsDue('cgo_inventory')
        || automationJobIsDue('cgo_catalog')
        || automationJobIsDue('supplier_catalog');
    return [
        'success' => true,
        'snapshot' => cgoGetStorefrontInventorySnapshot($variantIds, $role, $userId),
        'direct_snapshot' => $directSnapshot($directProductIds),
        'catalog_changed' => $catalogChanged,
        // A healthy cron owns supplier refreshes. Browsers keep reading the
        // cached snapshot but do not create supplier traffic of their own.
        'refresh_needed' => !$cronHealthy && $maintenanceDue,
        'maintenance_due' => $maintenanceDue,
        'background_runner_healthy' => $cronHealthy,
        'ttl_seconds' => cgoInventoryCacheTtlSeconds(),
    ];
};

// Release PHP's per-session file lock before any supplier network request. A
// slow provider must not freeze the customer's other tabs or checkout request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ignore_user_abort(true);

if ($storefrontSnapshotRequest) {
    // The first request is intentionally database-only and returns immediately.
    // A second fire-and-forget request performs due maintenance on hosting where
    // cron or fastcgi_finish_request is unreliable.
    $payload = $storefrontPayload(false);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) $json = '{"success":false,"message":"Unable to prepare inventory"}';
    http_response_code(200);
    echo $json;

    if (!empty($payload['refresh_needed']) && function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        automationRunStorefrontInventoryMaintenance('web_fallback', 12);
    }
    exit;
}

if ($storefrontRefreshRequest) {
    // Do not let a customer-triggered refresh duplicate healthy cron work.
    if ($cronHealthy) {
        jsonResponse($storefrontPayload(false), 200);
    }

    // Refresh only the Bridge sources that back variants currently rendered on
    // this page. This makes failover visible within one poll cycle without
    // forcing every customer to download the full supplier catalogue.
    $supplierTargetRefresh = supplierBridgeRefreshStorefrontInventory(
        $variantIds,
        supplierBridgeInventoryCacheTtlSeconds(),
        2
    );

    $staleProductIds = cgoGetStaleStorefrontInventoryProductIds(
        $variantIds,
        $directProductIds,
        cgoInventoryCacheTtlSeconds(),
        3
    );
    $targetRefresh = null;
    if ($staleProductIds !== []) {
        // One exact product requirement is enough to prevent a globally fresh but
        // partially omitted catalogue row from being ignored. The merged refresh
        // updates every product returned by the supplier in the same request.
        $targetRefresh = cgoRefreshRemoteInventory(
            false,
            2,
            cgoInventoryCacheTtlSeconds(),
            (int) $staleProductIds[0]
        );
    }
    $maintenance = automationRunStorefrontInventoryMaintenance(
        'storefront_kick',
        12,
        is_array($targetRefresh) && !empty($targetRefresh['catalog_refresh_needed'])
    );
    $payload = $storefrontPayload(!empty($maintenance['catalog_changed']));
    $payload['target_refresh_completed'] = is_array($targetRefresh) ? !empty($targetRefresh['success']) : null;
    $payload['supplier_target_refresh'] = [
        'attempted' => (int) ($supplierTargetRefresh['attempted'] ?? 0),
        'succeeded' => (int) ($supplierTargetRefresh['succeeded'] ?? 0),
        'failed' => (int) ($supplierTargetRefresh['failed'] ?? 0),
        'skipped_busy' => (int) ($supplierTargetRefresh['skipped_busy'] ?? 0),
        'skipped_fresh' => (int) ($supplierTargetRefresh['skipped_fresh'] ?? 0),
        'remaining_stale' => (int) ($supplierTargetRefresh['remaining_stale'] ?? 0),
    ];
    $payload['maintenance_completed'] = !empty($maintenance['success']);
    $payload['retry_after'] = !empty($payload['refresh_needed']) ? 3 : 0;
    jsonResponse($payload, 200);
}

// Administrator diagnostics may wait for a direct refresh and receive only
// operational counters. Raw provider responses and credentials stay server-side.
$supplierResult = supplierBridgeRefreshAllEnabled(false);
$result = cgoRefreshRemoteInventory(false, 2, cgoInventoryCacheTtlSeconds());
$supplierSucceeded = (int) ($supplierResult['succeeded'] ?? 0) > 0;
$cgoSucceeded = !empty($result['success']);
if ($cgoSucceeded || $supplierSucceeded) {
    $snapshot = [];
    $rows = $conn->query("SELECT id, remote_stock, remote_status, inventory_checked_at FROM cgo_products WHERE supplier_removed_at IS NULL");
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) continue;
            $stock = $row['remote_stock'] === null ? null : max(0, (int) $row['remote_stock']);
            $status = strtolower(trim((string) ($row['remote_status'] ?? '')));
            $snapshot[(string) $id] = [
                'stock' => $stock,
                'status' => $status,
                'available' => $stock !== null && $stock > 0,
                'checked_at' => $row['inventory_checked_at'] ?? null,
            ];
        }
        $rows->free();
    }
    $state = cgoInventoryState(true);
    jsonResponse([
        'success' => true,
        'cached' => !empty($result['cached']),
        'updated' => (int) ($result['updated'] ?? 0),
        'missing' => (int) ($result['missing'] ?? 0),
        'invalid' => (int) ($result['invalid'] ?? 0),
        'attempts' => (int) ($result['attempts'] ?? 0),
        'partial' => !empty($result['partial']),
        'coverage_percent' => $result['coverage_percent'] ?? null,
        'catalog_refresh_needed' => !empty($result['catalog_refresh_needed']),
        'unknown_products' => (int) ($result['unknown_products'] ?? 0),
        'supplier_bridge' => [
            'attempted' => (int) ($supplierResult['attempted'] ?? 0),
            'succeeded' => (int) ($supplierResult['succeeded'] ?? 0),
            'updated' => (int) ($supplierResult['updated'] ?? 0),
            'published' => (int) ($supplierResult['published'] ?? 0),
            'failed' => (int) ($supplierResult['failed'] ?? 0),
        ],
        'snapshot' => $snapshot,
        'ttl_seconds' => cgoInventoryCacheTtlSeconds(),
        'state' => [
            'last_success_at' => $state['last_success_at'] ?? null,
            'age_seconds' => $state['age_seconds'] ?? null,
            'last_error' => $state['last_error'] ?? '',
        ],
    ], 200);
}

if (!empty($result['busy']) && !$supplierSucceeded) {
    $retryAfter = max(1, min(5, (int) ($result['retry_after'] ?? 2)));
    header('Retry-After: ' . $retryAfter);
    jsonResponse([
        'success' => false,
        'busy' => true,
        'retry_after' => $retryAfter,
        'message' => 'Inventory refresh is already running',
    ], 202);
}

$state = cgoInventoryState(true);
jsonResponse([
    'success' => false,
    'message' => 'No configured supplier inventory could be refreshed',
    'failure_class' => $result['failure_class'] ?? '',
    'supplier_bridge' => [
        'attempted' => (int) ($supplierResult['attempted'] ?? 0),
        'succeeded' => (int) ($supplierResult['succeeded'] ?? 0),
        'updated' => (int) ($supplierResult['updated'] ?? 0),
        'failed' => (int) ($supplierResult['failed'] ?? 0),
    ],
    'state' => [
        'last_success_at' => $state['last_success_at'] ?? null,
        'age_seconds' => $state['age_seconds'] ?? null,
        'last_error' => $state['last_error'] ?? '',
    ],
], 503);
