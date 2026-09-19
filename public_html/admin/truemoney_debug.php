<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/truemoney.php';
require_once __DIR__ . '/../includes/truemoney_byteindev.php';
require_once __DIR__ . '/../includes/truemoney_byteindev_route.php';
require_once __DIR__ . '/../includes/truemoney_byteindev_debug.php';
requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string { return $isThai ? $th : $en; };
$e = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$adminId = max(0, (int) ($_SESSION['user_id'] ?? 0));

if (isset($_GET['debug_json'])) {
    $debugId = filter_var($_GET['debug_json'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $debugRow = $debugId ? trueMoneyDebugFetchById((int) $debugId) : null;
    if (!$debugRow) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'ไม่พบ TrueMoney Debug JSON'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $requestId = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($debugRow['request_id'] ?? 'truemoney'));
    $json = (string) ($debugRow['debug_json'] ?? '{}');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    if (($_GET['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="truemoney-debug-' . $requestId . '.json"');
    }
    echo $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'provider_probe') {
    requireCsrfToken();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');

    if (!function_exists('checkRateLimit') || !checkRateLimit('admin_truemoney_provider_probe_' . $adminId, 2, 60)) {
        http_response_code(429);
        echo json_encode([
            'schema' => 'sakazuki.debug',
            'version' => 1,
            'type' => 'truemoney.provider_probe',
            'result' => [
                'success' => false,
                'stage' => 'probe_rate_limited',
                'code' => 'local_probe_rate_limit',
            ],
            'message' => 'จำกัดการทดสอบไว้ไม่เกิน 2 ครั้งต่อนาที เพื่อป้องกันการยิง Provider รัวจากหน้า Admin',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $debug = trueMoneyByteIndevRunSafeProviderProbe($adminId);
        echo trueMoneyDebugPublicJson($debug);
    } catch (Throwable $ex) {
        http_response_code(500);
        echo json_encode([
            'schema' => 'sakazuki.debug',
            'version' => 1,
            'type' => 'truemoney.provider_probe',
            'result' => [
                'success' => false,
                'stage' => 'probe_exception',
                'code' => 'probe_exception',
            ],
            'error' => [
                'class' => get_class($ex),
                'code' => $ex->getCode(),
                'message' => trueMoneyDebugSafeText($ex->getMessage(), 600),
                'message_sha256' => hash('sha256', $ex->getMessage()),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$contract = trueMoneyByteIndevDebugContract();
$policy = trueMoneyByteIndevDebugRoutingPolicy();
$phone = preg_replace('/\D+/', '', (string) getSetting('truemoney_phone'));
$phoneCheck = trueMoneyPhoneContractCheck($phone);
$debugReady = ensureTrueMoneyDebugTable();
$debugRows = $debugReady ? trueMoneyDebugFetchRecent(50) : [];
$csrfToken = getCsrfToken();
$effectiveOrder = implode(' → ', array_map(static function ($name): string { return strtoupper((string) $name); }, (array) ($contract['effective_order'] ?? [])));
$disabledBackends = implode(', ', array_map(static function ($name): string { return strtoupper((string) $name); }, (array) ($contract['disabled_backends'] ?? [])));
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('TrueMoney / Wallet Debug', 'TrueMoney / Wallet Debug')); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#8b5cf6;--sakazuki-accent-rgb:139 92 246}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono-wrap{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere}
    </style>
</head>
<body class="min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
    <section>
        <h1 class="flex items-center gap-2 text-2xl font-bold text-white"><i class="bi bi-wallet2 text-orange-300"></i><?php echo $e($t('TrueMoney / Wallet Debug', 'TrueMoney / Wallet Debug')); ?></h1>
        <p class="mt-2 max-w-5xl text-sm text-gray-400"><?php echo $e($t('หน้านี้อ้างอิงเส้นทาง Production ปัจจุบันโดยตรง: Go ถูกปิด, NestJS เป็นตัวหลัก, FastAPI เป็นตัวสำรอง และไม่มีการสลับ Backend หลังส่งคำขอรับซองแล้ว', 'This page now reflects the current production route directly: Go disabled, NestJS primary, FastAPI fallback, and no backend failover after a redeem request is sent.')); ?></p>
    </section>

    <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-500"><?php echo $e($t('ระบบ TrueMoney', 'TrueMoney feature')); ?></div>
            <div class="mt-2 text-lg font-bold <?php echo getSetting('truemoney_enabled') === '1' ? 'text-green-300' : 'text-amber-300'; ?>"><?php echo $e(getSetting('truemoney_enabled') === '1' ? $t('เปิดใช้งาน', 'Enabled') : $t('ปิดใช้งาน', 'Disabled')); ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-500"><?php echo $e($t('เบอร์ผู้รับ / Local validation', 'Receiver phone / local validation')); ?></div>
            <div class="mt-2 text-lg font-bold <?php echo !empty($phoneCheck['exact_10_digits']) ? 'text-green-300' : 'text-red-300'; ?>"><?php echo $e($phoneCheck['masked'] ?: '-'); ?></div>
            <div class="mt-1 text-xs text-gray-500">10 digits + starts with 0: <?php echo !empty($phoneCheck['exact_10_digits']) ? 'YES' : 'NO'; ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-500"><?php echo $e($t('Production Provider', 'Production provider')); ?></div>
            <div class="mt-2 text-lg font-bold text-cyan-200"><?php echo $e(trueMoneyByteIndevProviderDisplayName()); ?></div>
            <div class="mt-1 text-xs text-white"><?php echo $e($effectiveOrder ?: '-'); ?></div>
            <div class="mt-1 text-xs text-amber-300"><?php echo $e(($disabledBackends ?: '-') . ' disabled'); ?></div>
        </div>
        <div class="glass rounded-xl p-4">
            <div class="text-xs text-gray-500"><?php echo $e($t('Recipient Guard / Debug', 'Recipient guard / Debug')); ?></div>
            <div class="mt-2 text-lg font-bold <?php echo $debugReady ? 'text-green-300' : 'text-red-300'; ?>"><?php echo $e($debugReady ? $t('พร้อมใช้งาน', 'Ready') : $t('ไม่พร้อม', 'Unavailable')); ?></div>
            <div class="mt-1 text-xs text-gray-500"><?php echo number_format(count($debugRows)); ?> recent rows · phone/name guard ON</div>
        </div>
    </section>

    <section class="glass rounded-xl border border-orange-400/20 p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-4xl">
                <h2 class="font-bold text-white"><i class="bi bi-activity mr-2 text-orange-300"></i><?php echo $e($t('ทดสอบ Backend Production แบบปลอดภัย', 'Safe production backend probe')); ?></h2>
                <p class="mt-2 text-sm text-gray-400"><?php echo $e($t('ทดสอบเส้นทางเดียวกับ Production ปัจจุบัน ไม่เรียก xpluem แล้ว ระบบจะตรวจ Go routing guard, health ของ NestJS/FastAPI และยิงซองปลอมที่จงใจใช้ไม่ได้ไปยัง Backend ที่ health ผ่าน โดยไม่สร้าง redemption และไม่แตะ Wallet', 'Tests the current production provider family. It no longer calls xpluem. The probe checks the Go routing guard, NestJS/FastAPI health, and sends a deliberately invalid synthetic voucher to healthy backends without creating a redemption or touching the wallet.')); ?></p>
                <div class="mt-3 rounded-lg border border-white/10 bg-black/25 p-3 text-xs text-gray-400">
                    <div><strong class="text-gray-200">Production route:</strong> <?php echo $e($effectiveOrder ?: '-'); ?> · <?php echo $e(($disabledBackends ?: '-') . ' disabled'); ?></div>
                    <div class="mt-1"><strong class="text-gray-200">Expected result:</strong> health ต้องผ่าน และ synthetic voucher ต้องถูกปฏิเสธด้วย JSON ที่รู้จัก การตอบ SUCCESS ต่อซองปลอมจะถูกจัดเป็นเหตุผิดปกติร้ายแรง</div>
                    <div class="mt-1"><strong class="text-gray-200">Safety:</strong> ไม่ใช้ซองจริง, ไม่สร้างรายการเติมเงิน, ไม่เครดิต Wallet, ไม่เก็บ raw provider body, ไม่เก็บ URL ที่มี voucher/phone แบบเต็ม</div>
                    <div class="mt-1"><strong class="text-gray-200">Recipient guard:</strong> การเติมเงินจริงยังตรวจผู้รับหลัง SUCCESS ก่อนเครดิตเงินเหมือนเดิม Probe นี้ไม่จำลอง SUCCESS เพื่อไม่เสี่ยงสร้างรายการเงินจริง</div>
                </div>
            </div>
            <button id="runProbe" type="button" class="shrink-0 rounded-lg bg-orange-500 px-5 py-3 font-bold text-white hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50"><i class="bi bi-send-check mr-2"></i><?php echo $e($t('ทดสอบ Backend ตอนนี้', 'Probe backends now')); ?></button>
        </div>
        <div id="probeStatus" class="mt-3 hidden rounded-lg border border-white/10 bg-black/20 p-3 text-sm"></div>
        <div id="probeActions" class="mt-3 hidden flex flex-wrap gap-2">
            <button id="copyProbe" type="button" class="rounded-lg bg-white/10 px-3 py-2 text-sm hover:bg-white/15"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอก JSON', 'Copy JSON')); ?></button>
            <button id="downloadProbe" type="button" class="rounded-lg bg-emerald-500/15 px-3 py-2 text-sm text-emerald-200 hover:bg-emerald-500/25"><i class="bi bi-download mr-1"></i><?php echo $e($t('ดาวน์โหลด JSON', 'Download JSON')); ?></button>
        </div>
        <pre id="probeJson" class="mono-wrap mt-3 hidden max-h-[42rem] overflow-auto whitespace-pre-wrap rounded-lg bg-black/35 p-4 text-xs text-gray-300"></pre>
    </section>

    <section class="glass rounded-xl p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="font-bold text-white"><i class="bi bi-filetype-json mr-2 text-cyan-300"></i><?php echo $e($t('Debug JSON ล่าสุด', 'Recent Debug JSON')); ?></h2>
                <p class="mt-1 text-xs text-gray-500"><?php echo $e($t('รวม redemption จริงและ provider probe สูงสุด 50 รายการล่าสุด รายการเก่าจะยังแสดง Provider ที่ใช้อยู่ ณ เวลานั้นตามจริง', 'Latest 50 redemption and provider-probe diagnostics. Historical rows keep the provider that was actually used at that time.')); ?></p>
            </div>
            <a href="truemoney_debug.php" class="rounded-lg bg-white/10 px-3 py-2 text-sm hover:bg-white/15"><i class="bi bi-arrow-clockwise mr-1"></i><?php echo $e($t('รีเฟรช', 'Refresh')); ?></a>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-white/10 text-xs text-gray-500"><tr><th class="p-3">Time</th><th class="p-3">Request</th><th class="p-3">Provider</th><th class="p-3">Result</th><th class="p-3">HTTP</th><th class="p-3">Duration</th><th class="p-3">JSON</th></tr></thead>
                <tbody>
                <?php if (!$debugRows): ?>
                    <tr><td colspan="7" class="p-6 text-center text-gray-500"><?php echo $e($t('ยังไม่มี Debug JSON', 'No debug JSON yet')); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($debugRows as $row): $id=(int)($row['id']??0); $jsonUrl='truemoney_debug.php?debug_json='.$id; $downloadUrl=$jsonUrl.'&download=1'; ?>
                    <tr class="border-b border-white/[.06] align-top">
                        <td class="p-3 text-xs text-gray-400"><?php echo $e($row['updated_at'] ?? '-'); ?></td>
                        <td class="p-3"><code class="mono-wrap text-xs text-cyan-200"><?php echo $e($row['request_id'] ?? '-'); ?></code><?php if (!empty($row['username'])): ?><div class="mt-1 text-xs text-gray-500"><?php echo $e($row['username']); ?> #<?php echo (int)($row['user_id']??0); ?></div><?php endif; ?></td>
                        <td class="p-3"><code class="mono-wrap text-xs text-gray-300"><?php echo $e($row['provider_host'] ?? '-'); ?></code></td>
                        <td class="p-3"><div class="font-medium <?php echo (int)($row['success']??0)===1 ? 'text-green-300' : 'text-red-300'; ?>"><?php echo $e($row['result_code'] ?? '-'); ?></div><div class="mt-1 text-xs text-gray-500"><?php echo $e($row['stage'] ?? '-'); ?></div></td>
                        <td class="p-3"><div><?php echo $row['http_code'] !== null ? (int)$row['http_code'] : '-'; ?></div><div class="text-xs text-gray-500">cURL <?php echo $row['curl_errno'] !== null ? (int)$row['curl_errno'] : '-'; ?></div></td>
                        <td class="p-3 text-xs"><?php echo $row['duration_ms'] !== null ? number_format((int)$row['duration_ms']).' ms' : '-'; ?></td>
                        <td class="p-3"><div class="flex flex-wrap gap-2"><button type="button" class="viewSaved rounded-md bg-white/10 px-2.5 py-1.5 text-xs hover:bg-white/15" data-url="<?php echo $e($jsonUrl); ?>"><i class="bi bi-eye mr-1"></i><?php echo $e($t('ดู', 'View')); ?></button><button type="button" class="copySaved rounded-md bg-violet-500/15 px-2.5 py-1.5 text-xs text-violet-200 hover:bg-violet-500/25" data-url="<?php echo $e($jsonUrl); ?>"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอก', 'Copy')); ?></button><a href="<?php echo $e($downloadUrl); ?>" class="rounded-md bg-emerald-500/15 px-2.5 py-1.5 text-xs text-emerald-200 hover:bg-emerald-500/25"><i class="bi bi-download mr-1"></i><?php echo $e($t('ดาวน์โหลด', 'Download')); ?></a></div></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass rounded-xl p-4">
        <h2 class="font-bold text-white"><i class="bi bi-journal-code mr-2 text-violet-300"></i><?php echo $e($t('Production Provider / Diagnostic Contract', 'Production provider / diagnostic contract')); ?></h2>
        <p class="mt-1 text-xs text-gray-500"><?php echo $e($t('แสดง Contract ที่หน้า Debug ใช้ตอนนี้ ไม่ใช่ Contract เก่าของ xpluem', 'Shows the contract currently used by this debug page, not the retired xpluem contract.')); ?></p>
        <pre class="mono-wrap mt-3 max-h-[34rem] overflow-auto whitespace-pre-wrap rounded-lg bg-black/35 p-4 text-xs text-gray-300"><?php echo $e(json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
    </section>
</main>

<div id="savedModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/70 p-4">
    <div class="glass flex max-h-[92vh] w-full max-w-5xl flex-col rounded-xl bg-[#121218]">
        <div class="flex items-center justify-between border-b border-white/10 p-4"><div class="font-bold">TrueMoney Debug JSON</div><button id="closeSaved" class="rounded-lg bg-white/10 px-3 py-2"><i class="bi bi-x-lg"></i></button></div>
        <pre id="savedJson" class="mono-wrap flex-1 overflow-auto whitespace-pre-wrap p-4 text-xs text-gray-300"></pre>
        <div class="flex gap-2 border-t border-white/10 p-4"><button id="copySavedModal" class="rounded-lg bg-violet-500/15 px-3 py-2 text-sm text-violet-200"><i class="bi bi-copy mr-1"></i><?php echo $e($t('คัดลอก JSON', 'Copy JSON')); ?></button></div>
    </div>
</div>

<script>
(() => {
    const csrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const runButton = document.getElementById('runProbe');
    const statusBox = document.getElementById('probeStatus');
    const actionBox = document.getElementById('probeActions');
    const jsonBox = document.getElementById('probeJson');
    const copyProbe = document.getElementById('copyProbe');
    const downloadProbe = document.getElementById('downloadProbe');
    let currentProbeJson = '';
    let savedModalJson = '';

    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const area = document.createElement('textarea');
        area.value = text; area.readOnly = true; area.style.position = 'fixed'; area.style.opacity = '0';
        document.body.appendChild(area); area.select();
        const ok = document.execCommand('copy'); area.remove();
        if (!ok) throw new Error('Clipboard unavailable');
    }
    function downloadJson(text, filename) {
        const blob = new Blob([text], {type:'application/json;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a'); link.href = url; link.download = filename; document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function prettyJson(text) {
        try { return JSON.stringify(JSON.parse(text), null, 2); } catch (_) { return text; }
    }

    runButton?.addEventListener('click', async () => {
        runButton.disabled = true;
        currentProbeJson = '';
        statusBox.classList.remove('hidden'); statusBox.textContent = 'กำลังตรวจ Go guard + NestJS + FastAPI จาก SAKAZUKI Server...';
        actionBox.classList.add('hidden'); jsonBox.classList.add('hidden');
        try {
            const body = new FormData(); body.set('action','provider_probe'); body.set('csrf_token', csrfToken);
            const response = await fetch('truemoney_debug.php', {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, body});
            const text = await response.text();
            currentProbeJson = prettyJson(text);
            jsonBox.textContent = currentProbeJson; jsonBox.classList.remove('hidden'); actionBox.classList.remove('hidden');
            let parsed = null; try { parsed = JSON.parse(text); } catch (_) {}
            const summary = parsed && parsed.probe && parsed.probe.summary ? parsed.probe.summary : null;
            const code = parsed && parsed.result ? parsed.result.code : null;
            const selected = summary && summary.selected_backend_by_health ? summary.selected_backend_by_health : null;
            statusBox.textContent = 'Backend probe เสร็จแล้ว' + (selected ? ' · selected ' + selected : '') + (summary && summary.status ? ' · ' + summary.status : '') + (code ? ' · ' + code : '');
            statusBox.className = 'mt-3 rounded-lg border p-3 text-sm ' + (parsed && parsed.result && parsed.result.success ? 'border-green-500/30 bg-green-500/10 text-green-200' : 'border-orange-500/30 bg-orange-500/10 text-orange-100');
        } catch (error) {
            statusBox.textContent = 'ทดสอบไม่สำเร็จ: ' + (error && error.message ? error.message : String(error));
            statusBox.className = 'mt-3 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200';
        } finally { runButton.disabled = false; }
    });
    copyProbe?.addEventListener('click', async () => { if (currentProbeJson) await copyText(currentProbeJson); });
    downloadProbe?.addEventListener('click', () => { if (currentProbeJson) downloadJson(currentProbeJson, 'truemoney-byteindev-probe-' + new Date().toISOString().replace(/[:.]/g,'-') + '.json'); });

    async function fetchJson(url) {
        const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
        const text = await response.text(); if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + text.slice(0,250)); return prettyJson(text);
    }
    const modal = document.getElementById('savedModal'); const modalJson = document.getElementById('savedJson');
    document.querySelectorAll('.viewSaved').forEach(btn => btn.addEventListener('click', async () => {
        savedModalJson = 'กำลังโหลด...'; modalJson.textContent = savedModalJson; modal.classList.remove('hidden'); modal.classList.add('flex');
        try { savedModalJson = await fetchJson(btn.dataset.url || ''); modalJson.textContent = savedModalJson; } catch (e) { savedModalJson = ''; modalJson.textContent = e.message || String(e); }
    }));
    document.querySelectorAll('.copySaved').forEach(btn => btn.addEventListener('click', async () => { const text = await fetchJson(btn.dataset.url || ''); await copyText(text); }));
    document.getElementById('copySavedModal')?.addEventListener('click', async () => { if (savedModalJson) await copyText(savedModalJson); });
    document.getElementById('closeSaved')?.addEventListener('click', () => { modal.classList.add('hidden'); modal.classList.remove('flex'); });
    modal?.addEventListener('click', e => { if (e.target === modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } });
})();
</script>
</body>
</html>