<?php
require_once '../includes/auth.php';
require_once '../includes/cheatgame.php';
requireLogin();

if (!isUser()) {
    header('Location: ../index.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$user = getUserById($userId);

global $conn;
$totalRefill = 0.0;
$refillStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE user_id = ? AND (LOWER(TRIM(COALESCE(type,''))) IN ('deposit','redeem_code') OR (TRIM(COALESCE(type,''))='' AND LOWER(COALESCE(description,'')) LIKE 'redeemed top-up code %')) AND LOWER(TRIM(COALESCE(status,'')))='completed'");
if ($refillStmt) {
    $refillStmt->bind_param('i', $userId);
    if ($refillStmt->execute()) {
        $refillResult = $refillStmt->get_result();
        $refillRow = $refillResult ? $refillResult->fetch_assoc() : null;
        $totalRefill = (float) ($refillRow['total'] ?? 0);
    }
    $refillStmt->close();
}

// Keep purchase-history searches out of the URL because users may paste
// a delivered product key into the search box. Pagination carries the same
// read-only POST state, matching My Keys without writing sensitive terms to
// access logs or browser history.
$historySearch = isset($_POST['q']) && is_scalar($_POST['q']) ? substr(trim((string) $_POST['q']), 0, 180) : '';
$historyPerPage = 50;
$historyPage = isset($_POST['page']) && is_scalar($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
$historyOffset = ($historyPage - 1) * $historyPerPage;
$purchaseHistory = function_exists('cgoGetUnifiedPurchaseGroupsPage')
    ? cgoGetUnifiedPurchaseGroupsPage($userId, 'user', $historyPerPage, $historyOffset, $historySearch)
    : cgoGetUnifiedPurchaseGroups($userId, 'user');
$groupedPurchases = $purchaseHistory['groups'] ?? [];
$totalKeysBought = (int) ($purchaseHistory['total_keys'] ?? 0);
$historyHasMore = !empty($purchaseHistory['has_more']);
$walletTimeline = function_exists('walletLedgerGetUserTimeline') ? walletLedgerGetUserTimeline($userId, 30) : [];
$walletSummary = function_exists('walletLedgerGetSummary') ? walletLedgerGetSummary($userId) : ['available' => false, 'status' => 'unavailable'];
$walletCurrentBalance = isset($user['balance']) ? (float) $user['balance'] : (float) getUserBalance($userId);
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="history.title"><?php echo Lang::t('history.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .key-item {
            transition: all .15s;
        }

        .hidden-keys {
            display: none;
        }

        .show-keys .hidden-keys {
            display: block;
        }

        input:focus {
            outline: none;
        }
    </style>
</head>

<body class="bg-[#0b101e] text-gray-100 min-h-screen pb-10">
    <?php include 'nav.php'; ?>

    <main class="max-w-md mx-auto p-4 space-y-4 pt-6">

        <!-- Refill History Card -->
        <a href="deposit.php"
            class="block bg-[#161c2d] border border-white/5 rounded-xl p-4 flex justify-between items-center hover:bg-[#1a2135] transition shadow-md">
            <div class="flex items-center gap-3">
                <div class="text-[#22c55e] bg-[#22c55e]/10 px-3 py-2 rounded-lg flex items-center justify-center">
                    <i class="bi bi-receipt text-[18px]"></i>
                </div>
                <span class="text-[#22c55e] font-bold text-[16px]" data-lang="history.refill_title"><?php echo Lang::t('history.refill_title'); ?></span>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right flex flex-col items-end">
                    <div class="text-gray-400 text-[10px] sm:text-[11px]" data-lang="history.total_refill"><?php echo Lang::t('history.total_refill'); ?></div>
                    <div class="text-white font-bold text-[15px] sm:text-[16px]">
                        <?php echo formatCurrency($totalRefill); ?>
                    </div>
                </div>
                <!-- Triangle Icon -->
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="text-gray-500">
                    <path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
                </svg>
            </div>
        </a>

        <!-- Profile Card -->
        <div class="bg-[#161c2d] border border-white/5 rounded-xl p-4 flex justify-between items-center shadow-md">
            <div class="flex items-center gap-3">
                <div
                    class="bg-[#3b82f6] text-white w-9 h-9 rounded-full flex items-center justify-center font-bold text-lg">
                    <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                </div>
                <span
                    class="text-white font-medium text-[15px]"><?php echo htmlspecialchars($user['username']); ?></span>
            </div>
            <!-- Triangle Icon -->
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="text-gray-500">
                <path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z" />
            </svg>
        </div>


        <!-- Balance Audit Timeline: owner-safe fields only. Sensitive bank/provider/admin evidence is Admin-only. -->
        <section class="bg-[#161c2d] border border-white/5 rounded-xl overflow-hidden shadow-md" aria-labelledby="walletAuditTitle">
            <div class="p-4 border-b border-white/5 flex items-center justify-between gap-3">
                <div>
                    <h2 id="walletAuditTitle" class="text-white font-bold text-[16px]"><?php echo getAppLang() === 'en' ? 'Balance history' : 'ประวัติยอดเงิน'; ?></h2>
                    <p class="text-gray-500 text-[11px] mt-0.5"><?php echo getAppLang() === 'en' ? 'Tracked money movements and refunds' : 'ดูที่มาของเงินเข้า เงินออก และเงินคืน'; ?></p>
                </div>
                <div class="text-right">
                    <div class="text-gray-500 text-[10px]"><?php echo getAppLang() === 'en' ? 'Current balance' : 'ยอดปัจจุบัน'; ?></div>
                    <div class="text-[#22c55e] font-bold text-[16px]"><?php echo formatCurrency($walletCurrentBalance); ?></div>
                </div>
            </div>

            <?php if (!empty($walletSummary['available'])): ?>
                <?php $auditStatus = (string) ($walletSummary['status'] ?? 'unavailable'); ?>
                <div class="px-4 py-3 border-b border-white/5 text-[11px] <?php echo $auditStatus === 'ok' ? 'text-emerald-300 bg-emerald-500/5' : 'text-amber-300 bg-amber-500/5'; ?>">
                    <?php if ($auditStatus === 'ok'): ?>
                        <i class="bi bi-shield-check mr-1"></i><?php echo getAppLang() === 'en' ? 'New detailed balance records are consistent.' : 'หลักฐานยอดเงินแบบละเอียดช่วงใหม่ตรวจสอบตรงกัน'; ?>
                    <?php elseif ($auditStatus === 'discrepancy'): ?>
                        <i class="bi bi-exclamation-triangle mr-1"></i><?php echo getAppLang() === 'en' ? 'A balance difference was detected. Please contact an administrator.' : 'ตรวจพบส่วนต่างของยอดเงิน กรุณาติดต่อผู้ดูแลระบบเพื่อตรวจสอบ'; ?>
                    <?php elseif ($auditStatus === 'chain_gap'): ?>
                        <i class="bi bi-exclamation-triangle mr-1"></i><?php echo getAppLang() === 'en' ? 'A gap was detected in the new balance audit chain.' : 'ตรวจพบช่วงขาดในหลักฐานยอดเงินแบบละเอียด'; ?>
                    <?php else: ?>
                        <?php echo getAppLang() === 'en' ? 'Balance audit status is unavailable.' : 'ยังไม่สามารถตรวจสถานะหลักฐานยอดเงินได้'; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="divide-y divide-white/5 max-h-[420px] overflow-y-auto">
                <?php if (empty($walletTimeline)): ?>
                    <div class="p-5 text-center text-gray-500 text-[12px]"><?php echo getAppLang() === 'en' ? 'No balance evidence is available yet.' : 'ยังไม่มีหลักฐานยอดเงินให้แสดง'; ?></div>
                <?php else: ?>
                    <?php foreach ($walletTimeline as $movement): ?>
                        <?php
                        $delta = (float) ($movement['delta_amount'] ?? 0);
                        $isLegacy = !empty($movement['_legacy_evidence']);
                        $isBaseline = (string) ($movement['source_type'] ?? '') === 'legacy_baseline';
                        $movementTime = strtotime((string) ($movement['created_at'] ?? ''));
                        ?>
                        <div class="p-3.5 flex gap-3 items-start">
                            <div class="w-8 h-8 shrink-0 rounded-lg flex items-center justify-center <?php echo $delta > 0.00001 ? 'bg-emerald-500/10 text-emerald-400' : ($delta < -0.00001 ? 'bg-rose-500/10 text-rose-400' : 'bg-slate-500/10 text-slate-400'); ?>">
                                <i class="bi <?php echo $delta > 0.00001 ? 'bi-arrow-down-left' : ($delta < -0.00001 ? 'bi-arrow-up-right' : 'bi-flag'); ?>"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-gray-200 text-[12px] font-medium break-words"><?php echo htmlspecialchars((string) ($movement['public_note'] ?? '')); ?></div>
                                    <div class="whitespace-nowrap font-bold text-[12px] <?php echo $delta > 0.00001 ? 'text-emerald-400' : ($delta < -0.00001 ? 'text-rose-400' : 'text-gray-400'); ?>">
                                        <?php echo $isBaseline ? '—' : (($delta > 0 ? '+' : '') . formatCurrency($delta)); ?>
                                    </div>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-gray-500">
                                    <span><?php echo $movementTime ? date('d/m/Y H:i', $movementTime) : '-'; ?></span>
                                    <?php if ($isLegacy): ?><span class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-300">LEGACY</span><?php endif; ?>
                                    <?php if ($movement['balance_before'] !== null && $movement['balance_after'] !== null): ?>
                                        <span><?php echo getAppLang() === 'en' ? 'Balance' : 'ยอด'; ?>: <?php echo formatCurrency((float) $movement['balance_before']); ?> → <?php echo formatCurrency((float) $movement['balance_after']); ?></span>
                                    <?php elseif ($isLegacy): ?>
                                        <span><?php echo getAppLang() === 'en' ? 'Historical before/after balance was not stored.' : 'ข้อมูลเก่าไม่มีการเก็บยอดก่อน/หลัง'; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="px-4 py-3 bg-black/10 text-gray-500 text-[10px] leading-relaxed">
                <?php echo getAppLang() === 'en'
                    ? 'LEGACY items are reconstructed only from existing evidence. No historical balance is invented. Sensitive slip and provider evidence is visible only to administrators.'
                    : 'รายการ LEGACY แสดงจากหลักฐานเดิมเท่าที่พิสูจน์ได้เท่านั้น ระบบจะไม่สร้างยอดก่อน/หลังย้อนหลังขึ้นมาเอง และข้อมูลสลิปหรือผู้ให้บริการที่ละเอียดอ่อนจะแสดงเฉพาะผู้ดูแลระบบ'; ?>
            </div>
        </section>

        <!-- Detail Modal -->
        <div class="fixed inset-0 bg-black/80 backdrop-blur-sm z-[1000] hidden items-center justify-center p-4" id="detailModalOverlay" aria-hidden="true">
            <div class="bg-[#1b2336] rounded-xl overflow-hidden shadow-2xl w-full max-w-[340px] transform transition-all" id="detailModal" role="dialog" aria-modal="true" tabindex="-1">
                <div class="p-5 pb-3 flex justify-between items-center">
                    <h5 class="font-bold text-white text-[16px]" data-lang="history.detail_title"><?php echo Lang::t('history.detail_title'); ?></h5>
                    <button type="button" aria-label="Close" onclick="closeDetailModal()" class="text-gray-400 hover:text-white text-xl transition-colors">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
                <div class="p-5 pt-0 space-y-4">
                    <div>
                        <div class="text-gray-400 text-[13px] mb-1" data-lang="common.table.product"><?php echo Lang::t('common.table.product'); ?></div>
                        <div class="text-white text-[15px]" id="modalProductName"></div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-[13px] mb-1" data-lang="common.table.date"><?php echo Lang::t('common.table.date'); ?></div>
                        <div class="text-white text-[15px]" id="modalDate"></div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-[13px] mb-2" data-lang="buy.license_keys"><?php echo Lang::t('buy.license_keys'); ?></div>
                        <div class="bg-[#121827] border border-white/5 rounded-lg p-3 text-gray-300 font-mono text-[14px] break-all max-h-32 overflow-y-auto" id="modalKeys">
                        </div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-[13px] mb-1" data-lang="common.table.price"><?php echo Lang::t('common.table.price'); ?></div>
                        <div class="text-[#22c55e] font-bold text-[16px]" id="modalPrice"></div>
                    </div>
                    <div class="pt-2 space-y-2">
                        <button type="button" onclick="copyModalKeys()" class="w-full bg-[#22c55e] hover:bg-green-600 text-white rounded-lg py-2.5 text-[14px] font-medium flex items-center justify-center gap-2 transition" id="modalCopyBtn">
                            <i class="bi bi-intersect"></i> <span data-lang="common.copy_all"><?php echo Lang::t('common.copy_all'); ?></span>
                        </button>
                        <a href="#" target="_blank" id="modalDownloadBtn" class="w-full bg-gradient-to-r from-[#ef4444] to-[#f97316] hover:opacity-90 text-white rounded-lg py-2.5 text-[14px] font-medium flex items-center justify-center gap-2 transition hidden">
                            <i class="bi bi-download"></i> <span data-lang="common.download"><?php echo Lang::t('common.download'); ?></span>
                        </a>
                        <button type="button" onclick="closeDetailModal()" class="w-full bg-[#2a3454] hover:bg-[#323d60] text-gray-300 rounded-lg py-2.5 text-[14px] font-medium transition" data-lang="common.close">
                            <?php echo Lang::t('common.close'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchases History Card -->
        <div class="bg-[#161c2d] border border-white/5 rounded-xl overflow-hidden mt-6 shadow-md">
            <div class="p-4 border-b border-white/5 flex items-center gap-2">
                <h2 class="text-white font-bold text-[17px]" data-lang="history.title"><?php echo Lang::t('history.title'); ?></h2>
                <span class="text-[#22c55e] text-sm flex items-center gap-1 font-medium"><span data-lang="history.total"><?php echo Lang::t('history.total'); ?></span> <svg width="14"
                        height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" class="mx-0.5">
                        <path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4" />
                        <path d="m21 2-9.6 9.6" />
                        <circle cx="7.5" cy="15.5" r="5.5" />
                    </svg> <?php echo $totalKeysBought; ?></span>
            </div>
            <form class="p-4" method="POST" action="history.php" role="search">
                <input type="hidden" name="page" value="1">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="search" name="q" value="<?php echo htmlspecialchars($historySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                            class="w-full bg-[#1b233d] border border-white/5 rounded-lg py-2.5 pl-10 pr-4 text-[14px] text-white placeholder-gray-500 focus:border-[#22c55e]/50 focus:ring-1 focus:ring-[#22c55e]/50 transition-all"
                            data-lang-placeholder="history.search_placeholder"
                            placeholder="<?php echo Lang::t('history.search_placeholder'); ?>">
                    </div>
                    <button type="submit" aria-label="<?php echo getAppLang() === 'en' ? 'Search purchase history' : 'ค้นหาประวัติการซื้อ'; ?>"
                        class="shrink-0 bg-[#22c55e] hover:bg-green-600 text-white rounded-lg px-3.5 transition">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
                <?php if ($historySearch !== ''): ?>
                    <div class="mt-2 flex items-center justify-between gap-2 text-[11px]">
                        <span class="text-gray-500"><?php echo getAppLang() === 'en' ? 'Searching all purchase history' : 'กำลังค้นหาจากประวัติการซื้อทั้งหมด'; ?></span>
                        <a href="history.php" class="text-[#22c55e] hover:text-green-300"><?php echo getAppLang() === 'en' ? 'Clear search' : 'ล้างการค้นหา'; ?></a>
                    </div>
                <?php endif; ?>
            </form>
            <div id="keysList" class="px-4 pb-4 space-y-3">
                <?php if (empty($groupedPurchases)): ?>
                    <div class="text-center py-8 text-gray-500 text-sm" data-lang="reseller.no_keys_purchased">
                        <i class="bi bi-inbox text-3xl mb-2 block"></i>
                        <?php echo Lang::t('reseller.no_keys_purchased'); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedPurchases as $index => $group): ?>
                        <?php
                        $qty = count($group['keys']);
                        $isLatest = ($historyPage === 1 && $historySearch === '' && $index === 0);
                        $totalPriceText = $qty > 1 ? formatCurrency($group['price_per_item']) . ' &times; ' . $qty . ' = ' . formatCurrency($group['total_price']) : formatCurrency($group['total_price']);
                        ?>
                        <div class="bg-[#1b233d] border border-white/5 rounded-xl p-3.5 key-item"
                            data-search="<?php echo htmlspecialchars(strtolower($group['product_name'] . ' ' . implode(' ', $group['keys']))); ?>">
                            <div class="flex justify-between items-start mb-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span
                                        class="font-bold text-gray-200 text-[14px]"><?php echo htmlspecialchars($group['product_name']); ?></span>
                                    <?php if ($qty > 1): ?>
                                        <span
                                            class="bg-[#3b82f6]/20 text-blue-400 text-[10px] font-bold px-1.5 py-0.5 rounded">x<?php echo $qty; ?></span>
                                    <?php endif; ?>
                                    <?php if ($isLatest): ?>
                                        <span
                                            class="bg-[#22c55e]/10 border border-[#22c55e]/20 text-[#22c55e] text-[10px] font-bold px-1.5 py-0.5 rounded" data-lang="history.latest"><?php echo Lang::t('history.latest'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[#22c55e] font-bold text-[14px] whitespace-nowrap ml-2">
                                    <?php echo $totalPriceText; ?>
                                </div>
                            </div>

                            <div class="flex justify-between items-center mb-3">
                                <div class="text-gray-400 text-[11px] flex items-center gap-1.5">
                                    <i class="bi bi-calendar3"></i> <?php echo $group['display_date']; ?>
                                </div>
                                <div class="text-gray-400 text-[11px]">
                                    <span data-lang="history.main_balance"><?php echo Lang::t('history.main_balance'); ?></span> <span
                                        class="text-[#3b82f6] ml-0.5"><?php echo formatCurrency($group['balance_after'] ?? 0); ?></span>
                                </div>
                            </div>

                            <div class="key-container">
                                <?php
                                $firstKey = (string) ($group['keys'][0] ?? '');
                                $accountItem = function_exists('cgoParseAccountDeliveryText')
                                    ? cgoParseAccountDeliveryText($firstKey)
                                    : null;
                                $accountFields = is_array($accountItem)
                                    ? (array) (($accountItem['payload']['fields'] ?? []))
                                    : [];
                                $accountLabels = [
                                    'account' => 'บัญชี',
                                    'email' => 'อีเมล',
                                    'phone' => 'เบอร์โทร',
                                    'password_account' => 'รหัสผ่านบัญชี',
                                    'password_email' => 'รหัสผ่านอีเมล',
                                    'recovery_email' => 'อีเมลกู้คืน',
                                    'recovery_password' => 'รหัสผ่านกู้คืน',
                                    'pin' => 'PIN',
                                    'secret' => 'Secret',
                                    'token' => 'Token',
                                    'url' => 'ลิงก์',
                                    'notes' => 'คำแนะนำ',
                                    'server' => 'เซิร์ฟเวอร์',
                                    'region' => 'ภูมิภาค',
                                ];
                                ?>

                                <?php if ($accountFields): ?>
                                    <div class="mt-2 rounded-xl border border-white/5 bg-[#121827] overflow-hidden">
                                        <div class="flex items-center justify-between gap-3 px-3 py-2.5 border-b border-white/5 bg-white/[0.02]">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2 text-[13px] font-semibold text-gray-200">
                                                    <i class="bi bi-person-vcard text-[#3b82f6]"></i>
                                                    <span>ข้อมูลบัญชีเกม</span>
                                                </div>
                                                <div class="text-[10px] text-gray-500 mt-0.5">หนึ่งรายการส่งมอบ · หลายช่องข้อมูล</div>
                                            </div>
                                            <button type="button"
                                                onclick="Lang.copy(this.getAttribute('data-account'))"
                                                data-account="<?php echo htmlspecialchars($firstKey, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="bg-[#3b82f6] hover:bg-blue-600 text-white rounded-lg px-3 h-[34px] text-[12px] flex items-center justify-center gap-1.5 transition whitespace-nowrap">
                                                <i class="bi bi-copy"></i>
                                                <span data-lang="buy.copy_all"><?php echo Lang::t('buy.copy_all'); ?></span>
                                            </button>
                                        </div>

                                        <div class="divide-y divide-white/5">
                                            <?php foreach ($accountFields as $accountField):
                                                $fieldName = strtolower(trim((string) ($accountField['name'] ?? '')));
                                                $fieldValue = (string) ($accountField['value'] ?? '');
                                                $fieldLabel = $accountLabels[$fieldName]
                                                    ?? trim((string) ($accountField['label'] ?? $fieldName));
                                                if ($fieldLabel === '') $fieldLabel = 'ข้อมูล';
                                                $isLongField = $fieldName === 'notes' || strlen($fieldValue) > 100 || strpos($fieldValue, "\n") !== false;
                                            ?>
                                                <div class="px-3 py-2.5 flex <?php echo $isLongField ? 'flex-col items-stretch' : 'items-center'; ?> gap-2">
                                                    <div class="<?php echo $isLongField ? '' : 'w-[112px] shrink-0'; ?> text-[10px] uppercase tracking-wide text-gray-500">
                                                        <?php echo htmlspecialchars($fieldLabel); ?>
                                                    </div>
                                                    <div class="flex-1 min-w-0 flex items-<?php echo $isLongField ? 'start' : 'center'; ?> gap-2">
                                                        <div class="flex-1 min-w-0 text-gray-200 font-mono text-[12px] <?php echo $isLongField ? 'whitespace-pre-wrap break-words leading-relaxed' : 'truncate'; ?> select-text">
                                                            <?php echo $fieldValue !== '' ? htmlspecialchars($fieldValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '<span class="text-gray-600">—</span>'; ?>
                                                        </div>
                                                        <?php if ($fieldValue !== ''): ?>
                                                            <button type="button"
                                                                onclick="Lang.copy(<?php echo htmlJsArg($fieldValue); ?>)"
                                                                class="shrink-0 w-[32px] h-[32px] rounded-lg border border-white/5 bg-white/[0.03] hover:bg-white/[0.08] text-gray-400 hover:text-white transition flex items-center justify-center"
                                                                aria-label="คัดลอก <?php echo htmlspecialchars($fieldLabel); ?>">
                                                                <i class="bi bi-copy text-[12px]"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="px-3 py-2.5 border-t border-white/5 bg-white/[0.015]">
                                            <button type="button"
                                                onclick="openDetailModal(<?php echo htmlJsArg((string)$group['product_name']); ?>, <?php echo htmlJsArg((string)$group['date']); ?>, <?php echo htmlJsArg($firstKey); ?>, <?php echo htmlJsArg(formatCurrency($group['total_price'])); ?>, <?php echo htmlJsArg((string)$group['download_url']); ?>)"
                                                class="w-full h-[36px] rounded-lg border border-white/5 text-gray-400 hover:text-white hover:bg-white/5 text-[12px] flex items-center justify-center gap-2 transition">
                                                <i class="bi bi-eye"></i>
                                                <span>ดูข้อมูลแบบเต็ม</span>
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- First key -->
                                    <div class="flex items-center gap-2 mt-2">
                                        <div
                                            class="flex-1 bg-[#121827] border border-white/5 rounded-lg p-2.5 text-gray-300 font-mono text-[13px] overflow-hidden text-ellipsis whitespace-nowrap leading-none select-all relative group h-[38px] flex items-center">
                                            <span class="key-text"><?php echo htmlspecialchars(preg_replace('/^(.{15})(.*)$/', '$1...', $firstKey)); ?></span>
                                            <span class="key-full hidden"><?php echo htmlspecialchars($firstKey); ?></span>
                                        </div>
                                        <button type="button"
                                            onclick="Lang.copy(<?php echo htmlJsArg($firstKey); ?>)"
                                            class="bg-[#3b82f6] hover:bg-blue-600 text-white rounded-lg px-3 h-[38px] text-[13px] flex w-auto items-center justify-center gap-1.5 transition whitespace-nowrap">
                                            <i class="bi bi-intersect"></i> <span class="btn-text" data-lang="common.copy"><?php echo Lang::t('common.copy'); ?></span>
                                        </button>
                                        <button type="button"
                                            onclick="openDetailModal(<?php echo htmlJsArg((string)$group['product_name']); ?>, <?php echo htmlJsArg((string)$group['date']); ?>, <?php echo htmlJsArg(implode("\n", $group['keys'])); ?>, <?php echo htmlJsArg(formatCurrency($group['total_price'])); ?>, <?php echo htmlJsArg((string)$group['download_url']); ?>)"
                                            class="bg-[#1b233d] border border-white/5 text-gray-400 hover:text-white hover:bg-white/5 rounded-lg w-[42px] h-[38px] flex items-center justify-center transition">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>

                                    <!-- Hidden keys -->
                                    <?php if ($qty > 1): ?>
                                        <div class="hidden-keys mt-2 space-y-2">
                                            <?php for ($i = 1; $i < $qty; $i++): ?>
                                                <div class="flex items-center gap-2">
                                                    <div
                                                        class="flex-1 bg-[#121827] border border-white/5 rounded-lg p-2.5 text-gray-300 font-mono text-[13px] overflow-hidden text-ellipsis whitespace-nowrap leading-none select-all relative group h-[38px] flex items-center">
                                                        <span class="key-text"><?php echo htmlspecialchars(preg_replace('/^(.{15})(.*)$/', '$1...', $group['keys'][$i])); ?></span>
                                                        <span class="key-full hidden"><?php echo htmlspecialchars($group['keys'][$i]); ?></span>
                                                    </div>
                                                    <button type="button"
                                                        onclick="Lang.copy(<?php echo htmlJsArg((string)$group['keys'][$i]); ?>)"
                                                        class="bg-[#1b233d] border border-white/5 hover:bg-white/5 text-gray-100 rounded-lg px-3 h-[38px] w-auto flex text-[13px] items-center justify-center gap-1.5 transition whitespace-nowrap">
                                                        <i class="bi bi-intersect"></i> <span class="btn-text" data-lang="common.copy"><?php echo Lang::t('common.copy'); ?></span>
                                                    </button>
                                                    <button type="button"
                                                        onclick="openDetailModal(<?php echo htmlJsArg((string)$group['product_name']); ?>, <?php echo htmlJsArg((string)$group['date']); ?>, <?php echo htmlJsArg((string)$group['keys'][$i]); ?>, <?php echo htmlJsArg(formatCurrency($group['price_per_item'])); ?>, <?php echo htmlJsArg((string)$group['download_url']); ?>)"
                                                        class="bg-[#1b233d] border border-white/5 text-gray-400 hover:text-white hover:bg-white/5 rounded-lg w-[42px] h-[38px] flex items-center justify-center transition">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                            <?php endfor; ?>

                                            <div class="flex gap-2 mt-2 pt-1">
                                                <button type="button" onclick="Lang.copy(this.getAttribute('data-keys'))"
                                                    data-keys="<?php echo htmlspecialchars(implode("\n", $group['keys']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                                                    class="bg-[#3b82f6] hover:bg-blue-600 text-white rounded-lg h-[38px] px-4 text-[13px] flex-1 flex items-center justify-center gap-1.5 transition">
                                                    <i class="bi bi-intersect"></i> <span class="btn-text" data-lang="buy.copy_all"><?php echo Lang::t('buy.copy_all'); ?></span>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="text-left mt-2 pl-1 show-more-text">
                                            <span class="text-gray-500 text-[11px]"><span class="text-gray-400">+<?php echo $qty - 1; ?></span> <span class="text-gray-400" data-lang="history.more_codes"><?php echo Lang::t('history.more_codes'); ?></span></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($historyPage > 1 || $historyHasMore): ?>
                <nav class="px-4 pb-4 flex items-center justify-between gap-3" aria-label="<?php echo getAppLang() === 'en' ? 'Purchase history pages' : 'หน้าประวัติการซื้อ'; ?>">
                    <?php if ($historyPage > 1): ?>
                        <form method="post" action="history.php">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($historySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="page" value="<?php echo $historyPage - 1; ?>">
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-[#1b233d] px-3 py-2 text-xs text-gray-200 hover:bg-[#222b45]">
                                <i class="bi bi-chevron-left"></i><?php echo getAppLang() === 'en' ? 'Previous' : 'ก่อนหน้า'; ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span class="text-[11px] text-gray-500"><?php echo (getAppLang() === 'en' ? 'Page ' : 'หน้า ') . number_format($historyPage); ?></span>
                    <?php if ($historyHasMore): ?>
                        <form method="post" action="history.php">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($historySearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <input type="hidden" name="page" value="<?php echo $historyPage + 1; ?>">
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-[#1b233d] px-3 py-2 text-xs text-gray-200 hover:bg-[#222b45]">
                                <?php echo getAppLang() === 'en' ? 'Next' : 'ถัดไป'; ?><i class="bi bi-chevron-right"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // View Details Modal
        window.currentModalKeys = '';
        function openDetailModal(productName, date, keys, price, downloadUrl) {
            document.getElementById('modalProductName').textContent = productName;
            document.getElementById('modalDate').textContent = date;
            
            // Format keys for display
            window.currentModalKeys = keys;
            const keysArray = keys.split('\n');
            const modalKeys = document.getElementById('modalKeys');
            modalKeys.replaceChildren();
            keysArray.forEach((keyText) => {
                const row = document.createElement('div');
                row.className = 'mb-1';
                row.textContent = keyText;
                modalKeys.appendChild(row);
            });
            
            document.getElementById('modalPrice').textContent = price;
            
            if (downloadUrl && downloadUrl.trim() !== '') {
                document.getElementById('modalDownloadBtn').href = downloadUrl;
                document.getElementById('modalDownloadBtn').classList.remove('hidden');
                document.getElementById('modalDownloadBtn').classList.add('flex');
            } else {
                document.getElementById('modalDownloadBtn').classList.add('hidden');
                document.getElementById('modalDownloadBtn').classList.remove('flex');
            }
            
            const overlay = document.getElementById('detailModalOverlay');
            window.historyModalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            overlay.setAttribute('aria-hidden', 'false');
            const modal = document.getElementById('detailModal');
            const firstFocusable = modal.querySelector('button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])');
            (firstFocusable || modal).focus({preventScroll: true});
        }

        function closeDetailModal() {
            const overlay = document.getElementById('detailModalOverlay');
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
            overlay.setAttribute('aria-hidden', 'true');
            if (window.historyModalReturnFocus instanceof HTMLElement && window.historyModalReturnFocus.isConnected) {
                window.historyModalReturnFocus.focus({preventScroll: true});
            }
        }
        
        function copyModalKeys() {
            const btnElement = document.getElementById('modalCopyBtn');
            const originalHtml = btnElement.innerHTML;
            
            Lang.copy(window.currentModalKeys, function(success) {
                if (success) {
                    successState(btnElement, originalHtml);
                }
            });
        }
        
        function successState(btnElement, originalHtml) {
            const msg = window.Lang && Lang.current === 'en' ? 'Copied!' : 'คัดลอกแล้ว';
            btnElement.innerHTML = `<i class="bi bi-check2"></i> <span>${msg}</span>`;
            btnElement.classList.replace('bg-[#22c55e]', 'bg-[#16a34a]');
            setTimeout(() => {
                btnElement.innerHTML = originalHtml;
                btnElement.classList.replace('bg-[#16a34a]', 'bg-[#22c55e]');
            }, 2000);
        }

        // Close modal when clicking outside
        document.getElementById('detailModalOverlay').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            const overlay = document.getElementById('detailModalOverlay');
            if (!overlay || overlay.classList.contains('hidden')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDetailModal();
                return;
            }
            if (event.key !== 'Tab') return;
            const modal = document.getElementById('detailModal');
            const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter((node) => node.offsetParent !== null);
            if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });

        // Toggle multiple keys expansion
        document.querySelectorAll('.show-more-text').forEach(el => {
            el.style.cursor = 'pointer';
            el.addEventListener('click', function () {
                const container = this.closest('.key-container');
                container.classList.toggle('show-keys');
                if (container.classList.contains('show-keys')) {
                    this.style.display = 'none'; // hide the "+ x more codes" text
                }
            });
        });
    </script>
</body>

</html>