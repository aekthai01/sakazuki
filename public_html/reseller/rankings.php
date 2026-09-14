<?php
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
requireReseller();
$userId = (int) $_SESSION['user_id'];
$snapshot = rankUserSnapshot($userId);
$rankingReady = !empty($snapshot['available']);
$lifetimeRows = $rankingReady ? rankLifetimeRows(RANK_LIFETIME_BOARD_LIMIT) : [];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="ranking.lifetime_title"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}</style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen pb-10">
<?php include 'nav.php'; ?>
<main class="max-w-3xl mx-auto p-4 md:p-6 space-y-5">
    <section class="glass rounded-2xl p-5 md:p-7 relative overflow-hidden">
        <div class="absolute -top-24 -right-20 w-64 h-64 rounded-full bg-green-500/20 blur-3xl pointer-events-none"></div>
        <div class="relative flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-green-500/15 border border-green-400/20 text-green-300 flex items-center justify-center"><i class="bi bi-trophy-fill text-xl"></i></div>
            <div><h1 class="text-xl md:text-2xl font-extrabold text-white" data-lang="ranking.lifetime_title"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_title'), ENT_QUOTES, 'UTF-8'); ?></h1><p class="text-sm text-gray-400 mt-1" data-lang="ranking.lifetime_desc"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_desc'), ENT_QUOTES, 'UTF-8'); ?></p></div>
        </div>
    </section>
    <?php if (!$rankingReady): ?><section class="rounded-2xl border border-red-400/25 bg-red-500/10 p-4 text-sm text-red-200 flex items-start gap-3"><i class="bi bi-exclamation-triangle-fill mt-0.5"></i><span data-lang="ranking.error.unavailable"><?php echo htmlspecialchars(Lang::t('ranking.error.unavailable'),ENT_QUOTES,'UTF-8');?></span></section><?php endif; ?>
    <section class="grid grid-cols-2 gap-3">
        <div class="glass rounded-2xl p-4 min-w-0"><p class="text-[11px] uppercase tracking-wider text-gray-500" data-lang="ranking.my_position"><?php echo htmlspecialchars(Lang::t('ranking.my_position'), ENT_QUOTES, 'UTF-8'); ?></p><p class="text-3xl font-black text-green-300 mt-2"><?php echo $snapshot['lifetime_position'] !== null ? '#' . (int) $snapshot['lifetime_position'] : '—'; ?></p></div>
        <div class="glass rounded-2xl p-4 min-w-0"><p class="text-[11px] uppercase tracking-wider text-gray-500" data-lang="ranking.lifetime_amount"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_amount'), ENT_QUOTES, 'UTF-8'); ?></p><p class="text-xl md:text-2xl font-black text-emerald-300 mt-2 truncate"><?php echo htmlspecialchars(rankFormatThb((float) $snapshot['lifetime_total_thb']), ENT_QUOTES, 'UTF-8'); ?></p></div>
    </section>
    <section class="glass rounded-2xl overflow-hidden">
        <div class="p-4 md:p-5 border-b border-white/10"><div class="flex justify-between gap-3"><div><h2 class="font-extrabold text-white" data-lang="ranking.lifetime_title"><?php echo htmlspecialchars(Lang::t('ranking.lifetime_title'), ENT_QUOTES, 'UTF-8'); ?></h2><p class="text-xs text-gray-500 mt-1" data-lang="ranking.public_amount_note"><?php echo htmlspecialchars(Lang::t('ranking.public_amount_note'), ENT_QUOTES, 'UTF-8'); ?></p></div><span class="shrink-0 px-2.5 py-1 h-fit rounded-full text-[11px] bg-green-500/10 text-green-300 border border-green-400/20">TOP 50</span></div></div>
        <div class="p-3 space-y-2">
            <?php if (empty($lifetimeRows)): ?><div class="py-12 text-center text-gray-500" data-lang="ranking.empty"><?php echo htmlspecialchars(Lang::t('ranking.empty'), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php foreach ($lifetimeRows as $row): $isMe=(int)$row['user_id']===$userId; ?>
                <div class="rounded-xl border p-3 <?php echo $isMe?'border-green-400/40 bg-green-500/10':'border-white/5 bg-white/[.025]'; ?>">
                    <div class="flex items-center gap-3">
                        <div class="w-9 text-center font-black <?php echo (int)$row['position']<=3?'text-yellow-200':'text-gray-500'; ?>">#<?php echo (int)$row['position']; ?></div>
                        <div class="min-w-0 flex-1"><div class="flex items-center gap-2 min-w-0"><span class="font-bold text-white truncate"><?php echo htmlspecialchars((string)$row['masked_username'],ENT_QUOTES,'UTF-8'); ?></span><span class="text-[10px] px-2 py-0.5 rounded-full shrink-0 <?php echo $row['role']==='reseller'?'bg-green-500/15 text-green-300':'bg-blue-500/15 text-blue-300'; ?>"><?php echo htmlspecialchars(rankRoleLabel((string)$row['role']),ENT_QUOTES,'UTF-8'); ?></span><?php if($isMe):?><span class="text-[10px] text-green-200 shrink-0" data-lang="ranking.current_user"><?php echo htmlspecialchars(Lang::t('ranking.current_user'),ENT_QUOTES,'UTF-8');?></span><?php endif;?></div></div>
                        <div class="text-right shrink-0"><div class="font-black text-emerald-300"><?php echo htmlspecialchars(rankFormatThb((float)$row['total_thb']),ENT_QUOTES,'UTF-8');?></div><div class="text-[9px] text-gray-500" data-lang="ranking.amount"><?php echo htmlspecialchars(Lang::t('ranking.amount'),ENT_QUOTES,'UTF-8');?></div></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body></html>
