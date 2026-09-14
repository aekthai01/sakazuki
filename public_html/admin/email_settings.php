<?php
require_once '../includes/auth.php';
require_once '../includes/account_recovery.php';
require_once '../includes/key_history_cleanup.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$lang = getAppLang();
$isTh = $lang !== 'en';
$error = '';
$success = '';
$cleanupResult = null;
$mailResult = null;
$schemaReady = accountRecoveryEnsureSchema();

if (!$schemaReady) {
    $error = $isTh
        ? 'สร้างตารางระบบกู้รหัสผ่านหรือ Mail Log ไม่สำเร็จ กรุณาตรวจสิทธิ์ CREATE TABLE / ALTER TABLE ของ MySQL'
        : 'Unable to create the recovery or mail-log tables. Check MySQL CREATE TABLE / ALTER TABLE permissions.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';

    if (in_array($action, ['save_mail', 'diagnose_mail', 'test_mail', 'test_reset_flow'], true)) {
        $username = isset($_POST['smtp_username']) && is_scalar($_POST['smtp_username']) ? (string) $_POST['smtp_username'] : '';
        $password = isset($_POST['smtp_app_password']) && is_string($_POST['smtp_app_password']) ? $_POST['smtp_app_password'] : '';
        $normalizedSubmittedPassword = preg_replace('/\s+/', '', $password);
        $credentialSource = $normalizedSubmittedPassword !== '' ? 'newly_submitted' : 'stored';
        $fromName = isset($_POST['from_name']) && is_scalar($_POST['from_name']) ? (string) $_POST['from_name'] : '';
        $mode = isset($_POST['smtp_mode']) && is_scalar($_POST['smtp_mode']) ? (string) $_POST['smtp_mode'] : 'auto';
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';

        $knownCredentialState = (string) getSetting('recovery_smtp_credential_state', '');
        if (in_array($action, ['diagnose_mail', 'test_mail', 'test_reset_flow'], true) && $normalizedSubmittedPassword === '' && $knownCredentialState === 'invalid') {
            $save = ['success' => false, 'message' => $isTh
                ? 'App Password เดิมถูก Gmail ปฏิเสธแล้ว กรุณาวาง App Password ใหม่ 16 ตัวในช่องก่อนทดสอบ การเว้นว่างจะนำรหัสเดิมที่เสียมาทดสอบซ้ำ'
                : 'The stored App Password was rejected. Enter a newly generated 16-character App Password before testing again.'];
        } else {
            $save = accountRecoverySaveMailConfig($username, $password, $fromName, $enabled, $mode);
        }
        if (empty($save['success'])) {
            $error = (string) ($save['message'] ?? ($isTh ? 'บันทึกการตั้งค่าไม่สำเร็จ' : 'Unable to save settings.'));
        } elseif ($action === 'save_mail') {
            if (!empty($save['requires_test'])) {
                $success = $isTh ? 'บันทึกการตั้งค่าแล้ว ระบบยังปิดอยู่จนกว่าจะส่งอีเมลทดสอบสำเร็จ' : 'Settings saved. Recovery remains disabled until a test message succeeds.';
            } else {
                $success = $isTh ? 'บันทึกการตั้งค่า Gmail SMTP เรียบร้อยแล้ว' : 'Gmail SMTP settings saved.';
            }
            logHistory((int) $_SESSION['user_id'], 'update_recovery_email_settings', 'Updated Gmail SMTP recovery settings');
        } elseif ($action === 'diagnose_mail') {
            $mailResult = accountRecoveryTestConnection([
                'event_type' => 'smtp_diagnostic',
                'actor_id' => (int) $_SESSION['user_id'],
                'credential_source' => $credentialSource,
            ]);
            $testStateStored = accountRecoveryStoreTestResult($mailResult);
            if (!empty($mailResult['success']) && $testStateStored) {
                $success = $isTh
                    ? 'ตรวจการเชื่อมต่อสำเร็จ ขั้นต่อไปให้กดส่งอีเมลทดสอบเพื่อเปิดใช้งานจริง: ' . accountRecoveryDescribeMailResult($mailResult, true)
                    : 'Authentication succeeded. Send a test message to activate recovery: ' . accountRecoveryDescribeMailResult($mailResult, false);
                logHistory((int) $_SESSION['user_id'], 'diagnose_recovery_email', 'Gmail SMTP authentication diagnostic succeeded');
            } elseif (!empty($mailResult['success'])) {
                $error = $isTh ? 'เชื่อมต่อ Gmail สำเร็จ แต่บันทึกสถานะการทดสอบลงฐานข้อมูลไม่สำเร็จ ระบบจึงยังไม่เปิดใช้งาน' : 'Gmail authentication succeeded, but the verified state could not be stored. Recovery remains disabled.';
            } else {
                // A failed diagnostic must not leave a knowingly broken recovery system enabled.
                upsertSetting('recovery_mail_enabled', '0');
                $error = accountRecoveryDescribeMailResult($mailResult, $isTh)
                    . ($isTh ? ' ระบบถูกปิดอัตโนมัติจนกว่าจะทดสอบผ่าน' : ' The recovery mail system was disabled until the test succeeds.');
            }
        } elseif ($action === 'test_reset_flow') {
            $testRecipient = isset($_POST['test_recipient']) && is_scalar($_POST['test_recipient']) ? (string) $_POST['test_recipient'] : '';
            $recipient = accountRecoveryNormalizeGmail($testRecipient);
            if ($recipient === '') {
                $error = $isTh ? 'กรุณาระบุ Gmail ของบัญชีลูกค้าหรือตัวแทนที่มีอยู่จริง' : 'Enter the Gmail address of an existing user or reseller account.';
            } else {
                $flowResult = accountRecoveryRequestPasswordReset($recipient, true);
                if (!empty($flowResult['success'])) {
                    $success = ($isTh ? 'เส้นทางลูกค้าจริงส่งลิงก์รีเซ็ตสำเร็จ' : 'The real customer reset flow sent a link successfully')
                        . ' · ' . htmlspecialchars((string) ($flowResult['transport'] ?? ''), ENT_QUOTES, 'UTF-8')
                        . (!empty($flowResult['smtp_code']) ? ' · SMTP ' . (int) $flowResult['smtp_code'] : '');
                    logHistory((int) $_SESSION['user_id'], 'test_customer_password_reset_flow', 'Tested the real password reset flow for ' . (string) ($flowResult['recipient'] ?? 'masked'));
                } else {
                    $error = ($isTh ? 'เส้นทางลูกค้าจริงล้มเหลว: ' : 'The real customer reset flow failed: ')
                        . (string) ($flowResult['message'] ?? $flowResult['code'] ?? 'unknown');
                }
            }
        } else {
            $testRecipient = isset($_POST['test_recipient']) && is_scalar($_POST['test_recipient']) ? (string) $_POST['test_recipient'] : '';
            $recipient = accountRecoveryNormalizeGmail($testRecipient);
            if ($recipient === '') {
                $error = $isTh ? 'กรุณาระบุ Gmail สำหรับรับข้อความทดสอบ' : 'Enter a valid Gmail test recipient.';
            } else {
                $siteName = (string) getSetting('site_name', 'Store');
                $subject = 'ทดสอบระบบรีเซ็ตรหัสผ่าน - ' . $siteName;
                $html = '<div style="font-family:Arial,sans-serif;max-width:540px;margin:auto;padding:24px">'
                    . '<h2>Gmail SMTP พร้อมใช้งาน</h2><p>เว็บไซต์สามารถส่งลิงก์รีเซ็ตรหัสผ่านได้แล้ว</p>'
                    . '<p style="color:#666">ข้อความทดสอบจาก ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p></div>';
                $mailResult = accountRecoverySendMail(
                    $recipient,
                    $subject,
                    $html,
                    'Gmail SMTP test completed successfully.',
                    true,
                    ['event_type' => 'smtp_test', 'actor_id' => (int) $_SESSION['user_id'], 'credential_source' => $credentialSource]
                );
                $testStateStored = accountRecoveryStoreTestResult($mailResult);
                if (!empty($mailResult['success']) && $testStateStored) {
                    $activation = accountRecoverySetMailEnabled(true);
                    if (!empty($activation['success'])) {
                        $success = $isTh
                            ? 'ส่งอีเมลทดสอบสำเร็จ และเปิดระบบลืมรหัสผ่านให้ใช้งานแล้ว: ' . accountRecoveryDescribeMailResult($mailResult, true)
                            : 'Test email sent successfully and password recovery is now enabled. ' . accountRecoveryDescribeMailResult($mailResult, false);
                        logHistory((int) $_SESSION['user_id'], 'test_recovery_email', 'Sent Gmail SMTP test message and enabled customer recovery');
                    } else {
                        upsertSetting('recovery_mail_enabled', '0');
                        $activationCode = (string) ($activation['code'] ?? 'activation_failed');
                        $error = ($isTh ? 'Gmail รับอีเมลทดสอบแล้ว แต่เปิดระบบลูกค้าจริงไม่สำเร็จ: ' : 'Gmail accepted the test message, but customer recovery could not be enabled: ') . $activationCode;
                    }
                } elseif (!empty($mailResult['success'])) {
                    upsertSetting('recovery_mail_enabled', '0');
                    $error = $isTh ? 'Gmail รับอีเมลทดสอบแล้ว แต่บันทึกผลทดสอบลงฐานข้อมูลไม่สำเร็จ ระบบลูกค้าจริงยังถูกปิดอยู่' : 'Gmail accepted the test message, but the verified state could not be stored. Customer recovery remains disabled.';
                } else {
                    upsertSetting('recovery_mail_enabled', '0');
                    $error = accountRecoveryDescribeMailResult($mailResult, $isTh)
                        . ($isTh ? ' ระบบถูกปิดอัตโนมัติจนกว่าจะส่งทดสอบผ่าน' : ' The recovery mail system was disabled until the test succeeds.');
                }
            }
        }
    } elseif ($action === 'activate_mail') {
        $activation = accountRecoverySetMailEnabled(true);
        if (!empty($activation['success'])) {
            $success = $isTh ? 'เปิดระบบลืมรหัสผ่านทาง Gmail เรียบร้อยแล้ว' : 'Gmail password recovery has been enabled.';
            logHistory((int) $_SESSION['user_id'], 'enable_recovery_email', 'Enabled Gmail password recovery from a verified send test');
        } else {
            $activationCode = (string) ($activation['code'] ?? 'not_ready');
            $activationMessages = [
                'send_test_required' => $isTh ? 'ยังไม่มีผลส่งอีเมลทดสอบที่สำเร็จ กรุณากดส่งอีเมลทดสอบก่อน' : 'A successful send test is required.',
                'credential_not_verified' => $isTh ? 'App Password ยังไม่ได้รับการยืนยัน กรุณาทดสอบใหม่' : 'The App Password has not been verified.',
                'base_url_missing' => $isTh ? 'ยังไม่ได้ตั้งค่า Site Base URL แบบ HTTPS' : 'The HTTPS Site Base URL is missing.',
            ];
            $error = $activationMessages[$activationCode] ?? ($isTh ? 'ระบบอีเมลยังไม่พร้อมเปิดใช้งาน' : 'The mail system is not ready to be enabled.');
        }
    } elseif ($action === 'clear_stored_app_password') {
        if (accountRecoveryClearStoredAppPassword()) {
            $success = $isTh ? 'ล้าง App Password ที่บันทึกไว้แล้ว กรุณาสร้างและใส่รหัสใหม่ก่อนทดสอบ' : 'The stored App Password was cleared. Enter a newly generated password before testing.';
            logHistory((int) $_SESSION['user_id'], 'clear_recovery_smtp_password', 'Cleared stored Gmail SMTP App Password');
        } else {
            $error = $isTh ? 'ล้าง App Password ที่บันทึกไว้ไม่สำเร็จ' : 'Unable to clear the stored App Password.';
        }
    } elseif ($action === 'clear_mail_logs') {
        if (accountRecoveryClearMailLogs()) {
            $success = $isTh ? 'ล้าง Mail Log เรียบร้อยแล้ว' : 'Mail logs cleared.';
            logHistory((int) $_SESSION['user_id'], 'clear_recovery_mail_logs', 'Cleared Gmail SMTP diagnostic logs');
        } else {
            $error = $isTh ? 'ล้าง Mail Log ไม่สำเร็จ' : 'Unable to clear mail logs.';
        }
    } elseif ($action === 'cleanup_now') {
        $cleanupResult = keyHistoryCleanupRun(true);
        if (!empty($cleanupResult['success'])) {
            $success = ($isTh ? 'ล้างประวัติคีย์เก่าเรียบร้อยแล้ว จำนวน ' : 'Old key history cleanup completed. Removed ')
                . number_format((int) ($cleanupResult['deleted'] ?? 0))
                . ($isTh ? ' รายการ' : ' records.');
            logHistory((int) $_SESSION['user_id'], 'manual_key_history_cleanup', 'Deleted old key history rows: ' . (int) ($cleanupResult['deleted'] ?? 0));
        } else {
            $error = $isTh ? 'ไม่สามารถล้างประวัติคีย์เก่าได้ กรุณาตรวจ error log' : 'Old key history cleanup failed. Check the error log.';
        }
    }
}

$config = accountRecoveryGetMailConfig();
$activationReadiness = accountRecoveryActivationReadiness();
$baseUrl = getCanonicalBaseUrl();
$lastCleanup = (string) getSetting('key_history_cleanup_last_run', $isTh ? 'ยังไม่เคยทำงาน' : 'Never');
$lastDeleted = (int) getSetting('key_history_cleanup_last_deleted', '0');
$mailLogs = accountRecoveryGetMailLogs(100);
$caFile = accountRecoveryFindCaFile();
$caVerifiedBySmtp = !empty($config['delivery_verified']) || ($config['last_test_status'] === 'success' && in_array((string) $config['last_test_stage'], ['authenticated', 'sent'], true));
$caDisplay = $caFile !== '' ? $caFile : ($caVerifiedBySmtp ? ($isTh ? 'ใช้ Trust Store ของระบบ และ TLS ผ่านการตรวจสอบแล้ว' : 'System trust store; TLS verification passed') : ($isTh ? 'ไม่พบไฟล์ CA ที่ระบุโดยตรง' : 'No explicit CA bundle found'));
$environmentRows = [
    ['PHP', PHP_VERSION, true],
    ['OpenSSL', extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : ($isTh ? 'ไม่พร้อม' : 'Unavailable'), extension_loaded('openssl')],
    ['stream_socket_client', function_exists('stream_socket_client') ? ($isTh ? 'พร้อม' : 'Available') : ($isTh ? 'ไม่มี' : 'Missing'), function_exists('stream_socket_client')],
    ['CA Certificate', $caDisplay, $caFile !== '' || $caVerifiedBySmtp],
    [$isTh ? 'แหล่งกุญแจอีเมล' : 'Mail secret source', ($config['secret_source'] !== '' ? $config['secret_source'] : ($isTh ? 'ไม่พบ' : 'Missing')) . ($config['secret_key_id'] !== '' ? ' · ' . $config['secret_key_id'] : '') . ($config['secret_fingerprint'] !== '' ? ' · ' . $config['secret_fingerprint'] : ''), $config['secret_exists']],
    ['App Password', $config['configured'] ? ($isTh ? 'ถอดรหัสได้' : 'Decryptable') : ($isTh ? 'ยังไม่พร้อม' : 'Not ready'), $config['configured']],
    [$isTh ? 'ยืนยัน SMTP ล่าสุด' : 'Last SMTP verification', $config['last_verified_at'] !== '' ? $config['last_verified_at'] : ($isTh ? 'ยังไม่เคย' : 'Never'), $config['last_verified_at'] !== ''],
    [$isTh ? 'ทดสอบส่งจริงล่าสุด' : 'Last verified delivery', $config['delivery_verified_at'] !== '' ? $config['delivery_verified_at'] : ($isTh ? 'รอการยืนยัน/ใช้ผล V8' : 'Pending / V8 fallback'), !empty($config['delivery_verified']) || !empty($activationReadiness['ready'])],
    [$isTh ? 'สถานะข้อมูลเข้าสู่ระบบ' : 'Credential state', $config['credential_state'] !== '' ? $config['credential_state'] : ($isTh ? 'ยังไม่เคยยืนยัน' : 'Not tested'), $config['credential_state'] === 'valid'],
    [$isTh ? 'อัปเดต App Password ล่าสุด' : 'App Password updated', $config['password_updated_at'] !== '' ? $config['password_updated_at'] : ($isTh ? 'ไม่มีข้อมูล' : 'Unknown'), $config['password_updated_at'] !== ''],
];

function recoveryLogPrettyDetails($raw): string
{
    if (!is_string($raw) || trim($raw) === '') return '{}';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $raw;
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($pretty) ? $pretty : $raw;
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isTh ? 'อีเมลและกู้รหัสผ่าน' : 'Email & Password Recovery'; ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        pre{white-space:pre-wrap;word-break:break-word}
        .copy-btn{display:inline-flex;align-items:center;gap:.4rem;border:1px solid rgba(34,211,238,.28);background:rgba(34,211,238,.08);color:#a5f3fc;border-radius:.65rem;padding:.45rem .7rem;font-size:.75rem;line-height:1;transition:.15s}
        .copy-btn:hover{background:rgba(34,211,238,.16)}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="p-4 md:p-6 max-w-7xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold"><i class="bi bi-envelope-lock text-blue-400 mr-2"></i><?php echo $isTh ? 'อีเมลและกู้รหัสผ่าน' : 'Email & Password Recovery'; ?></h1>
        <p class="text-gray-400 text-sm mt-1"><?php echo $isTh ? 'ตั้งค่า Gmail SMTP พร้อมเครื่องมือตรวจทีละขั้นและ Mail Log ที่ไม่บันทึกรหัสลับ' : 'Configure Gmail SMTP with step-by-step diagnostics and secret-safe mail logs.'; ?></p>
    </div>

    <?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-200"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="rounded-xl border border-green-500/30 bg-green-500/10 px-4 py-3 text-green-200"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <?php if (is_array($mailResult)): ?>
        <section class="glass rounded-2xl p-5 md:p-6">
            <h2 class="text-lg font-semibold mb-3"><i class="bi bi-activity text-cyan-300 mr-2"></i><?php echo $isTh ? 'ผลการทดสอบล่าสุด' : 'Latest test result'; ?></h2>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Status</div><div class="font-semibold <?php echo !empty($mailResult['success']) ? 'text-green-300' : 'text-red-300'; ?>"><?php echo !empty($mailResult['success']) ? 'SUCCESS' : 'FAILED'; ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Transport</div><div><?php echo htmlspecialchars((string) ($mailResult['transport'] ?? '-')); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Stage</div><div><?php echo htmlspecialchars((string) ($mailResult['stage'] ?? '-')); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">SMTP</div><div><?php echo htmlspecialchars((string) ($mailResult['smtp_code'] ?? '-')); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-gray-500 text-xs">Duration</div><div><?php echo number_format((int) ($mailResult['duration_ms'] ?? 0)); ?> ms</div></div>
            </div>
            <details class="mt-4 rounded-xl border border-white/10 bg-black/20 p-4">
                <summary class="cursor-pointer text-sm text-cyan-300"><?php echo $isTh ? 'ดูข้อมูลดีบักของคำขอนี้' : 'View diagnostic details'; ?></summary>
                <div class="mt-3 flex justify-end"><button type="button" class="copy-btn" onclick="copyRecoveryText('latest-mail-debug', this)"><i class="bi bi-clipboard"></i><?php echo $isTh ? 'คัดลอก Debug' : 'Copy debug'; ?></button></div>
                <pre id="latest-mail-debug" class="mt-2 text-xs text-gray-300"><?php echo htmlspecialchars(json_encode($mailResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)); ?></pre>
            </details>
        </section>
    <?php endif; ?>

    <section class="glass rounded-2xl p-5 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
            <div>
                <h2 class="text-lg font-semibold"><i class="bi bi-google text-red-400 mr-2"></i>Gmail SMTP</h2>
                <p class="text-xs text-gray-500 mt-1">smtp.gmail.com · AUTO: STARTTLS 587 → SMTPS 465</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="rounded-full px-3 py-1 text-xs <?php echo $config['enabled'] && $config['configured'] ? 'bg-green-500/15 text-green-300' : 'bg-amber-500/15 text-amber-300'; ?>">
                    <?php echo $config['enabled'] && $config['configured'] ? ($isTh ? 'เปิดใช้งาน' : 'Enabled') : ($isTh ? 'ยังไม่พร้อม/ถูกปิด' : 'Not ready/disabled'); ?>
                </span>
                <?php if ($config['last_test_status'] !== ''): ?>
                    <span class="rounded-full px-3 py-1 text-xs <?php echo $config['last_test_status'] === 'success' ? 'bg-cyan-500/15 text-cyan-300' : 'bg-red-500/15 text-red-300'; ?>">
                        <?php echo $isTh ? 'ทดสอบล่าสุด' : 'Last test'; ?>: <?php echo htmlspecialchars($config['last_test_status']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($baseUrl === ''): ?>
            <div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-100 text-sm">
                <?php echo $isTh ? 'ยังไม่ได้ตั้งค่า Site Base URL ในหน้าตั้งค่าระบบ ลิงก์รีเซ็ตรหัสผ่านจะสร้างไม่ได้' : 'Site Base URL is not configured, so password reset links cannot be generated.'; ?>
            </div>
        <?php else: ?>
            <div class="mb-4 text-xs text-gray-500"><?php echo $isTh ? 'โดเมนลิงก์รีเซ็ต' : 'Reset link domain'; ?>: <code class="text-blue-300"><?php echo htmlspecialchars($baseUrl); ?></code></div>
        <?php endif; ?>

        <?php if ($config['config_error'] !== ''): ?>
            <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-100 text-sm">
                <?php echo htmlspecialchars(accountRecoveryDescribeMailResult(['success' => false, 'error_code' => $config['config_error']], $isTh)); ?>
            </div>
        <?php endif; ?>

        <?php if ($config['credential_state'] === 'invalid'): ?>
            <div class="mb-4 rounded-xl border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-100">
                <div class="font-semibold"><i class="bi bi-key-fill mr-2"></i><?php echo $isTh ? 'App Password ที่บันทึกไว้ใช้ไม่ได้แล้ว' : 'The stored App Password is no longer valid'; ?></div>
                <p class="mt-1 text-xs text-red-200/80"><?php echo $isTh ? 'ช่อง App Password ที่เว้นว่างจะใช้รหัสเดิมซ้ำ กรุณาสร้างรหัสใหม่จากบัญชี Gmail ผู้ส่งเดียวกับด้านล่าง แล้ววางรหัสใหม่ก่อนกดทดสอบ' : 'Leaving the password blank reuses the rejected credential. Generate a new App Password from the same sender Gmail account and enter it before testing.'; ?></p>
            </div>
        <?php endif; ?>

        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php echo csrfField(); ?>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'Gmail ผู้ส่ง' : 'Sender Gmail'; ?></label>
                <input type="email" name="smtp_username" required maxlength="190" pattern="^[^@\s]+@gmail\.com$" placeholder="example@gmail.com"
                       value="<?php echo htmlspecialchars((string) $config['username'], ENT_QUOTES, 'UTF-8'); ?>"
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'ชื่อผู้ส่ง' : 'Sender name'; ?></label>
                <input type="text" name="from_name" required maxlength="120" value="<?php echo htmlspecialchars((string) $config['from_name'], ENT_QUOTES, 'UTF-8'); ?>"
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2">Gmail App Password</label>
                <input type="password" name="smtp_app_password" maxlength="64" autocomplete="new-password" placeholder="<?php echo $config['credential_state'] === 'invalid' ? ($isTh ? 'ต้องใส่ App Password ใหม่ 16 ตัว' : 'Enter a new 16-character App Password') : ($config['configured'] ? '•••• •••• •••• ••••' : '16-character App Password'); ?>"
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
                <p class="mt-1 text-xs text-gray-500"><?php echo $isTh ? 'ใช้ App Password 16 ตัวที่สร้างจาก Gmail ผู้ส่งบัญชีเดียวกัน เว้นว่างหมายถึงใช้รหัสเดิม ไม่ใช่สร้างรหัสใหม่ ช่องว่างภายในรหัสจะถูกตัดอัตโนมัติ' : 'Use the 16-character Google App Password. Leave blank to keep it; spaces are removed automatically.'; ?></p>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'โหมดการเชื่อมต่อ' : 'Connection mode'; ?></label>
                <select name="smtp_mode" class="w-full rounded-xl border border-white/10 bg-[#17171c] px-4 py-3 text-white">
                    <option value="auto" <?php echo $config['mode'] === 'auto' ? 'selected' : ''; ?>>AUTO — STARTTLS 587 แล้วลอง SMTPS 465</option>
                    <option value="starttls_587" <?php echo $config['mode'] === 'starttls_587' ? 'selected' : ''; ?>>STARTTLS — Port 587</option>
                    <option value="smtps_465" <?php echo $config['mode'] === 'smtps_465' ? 'selected' : ''; ?>>SMTPS — Port 465</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'Gmail รับข้อความทดสอบ' : 'Test recipient Gmail'; ?></label>
                <input type="email" name="test_recipient" maxlength="190" pattern="^[^@\s]+@gmail\.com$" placeholder="example@gmail.com"
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white">
            </div>
            <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-4 cursor-pointer">
                <input type="checkbox" name="enabled" value="1" class="accent-blue-500" <?php echo $config['enabled'] ? 'checked' : ''; ?>>
                <span><span class="block font-medium"><?php echo $isTh ? 'เปิดระบบลืมรหัสผ่านทาง Gmail' : 'Enable Gmail password recovery'; ?></span><span class="text-xs text-gray-500"><?php echo $isTh ? 'เมื่อการทดสอบล้มเหลว ระบบจะปิดสวิตช์นี้อัตโนมัติ' : 'This is disabled automatically after a failed test.'; ?></span></span>
            </label>
            <div class="md:col-span-2 flex flex-col sm:flex-row gap-3">
                <button type="submit" name="action" value="save_mail" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-3 font-semibold"><i class="bi bi-save mr-2"></i><?php echo $isTh ? 'บันทึกการตั้งค่า' : 'Save settings'; ?></button>
                <button type="submit" name="action" value="diagnose_mail" class="rounded-xl bg-cyan-600 hover:bg-cyan-500 px-5 py-3 font-semibold"><i class="bi bi-stethoscope mr-2"></i><?php echo $isTh ? 'ตรวจการเชื่อมต่อและล็อกอิน' : 'Test connection & login'; ?></button>
                <button type="submit" name="action" value="test_mail" class="rounded-xl bg-violet-600 hover:bg-violet-500 px-5 py-3 font-semibold"><i class="bi bi-send-check mr-2"></i><?php echo $isTh ? 'ส่งอีเมลทดสอบ' : 'Send test email'; ?></button>
                <button type="submit" name="action" value="test_reset_flow" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-3 font-semibold"><i class="bi bi-person-check mr-2"></i><?php echo $isTh ? 'ทดสอบแบบลูกค้าจริง' : 'Test real customer flow'; ?></button>
            </div>
            <div class="md:col-span-2 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-gray-400">
                <?php echo $isTh ? 'ปุ่มทดสอบแบบลูกค้าจริงต้องใช้ Gmail ที่ผูกกับบัญชี User หรือ Reseller จริง ระบบจะตรวจสถานะเปิดใช้งาน ค้นหาบัญชี สร้าง Token และส่งอีเมลรูปแบบเดียวกับหน้า “ลืมรหัสผ่าน”' : 'The real-flow test requires a Gmail address linked to an existing user or reseller. It checks activation, finds the account, creates a token, and sends the same email used by the public forgot-password page.'; ?>
            </div>
        </form>

        <?php if (!$config['enabled'] && !empty($activationReadiness['ready'])): ?>
            <form method="post" class="mt-3">
                <?php echo csrfField(); ?>
                <button type="submit" name="action" value="activate_mail" class="rounded-xl bg-green-600 hover:bg-green-500 px-4 py-2 text-sm font-semibold text-white"><i class="bi bi-power mr-2"></i><?php echo $isTh ? 'เปิดใช้งานจากผลทดสอบที่ผ่านแล้ว' : 'Enable from the verified test'; ?></button>
            </form>
        <?php endif; ?>

        <?php if ($config['has_stored_password']): ?>
            <form method="post" class="mt-3" onsubmit="return confirm('<?php echo $isTh ? 'ล้าง App Password ที่บันทึกไว้หรือไม่? ระบบลืมรหัสผ่านจะถูกปิดจนกว่าจะใส่รหัสใหม่' : 'Clear the stored App Password? Password recovery will remain disabled until a new credential is saved.'; ?>')">
                <?php echo csrfField(); ?>
                <button type="submit" name="action" value="clear_stored_app_password" class="rounded-xl border border-red-500/30 bg-red-500/10 hover:bg-red-500/20 px-4 py-2 text-sm text-red-200"><i class="bi bi-key mr-2"></i><?php echo $isTh ? 'ล้าง App Password เดิม' : 'Clear stored App Password'; ?></button>
            </form>
        <?php endif; ?>

        <div class="mt-5 rounded-xl border border-blue-500/20 bg-blue-500/5 p-4 text-sm text-gray-300">
            <div class="font-semibold text-blue-300 mb-2"><?php echo $isTh ? 'เงื่อนไขของ Google' : 'Google requirements'; ?></div>
            <p><?php echo $isTh ? 'บัญชี Gmail ผู้ส่งต้องเปิดการยืนยันแบบ 2 ขั้นตอน แล้วสร้าง App Password 16 ตัวสำหรับเว็บไซต์ ห้ามใช้รหัสผ่าน Gmail ปกติ หากเปลี่ยนรหัสผ่าน Google อาจต้องสร้าง App Password ใหม่' : 'The sender Gmail account needs 2-Step Verification and a 16-character App Password. Do not use the normal Gmail password. A new App Password may be required after changing the Google password.'; ?></p>
        </div>
    </section>

    <section class="glass rounded-2xl p-5 md:p-6">
        <h2 class="text-lg font-semibold mb-4"><i class="bi bi-pc-display-horizontal text-emerald-300 mr-2"></i><?php echo $isTh ? 'สถานะสภาพแวดล้อมเซิร์ฟเวอร์' : 'Server environment'; ?></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($environmentRows as $row): ?>
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-400"><?php echo htmlspecialchars((string) $row[0]); ?></span>
                        <i class="bi <?php echo $row[2] ? 'bi-check-circle-fill text-green-400' : 'bi-x-circle-fill text-red-400'; ?>"></i>
                    </div>
                    <div class="mt-2 text-sm break-all"><?php echo htmlspecialchars((string) $row[1]); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($config['last_test_at'] !== ''): ?>
            <div class="mt-4 text-xs text-gray-500">
                <?php echo $isTh ? 'ผลทดสอบที่บันทึกล่าสุด' : 'Stored last test'; ?>:
                <?php echo htmlspecialchars($config['last_test_at']); ?> ·
                <?php echo htmlspecialchars($config['last_test_transport']); ?> ·
                <?php echo htmlspecialchars($config['last_test_message']); ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="glass rounded-2xl p-5 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold"><i class="bi bi-journal-code text-orange-300 mr-2"></i>Gmail SMTP / Mail Log</h2>
                <p class="text-xs text-gray-500 mt-1"><?php echo $isTh ? 'เก็บ 100 รายการล่าสุด และลบรายการเกิน 90 วันอัตโนมัติ ไม่บันทึก App Password เนื้อหาอีเมล หรือลิงก์รีเซ็ต' : 'Shows the latest 100 entries and removes logs older than 90 days. App Passwords, message bodies, and reset links are never logged.'; ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="copy-btn" onclick="copyRecoveryText('all-mail-logs', this)"><i class="bi bi-clipboard-check"></i><?php echo $isTh ? 'คัดลอก Log ทั้งหมด' : 'Copy all logs'; ?></button>
                <button type="button" class="copy-btn" onclick="downloadRecoveryLogs()"><i class="bi bi-download"></i><?php echo $isTh ? 'ดาวน์โหลด .txt' : 'Download .txt'; ?></button>
                <form method="post" onsubmit="return confirm('<?php echo $isTh ? 'ต้องการล้าง Mail Log ทั้งหมดหรือไม่?' : 'Clear all mail logs?'; ?>')">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="action" value="clear_mail_logs" class="rounded-xl border border-red-500/30 bg-red-500/10 hover:bg-red-500/20 px-4 py-2 text-sm text-red-200"><i class="bi bi-trash3 mr-2"></i><?php echo $isTh ? 'ล้าง Log' : 'Clear logs'; ?></button>
                </form>
            </div>
        </div>

        <?php
            $safeLogsForCopy = [];
            foreach ($mailLogs as $copyLog) {
                $safeLogsForCopy[] = [
                    'time' => (string) $copyLog['created_at'],
                    'event' => (string) $copyLog['event_type'],
                    'status' => (string) $copyLog['status'],
                    'transport' => (string) $copyLog['transport'],
                    'stage' => (string) $copyLog['stage'],
                    'error_code' => (string) $copyLog['error_code'],
                    'smtp_code' => $copyLog['smtp_code'],
                    'recipient' => (string) $copyLog['recipient'],
                    'message' => (string) $copyLog['message'],
                    'details' => json_decode((string) $copyLog['details'], true),
                    'duration_ms' => (int) $copyLog['duration_ms'],
                ];
            }
            $safeLogsText = json_encode($safeLogsForCopy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($safeLogsText)) $safeLogsText = '[]';
        ?>
        <textarea id="all-mail-logs" class="hidden" aria-hidden="true"><?php echo htmlspecialchars($safeLogsText, ENT_QUOTES, 'UTF-8'); ?></textarea>

        <div class="overflow-x-auto rounded-xl border border-white/10">
            <table class="min-w-full text-sm">
                <thead class="bg-white/5 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-3">Time</th>
                        <th class="text-left px-3 py-3">Event</th>
                        <th class="text-left px-3 py-3">Status</th>
                        <th class="text-left px-3 py-3">Transport</th>
                        <th class="text-left px-3 py-3">Stage</th>
                        <th class="text-left px-3 py-3">SMTP</th>
                        <th class="text-left px-3 py-3">Recipient</th>
                        <th class="text-left px-3 py-3">Message</th>
                        <th class="text-left px-3 py-3">Debug</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (!$mailLogs): ?>
                        <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500"><?php echo $isTh ? 'ยังไม่มี Mail Log ให้ระบบสารภาพ' : 'No mail logs yet.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($mailLogs as $log): $logDomId = 'mail-log-' . (int) $log['id']; ?>
                            <tr class="align-top">
                                <td class="px-3 py-3 whitespace-nowrap text-xs text-gray-400"><?php echo htmlspecialchars((string) $log['created_at']); ?><br><span><?php echo number_format((int) $log['duration_ms']); ?> ms</span></td>
                                <td class="px-3 py-3"><?php echo htmlspecialchars((string) $log['event_type']); ?></td>
                                <td class="px-3 py-3"><span class="rounded-full px-2 py-1 text-xs <?php echo $log['status'] === 'success' ? 'bg-green-500/15 text-green-300' : 'bg-red-500/15 text-red-300'; ?>"><?php echo htmlspecialchars(strtoupper((string) $log['status'])); ?></span></td>
                                <td class="px-3 py-3 whitespace-nowrap"><?php echo htmlspecialchars((string) $log['transport']); ?></td>
                                <td class="px-3 py-3 whitespace-nowrap"><?php echo htmlspecialchars((string) $log['stage']); ?><br><span class="text-xs text-red-300"><?php echo htmlspecialchars((string) $log['error_code']); ?></span></td>
                                <td class="px-3 py-3"><?php echo $log['smtp_code'] !== null ? (int) $log['smtp_code'] : '-'; ?></td>
                                <td class="px-3 py-3 whitespace-nowrap"><?php echo htmlspecialchars((string) $log['recipient']); ?></td>
                                <td class="px-3 py-3 min-w-64 text-xs text-gray-300">
                                    <div id="<?php echo $logDomId; ?>-message"><?php echo htmlspecialchars((string) $log['message']); ?></div>
                                    <button type="button" class="copy-btn mt-2" onclick="copyRecoveryText('<?php echo $logDomId; ?>-message', this)"><i class="bi bi-clipboard"></i><?php echo $isTh ? 'คัดลอกข้อความ' : 'Copy message'; ?></button>
                                </td>
                                <td class="px-3 py-3">
                                    <details class="min-w-64">
                                        <summary class="cursor-pointer text-cyan-300 text-xs"><?php echo $isTh ? 'ดูรายละเอียด' : 'Details'; ?></summary>
                                        <button type="button" class="copy-btn mt-2" onclick="copyRecoveryText('<?php echo $logDomId; ?>-debug', this)"><i class="bi bi-clipboard"></i><?php echo $isTh ? 'คัดลอก Debug' : 'Copy debug'; ?></button>
                                        <pre id="<?php echo $logDomId; ?>-debug" class="mt-2 rounded-lg bg-black/30 p-3 text-[11px] text-gray-300"><?php echo htmlspecialchars(recoveryLogPrettyDetails($log['details'])); ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass rounded-2xl p-5 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold"><i class="bi bi-clock-history text-amber-300 mr-2"></i><?php echo $isTh ? 'ล้างประวัติคีย์อัตโนมัติ' : 'Automatic key-history cleanup'; ?></h2>
                <p class="text-sm text-gray-400 mt-1"><?php echo $isTh ? 'ลบเฉพาะรายละเอียดซื้อขายคีย์ที่เกิน 1 ปี ประวัติเติมเงินและการปรับยอดจะไม่ถูกลบ' : 'Deletes only key-sale details older than one year. Deposits and balance adjustments are preserved.'; ?></p>
                <p class="text-xs text-gray-500 mt-2"><?php echo $isTh ? 'ทำงานล่าสุด' : 'Last run'; ?>: <?php echo htmlspecialchars($lastCleanup); ?> · <?php echo $isTh ? 'ลบ' : 'Removed'; ?> <?php echo number_format($lastDeleted); ?> <?php echo $isTh ? 'รายการ' : 'records'; ?></p>
            </div>
            <form method="post" onsubmit="return confirm('<?php echo $isTh ? 'ต้องการเริ่มล้างประวัติคีย์เก่าตอนนี้หรือไม่?' : 'Run old key-history cleanup now?'; ?>')">
                <?php echo csrfField(); ?>
                <button type="submit" name="action" value="cleanup_now" class="rounded-xl bg-amber-500 hover:bg-amber-400 px-5 py-3 font-semibold text-black"><i class="bi bi-trash3 mr-2"></i><?php echo $isTh ? 'ล้างตอนนี้' : 'Clean now'; ?></button>
            </form>
        </div>
        <div class="mt-4 text-xs text-gray-500"><?php echo $isTh ? 'ระบบทำงานเป็นชุดเล็กและใช้ MySQL lock เพื่อไม่ให้หลายคำขอลบข้อมูลพร้อมกัน หากข้อมูลเก่ามีจำนวนมาก ระบบจะทยอยทำต่อโดยอัตโนมัติ' : 'Cleanup runs in bounded batches with a MySQL lock. Large backlogs are continued automatically.'; ?></div>
    </section>
</main>
<script>
async function copyRecoveryText(id, button){
    const element = document.getElementById(id);
    if (!element) return;
    const text = element.tagName === 'TEXTAREA' || element.tagName === 'INPUT' ? element.value : element.innerText;
    let copied = false;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            copied = true;
        }
    } catch (e) {}
    if (!copied) {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
        area.remove();
    }
    if (button) {
        const original = button.innerHTML;
        button.innerHTML = copied ? '<i class="bi bi-check2"></i><?php echo $isTh ? 'คัดลอกแล้ว' : 'Copied'; ?>' : '<i class="bi bi-x-lg"></i><?php echo $isTh ? 'คัดลอกไม่สำเร็จ' : 'Copy failed'; ?>';
        setTimeout(() => { button.innerHTML = original; }, 1600);
    }
}
function downloadRecoveryLogs(){
    const element = document.getElementById('all-mail-logs');
    if (!element) return;
    const blob = new Blob([element.value], {type:'text/plain;charset=utf-8'});
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'gmail-smtp-logs-' + new Date().toISOString().replace(/[:.]/g, '-') + '.txt';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
</script>
</body>
</html>
