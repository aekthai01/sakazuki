from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
path = ROOT / "public_html/includes/product_image_lifecycle.php"
text = path.read_text(encoding="utf-8")

old = """if (!function_exists('sakazukiProductImageCountQuery')) {
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
"""
new = """if (!function_exists('sakazukiProductImageCountQuery')) {
    /** @param list<string> $paths */
    function sakazukiProductImageCountQuery(mysqli $conn, string $sql, array $paths): ?int
    {
        try {
            if ($paths === []) return null;
            $stmt = $conn->prepare($sql);
            if (!$stmt) return null;
            $types = str_repeat('s', count($paths));
            $values = array_values($paths);
            $bind = [$types];
            foreach ($values as $index => $value) $bind[] = &$values[$index];
            if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
                $stmt->close();
                return null;
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
"""
if text.count(old) != 1:
    raise SystemExit("expected product image count helper exactly once")
text = text.replace(old, new, 1)

old = """        $total = sakazukiProductImageCountQuery(
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
"""
new = """        $legacyRelative = '/' . $relative;
        $total = sakazukiProductImageCountQuery(
            $conn,
            'SELECT COUNT(*) FROM products WHERE image=? OR image=?',
            [$relative, $legacyRelative]
        );
        if ($total === null) return null;

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('cgo_products', ['image_path', 'cached_image_path'])) {
            $count = sakazukiProductImageCountQuery(
                $conn,
                'SELECT COUNT(*) FROM cgo_products WHERE image_path=? OR image_path=? OR cached_image_path=? OR cached_image_path=?',
                [$relative, $legacyRelative, $relative, $legacyRelative]
            );
            if ($count === null) return null;
            $total += $count;
        }

        if (function_exists('sakazukiTableColumnsReady')
            && sakazukiTableColumnsReady('supplier_products', ['image_url'])) {
            $count = sakazukiProductImageCountQuery(
                $conn,
                'SELECT COUNT(*) FROM supplier_products WHERE image_url=? OR image_url=?',
                [$relative, $legacyRelative]
            );
            if ($count === null) return null;
            $total += $count;
        }
"""
if text.count(old) != 1:
    raise SystemExit("expected reference-count block exactly once")
text = text.replace(old, new, 1)

old = """            $deleteResult = sakazukiDeleteManagedProductImageIfUnreferenced(
                $relative,
                static function (string $candidate) use ($references): int {
                    return isset($references[$candidate]) ? 1 : 0;
                }
            );
"""
new = """            // The snapshot above is only a cheap candidate filter. Re-query
            // live database references immediately before unlinking so a sync or
            // admin request cannot attach this old file between scan and delete.
            $deleteResult = sakazukiDeleteManagedProductImageIfUnreferenced($relative);
"""
if text.count(old) != 1:
    raise SystemExit("expected cleanup delete block exactly once")
text = text.replace(old, new, 1)

path.write_text(text, encoding="utf-8")
print("Product image lifecycle hardening applied successfully")
