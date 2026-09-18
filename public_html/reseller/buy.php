<?php
require_once '../includes/auth.php';
require_once '../includes/cheatgame.php';
require_once '../includes/announcement_marquee.php';
requireReseller();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$error = '';
$success = '';
$purchasedKey = '';
$purchasedKeys = [];
$forceInventoryReload = false;
$inventoryReloadMessage = '';
$purchaseScope = 'catalog-buy:' . (int) ($_SESSION['user_id'] ?? 0);
$purchaseRequestToken = '';
$purchasePendingActive = false;
$pendingOrderId = 0;
$pendingOrderSource = '';
$pendingWaitRemainingSeconds = 8;

// Get announcement text
$announcementText = getAnnouncementText();
$announcementTextColor = getAnnouncementTextColor();

// Storefront catalogue: filter and paginate in MySQL instead of loading every product into PHP.
$currentAccountId = (int) ($_SESSION['user_id'] ?? 0);
$currentStoreRole = 'reseller';

$selectedPlatform = isset($_GET['platform']) && is_scalar($_GET['platform'])
    ? strtolower(substr(trim((string) $_GET['platform']), 0, 60))
    : 'all';
$selectedCategory = isset($_GET['category']) && is_scalar($_GET['category'])
    ? (function_exists('mb_substr')
        ? mb_substr(trim((string) $_GET['category']), 0, 255, 'UTF-8')
        : substr(trim((string) $_GET['category']), 0, 255))
    : 'all';
$searchQuery = isset($_GET['search']) && is_scalar($_GET['search'])
    ? substr(trim((string) $_GET['search']), 0, 120)
    : '';
$storePage = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$storePerPage = 24;

$catalogueFacets = getStorefrontCatalogueFacets('active', $selectedPlatform);
$selectedPlatform = (string) ($catalogueFacets['selected_platform'] ?? 'all');
$allowedPlatforms = (array) ($catalogueFacets['allowed_platforms'] ?? ['all', 'android', 'ios', 'both', 'account']);
$categoryCounts = (array) ($catalogueFacets['category_counts'] ?? []);
$platformProductCount = max(0, (int) ($catalogueFacets['platform_product_count'] ?? 0));
$categories = array_keys($categoryCounts);

// Category display names are stored as JSON in the existing settings table.
$categoryTranslations = [];
$categoryTranslationsRaw = getSetting('category_translations_v1', '');
if (is_string($categoryTranslationsRaw) && trim($categoryTranslationsRaw) !== '') {
    $decodedCategoryTranslations = json_decode($categoryTranslationsRaw, true);
    if (is_array($decodedCategoryTranslations)) $categoryTranslations = $decodedCategoryTranslations;
}

$currentCategoryLang = getAppLang();
$categoryLabels = [];
foreach ($categories as $categoryName) {
    $label = $categoryName;
    $translationRow = $categoryTranslations[$categoryName] ?? null;
    if (is_array($translationRow)) {
        $candidate = $translationRow[$currentCategoryLang] ?? '';
        if (is_scalar($candidate)) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') $label = $candidate;
        }
    }
    $categoryLabels[$categoryName] = $label;
}
usort($categories, static function ($left, $right) use ($categoryLabels) {
    return strnatcasecmp((string) ($categoryLabels[$left] ?? $left), (string) ($categoryLabels[$right] ?? $right));
});
if ($selectedCategory !== 'all' && !array_key_exists($selectedCategory, $categoryCounts)) $selectedCategory = 'all';

// Preserve searching by translated category labels without filtering the full catalogue in PHP.
$searchCategoryAliases = [];
if ($searchQuery !== '') {
    foreach ($categoryLabels as $categoryName => $label) {
        $matched = function_exists('mb_stripos')
            ? mb_stripos((string) $label, $searchQuery, 0, 'UTF-8') !== false
            : stripos((string) $label, $searchQuery) !== false;
        if ($matched) $searchCategoryAliases[] = (string) $categoryName;
    }
}

$cataloguePage = getStorefrontProductsPage(
    'active',
    $selectedPlatform,
    $selectedCategory,
    $searchQuery,
    $storePage,
    $storePerPage,
    $searchCategoryAliases
);
$filteredProducts = (array) ($cataloguePage['rows'] ?? []);
$storePage = max(1, (int) ($cataloguePage['page'] ?? 1));
$storePages = max(1, (int) ($cataloguePage['pages'] ?? 1));
$storeTotal = max(0, (int) ($cataloguePage['total'] ?? 0));

$buildStoreUrl = static function (array $overrides = []) use ($selectedPlatform, $selectedCategory, $searchQuery, $storePage): string {
    $filterChanged = array_key_exists('platform', $overrides)
        || array_key_exists('category', $overrides)
        || array_key_exists('search', $overrides);
    $query = array_merge([
        'platform' => $selectedPlatform,
        'category' => $selectedCategory,
        'search' => $searchQuery,
        'page' => $storePage,
    ], $overrides);
    if ($filterChanged && !array_key_exists('page', $overrides)) $query['page'] = 1;
    $query = array_filter($query, static function ($value, $key) {
        if ($value === '') return false;
        if ($value === 'all' && in_array($key, ['platform', 'category'], true)) return false;
        if ($key === 'page' && (int) $value <= 1) return false;
        return true;
    }, ARRAY_FILTER_USE_BOTH);
    return $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '?';
};

// Handle purchase by duration as a single database transaction.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['purchase_duration'])) {
    requireCsrfToken();
    $postedPurchaseToken = isset($_POST['purchase_token']) && is_string($_POST['purchase_token'])
        ? trim($_POST['purchase_token'])
        : '';

    // CSRF proves request origin. The one-time token separately prevents double
    // taps and browser POST resubmission from creating duplicate orders.
    if (!cgoConsumePurchaseToken($purchaseScope, $postedPurchaseToken)) {
        $error = getAppLang() === 'th'
            ? 'คำขอสั่งซื้อหมดอายุหรือถูกส่งซ้ำ กรุณาเลือกสินค้าแล้วกดยืนยันใหม่'
            : 'The purchase request expired or was submitted twice. Select the product and confirm again.';
    } else {
        $duration = isset($_POST['duration_group']) && is_string($_POST['duration_group']) ? trim($_POST['duration_group']) : '';
        $variantId = isset($_POST['variant_id_group']) && is_scalar($_POST['variant_id_group']) ? max(0, (int) $_POST['variant_id_group']) : 0;
        $productId = isset($_POST['product_id_duration']) && is_scalar($_POST['product_id_duration']) ? (int) $_POST['product_id_duration'] : 0;
        $quantity = isset($_POST['quantity']) && is_scalar($_POST['quantity']) ? (int) $_POST['quantity'] : 1;
        $purchase = cgoPurchaseUnifiedVariant($productId, $duration, $quantity, (int) $_SESSION['user_id'], $variantId);
        $purchasedKeys = isset($purchase['keys']) && is_array($purchase['keys']) ? $purchase['keys'] : [];
        $remoteSource = strtolower(trim((string) ($purchase['source'] ?? '')));
        $remoteOrderId = max(0, (int) ($purchase['order_id'] ?? 0));
        $remotePending = $remoteOrderId > 0
            && in_array($remoteSource, ['cgo', 'supplier'], true)
            && (!empty($purchase['pending'])
                || !empty($purchase['processing'])
                || (!empty($purchase['success']) && $purchasedKeys === []));

        if ($remotePending) {
            $purchasePendingActive = true;
            $pendingOrderId = $remoteOrderId;
            $pendingOrderSource = $remoteSource;
            $pendingState = $remoteSource === 'supplier'
                ? supplierBridgeGetStorefrontOrderState($remoteOrderId, (int) $_SESSION['user_id'], false)
                : cgoGetStorefrontOrderState($remoteOrderId, (int) $_SESSION['user_id'], false);
            if (!empty($pendingState['success'])) {
                $pendingWaitRemainingSeconds = max(1, min(18, (int) ($pendingState['deadline_remaining_seconds'] ?? 8)));
            }
            $totalPrice = (float) ($purchase['total'] ?? 0);
            $error = cgoStorefrontPurchaseErrorMessage(array_merge($purchase, ['pending' => true]));
            $success = '';
        } elseif (!empty($purchase['success'])) {
            $purchasedKey = implode("\n", $purchasedKeys);
            $totalPrice = (float) ($purchase['total'] ?? 0);
            $success = $purchasedKeys !== []
                ? Lang::t('buy.success.purchase', ['count' => count($purchasedKeys), 'total' => formatCurrency($totalPrice)])
                : (string) ($purchase['message'] ?? 'Order accepted and is processing');
            $dlInfo = getProductDownloadUrl($productId);
            $downloadUrl = $dlInfo['url'] ?? null;
            $firstCategory = $dlInfo['category'] ?? '';
        } else {
            $error = cgoStorefrontPurchaseErrorMessage($purchase);
            $forceInventoryReload = !empty($purchase['reload_storefront']);
            if ($forceInventoryReload) $inventoryReloadMessage = $error;
        }
    }
}

// Load stock only after purchase handling so the response reflects a key that
// was just consumed, while still using one bulk query set for the whole page.
$storefrontProductIds = [];
foreach ($filteredProducts as $storefrontProduct) {
    $storefrontProductId = (int) ($storefrontProduct['id'] ?? 0);
    if ($storefrontProductId > 0) $storefrontProductIds[] = $storefrontProductId;
}
$storefrontVariantMap = cgoGetUnifiedStoreVariantsForProducts(
    $storefrontProductIds,
    $currentStoreRole,
    $currentAccountId,
    true
);

$isInstantFilterRequest = isset($_SERVER['HTTP_X_INSTANT_FILTER'])
    && hash_equals('1', trim((string) $_SERVER['HTTP_X_INSTANT_FILTER']));
if (!$isInstantFilterRequest) {
    $purchaseRequestToken = cgoIssuePurchaseToken($purchaseScope);
}
if (!$isInstantFilterRequest && $purchaseRequestToken === '' && $error === '') {
    $error = getAppLang() === 'th'
        ? 'ไม่สามารถสร้างรหัสป้องกันคำสั่งซื้อซ้ำได้ กรุณารีเฟรชหน้า'
        : 'Unable to create the duplicate-order protection token. Refresh the page.';
}

$recentPurchaseActivity = $isInstantFilterRequest ? [] : getPublicRecentPurchaseActivity(10);

$cgoHasApiProducts = cgoStorefrontHasEnabledApiProducts();
$cgoInventoryPollingEnabled = $cgoHasApiProducts && !$isInstantFilterRequest;
$cgoInventoryState = cgoInventoryState();
$cgoInventoryTtlSeconds = cgoInventoryCacheTtlSeconds();
$cgoInventoryAgeSeconds = is_int($cgoInventoryState['age_seconds'] ?? null)
    ? max(0, (int) $cgoInventoryState['age_seconds'])
    : $cgoInventoryTtlSeconds;
$cgoInventoryInitialDelayMs = min(1200, $cgoInventoryAgeSeconds >= $cgoInventoryTtlSeconds
    ? 0
    : max(1000, ($cgoInventoryTtlSeconds - $cgoInventoryAgeSeconds) * 1000));
$cgoInventoryCsrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="buy.title"><?php echo Lang::t('buy.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .product-image-small {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .category-filter {
            scrollbar-width: thin;
            scrollbar-color: #22c55e transparent;
        }

        .category-filter::-webkit-scrollbar {
            height: 3px;
        }

        .category-filter::-webkit-scrollbar-track {
            background: transparent;
        }

        .category-filter::-webkit-scrollbar-thumb {
            background-color: #22c55e;
            border-radius: 2px;
        }



        /* Category pill pressed/active state (Tap kategori) */
        .category-filter a:active {
            background: rgba(255, 255, 255, 0.14) !important;
            border-color: rgba(255, 255, 255, 0.35) !important;
        }

        .category-filter a:focus-visible {
            outline: 2px solid rgba(34, 197, 94, 0.55);
            outline-offset: 2px;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(5px);
            z-index: 1000;
            display: none;
        }

        .variant-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1001;
            width: 90%;
            max-width: 500px;
            display: none;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translate(-50%, -40%);
            }

            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }

        .animate-slide-up {
            animation: slideUp .15s ease-out;
        }


        /* Motion feedback restored: modals remain GPU-friendly and visible on touch devices. */
        .animate-slide-up { animation: none; }
        .overlay {
            display: none;
            opacity: 0;
            visibility: hidden;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            transition: opacity .15s ease, visibility 0s linear .15s;
            will-change: opacity;
            z-index: 11000;
        }
        .overlay.is-visible {
            opacity: 1;
            visibility: visible;
            transition-delay: 0s;
        }
        .overlay.is-closing { opacity: 0; }
        .variant-modal {
            display: none;
            opacity: 0;
            visibility: hidden;
            transform: translate3d(-50%, -44%, 0) scale(.96);
            transition: opacity .15s ease, transform .15s cubic-bezier(.22, 1, .36, 1), visibility 0s linear .15s;
            will-change: transform, opacity;
            z-index: 11001;
        }
        .variant-modal.is-visible {
            opacity: 1;
            visibility: visible;
            transform: translate3d(-50%, -50%, 0) scale(1);
            transition-delay: 0s;
        }
        .variant-modal.is-closing {
            opacity: 0;
            transform: translate3d(-50%, -46%, 0) scale(.97);
        }
        .purchase-success-pop.is-visible {
            animation: purchase-success-pop .15s cubic-bezier(.16, 1, .3, 1) both;
        }
        .purchase-processing-overlay {
            position: fixed;
            inset: 0;
            z-index: 12050;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            background: rgba(5, 8, 18, .88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .15s ease, visibility 0s linear .15s;
        }
        .purchase-processing-overlay.is-visible {
            opacity: 1;
            visibility: visible;
            transition-delay: 0s;
        }
        .purchase-processing-card {
            width: min(92vw, 320px);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 18px;
            background: rgba(27, 35, 54, .96);
            padding: 1.35rem;
            text-align: center;
            box-shadow: 0 20px 55px rgba(0,0,0,.42);
            transform: translate3d(0, 12px, 0) scale(.96);
            opacity: 0;
            transition: opacity .15s ease, transform .15s cubic-bezier(.22,1,.36,1);
        }
        .purchase-processing-overlay.is-visible .purchase-processing-card {
            opacity: 1;
            transform: translate3d(0, 0, 0) scale(1);
        }
        .purchase-processing-spinner,
        .purchase-button-spinner {
            display: inline-block;
            border-radius: 999px;
            border-style: solid;
            border-color: rgba(255,255,255,.24);
            border-top-color: #ffffff;
            animation: purchase-spin .72s linear infinite;
        }
        .purchase-processing-spinner {
            width: 2.8rem;
            height: 2.8rem;
            border-width: 3px;
            margin-bottom: .9rem;
            filter: drop-shadow(0 0 10px rgba(99,102,241,.45));
        }
        .purchase-button-spinner {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
        }
        @keyframes purchase-spin { to { transform: rotate(360deg); } }
        @keyframes purchase-success-pop {
            0% { opacity: 0; transform: translate3d(-50%, -43%, 0) scale(.9); }
            65% { opacity: 1; transform: translate3d(-50%, -51%, 0) scale(1.018); }
            100% { opacity: 1; transform: translate3d(-50%, -50%, 0) scale(1); }
        }
        
        /* Product item styles */
        .product-item {
            transition: transform .15s cubic-bezier(.22, 1, .36, 1), border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .product-item:hover {
            background: rgba(34, 197, 94, 0.05);
            border-color: rgba(34, 197, 94, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-select {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        .btn-select:hover {
            background: rgba(34, 197, 94, 0.2);
            border-color: rgba(34, 197, 94, 0.5);
        }

        .desc-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Announcement marquee */
        .marquee-container {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            min-height: 1.75rem;
            display: flex;
            align-items: center;
            contain: layout paint;
        }

        .marquee-content {
            display: inline-flex;
            align-items: center;
            width: max-content;
            max-width: none;
            white-space: nowrap;
            color: var(--announcement-text-color, #93C5FD);
            will-change: transform;
            transform: translate3d(0, 0, 0);
        }

        .marquee-container[data-marquee-state="waiting"] .marquee-content {
            transform: translate3d(0, 0, 0);
        }

        @keyframes announcementMarqueeFallback {
            from { transform: translate3d(var(--marquee-start, 100vw), 0, 0); }
            to { transform: translate3d(var(--marquee-end, -100%), 0, 0); }
        }

        .marquee-content.announcement-marquee-fallback {
            animation: announcementMarqueeFallback var(--marquee-duration, 20s) linear infinite !important;
        }

        .marquee-container:hover .marquee-content.announcement-marquee-fallback,
        .marquee-container:focus-within .marquee-content.announcement-marquee-fallback {
            animation-play-state: paused !important;
        }


        /* Key list styles */
        .key-list-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 8px;
            font-family: monospace;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .key-list-item:last-child {
            margin-bottom: 0;
        }

        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="p-4">
        <!-- Alerts -->
        <?php if ($error): ?>
            <div id="purchaseStatusAlert" class="glass p-3 rounded-lg text-sm mb-4 <?php echo $purchasePendingActive
                ? 'border border-amber-500/30 bg-amber-900/10 text-amber-200'
                : 'border border-red-500/30 bg-red-900/10 text-red-300'; ?>">
                <i class="bi <?php echo $purchasePendingActive ? 'bi-arrow-repeat' : 'bi-exclamation-circle'; ?> mr-2"></i>
                <span id="purchaseStatusMessage"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="glass border border-green-500/30 p-3 rounded-lg bg-green-900/10 text-green-300 text-sm mb-4">
                <i class="bi bi-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($cgoHasApiProducts): ?>
            <div id="inventoryRefreshBanner" class="glass border border-cyan-500/30 p-3 rounded-lg bg-cyan-900/10 text-cyan-200 text-sm mb-4">
                <i data-inventory-icon class="bi bi-shield-check mr-2"></i>
                <span data-inventory-message><?php echo getAppLang() === 'th'
                    ? 'ระบบจะอัปเดตสต็อกสินค้าอัตโนมัติทุกประมาณ ' . (int) $cgoInventoryTtlSeconds . ' วินาที และต้นทางจะยืนยันสต็อกจริงตอนส่งคำสั่งซื้อ'
                    : 'Stock updates automatically about every ' . (int) $cgoInventoryTtlSeconds . ' seconds; the supplier validates final availability when the order is submitted.'; ?></span>
            </div>
        <?php endif; ?>

        <!-- Announcement Marquee -->
        <?php if ($announcementText): ?>
            <div class="glass rounded-lg p-2 mb-4">
                <div class="marquee-container" data-announcement-marquee data-marquee-speed="52">
                    <div class="marquee-content text-sm" data-announcement-track style="--announcement-text-color: <?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>; color: <?php echo htmlspecialchars($announcementTextColor, ENT_QUOTES, 'UTF-8'); ?>;">
                        <i class="bi bi-megaphone-fill mr-2 text-yellow-400"></i>
                        <?php echo $announcementText; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isInstantFilterRequest): ?>
            <div class="mb-4">
                <?php
                $purchaseActivityAccent = 'green';
                $purchaseActivityEndpoint = '../purchase_activity.php';
                include __DIR__ . '/../includes/purchase_activity.php';
                ?>
            </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div id="storefrontLiveCatalog" class="space-y-4">
        <div class="mb-6 space-y-4">
            <div class="glass rounded-lg p-3">
                <form method="GET" action="" class="flex flex-col sm:flex-row gap-2">
                    <div class="relative flex-1">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                            data-lang-placeholder="buy.search_placeholder"
                            placeholder="<?php echo Lang::t('buy.search_placeholder'); ?>"
                            class="w-full pl-9 pr-4 py-2 bg-transparent border border-white/20 rounded text-white placeholder-gray-400 focus:border-green-500 focus:ring-1 focus:ring-green-500 outline-none text-sm">
                    </div>
                    <?php if ($selectedPlatform !== 'all'): ?>
                        <input type="hidden" name="platform" value="<?php echo htmlspecialchars($selectedPlatform, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <?php if ($selectedCategory !== 'all'): ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <button type="submit" class="px-3 py-2 bg-green-500 hover:bg-green-600 text-white rounded text-sm flex items-center justify-center gap-1">
                        <i class="bi bi-search text-xs"></i>
                        <span data-lang="dashboard.search.button"><?php echo Lang::t('dashboard.search.button'); ?></span>
                    </button>
                    <?php if ($searchQuery !== '' || $selectedCategory !== 'all' || $selectedPlatform !== 'all'): ?>
                        <a href="<?php echo htmlspecialchars($buildStoreUrl(['platform' => 'all', 'category' => 'all', 'search' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="px-3 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded text-sm flex items-center justify-center gap-1">
                            <i class="bi bi-x-circle text-xs"></i>
                            <span data-lang="dashboard.clear_filter"><?php echo Lang::t('dashboard.clear_filter'); ?></span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Platform Filter: shared with the admin catalogue -->
            <div class="glass rounded-lg p-3">
                <div class="flex items-center gap-2 mb-2">
                    <i class="bi bi-phone text-green-400 text-xs"></i>
                    <span class="text-xs font-medium text-gray-300" data-lang="catalog.platform_title"><?php echo htmlspecialchars(Lang::t('catalog.platform_title'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="category-filter overflow-x-auto pb-1">
                    <div class="flex gap-1.5 min-w-max">
                        <?php
                        $platformOptions = [
                            'all' => [Lang::t('catalog.all_platforms'), 'bi-grid'],
                            'android' => ['Android', 'bi-android2'],
                            'ios' => ['iOS', 'bi-apple'],
                            'both' => [Lang::t('catalog.both_platforms'), 'bi-phone'],
                            'account' => [Lang::t('catalog.account_products'), 'bi-person-badge'],
                        ];
                        foreach ($allowedPlatforms as $dynamicPlatform) {
                            if (isset($platformOptions[$dynamicPlatform])) continue;
                            $platformOptions[$dynamicPlatform] = [ucwords(str_replace(['_', '-'], ' ', $dynamicPlatform)), 'bi-box'];
                        }
                        foreach ($platformOptions as $platformKey => $platformMeta):
                        ?>
                            <a href="<?php echo htmlspecialchars($buildStoreUrl(['platform' => $platformKey, 'category' => 'all']), ENT_QUOTES, 'UTF-8'); ?>"
                                class="px-3 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap border border-white/20 <?php echo $selectedPlatform === $platformKey ? 'bg-green-500 text-white border-green-400/50' : 'bg-white/5 text-gray-300 hover:bg-white/10'; ?>">
                                <i class="bi <?php echo htmlspecialchars($platformMeta[1], ENT_QUOTES, 'UTF-8'); ?> mr-1"></i>
                                <?php echo htmlspecialchars($platformMeta[0], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Category Filter: generated from the same active products for every role -->
            <?php if (!empty($categories)): ?>
                <div class="glass rounded-lg p-3">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="bi bi-filter text-green-400 text-xs"></i>
                        <span class="text-xs font-medium text-gray-300" data-lang="catalog.category_title"><?php echo htmlspecialchars(Lang::t('catalog.category_title'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="category-filter overflow-x-auto pb-1">
                        <div class="flex gap-1.5 min-w-max">
                            <a href="<?php echo htmlspecialchars($buildStoreUrl(['category' => 'all']), ENT_QUOTES, 'UTF-8'); ?>"
                                class="px-3 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap border border-white/20 <?php echo $selectedCategory === 'all' ? 'bg-green-500 text-white border-green-400/50' : 'bg-white/5 text-gray-300 hover:bg-white/10'; ?>" data-lang="buy.category_all">
                                <?php echo Lang::t('buy.category_all'); ?>
                                <span class="ml-1 opacity-70"><?php echo $platformProductCount; ?></span>
                            </a>
                            <?php foreach ($categories as $category): ?>
                                <a href="<?php echo htmlspecialchars($buildStoreUrl(['category' => $category]), ENT_QUOTES, 'UTF-8'); ?>"
                                    class="px-3 py-2 rounded-lg text-xs font-bold transition whitespace-nowrap border border-white/20 <?php echo $selectedCategory === $category ? 'bg-green-500 text-white border-green-400/50' : 'bg-white/5 text-gray-300 hover:bg-white/10'; ?>">
                                    <?php echo htmlspecialchars((string) ($categoryLabels[$category] ?? $category), ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="ml-1 opacity-70"><?php echo (int) ($categoryCounts[$category] ?? 0); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Products Grid - Desktop: 4 columns, Mobile: 1 column -->
        <div class="mb-8">
            <?php if (empty($filteredProducts)): ?>
                <div class="glass rounded-lg p-10 text-center">
                    <i class="bi bi-search text-3xl text-gray-400 mb-2"></i>
                    <p class="text-gray-400 text-sm" data-lang="buy.no_products_found"><?php echo Lang::t('buy.no_products_found'); ?></p>
                    <?php if ($searchQuery !== '' || $selectedCategory !== 'all' || $selectedPlatform !== 'all'): ?>
                        <a href="<?php echo htmlspecialchars($buildStoreUrl(['platform' => 'all', 'category' => 'all', 'search' => '']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="inline-block mt-3 px-3 py-1.5 bg-green-500 hover:bg-green-600 text-white rounded text-sm transition" data-lang="buy.clear_filters_btn"><?php echo Lang::t('buy.clear_filters_btn'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-4">
                    <?php foreach ($filteredProducts as $product): ?>
                        <?php
                        // Merge local stock with the mapped CHEATGAME fallback.
                        // Local keys always take priority over supplier inventory.
                        $variants = $storefrontVariantMap[(int) $product['id']] ?? [];

                        // Handle Fallback Image
                        $bg = !empty($product['image']) ? $product['image'] : '';
                        $fallback = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='640' height='440'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop stop-color='%231f1033'/%3E%3Cstop offset='1' stop-color='%230c0c12'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='100%25' height='100%25' fill='url(%23g)'/%3E%3Ctext x='50%25' y='52%25' dominant-baseline='middle' text-anchor='middle' fill='%23ffffff' font-family='Arial' font-size='30' opacity='0.5'%3E%3C/text%3E%3C/svg%3E";
                        $imgRaw = $product['image'] ?? '';
                        if (empty($imgRaw)) {
                            $imgSrc = $fallback;
                        } else if (preg_match('/^(https?:\/\/|data:|\/\/)/i', $imgRaw)) {
                            $imgSrc = $imgRaw;
                        } else {
                            $imgSrc = '../' . ltrim($imgRaw, '/');
                        }

                        $productPlatform = normalizeProductPlatform((string) ($product['platform'] ?? ''), 'other');
                        if ($productPlatform === 'ios') {
                            $platformStr = 'iOS';
                            $platformColor = 'bg-sky-500';
                        } elseif ($productPlatform === 'both') {
                            $platformStr = 'Android + iOS';
                            $platformColor = 'bg-violet-600';
                        } elseif ($productPlatform === 'android') {
                            $platformStr = 'Android';
                            $platformColor = 'bg-emerald-600';
                        } elseif ($productPlatform === 'account') {
                            $platformStr = 'Account';
                            $platformColor = 'bg-amber-600';
                        } else {
                            $platformStr = strtoupper(str_replace('_', ' ', $productPlatform));
                            $platformColor = 'bg-slate-600';
                        }
                        ?>

                        <div class="bg-[#1b2336] rounded-[14px] border border-white/5 p-4 flex flex-col gap-4 shadow-sm">

                            <div class="flex gap-4 items-start">
                                <div class="relative flex-shrink-0">
                                    <img src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="w-[72px] h-[72px] object-cover rounded-[10px] border border-white/5"
                                        alt="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                        onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8'); ?>';">
                                    <span
                                        class="absolute -bottom-2 -right-2 <?php echo $platformColor; ?> text-white text-[10px] font-bold px-2.5 py-0.5 rounded-full z-10">
                                        <?php echo $platformStr; ?>
                                    </span>
                                </div>

                                <div class="flex-grow pt-1">
                                    <h3 class="text-[#facc15] font-semibold text-sm tracking-wide uppercase mb-1.5">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </h3>
                                    <p class="text-[#8e9bb0] text-xs leading-relaxed line-clamp-2 pr-2">
                                        <?php echo htmlspecialchars($product['description'] ?? ''); ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
                                <details class="rounded-[10px] border border-white/5 bg-black/10 px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-medium text-blue-300 hover:text-blue-200">
                                        <?php echo getAppLang() === 'th' ? 'ดูรายละเอียดสินค้าทั้งหมด' : 'View full product details'; ?>
                                    </summary>
                                    <div class="mt-2 border-t border-white/5 pt-2 text-xs leading-relaxed text-[#aab3c4] whitespace-pre-wrap break-words"><?php echo htmlspecialchars((string) $product['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                </details>
                            <?php endif; ?>

                            <div class="flex flex-col gap-2 mt-2">
                                <?php if (empty($variants)): ?>
                                    <div
                                        class="bg-[#242b45] border border-white/5 rounded-[10px] p-3 text-center text-gray-500 text-sm font-medium" data-lang="buy.sold_out">
                                        <?php echo Lang::t('buy.sold_out'); ?>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($variants as $vData): ?>
                                        <?php
                                        $duration = (string) ($vData['duration'] ?? 'Standard');
                                        $variantId = (int) ($vData['variant_id'] ?? 0);
                                        $isRemote = in_array((string) ($vData['source'] ?? 'local'), ['api', 'cgo', 'supplier', 'store_api'], true);
                                        $isAvailable = !$isRemote || (!empty($vData['available']) && (int) ($vData['count'] ?? 0) > 0);
                                        ?>
                                        <button type="button"
                                            data-inventory-source="<?php echo $isRemote ? 'remote' : 'local'; ?>"
                                            data-inventory-variant-id="<?php echo $variantId; ?>"
                                            data-inventory-stock="<?php echo (int) ($vData['count'] ?? 0); ?>"
                                            <?php echo $isAvailable ? '' : 'disabled'; ?>
                                            onclick="selectVariant(<?php echo htmlspecialchars(json_encode($duration, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $variantId; ?>, <?php echo (int)$product['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$product['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)$vData['count']; ?>, <?php echo json_encode((float)$vData['price']); ?>, this)"
                                            class="w-full bg-[#242b45] hover:bg-[#2a3454] border border-white/5 hover:border-[#3b82f6]/40 transition-colors duration-150 rounded-[10px] py-2.5 px-4 flex justify-between items-center group <?php echo $isAvailable ? '' : 'opacity-50 cursor-not-allowed'; ?>">

                                            <span data-inventory-normal <?php echo $isAvailable ? '' : 'hidden'; ?> class="flex justify-between items-center w-full">
                                                <span class="text-[#b4bccc] text-[13px] font-medium group-hover:text-white transition-colors">
                                                    <?php echo htmlspecialchars($product['name'] . ' - ' . $duration); ?>
                                                </span>
                                                <span class="text-white font-bold text-[13px]">
                                                    <?php echo formatCurrency($vData['price']); ?>
                                                </span>
                                            </span>
                                            <?php if ($isRemote): ?>
                                                <span data-inventory-soldout <?php echo $isAvailable ? 'hidden' : ''; ?> class="w-full text-center text-gray-400 text-[13px] font-medium">
                                                    <?php echo getAppLang() === 'th' ? 'สินค้าหมดชั่วคราว' : 'Temporarily sold out'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($storePages > 1): ?>
                    <nav class="mt-5 flex flex-wrap items-center justify-center gap-2" aria-label="Store pages">
                        <?php if ($storePage > 1): ?>
                            <a href="<?php echo htmlspecialchars($buildStoreUrl(['page' => $storePage - 1]), ENT_QUOTES, 'UTF-8'); ?>"
                               class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-sm text-gray-200 hover:bg-green-500/20">
                                <?php echo getAppLang() === 'th' ? 'ก่อนหน้า' : 'Previous'; ?>
                            </a>
                        <?php endif; ?>
                        <?php
                        $pageStart = max(1, $storePage - 2);
                        $pageEnd = min($storePages, $storePage + 2);
                        for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
                        ?>
                            <a href="<?php echo htmlspecialchars($buildStoreUrl(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8'); ?>"
                               class="min-w-9 px-3 py-2 rounded-lg border text-center text-sm <?php echo $pageNumber === $storePage ? 'bg-green-500 text-white border-white/20' : 'bg-white/5 text-gray-300 border-white/10 hover:bg-green-500/20'; ?>">
                                <?php echo $pageNumber; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($storePage < $storePages): ?>
                            <a href="<?php echo htmlspecialchars($buildStoreUrl(['page' => $storePage + 1]), ENT_QUOTES, 'UTF-8'); ?>"
                               class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-sm text-gray-200 hover:bg-green-500/20">
                                <?php echo getAppLang() === 'th' ? 'ถัดไป' : 'Next'; ?>
                            </a>
                        <?php endif; ?>
                    </nav>
                    <div class="mt-2 text-center text-xs text-gray-500">
                        <?php echo getAppLang() === 'th'
                            ? 'หน้า ' . $storePage . ' / ' . $storePages . ' · ทั้งหมด ' . $storeTotal . ' รายการ'
                            : 'Page ' . $storePage . ' / ' . $storePages . ' · ' . $storeTotal . ' products'; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        </div>
    </main>

    <div class="overlay" id="overlay" onclick="closeQuantityModal()" aria-hidden="true"></div>

    <div class="variant-modal bg-[#1b2336] rounded-[16px] overflow-hidden animate-slide-up shadow-2xl"
        id="quantityModal" style="max-width: 340px;" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">

        <div class="p-5 pb-2 flex justify-between items-center">
            <h5 class="font-bold text-white text-[16px]" data-lang="buy.confirm_purchase_title">
                <?php echo Lang::t('buy.confirm_purchase_title'); ?>
            </h5>
            <button type="button" aria-label="Close" onclick="closeQuantityModal()" class="text-[#8e9bb0] hover:text-white text-xl transition-colors">
                <i class="bi bi-x"></i>
            </button>
        </div>

        <div class="p-5 pt-3">
            <div class="text-center mb-6">
                <h4 class="font-bold text-white text-[16px]" id="qtyCombinedName"></h4>
            </div>

            <div class="space-y-4 mb-6">
                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.price_per_item"><?php echo Lang::t('buy.price_per_item'); ?>:</span>
                    <div class="flex items-center gap-2">
                        <span class="text-[#22c55e] font-bold text-[15px]" id="qtyPricePerKey"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.quantity"><?php echo Lang::t('buy.quantity'); ?>:</span>
                    <div class="flex items-center bg-[#242b45] rounded-[8px] p-0.5">
                        <button type="button"
                            class="text-[#8e9bb0] hover:text-white w-8 h-8 flex items-center justify-center transition-colors"
                            onclick="changeQuantity(-1)">
                            <i class="bi bi-dash"></i>
                        </button>
                        <input type="number"
                            class="bg-transparent w-8 text-center text-white text-[14px] focus:outline-none font-medium p-0"
                            id="quantityInput" value="1" min="1" oninput="updateTotalAmount()"
                            style="-moz-appearance: textfield;">
                        <button type="button"
                            class="text-[#8e9bb0] hover:text-white w-8 h-8 flex items-center justify-center transition-colors"
                            onclick="changeQuantity(1)">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.total_price"><?php echo Lang::t('buy.total_price'); ?>:</span>
                    <span id="qtyTotalAmount" class="text-[#22c55e] font-bold text-[15px]"></span>
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.balance_after"><?php echo Lang::t('buy.balance_after'); ?>:</span>
                    <span class="text-white font-medium text-[15px]" id="balanceAfter"></span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" id="confirmPurchaseButton" onclick="confirmPurchase()"
                    class="flex-1 bg-gradient-to-r from-[#6366f1] to-[#a855f7] hover:opacity-90 text-white py-2.5 rounded-[10px] font-medium transition-opacity text-[14px] flex items-center justify-center gap-2 shadow-sm">
                    <i class="bi bi-cart2"></i> <span data-lang="buy.confirm"><?php echo Lang::t('buy.confirm'); ?></span>
                </button>
                    <button type="button" onclick="closeQuantityModal()"
                        class="flex-1 bg-[#242b45] hover:bg-[#2a3454] text-[#8e9bb0] hover:text-white py-2.5 rounded-[10px] font-medium transition-colors text-[14px]" data-lang="buy.cancel"><?php echo Lang::t('buy.cancel'); ?>
                    </button>
            </div>
        </div>
    </div>



    <div id="purchaseProcessingOverlay" class="purchase-processing-overlay" role="status" aria-live="assertive" aria-hidden="true">
        <div class="purchase-processing-card">
            <span class="purchase-processing-spinner" aria-hidden="true"></span>
            <div class="text-white font-semibold text-base"><?php echo getAppLang() === 'en' ? 'Processing your purchase' : 'กำลังดำเนินการซื้อ'; ?></div>
            <div class="text-gray-400 text-sm mt-1"><?php echo getAppLang() === 'en' ? 'Please do not refresh or press the purchase button again.' : 'กรุณาอย่ารีเฟรชหรือกดซื้อซ้ำ'; ?></div>
        </div>
    </div>

    <form method="POST" id="quantityPurchaseForm" style="display:none;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="purchase_token" value="<?php echo htmlspecialchars($purchaseRequestToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="duration_group" id="qtyDuration">
        <input type="hidden" name="variant_id_group" id="qtyVariantId" value="0">
        <input type="hidden" name="product_id_duration" id="qtyProductId">
        <input type="hidden" name="quantity" id="qtyQuantity">
        <input type="hidden" name="purchase_duration" value="1">
    </form>

    <?php if (!empty($purchasedKeys) && is_array($purchasedKeys)): ?>
        <div class="overlay is-visible" id="successOverlay" style="display: flex;" aria-hidden="false"></div>
        <div class="variant-modal bg-[#1b2336] rounded-[16px] overflow-hidden animate-slide-up border border-white/10 shadow-2xl is-visible purchase-success-pop"
            id="successModal" style="display: block; max-width: 360px;" role="dialog" aria-modal="true" aria-hidden="false" tabindex="-1">

            <div class="p-5 flex justify-between items-center border-b border-white/5">
                <h5 class="font-medium text-white text-[16px]" data-lang="buy.purchase_successful">
                    <?php echo Lang::t('buy.purchase_successful'); ?>
                </h5>
                <button type="button" aria-label="Close" onclick="closeSuccessModal()"
                    class="text-gray-400 hover:text-white text-lg w-7 h-7 flex items-center justify-center rounded-[8px] transition-colors">
                    <i class="bi bi-x"></i>
                </button>
            </div>

            <div class="p-5">
                <div class="mb-4">
                    <div class="text-[#8e9bb0] text-[13px] mb-1" data-lang="buy.product_name"><?php echo Lang::t('buy.product_name'); ?></div>
                    <div class="text-white font-medium text-[15px]">
                        <?php
                        $productName = '';
                        if (isset($productId) && (int) $productId > 0) {
                            $purchasedProductRow = getProductById((int) $productId);
                            if (is_array($purchasedProductRow)) {
                                $productName = (string) ($purchasedProductRow['name'] ?? '');
                            }
                        }
                        // ดึงชื่อแพ็กเกจมาต่อท้ายให้เหมือนในรูป (เช่น DRAGON DFM iOS - 1 day)
                        $durText = isset($_POST['duration_group']) ? $_POST['duration_group'] : '';
                        $displayName = $productName;
                        if (!empty($durText)) {
                            $displayName .= ' - ' . $durText;
                        }
                        echo htmlspecialchars($displayName);
                        ?>
                    </div>
                </div>

                <div class="flex justify-between items-center mb-4">
                    <div class="text-[#8e9bb0] text-[13px]" data-lang="common.date"><?php echo Lang::t('common.date'); ?></div>
                    <div class="text-[#8e9bb0] text-[13px]">
                        <?php
                        date_default_timezone_set('Asia/Bangkok');
                        echo date(Lang::t('common.date_format'));
                        ?>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-[#8e9bb0] text-[13px] mb-2" data-lang="buy.license_keys"><?php echo Lang::t('buy.license_keys'); ?></div>
                    <div class="space-y-2 max-h-40 overflow-y-auto">
                        <?php foreach ($purchasedKeys as $key): ?>
                            <div
                                class="bg-[#13192b] border border-white/5 rounded-[8px] p-3 text-[#b4bccc] text-[14px] font-mono break-all flex items-center">
                                <?php echo htmlspecialchars($key); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-between items-end mb-6">
                    <div>
                        <div class="text-[#8e9bb0] text-[13px] mb-1" data-lang="buy.amount_paid"><?php echo Lang::t('buy.amount_paid'); ?></div>
                        <div class="text-[#22c55e] font-bold text-[16px]">
                            <?php echo formatCurrency($totalPrice ?? 0); ?>
                        </div>
                    </div>
                    <button type="button" onclick="copyAllKeys()"
                        class="bg-gradient-to-r from-[#6366f1] to-[#a855f7] hover:opacity-90 text-white px-4 py-2 rounded-[10px] text-[13px] font-medium flex items-center gap-2 transition-opacity">
                        <i class="bi bi-front"></i> <span data-lang="buy.copy_all"><?php echo Lang::t('buy.copy_all'); ?></span>
                    </button>
                </div>

                <div class="space-y-3">
                    <?php if (!empty($downloadUrl)): ?>
                        <a href="<?php echo htmlspecialchars($downloadUrl); ?>" target="_blank"
                            class="w-full bg-gradient-to-r from-[#ef4444] to-[#f97316] hover:opacity-90 text-white py-2.5 rounded-[10px] font-medium transition-opacity text-[14px] flex items-center justify-center gap-2">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <span data-lang="buy.download_file"><?php echo Lang::t('buy.download_file'); ?></span>
                        </a>
                    <?php endif; ?>

                    <button type="button" onclick="closeSuccessModal()"
                        class="w-full bg-[#2a3454] hover:bg-[#323d60] text-white py-2.5 rounded-[10px] font-medium transition-colors text-[14px]" data-lang="common.close">
                        <?php echo Lang::t('common.close'); ?>
                    </button>
                </div>

                <div id="copySuccess"
                    class="bg-[#22c55e]/10 border border-[#22c55e]/30 p-2 rounded-[8px] text-[#22c55e] mt-3 hidden text-[12px] text-center">
                    <i class="bi bi-check-circle mr-1"></i><span id="copySuccessText"></span>
                </div>
            </div>
        </div>

        <script>
            function copySingleKey(key) {
                Lang.copy(key, function(success) {
                    if (success) {
                        document.getElementById('copySuccessText').textContent = Lang.t('buy.copy_success');
                        document.getElementById('copySuccess').classList.remove('hidden');
                        setTimeout(function () {
                            document.getElementById('copySuccess').classList.add('hidden');
                        }, 2000);
                    }
                });
            }

            function copyAllKeys() {
                const keys = <?php echo json_encode(implode("\n", array_map('strval', $purchasedKeys)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
                Lang.copy(keys, function(success) {
                    if (success) {
                        document.getElementById('copySuccessText').textContent = Lang.t('buy.copy_success');
                        document.getElementById('copySuccess').classList.remove('hidden');
                        setTimeout(function () {
                            document.getElementById('copySuccess').classList.add('hidden');
                        }, 2000);
                    }
                });
            }

            window.requestAnimationFrame(function () {
                const successModal = document.getElementById('successModal');
                if (!successModal) return;
                const firstControl = successModal.querySelector('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
                (firstControl || successModal).focus({ preventScroll: true });
            });

            function closeSuccessModal() {
                const successOverlay = document.getElementById('successOverlay');
                const successModal = document.getElementById('successModal');
                if (successOverlay) successOverlay.setAttribute('aria-hidden', 'true');
                if (successModal) successModal.setAttribute('aria-hidden', 'true');
                if (window.AppMotion) {
                    window.AppMotion.hide(successModal, 260);
                    window.AppMotion.hide(successOverlay, 220);
                } else {
                    if (successOverlay) successOverlay.style.display = 'none';
                    if (successModal) successModal.style.display = 'none';
                }
            }
        </script>
    <?php endif; ?>

    <script>
        // Navigation guard: background stock/order callbacks must never override
        // a navigation the user has already started (category/filter/menu/form).
        // This closes a rare race where an inventory refresh could reload the old
        // URL at the same moment a category link was navigating to a new URL.
        window.SakazukiBuyNavigation = window.SakazukiBuyNavigation || (function () {
            let pending = false;

            function markPending() {
                pending = true;
            }

            function isPending() {
                return pending || document.visibilityState === 'hidden';
            }

            document.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                if (!link || link.hasAttribute('download')) return;
                const target = String(link.getAttribute('target') || '').toLowerCase();
                if (target === '_blank' || target === '_top' || target === '_parent') return;
                const href = String(link.getAttribute('href') || '').trim();
                if (href === '' || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;
                try {
                    const url = new URL(link.href, window.location.href);
                    if (url.origin !== window.location.origin) return;
                    if (url.href === window.location.href) return;
                    markPending();
                } catch (_) {}
            }, true);

            document.addEventListener('submit', function (event) {
                if (!event.defaultPrevented) markPending();
            });
            window.addEventListener('pagehide', markPending);

            return Object.freeze({
                mark: markPending,
                isPending: isPending
            });
        })();

        function buyNavigationPending() {
            return !!(window.SakazukiBuyNavigation && window.SakazukiBuyNavigation.isPending());
        }

        function beginBuyNavigation() {
            if (window.SakazukiBuyNavigation) window.SakazukiBuyNavigation.mark();
        }

        let currentQtyData = {};
        let purchaseSubmitting = false;
        let userBalance = <?php echo getUserBalance($_SESSION['user_id']); ?>;
        const MAX_PURCHASE_QUANTITY = 100;

        function getPurchaseQuantityLimit() {
            const available = Math.max(0, parseInt(currentQtyData.availableQty || '0', 10) || 0);
            return Math.min(MAX_PURCHASE_QUANTITY, available);
        }

        function normalizeQuantityInput() {
            const input = document.getElementById('quantityInput');
            if (!input) return 0;

            const max = getPurchaseQuantityLimit();
            input.max = String(max);

            let quantity = parseInt(input.value, 10);
            if (!Number.isFinite(quantity) || quantity < 1) quantity = 1;
            if (max > 0 && quantity > max) quantity = max;

            input.value = String(quantity);
            return quantity;
        }

        function selectVariant(duration, variantId, productId, productName, availableQty, price, trigger) {
            const source = trigger && trigger.dataset ? String(trigger.dataset.inventorySource || 'local') : 'local';
            if (source === 'remote') {
                availableQty = trigger && trigger.dataset ? (parseInt(trigger.dataset.inventoryStock || '0', 10) || 0) : 0;
                if (availableQty < 1 || (trigger && trigger.disabled)) return;
            }
            currentQtyData = { duration, variantId, productId, productName, availableQty, price, source };
            window.purchaseModalReturnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
            let combinedName = productName;
            if (duration && duration !== 'Standard') combinedName += ' - ' + duration;

            document.getElementById('qtyCombinedName').textContent = combinedName;
            document.getElementById('qtyPricePerKey').textContent = formatCurrency(price);
            document.getElementById('quantityInput').value = 1;
            document.getElementById('quantityInput').max = getPurchaseQuantityLimit();
            updateTotalAmount();
            const overlay = document.getElementById('overlay');
            const modal = document.getElementById('quantityModal');
            if (overlay) overlay.setAttribute('aria-hidden', 'false');
            if (modal) modal.setAttribute('aria-hidden', 'false');
            if (window.AppMotion) {
                window.AppMotion.show(overlay, 'block');
                window.AppMotion.show(modal, 'block');
            } else {
                if (overlay) {
                    overlay.style.display = 'block';
                    overlay.classList.add('is-visible');
                }
                if (modal) {
                    modal.style.display = 'block';
                    modal.classList.add('is-visible');
                }
            }
            window.requestAnimationFrame(function () {
                const quantityInput = document.getElementById('quantityInput');
                if (quantityInput) quantityInput.focus({preventScroll: true});
                else if (modal) modal.focus({preventScroll: true});
            });
        }

        function closeQuantityModal() {
            const overlay = document.getElementById('overlay');
            const modal = document.getElementById('quantityModal');
            if (overlay) overlay.setAttribute('aria-hidden', 'true');
            if (modal) modal.setAttribute('aria-hidden', 'true');
            if (window.AppMotion) {
                window.AppMotion.hide(modal, 260);
                window.AppMotion.hide(overlay, 220);
            } else {
                if (overlay) overlay.style.display = 'none';
                if (modal) modal.style.display = 'none';
            }
            const returnFocus = window.purchaseModalReturnFocus;
            window.setTimeout(function () {
                if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus({preventScroll: true});
            }, 280);
        }

        const exchangeRate = <?php echo getExchangeRateThbToUsd(); ?>;
        function formatCurrency(amount) {
            return Lang.formatCurrency(amount);
        }

        function updateTotalAmount() {
            const quantity = normalizeQuantityInput();
            const total = quantity * currentQtyData.price;
            document.getElementById('qtyTotalAmount').textContent = formatCurrency(total);

            // Update balance after purchase
            const balanceAfter = userBalance - total;
            document.getElementById('balanceAfter').textContent = formatCurrency(balanceAfter);
        }

        function changeQuantity(delta) {
            const input = document.getElementById('quantityInput');
            const current = normalizeQuantityInput();
            const newValue = current + delta;
            const max = getPurchaseQuantityLimit();

            if (newValue >= 1 && newValue <= max) {
                input.value = newValue;
                updateTotalAmount();
            }
        }

        function confirmPurchase() {
            if (purchaseSubmitting) return;
            const quantity = normalizeQuantityInput();
            const max = getPurchaseQuantityLimit();
            const total = quantity * Number(currentQtyData.price || 0);

            if (quantity < 1 || quantity > max || !Number.isFinite(total) || total <= 0) return;
            if (total > userBalance) {
                alert(Lang.t('buy.insufficient_balance') || 'Insufficient balance');
                return;
            }

            document.getElementById('qtyDuration').value = currentQtyData.duration;
            document.getElementById('qtyVariantId').value = currentQtyData.variantId || 0;
            document.getElementById('qtyProductId').value = currentQtyData.productId;
            document.getElementById('qtyQuantity').value = quantity;

            purchaseSubmitting = true;
            const confirmButton = document.getElementById('confirmPurchaseButton');
            const processingOverlay = document.getElementById('purchaseProcessingOverlay');
            const processingLabel = <?php echo json_encode(getAppLang() === 'en' ? 'Processing...' : 'กำลังซื้อ...', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            if (confirmButton) {
                confirmButton.disabled = true;
                confirmButton.classList.add('opacity-70', 'cursor-not-allowed');
                confirmButton.innerHTML = '<span class="purchase-button-spinner" aria-hidden="true"></span><span>' + processingLabel + '</span>';
            }
            if (processingOverlay) {
                processingOverlay.setAttribute('aria-hidden', 'false');
                if (window.AppMotion) window.AppMotion.show(processingOverlay, 'flex');
                else {
                    processingOverlay.style.display = 'flex';
                    processingOverlay.classList.add('is-visible');
                }
            }

            const purchaseForm = document.getElementById('quantityPurchaseForm');
            // Give the browser one painted frame for the loading feedback before navigation.
            window.requestAnimationFrame(function () {
                window.setTimeout(function () {
                    if (purchaseForm) purchaseForm.submit();
                }, 80);
            });
        }

        // Keyboard support for purchase dialogs.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (typeof closeVariantModal === 'function') closeVariantModal();
                closeQuantityModal();
                <?php if (!empty($purchasedKeys)): ?>closeSuccessModal(); <?php endif; ?>
                return;
            }
            if (event.key !== 'Tab') return;
            const candidates = [document.getElementById('successModal'), document.getElementById('quantityModal')];
            const modal = candidates.find((node) => node && node.getAttribute('aria-hidden') !== 'true' && node.style.display !== 'none');
            if (!modal) return;
            const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter((node) => node.offsetParent !== null);
            if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });

        // Prevent clicks inside modal from closing it
        document.querySelectorAll('.variant-modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        });

        // Delegated binding covers product controls on the rendered page.
        document.addEventListener('click', function (event) {
            const item = event.target.closest('.product-item');
            if (!item || event.target.closest('button')) return;
            item.style.transform = 'scale(0.98)';
            setTimeout(() => { item.style.transform = ''; }, 150);
        });
    </script>




    <?php if ($purchasePendingActive && $pendingOrderId > 0 && in_array($pendingOrderSource, ['cgo', 'supplier'], true)): ?>
    <script>
    (function () {
        const orderId = <?php echo (int) $pendingOrderId; ?>;
        const source = <?php echo json_encode($pendingOrderSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const csrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const hardStopMs = <?php echo (int) min(19000, max(2000, ($pendingWaitRemainingSeconds + 1) * 1000)); ?>;
        const startedAt = Date.now();
        let stopped = false;
        let timer = null;

        const messageNode = document.getElementById('purchaseStatusMessage');
        const alertNode = document.getElementById('purchaseStatusAlert');

        function setMessage(message, mode) {
            if (messageNode && message) messageNode.textContent = message;
            if (!alertNode || !mode) return;
            alertNode.classList.remove(
                'border-amber-500/30', 'bg-amber-900/10', 'text-amber-200',
                'border-green-500/30', 'bg-green-900/10', 'text-green-300',
                'border-red-500/30', 'bg-red-900/10', 'text-red-300'
            );
            if (mode === 'success') {
                alertNode.classList.add('border-green-500/30', 'bg-green-900/10', 'text-green-300');
            } else if (mode === 'error') {
                alertNode.classList.add('border-red-500/30', 'bg-red-900/10', 'text-red-300');
            } else {
                alertNode.classList.add('border-amber-500/30', 'bg-amber-900/10', 'text-amber-200');
            }
        }

        function stop() {
            stopped = true;
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        }

        function schedule(ms) {
            if (stopped) return;
            const elapsed = Date.now() - startedAt;
            if (elapsed >= hardStopMs) {
                stop();
                setMessage(
                    <?php echo json_encode(getAppLang() === 'th'
                        ? 'ครบเวลารอหน้าร้านแล้ว ระบบจะตรวจสอบรายการนี้ต่อเบื้องหลังโดยอัตโนมัติ'
                        : 'The storefront wait window has ended. This order will continue to be checked automatically in the background.',
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                    'pending'
                );
                return;
            }
            timer = setTimeout(checkStatus, Math.max(1000, ms));
        }

        async function checkStatus() {
            if (stopped) return;
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const abortTimer = controller ? setTimeout(function () { controller.abort(); }, 9000) : null;
            try {
                const body = new URLSearchParams();
                body.set('csrf_token', csrfToken);
                body.set('order_id', String(orderId));
                body.set('source', source);

                const response = await fetch('../cgo_order_status.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body,
                    signal: controller ? controller.signal : undefined
                });
                const data = await response.json().catch(function () { return null; });
                if (!response.ok || !data || !data.success) {
                    schedule(3000);
                    return;
                }

                if (Number.isFinite(Number(data.balance))) {
                    userBalance = Number(data.balance);
                }

                if (data.completed) {
                    stop();
                    setMessage(data.message || 'Order completed', 'success');
                    setTimeout(function () {
                        if (buyNavigationPending()) return;
                        beginBuyNavigation();
                        window.location.href = 'mykeys.php';
                    }, 700);
                    return;
                }

                if (data.conflict) {
                    stop();
                    setMessage(data.message || 'Order requires administrator review', 'error');
                    return;
                }

                if (data.refunded) {
                    stop();
                    setMessage(data.message || 'Balance refunded', 'success');
                    setTimeout(function () {
                        if (buyNavigationPending()) return;
                        beginBuyNavigation();
                        window.location.href = <?php echo json_encode($buildStoreUrl(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                    }, 700);
                    return;
                }

                setMessage(data.message || 'Checking order status...', data.deadline_exceeded ? 'error' : 'pending');
                if (data.deadline_exceeded) {
                    stop();
                    return;
                }
                schedule(Math.max(2000, Number(data.retry_after_seconds || 3) * 1000));
            } catch (error) {
                schedule(3000);
            } finally {
                if (abortTimer) clearTimeout(abortTimer);
            }
        }

        schedule(500);
    })();
    </script>
    <?php endif; ?>

    <?php if ($cgoInventoryPollingEnabled): ?>
    <script>
    (function () {
        const ttlSeconds = <?php echo (int) $cgoInventoryTtlSeconds; ?>;
        const initialDelayMs = <?php echo (int) $cgoInventoryInitialDelayMs; ?>;
        let csrfToken = <?php echo json_encode($cgoInventoryCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const banner = document.getElementById('inventoryRefreshBanner');
        const messageNode = banner ? banner.querySelector('[data-inventory-message]') : null;
        const iconNode = banner ? banner.querySelector('[data-inventory-icon]') : null;
        const text = {
            ready: <?php echo json_encode(getAppLang() === 'th'
                ? 'ระบบจะอัปเดตสต็อกสินค้าอัตโนมัติทุกประมาณ ' . (int) $cgoInventoryTtlSeconds . ' วินาที และต้นทางจะยืนยันสต็อกจริงตอนส่งคำสั่งซื้อ'
                : 'Stock updates automatically about every ' . (int) $cgoInventoryTtlSeconds . ' seconds; the supplier validates final availability when the order is submitted.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            checking: <?php echo json_encode(getAppLang() === 'th'
                ? 'กำลังตรวจสอบสต็อกล่าสุดเบื้องหลัง'
                : 'Checking the latest stock in the background.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            success: <?php echo json_encode(getAppLang() === 'th'
                ? 'อัปเดตสต็อกล่าสุดแล้ว'
                : 'Stock is up to date.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            failed: <?php echo json_encode(getAppLang() === 'th'
                ? 'ยังอัปเดตสต็อกไม่สำเร็จ ระบบจะลองใหม่ และต้นทางยังเป็นผู้ยืนยันสต็อกจริงตอนสั่งซื้อ'
                : 'Stock could not be updated. The system will retry; the supplier still validates final availability during checkout.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            busy: <?php echo json_encode(getAppLang() === 'th'
                ? 'มีคำขออื่นกำลังอัปเดตสต็อก ระบบจะตรวจอีกครั้งในไม่กี่วินาที'
                : 'Another request is updating stock. The system will check again shortly.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            soldOut: <?php echo json_encode(getAppLang() === 'th' ? 'สินค้าหมดชั่วคราว' : 'Temporarily sold out', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        };

        let refreshing = false;
        let timer = null;
        let nextRefreshAt = Date.now() + initialDelayMs;

        function remoteButtons() {
            return Array.from(document.querySelectorAll('button[data-inventory-source="remote"]'));
        }

        const variantIds = Array.from(new Set(remoteButtons().map(function (button) {
            return parseInt(button.dataset.inventoryVariantId || '0', 10) || 0;
        }).filter(function (id) { return id > 0; })));

        if (variantIds.length < 1) return;

        function setBanner(kind, message) {
            if (!banner) return;
            banner.classList.remove('border-cyan-500/30', 'bg-cyan-900/10', 'text-cyan-200', 'border-green-500/30', 'bg-green-900/10', 'text-green-200', 'border-amber-500/30', 'bg-amber-900/10', 'text-amber-200');
            if (kind === 'success') banner.classList.add('border-green-500/30', 'bg-green-900/10', 'text-green-200');
            else if (kind === 'error') banner.classList.add('border-amber-500/30', 'bg-amber-900/10', 'text-amber-200');
            else banner.classList.add('border-cyan-500/30', 'bg-cyan-900/10', 'text-cyan-200');
            if (messageNode) messageNode.textContent = message;
            if (iconNode) {
                iconNode.className = kind === 'checking'
                    ? 'bi bi-arrow-repeat mr-2 animate-spin inline-block'
                    : (kind === 'success' ? 'bi bi-check-circle mr-2' : (kind === 'error' ? 'bi bi-exclamation-triangle mr-2' : 'bi bi-shield-check mr-2'));
            }
        }

        function setButtonStock(button, stock) {
            stock = Math.max(0, parseInt(stock || '0', 10) || 0);
            button.dataset.inventoryStock = String(stock);
            const normal = button.querySelector('[data-inventory-normal]');
            const soldOut = button.querySelector('[data-inventory-soldout]');
            button.classList.toggle('opacity-50', stock < 1);
            button.classList.toggle('cursor-not-allowed', stock < 1);
            button.disabled = stock < 1;
            if (normal) normal.hidden = stock < 1;
            if (soldOut) soldOut.hidden = stock > 0;
        }

        function applySnapshot(snapshot) {
            const rows = snapshot && typeof snapshot === 'object' ? snapshot : {};
            remoteButtons().forEach(function (button) {
                const id = String(parseInt(button.dataset.inventoryVariantId || '0', 10) || 0);
                const row = rows[id];
                // A missing row is not an explicit zero. Keep the last rendered
                // value so a partial response cannot falsely disable the product.
                if (!row || typeof row !== 'object') return;
                const stock = row && row.available ? Math.max(0, parseInt(row.stock || '0', 10) || 0) : 0;
                setButtonStock(button, stock);
            });

            if (typeof currentQtyData === 'object' && currentQtyData.source === 'remote') {
                const row = rows[String(currentQtyData.variantId || 0)];
                if (!row || typeof row !== 'object') return;
                const stock = row && row.available ? Math.max(0, parseInt(row.stock || '0', 10) || 0) : 0;
                if (stock < 1) {
                    if (typeof closeQuantityModal === 'function') closeQuantityModal();
                } else {
                    currentQtyData.availableQty = stock;
                    const input = document.getElementById('quantityInput');
                    if (input) {
                        const max = getPurchaseQuantityLimit();
                        input.max = String(max);
                        if ((parseInt(input.value || '1', 10) || 1) > max) input.value = String(max);
                        if (typeof updateTotalAmount === 'function') updateTotalAmount();
                    }
                }
            }
        }

        remoteButtons().forEach(function (button) {
            setButtonStock(button, button.dataset.inventoryStock || '0');
        });

        function schedule(delayMs) {
            if (timer) window.clearTimeout(timer);
            const safeDelay = Math.max(500, Number(delayMs) || 0);
            nextRefreshAt = Date.now() + safeDelay;
            timer = window.setTimeout(checkDue, safeDelay);
        }

        async function renewCsrfToken() {
            try {
                const response = await fetch('../cgo_inventory.php?action=token', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
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

        async function requestInventory(allowTokenRenewal, requestScope) {
            const body = new URLSearchParams();
            body.set('scope', requestScope || 'storefront');
            body.set('variant_ids', JSON.stringify(variantIds));
            const response = await fetch('../cgo_inventory.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: requestScope === 'storefront_refresh',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                },
                body: body.toString()
            });
            if (response.status === 403 && allowTokenRenewal && await renewCsrfToken()) {
                return requestInventory(false, requestScope);
            }
            let data = null;
            try { data = await response.json(); } catch (ignored) {}
            return { response, data };
        }

        let backgroundRefreshInFlight = false;
        function triggerBackgroundRefresh() {
            if (backgroundRefreshInFlight) return;
            const storageKey = 'cgo-inventory-kick-v2:' + window.location.host;
            const now = Date.now();
            try {
                const last = parseInt(window.localStorage.getItem(storageKey) || '0', 10) || 0;
                if (now - last < 15000) return;
                window.localStorage.setItem(storageKey, String(now));
            } catch (ignored) {}
            backgroundRefreshInFlight = true;
            requestInventory(true, 'storefront_refresh').then(function (result) {
                const response = result && result.response;
                const data = result && result.data;
                if (response && response.ok && data && data.success) {
                    if (data.catalog_changed === true) {
                        if (buyNavigationPending()) return;
                        beginBuyNavigation();
                        window.location.reload();
                        return;
                    }
                    applySnapshot(data.snapshot || {});
                }
            }).catch(function () {}).finally(function () {
                backgroundRefreshInFlight = false;
            });
        }

        async function refreshInventory() {
            if (refreshing) return;
            if (document.visibilityState === 'hidden') {
                schedule(5000);
                return;
            }
            refreshing = true;
            setBanner('checking', text.checking);
            try {
                const result = await requestInventory(true, 'storefront');
                const response = result.response;
                const data = result.data;

                if (response.ok && data && data.success) {
                    if (data.catalog_changed === true) {
                        // A new remote product/variant was published after this
                        // page rendered. Reload once so it appears immediately,
                        // unless the reseller already started another navigation.
                        if (buyNavigationPending()) return;
                        beginBuyNavigation();
                        window.location.reload();
                        return;
                    }
                    applySnapshot(data.snapshot || {});
                    if (data.refresh_needed === true) {
                        setBanner('checking', text.checking);
                        triggerBackgroundRefresh();
                        schedule(4000);
                    } else {
                        setBanner('success', text.success);
                        window.setTimeout(function () { setBanner('ready', text.ready); }, 3000);
                        schedule(Math.max(30, Number(data.ttl_seconds || ttlSeconds)) * 1000);
                    }
                    return;
                }
                if (response.status === 202 && data && data.busy) {
                    setBanner('ready', text.busy);
                    schedule(Math.max(1, Math.min(5, Number(data.retry_after || 2))) * 1000);
                    return;
                }
                setBanner('error', text.failed);
                schedule(30000);
            } catch (error) {
                setBanner('error', text.failed);
                schedule(30000);
            } finally {
                refreshing = false;
            }
        }

        function checkDue() {
            if (Date.now() >= nextRefreshAt - 250) refreshInventory();
            else schedule(nextRefreshAt - Date.now());
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && Date.now() >= nextRefreshAt) refreshInventory();
        });
        window.addEventListener('focus', function () {
            if (Date.now() >= nextRefreshAt) refreshInventory();
        });
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) refreshInventory();
        });
        schedule(initialDelayMs);
    })();
    </script>
    <?php endif; ?>

    <?php if ($forceInventoryReload): ?>
    <script>
    (function () {
        const message = <?php echo json_encode($inventoryReloadMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        if (message) window.alert(message);
        // Replace the POST response with a clean GET so browser refresh cannot
        // resubmit the purchase. The server already refreshed the latest stock.
        window.location.replace(window.location.pathname + window.location.search);
    })();
    </script>
    <?php endif; ?>

<script src="../assets/js/announcement-marquee.js?v=<?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?>"></script>
<!-- Store filters use normal navigation so stock polling always matches the rendered product page. -->
</body>

</html>
