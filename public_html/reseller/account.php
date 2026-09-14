<?php
require_once '../includes/auth.php';
requireLogin();
requireActive();

if (!isReseller() && !isAdmin()) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$me = $userId > 0 ? getUserById($userId) : null;
$emailChangeAvailable = $me && (int) ($me['email_change_used'] ?? 0) === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $username = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
    $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
    $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $result = updateOwnAccount($userId, $username, $email, $currentPassword, $newPassword, $confirmPassword);
    if ($result['success']) {
        $success = !empty($result['email_changed'])
            ? (getAppLang() === 'en' ? 'Account updated. Your one-time email change has been used.' : 'อัปเดตบัญชีแล้ว และใช้สิทธิ์เปลี่ยนอีเมลด้วยตัวเองครบ 1 ครั้งแล้ว')
            : 'Account updated successfully';
        logHistory($userId, 'update_account', 'Updated account settings');
        $me = getUserById($userId);
        $emailChangeAvailable = $me && (int) ($me['email_change_used'] ?? 0) === 0;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="nav.account"><?php echo Lang::t('nav.account'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}</style>
    <style>
        .glass { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); }
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="p-6 max-w-3xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <i class="bi bi-person-gear text-green-400"></i>
                <span data-lang="nav.account"><?php echo Lang::t('nav.account'); ?></span>
            </h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="glass border border-red-500/30 text-red-200 px-4 py-3 rounded-lg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="glass border border-green-500/30 text-green-200 px-4 py-3 rounded-lg"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="glass rounded-xl p-6">
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-gray-400 text-sm mb-2 block" data-lang="account.username"><?php echo Lang::t('account.username'); ?></label>
                        <input type="text" name="username" required maxlength="60"
                               class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                               value="<?php echo htmlspecialchars($me['username'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="text-gray-400 text-sm mb-2 block" data-lang="account.email"><?php echo Lang::t('account.email'); ?></label>
                        <input type="text" name="email" required maxlength="190" inputmode="email" autocapitalize="none"
                               <?php echo $emailChangeAvailable ? '' : 'readonly'; ?>
                               class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white <?php echo $emailChangeAvailable ? '' : 'opacity-60 cursor-not-allowed'; ?>"
                               value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>">
                        <small class="text-gray-500 text-xs block mt-1">
                            <?php if ($emailChangeAvailable): ?>
                                <?php echo getAppLang() === 'en' ? 'Gmail only. You may change it yourself once; the right does not expire.' : 'รองรับเฉพาะ Gmail คุณเปลี่ยนด้วยตัวเองได้ 1 ครั้ง และสิทธิ์นี้ไม่มีวันหมดอายุ'; ?>
                            <?php else: ?>
                                <?php echo getAppLang() === 'en' ? 'Your one-time email change has been used. Contact an administrator for another change.' : 'ใช้สิทธิ์เปลี่ยนอีเมลด้วยตัวเองแล้ว หากต้องการเปลี่ยนอีกให้ติดต่อแอดมิน'; ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-gray-400 text-sm mb-2 block" data-lang="account.new_password"><?php echo Lang::t('account.new_password'); ?></label>
                        <input type="password" name="new_password"
                               class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                               data-lang-placeholder="account.new_password_placeholder"
                               placeholder="<?php echo Lang::t('account.new_password_placeholder'); ?>">
                    </div>
                    <div>
                        <label class="text-gray-400 text-sm mb-2 block" data-lang="account.confirm_new_password"><?php echo Lang::t('account.confirm_new_password'); ?></label>
                        <input type="password" name="confirm_password"
                               class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white"
                               data-lang-placeholder="account.confirm_new_password_placeholder"
                               placeholder="<?php echo Lang::t('account.confirm_new_password_placeholder'); ?>">
                    </div>
                </div>

                <div>
                    <label class="text-gray-400 text-sm mb-2 block" data-lang="account.current_password"><?php echo Lang::t('account.current_password'); ?></label>
                    <input type="password" name="current_password" required
                           class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white">
                    <small class="text-gray-500 text-xs" data-lang="account.current_password_hint"><?php echo Lang::t('account.current_password_hint'); ?></small>
                </div>

                <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg font-medium transition flex items-center gap-2">
                    <i class="bi bi-save"></i>
                    <span data-lang="account.save_changes"><?php echo Lang::t('account.save_changes'); ?></span>
                </button>
            </form>
        </div>
    </main>
</body>
</html>
