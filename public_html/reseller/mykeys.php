<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cheatgame.php';

requireLogin();
if (!isReseller()) {
    authRedirect('index.php');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
// Keep key searches out of the URL because a user may paste an actual product
// key into the search box. Pagination submits the same read-only POST state.
$keySearch = isset($_POST['q']) && is_scalar($_POST['q']) ? substr(trim((string) $_POST['q']), 0, 180) : '';
$keyPerPage = 50;
$keyPage = isset($_POST['page']) && is_scalar($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
$keyOffset = ($keyPage - 1) * $keyPerPage;
$keyPageData = cgoGetUnifiedUserKeysPage($userId, $keyPerPage, $keyOffset, $keySearch);
$keyTotal = max(0, (int) ($keyPageData['total'] ?? 0));
$keyTotalPages = max(1, (int) ceil($keyTotal / $keyPerPage));
if ($keyPage > $keyTotalPages) {
    $keyPage = $keyTotalPages;
    $keyOffset = ($keyPage - 1) * $keyPerPage;
    $keyPageData = cgoGetUnifiedUserKeysPage($userId, $keyPerPage, $keyOffset, $keySearch);
}
$userKeys = $keyPageData['rows'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="mykeys.title"><?php echo Lang::t('mykeys.title'); ?> - Reseller Panel</title>
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
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in { animation: fadeInUp .15s ease-out; }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-6 space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:justify-between lg:items-center mb-6">
            <h3 class="text-2xl font-bold text-white">
                <i class="bi bi-key mr-2"></i><span data-lang="mykeys.title"><?php echo Lang::t('mykeys.title'); ?></span>
            </h3>
            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                <a href="key_resets.php" class="inline-flex items-center justify-center gap-2 rounded-lg border border-orange-500/30 bg-orange-500/10 px-4 py-2 text-sm font-medium text-orange-200 transition hover:bg-orange-500/20">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span data-lang="nav.key_reset"><?php echo Lang::t('nav.key_reset'); ?></span>
                </a>
                <form method="post" class="flex gap-2" role="search">
                    <input type="hidden" name="page" value="1">
                    <div class="relative min-w-0 flex-1 sm:w-80">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input name="q" type="search" autocomplete="off" value="<?php echo htmlspecialchars($keySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                            class="w-full rounded-lg border border-white/10 bg-black/20 py-2 pl-10 pr-3 text-sm text-white placeholder-gray-500 outline-none focus:border-green-500/50"
                            placeholder="<?php echo htmlspecialchars(Lang::t('mykeys.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <button type="submit" class="rounded-lg bg-green-500 px-3 py-2 text-sm font-bold text-white hover:bg-green-600" aria-label="<?php echo getAppLang() === 'th' ? 'ค้นหา' : 'Search'; ?>"><i class="bi bi-search"></i></button>
                    <?php if ($keySearch !== ''): ?><a href="mykeys.php" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-gray-300 hover:bg-white/10" aria-label="<?php echo getAppLang() === 'th' ? 'ล้างการค้นหา' : 'Clear search'; ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </form>
                <span class="px-3 py-1 rounded-full text-sm font-medium bg-green-500/20 text-green-400 border border-green-500/30 whitespace-nowrap">
                    <?php echo number_format($keyTotal); ?> <span data-lang="mykeys.keys_unit"><?php echo Lang::t('mykeys.keys_unit'); ?></span>
                </span>
            </div>
        </div>

        <div class="glass rounded-xl overflow-hidden animate-fade-in">
            <div class="p-6">
                <?php if (empty($userKeys)): ?>
                    <div class="text-center py-12">
                        <i class="bi bi-key text-gray-400" style="font-size: 5rem;"></i>
                        <p class="text-gray-400 mt-4"><?php echo htmlspecialchars($keySearch !== '' ? (getAppLang() === 'th' ? 'ไม่พบคีย์ที่ตรงกับคำค้นหา' : 'No keys match your search.') : Lang::t('mykeys.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                        <a href="buy.php" class="inline-block bg-green-500 hover:opacity-90 text-white px-6 py-3 rounded-lg font-medium transition mt-4">
                            <span data-lang="mykeys.buy_now"><?php echo Lang::t('mykeys.buy_now'); ?></span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.table.product"><?php echo Lang::t('common.table.product'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.table.duration"><?php echo Lang::t('common.table.duration'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.table.key"><?php echo Lang::t('common.table.key'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.table.price"><?php echo Lang::t('common.table.price'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.table.date"><?php echo Lang::t('common.table.date'); ?></th>
                                    <th class="text-left py-3 px-4 text-gray-400" data-lang="common.actions"><?php echo Lang::t('common.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="keyRows">
                                <?php foreach ($userKeys as $index => $key): ?>
                                    <?php
                                        $keyCode = trim((string) ($key['key_code'] ?? ''));
                                        $productName = (string) ($key['product_name'] ?? '');
                                        $accountItem = function_exists('cgoParseAccountDeliveryText')
                                            ? cgoParseAccountDeliveryText($keyCode)
                                            : null;
                                        $accountFields = is_array($accountItem)
                                            ? (array) ($accountItem['payload']['fields'] ?? [])
                                            : [];
                                        $searchText = $productName . ' ' . $keyCode . ' ' . (string) ($key['duration'] ?? '');
                                    ?>
                                    <tr
                                        class="border-b border-white/5 hover:bg-white/5 transition"
                                        style="animation-delay: <?php echo $index * 0.05; ?>s;"
                                        data-key-row
                                        data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="py-3 px-4"><span class="px-2 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400"><?php echo htmlspecialchars((string) ($key['duration'] ?? Lang::t('common.no_duration')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td class="py-3 px-4">
                                            <?php if ($accountFields): ?>
                                                <details class="group min-w-[300px] rounded-lg border border-green-400/20 bg-green-400/5 p-2">
                                                    <summary class="cursor-pointer list-none text-sm font-semibold text-green-300"><i class="bi bi-person-badge mr-1"></i>ข้อมูลบัญชีเกม <span class="ml-1 text-xs text-gray-500 group-open:hidden">แตะเพื่อดู</span></summary>
                                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                        <?php foreach ($accountFields as $field): ?>
                                                            <?php
                                                            $fieldValue = (string) ($field['value'] ?? '');
                                                            $wideField = in_array((string) ($field['name'] ?? ''), ['notes', 'url'], true) || strlen($fieldValue) > 80;
                                                            ?>
                                                            <div class="<?php echo $wideField ? 'sm:col-span-2' : ''; ?> rounded-md border border-white/5 bg-black/20 p-2">
                                                                <div class="mb-1 flex items-center justify-between gap-2 text-[10px] uppercase tracking-wide text-gray-500">
                                                                    <span><?php echo htmlspecialchars((string) ($field['label'] ?? $field['name'] ?? 'field'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                                    <?php if ($fieldValue !== ''): ?><button type="button" class="text-green-300" data-copy-value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><i class="bi bi-copy"></i></button><?php endif; ?>
                                                                </div>
                                                                <div class="whitespace-pre-wrap break-words font-mono text-xs text-gray-200"><?php echo $fieldValue !== '' ? htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '-'; ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <code class="text-accent break-all"><?php echo htmlspecialchars($keyCode, ENT_QUOTES, 'UTF-8'); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-yellow-400"><?php echo formatCurrency($key['purchase_price'] !== null ? $key['purchase_price'] : $key['price_reseller']); ?></td>
                                        <td class="py-3 px-4 text-gray-400 whitespace-nowrap"><?php echo date('M d, Y H:i', strtotime((string) $key['sold_at'])); ?></td>
                                        <td class="py-3 px-4">
                                            <button
                                                type="button"
                                                class="p-2 rounded-lg hover:bg-green-500/20 text-green-400 transition"
                                                data-copy-key="<?php echo htmlspecialchars($keyCode, ENT_QUOTES, 'UTF-8'); ?>"
                                                title="<?php echo htmlspecialchars(Lang::t('common.copy_key'), ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($keyTotalPages > 1): ?>
            <nav class="flex flex-wrap items-center justify-center gap-2" aria-label="<?php echo getAppLang() === 'th' ? 'หน้ารายการคีย์' : 'Key pages'; ?>">
                <?php if ($keyPage > 1): ?>
                    <form method="post"><input type="hidden" name="q" value="<?php echo htmlspecialchars($keySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><input type="hidden" name="page" value="<?php echo $keyPage - 1; ?>"><button type="submit" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-gray-200 hover:bg-white/10"><i class="bi bi-chevron-left mr-1"></i><?php echo getAppLang() === 'th' ? 'ก่อนหน้า' : 'Previous'; ?></button></form>
                <?php endif; ?>
                <span class="px-3 py-2 text-sm text-gray-400"><?php echo (getAppLang() === 'th' ? 'หน้า ' : 'Page ') . number_format($keyPage) . ' / ' . number_format($keyTotalPages); ?></span>
                <?php if ($keyPage < $keyTotalPages): ?>
                    <form method="post"><input type="hidden" name="q" value="<?php echo htmlspecialchars($keySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><input type="hidden" name="page" value="<?php echo $keyPage + 1; ?>"><button type="submit" class="rounded-lg border border-green-500/30 bg-green-500/10 px-3 py-2 text-sm text-green-200 hover:bg-green-500/20"><?php echo getAppLang() === 'th' ? 'ถัดไป' : 'Next'; ?><i class="bi bi-chevron-right ml-1"></i></button></form>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>

    <script>
        document.addEventListener('click', (event) => {
            const fieldButton = event.target.closest('[data-copy-value]');
            if (fieldButton) {
                const value = fieldButton.dataset.copyValue || '';
                if (value) Lang.copy(value);
                return;
            }
            const button = event.target.closest('[data-copy-key]');
            if (!button) return;
            const key = button.dataset.copyKey || '';
            if (key) Lang.copy(key);
        });

    </script>
</body>
</html>
