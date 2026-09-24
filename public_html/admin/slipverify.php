<?php
require_once '../includes/auth.php';
require_once '../includes/slip_nearby.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$lang = getAppLang();
$isTh = $lang !== 'en';
$error = '';
$success = '';
$probeResult = null;

function slipVerifyAdminPostString(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_POST) || !is_scalar($_POST[$key])) return $default;
    return (string) $_POST[$key];
}

function slipVerifyAdminCleanText(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return $value;
}

function slipVerifyAdminReadImage(): array
{
    if (!isset($_FILES['slip_image']) || !is_array($_FILES['slip_image'])) {
        return ['success' => false, 'message' => 'กรุณาเลือกรูปสลิปสำหรับทดสอบ'];
    }
    $file = $_FILES['slip_image'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => $uploadError === UPLOAD_ERR_NO_FILE ? 'กรุณาเลือกรูปสลิปสำหรับทดสอบ' : 'อัปโหลดรูปสลิปไม่สำเร็จ'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => 'ไฟล์สลิปไม่ถูกต้องหรือมีขนาดเกิน 4MB'];
    }
    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => 'ไม่สามารถอ่านไฟล์สลิปได้'];
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info)) {
        return ['success' => false, 'message' => 'ไฟล์ที่เลือกไม่ใช่รูปภาพที่ถูกต้อง'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_buffer($finfo, $bytes) : false;
        if ($finfo) finfo_close($finfo);
        if (is_string($detected)) $mime = $detected;
    }
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['success' => false, 'message' => 'รองรับเฉพาะ JPEG, PNG และ WebP'];
    }
    return ['success' => true, 'bytes' => $bytes, 'mime' => $mime, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = slipVerifyAdminPostString('action');

    if ($action === 'save_nearby_settings') {
        $submittedApiKey = nearbySlipValidateApiKey(slipVerifyAdminPostString('nearby_api_key'));
        $receiverName = slipVerifyAdminCleanText(slipVerifyAdminPostString('nearby_receiver_name'), 150);
        $bankCode = slipVerifyAdminCleanText(slipVerifyAdminPostString('nearby_bank_code'), 32);
        $accountNo = slipVerifyAdminCleanText(slipVerifyAdminPostString('nearby_account_no'), 100);
        $existingApiKey = nearbySlipValidateApiKey((string) getSetting('slipverify_nearby_api_key', ''));
        $effectiveApiKey = $submittedApiKey !== '' ? $submittedApiKey : $existingApiKey;

        if ($effectiveApiKey === '') {
            $error = $isTh ? 'กรุณาใส่ SlipVerify API Key ก่อนบันทึก' : 'Enter a SlipVerify API key before saving.';
        } elseif ($bankCode !== '' && !preg_match('/^[A-Za-z0-9_-]{2,32}$/D', $bankCode)) {
            $error = $isTh ? 'รหัสธนาคารต้องเป็นตัวเลข/ตัวอักษร เช่น 004 หรือ KBANK' : 'Bank code must look like 004 or KBANK.';
        } elseif ($accountNo !== '' && strlen(preg_replace('/\D+/', '', $accountNo)) < 3) {
            $error = $isTh ? 'เลขบัญชีสำหรับตรวจผู้รับสั้นเกินไป' : 'Receiver account check is too short.';
        } else {
            global $conn;
            $conn->begin_transaction();
            try {
                if ($submittedApiKey !== '' && !upsertSetting('slipverify_nearby_api_key', $submittedApiKey)) {
                    throw new RuntimeException('Unable to save SlipVerify API key');
                }
                foreach ([
                    ['slipverify_nearby_receiver_name', $receiverName],
                    ['slipverify_nearby_bank_code', strtoupper($bankCode)],
                    ['slipverify_nearby_account_no', $accountNo],
                    ['slipverify_nearby_token', ''],
                ] as $write) {
                    if (!upsertSetting($write[0], $write[1])) throw new RuntimeException('Unable to save SlipVerify setting');
                }
                $conn->commit();
                $success = $isTh ? 'บันทึก SlipVerify API Key และข้อมูลตรวจผู้รับแล้ว' : 'SlipVerify API key and receiver checks saved.';
                logHistory((int) $_SESSION['user_id'], 'update_slipverify_nearby', 'Updated SlipVerify Nearby API key settings');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('SlipVerify settings save failed: ' . $e->getMessage());
                $error = $isTh ? 'บันทึก SlipVerify ไม่สำเร็จ' : 'Unable to save SlipVerify settings.';
            }
        }
    } elseif ($action === 'clear_nearby_api_key') {
        $ok = upsertSetting('slipverify_nearby_api_key', '');
        upsertSetting('slipverify_nearby_token', '');
        if ($ok) {
            $success = $isTh ? 'ล้าง SlipVerify API Key แล้ว' : 'SlipVerify API key cleared.';
            logHistory((int) $_SESSION['user_id'], 'clear_slipverify_nearby_api_key', 'Cleared SlipVerify Nearby API key');
        } else {
            $error = $isTh ? 'ล้าง API Key ไม่สำเร็จ' : 'Unable to clear API key.';
        }
    } elseif ($action === 'probe_nearby_slip') {
        $apiKey = nearbySlipValidateApiKey((string) getSetting('slipverify_nearby_api_key', ''));
        if ($apiKey === '') {
            $error = $isTh ? 'ยังไม่ได้บันทึก SlipVerify API Key' : 'SlipVerify API key is not configured.';
        } else {
            $image = slipVerifyAdminReadImage();
            if (empty($image['success'])) {
                $error = (string) ($image['message'] ?? 'Invalid slip image');
            } else {
                $receiverOptions = [
                    'expected_receiver_name' => (string) getSetting('slipverify_nearby_receiver_name', ''),
                    'expected_bank_code' => (string) getSetting('slipverify_nearby_bank_code', ''),
                    'expected_account_no' => (string) getSetting('slipverify_nearby_account_no', ''),
                ];
                $started = microtime(true);
                $probeResult = nearbySlipVerifyV2($apiKey, (string) $image['bytes'], (string) $image['mime'], $receiverOptions, 45000);
                $probeResult['admin_probe_duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                $probeResult['image_sha256'] = (string) $image['sha256'];
                $probeResult['image_bytes'] = (int) $image['size'];
                unset($image['bytes']);
                if (!empty($probeResult['success'])) {
                    $success = $isTh ? 'SlipVerify ตรวจสลิปทดสอบสำเร็จ โดยไม่มีการเติมเงินจริง' : 'SlipVerify test succeeded. No wallet credit was performed.';
                    logHistory((int) $_SESSION['user_id'], 'probe_slipverify_nearby_success', 'SlipVerify Nearby API-key admin probe succeeded');
                } else {
                    $error = ($isTh ? 'ทดสอบ SlipVerify ไม่ผ่าน: ' : 'SlipVerify test failed: ') . (string) ($probeResult['message'] ?? $probeResult['error_code'] ?? 'unknown');
                    logHistory((int) $_SESSION['user_id'], 'probe_slipverify_nearby_failed', 'SlipVerify Nearby admin probe failed: ' . substr((string) ($probeResult['error_code'] ?? 'unknown'), 0, 80));
                }
            }
        }
    }
}

$apiKeyConfigured = nearbySlipValidateApiKey((string) getSetting('slipverify_nearby_api_key', '')) !== '';
$legacyTokenConfigured = trim((string) getSetting('slipverify_nearby_token', '')) !== '';
$receiverName = (string) getSetting('slipverify_nearby_receiver_name', (string) getSetting('easyslip_receiver_name', ''));
$bankCode = (string) getSetting('slipverify_nearby_bank_code', '');
$accountNo = (string) getSetting('slipverify_nearby_account_no', (string) getSetting('easyslip_account_number', ''));
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isTh ? 'ตั้งค่า SlipVerify API' : 'SlipVerify API Settings'; ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}pre{white-space:pre-wrap;word-break:break-word}</style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="p-4 md:p-6 max-w-5xl mx-auto space-y-6">
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold"><i class="bi bi-receipt-cutoff text-emerald-300 mr-2"></i>SlipVerify / Nearby API</h1>
            <p class="text-gray-400 text-sm mt-1"><?php echo $isTh ? 'ใช้ API Key แบบ X-API-Key ตามระบบใหม่ และทดสอบแยกจากระบบเติมเงินจริง' : 'Uses the new X-API-Key authentication and probes separately from the live wallet flow.'; ?></p>
        </div>
        <a href="settings.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10"><i class="bi bi-gear"></i><?php echo $isTh ? 'กลับหน้าตั้งค่าระบบ' : 'Back to system settings'; ?></a>
    </div>

    <?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-100"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-emerald-100"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($legacyTokenConfigured && !$apiKeyConfigured): ?><div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-100 text-sm"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $isTh ? 'พบ JWT Token เก่าที่เคยบันทึกไว้ แต่ระบบใหม่จะไม่ใช้ Token นี้ กรุณาสร้าง API Key ใหม่แล้วบันทึกด้านล่าง' : 'A legacy JWT token is stored, but it is ignored. Create and save a new API key below.'; ?></div><?php endif; ?>

    <section class="glass rounded-2xl p-5 md:p-6 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold"><i class="bi bi-key text-amber-300 mr-2"></i><?php echo $isTh ? 'API Key และผู้รับเงิน' : 'API Key & receiver checks'; ?></h2>
                <p class="text-xs text-gray-500 mt-1">POST https://api.nearbyshop.xyz/slipVerify/v2 · Header: X-API-Key</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs <?php echo $apiKeyConfigured ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'; ?>"><?php echo $apiKeyConfigured ? ($isTh ? 'มี API Key แล้ว' : 'API key configured') : ($isTh ? 'ยังไม่มี API Key' : 'API key missing'); ?></span>
        </div>

        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4" autocomplete="off">
            <?php echo csrfField(); ?>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-300 mb-2">SlipVerify API Key</label>
                <input type="password" name="nearby_api_key" maxlength="512" autocomplete="new-password"
                       placeholder="<?php echo $apiKeyConfigured ? ($isTh ? 'เว้นว่างเพื่อใช้ API Key เดิม' : 'Leave blank to keep the existing API key') : 'nb_live_your_api_key_here'; ?>"
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 font-mono text-sm text-white">
                <p class="mt-1 text-xs text-gray-500"><?php echo $isTh ? 'ใส่เฉพาะ API Key ไม่ต้องใส่ X-API-Key: ด้านหน้า ระบบจะไม่แสดงคีย์เดิมกลับมาบนหน้าเว็บ' : 'Paste only the API key, without the X-API-Key: prefix. The stored key is never echoed back.'; ?></p>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'ชื่อผู้รับที่คาดหวัง' : 'Expected receiver name'; ?></label>
                <input type="text" name="nearby_receiver_name" maxlength="150" value="<?php echo htmlspecialchars($receiverName, ENT_QUOTES, 'UTF-8'); ?>" placeholder="สมชาย ใจดี" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'รหัสธนาคารผู้รับ' : 'Expected bank code'; ?></label>
                <input type="text" name="nearby_bank_code" maxlength="32" value="<?php echo htmlspecialchars($bankCode, ENT_QUOTES, 'UTF-8'); ?>" placeholder="004 หรือ KBANK" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 font-mono text-white">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'เลขบัญชีผู้รับ หรือเลขท้ายที่ต้องตรง' : 'Expected receiver account / suffix'; ?></label>
                <input type="text" name="nearby_account_no" maxlength="100" value="<?php echo htmlspecialchars($accountNo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="067-8-999346 หรือ 346" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 font-mono text-white">
                <p class="mt-1 text-xs text-gray-500"><?php echo $isTh ? 'ส่งข้อมูลสามช่องนี้ไปให้ SlipVerify ตรวจพร้อมสลิป เพื่อลดความเสี่ยงลูกค้าโอนผิดบัญชีหรือเอาสลิปคนอื่นมาใช้' : 'These values are sent with the slip for receiver validation.'; ?></p>
            </div>
            <div class="md:col-span-2 flex flex-wrap gap-3">
                <button type="submit" name="action" value="save_nearby_settings" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-3 font-semibold"><i class="bi bi-save mr-2"></i><?php echo $isTh ? 'บันทึก SlipVerify' : 'Save SlipVerify'; ?></button>
                <?php if ($apiKeyConfigured || $legacyTokenConfigured): ?><button type="submit" name="action" value="clear_nearby_api_key" formnovalidate onclick="return confirm('<?php echo $isTh ? 'ล้าง API Key/Token เก่าที่บันทึกไว้?' : 'Clear stored API key and legacy token?'; ?>')" class="rounded-xl border border-red-400/30 bg-red-500/10 hover:bg-red-500/20 px-5 py-3 font-semibold text-red-200"><i class="bi bi-trash mr-2"></i><?php echo $isTh ? 'ล้าง Key' : 'Clear key'; ?></button><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="glass rounded-2xl p-5 md:p-6 space-y-5">
        <div>
            <h2 class="text-lg font-semibold"><i class="bi bi-flask text-cyan-300 mr-2"></i><?php echo $isTh ? 'ทดสอบด้วยสลิปจริง แต่ไม่เติมเงินจริง' : 'Probe with a real slip, without crediting funds'; ?></h2>
            <p class="text-xs text-gray-500 mt-1"><?php echo $isTh ? 'ทดสอบ API Key, สิทธิ์ v2, OCR, transaction ref, ยอดเงิน และ receiver validation ก่อนต่อเข้าระบบฝากเงินจริง' : 'Validate the API key, v2 permission, OCR, transaction reference, amount, and receiver validation before deposit integration.'; ?></p>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="probe_nearby_slip">
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'รูปสลิปทดสอบ' : 'Test slip image'; ?></label>
                <input type="file" name="slip_image" required accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
                <p class="mt-1 text-xs text-gray-500">JPEG / PNG / WebP · max 4MB</p>
            </div>
            <div class="rounded-xl border border-cyan-400/20 bg-cyan-400/5 p-4 text-sm text-cyan-100"><i class="bi bi-shield-check mr-2"></i><?php echo $isTh ? 'ปุ่มนี้เรียก Nearby API เท่านั้น ไม่เพิ่ม balance ไม่สร้าง transaction และไม่บันทึกฝากเงิน' : 'This calls Nearby only. It does not change balance, create a transaction, or save a deposit.'; ?></div>
            <button type="submit" class="rounded-xl bg-cyan-600 hover:bg-cyan-500 px-5 py-3 font-semibold disabled:opacity-50" <?php echo $apiKeyConfigured ? '' : 'disabled'; ?>><i class="bi bi-send-check mr-2"></i><?php echo $isTh ? 'ส่งสลิปทดสอบไป SlipVerify' : 'Send test slip to SlipVerify'; ?></button>
        </form>
    </section>

    <?php if (is_array($probeResult)): ?>
    <section class="glass rounded-2xl p-5 md:p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold"><i class="bi bi-activity text-violet-300 mr-2"></i><?php echo $isTh ? 'ผลทดสอบล่าสุด' : 'Latest probe result'; ?></h2>
            <span class="rounded-full px-3 py-1 text-xs <?php echo !empty($probeResult['success']) ? 'bg-emerald-500/15 text-emerald-300' : 'bg-red-500/15 text-red-300'; ?>"><?php echo !empty($probeResult['success']) ? 'SUCCESS' : 'FAILED'; ?></span>
        </div>
        <?php if (!empty($probeResult['success']) && isset($probeResult['data']) && is_array($probeResult['data'])): $d = $probeResult['data']; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Transaction Ref</div><div class="font-mono break-all"><?php echo htmlspecialchars((string) ($d['transaction_ref'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Amount</div><div class="font-semibold text-emerald-300"><?php echo number_format((float) ($d['amount'] ?? 0), 2); ?> THB</div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Transfer Date</div><div><?php echo htmlspecialchars((string) ($d['transfer_date'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Duration</div><div><?php echo number_format((int) ($probeResult['admin_probe_duration_ms'] ?? 0)); ?> ms</div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Sender</div><div><?php echo htmlspecialchars((string) ($d['sender_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars(trim((string) ($d['sender_bank_abbr'] ?? '') . ' ' . (string) ($d['sender_account'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Receiver</div><div><?php echo htmlspecialchars((string) ($d['receiver_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars(trim((string) ($d['receiver_bank_abbr'] ?? '') . ' ' . (string) ($d['receiver_account'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-red-400/20 bg-red-500/5 p-4 text-sm text-red-100">
                <div><strong>Error:</strong> <?php echo htmlspecialchars((string) ($probeResult['error_code'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-1"><?php echo htmlspecialchars((string) ($probeResult['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (!empty($probeResult['provider_code'])): ?><div class="mt-1 text-xs text-red-200/70">Provider code: <?php echo htmlspecialchars((string) $probeResult['provider_code'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <details class="rounded-xl border border-white/10 bg-black/20 p-4"><summary class="cursor-pointer text-sm text-violet-300"><?php echo $isTh ? 'ดูผล JSON ที่ผ่านการกรองแล้ว' : 'View sanitized JSON result'; ?></summary><pre class="mt-3 text-xs text-gray-300"><?php echo htmlspecialchars(json_encode($probeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?></pre></details>
    </section>
    <?php endif; ?>

    <section class="glass rounded-2xl p-5 md:p-6 text-sm text-gray-300 space-y-4">
        <h2 class="text-lg font-semibold text-white"><i class="bi bi-info-circle text-blue-300 mr-2"></i><?php echo $isTh ? 'วิธีหาและใช้งาน API Key' : 'How to get and use the API key'; ?></h2>
        <ol class="list-decimal pl-5 space-y-2">
            <li><?php echo $isTh ? 'เข้า Developer Portal ของ xNearby แล้วเข้าสู่ระบบ' : 'Open the xNearby developer portal and sign in.'; ?></li>
            <li><?php echo $isTh ? 'สร้าง/รับ API Key และตรวจว่าคีย์มีสิทธิ์ v2' : 'Create/get an API key and make sure it has v2 permission.'; ?></li>
            <li><?php echo $isTh ? 'คัดลอก API Key รูปแบบประมาณ nb_live_... มาใส่ช่องด้านบนแล้วกดบันทึก' : 'Copy the API key (for example nb_live_...) into the field above and save.'; ?></li>
            <li><?php echo $isTh ? 'กรอกชื่อผู้รับ ธนาคาร และเลขบัญชี/เลขท้ายให้ตรงกับบัญชีร้าน' : 'Set receiver name, bank, and account/suffix to match the store account.'; ?></li>
            <li><?php echo $isTh ? 'อัปโหลดสลิปจริงในส่วนทดสอบ แล้วดู SUCCESS / error code โดยยังไม่แตะยอดเงินจริง' : 'Upload a real slip in probe mode and inspect SUCCESS/error without touching wallet funds.'; ?></li>
        </ol>
        <div class="flex flex-wrap gap-3">
            <a href="https://nearbyshop.xyz/developer" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-emerald-300 hover:text-emerald-200"><i class="bi bi-key-fill"></i>nearbyshop.xyz/developer</a>
            <a href="https://docs.nearbyshop.xyz/slip-verify.html" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-cyan-300 hover:text-cyan-200"><i class="bi bi-book"></i>SlipVerify Docs</a>
        </div>
        <p class="text-xs text-gray-500"><?php echo $isTh ? 'อย่าส่ง API Key ในแชท อย่า commit ลง GitHub และอย่าใส่ใน JavaScript ฝั่ง browser' : 'Do not paste the API key into chat, commit it to GitHub, or expose it in browser-side JavaScript.'; ?></p>
    </section>
</main>
</body>
</html>
