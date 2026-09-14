<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';
requireAdmin();

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string {
    return $isTh ? $th : $en;
};
$h = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$getInt = static function (string $key): int {
    return isset($_GET[$key]) ? max(0, (int) $_GET[$key]) : 0;
};
$getString = static function (string $key, string $default = ''): string {
    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
};

if (!function_exists('supplierProductsAdminSafeQuery')) {
    function supplierProductsAdminSafeQuery($raw): string
    {
        if (!is_string($raw) || $raw === '') return '';
        $parsed = [];
        parse_str($raw, $parsed);
        $allowed = ['connection_id', 'state', 'category', 'q'];
        $clean = [];
        foreach ($allowed as $key) {
            if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
            $value = trim((string) $parsed[$key]);
            if ($value === '') continue;
            if ($key === 'connection_id') {
                $id = max(0, (int) $value);
                if ($id > 0) $clean[$key] = $id;
                continue;
            }
            $maxLength = $key === 'category' ? 255 : 190;
            $clean[$key] = function_exists('mb_substr')
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : substr($value, 0, $maxLength);
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('supplierProductsAdminRedirect')) {
    function supplierProductsAdminRedirect(
        int $connectionId,
        string $message = '',
        string $error = '',
        string $returnQuery = ''
    ): void {
        $_SESSION['supplier_products_admin_flash'] = ['message' => $message, 'error' => $error];
        $returnQuery = supplierProductsAdminSafeQuery($returnQuery);
        if ($returnQuery === '' && $connectionId > 0) {
            $returnQuery = http_build_query(['connection_id' => $connectionId], '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: api_products.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''), true, 303);
        exit;
    }
}

$message = '';
$error = '';
if (isset($_SESSION['supplier_products_admin_flash']) && is_array($_SESSION['supplier_products_admin_flash'])) {
    $flash = $_SESSION['supplier_products_admin_flash'];
    unset($_SESSION['supplier_products_admin_flash']);
    $message = is_string($flash['message'] ?? null) ? $flash['message'] : '';
    $error = is_string($flash['error'] ?? null) ? $flash['error'] : '';
}

$connectionId = $getInt('connection_id');
$state = $getString('state', 'all');
$category = $getString('category');
$search = $getString('q');

$allowedStates = [
    'all', 'in_stock', 'out_of_stock', 'mapped', 'unmapped',
    'enabled', 'disabled', 'removed',
];
if (!in_array($state, $allowedStates, true)) $state = 'all';

$currentReturnQuery = http_build_query(array_filter([
    'connection_id' => $connectionId > 0 ? $connectionId : null,
    'state' => $state !== 'all' ? $state : null,
    'category' => $category !== '' ? $category : null,
    'q' => $search !== '' ? $search : null,
], static fn($value): bool => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';
    $connectionId = max(0, (int) ($_POST['connection_id'] ?? $connectionId));
    $supplierProductId = max(0, (int) ($_POST['supplier_product_id'] ?? 0));
    $adminId = (int) ($_SESSION['user_id'] ?? 0);
    $returnQuery = supplierProductsAdminSafeQuery($_POST['return_query'] ?? '');

    if ($action === 'save_category_mapping') {
        $remoteCategory = isset($_POST['remote_category']) && is_scalar($_POST['remote_category'])
            ? trim((string) $_POST['remote_category']) : '';
        $localCategories = isset($_POST['local_categories']) && is_scalar($_POST['local_categories'])
            ? (string) $_POST['local_categories'] : '';
        $result = supplierBridgeSaveCategoryMapping(
            $connectionId,
            $remoteCategory,
            $localCategories,
            isset($_POST['mapping_enabled']),
            isset($_POST['apply_existing'])
        );
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('บันทึกการแมปหมวดหมู่ไม่สำเร็จ', 'Unable to save category mapping')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_category_mapping', 'Supplier #' . $connectionId . ' category=' . $remoteCategory . '; updated=' . (int) ($result['updated'] ?? 0));
        supplierProductsAdminRedirect(
            $connectionId,
            $t('บันทึกหมวดหมู่แล้ว อัปเดตสินค้าหลัก ', 'Category mapping saved. Storefront products updated: ') . (int) ($result['updated'] ?? 0),
            '',
            $returnQuery
        );
    }

    if ($action === 'delete_category_mapping') {
        $mappingId = max(0, (int) ($_POST['mapping_id'] ?? 0));
        if (!supplierBridgeDeleteCategoryMapping($mappingId, $connectionId)) {
            supplierProductsAdminRedirect($connectionId, '', $t('ลบการแมปหมวดหมู่ไม่สำเร็จ', 'Unable to delete category mapping'), $returnQuery);
        }
        $apply = supplierBridgeApplyCategoryMappings($connectionId);
        logHistory($adminId, 'supplier_category_mapping_delete', 'Deleted supplier category mapping #' . $mappingId . '; reapplied=' . (int) ($apply['updated'] ?? 0));
        $deleteWarning = empty($apply['success']) ? (string) ($apply['message'] ?? '') : '';
        supplierProductsAdminRedirect($connectionId, $t('ลบการแมปหมวดหมู่แล้ว และคำนวณหมวดหมู่ปัจจุบันใหม่', 'Category mapping deleted and current storefront categories recalculated.'), $deleteWarning, $returnQuery);
    }

    if ($action === 'apply_category_mappings') {
        $result = supplierBridgeApplyCategoryMappings($connectionId);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect($connectionId, '', (string) ($result['message'] ?? $t('อัปเดตหมวดหมู่ไม่ครบ', 'Category refresh was incomplete')), $returnQuery);
        }
        logHistory($adminId, 'supplier_category_mapping_apply', 'Supplier #' . $connectionId . ' updated=' . (int) ($result['updated'] ?? 0));
        supplierProductsAdminRedirect(
            $connectionId,
            $t('อัปเดตหมวดหมู่สินค้าหลักแล้ว ', 'Storefront categories updated: ') . (int) ($result['updated'] ?? 0),
            '',
            $returnQuery
        );
    }

    if ($action === 'create_api_only_product') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $categoriesInput = isset($_POST['local_categories']) && is_scalar($_POST['local_categories'])
            ? (string) $_POST['local_categories'] : '';
        $sourcePriority = max(-100000, min(100000, (int) ($_POST['source_priority'] ?? 100)));
        $result = supplierBridgeCreateApiOnlyProduct($supplierProductId, $categoriesInput, $sourcePriority);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('สร้างสินค้า API-only ไม่สำเร็จ', 'Unable to create API-only product')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_api_only_create', 'Supplier product #' . $supplierProductId . ' -> local product #' . (int) ($result['local_product_id'] ?? 0));
        $createWarning = (int) ($result['failed'] ?? 0) > 0
            ? $t('มีบางตัวเลือกเผยแพร่ไม่สำเร็จ: ', 'Some variants could not be published: ') . (int) ($result['failed'] ?? 0)
            : '';
        supplierProductsAdminRedirect(
            $connectionId,
            $t('สร้างสินค้า API-only และตัวเลือกจาก API แล้ว ', 'API-only storefront product created. Variants published: ') . (int) ($result['published'] ?? 0),
            $createWarning,
            $returnQuery
        );
    }

    if ($action === 'bulk_publish') {
        $ids = isset($_POST['supplier_product_ids']) && is_array($_POST['supplier_product_ids'])
            ? $_POST['supplier_product_ids'] : [];
        $result = supplierBridgePublishSelectedProducts($ids, true);
        if (empty($result['success']) && (int) ($result['published'] ?? 0) < 1) {
            supplierProductsAdminRedirect($connectionId, '', (string) ($result['message'] ?? $t('เผยแพร่รายการที่เลือกไม่สำเร็จ', 'Unable to publish selected products')), $returnQuery);
        }
        logHistory($adminId, 'supplier_bulk_publish', 'Supplier products published=' . (int) ($result['published'] ?? 0) . '; failed=' . (int) ($result['failed'] ?? 0));
        $warning = (int) ($result['failed'] ?? 0) > 0 ? (string) ($result['message'] ?? '') : '';
        supplierProductsAdminRedirect(
            $connectionId,
            $t('เผยแพร่รายการที่เลือกแล้ว ', 'Selected products published: ') . (int) ($result['published'] ?? 0),
            $warning,
            $returnQuery
        );
    }

    if ($action === 'save_group_categories') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $categoriesInput = isset($_POST['local_categories']) && is_scalar($_POST['local_categories'])
            ? (string) $_POST['local_categories'] : '';
        $useAutomatic = isset($_POST['use_automatic_categories']);
        $result = supplierBridgeSetGroupCategoryOverride($supplierProductId, $categoriesInput, $useAutomatic);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect($connectionId, '', (string) ($result['message'] ?? $t('บันทึกหมวดหมู่สินค้าไม่สำเร็จ', 'Unable to save product categories')), $returnQuery);
        }
        logHistory($adminId, 'supplier_group_categories', 'Supplier product #' . $supplierProductId . '; automatic=' . ($useAutomatic ? '1' : '0'));
        supplierProductsAdminRedirect(
            $connectionId,
            $useAutomatic
                ? $t('กลับมาใช้กฎหมวดหมู่อัตโนมัติแล้ว', 'Automatic category routing restored.')
                : $t('บันทึกหมวดหมู่เฉพาะสินค้านี้แล้ว', 'Product-specific storefront categories saved.'),
            '',
            $returnQuery
        );
    }

    if ($action === 'save_policy') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $result = supplierBridgeUpdateProductPolicy($supplierProductId, [
            'user_price_mode' => $_POST['user_price_mode'] ?? 'connection',
            'reseller_price_mode' => $_POST['reseller_price_mode'] ?? 'connection',
            'user_markup_percent' => $_POST['user_markup_percent'] ?? null,
            'reseller_markup_percent' => $_POST['reseller_markup_percent'] ?? null,
            'user_fixed_price' => $_POST['user_fixed_price'] ?? null,
            'reseller_fixed_price' => $_POST['reseller_fixed_price'] ?? null,
            'enabled' => isset($_POST['enabled']),
        ], true);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('บันทึกกฎราคาไม่สำเร็จ', 'Unable to save pricing policy')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_policy', 'Updated supplier product #' . $supplierProductId);
        supplierProductsAdminRedirect(
            $connectionId,
            $t('บันทึกและคำนวณราคาสินค้าแล้ว', 'Product pricing was saved and recalculated.'),
            '',
            $returnQuery
        );
    }

    if ($action === 'sync_group_variants') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if (!$product) {
            supplierProductsAdminRedirect($connectionId, '', $t('ไม่พบสินค้า API', 'Supplier product was not found'), $returnQuery);
        }
        $connectionId = (int) ($product['connection_id'] ?? $connectionId);
        $sourceRef = trim((string) ($product['remote_source_product_id'] ?? ''));
        $result = supplierBridgeSyncGroupVariants($connectionId, $sourceRef, false);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('ซิงค์รูปแบบสินค้าจากต้นทางไม่สำเร็จ', 'Unable to synchronize variants from the supplier group')),
                $returnQuery
            );
        }
        $createdDurations = isset($result['created_durations']) && is_array($result['created_durations'])
            ? array_values(array_filter(array_map('strval', $result['created_durations']), static fn(string $v): bool => trim($v) !== ''))
            : [];
        $detail = $t('ซิงค์รูปแบบจากต้นทางแล้ว', 'Supplier variants synchronized.');
        $detail .= ' ' . $t('ตรวจทั้งหมด ', 'Checked ') . (int) ($result['supplier_variants'] ?? 0);
        $detail .= $t(' รูปแบบ', ' variants');
        if ($createdDurations !== []) {
            $detail .= $t(' · สร้างใหม่: ', ' · Created: ') . implode(', ', $createdDurations);
        } else {
            $detail .= $t(' · ไม่มีรูปแบบใหม่ที่ต้องสร้าง', ' · No missing local variants');
        }
        logHistory(
            $adminId,
            'supplier_group_variant_sync',
            'Supplier #' . $connectionId . ' group=' . $sourceRef
                . '; local_product=' . (int) ($result['local_product_id'] ?? 0)
                . '; published=' . (int) ($result['published'] ?? 0)
                . '; created=' . (int) ($result['created_variants'] ?? 0)
        );
        supplierProductsAdminRedirect($connectionId, $detail, '', $returnQuery);
    }

    if ($action === 'publish_product') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $result = supplierBridgePublishById($supplierProductId, true);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('เผยแพร่สินค้าไม่สำเร็จ', 'Unable to publish product')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_publish', 'Published supplier product #' . $supplierProductId);
        supplierProductsAdminRedirect(
            $connectionId,
            $t('สร้างหรือรวมสินค้าเข้าหน้าหลักแล้ว', 'Product was published or merged into the storefront.'),
            '',
            $returnQuery
        );
    }

    if ($action === 'map_group') {
        $sourceRef = isset($_POST['source_ref']) && is_scalar($_POST['source_ref'])
            ? trim((string) $_POST['source_ref'])
            : '';
        $localProductId = max(0, (int) ($_POST['local_product_id'] ?? 0));
        $result = supplierBridgeMapGroupToLocalProduct($connectionId, $sourceRef, $localProductId);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('รวมสินค้าไม่สำเร็จ', 'Unable to merge product')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_group_map', 'Mapped supplier group ' . $sourceRef . ' to local product #' . $localProductId);
        $skipped = (int) ($result['skipped_manual'] ?? 0);
        $mapMessage = $t(
            'รวมสินค้าทุกตัวเลือกในกลุ่มเข้ากับสินค้าหลักแล้ว',
            'All supplier variants in the group were merged into the selected local product.'
        );
        if ($skipped > 0) {
            $mapMessage .= ' ' . $t('ข้ามตัวเลือกที่เคยเชื่อมแบบกำหนดเอง ', 'Manually linked variants skipped: ') . $skipped;
        }
        supplierProductsAdminRedirect($connectionId, $mapMessage, '', $returnQuery);
    }

    if ($action === 'link_variant') {
        $localVariantId = max(0, (int) ($_POST['local_variant_id'] ?? 0));
        $sourcePriority = max(-100000, min(100000, (int) ($_POST['source_priority'] ?? 100)));
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $result = supplierBridgeLinkVariantToLocal($supplierProductId, $localVariantId, $sourcePriority);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('เชื่อมตัวเลือกไม่สำเร็จ', 'Unable to link variant')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_variant_link', 'Linked supplier product #' . $supplierProductId . ' to local variant #' . $localVariantId . ' source_priority=' . $sourcePriority);
        supplierProductsAdminRedirect(
            $connectionId,
            $t('เชื่อม API เข้ากับตัวเลือกสินค้าแล้ว ระบบจะใช้ลำดับแหล่งสต็อกใหม่นี้ทันที', 'API product linked to the local variant and the new source priority is active.'),
            '',
            $returnQuery
        );
    }

    if ($action === 'unlink_product') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        if (!supplierBridgeUnlinkProduct($supplierProductId)) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                $t('ยกเลิกการเชื่อมไม่สำเร็จ', 'Unable to unlink product'),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_unlink', 'Unlinked supplier product #' . $supplierProductId);
        supplierProductsAdminRedirect(
            $connectionId,
            $t(
                'ยกเลิกการเชื่อม API กับตัวเลือกในหน้าร้านแล้ว ตัวเลือกเดิมยังไม่ถูกลบ',
                'API mapping removed. The existing local variant was not deleted.'
            ),
            '',
            $returnQuery
        );
    }

    if ($action === 'toggle_product') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        if (!supplierBridgeSetProductEnabled($supplierProductId, $enabled)) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                $t('เปลี่ยนสถานะสินค้าไม่สำเร็จ', 'Unable to change product status'),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_product_status', 'Set supplier product #' . $supplierProductId . ' enabled=' . ($enabled ? '1' : '0'));
        supplierProductsAdminRedirect(
            $connectionId,
            $t('เปลี่ยนสถานะสินค้า API แล้ว', 'API product status updated.'),
            '',
            $returnQuery
        );
    }

    if ($action === 'verify_inventory') {
        $product = supplierBridgeGetSupplierProduct($supplierProductId);
        if ($product) $connectionId = (int) $product['connection_id'];
        $result = supplierBridgeConfirmProductInventory($supplierProductId, 1, true);
        if (empty($result['success'])) {
            $code = trim((string) ($result['error_code'] ?? 'inventory_unavailable'));
            $detail = trim((string) ($result['message'] ?? $t('ยืนยันสต็อกไม่สำเร็จ', 'Unable to verify inventory')));
            logHistory($adminId, 'supplier_inventory_verify_failed', 'Supplier product #' . $supplierProductId . '; code=' . $code . '; message=' . substr($detail, 0, 500));
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                '[' . $code . '] ' . $detail,
                $returnQuery
            );
        }
        $stock = max(0, (int) ($result['stock'] ?? 0));
        $checkType = trim((string) ($result['check_type'] ?? 'exact'));
        logHistory($adminId, 'supplier_inventory_verify', 'Verified supplier product #' . $supplierProductId . '; stock=' . $stock . '; mode=' . $checkType);
        supplierProductsAdminRedirect(
            $connectionId,
            $t('ยืนยันสต็อกจากต้นทางแล้ว: ', 'Supplier inventory verified: ') . $stock . ' (' . $checkType . ')',
            '',
            $returnQuery
        );
    }

    if ($action === 'apply_connection_prices') {
        $result = supplierBridgeApplyConnectionPrices($connectionId);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('ซิงก์ราคาไม่ครบ', 'Price synchronization was incomplete')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_connection_price_apply', 'Applied supplier connection #' . $connectionId . ' prices; updated=' . (int) ($result['updated'] ?? 0));
        supplierProductsAdminRedirect(
            $connectionId,
            $t('คำนวณราคาใหม่แล้ว ', 'Recalculated prices: ') . (int) ($result['updated'] ?? 0) . $t(' ตัวเลือก', ' variants'),
            '',
            $returnQuery
        );
    }

    if ($action === 'publish_connection') {
        $result = supplierBridgePublishConnectionProducts($connectionId);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('เผยแพร่สินค้าไม่ครบ', 'Product publishing was incomplete')),
                $returnQuery
            );
        }
        logHistory($adminId, 'supplier_connection_publish', 'Published supplier connection #' . $connectionId . '; products=' . (int) ($result['published'] ?? 0));
        supplierProductsAdminRedirect(
            $connectionId,
            $t('เผยแพร่หรือรวมสินค้าแล้ว ', 'Published or merged: ') . (int) ($result['published'] ?? 0) . $t(' ตัวเลือก', ' variants'),
            '',
            $returnQuery
        );
    }

    if ($action === 'sync_connection') {
        $result = supplierBridgeRefreshConnection($connectionId, true);
        if (empty($result['success'])) {
            supplierProductsAdminRedirect(
                $connectionId,
                '',
                (string) ($result['message'] ?? $t('ซิงก์ Supplier ไม่สำเร็จ', 'Supplier sync failed')),
                $returnQuery
            );
        }
        $updatedCount = (int) ($result['updated'] ?? 0);
        $failedCount = (int) ($result['failed'] ?? 0);
        $publishFailedCount = (int) ($result['publish_failed'] ?? 0);
        $invalidCount = (int) ($result['invalid'] ?? 0);
        logHistory($adminId, 'supplier_connection_sync_products', 'Synchronized supplier connection #' . $connectionId
            . '; updated=' . $updatedCount . '; failed=' . $failedCount
            . '; publish_failed=' . $publishFailedCount . '; invalid=' . $invalidCount);
        $warning = '';
        if (!empty($result['partial_failure'])) {
            $warning = $t('ซิงก์สำเร็จบางส่วน', 'Partial synchronization')
                . ': failed=' . $failedCount
                . ', publish_failed=' . $publishFailedCount
                . ', invalid=' . $invalidCount;
        }
        supplierProductsAdminRedirect(
            $connectionId,
            $t('ดึงข้อมูลสินค้าและราคาใหม่แล้ว ', 'Supplier catalogue refreshed: ') . $updatedCount . $t(' รายการ', ' items'),
            $warning,
            $returnQuery
        );
    }

    supplierProductsAdminRedirect(
        $connectionId,
        '',
        $t('คำสั่งไม่ถูกต้อง', 'Invalid action'),
        $returnQuery
    );
}

$connections = supplierBridgeGetConnections();
$rows = supplierBridgeGetManagedProducts([
    'connection_id' => $connectionId,
    'state' => $state,
    'category' => $category,
    'search' => $search,
], 1000);
$apiCategories = supplierBridgeGetManagedProductCategories($connectionId);
$stats = supplierBridgeGetManagedProductStats([
    'connection_id' => $connectionId,
    'category' => $category,
    'search' => $search,
]);
$categoryMappings = supplierBridgeGetCategoryMappings($connectionId);

$localCatalog = supplierBridgeGetLocalCatalog();
$localProducts = [];
$localVariants = [];
$localCategories = [];
foreach ($localCatalog as $local) {
    $pid = (int) $local['product_id'];
    $categories = isset($local['categories']) && is_array($local['categories'])
        ? array_values($local['categories'])
        : [];
    foreach ($categories as $localCategory) {
        $localCategory = trim((string) $localCategory);
        if ($localCategory !== '') $localCategories[$localCategory] = true;
    }
    if (!isset($localProducts[$pid])) {
        $localProducts[$pid] = [
            'id' => $pid,
            'name' => (string) $local['product_name'],
            'categories' => $categories,
            'category_text' => implode(' · ', $categories),
            'status' => (string) ($local['product_status'] ?? ''),
        ];
    }
    $localVariants[] = [
        'id' => (int) $local['variant_id'],
        'product_id' => $pid,
        'name' => (string) $local['product_name'],
        'duration' => (string) $local['duration'],
        'categories' => $categories,
        'category_text' => implode(' · ', $categories),
        'status' => (string) ($local['variant_status'] ?? ''),
    ];
}
$localProducts = array_values($localProducts);
$localCategories = array_keys($localCategories);
natcasesort($localCategories);
$localCategories = array_values($localCategories);

$buildFilterUrl = static function (array $overrides = []) use ($connectionId, $state, $category, $search): string {
    $values = array_merge([
        'connection_id' => $connectionId > 0 ? $connectionId : null,
        'state' => $state !== 'all' ? $state : null,
        'category' => $category !== '' ? $category : null,
        'q' => $search !== '' ? $search : null,
    ], $overrides);
    $query = http_build_query(array_filter(
        $values,
        static fn($value): bool => $value !== null && $value !== ''
    ), '', '&', PHP_QUERY_RFC3986);
    return 'api_products.php' . ($query !== '' ? '?' . $query : '');
};

$jsonFlags = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?php echo $h($t('จัดการสินค้า API', 'API Product Manager')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09)}
        .field{width:100%;border-radius:.65rem;border:1px solid rgba(255,255,255,.12);background:#111827;padding:.6rem .75rem;color:#f3f4f6}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;border-radius:.65rem;padding:.6rem .85rem;font-weight:600;transition:.15s}
        .btn-primary{background:#7c3aed;color:#fff}
        .btn-soft{background:rgba(255,255,255,.09)}
        .btn-danger{background:rgba(239,68,68,.2);color:#fecaca}
        .badge{display:inline-flex;border-radius:999px;padding:.2rem .55rem;font-size:.72rem;font-weight:700}
        summary{cursor:pointer}
        details[open] summary{background:rgba(255,255,255,.025)}
        .filter-card{transition:.15s}
        .filter-card:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.18)}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-[1900px] mx-auto p-4 md:p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold">
                <i class="bi bi-box-seam text-sky-300 mr-2"></i><?php echo $h($t('สินค้า ราคา และการรวมหน้าหลัก', 'Supplier products, pricing and storefront mapping')); ?>
            </h1>
            <p class="text-gray-400 mt-2 max-w-5xl">
                <?php echo $h($t(
                    'กรองตามหมวดหมู่และสต็อกได้ทันที ส่วนการรวมสินค้าสามารถค้นหาชื่อหรือเลือกหมวดหมู่ของสินค้าหลักได้ ไม่ต้องเลื่อนรายการยาวจนลืมว่ากำลังหาอะไรอยู่',
                    'Filter supplier items by category and stock. Storefront mapping can search local names or narrow by local category.'
                )); ?>
            </p>
        </div>
        <a class="btn btn-soft" href="api_hub.php">
            <i class="bi bi-arrow-left"></i><?php echo $h($t('กลับศูนย์ API', 'API Hub')); ?>
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-900/20 p-4 text-red-200"><?php echo $h($error); ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-900/20 p-4 text-emerald-200"><?php echo $h($message); ?></div>
    <?php endif; ?>

    <section class="glass rounded-xl p-4 md:p-5 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold flex items-center gap-2">
                    <i class="bi bi-stars text-violet-300"></i><?php echo $h($t('Bridge Catalog Manager v2', 'Bridge Catalog Manager v2')); ?>
                </h2>
                <p class="text-sm text-gray-400 mt-1 max-w-5xl">
                    <?php echo $h($t(
                        'สร้างสินค้าและตัวเลือกจาก API ได้แม้ไม่มีคีย์จริงในตาราง keys โดยสต็อกจะอ่านจาก API โดยตรง หมวดหมู่ต้นทางสามารถแมปเป็นหมวดหมู่หน้าร้านสูงสุด 4 หมวด และกฎนี้จะถูกใช้กับการสร้าง/ซิงก์ครั้งถัดไป',
                        'Create storefront products and variants directly from API stock even with zero local keys. Supplier categories can map to up to four storefront categories and are reused by future publish/sync operations.'
                    )); ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="badge bg-sky-500/15 text-sky-200"><?php echo $h($t('API-only รองรับ', 'API-only ready')); ?></span>
                <span class="badge bg-emerald-500/15 text-emerald-200"><?php echo $h($t('ไม่สร้างคีย์ปลอม', 'No fake keys')); ?></span>
                <span class="badge bg-violet-500/15 text-violet-200"><?php echo $h($t('หมวดสูงสุด 4', 'Up to 4 categories')); ?></span>
            </div>
        </div>

        <?php if ($connectionId > 0): ?>
            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-4">
                <form method="post" class="rounded-xl border border-white/10 bg-black/20 p-4 space-y-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_category_mapping">
                    <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                    <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                    <h3 class="font-semibold"><i class="bi bi-folder-symlink mr-2 text-amber-300"></i><?php echo $h($t('กฎแปลงหมวดหมู่ API → หน้าร้าน', 'API → storefront category rule')); ?></h3>
                    <label class="text-xs text-gray-400 block">
                        <?php echo $h($t('หมวดหมู่จาก API', 'Supplier category')); ?>
                        <select class="field mt-1" name="remote_category" required>
                            <option value=""><?php echo $h($t('เลือกหมวดหมู่ต้นทาง', 'Select supplier category')); ?></option>
                            <?php foreach ($apiCategories as $categoryOption): ?>
                                <option value="<?php echo $h($categoryOption); ?>"><?php echo $h($categoryOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-xs text-gray-400 block">
                        <?php echo $h($t('หมวดหมู่หน้าร้าน สูงสุด 4 หมวด คั่นด้วยจุลภาค', 'Storefront categories, up to 4, comma-separated')); ?>
                        <input class="field mt-1" name="local_categories" maxlength="1100" list="bridge-local-category-list"
                               placeholder="Games / 8 Ball Pool, Keys / 1 Day" required>
                    </label>
                    <datalist id="bridge-local-category-list">
                        <?php foreach ($localCategories as $localCategory): ?><option value="<?php echo $h($localCategory); ?>"><?php endforeach; ?>
                    </datalist>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label><input type="checkbox" name="mapping_enabled" value="1" checked class="mr-1"> <?php echo $h($t('เปิดใช้กฎนี้', 'Enable rule')); ?></label>
                        <label><input type="checkbox" name="apply_existing" value="1" checked class="mr-1"> <?php echo $h($t('อัปเดตสินค้าที่แมปอยู่แล้ว', 'Apply to mapped products now')); ?></label>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึกกฎหมวดหมู่', 'Save category rule')); ?></button>
                </form>

                <div class="rounded-xl border border-white/10 bg-black/20 p-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold"><i class="bi bi-list-nested mr-2 text-sky-300"></i><?php echo $h($t('กฎหมวดหมู่ของ Supplier นี้', 'Category rules for this supplier')); ?></h3>
                        <?php if ($categoryMappings !== []): ?>
                            <form method="post">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="apply_category_mappings">
                                <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                                <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                <button class="btn btn-soft !py-2" type="submit"><i class="bi bi-arrow-repeat"></i><?php echo $h($t('ใช้กฎกับสินค้าปัจจุบัน', 'Apply rules now')); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($categoryMappings === []): ?>
                        <div class="text-sm text-gray-500 rounded-lg border border-dashed border-white/10 p-4"><?php echo $h($t('ยังไม่มีกฎ ระบบจะใช้ชื่อหมวดหมู่จาก API โดยตรง', 'No rules yet. Supplier category names are used as-is.')); ?></div>
                    <?php else: ?>
                        <div class="space-y-2 max-h-72 overflow-auto pr-1">
                            <?php foreach ($categoryMappings as $mapping): ?>
                                <div class="rounded-lg border border-white/10 p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                                    <div class="min-w-0 text-sm">
                                        <div class="font-semibold break-words"><?php echo $h($mapping['remote_category']); ?></div>
                                        <div class="text-gray-400 mt-1 break-words">→ <?php echo $h(implode(' · ', (array) ($mapping['local_categories'] ?? []))); ?></div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="badge <?php echo (int) ($mapping['enabled'] ?? 0) === 1 ? 'bg-emerald-500/15 text-emerald-200' : 'bg-gray-500/15 text-gray-300'; ?>"><?php echo $h((int) ($mapping['enabled'] ?? 0) === 1 ? $t('เปิด', 'Enabled') : $t('ปิด', 'Disabled')); ?></span>
                                        <form method="post" onsubmit="return confirm('<?php echo $h($t('ลบกฎหมวดหมู่นี้หรือไม่?', 'Delete this category rule?')); ?>')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_category_mapping">
                                            <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                                            <input type="hidden" name="mapping_id" value="<?php echo (int) $mapping['id']; ?>">
                                            <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                            <button class="btn btn-danger !py-2" type="submit"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="rounded-lg border border-amber-500/20 bg-amber-950/20 p-3 text-sm text-amber-100">
                <?php echo $h($t('เลือก Supplier ด้านล่างก่อน เพื่อจัดหมวดหมู่ สร้าง API-only product หรือเผยแพร่แบบกลุ่ม', 'Select a supplier below to manage category routing, API-only creation, and bulk publishing.')); ?>
            </div>
        <?php endif; ?>
    </section>

    <div id="apiProductsLivePanel" data-instant-panel class="space-y-5">
    <section class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <?php foreach ([
            'all' => [$t('ทั้งหมด', 'All'), $stats['total'], 'bi-grid'],
            'in_stock' => [$t('มีสต็อก', 'In stock'), $stats['in_stock'], 'bi-check-circle'],
            'out_of_stock' => [$t('หมดสต็อก', 'Out of stock'), $stats['out_of_stock'], 'bi-x-circle'],
            'mapped' => [$t('รวมหน้าหลักแล้ว', 'Mapped'), $stats['mapped'], 'bi-link-45deg'],
            'unmapped' => [$t('ยังไม่รวม', 'Unmapped'), $stats['unmapped'], 'bi-unlink'],
        ] as $tabState => $tab): ?>
            <?php $active = $state === $tabState || ($tabState === 'all' && $state === 'all'); ?>
            <a href="<?php echo $h($buildFilterUrl(['state' => $tabState === 'all' ? null : $tabState])); ?>"
               class="filter-card glass rounded-xl p-4 <?php echo $active ? 'ring-2 ring-violet-500/60 bg-violet-500/10' : ''; ?>">
                <div class="text-xs text-gray-400 flex items-center gap-2">
                    <i class="bi <?php echo $h($tab[2]); ?>"></i><?php echo $h($tab[0]); ?>
                </div>
                <div class="text-2xl font-bold mt-1"><?php echo number_format((int) $tab[1]); ?></div>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="glass rounded-xl p-4 md:p-5 space-y-4">
        <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
            <label class="text-sm text-gray-300">
                <?php echo $h($t('Supplier', 'Supplier')); ?>
                <select class="field mt-1" name="connection_id">
                    <option value="0"><?php echo $h($t('ทั้งหมด', 'All')); ?></option>
                    <?php foreach ($connections as $connection): ?>
                        <option value="<?php echo (int) $connection['id']; ?>" <?php echo $connectionId === (int) $connection['id'] ? 'selected' : ''; ?>>
                            #<?php echo (int) $connection['id']; ?> <?php echo $h($connection['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="text-sm text-gray-300">
                <?php echo $h($t('สถานะ', 'State')); ?>
                <select class="field mt-1" name="state">
                    <?php foreach ([
                        'all' => $t('ทั้งหมด', 'All'),
                        'in_stock' => $t('มีสต็อก', 'In stock'),
                        'out_of_stock' => $t('หมดสต็อก', 'Out of stock'),
                        'mapped' => $t('รวมหน้าหลักแล้ว', 'Mapped'),
                        'unmapped' => $t('ยังไม่รวม', 'Unmapped'),
                        'enabled' => $t('เปิดขาย', 'Enabled'),
                        'disabled' => $t('ปิดขาย', 'Disabled'),
                        'removed' => $t('ต้นทางลบแล้ว', 'Removed upstream'),
                    ] as $value => $label): ?>
                        <option value="<?php echo $h($value); ?>" <?php echo $state === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="text-sm text-gray-300">
                <?php echo $h($t('หมวดหมู่สินค้า API', 'API category')); ?>
                <select class="field mt-1" name="category">
                    <option value=""><?php echo $h($t('ทุกหมวดหมู่', 'All categories')); ?></option>
                    <?php foreach ($apiCategories as $categoryOption): ?>
                        <option value="<?php echo $h($categoryOption); ?>" <?php echo $category === $categoryOption ? 'selected' : ''; ?>>
                            <?php echo $h($categoryOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="xl:col-span-2 text-sm text-gray-300">
                <?php echo $h($t('ค้นหา', 'Search')); ?>
                <div class="flex gap-2 mt-1">
                    <input class="field" name="q" value="<?php echo $h($search); ?>"
                           placeholder="<?php echo $h($t('ชื่อสินค้า ระยะเวลา หมวดหมู่ หรือ Remote ID', 'Product, duration, category or remote ID')); ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    <?php if ($connectionId > 0 || $state !== 'all' || $category !== '' || $search !== ''): ?>
                        <a class="btn btn-soft" href="api_products.php"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </label>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4">
            <div class="text-sm text-gray-400">
                <?php echo $h($t('แสดง ', 'Showing ')); ?><strong class="text-white"><?php echo number_format(count($rows)); ?></strong>
                <?php echo $h($t(' รายการ', ' items')); ?>
                <?php if (count($rows) >= 1000): ?>
                    <span class="text-amber-300 ml-2"><?php echo $h($t('กรุณาใช้ตัวกรองเพื่อลดรายการ', 'Use filters to narrow the result.')); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($connectionId > 0): ?>
                <div class="flex flex-wrap gap-2">
                    <form id="bulkPublishForm" method="post" onsubmit="return confirm('<?php echo $h($t('เผยแพร่รายการที่เลือกเข้าหน้าร้านหรือไม่?', 'Publish selected items to the storefront?')); ?>')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="bulk_publish">
                        <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                        <button class="btn btn-soft" type="submit"><i class="bi bi-check2-square"></i><?php echo $h($t('เผยแพร่ที่เลือก', 'Publish selected')); ?></button>
                    </form>
                    <button class="btn btn-soft" type="button" id="selectVisibleApiProducts"><i class="bi bi-ui-checks-grid"></i><?php echo $h($t('เลือก/ยกเลิกทั้งหมดที่แสดง', 'Toggle visible')); ?></button>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="sync_connection">
                        <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                        <button class="btn btn-soft" type="submit">
                            <i class="bi bi-arrow-repeat"></i><?php echo $h($t('ดึงรายการและราคาใหม่', 'Refresh catalogue')); ?>
                        </button>
                    </form>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="apply_connection_prices">
                        <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                        <button class="btn btn-soft" type="submit">
                            <i class="bi bi-calculator"></i><?php echo $h($t('คำนวณราคาที่แมปทั้งหมด', 'Recalculate mapped prices')); ?>
                        </button>
                    </form>
                    <form method="post" onsubmit="return confirm('<?php echo $h($t(
                        'ระบบจะสร้างหรือรวมสินค้าที่เปิดใช้งานทั้งหมดเข้าหน้าหลัก ยืนยันหรือไม่?',
                        'Publish or merge all enabled supplier products?'
                    )); ?>')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="publish_connection">
                        <input type="hidden" name="connection_id" value="<?php echo $connectionId; ?>">
                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-shop"></i><?php echo $h($t('รวมสินค้าทั้งหมดเข้าหน้าหลัก', 'Publish all to storefront')); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="space-y-4">
        <?php if ($rows === []): ?>
            <div class="glass rounded-xl p-10 text-center text-gray-500">
                <i class="bi bi-search text-3xl block mb-2"></i>
                <?php echo $h($t('ไม่พบสินค้า API ตามตัวกรอง', 'No supplier products match the filters.')); ?>
            </div>
        <?php endif; ?>

        <?php foreach ($rows as $row):
            $mapped = (int) ($row['local_variant_id'] ?? 0) > 0;
            $groupMapped = (int) ($row['group_local_product_id'] ?? 0) > 0;
            $cost = (float) $row['cost_base'];
            $localUser = $row['local_price_user'] !== null ? (float) $row['local_price_user'] : null;
            $localReseller = $row['local_price_reseller'] !== null ? (float) $row['local_price_reseller'] : null;
            $inStock = (int) $row['remote_stock'] > 0 && empty($row['supplier_removed_at']);
            $rowCategories = isset($row['categories']) && is_array($row['categories']) ? $row['categories'] : [];
        ?>
            <details class="glass rounded-xl" <?php echo count($rows) === 1 ? 'open' : ''; ?>>
                <summary class="p-4 md:p-5 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <?php if ($connectionId > 0): ?>
                            <input type="checkbox" class="api-product-select w-4 h-4 shrink-0"
                                   name="supplier_product_ids[]" value="<?php echo (int) $row['id']; ?>"
                                   form="bulkPublishForm" onclick="event.stopPropagation()" aria-label="<?php echo $h($t('เลือกรายการนี้', 'Select this item')); ?>">
                        <?php endif; ?>
                        <?php if (!empty($row['image_url'])): ?>
                            <img src="<?php echo $h($row['image_url']); ?>" alt=""
                                 class="w-14 h-14 rounded-lg object-cover bg-black/30" loading="lazy">
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="font-bold text-lg break-words"><?php echo $h($row['name']); ?></div>
                            <div class="text-sm text-gray-400">
                                <?php echo $h($row['duration']); ?> · <?php echo $h($row['connection_name']); ?> · <?php echo $h($row['remote_product_id']); ?>
                            </div>
                            <?php if ($rowCategories): ?>
                                <div class="flex flex-wrap gap-1 mt-2">
                                    <?php foreach ($rowCategories as $rowCategory): ?>
                                        <span class="badge bg-white/10 text-gray-300"><?php echo $h($rowCategory); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge <?php echo (int) $row['enabled'] === 1 ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>">
                            <?php echo $h((int) $row['enabled'] === 1 ? $t('เปิดขาย', 'Enabled') : $t('ปิดขาย', 'Disabled')); ?>
                        </span>
                        <span class="badge <?php echo $mapped ? 'bg-sky-500/20 text-sky-200' : 'bg-amber-500/20 text-amber-200'; ?>">
                            <?php echo $h($mapped ? $t('รวมหน้าหลักแล้ว', 'Mapped') : $t('ยังไม่รวม', 'Unmapped')); ?>
                        </span>
                        <span class="badge <?php echo $inStock ? 'bg-green-500/20 text-green-200' : 'bg-gray-500/20 text-gray-300'; ?>">
                            <i class="bi <?php echo $inStock ? 'bi-check-circle' : 'bi-x-circle'; ?> mr-1"></i>
                            <?php echo $h($inStock ? $t('มีสต็อก ', 'In stock ') : $t('หมดสต็อก ', 'Out of stock ')); ?><?php echo (int) $row['remote_stock']; ?>
                        </span>
                    </div>
                </summary>

                <div class="border-t border-white/10 p-4 md:p-5 space-y-5">
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('ทุน API', 'API cost')); ?></div>
                            <div class="font-bold"><?php echo $h(number_format($cost, 2)); ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('ราคาแนะนำผู้ใช้ต้นทาง', 'Source user price')); ?></div>
                            <div><?php echo $row['source_price_user'] !== null ? $h(number_format((float) $row['source_price_user'], 2)) : '-'; ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('ราคาแนะนำตัวแทนต้นทาง', 'Source reseller price')); ?></div>
                            <div><?php echo $row['source_price_reseller'] !== null ? $h(number_format((float) $row['source_price_reseller'], 2)) : '-'; ?></div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('ราคาผู้ใช้เว็บนี้', 'Local user price')); ?></div>
                            <div class="<?php echo $localUser !== null && $localUser < $cost ? 'text-red-300' : 'text-emerald-300'; ?>">
                                <?php echo $localUser !== null ? $h(number_format($localUser, 2)) : '-'; ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('ราคาตัวแทนเว็บนี้', 'Local reseller price')); ?></div>
                            <div class="<?php echo $localReseller !== null && $localReseller < $cost ? 'text-red-300' : 'text-emerald-300'; ?>">
                                <?php echo $localReseller !== null ? $h(number_format($localReseller, 2)) : '-'; ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-gray-500"><?php echo $h($t('กำไรต่อชิ้น', 'Profit each')); ?></div>
                            <div>
                                <?php echo $localUser !== null ? $h(number_format($localUser - $cost, 2)) : '-'; ?>
                                /
                                <?php echo $localReseller !== null ? $h(number_format($localReseller - $cost, 2)) : '-'; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-black/20 p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="text-sm space-y-1 min-w-0">
                            <div class="font-semibold">
                                <i class="bi bi-activity mr-2 text-emerald-300"></i><?php echo $h($t('สถานะยืนยันสต็อก', 'Inventory verification')); ?>
                            </div>
                            <div class="text-gray-400 break-words">
                                <?php echo $h($t('ตรวจล่าสุด: ', 'Checked: ')); ?>
                                <?php echo $h((string) ($row['inventory_checked_at'] ?? '-') ?: '-'); ?>
                                · <?php echo $h($t('สำเร็จล่าสุด: ', 'Last success: ')); ?>
                                <?php echo $h((string) ($row['inventory_last_success_at'] ?? '-') ?: '-'); ?>
                            </div>
                            <?php if (!empty($row['inventory_last_error_code']) || !empty($row['inventory_last_error_message'])): ?>
                                <div class="text-red-300 break-words">
                                    [<?php echo $h((string) ($row['inventory_last_error_code'] ?? 'inventory_error')); ?>]
                                    <?php echo $h((string) ($row['inventory_last_error_message'] ?? '')); ?>
                                    <?php if (!empty($row['inventory_last_error_at'])): ?>
                                        · <?php echo $h((string) $row['inventory_last_error_at']); ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-emerald-300"><?php echo $h($t('ไม่พบข้อผิดพลาดจากการตรวจสต็อกล่าสุด', 'No error from the latest inventory check.')); ?></div>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="shrink-0">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="verify_inventory">
                            <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                            <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                            <button class="btn btn-soft" type="submit">
                                <i class="bi bi-arrow-repeat"></i><?php echo $h($t('ตรวจสต็อกสินค้านี้', 'Verify this inventory')); ?>
                            </button>
                        </form>
                    </div>

                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                        <form method="post" class="rounded-xl border border-white/10 bg-black/20 p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="save_policy">
                            <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                            <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                            <h3 class="md:col-span-2 font-bold">
                                <i class="bi bi-calculator mr-2 text-violet-300"></i><?php echo $h($t('กฎราคาของตัวเลือกนี้', 'Pricing policy for this variant')); ?>
                            </h3>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('ราคาผู้ใช้', 'User price mode')); ?>
                                <select class="field mt-1" name="user_price_mode">
                                    <?php foreach ([
                                        'connection' => $t('ตามค่าหลัก Supplier', 'Supplier default'),
                                        'source' => $t('ตามราคาแนะนำต้นทาง', 'Match source'),
                                        'markup' => $t('ทุน API + %', 'API cost + %'),
                                        'fixed' => $t('กำหนดราคาคงที่', 'Fixed price'),
                                        'keep' => $t('คงราคาเว็บนี้', 'Keep local price'),
                                    ] as $mode => $label): ?>
                                        <option value="<?php echo $h($mode); ?>" <?php echo ($row['user_price_mode'] ?? 'connection') === $mode ? 'selected' : ''; ?>>
                                            <?php echo $h($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('ราคาตัวแทน', 'Reseller price mode')); ?>
                                <select class="field mt-1" name="reseller_price_mode">
                                    <?php foreach ([
                                        'connection' => $t('ตามค่าหลัก Supplier', 'Supplier default'),
                                        'source' => $t('ตามราคาแนะนำต้นทาง', 'Match source'),
                                        'markup' => $t('ทุน API + %', 'API cost + %'),
                                        'fixed' => $t('กำหนดราคาคงที่', 'Fixed price'),
                                        'keep' => $t('คงราคาเว็บนี้', 'Keep local price'),
                                    ] as $mode => $label): ?>
                                        <option value="<?php echo $h($mode); ?>" <?php echo ($row['reseller_price_mode'] ?? 'connection') === $mode ? 'selected' : ''; ?>>
                                            <?php echo $h($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('กำไรผู้ใช้ %', 'User markup %')); ?>
                                <input class="field mt-1" type="number" step="0.01" min="0" max="10000"
                                       name="user_markup_percent"
                                       value="<?php echo $row['user_markup_percent'] !== null ? $h(number_format((float) $row['user_markup_percent'], 2, '.', '')) : ''; ?>"
                                       placeholder="<?php echo $h((string) $row['connection_user_markup_percent']); ?>">
                            </label>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('กำไรตัวแทน %', 'Reseller markup %')); ?>
                                <input class="field mt-1" type="number" step="0.01" min="0" max="10000"
                                       name="reseller_markup_percent"
                                       value="<?php echo $row['reseller_markup_percent'] !== null ? $h(number_format((float) $row['reseller_markup_percent'], 2, '.', '')) : ''; ?>"
                                       placeholder="<?php echo $h((string) $row['connection_reseller_markup_percent']); ?>">
                            </label>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('ราคาผู้ใช้คงที่', 'Fixed user price')); ?>
                                <input class="field mt-1" type="number" step="0.01" min="0" name="user_fixed_price"
                                       value="<?php echo $row['user_fixed_price'] !== null ? $h(number_format((float) $row['user_fixed_price'], 2, '.', '')) : ''; ?>">
                            </label>

                            <label class="text-xs text-gray-400">
                                <?php echo $h($t('ราคาตัวแทนคงที่', 'Fixed reseller price')); ?>
                                <input class="field mt-1" type="number" step="0.01" min="0" name="reseller_fixed_price"
                                       value="<?php echo $row['reseller_fixed_price'] !== null ? $h(number_format((float) $row['reseller_fixed_price'], 2, '.', '')) : ''; ?>">
                            </label>

                            <label class="md:col-span-2 text-sm">
                                <input type="checkbox" name="enabled" <?php echo (int) $row['enabled'] === 1 ? 'checked' : ''; ?> class="mr-2">
                                <?php echo $h($t('เปิดให้ขายผ่าน API และใช้เป็นสต็อกสำรองในหน้าหลัก', 'Enable API sales and storefront fallback')); ?>
                            </label>

                            <div class="md:col-span-2">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-save"></i><?php echo $h($t('บันทึกและใช้ราคาทันที', 'Save and apply now')); ?>
                                </button>
                            </div>
                        </form>

                        <div class="rounded-xl border border-white/10 bg-black/20 p-4 space-y-4">
                            <h3 class="font-bold">
                                <i class="bi bi-diagram-3 mr-2 text-sky-300"></i><?php echo $h($t('รวมเข้ากับหน้าหลัก', 'Storefront mapping')); ?>
                            </h3>
                            <div class="rounded-lg border border-cyan-500/20 bg-cyan-950/20 p-3 text-xs text-gray-300 leading-relaxed">
                                <?php echo $h($t(
                                    'ตัวเลือกเดียวเชื่อมได้หลาย API เลขลำดับแหล่งสต็อกยิ่งน้อยยิ่งถูกเลือกก่อน หากแหล่งแรกหมดหรือปฏิเสธคำสั่งซื้ออย่างชัดเจน ระบบจะลองแหล่งถัดไป แต่ถ้าคำสั่งซื้อไม่แน่ชัดหรือ timeout หลังส่งคำสั่ง ระบบจะหยุดเพื่อป้องกันการซื้อซ้ำ',
                                    'One local variant can use multiple APIs. Lower source priority is preferred. If a source is definitely sold out or rejects before an ambiguous order exists, checkout can try the next source. An uncertain/timeout order stops failover to prevent duplicate purchases.'
                                )); ?>
                            </div>

                            <form method="post" class="rounded-lg border border-violet-500/25 bg-violet-950/20 p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3"
                                  onsubmit="return confirm('<?php echo $h($t('ซิงค์รูปแบบทั้งหมดของสินค้านี้จากต้นทางใช่หรือไม่? ระบบจะใช้ 1/3/7 ที่มีอยู่แล้ว และสร้างเฉพาะรูปแบบที่ขาด เช่น 30 วัน', 'Synchronize every variant in this supplier group? Existing 1/3/7 variants will be reused and only missing variants such as 30 days will be created.')); ?>')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="sync_group_variants">
                                <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                <div>
                                    <div class="text-sm font-semibold text-violet-200"><i class="bi bi-layers mr-2"></i><?php echo $h($t('ซิงค์รูปแบบสินค้าตามต้นทาง', 'Sync variants from supplier')); ?></div>
                                    <div class="text-xs text-gray-400 mt-1 leading-relaxed"><?php echo $h($t(
                                        'ระบบตรวจทั้งกลุ่มสินค้า ใช้รูปแบบเดิมที่ระยะเวลาตรงกัน และสร้างเฉพาะระยะเวลาที่ต้นทางเพิ่มใหม่ จึงไม่ควรเกิด 1,1,3,3,7,7,30',
                                        'The whole supplier group is checked. Matching local durations are reused and only new upstream durations are created, preventing duplicate 1,1,3,3,7,7,30 variants.'
                                    )); ?></div>
                                </div>
                                <button class="btn btn-primary shrink-0" type="submit"><i class="bi bi-arrow-repeat"></i><?php echo $h($t('ซิงค์รูปแบบทั้งหมด', 'Sync all variants')); ?></button>
                            </form>

                            <?php if ($mapped): ?>
                                <div class="rounded-lg bg-sky-900/20 border border-sky-500/20 p-3 text-sm">
                                    <div class="font-semibold"><?php echo $h($row['local_product_name'] . ' - ' . $row['local_duration']); ?></div>
                                    <div class="text-gray-400">
                                        Product #<?php echo (int) $row['local_product_id']; ?> ·
                                        Variant #<?php echo (int) $row['local_variant_id']; ?> ·
                                        <?php echo $h($t('ลำดับแหล่งสต็อก ', 'Source priority ')); ?><?php echo (int) ($row['source_priority'] ?? 100); ?> ·
                                        <?php echo $h($t('ลำดับ API ', 'Connection priority ')); ?><?php echo (int) ($row['connection_priority'] ?? 100); ?> ·
                                        <?php echo $h((int) $row['sync_duration'] === 1 ? $t('ซิงก์ชื่อระยะเวลา', 'Duration synced') : $t('คงชื่อระยะเวลาเดิม', 'Local duration preserved')); ?>
                                    </div>
                                </div>
                                <?php $groupCategoryOverride = isset($row['group_category_override']) && is_array($row['group_category_override']) ? $row['group_category_override'] : []; ?>
                                <form method="post" class="rounded-lg border border-white/10 bg-black/20 p-3 space-y-2">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="save_group_categories">
                                    <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                    <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                    <label class="text-xs text-gray-400 block">
                                        <?php echo $h($t('หมวดหมู่เฉพาะสินค้านี้ สูงสุด 4 หมวด', 'Product-specific storefront categories, up to 4')); ?>
                                        <input class="field mt-1" name="local_categories" maxlength="1100" list="bridge-local-category-list"
                                               value="<?php echo $h(implode(', ', $groupCategoryOverride)); ?>"
                                               placeholder="<?php echo $h(implode(', ', array_slice($rowCategories, 0, 4))); ?>">
                                    </label>
                                    <label class="text-xs text-gray-300 flex items-center gap-2">
                                        <input type="checkbox" name="use_automatic_categories" value="1" <?php echo $groupCategoryOverride === [] ? 'checked' : ''; ?>>
                                        <?php echo $h($t('ใช้กฎหมวดหมู่ของ Supplier อัตโนมัติ (ไม่ใช้ override)', 'Use supplier category rules automatically (no override)')); ?>
                                    </label>
                                    <button class="btn btn-soft !py-2" type="submit"><i class="bi bi-tags"></i><?php echo $h($t('บันทึกหมวดหมู่', 'Save categories')); ?></button>
                                </form>
                            <?php elseif ($groupMapped): ?>
                                <div class="rounded-lg border border-amber-500/25 bg-amber-950/20 p-3 space-y-3">
                                    <div class="text-sm font-semibold text-amber-200"><i class="bi bi-stars mr-2"></i><?php echo $h($t('พบรูปแบบใหม่จากต้นทาง', 'New supplier variant detected')); ?></div>
                                    <div class="text-xs text-gray-400 leading-relaxed">
                                        <?php echo $h($t(
                                            'กลุ่มสินค้านี้เชื่อมกับหน้าหลักอยู่แล้ว แต่รูปแบบนี้ยังไม่มีการเชื่อม ไม่ต้องสร้างสินค้าใหม่ ให้ใช้ “ซิงค์รูปแบบทั้งหมด” ด้านบน ระบบจะนำ 1/3/7 เดิมกลับมาใช้และสร้างเฉพาะรูปแบบที่ขาด',
                                            'This product group is already linked to the storefront, but this variant is not linked yet. Do not create another product. Use “Sync all variants” above to reuse existing durations and create only the missing one.'
                                        )); ?>
                                    </div>
                                    <div class="text-xs text-amber-100">
                                        <?php echo $h($t('สินค้าหลัก: ', 'Local product: ')); ?>#<?php echo (int) ($row['group_local_product_id'] ?? 0); ?> · <?php echo $h((string) ($row['group_local_product_name'] ?? '')); ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="rounded-lg border border-emerald-500/20 bg-emerald-950/15 p-3 space-y-3">
                                    <div class="text-sm font-semibold text-emerald-200"><i class="bi bi-cloud-plus mr-2"></i><?php echo $h($t('สร้างสินค้า API-only ใหม่', 'Create new API-only product')); ?></div>
                                    <div class="text-xs text-gray-400 leading-relaxed"><?php echo $h($t(
                                        'สร้าง Product + Variant จากกลุ่ม API นี้โดยตรง ไม่สร้างแถวคีย์ปลอม สต็อกหน้าร้านจะใช้ remote_stock และจะสร้างทุกระยะเวลาในกลุ่มเดียวกันให้โดยอัตโนมัติ',
                                        'Creates Product + Variants directly from this API group without fake key rows. Storefront stock uses remote_stock and all durations in the group are created automatically.'
                                    )); ?></div>
                                    <form method="post" class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_140px_auto] gap-2 items-end">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="create_api_only_product">
                                        <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                        <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                        <label class="text-xs text-gray-400">
                                            <?php echo $h($t('หมวดหมู่หน้าร้าน (เว้นว่าง = ใช้กฎอัตโนมัติ)', 'Storefront categories (blank = category rules)')); ?>
                                            <input class="field mt-1" name="local_categories" maxlength="1100" list="bridge-local-category-list"
                                                   placeholder="<?php echo $h(implode(', ', array_slice($rowCategories, 0, 4))); ?>">
                                        </label>
                                        <label class="text-xs text-gray-400">
                                            <?php echo $h($t('Source priority', 'Source priority')); ?>
                                            <input class="field mt-1" type="number" name="source_priority" min="-100000" max="100000" value="100">
                                        </label>
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-square"></i><?php echo $h($t('สร้างใหม่', 'Create new')); ?></button>
                                    </form>
                                    <form method="post">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="publish_product">
                                        <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                        <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                        <button class="btn btn-soft w-full" type="submit">
                                            <i class="bi bi-magic"></i><?php echo $h($t('ให้ระบบค้นหาของเดิมแล้วสร้าง/รวมอัตโนมัติ', 'Auto-detect existing product and create/merge')); ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <form method="post" class="space-y-3 catalog-picker"
                                  data-picker-kind="product"
                                  data-selected="<?php echo (int) ($row['local_product_id'] ?? 0); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="map_group">
                                <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                <input type="hidden" name="source_ref" value="<?php echo $h($row['remote_source_product_id']); ?>">
                                <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <label class="text-xs text-gray-400">
                                        <?php echo $h($t('หมวดหมู่สินค้าหลัก', 'Local category')); ?>
                                        <select class="field mt-1 picker-category">
                                            <option value=""><?php echo $h($t('ทุกหมวดหมู่', 'All categories')); ?></option>
                                            <?php foreach ($localCategories as $localCategory): ?>
                                                <option value="<?php echo $h($localCategory); ?>"><?php echo $h($localCategory); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="text-xs text-gray-400">
                                        <?php echo $h($t('ค้นหาชื่อสินค้าหลัก', 'Search local product')); ?>
                                        <input class="field mt-1 picker-search" type="search"
                                               placeholder="<?php echo $h($t('พิมพ์ชื่อหรือเลขสินค้า', 'Type a name or product ID')); ?>">
                                    </label>
                                </div>

                                <label class="text-xs text-gray-400">
                                    <?php echo $h($t('สินค้าหลักที่ต้องการรวม', 'Target local product')); ?>
                                    <select class="field mt-1 picker-select" name="local_product_id" required>
                                        <option value=""><?php echo $h($t('เปิดช่องค้นหาเพื่อโหลดรายการ', 'Focus the search fields to load products')); ?></option>
                                    </select>
                                </label>
                                <div class="picker-count text-xs text-gray-500"></div>
                                <button class="btn btn-soft w-full" type="submit">
                                    <i class="bi bi-box-arrow-in-down"></i><?php echo $h($t('รวมทุกตัวเลือกเข้ากับสินค้าหลัก', 'Merge all variants into selected product')); ?>
                                </button>
                            </form>

                            <form method="post" class="space-y-3 catalog-picker"
                                  data-picker-kind="variant"
                                  data-selected="<?php echo (int) ($row['local_variant_id'] ?? 0); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="link_variant">
                                <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <label class="text-xs text-gray-400">
                                        <?php echo $h($t('หมวดหมู่ตัวเลือกเดิม', 'Local category')); ?>
                                        <select class="field mt-1 picker-category">
                                            <option value=""><?php echo $h($t('ทุกหมวดหมู่', 'All categories')); ?></option>
                                            <?php foreach ($localCategories as $localCategory): ?>
                                                <option value="<?php echo $h($localCategory); ?>"><?php echo $h($localCategory); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="text-xs text-gray-400">
                                        <?php echo $h($t('ค้นหาชื่อหรือระยะเวลา', 'Search product or duration')); ?>
                                        <input class="field mt-1 picker-search" type="search"
                                               placeholder="<?php echo $h($t('ชื่อสินค้า ระยะเวลา หรือ Variant ID', 'Product, duration or variant ID')); ?>">
                                    </label>
                                </div>

                                <label class="text-xs text-gray-400">
                                    <?php echo $h($t('ตัวเลือกที่ต้องการเชื่อมตรง', 'Target local variant')); ?>
                                    <select class="field mt-1 picker-select" name="local_variant_id" required>
                                        <option value=""><?php echo $h($t('เปิดช่องค้นหาเพื่อโหลดรายการ', 'Focus the search fields to load variants')); ?></option>
                                    </select>
                                </label>
                                <label class="text-xs text-gray-400">
                                    <?php echo $h($t('ลำดับแหล่งสต็อก (เลขน้อยลองก่อน)', 'Source priority (lower is tried first)')); ?>
                                    <input class="field mt-1" type="number" name="source_priority" min="-100000" max="100000"
                                           value="<?php echo (int) ($row['source_priority'] ?? 100); ?>" required>
                                </label>
                                <div class="picker-count text-xs text-gray-500"></div>
                                <button class="btn btn-soft w-full" type="submit">
                                    <i class="bi bi-link-45deg"></i><?php echo $h($t('เชื่อม/อัปเดตแหล่งสต็อกนี้', 'Link or update this stock source')); ?>
                                </button>
                            </form>

                            <?php if ($mapped): ?>
                                <form method="post" onsubmit="return confirm('<?php echo $h($t(
                                    'ยกเลิกเฉพาะการเชื่อม สินค้าในหน้าหลักจะไม่ถูกลบ ยืนยันหรือไม่?',
                                    'Remove only the API mapping? The local product will remain.'
                                )); ?>')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="unlink_product">
                                    <input type="hidden" name="connection_id" value="<?php echo (int) $row['connection_id']; ?>">
                                    <input type="hidden" name="supplier_product_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="return_query" value="<?php echo $h($currentReturnQuery); ?>">
                                    <button class="btn btn-danger w-full" type="submit">
                                        <i class="bi bi-unlink"></i><?php echo $h($t('ยกเลิกการเชื่อม', 'Unlink')); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
    </div>
</main>

<script>
const localProductOptions = <?php echo json_encode($localProducts, $jsonFlags); ?>;
const localVariantOptions = <?php echo json_encode($localVariants, $jsonFlags); ?>;
const pickerLabels = {
    empty: <?php echo json_encode($t('ไม่พบรายการตามหมวดหมู่หรือคำค้น', 'No local items match the category or search.'), $jsonFlags); ?>,
    selectProduct: <?php echo json_encode($t('เลือกสินค้าหลัก', 'Select a local product'), $jsonFlags); ?>,
    selectVariant: <?php echo json_encode($t('เลือกตัวเลือกสินค้า', 'Select a local variant'), $jsonFlags); ?>,
    showing: <?php echo json_encode($t('พบ ', 'Found '), $jsonFlags); ?>,
    items: <?php echo json_encode($t(' รายการ', ' items'), $jsonFlags); ?>
};

function normalizePickerText(value) {
    return String(value || '').toLocaleLowerCase().trim();
}

function pickerOptionLabel(item, kind) {
    const category = Array.isArray(item.categories) && item.categories.length
        ? '[' + item.categories.join(' / ') + '] '
        : '';
    if (kind === 'variant') {
        return category + '#' + item.product_id + '/' + item.id + ' ' + item.name + ' - ' + item.duration;
    }
    return category + '#' + item.id + ' ' + item.name;
}

function renderCatalogPicker(root) {
    const kind = root.dataset.pickerKind === 'variant' ? 'variant' : 'product';
    const data = kind === 'variant' ? localVariantOptions : localProductOptions;
    const category = root.querySelector('.picker-category')?.value || '';
    const search = normalizePickerText(root.querySelector('.picker-search')?.value || '');
    const select = root.querySelector('.picker-select');
    const count = root.querySelector('.picker-count');
    if (!select) return;

    const previous = select.value || root.dataset.selected || '';
    const filtered = data.filter((item) => {
        const categories = Array.isArray(item.categories) ? item.categories : [];
        if (category && !categories.includes(category)) return false;
        if (!search) return true;
        const haystack = normalizePickerText([
            item.id,
            item.product_id || '',
            item.name,
            item.duration || '',
            categories.join(' '),
        ].join(' '));
        return haystack.includes(search);
    });

    select.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = filtered.length
        ? (kind === 'variant' ? pickerLabels.selectVariant : pickerLabels.selectProduct)
        : pickerLabels.empty;
    select.appendChild(placeholder);

    filtered.forEach((item) => {
        const option = document.createElement('option');
        option.value = String(item.id);
        option.textContent = pickerOptionLabel(item, kind);
        if (String(item.id) === String(previous)) option.selected = true;
        select.appendChild(option);
    });

    if (count) count.textContent = pickerLabels.showing + filtered.length + pickerLabels.items;
    root.dataset.initialized = '1';
}

function initializePicker(root) {
    if (!root || root.dataset.initialized === '1') return;
    renderCatalogPicker(root);
    root.querySelector('.picker-category')?.addEventListener('change', () => renderCatalogPicker(root));
    root.querySelector('.picker-search')?.addEventListener('input', () => renderCatalogPicker(root));
}

document.addEventListener('focusin', (event) => {
    const root = event.target.closest('.catalog-picker');
    if (root) initializePicker(root);
});

document.addEventListener('toggle', (event) => {
    const details = event.target;
    if (!(details instanceof HTMLDetailsElement) || !details.open) return;
    details.querySelectorAll('.catalog-picker').forEach(initializePicker);
}, true);

document.addEventListener('submit', (event) => {
    const root = event.target.closest('.catalog-picker');
    if (!root) return;
    initializePicker(root);
    const select = root.querySelector('.picker-select');
    if (select && !select.value) {
        event.preventDefault();
        select.focus();
    }
});


const bulkPublishForm = document.getElementById('bulkPublishForm');
const selectVisibleApiProducts = document.getElementById('selectVisibleApiProducts');
if (selectVisibleApiProducts) {
    selectVisibleApiProducts.addEventListener('click', () => {
        const boxes = Array.from(document.querySelectorAll('.api-product-select'));
        if (!boxes.length) return;
        const shouldCheck = !boxes.every((box) => box.checked);
        boxes.forEach((box) => { box.checked = shouldCheck; });
    });
}
if (bulkPublishForm) {
    bulkPublishForm.addEventListener('submit', (event) => {
        if (!document.querySelector('.api-product-select:checked')) {
            event.preventDefault();
            window.alert(<?php echo json_encode($t('กรุณาเลือกรายการอย่างน้อย 1 รายการ', 'Select at least one product.'), $jsonFlags); ?>);
        }
    });
}
</script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
