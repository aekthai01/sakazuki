<?php
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/ranking.php';
require_once '../includes/binance_giftcard.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

global $conn;
$schemaReady = ensureBinanceGiftCardSchema();
if ($schemaReady) {
    binanceGiftCardMarkStaleRedeeming();
}

$allowedStatuses = [
    'received', 'preflight_error', 'redeeming', 'redeemed_pending_credit', 'completed', 'invalid', 'expired',
    'already_redeemed', 'unsupported_token', 'amount_out_of_range',
    'unknown_requires_review', 'provider_limit', 'configuration_error', 'rejected',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? (string) $_POST['action'] : '';
    if ($action === 'retry_credit' && $schemaReady) {
        $redemptionId = isset($_POST['redemption_id']) && is_scalar($_POST['redemption_id']) ? (int) $_POST['redemption_id'] : 0;
        $result = $redemptionId > 0
            ? creditBinanceGiftCardRedemption($redemptionId)
            : ['success' => false, 'message' => Lang::t('common.error.invalid_request')];
        $_SESSION['giftcard_admin_flash'] = [
            'success' => !empty($result['success']),
            'message' => (string) ($result['message'] ?? Lang::t('giftcard.error.unavailable')),
        ];
        header('Location: binance_giftcards.php', true, 303);
        exit;
    }
}

$flash = isset($_SESSION['giftcard_admin_flash']) && is_array($_SESSION['giftcard_admin_flash'])
    ? $_SESSION['giftcard_admin_flash']
    : null;
unset($_SESSION['giftcard_admin_flash']);

$status = isset($_GET['status']) && is_scalar($_GET['status']) ? trim((string) $_GET['status']) : '';
if (!in_array($status, $allowedStatuses, true)) $status = '';
$search = isset($_GET['search']) && is_scalar($_GET['search']) ? substr(trim((string) $_GET['search']), 0, 100) : '';
$page = isset($_GET['page']) && is_scalar($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min(100000, $page));
$perPage = 50;
$offset = ($page - 1) * $perPage;

function giftcardAdminBind(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '') return true;
    $args = [$types];
    foreach ($values as $index => &$value) $args[] = &$value;
    unset($value);
    return (bool) call_user_func_array([$stmt, 'bind_param'], $args);
}

function giftcardAdminStatusClass(string $status): string
{
    if ($status === 'completed') return 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300';
    if (in_array($status, ['received', 'redeeming', 'redeemed_pending_credit'], true)) return 'border-blue-400/30 bg-blue-400/10 text-blue-300';
    if ($status === 'preflight_error') return 'border-fuchsia-400/30 bg-fuchsia-400/10 text-fuchsia-300';
    if (in_array($status, ['unknown_requires_review', 'amount_out_of_range', 'unsupported_token'], true)) return 'border-amber-400/30 bg-amber-400/10 text-amber-300';
    return 'border-red-400/30 bg-red-400/10 text-red-300';
}

function giftcardAdminStatusLabel(string $status): string
{
    $key = 'admin.giftcard.status.' . preg_replace('/[^a-z0-9_]/', '', strtolower($status));
    $translated = Lang::t($key);
    return $translated === $key ? $status : $translated;
}

$rows = [];
$totalRows = 0;
$totalPages = 1;
$listDiagnosticCode = '';
$stats = ['total_count' => 0, 'completed_count' => 0, 'completed_usdt' => 0, 'completed_credit' => 0, 'review_count' => 0];

if ($schemaReady) {
    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($status !== '') {
        $where[] = 'g.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        // Accept copied formats such as "••••7765", "****7765", request IDs,
        // usernames, record IDs, provider codes, and the safe provider message.
        $searchNormalized = preg_replace('/^[•*\\s]+/u', '', $search);
        if (!is_string($searchNormalized) || $searchNormalized === '') $searchNormalized = $search;
        $where[] = "(u.username LIKE ? OR CAST(g.user_id AS CHAR) = ? OR CAST(g.id AS CHAR) = ?
                    OR g.request_id LIKE ? OR g.reference_no LIKE ? OR g.identity_no LIKE ?
                    OR g.code_last4 LIKE ? OR g.api_code LIKE ? OR g.provider_message_safe LIKE ?)";
        $like = '%' . $searchNormalized . '%';
        $types .= 'sssssssss';
        array_push($params, $like, $searchNormalized, $searchNormalized, $like, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $count = $conn->prepare("SELECT COUNT(*) FROM binance_giftcard_redemptions g LEFT JOIN users u ON u.id = g.user_id WHERE $whereSql");
    if ($count) {
        $countParams = $params;
        if (giftcardAdminBind($count, $types, $countParams) && $count->execute()) {
            $count->bind_result($totalRowsRaw);
            $count->fetch();
            $totalRows = (int) $totalRowsRaw;
        } else {
            $listDiagnosticCode = 'COUNT-' . (int) $count->errno;
            error_log('Gift Card admin count query failed; errno=' . (int) $count->errno);
        }
        $count->close();
    } else {
        $listDiagnosticCode = 'COUNT-PREP-' . (int) $conn->errno;
        error_log('Gift Card admin count prepare failed; errno=' . (int) $conn->errno);
    }
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // Embed only server-validated integers for LIMIT/OFFSET. This avoids a
    // compatibility issue on older MariaDB builds that reject bound LIMIT values.
    $limitSql = (int) $perPage;
    $offsetSql = (int) $offset;
    $baseSelect =
        "SELECT g.id, g.request_id, g.user_id, u.username, u.role AS account_role, u.status AS user_status,
                g.role_at_redeem, g.code_last4, g.reference_no, g.identity_no, g.token,
                g.token_amount, g.exchange_rate, g.credit_currency, g.credit_percent,
                g.count_for_ranking, g.rank_bonus_enabled, g.credit_amount,
                g.status, g.request_stage, g.api_code, g.api_message_safe, g.http_code,
                g.provider_message_safe, g.provider_requested_at, g.transaction_id,
                g.attempt_count, g.requested_at, g.redeemed_at, g.credited_at, g.updated_at";

    $querySql = $baseSelect . ", t.status AS transaction_status, t.description AS transaction_description
         FROM binance_giftcard_redemptions g
         LEFT JOIN users u ON u.id = g.user_id
         LEFT JOIN transactions t ON t.id = g.transaction_id
         WHERE $whereSql
         ORDER BY g.id DESC
         LIMIT $limitSql OFFSET $offsetSql";

    $query = $conn->prepare($querySql);
    $queryOk = false;
    if ($query) {
        $rowParams = $params;
        if (giftcardAdminBind($query, $types, $rowParams) && $query->execute()) {
            $result = $query->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $queryOk = true;
        } else {
            $listDiagnosticCode = 'LIST-' . (int) $query->errno;
            error_log('Gift Card admin list query failed; errno=' . (int) $query->errno);
        }
        $query->close();
    } else {
        $listDiagnosticCode = 'LIST-PREP-' . (int) $conn->errno;
        error_log('Gift Card admin list prepare failed; errno=' . (int) $conn->errno);
    }

    // A missing/older transactions column must never hide the Gift Card audit.
    // Fall back to the redemption and user tables, which are the source of truth.
    if (!$queryOk) {
        $fallbackSql = $baseSelect . ", NULL AS transaction_status, NULL AS transaction_description
             FROM binance_giftcard_redemptions g
             LEFT JOIN users u ON u.id = g.user_id
             WHERE $whereSql
             ORDER BY g.id DESC
             LIMIT $limitSql OFFSET $offsetSql";
        $fallback = $conn->prepare($fallbackSql);
        if ($fallback) {
            $fallbackParams = $params;
            if (giftcardAdminBind($fallback, $types, $fallbackParams) && $fallback->execute()) {
                $fallbackResult = $fallback->get_result();
                $rows = $fallbackResult ? $fallbackResult->fetch_all(MYSQLI_ASSOC) : [];
                $listDiagnosticCode = $listDiagnosticCode !== '' ? $listDiagnosticCode . '-FALLBACK' : 'FALLBACK';
            } else {
                $listDiagnosticCode .= '-FB' . (int) $fallback->errno;
                error_log('Gift Card admin fallback query failed; errno=' . (int) $fallback->errno);
            }
            $fallback->close();
        } else {
            $listDiagnosticCode .= '-FBP' . (int) $conn->errno;
            error_log('Gift Card admin fallback prepare failed; errno=' . (int) $conn->errno);
        }
    }

    $statsQuery = $conn->query(
        "SELECT COUNT(*) AS total_count,
                SUM(status = 'completed') AS completed_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN token_amount ELSE 0 END), 0) AS completed_usdt,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN credit_amount ELSE 0 END), 0) AS completed_credit,
                SUM(status IN ('received','preflight_error','redeeming','redeemed_pending_credit','unknown_requires_review','amount_out_of_range','unsupported_token','configuration_error')) AS review_count
         FROM binance_giftcard_redemptions"
    );
    if ($statsQuery && ($statsRow = $statsQuery->fetch_assoc())) $stats = array_merge($stats, $statsRow);
}

function giftcardAdminUrl(int $targetPage, string $status, string $search): string
{
    $query = ['page' => max(1, $targetPage)];
    if ($status !== '') $query['status'] = $status;
    if ($search !== '') $query['search'] = $search;
    return 'binance_giftcards.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(getAppLang(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(Lang::t('admin.giftcard.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>:root{--sakazuki-accent:#3b82f6;--sakazuki-accent-rgb:59 130 246}</style>
    <style>.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.08)}.mono-wrap{overflow-wrap:anywhere}</style>
</head>
<body class="min-h-screen bg-darkbg text-gray-100">
<?php include 'nav.php'; ?>
<main class="p-4 md:p-6 space-y-5">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-black text-white"><i class="bi bi-gift-fill mr-2 text-amber-300"></i><span data-lang="admin.giftcard.title"><?php echo htmlspecialchars(Lang::t('admin.giftcard.title'), ENT_QUOTES, 'UTF-8'); ?></span></h1>
            <p class="mt-1 text-sm text-gray-400" data-lang="admin.giftcard.subtitle"><?php echo htmlspecialchars(Lang::t('admin.giftcard.subtitle'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <a href="settings.php" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10"><i class="bi bi-gear"></i><span data-lang="nav.settings"><?php echo htmlspecialchars(Lang::t('nav.settings'), ENT_QUOTES, 'UTF-8'); ?></span></a>
    </div>

    <?php if (!$schemaReady): ?>
        <div class="rounded-xl border border-red-400/30 bg-red-400/10 p-4 text-red-200" data-lang="admin.giftcard.schema_error"><?php echo htmlspecialchars(Lang::t('admin.giftcard.schema_error'), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="rounded-xl border p-4 <?php echo !empty($flash['success']) ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200' : 'border-amber-400/30 bg-amber-400/10 text-amber-200'; ?>"><?php echo htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500" data-lang="admin.giftcard.stats.total"><?php echo Lang::t('admin.giftcard.stats.total'); ?></div><div class="mt-1 text-xl font-black"><?php echo number_format((int) $stats['total_count']); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500" data-lang="admin.giftcard.stats.completed"><?php echo Lang::t('admin.giftcard.stats.completed'); ?></div><div class="mt-1 text-xl font-black text-emerald-300"><?php echo number_format((int) $stats['completed_count']); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500" data-lang="admin.giftcard.stats.usdt"><?php echo Lang::t('admin.giftcard.stats.usdt'); ?></div><div class="mt-1 text-xl font-black text-amber-300"><?php echo number_format((float) $stats['completed_usdt'], 2); ?></div></div>
        <div class="glass rounded-xl p-4"><div class="text-xs text-gray-500" data-lang="admin.giftcard.stats.credit"><?php echo Lang::t('admin.giftcard.stats.credit'); ?></div><div class="mt-1 text-xl font-black text-blue-300"><?php echo htmlspecialchars(formatCurrency((float) $stats['completed_credit'], true), ENT_QUOTES, 'UTF-8'); ?></div></div>
        <div class="glass rounded-xl p-4 col-span-2 lg:col-span-1"><div class="text-xs text-gray-500" data-lang="admin.giftcard.stats.review"><?php echo Lang::t('admin.giftcard.stats.review'); ?></div><div class="mt-1 text-xl font-black text-orange-300"><?php echo number_format((int) $stats['review_count']); ?></div></div>
    </div>

    <div id="binanceGiftcardsLivePanel" data-instant-panel class="space-y-5">
    <div class="glass rounded-xl p-3 text-xs text-gray-400 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div><span data-lang="admin.giftcard.filtered_count"><?php echo htmlspecialchars(Lang::t('admin.giftcard.filtered_count'), ENT_QUOTES, 'UTF-8'); ?></span>: <strong class="text-white"><?php echo number_format($totalRows); ?></strong> / <?php echo number_format((int) $stats['total_count']); ?></div>
        <?php if ($search !== '' || $status !== ''): ?><a href="binance_giftcards.php" class="text-amber-300 hover:text-amber-200" data-lang="admin.giftcard.clear_filters"><?php echo htmlspecialchars(Lang::t('admin.giftcard.clear_filters'), ENT_QUOTES, 'UTF-8'); ?></a><?php endif; ?>
    </div>

    <?php if ($listDiagnosticCode !== ''): ?>
    <div class="rounded-xl border border-orange-400/30 bg-orange-400/10 p-3 text-xs text-orange-200">
        <span data-lang="admin.giftcard.list_fallback"><?php echo htmlspecialchars(Lang::t('admin.giftcard.list_fallback'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="ml-2 font-mono text-orange-100"><?php echo htmlspecialchars($listDiagnosticCode, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php endif; ?>

    <form method="GET" class="glass rounded-xl p-4 grid grid-cols-1 gap-3 md:grid-cols-[1fr_240px_auto]">
        <input name="search" maxlength="100" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-white/10 bg-black/20 px-4 py-2.5 outline-none focus:border-amber-400/50" placeholder="<?php echo htmlspecialchars(Lang::t('admin.giftcard.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
        <select name="status" class="rounded-lg border border-white/10 bg-panel px-4 py-2.5 text-white outline-none">
            <option value="" data-lang="admin.giftcard.all_statuses"><?php echo htmlspecialchars(Lang::t('admin.giftcard.all_statuses'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($allowedStatuses as $option): ?><option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $status === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars(giftcardAdminStatusLabel($option), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
        </select>
        <div class="flex gap-2"><button class="flex-1 rounded-lg bg-amber-400 px-5 py-2.5 font-bold text-black hover:bg-amber-300"><i class="bi bi-search mr-1"></i><span data-lang="common.search"><?php echo Lang::t('common.search'); ?></span></button><a href="binance_giftcards.php" class="rounded-lg border border-white/10 px-4 py-2.5 hover:bg-white/5"><i class="bi bi-x-lg"></i></a></div>
    </form>

    <div class="space-y-3">
        <?php if (!$rows): ?><div class="glass rounded-xl p-10 text-center text-gray-500"><div data-lang="admin.giftcard.empty"><?php echo htmlspecialchars(Lang::t('admin.giftcard.empty'), ENT_QUOTES, 'UTF-8'); ?></div><?php if (($search !== '' || $status !== '') && (int) $stats['total_count'] > 0): ?><a href="binance_giftcards.php" class="mt-3 inline-block text-amber-300 hover:text-amber-200" data-lang="admin.giftcard.clear_filters"><?php echo htmlspecialchars(Lang::t('admin.giftcard.clear_filters'), ENT_QUOTES, 'UTF-8'); ?></a><?php endif; ?></div><?php endif; ?>
        <?php foreach ($rows as $row):
            $rowStatus = (string) $row['status'];
            $canRetry = in_array($rowStatus, ['redeemed_pending_credit', 'amount_out_of_range'], true) && strtoupper((string) $row['token']) === 'USDT' && empty($row['transaction_id']);
        ?>
        <article class="glass rounded-2xl p-4 md:p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2"><span class="font-black text-white">#<?php echo (int) $row['id']; ?></span><span class="font-mono text-[11px] text-cyan-300"><?php echo htmlspecialchars((string) ($row['request_id'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span><span class="rounded-full border px-2 py-1 text-[11px] <?php echo giftcardAdminStatusClass($rowStatus); ?>"><?php echo htmlspecialchars(giftcardAdminStatusLabel($rowStatus), ENT_QUOTES, 'UTF-8'); ?></span><span class="text-xs text-gray-500">••••<?php echo htmlspecialchars((string) $row['code_last4'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="mt-2 font-bold text-white"><?php echo htmlspecialchars((string) ($row['username'] ?? Lang::t('admin.giftcard.missing_user')), ENT_QUOTES, 'UTF-8'); ?> <span class="text-xs font-normal text-gray-500">ID <?php echo (int) $row['user_id']; ?></span></div>
                    <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars((string) $row['role_at_redeem'], ENT_QUOTES, 'UTF-8'); ?> → <?php echo htmlspecialchars((string) ($row['account_role'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($row['user_status'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="text-left md:text-right"><div class="text-xl font-black text-amber-300"><?php echo number_format((float) $row['token_amount'], 8); ?> <?php echo htmlspecialchars((string) ($row['token'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div><div class="text-sm text-blue-300"><?php echo number_format((float) $row['credit_amount'], 2); ?> <?php echo htmlspecialchars((string) ($row['credit_currency'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-black/20 p-3"><div class="text-gray-500" data-lang="admin.giftcard.reference"><?php echo Lang::t('admin.giftcard.reference'); ?></div><div class="mono-wrap mt-1 font-mono text-gray-200"><?php echo htmlspecialchars((string) ($row['reference_no'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-black/20 p-3"><div class="text-gray-500" data-lang="admin.giftcard.identity"><?php echo Lang::t('admin.giftcard.identity'); ?></div><div class="mono-wrap mt-1 font-mono text-gray-200"><?php echo htmlspecialchars((string) ($row['identity_no'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-black/20 p-3"><div class="text-gray-500" data-lang="admin.giftcard.transaction"><?php echo Lang::t('admin.giftcard.transaction'); ?></div><div class="mt-1 text-gray-200"><?php echo $row['transaction_id'] ? '#' . (int) $row['transaction_id'] : '-'; ?> · <?php echo htmlspecialchars((string) ($row['transaction_status'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div class="rounded-xl bg-black/20 p-3"><div class="text-gray-500" data-lang="admin.giftcard.api_result"><?php echo Lang::t('admin.giftcard.api_result'); ?></div><div class="mono-wrap mt-1 text-gray-200">HTTP <?php echo (int) ($row['http_code'] ?? 0); ?> · <?php echo htmlspecialchars((string) ($row['api_code'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($row['api_message_safe'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div><?php if (!empty($row['provider_message_safe'])): ?><div class="mono-wrap mt-1 text-[11px] text-orange-200"><?php echo htmlspecialchars((string) $row['provider_message_safe'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?></div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 text-[11px] text-gray-500 md:grid-cols-5"><div><span data-lang="admin.giftcard.requested_at"><?php echo Lang::t('admin.giftcard.requested_at'); ?></span><br><?php echo htmlspecialchars((string) $row['requested_at'], ENT_QUOTES, 'UTF-8'); ?></div><div><span data-lang="admin.giftcard.stage"><?php echo Lang::t('admin.giftcard.stage'); ?></span><br><span class="text-cyan-300"><?php echo htmlspecialchars((string) ($row['request_stage'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></span><?php if (!empty($row['provider_requested_at'])): ?><br><?php echo htmlspecialchars((string) $row['provider_requested_at'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div><div><span data-lang="admin.giftcard.redeemed_at"><?php echo Lang::t('admin.giftcard.redeemed_at'); ?></span><br><?php echo htmlspecialchars((string) ($row['redeemed_at'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div><div><span data-lang="admin.giftcard.credited_at"><?php echo Lang::t('admin.giftcard.credited_at'); ?></span><br><?php echo htmlspecialchars((string) ($row['credited_at'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div><div><span data-lang="admin.giftcard.rate_percent"><?php echo Lang::t('admin.giftcard.rate_percent'); ?></span><br><?php echo number_format((float) $row['exchange_rate'], 4); ?> · <?php echo number_format((float) $row['credit_percent'], 2); ?>% · <?php echo htmlspecialchars((string) ($row['credit_currency'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div></div>
            <div class="mt-2 grid grid-cols-1 gap-2 text-[11px] text-gray-500 md:grid-cols-2">
                <div><span data-lang="admin.settings.giftcard_count_ranking"><?php echo Lang::t('admin.settings.giftcard_count_ranking'); ?></span>: <span class="text-gray-300"><?php echo Lang::t(!empty($row['count_for_ranking']) ? 'admin.settings.status_enabled' : 'admin.settings.status_disabled'); ?></span></div>
                <div><span data-lang="admin.settings.giftcard_rank_bonus"><?php echo Lang::t('admin.settings.giftcard_rank_bonus'); ?></span>: <span class="text-gray-300"><?php echo Lang::t(!empty($row['rank_bonus_enabled']) ? 'admin.settings.status_enabled' : 'admin.settings.status_disabled'); ?></span></div>
            </div>

            <?php if (!empty($row['transaction_description'])): ?><div class="mt-3 rounded-xl bg-black/20 p-3 text-xs text-gray-400"><?php echo htmlspecialchars((string) $row['transaction_description'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($canRetry): ?><form method="POST" class="mt-4" onsubmit="return confirm('<?php echo htmlspecialchars(Lang::t('admin.giftcard.retry_confirm'), ENT_QUOTES, 'UTF-8'); ?>')"><?php echo csrfField(); ?><input type="hidden" name="action" value="retry_credit"><input type="hidden" name="redemption_id" value="<?php echo (int) $row['id']; ?>"><button class="rounded-lg border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-sm font-bold text-emerald-300 hover:bg-emerald-400/20"><i class="bi bi-arrow-repeat mr-1"></i><span data-lang="admin.giftcard.retry_credit"><?php echo Lang::t('admin.giftcard.retry_credit'); ?></span></button></form><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?><nav class="flex items-center justify-between gap-3 text-sm"><a class="rounded-lg border border-white/10 px-4 py-2 <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-white/5'; ?>" href="<?php echo htmlspecialchars(giftcardAdminUrl($page - 1, $status, $search), ENT_QUOTES, 'UTF-8'); ?>">← <?php echo htmlspecialchars(Lang::t('common.previous'), ENT_QUOTES, 'UTF-8'); ?></a><span class="text-gray-400"><?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?> · <?php echo number_format($totalRows); ?></span><a class="rounded-lg border border-white/10 px-4 py-2 <?php echo $page >= $totalPages ? 'pointer-events-none opacity-40' : 'hover:bg-white/5'; ?>" href="<?php echo htmlspecialchars(giftcardAdminUrl($page + 1, $status, $search), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(Lang::t('common.next'), ENT_QUOTES, 'UTF-8'); ?> →</a></nav><?php endif; ?>
    </div>
</main>
<script src="../assets/js/lang.js"></script>
<script>
function refreshGiftcardLanguage(){if(window.Lang)Lang.updatePage();}
document.addEventListener('DOMContentLoaded',refreshGiftcardLanguage);
document.addEventListener('instantfilter:updated',refreshGiftcardLanguage);
</script>
<script src="../assets/js/instant-filter.js?v=3.0"></script>
</body>
</html>
