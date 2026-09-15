<?php
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
requireLogin();
if (!isUser()) {
    header('Location: ../index.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$snapshot = rankUserSnapshot($userId);
$rankingReady = !empty($snapshot['available']);
$monthlyRows = $rankingReady ? rankMonthlyRows(RANK_MONTHLY_BOARD_LIMIT) : [];
$lifetimeRows = $rankingReady ? rankLifetimeRows(RANK_LIFETIME_BOARD_LIMIT) : [];

function userRankBadgeClass(string $rankCode): string
{
    if ($rankCode === 'platinum') return 'border-cyan-200/40 bg-cyan-300/15 text-cyan-100';
    if ($rankCode === 'gold') return 'border-yellow-300/40 bg-yellow-400/15 text-yellow-200';
    if ($rankCode === 'silver') return 'border-slate-200/30 bg-slate-300/15 text-slate-100';
    if ($rankCode === 'bronze') return 'border-orange-300/30 bg-orange-500/15 text-orange-200';
    return 'border-white/10 bg-white/5 text-gray-400';
}

$monthlyTotalThb = max(0.0, (float) ($snapshot['monthly_total_thb'] ?? 0.0));
$monthlyProgressPercent = min(100.0, max(0.0, (float) ($snapshot['progress_percent'] ?? 0.0)));
$monthlyRemainingPercent = min(100.0, max(0.0, (float) ($snapshot['remaining_percent'] ?? 100.0)));
$currentBonusPercent = max(0.0, (float) ($snapshot['monthly_bonus_percent'] ?? 0.0));
$nextRankCode = is_string($snapshot['next_rank_code'] ?? null) ? (string) $snapshot['next_rank_code'] : null;
$nextRankThresholdThb = isset($snapshot['next_rank_threshold_thb']) && is_numeric($snapshot['next_rank_threshold_thb'])
    ? max(0.0, (float) $snapshot['next_rank_threshold_thb'])
    : null;
$nextRankBonusPercent = max(0.0, (float) ($snapshot['next_rank_bonus_percent'] ?? 0.0));
$remainingToNextThb = max(0.0, (float) ($snapshot['remaining_to_next_thb'] ?? 0.0));
$isMaxRank = !empty($snapshot['is_max_rank']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="ranking.title"><?php echo htmlspecialchars(Lang::t('ranking.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .podium-1{box-shadow:0 0 28px rgba(250,204,21,.12)}
        .leader-row{transition:transform .15s ease,border-color .15s ease,background .15s ease}
        .leader-row:active{transform:scale(.995)}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen pb-10">
<?php include 'nav.php'; ?>
<main class="max-w-3xl mx-auto p-4 md:p-6 space-y-5">
    <section class="glass rounded-2xl p-5 md:p-7 relative overflow-hidden">
        <div class="absolute -top-24 -right-20 w-64 h-64 rounded-full bg-blue-500/20 blur-3xl pointer-events-none"></div>
        <div class="relative flex items-start gap-4">
            <div class="w-12 h-12 rounded-2xl bg-blue-500/15 border border-blue-400/20 text-blue-300 flex items-center justify-center shrink-0">
                <i class="bi bi-trophy-fill text-xl"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-xl md:text-2xl font-extrabold text-white" data-lang="ranking.title"><?php echo htmlspecialchars(Lang::t('ranking.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="text-sm text-gray-400 mt-1" data-lang="ranking.subtitle"><?php echo htmlspecialchars(Lang::t('ranking.subtitle'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </section>

    <?php if (!$rankingReady): ?>
        <section class="rounded-2xl border border-red-400/25 bg-red-500/10 p-4 text-sm text-red-200 flex items-start gap-3">
            <i class="bi bi-exclamation-triangle-fill mt-0.5"></i>
            <span data-lang="ranking.error.unavailable"><?php echo htmlspecialchars(Lang::t('ranking.error.unavailable'), ENT_QUOTES, 'UTF-8'); ?></span>
        </section>
    <?php endif; ?>

    <section class="grid grid-cols-2 gap-3">
        <div class="glass rounded-2xl p-4 min-w-0">
            <p class="text-[11px] uppercase tracking-wider text-gray-500" data-lang="ranking.my_rank"><?php echo htmlspecialchars(Lang::t('ranking.my_rank'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="mt-3 inline-flex max-w-full items-center gap-2 px-3 py-1.5 rounded-full border font-bold <?php echo userRankBadgeClass((string) $snapshot['monthly_rank_code']); ?>">
                <i class="bi bi-shield-fill-check"></i>
                <span><?php echo htmlspecialchars(rankLabel((string) $snapshot['monthly_rank_code']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
        <div class="glass rounded-2xl p-4 min-w-0">
            <p class="text-[11px] uppercase tracking-wider text-gray-500" data-lang="ranking.my_position"><?php echo htmlspecialchars(Lang::t('ranking.my_position'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($snapshot['monthly_position'] !== null): ?>
                <p class="text-3xl font-black text-blue-300 mt-2">#<?php echo (int) $snapshot['monthly_position']; ?></p>
            <?php else: ?>
                <p class="text-sm font-semibold text-gray-400 mt-3" data-lang="ranking.not_ranked"><?php echo htmlspecialchars(Lang::t('ranking.not_ranked'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($rankingReady): ?>
    <section class="glass rounded-2xl p-4 md:p-5 overflow-hidden relative">
        <div class="absolute -right-16 -top-20 h-48 w-48 rounded-full bg-cyan-500/10 blur-3xl pointer-events-none"></div>
        <div class="relative">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-extrabold text-white" data-lang="ranking.month_progress_title"><?php echo htmlspecialchars(Lang::t('ranking.month_progress_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-500" data-lang="ranking.month_progress_desc"><?php echo htmlspecialchars(Lang::t('ranking.month_progress_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="shrink-0 rounded-xl border border-cyan-400/15 bg-cyan-500/10 px-3 py-2 text-right">
                    <div class="text-[9px] uppercase tracking-wider text-cyan-300/70" data-lang="ranking.rank_bonus"><?php echo htmlspecialchars(Lang::t('ranking.rank_bonus'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="mt-0.5 text-lg font-black text-cyan-200"><?php echo htmlspecialchars(number_format($currentBonusPercent, 2), ENT_QUOTES, 'UTF-8'); ?>%</div>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div class="text-[10px] uppercase tracking-wider text-gray-500" data-lang="ranking.month_deposit_amount"><?php echo htmlspecialchars(Lang::t('ranking.month_deposit_amount'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="mt-1 text-2xl font-black text-emerald-300 md:text-3xl"><?php echo htmlspecialchars(rankFormatThb($monthlyTotalThb), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-bold <?php echo userRankBadgeClass((string) $snapshot['monthly_rank_code']); ?>">
                    <i class="bi bi-shield-fill-check"></i>
                    <span><?php echo htmlspecialchars(rankLabel((string) $snapshot['monthly_rank_code']), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <div class="mt-4">
                <div class="mb-2 flex items-center justify-between gap-3 text-[11px]">
                    <span class="text-gray-400" data-lang="ranking.progress"><?php echo htmlspecialchars(Lang::t('ranking.progress'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="font-bold text-cyan-200"><?php echo htmlspecialchars(number_format($monthlyProgressPercent, 2), ENT_QUOTES, 'UTF-8'); ?>%</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-black/30 ring-1 ring-white/5" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo htmlspecialchars(number_format($monthlyProgressPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="h-full rounded-full bg-gradient-to-r from-blue-500 via-violet-500 to-cyan-400 transition-[width] duration-300" style="width: <?php echo htmlspecialchars(number_format($monthlyProgressPercent, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>%"></div>
                </div>
            </div>

            <?php if (!$isMaxRank && $nextRankCode !== null && $nextRankThresholdThb !== null): ?>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div class="min-w-0 rounded-xl border border-white/5 bg-white/[.035] px-3 py-3">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500" data-lang="ranking.next_rank_target"><?php echo htmlspecialchars(Lang::t('ranking.next_rank_target'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-1 truncate font-black text-white"><?php echo htmlspecialchars(rankLabel($nextRankCode), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-0.5 text-xs font-semibold text-cyan-300"><?php echo htmlspecialchars(rankFormatThb($nextRankThresholdThb), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-1 text-[10px] text-gray-500"><span data-lang="ranking.rank_bonus"><?php echo htmlspecialchars(Lang::t('ranking.rank_bonus'), ENT_QUOTES, 'UTF-8'); ?></span> <span class="font-bold text-cyan-200"><?php echo htmlspecialchars(number_format($nextRankBonusPercent, 2), ENT_QUOTES, 'UTF-8'); ?>%</span></div>
                    </div>
                    <div class="min-w-0 rounded-xl border border-white/5 bg-white/[.035] px-3 py-3 text-right">
                        <div class="text-[10px] uppercase tracking-wider text-gray-500" data-lang="ranking.remaining_to_next"><?php echo htmlspecialchars(Lang::t('ranking.remaining_to_next'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-1 truncate font-black text-orange-200"><?php echo htmlspecialchars(rankFormatThb($remainingToNextThb), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mt-0.5 text-xs text-gray-400"><span data-lang="ranking.remaining_percent"><?php echo htmlspecialchars(Lang::t('ranking.remaining_percent'), ENT_QUOTES, 'UTF-8'); ?></span> <span class="font-semibold text-orange-200"><?php echo htmlspecialchars(number_format($monthlyRemainingPercent, 2), ENT_QUOTES, 'UTF-8'); ?>%</span></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-4 rounded-xl border border-cyan-300/20 bg-cyan-400/10 px-4 py-3">
                    <div class="flex items-center gap-2 font-bold text-cyan-100"><i class="bi bi-stars"></i><span data-lang="ranking.max_rank"><?php echo htmlspecialchars(Lang::t('ranking.max_rank'), ENT_QUOTES, 'UTF-8'); ?></span>: <span><?php echo htmlspecialchars(rankLabel((string) $snapshot['monthly_rank_code']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <p class="mt-1 text-xs text-cyan-100/70" data-lang="ranking.max_rank_reached"><?php echo htmlspecialchars(Lang::t('ranking.max_rank_reached'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            <?php endif; ?>

            <div class="mt-3 flex items-start gap-2 text-[11px] leading-relaxed text-gray-500">
                <i class="bi bi-info-circle mt-0.5 shrink-0"></i>
                <span data-lang="ranking.bonus_next_deposit_note"><?php echo htmlspecialchars(Lang::t('ranking.bonus_next_deposit_note'), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="glass rounded-2xl overflow-hidden">
        <div class="p-4 md:p-5 border-b border-white/10">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="font-extrabold text-white" data-lang="ranking.monthly_title"><?php echo htmlspecialchars(Lang::t('ranking.monthly_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="text-xs text-gray-500 mt-1" data-lang="ranking.monthly_desc"><?php echo htmlspecialchars(Lang::t('ranking.monthly_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] bg-blue-500/10 text-blue-300 border border-blue-400/20">TOP 10</span>
            </div>
        </div>
        <div class="p-3 space-y-2">
            <?php if (empty($monthlyRows)): ?>
                <div class="py-10 text-center text-gray-500">
                    <i class="bi bi-hourglass-split text-2xl block mb-2"></i>
                    <span data-lang="ranking.empty"><?php echo htmlspecialchars(Lang::t('ranking.empty'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php else: ?>
                <?php foreach ($monthlyRows as $row): $isMe = (int) $row['user_id'] === $userId; ?>
                    <div class="leader-row rounded-xl border p-3 flex items-center gap-3 <?php echo $isMe ? 'border-blue-400/40 bg-blue-500/10' : 'border-white/5 bg-white/[.025]'; ?> <?php echo (int) $row['position'] === 1 ? 'podium-1' : ''; ?>">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center font-black shrink-0 <?php echo (int) $row['position'] <= 3 ? 'bg-yellow-400/15 text-yellow-200' : 'bg-white/5 text-gray-400'; ?>">#<?php echo (int) $row['position']; ?></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="font-bold text-white truncate"><?php echo htmlspecialchars((string) $row['masked_username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($isMe): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-200" data-lang="ranking.current_user"><?php echo htmlspecialchars(Lang::t('ranking.current_user'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <span class="shrink-0 px-2.5 py-1 rounded-full border text-xs font-bold <?php echo userRankBadgeClass((string) $row['rank_code']); ?>"><?php echo htmlspecialchars(rankLabel((string) $row['rank_code']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="px-4 pb-4 text-[11px] text-gray-500 flex items-start gap-2">
            <i class="bi bi-calendar3 mt-0.5"></i><span data-lang="ranking.reset_note"><?php echo htmlspecialchars(Lang::t('ranking.reset_note'), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </section>

    <section class="glass rounded-2xl overflow-hidden">
        <div class="p-4 md:p-5 border-b border-white/10">
            <h2 class="font-extrabold text-white" data-lang="ranking.lifetime_title"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="text-xs text-gray-500 mt-1" data-lang="ranking.lifetime_desc"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <div class="rounded-xl bg-white/[.035] border border-white/5 px-3 py-2 min-w-0">
                    <div class="text-[10px] uppercase tracking-wider text-gray-500" data-lang="ranking.my_position"><?php echo htmlspecialchars(Lang::t('ranking.my_position'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="font-black text-violet-300 mt-1"><?php echo $snapshot['lifetime_position'] !== null ? '#' . (int) $snapshot['lifetime_position'] : htmlspecialchars(Lang::t('ranking.not_ranked'), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="rounded-xl bg-white/[.035] border border-white/5 px-3 py-2 min-w-0 text-right">
                    <div class="text-[10px] uppercase tracking-wider text-gray-500" data-lang="ranking.lifetime_amount"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_amount'), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="font-black text-emerald-300 mt-1 truncate"><?php echo htmlspecialchars(rankFormatThb((float) $snapshot['lifetime_total_thb']), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </div>
        <div class="p-3 space-y-2">
            <?php if (empty($lifetimeRows)): ?>
                <div class="py-10 text-center text-gray-500" data-lang="ranking.empty"><?php echo htmlspecialchars(Lang::t('ranking.empty'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <?php foreach ($lifetimeRows as $row): $isMe = (int) $row['user_id'] === $userId; ?>
                    <div class="leader-row rounded-xl border p-3 <?php echo $isMe ? 'border-violet-400/40 bg-violet-500/10' : 'border-white/5 bg-white/[.025]'; ?>">
                        <div class="flex items-center gap-3">
                            <div class="w-9 text-center font-black <?php echo (int) $row['position'] <= 3 ? 'text-yellow-200' : 'text-gray-500'; ?>">#<?php echo (int) $row['position']; ?></div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-bold text-white truncate"><?php echo htmlspecialchars((string) $row['masked_username'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full shrink-0 <?php echo $row['role'] === 'reseller' ? 'bg-green-500/15 text-green-300' : 'bg-blue-500/15 text-blue-300'; ?>"><?php echo htmlspecialchars(rankRoleLabel((string) $row['role']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($isMe): ?><span class="text-[10px] text-violet-200 shrink-0" data-lang="ranking.current_user"><?php echo htmlspecialchars(Lang::t('ranking.current_user'), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-black text-emerald-300"><?php echo htmlspecialchars(rankFormatThb((float) $row['total_thb']), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-[9px] text-gray-500" data-lang="ranking.amount"><?php echo htmlspecialchars(Lang::t('ranking.amount'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="px-4 pb-4 text-[11px] text-gray-500 flex items-start gap-2"><i class="bi bi-shield-lock mt-0.5"></i><span data-lang="ranking.public_amount_note"><?php echo htmlspecialchars(Lang::t('ranking.public_amount_note'), ENT_QUOTES, 'UTF-8'); ?></span></div>
    </section>
</main>
</body>
</html>
