<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';
requireAdmin();

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string { return $isTh ? $th : $en; };
$h = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

$clientId = isset($_GET['client_id']) ? (int) $_GET['client_id'] : (int) ($_POST['client_id'] ?? 0);
$client = storeBridgeGetClient($clientId);
if (!$client) {
    http_response_code(404);
    exit($t('ไม่พบลูกค้า API', 'API client not found'));
}
$clientUsesProductRules = storeBridgeClientUsesLegacyProductRules($client);

if (!function_exists('storeBridgeClientProductQuery')) {
    function storeBridgeClientProductQuery(array $source): array
    {
        $allowedStock = ['all', 'in_stock', 'out_of_stock'];
        $allowedState = ['all', 'enabled', 'disabled'];
        $stock = isset($source['stock']) && is_scalar($source['stock']) ? strtolower(trim((string) $source['stock'])) : 'all';
        $state = isset($source['state']) && is_scalar($source['state']) ? strtolower(trim((string) $source['state'])) : 'all';
        if (!in_array($stock, $allowedStock, true)) $stock = 'all';
        if (!in_array($state, $allowedState, true)) $state = 'all';
        return [
            'q' => isset($source['q']) && is_scalar($source['q']) ? substr(trim((string) $source['q']), 0, 190) : '',
            'category' => isset($source['category']) && is_scalar($source['category']) ? substr(trim((string) $source['category']), 0, 255) : '',
            'stock' => $stock,
            'state' => $state,
        ];
    }
}

if (!function_exists('storeBridgeClientProductRedirect')) {
    function storeBridgeClientProductRedirect(int $clientId, string $message = '', string $error = '', array $query = []): void
    {
        $_SESSION['store_bridge_client_product_flash'] = ['message' => $message, 'error' => $error];
        $params = array_merge(['client_id' => $clientId], array_filter([
            'q' => isset($query['q']) ? substr(trim((string) $query['q']), 0, 190) : '',
            'category' => isset($query['category']) ? substr(trim((string) $query['category']), 0, 255) : '',
            'stock' => isset($query['stock']) && in_array($query['stock'], ['in_stock', 'out_of_stock'], true) ? $query['stock'] : '',
            'state' => isset($query['state']) && in_array($query['state'], ['enabled', 'disabled'], true) ? $query['state'] : '',
        ], static fn($value): bool => $value !== ''));
        header('Location: api_client_products.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 303);
        exit;
    }
}

$message = '';
$error = '';
if (isset($_SESSION['store_bridge_client_product_flash']) && is_array($_SESSION['store_bridge_client_product_flash'])) {
    $flash = $_SESSION['store_bridge_client_product_flash'];
    unset($_SESSION['store_bridge_client_product_flash']);
    $message = is_string($flash['message'] ?? null) ? $flash['message'] : '';
    $error = is_string($flash['error'] ?? null) ? $flash['error'] : '';
}

$query = storeBridgeClientProductQuery(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';
    $variantId = (int) ($_POST['variant_id'] ?? 0);
    $adminId = (int) ($_SESSION['user_id'] ?? 0);
    if (!$clientUsesProductRules) {
        storeBridgeClientProductRedirect($clientId, '', $t('โหมดหักเงินจากบัญชีตัวแทนจะใช้รายการสินค้า Store API ที่เปิดใช้งานและราคาตัวแทนจริงของบัญชีที่ผูก ส่วนแหล่งสต็อก LOCAL/CGO/Supplier ควบคุมจาก API Hub จึงไม่รับกฎสินค้า/ราคาพิเศษของ API แบบเดิม', 'Linked reseller-wallet clients use the active Store API catalogue and the linked account effective reseller price. LOCAL/CGO/supplier source access is controlled in API Hub, so legacy per-product API visibility/custom-price rules are disabled.'), $query);
    }
    if ($action === 'save_rule') {
        $result = storeBridgeSaveClientProductRule($clientId, $variantId, isset($_POST['enabled']), $_POST['custom_price'] ?? null);
        if (empty($result['success'])) storeBridgeClientProductRedirect($clientId, '', (string) ($result['message'] ?? $t('บันทึกไม่สำเร็จ', 'Save failed')), $query);
        logHistory($adminId, 'store_api_client_product_rule', 'Updated client #' . $clientId . ' variant #' . $variantId);
        storeBridgeClientProductRedirect($clientId, $t('บันทึกราคาสินค้าสำหรับ API Key แล้ว', 'Per-product API pricing saved.'), '', $query);
    }
    if ($action === 'reset_rule') {
        if (!storeBridgeResetClientProductRule($clientId, $variantId)) storeBridgeClientProductRedirect($clientId, '', $t('คืนค่าเริ่มต้นไม่สำเร็จ', 'Unable to reset rule'), $query);
        logHistory($adminId, 'store_api_client_product_reset', 'Reset client #' . $clientId . ' variant #' . $variantId);
        storeBridgeClientProductRedirect($clientId, $t('คืนค่าราคาสินค้านี้ตามระดับราคาของคีย์แล้ว', 'Product pricing reset to the client default.'), '', $query);
    }
    storeBridgeClientProductRedirect($clientId, '', $t('คำสั่งไม่ถูกต้อง', 'Invalid action'), $query);
}

$allRows = $clientUsesProductRules ? storeBridgeGetClientCatalogue($clientId) : [];
$categories = [];
$stats = ['all' => 0, 'in_stock' => 0, 'out_of_stock' => 0, 'enabled' => 0, 'disabled' => 0];
foreach ($allRows as $row) {
    $stats['all']++;
    $stats[(int) ($row['remote_stock'] ?? 0) > 0 ? 'in_stock' : 'out_of_stock']++;
    $stats[(int) ($row['rule_enabled'] ?? 1) === 1 ? 'enabled' : 'disabled']++;
    foreach (($row['categories'] ?? []) as $categoryName) {
        $categoryName = trim((string) $categoryName);
        if ($categoryName !== '') $categories[$categoryName] = $categoryName;
    }
}
natcasesort($categories);

$needle = function_exists('mb_strtolower') ? mb_strtolower($query['q'], 'UTF-8') : strtolower($query['q']);
$rows = array_values(array_filter($allRows, static function (array $row) use ($query, $needle): bool {
    $stock = (int) ($row['remote_stock'] ?? 0);
    if ($query['stock'] === 'in_stock' && $stock <= 0) return false;
    if ($query['stock'] === 'out_of_stock' && $stock > 0) return false;
    $enabled = (int) ($row['rule_enabled'] ?? 1) === 1;
    if ($query['state'] === 'enabled' && !$enabled) return false;
    if ($query['state'] === 'disabled' && $enabled) return false;
    if ($query['category'] !== '' && !in_array($query['category'], $row['categories'] ?? [], true)) return false;
    if ($needle !== '') {
        $haystack = implode(' ', [
            (string) ($row['name'] ?? ''),
            (string) ($row['duration'] ?? ''),
            (string) ($row['source_variant_id'] ?? ''),
            (string) ($row['source_product_id'] ?? ''),
            (string) ($row['platform'] ?? ''),
            (string) ($row['category_text'] ?? ''),
        ]);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        if (strpos($haystack, $needle) === false) return false;
    }
    return true;
}));

$buildFilterUrl = static function (array $overrides = []) use ($clientId, $query): string {
    $merged = array_merge($query, $overrides);
    $params = ['client_id' => $clientId];
    foreach (['q', 'category', 'stock', 'state'] as $key) {
        $value = trim((string) ($merged[$key] ?? ''));
        if ($value !== '' && $value !== 'all') $params[$key] = $value;
    }
    return 'api_client_products.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?php echo $h($t('ราคาสินค้า API รายคีย์', 'Per-client API pricing')); ?></title>
<link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
.glass{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09)}.field{border-radius:.6rem;border:1px solid rgba(255,255,255,.12);background:#111827;padding:.55rem .7rem;color:#f3f4f6}.btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;border-radius:.6rem;padding:.55rem .8rem;font-weight:600}.btn-primary{background:#7c3aed;color:#fff}.btn-soft{background:rgba(255,255,255,.09)}.filter-card{transition:.15s}.filter-card:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.2)}.filter-card.active{border-color:rgba(129,140,248,.7);background:rgba(99,102,241,.15)}
</style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-[1800px] mx-auto p-4 md:p-6 space-y-5">
<div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold"><i class="bi bi-tags text-amber-300 mr-2"></i><?php echo $h($t('กำหนดราคาสินค้าให้ API Key', 'Per-product API pricing')); ?></h1><p class="text-gray-400 mt-1">#<?php echo $clientId; ?> <?php echo $h($client['name']); ?> · <?php echo $h($client['price_tier']); ?> × <?php echo $h(number_format((float) $client['price_multiplier'], 4)); ?></p></div><a class="btn btn-soft" href="api_hub.php"><i class="bi bi-arrow-left"></i><?php echo $h($t('กลับศูนย์ API', 'Back to API Hub')); ?></a></div>
<?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/30 bg-red-900/20 p-4 text-red-200"><?php echo $h($error); ?></div><?php endif; ?>
<?php if ($message !== ''): ?><div class="rounded-xl border border-emerald-500/30 bg-emerald-900/20 p-4 text-emerald-200"><?php echo $h($message); ?></div><?php endif; ?>
<?php if (!$clientUsesProductRules): ?>
<div class="rounded-xl border border-amber-500/30 bg-amber-950/20 p-5 text-sm text-amber-100">
    <div class="font-bold text-base"><i class="bi bi-shield-lock mr-2"></i><?php echo $h($t('โหมดบัญชีตัวแทนไม่ใช้กฎสินค้า API แบบเดิม', 'Linked reseller-wallet mode does not use legacy API product rules')); ?></div>
    <div class="mt-2 text-amber-100/80"><?php echo $h($t('API Key นี้จะเห็นสินค้า/Variant ที่ Store API เปิดใช้งานและใช้ราคาตัวแทนจริงของบัญชีที่ผูก (รวมราคาพิเศษรายบัญชี) โดยไม่ใช้ Custom API Price, Multiplier หรือสถานะปิดสินค้าเก่าจาก store_api_client_products สต็อก LOCAL เปิดเสมอ ส่วน CGO/VIPSTORE/StarkMods/Supplier อื่นจะถูกนำมาขายต่อเฉพาะแหล่งที่ Admin ติ๊กอนุญาตให้ API Client นี้ใน API Hub', 'This API key sees active Store API products/variants and uses the linked account\'s effective reseller price (including account-specific pricing). Legacy Custom API Price, multiplier, and disabled-product rules are ignored. LOCAL remains available; CGO/VIPSTORE/StarkMods/other supplier stock is exported only when the provider explicitly allows that source for this API client in API Hub.')); ?></div>
</div>
<?php else: ?>
<div class="glass rounded-xl p-4 text-sm text-gray-300"><?php echo $h($t('เว้นราคาพิเศษว่างไว้เพื่อใช้ระดับราคาและตัวคูณของ API Key ตามปกติ ปิดสินค้าเพื่อไม่ส่งสินค้านั้นให้คีย์นี้เห็นและสั่งซื้อ โดยไม่กระทบ API Key อื่น', 'Leave custom price blank to use the client tier and multiplier. Disable a product to hide it from this API key without affecting other clients.')); ?></div>

<div id="apiClientProductsLivePanel" data-instant-panel class="space-y-5">
<section class="grid grid-cols-2 md:grid-cols-5 gap-3">
<?php foreach ([
    ['all', $t('ทั้งหมด', 'All'), 'bi-grid', $stats['all']],
    ['in_stock', $t('มีสต็อก', 'In stock'), 'bi-box-seam', $stats['in_stock']],
    ['out_of_stock', $t('หมดสต็อก', 'Out of stock'), 'bi-box', $stats['out_of_stock']],
    ['enabled', $t('เปิดให้คีย์นี้', 'Enabled'), 'bi-eye', $stats['enabled']],
    ['disabled', $t('ปิดสำหรับคีย์นี้', 'Disabled'), 'bi-eye-slash', $stats['disabled']],
] as [$key, $label, $icon, $count]):
    $isStock = in_array($key, ['in_stock', 'out_of_stock'], true);
    $isState = in_array($key, ['enabled', 'disabled'], true);
    $active = $key === 'all' ? ($query['stock'] === 'all' && $query['state'] === 'all') : ($isStock ? $query['stock'] === $key : $query['state'] === $key);
    $overrides = $key === 'all' ? ['stock' => 'all', 'state' => 'all'] : ($isStock ? ['stock' => $key] : ['state' => $key]);
?>
<a href="<?php echo $h($buildFilterUrl($overrides)); ?>" class="filter-card glass rounded-xl p-3 <?php echo $active ? 'active' : ''; ?>"><div class="flex items-center justify-between"><i class="bi <?php echo $h($icon); ?> text-indigo-300"></i><span class="text-xl font-bold"><?php echo number_format((int) $count); ?></span></div><div class="text-xs text-gray-400 mt-2"><?php echo $h($label); ?></div></a>
<?php endforeach; ?>
</section>

<form method="get" class="glass rounded-xl p-4 grid grid-cols-1 md:grid-cols-[minmax(260px,2fr)_minmax(200px,1fr)_minmax(170px,1fr)_auto] gap-3 items-end">
<input type="hidden" name="client_id" value="<?php echo $clientId; ?>">
<div><label class="text-xs text-gray-400 block mb-1"><?php echo $h($t('ค้นหาสินค้า', 'Search products')); ?></label><div class="relative"><i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i><input name="q" value="<?php echo $h($query['q']); ?>" class="field w-full pl-9" placeholder="<?php echo $h($t('ชื่อสินค้า ระยะเวลา หมวดหมู่ หรือ ID', 'Name, duration, category or ID')); ?>"></div></div>
<div><label class="text-xs text-gray-400 block mb-1"><?php echo $h($t('หมวดหมู่', 'Category')); ?></label><select name="category" class="field w-full"><option value=""><?php echo $h($t('ทุกหมวดหมู่', 'All categories')); ?></option><?php foreach ($categories as $categoryName): ?><option value="<?php echo $h($categoryName); ?>" <?php echo $query['category'] === $categoryName ? 'selected' : ''; ?>><?php echo $h($categoryName); ?></option><?php endforeach; ?></select></div>
<div><label class="text-xs text-gray-400 block mb-1"><?php echo $h($t('สถานะสต็อก', 'Stock status')); ?></label><select name="stock" class="field w-full"><option value="all"><?php echo $h($t('ทุกสถานะ', 'All stock')); ?></option><option value="in_stock" <?php echo $query['stock'] === 'in_stock' ? 'selected' : ''; ?>><?php echo $h($t('มีสต็อก', 'In stock')); ?></option><option value="out_of_stock" <?php echo $query['stock'] === 'out_of_stock' ? 'selected' : ''; ?>><?php echo $h($t('หมดสต็อก', 'Out of stock')); ?></option></select><input type="hidden" name="state" value="<?php echo $h($query['state']); ?>"></div>
<div class="flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i><?php echo $h($t('กรอง', 'Filter')); ?></button><a class="btn btn-soft" href="api_client_products.php?client_id=<?php echo $clientId; ?>"><i class="bi bi-x-circle"></i></a></div>
</form>

<div class="flex flex-wrap items-center justify-between gap-2 text-sm"><div class="text-gray-400"><?php echo $h($t('พบ', 'Showing')); ?> <span class="font-bold text-white"><?php echo number_format(count($rows)); ?></span> / <?php echo number_format(count($allRows)); ?> <?php echo $h($t('ตัวเลือกสินค้า', 'variants')); ?></div><?php if ($query['q'] !== '' || $query['category'] !== '' || $query['stock'] !== 'all' || $query['state'] !== 'all'): ?><a class="text-indigo-300 hover:text-indigo-200" href="api_client_products.php?client_id=<?php echo $clientId; ?>"><i class="bi bi-arrow-counterclockwise mr-1"></i><?php echo $h($t('ล้างตัวกรองทั้งหมด', 'Clear all filters')); ?></a><?php endif; ?></div>

<div class="glass rounded-xl overflow-hidden"><div class="overflow-auto max-h-[75vh]"><table class="w-full min-w-[1350px] text-sm"><thead class="sticky top-0 bg-gray-900 z-10"><tr><th class="p-3 text-left"><?php echo $h($t('สินค้าและหมวดหมู่', 'Product and categories')); ?></th><th class="p-3 text-right"><?php echo $h($t('ทุนจริง', 'Internal cost')); ?></th><th class="p-3 text-right"><?php echo $h($t('ราคาผู้ใช้', 'User price')); ?></th><th class="p-3 text-right"><?php echo $h($t('ราคาตัวแทน', 'Reseller price')); ?></th><th class="p-3 text-right"><?php echo $h($t('ราคา API ปกติ', 'Default API price')); ?></th><th class="p-3 text-right"><?php echo $h($t('ราคา API ที่ใช้', 'Final API price')); ?></th><th class="p-3 text-center"><?php echo $h($t('สต็อก', 'Stock')); ?></th><th class="p-3 text-left"><?php echo $h($t('จัดการ', 'Rule')); ?></th></tr></thead><tbody class="divide-y divide-white/5">
<?php foreach ($rows as $row): $hasStock = (int) $row['remote_stock'] > 0; ?>
<tr class="align-top <?php echo $hasStock ? '' : 'bg-red-950/10'; ?>"><td class="p-3"><div class="font-semibold"><?php echo $h($row['name']); ?></div><div class="text-xs text-gray-400">#<?php echo (int) $row['source_variant_id']; ?> · <?php echo $h($row['duration']); ?> · <?php echo $h($row['platform']); ?></div><?php if (!empty($row['categories'])): ?><div class="flex flex-wrap gap-1 mt-2"><?php foreach ($row['categories'] as $categoryName): ?><span class="rounded-full border border-indigo-400/20 bg-indigo-500/10 px-2 py-0.5 text-[10px] text-indigo-200"><?php echo $h($categoryName); ?></span><?php endforeach; ?></div><?php else: ?><div class="text-[10px] text-gray-600 mt-2"><?php echo $h($t('ยังไม่ได้กำหนดหมวดหมู่', 'No category assigned')); ?></div><?php endif; ?><div class="text-xs mt-2 <?php echo $row['product_status']==='active' && $row['variant_status']==='active' ? 'text-emerald-300':'text-red-300'; ?>"><?php echo $h($row['product_status'].' / '.$row['variant_status']); ?></div></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['cost_price'],2)); ?></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['price_user'],2)); ?></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['price_reseller'],2)); ?></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['default_api_price'],2)); ?></td><td class="p-3 text-right font-bold text-amber-200"><?php echo $h(number_format((float) $row['final_api_price'],2)); ?></td><td class="p-3 text-center"><span class="inline-flex rounded-full border px-2 py-1 text-xs font-bold <?php echo $hasStock ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-red-500/30 bg-red-500/10 text-red-300'; ?>"><?php echo number_format((int) $row['remote_stock']); ?> · <?php echo $h($hasStock ? $t('มีสต็อก','In stock') : $t('หมดสต็อก','Out of stock')); ?></span></td><td class="p-3 min-w-[390px]"><form method="post" class="flex flex-wrap items-center gap-2"><?php echo csrfField(); ?><input type="hidden" name="action" value="save_rule"><input type="hidden" name="client_id" value="<?php echo $clientId; ?>"><input type="hidden" name="variant_id" value="<?php echo (int) $row['source_variant_id']; ?>"><input type="hidden" name="q" value="<?php echo $h($query['q']); ?>"><input type="hidden" name="category" value="<?php echo $h($query['category']); ?>"><input type="hidden" name="stock" value="<?php echo $h($query['stock']); ?>"><input type="hidden" name="state" value="<?php echo $h($query['state']); ?>"><label class="text-xs"><input type="checkbox" name="enabled" <?php echo (int) $row['rule_enabled']===1?'checked':''; ?> class="mr-1"><?php echo $h($t('เปิดขาย', 'Enabled')); ?></label><input class="field w-36" type="number" step="0.01" min="0" name="custom_price" value="<?php echo $row['custom_price'] !== null ? $h(number_format((float) $row['custom_price'],2,'.','')) : ''; ?>" placeholder="<?php echo $h($t('ราคาพิเศษ', 'Custom price')); ?>"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึก', 'Save')); ?></button></form><form method="post" class="mt-2"><?php echo csrfField(); ?><input type="hidden" name="action" value="reset_rule"><input type="hidden" name="client_id" value="<?php echo $clientId; ?>"><input type="hidden" name="variant_id" value="<?php echo (int) $row['source_variant_id']; ?>"><input type="hidden" name="q" value="<?php echo $h($query['q']); ?>"><input type="hidden" name="category" value="<?php echo $h($query['category']); ?>"><input type="hidden" name="stock" value="<?php echo $h($query['stock']); ?>"><input type="hidden" name="state" value="<?php echo $h($query['state']); ?>"><button class="btn btn-soft !py-1 text-xs" type="submit"><i class="bi bi-arrow-counterclockwise"></i><?php echo $h($t('คืนค่าเริ่มต้น', 'Reset default')); ?></button></form></td></tr>
<?php endforeach; ?>
<?php if ($rows === []): ?><tr><td colspan="8" class="p-10 text-center text-gray-500"><i class="bi bi-search text-3xl block mb-3"></i><?php echo $h($t('ไม่พบสินค้าที่ตรงกับตัวกรอง', 'No products match these filters.')); ?></td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
<?php endif; ?>
</main>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body></html>
