from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: str, old: str, new: str) -> None:
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}: {old[:100]!r}")
    file_path.write_text(text.replace(old, new, 1), encoding="utf-8")


helper = r'''<?php
/**
 * Reference-aware lifecycle helpers for product images stored under
 * public_html/assets/uploads/products.
 *
 * Only simple image filenames inside this managed directory are ever eligible
 * for deletion. Remote URLs, traversal paths, symlinks and unknown locations
 * fail closed.
 */

if (!function_exists('sakazukiNormalizeManagedProductImagePath')) {
    function sakazukiNormalizeManagedProductImagePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || strpos($value, "\0") !== false) return '';
        $value = ltrim($value, '/');
        if (preg_match('#\Aassets/uploads/products/([A-Za-z0-9][A-Za-z0-9._-]{0,240}\.(?:jpe?g|png|webp|gif))\z#iD', $value) !== 1) {
            return '';
        }
        return $value;
    }
}

if (!function_exists('sakazukiProductImageUploadDirectory')) {
    function sakazukiProductImageUploadDirectory(?string $publicRoot = null): string
    {
        $root = $publicRoot === null ? dirname(__DIR__) : rtrim($publicRoot, '/\\');
        return $root . '/assets/uploads/products';
    }
}

if (!function_exists('sakazukiManagedProductImageFilesystemPath')) {
    function sakazukiManagedProductImageFilesystemPath(string $relative, ?string $publicRoot = null): string
    {
        $relative = sakazukiNormalizeManagedProductImagePath($relative);
        if ($relative === '') return '';
        return sakazukiProductImageUploadDirectory($publicRoot) . '/' . basename($relative);
    }
}

if (!function_exists('sakazukiProductImageCountQuery')) {
    function sakazukiProductImageCountQuery(mysqli $conn, string $sql, string $path, bool $bindTwice = false): ?int
    {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) return null;
            $a = $path;
            if ($bindTwice) {
                $b = $path;
                $stmt->bind_param('ss', $a, $b);
            } else {
                $stmt->bind_param('s', $a);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $count = 0;
            $stmt->bind_result($count);
            $ok = $stmt->fetch();
            $stmt->close();
            return $ok ? max(0, (int) $count) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sakazukiProductImageReferenceCount')) {
    function sakazukiProductImageReferenceCount(string $relative): ?int
    {
        global $conn;
        $relative = sakazukiNormalizeManagedProductImagePath($relative);
        if ($relative === '') return 0;
        if (!isset($conn) || !($conn instanceof mysqli)) return null;

        $total = sakazukiProductImageCountQuery(
            $conn,
            'SELECT COUNT(*) FROM products WHERE image=?',
            $relative
        );
        if ($total === null) return null;

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('cgo_products', ['image_path', 'cached_image_path'])) {
            $count = sakazukiProductImageCountQuery(
                $conn,
                'SELECT COUNT(*) FROM cgo_products WHERE image_path=? OR cached_image_path=?',
                $relative,
                true
            );
            if ($count === null) return null;
            $total += $count;
        }

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('supplier_products', ['image_url'])) {
            $count = sakazukiProductImageCountQuery(
                $conn,
                'SELECT COUNT(*) FROM supplier_products WHERE image_url=?',
                $relative
            );
            if ($count === null) return null;
            $total += $count;
        }

        return $total;
    }
}

if (!function_exists('sakazukiCollectManagedProductImageReferences')) {
    /** @return array<string,true>|null */
    function sakazukiCollectManagedProductImageReferences(): ?array
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli)) return null;
        $references = [];

        $load = static function (string $sql, array $columns) use ($conn, &$references): bool {
            try {
                $result = $conn->query($sql);
                if (!$result) return false;
                while ($row = $result->fetch_assoc()) {
                    foreach ($columns as $column) {
                        $relative = sakazukiNormalizeManagedProductImagePath((string) ($row[$column] ?? ''));
                        if ($relative !== '') $references[$relative] = true;
                    }
                }
                $result->free();
                return true;
            } catch (Throwable $e) {
                return false;
            }
        };

        if (!$load(
            "SELECT image FROM products WHERE image LIKE 'assets/uploads/products/%' OR image LIKE '/assets/uploads/products/%'",
            ['image']
        )) return null;

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('cgo_products', ['image_path', 'cached_image_path'])) {
            if (!$load(
                "SELECT image_path,cached_image_path FROM cgo_products WHERE image_path LIKE '%assets/uploads/products/%' OR cached_image_path LIKE '%assets/uploads/products/%'",
                ['image_path', 'cached_image_path']
            )) return null;
        }

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('supplier_products', ['image_url'])) {
            if (!$load(
                "SELECT image_url FROM supplier_products WHERE image_url LIKE '%assets/uploads/products/%'",
                ['image_url']
            )) return null;
        }

        return $references;
    }
}

if (!function_exists('sakazukiDeleteManagedProductImageIfUnreferenced')) {
    /**
     * @param callable|null $referenceCounter Optional test/maintenance override receiving the normalized relative path.
     * @return array{deleted:bool,reason:string,bytes:int,references:?int,path:string}
     */
    function sakazukiDeleteManagedProductImageIfUnreferenced(
        string $relative,
        ?callable $referenceCounter = null,
        ?string $publicRoot = null
    ): array {
        $normalized = sakazukiNormalizeManagedProductImagePath($relative);
        $result = ['deleted' => false, 'reason' => 'unmanaged', 'bytes' => 0, 'references' => null, 'path' => $normalized];
        if ($normalized === '') return $result;

        try {
            $references = $referenceCounter !== null
                ? $referenceCounter($normalized)
                : sakazukiProductImageReferenceCount($normalized);
        } catch (Throwable $e) {
            $references = null;
        }
        if ($references === null || !is_numeric($references)) {
            $result['reason'] = 'reference_check_failed';
            return $result;
        }
        $references = max(0, (int) $references);
        $result['references'] = $references;
        if ($references > 0) {
            $result['reason'] = 'referenced';
            return $result;
        }

        $path = sakazukiManagedProductImageFilesystemPath($normalized, $publicRoot);
        if ($path === '' || !file_exists($path)) {
            $result['reason'] = 'missing';
            return $result;
        }
        if (!is_file($path) || is_link($path)) {
            $result['reason'] = 'unsafe_file';
            return $result;
        }

        $uploadDirectory = sakazukiProductImageUploadDirectory($publicRoot);
        $realDirectory = realpath($uploadDirectory);
        $realPath = realpath($path);
        if ($realDirectory === false || $realPath === false || dirname($realPath) !== $realDirectory) {
            $result['reason'] = 'outside_managed_directory';
            return $result;
        }

        $bytes = max(0, (int) @filesize($realPath));
        if (!@unlink($realPath)) {
            $result['reason'] = 'unlink_failed';
            return $result;
        }
        $result['deleted'] = true;
        $result['reason'] = 'deleted';
        $result['bytes'] = $bytes;
        return $result;
    }
}

if (!function_exists('sakazukiCleanupOrphanedProductImages')) {
    /**
     * Bounded orphan cleanup for shared hosting. Files younger than the grace
     * period are never considered, preventing races with uploads/DB commits.
     */
    function sakazukiCleanupOrphanedProductImages(int $maxDeleted = 20, int $graceSeconds = 172800): array
    {
        $maxDeleted = max(1, min(100, $maxDeleted));
        $graceSeconds = max(3600, min(2592000, $graceSeconds));
        $directory = sakazukiProductImageUploadDirectory();
        if (!is_dir($directory)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Product image directory does not exist',
                'scanned' => 0,
                'deleted' => 0,
                'freed_bytes' => 0,
                'next_interval_seconds' => 21600,
            ];
        }

        $references = sakazukiCollectManagedProductImageReferences();
        if ($references === null) {
            return [
                'success' => false,
                'message' => 'Product image reference scan failed',
                'scanned' => 0,
                'deleted' => 0,
                'freed_bytes' => 0,
                'next_interval_seconds' => 3600,
            ];
        }

        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return [
                'success' => false,
                'message' => 'Product image directory could not be scanned',
                'scanned' => 0,
                'deleted' => 0,
                'freed_bytes' => 0,
                'next_interval_seconds' => 3600,
            ];
        }

        $files = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            $relative = sakazukiNormalizeManagedProductImagePath('assets/uploads/products/' . $name);
            if ($relative !== '') $files[] = $relative;
        }
        sort($files, SORT_STRING);

        $cursor = function_exists('getSetting') ? trim((string) getSetting('product_image_cleanup_cursor', '')) : '';
        $start = 0;
        if ($cursor !== '') {
            $found = false;
            foreach ($files as $index => $relative) {
                if (strcmp($relative, $cursor) > 0) {
                    $start = $index;
                    $found = true;
                    break;
                }
            }
            if (!$found) $start = 0;
        }

        $scanLimit = 500;
        $cutoff = time() - $graceSeconds;
        $scanned = 0;
        $referenced = 0;
        $recent = 0;
        $candidates = 0;
        $deleted = 0;
        $failed = 0;
        $freedBytes = 0;
        $lastRelative = '';
        $nextIndex = $start;

        for ($index = $start; $index < count($files) && $scanned < $scanLimit; $index++) {
            $relative = $files[$index];
            $nextIndex = $index + 1;
            $lastRelative = $relative;
            $scanned++;

            $path = sakazukiManagedProductImageFilesystemPath($relative);
            if ($path === '' || !is_file($path) || is_link($path)) continue;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $cutoff) {
                $recent++;
                continue;
            }
            if (isset($references[$relative])) {
                $referenced++;
                continue;
            }

            $candidates++;
            $deleteResult = sakazukiDeleteManagedProductImageIfUnreferenced(
                $relative,
                static function (string $candidate) use ($references): int {
                    return isset($references[$candidate]) ? 1 : 0;
                }
            );
            if (!empty($deleteResult['deleted'])) {
                $deleted++;
                $freedBytes += max(0, (int) ($deleteResult['bytes'] ?? 0));
                if ($deleted >= $maxDeleted) break;
            } elseif (($deleteResult['reason'] ?? '') !== 'missing') {
                $failed++;
            }
        }

        $reachedEnd = $files === [] || $nextIndex >= count($files);
        if (function_exists('upsertSetting')) {
            upsertSetting('product_image_cleanup_cursor', $reachedEnd ? '' : $lastRelative);
        }

        return [
            'success' => true,
            'message' => $deleted > 0 ? 'Orphan product images cleaned' : 'No eligible orphan product images found',
            'scanned' => $scanned,
            'referenced' => $referenced,
            'skipped_recent' => $recent,
            'candidates' => $candidates,
            'deleted' => $deleted,
            'failed' => $failed,
            'freed_bytes' => $freedBytes,
            'completed_pass' => $reachedEnd,
            'next_interval_seconds' => $deleted > 0 ? 300 : 21600,
        ];
    }
}
'''
(ROOT / "public_html/includes/product_image_lifecycle.php").write_text(helper, encoding="utf-8")


test = r'''<?php
require_once __DIR__ . '/../public_html/includes/product_image_lifecycle.php';

$tests = 0;
$failures = 0;

function image_lifecycle_assert($condition, string $message): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

image_lifecycle_assert(
    sakazukiNormalizeManagedProductImagePath('assets/uploads/products/p_abc123.webp') === 'assets/uploads/products/p_abc123.webp',
    'managed product image path should normalize'
);
image_lifecycle_assert(
    sakazukiNormalizeManagedProductImagePath('/assets/uploads/products/cgo_' . str_repeat('a', 64) . '.jpg') === 'assets/uploads/products/cgo_' . str_repeat('a', 64) . '.jpg',
    'leading slash should normalize for legacy database values'
);
foreach ([
    'https://example.com/image.webp',
    'assets/uploads/products/../secret.jpg',
    'assets/uploads/other/p_test.webp',
    '/etc/passwd',
    'assets/uploads/products/not-an-image.txt',
] as $unsafe) {
    image_lifecycle_assert(
        sakazukiNormalizeManagedProductImagePath($unsafe) === '',
        'unsafe/unmanaged path must be rejected: ' . $unsafe
    );
}

$root = sys_get_temp_dir() . '/sakazuki-image-lifecycle-' . bin2hex(random_bytes(6));
$uploadDir = $root . '/assets/uploads/products';
if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    fwrite(STDERR, "FAIL: unable to create temporary test directory\n");
    exit(1);
}

$relative = 'assets/uploads/products/p_test.webp';
$file = $uploadDir . '/p_test.webp';
file_put_contents($file, 'image-bytes');
$referenced = sakazukiDeleteManagedProductImageIfUnreferenced(
    $relative,
    static fn(string $path): int => 1,
    $root
);
image_lifecycle_assert(empty($referenced['deleted']), 'referenced file must not be deleted');
image_lifecycle_assert(($referenced['reason'] ?? '') === 'referenced', 'referenced deletion should report referenced');
image_lifecycle_assert(is_file($file), 'referenced file must still exist');

$deleted = sakazukiDeleteManagedProductImageIfUnreferenced(
    $relative,
    static fn(string $path): int => 0,
    $root
);
image_lifecycle_assert(!empty($deleted['deleted']), 'unreferenced managed file should be deleted');
image_lifecycle_assert(($deleted['reason'] ?? '') === 'deleted', 'successful deletion should report deleted');
image_lifecycle_assert(!file_exists($file), 'deleted file must no longer exist');

$outside = $root . '/outside.webp';
file_put_contents($outside, 'outside');
$outsideResult = sakazukiDeleteManagedProductImageIfUnreferenced(
    '../outside.webp',
    static fn(string $path): int => 0,
    $root
);
image_lifecycle_assert(empty($outsideResult['deleted']), 'unmanaged traversal path must not delete anything');
image_lifecycle_assert(is_file($outside), 'outside file must remain intact');

@unlink($outside);
@rmdir($uploadDir);
@rmdir(dirname($uploadDir));
@rmdir($root . '/assets');
@rmdir($root);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$tests} assertions failed\n");
    exit(1);
}

echo "Product image lifecycle tests passed ({$tests} assertions).\n";
'''
(ROOT / "tests/product_image_lifecycle_test.php").write_text(test, encoding="utf-8")


replace_once(
    "public_html/includes/functions.php",
    "require_once __DIR__ . '/slip_debug.php';\n",
    "require_once __DIR__ . '/slip_debug.php';\nrequire_once __DIR__ . '/product_image_lifecycle.php';\n",
)
replace_once(
    "public_html/includes/functions.php",
    "        // Keep the old file. Other imported records may still reference it; a\n        // separate reference-aware cleanup can remove orphans safely later.\n        $converted++;",
    "        if (function_exists('sakazukiDeleteManagedProductImageIfUnreferenced')) {\n            sakazukiDeleteManagedProductImageIfUnreferenced($oldRelative);\n        }\n        $converted++;",
)

replace_once(
    "public_html/admin/products.php",
    "    try {\n        if ($action === 'add') {",
    "    $pendingProductImage = '';\n    try {\n        if ($action === 'add') {",
)
replace_once(
    "public_html/admin/products.php",
    "            $image = handleProductImageUpload('image_file', '');\n            $conn->begin_transaction();",
    "            $image = handleProductImageUpload('image_file', '');\n            if ($image !== '') $pendingProductImage = $image;\n            $conn->begin_transaction();",
)
replace_once(
    "public_html/admin/products.php",
    "            $conn->commit();\n            $success = Lang::t('admin.products.success.add');",
    "            $conn->commit();\n            $pendingProductImage = '';\n            $success = Lang::t('admin.products.success.add');",
)
replace_once(
    "public_html/admin/products.php",
    "            // Never trust old_image from POST. The current path comes from the database.\n            $image = handleProductImageUpload('image_file', (string) ($current['image'] ?? ''));\n            $conn->begin_transaction();",
    "            // Never trust old_image from POST. The current path comes from the database.\n            $oldProductImage = (string) ($current['image'] ?? '');\n            $image = handleProductImageUpload('image_file', $oldProductImage);\n            if ($image !== $oldProductImage) $pendingProductImage = $image;\n            $conn->begin_transaction();",
)
replace_once(
    "public_html/admin/products.php",
    "            $conn->commit();\n            $success = Lang::t('admin.products.success.edit');",
    "            $conn->commit();\n            $pendingProductImage = '';\n            if ($image !== $oldProductImage && function_exists('sakazukiDeleteManagedProductImageIfUnreferenced')) {\n                sakazukiDeleteManagedProductImageIfUnreferenced($oldProductImage);\n            }\n            $success = Lang::t('admin.products.success.edit');",
)
replace_once(
    "public_html/admin/products.php",
    "    } catch (InvalidArgumentException $e) {\n        try { $conn->rollback(); } catch (Throwable $ignored) {}\n        $error = $e->getMessage();",
    "    } catch (InvalidArgumentException $e) {\n        try { $conn->rollback(); } catch (Throwable $ignored) {}\n        if ($pendingProductImage !== '' && function_exists('sakazukiDeleteManagedProductImageIfUnreferenced')) {\n            sakazukiDeleteManagedProductImageIfUnreferenced($pendingProductImage);\n            $pendingProductImage = '';\n        }\n        $error = $e->getMessage();",
)
replace_once(
    "public_html/admin/products.php",
    "    } catch (Throwable $e) {\n        try { $conn->rollback(); } catch (Throwable $ignored) {}\n        error_log('Product administration failed: ' . $e->getMessage());",
    "    } catch (Throwable $e) {\n        try { $conn->rollback(); } catch (Throwable $ignored) {}\n        if ($pendingProductImage !== '' && function_exists('sakazukiDeleteManagedProductImageIfUnreferenced')) {\n            sakazukiDeleteManagedProductImageIfUnreferenced($pendingProductImage);\n            $pendingProductImage = '';\n        }\n        error_log('Product administration failed: ' . $e->getMessage());",
)

replace_once(
    "public_html/includes/automation.php",
    "        'product_image_optimizer' => [\n",
    "        'product_image_cleanup' => [\n            'critical' => false,\n            // Reconcile filesystem uploads against database references. A 48h\n            // grace period prevents races with uploads and delayed sync work.\n            'interval' => 3600,\n            'callback' => static function (): array {\n                return function_exists('sakazukiCleanupOrphanedProductImages')\n                    ? sakazukiCleanupOrphanedProductImages(20, 172800)\n                    : ['success' => true, 'skipped' => true, 'message' => 'Product image cleanup is unavailable'];\n            },\n        ],\n        'product_image_optimizer' => [\n",
)

replace_once(
    ".gitignore",
    "# Runtime/cache state\n",
    "# Runtime/cache state\n/public_html/assets/uploads/products/\n",
)

replace_once(
    ".github/workflows/truemoney-byteindev-ci.yml",
    "    branches:\n      - upgrade/truemoney-byteindev-20260920\n",
    "    branches:\n      - main\n      - fix/product-image-lifecycle-20260924\n      - upgrade/truemoney-byteindev-20260920\n",
)
replace_once(
    ".github/workflows/truemoney-byteindev-ci.yml",
    "          php -l tests/truemoney_byteindev_routing_test.php\n",
    "          php -l tests/truemoney_byteindev_routing_test.php\n          php -l tests/product_image_lifecycle_test.php\n",
)
replace_once(
    ".github/workflows/truemoney-byteindev-ci.yml",
    "          php tests/truemoney_byteindev_routing_test.php\n\n      - name: Lint all production PHP\n",
    "          php tests/truemoney_byteindev_routing_test.php\n          php tests/product_image_lifecycle_test.php\n\n      - name: Lint all production PHP\n",
)

print("Product image lifecycle patch applied successfully")
