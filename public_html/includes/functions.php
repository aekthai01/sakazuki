<?php
/**
 * Common Functions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wallet_ledger.php';
require_once __DIR__ . '/commerce_center.php';
require_once __DIR__ . '/commerce_context.php';
require_once __DIR__ . '/transaction_integrity.php';
require_once __DIR__ . '/slip_debug.php';


/**
 * Production storefront requests must never perform schema migrations.
 *
 * The private hosting-cron worker defines SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS=true
 * before loading the application. Browser requests use a cheap table probe and
 * fail safely if deployment migrations have not run yet instead of issuing
 * CREATE/ALTER/INFORMATION_SCHEMA work while a customer is waiting.
 */
function sakazukiSchemaMigrationsAllowed(): bool
{
    if (defined('SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS')) {
        return SAKAZUKI_ALLOW_SCHEMA_MIGRATIONS === true;
    }
    return PHP_SAPI === 'cli';
}

/**
 * Cheap request-local readiness probe for an expected application table.
 * SELECT ... LIMIT 0 touches no rows and avoids INFORMATION_SCHEMA scans.
 */
function sakazukiTableReady(string $table, bool $refresh = false): bool
{
    global $conn;
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/iD', $table)) return false;
    if ($refresh) unset($cache[$table]);
    if (array_key_exists($table, $cache)) return $cache[$table];
    if (!isset($conn) || !($conn instanceof mysqli)) return $cache[$table] = false;
    try {
        $result = $conn->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
        if ($result instanceof mysqli_result) $result->free();
        return $cache[$table] = ($result !== false);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}


/**
 * Cheap request-local readiness probe for mandatory columns.
 *
 * Runtime HTTP requests must never run ALTER TABLE, but checking only whether
 * a table exists is not enough after a deployment adds columns used by every
 * checkout. SELECT ... LIMIT 0 validates the exact column set without reading
 * customer rows or scanning INFORMATION_SCHEMA.
 */
function sakazukiTableColumnsReady(string $table, array $columns, bool $refresh = false): bool
{
    global $conn;
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/iD', $table) || $columns === []) return false;
    $clean = [];
    foreach ($columns as $column) {
        if (!is_scalar($column)) return false;
        $column = trim((string) $column);
        if ($column === '' || preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        $clean[$column] = $column;
        if (count($clean) > 80) return false;
    }
    $columns = array_values($clean);
    sort($columns, SORT_STRING);
    $cacheKey = $table . ':' . implode(',', $columns);
    if ($refresh) unset($cache[$cacheKey]);
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    if (!isset($conn) || !($conn instanceof mysqli)) return $cache[$cacheKey] = false;
    $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
    try {
        $result = $conn->query('SELECT ' . implode(',', $quoted) . ' FROM `' . $table . '` LIMIT 0');
        if ($result instanceof mysqli_result) $result->free();
        return $cache[$cacheKey] = ($result !== false);
    } catch (Throwable $e) {
        return $cache[$cacheKey] = false;
    }
}



/**
 * Write a product image in a browser-friendly size. GD/WebP is used when the
 * hosting package supports it; otherwise callers can safely keep their legacy
 * upload fallback. No ImageMagick/root service is required.
 *
 * @return array{success:bool,filename:string,mime:string,width:int,height:int,optimized:bool}
 */
function sakazukiWriteOptimizedProductImage(
    string $bytes,
    string $directory,
    string $prefix = 'opt_',
    int $maxDimension = 1024
): array {
    $failed = ['success' => false, 'filename' => '', 'mime' => '', 'width' => 0, 'height' => 0, 'optimized' => false];
    if ($bytes === '' || strlen($bytes) > 8 * 1024 * 1024) return $failed;
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) return $failed;
    $width = (int) $info[0];
    $height = (int) $info[1];
    $mime = strtolower(trim((string) ($info['mime'] ?? '')));
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 24000000 || !isset($allowed[$mime])) {
        return $failed;
    }
    // Do not flatten animated GIFs into a single WebP frame. Callers keep the
    // validated original GIF through their compatibility fallback.
    if ($mime === 'image/gif') return $failed;
    $maxDimension = max(320, min(1600, $maxDimension));
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) return $failed;
    if (!is_writable($directory)) return $failed;
    if (!function_exists('imagecreatefromstring')) return $failed;

    $source = @imagecreatefromstring($bytes);
    if ($source === false) return $failed;
    $scale = min(1.0, $maxDimension / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $image = $source;
    if ($targetWidth !== $width || $targetHeight !== $height) {
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            imagedestroy($source);
            return $failed;
        }
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);
        if (!imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($resized);
            imagedestroy($source);
            return $failed;
        }
        imagedestroy($source);
        $image = $resized;
    }

    try {
        $random = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        imagedestroy($image);
        return $failed;
    }
    $prefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix) ?: 'opt_';
    $outputMime = $mime;
    $extension = $allowed[$mime];
    if (function_exists('imagewebp')) {
        $outputMime = 'image/webp';
        $extension = 'webp';
    }
    $filename = $prefix . $random . '.' . $extension;
    $destination = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
    $written = false;
    if ($outputMime === 'image/webp' && function_exists('imagewebp')) {
        $written = @imagewebp($image, $destination, 82);
    } elseif ($mime === 'image/jpeg' && function_exists('imagejpeg')) {
        $written = @imagejpeg($image, $destination, 84);
    } elseif ($mime === 'image/png' && function_exists('imagepng')) {
        $written = @imagepng($image, $destination, 7);
    } elseif ($mime === 'image/gif' && function_exists('imagegif')) {
        $written = @imagegif($image, $destination);
    }
    imagedestroy($image);
    if (!$written || !is_file($destination) || filesize($destination) < 1) {
        @unlink($destination);
        return $failed;
    }
    @chmod($destination, 0644);
    return [
        'success' => true,
        'filename' => $filename,
        'mime' => $outputMime,
        'width' => $targetWidth,
        'height' => $targetHeight,
        'optimized' => $outputMime === 'image/webp' || $targetWidth !== $width || $targetHeight !== $height,
    ];
}

/**
 * Incrementally converts oversized legacy product uploads in the background.
 * The cursor keeps each cron run bounded for shared/rented hosting.
 */
function sakazukiOptimizeLegacyProductImages(int $maxConverted = 3): array
{
    global $conn;
    $maxConverted = max(1, min(8, $maxConverted));
    if (!isset($conn) || !($conn instanceof mysqli)) return ['success' => false, 'message' => 'Database unavailable'];
    if (!function_exists('imagecreatefromstring')) {
        return ['success' => true, 'skipped' => true, 'message' => 'GD image support is unavailable', 'next_interval_seconds' => 21600];
    }

    $cursor = max(0, (int) getSetting('product_image_optimizer_cursor', '0'));
    $scanLimit = 80;
    $stmt = $conn->prepare("SELECT id,image FROM products WHERE id>? AND image<>'' ORDER BY id ASC LIMIT {$scanLimit}");
    if (!$stmt) return ['success' => false, 'message' => 'Image optimizer query could not be prepared'];
    $stmt->bind_param('i', $cursor);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'message' => 'Image optimizer query failed']; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) $result->free();
    $stmt->close();

    $publicRoot = dirname(__DIR__);
    $uploadDir = $publicRoot . '/assets/uploads/products/';
    $converted = 0;
    $scanned = 0;
    $lastId = $cursor;
    foreach ($rows as $row) {
        $id = max(0, (int) ($row['id'] ?? 0));
        if ($id < 1) continue;
        $lastId = $id;
        $scanned++;
        $relative = ltrim(str_replace('\\', '/', trim((string) ($row['image'] ?? ''))), '/');
        if (preg_match('#\Aassets/uploads/products/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)\z#iD', $relative) !== 1) continue;
        $source = $publicRoot . '/' . $relative;
        if (!is_file($source) || !is_readable($source)) continue;
        $size = (int) @filesize($source);
        $info = @getimagesize($source);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) continue;
        $width = (int) $info[0];
        $height = (int) $info[1];
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        // Already small enough for storefront cards and detail views.
        if ($width <= 1024 && $height <= 1024 && $size > 0 && $size <= 360 * 1024 && $extension === 'webp') continue;
        if ($width <= 1024 && $height <= 1024 && $size > 0 && $size <= 260 * 1024) continue;
        $bytes = @file_get_contents($source);
        if (!is_string($bytes) || $bytes === '') continue;
        $optimized = sakazukiWriteOptimizedProductImage($bytes, $uploadDir, 'opt_', 1024);
        if (empty($optimized['success']) || empty($optimized['filename'])) continue;
        $newRelative = 'assets/uploads/products/' . $optimized['filename'];
        $update = $conn->prepare('UPDATE products SET image=?,updated_at=NOW() WHERE id=? AND image=?');
        if (!$update) { @unlink($uploadDir . $optimized['filename']); continue; }
        $oldRelative = (string) ($row['image'] ?? '');
        $update->bind_param('sis', $newRelative, $id, $oldRelative);
        $ok = $update->execute() && $update->affected_rows === 1;
        $update->close();
        if (!$ok) { @unlink($uploadDir . $optimized['filename']); continue; }
        // Keep the old file. Other imported records may still reference it; a
        // separate reference-aware cleanup can remove orphans safely later.
        $converted++;
        if ($converted >= $maxConverted) break;
    }

    $reachedEnd = count($rows) < $scanLimit && $converted < $maxConverted;
    upsertSetting('product_image_optimizer_cursor', (string) ($reachedEnd ? 0 : $lastId));
    return [
        'success' => true,
        'scanned' => $scanned,
        'converted' => $converted,
        'completed_pass' => $reachedEnd,
        'next_interval_seconds' => $reachedEnd ? 21600 : ($converted > 0 ? 60 : 600),
    ];
}

/** Bump this marker whenever a deployment introduces schema changes. */
function sakazukiStorefrontSchemaMarker(): string
{
    return 'schema_storefront_20260910_redeem_code_integrity_v2';
}

/**
 * True after the private cron worker has completed the current schema version.
 * Cached per request so background jobs do not repeatedly inspect metadata.
 */
function sakazukiStorefrontSchemaPrepared(): bool
{
    global $conn;
    static $prepared = null;
    if ($prepared !== null) return $prepared;
    if (!sakazukiTableReady('automation_job_state')) return $prepared = false;
    $marker = sakazukiStorefrontSchemaMarker();
    $stmt = $conn->prepare(
        'SELECT 1 FROM automation_job_state WHERE job_name = ? AND last_success_at IS NOT NULL LIMIT 1'
    );
    if (!$stmt) return $prepared = false;
    $stmt->bind_param('s', $marker);
    if (!$stmt->execute()) { $stmt->close(); return $prepared = false; }
    $stmt->store_result();
    $prepared = $stmt->num_rows > 0;
    $stmt->close();
    return $prepared;
}

/**
 * Create the announcement storage only when it is missing.
 *
 * Normal storefront requests first read the existing table directly, so this
 * CREATE statement is not executed on every page view.
 */
function ensureAnnouncementTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('announcement_texts');
    }

    $sql = "CREATE TABLE IF NOT EXISTS announcement_texts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        text_content TEXT NOT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_announcement_status (status, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        return $ready = (bool) $conn->query($sql);
    } catch (Throwable $e) {
        error_log('Announcement table setup failed: ' . $e->getMessage());
        return $ready = false;
    }
}

/**
 * Get active announcement text
 */
function getAnnouncementText()
{
    global $conn;
    $sql = "SELECT text_content
            FROM announcement_texts
            WHERE status = 'active' AND TRIM(text_content) <> ''
            ORDER BY id ASC
            LIMIT 1";
    $result = $conn->query($sql);
    if (!$result && ensureAnnouncementTable()) {
        $result = $conn->query($sql);
    }
    if ($result && $row = $result->fetch_assoc()) {
        return cleanAnnouncementInput($row['text_content']);
    }
    return null;
}

/**
 * Return the saved announcement even when it is currently hidden.
 */
function getAnnouncementSettings(): array
{
    global $conn;
    $default = ['id' => 0, 'text' => '', 'status' => 'inactive'];
    $sql = "SELECT id, text_content, status
            FROM announcement_texts
            ORDER BY CASE
                WHEN status = 'active' AND TRIM(text_content) <> '' THEN 0
                WHEN status = 'active' THEN 1
                ELSE 2
            END, id ASC
            LIMIT 1";
    $result = $conn->query($sql);
    if (!$result && ensureAnnouncementTable()) {
        $result = $conn->query($sql);
    }
    if (!$result || !($row = $result->fetch_assoc())) return $default;

    $savedText = cleanAnnouncementInput($row['text_content'] ?? '');
    return [
        'id' => (int) ($row['id'] ?? 0),
        'text' => $savedText,
        'status' => (string) ($row['status'] ?? '') === 'active'
            && trim($savedText) !== ''
                ? 'active'
                : 'inactive',
    ];
}

/**
 * Update announcement text
 */
function updateAnnouncementText($text, $status = 'active')
{
    global $conn;
    if (!ensureAnnouncementTable()) return false;

    $text = cleanAnnouncementInput($text);
    $status = in_array($status, ['active', 'inactive'], true) ? $status : 'inactive';
    if ($text === '') $status = 'inactive';

    $plainText = strip_tags($text);
    if (function_exists('mb_strlen')) {
        $visibleLength = mb_strlen($plainText, 'UTF-8');
    } elseif (preg_match_all('/./us', $plainText, $characters) !== false) {
        $visibleLength = count($characters[0]);
    } else {
        $visibleLength = strlen($plainText);
    }
    if ($visibleLength > 1000) return false;

    try {
        $conn->begin_transaction();

        $existingId = 0;
        $check = $conn->query(
            "SELECT id FROM announcement_texts
             ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id ASC
             LIMIT 1 FOR UPDATE"
        );
        if ($check && ($row = $check->fetch_assoc())) {
            $existingId = (int) ($row['id'] ?? 0);
        }

        if ($existingId > 0) {
            $stmt = $conn->prepare('UPDATE announcement_texts SET text_content = ?, status = ? WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare announcement update');
            $stmt->bind_param('ssi', $text, $status, $existingId);
        } else {
            $stmt = $conn->prepare('INSERT INTO announcement_texts (text_content, status) VALUES (?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare announcement insert');
            $stmt->bind_param('ss', $text, $status);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to save announcement');
        }
        if ($existingId < 1) $existingId = (int) $conn->insert_id;
        $stmt->close();

        // Keep one canonical active announcement if an old installation
        // contains duplicate rows.
        if ($status === 'active') {
            $deactivate = $conn->prepare("UPDATE announcement_texts SET status = 'inactive' WHERE id <> ? AND status = 'active'");
            if (!$deactivate) throw new RuntimeException('Unable to prepare duplicate announcement cleanup');
            $deactivate->bind_param('i', $existingId);
            if (!$deactivate->execute()) {
                $deactivate->close();
                throw new RuntimeException('Unable to clean duplicate announcements');
            }
            $deactivate->close();
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Announcement update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all announcement texts (for admin)
 */
function getAllAnnouncements()
{
    global $conn;
    $sql = 'SELECT * FROM announcement_texts ORDER BY id DESC';
    $result = $conn->query($sql);
    if (!$result && ensureAnnouncementTable()) {
        $result = $conn->query($sql);
    }
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get announcement by ID
 */
function getAnnouncementById($id)
{
    global $conn;
    $id = (int) $id;
    if ($id < 1) return null;
    $stmt = $conn->prepare('SELECT * FROM announcement_texts WHERE id = ? LIMIT 1');
    if (!$stmt && ensureAnnouncementTable()) {
        $stmt = $conn->prepare('SELECT * FROM announcement_texts WHERE id = ? LIMIT 1');
    }
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * Delete announcement
 */
function deleteAnnouncement($id)
{
    global $conn;
    $id = (int) $id;
    if ($id < 1 || !ensureAnnouncementTable()) return false;
    $stmt = $conn->prepare('DELETE FROM announcement_texts WHERE id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Ensure store branding table exists
 */
function ensureStoreBrandingTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('store_branding');
    }

    $created = $conn->query("CREATE TABLE IF NOT EXISTS store_branding (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title_text VARCHAR(100) NOT NULL DEFAULT 'STORE',
        title_color VARCHAR(20) NOT NULL DEFAULT '#60a5fa',
        title_style VARCHAR(50) NOT NULL DEFAULT 'font-extrabold',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$created) return $ready = false;

    // Ensure a row exists (id=1) only in migration/cron context.
    $check = $conn->query("SELECT id FROM store_branding WHERE id = 1 LIMIT 1");
    if ($check && $check->num_rows === 0) {
        if (!$conn->query("INSERT INTO store_branding (id, title_text, title_color, title_style) VALUES (1, 'STORE', '#60a5fa', 'font-extrabold')")) {
            return $ready = false;
        }
    }
    return $ready = true;
}

/**
 * Ensure profit-analysis related columns exist.
 * - product_variants.cost_price
 * - keys.cost_price
 */
function ensureProfitColumns(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

    // Runtime requests only perform zero-row probes. They must never scan
    // INFORMATION_SCHEMA or execute ALTER TABLE while a customer is waiting.
    $variantProbe = @$conn->query("SELECT cost_price FROM product_variants LIMIT 0");
    $variantReady = $variantProbe !== false;
    if ($variantProbe instanceof mysqli_result) $variantProbe->free();

    $keyProbe = @$conn->query("SELECT cost_price FROM `keys` LIMIT 0");
    $keyReady = $keyProbe !== false;
    if ($keyProbe instanceof mysqli_result) $keyProbe->free();

    if ($variantReady && $keyReady) return $ready = true;
    if (!sakazukiSchemaMigrationsAllowed()) return $ready = false;

    if (!$variantReady) {
        if (!$conn->query("ALTER TABLE product_variants ADD COLUMN cost_price DOUBLE NOT NULL DEFAULT 0 AFTER price_reseller")) {
            $retry = @$conn->query("SELECT cost_price FROM product_variants LIMIT 0");
            if ($retry === false) return $ready = false;
            if ($retry instanceof mysqli_result) $retry->free();
        }
    }

    if (!$keyReady) {
        if (!$conn->query("ALTER TABLE `keys` ADD COLUMN cost_price DOUBLE NOT NULL DEFAULT 0 AFTER price_reseller")) {
            $retry = @$conn->query("SELECT cost_price FROM `keys` LIMIT 0");
            if ($retry === false) return $ready = false;
            if ($retry instanceof mysqli_result) $retry->free();
        }
    }

    return $ready = true;
}

/**
 * Ensure account-specific custom prices table exists (variant-level override).
 * This replaces legacy reseller_product_prices.
 */
function ensureResellerVariantPricesTable()
{
    global $conn;
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('reseller_variant_prices');
    }

    try {
        $created = $conn->query("CREATE TABLE IF NOT EXISTS reseller_variant_prices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reseller_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            custom_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            below_cost_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            confirmed_cost DECIMAL(12,2) NULL,
            confirmed_at DATETIME NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_reseller_variant (reseller_id, variant_id),
            KEY idx_reseller (reseller_id),
            KEY idx_variant (variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$created) {
            error_log('Special-price table creation failed: ' . $conn->error);
            $ready = false;
            return false;
        }

        // Existing installations may have created this table before below-cost
        // confirmation metadata existed. Add only the missing columns and cache
        // the result for the rest of the request so normal storefront reads do
        // not repeat INFORMATION_SCHEMA work.
        $requiredColumns = [
            'below_cost_confirmed' => "ALTER TABLE reseller_variant_prices ADD COLUMN below_cost_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
            'confirmed_cost' => "ALTER TABLE reseller_variant_prices ADD COLUMN confirmed_cost DECIMAL(12,2) NULL AFTER below_cost_confirmed",
            'confirmed_at' => "ALTER TABLE reseller_variant_prices ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_cost",
        ];
        $existingColumns = [];
        $columnResult = $conn->query(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'reseller_variant_prices'
               AND COLUMN_NAME IN ('below_cost_confirmed', 'confirmed_cost', 'confirmed_at')"
        );
        if (!$columnResult) {
            error_log('Special-price column check failed: ' . $conn->error);
            $ready = false;
            return false;
        }
        while ($columnRow = $columnResult->fetch_assoc()) {
            $existingColumns[(string) ($columnRow['COLUMN_NAME'] ?? '')] = true;
        }

        foreach ($requiredColumns as $column => $alterSql) {
            if (isset($existingColumns[$column])) continue;
            if ($conn->query($alterSql)) {
                $existingColumns[$column] = true;
                continue;
            }
            $alterError = $conn->error;

            // Two requests can perform the first migration at the same time.
            // If another request added the column after our initial check, treat
            // that duplicate-column race as success instead of disabling prices.
            $escapedColumn = $conn->real_escape_string($column);
            $verify = $conn->query(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'reseller_variant_prices'
                   AND COLUMN_NAME = '{$escapedColumn}'
                 LIMIT 1"
            );
            if ($verify && $verify->num_rows > 0) {
                $existingColumns[$column] = true;
                continue;
            }

            error_log('Special-price column migration failed for ' . $column . ': ' . $alterError);
            $ready = false;
            return false;
        }

        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('Special-price table preparation exception: ' . $e->getMessage());
        $ready = false;
        return false;
    }
}

/**
 * Load every active variant-level price override for one account.
 *
 * The store page, AJAX variant endpoint and purchase functions must all use
 * this same source. Keeping a single map per request also avoids one database
 * query per available key on catalogue pages.
 */
function getResellerVariantPriceMap($resellerId): array
{
    global $conn;
    static $requestCache = [];
    $resellerId = (int) $resellerId;
    if ($resellerId < 1 || !ensureResellerVariantPricesTable()) {
        return [];
    }
    if (array_key_exists($resellerId, $requestCache)) {
        return $requestCache[$resellerId];
    }

    $stmt = $conn->prepare(
        "SELECT variant_id, custom_price
         FROM reseller_variant_prices
         WHERE reseller_id = ? AND status = 'active'"
    );
    if (!$stmt) {
        return $requestCache[$resellerId] = [];
    }
    $stmt->bind_param('i', $resellerId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $requestCache[$resellerId] = [];
    }

    $result = $stmt->get_result();
    $prices = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $variantId = (int) ($row['variant_id'] ?? 0);
            $price = (float) ($row['custom_price'] ?? 0);
            if ($variantId > 0 && is_finite($price) && $price >= 0 && $price <= 10000000) {
                $prices[$variantId] = round($price, 2);
            }
        }
    }
    $stmt->close();
    return $requestCache[$resellerId] = $prices;
}

/**
 * Get the effective account price for a key/variant.
 */
function getEffectiveResellerPrice($resellerId, $variantId, $defaultPrice)
{
    $resellerId = (int) $resellerId;
    $variantId = (int) $variantId;
    $defaultPrice = round((float) $defaultPrice, 2);
    if ($resellerId < 1 || $variantId < 1) {
        return $defaultPrice;
    }

    static $requestCache = [];
    if (!array_key_exists($resellerId, $requestCache)) {
        $requestCache[$resellerId] = getResellerVariantPriceMap($resellerId);
    }

    return array_key_exists($variantId, $requestCache[$resellerId])
        ? (float) $requestCache[$resellerId][$variantId]
        : $defaultPrice;
}


/**
 * Return one active account-specific price override with its below-cost audit
 * metadata. The confirmed cost is the highest local/API cost explicitly
 * accepted by an administrator at the time the override was saved.
 */
function getResellerVariantPriceDetails($resellerId, $variantId): ?array
{
    global $conn;
    $resellerId = (int) $resellerId;
    $variantId = (int) $variantId;
    if ($resellerId < 1 || $variantId < 1 || !ensureResellerVariantPricesTable()) {
        return null;
    }

    static $requestCache = [];
    $cacheKey = $resellerId . ':' . $variantId;
    if (array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    $stmt = $conn->prepare(
        "SELECT custom_price, status, below_cost_confirmed, confirmed_cost, confirmed_at
         FROM reseller_variant_prices
         WHERE reseller_id = ? AND variant_id = ? AND status = 'active'
         LIMIT 1"
    );
    if (!$stmt) {
        $requestCache[$cacheKey] = null;
        return null;
    }
    $stmt->bind_param('ii', $resellerId, $variantId);
    if (!$stmt->execute()) {
        $stmt->close();
        $requestCache[$cacheKey] = null;
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        $requestCache[$cacheKey] = null;
        return null;
    }

    $price = round((float) ($row['custom_price'] ?? 0), 2);
    if (!is_finite($price) || $price <= 0 || $price > 10000000) {
        $requestCache[$cacheKey] = null;
        return null;
    }
    $details = [
        'custom_price' => $price,
        'status' => 'active',
        'below_cost_confirmed' => (int) ($row['below_cost_confirmed'] ?? 0) === 1,
        'confirmed_cost' => is_numeric($row['confirmed_cost'] ?? null)
            ? round(max(0.0, (float) $row['confirmed_cost']), 2)
            : null,
        'confirmed_at' => $row['confirmed_at'] ?? null,
    ];
    $requestCache[$cacheKey] = $details;
    return $details;
}

/**
 * Validate whether an account-specific override may remain below the current
 * supplier cost. A later cost increase invalidates the old confirmation and
 * requires the administrator to confirm the new cost in the pricing page.
 */
function resellerVariantPriceAllowsCost(?array $details, $price, $currentCost): bool
{
    $price = round((float) $price, 2);
    $currentCost = round((float) $currentCost, 2);
    if (!is_finite($price) || !is_finite($currentCost) || $price <= 0 || $currentCost < 0) {
        return false;
    }
    if ($price + 0.00001 >= $currentCost) {
        return true;
    }
    if (!$details || empty($details['below_cost_confirmed'])) {
        return false;
    }
    $overridePrice = round((float) ($details['custom_price'] ?? 0), 2);
    $confirmedCost = is_numeric($details['confirmed_cost'] ?? null)
        ? round((float) $details['confirmed_cost'], 2)
        : 0.0;
    return abs($overridePrice - $price) < 0.00001
        && $confirmedCost + 0.00001 >= $currentCost;
}


/**
 * Return a validated title-image asset for the user or reseller navbar.
 * Missing files are treated as disabled so a stale database path cannot
 * render a broken image. The version value is used for cache busting.
 */
function getTitleIconAsset(string $audience): array
{
    $settingKey = $audience === 'reseller' ? 'reseller_title_icon' : 'user_title_icon';
    $storedPath = ltrim(trim((string) (getSetting($settingKey) ?? '')), '/');
    $empty = [
        'path' => '',
        'stored_path' => $storedPath,
        'version' => 0,
        'exists' => false,
    ];

    if ($storedPath === '' || !preg_match('#^assets/uploads/icons/[A-Za-z0-9_-]+\.png$#', $storedPath)) {
        return $empty;
    }

    $publicRoot = dirname(__DIR__);
    $iconsRoot = realpath($publicRoot . '/assets/uploads/icons');
    $fullPath = realpath($publicRoot . '/' . $storedPath);

    if ($iconsRoot === false || $fullPath === false || !is_file($fullPath)) {
        return $empty;
    }

    $iconsPrefix = rtrim($iconsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($fullPath, $iconsPrefix) !== 0) {
        return $empty;
    }

    return [
        'path' => $storedPath,
        'stored_path' => $storedPath,
        'version' => (int) (@filemtime($fullPath) ?: 0),
        'exists' => true,
    ];
}

/**
 * Get store branding (title) for USER navbar
 */
function getStoreBranding()
{
    global $conn;
    ensureStoreBrandingTable();

    $res = $conn->query("SELECT title_text, title_color, title_style FROM store_branding WHERE id = 1 LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $allowedStyles = ['font-normal', 'font-medium', 'font-semibold', 'font-bold', 'font-extrabold', 'italic', 'uppercase', 'underline'];
        $styleParts = preg_split('/\s+/', trim((string) ($row['title_style'] ?? '')));
        $styleParts = array_values(array_filter($styleParts, function ($part) use ($allowedStyles) {
            return in_array($part, $allowedStyles, true);
        }));
        $row['title_style'] = $styleParts ? implode(' ', $styleParts) : 'font-extrabold';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($row['title_color'] ?? ''))) {
            $row['title_color'] = '#60a5fa';
        }
        $row['title_text'] = trim((string) ($row['title_text'] ?? 'STORE')) ?: 'STORE';
        return $row;
    }
    return ['title_text' => 'STORE', 'title_color' => '#60a5fa', 'title_style' => 'font-extrabold'];
}

/**
 * Update store branding (title) for USER navbar
 */
function updateStoreBranding($titleText, $titleColor, $titleStyle)
{
    global $conn;

    $titleText = trim($titleText);
    if ($titleText === '')
        $titleText = 'STORE';

    // Allowlist Tailwind utility classes for style
    $allowedStyles = [
        'font-normal',
        'font-medium',
        'font-semibold',
        'font-bold',
        'font-extrabold',
        'italic',
        'uppercase',
        'underline'
    ];

    $styleParts = preg_split('/\s+/', trim($titleStyle));
    $styleParts = array_values(array_filter($styleParts, function ($p) use ($allowedStyles) {
        return in_array($p, $allowedStyles, true);
    }));
    $titleStyle = implode(' ', $styleParts);
    if ($titleStyle === '')
        $titleStyle = 'font-extrabold';

    $titleColor = trim($titleColor);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $titleColor)) {
        $titleColor = '#60a5fa';
    }

    if (function_exists('mb_substr')) {
        $titleText = mb_substr($titleText, 0, 100, 'UTF-8');
    } else {
        $titleText = substr($titleText, 0, 100);
    }

    $stmt = $conn->prepare('UPDATE store_branding SET title_text = ?, title_color = ?, title_style = ? WHERE id = 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $titleText, $titleColor, $titleStyle);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Ensure categories table exists
 */
function ensureCategoriesTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('categories');
    }
    $created = $conn->query("CREATE TABLE IF NOT EXISTS categories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL UNIQUE,
        download_url TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $ready = (bool) $created;
}

/**
 * Get download URL for a category
 */
function getCategoryDownloadUrl($categoryName)
{
    global $conn;
    ensureCategoriesTable();
    $categoryName = trim((string) $categoryName);
    if ($categoryName === '' || strlen($categoryName) > 255) return null;
    $stmt = $conn->prepare('SELECT download_url FROM categories WHERE name = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $categoryName);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $stmt->bind_result($url);
    $found = $stmt->fetch();
    $stmt->close();
    $url = $found ? trim((string) $url) : '';
    return $url !== '' && function_exists('isSafeHttpsUrl') && isSafeHttpsUrl($url) ? $url : null;
}

/**
 * Update or create category download URL
 */
function setCategoryDownloadUrl($categoryName, $url)
{
    global $conn;
    ensureCategoriesTable();
    $categoryName = trim((string) $categoryName);
    $url = trim((string) $url);
    if ($categoryName === '' || strlen($categoryName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $categoryName)) {
        return false;
    }
    if ($url !== '' && (strlen($url) > 2048 || !function_exists('isSafeHttpsUrl') || !isSafeHttpsUrl($url))) {
        return false;
    }
    $stmt = $conn->prepare('INSERT INTO categories (name, download_url) VALUES (?, ?) ON DUPLICATE KEY UPDATE download_url = VALUES(download_url), updated_at = NOW()');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $categoryName, $url);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Get all categories with download URLs
 */
function getAllCategoriesWithLinks()
{
    global $conn;
    ensureCategoriesTable();
    $res = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Get download URL for a product (checks all its categories)
 */
function getProductDownloadUrl($productId)
{
    global $conn;
    $productId = (int) $productId;
    if ($productId < 1) return null;
    $stmt = $conn->prepare('SELECT download_url FROM products WHERE id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $stmt->bind_result($url);
    $found = $stmt->fetch();
    $stmt->close();
    $url = $found ? trim((string) $url) : '';
    if ($url !== '' && function_exists('isSafeHttpsUrl') && isSafeHttpsUrl($url)) {
        return ['url' => $url];
    }
    return null;
}

// Fungsi-fungsi yang sudah ada berikutnya...

/**
 * Get all products
 */
function ensureProductCategoryLinksTable()
{
    global $conn;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return sakazukiTableReady('product_category_links');
    }
    return (bool) $conn->query("CREATE TABLE IF NOT EXISTS product_category_links (
        id INT PRIMARY KEY AUTO_INCREMENT,
        product_id INT NOT NULL,
        category VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(product_id),
        INDEX(category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


/**
 * Keep deleted catalogue entries out of management/store listings without
 * destroying rows that sold-key history still joins to. Products/variants with
 * no sales are still physically deleted by the admin page.
 */
function ensureProductArchiveTables(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('product_admin_archives')
            && sakazukiTableReady('product_variant_admin_archives');
    }

    $productTable = $conn->query("CREATE TABLE IF NOT EXISTS product_admin_archives (
        product_id INT NOT NULL PRIMARY KEY,
        archived_by BIGINT UNSIGNED NULL,
        reason VARCHAR(80) NOT NULL DEFAULT 'admin_delete',
        archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product_archive_time (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$productTable) {
        error_log('Unable to prepare product_admin_archives: ' . $conn->error);
        return $ready = false;
    }

    $variantTable = $conn->query("CREATE TABLE IF NOT EXISTS product_variant_admin_archives (
        variant_id INT NOT NULL PRIMARY KEY,
        product_id INT NOT NULL,
        archived_by BIGINT UNSIGNED NULL,
        reason VARCHAR(80) NOT NULL DEFAULT 'admin_delete',
        archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_variant_archive_product (product_id),
        INDEX idx_variant_archive_time (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$variantTable) {
        error_log('Unable to prepare product_variant_admin_archives: ' . $conn->error);
        return $ready = false;
    }

    return $ready = true;
}

function isProductAdminArchived(int $productId): bool
{
    global $conn;
    if ($productId < 1 || !ensureProductArchiveTables()) return false;
    $stmt = $conn->prepare('SELECT 1 FROM product_admin_archives WHERE product_id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->store_result();
    $archived = $stmt->num_rows > 0;
    $stmt->close();
    return $archived;
}

function isProductVariantAdminArchived(int $variantId): bool
{
    global $conn;
    if ($variantId < 1 || !ensureProductArchiveTables()) return false;
    $stmt = $conn->prepare('SELECT 1 FROM product_variant_admin_archives WHERE variant_id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $variantId);
    $stmt->execute();
    $stmt->store_result();
    $archived = $stmt->num_rows > 0;
    $stmt->close();
    return $archived;
}

/**
 * Set categories for a product (1..4 categories).
 */
function setProductCategories($productId, array $categories)
{
    global $conn;
    ensureProductCategoryLinksTable();
    $productId = (int) $productId;
    if ($productId < 1) return false;

    // Normalize, deduplicate, limit count and size.
    $norm = [];
    foreach ($categories as $c) {
        if (!is_scalar($c)) continue;
        $c = trim((string) $c);
        if ($c === '' || strlen($c) > 100) continue;
        if (!in_array($c, $norm, true)) $norm[] = $c;
        if (count($norm) >= 4) break;
    }
    if (!$norm) return false;

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare('DELETE FROM product_category_links WHERE product_id = ?');
        $insert = $conn->prepare('INSERT INTO product_category_links (product_id, category) VALUES (?, ?)');
        if (!$delete || !$insert) throw new RuntimeException('category statement preparation failed');
        $delete->bind_param('i', $productId);
        if (!$delete->execute()) throw new RuntimeException('category reset failed');
        $delete->close();
        foreach ($norm as $category) {
            $insert->bind_param('is', $productId, $category);
            if (!$insert->execute()) throw new RuntimeException('category insert failed');
        }
        $insert->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Product category update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clear all category links for a product.
 */
function clearProductCategories($productId)
{
    global $conn;
    ensureProductCategoryLinksTable();
    $productId = (int) $productId;
    $conn->query("DELETE FROM product_category_links WHERE product_id = $productId");
    return true;
}


/**
 * Get categories for a product.
 */
function getProductCategories($productId)
{
    global $conn;
    ensureProductCategoryLinksTable();
    $productId = (int) $productId;
    $res = $conn->query("SELECT category FROM product_category_links WHERE product_id = $productId ORDER BY id ASC");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['category']))
                $out[] = $row['category'];
        }
    }
    return $out;
}

/**
 * Keep product platform metadata separate from the legacy products table.
 * This avoids a risky ALTER TABLE on existing rental-site databases.
 */
function ensureProductPlatformsTable(): bool
{
    global $conn;
    static $ready = false;
    if ($ready) return true;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;
    if (!sakazukiSchemaMigrationsAllowed() || sakazukiStorefrontSchemaPrepared()) {
        return $ready = sakazukiTableReady('product_platforms');
    }

    $sql = "CREATE TABLE IF NOT EXISTS product_platforms (
        product_id INT NOT NULL PRIMARY KEY,
        platform VARCHAR(60) NOT NULL DEFAULT 'both',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_platform (platform)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('Unable to prepare product_platforms table: ' . $conn->error);
        return false;
    }

    // Older installations used VARCHAR(10), which is too small for supplier
    // values such as "account" or future platform labels.
    $lengthResult = $conn->query("SELECT CHARACTER_MAXIMUM_LENGTH AS max_len FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_platforms' AND COLUMN_NAME = 'platform' LIMIT 1");
    $lengthRow = $lengthResult ? $lengthResult->fetch_assoc() : null;
    if ($lengthRow && (int) ($lengthRow['max_len'] ?? 0) < 60) {
        if (!$conn->query("ALTER TABLE product_platforms MODIFY COLUMN platform VARCHAR(60) NOT NULL DEFAULT 'both'")) {
            error_log('Unable to expand product platform column: ' . $conn->error);
            return false;
        }
    }

    $ready = true;
    return true;
}

function normalizeProductPlatform($platform, string $fallback = 'both'): string
{
    $platform = is_scalar($platform) ? strtolower(trim((string) $platform)) : '';
    $fallback = strtolower(trim($fallback));
    if ($platform === '') {
        return $fallback;
    }
    $platform = preg_replace('/[^a-z0-9_+.-]+/', '_', $platform) ?? '';
    $platform = trim($platform, '_.-');
    if ($platform === '') {
        return $fallback;
    }
    return substr($platform, 0, 60);
}

function inferLegacyProductPlatform(string $productName): string
{
    return stripos($productName, 'ios') !== false ? 'ios' : 'android';
}

function setProductPlatform(int $productId, string $platform): bool
{
    global $conn;
    $platform = normalizeProductPlatform($platform, 'both');
    if ($productId < 1 || !ensureProductPlatformsTable()) return false;
    $stmt = $conn->prepare(
        'INSERT INTO product_platforms (product_id, platform) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE platform = VALUES(platform), updated_at = NOW()'
    );
    if (!$stmt) return false;
    $stmt->bind_param('is', $productId, $platform);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function deleteProductPlatform(int $productId): bool
{
    global $conn;
    if ($productId < 1 || !ensureProductPlatformsTable()) return false;
    $stmt = $conn->prepare('DELETE FROM product_platforms WHERE product_id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('i', $productId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Get products with categories (supports 1..4 categories per product).
 * Returns each product with:
 * - categories (array)
 * - category (string, first category for backward compatibility)
 */
function getProducts($status = 'active')
{
    global $conn;
    ensureProductCategoryLinksTable();
    $platformTableReady = ensureProductPlatformsTable();
    $archiveTableReady = ensureProductArchiveTables();

    $normalizedStatus = $status === null ? 'all' : strtolower(trim((string) $status));
    if (!in_array($normalizedStatus, ['active', 'inactive', 'all'], true)) {
        $normalizedStatus = 'active';
    }
    $platformSelect = $platformTableReady ? ', MAX(pp.platform) AS stored_platform' : ", NULL AS stored_platform";
    $platformJoin = $platformTableReady ? ' LEFT JOIN product_platforms pp ON pp.product_id = p.id' : '';
    $archiveJoin = $archiveTableReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id = p.id' : '';
    $sql = "SELECT p.*,
                   GROUP_CONCAT(l.category ORDER BY l.id SEPARATOR '||') AS categories_concat"
           . $platformSelect
           . " FROM products p
              LEFT JOIN product_category_links l ON l.product_id = p.id"
           . $platformJoin
           . $archiveJoin;

    $where = [];
    if ($archiveTableReady) $where[] = 'paa.product_id IS NULL';
    if ($normalizedStatus !== 'all') $where[] = "p.status = '" . $normalizedStatus . "'";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    $sql .= " GROUP BY p.id ORDER BY p.name ASC";

    $result = $conn->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    foreach ($rows as &$r) {
        $cats = [];
        if (!empty($r['categories_concat'])) {
            $parts = explode('||', $r['categories_concat']);
            foreach ($parts as $c) {
                $c = trim($c);
                if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c;
            }
        }
        if (empty($cats) && !empty($r['category'])) {
            $cats = [$r['category']];
        }
        $cats = array_slice($cats, 0, 4);
        $r['categories'] = $cats;
        if (!empty($cats)) $r['category'] = $cats[0];

        $storedPlatform = normalizeProductPlatform($r['stored_platform'] ?? '', '');
        $r['platform'] = $storedPlatform !== ''
            ? $storedPlatform
            : inferLegacyProductPlatform((string) ($r['name'] ?? ''));
        unset($r['categories_concat'], $r['stored_platform']);
    }
    unset($r);

    return $rows;
}



/** Bind a dynamic list of scalar parameters without leaking SQL filtering to PHP. */
function sakazukiBindStatementValues(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '') return true;
    if (strlen($types) !== count($values)) return false;
    $args = [$types];
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $args[] = &$values[$index];
    }
    return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
}

/** SQL expression matching the legacy platform fallback used by getProducts(). */
function storefrontProductPlatformSql(bool $platformTableReady = true): string
{
    $fallback = "CASE WHEN LOWER(p.name) LIKE '%ios%' THEN 'ios' ELSE 'android' END";
    if (!$platformTableReady) return $fallback;
    return "COALESCE(NULLIF(TRIM(pp.platform), ''), {$fallback})";
}

/**
 * Return lightweight filter metadata without loading the entire catalogue into PHP.
 * Only grouped counts are transferred from MySQL.
 */
function getStorefrontCatalogueFacets(string $status = 'active', string $selectedPlatform = 'all'): array
{
    global $conn;
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    $selectedPlatform = normalizeProductPlatform($selectedPlatform, 'all');
    if ($selectedPlatform === '') $selectedPlatform = 'all';

    // Optional storefront metadata must stay optional. The legacy getProducts()
    // path already falls back safely when one of these deployment tables is
    // unavailable; the paged path must preserve that compatibility.
    $categoryReady = ensureProductCategoryLinksTable();
    $platformReady = ensureProductPlatformsTable();
    $archiveReady = ensureProductArchiveTables();

    $platformExpr = storefrontProductPlatformSql($platformReady);
    $platformJoin = $platformReady ? ' LEFT JOIN product_platforms pp ON pp.product_id = p.id' : '';
    $archiveJoin = $archiveReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id = p.id' : '';
    $archiveWhere = $archiveReady ? ' AND paa.product_id IS NULL' : '';

    $stmt = $conn->prepare(
        "SELECT {$platformExpr} AS platform_key, COUNT(*) AS product_count
         FROM products p{$platformJoin}{$archiveJoin}
         WHERE p.status = ?{$archiveWhere}
         GROUP BY platform_key"
    );
    $platformCounts = [];
    if ($stmt) {
        $stmt->bind_param('s', $status);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $key = normalizeProductPlatform((string) ($row['platform_key'] ?? ''), '');
                if ($key !== '') $platformCounts[$key] = max(0, (int) ($row['product_count'] ?? 0));
            }
        } else {
            error_log('Storefront platform facet query failed: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log('Storefront platform facet prepare failed: ' . $conn->error);
    }

    $allowedPlatforms = ['all', 'android', 'ios', 'both', 'account'];
    foreach (array_keys($platformCounts) as $key) {
        if (!in_array($key, $allowedPlatforms, true)) $allowedPlatforms[] = $key;
    }
    if (!in_array($selectedPlatform, $allowedPlatforms, true)) $selectedPlatform = 'all';

    $wherePlatform = '';
    $types = 's';
    $values = [$status];
    if ($selectedPlatform === 'android' || $selectedPlatform === 'ios') {
        $wherePlatform = " AND {$platformExpr} IN (?, 'both')";
        $types .= 's';
        $values[] = $selectedPlatform;
    } elseif ($selectedPlatform !== 'all') {
        $wherePlatform = " AND {$platformExpr} = ?";
        $types .= 's';
        $values[] = $selectedPlatform;
    }

    $categoryCounts = [];
    if ($categoryReady) {
        $categoryExpr = "COALESCE(NULLIF(TRIM(l.category), ''), NULLIF(TRIM(p.category), ''))";
        $categorySql =
            "SELECT {$categoryExpr} AS category, COUNT(DISTINCT p.id) AS product_count
             FROM products p{$platformJoin}{$archiveJoin}
             LEFT JOIN product_category_links l ON l.product_id = p.id
             WHERE p.status = ?{$archiveWhere}{$wherePlatform}
               AND {$categoryExpr} IS NOT NULL
             GROUP BY category
             ORDER BY category ASC";
    } else {
        $categoryExpr = "NULLIF(TRIM(p.category), '')";
        $categorySql =
            "SELECT {$categoryExpr} AS category, COUNT(*) AS product_count
             FROM products p{$platformJoin}{$archiveJoin}
             WHERE p.status = ?{$archiveWhere}{$wherePlatform}
               AND {$categoryExpr} IS NOT NULL
             GROUP BY category
             ORDER BY category ASC";
    }
    $categoryStmt = $conn->prepare($categorySql);
    if ($categoryStmt) {
        if (sakazukiBindStatementValues($categoryStmt, $types, $values) && $categoryStmt->execute()) {
            $result = $categoryStmt->get_result();
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $category = trim((string) ($row['category'] ?? ''));
                if ($category !== '') $categoryCounts[$category] = max(0, (int) ($row['product_count'] ?? 0));
            }
        } else {
            error_log('Storefront category facet query failed: ' . $categoryStmt->error);
        }
        $categoryStmt->close();
    } else {
        error_log('Storefront category facet prepare failed: ' . $conn->error);
    }

    $platformProductCount = 0;
    if ($selectedPlatform === 'all') {
        $platformProductCount = array_sum($platformCounts);
    } elseif ($selectedPlatform === 'android' || $selectedPlatform === 'ios') {
        $platformProductCount = (int) ($platformCounts[$selectedPlatform] ?? 0) + (int) ($platformCounts['both'] ?? 0);
    } else {
        $platformProductCount = (int) ($platformCounts[$selectedPlatform] ?? 0);
    }

    return [
        'selected_platform' => $selectedPlatform,
        'allowed_platforms' => $allowedPlatforms,
        'category_counts' => $categoryCounts,
        'platform_product_count' => $platformProductCount,
        'platform_counts' => $platformCounts,
    ];
}

/**
 * Fetch one storefront page directly from MySQL. Filtering, counting and LIMIT
 * happen in SQL so catalogue size no longer determines PHP memory or render time.
 */
function getStorefrontProductsPage(
    string $status,
    string $platform,
    string $category,
    string $search,
    int $page = 1,
    int $perPage = 24,
    array $searchCategoryAliases = []
): array {
    global $conn;
    $status = strtolower(trim($status));
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    $platform = normalizeProductPlatform($platform, 'all');
    if ($platform === '') $platform = 'all';
    $category = trim($category);
    if ($category === '') $category = 'all';
    $search = function_exists('mb_substr')
        ? mb_substr(trim($search), 0, 120, 'UTF-8')
        : substr(trim($search), 0, 120);
    $page = max(1, $page);
    $perPage = max(6, min(60, $perPage));

    $categoryReady = ensureProductCategoryLinksTable();
    $platformReady = ensureProductPlatformsTable();
    $archiveReady = ensureProductArchiveTables();
    $platformExpr = storefrontProductPlatformSql($platformReady);

    $where = ['p.status = ?'];
    $types = 's';
    $values = [$status];
    if ($archiveReady) $where[] = 'paa.product_id IS NULL';

    if ($platform === 'android' || $platform === 'ios') {
        $where[] = "{$platformExpr} IN (?, 'both')";
        $types .= 's';
        $values[] = $platform;
    } elseif ($platform !== 'all') {
        $where[] = "{$platformExpr} = ?";
        $types .= 's';
        $values[] = $platform;
    }

    if ($category !== 'all') {
        if ($categoryReady) {
            $where[] = "(
                EXISTS (SELECT 1 FROM product_category_links fl WHERE fl.product_id = p.id AND TRIM(fl.category) = ?)
                OR (
                    NOT EXISTS (SELECT 1 FROM product_category_links fx WHERE fx.product_id = p.id AND TRIM(fx.category) <> '')
                    AND TRIM(p.category) = ?
                )
            )";
            $types .= 'ss';
            $values[] = $category;
            $values[] = $category;
        } else {
            $where[] = 'TRIM(COALESCE(p.category,\'\')) = ?';
            $types .= 's';
            $values[] = $category;
        }
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchParts = ['p.name LIKE ?', 'p.description LIKE ?', 'p.category LIKE ?'];
        $types .= 'sss';
        array_push($values, $like, $like, $like);
        if ($categoryReady) {
            $searchParts[] = 'EXISTS (SELECT 1 FROM product_category_links sl WHERE sl.product_id = p.id AND sl.category LIKE ?)';
            $types .= 's';
            $values[] = $like;
        }

        $aliases = [];
        foreach ($searchCategoryAliases as $alias) {
            if (!is_scalar($alias)) continue;
            $alias = trim((string) $alias);
            if ($alias === '' || strlen($alias) > 255) continue;
            $aliases[$alias] = $alias;
            if (count($aliases) >= 20) break;
        }
        if ($aliases !== []) {
            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            if ($categoryReady) {
                $searchParts[] = "(p.category IN ({$placeholders}) OR EXISTS (SELECT 1 FROM product_category_links sal WHERE sal.product_id = p.id AND sal.category IN ({$placeholders})))";
                $types .= str_repeat('s', count($aliases) * 2);
                foreach ($aliases as $alias) $values[] = $alias;
                foreach ($aliases as $alias) $values[] = $alias;
            } else {
                $searchParts[] = "p.category IN ({$placeholders})";
                $types .= str_repeat('s', count($aliases));
                foreach ($aliases as $alias) $values[] = $alias;
            }
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    $whereSql = implode(' AND ', $where);
    $platformJoin = $platformReady ? ' LEFT JOIN product_platforms pp ON pp.product_id = p.id' : '';
    $archiveJoin = $archiveReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id = p.id' : '';
    $baseFrom = " FROM products p{$platformJoin}{$archiveJoin}";

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total' . $baseFrom . ' WHERE ' . $whereSql);
    $total = 0;
    if ($countStmt) {
        if (sakazukiBindStatementValues($countStmt, $types, $values) && $countStmt->execute()) {
            $result = $countStmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $total = max(0, (int) ($row['total'] ?? 0));
        } else {
            error_log('Storefront product count query failed: ' . $countStmt->error);
        }
        $countStmt->close();
    } else {
        error_log('Storefront product count prepare failed: ' . $conn->error);
    }

    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;
    $categorySelect = $categoryReady
        ? "(SELECT GROUP_CONCAT(cl.category ORDER BY cl.id SEPARATOR '||') FROM product_category_links cl WHERE cl.product_id = p.id)"
        : 'NULL';
    $selectSql =
        "SELECT p.*, {$categorySelect} AS categories_concat, {$platformExpr} AS stored_platform" .
        $baseFrom .
        " WHERE {$whereSql}
          ORDER BY p.name ASC, p.id ASC
          LIMIT ? OFFSET ?";
    $selectStmt = $conn->prepare($selectSql);
    $rows = [];
    if ($selectStmt) {
        $selectTypes = $types . 'ii';
        $selectValues = $values;
        $selectValues[] = $perPage;
        $selectValues[] = $offset;
        if (sakazukiBindStatementValues($selectStmt, $selectTypes, $selectValues) && $selectStmt->execute()) {
            $result = $selectStmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } else {
            error_log('Storefront product page query failed: ' . $selectStmt->error);
        }
        $selectStmt->close();
    } else {
        error_log('Storefront product page prepare failed: ' . $conn->error);
    }

    foreach ($rows as &$row) {
        $categories = [];
        if (!empty($row['categories_concat'])) {
            foreach (explode('||', (string) $row['categories_concat']) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && !in_array($candidate, $categories, true)) $categories[] = $candidate;
                if (count($categories) >= 4) break;
            }
        }
        if ($categories === [] && !empty($row['category'])) $categories = [(string) $row['category']];
        $row['categories'] = $categories;
        if ($categories !== []) $row['category'] = $categories[0];
        $row['platform'] = normalizeProductPlatform((string) ($row['stored_platform'] ?? ''), 'other');
        unset($row['categories_concat'], $row['stored_platform']);
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
    ];
}

/**
 * Return admin catalogue filter metadata without materializing every product.
 */
function getAdminProductCatalogueFacets(): array
{
    global $conn;
    $categoryReady = ensureProductCategoryLinksTable();
    $platformReady = ensureProductPlatformsTable();
    $archiveReady = ensureProductArchiveTables();
    $platformExpr = storefrontProductPlatformSql($platformReady);
    $platformJoin = $platformReady ? ' LEFT JOIN product_platforms pp ON pp.product_id=p.id' : '';
    $archiveJoin = $archiveReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id=p.id' : '';
    $archiveWhere = $archiveReady ? ' WHERE paa.product_id IS NULL' : '';

    $total = 0;
    $totalResult = $conn->query("SELECT COUNT(*) AS total FROM products p{$archiveJoin}{$archiveWhere}");
    if ($totalResult) {
        $row = $totalResult->fetch_assoc();
        $total = max(0, (int) ($row['total'] ?? 0));
        $totalResult->free();
    }

    $platforms = ['android' => true, 'ios' => true, 'both' => true, 'account' => true];
    $platformResult = $conn->query(
        "SELECT {$platformExpr} AS platform_key
         FROM products p{$platformJoin}{$archiveJoin}" .
        ($archiveReady ? ' WHERE paa.product_id IS NULL' : '') .
        ' GROUP BY platform_key'
    );
    if ($platformResult) {
        while ($row = $platformResult->fetch_assoc()) {
            $key = normalizeProductPlatform((string) ($row['platform_key'] ?? ''), '');
            if ($key !== '') $platforms[$key] = true;
        }
        $platformResult->free();
    } else {
        error_log('Admin product platform facet query failed: ' . $conn->error);
    }

    $categories = [];
    if ($categoryReady) {
        $archiveJoinA = $archiveReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id=p.id' : '';
        $archiveWhereA = $archiveReady ? ' AND paa.product_id IS NULL' : '';
        $categorySql =
            "SELECT category FROM (
                SELECT TRIM(l.category) AS category
                FROM product_category_links l
                JOIN products p ON p.id=l.product_id{$archiveJoinA}
                WHERE TRIM(l.category)<>''{$archiveWhereA}
                UNION
                SELECT TRIM(p.category) AS category
                FROM products p{$archiveJoinA}
                WHERE TRIM(COALESCE(p.category,''))<>''{$archiveWhereA}
                  AND NOT EXISTS (
                      SELECT 1 FROM product_category_links lx
                      WHERE lx.product_id=p.id AND TRIM(lx.category)<>''
                  )
            ) categories
            WHERE category<>''
            ORDER BY category ASC";
    } else {
        $categorySql =
            "SELECT DISTINCT TRIM(p.category) AS category
             FROM products p{$archiveJoin}
             WHERE TRIM(COALESCE(p.category,''))<>''" .
             ($archiveReady ? ' AND paa.product_id IS NULL' : '') .
            ' ORDER BY category ASC';
    }
    $categoryResult = $conn->query($categorySql);
    if ($categoryResult) {
        while ($row = $categoryResult->fetch_assoc()) {
            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '') $categories[] = $category;
        }
        $categoryResult->free();
    } else {
        error_log('Admin product category facet query failed: ' . $conn->error);
    }

    return [
        'total' => $total,
        'categories' => array_values(array_unique($categories)),
        'platforms' => array_keys($platforms),
    ];
}

/**
 * Admin product listing with SQL-side search, filtering, sorting and pagination.
 * Variant-duration matches are resolved with EXISTS so one product stays one row.
 */
function getAdminProductsPage(
    string $search,
    string $category,
    string $status,
    string $platform,
    string $sort,
    int $page,
    int $perPage
): array {
    global $conn;
    $search = function_exists('mb_substr')
        ? mb_substr(trim($search), 0, 120, 'UTF-8')
        : substr(trim($search), 0, 120);
    $category = trim($category);
    $status = strtolower(trim($status));
    $platform = normalizeProductPlatform($platform, '');
    $sort = strtolower(trim($sort));
    $page = max(1, $page);
    $perPage = in_array($perPage, [12, 24, 48, 96], true) ? $perPage : 24;
    if (!in_array($status, ['', 'active', 'inactive'], true)) $status = '';
    if (!in_array($sort, ['name_asc', 'name_desc', 'newest', 'oldest', 'status'], true)) $sort = 'name_asc';

    $categoryReady = ensureProductCategoryLinksTable();
    $platformReady = ensureProductPlatformsTable();
    $archiveReady = ensureProductArchiveTables();
    $platformExpr = storefrontProductPlatformSql($platformReady);
    $where = [];
    $types = '';
    $values = [];
    if ($archiveReady) $where[] = 'paa.product_id IS NULL';

    if ($status !== '') {
        $where[] = 'p.status=?';
        $types .= 's';
        $values[] = $status;
    }
    if ($platform !== '') {
        $where[] = "{$platformExpr}=?";
        $types .= 's';
        $values[] = $platform;
    }
    if ($category !== '') {
        if ($categoryReady) {
            $where[] = "(
                EXISTS (SELECT 1 FROM product_category_links fl WHERE fl.product_id=p.id AND TRIM(fl.category)=?)
                OR (
                    NOT EXISTS (SELECT 1 FROM product_category_links fx WHERE fx.product_id=p.id AND TRIM(fx.category)<>'')
                    AND TRIM(COALESCE(p.category,''))=?
                )
            )";
            $types .= 'ss';
            $values[] = $category;
            $values[] = $category;
        } else {
            $where[] = 'TRIM(COALESCE(p.category,\'\'))=?';
            $types .= 's';
            $values[] = $category;
        }
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $idNeedle = ltrim($search, "# \t\n\r\0\x0B");
        $searchParts = ['p.name LIKE ?', 'p.description LIKE ?', 'p.category LIKE ?', "{$platformExpr} LIKE ?"];
        $types .= 'ssss';
        array_push($values, $like, $like, $like, $like);
        if ($categoryReady) {
            $searchParts[] = 'EXISTS (SELECT 1 FROM product_category_links sl WHERE sl.product_id=p.id AND sl.category LIKE ?)';
            $types .= 's';
            $values[] = $like;
        }
        $variantArchiveJoin = $archiveReady ? ' LEFT JOIN product_variant_admin_archives sva ON sva.variant_id=sv.id' : '';
        $variantArchiveWhere = $archiveReady ? ' AND sva.variant_id IS NULL' : '';
        $searchParts[] = "EXISTS (SELECT 1 FROM product_variants sv{$variantArchiveJoin} WHERE sv.product_id=p.id{$variantArchiveWhere} AND sv.duration LIKE ?)";
        $types .= 's';
        $values[] = $like;
        if ($idNeedle !== '' && ctype_digit($idNeedle)) {
            $searchParts[] = 'p.id=?';
            $types .= 'i';
            $values[] = (int) $idNeedle;
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    $whereSql = $where ? implode(' AND ', $where) : '1=1';
    $platformJoin = $platformReady ? ' LEFT JOIN product_platforms pp ON pp.product_id=p.id' : '';
    $archiveJoin = $archiveReady ? ' LEFT JOIN product_admin_archives paa ON paa.product_id=p.id' : '';
    $baseFrom = " FROM products p{$platformJoin}{$archiveJoin}";

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total' . $baseFrom . ' WHERE ' . $whereSql);
    $total = 0;
    if ($countStmt) {
        if (sakazukiBindStatementValues($countStmt, $types, $values) && $countStmt->execute()) {
            $result = $countStmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $total = max(0, (int) ($row['total'] ?? 0));
        } else {
            error_log('Admin product count query failed: ' . $countStmt->error);
        }
        $countStmt->close();
    } else {
        error_log('Admin product count prepare failed: ' . $conn->error);
    }

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    switch ($sort) {
        case 'name_desc': $orderSql = 'p.name DESC,p.id DESC'; break;
        case 'newest': $orderSql = 'p.created_at DESC,p.id DESC'; break;
        case 'oldest': $orderSql = 'p.created_at ASC,p.id ASC'; break;
        case 'status': $orderSql = 'p.status ASC,p.name ASC,p.id ASC'; break;
        default: $orderSql = 'p.name ASC,p.id ASC'; break;
    }

    $categorySelect = $categoryReady
        ? "(SELECT GROUP_CONCAT(cl.category ORDER BY cl.id SEPARATOR '||') FROM product_category_links cl WHERE cl.product_id=p.id)"
        : 'NULL';
    $selectSql =
        "SELECT p.*, {$categorySelect} AS categories_concat, {$platformExpr} AS stored_platform" .
        $baseFrom .
        " WHERE {$whereSql}
          ORDER BY {$orderSql}
          LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($selectSql);
    $rows = [];
    if ($stmt) {
        $selectValues = $values;
        $selectValues[] = $perPage;
        $selectValues[] = $offset;
        $selectTypes = $types . 'ii';
        if (sakazukiBindStatementValues($stmt, $selectTypes, $selectValues) && $stmt->execute()) {
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } else {
            error_log('Admin product page query failed: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log('Admin product page prepare failed: ' . $conn->error);
    }

    foreach ($rows as &$row) {
        $categories = [];
        if (!empty($row['categories_concat'])) {
            foreach (explode('||', (string) $row['categories_concat']) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && !in_array($candidate, $categories, true)) $categories[] = $candidate;
                if (count($categories) >= 4) break;
            }
        }
        if ($categories === [] && !empty($row['category'])) $categories = [(string) $row['category']];
        $row['categories'] = $categories;
        if ($categories !== []) $row['category'] = $categories[0];
        $row['platform'] = normalizeProductPlatform((string) ($row['stored_platform'] ?? ''), 'other');
        unset($row['categories_concat'], $row['stored_platform']);
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
    ];
}

/**
 * Build one shared active-product catalogue summary for user and reseller
 * dashboards. This keeps category/platform counts identical across roles.
 */
function getActiveCatalogueSummary(): array
{
    $products = getProducts('active');
    $categoryCounts = [];
    $platformCounts = [
        'all' => count($products),
        'android' => 0,
        'ios' => 0,
        'both' => 0,
    ];

    foreach ($products as $product) {
        $platform = normalizeProductPlatform((string) ($product['platform'] ?? 'both'));
        if ($platform === 'android') {
            $platformCounts['android']++;
        } elseif ($platform === 'ios') {
            $platformCounts['ios']++;
        } elseif ($platform === 'both') {
            $platformCounts['both']++;
            $platformCounts['android']++;
            $platformCounts['ios']++;
        } elseif ($platform !== '') {
            if (!isset($platformCounts[$platform])) $platformCounts[$platform] = 0;
            $platformCounts[$platform]++;
        }

        $categories = $product['categories'] ?? [];
        if (empty($categories) && !empty($product['category'])) {
            $categories = [$product['category']];
        }

        foreach (array_unique(array_map('strval', (array) $categories)) as $category) {
            $category = trim($category);
            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }
        }
    }

    ksort($categoryCounts, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'products' => $products,
        'product_count' => count($products),
        'platform_counts' => $platformCounts,
        'category_counts' => $categoryCounts,
    ];
}

/**
 * Get product by ID
 */
function getProductById($id)
{
    global $conn;
    $id = (int) $id;
    if ($id < 1) return null;
    $platformTableReady = ensureProductPlatformsTable();
    $sql = $platformTableReady
        ? 'SELECT p.*, pp.platform AS stored_platform FROM products p LEFT JOIN product_platforms pp ON pp.product_id = p.id WHERE p.id = ? LIMIT 1'
        : 'SELECT p.*, NULL AS stored_platform FROM products p WHERE p.id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $storedPlatform = normalizeProductPlatform($row['stored_platform'] ?? '', '');
    $row['platform'] = $storedPlatform !== ''
        ? $storedPlatform
        : inferLegacyProductPlatform((string) ($row['name'] ?? ''));
    unset($row['stored_platform']);
    return $row;
}

/**
 * Get available keys for a product
 */
function getAvailableKeys($productId)
{
    global $conn;
    $productId = (int) $productId;
    if ($productId < 1) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT k.*
         FROM `keys` k
         JOIN products p ON p.id = k.product_id AND p.status = 'active'
         LEFT JOIN product_variants pv ON pv.id = k.variant_id
         WHERE k.product_id = ? AND k.status = 'available'
           AND (k.variant_id IS NULL OR pv.status = 'active')
         ORDER BY k.id ASC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Return compact available-key groups without loading full key codes.
 * Storefront stock counters need only duration, price and quantity; selecting
 * every key row made large catalogues consume unnecessary memory.
 */
function getAvailableKeyGroups($productId): array
{
    global $conn;
    static $requestCache = [];
    $productId = (int) $productId;
    if ($productId < 1) return [];
    if (array_key_exists($productId, $requestCache)) return $requestCache[$productId];

    $stmt = $conn->prepare(
        "SELECT first_key.variant_id,
                COALESCE(NULLIF(TRIM(first_key.duration), ''), 'Standard') AS duration,
                first_key.price_user,
                first_key.price_reseller,
                grouped.available_count
         FROM `keys` first_key
         JOIN (
             SELECT MIN(k.id) AS first_id, COUNT(*) AS available_count
             FROM `keys` k
             JOIN products p ON p.id = k.product_id AND p.status = 'active'
             LEFT JOIN product_variants pv ON pv.id = k.variant_id
             WHERE k.product_id = ? AND k.status = 'available'
               AND (k.variant_id IS NULL OR pv.status = 'active')
             GROUP BY k.variant_id, COALESCE(NULLIF(TRIM(k.duration), ''), 'Standard')
         ) grouped ON grouped.first_id = first_key.id
         ORDER BY grouped.first_id ASC"
    );
    if (!$stmt) return $requestCache[$productId] = [];
    $stmt->bind_param('i', $productId);
    if (!$stmt->execute()) {
        $stmt->close();
        return $requestCache[$productId] = [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $requestCache[$productId] = $rows;
}


/**
 * Bulk version of getAvailableKeyGroups() for storefront pages.
 * One grouped query replaces one query per rendered product.
 */
function getAvailableKeyGroupsForProducts(array $productIds): array
{
    global $conn;
    $ids = [];
    foreach ($productIds as $productId) {
        $productId = (int) $productId;
        if ($productId > 0) $ids[$productId] = $productId;
        if (count($ids) >= 60) break;
    }
    if ($ids === []) return [];
    $list = implode(',', array_values($ids));

    $sql = "SELECT first_key.product_id, first_key.variant_id,
                   COALESCE(NULLIF(TRIM(first_key.duration), ''), 'Standard') AS duration,
                   first_key.price_user, first_key.price_reseller,
                   grouped.available_count
            FROM `keys` first_key
            JOIN (
                SELECT k.product_id, MIN(k.id) AS first_id, COUNT(*) AS available_count
                FROM `keys` k
                JOIN products p ON p.id = k.product_id AND p.status = 'active'
                LEFT JOIN product_variants pv ON pv.id = k.variant_id
                WHERE k.product_id IN ({$list}) AND k.status = 'available'
                  AND (k.variant_id IS NULL OR pv.status = 'active')
                GROUP BY k.product_id, k.variant_id,
                         COALESCE(NULLIF(TRIM(k.duration), ''), 'Standard')
            ) grouped ON grouped.first_id = first_key.id
            ORDER BY first_key.product_id ASC, grouped.first_id ASC";
    $result = $conn->query($sql);
    if (!$result) return [];
    $map = [];
    while ($row = $result->fetch_assoc()) {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId < 1) continue;
        if (!isset($map[$productId])) $map[$productId] = [];
        unset($row['product_id']);
        $map[$productId][] = $row;
    }
    $result->free();
    return $map;
}

/**
 * Get key by ID
 */
function getKeyById($id)
{
    global $conn;
    $id = (int) $id;
    $result = $conn->query("SELECT k.*, p.name as product_name FROM `keys` k JOIN products p ON k.product_id = p.id WHERE k.id = $id");
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Get user's purchased keys
 */
function getUserKeys($userId, ?int $limit = null, int $offset = 0, string $search = '')
{
    global $conn;
    $userId = (int) $userId;
    if ($userId < 1) {
        return [];
    }

    $offset = max(0, $offset);
    $search = substr(trim($search), 0, 180);
    $where = ['k.assigned_to = ?'];
    $types = 'i';
    $params = [$userId];
    if ($search !== '') {
        $where[] = "(LOCATE(?, p.name) > 0 OR LOCATE(?, k.key_code) > 0 OR LOCATE(?, COALESCE(k.duration,'')) > 0)";
        $types .= 'sss';
        array_push($params, $search, $search, $search);
    }

    $sql = "SELECT k.*, p.name AS product_name
            FROM `keys` k
            JOIN products p ON p.id = k.product_id
            WHERE " . implode(' AND ', $where);
    if ($limit !== null) {
        // The unified legacy reader performs a PHP strcmp() on the returned id
        // when sold_at ties. Match that order here so a bounded source read
        // cannot omit a same-timestamp row that the legacy global sort ranks
        // ahead of the page boundary.
        $sql .= " ORDER BY k.sold_at DESC, CONVERT(CAST(k.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_bin DESC";
        $limit = max(1, min(1000, $limit));
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    } else {
        $sql .= ' ORDER BY k.sold_at DESC, k.id DESC';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $i => $_value) $bind[] = &$params[$i];
        if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
            $stmt->close();
            return [];
        }
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $paidByKey = [];
    if ($limit === null) {
        // Preserve the legacy unbounded reader exactly. History pages still use
        // this path today, so Phase A must not change their data semantics while
        // grouped history pagination is still being designed separately.
        $tx = $conn->prepare(
            "SELECT id, reference_id, amount
             FROM transactions
             WHERE user_id = ? AND type = 'purchase' AND status = 'completed'
               AND reference_id IS NOT NULL
             ORDER BY id DESC"
        );
        if ($tx) {
            $tx->bind_param('i', $userId);
            if ($tx->execute()) {
                $txResult = $tx->get_result();
                if ($txResult) {
                    while ($transaction = $txResult->fetch_assoc()) {
                        $keyId = (int) ($transaction['reference_id'] ?? 0);
                        if ($keyId > 0 && !array_key_exists($keyId, $paidByKey)) {
                            $paidByKey[$keyId] = [
                                'amount' => round((float) ($transaction['amount'] ?? 0), 2),
                                'transaction_id' => (int) ($transaction['id'] ?? 0),
                            ];
                        }
                    }
                }
            }
            $tx->close();
        }
    } else {
        // Bounded My Keys pages only need purchase metadata for the returned
        // rows; avoid scanning the user's entire transaction history.
        $keyIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows), static fn(int $id): bool => $id > 0));
        if ($keyIds !== []) {
            $idList = implode(',', array_map('intval', $keyIds));
            $tx = $conn->prepare(
                "SELECT id, reference_id, amount
                 FROM transactions
                 WHERE user_id = ? AND type = 'purchase' AND status = 'completed'
                   AND reference_id IN ({$idList})
                 ORDER BY id DESC"
            );
            if ($tx) {
                $tx->bind_param('i', $userId);
                if ($tx->execute()) {
                    $txResult = $tx->get_result();
                    if ($txResult) {
                        while ($transaction = $txResult->fetch_assoc()) {
                            $keyId = (int) ($transaction['reference_id'] ?? 0);
                            if ($keyId > 0 && !array_key_exists($keyId, $paidByKey)) {
                                $paidByKey[$keyId] = [
                                    'amount' => round((float) ($transaction['amount'] ?? 0), 2),
                                    'transaction_id' => (int) ($transaction['id'] ?? 0),
                                ];
                            }
                        }
                    }
                }
                $tx->close();
            }
        }
    }

    foreach ($rows as &$row) {
        $keyId = (int) ($row['id'] ?? 0);
        $row['purchase_price'] = isset($paidByKey[$keyId]) ? (float) $paidByKey[$keyId]['amount'] : null;
        $row['transaction_id'] = isset($paidByKey[$keyId]) ? (int) $paidByKey[$keyId]['transaction_id'] : 0;
    }
    unset($row);

    return $rows;
}

function countUserKeys(int $userId, string $search = ''): int
{
    global $conn;
    if ($userId < 1) return 0;
    $search = substr(trim($search), 0, 180);
    $sql = "SELECT COUNT(*) AS total FROM `keys` k JOIN products p ON p.id=k.product_id WHERE k.assigned_to=?";
    $types = 'i';
    $params = [$userId];
    if ($search !== '') {
        $sql .= " AND (LOCATE(?, p.name) > 0 OR LOCATE(?, k.key_code) > 0 OR LOCATE(?, COALESCE(k.duration,'')) > 0)";
        $types .= 'sss';
        array_push($params, $search, $search, $search);
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $bind = [$types];
    foreach ($params as $i => $_value) $bind[] = &$params[$i];
    if (!call_user_func_array([$stmt, 'bind_param'], $bind) || !$stmt->execute()) {
        $stmt->close();
        return 0;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return max(0, (int) ($row['total'] ?? 0));
}

/**
 * Get user balance
 */
function getUserBalance($userId)
{
    global $conn;
    $userId = (int) $userId;
    $result = $conn->query("SELECT balance FROM users WHERE id = $userId");
    if ($result && $row = $result->fetch_assoc()) {
        return $row['balance'];
    }
    return 0;
}

/**
 * Update user balance
 */
function updateBalance($userId, $amount)
{
    global $conn;
    $userId = (int) $userId;
    $amount = round((float) $amount, 2);
    if ($userId < 1 || !is_finite($amount) || $amount == 0.0 || abs($amount) > 10000000) {
        return false;
    }

    // Atomic update with prepared values. A negative adjustment may not make
    // the balance negative; administrative deductions use their own locked flow.
    $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND status = 'active' AND balance + ? >= 0");
    if (!$stmt) return false;
    $stmt->bind_param('did', $amount, $userId, $amount);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $ok ? getUserBalance($userId) : false;
}

/**
 * Add balance to user (alias for updateBalance)
 */
function addBalance($userId, $amount)
{
    return updateBalance($userId, $amount);
}

/**
 * Create transaction record
 */
function createTransaction($userId, $type, $amount, $status = 'completed', $description = '', $referenceId = null)
{
    global $conn;
    $userId = (int) $userId;
    $type = trim((string) $type);
    $amount = round((float) $amount, 2);
    $status = trim((string) $status);
    $description = (string) $description;
    $referenceIdValue = $referenceId === null ? null : (int) $referenceId;

    if ($userId < 1 || $type === '' || strlen($type) > 50 || !is_finite($amount) || abs($amount) > 10000000 ||
        !in_array($status, ['pending', 'completed', 'failed', 'cancelled', 'canceled'], true) || strlen($description) > 5000 ||
        ($referenceIdValue !== null && $referenceIdValue < 1)) {
        return false;
    }

    $stmt = $conn->prepare('INSERT INTO transactions (user_id, type, amount, status, description, reference_id) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) return false;
    $stmt->bind_param('isdssi', $userId, $type, $amount, $status, $description, $referenceIdValue);
    $ok = $stmt->execute();
    $insertId = $ok ? (int) $conn->insert_id : 0;
    $stmt->close();
    if ($insertId < 1) return false;

    if (!transactionIntegrityVerifyInsertedRow($insertId, $userId, $type, $amount, $status, $referenceIdValue)) {
        // Remove the malformed row even when the caller was not already inside
        // a transaction. Financial callers will then follow their existing
        // rollback path instead of committing a row whose ENUM silently became ''.
        $cleanup = $conn->prepare('DELETE FROM transactions WHERE id=? AND user_id=? LIMIT 1');
        if ($cleanup) {
            $cleanup->bind_param('ii', $insertId, $userId);
            $cleanup->execute();
            $cleanup->close();
        }
        return false;
    }
    return $insertId;
}

/**
 * Log history
 */
function logHistory($userId, $action, $details)
{
    global $conn;
    $userIdValue = $userId ? (int) $userId : null;
    $action = (string) $action;
    $details = (string) $details;
    $ipAddress = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 1000);

    $stmt = $conn->prepare('INSERT INTO history (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        error_log('History prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('issss', $userIdValue, $action, $details, $ipAddress, $userAgent);
    $ok = $stmt->execute();
    if (!$ok) {
        // Do not log details/user-agent because they may contain customer data.
        // The action name plus MySQL diagnostics is enough to identify schema,
        // constraint, or storage failures without another round of guesswork.
        error_log(
            'History execute failed; action=' . substr(preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $action) ?? 'unknown', 0, 100)
            . '; user_id=' . ($userIdValue === null ? 'null' : (string) $userIdValue)
            . '; errno=' . (int) $stmt->errno
            . '; error=' . substr((string) $stmt->error, 0, 500)
        );
    }
    $stmt->close();
    return $ok;
}

/**
 * Get setting value
 */
function getSetting($key, $default = null)
{
    global $conn;
    $key = trim((string) $key);
    if ($key === '' || strlen($key) > 190) {
        return $default;
    }
    if (!isset($GLOBALS['__settings_request_cache']) || !is_array($GLOBALS['__settings_request_cache'])) {
        $GLOBALS['__settings_request_cache'] = [];
    }
    if (array_key_exists($key, $GLOBALS['__settings_request_cache'])) {
        $cached = $GLOBALS['__settings_request_cache'][$key];
        return !empty($cached['found']) ? $cached['value'] : $default;
    }
    $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        error_log('Setting lookup prepare failed');
        return $default;
    }
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();
        error_log('Setting lookup failed');
        return $default;
    }
    $stmt->bind_result($settingValue);
    $found = $stmt->fetch();
    $GLOBALS['__settings_request_cache'][$key] = [
        'found' => (bool) $found,
        'value' => $found ? $settingValue : null,
    ];
    $value = $found ? $settingValue : $default;
    $stmt->close();
    return $value;
}

/**
 * Update setting
 */
function updateSetting($key, $value)
{
    return upsertSetting($key, $value);
}

/**
 * Get user transactions
 */
function getUserTransactions($userId, $limit = 50)
{
    global $conn;
    $userId = (int) $userId;
    $limit = max(1, min(500, (int) $limit));
    if ($userId < 1) return [];
    $stmt = $conn->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
    if (!$stmt) return [];
    $stmt->bind_param('ii', $userId, $limit);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Find a transaction by reference id
 */
function findTransactionByReference($referenceId, $userId = null)
{
    global $conn;
    $referenceId = (int) $referenceId;
    $sql = "SELECT * FROM transactions WHERE reference_id = $referenceId";
    if ($userId) {
        $sql .= " AND user_id = " . (int) $userId;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $res = $conn->query($sql);
    return $res ? $res->fetch_assoc() : null;
}

/**
 * Update transaction status
 */
function updateTransactionStatus($transactionId, $status)
{
    global $conn;
    $transactionId = (int) $transactionId;
    $status = strtolower(trim((string) $status));
    if ($transactionId < 1 || !in_array($status, ['pending', 'completed', 'failed', 'cancelled', 'canceled'], true)) {
        return false;
    }
    $stmt = $conn->prepare('UPDATE transactions SET status = ? WHERE id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('si', $status, $transactionId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Get all users
 */
function getAllUsers($role = null)
{
    global $conn;
    if ($role === null || $role === '') {
        $result = $conn->query('SELECT * FROM users ORDER BY created_at DESC');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    $role = strtolower(trim((string) $role));
    if (!in_array($role, ['admin', 'reseller', 'user'], true)) return [];
    $stmt = $conn->prepare('SELECT * FROM users WHERE role = ? ORDER BY created_at DESC');
    if (!$stmt) return [];
    $stmt->bind_param('s', $role);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Search managed accounts by exact ID, username, or email.
 * The query runs only after the administrator submits the search form.
 */
function searchManagedUsers(string $role, string $query, int $limit = 500): array
{
    global $conn;
    $role = strtolower(trim($role));
    $query = trim($query);
    $limit = max(1, min(500, $limit));
    if (!in_array($role, ['admin', 'reseller', 'user'], true) || $query === '') return [];

    $escaped = strtr($query, ['=' => '==', '%' => '=%', '_' => '=_']);
    $like = '%' . $escaped . '%';
    $exactId = ctype_digit($query) ? ltrim($query, '0') : '';
    if ($exactId === '') $exactId = $query === '0' ? '0' : '-1';

    $stmt = $conn->prepare(
        "SELECT *
         FROM users
         WHERE role = ?
           AND (
               username LIKE ? ESCAPE '='
               OR email LIKE ? ESCAPE '='
               OR CAST(id AS CHAR) = ?
           )
         ORDER BY created_at DESC
         LIMIT ?"
    );
    if (!$stmt) return [];
    $stmt->bind_param('ssssi', $role, $like, $like, $exactId, $limit);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}


/**
 * Return one server-side paginated page of managed accounts.
 * Search supports partial username/email and exact numeric ID, matching
 * searchManagedUsers() while avoiding loading every account into memory.
 */
function getManagedAccountsPage(string $role, string $query = '', int $page = 1, int $perPage = 50): array
{
    global $conn;
    $role = strtolower(trim($role));
    $query = trim($query);
    $page = max(1, $page);
    $perPage = max(10, min(100, $perPage));
    if (!in_array($role, ['admin', 'reseller', 'user'], true)) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage];
    }

    $total = 0;
    if ($query === '') {
        $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM users WHERE role = ?');
        if ($countStmt) {
            $countStmt->bind_param('s', $role);
            if ($countStmt->execute()) {
                $result = $countStmt->get_result();
                $total = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0);
            }
            $countStmt->close();
        }
    } else {
        $escaped = strtr($query, ['=' => '==', '%' => '=%', '_' => '=_']);
        $like = '%' . $escaped . '%';
        $exactId = ctype_digit($query) ? ltrim($query, '0') : '';
        if ($exactId === '') $exactId = $query === '0' ? '0' : '-1';
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM users
             WHERE role = ?
               AND (username LIKE ? ESCAPE '=' OR email LIKE ? ESCAPE '=' OR CAST(id AS CHAR) = ?)"
        );
        if ($countStmt) {
            $countStmt->bind_param('ssss', $role, $like, $like, $exactId);
            if ($countStmt->execute()) {
                $result = $countStmt->get_result();
                $total = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0);
            }
            $countStmt->close();
        }
    }

    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;
    $rows = [];

    if ($query === '') {
        $stmt = $conn->prepare('SELECT * FROM users WHERE role = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        if ($stmt) {
            $stmt->bind_param('sii', $role, $perPage, $offset);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            $stmt->close();
        }
    } else {
        $escaped = strtr($query, ['=' => '==', '%' => '=%', '_' => '=_']);
        $like = '%' . $escaped . '%';
        $exactId = ctype_digit($query) ? ltrim($query, '0') : '';
        if ($exactId === '') $exactId = $query === '0' ? '0' : '-1';
        $stmt = $conn->prepare(
            "SELECT *
             FROM users
             WHERE role = ?
               AND (username LIKE ? ESCAPE '=' OR email LIKE ? ESCAPE '=' OR CAST(id AS CHAR) = ?)
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        if ($stmt) {
            $stmt->bind_param('ssssii', $role, $like, $like, $exactId, $perPage, $offset);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            $stmt->close();
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
    ];
}

/**
 * Get user by ID
 */
function getUserById($id)
{
    global $conn;
    $id = (int) $id;
    $result = $conn->query("SELECT * FROM users WHERE id = $id");
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Get statistics for dashboard
 */
function getDashboardStats($role = null)
{
    global $conn;
    $stats = [];

    // Total users
    $sql = "SELECT COUNT(*) as total FROM users WHERE role = 'user'";
    $result = $conn->query($sql);
    $stats['total_users'] = $result ? $result->fetch_assoc()['total'] : 0;

    // Total resellers
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'reseller'");
    $stats['total_resellers'] = $result ? $result->fetch_assoc()['total'] : 0;

    // Keys sold. Old local-key rows are removed after one year, so preserve
    // their count through the compact daily archive. API orders were never
    // included in this dashboard card and remain excluded here.
    $result = $conn->query("SELECT COUNT(*) as total FROM `keys` WHERE status = 'sold'");
    $liveKeysSold = (int) ($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);
    $archivedKeysSold = 0;
    try {
        $archiveCount = $conn->query("SELECT SUM(transaction_count) AS total FROM key_sales_archive_daily WHERE sale_type = 'purchase'");
        $archiveCountRow = $archiveCount ? $archiveCount->fetch_assoc() : null;
        $archivedKeysSold = (int) ($archiveCountRow['total'] ?? 0);
    } catch (Throwable $ignored) {}
    $stats['keys_sold'] = $liveKeysSold + $archivedKeysSold;

    // Total income (sum of all purchases)
    $result = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE type IN ('purchase','cgo_purchase','supplier_purchase') AND status = 'completed'");
    $row = $result ? $result->fetch_assoc() : null;
    $liveIncome = (float) ($row['total'] ?? 0);
    $archivedIncome = 0.0;
    try {
        $archiveResult = $conn->query('SELECT SUM(total_amount) AS total FROM key_sales_archive_daily');
        $archiveRow = $archiveResult ? $archiveResult->fetch_assoc() : null;
        $archivedIncome = (float) ($archiveRow['total'] ?? 0);
    } catch (Throwable $ignored) {}
    $stats['total_income'] = $liveIncome + $archivedIncome;

    // Add announcement count. Older installations may not have this optional
    // table until the announcement setting is first opened.
    $result = $conn->query("SELECT COUNT(*) as total FROM announcement_texts");
    if (!$result && ensureAnnouncementTable()) {
        $result = $conn->query("SELECT COUNT(*) as total FROM announcement_texts");
    }
    $stats['total_announcements'] = $result ? $result->fetch_assoc()['total'] : 0;

    return $stats;
}

/**
 * Mask an account name before it is shown in public purchase activity.
 */
function maskPublicAccountName(string $username): string
{
    $username = trim($username);
    if ($username === '') return '***';

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $length = mb_strlen($username, 'UTF-8');
        $visible = mb_substr($username, 0, $length > 2 ? 2 : 1, 'UTF-8');
    } elseif (preg_match_all('/./us', $username, $characters) !== false && $characters[0] !== []) {
        $length = count($characters[0]);
        $visible = implode('', array_slice($characters[0], 0, $length > 2 ? 2 : 1));
    } else {
        $length = strlen($username);
        $visible = substr($username, 0, $length > 2 ? 2 : 1);
    }
    return $visible . '***';
}

/**
 * Check whether an optional purchase source table is available.
 *
 * The public activity feed must keep working even when one supplier module is
 * not installed or its schema is temporarily unavailable. A failure here only
 * disables that source; it never hides local purchases.
 */
function publicPurchaseActivityTableExists(string $table): bool
{
    static $cache = [];
    if (!preg_match('/\A[a-z0-9_]+\z/iD', $table)) return false;
    if (array_key_exists($table, $cache)) return $cache[$table];

    // Runtime feed requests must not scan INFORMATION_SCHEMA. A zero-row probe
    // is enough to establish whether an optional legacy/integration table is
    // available and is cheap on restricted shared-hosting accounts.
    return $cache[$table] = sakazukiTableReady($table);
}

/**
 * Return all columns for one optional table using a single SHOW COLUMNS query.
 * Previous code issued one INFORMATION_SCHEMA request per column, so a single
 * 30-second activity poll could spend dozens of database round-trips merely
 * discovering schema capabilities.
 */
function publicPurchaseActivityTableColumns(string $table): array
{
    global $conn;
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!preg_match('/\A[a-z0-9_]+\z/iD', $table) || !publicPurchaseActivityTableExists($table)) {
        return $cache[$key] = [];
    }

    $columns = [];
    try {
        $result = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $name = strtolower(trim((string) ($row['Field'] ?? '')));
                if ($name !== '') $columns[$name] = true;
            }
            if (method_exists($result, 'free')) $result->free();
        }
    } catch (Throwable $ignored) {}
    return $cache[$key] = $columns;
}

/** Check whether an optional integration table exposes a specific column. */
function publicPurchaseActivityColumnExists(string $table, string $column): bool
{
    if (!preg_match('/\A[a-z0-9_]+\z/iD', $table) || !preg_match('/\A[a-z0-9_]+\z/iD', $column)) {
        return false;
    }
    $columns = publicPurchaseActivityTableColumns($table);
    return isset($columns[strtolower($column)]);
}

/**
 * Create the optional activity-event table used to preserve one row per real
 * local checkout. This is called before the purchase transaction begins, since
 * MySQL DDL can implicitly commit an active transaction.
 *
 * Failure is deliberately non-fatal: selling keys must never depend on a
 * cosmetic public feed. The reader falls back to transaction reconstruction.
 */

function purchaseActivityEnsureEventTable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        $GLOBALS['purchase_activity_event_table_ready'] = $ready;
        return $ready;
    }

    global $conn;
    if (publicPurchaseActivityTableExists('purchase_activity_events')) {
        $ready = true;
        $GLOBALS['purchase_activity_event_table_ready'] = true;
        return true;
    }
    if (function_exists('sakazukiSchemaMigrationsAllowed') && !sakazukiSchemaMigrationsAllowed()) {
        $ready = false;
        $GLOBALS['purchase_activity_event_table_ready'] = false;
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS purchase_activity_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        source VARCHAR(24) NOT NULL DEFAULT 'local',
        source_reference BIGINT UNSIGNED NOT NULL,
        first_transaction_id BIGINT UNSIGNED NOT NULL,
        last_transaction_id BIGINT UNSIGNED NOT NULL,
        product_id INT NULL,
        product_name VARCHAR(255) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_purchase_activity_source_ref (source, source_reference),
        KEY idx_purchase_activity_created (created_at, id),
        KEY idx_purchase_activity_user (user_id, created_at),
        KEY idx_purchase_activity_tx_range (source, first_transaction_id, last_transaction_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        if (!$conn->query($sql)) {
            error_log('Purchase activity event table create failed: ' . $conn->error);
            $ready = false;
            $GLOBALS['purchase_activity_event_table_ready'] = false;
            return false;
        }
        $ready = true;
        $GLOBALS['purchase_activity_event_table_ready'] = true;
        return true;
    } catch (Throwable $e) {
        error_log('Purchase activity event table create failed: ' . $e->getMessage());
        $ready = false;
        $GLOBALS['purchase_activity_event_table_ready'] = false;
        return false;
    }
}

/** Save one privacy-safe event for one completed local checkout. */
function purchaseActivityRecordLocalOrder(
    int $userId,
    int $firstTransactionId,
    int $lastTransactionId,
    int $productId,
    string $productName,
    int $quantity,
    float $amount
): bool {
    global $conn;
    if ($userId < 1 || $firstTransactionId < 1 || $lastTransactionId < $firstTransactionId ||
        $productId < 1 || $quantity < 1 || !is_finite($amount)) {
        return false;
    }
    if (!purchaseActivityEnsureEventTable()) return false;

    $productName = trim($productName);
    if ($productName === '') $productName = getAppLang() === 'en' ? 'Product' : 'สินค้า';
    if (function_exists('mb_substr')) {
        $productName = mb_substr($productName, 0, 255, 'UTF-8');
    } else {
        $productName = substr($productName, 0, 255);
    }
    $quantity = max(1, min(10000, $quantity));
    $amount = round(max(0.0, $amount), 2);
    $sourceReference = $firstTransactionId;

    try {
        $stmt = $conn->prepare(
            "INSERT INTO purchase_activity_events
                (user_id, source, source_reference, first_transaction_id, last_transaction_id,
                 product_id, product_name, quantity, amount, created_at)
             VALUES (?, 'local', ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                last_transaction_id = VALUES(last_transaction_id),
                product_id = VALUES(product_id),
                product_name = VALUES(product_name),
                quantity = VALUES(quantity),
                amount = VALUES(amount)"
        );
        if (!$stmt) return false;
        $stmt->bind_param(
            'iiiiisid',
            $userId,
            $sourceReference,
            $firstTransactionId,
            $lastTransactionId,
            $productId,
            $productName,
            $quantity,
            $amount
        );
        $ok = $stmt->execute();
        if (!$ok) error_log('Purchase activity event insert failed: ' . $stmt->error);
        $stmt->close();
        return $ok;
    } catch (Throwable $e) {
        error_log('Purchase activity event insert failed: ' . $e->getMessage());
        return false;
    }
}

/** Execute one bounded source query without allowing one broken source to fail the feed. */
function publicPurchaseActivityFetch(string $sql, string $from, string $until, string $source): array
{
    global $conn;

    if (!isset($GLOBALS['purchase_activity_diagnostics'])
        || !is_array($GLOBALS['purchase_activity_diagnostics'])) {
        $GLOBALS['purchase_activity_diagnostics'] = ['sources' => []];
    }
    if (!isset($GLOBALS['purchase_activity_diagnostics']['sources'])
        || !is_array($GLOBALS['purchase_activity_diagnostics']['sources'])) {
        $GLOBALS['purchase_activity_diagnostics']['sources'] = [];
    }

    $setDiagnostic = static function (bool $ok, int $count, string $errorCode = '') use ($source): void {
        $GLOBALS['purchase_activity_diagnostics']['sources'][$source] = [
            'ok' => $ok,
            'count' => max(0, $count),
            'error' => $errorCode,
        ];
    };

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Purchase activity prepare failed for source ' . $source . ': ' . $conn->error);
            $setDiagnostic(false, 0, 'prepare_failed');
            return [];
        }
        $stmt->bind_param('ss', $from, $until);
        if (!$stmt->execute()) {
            error_log('Purchase activity execute failed for source ' . $source . ': ' . $stmt->error);
            $setDiagnostic(false, 0, 'execute_failed');
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) $result->free();
        $stmt->close();
        $rows = is_array($rows) ? $rows : [];
        $setDiagnostic(true, count($rows));
        return $rows;
    } catch (Throwable $e) {
        error_log('Purchase activity query failed for source ' . $source . ': ' . $e->getMessage());
        $setDiagnostic(false, 0, 'exception');
        return [];
    }
}

/** Purchase activity schema helpers for mixed/legacy installations. */
function publicPurchaseActivityHasColumns(string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!is_string($column) || $column === '' || !publicPurchaseActivityColumnExists($table, $column)) {
            return false;
        }
    }
    return true;
}

/** Extract only the product title from the transaction description fallback. */
function publicPurchaseActivityProductFromDescription(string $description): string
{
    $description = trim($description);
    if ($description === '') return '';

    if (preg_match('/:\s*(.+?)(?:\s+x[0-9]+)?\s*$/u', $description, $matches) !== 1) {
        return '';
    }

    $product = trim((string) ($matches[1] ?? ''));
    if ($product === '') return '';
    if (function_exists('mb_substr')) return mb_substr($product, 0, 255, 'UTF-8');
    return substr($product, 0, 255);
}


/**
 * Convert a stored product image value into a browser-safe URL for pages one
 * directory below the public web root (user/, reseller/, and admin/).
 *
 * Only HTTPS remote images and the site's generated product-upload paths are
 * accepted. Invalid, legacy, or ambiguous values return an empty string so the
 * activity card can show its built-in placeholder without breaking the feed.
 */
function publicPurchaseActivityImageUrl(string $image): string
{
    $image = trim($image);
    if ($image === '' || preg_match('/[\x00-\x1F\x7F]/', $image)) return '';

    if (substr($image, 0, 2) === '//') $image = 'https:' . $image;
    if (filter_var($image, FILTER_VALIDATE_URL) !== false) {
        return strtolower((string) parse_url($image, PHP_URL_SCHEME)) === 'https' ? $image : '';
    }

    $path = ltrim(str_replace('\\', '/', $image), '/');
    if ($path === '' || strpos($path, '../') !== false || strpos($path, './') === 0) return '';
    if (preg_match('#\Aassets/uploads/products/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp|gif|avif)\z#iD', $path) !== 1) {
        return '';
    }

    $segments = explode('/', $path);
    $encoded = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return '';
        $encoded[] = rawurlencode($segment);
    }
    return '../' . implode('/', $encoded);
}

/**
 * Load the latest privacy-safe purchases made during the current Bangkok day.
 *
 * Source strategy:
 * - Local stock sales come from the checkout event table, with a transaction
 *   fallback for purchases created before the event table existed.
 * - CGO and Store Bridge sales are read from their order tables directly.
 *   Their order rows are authoritative; transaction type/reference fields are
 *   used only as metadata/fallbacks. This matches the admin transaction page
 *   and prevents valid API orders from disappearing when an installation has
 *   legacy transaction types or partially migrated optional columns.
 */
function getPublicRecentPurchaseActivity(int $limit = 10, bool $bypassCache = false): array
{
    $limit = max(1, min(10, $limit));
    $timezone = new DateTimeZone('Asia/Bangkok');
    $dayStart = new DateTimeImmutable('today', $timezone);
    $from = $dayStart->format('Y-m-d H:i:s');
    $until = $dayStart->modify('+1 day')->format('Y-m-d H:i:s');

    // All logged-in users see the same privacy-safe feed. Reuse it briefly when
    // APCu is available instead of running four bounded source queries for every
    // browser poll. Diagnostics always bypass this cache.
    $cacheKey = '';
    if (!$bypassCache && function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $databaseName = defined('DB_NAME') ? (string) DB_NAME : '';
        $cacheKey = 'sakazuki:purchase_activity:v3:' . hash('sha256', $databaseName . '|' . $from . '|' . $limit);
        $cacheHit = false;
        $cached = apcu_fetch($cacheKey, $cacheHit);
        if ($cacheHit && is_array($cached)) {
            $GLOBALS['purchase_activity_diagnostics'] = [
                'day_from' => $from,
                'day_until' => $until,
                'sources' => ['cache' => ['ok' => true, 'count' => count($cached), 'error' => '']],
                'returned' => count($cached),
                'cache' => 'apcu_hit',
            ];
            return array_slice($cached, 0, $limit);
        }
    }

    // APCu is often disabled on rented/shared hosting. Use the private
    // application directory as a tiny cross-process cache so hundreds of tabs
    // do not repeat the same activity queries every 30 seconds. A failed or
    // unwritable file cache is harmless; the feed simply falls back to MySQL.
    $fileCache = dirname(dirname(__DIR__)) . '/private/purchase_activity_cache_v4.json';
    if (!$bypassCache && is_file($fileCache)) {
        $age = time() - (int) @filemtime($fileCache);
        if ($age >= 0 && $age < 12) {
            $cachedRaw = @file_get_contents($fileCache);
            $cachedPayload = is_string($cachedRaw) ? json_decode($cachedRaw, true) : null;
            if (is_array($cachedPayload)
                && hash_equals((string) ($cachedPayload['day_from'] ?? ''), $from)
                && isset($cachedPayload['items']) && is_array($cachedPayload['items'])) {
                $cachedItems = array_slice($cachedPayload['items'], 0, $limit);
                $GLOBALS['purchase_activity_diagnostics'] = [
                    'day_from' => $from,
                    'day_until' => $until,
                    'sources' => ['file_cache' => ['ok' => true, 'count' => count($cachedItems), 'error' => '']],
                    'returned' => count($cachedItems),
                    'cache' => 'file_hit',
                ];
                return $cachedItems;
            }
        }
    }

    $candidateLimit = max(40, $limit * 4);
    $rows = [];

    $GLOBALS['purchase_activity_diagnostics'] = [
        'day_from' => $from,
        'day_until' => $until,
        'sources' => [],
        'returned' => 0,
    ];

    $markUnavailable = static function (string $source, string $reason): void {
        $GLOBALS['purchase_activity_diagnostics']['sources'][$source] = [
            'ok' => true,
            'count' => 0,
            'error' => $reason,
        ];
    };

    $productsHaveImage = publicPurchaseActivityColumnExists('products', 'image');
    $localProductImageExpression = $productsHaveImage
        ? "COALESCE(NULLIF(p.image, ''), '')"
        : "''";

    $eventColumns = [
        'id', 'user_id', 'source', 'source_reference',
        'first_transaction_id', 'last_transaction_id',
        'product_id', 'product_name', 'quantity', 'created_at',
    ];
    $hasEventTable = publicPurchaseActivityTableExists('purchase_activity_events')
        && publicPurchaseActivityHasColumns('purchase_activity_events', $eventColumns);

    if ($hasEventTable) {
        $rows = publicPurchaseActivityFetch(
            "SELECT
                GREATEST(pae.last_transaction_id, pae.source_reference) AS transaction_id,
                pae.user_id,
                pae.created_at,
                u.username,
                COALESCE(NULLIF(pae.product_name, ''), NULLIF(p.name, ''), '') AS product_name,
                GREATEST(COALESCE(pae.quantity, 1), 1) AS quantity,
                " . $localProductImageExpression . " AS image_path,
                COALESCE(NULLIF(pepv.duration, ''), NULLIF(pek.duration, ''), '') AS duration,
                COALESCE(pet.description, '') AS source_description,
                CONCAT('local-event:', pae.id) AS event_key
             FROM purchase_activity_events pae
             INNER JOIN users u
                ON u.id = pae.user_id
               AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('user', 'reseller')
             LEFT JOIN products p ON p.id = pae.product_id
             LEFT JOIN transactions pet ON pet.id = pae.first_transaction_id
             LEFT JOIN `keys` pek ON pek.id = pet.reference_id
             LEFT JOIN product_variants pepv ON pepv.id = pek.variant_id
             WHERE pae.source = 'local'
               AND pae.created_at >= ?
               AND pae.created_at < ?
             ORDER BY pae.created_at DESC, pae.id DESC
             LIMIT " . $candidateLimit,
            $from,
            $until,
            'local_event'
        );
    } else {
        $markUnavailable('local_event', 'table_or_columns_unavailable');
    }

    $eventJoin = $hasEventTable
        ? "LEFT JOIN purchase_activity_events pae
               ON pae.source = 'local'
              AND pae.user_id = t.user_id
              AND t.id BETWEEN pae.first_transaction_id AND pae.last_transaction_id"
        : '';
    $eventFilter = $hasEventTable ? 'AND pae.id IS NULL' : '';

    $rows = array_merge($rows, publicPurchaseActivityFetch(
        "SELECT
            t.id AS transaction_id,
            t.user_id,
            t.created_at,
            u.username,
            COALESCE(NULLIF(p.name, ''), '') AS product_name,
            1 AS quantity,
            " . $localProductImageExpression . " AS image_path,
            COALESCE(NULLIF(k.duration, ''), '') AS duration,
            '' AS source_description,
            CONCAT_WS(
                CHAR(31),
                'local-legacy',
                t.user_id,
                COALESCE(k.product_id, 0),
                COALESCE(k.variant_id, 0),
                COALESCE(k.duration, ''),
                DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i:%s')
            ) AS event_key
         FROM transactions t
         INNER JOIN users u
            ON u.id = t.user_id
           AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('user', 'reseller')
         LEFT JOIN `keys` k ON k.id = t.reference_id
         LEFT JOIN products p ON p.id = k.product_id
         " . $eventJoin . "
         WHERE LOWER(TRIM(COALESCE(t.status, ''))) IN ('completed', 'success')
           AND LOWER(TRIM(COALESCE(t.type, ''))) = 'purchase'
           AND LOWER(COALESCE(t.description, '')) NOT LIKE '%cheatgame order %:%'
           AND LOWER(COALESCE(t.description, '')) NOT LIKE '%store bridge order %:%'
           AND t.created_at >= ?
           AND t.created_at < ?
           " . $eventFilter . "
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT " . $candidateLimit,
        $from,
        $until,
        'local_legacy'
    ));

    /*
     * CGO: read successful orders from cgo_orders first. The prior code started
     * from transactions and required t.type='cgo_purchase'. That is stricter
     * than the admin history and can return zero API rows on legacy/migrated
     * installations even though the orders and transactions are visible there.
     */
    $cgoRequired = ['id', 'user_id'];
    if (publicPurchaseActivityTableExists('cgo_orders')
        && publicPurchaseActivityHasColumns('cgo_orders', $cgoRequired)) {
        $cgoHasTransactionId = publicPurchaseActivityColumnExists('cgo_orders', 'transaction_id');
        $cgoHasStatus = publicPurchaseActivityColumnExists('cgo_orders', 'status');
        $cgoHasCreatedAt = publicPurchaseActivityColumnExists('cgo_orders', 'created_at');
        $cgoHasCompletedAt = publicPurchaseActivityColumnExists('cgo_orders', 'completed_at');
        $cgoHasQuantity = publicPurchaseActivityColumnExists('cgo_orders', 'quantity');

        $cgoTransactionJoin = $cgoHasTransactionId
            ? "LEFT JOIN transactions t ON (
                    t.id = o.transaction_id
                    OR (
                        (o.transaction_id IS NULL OR o.transaction_id = 0)
                        AND t.reference_id = o.id
                        AND (
                            LOWER(TRIM(COALESCE(t.type, ''))) = 'cgo_purchase'
                            OR LOWER(COALESCE(t.description, '')) LIKE '%cheatgame order %:%'
                        )
                    )
               )"
            : "LEFT JOIN transactions t
                 ON t.reference_id = o.id
                AND (
                    LOWER(TRIM(COALESCE(t.type, ''))) = 'cgo_purchase'
                    OR LOWER(COALESCE(t.description, '')) LIKE '%cheatgame order %:%'
                )";

        $cgoTimeParts = [];
        if ($cgoHasCompletedAt) $cgoTimeParts[] = 'o.completed_at';
        $cgoTimeParts[] = 't.created_at';
        if ($cgoHasCreatedAt) $cgoTimeParts[] = 'o.created_at';
        $cgoTimeExpression = 'COALESCE(' . implode(', ', $cgoTimeParts) . ')';

        $cgoTransactionIdExpression = $cgoHasTransactionId
            ? 'COALESCE(NULLIF(t.id, 0), NULLIF(o.transaction_id, 0), o.id)'
            : 'COALESCE(NULLIF(t.id, 0), o.id)';

        $cgoStatusCondition = $cgoHasStatus
            ? "(
                    LOWER(TRIM(COALESCE(o.status, ''))) IN ('success', 'completed')
                    OR (
                        LOWER(TRIM(COALESCE(t.status, ''))) IN ('completed', 'success')
                        AND LOWER(TRIM(COALESCE(o.status, ''))) NOT IN (
                            'failed', 'refunded', 'refunded_conflict', 'cancelled', 'canceled'
                        )
                    )
               )"
            : "LOWER(TRIM(COALESCE(t.status, ''))) IN ('completed', 'success')";

        $cgoJoins = [];
        $cgoNameCandidates = [];
        $cgoDurationCandidates = [];
        $cgoImageCandidates = [];

        if (publicPurchaseActivityColumnExists('cgo_orders', 'local_product_id')
            && publicPurchaseActivityHasColumns('products', ['id', 'name'])) {
            $cgoJoins[] = 'LEFT JOIN products lp ON lp.id = o.local_product_id';
            $cgoNameCandidates[] = "NULLIF(lp.name, '')";
            if ($productsHaveImage) $cgoImageCandidates[] = "NULLIF(lp.image, '')";
        }

        if (publicPurchaseActivityColumnExists('cgo_orders', 'cgo_product_id')
            && publicPurchaseActivityHasColumns('cgo_products', ['id', 'name'])) {
            $cgoJoins[] = 'LEFT JOIN cgo_products cp ON cp.id = o.cgo_product_id';
            if (publicPurchaseActivityColumnExists('cgo_products', 'brand')) {
                $cgoNameCandidates[] = "NULLIF(
                    CASE
                        WHEN COALESCE(NULLIF(cp.brand, ''), '') <> ''
                            THEN CONCAT(cp.brand, ' - ', cp.name)
                        ELSE cp.name
                    END,
                    ''
                )";
            } else {
                $cgoNameCandidates[] = "NULLIF(cp.name, '')";
            }
            if (publicPurchaseActivityColumnExists('cgo_products', 'duration')) {
                $cgoDurationCandidates[] = "NULLIF(cp.duration, '')";
            }
            if (publicPurchaseActivityColumnExists('cgo_products', 'cached_image_path')) {
                $cgoImageCandidates[] = "NULLIF(cp.cached_image_path, '')";
            }
            if (publicPurchaseActivityColumnExists('cgo_products', 'image_path')) {
                $cgoImageCandidates[] = "NULLIF(cp.image_path, '')";
            }
        }

        if (publicPurchaseActivityColumnExists('cgo_orders', 'local_variant_id')
            && publicPurchaseActivityHasColumns('product_variants', ['id', 'duration'])) {
            $cgoJoins[] = 'LEFT JOIN product_variants pv ON pv.id = o.local_variant_id';
            array_unshift($cgoDurationCandidates, "NULLIF(pv.duration, '')");
        }

        $cgoProductExpression = $cgoNameCandidates
            ? 'COALESCE(' . implode(', ', $cgoNameCandidates) . ", '')"
            : "''";
        $cgoDurationExpression = $cgoDurationCandidates
            ? 'COALESCE(' . implode(', ', $cgoDurationCandidates) . ", '')"
            : "''";
        $cgoImageExpression = $cgoImageCandidates
            ? 'COALESCE(' . implode(', ', $cgoImageCandidates) . ", '')"
            : "''";
        $cgoQuantityExpression = $cgoHasQuantity
            ? 'GREATEST(COALESCE(o.quantity, 1), 1)'
            : '1';

        $rows = array_merge($rows, publicPurchaseActivityFetch(
            "SELECT
                " . $cgoTransactionIdExpression . " AS transaction_id,
                o.user_id,
                " . $cgoTimeExpression . " AS created_at,
                u.username,
                " . $cgoProductExpression . " AS product_name,
                " . $cgoQuantityExpression . " AS quantity,
                " . $cgoImageExpression . " AS image_path,
                " . $cgoDurationExpression . " AS duration,
                COALESCE(t.description, '') AS source_description,
                CONCAT('cgo-order:', o.id) AS event_key
             FROM cgo_orders o
             INNER JOIN users u
                ON u.id = o.user_id
               AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('user', 'reseller')
             " . $cgoTransactionJoin . "
             " . implode("\n             ", $cgoJoins) . "
             WHERE " . $cgoStatusCondition . "
               AND " . $cgoTimeExpression . " >= ?
               AND " . $cgoTimeExpression . " < ?
             ORDER BY " . $cgoTimeExpression . " DESC, o.id DESC
             LIMIT " . $candidateLimit,
            $from,
            $until,
            'cgo_orders'
        ));
    } else {
        $markUnavailable('cgo_orders', 'table_or_required_columns_unavailable');
    }

    /*
     * Store Bridge follows the same order-first model. This also prevents a
     * supplier order from vanishing merely because its transaction type was
     * written by an older integration build.
     */
    $supplierRequired = ['id', 'user_id'];
    if (publicPurchaseActivityTableExists('supplier_orders')
        && publicPurchaseActivityHasColumns('supplier_orders', $supplierRequired)) {
        $supplierHasTransactionId = publicPurchaseActivityColumnExists('supplier_orders', 'transaction_id');
        $supplierHasStatus = publicPurchaseActivityColumnExists('supplier_orders', 'status');
        $supplierHasCreatedAt = publicPurchaseActivityColumnExists('supplier_orders', 'created_at');
        $supplierHasCompletedAt = publicPurchaseActivityColumnExists('supplier_orders', 'completed_at');
        $supplierHasQuantity = publicPurchaseActivityColumnExists('supplier_orders', 'quantity');

        $supplierTransactionJoin = $supplierHasTransactionId
            ? "LEFT JOIN transactions t ON (
                    t.id = o.transaction_id
                    OR (
                        (o.transaction_id IS NULL OR o.transaction_id = 0)
                        AND t.reference_id = o.id
                        AND (
                            LOWER(TRIM(COALESCE(t.type, ''))) = 'supplier_purchase'
                            OR LOWER(COALESCE(t.description, '')) LIKE '%store bridge order %:%'
                        )
                    )
               )"
            : "LEFT JOIN transactions t
                 ON t.reference_id = o.id
                AND (
                    LOWER(TRIM(COALESCE(t.type, ''))) = 'supplier_purchase'
                    OR LOWER(COALESCE(t.description, '')) LIKE '%store bridge order %:%'
                )";

        $supplierTimeParts = [];
        if ($supplierHasCompletedAt) $supplierTimeParts[] = 'o.completed_at';
        $supplierTimeParts[] = 't.created_at';
        if ($supplierHasCreatedAt) $supplierTimeParts[] = 'o.created_at';
        $supplierTimeExpression = 'COALESCE(' . implode(', ', $supplierTimeParts) . ')';

        $supplierTransactionIdExpression = $supplierHasTransactionId
            ? 'COALESCE(NULLIF(t.id, 0), NULLIF(o.transaction_id, 0), o.id)'
            : 'COALESCE(NULLIF(t.id, 0), o.id)';

        $supplierStatusCondition = $supplierHasStatus
            ? "(
                    LOWER(TRIM(COALESCE(o.status, ''))) IN ('success', 'completed')
                    OR (
                        LOWER(TRIM(COALESCE(t.status, ''))) IN ('completed', 'success')
                        AND LOWER(TRIM(COALESCE(o.status, ''))) NOT IN (
                            'failed', 'refunded', 'refunded_conflict', 'cancelled', 'canceled'
                        )
                    )
               )"
            : "LOWER(TRIM(COALESCE(t.status, ''))) IN ('completed', 'success')";

        $supplierJoins = [];
        $supplierNameCandidates = [];
        $supplierDurationCandidates = [];
        $supplierImageCandidates = [];

        if (publicPurchaseActivityColumnExists('supplier_orders', 'local_product_id')
            && publicPurchaseActivityHasColumns('products', ['id', 'name'])) {
            $supplierJoins[] = 'LEFT JOIN products slp ON slp.id = o.local_product_id';
            $supplierNameCandidates[] = "NULLIF(slp.name, '')";
            if ($productsHaveImage) $supplierImageCandidates[] = "NULLIF(slp.image, '')";
        }

        if (publicPurchaseActivityColumnExists('supplier_orders', 'supplier_product_id')
            && publicPurchaseActivityHasColumns('supplier_products', ['id', 'name'])) {
            $supplierJoins[] = 'LEFT JOIN supplier_products sp ON sp.id = o.supplier_product_id';
            $supplierNameCandidates[] = "NULLIF(sp.name, '')";
            if (publicPurchaseActivityColumnExists('supplier_products', 'duration')) {
                $supplierDurationCandidates[] = "NULLIF(sp.duration, '')";
            }
            if (publicPurchaseActivityColumnExists('supplier_products', 'image_url')) {
                $supplierImageCandidates[] = "NULLIF(sp.image_url, '')";
            }
        }

        if (publicPurchaseActivityColumnExists('supplier_orders', 'local_variant_id')
            && publicPurchaseActivityHasColumns('product_variants', ['id', 'duration'])) {
            $supplierJoins[] = 'LEFT JOIN product_variants spv ON spv.id = o.local_variant_id';
            array_unshift($supplierDurationCandidates, "NULLIF(spv.duration, '')");
        }

        $supplierProductExpression = $supplierNameCandidates
            ? 'COALESCE(' . implode(', ', $supplierNameCandidates) . ", '')"
            : "''";
        $supplierDurationExpression = $supplierDurationCandidates
            ? 'COALESCE(' . implode(', ', $supplierDurationCandidates) . ", '')"
            : "''";
        $supplierImageExpression = $supplierImageCandidates
            ? 'COALESCE(' . implode(', ', $supplierImageCandidates) . ", '')"
            : "''";
        $supplierQuantityExpression = $supplierHasQuantity
            ? 'GREATEST(COALESCE(o.quantity, 1), 1)'
            : '1';

        $rows = array_merge($rows, publicPurchaseActivityFetch(
            "SELECT
                " . $supplierTransactionIdExpression . " AS transaction_id,
                o.user_id,
                " . $supplierTimeExpression . " AS created_at,
                u.username,
                " . $supplierProductExpression . " AS product_name,
                " . $supplierQuantityExpression . " AS quantity,
                " . $supplierImageExpression . " AS image_path,
                " . $supplierDurationExpression . " AS duration,
                COALESCE(t.description, '') AS source_description,
                CONCAT('supplier-order:', o.id) AS event_key
             FROM supplier_orders o
             INNER JOIN users u
                ON u.id = o.user_id
               AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('user', 'reseller')
             " . $supplierTransactionJoin . "
             " . implode("\n             ", $supplierJoins) . "
             WHERE " . $supplierStatusCondition . "
               AND " . $supplierTimeExpression . " >= ?
               AND " . $supplierTimeExpression . " < ?
             ORDER BY " . $supplierTimeExpression . " DESC, o.id DESC
             LIMIT " . $candidateLimit,
            $from,
            $until,
            'supplier_orders'
        ));
    } else {
        $markUnavailable('supplier_orders', 'table_or_required_columns_unavailable');
    }

    $events = [];
    foreach ($rows as $row) {
        $transactionId = max(0, (int) ($row['transaction_id'] ?? 0));
        $userId = max(0, (int) ($row['user_id'] ?? 0));
        $eventKey = trim((string) ($row['event_key'] ?? ''));
        if ($transactionId < 1 || $userId < 1 || $eventKey === '') continue;

        $createdAt = trim((string) ($row['created_at'] ?? ''));
        if ($createdAt === '') continue;

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt, $timezone);
        if (!$date) {
            $timestamp = strtotime($createdAt);
            if ($timestamp === false) continue;
            $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        }
        $timestamp = $date->getTimestamp();

        $productName = trim((string) ($row['product_name'] ?? ''));
        if ($productName === '') {
            $productName = publicPurchaseActivityProductFromDescription(
                (string) ($row['source_description'] ?? '')
            );
        }

        $duration = trim((string) ($row['duration'] ?? ''));
        $productName = function_exists('commerceComposeProductLabel')
            ? commerceComposeProductLabel($productName, $duration)
            : trim($productName . ($duration !== '' && strcasecmp($duration, 'Standard') !== 0 ? ' - ' . $duration : ''));

        if ($productName === '') $productName = getAppLang() === 'en' ? 'Product' : 'สินค้า';
        $quantity = max(1, min(10000, (int) ($row['quantity'] ?? 1)));
        $imageUrl = publicPurchaseActivityImageUrl((string) ($row['image_path'] ?? ''));

        if (!isset($events[$eventKey])) {
            $events[$eventKey] = [
                '_transaction_id' => $transactionId,
                '_timestamp' => $timestamp,
                'account_name' => trim((string) ($row['username'] ?? '')) ?: 'User',
                'product_name' => $productName,
                'image_url' => $imageUrl,
                'quantity' => $quantity,
                'purchased_at' => $date->format('Y-m-d H:i:s'),
            ];
            continue;
        }

        if ((string) ($events[$eventKey]['image_url'] ?? '') === '' && $imageUrl !== '') {
            $events[$eventKey]['image_url'] = $imageUrl;
        }

        if (strncmp($eventKey, 'local-legacy' . chr(31), 13) === 0) {
            $events[$eventKey]['quantity'] = min(
                10000,
                (int) $events[$eventKey]['quantity'] + $quantity
            );
        } else {
            $events[$eventKey]['quantity'] = max(
                (int) $events[$eventKey]['quantity'],
                $quantity
            );
        }

        if ($timestamp > (int) $events[$eventKey]['_timestamp']
            || ($timestamp === (int) $events[$eventKey]['_timestamp']
                && $transactionId > (int) $events[$eventKey]['_transaction_id'])) {
            $events[$eventKey]['_transaction_id'] = $transactionId;
            $events[$eventKey]['_timestamp'] = $timestamp;
            $events[$eventKey]['purchased_at'] = $date->format('Y-m-d H:i:s');
        }
    }

    $activity = array_values($events);
    usort($activity, static function (array $left, array $right): int {
        $timeCompare = (int) ($right['_timestamp'] ?? 0) <=> (int) ($left['_timestamp'] ?? 0);
        if ($timeCompare !== 0) return $timeCompare;
        return (int) ($right['_transaction_id'] ?? 0) <=> (int) ($left['_transaction_id'] ?? 0);
    });
    $activity = array_slice($activity, 0, $limit);

    foreach ($activity as &$item) {
        unset($item['_transaction_id'], $item['_timestamp']);
    }
    unset($item);

    $GLOBALS['purchase_activity_diagnostics']['returned'] = count($activity);
    if ($cacheKey !== '' && function_exists('apcu_store')) {
        // A short TTL keeps new purchases visible quickly while collapsing the
        // duplicate query load from many users polling at the same time.
        @apcu_store($cacheKey, $activity, 12);
        $GLOBALS['purchase_activity_diagnostics']['cache'] = 'apcu_store';
    }
    if (!$bypassCache) {
        $cachePayload = json_encode([
            'day_from' => $from,
            'generated_at' => time(),
            'items' => $activity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($cachePayload)) {
            $cacheDir = dirname($fileCache);
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                try {
                    $suffix = bin2hex(random_bytes(5));
                } catch (Throwable $ignored) {
                    $suffix = str_replace('.', '', uniqid('', true));
                }
                $tempCache = $fileCache . '.tmp-' . $suffix;
                if (@file_put_contents($tempCache, $cachePayload, LOCK_EX) === strlen($cachePayload)) {
                    @chmod($tempCache, 0640);
                    if (!@rename($tempCache, $fileCache)) @unlink($tempCache);
                } else {
                    @unlink($tempCache);
                }
            }
        }
    }
    return $activity;
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest(): bool
{
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Sanitize input
 */
function cleanInput($data)
{
    if (!is_scalar($data)) {
        return '';
    }
    $data = trim((string) $data);
    $data = stripslashes($data);
    // Removed htmlspecialchars here to prevent double-escaping in the DB.
    // We always escape on output in the templates.
    return $data;
}

/**
 * Clean announcement (marquee) input.
 * Allows basic inline icon tags (e.g. <i class="..."></i>) and simple formatting.
 */
function cleanAnnouncementInput($data)
{
    if (!is_scalar($data)) {
        return '';
    }
    $data = trim((string) $data);
    $data = stripslashes($data);

    // Allow only a small set of formatting tags.
    $data = strip_tags($data, '<i><span><b><strong><em><br>');

    // Remove all attributes from formatting tags. For i/span, preserve only a strict class list.
    $data = preg_replace_callback('/<(i|span)\b([^>]*)>/i', function ($match) {
        $tag = strtolower($match[1]);
        $attrs = $match[2];
        $classes = [];
        if (preg_match('/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $classMatch)) {
            $raw = $classMatch[1] !== '' ? $classMatch[1] : $classMatch[2];
            foreach (preg_split('/\s+/', trim($raw)) as $class) {
                if ($class !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $class)) {
                    $classes[] = $class;
                }
                if (count($classes) >= 10) break;
            }
        }
        // Every class has already passed the strict character whitelist above,
        // so keeping literal quotes is safe and makes sanitization idempotent.
        return '<' . $tag . (empty($classes) ? '' : ' class="' . implode(' ', $classes) . '"') . '>';
    }, $data);
    $data = preg_replace('/<(b|strong|em)\b[^>]*>/i', '<$1>', $data);
    $data = preg_replace('/<br\b[^>]*\/?\s*>/i', '<br>', $data);

    return $data;
}


/**
 * Parse money/price input safely (supports "3.5", "3,5", "$3.5", "Rp30.000", etc.)
 * Returns float value.
 */
function parseMoneyInput($value): float
{
    if ($value === null || !is_scalar($value)) {
        return 0.0;
    }
    $s = trim((string) $value);
    if ($s === '')
        return 0.0;

    // Keep only digits, separators, minus
    $s = preg_replace('/[^0-9,\.\-]/', '', $s);

    // If both comma and dot exist, decide decimal separator by last position
    $hasComma = strpos($s, ',') !== false;
    $hasDot = strpos($s, '.') !== false;

    if ($hasComma && $hasDot) {
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma > $lastDot) {
            // comma as decimal: remove dots (thousands) then comma->dot
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // dot as decimal: remove commas (thousands)
            $s = str_replace(',', '', $s);
        }
    } elseif ($hasComma && !$hasDot) {
        // comma as decimal
        $s = str_replace(',', '.', $s);
    } else {
        // dot only or none: ok
    }

    // Prevent strings like "-" or "."
    if ($s === '' || $s === '-' || $s === '.' || $s === '-.')
        return 0.0;

    return (float) $s;
}

/**
 * Language Management Class
 */
class Lang {
    public static function current() {
        return getAppLang();
    }
    private static $translations = null;

    private static function init() {
        if (self::$translations !== null) return;
        
        self::$translations = [
            'admin.settings.success.branding' => [ 'en' => 'Store title updated successfully!', 'th' => 'อัปเดตชื่อร้านค้าเรียบร้อยแล้ว!' ],
            'admin.settings.success.promptpay' => [ 'en' => 'PromptPay settings saved successfully!', 'th' => 'บันทึกการตั้งค่าพร้อมเพย์เรียบร้อยแล้ว!' ],
            'admin.settings.success.truemoney' => [ 'en' => 'TrueMoney Angpao settings saved successfully!', 'th' => 'บันทึกการตั้งค่าทรูมันนี่เรียบร้อยแล้ว!' ],
            'admin.settings.success.easyslip' => [ 'en' => 'EasySlip settings saved successfully!', 'th' => 'บันทึกการตั้งค่า EasySlip เรียบร้อยแล้ว!' ],
            'admin.settings.success.binance' => [ 'en' => 'Binance settings saved successfully!', 'th' => 'บันทึกการตั้งค่า Binance เรียบร้อยแล้ว!' ],
            'admin.settings.success.account' => [ 'en' => 'Account updated successfully', 'th' => 'อัปเดตข้อมูลบัญชีเรียบร้อยแล้ว' ],
            
            // Users
            'admin.users.success.add' => [ 'en' => 'User added successfully', 'th' => 'เพิ่มผู้ใช้สำเร็จแล้ว' ],
            'admin.users.error.add' => [ 'en' => 'Failed to add user', 'th' => 'ไม่สามารถเพิ่มผู้ใช้ได้' ],
            'admin.users.success.ban' => [ 'en' => 'User banned successfully', 'th' => 'แบนผู้ใช้สำเร็จแล้ว' ],
            'admin.users.success.unban' => [ 'en' => 'User unbanned successfully', 'th' => 'ยกเลิกแบนผู้ใช้สำเร็จแล้ว' ],
            'admin.users.success.delete' => [ 'en' => 'User deleted successfully', 'th' => 'ลบผู้ใช้สำเร็จแล้ว' ],
            'admin.users.error.delete' => [ 'en' => 'Failed to delete user', 'th' => 'ไม่สามารถลบผู้ใช้ได้' ],
            'admin.users.success.balance_add' => [ 'en' => 'Balance added successfully', 'th' => 'เพิ่มยอดเงินสำเร็จแล้ว' ],
            'admin.users.success.balance_deduct' => [ 'en' => 'Balance deducted successfully', 'th' => 'หักยอดเงินสำเร็จแล้ว' ],
            'common.error.operation' => [ 'en' => 'Operation failed', 'th' => 'การดำเนินการล้มเหลว' ],
            'common.error' => [ 'en' => 'Error', 'th' => 'เกิดข้อผิดพลาด' ],
            'admin.products.category_placeholder' => [ 'en' => 'Category {number}', 'th' => 'หมวดหมู่ {number}' ],
            'admin.settings.easyslip_phone_placeholder' => [ 'en' => 'Receiver phone number', 'th' => 'กรอกเบอร์โทรผู้รับเงิน' ],
            'admin.settings.enable_truemoney' => [ 'en' => 'Enable TrueMoney', 'th' => 'เปิดใช้งาน TrueMoney' ],
            'admin.settings.wallet_number' => [ 'en' => 'Wallet Number', 'th' => 'หมายเลขวอลเล็ท' ],
            'admin.settings.wallet_hint' => [ 'en' => 'Enter wallet phone number', 'th' => 'กรอกหมายเลขโทรศัพท์วอลเล็ท' ],
            'admin.settings.save_truemoney' => [ 'en' => 'Save TrueMoney', 'th' => 'บันทึก TrueMoney' ],
            'admin.settings.easyslip_enabled' => [ 'en' => 'Enable EasySlip', 'th' => 'เปิดใช้งาน EasySlip' ],
            'common.loading' => ['en' => 'Loading...', 'th' => 'กำลังโหลด...'],
            'reseller.dashboard.title' => ['en' => 'Reseller Dashboard', 'th' => 'หน้าหลักตัวแทน'],

            // Products
            'admin.products.success.add' => [ 'en' => 'Product added successfully', 'th' => 'เพิ่มสินค้าสำเร็จแล้ว' ],
            'admin.products.error.add' => [ 'en' => 'Failed to add product', 'th' => 'ไม่สามารถเพิ่มสินค้าได้' ],
            'admin.products.success.edit' => [ 'en' => 'Product updated successfully', 'th' => 'อัปเดตสินค้าสำเร็จแล้ว' ],
            'admin.products.error.edit' => [ 'en' => 'Failed to update product', 'th' => 'ไม่สามารถอัปเดตสินค้าได้' ],
            'admin.products.success.delete' => [ 'en' => 'Product deleted successfully', 'th' => 'ลบสินค้าสำเร็จแล้ว' ],
            'admin.products.error.delete' => [ 'en' => 'Failed to delete product', 'th' => 'ไม่สามารถลบทิ้งได้' ],
            'admin.products.success.status' => [ 'en' => 'Product status updated successfully', 'th' => 'อัปเดตสถานะสินค้าสำเร็จแล้ว' ],
            'admin.products.error.name_required' => [ 'en' => 'Product name is required', 'th' => 'กรุณากรอกชื่อสินค้า' ],
            'admin.products.success.variant_add' => [ 'en' => 'Variant added successfully', 'th' => 'เพิ่มรูปแบบสินค้าสำเร็จแล้ว' ],
            'admin.products.error.variant_add' => [ 'en' => 'Failed to add variant', 'th' => 'ไม่สามารถเพิ่มรูปแบบสินค้าได้' ],
            'admin.products.error.duration_required' => [ 'en' => 'Duration is required', 'th' => 'กรุณากรอกระยะเวลา' ],
            'admin.dashboard.success_purchase' => ['en' => '{count} key(s) purchased successfully for {total}!', 'th' => 'ซื้อสำเร็จ {count} คีย์ เป็นจำนวนเงิน {total}!'],
            'admin.dashboard.error_no_keys' => ['en' => 'No keys found for this product.', 'th' => 'ไม่พบคีย์สำหรับสินค้านี้'],
            'admin.dashboard.error_not_enough' => ['en' => 'Not enough keys available.', 'th' => 'คีย์มีจำนวนไม่เพียงพอ'],
            'admin.profit.help_text' => ['en' => '* Profit uses the Commerce Center sale and cost snapshots for every sales channel. Orders with unknown cost are not guessed as zero-cost profit.', 'th' => '* กำไรใช้ข้อมูลราคาขายและต้นทุนจาก Commerce Center ของทุกช่องทางการขาย รายการที่ยังไม่ทราบต้นทุนจะไม่ถูกเดาเป็นกำไรต้นทุนศูนย์'],
            'dashboard.title' => ['en' => 'User Dashboard', 'th' => 'หน้าหลักผู้ใช้'],
            'dashboard.welcome' => ['en' => 'Welcome', 'th' => 'ยินดีต้อนรับ'],
            'dashboard.status.active' => ['en' => 'Active', 'th' => 'ใช้งานอยู่'],
            'dashboard.status.banned' => ['en' => 'Banned', 'th' => 'ถูกระงับ'],
            'dashboard.keys_bought' => ['en' => 'Total Keys Purchased', 'th' => 'คีย์ที่ซื้อทั้งหมด'],
            'dashboard.view_my_keys' => ['en' => 'View My Keys', 'th' => 'ดูคีย์ของฉัน'],
            'dashboard.recent_purchases' => ['en' => 'Recent Purchases', 'th' => 'การซื้อล่าสุด'],
            'dashboard.empty_keys' => ['en' => 'No keys purchased yet', 'th' => 'ยังไม่มีการซื้อคีย์'],
            'dashboard.start_buying' => ['en' => 'Start buying now', 'th' => 'เริ่มซื้อเลย'],
            'dashboard.view_all_keys' => ['en' => 'View All Keys', 'th' => 'ดูคีย์ทั้งหมด'],
            'common.no_duration' => ['en' => 'No Duration', 'th' => 'ไม่มีระยะเวลา'],
            'nav.balance' => ['en' => 'Credit Balance', 'th' => 'เครดิตคงเหลือ'],
            'common.error.user_not_found' => ['en' => 'User not found', 'th' => 'ไม่พบรายชื่อผู้ใช้'],
            'common.error.invalid_email' => ['en' => 'Invalid email address', 'th' => 'ที่อยู่อีเมลไม่ถูกต้อง'],
            'common.error.password_short' => ['en' => 'New password must be at least 6 characters', 'th' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร'],
            'common.error.password_mismatch' => ['en' => 'New password confirmation does not match', 'th' => 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน'],
            'common.error.update_failed' => ['en' => 'Failed to update account', 'th' => 'ไม่สามารถอัปเดตบัญชีได้'],
            'common.close' => ['en' => 'Close', 'th' => 'ปิด'],
            'common.categories' => ['en' => 'Categories:', 'th' => 'หมวดหมู่:'],
            'common.all' => ['en' => 'All', 'th' => 'ทั้งหมด'],
            'common.error.invalid_request' => ['en' => 'Invalid request', 'th' => 'คำขอไม่ถูกต้อง'],
            'common.verifying' => ['en' => 'Verifying...', 'th' => 'กำลังตรวจสอบ...'],
            'common.success' => ['en' => 'Success', 'th' => 'สำเร็จ'],
            'common.copy_failed' => ['en' => 'Copy failed', 'th' => 'คัดลอกไม่สำเร็จ'],
            'common.copy_manually' => ['en' => 'Please manually copy the text.', 'th' => 'กรุณาคัดลอกข้อความด้วยตนเอง'],
            'common.confirm_delete_item' => ['en' => 'Are you sure you want to delete this item?', 'th' => 'ยืนยันการลบรายการนี้หรือไม่?'],
            'common.download' => ['en' => 'Download', 'th' => 'ดาวน์โหลด'],
            'common.copy' => ['en' => 'Copy', 'th' => 'คัดลอก'],
            'common.copy_all' => ['en' => 'Copy All', 'th' => 'คัดลอกทั้งหมด'],
            'common.lang.th' => ['en' => 'Thai', 'th' => 'ภาษาไทย'],
            'common.lang.en' => ['en' => 'English', 'th' => 'English'],
            'common.search_placeholder' => ['en' => 'Search products...', 'th' => 'ค้นหาสินค้า...'],
            'common.table.product' => ['en' => 'Product', 'th' => 'สินค้า'],
            'common.table.duration' => ['en' => 'Duration', 'th' => 'ระยะเวลา'],
            'common.table.key' => ['en' => 'License Key', 'th' => 'คีย์สินค้า'],
            'common.table.price' => ['en' => 'Price', 'th' => 'ราคา'],
            'common.table.date' => ['en' => 'Date', 'th' => 'วันที่'],
            'common.actions' => ['en' => 'Actions', 'th' => 'จัดการ'],
            'common.copy_key' => ['en' => 'Copy Key', 'th' => 'คัดลอกคีย์'],
            'account.title' => ['en' => 'Account Settings', 'th' => 'ตั้งค่าบัญชี'],
            'account.success.update' => ['en' => 'Account updated successfully', 'th' => 'อัปเดตข้อมูลบัญชีเรียบร้อยแล้ว'],
            'account.error.password' => ['en' => 'Current password is incorrect', 'th' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'],
            'account.error.email_used' => ['en' => 'Username or email already in use', 'th' => 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้งานแล้ว'],
            'account.error.username_format' => ['en' => 'Username must be 3-60 characters and use only letters, numbers, dot, dash, or underscore', 'th' => 'ชื่อผู้ใช้ต้องมี 3-60 ตัวอักษร และใช้ได้เฉพาะตัวอักษร ตัวเลข จุด ขีดกลาง หรือขีดล่าง'],
            'account.error.invalid_email' => ['en' => 'Invalid email address', 'th' => 'รูปแบบอีเมลไม่ถูกต้อง'],
            'account.error.password_short' => ['en' => 'New password must be at least 8 characters', 'th' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'],
            'account.error.password_mismatch' => ['en' => 'New password confirmation does not match', 'th' => 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน'],
            'account.error.update_failed' => ['en' => 'Failed to update account', 'th' => 'ไม่สามารถอัปเดตบัญชีได้'],
            'buy.title' => ['en' => 'Buy Keys - Reseller Panel', 'th' => 'ซื้อคีย์ - ระบบรีเซลเลอร์'],
            'buy.sold_out' => ['en' => 'Sold Out', 'th' => 'สินค้าหมด'],
            'buy.error.not_enough' => ['en' => 'Not enough keys available for this duration', 'th' => 'จำนวนคีย์ไม่เพียงพอสำหรับระยะเวลานี้'],
            'buy.success.purchase' => ['en' => '{count} key(s) purchased successfully for {total}!', 'th' => 'ซื้อสำเร็จ {count} คีย์ เป็นจำนวนเงิน {total}!'],
            'buy.search_placeholder' => ['en' => 'Search products...', 'th' => 'ค้นหาสินค้า...'],
            'buy.copy_success' => ['en' => 'Key copied to clipboard!', 'th' => 'คัดลอกคีย์ไปยังคลิปบอร์ดแล้ว!'],
            'buy.category_all' => ['en' => 'All', 'th' => 'ทั้งหมด'],
            'buy.purchase_successful' => ['en' => 'Purchase Successful', 'th' => 'ซื้อสินค้าสำเร็จ'],
            'buy.modal.confirm_title' => ['en' => 'Confirm Purchase', 'th' => 'ยืนยันการสั่งซื้อ'],
            'buy.modal.price_per_item' => ['en' => 'Price per item:', 'th' => 'ราคาต่อชิ้น:'],
            'buy.modal.quantity' => ['en' => 'Quantity:', 'th' => 'จำนวน:'],
            'buy.modal.total_price' => ['en' => 'Total Price:', 'th' => 'ราคารวม:'],
            'buy.modal.balance_after' => ['en' => 'Balance After:', 'th' => 'ยอดคงเหลือหลังซื้อ:'],
            'buy.modal.success_title' => ['en' => 'Success!', 'th' => 'สำเร็จ!'],
            'buy.modal.amount_paid' => ['en' => 'Amount Paid:', 'th' => 'จำนวนที่ชำระ:'],
            'admin.transactions.table.date' => ['en' => 'Date', 'th' => 'วันที่'],
            'admin.dashboard.error.invalid_request' => ['en' => 'Invalid purchase request.', 'th' => 'คำขอซื้อไม่ถูกต้อง'],
            'admin.dashboard.error.purchase_failed' => ['en' => 'Purchase failed. Please contact admin.', 'th' => 'การซื้อล้มเหลว กรุณาติดต่อผู้ดูแลระบบ'],
            'admin.users.error.fields_required' => ['en' => 'All fields are required', 'th' => 'กรุณากรอกข้อมูลให้ครบทุกช่อง'],
            'admin.settings.store_title_color_hint' => ['en' => 'Use HEX (e.g., #60a5fa)', 'th' => 'ใช้รหัสสี HEX (เช่น #60a5fa)'],
            'admin.settings.store_title_style_hint' => ['en' => 'For combinations (e.g., bold + uppercase), edit manually in database.', 'th' => 'สำหรับการรวมสไตล์ (เช่น หนา + ตัวพิมพ์ใหญ่) กรุณาแก้ไขในฐานข้อมูลโดยตรง'],
            'admin.settings.font_style.normal' => ['en' => 'Normal', 'th' => 'ปกติ'],
            'admin.settings.font_style.bold' => ['en' => 'Bold', 'th' => 'หนา'],
            'admin.settings.font_style.italic' => ['en' => 'Italic', 'th' => 'เอียง'],
            'admin.settings.font_style.uppercase' => ['en' => 'Uppercase', 'th' => 'ตัวพิมพ์ใหญ่'],
            'admin.categories.success.update' => ['en' => 'Download URL for category \'{name}\' updated successfully.', 'th' => 'อัปเดตลิงก์ดาวน์โหลดสำหรับหมวดหมู่ \'{name}\' เรียบร้อยแล้ว'],
            'admin.categories.error.update' => ['en' => 'Failed to update download URL.', 'th' => 'ไม่สามารถอัปเดตลิงก์ดาวน์โหลดได้'],
            'admin.categories.error.invalid_url' => ['en' => 'Download URL must be a valid HTTPS URL.', 'th' => 'ลิงก์ดาวน์โหลดต้องเป็น URL แบบ HTTPS ที่ถูกต้อง'],
            'deposit.angpao.placeholder' => ['en' => 'Paste Angpao link here', 'th' => 'วางลิงก์ซองอั่งเปาที่นี่'],
            'deposit.angpao.verifying' => ['en' => 'Verifying Angpao...', 'th' => 'กำลังตรวจสอบซองอั่งเปา...'],
            'deposit.status.verifying' => ['en' => 'Verifying slip...', 'th' => 'กำลังตรวจสอบข้อมูลสลิป...'],
            'deposit.binance.verifying' => ['en' => 'Verifying Transaction...', 'th' => 'กำลังตรวจสอบ Transaction...'],
            'deposit.info.pending_review' => ['en' => 'Your payment is pending admin review or confirmation from gateway.', 'th' => 'ยอดเงินของคุณอยู่ระหว่างรอการตรวจสอบหรือยืนยัน'],
            'deposit.redeem.failed' => ['en' => 'Redeem failed', 'th' => 'การแลกโค้ดล้มเหลว'],
            'deposit.receiver_name' => ['en' => '{en}', 'th' => '{th}'],
            'deposit.status.success' => ['en' => 'Deposit successful', 'th' => 'เติมเงินสำเร็จ'],
            'deposit.bank_default' => ['en' => 'KBank', 'th' => 'กสิกรไทย'],
            'deposit.bank_name' => ['en' => 'KBank', 'th' => 'กสิกรไทย'],
            'common.default_announcement' => ['en' => '🎖️ Welcome to the official SPWZ shop 🎖️', 'th' => '🎖️ยินดีต้อนรับเข้าสู่ร้าน SPWZ อย่างเป็นทางการ 🎖️'],
            'admin.out_of_stock.heading' => ['en' => 'Out of Stock Variants', 'th' => 'รูปแบบที่สินค้าหมด'],
            'deposit.error.binance_tx_used' => ['en' => 'This TxID has already been used for a deposit. Duplicate use is prohibited.', 'th' => 'TxID นี้เคยถูกใช้เติมเงินแล้ว ห้ามใช้ซ้ำ'],
            'deposit.error.binance_tx_processing' => ['en' => 'This TxID is currently being processed. Please wait.', 'th' => 'กำลังประมวลผล TxID นี้อยู่ กรุณารอสักครู่'],
            'deposit.error.exchange_rate_failed' => ['en' => 'Unable to fetch current exchange rate.', 'th' => 'ไม่สามารถแปลงอัตราแลกเปลี่ยนได้'],
            'deposit.error.save_failed' => ['en' => 'Unable to save deposit data.', 'th' => 'ไม่สามารถบันทึกข้อมูลการฝากเงินได้'],
            'deposit.error.add_balance_failed' => ['en' => 'Unable to credit balance to account.', 'th' => 'ไม่สามารถเพิ่มยอดเงินได้'],
            'deposit.error.slip_hash_used' => ['en' => 'This slip image has already been submitted. Duplicate slips are prohibited.', 'th' => 'สลิปนี้เคยถูกส่งเข้าระบบไปแล้ว ห้ามใช้สลิปซ้ำ'],
            'deposit.error.slip_invalid_format' => ['en' => 'Invalid slip image format.', 'th' => 'รูปแบบไฟล์รูปภาพสลิปไม่ถูกต้อง'],
            'deposit.error.slip_used' => ['en' => 'This slip has already been used.', 'th' => 'สลิปนี้เคยใช้แล้ว'],
            'deposit.error.easyslip_not_configured' => ['en' => 'Receiver account not configured. Please contact admin.', 'th' => 'ยังไม่ได้ตั้งค่าบัญชีผู้รับเงิน กรุณาตรวจสอบในหน้าตั้งค่า'],
            'deposit.error.binance_disabled' => ['en' => 'Binance deposit system is not currently enabled.', 'th' => 'ระบบเติมเงินผ่าน Binance ยังไม่เปิดใช้งาน'],
            'deposit.error.method_not_allowed' => ['en' => 'Method not allowed.', 'th' => 'เรียกใช้งานไม่ถูกต้อง'],
            'deposit.error.enter_txid' => ['en' => 'Please enter Transaction ID', 'th' => 'กรุณากรอก Transaction ID'],
            'admin.out_of_stock.desc' => ['en' => 'Showing variants where available stock = 0', 'th' => 'แสดงรูปแบบสินค้าที่สต็อกคงเหลือ = 0'],
            'admin.out_of_stock.empty' => ['en' => 'No out-of-stock variants found.', 'th' => 'ไม่พบรูปแบบที่สินค้าหมด'],
            'admin.out_of_stock.table.product' => ['en' => 'Product', 'th' => 'สินค้า'],
            'admin.out_of_stock.table.variant' => ['en' => 'Variant', 'th' => 'รูปแบบ'],
            'admin.out_of_stock.table.user_price' => ['en' => 'User Price', 'th' => 'ราคาขาย'],
            'admin.out_of_stock.table.reseller_price' => ['en' => 'Reseller Price', 'th' => 'ราคารีเซลเลอร์'],
            'admin.out_of_stock.table.total_keys' => ['en' => 'Total Keys', 'th' => 'คีย์ทั้งหมด'],
            'admin.out_of_stock.table.available' => ['en' => 'Available', 'th' => 'พร้อมใช้งาน'],
            'admin.out_of_stock.table.action' => ['en' => 'Action', 'th' => 'ดำเนินการ'],
            'admin.out_of_stock.add_keys' => ['en' => 'Add Keys', 'th' => 'เพิ่มคีย์'],
            'admin.out_of_stock.modal.title' => ['en' => 'Add Keys to Variant', 'th' => 'เพิ่มคีย์เข้าในรูปแบบสินค้า'],
            'admin.out_of_stock.modal.product' => ['en' => 'Product:', 'th' => 'สินค้า:'],
            'admin.out_of_stock.modal.variant' => ['en' => 'Variant:', 'th' => 'รูปแบบ:'],
            'admin.out_of_stock.modal.key_placeholder' => ['en' => 'Key Codes (one per line)', 'th' => 'รหัสคีย์ (บรรทัดละ 1 คีย์)'],
            'admin.out_of_stock.modal.cancel' => ['en' => 'Cancel', 'th' => 'ยกเลิก'],
            'admin.out_of_stock.modal.add' => ['en' => 'Add Keys', 'th' => 'เพิ่มคีย์'],
            'common.days' => ['en' => 'Days', 'th' => 'วัน'],
            'history.title' => ['en' => 'History', 'th' => 'ประวัติ'],
            'history.refill_title' => ['en' => 'Refill History', 'th' => 'ประวัติการเติมเงิน'],
            'history.detail_title' => ['en' => 'Purchase Details', 'th' => 'รายละเอียดการซื้อ'],
            'history.total_refill' => ['en' => 'Total Refill', 'th' => 'ยอดเติมเงินทั้งหมด'],
            'history.total' => ['en' => 'Total', 'th' => 'รวม'],
            'history.latest' => ['en' => 'LATEST', 'th' => 'ล่าสุด'],
            'history.more_codes' => ['en' => 'more codes', 'th' => 'รหัสเพิ่มเติม'],
            'history.search_placeholder' => ['en' => 'Search purchases or license keys...', 'th' => 'ค้นหาการซื้อหรือคีย์...'],
            'history.copy_success' => ['en' => 'Copied!', 'th' => 'คัดลอกแล้ว'],
            'history.main_balance' => ['en' => 'Main Balance:', 'th' => 'ยอดคงเหลือหลัก:'],
            'history.empty' => ['en' => 'No purchase history found', 'th' => 'ไม่พบประวัติการซื้อ'],

            // Login & Register
            'login.title' => ['en' => 'Login', 'th' => 'เข้าสู่ระบบ'],
            'login.desc' => ['en' => 'Login to access your account', 'th' => 'เข้าสู่ระบบเพื่อใช้งาน'],
            'login.username' => ['en' => 'Username', 'th' => 'ชื่อผู้ใช้'],
            'login.password' => ['en' => 'Password', 'th' => 'รหัสผ่าน'],
            'login.placeholder.username' => ['en' => 'Enter username', 'th' => 'ระบุชื่อผู้ใช้'],
            'login.placeholder.password' => ['en' => 'Enter password', 'th' => 'ระบุรหัสผ่าน'],
            'login.btn' => ['en' => 'Login', 'th' => 'เข้าสู่ระบบ'],
            'login.no_account' => ['en' => "Don't have an account?", 'th' => 'ยังไม่มีบัญชีใช่ไหม?'],
            'login.banned' => ['en' => 'Your account has been banned', 'th' => 'บัญชีของคุณถูกระงับการใช้งาน'],
            'login.error' => ['en' => 'Invalid username or password', 'th' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],

            'register.title' => ['en' => 'Create Account', 'th' => 'สมัครสมาชิก'],
            'register.desc' => ['en' => 'Join us today', 'th' => 'เริ่มใช้งานวันนี้'],
            'register.email' => ['en' => 'Email Address', 'th' => 'อีเมล'],
            'register.confirm_password' => ['en' => 'Confirm Password', 'th' => 'ยืนยันรหัสผ่าน'],
            'register.placeholder.email' => ['en' => 'Enter email', 'th' => 'ระบุอีเมล'],
            'register.placeholder.confirm' => ['en' => 'Confirm password', 'th' => 'ยืนยันรหัสผ่าน'],
            'register.btn' => ['en' => 'Register', 'th' => 'สมัครสมาชิก'],
            'register.have_account' => ['en' => 'Already have an account?', 'th' => 'มีบัญชีอยู่แล้วใช่ไหม?'],
            'register.error.mismatch' => ['en' => 'Passwords do not match', 'th' => 'รหัสผ่านไม่ตรงกัน'],
            'register.error.rate_limit' => ['en' => 'Too many registration attempts. Please wait about {minutes} minute(s).', 'th' => 'สมัครสมาชิกบ่อยเกินไป กรุณารอประมาณ {minutes} นาที'],
            'register.error.failed' => ['en' => 'Registration could not be completed.', 'th' => 'ไม่สามารถสมัครสมาชิกได้'],
            'register.success.login' => ['en' => 'Registration completed. Please sign in.', 'th' => 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ'],

            // Admin Dashboard
            'admin.dashboard.title' => ['th' => 'หน้าจัดการ - ผู้ดูแลระบบ', 'en' => 'Store - Admin'],

            // Admin Users (New keys)
            'admin.users.heading' => ['th' => 'จัดการผู้ใช้', 'en' => 'Manage Users'],
            'admin.users.add_user_btn' => ['th' => 'เพิ่มผู้ใช้', 'en' => 'Add User'],
            'admin.users.modal.add_title' => ['th' => 'เพิ่มผู้ใช้ใหม่', 'en' => 'Add New User'],
            'admin.users.modal.initial_balance' => ['th' => 'ยอดเงินเริ่มต้น', 'en' => 'Initial Balance'],
            'admin.users.modal.balance_title' => ['th' => 'แก้ไขยอดเงิน', 'en' => 'Update Balance'],
            'admin.users.modal.user_label' => ['th' => 'ผู้ใช้:', 'en' => 'User:'],
            'admin.users.modal.current_balance' => ['th' => 'ยอดเงินปัจจุบัน:', 'en' => 'Current Balance:'],
            'admin.users.modal.amount_to_add' => ['th' => 'จำนวนเงินที่เพิ่ม/ลด', 'en' => 'Amount to Add/Deduct'],
            'admin.users.modal.new_balance' => ['th' => 'ยอดเงินใหม่:', 'en' => 'New Balance:'],

            // Admin Settings (New keys)
            'admin.settings.general_title' => ['th' => 'ตั้งค่าทั่วไป', 'en' => 'General Settings'],
            'admin.settings.site_name' => ['th' => 'ชื่อเว็บไซต์', 'en' => 'Site Name'],
            'admin.settings.currency_symbol' => ['th' => 'สัญลักษณ์เงิน', 'en' => 'Currency Symbol'],
            'admin.settings.currency_name' => ['th' => 'ชื่อย่อเงิน', 'en' => 'Currency Name'],
            'admin.settings.store_title' => ['th' => 'แบรนด์ดิ้งร้านค้า', 'en' => 'Store Branding'],
            'admin.settings.store_title_desc' => ['th' => 'ปรับแต่งหัวข้อร้านค้า สี และสไตล์', 'en' => 'Customize store title, color, and style'],
            'admin.settings.store_title_text' => ['th' => 'ข้อความหัวข้อ', 'en' => 'Title Text'],
            'admin.settings.store_title_color' => ['th' => 'สีของหัวข้อ', 'en' => 'Title Color'],
            'admin.settings.store_title_style' => ['th' => 'สไตล์หัวข้อ', 'en' => 'Title Style'],
            'admin.settings.title_image' => ['th' => 'รูปภาพหัวข้อ (PNG)', 'en' => 'Title Image (PNG)'],
            'admin.settings.user_title_image' => ['th' => 'รูปหัวข้อฝั่งผู้ใช้', 'en' => 'User Title Image'],
            'admin.settings.reseller_title_image' => ['th' => 'รูปหัวข้อฝั่งตัวแทน', 'en' => 'Reseller Title Image'],
            'admin.settings.image_hint' => ['th' => 'เฉพาะไฟล์ .png เท่านั้น', 'en' => 'PNG only'],
            'admin.settings.save_store_title' => ['th' => 'บันทึกแบรนด์ดิ้ง', 'en' => 'Save Branding'],
            'admin.settings.save_general' => ['th' => 'บันทึกการตั้งค่าทั่วไป', 'en' => 'Save General Settings'],
            'admin.settings.announcement_title' => ['th' => 'ประกาศ (Marquee)', 'en' => 'Announcement Settings'],
            'admin.settings.announcement_desc' => ['th' => 'ตั้งค่าข้อความประกาศวิ่งที่หน้าซื้อสินค้า', 'en' => 'Configure scrolling announcement text'],
            'admin.settings.truemoney_title' => ['th' => 'TrueMoney Gift Voucher (Angpao)', 'en' => 'TrueMoney Gift Voucher (Angpao)'],
            'admin.settings.easyslip_title' => ['th' => 'EasySlip API (สแกนสลิป)', 'en' => 'EasySlip API (Slip Verification)'],
            'admin.settings.binance_title' => ['th' => 'Binance Pay (คริปโต)', 'en' => 'Binance Pay (Crypto)'],
            'admin.settings.api_key' => ['th' => 'API Key', 'en' => 'API Key'],
            'admin.settings.api_secret' => ['th' => 'API Secret', 'en' => 'API Secret'],
            'admin.settings.callback_url' => ['th' => 'Callback URL', 'en' => 'Callback URL'],
            'admin.settings.receiver_name_th' => ['th' => 'ชื่อผู้รับ (ไทย)', 'en' => 'Receiver Name (Thai)'],
            'admin.settings.receiver_name_en' => ['th' => 'ชื่อผู้รับ (Eng)', 'en' => 'Receiver Name (English)'],
            'admin.settings.account_number' => ['th' => 'เลขบัญชี/เบอร์โทร', 'en' => 'Account Number/Phone'],
            'admin.settings.bank_name' => ['th' => 'ชื่อธนาคาร', 'en' => 'Bank Name'],
            'admin.settings.wallet_address' => ['th' => 'Wallet Address', 'en' => 'Wallet Address'],
            'admin.settings.status_enabled' => ['th' => 'เปิดใช้งาน', 'en' => 'Enabled'],
            'admin.settings.status_disabled' => ['th' => 'ปิดใช้งาน', 'en' => 'Disabled'],
            'admin.settings.save_btn' => ['th' => 'บันทึกการตั้งค่า', 'en' => 'Save Settings'],
            'admin.settings.placeholder.token' => ['th' => 'วางโทเค็นของคุณที่นี่', 'en' => 'Paste your token here'],
            'admin.settings.placeholder.url' => ['th' => 'ปล่อยว่างเพื่อใช้ค่าเริ่มต้น', 'en' => 'Leave empty for default'],
            'admin.settings.characters' => ['th' => 'ตัวอักษร', 'en' => 'characters'],
            'admin.settings.announcement_default' => ['th' => 'ยินดีต้อนรับเข้าสู่ร้านค้า!', 'en' => 'Welcome to the shop!'],

            // Reseller Extras
            'reseller.buy_keys_btn' => ['th' => 'ซื้อสินค้าตัวแทน', 'en' => 'Buy Keys'],
            'buy.no_products_found' => ['en' => 'No products found', 'th' => 'ไม่พบสินค้า'],
            'buy.clear_filters_btn' => ['en' => 'Clear filters', 'th' => 'ล้างการกรอง'],
            'buy.product_name' => ['th' => 'ชื่อสินค้า:', 'en' => 'Product Name:'],
            'buy.license_keys' => ['th' => 'คีย์สินค้า:', 'en' => 'License Keys:'],
            'buy.amount_paid' => ['th' => 'จำนวนที่ชำระ:', 'en' => 'Amount Paid:'],
            'buy.download_file' => ['th' => 'ดาวน์โหลดไฟล์', 'en' => 'Download File'],
            'buy.copy_all' => ['th' => 'คัดลอกทั้งหมด', 'en' => 'Copy All'],
            'buy.cancel' => ['th' => 'ยกเลิก', 'en' => 'Cancel'],
            'buy.insufficient_balance' => ['th' => 'ยอดเงินไม่เพียงพอ!', 'en' => 'Insufficient balance!'],

            // Admin Settings Extra
            'admin.settings.title' => ['th' => 'ตั้งค่าระบบ', 'en' => 'System Settings'],
            'admin.settings.account_number_placeholder' => ['th' => 'กรอกเลขบัญชีหรือเบอร์โทร', 'en' => 'Enter account or phone number'],
            'admin.settings.easyslip_phone' => ['th' => 'เบอร์โทร PromptPay (ผู้รับ)', 'en' => 'PromptPay Phone (Receiver)'],
            'admin.settings.easyslip_phone_hint' => ['th' => 'เบอร์ PromptPay ที่ใช้รับเงินเพื่อตรวจสอบสลิป', 'en' => 'Receiver PromptPay for verifying slip'],
            'admin.settings.easyslip_account' => ['th' => 'เลขบัญชีธนาคาร (ผู้รับ)', 'en' => 'Receiver Account Number'],
            'admin.settings.easyslip_account_hint' => ['th' => 'เลขบัญชีธนาคารที่ใช้รับเงินเพื่อตรวจสอบสลิป', 'en' => 'Receiver bank account for verifying slip'],
            'admin.settings.receiver_name_placeholder' => ['th' => 'เช่น นายสมชาย ใจดี', 'en' => 'e.g., Mr. Somchai Jaidee'],
            'admin.settings.currency_selection' => ['th' => 'เลือกสกุลเงิน', 'en' => 'Select Currency'],
            'admin.settings.currency_hint' => ['th' => 'เลือกสกุลเงินหลักที่ต้องการใช้ในระบบ (หากตั้งเป็น THB ระบบจะคำนวณสลิปเป็นเงินบาท หากเป็น USD จะแปลงส่วนต่างให้อัตโนมัติ)', 'en' => 'Select the primary currency (If THB, slips are treated as Baht. If USD, they are auto-converted).'],
            'admin.settings.currency.thb' => ['th' => 'เงินบาทไทย (THB - ฿)', 'en' => 'Thai Baht (THB - ฿)'],
            'admin.settings.currency.usd' => ['th' => 'ดอลลาร์สหรัฐ (USD - $)', 'en' => 'US Dollar (USD - $)'],
            'admin.settings.currency.inr' => ['th' => 'รูปีอินเดีย (INR - ₹)', 'en' => 'Indian Rupee (INR - ₹)'],
            'admin.settings.currency.eur' => ['th' => 'ยูโร (EUR - €)', 'en' => 'Euro (EUR - €)'],
            'admin.settings.currency.gbp' => ['th' => 'ปอนด์สเตอลิงก์ (GBP - £)', 'en' => 'British Pound (GBP - £)'],
            'admin.settings.currency.vnd' => ['th' => 'ดงเวียดนาม (VND - ₫)', 'en' => 'Vietnamese Dong (VND - ₫)'],
            'admin.settings.currency.php' => ['th' => 'เปโซฟิลิปปินส์ (PHP - ₱)', 'en' => 'Philippine Peso (PHP - ₱)'],
            'admin.settings.currency.myr' => ['th' => 'ริงกิตมาเลเซีย (MYR - RM)', 'en' => 'Malaysian Ringgit (MYR - RM)'],
            'admin.settings.currency.jpy' => ['th' => 'เยนญี่ปุ่น (JPY - ¥)', 'en' => 'Japanese Yen (JPY - ¥)'],
            'admin.settings.currency.cny' => ['th' => 'หยวนจีน (CNY - ¥)', 'en' => 'Chinese Yuan (CNY - ¥)'],
            'admin.settings.currency.krw' => ['th' => 'วอนเกาหลีใต้ (KRW - ₩)', 'en' => 'South Korean Won (KRW - ₩)'],
            'admin.settings.easyslip_info' => ['th' => 'ชื่อใช้เพื่อตรวจสอบสลิปที่อัปโหลด (ปล่อยว่างไว้ถ้าไม่ต้องการเช็คชื่อ)', 'en' => 'Names verify uploaded slips (ignored if empty)'],
            'admin.settings.save_easyslip' => ['th' => 'บันทึกการตั้งค่า EasySlip', 'en' => 'Save EasySlip Settings'],
            'admin.settings.binance_enabled' => ['th' => 'เปิดใช้งานการเติมเงิน Binance USDT', 'en' => 'Enable Binance USDT Deposit'],
            'admin.settings.binance_wallet' => ['th' => 'ที่อยู่กระเป๋าเงิน (TRC20)', 'en' => 'Wallet Address (TRC20)'],
            'admin.settings.binance_wallet_hint' => ['th' => 'ที่อยู่ TRC20 ของคุณสำหรับรับ USDT', 'en' => 'Your TRC20 address for receiving USDT'],
            'admin.settings.binance_api_key' => ['th' => 'Binance API Key', 'en' => 'Binance API Key'],
            'admin.settings.binance_secret_key' => ['th' => 'Binance Secret Key', 'en' => 'Binance Secret Key'],
            'admin.settings.binance_secret_hint' => ['th' => 'Secret Key จะแสดงเพียงครั้งเดียวเมื่อสร้าง', 'en' => 'Secret Key shows only once when creating'],
            'admin.settings.binance_info' => ['th' => 'ต้องใช้ API และ Secret key (สิทธิ์ Read-Only)', 'en' => 'API and Secret keys required (Read-Only)'],
            'admin.settings.save_binance' => ['th' => 'บันทึกการตั้งค่า Binance', 'en' => 'Save Binance Settings'],
            'admin.settings.username_placeholder' => ['th' => 'ชื่อผู้ใช้สำหรับเข้าสู่ระบบ', 'en' => 'Username for login'],
            'admin.settings.email_placeholder' => ['th' => 'อีเมลสำหรับติดต่อหรือกู้คืน', 'en' => 'Email for contact or recovery'],
            'admin.settings.new_password' => ['th' => 'รหัสผ่านใหม่ (ไม่บังคับ)', 'en' => 'New Password (optional)'],
            'admin.settings.password_placeholder' => ['th' => 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน', 'en' => 'Leave blank to keep current'],
            'admin.settings.confirm_password_placeholder' => ['th' => 'ยืนยันรหัสผ่านใหม่อีกครั้ง', 'en' => 'Confirm new password'],
            'admin.settings.current_password' => ['th' => 'รหัสผ่านปัจจุบัน (จำเป็น)', 'en' => 'Current Password (required)'],
            'admin.settings.current_password_hint' => ['th' => 'จำเป็นต้องกรอกเพื่อบันทึกการเปลี่ยนแปลง', 'en' => 'Required to save changes.'],
            'admin.settings.announcement_text' => ['th' => 'ข้อความประกาศ', 'en' => 'Announcement Text'],
            'admin.settings.help.announcement_text' => ['th' => 'กรอกข้อความที่จะประกาศให้ผู้ใช้เห็นบนเว็บไซต์', 'en' => 'Enter the announcement shown to users on the website.'],
            'admin.settings.help.announcement_active' => ['th' => 'เปิดแสดงประกาศนี้บนเว็บไซต์', 'en' => 'Show this announcement on the website.'],
            'admin.settings.help.announcement_inactive' => ['th' => 'ซ่อนประกาศนี้โดยยังเก็บข้อความไว้', 'en' => 'Hide this announcement while keeping its text.'],
            'admin.settings.announcement_placeholder' => ['th' => 'กรอกข้อความประกาศ (รองรับ emoji 🎯✨)', 'en' => 'Enter announcement text (supports emoji 🎯✨)'],
            'admin.settings.announcement_tip' => ['th' => 'เคล็ดลับ: ใช้ emoji เพื่อความสวยงาม', 'en' => 'Tip: Use emojis for better visual appeal'],
            'admin.settings.status' => ['th' => 'สถานะ', 'en' => 'Status'],
            'admin.settings.inactive' => ['th' => 'ปิดใช้งาน', 'en' => 'Inactive'],
            'admin.settings.announcement_scroll_tip' => ['th' => 'ข้อความนี้จะเลื่อนอัตโนมัติในหน้าซื้อสินค้า', 'en' => 'This text will scroll automatically on the buy page'],
            'admin.settings.save_announcement' => ['th' => 'บันทึกประกาศ', 'en' => 'Save Announcement'],
            'common.clear' => ['th' => 'ล้างข้อมูล', 'en' => 'Clear'],
            'admin.settings.announcement_clear_confirm' => ['th' => 'คุณแน่ใจหรือไม่ว่าต้องการล้างข้อความประกาศ?', 'en' => 'Are you sure you want to clear the announcement text?'],
            'admin.settings.success.general' => ['th' => 'บันทึกการตั้งค่าทั่วไปเรียบร้อยแล้ว', 'en' => 'General settings saved successfully!'],
            'admin.settings.success.announcement' => ['th' => 'บันทึกข้อความประกาศเรียบร้อยแล้ว', 'en' => 'Announcement text saved successfully!'],
            'admin.products.variant_label' => ['en' => 'Variant', 'th' => 'รูปแบบ'],
            'admin.products.modal.product_label' => ['en' => 'Product:', 'th' => 'สินค้า:'],
            'admin.products.modal.variant_label' => ['en' => 'Variant:', 'th' => 'รูปแบบ:'],
            'account.confirm_new_password' => ['en' => 'Confirm New Password', 'th' => 'ยืนยันรหัสผ่านใหม่'],
            'account.confirm_new_password_placeholder' => ['en' => 'Re-enter your new password', 'th' => 'กรอกรหัสผ่านใหม่อีกครั้ง'],
            'account.current_password' => ['en' => 'Current Password', 'th' => 'รหัสผ่านปัจจุบัน'],
            'account.current_password_hint' => ['en' => 'Required to save changes', 'th' => 'จำเป็นต้องระบุเพื่อบันทึกการเปลี่ยนแปลง'],
            'account.email' => ['en' => 'Email Address', 'th' => 'อีเมล'],
            'account.new_password' => ['en' => 'New Password', 'th' => 'รหัสผ่านใหม่'],
            'account.new_password_placeholder' => ['en' => 'Leave blank to keep current', 'th' => 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน'],
            'account.save_changes' => ['en' => 'Save Changes', 'th' => 'บันทึกการเปลี่ยนแปลง'],
            'account.username' => ['en' => 'Username', 'th' => 'ชื่อผู้ใช้'],
            'admin.binance.clear' => ['en' => 'Clear Filters', 'th' => 'ล้างการกรอง'],
            'admin.binance.empty' => ['en' => 'No Binance deposits found.', 'th' => 'ไม่พบข้อมูลการเติมเงิน Binance'],
            'admin.binance.heading' => ['en' => 'Binance Pay Deposits', 'th' => 'รายการเติมเงิน Binance'],
            'admin.binance.search_placeholder' => ['en' => 'Search by User, TxID or Network...', 'th' => 'ค้นหาโดยผู้ใช้, TxID หรือเครือข่าย...'],
            'admin.binance.stats.total_records' => ['en' => 'Total Transactions', 'th' => 'จำนวนรายการทั้งหมด'],
            'admin.binance.stats.total_thb' => ['en' => 'Total THB Received', 'th' => 'ยอดเงินรวม (บาท)'],
            'admin.binance.stats.total_usdt' => ['en' => 'Total USDT Received', 'th' => 'ยอดเงินรวม (USDT)'],
            'admin.binance.table.date' => ['en' => 'Deposit Date', 'th' => 'วันที่เติมเงิน'],
            'admin.binance.table.network' => ['en' => 'Network', 'th' => 'เครือข่าย'],
            'admin.binance.table.rate' => ['en' => 'Rate (USDT/THB)', 'th' => 'เรทแลกเปลี่ยน'],
            'admin.binance.table.thb' => ['en' => 'Amount (THB)', 'th' => 'ยอดเงิน (บาท)'],
            'admin.binance.table.txid' => ['en' => 'Transaction ID', 'th' => 'TxID'],
            'admin.binance.table.user' => ['en' => 'User', 'th' => 'ผู้ใช้'],
            'admin.binance.title' => ['en' => 'Binance Deposits', 'th' => 'การเติมเงิน Binance'],
            'admin.categories.empty' => ['en' => 'No categories found.', 'th' => 'ไม่พบหมวดหมู่'],
            'admin.categories.heading' => ['en' => 'Category Management', 'th' => 'จัดการหมวดหมู่'],
            'admin.categories.help.desc' => ['en' => 'Manage product categories and their download links.', 'th' => 'จัดการหมวดหมู่สินค้าและลิงก์ดาวน์โหลด'],
            'admin.categories.help.title' => ['en' => 'Categories', 'th' => 'หมวดหมู่'],
            'admin.categories.table.name' => ['en' => 'Category Name', 'th' => 'ชื่อหมวดหมู่'],
            'admin.categories.table.url' => ['en' => 'Global Download URL', 'th' => 'ลิงก์ดาวน์โหลดหลัก'],
            'admin.categories.title' => ['en' => 'Manage Categories', 'th' => 'จัดการหมวดหมู่'],
            'admin.categories.url_placeholder' => ['en' => 'https://...', 'th' => 'https://...'],
            'admin.codes.generate.btn' => ['en' => 'Generate Code', 'th' => 'สร้างโค้ด'],
            'admin.codes.generate.help' => ['en' => 'Share this code with users to add balance.', 'th' => 'ส่งโค้ดนี้ให้ผู้ใช้เพื่อเพิ่มยอดเงิน'],
            'admin.codes.generate.nominal' => ['en' => 'Amount (Value)', 'th' => 'จำนวนเงิน (มูลค่า)'],
            'admin.codes.generate.result' => ['en' => 'Generated Code:', 'th' => 'โค้ดที่สร้างขึ้น:'],
            'admin.codes.generate.title' => ['en' => 'Generate Redeem Code', 'th' => 'สร้างโค้ดเติมเงิน'],
            'admin.codes.heading' => ['en' => 'Redeem Code Management', 'th' => 'จัดการโค้ดเติมเงิน'],
            'admin.codes.recent.empty' => ['en' => 'No codes generated yet.', 'th' => 'ยังไม่มีการสร้างโค้ด'],
            'admin.codes.recent.title' => ['en' => 'Recent Codes', 'th' => 'โค้ดล่าสุด'],
            'admin.codes.redeem.btn' => ['en' => 'Redeem for Current User', 'th' => 'เติมเข้าบัญชีตนเอง'],
            'admin.codes.redeem.code' => ['en' => 'Redeem Code', 'th' => 'โค้ดเติมเงิน'],
            'admin.codes.redeem.title' => ['en' => 'Self Redeem', 'th' => 'เติมเงินด้วยตนเอง'],
            'admin.codes.redeem.warning' => ['en' => 'Using a code will add balance to your admin account.', 'th' => 'การเติมโค้ดจะเพิ่มยอดเงินเข้าสู่บัญชีแอดมินของคุณ'],
            'admin.codes.status.unused' => ['en' => 'Available', 'th' => 'ยังไม่ได้ใช้'],
            'admin.codes.status.used' => ['en' => 'Used', 'th' => 'ใช้แล้ว'],
            'admin.codes.table.created' => ['en' => 'Created At', 'th' => 'สร้างเมื่อ'],
            'admin.codes.table.used_by' => ['en' => 'Used By', 'th' => 'ใช้โดย'],
            'admin.codes.title' => ['en' => 'Manage Codes', 'th' => 'จัดการโค้ด'],
            'admin.keys.all_products' => ['en' => 'All Products', 'th' => 'สินค้าทั้งหมด'],
            'admin.keys.bulk_delete' => ['en' => 'Delete Selected', 'th' => 'ลบที่เลือก'],
            'admin.keys.confirm_bulk_delete' => ['en' => 'Delete {count} selected key(s)? Keys with purchase history will remain in customer history.', 'th' => 'ลบคีย์ที่เลือก {count} รายการหรือไม่? คีย์ที่มีประวัติซื้อจะยังคงอยู่ในประวัติลูกค้า'],
            'admin.keys.confirm_delete_btn' => ['en' => 'Confirm Delete', 'th' => 'ยืนยันการลบ'],
            'admin.keys.copied' => ['en' => 'Key copied', 'th' => 'คัดลอกคีย์แล้ว'],
            'admin.keys.delete_available_warning' => ['en' => 'This key has no purchase history. It will be removed from live stock and an Admin audit snapshot will be kept.', 'th' => 'คีย์นี้ยังไม่มีประวัติการซื้อ ระบบจะลบออกจากสต็อกจริง และเก็บข้อมูลสำหรับตรวจสอบของแอดมินไว้'],
            'admin.keys.delete_reason' => ['en' => 'Reason', 'th' => 'เหตุผลในการลบ'],
            'admin.keys.delete_sold_warning' => ['en' => 'This key has purchase history. It will be removed only from the Admin inventory view; the live key row and customer purchase history will be preserved.', 'th' => 'คีย์นี้มีประวัติการซื้อ ระบบจะลบออกจากหน้าคลังของแอดมินเท่านั้น โดยยังเก็บข้อมูลคีย์และประวัติการซื้อของลูกค้าไว้ครบ'],
            'admin.keys.delete_title' => ['en' => 'Delete Key', 'th' => 'ลบคีย์'],
            'admin.keys.deleted_hint' => ['en' => 'Deleted records are audit snapshots and do not return to live stock.', 'th' => 'รายการที่ลบเป็นข้อมูลตรวจสอบย้อนหลัง และจะไม่กลับเข้าไปในสต็อก'],
            'admin.keys.details_title' => ['en' => 'Key Details', 'th' => 'รายละเอียดคีย์'],
            'admin.keys.empty' => ['en' => 'No license keys found.', 'th' => 'ไม่พบคีย์สินค้า'],
            'admin.keys.error.already_deleted' => ['en' => 'This key was already removed from the Admin inventory.', 'th' => 'คีย์นี้ถูกลบออกจากคลังของแอดมินไปแล้ว'],
            'admin.keys.error.bulk_limit' => ['en' => 'You can delete up to 100 selected keys at one time.', 'th' => 'ลบคีย์ที่เลือกได้สูงสุด 100 รายการต่อครั้ง'],
            'admin.keys.error.delete_failed' => ['en' => 'Unable to delete this key safely. No purchase history was removed.', 'th' => 'ไม่สามารถลบคีย์ได้อย่างปลอดภัย โดยไม่มีประวัติการซื้อใดถูกลบ'],
            'admin.keys.error.journal' => ['en' => 'The key deletion audit journal is unavailable. Deletion is disabled until it is ready.', 'th' => 'ระบบบันทึกข้อมูลคีย์ที่ลบยังไม่พร้อม จึงปิดการลบไว้ชั่วคราวเพื่อป้องกันข้อมูลสูญหาย'],
            'admin.keys.error.not_found' => ['en' => 'Key not found.', 'th' => 'ไม่พบคีย์นี้'],
            'admin.keys.filter_product' => ['en' => 'Product', 'th' => 'สินค้า'],
            'admin.keys.filter_status' => ['en' => 'Status', 'th' => 'สถานะ'],
            'admin.keys.heading' => ['en' => 'License Key Inventory', 'th' => 'คลังคีย์สินค้า'],
            'admin.keys.reason.bulk_delete' => ['en' => 'Bulk delete', 'th' => 'ลบหลายรายการ'],
            'admin.keys.reason.no_longer_needed' => ['en' => 'No longer needed', 'th' => 'ไม่ต้องการใช้งานแล้ว'],
            'admin.keys.reason.other' => ['en' => 'Other', 'th' => 'อื่นๆ'],
            'admin.keys.reason.unusable' => ['en' => 'Key is unusable', 'th' => 'คีย์ใช้งานไม่ได้'],
            'admin.keys.reason.wrong_entry' => ['en' => 'Entered by mistake', 'th' => 'ใส่คีย์ผิด'],
            'admin.keys.results' => ['en' => 'Showing {from}-{to} of {total} keys', 'th' => 'แสดง {from}-{to} จากทั้งหมด {total} คีย์'],
            'admin.keys.search_label' => ['en' => 'Search', 'th' => 'ค้นหา'],
            'admin.keys.search_placeholder' => ['en' => 'Key, product, username, email, user ID, transaction or API order...', 'th' => 'คีย์, สินค้า, ชื่อผู้ใช้, อีเมล, User ID, Transaction หรือ API Order...'],
            'admin.keys.select_at_least_one' => ['en' => 'Please select at least one key.', 'th' => 'กรุณาเลือกอย่างน้อย 1 รายการ'],
            'admin.keys.selected' => ['en' => 'selected', 'th' => 'รายการที่เลือก'],
            'admin.keys.source.inventory' => ['en' => 'Inventory', 'th' => 'สต็อก'],
            'admin.keys.source.legacy' => ['en' => 'Legacy / Unknown', 'th' => 'ข้อมูลเก่า / ไม่ทราบที่มา'],
            'admin.keys.source.local' => ['en' => 'Website purchase', 'th' => 'ซื้อผ่านเว็บไซต์'],
            'admin.keys.source.store_api' => ['en' => 'Store API', 'th' => 'Store API'],
            'admin.keys.status.available' => ['en' => 'Available', 'th' => 'พร้อมใช้งาน'],
            'admin.keys.status.deleted' => ['en' => 'Deleted', 'th' => 'ลบแล้ว'],
            'admin.keys.status.sold' => ['en' => 'Sold', 'th' => 'ขายแล้ว'],
            'admin.keys.subtitle' => ['en' => 'View, search, copy and safely remove local license keys. Prices and key creation are managed from Products.', 'th' => 'สำหรับดู ค้นหา คัดลอก และลบคีย์อย่างปลอดภัยเท่านั้น การเพิ่มคีย์และจัดการราคาให้ทำจากหน้าสินค้า'],
            'admin.keys.success.available_deleted' => ['en' => 'Available key removed from live stock. An Admin audit snapshot was kept.', 'th' => 'ลบคีย์ที่ยังไม่ขายออกจากสต็อกแล้ว และเก็บข้อมูลตรวจสอบของแอดมินไว้'],
            'admin.keys.success.bulk' => ['en' => 'Removed {deleted} available key(s); archived {archived} history-linked key(s) without affecting customer history.', 'th' => 'ลบคีย์ที่ยังไม่ขาย {deleted} รายการ และซ่อนคีย์ที่มีประวัติการซื้อ {archived} รายการ โดยไม่กระทบประวัติลูกค้า'],
            'admin.keys.success.bulk_failed' => ['en' => '{failed} item(s) could not be removed.', 'th' => 'มี {failed} รายการที่ไม่สามารถลบได้'],
            'admin.keys.success.sold_archived' => ['en' => 'Sold key removed from the Admin inventory view. Customer purchase history was preserved.', 'th' => 'ลบคีย์ที่ขายแล้วออกจากหน้าคลังแอดมิน โดยประวัติการซื้อของลูกค้ายังคงอยู่ครบ'],
            'admin.keys.summary.available' => ['en' => 'Available', 'th' => 'พร้อมใช้'],
            'admin.keys.summary.deleted' => ['en' => 'Deleted', 'th' => 'ลบแล้ว'],
            'admin.keys.summary.sold' => ['en' => 'Sold', 'th' => 'ขายแล้ว'],
            'admin.keys.table.added_at' => ['en' => 'Added', 'th' => 'วันที่ลง'],
            'admin.keys.table.api_client' => ['en' => 'API Client', 'th' => 'API Client'],
            'admin.keys.table.buyer' => ['en' => 'Buyer', 'th' => 'ผู้ซื้อ'],
            'admin.keys.table.buyer_id' => ['en' => 'Buyer ID', 'th' => 'User ID ผู้ซื้อ'],
            'admin.keys.table.deleted_at' => ['en' => 'Deleted', 'th' => 'วันที่ลบ'],
            'admin.keys.table.external_ref' => ['en' => 'External Ref', 'th' => 'External Ref'],
            'admin.keys.table.key_code' => ['en' => 'Key Code', 'th' => 'รหัสคีย์'],
            'admin.keys.table.paid' => ['en' => 'Paid', 'th' => 'ยอดที่จ่าย'],
            'admin.keys.table.product' => ['en' => 'Product', 'th' => 'สินค้า'],
            'admin.keys.table.sold_at' => ['en' => 'Sold', 'th' => 'วันที่ขาย'],
            'admin.keys.table.source' => ['en' => 'Sale Source', 'th' => 'แหล่งที่ขาย'],
            'admin.keys.table.store_order' => ['en' => 'Store API Order', 'th' => 'Store API Order'],
            'admin.keys.table.transaction' => ['en' => 'Transaction', 'th' => 'Transaction'],
            'admin.keys.title' => ['en' => 'Manage Keys', 'th' => 'จัดการคีย์'],
            'admin.keys.view_details' => ['en' => 'Details', 'th' => 'รายละเอียด'],
            'admin.products.add_btn' => ['en' => 'Add Product', 'th' => 'เพิ่มสินค้า'],
            'admin.products.add_keys' => ['en' => 'Add Keys', 'th' => 'เพิ่มคีย์'],
            'admin.products.add_modal_title' => ['en' => 'Add New Product', 'th' => 'เพิ่มสินค้าใหม่'],
            'admin.products.add_variant_btn' => ['en' => 'Add Variant', 'th' => 'เพิ่มรูปแบบ'],
            'admin.products.added_on' => ['en' => 'Added On', 'th' => 'เพิ่มเมื่อ'],
            'admin.products.available_keys' => ['en' => 'Available', 'th' => 'คีย์ว่าง'],
            'admin.products.category_hint' => ['en' => 'Optional category for grouping', 'th' => 'หมวดหมู่สินค้า (ไม่บังคับ)'],
            'admin.products.cost_label' => ['en' => 'Cost (Modal)', 'th' => 'ต้นทุน (ทุนคีย์)'],
            'admin.products.download_url_hint' => ['en' => 'Specific link for this product', 'th' => 'ลิงก์เฉพาะสำหรับสินค้านี้'],
            'admin.products.duration_label' => ['en' => 'Duration (Days)', 'th' => 'ระยะเวลา (วัน)'],
            'admin.products.duration_placeholder' => ['en' => 'e.g., 30', 'th' => 'เช่น 30'],
            'admin.products.edit_modal_title' => ['en' => 'Edit Product', 'th' => 'แก้ไขสินค้า'],
            'admin.products.empty' => ['en' => 'No products found.', 'th' => 'ไม่พบสินค้า'],
            'admin.products.form_category' => ['en' => 'Category', 'th' => 'หมวดหมู่'],
            'admin.products.form_desc' => ['en' => 'Description', 'th' => 'รายละเอียด'],
            'admin.products.form_dl_url' => ['en' => 'Download Link', 'th' => 'ลิงก์ดาวน์โหลด'],
            'admin.products.form_image' => ['en' => 'Product Image', 'th' => 'รูปภาพสินค้า'],
            'admin.products.form_name' => ['en' => 'Product Name', 'th' => 'ชื่อสินค้า'],
            'admin.products.form_save' => ['en' => 'Save Product', 'th' => 'บันทึกสินค้า'],
            'admin.products.image_add_hint' => ['en' => 'Upload a product image', 'th' => 'อัปโหลดรูปภาพสินค้า'],
            'admin.products.image_edit_hint' => ['en' => 'Leave empty to keep current', 'th' => 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน'],
            'admin.products.initial_variants' => ['en' => 'Initial Variants', 'th' => 'รูปแบบเริ่มต้น'],
            'admin.products.keys_hint' => ['en' => 'Paste keys here (one per line)', 'th' => 'วางคีย์ที่นี่ (บรรทัดละ 1 คีย์)'],
            'admin.products.modal_edit_variant_title' => ['en' => 'Edit Variant', 'th' => 'แก้ไขรูปแบบสินค้า'],
            'admin.products.no_variants' => ['en' => 'No variants defined.', 'th' => 'ยังไม่ได้กำหนดรูปแบบ'],
            'admin.products.price_reseller' => ['en' => 'Reseller Price', 'th' => 'ราคาตัวแทน'],
            'admin.products.price_user' => ['en' => 'User Price', 'th' => 'ราคาผู้ใช้'],
            'admin.products.status.active' => ['en' => 'Active', 'th' => 'เปิดใช้งาน'],
            'admin.products.status.inactive' => ['en' => 'Inactive', 'th' => 'ปิดใช้งาน'],
            'admin.products.title' => ['en' => 'Manage Products', 'th' => 'จัดการสินค้า'],
            'admin.products.total_keys' => ['en' => 'Total Keys', 'th' => 'คีย์ทั้งหมด'],
            'admin.products.update_variant_btn' => ['en' => 'Update Variant', 'th' => 'อัปเดตรูปแบบ'],
            'admin.products.variant.add_btn' => ['en' => 'Add Variant', 'th' => 'เพิ่มรูปแบบ'],
            'admin.products.variant.add_keys' => ['en' => 'Add Keys', 'th' => 'เพิ่มคีย์'],
            'admin.products.variant.available_keys' => ['en' => 'Available Keys', 'th' => 'คีย์ที่พร้อมขาย'],
            'admin.products.variant.total_keys' => ['en' => 'Total Keys', 'th' => 'จำนวนคีย์ทั้งหมด'],
            'admin.products.variants' => ['en' => 'Variants', 'th' => 'รูปแบบทั้งหมด'],
            'admin.profit.apply' => ['en' => 'Apply Filter', 'th' => 'ใช้ตัวกรอง'],
            'admin.profit.by_product' => ['en' => 'Profit by Product', 'th' => 'กำไรตามรายสินค้า'],
            'admin.profit.cost_modal' => ['en' => 'Total Cost', 'th' => 'ต้นทุนรวม'],
            'admin.profit.empty_purchases' => ['en' => 'No purchases found in this range.', 'th' => 'ไม่พบข้อมูลการซื้อในช่วงนี้'],
            'admin.profit.empty_range' => ['en' => 'No data for selected range.', 'th' => 'ไม่พบข้อมูลในช่วงที่เลือก'],
            'admin.profit.end' => ['en' => 'End Date', 'th' => 'วันที่สิ้นสุด'],
            'admin.profit.heading' => ['en' => 'Profit Report', 'th' => 'รายงานผลกำไร'],
            'admin.profit.includes_reseller' => ['en' => '(All sales channels)', 'th' => '(รวมทุกช่องทางการขาย)'],
            'admin.profit.profit' => ['en' => 'Net Profit', 'th' => 'กำไรสุทธิ'],
            'admin.profit.recent_purchases' => ['en' => 'Recent Profit Breakdown', 'th' => 'แจกแจงกำไรล่าสุด'],
            'admin.profit.revenue' => ['en' => 'Total Revenue', 'th' => 'รายได้รวม'],
            'admin.profit.start' => ['en' => 'Start Date', 'th' => 'วันที่เริ่มต้น'],
            'admin.profit.table.sell' => ['en' => 'Sell Price', 'th' => 'ราคาขาย'],
            'admin.profit.table.sold' => ['en' => 'Qty Sold', 'th' => 'จำนวนที่ขาย'],
            'admin.profit.title' => ['en' => 'Profit Analysis', 'th' => 'วิเคราะห์กำไร'],
            'admin.profit.unknown_cost_warning' => ['en' => '{count} order(s) still have unknown cost. Total cost and net profit are incomplete until cost data is reconciled.', 'th' => 'มี {count} รายการที่ยังไม่ทราบต้นทุน ต้นทุนรวมและกำไรสุทธิจะยังไม่ครบจนกว่าข้อมูลต้นทุนจะถูกซิงค์ครบ'],
            'admin.profit.unknown_cost_short' => ['en' => 'Unknown cost', 'th' => 'ไม่ทราบต้นทุน'],
            'admin.profit.top_500' => ['en' => '(Summary covers the full range; details show the latest 500)', 'th' => '(ยอดสรุปรวมทั้งช่วง รายละเอียดแสดง 500 รายการล่าสุด)'],
            'admin.reseller_prices.custom_for' => ['en' => 'Custom prices for {username}', 'th' => 'ราคาพิเศษสำหรับ {username}'],
            'admin.reseller_prices.heading' => ['en' => 'Reseller Specific Pricing', 'th' => 'จัดการราคาพิเศษตัวแทน'],
            'admin.reseller_prices.help_default' => ['en' => 'Set to 0 or leave empty to use default reseller price.', 'th' => 'ใส่ 0 หรือเว้นว่างเพื่อใช้ราคาตัวแทนปกติ'],
            'admin.reseller_prices.placeholder_default' => ['en' => 'Default price', 'th' => 'ราคาปกติ'],
            'admin.reseller_prices.placeholder_duration' => ['en' => 'Duration (Days)', 'th' => 'ระยะเวลา (วัน)'],
            'admin.reseller_prices.save_btn' => ['en' => 'Save Custom Prices', 'th' => 'บันทึกราคาพิเศษ'],
            'admin.reseller_prices.select_default' => ['en' => '-- Select Reseller --', 'th' => '-- เลือกตัวแทน --'],
            'admin.reseller_prices.select_label' => ['en' => 'Select Reseller Account', 'th' => 'เลือกบัญชีตัวแทน'],
            'admin.reseller_prices.table.custom_price' => ['en' => 'Custom Price', 'th' => 'ราคาพิเศษ'],
            'admin.reseller_prices.table.default_price' => ['en' => 'Base Reseller Price', 'th' => 'ราคาพื้นฐานตัวแทน'],
            'admin.reseller_prices.table.duration' => ['en' => 'Duration', 'th' => 'ระยะเวลา'],
            'admin.reseller_prices.table.product' => ['en' => 'Product Name', 'th' => 'ชื่อสินค้า'],
            'admin.reseller_prices.title' => ['en' => 'Special Pricing', 'th' => 'ราคาพิเศษ'],
            'admin.reseller_prices.error.invalid_request' => ['en' => 'The reseller pricing request is invalid.', 'th' => 'คำขอตั้งราคาตัวแทนไม่ถูกต้อง'],
            'admin.reseller_prices.error.prepare' => ['en' => 'The reseller pricing table could not be prepared.', 'th' => 'ไม่สามารถเตรียมตารางราคาตัวแทนได้'],
            'admin.reseller_prices.error.save' => ['en' => 'Custom reseller prices could not be saved.', 'th' => 'ไม่สามารถบันทึกราคาพิเศษของตัวแทนได้'],
            'admin.reseller_prices.error.invalid_account' => ['en' => 'The selected account is not a reseller.', 'th' => 'บัญชีที่เลือกไม่ใช่บัญชีตัวแทน'],
            'admin.reseller_prices.success.saved' => ['en' => '{count} custom price(s) were saved.', 'th' => 'บันทึกราคาพิเศษแล้ว {count} รายการ'],
            'admin.reseller_prices.success.cleared' => ['en' => 'Custom prices were cleared. Default reseller prices will be used.', 'th' => 'ล้างราคาพิเศษแล้ว ระบบจะใช้ราคาตัวแทนปกติ'],
            'admin.resellers.add_btn' => ['en' => 'Add Reseller', 'th' => 'เพิ่มตัวแทน'],
            'admin.resellers.empty' => ['en' => 'No resellers found.', 'th' => 'ไม่พบข้อมูลตัวแทน'],
            'admin.resellers.heading' => ['en' => 'Reseller Management', 'th' => 'จัดการตัวแทน'],
            'admin.resellers.modal.add_balance' => ['en' => 'Add Balance', 'th' => 'เพิ่มยอดเงิน'],
            'admin.resellers.modal.add_title' => ['en' => 'Create Reseller Account', 'th' => 'สร้างบัญชีตัวแทน'],
            'admin.resellers.modal.deduct_balance' => ['en' => 'Deduct Balance', 'th' => 'หักยอดเงิน'],
            'admin.resellers.modal.operation' => ['en' => 'Operation', 'th' => 'ดำเนินการ'],
            'admin.resellers.modal.reseller_label' => ['en' => 'Reseller:', 'th' => 'ตัวแทน:'],
            'admin.resellers.modal.update_balance' => ['en' => 'Update Balance', 'th' => 'ปรับปรุงยอดเงิน'],
            'admin.resellers.title' => ['en' => 'Manage Resellers', 'th' => 'จัดการตัวแทน'],
            'admin.settings.easyslip_api_hint' => ['en' => 'Get your API Key from easyslip.com', 'th' => 'รับ API Key ได้ที่ easyslip.com'],
            'admin.settings.easyslip_api_placeholder' => ['en' => 'Your EasySlip API Key', 'th' => 'รหัส EasySlip API'],
            'admin.settings.new_password_hint' => ['en' => 'Leave blank if not changing', 'th' => 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน'],
            'admin.settings.placeholder.api_key' => ['en' => 'Enter API Key', 'th' => 'กรอก API Key'],
            'admin.settings.placeholder.confirm_password' => ['en' => 'Confirm Password', 'th' => 'ยืนยันรหัสผ่าน'],
            'admin.settings.placeholder.leave_blank' => ['en' => 'Leave blank to keep current', 'th' => 'เว้นว่างไว้'],
            'admin.settings.placeholder.secret_key' => ['en' => 'Enter Secret Key', 'th' => 'กรอก Secret Key'],
            'admin.settings.placeholder.wallet' => ['en' => 'Enter Wallet Address', 'th' => 'กรอกที่อยู่กระเป๋า'],
            'admin.settings.receiver_name_en_hint' => ['en' => 'Name on slip (English)', 'th' => 'ชื่อในสลิป (อังกฤษ)'],
            'admin.settings.receiver_name_th_hint' => ['en' => 'Name on slip (Thai)', 'th' => 'ชื่อในสลิป (ไทย)'],
            'admin.transactions.all_users' => ['en' => 'All Users', 'th' => 'ผู้ใช้ทั้งหมด'],
            'admin.transactions.empty' => ['en' => 'No transactions found.', 'th' => 'ไม่พบข้อมูลรายการ'],
            'admin.transactions.filter_user' => ['en' => 'Filter by User', 'th' => 'กรองตามผู้ใช้'],
            'admin.transactions.heading' => ['en' => 'Transaction Logs', 'th' => 'รายการย้อนหลัง'],
            'admin.transactions.latest' => ['en' => 'Latest 100 Transactions', 'th' => '100 รายการล่าสุด'],
            'admin.transactions.modal.amount' => ['en' => 'Amount Paid:', 'th' => 'ยอดที่ชำระ:'],
            'admin.transactions.modal.close' => ['en' => 'Close', 'th' => 'ปิด'],
            'admin.transactions.modal.copy' => ['en' => 'Copy All', 'th' => 'คัดลอกทั้งหมด'],
            'admin.transactions.modal.date' => ['en' => 'Date:', 'th' => 'วันที่:'],
            'admin.transactions.modal.download' => ['en' => 'Download', 'th' => 'ดาวน์โหลด'],
            'admin.transactions.modal.keys' => ['en' => 'License Keys:', 'th' => 'คีย์สินค้า:'],
            'admin.transactions.modal.product' => ['en' => 'Product:', 'th' => 'สินค้า:'],
            'admin.transactions.modal.title' => ['en' => 'Transaction Details', 'th' => 'รายละเอียดรายการ'],
            'admin.transactions.more_codes' => ['en' => 'more keys', 'th' => 'คีย์เพิ่มเติม'],
            'admin.transactions.search_label' => ['en' => 'Search Transaction/Key', 'th' => 'ค้นหารายการ/คีย์'],
            'admin.transactions.search_placeholder' => ['en' => 'Enter key or search term...', 'th' => 'ระบุคีย์หรือสิ่งที่ค้นหา...'],
            'admin.transactions.title' => ['en' => 'Transactions', 'th' => 'ประวัติรายการ'],
            'admin.users.ban_title' => ['en' => 'Ban User', 'th' => 'แบนผู้ใช้'],
            'admin.users.delete_title' => ['en' => 'Delete User', 'th' => 'ลบผู้ใช้'],
            'admin.users.empty' => ['en' => 'No users found.', 'th' => 'ไม่พบข้อมูลผู้ใช้'],
            'admin.users.status.active' => ['en' => 'Active', 'th' => 'ปกติ'],
            'admin.users.status.banned' => ['en' => 'Banned', 'th' => 'ถูกแบน'],
            'admin.users.table.balance' => ['en' => 'Balance', 'th' => 'ยอดคงเหลือ'],
            'admin.users.table.email' => ['en' => 'Email', 'th' => 'อีเมล'],
            'admin.users.table.id' => ['en' => 'ID', 'th' => 'ลำดับ'],
            'admin.users.table.joined' => ['en' => 'Joined Date', 'th' => 'วันที่สมัคร'],
            'admin.users.table.status' => ['en' => 'Status', 'th' => 'สถานะ'],
            'admin.users.table.username' => ['en' => 'Username', 'th' => 'ชื่อผู้ใช้'],
            'admin.users.title' => ['en' => 'Manage Users', 'th' => 'จัดการผู้ใช้'],
            'admin.users.unban_title' => ['en' => 'Unban User', 'th' => 'ปลดแบนผู้ใช้'],
            'buy.balance_after' => ['en' => 'Balance After', 'th' => 'ยอดเงินหลังซื้อ'],
            'buy.confirm' => ['en' => 'Confirm Purchase', 'th' => 'ยืนยันการซื้อ'],
            'buy.confirm_purchase_title' => ['en' => 'Confirm Purchase', 'th' => 'ยืนยันการสั่งซื้อ'],
            'buy.price_per_item' => ['en' => 'Price per item', 'th' => 'ราคาต่อชิ้น'],
            'buy.quantity' => ['en' => 'Quantity', 'th' => 'จำนวน'],
            'buy.total_price' => ['en' => 'Total Price', 'th' => 'ราคารวม'],
            'category' => ['en' => 'Category', 'th' => 'หมวดหมู่'],
            'common.action' => ['en' => 'Action', 'th' => 'ดำเนินการ'],
            'common.activate' => ['en' => 'Activate', 'th' => 'เปิดใช้งาน'],
            'common.add' => ['en' => 'Add', 'th' => 'เพิ่ม'],
            'common.amount' => ['en' => 'Amount', 'th' => 'จำนวนเงิน'],
            'common.cancel' => ['en' => 'Cancel', 'th' => 'ยกเลิก'],
            'common.clear_filters' => ['en' => 'Clear Filters', 'th' => 'ล้างการค้นหา'],
            'common.confirm' => ['en' => 'Confirm', 'th' => 'ยืนยัน'],
            'common.date' => ['en' => 'Date', 'th' => 'วันที่'],
            'common.date_format' => ['en' => 'Y-m-d H:i', 'th' => 'd/m/Y H:i'],
            'common.delete' => ['en' => 'Delete', 'th' => 'ลบ'],
            'common.edit' => ['en' => 'Edit', 'th' => 'แก้ไข'],
            'common.filter' => ['en' => 'Filter', 'th' => 'กรอง'],
            'common.key_code' => ['en' => 'License Key', 'th' => 'รหัสคีย์'],
            'common.no_products_found' => ['en' => 'No products found.', 'th' => 'ไม่พบสินค้า'],
            'common.password' => ['en' => 'Password', 'th' => 'รหัสผ่าน'],
            'common.pause' => ['en' => 'Pause', 'th' => 'หยุดชั่วคราว'],
            'common.placeholder.code' => ['en' => 'Enter Code', 'th' => 'ระบุโค้ด'],
            'common.placeholder.nominal' => ['en' => 'Enter Amount', 'th' => 'ระบุจำนวนเงิน'],
            'common.placeholder.url' => ['en' => 'https://...', 'th' => 'https://...'],
            'common.preview' => ['en' => 'Preview', 'th' => 'ตัวอย่าง'],
            'common.product_name' => ['en' => 'Product Name', 'th' => 'ชื่อสินค้า'],
            'common.reseller' => ['en' => 'Reseller', 'th' => 'ตัวแทน'],
            'common.save' => ['en' => 'Save', 'th' => 'บันทึก'],
            'common.search' => ['en' => 'Search', 'th' => 'ค้นหา'],
            'common.sold_out' => ['en' => 'Sold Out', 'th' => 'สินค้าหมด'],
            'common.update' => ['en' => 'Update', 'th' => 'อัปเดต'],
            'common.user' => ['en' => 'User', 'th' => 'ผู้ใช้'],
            'dashboard.categories' => ['en' => 'Categories', 'th' => 'หมวดหมู่'],
            'dashboard.clear_filter' => ['en' => 'Clear Filter', 'th' => 'ล้างการกรอง'],
            'dashboard.keys_count' => ['en' => 'Keys', 'th' => 'คีย์'],
            'dashboard.search.button' => ['en' => 'Search', 'th' => 'ค้นหา'],
            'dashboard.table.date' => ['en' => 'Date', 'th' => 'วันที่'],
            'dashboard.table.duration' => ['en' => 'Duration', 'th' => 'ระยะเวลา'],
            'dashboard.table.key' => ['en' => 'Key', 'th' => 'คีย์'],
            'dashboard.table.paid' => ['en' => 'Paid', 'th' => 'ที่ชำระ'],
            'dashboard.table.product' => ['en' => 'Product', 'th' => 'สินค้า'],
            'deposit.add_balance' => ['en' => 'Add Balance', 'th' => 'เติมเงินเข้าบัญชี'],
            'deposit.amount_placeholder' => ['en' => 'Enter amount', 'th' => 'ระบุจำนวนเงิน'],
            'deposit.angpao.button' => ['en' => 'Redeem Gift', 'th' => 'แลกของขวัญ'],
            'deposit.angpao.title' => ['en' => 'TrueMoney Gift Voucher', 'th' => 'ทรูมันนี่วอลเล็ท (ซองอั่งเปา)'],
            'deposit.angpao.warning' => ['en' => 'Amount will be added instantly after verification.', 'th' => 'ยอดเงินจะถูกเพิ่มทันทีหลังจากการตรวจสอบ'],
            'deposit.binance.important' => ['en' => 'IMPORTANT:', 'th' => 'สำคัญ:'],
            'deposit.binance.loss_warning' => ['en' => 'Sending to wrong network will result in permanent loss.', 'th' => 'การส่งผิดเครือข่ายจะทำให้เงินหายถาวร'],
            'deposit.binance.network' => ['en' => 'Network: TRC20 (Tron)', 'th' => 'เครือข่าย: TRC20 (Tron)'],
            'deposit.binance.network_warning' => ['en' => 'ONLY TRC20 (TRON) NETWORK IS SUPPORTED!', 'th' => 'รองรับเฉพาะเครือข่าย TRC20 (TRON) เท่านั้น!'],
            'deposit.binance.support_auto_convert' => ['en' => 'Automatic USDT to THB conversion', 'th' => 'แปลง USDT เป็น THB อัตโนมัติ'],
            'deposit.binance.support_network' => ['en' => 'TRC20 Network only', 'th' => 'เฉพาะเครือข่าย TRC20'],
            'deposit.binance.support_usdt' => ['en' => 'Supports USDT deposits', 'th' => 'รองรับการฝาก USDT'],
            'deposit.binance.support_wait' => ['en' => 'Processing time: 1-5 minutes', 'th' => 'ใช้เวลาดำเนินการ 1-5 นาที'],
            'deposit.binance.title' => ['en' => 'Binance USDT (TRC20)', 'th' => 'Binance USDT (TRC20)'],
            'deposit.binance.txid_placeholder' => ['en' => 'Paste Transaction ID (TxID)', 'th' => 'วางรหัสธุรกรรม (TxID)'],
            'deposit.binance.verify_btn' => ['en' => 'Verify Transaction', 'th' => 'ตรวจสอบธุรกรรม'],
            'deposit.binance.wallet_label' => ['en' => 'Deposit Address (TRC20)', 'th' => 'ที่อยู่สำหรับฝากเงิน (TRC20)'],
            'deposit.error.failed' => ['en' => 'Deposit failed. Please try again.', 'th' => 'การเติมเงินล้มเหลว กรุณาลองใหม่'],
            'deposit.error.general' => ['en' => 'An unexpected error occurred.', 'th' => 'เกิดข้อผิดพลาดที่ไม่คาดคิด'],
            'deposit.error.invalid' => ['en' => 'Invalid deposit data.', 'th' => 'ข้อมูลการเติมเงินไม่ถูกต้อง'],
            'deposit.error.nourl' => ['en' => 'Payment URL not found.', 'th' => 'ไม่พบ URL สำหรับชำระเงิน'],
            'deposit.error.pending' => ['en' => 'You have a pending transaction. Please wait.', 'th' => 'คุณมีรายการที่ค้างอยู่ กรุณารอสักครู่'],
            'deposit.error.txinit' => ['en' => 'Failed to initialize transaction.', 'th' => 'ไม่สามารถสร้างรายการธุรกรรมได้'],
            'deposit.mobile' => ['en' => 'Mobile Number', 'th' => 'เบอร์โทรศัพท์'],
            'deposit.mobile_placeholder' => ['en' => '08x-xxx-xxxx', 'th' => '08x-xxx-xxxx'],
            'deposit.my_balance' => ['en' => 'My Balance:', 'th' => 'ยอดเงินของฉัน:'],
            'deposit.pay_btn' => ['en' => 'Pay Now', 'th' => 'ชำระเงินตอนนี้'],
            'deposit.redeem.btn' => ['en' => 'Redeem Code', 'th' => 'แลกโค้ด'],
            'deposit.redeem.placeholder' => ['en' => 'Enter your code here', 'th' => 'กรอกโค้ดของคุณที่นี่'],
            'deposit.redeem.title' => ['en' => 'Redeem Code', 'th' => 'แลกโค้ดเติมเงิน'],
            'deposit.redeem.warning' => ['en' => 'Each code can only be used once.', 'th' => 'แต่ละโค้ดสามารถใช้ได้เพียงครั้งเดียว'],
            'deposit.slip.account_name_label' => ['en' => 'Account Name:', 'th' => 'ชื่อบัญชี:'],
            'deposit.slip.account_number_label' => ['en' => 'Account Number:', 'th' => 'เลขบัญชี:'],
            'deposit.slip.bank_label' => ['en' => 'Bank:', 'th' => 'ธนาคาร:'],
            'deposit.slip.banking' => ['en' => 'Bank Transfer (Slip Scan)', 'th' => 'โอนผ่านธนาคาร (สแกนสลิป)'],
            'deposit.slip.fee_free' => ['en' => '0% Fee', 'th' => 'ไม่มีค่าธรรมเนียม'],
            'deposit.slip.or' => ['en' => 'OR', 'th' => 'หรือ'],
            'deposit.slip.title' => ['en' => 'Bank Transfer', 'th' => 'โอนเงินธนาคาร'],
            'deposit.slip.upload_btn' => ['en' => 'Upload Slip', 'th' => 'อัปโหลดสลิป'],
            'deposit.slip.upload_hint' => ['en' => 'Click to upload or drag slip here', 'th' => 'คลิกเพื่ออัปโหลดหรือลากสลิปมาที่นี่'],
            'deposit.slip.warning' => ['en' => '* System verifies slips automatically within seconds.', 'th' => '* ระบบจะตรวจสอบสลิปโดยอัตโนมัติภายในไม่กี่วินาที'],
            'deposit.slip.warning_mobile' => ['en' => 'Please transfer via bank app only. System does not support TrueMoney Transfer.', 'th' => 'กรุณาโอนผ่านแอปธนาคารเท่านั้น ระบบไม่รองรับการโอนด้วยทรูมันนี่'],
            'deposit.select_method' => ['en' => 'Please select your deposit method', 'th' => 'โปรดเลือกช่องทางการเติมเงินของคุณ'],
            'deposit.angpao.fee' => ['en' => 'Fee 2.9% up to 20฿', 'th' => 'ค่าธรรมเนียม 2.9% สูงสุด 20฿'],
            'deposit.angpao.label' => ['en' => 'Angpao Link', 'th' => 'ลิงก์ซองอั่งเปา'],
            'deposit.redeem.instant' => ['en' => 'Instant Reward', 'th' => 'แลกรางวัลทันที'],
            'deposit.redeem.label' => ['en' => 'Redeem Code', 'th' => 'รหัสเติมเงิน'],
            'deposit.success_msg' => ['en' => 'Deposit initialized successfully!', 'th' => 'เริ่มรายการเติมเงินเรียบร้อยแล้ว!'],
            'deposit.title' => ['en' => 'Deposit Balance', 'th' => 'เติมเงิน'],
            'key' => ['en' => 'Key', 'th' => 'คีย์'],
            'mykeys.buy_now' => ['en' => 'Buy Now', 'th' => 'ซื้อเลย'],
            'mykeys.empty' => ['en' => 'You haven\'t purchased any keys yet.', 'th' => 'คุณยังไม่มีคีย์ที่ซื้อ'],
            'mykeys.keys_unit' => ['en' => 'Keys', 'th' => 'คีย์'],
            'mykeys.title' => ['en' => 'My License Keys', 'th' => 'คีย์ของฉัน'],
            'mykeys.search_placeholder' => ['en' => 'Search product or license key...', 'th' => 'ค้นหาสินค้าหรือรหัสคีย์...'],
            'mykeys.reset_hwid' => ['en' => 'Reset HWID', 'th' => 'รีเซ็ต HWID'],
            'mykeys.reset_confirm' => ['en' => 'Reset the HWID for this key?', 'th' => 'ยืนยันการรีเซ็ต HWID ของคีย์นี้หรือไม่?'],
            'mykeys.reset_config_unavailable' => ['en' => 'The HWID reset service is not configured. Please contact the administrator.', 'th' => 'ระบบรีเซ็ต HWID ยังไม่ได้ตั้งค่า กรุณาติดต่อผู้ดูแลระบบ'],
            'mykeys.reset_supported_note' => ['en' => 'Only supported xChetos keys assigned to this reseller can be reset. The server verifies each key in the shop xChetos inventory before sending a reset.', 'th' => 'รีเซ็ตได้เฉพาะคีย์ xChetos ที่รองรับและเป็นของบัญชีตัวแทนนี้ โดยเซิร์ฟเวอร์จะตรวจคีย์กับรายการของร้านใน xChetos ก่อนส่งคำขอทุกครั้ง'],
            'mykeys.reset_history' => ['en' => 'Recent HWID Reset History', 'th' => 'ประวัติรีเซ็ต HWID ล่าสุด'],
            'mykeys.reset_history_empty' => ['en' => 'No HWID reset history yet.', 'th' => 'ยังไม่มีประวัติการรีเซ็ต HWID'],
            'mykeys.reset_history_status' => ['en' => 'Status', 'th' => 'สถานะ'],
            'mykeys.reset_history_time' => ['en' => 'Requested At', 'th' => 'เวลาที่ดำเนินการ'],
            'mykeys.reset_status.success' => ['en' => 'Success', 'th' => 'สำเร็จ'],
            'mykeys.reset_status.failed' => ['en' => 'Failed', 'th' => 'ล้มเหลว'],
            'mykeys.reset_status.unknown' => ['en' => 'Check Required', 'th' => 'ต้องตรวจสอบ'],
            'mykeys.reset_status.processing' => ['en' => 'Processing', 'th' => 'กำลังดำเนินการ'],
            'mykeys.reset_message.success' => ['en' => 'HWID reset completed for {key}.', 'th' => 'รีเซ็ต HWID สำเร็จสำหรับคีย์ {key}'],
            'mykeys.reset_message.invalid_request' => ['en' => 'The reset request is invalid.', 'th' => 'คำขอรีเซ็ตไม่ถูกต้อง'],
            'mykeys.reset_message.forbidden' => ['en' => 'Only reseller accounts may reset HWID.', 'th' => 'เฉพาะบัญชีตัวแทนเท่านั้นที่สามารถรีเซ็ต HWID ได้'],
            'mykeys.reset_message.not_configured' => ['en' => 'The xChetos reset account has not been configured.', 'th' => 'ยังไม่ได้ตั้งค่าบัญชี xChetos สำหรับระบบรีเซ็ต'],
            'mykeys.reset_message.audit_unavailable' => ['en' => 'The reset log could not be prepared. No request was sent.', 'th' => 'ไม่สามารถเตรียมระบบบันทึกประวัติได้ จึงยังไม่ได้ส่งคำขอรีเซ็ต'],
            'mykeys.reset_message.key_not_found' => ['en' => 'This key does not belong to your reseller account.', 'th' => 'คีย์นี้ไม่ได้เป็นของบัญชีตัวแทนของคุณ'],
            'mykeys.reset_message.key_not_supported' => ['en' => 'This key is not supported by the xChetos reset service.', 'th' => 'คีย์นี้ไม่รองรับการรีเซ็ตผ่าน xChetos'],
            'mykeys.reset_message.rate_limited' => ['en' => 'Too many reset attempts. Please wait about {seconds} seconds.', 'th' => 'มีการรีเซ็ตถี่เกินไป กรุณารอประมาณ {seconds} วินาที'],
            'mykeys.reset_message.cooldown' => ['en' => 'This key was reset recently. Please wait another {seconds} seconds.', 'th' => 'คีย์นี้เพิ่งถูกรีเซ็ต กรุณารออีก {seconds} วินาที'],
            'mykeys.reset_message.verification_pending' => ['en' => 'The previous reset result still needs checking. Please wait {seconds} seconds before trying again.', 'th' => 'ผลการรีเซ็ตครั้งก่อนยังต้องตรวจสอบ กรุณารอ {seconds} วินาทีก่อนลองใหม่'],
            'mykeys.reset_message.busy' => ['en' => 'Another reset is being processed for this reseller account.', 'th' => 'บัญชีตัวแทนนี้กำลังดำเนินการรีเซ็ตรายการอื่นอยู่'],
            'mykeys.reset_message.login_failed' => ['en' => 'The website could not sign in to xChetos. Please contact the administrator.', 'th' => 'เว็บไซต์ไม่สามารถเข้าสู่ระบบ xChetos ได้ กรุณาติดต่อผู้ดูแลระบบ'],
            'mykeys.reset_message.provider_not_owned' => ['en' => 'xChetos reported that this license does not belong to the shop account.', 'th' => 'xChetos แจ้งว่าคีย์นี้ไม่ได้อยู่ในบัญชีของร้าน'],
            'mykeys.reset_message.provider_rate_limited' => ['en' => 'xChetos temporarily limited reset requests. Please try again later.', 'th' => 'xChetos จำกัดการรีเซ็ตชั่วคราว กรุณาลองใหม่ภายหลัง'],
            'mykeys.reset_message.provider_rejected' => ['en' => 'xChetos rejected this reset request.', 'th' => 'xChetos ปฏิเสธคำขอรีเซ็ตนี้'],
            'mykeys.reset_message.provider_unavailable' => ['en' => 'The xChetos service is currently unavailable.', 'th' => 'ขณะนี้ไม่สามารถเชื่อมต่อบริการ xChetos ได้'],
            'mykeys.reset_message.provider_unknown' => ['en' => 'The result could not be confirmed. The reset may already have completed; check before retrying.', 'th' => 'ไม่สามารถยืนยันผลได้ การรีเซ็ตอาจสำเร็จแล้ว กรุณาตรวจสอบก่อนกดซ้ำ'],
            'mykeys.reset_message.curl_missing' => ['en' => 'The server does not have PHP cURL enabled.', 'th' => 'เซิร์ฟเวอร์ยังไม่ได้เปิดใช้งาน PHP cURL'],
            'mykeys.reset_message.provider_verification_unknown' => ['en' => 'The provider ownership check ended uncertainly, so no reset was sent. Please ask the administrator to inspect the reference log.', 'th' => 'การตรวจสอบคีย์กับ xChetos ได้ผลไม่แน่ชัด ระบบจึงยังไม่ส่งรีเซ็ต กรุณาให้แอดมินตรวจ Logs จากรหัสอ้างอิง'],
            'mykeys.reset_message.provider_inventory_invalid' => ['en' => 'The xChetos license inventory response format changed or was invalid. No reset was sent.', 'th' => 'รูปแบบรายการคีย์จาก xChetos เปลี่ยนหรือไม่ถูกต้อง ระบบจึงไม่ส่งรีเซ็ต'],
            'mykeys.reset_message.provider_inventory_incomplete' => ['en' => 'The xChetos inventory was larger than the configured safe scan limit. No reset was sent.', 'th' => 'รายการคีย์ xChetos มากกว่าขอบเขตตรวจสอบที่ตั้งไว้ ระบบจึงไม่ส่งรีเซ็ต'],
            'mykeys.reset_reference' => ['en' => 'Reference ID: {id}', 'th' => 'รหัสอ้างอิง: {id}'],
            'mykeys.reset_message.generic' => ['en' => 'The HWID reset could not be completed.', 'th' => 'ไม่สามารถรีเซ็ต HWID ได้'],
            'nav.account' => ['en' => 'Account', 'th' => 'บัญชี'],
            'nav.admin' => ['en' => 'Admin Panel', 'th' => 'ระบบหลังบ้าน'],
            'nav.binance' => ['en' => 'Binance Deposits', 'th' => 'การเติมเงิน Binance'],
            'nav.buy' => ['en' => 'Buy Products', 'th' => 'ซื้อสินค้า'],
            'nav.api_store' => ['en' => 'API Products', 'th' => 'สินค้า API'],
            'nav.cheatgame_api' => ['en' => 'CHEATGAME API', 'th' => 'CHEATGAME API'],
            'nav.xchetos_reset' => ['en' => 'xChetos Reset', 'th' => 'รีเซ็ต xChetos'],
            'nav.key_reset' => ['en' => 'Key Reset', 'th' => 'รีเซ็ตคีย์'],
            'nav.codes' => ['en' => 'Redeem Codes', 'th' => 'จัดการโค้ด'],
            'nav.dashboard' => ['en' => 'Dashboard', 'th' => 'แผงควบคุม'],
            'nav.dashboard_short' => ['en' => 'Dash', 'th' => 'แผงคุม'],
            'nav.deposit' => ['en' => 'Deposit', 'th' => 'เติมเงิน'],
            'nav.history' => ['en' => 'History', 'th' => 'ประวัติ'],
            'nav.logout' => ['en' => 'Logout', 'th' => 'ออกจากระบบ'],
            'nav.more' => ['en' => 'More', 'th' => 'เพิ่มเติม'],
            'nav.rankings' => ['en' => 'Rankings', 'th' => 'จัดอันดับ'],
            'ranking.title' => ['en' => 'Rank Arena', 'th' => 'สนามจัดอันดับ'],
            'ranking.subtitle' => ['en' => 'Compete throughout the month and build your all-time standing.', 'th' => 'แข่งขันตลอดเดือนและสะสมอันดับตลอดกาล'],
            'ranking.monthly_title' => ['en' => 'Monthly User Rankings', 'th' => 'อันดับผู้ใช้ประจำเดือน'],
            'ranking.monthly_desc' => ['en' => 'Top 10 users for the current calendar month.', 'th' => 'ผู้ใช้อันดับสูงสุด 10 คนของเดือนปัจจุบัน'],
            'ranking.lifetime_title' => ['en' => 'All-Time Deposit Rankings', 'th' => 'อันดับเติมเงินสะสมตลอดกาล'],
            'ranking.lifetime_desc' => ['en' => 'Users and resellers compete together. Administrators are excluded.', 'th' => 'ผู้ใช้และตัวแทนแข่งขันร่วมกัน โดยไม่รวมแอดมิน'],
            'ranking.my_rank' => ['en' => 'Your Current Rank', 'th' => 'แรงค์ปัจจุบันของคุณ'],
            'ranking.my_position' => ['en' => 'Your Position', 'th' => 'อันดับของคุณ'],
            'ranking.position' => ['en' => 'Position', 'th' => 'อันดับ'],
            'ranking.not_ranked' => ['en' => 'Not ranked yet', 'th' => 'ยังไม่มีอันดับ'],
            'ranking.rank.unranked' => ['en' => 'Unranked', 'th' => 'ยังไม่มีแรงค์'],
            'ranking.rank.bronze' => ['en' => 'Bronze', 'th' => 'Bronze'],
            'ranking.rank.silver' => ['en' => 'Silver', 'th' => 'Silver'],
            'ranking.rank.gold' => ['en' => 'Gold', 'th' => 'Gold'],
            'ranking.rank.platinum' => ['en' => 'Platinum', 'th' => 'Platinum'],
            'ranking.month_progress_title' => ['en' => 'This Month\'s Rank Progress', 'th' => 'ความคืบหน้าแรงค์เดือนนี้'],
            'ranking.month_progress_desc' => ['en' => 'Check how much you have deposited this month and how close you are to the next rank.', 'th' => 'ตรวจสอบยอดเติมเดือนนี้และดูว่าเหลืออีกเท่าไรจึงจะขึ้นแรงค์ถัดไป'],
            'ranking.month_deposit_amount' => ['en' => 'Rank-qualifying deposits this month', 'th' => 'ยอดเติมที่นับเข้าแรงค์เดือนนี้'],
            'ranking.rank_bonus' => ['en' => 'Rank bonus', 'th' => 'โบนัสแรงค์'],
            'ranking.next_rank_target' => ['en' => 'Next rank target', 'th' => 'เป้าหมายแรงค์ถัดไป'],
            'ranking.remaining_to_next' => ['en' => 'Amount remaining', 'th' => 'ยอดที่เหลืออีก'],
            'ranking.progress' => ['en' => 'Progress', 'th' => 'ความคืบหน้า'],
            'ranking.remaining_percent' => ['en' => 'Remaining', 'th' => 'เหลืออีก'],
            'ranking.max_rank' => ['en' => 'Highest rank', 'th' => 'แรงค์สูงสุด'],
            'ranking.max_rank_reached' => ['en' => 'You have reached the highest rank for this month.', 'th' => 'คุณขึ้นถึงแรงค์สูงสุดของเดือนนี้แล้ว'],
            'ranking.bonus_next_deposit_note' => ['en' => 'Your current rank bonus applies to the next eligible completed deposit.', 'th' => 'โบนัสของแรงค์ปัจจุบันจะใช้กับรายการเติมเงินที่สำเร็จและเข้าเงื่อนไขครั้งถัดไป'],
            'ranking.role.user' => ['en' => 'User', 'th' => 'ผู้ใช้งาน'],
            'ranking.role.reseller' => ['en' => 'Reseller', 'th' => 'ตัวแทน'],
            'ranking.role.admin' => ['en' => 'Administrator', 'th' => 'แอดมิน'],
            'ranking.score' => ['en' => 'Power', 'th' => 'พลังสะสม'],
            'ranking.amount' => ['en' => 'Total deposited', 'th' => 'ยอดเติมสะสม'],
            'ranking.lifetime_amount' => ['en' => 'Your all-time deposits', 'th' => 'ยอดเติมสะสมของคุณ'],
            'ranking.reset_note' => ['en' => 'Monthly rankings start fresh on the 1st for everyone.', 'th' => 'อันดับประจำเดือนเริ่มใหม่พร้อมกันทุกคนในวันที่ 1'],
            'ranking.privacy_note' => ['en' => 'Usernames are partially hidden.', 'th' => 'ชื่อผู้ใช้ถูกปิดบางส่วน'],
            'ranking.public_amount_note' => ['en' => 'All-time deposit totals are public while usernames remain partially hidden.', 'th' => 'ยอดเติมสะสมแสดงแบบสาธารณะ แต่ชื่อผู้ใช้ยังถูกปิดบางส่วน'],
            'ranking.empty' => ['en' => 'No ranking data yet.', 'th' => 'ยังไม่มีข้อมูลการจัดอันดับ'],
            'ranking.bonus_received' => ['en' => 'Rank bonus', 'th' => 'โบนัสแรงค์'],
            'ranking.open_board' => ['en' => 'Open Rankings', 'th' => 'เปิดกระดานจัดอันดับ'],
            'ranking.current_user' => ['en' => 'You', 'th' => 'คุณ'],
            'ranking.admin.title' => ['en' => 'Ranking & Bonus Audit', 'th' => 'ตรวจสอบอันดับและโบนัส'],
            'ranking.admin.awards' => ['en' => 'Recent Bonus Decisions', 'th' => 'การตัดสินโบนัสล่าสุด'],
            'ranking.admin.no_awards' => ['en' => 'No bonus decisions yet.', 'th' => 'ยังไม่มีรายการตัดสินโบนัส'],
            'ranking.admin.deposit_tx' => ['en' => 'Deposit Transaction', 'th' => 'ธุรกรรมเติมเงิน'],
            'ranking.admin.base' => ['en' => 'Base Deposit', 'th' => 'ยอดเติมหลัก'],
            'ranking.admin.bonus' => ['en' => 'Bonus', 'th' => 'โบนัส'],
            'ranking.admin.status' => ['en' => 'Decision', 'th' => 'ผลการตัดสิน'],
            'ranking.admin.readonly_desc' => ['en' => 'Read-only financial audit for rank decisions and bonus transactions.', 'th' => 'หน้าตรวจสอบการตัดสินแรงค์และธุรกรรมโบนัสแบบอ่านอย่างเดียว'],
            'ranking.admin.monthly_entries' => ['en' => 'Monthly board entries', 'th' => 'รายการบนบอร์ดรายเดือน'],
            'ranking.admin.lifetime_entries' => ['en' => 'Lifetime board entries', 'th' => 'รายการบนบอร์ดตลอดกาล'],
            'ranking.admin.applied_month' => ['en' => 'Bonuses applied this month', 'th' => 'โบนัสที่จ่ายเดือนนี้'],
            'ranking.admin.paid_month' => ['en' => 'Bonus paid this month', 'th' => 'ยอดโบนัสเดือนนี้'],
            'ranking.admin.user' => ['en' => 'User', 'th' => 'ผู้ใช้'],
            'ranking.admin.rank' => ['en' => 'Rank', 'th' => 'แรงค์'],
            'ranking.admin.rank_used' => ['en' => 'Benefit rank used', 'th' => 'แรงค์ที่ใช้คำนวณ'],
            'ranking.admin.date' => ['en' => 'Date', 'th' => 'วันที่'],
            'ranking.admin.monthly_total' => ['en' => 'Monthly qualifying total', 'th' => 'ยอดสะสมเข้าแรงค์เดือนนี้'],
            'ranking.admin.lifetime_total' => ['en' => 'All-time deposit total', 'th' => 'ยอดเติมสะสมทั้งหมด'],
            'ranking.admin.monthly_board' => ['en' => 'Complete Monthly Rank Board', 'th' => 'กระดานแรงค์รายเดือนทั้งหมด'],
            'ranking.admin.lifetime_board' => ['en' => 'Complete All-Time Deposit Board', 'th' => 'กระดานเติมเงินสะสมทั้งหมด'],
            'ranking.admin.ledger' => ['en' => 'Deposit Qualification Ledger', 'th' => 'บัญชีรายการเติมเงินที่ใช้จัดอันดับ'],
            'ranking.admin.ledger_desc' => ['en' => 'Every recorded deposit used by rankings, including source transaction status for reconciliation.', 'th' => 'รายการเติมเงินทุกแถวที่ระบบจัดอันดับบันทึกไว้ พร้อมสถานะธุรกรรมต้นทางสำหรับตรวจสอบ'],
            'ranking.admin.bonus_desc' => ['en' => 'Every bonus decision, including deposits that received no bonus.', 'th' => 'การตัดสินโบนัสทุกครั้ง รวมถึงรายการที่ไม่ได้รับโบนัส'],
            'ranking.admin.user_id' => ['en' => 'User ID', 'th' => 'รหัสผู้ใช้'],
            'ranking.admin.username' => ['en' => 'Full username', 'th' => 'ชื่อผู้ใช้เต็ม'],
            'ranking.admin.role' => ['en' => 'Role', 'th' => 'ประเภทบัญชี'],
            'ranking.admin.last_deposit' => ['en' => 'Last deposit', 'th' => 'เติมล่าสุด'],
            'ranking.admin.credited_amount' => ['en' => 'Credited amount', 'th' => 'ยอดเครดิตจริง'],
            'ranking.admin.qualifying_thb' => ['en' => 'Qualifying THB', 'th' => 'ยอดที่ใช้จัดอันดับ (บาท)'],
            'ranking.admin.source' => ['en' => 'Source', 'th' => 'ช่องทาง'],
            'ranking.admin.method' => ['en' => 'Conversion method', 'th' => 'วิธีคำนวณเป็นบาท'],
            'ranking.admin.tx_status' => ['en' => 'Transaction status', 'th' => 'สถานะธุรกรรม'],
            'ranking.admin.account_status' => ['en' => 'Account status', 'th' => 'สถานะบัญชี'],
            'ranking.admin.description' => ['en' => 'Description', 'th' => 'รายละเอียด'],
            'ranking.admin.period' => ['en' => 'Rank period', 'th' => 'รอบแรงค์'],
            'ranking.admin.bonus_tx' => ['en' => 'Bonus transaction', 'th' => 'ธุรกรรมโบนัส'],
            'ranking.admin.qualifying_total' => ['en' => 'Monthly total after deposit', 'th' => 'ยอดสะสมเดือนหลังรายการ'],
            'ranking.admin.records' => ['en' => 'records', 'th' => 'รายการ'],
            'ranking.admin.previous' => ['en' => 'Previous', 'th' => 'ก่อนหน้า'],
            'ranking.admin.next' => ['en' => 'Next', 'th' => 'ถัดไป'],
            'ranking.admin.page' => ['en' => 'Page', 'th' => 'หน้า'],
            'ranking.admin.of' => ['en' => 'of', 'th' => 'จาก'],
            'ranking.admin.no_ledger' => ['en' => 'No deposit ledger records yet.', 'th' => 'ยังไม่มีรายการในบัญชีจัดอันดับ'],
            'ranking.admin.no_board' => ['en' => 'No board entries yet.', 'th' => 'ยังไม่มีรายการบนกระดาน'],
            'ranking.admin.ledger_id' => ['en' => 'Ledger ID', 'th' => 'รหัสบัญชีรายการ'],
            'ranking.admin.award_id' => ['en' => 'Award ID', 'th' => 'รหัสการตัดสินโบนัส'],
            'ranking.admin.current_role' => ['en' => 'Current role', 'th' => 'ประเภทปัจจุบัน'],
            'ranking.admin.updated' => ['en' => 'Updated', 'th' => 'อัปเดตล่าสุด'],
            'ranking.admin.raw_status.completed' => ['en' => 'Completed', 'th' => 'สำเร็จ'],
            'ranking.admin.raw_status.pending' => ['en' => 'Pending', 'th' => 'รอดำเนินการ'],
            'ranking.admin.raw_status.failed' => ['en' => 'Failed', 'th' => 'ล้มเหลว'],
            'ranking.admin.raw_status.processing' => ['en' => 'Processing', 'th' => 'กำลังดำเนินการ'],
            'ranking.admin.raw_status.active' => ['en' => 'Active', 'th' => 'ใช้งานอยู่'],
            'ranking.admin.raw_status.banned' => ['en' => 'Banned', 'th' => 'ถูกระงับ'],
            'ranking.admin.raw_status.missing' => ['en' => 'Missing record', 'th' => 'ไม่พบข้อมูลต้นทาง'],
            'ranking.admin.raw_status.unknown' => ['en' => 'Unknown', 'th' => 'ไม่ทราบสถานะ'],
            'ranking.admin.status.applied' => ['en' => 'Applied', 'th' => 'จ่ายแล้ว'],
            'ranking.admin.status.not_eligible' => ['en' => 'No bonus', 'th' => 'ยังไม่ได้โบนัส'],
            'ranking.admin.status.processing' => ['en' => 'Processing', 'th' => 'กำลังดำเนินการ'],
            'ranking.admin.status.unknown' => ['en' => 'Unknown', 'th' => 'ไม่ทราบสถานะ'],
            'ranking.error.unavailable' => ['en' => 'The ranking system is temporarily unavailable. Please try again or contact the administrator.', 'th' => 'ระบบจัดอันดับยังไม่พร้อม กรุณาลองใหม่อีกครั้งหรือติดต่อผู้ดูแลระบบ'],
            'admin.transactions.type.rank_bonus' => ['en' => 'Rank Bonus', 'th' => 'โบนัสแรงค์'],
            'nav.more_short' => ['en' => 'More', 'th' => 'อื่น ๆ'],
            'nav.my_keys' => ['en' => 'My Keys', 'th' => 'คีย์ของฉัน'],
            'nav.out_of_stock' => ['en' => 'Out of Stock', 'th' => 'สินค้าหมด'],
            'nav.product_keys' => ['en' => 'Manage Keys', 'th' => 'จัดการคีย์'],
            'nav.products' => ['en' => 'Products', 'th' => 'จัดการสินค้า'],
            'nav.products_short' => ['en' => 'Products', 'th' => 'สินค้า'],
            'nav.profit' => ['en' => 'Profit Report', 'th' => 'สรุปกำไร'],
            'nav.reseller' => ['en' => 'Reseller Panel', 'th' => 'ระบบตัวแทน'],
            'nav.resellers' => ['en' => 'Manage Resellers', 'th' => 'จัดการตัวแทน'],
            'nav.settings' => ['en' => 'Settings', 'th' => 'ตั้งค่าระบบ'],
            'nav.settings_short' => ['en' => 'Settings', 'th' => 'ตั้งค่า'],
            'nav.special_prices' => ['en' => 'Special Prices', 'th' => 'ราคาพิเศษ'],
            'nav.transactions' => ['en' => 'Transactions', 'th' => 'ประวัติรายการ'],
            'nav.transactions_short' => ['en' => 'Trans', 'th' => 'รายการ'],
            'nav.users' => ['en' => 'Users', 'th' => 'ผู้ใช้'],
            'nav.users_short' => ['en' => 'Users', 'th' => 'ผู้ใช้'],
            'products.status.active' => ['en' => 'Active', 'th' => 'เปิดใช้งาน'],
            'register.confirm_password.placeholder' => ['en' => 'Re-enter password', 'th' => 'ยืนยันรหัสผ่านอีกครั้ง'],
            'register.username' => ['en' => 'Username', 'th' => 'ชื่อผู้ใช้'],
            'reseller.account_type' => ['en' => 'Account Type', 'th' => 'ประเภทบัญชี'],
            'reseller.nav.reseller_panel' => ['en' => 'Reseller Panel', 'th' => 'แผงตัวแทน'],
            'reseller.no_keys_purchased' => ['en' => 'No keys purchased yet.', 'th' => 'ยังไม่มีการซื้อคีย์'],
            'reseller.no_keys_purchased_start' => ['en' => 'No keys purchased yet. Start buying now!', 'th' => 'ยังไม่มีการซื้อคีย์ เริ่มซื้อตอนนี้เลย!'],
            'reseller.recent_purchases' => ['en' => 'Recent Reseller Purchases', 'th' => 'การซื้อล่าสุดของตัวแทน'],

            // Store catalogue / dashboard categories
            'catalog.browse_title' => ['th' => 'เลือกสินค้าตามหมวดหมู่', 'en' => 'Browse Products by Category'],
            'catalog.browse_desc' => ['th' => 'เลือกแพลตฟอร์มหรือหมวดหมู่ แล้วไปยังหน้าสินค้าที่กรองไว้ทันที', 'en' => 'Choose a platform or category and open the store with matching products.'],
            'catalog.platform_title' => ['th' => 'แพลตฟอร์ม / รูปแบบสินค้า', 'en' => 'Platform / Product Type'],
            'catalog.platform_desc' => ['th' => 'เลือก Android, iOS, ทั้งสองระบบ หรือสินค้าแบบ Account', 'en' => 'Choose Android, iOS, both platforms, or Account products.'],
            'catalog.category_title' => ['th' => 'หมวดหมู่สินค้า', 'en' => 'Product Categories'],
            'catalog.category_desc' => ['th' => 'หมวดหมู่ทั้งหมดจะแสดงแบบตารางและไม่ต้องเลื่อนด้านข้าง', 'en' => 'All categories are shown in a responsive grid without horizontal scrolling.'],
            'catalog.all_platforms' => ['th' => 'ทุกแพลตฟอร์ม', 'en' => 'All Platforms'],
            'catalog.both_platforms' => ['th' => 'ทั้งสองระบบ', 'en' => 'Android + iOS'],
            'catalog.account_products' => ['th' => 'Account', 'en' => 'Account'],
            'catalog.products_count' => ['th' => '{count} สินค้า', 'en' => '{count} products'],
            'catalog.open_store' => ['th' => 'เปิดหน้าสินค้า', 'en' => 'Open Store'],
            'catalog.no_categories' => ['th' => 'ยังไม่มีหมวดหมู่สินค้าที่เปิดใช้งาน', 'en' => 'No active product categories yet.'],
            'catalog.total_products' => ['th' => 'สินค้าที่เปิดใช้งานทั้งหมด', 'en' => 'Total Active Products'],

            // Settings language and validation
            'admin.settings.default_language' => ['th' => 'ภาษาเริ่มต้นของเว็บไซต์', 'en' => 'Default Website Language'],
            'admin.settings.default_language_hint' => ['th' => 'ใช้เป็นภาษาเริ่มต้นจนกว่าผู้ใช้จะเลือกภาษาอื่น', 'en' => 'Used until a visitor selects another language.'],
            'admin.settings.site_base_url' => ['th' => 'โดเมนเว็บไซต์นี้ (Base URL)', 'en' => 'This Website Base URL'],
            'admin.settings.site_base_url_hint' => ['th' => 'ใช้ HTTPS และไม่ใส่ path ต่อท้าย', 'en' => 'Use HTTPS and do not include a trailing path.'],
            'admin.settings.secret_not_shown' => ['th' => 'ระบบจะไม่แสดงค่าลับเดิมกลับมาในหน้าเว็บ', 'en' => 'Existing secrets are never displayed on this page.'],
            'admin.settings.bank_name_th' => ['th' => 'ชื่อธนาคาร (ไทย)', 'en' => 'Bank Name (Thai)'],
            'admin.settings.bank_name_en' => ['th' => 'ชื่อธนาคาร (อังกฤษ/รหัส)', 'en' => 'Bank Name (English / Code)'],
            'admin.settings.bank_name_th_placeholder' => ['th' => 'เช่น กรุงไทย', 'en' => 'Example: Krungthai'],
            'admin.settings.bank_name_en_placeholder' => ['th' => 'เช่น KTB', 'en' => 'Example: KTB'],
            'admin.settings.bank_name_th_hint' => ['th' => 'ใช้แสดงในหน้า Mobile Banking', 'en' => 'Displayed on the Mobile Banking instructions.'],
            'admin.settings.bank_name_en_hint' => ['th' => 'ใช้เมื่อเว็บไซต์แสดงภาษาอังกฤษ', 'en' => 'Used when the website is displayed in English.'],
            'admin.settings.slip_max_age' => ['th' => 'อายุสลิปสูงสุด (นาที)', 'en' => 'Maximum Slip Age (Minutes)'],
            'admin.settings.slip_max_age_hint' => ['th' => 'แนะนำ 1440 นาที (24 ชั่วโมง) เพื่อป้องกันการใช้สลิปเก่า', 'en' => 'Recommended: 1440 minutes (24 hours) to prevent old slips from being reused.'],
            'admin.settings.keep_api_key_placeholder' => ['th' => 'เว้นว่างเพื่อใช้ API Key เดิม', 'en' => 'Leave blank to keep the current API key'],
            'admin.settings.keep_secret_key_placeholder' => ['th' => 'เว้นว่างเพื่อใช้ Secret Key เดิม', 'en' => 'Leave blank to keep the current secret key'],
            'admin.settings.secret_replace_hint' => ['th' => 'ระบบจะเก็บค่าเดิมไว้ และเปลี่ยนเฉพาะเมื่อกรอกค่าใหม่', 'en' => 'The current value is kept unless a new value is entered.'],
            'admin.settings.current_image' => ['th' => 'รูปปัจจุบัน', 'en' => 'Current image'],
            'admin.settings.remove_image' => ['th' => 'ลบรูป', 'en' => 'Remove image'],
            'admin.settings.undo_remove' => ['th' => 'ยกเลิกการลบ', 'en' => 'Undo removal'],
            'admin.settings.image_remove_pending' => ['th' => 'รูปนี้จะถูกลบเมื่อกดบันทึก', 'en' => 'This image will be removed when you save.'],
            'admin.settings.image_hint_full' => ['th' => 'รองรับ PNG ไม่เกิน 2 MB ขนาดสูงสุด 2048×2048 พิกเซล', 'en' => 'PNG only, up to 2 MB and 2048×2048 pixels.'],
            'admin.settings.image_preview_alt' => ['th' => 'ตัวอย่างรูปหัวข้อ', 'en' => 'Title image preview'],
            'admin.settings.no_image' => ['th' => 'ยังไม่ได้ตั้งค่ารูป', 'en' => 'No image configured'],
            'admin.settings.image_file_missing' => ['th' => 'ไม่พบไฟล์เดิม กรุณาลบค่าหรืออัปโหลดรูปใหม่', 'en' => 'The saved file is missing. Remove it or upload a new image.'],

            'admin.settings.error.invalid_currency' => ['th' => 'สกุลเงินที่เลือกไม่ถูกต้อง', 'en' => 'Invalid currency selection.'],
            'admin.settings.error.currency_change_requires_migration' => ['th' => 'ไม่สามารถเปลี่ยนสกุลเงินฐานได้หลังมีข้อมูลยอดเงิน ราคา หรือธุรกรรม ต้องแปลงข้อมูลฐานทั้งหมดก่อน มิฉะนั้นตัวเลขเดิมจะถูกตีความผิดสกุลเงิน', 'en' => 'The base currency cannot be changed after balances, prices, or transactions exist. Migrate all stored monetary data first or existing numbers would be interpreted in the wrong currency.'],
            'admin.settings.error.site_name' => ['th' => 'ชื่อเว็บไซต์ต้องมีความยาว 1–100 ตัวอักษร', 'en' => 'Site name must contain 1 to 100 characters.'],
            'admin.settings.error.default_language' => ['th' => 'ภาษาเริ่มต้นไม่ถูกต้อง', 'en' => 'Invalid default language.'],
            'admin.settings.error.discount' => ['th' => 'ส่วนลดตัวแทนต้องอยู่ระหว่าง 0 ถึง 100', 'en' => 'Reseller discount must be between 0 and 100.'],
            'admin.settings.error.site_base_url' => ['th' => 'โดเมนเว็บไซต์ต้องเป็น HTTPS URL ที่ถูกต้อง เช่น https://shop.example.com', 'en' => 'The website domain must be a valid HTTPS URL, such as https://shop.example.com.'],
            'admin.settings.error.title_color' => ['th' => 'สีหัวข้อต้องเป็น HEX 6 หลัก เช่น #60a5fa', 'en' => 'Title color must be a 6-digit HEX value such as #60a5fa.'],
            'admin.settings.error.truemoney_phone' => ['th' => 'หมายเลขโทรศัพท์ TrueMoney ไม่ถูกต้อง', 'en' => 'TrueMoney phone number is invalid.'],
            'admin.settings.error.easyslip_key_required' => ['th' => 'กรุณากรอก EasySlip API key ก่อนเปิดตรวจสลิป', 'en' => 'Enter the EasySlip API key before enabling slip verification.'],
            'admin.settings.error.easyslip_key' => ['th' => 'รูปแบบ EasySlip API key ไม่ถูกต้อง', 'en' => 'EasySlip API key format is invalid.'],
            'admin.settings.error.receiver_account' => ['th' => 'กรุณากรอกเบอร์โทรหรือเลขบัญชีผู้รับอย่างน้อยหนึ่งรายการ', 'en' => 'Enter at least one receiver phone number or bank account number.'],
            'admin.settings.error.receiver_name' => ['th' => 'กรุณากรอกชื่อผู้รับอย่างน้อยหนึ่งภาษา', 'en' => 'Enter at least one receiver name.'],
            'admin.settings.error.bank_name' => ['th' => 'กรุณากรอกชื่อธนาคารอย่างน้อยหนึ่งภาษา', 'en' => 'Enter at least one bank name.'],
            'admin.settings.error.receiver_too_long' => ['th' => 'ชื่อผู้รับหรือชื่อธนาคารยาวเกินกำหนด', 'en' => 'Receiver or bank name is too long.'],
            'admin.settings.error.receiver_phone' => ['th' => 'เบอร์โทรผู้รับไม่ถูกต้อง', 'en' => 'Receiver phone number is invalid.'],
            'admin.settings.error.receiver_account_number' => ['th' => 'เลขบัญชีผู้รับไม่ถูกต้อง', 'en' => 'Receiver bank account number is invalid.'],
            'admin.settings.error.slip_age' => ['th' => 'อายุสลิปต้องอยู่ระหว่าง 5 ถึง 10080 นาที', 'en' => 'Maximum slip age must be between 5 and 10080 minutes.'],
            'admin.settings.error.binance_required' => ['th' => 'ต้องตั้งค่า Binance API key, secret key และกระเป๋า TRC20 ก่อนเปิดใช้งาน', 'en' => 'Binance API key, secret key, and TRC20 wallet are required before enabling Binance deposits.'],
            'admin.settings.error.binance_credentials' => ['th' => 'รูปแบบข้อมูล Binance API ไม่ถูกต้อง', 'en' => 'Binance API credentials have an invalid format.'],
            'admin.settings.error.binance_wallet' => ['th' => 'กระเป๋า Binance ต้องเป็นที่อยู่ TRON/TRC20 ที่ถูกต้อง', 'en' => 'Binance wallet must be a valid TRON/TRC20 address.'],
            'admin.settings.error.username' => ['th' => 'ชื่อผู้ใช้ต้องมี 3–50 ตัว และใช้ได้เฉพาะตัวอักษร ตัวเลข จุด ขีดล่าง หรือขีดกลาง', 'en' => 'Username must be 3–50 characters and contain only letters, numbers, dot, underscore, or hyphen.'],
            'admin.settings.error.password_short' => ['th' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', 'en' => 'New password must be at least 8 characters.'],

            // Binance Gift Card
            'common.previous' => ['en' => 'Previous', 'th' => 'ก่อนหน้า'],
            'common.next' => ['en' => 'Next', 'th' => 'ถัดไป'],
            'nav.binance_giftcards' => ['en' => 'Binance Gift Cards', 'th' => 'ของขวัญ Binance'],
            'giftcard.title' => ['en' => 'Binance Gift Card', 'th' => 'ของขวัญ Binance'],
            'giftcard.usdt_only' => ['en' => 'Only USDT Binance Gift Cards are credited automatically.', 'th' => 'รองรับการเติมยอดอัตโนมัติเฉพาะ Binance Gift Card ที่เป็น USDT'],
            'giftcard.code_only_warning' => ['en' => 'A redemption code is consumed immediately when Binance accepts it. The token and value are confirmed from the redemption result. Non-USDT or policy exceptions are held for administrator review.', 'th' => 'เมื่อ Binance รับรหัส รหัสจะถูกใช้ทันที ระบบจะตรวจเหรียญและมูลค่าจากผลการแลก หากไม่ใช่ USDT หรือไม่ผ่านเงื่อนไข รายการจะถูกพักให้ผู้ดูแลตรวจสอบ'],
            'giftcard.code_label' => ['en' => '16-character redemption code', 'th' => 'รหัสรับของขวัญ 16 ตัว'],
            'giftcard.code_hint' => ['en' => 'Letters and numbers only. Spaces and hyphens are removed automatically.', 'th' => 'ใช้ตัวอักษรและตัวเลขเท่านั้น ระบบจะตัดช่องว่างและขีดออกให้อัตโนมัติ'],
            'giftcard.toggle_code' => ['en' => 'Show or hide code', 'th' => 'แสดงหรือซ่อนรหัส'],
            'giftcard.redeem_button' => ['en' => 'Redeem Gift Card', 'th' => 'รับของขวัญ'],
            'giftcard.processing' => ['en' => 'Redeeming securely. Do not submit the code again.', 'th' => 'กำลังรับของขวัญอย่างปลอดภัย ห้ามส่งรหัสซ้ำ'],
            'giftcard.secret_warning' => ['en' => 'Keep this code secret. The full code is removed from the form after submission and is not shown in transaction history.', 'th' => 'เก็บรหัสนี้เป็นความลับ รหัสเต็มจะถูกลบจากแบบฟอร์มหลังส่งและไม่แสดงในประวัติธุรกรรม'],
            'giftcard.success' => ['en' => 'Gift Card credited successfully', 'th' => 'รับของขวัญและเติมยอดสำเร็จ'],
            'giftcard.support_code' => ['en' => 'Support code', 'th' => 'รหัสตรวจสอบ'],
            'giftcard.error.disabled' => ['en' => 'Binance Gift Card redemption is currently unavailable.', 'th' => 'ระบบรับของขวัญ Binance ยังไม่เปิดใช้งาน'],
            'giftcard.error.unavailable' => ['en' => 'The Gift Card service is temporarily unavailable. Please try again later.', 'th' => 'ระบบรับของขวัญไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง'],
            'giftcard.error.format' => ['en' => 'Enter a valid 16-character redemption code.', 'th' => 'กรุณากรอกรหัสรับของขวัญ 16 ตัวให้ถูกต้อง'],
            'giftcard.error.role_disabled' => ['en' => 'Gift Card redemption is not available for this account type.', 'th' => 'บัญชีประเภทนี้ยังไม่สามารถใช้ระบบรับของขวัญได้'],
            'giftcard.error.account_age' => ['en' => 'This account is not yet eligible to redeem a Gift Card.', 'th' => 'บัญชีนี้ยังไม่ผ่านระยะเวลาที่กำหนดสำหรับรับของขวัญ'],
            'giftcard.error.provider_limit' => ['en' => 'Gift Card verification is temporarily paused for safety. Please contact an administrator.', 'th' => 'ระบบตรวจรหัสถูกพักชั่วคราวเพื่อความปลอดภัย กรุณาติดต่อผู้ดูแล'],
            'giftcard.error.user_limit' => ['en' => 'You have reached today\'s Gift Card attempt limit.', 'th' => 'คุณใช้จำนวนครั้งในการลองรหัสของวันนี้ครบแล้ว'],
            'giftcard.error.wait' => ['en' => 'Please wait before trying another Gift Card code.', 'th' => 'กรุณารอก่อนลองรหัสของขวัญอีกครั้ง'],
            'giftcard.error.used_or_invalid' => ['en' => 'The card code is invalid or has already been used.', 'th' => 'รหัสบัตรไม่ถูกต้อง หรือ บัตรถูกใช้งานไปแล้ว'],
            'giftcard.error.review' => ['en' => 'The code may already have been accepted. Do not submit it again. An administrator must review this transaction.', 'th' => 'รหัสอาจถูก Binance รับแล้ว ห้ามส่งซ้ำ รายการนี้ต้องให้ผู้ดูแลตรวจสอบ'],
            'giftcard.error.not_usdt' => ['en' => 'The Gift Card was redeemed but is not USDT. It was not credited to the website and requires administrator review.', 'th' => 'รับของขวัญสำเร็จแต่เหรียญไม่ใช่ USDT จึงยังไม่เติมยอดเว็บไซต์และต้องให้ผู้ดูแลตรวจสอบ'],
            'giftcard.error.amount_review' => ['en' => 'The Gift Card was redeemed but its amount requires administrator review.', 'th' => 'รับของขวัญสำเร็จแต่มูลค่าต้องให้ผู้ดูแลตรวจสอบก่อนเติมยอด'],
            'giftcard.error.currency' => ['en' => 'The website currency configuration does not support automatic Gift Card credit.', 'th' => 'การตั้งค่าสกุลเงินของเว็บไซต์ไม่รองรับการเติมยอด Gift Card อัตโนมัติ'],
            'giftcard.error.already_credited' => ['en' => 'This Gift Card has already been credited.', 'th' => 'ของขวัญนี้ถูกเติมยอดแล้ว'],
            'giftcard.error.already_submitted' => ['en' => 'This Gift Card code has already been submitted.', 'th' => 'รหัสของขวัญนี้ถูกส่งเข้าระบบแล้ว'],
            'giftcard.error.processing' => ['en' => 'This Gift Card is still being processed. Do not submit it again.', 'th' => 'ของขวัญนี้กำลังดำเนินการอยู่ ห้ามส่งรหัสซ้ำ'],
            'admin.settings.giftcard_title' => ['en' => 'Binance Gift Card', 'th' => 'Binance Gift Card'],
            'admin.settings.giftcard_subtitle' => ['en' => 'Redeem 16-character Binance Gift Card codes while keeping TRC20 deposits unchanged.', 'th' => 'รับรหัสของขวัญ Binance 16 ตัว โดยคงระบบฝาก TRC20 เดิมไว้'],
            'admin.settings.giftcard_audit' => ['en' => 'Redemption audit', 'th' => 'ตรวจสอบรายการรับของขวัญ'],
            'admin.settings.giftcard_enabled' => ['en' => 'Enable Binance Gift Card', 'th' => 'เปิดระบบ Binance Gift Card'],
            'admin.settings.giftcard_users' => ['en' => 'Allow users', 'th' => 'อนุญาตผู้ใช้งาน'],
            'admin.settings.giftcard_resellers' => ['en' => 'Allow resellers', 'th' => 'อนุญาตตัวแทน'],
            'admin.settings.giftcard_credentials_mode' => ['en' => 'API credential mode', 'th' => 'รูปแบบ API Key'],
            'admin.settings.giftcard_credentials_shared' => ['en' => 'Use existing TRC20 API credentials', 'th' => 'ใช้ API Key เดียวกับ TRC20'],
            'admin.settings.giftcard_credentials_separate' => ['en' => 'Use separate Gift Card API credentials', 'th' => 'ใช้ API Key แยกสำหรับ Gift Card'],
            'admin.settings.giftcard_credentials_hint' => ['en' => 'A separate restricted API key is recommended. The saved secret is never displayed.', 'th' => 'แนะนำให้ใช้ API Key แยกและจำกัดสิทธิ์ Secret ที่บันทึกไว้จะไม่ถูกแสดง'],
            'admin.settings.giftcard_api_key' => ['en' => 'Gift Card API Key', 'th' => 'Gift Card API Key'],
            'admin.settings.giftcard_secret_key' => ['en' => 'Gift Card Secret Key', 'th' => 'Gift Card Secret Key'],
            'admin.settings.giftcard_secret_notice' => ['en' => 'Leave blank to keep the currently saved value. Secrets are never shown back in the form.', 'th' => 'เว้นว่างเพื่อใช้ค่าเดิม ระบบจะไม่แสดง Secret ที่บันทึกไว้กลับมาในแบบฟอร์ม'],
            'admin.settings.giftcard_token' => ['en' => 'Accepted token', 'th' => 'เหรียญที่รองรับ'],
            'admin.settings.giftcard_token_hint' => ['en' => 'Automatic credit is restricted to USDT.', 'th' => 'เติมยอดอัตโนมัติเฉพาะ USDT'],
            'admin.settings.giftcard_min' => ['en' => 'Minimum per card (USDT)', 'th' => 'ขั้นต่ำต่อใบ (USDT)'],
            'admin.settings.giftcard_max' => ['en' => 'Maximum per card (USDT)', 'th' => 'สูงสุดต่อใบ (USDT)'],
            'admin.settings.giftcard_daily' => ['en' => 'Maximum per account per day (USDT)', 'th' => 'สูงสุดต่อบัญชีต่อวัน (USDT)'],
            'admin.settings.giftcard_credit_percent' => ['en' => 'Website credit percentage', 'th' => 'เปอร์เซ็นต์ยอดที่เติมเข้าเว็บไซต์'],
            'admin.settings.giftcard_user_attempts' => ['en' => 'Attempts per account per day', 'th' => 'จำนวนครั้งต่อบัญชีต่อวัน'],
            'admin.settings.giftcard_global_invalid' => ['en' => 'Global invalid-code stop limit', 'th' => 'จำนวนรหัสผิดรวมก่อนพักระบบ'],
            'admin.settings.giftcard_account_age' => ['en' => 'Minimum account age (days)', 'th' => 'อายุบัญชีขั้นต่ำ (วัน)'],
            'admin.settings.giftcard_count_ranking' => ['en' => 'Count toward deposit rankings', 'th' => 'นับในอันดับยอดเติมเงิน'],
            'admin.settings.giftcard_count_ranking_hint' => ['en' => 'Records the qualifying THB value in the ranking ledger.', 'th' => 'บันทึกมูลค่าเงินบาทที่เข้าเงื่อนไขในระบบจัดอันดับ'],
            'admin.settings.giftcard_rank_bonus' => ['en' => 'Apply monthly rank bonus', 'th' => 'ใช้โบนัสแรงค์รายเดือน'],
            'admin.settings.giftcard_rank_bonus_hint' => ['en' => 'High financial impact. Keep disabled until real redemption costs are reviewed.', 'th' => 'มีผลต่อการเงินสูง ควรปิดไว้จนกว่าจะตรวจต้นทุนการรับของขวัญจริง'],
            'admin.settings.giftcard_code_only_title' => ['en' => 'Important code-only limitation', 'th' => 'ข้อจำกัดสำคัญของการใช้รหัสอย่างเดียว'],
            'admin.settings.giftcard_code_only_desc' => ['en' => 'The token and amount cannot be verified before redemption when only the 16-character code is supplied. Binance consumes an accepted code first, then returns its token and value. Exceptions are held for manual review.', 'th' => 'เมื่อมีเพียงรหัส 16 ตัว จะตรวจเหรียญและมูลค่าก่อนรับไม่ได้ Binance จะรับรหัสก่อนแล้วจึงตอบเหรียญและมูลค่า รายการผิดเงื่อนไขจะถูกพักตรวจ'],
            'admin.settings.giftcard_save' => ['en' => 'Save Gift Card settings', 'th' => 'บันทึกการตั้งค่า Gift Card'],
            'admin.settings.giftcard_test' => ['en' => 'Test saved API credentials', 'th' => 'ทดสอบ API Key ที่บันทึกไว้'],
            'admin.settings.giftcard_success' => ['en' => 'Binance Gift Card settings saved.', 'th' => 'บันทึกการตั้งค่า Binance Gift Card แล้ว'],
            'admin.settings.giftcard_test_success' => ['en' => 'Signed GET, RSA public-key access, and local OAEP encryption passed. This test does not call redeemCode or consume a Gift Card.', 'th' => 'Signed GET การอ่าน RSA Public Key และการเข้ารหัส OAEP ภายในเซิร์ฟเวอร์ผ่านแล้ว การทดสอบนี้ไม่ได้เรียก redeemCode และไม่ใช้รหัส Gift Card'],
            'admin.settings.giftcard_error_local_rsa' => ['en' => 'The server can read Binance RSA key but cannot produce the required encrypted code.', 'th' => 'เซิร์ฟเวอร์อ่าน RSA Public Key ได้ แต่ไม่สามารถสร้างรหัสเข้ารหัสตามรูปแบบที่ Binance ต้องการ'],
            'admin.settings.giftcard_test_failed' => ['en' => 'Unable to verify the saved Gift Card API credentials.', 'th' => 'ไม่สามารถยืนยัน API Key สำหรับ Gift Card ที่บันทึกไว้'],
            'admin.settings.giftcard_error_mode' => ['en' => 'Select a valid API credential mode.', 'th' => 'กรุณาเลือกรูปแบบ API Key ที่ถูกต้อง'],
            'admin.settings.giftcard_error_roles' => ['en' => 'Enable Gift Card access for at least one account type.', 'th' => 'กรุณาเปิดให้ใช้งานอย่างน้อยหนึ่งประเภทบัญชี'],
            'admin.settings.giftcard_error_shared_credentials' => ['en' => 'The existing Binance TRC20 API credentials are incomplete.', 'th' => 'API Key ของ Binance TRC20 เดิมไม่ครบ'],
            'admin.settings.giftcard_error_credentials' => ['en' => 'The Gift Card API credentials are missing or invalid.', 'th' => 'API Key สำหรับ Gift Card ไม่ครบหรือรูปแบบไม่ถูกต้อง'],
            'admin.settings.giftcard_error_limits' => ['en' => 'Check the minimum, maximum, and daily USDT limits.', 'th' => 'กรุณาตรวจยอดขั้นต่ำ สูงสุด และวงเงินรายวัน'],
            'admin.settings.giftcard_error_credit_percent' => ['en' => 'Credit percentage must be between 1 and 100.', 'th' => 'เปอร์เซ็นต์เติมยอดต้องอยู่ระหว่าง 1 ถึง 100'],
            'admin.settings.giftcard_error_attempts' => ['en' => 'Attempt limits must be between 1 and 4.', 'th' => 'จำนวนครั้งที่อนุญาตต้องอยู่ระหว่าง 1 ถึง 4'],
            'admin.settings.giftcard_error_account_age' => ['en' => 'Account age must be between 0 and 365 days.', 'th' => 'อายุบัญชีต้องอยู่ระหว่าง 0 ถึง 365 วัน'],
            'admin.settings.giftcard_error_schema' => ['en' => 'The Gift Card database table could not be prepared.', 'th' => 'ไม่สามารถเตรียมตารางฐานข้อมูล Gift Card ได้'],
            'admin.settings.giftcard_error_clock' => ['en' => 'Server time differs from Binance. Synchronize the server clock.', 'th' => 'เวลาเซิร์ฟเวอร์ไม่ตรงกับ Binance กรุณาซิงก์เวลาเซิร์ฟเวอร์'],
            'admin.settings.giftcard_error_signature' => ['en' => 'Binance rejected the API signature. Check the secret and signing configuration.', 'th' => 'Binance ปฏิเสธลายเซ็น API กรุณาตรวจ Secret และการสร้างลายเซ็น'],
            'admin.settings.giftcard_error_provider_limit' => ['en' => 'Binance has temporarily blocked further invalid redemption attempts.', 'th' => 'Binance พักการลองรหัสผิดเพิ่มเติมชั่วคราว'],
            'admin.giftcard.title' => ['en' => 'Binance Gift Card Audit', 'th' => 'ตรวจสอบ Binance Gift Card'],
            'admin.giftcard.subtitle' => ['en' => 'Full redemption and credit audit. Full redemption codes and API secrets are never displayed.', 'th' => 'ตรวจรายการรับของขวัญและเติมยอดอย่างละเอียด โดยไม่แสดงรหัสเต็มหรือ API Secret'],
            'admin.giftcard.schema_error' => ['en' => 'The Gift Card audit table is unavailable. Import the supplied schema or check database permissions.', 'th' => 'ตารางตรวจสอบ Gift Card ไม่พร้อมใช้งาน กรุณานำเข้าไฟล์โครงสร้างหรือตรวจสิทธิ์ฐานข้อมูล'],
            'admin.giftcard.stats.total' => ['en' => 'All requests', 'th' => 'คำขอทั้งหมด'],
            'admin.giftcard.stats.completed' => ['en' => 'Credited', 'th' => 'เติมยอดแล้ว'],
            'admin.giftcard.stats.usdt' => ['en' => 'Redeemed USDT', 'th' => 'USDT ที่รับแล้ว'],
            'admin.giftcard.stats.credit' => ['en' => 'Website credit', 'th' => 'ยอดเติมเว็บไซต์'],
            'admin.giftcard.stats.review' => ['en' => 'Needs review', 'th' => 'รอตรวจสอบ'],
            'admin.giftcard.search_placeholder' => ['en' => 'Search username, support code, IDs, API result, or last 4 characters', 'th' => 'ค้นหาชื่อ รหัสตรวจสอบ ไอดี ผล API หรือท้ายรหัส 4 ตัว'],
            'admin.giftcard.filtered_count' => ['en' => 'Matching records', 'th' => 'รายการที่ตรงกับตัวกรอง'],
            'admin.giftcard.clear_filters' => ['en' => 'Clear filters and show latest records', 'th' => 'ล้างตัวกรองและแสดงรายการล่าสุด'],
            'admin.giftcard.list_fallback' => ['en' => 'The audit list used compatibility mode. Diagnostic:', 'th' => 'รายการตรวจสอบใช้โหมดรองรับฐานข้อมูลเดิม รหัสวินิจฉัย:'],
            'admin.giftcard.stage' => ['en' => 'Processing stage', 'th' => 'ขั้นตอนการทำงาน'],
            'admin.giftcard.all_statuses' => ['en' => 'All statuses', 'th' => 'ทุกสถานะ'],
            'admin.giftcard.empty' => ['en' => 'No Gift Card redemptions found.', 'th' => 'ไม่พบรายการรับ Gift Card'],
            'admin.giftcard.missing_user' => ['en' => 'User not found', 'th' => 'ไม่พบบัญชีผู้ใช้'],
            'admin.giftcard.reference' => ['en' => 'Reference number', 'th' => 'Reference Number'],
            'admin.giftcard.identity' => ['en' => 'Identity number', 'th' => 'Identity Number'],
            'admin.giftcard.transaction' => ['en' => 'Website transaction', 'th' => 'ธุรกรรมเว็บไซต์'],
            'admin.giftcard.api_result' => ['en' => 'Safe API result', 'th' => 'ผล API ที่ปลอดภัย'],
            'admin.giftcard.requested_at' => ['en' => 'Requested', 'th' => 'เวลาส่งรหัส'],
            'admin.giftcard.redeemed_at' => ['en' => 'Redeemed by Binance', 'th' => 'เวลาที่ Binance รับ'],
            'admin.giftcard.credited_at' => ['en' => 'Website credited', 'th' => 'เวลาเติมยอดเว็บไซต์'],
            'admin.giftcard.rate_percent' => ['en' => 'Rate / credit percentage', 'th' => 'เรต / เปอร์เซ็นต์เติมยอด'],
            'admin.giftcard.retry_credit' => ['en' => 'Retry website credit', 'th' => 'ดำเนินการเติมยอดเว็บไซต์อีกครั้ง'],
            'admin.giftcard.retry_confirm' => ['en' => 'Retry only after confirming Binance already accepted this Gift Card. Continue?', 'th' => 'ดำเนินการต่อเมื่อยืนยันแล้วว่า Binance รับ Gift Card นี้สำเร็จ ต้องการทำต่อหรือไม่'],
            'admin.giftcard.status.received' => ['en' => 'Request received', 'th' => 'รับคำขอแล้ว'],
            'admin.giftcard.status.preflight_error' => ['en' => 'Preflight error', 'th' => 'ตรวจสอบก่อนส่งไม่ผ่าน'],
            'admin.giftcard.status.redeeming' => ['en' => 'Redeeming', 'th' => 'กำลังรับของขวัญ'],
            'admin.giftcard.status.redeemed_pending_credit' => ['en' => 'Redeemed, pending credit', 'th' => 'รับแล้ว รอเติมยอด'],
            'admin.giftcard.status.completed' => ['en' => 'Completed', 'th' => 'สำเร็จ'],
            'admin.giftcard.status.invalid' => ['en' => 'Invalid code', 'th' => 'รหัสไม่ถูกต้อง'],
            'admin.giftcard.status.expired' => ['en' => 'Expired', 'th' => 'หมดอายุ'],
            'admin.giftcard.status.already_redeemed' => ['en' => 'Already redeemed', 'th' => 'ถูกใช้แล้ว'],
            'admin.giftcard.status.unsupported_token' => ['en' => 'Non-USDT token', 'th' => 'เหรียญไม่ใช่ USDT'],
            'admin.giftcard.status.amount_out_of_range' => ['en' => 'Amount needs review', 'th' => 'มูลค่าต้องตรวจสอบ'],
            'admin.giftcard.status.unknown_requires_review' => ['en' => 'Unknown provider outcome', 'th' => 'ไม่ทราบผลจาก Binance'],
            'admin.giftcard.status.provider_limit' => ['en' => 'Provider limit reached', 'th' => 'ถึงขีดจำกัด Binance'],
            'admin.giftcard.status.configuration_error' => ['en' => 'Configuration error', 'th' => 'การตั้งค่าผิดพลาด'],
            'admin.giftcard.status.rejected' => ['en' => 'Rejected', 'th' => 'ถูกปฏิเสธ'],
            'ranking.admin.status.disabled' => ['en' => 'Bonus disabled by policy', 'th' => 'ปิดโบนัสตามการตั้งค่า'],
            'users.btn.save' => ['en' => 'Save User', 'th' => 'บันทึกผู้ใช้'],
        ];
    }

    public static function t($key, $params = []) {
        self::init();
        $lang = getAppLang();
        $text = $key;
        if (isset(self::$translations[$key])) {
            $text = self::$translations[$key][$lang] ?? self::$translations[$key]['en'] ?? $key;
        }
        
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }
        
        return $text;
    }
}

/**
 * Get current application language
 */
function getAppLang()
{
    if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['th', 'en'], true)) {
        return $_SESSION['lang'];
    }
    // Fallback to cookie, but never trust arbitrary cookie values.
    if (isset($_COOKIE['app_lang']) && in_array($_COOKIE['app_lang'], ['th', 'en'], true)) {
        $_SESSION['lang'] = $_COOKIE['app_lang'];
        return $_COOKIE['app_lang'];
    }
    // Fallback to admin-configured default language (settings)
    $default = getSetting('default_language');
    if ($default === 'en' || $default === 'th') {
        return $default;
    }
    return 'th'; // Default
}

/**
 * Set application language
 */
function setAppLang($lang)
{
    $lang = ($lang === 'en') ? 'en' : 'th';
    $_SESSION['lang'] = $lang;
    $secure = function_exists('requestIsHttps') ? requestIsHttps() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('app_lang', $lang, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Format currency with dynamic conversion
 */
function formatCurrency($amount, $ignoreLangConversion = false)
{
    $amount = (float) $amount;
    if (!is_finite($amount)) {
        return 'N/A';
    }

    $baseCurrency = strtoupper(trim((string) (getSetting('currency_name') ?: 'THB')));
    if (!in_array($baseCurrency, ['THB', 'USD'], true)) {
        error_log('Unsupported store currency: ' . $baseCurrency . '. Only THB and USD are allowed.');
        return 'N/A';
    }
    $baseSymbol = $baseCurrency === 'THB' ? '฿' : '$';

    if ($ignoreLangConversion) {
        return $baseSymbol . number_format($amount, 2);
    }

    $currentLang = getAppLang();
    if ($currentLang === 'en') {
        if ($baseCurrency === 'USD') {
            return '$' . number_format($amount, 2);
        }
        $rate = getExchangeRateThbToUsd();
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return 'Rate unavailable';
        }
        return '$' . number_format($amount * (float) $rate, 2);
    }

    if ($baseCurrency === 'THB') {
        return '฿' . number_format($amount, 2);
    }
    $rate = getExchangeRateThbToUsd();
    if (!is_numeric($rate) || (float) $rate <= 0) {
        return 'อัตราแลกเปลี่ยนไม่พร้อมใช้งาน';
    }
    return '฿' . number_format($amount / (float) $rate, 2);
}

/**
 * Convert input amount to base currency (THB)
 */
function toBaseCurrency($amount)
{
    // Legacy helper. Amounts are now stored/entered in the configured currency.
    return (float) $amount;
}

/**
 * Check if key exists and is available
 */
function isKeyAvailable($keyId)
{
    $key = getKeyById($keyId);
    return $key && $key['status'] == 'available';
}

/**
 * Purchase key
 */
function purchaseKey($keyId, $userId)
{
    global $conn;
    $keyId = (int) $keyId;
    $userId = (int) $userId;
    if ($keyId < 1 || $userId < 1) {
        return ['success' => false, 'message' => 'Invalid purchase request'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'message' => 'Financial audit storage is unavailable'];
    }

    $conn->begin_transaction();
    try {
        $keyStmt = $conn->prepare(
            "SELECT k.*, p.name AS product_name
             FROM `keys` k
             JOIN products p ON p.id = k.product_id AND p.status = 'active'
             LEFT JOIN product_variants pv ON pv.id = k.variant_id
             WHERE k.id = ? AND k.status = 'available'
               AND (k.variant_id IS NULL OR pv.status = 'active')
             LIMIT 1 FOR UPDATE"
        );
        if (!$keyStmt) throw new RuntimeException('Unable to prepare key lookup');
        $keyStmt->bind_param('i', $keyId);
        if (!$keyStmt->execute()) {
            $keyStmt->close();
            throw new RuntimeException('Unable to read key');
        }
        $keyResult = $keyStmt->get_result();
        $key = $keyResult ? $keyResult->fetch_assoc() : null;
        $keyStmt->close();
        if (!$key) {
            throw new RuntimeException('Key is unavailable or its product is disabled');
        }

        $userStmt = $conn->prepare("SELECT id, role, balance, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        if (!$userStmt) throw new RuntimeException('Unable to prepare account lookup');
        $userStmt->bind_param('i', $userId);
        if (!$userStmt->execute()) {
            $userStmt->close();
            throw new RuntimeException('Unable to read account');
        }
        $userResult = $userStmt->get_result();
        $user = $userResult ? $userResult->fetch_assoc() : null;
        $userStmt->close();
        if (!$user || (string) $user['status'] !== 'active' || !in_array((string) $user['role'], ['user', 'reseller', 'admin'], true)) {
            throw new RuntimeException('Account is not active');
        }

        $price = (string) $user['role'] === 'reseller' ? (float) $key['price_reseller'] : (float) $key['price_user'];
        // A variant override belongs to the selected account ID. Normal users
        // keep price_user as their base; resellers keep price_reseller.
        if ((int) ($key['variant_id'] ?? 0) > 0) {
            if (!ensureResellerVariantPricesTable()) {
                throw new RuntimeException('Unable to read account pricing');
            }
            $price = (float) getEffectiveResellerPrice($userId, (int) $key['variant_id'], $price);
        }
        $price = round($price, 2);
        if (!is_finite($price) || $price < 0 || $price > 10000000) {
            throw new RuntimeException('Key price is invalid');
        }

        $balanceStmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND status = 'active' AND balance >= ?");
        if (!$balanceStmt) throw new RuntimeException('Unable to prepare balance update');
        $balanceStmt->bind_param('did', $price, $userId, $price);
        $balanceOk = $balanceStmt->execute() && $balanceStmt->affected_rows === 1;
        $balanceStmt->close();
        if (!$balanceOk) {
            throw new RuntimeException('Insufficient balance');
        }

        $keyUpdate = $conn->prepare("UPDATE `keys` SET status = 'sold', assigned_to = ?, purchased_by = ?, sold_at = NOW() WHERE id = ? AND status = 'available'");
        if (!$keyUpdate) throw new RuntimeException('Unable to prepare key update');
        $keyUpdate->bind_param('iii', $userId, $userId, $keyId);
        $keyUpdated = $keyUpdate->execute() && $keyUpdate->affected_rows === 1;
        $keyUpdate->close();
        if (!$keyUpdated) {
            throw new RuntimeException('Key was purchased by another customer');
        }

        $description = 'Purchased ' . (string) $key['product_name'] . ' key: ' . (string) $key['key_code'];
        $purchaseTransactionId = (int) createTransaction($userId, 'purchase', $price, 'completed', $description, $keyId);
        if ($purchaseTransactionId < 1) {
            throw new RuntimeException('Unable to record purchase');
        }
        $walletBefore = round((float) $user['balance'], 2);
        $walletAfter = round($walletBefore - $price, 2);
        if (!walletLedgerRecordMovement(
            $userId, -$price, $walletBefore, $walletAfter,
            'local_purchase', 'transaction:' . $purchaseTransactionId,
            $keyId, $purchaseTransactionId, null,
            'ซื้อสินค้า ' . (string) $key['product_name'],
            'Local key purchase', null, true
        )) throw new RuntimeException('Unable to record wallet purchase audit');
        if (!logHistory($userId, 'key_purchase', $description . ' for ' . formatCurrency($price))) {
            throw new RuntimeException('Unable to record purchase history');
        }

        $conn->commit();
        commerceCenterSyncSafe('local_purchase', $purchaseTransactionId);
        return ['success' => true, 'key' => (string) $key['key_code']];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $safeMessages = [
            'Invalid purchase request', 'Key is unavailable or its product is disabled',
            'Account is not active', 'Key price is invalid', 'Insufficient balance',
            'Key was purchased by another customer'
        ];
        $message = in_array($e->getMessage(), $safeMessages, true) ? $e->getMessage() : 'Purchase could not be completed';
        error_log('Single-key purchase failed: ' . $e->getMessage());
        return ['success' => false, 'message' => $message];
    }
}

/**
 * Validate an HTTPS URL and reject literal loopback/private/reserved IP targets.
 */
function isSafeHttpsUrl($url): bool
{
    if (!is_string($url) && !is_scalar($url)) {
        return false;
    }
    $url = trim((string) $url);
    if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return false;
    }
    // Userinfo in a URL is frequently used to disguise the real host and is not
    // required by any payment endpoint used by this application.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower(trim((string) $parts['host'], '[]'));
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) === 1;
}

/**
 * Configure cURL to accept only a bounded HTTPS response body.
 * This prevents a slow or malicious provider from exhausting PHP memory.
 */
function configureBoundedCurlResponse($ch, string &$response, bool &$tooLarge, int $maxBytes = 1048576): void
{
    $response = '';
    $tooLarge = false;
    $maxBytes = max(1024, min($maxBytes, 8 * 1024 * 1024));
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$response, &$tooLarge, $maxBytes) {
            $length = strlen($chunk);
            if (strlen($response) + $length > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $response .= $chunk;
            return $length;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
}

/**
 * Redeem Codes (top-up with one-time codes)
 * - Codes are 6 chars (A-Z0-9)
 * - Single-use
 * - 10 wrong attempts => user suspended
 */

function ensureRedeemCodesSchema()
{
    global $conn;
    static $schemaReady = false;

    if ($schemaReady) {
        return true;
    }

    $tableSql = "CREATE TABLE IF NOT EXISTS redeem_codes (
        code VARCHAR(6) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('unused','used') NOT NULL DEFAULT 'unused',
        created_by BIGINT UNSIGNED NULL,
        used_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (code),
        KEY idx_status (status),
        KEY idx_used_by (used_by),
        KEY idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$conn->query($tableSql)) {
        error_log('Unable to create redeem_codes table: ' . $conn->error);
        return false;
    }

    // Avoid running ALTER TABLE on every request. MySQL DDL can lock the table
    // and implicitly commit an active transaction, so only add the column when missing.
    $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'redeem_fail_count'");
    if (!$columnCheck) {
        error_log('Unable to inspect users.redeem_fail_count: ' . $conn->error);
        return false;
    }
    $columnExists = $columnCheck->num_rows > 0;
    $columnCheck->free();

    if (!$columnExists && !$conn->query("ALTER TABLE users ADD COLUMN redeem_fail_count INT NOT NULL DEFAULT 0")) {
        error_log('Unable to add users.redeem_fail_count: ' . $conn->error);
        return false;
    }

    $schemaReady = true;
    return true;
}

function generateRedeemCode($amount, $adminId = null)
{
    global $conn;
    if (!function_exists('isAdmin') || !isAdmin() || !function_exists('isUserActive') || !isUserActive()) {
        return ['success' => false, 'message' => 'Unauthorized'];
    }
    if (!ensureRedeemCodesSchema()) {
        return ['success' => false, 'message' => 'Redeem code storage is not available'];
    }

    $amount = round((float) $amount, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
        return ['success' => false, 'message' => 'Invalid amount'];
    }
    $adminIdValue = $adminId !== null ? (int) $adminId : null;
    if ($adminIdValue !== null && $adminIdValue < 1) {
        return ['success' => false, 'message' => 'Invalid administrator'];
    }

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $stmt = $conn->prepare("INSERT INTO redeem_codes (code, amount, status, created_by) VALUES (?, ?, 'unused', ?)");
    if (!$stmt) {
        error_log('Redeem code insert prepare failed');
        return ['success' => false, 'message' => 'Unable to create code'];
    }

    for ($tries = 0; $tries < 30; $tries++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt->bind_param('sdi', $code, $amount, $adminIdValue);
        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'code' => $code, 'amount' => $amount];
        }
        if ((int) $stmt->errno !== 1062) {
            error_log('Redeem code insert failed; errno=' . (int) $stmt->errno);
            $stmt->close();
            return ['success' => false, 'message' => 'Unable to create code'];
        }
    }
    $stmt->close();
    return ['success' => false, 'message' => 'Unable to create a unique code'];
}

function redeemTopupCode($userId, $codeRaw)
{
    global $conn;
    $stage = 'redeem_schema_check';
    $committed = false;
    $redeemTransactionId = 0;
    $walletAfter = null;

    if (!ensureRedeemCodesSchema()) {
        return ['success' => false, 'message' => 'Redeem code storage is not available'];
    }

    $userId = (int) $userId;
    $code = is_scalar($codeRaw) ? strtoupper(trim((string) $codeRaw)) : '';
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    if ($userId < 1) {
        return ['success' => false, 'message' => 'Invalid account'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'message' => 'Financial audit storage is unavailable'];
    }

    // Redeem credit must be representable in transactions.type before any wallet
    // mutation begins. Older ENUM schemas may accept "purchase" but reject
    // "redeem_code", which previously surfaced only as a generic rollback.
    $stage = 'transaction_type_schema_check';
    if (function_exists('transactionIntegrityTypeColumnInfo')
        && function_exists('transactionIntegrityTypeColumnSupports')) {
        $typeInfo = transactionIntegrityTypeColumnInfo(true);
        if (!transactionIntegrityTypeColumnSupports('redeem_code', $typeInfo)) {
            error_log('Redeem code blocked: transactions.type does not support redeem_code; run storefront schema maintenance');
            return ['success' => false, 'message' => 'Redeem service requires database maintenance'];
        }
    }

    if (strlen($code) !== 6) {
        return handleRedeemFail($userId, 'Invalid code format');
    }

    $stage = 'transaction_begin';
    $conn->begin_transaction();
    try {
        $stage = 'user_lock';
        $userStmt = $conn->prepare('SELECT id, status, balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$userStmt) throw new RuntimeException('prepare account lock failed');
        $userStmt->bind_param('i', $userId);
        if (!$userStmt->execute()) {
            $userStmt->close();
            throw new RuntimeException('account lock failed');
        }
        $userResult = $userStmt->get_result();
        $user = $userResult ? $userResult->fetch_assoc() : null;
        $userStmt->close();
        if (!$user) {
            $conn->rollback();
            return ['success' => false, 'message' => 'User not found'];
        }
        if ((string) $user['status'] !== 'active') {
            $conn->rollback();
            return ['success' => false, 'message' => 'Account not active'];
        }

        $stage = 'code_lock';
        $codeStmt = $conn->prepare('SELECT amount, status FROM redeem_codes WHERE code = ? LIMIT 1 FOR UPDATE');
        if (!$codeStmt) throw new RuntimeException('prepare code lock failed');
        $codeStmt->bind_param('s', $code);
        if (!$codeStmt->execute()) {
            $codeStmt->close();
            throw new RuntimeException('code lookup failed');
        }
        $codeResult = $codeStmt->get_result();
        $row = $codeResult ? $codeResult->fetch_assoc() : null;
        $codeStmt->close();
        if (!$row) {
            $conn->commit();
            return handleRedeemFail($userId, 'Code not found');
        }
        if ((string) $row['status'] !== 'unused') {
            $conn->commit();
            return handleRedeemFail($userId, 'Code already used');
        }

        $stage = 'amount_validation';
        $amount = round((float) $row['amount'], 2);
        if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
            throw new RuntimeException('invalid redeem amount');
        }

        $stage = 'code_mark_used';
        $markStmt = $conn->prepare("UPDATE redeem_codes SET status = 'used', used_by = ?, used_at = NOW() WHERE code = ? AND status = 'unused'");
        if (!$markStmt) throw new RuntimeException('prepare code update failed');
        $markStmt->bind_param('is', $userId, $code);
        $marked = $markStmt->execute() && $markStmt->affected_rows === 1;
        $markStmt->close();
        if (!$marked) throw new RuntimeException('code update conflict');

        $stage = 'balance_credit';
        $balanceStmt = $conn->prepare("UPDATE users SET balance = balance + ?, redeem_fail_count = 0 WHERE id = ? AND status = 'active'");
        if (!$balanceStmt) throw new RuntimeException('prepare balance update failed');
        $balanceStmt->bind_param('di', $amount, $userId);
        $credited = $balanceStmt->execute() && $balanceStmt->affected_rows === 1;
        $balanceStmt->close();
        if (!$credited) throw new RuntimeException('balance update failed');

        $maskedCode = '****' . substr($code, -2);
        $stage = 'transaction_insert';
        $redeemTransactionId = (int) createTransaction($userId, 'redeem_code', $amount, 'completed', 'Redeemed top-up code ' . $maskedCode);
        if ($redeemTransactionId < 1) throw new RuntimeException('transaction insert failed');

        $walletBefore = round((float) ($user['balance'] ?? 0), 2);
        $walletAfter = round($walletBefore + $amount, 2);
        $stage = 'wallet_ledger';
        if (!walletLedgerRecordMovement(
            $userId, $amount, $walletBefore, $walletAfter,
            'redeem_code', 'transaction:' . $redeemTransactionId,
            null, $redeemTransactionId, null,
            'เติมเงินด้วยโค้ด',
            'Redeem code credit; full code is intentionally not stored in wallet audit.',
            $maskedCode, true
        )) throw new RuntimeException('wallet audit insert failed');

        $stage = 'commit';
        if (!$conn->commit()) throw new RuntimeException('redeem commit failed');
        $committed = true;
    } catch (Throwable $e) {
        $dbErrno = (int) ($conn->errno ?? 0);
        $dbError = trim((string) ($conn->error ?? ''));
        if (!$committed) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
        try { $errorId = 'RDC-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))); }
        catch (Throwable $ignored) { $errorId = 'RDC-' . date('ymd-His'); }
        error_log(
            $errorId . ' Redeem code transaction failed; stage=' . $stage
            . '; message=' . $e->getMessage()
            . '; db_errno=' . $dbErrno
            . ($dbError !== '' ? '; db=' . substr($dbError, 0, 500) : '')
        );
        $publicMessage = 'Redeem could not be completed [' . $errorId . ']';
        if ((string) ($_SESSION['role'] ?? '') === 'admin') {
            $publicMessage .= ' / ' . $stage;
        }
        return [
            'success' => false,
            'message' => $publicMessage,
            'error_id' => $errorId,
            'stage' => $stage,
        ];
    }

    // History is secondary audit data. The authoritative wallet movement is the
    // committed transaction + wallet ledger, so a history-table fault must not
    // undo a successful credit.
    $stage = 'history_log';
    try {
        if (!logHistory($userId, 'redeem_code', 'Redeemed top-up code ' . $maskedCode . ' amount ' . number_format($amount, 2, '.', ''))) {
            error_log('Redeem code committed but history logging failed; TX #' . $redeemTransactionId);
        }
    } catch (Throwable $historyError) {
        error_log('Redeem code committed but history logging threw; TX #' . $redeemTransactionId . '; message=' . $historyError->getMessage());
    }

    if (isset($_SESSION) && (int) ($_SESSION['user_id'] ?? 0) === $userId && $walletAfter !== null) {
        $_SESSION['balance'] = $walletAfter;
    }
    return ['success' => true, 'amount' => $amount, 'message' => 'Redeem success: +' . formatCurrency($amount)];
}

function handleRedeemFail($userId, $message)
{
    global $conn;
    $userId = (int) $userId;
    $message = is_scalar($message) ? substr((string) $message, 0, 160) : 'Invalid code';
    if ($userId < 1) {
        return ['success' => false, 'message' => $message];
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT status, COALESCE(redeem_fail_count, 0) AS fail_count FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('prepare fail counter lock failed');
        $lock->bind_param('i', $userId);
        if (!$lock->execute()) {
            $lock->close();
            throw new RuntimeException('fail counter lock failed');
        }
        $result = $lock->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$user || (string) $user['status'] !== 'active') {
            $conn->rollback();
            return ['success' => false, 'message' => 'Account not active'];
        }

        $fails = min(10, max(0, (int) $user['fail_count']) + 1);
        $newStatus = $fails >= 10 ? 'banned' : 'active';
        $update = $conn->prepare('UPDATE users SET redeem_fail_count = ?, status = ? WHERE id = ? AND status = \'active\'');
        if (!$update) throw new RuntimeException('prepare fail counter update failed');
        $update->bind_param('isi', $fails, $newStatus, $userId);
        $updated = $update->execute() && $update->affected_rows === 1;
        $update->close();
        if (!$updated) throw new RuntimeException('fail counter update conflict');

        if ($fails >= 10 && !logHistory($userId, 'account_banned', 'Banned after 10 invalid top-up code attempts')) {
            throw new RuntimeException('ban history insert failed');
        }
        $conn->commit();

        if ($fails >= 10) {
            return ['success' => false, 'message' => 'Too many wrong codes. Account has been banned.'];
        }
        return ['success' => false, 'message' => $message . '. Attempts left: ' . (10 - $fails)];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Redeem failure counter update failed: ' . $e->getMessage());
        return ['success' => false, 'message' => $message];
    }
}


/**
 * PromptPay QR Code Generation System
 * Uses EasySlip Bill Payment API
 */

/**
 * Get PromptPay settings
 */
function getPromptPaySettings()
{
    return [
        'enabled' => getSetting('promptpay_enabled') === '1',
        'account_type' => getSetting('promptpay_account_type') ?: 'msisdn',
        'account_number' => getSetting('promptpay_account_number') ?: ''
    ];
}

/**
 * Save PromptPay settings
 */
function savePromptPaySettings($enabled, $accountType, $accountNumber, $clientId = null, $clientSecret = null, $receiverName = null)
{
    global $conn;

    $validTypes = ['msisdn', 'natId', 'eWalletId'];
    if (!in_array($accountType, $validTypes, true)) {
        return ['success' => false, 'message' => 'Invalid account type'];
    }

    $accountNumber = preg_replace('/[^0-9]/', '', (string) $accountNumber);
    if ($enabled && $accountNumber === '') {
        return ['success' => false, 'message' => 'PromptPay account number is required when enabled'];
    }

    if ($accountNumber !== '') {
        if ($accountType === 'msisdn' && (strlen($accountNumber) !== 10 || $accountNumber[0] !== '0')) {
            return ['success' => false, 'message' => 'Mobile number must be 10 digits starting with 0'];
        }
        if ($accountType === 'natId' && strlen($accountNumber) !== 13) {
            return ['success' => false, 'message' => 'National ID must be exactly 13 digits'];
        }
        if ($accountType === 'eWalletId' && strlen($accountNumber) !== 15) {
            return ['success' => false, 'message' => 'eWallet ID must be exactly 15 digits'];
        }
    }

    $clientId = $clientId === null ? null : trim((string) $clientId);
    $clientSecret = $clientSecret === null ? null : trim((string) $clientSecret);
    if ($clientId !== null && (strlen($clientId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $clientId))) {
        return ['success' => false, 'message' => 'SUBA Client ID format is invalid'];
    }
    if ($clientSecret !== null && (strlen($clientSecret) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $clientSecret))) {
        return ['success' => false, 'message' => 'SUBA Client Secret format is invalid'];
    }

    // Blank credentials preserve the existing secret instead of accidentally erasing it.
    $writes = [
        ['promptpay_enabled', $enabled ? '1' : '0'],
        ['promptpay_account_type', $accountType],
        ['promptpay_account_number', $accountNumber],
    ];
    if ($clientId !== null && $clientId !== '') {
        $writes[] = ['suba_client_id', $clientId];
    }
    if ($clientSecret !== null && $clientSecret !== '') {
        $writes[] = ['suba_client_secret', $clientSecret];
    }
    if ($receiverName !== null) {
        $writes[] = ['easyslip_receiver_name', trim((string) $receiverName)];
    }

    $conn->begin_transaction();
    try {
        foreach ($writes as $write) {
            if (!upsertSetting($write[0], $write[1])) {
                throw new RuntimeException('Unable to save setting: ' . $write[0]);
            }
        }
        $conn->commit();
        return ['success' => true, 'message' => 'PromptPay settings saved'];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('PromptPay settings update failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to save PromptPay settings'];
    }
}

/**
 * Upsert setting (insert if not exists, update if exists)
 * Robust version: checks existence first to handle tables without UNIQUE keys.
 */
function upsertSetting($key, $value)
{
    global $conn;
    $key = trim((string) $key);
    $value = is_scalar($value) || $value === null ? (string) $value : '';
    if ($key === '' || strlen($key) > 190 || strlen($value) > 65535) {
        return false;
    }

    $check = $conn->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1');
    if (!$check) {
        return false;
    }
    $check->bind_param('s', $key);
    if (!$check->execute() || !$check->store_result()) {
        $check->close();
        return false;
    }
    $exists = $check->num_rows > 0;
    $check->close();

    if ($exists) {
        $stmt = $conn->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $value, $key);
    } else {
        $stmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $key, $value);
    }

    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        if (!isset($GLOBALS['__settings_request_cache']) || !is_array($GLOBALS['__settings_request_cache'])) {
            $GLOBALS['__settings_request_cache'] = [];
        }
        $GLOBALS['__settings_request_cache'][$key] = ['found' => true, 'value' => $value];
    }
    return $ok;
}

/**
 * Generate PromptPay QR Code using EasySlip Bill Payment API
 * @param float|null $amount Payment amount (optional)
 * @return array ['success' => bool, 'qr_image' => string, 'payload' => string, 'message' => string]
 */
function generatePromptPayQR($amount = null)
{
    $settings = getPromptPaySettings();

    if (empty($settings['enabled'])) {
        return ['success' => false, 'message' => 'PromptPay is not enabled'];
    }

    $accountType = (string) ($settings['account_type'] ?? '');
    $accountNumber = preg_replace('/\D+/', '', (string) ($settings['account_number'] ?? ''));
    if (!in_array($accountType, ['msisdn', 'natId', 'eWalletId'], true) || $accountNumber === '') {
        return ['success' => false, 'message' => 'PromptPay account is not configured correctly'];
    }

    $postData = ['type' => 'PROMPTPAY', $accountType => $accountNumber];
    if ($amount !== null) {
        $amountValue = round((float) $amount, 2);
        if (!is_finite($amountValue) || $amountValue <= 0 || $amountValue > 10000000) {
            return ['success' => false, 'message' => 'Invalid payment amount'];
        }
        $postData['amount'] = $amountValue;
    } else {
        $amountValue = null;
    }

    $payload = json_encode($postData, JSON_UNESCAPED_SLASHES);
    if ($payload === false || !function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Payment QR service is unavailable'];
    }

    $response = '';
    $tooLarge = false;
    $ch = curl_init('https://bill-payment-api.easyslip.com/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'SakazukiPromptPay/1.0',
    ]);
    configureBoundedCurlResponse($ch, $response, $tooLarge, 2 * 1024 * 1024);
    $executed = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNo = curl_errno($ch);
    curl_close($ch);

    if ($executed === false || $curlErrorNo !== 0 || $tooLarge) {
        error_log('PromptPay QR request failed; curl=' . $curlErrorNo . '; oversized=' . ($tooLarge ? '1' : '0'));
        return ['success' => false, 'message' => 'Payment QR service connection failed'];
    }
    if ($httpCode !== 200) {
        error_log('PromptPay QR API rejected request; HTTP ' . $httpCode);
        return ['success' => false, 'message' => 'Payment QR service rejected the request'];
    }

    $result = json_decode($response, true, 32);
    if (!is_array($result) || !isset($result['image_base64']) || !is_string($result['image_base64'])) {
        return ['success' => false, 'message' => 'Invalid response from payment QR service'];
    }
    $imageBase64 = trim($result['image_base64']);
    if ($imageBase64 === '' || strlen($imageBase64) > 1500000) {
        return ['success' => false, 'message' => 'Invalid QR image returned by payment service'];
    }
    $encodedImage = preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', $imageBase64);
    $decodedImage = is_string($encodedImage) ? base64_decode($encodedImage, true) : false;
    if ($decodedImage === false || strlen($decodedImage) < 16 || strlen($decodedImage) > 1024 * 1024) {
        return ['success' => false, 'message' => 'Invalid QR image returned by payment service'];
    }
    $mime = isset($result['mime']) && is_string($result['mime']) && in_array(strtolower($result['mime']), ['image/png', 'image/jpeg'], true)
        ? strtolower($result['mime'])
        : 'image/png';

    return [
        'success' => true,
        'qr_image' => $imageBase64,
        'mime' => $mime,
        'payload' => isset($result['payload']) && is_scalar($result['payload']) ? substr((string) $result['payload'], 0, 2048) : '',
        'amount' => $amountValue,
    ];
}

/**
 * ========================================
 * Slip Verification System (EasySlip API)
 * ========================================
 */

/**
 * Get EasySlip API settings
 */
function getEasySlipSettings()
{
    return [
        'api_key' => getSetting('easyslip_api_key') ?: '',
        'receiver_account' => getSetting('easyslip_account_number') ?: getSetting('promptpay_account_number') ?: '',
        'receiver_phone' => getSetting('easyslip_phone') ?: '',
        'receiver_name' => getSetting('easyslip_receiver_name') ?: '',
        'receiver_name_en' => getSetting('easyslip_receiver_name_en') ?: ''
    ];
}

/**
 * Get Slip Verify settings
 */
function getSlipVerifySettings()
{
    return [
        'client_id' => getSetting('suba_client_id') ?: '',
        'client_secret' => getSetting('suba_client_secret') ?: '',
        'receiver_account' => getSetting('promptpay_account_number') ?: '',
        'receiver_name' => getSetting('easyslip_receiver_name') ?: ''
    ];
}

/**
 * Verify slip with Suba Slip Verify API
 * @param string $imageBase64 Base64 encoded slip image
 * @return array [success, data (transaction details), message]
 */
function verifySlipWithSuba($imageBase64)
{
    $settings = getSlipVerifySettings();

    if (empty($settings['client_id']) || empty($settings['client_secret'])) {
        return ['success' => false, 'message' => 'Slip Verify credentials not configured'];
    }

    // Decode and validate a bounded image payload before writing a temporary file.
    if (!is_string($imageBase64) || strlen($imageBase64) > 6 * 1024 * 1024 ||
        !preg_match('/^data:image\/(jpeg|jpg|png);base64,/i', $imageBase64, $type)) {
        return ['success' => false, 'message' => 'Invalid image data'];
    }
    $data = substr($imageBase64, strpos($imageBase64, ',') + 1);
    $type = strtolower($type[1]);
    $contentType = 'image/' . ($type === 'jpg' ? 'jpeg' : $type);
    $imageBin = base64_decode($data, true);
    if ($imageBin === false || strlen($imageBin) < 1 || strlen($imageBin) > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Invalid image data'];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_buffer($finfo, $imageBin) : false;
        if ($finfo) finfo_close($finfo);
        if ($detectedMime !== false && !in_array($detectedMime, ['image/jpeg', 'image/png'], true)) {
            return ['success' => false, 'message' => 'Invalid image format'];
        }
    }

    $apiUrl = 'https://suba.rdcw.co.th/v2/inquiry';

    $auth = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);

    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        return ['success' => false, 'message' => 'ระบบตรวจสอบสลิปไม่พร้อมใช้งาน'];
    }
    $ch = curl_init($apiUrl);

    // Create a temporary file for the binary data to use with CURLFile
    $tempDir = sys_get_temp_dir();
    $tmpFile = @tempnam($tempDir, 'slip_');

    // Fallback to local uploads directory if system temp fails
    if (!$tmpFile) {
        $localDir = __DIR__ . '/../assets/uploads';
        if (!is_dir($localDir)) {
            @mkdir($localDir, 0700, true);
        }
        $tmpFile = @tempnam($localDir, 'slip_');
    }

    if (!$tmpFile) {
        return ['success' => false, 'message' => 'ไม่สามารถสร้างไฟล์ชั่วคราวได้'];
    }

    if (file_put_contents($tmpFile, $imageBin, LOCK_EX) === false) {
        @unlink($tmpFile);
        return ['success' => false, 'message' => 'ไม่สามารถเขียนไฟล์ชั่วคราวได้'];
    }
    @chmod($tmpFile, 0600);

    $postData = [
        'file' => new CURLFile($tmpFile, $contentType, 'slip.' . ($type == 'png' ? 'png' : 'jpg'))
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $auth, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'SakazukiSlipVerify/1.0',
    ]);
    $response = '';
    $tooLarge = false;
    configureBoundedCurlResponse($ch, $response, $tooLarge, 1024 * 1024);
    $executed = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNo = curl_errno($ch);
    @unlink($tmpFile);
    curl_close($ch);

    if ($executed === false || $curlErrorNo !== 0 || $tooLarge) {
        error_log('Suba connection failed; curl=' . $curlErrorNo . '; oversized=' . ($tooLarge ? '1' : '0'));
        return ['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อระบบตรวจสอบสลิปได้'];
    }

    $result = json_decode($response, true, 32);

    // Debugging (optional, results will show in error_log if it's configured)
    if ($httpCode !== 200 || !isset($result['data'])) {
        error_log('Suba API Error: HTTP ' . $httpCode);
    }

    if ($httpCode !== 200 || !is_array($result)) {
        $providerCode = is_array($result) && isset($result['code']) && is_scalar($result['code']) ? substr((string) $result['code'], 0, 40) : 'unknown';
        error_log('Suba verification rejected; HTTP ' . $httpCode . '; code=' . $providerCode);
        return ['success' => false, 'message' => 'ระบบตรวจสอบสลิปปฏิเสธรายการ กรุณาตรวจสอบรูปแล้วลองใหม่'];
    }

    // Map response data
    // Assuming response contains 'valid' and transaction data
    // If exact structure is unknown, we map common fields and include raw_response

    $data = $result['data'] ?? $result; // Fallback if data is at root

    return [
        'success' => true,
        'data' => [
            'transaction_ref' => $data['transRef'] ?? ($data['trans_ref'] ?? ''),
            'amount' => (float) ($data['amount']['amount'] ?? ($data['amount'] ?? 0)),
            'sender_name' => $data['sender']['name'] ?? ($data['sender']['displayName'] ?? ''),
            'sender_account' => $data['sender']['account']['value'] ?? ($data['sender']['accountNumber'] ?? ''),
            'receiver_name' => $data['receiver']['name'] ?? ($data['receiver']['displayName'] ?? ''),
            'receiver_account' => $data['receiver']['account']['value'] ?? ($data['receiver']['accountNumber'] ?? ''),
            'bank_code' => $data['sendingBank'] ?? '',
            'transfer_date' => $data['transDate'] ?? ($data['date'] ?? '')
        ]
    ];
}

/**
 * Legacy database-scoped remark retained only for recovering requests created
 * before the two managed websites were treated as one merchant.
 */
function getLegacyEasySlipVerificationRemark(): string
{
    $dbName = defined('DB_NAME') ? (string) DB_NAME : '';
    $dbUser = defined('DB_USER') ? (string) DB_USER : '';
    $pathHint = dirname(__DIR__);
    return 'sakazuki:' . substr(hash('sha256', $dbName . '|' . $dbUser . '|' . $pathHint), 0, 32);
}

function getEasySlipMerchantIdentity(): string
{
    $settings = getEasySlipSettings();
    $digits = static function ($value): string {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    };
    $name = static function ($value): string {
        $value = (string) $value;
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = trim($value);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?: '';
    };

    // Both managed storefronts receive money into the same merchant account.
    // This fingerprint contains no raw account/name data and is stable across
    // deployments. It identifies the merchant namespace, not a single request.
    $merchantSeed = implode('|', [
        $digits($settings['receiver_account'] ?? ''),
        $digits($settings['receiver_phone'] ?? ''),
        $name($settings['receiver_name'] ?? ''),
        $name($settings['receiver_name_en'] ?? ''),
    ]);
    if (str_replace('|', '', $merchantSeed) !== '') {
        return hash('sha256', $merchantSeed);
    }
    return hash('sha256', getLegacyEasySlipVerificationRemark());
}

function getEasySlipRemarkSigningKey(): ?string
{
    // The Store Bridge key is already shared by the two managed storefronts and
    // kept outside public_html. Reusing it only as an HMAC key lets either site
    // prove that a duplicate EasySlip remark was generated by our own system.
    // No secret material is ever placed in the remark itself.
    if (!function_exists('storeBridgeEncryptionKey')) return null;
    try {
        $key = storeBridgeEncryptionKey(false);
    } catch (Throwable $e) {
        return null;
    }
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

function getEasySlipVerificationRemark(string $attemptUuid = '', int $userId = 0): string
{
    $merchantId = getEasySlipMerchantIdentity();
    $attemptUuid = normalizeSlipVerificationAttemptUuid($attemptUuid);
    $userId = (int) $userId;
    $key = getEasySlipRemarkSigningKey();

    if ($attemptUuid !== '' && $userId > 0 && $key !== null) {
        $merchantPrefix = substr($merchantId, 0, 16);
        $payload = 'easyslip-v2|' . $merchantPrefix . '|' . $attemptUuid . '|' . $userId;
        $signature = substr(hash_hmac('sha256', $payload, $key), 0, 32);
        return 'ez2:' . $merchantPrefix . ':' . $attemptUuid . ':' . $signature;
    }

    // Compatibility only. This value is deliberately NOT accepted as strong
    // proof for new provider-duplicate recovery because it is merchant-scoped,
    // not request-scoped and signed.
    return 'merchant:' . substr($merchantId, 0, 32);
}

function validateEasySlipVerificationRemark(string $remark, int $userId): array
{
    $remark = trim($remark);
    $userId = (int) $userId;
    $result = [
        'trusted' => false,
        'version' => '',
        'attempt_id' => '',
        'merchant_match' => false,
        'signature_match' => false,
    ];
    if ($remark === '' || $userId < 1) return $result;

    if (!preg_match('/^ez2:([a-f0-9]{16}):([a-f0-9-]{36}):([a-f0-9]{32})$/D', strtolower($remark), $m)) {
        return $result;
    }
    $attemptUuid = normalizeSlipVerificationAttemptUuid($m[2]);
    if ($attemptUuid === '') return $result;

    $expectedMerchant = substr(getEasySlipMerchantIdentity(), 0, 16);
    $merchantMatch = hash_equals($expectedMerchant, $m[1]);
    $key = getEasySlipRemarkSigningKey();
    $signatureMatch = false;
    if ($merchantMatch && $key !== null) {
        $payload = 'easyslip-v2|' . $expectedMerchant . '|' . $attemptUuid . '|' . $userId;
        $expectedSignature = substr(hash_hmac('sha256', $payload, $key), 0, 32);
        $signatureMatch = hash_equals($expectedSignature, $m[3]);
    }

    return [
        'trusted' => $merchantMatch && $signatureMatch,
        'version' => 'ez2',
        'attempt_id' => $attemptUuid,
        'merchant_match' => $merchantMatch,
        'signature_match' => $signatureMatch,
    ];
}


function slipProviderPolicyInt(string $settingKey, int $default, int $min, int $max): int
{
    $raw = trim((string) getSetting($settingKey, (string) $default));
    $value = preg_match('/^-?\d+$/D', $raw) === 1 ? (int) $raw : $default;
    return max($min, min($max, $value));
}

function slipProviderPolicy(): array
{
    return [
        // Production evidence showed EasySlip recording FOUND roughly eighteen
        // seconds after our request started even though the website had already
        // stopped waiting at seven seconds. Use one long authoritative request
        // instead of manufacturing a provider duplicate with an immediate retry.
        // The endpoint-wide deadline still caps the complete customer flow below
        // one minute and reserves time for the financial transaction afterwards.
        'primary_timeout_ms' => slipProviderPolicyInt('easyslip_primary_timeout_ms', 40000, 25000, 40000),
        'payload_timeout_ms' => slipProviderPolicyInt('easyslip_payload_timeout_ms', 40000, 25000, 40000),
        // Route fallback is only for failures where the request body was NOT fully
        // uploaded. It never follows an ambiguous full-body provider timeout.
        'fallback_timeout_ms' => slipProviderPolicyInt('easyslip_ipv4_fallback_timeout_ms', 6000, 1500, 8000),
        'connect_timeout_ms' => slipProviderPolicyInt('easyslip_connect_timeout_ms', 1500, 500, 3000),
        'retry_cooldown_seconds' => slipProviderPolicyInt('easyslip_retry_cooldown_seconds', 20, 5, 300),
        // EasySlip/edge 5xx and 429 are definitive HTTP responses, unlike a
        // zero-byte timeout after the whole request body was uploaded. Retry a
        // small bounded number of those transient statuses inside the same
        // customer submission, reusing the exact payload + signed remark.
        'transient_max_attempts' => slipProviderPolicyInt('easyslip_transient_max_attempts', 3, 1, 3),
        'transient_backoff_first_ms' => slipProviderPolicyInt('easyslip_transient_backoff_first_ms', 3000, 1000, 10000),
        'transient_backoff_second_ms' => slipProviderPolicyInt('easyslip_transient_backoff_second_ms', 10000, 2000, 15000),
        // Full-body + zero-byte timeout is an ambiguous outcome, not proof the
        // provider failed. A short same-slip cooldown allows the next request to
        // recover EasySlip's cached duplicate result without creating a storm.
        'outcome_unknown_retry_seconds' => slipProviderPolicyInt('easyslip_outcome_unknown_retry_seconds', 15, 5, 60),
        'breaker_window_seconds' => slipProviderPolicyInt('easyslip_breaker_window_seconds', 60, 20, 300),
        'breaker_failure_threshold' => slipProviderPolicyInt('easyslip_breaker_failure_threshold', 3, 2, 20),
        'breaker_open_seconds' => slipProviderPolicyInt('easyslip_breaker_open_seconds', 30, 10, 300),
        'breaker_half_open_seconds' => slipProviderPolicyInt('easyslip_breaker_half_open_seconds', 10, 5, 60),
        // A breaker reacts only after failures return. Bound in-flight operations
        // as well so 20 simultaneous customers cannot all create two upstream
        // calls before the first timeout has a chance to open the breaker.
        'max_concurrent_operations' => slipProviderPolicyInt('easyslip_max_concurrent_operations', 6, 1, 12),
    ];
}

function slipVerificationDeadlineRemainingMs(float $deadline): int
{
    if ($deadline <= 0) return PHP_INT_MAX;
    return max(0, (int) floor(($deadline - microtime(true)) * 1000));
}

function slipProviderTransientHttpCode(int $httpCode): bool
{
    return in_array($httpCode, [408, 429, 500, 502, 503, 504], true);
}

/**
 * cURL can leave the last HTTP code at an informational 1xx response when the
 * provider accepted the request body but never returned a final response before
 * our timeout. HTTP 100 Continue is the production case we have observed.
 */
function slipProviderHttpCodeIsInformational(int $httpCode): bool
{
    return $httpCode >= 100 && $httpCode < 200;
}

/**
 * A timeout after the complete request body was uploaded has an unknown provider
 * outcome when no final HTTP response was received. Treat both code 0 (no HTTP
 * response at all) and informational 1xx codes as non-final responses.
 */
function slipProviderAttemptIsAmbiguousFullUploadTimeout(array $attempt): bool
{
    $httpCode = (int) ($attempt['http_code'] ?? 0);
    return (int) ($attempt['curl_errno'] ?? 0) === CURLE_OPERATION_TIMEDOUT
        && ($httpCode === 0 || slipProviderHttpCodeIsInformational($httpCode))
        && strlen((string) ($attempt['response'] ?? '')) === 0
        && !empty($attempt['body_fully_uploaded']);
}

function slipProviderTransientErrorCode(int $httpCode, string $providerCode = ''): string
{
    $providerCode = strtoupper(trim($providerCode));
    if ($httpCode === 429) return 'provider_rate_limited';
    if ($httpCode === 500 && $providerCode === 'API_SERVER_ERROR') return 'provider_api_server_error';
    if ($httpCode === 500) return 'provider_server_error';
    if ($httpCode === 502) return 'provider_bad_gateway';
    if ($httpCode === 503) return 'provider_service_unavailable';
    if ($httpCode === 504) return 'provider_gateway_timeout';
    if ($httpCode === 408) return 'provider_request_timeout';
    return 'provider_http_unavailable';
}

function slipProviderRetryAfterSeconds(array $headers, int $defaultSeconds = 0, int $maxSeconds = 300): int
{
    $maxSeconds = max(1, min(3600, $maxSeconds));
    $raw = trim((string) ($headers['retry-after'] ?? ''));
    if ($raw !== '') {
        if (preg_match('/^\d+$/D', $raw) === 1) {
            return max(0, min($maxSeconds, (int) $raw));
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return max(0, min($maxSeconds, $ts - time()));
        }
    }
    return max(0, min($maxSeconds, $defaultSeconds));
}

function slipProviderIpFamily(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return 'IPv4';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return 'IPv6';
    return '';
}

/**
 * Limit simultaneously in-flight EasySlip operations across PHP workers. MySQL
 * named locks are server-wide, automatically released if a worker dies, and do
 * not require another schema migration. The API-key fingerprint makes sibling
 * sites share the same slots when they use the same MySQL server + EasySlip key.
 */
function slipProviderAcquireConcurrencySlot(string $apiKey): array
{
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return ['success' => true, 'degraded' => true, 'slot_name' => ''];
    $limit = (int) slipProviderPolicy()['max_concurrent_operations'];
    $fingerprint = substr(hash('sha256', $apiKey), 0, 16);
    $sawValidResult = false;
    for ($slot = 0; $slot < $limit; $slot++) {
        $name = 'slip:easyslip:' . $fingerprint . ':' . $slot;
        $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        if (!$stmt) return ['success' => true, 'degraded' => true, 'slot_name' => ''];
        $stmt->bind_param('s', $name);
        $ok = $stmt->execute();
        $result = $ok ? $stmt->get_result() : false;
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$ok || !is_array($row) || !array_key_exists('acquired', $row) || $row['acquired'] === null) {
            // Concurrency protection is a resilience layer, not a financial
            // authority. A MySQL feature/permission problem must not turn into a
            // new site-wide payment outage.
            return ['success' => true, 'degraded' => true, 'slot_name' => ''];
        }
        $sawValidResult = true;
        if ((int) $row['acquired'] === 1) {
            return ['success' => true, 'degraded' => false, 'slot_name' => $name, 'slot' => $slot, 'limit' => $limit];
        }
    }
    return [
        'success' => false,
        'busy' => $sawValidResult,
        'retry_after_seconds' => 2,
        'limit' => $limit,
    ];
}

function slipProviderReleaseConcurrencySlot(string $slotName): void
{
    global $conn;
    $slotName = trim($slotName);
    if ($slotName === '' || !isset($conn) || !($conn instanceof mysqli)) return;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if (!$stmt) return;
    $stmt->bind_param('s', $slotName);
    $stmt->execute();
    $stmt->close();
}

/**
 * Acquire one provider-operation permit. During an open breaker no upstream call
 * is made. When the open interval expires, exactly one PHP worker receives a
 * short half-open probe lease; other workers return immediately instead of
 * stampeding EasySlip.
 */
function slipProviderHealthPermit(bool $diagnosticOnly = false): array
{
    global $conn;
    if ($diagnosticOnly) return ['allowed' => true, 'diagnostic' => true];
    if (!ensureSlipProviderHealthTable()) return ['allowed' => true, 'degraded_health_storage' => true];
    $policy = slipProviderPolicy();
    try {
        $conn->begin_transaction();
        $result = $conn->query("SELECT * FROM slip_provider_health WHERE provider='easyslip' LIMIT 1 FOR UPDATE");
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        if (!$row) {
            $conn->query("INSERT IGNORE INTO slip_provider_health (provider, window_started_at) VALUES ('easyslip', NOW(6))");
            $result = $conn->query("SELECT * FROM slip_provider_health WHERE provider='easyslip' LIMIT 1 FOR UPDATE");
            $row = $result ? $result->fetch_assoc() : [];
            if ($result) $result->free();
        }
        $now = microtime(true);
        $openUntil = !empty($row['breaker_open_until']) ? strtotime((string) $row['breaker_open_until']) : false;
        $halfOpenUntil = !empty($row['half_open_until']) ? strtotime((string) $row['half_open_until']) : false;
        if ($openUntil !== false && $openUntil > $now) {
            $conn->commit();
            return [
                'allowed' => false,
                'breaker_open' => true,
                'retry_after_seconds' => max(1, (int) ceil($openUntil - $now)),
                'open_until' => (string) $row['breaker_open_until'],
            ];
        }
        if ($openUntil !== false && $openUntil <= $now) {
            // The first request after the open interval owns a bounded half-open
            // probe. Set open_until NULL and a probe lease atomically under lock.
            if ($halfOpenUntil !== false && $halfOpenUntil > $now) {
                $conn->commit();
                return [
                    'allowed' => false,
                    'breaker_open' => true,
                    'half_open_busy' => true,
                    'retry_after_seconds' => max(1, (int) ceil($halfOpenUntil - $now)),
                ];
            }
            $halfSeconds = (int) $policy['breaker_half_open_seconds'];
            $stmt = $conn->prepare("UPDATE slip_provider_health SET breaker_open_until=NULL, half_open_until=DATE_ADD(NOW(6), INTERVAL ? SECOND), updated_at=NOW(6) WHERE provider='easyslip'");
            if ($stmt) {
                $stmt->bind_param('i', $halfSeconds);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
            return ['allowed' => true, 'half_open' => true];
        }
        if ($halfOpenUntil !== false && $halfOpenUntil > $now) {
            $conn->commit();
            return [
                'allowed' => false,
                'breaker_open' => true,
                'half_open_busy' => true,
                'retry_after_seconds' => max(1, (int) ceil($halfOpenUntil - $now)),
            ];
        }
        // Stale probe leases are harmless once no breaker is open.
        if ($halfOpenUntil !== false && $halfOpenUntil <= $now) {
            $conn->query("UPDATE slip_provider_health SET half_open_until=NULL WHERE provider='easyslip'");
        }
        $conn->commit();
        return ['allowed' => true, 'half_open' => false];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Slip provider breaker permit failed: ' . $e->getMessage());
        // Provider-health telemetry must never become a new global outage. The
        // financial dedupe path still fails closed independently.
        return ['allowed' => true, 'degraded_health_storage' => true];
    }
}

function slipProviderHealthRecord(array $outcome, bool $diagnosticOnly = false): void
{
    global $conn;
    if ($diagnosticOnly || !ensureSlipProviderHealthTable()) return;
    $policy = slipProviderPolicy();
    $responsive = !empty($outcome['responsive']);
    $availabilityFailure = !empty($outcome['availability_failure']);
    $zeroByteTimeout = !empty($outcome['zero_byte_timeout']);
    $latencyMs = max(0, min(2147483647, (int) ($outcome['latency_ms'] ?? 0)));
    $transportClass = substr(trim((string) ($outcome['transport_class'] ?? '')), 0, 32);
    $httpCode = max(0, min(999, (int) ($outcome['http_code'] ?? 0)));
    $ipFamily = in_array((string) ($outcome['ip_family'] ?? ''), ['IPv4', 'IPv6'], true) ? (string) $outcome['ip_family'] : '';
    try {
        $conn->begin_transaction();
        $result = $conn->query("SELECT * FROM slip_provider_health WHERE provider='easyslip' LIMIT 1 FOR UPDATE");
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        if (!$row) {
            $conn->query("INSERT IGNORE INTO slip_provider_health (provider, window_started_at) VALUES ('easyslip', NOW(6))");
            $result = $conn->query("SELECT * FROM slip_provider_health WHERE provider='easyslip' LIMIT 1 FOR UPDATE");
            $row = $result ? $result->fetch_assoc() : [];
            if ($result) $result->free();
        }
        $windowStart = !empty($row['window_started_at']) ? strtotime((string) $row['window_started_at']) : false;
        $windowExpired = $windowStart === false || $windowStart < time() - (int) $policy['breaker_window_seconds'];
        $requests = $windowExpired ? 0 : (int) ($row['requests_window'] ?? 0);
        $timeouts = $windowExpired ? 0 : (int) ($row['zero_byte_timeouts_window'] ?? 0);
        $successes = $windowExpired ? 0 : (int) ($row['successes_window'] ?? 0);
        $consecutive = (int) ($row['consecutive_transport_failures'] ?? 0);
        $requests++;
        if ($responsive) {
            $successes++;
            $consecutive = 0;
        } elseif ($availabilityFailure) {
            $consecutive++;
            if ($zeroByteTimeout) $timeouts++;
        } else {
            // A deterministic provider rejection still proves the service is
            // responsive and should recover a half-open breaker.
            $successes++;
            $consecutive = 0;
        }
        $threshold = (int) $policy['breaker_failure_threshold'];
        $open = $availabilityFailure && ($consecutive >= $threshold || $timeouts >= $threshold);
        $openSeconds = (int) $policy['breaker_open_seconds'];
        $windowStartedSql = $windowExpired ? 'NOW(6)' : 'window_started_at';
        $stmt = $conn->prepare(
            "UPDATE slip_provider_health SET
             window_started_at={$windowStartedSql}, requests_window=?, zero_byte_timeouts_window=?, successes_window=?,
             consecutive_transport_failures=?,
             breaker_open_until=" . ($open ? 'DATE_ADD(NOW(6), INTERVAL ? SECOND)' : 'NULL') . ",
             half_open_until=NULL,
             last_success_at=" . ($responsive || !$availabilityFailure ? 'NOW(6)' : 'last_success_at') . ",
             last_failure_at=" . ($availabilityFailure ? 'NOW(6)' : 'last_failure_at') . ",
             last_latency_ms=?, last_transport_class=?, last_http_code=?, last_ip_family=?, updated_at=NOW(6)
             WHERE provider='easyslip'"
        );
        if ($stmt) {
            if ($open) {
                $stmt->bind_param('iiiiiisis', $requests, $timeouts, $successes, $consecutive, $openSeconds, $latencyMs, $transportClass, $httpCode, $ipFamily);
            } else {
                $stmt->bind_param('iiiiisis', $requests, $timeouts, $successes, $consecutive, $latencyMs, $transportClass, $httpCode, $ipFamily);
            }
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Slip provider breaker state update failed: ' . $e->getMessage());
    }
}

/**
 * Verify slip with EasySlip API v2 (Base64 JSON mode)
 * Endpoint: POST https://api.easyslip.com/v2/verify/bank
 * Content-Type: application/json
 * @param string $imageBase64 Base64 encoded slip image (data:image/...;base64,... or raw base64)
 * @return array [success, data (transaction details), message]
 */
function verifySlipWithEasyslip($imageBase64, string $remark = '', array $debugContext = [])
{
    $settings = getEasySlipSettings();
    $apiKey = trim($settings['api_key'] ?? '');
    $attemptUuid = slipDebugNormalizeUuid($debugContext['attempt_uuid'] ?? '');
    $slipHash = slipDebugNormalizeHash($debugContext['slip_hash'] ?? '');
    $userId = max(0, (int) ($debugContext['user_id'] ?? 0));
    $debugBase = [
        'attempt_uuid' => $attemptUuid,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
    ];
    $log = static function (string $stage, string $severity, string $message, array $details = []) use ($debugBase): void {
        slipDebugLogEvent($stage, $severity, $message, array_merge($debugBase, $details));
    };

    if (empty($apiKey)) {
        $log('provider_preflight_failed', 'error', 'EasySlip API key is not configured', [
            'error_code' => 'missing_api_key',
            'context' => ['api_key_configured' => false, 'clock' => slipDebugClockSnapshot()],
        ]);
        return ['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า EasySlip API Key กรุณาตั้งค่าในหน้า Admin Settings'];
    }

    $imageMetadata = slipDebugImageMetadataFromDataUri($imageBase64);

    // Validate a bounded image payload here as well as in the caller. Keeping
    // validation at the API boundary prevents future callers from bypassing it.
    if (!is_string($imageBase64) || $imageBase64 === '' || strlen($imageBase64) > 6 * 1024 * 1024) {
        $log('provider_preflight_failed', 'error', 'EasySlip image payload is missing or oversized', [
            'error_code' => 'invalid_image_payload',
            'context' => ['image' => $imageMetadata],
        ]);
        return ['success' => false, 'message' => 'ไม่พบข้อมูลรูปภาพหรือรูปภาพมีขนาดใหญ่เกินไป'];
    }
    $encodedPart = $imageBase64;
    if (preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $imageBase64)) {
        $encodedPart = substr($imageBase64, strpos($imageBase64, ',') + 1);
    }
    $decodedImage = base64_decode($encodedPart, true);
    if ($decodedImage === false || strlen($decodedImage) < 1 || strlen($decodedImage) > 4 * 1024 * 1024) {
        $log('provider_preflight_failed', 'error', 'EasySlip decoded image is invalid or oversized', [
            'error_code' => 'invalid_decoded_image',
            'context' => ['image' => $imageMetadata],
        ]);
        return ['success' => false, 'message' => 'ข้อมูลรูปภาพไม่ถูกต้อง'];
    }
    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeResult = $finfo ? finfo_buffer($finfo, $decodedImage) : false;
        if ($finfo) finfo_close($finfo);
        if (is_string($mimeResult)) $detectedMime = $mimeResult;
        if ($mimeResult !== false && !in_array($mimeResult, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            $log('provider_preflight_failed', 'error', 'EasySlip image MIME type is not allowed', [
                'error_code' => 'invalid_image_mime',
                'context' => ['image' => array_merge($imageMetadata, ['detected_mime' => $detectedMime])],
            ]);
            return ['success' => false, 'message' => 'รูปแบบรูปภาพไม่ถูกต้อง'];
        }
    }
    $imageMetadata['detected_mime'] = $detectedMime;
    $imageMetadata['decoded_bytes'] = strlen($decodedImage);
    $imageMetadata['decoded_sha256'] = hash('sha256', $decodedImage);
    unset($decodedImage, $encodedPart);

    $apiUrl = 'https://api.easyslip.com/v2/verify/bank';

    $remark = trim($remark);
    if ($remark !== '' && (strlen($remark) > 255 || preg_match('/[\x00-\x1F\x7F]/', $remark))) {
        $log('provider_preflight_failed', 'error', 'EasySlip remark failed local validation', [
            'error_code' => 'invalid_remark',
            'context' => ['remark_length' => strlen($remark), 'remark_sha256' => hash('sha256', $remark)],
        ]);
        return ['success' => false, 'message' => 'ข้อมูลระบุเว็บไซต์ไม่ถูกต้อง'];
    }
    $qrPayload = trim((string) ($debugContext['qr_payload'] ?? ''));
    if ($qrPayload !== '' && (strlen($qrPayload) > 128 || preg_match('/[^\x21-\x7E]/', $qrPayload))) {
        // Browser-side QR extraction is only an optimization. Never trust or
        // require it for financial correctness; invalid hints fall back to the
        // provider's image parser.
        $qrPayload = '';
    }
    $requestMode = $qrPayload !== '' ? 'payload' : 'base64';
    $requestPayload = $qrPayload !== ''
        ? ['payload' => $qrPayload, 'checkDuplicate' => true]
        : ['base64' => $imageBase64, 'checkDuplicate' => true];
    if ($remark !== '') {
        $requestPayload['remark'] = $remark;
    }
    $postData = json_encode($requestPayload, JSON_UNESCAPED_SLASHES);
    if ($postData === false) {
        $log('provider_preflight_failed', 'error', 'EasySlip request JSON encoding failed', [
            'error_code' => 'request_json_encode_failed',
            'context' => ['json_error' => json_last_error_msg(), 'image' => $imageMetadata],
        ]);
        return ['success' => false, 'message' => 'ไม่สามารถเตรียมข้อมูลรูปภาพได้'];
    }

    $safeRequest = [
        'method' => 'POST',
        'url' => $apiUrl,
        'headers' => [
            'Authorization' => 'Bearer [REDACTED]',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'SakazukiEasySlip/1.0',
        ],
        'body_keys' => array_keys($requestPayload),
        'body_non_image_fields' => [
            'checkDuplicate' => true,
            'remark' => $remark,
            'request_mode' => $requestMode,
            'payload_length' => $qrPayload !== '' ? strlen($qrPayload) : 0,
            'payload_sha256' => $qrPayload !== '' ? hash('sha256', $qrPayload) : '',
        ],
        'image_payload_metadata' => [
            'redacted' => true,
            'data_uri' => $imageMetadata['data_uri'] ?? false,
            'mime' => $imageMetadata['detected_mime'] ?: ($imageMetadata['mime'] ?? ''),
            'encoded_bytes' => $imageMetadata['encoded_bytes'] ?? 0,
            'decoded_bytes' => $imageMetadata['decoded_bytes'] ?? null,
            'decoded_sha256' => $imageMetadata['decoded_sha256'] ?? '',
        ],
        'request_json_bytes' => strlen($postData),
    ];
    $policy = slipProviderPolicy();
    $diagnosticOnly = !empty($debugContext['diagnostic_only']);
    $deadline = isset($debugContext['deadline']) && is_numeric($debugContext['deadline'])
        ? (float) $debugContext['deadline']
        : 0.0;
    $providerContext = [
        'api_key_configured' => true,
        'api_key_sha256_prefix' => substr(hash('sha256', $apiKey), 0, 16),
        'ssl_verify_peer' => true,
        'ssl_verify_host' => 2,
        'connect_timeout_ms' => (int) $policy['connect_timeout_ms'],
        'primary_timeout_ms' => (int) $policy['primary_timeout_ms'],
        'payload_timeout_ms' => (int) $policy['payload_timeout_ms'],
        'ipv4_fallback_timeout_ms' => (int) $policy['fallback_timeout_ms'],
        'request_mode' => $requestMode,
        'max_response_bytes' => 1024 * 1024,
        'diagnostic_only' => $diagnosticOnly,
        'clock_before_request' => slipDebugClockSnapshot(),
    ];

    if (!function_exists('curl_init')) {
        $log('provider_preflight_failed', 'critical', 'PHP cURL extension is unavailable', [
            'error_code' => 'curl_unavailable',
            'request' => $safeRequest,
            'context' => $providerContext,
        ]);
        return ['success' => false, 'error_code' => 'provider_connection', 'retryable' => true, 'message' => 'ระบบตรวจสอบสลิปภายนอกไม่พร้อมใช้งานชั่วคราว'];
    }

    // Refuse to consume a half-open circuit-breaker probe when the browser has
    // already spent almost all of its request budget uploading the image. Doing
    // this before acquiring the permit prevents a slow upload from holding the
    // only recovery probe lease without ever contacting EasySlip.
    $remainingMs = slipVerificationDeadlineRemainingMs($deadline);
    if ($remainingMs < 1200) {
        return [
            'success' => false,
            'error_code' => 'customer_deadline_exceeded',
            'retryable' => true,
            'provider_attempts_used' => 0,
            'transport_class' => 'deadline',
            'message' => 'ระบบยังไม่ได้ส่งสลิปไปตรวจ เนื่องจากเวลาในคำขอนี้เหลือน้อยเกินไป กรุณาใช้สลิปเดิมลองใหม่',
        ];
    }

    $permit = slipProviderHealthPermit($diagnosticOnly);
    if (empty($permit['allowed'])) {
        $retryAfter = max(1, (int) ($permit['retry_after_seconds'] ?? $policy['breaker_open_seconds']));
        $log('provider_circuit_open', 'warning', 'EasySlip circuit breaker blocked an upstream request', [
            'error_code' => 'provider_circuit_open',
            'response' => ['retry_after_seconds' => $retryAfter],
            'context' => array_merge($providerContext, ['breaker' => $permit]),
        ]);
        return [
            'success' => false,
            'error_code' => 'provider_circuit_open',
            'retryable' => true,
            'retry_after_seconds' => $retryAfter,
            'transport_class' => 'circuit_open',
            'provider_attempts_used' => 0,
            'message' => 'ระบบตรวจสอบสลิปภายนอกขัดข้องชั่วคราว กรุณาใช้สลิปเดิมลองใหม่ภายหลัง',
        ];
    }

    $concurrency = slipProviderAcquireConcurrencySlot($apiKey);
    if (empty($concurrency['success'])) {
        $retryAfter = max(1, (int) ($concurrency['retry_after_seconds'] ?? 2));
        $log('provider_concurrency_busy', 'warning', 'EasySlip request skipped because all bounded provider slots are in use', [
            'error_code' => 'provider_busy',
            'response' => ['retry_after_seconds' => $retryAfter, 'limit' => (int) ($concurrency['limit'] ?? 0)],
            'context' => $providerContext,
        ]);
        return [
            'success' => false,
            'error_code' => 'provider_busy',
            'retryable' => true,
            'retry_after_seconds' => $retryAfter,
            'transport_class' => 'local_concurrency',
            'provider_attempts_used' => 0,
            'message' => 'มีรายการกำลังตรวจสอบพร้อมกันหลายรายการ ระบบยังไม่ได้ส่งสลิปนี้ไปตรวจ กรุณาใช้สลิปเดิมลองใหม่ในอีกสักครู่',
        ];
    }
    $providerSlotName = (string) ($concurrency['slot_name'] ?? '');

    $runAttempt = static function (bool $forceIpv4, int $timeoutMs, string $routeLabel) use (
        $apiUrl, $postData, $apiKey, $safeRequest, $providerContext, $log,
        $slipHash, $diagnosticOnly, $policy
    ): array {
        if (!$diagnosticOnly && preg_match('/^[a-f0-9]{64}$/D', $slipHash) === 1) {
            // Increment immediately before the actual network call. A PHP worker
            // killed mid-request therefore still leaves an accurate durable count.
            slipVerificationMarkProviderRequest($slipHash);
        }
        $ch = curl_init($apiUrl);
        if ($ch === false) {
            return [
                'executed' => false, 'curl_errno' => CURLE_FAILED_INIT, 'curl_error' => 'Unable to initialize cURL',
                'http_code' => 0, 'response' => '', 'too_large' => false, 'result' => null,
                'json_valid' => false, 'duration_ms' => 0, 'curl_info' => [], 'route' => $routeLabel,
                'ip_family' => '',
            ];
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min((int) $policy['connect_timeout_ms'], max(500, $timeoutMs)),
            CURLOPT_TIMEOUT_MS => max(500, $timeoutMs),
            CURLOPT_USERAGENT => 'SakazukiEasySlip/1.0',
        ];
        if ($forceIpv4 && defined('CURL_IPRESOLVE_V4')) $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        curl_setopt_array($ch, $options);
        $response = '';
        $tooLarge = false;
        $safeResponseHeaders = [];
        configureBoundedCurlResponse($ch, $response, $tooLarge, 1024 * 1024);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $headerLine) use (&$safeResponseHeaders) {
            $length = strlen($headerLine);
            if ($length < 1 || $length > 16384) return $length;
            $pos = strpos($headerLine, ':');
            if ($pos === false) return $length;
            $name = strtolower(trim(substr($headerLine, 0, $pos)));
            if (!in_array($name, ['server', 'cf-ray', 'cf-error-type', 'cf-error-origin', 'retry-after', 'content-type', 'date'], true)) {
                return $length;
            }
            $value = trim(substr($headerLine, $pos + 1));
            if ($value === '') return $length;
            $safeResponseHeaders[$name] = substr($value, 0, 1000);
            return $length;
        });
        $startedAt = microtime(true);
        $executed = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $curlInfo = curl_getinfo($ch);
        $httpCode = (int) ($curlInfo['http_code'] ?? 0);
        $curlErrorNo = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = json_decode($response, true, 32);
        $jsonValid = is_array($result);
        $ipFamily = slipProviderIpFamily((string) ($curlInfo['primary_ip'] ?? ''));
        $reportedUploadLength = (int) round((float) ($curlInfo['upload_content_length'] ?? 0));
        $expectedUploadBytes = $reportedUploadLength > 0 ? $reportedUploadLength : strlen($postData);
        $uploadedBytes = max(0, (int) round((float) ($curlInfo['size_upload'] ?? 0)));
        $bodyFullyUploaded = $expectedUploadBytes > 0
            && $uploadedBytes >= max(1, $expectedUploadBytes - 4);
        $responseForLog = [
            'http_code' => $httpCode,
            'body_bytes' => strlen($response),
            'body_sha256' => hash('sha256', $response),
            'json_valid' => $jsonValid,
            'json_error' => $jsonValid ? '' : json_last_error_msg(),
            'headers' => $safeResponseHeaders,
            'body' => $jsonValid ? $result : null,
            'raw_body' => $jsonValid ? null : substr($response, 0, 200000),
        ];
        $attemptContext = $providerContext;
        $attemptContext['route'] = $routeLabel;
        $attemptContext['force_ipv4'] = $forceIpv4;
        $attemptContext['timeout_ms'] = $timeoutMs;
        $attemptContext['clock_after_request'] = slipDebugClockSnapshot();
        $attemptContext['curl'] = [
            'errno' => $curlErrorNo,
            'error' => substr($curlError, 0, 1000),
            'response_too_large' => $tooLarge,
            'info' => $curlInfo,
            'ip_family' => $ipFamily,
            'expected_upload_bytes' => $expectedUploadBytes,
            'uploaded_bytes' => $uploadedBytes,
            'body_fully_uploaded' => $bodyFullyUploaded,
        ];
        $providerErrorCodeForLog = '';
        if ($jsonValid && isset($result['error']) && is_array($result['error']) && is_scalar($result['error']['code'] ?? null)) {
            $providerErrorCodeForLog = substr((string) $result['error']['code'], 0, 80);
        }
        $httpTransientForLog = slipProviderTransientHttpCode($httpCode);
        $exchangeSeverity = ($executed !== false && $curlErrorNo === 0 && !$tooLarge && $httpCode > 0)
            ? ($httpTransientForLog || $httpCode >= 400 ? 'warning' : ($jsonValid ? 'info' : 'error'))
            : 'error';
        $exchangeErrorCode = $curlErrorNo !== 0
            ? 'curl_' . $curlErrorNo
            : ($tooLarge
                ? 'response_too_large'
                : ($httpTransientForLog
                    ? slipProviderTransientErrorCode($httpCode, $providerErrorCodeForLog)
                    : (!$jsonValid ? 'invalid_json' : ($httpCode >= 400 ? 'http_' . $httpCode : ''))));
        $log('provider_exchange', $exchangeSeverity, 'EasySlip request and response captured', [
            'error_code' => $exchangeErrorCode,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'request' => array_merge($safeRequest, ['route' => $routeLabel, 'force_ipv4' => $forceIpv4]),
            'response' => $responseForLog,
            'context' => $attemptContext,
        ]);
        return [
            'executed' => $executed,
            'curl_errno' => $curlErrorNo,
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'response' => $response,
            'too_large' => $tooLarge,
            'result' => $result,
            'json_valid' => $jsonValid,
            'duration_ms' => $durationMs,
            'curl_info' => $curlInfo,
            'route' => $routeLabel,
            'ip_family' => $ipFamily,
            'body_fully_uploaded' => $bodyFullyUploaded,
            'expected_upload_bytes' => $expectedUploadBytes,
            'uploaded_bytes' => $uploadedBytes,
            'response_headers' => $safeResponseHeaders,
        ];
    };

    // Recalculate because acquiring the breaker permit may have waited briefly.
    // QR-payload verification remains the preferred path because it sends far less
    // data, but Production showed that provider processing itself can still take
    // well beyond ten seconds. Both modes therefore receive one sufficiently long
    // request while the endpoint-wide deadline remains authoritative.
    $remainingMs = slipVerificationDeadlineRemainingMs($deadline);
    // Keep enough time after the provider result for shared-ledger fencing,
    // duplicate checks, wallet update, audit rows and a terminal JSON response.
    // Correctness wins over squeezing one more second out of the upstream wait.
    $reserveMs = $deadline > 0 ? 8000 : 0;
    $primaryTimeout = $requestMode === 'payload'
        ? (int) $policy['payload_timeout_ms']
        : (int) $policy['primary_timeout_ms'];
    if ($deadline > 0) $primaryTimeout = min($primaryTimeout, max(1200, $remainingMs - $reserveMs));
    $attemptResults = [];
    $final = [];
    $transientRetryDeferredSeconds = 0;
    try {
        $primary = $runAttempt(false, $primaryTimeout, $requestMode === 'payload' ? 'primary_payload' : 'primary_image');
        $attemptResults[] = $primary;
        $final = $primary;

        $primaryBodyFullyUploaded = !empty($primary['body_fully_uploaded']);
        $primaryAmbiguousFullUploadTimeout = slipProviderAttemptIsAmbiguousFullUploadTimeout($primary);

        // Only switch network route when the request did NOT reach the provider
        // as a complete HTTP body. Once the full body was uploaded, retrying a
        // timeout immediately is unsafe because the provider may still commit it.
        $preSendFailureCodes = [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST];
        foreach (['CURLE_SSL_CONNECT_ERROR', 'CURLE_SEND_ERROR'] as $curlConstant) {
            if (defined($curlConstant)) $preSendFailureCodes[] = constant($curlConstant);
        }
        $preSendFailureCodes = array_values(array_unique(array_map('intval', $preSendFailureCodes)));
        $fallbackEligible = !$primaryBodyFullyUploaded
            && in_array((int) ($primary['curl_errno'] ?? 0), $preSendFailureCodes, true)
            && (int) ($primary['http_code'] ?? 0) === 0
            && strlen((string) ($primary['response'] ?? '')) === 0;

        if ($fallbackEligible && count($attemptResults) < (int) $policy['transient_max_attempts']) {
            $remainingMs = slipVerificationDeadlineRemainingMs($deadline);
            if ($deadline <= 0 || $remainingMs >= 2200) {
                $fallbackTimeout = (int) $policy['fallback_timeout_ms'];
                if ($deadline > 0) $fallbackTimeout = min($fallbackTimeout, max(1200, $remainingMs - $reserveMs));
                $fallback = $runAttempt(true, $fallbackTimeout, 'ipv4_presend_fallback');
                $attemptResults[] = $fallback;
                $final = $fallback;
            }
        } elseif ($primaryAmbiguousFullUploadTimeout) {
            // Ambiguous outcome. Never retry this condition inside the same
            // submission. A later same-slip retry can safely recover provider
            // cache using the same durable signed remark.
        }

        // A definitive transient HTTP status is different from an ambiguous
        // zero-byte timeout: the provider/edge explicitly rejected the request.
        // Hide short 408/429/5xx incidents from the customer with bounded retry,
        // reusing the exact same payload and signed remark. This is safe for the
        // wallet because EasySlip duplicate metadata is never our financial
        // authority; shared/local ledgers still enforce exactly-once credit.
        while (count($attemptResults) < (int) $policy['transient_max_attempts']) {
            $finalHttp = (int) ($final['http_code'] ?? 0);
            $finalCurlErrno = (int) ($final['curl_errno'] ?? 0);
            if ($finalCurlErrno !== 0 || !slipProviderTransientHttpCode($finalHttp)) break;

            $headers = is_array($final['response_headers'] ?? null) ? $final['response_headers'] : [];
            $retryAfterSeconds = slipProviderRetryAfterSeconds($headers, 0, 300);
            $attemptNumber = count($attemptResults) + 1;
            if ($retryAfterSeconds > 0) {
                $backoffMs = $retryAfterSeconds * 1000;
            } else {
                $backoffMs = count($attemptResults) <= 1
                    ? (int) $policy['transient_backoff_first_ms']
                    : (int) $policy['transient_backoff_second_ms'];
            }

            $remainingMs = slipVerificationDeadlineRemainingMs($deadline);
            $minimumAttemptMs = 1200;
            if ($deadline > 0 && $remainingMs < ($backoffMs + $reserveMs + $minimumAttemptMs)) {
                $transientRetryDeferredSeconds = max(
                    $retryAfterSeconds,
                    max(1, (int) ceil(($backoffMs + $reserveMs + $minimumAttemptMs - $remainingMs) / 1000))
                );
                $log('provider_transient_retry_deferred', 'warning', 'Transient EasySlip retry skipped because the customer deadline has insufficient budget', [
                    'error_code' => slipProviderTransientErrorCode($finalHttp),
                    'http_code' => $finalHttp,
                    'response' => [
                        'attempts_used' => count($attemptResults),
                        'retry_after_seconds' => $retryAfterSeconds,
                        'planned_backoff_ms' => $backoffMs,
                        'remaining_ms' => $remainingMs,
                    ],
                ]);
                break;
            }

            $log('provider_transient_retry_scheduled', 'warning', 'EasySlip returned a transient HTTP status; retrying within the same durable slip attempt', [
                'error_code' => slipProviderTransientErrorCode($finalHttp),
                'http_code' => $finalHttp,
                'response' => [
                    'next_provider_attempt' => $attemptNumber,
                    'backoff_ms' => $backoffMs,
                    'retry_after_seconds' => $retryAfterSeconds,
                    'safe_response_headers' => $headers,
                ],
            ]);
            if ($backoffMs > 0) usleep($backoffMs * 1000);

            $remainingMs = slipVerificationDeadlineRemainingMs($deadline);
            $retryTimeout = $requestMode === 'payload'
                ? (int) $policy['payload_timeout_ms']
                : (int) $policy['primary_timeout_ms'];
            if ($deadline > 0) {
                if ($remainingMs <= $reserveMs + $minimumAttemptMs) break;
                $retryTimeout = min($retryTimeout, max($minimumAttemptMs, $remainingMs - $reserveMs));
            }

            $retryRoute = 'transient_http_retry_' . $attemptNumber;
            $retryAttempt = $runAttempt(false, $retryTimeout, $retryRoute);
            $attemptResults[] = $retryAttempt;
            $final = $retryAttempt;

            // If the retry itself becomes ambiguous after a complete upload,
            // stop immediately. Never stack another request behind an unknown
            // provider outcome merely because an earlier attempt returned 503.
            $retryAmbiguous = slipProviderAttemptIsAmbiguousFullUploadTimeout($retryAttempt);
            if ($retryAmbiguous) break;
        }
    } finally {
        slipProviderReleaseConcurrencySlot($providerSlotName);
    }

    $executed = $final['executed'] ?? false;
    $durationMs = (int) ($final['duration_ms'] ?? 0);
    $curlInfo = is_array($final['curl_info'] ?? null) ? $final['curl_info'] : [];
    $httpCode = (int) ($final['http_code'] ?? 0);
    $curlErrorNo = (int) ($final['curl_errno'] ?? 0);
    $curlError = (string) ($final['curl_error'] ?? '');
    $response = (string) ($final['response'] ?? '');
    $tooLarge = !empty($final['too_large']);
    $result = is_array($final['result'] ?? null) ? $final['result'] : null;
    $jsonValid = is_array($result);
    $finalIpFamily = (string) ($final['ip_family'] ?? '');
    $finalResponseHeaders = is_array($final['response_headers'] ?? null) ? $final['response_headers'] : [];
    $providerAttemptsUsed = count($attemptResults);
    $zeroByteTimeout = $curlErrorNo === CURLE_OPERATION_TIMEDOUT
        && ($httpCode === 0 || slipProviderHttpCodeIsInformational($httpCode))
        && $response === '';
    $operationHadAmbiguousOutcome = false;
    foreach ($attemptResults as $attemptResult) {
        if (slipProviderAttemptIsAmbiguousFullUploadTimeout($attemptResult)) {
            $operationHadAmbiguousOutcome = true;
            break;
        }
    }
    $transportResponsive = $executed !== false && $curlErrorNo === 0 && !$tooLarge && $jsonValid && $httpCode > 0;
    $providerOutcomeUnknown = !$transportResponsive && $operationHadAmbiguousOutcome;
    // HTTP availability status must be classified before JSON validity. A plain
    // text `503 Service Unavailable` is a valid HTTP-layer outage response, not
    // a malformed EasySlip v2 success/error document.
    $httpAvailabilityFailure = $httpCode === 408 || $httpCode === 429 || ($httpCode >= 500 && $httpCode <= 599);
    $transportClass = $providerOutcomeUnknown
        ? 'outcome_unknown'
        : ($httpAvailabilityFailure
            ? 'http_unavailable'
            : ($zeroByteTimeout
                ? 'zero_byte_timeout'
                : ($curlErrorNo === CURLE_OPERATION_TIMEDOUT ? 'timeout'
                    : ($curlErrorNo !== 0 ? 'connection'
                        : ($tooLarge ? 'response_too_large'
                            : (!$jsonValid ? 'invalid_json' : 'http_response'))))));
    // Keep deterministic 4xx validation/auth failures out of the breaker, but
    // treat timeout/rate-limit/server statuses as availability failures so many
    // customers cannot hammer an unhealthy upstream service.
    $responsive = $transportResponsive && !$httpAvailabilityFailure;
    $availabilityFailure = !$transportResponsive || $httpAvailabilityFailure;
    $operationLatencyMs = 0;
    foreach ($attemptResults as $attemptResult) $operationLatencyMs += max(0, (int) ($attemptResult['duration_ms'] ?? 0));
    // A full request body followed by a local response timeout is NOT evidence
    // that EasySlip is down. Production provider logs showed FOUND for exactly
    // this case. Do not poison/open the circuit breaker from an ambiguous client
    // timeout; the durable per-slip cooldown handles the uncertainty instead.
    if (!$providerOutcomeUnknown) {
        slipProviderHealthRecord([
            'responsive' => $responsive,
            'availability_failure' => $availabilityFailure,
            'zero_byte_timeout' => $zeroByteTimeout,
            'latency_ms' => $operationLatencyMs,
            'transport_class' => $transportClass,
            'http_code' => $httpCode,
            'ip_family' => $finalIpFamily,
        ], $diagnosticOnly);
    }

    if ($providerOutcomeUnknown) {
        return [
            'success' => false,
            'error_code' => 'provider_outcome_unknown',
            'retryable' => true,
            'retry_after_seconds' => (int) $policy['outcome_unknown_retry_seconds'],
            'provider_attempts_used' => $providerAttemptsUsed,
            'transport_class' => 'outcome_unknown',
            'provider_http_code' => $httpCode,
            'provider_ip_family' => $finalIpFamily,
            'request_body_fully_uploaded' => true,
            'message' => 'เว็บส่งข้อมูลสลิปไปยังระบบตรวจสอบภายนอกครบแล้ว แต่ยังไม่ได้รับผลตอบกลับภายในเวลาที่กำหนด รายการนี้ยังไม่มีการเติมเงิน',
        ];
    }

    if ($executed === false || $curlErrorNo !== 0 || $tooLarge) {
        error_log('EasySlip connection failed; curl=' . $curlErrorNo . '; oversized=' . ($tooLarge ? '1' : '0'));
        $isTimeout = $curlErrorNo === CURLE_OPERATION_TIMEDOUT;
        return [
            'success' => false,
            'error_code' => $isTimeout ? 'provider_timeout' : ($tooLarge ? 'provider_response_too_large' : 'provider_connection'),
            'retryable' => true,
            'provider_attempts_used' => $providerAttemptsUsed,
            'transport_class' => $transportClass,
            'provider_http_code' => $httpCode,
            'provider_ip_family' => $finalIpFamily,
            'zero_byte_timeout' => $zeroByteTimeout,
            'message' => $isTimeout
                ? 'ระบบตรวจสอบสลิปภายนอกตอบกลับช้ากว่าปกติ รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมลองใหม่ภายหลัง'
                : 'ระบบตรวจสอบสลิปภายนอกเชื่อมต่อไม่ได้ชั่วคราว รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมลองใหม่ภายหลัง',
        ];
    }

    if (slipProviderTransientHttpCode($httpCode)) {
        $providerCode = '';
        if (is_array($result) && isset($result['error']) && is_array($result['error']) && is_scalar($result['error']['code'] ?? null)) {
            $providerCode = substr(trim((string) $result['error']['code']), 0, 80);
        }
        $localCode = slipProviderTransientErrorCode($httpCode, $providerCode);
        $defaultRetry = $httpCode === 429 ? (int) $policy['retry_cooldown_seconds'] : 10;
        $retryAfter = slipProviderRetryAfterSeconds($finalResponseHeaders, $defaultRetry, 300);
        $retryAfter = max($retryAfter, $transientRetryDeferredSeconds);
        $log('provider_transient_exhausted', 'warning', 'EasySlip transient HTTP retries were exhausted or deferred by the one-minute customer deadline', [
            'error_code' => $localCode,
            'http_code' => $httpCode,
            'duration_ms' => $operationLatencyMs,
            'response' => [
                'provider_code' => $providerCode,
                'attempts_used' => $providerAttemptsUsed,
                'retry_after_seconds' => $retryAfter,
                'safe_response_headers' => $finalResponseHeaders,
            ],
        ]);
        return [
            'success' => false,
            'pending' => true,
            'error_code' => $localCode,
            'provider_code' => $providerCode,
            'retryable' => true,
            'retry_after_seconds' => $retryAfter,
            'system_error' => false,
            'provider_attempts_used' => $providerAttemptsUsed,
            'transport_class' => 'http_unavailable',
            'provider_http_code' => $httpCode,
            'provider_ip_family' => $finalIpFamily,
            'message' => 'ระบบตรวจสอบสลิปภายนอกขัดข้องชั่วคราว ระบบเก็บรายการสลิปเดิมไว้แล้วและจะไม่ถือว่าเป็นรายการล้มเหลว',
        ];
    }

    if (!is_array($result)) {
        error_log('EasySlip returned an invalid JSON response (HTTP ' . $httpCode . ')');
        return [
            'success' => false,
            'error_code' => 'provider_invalid_response',
            'retryable' => true,
            'provider_attempts_used' => $providerAttemptsUsed,
            'transport_class' => 'invalid_json',
            'provider_http_code' => $httpCode,
            'provider_ip_family' => $finalIpFamily,
            'message' => 'ระบบตรวจสอบสลิปภายนอกตอบกลับไม่ถูกต้องชั่วคราว กรุณาใช้สลิปเดิมลองใหม่ภายหลัง',
        ];
    }

    if ($httpCode !== 200) { error_log('EasySlip v2 API error HTTP ' . $httpCode); }

    if ($httpCode !== 200 || empty($result['success'])) {
        $errorData = isset($result['error']) && is_array($result['error']) ? $result['error'] : [];
        $errCodeRaw = $errorData['code'] ?? '';
        $errCode = is_scalar($errCodeRaw) ? substr((string) $errCodeRaw, 0, 80) : '';
        $friendlyMessages = [
            'VALIDATION_ERROR'      => 'ข้อมูลสลิปที่ส่งไปตรวจไม่ถูกต้อง กรุณาใช้รูปสลิปเดิมลองใหม่',
            'IMAGE_SIZE_TOO_LARGE'  => 'รูปภาพมีขนาดใหญ่เกินไป (สูงสุด 4MB)',
            'INVALID_IMAGE_FORMAT'  => 'รูปแบบไฟล์ไม่ถูกต้อง (รองรับ JPEG, PNG, GIF, WebP)',
            'SLIP_NOT_FOUND'        => 'ไม่พบข้อมูลสลิปหรือสลิปไม่ถูกต้อง กรุณาตรวจสอบสลิปแล้วลองใหม่',
            'SLIP_PENDING'          => 'สลิปธนาคารกรุงเทพยังไม่พร้อม กรุณารอสักครู่แล้วลองใหม่',
            'MISSING_API_KEY'       => 'ไม่พบ API Key กรุณาตั้งค่า EasySlip API Key ในหน้า Admin Settings',
            'INVALID_API_KEY'       => 'API Key ไม่ถูกต้องหรือถูกยกเลิกแล้ว กรุณาตรวจสอบ API Key ใน EasySlip Developer Portal',
            'IP_NOT_ALLOWED'        => 'IP ของ Server ไม่ได้รับอนุญาต กรุณาเพิ่ม IP ใน EasySlip Developer Portal',
            'BRANCH_INACTIVE'       => 'Branch ถูกปิดใช้งาน กรุณาเปิดใช้งานใหม่ใน EasySlip Developer Portal',
            'SERVICE_BANNED'         => 'บริการ EasySlip ของบัญชีนี้ถูกระงับ กรุณาตรวจสอบใน Developer Portal',
            'USER_BANNED'            => 'บัญชี EasySlip ถูกระงับ กรุณาติดต่อ EasySlip Support',
            'QUOTA_EXCEEDED'        => 'โควต้า API หมดแล้ว กรุณาอัพเกรดแพ็กเกจหรือรอให้โควต้ารีเซ็ต',
        ];
        if (!isset($friendlyMessages[$errCode])) {
            error_log('EasySlip verification failed: HTTP ' . $httpCode . ' code=' . substr((string) $errCode, 0, 80));
        }
        $msg = $friendlyMessages[$errCode] ?? 'ตรวจสอบสลิปไม่ผ่าน โปรดลองใหม่อีกครั้ง';
        $retryableCodes = ['SLIP_PENDING', 'QUOTA_EXCEEDED'];
        $systemCodes = ['MISSING_API_KEY', 'INVALID_API_KEY', 'IP_NOT_ALLOWED', 'BRANCH_INACTIVE', 'SERVICE_BANNED', 'USER_BANNED', 'QUOTA_EXCEEDED'];
        $httpRetryable = $httpCode === 408 || $httpCode === 429 || ($httpCode >= 500 && $httpCode <= 599);
        $retryable = in_array($errCode, $retryableCodes, true) || $httpRetryable;
        $localCode = $httpRetryable
            ? 'provider_http_unavailable'
            : ($errCode !== '' ? strtolower($errCode) : 'provider_rejected');
        $retryAfterSeconds = 0;
        if ($retryable) {
            if ($errCode === 'QUOTA_EXCEEDED') $retryAfterSeconds = 300;
            elseif ($httpCode === 429) $retryAfterSeconds = 60;
            elseif ($errCode === 'SLIP_PENDING') $retryAfterSeconds = 15;
            else $retryAfterSeconds = (int) slipProviderPolicy()['retry_cooldown_seconds'];
        }
        $log('provider_rejected', 'error', 'EasySlip rejected the verification request', [
            'error_code' => $errCode !== '' ? $errCode : 'provider_rejected',
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'response' => ['provider_error' => $errorData, 'friendly_message' => $msg],
        ]);
        return [
            'success' => false,
            'error_code' => $localCode,
            'provider_code' => $errCode,
            'retryable' => $retryable,
            'retry_after_seconds' => $retryAfterSeconds,
            'system_error' => in_array($errCode, $systemCodes, true),
            'provider_attempts_used' => $providerAttemptsUsed,
            'transport_class' => $httpRetryable ? 'http_unavailable' : 'http_response',
            'provider_http_code' => $httpCode,
            'provider_ip_family' => $finalIpFamily,
            'message' => $httpRetryable && $errCode === ''
                ? 'ระบบตรวจสอบสลิปภายนอกขัดข้องชั่วคราว รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมลองใหม่ภายหลัง'
                : $msg,
        ];
    }

    // EasySlip v2: data.rawSlip contains the transaction details
    $apiData = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    $rawSlip = isset($apiData['rawSlip']) && is_array($apiData['rawSlip']) ? $apiData['rawSlip'] : [];

    if (empty($rawSlip)) {
        $log('provider_normalization_failed', 'error', 'EasySlip response has no rawSlip object', [
            'error_code' => 'missing_raw_slip',
            'http_code' => $httpCode,
            'response' => ['data_keys' => array_keys($apiData)],
        ]);
        return ['success' => false, 'message' => 'ไม่พบข้อมูลสลิปในการตอบกลับของ API'];
    }

    $transRefRaw = $rawSlip['transRef'] ?? '';
    $transRef = is_scalar($transRefRaw) ? trim((string) $transRefRaw) : '';
    if ($transRef === '' || strlen($transRef) > 100 || preg_match('/[\x00-\x20\x7F]/', $transRef)) {
        $log('provider_normalization_failed', 'error', 'EasySlip transaction reference is invalid', [
            'error_code' => 'invalid_transaction_ref',
            'response' => ['raw_value' => $transRefRaw, 'raw_type' => gettype($transRefRaw)],
        ]);
        return ['success' => false, 'message' => 'API ไม่ได้ส่งเลขอ้างอิงรายการที่ถูกต้อง'];
    }
    $amountData = isset($rawSlip['amount']) && is_array($rawSlip['amount']) ? $rawSlip['amount'] : [];
    $amountRaw = $amountData['amount'] ?? null;
    if (!is_scalar($amountRaw) || !is_numeric((string) $amountRaw)) {
        $log('provider_normalization_failed', 'error', 'EasySlip amount is not numeric', [
            'error_code' => 'invalid_amount_type',
            'response' => ['raw_amount' => $amountRaw, 'raw_type' => gettype($amountRaw)],
        ]);
        return ['success' => false, 'message' => 'จำนวนเงินบนสลิปไม่ถูกต้อง'];
    }
    $amount = round((float) $amountRaw, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
        $log('provider_normalization_failed', 'error', 'EasySlip amount is outside the accepted range', [
            'error_code' => 'invalid_amount_range',
            'response' => ['raw_amount' => $amountRaw, 'normalized_amount' => $amount],
        ]);
        return ['success' => false, 'message' => 'จำนวนเงินบนสลิปไม่ถูกต้อง'];
    }

    $localAmountData = isset($amountData['local']) && is_array($amountData['local']) ? $amountData['local'] : [];
    $localAmountRaw = $localAmountData['amount'] ?? null;
    $localAmount = null;
    if (is_scalar($localAmountRaw) && is_numeric((string) $localAmountRaw)) {
        $candidateLocalAmount = round((float) $localAmountRaw, 2);
        if (is_finite($candidateLocalAmount) && $candidateLocalAmount > 0 && $candidateLocalAmount <= 10000000) {
            $localAmount = $candidateLocalAmount;
        }
    }
    $localCurrencyRaw = isset($localAmountData['currency']) && is_scalar($localAmountData['currency'])
        ? substr(trim((string) $localAmountData['currency']), 0, 32)
        : '';

    $transDateRaw = $rawSlip['date'] ?? '';
    $transDate = is_scalar($transDateRaw) ? substr(trim((string) $transDateRaw), 0, 100) : '';

    // Sender
    $senderData = isset($rawSlip['sender']) && is_array($rawSlip['sender']) ? $rawSlip['sender'] : [];
    $senderBank = isset($senderData['bank']) && is_array($senderData['bank']) ? $senderData['bank'] : [];
    $bankCodeRaw = $senderBank['id'] ?? '';
    $bankCode = is_scalar($bankCodeRaw) ? substr(trim((string) $bankCodeRaw), 0, 50) : '';
    $senderAccountData = isset($senderData['account']) && is_array($senderData['account']) ? $senderData['account'] : [];
    $senderNameData = isset($senderAccountData['name']) && is_array($senderAccountData['name']) ? $senderAccountData['name'] : [];
    $senderNameRaw = $senderNameData['th'] ?? $senderNameData['en'] ?? '';
    $senderName = is_scalar($senderNameRaw) ? substr(trim((string) $senderNameRaw), 0, 255) : '';
    $senderBankAccount = isset($senderAccountData['bank']) && is_array($senderAccountData['bank']) ? $senderAccountData['bank'] : [];
    $senderProxyAccount = isset($senderAccountData['proxy']) && is_array($senderAccountData['proxy']) ? $senderAccountData['proxy'] : [];
    $senderAccountRaw = $senderBankAccount['account'] ?? $senderProxyAccount['account'] ?? '';
    $senderAccount = is_scalar($senderAccountRaw) ? substr(trim((string) $senderAccountRaw), 0, 255) : '';

    // Receiver
    $receiverData = isset($rawSlip['receiver']) && is_array($rawSlip['receiver']) ? $rawSlip['receiver'] : [];
    $receiverAccountData = isset($receiverData['account']) && is_array($receiverData['account']) ? $receiverData['account'] : [];
    $receiverNameData = isset($receiverAccountData['name']) && is_array($receiverAccountData['name']) ? $receiverAccountData['name'] : [];
    $receiverNameRaw = $receiverNameData['th'] ?? $receiverNameData['en'] ?? '';
    $receiverName = is_scalar($receiverNameRaw) ? substr(trim((string) $receiverNameRaw), 0, 255) : '';

    $accounts = [];
    foreach (['bank', 'proxy'] as $accountType) {
        $accountData = isset($receiverAccountData[$accountType]) && is_array($receiverAccountData[$accountType]) ? $receiverAccountData[$accountType] : [];
        $accountRaw = $accountData['account'] ?? '';
        if (is_scalar($accountRaw)) {
            $account = substr(trim((string) $accountRaw), 0, 255);
            if ($account !== '') $accounts[] = $account;
        }
    }
    $receiverAccount = implode(',', array_values(array_unique($accounts)));

    $normalized = [
        'transaction_ref'  => $transRef,
        'amount'           => $amount,
        'sender_name'      => $senderName,
        'sender_account'   => $senderAccount,
        'receiver_name'    => $receiverName,
        'receiver_account' => $receiverAccount,
        'bank_code'        => $bankCode,
        'transfer_date'    => $transDate,
        'country_code'     => (isset($rawSlip['countryCode']) && is_scalar($rawSlip['countryCode'])) ? strtoupper(substr(trim((string) $rawSlip['countryCode']), 0, 8)) : '',
        'local_amount'     => $localAmount,
        'local_currency'   => $localCurrencyRaw,
        'verification_remark' => (isset($apiData['remark']) && is_scalar($apiData['remark'])) ? substr(trim((string) $apiData['remark']), 0, 255) : '',
        'is_duplicate'     => !empty($apiData['isDuplicate'])
    ];

    $log('provider_normalized', 'info', 'EasySlip response normalized for local validation', [
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'response' => [
            'raw_date_value' => $transDateRaw,
            'raw_date_type' => gettype($transDateRaw),
            'normalized_data' => $normalized,
        ],
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);

    return ['success' => true, 'data' => $normalized, 'provider_attempts_used' => $providerAttemptsUsed, 'transport_class' => 'http_response', 'provider_http_code' => $httpCode, 'provider_ip_family' => $finalIpFamily];
}

/**
 * Check if slip has already been used
 */
function isSlipAlreadyUsed($transactionRef)
{
    global $conn;
    if (!is_scalar($transactionRef)) return false;
    $ref = trim((string) $transactionRef);
    if ($ref === '' || strlen($ref) > 190) return false;
    $stmt = $conn->prepare('SELECT id FROM slip_deposits WHERE transaction_ref = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $ref);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Check if slip hash has already been used (to prevent API quota drain)
 */
function isSlipHashAlreadyUsed($hash)
{
    global $conn;
    if (!is_scalar($hash)) return false;
    $h = strtolower(trim((string) $hash));
    if (!preg_match('/^[a-f0-9]{64}$/', $h)) return false;
    $stmt = $conn->prepare('SELECT id FROM slip_deposits WHERE slip_hash = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $h);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Track verification attempts before calling EasySlip. This allows a safe retry
 * after this site's own database rollback while rejecting duplicates first
 * verified by another rental-site database sharing the same provider key.
 */
function ensureSlipVerificationAttemptsTable(): bool
{
    global $conn;
    static $ready = false;
    if ($ready) return true;
    if (!isset($conn) || !($conn instanceof mysqli)) return false;

    $sql = "CREATE TABLE IF NOT EXISTS slip_verification_attempts (
        slip_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'processing',
        verification_remark VARCHAR(64) NOT NULL,
        transaction_ref VARCHAR(100) NULL,
        attempts INT NOT NULL DEFAULT 1,
        last_error VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_slip_attempt_user (user_id),
        KEY idx_slip_attempt_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('Unable to prepare slip verification attempts table: ' . $conn->error);
        return false;
    }
    $ready = true;
    return true;
}

function claimSlipVerificationAttempt(string $slipHash, int $userId, string $remark): array
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $remark = trim($remark);
    if (!preg_match('/^[a-f0-9]{64}$/', $slipHash) || $userId < 1 || $remark === '' || strlen($remark) > 64
        || !ensureSlipVerificationAttemptsTable()) {
        return ['success' => false, 'retry' => false, 'reason' => 'storage'];
    }

    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            "INSERT IGNORE INTO slip_verification_attempts
             (slip_hash, user_id, status, verification_remark, attempts)
             VALUES (?, ?, 'processing', ?, 1)"
        );
        if (!$insert) throw new RuntimeException('attempt insert prepare failed');
        $insert->bind_param('sis', $slipHash, $userId, $remark);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('attempt insert failed');
        }
        $inserted = $insert->affected_rows === 1;
        $insert->close();
        if ($inserted) {
            $conn->commit();
            return ['success' => true, 'retry' => false, 'reason' => 'new'];
        }

        $select = $conn->prepare(
            'SELECT user_id, status, verification_remark, UNIX_TIMESTAMP(updated_at) AS updated_ts '
            . 'FROM slip_verification_attempts WHERE slip_hash = ? LIMIT 1 FOR UPDATE'
        );
        if (!$select) throw new RuntimeException('attempt select prepare failed');
        $select->bind_param('s', $slipHash);
        if (!$select->execute()) {
            $select->close();
            throw new RuntimeException('attempt select failed');
        }
        $result = $select->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $select->close();
        if (!$row) throw new RuntimeException('attempt row missing');

        $storedUserId = (int) ($row['user_id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $storedRemark = (string) ($row['verification_remark'] ?? '');
        $updatedTs = (int) ($row['updated_ts'] ?? 0);
        if (!hash_equals($storedRemark, $remark)) {
            $conn->commit();
            return ['success' => false, 'retry' => false, 'reason' => 'different_site'];
        }
        if ($storedUserId !== $userId) {
            $conn->commit();
            return ['success' => false, 'retry' => false, 'reason' => 'different_user'];
        }
        if ($status === 'completed') {
            $conn->commit();
            return ['success' => false, 'retry' => false, 'reason' => 'completed'];
        }
        if ($status === 'blocked') {
            $conn->commit();
            return ['success' => false, 'retry' => false, 'reason' => 'blocked'];
        }
        if ($status === 'processing' && $updatedTs > (time() - 120)) {
            $conn->commit();
            return ['success' => false, 'retry' => false, 'reason' => 'processing'];
        }

        $update = $conn->prepare(
            "UPDATE slip_verification_attempts
             SET status = 'processing', attempts = attempts + 1, last_error = '', updated_at = NOW()
             WHERE slip_hash = ?"
        );
        if (!$update) throw new RuntimeException('attempt retry prepare failed');
        $update->bind_param('s', $slipHash);
        $ok = $update->execute() && $update->affected_rows === 1;
        $update->close();
        if (!$ok) throw new RuntimeException('attempt retry update failed');
        $conn->commit();
        return ['success' => true, 'retry' => true, 'reason' => 'retry'];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Slip verification attempt claim failed: ' . $e->getMessage());
        return ['success' => false, 'retry' => false, 'reason' => 'storage'];
    }
}

function finishSlipVerificationAttempt(string $slipHash, string $status, string $transactionRef = '', string $lastError = ''): bool
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $status = strtolower(trim($status));
    $transactionRef = substr(trim($transactionRef), 0, 100);
    $lastError = substr(trim($lastError), 0, 64);
    if (!preg_match('/^[a-f0-9]{64}$/', $slipHash)
        || !in_array($status, ['failed', 'completed', 'blocked'], true)
        || !ensureSlipVerificationAttemptsTable()) {
        return false;
    }
    $stmt = $conn->prepare(
        'UPDATE slip_verification_attempts SET status = ?, transaction_ref = ?, last_error = ?, updated_at = NOW() WHERE slip_hash = ?'
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssss', $status, $transactionRef, $lastError, $slipHash);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
/** Generate a public, unguessable identifier for one browser verification attempt. */
function generateSlipVerificationAttemptUuid(): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable $e) {
        $bytes = hash('sha256', uniqid('', true) . microtime(true), true);
    }
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex(substr($bytes, 0, 16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function normalizeSlipVerificationAttemptUuid($value): string
{
    if (!is_scalar($value)) return '';
    $value = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value)
        ? $value
        : '';
}

/**
 * Durable state machine for slip verification. A separate table is used instead
 * of altering the legacy attempt table on a busy storefront deployment.
 */
function ensureSlipVerificationJobsTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;

    $sql = "CREATE TABLE IF NOT EXISTS slip_verification_jobs (
        attempt_uuid CHAR(36) NOT NULL,
        slip_hash CHAR(64) NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'verifying',
        verification_remark VARCHAR(255) NOT NULL DEFAULT '',
        transaction_ref VARCHAR(100) NULL,
        provider_data_ciphertext LONGTEXT NULL,
        provider_is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
        provider_verified_at DATETIME NULL,
        provider_request_count INT UNSIGNED NOT NULL DEFAULT 0,
        provider_first_attempt_at DATETIME NULL,
        provider_last_attempt_at DATETIME NULL,
        provider_next_retry_at DATETIME NULL,
        provider_timeout_streak INT UNSIGNED NOT NULL DEFAULT 0,
        provider_last_transport_class VARCHAR(32) NOT NULL DEFAULT '',
        provider_last_http_code INT NULL,
        provider_last_ip_family VARCHAR(8) NOT NULL DEFAULT '',
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        last_error_code VARCHAR(80) NOT NULL DEFAULT '',
        last_error_message VARCHAR(500) NOT NULL DEFAULT '',
        slip_deposit_id BIGINT UNSIGNED NULL,
        deposit_transaction_id BIGINT UNSIGNED NULL,
        credit_amount DECIMAL(16,2) NULL,
        bonus_amount DECIMAL(16,2) NULL,
        total_credited DECIMAL(16,2) NULL,
        completed_at DATETIME NULL,
        terminal_at DATETIME NULL,
        shared_finalized_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (attempt_uuid),
        UNIQUE KEY uq_slip_job_hash (slip_hash),
        KEY idx_slip_job_user (user_id, created_at),
        KEY idx_slip_job_status (status, updated_at),
        KEY idx_slip_job_reference (transaction_ref),
        KEY idx_slip_job_provider_retry (provider_next_retry_at, status),
        KEY idx_slip_job_terminal (terminal_at, status),
        KEY idx_slip_job_shared_finalize (status, shared_finalized_at, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        error_log('Unable to prepare durable slip verification jobs: ' . $conn->error);
        return $ready = false;
    }

    // Existing deployments predate the shared-finalization marker. Add it once
    // so a local wallet commit can be repaired in the shared cross-site ledger
    // without requiring an administrator. This marker never controls whether
    // money is credited; it records only that the cross-site fencing record has
    // reached its terminal completed state.
    $column = $conn->query("SHOW COLUMNS FROM slip_verification_jobs LIKE 'shared_finalized_at'");
    $hasSharedFinalizedAt = $column && $column->num_rows > 0;
    if ($column) $column->free();
    if (!$hasSharedFinalizedAt && !$conn->query(
        "ALTER TABLE slip_verification_jobs ADD COLUMN shared_finalized_at DATETIME NULL AFTER completed_at, ADD KEY idx_slip_job_shared_finalize (status, shared_finalized_at, updated_at)"
    )) {
        error_log('Unable to add slip shared-finalization marker: ' . $conn->error);
        return $ready = false;
    }
    // Recover cleanly from a partially/manual migrated table where the column
    // exists but the repair index does not. Missing this index is not a money
    // correctness issue, but it can turn background repair into an unnecessary
    // table scan as verification history grows.
    $sharedIndex = $conn->query("SHOW INDEX FROM slip_verification_jobs WHERE Key_name='idx_slip_job_shared_finalize'");
    $hasSharedIndex = $sharedIndex && $sharedIndex->num_rows > 0;
    if ($sharedIndex) $sharedIndex->free();
    if (!$hasSharedIndex && !$conn->query(
        "ALTER TABLE slip_verification_jobs ADD KEY idx_slip_job_shared_finalize (status, shared_finalized_at, updated_at)"
    )) {
        error_log('Unable to add slip shared-finalization repair index: ' . $conn->error);
        return $ready = false;
    }
    $providerCountColumn = $conn->query("SHOW COLUMNS FROM slip_verification_jobs LIKE 'provider_request_count'");
    $hasProviderCount = $providerCountColumn && $providerCountColumn->num_rows > 0;
    if ($providerCountColumn) $providerCountColumn->free();
    if (!$hasProviderCount && !$conn->query(
        "ALTER TABLE slip_verification_jobs ADD COLUMN provider_request_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER provider_verified_at"
    )) {
        error_log('Unable to add slip provider-request counter: ' . $conn->error);
        return $ready = false;
    }
    $additiveColumns = [
        'provider_first_attempt_at' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_first_attempt_at DATETIME NULL AFTER provider_request_count",
        'provider_last_attempt_at' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_last_attempt_at DATETIME NULL AFTER provider_first_attempt_at",
        'provider_next_retry_at' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_next_retry_at DATETIME NULL AFTER provider_last_attempt_at",
        'provider_timeout_streak' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_timeout_streak INT UNSIGNED NOT NULL DEFAULT 0 AFTER provider_next_retry_at",
        'provider_last_transport_class' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_last_transport_class VARCHAR(32) NOT NULL DEFAULT '' AFTER provider_timeout_streak",
        'provider_last_http_code' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_last_http_code INT NULL AFTER provider_last_transport_class",
        'provider_last_ip_family' => "ALTER TABLE slip_verification_jobs ADD COLUMN provider_last_ip_family VARCHAR(8) NOT NULL DEFAULT '' AFTER provider_last_http_code",
        'terminal_at' => "ALTER TABLE slip_verification_jobs ADD COLUMN terminal_at DATETIME NULL AFTER completed_at",
    ];
    foreach ($additiveColumns as $columnName => $alterSql) {
        $columnResult = $conn->query("SHOW COLUMNS FROM slip_verification_jobs LIKE '" . $conn->real_escape_string($columnName) . "'");
        $hasColumn = $columnResult && $columnResult->num_rows > 0;
        if ($columnResult) $columnResult->free();
        if (!$hasColumn && !$conn->query($alterSql)) {
            error_log('Unable to add slip verification column ' . $columnName . ': ' . $conn->error);
            return $ready = false;
        }
    }
    $additiveIndexes = [
        'idx_slip_job_provider_retry' => 'ALTER TABLE slip_verification_jobs ADD KEY idx_slip_job_provider_retry (provider_next_retry_at, status)',
        'idx_slip_job_terminal' => 'ALTER TABLE slip_verification_jobs ADD KEY idx_slip_job_terminal (terminal_at, status)',
    ];
    foreach ($additiveIndexes as $indexName => $alterSql) {
        $indexResult = $conn->query("SHOW INDEX FROM slip_verification_jobs WHERE Key_name='" . $conn->real_escape_string($indexName) . "'");
        $hasIndex = $indexResult && $indexResult->num_rows > 0;
        if ($indexResult) $indexResult->free();
        if (!$hasIndex && !$conn->query($alterSql)) {
            error_log('Unable to add slip verification index ' . $indexName . ': ' . $conn->error);
            return $ready = false;
        }
    }

    return $ready = true;
}

/**
 * Provider health is deliberately an optional resilience layer. Failure to
 * create/update this table must never make the core slip job storage unusable;
 * financial dedupe remains protected independently by local/shared ledgers.
 */
function ensureSlipProviderHealthTable(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    $healthSql = "CREATE TABLE IF NOT EXISTS slip_provider_health (
        provider VARCHAR(32) NOT NULL,
        window_started_at DATETIME(6) NULL,
        requests_window INT UNSIGNED NOT NULL DEFAULT 0,
        zero_byte_timeouts_window INT UNSIGNED NOT NULL DEFAULT 0,
        successes_window INT UNSIGNED NOT NULL DEFAULT 0,
        consecutive_transport_failures INT UNSIGNED NOT NULL DEFAULT 0,
        breaker_open_until DATETIME(6) NULL,
        half_open_until DATETIME(6) NULL,
        last_success_at DATETIME(6) NULL,
        last_failure_at DATETIME(6) NULL,
        last_latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
        last_transport_class VARCHAR(32) NOT NULL DEFAULT '',
        last_http_code INT NULL,
        last_ip_family VARCHAR(8) NOT NULL DEFAULT '',
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (provider),
        KEY idx_slip_provider_breaker (breaker_open_until, half_open_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($healthSql)) {
        error_log('Unable to prepare slip provider health state: ' . $conn->error);
        return $ready = false;
    }
    if (!$conn->query("INSERT IGNORE INTO slip_provider_health (provider, window_started_at) VALUES ('easyslip', NOW(6))")) {
        error_log('Unable to initialize slip provider health state: ' . $conn->error);
        return $ready = false;
    }
    return $ready = true;
}

function slipVerificationProviderSnapshotEncryptionReady(): bool
{
    if (!function_exists('openssl_encrypt') || !function_exists('storeBridgeEncryptionKey')) return false;
    try {
        $key = storeBridgeEncryptionKey(true);
        return is_string($key) && strlen($key) === 32;
    } catch (Throwable $e) {
        error_log('Slip provider snapshot encryption preflight failed: ' . $e->getMessage());
        return false;
    }
}

function slipVerificationEncryptProviderData(array $data): ?string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || $json === '' || strlen($json) > 16384) return null;

    if (function_exists('storeBridgeEncryptionKey') && function_exists('openssl_encrypt')) {
        $key = storeBridgeEncryptionKey(true);
        if (is_string($key) && strlen($key) === 32) {
            try {
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt(
                    $json,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    'slip_verification_jobs.provider_data'
                );
                if (is_string($cipher) && strlen($tag) === 16) {
                    return 'v1.' . rtrim(strtr(base64_encode($nonce . $tag . $cipher), '+/', '-_'), '=');
                }
            } catch (Throwable $e) {
                error_log('Slip provider snapshot encryption failed: ' . $e->getMessage());
            }
        }
    }

    // Provider snapshots contain banking metadata and are required for later
    // automatic recovery. Base64 is not encryption, so a missing/broken crypto
    // layer must fail closed rather than persist financial metadata in plaintext.
    // Decryption keeps legacy j1.* support below solely so old rows remain
    // recoverable during migration; new snapshots are never written that way.
    error_log('Slip provider snapshot encryption unavailable; refusing plaintext fallback');
    return null;
}

function slipVerificationDecryptProviderData($encoded): ?array
{
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 32768) return null;
    $decode = static function (string $value) {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
        return base64_decode($value, true);
    };

    $json = null;
    if (strpos($encoded, 'v1.') === 0) {
        $blob = $decode(substr($encoded, 3));
        if (!is_string($blob) || strlen($blob) < 29 || !function_exists('storeBridgeEncryptionKey') || !function_exists('openssl_decrypt')) {
            return null;
        }
        $nonce = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        $keys = [];
        if (function_exists('storeBridgeDecryptionKeys')) {
            foreach (storeBridgeDecryptionKeys() as $candidate) {
                if (is_string($candidate) && strlen($candidate) === 32) {
                    $keys[hash('sha256', $candidate)] = $candidate;
                }
            }
        }
        $primary = storeBridgeEncryptionKey(false);
        if (is_string($primary) && strlen($primary) === 32) {
            $keys[hash('sha256', $primary)] = $primary;
        }
        foreach ($keys as $key) {
            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                'slip_verification_jobs.provider_data'
            );
            if (is_string($plain)) {
                $json = $plain;
                break;
            }
        }
        if (!is_string($json)) return null;
    } elseif (strpos($encoded, 'j1.') === 0) {
        $plain = $decode(substr($encoded, 3));
        if (!is_string($plain)) return null;
        $json = $plain;
    } else {
        return null;
    }

    $data = json_decode($json, true, 32);
    return is_array($data) ? $data : null;
}

function slipVerificationReadJobByHash(string $slipHash, bool $forUpdate = false): ?array
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable()) return null;
    $sql = 'SELECT * FROM slip_verification_jobs WHERE slip_hash = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('s', $slipHash);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function slipVerificationReadJobByAttempt(string $attemptUuid, int $userId = 0): ?array
{
    global $conn;
    $attemptUuid = normalizeSlipVerificationAttemptUuid($attemptUuid);
    $userId = (int) $userId;
    if ($attemptUuid === '' || !ensureSlipVerificationJobsTable()) return null;
    $sql = 'SELECT * FROM slip_verification_jobs WHERE attempt_uuid = ?';
    if ($userId > 0) $sql .= ' AND user_id = ?';
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    if ($userId > 0) $stmt->bind_param('si', $attemptUuid, $userId); else $stmt->bind_param('s', $attemptUuid);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function slipVerificationImportLegacyAttempt(string $slipHash, int $userId, string $attemptUuid, string $remark): void
{
    global $conn;
    $tableResult = $conn->query("SHOW TABLES LIKE 'slip_verification_attempts'");
    $exists = $tableResult && $tableResult->num_rows > 0;
    if ($tableResult) $tableResult->free();
    if (!$exists) return;

    $stmt = $conn->prepare(
        'SELECT user_id, status, transaction_ref, attempts, last_error, created_at, updated_at '
        . 'FROM slip_verification_attempts WHERE slip_hash = ? LIMIT 1'
    );
    if (!$stmt) return;
    $stmt->bind_param('s', $slipHash);
    if (!$stmt->execute()) { $stmt->close(); return; }
    $result = $stmt->get_result();
    $legacy = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$legacy || (int) ($legacy['user_id'] ?? 0) !== $userId) return;

    $legacyStatus = strtolower((string) ($legacy['status'] ?? 'failed'));
    $status = $legacyStatus === 'completed' ? 'completed' : 'failed';
    $transactionRef = substr(trim((string) ($legacy['transaction_ref'] ?? '')), 0, 100);
    $attempts = max(1, (int) ($legacy['attempts'] ?? 1));
    $lastError = substr(trim((string) ($legacy['last_error'] ?? 'legacy_attempt')), 0, 80);
    $createdAt = (string) ($legacy['created_at'] ?? date('Y-m-d H:i:s'));
    $updatedAt = (string) ($legacy['updated_at'] ?? $createdAt);
    $completedAt = $status === 'completed' ? $updatedAt : null;

    $insert = $conn->prepare(
        "INSERT IGNORE INTO slip_verification_jobs
         (attempt_uuid, slip_hash, user_id, status, verification_remark, transaction_ref,
          attempts, last_error_code, completed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?)"
    );
    if (!$insert) return;
    $insert->bind_param(
        'ssisssissss',
        $attemptUuid,
        $slipHash,
        $userId,
        $status,
        $remark,
        $transactionRef,
        $attempts,
        $lastError,
        $completedAt,
        $createdAt,
        $updatedAt
    );
    $insert->execute();
    $insert->close();
}

function claimSlipVerificationJob(string $slipHash, int $userId, string $remark, string $requestedAttemptUuid = ''): array
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $userId = (int) $userId;
    $remark = substr(trim($remark), 0, 255);
    $attemptUuid = normalizeSlipVerificationAttemptUuid($requestedAttemptUuid);
    if ($attemptUuid === '') $attemptUuid = generateSlipVerificationAttemptUuid();
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || $userId < 1 || !ensureSlipVerificationJobsTable()) {
        return ['success' => false, 'reason' => 'storage', 'attempt_id' => $attemptUuid];
    }

    // Import only a matching local legacy row. Cross-site ownership remains the
    // responsibility of the shared ledger, never of an EasySlip remark string.
    slipVerificationImportLegacyAttempt($slipHash, $userId, $attemptUuid, $remark);

    try {
        $conn->begin_transaction();
        $insert = $conn->prepare(
            "INSERT IGNORE INTO slip_verification_jobs
             (attempt_uuid, slip_hash, user_id, status, verification_remark, attempts)
             VALUES (?, ?, ?, 'verifying', ?, 1)"
        );
        if (!$insert) throw new RuntimeException('job_insert_prepare');
        $insert->bind_param('ssis', $attemptUuid, $slipHash, $userId, $remark);
        if (!$insert->execute()) { $insert->close(); throw new RuntimeException('job_insert'); }
        $inserted = $insert->affected_rows === 1;
        $insert->close();
        if ($inserted) {
            $conn->commit();
            return [
                'success' => true,
                'new' => true,
                'retry' => false,
                'resume' => false,
                'attempt_id' => $attemptUuid,
                'slip_hash' => $slipHash,
            ];
        }

        $select = $conn->prepare('SELECT * FROM slip_verification_jobs WHERE slip_hash = ? LIMIT 1 FOR UPDATE');
        if (!$select) throw new RuntimeException('job_select_prepare');
        $select->bind_param('s', $slipHash);
        if (!$select->execute()) { $select->close(); throw new RuntimeException('job_select'); }
        $result = $select->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $select->close();
        if (!$row) throw new RuntimeException('job_missing');

        $existingAttempt = (string) ($row['attempt_uuid'] ?? $attemptUuid);
        if ((int) ($row['user_id'] ?? 0) !== $userId) {
            $conn->commit();
            return ['success' => false, 'reason' => 'different_user', 'attempt_id' => $existingAttempt];
        }

        $status = strtolower((string) ($row['status'] ?? 'failed'));
        $updatedTs = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
        $lastErrorCode = strtolower(trim((string) ($row['last_error_code'] ?? '')));
        if ($status === 'failed' && $lastErrorCode === 'shared_history_sync_pending' && $updatedTs > time() - 10) {
            $conn->commit();
            return [
                'success' => false,
                'reason' => 'history_cooldown',
                'attempt_id' => $existingAttempt,
                'job' => $row,
                'retry_after_seconds' => max(1, 10 - max(0, time() - $updatedTs)),
            ];
        }
        if ($status === 'completed') {
            $conn->commit();
            return ['success' => false, 'reason' => 'completed', 'attempt_id' => $existingAttempt, 'job' => $row];
        }
        // A legitimate EasySlip request can now remain in-flight for up to forty
        // seconds. The old 15-second freshness window allowed a second browser
        // submission to hijack the same durable job while request #1 was still
        // running. Keep verification protected for the full one-minute customer
        // budget; financial crediting remains a shorter local transaction phase.
        $processingFreshSeconds = $status === 'verifying' ? 60 : 30;
        if (in_array($status, ['verifying', 'crediting'], true) && $updatedTs > time() - $processingFreshSeconds) {
            $conn->commit();
            return ['success' => false, 'reason' => 'processing', 'attempt_id' => $existingAttempt, 'job' => $row];
        }

        $providerData = slipVerificationDecryptProviderData((string) ($row['provider_data_ciphertext'] ?? ''));
        $resume = is_array($providerData) && $providerData !== [];
        $nextRetryTs = !empty($row['provider_next_retry_at']) ? strtotime((string) $row['provider_next_retry_at']) : false;
        if (!$resume && $nextRetryTs !== false && $nextRetryTs > time()) {
            $conn->commit();
            return [
                'success' => false,
                'reason' => 'provider_cooldown',
                'attempt_id' => $existingAttempt,
                'job' => $row,
                'retry_after_seconds' => max(1, (int) ceil($nextRetryTs - time())),
            ];
        }
        $nextStatus = $resume ? 'provider_verified' : 'verifying';
        $update = $conn->prepare(
            "UPDATE slip_verification_jobs
             SET status = ?, verification_remark = ?, attempts = attempts + 1,
                 last_error_code = '', last_error_message = '', updated_at = NOW()
             WHERE attempt_uuid = ?"
        );
        if (!$update) throw new RuntimeException('job_retry_prepare');
        $update->bind_param('sss', $nextStatus, $remark, $existingAttempt);
        if (!$update->execute()) { $update->close(); throw new RuntimeException('job_retry'); }
        $update->close();
        $conn->commit();
        return [
            'success' => true,
            'new' => false,
            'retry' => true,
            'resume' => $resume,
            'provider_data' => $providerData,
            'attempt_id' => $existingAttempt,
            'slip_hash' => $slipHash,
            'previous_status' => $status,
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Durable slip job claim failed: ' . $e->getMessage());
        return ['success' => false, 'reason' => 'storage', 'attempt_id' => $attemptUuid];
    }
}

function slipVerificationUpdateJobRemark(string $slipHash, string $attemptUuid, int $userId, string $remark): bool
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $attemptUuid = normalizeSlipVerificationAttemptUuid($attemptUuid);
    $userId = (int) $userId;
    $remark = substr(trim($remark), 0, 255);
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || $attemptUuid === '' || $userId < 1 || $remark === '') return false;
    $stmt = $conn->prepare(
        'UPDATE slip_verification_jobs SET verification_remark = ?, updated_at = NOW() '
        . 'WHERE slip_hash = ? AND attempt_uuid = ? AND user_id = ?'
    );
    if (!$stmt) return false;
    $stmt->bind_param('sssi', $remark, $slipHash, $attemptUuid, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function slipVerificationSetJobStatus(
    string $slipHash,
    string $status,
    string $errorCode = '',
    string $errorMessage = ''
): bool {
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $status = strtolower(trim($status));
    $allowed = ['verifying', 'provider_pending', 'provider_verified', 'crediting', 'completed', 'failed', 'review'];
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !in_array($status, $allowed, true)
        || !ensureSlipVerificationJobsTable()) return false;
    $errorCode = substr(trim($errorCode), 0, 80);
    $errorMessage = substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $errorMessage)), 0, 500);
    $stmt = $conn->prepare(
        'UPDATE slip_verification_jobs SET status = ?, last_error_code = ?, last_error_message = ?, updated_at = NOW() '
        . 'WHERE slip_hash = ?'
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssss', $status, $errorCode, $errorMessage, $slipHash);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $debugJob = slipVerificationReadJobByHash($slipHash);
        slipDebugLogEvent('job_status_changed', in_array($status, ['failed', 'review'], true) ? 'warning' : 'info', 'Slip verification job status updated', [
            'attempt_uuid' => $debugJob['attempt_uuid'] ?? '',
            'slip_hash' => $slipHash,
            'user_id' => (int) ($debugJob['user_id'] ?? 0),
            'error_code' => $errorCode,
            'response' => [
                'status' => $status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'attempts' => isset($debugJob['attempts']) ? (int) $debugJob['attempts'] : null,
            ],
        ]);
    }
    return $ok;
}

function slipVerificationProviderDuplicateRecoveryAllowed(
    bool $providerDuplicate,
    bool $trustedOwnRequest,
    bool $hasReusableProviderSnapshot,
    int $providerRequestCount,
    bool $sharedHistoryReady = false
): bool {
    if (!$providerDuplicate) return true;
    // A pre-existing encrypted snapshot plus our signed attempt provenance proves
    // this durable job already received a provider result. Otherwise a provider
    // duplicate is safe only after every configured website has published that
    // its pre-upgrade slip history is fully represented in the central ledger.
    // Merely counting outbound API attempts is intentionally NOT enough: a timed-
    // out first request cannot prove whether the provider received it, and using
    // that ambiguity as financial evidence could admit a historical reused slip.
    if ($hasReusableProviderSnapshot && $trustedOwnRequest) return true;
    return $sharedHistoryReady;
}

function slipVerificationSharedHistoryReady(): array
{
    if (!function_exists('sharedLedgerHistoryReady')) {
        return ['success' => false, 'ready' => false, 'message' => 'Shared-history readiness service is unavailable'];
    }

    // Readiness is monotonic for a fixed set of website identities: once every
    // configured site's historical marker is completed, those facts never need
    // to be "unmigrated". Cache the successful global decision locally so normal
    // deposits do not spend another remote Store API round-trip on every slip.
    // Bind the cache to the current expected-site fingerprint so adding/renaming a
    // site automatically invalidates an older two-site readiness decision.
    $siteIds = function_exists('sharedLedgerExpectedSiteIds') ? sharedLedgerExpectedSiteIds() : [];
    $normalizedIds = [];
    foreach ((array) $siteIds as $siteId) {
        if (!is_scalar($siteId)) continue;
        $siteId = strtoupper(trim((string) $siteId));
        if ($siteId !== '') $normalizedIds[$siteId] = true;
    }
    $normalizedIds = array_keys($normalizedIds);
    sort($normalizedIds, SORT_STRING);
    $fingerprint = $normalizedIds !== []
        ? hash('sha256', implode("\0", $normalizedIds))
        : '';
    $cachedFingerprint = trim((string) getSetting('shared_ledger_global_slip_history_ready_fingerprint', ''));
    $cachedReady = trim((string) getSetting('shared_ledger_global_slip_history_ready', '0'));
    if ($fingerprint !== '' && hash_equals($fingerprint, $cachedFingerprint)) {
        if ($cachedReady === '1') {
            return [
                'success' => true,
                'ready' => true,
                'site_ids' => $normalizedIds,
                'completed_site_ids' => $normalizedIds,
                'missing_site_ids' => [],
                'cached' => true,
            ];
        }
        // While the one-time migration is incomplete, many customers can arrive
        // at once. A tiny negative cache prevents every upload from issuing its
        // own shared_history_status request to the hub. It never grants credit:
        // stale "not ready" can only delay availability by a few seconds.
        $checkedAt = (int) getSetting('shared_ledger_global_slip_history_checked_at', '0');
        if ($checkedAt > 0 && $checkedAt >= time() - 3) {
            return [
                'success' => true,
                'ready' => false,
                'site_ids' => $normalizedIds,
                'completed_site_ids' => [],
                'missing_site_ids' => $normalizedIds,
                'cached' => true,
                'negative_cache' => true,
            ];
        }
    }

    try {
        $result = sharedLedgerHistoryReady($normalizedIds);
        if (!is_array($result)) {
            return ['success' => false, 'ready' => false, 'message' => 'Shared-history readiness response is invalid'];
        }
        if (!empty($result['success']) && $fingerprint !== '') {
            // Cache both authoritative outcomes. A positive result is monotonic
            // for this site fingerprint; a negative result expires after three
            // seconds and therefore can never create a false financial decision.
            if (upsertSetting('shared_ledger_global_slip_history_ready_fingerprint', $fingerprint)) {
                upsertSetting('shared_ledger_global_slip_history_ready', !empty($result['ready']) ? '1' : '0');
                upsertSetting('shared_ledger_global_slip_history_checked_at', (string) time());
            }
        }
        return $result;
    } catch (Throwable $e) {
        error_log('Slip shared-history readiness check failed: ' . $e->getMessage());
        return ['success' => false, 'ready' => false, 'message' => 'Shared-history readiness check failed'];
    }
}

function slipVerificationMarkProviderRequest(string $slipHash): int
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable()) return 0;
    $stmt = $conn->prepare(
        'UPDATE slip_verification_jobs SET provider_request_count = provider_request_count + 1, '
        . 'provider_first_attempt_at = COALESCE(provider_first_attempt_at, NOW()), provider_last_attempt_at = NOW(), updated_at = NOW() '
        . 'WHERE slip_hash = ?'
    );
    if (!$stmt) return 0;
    $stmt->bind_param('s', $slipHash);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return 0;
    $job = slipVerificationReadJobByHash($slipHash);
    return max(0, (int) ($job['provider_request_count'] ?? 0));
}


function slipVerificationRecordProviderOutcome(string $slipHash, array $verification): void
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable()) return;
    $success = !empty($verification['success']);
    $errorCode = strtolower(trim((string) ($verification['error_code'] ?? '')));
    $retryable = !empty($verification['retryable']);
    $transportClass = substr(trim((string) ($verification['transport_class'] ?? ($success ? 'http_response' : ''))), 0, 32);
    $httpCode = max(0, min(999, (int) ($verification['provider_http_code'] ?? 0)));
    $ipFamily = in_array((string) ($verification['provider_ip_family'] ?? ''), ['IPv4', 'IPv6'], true)
        ? (string) $verification['provider_ip_family'] : '';
    $transportFailure = in_array($errorCode, [
        'provider_timeout', 'provider_outcome_unknown', 'provider_connection', 'provider_invalid_response',
        'provider_response_too_large', 'provider_http_unavailable', 'provider_circuit_open', 'provider_busy', 'customer_deadline_exceeded'
    ], true);
    $timeoutFailure = in_array($errorCode, ['provider_timeout', 'provider_outcome_unknown'], true);
    $cooldown = isset($verification['retry_after_seconds'])
        ? max(1, min(600, (int) $verification['retry_after_seconds']))
        : (int) slipProviderPolicy()['retry_cooldown_seconds'];

    if ($success) {
        $stmt = $conn->prepare(
            "UPDATE slip_verification_jobs SET provider_next_retry_at=NULL, provider_timeout_streak=0,
             provider_last_transport_class=?, provider_last_http_code=NULLIF(?,0), provider_last_ip_family=?, updated_at=NOW()
             WHERE slip_hash=?"
        );
        if ($stmt) {
            $stmt->bind_param('siss', $transportClass, $httpCode, $ipFamily, $slipHash);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $nextRetrySql = $retryable ? 'DATE_ADD(NOW(), INTERVAL ? SECOND)' : 'NULL';
    $streakSql = $timeoutFailure ? 'provider_timeout_streak + 1' : ($transportFailure ? 'provider_timeout_streak' : '0');
    $stmt = $conn->prepare(
        "UPDATE slip_verification_jobs SET provider_next_retry_at={$nextRetrySql}, provider_timeout_streak={$streakSql},
         provider_last_transport_class=?, provider_last_http_code=NULLIF(?,0), provider_last_ip_family=?, updated_at=NOW()
         WHERE slip_hash=?"
    );
    if (!$stmt) return;
    if ($retryable) {
        $stmt->bind_param('isiss', $cooldown, $transportClass, $httpCode, $ipFamily, $slipHash);
    } else {
        $stmt->bind_param('siss', $transportClass, $httpCode, $ipFamily, $slipHash);
    }
    $stmt->execute();
    $stmt->close();
}

function slipVerificationSetRetryCooldown(string $slipHash, int $seconds): void
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $seconds = max(1, min(600, $seconds));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable()) return;
    $stmt = $conn->prepare(
        'UPDATE slip_verification_jobs SET provider_next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at=NOW() WHERE slip_hash=?'
    );
    if (!$stmt) return;
    $stmt->bind_param('is', $seconds, $slipHash);
    $stmt->execute();
    $stmt->close();
}

function slipVerificationStoreProviderData(string $slipHash, array $slipData): bool
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $ciphertext = slipVerificationEncryptProviderData($slipData);
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || $ciphertext === null || !ensureSlipVerificationJobsTable()) return false;
    $transactionRef = substr(trim((string) ($slipData['transaction_ref'] ?? '')), 0, 100);
    $duplicate = !empty($slipData['is_duplicate']) ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE slip_verification_jobs
         SET status = 'provider_verified', transaction_ref = NULLIF(?, ''),
             provider_data_ciphertext = ?, provider_is_duplicate = ?,
             provider_verified_at = COALESCE(provider_verified_at, NOW()),
             provider_next_retry_at = NULL, provider_timeout_streak = 0,
             last_error_code = '', last_error_message = '', updated_at = NOW()
         WHERE slip_hash = ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ssis', $transactionRef, $ciphertext, $duplicate, $slipHash);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if ($ok) {
        $debugJob = slipVerificationReadJobByHash($slipHash);
        slipDebugLogEvent('provider_snapshot_saved', 'info', 'Normalized EasySlip data saved for durable recovery', [
            'attempt_uuid' => $debugJob['attempt_uuid'] ?? '',
            'slip_hash' => $slipHash,
            'user_id' => (int) ($debugJob['user_id'] ?? 0),
            'response' => [
                'transaction_ref' => $transactionRef,
                'provider_is_duplicate' => (bool) $duplicate,
                'normalized_provider_data' => $slipData,
            ],
        ]);
    }
    return $ok;
}

function slipVerificationCompleteJob(
    string $slipHash,
    string $transactionRef,
    int $slipDepositId,
    int $depositTransactionId,
    float $creditAmount,
    float $bonusAmount,
    float $totalCredited
): bool {
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    $transactionRef = substr(trim($transactionRef), 0, 100);
    $creditAmount = round($creditAmount, 2);
    $bonusAmount = round($bonusAmount, 2);
    $totalCredited = round($totalCredited, 2);
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || $slipDepositId < 1 || $depositTransactionId < 1
        || !ensureSlipVerificationJobsTable()) return false;
    $stmt = $conn->prepare(
        "UPDATE slip_verification_jobs
         SET status = 'completed', transaction_ref = NULLIF(?, ''), slip_deposit_id = ?,
             deposit_transaction_id = ?, credit_amount = ?, bonus_amount = ?, total_credited = ?,
             last_error_code = '', last_error_message = '', completed_at = NOW(), terminal_at = NOW(), shared_finalized_at = NULL, updated_at = NOW()
         WHERE slip_hash = ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param(
        'siiddds',
        $transactionRef,
        $slipDepositId,
        $depositTransactionId,
        $creditAmount,
        $bonusAmount,
        $totalCredited,
        $slipHash
    );
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        $debugJob = slipVerificationReadJobByHash($slipHash);
        slipDebugLogEvent('job_completed', 'info', 'Slip verification and balance credit completed', [
            'attempt_uuid' => $debugJob['attempt_uuid'] ?? '',
            'slip_hash' => $slipHash,
            'user_id' => (int) ($debugJob['user_id'] ?? 0),
            'response' => [
                'transaction_ref' => $transactionRef,
                'slip_deposit_id' => $slipDepositId,
                'deposit_transaction_id' => $depositTransactionId,
                'credit_amount' => $creditAmount,
                'bonus_amount' => $bonusAmount,
                'total_credited' => $totalCredited,
            ],
        ]);
    }
    return $ok;
}

function slipVerificationFindDeposit(string $transactionRef = '', string $slipHash = ''): ?array
{
    global $conn;
    $transactionRef = substr(trim($transactionRef), 0, 100);
    $slipHash = strtolower(trim($slipHash));
    if ($transactionRef === '' && !preg_match('/^[a-f0-9]{64}$/D', $slipHash)) return null;
    if ($transactionRef !== '' && preg_match('/^[a-f0-9]{64}$/D', $slipHash)) {
        $stmt = $conn->prepare('SELECT * FROM slip_deposits WHERE transaction_ref = ? OR slip_hash = ? ORDER BY id ASC LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('ss', $transactionRef, $slipHash);
    } elseif ($transactionRef !== '') {
        $stmt = $conn->prepare('SELECT * FROM slip_deposits WHERE transaction_ref = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $transactionRef);
    } else {
        $stmt = $conn->prepare('SELECT * FROM slip_deposits WHERE slip_hash = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $slipHash);
    }
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function slipVerificationFindDepositTransaction(int $slipDepositId, int $userId): ?array
{
    global $conn;
    if ($slipDepositId < 1 || $userId < 1) return null;
    $stmt = $conn->prepare(
        "SELECT * FROM transactions
         WHERE user_id = ? AND type = 'deposit' AND status = 'completed'
           AND reference_id = ? ORDER BY id ASC LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('ii', $userId, $slipDepositId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function slipVerificationReadAuthoritativeBonus(int $depositTransactionId, float $baseCredit): array
{
    global $conn;
    $baseCredit = round(max(0.0, $baseCredit), 2);
    if ($depositTransactionId < 1) {
        return ['available' => true, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'none'];
    }
    try {
        $table = $conn->query("SHOW TABLES LIKE 'rank_bonus_awards'");
        $exists = $table && $table->num_rows > 0;
        if ($table) $table->free();
        if (!$exists) return ['available' => true, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'none'];
        $stmt = $conn->prepare(
            'SELECT bonus_amount, status FROM rank_bonus_awards WHERE deposit_transaction_id = ? LIMIT 1'
        );
        if (!$stmt) return ['available' => false, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'unavailable'];
        $stmt->bind_param('i', $depositTransactionId);
        if (!$stmt->execute()) { $stmt->close(); return ['available' => false, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'unavailable']; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return ['available' => true, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'none'];
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        // The award row is written in the same DB transaction as the deposit.
        // Once the deposit transaction exists, its recorded bonus amount is the
        // authoritative audit source even when the durable slip job update was
        // interrupted after COMMIT.
        $bonus = round(max(0.0, (float) ($row['bonus_amount'] ?? 0)), 2);
        return [
            'available' => true,
            'bonus_amount' => $bonus,
            'total_credited' => round($baseCredit + $bonus, 2),
            'source' => 'rank_bonus_awards',
            'status' => $status,
        ];
    } catch (Throwable $e) {
        error_log('Slip bonus recovery lookup failed: ' . $e->getMessage());
        return ['available' => false, 'bonus_amount' => 0.0, 'total_credited' => $baseCredit, 'source' => 'unavailable'];
    }
}

function slipVerificationFinalizeExistingDeposit(array $job, array $deposit): array
{
    $userId = (int) ($job['user_id'] ?? 0);
    if ((int) ($deposit['user_id'] ?? 0) !== $userId) {
        // This is not an ambiguous financial state: the authoritative local
        // deposit already belongs to another account, so the correct automatic
        // outcome is a terminal duplicate rejection rather than admin review.
        slipVerificationSetJobStatus((string) $job['slip_hash'], 'failed', 'credited_to_different_user', 'สลิปนี้ถูกเติมเข้าบัญชีอื่นแล้ว');
        return ['success' => false, 'retryable' => false, 'code' => 'credited_to_different_user', 'message' => 'สลิปนี้ถูกใช้งานแล้ว ไม่สามารถเติมซ้ำได้'];
    }
    $depositId = (int) ($deposit['id'] ?? 0);
    $transaction = slipVerificationFindDepositTransaction($depositId, $userId);
    if (!$transaction) {
        slipVerificationSetJobStatus((string) $job['slip_hash'], 'review', 'deposit_without_transaction', 'พบหลักฐานสลิปแต่ไม่พบธุรกรรมเติมเงิน');
        return ['success' => false, 'review' => true, 'message' => 'พบข้อมูลสลิปที่ต้องตรวจสอบโดยผู้ดูแลระบบ'];
    }
    $credit = round((float) ($transaction['amount'] ?? 0), 2);
    $bonusEvidence = slipVerificationReadAuthoritativeBonus((int) ($transaction['id'] ?? 0), $credit);
    if (empty($bonusEvidence['available'])) {
        slipVerificationSetJobStatus((string) $job['slip_hash'], 'provider_verified', 'bonus_recovery_unavailable', 'เติมเงินสำเร็จแล้ว แต่ระบบกำลังซิงค์หลักฐานโบนัสอัตโนมัติ');
        return [
            'success' => false,
            'pending' => true,
            'attempt_id' => (string) ($job['attempt_uuid'] ?? ''),
            'message' => 'ยอดเงินถูกบันทึกแล้ว ระบบกำลังซิงค์รายละเอียดโบนัสอัตโนมัติ',
        ];
    }
    $bonus = max(0.0, round((float) ($bonusEvidence['bonus_amount'] ?? 0), 2));
    $total = max($credit, round((float) ($bonusEvidence['total_credited'] ?? ($credit + $bonus)), 2));
    slipVerificationCompleteJob(
        (string) $job['slip_hash'],
        (string) ($deposit['transaction_ref'] ?? ''),
        $depositId,
        (int) ($transaction['id'] ?? 0),
        $credit,
        $bonus,
        $total
    );
    return [
        'success' => true,
        'already_completed' => true,
        'amount' => $credit,
        'bonus_amount' => $bonus,
        'total_credited' => $total,
        'new_balance' => (float) getUserBalance($userId),
        'attempt_id' => (string) ($job['attempt_uuid'] ?? ''),
        'message' => 'รายการนี้เติมเงินสำเร็จแล้ว',
    ];
}

function slipVerificationJobIsAutoRecoverable(array $job): bool
{
    $status = strtolower(trim((string) ($job['status'] ?? '')));
    $code = strtolower(trim((string) ($job['last_error_code'] ?? '')));
    $hasProviderSnapshot = trim((string) ($job['provider_data_ciphertext'] ?? '')) !== '';
    if (in_array($status, ['provider_verified', 'crediting'], true)) return $hasProviderSnapshot;

    if ($status === 'failed') {
        // These failures are recoverable only after EasySlip has already been
        // verified and an encrypted provider snapshot exists. In particular, a
        // shared-ledger outage before the provider request must ask the browser
        // to submit the same image again; trying to auto-recover an empty snapshot
        // only turns a short outage into provider_snapshot_missing.
        return $hasProviderSnapshot && in_array($code, [
            'exchange_rate',
            'ranking_schema',
            'shared_unavailable',
            'shared_reserve_failed',
            'balance_update_failed',
            'wallet_balance_read_failed',
            'wallet_audit_failed',
            'slip_record_failed',
            'transaction_record_failed',
            'duplicate_check_prepare',
            'duplicate_check',
        ], true);
    }

    // Commit outcome is deliberately recoverable: completeVerified... first
    // looks for the authoritative deposit/transaction rows before attempting
    // any new credit, so polling can reconcile it without an administrator.
    return $hasProviderSnapshot && $status === 'review' && $code === 'commit_outcome_unknown';
}

function slipVerificationPublicJobStatus(array $job): array
{
    $status = strtolower((string) ($job['status'] ?? ''));
    $attemptId = (string) ($job['attempt_uuid'] ?? '');
    if ($status === 'completed') {
        return [
            'success' => true,
            'completed' => true,
            'attempt_id' => $attemptId,
            'amount' => round((float) ($job['credit_amount'] ?? 0), 2),
            'bonus_amount' => round((float) ($job['bonus_amount'] ?? 0), 2),
            'total_credited' => round((float) ($job['total_credited'] ?? $job['credit_amount'] ?? 0), 2),
            'new_balance' => (float) getUserBalance((int) ($job['user_id'] ?? 0)),
            'message' => 'เติมเงินสำเร็จ',
        ];
    }
    if ($status === 'review') {
        $reviewCode = strtolower(trim((string) ($job['last_error_code'] ?? 'review')));
        if ($reviewCode === 'commit_outcome_unknown') {
            return [
                'success' => false,
                'pending' => true,
                'review' => false,
                'attempt_id' => $attemptId,
                'code' => $reviewCode,
                'message' => 'ระบบกำลังยืนยันผลการบันทึกยอดเงินอัตโนมัติ กรุณาอย่าส่งสลิปซ้ำ',
            ];
        }
        return [
            'success' => false,
            'pending' => false,
            'review' => true,
            'attempt_id' => $attemptId,
            'code' => $reviewCode,
            'message' => 'พบข้อมูลที่ขัดแย้งกันและต้องตรวจสอบเพื่อป้องกันยอดเงินผิดพลาด กรุณาอย่าส่งสลิปซ้ำ',
        ];
    }
    $activeCode = strtolower(trim((string) ($job['last_error_code'] ?? '')));
    if ($status === 'provider_verified' && $activeCode === 'shared_history_sync_pending') {
        return [
            'success' => false,
            'pending' => false,
            'retryable' => true,
            'attempt_id' => $attemptId,
            'code' => 'shared_history_sync_pending',
            'error_code' => 'shared_history_sync_pending',
            'retry_after_seconds' => 15,
            'message' => 'ตรวจสลิปผ่านแล้ว แต่ระบบกำลังซิงค์ประวัติระหว่างเว็บไซต์ รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมลองใหม่ภายหลัง',
        ];
    }
    if ($status === 'provider_pending') {
        $nextRetryTs = !empty($job['provider_next_retry_at']) ? strtotime((string) $job['provider_next_retry_at']) : false;
        $retryAfter = $nextRetryTs !== false ? max(0, (int) ceil($nextRetryTs - time())) : 0;
        if ($retryAfter > 0) {
            return [
                'success' => false,
                'pending' => true,
                'retryable' => false,
                'attempt_id' => $attemptId,
                'code' => 'provider_pending_confirmation',
                'error_code' => 'provider_pending_confirmation',
                'retry_after_seconds' => $retryAfter,
                'message' => 'เว็บส่งสลิปไปตรวจครบแล้ว แต่ยังไม่ได้รับผลยืนยันกลับมา ระบบจะไม่ส่งซ้ำระหว่างช่วงรอนี้ กรุณารออีกประมาณ ' . $retryAfter . ' วินาที',
            ];
        }
        return [
            'success' => false,
            'pending' => false,
            'retryable' => true,
            'attempt_id' => $attemptId,
            'code' => 'provider_pending_confirmation',
            'error_code' => 'provider_pending_confirmation',
            'retry_after_seconds' => 0,
            'message' => 'ยังไม่ได้รับผลยืนยันจากระบบตรวจสลิปภายในเวลาที่กำหนด รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมส่งอีกครั้ง ระบบจะใช้รายการเดิมและตรวจป้องกันยอดซ้ำให้',
        ];
    }
    if (in_array($status, ['verifying', 'provider_verified', 'crediting'], true)) {
        return [
            'success' => false,
            'pending' => true,
            'attempt_id' => $attemptId,
            'code' => $status,
            'message' => 'ระบบกำลังตรวจสอบและกู้คืนรายการนี้อัตโนมัติ กรุณาอย่าส่งสลิปซ้ำ',
        ];
    }
    $failedCode = strtolower(trim((string) ($job['last_error_code'] ?? 'failed')));
    $terminalValidationCodes = [
        'older_than_max_age',
        'future_beyond_allowance',
        'parse_error',
        'empty_date',
        'invalid_date',
        'invalid_country',
        'invalid_currency',
        'missing_transaction_ref',
        'receiver_mismatch',
        'invalid_amount',
        'unsupported_currency',
        'receiver_not_configured',
        'receiver_missing',
        'provider_duplicate_untrusted',
        'shared_already_completed',
        'slip_hash_used',
        'credited_to_different_user',
    ];
    $autoRecoverable = slipVerificationJobIsAutoRecoverable($job);
    $retryable = $autoRecoverable || !in_array($failedCode, $terminalValidationCodes, true);
    $sameSlipResubmitCodes = [
        'shared_unavailable', 'provider_timeout', 'provider_outcome_unknown', 'provider_connection', 'provider_invalid_response',
        'provider_response_too_large', 'provider_circuit_open', 'customer_deadline_exceeded',
        'snapshot_encryption_unavailable', 'provider_request_state_failed',
        'provider_snapshot_write_failed', 'provider_snapshot_missing', 'verification_interrupted',
    ];
    $message = $autoRecoverable
        ? 'ระบบกำลังกู้คืนรายการนี้อัตโนมัติ กรุณาอย่าส่งสลิปซ้ำ'
        : ((string) ($job['last_error_message'] ?? '') !== ''
            ? (string) $job['last_error_message']
            : 'ตรวจสอบรายการไม่สำเร็จ');
    if (!$autoRecoverable && $retryable && in_array($failedCode, $sameSlipResubmitCodes, true)
        && strpos($message, 'สลิปเดิม') === false) {
        $message = rtrim($message, ' .') . ' กรุณาใช้สลิปเดิมส่งใหม่ ระบบจะตรวจรายการเดิมก่อนเพื่อป้องกันการเติมซ้ำ';
    }
    return [
        'success' => false,
        'pending' => $autoRecoverable,
        'retryable' => $retryable,
        'retry_after_seconds' => (!empty($job['provider_next_retry_at']) && strtotime((string) $job['provider_next_retry_at']) !== false)
            ? max(0, (int) ceil(strtotime((string) $job['provider_next_retry_at']) - time()))
            : 0,
        'attempt_id' => $attemptId,
        'code' => $failedCode,
        'message' => $message,
    ];
}


/**
 * Save slip deposit record
 */
function saveSlipDeposit($userId, $slipData, $slipImage = null, $slipHash = null)
{
    global $conn;

    $clip = static function ($value, int $maxLength): string {
        $value = trim((string) $value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    };

    // Keep values within the legacy schema limits. Some banks return long
    // display names/account metadata; strict MySQL mode otherwise rejects the
    // whole deposit after verification has already succeeded.
    $transactionRef = $clip($slipData['transaction_ref'] ?? '', 100);
    $senderName = $clip($slipData['sender_name'] ?? '', 100);
    $senderAccount = $clip($slipData['sender_account'] ?? '', 50);
    $receiverName = $clip($slipData['receiver_name'] ?? '', 100);
    $receiverAccount = $clip($slipData['receiver_account'] ?? '', 50);
    $bankCode = $clip($slipData['bank_code'] ?? '', 20);
    $transferDate = $clip($slipData['transfer_date'] ?? '', 50);
    $amount = round((float) ($slipData['amount'] ?? 0), 2);

    if ($transactionRef === '' || !is_finite($amount) || $amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO slip_deposits 
        (user_id, transaction_ref, amount, sender_name, sender_account, receiver_name, receiver_account, bank_code, transfer_date, slip_image, slip_hash, api_response) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log('Slip deposit prepare failed: ' . $conn->error);
        return false;
    }

    // Store only non-sensitive verification metadata. The normalized transaction
    // fields are already saved in dedicated columns, so retaining the full API response
    // would unnecessarily duplicate personal banking data.
    $verificationMetadata = [
        'provider' => 'easyslip',
        'verified' => true,
        'stored_at' => date('c'),
    ];
    if (isset($slipData['_date_correction']) && is_array($slipData['_date_correction'])) {
        $dateCorrection = $slipData['_date_correction'];
        $verificationMetadata['date_correction'] = [
            'code' => substr(trim((string) ($dateCorrection['code'] ?? '')), 0, 80),
            'provider_date' => substr(trim((string) ($dateCorrection['provider_date'] ?? '')), 0, 50),
            'effective_date' => substr(trim((string) ($dateCorrection['effective_date'] ?? '')), 0, 50),
            'correction_seconds' => (int) ($dateCorrection['correction_seconds'] ?? 0),
            'provider_observed_at' => substr(trim((string) ($dateCorrection['provider_observed_at'] ?? '')), 0, 50),
            'anchor_reason' => substr(trim((string) ($dateCorrection['anchor_reason'] ?? '')), 0, 80),
            'policy' => substr(trim((string) ($dateCorrection['policy'] ?? '')), 0, 80),
            'applied_at' => substr(trim((string) ($dateCorrection['applied_at'] ?? '')), 0, 50),
        ];
    }
    $apiResponse = json_encode($verificationMetadata, JSON_UNESCAPED_SLASHES);

    $stmt->bind_param(
        'isdsssssssss',
        $userId,
        $transactionRef,
        $amount,
        $senderName,
        $senderAccount,
        $receiverName,
        $receiverAccount,
        $bankCode,
        $transferDate,
        $slipImage,
        $slipHash,
        $apiResponse
    );

    $ok = $stmt->execute();
    $insertId = $ok ? (int) $conn->insert_id : 0;
    $stmt->close();
    return $insertId > 0 ? $insertId : false;
}

/**
 * Get current exchange rate THB -> USD
 */
function getUsdToThbRate(): float
{
    // The shop owner controls the accounting rate. No page request contacts an
    // external FX provider, so pricing cannot stall when a third-party API is
    // slow or unavailable. The stored value means: 1 USD = N THB.
    $configured = getSetting('usd_thb_rate', '35');
    $rate = is_numeric($configured) ? (float) $configured : 35.0;
    if (!is_finite($rate) || $rate < 1.0 || $rate > 1000.0) {
        $rate = 35.0;
    }
    return $rate;
}

/**
 * Return USD per THB for legacy callers.
 *
 * Historical code multiplies THB by this value to obtain USD and divides USD
 * by it to obtain THB. Keep that contract, but derive it from the administrator
 * controlled THB-per-USD setting instead of a network request.
 */
function getExchangeRateThbToUsd(int $networkTimeoutSeconds = 8)
{
    static $cachedRate = null;
    if ($cachedRate !== null) return $cachedRate;

    $usdToThb = getUsdToThbRate();
    if (!is_finite($usdToThb) || $usdToThb <= 0) return false;
    $cachedRate = 1.0 / $usdToThb;
    return $cachedRate;
}

/**
 * Compare a configured receiver account/phone against an EasySlip value.
 * Returns match strength so short masked values require a stronger name match.
 */
function receiverAccountMatchDetails($expectedAccount, $apiAccount, bool $isPhone = false): array
{
    $expectedDigits = preg_replace('/\D+/', '', (string) $expectedAccount);
    $apiCompact = strtolower((string) preg_replace('/[^0-9x*]/i', '', (string) $apiAccount));
    $noMatch = ['matched' => false, 'strength' => 0, 'visible_digits' => 0];
    if ($expectedDigits === '' || $apiCompact === '') return $noMatch;

    $expectedVariants = [$expectedDigits];
    if ($isPhone) {
        if (preg_match('/^0[0-9]{9}$/', $expectedDigits)) {
            $expectedVariants[] = '66' . substr($expectedDigits, 1);
        } elseif (preg_match('/^66[0-9]{9}$/', $expectedDigits)) {
            $expectedVariants[] = '0' . substr($expectedDigits, 2);
        }
    }
    $expectedVariants = array_values(array_unique($expectedVariants));
    $best = $noMatch;

    foreach ($expectedVariants as $expected) {
        if (preg_match('/^[0-9]+$/', $apiCompact)) {
            if (strlen($apiCompact) === strlen($expected) && hash_equals($expected, $apiCompact)) {
                return ['matched' => true, 'strength' => 3, 'visible_digits' => strlen($apiCompact)];
            }
            if (strlen($apiCompact) >= 4 && strlen($apiCompact) < strlen($expected)
                && hash_equals(substr($expected, -strlen($apiCompact)), $apiCompact)) {
                $strength = strlen($apiCompact) >= 6 ? 2 : 1;
                if ($strength > $best['strength']) {
                    $best = ['matched' => true, 'strength' => $strength, 'visible_digits' => strlen($apiCompact)];
                }
            }
            continue;
        }

        if (!preg_match('/^[0-9x*]+$/', $apiCompact)) continue;
        $visibleDigits = preg_match_all('/[0-9]/', $apiCompact);
        if ($visibleDigits < 4) continue;

        if (strlen($apiCompact) === strlen($expected)) {
            $matched = true;
            foreach (str_split($apiCompact) as $index => $character) {
                if (ctype_digit($character) && !hash_equals($character, $expected[$index])) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $strength = $visibleDigits >= 6 ? 2 : 1;
                if ($strength > $best['strength']) {
                    $best = ['matched' => true, 'strength' => $strength, 'visible_digits' => $visibleDigits];
                }
            }
            continue;
        }

        if (preg_match('/^([0-9]{1,})[x*]+([0-9]{1,})$/', $apiCompact, $parts)) {
            $prefix = $parts[1];
            $suffix = $parts[2];
            if (($visibleDigits >= 4) && strlen($expected) >= ($visibleDigits)
                && hash_equals($prefix, substr($expected, 0, strlen($prefix)))
                && hash_equals($suffix, substr($expected, -strlen($suffix)))) {
                $strength = $visibleDigits >= 6 ? 2 : 1;
                if ($strength > $best['strength']) {
                    $best = ['matched' => true, 'strength' => $strength, 'visible_digits' => $visibleDigits];
                }
            }
        }
    }

    return $best;
}

function receiverAccountMatches($expectedAccount, $apiAccount): bool
{
    return receiverAccountMatchDetails($expectedAccount, $apiAccount)['matched'];
}

function normalizeReceiverNameForMatch($name): string
{
    $name = trim((string) $name);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = preg_replace('/^(?:นาย|นางสาว|นาง|ด\.?ช\.?|ด\.?ญ\.?|mr|mrs|ms|miss)\.?\s*/ui', '', $name);
    $name = preg_replace('/[\s\p{P}\p{S}_]+/u', '', $name);
    return (string) $name;
}

function receiverNameTokens($name): array
{
    $name = trim((string) $name);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = preg_replace('/^(?:นาย|นางสาว|นาง|ด\.?ช\.?|ด\.?ญ\.?|mr|mrs|ms|miss)\.?\s*/ui', '', $name);
    $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_values($parts) : [];
}

function receiverNameMatchStrength($configuredName, $receivedName): int
{
    $configured = normalizeReceiverNameForMatch($configuredName);
    $received = normalizeReceiverNameForMatch($receivedName);
    if ($configured === '' || $received === '') return 0;
    if (hash_equals($configured, $received)) return 3;

    $configuredLength = function_exists('mb_strlen') ? mb_strlen($configured, 'UTF-8') : strlen($configured);
    $receivedLength = function_exists('mb_strlen') ? mb_strlen($received, 'UTF-8') : strlen($received);
    $shorterLength = min($configuredLength, $receivedLength);
    $configuredContainsReceived = function_exists('mb_strpos')
        ? mb_strpos($configured, $received, 0, 'UTF-8') !== false
        : strpos($configured, $received) !== false;
    $receivedContainsConfigured = function_exists('mb_strpos')
        ? mb_strpos($received, $configured, 0, 'UTF-8') !== false
        : strpos($received, $configured) !== false;
    if ($shorterLength >= 5 && ($configuredContainsReceived || $receivedContainsConfigured)) {
        return 2;
    }

    $configuredTokens = receiverNameTokens($configuredName);
    $receivedTokens = receiverNameTokens($receivedName);
    if (!$configuredTokens || !$receivedTokens) return 0;

    $firstConfigured = $configuredTokens[0];
    $firstReceived = $receivedTokens[0];
    if ($firstConfigured === $firstReceived) {
        if (count($configuredTokens) === 1 || count($receivedTokens) === 1) return 2;
        $lastConfigured = end($configuredTokens);
        $lastReceived = end($receivedTokens);
        if ($lastConfigured === $lastReceived) return 2;
        $lastConfiguredLen = function_exists('mb_strlen') ? mb_strlen($lastConfigured, 'UTF-8') : strlen($lastConfigured);
        $lastReceivedLen = function_exists('mb_strlen') ? mb_strlen($lastReceived, 'UTF-8') : strlen($lastReceived);
        $prefixLen = min($lastConfiguredLen, $lastReceivedLen);
        if ($prefixLen >= 1) {
            $configuredPrefix = function_exists('mb_substr') ? mb_substr($lastConfigured, 0, $prefixLen, 'UTF-8') : substr($lastConfigured, 0, $prefixLen);
            $receivedPrefix = function_exists('mb_substr') ? mb_substr($lastReceived, 0, $prefixLen, 'UTF-8') : substr($lastReceived, 0, $prefixLen);
            if ($configuredPrefix === $receivedPrefix) return 2;
        }
    }

    foreach ($configuredTokens as $configuredToken) {
        $len = function_exists('mb_strlen') ? mb_strlen($configuredToken, 'UTF-8') : strlen($configuredToken);
        if ($len < 4) continue;
        foreach ($receivedTokens as $receivedToken) {
            if ($configuredToken === $receivedToken) return 1;
        }
    }
    return 0;
}

/**
 * Normalize currency values returned by slip providers.
 * EasySlip documents `THB`, while some bank/parser paths may expose the
 * ISO-4217 numeric form `764` or a human-readable Baht label.
 * Unknown values are deliberately NOT accepted.
 */
function normalizeSlipCurrencyCode($currency): string
{
    if (!is_scalar($currency)) return '';
    $value = trim((string) $currency);
    if ($value === '') return '';

    $upper = function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
    $compact = preg_replace('/[\s._()\[\]{}\-]+/u', '', $upper);
    if (!is_string($compact) || $compact === '') return '';

    $thaiBahtAliases = [
        'THB',        // ISO-4217 alphabetic code
        '764',        // ISO-4217 numeric code
        'BAHT',
        'THAIBAHT',
        'THB764',
        '764THB',
        '฿',
        'บาท',
        'ไทยบาท',
    ];
    return in_array($compact, $thaiBahtAliases, true) ? 'THB' : $compact;
}

/**
 * Validate an ISO-8601 slip timestamp against a configurable age window.
 * Allows a small future skew for server/bank clock differences.
 */
function analyzeSlipDateAcceptance($dateValue, int $maxAgeMinutes = 1440, ?int $nowTimestamp = null): array
{
    $rawType = gettype($dateValue);
    $rawValue = is_scalar($dateValue) || $dateValue === null ? (string) $dateValue : '';
    $dateValue = trim($rawValue);
    $now = $nowTimestamp ?? time();
    $futureAllowanceSeconds = 600;

    $analysis = [
        'accepted' => false,
        'reason' => '',
        'raw_type' => $rawType,
        'raw_untrimmed_value' => substr($rawValue, 0, 500),
        'raw_value' => substr($dateValue, 0, 500),
        'raw_untrimmed_length' => strlen($rawValue),
        'raw_length' => strlen($dateValue),
        'trimmed_bytes' => max(0, strlen($rawValue) - strlen($dateValue)),
        'raw_sha256' => hash('sha256', $rawValue),
        'raw_hex_prefix' => bin2hex(substr($rawValue, 0, 100)),
        'raw_valid_utf8' => function_exists('mb_check_encoding') ? mb_check_encoding($rawValue, 'UTF-8') : null,
        'max_age_minutes' => $maxAgeMinutes,
        'max_age_seconds' => $maxAgeMinutes * 60,
        'future_allowance_seconds' => $futureAllowanceSeconds,
        'php_timezone' => date_default_timezone_get(),
        'server_timestamp' => $now,
        'server_local_iso8601' => date('c', $now),
        'server_utc_iso8601' => gmdate('c', $now),
        'oldest_allowed_timestamp' => $now - ($maxAgeMinutes * 60),
        'oldest_allowed_local_iso8601' => date('c', $now - ($maxAgeMinutes * 60)),
        'latest_allowed_timestamp' => $now + $futureAllowanceSeconds,
        'latest_allowed_local_iso8601' => date('c', $now + $futureAllowanceSeconds),
        'looks_like_iso8601' => preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $dateValue) === 1,
        'possible_buddhist_year' => false,
    ];

    if (preg_match('/^(\d{4})[-\/]/', $dateValue, $yearMatch) === 1) {
        $year = (int) $yearMatch[1];
        $analysis['leading_year'] = $year;
        $analysis['possible_buddhist_year'] = $year >= 2400;
    }

    if ($dateValue === '') {
        $analysis['reason'] = 'empty_date';
        return $analysis;
    }
    if ($maxAgeMinutes < 5 || $maxAgeMinutes > 10080) {
        $analysis['reason'] = 'invalid_max_age';
        return $analysis;
    }

    try {
        $date = new DateTimeImmutable($dateValue);
        $parseDiagnostics = DateTimeImmutable::getLastErrors();
        $analysis['parse_diagnostics'] = is_array($parseDiagnostics) ? $parseDiagnostics : [
            'warning_count' => 0,
            'warnings' => [],
            'error_count' => 0,
            'errors' => [],
        ];
        // DateTime may normalize impossible dates (for example 30 February)
        // while only emitting a parse warning. Financial validation must never
        // accept a provider timestamp that PHP silently repaired for us.
        if ((int) ($analysis['parse_diagnostics']['warning_count'] ?? 0) > 0
            || (int) ($analysis['parse_diagnostics']['error_count'] ?? 0) > 0) {
            $analysis['reason'] = 'parse_error';
            return $analysis;
        }
    } catch (Throwable $e) {
        $analysis['reason'] = 'parse_error';
        $analysis['parse_exception'] = get_class($e);
        $analysis['parse_error'] = substr($e->getMessage(), 0, 500);
        return $analysis;
    }

    $timestamp = $date->getTimestamp();
    $ageSeconds = $now - $timestamp;
    $analysis['parsed_timestamp'] = $timestamp;
    $analysis['parsed_iso8601'] = $date->format('c');
    $analysis['parsed_timezone_name'] = $date->getTimezone()->getName();
    $analysis['parsed_timezone_offset_seconds'] = $date->getOffset();
    $analysis['parsed_local_in_server_timezone'] = $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('c');
    $analysis['parsed_utc'] = $date->setTimezone(new DateTimeZone('UTC'))->format('c');
    $analysis['parsed_components'] = [
        'year' => (int) $date->format('Y'),
        'month' => (int) $date->format('m'),
        'day' => (int) $date->format('d'),
        'hour' => (int) $date->format('H'),
        'minute' => (int) $date->format('i'),
        'second' => (int) $date->format('s'),
    ];
    $analysis['comparison'] = [
        'timestamp_lte_latest_allowed' => $timestamp <= ($now + $futureAllowanceSeconds),
        'timestamp_gte_oldest_allowed' => $timestamp >= ($now - ($maxAgeMinutes * 60)),
        'timestamp_minus_latest_allowed_seconds' => $timestamp - ($now + $futureAllowanceSeconds),
        'timestamp_minus_oldest_allowed_seconds' => $timestamp - ($now - ($maxAgeMinutes * 60)),
    ];
    $analysis['age_seconds'] = $ageSeconds;
    $analysis['age_minutes'] = round($ageSeconds / 60, 6);
    $analysis['age_hours'] = round($ageSeconds / 3600, 6);

    if ($timestamp > ($now + $futureAllowanceSeconds)) {
        $analysis['reason'] = 'future_beyond_allowance';
        $analysis['future_by_seconds'] = $timestamp - $now;
        return $analysis;
    }
    if ($timestamp < ($now - ($maxAgeMinutes * 60))) {
        $analysis['reason'] = 'older_than_max_age';
        $analysis['too_old_by_seconds'] = ($now - ($maxAgeMinutes * 60)) - $timestamp;
        return $analysis;
    }

    $analysis['accepted'] = true;
    $analysis['reason'] = 'accepted';
    return $analysis;
}

function isSlipDateAcceptable($dateValue, int $maxAgeMinutes = 1440): bool
{
    $analysis = analyzeSlipDateAcceptance($dateValue, $maxAgeMinutes);
    return !empty($analysis['accepted']);
}

function slipVerificationParseProviderObservedAtTimestamp($value): ?int
{
    if (!is_scalar($value)) return null;
    $raw = trim((string) $value);
    if ($raw === '') return null;
    try {
        // provider_verified_at is written by MySQL NOW() while the connection
        // session timezone is +07:00. Values without an explicit offset must
        // therefore be interpreted in the application's Bangkok timezone.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $date = new DateTimeImmutable($raw, $timezone);
        $timestamp = $date->getTimestamp();
        return $timestamp > 0 ? $timestamp : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve EasySlip's transfer timestamp without modifying the provider snapshot.
 *
 * Normal timestamps from every bank use the ordinary validation path. A strict,
 * bank-agnostic fallback handles an observed EasySlip anomaly where a verified
 * Thai-bank timestamp keeps the correct HH:mm:ss and +07:00 offset but carries a
 * calendar date exactly one day too far forward.
 *
 * The provider verification time is the evidence anchor. This is important:
 * retrying a saved snapshot tomorrow must not make a timestamp that was invalid
 * in the future at verification time suddenly become valid merely because the
 * wall clock caught up.
 *
 * The one-day correction is fail-closed and is used only when all of these hold:
 * - the raw timestamp was rejected as future_beyond_allowance at provider verify;
 * - country is TH when the provider supplies it, and bank_code is a valid Thai bank code;
 * - timestamp is explicit ISO-8601 with Thailand +07:00;
 * - raw time is 1h..24h10m ahead of the provider verification anchor;
 * - provider calendar date is anchor-day or the immediately following day;
 * - if transaction_ref encodes Nxxx, xxx must match bank_code;
 * - subtracting one calendar day is valid at the provider anchor;
 * - the corrected timestamp is still inside the configured age window now.
 *
 * This keeps ordinary processing compatible with every bank while correcting
 * only the exact one-day anomaly supported by durable evidence.
 */
function resolveSlipTransferDateForValidation(
    array $slipData,
    int $maxAgeMinutes = 1440,
    ?int $nowTimestamp = null,
    ?int $providerObservedAtTimestamp = null
): array {
    $now = $nowTimestamp ?? time();
    $observedAt = $providerObservedAtTimestamp ?? $now;
    $providerDate = is_scalar($slipData['transfer_date'] ?? null)
        ? trim((string) ($slipData['transfer_date'] ?? ''))
        : '';
    $bankCode = is_scalar($slipData['bank_code'] ?? null)
        ? trim((string) ($slipData['bank_code'] ?? ''))
        : '';
    $bankCodeNormalized = preg_match('/^\d{1,3}$/D', $bankCode) === 1
        ? str_pad($bankCode, 3, '0', STR_PAD_LEFT)
        : $bankCode;
    $transactionRef = is_scalar($slipData['transaction_ref'] ?? null)
        ? trim((string) ($slipData['transaction_ref'] ?? ''))
        : '';
    $countryCode = strtoupper(trim((string) ($slipData['country_code'] ?? '')));

    $currentAnalysis = analyzeSlipDateAcceptance($providerDate, $maxAgeMinutes, $now);
    $anchorAnalysis = analyzeSlipDateAcceptance($providerDate, $maxAgeMinutes, $observedAt);

    // A timestamp must have been valid when EasySlip actually verified it and
    // must still be within the age policy now. This prevents retry-time drift.
    $baselineAccepted = !empty($currentAnalysis['accepted']) && !empty($anchorAnalysis['accepted']);
    $baselineReason = !empty($anchorAnalysis['accepted'])
        ? (string) ($currentAnalysis['reason'] ?? '')
        : (string) ($anchorAnalysis['reason'] ?? '');

    $encodedBankCode = '';
    if (preg_match('/^N(\d{3})/i', $transactionRef, $bankMatch) === 1) {
        $encodedBankCode = (string) $bankMatch[1];
    }

    $result = [
        'accepted' => $baselineAccepted,
        'provider_date' => $providerDate,
        'effective_date' => $providerDate,
        'correction_applied' => false,
        'correction_code' => '',
        'correction_seconds' => 0,
        'rejection_reason' => $baselineAccepted ? '' : $baselineReason,
        'provider_observed_at_timestamp' => $observedAt,
        'provider_observed_at_local_iso8601' => date('c', $observedAt),
        'original_analysis' => $currentAnalysis,
        'anchor_analysis' => $anchorAnalysis,
        'effective_analysis' => $currentAnalysis,
        'eligibility' => [
            'bank_code' => $bankCode,
            'bank_code_normalized' => $bankCodeNormalized,
            'bank_code_format_valid' => preg_match('/^\d{3}$/D', $bankCodeNormalized) === 1,
            'transaction_ref_prefix' => substr($transactionRef, 0, 12),
            'transaction_ref_encoded_bank_code' => $encodedBankCode,
            'transaction_ref_bank_consistent' => $encodedBankCode === '' ? null : hash_equals($bankCodeNormalized, $encodedBankCode),
            'country_code' => $countryCode,
            'country_code_explicit' => $countryCode !== '',
            'thailand_country_match' => $countryCode === '' || hash_equals('TH', $countryCode),
            'explicit_iso8601_timezone' => preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})$/D', $providerDate) === 1,
            'provider_observed_at_timestamp' => $observedAt,
        ],
    ];

    if ($baselineAccepted) {
        return $result;
    }

    // Only a future anomaly observed at the original provider-verification time
    // is eligible for date correction. Other failures remain final validation
    // failures and are never "fixed" merely because a later retry occurs.
    if (($anchorAnalysis['reason'] ?? '') !== 'future_beyond_allowance') {
        return $result;
    }
    if (!$result['eligibility']['bank_code_format_valid']
        || !$result['eligibility']['thailand_country_match']
        || !$result['eligibility']['explicit_iso8601_timezone']
        || $result['eligibility']['transaction_ref_bank_consistent'] === false) {
        return $result;
    }

    try {
        $providerDateTime = new DateTimeImmutable($providerDate);
        $serverTimezone = new DateTimeZone(date_default_timezone_get());
        $anchorNow = (new DateTimeImmutable('@' . $observedAt))->setTimezone($serverTimezone);
        $providerLocal = $providerDateTime->setTimezone($serverTimezone);
    } catch (Throwable $e) {
        $result['eligibility']['resolution_parse_error'] = substr($e->getMessage(), 0, 300);
        return $result;
    }

    $futureByAnchorSeconds = $providerDateTime->getTimestamp() - $observedAt;
    $providerOffsetSeconds = $providerDateTime->getOffset();
    $anchorDate = $anchorNow->format('Y-m-d');
    $anchorNextDate = $anchorNow->modify('+1 day')->format('Y-m-d');
    $providerLocalDate = $providerLocal->format('Y-m-d');

    $result['eligibility']['provider_timezone_offset_seconds'] = $providerOffsetSeconds;
    $result['eligibility']['provider_local_date'] = $providerLocalDate;
    $result['eligibility']['anchor_local_date'] = $anchorDate;
    $result['eligibility']['anchor_next_local_date'] = $anchorNextDate;
    $result['eligibility']['future_by_anchor_seconds'] = $futureByAnchorSeconds;
    $result['eligibility']['future_window_match'] = $futureByAnchorSeconds >= 3600 && $futureByAnchorSeconds <= 87000;
    $result['eligibility']['provider_date_relation_match'] = in_array($providerLocalDate, [$anchorDate, $anchorNextDate], true);
    $result['eligibility']['thailand_offset_match'] = $providerOffsetSeconds === 25200;

    if (!$result['eligibility']['future_window_match']
        || !$result['eligibility']['provider_date_relation_match']
        || !$result['eligibility']['thailand_offset_match']) {
        return $result;
    }

    $correctedDateTime = $providerDateTime->modify('-1 day');
    $correctedLocal = $correctedDateTime->setTimezone($serverTimezone);
    $anchorPreviousDate = $anchorNow->modify('-1 day')->format('Y-m-d');
    $correctedLocalDate = $correctedLocal->format('Y-m-d');

    $result['eligibility']['corrected_local_date'] = $correctedLocalDate;
    $result['eligibility']['anchor_previous_local_date'] = $anchorPreviousDate;
    $result['eligibility']['corrected_date_relation_match'] = in_array(
        $correctedLocalDate,
        [$anchorPreviousDate, $anchorDate],
        true
    );

    if (!$result['eligibility']['corrected_date_relation_match']) {
        return $result;
    }

    $correctedValue = $correctedDateTime->format('c');
    $anchorCorrectedAnalysis = analyzeSlipDateAcceptance($correctedValue, $maxAgeMinutes, $observedAt);
    $currentCorrectedAnalysis = analyzeSlipDateAcceptance($correctedValue, $maxAgeMinutes, $now);
    $result['candidate_date'] = $correctedValue;
    $result['candidate_anchor_analysis'] = $anchorCorrectedAnalysis;
    $result['candidate_analysis'] = $currentCorrectedAnalysis;

    if (empty($anchorCorrectedAnalysis['accepted']) || empty($currentCorrectedAnalysis['accepted'])) {
        $result['rejection_reason'] = empty($anchorCorrectedAnalysis['accepted'])
            ? (string) ($anchorCorrectedAnalysis['reason'] ?? 'invalid_date')
            : (string) ($currentCorrectedAnalysis['reason'] ?? 'invalid_date');
        return $result;
    }

    $result['accepted'] = true;
    $result['effective_date'] = $correctedValue;
    $result['correction_applied'] = true;
    $result['correction_code'] = 'thai_bank_provider_one_day_rollover';
    $result['correction_seconds'] = 86400;
    $result['rejection_reason'] = '';
    $result['effective_analysis'] = $currentCorrectedAnalysis;
    return $result;
}

function slipVerificationAcquireNamedLock(string $slipHash, int $waitSeconds = 0): array
{
    global $conn;
    $database = defined('DB_NAME') ? (string) DB_NAME : 'default';
    $lockName = 'slip:' . substr(hash('sha256', $database . '|' . $slipHash), 0, 48);
    $waitSeconds = max(0, min(3, $waitSeconds));
    $stmt = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    if (!$stmt) return ['success' => false, 'name' => $lockName];
    $stmt->bind_param('si', $lockName, $waitSeconds);
    $ok = $stmt->execute();
    $result = $ok ? $stmt->get_result() : null;
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return ['success' => (int) ($row['acquired'] ?? 0) === 1, 'name' => $lockName];
}

function slipVerificationReleaseNamedLock(string $lockName): void
{
    global $conn;
    if ($lockName === '') return;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if (!$stmt) return;
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

function slipVerificationClaimMessage(array $claim): array
{
    if (!empty($claim['success'])) return ['ok' => true];
    if (!empty($claim['duplicate'])) {
        $status = strtolower((string) ($claim['status'] ?? ''));
        if ($status === 'completed') {
            // A completed claim owned by another site/attempt is authoritative
            // proof that this cross-site reference was already consumed. That is
            // a normal terminal duplicate, not an admin-review condition. Only a
            // same-owner completed claim without the matching local financial row
            // is an invariant conflict that deserves review.
            if (empty($claim['same_owner'])) {
                return ['ok' => false, 'used' => true, 'code' => 'shared_already_completed'];
            }
            return ['ok' => false, 'review' => true, 'code' => 'shared_completed_without_local_record'];
        }
        return ['ok' => false, 'pending' => true, 'code' => 'shared_processing_elsewhere'];
    }
    return ['ok' => false, 'retryable' => true, 'code' => 'shared_unavailable'];
}

function slipVerificationMarkSharedFinalized(string $slipHash): bool
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable()) return false;
    $stmt = $conn->prepare(
        "UPDATE slip_verification_jobs SET shared_finalized_at = COALESCE(shared_finalized_at, NOW()), updated_at = updated_at WHERE slip_hash = ? AND status = 'completed'"
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $slipHash);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function slipVerificationFinalizeSharedClaims(array $job, array $slipData): bool
{
    global $conn;
    $attemptUuid = (string) ($job['attempt_uuid'] ?? '');
    $slipHash = strtolower(trim((string) ($job['slip_hash'] ?? '')));
    $transactionRef = substr(trim((string) ($slipData['transaction_ref'] ?? $job['transaction_ref'] ?? '')), 0, 100);
    $allCompleted = preg_match('/^[a-f0-9]{64}$/D', $slipHash) === 1 && $transactionRef !== '';
    $ownershipConflict = false;
    foreach ([
        ['namespace' => 'slip_image', 'reference' => $slipHash],
        ['namespace' => 'slip_transaction', 'reference' => $transactionRef],
    ] as $item) {
        if ($item['reference'] === '') continue;
        $claim = sharedLedgerBegin($item['namespace'], $item['reference'], 600, $attemptUuid);
        if (empty($claim['success'])) {
            if (!empty($claim['duplicate']) && (string) ($claim['status'] ?? '') === 'completed') {
                if (!empty($claim['same_owner'])) continue;
                // Local money is already committed, so never try to "fix" this
                // automatically by moving money again. A completed shared claim
                // owned by another attempt/site is a true invariant conflict and
                // must remain visible in audit evidence rather than being silently
                // marked as successfully finalized.
                $ownershipConflict = true;
                $allCompleted = false;
                error_log('CRITICAL: completed slip shared claim belongs to another owner after local commit; namespace=' . $item['namespace']);
                continue;
            }
            $allCompleted = false;
            error_log('Slip shared claim could not be recovered after local commit; namespace=' . $item['namespace']);
            continue;
        }
        $lease = $claim['lease'];
        $reserved = sharedLedgerReserve($lease);
        if (empty($reserved['success'])) {
            $allCompleted = false;
            error_log('Slip shared claim could not be reserved after local commit; namespace=' . $item['namespace']);
            continue;
        }
        $completed = sharedLedgerComplete($lease);
        if (empty($completed['success'])) {
            $allCompleted = false;
            error_log('Slip shared claim could not be completed after local commit; namespace=' . $item['namespace']);
        }
    }
    if ($allCompleted && $slipHash !== '') {
        slipVerificationMarkSharedFinalized($slipHash);
    } elseif ($ownershipConflict && $slipHash !== '' && preg_match('/^[a-f0-9]{64}$/D', $slipHash) === 1) {
        $stmt = $conn->prepare(
            "UPDATE slip_verification_jobs
             SET last_error_code='shared_finalize_owner_conflict',
                 last_error_message='Local credit committed but shared ledger is completed by another owner; automatic money movement is blocked',
                 updated_at=NOW()
             WHERE slip_hash=? AND status='completed'"
        );
        if ($stmt) {
            $stmt->bind_param('s', $slipHash);
            $stmt->execute();
            $stmt->close();
        }
        slipDebugLogEvent('shared_finalize_owner_conflict', 'critical', 'Completed shared claim belongs to another owner after local wallet commit', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'error_code' => 'shared_finalize_owner_conflict',
            'response' => ['financial_action' => 'none', 'automatic_retry' => false],
        ]);
    }
    return $allCompleted;
}

function completeVerifiedSlipVerificationJob(string $slipHash, float $deadline = 0.0): array
{
    global $conn;
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash) || !ensureSlipVerificationJobsTable() || !ensureSlipDepositsTable()) {
        return ['success' => false, 'message' => 'ระบบจัดเก็บการตรวจสลิปไม่พร้อมใช้งาน'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'pending' => true, 'message' => 'ระบบบันทึกหลักฐานยอดเงินยังไม่พร้อม กรุณาลองใหม่อีกครั้ง'];
    }

    $lock = slipVerificationAcquireNamedLock($slipHash, 1);
    if (empty($lock['success'])) {
        $job = slipVerificationReadJobByHash($slipHash);
        return [
            'success' => false,
            'pending' => true,
            'attempt_id' => (string) ($job['attempt_uuid'] ?? ''),
            'message' => 'รายการนี้กำลังดำเนินการอยู่ กรุณารอสักครู่',
        ];
    }

    try {
        $job = slipVerificationReadJobByHash($slipHash);
        if (!$job) return ['success' => false, 'pending' => false, 'retryable' => true, 'code' => 'attempt_not_found', 'message' => 'ยังไม่พบรายการตรวจสลิปบนเซิร์ฟเวอร์'];
        $attemptUuid = (string) ($job['attempt_uuid'] ?? '');
        $userId = (int) ($job['user_id'] ?? 0);
        $depositUser = $userId > 0 ? getUserById($userId) : null;
        if (!$depositUser || ($depositUser['status'] ?? '') !== 'active') {
            slipVerificationSetJobStatus($slipHash, 'review', 'user_unavailable', 'บัญชีผู้ใช้ไม่พร้อมรับยอดเงิน');
            return ['success' => false, 'review' => true, 'attempt_id' => $attemptUuid, 'message' => 'บัญชีผู้ใช้ต้องได้รับการตรวจสอบโดยผู้ดูแล'];
        }

        $slipData = slipVerificationDecryptProviderData((string) ($job['provider_data_ciphertext'] ?? ''));
        if (!is_array($slipData) || $slipData === []) {
            slipDebugLogEvent('local_validation_failed', 'error', 'Provider snapshot could not be decrypted or was empty', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'error_code' => 'provider_snapshot_missing',
                'context' => [
                    'job_status' => $job['status'] ?? '',
                    'provider_snapshot_present' => !empty($job['provider_data_ciphertext']),
                    'provider_verified_at' => $job['provider_verified_at'] ?? null,
                    'clock' => slipDebugClockSnapshot(),
                ],
            ]);
            slipVerificationSetJobStatus($slipHash, 'failed', 'provider_snapshot_missing', 'ไม่พบข้อมูลยืนยันจากผู้ให้บริการ กรุณาเลือกสลิปเดิมเพื่อลองใหม่');
            return ['success' => false, 'retryable' => true, 'attempt_id' => $attemptUuid, 'message' => 'ไม่พบข้อมูลยืนยัน กรุณาเลือกสลิปเดิมเพื่อลองใหม่'];
        }

        slipDebugLogEvent('local_validation_started', 'info', 'Local validation started from durable provider snapshot', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'response' => ['normalized_provider_data' => $slipData],
            'context' => [
                'job_status' => $job['status'] ?? '',
                'job_attempts' => (int) ($job['attempts'] ?? 0),
                'provider_verified_at' => $job['provider_verified_at'] ?? null,
                'job_created_at' => $job['created_at'] ?? null,
                'job_updated_at' => $job['updated_at'] ?? null,
                'clock' => slipDebugClockSnapshot(),
            ],
        ]);

        $transactionRef = substr(trim((string) ($slipData['transaction_ref'] ?? '')), 0, 100);
        $existingDeposit = slipVerificationFindDeposit($transactionRef, $slipHash);
        if ($existingDeposit) {
            $result = slipVerificationFinalizeExistingDeposit($job, $existingDeposit);
            if (!empty($result['success'])) slipVerificationFinalizeSharedClaims($job, $slipData);
            return $result;
        }

        // Once the provider snapshot is durable, global history readiness can be
        // checked BEFORE taking a live image lease. This removes an unnecessary
        // cross-site claim/release round-trip from the migration gate and makes a
        // not-ready deployment fail closed quickly instead of consuming the
        // customer's bounded request budget.
        // Until every site's pre-upgrade history has been imported, a negative
        // central-ledger lookup is not authoritative. This gate applies to EVERY
        // provider-verified slip, not only EasySlip isDuplicate=true, because the
        // provider's duplicate flag is verification history rather than our wallet
        // ledger. The encrypted provider snapshot is retained, so a later retry
        // resumes locally without consuming another EasySlip request.
        $historyReady = slipVerificationSharedHistoryReady();
        if (empty($historyReady['ready'])) {
            slipVerificationSetJobStatus($slipHash, 'provider_verified', 'shared_history_sync_pending', 'ระบบกำลังซิงค์ประวัติสลิปเดิมระหว่างเว็บไซต์เพื่อป้องกันการเติมซ้ำ');
            slipDebugLogEvent('financial_history_waiting', 'warning', 'Provider-verified slip held until all sites finish historical shared-ledger migration', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'error_code' => 'shared_history_sync_pending',
                'response' => [
                    'provider_is_duplicate' => !empty($slipData['is_duplicate']),
                    'history_ready' => false,
                    'history_status' => $historyReady,
                    'financial_action' => 'none',
                ],
            ]);
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptUuid,
                'code' => 'shared_history_sync_pending',
                'error_code' => 'shared_history_sync_pending',
                'retry_after_seconds' => 15,
                'message' => 'ตรวจสลิปผ่านแล้ว แต่ระบบกำลังซิงค์ประวัติระหว่างเว็บไซต์เพื่อป้องกันยอดซ้ำ รายการนี้ยังไม่มีการเติมเงิน กรุณาใช้สลิปเดิมลองใหม่อีกครั้งภายหลัง',
            ];
        }

        if ($deadline > 0 && slipVerificationDeadlineRemainingMs($deadline) < 2300) {
            slipVerificationSetJobStatus($slipHash, 'provider_verified', 'customer_deadline_exceeded', 'เวลาในคำขอนี้เหลือน้อยเกินไปก่อนเริ่มล็อกรายการทางการเงิน');
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptUuid,
                'code' => 'customer_deadline_exceeded',
                'error_code' => 'customer_deadline_exceeded',
                'retry_after_seconds' => 3,
                'message' => 'ตรวจสลิปผ่านแล้วและบันทึกผลไว้แล้ว ระบบจะใช้ผลเดิมเมื่อคุณลองอีกครั้ง โดยไม่ส่งสลิปไปตรวจซ้ำ',
            ];
        }

        $sharedLeases = [];
        $releaseClaims = static function () use (&$sharedLeases): void {
            foreach (array_reverse($sharedLeases) as $lease) {
                $released = sharedLedgerRelease($lease);
                if (empty($released['success'])) error_log('Unable to release a slip shared claim');
            }
            $sharedLeases = [];
        };
        $completeClaims = static function () use (&$sharedLeases): bool {
            $allCompleted = true;
            foreach ($sharedLeases as $lease) {
                $completed = sharedLedgerComplete($lease);
                if (empty($completed['success'])) {
                    $allCompleted = false;
                    error_log('Unable to finalize a slip shared claim after commit');
                }
            }
            $sharedLeases = [];
            return $allCompleted;
        };

        $imageClaim = sharedLedgerBegin('slip_image', $slipHash, 30, $attemptUuid);
        $imageDecision = slipVerificationClaimMessage($imageClaim);
        if (empty($imageDecision['ok'])) {
            if (!empty($imageDecision['used'])) {
                slipVerificationSetJobStatus($slipHash, 'failed', $imageDecision['code'], 'สลิปนี้ถูกใช้งานสำเร็จจากอีกเว็บไซต์แล้ว');
                return ['success' => false, 'retryable' => false, 'attempt_id' => $attemptUuid, 'code' => $imageDecision['code'], 'message' => 'สลิปนี้ถูกใช้งานแล้ว ไม่สามารถเติมซ้ำได้'];
            }
            if (!empty($imageDecision['review'])) {
                slipVerificationSetJobStatus($slipHash, 'review', $imageDecision['code'], 'Shared ledger เป็นเจ้าของรายการนี้เองแต่ฐานข้อมูล local ขาดข้อมูลทางการเงิน');
                return ['success' => false, 'review' => true, 'attempt_id' => $attemptUuid, 'message' => 'พบข้อมูลทางการเงินที่ขัดแย้งกัน ระบบหยุดอัตโนมัติเพื่อป้องกันยอดผิดพลาด'];
            }
            if (!empty($imageDecision['pending'])) {
                slipVerificationSetJobStatus($slipHash, 'provider_verified', $imageDecision['code'], 'รายการกำลังดำเนินการจากอีกเว็บไซต์');
                return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'รายการกำลังดำเนินการจากอีกเว็บไซต์ ระบบจะตรวจซ้ำอัตโนมัติ'];
            }
            slipVerificationSetJobStatus($slipHash, 'provider_verified', $imageDecision['code'], 'ระบบเชื่อมข้อมูลสองเว็บไซต์ไม่พร้อมใช้งาน');
            return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ระบบเชื่อมข้อมูลสองเว็บไซต์สะดุดชั่วคราว ระบบจะลองกู้คืนอัตโนมัติ'];
        }
        $sharedLeases[] = $imageClaim['lease'];

        if (!empty($slipData['is_duplicate'])) {
            slipDebugLogEvent('provider_duplicate_history_ready', 'info', 'Historical shared-ledger migration is complete; provider duplicate may use authoritative local/shared deduplication', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'response' => ['history_status' => $historyReady],
            ]);
        }

        // EasySlip's isDuplicate means the slip has been verified by the provider
        // before; it is not by itself proof that this wallet was credited. Once
        // the durable retry provenance above is satisfied, monetary deduplication
        // is still enforced below by shared/local references.
        if (!empty($slipData['is_duplicate'])) {
            slipDebugLogEvent('provider_duplicate_observed', 'info', 'EasySlip reported prior verification for a trusted durable retry; local/shared ledgers remain authoritative', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'response' => [
                    'provider_is_duplicate' => true,
                    'signed_remark_trusted' => !empty($slipData['_duplicate_remark_trusted']) || !empty($slipData['_duplicate_recovery_allowed']),
                    'duplicate_remark_attempt_id' => (string) ($slipData['_duplicate_remark_attempt_id'] ?? ''),
                    'financial_duplicate_authority' => 'shared_and_local_transaction_reference',
                ],
            ]);
        }

        $maxAgeSettingRaw = getSetting('easyslip_max_age_minutes');
        $maxAgeSettingCandidate = ($maxAgeSettingRaw === null || $maxAgeSettingRaw === '') ? 1440 : $maxAgeSettingRaw;
        $maxAgeMinutes = filter_var($maxAgeSettingCandidate, FILTER_VALIDATE_INT);
        $maxAgeFallbackUsed = false;
        if ($maxAgeMinutes === false || $maxAgeMinutes < 5 || $maxAgeMinutes > 10080) {
            $maxAgeMinutes = 1440;
            $maxAgeFallbackUsed = true;
        }
        $dateValidationNow = time();
        $providerObservedAtTimestamp = slipVerificationParseProviderObservedAtTimestamp($job['provider_verified_at'] ?? null);
        if ($providerObservedAtTimestamp === null) {
            // Legacy rows without provider_verified_at are anchored to job creation.
            // If neither timestamp can be parsed, use now but record the fallback.
            $providerObservedAtTimestamp = slipVerificationParseProviderObservedAtTimestamp($job['created_at'] ?? null);
        }
        $providerObservedAnchorFallback = false;
        if ($providerObservedAtTimestamp === null) {
            $providerObservedAtTimestamp = $dateValidationNow;
            $providerObservedAnchorFallback = true;
        }
        $dateResolution = resolveSlipTransferDateForValidation(
            $slipData,
            (int) $maxAgeMinutes,
            $dateValidationNow,
            $providerObservedAtTimestamp
        );
        $dateAnalysis = is_array($dateResolution['effective_analysis'] ?? null)
            ? $dateResolution['effective_analysis']
            : analyzeSlipDateAcceptance($slipData['transfer_date'] ?? '', (int) $maxAgeMinutes, $dateValidationNow);

        if (!empty($dateResolution['correction_applied'])) {
            $providerTransferDate = (string) ($dateResolution['provider_date'] ?? ($slipData['transfer_date'] ?? ''));
            $effectiveTransferDate = (string) ($dateResolution['effective_date'] ?? $providerTransferDate);
            $slipData['provider_transfer_date'] = $providerTransferDate;
            $slipData['transfer_date'] = $effectiveTransferDate;
            $slipData['_date_correction'] = [
                'code' => (string) ($dateResolution['correction_code'] ?? 'thai_bank_provider_one_day_rollover'),
                'provider_date' => $providerTransferDate,
                'effective_date' => $effectiveTransferDate,
                'correction_seconds' => (int) ($dateResolution['correction_seconds'] ?? 86400),
                'provider_observed_at' => date('c', $providerObservedAtTimestamp),
                'anchor_reason' => (string) ($dateResolution['anchor_analysis']['reason'] ?? ''),
                'policy' => 'thai_bank_one_day_rollover_anchor_v2',
                'applied_at' => date('c', $dateValidationNow),
            ];

            slipDebugLogEvent('date_rollover_correction_applied', 'warning', 'Strict Thai-bank one-day provider timestamp correction applied', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'error_code' => (string) ($dateResolution['correction_code'] ?? 'thai_bank_provider_one_day_rollover'),
                'response' => [
                    'provider_transfer_date' => $providerTransferDate,
                    'effective_transfer_date' => $effectiveTransferDate,
                    'correction_seconds' => (int) ($dateResolution['correction_seconds'] ?? 86400),
                    'original_analysis' => $dateResolution['original_analysis'] ?? null,
                    'provider_anchor_analysis' => $dateResolution['anchor_analysis'] ?? null,
                    'candidate_anchor_analysis' => $dateResolution['candidate_anchor_analysis'] ?? null,
                    'effective_analysis' => $dateAnalysis,
                ],
                'context' => [
                    'eligibility' => $dateResolution['eligibility'] ?? [],
                    'clock' => slipDebugClockSnapshot(),
                ],
            ]);
        }

        slipDebugLogEvent('date_validation', !empty($dateResolution['accepted']) ? 'info' : 'error', !empty($dateResolution['accepted']) ? 'Slip timestamp accepted' : 'Slip timestamp rejected', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'error_code' => !empty($dateResolution['accepted']) ? '' : (string) (($dateResolution['rejection_reason'] ?? null) ?: ($dateResolution['anchor_analysis']['reason'] ?? null) ?: ($dateAnalysis['reason'] ?? 'invalid_date')),
            'response' => [
                'provider_transfer_date' => $dateResolution['provider_date'] ?? ($slipData['transfer_date'] ?? null),
                'effective_transfer_date' => $dateResolution['effective_date'] ?? ($slipData['transfer_date'] ?? null),
                'correction_applied' => !empty($dateResolution['correction_applied']),
                'correction_code' => (string) ($dateResolution['correction_code'] ?? ''),
                'date_analysis' => $dateAnalysis,
                'original_date_analysis' => $dateResolution['original_analysis'] ?? null,
                'provider_anchor_analysis' => $dateResolution['anchor_analysis'] ?? null,
                'candidate_anchor_analysis' => $dateResolution['candidate_anchor_analysis'] ?? null,
                'correction_eligibility' => $dateResolution['eligibility'] ?? [],
            ],
            'context' => [
                'setting_key' => 'easyslip_max_age_minutes',
                'setting_raw_value' => $maxAgeSettingRaw,
                'setting_raw_type' => gettype($maxAgeSettingRaw),
                'effective_max_age_minutes' => (int) $maxAgeMinutes,
                'fallback_to_1440_used' => $maxAgeFallbackUsed,
                'provider_observed_at_timestamp' => $providerObservedAtTimestamp,
                'provider_observed_at_local_iso8601' => date('c', $providerObservedAtTimestamp),
                'provider_observed_anchor_fallback_to_now' => $providerObservedAnchorFallback,
                'clock' => slipDebugClockSnapshot(),
                'php_version' => PHP_VERSION,
                'server_software' => substr((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 0, 255),
            ],
        ]);
        if (empty($dateResolution['accepted'])) {
            $releaseClaims();
            $dateRejectReason = (string) (($dateResolution['rejection_reason'] ?? null)
                ?: ($dateResolution['anchor_analysis']['reason'] ?? null)
                ?: ($dateAnalysis['reason'] ?? 'invalid_date'));
            $dateRejectCode = in_array($dateRejectReason, ['older_than_max_age', 'future_beyond_allowance', 'parse_error', 'empty_date'], true)
                ? $dateRejectReason
                : 'invalid_date';
            if ($dateRejectCode === 'older_than_max_age') {
                $dateRejectMessage = 'สลิปเก่าเกินเวลาที่กำหนด';
            } elseif ($dateRejectCode === 'future_beyond_allowance') {
                $dateRejectMessage = 'วันเวลาจากธนาคารอยู่ในอนาคตผิดปกติ ระบบไม่สามารถยืนยันรายการนี้อัตโนมัติได้';
            } elseif (in_array($dateRejectCode, ['parse_error', 'empty_date'], true)) {
                $dateRejectMessage = 'ไม่สามารถอ่านวันเวลาจากข้อมูลสลิปได้';
            } else {
                $dateRejectMessage = 'วันเวลาบนสลิปไม่ผ่านการตรวจสอบความถูกต้อง';
            }
            slipVerificationSetJobStatus($slipHash, 'failed', $dateRejectCode, $dateRejectMessage);
            return [
                'success' => false,
                'retryable' => false,
                'error_code' => $dateRejectCode,
                'attempt_id' => $attemptUuid,
                'message' => $dateRejectMessage,
            ];
        }
        $countryCodeRaw = trim((string) ($slipData['country_code'] ?? ''));
        $countryCodeNormalized = strtoupper($countryCodeRaw);
        $rawLocalCurrency = trim((string) ($slipData['local_currency'] ?? ''));
        $normalizedLocalCurrency = normalizeSlipCurrencyCode($rawLocalCurrency);
        slipDebugLogEvent('provider_field_validation', 'info', 'Provider country, currency and transaction reference prepared for validation', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'response' => [
                'country_code_raw' => $countryCodeRaw,
                'country_code_normalized' => $countryCodeNormalized,
                'local_currency_raw' => $rawLocalCurrency,
                'local_currency_normalized' => $normalizedLocalCurrency,
                'transaction_ref' => $transactionRef,
                'provider_amount' => $slipData['amount'] ?? null,
                'provider_local_amount' => $slipData['local_amount'] ?? null,
            ],
        ]);
        if ($countryCodeRaw !== '' && $countryCodeNormalized !== 'TH') {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'invalid_country', 'รองรับเฉพาะสลิปธนาคารประเทศไทย');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'รองรับเฉพาะสลิปธนาคารประเทศไทย'];
        }
        if ($rawLocalCurrency !== '' && $normalizedLocalCurrency !== 'THB') {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'invalid_currency', 'รองรับเฉพาะรายการสกุลเงินบาท');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'รองรับเฉพาะรายการสกุลเงินบาท'];
        }

        if ($transactionRef === '') {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'missing_transaction_ref', 'ไม่พบเลขอ้างอิงรายการ');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'ไม่พบเลขอ้างอิงรายการบนสลิป'];
        }

        $esPhone = trim((string) getSetting('easyslip_phone'));
        $esAcc = trim((string) getSetting('easyslip_account_number'));
        $esNameTh = trim((string) getSetting('easyslip_receiver_name'));
        $esNameEn = trim((string) getSetting('easyslip_receiver_name_en'));
        $configuredReceivers = [];
        if (preg_replace('/\D+/', '', $esAcc) !== '') $configuredReceivers[] = ['account' => $esAcc, 'is_phone' => false];
        if (preg_replace('/\D+/', '', $esPhone) !== '') $configuredReceivers[] = ['account' => $esPhone, 'is_phone' => true];
        $configuredNames = array_values(array_filter([$esNameTh, $esNameEn], static function ($name) {
            return normalizeReceiverNameForMatch($name) !== '';
        }));
        if (!$configuredReceivers) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'receiver_not_configured', 'ยังไม่ได้ตั้งค่าบัญชีผู้รับ');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => Lang::t('deposit.error.easyslip_not_configured')];
        }

        $receivedAccountStr = trim((string) ($slipData['receiver_account'] ?? ''));
        $receivedName = trim((string) ($slipData['receiver_name'] ?? ''));
        if ($receivedAccountStr === '') {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'receiver_missing', 'API ส่งข้อมูลบัญชีปลายทางไม่ครบ');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'API ส่งข้อมูลบัญชีปลายทางไม่ครบ'];
        }
        $accountsFromApi = array_values(array_filter(array_map('trim', explode(',', $receivedAccountStr))));
        $isValidTransfer = false;
        $receiverMatchDiagnostics = [];
        foreach ($configuredReceivers as $configuredReceiver) {
            foreach ($accountsFromApi as $apiAccount) {
                $accountMatch = receiverAccountMatchDetails($configuredReceiver['account'], $apiAccount, (bool) $configuredReceiver['is_phone']);
                $nameStrength = 0;
                $nameChecks = [];
                foreach ($configuredNames as $configuredName) {
                    $strength = receiverNameMatchStrength($configuredName, $receivedName);
                    $nameChecks[] = [
                        'configured_name' => $configuredName,
                        'received_name' => $receivedName,
                        'strength' => $strength,
                        'configured_normalized' => normalizeReceiverNameForMatch($configuredName),
                        'received_normalized' => normalizeReceiverNameForMatch($receivedName),
                    ];
                    $nameStrength = max($nameStrength, $strength);
                }
                $pairAccepted = !empty($accountMatch['matched']) && (
                    (int) $accountMatch['strength'] >= 3
                    || ((int) $accountMatch['strength'] >= 2 && $nameStrength >= 1)
                    || ((int) $accountMatch['strength'] === 1 && $nameStrength >= 2)
                );
                $receiverMatchDiagnostics[] = [
                    'configured_account' => $configuredReceiver['account'],
                    'configured_is_phone' => (bool) $configuredReceiver['is_phone'],
                    'api_account' => $apiAccount,
                    'account_match' => $accountMatch,
                    'name_strength_max' => $nameStrength,
                    'name_checks' => $nameChecks,
                    'pair_accepted' => $pairAccepted,
                ];
                if ($pairAccepted) {
                    $isValidTransfer = true;
                    break 2;
                }
            }
        }
        slipDebugLogEvent('receiver_validation', $isValidTransfer ? 'info' : 'error', $isValidTransfer ? 'Receiver account/name accepted' : 'Receiver account/name rejected', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'error_code' => $isValidTransfer ? '' : 'receiver_mismatch',
            'request' => [
                'configured_accounts' => $configuredReceivers,
                'configured_names' => $configuredNames,
            ],
            'response' => [
                'provider_accounts' => $accountsFromApi,
                'provider_name' => $receivedName,
                'accepted' => $isValidTransfer,
                'comparisons' => $receiverMatchDiagnostics,
            ],
        ]);
        if (!$isValidTransfer) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'receiver_mismatch', 'บัญชีหรือชื่อผู้รับเงินไม่ตรงกับที่ตั้งค่า');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'บัญชีหรือชื่อผู้รับเงินไม่ตรงกับที่ตั้งค่าไว้'];
        }

        $providerAmountRaw = $slipData['amount'] ?? null;
        $providerLocalAmountRaw = $slipData['local_amount'] ?? null;
        $amountSource = $providerAmountRaw ?? 0;
        if ($normalizedLocalCurrency === 'THB' && isset($slipData['local_amount']) && is_numeric((string) $slipData['local_amount'])) {
            $amountSource = $slipData['local_amount'];
        }
        $thbAmount = round((float) $amountSource, 2);
        if (!is_finite($thbAmount) || $thbAmount <= 0 || $thbAmount > 10000000) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'invalid_amount', 'จำนวนเงินไม่ถูกต้อง');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'จำนวนเงินไม่ถูกต้อง'];
        }
        $slipData['amount'] = $thbAmount;
        slipDebugLogEvent('amount_validation', 'info', 'Slip amount accepted for balance credit calculation', [
            'attempt_uuid' => $attemptUuid,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'response' => [
                'provider_amount' => $providerAmountRaw,
                'provider_local_amount' => $providerLocalAmountRaw,
                'raw_local_currency' => $rawLocalCurrency,
                'normalized_local_currency' => $normalizedLocalCurrency,
                'selected_amount_source' => $amountSource,
                'thb_amount' => $thbAmount,
            ],
        ]);

        $appCurrencyName = strtoupper((string) (getSetting('currency_name') ?: 'THB'));
        $creditAmount = $thbAmount;
        $conversionNote = '';
        if ($appCurrencyName === 'USD') {
            $rate = getExchangeRateThbToUsd(3);
            if (!$rate || $rate <= 0) {
                $releaseClaims();
                slipVerificationSetJobStatus($slipHash, 'provider_verified', 'exchange_rate', 'ยังไม่สามารถอ่านอัตราแลกเปลี่ยนได้');
                return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ระบบกำลังรออัตราแลกเปลี่ยนและจะลองใหม่อัตโนมัติ'];
            }
            $creditAmount = round($thbAmount * $rate, 2);
            $conversionNote = " (Converted from {$thbAmount} THB)";
        } elseif ($appCurrencyName !== 'THB') {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'failed', 'unsupported_currency', 'รองรับสกุลเงินร้านค้า THB หรือ USD เท่านั้น');
            return ['success' => false, 'attempt_id' => $attemptUuid, 'message' => 'ระบบเติมเงินอัตโนมัติรองรับสกุลเงินร้านค้า THB หรือ USD เท่านั้น'];
        }

        if (!function_exists('ensureRankingSchema') || !ensureRankingSchema()) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'provider_verified', 'ranking_schema', 'ระบบอันดับยังไม่พร้อม');
            return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ระบบกำลังเตรียมข้อมูลและจะลองใหม่อัตโนมัติ'];
        }

        if ($deadline > 0 && slipVerificationDeadlineRemainingMs($deadline) < 2300) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'provider_verified', 'customer_deadline_exceeded', 'เวลาในคำขอนี้เหลือน้อยเกินไปก่อนล็อกรหัสอ้างอิงสลิป');
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptUuid,
                'code' => 'customer_deadline_exceeded',
                'error_code' => 'customer_deadline_exceeded',
                'retry_after_seconds' => 3,
                'message' => 'ตรวจสลิปผ่านแล้วและบันทึกผลไว้แล้ว กรุณาลองสลิปเดิมอีกครั้ง ระบบจะไม่เรียก EasySlip ซ้ำ',
            ];
        }

        $transactionClaim = sharedLedgerBegin('slip_transaction', $transactionRef, 30, $attemptUuid);
        $transactionDecision = slipVerificationClaimMessage($transactionClaim);
        if (empty($transactionDecision['ok'])) {
            $releaseClaims();
            if (!empty($transactionDecision['used'])) {
                slipVerificationSetJobStatus($slipHash, 'failed', $transactionDecision['code'], 'เลขอ้างอิงสลิปนี้ถูกใช้งานสำเร็จจากอีกเว็บไซต์แล้ว');
                return ['success' => false, 'retryable' => false, 'attempt_id' => $attemptUuid, 'code' => $transactionDecision['code'], 'message' => 'สลิปนี้ถูกใช้งานแล้ว ไม่สามารถเติมซ้ำได้'];
            }
            if (!empty($transactionDecision['review'])) {
                slipVerificationSetJobStatus($slipHash, 'review', $transactionDecision['code'], 'Shared ledger เป็นเจ้าของเลขอ้างอิงนี้เองแต่ฐานข้อมูล local ขาดข้อมูลทางการเงิน');
                return ['success' => false, 'review' => true, 'attempt_id' => $attemptUuid, 'message' => 'พบข้อมูลทางการเงินที่ขัดแย้งกัน ระบบหยุดอัตโนมัติเพื่อป้องกันยอดผิดพลาด'];
            }
            slipVerificationSetJobStatus($slipHash, 'provider_verified', $transactionDecision['code'], 'เลขอ้างอิงกำลังดำเนินการจากอีกเว็บไซต์');
            return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'รายการกำลังดำเนินการจากอีกเว็บไซต์ ระบบจะตรวจซ้ำอัตโนมัติ'];
        }
        $sharedLeases[] = $transactionClaim['lease'];

        if ($deadline > 0 && slipVerificationDeadlineRemainingMs($deadline) < 4300) {
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'provider_verified', 'customer_deadline_exceeded', 'เวลาในคำขอนี้เหลือน้อยเกินไปก่อน reserve shared financial fences');
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptUuid,
                'code' => 'customer_deadline_exceeded',
                'error_code' => 'customer_deadline_exceeded',
                'retry_after_seconds' => 3,
                'message' => 'ตรวจสลิปผ่านแล้วและบันทึกผลไว้แล้ว กรุณาลองสลิปเดิมอีกครั้งเพื่อดำเนินการต่อ',
            ];
        }

        foreach ($sharedLeases as $lease) {
            $reserved = sharedLedgerReserve($lease);
            if (empty($reserved['success'])) {
                $releaseClaims();
                slipVerificationSetJobStatus($slipHash, 'provider_verified', 'shared_reserve_failed', 'ไม่สามารถล็อกรายการระหว่างสองเว็บไซต์ได้');
                return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ระบบกำลังรอล็อกรายการและจะลองใหม่อัตโนมัติ'];
            }
        }

        if (!slipVerificationSetJobStatus($slipHash, 'crediting')) {
            $releaseClaims();
            return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ระบบยังไม่พร้อมบันทึกสถานะการเติมเงิน กรุณารอสักครู่'];
        }

        $committed = false;
        try {
            $conn->begin_transaction();
            $duplicateCheck = $conn->prepare(
                'SELECT id, user_id, transaction_ref, amount FROM slip_deposits '
                . 'WHERE transaction_ref = ? OR slip_hash = ? ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            if (!$duplicateCheck) throw new RuntimeException('duplicate_check_prepare');
            $duplicateCheck->bind_param('ss', $transactionRef, $slipHash);
            if (!$duplicateCheck->execute()) { $duplicateCheck->close(); throw new RuntimeException('duplicate_check'); }
            $duplicateResult = $duplicateCheck->get_result();
            $duplicateRow = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
            $duplicateCheck->close();
            if ($duplicateRow) {
                $conn->rollback();
                $releaseClaims();
                $freshJob = slipVerificationReadJobByHash($slipHash) ?: $job;
                $result = slipVerificationFinalizeExistingDeposit($freshJob, $duplicateRow);
                if (!empty($result['success'])) slipVerificationFinalizeSharedClaims($freshJob, $slipData);
                return $result;
            }

            $slipDepositId = saveSlipDeposit($userId, $slipData, null, $slipHash);
            if (!is_int($slipDepositId) || $slipDepositId < 1) throw new RuntimeException('slip_record_failed');
            $walletBefore = walletLedgerReadBalance($userId, true);
            if ($walletBefore === null) throw new RuntimeException('wallet_balance_read_failed');
            if (!addBalance($userId, $creditAmount)) throw new RuntimeException('balance_update_failed');

            $depositTransactionId = createTransaction(
                $userId,
                'deposit',
                $creditAmount,
                'completed',
                'Slip verification' . $conversionNote,
                $slipDepositId
            );
            if (!$depositTransactionId) throw new RuntimeException('transaction_record_failed');
            $walletAfter = round((float) $walletBefore + $creditAmount, 2);
            if (!walletLedgerRecordMovement(
                $userId, $creditAmount, (float) $walletBefore, $walletAfter,
                'slip_deposit', 'transaction:' . (int) $depositTransactionId,
                (int) $slipDepositId, (int) $depositTransactionId, null,
                'เติมเงินผ่านสลิปธนาคาร',
                'Verified bank-slip credit. Banking identity details remain in admin-only slip evidence.',
                $transactionRef, true
            )) throw new RuntimeException('wallet_audit_failed');

            $rankResult = rankRecordDepositAndApplyBonus($userId, (int) $depositTransactionId, $thbAmount, 'slip');
            if (empty($rankResult['success'])) throw new RuntimeException((string) ($rankResult['message'] ?? 'ranking_bonus_failed'));
            if (!$conn->commit()) throw new RuntimeException('commit_outcome_unknown');
            $committed = true;
        } catch (Throwable $e) {
            if (!$committed) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
            }
            $reason = substr($e->getMessage(), 0, 80);
            if ($reason === 'commit_outcome_unknown') {
                $possibleDeposit = slipVerificationFindDeposit($transactionRef, $slipHash);
                if ($possibleDeposit) {
                    $freshJob = slipVerificationReadJobByHash($slipHash) ?: $job;
                    $result = slipVerificationFinalizeExistingDeposit($freshJob, $possibleDeposit);
                    if (!empty($result['success'])) {
                        if ($completeClaims()) slipVerificationMarkSharedFinalized($slipHash);
                        else slipVerificationFinalizeSharedClaims($freshJob, $slipData);
                        return $result;
                    }
                }
                slipVerificationSetJobStatus($slipHash, 'review', 'commit_outcome_unknown', 'ผลการ commit ยังไม่ชัดเจน ระบบจะตรวจฐานข้อมูลและกู้คืนอัตโนมัติ');
                return [
                    'success' => false,
                    'pending' => true,
                    'review' => false,
                    'attempt_id' => $attemptUuid,
                    'message' => 'ระบบกำลังยืนยันผลการเติมเงินอัตโนมัติ กรุณาอย่าส่งสลิปซ้ำ',
                ];
            }
            $releaseClaims();
            slipVerificationSetJobStatus($slipHash, 'provider_verified', $reason, 'ตรวจสลิปผ่านแล้ว ระบบจะลองบันทึกยอดเงินให้อัตโนมัติอีกครั้ง');
            error_log('Slip credit transaction failed safely: ' . $reason);
            return ['success' => false, 'pending' => true, 'attempt_id' => $attemptUuid, 'message' => 'ตรวจสลิปผ่านแล้ว ระบบกำลังกู้คืนการเติมเงินอัตโนมัติ กรุณาอย่าส่งสลิปซ้ำ'];
        }

        $bonusAmount = round((float) ($rankResult['bonus_amount'] ?? 0), 2);
        $totalCredited = round((float) ($rankResult['total_credited'] ?? $creditAmount), 2);
        if (!slipVerificationCompleteJob(
            $slipHash,
            $transactionRef,
            (int) $slipDepositId,
            (int) $depositTransactionId,
            $creditAmount,
            $bonusAmount,
            $totalCredited
        )) {
            error_log('Slip deposit committed but durable job could not be finalized; ref_hash=' . substr(hash('sha256', $transactionRef), 0, 12));
        }
        if ($completeClaims()) {
            slipVerificationMarkSharedFinalized($slipHash);
        } else {
            // Local money is already committed. Repair the cross-site fence now
            // if possible; otherwise the reconciler will keep retrying without
            // touching the wallet again.
            $freshJob = slipVerificationReadJobByHash($slipHash) ?: $job;
            slipVerificationFinalizeSharedClaims($freshJob, $slipData);
        }
        if (!logHistory($userId, 'slip_deposit', 'Slip deposit: ' . formatCurrency($creditAmount) . $conversionNote . ' - Ref: ' . $transactionRef)) {
            error_log('Slip deposit committed but audit history could not be written');
        }

        return [
            'success' => true,
            'completed' => true,
            'attempt_id' => $attemptUuid,
            'amount' => $creditAmount,
            'bonus_amount' => $bonusAmount,
            'total_credited' => $totalCredited,
            'new_balance' => (float) getUserBalance($userId),
            'message' => 'เติมเงินสำเร็จ ' . formatCurrency($totalCredited),
        ];
    } finally {
        slipVerificationReleaseNamedLock((string) ($lock['name'] ?? ''));
    }
}

function getSlipVerificationJobStatusForUser(int $userId, string $attemptUuid, float $deadline = 0.0, string $slipHash = ''): array
{
    $attemptUuid = normalizeSlipVerificationAttemptUuid($attemptUuid);
    $slipHash = strtolower(trim($slipHash));
    if (!preg_match('/^[a-f0-9]{64}$/D', $slipHash)) $slipHash = '';

    $job = $attemptUuid !== '' ? slipVerificationReadJobByAttempt($attemptUuid, $userId) : null;
    // A mobile browser can abort before it receives the canonical attempt UUID.
    // The uploaded file hash is stable across that race, so use it as a guarded
    // recovery key for the same authenticated user instead of falsely reporting
    // that the server never received the slip.
    if (!$job && $slipHash !== '') {
        $candidate = slipVerificationReadJobByHash($slipHash);
        if ($candidate && (int) ($candidate['user_id'] ?? 0) === $userId) $job = $candidate;
    }
    if (!$job) {
        return [
            'success' => false,
            'pending' => false,
            'retryable' => true,
            'code' => 'attempt_not_found',
            'message' => 'ยังเชื่อมรายการนี้กับสถานะบนเซิร์ฟเวอร์ไม่ได้ กรุณาใช้สลิปเดิมส่งใหม่ ระบบจะตรวจรายการเดิมก่อนเพื่อป้องกันการเติมซ้ำ',
        ];
    }

    $canonicalAttemptUuid = (string) ($job['attempt_uuid'] ?? '');
    $updated = strtotime((string) ($job['updated_at'] ?? '')) ?: 0;
    if (slipVerificationJobIsAutoRecoverable($job) && $updated < time() - 3) {
        $result = completeVerifiedSlipVerificationJob((string) $job['slip_hash'], $deadline);
        if (!empty($result['success'])) return $result;
        $job = slipVerificationReadJobByHash((string) $job['slip_hash']) ?: $job;
        $canonicalAttemptUuid = (string) ($job['attempt_uuid'] ?? $canonicalAttemptUuid);
    }
    $public = slipVerificationPublicJobStatus($job);
    if ($canonicalAttemptUuid !== '') $public['attempt_id'] = $canonicalAttemptUuid;
    return $public;
}

function reconcileSlipVerificationJobs(int $limit = 10): array
{
    global $conn;
    $limit = max(1, min(50, $limit));
    $summary = ['success' => true, 'attempted' => 0, 'reconciled' => 0, 'failed' => 0, 'skipped' => 0, 'shared_repairs' => 0];
    if (!ensureSlipVerificationJobsTable()) return ['success' => false, 'message' => 'Slip reconciliation storage is unavailable'];

    // Verification requests without a stored provider snapshot cannot be resumed
    // without the image. Mark stale rows retryable instead of leaving a fake
    // two-minute process running forever.
    $conn->query(
        "UPDATE slip_verification_jobs
         SET status = 'failed', last_error_code = 'verification_interrupted',
             last_error_message = 'การเชื่อมต่อขาดก่อนบันทึกผลตรวจ กรุณาเลือกสลิปเดิมเพื่อลองใหม่', updated_at = NOW()
         WHERE status = 'verifying' AND provider_data_ciphertext IS NULL
           AND updated_at < DATE_SUB(NOW(), INTERVAL 70 SECOND)"
    );

    $sql = "SELECT attempt_uuid, slip_hash, status, last_error_code
            FROM slip_verification_jobs
            WHERE provider_data_ciphertext IS NOT NULL
              AND (
                  status IN ('provider_verified','crediting')
                  OR (status = 'failed' AND last_error_code IN (
                      'exchange_rate','ranking_schema','shared_unavailable','shared_reserve_failed',
                      'balance_update_failed','wallet_balance_read_failed','wallet_audit_failed',
                      'slip_record_failed','transaction_record_failed','duplicate_check_prepare','duplicate_check'
                  ))
                  OR (status = 'review' AND last_error_code = 'commit_outcome_unknown')
              )
              AND updated_at < DATE_SUB(NOW(), INTERVAL 3 SECOND)
            ORDER BY updated_at ASC LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['success' => false, 'message' => 'Slip reconciliation query could not be prepared'];
    $stmt->bind_param('i', $limit);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'message' => 'Slip reconciliation query failed']; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    foreach ($rows as $row) {
        $summary['attempted']++;
        $reconciled = completeVerifiedSlipVerificationJob((string) ($row['slip_hash'] ?? ''));
        if (!empty($reconciled['success'])) $summary['reconciled']++;
        elseif (!empty($reconciled['pending'])) $summary['skipped']++;
        else $summary['failed']++;
    }

    // A local wallet commit must never remain permanently 'reserved' in the
    // cross-site ledger just because the final remote completion call lost its
    // response. Repair completed jobs independently and idempotently; this path
    // never credits money and therefore can run aggressively.
    $repairLimit = max(2, min(50, $limit));
    $repair = $conn->prepare(
        "SELECT * FROM slip_verification_jobs
         WHERE status='completed' AND shared_finalized_at IS NULL
           AND last_error_code <> 'shared_finalize_owner_conflict'
         ORDER BY updated_at ASC LIMIT ?"
    );
    if ($repair) {
        $repair->bind_param('i', $repairLimit);
        if ($repair->execute()) {
            $repairResult = $repair->get_result();
            while ($repairJob = $repairResult ? $repairResult->fetch_assoc() : null) {
                if (!$repairJob) break;
                $minimalData = ['transaction_ref' => (string) ($repairJob['transaction_ref'] ?? '')];
                if (slipVerificationFinalizeSharedClaims($repairJob, $minimalData)) {
                    $summary['shared_repairs']++;
                }
            }
        }
        $repair->close();
    }
    return $summary;
}

/**
 * Process slip deposit - verify, validate, and add balance
 * @param int $userId User ID
 * @param string $imageBase64 Base64 encoded slip image
 * @return array [success, amount, message]
 */
function processSlipDeposit($userId, $imageBase64, string $requestedAttemptUuid = '', float $deadline = 0.0, string $qrPayload = '')
{
    $userId = (int) $userId;
    $qrPayload = trim($qrPayload);
    if ($qrPayload !== '' && (strlen($qrPayload) > 128 || preg_match('/[^\x21-\x7E]/', $qrPayload))) {
        $qrPayload = '';
    }
    $debugAttemptId = normalizeSlipVerificationAttemptUuid($requestedAttemptUuid);
    $depositUser = $userId > 0 ? getUserById($userId) : null;
    if (!$depositUser || ($depositUser['status'] ?? '') !== 'active') {
        return ['success' => false, 'message' => Lang::t('common.error.invalid_request')];
    }
    if ((string) getSetting('easyslip_enabled') !== '1') {
        return [
            'success' => false,
            'error_code' => 'easyslip_disabled',
            'retryable' => false,
            'message' => 'ระบบตรวจสอบสลิปธนาคารถูกปิดใช้งานชั่วคราว',
        ];
    }
    if (!ensureSlipDepositsTable() || !ensureSlipVerificationJobsTable()) {
        error_log('Unable to prepare slip deposit storage');
        return ['success' => false, 'message' => Lang::t('common.error.operation')];
    }

    if (!is_string($imageBase64) || strlen($imageBase64) > 6 * 1024 * 1024
        || !preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $imageBase64)) {
        return ['success' => false, 'message' => Lang::t('deposit.error.slip_invalid_format')];
    }
    $data = substr($imageBase64, strpos($imageBase64, ',') + 1);
    $imageBin = base64_decode($data, true);
    if ($imageBin === false || strlen($imageBin) < 1 || strlen($imageBin) > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => Lang::t('deposit.error.slip_invalid_format')];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_buffer($finfo, $imageBin) : false;
        if ($finfo) finfo_close($finfo);
        if ($mime !== false && !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return ['success' => false, 'message' => Lang::t('deposit.error.slip_invalid_format')];
        }
    }
    $slipHash = hash('sha256', $imageBin);
    $imageBytesLength = strlen($imageBin);
    $imageMime = isset($mime) && is_string($mime) ? $mime : '';
    unset($imageBin, $data);

    slipDebugLogEvent('process_started', 'info', 'Slip deposit processing started', [
        'attempt_uuid' => $debugAttemptId,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
        'include_request_context' => true,
        'request' => [
            'image' => [
                'mime' => $imageMime,
                'decoded_bytes' => $imageBytesLength,
                'decoded_sha256' => $slipHash,
                'encoded_bytes' => is_string($imageBase64) ? strlen($imageBase64) : 0,
            ],
            'qr_payload_hint' => [
                'available' => $qrPayload !== '',
                'length' => $qrPayload !== '' ? strlen($qrPayload) : 0,
                'sha256' => $qrPayload !== '' ? hash('sha256', $qrPayload) : '',
            ],
        ],
        'context' => [
            'user' => [
                'id' => $userId,
                'username' => $depositUser['username'] ?? '',
                'role' => $depositUser['role'] ?? '',
                'status' => $depositUser['status'] ?? '',
            ],
            'settings_snapshot' => [
                'easyslip_enabled' => getSetting('easyslip_enabled'),
                'easyslip_max_age_minutes_raw' => getSetting('easyslip_max_age_minutes'),
                'easyslip_account_number' => getSetting('easyslip_account_number'),
                'easyslip_phone' => getSetting('easyslip_phone'),
                'easyslip_receiver_name' => getSetting('easyslip_receiver_name'),
                'easyslip_receiver_name_en' => getSetting('easyslip_receiver_name_en'),
                'currency_name' => getSetting('currency_name'),
                'api_key_configured' => trim((string) getSetting('easyslip_api_key')) !== '',
            ],
            'clock' => slipDebugClockSnapshot(),
        ],
    ]);

    $existingDeposit = slipVerificationFindDeposit('', $slipHash);
    if ($existingDeposit) {
        slipDebugLogEvent('duplicate_image_rejected', 'warning', 'Slip image hash already exists in local deposits', [
            'attempt_uuid' => $debugAttemptId,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'error_code' => 'slip_hash_used',
            'response' => ['existing_deposit' => $existingDeposit],
        ]);
        return ['success' => false, 'message' => Lang::t('deposit.error.slip_hash_used')];
    }

    // The first remark is tied to the browser attempt when available. After the
    // durable job is claimed we rebuild it from the canonical attempt id, so a
    // retry that supplied a different UUID cannot overwrite the identity of the
    // original verification request.
    $requestedCanonicalAttempt = normalizeSlipVerificationAttemptUuid($requestedAttemptUuid);
    $claimRemark = getEasySlipVerificationRemark($requestedCanonicalAttempt, $userId);
    $jobClaim = claimSlipVerificationJob($slipHash, $userId, $claimRemark, $requestedAttemptUuid);
    $attemptId = (string) ($jobClaim['attempt_id'] ?? $requestedCanonicalAttempt);
    $verificationRemark = getEasySlipVerificationRemark($attemptId, $userId);
    if ($attemptId !== '' && $verificationRemark !== $claimRemark) {
        slipVerificationUpdateJobRemark($slipHash, $attemptId, $userId, $verificationRemark);
    }
    $reassignedDebugEvents = 0;
    if ($debugAttemptId !== '' && $attemptId !== '' && !hash_equals($debugAttemptId, $attemptId)) {
        $reassignedDebugEvents = slipDebugReassignAttempt($debugAttemptId, $attemptId, $slipHash, $userId);
    }
    slipDebugLogEvent('job_claim', empty($jobClaim['success']) ? 'warning' : 'info', empty($jobClaim['success']) ? 'Slip verification job claim was not granted' : 'Slip verification job claimed', [
        'attempt_uuid' => $attemptId,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
        'error_code' => empty($jobClaim['success']) ? (string) ($jobClaim['reason'] ?? 'storage') : '',
        'request' => [
            'requested_attempt_uuid' => $requestedAttemptUuid,
            'verification_remark' => $verificationRemark,
        ],
        'response' => [
            'success' => !empty($jobClaim['success']),
            'reason' => $jobClaim['reason'] ?? '',
            'new' => !empty($jobClaim['new']),
            'retry' => !empty($jobClaim['retry']),
            'resume' => !empty($jobClaim['resume']),
            'previous_status' => $jobClaim['previous_status'] ?? '',
            'existing_job_status' => is_array($jobClaim['job'] ?? null) ? ($jobClaim['job']['status'] ?? '') : '',
            'provider_snapshot_available' => is_array($jobClaim['provider_data'] ?? null),
            'request_attempt_uuid' => $debugAttemptId,
            'canonical_attempt_uuid' => $attemptId,
            'reassigned_debug_events' => $reassignedDebugEvents,
        ],
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
    if (empty($jobClaim['success'])) {
        $reason = (string) ($jobClaim['reason'] ?? 'storage');
        if ($reason === 'processing') {
            return [
                'success' => false,
                'pending' => true,
                'attempt_id' => $attemptId,
                'message' => 'สลิปนี้กำลังถูกตรวจสอบ ระบบจะติดตามผลให้อัตโนมัติ กรุณาอย่าส่งซ้ำ',
            ];
        }
        if ($reason === 'completed' && is_array($jobClaim['job'] ?? null)) {
            return slipVerificationPublicJobStatus($jobClaim['job']);
        }
        if ($reason === 'provider_cooldown') {
            $retryAfter = max(1, (int) ($jobClaim['retry_after_seconds'] ?? 1));
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptId,
                'error_code' => 'provider_cooldown',
                'retry_after_seconds' => $retryAfter,
                'message' => 'ระบบตรวจสอบสลิปภายนอกเพิ่งตอบช้าหรือขัดข้อง รายการเดิมถูกเก็บไว้อย่างปลอดภัย กรุณาลองอีกครั้งในประมาณ ' . $retryAfter . ' วินาที',
            ];
        }
        if ($reason === 'history_cooldown') {
            $retryAfter = max(1, (int) ($jobClaim['retry_after_seconds'] ?? 1));
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptId,
                'error_code' => 'shared_history_sync_pending',
                'retry_after_seconds' => $retryAfter,
                'message' => 'ระบบกำลังย้ายประวัติสลิประหว่างเว็บไซต์เพื่อป้องกันยอดซ้ำ ยังไม่มีการส่งสลิปนี้ไปตรวจภายนอก กรุณาลองอีกครั้งในอีกสักครู่',
            ];
        }
        if ($reason === 'different_user') {
            return ['success' => false, 'attempt_id' => $attemptId, 'message' => Lang::t('deposit.error.slip_used')];
        }
        return ['success' => false, 'attempt_id' => $attemptId, 'message' => 'ระบบเตรียมการตรวจสลิปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'];
    }

    $hasReusableProviderSnapshot = !empty($jobClaim['resume']) && is_array($jobClaim['provider_data'] ?? null);
    $providerRefreshControl = $hasReusableProviderSnapshot ? slipDebugProviderRefreshStatus($slipHash) : null;
    $providerRefreshArmed = !empty($providerRefreshControl['force_provider_refresh_once']);

    // During the one-time historical migration, no new slip can be financially
    // finalized anyway. Check global readiness before consuming EasySlip quota or
    // creating a live cross-site image lease. This lets the background/web bulk
    // importer finish cleanly and turns the current production-wide failure mode
    // into a short explicit maintenance response instead of an 8-second provider
    // call followed by another migration gate.
    if (!$hasReusableProviderSnapshot) {
        $historyPreflight = slipVerificationSharedHistoryReady();
        if (empty($historyPreflight['ready'])) {
            slipVerificationSetJobStatus($slipHash, 'failed', 'shared_history_sync_pending', 'ระบบกำลังย้ายประวัติสลิประหว่างเว็บไซต์ก่อนเปิดการเติมเงินอัตโนมัติ');
            slipDebugLogEvent('history_preflight_waiting', 'warning', 'New EasySlip request skipped until historical shared-ledger migration is globally ready', [
                'attempt_uuid' => $attemptId,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'error_code' => 'shared_history_sync_pending',
                'response' => ['history_status' => $historyPreflight, 'provider_request_sent' => false, 'financial_action' => 'none'],
            ]);
            return [
                'success' => false,
                'pending' => false,
                'retryable' => true,
                'attempt_id' => $attemptId,
                'error_code' => 'shared_history_sync_pending',
                'retry_after_seconds' => 10,
                'message' => 'ระบบกำลังย้ายประวัติสลิประหว่างเว็บไซต์เพื่อป้องกันยอดซ้ำ ยังไม่มีการส่งสลิปนี้ไปตรวจภายนอก กรุณาลองอีกครั้งในอีกสักครู่',
            ];
        }
    }

    if ($hasReusableProviderSnapshot && !$providerRefreshArmed) {
        slipDebugLogEvent('provider_snapshot_reused', 'info', 'Existing EasySlip provider snapshot reused; no new provider request was sent', [
            'attempt_uuid' => $attemptId,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'response' => ['normalized_provider_data' => $jobClaim['provider_data']],
            'context' => [
                'previous_status' => $jobClaim['previous_status'] ?? '',
                'clock' => slipDebugClockSnapshot(),
            ],
        ]);
        return completeVerifiedSlipVerificationJob($slipHash, $deadline);
    }

    if ($hasReusableProviderSnapshot && $providerRefreshArmed) {
        $forceFreshProviderResponse = slipDebugConsumeProviderRefresh($slipHash);
        if ($forceFreshProviderResponse) {
            slipDebugLogEvent('provider_refresh_forced', 'warning', 'Admin diagnostic provider capture started without mutating the financial job', [
                'attempt_uuid' => $attemptId,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'context' => [
                    'previous_status' => $jobClaim['previous_status'] ?? '',
                    'provider_snapshot_available' => true,
                    'control_requested_at' => $providerRefreshControl['requested_at'] ?? null,
                    'control_requested_by' => $providerRefreshControl['requested_by'] ?? null,
                    'clock' => slipDebugClockSnapshot(),
                ],
            ]);
            $diagnosticResult = verifySlipWithEasyslip($imageBase64, $verificationRemark, [
                'attempt_uuid' => $attemptId,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'diagnostic_only' => true,
                'deadline' => $deadline,
                'qr_payload' => $qrPayload,
            ]);
            slipDebugLogEvent('provider_refresh_observed', !empty($diagnosticResult['success']) ? 'info' : 'warning', 'Admin diagnostic provider capture finished; durable financial snapshot was preserved', [
                'attempt_uuid' => $attemptId,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
                'error_code' => empty($diagnosticResult['success']) ? (string) ($diagnosticResult['error_code'] ?? 'diagnostic_failed') : '',
                'response' => [
                    'success' => !empty($diagnosticResult['success']),
                    'error_code' => (string) ($diagnosticResult['error_code'] ?? ''),
                    'provider_code' => (string) ($diagnosticResult['provider_code'] ?? ''),
                    'transport_class' => (string) ($diagnosticResult['transport_class'] ?? ''),
                    'provider_http_code' => (int) ($diagnosticResult['provider_http_code'] ?? 0),
                    'provider_ip_family' => (string) ($diagnosticResult['provider_ip_family'] ?? ''),
                    'financial_job_mutated' => false,
                ],
            ]);
        } else {
            slipDebugLogEvent('provider_refresh_not_consumed', 'info', 'Admin diagnostic provider capture was cancelled before use; durable snapshot was preserved', [
                'attempt_uuid' => $attemptId,
                'slip_hash' => $slipHash,
                'user_id' => $userId,
            ]);
        }
        return completeVerifiedSlipVerificationJob($slipHash, $deadline);
    }

    // The provider may legitimately take tens of seconds. Keep the cross-site
    // image fence alive longer than the entire customer request so the sibling
    // website cannot take over the same slip while EasySlip is still working.
    $imageClaim = sharedLedgerBegin('slip_image', $slipHash, 75, $attemptId);
    slipDebugLogEvent('shared_image_claim', empty($imageClaim['success']) ? 'warning' : 'info', empty($imageClaim['success']) ? 'Shared image ledger claim was not granted' : 'Shared image ledger claim granted', [
        'attempt_uuid' => $attemptId,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
        'error_code' => empty($imageClaim['success']) ? (string) ($imageClaim['error'] ?? $imageClaim['status'] ?? 'shared_claim_failed') : '',
        'response' => $imageClaim,
        'context' => ['clock' => slipDebugClockSnapshot()],
    ]);
    if (empty($imageClaim['success'])) {
        if (!empty($imageClaim['duplicate'])) {
            $status = strtolower((string) ($imageClaim['status'] ?? ''));
            if ($status === 'completed') {
                if (empty($imageClaim['same_owner'])) {
                    slipVerificationSetJobStatus($slipHash, 'failed', 'shared_already_completed', 'สลิปนี้ถูกใช้งานสำเร็จจากอีกเว็บไซต์แล้ว');
                    return [
                        'success' => false,
                        'retryable' => false,
                        'attempt_id' => $attemptId,
                        'code' => 'shared_already_completed',
                        'message' => 'สลิปนี้ถูกใช้งานแล้ว ไม่สามารถเติมซ้ำได้',
                    ];
                }
                slipVerificationSetJobStatus(
                    $slipHash,
                    'review',
                    'shared_completed_without_local_record',
                    'Shared ledger เป็นเจ้าของรายการนี้เองแต่ฐานข้อมูล local ขาดข้อมูลทางการเงิน'
                );
                return [
                    'success' => false,
                    'review' => true,
                    'attempt_id' => $attemptId,
                    'message' => 'พบข้อมูลทางการเงินที่ขัดแย้งกัน ระบบหยุดอัตโนมัติเพื่อป้องกันยอดผิดพลาด',
                ];
            }
            slipVerificationSetJobStatus($slipHash, 'verifying', 'shared_processing_elsewhere', 'สลิปกำลังดำเนินการจากอีกเว็บไซต์');
            return [
                'success' => false,
                'pending' => true,
                'attempt_id' => $attemptId,
                'message' => 'สลิปกำลังดำเนินการจากอีกเว็บไซต์ ระบบจะตรวจซ้ำอัตโนมัติ',
            ];
        }
        slipVerificationSetJobStatus($slipHash, 'failed', 'shared_unavailable', 'ระบบเชื่อมข้อมูลระหว่างสองเว็บไซต์ขัดข้องชั่วคราว');
        return [
            'success' => false,
            'retryable' => true,
            'attempt_id' => $attemptId,
            'message' => 'ระบบเชื่อมข้อมูลระหว่างสองเว็บไซต์ขัดข้องชั่วคราว กรุณาใช้สลิปเดิมส่งใหม่',
        ];
    }


    if (!slipVerificationProviderSnapshotEncryptionReady()) {
        sharedLedgerRelease($imageClaim['lease']);
        slipVerificationSetJobStatus($slipHash, 'failed', 'snapshot_encryption_unavailable', 'ระบบเข้ารหัสข้อมูลยืนยันสลิปไม่พร้อมใช้งานชั่วคราว');
        slipDebugLogEvent('provider_preflight_failed', 'critical', 'Provider verification skipped because encrypted snapshot storage is unavailable', [
            'attempt_uuid' => $attemptId,
            'slip_hash' => $slipHash,
            'user_id' => $userId,
            'error_code' => 'snapshot_encryption_unavailable',
            'context' => ['clock' => slipDebugClockSnapshot()],
        ]);
        return [
            'success' => false,
            'retryable' => true,
            'attempt_id' => $attemptId,
            'error_code' => 'snapshot_encryption_unavailable',
            'message' => 'ระบบจัดเก็บผลตรวจสลิปไม่พร้อมชั่วคราว กรุณาใช้สลิปเดิมลองใหม่อีกครั้ง',
        ];
    }

    $verification = verifySlipWithEasyslip($imageBase64, $verificationRemark, [
        'attempt_uuid' => $attemptId,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
        'deadline' => $deadline,
        'qr_payload' => $qrPayload,
    ]);
    slipVerificationRecordProviderOutcome($slipHash, is_array($verification) ? $verification : []);
    if (empty($verification['success'])) {
        sharedLedgerRelease($imageClaim['lease']);
        $message = (string) ($verification['message'] ?? 'ตรวจสอบสลิปไม่สำเร็จ');
        $errorCode = substr(trim((string) ($verification['error_code'] ?? 'provider_error')), 0, 80);
        if ($errorCode === '') $errorCode = 'provider_error';
        $retryable = !empty($verification['retryable']);
        $retryAfter = max(0, (int) ($verification['retry_after_seconds'] ?? slipProviderPolicy()['retry_cooldown_seconds']));
        if (!empty($verification['pending']) || $errorCode === 'provider_outcome_unknown') {
            // Ambiguous full-upload timeouts and exhausted transient HTTP retries
            // are both non-terminal availability states. Keep the durable job and
            // signed remark intact so a later same-slip retry can recover cache
            // without ever turning an upstream 503 into a failed financial row.
            $pendingCode = $errorCode === 'provider_outcome_unknown'
                ? 'provider_pending_confirmation'
                : 'provider_pending_transient';
            slipVerificationSetJobStatus($slipHash, 'provider_pending', $pendingCode, $message);
            return [
                'success' => false,
                'pending' => true,
                'retryable' => false,
                'system_error' => false,
                'error_code' => $pendingCode,
                'provider_code' => (string) ($verification['provider_code'] ?? ''),
                'attempt_id' => $attemptId,
                'retry_after_seconds' => $retryAfter,
                'message' => $errorCode === 'provider_outcome_unknown'
                    ? 'เว็บส่งสลิปไปตรวจครบแล้ว แต่ยังไม่ได้รับผลยืนยันกลับมา ระบบจะไม่ส่งสลิปซ้ำอัตโนมัติ กรุณารอผลรายการเดิมก่อน'
                    : 'ระบบตรวจสอบสลิปภายนอกสะดุดชั่วคราว ระบบได้ลองใหม่ให้อัตโนมัติแล้วและเก็บรายการเดิมไว้ กรุณารอหรือลองสลิปเดิมอีกครั้งตามเวลาที่แจ้ง',
            ];
        }
        slipVerificationSetJobStatus($slipHash, 'failed', $errorCode, $message);
        return [
            'success' => false,
            'retryable' => $retryable,
            'system_error' => !empty($verification['system_error']),
            'error_code' => $errorCode,
            'provider_code' => (string) ($verification['provider_code'] ?? ''),
            'attempt_id' => $attemptId,
            'retry_after_seconds' => $retryAfter,
            'message' => $message,
        ];
    }

    $slipData = is_array($verification['data'] ?? null) ? $verification['data'] : [];
    $providerJobAfterRequest = slipVerificationReadJobByHash($slipHash);
    $providerRequestCount = max(0, (int) ($providerJobAfterRequest['provider_request_count'] ?? 0));
    $providerDuplicate = !empty($slipData['is_duplicate']);
    $returnedRemark = trim((string) ($slipData['verification_remark'] ?? ''));
    // EasySlip's duplicate flag is provider-verification history, not proof that
    // our wallet was credited. The signed remark remains useful provenance, but
    // monetary deduplication is enforced by our shared/local slip and transaction
    // references later in the flow.
    $remarkProof = validateEasySlipVerificationRemark($returnedRemark, $userId);
    $trustedOwnRequest = !empty($remarkProof['trusted']);
    $slipData['_duplicate_remark_trusted'] = $trustedOwnRequest;
    // A provider-level duplicate on the FIRST EasySlip verification of this
    // durable job may be a historic use from this or the sibling website. Do
    // not credit it merely because EasySlip echoed our current remark. A saved
    // snapshot from an earlier request of the SAME durable job is allowed to
    // resume, which preserves mobile retry/recovery and the known KTB test case.
    $slipData['_duplicate_recovery_allowed'] = slipVerificationProviderDuplicateRecoveryAllowed(
        $providerDuplicate,
        $trustedOwnRequest,
        $hasReusableProviderSnapshot,
        $providerRequestCount,
        false
    );
    // Cross-site historical readiness is checked once inside the locked local
    // completion path. Avoid a duplicate network round-trip here; the browser
    // has a strict bounded budget and the durable snapshot is already sufficient
    // for background/status recovery if the readiness hub is briefly slow.
    $slipData['_shared_history_ready_at_verify'] = null;
    $slipData['_duplicate_remark_attempt_id'] = (string) ($remarkProof['attempt_id'] ?? '');
    slipDebugLogEvent('provider_duplicate_policy', $providerDuplicate && !$trustedOwnRequest ? 'warning' : 'info', 'EasySlip duplicate metadata recorded; wallet deduplication remains local/shared-ledger authoritative', [
        'attempt_uuid' => $attemptId,
        'slip_hash' => $slipHash,
        'user_id' => $userId,
        'error_code' => '',
        'request' => [
            'expected_signed_remark_sha256' => hash('sha256', $verificationRemark),
            'expected_remark_version' => strpos($verificationRemark, 'ez2:') === 0 ? 'ez2' : 'compatibility',
        ],
        'response' => [
            'provider_is_duplicate' => $providerDuplicate,
            'provider_returned_remark_sha256' => $returnedRemark !== '' ? hash('sha256', $returnedRemark) : '',
            'remark_proof' => $remarkProof,
            'signed_remark_trusted' => $trustedOwnRequest,
            'provider_duplicate_is_financial_decision' => false,
            'provider_request_count' => $providerRequestCount,
            'shared_history_ready' => null,
            'shared_history_status' => $providerDuplicate ? 'deferred_to_locked_completion' : null,
            'financial_duplicate_authority' => 'shared_and_local_reference_after_history_readiness',
        ],
    ]);

    // Persist immediately after EasySlip responds. A browser disconnect or proxy
    // timeout after this point can be recovered without consuming another API
    // request or asking the customer to upload the slip again.
    if (!slipVerificationStoreProviderData($slipHash, $slipData)) {
        sharedLedgerRelease($imageClaim['lease']);
        // EasySlip already returned successfully. If encrypted snapshot storage is
        // unhealthy, immediately calling the provider again only burns quota and
        // can recreate the retry storm without improving recoverability.
        $snapshotRetryAfter = max(10, (int) slipProviderPolicy()['retry_cooldown_seconds']);
        slipVerificationSetRetryCooldown($slipHash, $snapshotRetryAfter);
        slipVerificationSetJobStatus($slipHash, 'failed', 'provider_snapshot_write_failed', 'ตรวจสลิปผ่านแล้วแต่บันทึกผลตรวจไม่สำเร็จ');
        return [
            'success' => false,
            'retryable' => true,
            'attempt_id' => $attemptId,
            'error_code' => 'provider_snapshot_write_failed',
            'retry_after_seconds' => $snapshotRetryAfter,
            'message' => 'ตรวจสลิปผ่านแล้วแต่ยังบันทึกผลไม่ได้ ระบบหยุดการตรวจซ้ำชั่วคราวเพื่อป้องกันรายการซ้ำ กรุณาใช้สลิปเดิมลองใหม่ภายหลัง',
        ];
    }

    return completeVerifiedSlipVerificationJob($slipHash, $deadline);
}

/**
 * Ensure slip_deposits table exists
 */
function ensureSlipDepositsTable()
{
    global $conn;
    static $ensured = false;
    if ($ensured) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS slip_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        transaction_ref VARCHAR(100) UNIQUE,
        amount DECIMAL(10,2) NOT NULL,
        sender_name VARCHAR(100),
        sender_account VARCHAR(50),
        receiver_name VARCHAR(100),
        receiver_account VARCHAR(50),
        bank_code VARCHAR(20),
        transfer_date VARCHAR(50),
        slip_image LONGTEXT,
        slip_hash VARCHAR(64) NULL,
        api_response TEXT,
        verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_transaction_ref (transaction_ref),
        INDEX idx_slip_hash (slip_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        return false;
    }

    // Migrate legacy tables once on the actual deposit path, not on every page load.
    $columnCheck = $conn->query("SHOW COLUMNS FROM slip_deposits LIKE 'slip_hash'");
    if (!$columnCheck) {
        return false;
    }
    if ($columnCheck->num_rows === 0 && !$conn->query("ALTER TABLE slip_deposits ADD COLUMN slip_hash VARCHAR(64) NULL AFTER slip_image")) {
        return false;
    }

    $indexCheck = $conn->query("SHOW INDEX FROM slip_deposits WHERE Key_name = 'idx_slip_hash'");
    if (!$indexCheck) {
        return false;
    }
    if ($indexCheck->num_rows === 0 && !$conn->query("ALTER TABLE slip_deposits ADD INDEX idx_slip_hash (slip_hash)")) {
        return false;
    }

    $ensured = true;
    return true;
}

/**
 * Validate the canonical public origin used for safe same-site redirects.
 * Paths, credentials, query strings, and fragments are intentionally rejected.
 */
function normalizeCanonicalBaseUrl($value): string
{
    if (!is_scalar($value)) return '';
    $base = rtrim(trim((string) $value), '/');
    if ($base === '' || strlen($base) > 512 || !isSafeHttpsUrl($base)) return '';
    $parts = parse_url($base);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || !empty($parts['user'])
        || !empty($parts['pass'])
        || !empty($parts['query'])
        || !empty($parts['fragment'])
        || (!empty($parts['path']) && $parts['path'] !== '/')) {
        return '';
    }
    return $base;
}

/** Return the configured canonical public URL without trusting Host headers. */
function getCanonicalBaseUrl(): string
{
    $configured = normalizeCanonicalBaseUrl(getSetting('site_base_url', ''));
    if ($configured !== '') return $configured;
    return defined('APP_BASE_URL') ? normalizeCanonicalBaseUrl(APP_BASE_URL) : '';
}

/** Admin-only account management helpers. */
function validateManagedUsername(string $username): bool
{
    return strlen($username) >= 3 && strlen($username) <= 60 && preg_match('/^[A-Za-z0-9_.-]+$/', $username) === 1;
}

function createManagedAccount(string $username, string $email, string $password, string $role, float $initialBalance = 0.0): array
{
    global $conn;
    require_once __DIR__ . '/account_recovery.php';
    $username = trim($username);
    $email = accountRecoveryNormalizeGmail($email);
    $role = in_array($role, ['user', 'reseller'], true) ? $role : '';
    $initialBalance = round($initialBalance, 2);

    if ($role === '' || !validateManagedUsername($username)) {
        return ['success' => false, 'message' => Lang::t('account.error.username_format')];
    }
    if ($email === '') {
        return ['success' => false, 'message' => 'รองรับเฉพาะอีเมล @gmail.com เท่านั้น'];
    }
    if (strlen($password) < 8 || strlen($password) > 200) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters'];
    }
    if (!is_finite($initialBalance) || $initialBalance < 0 || $initialBalance > 10000000) {
        return ['success' => false, 'message' => 'Invalid opening balance'];
    }
    if (!accountRecoveryEnsureSchema()) {
        return ['success' => false, 'message' => 'Unable to prepare account recovery'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'message' => 'Unable to prepare financial audit storage'];
    }

    $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR LOWER(email) = ? LIMIT 1');
    if (!$check) return ['success' => false, 'message' => 'Unable to create account'];
    $check->bind_param('ss', $username, $email);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    $check->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) return ['success' => false, 'message' => 'Unable to create account'];

    $conn->begin_transaction();
    try {
        $status = 'active';
        $insert = $conn->prepare('INSERT INTO users (username, email, password, role, balance, status) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$insert) throw new RuntimeException('prepare insert failed');
        $insert->bind_param('ssssds', $username, $email, $hash, $role, $initialBalance, $status);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('insert failed');
        }
        $userId = (int) $conn->insert_id;
        $insert->close();
        if ($initialBalance > 0) {
            $openingTransactionId = (int) createTransaction($userId, 'manual_add', $initialBalance, 'completed', 'Opening balance added by admin');
            if ($openingTransactionId < 1) throw new RuntimeException('opening transaction failed');
            $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
            if ($actorUserId < 1 || (string) ($_SESSION['role'] ?? '') !== 'admin') $actorUserId = 0;
            if (!walletLedgerRecordMovement(
                $userId, $initialBalance, 0.0, $initialBalance,
                'admin_opening_balance', 'transaction:' . $openingTransactionId,
                null, $openingTransactionId, $actorUserId > 0 ? $actorUserId : null,
                'ยอดตั้งต้นโดยผู้ดูแลระบบ',
                'Opening balance assigned when the managed account was created.',
                null, true
            )) throw new RuntimeException('opening wallet audit failed');
        }
        $conn->commit();
        return ['success' => true, 'user_id' => $userId, 'username' => $username];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Managed account creation failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to create account'];
    }
}

function setManagedAccountStatus(int $userId, string $expectedRole, string $status): array
{
    global $conn;
    if ($userId < 1 || !in_array($expectedRole, ['user', 'reseller'], true) || !in_array($status, ['active', 'banned'], true)) {
        return ['success' => false, 'message' => 'Invalid account request'];
    }
    $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ? AND role = ?');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to update account'];
    $stmt->bind_param('sis', $status, $userId, $expectedRole);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ok) return ['success' => false, 'message' => 'Account not found or unchanged'];
    return ['success' => true];
}

function deleteManagedAccount(int $userId, string $expectedRole): array
{
    global $conn;
    if ($userId < 1 || !in_array($expectedRole, ['user', 'reseller'], true)) {
        return ['success' => false, 'message' => 'Invalid account request'];
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id, username, status FROM users WHERE id = ? AND role = ? LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('prepare account lock failed');
        $lock->bind_param('is', $userId, $expectedRole);
        if (!$lock->execute()) {
            $lock->close();
            throw new RuntimeException('account lock failed');
        }
        $result = $lock->get_result();
        $account = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$account) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Account not found'];
        }

        // Preserve any account referenced by financial, key, or audit records.
        // Hard-deleting such a user would break history and may violate foreign keys.
        $references = [
            ['transactions', 'user_id'],
            ['history', 'user_id'],
            ['keys', 'assigned_to'],
            ['keys', 'purchased_by'],
            ['slip_deposits', 'user_id'],
            ['binance_deposits', 'user_id'],
            ['truemoney_redemptions', 'user_id'],
            ['payment_gateway_orders', 'user_id'],
            ['redeem_codes', 'created_by'],
            ['redeem_codes', 'used_by'],
            ['reseller_variant_prices', 'reseller_id'],
        ];
        $hasHistory = false;
        $schemaStmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if (!$schemaStmt) throw new RuntimeException('prepare schema inspection failed');
        foreach ($references as [$table, $column]) {
            $schemaStmt->bind_param('ss', $table, $column);
            if (!$schemaStmt->execute()) throw new RuntimeException('schema inspection failed');
            $schemaStmt->bind_result($columnExists);
            $schemaStmt->fetch();
            $schemaStmt->free_result();
            if ((int) $columnExists < 1) continue;

            // Table and column names are from the fixed allowlist above, never user input.
            $check = $conn->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
            if (!$check) throw new RuntimeException('prepare history inspection failed');
            $check->bind_param('i', $userId);
            if (!$check->execute()) {
                $check->close();
                throw new RuntimeException('history inspection failed');
            }
            $check->store_result();
            $found = $check->num_rows > 0;
            $check->close();
            if ($found) {
                $hasHistory = true;
                break;
            }
        }
        $schemaStmt->close();

        if ($hasHistory) {
            $disable = $conn->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role = ?");
            if (!$disable) throw new RuntimeException('prepare account disable failed');
            $disable->bind_param('is', $userId, $expectedRole);
            if (!$disable->execute() || $disable->affected_rows < 0) {
                $disable->close();
                throw new RuntimeException('account disable failed');
            }
            $disable->close();
            $conn->commit();
            return [
                'success' => true,
                'mode' => 'disabled',
                'username' => (string) $account['username'],
                'message' => 'Account was disabled instead of deleted to preserve financial and key history',
            ];
        }

        $delete = $conn->prepare('DELETE FROM users WHERE id = ? AND role = ?');
        if (!$delete) throw new RuntimeException('prepare account delete failed');
        $delete->bind_param('is', $userId, $expectedRole);
        if (!$delete->execute() || $delete->affected_rows !== 1) {
            $delete->close();
            throw new RuntimeException('account delete failed');
        }
        $delete->close();
        $conn->commit();
        return ['success' => true, 'mode' => 'deleted', 'username' => (string) $account['username']];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Managed account deletion failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to remove account'];
    }
}

function adjustManagedAccountBalance(int $userId, string $expectedRole, string $operation, float $amount): array
{
    global $conn;
    $amount = round($amount, 2);
    if ($userId < 1 || !in_array($expectedRole, ['user', 'reseller'], true) ||
        !in_array($operation, ['add', 'deduct'], true) || !is_finite($amount) || $amount <= 0 || $amount > 10000000) {
        return ['success' => false, 'message' => 'Invalid balance request'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'message' => 'Financial audit storage is unavailable'];
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT username, balance FROM users WHERE id = ? AND role = ? LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('prepare lock failed');
        $lock->bind_param('is', $userId, $expectedRole);
        if (!$lock->execute()) {
            $lock->close();
            throw new RuntimeException('lock failed');
        }
        $result = $lock->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$user) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Account not found'];
        }

        $current = (float) $user['balance'];
        if ($operation === 'deduct' && $current < $amount) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Insufficient balance'];
        }
        $delta = $operation === 'add' ? $amount : -$amount;
        $update = $conn->prepare('UPDATE users SET balance = balance + ? WHERE id = ? AND role = ?');
        if (!$update) throw new RuntimeException('prepare update failed');
        $update->bind_param('dis', $delta, $userId, $expectedRole);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('balance update failed');
        }
        $update->close();

        $type = $operation === 'add' ? 'manual_add' : 'manual_deduct';
        $description = $operation === 'add' ? 'Balance added by admin' : 'Balance deducted by admin';
        $balanceTransactionId = (int) createTransaction($userId, $type, $amount, 'completed', $description);
        if ($balanceTransactionId < 1) throw new RuntimeException('balance transaction failed');
        $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
        if ($actorUserId < 1 || (string) ($_SESSION['role'] ?? '') !== 'admin') $actorUserId = 0;
        $newBalance = round($current + $delta, 2);
        if (!walletLedgerRecordMovement(
            $userId, $delta, $current, $newBalance,
            'admin_adjustment', 'transaction:' . $balanceTransactionId,
            null, $balanceTransactionId, $actorUserId > 0 ? $actorUserId : null,
            $operation === 'add' ? 'ผู้ดูแลระบบเพิ่มยอดเงิน' : 'ผู้ดูแลระบบหักยอดเงิน',
            $operation === 'add' ? 'Manual admin credit' : 'Manual admin deduction',
            null, true
        )) throw new RuntimeException('wallet audit insert failed');
        $conn->commit();
        return [
            'success' => true,
            'username' => (string) $user['username'],
            'new_balance' => $newBalance,
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Managed balance update failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to update balance'];
    }
}

function updateOwnAccount(int $userId, string $username, string $email, string $currentPassword, string $newPassword = '', string $confirmPassword = ''): array
{
    global $conn;
    require_once __DIR__ . '/account_recovery.php';
    if (!accountRecoveryEnsureSchema() || $userId < 1) {
        return ['success' => false, 'message' => Lang::t('common.error.user_not_found')];
    }
    $username = trim($username);
    $submittedEmail = strtolower(trim($email));
    $user = getUserById($userId);
    if (!$user || !password_verify($currentPassword, (string) $user['password'])) {
        return ['success' => false, 'message' => Lang::t('account.error.password')];
    }
    if (!validateManagedUsername($username)) {
        return ['success' => false, 'message' => Lang::t('account.error.username_format')];
    }
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 200) {
            return ['success' => false, 'message' => Lang::t('account.error.password_short')];
        }
        if (!hash_equals($newPassword, $confirmPassword)) {
            return ['success' => false, 'message' => Lang::t('account.error.password_mismatch')];
        }
    }

    $currentEmail = strtolower(trim((string) ($user['email'] ?? '')));
    $emailChanged = $submittedEmail !== $currentEmail;
    $email = $emailChanged ? accountRecoveryNormalizeGmail($submittedEmail) : (string) ($user['email'] ?? '');
    if ($emailChanged && $email === '') {
        return ['success' => false, 'message' => 'รองรับเฉพาะอีเมล @gmail.com เท่านั้น'];
    }
    if ($emailChanged && in_array((string) ($user['role'] ?? ''), ['user', 'reseller'], true)
        && (int) ($user['email_change_used'] ?? 0) === 1) {
        return ['success' => false, 'message' => 'คุณใช้สิทธิ์เปลี่ยนอีเมลด้วยตัวเองครบ 1 ครั้งแล้ว กรุณาติดต่อแอดมิน'];
    }

    $emailLookup = strtolower(trim($email));
    $check = $conn->prepare('SELECT id FROM users WHERE (username = ? OR LOWER(email) = ?) AND id <> ? LIMIT 1');
    if (!$check) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
    $check->bind_param('ssi', $username, $emailLookup, $userId);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        return ['success' => false, 'message' => Lang::t('account.error.email_used')];
    }
    $check->close();

    $hash = null;
    if ($newPassword !== '') {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hash === false) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
    }

    if ($newPassword !== '' && $emailChanged) {
        $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ?, password_changed_at = NOW(), email_change_used = 1, email_verified_at = NULL WHERE id = ? AND email_change_used = 0');
        if (!$stmt) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
        $stmt->bind_param('sssi', $username, $email, $hash, $userId);
    } elseif ($newPassword !== '') {
        $stmt = $conn->prepare('UPDATE users SET username = ?, password = ?, password_changed_at = NOW() WHERE id = ?');
        if (!$stmt) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
        $stmt->bind_param('ssi', $username, $hash, $userId);
    } elseif ($emailChanged) {
        $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, email_change_used = 1, email_verified_at = NULL WHERE id = ? AND email_change_used = 0');
        if (!$stmt) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
        $stmt->bind_param('ssi', $username, $email, $userId);
    } else {
        $stmt = $conn->prepare('UPDATE users SET username = ? WHERE id = ?');
        if (!$stmt) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
        $stmt->bind_param('si', $username, $userId);
    }
    $executed = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    if (!$executed) return ['success' => false, 'message' => Lang::t('account.error.update_failed')];
    if ($emailChanged && $affectedRows !== 1) {
        return ['success' => false, 'message' => 'สิทธิ์เปลี่ยนอีเมลด้วยตัวเองถูกใช้ไปแล้ว กรุณาติดต่อแอดมิน'];
    }

    if ($emailChanged) {
        $invalidate = $conn->prepare('UPDATE auth_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        if ($invalidate) {
            $invalidate->bind_param('i', $userId);
            $invalidate->execute();
            $invalidate->close();
        }
    }
    if ($newPassword !== '' && function_exists('revokeRememberTokensForUser')) {
        revokeRememberTokensForUser($userId);
        // Other sessions retain the old fingerprint and will be rejected, while
        // this verified session is allowed to continue without a confusing
        // logout immediately after the account page reports success.
        $freshUser = getUserById($userId);
        if ($freshUser && function_exists('authPasswordFingerprint')) {
            $_SESSION['auth_password_fingerprint'] = authPasswordFingerprint($freshUser);
        }
    }
    $_SESSION['username'] = $username;
    if (function_exists('regenerateSession')) regenerateSession();
    return ['success' => true, 'username' => $username, 'email_changed' => $emailChanged];
}

/**
 * Read-only structural probe for the shared audit-history table.
 *
 * The old readiness check only verified that an INSERT could be prepared. That
 * missed legacy tables whose `id` column existed but was neither PRIMARY KEY nor
 * AUTO_INCREMENT, so every row could remain id=0 until the first real INSERT
 * failed. Keep this probe read-only; the maintenance repair below owns all DDL.
 *
 * @return array<string,mixed>
 */
function localHistoryAuditSchemaStatus(bool $includeDataStats = false): array
{
    global $conn;
    $status = [
        'ready' => false,
        'table_exists' => false,
        'engine' => '',
        'columns_ready' => false,
        'insert_prepare_ready' => false,
        'id_exists' => false,
        'id_type' => '',
        'id_primary' => false,
        'id_auto_increment' => false,
        'id_unsigned' => false,
        'user_created_index_ready' => false,
        'failed_checks' => [],
    ];
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $status['failed_checks'][] = 'database';
        return $status;
    }

    try {
        $tableResult = $conn->query("SHOW TABLE STATUS LIKE 'history'");
        $tableRow = $tableResult ? $tableResult->fetch_assoc() : null;
        if ($tableResult instanceof mysqli_result) $tableResult->free();
        if (!$tableRow) {
            $status['failed_checks'][] = 'table_missing';
            return $status;
        }
        $status['table_exists'] = true;
        $status['engine'] = strtoupper(trim((string) ($tableRow['Engine'] ?? '')));
    } catch (Throwable $e) {
        $status['failed_checks'][] = 'table_probe';
        return $status;
    }

    $required = ['id','user_id','action','details','ip_address','user_agent','created_at'];
    $columns = [];
    try {
        $columnResult = $conn->query(
            "SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='history'"
        );
        if ($columnResult) {
            while ($row = $columnResult->fetch_assoc()) {
                $name = strtolower(trim((string) ($row['COLUMN_NAME'] ?? '')));
                if ($name !== '') $columns[$name] = $row;
            }
            $columnResult->free();
        }
    } catch (Throwable $e) {
        $status['failed_checks'][] = 'column_probe';
    }

    $missing = [];
    foreach ($required as $column) {
        if (!isset($columns[$column])) $missing[] = $column;
    }
    $status['columns_ready'] = $missing === [];
    if ($missing !== []) {
        $status['missing_columns'] = $missing;
        $status['failed_checks'][] = 'required_columns';
    }

    if (isset($columns['id'])) {
        $id = $columns['id'];
        $idType = strtolower(trim((string) ($id['COLUMN_TYPE'] ?? '')));
        $extra = strtolower(trim((string) ($id['EXTRA'] ?? '')));
        $status['id_exists'] = true;
        $status['id_type'] = $idType;
        $status['id_primary'] = strtoupper(trim((string) ($id['COLUMN_KEY'] ?? ''))) === 'PRI';
        $status['id_auto_increment'] = strpos($extra, 'auto_increment') !== false;
        $status['id_unsigned'] = strpos($idType, 'unsigned') !== false;
    } else {
        $status['failed_checks'][] = 'id_missing';
    }
    if (!$status['id_primary']) $status['failed_checks'][] = 'id_primary';
    if (!$status['id_auto_increment']) $status['failed_checks'][] = 'id_auto_increment';

    try {
        $indexResult = $conn->query('SHOW INDEX FROM `history`');
        $indexes = [];
        if ($indexResult) {
            while ($row = $indexResult->fetch_assoc()) {
                $name = (string) ($row['Key_name'] ?? '');
                $seq = (int) ($row['Seq_in_index'] ?? 0);
                $column = strtolower(trim((string) ($row['Column_name'] ?? '')));
                if ($name === '' || $seq < 1 || $column === '') continue;
                $indexes[$name][$seq] = $column;
            }
            $indexResult->free();
        }
        foreach ($indexes as &$indexColumns) {
            ksort($indexColumns);
            $indexColumns = array_values($indexColumns);
        }
        unset($indexColumns);
        foreach ($indexes as $indexColumns) {
            if (array_slice($indexColumns, 0, 2) === ['user_id','created_at']) {
                $status['user_created_index_ready'] = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $status['failed_checks'][] = 'index_probe';
    }

    try {
        $stmt = $conn->prepare('INSERT INTO history (user_id,action,details,ip_address,user_agent) VALUES (?,?,?,?,?)');
        $status['insert_prepare_ready'] = $stmt instanceof mysqli_stmt;
        if ($stmt instanceof mysqli_stmt) $stmt->close();
    } catch (Throwable $e) {
        $status['insert_prepare_ready'] = false;
    }
    if (!$status['insert_prepare_ready']) $status['failed_checks'][] = 'insert_prepare';

    if ($includeDataStats && $status['id_exists']) {
        try {
            $statsResult = $conn->query(
                'SELECT COUNT(*) AS total_rows, COUNT(DISTINCT id) AS distinct_ids, '
                . 'SUM(id IS NULL) AS null_ids, SUM(id=0) AS zero_ids, '
                . 'SUM(id<>0) AS nonzero_rows, COUNT(DISTINCT CASE WHEN id<>0 THEN id END) AS distinct_nonzero_ids, '
                . 'SUM(id<0) AS negative_ids, MIN(id) AS min_id, MAX(id) AS max_id '
                . 'FROM `history`'
            );
            $stats = $statsResult ? $statsResult->fetch_assoc() : null;
            if ($statsResult instanceof mysqli_result) $statsResult->free();
            if ($stats) {
                $status['data_stats'] = [
                    'total_rows' => (int) ($stats['total_rows'] ?? 0),
                    'distinct_ids' => (int) ($stats['distinct_ids'] ?? 0),
                    'null_ids' => (int) ($stats['null_ids'] ?? 0),
                    'zero_ids' => (int) ($stats['zero_ids'] ?? 0),
                    'nonzero_rows' => (int) ($stats['nonzero_rows'] ?? 0),
                    'distinct_nonzero_ids' => (int) ($stats['distinct_nonzero_ids'] ?? 0),
                    'duplicate_nonzero_rows' => max(0, (int) ($stats['nonzero_rows'] ?? 0) - (int) ($stats['distinct_nonzero_ids'] ?? 0)),
                    'negative_ids' => (int) ($stats['negative_ids'] ?? 0),
                    'min_id' => $stats['min_id'] === null ? null : (int) $stats['min_id'],
                    'max_id' => $stats['max_id'] === null ? null : (int) $stats['max_id'],
                ];
            }
        } catch (Throwable $e) {
            $status['failed_checks'][] = 'data_stats';
        }
    }

    $status['failed_checks'] = array_values(array_unique($status['failed_checks']));
    $status['ready'] = $status['table_exists']
        && $status['columns_ready']
        && $status['insert_prepare_ready']
        && $status['id_exists']
        && $status['id_primary']
        && $status['id_auto_increment'];
    return $status;
}

/**
 * Maintenance-only self-heal for legacy `history.id` schemas.
 *
 * Safe automatic repair is intentionally narrow:
 * - no external FK may reference history;
 * - an unrelated PRIMARY/AUTO_INCREMENT column blocks the migration;
 * - duplicate IDs are auto-renumbered only when duplicates are exclusively the legacy id=0 sentinel;
 * - a full backup table is created before any destructive identity rewrite;
 * - a real INSERT+DELETE probe proves execution, not just prepare(), works.
 *
 * @return array<string,mixed>
 */
function localHistoryAuditSchemaRepair(): array
{
    global $conn;
    $started = microtime(true);
    $result = [
        'success' => false,
        'changed' => false,
        'skipped' => false,
        'busy' => false,
        'backup_table' => '',
        'steps' => [],
        'duration_ms' => 0,
    ];
    $addStep = static function (string $name, string $status, string $message = '', array $meta = []) use (&$result): void {
        $entry = ['step' => $name, 'status' => $status];
        if ($message !== '') $entry['message'] = substr($message, 0, 500);
        foreach ($meta as $key => $value) {
            if (is_scalar($value) || $value === null || is_array($value)) $entry[$key] = $value;
        }
        $result['steps'][] = $entry;

        // Maintenance runs infrequently, so emit each safe structural step to
        // the PHP error log as well as JSON. No customer details, SQL payloads,
        // credentials, gift codes, or provider bodies are included here.
        $logMeta = '';
        if ($meta !== []) {
            $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($encoded) && $encoded !== '') $logMeta = '; meta=' . substr($encoded, 0, 1400);
        }
        error_log('[maintenance][history_schema][' . preg_replace('/[^a-z0-9_\-]/i', '_', $name) . ']'
            . ' status=' . preg_replace('/[^a-z0-9_\-]/i', '_', $status)
            . ($message !== '' ? '; message=' . substr(str_replace(["\r", "\n"], ' ', $message), 0, 500) : '')
            . $logMeta);
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

    $databaseName = defined('DB_NAME') ? (string) DB_NAME : 'default';
    $lockName = 'schema:history:' . substr(hash('sha256', $databaseName), 0, 20);
    $lock = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
    if (!$lock) {
        $result['message'] = 'History schema lock unavailable';
        $addStep('lock', 'failed', 'Unable to prepare schema lock');
        return $finish();
    }
    $lock->bind_param('s', $lockName);
    $lock->execute();
    $lockResult = $lock->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lock->close();
    if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
        $result['success'] = true;
        $result['skipped'] = true;
        $result['busy'] = true;
        $result['message'] = 'History schema maintenance is already running';
        $addStep('lock', 'busy', 'Another maintenance process owns the schema lock');
        return $finish();
    }
    $addStep('lock', 'ok', 'Exclusive history schema lock acquired');

    try {
        $before = localHistoryAuditSchemaStatus(true);
        $result['before'] = $before;
        $addStep('inspect_before', !empty($before['ready']) ? 'ok' : 'needs_repair', 'History schema inspected', [
            'failed_checks' => (array) ($before['failed_checks'] ?? []),
            'data_stats' => (array) ($before['data_stats'] ?? []),
        ]);

        if (!empty($before['ready']) && !empty($before['user_created_index_ready'])) {
            $result['success'] = true;
            $result['skipped'] = true;
            $result['message'] = 'History audit schema already ready';
            return $finish();
        }

        // Identity correctness and read-path performance are deliberately
        // separate checks. A manually repaired/older site can have a perfectly
        // safe PRIMARY KEY + AUTO_INCREMENT while still missing the optional
        // (user_id, created_at) index. Maintenance may add that index without
        // rebuilding IDs or touching row values.
        if (!empty($before['ready']) && empty($before['user_created_index_ready'])) {
            if (!$conn->query('ALTER TABLE `history` ADD INDEX `idx_history_user_created` (`user_id`,`created_at`)')) {
                // A concurrent maintenance run may have added the same index
                // between the read-only probe and this DDL. Re-probe before
                // treating a duplicate-index race as a real failure.
                $raceCheck = localHistoryAuditSchemaStatus(false);
                if (empty($raceCheck['user_created_index_ready'])) {
                    throw new RuntimeException('history_user_created_index_failed: ' . $conn->error);
                }
            } else {
                $result['changed'] = true;
            }
            $addStep('history_index', 'ok', 'Added user/created_at history index to an otherwise healthy table');
        }

        if (empty($before['table_exists'])) {
            $createSql = "CREATE TABLE `history` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL DEFAULT '',
                details TEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(1000) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_history_user_created (user_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($createSql)) {
                throw new RuntimeException('create_history_failed: ' . $conn->error);
            }
            $result['changed'] = true;
            $addStep('create_table', 'ok', 'Created missing history table');
        } else {
            // First make missing non-identity columns additive. These never
            // rewrite existing values and are safe on legacy installations.
            $definitions = [
                'user_id' => 'BIGINT UNSIGNED NULL',
                'action' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'details' => 'TEXT NULL',
                'ip_address' => "VARCHAR(45) NOT NULL DEFAULT ''",
                'user_agent' => "VARCHAR(1000) NOT NULL DEFAULT ''",
                'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            ];
            $present = [];
            $columnResult = $conn->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='history'"
            );
            if ($columnResult) {
                while ($row = $columnResult->fetch_assoc()) {
                    $name = strtolower(trim((string) ($row['COLUMN_NAME'] ?? '')));
                    if ($name !== '') $present[$name] = true;
                }
                $columnResult->free();
            }
            foreach ($definitions as $column => $definition) {
                if (!empty($present[$column])) continue;
                if (!$conn->query("ALTER TABLE `history` ADD COLUMN `{$column}` {$definition}")) {
                    throw new RuntimeException('add_history_column_failed:' . $column . ': ' . $conn->error);
                }
                $result['changed'] = true;
                $addStep('add_column_' . $column, 'ok', 'Added missing history column');
            }

            $identity = localHistoryAuditSchemaStatus(true);
            if (empty($identity['id_primary']) || empty($identity['id_auto_increment'])) {
                $fkCount = 0;
                $fkResult = $conn->query(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE "
                    . "WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='history'"
                );
                if ($fkResult) {
                    $fkRow = $fkResult->fetch_assoc();
                    $fkCount = (int) ($fkRow['c'] ?? 0);
                    $fkResult->free();
                }
                if ($fkCount > 0) {
                    $result['blocked'] = true;
                    $result['message'] = 'History identity repair blocked by foreign-key references';
                    $addStep('foreign_keys', 'blocked', 'Foreign-key references exist', ['count' => $fkCount]);
                    error_log('[maintenance][history_schema] repair blocked: foreign-key references=' . $fkCount);
                    return $finish();
                }
                $addStep('foreign_keys', 'ok', 'No external foreign-key references');

                $primaryColumns = [];
                $otherAutoIncrement = [];
                $indexResult = $conn->query("SHOW INDEX FROM `history` WHERE Key_name='PRIMARY'");
                if ($indexResult) {
                    while ($row = $indexResult->fetch_assoc()) {
                        $seq = (int) ($row['Seq_in_index'] ?? 0);
                        $column = strtolower(trim((string) ($row['Column_name'] ?? '')));
                        if ($seq > 0 && $column !== '') $primaryColumns[$seq] = $column;
                    }
                    $indexResult->free();
                    ksort($primaryColumns);
                    $primaryColumns = array_values($primaryColumns);
                }
                $autoResult = $conn->query(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
                    . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='history' AND EXTRA LIKE '%auto_increment%'"
                );
                if ($autoResult) {
                    while ($row = $autoResult->fetch_assoc()) {
                        $column = strtolower(trim((string) ($row['COLUMN_NAME'] ?? '')));
                        if ($column !== '' && $column !== 'id') $otherAutoIncrement[] = $column;
                    }
                    $autoResult->free();
                }
                $knownTempId = '_sakazuki_history_id_v2';
                $partialIdentitySwap = $primaryColumns === [$knownTempId]
                    && $otherAutoIncrement === [$knownTempId];
                if ((($primaryColumns !== [] && $primaryColumns !== ['id']) || $otherAutoIncrement !== [])
                    && !$partialIdentitySwap) {
                    $result['blocked'] = true;
                    $result['message'] = 'History identity repair found an unexpected primary/auto-increment layout';
                    $addStep('identity_layout', 'blocked', 'Unexpected identity layout', [
                        'primary_columns' => $primaryColumns,
                        'other_auto_increment' => $otherAutoIncrement,
                    ]);
                    error_log('[maintenance][history_schema] repair blocked: unexpected identity layout');
                    return $finish();
                }

                $stats = (array) ($identity['data_stats'] ?? []);
                $total = (int) ($stats['total_rows'] ?? 0);
                $distinct = (int) ($stats['distinct_ids'] ?? 0);
                $nullIds = (int) ($stats['null_ids'] ?? 0);
                $zeroIds = (int) ($stats['zero_ids'] ?? 0);
                $nonzeroRows = (int) ($stats['nonzero_rows'] ?? max(0, $total - $zeroIds));
                $distinctNonzeroIds = (int) ($stats['distinct_nonzero_ids'] ?? max(0, $distinct - ($zeroIds > 0 ? 1 : 0)));
                $duplicateNonzeroRows = max(0, $nonzeroRows - $distinctNonzeroIds);
                $negativeIds = (int) ($stats['negative_ids'] ?? 0);
                $minId = $stats['min_id'] ?? null;
                if (empty($identity['id_exists'])) {
                    $countResult = $conn->query('SELECT COUNT(*) AS c FROM `history`');
                    if ($countResult) {
                        $countRow = $countResult->fetch_assoc();
                        $total = (int) ($countRow['c'] ?? 0);
                        $countResult->free();
                    }
                }

                $backupTable = 'history_backup_identity_v3';
                $result['backup_table'] = $backupTable;
                if (!$conn->query("CREATE TABLE IF NOT EXISTS `{$backupTable}` LIKE `history`")) {
                    throw new RuntimeException('history_backup_create_failed: ' . $conn->error);
                }
                $backupCount = 0;
                $backupCountResult = $conn->query("SELECT COUNT(*) AS c FROM `{$backupTable}`");
                if ($backupCountResult) {
                    $backupRow = $backupCountResult->fetch_assoc();
                    $backupCount = (int) ($backupRow['c'] ?? 0);
                    $backupCountResult->free();
                }
                if ($backupCount === 0 && $total > 0) {
                    if (!$conn->query("INSERT INTO `{$backupTable}` SELECT * FROM `history`")) {
                        throw new RuntimeException('history_backup_copy_failed: ' . $conn->error);
                    }
                    $backupCount = (int) $conn->affected_rows;
                }
                if ($total > 0 && $backupCount !== $total) {
                    $result['blocked'] = true;
                    $result['message'] = 'History backup row count does not match source';
                    $addStep('backup', 'blocked', 'Backup row count mismatch', [
                        'source_rows' => $total,
                        'backup_rows' => $backupCount,
                    ]);
                    error_log('[maintenance][history_schema] repair blocked: backup row mismatch source=' . $total . '; backup=' . $backupCount);
                    return $finish();
                }
                $addStep('backup', 'ok', 'History backup verified', [
                    'table' => $backupTable,
                    'rows' => $backupCount,
                ]);

                if (empty($identity['id_exists'])) {
                    if (!$conn->query('ALTER TABLE `history` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST')) {
                        throw new RuntimeException('history_id_add_failed: ' . $conn->error);
                    }
                    $result['changed'] = true;
                    $addStep('identity_repair', 'ok', 'Added missing AUTO_INCREMENT primary id');
                } elseif ($primaryColumns === ['id'] && !$identity['id_auto_increment']) {
                    // Existing primary IDs remain stable; only add AUTO_INCREMENT.
                    if ($nullIds !== 0 || ($total > 0 && ($minId === null || (int) $minId < 1))) {
                        $result['blocked'] = true;
                        $result['message'] = 'Existing primary history IDs are not safe for AUTO_INCREMENT';
                        $addStep('identity_repair', 'blocked', 'Existing primary IDs contain null/zero values');
                        return $finish();
                    }
                    if (!$conn->query('ALTER TABLE `history` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')) {
                        throw new RuntimeException('history_id_auto_increment_failed: ' . $conn->error);
                    }
                    $result['changed'] = true;
                    $addStep('identity_repair', 'ok', 'Enabled AUTO_INCREMENT on existing primary id');
                } elseif ($primaryColumns === [] && $total === $distinct && $nullIds === 0 && ($total === 0 || (int) $minId >= 1)) {
                    // Preserve already-unique IDs so any non-FK historic references remain valid.
                    if (!$conn->query('ALTER TABLE `history` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL, ADD PRIMARY KEY (`id`)')) {
                        throw new RuntimeException('history_id_primary_failed: ' . $conn->error);
                    }
                    if (!$conn->query('ALTER TABLE `history` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')) {
                        throw new RuntimeException('history_id_auto_increment_failed: ' . $conn->error);
                    }
                    $result['changed'] = true;
                    $addStep('identity_repair', 'ok', 'Preserved unique IDs and enabled PRIMARY KEY AUTO_INCREMENT');
                } elseif (($primaryColumns === [] || $partialIdentitySwap)
                    && $total > 0
                    && $nullIds === 0
                    && $zeroIds > 0
                    && $negativeIds === 0
                    && $duplicateNonzeroRows === 0
                    && ($minId === null || (int) $minId === 0)) {
                    // Known legacy family: duplicate IDs are exclusively the id=0
                    // sentinel. This includes both an all-zero table and mixed
                    // tables where older valid positive IDs are unique but later
                    // rows collapsed to zero. No FK points at history and a full
                    // backup exists, so rebuild the audit-only identity column.
                    $tempId = $knownTempId;
                    $tempExists = false;
                    $tempProbe = $conn->query(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS "
                        . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='history' AND COLUMN_NAME='{$tempId}' LIMIT 1"
                    );
                    if ($tempProbe) {
                        $tempExists = $tempProbe->num_rows > 0;
                        $tempProbe->free();
                    }
                    if (!$tempExists) {
                        if (!$conn->query("ALTER TABLE `history` ADD COLUMN `{$tempId}` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST")) {
                            throw new RuntimeException('history_temp_id_add_failed: ' . $conn->error);
                        }
                    }
                    $tempStatsResult = $conn->query(
                        "SELECT COUNT(*) AS total_rows,COUNT(DISTINCT `{$tempId}`) AS distinct_ids,MIN(`{$tempId}`) AS min_id "
                        . "FROM `history`"
                    );
                    $tempStats = $tempStatsResult ? $tempStatsResult->fetch_assoc() : null;
                    if ($tempStatsResult instanceof mysqli_result) $tempStatsResult->free();
                    if (!$tempStats
                        || (int) ($tempStats['total_rows'] ?? -1) !== $total
                        || (int) ($tempStats['distinct_ids'] ?? -1) !== $total
                        || ($total > 0 && (int) ($tempStats['min_id'] ?? 0) < 1)) {
                        throw new RuntimeException('history_temp_id_validation_failed');
                    }
                    if (!$conn->query(
                        "ALTER TABLE `history` DROP COLUMN `id`, "
                        . "CHANGE COLUMN `{$tempId}` `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST"
                    )) {
                        throw new RuntimeException('history_identity_swap_failed: ' . $conn->error);
                    }
                    $result['changed'] = true;
                    $addStep('identity_repair', 'ok', 'Rebuilt legacy history IDs with duplicate-zero sentinel', [
                        'rows_renumbered' => $total,
                        'zero_ids_repaired' => $zeroIds,
                        'positive_ids_seen' => $nonzeroRows,
                        'duplicate_nonzero_rows' => $duplicateNonzeroRows,
                        'backup_table' => $backupTable,
                    ]);
                } elseif ($primaryColumns === [] && $total === 0) {
                    if (!$conn->query('ALTER TABLE `history` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL, ADD PRIMARY KEY (`id`)')) {
                        throw new RuntimeException('history_empty_primary_failed: ' . $conn->error);
                    }
                    if (!$conn->query('ALTER TABLE `history` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')) {
                        throw new RuntimeException('history_empty_auto_increment_failed: ' . $conn->error);
                    }
                    $result['changed'] = true;
                    $addStep('identity_repair', 'ok', 'Prepared identity on empty history table');
                } else {
                    $result['blocked'] = true;
                    $result['message'] = 'History IDs are invalid but do not match a safe automatic repair pattern';
                    $addStep('identity_repair', 'blocked', 'Manual review required for unexpected duplicate IDs', [
                        'data_stats' => $stats,
                    ]);
                    error_log('[maintenance][history_schema] repair blocked: unexpected duplicate id pattern; total=' . $total
                        . '; distinct=' . $distinct . '; zero=' . $zeroIds . '; nonzero=' . $nonzeroRows
                        . '; distinct_nonzero=' . $distinctNonzeroIds . '; duplicate_nonzero=' . $duplicateNonzeroRows
                        . '; negative=' . $negativeIds);
                    return $finish();
                }
            }

            // Add the read-path index if no equivalent prefix already exists.
            $afterIdentity = localHistoryAuditSchemaStatus(false);
            if (!empty($afterIdentity['columns_ready']) && empty($afterIdentity['user_created_index_ready'])) {
                if (!$conn->query('ALTER TABLE `history` ADD INDEX `idx_history_user_created` (`user_id`,`created_at`)')) {
                    throw new RuntimeException('history_user_created_index_failed: ' . $conn->error);
                }
                $result['changed'] = true;
                $addStep('history_index', 'ok', 'Added user/created_at history index');
            }
        }

        $after = localHistoryAuditSchemaStatus(true);
        $result['after'] = $after;
        if (empty($after['ready'])) {
            $result['message'] = 'History schema remains unhealthy after maintenance';
            $addStep('inspect_after', 'failed', 'Post-repair validation failed', [
                'failed_checks' => (array) ($after['failed_checks'] ?? []),
            ]);
            error_log('[maintenance][history_schema] post-repair validation failed: ' . implode(',', (array) ($after['failed_checks'] ?? [])));
            return $finish();
        }
        $addStep('inspect_after', 'ok', 'Structural validation passed');

        // Prove a real execute() succeeds. Prepare-only checks are exactly what
        // hid the original id=0 production defect.
        $probeAction = 'maintenance_history_schema_probe';
        $probeDetails = 'Automatic history schema verification';
        $probeIp = '127.0.0.1';
        $probeAgent = 'Sakazuki-Maintenance/2';
        $probeUserId = null;
        $probe = $conn->prepare(
            'INSERT INTO history (user_id,action,details,ip_address,user_agent) VALUES (?,?,?,?,?)'
        );
        if (!$probe) {
            throw new RuntimeException('history_probe_prepare_failed: ' . $conn->error);
        }
        $probe->bind_param('issss', $probeUserId, $probeAction, $probeDetails, $probeIp, $probeAgent);
        $probeOk = $probe->execute();
        $probeErrno = (int) $probe->errno;
        $probeError = (string) $probe->error;
        $probeId = $probeOk ? (int) $conn->insert_id : 0;
        $probe->close();
        if (!$probeOk || $probeId < 1) {
            throw new RuntimeException('history_probe_execute_failed:' . $probeErrno . ': ' . $probeError);
        }
        $cleanup = $conn->prepare('DELETE FROM history WHERE id=? AND action=? LIMIT 1');
        $deleted = false;
        if ($cleanup) {
            $cleanup->bind_param('is', $probeId, $probeAction);
            $deleted = $cleanup->execute() && $cleanup->affected_rows === 1;
            $cleanup->close();
        }
        if (!$deleted) {
            error_log('[maintenance][history_schema] probe row cleanup failed; id=' . $probeId);
        }
        $result['probe_insert_id'] = $probeId;
        $result['probe_cleaned'] = $deleted;
        $addStep('runtime_insert_probe', 'ok', 'Real INSERT execution succeeded', [
            'insert_id' => $probeId,
            'cleaned' => $deleted,
        ]);

        $result['success'] = true;
        $result['message'] = $result['changed']
            ? 'History audit schema repaired and verified'
            : 'History audit schema verified';
        if ($result['changed']) {
            error_log('[maintenance][history_schema] repair completed successfully');
        }
        return $finish();
    } catch (Throwable $e) {
        $message = substr($e->getMessage(), 0, 700);
        $result['message'] = 'History schema maintenance failed';
        $result['error'] = $message;
        $addStep('exception', 'failed', $message);
        error_log('[maintenance][history_schema] failed: ' . $message);
        return $finish();
    } finally {
        try {
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            if ($release) {
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->close();
            }
        } catch (Throwable $ignored) {
        }
    }
}

/**
 * Read-only health probe for the local key checkout path.
 *
 * It does not buy a key or change a balance. The maintenance runner uses this
 * to distinguish a wallet-table problem from an older core checkout schema.
 * Returned details deliberately contain only table/check names, never SQL text,
 * credentials, key codes, usernames, or provider payloads.
 *
 * @return array<string,mixed>
 */
function localCheckoutRuntimeHealth(bool $refresh = false): array
{
    global $conn;
    $requiredColumns = [
        'users' => ['id','username','role','balance','status'],
        'keys' => ['id','key_code','product_id','variant_id','duration','status','price_user','price_reseller','assigned_to','purchased_by','sold_at'],
        'products' => ['id','name','status'],
        'product_variants' => ['id','status'],
        'transactions' => ['id','user_id','type','amount','status','description','reference_id'],
        'history' => ['id','user_id','action','details','ip_address','user_agent','created_at'],
    ];
    $columns = [];
    foreach ($requiredColumns as $table => $list) {
        $columns[$table] = function_exists('sakazukiTableColumnsReady')
            ? sakazukiTableColumnsReady($table, $list, $refresh)
            : false;
    }
    $walletReady = function_exists('walletLedgerRuntimeSchemaReady')
        ? walletLedgerRuntimeSchemaReady($refresh)
        : (function_exists('ensureWalletLedgerSchema') ? ensureWalletLedgerSchema() : false);

    $prepareChecks = [
        'user_lock' => 'SELECT id,username,role,balance,status FROM users WHERE id=? LIMIT 1 FOR UPDATE',
        'key_lock' => "SELECT k.id,k.key_code,k.variant_id,k.price_user,k.price_reseller,p.name AS product_name FROM `keys` k JOIN products p ON p.id=k.product_id AND p.status='active' LEFT JOIN product_variants pv ON pv.id=k.variant_id WHERE k.product_id=? AND k.duration=? AND k.status='available' AND (k.variant_id IS NULL OR pv.status='active') ORDER BY k.id ASC LIMIT 1 FOR UPDATE",
        'balance_debit' => 'UPDATE users SET balance=balance-? WHERE id=? AND balance>=?',
        'key_sell' => "UPDATE `keys` SET status='sold',assigned_to=?,purchased_by=?,sold_at=NOW() WHERE id=? AND status='available'",
        'transaction_insert' => 'INSERT INTO transactions (user_id,type,amount,status,description,reference_id) VALUES (?,?,?,?,?,?)',
        'history_insert' => 'INSERT INTO history (user_id,action,details,ip_address,user_agent) VALUES (?,?,?,?,?)',
    ];
    $prepared = [];
    if (isset($conn) && $conn instanceof mysqli) {
        foreach ($prepareChecks as $name => $sql) {
            try {
                $stmt = $conn->prepare($sql);
                $prepared[$name] = $stmt instanceof mysqli_stmt;
                if ($stmt instanceof mysqli_stmt) $stmt->close();
            } catch (Throwable $e) {
                $prepared[$name] = false;
            }
        }
    } else {
        foreach ($prepareChecks as $name => $_sql) $prepared[$name] = false;
    }

    $engines = [];
    if (isset($conn) && $conn instanceof mysqli) {
        foreach (['users','keys','transactions','wallet_balance_ledger'] as $table) {
            try {
                $escaped = $conn->real_escape_string($table);
                $result = $conn->query("SHOW TABLE STATUS LIKE '{$escaped}'");
                $row = $result ? $result->fetch_assoc() : null;
                if ($result instanceof mysqli_result) $result->free();
                $engines[$table] = strtoupper(trim((string) ($row['Engine'] ?? '')));
            } catch (Throwable $e) {
                $engines[$table] = '';
            }
        }
    }
    $nonTransactional = [];
    foreach ($engines as $table => $engine) {
        if ($engine !== '' && $engine !== 'INNODB') $nonTransactional[] = $table;
    }

    $corePrepareNames = ['user_lock','key_lock','balance_debit','key_sell','transaction_insert'];
    $failedCorePrepares = array_values(array_filter($corePrepareNames, static fn(string $name): bool => empty($prepared[$name])));
    $coreColumnNames = ['users','keys','products','product_variants','transactions'];
    $failedCoreColumns = array_values(array_filter($coreColumnNames, static fn(string $table): bool => empty($columns[$table])));
    $historyIdentity = function_exists('localHistoryAuditSchemaStatus')
        ? localHistoryAuditSchemaStatus(false)
        : ['ready' => !empty($columns['history']) && !empty($prepared['history_insert'])];
    $historyReady = !empty($columns['history'])
        && !empty($prepared['history_insert'])
        && !empty($historyIdentity['ready']);
    $typeInfo = function_exists('transactionIntegrityTypeColumnInfo') ? transactionIntegrityTypeColumnInfo($refresh) : [];
    $purchaseTypeSupported = function_exists('transactionIntegrityTypeColumnSupports')
        ? transactionIntegrityTypeColumnSupports('purchase', $typeInfo)
        : null;

    $purchaseTypeReady = $purchaseTypeSupported === true;
    $coreReady = $failedCoreColumns === [] && $failedCorePrepares === [] && $walletReady && $purchaseTypeReady;
    return [
        'success' => $coreReady,
        'core_ready' => $coreReady,
        'wallet_ready' => $walletReady,
        'history_ready' => $historyReady,
        'history_identity' => [
            'id_exists' => !empty($historyIdentity['id_exists']),
            'id_primary' => !empty($historyIdentity['id_primary']),
            'id_auto_increment' => !empty($historyIdentity['id_auto_increment']),
            'id_type' => (string) ($historyIdentity['id_type'] ?? ''),
            'id_unsigned' => !empty($historyIdentity['id_unsigned']),
            'insert_prepare_ready' => !empty($historyIdentity['insert_prepare_ready']),
            'user_created_index_ready' => !empty($historyIdentity['user_created_index_ready']),
        ],
        'history_failed_checks' => array_values((array) ($historyIdentity['failed_checks'] ?? [])),
        'purchase_type_supported' => $purchaseTypeSupported,
        'failed_column_checks' => $failedCoreColumns,
        'failed_prepare_checks' => $failedCorePrepares,
        'non_transactional_tables' => $nonTransactional,
    ];
}

/**
 * Additive emergency migration for columns used by the local checkout path.
 * This is maintenance/CLI only. It never drops columns, rewrites balances,
 * deletes orders, or alters existing values.
 */
function localCheckoutEnsureAdditiveSchema(): bool
{
    global $conn;
    if (!function_exists('sakazukiSchemaMigrationsAllowed') || !sakazukiSchemaMigrationsAllowed()) {
        $health = localCheckoutRuntimeHealth(true);
        return !empty($health['core_ready']);
    }
    if (!isset($conn) || !($conn instanceof mysqli)) return false;

    $columnExists = static function (string $table, string $column) use ($conn): bool {
        try {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            if (!$stmt) return false;
            $stmt->bind_param('ss', $table, $column);
            if (!$stmt->execute()) { $stmt->close(); return false; }
            $stmt->bind_result($count);
            $found = $stmt->fetch();
            $stmt->close();
            return $found && (int) $count > 0;
        } catch (Throwable $e) {
            return false;
        }
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($conn, $columnExists): bool {
        if ($columnExists($table, $column)) return true;
        if (preg_match('/^[a-z0-9_]+$/iD', $table) !== 1 || preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        try {
            if ($conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}")) return true;
        } catch (Throwable $e) {
            error_log('Local checkout column migration failed [' . $table . '.' . $column . ']: ' . $e->getMessage());
        }
        return $columnExists($table, $column);
    };

    // These ownership fields are additive and are written by every local-key sale.
    foreach ([
        ['keys','assigned_to','BIGINT UNSIGNED NULL'],
        ['keys','purchased_by','BIGINT UNSIGNED NULL'],
        ['keys','sold_at','DATETIME NULL'],
        ['transactions','reference_id','BIGINT UNSIGNED NULL'],
    ] as [$table,$column,$definition]) {
        if (!$addColumn($table, $column, $definition)) return false;
    }

    // Older installations can still have transactions.type as an ENUM that does
    // not accept "purchase". On a strict MySQL/MariaDB server the INSERT fails
    // before post-insert verification can help. The existing integrity helper
    // performs a schema-only ENUM -> VARCHAR(50) migration and preserves rows.
    if (function_exists('transactionIntegrityTypeColumnInfo')
        && function_exists('transactionIntegrityTypeColumnReady')
        && function_exists('transactionIntegrityAlterTypeColumn')) {
        $typeInfo = transactionIntegrityTypeColumnInfo(true);
        if (!transactionIntegrityTypeColumnReady($typeInfo)) {
            $purchaseWasSupported = function_exists('transactionIntegrityTypeColumnSupports')
                ? transactionIntegrityTypeColumnSupports('purchase', $typeInfo)
                : false;
            try {
                transactionIntegrityAlterTypeColumn();
            } catch (Throwable $e) {
                error_log('Local checkout transaction type migration failed: ' . $e->getMessage());
                // If the old type cannot even store a local purchase, continuing
                // would only reproduce the checkout failure we are repairing.
                if (!$purchaseWasSupported) return false;
            }
        }
    }

    // History is secondary audit data. Keep it repairable, but checkout no longer
    // rolls back a completed sale merely because this table is unhealthy.
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL DEFAULT '',
            details TEXT NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(1000) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_history_user_created (user_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('History table maintenance failed: ' . $e->getMessage());
    }
    foreach ([
        ['history','user_id','BIGINT UNSIGNED NULL'],
        ['history','action',"VARCHAR(100) NOT NULL DEFAULT ''"],
        ['history','details','TEXT NULL'],
        ['history','ip_address',"VARCHAR(45) NOT NULL DEFAULT ''"],
        ['history','user_agent',"VARCHAR(1000) NOT NULL DEFAULT ''"],
    ] as [$table,$column,$definition]) {
        $addColumn($table, $column, $definition);
    }

    $health = localCheckoutRuntimeHealth(true);
    return !empty($health['core_ready']);
}

/**
 * Purchase multiple keys as one atomic operation. Either every requested key is
 * assigned and the full balance is deducted, or nothing changes.
 */
function purchaseKeysAtomic(int $productId, string $duration, int $quantity, int $userId, int $variantId = 0): array
{
    global $conn;
    $stage = 'validate_request';
    $committed = false;
    $duration = trim($duration);
    if ($productId < 1 || $userId < 1 || $variantId < 0 || $duration === '' || strlen($duration) > 100 || $quantity < 1 || $quantity > 100) {
        return ['success' => false, 'message' => 'Invalid purchase request'];
    }
    if (!ensureWalletLedgerSchema()) {
        return ['success' => false, 'message' => 'Financial audit storage is unavailable'];
    }

    // Prepare the optional public activity table before opening the sales
    // transaction. A failure only disables precise activity logging; it never
    // blocks the purchase itself.
    $stage = 'activity_prepare';
    $purchaseActivityEventsReady = purchaseActivityEnsureEventTable();

    $stage = 'transaction_begin';
    $conn->begin_transaction();
    try {
        $stage = 'user_lock';
        $userStmt = $conn->prepare("SELECT id, username, role, balance, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        if (!$userStmt) throw new RuntimeException('prepare user lock failed');
        $userStmt->bind_param('i', $userId);
        if (!$userStmt->execute()) {
            $userStmt->close();
            throw new RuntimeException('user lock failed');
        }
        $userResult = $userStmt->get_result();
        $user = $userResult ? $userResult->fetch_assoc() : null;
        $userStmt->close();
        if (!$user || (string) $user['status'] !== 'active' || !in_array((string) $user['role'], ['user', 'reseller', 'admin'], true)) {
            throw new RuntimeException('User account is not active');
        }

        $stage = 'key_lock';
        $variantFilter = $variantId > 0 ? ' AND (k.variant_id = ? OR k.variant_id IS NULL)' : '';
        $keySql = "SELECT k.id, k.key_code, k.variant_id, k.price_user, k.price_reseller, p.name AS product_name
                   FROM `keys` k
                   JOIN products p ON p.id = k.product_id AND p.status = 'active'
                   LEFT JOIN product_variants pv ON pv.id = k.variant_id
                   WHERE k.product_id = ? AND k.duration = ? AND k.status = 'available'"
                   . $variantFilter .
                   " AND (k.variant_id IS NULL OR pv.status = 'active')
                   ORDER BY k.id ASC
                   LIMIT " . (int) $quantity . " FOR UPDATE";
        $keyStmt = $conn->prepare($keySql);
        if (!$keyStmt) throw new RuntimeException('prepare key lock failed');
        if ($variantId > 0) {
            $keyStmt->bind_param('isi', $productId, $duration, $variantId);
        } else {
            $keyStmt->bind_param('is', $productId, $duration);
        }
        if (!$keyStmt->execute()) {
            $keyStmt->close();
            throw new RuntimeException('key lock failed');
        }
        $keyResult = $keyStmt->get_result();
        $keys = $keyResult ? $keyResult->fetch_all(MYSQLI_ASSOC) : [];
        $keyStmt->close();
        if (count($keys) !== $quantity) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Not enough available keys'];
        }

        $stage = 'pricing';
        $total = 0.0;
        foreach ($keys as &$key) {
            $price = (string) $user['role'] === 'reseller' ? (float) $key['price_reseller'] : (float) $key['price_user'];
            // Apply the account-specific variant override without changing role.
            if ((int) ($key['variant_id'] ?? 0) > 0) {
                $price = getEffectiveResellerPrice($userId, (int) $key['variant_id'], $price);
            }
            $price = round((float) $price, 2);
            if (!is_finite($price) || $price < 0 || $price > 10000000) {
                throw new RuntimeException('Invalid key price');
            }
            $key['_effective_price'] = $price;
            $total = round($total + $price, 2);
        }
        unset($key);
        if ((float) $user['balance'] + 0.00001 < $total) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Insufficient balance'];
        }

        $stage = 'balance_debit';
        $debit = $conn->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
        if (!$debit) throw new RuntimeException('prepare debit failed');
        $debit->bind_param('did', $total, $userId, $total);
        if (!$debit->execute() || $debit->affected_rows !== 1) {
            $debit->close();
            throw new RuntimeException('Insufficient balance or update conflict');
        }
        $debit->close();

        $stage = 'key_assignment';
        $sell = $conn->prepare("UPDATE `keys` SET status = 'sold', assigned_to = ?, purchased_by = ?, sold_at = NOW() WHERE id = ? AND status = 'available'");
        if (!$sell) throw new RuntimeException('prepare key assignment failed');
        // Reuse one prepared transaction statement for the whole quantity. The
        // previous helper prepared the same INSERT once per key, which added
        // avoidable database round-trips while providing no extra protection.
        $stage = 'transaction_prepare';
        $transactionStmt = $conn->prepare(
            'INSERT INTO transactions (user_id, type, amount, status, description, reference_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$transactionStmt) {
            $sell->close();
            throw new RuntimeException('prepare purchase transaction failed');
        }
        $codes = [];
        $firstPurchaseTransactionId = 0;
        $lastPurchaseTransactionId = 0;
        foreach ($keys as $key) {
            $keyId = (int) $key['id'];
            $sell->bind_param('iii', $userId, $userId, $keyId);
            if (!$sell->execute() || $sell->affected_rows !== 1) {
                $transactionStmt->close();
                $sell->close();
                throw new RuntimeException('A key was taken by another customer');
            }
            $description = 'Purchased ' . (string) $key['product_name'] . ' key: ' . (string) $key['key_code'];
            if (strlen($description) > 5000) {
                $transactionStmt->close();
                $sell->close();
                throw new RuntimeException('Purchase transaction description is too long');
            }
            $transactionType = 'purchase';
            $transactionAmount = (float) $key['_effective_price'];
            $transactionStatus = 'completed';
            $transactionStmt->bind_param(
                'isdssi',
                $userId,
                $transactionType,
                $transactionAmount,
                $transactionStatus,
                $description,
                $keyId
            );
            $stage = 'transaction_insert';
            if (!$transactionStmt->execute() || (int) $conn->insert_id < 1) {
                $transactionStmt->close();
                $sell->close();
                throw new RuntimeException('Failed to create purchase transaction');
            }
            $createdTransactionId = (int) $conn->insert_id;
            $stage = 'transaction_verify';
            if (!transactionIntegrityVerifyInsertedRow(
                $createdTransactionId,
                $userId,
                $transactionType,
                $transactionAmount,
                $transactionStatus,
                $keyId
            )) {
                $transactionStmt->close();
                $sell->close();
                throw new RuntimeException('Purchase transaction verification failed');
            }
            if ($firstPurchaseTransactionId < 1) $firstPurchaseTransactionId = $createdTransactionId;
            $lastPurchaseTransactionId = $createdTransactionId;
            $codes[] = (string) $key['key_code'];
        }
        $sell->close();
        $transactionStmt->close();

        if ($firstPurchaseTransactionId < 1 || $lastPurchaseTransactionId < 1) {
            throw new RuntimeException('Purchase transaction range is unavailable');
        }
        $walletBefore = round((float) $user['balance'], 2);
        $walletAfter = round($walletBefore - $total, 2);
        $walletEventKey = $firstPurchaseTransactionId === $lastPurchaseTransactionId
            ? 'transaction:' . $firstPurchaseTransactionId
            : 'local_purchase_group:' . $firstPurchaseTransactionId . ':' . $lastPurchaseTransactionId;
        $stage = 'wallet_ledger';
        if (!walletLedgerRecordMovement(
            $userId, -$total, $walletBefore, $walletAfter,
            'local_purchase', $walletEventKey,
            $productId, $firstPurchaseTransactionId, null,
            'ซื้อสินค้า ' . (string) ($keys[0]['product_name'] ?? '') . ' จำนวน ' . count($codes) . ' รายการ',
            'Atomic local-key checkout; transaction range #' . $firstPurchaseTransactionId . '-#' . $lastPurchaseTransactionId,
            null, true
        )) throw new RuntimeException('Failed to save wallet purchase audit');

        $stage = 'activity_log';
        if ($purchaseActivityEventsReady && $firstPurchaseTransactionId > 0) {
            $activityProductName = function_exists('commerceComposeProductLabel')
                ? commerceComposeProductLabel((string) ($keys[0]['product_name'] ?? ''), $duration)
                : trim((string) ($keys[0]['product_name'] ?? '') . ' - ' . $duration);
            if (!purchaseActivityRecordLocalOrder(
                $userId,
                $firstPurchaseTransactionId,
                $lastPurchaseTransactionId,
                $productId,
                $activityProductName,
                count($codes),
                $total
            )) {
                error_log('Local purchase completed without a precise public activity event');
            }
        }

        $stage = 'commit';
        if (!$conn->commit()) {
            throw new RuntimeException('Failed to commit local purchase');
        }
        $committed = true;

        // History is secondary audit data. A history-table problem must not
        // roll back a sale whose balance, key ownership, transaction and wallet
        // ledger have already committed successfully.
        $stage = 'history_log';
        try {
            if (!logHistory($userId, 'key_purchase', 'Purchased ' . count($codes) . ' key(s) for ' . number_format($total, 2, '.', ''))) {
                error_log('Local purchase committed but history logging failed; TX range #' . $firstPurchaseTransactionId . '-#' . $lastPurchaseTransactionId);
            }
        } catch (Throwable $historyError) {
            error_log('Local purchase committed but history logging threw: ' . $historyError->getMessage());
        }

        $_SESSION['balance'] = round((float) $user['balance'] - $total, 2);
        // A bulk purchase can contain up to 100 transaction rows. Do not add
        // dozens of read-model transactions to the customer's response time;
        // the background Commerce Center reconciliation job backfills them.
        return ['success' => true, 'keys' => $codes, 'total' => $total];
    } catch (Throwable $e) {
        $dbError = trim((string) ($conn->error ?? ''));
        if (!$committed) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
        try { $errorId = 'LCP-' . date('ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))); }
        catch (Throwable $ignored) { $errorId = 'LCP-' . date('ymd-His'); }
        error_log(
            $errorId . ' Atomic key purchase failed; stage=' . $stage
            . '; message=' . $e->getMessage()
            . ($dbError !== '' ? '; db=' . substr($dbError, 0, 500) : '')
        );
        $message = 'Purchase could not be completed. Please try again.';
        if ((string) ($_SESSION['role'] ?? '') === 'admin') {
            $message .= ' [' . $errorId . ' / ' . $stage . ']';
        }
        return ['success' => false, 'message' => $message, 'error_id' => $errorId, 'stage' => $stage];
    }
}

if (!function_exists('htmlJsArg')) {
    /**
     * Encode a value as a JavaScript literal that is safe inside an HTML attribute.
     */
    function htmlJsArg($value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return htmlspecialchars($json === false ? 'null' : $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
