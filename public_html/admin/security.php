<?php
require_once '../includes/auth.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

global $conn;
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';
$schemaReady = accountVerificationRuntimeSchemaReady(true);

function securityPostString(string $key): string
{
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : '';
}

function securityStoreAccountSettings(bool $emailRequired): array
{
    global $conn;

    if ($emailRequired) {
        $mailReady = function_exists('accountRecoveryActivationReadiness') ? accountRecoveryActivationReadiness() : ['ready' => false];
        if (empty($mailReady['ready'])) {
            return ['success' => false, 'message' => 'ยังบังคับยืนยันอีเมลไม่ได้ กรุณาตั้งค่าและทดสอบ Gmail SMTP ให้สำเร็จก่อน'];
        }
    }

    $conn->begin_transaction();
    try {
        if (!upsertSetting('account_email_otp_required', $emailRequired ? '1' : '0')) {
            throw new RuntimeException('save failed');
        }
        if (!accountVerificationClearRetiredProviderSettings()) {
            throw new RuntimeException('retired provider cleanup failed');
        }
        $conn->commit();
        return ['success' => true, 'message' => 'บันทึกการตั้งค่า Email OTP แล้ว และปิด LINE/SMS verification เรียบร้อย'];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Security settings save failed');
        return ['success' => false, 'message' => 'บันทึกการตั้งค่าไม่สำเร็จ'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken('security.php');
    $action = securityPostString('action');
    $result = ['success' => false, 'message' => 'คำขอไม่ถูกต้อง'];

    if ($action === 'save_settings') {
        $result = securityStoreAccountSettings(securityPostString('email_required') === '1');
    } elseif ($action === 'block_email') {
        $result = accountVerificationBlockEmail(securityPostString('email'), securityPostString('reason'), $adminId);
    } elseif ($action === 'block_ip') {
        $result = accountVerificationBlockIp(securityPostString('ip'), securityPostString('reason'), $adminId);
    } elseif ($action === 'block_device') {
        $result = accountVerificationBlockDevice(securityPostString('device_hash'), securityPostString('reason'), $adminId);
    } elseif ($action === 'block_account_all' || $action === 'unblock_account_all') {
        $targetId = isset($_POST['user_id']) && is_scalar($_POST['user_id']) ? max(0, (int) $_POST['user_id']) : 0;
        $target = $targetId > 0 ? getUserById($targetId) : null;
        $targetRole = is_array($target) ? strtolower((string) ($target['role'] ?? '')) : '';
        if (!$target || !in_array($targetRole, ['user', 'reseller'], true)) {
            $result = ['success' => false, 'message' => 'ไม่พบบัญชี User/Reseller ที่ต้องการ'];
        } elseif ($action === 'block_account_all') {
            $statusResult = ((string) ($target['status'] ?? '') === 'banned')
                ? ['success' => true]
                : setManagedAccountStatus($targetId, $targetRole, 'banned');
            $signalsResult = accountVerificationBlockKnownUserSignals(
                $targetId,
                'บล็อคทั้งหมดจากหน้า Security โดยผู้ดูแลระบบ',
                $adminId
            );
            if (function_exists('revokeRememberTokensForUser')) revokeRememberTokensForUser($targetId);
            $ok = !empty($statusResult['success']) && !empty($signalsResult['success']);
            $counts = is_array($signalsResult['counts'] ?? null) ? $signalsResult['counts'] : [];
            $result = [
                'success' => $ok,
                'message' => $ok
                    ? 'บล็อคบัญชี ' . (string) ($target['username'] ?? '') . ' พร้อม Email / Device / IP ล่าสุดที่รู้จักแล้ว'
                    : 'บัญชีถูกระงับบางส่วน แต่ Shared Security ยังทำงานไม่ครบ: ' . (string) ($signalsResult['message'] ?? $statusResult['message'] ?? 'unknown'),
            ];
            logHistory($adminId, 'security_block_account_all', 'Blocked account and known security signals: ' . (string) ($target['username'] ?? ('ID ' . $targetId)) . '; counts=' . json_encode($counts));
        } else {
            $signalsResult = accountVerificationUnblockKnownUserSignals($targetId);
            if (!empty($signalsResult['success'])) {
                $statusResult = ((string) ($target['status'] ?? '') === 'active')
                    ? ['success' => true]
                    : setManagedAccountStatus($targetId, $targetRole, 'active');
            } else {
                $statusResult = ['success' => false, 'message' => 'Shared Security ยังปลดไม่ครบ จึงยังไม่เปิดบัญชี'];
            }
            $ok = !empty($signalsResult['success']) && !empty($statusResult['success']);
            $counts = is_array($signalsResult['counts'] ?? null) ? $signalsResult['counts'] : [];
            $result = [
                'success' => $ok,
                'message' => $ok
                    ? 'ปลดบล็อคบัญชี ' . (string) ($target['username'] ?? '') . ' พร้อม Email / Device / IP ที่รู้จักแล้ว'
                    : (string) ($signalsResult['message'] ?? $statusResult['message'] ?? 'ปลดบล็อคไม่สำเร็จ'),
            ];
            logHistory($adminId, 'security_unblock_account_all', 'Unblocked account and known security signals: ' . (string) ($target['username'] ?? ('ID ' . $targetId)) . '; counts=' . json_encode($counts));
        }
    } elseif ($action === 'unblock_email') {
        $ok = accountVerificationUnblockEmail(securityPostString('email'));
        $result = ['success' => $ok, 'message' => $ok ? 'ยกเลิกบล็อคอีเมลทั้งสองเว็บไซต์แล้ว' : 'ยกเลิกบล็อคอีเมลไม่สำเร็จ'];
    } elseif ($action === 'unblock_ip') {
        $ok = accountVerificationUnblockIp(securityPostString('ip'));
        $result = ['success' => $ok, 'message' => $ok ? 'ยกเลิกบล็อค IP ทั้งสองเว็บไซต์แล้ว' : 'ยกเลิกบล็อค IP ไม่สำเร็จ'];
    } elseif ($action === 'unblock_device') {
        $ok = accountVerificationUnblockDevice(securityPostString('device_hash'));
        $result = ['success' => $ok, 'message' => $ok ? 'ยกเลิกบล็อคอุปกรณ์ทั้งสองเว็บไซต์แล้ว' : 'ยกเลิกบล็อคอุปกรณ์ไม่สำเร็จ'];
    }

    if (!empty($result['success'])) $success = (string) ($result['message'] ?? 'สำเร็จ');
    else $error = (string) ($result['message'] ?? 'ดำเนินการไม่สำเร็จ');
    $schemaReady = accountVerificationRuntimeSchemaReady(true);
}

$emailRequired = accountVerificationEmailRequired();
$mailReadiness = function_exists('accountRecoveryActivationReadiness') ? accountRecoveryActivationReadiness() : ['ready' => false];

$accountSearch = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $accountSearch = securityPostString('account_q');
if ($accountSearch === '' && isset($_GET['account_q']) && is_scalar($_GET['account_q'])) $accountSearch = trim((string) $_GET['account_q']);
if (function_exists('mb_substr')) $accountSearch = mb_substr($accountSearch, 0, 120, 'UTF-8');
else $accountSearch = substr($accountSearch, 0, 120);
$accountResults = $schemaReady && $accountSearch !== '' ? accountVerificationAdminSearchAccounts($accountSearch, 20) : [];

$currentSiteId = sharedSecurityCurrentSiteId();
$securitySearch = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
if (function_exists('mb_substr')) $securitySearch = mb_substr($securitySearch, 0, 120, 'UTF-8');
else $securitySearch = substr($securitySearch, 0, 120);
$devicePage = isset($_GET['dp']) && is_scalar($_GET['dp']) ? max(1, (int) $_GET['dp']) : 1;
$blockPage = isset($_GET['bp']) && is_scalar($_GET['bp']) ? max(1, (int) $_GET['bp']) : 1;
$perPage = 50;
$shared = $schemaReady ? sharedSecurityList([
    'q' => $securitySearch,
    'device_page' => $devicePage,
    'block_page' => $blockPage,
    'per_page' => $perPage,
]) : ['success' => false, 'code' => 'schema_not_ready'];
$sharedReady = !empty($shared['success']);
$blocks = $sharedReady && is_array($shared['blocks'] ?? null) ? $shared['blocks'] : [];
$devices = $sharedReady && is_array($shared['devices'] ?? null) ? $shared['devices'] : [];
$devicePagination = $sharedReady && is_array($shared['device_pagination'] ?? null)
    ? $shared['device_pagination']
    : ['page' => $devicePage, 'pages' => 1, 'total' => count($devices), 'per_page' => $perPage];
$blockPagination = $sharedReady && is_array($shared['block_pagination'] ?? null)
    ? $shared['block_pagination']
    : ['page' => $blockPage, 'pages' => 1, 'total' => count($blocks), 'per_page' => $perPage];

if (!$sharedReady && $schemaReady) {
    // Local fallback is display-only. It is still paginated so a large device
    // registry cannot turn this admin page into an accidental database stress test.
    $where = "u.role <> 'admin'";
    $like = '';
    if ($securitySearch !== '') {
        $escaped = strtr($securitySearch, ['=' => '==', '%' => '=%', '_' => '=_']);
        $like = '%' . $escaped . '%';
        $where .= " AND (u.username LIKE ? ESCAPE '=' OR d.device_label LIKE ? ESCAPE '=' OR d.browser_label LIKE ? ESCAPE '=' OR d.first_ip LIKE ? ESCAPE '=' OR d.last_ip LIKE ? ESCAPE '=')";
    }
    $deviceTotal = 0;
    $countSql = "SELECT COUNT(*) AS total FROM auth_devices d INNER JOIN users u ON u.id=d.user_id WHERE {$where}";
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if ($securitySearch !== '') $countStmt->bind_param('sssss', $like, $like, $like, $like, $like);
        if ($countStmt->execute()) { $r = $countStmt->get_result(); $deviceTotal = (int) (($r ? $r->fetch_assoc() : null)['total'] ?? 0); }
        $countStmt->close();
    }
    $devicePages = max(1, (int) ceil($deviceTotal / $perPage));
    $devicePage = min($devicePage, $devicePages);
    $offset = ($devicePage - 1) * $perPage;
    $sql = "SELECT d.user_id,d.device_hash,d.device_label,d.browser_label,d.first_ip,d.last_ip,d.first_seen_at,d.last_seen_at,u.username,u.role
            FROM auth_devices d INNER JOIN users u ON u.id=d.user_id WHERE {$where}
            ORDER BY d.last_seen_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($securitySearch !== '') $stmt->bind_param('sssssii', $like, $like, $like, $like, $like, $perPage, $offset);
        else $stmt->bind_param('ii', $perPage, $offset);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $row['site_id'] = $currentSiteId;
                $devices[] = $row;
            }
        }
        $stmt->close();
    }
    $devicePagination = ['page' => $devicePage, 'pages' => $devicePages, 'total' => $deviceTotal, 'per_page' => $perPage];
}

$detailSite = isset($_GET['detail_site']) && is_scalar($_GET['detail_site']) ? sharedSecurityNormalizeSiteId($_GET['detail_site']) : '';
$detailUserId = isset($_GET['detail_user']) && is_scalar($_GET['detail_user']) ? max(0, (int) $_GET['detail_user']) : 0;
$detailDeviceHash = isset($_GET['detail_device']) && is_scalar($_GET['detail_device']) ? sharedSecurityNormalizeHash($_GET['detail_device']) : '';
$deviceDetail = null;
$detailLocalAccount = null;
$detailRememberedDevices = [];
if ($sharedReady && $detailSite !== '' && $detailUserId > 0 && $detailDeviceHash !== '') {
    $detailResult = sharedSecurityDeviceDetail($detailSite, $detailUserId, $detailDeviceHash);
    if (!empty($detailResult['success'])) {
        $deviceDetail = $detailResult;
        if ($detailSite === $currentSiteId) {
            $stmt = $conn->prepare('SELECT id,username,email,role,status,email_verified_at,created_at FROM users WHERE id=? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $detailUserId);
                if ($stmt->execute()) { $r = $stmt->get_result(); $detailLocalAccount = $r ? $r->fetch_assoc() : null; }
                $stmt->close();
            }
            if (is_array($detailLocalAccount)) {
                $detailRememberedDevices = accountVerificationGetRememberedDeviceSummary($detailUserId, 10);
            }
        }
    }
}

$buildSecurityUrl = static function (int $dp, int $bp, array $extra = []) use ($securitySearch): string {
    $params = ['dp' => max(1, $dp), 'bp' => max(1, $bp)];
    if ($securitySearch !== '') $params['q'] = $securitySearch;
    foreach ($extra as $key => $value) if ($value !== '' && $value !== null) $params[$key] = $value;
    return 'security.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root{--sakazuki-accent:#6366f1;--sakazuki-accent2:#8b5cf6}
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        input,button{font-size:16px!important}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .security-modal{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
        .security-modal-card{width:min(100%,1080px);max-height:92vh;overflow:auto}
    </style>
</head>
<body class="bg-darkbg text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="p-4 md:p-6 max-w-7xl mx-auto space-y-6">
    <header>
        <h1 class="text-2xl font-bold flex items-center gap-2"><i class="bi bi-shield-lock text-indigo-300"></i>ความปลอดภัยบัญชี</h1>
        <p class="text-sm text-gray-400 mt-1">Email OTP 6 หลัก + รายการบล็อค Email / IP / อุปกรณ์ ที่ใช้ร่วมกันระหว่าง SAK010 และ ONL005</p>
    </header>

    <?php if ($error !== ''): ?><div class="glass rounded-xl border-red-500/30 px-4 py-3 text-red-200"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="glass rounded-xl border-green-500/30 px-4 py-3 text-green-200"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (!$schemaReady): ?><div class="glass rounded-xl border-yellow-500/30 px-4 py-3 text-yellow-100">Security schema ยังไม่พร้อม ต้องรัน maintenance/cron schema migration ก่อน</div><?php endif; ?>
    <?php if ($schemaReady && !$sharedReady): ?><div class="glass rounded-xl border-yellow-500/30 px-4 py-3 text-yellow-100">เชื่อม Shared Security Hub ไม่สำเร็จ ขณะนี้แสดงข้อมูลอุปกรณ์เฉพาะเว็บนี้ การบล็อคข้ามเว็บจะรายงานข้อผิดพลาดแทนการแกล้งทำว่าสำเร็จ</div><?php endif; ?>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <div class="glass rounded-2xl p-5 space-y-5">
            <div>
                <h2 class="font-semibold text-lg">การยืนยันบัญชี</h2>
                <p class="text-sm text-gray-400 mt-1">ลูกค้าใช้ Email OTP 6 หลักเท่านั้น ไม่มี LINE, Google, SMS หรือ KYC</p>
            </div>
            <form method="POST" class="space-y-4">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="save_settings">
                <label class="flex items-center gap-3"><input type="checkbox" name="email_required" value="1" <?php echo $emailRequired ? 'checked' : ''; ?>><span>บังคับ Email OTP 6 หลักก่อนใช้งานบัญชี</span></label>
                <div class="text-xs <?php echo !empty($mailReadiness['ready']) ? 'text-green-300' : 'text-yellow-300'; ?>">Gmail SMTP: <?php echo !empty($mailReadiness['ready']) ? 'พร้อมใช้งาน' : 'ยังไม่ผ่านการตั้งค่า/ทดสอบ'; ?></div>
                <div class="rounded-lg bg-white/5 px-3 py-3 text-xs text-gray-300 space-y-1">
                    <div>Site ID: <span class="mono"><?php echo htmlspecialchars($currentSiteId !== '' ? $currentSiteId : 'ไม่พบ', ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <p class="text-gray-500">การกดบล็อคบัญชีจากหน้า Users/Resellers จะพยายามบล็อค Email + Device ID + IP ที่เคยพบของบัญชีนั้นไว้ใน Shared Security ด้วย</p>
                </div>
                <button class="rounded-lg bg-indigo-500 hover:bg-indigo-600 px-4 py-2.5 font-medium">บันทึกการตั้งค่า</button>
            </form>
        </div>

        <div class="glass rounded-2xl p-5 space-y-4">
            <div>
                <h2 class="font-semibold text-lg">บล็อคด้วยมือ</h2>
                <p class="text-sm text-gray-400 mt-1">คำสั่งที่สำเร็จจะใช้ร่วมกันทั้ง SAK010 และ ONL005 ไม่มี risk score อัตโนมัติ</p>
            </div>
            <form method="POST" class="space-y-2">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="block_email">
                <input name="email" type="email" maxlength="190" class="glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="อีเมล เช่น example@gmail.com">
                <input name="reason" maxlength="255" class="glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="เหตุผล">
                <button class="rounded-lg bg-red-500/80 hover:bg-red-500 px-4 py-2.5">บล็อค Email</button>
            </form>
            <form method="POST" class="space-y-2 border-t border-white/10 pt-4">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="block_ip">
                <input name="ip" maxlength="45" class="glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="IP เช่น 203.0.113.10">
                <input name="reason" maxlength="255" class="glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="เหตุผล">
                <button class="rounded-lg bg-red-500/80 hover:bg-red-500 px-4 py-2.5">บล็อค IP</button>
            </form>
            <form method="POST" class="space-y-2 border-t border-white/10 pt-4">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="block_device">
                <input name="device_hash" maxlength="64" class="mono glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="Device hash 64 ตัว">
                <input name="reason" maxlength="255" class="glass rounded-lg px-3 py-2.5 w-full bg-transparent" placeholder="เหตุผล">
                <button class="rounded-lg bg-red-500/80 hover:bg-red-500 px-4 py-2.5">บล็อคอุปกรณ์</button>
            </form>
        </div>
    </section>

    <section class="glass rounded-2xl p-5 space-y-4">
        <div>
            <h2 class="font-semibold text-lg"><i class="bi bi-person-lock text-red-300 mr-2"></i>ค้นหาบัญชีแล้วบล็อค / ปลดบล็อคทั้งหมด</h2>
            <p class="text-sm text-gray-400 mt-1">ค้นหาจากชื่อผู้ใช้, อีเมล หรือ ID ในฐานข้อมูลของเว็บนี้ จากนั้นสั่งระงับบัญชีพร้อม Email + Device ID + IP ล่าสุดที่ระบบรู้จักได้ในครั้งเดียว รายการ Shared Security ที่สร้างขึ้นจะมีผลกับทั้ง SAK010 และ ONL005</p>
        </div>
        <form method="GET" class="flex flex-col sm:flex-row gap-2">
            <input type="search" name="account_q" value="<?php echo htmlspecialchars($accountSearch, ENT_QUOTES, 'UTF-8'); ?>" maxlength="120" autocomplete="off" enterkeyhint="search" class="glass rounded-lg px-3 py-2.5 bg-transparent flex-1 min-w-0" placeholder="ชื่อผู้ใช้ / อีเมล / ID">
            <?php if ($securitySearch !== ''): ?><input type="hidden" name="q" value="<?php echo htmlspecialchars($securitySearch, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <button class="rounded-lg bg-indigo-500 hover:bg-indigo-600 px-4 py-2.5 font-medium"><i class="bi bi-search mr-1"></i>ค้นหาบัญชี</button>
            <?php if ($accountSearch !== ''): ?><a href="<?php echo htmlspecialchars($buildSecurityUrl((int) ($devicePagination['page'] ?? 1), (int) ($blockPagination['page'] ?? 1)), ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg bg-white/10 hover:bg-white/15 px-4 py-2.5 text-center">ล้าง</a><?php endif; ?>
        </form>
        <?php if ($accountSearch !== ''): ?>
        <div class="space-y-2">
            <?php foreach ($accountResults as $accountRow): ?>
            <div class="rounded-xl border border-white/10 bg-white/5 p-3 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <div class="min-w-0">
                    <div class="font-medium break-all"><?php echo htmlspecialchars((string) ($accountRow['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <span class="text-xs text-gray-500">#<?php echo (int) ($accountRow['id'] ?? 0); ?> · <?php echo htmlspecialchars((string) ($accountRow['role'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="text-xs text-gray-400 break-all"><?php echo htmlspecialchars((string) ($accountRow['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-xs mt-1 text-gray-500">สถานะ: <?php echo htmlspecialchars((string) ($accountRow['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · Device <?php echo (int) ($accountRow['known_devices'] ?? 0); ?> · IP ล่าสุดที่ไม่ซ้ำ <?php echo (int) ($accountRow['known_ips'] ?? 0); ?><?php if (!empty($accountRow['last_seen_at'])): ?> · พบล่าสุด <?php echo htmlspecialchars((string) $accountRow['last_seen_at'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <?php if (($accountRow['status'] ?? '') !== 'banned'): ?>
                    <form method="POST" onsubmit="return confirm('บล็อคบัญชีนี้พร้อม Email, Device ID และ IP ล่าสุดที่รู้จัก? IP อาจถูกแชร์โดยหลายคน ควรตรวจรายละเอียดก่อนยืนยัน');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="block_account_all">
                        <input type="hidden" name="user_id" value="<?php echo (int) ($accountRow['id'] ?? 0); ?>">
                        <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountSearch, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="rounded-lg bg-red-500/80 hover:bg-red-500 px-3 py-2 text-sm"><i class="bi bi-slash-circle mr-1"></i>บล็อคทั้งหมด</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" onsubmit="return confirm('ปลดสถานะบัญชีและลบ Email / Device / IP ที่ระบบรู้จักของบัญชีนี้ออกจาก Shared Security?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="unblock_account_all">
                        <input type="hidden" name="user_id" value="<?php echo (int) ($accountRow['id'] ?? 0); ?>">
                        <input type="hidden" name="account_q" value="<?php echo htmlspecialchars($accountSearch, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="rounded-lg bg-green-500/80 hover:bg-green-500 px-3 py-2 text-sm"><i class="bi bi-unlock mr-1"></i>ปลดบล็อคทั้งหมด</button>
                    </form>
                    <?php endif; ?>
                    <?php $accountPage = (($accountRow['role'] ?? '') === 'reseller') ? 'resellers.php' : 'users.php'; ?>
                    <a href="<?php echo $accountPage; ?>?security_id=<?php echo (int) ($accountRow['id'] ?? 0); ?>" class="rounded-lg bg-white/10 hover:bg-white/15 px-3 py-2 text-sm"><i class="bi bi-shield-check mr-1"></i>รายละเอียด</a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($accountResults === []): ?><div class="rounded-xl bg-white/5 p-4 text-sm text-gray-500 text-center">ไม่พบบัญชี User/Reseller ที่ตรงกับคำค้นหา</div><?php endif; ?>
        </div>
        <div class="rounded-xl border border-yellow-500/20 bg-yellow-500/5 p-3 text-xs text-yellow-200">คำสั่ง “บล็อคทั้งหมด” รวม IP ล่าสุดที่บันทึกจากอุปกรณ์และ Remember Device ด้วย จึงอาจกระทบผู้ใช้คนอื่นที่แชร์ IP เดียวกันผ่านมือถือ/CGNAT/Wi‑Fi ควรใช้ Device + Email เป็นหลักและตรวจ IP ก่อนกดยืนยัน</div>
        <?php endif; ?>
    </section>

    <section class="glass rounded-2xl p-5 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <h2 class="font-semibold text-lg">ค้นหาข้อมูลความปลอดภัย</h2>
                <p class="text-sm text-gray-400 mt-1">รายการอุปกรณ์เป็น 1 แถวต่อบัญชี + Device ID และอัปเดตเวลาเดิม ไม่ได้เพิ่ม log ใหม่ทุก request จึงโตช้ากว่า access log มาก หน้าแสดงผลแบ่งครั้งละ <?php echo (int) $perPage; ?> รายการ</p>
            </div>
            <form method="GET" class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
                <input name="q" value="<?php echo htmlspecialchars($securitySearch, ENT_QUOTES, 'UTF-8'); ?>" maxlength="120" class="glass rounded-lg px-3 py-2.5 bg-transparent min-w-0 w-full md:w-auto" placeholder="ค้นหาเว็บ / บัญชี / รุ่น / เบราว์เซอร์ / IP / hash / เหตุผล">
                <button class="rounded-lg bg-indigo-500 hover:bg-indigo-600 px-4 py-2.5 font-medium">ค้นหา</button>
                <?php if ($securitySearch !== ''): ?><a href="security.php" class="rounded-lg bg-white/10 hover:bg-white/15 px-4 py-2.5 text-center">ล้าง</a><?php endif; ?>
            </form>
        </div>
        <div class="rounded-xl border border-white/10 bg-white/5 p-3 text-xs text-gray-400">
            ไม่ลบข้อมูลเก่าอัตโนมัติในเวอร์ชันนี้ เพื่อไม่ให้หลักฐานหายโดยไม่ตั้งใจ ถ้าภายหลังจำนวนอุปกรณ์โตมากจริง ค่อยเพิ่ม retention/archiving ตามช่วงเวลาที่คุณกำหนดเองจะปลอดภัยกว่า
        </div>
    </section>

    <?php if (is_array($deviceDetail) && is_array($deviceDetail['device'] ?? null)):
        $detailRow = $deviceDetail['device'];
        $sameDeviceRows = is_array($deviceDetail['same_device_accounts'] ?? null) ? $deviceDetail['same_device_accounts'] : [];
        $sameIpRows = is_array($deviceDetail['same_ip_accounts'] ?? null) ? $deviceDetail['same_ip_accounts'] : [];
    ?>
    <div class="security-modal" id="deviceDetailModal" role="dialog" aria-modal="true" aria-labelledby="deviceDetailTitle">
        <section class="glass rounded-2xl p-5 space-y-5 security-modal-card">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="deviceDetailTitle" class="font-semibold text-lg"><i class="bi bi-phone text-indigo-300 mr-2"></i>รายละเอียดอุปกรณ์</h2>
                <p class="text-sm text-gray-400 mt-1">ข้อมูลนี้ใช้ประกอบการตรวจสอบเท่านั้น ระบบไม่ทำคะแนนความเสี่ยงและไม่บล็อคบัญชีจากการจับคู่เหล่านี้อัตโนมัติ</p>
            </div>
            <button type="button" onclick="closeDeviceDetailModal()" class="rounded-lg bg-white/10 hover:bg-white/15 px-3 py-2 text-sm">ปิดรายละเอียด</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
            <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">เว็บ / บัญชี</div><div class="mt-1"><?php echo htmlspecialchars((string) ($detailRow['site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($detailRow['username'] ?? ('#' . ($detailRow['user_id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($detailRow['role'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></div>
            <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">อุปกรณ์ / เบราว์เซอร์</div><div class="mt-1"><?php echo htmlspecialchars((string) ($detailRow['device_label'] ?? 'ไม่ทราบรุ่น'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-400"><?php echo htmlspecialchars((string) ($detailRow['browser_label'] ?? 'ไม่ทราบเบราว์เซอร์'), ENT_QUOTES, 'UTF-8'); ?></div></div>
            <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">IP แรก / ล่าสุด</div><div class="mono mt-1 break-all"><?php echo htmlspecialchars((string) ($detailRow['first_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="mono text-xs text-gray-400 break-all"><?php echo htmlspecialchars((string) ($detailRow['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></div>
            <div class="rounded-xl bg-white/5 p-3"><div class="text-xs text-gray-500">เห็นครั้งแรก / ล่าสุด</div><div class="mt-1"><?php echo htmlspecialchars((string) ($detailRow['first_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-gray-400"><?php echo htmlspecialchars((string) ($detailRow['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></div>
        </div>
        <div class="rounded-xl bg-black/20 p-3">
            <div class="text-xs text-gray-500 mb-1">Device hash เต็ม</div>
            <div class="mono text-xs break-all select-all"><?php echo htmlspecialchars((string) ($detailRow['device_hash'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php if (is_array($detailLocalAccount)): ?>
        <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm">
            <div class="font-medium mb-2">ข้อมูลบัญชีจากฐานข้อมูลเว็บนี้</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-gray-300">
                <div>อีเมล: <span class="select-all"><?php echo htmlspecialchars((string) ($detailLocalAccount['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div>Email OTP: <?php echo !empty($detailLocalAccount['email_verified_at']) ? 'ยืนยันแล้ว · ' . htmlspecialchars((string) $detailLocalAccount['email_verified_at'], ENT_QUOTES, 'UTF-8') : 'ยังไม่ยืนยัน'; ?></div>
                <div>สถานะ: <?php echo htmlspecialchars((string) ($detailLocalAccount['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php $detailAccountPage = (($detailLocalAccount['role'] ?? '') === 'reseller') ? 'resellers.php' : 'users.php'; ?>
            <a class="inline-block mt-3 text-indigo-300 hover:text-white" href="<?php echo $detailAccountPage; ?>?security_id=<?php echo (int) ($detailLocalAccount['id'] ?? 0); ?>">เปิดรายละเอียดบัญชี →</a>
            <div class="mt-4 rounded-lg border border-white/10 overflow-hidden">
                <div class="px-3 py-2 bg-white/5"><div class="font-medium">Remember Device ที่ยังใช้งานอยู่</div><div class="text-xs text-gray-500">ระบบอัปเดต IP ตอน auto-login และ session ที่ใช้งานอยู่ โดยไม่แสดง token จริง</div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-gray-500"><tr><th class="text-left p-2">IP ล่าสุด</th><th class="text-left p-2">ใช้ล่าสุด</th><th class="text-left p-2">หมดอายุ</th><th class="text-left p-2">คงเหลือ</th></tr></thead><tbody>
                <?php foreach ($detailRememberedDevices as $remembered): ?><tr class="border-t border-white/5"><td class="p-2 mono"><?php echo htmlspecialchars((string) ($remembered['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($remembered['last_used_at'] ?? $remembered['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($remembered['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo isset($remembered['remaining_days']) ? (int) $remembered['remaining_days'] . ' วัน' : '-'; ?></td></tr><?php endforeach; ?>
                <?php if ($detailRememberedDevices === []): ?><tr><td colspan="4" class="p-3 text-gray-500">ไม่มี Remember Device ที่ยังไม่หมดอายุ</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </div>
        <?php else: ?>
        <div class="rounded-xl border border-white/10 bg-white/5 p-3 text-xs text-gray-400">อีเมลไม่ได้ถูกคัดลอกมาเก็บที่ Security Hub เพื่อไม่เพิ่ม PII ซ้ำข้ามฐานข้อมูล ถ้าเป็นบัญชีจากอีกเว็บ ให้เปิดหน้า Users/Resellers ของเว็บต้นทางเพื่อดูอีเมล</div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="rounded-xl border border-white/10 overflow-hidden">
                <div class="px-4 py-3 bg-white/5"><div class="font-medium">บัญชีที่เคยใช้ Device ID เดียวกัน</div><div class="text-xs text-gray-500">สูงสุด 20 รายการล่าสุด · การพบตรงกันเป็นข้อมูลอ้างอิง ไม่ใช่คำตัดสิน</div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-gray-500"><tr><th class="text-left p-2">เว็บ</th><th class="text-left p-2">บัญชี</th><th class="text-left p-2">IP ล่าสุด</th><th class="text-left p-2">ล่าสุด</th></tr></thead><tbody>
                <?php foreach ($sameDeviceRows as $match): ?><tr class="border-t border-white/5"><td class="p-2"><?php echo htmlspecialchars((string) ($match['site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($match['username'] ?? ('#' . ($match['user_id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2 mono"><?php echo htmlspecialchars((string) ($match['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($match['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
                <?php if ($sameDeviceRows === []): ?><tr><td colspan="4" class="p-3 text-gray-500">ไม่พบรายการอื่น</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
            <div class="rounded-xl border border-white/10 overflow-hidden">
                <div class="px-4 py-3 bg-white/5"><div class="font-medium">บัญชีที่มี IP ล่าสุดเดียวกัน</div><div class="text-xs text-yellow-300">IP มือถือ, หอพัก, บริษัท, CGNAT หรือ Wi‑Fi สาธารณะอาจแชร์กันหลายคน ห้ามใช้ข้อมูลนี้ลำพังในการตัดสินบล็อค</div></div>
                <div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-gray-500"><tr><th class="text-left p-2">เว็บ</th><th class="text-left p-2">บัญชี</th><th class="text-left p-2">อุปกรณ์</th><th class="text-left p-2">ล่าสุด</th></tr></thead><tbody>
                <?php foreach ($sameIpRows as $match): ?><tr class="border-t border-white/5"><td class="p-2"><?php echo htmlspecialchars((string) ($match['site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($match['username'] ?? ('#' . ($match['user_id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($match['device_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td class="p-2"><?php echo htmlspecialchars((string) ($match['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
                <?php if ($sameIpRows === []): ?><tr><td colspan="4" class="p-3 text-gray-500">ไม่พบรายการอื่น</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </div>
        </section>
    </div>
    <?php endif; ?>

    <section class="glass rounded-2xl p-5 overflow-x-auto">
        <div class="mb-4 flex flex-col md:flex-row md:items-end md:justify-between gap-2">
            <div><h2 class="font-semibold text-lg">อุปกรณ์ล่าสุดจากทั้งสองเว็บไซต์</h2><p class="text-sm text-gray-400">ชื่อรุ่นเป็นข้อมูล best-effort จาก Client Hints/User-Agent และเพิ่มชื่อเบราว์เซอร์โดยไม่เก็บ User-Agent ดิบ</p></div>
            <div class="text-xs text-gray-500">ทั้งหมด <?php echo (int) ($devicePagination['total'] ?? count($devices)); ?> รายการ · หน้า <?php echo (int) ($devicePagination['page'] ?? 1); ?>/<?php echo (int) ($devicePagination['pages'] ?? 1); ?></div>
        </div>
        <table class="min-w-full text-sm">
            <thead class="text-gray-400"><tr><th class="text-left p-2">เว็บ</th><th class="text-left p-2">บัญชี</th><th class="text-left p-2">อุปกรณ์</th><th class="text-left p-2">IP ล่าสุด</th><th class="text-left p-2">ล่าสุด</th><th class="p-2"></th></tr></thead>
            <tbody>
            <?php foreach ($devices as $row): ?>
                <tr class="border-t border-white/5">
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['username'] ?? ('#' . ($row['user_id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?><div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($row['role'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td>
                    <td class="p-2"><div><?php echo htmlspecialchars((string) ($row['device_label'] ?? 'ไม่ทราบรุ่น'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-xs text-indigo-300"><?php echo htmlspecialchars((string) ($row['browser_label'] ?? 'ไม่ทราบเบราว์เซอร์'), ENT_QUOTES, 'UTF-8'); ?></div><div class="mono text-xs text-gray-500"><?php echo htmlspecialchars(substr((string) ($row['device_hash'] ?? ''), 0, 16) . '…', ENT_QUOTES, 'UTF-8'); ?></div></td>
                    <td class="p-2 mono"><?php echo htmlspecialchars((string) ($row['last_ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2">
                        <div class="flex flex-wrap gap-2">
                            <?php $detailUrl = $buildSecurityUrl((int) ($devicePagination['page'] ?? 1), (int) ($blockPagination['page'] ?? 1), ['detail_site' => (string) ($row['site_id'] ?? ''), 'detail_user' => (int) ($row['user_id'] ?? 0), 'detail_device' => (string) ($row['device_hash'] ?? '')]); ?>
                            <a href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>" class="text-xs rounded bg-indigo-500/20 text-indigo-200 px-2 py-1">รายละเอียด</a>
                            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="block_device"><input type="hidden" name="device_hash" value="<?php echo htmlspecialchars((string) ($row['device_hash'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="reason" value="บล็อคจากรายการอุปกรณ์"><button class="text-xs rounded bg-red-500/20 text-red-200 px-2 py-1">บล็อคเครื่อง</button></form>
                            <?php if (!empty($row['last_ip'])): ?><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="block_ip"><input type="hidden" name="ip" value="<?php echo htmlspecialchars((string) $row['last_ip'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="reason" value="บล็อคจากรายการอุปกรณ์"><button class="text-xs rounded bg-red-500/20 text-red-200 px-2 py-1">บล็อค IP</button></form><?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($devices === []): ?><tr><td colspan="6" class="p-4 text-center text-gray-500">ยังไม่มีข้อมูลอุปกรณ์</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ((int) ($devicePagination['pages'] ?? 1) > 1): ?>
        <nav class="mt-4 flex items-center justify-between text-sm">
            <div class="text-gray-500">แสดงครั้งละ <?php echo (int) ($devicePagination['per_page'] ?? $perPage); ?> รายการ</div>
            <div class="flex items-center gap-2">
                <?php if ((int) $devicePagination['page'] > 1): ?><a class="rounded-lg bg-white/10 px-3 py-2" href="<?php echo htmlspecialchars($buildSecurityUrl((int) $devicePagination['page'] - 1, (int) ($blockPagination['page'] ?? 1)), ENT_QUOTES, 'UTF-8'); ?>">ก่อนหน้า</a><?php endif; ?>
                <span class="text-gray-400"><?php echo (int) $devicePagination['page']; ?> / <?php echo (int) $devicePagination['pages']; ?></span>
                <?php if ((int) $devicePagination['page'] < (int) $devicePagination['pages']): ?><a class="rounded-lg bg-white/10 px-3 py-2" href="<?php echo htmlspecialchars($buildSecurityUrl((int) $devicePagination['page'] + 1, (int) ($blockPagination['page'] ?? 1)), ENT_QUOTES, 'UTF-8'); ?>">ถัดไป</a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    </section>

    <section class="glass rounded-2xl p-5 overflow-x-auto">
        <div class="mb-4 flex flex-col md:flex-row md:items-end md:justify-between gap-2"><div><h2 class="font-semibold text-lg">รายการบล็อคร่วม</h2><p class="text-sm text-gray-400">ทั้งสองเว็บไซต์ตรวจรายการ Email / IP / Device ชุดนี้ร่วมกัน</p></div><div class="text-xs text-gray-500">ทั้งหมด <?php echo (int) ($blockPagination['total'] ?? count($blocks)); ?> รายการ · หน้า <?php echo (int) ($blockPagination['page'] ?? 1); ?>/<?php echo (int) ($blockPagination['pages'] ?? 1); ?></div></div>
        <table class="min-w-full text-sm">
            <thead class="text-gray-400"><tr><th class="text-left p-2">ประเภท</th><th class="text-left p-2">ค่า</th><th class="text-left p-2">เหตุผล</th><th class="text-left p-2">บล็อคจาก</th><th class="text-left p-2">เวลา</th><th class="p-2"></th></tr></thead>
            <tbody>
            <?php foreach ($blocks as $row):
                $type = strtolower((string) ($row['subject_type'] ?? ''));
                $hash = (string) ($row['subject_hash'] ?? '');
                $display = (string) ($row['subject_display'] ?? '');
                $value = $type === 'device' ? $hash : $display;
            ?>
                <tr class="border-t border-white/5">
                    <td class="p-2"><?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2 mono text-xs"><?php echo htmlspecialchars($type === 'device' ? substr($hash, 0, 20) . '…' : $display, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['blocked_by_site_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2"><?php echo htmlspecialchars((string) ($row['updated_at'] ?? $row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="p-2">
                        <?php if (in_array($type, ['email','ip','device'], true)): ?>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="unblock_<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="<?php echo $type === 'email' ? 'email' : ($type === 'ip' ? 'ip' : 'device_hash'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="text-xs rounded bg-white/10 hover:bg-white/15 px-2 py-1">ยกเลิกบล็อค</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($blocks === []): ?><tr><td colspan="6" class="p-4 text-center text-gray-500">ยังไม่มีรายการบล็อคร่วม</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ((int) ($blockPagination['pages'] ?? 1) > 1): ?>
        <nav class="mt-4 flex items-center justify-end gap-2 text-sm">
            <?php if ((int) $blockPagination['page'] > 1): ?><a class="rounded-lg bg-white/10 px-3 py-2" href="<?php echo htmlspecialchars($buildSecurityUrl((int) ($devicePagination['page'] ?? 1), (int) $blockPagination['page'] - 1), ENT_QUOTES, 'UTF-8'); ?>">ก่อนหน้า</a><?php endif; ?>
            <span class="text-gray-400"><?php echo (int) $blockPagination['page']; ?> / <?php echo (int) $blockPagination['pages']; ?></span>
            <?php if ((int) $blockPagination['page'] < (int) $blockPagination['pages']): ?><a class="rounded-lg bg-white/10 px-3 py-2" href="<?php echo htmlspecialchars($buildSecurityUrl((int) ($devicePagination['page'] ?? 1), (int) $blockPagination['page'] + 1), ENT_QUOTES, 'UTF-8'); ?>">ถัดไป</a><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
</main>
<script>
function closeDeviceDetailModal() {
    const modal = document.getElementById('deviceDetailModal');
    if (modal) modal.remove();
    try {
        const url = new URL(window.location.href);
        url.searchParams.delete('detail_site');
        url.searchParams.delete('detail_user');
        url.searchParams.delete('detail_device');
        history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
    } catch (e) {}
}
document.addEventListener('click', function (event) {
    const modal = document.getElementById('deviceDetailModal');
    if (modal && event.target === modal) closeDeviceDetailModal();
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && document.getElementById('deviceDetailModal')) closeDeviceDetailModal();
});
</script>
</body>
</html>
