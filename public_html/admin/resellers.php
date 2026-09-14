<?php
require_once '../includes/auth.php';
requireAdmin();

global $conn;
$error = '';
$success = '';
$expectedRole = 'reseller';
$resellerSearch = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
if (function_exists('mb_substr')) {
    $resellerSearch = mb_substr($resellerSearch, 0, 120, 'UTF-8');
} else {
    $resellerSearch = substr($resellerSearch, 0, 120);
}

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
            $success = 'Reseller added successfully';
            logHistory((int) $_SESSION['user_id'], 'add_reseller', 'Added reseller: ' . $result['username']);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'update_email') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $newEmail = isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '';
        $target = getUserById($targetId);
        $result = accountRecoveryAdminUpdateEmail($targetId, $expectedRole, $newEmail);
        if ($result['success']) {
            $success = 'Reseller email updated successfully';
            logHistory((int) $_SESSION['user_id'], 'admin_update_reseller_email', 'Changed email for: ' . ($target['username'] ?? ('ID ' . $targetId)) . ' to ' . $result['email']);
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'ban' || $action === 'unban') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $status = $action === 'ban' ? 'banned' : 'active';
        $target = getUserById($targetId);
        $result = setManagedAccountStatus($targetId, $expectedRole, $status);
        if ($result['success']) {
            $success = 'Reseller status updated successfully';
            logHistory((int) $_SESSION['user_id'], $action . '_reseller', ucfirst($action) . ' reseller: ' . ($target['username'] ?? ('ID ' . $targetId)));
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
                ? 'Reseller disabled to preserve transaction and key history'
                : 'Reseller deleted successfully';
            logHistory(
                (int) $_SESSION['user_id'],
                $preserved ? 'disable_reseller_preserve_history' : 'delete_reseller',
                ($preserved ? 'Disabled reseller to preserve history: ' : 'Deleted reseller: ') . ($target['username'] ?? ('ID ' . $targetId))
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

$resellerPage = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$resellerPageData = getManagedAccountsPage('reseller', $resellerSearch, $resellerPage, 50);
$resellers = $resellerPageData['rows'];
$resellerTotal = (int) $resellerPageData['total'];
$resellerPage = (int) $resellerPageData['page'];
$resellerPages = (int) $resellerPageData['pages'];
$resellersPageEnglish = getAppLang() === 'en';
$buildResellerPageUrl = static function (int $page) use ($resellerSearch): string {
    $params = ['page' => max(1, $page)];
    if ($resellerSearch !== '') $params['q'] = $resellerSearch;
    return 'resellers.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="<?php echo getAppLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-lang="admin.resellers.title">Manage Resellers - Admin Panel</title>
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
            <h3 class="text-xl md:text-2xl font-bold text-white"><i class="bi bi-person-badge mr-2"></i><span data-lang="admin.resellers.heading">Manage Resellers</span></h3>
            <button onclick="openAddModal()" class="bg-green-500 hover:opacity-90 text-white px-3 py-1.5 md:px-4 md:py-2 rounded-lg font-medium transition text-sm md:text-base w-full md:w-auto">
                <i class="bi bi-person-plus mr-1 md:mr-2"></i><span data-lang="admin.resellers.add_btn"><?php echo Lang::t('admin.resellers.add_btn'); ?></span>
            </button>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="glass border border-red-500/50 p-3 md:p-4 rounded-lg bg-red-900/20 text-red-300 text-sm md:text-base">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="glass border border-green-500/50 p-3 md:p-4 rounded-lg bg-green-900/20 text-green-300 text-sm md:text-base">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Reseller Search -->
        <div class="glass rounded-lg md:rounded-xl p-4 animate-fade-in">
            <form method="GET" action="resellers.php" class="flex flex-col sm:flex-row gap-2">
                <label for="resellerSearch" class="sr-only"><?php echo $resellersPageEnglish ? 'Search reseller accounts' : 'ค้นหาบัญชีตัวแทน'; ?></label>
                <div class="relative flex-1">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input id="resellerSearch" type="search" name="q" value="<?php echo htmlspecialchars($resellerSearch, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" maxlength="120" enterkeyhint="search" autocomplete="off" placeholder="<?php echo $resellersPageEnglish ? 'ID, username, or email' : 'ค้นหาจาก ID, ชื่อผู้ใช้ หรืออีเมล'; ?>" class="w-full bg-black/20 border border-white/10 rounded-lg py-2.5 pl-10 pr-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-accent">
                </div>
                <button type="submit" class="bg-accent hover:opacity-90 text-white px-5 py-2.5 rounded-lg font-medium transition text-sm"><i class="bi bi-search mr-1"></i><?php echo $resellersPageEnglish ? 'Search' : 'ค้นหา'; ?></button>
                <?php if ($resellerSearch !== ''): ?>
                    <a href="resellers.php" class="bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 px-4 py-2.5 rounded-lg font-medium transition text-sm text-center"><i class="bi bi-x-circle mr-1"></i><?php echo $resellersPageEnglish ? 'Clear' : 'ล้างการค้นหา'; ?></a>
                <?php endif; ?>
            </form>
            <div class="mt-2 text-xs text-gray-500">
                <?php echo $resellersPageEnglish ? 'Total matching accounts:' : 'บัญชีที่ตรงกับรายการ:'; ?>
                <strong class="text-gray-300"><?php echo $resellerTotal; ?></strong>
            </div>
        </div>

        <!-- Resellers Table -->
        <div class="glass rounded-lg md:rounded-xl overflow-hidden animate-fade-in">
            <div class="p-4 md:p-6">
                <div class="overflow-x-auto -mx-3 md:mx-0">
                    <table class="w-full min-w-[700px] md:min-w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.id"><?php echo Lang::t('admin.users.table.id'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.username"><?php echo Lang::t('admin.users.table.username'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.email"><?php echo Lang::t('admin.users.table.email'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.balance"><?php echo Lang::t('admin.users.table.balance'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.status"><?php echo Lang::t('admin.users.table.status'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="admin.users.table.joined"><?php echo Lang::t('admin.users.table.joined'); ?></th>
                                <th class="text-left py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm" data-lang="common.action"><?php echo Lang::t('common.action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resellers)): ?>
                                <tr>
                                     <td colspan="7" class="text-center py-6 md:py-8 text-gray-500 text-sm md:text-base"><?php echo $resellerSearch !== '' ? ($resellersPageEnglish ? 'No reseller account matches this search.' : 'ไม่พบบัญชีตัวแทนที่ตรงกับคำค้นหา') : Lang::t('admin.resellers.empty'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($resellers as $index => $reseller): ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5 transition" style="animation-delay: <?php echo $index * 0.05; ?>s;">
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-xs md:text-sm"><?php echo $reseller['id']; ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 font-medium text-xs md:text-sm truncate max-w-[100px]"><?php echo htmlspecialchars($reseller['username']); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm truncate max-w-[120px]"><?php echo htmlspecialchars($reseller['email']); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-green-400 text-xs md:text-sm"><?php echo formatCurrency($reseller['balance']); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <?php if ($reseller['status'] == 'active'): ?>
                                                <span class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400" data-lang="admin.users.status.active"><?php echo Lang::t('admin.users.status.active'); ?></span>
                                            <?php else: ?>
                                                <span class="px-1.5 py-0.5 md:px-2 md:py-1 rounded-full text-xs font-medium bg-red-500/20 text-red-400" data-lang="admin.users.status.banned"><?php echo Lang::t('admin.users.status.banned'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-2 md:py-3 md:px-4 text-gray-400 text-xs md:text-sm"><?php echo date('M d, Y', strtotime($reseller['created_at'])); ?></td>
                                        <td class="py-2 px-2 md:py-3 md:px-4">
                                            <div class="flex items-center space-x-1 md:space-x-2">
                                                <?php if ($reseller['status'] == 'active'): ?>
                                                    <button onclick="banReseller(<?php echo (int)$reseller['id']; ?>, <?php echo htmlJsArg((string)$reseller['username']); ?>)" class="p-1.5 md:p-2 rounded-lg hover:bg-red-500/20 text-red-400 transition" data-lang-title="admin.users.ban_title" title="<?php echo Lang::t('admin.users.ban_title'); ?>">
                                                        <i class="bi bi-ban text-xs md:text-sm"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="unbanReseller(<?php echo (int)$reseller['id']; ?>, <?php echo htmlJsArg((string)$reseller['username']); ?>)" class="p-1.5 md:p-2 rounded-lg hover:bg-green-500/20 text-green-400 transition" data-lang-title="admin.users.unban_title" title="<?php echo Lang::t('admin.users.unban_title'); ?>">
                                                        <i class="bi bi-check-circle text-xs md:text-sm"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button onclick="updateBalanceModal(<?php echo (int)$reseller['id']; ?>, <?php echo htmlJsArg((string)$reseller['username']); ?>)" class="p-1.5 md:p-2 rounded-lg hover:bg-yellow-500/20 text-yellow-400 transition" data-lang-title="admin.users.modal.balance_title" title="<?php echo Lang::t('admin.users.modal.balance_title'); ?>">
                                                    <i class="bi bi-wallet2 text-xs md:text-sm"></i>
                                                </button>
                                                <button onclick="editResellerEmail(<?php echo (int)$reseller['id']; ?>, <?php echo htmlJsArg((string)$reseller['username']); ?>, <?php echo htmlJsArg((string)$reseller['email']); ?>)" class="p-1.5 md:p-2 rounded-lg hover:bg-blue-500/20 text-blue-400 transition" title="เปลี่ยน Gmail">
                                                    <i class="bi bi-envelope-at text-xs md:text-sm"></i>
                                                </button>
                                                <button onclick="deleteReseller(<?php echo (int)$reseller['id']; ?>, <?php echo htmlJsArg((string)$reseller['username']); ?>)" class="p-1.5 md:p-2 rounded-lg hover:bg-red-500/20 text-red-400 transition" data-lang-title="admin.users.delete_title" title="<?php echo Lang::t('admin.users.delete_title'); ?>">
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
                <?php if ($resellerPages > 1): ?>
                    <nav class="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm" aria-label="<?php echo $resellersPageEnglish ? 'Reseller account pages' : 'หน้ารายการบัญชีตัวแทน'; ?>">
                        <div class="text-gray-500"><?php echo $resellersPageEnglish ? 'Total' : 'ทั้งหมด'; ?> <strong class="text-gray-300"><?php echo $resellerTotal; ?></strong> <?php echo $resellersPageEnglish ? 'account(s)' : 'บัญชี'; ?></div>
                        <div class="flex items-center gap-2">
                            <?php if ($resellerPage > 1): ?><a href="<?php echo htmlspecialchars($buildResellerPageUrl($resellerPage - 1), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-gray-300 hover:bg-white/10"><?php echo $resellersPageEnglish ? 'Previous' : 'ก่อนหน้า'; ?></a><?php endif; ?>
                            <span class="rounded-lg bg-accent/15 px-3 py-2 text-indigo-200"><?php echo $resellerPage; ?> / <?php echo $resellerPages; ?></span>
                            <?php if ($resellerPage < $resellerPages): ?><a href="<?php echo htmlspecialchars($buildResellerPageUrl($resellerPage + 1), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-gray-300 hover:bg-white/10"><?php echo $resellersPageEnglish ? 'Next' : 'ถัดไป'; ?></a><?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Reseller Modal -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="addModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">
            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i class="bi bi-person-plus text-green-400 mr-2"></i><span data-lang="admin.resellers.modal.add_title"><?php echo Lang::t('admin.resellers.modal.add_title'); ?></span></h5>
                <button type="button" aria-label="Close" onclick="closeModal('addModal')" class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.table.username"><?php echo Lang::t('admin.users.table.username'); ?></label>
                    <input type="text" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="username" required>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.table.email"><?php echo Lang::t('admin.users.table.email'); ?></label>
                    <input type="email" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="email" required pattern="^[^@\s]+@gmail\.com$" placeholder="example@gmail.com">
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="common.password"><?php echo Lang::t('common.password'); ?></label>
                    <input type="password" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="password" required>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.users.table.balance"><?php echo Lang::t('admin.users.table.balance'); ?></label>
                    <input type="number" step="0.01" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="balance" value="0" required>
                </div>
                <div class="flex flex-col md:flex-row gap-2 md:gap-3">
                    <button type="submit" class="bg-green-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base" data-lang="admin.resellers.add_btn"><?php echo Lang::t('admin.resellers.add_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Balance Modal -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden items-center justify-center z-50" id="balanceModal" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
        <div class="glass rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-3 md:mx-4">
            <div class="flex justify-between items-center mb-3 md:mb-4">
                <h5 class="text-base md:text-lg font-bold text-white"><i class="bi bi-wallet2 text-yellow-400 mr-2"></i><span data-lang="admin.resellers.modal.update_balance"><?php echo Lang::t('admin.resellers.modal.update_balance'); ?></span></h5>
                <button type="button" aria-label="Close" onclick="closeModal('balanceModal')" class="text-gray-400 hover:text-white text-lg md:text-xl">✕</button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_balance">
                <input type="hidden" name="user_id" id="balance_user_id">
                <div class="mb-3 md:mb-4">
                    <p class="text-gray-400 text-sm md:text-base"><span data-lang="admin.resellers.modal.reseller_label"><?php echo Lang::t('admin.resellers.modal.reseller_label'); ?></span> <strong class="text-white" id="balance_username"></strong></p>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="admin.resellers.modal.operation"><?php echo Lang::t('admin.resellers.modal.operation'); ?></label>
                    <select class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="operation" required>
                        <option value="add" class="bg-panel" data-lang="admin.resellers.modal.add_balance"><?php echo Lang::t('admin.resellers.modal.add_balance'); ?></option>
                        <option value="deduct" class="bg-panel" data-lang="admin.resellers.modal.deduct_balance"><?php echo Lang::t('admin.resellers.modal.deduct_balance'); ?></option>
                    </select>
                </div>
                <div class="mb-3 md:mb-4">
                    <label class="text-gray-400 text-xs md:text-sm mb-1 md:mb-2 block" data-lang="common.amount"><?php echo Lang::t('common.amount'); ?></label>
                    <input type="number" step="0.01" class="glass border border-white/10 rounded-lg p-2 w-full bg-transparent text-white text-sm md:text-base" name="amount" required>
                </div>
                <div class="flex flex-col md:flex-row gap-2 md:gap-3">
                    <button type="submit" class="bg-green-500 hover:opacity-90 text-white py-2 rounded-lg font-medium transition text-sm md:text-base" data-lang="common.update"><?php echo Lang::t('common.update'); ?></button>
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
        
        function banReseller(id, username) {
            if (confirm(Lang.t('admin.resellers.confirm_ban').replace('{username}', username))) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="ban"><input type="hidden" name="user_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function unbanReseller(id, username) {
            if (confirm(Lang.t('admin.resellers.confirm_unban').replace('{username}', username))) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="unban"><input type="hidden" name="user_id" value="' + id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function updateBalanceModal(id, username) {
            document.getElementById('balance_user_id').value = id;
            document.getElementById('balance_username').textContent = username;
            showManagedModal('balanceModal');
        }
        
        function editResellerEmail(id, username, currentEmail) {
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

        function deleteReseller(id, username) {
            if (confirm(Lang.t('admin.resellers.confirm_delete').replace('{username}', username))) {
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