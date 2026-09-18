<?php
require_once '../includes/auth.php';
requireAdmin();

global $conn;
$error = '';
$success = '';
$expectedRole = 'user';
$userSearch = isset($_GET['q']) && is_scalar($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';
$userSearchOriginal = $userSearch;
if (function_exists('mb_substr')) {
    $userSearch = mb_substr($userSearchOriginal, 0, 120, 'UTF-8');
} elseif (preg_match_all('/./us', $userSearchOriginal, $userSearchCharacters) !== false) {
    $userSearch = implode('', array_slice($userSearchCharacters[0], 0, 120));
} else {
    $userSearch = substr($userSearchOriginal, 0, 120);
}
unset($userSearchOriginal, $userSearchCharacters);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add') {
        $username = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $balance = isset($_POST['balance']) && is_scalar($_POST['balance']) ? (float) $_POST['balance'] : 0.0;
        $result = createManagedAccount($username, $email, $password, $expectedRole, $balance);
        if ($result['success']) {
            $success = 'User added successfully';
            logHistory((int) $_SESSION['user_id'], 'add_user', 'Added user: ' . $result['username']);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'update_email') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $newEmail = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
        $target = getUserById($targetId);
        $result = accountRecoveryAdminUpdateEmail($targetId, $expectedRole, $newEmail);
        if ($result['success']) {
            $success = 'User email updated successfully';
            logHistory((int) $_SESSION['user_id'], 'admin_update_user_email', 'Changed email for: ' . ($target['username'] ?? ('ID ' . $targetId)) . ' to ' . $result['email']);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'ban' || $action === 'unban') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $status = $action === 'ban' ? 'banned' : 'active';
        $target = getUserById($targetId);
        $result = setManagedAccountStatus($targetId, $expectedRole, $status);
        if ($result['success']) {
            if ($action === 'ban') {
                $securityBlock = accountVerificationBlockKnownUserSignals(
                    $targetId,
                    'บล็อคพร้อมบัญชี user โดยผู้ดูแลระบบ',
                    (int) $_SESSION['user_id']
                );
                $success = !empty($securityBlock['success'])
                    ? 'User banned; email, known devices, and IPs were added to the shared block list'
                    : 'User banned locally. ' . (string) ($securityBlock['message'] ?? 'Shared security blocking was not fully completed');
            } else {
                $success = 'User activated. Shared Email/IP/Device blocks remain until manually removed in Security';
            }
            logHistory((int) $_SESSION['user_id'], $action . '_user', ucfirst($action) . ' user: ' . ($target['username'] ?? ('ID ' . $targetId)));
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'delete') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $target = getUserById($targetId);
        $result = deleteManagedAccount($targetId, $expectedRole);
        if ($result['success']) {
            $preserved = ($result['mode'] ?? '') === 'disabled';
            $success = $preserved
                ? 'User disabled to preserve transaction and key history'
                : 'User deleted successfully';
            logHistory(
                (int) $_SESSION['user_id'],
                $preserved ? 'disable_user_preserve_history' : 'delete_user',
                ($preserved ? 'Disabled user to preserve history: ' : 'Deleted user: ') . ($target['username'] ?? ('ID ' . $targetId))
            );
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'update_balance') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
        $amount = isset($_POST['amount']) && is_scalar($_POST['amount']) ? (float) $_POST['amount'] : 0.0;
        $result = adjustManagedAccountBalance($targetId, $expectedRole, $operation, $amount);
        if ($result['success']) {
            $success = $operation === 'add' ? 'Balance added successfully' : 'Balance deducted successfully';
            logHistory((int) $_SESSION['user_id'], 'update_balance', 'Updated balance for: ' . $result['username']);
        } else {
            $error = $result['message'];
        }
    }
}

$userPage = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$userPageData = getManagedAccountsPage('user', $userSearch, $userPage, 50);
$users = $userPageData['rows'];
$userTotal = (int) $userPageData['total'];
$userPage = (int) $userPageData['page'];
$userPages = (int) $userPageData['pages'];
$usersPageEnglish = getAppLang() === 'en';
$buildUsersPageUrl = static function (int $page) use ($userSearch): string {
    $params = ['page' => max(1, $page)];
    if ($userSearch !== '') $params['q'] = $userSearch;
    return 'users.php?' . http_build_query($params);
};
$securityUserId = isset($_GET['security_id']) && is_scalar($_GET['security_id']) ? max(0, (int) $_GET['security_id']) : 0;
$userSecurityDetails = $securityUserId > 0 ? accountVerificationAdminAccountSecurityDetails($securityUserId, $expectedRole) : null;
$buildUsersSecurityUrl = static function (int $userId) use ($userSearch, $userPage): string {
    $params = ['security_id' => max(0, $userId), 'page' => max(1, $userPage)];
    if ($userSearch !== '') $params['q'] = $userSearch;
    return 'users.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="admin.users.title"><?php echo Lang::t('admin.users.title'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#6366f1;--sakazuki-accent-rgb:99 102 241;--sakazuki-accent2:#8b5cf6;--sakazuki-accent2-rgb:139 92 246;--sakazuki-glow:0 0 25px rgba(99,102,241,0.35)}</style>
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeInUp .15s ease-out;
        }
    </style>
</head>

<body class="bg-darkbg text-gray-100 min-h-screen">
    <?php include 'nav.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4 md:space-y-6">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 md:mb-6 gap-2">
            <h3 class="text-xl md:text-2xl font-bold text-white"><i class="bi bi-people mr-2"></i><span data-lang="admin.users.heading"><?php echo Lang::t('admin.users.heading'); ?></span></h3>
            <button onclick="openAddModal()"
                class="bg-accent hover:opacity-90 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg font-medium transition text-sm md:text-base w-full md:w-auto">
                <i class="bi bi-person-plus mr-1 md:mr-2"></i><span data-lang="admin.users.add_user_btn"><?php echo Lang::t('admin.users.add_user_btn'); ?></span>
            </button>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div
                class="glass border border-red-500/50 p-3 md:p-4 rounded-lg bg-red-900/20 text-red-300 text-sm md:text-base">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div
                class="glass border border-green-500/50 p-3 md:p-4 rounded-lg bg-green-900/20 text-green-300 text-sm md:text-base">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Account Search -->
        <div class="glass rounded-lg md:rounded-xl p-4 animate-fade-in">
            <form method="GET" action="users.php" class="flex flex-col sm:flex-row gap-2">
                <label for="userSearch" class="sr-only">
                    <?php echo $usersPageEnglish ? 'Search customer accounts' : 'ค้นหาบัญชีลูกค้า'; ?>
                </label>
                <div class="relative flex-1">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input
                        id="userSearch"
                        type="search"
                        name="q"
                        value="<?php echo htmlspecialchars($userSearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                        maxlength="120"
                        enterkeyhint="search"
                        autocomplete="off"
                        placeholder="<?php echo $usersPageEnglish ? 'ID, username, or email' : 'ค้นหาจาก ID, ชื่อผู้ใช้ หรืออีเมล'; ?>"
                        class="w-full bg-black/20 border border-white/10 rounded-lg py-2.5 pl-10 pr-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-accent">
                </div>
                <button type="submit"
                    class="bg-accent hover:opacity-90 text-white px-5 py-2.5 rounded-lg font-medium transition text-sm">
                    <i class="bi bi-search mr-1"></i>
                    <?php echo $usersPageEnglish ? 'Search' : 'ค้นหา'; ?>
                </button>
                <?php if ($userSearch !== ''): ?>
                    <a href="users.php"
                        class="bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 px-4 py-2.5 rounded-lg font-medium transition text-sm text-center">
                        <i class="bi bi-x-circle mr-1"></i>
                        <?php echo $usersPageEnglish ? 'Clear' : 'ล้างการค้นหา'; ?>
                    </a>
                <?php endif; ?>
            </form>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-1 text-xs text-gray-500">
                <span>
                    <?php echo $usersPageEnglish
                        ? 'Type the full term, then press Search or Enter.'
                        : 'พิมพ์ข้อมูลให้ครบ แล้วกดค้นหาหรือ Enter'; ?>
                </span>
                <?php if ($userSearch !== ''): ?>
                    <span>
                        <?php echo $usersPageEnglish ? 'Found' : 'พบ'; ?>
                        <strong class="text-gray-300"><?php echo $userTotal; ?></strong>
                        <?php echo $usersPageEnglish ? 'account(s)' : 'บัญชี'; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (is_array($userSecurityDetails) && !empty($userSecurityDetails['success'])):
            $securityAccount = $userSecurityDetails['account'];
            $securityDevices = is_array($userSecurityDetails['devices'] ?? null) ? $userSecurityDetails['devices'] : [];
            $rememberedDevices = is_array($userSecurityDetails['remembered_devices'] ?? null) ? $userSecurityDetails['remembered_devices'] : [];
        ?>
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-3" id="securityDetailModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <section class="glass rounded-lg md:rounded-xl p-4 md:p-6 w-full" style="max-width:72rem;max-height:92vh;overflow:auto;">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-base md:text-lg font-semibold text-white"><i class="bi bi-shield-check text-indigo-300 mr-2"></i><?php echo $usersPageEnglish ? 'Security details' : 'รายละเอียดความปลอดภัย'; ?> · <?php echo htmlspecialchars((string) ($securityAccount['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="text-xs md:text-sm text-gray-400 mt-1"><?php echo $usersPageEnglish ? 'Local account data plus device observations synchronized through Shared Security.' : 'ข้อมูลบัญชีในฐานนี้ + อุปกรณ์ที่ซิงก์ผ่าน Shared Security'; ?></p>
                </div>
                <button type="button" onclick="closeSecurityDetailModal()" class="rounded-lg bg-white/10 hover:bg-white/15 px-3 py-2 text-sm text-center"><?php echo $usersPageEnglish ? 'Close' : 'ปิดรายละเอียด'; ?></button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 text-sm mb-4">
                <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">Email</div><div class="mt-1 break-all select-all"><?php echo htmlspecialchars((string) ($securityAccount['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs <?php echo !empty($userSecurityDetails['email_blocked']) ? 'text-red-300' : 'text-gray-500'; ?>"><?php echo !empty($userSecurityDetails['email_blocked']) ? ($usersPageEnglish ? 'Shared block: active' : 'อยู่ในรายการบล็อคร่วม') : ($usersPageEnglish ? 'Shared block: none' : 'ไม่อยู่ในรายการบล็อคร่วม'); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">Email OTP</div><div class="mt-1"><?php echo !empty($securityAccount['email_verified_at']) ? ($usersPageEnglish ? 'Verified' : 'ยืนยันแล้ว') : ($usersPageEnglish ? 'Not verified' : 'ยังไม่ยืนยัน'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($securityAccount['email_verified_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $usersPageEnglish ? 'Account status' : 'สถานะบัญชี'; ?></div><div class="mt-1"><?php echo htmlspecialchars((string) ($securityAccount['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500">ID #<?php echo (int) ($securityAccount['id'] ?? 0); ?></div></div>
                <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $usersPageEnglish ? 'Created' : 'สร้างบัญชี'; ?></div><div class="mt-1"><?php echo htmlspecialchars((string) ($securityAccount['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($userSecurityDetails['site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
            <div class="overflow-x-auto rounded-xl border border-white/10">
                <table class="min-w-full text-xs md:text-sm">
                    <thead class="text-gray-500 bg-white/5"><tr><th class="text-left p-2">อุปกรณ์</th><th class="text-left p-2">เบราว์เซอร์</th><th class="text-left p-2">IP แรก</th><th class="text-left p-2">IP ล่าสุด</th><th class="text-left p-2">เห็นครั้งแรก</th><th class="text-left p-2">ล่าสุด</th><th class="p-2"></th></tr></thead>
                    <tbody>
                    <?php foreach ($securityDevices as $securityDevice): ?>
                        <tr class="border-t border-white/5"><td class="p-2"><?php echo htmlspecialchars((string) ($securityDevice['device_label'] ?? 'ไม่ทราบรุ่น'), ENT_QUOTES, 'UTF-8'); ?><div class="mono text-xs text-gray-600"><?php echo htmlspecialchars(substr((string) ($securityDevice['device_hash'] ?? ''), 0, 16) . '…', ENT_QUOTES, 'UTF-8'); ?></div></td><td class="p-2"><?php echo htmlspecialchars((string) ($securityDevice['browser_label'] ?? 'ไม่ทราบ'), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2 mono"><?php echo htmlspecialchars((string) ($securityDevice['first_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2 mono"><?php echo htmlspecialchars((string) ($securityDevice['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($securityDevice['first_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($securityDevice['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php if (!empty($securityDevice['device_hash']) && !empty($userSecurityDetails['site_id'])): ?><a class="text-indigo-300 hover:text-white" href="security.php?<?php echo htmlspecialchars(http_build_query(['detail_site' => (string) $userSecurityDetails['site_id'], 'detail_user' => (int) ($securityAccount['id'] ?? 0), 'detail_device' => (string) $securityDevice['device_hash']]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $usersPageEnglish ? 'More' : 'เพิ่มเติม'; ?></a><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($securityDevices === []): ?><tr><td colspan="7" class="p-4 text-center text-gray-500"><?php echo $usersPageEnglish ? 'No device observations yet.' : 'ยังไม่มีข้อมูลอุปกรณ์'; ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 rounded-xl border border-white/10 overflow-hidden">
                <div class="px-4 py-3 bg-white/5">
                    <div class="font-medium"><?php echo $usersPageEnglish ? 'Remembered-device sessions' : 'อุปกรณ์ที่กดจดจำ 7–30 วัน'; ?></div>
                    <div class="text-xs text-gray-500"><?php echo $usersPageEnglish ? 'The token value is never shown. Only last IP / use time / expiry are displayed.' : 'ไม่แสดง token จริง แสดงเฉพาะ IP ล่าสุด เวลาใช้งาน และวันหมดอายุ'; ?></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="text-gray-500"><tr><th class="text-left p-2">IP ล่าสุด</th><th class="text-left p-2"><?php echo $usersPageEnglish ? 'Last used' : 'ใช้ล่าสุด'; ?></th><th class="text-left p-2"><?php echo $usersPageEnglish ? 'Expires' : 'หมดอายุ'; ?></th><th class="text-left p-2"><?php echo $usersPageEnglish ? 'Remaining' : 'คงเหลือ'; ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($rememberedDevices as $remembered): ?>
                            <tr class="border-t border-white/5"><td class="p-2 mono"><?php echo htmlspecialchars((string) ($remembered['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($remembered['last_used_at'] ?? $remembered['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($remembered['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo isset($remembered['remaining_days']) ? (int) $remembered['remaining_days'] . ($usersPageEnglish ? ' day(s)' : ' วัน') : '-'; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if ($rememberedDevices === []): ?><tr><td colspan="4" class="p-3 text-center text-gray-500"><?php echo $usersPageEnglish ? 'No active remembered-device token.' : 'ไม่มี Remember Device ที่ยังไม่หมดอายุ'; ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if (empty($userSecurityDetails['shared_available'])): ?><p class="text-xs text-yellow-300 mt-3"><?php echo $usersPageEnglish ? 'Shared Security is temporarily unavailable; this panel is showing local device records.' : 'Shared Security เชื่อมต่อไม่ได้ชั่วคราว หน้านี้กำลังแสดงข้อมูลอุปกรณ์จากฐานปัจจุบัน'; ?></p><?php endif; ?>
        </section>
        </div>
        <?php elseif ($securityUserId > 0): ?>
        <div class="glass rounded-xl p-4 mb-4 text-yellow-300 text-sm"><?php echo $usersPageEnglish ? 'Security details could not be loaded for this account.' : 'ไม่สามารถโหลดรายละเอียดความปลอดภัยของบัญชีนี้ได้'; ?></div>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="glass rounded-lg md:rounded-xl overflow-hidden animate-fade-in">
            <div class="p-4 md:p-6">
                <div class="overflow-x-auto -mx-3 md:mx-0">
                    <table class="w-full min-w-[700px] md:min-w-full">
                        <thead>
                             <tr class="border-b border-white/10">
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.id"><?php echo Lang::t('admin.users.table.id'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.username">
                                    <?php echo Lang::t('admin.users.table.username'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.email"><?php echo Lang::t('admin.users.table.email'); ?>
                                </th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.balance"><?php echo Lang::t('admin.users.table.balance'); ?>
                                </th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.status"><?php echo Lang::t('admin.users.table.status'); ?>
                                </th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.joined"><?php echo Lang::t('admin.users.table.joined'); ?>
                                </th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.action"><?php echo Lang::t('common.action'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                 <tr>
                                    <?php if ($userSearch !== ''): ?>
                                        <td colspan="7" class="text-center py-6 md:py-8 text-gray-500 text-sm md:text-base">
                                            <i class="bi bi-person-x text-2xl block mb-2"></i>
                                            <?php echo $usersPageEnglish
                                                ? 'No customer account matches this search.'
                                                : 'ไม่พบบัญชีลูกค้าที่ตรงกับคำค้นหา'; ?>
                                        </td>
                                    <?php else: ?>
                                        <td colspan="7" class="text-center py-6 md:py-8 text-gray-500 text-sm md:text-base" data-lang="admin.users.empty"><?php echo Lang::t('admin.users.empty'); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $index => $user): ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5 transition"
                                        style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-xs md:text-sm"><?php echo $user['id']; ?></td>
                                        <td
                                            class="py-2 px-2 md:py-3 md:px-4 font-medium text-xs md:text-sm truncate max-w-[100px]">
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </td>
                                        <td
                                            class="py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm truncate max-w-[120px]">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </td>
                                         <td class="py-2 px-2 md:py-3 md:px-4">
                                            <div class="flex flex-col md:flex-row md:items-center gap-1 md:gap-2">
                                                <span
                                                    class="text-green-400 text-xs md:text-sm"><?php echo formatCurrency($user['balance']); ?></span>
                                                 <button
                                                    onclick="updateBalanceModal(<?php echo (int)$user['id']; ?>, <?php echo htmlJsArg((string)$user['username']); ?>, <?php echo json_encode((float)$user['balance']); ?>)"
                                                    class="text-xs px-1.5 py-0.5 md:px-2 md:py-1 rounded bg-green-500/20 text-green-400 hover:bg-green-500/30 transition"
                                                    data-lang-title="admin.users.modal.balance_title"
                                                    title="<?php echo Lang::t('admin.users.modal.balance_title'); ?>">
                                                    <i class="bi bi-plus-circle mr-0.5 md:mr-1 text-xs"></i><span data-lang="common.add"><?php echo Lang::t('common.add'); ?></span>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <?php if ($user['status'] == 'active'): ?>
                                                <span
                                                    class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400" data-lang="admin.users.status.active"><?php echo Lang::t('admin.users.status.active'); ?></span>
                                            <?php else: ?>
                                                <span
                                                    class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-red-500/20 text-red-400" data-lang="admin.users.status.banned"><?php echo Lang::t('admin.users.status.banned'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm">
                                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                        </td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <div class="flex items-center space-x-1 md:space-x-2">
                                                 <?php if ($user['status'] == 'active'): ?>
                                                    <button
                                                        onclick="banUser(<?php echo (int)$user['id']; ?>, <?php echo htmlJsArg((string)$user['username']); ?>)"
                                                        class="p-1.5 md:p-2 rounded-lg hover:bg-red-500/20 text-red-400 transition"
                                                        data-lang-title="admin.users.ban_title"
                                                        title="<?php echo Lang::t('admin.users.ban_title'); ?>">
                                                        <i class="bi bi-ban text-xs md:text-sm"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        onclick="unbanUser(<?php echo (int)$user['id']; ?>, <?php echo htmlJsArg((string)$user['username']); ?>)"
                                                        class="p-1.5 md:p-2 rounded-lg hover:bg-green-500/20 text-green-400 transition"
                                                        data-lang-title="admin.users.unban_title"
                                                        title="<?php echo Lang::t('admin.users.unban_title'); ?>">
                                                        <i class="bi bi-check-circle text-xs md:text-sm"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <a href="<?php echo htmlspecialchars($buildUsersSecurityUrl((int) $user['id']), ENT_QUOTES, 'UTF-8'); ?>" class="p-1.5 md:p-2 rounded-lg hover:bg-indigo-500/20 text-indigo-300 transition" title="<?php echo $usersPageEnglish ? 'Security details' : 'รายละเอียด IP / อุปกรณ์ / OTP'; ?>">
                                                    <i class="bi bi-shield-check text-xs md:text-sm"></i>
                                                </a>
                                                <button
                                                    onclick="editUserEmail(<?php echo (int)$user['id']; ?>, <?php echo htmlJsArg((string)$user['username']); ?>, <?php echo htmlJsArg((string)$user['email']); ?>)"
                                                    class="p-1.5 md:p-2 rounded-lg hover:bg-blue-500/20 text-blue-400 transition"
                                                    title="เปลี่ยน Gmail">
                                                    <i class="bi bi-envelope-at text-xs md:text-sm"></i>
                                                </button>
                                                <button
                                                    onclick="deleteUser(<?php echo (int)$user['id']; ?>, <?php echo htmlJsArg((string)$user['username']); ?>)"
                                                    class="p-1.5 md:p-2 rounded-lg hover:bg-red-500/20 text-red-400 transition"
                                                    data-lang-title="admin.users.delete_title"
                                                    title="<?php echo Lang::t('admin.users.delete_title'); ?>">
                                                    <i class="bi bi-trash text-xs md:text-sm"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($userPages > 1): ?>
                    <nav class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm" aria-label="<?php echo $usersPageEnglish ? 'Customer account pages' : 'หน้ารายการบัญชีลูกค้า'; ?>">
                        <div class="text-gray-500">
                            <?php echo $usersPageEnglish ? 'Total' : 'ทั้งหมด'; ?>
                            <strong class="text-gray-300"><?php echo $userTotal; ?></strong>
                            <?php echo $usersPageEnglish ? 'account(s)' : 'บัญชี'; ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($userPage > 1): ?>
                                <a href="<?php echo htmlspecialchars($buildUsersPageUrl($userPage - 1), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-gray-300 hover:bg-white/10"><?php echo $usersPageEnglish ? 'Previous' : 'ก่อนหน้า'; ?></a>
                            <?php endif; ?>
                            <span class="rounded-lg bg-accent/15 px-3 py-2 text-indigo-200"><?php echo $userPage; ?> / <?php echo $userPages; ?></span>
                            <?php if ($userPage < $userPages): ?>
                                <a href="<?php echo htmlspecialchars($buildUsersPageUrl($userPage + 1), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-gray-300 hover:bg-white/10"><?php echo $usersPageEnglish ? 'Next' : 'ถัดไป'; ?></a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add User Modal -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">
            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i
                        class="bi bi-person-plus text-accent mr-2"></i><span data-lang="admin.users.modal.add_title"><?php echo Lang::t('admin.users.modal.add_title'); ?></span></h5>
                <button type="button" aria-label="Close" onclick="closeModal('addModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.table.username"><?php echo Lang::t('admin.users.table.username'); ?></label>
                    <input type="text"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="username" required>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.table.email"><?php echo Lang::t('admin.users.table.email'); ?></label>
                    <input type="email"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="email" required pattern="^[^@\s]+@gmail\.com$" placeholder="example@gmail.com">
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="common.password"><?php echo Lang::t('common.password'); ?></label>
                    <input type="password"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="password" required>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.modal.initial_balance"><?php echo Lang::t('admin.users.modal.initial_balance'); ?></label>
                    <input type="number" step="0.01"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="balance" value="0" required>
                </div>
                <div class="flex flex-col md:flex-row gap-2 md:gap-3">
                    <button type="submit"
                        class="bg-accent hover:opacity-90 p-2 text-white py-2 rounded-lg font-medium transition text-sm md:text-base" data-lang="admin.users.add_user_btn"><?php echo Lang::t('admin.users.add_user_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Balance Modal -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="balanceModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">
            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i
                        class="bi bi-wallet2 text-green-400 mr-2"></i><span data-lang="admin.users.modal.balance_title"><?php echo Lang::t('admin.users.modal.balance_title'); ?></span></h5>
                <button type="button" aria-label="Close" onclick="closeModal('balanceModal')"
                    class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_balance">
                <input type="hidden" name="user_id" id="balance_user_id">
                <div class="mb-3 md:mb-4">
                    <p class="text-gray-400 text-sm md:text-base mb-1"><span data-lang="admin.users.modal.user_label"><?php echo Lang::t('admin.users.modal.user_label'); ?></span> <strong class="text-white"
                            id="balance_username"></strong></p>
                    <p class="text-gray-400 text-xs md:text-sm"><span data-lang="admin.users.modal.current_balance"><?php echo Lang::t('admin.users.modal.current_balance'); ?></span> <strong class="text-green-400"
                            id="balance_current">฿0.00</strong></p>
                </div>
                <div class="mb-3 md:mb-4">
                    <label for="balance_operation" class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block"><?php echo $usersPageEnglish ? 'Operation' : 'รายการ'; ?></label>
                    <select id="balance_operation" name="operation" required onchange="updateBalancePreview()"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-panel text-white text-sm md:text-base">
                        <option value="add"><?php echo $usersPageEnglish ? 'Add balance' : 'เพิ่มเงิน'; ?></option>
                        <option value="deduct"><?php echo $usersPageEnglish ? 'Deduct balance' : 'ลบเงิน'; ?></option>
                    </select>
                </div>
                <div class="mb-3 md:mb-4">
                    <label id="balance_amount_label" for="balance_amount" class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block"><?php echo $usersPageEnglish ? 'Amount to add' : 'จำนวนเงินที่เพิ่ม'; ?></label>
                    <input type="number" step="0.01" min="0.01"
                        class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base"
                        name="amount" id="balance_amount" required oninput="updateBalancePreview()">
                </div>
                <div id="balance_preview_box" class="mb-3 md:mb-4 p-3 rounded-lg bg-green-500/10 border border-green-500/30">
                    <p class="text-gray-400 text-xs md:text-sm"><span data-lang="admin.users.modal.new_balance"><?php echo Lang::t('admin.users.modal.new_balance'); ?></span> <strong
                            class="text-green-400 text-base md:text-lg" id="balance_new">฿0.00</strong></p>
                    <p id="balance_warning" class="hidden mt-1 text-xs text-red-300"><?php echo $usersPageEnglish ? 'The deduction exceeds the current balance.' : 'จำนวนเงินที่ลบมากกว่ายอดเงินปัจจุบัน'; ?></p>
                </div>
                <div class="flex flex-col md:flex-row gap-2 md:gap-3">
                    <button id="balance_submit" type="submit"
                        class="bg-green-500 p-2 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base">
                        <i id="balance_submit_icon" class="bi bi-plus-circle mr-2"></i><span id="balance_submit_text"><?php echo $usersPageEnglish ? 'Add balance' : 'เพิ่มเงิน'; ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let activeManagedModal = null;
        let managedModalReturnFocus = null;

        function getManagedModalFocusable(modal) {
            return Array.from(modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
                .filter((node) => node.offsetParent !== null);
        }

        function showManagedModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            managedModalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            activeManagedModal = modal;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            window.requestAnimationFrame(() => {
                const focusable = getManagedModalFocusable(modal);
                (focusable[0] || modal).focus({preventScroll: true});
            });
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            if (activeManagedModal === modal) activeManagedModal = null;
            document.body.style.overflow = activeManagedModal ? 'hidden' : '';
            if (managedModalReturnFocus instanceof HTMLElement && managedModalReturnFocus.isConnected) {
                managedModalReturnFocus.focus({preventScroll: true});
            }
        }

        function openAddModal() {
            showManagedModal('addModal');
        }

        function banUser(id, username) {
            if (confirm(Lang.t('admin.users.confirm_ban').replace('{username}', username))) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="ban"><input type="hidden" name="user_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function unbanUser(id, username) {
            if (confirm(Lang.t('admin.users.confirm_unban').replace('{username}', username))) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="unban"><input type="hidden" name="user_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        let currentBalanceValue = 0;

        function updateBalanceModal(id, username, balance) {
            currentBalanceValue = Math.max(0, parseFloat(balance) || 0);
            document.getElementById('balance_user_id').value = id;
            document.getElementById('balance_username').textContent = username;
            document.getElementById('balance_current').textContent = Lang.formatCurrency(currentBalanceValue);
            document.getElementById('balance_operation').value = 'add';
            document.getElementById('balance_amount').value = '';
            updateBalancePreview();
            showManagedModal('balanceModal');
        }

        function closeSecurityDetailModal() {
            closeModal('securityDetailModal');
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('security_id');
                history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
            } catch (e) {}
        }

        function updateBalancePreview() {
            const operation = document.getElementById('balance_operation').value === 'deduct' ? 'deduct' : 'add';
            const amountInput = document.getElementById('balance_amount');
            const amount = Math.max(0, parseFloat(amountInput.value) || 0);
            const isDeduct = operation === 'deduct';
            const newBalance = currentBalanceValue + (isDeduct ? -amount : amount);
            const invalidDeduction = isDeduct && amount > currentBalanceValue;
            const preview = document.getElementById('balance_new');
            const previewBox = document.getElementById('balance_preview_box');
            const warning = document.getElementById('balance_warning');
            const submit = document.getElementById('balance_submit');
            const icon = document.getElementById('balance_submit_icon');
            const text = document.getElementById('balance_submit_text');
            const label = document.getElementById('balance_amount_label');

            if (isDeduct) amountInput.max = currentBalanceValue.toFixed(2);
            else amountInput.removeAttribute('max');
            preview.textContent = Lang.formatCurrency(Math.max(0, newBalance));
            preview.classList.toggle('text-red-400', invalidDeduction);
            preview.classList.toggle('text-green-400', !invalidDeduction);
            previewBox.classList.toggle('bg-red-500/10', invalidDeduction);
            previewBox.classList.toggle('border-red-500/30', invalidDeduction);
            previewBox.classList.toggle('bg-green-500/10', !invalidDeduction);
            previewBox.classList.toggle('border-green-500/30', !invalidDeduction);
            warning.classList.toggle('hidden', !invalidDeduction);
            submit.disabled = invalidDeduction || amount <= 0;
            submit.classList.toggle('opacity-50', submit.disabled);
            submit.classList.toggle('cursor-not-allowed', submit.disabled);
            submit.classList.toggle('bg-red-500', isDeduct);
            submit.classList.toggle('bg-green-500', !isDeduct);
            icon.className = isDeduct ? 'bi bi-dash-circle mr-2' : 'bi bi-plus-circle mr-2';
            text.textContent = isDeduct
                ? <?php echo json_encode($usersPageEnglish ? 'Deduct balance' : 'ลบเงิน', JSON_UNESCAPED_UNICODE); ?>
                : <?php echo json_encode($usersPageEnglish ? 'Add balance' : 'เพิ่มเงิน', JSON_UNESCAPED_UNICODE); ?>;
            label.textContent = isDeduct
                ? <?php echo json_encode($usersPageEnglish ? 'Amount to deduct' : 'จำนวนเงินที่ลบ', JSON_UNESCAPED_UNICODE); ?>
                : <?php echo json_encode($usersPageEnglish ? 'Amount to add' : 'จำนวนเงินที่เพิ่ม', JSON_UNESCAPED_UNICODE); ?>;
        }

        function editUserEmail(id, username, currentEmail) {
            const message = (typeof Lang !== 'undefined' && window.PHP_LANG === 'en')
                ? 'Enter the new Gmail address for ' + username
                : 'ระบุ Gmail ใหม่สำหรับ ' + username;
            const email = prompt(message, currentEmail || '');
            if (email === null) return;
            const normalized = email.trim().toLowerCase();
            if (!/^[^@\s]+@gmail\.com$/.test(normalized)) {
                alert(window.PHP_LANG === 'en' ? 'Only @gmail.com addresses are supported.' : 'รองรับเฉพาะอีเมล @gmail.com เท่านั้น');
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<?php echo csrfField(); ?>'
                + '<input type="hidden" name="action" value="update_email">'
                + '<input type="hidden" name="user_id" value="' + id + '">'
                + '<input type="hidden" name="email" value="">';
            form.querySelector('input[name="email"]').value = normalized;
            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('keydown', function (event) {
            const modal = activeManagedModal;
            if (!modal || modal.classList.contains('hidden')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal(modal.id);
                return;
            }
            if (event.key !== 'Tab') return;
            const focusable = getManagedModalFocusable(modal);
            if (focusable.length === 0) { event.preventDefault(); modal.focus(); return; }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });

        <?php if (is_array($userSecurityDetails) && !empty($userSecurityDetails['success'])): ?>
        showManagedModal('securityDetailModal');
        <?php endif; ?>

        function deleteUser(id, username) {
            if (confirm(Lang.t('admin.users.confirm_delete').replace('{username}', username))) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>

</html>
