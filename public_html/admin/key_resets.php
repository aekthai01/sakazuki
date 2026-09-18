<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/key_reset.php';

requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$isThai = function_exists('getAppLang') ? getAppLang() !== 'en' : true;
$t = static function (string $th, string $en) use ($isThai): string {
    return $isThai ? $th : $en;
};
$e = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$flash = null;
if (isset($_SESSION['key_reset_admin_flash']) && is_array($_SESSION['key_reset_admin_flash'])) {
    $flash = $_SESSION['key_reset_admin_flash'];
} elseif (isset($_SESSION['xchetos_admin_flash']) && is_array($_SESSION['xchetos_admin_flash'])) {
    $flash = $_SESSION['xchetos_admin_flash'];
}
unset($_SESSION['key_reset_admin_flash'], $_SESSION['xchetos_admin_flash']);

$diagnosticReport = isset($_SESSION['key_reset_admin_diagnostic_report']) && is_array($_SESSION['key_reset_admin_diagnostic_report'])
    ? $_SESSION['key_reset_admin_diagnostic_report']
    : null;
$indexReport = isset($_SESSION['key_reset_admin_index_report']) && is_array($_SESSION['key_reset_admin_index_report'])
    ? $_SESSION['key_reset_admin_index_report']
    : null;
unset($_SESSION['key_reset_admin_diagnostic_report'], $_SESSION['key_reset_admin_index_report']);

$messages = [
    'success' => $t('รีเซ็ตคีย์สำเร็จ', 'Key reset completed successfully.'),
    'diagnostic_success' => $t('ทดสอบการเชื่อมต่อสำเร็จ ล็อกอินและอ่านรายการคีย์ได้', 'Diagnostic passed. Login and provider inventory access succeeded.'),
    'token_cleared' => $t('ล้าง Token cache แล้ว คำขอถัดไปจะล็อกอินใหม่', 'The token cache was cleared. The next request will sign in again.'),
    'limits_updated' => $t('บันทึกขีดจำกัดใหม่แล้ว', 'The reset limits were updated.'),
    'invalid_request' => $t('คำขอไม่ถูกต้อง', 'The request is invalid.'),
    'forbidden' => $t('บัญชีนี้ไม่มีสิทธิ์ใช้งาน', 'This account is not authorized.'),
    'provider_not_supported' => $t('ยังไม่มีตัวเชื่อมต่อสำหรับผู้ให้บริการนี้', 'No reset adapter is available for this provider.'),
    'settings_unavailable' => $t('ระบบตั้งค่าไม่พร้อมใช้งาน', 'The settings service is unavailable.'),
    'settings_save_failed' => $t('บันทึกขีดจำกัดไม่สำเร็จ', 'The limits could not be saved.'),
    'not_configured' => $t('ตั้งค่าบัญชีผู้ให้บริการยังไม่ครบ', 'The provider account is not fully configured.'),
    'audit_unavailable' => $t('ระบบ Logs ไม่พร้อม จึงไม่ส่งคำขอรีเซ็ต', 'The audit log is unavailable, so no reset was sent.'),
    'key_not_supported' => $t('คีย์ไม่ตรงรูปแบบที่ผู้ให้บริการรองรับ', 'The key does not match a supported provider format.'),
    'rate_limited' => $t('ส่งคำขอถี่เกินไป กรุณารอสักครู่', 'Requests are being submitted too quickly. Please wait.'),
    'daily_limit_reached' => $t('ตัวแทนใช้โควตาครบในรอบ 24 ชั่วโมงแล้ว', 'The reseller reached the rolling 24-hour allowance.'),
    'key_reset_limit_reached' => $t('คีย์นี้ใช้สิทธิ์รีเซ็ตของตัวแทนครบแล้ว', 'This key reached its reseller reset limit.'),
    'cooldown' => $t('คีย์นี้เพิ่งถูกรีเซ็ตและยังอยู่ในช่วงพัก', 'This key was reset recently and is still in cooldown.'),
    'verification_pending' => $t('ผลครั้งก่อนยังไม่แน่ชัด กรุณาตรวจ Logs ก่อนกดซ้ำ', 'The previous result is uncertain. Inspect the logs before retrying.'),
    'busy' => $t('มีคำขออื่นกำลังทำงานกับบัญชีหรือคีย์นี้', 'Another request is processing for this account or key.'),
    'login_failed' => $t('ล็อกอินผู้ให้บริการไม่สำเร็จ', 'Provider login failed.'),
    'provider_not_owned' => $t('ผู้ให้บริการปฏิเสธคีย์นี้ คีย์อาจไม่ได้อยู่ในบัญชีผู้ให้บริการของร้าน', 'The provider rejected this key. It may not belong to the shop provider account.'),
    'provider_rate_limited' => $t('ผู้ให้บริการจำกัดคำขอชั่วคราว', 'The provider temporarily rate-limited requests.'),
    'provider_rejected' => $t('ผู้ให้บริการปฏิเสธคำขอรีเซ็ต', 'The provider rejected the reset request.'),
    'provider_unavailable' => $t('เชื่อมต่อผู้ให้บริการไม่ได้', 'The provider is unavailable.'),
    'provider_unknown' => $t('ผลไม่แน่ชัด การรีเซ็ตอาจสำเร็จแล้ว ห้ามกดซ้ำทันที', 'The result is uncertain and may already have succeeded. Do not retry immediately.'),
    'provider_verification_unknown' => $t('ตรวจสอบความเป็นเจ้าของคีย์ไม่สำเร็จแบบไม่แน่ชัด จึงหยุดก่อนรีเซ็ต', 'Provider key verification was uncertain, so the reset was stopped.'),
    'provider_inventory_invalid' => $t('รูปแบบรายการคีย์จากผู้ให้บริการเปลี่ยนหรือไม่ถูกต้อง', 'The provider inventory response was invalid or changed.'),
    'provider_inventory_incomplete' => $t('ตรวจรายการคีย์ได้ไม่ครบ จึงไม่เสี่ยงส่งรีเซ็ต', 'The provider inventory could not be scanned completely, so no reset was sent.'),
    'curl_missing' => $t('เซิร์ฟเวอร์ไม่ได้เปิด PHP cURL', 'PHP cURL is not enabled on the server.'),
    'server_error' => $t('เซิร์ฟเวอร์เกิดข้อผิดพลาดก่อนยืนยันผล กรุณาใช้ Ref ตรวจ Log', 'The server failed before confirming the result. Use the reference ID to inspect the log.'),
    'csrf_failed' => $t('โทเคนความปลอดภัยหมดอายุ กรุณาโหลดหน้าใหม่', 'The security token expired. Reload the page.'),
    'session_expired' => $t('เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่', 'The session expired. Sign in again.'),
    'key_not_found' => $t('ไม่พบคีย์หรือรายการคีย์นี้ในระบบ', 'The key or saved key record could not be found.'),
    'storage_repaired' => $t('ตรวจและซ่อมตาราง Logs สำเร็จแล้ว', 'The log storage was checked and repaired.'),
    'storage_repair_failed' => $t('ซ่อมตาราง Logs ไม่สำเร็จ เปิดรายงาน SQL ด้านล่างเพื่อตรวจสาเหตุ', 'The log storage repair failed. Inspect the SQL report below.'),
    'search_index_rebuilt' => $t('สร้างดัชนีค้นหาคีย์แล้ว', 'The key search index was rebuilt.'),
    'search_index_rebuild_failed' => $t('สร้างดัชนีค้นหาคีย์ไม่สำเร็จ เปิดรายงาน SQL ด้านล่าง', 'The key search index rebuild failed. Inspect the SQL report below.'),
];

$providers = keyResetProviders();
$logsReady = xchetosEnsureResetTable();
if ($logsReady) xchetosGetKeyVaultSecret(true);
$system = xchetosSystemStatus();
$policy = keyResetGetPolicy('xchetos');
$stats = $logsReady ? keyResetGetAdminStats() : [
    'total_24h' => 0,
    'success_24h' => 0,
    'failed_24h' => 0,
    'unknown_24h' => 0,
    'processing' => 0,
    'reseller_reset_calls_24h' => 0,
    'unique_keys_24h' => 0,
];
$quotaRows = $logsReady ? keyResetGetAdminQuotaRows(50) : [];

// Read-only filters use GET so search is immediate, bookmarkable, and the
// browser Back button behaves normally. Remove stale session filters from old versions.
unset($_SESSION['key_reset_log_filters']);
$allowedLogOperations = ['reset_owned', 'reset_admin', 'diagnostic_login', 'clear_token', 'settings_update'];
$statusInput = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string) $_GET['status']) : '';
$operationInput = isset($_GET['operation']) && is_scalar($_GET['operation']) ? trim((string) $_GET['operation']) : '';
$searchFilter = isset($_GET['q']) && is_scalar($_GET['q']) ? substr(trim((string) $_GET['q']), 0, 255) : '';
$statusFilter = in_array($statusInput, ['processing', 'success', 'failed', 'unknown'], true) ? $statusInput : '';
$operationFilter = in_array($operationInput, $allowedLogOperations, true) ? $operationInput : '';
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$hasActiveLogFilters = $searchFilter !== '' || $statusFilter !== '' || $operationFilter !== '';
$logData = $logsReady
    ? keyResetGetAdminLogs(['status' => $statusFilter, 'operation' => $operationFilter, 'search' => $searchFilter], $page, 50)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 1];

$storageDiagnostics = keyResetGetStorageDiagnostics(false);
$systemDebugLogs = keyResetGetSystemDebugLogs(40);
$diagnosticPayload = [
    'current' => $storageDiagnostics,
    'last_repair' => $diagnosticReport,
    'last_index_rebuild' => $indexReport,
    'search' => (array) ($logData['search_debug'] ?? []),
];
$diagnosticJson = json_encode($diagnosticPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($diagnosticJson)) $diagnosticJson = '{}';

$operationLabels = [
    'reset_owned' => $t('ตัวแทนรีเซ็ตคีย์ลูกค้า', 'Reseller customer-key reset'),
    'reset_admin' => $t('แอดมินรีเซ็ตด้วยคีย์ตรง', 'Administrator manual reset'),
    'diagnostic_login' => $t('ทดสอบผู้ให้บริการ', 'Provider diagnostic'),
    'clear_token' => $t('ล้าง Token cache', 'Clear token cache'),
    'settings_update' => $t('แก้ไขขีดจำกัด', 'Policy update'),
];

$buildPageUrl = static function (int $targetPage) use ($searchFilter, $statusFilter, $operationFilter): string {
    $query = ['page' => max(1, $targetPage)];
    if ($searchFilter !== '') $query['q'] = $searchFilter;
    if ($statusFilter !== '') $query['status'] = $statusFilter;
    if ($operationFilter !== '') $query['operation'] = $operationFilter;
    return 'key_resets.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . '#reset-logs';
};
?>
<!DOCTYPE html>
<html lang="<?php echo $isThai ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $e($t('ระบบจัดการรีเซ็ตคีย์', 'Key Reset Management')); ?> - Admin</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#8b5cf6;--sakazuki-accent-rgb:139 92 246}</style>
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}
        .mono-wrap{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow-wrap:anywhere;word-break:break-word}
        details summary::-webkit-details-marker{display:none}
        .reset-toast{animation:resetToastIn .15s cubic-bezier(.22,1,.36,1) both;box-shadow:0 22px 70px rgba(0,0,0,.42)}
        .reset-toast.is-leaving{animation:resetToastOut .15s ease both}
        .reset-toast-progress{transform-origin:left;animation:resetToastProgress 10s linear both}
        @keyframes resetToastIn{from{opacity:0;transform:translate3d(24px,-8px,0) scale(.97)}to{opacity:1;transform:translate3d(0,0,0) scale(1)}}
        @keyframes resetToastOut{to{opacity:0;transform:translate3d(18px,-6px,0) scale(.97)}}
        @keyframes resetToastProgress{from{transform:scaleX(1)}to{transform:scaleX(0)}}
    </style>
</head>
<body class="key-reset-admin-page min-h-screen bg-darkbg text-gray-100">
<?php include __DIR__ . '/nav.php'; ?>
<main class="space-y-6 p-4 md:p-6">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white"><i class="bi bi-arrow-counterclockwise mr-2 text-orange-300"></i><?php echo $e($t('ระบบจัดการรีเซ็ตคีย์', 'Key Reset Management')); ?></h1>
            <p class="mt-1 text-sm text-gray-400"><?php echo $e($t('แยกส่วนผู้ให้บริการออกจากหน้าเว็บแล้ว เพื่อเพิ่ม API รีเซ็ตสินค้าอื่นภายหลังโดยไม่ต้องรื้อระบบเดิม', 'Provider adapters are separated from the UI so more reset APIs can be added without rebuilding the existing workflow.')); ?></p>
        </div>
        <div class="rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-xs text-gray-400">
            PHP <?php echo $e($system['php_version'] ?? '-'); ?> · cURL <?php echo $e($system['curl_version'] ?: '-'); ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php
        $flashCode = (string) ($flash['code'] ?? 'invalid_request');
        $flashStatus = (string) ($flash['status'] ?? 'failed');
        $flashSuccess = !empty($flash['success']);
        $flashText = $messages[$flashCode] ?? $t('ดำเนินการไม่สำเร็จ กรุณาตรวจ Logs ด้วยรหัสอ้างอิง', 'The action did not complete. Inspect the logs using the reference ID.');
        $flashClass = $flashSuccess
            ? 'border-green-500/40 bg-green-950/95 text-green-100'
            : ($flashStatus === 'unknown' ? 'border-yellow-500/40 bg-yellow-950/95 text-yellow-100' : 'border-red-500/40 bg-red-950/95 text-red-100');
        $flashIcon = $flashSuccess ? 'bi-check-circle-fill' : ($flashStatus === 'unknown' ? 'bi-exclamation-triangle-fill' : 'bi-x-octagon-fill');
        $flashTitle = $flashSuccess
            ? $t('ดำเนินการสำเร็จ', 'Action completed')
            : ($flashStatus === 'unknown' ? $t('ผลยังไม่แน่ชัด', 'Result is uncertain') : $t('ดำเนินการไม่สำเร็จ', 'Action failed'));
        ?>
        <div class="pointer-events-none fixed inset-x-3 top-3 z-[120] flex justify-end md:inset-x-6 md:top-6" aria-live="assertive">
            <div id="adminResetToast" class="reset-toast pointer-events-auto relative w-full max-w-lg overflow-hidden rounded-2xl border <?php echo $flashClass; ?>" data-auto-close="<?php echo $flashSuccess ? '8000' : '0'; ?>" role="alert">
                <div class="flex items-start gap-3 p-4 pr-12">
                    <div class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10"><i class="bi <?php echo $flashIcon; ?> text-xl"></i></div>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold"><?php echo $e($flashTitle); ?></div>
                        <div class="mt-1 text-sm leading-6 opacity-95"><?php echo $e($flashText); ?></div>
                        <div class="mt-3 grid gap-1.5 text-xs opacity-80">
                            <?php if (!empty($flash['product_name'])): ?><div><span class="opacity-70"><?php echo $e($t('สินค้า: ', 'Product: ')); ?></span><?php echo $e($flash['product_name']); ?></div><?php endif; ?>
                            <?php if (!empty($flash['key_masked'])): ?><div class="mono-wrap"><span class="opacity-70"><?php echo $e($t('คีย์: ', 'Key: ')); ?></span><?php echo $e($flash['key_masked']); ?></div><?php endif; ?>
                            <?php if ((int) ($flash['key_reset_limit'] ?? 0) > 0): ?><div><?php echo $e($t('สิทธิ์คีย์: ', 'Key usage: ') . (int) ($flash['key_reset_count'] ?? 0) . ' / ' . (int) $flash['key_reset_limit'] . $t(' · เหลือ ', ' · remaining ') . (int) ($flash['key_reset_remaining'] ?? 0)); ?></div><?php endif; ?>
                            <?php if ((int) ($flash['daily_limit'] ?? 0) > 0): ?><div><?php echo $e($t('โควตา 24 ชม.: ', '24-hour quota: ') . (int) ($flash['daily_used'] ?? 0) . ' / ' . (int) $flash['daily_limit'] . $t(' · เหลือ ', ' · remaining ') . (int) ($flash['daily_remaining'] ?? 0)); ?></div><?php endif; ?>
                            <?php if (!empty($flash['daily_reset_at'])): ?><div><?php echo $e($t('คืนโควตาถัดไป: ', 'Next quota return: ') . $flash['daily_reset_at']); ?></div><?php endif; ?>
                            <?php if (!empty($flash['wait_seconds'])): ?><div><?php echo $e($t('รอประมาณ ', 'Wait about ') . (int) $flash['wait_seconds'] . $t(' วินาที', ' seconds')); ?></div><?php endif; ?>
                            <?php if (!empty($flash['request_id'])): ?><div class="mono-wrap"><span class="opacity-70">Ref: </span><?php echo $e($flash['request_id']); ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <button id="adminResetToastClose" type="button" class="absolute right-3 top-3 rounded-lg p-2 opacity-70 hover:bg-white/10 hover:opacity-100" aria-label="<?php echo $e($t('ปิดการแจ้งเตือน', 'Close notification')); ?>"><i class="bi bi-x-lg"></i></button>
                <?php if ($flashSuccess): ?><div class="reset-toast-progress h-1 bg-current opacity-45"></div><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
        <?php
        $statCards = [
            [$t('Logs 24 ชม.', 'Logs 24h'), $stats['total_24h'] ?? 0, 'bi-list-check', 'text-blue-300'],
            [$t('สำเร็จ', 'Success'), $stats['success_24h'] ?? 0, 'bi-check-circle', 'text-green-300'],
            [$t('ล้มเหลว', 'Failed'), $stats['failed_24h'] ?? 0, 'bi-x-circle', 'text-red-300'],
            [$t('ไม่แน่ชัด', 'Unknown'), $stats['unknown_24h'] ?? 0, 'bi-exclamation-triangle', 'text-yellow-300'],
            [$t('กำลังทำงาน', 'Processing'), $stats['processing'] ?? 0, 'bi-hourglass-split', 'text-cyan-300'],
            [$t('คำขอตัวแทน', 'Reseller calls'), $stats['reseller_reset_calls_24h'] ?? 0, 'bi-people', 'text-orange-300'],
            [$t('คีย์ไม่ซ้ำ', 'Unique keys'), $stats['unique_keys_24h'] ?? 0, 'bi-key', 'text-violet-300'],
        ];
        foreach ($statCards as [$label, $value, $icon, $class]): ?>
            <div class="glass rounded-xl p-4">
                <div class="text-xs text-gray-500"><?php echo $e($label); ?></div>
                <div class="mt-2 flex items-center justify-between"><span class="text-2xl font-bold text-white"><?php echo (int) $value; ?></span><i class="bi <?php echo $icon . ' ' . $class; ?> text-xl"></i></div>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="glass flex flex-wrap items-center gap-2 rounded-xl p-3 text-sm">
        <span class="mr-auto text-xs text-gray-500"><i class="bi bi-layout-sidebar-inset mr-2"></i><?php echo $e($t('จัดพื้นที่หน้าจอ', 'Page sections')); ?></span>
        <button type="button" data-section-toggle="key-reset-diagnostics" data-default-visible="0" class="rounded-lg bg-cyan-500/15 px-3 py-2 text-cyan-100 hover:bg-cyan-500/25" aria-controls="key-reset-diagnostics"><i class="bi bi-bug mr-2"></i><span data-toggle-label><?php echo $e($t('แสดง SQL Logs / Debug', 'Show SQL logs / debug')); ?></span></button>
        <button type="button" data-section-toggle="reset-logs" data-default-visible="1" class="rounded-lg bg-violet-500/15 px-3 py-2 text-violet-100 hover:bg-violet-500/25" aria-controls="reset-logs"><i class="bi bi-journal-text mr-2"></i><span data-toggle-label><?php echo $e($t('ซ่อน Logs รีเซ็ต', 'Hide reset logs')); ?></span></button>
    </section>

    <section id="key-reset-diagnostics" class="glass overflow-hidden rounded-xl">
        <div class="border-b border-white/10 p-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white"><i class="bi bi-database-gear mr-2 text-cyan-300"></i><?php echo $e($t('SQL Logs และ Debug', 'SQL Logs and Debug')); ?></h2>
                    <p class="mt-1 text-xs leading-5 text-gray-500"><?php echo $e($t('ตรวจว่าตารางถูกสร้างจริง ดูจำนวนแถว ซ่อม schema และคัดลอกรายงานได้จากหน้านี้ ไม่ต้องไล่เปิด phpMyAdmin แบบเล่นซ่อนหา', 'Verify the actual tables and rows, repair the schema, and copy a support report here.')); ?></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="key_reset_action.php#key-reset-diagnostics" data-no-page-loader="1">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="repair_logs">
                        <input type="hidden" name="provider" value="xchetos">
                        <button type="submit" class="rounded-lg bg-cyan-500/20 px-4 py-2 text-sm font-medium text-cyan-100 hover:bg-cyan-500/30"><i class="bi bi-tools mr-2"></i><?php echo $e($t('ตรวจและซ่อมตาราง', 'Check and repair tables')); ?></button>
                    </form>
                    <form method="post" action="key_reset_action.php#key-reset-diagnostics" data-no-page-loader="1">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="rebuild_search_index">
                        <input type="hidden" name="provider" value="xchetos">
                        <input type="hidden" name="batch_limit" value="1000">
                        <button type="submit" class="rounded-lg bg-violet-500/20 px-4 py-2 text-sm font-medium text-violet-100 hover:bg-violet-500/30"><i class="bi bi-search-heart mr-2"></i><?php echo $e($t('สร้างดัชนีค้นหา 1,000 คีย์', 'Index 1,000 keys')); ?></button>
                    </form>
                    <button type="button" data-copy-target="keyResetDiagnosticJson" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-gray-100 hover:bg-white/15"><i class="bi bi-copy mr-2"></i><?php echo $e($t('คัดลอกรายงาน', 'Copy report')); ?></button>
                </div>
            </div>
        </div>
        <div class="space-y-5 p-5">
            <?php
            $auditInfo = (array) ($storageDiagnostics['audit_table'] ?? []);
            $searchInfo = (array) ($storageDiagnostics['search_table'] ?? []);
            $debugInfo = (array) ($storageDiagnostics['debug_table'] ?? []);
            $diagnosticCards = [
                [$t('Audit Logs', 'Audit Logs'), !empty($auditInfo['exists']), (int) ($auditInfo['rows'] ?? 0) . $t(' แถว', ' rows'), 'xchetos_hwid_reset_logs'],
                [$t('ดัชนีค้นหา', 'Search Index'), !empty($searchInfo['exists']), (int) ($searchInfo['rows'] ?? 0) . $t(' tokens', ' tokens'), 'xchetos_hwid_reset_key_search'],
                [$t('System Debug', 'System Debug'), !empty($debugInfo['exists']), (int) ($debugInfo['rows'] ?? 0) . $t(' แถว', ' rows'), 'xchetos_hwid_reset_system_logs'],
                [$t('คลังคีย์เข้ารหัส', 'Encrypted Key Vault'), !empty($storageDiagnostics['vault_ready']), !empty($storageDiagnostics['vault_ready']) ? $t('พร้อม', 'Ready') : $t('ไม่พร้อม', 'Unavailable'), 'AES-256-GCM'],
            ];
            ?>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <?php foreach ($diagnosticCards as [$label, $ok, $detail, $name]): ?>
                    <div class="rounded-xl border p-4 <?php echo $ok ? 'border-green-500/20 bg-green-500/10' : 'border-red-500/25 bg-red-500/10'; ?>">
                        <div class="flex items-start justify-between gap-3"><div><div class="text-sm font-medium text-white"><?php echo $e($label); ?></div><div class="mono-wrap mt-1 text-xs text-gray-500"><?php echo $e($name); ?></div></div><i class="bi <?php echo $ok ? 'bi-check-circle-fill text-green-300' : 'bi-x-octagon-fill text-red-300'; ?>"></i></div>
                        <div class="mt-3 text-sm <?php echo $ok ? 'text-green-200' : 'text-red-200'; ?>"><?php echo $e($detail); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php $lastStorageError = (array) ($storageDiagnostics['last_error'] ?? []); ?>
            <?php if ($lastStorageError !== []): ?>
                <div class="rounded-xl border border-yellow-500/25 bg-yellow-500/10 p-4 text-yellow-100">
                    <div class="font-medium"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $e($t('ข้อผิดพลาด SQL ล่าสุด', 'Latest SQL error')); ?></div>
                    <div class="mono-wrap mt-2 text-xs leading-6"><?php echo $e((string) ($lastStorageError['code'] ?? 'unknown') . ' · errno ' . (int) ($lastStorageError['errno'] ?? 0) . ' · ' . (string) ($lastStorageError['message'] ?? '')); ?></div>
                    <div class="mt-2 text-xs text-yellow-200/70"><?php echo $e($t('ถ้าเว็บไม่มีสิทธิ์ CREATE/ALTER ให้นำไฟล์ private/key_reset_logs_schema.sql ใน ZIP ไป Import ผ่าน phpMyAdmin หนึ่งครั้ง', 'If the web database user lacks CREATE/ALTER privileges, import private/key_reset_logs_schema.sql once in phpMyAdmin.')); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($indexReport): ?>
                <div class="rounded-xl border border-violet-500/25 bg-violet-500/10 p-4 text-sm text-violet-100">
                    <?php echo $e($t('ผลสร้างดัชนี: ประมวลผล ', 'Index result: processed ') . (int) ($indexReport['processed'] ?? 0) . $t(' · อัปเดต ', ' · updated ') . (int) ($indexReport['updated'] ?? 0) . (!empty($indexReport['pending']) ? $t(' · ยังมีรายการค้าง', ' · more rows remain') : $t(' · เสร็จแล้ว', ' · complete'))); ?>
                    <?php if (!empty($indexReport['error'])): ?><div class="mono-wrap mt-1 text-xs text-red-200"><?php echo $e($indexReport['error']); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-xl bg-black/30 p-4">
                    <div class="mb-3 flex items-center justify-between gap-3"><div class="text-sm font-medium text-white"><?php echo $e($t('รายงานรวมสำหรับคัดลอก', 'Copyable diagnostic report')); ?></div><span class="text-xs text-gray-500"><?php echo $e($storageDiagnostics['checked_at'] ?? '-'); ?></span></div>
                    <pre id="keyResetDiagnosticJson" class="mono-wrap max-h-96 overflow-auto whitespace-pre-wrap text-xs leading-6 text-gray-300"><?php echo $e($diagnosticJson); ?></pre>
                </div>
                <div class="rounded-xl bg-black/30 p-4">
                    <div class="mb-3 text-sm font-medium text-white"><?php echo $e($t('System Debug ล่าสุด', 'Recent System Debug')); ?></div>
                    <?php if (empty($systemDebugLogs)): ?>
                        <div class="py-8 text-center text-sm text-gray-500"><?php echo $e($t('ยังไม่มี Debug row หรือยังสร้างตารางไม่ได้', 'No debug rows yet, or the debug table is unavailable.')); ?></div>
                    <?php else: ?>
                        <div class="max-h-96 space-y-2 overflow-auto pr-1">
                            <?php foreach ($systemDebugLogs as $debugRow): ?>
                                <?php
                                $debugCopy = json_encode($debugRow, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                                if (!is_string($debugCopy)) $debugCopy = '{}';
                                $debugId = 'system-debug-' . (int) ($debugRow['id'] ?? 0);
                                ?>
                                <div class="rounded-lg border border-white/10 bg-white/5 p-3">
                                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="mono-wrap text-xs text-cyan-200"><?php echo $e(($debugRow['event_code'] ?? '-') . ' · ' . ($debugRow['stage'] ?? '-')); ?></div><div class="mt-1 text-xs text-gray-500"><?php echo $e(($debugRow['created_at'] ?? '-') . ' · ' . strtoupper((string) ($debugRow['level'] ?? 'info'))); ?></div></div><button type="button" data-copy-target="<?php echo $e($debugId); ?>" class="shrink-0 rounded-md bg-white/10 px-2 py-1 text-gray-300 hover:bg-white/15" title="Copy"><i class="bi bi-copy"></i></button></div>
                                    <pre id="<?php echo $e($debugId); ?>" class="mono-wrap mt-2 max-h-36 overflow-auto whitespace-pre-wrap text-xs text-gray-400"><?php echo $e($debugCopy); ?></pre>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-3">
        <div class="glass rounded-xl p-5 xl:col-span-1">
            <h2 class="text-lg font-semibold text-white"><i class="bi bi-cloud-check mr-2 text-cyan-300"></i><?php echo $e($t('ผู้ให้บริการ', 'Provider')); ?></h2>
            <div class="mt-4 space-y-2 text-sm text-gray-300">
                <div class="flex justify-between gap-4"><span>xChetos</span><span class="rounded-full border px-2 py-1 text-xs <?php echo !empty($system['configured']) ? 'border-green-500/30 bg-green-500/10 text-green-300' : 'border-red-500/30 bg-red-500/10 text-red-300'; ?>"><?php echo $e(!empty($system['configured']) ? $t('พร้อม', 'Ready') : $t('ตั้งค่าไม่ครบ', 'Not configured')); ?></span></div>
                <div class="flex justify-between gap-4"><span><?php echo $e($t('โหมดรีเซ็ต', 'Reset mode')); ?></span><span class="text-green-300"><?php echo $e($t('ส่งตรงทันที', 'Direct')); ?></span></div>
                <div class="flex justify-between gap-4"><span><?php echo $e($t('สแกนรายการก่อนรีเซ็ต', 'Inventory pre-scan')); ?></span><span class="text-gray-400"><?php echo $e($t('ปิด', 'Disabled')); ?></span></div>
                <div class="flex justify-between gap-4"><span><?php echo $e($t('ตัวแทนรีเซ็ตคีย์', 'Reseller key access')); ?></span><span class="text-green-300"><?php echo $e($t('ได้ทุกคีย์', 'Any key')); ?></span></div>
                <?php
                $vaultClass = !empty($system['key_vault_available']) ? 'text-green-300' : 'text-red-300';
                $vaultText = !empty($system['key_vault_available'])
                    ? $t('พร้อม · เข้ารหัส AES-256-GCM', 'Ready · AES-256-GCM encrypted')
                    : (!empty($system['key_vault_locked'])
                        ? $t('ไฟล์ลับหาย · ต้องคืนจากสำรอง', 'Secret missing · restore backup')
                        : (!empty($system['key_vault_secret_invalid'])
                            ? $t('ไฟล์ลับเสียหรืออ่านไม่ได้', 'Secret file is invalid or unreadable')
                            : (empty($system['key_vault_directory_writable'])
                                ? $t('โฟลเดอร์ private เขียนไม่ได้', 'The private directory is not writable')
                                : $t('กำลังรอสร้างอัตโนมัติ', 'Waiting for automatic creation'))));
                ?>
                <div class="flex justify-between gap-4"><span><?php echo $e($t('คลังคีย์สำหรับแอดมิน', 'Administrator key vault')); ?></span><span class="<?php echo $vaultClass; ?>"><?php echo $e($vaultText); ?></span></div>
                <div class="mono-wrap border-t border-white/10 pt-2 text-xs text-gray-500"><?php echo $e(implode(', ', (array) ($system['allowed_key_prefixes'] ?? []))); ?></div>
            </div>
            <div class="mt-4 grid gap-2">
                <form method="post" action="key_reset_action.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="diagnostic_login">
                    <input type="hidden" name="provider" value="xchetos">
                    <button class="w-full rounded-lg bg-cyan-500/20 px-4 py-3 text-sm font-medium text-cyan-200 hover:bg-cyan-500/30"><i class="bi bi-activity mr-2"></i><?php echo $e($t('ทดสอบล็อกอินและรายการคีย์', 'Test login and inventory')); ?></button>
                </form>
                <form method="post" action="key_reset_action.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="clear_token">
                    <input type="hidden" name="provider" value="xchetos">
                    <button class="w-full rounded-lg bg-white/10 px-4 py-3 text-sm font-medium text-gray-200 hover:bg-white/15"><i class="bi bi-trash3 mr-2"></i><?php echo $e($t('ล้าง Token cache', 'Clear token cache')); ?></button>
                </form>
            </div>
        </div>

        <div class="glass rounded-xl p-5">
            <h2 class="text-lg font-semibold text-white"><i class="bi bi-sliders mr-2 text-violet-300"></i><?php echo $e($t('ขีดจำกัดตัวแทน', 'Reseller Limits')); ?></h2>
            <p class="mt-2 text-sm text-gray-400"><?php echo $e($t('โควตารวมเป็นแบบเลื่อนย้อนหลัง 24 ชั่วโมง และคืนโควตาอัตโนมัติโดยไม่ต้องรีเซ็ตด้วยมือ', 'The allowance uses a rolling 24-hour window and returns automatically.')); ?></p>
            <form method="post" action="key_reset_action.php" class="mt-4 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_limits">
                <input type="hidden" name="provider" value="xchetos">
                <div>
                    <label class="mb-2 block text-sm text-gray-300" for="dailyLimit"><?php echo $e($t('จำนวนคำขอสูงสุดต่อตัวแทนใน 24 ชม.', 'Maximum calls per reseller in 24h')); ?></label>
                    <input id="dailyLimit" name="daily_limit" type="number" min="1" max="5000" required value="<?php echo (int) ($policy['daily_limit'] ?? 100); ?>" class="w-full rounded-lg border border-white/10 bg-black/30 px-4 py-3 outline-none focus:border-violet-500/60">
                </div>
                <input type="hidden" name="key_limit" value="2">
                <div>
                    <div class="mb-2 text-sm text-gray-300"><?php echo $e($t('สิทธิ์ต่อคีย์กำหนดอัตโนมัติ', 'Automatic per-key allowance')); ?></div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500">1D</div><div class="text-xl font-bold text-white">2</div></div>
                        <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500">2-3D</div><div class="text-xl font-bold text-white">4</div></div>
                        <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500">4D+</div><div class="text-xl font-bold text-white">5</div></div>
                    </div>
                </div>
                <button class="w-full rounded-lg bg-violet-500 px-4 py-3 font-medium text-white hover:bg-violet-600"><i class="bi bi-save mr-2"></i><?php echo $e($t('บันทึกขีดจำกัด', 'Save limits')); ?></button>
            </form>
        </div>

        <div class="glass rounded-xl p-5">
            <h2 class="text-lg font-semibold text-white"><i class="bi bi-key mr-2 text-orange-300"></i><?php echo $e($t('แอดมินรีเซ็ตคีย์', 'Administrator Reset')); ?></h2>
            <p class="mt-2 text-sm text-gray-400"><?php echo $e($t('สิทธิ์แอดมินไม่ถูกหักโควตาตัวแทน ระบบตรวจเฉพาะรูปแบบแล้วส่งคำขอไปยังผู้ให้บริการทันที', 'Administrator resets bypass reseller quotas. The key format is checked, then the request is sent directly.')); ?></p>
            <form method="post" action="key_reset_action.php" id="adminResetForm" class="mt-4 space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reset_manual">
                <div>
                    <label class="mb-2 block text-sm text-gray-300" for="provider"><?php echo $e($t('ผู้ให้บริการ', 'Provider')); ?></label>
                    <select id="provider" name="provider" class="w-full rounded-lg border border-white/10 bg-panel px-4 py-3">
                        <?php foreach ($providers as $provider): ?><option value="<?php echo $e($provider['code'] ?? ''); ?>"><?php echo $e($provider['label'] ?? '-'); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm text-gray-300" for="licenseKey"><?php echo $e($t('License Key', 'License Key')); ?></label>
                    <input id="licenseKey" name="license_key" type="text" required maxlength="255" autocomplete="off" autocapitalize="off" spellcheck="false" class="mono-wrap w-full rounded-lg border border-white/10 bg-black/30 px-4 py-3 outline-none focus:border-orange-500/60" placeholder="22-...">
                </div>
                <button class="w-full rounded-lg bg-orange-500 px-4 py-3 font-semibold text-white hover:bg-orange-600"><i class="bi bi-arrow-counterclockwise mr-2"></i><?php echo $e($t('รีเซ็ตคีย์', 'Reset key')); ?></button>
            </form>
        </div>
    </section>

    <section class="glass overflow-hidden rounded-xl">
        <div class="border-b border-white/10 p-5">
            <h2 class="text-lg font-semibold text-white"><i class="bi bi-speedometer mr-2 text-green-300"></i><?php echo $e($t('การใช้โควตาของตัวแทนใน 24 ชั่วโมง', 'Reseller Usage in the Last 24 Hours')); ?></h2>
            <p class="mt-1 text-xs text-gray-500"><?php echo $e($t('โควตาจะคืนทีละรายการเมื่อคำขอเก่าครบ 24 ชั่วโมง', 'Quota returns one call at a time as old requests reach 24 hours.')); ?></p>
        </div>
        <div class="overflow-x-auto p-5">
            <?php if (empty($quotaRows)): ?>
                <div class="py-8 text-center text-gray-500"><?php echo $e($t('ยังไม่มีตัวแทนใช้คำขอรีเซ็ตใน 24 ชั่วโมงล่าสุด', 'No reseller reset calls were recorded in the last 24 hours.')); ?></div>
            <?php else: ?>
                <table class="w-full min-w-[760px] text-sm">
                    <thead><tr class="border-b border-white/10 text-left text-gray-500"><th class="px-3 py-3"><?php echo $e($t('ตัวแทน', 'Reseller')); ?></th><th class="px-3 py-3"><?php echo $e($t('ใช้แล้ว', 'Used')); ?></th><th class="px-3 py-3"><?php echo $e($t('คงเหลือ', 'Remaining')); ?></th><th class="px-3 py-3"><?php echo $e($t('คืนโควตาถัดไป', 'Next quota return')); ?></th><th class="px-3 py-3"><?php echo $e($t('คำขอล่าสุด', 'Latest call')); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($quotaRows as $row): ?>
                        <tr class="border-b border-white/5"><td class="px-3 py-3 font-medium text-white"><?php echo $e($row['username'] ?? ('user#' . ($row['user_id'] ?? ''))); ?></td><td class="px-3 py-3 text-orange-200"><?php echo (int) ($row['used_count'] ?? 0); ?> / <?php echo (int) ($row['limit'] ?? 0); ?></td><td class="px-3 py-3 text-green-300"><?php echo (int) ($row['remaining'] ?? 0); ?></td><td class="px-3 py-3 text-gray-300"><?php echo $e($row['reset_at'] ?? '-'); ?></td><td class="px-3 py-3 text-gray-500"><?php echo $e($row['last_reset_at'] ?? '-'); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section id="reset-logs" class="glass scroll-mt-4 overflow-hidden rounded-xl">
        <div class="border-b border-white/10 p-5">
            <div class="space-y-4">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-white"><i class="bi bi-journal-text mr-2 text-violet-300"></i><?php echo $e($t('Logs การรีเซ็ตคีย์', 'Key Reset Logs')); ?></h2>
                        <p class="mt-1 text-xs leading-5 text-gray-500"><?php echo $e($t('ค้นหาได้จากคีย์เต็ม ส่วนใดก็ได้ของคีย์ รูปย่อแบบ หน้า****ท้าย ผู้ดำเนินการ เจ้าของคีย์ อีเมล ชื่อสินค้า Ref เลข TX และเลข Order โดยไม่เก็บคีย์เต็มเป็นข้อความธรรมดา', 'Search full keys, any fragment, PREFIX****SUFFIX, actors, key owners, email, product, reference, TX ID, or Order ID without storing plaintext keys.')); ?></p>
                    </div>
                    <div class="rounded-lg border border-blue-500/20 bg-blue-500/10 px-3 py-2 text-xs leading-5 text-blue-200"><?php echo $e($t('คีย์เต็มยังเข้ารหัส AES-256-GCM ส่วนการค้นหาบางส่วนใช้ดัชนี HMAC ที่ย้อนกลับเป็นคีย์ไม่ได้', 'Full keys remain AES-256-GCM encrypted. Partial lookup uses a one-way HMAC search index.')); ?></div>
                </div>
                <form id="logSearchForm" method="get" action="key_resets.php#reset-logs" data-no-page-loader="1" class="grid gap-2 lg:grid-cols-[minmax(260px,1.8fr)_minmax(150px,.7fr)_minmax(210px,1fr)_auto]">
                    <div class="relative">
                        <i class="bi bi-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input id="logSearchInput" type="search" name="q" value="<?php echo $e($searchFilter); ?>" maxlength="255" autocomplete="off" enterkeyhint="search" spellcheck="false" class="mono-wrap w-full rounded-lg border border-white/10 bg-black/30 py-2 pl-10 pr-10 text-sm outline-none focus:border-violet-500/50" placeholder="<?php echo $e($t('คีย์เต็ม, F95C20BE, เจ้าของ, อีเมล, Ref, TX หรือ Order', 'Full key, F95C20BE, owner, email, Ref, TX, or Order')); ?>">
                        <?php if ($searchFilter !== ''): ?>
                            <?php $clearSearchQuery = array_filter(['status' => $statusFilter, 'operation' => $operationFilter], static fn($value) => $value !== ''); ?>
                            <a href="key_resets.php<?php echo $clearSearchQuery !== [] ? '?' . $e(http_build_query($clearSearchQuery, '', '&', PHP_QUERY_RFC3986)) : ''; ?>#reset-logs" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-gray-500 hover:bg-white/10 hover:text-white" title="<?php echo $e($t('ล้างข้อความค้นหา', 'Clear search text')); ?>"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                    <select id="logStatusFilter" name="status" class="rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm"><option value=""><?php echo $e($t('ทุกสถานะ', 'All statuses')); ?></option><?php foreach (['processing','success','failed','unknown'] as $option): ?><option value="<?php echo $option; ?>" <?php echo $statusFilter === $option ? 'selected' : ''; ?>><?php echo $e(ucfirst($option)); ?></option><?php endforeach; ?></select>
                    <select id="logOperationFilter" name="operation" class="rounded-lg border border-white/10 bg-panel px-3 py-2 text-sm"><option value=""><?php echo $e($t('ทุกการทำงาน', 'All operations')); ?></option><?php foreach ($operationLabels as $value => $label): ?><option value="<?php echo $e($value); ?>" <?php echo $operationFilter === $value ? 'selected' : ''; ?>><?php echo $e($label); ?></option><?php endforeach; ?></select>
                    <div class="flex gap-2"><button id="logSearchButton" type="submit" class="flex-1 whitespace-nowrap rounded-lg bg-violet-500 px-4 py-2 text-sm font-medium text-white hover:bg-violet-600"><i class="bi bi-search mr-1"></i><?php echo $e($t('ค้นหา', 'Search')); ?></button><?php if ($hasActiveLogFilters): ?><a href="key_resets.php#reset-logs" class="whitespace-nowrap rounded-lg bg-white/10 px-4 py-2 text-center text-sm hover:bg-white/15"><?php echo $e($t('ล้างทั้งหมด', 'Clear all')); ?></a><?php endif; ?></div>
                </form>
                <div class="flex flex-col gap-2 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between">
                    <div><i class="bi bi-lightning-charge mr-1 text-yellow-300"></i><?php echo $e($t('กดปุ่มค้นหาหรือ Enter · เปลี่ยนสถานะหรือประเภทแล้วระบบค้นหาให้ทันที · กด / เพื่อโฟกัสช่องค้นหา', 'Press Search or Enter. Status/operation changes apply immediately. Press / to focus search.')); ?></div>
                    <div class="text-gray-400"><?php echo $e($hasActiveLogFilters ? $t('พบ ', 'Found ') . (int) ($logData['total'] ?? 0) . $t(' รายการ', ' records') : $t('ทั้งหมด ', 'Total ') . (int) ($logData['total'] ?? 0) . $t(' รายการ', ' records')); ?></div>
                </div>
            </div>
        </div>
        <div class="space-y-3 p-5">
            <?php if (!$logsReady): ?>
                <div class="rounded-lg border border-red-500/30 bg-red-500/10 p-4 text-red-200"><?php echo $e($t('ไม่สามารถสร้างหรืออัปเกรดตาราง Logs ได้ ตรวจสิทธิ์ CREATE/ALTER และ MySQL error log', 'The log table could not be created or upgraded. Check CREATE/ALTER privileges and the MySQL error log.')); ?></div>
            <?php elseif (!empty($logData['error'])): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-red-100" role="alert">
                    <div class="flex items-start gap-3"><i class="bi bi-database-exclamation mt-0.5 text-red-300"></i><div><div class="font-medium"><?php echo $e($t('ระบบค้นหา Logs ทำงานไม่สำเร็จ', 'The log search could not be completed.')); ?></div><div class="mt-1 text-sm text-red-200/80"><?php echo $e($t('ระบบไม่แสดงผลลัพธ์ว่างปลอมอีกต่อไป กรุณาใช้รหัสนี้ตรวจ PHP/MySQL error log: ', 'An empty result is no longer shown as if it were valid. Inspect the PHP/MySQL error log using this code: ') . (string) $logData['error']); ?></div></div></div>
                </div>
            <?php elseif (empty($logData['rows'])): ?>
                <div class="py-10 text-center text-gray-500"><?php echo $e($searchFilter !== '' ? $t('ไม่พบคีย์ ชื่อสินค้า ผู้ใช้ หรือ Ref ที่ตรงกับคำค้นหา', 'No key, product, user, or reference matches the search.') : $t('ไม่พบ Logs ที่ตรงกับตัวกรอง', 'No logs match the current filters.')); ?></div>
            <?php else: ?>
                <?php foreach ($logData['rows'] as $row): ?>
                    <?php
                    $status = (string) ($row['status'] ?? 'failed');
                    $badge = $status === 'success' ? 'border-green-500/30 bg-green-500/10 text-green-300'
                        : ($status === 'unknown' ? 'border-yellow-500/30 bg-yellow-500/10 text-yellow-200'
                        : ($status === 'processing' ? 'border-cyan-500/30 bg-cyan-500/10 text-cyan-200' : 'border-red-500/30 bg-red-500/10 text-red-200'));
                    $fullKey = trim((string) ($row['full_key'] ?? ''));
                    $fullKeySource = (string) ($row['full_key_source'] ?? 'unavailable');
                    $displayKey = $fullKey !== '' ? $fullKey : (string) ($row['key_masked'] ?? '-');
                    $keySourceLabel = $fullKeySource === 'encrypted_log'
                        ? $t('คีย์เต็มจากประวัติรีเซ็ตที่เข้ารหัส', 'Full key from encrypted reset history')
                        : ($fullKeySource === 'shop_database'
                            ? $t('คีย์เต็มจากฐานข้อมูลร้าน', 'Full key from shop database')
                            : $t('คีย์แบบปิดบางส่วน · ประวัติเก่าไม่สามารถย้อนคืนได้', 'Masked key · legacy full key unavailable'));
                    $debugRaw = (string) ($row['debug_json'] ?? '');
                    $debugData = $debugRaw !== '' ? json_decode($debugRaw, true) : null;
                    $debugPretty = is_array($debugData) ? json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $debugRaw;
                    $debugPretty = is_string($debugPretty) ? $debugPretty : '';
                    $actor = trim((string) ($row['actor_username'] ?? '')) ?: 'user#' . (string) ($row['user_id'] ?? '-');
                    $target = trim((string) ($row['target_username'] ?? '')) ?: ((int) ($row['target_user_id'] ?? 0) > 0 ? 'user#' . (string) $row['target_user_id'] : '-');
                    $owner = trim((string) ($row['owner_display'] ?? '')) ?: $target;
                    $ownerEmail = trim((string) ($row['owner_email'] ?? ''));
                    $ownerRole = trim((string) ($row['owner_role'] ?? ''));
                    $ownerSource = trim((string) ($row['owner_source'] ?? ''));
                    $ownerId = max(0, (int) ($row['owner_user_id'] ?? 0));
                    $productDisplayName = trim((string) ($row['product_display_name'] ?? '')) ?: trim((string) ($row['product_name'] ?? ''));
                    $relatedTx = max(0, (int) ($row['related_transaction_id'] ?? 0));
                    $relatedOrder = max(0, (int) ($row['related_order_id'] ?? 0));
                    $purchaseCreatedAt = trim((string) ($row['purchase_created_at'] ?? ''));
                    $auditCopyPayload = $row;
                    unset($auditCopyPayload['full_key'], $auditCopyPayload['key_ciphertext']);
                    $auditCopyPayload['display_key'] = (string) ($row['key_masked'] ?? '-');
                    $auditCopyPayload['actor_display'] = $actor;
                    $auditCopyPayload['target_display'] = $target;
                    $auditCopyPayload['owner_display'] = $owner;
                    $auditCopyPayload['product_display_name'] = $productDisplayName;
                    $auditCopyJson = json_encode($auditCopyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    if (!is_string($auditCopyJson)) $auditCopyJson = '{}';
                    $auditCopyId = 'audit-log-' . (int) ($row['id'] ?? 0);
                    ?>
                    <details class="rounded-xl border border-white/10 bg-black/20">
                        <summary class="cursor-pointer list-none p-4">
                            <div class="grid gap-4 xl:grid-cols-[1fr_1.6fr_1fr_1fr_auto] xl:items-center">
                                <div><div class="text-xs text-gray-500"><?php echo $e($t('ผู้ดำเนินการ', 'Actor')); ?></div><div class="mt-1 text-sm text-white"><?php echo $e($actor); ?> <span class="text-gray-500">· <?php echo $e($row['actor_role'] ?? '-'); ?></span></div><div class="text-xs text-gray-500"><?php echo $e($t('เจ้าของคีย์: ', 'Key owner: ') . $owner); ?><?php if ($ownerRole !== ''): ?> <span class="text-cyan-300">· <?php echo $e($ownerRole); ?></span><?php endif; ?></div></div>
                                <div><div class="text-xs text-gray-500"><?php echo $e($keySourceLabel); ?></div><div class="mt-1 flex items-start gap-2"><code class="mono-wrap flex-1 text-sm <?php echo $fullKey !== '' ? 'text-green-300' : 'text-gray-300'; ?>"><?php echo $e($displayKey); ?></code><?php if ($fullKey !== ''): ?><button type="button" data-copy-value="<?php echo $e($fullKey); ?>" class="shrink-0 rounded-md bg-green-500/15 px-2 py-1 text-green-300 hover:bg-green-500/25" title="Copy"><i class="bi bi-clipboard"></i></button><?php endif; ?></div><div class="mt-1 text-xs text-gray-500"><?php echo $e($t('ใช้สิทธิ์คีย์นี้ ', 'Key usage ') . (int) ($row['key_reset_count'] ?? 0) . ' / ' . (int) ($row['key_reset_limit'] ?? 2)); ?></div></div>
                                <div><div class="text-xs text-gray-500"><?php echo $e($t('การทำงาน / ผู้ให้บริการ', 'Operation / Provider')); ?></div><div class="mt-1 text-sm text-gray-200"><?php echo $e($operationLabels[$row['operation_type']] ?? $row['operation_type'] ?? '-'); ?></div><div class="text-xs text-gray-500"><?php echo $e(keyResetProviderLabel((string) ($row['provider_code'] ?? 'xchetos'))); ?></div></div>
                                <div><div class="text-xs text-gray-500"><?php echo $e($t('ผล / เวลา', 'Result / Time')); ?></div><div class="mono-wrap mt-1 text-sm text-gray-200"><?php echo $e(($row['result_code'] ?? '-') . ' · ' . ($row['request_stage'] ?? '-')); ?></div><div class="text-xs text-gray-500"><?php echo $e($row['created_at'] ?? '-'); ?></div></div>
                                <span class="justify-self-start rounded-full border px-3 py-1 text-xs font-medium <?php echo $badge; ?>"><?php echo $e(strtoupper($status)); ?></span>
                            </div>
                        </summary>
                        <div class="space-y-4 border-t border-white/10 p-4">
                            <div class="flex justify-end"><button type="button" data-copy-target="<?php echo $e($auditCopyId); ?>" class="rounded-lg bg-white/10 px-3 py-2 text-xs font-medium text-gray-200 hover:bg-white/15"><i class="bi bi-copy mr-2"></i><?php echo $e($t('คัดลอก Log นี้', 'Copy this log')); ?></button></div>
                            <pre id="<?php echo $e($auditCopyId); ?>" class="hidden"><?php echo $e($auditCopyJson); ?></pre>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5 text-sm">
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('รหัสอ้างอิง', 'Reference')); ?></div><div class="mt-1 flex items-start gap-2"><div class="mono-wrap flex-1 text-violet-200"><?php echo $e($row['request_id'] ?? '-'); ?></div><?php if (!empty($row['request_id'])): ?><button type="button" data-copy-value="<?php echo $e($row['request_id']); ?>" class="shrink-0 rounded-md bg-violet-500/15 px-2 py-1 text-violet-200 hover:bg-violet-500/25" title="Copy Ref"><i class="bi bi-clipboard"></i></button><?php endif; ?></div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('เจ้าของคีย์', 'Key owner')); ?></div><div class="mt-1 font-medium text-cyan-100"><?php echo $e($owner); ?></div><div class="mono-wrap mt-1 text-xs text-gray-500"><?php echo $e(($ownerEmail !== '' ? $ownerEmail : '-') . ($ownerId > 0 ? ' · user#' . $ownerId : '') . ($ownerSource !== '' ? ' · ' . $ownerSource : '')); ?></div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('สินค้า', 'Product')); ?></div><div class="mt-1"><?php echo $e($productDisplayName !== '' ? $productDisplayName : '-'); ?></div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('แหล่ง / รายการซื้อ', 'Source / purchase')); ?></div><div class="mono-wrap mt-1"><?php echo $e(($row['key_source'] ?? '-') . ' / ' . ($row['key_record_id'] ?? '-')); ?></div><div class="mono-wrap mt-1 flex flex-wrap gap-2 text-xs text-gray-500"><?php if ($relatedTx > 0): ?><a class="text-blue-300 hover:underline" href="transactions.php?search=<?php echo $relatedTx; ?>">TX #<?php echo $relatedTx; ?></a><?php else: ?><span>TX -</span><?php endif; ?><span>·</span><?php if ($relatedOrder > 0): ?><a class="text-violet-300 hover:underline" href="transactions.php?search=<?php echo $relatedOrder; ?>">Order #<?php echo $relatedOrder; ?></a><?php else: ?><span>Order -</span><?php endif; ?><?php if (in_array($ownerSource, ['supplier','store_api','store_api_client'], true)): ?><span>·</span><a class="text-emerald-300 hover:underline" href="api_hub.php"><?php echo $e($t('เปิด API Hub', 'Open API Hub')); ?></a><?php endif; ?></div><?php if ($purchaseCreatedAt !== ''): ?><div class="mt-1 text-xs text-gray-500"><?php echo $e($purchaseCreatedAt); ?></div><?php endif; ?></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('อายุคีย์ / สิทธิ์', 'Key duration / allowance')); ?></div><div class="mono-wrap mt-1"><?php echo (int) ($row['key_duration_days'] ?? 0) > 0 ? (int) $row['key_duration_days'] . 'D' : '-'; ?> · <?php echo (int) ($row['key_reset_limit'] ?? 2); ?> <?php echo $e($t('ครั้ง', 'resets')); ?></div></div>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 text-sm">
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500">Endpoint</div><div class="mono-wrap mt-1"><?php echo $e(($row['http_method'] ?? '-') . ' ' . ($row['endpoint'] ?? '-')); ?></div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500">HTTP / Token retry</div><div class="mt-1"><?php echo $e($row['provider_http_status'] ?? '-'); ?> · <?php echo !empty($row['token_retry']) ? $e($t('ล็อกอินใหม่', 'Re-authenticated')) : $e($t('ไม่ Retry', 'No retry')); ?></div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('เครือข่าย', 'Network')); ?></div><div class="mono-wrap mt-1"><?php echo $e(($row['transport_code'] ?? '-') . ' · cURL ' . ($row['curl_errno'] ?? '0')); ?></div><div class="text-xs text-gray-500"><?php echo (int) ($row['connect_ms'] ?? 0); ?>ms / <?php echo (int) ($row['duration_ms'] ?? 0); ?>ms</div></div>
                                <div class="rounded-lg bg-white/5 p-3"><div class="text-xs text-gray-500"><?php echo $e($t('IP ปลายทาง / ผู้ขอ', 'Provider / Request IP')); ?></div><div class="mono-wrap mt-1"><?php echo $e(($row['primary_ip'] ?? '-') . ' / ' . ($row['request_ip'] ?? '-')); ?></div></div>
                            </div>
                            <?php if (!empty($row['provider_message'])): ?><div class="rounded-lg border border-orange-500/20 bg-orange-500/10 p-3"><div class="text-xs text-orange-300"><?php echo $e($t('ข้อความผู้ให้บริการที่ตัดข้อมูลลับแล้ว', 'Sanitized provider message')); ?></div><div class="mono-wrap mt-1 text-sm text-orange-100"><?php echo $e($row['provider_message']); ?></div></div><?php endif; ?>
                            <?php if (!empty($row['curl_error'])): ?><div class="rounded-lg border border-red-500/20 bg-red-500/10 p-3"><div class="text-xs text-red-300">cURL error</div><div class="mono-wrap mt-1 text-sm text-red-100"><?php echo $e($row['curl_error']); ?></div></div><?php endif; ?>
                            <div class="grid gap-3 lg:grid-cols-2">
                                <div class="rounded-lg bg-black/30 p-3"><div class="mb-2 text-xs text-gray-500"><?php echo $e($t('Debug JSON ที่ตัดข้อมูลลับแล้ว', 'Sanitized debug JSON')); ?></div><pre class="mono-wrap max-h-80 overflow-auto whitespace-pre-wrap text-xs text-gray-300"><?php echo $e($debugPretty !== '' ? $debugPretty : '{}'); ?></pre></div>
                                <div class="space-y-2 rounded-lg bg-black/30 p-3 text-xs text-gray-400"><div><span class="text-gray-500">User-Agent:</span> <span class="mono-wrap"><?php echo $e($row['user_agent'] ?? '-'); ?></span></div><div><span class="text-gray-500">Completed:</span> <?php echo $e($row['completed_at'] ?? '-'); ?></div><div><span class="text-gray-500">Provider attempted:</span> <?php echo !empty($row['provider_attempted']) ? '1' : '0'; ?></div><div><span class="text-gray-500">Key SHA-256:</span> <span class="mono-wrap"><?php echo $e($row['key_hash'] ?? '-'); ?></span></div></div>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>

                <?php if ((int) ($logData['pages'] ?? 1) > 1): ?>
                    <div class="flex items-center justify-between pt-3 text-sm"><div class="text-gray-500"><?php echo $e($t('ทั้งหมด ', 'Total ') . (int) ($logData['total'] ?? 0) . $t(' รายการ', ' records')); ?></div><div class="flex gap-2"><?php if ((int) $logData['page'] > 1): ?><a class="rounded-lg bg-white/10 px-4 py-2 hover:bg-white/15" href="<?php echo $e($buildPageUrl((int) $logData['page'] - 1)); ?>"><?php echo $e($t('ก่อนหน้า', 'Previous')); ?></a><?php endif; ?><span class="rounded-lg bg-violet-500/20 px-4 py-2 text-violet-200"><?php echo (int) $logData['page']; ?> / <?php echo (int) $logData['pages']; ?></span><?php if ((int) $logData['page'] < (int) $logData['pages']): ?><a class="rounded-lg bg-white/10 px-4 py-2 hover:bg-white/15" href="<?php echo $e($buildPageUrl((int) $logData['page'] + 1)); ?>"><?php echo $e($t('ถัดไป', 'Next')); ?></a><?php endif; ?></div></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<script>
const adminResetToast = document.getElementById('adminResetToast');
const closeAdminResetToast = () => {
    if (!adminResetToast || adminResetToast.classList.contains('is-leaving')) return;
    adminResetToast.classList.add('is-leaving');
    window.setTimeout(() => adminResetToast.remove(), 240);
};
document.getElementById('adminResetToastClose')?.addEventListener('click', closeAdminResetToast);
if (adminResetToast) {
    const autoClose = Number(adminResetToast.dataset.autoClose || 0);
    if (autoClose > 0) window.setTimeout(closeAdminResetToast, autoClose);
}

const resetForm = document.getElementById('adminResetForm');
if (resetForm) resetForm.addEventListener('submit', (event) => {
    const key = document.getElementById('licenseKey')?.value?.trim() || '';
    const message = <?php echo json_encode($t('ยืนยันส่งคำขอรีเซ็ตจริงสำหรับคีย์นี้หรือไม่?', 'Send a real reset request for this key?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (!key || !window.confirm(message)) { event.preventDefault(); return; }
    const button = resetForm.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
});

async function copyAdminText(value) {
    const text = String(value || '');
    if (!text) return false;
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch (_) {}
    try {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const copied = document.execCommand('copy');
        area.remove();
        return copied;
    } catch (_) {
        return false;
    }
}

document.querySelectorAll('[data-copy-value], [data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const targetId = button.dataset.copyTarget || '';
        const target = targetId ? document.getElementById(targetId) : null;
        const value = button.dataset.copyValue || target?.textContent || '';
        const old = button.innerHTML;
        const copied = await copyAdminText(value);
        button.innerHTML = copied ? '<i class="bi bi-check2"></i>' : '<i class="bi bi-x-lg"></i>';
        window.setTimeout(() => { button.innerHTML = old; }, 1100);
    });
});

const sectionToggleLabels = {
    showDiagnostics: <?php echo json_encode($t('แสดง SQL Logs / Debug', 'Show SQL logs / debug'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    hideDiagnostics: <?php echo json_encode($t('ซ่อน SQL Logs / Debug', 'Hide SQL logs / debug'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    showLogs: <?php echo json_encode($t('แสดง Logs รีเซ็ต', 'Show reset logs'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    hideLogs: <?php echo json_encode($t('ซ่อน Logs รีเซ็ต', 'Hide reset logs'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
};

function setKeyResetSectionVisible(sectionId, visible, persist = true) {
    const section = document.getElementById(sectionId);
    const button = document.querySelector(`[data-section-toggle="${sectionId}"]`);
    if (!section || !button) return;
    section.classList.toggle('hidden', !visible);
    button.setAttribute('aria-expanded', visible ? 'true' : 'false');
    const label = button.querySelector('[data-toggle-label]');
    if (label) {
        label.textContent = sectionId === 'reset-logs'
            ? (visible ? sectionToggleLabels.hideLogs : sectionToggleLabels.showLogs)
            : (visible ? sectionToggleLabels.hideDiagnostics : sectionToggleLabels.showDiagnostics);
    }
    const icon = button.querySelector('i');
    if (icon) icon.classList.toggle('opacity-60', !visible);
    if (persist) {
        try { localStorage.setItem(`sakazuki:${sectionId}:visible`, visible ? '1' : '0'); } catch (_) {}
    }
}

document.querySelectorAll('[data-section-toggle]').forEach((button) => {
    const sectionId = button.dataset.sectionToggle || '';
    let visible = button.dataset.defaultVisible !== '0';
    try {
        const saved = localStorage.getItem(`sakazuki:${sectionId}:visible`);
        if (saved === '0' || saved === '1') visible = saved === '1';
    } catch (_) {}
    if (sectionId === 'reset-logs' && (location.hash === '#reset-logs' || <?php echo $hasActiveLogFilters || !empty($logData['error']) ? 'true' : 'false'; ?>)) visible = true;
    if (sectionId === 'key-reset-diagnostics' && <?php echo ($diagnosticReport || $indexReport || !empty($storageDiagnostics['last_error'])) ? 'true' : 'false'; ?>) visible = true;
    setKeyResetSectionVisible(sectionId, visible, false);
    button.addEventListener('click', () => {
        const section = document.getElementById(sectionId);
        setKeyResetSectionVisible(sectionId, section?.classList.contains('hidden') ?? true, true);
    });
});

const logSearchForm = document.getElementById('logSearchForm');
const logSearchInput = document.getElementById('logSearchInput');
const logSearchButton = document.getElementById('logSearchButton');

['logStatusFilter', 'logOperationFilter'].forEach((id) => {
    document.getElementById(id)?.addEventListener('change', () => {
        logSearchForm?.requestSubmit();
    });
});

logSearchForm?.addEventListener('submit', () => {
    if (logSearchButton) {
        logSearchButton.disabled = true;
        logSearchButton.classList.add('opacity-60');
        logSearchButton.setAttribute('aria-busy', 'true');
        const label = logSearchButton.lastChild;
        if (label?.nodeType === Node.TEXT_NODE) label.textContent = <?php echo json_encode($t(' กำลังค้นหา...', ' Searching...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    }
});

// Storage repair/index forms are explicit heavy operations. Disable only their
// own button, never cover the entire page with a blur overlay.
document.querySelectorAll('form[data-no-page-loader="1"][method="post"]').forEach((formNode) => {
    formNode.addEventListener('submit', () => {
        const button = formNode.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.classList.add('opacity-60');
            button.setAttribute('aria-busy', 'true');
        }
    });
});

document.addEventListener('keydown', (event) => {
    const target = event.target;
    const typing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable;
    if (event.key === '/' && !typing) {
        event.preventDefault();
        logSearchInput?.focus();
        logSearchInput?.select();
    } else if (event.key === 'Escape' && document.activeElement === logSearchInput && logSearchInput) {
        event.preventDefault();
        logSearchInput.value = '';
        logSearchForm?.requestSubmit();
    }
});
</script>
</body>
</html>
