<?php

require_once '../includes/auth.php';

require_once '../includes/db.php';
require_once '../includes/cheatgame.php';
require_once '../includes/announcement_marquee.php';

requireAdmin();





$error = '';

$success = '';

$purchasedKey = '';

$purchasedKeys = [];

$totalPrice = 0;

$productId = 0;

$duration = '';

$purchaseScope = 'catalog-buy:' . (int) ($_SESSION['user_id'] ?? 0);
$purchaseRequestToken = '';



// Get announcement text

$announcementText = getAnnouncementText();
$announcementTextColor = getAnnouncementTextColor();



// Storefront catalogue: filter and paginate in MySQL instead of loading every product into PHP.
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
$platformCounts = (array) ($catalogueFacets['platform_counts'] ?? []);
$allPlatformProductCount = array_sum(array_map('intval', $platformCounts));
$categories = array_keys($categoryCounts);
natcasesort($categories);
$categories = array_values($categories);
if ($selectedCategory !== 'all' && !array_key_exists($selectedCategory, $categoryCounts)) $selectedCategory = 'all';

$cataloguePage = getStorefrontProductsPage(
    'active',
    $selectedPlatform,
    $selectedCategory,
    $searchQuery,
    $storePage,
    $storePerPage
);
$filteredProducts = (array) ($cataloguePage['rows'] ?? []);
$storePage = max(1, (int) ($cataloguePage['page'] ?? 1));
$storePages = max(1, (int) ($cataloguePage['pages'] ?? 1));
$storeTotal = max(0, (int) ($cataloguePage['total'] ?? 0));

$buildDashboardUrl = static function (array $overrides = []) use ($selectedPlatform, $selectedCategory, $searchQuery, $storePage): string {
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

// Handle purchase by duration as one atomic operation.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['purchase_duration'])) {
    requireCsrfToken();
    $postedPurchaseToken = isset($_POST['purchase_token']) && is_string($_POST['purchase_token'])
        ? trim($_POST['purchase_token'])
        : '';
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
        if (!empty($purchase['success'])) {
            $purchasedKeys = isset($purchase['keys']) && is_array($purchase['keys']) ? $purchase['keys'] : [];
            $purchasedKey = implode("\n", $purchasedKeys);
            $totalPrice = (float) ($purchase['total'] ?? 0);
            $success = $purchasedKeys !== []
                ? Lang::t('admin.dashboard.success_purchase', ['count' => count($purchasedKeys), 'total' => formatCurrency($totalPrice)])
                : (string) ($purchase['message'] ?? 'Order accepted and is processing');
            $dlInfo = getProductDownloadUrl($productId);
            $downloadUrl = $dlInfo['url'] ?? null;
            $firstCategory = $dlInfo['category'] ?? '';
        } else {
            $error = (string) ($purchase['message'] ?? Lang::t('admin.dashboard.error.purchase_failed'));
        }
    }
}

// Load variants once for the current page. The old admin dashboard queried
// local/API variants again for every rendered product (N+1).
$storefrontProductIds = [];
foreach ($filteredProducts as $storefrontProduct) {
    $storefrontProductId = (int) ($storefrontProduct['id'] ?? 0);
    if ($storefrontProductId > 0) $storefrontProductIds[] = $storefrontProductId;
}
$storefrontVariantMap = cgoGetUnifiedStoreVariantsForProducts(
    $storefrontProductIds,
    'user',
    (int) ($_SESSION['user_id'] ?? 0)
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

?>

<!DOCTYPE html>

<html lang="<?php echo getAppLang(); ?>">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo Lang::t('admin.dashboard.title'); ?></title>

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

            scrollbar-color: #3b82f6 transparent;

        }



        .category-filter::-webkit-scrollbar {

            height: 3px;

        }



        .category-filter::-webkit-scrollbar-track {

            background: transparent;

        }



        .category-filter::-webkit-scrollbar-thumb {

            background-color: #3b82f6;

            border-radius: 2px;

        }







        /* Category pill styles (Tap kategori) */

        .category-pill {

            background: rgba(255, 255, 255, 0.05);

            color: #d1d5db;

            border: 1px solid rgba(255, 255, 255, 0.20);

        }

        .category-pill:hover {

            background: rgba(255, 255, 255, 0.10);

        }

        .category-pill:active {

            background: rgba(255, 255, 255, 0.14);

            border-color: rgba(255, 255, 255, 0.35);

        }

        .category-pill-active {

            background: #3b82f6;

            color: #ffffff;

            border: 1px solid rgba(96, 165, 250, 0.50);

        }

        .category-pill-active:hover {

            background: #2563eb;

        }

        .category-pill-active:active {

            background: #1d4ed8;

        }

        .category-filter a:focus-visible {

            outline: 2px solid rgba(59, 130, 246, 0.55);

            outline-offset: 2px;

        }

        .platform-tab,
        .category-grid-card {
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.035);
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
        }

        .platform-tab:hover,
        .category-grid-card:hover {
            transform: translateY(-1px);
            border-color: rgba(96, 165, 250, 0.45);
            background: rgba(59, 130, 246, 0.08);
        }

        .platform-tab-active,
        .category-grid-card-active {
            border-color: rgba(96, 165, 250, 0.65);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.28), rgba(124, 58, 237, 0.18));
            box-shadow: 0 8px 24px rgba(30, 64, 175, 0.16);
        }

        .filter-count {
            min-width: 1.6rem;
            height: 1.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #bfdbfe;
            font-size: 0.68rem;
            font-weight: 700;
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



        /* Product item styles */

        .product-item {

            transition:
                transform .15s cubic-bezier(.22, 1, .36, 1),
                background-color .15s ease,
                border-color .15s ease,
                box-shadow .15s ease;

            border-radius: 10px;

            background: rgba(255, 255, 255, 0.02);

            border: 1px solid rgba(255, 255, 255, 0.1);

            overflow: hidden;

        }



        .product-item:hover {

            background: rgba(59, 130, 246, 0.05);

            border-color: rgba(59, 130, 246, 0.3);

            transform: translateY(-2px);

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);

        }



        .btn-select {

            background: rgba(59, 130, 246, 0.1);

            color: #3b82f6;

            border: 1px solid rgba(59, 130, 246, 0.3);

            transition: background-color .15s ease, border-color .15s ease, color .15s ease;

        }



        .btn-select:hover {

            background: rgba(59, 130, 246, 0.2);

            border-color: rgba(59, 130, 246, 0.5);

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



        .clamp2 {

            display: -webkit-box;

            -webkit-line-clamp: 2;

            -webkit-box-orient: vertical;

            overflow: hidden;

        }

        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>

</head>

<body class="performance-dashboard bg-gray-900 text-gray-100 min-h-screen">

    <?php include 'nav.php'; ?>



    <main class="p-4">

        <!-- Alerts -->

        <?php if ($error): ?>

            <div class="glass border border-red-500/30 p-3 rounded-lg bg-red-900/10 text-red-300 text-sm mb-4">

                <i class="bi bi-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>

            </div>

        <?php endif; ?>



        <?php if ($success): ?>

            <div class="glass border border-green-500/30 p-3 rounded-lg bg-green-900/10 text-green-300 text-sm mb-4">

                <i class="bi bi-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>

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
                $purchaseActivityAccent = 'violet';
                $purchaseActivityEndpoint = '../purchase_activity.php';
                include __DIR__ . '/../includes/purchase_activity.php';
                ?>
            </div>
        <?php endif; ?>



        <!-- Search and Filters -->
        <div id="adminStorefrontLiveCatalog" data-instant-panel class="space-y-4">
        <section class="mb-6 space-y-4" aria-label="<?php echo htmlspecialchars(Lang::t('catalog.browse_title'), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="glass rounded-2xl p-4">
                <form method="GET" action="" data-instant-submit-only class="flex flex-col sm:flex-row gap-3">
                    <?php if ($selectedPlatform !== 'all'): ?>
                        <input type="hidden" name="platform" value="<?php echo htmlspecialchars($selectedPlatform, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <?php if ($selectedCategory !== 'all'): ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="relative flex-1">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="search" name="search" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                            data-lang-placeholder="common.search_placeholder"
                            placeholder="<?php echo Lang::t('common.search_placeholder'); ?>"
                            class="w-full pl-9 pr-4 py-2.5 bg-[#111827]/70 border border-white/10 rounded-xl text-white placeholder-gray-500 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none text-sm">
                    </div>
                    <button type="submit"
                        class="px-4 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                        <i class="bi bi-search"></i>
                        <span data-lang="common.search"><?php echo Lang::t('common.search'); ?></span>
                    </button>
                    <?php if ($searchQuery !== '' || $selectedCategory !== 'all' || $selectedPlatform !== 'all'): ?>
                        <a href="?"
                            class="px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-200 rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span data-lang="common.clear"><?php echo Lang::t('common.clear'); ?></span>
                        </a>
                    <?php endif; ?>
                </form>
                <p class="text-[11px] text-gray-500 mt-2">
                    <i class="bi bi-keyboard mr-1"></i>
                    <?php echo getAppLang() === 'th'
                        ? 'พิมพ์ชื่อสินค้าให้ครบ แล้วกดปุ่มค้นหาหรือ Enter'
                        : 'Finish typing, then press Search or Enter.'; ?>
                </p>
            </div>

            <div class="glass rounded-2xl p-4">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <p class="text-sm font-semibold text-white" data-lang="catalog.platform_title"><?php echo Lang::t('catalog.platform_title'); ?></p>
                        <p class="text-[11px] text-gray-500 mt-0.5" data-lang="catalog.platform_desc"><?php echo Lang::t('catalog.platform_desc'); ?></p>
                    </div>
                    <i class="bi bi-phone text-blue-400 text-lg"></i>
                </div>
                <?php
                $platformOptions = [
                    'all' => ['label' => Lang::t('catalog.all_platforms'), 'icon' => 'bi-grid-3x3-gap', 'count' => $allPlatformProductCount],
                    'android' => ['label' => 'Android', 'icon' => 'bi-android2', 'count' => (int) ($platformCounts['android'] ?? 0) + (int) ($platformCounts['both'] ?? 0)],
                    'ios' => ['label' => 'iOS', 'icon' => 'bi-apple', 'count' => (int) ($platformCounts['ios'] ?? 0) + (int) ($platformCounts['both'] ?? 0)],
                    'both' => ['label' => Lang::t('catalog.both_platforms'), 'icon' => 'bi-phone', 'count' => (int) ($platformCounts['both'] ?? 0)],
                    'account' => ['label' => Lang::t('catalog.account_products'), 'icon' => 'bi-person-badge', 'count' => (int) ($platformCounts['account'] ?? 0)],
                ];
                foreach ($allowedPlatforms as $dynamicPlatform) {
                    if (isset($platformOptions[$dynamicPlatform])) continue;
                    $platformOptions[$dynamicPlatform] = [
                        'label' => ucwords(str_replace(['_', '-'], ' ', $dynamicPlatform)),
                        'icon' => 'bi-box',
                        'count' => (int) ($platformCounts[$dynamicPlatform] ?? 0),
                    ];
                }
                ?>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2.5">
                    <?php foreach ($platformOptions as $platformKey => $platformInfo): ?>
                        <a href="<?php echo htmlspecialchars($buildDashboardUrl(['platform' => $platformKey, 'category' => 'all']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="platform-tab <?php echo $selectedPlatform === $platformKey ? 'platform-tab-active' : ''; ?> rounded-xl p-3 flex items-center gap-3">
                            <span class="w-9 h-9 rounded-lg bg-white/5 flex items-center justify-center text-blue-300">
                                <i class="bi <?php echo htmlspecialchars($platformInfo['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs font-semibold text-gray-100 truncate"><?php echo htmlspecialchars($platformInfo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="block text-[10px] text-gray-500 mt-0.5"><?php echo htmlspecialchars(Lang::t('catalog.products_count', ['count' => (int) $platformInfo['count']]), ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($categories)): ?>
                <div class="glass rounded-2xl p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <div>
                            <p class="text-sm font-semibold text-white" data-lang="catalog.category_title"><?php echo Lang::t('catalog.category_title'); ?></p>
                            <p class="text-[11px] text-gray-500 mt-0.5" data-lang="catalog.category_desc"><?php echo Lang::t('catalog.category_desc'); ?></p>
                        </div>
                        <i class="bi bi-collection text-violet-400 text-lg"></i>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2.5">
                        <a href="<?php echo htmlspecialchars($buildDashboardUrl(['category' => 'all']), ENT_QUOTES, 'UTF-8'); ?>"
                            class="category-grid-card <?php echo $selectedCategory === 'all' ? 'category-grid-card-active' : ''; ?> rounded-xl p-3 flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 min-w-0">
                                <i class="bi bi-grid text-blue-300"></i>
                                <span class="text-xs font-semibold truncate" data-lang="common.all"><?php echo Lang::t('common.all'); ?></span>
                            </span>
                            <span class="filter-count"><?php echo $platformProductCount; ?></span>
                        </a>
                        <?php foreach ($categories as $category): ?>
                            <a href="<?php echo htmlspecialchars($buildDashboardUrl(['category' => $category]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="category-grid-card <?php echo $selectedCategory === $category ? 'category-grid-card-active' : ''; ?> rounded-xl p-3 flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2 min-w-0">
                                    <i class="bi bi-tag text-violet-300"></i>
                                    <span class="text-xs font-semibold truncate"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                                <span class="filter-count"><?php echo (int) ($categoryCounts[$category] ?? 0); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <div class="mb-8">
            <?php if (empty($filteredProducts)): ?>
                <div class="glass rounded-lg p-10 text-center">
                    <i class="bi bi-search text-3xl text-gray-400 mb-2"></i>
                    <p class="text-gray-400 text-sm" data-lang="common.no_products_found">No products found</p>
                    <?php if ($searchQuery !== '' || $selectedCategory !== 'all' || $selectedPlatform !== 'all'): ?>
                        <a href="?"
                            class="inline-block mt-3 px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded text-sm transition">
                            <span data-lang="common.clear_filters"><?php echo Lang::t('common.clear_filters'); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-4">
                    <?php foreach ($filteredProducts as $product): ?>
                        <?php
                        // Main-catalogue variants include local stock and the mapped
                        // CHEATGAME fallback. Local keys remain the first source.
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

                        $productPlatform = normalizeProductPlatform((string) ($product['platform'] ?? 'android'));
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
                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                        class="w-[72px] h-[72px] object-cover rounded-[10px] border border-white/5"
                                        alt="<?php echo htmlspecialchars($product['name']); ?>">
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
                                        class="bg-[#242b45] border border-white/5 rounded-[10px] p-3 text-center text-gray-500 text-sm font-medium">
                                        <span data-lang="common.sold_out"><?php echo Lang::t('common.sold_out'); ?></span>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($variants as $vData): ?>
                                        <?php $duration = (string) ($vData['duration'] ?? 'Standard'); ?>
                                        <button type="button"
                                            onclick="selectVariant(<?php echo htmlspecialchars(json_encode($duration, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int) ($vData['variant_id'] ?? 0); ?>, <?php echo (int)$product['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$product['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (int)$vData['count']; ?>, <?php echo json_encode((float)$vData['price']); ?>)"
                                            class="w-full bg-[#242b45] hover:bg-[#2a3454] border border-white/5 hover:border-[#3b82f6]/40 transition-all rounded-[10px] py-2.5 px-4 flex justify-between items-center group">

                                            <span
                                                class="text-[#b4bccc] text-[13px] font-medium group-hover:text-white transition-colors">
                                                <?php echo htmlspecialchars($product['name'] . ' - ' . $duration); ?>
                                            </span>

                                            <span class="text-white font-bold text-[13px]">
                                                <?php echo formatCurrency($vData['price']); ?>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($storePages > 1): ?>
                    <nav class="mt-5 flex flex-wrap items-center justify-center gap-2" aria-label="Product pages">
                        <?php if ($storePage > 1): ?>
                            <a href="<?php echo htmlspecialchars($buildDashboardUrl(['page' => $storePage - 1]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-gray-200 hover:bg-white/10">&lsaquo;</a>
                        <?php endif; ?>
                        <?php
                        $pageStart = max(1, $storePage - 2);
                        $pageEnd = min($storePages, $storePage + 2);
                        for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
                        ?>
                            <a href="<?php echo htmlspecialchars($buildDashboardUrl(['page' => $pageNumber]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="rounded-lg border px-3 py-2 text-xs font-semibold <?php echo $pageNumber === $storePage ? 'border-blue-400/50 bg-blue-500 text-white' : 'border-white/10 bg-white/5 text-gray-200 hover:bg-white/10'; ?>"><?php echo $pageNumber; ?></a>
                        <?php endfor; ?>
                        <?php if ($storePage < $storePages): ?>
                            <a href="<?php echo htmlspecialchars($buildDashboardUrl(['page' => $storePage + 1]), ENT_QUOTES, 'UTF-8'); ?>"
                                class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-gray-200 hover:bg-white/10">&rsaquo;</a>
                        <?php endif; ?>
                    </nav>
                    <p class="mt-2 text-center text-xs text-gray-500"><?php echo getAppLang() === 'th'
                        ? 'หน้า ' . $storePage . ' / ' . $storePages . ' · ทั้งหมด ' . $storeTotal . ' รายการ'
                        : 'Page ' . $storePage . ' / ' . $storePages . ' · ' . $storeTotal . ' products'; ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        </div>
    </main>



    <!-- Variant Selection Modal -->
    <div class="overlay" id="overlay" onclick="closeQuantityModal()"></div>

    <div class="variant-modal bg-[#1b2336] rounded-[16px] overflow-hidden animate-slide-up shadow-2xl"
        id="quantityModal" style="max-width: 340px;">

        <div class="p-5 pb-2 flex justify-between items-center">
            <h5 class="font-bold text-white text-[16px]" data-lang="buy.modal.confirm_title">
                <?php echo Lang::t('buy.modal.confirm_title'); ?>
            </h5>
            <button onclick="closeQuantityModal()" class="text-[#8e9bb0] hover:text-white text-xl transition-colors">
                <i class="bi bi-x"></i>
            </button>
        </div>

        <div class="p-5 pt-3">
            <div class="text-center mb-6">
                <h4 class="font-bold text-white text-[16px]" id="qtyCombinedName"></h4>
            </div>

            <div class="space-y-4 mb-6">
                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.modal.price_per_item"><?php echo Lang::t('buy.modal.price_per_item'); ?></span>
                    <div class="flex items-center gap-2">
                        <span class="text-[#22c55e] font-bold text-[15px]" id="qtyPricePerKey"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.modal.quantity"><?php echo Lang::t('buy.modal.quantity'); ?></span>
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
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.modal.total_price"><?php echo Lang::t('buy.modal.total_price'); ?></span>
                    <span id="qtyTotalAmount" class="text-[#22c55e] font-bold text-[15px]"></span>
                </div>

                <div class="flex justify-between items-center">
                    <span class="text-[#8e9bb0] text-[14px]" data-lang="buy.modal.balance_after"><?php echo Lang::t('buy.modal.balance_after'); ?></span>
                    <span class="text-white font-medium text-[15px]" id="balanceAfter"></span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="confirmPurchase()"
                    class="flex-1 bg-gradient-to-r from-[#6366f1] to-[#a855f7] hover:opacity-90 text-white py-2.5 rounded-[10px] font-medium transition-opacity text-[14px] flex items-center justify-center gap-2 shadow-sm">
                    <i class="bi bi-cart2"></i> <span data-lang="common.confirm"><?php echo Lang::t('common.confirm'); ?></span>
                </button>
                <button onclick="closeQuantityModal()"
                    class="flex-1 bg-[#242b45] hover:bg-[#2a3454] text-[#8e9bb0] hover:text-white py-2.5 rounded-[10px] font-medium transition-colors text-[14px]" data-lang="common.cancel">
                    <?php echo Lang::t('common.cancel'); ?>
                </button>
            </div>
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
        <div class="overlay" id="successOverlay" style="display: flex;"></div>
        <div class="variant-modal bg-[#1b2336] rounded-[16px] overflow-hidden animate-slide-up border border-white/10 shadow-2xl"
            id="successModal" style="display: block; max-width: 360px;">

            <div class="p-5 flex justify-between items-center border-b border-white/5">
                <h5 class="font-medium text-white text-[16px]" data-lang="buy.modal.success_title">
                    <?php echo Lang::t('buy.modal.success_title'); ?>
                </h5>
                <button onclick="closeSuccessModal()"
                    class="text-gray-400 hover:text-white text-lg w-7 h-7 flex items-center justify-center rounded-[8px] transition-colors">
                    <i class="bi bi-x"></i>
                </button>
            </div>

            <div class="p-5">
                <div class="mb-4">
                    <div class="text-[#8e9bb0] text-[13px] mb-1" data-lang="common.product_name"><?php echo Lang::t('common.product_name'); ?></div>
                    <div class="text-white font-medium text-[15px]">
                        <?php
                        $productName = '';
                        if (isset($productId) && (int) $productId > 0) {
                            $purchasedProduct = getProductById((int) $productId);
                            if (is_array($purchasedProduct)) $productName = (string) ($purchasedProduct['name'] ?? '');
                        }
                        // Append package name to make it look like in the mockup (e.g. DRAGON DFM iOS - 1 day)
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
                        echo date('d-m-Y H:i');
                        ?>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-[#8e9bb0] text-[13px] mb-2" data-lang="common.key_code"><?php echo Lang::t('common.key_code'); ?></div>
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
                        <div class="text-[#8e9bb0] text-[13px] mb-1" data-lang="buy.modal.amount_paid"><?php echo Lang::t('buy.modal.amount_paid'); ?></div>
                        <div class="text-[#22c55e] font-bold text-[16px]">
                            <?php echo formatCurrency($totalPrice ?? 0); ?>
                        </div>
                    </div>
                    <button onclick="copyAllKeys()"
                        class="bg-gradient-to-r from-[#6366f1] to-[#a855f7] hover:opacity-90 text-white px-4 py-2 rounded-[10px] text-[13px] font-medium flex items-center gap-2 transition-opacity">
                        <i class="bi bi-front"></i> <span data-lang="common.copy_all"><?php echo Lang::t('common.copy_all'); ?></span>
                    </button>
                </div>

                <div class="space-y-3">
                    <?php if (!empty($downloadUrl)): ?>
                        <a href="<?php echo htmlspecialchars($downloadUrl); ?>" target="_blank"
                            class="w-full bg-gradient-to-r from-[#ef4444] to-[#f97316] hover:opacity-90 text-white py-2.5 rounded-[10px] font-medium transition-opacity text-[14px] flex items-center justify-center gap-2">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <span data-lang="common.download"><?php echo Lang::t('common.download'); ?></span>
                        </a>
                    <?php endif; ?>

                    <button onclick="closeSuccessModal()"
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
                Lang.copy(key);
            }

            function copyAllKeys() {
                const keys = <?php echo json_encode(implode("\n", array_map('strval', $purchasedKeys)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
                Lang.copy(keys);
            }

            function closeSuccessModal() {
                document.getElementById('successOverlay').style.display = 'none';
                document.getElementById('successModal').style.display = 'none';
            }
        </script>
    <?php endif; ?>


    <script>

        let currentQtyData = {};

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

        function selectVariant(duration, variantId, productId, productName, availableQty, price) {
            currentQtyData = { duration, variantId, productId, productName, availableQty, price };
            let combinedName = productName;
            if (duration && duration !== 'Standard') {
                combinedName += ' - ' + duration;
            }

            document.getElementById('qtyCombinedName').textContent = combinedName;
            document.getElementById('qtyPricePerKey').textContent = formatCurrency(price);
            document.getElementById('quantityInput').value = 1;
            document.getElementById('quantityInput').max = getPurchaseQuantityLimit();
            updateTotalAmount();

            document.getElementById('overlay').style.display = 'block';
            document.getElementById('quantityModal').style.display = 'block';
        }



        function closeQuantityModal() {

            document.getElementById('overlay').style.display = 'none';

            document.getElementById('quantityModal').style.display = 'none';

        }



        function formatCurrency(amount) {
            return Lang.formatCurrency(amount);
        }



        function updateTotalAmount() {

            const quantity = normalizeQuantityInput();

            const total = quantity * currentQtyData.price;

            document.getElementById('qtyTotalAmount').textContent = formatCurrency(total);



            // Update balance after purchase

            const balanceAfter = userBalance - total;

            document.getElementById('balanceAfter').textContent = formatCurrency(Math.max(balanceAfter, 0));

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

            const quantity = normalizeQuantityInput();

            const max = getPurchaseQuantityLimit();

            const total = quantity * currentQtyData.price;

            if (quantity < 1 || quantity > max || !Number.isFinite(total) || total <= 0) {
                return;
            }



            if (total > userBalance) {
                alert(Lang.t('common.insufficient_balance'));
                return;

            }



            document.getElementById('qtyDuration').value = currentQtyData.duration;

            document.getElementById('qtyVariantId').value = currentQtyData.variantId || 0;

            document.getElementById('qtyProductId').value = currentQtyData.productId;

            document.getElementById('qtyQuantity').value = quantity;



            document.getElementById('quantityPurchaseForm').submit();

        }



        // Close modals with Escape key

        document.addEventListener('keydown', function (event) {

            if (event.key === 'Escape') {

                closeVariantModal();

                closeQuantityModal();

                <?php if (!empty($purchasedKeys)): ?>closeSuccessModal(); <?php endif; ?>

            }

        });



        // Prevent clicks inside modal from closing it

        document.querySelectorAll('.variant-modal').forEach(modal => {

            modal.addEventListener('click', function (e) {

                e.stopPropagation();

            });

        });



        // Delegated binding also covers products replaced by instant filters.

        document.addEventListener('click', function (event) {

            const item = event.target.closest('.product-item');

            if (!item || event.target.closest('button')) return;

            item.style.transform = 'scale(0.98)';

            setTimeout(() => { item.style.transform = ''; }, 150);

        });

    </script>

<script src="../assets/js/announcement-marquee.js?v=<?php echo (int) (@filemtime(__DIR__ . '/../assets/js/announcement-marquee.js') ?: 1); ?>"></script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>

</html>
