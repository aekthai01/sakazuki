<?php
// Dashboard dynamic fragment. Variables are prepared by dashboard_dynamic.php.
?>
    <?php
    $purchaseActivityAccent = 'blue';
    $purchaseActivityEndpoint = '../purchase_activity.php';
    include __DIR__ . '/../includes/purchase_activity.php';
    ?>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">

        <!-- Balance -->
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 transition animate-fade-in">
            <div class="flex justify-between items-center">
                <div>
                    <h4 class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2" data-lang="nav.balance"><?php echo Lang::t('nav.balance'); ?></h4>
                    <p class="text-2xl md:text-3xl font-semibold text-blue-400">
                        <?php echo formatCurrency($stats['balance']); ?>
                    </p>
                </div>
                <i class="bi bi-wallet2 text-blue-400 text-2xl md:text-3xl"></i>
            </div>
        </div>

        <!-- Keys Purchased -->
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 transition animate-fade-in" style="animation-delay: 0.1s;">
            <div class="flex justify-between items-center">
                <div>
                    <h4 class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2" data-lang="dashboard.keys_bought"><?php echo Lang::t('dashboard.keys_bought'); ?></h4>
                    <p class="text-2xl md:text-3xl font-semibold text-green-400">
                        <?php echo $stats['keys_bought']; ?>
                    </p>
                </div>
                <i class="bi bi-key text-green-400 text-2xl md:text-3xl"></i>
            </div>
            <a href="mykeys.php" class="bg-blue-500/20 hover:bg-blue-500/30 text-blue-400 px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 mt-3 md:mt-4">
                <i class="bi bi-eye"></i> <span data-lang="dashboard.view_my_keys"><?php echo Lang::t('dashboard.view_my_keys'); ?></span>
            </a>
        </div>

        <!-- Monthly Rank -->
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 transition animate-fade-in" style="animation-delay: 0.15s;">
            <div class="flex justify-between items-start gap-3">
                <div class="min-w-0">
                    <h4 class="text-gray-400 text-xs md:text-sm mb-2" data-lang="ranking.my_rank"><?php echo Lang::t('ranking.my_rank'); ?></h4>
                    <?php if (!empty($rankSnapshot['available'])): ?>
                    <p class="text-2xl md:text-3xl font-black text-violet-300 truncate"><?php echo htmlspecialchars(rankLabel((string) $rankSnapshot['monthly_rank_code']), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-xs text-gray-500 mt-1">
                        <span data-lang="ranking.my_position"><?php echo Lang::t('ranking.my_position'); ?></span>:
                        <span class="text-white font-bold"><?php echo $rankSnapshot['monthly_position'] !== null ? '#' . (int) $rankSnapshot['monthly_position'] : htmlspecialchars(Lang::t('ranking.not_ranked'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                    <?php else: ?>
                    <p class="text-sm font-semibold text-red-300" data-lang="ranking.error.unavailable"><?php echo htmlspecialchars(Lang::t('ranking.error.unavailable'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
                <i class="bi bi-trophy-fill text-violet-300 text-2xl md:text-3xl"></i>
            </div>
            <a href="rankings.php" class="bg-violet-500/15 hover:bg-violet-500/25 text-violet-200 px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 mt-3 md:mt-4">
                <i class="bi bi-bar-chart-fill"></i> <span data-lang="ranking.open_board"><?php echo Lang::t('ranking.open_board'); ?></span>
            </a>
        </div>
    </div>


    <!-- Product Catalogue -->
    <section class="glass rounded-2xl p-4 md:p-6 relative overflow-hidden animate-fade-in" style="animation-delay: 0.18s;">
        <div class="absolute -top-20 -right-20 w-56 h-56 rounded-full bg-gradient-to-br from-blue-500/20 to-violet-500/20 text-blue-300 blur-3xl opacity-40 pointer-events-none"></div>

        <div class="relative flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500/20 to-violet-500/20 text-blue-300 flex items-center justify-center shrink-0">
                    <i class="bi bi-grid-1x2-fill text-lg"></i>
                </div>
                <div>
                    <h4 class="text-lg font-bold text-white" data-lang="catalog.browse_title"><?php echo Lang::t('catalog.browse_title'); ?></h4>
                    <p class="text-xs md:text-sm text-gray-400 mt-1" data-lang="catalog.browse_desc"><?php echo Lang::t('catalog.browse_desc'); ?></p>
                </div>
            </div>
            <a href="buy.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border bg-blue-500/15 hover:bg-blue-500/25 border-blue-400/20 text-blue-200 text-sm font-semibold transition shrink-0">
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
                            <span class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-blue-300 shrink-0">
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
                    <?php $catalogCategoryColors = ['text-violet-300','text-sky-300','text-fuchsia-300','text-cyan-300','text-amber-300']; ?>
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
    <div class="glass rounded-lg md:rounded-xl overflow-hidden animate-fade-in" style="animation-delay: 0.2s;">
        <div class="p-4 md:p-6 border-b border-white/10">
            <h5 class="font-bold text-white text-sm md:text-base" data-lang="dashboard.recent_purchases">
                <i class="bi bi-clock-history mr-2"></i><?php echo Lang::t('dashboard.recent_purchases'); ?>
            </h5>
        </div>

        <div class="p-3 md:p-6">

            <?php if (empty($userKeys)): ?>
                <div class="text-center py-6 md:py-8 text-gray-400">
                    <i class="bi bi-key text-2xl md:text-4xl mb-2 md:mb-3"></i>
                    <p class="text-sm md:text-base">
                        <span data-lang="dashboard.empty_keys"><?php echo Lang::t('dashboard.empty_keys'); ?></span>
                        <a href="buy.php" class="text-blue-400 hover:underline" data-lang="dashboard.start_buying"><?php echo Lang::t('dashboard.start_buying'); ?></a>
                    </p>
                </div>
            <?php else: ?>

                <div class="overflow-x-auto -mx-3 md:mx-0">
                    <table class="w-full min-w-[600px] md:min-w-full">

                        <thead>
                        <tr class="border-b border-white/10">
                            <th class="text-left py-3 px-4 text-gray-400 text-sm" data-lang="common.table.product"><?php echo Lang::t('common.table.product'); ?></th>
                            <th class="text-left py-3 px-4 text-gray-400 text-sm" data-lang="common.table.duration"><?php echo Lang::t('common.table.duration'); ?></th>
                            <th class="text-left py-3 px-4 text-gray-400 text-sm" data-lang="common.table.key"><?php echo Lang::t('common.table.key'); ?></th>
                            <th class="text-left py-3 px-4 text-gray-400 text-sm" data-lang="common.table.price"><?php echo Lang::t('common.table.price'); ?></th>
                            <th class="text-left py-3 px-4 text-gray-400 text-sm" data-lang="common.table.date"><?php echo Lang::t('common.table.date'); ?></th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach (array_slice($userKeys, 0, 5) as $index => $key): ?>
                            <tr class="border-b border-white/5 hover:bg-white/5 transition">

                                <td class="py-3 px-4 text-sm">
                                    <?php echo htmlspecialchars($key['product_name']); ?>
                                </td>

                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-blue-500/20 text-blue-400">
                                        <?php echo htmlspecialchars($key['duration'] ?? Lang::t('common.no_duration')); ?>
                                    </span>
                                </td>

                                <td class="py-3 px-4">
                                    <code class="text-accent text-sm">
                                        <?php echo htmlspecialchars($key['key_code']); ?>
                                    </code>
                                </td>

                                <td class="py-3 px-4 text-yellow-400 text-sm">
                                    <?php echo formatCurrency($key['price_user']); ?>
                                </td>

                                <td class="py-3 px-4 text-gray-400 text-sm">
                                    <?php echo date(Lang::t('common.date_format'), strtotime($key['sold_at'])); ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

                <div class="text-center mt-6">
                    <a href="mykeys.php"
                       class="bg-accent hover:opacity-90 text-white px-6 py-2 rounded-lg font-medium transition inline-block text-sm" data-lang="dashboard.view_all_keys">
                        <?php echo Lang::t('dashboard.view_all_keys'); ?>
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>
