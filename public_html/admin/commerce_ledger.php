<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commerce_center.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string { return $isTh ? $th : $en; };
$h = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$message = '';
$error = '';
$schemaReady = commerceCenterEnsureSchema();

if (!$schemaReady) {
    $error = $t(
        'ตาราง Central Ledger ยังไม่พร้อม กรุณารัน private/commerce_center_schema.sql ในฐานข้อมูลนี้ก่อน',
        'Central Ledger tables are not ready. Run private/commerce_center_schema.sql in this database first.'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';
    if (!$schemaReady) {
        $error = $t('ฐานข้อมูล Ledger ยังไม่พร้อม', 'The ledger database schema is not ready.');
    } elseif ($action === 'reconcile') {
        $result = commerceCenterReconcile(160);
        if (!empty($result['success'])) {
            $message = $t('ซิงก์ Order และ Ledger แล้ว: ', 'Orders and ledger reconciled: ') . (string) ($result['message'] ?? '');
            logHistory((int) ($_SESSION['user_id'] ?? 0), 'commerce_ledger_reconcile', (string) ($result['message'] ?? 'completed'));
        } else {
            $error = $t('ซิงก์ Ledger ไม่สมบูรณ์: ', 'Ledger reconciliation was incomplete: ') . (string) ($result['message'] ?? 'unknown error');
        }
    }
}

$view = isset($_GET['view']) && is_scalar($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : 'ledger';
if (!in_array($view, ['ledger', 'financials'], true)) $view = 'ledger';
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$sourceType = isset($_GET['source_type']) && is_scalar($_GET['source_type']) ? trim((string) $_GET['source_type']) : '';
$entryType = isset($_GET['entry_type']) && is_scalar($_GET['entry_type']) ? trim((string) $_GET['entry_type']) : '';
$accountType = isset($_GET['account_type']) && is_scalar($_GET['account_type']) ? trim((string) $_GET['account_type']) : '';
$status = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string) $_GET['status']) : '';
$dateFrom = isset($_GET['date_from']) && is_scalar($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) && is_scalar($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
foreach (['search' => 190, 'sourceType' => 60, 'entryType' => 60, 'accountType' => 60, 'status' => 40] as $name => $max) {
    if (strlen($$name) > $max) $$name = substr($$name, 0, $max);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateTo)) $dateTo = '';

$page = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 100;
$offset = ($page - 1) * $perPage;
$stats = $schemaReady ? commerceCenterFinancialStats() : [];
$ledgerRows = [];
$financialRows = [];
$hasNextPage = false;
$commonFilters = [
    'search' => $search,
    'source_type' => $sourceType,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];
if ($schemaReady && $view === 'ledger') {
    $ledgerFilters = $commonFilters;
    $ledgerFilters['entry_type'] = $entryType;
    $ledgerFilters['account_type'] = $accountType;
    $ledgerRows = commerceCenterListLedger($ledgerFilters, $perPage + 1, $offset);
    $hasNextPage = count($ledgerRows) > $perPage;
    if ($hasNextPage) $ledgerRows = array_slice($ledgerRows, 0, $perPage);
} elseif ($schemaReady) {
    $financialFilters = $commonFilters;
    $financialFilters['financial_status'] = $status;
    unset($financialFilters['status']);
    $financialRows = commerceCenterListFinancials($financialFilters, $perPage + 1, $offset);
    $hasNextPage = count($financialRows) > $perPage;
    if ($hasNextPage) $financialRows = array_slice($financialRows, 0, $perPage);
}
$pageUrl = static function (int $targetPage) use ($view, $search, $sourceType, $entryType, $accountType, $status, $dateFrom, $dateTo): string {
    $query = array_filter([
        'view' => $view !== 'ledger' ? $view : null,
        'q' => $search !== '' ? $search : null,
        'source_type' => $sourceType !== '' ? $sourceType : null,
        'entry_type' => $entryType !== '' ? $entryType : null,
        'account_type' => $accountType !== '' ? $accountType : null,
        'status' => $status !== '' ? $status : null,
        'date_from' => $dateFrom !== '' ? $dateFrom : null,
        'date_to' => $dateTo !== '' ? $dateTo : null,
        'page' => $targetPage > 1 ? $targetPage : null,
    ], static fn($value): bool => $value !== null && $value !== '');
    return 'commerce_ledger.php' . ($query ? ('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)) : '');
};

$sourceLabels = [
    'commerce_order' => $t('ผลการเงินจาก Order', 'Order financial event'),
    'transaction' => $t('กระเป๋าผู้ใช้', 'User wallet transaction'),
    'store_api_balance_ledger' => $t('เครดิต Store API', 'Store API credit ledger'),
    'local_purchase' => $t('คีย์ในเว็บ', 'Local purchase'),
    'cgo_purchase' => 'CGO API',
    'supplier_purchase' => $t('ซื้อผ่าน Store API', 'Store API purchase'),
    'store_api_sale' => $t('ขายผ่าน Store API', 'Store API sale'),
];
$ledgerSourceOptions = [
    'commerce_order' => $sourceLabels['commerce_order'],
    'local_purchase' => $sourceLabels['local_purchase'],
    'cgo_purchase' => $sourceLabels['cgo_purchase'],
    'supplier_purchase' => $sourceLabels['supplier_purchase'],
    'store_api_sale' => $sourceLabels['store_api_sale'],
    'transaction' => $sourceLabels['transaction'],
    'store_api_balance_ledger' => $sourceLabels['store_api_balance_ledger'],
];
$financialSourceOptions = [
    'local_purchase' => $sourceLabels['local_purchase'],
    'cgo_purchase' => $sourceLabels['cgo_purchase'],
    'supplier_purchase' => $sourceLabels['supplier_purchase'],
    'store_api_sale' => $sourceLabels['store_api_sale'],
];
$entryLabels = [
    'sale_revenue' => $t('รายได้จากการขาย', 'Sale revenue'),
    'cost_of_goods' => $t('ต้นทุนสินค้า', 'Cost of goods'),
    'refund_reversal' => $t('หักรายได้จากการคืนเงิน', 'Refund reversal'),
    'purchase' => $t('ซื้อคีย์ในเว็บ', 'Local purchase debit'),
    'cgo_purchase' => $t('ซื้อ CGO', 'CGO purchase debit'),
    'supplier_purchase' => $t('ซื้อผ่าน Supplier', 'Supplier purchase debit'),
    'store_api_purchase' => $t('หักยอดตัวแทนจาก Store API', 'Reseller wallet Store API debit'),
    'deposit' => $t('เติมเงิน', 'Deposit'),
    'redeem_code' => $t('เติมโค้ด', 'Redeem code'),
    'manual_add' => $t('แอดมินเพิ่มยอด', 'Admin credit'),
    'manual_deduct' => $t('แอดมินหักยอด', 'Admin debit'),
    'rank_bonus' => $t('โบนัสแรงค์', 'Rank bonus'),
    'opening_balance' => $t('ยอดเริ่มต้น API', 'API opening balance'),
    'admin_credit' => $t('เพิ่มเครดิต API', 'API admin credit'),
    'admin_debit' => $t('หักเครดิต API', 'API admin debit'),
    'order_debit' => $t('ตัดเครดิต API จาก Order', 'API order debit'),
];
$money = static function ($value, string $currency = 'THB') use ($h): string {
    return $h(number_format((float) $value, 2) . ' ' . strtoupper($currency !== '' ? $currency : 'THB'));
};
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($t('บัญชีธุรกรรมกลาง', 'Central Ledger')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.09)}
        .field{width:100%;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(17,24,39,.88);padding:.68rem .82rem;color:#f3f4f6}
        .field:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.15)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:.7rem;padding:.65rem .95rem;font-weight:700;transition:.15s}
        .btn-primary{background:#0284c7;color:#fff}.btn-primary:hover{background:#0369a1}
        .btn-soft{background:rgba(255,255,255,.08);color:#e5e7eb}.btn-soft:hover{background:rgba(255,255,255,.13)}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:.22rem .6rem;font-size:.75rem;font-weight:700}
    </style>
</head>
<body class="bg-[#0b1019] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-[1700px] mx-auto p-4 md:p-6 space-y-5">
    <section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3"><i class="bi bi-journal-text text-sky-400"></i><?php echo $h($t('บัญชีธุรกรรมกลาง', 'Central Ledger')); ?></h1>
            <p class="mt-2 text-gray-400 max-w-4xl"><?php echo $h($t(
                'รวมยอดขาย ต้นทุน กำไร Refund ธุรกรรมผู้ใช้ และเครดิต Store API จากข้อมูลจริง โดยเป็น Read Model และไม่แก้ยอดเงินจริง',
                'Unified sales, cost, profit, refunds, user-wallet transactions and Store API credit movements. This is a read model and never changes authoritative balances.'
            )); ?></p>
            <div class="mt-2 text-xs text-gray-500">Site ID: <?php echo $h(commerceCenterSiteId()); ?> · Center v<?php echo $h(COMMERCE_CENTER_VERSION); ?></div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn btn-soft" href="commerce_center.php"><i class="bi bi-database-check"></i><?php echo $h($t('Commerce Center', 'Commerce Center')); ?></a>
            <a class="btn btn-soft" href="transactions.php"><i class="bi bi-receipt"></i><?php echo $h($t('Transactions เดิม', 'Legacy transactions')); ?></a>
            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="reconcile"><button type="submit" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i><?php echo $h($t('ซิงก์ตอนนี้', 'Reconcile now')); ?></button></form>
        </div>
    </section>

    <?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/40 bg-red-900/20 p-4 text-red-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $h($error); ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="rounded-xl border border-emerald-500/40 bg-emerald-900/20 p-4 text-emerald-200"><i class="bi bi-check-circle mr-2"></i><?php echo $h($message); ?></div><?php endif; ?>

    <section class="grid grid-cols-2 xl:grid-cols-6 gap-3">
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('ยอดขายรวม', 'Gross sales')); ?></div><div class="text-xl font-bold mt-1 text-emerald-300"><?php echo $money($stats['gross_sales'] ?? 0); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('ต้นทุนที่ทราบ', 'Known cost')); ?></div><div class="text-xl font-bold mt-1 text-orange-300"><?php echo $money($stats['total_cost'] ?? 0); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('ยอดคืนเงิน', 'Refunds')); ?></div><div class="text-xl font-bold mt-1 text-rose-300"><?php echo $money($stats['refunds'] ?? 0); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('รายได้สุทธิ', 'Net revenue')); ?></div><div class="text-xl font-bold mt-1 text-cyan-300"><?php echo $money($stats['net_revenue'] ?? 0); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('กำไรจากต้นทุนที่ทราบ', 'Known profit')); ?></div><div class="text-xl font-bold mt-1 text-violet-300"><?php echo $money($stats['known_profit'] ?? 0); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('Order ต้นทุนไม่ทราบ', 'Unknown-cost orders')); ?></div><div class="text-xl font-bold mt-1 <?php echo (int) ($stats['unknown_cost_orders'] ?? 0) > 0 ? 'text-amber-300' : 'text-emerald-300'; ?>"><?php echo number_format((int) ($stats['unknown_cost_orders'] ?? 0)); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo number_format((int) ($stats['ledger_entries'] ?? 0)); ?> ledger rows</div></div>
    </section>

    <section class="glass rounded-xl p-4">
        <div class="flex flex-wrap gap-2 mb-4">
            <a href="?view=ledger" class="btn <?php echo $view === 'ledger' ? 'btn-primary' : 'btn-soft'; ?>"><i class="bi bi-list-ul"></i><?php echo $h($t('รายการ Ledger', 'Ledger entries')); ?></a>
            <a href="?view=financials" class="btn <?php echo $view === 'financials' ? 'btn-primary' : 'btn-soft'; ?>"><i class="bi bi-graph-up-arrow"></i><?php echo $h($t('สรุปต่อ Order', 'Order financials')); ?></a>
        </div>
        <form method="get" class="grid sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-2">
            <input type="hidden" name="view" value="<?php echo $h($view); ?>">
            <input class="field xl:col-span-2" name="q" value="<?php echo $h($search); ?>" placeholder="Order / Ref / Buyer / Account / Product">
            <select class="field" name="source_type">
                <option value=""><?php echo $h($t('ทุกแหล่ง', 'All sources')); ?></option>
                <?php foreach (($view === 'ledger' ? $ledgerSourceOptions : $financialSourceOptions) as $value => $label): ?><option value="<?php echo $h($value); ?>" <?php echo $sourceType === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?>
            </select>
            <?php if ($view === 'ledger'): ?>
            <select class="field" name="entry_type"><option value=""><?php echo $h($t('ทุกรายการ', 'All entry types')); ?></option><?php foreach ($entryLabels as $value => $label): ?><option value="<?php echo $h($value); ?>" <?php echo $entryType === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select>
            <select class="field" name="account_type"><option value=""><?php echo $h($t('ทุกบัญชี', 'All accounts')); ?></option><?php foreach (['sales_revenue','inventory_cost','sales_returns','local_user_wallet','api_client_wallet'] as $value): ?><option value="<?php echo $h($value); ?>" <?php echo $accountType === $value ? 'selected' : ''; ?>><?php echo $h($value); ?></option><?php endforeach; ?></select>
            <input class="field" type="date" name="date_from" value="<?php echo $h($dateFrom); ?>">
            <input class="field" type="date" name="date_to" value="<?php echo $h($dateTo); ?>">
            <?php else: ?>
            <select class="field" name="status"><option value=""><?php echo $h($t('ทุกสถานะ', 'All statuses')); ?></option><?php foreach (['recognized','refunded','pending','void','review'] as $value): ?><option value="<?php echo $h($value); ?>" <?php echo $status === $value ? 'selected' : ''; ?>><?php echo $h($value); ?></option><?php endforeach; ?></select>
            <input class="field" type="date" name="date_from" value="<?php echo $h($dateFrom); ?>">
            <input class="field" type="date" name="date_to" value="<?php echo $h($dateTo); ?>">
            <?php endif; ?>
            <button class="btn btn-soft" type="submit"><i class="bi bi-search"></i><?php echo $h($t('ค้นหา', 'Search')); ?></button>
        </form>
    </section>

    <?php if ($view === 'ledger'): ?>
    <section class="glass rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/5"><h2 class="font-bold"><i class="bi bi-list-check text-sky-300 mr-2"></i><?php echo $h($t('รายการธุรกรรมรวม', 'Unified transaction entries')); ?></h2><p class="text-xs text-gray-500 mt-1"><?php echo $h($t('รายการกระเป๋าเงินมี reporting amount เป็นศูนย์ เพื่อไม่ให้นับรายได้ซ้ำกับยอดขายจาก Order', 'Wallet entries have zero reporting amount so order revenue is not counted twice.')); ?></p></div>
        <div class="overflow-auto max-h-[820px]"><table class="w-full min-w-[1450px] text-sm">
            <thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left"><?php echo $h($t('เวลา / แหล่ง', 'Time / source')); ?></th><th class="p-3 text-left"><?php echo $h($t('ประเภทรายการ', 'Entry')); ?></th><th class="p-3 text-left"><?php echo $h($t('บัญชี', 'Account')); ?></th><th class="p-3 text-left"><?php echo $h($t('คู่รายการ', 'Counterparty')); ?></th><th class="p-3 text-right"><?php echo $h($t('จำนวน', 'Amount')); ?></th><th class="p-3 text-right"><?php echo $h($t('ผลต่อรายงาน', 'Reporting')); ?></th><th class="p-3 text-left"><?php echo $h($t('Order / สินค้า', 'Order / product')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ', 'Status')); ?></th></tr></thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($ledgerRows as $row): $reporting = (float) ($row['reporting_amount'] ?? 0); ?>
                <tr class="hover:bg-white/[.025]">
                    <td class="p-3"><div><?php echo $h($row['occurred_at'] ?: $row['created_at']); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo $h($sourceLabels[$row['source_type']] ?? $row['source_type']); ?> #<?php echo $h($row['source_record_id']); ?></div><?php if ((string) ($row['source_type'] ?? '') === 'commerce_order' && (string) ($row['order_source_type'] ?? '') !== ''): ?><div class="text-xs text-violet-300 mt-1"><?php echo $h($sourceLabels[$row['order_source_type']] ?? $row['order_source_type']); ?></div><?php endif; ?></td>
                    <td class="p-3"><div class="font-semibold"><?php echo $h($entryLabels[$row['entry_type']] ?? $row['entry_type']); ?></div><div class="text-xs text-gray-500 max-w-[330px] truncate" title="<?php echo $h($row['description']); ?>"><?php echo $h($row['description']); ?></div></td>
                    <td class="p-3"><div class="font-semibold"><?php echo $h($row['account_label_snapshot'] ?: $row['account_type']); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['account_type'] . ':' . $row['account_id']); ?></div><?php if ($row['balance_after'] !== null): ?><div class="text-xs text-cyan-300 mt-1"><?php echo $h(number_format((float) $row['balance_before'], 2) . ' → ' . number_format((float) $row['balance_after'], 2)); ?></div><?php endif; ?></td>
                    <td class="p-3"><div><?php echo $h($row['counterparty_label_snapshot'] ?: '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h(trim($row['counterparty_type'] . ':' . $row['counterparty_id'], ':')); ?></div></td>
                    <td class="p-3 text-right"><div class="font-bold <?php echo $row['direction'] === 'credit' ? 'text-emerald-300' : 'text-rose-300'; ?>"><?php echo $h(($row['direction'] === 'credit' ? '+' : '-') . number_format((float) $row['amount'], 2)); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['currency']); ?></div></td>
                    <td class="p-3 text-right font-semibold <?php echo $reporting > 0 ? 'text-emerald-300' : ($reporting < 0 ? 'text-rose-300' : 'text-gray-500'); ?>"><?php echo $h(($reporting > 0 ? '+' : '') . number_format($reporting, 2)); ?></td>
                    <td class="p-3"><div class="font-semibold"><?php echo $h($row['product_name_snapshot'] ?: '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['duration_snapshot']); ?></div><?php if ((int) $row['order_id'] > 0): ?><div class="font-mono text-[11px] text-violet-300 mt-1 break-all"><?php echo $h($row['order_uuid']); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['external_ref']); ?></div><?php endif; ?></td>
                    <td class="p-3"><span class="badge <?php echo in_array(strtolower((string) $row['status']), ['completed','success','recognized'], true) ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'; ?>"><?php echo $h($row['status']); ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($ledgerRows === []): ?><tr><td colspan="8" class="p-10 text-center text-gray-500"><?php echo $h($t('ยังไม่มีข้อมูล Ledger หรือยังไม่ได้ Backfill', 'No ledger data yet, or backfill has not run.')); ?></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php else: ?>
    <section class="glass rounded-xl overflow-hidden">
        <div class="p-4 border-b border-white/5"><h2 class="font-bold"><i class="bi bi-calculator text-violet-300 mr-2"></i><?php echo $h($t('ผลการเงินต่อคำสั่งซื้อ', 'Financials by order')); ?></h2><p class="text-xs text-gray-500 mt-1"><?php echo $h($t('กำไรจะแสดงเฉพาะ Order ที่มีต้นทุนยืนยันได้ ไม่ถือว่าต้นทุนที่ไม่ทราบเป็นศูนย์', 'Profit is shown only when cost is known; unknown cost is never treated as zero.')); ?></p></div>
        <div class="overflow-auto max-h-[820px]"><table class="w-full min-w-[1300px] text-sm">
            <thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left">Order / Source</th><th class="p-3 text-left"><?php echo $h($t('ผู้ซื้อ / สินค้า', 'Buyer / product')); ?></th><th class="p-3 text-right"><?php echo $h($t('ยอดขาย', 'Sales')); ?></th><th class="p-3 text-right"><?php echo $h($t('ต้นทุน', 'Cost')); ?></th><th class="p-3 text-right"><?php echo $h($t('Refund', 'Refund')); ?></th><th class="p-3 text-right"><?php echo $h($t('รายได้สุทธิ', 'Net revenue')); ?></th><th class="p-3 text-right"><?php echo $h($t('กำไร', 'Profit')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ', 'Status')); ?></th></tr></thead>
            <tbody class="divide-y divide-white/5">
            <?php foreach ($financialRows as $row): ?>
                <tr class="hover:bg-white/[.025]">
                    <td class="p-3"><div class="font-mono text-xs break-all"><?php echo $h($row['order_uuid']); ?></div><div class="mt-1"><span class="badge bg-violet-500/15 text-violet-200"><?php echo $h($sourceLabels[$row['source_type']] ?? $row['source_type']); ?></span></div><div class="text-xs text-gray-500 mt-1">#<?php echo $h($row['source_record_id']); ?> · <?php echo $h($row['external_ref']); ?></div></td>
                    <td class="p-3"><div class="font-semibold"><?php echo $h($row['buyer_name_snapshot'] ?: '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['customer_ref']); ?></div><div class="mt-2 font-semibold text-cyan-100"><?php echo $h($row['product_name_snapshot'] ?: '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['duration_snapshot']); ?> × <?php echo (int) ($row['quantity'] ?? 0); ?></div></td>
                    <td class="p-3 text-right font-semibold text-emerald-300"><?php echo $money($row['sale_total'], $row['currency']); ?></td>
                    <td class="p-3 text-right"><div class="font-semibold <?php echo $row['cost_status'] === 'known' ? 'text-orange-300' : 'text-amber-300'; ?>"><?php echo $row['cost_status'] === 'known' ? $money($row['cost_total'], $row['currency']) : $h($t('ไม่ทราบ', 'Unknown')); ?></div><div class="text-xs text-gray-500"><?php echo $h($row['cost_status']); ?></div></td>
                    <td class="p-3 text-right font-semibold text-rose-300"><?php echo $money($row['refund_total'], $row['currency']); ?></td>
                    <td class="p-3 text-right font-semibold text-cyan-300"><?php echo $money($row['net_revenue'], $row['currency']); ?></td>
                    <td class="p-3 text-right"><div class="font-bold <?php echo $row['profit_total'] === null ? 'text-gray-500' : ((float) $row['profit_total'] >= 0 ? 'text-violet-300' : 'text-rose-300'); ?>"><?php echo $row['profit_total'] === null ? $h('-') : $money($row['profit_total'], $row['currency']); ?></div><?php if ($row['margin_percent'] !== null): ?><div class="text-xs text-gray-500"><?php echo $h(number_format((float) $row['margin_percent'], 2)); ?>%</div><?php endif; ?></td>
                    <td class="p-3"><span class="badge <?php echo $row['financial_status'] === 'recognized' ? 'bg-emerald-500/15 text-emerald-200' : ($row['financial_status'] === 'review' ? 'bg-red-500/15 text-red-200' : 'bg-amber-500/15 text-amber-200'); ?>"><?php echo $h($row['financial_status']); ?></span><div class="text-xs text-gray-500 mt-2"><?php echo $h($row['source_completed_at'] ?: $row['source_created_at']); ?></div></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($financialRows === []): ?><tr><td colspan="8" class="p-10 text-center text-gray-500"><?php echo $h($t('ยังไม่มีข้อมูล Financials หรือยังไม่ได้ Backfill', 'No financial data yet, or backfill has not run.')); ?></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <?php if ($page > 1 || $hasNextPage): ?>
    <nav class="flex flex-wrap items-center justify-center gap-2" aria-label="<?php echo $h($t('หน้ารายการ Ledger', 'Ledger pages')); ?>">
        <?php if ($page > 1): ?><a class="btn btn-soft" href="<?php echo $h($pageUrl($page - 1)); ?>"><i class="bi bi-chevron-left"></i><?php echo $h($t('ก่อนหน้า', 'Previous')); ?></a><?php endif; ?>
        <span class="px-3 py-2 text-sm text-gray-400"><?php echo $h($t('หน้า ', 'Page ') . number_format($page)); ?></span>
        <?php if ($hasNextPage): ?><a class="btn btn-soft" href="<?php echo $h($pageUrl($page + 1)); ?>"><?php echo $h($t('ถัดไป', 'Next')); ?><i class="bi bi-chevron-right"></i></a><?php endif; ?>
    </nav>
    <?php endif; ?>

    <section class="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4 text-sm text-sky-100">
        <div class="font-bold"><i class="bi bi-shield-check mr-2"></i><?php echo $h($t('ขอบเขตความปลอดภัย', 'Safety boundary')); ?></div>
        <p class="mt-1 text-sky-100/70"><?php echo $h($t(
            'หน้านี้อ่านและ Backfill ข้อมูลรายงานเท่านั้น ไม่ปรับ Balance ผู้ใช้ เครดิต API สต็อก คีย์ หรือสถานะ Order ต้นทาง',
            'This page only reads and backfills reporting data. It does not modify user balances, API credit, stock, keys or authoritative order status.'
        )); ?></p>
    </section>
</main>
</body>
</html>
