<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';

// DEVNOOD-compatible fallback endpoint. The normal provider endpoint remains
// /api/store/v1.php, but this reseller page can serve the same API protocol when
// called server-to-server with X-API-Key/Bearer credentials. Browser requests
// without API credentials continue to the reseller dashboard below.
$storeApiHeaderKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
$storeApiAuthorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$storeApiHasCredential = $storeApiHeaderKey !== '' || preg_match('/^Bearer\s+\S+/i', $storeApiAuthorization) === 1;
$storeApiAction = strtolower(trim((string) ($_GET['action'] ?? '')));
$storeApiMethod = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
// Once an explicit Store API credential is present, always stay on the JSON API
// path. Unknown actions/methods must return machine-readable API errors instead
// of accidentally falling through to the reseller HTML dashboard.
if ($storeApiHasCredential) {
    storeBridgeDevnoodServeApi();
}

requireReseller();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string { return $isTh ? $th : $en; };
$h = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!function_exists('resellerStoreApiFlashRedirect')) {
    function resellerStoreApiFlashRedirect(string $message = '', string $error = '', string $newKey = '', array $extra = []): void
    {
        $_SESSION['reseller_store_api_flash'] = array_merge([
            'message' => $message,
            'error' => $error,
            'new_key' => $newKey,
        ], $extra);
        header('Location: api_store.php', true, 303);
        exit;
    }
}

if (!function_exists('resellerStoreApiEncodeJson')) {
    function resellerStoreApiEncodeJson(array $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('resellerStoreApiPublicPingTest')) {
    function resellerStoreApiPublicPingTest(string $url): array
    {
        $result = [
            'success' => false,
            'http_code' => 0,
            'duration_ms' => 0,
            'curl_errno' => 0,
            'error' => '',
            'response' => null,
        ];
        if (!function_exists('curl_init')) {
            $result['error'] = 'PHP cURL extension is unavailable on the provider server';
            return $result;
        }
        $parts = @parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '') {
            $result['error'] = 'Ping endpoint is invalid';
            return $result;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            $result['error'] = 'Unable to initialize HTTP test';
            return $result;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Sakazuki-Reseller-AutoTest/1.0',
            CURLOPT_ENCODING => '',
        ]);
        $started = microtime(true);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $result['http_code'] = max(0, (int) ($info['http_code'] ?? 0));
        $result['curl_errno'] = max(0, (int) $errno);
        $result['error'] = substr(trim((string) $curlError), 0, 500);
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                $result['response'] = $decoded;
            } else {
                $result['response_preview'] = substr(trim(strip_tags($body)), 0, 1000);
            }
        }
        $result['success'] = $errno === 0
            && $result['http_code'] === 200
            && is_array($result['response'])
            && !empty($result['response']['success']);
        return $result;
    }
}

$message = '';
$error = '';
$newKey = '';
$autoTest = null;
if (isset($_SESSION['reseller_store_api_flash']) && is_array($_SESSION['reseller_store_api_flash'])) {
    $flash = $_SESSION['reseller_store_api_flash'];
    unset($_SESSION['reseller_store_api_flash']);
    $message = is_string($flash['message'] ?? null) ? $flash['message'] : '';
    $error = is_string($flash['error'] ?? null) ? $flash['error'] : '';
    $newKey = is_string($flash['new_key'] ?? null) ? $flash['new_key'] : '';
    $autoTest = is_array($flash['auto_test'] ?? null) ? $flash['auto_test'] : null;
}

$schemaReady = storeBridgeEnsureSchema();
$program = storeBridgeResellerApiSettings();
$allowed = $schemaReady && storeBridgeResellerApiUserAllowed($userId, $program);
$resellerWalletReadiness = $schemaReady
    ? storeBridgeResellerWalletBillingReadiness(true)
    : ['ready' => false, 'code' => 'api_unavailable', 'message' => 'Store API schema is unavailable'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    if (!$schemaReady) resellerStoreApiFlashRedirect('', $t('ระบบ Store API ยังไม่พร้อม กรุณาติดต่อแอดมิน', 'Store API is not ready. Contact the administrator.'));
    $action = isset($_POST['action']) && is_scalar($_POST['action']) ? trim((string) $_POST['action']) : '';
    if (!$allowed) resellerStoreApiFlashRedirect('', $t('บัญชีนี้ยังไม่ได้รับสิทธิ์ใช้งาน Store API', 'This account is not currently eligible for Store API access.'));

    if ($action === 'create_api_key') {
        $allowedIps = isset($_POST['allowed_ips']) && is_scalar($_POST['allowed_ips']) ? trim((string) $_POST['allowed_ips']) : '';
        $profile = [
            'website_name' => is_scalar($_POST['website_name'] ?? null) ? trim((string) $_POST['website_name']) : '',
            'website_url' => is_scalar($_POST['website_url'] ?? null) ? trim((string) $_POST['website_url']) : '',
            'webhook_url' => is_scalar($_POST['webhook_url'] ?? null) ? trim((string) $_POST['webhook_url']) : '',
        ];
        $result = storeBridgeCreateResellerSelfServiceClient($userId, $allowedIps, $profile);
        if (empty($result['success'])) resellerStoreApiFlashRedirect('', (string) ($result['message'] ?? $t('สร้าง API Key ไม่สำเร็จ', 'API key creation failed.')));
        logHistory($userId, 'reseller_store_api_create', 'Created reseller self-service Store API client #' . (int) ($result['client_id'] ?? 0));
        resellerStoreApiFlashRedirect($t('สร้าง API Key สำเร็จ กรุณาคัดลอกตอนนี้ เพราะระบบจะแสดงคีย์เต็มเพียงครั้งเดียว', 'API key created. Copy it now because the full key is shown only once.'), '', (string) ($result['api_key'] ?? ''));
    }

    if ($action === 'save_api_ips') {
        $allowedIps = isset($_POST['allowed_ips']) && is_scalar($_POST['allowed_ips']) ? trim((string) $_POST['allowed_ips']) : '';
        $result = storeBridgeUpdateResellerSelfServiceNetwork($userId, $allowedIps);
        if (empty($result['success'])) resellerStoreApiFlashRedirect('', (string) ($result['message'] ?? $t('บันทึก IP ไม่สำเร็จ', 'Unable to save IP allowlist.')));
        logHistory($userId, 'reseller_store_api_ip_update', 'Updated reseller self-service Store API IP allowlist');
        resellerStoreApiFlashRedirect($t('บันทึก IP/CIDR ที่อนุญาตแล้ว', 'Allowed IP/CIDR list saved.'));
    }

    if ($action === 'save_api_profile') {
        $result = storeBridgeUpdateResellerSelfServiceProfile($userId, $_POST);
        if (empty($result['success'])) resellerStoreApiFlashRedirect('', (string) ($result['message'] ?? $t('บันทึกข้อมูลเว็บไซต์ไม่สำเร็จ', 'Unable to save website integration profile.')));
        logHistory($userId, 'reseller_store_api_profile_update', 'Updated reseller Store API website/webhook profile');
        resellerStoreApiFlashRedirect($t('บันทึกชื่อเว็บไซต์และ Webhook URL แล้ว', 'Website integration profile saved.'));
    }

    if ($action === 'run_auto_test') {
        $testClient = storeBridgeGetResellerSelfServiceClient($userId);
        if (!$testClient) resellerStoreApiFlashRedirect('', $t('ยังไม่มี API Client สำหรับทดสอบ', 'There is no API client to test yet.'));

        $startedAt = microtime(true);
        $publicPing = resellerStoreApiPublicPingTest(storeBridgeEndpointSibling('ping.php'));
        $balanceResult = storeBridgeDevnoodApiResult($testClient, 'balance', []);
        $productsResult = storeBridgeDevnoodApiResult($testClient, 'products', []);

        $balanceOk = !empty($balanceResult['success']) && (int) ($balanceResult['http_code'] ?? 0) === 200;
        $productsOk = !empty($productsResult['success']) && (int) ($productsResult['http_code'] ?? 0) === 200;
        $productsData = is_array($productsResult['data'] ?? null) ? $productsResult['data'] : [];
        $productCount = isset($productsData['count'])
            ? (int) $productsData['count']
            : (is_array($productsData['products'] ?? null) ? count($productsData['products']) : 0);

        $webhook = [
            'configured' => trim((string) ($testClient['webhook_url'] ?? '')) !== '',
            'tested' => false,
            'success' => null,
            'http_code' => null,
            'duration_ms' => null,
            'code' => '',
            'message' => '',
        ];
        if ($webhook['configured']) {
            $webhookResult = storeBridgeTestResellerWebhook($userId);
            $webhook = [
                'configured' => true,
                'tested' => true,
                'success' => !empty($webhookResult['success']),
                'http_code' => (int) ($webhookResult['http_code'] ?? 0),
                'duration_ms' => (int) ($webhookResult['total_ms'] ?? 0),
                'code' => (string) ($webhookResult['code'] ?? ''),
                'message' => substr((string) ($webhookResult['message'] ?? $webhookResult['diagnosis'] ?? ''), 0, 1000),
            ];
        }

        $clientActive = (string) ($testClient['status'] ?? '') === 'active';
        $coreOk = $clientActive && $balanceOk && $productsOk;
        $warnings = [];
        if (!$clientActive) $warnings[] = 'api_client_inactive';
        if (empty($publicPing['success'])) $warnings[] = 'public_ping_failed';
        if (!$balanceOk) $warnings[] = 'balance_failed';
        if (!$productsOk) $warnings[] = 'products_failed';
        if (!empty($webhook['tested']) && empty($webhook['success'])) $warnings[] = 'webhook_failed';
        $status = !$coreOk ? 'failed' : ($warnings ? 'warning' : 'ok');

        $report = [
            'success' => $coreOk,
            'status' => $status,
            'generated_at' => date(DATE_ATOM),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'endpoint' => supplierBridgeCurrentEndpoint(),
            'client' => [
                'id' => (int) ($testClient['id'] ?? 0),
                'status' => (string) ($testClient['status'] ?? ''),
                'api_key_masked' => (string) ($testClient['key_prefix'] ?? '') . '••••' . (string) ($testClient['key_last4'] ?? ''),
                'allowed_ip_enabled' => trim((string) ($testClient['allowed_ips'] ?? '')) !== '',
                'website_configured' => trim((string) ($testClient['website_url'] ?? '')) !== '',
                'webhook_configured' => $webhook['configured'],
            ],
            'tests' => [
                'public_ping' => $publicPing,
                'balance' => [
                    'success' => $balanceOk,
                    'http_code' => (int) ($balanceResult['http_code'] ?? 0),
                    'code' => (string) ($balanceResult['code'] ?? ''),
                    'message' => (string) ($balanceResult['message'] ?? ''),
                    'available_balance' => $balanceOk ? ($balanceResult['data']['available_balance'] ?? $balanceResult['data']['balance'] ?? null) : null,
                ],
                'products' => [
                    'success' => $productsOk,
                    'http_code' => (int) ($productsResult['http_code'] ?? 0),
                    'code' => (string) ($productsResult['code'] ?? ''),
                    'message' => (string) ($productsResult['message'] ?? ''),
                    'count' => $productCount,
                ],
                'webhook' => $webhook,
            ],
            'warnings' => array_values(array_unique($warnings)),
            'interpretation' => [
                'safe' => 'Read-only test. No order is created and no balance is debited.',
                'public_ping' => 'Checks whether the public ping endpoint can be reached from the Sakazuki server. A failure here is a warning because self-routing/Cloudflare can differ from your hosting route.',
                'balance_products' => 'Checks Store API database/business readiness directly without API-key replay.',
                'api_key' => 'Sakazuki stores only the API-key hash, so this page cannot replay your full key. Use the downloadable shared-hosting tester to test the real key/IP from your hosting.',
                'webhook' => $webhook['configured'] ? 'Webhook reachability was tested from Sakazuki to your configured URL.' : 'Webhook test skipped because no webhook URL is configured.',
            ],
        ];
        logHistory($userId, 'reseller_store_api_auto_test', 'Automatic Store API test status=' . $status);
        $msg = $t('ทดสอบ API เสร็จแล้ว ดูผลและดาวน์โหลด JSON ด้านล่าง', 'API test finished. Review the result and download the JSON below.');
        resellerStoreApiFlashRedirect($coreOk ? $msg : '', $coreOk ? '' : $t('พบจุดที่ต้องตรวจเพิ่ม ดู JSON ด้านล่าง', 'One or more core checks need attention. Review the JSON below.'), '', ['auto_test' => $report]);
    }

    if ($action === 'regenerate_api_key') {
        $result = storeBridgeRegenerateResellerSelfServiceKey($userId);
        if (empty($result['success'])) resellerStoreApiFlashRedirect('', (string) ($result['message'] ?? $t('สร้าง API Key ใหม่ไม่สำเร็จ', 'API key regeneration failed.')));
        logHistory($userId, 'reseller_store_api_regenerate', 'Regenerated reseller self-service Store API key');
        resellerStoreApiFlashRedirect($t('สร้าง API Key ใหม่แล้ว คีย์เดิมใช้งานไม่ได้ทันที กรุณาคัดลอกคีย์ใหม่นี้', 'A new API key was generated. The old key is now invalid. Copy the new key now.'), '', (string) ($result['api_key'] ?? ''));
    }

    if ($action === 'set_api_status') {
        $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';
        if (!storeBridgeSetResellerSelfServiceStatus($userId, $status)) resellerStoreApiFlashRedirect('', $t('เปลี่ยนสถานะ API ไม่สำเร็จ', 'Unable to change API status.'));
        logHistory($userId, 'reseller_store_api_status', 'Set reseller self-service Store API status=' . $status);
        resellerStoreApiFlashRedirect($status === 'active' ? $t('เปิดใช้งาน API แล้ว', 'API enabled.') : $t('ระงับ API แล้ว', 'API disabled.'));
    }

    resellerStoreApiFlashRedirect('', $t('คำสั่งไม่ถูกต้อง', 'Invalid action.'));
}

$client = $schemaReady ? storeBridgeGetResellerSelfServiceClient($userId) : null;
$account = $schemaReady ? storeBridgeGetResellerAccount($userId, false) : null;
$balance = $account ? (float) ($account['balance'] ?? 0) : (float) getUserBalance($userId);
$endpoint = supplierBridgeCurrentEndpoint();
$keyGeneration = !empty($program['key_generation']) && !empty($resellerWalletReadiness['ready']);
$mode = (string) ($program['mode'] ?? 'off');
$maskedKey = $client ? (string) ($client['key_prefix'] ?? '') . '••••' . (string) ($client['key_last4'] ?? '') : '';
$requestLogs = ($schemaReady && $client) ? storeBridgeRecentClientRequestLogs((int) $client['id'], 10) : [];
$autoTestJson = is_array($autoTest) ? resellerStoreApiEncodeJson($autoTest) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer API</title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body{background:#0d0d10;color:#f3f4f6}.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.09)}
        .field{width:100%;border-radius:.65rem;border:1px solid rgba(255,255,255,.12);background:rgba(17,24,39,.85);padding:.7rem .85rem;color:#f3f4f6}.field:focus{outline:none;border-color:#4ade80;box-shadow:0 0 0 3px rgba(74,222,128,.12)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:.65rem;padding:.62rem .9rem;font-weight:600;transition:.15s}.btn-primary{background:#16a34a;color:#fff}.btn-primary:hover{background:#15803d}.btn-soft{background:rgba(255,255,255,.08);color:#e5e7eb}.btn-soft:hover{background:rgba(255,255,255,.13)}.btn-danger{background:rgba(239,68,68,.18);color:#fecaca}.btn-danger:hover{background:rgba(239,68,68,.28)}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:.2rem .55rem;font-size:.75rem;font-weight:700}.codebox{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.09);border-radius:.75rem;padding:1rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;line-height:1.55}.mini-table{width:100%;border-collapse:collapse}.mini-table th,.mini-table td{padding:.65rem .7rem;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}.mini-table th{color:#9ca3af;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em}.status-ok{color:#86efac}.status-warn{color:#fcd34d}.status-bad{color:#fca5a5}
    </style>
</head>
<body class="min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-6xl mx-auto p-4 md:p-6 space-y-6">
    <section>
        <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3"><i class="bi bi-code-slash text-green-400"></i>Developer API</h1>
        <p class="mt-2 text-gray-400 max-w-4xl"><?php echo $h($t('เหลือเฉพาะของที่ต้องใช้จริง: Endpoint, API Key, IP, Webhook, ปุ่มทดสอบอัตโนมัติ และ JSON Log สำหรับส่งให้ผู้พัฒนา ไม่ต้องใช้ Terminal หรือคำสั่ง cURL', 'Only the essentials remain: endpoint, API key, IP rules, webhook, one-click tests, and JSON logs for support. No terminal or cURL commands are required.')); ?></p>
    </section>

    <?php if ($error !== ''): ?><div class="rounded-xl border border-red-500/40 bg-red-900/20 p-4 text-red-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $h($error); ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="rounded-xl border border-emerald-500/40 bg-emerald-900/20 p-4 text-emerald-200"><i class="bi bi-check-circle mr-2"></i><?php echo $h($message); ?></div><?php endif; ?>
    <?php if ($schemaReady && $allowed && empty($resellerWalletReadiness['ready'])): ?><div class="rounded-xl border border-amber-500/40 bg-amber-900/20 p-4 text-amber-100"><i class="bi bi-tools mr-2"></i><?php echo $h($t('แอดมินกำลังเตรียมระบบบัญชีสำหรับ Store API อยู่ การสร้าง/Regenerate Key ถูกพักไว้ชั่วคราวเพื่อไม่ให้รายการเงินบันทึกผิดประเภท', 'The administrator is preparing Store API wallet accounting. Key creation/regeneration is temporarily paused to prevent financial records from being misclassified.')); ?></div><?php endif; ?>

    <?php if ($newKey !== ''): ?>
        <section class="rounded-xl border border-amber-400/50 bg-amber-900/20 p-5">
            <div class="font-bold text-amber-200"><i class="bi bi-key-fill mr-2"></i><?php echo $h($t('API Key นี้จะแสดงเต็มเพียงครั้งเดียว', 'This full API key is shown only once.')); ?></div>
            <div class="mt-3 flex flex-col md:flex-row gap-2"><code id="generatedResellerApiKey" class="flex-1 break-all rounded-lg bg-black/40 border border-white/10 p-3 text-sm text-amber-100"><?php echo $h($newKey); ?></code><button type="button" class="btn btn-primary" onclick="copyText('generatedResellerApiKey')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก', 'Copy')); ?></button></div>
        </section>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <section class="glass rounded-xl p-6 border border-red-500/30"><div class="text-red-200 font-bold"><?php echo $h($t('Store API ยังไม่พร้อมใช้งาน', 'Store API is not ready.')); ?></div></section>
    <?php elseif (!$allowed): ?>
        <section class="glass rounded-xl p-6 border border-amber-500/25">
            <div class="flex items-center gap-3"><i class="bi bi-lock text-amber-300 text-2xl"></i><div><div class="font-bold text-lg"><?php echo $h($t('บัญชีนี้ยังไม่ได้เปิดใช้ Developer API', 'Developer API is not enabled for this account.')); ?></div><div class="text-sm text-gray-400 mt-1"><?php echo $h($t('โหมดระบบปัจจุบัน: ', 'Current program mode: ')); ?><?php echo $h(strtoupper($mode)); ?></div></div></div>
        </section>
    <?php else: ?>
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="glass rounded-xl p-5"><div class="text-sm text-gray-400"><?php echo $h($t('ยอดเงินที่ API ใช้ได้', 'Available API balance')); ?></div><div class="text-3xl font-bold text-green-300 mt-2"><?php echo $h(formatCurrency($balance)); ?></div><div class="text-xs text-gray-500 mt-1">billing_mode = reseller_wallet</div></div>
            <div class="glass rounded-xl p-5"><div class="text-sm text-gray-400"><?php echo $h($t('สถานะ API', 'API status')); ?></div><div class="mt-2"><span class="badge <?php echo $client && $client['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-200' : 'bg-gray-500/20 text-gray-300'; ?>"><?php echo $h($client ? strtoupper((string) $client['status']) : $t('ยังไม่ได้สร้าง', 'NOT CREATED')); ?></span></div></div>
            <div class="glass rounded-xl p-5"><div class="text-sm text-gray-400">Endpoint</div><div class="mt-2 text-xs break-all font-mono text-sky-200" id="resellerApiEndpoint"><?php echo $h($endpoint); ?></div><button class="btn btn-soft !py-2 mt-3" type="button" onclick="copyText('resellerApiEndpoint')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก', 'Copy')); ?></button></div>
        </section>

        <?php if (!$client): ?>
            <section class="glass rounded-xl p-5 md:p-6">
                <h2 class="text-xl font-bold"><i class="bi bi-key mr-2 text-amber-300"></i><?php echo $h($t('สร้าง API Key', 'Create API key')); ?></h2>
                <p class="text-sm text-gray-400 mt-2"><?php echo $h($t('คีย์จะผูกกับบัญชีตัวแทนนี้และหักยอดเงินจากบัญชีนี้โดยตรง Full key เก็บฝั่ง Server เป็น SHA-256 hash จึงเรียกดูคีย์เต็มภายหลังไม่ได้', 'The key is linked to this reseller account and debits this account directly. The server stores only a SHA-256 hash, so the full key cannot be retrieved later.')); ?></p>
                <?php if (!$keyGeneration): ?><div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-100"><?php echo $h(empty($resellerWalletReadiness['ready']) ? $t('ระบบบัญชี Store API ยังอยู่ระหว่างเตรียมความพร้อม กรุณารอแอดมินตรวจสอบก่อนสร้างคีย์', 'Store API wallet accounting is not ready yet. Please wait for the administrator to finish verification before creating a key.') : $t('แอดมินยังปิดการสร้าง API Key ไว้', 'The administrator has temporarily disabled API key generation.')); ?></div><?php else: ?>
                <form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4"><?php echo csrfField(); ?><input type="hidden" name="action" value="create_api_key">
                    <label class="block text-sm text-gray-300"><?php echo $h($t('ชื่อเว็บไซต์/ระบบ', 'Website / system name')); ?><input class="field mt-1" name="website_name" maxlength="190" placeholder="DEV NOOD Store"></label>
                    <label class="block text-sm text-gray-300"><?php echo $h($t('เว็บไซต์หลัก (HTTPS)', 'Website URL (HTTPS)')); ?><input class="field mt-1" name="website_url" type="url" placeholder="https://example.com"></label>
                    <label class="block text-sm text-gray-300 lg:col-span-2"><?php echo $h($t('Webhook URL (HTTPS, optional)', 'Webhook URL (HTTPS, optional)')); ?><input class="field mt-1" name="webhook_url" type="url" placeholder="https://example.com/api/sakazuki-webhook"></label>
                    <label class="block text-sm text-gray-300 lg:col-span-2"><?php echo $h($t('IP/CIDR ที่อนุญาต (แนะนำ ถ้า Server มี Public IP คงที่; เว้นว่าง = ไม่จำกัด IP)', 'Allowed IP/CIDR (recommended for a server with stable public egress IP; blank = unrestricted)')); ?><textarea class="field mt-1" rows="4" name="allowed_ips" placeholder="103.70.5.234&#10;203.0.113.0/24"></textarea></label>
                    <div class="lg:col-span-2"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i><?php echo $h($t('สร้าง API Key', 'Create API key')); ?></button></div>
                </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="glass rounded-xl p-5 md:p-6">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4"><div><h2 class="text-xl font-bold"><i class="bi bi-key-fill mr-2 text-amber-300"></i><?php echo $h($t('API Key ของบัญชีนี้', 'This account API key')); ?></h2><div class="font-mono text-sm text-gray-300 mt-2"><?php echo $h($maskedKey); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo $h($t('คีย์เต็มเรียกดูซ้ำไม่ได้ หากหายต้อง Regenerate', 'The full key cannot be shown again. Regenerate it if lost.')); ?></div></div><span class="badge <?php echo $client['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>"><?php echo $h(strtoupper((string) $client['status'])); ?></span></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-5 text-sm"><div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">All req/min</div><div class="font-bold mt-1"><?php echo (int) $client['rate_limit_per_minute']; ?></div></div><div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Orders/min</div><div class="font-bold mt-1"><?php echo (int) ($client['order_rate_limit_per_minute'] ?? 10); ?></div></div><div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Max/order</div><div class="font-bold mt-1"><?php echo (float) ($client['max_order_amount'] ?? 0) > 0 ? $h(formatCurrency((float) $client['max_order_amount'])) : '∞'; ?></div></div><div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500">Daily limit</div><div class="font-bold mt-1"><?php echo (float) ($client['daily_spend_limit'] ?? 0) > 0 ? $h(formatCurrency((float) $client['daily_spend_limit'])) : '∞'; ?></div></div></div>
                <form method="post" class="mt-5"><?php echo csrfField(); ?><input type="hidden" name="action" value="save_api_ips"><label class="block text-sm text-gray-300"><?php echo $h($t('IP/CIDR ที่อนุญาต (เว้นว่าง = ไม่จำกัด IP)', 'Allowed IP/CIDR (blank = unrestricted)')); ?><textarea class="field mt-1" rows="4" name="allowed_ips" placeholder="103.70.5.234"><?php echo $h((string) ($client['allowed_ips'] ?? '')); ?></textarea></label><button class="btn btn-soft mt-3" type="submit"><i class="bi bi-shield-check"></i><?php echo $h($t('บันทึก IP', 'Save IP allowlist')); ?></button></form>
                <div class="flex flex-wrap gap-2 mt-5"><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_api_status"><input type="hidden" name="status" value="<?php echo $client['status'] === 'active' ? 'inactive' : 'active'; ?>"><button class="btn <?php echo $client['status'] === 'active' ? 'btn-danger' : 'btn-primary'; ?>" type="submit"><?php echo $h($client['status'] === 'active' ? $t('ระงับ API', 'Disable API') : $t('เปิด API', 'Enable API')); ?></button></form><?php if ($keyGeneration): ?><form method="post" onsubmit="return confirm('<?php echo $h($t('คีย์เดิมจะใช้งานไม่ได้ทันที ยืนยันสร้างคีย์ใหม่?', 'The current key will stop working immediately. Regenerate?')); ?>')"><?php echo csrfField(); ?><input type="hidden" name="action" value="regenerate_api_key"><button class="btn btn-soft" type="submit"><i class="bi bi-arrow-clockwise"></i><?php echo $h($t('Regenerate Key', 'Regenerate key')); ?></button></form><?php endif; ?></div>
            </section>

            <section class="glass rounded-xl p-5 md:p-6">
                <h2 class="text-xl font-bold"><i class="bi bi-globe2 mr-2 text-violet-300"></i><?php echo $h($t('ข้อมูลเว็บไซต์และ Webhook', 'Website & webhook profile')); ?></h2>
                <p class="text-sm text-gray-400 mt-2"><?php echo $h($t('ข้อมูลนี้ช่วยแยกว่า API Key นี้เป็นของเว็บไหน หากใส่ Webhook URL ระบบจะทดสอบให้พร้อมกับปุ่ม “ทดสอบ API อัตโนมัติ” ด้านล่าง โดยบล็อก Private IP และ Redirect เพื่อป้องกัน SSRF', 'This metadata identifies which storefront owns the API client. If a webhook URL is configured, it is tested together with the automatic API test below. Private/reserved targets and redirects are blocked to prevent SSRF.')); ?></p>
                <form method="post" class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4"><?php echo csrfField(); ?><input type="hidden" name="action" value="save_api_profile">
                    <label class="block text-sm text-gray-300"><?php echo $h($t('ชื่อเว็บไซต์/ระบบ', 'Website / system name')); ?><input class="field mt-1" name="website_name" maxlength="190" value="<?php echo $h((string) ($client['website_name'] ?? '')); ?>" placeholder="DEV NOOD Store"></label>
                    <label class="block text-sm text-gray-300"><?php echo $h($t('เว็บไซต์หลัก (HTTPS)', 'Website URL (HTTPS)')); ?><input class="field mt-1" name="website_url" type="url" value="<?php echo $h((string) ($client['website_url'] ?? '')); ?>" placeholder="https://example.com"></label>
                    <label class="block text-sm text-gray-300 lg:col-span-2"><?php echo $h($t('Webhook URL (HTTPS port 443)', 'Webhook URL (HTTPS port 443)')); ?><input class="field mt-1" name="webhook_url" type="url" value="<?php echo $h((string) ($client['webhook_url'] ?? '')); ?>" placeholder="https://example.com/api/sakazuki-webhook"></label>
                    <div class="lg:col-span-2 flex flex-wrap gap-2"><button class="btn btn-soft" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึกข้อมูล', 'Save profile')); ?></button></div>
                </form>
                <div class="mt-4 rounded-lg border border-white/10 bg-black/20 p-3 text-xs text-gray-400 leading-relaxed"><?php echo $h($t('ถ้าใส่ Webhook URL ปุ่ม “ทดสอบ API อัตโนมัติ” ด้านล่างจะทดสอบ Webhook ให้ด้วย ปลายทางควรรับ HTTPS POST และตอบ HTTP 2xx เมื่อรับข้อมูลได้', 'If you configure a webhook URL, the automatic API test below will test it too. The endpoint should accept HTTPS POST and return HTTP 2xx when the test event is accepted.')); ?></div>
            </section>

            <section class="glass rounded-xl p-5 md:p-6 border border-sky-500/20">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold"><i class="bi bi-activity mr-2 text-sky-300"></i><?php echo $h($t('ทดสอบ API อัตโนมัติ', 'Automatic API test')); ?></h2>
                        <p class="text-sm text-gray-400 mt-2 max-w-3xl"><?php echo $h($t('กดครั้งเดียว ระบบจะตรวจ Endpoint, Store API, ยอดเงิน, รายการสินค้า และ Webhook (ถ้ามี) โดยไม่ซื้อสินค้าและไม่หักเงิน จากนั้นสร้าง JSON ให้ดาวน์โหลดได้', 'One click checks the endpoint, Store API, balance, catalogue, and webhook when configured. It never places an order or debits balance, then creates a downloadable JSON report.')); ?></p>
                    </div>
                    <form method="post" class="shrink-0"><?php echo csrfField(); ?><input type="hidden" name="action" value="run_auto_test"><button class="btn btn-primary" type="submit"><i class="bi bi-play-circle"></i><?php echo $h($t('เริ่มทดสอบ', 'Run test')); ?></button></form>
                </div>

                <?php if (is_array($autoTest) && $autoTestJson !== ''): ?>
                    <?php $autoOk = !empty($autoTest['success']); $autoStatus = (string) ($autoTest['status'] ?? ($autoOk ? 'ok' : 'failed')); ?>
                    <div class="mt-5 rounded-xl border <?php echo $autoOk ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-red-500/30 bg-red-500/10'; ?> p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><div class="font-bold <?php echo $autoOk ? 'text-emerald-200' : 'text-red-200'; ?>"><?php echo $h($autoOk ? $t('ระบบหลักพร้อมใช้งาน', 'Core API is ready') : $t('พบจุดที่ต้องตรวจเพิ่ม', 'A core check needs attention')); ?></div><div class="text-xs text-gray-400 mt-1"><?php echo $h(strtoupper($autoStatus)); ?> · <?php echo (int) ($autoTest['duration_ms'] ?? 0); ?> ms</div></div>
                            <div class="flex gap-2"><button class="btn btn-soft !py-2" type="button" onclick="copyText('autoTestJson')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button><button class="btn btn-soft !py-2" type="button" onclick="downloadJsonFromElement('autoTestJson','store-api-auto-test.json')"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลด JSON', 'Download JSON')); ?></button></div>
                        </div>
                        <details class="mt-3 rounded-lg border border-white/10 bg-black/20"><summary class="p-3 cursor-pointer text-sm text-sky-200"><?php echo $h($t('ดู Log JSON เต็ม', 'View full JSON log')); ?></summary><pre id="autoTestJson" class="codebox m-3 mt-0 max-h-[520px] overflow-auto"><?php echo $h($autoTestJson); ?></pre></details>
                    </div>
                <?php endif; ?>

                <div class="mt-5 flex flex-col md:flex-row gap-3">
                    <a class="btn btn-soft flex-1" href="/reseller/api_store_download.php?file=hosting_tester" download data-no-page-loader="1"><i class="bi bi-file-earmark-code"></i><?php echo $h($t('ดาวน์โหลดตัวทดสอบสำหรับ Shared Hosting', 'Download shared-hosting tester')); ?></a>
                    <a class="btn btn-soft flex-1" href="/reseller/api_store_download.php?file=guide" download data-no-page-loader="1"><i class="bi bi-file-earmark-text"></i><?php echo $h($t('ดาวน์โหลดคู่มือ API แบบสั้น', 'Download short API guide')); ?></a>
                </div>

                <details class="mt-4 rounded-lg border border-white/10 bg-black/20">
                    <summary class="p-3 cursor-pointer text-sm font-semibold"><?php echo $h($t('ใช้ Shared Hosting ต้องทดสอบยังไง?', 'How do I test from shared hosting?')); ?></summary>
                    <div class="p-4 pt-1 text-sm text-gray-300 leading-relaxed"><?php echo $h($t('ดาวน์โหลดไฟล์ตัวทดสอบ อัปโหลดไปที่โฮสของร้าน เปิดผ่าน Browser ใส่ API Key แล้วกด Test ไฟล์จะยิงจากโฮสจริงของคุณไปหา Store API และสร้าง JSON ให้ดาวน์โหลด ไม่ต้อง SSH และไม่ต้องใช้ Terminal หลังทดสอบเสร็จให้ลบไฟล์ออกจากโฮส', 'Download the tester, upload it to your storefront hosting, open it in a browser, paste the API key and run the test. The request is sent from your real hosting to the Store API and produces a downloadable JSON report. No SSH or terminal is required. Delete the tester after use.')); ?></div>
                </details>

                <details class="mt-4 rounded-xl border border-white/10 overflow-hidden">
                    <summary class="p-4 bg-white/5 cursor-pointer font-bold"><?php echo $h($t('Request Log ล่าสุด', 'Recent request logs')); ?> <span class="text-xs text-gray-500 font-normal">(<?php echo count($requestLogs); ?>)</span></summary>
                    <div class="overflow-x-auto">
                    <?php if (!$requestLogs): ?><div class="p-4 text-sm text-gray-500"><?php echo $h($t('ยังไม่มี Request จากเว็บไซต์ของคุณเข้ามา', 'No requests from your integration have been recorded yet.')); ?></div><?php else: ?>
                        <table class="mini-table text-xs min-w-[760px]"><thead><tr><th>Time</th><th>Action</th><th>HTTP</th><th>Duration</th><th>Request ID</th><th>JSON</th></tr></thead><tbody>
                        <?php foreach ($requestLogs as $row): ?>
                            <?php $reqEvidence = is_array($row['diagnostic'] ?? null) ? $row['diagnostic'] : []; $reqPretty = json_encode($reqEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); if (!is_string($reqPretty)) $reqPretty = '{}'; $reqEvidenceId = 'resellerRequestEvidence' . (int) ($row['id'] ?? 0); $reqFile = 'store-api-request-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) (($row['request_id'] ?? '') ?: ($row['id'] ?? 'evidence'))) . '.json'; ?>
                            <tr><td><?php echo $h((string) ($row['created_at'] ?? '')); ?></td><td><?php echo $h(trim((string) ($row['http_method'] ?? '') . ' ' . (string) ($row['action'] ?? ''))); ?><div class="text-gray-500 mt-1"><?php echo $h((string) (($row['stage'] ?? '') ?: '-')); ?></div></td><td class="<?php echo (int) ($row['http_code'] ?? 0) >= 200 && (int) ($row['http_code'] ?? 0) < 300 ? 'status-ok' : ((int) ($row['http_code'] ?? 0) >= 500 ? 'status-bad' : 'status-warn'); ?>">HTTP <?php echo (int) ($row['http_code'] ?? 0); ?><div class="text-gray-400 mt-1"><?php echo $h((string) (($row['result_code'] ?? '') ?: '-')); ?></div></td><td><?php echo (int) ($row['duration_ms'] ?? 0); ?> ms</td><td class="font-mono"><?php echo $h((string) ($row['request_id'] ?? '-')); ?></td><td><details><summary class="cursor-pointer text-sky-200"><?php echo $h($t('เปิด', 'Open')); ?></summary><pre id="<?php echo $h($reqEvidenceId); ?>" class="codebox mt-2 max-h-72 overflow-auto"><?php echo $h($reqPretty); ?></pre><div class="flex gap-2 mt-2"><button class="btn btn-soft !py-1" type="button" onclick="copyText('<?php echo $h($reqEvidenceId); ?>')"><?php echo $h($t('คัดลอก', 'Copy')); ?></button><button class="btn btn-soft !py-1" type="button" onclick="downloadJsonFromElement('<?php echo $h($reqEvidenceId); ?>','<?php echo $h($reqFile); ?>')"><?php echo $h($t('ดาวน์โหลด', 'Download')); ?></button></div></details></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
                    </div>
                </details>
            </section>
        <?php endif; ?>

        <section class="glass rounded-xl p-5 md:p-6">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div><h2 class="text-xl font-bold"><i class="bi bi-journal-check mr-2 text-sky-300"></i><?php echo $h($t('วิธีเชื่อม API แบบสั้น', 'Simple API integration guide')); ?></h2><p class="text-sm text-gray-400 mt-2 max-w-3xl"><?php echo $h($t('หลัก ๆ มี 4 อย่าง: เก็บ API Key ไว้ฝั่ง Server, ดึงสินค้า, ส่ง Order พร้อม external_ref ของร้านคุณ และถ้า Order ยัง Processing ให้เช็ก order_status ด้วย Ref เดิม ห้ามสร้าง Ref ใหม่เพื่อซื้อซ้ำ', 'There are four essentials: keep the API key on your backend, fetch products, submit orders with your own external_ref, and if an order is still processing, query order_status using the same reference. Never create a new reference just to retry the same order.')); ?></p></div>
                <a class="btn btn-soft shrink-0" href="/reseller/api_store_download.php?file=guide" download data-no-page-loader="1"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลดคู่มือ', 'Download guide')); ?></a>
            </div>

            <details class="mt-5 rounded-xl border border-white/10 bg-black/20">
                <summary class="p-4 cursor-pointer font-semibold"><?php echo $h($t('ดู Request และ JSON ตัวอย่าง', 'Request and JSON examples')); ?></summary>
                <div class="p-4 pt-0 space-y-4 text-sm text-gray-300 leading-relaxed">
                    <div><strong>Endpoint:</strong> <code class="font-mono text-sky-200 break-all"><?php echo $h($endpoint); ?></code></div>
                    <div><?php echo $h($t('ทุก Request ที่ต้องยืนยันตัวตนให้ส่ง Header X-API-Key จากฝั่ง Server ของร้านคุณ ไม่ควรใส่ API Key ใน JavaScript หน้าเว็บ', 'Send X-API-Key from your storefront backend for authenticated requests. Never expose the API key in frontend JavaScript.')); ?></div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div><div class="font-semibold mb-2"><?php echo $h($t('Order ที่ส่งมา', 'Order request')); ?></div><pre class="codebox">{
  "action": "order",
  "external_ref": "MYSHOP-ORDER-10001",
  "product_id": "variant-103",
  "quantity": 1
}</pre></div>
                        <div><div class="font-semibold mb-2"><?php echo $h($t('สำเร็จ', 'Success')); ?></div><pre class="codebox">HTTP 200
{
  "success": true,
  "data": {
    "status": "success",
    "keys": ["..."]
  },
  "request_id": "req_..."
}</pre></div>
                    </div>
                    <div><div class="font-semibold mb-2"><?php echo $h($t('ยัง Processing', 'Still processing')); ?></div><pre class="codebox">HTTP 202
{
  "success": true,
  "code": "processing",
  "data": {
    "status": "processing",
    "keys": []
  },
  "request_id": "req_..."
}</pre><p class="mt-2 text-amber-200"><?php echo $h($t('HTTP 202 ไม่ใช่ Error และไม่ใช่ให้ซื้อซ้ำ ให้เรียก order_status ด้วย external_ref เดิม', 'HTTP 202 is not a failure and is not a signal to buy again. Query order_status using the same external_ref.')); ?></p></div>
                    <div><?php echo $h($t('เวลาเกิดปัญหา เก็บ HTTP + code + message + request_id + external_ref แล้วส่งให้ผู้ดูแล ไม่ต้องส่ง API Key', 'When a problem occurs, keep the HTTP status, code, message, request_id, and external_ref. Do not send your API key to support.')); ?></div>
                </div>
            </details>

            <details class="mt-4 rounded-xl border border-white/10 bg-black/20">
                <summary class="p-4 cursor-pointer font-semibold"><?php echo $h($t('HTTP ไม่ใช่ 200 แปลว่าอะไร?', 'What does a non-200 HTTP status mean?')); ?></summary>
                <div class="overflow-x-auto"><table class="mini-table text-sm min-w-[700px]"><thead><tr><th>HTTP</th><th><?php echo $h($t('ความหมาย', 'Meaning')); ?></th><th><?php echo $h($t('ทำอะไรต่อ', 'Next step')); ?></th></tr></thead><tbody>
                    <tr><td class="status-ok">200</td><td><?php echo $h($t('สำเร็จ', 'Success')); ?></td><td><?php echo $h($t('อ่าน JSON และใช้งานผลลัพธ์', 'Use the returned JSON.')); ?></td></tr>
                    <tr><td class="status-ok">202</td><td><?php echo $h($t('Order ถูกเริ่มแล้ว แต่ยัง Processing', 'Order accepted and still processing')); ?></td><td><?php echo $h($t('เช็ก order_status ด้วย Ref เดิม ห้ามซื้อซ้ำด้วย Ref ใหม่', 'Check order_status with the same reference. Do not duplicate the order.')); ?></td></tr>
                    <tr><td>400</td><td><?php echo $h($t('JSON/Request ผิดรูปแบบ', 'Invalid JSON/request')); ?></td><td><?php echo $h($t('ตรวจ JSON และ Content-Type', 'Check JSON and Content-Type.')); ?></td></tr>
                    <tr><td>401</td><td><?php echo $h($t('API Key ผิดหรือไม่มี', 'Missing/invalid API key')); ?></td><td><?php echo $h($t('ตรวจ X-API-Key และคีย์ล่าสุด', 'Check X-API-Key and the latest key.')); ?></td></tr>
                    <tr><td>402</td><td><?php echo $h($t('ยอดเงินไม่พอ', 'Insufficient balance')); ?></td><td><?php echo $h($t('เติมยอดก่อน Order ใหม่', 'Top up before a new order.')); ?></td></tr>
                    <tr><td>403</td><td><?php echo $h($t('API/บัญชี/IP/วงเงินไม่อนุญาต', 'API/account/IP/limit denied')); ?></td><td><?php echo $h($t('ดู code เช่น ip_not_allowed หรือ api_disabled', 'Read the JSON code, e.g. ip_not_allowed or api_disabled.')); ?></td></tr>
                    <tr><td>404</td><td><?php echo $h($t('ไม่พบ Product/Order/Action', 'Product/order/action not found')); ?></td><td><?php echo $h($t('ตรวจ ID, Ref และ Action', 'Check the ID, reference and action.')); ?></td></tr>
                    <tr><td>409</td><td><?php echo $h($t('สถานะชนกัน เช่น ของหมด หรือ Ref เดิมแต่ข้อมูลเปลี่ยน', 'Business conflict, out of stock, or idempotency mismatch')); ?></td><td><?php echo $h($t('อ่าน code/message ก่อน Retry', 'Read code/message before retrying.')); ?></td></tr>
                    <tr><td>413 / 422</td><td><?php echo $h($t('Request ใหญ่เกินไป หรือข้อมูลไม่ถูกต้อง', 'Request too large or validation failed')); ?></td><td><?php echo $h($t('แก้ข้อมูลที่ส่ง', 'Fix the request payload.')); ?></td></tr>
                    <tr><td>429</td><td><?php echo $h($t('ยิงถี่เกิน Rate Limit', 'Rate limited')); ?></td><td><?php echo $h($t('รอแล้ว Retry', 'Wait before retrying.')); ?></td></tr>
                    <tr><td class="status-bad">500</td><td><?php echo $h($t('Server Error', 'Internal server error')); ?></td><td><?php echo $h($t('เก็บ request_id แล้วส่ง JSON Log ให้ผู้ดูแล', 'Keep request_id and send the JSON log to support.')); ?></td></tr>
                    <tr><td class="status-bad">503</td><td><?php echo $h($t('ระบบ/ฐานข้อมูล/ต้นทางไม่พร้อมชั่วคราว', 'Service/database/upstream temporarily unavailable')); ?></td><td><?php echo $h($t('ถ้าเป็น Order ให้เช็ก Ref เดิมก่อน ห้ามรีบสร้าง Order ใหม่', 'For orders, check the original reference before creating anything new.')); ?></td></tr>
                    <tr><td class="status-bad">0 / ไม่มี HTTP</td><td><?php echo $h($t('ยังไม่ได้ Response จาก Server', 'No HTTP response received')); ?></td><td><?php echo $h($t('มักเป็น DNS/TLS/Network/Timeout ใช้ Shared Hosting Tester เก็บ JSON หลักฐาน', 'Usually DNS/TLS/network/timeout. Use the shared-hosting tester to capture JSON evidence.')); ?></td></tr>
                </tbody></table></div>
            </details>
        </section>
    <?php endif; ?>
</main>
<script>
async function copyText(id){const node=document.getElementById(id);if(!node)return;const text=(node.textContent||'').trim();try{await navigator.clipboard.writeText(text);}catch(e){const area=document.createElement('textarea');area.value=text;document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();}}
function downloadJsonFromElement(id,filename){const node=document.getElementById(id);if(!node)return;const text=(node.textContent||'').trim();if(!text)return;let normalized=text;try{normalized=JSON.stringify(JSON.parse(text),null,2);}catch(e){}const safe=(String(filename||'diagnostic.json').replace(/[^A-Za-z0-9._-]+/g,'-').replace(/^-+|-+$/g,'')||'diagnostic.json');const finalName=safe.toLowerCase().endsWith('.json')?safe:(safe+'.json');const blob=new Blob([normalized+'\n'],{type:'application/json;charset=utf-8'});const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=finalName;link.setAttribute('data-no-page-loader','1');document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
</script>
</body>
</html>
