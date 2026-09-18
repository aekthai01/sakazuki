<?php
require_once '../includes/auth.php';
requireAdmin();

$defaultEnd = date('Y-m-d');
$defaultStart = date('Y-m-d', strtotime('-30 days'));
$normalizeDateInput = static function ($value, string $fallback): string {
    if (!is_scalar($value) && $value !== null) return $fallback;
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return $fallback;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0))) return $fallback;
    return $date->format('Y-m-d') === $value ? $value : $fallback;
};
$startDate = $normalizeDateInput($_GET['start'] ?? null, $defaultStart);
$endDate = $normalizeDateInput($_GET['end'] ?? null, $defaultEnd);

$profitReport = commerceCenterProfitReport([
    'date_from' => $startDate,
    'date_to' => $endDate,
    'financial_status' => 'recognized',
], 500);
$summary = is_array($profitReport['summary'] ?? null) ? $profitReport['summary'] : [];
$totalRevenue = (float) ($summary['revenue'] ?? 0);
$totalCost = (float) ($summary['cost'] ?? 0);
$totalProfit = (float) ($summary['profit'] ?? 0);
$unknownCostOrders = max(0, (int) ($summary['unknown_cost_orders'] ?? 0));
$byProduct = is_array($profitReport['by_product'] ?? null) ? $profitReport['by_product'] : [];
$rows = is_array($profitReport['details'] ?? null) ? $profitReport['details'] : [];
$reportError = !empty($profitReport['success']) ? '' : trim((string) ($profitReport['message'] ?? ''));
$detailsTruncated = !empty($profitReport['details_truncated']);
$detailLimit = max(0, (int) ($profitReport['detail_limit'] ?? 500));

?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title data-lang="admin.profit.title"><?php echo Lang::t('admin.profit.title'); ?> - Admin Panel</title>
  <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08);}    
  </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
  <?php include 'nav.php'; ?>

  <main class="p-4 md:p-6 space-y-4">
    <div id="adminProfitLivePanel" data-instant-panel class="space-y-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <h1 class="text-xl md:text-2xl font-bold text-white flex items-center gap-2">
        <i class="bi bi-graph-up-arrow text-blue-400"></i>
        <span data-lang="admin.profit.heading"><?php echo Lang::t('admin.profit.heading'); ?></span>
      </h1>

      <form class="glass rounded-xl p-3 flex flex-col md:flex-row gap-2 items-stretch md:items-end" method="GET">
        <div>
          <label class="text-xs text-gray-400" data-lang="admin.profit.start"><?php echo Lang::t('admin.profit.start'); ?></label>
          <input type="date" name="start" value="<?php echo htmlspecialchars($startDate); ?>"
                 class="mt-1 w-full bg-transparent border border-white/10 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="text-xs text-gray-400" data-lang="admin.profit.end"><?php echo Lang::t('admin.profit.end'); ?></label>
          <input type="date" name="end" value="<?php echo htmlspecialchars($endDate); ?>"
                 class="mt-1 w-full bg-transparent border border-white/10 rounded-lg px-3 py-2 text-sm" />
        </div>
        <button class="bg-blue-500 hover:opacity-90 text-white rounded-lg px-4 py-2 text-sm font-medium" data-lang="admin.profit.apply">
          <?php echo Lang::t('admin.profit.apply'); ?>
        </button>
      </form>
    </div>

    <?php if ($reportError !== ''): ?>
      <div class="glass rounded-xl p-3 border border-red-500/30 text-sm text-red-300">
        <?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php elseif ($unknownCostOrders > 0): ?>
      <div class="glass rounded-xl p-3 border border-amber-500/30 text-sm text-amber-300" data-lang="admin.profit.unknown_cost_warning">
        <?php echo htmlspecialchars(str_replace('{count}', number_format($unknownCostOrders), Lang::t('admin.profit.unknown_cost_warning')), ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div class="glass rounded-xl p-4">
        <div class="text-xs text-gray-400" data-lang="admin.profit.revenue"><?php echo Lang::t('admin.profit.revenue'); ?></div>
        <div class="text-lg md:text-2xl font-extrabold text-white"><?php echo formatCurrency($totalRevenue); ?></div>
      </div>
      <div class="glass rounded-xl p-4">
        <div class="text-xs text-gray-400" data-lang="admin.profit.cost_modal"><?php echo Lang::t('admin.profit.cost_modal'); ?></div>
        <div class="text-lg md:text-2xl font-extrabold text-white"><?php echo formatCurrency($totalCost); ?></div>
      </div>
      <div class="glass rounded-xl p-4">
        <div class="text-xs text-gray-400" data-lang="admin.profit.profit"><?php echo Lang::t('admin.profit.profit'); ?></div>
        <div class="text-lg md:text-2xl font-extrabold <?php echo ($totalProfit >= 0) ? 'text-green-400' : 'text-red-400'; ?>"><?php echo formatCurrency($totalProfit); ?></div>
      </div>
    </div>

    <!-- Product summary -->
    <div class="glass rounded-xl p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold text-white" data-lang="admin.profit.by_product"><?php echo Lang::t('admin.profit.by_product'); ?></h2>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-gray-400 border-b border-white/10">
              <th class="text-left py-2" data-lang="admin.reseller_prices.table.product"><?php echo Lang::t('admin.reseller_prices.table.product'); ?></th>
               <th class="text-right py-2" data-lang="admin.profit.table.sold"><?php echo Lang::t('admin.profit.table.sold'); ?></th>
               <th class="text-right py-2" data-lang="admin.profit.revenue"><?php echo Lang::t('admin.profit.revenue'); ?></th>
              <th class="text-right py-2" data-lang="admin.profit.cost_modal"><?php echo Lang::t('admin.profit.cost_modal'); ?></th>
              <th class="text-right py-2" data-lang="admin.profit.profit"><?php echo Lang::t('admin.profit.profit'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($byProduct)): ?>
             <tr><td colspan="5" class="py-4 text-center text-gray-400" data-lang="admin.profit.empty_range"><?php echo Lang::t('admin.profit.empty_range'); ?></td></tr>
          <?php else: ?>
            <?php foreach ($byProduct as $pname => $s): ?>
              <tr class="border-b border-white/5">
                <td class="py-2 pr-2 text-white"><?php echo htmlspecialchars($pname); ?></td>
                <td class="py-2 text-right text-gray-200"><?php echo (int)$s['count']; ?></td>
                <td class="py-2 text-right text-gray-200"><?php echo formatCurrency($s['revenue']); ?></td>
                <td class="py-2 text-right text-gray-200"><?php echo formatCurrency($s['cost']); ?></td>
                <td class="py-2 text-right font-bold <?php echo ($s['profit'] >= 0) ? 'text-green-400' : 'text-red-400'; ?>"><?php echo formatCurrency($s['profit']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail list -->
    <div class="glass rounded-xl p-4">
      <div class="flex items-center justify-between gap-3 mb-3">
        <h2 class="font-bold text-white" data-lang="admin.profit.recent_purchases"><?php echo Lang::t('admin.profit.recent_purchases'); ?></h2>
        <div class="text-right">
          <div class="text-xs text-gray-400" data-lang="admin.profit.includes_reseller"><?php echo Lang::t('admin.profit.includes_reseller'); ?></div>
          <?php if ($detailsTruncated): ?>
            <div class="text-[11px] text-gray-500" data-lang="admin.profit.top_500"><?php echo Lang::t('admin.profit.top_500'); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-gray-400 border-b border-white/10">
               <th class="text-left py-2" data-lang="admin.transactions.table.date"><?php echo Lang::t('admin.transactions.table.date'); ?></th>
               <th class="text-left py-2" data-lang="admin.codes.table.used_by"><?php echo Lang::t('admin.codes.table.used_by'); ?></th>
              <th class="text-left py-2" data-lang="admin.reseller_prices.table.product"><?php echo Lang::t('admin.reseller_prices.table.product'); ?></th>
               <th class="text-left py-2" data-lang="admin.reseller_prices.table.duration"><?php echo Lang::t('admin.reseller_prices.table.duration'); ?></th>
               <th class="text-right py-2" data-lang="admin.profit.table.sell"><?php echo Lang::t('admin.profit.table.sell'); ?></th>
              <th class="text-right py-2" data-lang="admin.profit.cost_modal"><?php echo Lang::t('admin.profit.cost_modal'); ?></th>
              <th class="text-right py-2" data-lang="admin.profit.profit"><?php echo Lang::t('admin.profit.profit'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
             <tr><td colspan="7" class="py-4 text-center text-gray-400" data-lang="admin.profit.empty_purchases"><?php echo Lang::t('admin.profit.empty_purchases'); ?></td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $rev = (float)($r['amount'] ?? 0);
              $cst = (float)($r['cost_price'] ?? 0);
              $prfKnown = array_key_exists('profit', $r) && $r['profit'] !== null;
              $prf = $prfKnown ? (float) $r['profit'] : 0.0;
              $createdTimestamp = strtotime((string) ($r['created_at'] ?? ''));
            ?>
              <tr class="border-b border-white/5">
                <td class="py-2 pr-2 text-gray-300"><?php echo htmlspecialchars($createdTimestamp !== false ? date('Y-m-d H:i', $createdTimestamp) : '-'); ?></td>
                <td class="py-2 pr-2 text-gray-200"><?php echo htmlspecialchars($r['username'] ?? '-'); ?> <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($r['role'] ?? '-'); ?>)</span></td>
                <td class="py-2 pr-2 text-white"><?php echo htmlspecialchars($r['product_name'] ?? '-'); ?></td>
                <td class="py-2 pr-2 text-gray-300"><?php echo htmlspecialchars($r['duration'] ?? '-'); ?></td>
                <td class="py-2 text-right text-gray-200"><?php echo formatCurrency($rev); ?></td>
                <td class="py-2 text-right text-gray-200"><?php echo $prfKnown ? formatCurrency($cst) : '-'; ?></td>
                <td class="py-2 text-right font-bold <?php echo !$prfKnown ? 'text-amber-300' : (($prf >= 0) ? 'text-green-400' : 'text-red-400'); ?>"><?php echo $prfKnown ? formatCurrency($prf) : Lang::t('admin.profit.unknown_cost_short'); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-gray-500 mt-3" data-lang="admin.profit.help_text">
        <?php echo Lang::t('admin.profit.help_text'); ?>
      </p>
    </div>
    </div>

  </main>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
