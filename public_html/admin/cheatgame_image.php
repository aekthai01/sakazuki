<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';
requireAdmin();

// Image requests may arrive in parallel. Release the PHP session lock after the
// authorization check so one slow supplier image cannot serialize the page.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$productId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
$product = $productId > 0 ? cgoGetProductById($productId, false) : null;

$sendPlaceholder = static function (int $status = 404): void {
    http_response_code($status);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="224" height="160" viewBox="0 0 224 160"><rect width="224" height="160" fill="#17171c"/><text x="112" y="86" text-anchor="middle" fill="#6b7280" font-family="sans-serif" font-size="18">No image</text></svg>';
    exit;
};

$streamLocalImage = static function (string $relativePath) use ($sendPlaceholder): void {
    $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if (preg_match('#^assets/uploads/products/cgo_[a-f0-9]{64}\.(?:jpe?g|png|webp|gif)$#i', $relativePath) !== 1) {
        $sendPlaceholder(404);
    }
    $publicRoot = realpath(dirname(__DIR__));
    $allowedRoot = realpath(dirname(__DIR__) . '/assets/uploads/products');
    $file = realpath(dirname(__DIR__) . '/' . $relativePath);
    if ($publicRoot === false || $allowedRoot === false || $file === false || !is_file($file)
        || strpos($file, $allowedRoot . DIRECTORY_SEPARATOR) !== 0) {
        $sendPlaceholder(404);
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $file);
            if (is_string($detected)) $mime = strtolower(trim($detected));
            finfo_close($finfo);
        }
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowedMimes, true)) {
        $sendPlaceholder(415);
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($file));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
};

if (!$product) {
    $sendPlaceholder(404);
}

$cachedPath = trim((string) ($product['cached_image_path'] ?? ''));
if ($cachedPath !== '') {
    $candidate = dirname(__DIR__) . '/' . ltrim($cachedPath, '/');
    if (is_file($candidate) && filesize($candidate) > 0) {
        $streamLocalImage($cachedPath);
    }
}

$remoteUrl = cgoProductImageUrl($product);
if ($remoteUrl === '') {
    $sendPlaceholder(404);
}

$cachedPath = cgoCacheProviderImage($remoteUrl);
if ($cachedPath !== '') {
    global $conn;
    $stmt = $conn->prepare('UPDATE cgo_products SET cached_image_path = ? WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('si', $cachedPath, $productId);
        $stmt->execute();
        $stmt->close();
    }
    $streamLocalImage($cachedPath);
}

// Final fallback: let the browser request the exact HTTPS URL supplied by the
// API. No API key or secret is attached to this redirect.
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, max-age=300');
header('Location: ' . $remoteUrl, true, 302);
exit;
