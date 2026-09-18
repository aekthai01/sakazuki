<?php
/**
 * Shared CHEATGAME storefront template.
 * Required variables before include:
 *   $cgoNavFile, $cgoAccentClass, $cgoAccentBg, $cgoRoleLabel
 */
if (!isset($cgoNavFile, $cgoAccentClass, $cgoAccentBg, $cgoRoleLabel)) {
    http_response_code(500);
    exit('Storefront configuration error');
}

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string {
    return $isTh ? $th : $en;
};
$error = '';
$success = '';
$purchasedKeys = [];
$forceInventoryReload = false;
$inventoryReloadMessage = '';

if (!isset($_SESSION['cgo_purchase_tokens']) || !is_array($_SESSION['cgo_purchase_tokens'])) {
    $_SESSION['cgo_purchase_tokens'] = [];
}
foreach ($_SESSION['cgo_purchase_tokens'] as $token => $tokenData) {
    if (!is_array($tokenData) || (int) ($tokenData['expires_at'] ?? 0) < time()) {
        unset($_SESSION['cgo_purchase_tokens'][$token]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['buy_cgo_product'])) {
    requireCsrfToken();
    $productId = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $purchaseToken = isset($_POST['purchase_token']) && is_string($_POST['purchase_token']) ? trim($_POST['purchase_token']) : '';
    $tokenData = $purchaseToken !== '' ? ($_SESSION['cgo_purchase_tokens'][$purchaseToken] ?? null) : null;
    if ($purchaseToken !== '') unset($_SESSION['cgo_purchase_tokens'][$purchaseToken]);

    if (!is_array($tokenData) || (int) ($tokenData['product_id'] ?? 0) !== $productId || (int) ($tokenData['expires_at'] ?? 0) < time()) {
        $error = $t('คำขอสั่งซื้อหมดอายุหรือถูกส่งซ้ำ กรุณากดซื้อใหม่จากหน้านี้', 'The purchase request expired or was submitted twice. Submit it again from this page.');
    } else {
        $result = cgoPurchaseProduct($productId, (int) ($_SESSION['user_id'] ?? 0));
        if (!empty($result['success'])) {
            $purchasedKeys = isset($result['keys']) && is_array($result['keys']) ? $result['keys'] : [];
            $success = !empty($result['processing'])
                ? $t('ผู้ให้บริการรับคำสั่งซื้อแล้ว กำลังประมวลผล ตรวจสถานะในรายการด้านล่าง', 'The supplier accepted the order and is processing it. Check the order list below.')
                : $t('สั่งซื้อสำเร็จ', 'Order completed.');
        } else {
            $resultCode = (string) ($result['code'] ?? '');
            if ($resultCode === 'supplier_out_of_stock') {
                $error = $t('สต็อกหมดแล้ว รอแอดมินเติมของ', 'The supplier stock is sold out. Please wait for the administrator to restock it.');
            } elseif ($resultCode === 'supplier_stock_insufficient') {
                $available = max(0, (int) ($result['available_stock'] ?? 0));
                $error = $t(
                    'สต็อก API คงเหลือไม่พอตามจำนวนที่เลือก กรุณาเลือกใหม่ (คงเหลือล่าสุด ' . $available . ')',
                    'The latest API stock is lower than the selected quantity. Choose again (latest stock: ' . $available . ').'
                );
            } elseif ($resultCode === 'supplier_product_busy') {
                $error = $t(
                    'มีลูกค้าคนอื่นกำลังตรวจหรือซื้อสินค้านี้อยู่ ระบบยังไม่หักยอด กรุณารอสักครู่แล้วลองใหม่',
                    'Another customer is checking or buying this item. No balance was charged. Please try again shortly.'
                );
            } elseif ($resultCode === 'supplier_lock_unavailable') {
                $error = $t(
                    'ระบบล็อกคำสั่งซื้อระหว่างสองเว็บไซต์ไม่พร้อมใช้งาน จึงยกเลิกเพื่อความปลอดภัยและยังไม่หักยอด',
                    'The cross-site order lock is unavailable, so the purchase was stopped safely and no balance was charged.'
                );
            } else {
                $error = (string) ($result['message'] ?? $t('สั่งซื้อไม่สำเร็จ', 'Order failed.'));
            }
            $forceInventoryReload = !empty($result['reload_storefront']);
            if ($forceInventoryReload) $inventoryReloadMessage = $error;
        }
    }
}

$cgoHasApiProducts = cgoStorefrontHasEnabledApiProducts();
$products = cgoGetProductsForStorefront();
$storeRole = (string) ($_SESSION['role'] ?? 'user');
$products = array_values(array_filter($products, static function (array $product) use ($storeRole): bool {
    $price = $storeRole === 'reseller'
        ? (float) ($product['reseller_price_base'] ?? 0)
        : (float) ($product['user_price_base'] ?? 0);
    $cost = (float) ($product['cost_base'] ?? 0);
    return is_finite($price) && is_finite($cost) && $price > 0 && $price + 0.00001 >= $cost;
}));
$purchaseTokens = [];
foreach ($products as $tokenProduct) {
    $token = bin2hex(random_bytes(24));
    $productIdForToken = (int) ($tokenProduct['id'] ?? 0);
    if ($productIdForToken < 1) continue;
    $_SESSION['cgo_purchase_tokens'][$token] = [
        'product_id' => $productIdForToken,
        'expires_at' => time() + 1800,
    ];
    $purchaseTokens[$productIdForToken] = $token;
}
if (count($_SESSION['cgo_purchase_tokens']) > 200) {
    $_SESSION['cgo_purchase_tokens'] = array_slice($_SESSION['cgo_purchase_tokens'], -200, null, true);
}
$orders = cgoGetOrdersForUser((int) ($_SESSION['user_id'] ?? 0), 100);
$user = getCurrentUser();
$hasValidEmail = is_array($user) && filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$csrf = csrfField();
$inventoryCsrfToken = getCsrfToken();
$inventoryTtlSeconds = cgoInventoryCacheTtlSeconds();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($t('ร้านสินค้า API', 'API Product Store'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .key-box{word-break:break-all}
        .cgo-product-image{aspect-ratio:16/9;object-fit:cover;width:100%}
        .cgo-description{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.65}
        .cgo-details summary{cursor:pointer;list-style:none}
        .cgo-details summary::-webkit-details-marker{display:none}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include $cgoNavFile; ?>
<main class="p-4 md:p-6 max-w-7xl mx-auto space-y-5">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold flex items-center gap-2"><i class="bi bi-cloud-download <?php echo htmlspecialchars($cgoAccentClass, ENT_QUOTES, 'UTF-8'); ?>"></i><?php echo $t('สินค้า CHEATGAME API', 'CHEATGAME API Products'); ?></h1>
            <p class="text-gray-400 text-sm mt-1"><?php echo $t('ราคาจะแสดงเป็นเงินบาทเมื่อใช้ภาษาไทย และดอลลาร์เมื่อใช้ภาษาอังกฤษ', 'Prices are shown in Thai baht for Thai and US dollars for English.'); ?></p>
        </div>
        <a href="#orders" class="px-4 py-2 rounded-lg bg-white/5 border border-white/10 hover:bg-white/10 text-sm"><i class="bi bi-clock-history mr-2"></i><?php echo $t('ดูคำสั่งซื้อ', 'View orders'); ?></a>
    </div>

    <?php if (!$hasValidEmail): ?>
        <div class="glass border-yellow-500/30 text-yellow-200 rounded-xl p-4">
            <?php echo $t('บัญชีนี้ยังไม่มีอีเมลที่ถูกต้อง ต้องแก้ไขอีเมลในหน้าบัญชีก่อนสั่งซื้อ เพราะ API ปลายทางกำหนด customer_email', 'This account needs a valid email before ordering because the supplier API requires customer_email.'); ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?><div class="glass border-green-500/30 text-green-200 rounded-xl p-4"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="glass border-red-500/30 text-red-200 rounded-xl p-4"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <?php if ($cgoHasApiProducts): ?>
        <div class="glass border-cyan-500/30 bg-cyan-500/10 text-cyan-100 rounded-xl p-4">
            <i class="bi bi-shield-check mr-2"></i><?php echo $t(
                'สต็อก API ที่แสดงเป็นข้อมูลล่าสุดที่ระบบบันทึกไว้ ระบบจะตรวจสต็อกจริงกับผู้ให้บริการอีกครั้งก่อนหักยอดทุกคำสั่งซื้อ',
                'Displayed API stock is the latest saved snapshot. The system verifies live supplier stock again before charging every order.'
            ); ?>
        </div>
    <?php endif; ?>

    <?php if ($purchasedKeys): ?>
    <section class="glass rounded-xl p-5 border-green-500/30">
        <h2 class="font-semibold text-green-300 mb-3"><i class="bi bi-key mr-2"></i><?php echo $t('คีย์ที่ได้รับ', 'Delivered keys'); ?></h2>
        <div class="space-y-2">
            <?php foreach ($purchasedKeys as $key): ?>
                <div class="key-box bg-black/30 rounded-lg p-3 font-mono text-sm flex gap-3 items-start justify-between"><span><?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="copy-key text-blue-400" data-key="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-copy"></i></button></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php if (!$products): ?>
                <div class="glass rounded-xl p-8 text-center text-gray-400 md:col-span-2 xl:col-span-3"><i class="bi bi-box-seam text-4xl"></i><p class="mt-3"><?php echo $t('ยังไม่มีสินค้า API ที่เปิดขาย', 'No API products are enabled.'); ?></p></div>
            <?php endif; ?>
            <?php foreach ($products as $product):
                $role = (string) ($_SESSION['role'] ?? 'user');
                $price = $role === 'reseller' ? (float) $product['reseller_price_base'] : (float) $product['user_price_base'];
                $displayTitle = cgoProductDisplayTitle($product);
                $variantLabel = cgoProductVariantLabel($product);
                $imageUrl = cgoProductImageUrl($product);
                $platformLabel = trim((string) ($product['platform'] ?? ''));
                $stockValue = $product['remote_stock'] === null ? null : (int) $product['remote_stock'];
                $description = trim((string) ($product['description'] ?? ''));
            ?>
                <article class="glass rounded-xl overflow-hidden flex flex-col" data-cgo-card data-cgo-product-id="<?php echo (int) $product['id']; ?>">
                    <?php if ($imageUrl !== ''): ?>
                        <img class="cgo-product-image bg-black/20" src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                        <div class="cgo-product-image bg-gradient-to-br from-white/5 to-black/20 flex items-center justify-center text-gray-600">
                            <i class="bi bi-image text-5xl"></i>
                        </div>
                    <?php endif; ?>
                    <div class="p-5 flex flex-col flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-semibold text-lg text-white break-words"><?php echo htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                                <?php if ($variantLabel !== ''): ?><div class="text-sm text-violet-300 mt-1 font-medium"><?php echo htmlspecialchars($variantLabel, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                <div class="flex flex-wrap gap-2 mt-2 text-xs">
                                    <?php if ($platformLabel !== ''): ?><span class="px-2 py-1 rounded-full bg-blue-500/10 text-blue-300 border border-blue-500/20"><i class="bi bi-device-ssd mr-1"></i><?php echo htmlspecialchars(strtoupper($platformLabel), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                    <span data-cgo-stock-label class="px-2 py-1 rounded-full bg-white/5 text-gray-300 border border-white/10"><?php echo $t('สต็อกล่าสุดที่บันทึก', 'Last saved stock'); ?>: <span data-cgo-stock-value><?php echo $stockValue === null ? 'N/A' : (int) $stockValue; ?></span></span>
                                </div>
                            </div>
                            <span class="shrink-0 text-xs px-2 py-1 rounded-full bg-green-500/10 text-green-300 border border-green-500/20"><?php echo $t('ตรวจสต็อกก่อนซื้อ', 'Checked before purchase'); ?></span>
                        </div>

                        <?php if ($description !== ''): ?>
                            <details class="cgo-details mt-4 rounded-lg bg-black/20 border border-white/5">
                                <summary class="px-3 py-2 text-sm text-gray-200 flex items-center justify-between gap-2">
                                    <span><i class="bi bi-card-text mr-2"></i><?php echo $t('รายละเอียดสินค้า', 'Product details'); ?></span>
                                    <i class="bi bi-chevron-down text-xs"></i>
                                </summary>
                                <div class="cgo-description border-t border-white/5 px-3 py-3 text-sm text-gray-400"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></div>
                            </details>
                        <?php endif; ?>

                        <div class="mt-auto pt-5">
                            <div class="flex items-center justify-between mb-3"><span class="text-gray-400 text-sm"><?php echo $t('ราคา 1 ชิ้น', 'Price per item'); ?></span><span class="font-bold text-xl <?php echo htmlspecialchars($cgoAccentClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(formatCurrency($price), ENT_QUOTES, 'UTF-8'); ?></span></div>
                            <form method="POST" onsubmit="if (!confirm(<?php echo htmlspecialchars(json_encode($t('ยืนยันการสั่งซื้อ 1 ชิ้น? ระบบจะตรวจสต็อกจริงก่อนหักยอดและส่งคำสั่งไปยังผู้ให้บริการ', 'Confirm one item? Live stock will be checked before your balance is reserved and the order is sent to the supplier.'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)) return false; this.querySelector('button[type=submit]').disabled = true; return true;">
                                <?php echo $csrf; ?><input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>"><input type="hidden" name="purchase_token" value="<?php echo htmlspecialchars((string) ($purchaseTokens[(int) $product['id']] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="buy_cgo_product" value="1">
                                <button data-cgo-purchase-button data-cgo-product-id="<?php echo (int) $product['id']; ?>" data-cgo-email-valid="<?php echo $hasValidEmail ? '1' : '0'; ?>" data-cgo-stock="<?php echo max(0, (int) ($stockValue ?? 0)); ?>" <?php echo (!$hasValidEmail || (int) ($stockValue ?? 0) < 1) ? 'disabled' : ''; ?> class="w-full px-4 py-2.5 rounded-lg <?php echo htmlspecialchars($cgoAccentBg, ENT_QUOTES, 'UTF-8'); ?> text-white font-semibold disabled:opacity-40 disabled:cursor-not-allowed"><i class="bi bi-cart-check mr-2"></i><span data-cgo-button-label><?php echo (int) ($stockValue ?? 0) > 0 ? $t('ซื้อ 1 ชิ้น', 'Buy 1 item') : $t('สินค้าหมดชั่วคราว', 'Temporarily sold out'); ?></span></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="orders" class="glass rounded-xl overflow-hidden">
        <div class="p-5 border-b border-white/10"><h2 class="font-semibold text-white"><i class="bi bi-receipt mr-2"></i><?php echo $t('คำสั่งซื้อ API ของฉัน', 'My API orders'); ?></h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="bg-white/5 text-gray-400"><tr><th class="text-left p-3">#</th><th class="text-left p-3"><?php echo $t('สินค้า', 'Product'); ?></th><th class="text-left p-3"><?php echo $t('สถานะ', 'Status'); ?></th><th class="text-right p-3"><?php echo $t('ยอด', 'Amount'); ?></th><th class="text-left p-3"><?php echo $t('คีย์', 'Keys'); ?></th><th class="text-left p-3"><?php echo $t('เวลา', 'Time'); ?></th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if (!$orders): ?><tr><td colspan="6" class="p-8 text-center text-gray-500"><?php echo $t('ยังไม่มีคำสั่งซื้อ', 'No orders yet.'); ?></td></tr><?php endif; ?>
                <?php foreach ($orders as $order): ?>
                    <tr class="align-top">
                        <td class="p-3 font-mono"><?php echo (int) $order['id']; ?><div class="text-[10px] text-gray-600 mt-1"><?php echo htmlspecialchars((string) $order['external_ref'], ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td class="p-3"><?php echo htmlspecialchars((string) $order['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="p-3"><span class="px-2 py-1 rounded bg-white/5"><?php echo htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8'); ?></span><?php if (!empty($order['error_message'])): ?><div class="text-xs text-red-300 mt-2 max-w-sm"><?php echo htmlspecialchars((string) $order['error_message'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?></td>
                        <td class="p-3 text-right"><?php echo htmlspecialchars(formatCurrency((float) $order['total_price_base']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="p-3"><div class="space-y-1 max-w-sm"><?php if (empty($order['keys'])): ?><span class="text-gray-500">—</span><?php else: ?><?php foreach ($order['keys'] as $key): ?><div class="key-box font-mono text-xs bg-black/20 rounded p-2 flex justify-between gap-2"><span><?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="copy-key text-blue-400" data-key="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-copy"></i></button></div><?php endforeach; ?><?php endif; ?></div></td>
                        <td class="p-3 text-gray-400"><?php echo htmlspecialchars((string) $order['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php if ($cgoHasApiProducts): ?>
<script>
(function () {
    const productIds = Array.from(document.querySelectorAll('[data-cgo-card]')).map(function (card) {
        return parseInt(card.getAttribute('data-cgo-product-id') || '0', 10) || 0;
    }).filter(function (id) { return id > 0; });
    if (productIds.length < 1) return;

    const ttlSeconds = <?php echo (int) $inventoryTtlSeconds; ?>;
    let csrfToken = <?php echo json_encode($inventoryCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let timer = null;
    let backgroundInFlight = false;

    function applySnapshot(snapshot) {
        const rows = snapshot && typeof snapshot === 'object' ? snapshot : {};
        document.querySelectorAll('[data-cgo-card]').forEach(function (card) {
            const id = String(parseInt(card.getAttribute('data-cgo-product-id') || '0', 10) || 0);
            const row = rows[id];
            if (!row || typeof row !== 'object') return;
            const stock = row.available ? Math.max(0, parseInt(row.stock || '0', 10) || 0) : 0;
            const stockNode = card.querySelector('[data-cgo-stock-value]');
            if (stockNode) stockNode.textContent = String(stock);
            const button = card.querySelector('[data-cgo-purchase-button]');
            if (!button) return;
            button.dataset.cgoStock = String(stock);
            const emailValid = button.dataset.cgoEmailValid === '1';
            button.disabled = !emailValid || stock < 1;
            const label = button.querySelector('[data-cgo-button-label]');
            if (label) label.textContent = stock > 0
                ? <?php echo json_encode($t('ซื้อ 1 ชิ้น', 'Buy 1 item'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                : <?php echo json_encode($t('สินค้าหมดชั่วคราว', 'Temporarily sold out'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        });
    }

    async function renewToken() {
        try {
            const response = await fetch('../cgo_inventory.php?action=token', {
                credentials: 'same-origin', cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (response.ok && data && data.success && typeof data.csrf_token === 'string') {
                csrfToken = data.csrf_token;
                return true;
            }
        } catch (ignored) {}
        return false;
    }

    async function request(scope, retryToken) {
        const body = new URLSearchParams();
        body.set('scope', scope);
        body.set('product_ids', JSON.stringify(productIds));
        const response = await fetch('../cgo_inventory.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: scope === 'storefront_refresh',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: body.toString()
        });
        if (response.status === 403 && retryToken && await renewToken()) return request(scope, false);
        let data = null;
        try { data = await response.json(); } catch (ignored) {}
        return { response, data };
    }

    function triggerBackground() {
        if (backgroundInFlight) return;
        const key = 'cgo-direct-kick-v2:' + window.location.host;
        const now = Date.now();
        try {
            const last = parseInt(localStorage.getItem(key) || '0', 10) || 0;
            if (now - last < 15000) return;
            localStorage.setItem(key, String(now));
        } catch (ignored) {}
        backgroundInFlight = true;
        request('storefront_refresh', true).then(function (result) {
            if (result.response && result.response.ok && result.data && result.data.success) {
                applySnapshot(result.data.direct_snapshot || {});
                if (result.data.catalog_changed === true) window.location.reload();
            }
        }).catch(function () {}).finally(function () { backgroundInFlight = false; });
    }

    function schedule(ms) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(poll, Math.max(1000, ms || 0));
    }

    async function poll() {
        if (document.visibilityState === 'hidden') {
            schedule(5000);
            return;
        }
        try {
            const result = await request('storefront', true);
            if (result.response && result.response.ok && result.data && result.data.success) {
                applySnapshot(result.data.direct_snapshot || {});
                if (result.data.refresh_needed === true) {
                    triggerBackground();
                    schedule(4000);
                } else {
                    schedule(Math.max(30, Number(result.data.ttl_seconds || ttlSeconds)) * 1000);
                }
                return;
            }
        } catch (ignored) {}
        schedule(30000);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') poll();
    });
    schedule(800);
})();
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.copy-key').forEach(function (button) {
    button.addEventListener('click', async function () {
        const value = this.getAttribute('data-key') || '';
        try {
            await navigator.clipboard.writeText(value);
            this.innerHTML = '<i class="bi bi-check-lg"></i>';
            setTimeout(() => { this.innerHTML = '<i class="bi bi-copy"></i>'; }, 1200);
        } catch (e) {
            window.prompt('Copy key', value);
        }
    });
});
</script>


<?php if ($forceInventoryReload): ?>
<script>
(function () {
    const message = <?php echo json_encode($inventoryReloadMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (message) window.alert(message);
    window.location.replace(window.location.pathname + window.location.search);
})();
</script>
<?php endif; ?>

</body>
</html>
