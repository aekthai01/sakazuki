<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/transaction_integrity.php';
requireAdmin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string { return $isThai ? $th : $en; };
$e = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

$preview = transactionIntegrityPreview(100);
$_SESSION['transaction_integrity_preview_hash'] = (string) ($preview['preview_hash'] ?? '');
$column = (array) ($preview['column'] ?? []);
$activeOrders = (array) ($preview['recent_active_orders'] ?? []);
$repairable = (array) ($preview['repairable_by_type'] ?? []);
$previewJson = json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($previewJson)) $previewJson = '{}';

$batches = [];
$debugLogs = [];
try {
    if (transactionIntegrityEnsureRepairTables()) {
        $result = $conn->query('SELECT batch_id,admin_user_id,candidate_count,repaired_count,status,error_message,created_at,completed_at FROM transaction_type_repair_batches ORDER BY id DESC LIMIT 10');
        if ($result) {
            $batches = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
    }
    if (transactionIntegrityEnsureDebugTable()) {
        $result = $conn->query('SELECT request_id,batch_id,action,stage,level,code,message,db_errno,duration_ms,context_json,created_at FROM transaction_integrity_debug_logs ORDER BY id DESC LIMIT 20');
        if ($result) {
            $debugLogs = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
    }
} catch (Throwable $error) {
    error_log('Transaction integrity page history read failed: ' . $error->getMessage());
}

$canApply = empty($preview['query_error'])
    && ((int) ($activeOrders['count'] ?? 0) === 0)
    && (!empty($preview['repairable_count']) || empty($preview['schema_ready']));
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('ซ่อมชนิด Transaction', 'Transaction Type Repair')); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#8b5cf6;--sakazuki-accent-rgb:139 92 246}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere}
        details>summary::-webkit-details-marker{display:none}
        body.transaction-integrity-ready #site-loader{display:none!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}
        .repair-progress-bar{transition:width .15s cubic-bezier(.22,1,.36,1)}
        .repair-status-enter{animation:repair-status-enter .15s cubic-bezier(.22,1,.36,1) both}
        @keyframes repair-status-enter{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
    </style>
</head>
<body class="min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
    <section class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><i class="bi bi-wrench-adjustable-circle text-amber-300"></i><?php echo $e($t('ซ่อมชนิด Transaction', 'Transaction Type Repair')); ?></h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-400"><?php echo $e($t('ระบบใหม่ทำงานเป็นขั้นตอน ไม่เปลี่ยนหน้าและไม่ใช้ Loader เต็มจอ หากขั้นใดผิดพลาดจะมี Batch, Ref และ Debug ให้คัดลอกทันที', 'The repair now runs in bounded stages without navigating away or using a full-page loader. Any failure returns a batch ID, request reference, and copyable diagnostics.')); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="transaction_integrity.php" data-no-page-loader class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium hover:bg-cyan-600"><i class="bi bi-arrow-clockwise mr-1"></i><?php echo $e($t('ตรวจใหม่', 'Refresh preview')); ?></a>
            <button id="copyPreview" type="button" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอก Preview', 'Copy preview')); ?></button>
        </div>
    </section>

    <section id="repairStatusPanel" class="hidden repair-status-enter rounded-xl border border-cyan-500/30 bg-cyan-500/8 p-4" aria-live="polite">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2 font-medium text-white"><span id="repairStatusIcon" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-cyan-500/15 text-cyan-300"><i class="bi bi-arrow-repeat"></i></span><span id="repairStatusTitle"><?php echo $e($t('กำลังเตรียมการซ่อม', 'Preparing repair')); ?></span></div>
                <div id="repairStatusDetail" class="mt-2 text-sm text-gray-300"></div>
                <div class="mono mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500"><span>Batch: <b id="repairBatchId" class="text-gray-300">-</b></span><span>Ref: <b id="repairRequestId" class="text-gray-300">-</b></span><span><?php echo $e($t('เวลา', 'Elapsed')); ?>: <b id="repairElapsed" class="text-gray-300">0s</b></span></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="copyRepairDebug" type="button" class="rounded-lg bg-white/10 px-3 py-2 text-xs hover:bg-white/15"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอกดีบัก', 'Copy debug')); ?></button>
                <button id="closeRepairStatus" type="button" class="rounded-lg bg-white/10 px-3 py-2 text-xs hover:bg-white/15"><i class="bi bi-x-lg mr-1"></i><?php echo $e($t('ปิด', 'Close')); ?></button>
            </div>
        </div>
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-black/35"><div id="repairProgressBar" class="repair-progress-bar h-full w-0 rounded-full bg-cyan-400"></div></div>
        <div class="mt-2 flex justify-between text-xs text-gray-500"><span id="repairProgressText">0 / 0</span><span id="repairStageCode">idle</span></div>
        <pre id="repairDebugPreview" class="mono mt-4 hidden max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-black/30 p-3 text-xs text-gray-400"></pre>
    </section>

    <?php if (!empty($preview['query_error'])): ?>
        <section class="rounded-xl border border-red-500/35 bg-red-500/10 p-4 text-sm text-red-200"><i class="bi bi-exclamation-octagon mr-2"></i><?php echo $e($t('อ่าน Preview ไม่สำเร็จ: ', 'Preview failed: ') . $preview['query_error']); ?></section>
    <?php endif; ?>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('ชนิดคอลัมน์ปัจจุบัน', 'Current column type')); ?></div><div class="mono mt-2 text-sm font-bold text-white"><?php echo $e($column['Type'] ?? 'missing'); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('รองรับชนิดใหม่', 'Schema support')); ?></div><div class="mt-2 text-xl font-bold <?php echo !empty($preview['schema_ready']) ? 'text-green-300' : 'text-red-300'; ?>"><?php echo !empty($preview['schema_ready']) ? $e($t('พร้อม', 'Ready')) : $e($t('ต้องแก้', 'Migration required')); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('ประเภทว่าง', 'Blank types')); ?></div><div class="mt-2 text-xl font-bold text-orange-300"><?php echo number_format((int) ($preview['blank_type_count'] ?? 0)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('ซ่อมได้จากหลักฐาน', 'Strongly repairable')); ?></div><div class="mt-2 text-xl font-bold text-cyan-300"><?php echo number_format((int) ($preview['repairable_count'] ?? 0)); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('Order กำลังทำงาน 5 นาทีล่าสุด', 'Active orders, last 5 min')); ?></div><div class="mt-2 text-xl font-bold <?php echo (int) ($activeOrders['count'] ?? 0) > 0 ? 'text-red-300' : 'text-green-300'; ?>"><?php echo number_format((int) ($activeOrders['count'] ?? 0)); ?></div></div>
    </section>

    <section class="glass rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-medium text-white"><i class="bi bi-diagram-3 mr-2 text-cyan-300"></i><?php echo $e($t('รายการที่จะซ่อมตามหลักฐาน', 'Repair candidates by evidence')); ?></h2><p class="mt-1 text-xs text-gray-500"><?php echo $e($t('ซ่อมทีละชุดย่อยสูงสุด 40 รายการ แต่ละชุดมี Backup ก่อน UPDATE', 'Rows are repaired in bounded chunks of up to 40, with a backup before every update.')); ?></p></div><span class="mono text-xs text-gray-500"><?php echo $e($preview['checked_at'] ?? ''); ?></span></div>
        <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($repairable as $type => $count): ?><div class="rounded-lg bg-black/25 p-3"><div class="mono text-xs text-cyan-300"><?php echo $e($type); ?></div><div class="mt-1 text-lg font-bold"><?php echo number_format((int) $count); ?></div></div><?php endforeach; ?>
            <?php if (!$repairable): ?><div class="text-sm text-gray-500"><?php echo $e($t('ไม่พบรายการที่ต้องเปลี่ยน', 'No repair candidates found.')); ?></div><?php endif; ?>
        </div>
    </section>

    <details class="glass rounded-xl" open>
        <summary class="cursor-pointer list-none p-4 font-medium"><i class="bi bi-list-check mr-2 text-violet-300"></i><?php echo $e($t('ตัวอย่างรายการที่จะเปลี่ยน', 'Sample rows to change')); ?> <span class="ml-2 text-xs text-gray-500"><?php echo number_format(count((array) ($preview['samples'] ?? []))); ?></span></summary>
        <div class="border-t border-white/10 p-4"><div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-xs"><thead class="text-gray-500"><tr><th class="px-2 py-2">TX</th><th class="px-2 py-2"><?php echo $e($t('เดิม', 'Old')); ?></th><th class="px-2 py-2"><?php echo $e($t('ใหม่', 'New')); ?></th><th class="px-2 py-2"><?php echo $e($t('รายละเอียด', 'Description')); ?></th><th class="px-2 py-2"><?php echo $e($t('เวลา', 'Time')); ?></th></tr></thead><tbody class="divide-y divide-white/5"><?php foreach ((array) ($preview['samples'] ?? []) as $row): ?><tr><td class="mono px-2 py-2 text-gray-300">#<?php echo (int) ($row['id'] ?? 0); ?></td><td class="mono px-2 py-2 text-orange-300"><?php echo $e(($row['old_type'] ?? '') !== '' ? $row['old_type'] : '(blank)'); ?></td><td class="mono px-2 py-2 text-green-300"><?php echo $e($row['inferred_type'] ?? ''); ?></td><td class="max-w-xl px-2 py-2 text-gray-400"><?php echo $e($row['description'] ?? ''); ?></td><td class="mono px-2 py-2 text-gray-500"><?php echo $e($row['created_at'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </details>

    <section class="rounded-xl border <?php echo $canApply ? 'border-amber-500/35 bg-amber-500/8' : 'border-white/10 bg-white/[.03]'; ?> p-4">
        <h2 class="font-medium text-white"><i class="bi bi-shield-lock mr-2 text-amber-300"></i><?php echo $e($t('ใช้การซ่อมแบบมี Backup', 'Apply repair with backup')); ?></h2>
        <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-400"><li><?php echo $e($t('สร้าง Batch และ Debug Ref ก่อนเริ่มงานหนัก', 'Create a batch and diagnostic reference before heavy work.')); ?></li><li><?php echo $e($t('จำกัดการรอ Metadata Lock ไม่เกินประมาณ 5 วินาที', 'Bound metadata-lock waiting to approximately five seconds.')); ?></li><li><?php echo $e($t('ซ่อมข้อมูลทีละชุดและรายงานความคืบหน้า', 'Repair rows in chunks and report progress.')); ?></li><li><?php echo $e($t('ไม่มี Loader เต็มหน้าจอ และ Error จะไม่หายเอง', 'No full-page loader; errors remain visible.')); ?></li></ol>
        <?php if ((int) ($activeOrders['count'] ?? 0) > 0): ?><div class="mt-3 rounded-lg bg-red-500/10 p-3 text-sm text-red-200"><?php echo $e($t('ปุ่มถูกปิดเพราะมี Order กำลังทำงาน กรุณารออย่างน้อย 5 นาทีหลัง Order ล่าสุด', 'The button is disabled because an order is active. Wait at least five minutes after the last active order.')); ?></div><?php endif; ?>
        <form method="post" action="transaction_integrity_action.php" data-no-page-loader class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" id="repairForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="preview_hash" value="<?php echo $e($preview['preview_hash'] ?? ''); ?>">
            <label class="flex-1 text-sm text-gray-400"><span class="mb-1 block text-xs"><?php echo $e($t('พิมพ์ REPAIR เพื่อยืนยัน', 'Type REPAIR to confirm')); ?></span><input name="confirmation" autocomplete="off" class="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-white" placeholder="REPAIR" <?php echo $canApply ? '' : 'disabled'; ?>></label>
            <button id="repairSubmit" type="submit" class="rounded-lg px-5 py-2.5 text-sm font-medium <?php echo $canApply ? 'bg-amber-500 text-black hover:bg-amber-400' : 'cursor-not-allowed bg-white/10 text-gray-600'; ?>" <?php echo $canApply ? '' : 'disabled'; ?>><i class="bi bi-database-gear mr-1"></i><span><?php echo $e($t('Backup และซ่อม', 'Back up and repair')); ?></span></button>
        </form>
    </section>

    <?php if ($batches): ?><details class="glass rounded-xl"><summary class="cursor-pointer list-none p-4 font-medium"><i class="bi bi-clock-history mr-2 text-gray-300"></i><?php echo $e($t('ประวัติการซ่อม 10 รอบล่าสุด', 'Last 10 repair batches')); ?></summary><div class="border-t border-white/10 p-4"><div class="space-y-2"><?php foreach ($batches as $batch): ?><pre class="mono whitespace-pre-wrap rounded-lg bg-black/30 p-3 text-xs text-gray-300"><?php echo $e(json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre><?php endforeach; ?></div></div></details><?php endif; ?>

    <details class="glass rounded-xl" id="integrityDebugLogs">
        <summary class="cursor-pointer list-none p-4 font-medium"><i class="bi bi-bug mr-2 text-orange-300"></i><?php echo $e($t('Logs และ Debug ล่าสุด', 'Recent logs and diagnostics')); ?> <span class="ml-2 text-xs text-gray-500"><?php echo number_format(count($debugLogs)); ?></span></summary>
        <div class="border-t border-white/10 p-4">
            <?php if (!$debugLogs): ?><div class="text-sm text-gray-500"><?php echo $e($t('ยังไม่มี Debug Log ระบบจะสร้างเมื่อมีการกดซ่อม', 'No diagnostic logs yet. They are created when a repair action runs.')); ?></div><?php else: ?><div class="space-y-2"><?php foreach ($debugLogs as $log): ?><div class="rounded-lg bg-black/30 p-3"><div class="flex flex-wrap justify-between gap-2 text-xs"><span class="mono text-gray-300"><?php echo $e(($log['action'] ?? '-') . ' / ' . ($log['stage'] ?? '-') . ' / ' . ($log['code'] ?? '-')); ?></span><span class="text-gray-600"><?php echo $e($log['created_at'] ?? ''); ?></span></div><pre class="mono mt-2 whitespace-pre-wrap text-xs text-gray-500"><?php echo $e(json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></div><?php endforeach; ?></div><?php endif; ?>
        </div>
    </details>

    <section class="glass rounded-xl p-4 text-sm text-gray-400"><div class="font-medium text-white"><i class="bi bi-info-circle mr-2 text-cyan-300"></i><?php echo $e($t('สิ่งที่หน้านี้ไม่ทำ', 'What this page does not do')); ?></div><p class="mt-2"><?php echo $e($t('ไม่ลบข้อมูลบัญชี CGO ที่ถูกแยกหลายบรรทัด ไม่สร้างคีย์ Local ที่ถูกลบกลับมา และไม่ปรับยอดเงิน', 'It does not delete fragmented CGO account fields, recreate deleted local keys, or modify balances.')); ?></p></section>

    <details class="glass rounded-xl"><summary class="cursor-pointer list-none p-4 font-medium"><i class="bi bi-code-square mr-2 text-gray-300"></i><?php echo $e($t('Preview JSON', 'Preview JSON')); ?></summary><pre id="previewJson" class="mono max-h-[36rem] overflow-auto whitespace-pre-wrap border-t border-white/10 p-4 text-xs text-gray-300"><?php echo $e($previewJson); ?></pre></details>
</main>
<script>
const TI_TEXT = <?php echo json_encode([
    'confirm' => $t('กรุณาพิมพ์ REPAIR ให้ถูกต้อง', 'Type REPAIR exactly to continue.'),
    'initializing' => $t('กำลังสร้าง Batch และตรวจข้อมูลล่าสุด', 'Creating a batch and validating the latest data'),
    'migrating' => $t('กำลังแก้ Schema โดยจำกัดเวลารอ Lock', 'Migrating the schema with bounded lock waiting'),
    'repairing' => $t('กำลัง Backup และซ่อม Transaction ทีละชุด', 'Backing up and repairing transactions in chunks'),
    'completed' => $t('ซ่อมสำเร็จแล้ว กด “ตรวจใหม่” เพื่อยืนยันผล', 'Repair completed. Refresh the preview to verify the result.'),
    'failed' => $t('ซ่อมไม่สำเร็จ ข้อความนี้จะไม่หายเอง กรุณาคัดลอก Debug', 'Repair failed. This message will remain visible; copy the diagnostics.'),
    'timeout' => $t('คำขอใช้เวลานานเกินกำหนด ระบบหยุดรอฝั่งหน้าจอและตรวจสถานะ Batch แทน', 'The request exceeded the UI timeout; the page stopped waiting and checked batch status instead.'),
    'copied' => $t('คัดลอกแล้ว', 'Copied'),
    'copyFailed' => $t('คัดลอกไม่สำเร็จ', 'Copy failed'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function releaseTransactionIntegrityBlockingUi(reason='') {
    document.body?.classList.add('transaction-integrity-ready');
    const loader = document.getElementById('site-loader');
    if (loader) {
        loader.style.setProperty('display','none','important');
        loader.style.setProperty('opacity','0','important');
        loader.style.setProperty('visibility','hidden','important');
        loader.style.setProperty('pointer-events','none','important');
        loader.style.setProperty('backdrop-filter','none','important');
        loader.style.setProperty('-webkit-backdrop-filter','none','important');
        loader.remove();
    }
    document.getElementById('drawerOverlay')?.classList.remove('open');
}
['DOMContentLoaded','load','pageshow'].forEach(name=>window.addEventListener(name,()=>releaseTransactionIntegrityBlockingUi(name),{once:name!=='pageshow'}));
releaseTransactionIntegrityBlockingUi('script-ready');
setTimeout(()=>releaseTransactionIntegrityBlockingUi('fallback'),800);
new MutationObserver(()=>{if(document.body?.classList.contains('transaction-integrity-ready')&&document.getElementById('site-loader'))releaseTransactionIntegrityBlockingUi('mutation');}).observe(document.documentElement,{childList:true,subtree:true});

async function copyText(text){try{if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(text);return true;}}catch(_){}const t=document.createElement('textarea');t.value=String(text||'');t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.select();let ok=false;try{ok=document.execCommand('copy');}catch(_){}t.remove();return ok;}
document.getElementById('copyPreview')?.addEventListener('click',async function(){const old=this.innerHTML;const ok=await copyText(document.getElementById('previewJson')?.textContent||'');this.innerHTML=ok?'<i class="bi bi-check2 mr-1"></i>'+TI_TEXT.copied:'<i class="bi bi-x-lg mr-1"></i>'+TI_TEXT.copyFailed;setTimeout(()=>this.innerHTML=old,1600);});

const repairForm=document.getElementById('repairForm');
const repairPanel=document.getElementById('repairStatusPanel');
const repairTitle=document.getElementById('repairStatusTitle');
const repairDetail=document.getElementById('repairStatusDetail');
const repairIcon=document.getElementById('repairStatusIcon');
const repairBar=document.getElementById('repairProgressBar');
const repairProgressText=document.getElementById('repairProgressText');
const repairStageCode=document.getElementById('repairStageCode');
const repairBatchId=document.getElementById('repairBatchId');
const repairRequestId=document.getElementById('repairRequestId');
const repairElapsed=document.getElementById('repairElapsed');
const repairDebugPreview=document.getElementById('repairDebugPreview');
const repairSubmit=document.getElementById('repairSubmit');
let repairDebug=[];
let repairStartedAt=0;
let elapsedTimer=0;
let repairRunning=false;

function createBatchId(){if(window.crypto?.randomUUID)return crypto.randomUUID().toLowerCase();const p=()=>Math.floor(Math.random()*0x10000).toString(16).padStart(4,'0');return `${p()}${p()}-${p()}-4${p().slice(1)}-${(8+Math.floor(Math.random()*4)).toString(16)}${p().slice(1)}-${p()}${p()}${p()}`;}
function addDebug(stage,data={}){const item={at:new Date().toISOString(),stage,...data};repairDebug.push(item);repairDebugPreview.textContent=JSON.stringify(repairDebug,null,2);return item;}
function setStatus(kind,title,detail,stage=''){repairPanel.classList.remove('hidden','border-red-500/35','bg-red-500/10','border-green-500/35','bg-green-500/10','border-cyan-500/30','bg-cyan-500/8');repairPanel.classList.add('repair-status-enter');if(kind==='error'){repairPanel.classList.add('border-red-500/35','bg-red-500/10');repairIcon.className='inline-flex h-7 w-7 items-center justify-center rounded-full bg-red-500/15 text-red-300';repairIcon.innerHTML='<i class="bi bi-exclamation-octagon"></i>';}else if(kind==='success'){repairPanel.classList.add('border-green-500/35','bg-green-500/10');repairIcon.className='inline-flex h-7 w-7 items-center justify-center rounded-full bg-green-500/15 text-green-300';repairIcon.innerHTML='<i class="bi bi-check2-circle"></i>';}else{repairPanel.classList.add('border-cyan-500/30','bg-cyan-500/8');repairIcon.className='inline-flex h-7 w-7 items-center justify-center rounded-full bg-cyan-500/15 text-cyan-300';repairIcon.innerHTML='<i class="bi bi-arrow-repeat"></i>';}repairTitle.textContent=title||'';repairDetail.textContent=detail||'';repairStageCode.textContent=stage||kind;}
function setProgress(done,total){done=Math.max(0,Number(done)||0);total=Math.max(0,Number(total)||0);const percent=total>0?Math.min(100,Math.round(done*100/total)):(done>0?100:0);repairBar.style.width=percent+'%';repairProgressText.textContent=`${done.toLocaleString()} / ${total.toLocaleString()} (${percent}%)`;}
function setRunning(value){repairRunning=Boolean(value);if(repairSubmit){repairSubmit.disabled=repairRunning;repairSubmit.setAttribute('aria-busy',repairRunning?'true':'false');const span=repairSubmit.querySelector('span');if(span)span.textContent=repairRunning?TI_TEXT.repairing:<?php echo json_encode($t('Backup และซ่อม', 'Back up and repair'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;}if(repairRunning){repairStartedAt=Date.now();clearInterval(elapsedTimer);elapsedTimer=setInterval(()=>{repairElapsed.textContent=Math.max(0,Math.round((Date.now()-repairStartedAt)/1000))+'s';},500);}else{clearInterval(elapsedTimer);}}

async function callRepairApi(action,batchId,extra={},timeoutMs=15000){
    releaseTransactionIntegrityBlockingUi('before-api');
    const data=new FormData(repairForm);data.set('action',action);data.set('batch_id',batchId);Object.entries(extra).forEach(([k,v])=>data.set(k,String(v)));
    const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),timeoutMs);const started=performance.now();
    try{
        const response=await fetch('transaction_integrity_action.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
        const text=await response.text();let json=null;try{json=JSON.parse(text);}catch(_){throw Object.assign(new Error('invalid_json_response'),{code:'invalid_json_response',http_status:response.status,response_text:text.slice(0,1200)});}
        repairRequestId.textContent=json.request_id||repairRequestId.textContent||'-';addDebug(action,{http_status:response.status,duration_ms:Math.round(performance.now()-started),response:json});
        if(!response.ok||!json.success)throw Object.assign(new Error(json.message||json.code||'request_failed'),json,{http_status:response.status});
        return json;
    }catch(error){const normalized={code:error?.name==='AbortError'?'ui_timeout':(error?.code||'network_error'),message:String(error?.message||error),http_status:error?.http_status||0,response_text:error?.response_text||''};addDebug(action,{error:normalized,duration_ms:Math.round(performance.now()-started)});throw Object.assign(error instanceof Error?error:new Error(normalized.message),normalized);}finally{clearTimeout(timer);releaseTransactionIntegrityBlockingUi('after-api');}
}
function sleep(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
async function waitForSchema(batchId){for(let i=0;i<15;i++){await sleep(1800);try{const status=await callRepairApi('status',batchId,{},10000);const batch=status.batch||{};if(batch.status==='failed')throw Object.assign(new Error(batch.error_message||'schema_migration_failed'),{code:'batch_failed',status});if(status.schema_ready)return status;setStatus('working',TI_TEXT.migrating,TI_TEXT.timeout,`schema_poll_${i+1}`);}catch(error){if(error.code==='batch_failed')throw error;addDebug('schema_status_retry',{attempt:i+1,code:error.code||'status_error',message:String(error.message||error)});setStatus('working',TI_TEXT.migrating,TI_TEXT.timeout,`schema_retry_${i+1}`);}}throw Object.assign(new Error('schema_migration_timeout'),{code:'schema_migration_timeout'});}

repairForm?.addEventListener('submit',async event=>{
    event.preventDefault();releaseTransactionIntegrityBlockingUi('submit');
    if(repairRunning)return;
    const confirmation=repairForm.querySelector('input[name="confirmation"]');
    if(!confirmation||confirmation.value.trim().toUpperCase()!=='REPAIR'){confirmation?.focus();setStatus('error',TI_TEXT.failed,TI_TEXT.confirm,'confirmation');return;}
    const batchId=createBatchId();repairDebug=[];repairDebugPreview.textContent='';repairDebugPreview.classList.add('hidden');repairBatchId.textContent=batchId;repairRequestId.textContent='-';setProgress(0,Number(<?php echo (int) ($preview['repairable_count'] ?? 0); ?>));setRunning(true);
    try{
        setStatus('working',TI_TEXT.initializing,'','initialize');
        const initialized=await callRepairApi('initialize',batchId,{},15000);const total=Number(initialized.candidate_count||<?php echo (int) ($preview['repairable_count'] ?? 0); ?>);setProgress(0,total);
        setStatus('working',TI_TEXT.migrating,'','migrate_schema');
        try{await callRepairApi('migrate_schema',batchId,{},22000);}catch(error){if(error.code!=='ui_timeout'&&error.code!=='network_error')throw error;setStatus('working',TI_TEXT.migrating,TI_TEXT.timeout,'schema_status_check');await waitForSchema(batchId);}
        let completed=false;for(let round=0;round<30&&!completed;round++){
            setStatus('working',TI_TEXT.repairing,`${round+1}`,'repair_chunk');
            const chunk=await callRepairApi('repair_chunk',batchId,{limit:40},18000);const repaired=Number(chunk.repaired_count||0);const candidate=Number(chunk.candidate_count||total);setProgress(repaired,candidate);completed=chunk.code==='completed'||chunk.status==='completed'||Number(chunk.remaining)===0;
            if(!completed)await sleep(250);
        }
        if(!completed)throw Object.assign(new Error('chunk_limit_exceeded'),{code:'chunk_limit_exceeded'});
        const finalStatus=await callRepairApi('status',batchId,{},10000);const batch=finalStatus.batch||{};setProgress(Number(batch.repaired_count||total),Number(batch.candidate_count||total));setStatus('success',TI_TEXT.completed,`Batch ${batchId}`,'completed');addDebug('completed',{status:finalStatus});
    }catch(error){const code=error?.code||'repair_failed';const detail=[code,error?.message,error?.db_errno?`DB #${error.db_errno}`:'',error?.db_error||''].filter(Boolean).join(' · ');setStatus('error',TI_TEXT.failed,detail,code);repairDebugPreview.classList.remove('hidden');addDebug('failed',{code,message:String(error?.message||error),db_errno:error?.db_errno||0,db_error:error?.db_error||''});document.getElementById('integrityDebugLogs')?.setAttribute('open','');}
    finally{setRunning(false);releaseTransactionIntegrityBlockingUi('finished');}
});

document.getElementById('copyRepairDebug')?.addEventListener('click',async function(){const report={page:location.href,batch_id:repairBatchId.textContent,request_id:repairRequestId.textContent,elapsed:repairElapsed.textContent,user_agent:navigator.userAgent,events:repairDebug};const old=this.innerHTML;const ok=await copyText(JSON.stringify(report,null,2));this.innerHTML=ok?'<i class="bi bi-check2 mr-1"></i>'+TI_TEXT.copied:'<i class="bi bi-x-lg mr-1"></i>'+TI_TEXT.copyFailed;setTimeout(()=>this.innerHTML=old,1600);});
document.getElementById('closeRepairStatus')?.addEventListener('click',()=>{if(!repairRunning)repairPanel.classList.add('hidden');});
</script>
</body>
</html>
