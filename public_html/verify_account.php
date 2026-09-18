<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(true);

if (isAdmin()) {
    redirectByRole();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

$state = accountVerificationStateForUserId($userId);
if (!empty($state['schema_ready']) && accountVerificationIsComplete($userId)) {
    redirectByRole();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('verify_account.php');
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';
    $result = ['success' => false, 'message' => 'คำขอไม่ถูกต้อง'];

    if ($action === 'send_email_otp') {
        $result = accountVerificationRequestEmailOtp($userId);
    } elseif ($action === 'verify_email_otp') {
        $code = isset($_POST['email_otp']) && is_scalar($_POST['email_otp']) ? (string) $_POST['email_otp'] : '';
        $result = accountVerificationVerifyEmailOtp($userId, $code);
    }

    if (!empty($result['success'])) $success = (string) ($result['message'] ?? 'ดำเนินการเรียบร้อยแล้ว');
    else $error = (string) ($result['message'] ?? 'ดำเนินการไม่สำเร็จ');

    $state = accountVerificationStateForUserId($userId);
    if (!empty($state['schema_ready']) && accountVerificationIsComplete($userId)) {
        redirectByRole();
    }
}

$emailMasked = function_exists('accountRecoveryMaskEmail')
    ? accountRecoveryMaskEmail((string) ($state['email'] ?? ''))
    : (string) ($state['email'] ?? '');
$emailCooldown = !empty($state['schema_ready']) ? accountVerificationOtpCooldown($userId, 'email') : 0;
$currentLang = getAppLang();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>ยืนยันบัญชี - <?php echo htmlspecialchars((string) (getStoreBranding()['title_text'] ?? 'STORE'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root{--sakazuki-accent:#6366f1;--sakazuki-accent2:#8b5cf6}
        html{-webkit-text-size-adjust:100%} body{overflow-x:hidden}
        input,button{font-size:16px!important}
        .glass{background:rgba(255,255,255,.045);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .otp-input{letter-spacing:.3em;font-variant-numeric:tabular-nums;text-align:center}
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
<main class="min-h-screen flex items-center justify-center px-4 py-8">
    <section class="w-full max-w-xl space-y-4" aria-labelledby="verify-title">
        <div class="text-center space-y-2">
            <div class="mx-auto h-12 w-12 rounded-xl bg-indigo-500/15 border border-indigo-400/20 flex items-center justify-center">
                <i class="bi bi-shield-check text-2xl text-indigo-300"></i>
            </div>
            <h1 id="verify-title" class="text-2xl font-bold">ยืนยันบัญชีก่อนใช้งาน</h1>
            <p class="text-sm text-gray-400">ยืนยันอีเมลด้วยรหัส OTP 6 หลักครั้งเดียว ไม่ต้องเชื่อม LINE, Google หรือเบอร์โทรศัพท์</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="glass rounded-xl border-red-500/30 px-4 py-3 text-red-200" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="glass rounded-xl border-green-500/30 px-4 py-3 text-green-200" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (empty($state['schema_ready'])): ?>
            <div class="glass rounded-2xl p-5 border-yellow-500/25">
                <div class="flex gap-3">
                    <i class="bi bi-exclamation-triangle text-yellow-300 text-xl"></i>
                    <div>
                        <h2 class="font-semibold text-yellow-100">ระบบยืนยันตัวตนยังไม่พร้อม</h2>
                        <p class="mt-1 text-sm text-gray-400">กรุณารัน maintenance/cron schema migration ของเว็บไซต์ก่อน</p>
                    </div>
                </div>
            </div>
        <?php elseif (!empty($state['email_required'])): ?>
            <div class="glass rounded-2xl p-5 space-y-4">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 h-9 w-9 rounded-lg flex items-center justify-center <?php echo !empty($state['email_verified']) ? 'bg-green-500/15 text-green-300' : 'bg-indigo-500/15 text-indigo-300'; ?>">
                        <i class="bi <?php echo !empty($state['email_verified']) ? 'bi-check-lg' : 'bi-envelope'; ?>"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="font-semibold">อีเมล</h2>
                            <span class="text-xs <?php echo !empty($state['email_verified']) ? 'text-green-300' : 'text-yellow-300'; ?>"><?php echo !empty($state['email_verified']) ? 'ยืนยันแล้ว' : 'รอยืนยัน'; ?></span>
                        </div>
                        <p class="mt-1 text-sm text-gray-400 break-all"><?php echo htmlspecialchars($emailMasked, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>

                <?php if (empty($state['email_verified'])): ?>
                    <form method="POST" class="space-y-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="send_email_otp">
                        <button type="submit" class="w-full rounded-lg bg-indigo-500 hover:bg-indigo-600 disabled:opacity-50 px-4 py-2.5 font-medium transition" <?php echo $emailCooldown > 0 ? 'disabled' : ''; ?>>
                            <i class="bi bi-send mr-2"></i><?php echo $emailCooldown > 0 ? 'ขอรหัสใหม่ได้ใน ' . (int) $emailCooldown . ' วินาที' : 'ส่งรหัส OTP 6 หลัก'; ?>
                        </button>
                    </form>
                    <form method="POST" class="space-y-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="verify_email_otp">
                        <label for="email_otp" class="block text-sm text-gray-400">รหัส OTP 6 หลัก</label>
                        <input id="email_otp" name="email_otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="otp-input glass rounded-lg px-3 py-3 w-full bg-transparent text-white border border-white/10" placeholder="000000">
                        <button type="submit" class="w-full rounded-lg bg-white/10 hover:bg-white/15 px-4 py-2.5 font-medium transition">ยืนยันอีเมล</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="flex items-center justify-between text-sm text-gray-500 px-1">
            <span>เข้าสู่ระบบเป็น <?php echo htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
            <a href="logout.php" class="text-gray-300 hover:text-white underline underline-offset-4">ออกจากระบบ</a>
        </div>
    </section>
</main>
</body>
</html>
