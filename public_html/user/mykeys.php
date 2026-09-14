<?php
require_once '../includes/auth.php';
require_once '../includes/cheatgame.php';
requireLogin();

if (!isUser()) {
    header('Location: ../index.php');
    exit();
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
    <title data-lang="mykeys.title"><?php echo Lang::t('mykeys.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#3b82f6;--sakazuki-accent-rgb:59 130 246;--sakazuki-accent2:#60a5fa;--sakazuki-accent2-rgb:96 165 250;--sakazuki-glow:0 0 25px rgba(59,130,246,0.35)}</style>
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
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .animate-fade-in {
            animation: fadeInUp .15s ease-out;
        }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 md:space-y-6">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 md:mb-6 gap-3">
            <h3 class="text-xl md:text-2xl font-bold text-white"><i class="bi bi-key mr-2"></i><span data-lang="nav.my_keys"><?php echo Lang::t('nav.my_keys'); ?></span></h3>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center md:w-auto">
                <form method="post" class="flex w-full gap-2 sm:w-auto" role="search">
                    <input type="hidden" name="page" value="1">
                    <div class="relative min-w-0 flex-1 sm:w-72">
                        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input name="q" type="search" autocomplete="off" value="<?php echo htmlspecialchars($keySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                            class="w-full rounded-lg border border-white/10 bg-black/20 py-2 pl-10 pr-3 text-sm text-white placeholder-gray-500 outline-none focus:border-blue-500/50"
                            placeholder="<?php echo htmlspecialchars(Lang::t('mykeys.search_placeholder') ?: 'ค้นหาสินค้า คีย์ หรือระยะเวลา', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                            aria-label="<?php echo htmlspecialchars(Lang::t('mykeys.search_placeholder') ?: 'ค้นหาคีย์', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    </div>
                    <button type="submit" class="rounded-lg bg-blue-500 px-3 py-2 text-sm font-bold text-white hover:bg-blue-600" aria-label="<?php echo getAppLang() === 'th' ? 'ค้นหา' : 'Search'; ?>"><i class="bi bi-search"></i></button>
                    <?php if ($keySearch !== ''): ?><a href="mykeys.php" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-gray-300 hover:bg-white/10" aria-label="<?php echo getAppLang() === 'th' ? 'ล้างการค้นหา' : 'Clear search'; ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </form>
                <span class="px-2 py-1 rounded-full text-xs md:text-sm font-medium bg-blue-500/20 text-blue-400 border border-blue-500/30 whitespace-nowrap">
                    <?php echo number_format($keyTotal); ?> <span data-lang="dashboard.keys_count"><?php echo Lang::t('dashboard.keys_count'); ?></span>
                </span>
            </div>
        </div>

        <div class="glass rounded-lg md:rounded-xl overflow-hidden animate-fade-in">
            <div class="p-4 md:p-6">
                <?php if (empty($userKeys)): ?>
                    <div class="text-center py-8 md:py-12">
                        <i class="bi bi-key text-gray-400 text-4xl md:text-5xl"></i>
                        <p class="text-gray-400 mt-3 md:mt-4 text-sm md:text-base"><?php echo htmlspecialchars($keySearch !== '' ? (getAppLang() === 'th' ? 'ไม่พบคีย์ที่ตรงกับคำค้นหา' : 'No keys match your search.') : Lang::t('reseller.no_keys_purchased'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                        <a href="buy.php" class="inline-block bg-blue-500 hover:opacity-90 text-white px-4 py-2 md:px-6 md:py-3 rounded-lg font-medium transition mt-3 md:mt-4 text-sm md:text-base" data-lang="nav.buy">
                            <?php echo Lang::t('nav.buy'); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto -mx-2 md:mx-0">
                        <table class="w-full min-w-[700px] md:min-w-full">
                            <thead>
                                <tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.table.product"><?php echo Lang::t('common.table.product'); ?></th>
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.table.duration"><?php echo Lang::t('common.table.duration'); ?></th>
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.table.key"><?php echo Lang::t('common.table.key'); ?></th>
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.table.price"><?php echo Lang::t('common.table.price'); ?></th>
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.table.date"><?php echo Lang::t('common.table.date'); ?></th>
                                    <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.actions"><?php echo Lang::t('common.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userKeys as $index => $key): ?>
                                    <?php
                                    $keyCode = trim((string) ($key['key_code'] ?? ''));
                                    $accountItem = function_exists('cgoParseAccountDeliveryText')
                                        ? cgoParseAccountDeliveryText($keyCode)
                                        : null;
                                    $accountFields = is_array($accountItem)
                                        ? (array) ($accountItem['payload']['fields'] ?? [])
                                        : [];
                                    $searchText = (string) ($key['product_name'] ?? '') . ' ' . $keyCode . ' ' . (string) ($key['duration'] ?? '');
                                    ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5 transition" style="animation-delay: <?php echo $index * 0.05; ?>s;"
                                        data-user-key-row data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                        <td class="py-2 px-2 md:py-3 md:px-4 font-medium text-xs md:text-sm truncate max-w-[120px]"><?php echo htmlspecialchars($key['product_name']); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4"><span class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-blue-500/20 text-blue-400"><?php echo htmlspecialchars($key['duration'] ?? Lang::t('common.no_duration')); ?></span></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <?php if ($accountFields): ?>
                                                <details class="group min-w-[260px] rounded-lg border border-blue-400/20 bg-blue-400/5 p-2">
                                                    <summary class="cursor-pointer list-none text-xs font-semibold text-blue-300"><i class="bi bi-person-badge mr-1"></i>ข้อมูลบัญชีเกม <span class="ml-1 text-gray-500 group-open:hidden">แตะเพื่อดู</span></summary>
                                                    <div class="mt-2 grid gap-2">
                                                        <?php foreach ($accountFields as $field): ?>
                                                            <?php $fieldValue = (string) ($field['value'] ?? ''); ?>
                                                            <div class="rounded-md border border-white/5 bg-black/20 p-2">
                                                                <div class="mb-1 flex items-center justify-between gap-2 text-[10px] uppercase tracking-wide text-gray-500">
                                                                    <span><?php echo htmlspecialchars((string) ($field['label'] ?? $field['name'] ?? 'field'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                                                    <?php if ($fieldValue !== ''): ?><button type="button" class="copy-field-btn text-blue-300" data-copy-value="<?php echo htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><i class="bi bi-copy"></i></button><?php endif; ?>
                                                                </div>
                                                                <div class="whitespace-pre-wrap break-words font-mono text-xs text-gray-200"><?php echo $fieldValue !== '' ? htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '-'; ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <code class="text-accent text-xs md:text-sm font-mono break-all"><?php echo htmlspecialchars($keyCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-yellow-400 text-xs md:text-sm"><?php echo formatCurrency($key['price_user']); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm"><?php echo date('M d, Y', strtotime($key['sold_at'])); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <button type="button" class="copy-key-btn p-1.5 md:p-2 rounded-lg hover:bg-blue-500/20 text-blue-400 transition" data-key="<?php echo htmlspecialchars($keyCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" title="<?php echo Lang::t('common.copy_key'); ?>">
                                                <i class="bi bi-clipboard text-sm md:text-base"></i>
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
                    <form method="post"><input type="hidden" name="q" value="<?php echo htmlspecialchars($keySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><input type="hidden" name="page" value="<?php echo $keyPage + 1; ?>"><button type="submit" class="rounded-lg border border-blue-500/30 bg-blue-500/10 px-3 py-2 text-sm text-blue-200 hover:bg-blue-500/20"><?php echo getAppLang() === 'th' ? 'ถัดไป' : 'Next'; ?><i class="bi bi-chevron-right ml-1"></i></button></form>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>

    <script>
        function copyToClipboard(text) {
            Lang.copy(text);
        }

        document.addEventListener('click', function (event) {
            const fieldButton = event.target.closest('[data-copy-value]');
            if (fieldButton) {
                copyToClipboard(fieldButton.dataset.copyValue || '');
                return;
            }
            const button = event.target.closest('.copy-key-btn');
            if (!button) return;
            copyToClipboard(button.dataset.key || '');
        });

    </script>
</body>
</html>
