<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

requireLogin();
if (!isReseller()) authRedirect('index.php');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string {
    return $isThai ? $th : $en;
};
$e = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$providers = keyResetProviders();
$quota = keyResetGetResellerQuota($userId, 'xchetos');
$history = keyResetGetHistory($userId, 30);
$policy = keyResetGetPolicy('xchetos');

$flash = null;
if (isset($_SESSION['key_reset_flash']) && is_array($_SESSION['key_reset_flash'])) {
    $flash = $_SESSION['key_reset_flash'];
} elseif (isset($_SESSION['xchetos_reset_flash']) && is_array($_SESSION['xchetos_reset_flash'])) {
    $flash = $_SESSION['xchetos_reset_flash'];
}
unset($_SESSION['key_reset_flash'], $_SESSION['xchetos_reset_flash']);

$messages = [
    'success' => $t('รีเซ็ตคีย์สำเร็จ', 'Key reset completed successfully.'),
    'invalid_request' => $t('กรุณากรอกคีย์ให้ถูกต้อง', 'Please enter a valid key.'),
    'forbidden' => $t('เฉพาะบัญชีตัวแทนเท่านั้นที่ใช้งานได้', 'Only reseller accounts may use this page.'),
    'provider_not_supported' => $t('ยังไม่มีระบบรีเซ็ตสำหรับผู้ให้บริการนี้', 'This provider does not have a reset adapter yet.'),
    'not_configured' => $t('ระบบรีเซ็ตยังตั้งค่าไม่ครบ กรุณาติดต่อแอดมิน', 'The reset provider is not fully configured. Please contact an administrator.'),
    'audit_unavailable' => $t('ระบบ Logs ไม่พร้อม จึงไม่ส่งคำขอรีเซ็ต', 'The audit log is unavailable, so no reset was sent.'),
    'key_not_found' => $t('ไม่พบคีย์จากรายการเดิม กรุณาวางคีย์เต็มในช่องรีเซ็ตด่วน', 'The saved key could not be found. Paste the full key into Quick Reset.'),
    'key_not_supported' => $t('รูปแบบคีย์ไม่รองรับ กรุณาตรวจสอบคีย์อีกครั้ง', 'The key format is not supported. Please check the key.'),
    'rate_limited' => $t('กดถี่เกินไป กรุณารอสักครู่', 'Requests are being submitted too quickly. Please wait.'),
    'daily_limit_reached' => $t('ใช้โควตารีเซ็ตครบแล้ว ระบบจะคืนโควตาอัตโนมัติเมื่อรายการเก่าครบ 24 ชั่วโมง', 'The reset allowance is exhausted. Quota returns automatically as the oldest request reaches 24 hours.'),
    'key_reset_limit_reached' => $t('คีย์นี้ใช้สิทธิ์รีเซ็ตครบตามอายุคีย์แล้ว', 'This key has reached the reset allowance for its duration.'),
    'cooldown' => $t('คีย์นี้เพิ่งถูกรีเซ็ต กรุณารอก่อนกดซ้ำ', 'This key was reset recently. Please wait before retrying.'),
    'verification_pending' => $t('ผลครั้งก่อนยังไม่แน่ชัด กรุณารอและห้ามกดซ้ำทันที', 'The previous result is uncertain. Wait and do not retry immediately.'),
    'busy' => $t('มีคำขออื่นกำลังทำงานกับบัญชีหรือคีย์นี้', 'Another request is processing for this account or key.'),
    'login_failed' => $t('เว็บไซต์ล็อกอินผู้ให้บริการไม่สำเร็จ กรุณาติดต่อแอดมิน', 'The website could not sign in to the provider. Please contact an administrator.'),
    'provider_not_owned' => $t('ผู้ให้บริการปฏิเสธคีย์นี้ คีย์อาจไม่ได้อยู่ในบัญชีผู้ให้บริการของร้าน', 'The provider rejected this key. It may not belong to the shop provider account.'),
    'provider_rate_limited' => $t('ผู้ให้บริการจำกัดคำขอชั่วคราว กรุณาลองใหม่ภายหลัง', 'The provider temporarily rate-limited requests. Please retry later.'),
    'provider_rejected' => $t('ผู้ให้บริการปฏิเสธคำขอ กรุณาตรวจสอบคีย์', 'The provider rejected the request. Please check the key.'),
    'provider_unavailable' => $t('ขณะนี้เชื่อมต่อผู้ให้บริการไม่ได้', 'The provider is currently unavailable.'),
    'provider_unknown' => $t('ยืนยันผลจากผู้ให้บริการไม่ได้ การรีเซ็ตอาจสำเร็จแล้ว กรุณาตรวจประวัติก่อนกดซ้ำ', 'The provider result is uncertain and may already have succeeded. Check history before retrying.'),
    'provider_verification_unknown' => $t('ตรวจสอบความเป็นเจ้าของคีย์ไม่สำเร็จแบบไม่แน่ชัด ระบบจึงหยุดก่อนส่งรีเซ็ต', 'Provider ownership verification was uncertain, so the reset was stopped before submission.'),
    'provider_inventory_invalid' => $t('รูปแบบรายการคีย์จากผู้ให้บริการเปลี่ยนหรือไม่ถูกต้อง กรุณาแจ้งแอดมิน', 'The provider inventory response was invalid or changed. Contact an administrator.'),
    'provider_inventory_incomplete' => $t('ระบบตรวจรายการคีย์ได้ไม่ครบ จึงหยุดก่อนรีเซ็ตเพื่อป้องกันความผิดพลาด', 'The provider inventory could not be scanned completely, so reset was stopped for safety.'),
    'processing' => $t('เซิร์ฟเวอร์กำลังดำเนินการ กรุณารอสักครู่', 'The server is still processing the request.'),
    'session_expired' => $t('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่และเข้าสู่ระบบอีกครั้ง', 'Your session expired. Reload the page and sign in again.'),
    'csrf_failed' => $t('โทเคนความปลอดภัยหมดอายุ กรุณาโหลดหน้าใหม่', 'The security token expired. Reload the page.'),
    'server_error' => $t('เซิร์ฟเวอร์เกิดข้อผิดพลาดก่อนยืนยันผล กรุณาแจ้ง Ref ให้แอดมินตรวจ Log', 'The server failed before confirming the result. Give the Ref to an administrator.'),
    'invalid_response' => $t('เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง ระบบกำลังตรวจประวัติคำขอให้อัตโนมัติ', 'The server returned an invalid response. The request history is being checked automatically.'),
    'request_not_found' => $t('ไม่พบคำขอนี้ในเซิร์ฟเวอร์ จึงยังไม่มีการใช้โควตา', 'This request was not found on the server, so no allowance was consumed.'),
    'curl_missing' => $t('เซิร์ฟเวอร์ไม่ได้เปิด PHP cURL', 'PHP cURL is not enabled on the server.'),
];

$flashCode = (string) ($flash['code'] ?? '');
$flashStatus = (string) ($flash['status'] ?? (!empty($flash['success']) ? 'success' : 'failed'));
$flashText = $flash ? ($messages[$flashCode] ?? $t('ไม่สามารถดำเนินการรีเซ็ตได้', 'The reset could not be completed.')) : '';
$quotaUsed = max(0, (int) ($quota['used'] ?? 0));
$quotaLimit = max(1, (int) ($quota['limit'] ?? $policy['daily_limit'] ?? 100));
$quotaRemaining = max(0, (int) ($quota['remaining'] ?? 0));
$quotaPercent = min(100, (int) round(($quotaUsed / $quotaLimit) * 100));
$quotaResetAt = trim((string) ($quota['reset_at'] ?? ''));
$providerReady = !empty($providers['xchetos']['configured']);
$quotaAvailable = !empty($quota['available']);

function keyResetStatusClass(string $status): string
{
    if ($status === 'success') return 'border-green-500/30 bg-green-500/10 text-green-300';
    if ($status === 'unknown') return 'border-yellow-500/30 bg-yellow-500/10 text-yellow-200';
    if ($status === 'processing') return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-200';
    return 'border-red-500/30 bg-red-500/10 text-red-300';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('รีเซ็ตคีย์', 'Key Reset')); ?> - Reseller</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#22c55e;--sakazuki-accent-rgb:34 197 94}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono-wrap{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere;word-break:break-word}
        .spin{animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
        .reset-toast{animation:resetToastIn .15s cubic-bezier(.22,1,.36,1) both;box-shadow:0 22px 70px rgba(0,0,0,.46)}
        .reset-toast.is-leaving{animation:resetToastOut .15s ease both}
        .reset-toast-progress{transform-origin:left;animation:resetToastProgress 8s linear both}
        @keyframes resetToastIn{from{opacity:0;transform:translate3d(24px,-8px,0) scale(.97)}to{opacity:1;transform:translate3d(0,0,0) scale(1)}}
        @keyframes resetToastOut{to{opacity:0;transform:translate3d(18px,-6px,0) scale(.97)}}
        @keyframes resetToastProgress{from{transform:scaleX(1)}to{transform:scaleX(0)}}
        .key-reset-inline-busy{display:none}
        .key-reset-inline-busy.is-active{display:flex}
    </style>
</head>
<body class="key-reset-page min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white"><i class="bi bi-lightning-charge-fill mr-2 text-orange-300"></i><?php echo $e($t('รีเซ็ตคีย์ด่วน', 'Quick Key Reset')); ?></h1>
            <p class="mt-1 text-sm text-gray-400"><?php echo $e($t('ตัวแทนสามารถช่วยรีเซ็ตคีย์ของลูกค้าได้ทุกคน เพียงวางคีย์แล้วกดครั้งเดียว', 'A reseller can help any customer. Paste the key and submit once.')); ?></p>
        </div>
        <a href="mykeys.php" class="inline-flex items-center justify-center rounded-lg bg-white/10 px-4 py-2 text-sm text-gray-200 hover:bg-white/15"><i class="bi bi-key mr-2"></i><?php echo $e($t('คีย์ของฉัน', 'My Keys')); ?></a>
    </header>

    <div id="resetToastHost" class="pointer-events-none fixed inset-x-3 top-3 z-[120] flex flex-col items-end gap-3 md:inset-x-6 md:top-6" aria-live="assertive">
        <div id="ajaxResult" class="reset-toast pointer-events-auto hidden w-full max-w-lg overflow-hidden rounded-2xl border" role="alert"></div>
        <?php if ($flash): ?>
            <?php
            $serverFlashSuccess = !empty($flash['success']);
            $serverFlashClass = $serverFlashSuccess
                ? 'border-green-500/40 bg-green-950/95 text-green-100'
                : ($flashStatus === 'unknown' ? 'border-yellow-500/40 bg-yellow-950/95 text-yellow-100' : ($flashStatus === 'processing' ? 'border-cyan-500/40 bg-cyan-950/95 text-cyan-100' : 'border-red-500/40 bg-red-950/95 text-red-100'));
            $serverFlashIcon = $serverFlashSuccess ? 'bi-check-circle-fill' : ($flashStatus === 'unknown' ? 'bi-exclamation-triangle-fill' : ($flashStatus === 'processing' ? 'bi-hourglass-split' : 'bi-x-octagon-fill'));
            $serverFlashTitle = $serverFlashSuccess ? $t('รีเซ็ตสำเร็จ', 'Reset completed') : ($flashStatus === 'unknown' ? $t('ผลยังไม่แน่ชัด', 'Result is uncertain') : ($flashStatus === 'processing' ? $t('กำลังดำเนินการ', 'Processing') : $t('รีเซ็ตไม่สำเร็จ', 'Reset failed')));
            ?>
            <div id="serverFlashResult" class="reset-toast pointer-events-auto relative w-full max-w-lg overflow-hidden rounded-2xl border <?php echo $serverFlashClass; ?>" data-auto-close="<?php echo $serverFlashSuccess ? '8000' : '0'; ?>" role="alert">
                <div class="flex items-start gap-3 p-4 pr-12">
                    <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10"><i class="bi <?php echo $serverFlashIcon; ?> text-xl"></i></div>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold"><?php echo $e($serverFlashTitle); ?></div>
                        <div class="mt-1 text-sm leading-6 opacity-95"><?php echo $e($flashText); ?></div>
                        <div class="mt-3 grid gap-1.5 text-xs opacity-80">
                            <?php if (!empty($flash['product_name'])): ?><div><span class="opacity-70"><?php echo $e($t('สินค้า: ', 'Product: ')); ?></span><?php echo $e($flash['product_name']); ?></div><?php endif; ?>
                            <?php if (!empty($flash['key_masked'])): ?><div class="mono-wrap"><span class="opacity-70"><?php echo $e($t('คีย์: ', 'Key: ')); ?></span><?php echo $e($flash['key_masked']); ?></div><?php endif; ?>
                            <?php if ((int) ($flash['key_reset_limit'] ?? 0) > 0): ?><div><?php echo $e($t('สิทธิ์คีย์: ', 'Key usage: ') . (int) ($flash['key_reset_count'] ?? 0) . ' / ' . (int) $flash['key_reset_limit'] . $t(' · เหลือ ', ' · remaining ') . (int) ($flash['key_reset_remaining'] ?? 0)); ?></div><?php endif; ?>
                            <?php if ((int) ($flash['daily_limit'] ?? 0) > 0): ?><div><?php echo $e($t('โควตา 24 ชม.: ', '24-hour quota: ') . (int) ($flash['daily_used'] ?? 0) . ' / ' . (int) $flash['daily_limit'] . $t(' · เหลือ ', ' · remaining ') . (int) ($flash['daily_remaining'] ?? 0)); ?></div><?php endif; ?>
                            <?php if (!empty($flash['daily_reset_at'])): ?><div><?php echo $e($t('คืนโควตาถัดไป: ', 'Next quota return: ') . $flash['daily_reset_at']); ?></div><?php endif; ?>
                            <?php if (!empty($flash['wait_seconds'])): ?><div><?php echo $e($t('รอประมาณ ', 'Wait about ') . (int) $flash['wait_seconds'] . $t(' วินาที', ' seconds')); ?></div><?php endif; ?>
                            <?php if (!empty($flash['request_id'])): ?><div class="mono-wrap"><span class="opacity-70">Ref: </span><?php echo $e($flash['request_id']); ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <button type="button" data-close-reset-toast class="absolute right-3 top-3 rounded-lg p-2 opacity-70 hover:bg-white/10 hover:opacity-100" aria-label="<?php echo $e($t('ปิดการแจ้งเตือน', 'Close notification')); ?>"><i class="bi bi-x-lg"></i></button>
                <?php if ($serverFlashSuccess): ?><div class="reset-toast-progress h-1 bg-current opacity-45"></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <section class="grid gap-4 lg:grid-cols-3">
        <div class="glass rounded-xl p-5 lg:col-span-2">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-400"><?php echo $e($t('โควตาตัวแทนใน 24 ชั่วโมง', 'Reseller allowance in 24 hours')); ?></div>
                    <div class="mt-2 text-3xl font-bold text-white"><span id="quotaUsed"><?php echo $quotaUsed; ?></span> <span class="text-lg text-gray-500">/ <span id="quotaLimit"><?php echo $quotaLimit; ?></span></span></div>
                </div>
                <span id="quotaBadge" class="rounded-full border px-3 py-1 text-xs <?php echo $quotaRemaining > 0 ? 'border-green-500/30 bg-green-500/10 text-green-300' : 'border-red-500/30 bg-red-500/10 text-red-300'; ?>"><?php echo $e($t('เหลือ ', 'Remaining ')); ?><span id="quotaRemaining"><?php echo $quotaRemaining; ?></span></span>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-black/40"><div id="quotaProgress" class="h-full rounded-full bg-green-500 transition-all" style="width:<?php echo $quotaPercent; ?>%"></div></div>
            <div class="mt-3 flex flex-col gap-1 text-xs text-gray-500 sm:flex-row sm:justify-between">
                <span><?php echo $e($t('นับเฉพาะคำขอที่ส่งไปยังผู้ให้บริการจริง', 'Only requests actually sent to the provider consume allowance.')); ?></span>
                <span id="quotaResetAt"><?php echo $quotaResetAt !== '' ? $e($t('สิทธิ์ถัดไปคืนเวลา ', 'Next slot returns at ') . $quotaResetAt) : $e($t('ยังไม่มีรายการใน 24 ชั่วโมง', 'No requests in the current 24-hour window')); ?></span>
            </div>
        </div>
        <div class="glass rounded-xl p-5">
            <div class="flex items-center justify-between"><div class="text-sm text-gray-400"><?php echo $e($t('สิทธิ์ตามอายุคีย์', 'Allowance by key duration')); ?></div><i class="bi bi-speedometer2 text-orange-300"></i></div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg bg-white/5 p-2"><div class="text-xs text-gray-500">1D</div><div class="font-bold text-white">2</div></div>
                <div class="rounded-lg bg-white/5 p-2"><div class="text-xs text-gray-500">2-3D</div><div class="font-bold text-white">4</div></div>
                <div class="rounded-lg bg-white/5 p-2"><div class="text-xs text-gray-500">4D+</div><div class="font-bold text-white">5</div></div>
            </div>
            <p class="mt-3 text-xs leading-5 text-gray-500"><?php echo $e($t('นับรวมทุกตัวแทนด้วยแฮชของคีย์ คีย์เดิมจึงไม่สามารถวนขอเกินสิทธิ์ได้', 'Counted globally by key hash across all resellers.')); ?></p>
        </div>
    </section>

    <?php if (!$providerReady): ?>
        <div class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 px-4 py-3 text-yellow-200" role="alert"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $e($t('ผู้ให้บริการ xChetos ยังตั้งค่าไม่ครบ ปุ่มรีเซ็ตถูกปิดไว้', 'The xChetos provider is not configured, so reset is disabled.')); ?></div>
    <?php elseif (!$quotaAvailable): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-200" role="alert"><i class="bi bi-database-exclamation mr-2"></i><?php echo $e($t('ระบบตรวจสอบโควตาไม่พร้อม จึงปิดปุ่มรีเซ็ตชั่วคราวเพื่อป้องกันการนับผิด กรุณาแจ้งแอดมิน', 'Quota verification is unavailable, so reset is temporarily disabled to prevent incorrect counting. Contact an administrator.')); ?></div>
    <?php elseif ($quotaRemaining <= 0): ?>
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-100" role="alert">
            <div class="flex items-start gap-3"><i class="bi bi-speedometer mt-0.5 text-red-300"></i><div><div class="font-medium"><?php echo $e($t('โควตารีเซ็ต 24 ชั่วโมงครบแล้ว', 'The 24-hour reset quota is exhausted.')); ?></div><div class="mt-1 text-sm text-red-200/80"><?php echo $e($quotaResetAt !== '' ? $t('สิทธิ์ถัดไปจะคืนเวลา ', 'The next slot returns at ') . $quotaResetAt : $t('ระบบจะคืนสิทธิ์อัตโนมัติเมื่อคำขอที่เก่าที่สุดครบ 24 ชั่วโมง', 'A slot returns automatically when the oldest request reaches 24 hours.')); ?></div></div></div>
        </div>
    <?php endif; ?>

    <section class="glass rounded-2xl p-5 md:p-7">
        <div class="mx-auto max-w-3xl">
            <div class="mb-5 flex items-center gap-3"><div class="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-500/15 text-orange-300"><i class="bi bi-arrow-counterclockwise text-xl"></i></div><div><h2 class="text-xl font-semibold text-white"><?php echo $e($t('วางคีย์แล้วรีเซ็ตทันที', 'Paste and reset immediately')); ?></h2><p class="text-xs text-gray-500"><?php echo $e($t('ไม่สแกนรายการคีย์ของผู้ให้บริการก่อนส่ง จึงเร็วกว่าเดิมมาก', 'No provider inventory scan is performed before reset.')); ?></p></div></div>
            <form id="quickResetForm" method="post" action="key_reset_action.php" class="space-y-4" data-key-reset-ajax="1" data-no-page-loader="1">
                <?php echo csrfField(); ?>
                <input type="hidden" name="reset_action" value="reset_key">
                <input type="hidden" name="provider" value="xchetos">
                <input id="clientRequestId" type="hidden" name="client_request_id" value="">
                <div>
                    <label for="licenseKey" class="mb-2 block text-sm text-gray-300"><?php echo $e($t('License Key ของลูกค้า', 'Customer License Key')); ?></label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input id="licenseKey" name="license_key" type="text" required maxlength="255" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="text" class="mono-wrap min-w-0 flex-1 rounded-xl border border-white/10 bg-black/30 px-4 py-3 text-green-300 outline-none focus:border-orange-500/60" placeholder="22-4-3D-F95C20BE...">
                        <button id="pasteKeyButton" type="button" class="rounded-xl bg-white/10 px-4 py-3 text-sm text-gray-200 hover:bg-white/15"><i class="bi bi-clipboard-plus mr-2"></i><?php echo $e($t('วาง', 'Paste')); ?></button>
                    </div>
                    <div id="detectedPolicy" class="mt-2 text-xs text-gray-500"><?php echo $e($t('ระบบจะอ่านจำนวนวันจากคีย์และกำหนดสิทธิ์ให้อัตโนมัติ', 'The duration and allowance are detected automatically.')); ?></div>
                </div>
                <button id="resetSubmitButton" type="submit" <?php echo $providerReady && $quotaAvailable && $quotaRemaining > 0 ? '' : 'disabled'; ?> class="w-full rounded-xl bg-orange-500 px-5 py-3.5 font-semibold text-white shadow-lg shadow-orange-500/10 hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-40"><i id="resetButtonIcon" class="bi bi-lightning-charge-fill mr-2"></i><span id="resetButtonText"><?php echo $e($t('รีเซ็ตคีย์', 'Reset key')); ?></span></button>
                <div id="resetInlineBusy" class="key-reset-inline-busy items-center justify-center gap-2 rounded-xl border border-cyan-500/20 bg-cyan-500/10 px-4 py-3 text-sm text-cyan-100" role="status" aria-live="polite"><i class="bi bi-arrow-repeat spin"></i><span id="resetInlineBusyText"><?php echo $e($t('กำลังติดต่อเซิร์ฟเวอร์...', 'Contacting the server...')); ?></span></div>
                <p class="text-center text-xs text-gray-500"><?php echo $e($t('กดครั้งเดียวแล้วรอผล ห้ามกดซ้ำหากอินเทอร์เน็ตหลุด เพราะคำขอแรกอาจสำเร็จแล้ว', 'Submit once and wait. Do not retry immediately after a network interruption.')); ?></p>
            </form>
        </div>
    </section>

    <section class="glass overflow-hidden rounded-xl">
        <div class="border-b border-white/10 p-5"><h2 class="text-lg font-semibold text-white"><i class="bi bi-clock-history mr-2 text-orange-300"></i><?php echo $e($t('ประวัติรีเซ็ตล่าสุด', 'Recent Reset History')); ?></h2></div>
        <div class="overflow-x-auto p-5">
            <table class="w-full min-w-[820px] text-sm">
                <thead><tr class="border-b border-white/10 text-left text-gray-500"><th class="px-3 py-3"><?php echo $e($t('คีย์', 'Key')); ?></th><th class="px-3 py-3"><?php echo $e($t('อายุ/สิทธิ์', 'Duration / limit')); ?></th><th class="px-3 py-3"><?php echo $e($t('ผล', 'Result')); ?></th><th class="px-3 py-3"><?php echo $e($t('เวลา', 'Time')); ?></th><th class="px-3 py-3">Ref</th></tr></thead>
                <tbody id="resetHistoryBody">
                <?php if (empty($history)): ?>
                    <tr id="emptyHistoryRow"><td colspan="5" class="px-3 py-10 text-center text-gray-500"><?php echo $e($t('ยังไม่มีประวัติการรีเซ็ต', 'No reset history yet.')); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($history as $item): ?>
                        <?php $status = (string) ($item['status'] ?? 'failed'); $days = max(0, (int) ($item['key_duration_days'] ?? 0)); $limit = max(1, (int) ($item['key_reset_limit'] ?? 2)); ?>
                        <tr class="border-b border-white/5">
                            <td class="mono-wrap px-3 py-3 text-gray-300"><?php echo $e($item['key_masked'] ?: '-'); ?></td>
                            <td class="px-3 py-3 text-gray-300"><?php echo $days > 0 ? $days . 'D' : '-'; ?> <span class="text-gray-500">· <?php echo $limit; ?> <?php echo $e($t('ครั้ง', 'resets')); ?></span></td>
                            <td class="px-3 py-3"><span class="rounded-full border px-2 py-1 text-xs <?php echo keyResetStatusClass($status); ?>"><?php echo $e(strtoupper($status)); ?></span><div class="mt-1 text-xs text-gray-500"><?php echo $e($item['result_code'] ?: '-'); ?></div></td>
                            <td class="whitespace-nowrap px-3 py-3 text-gray-400"><?php echo $e($item['completed_at'] ?: $item['created_at'] ?: '-'); ?></td>
                            <td class="mono-wrap px-3 py-3 text-xs text-gray-500"><?php echo $e(substr((string) ($item['request_id'] ?? '-'), 0, 12)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
const resetMessages = <?php echo json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const localeText = <?php echo json_encode([
    'resetting' => $t('กำลังรีเซ็ต...', 'Resetting...'),
    'reset' => $t('รีเซ็ตคีย์', 'Reset key'),
    'remaining' => $t('เหลือ ', 'Remaining '),
    'nextReturn' => $t('สิทธิ์ถัดไปคืนเวลา ', 'Next slot returns at '),
    'daysLimit' => $t('%d วัน · รีเซ็ตได้สูงสุด %l ครั้ง', '%d days · up to %l resets'),
    'unknownLimit' => $t('ไม่พบจำนวนวันในคีย์ · ใช้สิทธิ์พื้นฐาน 2 ครั้ง', 'No duration detected · fallback allowance is 2'),
    'usage' => $t('ใช้สิทธิ์ ', 'Usage '),
    'wait' => $t('กรุณารอประมาณ ', 'Wait about '),
    'seconds' => $t(' วินาที', ' seconds'),
    'checkingStatus' => $t('การตอบกลับของหน้าจอขาด ระบบกำลังตรวจผลจาก Log ให้อัตโนมัติ...', 'The page response was interrupted. The server log is being checked automatically...'),
    'networkNotFound' => $t('ไม่พบคำขอในเซิร์ฟเวอร์ คำขอยังไม่ถูกส่ง กรุณาตรวจอินเทอร์เน็ตแล้วลองใหม่', 'The request was not found on the server. It was not submitted; check your connection and retry.'),
    'uiRecovered' => $t('เซิร์ฟเวอร์ทำงานสำเร็จ แต่ส่วนแสดงผลมีปัญหาเล็กน้อย ผลด้านล่างเป็นข้อมูลจริงจากเซิร์ฟเวอร์', 'The server completed the request, but the page display had a minor error. The result below is authoritative.'),
    'copyDenied' => $t('เบราว์เซอร์ไม่อนุญาตให้อ่านคลิปบอร์ด กรุณากดค้างแล้ววางในช่อง', 'Clipboard access was denied. Press and hold to paste into the field.'),
    'successTitle' => $t('รีเซ็ตสำเร็จ', 'Reset completed'),
    'failedTitle' => $t('รีเซ็ตไม่สำเร็จ', 'Reset failed'),
    'unknownTitle' => $t('ผลยังไม่แน่ชัด', 'Result is uncertain'),
    'processingTitle' => $t('กำลังดำเนินการ', 'Processing'),
    'product' => $t('สินค้า: ', 'Product: '),
    'keyLabel' => $t('คีย์: ', 'Key: '),
    'keyUsage' => $t('สิทธิ์คีย์: ', 'Key usage: '),
    'dailyQuota' => $t('โควตา 24 ชม.: ', '24-hour quota: '),
    'remainingWord' => $t(' · เหลือ ', ' · remaining '),
    'close' => $t('ปิดการแจ้งเตือน', 'Close notification'),
    'copySupport' => $t('คัดลอกข้อมูลแจ้งปัญหา', 'Copy support details'),
    'copied' => $t('คัดลอกแล้ว', 'Copied'),
    'copyFailed' => $t('คัดลอกไม่ได้ กรุณากดค้างที่ Ref', 'Copy failed. Press and hold the reference ID.'),
    'requestTimeout' => $t('เซิร์ฟเวอร์ตอบช้าเกินกำหนด ระบบหยุดรอแล้วและกำลังตรวจ Log ด้วย Ref เดิม', 'The server took too long. Waiting stopped and the same reference is being checked in the log.'),
    'contactingServer' => $t('กำลังติดต่อเซิร์ฟเวอร์...', 'Contacting the server...'),
    'checkingLog' => $t('กำลังตรวจสอบผลจาก SQL Log...', 'Checking the SQL log...'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const serverFlashPayload = <?php echo json_encode($flash ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

const form = document.getElementById('quickResetForm');
const keyInput = document.getElementById('licenseKey');
const submitButton = document.getElementById('resetSubmitButton');
const buttonIcon = document.getElementById('resetButtonIcon');
const buttonText = document.getElementById('resetButtonText');
const resultBox = document.getElementById('ajaxResult');
const requestIdInput = document.getElementById('clientRequestId');
const inlineBusy = document.getElementById('resetInlineBusy');
const inlineBusyText = document.getElementById('resetInlineBusyText');
const appliedRequestIds = new Set();
let requestRunning = false;
let requestWatchdog = 0;

// Do not read form.action here. A form control named "action" becomes a
// named property of HTMLFormElement and can replace form.action with an
// HTMLInputElement. That browser behavior produced requests to
// /reseller/[object%20HTMLInputElement] in V10/V11.
function resolveResetEndpoint() {
    const rawEndpoint = form.getAttribute('action') || 'key_reset_action.php';
    const endpoint = new URL(rawEndpoint, window.location.href);
    if (endpoint.origin !== window.location.origin) {
        throw new Error('cross_origin_reset_endpoint');
    }
    return endpoint.toString();
}

const resetEndpoint = resolveResetEndpoint();

function createRequestId() {
    try {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    } catch (_) {
        const seed = String(Date.now()) + Math.random() + navigator.userAgent;
        let out = '';
        for (let index = 0; index < 32; index++) {
            out += Math.floor(Math.random() * 16).toString(16);
        }
        return out;
    }
}

function parseJsonDocument(text) {
    const clean = String(text || '').replace(/^\uFEFF/, '').trim();
    if (!clean) throw new Error('empty_response');
    try {
        return JSON.parse(clean);
    } catch (_) {
        // Some shared hosts print a warning before JSON. V11 clears that output
        // server-side, but this fallback keeps older PHP/FPM layers usable.
        const start = clean.indexOf('{');
        const end = clean.lastIndexOf('}');
        if (start >= 0 && end > start) return JSON.parse(clean.slice(start, end + 1));
        throw new Error('invalid_json');
    }
}

function wait(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
}

async function copyText(value) {
    const text = String(value || '');
    if (!text) return false;
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch (_) {}
    try {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const copied = document.execCommand('copy');
        area.remove();
        return copied;
    } catch (_) {
        return false;
    }
}

function buildSupportReport(data, message = '') {
    const report = {
        generated_at: new Date().toISOString(),
        page: window.location.pathname,
        status: String(data?.status || (data?.success ? 'success' : 'failed')),
        success: Boolean(data?.success),
        code: String(data?.code || ''),
        request_stage: String(data?.request_stage || ''),
        request_id: String(data?.request_id || ''),
        message: String(message || ''),
        product_name: String(data?.product_name || ''),
        key_masked: String(data?.key_masked || ''),
        provider_attempted: Boolean(data?.provider_attempted),
        wait_seconds: Number(data?.wait_seconds || 0),
        daily_used: Number(data?.daily_used || 0),
        daily_limit: Number(data?.daily_limit || 0),
        daily_remaining: Number(data?.daily_remaining || 0),
        key_reset_count: Number(data?.key_reset_count || 0),
        key_reset_limit: Number(data?.key_reset_limit || 0),
        key_reset_remaining: Number(data?.key_reset_remaining || 0),
        server_time: String(data?.server_time || ''),
        browser: navigator.userAgent,
    };
    return JSON.stringify(report, null, 2);
}

function attachSupportCopyButton(container, data, message = '') {
    if (!container || container.querySelector('[data-support-copy]')) return;
    const actions = document.createElement('div');
    actions.className = 'mt-3 flex flex-wrap items-center gap-2';
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.supportCopy = '1';
    button.className = 'rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs font-medium hover:bg-white/15';
    button.innerHTML = '<i class="bi bi-copy mr-1.5"></i><span></span>';
    button.querySelector('span').textContent = localeText.copySupport;
    button.addEventListener('click', async () => {
        const ok = await copyText(buildSupportReport(data, message));
        const label = button.querySelector('span');
        if (label) label.textContent = ok ? localeText.copied : localeText.copyFailed;
        window.setTimeout(() => { if (label) label.textContent = localeText.copySupport; }, 1800);
    });
    actions.appendChild(button);
    container.appendChild(actions);
}

function detectPolicy(value) {
    const normalized = String(value || '').replace(/[\s\u200B\uFEFF]+/g, '');
    const parts = normalized.split('-');
    const match = parts[2] ? parts[2].match(/^(\d{1,5})D$/i) : null;
    const days = match ? Math.max(0, parseInt(match[1], 10) || 0) : 0;
    let limit = 2;
    if (days >= 2 && days <= 3) limit = 4;
    else if (days >= 4) limit = 5;
    return {normalized, days, limit};
}

function updateDetectedPolicy() {
    const info = detectPolicy(keyInput.value);
    const target = document.getElementById('detectedPolicy');
    if (!info.normalized) return;
    target.textContent = info.days > 0
        ? localeText.daysLimit.replace('%d', info.days).replace('%l', info.limit)
        : localeText.unknownLimit;
}
keyInput.addEventListener('input', updateDetectedPolicy);

function resultClass(status, success) {
    if (success) return 'border-green-500/30 bg-green-500/10 text-green-300';
    if (status === 'unknown') return 'border-yellow-500/30 bg-yellow-500/10 text-yellow-200';
    if (status === 'processing') return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-200';
    return 'border-red-500/30 bg-red-500/10 text-red-300';
}

function toastClass(status, success) {
    if (success) return 'border-green-500/40 bg-green-950/95 text-green-100';
    if (status === 'unknown') return 'border-yellow-500/40 bg-yellow-950/95 text-yellow-100';
    if (status === 'processing') return 'border-cyan-500/40 bg-cyan-950/95 text-cyan-100';
    return 'border-red-500/40 bg-red-950/95 text-red-100';
}

function toastTitle(status, success) {
    if (success) return localeText.successTitle;
    if (status === 'unknown') return localeText.unknownTitle;
    if (status === 'processing') return localeText.processingTitle;
    return localeText.failedTitle;
}

function toastIcon(status, success) {
    if (success) return 'bi-check-circle-fill';
    if (status === 'unknown') return 'bi-exclamation-triangle-fill';
    if (status === 'processing') return 'bi-hourglass-split';
    return 'bi-x-octagon-fill';
}

function closeResetToast(node) {
    if (!node || node.classList.contains('is-leaving')) return;
    node.classList.add('is-leaving');
    window.setTimeout(() => {
        node.classList.add('hidden');
        node.classList.remove('is-leaving');
    }, 240);
}

function buildResultDetail(label, value, mono = false) {
    if (value === '' || value === null || value === undefined) return null;
    const line = document.createElement('div');
    if (mono) line.className = 'mono-wrap';
    const prefix = document.createElement('span');
    prefix.className = 'opacity-70';
    prefix.textContent = label;
    line.append(prefix, document.createTextNode(String(value)));
    return line;
}

let resultCloseTimer = 0;
function showResult(data, overrideMessage = '') {
    const serverFlash = document.getElementById('serverFlashResult');
    if (serverFlash && !serverFlash.classList.contains('hidden')) closeResetToast(serverFlash);
    const status = String(data.status || (data.success ? 'success' : 'failed'));
    const success = Boolean(data.success);
    const message = overrideMessage || resetMessages[data.code] || resetMessages.provider_unknown;
    window.clearTimeout(resultCloseTimer);

    resultBox.className = 'reset-toast pointer-events-auto relative w-full max-w-lg overflow-hidden rounded-2xl border ' + toastClass(status, success);
    resultBox.replaceChildren();

    const content = document.createElement('div');
    content.className = 'flex items-start gap-3 p-4 pr-12';
    const iconWrap = document.createElement('div');
    iconWrap.className = 'mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10';
    const icon = document.createElement('i');
    icon.className = 'bi ' + toastIcon(status, success) + ' text-xl';
    iconWrap.appendChild(icon);

    const body = document.createElement('div');
    body.className = 'min-w-0 flex-1';
    const title = document.createElement('div');
    title.className = 'font-semibold';
    title.textContent = toastTitle(status, success);
    const messageNode = document.createElement('div');
    messageNode.className = 'mt-1 text-sm leading-6 opacity-95';
    messageNode.textContent = message;
    const details = document.createElement('div');
    details.className = 'mt-3 grid gap-1.5 text-xs opacity-80';

    const detailNodes = [];
    if (data.product_name) detailNodes.push(buildResultDetail(localeText.product, data.product_name));
    if (data.key_masked) detailNodes.push(buildResultDetail(localeText.keyLabel, data.key_masked, true));
    if (Number(data.key_reset_limit) > 0) {
        detailNodes.push(buildResultDetail(
            localeText.keyUsage,
            Number(data.key_reset_count || 0) + ' / ' + Number(data.key_reset_limit) + localeText.remainingWord + Number(data.key_reset_remaining || 0)
        ));
    }
    if (Number(data.daily_limit) > 0) {
        detailNodes.push(buildResultDetail(
            localeText.dailyQuota,
            Number(data.daily_used || 0) + ' / ' + Number(data.daily_limit) + localeText.remainingWord + Number(data.daily_remaining || 0)
        ));
    }
    if (data.daily_reset_at) detailNodes.push(buildResultDetail(localeText.nextReturn, data.daily_reset_at));
    if (Number(data.wait_seconds) > 0) detailNodes.push(buildResultDetail('', localeText.wait + Number(data.wait_seconds) + localeText.seconds));
    if (data.request_id) detailNodes.push(buildResultDetail('Ref: ', data.request_id, true));
    detailNodes.filter(Boolean).forEach(node => details.appendChild(node));

    body.append(title, messageNode);
    if (details.childNodes.length > 0) body.appendChild(details);
    attachSupportCopyButton(body, data, message);
    content.append(iconWrap, body);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'absolute right-3 top-3 rounded-lg p-2 opacity-70 hover:bg-white/10 hover:opacity-100';
    closeButton.setAttribute('aria-label', localeText.close);
    closeButton.innerHTML = '<i class="bi bi-x-lg"></i>';
    closeButton.addEventListener('click', () => closeResetToast(resultBox));

    resultBox.append(content, closeButton);
    if (success) {
        const progress = document.createElement('div');
        progress.className = 'reset-toast-progress h-1 bg-current opacity-45';
        resultBox.appendChild(progress);
        resultCloseTimer = window.setTimeout(() => closeResetToast(resultBox), 8000);
    }
    resultBox.classList.remove('hidden');
}

document.querySelectorAll('[data-close-reset-toast]').forEach((button) => {
    button.addEventListener('click', () => closeResetToast(button.closest('.reset-toast')));
});
const serverFlashResult = document.getElementById('serverFlashResult');
if (serverFlashResult) {
    const autoClose = Number(serverFlashResult.dataset.autoClose || 0);
    if (autoClose > 0) window.setTimeout(() => closeResetToast(serverFlashResult), autoClose);
    const serverBody = serverFlashResult.querySelector('.min-w-0.flex-1');
    const serverMessage = serverFlashResult.querySelector('.mt-1.text-sm')?.textContent || '';
    attachSupportCopyButton(serverBody, serverFlashPayload, serverMessage);
}

function updateQuota(data) {
    const limit = Number(data.daily_limit || 0);
    if (limit <= 0) return;
    const used = Number(data.daily_used || 0);
    const hasRemaining = data.daily_remaining !== undefined && data.daily_remaining !== null && data.daily_remaining !== '';
    const remaining = Math.max(0, hasRemaining ? Number(data.daily_remaining) : (limit - used));
    document.getElementById('quotaUsed').textContent = used;
    document.getElementById('quotaLimit').textContent = limit;
    document.getElementById('quotaRemaining').textContent = remaining;
    document.getElementById('quotaProgress').style.width = Math.min(100, Math.round((used / limit) * 100)) + '%';
    const badge = document.getElementById('quotaBadge');
    badge.className = 'rounded-full border px-3 py-1 text-xs ' + (remaining > 0 ? 'border-green-500/30 bg-green-500/10 text-green-300' : 'border-red-500/30 bg-red-500/10 text-red-300');
    if (data.daily_reset_at) document.getElementById('quotaResetAt').textContent = localeText.nextReturn + data.daily_reset_at;
    if (remaining <= 0) submitButton.disabled = true;
}

function appendHistory(data) {
    if (!data.request_id) return;
    const requestKey = String(data.request_id);
    if (appliedRequestIds.has(requestKey)) return;
    appliedRequestIds.add(requestKey);
    const body = document.getElementById('resetHistoryBody');
    const empty = document.getElementById('emptyHistoryRow');
    if (empty) empty.remove();
    const row = document.createElement('tr');
    row.className = 'border-b border-white/5';
    const status = String(data.status || 'failed').toUpperCase();
    const statusClass = resultClass(data.status, data.success);
    const duration = Number(data.key_duration_days || 0) > 0 ? Number(data.key_duration_days) + 'D' : '-';
    row.innerHTML = '<td class="mono-wrap px-3 py-3 text-gray-300"></td>' +
        '<td class="px-3 py-3 text-gray-300"></td>' +
        '<td class="px-3 py-3"><span class="rounded-full border px-2 py-1 text-xs ' + statusClass + '"></span><div class="mt-1 text-xs text-gray-500"></div></td>' +
        '<td class="whitespace-nowrap px-3 py-3 text-gray-400"></td>' +
        '<td class="mono-wrap px-3 py-3 text-xs text-gray-500"></td>';
    row.children[0].textContent = data.key_masked || '-';
    row.children[1].textContent = duration + ' · ' + Number(data.key_reset_limit || 2);
    row.children[2].children[0].textContent = status;
    row.children[2].children[1].textContent = data.code || '-';
    row.children[3].textContent = data.server_time || '-';
    row.children[4].textContent = String(data.request_id).slice(0, 12);
    body.prepend(row);
}


function applyServerResult(data) {
    showResult(data);
    try {
        updateQuota(data);
        appendHistory(data);
    } catch (uiError) {
        console.error('Key reset UI update failed:', uiError);
        // Never replace a real server success with a fake network warning.
        showResult(data, data.success ? localeText.uiRecovered : '');
    }
}

async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 12000) {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, {...options, signal: controller.signal});
        const responseText = await response.text();
        return {response, data: parseJsonDocument(responseText)};
    } finally {
        window.clearTimeout(timeoutId);
    }
}

async function recoverRequestStatus(requestId) {
    if (!requestId) return null;
    setInlineBusy(true, localeText.checkingLog);

    // Only use status recovery after the HTTP response was interrupted or invalid.
    // A valid server failure is final and must never be replaced by a fake
    // "processing" state merely because a later DOM update threw an exception.
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await wait(1000 + attempt * 500);
        try {
            const url = new URL('key_reset_status.php', window.location.href);
            url.searchParams.set('provider', 'xchetos');
            url.searchParams.set('request_id', requestId);
            url.searchParams.set('_', String(Date.now()));
            const {response, data} = await fetchJsonWithTimeout(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {'Accept':'application/json'},
                cache: 'no-store'
            }, 9000);
            if (response.status === 401 || data.code === 'session_expired') return data;
            if (data.found && data.status !== 'processing') return data;
            if (data.found && data.status === 'processing') {
                showResult(data, localeText.checkingStatus);
            }
        } catch (statusError) {
            console.error('Key reset status lookup failed:', statusError);
        }
    }
    return null;
}

function safelyApplyServerResult(data) {
    try {
        applyServerResult(data);
    } catch (uiError) {
        console.error('Key reset result rendering failed:', uiError);
        // Keep the authoritative server result visible with the smallest possible
        // DOM path. A rendering problem must not trigger another provider request.
        try {
            showResult(data, data.success ? localeText.uiRecovered : '');
        } catch (_) {
            window.alert(resetMessages[data.code] || resetMessages.server_error);
        }
    } finally {
    }
}

form.addEventListener('submit', async (event) => {
    if (!window.fetch || !window.FormData) return;
    event.preventDefault();
    if (requestRunning || !form.reportValidity()) return;

    const info = detectPolicy(keyInput.value);
    keyInput.value = info.normalized;
    const requestId = createRequestId();
    requestIdInput.value = requestId;

    requestRunning = true;
    submitButton.disabled = true;
    buttonIcon.className = 'bi bi-arrow-repeat spin mr-2';
    buttonText.textContent = localeText.resetting;
    setInlineBusy(true, localeText.contactingServer);

    let serverData = null;
    let responseInterrupted = false;
    const requestController = new AbortController();
    window.clearTimeout(requestWatchdog);
    requestWatchdog = window.setTimeout(() => requestController.abort(), 38000);

    try {
        const requestBody = new FormData(form);
        requestBody.set('response_format', 'json');
        requestBody.set('reset_action', 'reset_key');

        let response;
        let responseText;
        try {
            response = await fetch(resetEndpoint, {
                method: 'POST',
                body: requestBody,
                credentials: 'same-origin',
                headers: {'Accept':'application/json'},
                cache: 'no-store',
                redirect: 'follow',
                signal: requestController.signal
            });
            responseText = await response.text();
            serverData = parseJsonDocument(responseText);
            if (!serverData.request_id) serverData.request_id = requestId;
        } catch (transportError) {
            responseInterrupted = true;
            console.error('Key reset transport/JSON failure:', transportError);
            showResult(
                {success:false,status:'processing',code:'processing',request_id:requestId},
                transportError?.name === 'AbortError' ? localeText.requestTimeout : localeText.checkingStatus
            );
        }

        if (serverData) {
            // JSON received from the endpoint is authoritative even when the HTTP
            // status is 4xx/5xx. Do not enter recovery for a valid error response.
            safelyApplyServerResult(serverData);
        } else if (responseInterrupted) {
            setInlineBusy(true, localeText.checkingLog);
            serverData = await recoverRequestStatus(requestId);
            if (serverData) {
                safelyApplyServerResult(serverData);
            } else {
                showResult(
                    {success:false,status:'failed',code:'request_not_found',request_id:requestId},
                    localeText.networkNotFound
                );
            }
        }
    } finally {
        window.clearTimeout(requestWatchdog);
        requestController.abort();
        requestRunning = false;
        setInlineBusy(false);
        buttonIcon.className = 'bi bi-lightning-charge-fill mr-2';
        buttonText.textContent = localeText.reset;
        const remaining = Number(document.getElementById('quotaRemaining').textContent || 0);
        submitButton.disabled = <?php echo $providerReady && $quotaAvailable ? 'false' : 'true'; ?> || remaining <= 0;
        if (serverData && serverData.success) keyInput.value = '';
        requestIdInput.value = '';
        keyInput.focus();
    }
});

document.getElementById('pasteKeyButton').addEventListener('click', async () => {
    try {
        if (!navigator.clipboard || !navigator.clipboard.readText) throw new Error('unsupported');
        keyInput.value = await navigator.clipboard.readText();
        updateDetectedPolicy();
        keyInput.focus();
    } catch (_) {
        keyInput.focus();
        showResult({success:false,status:'failed',code:'invalid_request'}, localeText.copyDenied);
    }
});
</script>
</body>
</html>
