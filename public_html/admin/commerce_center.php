<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commerce_center.php';
require_once __DIR__ . '/../includes/commerce_context.php';
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
$keyContext = null;

$schemaReady = commerceCenterEnsureSchema();
if (!$schemaReady) {
    $error = $t(
        'ตาราง Commerce Center ยังไม่พร้อม กรุณารัน private/commerce_center_schema.sql ในฐานข้อมูลนี้ก่อน',
        'Commerce Center tables are not ready. Run private/commerce_center_schema.sql in this database first.'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';
    if (!$schemaReady) {
        $error = $t('ฐานข้อมูลส่วนกลางยังไม่พร้อม', 'The central database schema is not ready.');
    } elseif ($action === 'reconcile') {
        $result = commerceCenterReconcile(80);
        if (!empty($result['success'])) {
            $message = $t('ซิงก์ข้อมูลส่วนกลางแล้ว: ', 'Commerce reconciliation completed: ') . (string) ($result['message'] ?? '');
            logHistory((int) ($_SESSION['user_id'] ?? 0), 'commerce_center_reconcile', (string) ($result['message'] ?? 'completed'));
        } else {
            $error = $t('ซิงก์ข้อมูลส่วนกลางไม่สมบูรณ์: ', 'Commerce reconciliation was incomplete: ') . (string) ($result['message'] ?? 'unknown error');
        }
    } elseif ($action === 'lookup_key') {
        $key = isset($_POST['license_key']) && is_scalar($_POST['license_key']) ? trim((string) $_POST['license_key']) : '';
        if ($key === '' || strlen($key) > 5000) {
            $error = $t('คีย์ไม่ถูกต้อง', 'Invalid key.');
        } else {
            $keyContext = commerceResolveKeyContext(['full_key' => $key]);
            if (empty($keyContext['resolved'])) {
                $error = !empty($keyContext['conflict'])
                    ? $t('พบคีย์ซ้ำหลายแหล่ง ต้องตรวจสอบด้วยตนเอง', 'The key matched multiple sources and requires manual review.')
                    : $t('ไม่พบประวัติการส่งมอบคีย์นี้', 'No delivery history was found for this key.');
            }
        }
    }
}

$source = isset($_GET['source']) && is_scalar($_GET['source']) ? trim((string) $_GET['source']) : '';
$status = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string) $_GET['status']) : '';
$search = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
$allowedSources = ['', 'local_purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_sale'];
if (!in_array($source, $allowedSources, true)) $source = '';
if (strlen($status) > 40) $status = '';
if (strlen($search) > 190) $search = substr($search, 0, 190);

$stats = $schemaReady ? commerceCenterStats() : [];
$orders = $schemaReady ? commerceCenterListOrders(['source_type' => $source, 'status' => $status, 'search' => $search], 200) : [];
$failures = [];
if ($schemaReady) {
    try {
        $result = $conn->query("SELECT * FROM commerce_sync_failures WHERE resolved_at IS NULL ORDER BY last_failed_at DESC LIMIT 50");
        $failures = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) $result->free();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $t('อ่านรายการซิงก์ล้มเหลวไม่ได้', 'Unable to read sync failures.');
    }
}

$sourceLabels = [
    'local_purchase' => $t('คีย์ในเว็บไซต์', 'Local key'),
    'cgo_purchase' => 'CGO API',
    'supplier_purchase' => $t('ซื้อผ่าน Store API', 'Store API purchase'),
    'store_api_sale' => $t('ขายผ่าน Store API', 'Store API sale'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($t('ศูนย์ข้อมูลการค้า', 'Commerce Center')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.09)}
        .field{width:100%;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(17,24,39,.86);padding:.7rem .85rem;color:#f3f4f6}
        .field:focus{outline:none;border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.16)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:.7rem;padding:.65rem .95rem;font-weight:700;transition:.15s}
        .btn-primary{background:#7c3aed;color:#fff}.btn-primary:hover{background:#6d28d9}
        .btn-soft{background:rgba(255,255,255,.08);color:#e5e7eb}.btn-soft:hover{background:rgba(255,255,255,.13)}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:.22rem .6rem;font-size:.75rem;font-weight:700}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-[1600px] mx-auto p-4 md:p-6 space-y-6">
    <section>
        <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3"><i class="bi bi-database-check text-violet-400"></i><?php echo $h($t('ศูนย์ข้อมูลการค้า', 'Commerce Center')); ?></h1>
        <p class="mt-2 text-gray-400 max-w-4xl"><?php echo $h($t(
            'รวมคำสั่งซื้อ สินค้า ราคา ผู้ซื้อ และเจ้าของคีย์จาก Local, CGO และ Store API โดยไม่เปลี่ยนเส้นทางตัดเงินหรือส่งคีย์เดิม',
            'Unified order, product, price, buyer and key-ownership snapshots without replacing the existing checkout or delivery flows.'
        )); ?></p>
        <div class="mt-2 text-xs text-gray-500">Site ID: <?php echo $h(commerceCenterSiteId()); ?> · Center v<?php echo $h(COMMERCE_CENTER_VERSION); ?></div>
        <div class="mt-4 flex flex-wrap gap-2"><a class="btn btn-soft" href="commerce_ledger.php"><i class="bi bi-journal-text text-sky-300"></i><?php echo $h($t('เปิดบัญชีธุรกรรมกลาง', 'Open Central Ledger')); ?></a><a class="btn btn-soft" href="transactions.php"><i class="bi bi-receipt"></i><?php echo $h($t('Transactions เดิม', 'Legacy transactions')); ?></a></div>
    </section>

    <?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/40 bg-red-900/20 p-4 text-red-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $h($error); ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="rounded-xl border border-emerald-500/40 bg-emerald-900/20 p-4 text-emerald-200"><i class="bi bi-check-circle mr-2"></i><?php echo $h($message); ?></div><?php endif; ?>

    <section class="grid grid-cols-2 lg:grid-cols-6 gap-3">
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('คำสั่งซื้อกลาง', 'Central orders')); ?></div><div class="text-2xl font-bold mt-1"><?php echo number_format((int) ($stats['orders'] ?? 0)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('คีย์ที่ส่งมอบ', 'Deliveries')); ?></div><div class="text-2xl font-bold mt-1"><?php echo number_format((int) ($stats['deliveries'] ?? 0)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('รายได้สุทธิ', 'Net revenue')); ?></div><div class="text-2xl font-bold mt-1 text-cyan-300"><?php echo $h(number_format((float) ($stats['net_revenue'] ?? 0), 2)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('กำไรที่ทราบต้นทุน', 'Known profit')); ?></div><div class="text-2xl font-bold mt-1 text-violet-300"><?php echo $h(number_format((float) ($stats['known_profit'] ?? 0), 2)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('รายการ Ledger', 'Ledger entries')); ?></div><div class="text-2xl font-bold mt-1"><?php echo number_format((int) ($stats['ledger_entries'] ?? 0)); ?></div><div class="text-xs text-amber-300 mt-1"><?php echo number_format((int) ($stats['unknown_cost_orders'] ?? 0)); ?> unknown cost</div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-400"><?php echo $h($t('รอซ่อมข้อมูล', 'Unresolved sync failures')); ?></div><div class="text-2xl font-bold mt-1 <?php echo (int) ($stats['failures'] ?? 0) > 0 ? 'text-red-300' : 'text-emerald-300'; ?>"><?php echo number_format((int) ($stats['failures'] ?? 0)); ?></div></div>
    </section>

    <section class="grid lg:grid-cols-2 gap-4">
        <div class="glass rounded-xl p-5">
            <h2 class="font-bold text-lg"><i class="bi bi-arrow-repeat text-sky-300 mr-2"></i><?php echo $h($t('ตามซ่อมและ Backfill', 'Reconcile and backfill')); ?></h2>
            <p class="text-sm text-gray-400 mt-2"><?php echo $h($t('สแกนข้อมูลที่ตกหล่นหรือมีสถานะเปลี่ยน โดยไม่แตะยอดเงินและสต็อก', 'Scans missing or changed source orders without changing wallet or stock data.')); ?></p>
            <form method="post" class="mt-4"><?php echo csrfField(); ?><input type="hidden" name="action" value="reconcile"><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-clockwise"></i><?php echo $h($t('ซิงก์ส่วนกลางตอนนี้', 'Reconcile now')); ?></button></form>
        </div>
        <div class="glass rounded-xl p-5">
            <h2 class="font-bold text-lg"><i class="bi bi-key text-amber-300 mr-2"></i><?php echo $h($t('ตรวจเจ้าของคีย์', 'Key ownership lookup')); ?></h2>
            <form method="post" class="mt-3 flex flex-col sm:flex-row gap-2"><?php echo csrfField(); ?><input type="hidden" name="action" value="lookup_key"><input class="field flex-1 font-mono" name="license_key" autocomplete="off" maxlength="5000" required placeholder="License key"><button class="btn btn-soft" type="submit"><i class="bi bi-search"></i><?php echo $h($t('ค้นหา', 'Search')); ?></button></form>
        </div>
    </section>

    <?php if (is_array($keyContext) && !empty($keyContext['resolved'])): ?>
    <section class="glass rounded-xl p-5 border border-emerald-500/20">
        <h2 class="font-bold text-lg text-emerald-200"><i class="bi bi-person-check mr-2"></i><?php echo $h($t('พบประวัติการส่งมอบ', 'Delivery ownership found')); ?></h2>
        <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('สินค้า', 'Product')); ?></div><div class="mt-1 font-semibold"><?php echo $h($keyContext['product_display_name'] ?? '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('ผู้ถือครอง', 'Owner')); ?></div><div class="mt-1 font-semibold"><?php echo $h($keyContext['owner_display'] ?? '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h(($keyContext['owner_site_id'] ?? '') . ' / ' . ($keyContext['owner_external_user_id'] ?? '')); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('ระดับยืนยัน', 'Ownership confidence')); ?></div><div class="mt-1 font-semibold"><?php echo $h($keyContext['ownership_status'] ?? '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('คำสั่งซื้อ', 'Order')); ?></div><div class="mt-1 font-mono text-xs break-all"><?php echo $h($keyContext['order_uuid'] ?? '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($keyContext['external_ref'] ?? ''); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('ราคา', 'Total')); ?></div><div class="mt-1 font-semibold"><?php echo $h(number_format((float) ($keyContext['total_price'] ?? 0), 2) . ' ' . ($keyContext['currency'] ?? '')); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('เวลา', 'Purchased at')); ?></div><div class="mt-1 font-semibold"><?php echo $h($keyContext['purchased_at'] ?? '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3 sm:col-span-2"><div class="text-gray-500">Customer ref</div><div class="mt-1 font-mono text-xs break-all"><?php echo $h($keyContext['customer_ref'] ?? '-'); ?></div></div>
        </div>
    </section>
    <?php endif; ?>

    <section class="glass rounded-xl p-5">
        <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div><h2 class="font-bold text-lg"><i class="bi bi-receipt text-violet-300 mr-2"></i><?php echo $h($t('คำสั่งซื้อรวม', 'Unified orders')); ?></h2><p class="text-sm text-gray-500 mt-1"><?php echo $h($t('แสดงสูงสุด 200 รายการล่าสุดตามตัวกรอง', 'Shows up to 200 latest matching rows.')); ?></p></div>
            <form method="get" class="grid sm:grid-cols-3 gap-2 w-full lg:w-auto">
                <select class="field" name="source"><option value=""><?php echo $h($t('ทุกแหล่ง', 'All sources')); ?></option><?php foreach ($sourceLabels as $value => $label): ?><option value="<?php echo $h($value); ?>" <?php echo $source === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select>
                <input class="field" name="status" value="<?php echo $h($status); ?>" placeholder="status">
                <div class="flex gap-2"><input class="field" name="q" value="<?php echo $h($search); ?>" placeholder="UUID / Ref / Buyer / Product"><button class="btn btn-soft" type="submit"><i class="bi bi-funnel"></i></button></div>
            </form>
        </div>
        <div class="overflow-auto mt-4 max-h-[720px]">
            <table class="w-full min-w-[1100px] text-sm">
                <thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left">UUID / Source</th><th class="p-3 text-left"><?php echo $h($t('ผู้ซื้อ', 'Buyer')); ?></th><th class="p-3 text-left"><?php echo $h($t('สินค้า', 'Product')); ?></th><th class="p-3 text-right"><?php echo $h($t('ยอด / ต้นทุน', 'Total / cost')); ?></th><th class="p-3 text-center"><?php echo $h($t('คีย์', 'Keys')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ / เวลา', 'Status / time')); ?></th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php foreach ($orders as $order): ?>
                    <tr class="hover:bg-white/[.025]">
                        <td class="p-3"><div class="font-mono text-xs break-all"><?php echo $h($order['order_uuid']); ?></div><div class="mt-1"><span class="badge bg-violet-500/15 text-violet-200"><?php echo $h($sourceLabels[$order['source_type']] ?? $order['source_type']); ?></span></div><div class="text-xs text-gray-500 mt-1"><?php echo $h($order['source_site_id'] . ' #' . $order['source_record_id']); ?></div></td>
                        <td class="p-3"><div class="font-semibold"><?php echo $h($order['buyer_name_snapshot'] !== '' ? $order['buyer_name_snapshot'] : '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($order['customer_ref'] ?: ($order['origin_site_id'] . ':' . $order['origin_user_id'])); ?></div><div class="text-xs text-gray-500"><?php echo $h($order['buyer_email_snapshot']); ?></div></td>
                        <td class="p-3"><div class="font-semibold"><?php echo $h($order['product_name_snapshot'] ?: '-'); ?></div><div class="text-xs text-gray-500"><?php echo $h($order['duration_snapshot']); ?> × <?php echo (int) ($order['quantity'] ?? 0); ?></div></td>
                        <td class="p-3 text-right"><div class="font-bold text-emerald-300"><?php echo $h(number_format((float) $order['total'], 2) . ' ' . $order['currency']); ?></div><div class="text-xs text-gray-500">cost <?php echo $h(number_format((float) $order['cost_total'], 2)); ?></div></td>
                        <td class="p-3 text-center"><span class="badge bg-white/10"><?php echo (int) $order['delivery_count']; ?>/<?php echo (int) ($order['quantity'] ?? 0); ?></span></td>
                        <td class="p-3"><span class="badge <?php echo in_array(strtolower((string) $order['status']), ['success','completed'], true) ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'; ?>"><?php echo $h($order['status']); ?></span><div class="text-xs text-gray-500 mt-2"><?php echo $h($order['source_completed_at'] ?: $order['source_created_at']); ?></div><div class="text-xs text-gray-600">sync <?php echo $h($order['last_synced_at']); ?></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($orders === []): ?><tr><td colspan="6" class="p-8 text-center text-gray-500"><?php echo $h($t('ยังไม่มีข้อมูล หรือยังไม่ได้รัน Backfill', 'No data yet, or backfill has not run.')); ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($failures !== []): ?>
    <section class="glass rounded-xl p-5 border border-red-500/20">
        <h2 class="font-bold text-lg text-red-200"><i class="bi bi-exclamation-octagon mr-2"></i><?php echo $h($t('รายการซิงก์ที่ยังแก้ไม่สำเร็จ', 'Unresolved sync failures')); ?></h2>
        <div class="overflow-auto mt-4"><table class="w-full min-w-[800px] text-sm"><thead><tr class="text-gray-400"><th class="p-2 text-left">Source</th><th class="p-2 text-left">Record</th><th class="p-2 text-left">Error</th><th class="p-2 text-right">Attempts</th><th class="p-2 text-left">Last failure</th></tr></thead><tbody class="divide-y divide-white/5"><?php foreach ($failures as $failure): ?><tr><td class="p-2"><?php echo $h($failure['source_type']); ?></td><td class="p-2">#<?php echo $h($failure['source_record_id']); ?></td><td class="p-2 text-red-200"><?php echo $h($failure['error_message']); ?></td><td class="p-2 text-right"><?php echo (int) $failure['attempts']; ?></td><td class="p-2 text-gray-400"><?php echo $h($failure['last_failed_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
