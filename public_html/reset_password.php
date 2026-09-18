<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/account_recovery.php';

$lang = getAppLang();
$isTh = $lang !== 'en';
$error = '';
$token = '';
if (isset($_GET['token']) && is_scalar($_GET['token'])) $token = strtolower(trim((string) $_GET['token']));
if (isset($_POST['token']) && is_scalar($_POST['token'])) $token = strtolower(trim((string) $_POST['token']));
$tokenData = accountRecoveryFindValidToken($token);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('reset_password.php?token=' . rawurlencode($token));
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $result = accountRecoveryResetPassword($token, $newPassword, $confirmPassword);
    if (!empty($result['success'])) {
        header('Location: login.php?reset=1', true, 302);
        exit();
    }
    $error = (string) ($result['message'] ?? ($isTh ? 'ไม่สามารถตั้งรหัสผ่านได้' : 'Unable to reset password.'));
    $tokenData = accountRecoveryFindValidToken($token);
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo $isTh ? 'ตั้งรหัสผ่านใหม่' : 'Reset password'; ?> - <?php echo htmlspecialchars(getStoreBranding()['title_text']); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>.glass{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}</style>
    <script defer src="assets/js/security.js?v=3.4"></script>
</head>
<body class="min-h-screen bg-[#0d0d10] text-gray-100 flex items-center justify-center p-4">
    <main class="w-full max-w-md glass rounded-2xl p-6 md:p-8 shadow-2xl">
        <div class="text-center mb-6">
            <div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-green-500/15 text-green-300 flex items-center justify-center text-2xl"><i class="bi bi-shield-lock"></i></div>
            <h1 class="text-2xl font-bold"><?php echo $isTh ? 'ตั้งรหัสผ่านใหม่' : 'Set a new password'; ?></h1>
            <?php if ($tokenData): ?><p class="text-gray-400 text-sm mt-2"><?php echo $isTh ? 'บัญชี' : 'Account'; ?>: <?php echo htmlspecialchars((string) $tokenData['username']); ?></p><?php endif; ?>
        </div>

        <?php if ($error !== ''): ?><div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-200 text-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <?php if (!$tokenData): ?>
            <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-4 text-amber-100 text-sm">
                <?php echo $isTh ? 'ลิงก์นี้ไม่ถูกต้อง ถูกใช้ไปแล้ว หรือหมดอายุ กรุณาขอลิงก์ใหม่' : 'This link is invalid, expired, or has already been used. Please request a new one.'; ?>
            </div>
            <a href="forgot_password.php" class="mt-5 flex items-center justify-center rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-3 font-semibold transition"><?php echo $isTh ? 'ขอลิงก์ใหม่' : 'Request a new link'; ?></a>
        <?php else: ?>
            <form method="post" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label for="new_password" class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'รหัสผ่านใหม่' : 'New password'; ?></label>
                    <input id="new_password" name="new_password" type="password" required minlength="8" maxlength="200" autocomplete="new-password"
                           class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none focus:border-green-400">
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'ยืนยันรหัสผ่านใหม่' : 'Confirm new password'; ?></label>
                    <input id="confirm_password" name="confirm_password" type="password" required minlength="8" maxlength="200" autocomplete="new-password"
                           class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none focus:border-green-400">
                </div>
                <p class="text-xs text-gray-500"><?php echo $isTh ? 'หลังเปลี่ยนสำเร็จ อุปกรณ์ที่เคยจดจำบัญชีนี้จะต้องเข้าสู่ระบบใหม่' : 'Remembered devices will need to sign in again after the password is changed.'; ?></p>
                <button type="submit" class="w-full rounded-xl bg-green-600 hover:bg-green-500 px-4 py-3 font-semibold transition"><i class="bi bi-check2-circle mr-2"></i><?php echo $isTh ? 'บันทึกรหัสผ่านใหม่' : 'Save new password'; ?></button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="mt-5 flex items-center justify-center text-sm text-gray-400 hover:text-white"><i class="bi bi-arrow-left mr-2"></i><?php echo $isTh ? 'กลับไปหน้าเข้าสู่ระบบ' : 'Back to sign in'; ?></a>
    </main>
</body>
</html>
