<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/account_recovery.php';

if (isLoggedIn()) redirectByRole();

$lang = getAppLang();
$isTh = $lang !== 'en';
$error = '';
$success = '';
$email = '';
$recoveryReadiness = accountRecoveryActivationReadiness();
$recoveryAvailable = !empty($recoveryReadiness['ready']) && accountRecoveryGetMailConfig()['enabled'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('forgot_password.php');
    $email = isset($_POST['email']) && is_scalar($_POST['email']) ? trim((string) $_POST['email']) : '';
    if (!$recoveryAvailable) {
        $error = $isTh ? 'ระบบกู้รหัสผ่านทางอีเมลปิดให้บริการชั่วคราว กรุณาติดต่อแอดมิน' : 'Email password recovery is temporarily unavailable. Please contact the administrator.';
    } elseif (!accountRecoveryIsGmail($email)) {
        $error = $isTh ? 'กรุณาระบุอีเมล @gmail.com ให้ถูกต้อง' : 'Please enter a valid @gmail.com address.';
    } else {
        $result = accountRecoveryRequestPasswordReset($email);
        $success = $isTh
            ? 'หาก Gmail นี้ตรงกับบัญชี ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ให้ กรุณาตรวจสอบกล่องจดหมายและโฟลเดอร์สแปม'
            : 'If this Gmail address matches an account, a reset link will be sent. Please check your inbox and spam folder.';
        $email = '';
    }
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo $isTh ? 'ลืมรหัสผ่าน' : 'Forgot password'; ?> - <?php echo htmlspecialchars(getStoreBranding()['title_text']); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>.glass{background:rgba(255,255,255,.04);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}</style>
    <script defer src="assets/js/security.js?v=3.4"></script>
</head>
<body class="min-h-screen bg-[#0d0d10] text-gray-100 flex items-center justify-center p-4">
    <main class="w-full max-w-md glass rounded-2xl p-6 md:p-8 shadow-2xl">
        <div class="text-center mb-6">
            <div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-blue-500/15 text-blue-300 flex items-center justify-center text-2xl"><i class="bi bi-envelope-lock"></i></div>
            <h1 class="text-2xl font-bold"><?php echo $isTh ? 'ลืมรหัสผ่าน' : 'Forgot password'; ?></h1>
            <p class="text-gray-400 text-sm mt-2"><?php echo $isTh ? 'ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ไปยัง Gmail ที่ผูกกับบัญชี' : 'A password reset link will be sent to the Gmail address linked to your account.'; ?></p>
        </div>

        <?php if ($error !== ''): ?><div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-200 text-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (!$recoveryAvailable && $error === ''): ?><div class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-100 text-sm"><?php echo $isTh ? 'ระบบกู้รหัสผ่านทาง Gmail ยังไม่เปิดใช้งาน กรุณาติดต่อแอดมิน' : 'Gmail password recovery is not currently enabled.'; ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="mb-4 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-green-200 text-sm"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <form method="post" class="space-y-4" autocomplete="off">
            <?php echo csrfField(); ?>
            <div>
                <label for="email" class="block text-sm text-gray-300 mb-2"><?php echo $isTh ? 'Gmail ของบัญชี' : 'Account Gmail'; ?></label>
                <input id="email" name="email" type="email" required maxlength="190" autocomplete="email" inputmode="email"
                       pattern="^[^@\s]+@gmail\.com$" placeholder="example@gmail.com"
                       value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo $recoveryAvailable ? '' : 'disabled'; ?>
                       class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none focus:border-blue-400 disabled:opacity-50">
            </div>
            <button type="submit" <?php echo $recoveryAvailable ? '' : 'disabled'; ?> class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-3 font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="bi bi-send mr-2"></i><?php echo $isTh ? 'ส่งลิงก์รีเซ็ตรหัสผ่าน' : 'Send reset link'; ?>
            </button>
        </form>

        <a href="login.php" class="mt-5 flex items-center justify-center text-sm text-gray-400 hover:text-white"><i class="bi bi-arrow-left mr-2"></i><?php echo $isTh ? 'กลับไปหน้าเข้าสู่ระบบ' : 'Back to sign in'; ?></a>
    </main>
</body>
</html>
