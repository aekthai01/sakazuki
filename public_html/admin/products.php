<?php

/**

 * Handle product image upload (stores in /assets/uploads/products).

 * Returns relative path (e.g., assets/uploads/products/xxx.jpg) or existing value.

 */

function handleProductImageUpload(string $fileField, string $existing = ''): string
{
    $uploadDirFs = __DIR__ . '/../assets/uploads/products/';
    $uploadDirRel = 'assets/uploads/products/';

    if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField]) ||
        (int) ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    $file = $_FILES[$fileField];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $existing;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > 4 * 1024 * 1024) {
        return $existing;
    }

    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
        return $existing;
    }
    $imageInfo = @getimagesizefromstring($bytes);
    if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1]) ||
        (int) $imageInfo[0] > 6000 || (int) $imageInfo[1] > 6000 || ((int) $imageInfo[0] * (int) $imageInfo[1]) > 24000000) {
        return $existing;
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? finfo_buffer($finfo, $bytes) : false;
    if ($finfo) finfo_close($finfo);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        return $existing;
    }

    if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
        return $existing;
    }

    // Prefer a 1024px browser-friendly WebP (or resized original format when
    // WebP is unavailable). This keeps multi-megabyte phone uploads away from
    // 72px catalogue cards. Shared hosting only needs the common PHP GD module.
    if (function_exists('sakazukiWriteOptimizedProductImage')) {
        $optimized = sakazukiWriteOptimizedProductImage($bytes, $uploadDirFs, 'p_', 1024);
        if (!empty($optimized['success']) && !empty($optimized['filename'])) {
            return $uploadDirRel . $optimized['filename'];
        }
    }

    // Compatibility fallback for hosting packages without GD/WebP. Keep the
    // validated original rather than breaking product administration entirely.
    try {
        $filename = 'p_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    } catch (Throwable $e) {
        return $existing;
    }
    $destination = $uploadDirFs . $filename;
    $written = file_put_contents($destination, $bytes, LOCK_EX) === strlen($bytes);
    if (!$written) {
        @unlink($destination);
        return $existing;
    }
    @chmod($destination, 0644);
    return $uploadDirRel . $filename;
}

/**
 * Safely encode a PHP value for use as an inline JavaScript argument inside HTML attributes.
 */
function jsArg($value): string
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return htmlspecialchars($json === false ? 'null' : $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once '../includes/auth.php';
require_once '../includes/store_bridge.php';

requireAdmin();
ensureProductPlatformsTable();
ensureProductArchiveTables();

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function sanitizeProductsReturnQuery($raw): string
{
    if (!is_scalar($raw)) return '';
    $parsed = [];
    parse_str((string) $raw, $parsed);
    $allowed = ['q', 'category', 'status', 'platform', 'sort', 'per_page', 'page'];
    $clean = [];
    foreach ($allowed as $key) {
        if (!isset($parsed[$key]) || !is_scalar($parsed[$key])) continue;
        $value = trim((string) $parsed[$key]);
        if ($value === '' || strlen($value) > 160) continue;
        $clean[$key] = $value;
    }
    return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
}

function productsPageUrl(string $anchor = ''): string
{
    $rawQuery = $_POST['_return_query'] ?? ($_SERVER['QUERY_STRING'] ?? '');
    $query = sanitizeProductsReturnQuery($rawQuery);
    $url = 'products.php' . ($query !== '' ? '?' . $query : '');
    $anchor = trim($anchor);
    if ($anchor !== '') {
        $url .= '#' . rawurlencode($anchor);
    }
    return $url;
}


/**
 * Translate server-side validation/flash messages for this page without
 * depending on a browser-side dictionary.
 */
function productsUiText(string $th, string $en): string
{
    return getAppLang() === 'en' ? $en : $th;
}

function setProductsFlash(
    string $type,
    string $message,
    array $details = [],
    string $returnAnchor = '',
    int $returnScrollY = 0
): void
{
    if ($message === '') {
        return;
    }

    $_SESSION['products_flash'] = [
        'type' => $type,
        'message' => $message,
        'details' => array_values(array_filter(array_map('strval', $details), static function ($v) { return $v !== ''; })),
        'return_anchor' => normalizeProductsAnchor($returnAnchor),
        'return_scroll_y' => max(0, min(10000000, $returnScrollY)),
    ];
}

function redirectAfterProductsPost(string $success, string $error, string $anchor = '', array $details = []): void
{
    $postedScrollY = isset($_POST['_return_scroll_y']) && is_scalar($_POST['_return_scroll_y'])
        ? max(0, min(10000000, (int) $_POST['_return_scroll_y']))
        : 0;
    if ($success !== '') {
        setProductsFlash('success', $success, $details, $anchor, $postedScrollY);
    } elseif ($error !== '') {
        setProductsFlash('error', $error, $details, $anchor, $postedScrollY);
    }

    header('Location: ' . productsPageUrl($anchor), true, 303);
    exit;
}

function summarizeKeyList(array $keys, int $limit = 20): array
{
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static function ($v) { return $v !== ''; })));
    if (count($keys) <= $limit) {
        return $keys;
    }

    $shown = array_slice($keys, 0, $limit);
    $shown[] = productsUiText('... และอีก ' . (count($keys) - $limit) . ' รายการ', '... and ' . (count($keys) - $limit) . ' more');
    return $shown;
}


function normalizeProductsAnchor($anchor): string
{
    if (!is_scalar($anchor)) {
        return '';
    }
    $anchor = trim((string) $anchor);
    if ($anchor === '') {
        return '';
    }

    // Only allow anchors generated by this page. This prevents odd redirects while still
    // supporting product-123 and variant-456 targets.
    return preg_match('/^(product|variant)-\d+$/', $anchor) ? $anchor : '';
}

function postedProductsReturnAnchor(): string
{
    return normalizeProductsAnchor($_POST['_return_anchor'] ?? '');
}


global $conn;



// Ensure columns needed for profit analysis exist

ensureProfitColumns();



$error = '';

$success = '';

$flashDetails = [];
$flashReturnAnchor = '';
$flashReturnScrollY = 0;

if (isset($_SESSION['products_flash']) && is_array($_SESSION['products_flash'])) {
    $flashType = (string) ($_SESSION['products_flash']['type'] ?? '');
    $flashMessage = (string) ($_SESSION['products_flash']['message'] ?? '');
    $flashDetails = $_SESSION['products_flash']['details'] ?? [];
    if (!is_array($flashDetails)) {
        $flashDetails = [];
    }
    $flashDetails = array_values(array_filter(array_map('strval', $flashDetails), static function ($v) { return $v !== ''; }));
    $flashReturnAnchor = normalizeProductsAnchor($_SESSION['products_flash']['return_anchor'] ?? '');
    $flashReturnScrollY = max(0, min(10000000, (int) ($_SESSION['products_flash']['return_scroll_y'] ?? 0)));

    if ($flashType === 'success') {
        $success = $flashMessage;
    } elseif ($flashType === 'error') {
        $error = $flashMessage;
    }

    unset($_SESSION['products_flash']);
}

$editProductId = isset($_GET['edit']) && is_scalar($_GET['edit']) ? (int) $_GET['edit'] : 0;

// NOTE: do not auto-open Add Product modal via querystring (prevents auto-open on refresh)

$openAdd = false;



/**

 * ====== AJAX SUBMIT WITH FULL PAGE REFRESH AFTER SUCCESS ======

 * Ajax helper + JSON responder for update_variant & add_variant_keys

 */

// Utility functions moved to includes/functions.php



// Handle actions

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    // Schema helpers may execute DDL, which implicitly commits MySQL
    // transactions. Run them BEFORE any business transaction in this request.
    // This keeps archive/product/variant operations genuinely atomic.
    $bridgeSchemaReady = storeBridgeEnsureSchema();
    ensureProductCategoryLinksTable();
    $actionRaw = $_POST['action'] ?? '';
    $action = is_scalar($actionRaw) ? trim((string) $actionRaw) : '';
    $postRedirectAnchor = postedProductsReturnAnchor();
    $postFlashDetails = [];

    $normalizeCategories = static function (): array {
        $categories = [];
        for ($i = 1; $i <= 4; $i++) {
            $raw = $_POST['category' . $i] ?? '';
            if (!is_scalar($raw)) continue;
            $value = trim((string) $raw);
            if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) continue;
            if (!in_array($value, $categories, true)) $categories[] = $value;
        }
        if (!$categories) {
            $legacy = $_POST['category'] ?? '';
            if (is_scalar($legacy)) {
                $legacy = trim((string) $legacy);
                if ($legacy !== '' && strlen($legacy) <= 255 && !preg_match('/[\x00-\x1F\x7F]/', $legacy)) {
                    $categories[] = $legacy;
                }
            }
        }
        return array_slice($categories, 0, 4);
    };

    $validPrice = static function ($value): bool {
        return is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 10000000;
    };

    $saveCategories = static function (int $productId, array $categories) use ($conn): bool {
        if ($productId < 1) return false;
        if (!$conn->query('DELETE FROM product_category_links WHERE product_id = ' . $productId)) return false;
        if (!$categories) return true;
        $stmt = $conn->prepare('INSERT INTO product_category_links (product_id, category) VALUES (?, ?)');
        if (!$stmt) return false;
        foreach ($categories as $categoryValue) {
            $stmt->bind_param('is', $productId, $categoryValue);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }
        }
        $stmt->close();
        return true;
    };


    $deleteCgoLinksForProduct = static function (int $productId) use ($conn): bool {
        $stmt = $conn->prepare('DELETE FROM cgo_catalog_links WHERE local_product_id = ?');
        if (!$stmt) return (int) $conn->errno === 1146;
        $stmt->bind_param('i', $productId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    };

    $deleteCgoLinksForVariant = static function (int $variantId) use ($conn): bool {
        $stmt = $conn->prepare('DELETE FROM cgo_catalog_links WHERE local_variant_id = ?');
        if (!$stmt) return (int) $conn->errno === 1146;
        $stmt->bind_param('i', $variantId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    };

    // Admin deletion is authoritative. Disable the exact supplier rows that
    // were mapped to the removed item before unlinking them, otherwise an
    // automatic Bridge sync could immediately publish the same API row again.
    // supplier_products itself is retained for audit/catalog visibility.
    $detachBridgeForProduct = static function (int $productId) use ($conn, $bridgeSchemaReady): bool {
        if ($productId < 1) return false;
        if (!$bridgeSchemaReady) return false;
        $ids = [];
        $stmt = $conn->prepare('SELECT DISTINCT supplier_product_id FROM supplier_catalog_links WHERE local_product_id=? FOR UPDATE');
        if (!$stmt) return false;
        $stmt->bind_param('i', $productId);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $result = $stmt->get_result();
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $id = (int) ($row['supplier_product_id'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        $stmt->close();
        if ($ids) {
            $idList = implode(',', array_map('intval', array_values($ids)));
            if (!$conn->query("UPDATE supplier_products SET enabled=0 WHERE id IN ({$idList})")) return false;
            if (!$conn->query("UPDATE supplier_catalog_links SET api_fallback_enabled=0 WHERE supplier_product_id IN ({$idList})")) return false;
        }
        $deleteLinks = $conn->prepare('DELETE FROM supplier_catalog_links WHERE local_product_id=?');
        if (!$deleteLinks) return false;
        $deleteLinks->bind_param('i', $productId);
        $ok = $deleteLinks->execute();
        $deleteLinks->close();
        if (!$ok) return false;
        $deleteGroups = $conn->prepare('DELETE FROM supplier_catalog_products WHERE local_product_id=?');
        if (!$deleteGroups) return false;
        $deleteGroups->bind_param('i', $productId);
        $ok = $deleteGroups->execute();
        $deleteGroups->close();
        return $ok;
    };

    $detachBridgeForVariant = static function (int $variantId) use ($conn, $bridgeSchemaReady): bool {
        if ($variantId < 1) return false;
        if (!$bridgeSchemaReady) return false;
        $ids = [];
        $stmt = $conn->prepare('SELECT DISTINCT supplier_product_id FROM supplier_catalog_links WHERE local_variant_id=? FOR UPDATE');
        if (!$stmt) return false;
        $stmt->bind_param('i', $variantId);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $result = $stmt->get_result();
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $id = (int) ($row['supplier_product_id'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        $stmt->close();
        if ($ids) {
            $idList = implode(',', array_map('intval', array_values($ids)));
            if (!$conn->query("UPDATE supplier_products SET enabled=0 WHERE id IN ({$idList})")) return false;
            if (!$conn->query("UPDATE supplier_catalog_links SET api_fallback_enabled=0 WHERE supplier_product_id IN ({$idList})")) return false;
        }
        $delete = $conn->prepare('DELETE FROM supplier_catalog_links WHERE local_variant_id=?');
        if (!$delete) return false;
        $delete->bind_param('i', $variantId);
        $ok = $delete->execute();
        $delete->close();
        return $ok;
    };

    try {
        if ($action === 'add') {
            $name = is_scalar($_POST['name'] ?? null) ? trim((string) $_POST['name']) : '';
            $description = is_scalar($_POST['description'] ?? null) ? trim((string) $_POST['description']) : '';
            $downloadUrl = is_scalar($_POST['download_url'] ?? null) ? trim((string) $_POST['download_url']) : '';
            $platform = normalizeProductPlatform($_POST['platform'] ?? '', 'both');
            $categories = $normalizeCategories();
            $category = $categories[0] ?? '';

            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException(productsUiText('กรุณากรอกชื่อสินค้า และต้องไม่เกิน 255 ตัวอักษร', 'Product name is required and must not exceed 255 characters'));
            }
            if (strlen($description) > 10000) {
                throw new InvalidArgumentException(productsUiText('รายละเอียดสินค้ายาวเกินกำหนด', 'Product description is too long'));
            }
            if ($downloadUrl !== '' && (strlen($downloadUrl) > 2048 || !isSafeHttpsUrl($downloadUrl))) {
                throw new InvalidArgumentException(productsUiText('ลิงก์ดาวน์โหลดต้องเป็น URL แบบ HTTPS ที่ถูกต้อง', 'Download URL must be a valid HTTPS URL'));
            }

            $variants = [];
            $variantDurationKeys = [];
            $postedVariants = $_POST['variants'] ?? [];
            if ($postedVariants !== [] && !is_array($postedVariants)) {
                throw new InvalidArgumentException(productsUiText('ข้อมูลรูปแบบสินค้าไม่ถูกต้อง', 'Invalid variant data'));
            }
            if (is_array($postedVariants) && count($postedVariants) > 50) {
                throw new InvalidArgumentException(productsUiText('มีรูปแบบสินค้ามากเกินไปในคำขอเดียว', 'Too many variants in one request'));
            }
            foreach ((array) $postedVariants as $variant) {
                if (!is_array($variant)) continue;
                $duration = is_scalar($variant['duration'] ?? null) ? trim((string) $variant['duration']) : '';
                if ($duration === '') continue;
                $priceUser = parseMoneyInput($variant['price_user'] ?? 0);
                $priceReseller = parseMoneyInput($variant['price_reseller'] ?? 0);
                $costPrice = parseMoneyInput($variant['cost_price'] ?? 0);
                if (strlen($duration) > 100 || preg_match('/[\x00-\x1F\x7F]/', $duration) ||
                    !$validPrice($priceUser) || !$validPrice($priceReseller) || !$validPrice($costPrice)) {
                    throw new InvalidArgumentException(productsUiText('รูปแบบสินค้าอย่างน้อยหนึ่งรายการมีข้อมูลไม่ถูกต้อง', 'One or more variants contain invalid data'));
                }
                $durationKey = function_exists('mb_strtolower') ? mb_strtolower(trim($duration), 'UTF-8') : strtolower(trim($duration));
                if (isset($variantDurationKeys[$durationKey])) {
                    throw new InvalidArgumentException(productsUiText('มีรูปแบบสินค้าระยะเวลาเดียวกันซ้ำในรายการที่ส่งมา: ' . $duration, 'Duplicate variant duration in the submitted product: ' . $duration));
                }
                $variantDurationKeys[$durationKey] = true;
                $variants[] = [$duration, $priceUser, $priceReseller, $costPrice];
            }

            $image = handleProductImageUpload('image_file', '');
            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO products (name, description, image, category, download_url, status) VALUES (?, ?, ?, ?, ?, 'active')");
            if (!$stmt) throw new RuntimeException('Unable to prepare product insert');
            $stmt->bind_param('sssss', $name, $description, $image, $category, $downloadUrl);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to add product');
            }
            $productId = (int) $conn->insert_id;
            $stmt->close();
            if (!$saveCategories($productId, $categories)) throw new RuntimeException('Unable to save product categories');
            if (!setProductPlatform($productId, $platform)) throw new RuntimeException('Unable to save product platform');

            if ($variants) {
                $variantStmt = $conn->prepare('INSERT INTO product_variants (product_id, duration, price_user, price_reseller, cost_price) VALUES (?, ?, ?, ?, ?)');
                if (!$variantStmt) throw new RuntimeException('Unable to prepare variants');
                foreach ($variants as [$duration, $priceUser, $priceReseller, $costPrice]) {
                    $variantStmt->bind_param('isddd', $productId, $duration, $priceUser, $priceReseller, $costPrice);
                    if (!$variantStmt->execute()) {
                        $variantStmt->close();
                        throw new RuntimeException('Unable to add variant');
                    }
                }
                $variantStmt->close();
            }
            $conn->commit();
            $success = Lang::t('admin.products.success.add');
            $postRedirectAnchor = 'product-' . $productId;
            logHistory((int) $_SESSION['user_id'], 'add_product', 'Added product ID: ' . $productId);

        } elseif ($action === 'edit') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $current = getProductById($productId);
            if (!$current || isProductAdminArchived($productId)) {
                throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า หรือสินค้านี้ถูกลบออกจากรายการแล้ว', 'Product not found or already removed from the catalogue'));
            }

            $name = is_scalar($_POST['name'] ?? null) ? trim((string) $_POST['name']) : '';
            $description = is_scalar($_POST['description'] ?? null) ? trim((string) $_POST['description']) : '';
            $downloadUrl = is_scalar($_POST['download_url'] ?? null) ? trim((string) $_POST['download_url']) : '';
            $platform = normalizeProductPlatform($_POST['platform'] ?? '', (string) ($current['platform'] ?? 'both'));
            $categories = $normalizeCategories();
            $category = $categories[0] ?? '';
            if ($name === '' || strlen($name) > 255) throw new InvalidArgumentException(productsUiText('กรุณากรอกชื่อสินค้า และต้องไม่เกิน 255 ตัวอักษร', 'Product name is required and must not exceed 255 characters'));
            if (strlen($description) > 10000) throw new InvalidArgumentException(productsUiText('รายละเอียดสินค้ายาวเกินกำหนด', 'Product description is too long'));
            if ($downloadUrl !== '' && (strlen($downloadUrl) > 2048 || !isSafeHttpsUrl($downloadUrl))) {
                throw new InvalidArgumentException(productsUiText('ลิงก์ดาวน์โหลดต้องเป็น URL แบบ HTTPS ที่ถูกต้อง', 'Download URL must be a valid HTTPS URL'));
            }

            // Never trust old_image from POST. The current path comes from the database.
            $image = handleProductImageUpload('image_file', (string) ($current['image'] ?? ''));
            $conn->begin_transaction();
            $stmt = $conn->prepare('UPDATE products SET name = ?, description = ?, image = ?, category = ?, download_url = ?, updated_at = NOW() WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare product update');
            $stmt->bind_param('sssssi', $name, $description, $image, $category, $downloadUrl, $productId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to update product');
            }
            $stmt->close();
            if (!$saveCategories($productId, $categories)) throw new RuntimeException('Unable to save product categories');
            if (!setProductPlatform($productId, $platform)) throw new RuntimeException('Unable to save product platform');

            // Manual edits in Products are authoritative. Do not let the next
            // supplier catalog refresh silently restore the old API name,
            // description, image or categories over the administrator's work.
            if ($bridgeSchemaReady) {
                $manualDetails = $conn->prepare('UPDATE supplier_catalog_products SET sync_details=0 WHERE local_product_id=?');
                if (!$manualDetails) throw new RuntimeException('Unable to preserve manual Bridge product details');
                $manualDetails->bind_param('i', $productId);
                if (!$manualDetails->execute()) { $manualDetails->close(); throw new RuntimeException('Unable to preserve manual Bridge product details'); }
                $manualDetails->close();
            }
            try {
                $manualCgoDetails = $conn->prepare('UPDATE cgo_catalog_links SET sync_details=0 WHERE local_product_id=?');
                if ($manualCgoDetails) {
                    $manualCgoDetails->bind_param('i', $productId);
                    $manualCgoDetails->execute();
                    $manualCgoDetails->close();
                }
            } catch (Throwable $ignored) {
                // CGO is optional on installations without its integration table.
            }

            $conn->commit();
            $success = Lang::t('admin.products.success.edit');
            $postRedirectAnchor = 'product-' . $productId;
            logHistory((int) $_SESSION['user_id'], 'edit_product', 'Edited product ID: ' . $productId);

        } elseif ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $product = getProductById($productId);
            if (!$product || isProductAdminArchived($productId)) {
                throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า หรือสินค้านี้ถูกลบออกจากรายการแล้ว', 'Product not found or already removed from the catalogue'));
            }
            if (!ensureProductArchiveTables()) throw new RuntimeException('Unable to prepare product archive tables');

            // Product and variant rows are retained deliberately. Purchased-key
            // history joins to these rows, and retaining every key also preserves
            // global duplicate-key protection. Archive metadata removes the item
            // from normal catalogue/admin lists without damaging that evidence.
            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            ensureResellerVariantPricesTable();
            $conn->begin_transaction();

            $archive = $conn->prepare("INSERT INTO product_admin_archives (product_id, archived_by, reason) VALUES (?, ?, 'admin_delete') ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = CURRENT_TIMESTAMP");
            if (!$archive) throw new RuntimeException('Unable to prepare product archive');
            $archive->bind_param('ii', $productId, $adminId);
            if (!$archive->execute()) {
                $archive->close();
                throw new RuntimeException('Unable to archive product');
            }
            $archive->close();

            $archiveVariants = $conn->prepare("INSERT INTO product_variant_admin_archives (variant_id, product_id, archived_by, reason)
                SELECT id, product_id, ?, 'product_delete' FROM product_variants WHERE product_id = ?
                ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = CURRENT_TIMESTAMP");
            if (!$archiveVariants) throw new RuntimeException('Unable to prepare variant archive');
            $archiveVariants->bind_param('ii', $adminId, $productId);
            if (!$archiveVariants->execute()) {
                $archiveVariants->close();
                throw new RuntimeException('Unable to archive product variants');
            }
            $archiveVariants->close();

            if (!$deleteCgoLinksForProduct($productId)) throw new RuntimeException('Unable to remove CGO catalogue mappings');
            if (!$detachBridgeForProduct($productId)) throw new RuntimeException('Unable to remove Store Bridge catalogue mappings');
            if (!$conn->query('DELETE rvp FROM reseller_variant_prices rvp INNER JOIN product_variants pv ON pv.id = rvp.variant_id WHERE pv.product_id = ' . $productId)) {
                throw new RuntimeException('Unable to delete reseller variant prices');
            }

            $disableVariants = $conn->prepare("UPDATE product_variants SET status = 'inactive', updated_at = NOW() WHERE product_id = ?");
            if (!$disableVariants) throw new RuntimeException('Unable to prepare variant archive status');
            $disableVariants->bind_param('i', $productId);
            if (!$disableVariants->execute()) {
                $disableVariants->close();
                throw new RuntimeException('Unable to disable archived variants');
            }
            $disableVariants->close();

            $disableProduct = $conn->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            if (!$disableProduct) throw new RuntimeException('Unable to prepare product archive status');
            $disableProduct->bind_param('i', $productId);
            if (!$disableProduct->execute() || $disableProduct->affected_rows < 0) {
                $disableProduct->close();
                throw new RuntimeException('Unable to archive product');
            }
            $disableProduct->close();

            $conn->commit();
            $success = productsUiText(
                'ลบสินค้าออกจากรายการแล้ว โดยเก็บคีย์และประวัติการขายไว้ทั้งหมด',
                'Product removed from the catalogue while preserving all keys and sales history'
            );
            logHistory($adminId, 'archive_product_preserve_keys', 'Archived product ID: ' . $productId . '; all key rows preserved');

        } elseif ($action === 'toggle_status') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId < 1 || isProductAdminArchived($productId)) {
                throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า หรือสินค้านี้ถูกลบออกจากรายการแล้ว', 'Product not found or already removed from the catalogue'));
            }
            $stmt = $conn->prepare("UPDATE products SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END, updated_at = NOW() WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Unable to prepare status update');
            $stmt->bind_param('i', $productId);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า', 'Product not found'));
            }
            $stmt->close();
            $success = Lang::t('admin.products.success.status');
            $postRedirectAnchor = 'product-' . $productId;
            logHistory((int) $_SESSION['user_id'], 'toggle_product', 'Changed status for product ID: ' . $productId);

        } elseif ($action === 'add_variant') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $duration = is_scalar($_POST['duration'] ?? null) ? trim((string) $_POST['duration']) : '';
            $priceUser = parseMoneyInput($_POST['price_user'] ?? 0);
            $priceReseller = parseMoneyInput($_POST['price_reseller'] ?? 0);
            $costPrice = parseMoneyInput($_POST['cost_price'] ?? 0);
            if ($duration === '' || strlen($duration) > 100 || preg_match('/[\x00-\x1F\x7F]/', $duration)) throw new InvalidArgumentException(Lang::t('admin.products.error.duration_required'));
            if (!$validPrice($priceUser) || !$validPrice($priceReseller) || !$validPrice($costPrice)) throw new InvalidArgumentException(productsUiText('ราคาของรูปแบบสินค้าไม่ถูกต้อง', 'Invalid variant price'));
            if (!getProductById($productId) || isProductAdminArchived($productId)) {
                throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า หรือสินค้านี้ถูกลบออกจากรายการแล้ว', 'Product not found or already removed from the catalogue'));
            }

            $conn->begin_transaction();
            $productLock = $conn->prepare('SELECT id FROM products WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$productLock) throw new RuntimeException('Unable to lock product');
            $productLock->bind_param('i', $productId);
            if (!$productLock->execute()) { $productLock->close(); throw new RuntimeException('Unable to lock product'); }
            $productLock->store_result();
            $productExists = $productLock->num_rows === 1;
            $productLock->close();
            if (!$productExists) throw new InvalidArgumentException(productsUiText('ไม่พบสินค้า', 'Product not found'));

            $dup = $conn->prepare('SELECT pv.id FROM product_variants pv LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id WHERE pv.product_id = ? AND LOWER(TRIM(pv.duration)) = LOWER(TRIM(?)) AND va.variant_id IS NULL LIMIT 1 FOR UPDATE');
            if (!$dup) throw new RuntimeException('Unable to check variant');
            $dup->bind_param('is', $productId, $duration);
            $dup->execute();
            $dup->store_result();
            $exists = $dup->num_rows > 0;
            $dup->close();
            if ($exists) throw new InvalidArgumentException(productsUiText('มีรูปแบบสินค้าที่ใช้ระยะเวลานี้อยู่แล้ว', 'A variant with this duration already exists'));

            $stmt = $conn->prepare('INSERT INTO product_variants (product_id, duration, price_user, price_reseller, cost_price) VALUES (?, ?, ?, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare variant');
            $stmt->bind_param('isddd', $productId, $duration, $priceUser, $priceReseller, $costPrice);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to add variant');
            }
            $newVariantId = (int) $conn->insert_id;
            $stmt->close();
            $conn->commit();
            $success = Lang::t('admin.products.success.variant_add');
            $postRedirectAnchor = 'variant-' . $newVariantId;
            logHistory((int) $_SESSION['user_id'], 'add_variant', 'Added variant ID: ' . $newVariantId);

        } elseif ($action === 'update_variant') {
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $duration = is_scalar($_POST['duration'] ?? null) ? trim((string) $_POST['duration']) : '';
            $priceUser = parseMoneyInput($_POST['price_user'] ?? 0);
            $priceReseller = parseMoneyInput($_POST['price_reseller'] ?? 0);
            $costPrice = parseMoneyInput($_POST['cost_price'] ?? 0);
            if ($duration === '' || strlen($duration) > 100 || preg_match('/[\x00-\x1F\x7F]/', $duration)) throw new InvalidArgumentException(productsUiText('กรุณากรอกระยะเวลา', 'Duration is required'));
            if (!$validPrice($priceUser) || !$validPrice($priceReseller) || !$validPrice($costPrice)) throw new InvalidArgumentException(productsUiText('ราคาของรูปแบบสินค้าไม่ถูกต้อง', 'Invalid variant price'));

            $conn->begin_transaction();
            $lock = $conn->prepare('SELECT pv.product_id,pv.duration,pv.price_user,pv.price_reseller FROM product_variants pv LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id WHERE pv.id = ? AND va.variant_id IS NULL FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to inspect variant');
            $lock->bind_param('i', $variantId);
            $lock->execute();
            $lock->bind_result($variantProductId, $oldDuration, $oldPriceUser, $oldPriceReseller);
            $found = $lock->fetch();
            $lock->close();
            if (!$found) throw new InvalidArgumentException(productsUiText('ไม่พบรูปแบบสินค้า', 'Variant not found'));
            $productLock = $conn->prepare('SELECT id FROM products WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$productLock) throw new RuntimeException('Unable to lock product');
            $productLock->bind_param('i', $variantProductId);
            if (!$productLock->execute()) { $productLock->close(); throw new RuntimeException('Unable to lock product'); }
            $productLock->close();

            $dup = $conn->prepare('SELECT pv.id FROM product_variants pv LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id WHERE pv.product_id = ? AND LOWER(TRIM(pv.duration)) = LOWER(TRIM(?)) AND pv.id <> ? AND va.variant_id IS NULL LIMIT 1 FOR UPDATE');
            if (!$dup) throw new RuntimeException('Unable to check variant');
            $dup->bind_param('isi', $variantProductId, $duration, $variantId);
            $dup->execute();
            $dup->store_result();
            $duplicateDuration = $dup->num_rows > 0;
            $dup->close();
            if ($duplicateDuration) throw new InvalidArgumentException(productsUiText('มีรูปแบบสินค้าที่ใช้ระยะเวลานี้อยู่แล้ว', 'A variant with this duration already exists'));

            $stmt = $conn->prepare('UPDATE product_variants SET duration = ?, price_user = ?, price_reseller = ?, cost_price = ?, updated_at = NOW() WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare variant update');
            $stmt->bind_param('sdddi', $duration, $priceUser, $priceReseller, $costPrice, $variantId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to update variant');
            }
            $stmt->close();

            // Sold keys are historical records. Only unsold inventory follows new prices and duration.
            $keyStmt = $conn->prepare("UPDATE `keys` SET duration = ?, price_user = ?, price_reseller = ?, cost_price = ? WHERE variant_id = ? AND status = 'available' AND assigned_to IS NULL");
            if (!$keyStmt) throw new RuntimeException('Unable to prepare inventory update');
            $keyStmt->bind_param('sdddi', $duration, $priceUser, $priceReseller, $costPrice, $variantId);
            if (!$keyStmt->execute()) {
                $keyStmt->close();
                throw new RuntimeException('Unable to update inventory');
            }
            $keyStmt->close();

            $oldDurationKey = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $oldDuration), 'UTF-8') : strtolower(trim((string) $oldDuration));
            $newDurationKey = function_exists('mb_strtolower') ? mb_strtolower(trim($duration), 'UTF-8') : strtolower(trim($duration));
            $manualPriceChanged = abs((float) $oldPriceUser - $priceUser) > 0.00001
                || abs((float) $oldPriceReseller - $priceReseller) > 0.00001;
            if ($bridgeSchemaReady && $oldDurationKey !== $newDurationKey) {
                $manualDuration = $conn->prepare('UPDATE supplier_catalog_links SET sync_duration=0 WHERE local_variant_id=?');
                if (!$manualDuration) throw new RuntimeException('Unable to preserve manual duration override');
                $manualDuration->bind_param('i', $variantId);
                if (!$manualDuration->execute()) { $manualDuration->close(); throw new RuntimeException('Unable to preserve manual duration override'); }
                $manualDuration->close();
            }
            if ($bridgeSchemaReady && $manualPriceChanged) {
                // A price edited in Products is an explicit local override. Set
                // mapped Bridge rows to KEEP so the next catalog sync cannot
                // silently undo the administrator's price.
                $manualPrice = $conn->prepare("UPDATE supplier_products sp INNER JOIN supplier_catalog_links scl ON scl.supplier_product_id=sp.id SET sp.user_price_mode='keep',sp.reseller_price_mode='keep' WHERE scl.local_variant_id=?");
                if (!$manualPrice) throw new RuntimeException('Unable to preserve manual Bridge price override');
                $manualPrice->bind_param('i', $variantId);
                if (!$manualPrice->execute()) { $manualPrice->close(); throw new RuntimeException('Unable to preserve manual Bridge price override'); }
                $manualPrice->close();
            }
            if ($manualPriceChanged) {
                // CGO has a per-link price-sync switch. Disable only automatic
                // price overwrites for this manually edited variant; stock sync
                // and ordering remain unaffected.
                try {
                    $manualCgoPrice = $conn->prepare('UPDATE cgo_catalog_links SET sync_price=0 WHERE local_variant_id=?');
                    if ($manualCgoPrice) {
                        $manualCgoPrice->bind_param('i', $variantId);
                        $manualCgoPrice->execute();
                        $manualCgoPrice->close();
                    }
                } catch (Throwable $ignored) {
                    // CGO integration is optional on installations without its table.
                }
            }
            $conn->commit();

            $success = productsUiText('อัปเดตรูปแบบสินค้าสำเร็จแล้ว', 'Variant updated successfully');
            $postRedirectAnchor = 'variant-' . $variantId;
            logHistory((int) $_SESSION['user_id'], 'update_variant', 'Updated variant ID: ' . $variantId);

            if (isAjaxRequest()) {
                $postedScrollY = isset($_POST['_return_scroll_y']) && is_scalar($_POST['_return_scroll_y'])
                    ? max(0, min(10000000, (int) $_POST['_return_scroll_y']))
                    : 0;
                setProductsFlash('success', $success, [], 'variant-' . $variantId, $postedScrollY);
                $statsStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available FROM `keys` WHERE variant_id = ?");
                $keyCount = 0;
                $availableKeyCount = 0;
                if ($statsStmt) {
                    $statsStmt->bind_param('i', $variantId);
                    $statsStmt->execute();
                    $statsResult = $statsStmt->get_result();
                    $stats = $statsResult ? $statsResult->fetch_assoc() : null;
                    $keyCount = (int) ($stats['total'] ?? 0);
                    $availableKeyCount = (int) ($stats['available'] ?? 0);
                    $statsStmt->close();
                }
                jsonResponse([
                    'ok' => true,
                    'message' => $success,
                    'action' => 'update_variant',
                    'reload' => true,
                    'redirect' => productsPageUrl('variant-' . $variantId),
                    'variant' => [
                        'id' => $variantId,
                        'duration' => $duration,
                        'price_user' => $priceUser,
                        'price_reseller' => $priceReseller,
                        'cost_price' => $costPrice,
                        'price_user_fmt' => formatCurrency($priceUser),
                        'price_reseller_fmt' => formatCurrency($priceReseller),
                        'total_keys' => $keyCount,
                        'available_keys' => $availableKeyCount,
                    ],
                ]);
            }

        } elseif ($action === 'delete_variant') {
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            if (!ensureProductArchiveTables()) throw new RuntimeException('Unable to prepare product archive tables');
            $stmt = $conn->prepare('SELECT pv.product_id FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id LEFT JOIN product_admin_archives pa ON pa.product_id = p.id WHERE pv.id = ? AND va.variant_id IS NULL AND pa.product_id IS NULL LIMIT 1');
            if (!$stmt) throw new RuntimeException('Unable to inspect variant');
            $stmt->bind_param('i', $variantId);
            $stmt->execute();
            $stmt->bind_result($variantProductId);
            $found = $stmt->fetch();
            $stmt->close();
            if (!$found) throw new InvalidArgumentException(productsUiText('ไม่พบรูปแบบสินค้า หรือรายการนี้ถูกลบแล้ว', 'Variant not found or already removed'));

            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            ensureResellerVariantPricesTable();
            $conn->begin_transaction();
            if (!$deleteCgoLinksForVariant($variantId)) throw new RuntimeException('Unable to remove CGO catalogue mapping');
            if (!$detachBridgeForVariant($variantId)) throw new RuntimeException('Unable to remove Store Bridge catalogue mapping');

            $delPrice = $conn->prepare('DELETE FROM reseller_variant_prices WHERE variant_id = ?');
            if (!$delPrice) throw new RuntimeException('Unable to prepare special-price delete');
            $delPrice->bind_param('i', $variantId);
            if (!$delPrice->execute()) {
                $delPrice->close();
                throw new RuntimeException('Unable to delete special prices');
            }
            $delPrice->close();

            // Keep every key row so sold history and duplicate-key checks remain
            // intact. The inactive archived variant cannot be sold on the store.
            $archive = $conn->prepare("INSERT INTO product_variant_admin_archives (variant_id, product_id, archived_by, reason) VALUES (?, ?, ?, 'admin_delete') ON DUPLICATE KEY UPDATE archived_by = VALUES(archived_by), archived_at = CURRENT_TIMESTAMP");
            if (!$archive) throw new RuntimeException('Unable to prepare variant archive');
            $archive->bind_param('iii', $variantId, $variantProductId, $adminId);
            if (!$archive->execute()) {
                $archive->close();
                throw new RuntimeException('Unable to archive variant');
            }
            $archive->close();

            $disable = $conn->prepare("UPDATE product_variants SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            if (!$disable) throw new RuntimeException('Unable to prepare variant archive status');
            $disable->bind_param('i', $variantId);
            if (!$disable->execute()) {
                $disable->close();
                throw new RuntimeException('Unable to archive variant');
            }
            $disable->close();

            $conn->commit();
            $success = productsUiText(
                'ลบรูปแบบสินค้าออกจากรายการแล้ว โดยเก็บคีย์และประวัติการขายไว้ทั้งหมด',
                'Variant removed while preserving all keys and sales history'
            );
            logHistory($adminId, 'archive_variant_preserve_keys', 'Archived variant ID: ' . $variantId . '; all key rows preserved');
            $postRedirectAnchor = 'product-' . (int) $variantProductId;

        } elseif ($action === 'add_variant_keys') {
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $keyCodesRaw = $_POST['key_codes'] ?? '';
            if (!is_scalar($keyCodesRaw)) throw new InvalidArgumentException(productsUiText('ข้อมูลคีย์ไม่ถูกต้อง', 'Invalid key data'));
            if (strlen((string) $keyCodesRaw) > 1000000) throw new InvalidArgumentException(productsUiText('รายการคีย์มีขนาดใหญ่เกินไป', 'Key list is too large'));

            $variantStmt = $conn->prepare('SELECT pv.product_id, pv.duration, pv.price_user, pv.price_reseller, pv.cost_price FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id LEFT JOIN product_admin_archives pa ON pa.product_id = p.id WHERE pv.id = ? AND va.variant_id IS NULL AND pa.product_id IS NULL LIMIT 1');
            if (!$variantStmt) throw new RuntimeException('Unable to inspect variant');
            $variantStmt->bind_param('i', $variantId);
            $variantStmt->execute();
            $variantResult = $variantStmt->get_result();
            $variant = $variantResult ? $variantResult->fetch_assoc() : null;
            $variantStmt->close();
            if (!$variant) throw new InvalidArgumentException(productsUiText('ไม่พบรูปแบบสินค้า', 'Variant not found'));

            $rawKeys = preg_split('/\R/u', (string) $keyCodesRaw) ?: [];
            $submittedLineCount = 0;
            foreach ($rawKeys as $rawLine) {
                if (trim((string) $rawLine) !== '' && ++$submittedLineCount > 1000) {
                    throw new InvalidArgumentException(productsUiText('มีจำนวนคีย์มากเกินไป เพิ่มได้สูงสุด 1,000 คีย์ต่อครั้ง', 'Too many keys. Maximum 1,000 keys per request.'));
                }
            }
            $uniqueKeys = [];
            $seen = [];
            $duplicateInSubmit = [];
            foreach ($rawKeys as $rawKey) {
                $key = trim((string) $rawKey);
                if ($key === '') continue;
                if (strlen($key) > 500 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
                    throw new InvalidArgumentException(productsUiText('คีย์ยาวเกินไปหรือมีอักขระควบคุมที่ไม่อนุญาต', 'A key is too long or contains control characters'));
                }
                if (isset($seen[$key])) {
                    $duplicateInSubmit[] = $key;
                    continue;
                }
                $seen[$key] = true;
                $uniqueKeys[] = $key;
                if (count($uniqueKeys) > 1000) throw new InvalidArgumentException(productsUiText('เพิ่มคีย์ไม่ซ้ำได้สูงสุด 1,000 คีย์ต่อครั้ง', 'Maximum 1,000 unique keys per request'));
            }
            if (!$uniqueKeys) throw new InvalidArgumentException(productsUiText('กรุณากรอกคีย์อย่างน้อย 1 รายการ', 'Please enter at least one key.'));

            $productId = (int) $variant['product_id'];
            $duration = (string) $variant['duration'];
            $priceUser = (float) $variant['price_user'];
            $priceReseller = (float) $variant['price_reseller'];
            $costPrice = (float) $variant['cost_price'];
            $added = 0;
            $duplicates = $duplicateInSubmit;
            $conn->begin_transaction();
            $insert = $conn->prepare("INSERT INTO `keys` (product_id, variant_id, key_code, duration, price_user, price_reseller, cost_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'available')");
            if (!$insert) throw new RuntimeException('Unable to prepare key insert');
            foreach ($uniqueKeys as $key) {
                $insert->bind_param('iissddd', $productId, $variantId, $key, $duration, $priceUser, $priceReseller, $costPrice);
                if ($insert->execute()) {
                    $added++;
                    continue;
                }
                if ((int) $insert->errno === 1062) {
                    $duplicates[] = $key;
                    continue;
                }
                $errno = (int) $insert->errno;
                $insert->close();
                throw new RuntimeException('Key insert failed with database code ' . $errno);
            }
            $insert->close();
            $conn->commit();

            $duplicates = array_values(array_unique($duplicates));
            $failureDetails = [];
            if ($duplicates) {
                $failureDetails[] = productsUiText('ข้ามคีย์ที่ซ้ำ:', 'Duplicate key(s) skipped:');
                foreach (summarizeKeyList($duplicates, 50) as $duplicateKey) $failureDetails[] = '- ' . $duplicateKey;
            }
            if ($added < 1) {
                $error = productsUiText('ไม่ได้เพิ่มคีย์ เนื่องจากคีย์ทั้งหมดมีอยู่แล้วหรือซ้ำกัน', 'No keys were added. All submitted keys already exist or were duplicated.');
                $postFlashDetails = $failureDetails;
            } else {
                $success = productsUiText('เพิ่มคีย์ให้รูปแบบสินค้าสำเร็จ ' . $added . ' รายการ', $added . ' key(s) added to variant successfully');
                if ($duplicates) $success .= productsUiText(' และข้ามคีย์ซ้ำ ' . count($duplicates) . ' รายการ', '. ' . count($duplicates) . ' duplicate(s) skipped');
                $postFlashDetails = $failureDetails;
                logHistory((int) $_SESSION['user_id'], 'add_variant_keys', 'Added ' . $added . ' keys to variant ID: ' . $variantId);
            }
            $postRedirectAnchor = 'variant-' . $variantId;

            if (isAjaxRequest()) {
                $statsStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available FROM `keys` WHERE variant_id = ?");
                $totalKeys = 0;
                $availableKeys = 0;
                if ($statsStmt) {
                    $statsStmt->bind_param('i', $variantId);
                    $statsStmt->execute();
                    $statsResult = $statsStmt->get_result();
                    $stats = $statsResult ? $statsResult->fetch_assoc() : null;
                    $totalKeys = (int) ($stats['total'] ?? 0);
                    $availableKeys = (int) ($stats['available'] ?? 0);
                    $statsStmt->close();
                }
                if ($added > 0) {
                    $postedScrollY = isset($_POST['_return_scroll_y']) && is_scalar($_POST['_return_scroll_y'])
                        ? max(0, min(10000000, (int) $_POST['_return_scroll_y']))
                        : 0;
                    setProductsFlash('success', $success, $failureDetails, 'variant-' . $variantId, $postedScrollY);
                }
                jsonResponse([
                    'ok' => $added > 0,
                    'message' => $success,
                    'error' => $error,
                    'details' => $failureDetails,
                    'duplicate_keys' => summarizeKeyList($duplicates),
                    'action' => 'add_variant_keys',
                    'reload' => $added > 0,
                    'redirect' => productsPageUrl('variant-' . $variantId),
                    'variant' => ['id' => $variantId, 'total_keys' => $totalKeys, 'available_keys' => $availableKeys],
                ], $added > 0 ? 200 : 422);
            }
        } else {
            throw new InvalidArgumentException(productsUiText('คำสั่งไม่ถูกต้อง', 'Unknown action'));
        }
    } catch (InvalidArgumentException $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $error = $e->getMessage();
        if (isAjaxRequest()) jsonResponse(['ok' => false, 'error' => $error], 422);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Product administration failed: ' . $e->getMessage());
        $error = productsUiText('ไม่สามารถดำเนินการได้ และไม่มีการบันทึกการเปลี่ยนแปลง', 'The operation could not be completed. No changes were saved.');
        if (isAjaxRequest()) jsonResponse(['ok' => false, 'error' => $error], 500);
    }

    if (!isAjaxRequest()) {
        redirectAfterProductsPost($success, $error, $postRedirectAnchor, $postFlashDetails);
    }
}

$productSearch = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
if (strlen($productSearch) > 120) $productSearch = substr($productSearch, 0, 120);
$productCategoryFilter = isset($_GET['category']) && is_scalar($_GET['category']) ? trim((string) $_GET['category']) : '';
$productStatusFilter = isset($_GET['status']) && is_scalar($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
$productPlatformFilter = isset($_GET['platform']) && is_scalar($_GET['platform']) ? strtolower(trim((string) $_GET['platform'])) : '';
$productSort = isset($_GET['sort']) && is_scalar($_GET['sort']) ? strtolower(trim((string) $_GET['sort'])) : 'name_asc';
$productPerPage = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? (int) $_GET['per_page'] : 24;
$productPage = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
if (!in_array($productStatusFilter, ['', 'active', 'inactive'], true)) $productStatusFilter = '';
if (!in_array($productSort, ['name_asc', 'name_desc', 'newest', 'oldest', 'status'], true)) $productSort = 'name_asc';
if (!in_array($productPerPage, [12, 24, 48, 96], true)) $productPerPage = 24;

$productFacets = getAdminProductCatalogueFacets();
$availableProductCategories = (array) ($productFacets['categories'] ?? []);
natcasesort($availableProductCategories);
$availableProductCategories = array_values($availableProductCategories);
$availableProductPlatforms = (array) ($productFacets['platforms'] ?? ['android', 'ios', 'both', 'account']);
$totalProductCount = max(0, (int) ($productFacets['total'] ?? 0));
$platformSortPriority = ['android' => 0, 'ios' => 1, 'both' => 2, 'account' => 3];
usort($availableProductPlatforms, static function (string $a, string $b) use ($platformSortPriority): int {
    $priorityCompare = ($platformSortPriority[$a] ?? 100) <=> ($platformSortPriority[$b] ?? 100);
    return $priorityCompare !== 0 ? $priorityCompare : strnatcasecmp($a, $b);
});
if ($productCategoryFilter !== '' && !in_array($productCategoryFilter, $availableProductCategories, true)) $productCategoryFilter = '';
if ($productPlatformFilter !== '' && !in_array(normalizeProductPlatform($productPlatformFilter, ''), $availableProductPlatforms, true)) $productPlatformFilter = '';

$productPageData = getAdminProductsPage(
    $productSearch,
    $productCategoryFilter,
    $productStatusFilter,
    $productPlatformFilter,
    $productSort,
    $productPage,
    $productPerPage
);
$products = (array) ($productPageData['rows'] ?? []);
$filteredProductCount = max(0, (int) ($productPageData['total'] ?? 0));
$productPage = max(1, (int) ($productPageData['page'] ?? 1));
$productTotalPages = max(1, (int) ($productPageData['pages'] ?? 1));

$productFilterParams = [
    'q' => $productSearch,
    'category' => $productCategoryFilter,
    'status' => $productStatusFilter,
    'platform' => $productPlatformFilter,
    'sort' => $productSort === 'name_asc' ? '' : $productSort,
    'per_page' => $productPerPage === 24 ? '' : (string) $productPerPage,
    'page' => $productPage > 1 ? (string) $productPage : '',
];
$buildProductsQuery = static function (array $overrides = []) use ($productFilterParams): string {
    $params = $productFilterParams;
    foreach ($overrides as $key => $value) $params[$key] = $value;
    $params = array_filter($params, static function ($value): bool { return $value !== '' && $value !== null; });
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
$productsReturnQuery = $buildProductsQuery();

// Get all variants for the current page in one query instead of one query per product.
$productVariants = [];
$productIdsForVariants = [];
foreach ($products as $product) {
    $productId = (int) ($product['id'] ?? 0);
    if ($productId > 0) {
        $productIdsForVariants[$productId] = $productId;
        $productVariants[$productId] = [];
    }
}
if ($productIdsForVariants !== []) {
    $productIdList = implode(',', array_map('intval', array_values($productIdsForVariants)));
    $variantResult = $conn->query(
        "SELECT pv.*
         FROM product_variants pv
         LEFT JOIN product_variant_admin_archives va ON va.variant_id=pv.id
         WHERE pv.product_id IN ({$productIdList}) AND va.variant_id IS NULL
         ORDER BY pv.product_id ASC,pv.price_user ASC,pv.price_reseller ASC,pv.duration ASC"
    );
    if ($variantResult) {
        while ($variant = $variantResult->fetch_assoc()) {
            $productId = (int) ($variant['product_id'] ?? 0);
            if ($productId > 0 && isset($productVariants[$productId])) $productVariants[$productId][] = $variant;
        }
        $variantResult->free();
    }
}

// Prefetch key counts once instead of running two COUNT queries for every rendered variant card.
$variantKeyCounts = [];
$variantIds = [];
foreach ($productVariants as $variants) {
    foreach ($variants as $variant) {
        $variantIds[] = (int) $variant['id'];
    }
}

$variantIds = array_values(array_unique(array_filter($variantIds)));
if (!empty($variantIds)) {
    $variantIdList = implode(',', $variantIds);
    // Checkout deliberately supports legacy key rows whose variant_id is NULL
    // as long as product + duration match. Count them here as well, otherwise
    // Admin can display Local=0 while the storefront can still sell the key.
    $countResult = $conn->query("SELECT pv.id AS variant_id,
            COUNT(k.id) AS total,
            SUM(CASE WHEN k.status = 'available' THEN 1 ELSE 0 END) AS available
        FROM product_variants pv
        LEFT JOIN `keys` k
          ON k.product_id=pv.product_id
         AND k.duration=pv.duration
         AND (k.variant_id=pv.id OR k.variant_id IS NULL)
        WHERE pv.id IN ($variantIdList)
        GROUP BY pv.id");
    if ($countResult) {
        while ($row = $countResult->fetch_assoc()) {
            $variantKeyCounts[(int) $row['variant_id']] = [
                'total' => (int) $row['total'],
                'available' => (int) $row['available'],
            ];
        }
    }
}

// Remote inventory is not represented by rows in `keys`. Keep it separate so
// API-only products do not look out-of-stock in Admin while avoiding the more
// dangerous mistake of pretending remote stock is local key stock. `bridge`
// and `cgo` below are the maximum quantity available from ONE source, because
// checkout intentionally does not split a single order across suppliers.
$variantRemoteInventory = [];
foreach ($variantIds as $variantId) {
    $variantRemoteInventory[(int) $variantId] = [
        'bridge_stock' => 0,
        'bridge_sources' => 0,
        'cgo_stock' => 0,
        'cgo_sources' => 0,
    ];
}

if (!empty($variantIds)) {
    $variantIdList = implode(',', array_map('intval', $variantIds));

    try {
        $bridgeStockResult = $conn->query(
            "SELECT scl.local_variant_id,
                    MAX(CASE
                        WHEN scl.api_fallback_enabled=1
                         AND sp.enabled=1
                         AND sp.supplier_removed_at IS NULL
                         AND sc.status='active'
                        THEN GREATEST(COALESCE(sp.remote_stock,0),0)
                        ELSE 0
                    END) AS bridge_stock,
                    SUM(CASE
                        WHEN scl.api_fallback_enabled=1
                         AND sp.enabled=1
                         AND sp.supplier_removed_at IS NULL
                         AND sc.status='active'
                        THEN 1 ELSE 0
                    END) AS bridge_sources
             FROM supplier_catalog_links scl
             INNER JOIN supplier_products sp ON sp.id=scl.supplier_product_id
             INNER JOIN supplier_connections sc ON sc.id=sp.connection_id
             WHERE scl.local_variant_id IN ({$variantIdList})
             GROUP BY scl.local_variant_id"
        );
        if ($bridgeStockResult) {
            while ($row = $bridgeStockResult->fetch_assoc()) {
                $variantId = (int) ($row['local_variant_id'] ?? 0);
                if ($variantId < 1 || !isset($variantRemoteInventory[$variantId])) continue;
                $variantRemoteInventory[$variantId]['bridge_stock'] = max(0, (int) ($row['bridge_stock'] ?? 0));
                $variantRemoteInventory[$variantId]['bridge_sources'] = max(0, (int) ($row['bridge_sources'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        // Bridge is optional on older installations. Product management must
        // remain usable even if these integration tables have not been created.
        error_log('Admin products Bridge stock summary unavailable: ' . $e->getMessage());
    }

    try {
        $cgoStockResult = $conn->query(
            "SELECT ccl.local_variant_id,
                    MAX(CASE
                        WHEN ccl.api_fallback_enabled=1
                         AND cp.enabled=1
                         AND cp.supplier_removed_at IS NULL
                        THEN GREATEST(COALESCE(cp.remote_stock,0),0)
                        ELSE 0
                    END) AS cgo_stock,
                    SUM(CASE
                        WHEN ccl.api_fallback_enabled=1
                         AND cp.enabled=1
                         AND cp.supplier_removed_at IS NULL
                        THEN 1 ELSE 0
                    END) AS cgo_sources
             FROM cgo_catalog_links ccl
             INNER JOIN cgo_products cp ON cp.id=ccl.cgo_product_id
             WHERE ccl.local_variant_id IN ({$variantIdList})
             GROUP BY ccl.local_variant_id"
        );
        if ($cgoStockResult) {
            while ($row = $cgoStockResult->fetch_assoc()) {
                $variantId = (int) ($row['local_variant_id'] ?? 0);
                if ($variantId < 1 || !isset($variantRemoteInventory[$variantId])) continue;
                $variantRemoteInventory[$variantId]['cgo_stock'] = max(0, (int) ($row['cgo_stock'] ?? 0));
                $variantRemoteInventory[$variantId]['cgo_sources'] = max(0, (int) ($row['cgo_sources'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        error_log('Admin products CGO stock summary unavailable: ' . $e->getMessage());
    }
}

// Get product to edit
$editProduct = null;
if ($editProductId) {
    $editProduct = getProductById($editProductId);
    if ($editProduct && isProductAdminArchived((int) $editProduct['id'])) $editProduct = null;
}

// Prefill categories (1..4)
$editCategories = [];
if ($editProduct) {
    $editCategories = getProductCategories($editProduct['id']);
    if (empty($editCategories) && !empty($editProduct['category'])) {
        $editCategories = [$editProduct['category']];
    }
    $editCategories = array_slice($editCategories, 0, 4);
}

$pageLang = getAppLang();
if (!in_array($pageLang, ['th', 'en'], true)) {
    $pageLang = 'th';
}

?>
<!DOCTYPE html>

<html lang="<?php echo htmlspecialchars($pageLang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script>
        // Must be defined before nav.php loads lang.js. Otherwise stale
        // localStorage can override the language selected in PHP/session.
        window.PHP_LANG = <?php echo json_encode($pageLang, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <title><?php echo htmlspecialchars(Lang::t('admin.products.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>

    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">


    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}</style>

    <style>
        .glass {

            background: rgba(255, 255, 255, 0.05);

            backdrop-filter: blur(16px);

            -webkit-backdrop-filter: blur(16px);

            border: 1px solid rgba(255, 255, 255, 0.08);

        }

        @keyframes fadeInUp {

            from {

                opacity: 0;

                transform: translateY(30px);

            }

            to {

                opacity: 1;

                transform: translateY(0);

            }

        }

        .animate-fade-in {

            animation: fadeInUp .15s ease-out;

        }

        .variant-price {

            transition: all .15s ease;

        }

        .variant-price:hover {

            background: rgba(99, 102, 241, 0.1);

            transform: translateY(-2px);

        }

        .products-scroll-target {
            scroll-margin-top: 90px;
        }

        .products-highlight {
            box-shadow: 0 0 0 2px rgba(236, 72, 153, 0.65), 0 0 28px rgba(236, 72, 153, 0.25);
            transition: box-shadow .15s ease;
        }

        .edit-btn {

            opacity: 1;

            transition: opacity .15s ease;

        }

        /* boleh hapus ini */

        .variant-item:hover .edit-btn {

            opacity: 1;

        }
    </style>

</head>

<body class="bg-darkbg text-gray-100 min-h-screen">

    <?php include 'nav.php'; ?>



    <main class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 md:space-y-6">

        <!-- Header -->

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 md:mb-6 gap-2">
            <h3 class="text-xl md:text-2xl font-bold text-white"><i class="bi bi-box-seam mr-2"></i><span data-lang="admin.products.title">Manage Products</span></h3>
            <button type="button" onclick="openAddModal()"
                class="bg-green-500 hover:opacity-90 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg font-medium transition text-sm md:text-base w-full md:w-auto">
                <i class="bi bi-plus-circle mr-1 md:mr-2"></i><span data-lang="admin.products.add_btn">Add Product</span>
            </button>
        </div>



        <div id="toastContainer" class="fixed top-4 right-4 left-4 md:left-auto z-[9999] space-y-2 pointer-events-none"></div>
        <script>
            window.PRODUCTS_FLASH = <?php echo json_encode([
                'success' => $success,
                'error' => $error,
                'details' => $flashDetails,
                'return_anchor' => $flashReturnAnchor,
                'return_scroll_y' => $flashReturnScrollY,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        </script>

<?php if ($error || $success): ?>
    <div id="serverFlashToast"
        class="fixed top-4 right-4 left-4 md:left-auto z-[10000] pointer-events-auto glass border rounded-lg p-3 md:p-4 shadow-lg text-sm md:text-base max-w-xl ml-auto <?php echo $error ? 'border-red-500/50 bg-red-900/90 text-red-100' : 'border-green-500/50 bg-green-900/90 text-green-100'; ?>">
        <div class="flex gap-3 items-start">
            <div class="flex-1">
                <div class="font-semibold mb-0.5"><?php echo htmlspecialchars($error ? Lang::t('common.error') : Lang::t('common.success'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <div><?php echo htmlspecialchars($error ?: $success); ?></div>
                <?php if (!empty($flashDetails)): ?>
                    <ul class="mt-2 list-disc list-inside text-xs md:text-sm text-gray-100 space-y-1 max-h-48 overflow-y-auto">
                        <?php foreach ($flashDetails as $detail): ?>
                            <li><?php echo htmlspecialchars($detail); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <button type="button" class="w-11 h-11 min-w-11 flex items-center justify-center text-gray-200 hover:text-white text-2xl leading-none rounded-lg" aria-label="<?php echo htmlspecialchars(Lang::t('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onclick="this.closest('#serverFlashToast').remove()">&times;</button>
        </div>
    </div>

    <script>
        setTimeout(() => {
            document.getElementById('serverFlashToast')?.remove();
        }, 3000);
    </script>
<?php endif; ?>

        <!-- Alerts -->

        <?php if ($error): ?>

            <div
                class="glass border border-red-500/50 p-3 md:p-4 rounded-lg bg-red-900/20 text-red-300 text-sm md:text-base">

                <?php echo htmlspecialchars($error); ?>
                <?php if (!empty($flashDetails)): ?>
                    <ul class="mt-2 list-disc list-inside space-y-1">
                        <?php foreach ($flashDetails as $detail): ?>
                            <li><?php echo htmlspecialchars($detail); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

            </div>

        <?php endif; ?>



        <?php if ($success): ?>

            <div
                class="glass border border-green-500/50 p-3 md:p-4 rounded-lg bg-green-900/20 text-green-300 text-sm md:text-base">

                <?php echo htmlspecialchars($success); ?>
                <?php if (!empty($flashDetails)): ?>
                    <ul class="mt-2 list-disc list-inside space-y-1">
                        <?php foreach ($flashDetails as $detail): ?>
                            <li><?php echo htmlspecialchars($detail); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

            </div>

        <?php endif; ?>



        <div id="adminProductsLivePanel" data-instant-panel class="space-y-4">
        <section id="product-filters" class="glass rounded-xl border border-white/10 p-3 md:p-4 sticky top-2 z-20 bg-[#111217]/95 backdrop-blur-xl">
            <form method="GET" action="products.php#product-filters" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-2 md:gap-3 items-end">
                <label class="xl:col-span-2 text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('ค้นหาสินค้า / รูปแบบ / เลข ID', 'Search product / variant / ID'), ENT_QUOTES, 'UTF-8'); ?>
                    <input type="search" name="q" value="<?php echo htmlspecialchars($productSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="AORUS, 30 DAYS, #125" class="mt-1 w-full min-h-11 bg-black/25 border border-white/10 rounded-lg px-3 py-2 text-white">
                </label>
                <label class="text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('หมวดหมู่', 'Category'), ENT_QUOTES, 'UTF-8'); ?>
                    <select name="category" class="mt-1 w-full min-h-11 bg-black/25 border border-white/10 rounded-lg px-3 py-2 text-white">
                        <option value=""><?php echo htmlspecialchars(productsUiText('ทุกหมวดหมู่', 'All categories'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php foreach ($availableProductCategories as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $productCategoryFilter === $categoryOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('สถานะ', 'Status'), ENT_QUOTES, 'UTF-8'); ?>
                    <select name="status" class="mt-1 w-full min-h-11 bg-black/25 border border-white/10 rounded-lg px-3 py-2 text-white">
                        <option value=""><?php echo htmlspecialchars(productsUiText('ทุกสถานะ', 'All statuses'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="active" <?php echo $productStatusFilter === 'active' ? 'selected' : ''; ?>><?php echo htmlspecialchars(productsUiText('เปิดใช้งาน', 'Active'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="inactive" <?php echo $productStatusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo htmlspecialchars(productsUiText('ปิดใช้งาน', 'Inactive'), ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                </label>
                <label class="text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('แพลตฟอร์ม', 'Platform'), ENT_QUOTES, 'UTF-8'); ?>
                    <select name="platform" class="mt-1 w-full min-h-11 bg-black/25 border border-white/10 rounded-lg px-3 py-2 text-white">
                        <option value=""><?php echo htmlspecialchars(productsUiText('ทุกแพลตฟอร์ม', 'All platforms'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php foreach ($availableProductPlatforms as $platformOption): ?>
                            <option value="<?php echo htmlspecialchars($platformOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $productPlatformFilter === $platformOption ? 'selected' : ''; ?>><?php echo htmlspecialchars(strtoupper($platformOption), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('เรียงลำดับ', 'Sort'), ENT_QUOTES, 'UTF-8'); ?>
                    <select name="sort" class="mt-1 w-full min-h-11 bg-black/25 border border-white/10 rounded-lg px-3 py-2 text-white">
                        <option value="name_asc" <?php echo $productSort === 'name_asc' ? 'selected' : ''; ?>>A → Z</option>
                        <option value="name_desc" <?php echo $productSort === 'name_desc' ? 'selected' : ''; ?>>Z → A</option>
                        <option value="newest" <?php echo $productSort === 'newest' ? 'selected' : ''; ?>><?php echo htmlspecialchars(productsUiText('ใหม่สุด', 'Newest'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="oldest" <?php echo $productSort === 'oldest' ? 'selected' : ''; ?>><?php echo htmlspecialchars(productsUiText('เก่าสุด', 'Oldest'), ENT_QUOTES, 'UTF-8'); ?></option>
                        <option value="status" <?php echo $productSort === 'status' ? 'selected' : ''; ?>><?php echo htmlspecialchars(productsUiText('ตามสถานะ', 'By status'), ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                </label>
                <div class="md:col-span-2 xl:col-span-6 flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
                    <div class="text-xs text-gray-400">
                        <?php echo htmlspecialchars(productsUiText('พบ', 'Showing'), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="text-white font-semibold"><?php echo number_format($filteredProductCount); ?></span>
                        / <?php echo number_format($totalProductCount); ?>
                        <?php echo htmlspecialchars(productsUiText('สินค้า', 'products'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <label class="text-xs text-gray-400 flex items-center gap-2">
                            <?php echo htmlspecialchars(productsUiText('ต่อหน้า', 'Per page'), ENT_QUOTES, 'UTF-8'); ?>
                            <select name="per_page" class="min-h-10 bg-black/25 border border-white/10 rounded-lg px-2 py-1 text-white">
                                <?php foreach ([12, 24, 48, 96] as $perPageOption): ?><option value="<?php echo $perPageOption; ?>" <?php echo $productPerPage === $perPageOption ? 'selected' : ''; ?>><?php echo $perPageOption; ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <button class="min-h-10 rounded-lg bg-green-500 hover:bg-green-600 px-4 py-2 text-white"><i class="bi bi-search mr-1"></i><?php echo htmlspecialchars(productsUiText('ค้นหา', 'Search'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <a href="products.php#product-filters" class="min-h-10 inline-flex items-center rounded-lg bg-white/10 hover:bg-white/15 px-4 py-2 text-gray-200"><?php echo htmlspecialchars(productsUiText('ล้างตัวกรอง', 'Reset'), ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                </div>
            </form>
        </section>

        <!-- Products Grid -->

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">

            <?php if (empty($products)): ?>
                <div class="col-span-full">
                    <div class="glass border border-blue-500/50 p-6 md:p-8 rounded-lg text-center text-gray-400">
                        <i class="bi bi-box-seam text-3xl md:text-4xl mb-2 md:mb-3"></i>
                        <p class="text-sm md:text-base"><?php echo htmlspecialchars($totalProductCount > 0 ? productsUiText('ไม่พบสินค้าที่ตรงกับตัวกรอง', 'No products match the current filters.') : productsUiText('ยังไม่มีสินค้า กรุณาเพิ่มสินค้าแรก', 'No products found. Add your first product!'), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            <?php else: ?>

                <?php foreach ($products as $index => $product): ?>

                    <div id="product-<?php echo (int) $product['id']; ?>"
                        data-product-id="<?php echo (int) $product['id']; ?>"
                        class="products-scroll-target glass rounded-lg md:rounded-xl overflow-hidden hover:shadow-glow transition animate-fade-in"
                        style="animation-delay: <?php echo min($index, 12) * 0.04; ?>s;">

                        <div class="p-4 md:p-6">

                            <div class="flex items-start mb-3">

                                <?php if ($product['image']): ?>

                                    <?php

                                    $imgRaw = $product['image'];

                                    if (preg_match('/^(https?:\/\/|data:|\/\/)/i', $imgRaw)) {

                                        $imgSrc = $imgRaw;

                                    } else {

                                        $imgSrc = '../' . ltrim($imgRaw, '/');

                                    }

                                    ?>

                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                        alt="<?php echo htmlspecialchars($product['name']); ?>"
                                        loading="lazy" decoding="async"
                                        class="w-12 h-12 md:w-14 md:h-14 rounded-lg object-cover mr-3">

                                <?php else: ?>

                                    <i class="bi bi-box-seam text-green-400 text-2xl md:text-3xl mr-3"></i>

                                <?php endif; ?>

                                <div class="flex-1 min-w-0">

                                    <h5 class="font-bold text-white mb-1 text-sm md:text-base truncate">

                                        <?php echo htmlspecialchars($product['name']); ?>

                                    </h5>

                                    <div class="flex flex-wrap gap-1 mb-1">

                                        <?php if ($product['status'] == 'active'): ?>
                                            <span
                                                class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400" data-lang="admin.products.status.active">Active</span>
                                        <?php else: ?>
                                            <span
                                                class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-gray-500/20 text-gray-400" data-lang="admin.products.status.inactive">Inactive</span>
                                        <?php endif; ?>

                                        <?php
                                        $productPlatform = normalizeProductPlatform($product['platform'] ?? '', 'both');
                                        $platformDisplay = [
                                            'android' => ['Android', 'bg-green-500/20 text-green-300', 'bi-android2'],
                                            'ios' => ['iOS', 'bg-sky-500/20 text-sky-300', 'bi-apple'],
                                            'both' => ['Android + iOS', 'bg-violet-500/20 text-violet-300', 'bi-phone'],
                                            'account' => ['Account', 'bg-amber-500/20 text-amber-300', 'bi-person-badge'],
                                        ];
                                        $platformMeta = $platformDisplay[$productPlatform] ?? [
                                            ucwords(str_replace(['_', '-'], ' ', $productPlatform)),
                                            'bg-slate-500/20 text-slate-300',
                                            'bi-box',
                                        ];
                                        ?>
                                        <span class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium <?php echo $platformMeta[1]; ?>">
                                            <i class="bi <?php echo $platformMeta[2]; ?> mr-1"></i><?php echo htmlspecialchars($platformMeta[0], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>

                                        <?php foreach ((array) ($product['categories'] ?? []) as $categoryBadge): ?>
                                            <?php if (trim((string) $categoryBadge) === '') continue; ?>
                                            <span class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-blue-500/20 text-blue-400">
                                                <?php echo htmlspecialchars((string) $categoryBadge, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endforeach; ?>

                                    </div>

                                </div>

                            </div>

                            <p class="text-gray-400 text-xs md:text-sm mb-3 line-clamp-2">

                                <?php echo htmlspecialchars($product['description']); ?>

                            </p>

                            <p class="text-gray-500 text-xs mb-0"><i class="bi bi-calendar-event mr-1"></i><span data-lang="admin.products.added_on">Added:</span>
                                <?php echo $pageLang === 'th' ? date('d/m/Y', strtotime($product['created_at'])) : date('M d, Y', strtotime($product['created_at'])); ?>
                            </p>

                        </div>



                        <!-- Variants Section -->

                        <div class="px-4 md:px-6 pb-4 md:pb-6">

                            <!-- Mobile: collapsible variants (hemat ruang) -->

                            <div class="md:hidden">

                                <details class="glass border border-white/10 rounded-lg overflow-hidden">

                                    <summary class="list-none cursor-pointer px-4 py-3 flex items-center justify-between">

                                        <span class="text-gray-300 text-sm font-semibold flex items-center gap-2">

                                            <i class="bi bi-layers"></i> <span data-lang="admin.products.variants">Variants</span>

                                        </span>

                                        <span class="text-xs text-gray-400 flex items-center gap-2">

                                            <span class="px-2 py-1 rounded bg-white/5 border border-white/10">

                                                <?php echo !empty($productVariants[$product['id']]) ? count($productVariants[$product['id']]) : 0; ?>
                                                item

                                            </span>

                                            <i class="bi bi-chevron-down"></i>

                                        </span>

                                    </summary>

                                    <div class="px-4 pb-4">



                                        <div class="flex justify-between items-center mb-3">
                                            <h6 class="text-gray-400 text-xs md:text-sm font-medium" data-lang="admin.products.variants">Variants:</h6>
                                            <button type="button"
                                                class="text-xs px-2 py-1 rounded bg-yellow-500/20 text-yellow-400 hover:bg-yellow-500/30 transition flex items-center gap-1"
                                                onclick="openAddVariantModal(<?php echo (int) $product['id']; ?>, <?php echo jsArg($product['name']); ?>)">
                                                <i class="bi bi-plus-circle text-xs"></i>
                                                <span data-lang="admin.products.add_variant_btn">Add Variant</span>
                                            </button>
                                        </div>



                                        <?php if (!empty($productVariants[$product['id']])): ?>

                                            <div class="space-y-2">

                                                <?php foreach ($productVariants[$product['id']] as $variant): ?>

                                                    <div id="variant-mobile-<?php echo (int) $variant['id']; ?>" data-variant-id="<?php echo (int) $variant['id']; ?>"
                                                        class="variant-item products-scroll-target glass border border-white/10 rounded-lg p-2 hover:border-accent/30 transition-all">

                                                        <div class="flex justify-between items-start mb-1">

                                                            <div class="flex-1">

                                                                <div class="flex items-center gap-2 mb-0.5">

                                                                    <span class="text-white text-xs md:text-sm font-medium">
                                                                        <?php echo htmlspecialchars($variant['duration']); ?>
                                                                    </span>

                                                                    <button type="button"
                                                                        class="edit-btn text-xs px-1.5 py-0.5 rounded bg-pink-500/20 text-pink-400 hover:bg-pink-500/30 transition"
                                                                        onclick="openAddVariantKeysModal(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($product['name']); ?>, <?php echo jsArg($variant['duration']); ?>)"
                                                                        data-lang-title="admin.products.add_keys"
                                                                        title="<?php echo Lang::t('admin.products.add_keys'); ?>">
                                                                        <i class="bi bi-plus-circle text-xs"></i> <span data-lang="admin.products.add_keys">Keys</span>
                                                                    </button>

                                                                </div>



                                                                <div class="grid grid-cols-2 gap-1">
                                                                    <div
                                                                        class="variant-price bg-green-500/5 border border-green-500/20 rounded px-2 py-1.5">
                                                                        <div class="text-[11px] text-gray-400 leading-tight" data-lang="common.user">User</div>
                                                                        <div class="text-green-400 font-bold text-xs leading-tight">
                                                                            <?php echo formatCurrency($variant['price_user']); ?>
                                                                        </div>
                                                                    </div>
                                                                    <div
                                                                        class="variant-price bg-blue-500/5 border border-blue-500/20 rounded px-2 py-1.5">
                                                                        <div class="text-[11px] text-gray-400 leading-tight" data-lang="common.reseller">Reseller</div>
                                                                        <div class="text-blue-400 font-bold text-xs leading-tight">
                                                                            <?php echo formatCurrency($variant['price_reseller']); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                            </div>



                                                            <!-- Action Buttons -->

                                                            <div class="flex flex-col gap-1 ml-2">

                                                                <button type="button"
                                                                    class="edit-btn p-1.5 rounded-lg hover:bg-blue-500/20 text-blue-400 transition"
                                                                    onclick="openEditVariantModal(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($variant['duration']); ?>, <?php echo (float) $variant['price_user']; ?>, <?php echo (float) $variant['price_reseller']; ?>, <?php echo (float) ($variant['cost_price'] ?? 0); ?>, <?php echo jsArg($product['name']); ?>)"
                                                                    data-lang-title="common.edit"
                                                                    title="<?php echo Lang::t('common.edit'); ?>">

                                                                    <i class="bi bi-pencil text-xs"></i>

                                                                </button>

                                                                <button type="button"
                                                                    class="edit-btn p-1.5 rounded-lg hover:bg-red-500/20 text-red-400 transition"
                                                                    onclick="deleteVariant(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($variant['duration']); ?>)"
                                                                    data-lang-title="common.delete"
                                                                    title="<?php echo Lang::t('common.delete'); ?>">

                                                                    <i class="bi bi-trash text-xs"></i>

                                                                </button>

                                                            </div>

                                                        </div>



                                                        <!-- Key Count -->

                                                        <?php



                                                        $keyStats = $variantKeyCounts[(int) $variant['id']] ?? ['total' => 0, 'available' => 0];


                                                        $keyCount = $keyStats['total'];


                                                        $availableKeyCount = $keyStats['available'];
                                                        $remoteStats = $variantRemoteInventory[(int) $variant['id']] ?? [
                                                            'bridge_stock' => 0,
                                                            'bridge_sources' => 0,
                                                            'cgo_stock' => 0,
                                                            'cgo_sources' => 0,
                                                        ];
                                                        $bridgeRemoteStock = (int) ($remoteStats['bridge_stock'] ?? 0);
                                                        $bridgeRemoteSources = (int) ($remoteStats['bridge_sources'] ?? 0);
                                                        $cgoRemoteStock = (int) ($remoteStats['cgo_stock'] ?? 0);
                                                        $cgoRemoteSources = (int) ($remoteStats['cgo_sources'] ?? 0);
                                                        $maxSellableSourceStock = max($availableKeyCount, $bridgeRemoteStock, $cgoRemoteStock);



                                                        ?>

                                                        <div class="text-xs text-gray-500 mt-1 flex justify-between">
                                                            <span><span data-lang="admin.products.variant.total_keys">Total Keys:</span> <span class="text-white">
                                                                    <?php echo $keyCount; ?>
                                                                </span></span>
                                                            <span><span data-lang="admin.products.variant.available_keys">Available:</span> <span
                                                                    class="<?php echo $availableKeyCount > 0 ? 'text-green-400' : 'text-red-400'; ?>">
                                                                    <?php echo $availableKeyCount; ?>
                                                                </span></span>
                                                        </div>
                                                        <?php if ($bridgeRemoteSources > 0 || $cgoRemoteSources > 0): ?>
                                                            <div class="mt-2 rounded-lg border border-indigo-500/15 bg-indigo-500/5 px-2.5 py-2 text-[11px] text-gray-400 space-y-1">
                                                                <?php if ($bridgeRemoteSources > 0): ?>
                                                                    <div class="flex items-center justify-between gap-2"><span>Bridge API <span class="text-gray-600">(<?php echo $bridgeRemoteSources; ?> source<?php echo $bridgeRemoteSources === 1 ? '' : 's'; ?>)</span></span><span class="font-semibold <?php echo $bridgeRemoteStock > 0 ? 'text-cyan-300' : 'text-red-300'; ?>"><?php echo $bridgeRemoteStock; ?></span></div>
                                                                <?php endif; ?>
                                                                <?php if ($cgoRemoteSources > 0): ?>
                                                                    <div class="flex items-center justify-between gap-2"><span>CGO API <span class="text-gray-600">(<?php echo $cgoRemoteSources; ?> source<?php echo $cgoRemoteSources === 1 ? '' : 's'; ?>)</span></span><span class="font-semibold <?php echo $cgoRemoteStock > 0 ? 'text-cyan-300' : 'text-red-300'; ?>"><?php echo $cgoRemoteStock; ?></span></div>
                                                                <?php endif; ?>
                                                                <div class="pt-1 border-t border-white/5 flex items-center justify-between gap-2"><span><?php echo htmlspecialchars(productsUiText('ขายได้สูงสุดจากแหล่งเดียว', 'Max from one source'), ENT_QUOTES, 'UTF-8'); ?></span><span class="font-bold <?php echo $maxSellableSourceStock > 0 ? 'text-green-300' : 'text-red-300'; ?>"><?php echo $maxSellableSourceStock; ?></span></div>
                                                                <div class="text-[10px] text-gray-600"><?php echo htmlspecialchars(productsUiText('สต็อก API แยกจากคีย์ในร้าน และไม่นำมาบวกรวมกันเพื่อป้องกันการแสดงจำนวนที่ซื้อจริงไม่ได้', 'API inventory is separate from local keys and is not summed across sources.'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                            </div>
                                                        <?php endif; ?>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-3 text-gray-400 text-xs">
                                                <i class="bi bi-info-circle mr-1"></i>
                                                <span data-lang="admin.products.no_variants">No variants added yet</span>
                                            </div>

                                        <?php endif; ?>



                                    </div>

                                </details>

                            </div>



                            <!-- Desktop: show variants normally -->

                            <div class="hidden md:block">



                                <div class="flex justify-between items-center mb-3">
                                    <h6 class="text-gray-400 text-xs md:text-sm font-medium" data-lang="admin.products.variants">Variants:</h6>
                                    <button type="button"
                                        class="text-xs px-2 py-1 rounded bg-yellow-500/20 text-yellow-400 hover:bg-yellow-500/30 transition flex items-center gap-1"
                                        onclick="openAddVariantModal(<?php echo (int) $product['id']; ?>, <?php echo jsArg($product['name']); ?>)">
                                        <i class="bi bi-plus-circle text-xs"></i>
                                        <span data-lang="admin.products.add_variant_btn">Add Variant</span>
                                    </button>
                                </div>



                                <?php if (!empty($productVariants[$product['id']])): ?>

                                    <div class="space-y-2">

                                        <?php foreach ($productVariants[$product['id']] as $variant): ?>

                                            <div id="variant-<?php echo (int) $variant['id']; ?>" data-variant-id="<?php echo (int) $variant['id']; ?>"
                                                class="variant-item products-scroll-target glass border border-white/10 rounded-lg p-2 hover:border-accent/30 transition-all">

                                                <div class="flex justify-between items-start mb-1">

                                                    <div class="flex-1">

                                                        <div class="flex items-center gap-2 mb-0.5">

                                                            <span class="text-white text-xs md:text-sm font-medium">
                                                                <?php echo htmlspecialchars($variant['duration']); ?>
                                                            </span>

                                                            <button type="button"
                                                                class="edit-btn text-xs px-1.5 py-0.5 rounded bg-pink-500/20 text-pink-400 hover:bg-pink-500/30 transition"
                                                                onclick="openAddVariantKeysModal(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($product['name']); ?>, <?php echo jsArg($variant['duration']); ?>)"
                                                                data-lang-title="admin.products.add_keys"
                                                                title="<?php echo Lang::t('admin.products.add_keys'); ?>">
                                                                <i class="bi bi-plus-circle text-xs"></i> <span data-lang="admin.products.add_keys">Keys</span>
                                                            </button>

                                                        </div>



                                                        <!-- Price Display -->

                                                        <div class="grid grid-cols-2 gap-1">
                                                            <div
                                                                class="variant-price bg-green-500/5 border border-green-500/20 rounded px-2 py-1.5">
                                                                <div class="text-[11px] text-gray-400 leading-tight" data-lang="common.user">User</div>
                                                                <div class="text-green-400 font-bold text-xs leading-tight">
                                                                    <?php echo formatCurrency($variant['price_user']); ?>
                                                                </div>
                                                            </div>
                                                            <div
                                                                class="variant-price bg-blue-500/5 border border-blue-500/20 rounded px-2 py-1.5">
                                                                <div class="text-[11px] text-gray-400 leading-tight" data-lang="common.reseller">Reseller</div>
                                                                <div class="text-blue-400 font-bold text-xs leading-tight">
                                                                    <?php echo formatCurrency($variant['price_reseller']); ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                    </div>



                                                    <!-- Action Buttons -->

                                                    <div class="flex flex-col gap-1 ml-2">

                                                        <button type="button"
                                                            class="edit-btn p-1.5 rounded-lg hover:bg-blue-500/20 text-blue-400 transition"
                                                            onclick="openEditVariantModal(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($variant['duration']); ?>, <?php echo (float) $variant['price_user']; ?>, <?php echo (float) $variant['price_reseller']; ?>, <?php echo (float) ($variant['cost_price'] ?? 0); ?>, <?php echo jsArg($product['name']); ?>)"
                                                            data-lang-title="common.edit"
                                                            title="<?php echo Lang::t('common.edit'); ?>">

                                                            <i class="bi bi-pencil text-xs"></i>

                                                        </button>

                                                        <button type="button"
                                                            class="edit-btn p-1.5 rounded-lg hover:bg-red-500/20 text-red-400 transition"
                                                            onclick="deleteVariant(<?php echo (int) $variant['id']; ?>, <?php echo jsArg($variant['duration']); ?>)"
                                                            data-lang-title="common.delete"
                                                            title="<?php echo Lang::t('common.delete'); ?>">

                                                            <i class="bi bi-trash text-xs"></i>

                                                        </button>

                                                    </div>

                                                </div>



                                                <!-- Key Count -->

                                                <?php



                                                $keyStats = $variantKeyCounts[(int) $variant['id']] ?? ['total' => 0, 'available' => 0];


                                                $keyCount = $keyStats['total'];


                                                $availableKeyCount = $keyStats['available'];
                                                $remoteStats = $variantRemoteInventory[(int) $variant['id']] ?? [
                                                    'bridge_stock' => 0,
                                                    'bridge_sources' => 0,
                                                    'cgo_stock' => 0,
                                                    'cgo_sources' => 0,
                                                ];
                                                $bridgeRemoteStock = (int) ($remoteStats['bridge_stock'] ?? 0);
                                                $bridgeRemoteSources = (int) ($remoteStats['bridge_sources'] ?? 0);
                                                $cgoRemoteStock = (int) ($remoteStats['cgo_stock'] ?? 0);
                                                $cgoRemoteSources = (int) ($remoteStats['cgo_sources'] ?? 0);
                                                $maxSellableSourceStock = max($availableKeyCount, $bridgeRemoteStock, $cgoRemoteStock);



                                                ?>

                                                <div class="text-xs text-gray-500 mt-1 flex justify-between">
                                                    <span><span data-lang="admin.products.total_keys">Total Keys:</span> <span class="text-white">
                                                            <?php echo $keyCount; ?>
                                                        </span></span>
                                                    <span><span data-lang="admin.products.available_keys">Available:</span> <span
                                                            class="<?php echo $availableKeyCount > 0 ? 'text-green-400' : 'text-red-400'; ?>">
                                                            <?php echo $availableKeyCount; ?>
                                                        </span></span>
                                                </div>
                                                <?php if ($bridgeRemoteSources > 0 || $cgoRemoteSources > 0): ?>
                                                    <div class="mt-2 rounded-lg border border-indigo-500/15 bg-indigo-500/5 px-2.5 py-2 text-[11px] text-gray-400 space-y-1">
                                                        <?php if ($bridgeRemoteSources > 0): ?>
                                                            <div class="flex items-center justify-between gap-2"><span>Bridge API <span class="text-gray-600">(<?php echo $bridgeRemoteSources; ?> source<?php echo $bridgeRemoteSources === 1 ? '' : 's'; ?>)</span></span><span class="font-semibold <?php echo $bridgeRemoteStock > 0 ? 'text-cyan-300' : 'text-red-300'; ?>"><?php echo $bridgeRemoteStock; ?></span></div>
                                                        <?php endif; ?>
                                                        <?php if ($cgoRemoteSources > 0): ?>
                                                            <div class="flex items-center justify-between gap-2"><span>CGO API <span class="text-gray-600">(<?php echo $cgoRemoteSources; ?> source<?php echo $cgoRemoteSources === 1 ? '' : 's'; ?>)</span></span><span class="font-semibold <?php echo $cgoRemoteStock > 0 ? 'text-cyan-300' : 'text-red-300'; ?>"><?php echo $cgoRemoteStock; ?></span></div>
                                                        <?php endif; ?>
                                                        <div class="pt-1 border-t border-white/5 flex items-center justify-between gap-2"><span><?php echo htmlspecialchars(productsUiText('ขายได้สูงสุดจากแหล่งเดียว', 'Max from one source'), ENT_QUOTES, 'UTF-8'); ?></span><span class="font-bold <?php echo $maxSellableSourceStock > 0 ? 'text-green-300' : 'text-red-300'; ?>"><?php echo $maxSellableSourceStock; ?></span></div>
                                                        <div class="text-[10px] text-gray-600"><?php echo htmlspecialchars(productsUiText('สต็อก API แยกจากคีย์ในร้าน และไม่นำมาบวกรวมกันเพื่อป้องกันการแสดงจำนวนที่ซื้อจริงไม่ได้', 'API inventory is separate from local keys and is not summed across sources.'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </div>
                                                <?php endif; ?>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                <?php else: ?>

                                    <div class="text-center py-3 text-gray-400 text-xs">
                                        <i class="bi bi-info-circle mr-1"></i>
                                        <span data-lang="admin.products.no_variants">No variants added yet</span>
                                    </div>

                                <?php endif; ?>



                            </div>

                        </div>



                        <!-- Product Actions -->

                        <div class="border-t border-white/10 p-4 md:p-6 pt-3 md:pt-4">

                            <div class="flex justify-between items-center gap-2">

                                <button type="button"
                                    class="p-2 rounded-lg hover:bg-blue-500/20 text-blue-400 transition flex-1"
                                    onclick="rememberProductsPosition('product-<?php echo (int) $product['id']; ?>'); window.location.href='products.php?edit=<?php echo (int) $product['id']; ?><?php echo $productsReturnQuery !== '' ? '&amp;' . htmlspecialchars($productsReturnQuery, ENT_QUOTES, 'UTF-8') : ''; ?>#product-<?php echo (int) $product['id']; ?>'"
                                    data-lang-title="common.edit"
                                    title="<?php echo Lang::t('common.edit'); ?>">

                                    <i class="bi bi-pencil text-xs md:text-sm"></i>

                                    <span class="text-xs ml-1 hidden md:inline" data-lang="common.edit">Edit</span>

                                </button>

                                <button type="button"
                                    class="p-2 rounded-lg hover:bg-yellow-500/20 text-yellow-400 transition flex-1"
                                    onclick="toggleStatus(<?php echo (int) $product['id']; ?>, <?php echo jsArg($product['status']); ?>)"
                                    title="<?php echo $product['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>">

                                    <?php echo $product['status'] == 'active' ? '<i class="bi bi-pause text-xs md:text-sm"></i>' : '<i class="bi bi-play text-xs md:text-sm"></i>'; ?>

                                    <span class="text-xs ml-1 hidden md:inline">
                                        <?php echo $product['status'] == 'active' ? '<span data-lang="common.pause">Pause</span>' : '<span data-lang="common.activate">Activate</span>'; ?>
                                    </span>

                                </button>

                                <button type="button" class="p-2 rounded-lg hover:bg-red-500/20 text-red-400 transition flex-1"
                                    onclick="deleteProduct(<?php echo (int) $product['id']; ?>, <?php echo jsArg($product['name']); ?>)"
                                    data-lang-title="common.delete"
                                    title="<?php echo Lang::t('common.delete'); ?>">

                                    <i class="bi bi-trash text-xs md:text-sm"></i>

                                    <span class="text-xs ml-1 hidden md:inline" data-lang="common.delete">Delete</span>

                                </button>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

        <?php if ($productTotalPages > 1): ?>
            <?php
            $pageStart = max(1, $productPage - 2);
            $pageEnd = min($productTotalPages, $productPage + 2);
            ?>
            <nav class="glass rounded-xl border border-white/10 p-3 flex flex-wrap items-center justify-between gap-3" aria-label="Product pages">
                <div class="text-xs text-gray-400">
                    <?php echo htmlspecialchars(productsUiText('หน้า', 'Page'), ENT_QUOTES, 'UTF-8'); ?>
                    <span class="text-white font-semibold"><?php echo $productPage; ?></span>
                    / <?php echo $productTotalPages; ?>
                </div>
                <div class="flex flex-wrap gap-1">
                    <?php if ($productPage > 1): ?>
                        <a class="min-w-10 min-h-10 inline-flex items-center justify-center rounded-lg bg-white/10 hover:bg-white/15 text-gray-200" href="products.php?<?php echo htmlspecialchars($buildProductsQuery(['page' => $productPage - 1]), ENT_QUOTES, 'UTF-8'); ?>#product-filters" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php if ($pageStart > 1): ?>
                        <a class="min-w-10 min-h-10 inline-flex items-center justify-center rounded-lg bg-white/10 hover:bg-white/15 text-gray-200" href="products.php?<?php echo htmlspecialchars($buildProductsQuery(['page' => 1]), ENT_QUOTES, 'UTF-8'); ?>#product-filters">1</a>
                        <?php if ($pageStart > 2): ?><span class="min-w-8 min-h-10 inline-flex items-center justify-center text-gray-500">…</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                        <a class="min-w-10 min-h-10 inline-flex items-center justify-center rounded-lg <?php echo $pageNumber === $productPage ? 'bg-green-500 text-white' : 'bg-white/10 hover:bg-white/15 text-gray-200'; ?>" href="products.php?<?php echo htmlspecialchars($buildProductsQuery(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8'); ?>#product-filters"><?php echo $pageNumber; ?></a>
                    <?php endfor; ?>
                    <?php if ($pageEnd < $productTotalPages): ?>
                        <?php if ($pageEnd < $productTotalPages - 1): ?><span class="min-w-8 min-h-10 inline-flex items-center justify-center text-gray-500">…</span><?php endif; ?>
                        <a class="min-w-10 min-h-10 inline-flex items-center justify-center rounded-lg bg-white/10 hover:bg-white/15 text-gray-200" href="products.php?<?php echo htmlspecialchars($buildProductsQuery(['page' => $productTotalPages]), ENT_QUOTES, 'UTF-8'); ?>#product-filters"><?php echo $productTotalPages; ?></a>
                    <?php endif; ?>
                    <?php if ($productPage < $productTotalPages): ?>
                        <a class="min-w-10 min-h-10 inline-flex items-center justify-center rounded-lg bg-white/10 hover:bg-white/15 text-gray-200" href="products.php?<?php echo htmlspecialchars($buildProductsQuery(['page' => $productPage + 1]), ENT_QUOTES, 'UTF-8'); ?>#product-filters" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
        </div>

    </main>



    <!-- Add/Edit Product Modal -->

    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal">

        <div
            class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4 max-h-[90vh] overflow-y-auto">

            <div class="flex justify-between items-center mb-3 md:mb-4">

                <h5 class="text-base md:text-lg font-bold text-white">
                    <i class="bi bi-box-seam text-green-400 mr-2"></i>
                    <?php if ($editProduct): ?>
                        <span data-lang="admin.products.edit_modal_title">Edit Product</span>
                    <?php else: ?>
                        <span data-lang="admin.products.add_modal_title">Add New Product</span>
                    <?php endif; ?>
                </h5>

                <button onclick="closeModal('addModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>

            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="<?php echo $editProduct ? 'edit' : 'add'; ?>">

                <?php if ($editProduct): ?>

                    <input type="hidden" name="product_id" value="<?php echo $editProduct['id']; ?>">
                    <input type="hidden" name="_return_anchor" value="product-<?php echo (int) $editProduct['id']; ?>">

                <?php endif; ?>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.form_name">Product Name</label>
                    <input type="text"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="name" value="<?php echo $editProduct ? htmlspecialchars($editProduct['name']) : ''; ?>"
                        required autofocus>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-2 block font-semibold">แพลตฟอร์ม / รูปแบบสินค้า</label>
                    <?php
                    $selectedProductPlatform = normalizeProductPlatform($editProduct['platform'] ?? '', 'both');
                    $productPlatformChoices = [
                        'android' => ['Android', 'bi-android2', 'peer-checked:border-green-400 peer-checked:bg-green-500/15'],
                        'ios' => ['iOS', 'bi-apple', 'peer-checked:border-sky-400 peer-checked:bg-sky-500/15'],
                        'both' => ['ทั้งสอง', 'bi-phone', 'peer-checked:border-violet-400 peer-checked:bg-violet-500/15'],
                        'account' => ['Account', 'bi-person-badge', 'peer-checked:border-amber-400 peer-checked:bg-amber-500/15'],
                    ];
                    if (!isset($productPlatformChoices[$selectedProductPlatform])) {
                        $productPlatformChoices[$selectedProductPlatform] = [
                            ucwords(str_replace(['_', '-'], ' ', $selectedProductPlatform)),
                            'bi-box',
                            'peer-checked:border-slate-400 peer-checked:bg-slate-500/15',
                        ];
                    }
                    ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <?php foreach ($productPlatformChoices as $platformValue => $platformMeta): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="platform" value="<?php echo $platformValue; ?>" class="peer sr-only"
                                    <?php echo $selectedProductPlatform === $platformValue ? 'checked' : ''; ?> required>
                                <div class="glass border border-white/10 rounded-xl px-2 py-3 text-center text-xs text-gray-300 transition <?php echo $platformMeta[2]; ?> peer-checked:text-white">
                                    <i class="bi <?php echo $platformMeta[1]; ?> block text-lg mb-1"></i>
                                    <?php echo $platformMeta[0]; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">สินค้าแบบ “ทั้งสอง” จะแสดงเมื่อกรอง Android หรือ iOS ส่วน Account จะแยกเป็นรูปแบบสินค้าของตัวเอง</p>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.form_category">Categories (1 - 4)</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <input type="text"
                                class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                                name="category<?php echo $i; ?>"
                                value="<?php echo $editProduct ? htmlspecialchars($editCategories[$i - 1] ?? '') : ''; ?>"
                                data-lang-placeholder="admin.products.category_placeholder"
                                data-lang-params='<?php echo htmlspecialchars(json_encode(['number' => $i], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>'
                                placeholder="<?php echo htmlspecialchars(productsUiText('หมวดหมู่ ' . $i, 'Category ' . $i), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php endfor; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2" data-lang="admin.products.category_hint">Fill 1 to 4 categories. Product will appear in each category list.</p>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.form_image">Upload Image</label>
                    <input type="hidden" name="old_image"
                        value="<?php echo $editProduct ? htmlspecialchars($editProduct['image'] ?? '') : ''; ?>">
                    <input type="file"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="image_file" accept="image/*">
                    <?php if ($editProduct && !empty($editProduct['image'])): ?>
                        <small class="text-gray-500 text-xs" data-lang="admin.products.image_edit_hint">Leave empty to keep current image.</small>
                    <?php else: ?>
                        <small class="text-gray-500 text-xs" data-lang="admin.products.image_add_hint">Select an image file for the product.</small>
                    <?php endif; ?>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block font-bold text-blue-400" data-lang="admin.products.form_dl_url">Download Link (URL)</label>
                    <div class="relative">
                        <i class="bi bi-link-45deg absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input type="text"
                            class="glass border border-white/10 rounded-lg py-2 pl-10 pr-4 w-full bg-transparent text-white text-sm"
                            name="download_url"
                            value="<?php echo $editProduct ? htmlspecialchars($editProduct['download_url'] ?? '') : ''; ?>"
                            data-lang-placeholder="common.placeholder.url"
                            placeholder="https://example.com/file.zip">
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1" data-lang="admin.products.download_url_hint">This link is specific to this product only.</p>
                </div>



                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.form_desc">Description</label>
                    <textarea
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="description"
                        rows="3"><?php echo $editProduct ? htmlspecialchars($editProduct['description']) : ''; ?></textarea>
                </div>

                <?php if (!$editProduct): ?>

                    <!-- Add initial variants when creating product -->

                    <div id="variantsContainer" class="mb-3 md:mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <label class="text-gray-400 text-xs md:text-sm" data-lang="admin.products.initial_variants">Initial Variants</label>
                            <button type="button" onclick="addVariantField()"
                                class="text-xs bg-accent hover:opacity-90 text-white px-2 py-1 rounded">
                                <i class="bi bi-plus mr-1"></i><span data-lang="admin.products.add_variant_btn">Add Variant</span>
                            </button>
                        </div>

                        <div id="variantsList" class="space-y-2">

                            <!-- Dynamic variant fields will be added here -->

                        </div>

                    </div>

                <?php endif; ?>

                <div class="flex flex-col md:flex-row gap-2 md:gap-3">

                    <button type="button" onclick="closeModal('addModal')"
                        class="bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="common.cancel">
                        <?php echo Lang::t('common.cancel'); ?>
                    </button>
                    <button type="submit"
                        class="bg-green-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1">
                        <?php if ($editProduct): ?>
                            <span data-lang="admin.products.form_save">Update Product</span>
                        <?php else: ?>
                            <span data-lang="admin.products.add_btn">Add Product</span>
                        <?php endif; ?>
                    </button>

                </div>

            </form>

        </div>

    </div>



    <!-- Add Variant Modal -->

    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50"
        id="addVariantModal">

        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">

            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i
                        class="bi bi-plus-circle text-yellow-400 mr-2"></i><span data-lang="admin.products.variant.add_btn">Add Variant</span></h5>
                <button onclick="closeModal('addVariantModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="add_variant">

                <input type="hidden" name="product_id" id="variant_product_id">
                <input type="hidden" name="_return_anchor" id="variant_return_anchor" value="">

                <div class="mb-3 md:mb-4">
                    <p class="text-gray-400 text-sm md:text-base mb-2"><span data-lang="admin.products.modal.product_label">Product:</span> <strong class="text-white"
                            id="variant_product_name"></strong></p>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.duration_label">Duration</label>
                    <input type="text"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="duration" data-lang-placeholder="admin.products.duration_placeholder" placeholder="<?php echo Lang::t('admin.products.duration_placeholder'); ?>" required>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4 mb-3 md:mb-4">

                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.price_user">User Price</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="price_user" required>
                    </div>
                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.price_reseller">Reseller Price</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="price_reseller" required>
                    </div>
                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.cost_label">Cost (Modal)</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="cost_price" value="0">
                    </div>

                </div>

                <div class="flex flex-col md:flex-row gap-2 md:gap-3">

                    <button type="button" onclick="closeModal('addVariantModal')"
                        class="bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="common.cancel">
                        <?php echo Lang::t('common.cancel'); ?>
                    </button>
                    <button type="submit"
                        class="bg-yellow-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="admin.products.add_variant_btn">
                        Add Variant
                    </button>

                </div>

            </form>

        </div>

    </div>



    <!-- Edit Variant Modal -->

    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50"
        id="editVariantModal">

        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">

            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i
                        class="bi bi-pencil text-blue-400 mr-2"></i><span data-lang="admin.products.modal_edit_variant_title">Edit Variant</span></h5>
                <button onclick="closeModal('editVariantModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="update_variant">

                <input type="hidden" name="variant_id" id="edit_variant_id">
                <input type="hidden" name="_return_anchor" id="edit_variant_return_anchor" value="">

                <div class="mb-3 md:mb-4">
                    <p class="text-gray-400 text-sm md:text-base mb-2"><span data-lang="admin.products.modal.product_label">Product:</span> <strong class="text-white"
                            id="edit_product_name"></strong></p>
                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.duration_label">Duration</label>
                    <input type="text"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="duration" id="edit_variant_duration" data-lang-placeholder="admin.products.duration_placeholder" required>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4 mb-3 md:mb-4">

                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.price_user">User Price</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="price_user" id="edit_price_user" required>
                    </div>
                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.price_reseller">Reseller Price</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="price_reseller" id="edit_price_reseller" required>
                    </div>
                    <div>
                        <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.cost_label">Cost (Modal)</label>
                        <input type="text" inputmode="decimal"
                            class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                            name="cost_price" id="edit_cost_price" value="">
                    </div>

                </div>

                <div class="flex flex-col md:flex-row gap-2 md:gap-3">

                    <button type="button" onclick="closeModal('editVariantModal')"
                        class="bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="common.cancel">
                        <?php echo Lang::t('common.cancel'); ?>
                    </button>
                    <button type="submit"
                        class="bg-blue-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="admin.products.update_variant_btn">
                        Update Variant
                    </button>

                </div>

            </form>

        </div>

    </div>



    <!-- Add Keys to Variant Modal -->

    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50"
        id="addVariantKeysModal">

        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">

            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i class="bi bi-key text-pink-400 mr-2"></i><span data-lang="admin.products.variant.add_keys">Add Keys to Variant</span></h5>
                <button onclick="closeModal('addVariantKeysModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>

                <input type="hidden" name="action" value="add_variant_keys">

                <input type="hidden" name="variant_id" id="keys_variant_id">
                <input type="hidden" name="_return_anchor" id="keys_variant_return_anchor" value="">

                <div class="mb-3 md:mb-4">

                    <p class="text-gray-400 text-sm md:text-base mb-1"><span data-lang="admin.products.modal.product_label">Product:</span> <strong class="text-white"
                            id="keys_product_name"></strong></p>

                    <p class="text-gray-400 text-sm md:text-base"><span data-lang="admin.products.modal.variant_label">Variant:</span> <strong class="text-white"
                            id="keys_variant_name"></strong></p>

                </div>

                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.products.variant.add_keys">Key Codes (one per line)</label>
                    <textarea
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="key_codes" rows="6" data-lang-placeholder="admin.products.keys_hint" placeholder="<?php echo Lang::t('admin.products.keys_hint'); ?>"
                        required></textarea>
                </div>

                <div class="flex flex-col md:flex-row gap-2 md:gap-3">

                    <button type="button" onclick="closeModal('addVariantKeysModal')"
                        class="bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="common.cancel">
                        <?php echo Lang::t('common.cancel'); ?>
                    </button>
                    <button type="submit"
                        class="bg-pink-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base flex-1" data-lang="admin.products.add_keys">
                        Add Keys
                    </button>

                </div>

            </form>

        </div>

    </div>



    <script>

        const PRODUCTS_ALLOWED_RETURN_FIELDS = ['q', 'category', 'status', 'platform', 'sort', 'per_page', 'page'];

        function currentProductsReturnQuery() {
            const current = new URL(window.location.href);
            const clean = new URLSearchParams();
            PRODUCTS_ALLOWED_RETURN_FIELDS.forEach(function (name) {
                const value = (current.searchParams.get(name) || '').trim();
                if (value) clean.set(name, value.slice(0, 160));
            });
            return clean.toString();
        }

        function attachProductsReturnQuery(form) {
            if (!form || String(form.method || '').toLowerCase() !== 'post') return;
            let input = form.querySelector('input[name="_return_query"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_return_query';
                form.appendChild(input);
            }
            // The instant category/search filter replaces only the product panel.
            // Read the live URL here instead of reusing the query from initial load.
            input.value = currentProductsReturnQuery();
        }

        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(attachProductsReturnQuery);

        function closeModal(modalId) {

            document.getElementById(modalId).classList.add('hidden');

            // Clear edit mode on close

            if (modalId === 'addModal' && window.location.search.includes('edit=')) {
                const url = new URL(window.location.href);
                url.searchParams.delete('edit');
                window.history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
            }

        }



        function openAddModal() {

            document.getElementById('addModal').classList.remove('hidden');

            document.getElementById('addModal').classList.add('flex');

        }



        function openAddVariantModal(productId, productName) {

            document.getElementById('variant_product_id').value = productId;

            const variantReturnAnchor = document.getElementById('variant_return_anchor');
            if (variantReturnAnchor) variantReturnAnchor.value = 'product-' + productId;

            document.getElementById('variant_product_name').textContent = productName;

            document.getElementById('addVariantModal').classList.remove('hidden');

            document.getElementById('addVariantModal').classList.add('flex');

        }



        function openEditVariantModal(variantId, duration, priceUser, priceReseller, costPrice, productName) {

            document.getElementById('edit_variant_id').value = variantId;

            const editReturnAnchor = document.getElementById('edit_variant_return_anchor');
            if (editReturnAnchor) editReturnAnchor.value = 'variant-' + variantId;

            document.getElementById('edit_variant_duration').value = duration;

            document.getElementById('edit_price_user').value = priceUser;

            document.getElementById('edit_price_reseller').value = priceReseller;

            const cp = (costPrice === null || costPrice === undefined) ? '' : String(costPrice);

            document.getElementById('edit_cost_price').value = (cp === '0' || cp === '0.0' || cp === '0.00') ? '' : cp;

            document.getElementById('edit_product_name').textContent = productName;

            document.getElementById('editVariantModal').classList.remove('hidden');

            document.getElementById('editVariantModal').classList.add('flex');

        }



        function openAddVariantKeysModal(variantId, productName, variantName) {

            document.getElementById('keys_variant_id').value = variantId;

            const keysReturnAnchor = document.getElementById('keys_variant_return_anchor');
            if (keysReturnAnchor) keysReturnAnchor.value = 'variant-' + variantId;

            document.getElementById('keys_product_name').textContent = productName;

            document.getElementById('keys_variant_name').textContent = variantName;

            document.getElementById('addVariantKeysModal').classList.remove('hidden');

            document.getElementById('addVariantKeysModal').classList.add('flex');

        }



        // Open edit modal if edit parameter exists

        <?php if ($editProduct || $openAdd): ?>

            document.addEventListener('DOMContentLoaded', function () {

                openAddModal();

            });

        <?php endif; ?>



        function toggleStatus(id, currentStatus) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="product_id" value="' + id + '"><input type="hidden" name="current_status" value="' + currentStatus + '"><input type="hidden" name="_return_anchor" value="product-' + id + '">';
            rememberProductsPosition('product-' + id);
            document.body.appendChild(form);
            attachProductsReturnQuery(form);
            setProductsHiddenReturnFields(form, 'product-' + id);
            form.submit();
        }

        function deleteProduct(id, name) {
            if (confirm(Lang.t('admin.products.confirm_delete_product'))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" value="${id}">
                    <input type="hidden" name="_return_anchor" value="">
                `;
                rememberProductsPosition('');
                document.body.appendChild(form);
                attachProductsReturnQuery(form);
                setProductsHiddenReturnFields(form, '');
                form.submit();
            }
        }

        function deleteVariant(id, duration) {
            if (confirm(Lang.t('admin.products.confirm_delete_variant'))) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_variant">
                    <input type="hidden" name="variant_id" value="${id}">
                    <input type="hidden" name="_return_anchor" value="">
                `;
                rememberProductsPosition('');
                document.body.appendChild(form);
                attachProductsReturnQuery(form);
                setProductsHiddenReturnFields(form, '');
                form.submit();
            }
        }



        /**

         * ====== AJAX SUBMIT WITH FULL PAGE REFRESH AFTER SUCCESS ======

         * Intercept submit for:

         * - update_variant (Edit Variant Modal)

         * - add_variant_keys (Add Keys Modal)

         * Send via fetch(), then reload via redirect so the page is fully synced with the database.

         */

        function productsStateKey(type) {
            return 'products:' + type + ':' + window.location.pathname + '?' + currentProductsReturnQuery();
        }

        function getProductsScrollContainer() {
            const main = document.querySelector('main');
            if (main && main.scrollHeight > main.clientHeight + 5) {
                return main;
            }
            return document.scrollingElement || document.documentElement;
        }

        function getProductsScrollTop() {
            const scroller = getProductsScrollContainer();
            if (scroller === document.scrollingElement || scroller === document.documentElement || scroller === document.body) {
                return window.scrollY || window.pageYOffset || scroller.scrollTop || 0;
            }
            return scroller.scrollTop || 0;
        }

        function setProductsHiddenReturnFields(form, targetId) {
            if (!form) return;
            let anchorInput = form.querySelector('input[name="_return_anchor"]');
            if (!anchorInput) {
                anchorInput = document.createElement('input');
                anchorInput.type = 'hidden';
                anchorInput.name = '_return_anchor';
                form.appendChild(anchorInput);
            }
            anchorInput.value = targetId || '';

            let scrollInput = form.querySelector('input[name="_return_scroll_y"]');
            if (!scrollInput) {
                scrollInput = document.createElement('input');
                scrollInput.type = 'hidden';
                scrollInput.name = '_return_scroll_y';
                form.appendChild(scrollInput);
            }
            scrollInput.value = String(getProductsScrollTop());
        }

        function rememberProductsPosition(targetId) {
            try {
                sessionStorage.setItem(productsStateKey('lastScrollY'), String(getProductsScrollTop()));
                if (targetId) {
                    sessionStorage.setItem(productsStateKey('lastTarget'), targetId);
                } else {
                    sessionStorage.removeItem(productsStateKey('lastTarget'));
                }
            } catch (err) {
                // Storage can be disabled; URL hash and hidden return anchor still work.
            }
        }

        function getTargetFromForm(form) {
            const action = form.querySelector('[name="action"]')?.value || '';
            const productId = form.querySelector('[name="product_id"]')?.value || '';
            const variantId = form.querySelector('[name="variant_id"]')?.value || '';

            if (variantId && ['update_variant', 'add_variant_keys'].includes(action)) return 'variant-' + variantId;
            if (variantId && action === 'delete_variant') return '';
            if (productId && ['edit', 'toggle_status', 'add_variant'].includes(action)) return 'product-' + productId;
            return '';
        }

        function findProductsTarget(targetId) {
            if (!targetId) return null;

            if (targetId.startsWith('variant-')) {
                const variantId = targetId.replace('variant-', '');
                const safeVariantId = (window.CSS && CSS.escape) ? CSS.escape(variantId) : variantId.replace(/"/g, '\"');
                const variants = Array.from(document.querySelectorAll('.variant-item[data-variant-id="' + safeVariantId + '"]'));
                let el = variants.find(item => item.offsetParent !== null) || variants[0] || document.getElementById(targetId);
                if (el) {
                    const details = el.closest('details');
                    if (details) details.open = true;
                }
                return el || null;
            }

            return document.getElementById(targetId);
        }

        function scrollProductsTargetIntoView(targetEl) {
            if (!targetEl) return;
            const details = targetEl.closest('details');
            if (details) details.open = true;

            const scroller = getProductsScrollContainer();
            if (scroller === document.scrollingElement || scroller === document.documentElement || scroller === document.body) {
                targetEl.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
                return;
            }

            const scrollerRect = scroller.getBoundingClientRect();
            const targetRect = targetEl.getBoundingClientRect();
            const delta = targetRect.top - scrollerRect.top - (scrollerRect.height / 2) + (targetRect.height / 2);
            scroller.scrollTop += delta;
        }

        function highlightProductsTarget(el) {
            if (!el) return;
            el.classList.add('products-highlight');
            setTimeout(() => el.classList.remove('products-highlight'), 2500);
        }

        let productsPositionRestored = false;

        function restoreProductsPosition() {
            if (productsPositionRestored) return;
            let targetId = '';
            try {
                targetId = decodeURIComponent((window.location.hash || '').replace(/^#/, ''));
            } catch (err) {
                targetId = (window.location.hash || '').replace(/^#/, '');
            }

            if (!targetId) {
                targetId = String((window.PRODUCTS_FLASH || {}).return_anchor || '');
            }

            if (!targetId) {
                try {
                    targetId = sessionStorage.getItem(productsStateKey('lastTarget')) || '';
                } catch (err) {}
            }

            const targetEl = findProductsTarget(targetId);
            if (targetEl) {
                scrollProductsTargetIntoView(targetEl);
                highlightProductsTarget(targetEl);
                productsPositionRestored = true;
                try {
                    sessionStorage.removeItem(productsStateKey('lastTarget'));
                    sessionStorage.removeItem(productsStateKey('lastScrollY'));
                } catch (err) {}
                return;
            }

            try {
                const serverY = parseInt(String((window.PRODUCTS_FLASH || {}).return_scroll_y || ''), 10);
                const storedY = parseInt(sessionStorage.getItem(productsStateKey('lastScrollY')) || '', 10);
                const savedY = !Number.isNaN(serverY) && serverY > 0 ? serverY : storedY;
                if (!Number.isNaN(savedY) && savedY > 0) {
                    const scroller = getProductsScrollContainer();
                    if (scroller === document.scrollingElement || scroller === document.documentElement || scroller === document.body) {
                        window.scrollTo(0, savedY);
                    } else {
                        scroller.scrollTop = savedY;
                    }
                    productsPositionRestored = true;
                    sessionStorage.removeItem(productsStateKey('lastScrollY'));
                }
            } catch (err) {}
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function showToast(type, text, details) {
            const container = document.getElementById('toastContainer');
            if (!container || !text) return;

            const div = document.createElement('div');
            div.className = 'pointer-events-auto glass border rounded-lg p-3 md:p-4 shadow-lg text-sm md:text-base max-w-xl ml-auto ' +
                (type === 'success'
                    ? 'border-green-500/50 bg-green-900/80 text-green-100'
                    : 'border-red-500/50 bg-red-900/80 text-red-100');

            const title = type === 'success' ? Lang.t('common.success') : Lang.t('common.error');
            const closeLabel = Lang.t('common.close');
            const detailList = Array.isArray(details) && details.length
                ? '<ul class="mt-2 list-disc list-inside text-xs md:text-sm text-gray-100 space-y-1">' + details.map(d => '<li>' + escapeHtml(d) + '</li>').join('') + '</ul>'
                : '';

            div.innerHTML = '<div class="flex gap-3 items-start">' +
                '<div class="flex-1"><div class="font-semibold mb-0.5">' + title + '</div><div>' + escapeHtml(text) + '</div>' + detailList + '</div>' +
                '<button type="button" class="w-11 h-11 min-w-11 flex items-center justify-center text-gray-200 hover:text-white text-2xl leading-none rounded-lg" aria-label="' + escapeHtml(closeLabel) + '">&times;</button>' +
                '</div>';

            div.querySelector('button')?.addEventListener('click', () => div.remove());
            container.appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        function showTopMessage(type, text, details) {

            // type: 'success' | 'error'

            const main = document.querySelector('main');

            if (!main) return;



            // remove existing injected message (optional)

            const existing = document.getElementById('ajaxFlashMsg');

            if (existing) existing.remove();



            const div = document.createElement('div');

            div.id = 'ajaxFlashMsg';

            div.className = 'glass border p-3 md:p-4 rounded-lg text-sm md:text-base ' +

                (type === 'success'

                    ? 'border-green-500/50 bg-green-900/20 text-green-300'

                    : 'border-red-500/50 bg-red-900/20 text-red-300');

            div.textContent = text;

            if (Array.isArray(details) && details.length) {
                const ul = document.createElement('ul');
                ul.className = 'mt-2 list-disc list-inside space-y-1';
                details.forEach(function (detail) {
                    const li = document.createElement('li');
                    li.textContent = detail;
                    ul.appendChild(li);
                });
                div.appendChild(ul);
            }

            showToast(type, text, details || []);



            // insert after header block (after first child)

            const first = main.firstElementChild;

            if (first && first.nextElementSibling) {

                main.insertBefore(div, first.nextElementSibling);

            } else {

                main.insertBefore(div, main.firstChild);

            }

        }



        function getVariantItemById(variantId) {

            const items = document.querySelectorAll('.variant-item');

            for (const item of items) {

                const btn = item.querySelector('button[onclick*="openEditVariantModal(' + variantId + ',"]');

                const btn2 = item.querySelector('button[onclick*="openAddVariantKeysModal(' + variantId + ',"]');

                const btn3 = item.querySelector('button[onclick*="deleteVariant(' + variantId + ',"]');

                if (btn || btn2 || btn3) return item;

            }

            return null;

        }



        function updateVariantCardFromPayload(payload) {

            if (!payload || !payload.variant) return;

            const v = payload.variant;

            const item = getVariantItemById(v.id);

            if (!item) return;



            // duration text (first .font-medium span in header row)

            const durSpan = item.querySelector('span.font-medium');

            if (durSpan && v.duration !== undefined) durSpan.textContent = v.duration;



            // price values (two .variant-price blocks, each has .font-bold value)

            const priceVals = item.querySelectorAll('.variant-price .font-bold');

            if (priceVals && priceVals.length >= 2) {

                if (v.price_user_fmt !== undefined) priceVals[0].textContent = v.price_user_fmt;

                if (v.price_reseller_fmt !== undefined) priceVals[1].textContent = v.price_reseller_fmt;

            }



            // key counts area

            const infoRow = item.querySelector('.text-xs.text-gray-500.mt-1');

            if (infoRow) {

                const spans = infoRow.querySelectorAll('span');

                // Expected: [ "Total Keys: X", "Available: Y" ]

                if (spans.length >= 2) {

                    // Total Keys

                    const totalWhite = spans[0].querySelector('.text-white');

                    if (totalWhite && v.total_keys !== undefined) totalWhite.textContent = String(v.total_keys);



                    // Available

                    const availSpan = spans[1].querySelector('span');

                    if (availSpan && v.available_keys !== undefined) {

                        availSpan.textContent = String(v.available_keys);

                        availSpan.className = (v.available_keys > 0) ? 'text-green-400' : 'text-red-400';

                    }

                }

            }

        }



        async function ajaxSubmitForm(form, closeModalId) {

            const fd = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const formTarget = getTargetFromForm(form);
            if (submitBtn) submitBtn.disabled = true;

            try {

                const res = await fetch(window.location.pathname, {

                    method: 'POST',

                    body: fd,

                    headers: {

                        'X-Requested-With': 'XMLHttpRequest',

                        'Accept': 'application/json'

                    }

                });

                const data = await res.json();



                if (!data || !data.ok) {

                    showTopMessage('error', (data && data.error) ? data.error : Lang.t('admin.dashboard.error.invalid_request'), data && data.details ? data.details : []);

                    return;

                }



                // success: do a full refresh so counts, buttons, cards and server-rendered data stay in sync.

                showTopMessage('success', data.message || Lang.t('common.success'), data.details || []);
                rememberProductsPosition(formTarget);

                const redirectUrl = data.redirect || window.location.pathname;

                window.location.assign(redirectUrl);

                return;

            } catch (error) {
                console.error('AJAX Error:', error);
                showTopMessage('error', Lang.t('deposit.error.server'), []);
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        }



        document.addEventListener('DOMContentLoaded', function () {

            document.addEventListener('submit', function (ev) {
                const form = ev.target;
                if (!form || String(form.method || '').toLowerCase() !== 'post') return;
                attachProductsReturnQuery(form);
                const targetId = getTargetFromForm(form) || form.querySelector('input[name="_return_anchor"]')?.value || '';
                setProductsHiddenReturnFields(form, targetId);
                rememberProductsPosition(targetId);
            }, true);

            const flash = window.PRODUCTS_FLASH || {};
            if (flash.error) {
                showToast('error', flash.error, flash.details || []);
            } else if (flash.success) {
                showToast('success', flash.success, flash.details || []);
            }

            requestAnimationFrame(restoreProductsPosition);
            setTimeout(restoreProductsPosition, 80);
            setTimeout(restoreProductsPosition, 350);
            window.addEventListener('load', function () {
                restoreProductsPosition();
                setTimeout(restoreProductsPosition, 150);
            }, { once: true });

            // Intercept Edit Variant form

            const editModal = document.getElementById('editVariantModal');

            if (false && editModal) {

                const f = editModal.querySelector('form');

                if (f) {

                    f.addEventListener('submit', function (ev) {

                        ev.preventDefault();

                        ajaxSubmitForm(f, 'editVariantModal');

                    });

                }

            }



            // Intercept Add Keys form

            const keysModal = document.getElementById('addVariantKeysModal');

            if (false && keysModal) {

                const f2 = keysModal.querySelector('form');

                if (f2) {

                    f2.addEventListener('submit', function (ev) {

                        ev.preventDefault();

                        ajaxSubmitForm(f2, 'addVariantKeysModal');

                    });

                }

            }

        });



        // Dynamic variant fields for new product

        let variantCount = 0;



        function addVariantField() {

            variantCount++;

            const variantsList = document.getElementById('variantsList');

            const variantHtml = `

                <div class="glass border border-white/10 rounded-lg p-3">

                    <div class="flex justify-between items-center mb-2">

                        <span class="text-white text-xs" data-lang="admin.products.variant_label">Variant</span> <span class="text-white text-xs">#${variantCount}</span>

                        <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-400 hover:text-red-300 text-xs">

                            <i class="bi bi-x"></i>

                        </button>

                    </div>

                    <div class="mb-2">

                        <input type="text" 

                               class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-xs" 

                               name="variants[${variantCount}][duration]" 

                               data-lang-placeholder="admin.products.duration_placeholder"

                               placeholder="Duration (e.g., 1 Month)" 

                               required>

                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">

                        <div>

                            <input type="text" inputmode="decimal" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-xs" 

                                   name="variants[${variantCount}][price_user]" 

                                   data-lang-placeholder="admin.products.price_user"

                                   placeholder="User Price" 

                                   required>

                        </div>

                        <div>

                            <input type="text" inputmode="decimal" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-xs" 

                                   name="variants[${variantCount}][price_reseller]" 

                                   data-lang-placeholder="admin.products.price_reseller"

                                   placeholder="Reseller Price" 

                                   required>

                        </div>

                        <div>

                            <input type="text" inputmode="decimal" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-xs" 

                                   name="variants[${variantCount}][cost_price]" 

                                   data-lang-placeholder="admin.products.cost_label"

                                   placeholder="Cost (Modal)" 

                                   value="0">

                        </div>

                    </div>

                </div>

            `;

            variantsList.insertAdjacentHTML('beforeend', variantHtml);

            // Newly inserted fields are not present during Lang.init(). Apply
            // the current language immediately instead of leaving English placeholders.
            if (window.Lang && typeof window.Lang.updatePage === 'function') {
                window.Lang.updatePage();
            }

        }

    </script>

<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>

</html>
