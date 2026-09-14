<?php
require_once '../includes/auth.php';
require_once '../includes/cheatgame.php';
requireReseller();

$user = getCurrentUser();
$totalKeysBought = cgoCountUnifiedUserKeys((int) $user['id']);
$stats = [
    'balance' => getUserBalance($user['id']),
    'keys_bought' => $totalKeysBought,
    'status' => $user['status']
];
$catalogSummary = getActiveCatalogueSummary();
$catalogProductCount = (int) ($catalogSummary['product_count'] ?? 0);
$catalogPlatformCounts = $catalogSummary['platform_counts'] ?? ['all' => 0, 'android' => 0, 'ios' => 0, 'both' => 0];
$catalogCategoryCounts = $catalogSummary['category_counts'] ?? [];
$recentPurchaseActivity = getPublicRecentPurchaseActivity(10);
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="reseller.dashboard.title"><?php echo Lang::t('reseller.dashboard.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#22c55e;--sakazuki-accent-rgb:34 197 94;--sakazuki-accent2:#4ade80;--sakazuki-accent2-rgb:74 222 128;--sakazuki-glow:0 0 25px rgba(34,197,94,0.35)}</style>
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
                transform: translate3d(0, 10px, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }
        .animate-fade-in {
            animation: fadeInUp .15s cubic-bezier(.22, 1, .36, 1) both;
            backface-visibility: hidden;
        }

        .catalog-auto-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
        }
        .catalog-card {
            min-width: 0;
            border: 1px solid rgba(255, 255, 255, 0.09);
            background: rgba(255, 255, 255, 0.035);
            transition: transform .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }
        .catalog-card:hover {
            transform: translateY(-2px);
            border-color: rgba(74,222,128,.45);
            background: rgba(34,197,94,.10);
            box-shadow: 0 12px 30px rgba(34,197,94,.16);
        }
        .catalog-card:focus-visible {
            outline: 2px solid rgba(74,222,128,.45);
            outline-offset: 2px;
        }
    </style>
</head>
<body class="performance-dashboard bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-6 space-y-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white"><span data-lang="dashboard.welcome"><?php echo Lang::t('dashboard.welcome'); ?></span> <?php echo htmlspecialchars($user['username']); ?>!</h3>
            <span class="px-3 py-1 rounded-full text-sm font-medium <?php echo $stats['status'] == 'active' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>" data-lang="<?php echo $stats['status'] == 'active' ? 'dashboard.status.active' : 'dashboard.status.banned'; ?>">
                <?php echo $stats['status'] == 'active' ? Lang::t('dashboard.status.active') : Lang::t('dashboard.status.banned'); ?>
            </span>
        </div>

        <?php
        $purchaseActivityAccent = 'green';
        $purchaseActivityEndpoint = '../purchase_activity.php';
        include __DIR__ . '/../includes/purchase_activity.php';
        ?>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="glass rounded-xl p-6 hover:shadow-glow hover:scale-[1.02] transition animate-fade-in">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="text-gray-400 text-sm mb-2" data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?></h4>
                        <p class="text-3xl font-semibold text-green-400"><?php echo formatCurrency($stats['balance']); ?></p>
                    </div>
                    <i class="bi bi-wallet2 text-green-400" style="font-size: 3rem;"></i>
                </div>
            </div>

            <div class="glass rounded-xl p-6 hover:shadow-glow hover:scale-[1.02] transition animate-fade-in" style="animation-delay: 0.1s;">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="text-gray-400 text-sm mb-2" data-lang="dashboard.keys_count"><?php echo Lang::t('dashboard.keys_count'); ?></h4>
                        <p class="text-3xl font-semibold text-blue-400"><?php echo $stats['keys_bought']; ?></p>
                    </div>
                    <i class="bi bi-key text-blue-400" style="font-size: 3rem;"></i>
                </div>
                <a href="mykeys.php" class="block w-full bg-blue-500/10 hover:bg-blue-500/20 text-white py-2 px-4 rounded-lg text-center transition border border-blue-500/20 mt-4" data-lang="dashboard.view_my_keys">
                    <?php echo Lang::t('dashboard.view_my_keys'); ?>
                </a>
            </div>

            <div class="glass rounded-xl p-6 hover:shadow-glow hover:scale-[1.02] transition animate-fade-in" style="animation-delay: 0.2s;">
                <div class="flex justify-between items-center">
                    <div>
                        <h4 class="text-gray-400 text-sm mb-2" data-lang="reseller.account_type"><?php echo Lang::t('reseller.account_type'); ?></h4>
                        <h4 class="text-2xl font-bold text-yellow-400" data-lang="reseller.nav.reseller_panel"><?php echo Lang::t('reseller.nav.reseller_panel'); ?></h4>
                    </div>
                    <i class="bi bi-person-badge text-yellow-400" style="font-size: 3rem;"></i>
                </div>
                <a href="buy.php" class="block w-full bg-yellow-500/10 hover:bg-yellow-500/20 text-white py-2 px-4 rounded-lg text-center transition border border-yellow-500/20 mt-4" data-lang="reseller.buy_keys_btn">
                    <?php echo Lang::t('reseller.buy_keys_btn'); ?>
                </a>
            </div>
        </div>


    <!-- Product Catalogue -->
    <section class="glass rounded-2xl p-4 md:p-6 relative overflow-hidden animate-fade-in" style="animation-delay: 0.18s;">
        <div class="absolute -top-20 -right-20 w-56 h-56 rounded-full bg-gradient-to-br from-green-500/20 to-emerald-500/20 text-green-300 blur-3xl opacity-40 pointer-events-none"></div>

        <div class="relative flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-green-500/20 to-emerald-500/20 text-green-300 flex items-center justify-center shrink-0">
                    <i class="bi bi-grid-1x2-fill text-lg"></i>
                </div>
                <div>
                    <h4 class="text-lg font-bold text-white" data-lang="catalog.browse_title"><?php echo Lang::t('catalog.browse_title'); ?></h4>
                    <p class="text-xs md:text-sm text-gray-400 mt-1" data-lang="catalog.browse_desc"><?php echo Lang::t('catalog.browse_desc'); ?></p>
                </div>
            </div>
            <a href="buy.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border bg-green-500/15 hover:bg-green-500/25 border-green-400/20 text-green-200 text-sm font-semibold transition shrink-0">
                <i class="bi bi-bag-check"></i>
                <span data-lang="catalog.open_store"><?php echo Lang::t('catalog.open_store'); ?></span>
            </a>
        </div>

        <div class="relative space-y-5">
            <div>
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <p class="text-sm font-semibold text-white" data-lang="catalog.platform_title"><?php echo Lang::t('catalog.platform_title'); ?></p>
                        <p class="text-[11px] text-gray-500 mt-0.5" data-lang="catalog.platform_desc"><?php echo Lang::t('catalog.platform_desc'); ?></p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full bg-white/5 border border-white/10 text-[11px] text-gray-300">
                        <?php echo htmlspecialchars(Lang::t('catalog.products_count', ['count' => $catalogProductCount]), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <?php
                $catalogPlatforms = [
                    'all' => ['label' => Lang::t('catalog.all_platforms'), 'icon' => 'bi-grid-3x3-gap-fill'],
                    'android' => ['label' => 'Android', 'icon' => 'bi-android2'],
                    'ios' => ['label' => 'iOS', 'icon' => 'bi-apple'],
                    'both' => ['label' => Lang::t('catalog.both_platforms'), 'icon' => 'bi-phone-fill'],
                    'account' => ['label' => Lang::t('catalog.account_products'), 'icon' => 'bi-person-badge'],
                ];
                foreach (array_keys($catalogPlatformCounts) as $dynamicPlatform) {
                    if ($dynamicPlatform === 'all' || isset($catalogPlatforms[$dynamicPlatform])) continue;
                    $catalogPlatforms[$dynamicPlatform] = [
                        'label' => ucwords(str_replace(['_', '-'], ' ', (string) $dynamicPlatform)),
                        'icon' => 'bi-box',
                    ];
                }
                ?>
                <div class="catalog-auto-grid">
                    <?php foreach ($catalogPlatforms as $platformKey => $platformInfo): ?>
                        <a href="buy.php?<?php echo htmlspecialchars(http_build_query(['platform' => $platformKey], '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8'); ?>"
                           class="catalog-card rounded-xl p-3.5 flex items-center gap-3">
                            <span class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-green-300 shrink-0">
                                <i class="bi <?php echo htmlspecialchars($platformInfo['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-gray-100 break-words"><?php echo htmlspecialchars($platformInfo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="block text-[11px] text-gray-500 mt-0.5">
                                    <?php echo htmlspecialchars(Lang::t('catalog.products_count', ['count' => (int) ($catalogPlatformCounts[$platformKey] ?? 0)]), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </span>
                            <i class="bi bi-arrow-up-right text-gray-600 text-xs"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div class="mb-3">
                    <p class="text-sm font-semibold text-white" data-lang="catalog.category_title"><?php echo Lang::t('catalog.category_title'); ?></p>
                    <p class="text-[11px] text-gray-500 mt-0.5" data-lang="catalog.category_desc"><?php echo Lang::t('catalog.category_desc'); ?></p>
                </div>

                <?php if (empty($catalogCategoryCounts)): ?>
                    <div class="rounded-xl border border-dashed border-white/10 bg-white/[0.02] p-5 text-center text-sm text-gray-500">
                        <i class="bi bi-inboxes text-2xl block mb-2"></i>
                        <span data-lang="catalog.no_categories"><?php echo Lang::t('catalog.no_categories'); ?></span>
                    </div>
                <?php else: ?>
                    <?php $catalogCategoryColors = ['text-emerald-300','text-teal-300','text-lime-300','text-cyan-300','text-amber-300']; ?>
                    <div class="catalog-auto-grid">
                        <?php $catalogIndex = 0; ?>
                        <?php foreach ($catalogCategoryCounts as $categoryName => $categoryCount): ?>
                            <?php $categoryColor = $catalogCategoryColors[$catalogIndex % count($catalogCategoryColors)]; ?>
                            <a href="buy.php?<?php echo htmlspecialchars(http_build_query(['category' => $categoryName], '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8'); ?>"
                               class="catalog-card rounded-xl p-3.5 flex items-center gap-3">
                                <span class="w-9 h-9 rounded-lg bg-white/5 flex items-center justify-center <?php echo htmlspecialchars($categoryColor, ENT_QUOTES, 'UTF-8'); ?> shrink-0">
                                    <i class="bi bi-tag-fill"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-100 break-words"><?php echo htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="block text-[11px] text-gray-500 mt-0.5">
                                        <?php echo htmlspecialchars(Lang::t('catalog.products_count', ['count' => (int) $categoryCount]), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </span>
                                <i class="bi bi-chevron-right text-gray-600 text-xs"></i>
                            </a>
                            <?php $catalogIndex++; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

        <!-- Recent Keys -->
        <div class="glass rounded-xl overflow-hidden animate-fade-in" style="animation-delay: 0.3s;">
            <div class="p-6 border-b border-white/10">
                <h5 class="font-bold text-white"><i class="bi bi-clock-history mr-2"></i><span data-lang="reseller.recent_purchases"><?php echo Lang::t('reseller.recent_purchases'); ?></span></h5>
            </div>
            <div class="p-6">
                <?php if (empty($userKeys)): ?>
                    <div class="text-center py-8 text-gray-400" data-lang="reseller.no_keys_purchased_start">
                        <i class="bi bi-key text-4xl mb-3"></i>
                        <p><?php echo Lang::t('reseller.no_keys_purchased_start'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="dashboard.table.product"><?php echo Lang::t('dashboard.table.product'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="dashboard.table.duration"><?php echo Lang::t('dashboard.table.duration'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="dashboard.table.key"><?php echo Lang::t('dashboard.table.key'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="dashboard.table.paid"><?php echo Lang::t('dashboard.table.paid'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="dashboard.table.date"><?php echo Lang::t('dashboard.table.date'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($userKeys, 0, 10) as $index => $key): ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5 transition" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($key['product_name']); ?></td>
                                        <td class="py-3 px-4"><span class="px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400"><?php echo htmlspecialchars($key['duration'] ?? Lang::t('common.no_duration')); ?></span></td>
                                        <td class="py-3 px-4"><code class="text-accent"><?php echo htmlspecialchars($key['key_code']); ?></code></td>
                                        <td class="py-3 px-4 text-yellow-400"><?php echo formatCurrency($key['purchase_price'] !== null ? $key['purchase_price'] : $key['price_reseller']); ?></td>
                                        <td class="py-3 px-4 text-gray-400"><?php echo date(Lang::t('common.date_format'), strtotime($key['sold_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-center mt-6">
                        <a href="mykeys.php" class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition inline-block" data-lang="dashboard.view_my_keys">
                            <?php echo Lang::t('dashboard.view_my_keys'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
