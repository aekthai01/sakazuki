<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';
require_once __DIR__ . '/../includes/automation.php';
requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string { return $isThai ? $th : $en; };
$e = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$jsonPretty = static function ($value): string {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) ? $json : 'null';
};

function adminSlipDebugBind(mysqli_stmt $stmt, string $types, array &$params): bool
{
    if ($types === '') return true;
    if (strlen($types) !== count($params)) return false;
    $args = [$types];
    foreach ($params as $index => $_value) $args[] = &$params[$index];
    return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
}

function adminSlipDebugFetchEvents(string $attemptUuid): array
{
    global $conn;
    if ($attemptUuid === '' || !ensureSlipVerificationDebugTable()) return [];
    $stmt = $conn->prepare(
        'SELECT l.*, u.username, u.role FROM slip_verification_debug_logs l '
        . 'LEFT JOIN users u ON u.id = l.user_id WHERE l.attempt_uuid = ? ORDER BY l.id ASC'
    );
    if (!$stmt) return [];
    $stmt->bind_param('s', $attemptUuid);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return array_map('slipDebugDecodeRow', $rows);
}

function adminSlipDebugSafeJob(?array $job): ?array
{
    if (!$job) return null;
    $provider = slipVerificationDecryptProviderData((string) ($job['provider_data_ciphertext'] ?? ''));
    unset($job['provider_data_ciphertext']);
    $job['provider_data'] = $provider;
    return $job;
}

$schemaReady = ensureSlipVerificationDebugTable();
$controlSchemaReady = ensureSlipVerificationDebugControlTable();
$jobsReady = ensureSlipVerificationJobsTable();
$notice = '';
$noticeType = 'info';
$attemptUuid = slipDebugNormalizeUuid($_GET['attempt'] ?? $_POST['attempt'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken('slip-debug.php');
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'purge_old') {
        $days = filter_var($_POST['days'] ?? slipDebugRetentionDays(), FILTER_VALIDATE_INT);
        $days = ($days !== false && $days >= 1 && $days <= 365) ? (int) $days : slipDebugRetentionDays();
        $deleted = slipDebugPurgeOlderThan($days);
        if ($deleted >= 0) {
            $notice = $t("ลบ Debug log ที่เก่ากว่า {$days} วันแล้ว {$deleted} รายการ", "Deleted {$deleted} debug events older than {$days} days");
            $noticeType = 'success';
        } else {
            $notice = $t('ลบ Debug log ไม่สำเร็จ', 'Debug log cleanup failed');
            $noticeType = 'error';
        }
    } elseif ($postAction === 'run_history_backfill') {
        @set_time_limit(40);
        $drain = automationDrainSharedDepositHistory(40, 25, 250, 'admin_drain');
        $processed = max(0, (int) ($drain['processed'] ?? 0));
        $localReady = !empty($drain['slip_history_ready_local']);
        $globalReady = !empty($drain['global_history_ready']);
        if (($drain['success'] ?? false) !== false) {
            $notice = $t(
                'ย้ายประวัติสลิปแล้ว ' . $processed . ' รายการในรอบนี้ · เว็บนี้=' . ($localReady ? 'พร้อม' : 'ยังไม่ครบ') . ' · ทั้งสองเว็บ=' . ($globalReady ? 'พร้อม' : 'ยังไม่ครบ'),
                'Migrated ' . $processed . ' slip-history rows this run · local=' . ($localReady ? 'ready' : 'incomplete') . ' · both sites=' . ($globalReady ? 'ready' : 'incomplete')
            );
            $noticeType = $globalReady ? 'success' : 'info';
        } else {
            $blocker = '';
            $lastResult = is_array($drain['last_result'] ?? null) ? $drain['last_result'] : [];
            foreach ((array) ($lastResult['streams'] ?? []) as $streamName => $streamState) {
                if (!is_array($streamState) || empty($streamState['blocked_row_id'])) continue;
                $blocker = ' · stream=' . preg_replace('/[^a-z0-9_-]/i', '', (string) $streamName)
                    . ' · row=' . max(0, (int) $streamState['blocked_row_id'])
                    . ' · status=' . preg_replace('/[^a-z0-9_-]/i', '', (string) ($streamState['blocked_status'] ?? 'unknown'));
                break;
            }
            $notice = $t('ย้ายประวัติสลิปไม่สำเร็จ: ', 'Slip-history migration failed: ')
                . (string) ($drain['message'] ?? 'unknown error') . $blocker;
            $noticeType = 'error';
        }
    } elseif (in_array($postAction, ['arm_provider_refresh', 'cancel_provider_refresh'], true) && $attemptUuid !== '') {
        $controlJob = slipVerificationReadJobByAttempt($attemptUuid);
        $controlSlipHash = slipDebugNormalizeHash($controlJob['slip_hash'] ?? '');
        $adminId = max(0, (int) ($_SESSION['user_id'] ?? 0));
        if (!$controlJob || $controlSlipHash === '') {
            $notice = $t('ไม่พบ Job หรือ Slip hash สำหรับรายการนี้', 'No job or slip hash was found for this attempt');
            $noticeType = 'error';
        } elseif ($postAction === 'arm_provider_refresh') {
            $armed = slipDebugArmProviderRefresh($controlSlipHash, $adminId);
            $notice = $armed
                ? $t('เปิดการจับคำตอบ EasySlip ใหม่ 1 ครั้งแล้ว: การอัปโหลดสลิปเดิมครั้งถัดไปจะเรียก EasySlip จริงและใช้โควตา 1 ครั้ง', 'One fresh EasySlip capture is armed. The next upload of this same slip will call EasySlip and consume one API request.')
                : $t('เปิดการจับคำตอบ EasySlip ใหม่ไม่สำเร็จ', 'Could not arm a fresh EasySlip capture');
            $noticeType = $armed ? 'success' : 'error';
            slipDebugLogEvent('admin_provider_refresh_armed', $armed ? 'warning' : 'error', $armed ? 'Admin armed one fresh EasySlip request' : 'Admin failed to arm a fresh EasySlip request', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $controlSlipHash,
                'user_id' => (int) ($controlJob['user_id'] ?? 0),
                'error_code' => $armed ? '' : 'arm_refresh_failed',
                'context' => ['admin_id' => $adminId, 'clock' => slipDebugClockSnapshot()],
            ]);
        } else {
            $cancelled = slipDebugCancelProviderRefresh($controlSlipHash);
            $notice = $cancelled
                ? $t('ยกเลิกการเรียก EasySlip ใหม่แล้ว', 'The fresh EasySlip capture has been cancelled')
                : $t('ยกเลิกการเรียก EasySlip ใหม่ไม่สำเร็จ', 'Could not cancel the fresh EasySlip capture');
            $noticeType = $cancelled ? 'success' : 'error';
            slipDebugLogEvent('admin_provider_refresh_cancelled', $cancelled ? 'info' : 'error', $cancelled ? 'Admin cancelled the fresh EasySlip request' : 'Admin failed to cancel the fresh EasySlip request', [
                'attempt_uuid' => $attemptUuid,
                'slip_hash' => $controlSlipHash,
                'user_id' => (int) ($controlJob['user_id'] ?? 0),
                'error_code' => $cancelled ? '' : 'cancel_refresh_failed',
                'context' => ['admin_id' => $adminId, 'clock' => slipDebugClockSnapshot()],
            ]);
        }
    }
}

$exportRequested = isset($_GET['export']) && (string) $_GET['export'] === '1';

if ($schemaReady && $attemptUuid !== '' && $exportRequested) {
    $events = adminSlipDebugFetchEvents($attemptUuid);
    $job = adminSlipDebugSafeJob(slipVerificationReadJobByAttempt($attemptUuid));
    $apiKey = trim((string) getSetting('easyslip_api_key'));
    $export = [
        'diagnostic_version' => 1,
        'generated_at' => date('c'),
        'site' => [
            'site_id' => defined('APP_SITE_ID') ? APP_SITE_ID : '',
            'site_domain' => defined('APP_SITE_DOMAIN') ? APP_SITE_DOMAIN : '',
            'http_host' => $_SERVER['HTTP_HOST'] ?? '',
            'database_name' => defined('DB_NAME') ? DB_NAME : '',
            'php_version' => PHP_VERSION,
        ],
        'attempt_uuid' => $attemptUuid,
        'current_clock' => slipDebugClockSnapshot(),
        'current_settings' => [
            'easyslip_enabled' => getSetting('easyslip_enabled'),
            'easyslip_max_age_minutes_raw' => getSetting('easyslip_max_age_minutes'),
            'easyslip_account_number' => getSetting('easyslip_account_number'),
            'easyslip_phone' => getSetting('easyslip_phone'),
            'easyslip_receiver_name' => getSetting('easyslip_receiver_name'),
            'easyslip_receiver_name_en' => getSetting('easyslip_receiver_name_en'),
            'currency_name' => getSetting('currency_name'),
            'api_key_configured' => $apiKey !== '',
            'api_key_sha256_prefix' => $apiKey !== '' ? substr(hash('sha256', $apiKey), 0, 16) : '',
        ],
        'job' => $job,
        'provider_refresh_control' => $job ? slipDebugProviderRefreshStatus((string) ($job['slip_hash'] ?? '')) : null,
        'events' => $events,
    ];
    $filename = 'slip-debug-' . $attemptUuid . '-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $jsonPretty($export);
    exit;
}

$detailEvents = [];
$detailJob = null;
$providerRefreshControl = null;
$dateEvent = null;
$providerExchangeEvent = null;
if ($schemaReady && $attemptUuid !== '') {
    $detailEvents = adminSlipDebugFetchEvents($attemptUuid);
    $detailJob = adminSlipDebugSafeJob(slipVerificationReadJobByAttempt($attemptUuid));
    if ($detailJob) $providerRefreshControl = slipDebugProviderRefreshStatus((string) ($detailJob['slip_hash'] ?? ''));
    foreach ($detailEvents as $event) {
        if (($event['stage'] ?? '') === 'date_validation') $dateEvent = $event;
        if (($event['stage'] ?? '') === 'provider_exchange') $providerExchangeEvent = $event;
    }
}

$summary = ['attempts' => 0, 'events' => 0, 'errors' => 0, 'invalid_dates' => 0, 'provider_calls' => 0, 'latest_at' => null];
if ($schemaReady) {
    try {
        $result = $conn->query(
            "SELECT COUNT(DISTINCT NULLIF(attempt_uuid,'')) AS attempts, COUNT(*) AS events, "
            . "SUM(severity IN ('error','critical')) AS errors, "
            . "SUM(stage='date_validation' AND severity IN ('error','critical')) AS invalid_dates, "
            . "SUM(stage='provider_exchange') AS provider_calls, MAX(created_at) AS latest_at "
            . "FROM slip_verification_debug_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        if ($result && ($row = $result->fetch_assoc())) $summary = array_merge($summary, $row);
    } catch (Throwable $ex) {
        $notice = $t('อ่านข้อมูลสรุปไม่สำเร็จ: ', 'Could not read summary: ') . $ex->getMessage();
        $noticeType = 'error';
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$severity = strtolower(trim((string) ($_GET['severity'] ?? 'all')));
if (!in_array($severity, ['all', 'debug', 'info', 'warning', 'error', 'critical'], true)) $severity = 'all';
$stage = strtolower(trim((string) ($_GET['stage'] ?? '')));
$stage = preg_match('/^[a-z0-9_.-]{1,80}$/D', $stage) ? $stage : '';
$days = filter_var($_GET['days'] ?? 7, FILTER_VALIDATE_INT);
$days = ($days !== false && in_array((int) $days, [1, 3, 7, 14, 30, 90], true)) ? (int) $days : 7;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ["l.attempt_uuid <> ''", 'l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
$params = [$days];
$types = 'i';
if ($q !== '') {
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $where[] = "(l.attempt_uuid LIKE ? ESCAPE '!' OR l.slip_hash LIKE ? ESCAPE '!' OR l.error_code LIKE ? ESCAPE '!' OR l.event_message LIKE ? ESCAPE '!' OR l.stage LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";
    for ($i = 0; $i < 6; $i++) $params[] = $like;
    $types .= 'ssssss';
}
if ($severity !== 'all') {
    $where[] = 'l.severity = ?';
    $params[] = $severity;
    $types .= 's';
}
if ($stage !== '') {
    $where[] = 'l.stage = ?';
    $params[] = $stage;
    $types .= 's';
}
$whereSql = implode(' AND ', $where);

$totalAttempts = 0;
$attemptRows = [];
$stageOptions = [];
if ($schemaReady && $attemptUuid === '') {
    try {
        $countSql = 'SELECT COUNT(DISTINCT l.attempt_uuid) AS total FROM slip_verification_debug_logs l LEFT JOIN users u ON u.id=l.user_id WHERE ' . $whereSql;
        $countStmt = $conn->prepare($countSql);
        if ($countStmt) {
            $countParams = $params;
            adminSlipDebugBind($countStmt, $types, $countParams);
            if ($countStmt->execute()) {
                $row = $countStmt->get_result()->fetch_assoc();
                $totalAttempts = (int) ($row['total'] ?? 0);
            }
            $countStmt->close();
        }

        $listSql = "SELECT l.attempt_uuid, MAX(l.id) AS last_id, MIN(l.created_at) AS first_at, MAX(l.created_at) AS last_at, "
            . "COUNT(*) AS event_count, MAX(l.user_id) AS user_id, MAX(u.username) AS username, MAX(u.role) AS user_role, "
            . "MAX(l.slip_hash) AS slip_hash, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(l.stage ORDER BY l.id DESC SEPARATOR '|'),'|',1) AS latest_stage, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(l.severity ORDER BY l.id DESC SEPARATOR '|'),'|',1) AS latest_severity, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(l.event_message ORDER BY l.id DESC SEPARATOR '|'),'|',1) AS latest_message, "
            . "SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(l.error_code,'') ORDER BY l.id DESC SEPARATOR '|'),'|',1) AS latest_error_code, "
            . "MAX(j.status) AS job_status, MAX(j.last_error_code) AS job_error_code, MAX(j.last_error_message) AS job_error_message, MAX(j.transaction_ref) AS transaction_ref "
            . "FROM slip_verification_debug_logs l LEFT JOIN users u ON u.id=l.user_id "
            . "LEFT JOIN slip_verification_jobs j ON j.attempt_uuid=l.attempt_uuid WHERE {$whereSql} "
            . "GROUP BY l.attempt_uuid ORDER BY last_id DESC LIMIT ? OFFSET ?";
        $listStmt = $conn->prepare($listSql);
        if ($listStmt) {
            $listParams = $params;
            $listParams[] = $perPage;
            $listParams[] = $offset;
            $listTypes = $types . 'ii';
            adminSlipDebugBind($listStmt, $listTypes, $listParams);
            if ($listStmt->execute()) {
                $result = $listStmt->get_result();
                $attemptRows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            $listStmt->close();
        }

        $stageResult = $conn->query("SELECT stage, COUNT(*) AS c FROM slip_verification_debug_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY stage ORDER BY c DESC, stage ASC LIMIT 100");
        $stageOptions = $stageResult ? $stageResult->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $ex) {
        $notice = $t('อ่าน Debug log ไม่สำเร็จ: ', 'Could not read debug logs: ') . $ex->getMessage();
        $noticeType = 'error';
    }
}
$totalPages = max(1, (int) ceil($totalAttempts / $perPage));
$currentClock = slipDebugClockSnapshot();
$historyJobState = automationReadJobState('shared_history');
$historyStatus = function_exists('sharedLedgerHistoryReady') ? sharedLedgerHistoryReady() : ['success' => false, 'ready' => false, 'message' => 'Unavailable'];
$historyCursors = [
    'transaction' => trim((string) getSetting('shared_ledger_backfill_slip_cursor', '')),
    'image' => trim((string) getSetting('shared_ledger_backfill_slip_image_cursor', '')),
];
$providerHealth = null;
if ($jobsReady) {
    try {
        $healthResult = $conn->query("SELECT * FROM slip_provider_health WHERE provider='easyslip' LIMIT 1");
        $providerHealth = $healthResult ? $healthResult->fetch_assoc() : null;
        if ($healthResult) $healthResult->free();
    } catch (Throwable $ignored) {}
}

$severityClass = static function (string $value): string {
    return [
        'debug' => 'bg-slate-500/15 text-slate-300 border-slate-400/20',
        'info' => 'bg-sky-500/15 text-sky-300 border-sky-400/20',
        'warning' => 'bg-amber-500/15 text-amber-200 border-amber-400/20',
        'error' => 'bg-red-500/15 text-red-200 border-red-400/20',
        'critical' => 'bg-fuchsia-500/15 text-fuchsia-200 border-fuchsia-400/20',
    ][$value] ?? 'bg-white/10 text-gray-300 border-white/10';
};
$statusClass = static function (string $value): string {
    if ($value === 'completed') return 'bg-emerald-500/15 text-emerald-300';
    if (in_array($value, ['failed', 'review'], true)) return 'bg-red-500/15 text-red-200';
    if (in_array($value, ['verifying', 'provider_verified', 'crediting'], true)) return 'bg-amber-500/15 text-amber-200';
    return 'bg-white/10 text-gray-300';
};
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('Debug ตรวจสลิป', 'Slip Verification Debug')); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#8b5cf6;--sakazuki-accent-rgb:139 92 246}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono-wrap{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere;word-break:break-word}
        details>summary::-webkit-details-marker{display:none}
        pre{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
    </style>
</head>
<body class="min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="mx-auto max-w-[96rem] space-y-5 p-4 md:p-6">
    <section class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold text-white"><i class="bi bi-bug text-fuchsia-300"></i><?php echo $e($t('Debug ระบบตรวจสลิป', 'Slip Verification Debug')); ?></h1>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-400"><?php echo $e($t('บันทึกลำดับตั้งแต่รับไฟล์, ตรวจรูป, ส่งคำขอ EasySlip, รับ JSON ตอบกลับ, แปลงข้อมูล, ตรวจวันเวลา, ตรวจบัญชีผู้รับ จนถึงผลที่ส่งกลับเบราว์เซอร์ โดยไม่เก็บ API Key หรือ Base64 ของรูปสลิป', 'Captures the full lifecycle from upload validation through the EasySlip exchange, normalization, date/receiver checks, and the browser response. API keys and image Base64 are never stored.')); ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($attemptUuid !== ''): ?>
                <a href="slip-debug.php" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15"><i class="bi bi-arrow-left mr-1"></i><?php echo $e($t('กลับหน้ารวม', 'Back to list')); ?></a>
                <a href="slip-debug.php?attempt=<?php echo $e($attemptUuid); ?>&export=1" download data-no-page-loader class="rounded-lg bg-fuchsia-500 px-4 py-2 text-sm font-medium text-white hover:bg-fuchsia-600"><i class="bi bi-download mr-1"></i><?php echo $e($t('ดาวน์โหลด JSON ส่งให้วิเคราะห์', 'Download diagnostic JSON')); ?></a>
            <?php else: ?>
                <a href="slip-debug.php" class="rounded-lg bg-violet-500 px-4 py-2 text-sm font-medium text-white hover:bg-violet-600"><i class="bi bi-arrow-clockwise mr-1"></i><?php echo $e($t('รีเฟรช', 'Refresh')); ?></a>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$schemaReady): ?>
        <section class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-red-200"><i class="bi bi-database-x mr-2"></i><?php echo $e($t('ไม่สามารถสร้างหรืออ่านตาราง slip_verification_debug_logs ได้ กรุณาตรวจสิทธิ์ CREATE TABLE ของฐานข้อมูล', 'Could not create or read slip_verification_debug_logs. Check the database CREATE TABLE permission.')); ?></section>
    <?php endif; ?>
    <?php if (!$controlSchemaReady || !$jobsReady): ?>
        <section class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-amber-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $e($t('ตารางควบคุมการ Debug หรือ Job ตรวจสลิปยังไม่พร้อม ฟังก์ชันเรียก EasySlip ใหม่ 1 ครั้งจะใช้งานไม่ได้ แต่ Log หลักยังทำงานได้', 'The diagnostic control or verification-job table is unavailable. One-time fresh EasySlip capture is disabled, while normal logging can still work.')); ?></section>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <section class="rounded-xl border p-4 text-sm <?php echo $noticeType === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' : ($noticeType === 'error' ? 'border-red-500/30 bg-red-500/10 text-red-200' : 'border-sky-500/30 bg-sky-500/10 text-sky-200'); ?>"><?php echo $e($notice); ?></section>
    <?php endif; ?>

    <section class="glass rounded-xl p-4">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h2 class="font-semibold text-white"><i class="bi bi-shield-check mr-1 text-emerald-300"></i><?php echo $e($t('สุขภาพระบบสลิปและ Shared History', 'Slip provider & shared-history health')); ?></h2>
                <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Global history</div><div class="mt-1 font-semibold <?php echo !empty($historyStatus['ready']) ? 'text-emerald-300' : 'text-amber-300'; ?>"><?php echo !empty($historyStatus['ready']) ? 'READY' : 'NOT READY'; ?></div><div class="mono-wrap mt-1 text-gray-500"><?php echo $e(implode(', ', (array) ($historyStatus['missing_site_ids'] ?? []))); ?></div></div>
                    <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Local cursors</div><div class="mono-wrap mt-1 text-gray-200">transaction=<?php echo $e($historyCursors['transaction'] === '' ? 'uninitialized' : $historyCursors['transaction']); ?><br>image=<?php echo $e($historyCursors['image'] === '' ? 'uninitialized' : $historyCursors['image']); ?></div></div>
                    <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Shared-history worker</div><div class="mono-wrap mt-1 text-gray-200">last=<?php echo $e($historyJobState['last_finished_at'] ?? '-'); ?><br>next=<?php echo $e($historyJobState['next_run_at'] ?? '-'); ?></div><div class="mt-1 text-gray-500"><?php echo $e($historyJobState['last_message'] ?? '-'); ?></div></div>
                    <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">EasySlip breaker</div><div class="mono-wrap mt-1 text-gray-200">open_until=<?php echo $e($providerHealth['breaker_open_until'] ?? '-'); ?><br>failures=<?php echo $e($providerHealth['consecutive_transport_failures'] ?? 0); ?><br>last=<?php echo $e($providerHealth['last_transport_class'] ?? '-'); ?> / <?php echo $e($providerHealth['last_ip_family'] ?? '-'); ?></div></div>
                </div>
            </div>
            <form method="POST" class="shrink-0" onsubmit="return confirm('<?php echo $e($t('รัน Bulk Backfill ของเว็บนี้ทันทีหรือไม่? ระบบจะไม่ force readiness และไม่แตะยอดเงินลูกค้า', 'Run the local bulk slip-history migration now? This never forces readiness or changes customer balances.')); ?>')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="run_history_backfill">
                <button type="submit" class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-black hover:bg-emerald-400"><i class="bi bi-database-up mr-1"></i><?php echo $e($t('เร่งย้ายประวัติสลิปเว็บนี้', 'Drain local slip history')); ?></button>
            </form>
        </div>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <?php
        $cards = [
            [$t('Attempts 24 ชม.', 'Attempts / 24h'), (int) ($summary['attempts'] ?? 0), 'text-violet-300'],
            [$t('Events 24 ชม.', 'Events / 24h'), (int) ($summary['events'] ?? 0), 'text-sky-300'],
            [$t('Error Events', 'Error events'), (int) ($summary['errors'] ?? 0), 'text-red-300'],
            [$t('วันเวลาถูกปฏิเสธ', 'Date rejections'), (int) ($summary['invalid_dates'] ?? 0), 'text-amber-300'],
            [$t('เรียก EasySlip', 'EasySlip calls'), (int) ($summary['provider_calls'] ?? 0), 'text-emerald-300'],
            [$t('ล่าสุด', 'Latest'), $summary['latest_at'] ?: '-', 'text-gray-200'],
        ];
        foreach ($cards as $card): ?>
            <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($card[0]); ?></div><div class="mono-wrap mt-2 text-lg font-bold <?php echo $card[2]; ?>"><?php echo $e($card[1]); ?></div></div>
        <?php endforeach; ?>
    </section>

    <section class="glass rounded-xl p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="text-sm font-semibold text-white"><i class="bi bi-clock-history mr-1 text-cyan-300"></i><?php echo $e($t('นาฬิกาปัจจุบันของระบบ', 'Current system clocks')); ?></div>
                <div class="mono-wrap mt-2 text-xs leading-6 text-gray-400">
                    PHP: <?php echo $e($currentClock['php_local_iso8601'] ?? '-'); ?> · TZ: <?php echo $e($currentClock['php_timezone'] ?? '-'); ?><br>
                    DB: <?php echo $e($currentClock['database']['db_now'] ?? '-'); ?> · Session TZ: <?php echo $e($currentClock['database']['db_session_timezone'] ?? '-'); ?> · DB-PHP: <?php echo $e($currentClock['db_minus_php_seconds'] ?? '-'); ?>s
                </div>
            </div>
            <form method="POST" class="flex flex-wrap items-end gap-2" onsubmit="return confirm('<?php echo $e($t('ลบเฉพาะ Debug log ที่เก่ากว่าจำนวนวันที่เลือกหรือไม่?', 'Delete only debug events older than the selected number of days?')); ?>')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="purge_old">
                <label class="text-xs text-gray-400"><span class="mb-1 block"><?php echo $e($t('เก่ากว่า', 'Older than')); ?></span><select name="days" class="rounded-lg border border-white/10 bg-panel px-3 py-2 text-white"><?php foreach ([7,14,30,60,90,180] as $option): ?><option value="<?php echo $option; ?>" <?php echo $option === slipDebugRetentionDays() ? 'selected' : ''; ?>><?php echo $option; ?> <?php echo $e($t('วัน', 'days')); ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="rounded-lg border border-red-400/20 bg-red-500/10 px-4 py-2 text-sm text-red-200 hover:bg-red-500/20"><i class="bi bi-trash3 mr-1"></i><?php echo $e($t('ลบ Log เก่า', 'Purge old logs')); ?></button>
            </form>
        </div>
    </section>

    <?php if ($attemptUuid !== ''): ?>
        <?php if (!$detailEvents && !$detailJob): ?>
            <section class="glass rounded-xl p-8 text-center text-gray-400"><?php echo $e($t('ไม่พบ Attempt นี้', 'Attempt not found')); ?></section>
        <?php else: ?>
            <section class="grid gap-3 lg:grid-cols-4">
                <div class="glass rounded-xl p-4 lg:col-span-2"><div class="text-xs text-gray-500">Attempt UUID</div><div class="mono-wrap mt-2 text-sm text-violet-200"><?php echo $e($attemptUuid); ?></div></div>
                <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('สถานะ Job', 'Job status')); ?></div><div class="mt-2"><span class="rounded-full px-3 py-1 text-sm <?php echo $statusClass((string) ($detailJob['status'] ?? '')); ?>"><?php echo $e($detailJob['status'] ?? '-'); ?></span></div></div>
                <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500"><?php echo $e($t('จำนวน Event', 'Event count')); ?></div><div class="mt-2 text-xl font-bold text-sky-300"><?php echo count($detailEvents); ?></div></div>
            </section>

            <?php if ($detailJob): ?>
                <section class="glass rounded-xl p-4">
                    <h2 class="font-semibold text-white"><i class="bi bi-diagram-3 mr-1 text-violet-300"></i><?php echo $e($t('สถานะ Durable Job', 'Durable job state')); ?></h2>
                    <div class="mt-3 grid gap-3 text-sm md:grid-cols-2 xl:grid-cols-4">
                        <?php foreach ([
                            $t('ผู้ใช้', 'User') => ($detailJob['user_id'] ?? '-') . ' / ' . (($detailEvents[0]['username'] ?? '') ?: '-'),
                            'Slip Hash' => $detailJob['slip_hash'] ?? '-',
                            $t('เลขอ้างอิง', 'Transaction ref') => $detailJob['transaction_ref'] ?? '-',
                            $t('จำนวนครั้ง', 'Attempts') => $detailJob['attempts'] ?? '-',
                            $t('Error Code', 'Error code') => $detailJob['last_error_code'] ?? '-',
                            $t('Error Message', 'Error message') => $detailJob['last_error_message'] ?? '-',
                            $t('สร้างเมื่อ', 'Created') => $detailJob['created_at'] ?? '-',
                            $t('อัปเดตเมื่อ', 'Updated') => $detailJob['updated_at'] ?? '-',
                        ] as $label => $value): ?>
                            <div class="rounded-lg bg-black/20 p-3"><div class="text-xs text-gray-500"><?php echo $e($label); ?></div><div class="mono-wrap mt-1 text-gray-200"><?php echo $e($value); ?></div></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($detailJob && !empty($detailJob['slip_hash'])): ?>
                <?php $refreshArmed = !empty($providerRefreshControl['force_provider_refresh_once']); ?>
                <section class="rounded-xl border <?php echo $refreshArmed ? 'border-amber-400/30 bg-amber-500/10' : 'border-fuchsia-400/20 bg-fuchsia-500/[.07]'; ?> p-4">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                        <div class="max-w-4xl">
                            <h2 class="font-semibold <?php echo $refreshArmed ? 'text-amber-200' : 'text-fuchsia-200'; ?>"><i class="bi bi-radioactive mr-1"></i><?php echo $e($t('จับคำตอบ EasySlip ใหม่สำหรับสลิปเดิม 1 ครั้ง', 'Capture one fresh EasySlip response for this same slip')); ?></h2>
                            <p class="mt-2 text-sm leading-6 text-gray-400"><?php echo $e($t('ปกติระบบจะใช้ผล EasySlip ที่บันทึกไว้เพื่อไม่เสียโควตาซ้ำ ปุ่มนี้จะบังคับเฉพาะการอัปโหลดสลิปเดิมครั้งถัดไปให้เรียก EasySlip จริง 1 ครั้ง จากนั้นปิดตัวเองอัตโนมัติ ใช้สำหรับเก็บ Request/Response ดิบเพื่อวิเคราะห์เท่านั้น', 'Normally the saved EasySlip snapshot is reused to avoid wasting quota. This control forces only the next upload of the same slip to call EasySlip once, then turns itself off automatically. It exists solely to capture a fresh raw request/response for diagnosis.')); ?></p>
                            <div class="mono-wrap mt-2 text-xs text-gray-500">Slip hash: <?php echo $e($detailJob['slip_hash']); ?><?php if ($providerRefreshControl): ?> · requested: <?php echo $e($providerRefreshControl['requested_at'] ?? '-'); ?> · consumed: <?php echo $e($providerRefreshControl['consumed_at'] ?? '-'); ?><?php endif; ?></div>
                        </div>
                        <form method="POST" class="shrink-0" onsubmit="return confirm('<?php echo $e($refreshArmed ? $t('ยกเลิกการเรียก EasySlip ใหม่หรือไม่?', 'Cancel the fresh EasySlip capture?') : $t('การทดสอบครั้งถัดไปจะเรียก EasySlip จริงและใช้โควตา 1 ครั้ง ยืนยันหรือไม่?', 'The next test will call EasySlip and consume one API request. Continue?')); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="attempt" value="<?php echo $e($attemptUuid); ?>">
                            <input type="hidden" name="action" value="<?php echo $refreshArmed ? 'cancel_provider_refresh' : 'arm_provider_refresh'; ?>">
                            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium <?php echo $refreshArmed ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100 hover:bg-amber-500/25' : 'bg-fuchsia-500 text-white hover:bg-fuchsia-600'; ?>"><i class="bi <?php echo $refreshArmed ? 'bi-x-circle' : 'bi-cloud-arrow-down'; ?> mr-1"></i><?php echo $e($refreshArmed ? $t('ยกเลิกการรอทดสอบ', 'Cancel armed capture') : $t('เปิดจับคำตอบใหม่ 1 ครั้ง', 'Arm one fresh capture')); ?></button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($dateEvent): ?>
                <?php $dateAnalysis = $dateEvent['response']['date_analysis'] ?? []; $dateAccepted = !empty($dateAnalysis['accepted']); ?>
                <section class="rounded-xl border p-4 <?php echo $dateAccepted ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-red-500/30 bg-red-500/10'; ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-semibold <?php echo $dateAccepted ? 'text-emerald-200' : 'text-red-200'; ?>"><i class="bi bi-calendar2-check mr-1"></i><?php echo $e($t('ผลวิเคราะห์วันเวลา', 'Date validation analysis')); ?></h2>
                        <span class="rounded-full px-3 py-1 text-xs <?php echo $dateAccepted ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>"><?php echo $e($dateAnalysis['reason'] ?? '-'); ?></span>
                    </div>
                    <div class="mt-4 grid gap-3 text-sm md:grid-cols-2 xl:grid-cols-4">
                        <?php foreach ([
                            $t('ค่าจาก EasySlip', 'EasySlip date') => $dateAnalysis['raw_value'] ?? '-',
                            $t('เวลาที่ PHP แปลงได้', 'Parsed time') => $dateAnalysis['parsed_iso8601'] ?? '-',
                            $t('เวลาปัจจุบัน PHP', 'PHP now') => $dateAnalysis['server_local_iso8601'] ?? '-',
                            $t('อายุสลิป (นาที)', 'Slip age (minutes)') => $dateAnalysis['age_minutes'] ?? '-',
                            $t('ขีดจำกัด (นาที)', 'Max age (minutes)') => $dateAnalysis['max_age_minutes'] ?? '-',
                            $t('เวลาเก่าสุดที่ยอมรับ', 'Oldest accepted') => $dateAnalysis['oldest_allowed_local_iso8601'] ?? '-',
                            $t('Timezone ที่ Parse', 'Parsed timezone') => $dateAnalysis['parsed_timezone_name'] ?? '-',
                            $t('ใช้ค่า Fallback 1440', 'Used 1440 fallback') => !empty($dateEvent['context']['fallback_to_1440_used']) ? 'true' : 'false',
                        ] as $label => $value): ?>
                            <div class="rounded-lg bg-black/20 p-3"><div class="text-xs text-gray-500"><?php echo $e($label); ?></div><div class="mono-wrap mt-1 text-gray-100"><?php echo $e($value); ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <details class="mt-4 rounded-lg bg-black/25"><summary class="cursor-pointer p-3 text-sm text-gray-300"><i class="bi bi-code-square mr-1"></i><?php echo $e($t('ดู JSON การคำนวณทั้งหมด', 'View full calculation JSON')); ?></summary><pre class="border-t border-white/10 p-4 text-xs text-gray-300"><?php echo $e($jsonPretty(['response'=>$dateEvent['response'],'context'=>$dateEvent['context']])); ?></pre></details>
                </section>
            <?php endif; ?>

            <?php if ($providerExchangeEvent): ?>
                <section class="glass rounded-xl p-4">
                    <h2 class="font-semibold text-white"><i class="bi bi-cloud-arrow-left-right mr-1 text-sky-300"></i><?php echo $e($t('คำขอและคำตอบ EasySlip จริง', 'Actual EasySlip request and response')); ?></h2>
                    <p class="mt-1 text-xs text-gray-500"><?php echo $e($t('Base64 รูปและ Authorization ถูกตัดออก แต่โครงสร้าง Request, HTTP status, cURL info และ JSON ตอบกลับถูกเก็บไว้', 'Image Base64 and Authorization are removed; request structure, HTTP status, cURL details, and the returned JSON are retained.')); ?></p>
                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                        <div><div class="mb-2 text-sm font-medium text-violet-200">Request</div><pre class="max-h-[42rem] overflow-auto rounded-lg bg-black/35 p-4 text-xs text-gray-300"><?php echo $e($jsonPretty($providerExchangeEvent['request'])); ?></pre></div>
                        <div><div class="mb-2 text-sm font-medium text-emerald-200">Response</div><pre class="max-h-[42rem] overflow-auto rounded-lg bg-black/35 p-4 text-xs text-gray-300"><?php echo $e($jsonPretty($providerExchangeEvent['response'])); ?></pre></div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-white"><i class="bi bi-list-ol mr-1 text-fuchsia-300"></i><?php echo $e($t('Timeline ทุกขั้นตอน', 'Complete event timeline')); ?></h2>
                <?php foreach ($detailEvents as $index => $event): ?>
                    <?php $sev = (string) ($event['severity'] ?? 'info'); ?>
                    <details class="glass overflow-hidden rounded-xl" <?php echo in_array($sev, ['error','critical'], true) || ($event['stage'] ?? '') === 'date_validation' ? 'open' : ''; ?>>
                        <summary class="flex cursor-pointer list-none flex-col gap-2 p-4 hover:bg-white/[.03] md:flex-row md:items-center md:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2"><span class="text-xs text-gray-500">#<?php echo $index + 1; ?></span><span class="mono-wrap font-semibold text-white"><?php echo $e($event['stage'] ?? '-'); ?></span><span class="rounded-full border px-2 py-0.5 text-[11px] <?php echo $severityClass($sev); ?>"><?php echo $e($sev); ?></span><?php if (!empty($event['error_code'])): ?><span class="mono-wrap rounded bg-red-500/10 px-2 py-0.5 text-[11px] text-red-200"><?php echo $e($event['error_code']); ?></span><?php endif; ?></div>
                                <div class="mt-1 text-sm text-gray-400"><?php echo $e($event['event_message'] ?? ''); ?></div>
                            </div>
                            <div class="mono-wrap shrink-0 text-xs text-gray-500"><?php echo $e($event['created_at'] ?? '-'); ?><?php echo isset($event['duration_ms']) && $event['duration_ms'] !== null ? ' · ' . $e($event['duration_ms']) . ' ms' : ''; ?><?php echo isset($event['http_code']) && $event['http_code'] !== null ? ' · HTTP ' . $e($event['http_code']) : ''; ?></div>
                        </summary>
                        <div class="border-t border-white/10 p-4">
                            <div class="grid gap-4 xl:grid-cols-3">
                                <?php foreach (['request' => 'Request', 'response' => 'Response', 'context' => 'Context'] as $field => $label): ?>
                                    <div>
                                        <div class="mb-2 flex items-center justify-between text-xs text-gray-400"><span><?php echo $label; ?></span><span><?php echo $e($event[$field . '_storage'] ?? 'none'); ?></span></div>
                                        <pre class="max-h-[38rem] overflow-auto rounded-lg bg-black/35 p-3 text-xs text-gray-300"><?php echo $e($jsonPretty($event[$field] ?? null)); ?></pre>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="glass rounded-xl p-4">
            <form method="get" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                <label class="xl:col-span-2"><span class="mb-1 block text-xs text-gray-500"><?php echo $e($t('ค้นหา Attempt, Hash, Username, Stage, Error', 'Search attempt, hash, username, stage, error')); ?></span><input type="text" name="q" value="<?php echo $e($q); ?>" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm text-white outline-none focus:border-violet-400" placeholder="UUID / hash / invalid_date"></label>
                <label><span class="mb-1 block text-xs text-gray-500">Severity</span><select name="severity" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm text-white"><?php foreach (['all','debug','info','warning','error','critical'] as $option): ?><option value="<?php echo $option; ?>" <?php echo $severity === $option ? 'selected' : ''; ?>><?php echo ucfirst($option); ?></option><?php endforeach; ?></select></label>
                <label><span class="mb-1 block text-xs text-gray-500">Stage</span><select name="stage" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm text-white"><option value=""><?php echo $e($t('ทั้งหมด', 'All')); ?></option><?php foreach ($stageOptions as $option): ?><option value="<?php echo $e($option['stage']); ?>" <?php echo $stage === $option['stage'] ? 'selected' : ''; ?>><?php echo $e($option['stage']); ?> (<?php echo (int) $option['c']; ?>)</option><?php endforeach; ?></select></label>
                <label><span class="mb-1 block text-xs text-gray-500"><?php echo $e($t('ช่วงเวลา', 'Time range')); ?></span><select name="days" class="w-full rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm text-white"><?php foreach ([1,3,7,14,30,90] as $option): ?><option value="<?php echo $option; ?>" <?php echo $days === $option ? 'selected' : ''; ?>><?php echo $option; ?> <?php echo $e($t('วัน', 'days')); ?></option><?php endforeach; ?></select></label>
                <div class="flex items-end gap-2"><button class="flex-1 rounded-lg bg-violet-500 px-4 py-2 text-sm font-medium text-white hover:bg-violet-600"><i class="bi bi-search mr-1"></i><?php echo $e($t('ค้นหา', 'Search')); ?></button><a href="slip-debug.php" class="rounded-lg bg-white/10 px-4 py-2 text-sm hover:bg-white/15"><i class="bi bi-x-lg"></i></a></div>
            </form>
        </section>

        <section class="glass overflow-hidden rounded-xl">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 p-4"><div><h2 class="font-semibold text-white"><?php echo $e($t('รายการตรวจสลิป', 'Verification attempts')); ?></h2><div class="mt-1 text-xs text-gray-500"><?php echo number_format($totalAttempts); ?> attempts · page <?php echo $page; ?>/<?php echo $totalPages; ?></div></div></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-white/[.03] text-xs uppercase text-gray-500"><tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">User</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Latest Stage</th><th class="px-4 py-3">Error</th><th class="px-4 py-3">Attempt / Hash</th><th class="px-4 py-3 text-right">Events</th></tr></thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (!$attemptRows): ?><tr><td colspan="7" class="px-4 py-10 text-center text-gray-500"><?php echo $e($t('ยังไม่มี Debug log ตามเงื่อนไขนี้', 'No debug logs match these filters')); ?></td></tr><?php endif; ?>
                        <?php foreach ($attemptRows as $row): ?>
                            <?php $latestSev = (string) ($row['latest_severity'] ?? 'info'); $jobStatus = (string) ($row['job_status'] ?? ''); ?>
                            <tr class="hover:bg-white/[.025]">
                                <td class="whitespace-nowrap px-4 py-3"><div class="mono-wrap text-xs text-gray-300"><?php echo $e($row['last_at'] ?? '-'); ?></div><div class="mt-1 text-[11px] text-gray-600"><?php echo $e($row['first_at'] ?? '-'); ?></div></td>
                                <td class="px-4 py-3"><div class="font-medium text-white"><?php echo $e($row['username'] ?: ('#' . $row['user_id'])); ?></div><div class="text-xs text-gray-500"><?php echo $e($row['user_role'] ?? '-'); ?></div></td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs <?php echo $statusClass($jobStatus); ?>"><?php echo $e($jobStatus ?: '-'); ?></span></td>
                                <td class="px-4 py-3"><div class="flex items-center gap-2"><span class="rounded-full border px-2 py-0.5 text-[11px] <?php echo $severityClass($latestSev); ?>"><?php echo $e($latestSev); ?></span><span class="mono-wrap text-xs text-gray-200"><?php echo $e($row['latest_stage'] ?? '-'); ?></span></div><div class="mt-1 max-w-md truncate text-xs text-gray-500"><?php echo $e($row['latest_message'] ?? ''); ?></div></td>
                                <td class="px-4 py-3"><div class="mono-wrap text-xs text-red-200"><?php echo $e($row['job_error_code'] ?: ($row['latest_error_code'] ?: '-')); ?></div><div class="mt-1 max-w-sm truncate text-xs text-gray-500"><?php echo $e($row['job_error_message'] ?? ''); ?></div></td>
                                <td class="px-4 py-3"><a href="slip-debug.php?attempt=<?php echo $e($row['attempt_uuid']); ?>" class="mono-wrap text-xs text-violet-300 hover:text-violet-200"><?php echo $e($row['attempt_uuid']); ?></a><div class="mono-wrap mt-1 max-w-xs truncate text-[11px] text-gray-600" title="<?php echo $e($row['slip_hash']); ?>"><?php echo $e($row['slip_hash']); ?></div></td>
                                <td class="px-4 py-3 text-right"><a href="slip-debug.php?attempt=<?php echo $e($row['attempt_uuid']); ?>" class="inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-1.5 text-xs hover:bg-white/15"><?php echo (int) $row['event_count']; ?> <i class="bi bi-chevron-right"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="flex items-center justify-between border-t border-white/10 p-4 text-sm">
                    <?php $queryBase = $_GET; unset($queryBase['page'], $queryBase['attempt'], $queryBase['export']); ?>
                    <a class="rounded-lg bg-white/10 px-4 py-2 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-white/15'; ?>" href="?<?php echo $e(http_build_query(array_merge($queryBase, ['page' => max(1, $page - 1)]))); ?>"><i class="bi bi-chevron-left mr-1"></i><?php echo $e($t('ก่อนหน้า', 'Previous')); ?></a>
                    <span class="text-gray-500"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                    <a class="rounded-lg bg-white/10 px-4 py-2 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-white/15'; ?>" href="?<?php echo $e(http_build_query(array_merge($queryBase, ['page' => min($totalPages, $page + 1)]))); ?>"><?php echo $e($t('ถัดไป', 'Next')); ?><i class="bi bi-chevron-right ml-1"></i></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
