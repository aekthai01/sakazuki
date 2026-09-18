<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commerce_consistency.php';
requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string { return $isThai ? $th : $en; };
$e = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$maintenanceResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'initialize_optional_tables') {
    requireCsrfToken('commerce_consistency.php');
    $maintenanceResult = [
        'purchase_activity_events' => function_exists('purchaseActivityEnsureEventTable')
            ? purchaseActivityEnsureEventTable()
            : false,
        'transaction_repair_tables' => function_exists('transactionIntegrityEnsureRepairTables')
            ? transactionIntegrityEnsureRepairTables()
            : false,
        'cgo_schema' => function_exists('cgoEnsureTables') ? cgoEnsureTables() : false,
        'supplier_schema' => function_exists('storeBridgeEnsureSchema') ? storeBridgeEnsureSchema() : false,
    ];
}

$sampleInput = isset($_GET['sample']) && is_scalar($_GET['sample']) ? (int) $_GET['sample'] : 50;
$sampleLimit = in_array($sampleInput, [25, 50, 100], true) ? $sampleInput : 50;
$report = commerceConsistencyRun($sampleLimit);
$reportJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($reportJson)) $reportJson = '{}';
$summary = (array) ($report['summary'] ?? []);
$healthy = !empty($report['healthy']);
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('ตรวจความสอดคล้องข้อมูลการขาย', 'Commerce Consistency Check')); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#8b5cf6;--sakazuki-accent-rgb:139 92 246}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono-wrap{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere}
        details>summary::-webkit-details-marker{display:none}
    </style>
</head>
<body class="min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
    <section class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-white"><i class="bi bi-database-check text-cyan-300"></i><?php echo $e($t('ตรวจความสอดคล้องข้อมูลการขาย', 'Commerce Consistency Check')); ?></h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-400"><?php echo $e($t('ตรวจแบบอ่านอย่างเดียว ไม่แก้ไข ไม่ลบ และไม่ย้ายข้อมูล ใช้ค้นหาจุดที่ Order, Transaction, คีย์, เจ้าของ และ Purchase Event เชื่อมกันไม่ครบ', 'Read-only inspection. It does not alter, delete, or move data. It finds incomplete links between orders, transactions, keys, owners, and purchase events.')); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" class="inline" onsubmit="return confirm('<?php echo $e($t('สร้างเฉพาะตารางและดัชนีเสริมที่ขาด โดยไม่แก้ยอดเงินหรือคำสั่งซื้อ ใช่หรือไม่?', 'Create only missing optional tables/indexes without changing balances or orders?')); ?>')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="initialize_optional_tables">
                <button type="submit" class="rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-400/20"><i class="bi bi-database-add mr-1"></i><?php echo $e($t('สร้างโครงสร้างเสริมที่ขาด', 'Initialize optional schema')); ?></button>
            </form>
            <a href="commerce_consistency.php?sample=<?php echo $sampleLimit; ?>" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-600"><i class="bi bi-arrow-clockwise mr-1"></i><?php echo $e($t('ตรวจใหม่', 'Run again')); ?></a>
            <button id="copyReport" type="button" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium hover:bg-white/15"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอกรายงาน', 'Copy report')); ?></button>
        </div>
    </section>

    <?php if (is_array($maintenanceResult)): ?>
        <?php $maintenanceOk = !in_array(false, $maintenanceResult, true); ?>
        <section class="rounded-xl border <?php echo $maintenanceOk ? 'border-green-500/30 bg-green-500/10 text-green-200' : 'border-amber-500/30 bg-amber-500/10 text-amber-100'; ?> p-4 text-sm">
            <div class="font-bold"><i class="bi <?php echo $maintenanceOk ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?> mr-1"></i><?php echo $e($maintenanceOk ? $t('ตรวจและสร้างโครงสร้างเสริมเรียบร้อย', 'Optional schema initialized') : $t('บางโครงสร้างยังสร้างไม่ได้', 'Some optional schema could not be initialized')); ?></div>
            <div class="mt-2 font-mono text-xs"><?php echo $e(json_encode($maintenanceResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></div>
        </section>
    <?php endif; ?>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <?php
        $cards = [
            [$t('สถานะรวม', 'Overall'), $healthy ? $t('ปกติ', 'Healthy') : $t('ควรตรวจ', 'Review'), $healthy ? 'text-green-300' : 'text-orange-300'],
            [$t('ปัญหาระดับสูง', 'Errors'), (int) ($summary['errors'] ?? 0), 'text-red-300'],
            [$t('คำเตือน', 'Warnings'), (int) ($summary['warnings'] ?? 0), 'text-orange-300'],
            [$t('Query Error', 'Query errors'), (int) ($summary['query_errors'] ?? 0), 'text-fuchsia-300'],
            [$t('Checks ที่รัน', 'Checks run'), (int) ($summary['checks_run'] ?? 0), 'text-cyan-300'],
            [$t('Checks ที่ข้าม', 'Skipped'), (int) ($summary['skipped'] ?? 0), 'text-gray-300'],
        ];
        foreach ($cards as $card): ?>
            <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($card[0]); ?></div><div class="mt-2 text-xl font-bold <?php echo $card[2]; ?>"><?php echo $e($card[1]); ?></div></div>
        <?php endforeach; ?>
    </section>

    <section class="glass rounded-xl p-4">
        <div class="grid gap-3 text-sm md:grid-cols-3">
            <div><div class="text-xs text-gray-500"><?php echo $e($t('ฐานข้อมูล', 'Database')); ?></div><div class="mono-wrap mt-1 text-white"><?php echo $e($report['database_name'] ?? '-'); ?></div></div>
            <div><div class="text-xs text-gray-500"><?php echo $e($t('เซิร์ฟเวอร์', 'Server')); ?></div><div class="mono-wrap mt-1 text-white"><?php echo $e($report['server_version'] ?? '-'); ?></div></div>
            <div><div class="text-xs text-gray-500"><?php echo $e($t('เวลาตรวจ', 'Checked at')); ?></div><div class="mono-wrap mt-1 text-white"><?php echo $e($report['checked_at'] ?? '-'); ?></div></div>
        </div>
        <form method="get" class="mt-4 flex flex-wrap items-end gap-2 border-t border-white/10 pt-4">
            <label class="text-sm text-gray-400"><span class="mb-1 block text-xs"><?php echo $e($t('จำนวนตัวอย่างต่อ Check', 'Sample rows per check')); ?></span><select name="sample" class="rounded-lg border border-white/10 bg-panel px-3 py-2 text-white"><?php foreach ([25,50,100] as $option): ?><option value="<?php echo $option; ?>" <?php echo $sampleLimit === $option ? 'selected' : ''; ?>><?php echo $option; ?></option><?php endforeach; ?></select></label>
            <button class="rounded-lg bg-violet-500 px-4 py-2 text-sm font-medium text-white hover:bg-violet-600"><?php echo $e($t('ใช้ค่าและตรวจใหม่', 'Apply and rerun')); ?></button>
            <span class="text-xs text-gray-500"><?php echo $e($t('จำนวนนี้จำกัดเฉพาะตัวอย่างที่แสดง ไม่จำกัดจำนวนที่นับจริง', 'This limits displayed samples only, not the actual count.')); ?></span>
        </form>
    </section>

    <section class="space-y-3">
        <?php foreach ((array) ($report['checks'] ?? []) as $check): ?>
            <?php
            $status = (string) ($check['status'] ?? 'ok');
            $severity = (string) ($check['severity'] ?? 'warning');
            $count = (int) ($check['count'] ?? 0);
            if ($status === 'query_error') {
                $border = 'border-fuchsia-500/40'; $badge = 'bg-fuchsia-500/15 text-fuchsia-200'; $icon = 'bi-bug';
            } elseif ($status === 'skipped') {
                $border = 'border-white/10'; $badge = 'bg-white/10 text-gray-300'; $icon = 'bi-skip-forward';
            } elseif ($count < 1) {
                $border = 'border-green-500/25'; $badge = 'bg-green-500/10 text-green-300'; $icon = 'bi-check-circle';
            } elseif ($severity === 'error') {
                $border = 'border-red-500/35'; $badge = 'bg-red-500/15 text-red-200'; $icon = 'bi-exclamation-octagon';
            } else {
                $border = 'border-orange-500/35'; $badge = 'bg-orange-500/15 text-orange-200'; $icon = 'bi-exclamation-triangle';
            }
            $title = $isThai ? (string) ($check['title_th'] ?? '') : (string) ($check['title_en'] ?? '');
            $help = $isThai ? (string) ($check['help_th'] ?? '') : (string) ($check['help_en'] ?? '');
            $open = $status === 'query_error' || $count > 0;
            ?>
            <details class="glass overflow-hidden rounded-xl border <?php echo $border; ?>" <?php echo $open ? 'open' : ''; ?>>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 hover:bg-white/[.03]">
                    <div class="min-w-0"><div class="flex items-center gap-2 font-medium text-white"><i class="bi <?php echo $icon; ?>"></i><span><?php echo $e($title); ?></span></div><div class="mono-wrap mt-1 text-xs text-gray-500"><?php echo $e($check['code'] ?? ''); ?></div></div>
                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-medium <?php echo $badge; ?>"><?php echo $status === 'skipped' ? $e($t('ข้าม', 'Skipped')) : ($status === 'query_error' ? $e($t('Query Error', 'Query error')) : number_format($count)); ?></span>
                </summary>
                <div class="border-t border-white/10 p-4">
                    <?php if ($help !== ''): ?><p class="text-sm text-gray-400"><?php echo $e($help); ?></p><?php endif; ?>
                    <?php if ($status === 'query_error' || $status === 'skipped'): ?>
                        <div class="mono-wrap mt-3 rounded-lg bg-black/30 p-3 text-xs text-fuchsia-200">errno=<?php echo (int) ($check['db_errno'] ?? 0); ?> · <?php echo $e($check['error'] ?? '-'); ?></div>
                    <?php elseif ($count < 1): ?>
                        <div class="mt-3 text-sm text-green-300"><i class="bi bi-check2-circle mr-1"></i><?php echo $e($t('ไม่พบข้อมูลผิดปกติใน Check นี้', 'No inconsistency was found by this check.')); ?></div>
                    <?php else: ?>
                        <div class="mt-3 flex items-center justify-between gap-2"><div class="text-xs text-gray-500"><?php echo $e($t('แสดงตัวอย่างสูงสุด ', 'Showing up to ') . $sampleLimit . $t(' รายการ', ' rows')); ?></div><button type="button" class="copy-check rounded-md bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15" data-check-code="<?php echo $e($check['code'] ?? ''); ?>"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอกส่วนนี้', 'Copy this check')); ?></button></div>
                        <div class="mt-3 space-y-2">
                            <?php foreach ((array) ($check['rows'] ?? []) as $index => $row): ?>
                                <?php $rowJson = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); if (!is_string($rowJson)) $rowJson = '{}'; ?>
                                <pre class="mono-wrap max-h-72 overflow-auto whitespace-pre-wrap rounded-lg border border-white/10 bg-black/30 p-3 text-xs text-gray-300"><?php echo $e($rowJson); ?></pre>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </section>

    <details class="glass rounded-xl">
        <summary class="cursor-pointer list-none p-4 font-medium text-white"><i class="bi bi-table mr-2 text-violet-300"></i><?php echo $e($t('สถานะตารางและรายงาน JSON เต็ม', 'Table status and full JSON report')); ?></summary>
        <div class="border-t border-white/10 p-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ((array) ($report['tables'] ?? []) as $table => $info): ?>
                    <div class="rounded-lg bg-black/25 p-3"><div class="mono-wrap text-xs text-white"><?php echo $e($table); ?></div><div class="mt-1 text-xs <?php echo !empty($info['exists']) ? 'text-green-300' : 'text-gray-500'; ?>"><?php echo !empty($info['exists']) ? $e((int) ($info['columns'] ?? 0) . $t(' คอลัมน์', ' columns')) : $e($t('ไม่พบ/อ่านไม่ได้', 'Missing/unreadable')); ?></div></div>
                <?php endforeach; ?>
            </div>
            <pre id="fullReport" class="mono-wrap mt-4 max-h-[38rem] overflow-auto whitespace-pre-wrap rounded-lg bg-black/35 p-4 text-xs text-gray-300"><?php echo $e($reportJson); ?></pre>
        </div>
    </details>

    <section class="glass rounded-xl p-4 text-sm text-gray-400">
        <div class="font-medium text-white"><i class="bi bi-shield-check mr-2 text-green-300"></i><?php echo $e($t('ขอบเขตความปลอดภัย', 'Safety boundary')); ?></div>
        <p class="mt-2"><?php echo $e($t('การรันรายงานใช้เฉพาะ SELECT และ SHOW COLUMNS ไม่แก้ยอดเงิน คำสั่งซื้อ หรือคีย์ ปุ่มสร้างโครงสร้างเสริมเป็นคำสั่งแยกที่มี CSRF และสร้างเฉพาะตาราง/ดัชนีที่ขาด โดยไม่ซ่อมหรือลบข้อมูลธุรกรรมอัตโนมัติ', 'Running the report uses only SELECT and SHOW COLUMNS and does not change balances, orders, or keys. The optional schema button is a separate CSRF-protected action that creates only missing tables/indexes and never auto-repairs or deletes transaction data.')); ?></p>
        <div class="mt-3 flex flex-wrap gap-2"><a href="transactions.php" class="rounded-lg bg-white/10 px-3 py-2 hover:bg-white/15"><i class="bi bi-wallet2 mr-1"></i><?php echo $e($t('เปิด Transactions', 'Open Transactions')); ?></a><a href="transaction_integrity.php" class="rounded-lg bg-amber-500/15 px-3 py-2 text-amber-200 hover:bg-amber-500/25"><i class="bi bi-wrench-adjustable-circle mr-1"></i><?php echo $e($t('ซ่อมชนิด Transaction', 'Transaction Repair')); ?></a><a href="api_hub.php" class="rounded-lg bg-white/10 px-3 py-2 hover:bg-white/15"><i class="bi bi-diagram-3 mr-1"></i><?php echo $e($t('เปิด API Hub', 'Open API Hub')); ?></a><a href="key_resets.php" class="rounded-lg bg-white/10 px-3 py-2 hover:bg-white/15"><i class="bi bi-arrow-counterclockwise mr-1"></i><?php echo $e($t('เปิด Reset Logs', 'Open Reset Logs')); ?></a></div>
    </section>
</main>
<script>
const consistencyReport = <?php echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
async function copyText(text) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch (_) {}
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    let copied = false;
    try { copied = document.execCommand('copy'); } catch (_) {}
    textarea.remove();
    return copied;
}
function showCopyResult(button, copied) {
    const original = button.innerHTML;
    button.innerHTML = copied ? '<i class="bi bi-check2 mr-1"></i><?php echo $e($t('คัดลอกแล้ว', 'Copied')); ?>' : '<i class="bi bi-x-lg mr-1"></i><?php echo $e($t('คัดลอกไม่สำเร็จ', 'Copy failed')); ?>';
    setTimeout(() => { button.innerHTML = original; }, 1800);
}
document.getElementById('copyReport')?.addEventListener('click', async function () {
    showCopyResult(this, await copyText(JSON.stringify(consistencyReport, null, 2)));
});
document.querySelectorAll('.copy-check').forEach((button) => {
    button.addEventListener('click', async function () {
        const code = this.dataset.checkCode || '';
        const check = (consistencyReport.checks || []).find((item) => item.code === code) || {};
        showCopyResult(this, await copyText(JSON.stringify(check, null, 2)));
    });
});
</script>
</body>
</html>
