<?php
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
requireAdmin();

function rankingAdminPageParam(string $name): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    return is_int($value) && $value > 0 ? $value : 1;
}

function rankingAdminPageUrl(string $parameter, int $page): string
{
    $query = $_GET;
    $query[$parameter] = max(1, $page);
    return 'rankings.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function rankingAdminStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['completed', 'active', 'applied'], true)) {
        return 'bg-green-500/15 text-green-300 border-green-400/20';
    }
    if (in_array($status, ['processing', 'pending'], true)) {
        return 'bg-yellow-500/15 text-yellow-300 border-yellow-400/20';
    }
    if (in_array($status, ['failed', 'banned', 'missing'], true)) {
        return 'bg-red-500/15 text-red-300 border-red-400/20';
    }
    return 'bg-gray-500/15 text-gray-300 border-gray-400/20';
}

function rankingAdminStatusLabel(string $status): string
{
    $status = strtolower(trim($status));
    $known = ['completed', 'pending', 'failed', 'processing', 'active', 'banned', 'missing'];
    $key = in_array($status, $known, true) ? $status : 'unknown';
    return Lang::t('ranking.admin.raw_status.' . $key);
}

function rankingAdminPagination(string $parameter, int $currentPage, int $totalPages, int $totalRows): void
{
    $totalPages = max(1, $totalPages);
    $currentPage = max(1, min($currentPage, $totalPages));
    ?>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 px-4 py-3 text-xs text-gray-400">
        <div>
            <span data-lang="ranking.admin.page"><?php echo htmlspecialchars(Lang::t('ranking.admin.page'), ENT_QUOTES, 'UTF-8'); ?></span>
            <strong class="text-white"><?php echo $currentPage; ?></strong>
            <span data-lang="ranking.admin.of"><?php echo htmlspecialchars(Lang::t('ranking.admin.of'), ENT_QUOTES, 'UTF-8'); ?></span>
            <strong class="text-white"><?php echo $totalPages; ?></strong>
            · <?php echo number_format($totalRows); ?>
            <span data-lang="ranking.admin.records"><?php echo htmlspecialchars(Lang::t('ranking.admin.records'), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($currentPage > 1): ?>
                <a class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white hover:bg-white/10" href="<?php echo htmlspecialchars(rankingAdminPageUrl($parameter, $currentPage - 1), ENT_QUOTES, 'UTF-8'); ?>" data-lang="ranking.admin.previous"><?php echo htmlspecialchars(Lang::t('ranking.admin.previous'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
            <?php if ($currentPage < $totalPages): ?>
                <a class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white hover:bg-white/10" href="<?php echo htmlspecialchars(rankingAdminPageUrl($parameter, $currentPage + 1), ENT_QUOTES, 'UTF-8'); ?>" data-lang="ranking.admin.next"><?php echo htmlspecialchars(Lang::t('ranking.admin.next'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$rankingReady = ensureRankingSchema();
$monthlySummary = $rankingReady ? rankAdminMonthlySummary() : ['entry_count' => 0, 'total_thb' => 0.0];
$lifetimeSummary = $rankingReady ? rankAdminLifetimeSummary() : ['entry_count' => 0, 'total_thb' => 0.0];
$bonusSummary = $rankingReady ? rankCurrentMonthBonusSummary() : ['bonus_total' => 0.0, 'applied_count' => 0];
$ledgerCount = $rankingReady ? rankAdminDepositLedgerCount() : 0;
$awardCount = $rankingReady ? rankAdminBonusAwardCount() : 0;

$boardPerPage = 50;
$auditPerPage = 50;

$monthlyPages = max(1, (int) ceil(((int) $monthlySummary['entry_count']) / $boardPerPage));
$lifetimePages = max(1, (int) ceil(((int) $lifetimeSummary['entry_count']) / $boardPerPage));
$ledgerPages = max(1, (int) ceil($ledgerCount / $auditPerPage));
$awardPages = max(1, (int) ceil($awardCount / $auditPerPage));

$monthlyPage = min(rankingAdminPageParam('monthly_page'), $monthlyPages);
$lifetimePage = min(rankingAdminPageParam('lifetime_page'), $lifetimePages);
$ledgerPage = min(rankingAdminPageParam('ledger_page'), $ledgerPages);
$awardPage = min(rankingAdminPageParam('award_page'), $awardPages);

$monthlyRows = $rankingReady ? rankMonthlyRows($boardPerPage, ($monthlyPage - 1) * $boardPerPage) : [];
$lifetimeRows = $rankingReady ? rankLifetimeRows($boardPerPage, ($lifetimePage - 1) * $boardPerPage) : [];
$ledgerRows = $rankingReady ? rankAdminDepositLedger($auditPerPage, ($ledgerPage - 1) * $auditPerPage) : [];
$awards = $rankingReady ? rankRecentBonusAwards($auditPerPage, ($awardPage - 1) * $auditPerPage) : [];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="ranking.admin.title"><?php echo htmlspecialchars(Lang::t('ranking.admin.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        details>summary{list-style:none}details>summary::-webkit-details-marker{display:none}
        details[open] .section-chevron{transform:rotate(180deg)}
        .section-chevron{transition:transform .15s ease}
        .audit-card{overflow-wrap:anywhere}
    </style>
</head>
<body class="min-h-screen bg-[#0d0d10] pb-12 text-gray-100">
<?php include 'nav.php'; ?>
<main class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
    <section class="glass rounded-2xl p-5 md:p-6">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-violet-400/20 bg-violet-500/15 text-violet-300"><i class="bi bi-shield-check text-xl"></i></div>
            <div>
                <h1 class="text-xl font-black md:text-2xl" data-lang="ranking.admin.title"><?php echo htmlspecialchars(Lang::t('ranking.admin.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="mt-1 text-sm text-gray-400" data-lang="ranking.admin.readonly_desc"><?php echo htmlspecialchars(Lang::t('ranking.admin.readonly_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </section>

    <?php if (!$rankingReady): ?>
        <section class="flex items-start gap-3 rounded-2xl border border-red-400/25 bg-red-500/10 p-4 text-sm text-red-200">
            <i class="bi bi-exclamation-triangle-fill mt-0.5"></i>
            <span data-lang="ranking.error.unavailable"><?php echo htmlspecialchars(Lang::t('ranking.error.unavailable'), ENT_QUOTES, 'UTF-8'); ?></span>
        </section>
    <?php endif; ?>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.monthly_entries"><?php echo htmlspecialchars(Lang::t('ranking.admin.monthly_entries'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-2xl font-black text-blue-300"><?php echo number_format((int) $monthlySummary['entry_count']); ?></p></div>
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.monthly_total"><?php echo htmlspecialchars(Lang::t('ranking.admin.monthly_total'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 truncate text-lg font-black text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $monthlySummary['total_thb']), ENT_QUOTES, 'UTF-8'); ?></p></div>
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.lifetime_entries"><?php echo htmlspecialchars(Lang::t('ranking.admin.lifetime_entries'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-2xl font-black text-violet-300"><?php echo number_format((int) $lifetimeSummary['entry_count']); ?></p></div>
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.lifetime_total"><?php echo htmlspecialchars(Lang::t('ranking.admin.lifetime_total'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 truncate text-lg font-black text-emerald-300"><?php echo htmlspecialchars(rankFormatThb((float) $lifetimeSummary['total_thb']), ENT_QUOTES, 'UTF-8'); ?></p></div>
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.applied_month"><?php echo htmlspecialchars(Lang::t('ranking.admin.applied_month'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-2xl font-black text-green-300"><?php echo number_format((int) $bonusSummary['applied_count']); ?></p></div>
        <div class="glass rounded-xl p-4"><p class="text-[11px] text-gray-500" data-lang="ranking.admin.paid_month"><?php echo htmlspecialchars(Lang::t('ranking.admin.paid_month'), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 truncate text-lg font-black text-yellow-300"><?php echo htmlspecialchars(formatCurrency((float) $bonusSummary['bonus_total'], true), ENT_QUOTES, 'UTF-8'); ?></p></div>
    </section>

    <details class="glass overflow-hidden rounded-2xl" open>
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 md:p-5">
            <div><h2 class="font-black" data-lang="ranking.admin.monthly_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.monthly_board'), ENT_QUOTES, 'UTF-8'); ?></h2><p class="mt-1 text-xs text-gray-500" data-lang="ranking.reset_note"><?php echo htmlspecialchars(Lang::t('ranking.reset_note'), ENT_QUOTES, 'UTF-8'); ?></p></div>
            <i class="bi bi-chevron-down section-chevron text-gray-400"></i>
        </summary>
        <div class="border-t border-white/10">
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[920px] text-sm">
                    <thead class="bg-white/[.03] text-gray-400"><tr><th class="p-3 text-left">#</th><th class="p-3 text-left" data-lang="ranking.admin.user_id"><?php echo htmlspecialchars(Lang::t('ranking.admin.user_id'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.username"><?php echo htmlspecialchars(Lang::t('ranking.admin.username'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.rank"><?php echo htmlspecialchars(Lang::t('ranking.admin.rank'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.monthly_total"><?php echo htmlspecialchars(Lang::t('ranking.admin.monthly_total'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.last_deposit"><?php echo htmlspecialchars(Lang::t('ranking.admin.last_deposit'), ENT_QUOTES, 'UTF-8'); ?></th></tr></thead>
                    <tbody>
                    <?php if (empty($monthlyRows)): ?><tr><td colspan="6" class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_board'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endif; ?>
                    <?php foreach ($monthlyRows as $row): ?><tr class="border-t border-white/5"><td class="p-3 font-black text-blue-300">#<?php echo (int) $row['position']; ?></td><td class="p-3 text-gray-400"><?php echo (int) $row['user_id']; ?></td><td class="p-3 font-semibold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3"><?php echo htmlspecialchars(rankLabel((string) $row['rank_code']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-right font-black text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['total_thb']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-gray-400"><?php echo htmlspecialchars((string) $row['last_deposit_at'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 p-3 md:hidden">
                <?php if (empty($monthlyRows)): ?><div class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_board'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <?php foreach ($monthlyRows as $row): ?><article class="audit-card rounded-xl border border-white/5 bg-white/[.025] p-3"><div class="flex items-start justify-between gap-3"><div><div class="font-black text-blue-300">#<?php echo (int) $row['position']; ?> · <?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></div><div class="mt-1 text-[11px] text-gray-500">ID <?php echo (int) $row['user_id']; ?> · <?php echo htmlspecialchars(rankLabel((string) $row['rank_code']), ENT_QUOTES, 'UTF-8'); ?></div></div><div class="shrink-0 text-right font-black text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['total_thb']), ENT_QUOTES, 'UTF-8'); ?></div></div><div class="mt-2 text-[11px] text-gray-500"><?php echo htmlspecialchars((string) $row['last_deposit_at'], ENT_QUOTES, 'UTF-8'); ?></div></article><?php endforeach; ?>
            </div>
            <?php rankingAdminPagination('monthly_page', $monthlyPage, $monthlyPages, (int) $monthlySummary['entry_count']); ?>
        </div>
    </details>

    <details class="glass overflow-hidden rounded-2xl" open>
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 md:p-5"><div><h2 class="font-black" data-lang="ranking.admin.lifetime_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.lifetime_board'), ENT_QUOTES, 'UTF-8'); ?></h2><p class="mt-1 text-xs text-gray-500" data-lang="ranking.lifetime_desc"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_desc'), ENT_QUOTES, 'UTF-8'); ?></p></div><i class="bi bi-chevron-down section-chevron text-gray-400"></i></summary>
        <div class="border-t border-white/10">
            <div class="hidden overflow-x-auto md:block"><table class="w-full min-w-[980px] text-sm"><thead class="bg-white/[.03] text-gray-400"><tr><th class="p-3 text-left">#</th><th class="p-3 text-left" data-lang="ranking.admin.user_id"><?php echo htmlspecialchars(Lang::t('ranking.admin.user_id'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.username"><?php echo htmlspecialchars(Lang::t('ranking.admin.username'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.role"><?php echo htmlspecialchars(Lang::t('ranking.admin.role'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.lifetime_total"><?php echo htmlspecialchars(Lang::t('ranking.admin.lifetime_total'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.last_deposit"><?php echo htmlspecialchars(Lang::t('ranking.admin.last_deposit'), ENT_QUOTES, 'UTF-8'); ?></th></tr></thead><tbody>
            <?php if (empty($lifetimeRows)): ?><tr><td colspan="6" class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_board'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endif; ?>
            <?php foreach ($lifetimeRows as $row): ?><tr class="border-t border-white/5"><td class="p-3 font-black text-violet-300">#<?php echo (int) $row['position']; ?></td><td class="p-3 text-gray-400"><?php echo (int) $row['user_id']; ?></td><td class="p-3 font-semibold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3"><?php echo htmlspecialchars(rankRoleLabel((string) $row['role']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-right font-black text-emerald-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['total_thb']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-gray-400"><?php echo htmlspecialchars((string) $row['last_deposit_at'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="space-y-2 p-3 md:hidden"><?php if (empty($lifetimeRows)): ?><div class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_board"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_board'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php foreach ($lifetimeRows as $row): ?><article class="audit-card rounded-xl border border-white/5 bg-white/[.025] p-3"><div class="flex items-start justify-between gap-3"><div><div class="font-black text-violet-300">#<?php echo (int) $row['position']; ?> · <?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></div><div class="mt-1 text-[11px] text-gray-500">ID <?php echo (int) $row['user_id']; ?> · <?php echo htmlspecialchars(rankRoleLabel((string) $row['role']), ENT_QUOTES, 'UTF-8'); ?></div></div><div class="shrink-0 text-right font-black text-emerald-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['total_thb']), ENT_QUOTES, 'UTF-8'); ?></div></div><div class="mt-2 text-[11px] text-gray-500"><?php echo htmlspecialchars((string) $row['last_deposit_at'], ENT_QUOTES, 'UTF-8'); ?></div></article><?php endforeach; ?></div>
            <?php rankingAdminPagination('lifetime_page', $lifetimePage, $lifetimePages, (int) $lifetimeSummary['entry_count']); ?>
        </div>
    </details>

    <details class="glass overflow-hidden rounded-2xl">
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 md:p-5"><div><h2 class="font-black" data-lang="ranking.admin.ledger"><?php echo htmlspecialchars(Lang::t('ranking.admin.ledger'), ENT_QUOTES, 'UTF-8'); ?></h2><p class="mt-1 text-xs text-gray-500" data-lang="ranking.admin.ledger_desc"><?php echo htmlspecialchars(Lang::t('ranking.admin.ledger_desc'), ENT_QUOTES, 'UTF-8'); ?></p></div><i class="bi bi-chevron-down section-chevron text-gray-400"></i></summary>
        <div class="border-t border-white/10">
            <div class="hidden overflow-x-auto lg:block"><table class="w-full min-w-[1500px] text-xs"><thead class="bg-white/[.03] text-gray-400"><tr><th class="p-3 text-left" data-lang="ranking.admin.ledger_id"><?php echo htmlspecialchars(Lang::t('ranking.admin.ledger_id'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.deposit_tx"><?php echo htmlspecialchars(Lang::t('ranking.admin.deposit_tx'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.user"><?php echo htmlspecialchars(Lang::t('ranking.admin.user'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.role"><?php echo htmlspecialchars(Lang::t('ranking.admin.role'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.credited_amount"><?php echo htmlspecialchars(Lang::t('ranking.admin.credited_amount'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.qualifying_thb"><?php echo htmlspecialchars(Lang::t('ranking.admin.qualifying_thb'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.source"><?php echo htmlspecialchars(Lang::t('ranking.admin.source'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.method"><?php echo htmlspecialchars(Lang::t('ranking.admin.method'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.tx_status"><?php echo htmlspecialchars(Lang::t('ranking.admin.tx_status'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.account_status"><?php echo htmlspecialchars(Lang::t('ranking.admin.account_status'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.date"><?php echo htmlspecialchars(Lang::t('ranking.admin.date'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.description"><?php echo htmlspecialchars(Lang::t('ranking.admin.description'), ENT_QUOTES, 'UTF-8'); ?></th></tr></thead><tbody>
            <?php if (empty($ledgerRows)): ?><tr><td colspan="12" class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_ledger"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_ledger'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endif; ?>
            <?php foreach ($ledgerRows as $row): $transactionStatus = (string) ($row['transaction_status'] ?? 'missing'); $accountStatus = (string) ($row['user_status'] ?? 'missing'); ?><tr class="border-t border-white/5 align-top"><td class="p-3 text-gray-500">#<?php echo (int) $row['id']; ?></td><td class="p-3 font-semibold">#<?php echo (int) $row['deposit_transaction_id']; ?></td><td class="p-3"><div class="font-semibold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></div><div class="text-[10px] text-gray-500">ID <?php echo (int) $row['user_id']; ?></div></td><td class="p-3"><div><?php echo htmlspecialchars(rankRoleLabel((string) $row['role_at_deposit']), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-[10px] text-gray-500"><span data-lang="ranking.admin.current_role"><?php echo htmlspecialchars(Lang::t('ranking.admin.current_role'), ENT_QUOTES, 'UTF-8'); ?></span>: <?php echo htmlspecialchars(rankRoleLabel((string) $row['account_role']), ENT_QUOTES, 'UTF-8'); ?></div></td><td class="p-3 text-right"><?php echo htmlspecialchars(formatCurrency((float) $row['credited_amount'], true), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-right font-black text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['qualifying_amount_thb']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3"><code><?php echo htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8'); ?></code></td><td class="p-3"><code><?php echo htmlspecialchars((string) $row['qualification_method'], ENT_QUOTES, 'UTF-8'); ?></code></td><td class="p-3"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($transactionStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($transactionStatus), ENT_QUOTES, 'UTF-8'); ?></span></td><td class="p-3"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($accountStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($accountStatus), ENT_QUOTES, 'UTF-8'); ?></span></td><td class="p-3 text-gray-400"><?php echo htmlspecialchars((string) $row['deposited_at'], ENT_QUOTES, 'UTF-8'); ?></td><td class="max-w-sm p-3 text-gray-400"><?php echo htmlspecialchars((string) ($row['transaction_description'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="space-y-3 p-3 lg:hidden"><?php if (empty($ledgerRows)): ?><div class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_ledger"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_ledger'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php foreach ($ledgerRows as $row): $transactionStatus = (string) ($row['transaction_status'] ?? 'missing'); $accountStatus = (string) ($row['user_status'] ?? 'missing'); ?><article class="audit-card rounded-xl border border-white/5 bg-white/[.025] p-3 text-xs"><div class="flex items-start justify-between gap-3"><div><div class="font-bold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?> <span class="text-gray-500">#<?php echo (int) $row['user_id']; ?></span></div><div class="mt-1 text-gray-500"><?php echo htmlspecialchars(Lang::t('ranking.admin.ledger_id'), ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int) $row['id']; ?> · <?php echo htmlspecialchars(Lang::t('ranking.admin.deposit_tx'), ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int) $row['deposit_transaction_id']; ?></div></div><div class="text-right"><div class="font-black text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['qualifying_amount_thb']), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-[10px] text-gray-500"><?php echo htmlspecialchars(formatCurrency((float) $row['credited_amount'], true), ENT_QUOTES, 'UTF-8'); ?></div></div></div><div class="mt-3 grid grid-cols-2 gap-2 text-gray-400"><div><?php echo htmlspecialchars(Lang::t('ranking.admin.role'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo htmlspecialchars(rankRoleLabel((string) $row['role_at_deposit']), ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.current_role'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo htmlspecialchars(rankRoleLabel((string) $row['account_role']), ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.source'), ENT_QUOTES, 'UTF-8'); ?>: <code class="text-white"><?php echo htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8'); ?></code></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.method'), ENT_QUOTES, 'UTF-8'); ?>: <code class="text-white"><?php echo htmlspecialchars((string) $row['qualification_method'], ENT_QUOTES, 'UTF-8'); ?></code></div></div><div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($transactionStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($transactionStatus), ENT_QUOTES, 'UTF-8'); ?></span><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($accountStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($accountStatus), ENT_QUOTES, 'UTF-8'); ?></span></div><div class="mt-3 text-gray-500"><?php echo htmlspecialchars((string) $row['deposited_at'], ENT_QUOTES, 'UTF-8'); ?></div><div class="mt-2 rounded-lg bg-black/20 p-2 text-gray-400"><?php echo htmlspecialchars((string) ($row['transaction_description'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div></article><?php endforeach; ?></div>
            <?php rankingAdminPagination('ledger_page', $ledgerPage, $ledgerPages, $ledgerCount); ?>
        </div>
    </details>

    <details class="glass overflow-hidden rounded-2xl">
        <summary class="flex cursor-pointer items-center justify-between gap-3 p-4 md:p-5"><div><h2 class="font-black" data-lang="ranking.admin.awards"><?php echo htmlspecialchars(Lang::t('ranking.admin.awards'), ENT_QUOTES, 'UTF-8'); ?></h2><p class="mt-1 text-xs text-gray-500" data-lang="ranking.admin.bonus_desc"><?php echo htmlspecialchars(Lang::t('ranking.admin.bonus_desc'), ENT_QUOTES, 'UTF-8'); ?></p></div><i class="bi bi-chevron-down section-chevron text-gray-400"></i></summary>
        <div class="border-t border-white/10">
            <div class="hidden overflow-x-auto lg:block"><table class="w-full min-w-[1600px] text-xs"><thead class="bg-white/[.03] text-gray-400"><tr><th class="p-3 text-left" data-lang="ranking.admin.award_id"><?php echo htmlspecialchars(Lang::t('ranking.admin.award_id'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.user"><?php echo htmlspecialchars(Lang::t('ranking.admin.user'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.deposit_tx"><?php echo htmlspecialchars(Lang::t('ranking.admin.deposit_tx'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.bonus_tx"><?php echo htmlspecialchars(Lang::t('ranking.admin.bonus_tx'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.period"><?php echo htmlspecialchars(Lang::t('ranking.admin.period'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.rank_used"><?php echo htmlspecialchars(Lang::t('ranking.admin.rank_used'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.base"><?php echo htmlspecialchars(Lang::t('ranking.admin.base'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.bonus"><?php echo htmlspecialchars(Lang::t('ranking.admin.bonus'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-right" data-lang="ranking.admin.qualifying_total"><?php echo htmlspecialchars(Lang::t('ranking.admin.qualifying_total'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.source"><?php echo htmlspecialchars(Lang::t('ranking.admin.source'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.tx_status"><?php echo htmlspecialchars(Lang::t('ranking.admin.tx_status'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.status"><?php echo htmlspecialchars(Lang::t('ranking.admin.status'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.date"><?php echo htmlspecialchars(Lang::t('ranking.admin.date'), ENT_QUOTES, 'UTF-8'); ?></th><th class="p-3 text-left" data-lang="ranking.admin.description"><?php echo htmlspecialchars(Lang::t('ranking.admin.description'), ENT_QUOTES, 'UTF-8'); ?></th></tr></thead><tbody>
            <?php if (empty($awards)): ?><tr><td colspan="14" class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_awards"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_awards'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endif; ?>
            <?php foreach ($awards as $row): $decisionStatus = in_array((string) $row['status'], ['applied', 'not_eligible', 'processing', 'disabled'], true) ? (string) $row['status'] : 'unknown'; $depositStatus = (string) ($row['deposit_status'] ?? 'missing'); ?><tr class="border-t border-white/5 align-top"><td class="p-3 text-gray-500">#<?php echo (int) $row['id']; ?></td><td class="p-3"><div class="font-semibold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?></div><div class="text-[10px] text-gray-500">ID <?php echo (int) $row['user_id']; ?> · <?php echo htmlspecialchars(rankRoleLabel((string) $row['role']), ENT_QUOTES, 'UTF-8'); ?></div></td><td class="p-3">#<?php echo (int) $row['deposit_transaction_id']; ?></td><td class="p-3"><?php echo !empty($row['bonus_transaction_id']) ? '#' . (int) $row['bonus_transaction_id'] : '—'; ?></td><td class="p-3"><?php echo htmlspecialchars((string) $row['period_start'], ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3"><?php echo htmlspecialchars(rankLabel((string) $row['rank_code']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(number_format((float) $row['bonus_percent'], 2), ENT_QUOTES, 'UTF-8'); ?>%</td><td class="p-3 text-right"><?php echo htmlspecialchars(formatCurrency((float) $row['base_amount'], true), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-right font-black text-green-300"><?php echo htmlspecialchars(formatCurrency((float) $row['bonus_amount'], true), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3 text-right font-semibold text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['qualifying_total_thb']), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-3"><code><?php echo htmlspecialchars((string) ($row['source'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code><div class="mt-1 text-[10px] text-gray-500"><?php echo htmlspecialchars((string) ($row['qualification_method'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div></td><td class="p-3"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($depositStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($depositStatus), ENT_QUOTES, 'UTF-8'); ?></span></td><td class="p-3"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($decisionStatus); ?>"><?php echo htmlspecialchars(Lang::t('ranking.admin.status.' . $decisionStatus), ENT_QUOTES, 'UTF-8'); ?></span></td><td class="p-3 text-gray-400"><div><?php echo htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'); ?></div><div class="mt-1 text-[10px]"><span data-lang="ranking.admin.updated"><?php echo htmlspecialchars(Lang::t('ranking.admin.updated'), ENT_QUOTES, 'UTF-8'); ?></span>: <?php echo htmlspecialchars((string) $row['updated_at'], ENT_QUOTES, 'UTF-8'); ?></div></td><td class="max-w-sm p-3 text-gray-400"><?php echo htmlspecialchars((string) ($row['deposit_description'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="space-y-3 p-3 lg:hidden"><?php if (empty($awards)): ?><div class="p-8 text-center text-gray-500" data-lang="ranking.admin.no_awards"><?php echo htmlspecialchars(Lang::t('ranking.admin.no_awards'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php foreach ($awards as $row): $decisionStatus = in_array((string) $row['status'], ['applied', 'not_eligible', 'processing', 'disabled'], true) ? (string) $row['status'] : 'unknown'; $depositStatus = (string) ($row['deposit_status'] ?? 'missing'); ?><article class="audit-card rounded-xl border border-white/5 bg-white/[.025] p-3 text-xs"><div class="flex items-start justify-between gap-3"><div><div class="font-bold text-white"><?php echo htmlspecialchars((string) $row['username'], ENT_QUOTES, 'UTF-8'); ?> <span class="text-gray-500">#<?php echo (int) $row['user_id']; ?></span></div><div class="mt-1 text-gray-500"><?php echo htmlspecialchars(Lang::t('ranking.admin.award_id'), ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int) $row['id']; ?> · <?php echo htmlspecialchars(Lang::t('ranking.admin.deposit_tx'), ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int) $row['deposit_transaction_id']; ?></div></div><div class="text-right"><div class="font-black text-green-300">+<?php echo htmlspecialchars(formatCurrency((float) $row['bonus_amount'], true), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-[10px] text-gray-500"><?php echo htmlspecialchars(number_format((float) $row['bonus_percent'], 2), ENT_QUOTES, 'UTF-8'); ?>%</div></div></div><div class="mt-3 grid grid-cols-2 gap-2 text-gray-400"><div><?php echo htmlspecialchars(Lang::t('ranking.admin.rank_used'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo htmlspecialchars(rankLabel((string) $row['rank_code']), ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.period'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo htmlspecialchars((string) $row['period_start'], ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.base'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo htmlspecialchars(formatCurrency((float) $row['base_amount'], true), ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.qualifying_total'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-cyan-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['qualifying_total_thb']), ENT_QUOTES, 'UTF-8'); ?></span></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.source'), ENT_QUOTES, 'UTF-8'); ?>: <code class="text-white"><?php echo htmlspecialchars((string) ($row['source'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code></div><div><?php echo htmlspecialchars(Lang::t('ranking.admin.bonus_tx'), ENT_QUOTES, 'UTF-8'); ?>: <span class="text-white"><?php echo !empty($row['bonus_transaction_id']) ? '#' . (int) $row['bonus_transaction_id'] : '—'; ?></span></div></div><div class="mt-3 flex flex-wrap gap-2"><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($depositStatus); ?>"><?php echo htmlspecialchars(rankingAdminStatusLabel($depositStatus), ENT_QUOTES, 'UTF-8'); ?></span><span class="rounded-full border px-2 py-1 <?php echo rankingAdminStatusClass($decisionStatus); ?>"><?php echo htmlspecialchars(Lang::t('ranking.admin.status.' . $decisionStatus), ENT_QUOTES, 'UTF-8'); ?></span></div><div class="mt-3 text-gray-500"><?php echo htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8'); ?></div><div class="mt-2 rounded-lg bg-black/20 p-2 text-gray-400"><?php echo htmlspecialchars((string) ($row['deposit_description'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div></article><?php endforeach; ?></div>
            <?php rankingAdminPagination('award_page', $awardPage, $awardPages, $awardCount); ?>
        </div>
    </details>
</main>
</body>
</html>
