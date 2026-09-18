<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store_bridge.php';
requireAdmin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$isTh = getAppLang() === 'th';
$t = static function (string $th, string $en) use ($isTh): string {
    return $isTh ? $th : $en;
};
$h = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$postString = static function (string $key, string $default = ''): string {
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
};

if (!function_exists('storeBridgeAdminFlashRedirect')) {
    function storeBridgeAdminFlashRedirect(string $message = '', string $error = '', string $newKey = ''): void
    {
        $_SESSION['store_bridge_admin_flash'] = [
            'message' => $message,
            'error' => $error,
            'new_key' => $newKey,
        ];
        header('Location: api_hub.php', true, 303);
        exit;
    }
}

$message = '';
$error = '';
$newKey = '';
if (isset($_SESSION['store_bridge_admin_flash']) && is_array($_SESSION['store_bridge_admin_flash'])) {
    $flash = $_SESSION['store_bridge_admin_flash'];
    unset($_SESSION['store_bridge_admin_flash']);
    $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $error = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
    $newKey = isset($flash['new_key']) && is_string($flash['new_key']) ? $flash['new_key'] : '';
}

$schemaReady = storeBridgeEnsureSchema();
if (!$schemaReady && $error === '') {
    $error = $t(
        'ไม่สามารถสร้างตาราง Store API ได้ กรุณาตรวจสอบสิทธิ์ CREATE/ALTER ของผู้ใช้ฐานข้อมูล',
        'Store API tables could not be prepared. Check the database user CREATE/ALTER permissions.'
    );
}

$resellerWalletReadiness = $schemaReady
    ? storeBridgeResellerWalletBillingReadiness(true)
    : ['ready' => false, 'transaction_type_ready' => false, 'wallet_ledger_ready' => false, 'code' => 'api_unavailable', 'message' => 'Store API schema is unavailable'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    if (!$schemaReady) {
        storeBridgeAdminFlashRedirect('', $t('ฐานข้อมูล Store API ยังไม่พร้อม', 'Store API database schema is not ready.'));
    }

    $action = $postString('action');
    $adminId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'create_client') {
        $result = storeBridgeCreateClient([
            'name' => $postString('name'),
            'billing_mode' => $postString('billing_mode', 'api_balance'),
            'linked_user_id' => $postString('linked_user_id', '0'),
            'price_tier' => $postString('price_tier', 'reseller'),
            'price_multiplier' => $postString('price_multiplier', '1'),
            'balance' => $postString('balance', '0'),
            'allowed_ips' => $postString('allowed_ips'),
            'rate_limit_per_minute' => $postString('rate_limit_per_minute', '120'),
            'order_rate_limit_per_minute' => $postString('order_rate_limit_per_minute', '10'),
            'max_order_amount' => $postString('max_order_amount', '0'),
            'daily_spend_limit' => $postString('daily_spend_limit', '0'),
        ], $adminId);
        if (empty($result['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('สร้าง API Key ไม่สำเร็จ', 'API key creation failed.')));
        }
        logHistory($adminId, 'store_api_client_create', 'Created Store API client #' . (int) $result['client_id']);
        storeBridgeAdminFlashRedirect(
            $t('สร้าง API Key สำเร็จ ระบบจะแสดงคีย์จริงเพียงครั้งเดียว', 'API key created. The full key is shown only once.'),
            '',
            (string) $result['api_key']
        );
    }

    if ($action === 'adjust_balance') {
        $clientId = (int) $postString('client_id', '0');
        $amountText = $postString('amount');
        if (!is_numeric($amountText)) {
            storeBridgeAdminFlashRedirect('', $t('จำนวนเงินไม่ถูกต้อง', 'Invalid amount.'));
        }
        $result = storeBridgeAdjustClientBalance($clientId, (float) $amountText, $postString('note'), $adminId);
        if (empty($result['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('แก้ไขยอด API ไม่สำเร็จ', 'API balance update failed.')));
        }
        logHistory($adminId, 'store_api_balance_adjust', 'Adjusted Store API client #' . $clientId . ' by ' . $amountText);
        storeBridgeAdminFlashRedirect($t('ปรับยอดคงเหลือ API แล้ว', 'API balance updated.'));
    }

    if ($action === 'set_client_status') {
        $clientId = (int) $postString('client_id', '0');
        $status = $postString('status') === 'active' ? 'active' : 'inactive';
        if (!storeBridgeSetClientStatus($clientId, $status)) {
            storeBridgeAdminFlashRedirect('', $t('เปลี่ยนสถานะ API Key ไม่สำเร็จ', 'API key status update failed.'));
        }
        logHistory($adminId, 'store_api_client_status', 'Set Store API client #' . $clientId . ' status=' . $status);
        storeBridgeAdminFlashRedirect($t('เปลี่ยนสถานะ API Key แล้ว', 'API key status updated.'));
    }

    if ($action === 'update_client') {
        $clientId = (int) $postString('client_id', '0');
        $result = storeBridgeUpdateClient($clientId, [
            'name' => $postString('name'),
            'billing_mode' => $postString('billing_mode', 'api_balance'),
            'linked_user_id' => $postString('linked_user_id', '0'),
            'price_tier' => $postString('price_tier', 'reseller'),
            'price_multiplier' => $postString('price_multiplier', '1'),
            'allowed_ips' => $postString('allowed_ips'),
            'rate_limit_per_minute' => $postString('rate_limit_per_minute', '120'),
            'order_rate_limit_per_minute' => $postString('order_rate_limit_per_minute', '10'),
            'max_order_amount' => $postString('max_order_amount', '0'),
            'daily_spend_limit' => $postString('daily_spend_limit', '0'),
            'source_access' => [
                'cgo' => isset($_POST['allow_cgo']),
                'supplier_connection_ids' => isset($_POST['supplier_connection_ids']) && is_array($_POST['supplier_connection_ids'])
                    ? $_POST['supplier_connection_ids']
                    : [],
            ],
        ]);
        if (empty($result['success'])) storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('แก้ไขลูกค้า API ไม่สำเร็จ', 'API client update failed.')));
        logHistory($adminId, 'store_api_client_update', 'Updated Store API client #' . $clientId);
        storeBridgeAdminFlashRedirect($t('บันทึกการตั้งค่าลูกค้า API แล้ว', 'API client settings saved.'));
    }

    if ($action === 'regenerate_client_key') {
        $clientId = (int) $postString('client_id', '0');
        $result = storeBridgeRegenerateClientKey($clientId);
        if (empty($result['success'])) storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('สร้างคีย์ใหม่ไม่สำเร็จ', 'Key regeneration failed.')));
        logHistory($adminId, 'store_api_client_regenerate', 'Regenerated Store API client #' . $clientId);
        storeBridgeAdminFlashRedirect($t('สร้าง API Key ใหม่แล้ว คีย์เก่าถูกยกเลิกทันที', 'A new API key was generated and the old key was revoked.'), '', (string) $result['api_key']);
    }

    if ($action === 'delete_client') {
        $clientId = (int) $postString('client_id', '0');
        $result = storeBridgeDeleteClient($clientId);
        if (empty($result['success'])) storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('ลบ API Key ไม่สำเร็จ', 'API key deletion failed.')));
        logHistory($adminId, 'store_api_client_delete', 'Deleted Store API client #' . $clientId . ' mode=' . (string) ($result['mode'] ?? 'unknown'));
        $messageText = ($result['mode'] ?? '') === 'hard'
            ? $t('ลบ API Key และข้อมูลที่ไม่มีประวัติคำสั่งซื้อแล้ว', 'API key and unused client record deleted.')
            : $t('ยกเลิกและลบสิทธิ์ API Key แล้ว โดยเก็บประวัติคำสั่งซื้อไว้ตรวจสอบ', 'API key access deleted while order history was retained.');
        storeBridgeAdminFlashRedirect($messageText);
    }

    if ($action === 'update_reseller_api_program') {
        $mode = strtolower($postString('program_mode', 'off'));
        if (!in_array($mode, ['off', 'pilot', 'all'], true)) $mode = 'off';
        $rawPilot = $postString('pilot_user_ids');
        $pilotIds = [];
        foreach (preg_split('/[\s,;]+/', $rawPilot, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (ctype_digit($piece) && (int) $piece > 0) $pilotIds[(int) $piece] = true;
        }
        $pilotValue = implode(',', array_keys($pilotIds));
        $defaultRateText = $postString('default_rate_limit', '120');
        $defaultOrderRateText = $postString('default_order_rate_limit', '10');
        $defaultMaxOrderText = $postString('default_max_order_amount', '0');
        $defaultDailyLimitText = $postString('default_daily_spend_limit', '0');
        if (!is_numeric($defaultRateText) || !is_numeric($defaultOrderRateText) || !is_numeric($defaultMaxOrderText) || !is_numeric($defaultDailyLimitText)) {
            storeBridgeAdminFlashRedirect('', $t('ค่าจำกัด API เริ่มต้นไม่ถูกต้อง', 'Default API limit values are invalid.'));
        }
        $defaultRate = max(10, min(5000, (int) $defaultRateText));
        $defaultOrderRate = max(1, min(1000, (int) $defaultOrderRateText));
        $defaultMaxOrder = round((float) $defaultMaxOrderText, 2);
        $defaultDailyLimit = round((float) $defaultDailyLimitText, 2);
        if (!is_finite($defaultMaxOrder) || $defaultMaxOrder < 0 || $defaultMaxOrder > 1000000000 || !is_finite($defaultDailyLimit) || $defaultDailyLimit < 0 || $defaultDailyLimit > 1000000000) {
            storeBridgeAdminFlashRedirect('', $t('วงเงิน API เริ่มต้นไม่ถูกต้อง', 'Default API spending limits are invalid.'));
        }

        $conn->begin_transaction();
        try {
            $results = [
                updateSetting('store_reseller_api_program_mode', $mode),
                updateSetting('store_reseller_api_menu_visible', isset($_POST['menu_visible']) ? '1' : '0'),
                updateSetting('store_reseller_api_key_generation', isset($_POST['key_generation']) ? '1' : '0'),
                updateSetting('store_reseller_api_pilot_user_ids', $pilotValue),
                updateSetting('store_reseller_api_default_rate_limit', (string) $defaultRate),
                updateSetting('store_reseller_api_default_order_rate_limit', (string) $defaultOrderRate),
                updateSetting('store_reseller_api_default_max_order_amount', number_format($defaultMaxOrder, 2, '.', '')),
                updateSetting('store_reseller_api_default_daily_spend_limit', number_format($defaultDailyLimit, 2, '.', '')),
            ];
            if (in_array(false, $results, true)) throw new RuntimeException('settings_update_failed');
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            storeBridgeAdminFlashRedirect('', $t('บันทึกการเปิดใช้งาน API สำหรับตัวแทนไม่สำเร็จ', 'Unable to save the reseller API program settings.'));
        }
        logHistory($adminId, 'store_reseller_api_program_update', 'Reseller API program mode=' . $mode . ' pilot=' . $pilotValue . ' defaults=' . $defaultRate . '/' . $defaultOrderRate . '/' . number_format($defaultMaxOrder, 2, '.', '') . '/' . number_format($defaultDailyLimit, 2, '.', ''));
        storeBridgeAdminFlashRedirect($t('บันทึกการเปิดใช้งาน API สำหรับตัวแทนแล้ว', 'Reseller API program settings saved.'));
    }

    if ($action === 'create_connection') {
        $result = supplierBridgeCreateConnection([
            'name' => $postString('name'),
            'provider_type' => $postString('provider_type', 'sakazuki_v1'),
            'endpoint_url' => $postString('endpoint_url'),
            'api_key' => $postString('api_key'),
            'priority' => $postString('priority', '100'),
            'purchase_mode' => $postString('purchase_mode', 'live'),
            'auto_publish' => isset($_POST['auto_publish']),
            'sync_details' => isset($_POST['sync_details']),
            'sync_prices' => isset($_POST['sync_prices']),
            'user_price_mode' => $postString('user_price_mode', 'source'),
            'reseller_price_mode' => $postString('reseller_price_mode', 'source'),
            'user_markup_percent' => $postString('user_markup_percent', '20'),
            'reseller_markup_percent' => $postString('reseller_markup_percent', '10'),
            'protect_below_cost' => isset($_POST['protect_below_cost']),
        ], $adminId);
        if (empty($result['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('เพิ่ม Supplier ไม่สำเร็จ', 'Supplier connection creation failed.')));
        }
        $connectionId = (int) $result['connection_id'];
        $sync = supplierBridgeRefreshConnection($connectionId, true);
        logHistory($adminId, 'supplier_connection_create', 'Created supplier connection #' . $connectionId);
        if (empty($sync['success'])) {
            storeBridgeAdminFlashRedirect(
                $t('บันทึกการเชื่อมต่อแล้ว แต่ทดสอบ API ไม่ผ่าน', 'Connection saved, but the API test failed.'),
                (string) ($sync['message'] ?? $t('ไม่สามารถดึงรายการสินค้าได้', 'Products could not be retrieved.'))
            );
        }
        storeBridgeAdminFlashRedirect(
            $t('เพิ่ม Supplier และซิงก์สินค้าแล้ว ', 'Supplier added and products synchronized: ')
            . (int) ($sync['updated'] ?? 0)
            . $t(' รายการ', ' items')
        );
    }

    if ($action === 'update_connection') {
        $connectionId = (int) $postString('connection_id', '0');
        $result = supplierBridgeUpdateConnection($connectionId, [
            'name' => $postString('name'),
            'endpoint_url' => $postString('endpoint_url'),
            'api_key' => $postString('api_key'),
            'priority' => $postString('priority', '100'),
            'purchase_mode' => $postString('purchase_mode', ''),
            'auto_publish' => isset($_POST['auto_publish']),
            'sync_details' => isset($_POST['sync_details']),
            'sync_prices' => isset($_POST['sync_prices']),
            'user_price_mode' => $postString('user_price_mode', 'source'),
            'reseller_price_mode' => $postString('reseller_price_mode', 'source'),
            'user_markup_percent' => $postString('user_markup_percent', '20'),
            'reseller_markup_percent' => $postString('reseller_markup_percent', '10'),
            'protect_below_cost' => isset($_POST['protect_below_cost']),
        ]);
        if (empty($result['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('แก้ไข Supplier ไม่สำเร็จ', 'Supplier update failed.')));
        }
        logHistory($adminId, 'supplier_connection_update', 'Updated supplier connection #' . $connectionId);
        storeBridgeAdminFlashRedirect($t('บันทึกการตั้งค่า Supplier แล้ว', 'Supplier settings saved.'));
    }

    if ($action === 'set_connection_status') {
        $connectionId = (int) $postString('connection_id', '0');
        $status = $postString('status') === 'active' ? 'active' : 'inactive';
        if (!supplierBridgeSetConnectionStatus($connectionId, $status)) {
            storeBridgeAdminFlashRedirect('', $t('เปลี่ยนสถานะ Supplier ไม่สำเร็จ', 'Supplier status update failed.'));
        }
        logHistory($adminId, 'supplier_connection_status', 'Set supplier connection #' . $connectionId . ' status=' . $status);
        storeBridgeAdminFlashRedirect($t('เปลี่ยนสถานะ Supplier แล้ว', 'Supplier status updated.'));
    }

    if ($action === 'sync_connection') {
        $connectionId = (int) $postString('connection_id', '0');
        $sync = supplierBridgeRefreshConnection($connectionId, true);
        if (empty($sync['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($sync['message'] ?? $t('ซิงก์ Supplier ไม่สำเร็จ', 'Supplier synchronization failed.')));
        }
        logHistory($adminId, 'supplier_connection_sync', 'Synchronized supplier connection #' . $connectionId);
        storeBridgeAdminFlashRedirect(
            $t('ซิงก์แล้ว ', 'Synchronized: ') . (int) ($sync['updated'] ?? 0)
            . $t(' รายการ; เผยแพร่เข้าหน้าร้าน ', ' items; published to storefront: ')
            . (int) ($sync['published'] ?? 0)
        );
    }

    if ($action === 'reconcile_order') {
        $orderId = (int) $postString('order_id', '0');
        $result = supplierBridgeReconcileOrder($orderId);
        if (empty($result['success'])) {
            storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('ตรวจสอบคำสั่งซื้อไม่สำเร็จ', 'Order reconciliation failed.')));
        }
        logHistory($adminId, 'supplier_order_reconcile', 'Reconciled supplier order #' . $orderId);
        storeBridgeAdminFlashRedirect((string) ($result['message'] ?? $t('ตรวจสอบคำสั่งซื้อแล้ว', 'Order reconciled.')));
    }

    if ($action === 'manual_confirm_supplier_order') {
        $orderId = (int) $postString('order_id', '0');
        $rawKeys = isset($_POST['manual_keys']) && is_scalar($_POST['manual_keys']) ? trim((string) $_POST['manual_keys']) : '';
        $result = supplierBridgeAdminConfirmOrderSuccess($orderId, $rawKeys, $adminId);
        if (empty($result['success'])) storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('ยืนยันคำสั่งซื้อไม่สำเร็จ', 'Unable to confirm supplier order.')));
        storeBridgeAdminFlashRedirect((string) ($result['message'] ?? $t('ยืนยันคำสั่งซื้อแล้ว', 'Supplier order confirmed.')));
    }

    if ($action === 'manual_refund_supplier_order') {
        $orderId = (int) $postString('order_id', '0');
        $result = supplierBridgeAdminRefundOrder($orderId, $adminId, isset($_POST['confirmed_no_supplier_order']));
        if (empty($result['success'])) storeBridgeAdminFlashRedirect('', (string) ($result['message'] ?? $t('คืนยอดคำสั่งซื้อไม่สำเร็จ', 'Unable to refund supplier order.')));
        storeBridgeAdminFlashRedirect((string) ($result['message'] ?? $t('คืนยอดคำสั่งซื้อแล้ว', 'Supplier order refunded.')));
    }

    if ($action === 'test_client_webhook') {
        $clientId = (int) $postString('client_id', '0');
        $result = storeBridgeTestClientWebhook($clientId);
        logHistory($adminId, 'store_api_admin_webhook_test', 'Webhook test client #' . $clientId . ' result=' . (string) ($result['code'] ?? 'unknown') . ' http=' . (int) ($result['http_code'] ?? 0));
        if (empty($result['success'])) {
            $detail = trim((string) ($result['diagnosis'] ?? ''));
            $base = trim((string) ($result['message'] ?? $t('ทดสอบ Webhook ไม่สำเร็จ', 'Webhook test failed.')));
            storeBridgeAdminFlashRedirect('', $base . ($detail !== '' ? ' — ' . $detail : ''));
        }
        storeBridgeAdminFlashRedirect($t('Webhook ตอบกลับสำเร็จ ดู Debug JSON ล่าสุดได้ที่รายการ API Client', 'Webhook responded successfully. The latest Debug JSON is available on the API client row.'));
    }

    storeBridgeAdminFlashRedirect('', $t('คำสั่งไม่ถูกต้อง', 'Invalid action.'));
}

$clients = $schemaReady ? storeBridgeGetClients() : [];
$diagnosticSchemaReady = $schemaReady && storeBridgeEnsureDiagnosticSchema();
$webhookTestLogs = $schemaReady ? storeBridgeGetWebhookTestLogs(100) : [];
$diagnosticProbeLogs = $diagnosticSchemaReady ? storeBridgeGetDiagnosticProbeLogs(100) : [];
$connections = $schemaReady ? supplierBridgeGetConnections() : [];
$supplierAttemptSchemaReady = $schemaReady && supplierBridgeEnsureApiAttemptDiagnosticSchema();
$supplierApiAttemptLogs = $supplierAttemptSchemaReady ? supplierBridgeGetApiAttemptLogs(100) : [];
$providerOrders = $schemaReady ? storeBridgeGetProviderOrders(100) : [];
$supplierOrders = $schemaReady ? supplierBridgeGetOrders(100) : [];
$providerTypes = supplierBridgeProviderTypes();
$providerEndpoint = supplierBridgeCurrentEndpoint();
$providerPingEndpoint = storeBridgeEndpointSibling('ping.php');
$providerProbeEndpoint = storeBridgeEndpointSibling('diagnostic.php');
$resellerApiSettings = storeBridgeResellerApiSettings();
$resellerAccounts = [];
if ($schemaReady) {
    $resellerResult = $conn->query("SELECT id,username,email,balance,status FROM users WHERE role='reseller' ORDER BY username ASC,id ASC");
    $resellerAccounts = $resellerResult ? $resellerResult->fetch_all(MYSQLI_ASSOC) : [];
}
$apiDocsTemplate = <<<'TXT'
SAKAZUKI STORE API v2.2 - Integration Guide
=========================================

BASE ENDPOINT
{{PROVIDER_ENDPOINT}}

The route intentionally remains /api/store/v1.php for backward compatibility. The api_version value in responses is the implementation/capability revision; do NOT guess or switch the URL to /v2.php unless the provider explicitly publishes a new endpoint.

1) TRANSPORT / DATA FORMAT
- Use HTTPS only. TLS encrypts the HTTP request and response while data is in transit.
- Request/response encoding: UTF-8 JSON. POST request bodies are limited to 1 MiB.
- The JSON body is NOT encrypted with a separate AES layer. Do not invent an extra encryption scheme on the client side.
- API keys are generated from cryptographically secure random bytes. The database persistently stores only a SHA-256 one-way hash plus a short prefix/last-4 identifier. The plaintext key is carried only through the authenticated one-time display flow and is removed from that flash session after it is shown; it cannot be recovered from the database later.
- Supplier credentials stored by this website use AES-256-GCM at rest. This is separate from reseller Store API authentication.
- Normal reseller Store API calls do NOT require a custom HMAC signature or client-side AES encryption. HTTPS/TLS plus the API key (and optional IP/CIDR allowlist) is the required authentication transport for the public reseller API.

2) AUTHENTICATION
Recommended header:
  X-API-Key: YOUR_API_KEY
Alternative:
  Authorization: Bearer YOUR_API_KEY

Never send the API key in the URL/query string. Keep it in a server-side secret/environment variable and never expose it to browser JavaScript, public Git repositories, application logs, screenshots, or client-side source code.

Optional IP/CIDR allowlist can be configured per API client. If the provider is behind Cloudflare/reverse proxy, the origin must trust only the actual proxy IP/CIDR ranges. This deployment reads TRUSTED_PROXY_IPS from the environment first, or private/trusted_proxies.php as a shared-hosting fallback. Forwarded headers such as CF-Connecting-IP are accepted only when REMOTE_ADDR is trusted; direct clients cannot spoof those headers to bypass the allowlist.
Important: trusted-proxy configuration belongs to the PROVIDER SERVER, not the reseller API request. Never solve IP allowlist problems by trusting CF-Connecting-IP/X-Forwarded-For from arbitrary REMOTE_ADDR values. The bundled private/trusted_proxies.php contains Cloudflare's official proxy CIDRs verified for this package on 2026-08-31; review Cloudflare's official IP list when maintaining the deployment.

3) CONNECTIVITY / TIMEOUT DIAGNOSTICS
Fast network/PHP ping (NO database and NO API key):
  GET {{PROVIDER_PING_ENDPOINT}}

If this endpoint itself times out, troubleshoot DNS resolution, outbound TCP 443, TLS, Cloudflare/WAF, hosting/origin routing, or PHP dispatch BEFORE debugging API keys or order code.

Authenticated diagnostic (does not buy anything):
  GET {{PROVIDER_ENDPOINT}}?action=diagnostic
  X-API-Key: YOUR_API_KEY

A successful response reports the detected source IP, proxy trust state and whether the authenticated request passed the configured IP allowlist. Reseller accounts also have a one-time Backend IP Probe in reseller/api_store.php. Generate the probe there and run the generated curl command on the ACTUAL backend server that will call this API. The probe expires after 10 minutes and does not require exposing the API key.

Diagnostic order:
  1. ping.php -> proves DNS/TCP/TLS/Cloudflare/PHP path reaches PHP without DB.
  2. one-time Backend IP Probe -> proves PHP + Store Bridge DB and records the real source IP seen by the provider.
  3. action=diagnostic -> proves API key + API program + linked account + IP allowlist authentication.
  4. action=balance/products -> proves normal read-only API operations.
  5. action=order -> only after the previous stages pass.

If a timeout happens BEFORE PHP, there will be no store_api_request_logs row. That absence is useful evidence that the request never reached the Store API application.

4) BILLING MODES
The billing mode is configured by the provider, NOT chosen in each order request.
- api_balance: legacy Store API credit. Balance is managed in API Hub. Existing website-to-website integrations can keep using this mode.
- reseller_wallet: the API is linked to one reseller account and purchases debit users.balance directly. The reseller can top up through the normal website deposit flow. API prices use the linked reseller account's effective reseller price, including account-specific variant pricing.

Product visibility/pricing rule by billing mode:
- api_balance: legacy per-API-key product enable/disable rules, Custom API Price, price tier and multiplier are supported. CGO/Store Bridge supplier access follows the same per-client source checkboxes in API Hub and debits/refunds the isolated API credit balance.
- reseller_wallet: uses this provider endpoint's active product/variant catalogue and the linked reseller account's effective reseller price. It intentionally ignores legacy per-key Custom API Price, multiplier and disabled-product rules so switching billing mode cannot silently hide products or use stale API pricing. Availability is LOCAL-first, then only CGO/Store Bridge supplier connections explicitly enabled for this API client. The advertised stock is the largest quantity one permitted source can fulfill by itself, never a sum of independent source pools. One order is fulfilled by one source in full; the provider source is internal and does not change the reseller integration.

For reseller_wallet, the linked reseller account is the PAYER only. Keys delivered by Store API are external API deliveries and are returned by order/order_status; they are not inserted into the reseller account's local My Keys entitlement. This prevents one sale from being counted as both an external Store API sale and a direct local-key purchase.

Fulfillment source rule:
- If LOCAL has the full requested quantity, LOCAL is used.
- If LOCAL cannot fulfill the whole order, CGO is considered only when this API client allows CGO.
- If CGO cannot fulfill it, permitted Store Bridge supplier connections are considered in configured source priority. VIPSTORE/StarkMods/other supplier stock is never exposed to a client unless the provider explicitly enables that connection for the client.
- Cached source stock is used for fast products/inventory responses; action=order performs the authoritative checkout-time verification.
- Stock pools are never added together and one Store API order never mixes keys from multiple fulfillment sources.
- If upstream acceptance is uncertain, the Store API order stays processing and returns HTTP 202. Do NOT create a second purchase; poll action=order_status with the same order_id/external_ref.
- Store API billing is debited once. CGO and Store Bridge supplier children run in procurement-only mode and do not debit the reseller balance a second time. A proven terminal pre-delivery failure is automatically refunded and recorded in the financial audit ledger.

Check the active mode with GET action=balance. The response includes billing_mode. Spending limits and request/order quotas are configured per API client unless the provider tells you otherwise. Quotas are enforced across the whole API client/key, not separately per source IP.

5) GET PRODUCT CATALOG
GET {{PROVIDER_ENDPOINT}}?action=products
Headers:
  Accept: application/json
  X-API-Key: YOUR_API_KEY

Successful response example:
{
  "success": true,
  "api_version": "2.2",
  "currency": "THB",
  "billing_mode": "reseller_wallet",
  "products": [
    {
      "id": "variant-166",
      "remote_product_id": "variant-166",
      "name": "PRODUCT NAME",
      "duration": "3 Day",
      "price": 80.00,
      "stock": 3
    }
  ]
}

Use the returned id/remote_product_id as product_id when placing an order. Do not guess local database IDs.

6) CHECK ONE PRODUCT'S INVENTORY
GET {{PROVIDER_ENDPOINT}}?action=inventory&product_id=variant-166

7) CHECK BALANCE
GET {{PROVIDER_ENDPOINT}}?action=balance

Example:
{
  "success": true,
  "billing_mode": "reseller_wallet",
  "balance": 1420.00,
  "currency": "THB",
  "request_id": "req_..."
}

8) CREATE ORDER
POST {{PROVIDER_ENDPOINT}}
Content-Type: application/json
Accept: application/json
X-API-Key: YOUR_API_KEY

Body:
{
  "action": "order",
  "external_ref": "DEVNOOD-20260831-000001",
  "product_id": "variant-166",
  "quantity": 1,
  "origin_site_id": "devnoodshop",
  "origin_user_id": "12345",
  "customer_ref": "optional-reference",
  "customer_name": "optional",
  "customer_email": "optional@example.com"
}

Rules:
- external_ref is REQUIRED, 8-120 characters, allowed characters: A-Z a-z 0-9 . _ : -
- external_ref must be unique per API client for a logical order.
- quantity: integer 1-100. Fractional/non-numeric quantities are rejected instead of being silently rounded/cast.
- product_id must come from action=products and currently uses the returned variant-N identifier format.
- origin_site_id is optional; when supplied it accepts only a-z, 0-9, dot, underscore, colon and hyphen after normalization to lowercase.
- If BOTH origin_site_id and origin_user_id are supplied, the provider canonicalizes customer_ref to origin_site_id:user:origin_user_id. In that case a separately supplied customer_ref is not used as an independent identity value.
- The provider controls billing_mode, prices, product access, rate limits, order-rate limits, per-order spending limits and daily spending limits.
- In reseller_wallet mode, treat action=products as the source of truth for what THIS Store API endpoint can sell. Do not assume every product visible on the website storefront is exported here, and do not cache hidden/disabled legacy API rules from an older api_balance configuration.

Successful response:
{
  "success": true,
  "data": {
    "success": true,
    "status": "success",
    "order_id": "1234",
    "external_ref": "DEVNOOD-20260831-000001",
    "product_id": "variant-166",
    "quantity": 1,
    "unit_price": 80.00,
    "total": 80.00,
    "currency": "THB",
    "billing_mode": "reseller_wallet",
    "balance_before": 1500.00,
    "balance_after": 1420.00,
    "keys": ["DELIVERED-KEY"],
    "balance": 1420.00
  },
  "request_id": "req_..."
}

9) IDEMPOTENCY / TIMEOUT SAFETY - IMPORTANT
external_ref is the idempotency key.
- If a request times out or the connection drops, DO NOT immediately create a new external_ref and buy again.
- First query order_status using the SAME external_ref.
- Repeating the same external_ref for the same logical order returns the original order and does not debit twice.
- Immutable order identity includes product_id, quantity and the exact normalized origin_site_id/origin_user_id/effective customer_ref values used on the first request, including whether each field was empty. When both origin fields exist, effective customer_ref is the canonical origin_site_id:user:origin_user_id value described above. Retry with the same logical payload; do not add, omit or change those identity fields.
- Reusing the same external_ref for a different product, quantity, or added/omitted/changed customer/origin identity returns HTTP 409 code=idempotency_conflict.
- customer_name/customer_email are descriptive snapshots, not permission to reuse an external_ref for a different logical order.

Recommended timeout recovery:
  POST order -> connection timeout/unknown result
  -> GET order_status with the same external_ref
  -> if found and success, use the returned keys
  -> if not found, retry POST order with the SAME external_ref

10) ORDER STATUS
GET {{PROVIDER_ENDPOINT}}?action=order_status&external_ref=DEVNOOD-20260831-000001
or
GET {{PROVIDER_ENDPOINT}}?action=order_status&order_id=1234

POST is also supported with JSON containing action=order_status and external_ref/order_id.

11) HTTP STATUS / MACHINE ERROR CODES
200  success / existing idempotent order returned
400  invalid JSON/action/request syntax
401  missing_api_key, invalid_api_key
402  insufficient_balance
403  ip_not_allowed, api_disabled, api_program_disabled, account_inactive,
     max_order_amount_exceeded, daily_spend_limit_exceeded
404  product_not_found, order_not_found
409  out_of_stock, idempotency_conflict
413  request body too large/invalid
415  unsupported content type for protected internal actions
422  invalid_request, invalid_product_id, invalid_quantity, invalid_origin_site_id,
     invalid_order_id, invalid_external_ref
429  rate_limited, order_rate_limited
500  internal_error
503  api_unavailable, billing_schema_unavailable, wallet_ledger_unavailable,
     temporary_database_conflict, catalogue/inventory/order lookup temporarily unavailable

Standard error response shape:
{
  "success": false,
  "code": "machine_readable_code",
  "message": "Human-readable explanation",
  "request_id": "req_..."
}

Every normal API response includes request_id. Save request_id, external_ref and the HTTP status in your own logs when troubleshooting. Do NOT log the API key or delivered keys unless your security model explicitly requires it. Program logic should use HTTP status + code, not parse message text.

12) RETRY POLICY
Safe to retry normally:
- products
- inventory
- balance
- order_status

Order:
- Retry only with the SAME external_ref.
- Prefer order_status before retrying after an unknown/timeout result.
- Use exponential backoff for HTTP 429/503, for example 1s, 2s, 4s. For order-related 503 such as temporary_database_conflict, keep the SAME external_ref. Do not hammer the endpoint.

13) cURL EXAMPLES
Products:
  curl -sS -H 'Accept: application/json' -H 'X-API-Key: YOUR_API_KEY' \
    '{{PROVIDER_ENDPOINT}}?action=products'

Create order:
  curl -sS -X POST '{{PROVIDER_ENDPOINT}}' \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -H 'X-API-Key: YOUR_API_KEY' \
    --data '{"action":"order","external_ref":"ORDER-20260831-000001","product_id":"variant-166","quantity":1}'

14) PHP EXAMPLE
<?php
$endpoint = '{{PROVIDER_ENDPOINT}}';
$apiKey = getenv('SAKAZUKI_API_KEY');
$payload = [
    'action' => 'order',
    'external_ref' => 'ORDER-' . date('YmdHis') . '-000001',
    'product_id' => 'variant-166',
    'quantity' => 1,
];
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
]);
$body = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
// If the order result is unknown because of a timeout/network error,
// query order_status with the SAME external_ref before retrying.
?>

15) NODE.JS (fetch) EXAMPLE
const endpoint = '{{PROVIDER_ENDPOINT}}';
const apiKey = process.env.SAKAZUKI_API_KEY;
const externalRef = 'ORDER-' + Date.now();
const response = await fetch(endpoint, {
  method: 'POST',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-API-Key': apiKey
  },
  body: JSON.stringify({
    action: 'order',
    external_ref: externalRef,
    product_id: 'variant-166',
    quantity: 1
  })
});
const data = await response.json();
if (!response.ok) {
  console.error(response.status, data.code, data.message, data.request_id);
}

16) SECURITY CHECKLIST FOR THE INTEGRATOR
- Server-to-server calls only. Never expose the API key in frontend/browser code.
- Restrict the API key by public egress IP/CIDR when your server IP is stable.
- Generate a unique external_ref before the first order attempt and persist it before sending the request. Persist the complete original logical-order payload too (product_id, quantity and the exact origin/customer identity fields, including empty values) so a timeout retry can reproduce the same request exactly.
- Treat delivered keys as secrets. Persist the successful order result on your server; reseller_wallet deliveries do not appear as local My Keys on the payer account.
- Respect HTTP status and machine-readable code; do not parse message text to decide program logic.
- Regenerate the API key immediately if it is suspected to be leaked. The old key stops working immediately.

17) WEBSITE / WEBHOOK PROFILE
Reseller self-service clients can save a website/system name, primary HTTPS website URL and HTTPS webhook URL in reseller/api_store.php. The page includes a bounded server-side webhook reachability test with DNS pinning, HTTPS-only port 443, no redirects and private/reserved IP blocking to prevent SSRF.

Current webhook behavior in Store API v2.2:
- The webhook URL is stored as integration metadata and can be reachability-tested manually.
- It is NOT called synchronously from order.success. A slow reseller callback must not delay or timeout the purchase response.
- order_status remains the authoritative recovery mechanism after an unknown/timeout order result.
- A production order-event webhook should be implemented as an asynchronous queued delivery with signatures/retries rather than being bolted onto the live order transaction.

END OF GUIDE
TXT;
$apiDocsText = strtr($apiDocsTemplate, [
    '{{PROVIDER_ENDPOINT}}' => $providerEndpoint,
    '{{PROVIDER_PING_ENDPOINT}}' => $providerPingEndpoint,
]);

$ledgerRows = [];
$requestRows = [];
if ($schemaReady) {
    $ledgerResult = $conn->query("SELECT l.*, c.name AS client_name FROM store_api_balance_ledger l JOIN store_api_clients c ON c.id=l.client_id ORDER BY l.id DESC LIMIT 100");
    $ledgerRows = $ledgerResult ? $ledgerResult->fetch_all(MYSQLI_ASSOC) : [];
    $requestRows = storeBridgeGetRecentRequestLogs(100);
}
$orderRequestRows = array_values(array_filter($requestRows, static function ($row) {
    return strtolower(trim((string) ($row['action'] ?? ''))) === 'order';
}));

$totalProviderBalance = 0.0;
$linkedWalletClientCount = 0;
$ipRestrictedClientCount = 0;
foreach ($clients as $client) {
    if (storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance') === 'api_balance') $totalProviderBalance += (float) ($client['balance'] ?? 0);
    else $linkedWalletClientCount++;
    if (trim((string) ($client['allowed_ips'] ?? '')) !== '') $ipRestrictedClientCount++;
}
$trustedProxyRules = function_exists('trustedProxyRules') ? trustedProxyRules() : [];
$trustedProxyIpsConfigured = $trustedProxyRules !== [];
$trustedProxyConfigSource = function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'none';
$totalSupplierProducts = 0;
foreach ($connections as $connection) $totalSupplierProducts += (int) ($connection['product_count'] ?? 0);
$supplierCatalogStats = $schemaReady ? supplierBridgeGetManagedProductStats() : [
    'total' => 0, 'in_stock' => 0, 'out_of_stock' => 0, 'mapped' => 0, 'unmapped' => 0,
    'enabled' => 0, 'disabled' => 0, 'removed' => 0,
];
$supplierCategoryMappings = $schemaReady ? supplierBridgeGetCategoryMappings(0) : [];
$supplierCategoryCount = $schemaReady ? count(supplierBridgeGetManagedProductCategories(0)) : 0;
$storeBridgeKeyInfo = storeBridgeEncryptionKeyInfo();
?>
<!DOCTYPE html>
<html lang="<?php echo $isTh ? 'th' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($t('ศูนย์จัดการ API', 'API Hub')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind-built.css?v=20260903-3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.09)}
        .field{width:100%;border-radius:.65rem;border:1px solid rgba(255,255,255,.12);background:rgba(17,24,39,.85);padding:.65rem .8rem;color:#f3f4f6}
        .field:focus{outline:none;border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.15)}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border-radius:.65rem;padding:.62rem .9rem;font-weight:600;transition:.15s}
        .btn-primary{background:#7c3aed;color:white}.btn-primary:hover{background:#6d28d9}
        .btn-soft{background:rgba(255,255,255,.08);color:#e5e7eb}.btn-soft:hover{background:rgba(255,255,255,.13)}
        .btn-danger{background:rgba(239,68,68,.18);color:#fecaca}.btn-danger:hover{background:rgba(239,68,68,.28)}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:.2rem .55rem;font-size:.75rem;font-weight:700}
        summary{cursor:pointer;user-select:none}
        .guide-toggle .guide-when-open{display:none}
        .guide-toggle[open] .guide-when-closed{display:none}
        .guide-toggle[open] .guide-when-open{display:inline-flex}
    </style>
</head>
<body class="bg-[#0d0d10] text-gray-100 min-h-screen">
<?php include 'nav.php'; ?>
<main class="max-w-[1600px] mx-auto p-4 md:p-6 space-y-6">
    <section>
        <h1 class="text-2xl md:text-3xl font-bold flex items-center gap-3">
            <i class="bi bi-diagram-3 text-violet-400"></i>
            <?php echo $h($t('ศูนย์จัดการ API', 'API Hub')); ?>
        </h1>
        <p class="text-gray-400 mt-2 max-w-4xl">
            <?php echo $h($t(
                'เว็บไซต์นี้ทำหน้าที่เป็นได้ทั้งผู้ให้บริการ API และเว็บที่ดึงสินค้าจาก Supplier โดยใช้ตารางและประวัติแยกจาก CHEATGAME เดิม จึงไม่เอาคีย์สองระบบไปกองรวมกันแล้วหวังว่าฐานข้อมูลจะเข้าใจเอง',
                'This website can be both an API provider and a supplier consumer. Store Bridge tables and history are separate from the existing CHEATGAME integration.'
            )); ?>
        </p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="rounded-xl border border-red-500/40 bg-red-900/20 p-4 text-red-200"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $h($error); ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <div class="rounded-xl border border-emerald-500/40 bg-emerald-900/20 p-4 text-emerald-200"><i class="bi bi-check-circle mr-2"></i><?php echo $h($message); ?></div>
    <?php endif; ?>
    <?php if ($schemaReady && empty($resellerWalletReadiness['ready'])): ?>
        <div class="rounded-xl border border-amber-500/40 bg-amber-900/20 p-4 text-amber-100">
            <div class="font-bold"><i class="bi bi-exclamation-triangle mr-2"></i><?php echo $h($t('โหมดหักยอดบัญชีตัวแทนยังถูกล็อกเพื่อความปลอดภัย', 'Linked reseller-wallet billing is safety-locked.')); ?></div>
            <div class="text-sm mt-1 leading-relaxed"><?php echo $h($t('Store API แบบเครดิตเดิมยังใช้ได้ตามปกติ แต่ reseller_wallet จะยังสร้าง/สั่งซื้อไม่ได้จน transactions.type รองรับ store_api_purchase และ Wallet Ledger พร้อม เพื่อไม่ให้ยอดเงินหรือรายงานถูกตีความเป็นการซื้อคีย์ในเว็บซ้ำ', 'Legacy API-balance clients continue to work, but reseller_wallet creation/orders are blocked until transactions.type supports store_api_purchase and the Wallet Ledger is ready. This prevents wallet/reporting records from being misclassified as duplicate local purchases.')); ?></div>
            <?php if (empty($resellerWalletReadiness['transaction_type_ready'])): ?><a class="btn btn-soft !py-2 mt-3" href="transaction_integrity.php"><i class="bi bi-wrench-adjustable-circle"></i><?php echo $h($t('เปิด Transaction Integrity เพื่อ Backup/อัปเกรด Schema', 'Open Transaction Integrity to back up/migrate the schema')); ?></a><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($schemaReady && $ipRestrictedClientCount > 0 && !$trustedProxyIpsConfigured): ?>
        <div class="rounded-xl border border-amber-500/30 bg-amber-900/15 p-4 text-amber-100">
            <div class="font-bold"><i class="bi bi-router mr-2"></i><?php echo $h($t('มี API Client ใช้ IP Allowlist แต่ยังไม่พบ Trusted Proxy ที่กำหนดไว้', 'IP-restricted API clients exist, but no trusted proxy rules are configured.')); ?></div>
            <div class="text-sm mt-1 leading-relaxed"><?php echo $h($t('ถ้า Origin นี้อยู่หลัง Cloudflare/Reverse Proxy ต้องกำหนด IP/CIDR ของ Proxy ที่เชื่อถือได้ ผ่าน Environment TRUSTED_PROXY_IPS หรือไฟล์ private/trusted_proxies.php มิฉะนั้นระบบอาจเห็น REMOTE_ADDR ของ Proxy แทน IP จริง เช่น 103.70.5.234 และตอบ ip_not_allowed ห้ามแก้ด้วยการเชื่อ CF-Connecting-IP/X-Forwarded-For จากทุก IP', 'If this origin is behind Cloudflare/reverse proxy, configure trusted proxy IP/CIDR ranges through TRUSTED_PROXY_IPS or private/trusted_proxies.php. Otherwise the allowlist may see proxy REMOTE_ADDR instead of the real caller and return ip_not_allowed. Never trust forwarded headers from arbitrary remote addresses.')); ?></div>
        </div>
    <?php endif; ?>
    <?php if ($schemaReady && $trustedProxyIpsConfigured): ?>
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-900/10 p-4 text-emerald-100">
            <div class="font-bold"><i class="bi bi-router mr-2"></i><?php echo $h($t('Trusted Proxy พร้อมใช้งาน', 'Trusted proxy rules are configured.')); ?></div>
            <div class="text-sm mt-1"><?php echo $h($t('โหลดกฎ Proxy แล้ว ', 'Loaded ')); ?><?php echo count($trustedProxyRules); ?> CIDR/IP · source=<?php echo $h($trustedProxyConfigSource); ?>. <?php echo $h($t('ระบบจะอ่าน CF-Connecting-IP/X-Forwarded-For เฉพาะเมื่อ REMOTE_ADDR มาจาก Proxy ที่อยู่ในรายการนี้', 'Forwarded IP headers are trusted only when REMOTE_ADDR matches one of these proxy rules.')); ?></div>
        </div>
    <?php endif; ?>
    <?php if ($newKey !== ''): ?>
        <div class="rounded-xl border border-amber-400/50 bg-amber-900/20 p-5">
            <div class="font-bold text-amber-200"><i class="bi bi-key-fill mr-2"></i><?php echo $h($t('คัดลอก API Key ตอนนี้ ระบบไม่สามารถแสดงคีย์เต็มนี้อีกครั้ง', 'Copy this API key now. The full key cannot be displayed again.')); ?></div>
            <div class="mt-3 flex flex-col md:flex-row gap-2">
                <code id="generatedApiKey" class="flex-1 break-all rounded-lg bg-black/40 border border-white/10 p-3 text-sm text-amber-100"><?php echo $h($newKey); ?></code>
                <button type="button" class="btn btn-primary" onclick="copyText('generatedApiKey')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก', 'Copy')); ?></button>
            </div>
        </div>
    <?php endif; ?>

    <section class="glass rounded-xl p-5 md:p-6 border <?php echo !empty($storeBridgeKeyInfo['configured']) ? 'border-emerald-500/20' : 'border-red-500/30'; ?>">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold flex items-center gap-2"><i class="bi bi-shield-lock text-emerald-300"></i><?php echo $h($t('ตัวตนและกุญแจ Store Bridge', 'Store Bridge identity and key')); ?></h2>
                <p class="text-sm text-gray-400 mt-1"><?php echo $h($t('ข้อมูลนี้ช่วยยืนยันว่ากำลังใช้กุญแจของเว็บไซต์และฐานข้อมูลที่ถูกต้อง โดยไม่แสดงกุญแจเต็มบนหน้าเว็บ', 'This identifies the website and encryption key without exposing the full key in the browser.')); ?></p>
            </div>
            <span class="badge <?php echo !empty($storeBridgeKeyInfo['configured']) ? 'bg-emerald-500/15 text-emerald-200' : 'bg-red-500/15 text-red-200'; ?>"><?php echo !empty($storeBridgeKeyInfo['configured']) ? $h($t('พร้อมใช้งาน', 'Configured')) : $h($t('ไม่พบกุญแจ', 'Missing key')); ?></span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 mt-4 text-sm">
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('เว็บไซต์', 'Site ID')); ?></div><div class="mt-1 break-all"><?php echo $h($storeBridgeKeyInfo['site_id'] ?: '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('โดเมน', 'Domain')); ?></div><div class="mt-1 break-all"><?php echo $h($storeBridgeKeyInfo['site_domain'] ?: '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('รหัสกุญแจ', 'Key ID')); ?></div><div class="mt-1 break-all text-amber-200"><?php echo $h($storeBridgeKeyInfo['key_id'] ?: '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('ลายนิ้วมือ', 'Fingerprint')); ?></div><div class="mt-1 font-mono text-cyan-200"><?php echo $h($storeBridgeKeyInfo['fingerprint'] ?: '-'); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('แหล่งที่มา', 'Source')); ?></div><div class="mt-1"><?php echo $h($storeBridgeKeyInfo['source'] ?: '-'); ?><?php if ((int) ($storeBridgeKeyInfo['key_count'] ?? 0) > 1): ?><span class="text-xs text-gray-500"> · <?php echo (int) $storeBridgeKeyInfo['key_count']; ?> keys</span><?php endif; ?></div></div>
        </div>
        <?php if (($storeBridgeKeyInfo['source'] ?? '') === 'legacy_file'): ?>
            <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-100"><?php echo $h($t('ระบบยังอ่านกุญแจจาก private/store_bridge_secret.php กรุณาคัดลอกค่า encryption_key ไปยัง secrets.store_bridge.encryption_key_b64 ใน private/database.php ก่อนลบไฟล์เดิม', 'The key is still loaded from private/store_bridge_secret.php. Copy encryption_key into secrets.store_bridge.encryption_key_b64 in private/database.php before removing the legacy file.')); ?></div>
        <?php endif; ?>
    </section>

    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
        <div class="glass rounded-xl p-5"><div class="text-gray-400 text-sm"><?php echo $h($t('API Key ที่สร้าง', 'Issued API keys')); ?></div><div class="text-3xl font-bold mt-2"><?php echo count($clients); ?></div></div>
        <div class="glass rounded-xl p-5"><div class="text-gray-400 text-sm"><?php echo $h($t('เครดิต API แบบเดิมรวม', 'Legacy API credit total')); ?></div><div class="text-3xl font-bold mt-2 text-emerald-300"><?php echo $h(formatCurrency($totalProviderBalance)); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo $h($t('ไม่นับยอดบัญชีตัวแทน', 'Excludes reseller wallets')); ?></div></div>
        <div class="glass rounded-xl p-5"><div class="text-gray-400 text-sm"><?php echo $h($t('API ที่หักบัญชีตัวแทน', 'Linked-wallet API clients')); ?></div><div class="text-3xl font-bold mt-2 text-amber-300"><?php echo (int) $linkedWalletClientCount; ?></div></div>
        <div class="glass rounded-xl p-5"><div class="text-gray-400 text-sm"><?php echo $h($t('Supplier ที่เชื่อม', 'Supplier connections')); ?></div><div class="text-3xl font-bold mt-2"><?php echo count($connections); ?></div></div>
        <div class="glass rounded-xl p-5"><div class="text-gray-400 text-sm"><?php echo $h($t('สินค้าจาก Supplier', 'Supplier products')); ?></div><div class="text-3xl font-bold mt-2 text-sky-300"><?php echo $totalSupplierProducts; ?></div></div>
    </section>

    <section class="glass rounded-xl p-5 md:p-6 border border-sky-500/20">
        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold flex items-center gap-2"><i class="bi bi-boxes text-sky-300"></i><?php echo $h($t('Bridge Catalog Manager', 'Bridge Catalog Manager')); ?></h2>
                <p class="text-sm text-gray-400 mt-2 max-w-4xl"><?php echo $h($t(
                    'ศูนย์เดียวสำหรับสร้างสินค้า API-only, รวมหลาย API เข้ากับ Variant เดียว, ตั้ง Source Priority, จัดหมวดหมู่ API → หน้าร้าน และตรวจสต็อก โดยสินค้า API-only ไม่ต้องมีคีย์ปลอมในตาราง keys',
                    'One place to create API-only products, merge multiple APIs into one variant, set source priority, route supplier categories to storefront categories, and verify stock. API-only items do not require fake rows in keys.'
                )); ?></p>
            </div>
            <a class="btn btn-primary shrink-0" href="api_products.php"><i class="bi bi-box-arrow-up-right"></i><?php echo $h($t('เปิด Catalog Manager', 'Open Catalog Manager')); ?></a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mt-4 text-sm">
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('สินค้า API', 'API items')); ?></div><div class="text-2xl font-bold mt-1"><?php echo (int) ($supplierCatalogStats['total'] ?? 0); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('มีสต็อก', 'In stock')); ?></div><div class="text-2xl font-bold mt-1 text-emerald-300"><?php echo (int) ($supplierCatalogStats['in_stock'] ?? 0); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('รวมหน้าร้านแล้ว', 'Mapped')); ?></div><div class="text-2xl font-bold mt-1 text-sky-300"><?php echo (int) ($supplierCatalogStats['mapped'] ?? 0); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('ยังไม่รวม', 'Unmapped')); ?></div><div class="text-2xl font-bold mt-1 text-amber-300"><?php echo (int) ($supplierCatalogStats['unmapped'] ?? 0); ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('หมวด API', 'API categories')); ?></div><div class="text-2xl font-bold mt-1"><?php echo (int) $supplierCategoryCount; ?></div></div>
            <div class="rounded-lg bg-black/20 p-3"><div class="text-gray-500"><?php echo $h($t('กฎหมวดหมู่', 'Category rules')); ?></div><div class="text-2xl font-bold mt-1 text-violet-300"><?php echo count($supplierCategoryMappings); ?></div></div>
        </div>
        <?php if ($connections !== []): ?>
            <div class="flex flex-wrap gap-2 mt-4">
                <?php foreach ($connections as $connection): ?>
                    <a class="btn btn-soft !py-2" href="api_products.php?connection_id=<?php echo (int) $connection['id']; ?>">
                        <i class="bi bi-hdd-network"></i><?php echo $h($connection['name']); ?> · <?php echo (int) ($connection['product_count'] ?? 0); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="glass rounded-xl p-5 md:p-6">
        <h2 class="text-xl font-bold flex items-center gap-2"><i class="bi bi-broadcast-pin text-violet-400"></i><?php echo $h($t('Endpoint ของเว็บไซต์นี้', 'This website provider endpoint')); ?></h2>
        <p class="text-sm text-gray-400 mt-2"><?php echo $h($t('นำ URL นี้ไปกรอกในเว็บปลายทาง พร้อม API Key ที่สร้างจากส่วนถัดไป', 'Use this URL on the destination website together with a key created below.')); ?></p>
        <div class="mt-3 flex flex-col md:flex-row gap-2">
            <code id="providerEndpoint" class="flex-1 break-all rounded-lg bg-black/35 border border-white/10 p-3 text-sm text-violet-100"><?php echo $h($providerEndpoint); ?></code>
            <button type="button" class="btn btn-soft" onclick="copyText('providerEndpoint')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก URL', 'Copy URL')); ?></button>
        </div>
    </section>

    <section class="glass rounded-xl p-5 md:p-6 border border-emerald-500/20">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-5">
            <div class="max-w-4xl">
                <h2 class="text-xl font-bold flex items-center gap-2"><i class="bi bi-people text-emerald-300"></i><?php echo $h($t('โปรแกรม Store API สำหรับบัญชีตัวแทน', 'Reseller Store API program')); ?></h2>
                <p class="text-sm text-gray-400 mt-2"><?php echo $h($t(
                    'ควบคุมหน้า Developer API และการสร้างคีย์แบบ Self-service ของตัวแทน ระบบ backend จะตรวจโหมดนี้จริง ไม่ใช่แค่ซ่อนเมนูสวย ๆ แล้วปล่อย Endpoint เปิดอยู่เงียบ ๆ',
                    'Controls reseller self-service Developer API access. Backend authentication enforces the program mode; hiding the menu alone is not treated as security.'
                )); ?></p>
                <div class="mt-3 text-xs text-gray-500 leading-relaxed">
                    <?php echo $h($t('OFF = ปิดคีย์แบบ self-service ทั้งหมด · PILOT = ใช้ได้เฉพาะ User ID ที่กำหนด · ALL = ตัวแทนทุกบัญชีที่ Active ใช้ได้', 'OFF = all self-service keys blocked · PILOT = only listed user IDs · ALL = every active reseller account.')); ?>
                </div>
            </div>
            <span class="badge <?php echo ($resellerApiSettings['mode'] ?? 'off') === 'all' ? 'bg-emerald-500/20 text-emerald-200' : (($resellerApiSettings['mode'] ?? 'off') === 'pilot' ? 'bg-amber-500/20 text-amber-200' : 'bg-red-500/20 text-red-200'); ?>"><?php echo $h(strtoupper((string) ($resellerApiSettings['mode'] ?? 'off'))); ?></span>
        </div>
        <form method="post" class="grid grid-cols-1 lg:grid-cols-4 gap-4 mt-5">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="update_reseller_api_program">
            <label class="text-sm text-gray-300"><?php echo $h($t('โหมดการเปิดใช้งาน', 'Program mode')); ?>
                <select class="field mt-1" name="program_mode">
                    <option value="off" <?php echo ($resellerApiSettings['mode'] ?? 'off') === 'off' ? 'selected' : ''; ?>>OFF</option>
                    <option value="pilot" <?php echo ($resellerApiSettings['mode'] ?? '') === 'pilot' ? 'selected' : ''; ?>>PILOT</option>
                    <option value="all" <?php echo ($resellerApiSettings['mode'] ?? '') === 'all' ? 'selected' : ''; ?>>ALL RESELLERS</option>
                </select>
            </label>
            <label class="lg:col-span-3 text-sm text-gray-300"><?php echo $h($t('Pilot User IDs (คั่นด้วย comma/เว้นวรรค)', 'Pilot reseller user IDs (comma/space separated)')); ?>
                <input class="field mt-1" name="pilot_user_ids" value="<?php echo $h((string) ($resellerApiSettings['pilot_raw'] ?? '')); ?>" placeholder="27, 42, 105">
            </label>
            <label class="text-sm text-gray-300"><?php echo $h($t('Request เริ่มต้น / นาที', 'Default requests / minute')); ?><input class="field mt-1" type="number" min="10" max="5000" name="default_rate_limit" value="<?php echo (int) ($resellerApiSettings['default_rate_limit'] ?? 120); ?>"></label>
            <label class="text-sm text-gray-300"><?php echo $h($t('Order เริ่มต้น / นาที', 'Default orders / minute')); ?><input class="field mt-1" type="number" min="1" max="1000" name="default_order_rate_limit" value="<?php echo (int) ($resellerApiSettings['default_order_rate_limit'] ?? 10); ?>"></label>
            <label class="text-sm text-gray-300"><?php echo $h($t('วงเงินสูงสุดต่อ Order', 'Default max amount / order')); ?><input class="field mt-1" type="number" step="0.01" min="0" name="default_max_order_amount" value="<?php echo $h(number_format((float) ($resellerApiSettings['default_max_order_amount'] ?? 0), 2, '.', '')); ?>"><span class="text-xs text-gray-500 mt-1 block">0 = <?php echo $h($t('ไม่จำกัด', 'unlimited')); ?></span></label>
            <label class="text-sm text-gray-300"><?php echo $h($t('วงเงินต่อวัน', 'Default daily spend limit')); ?><input class="field mt-1" type="number" step="0.01" min="0" name="default_daily_spend_limit" value="<?php echo $h(number_format((float) ($resellerApiSettings['default_daily_spend_limit'] ?? 0), 2, '.', '')); ?>"><span class="text-xs text-gray-500 mt-1 block">0 = <?php echo $h($t('ไม่จำกัด', 'unlimited')); ?></span></label>
            <div class="lg:col-span-4 text-xs text-gray-500 -mt-1"><?php echo $h($t('ค่าเหล่านี้ใช้ตอนตัวแทนสร้าง Self-service API Key ใหม่เท่านั้น Client ที่มีอยู่แล้วแก้ได้รายตัวในตารางด้านล่าง', 'These defaults apply when a reseller creates a new self-service API key. Existing clients keep their current limits and can be edited individually below.')); ?></div>
            <label class="rounded-lg border border-white/10 bg-black/20 p-3 text-sm"><input type="checkbox" class="mr-2" name="menu_visible" <?php echo !empty($resellerApiSettings['menu_visible']) ? 'checked' : ''; ?>><?php echo $h($t('แสดงเมนู Developer API ให้บัญชีที่มีสิทธิ์', 'Show Developer API menu to eligible resellers')); ?></label>
            <label class="rounded-lg border border-white/10 bg-black/20 p-3 text-sm"><input type="checkbox" class="mr-2" name="key_generation" <?php echo !empty($resellerApiSettings['key_generation']) ? 'checked' : ''; ?>><?php echo $h($t('อนุญาตสร้าง/Regenerate API Key', 'Allow API key create/regenerate')); ?></label>
            <div class="lg:col-span-2 flex items-center"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึกการเปิดใช้งาน', 'Save program settings')); ?></button></div>
        </form>
        <div class="mt-4 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-100 leading-relaxed">
            <?php echo $h($t('หมายเหตุ: API Client ที่แอดมินสร้างเอง (client_type=admin) จะไม่ถูกปิดตามโปรแกรม Self-service นี้ เพื่อไม่ทำให้ API ระหว่างสองเว็บไซต์เดิมของคุณดับโดยไม่ได้ตั้งใจ', 'Note: admin-issued API clients are not disabled by this self-service program switch, preserving existing website-to-website integrations.')); ?>
        </div>
    </section>

    <section class="grid grid-cols-1 2xl:grid-cols-2 gap-6">
        <div class="glass rounded-xl p-5 md:p-6">
            <h2 class="text-xl font-bold mb-4"><i class="bi bi-key mr-2 text-amber-300"></i><?php echo $h($t('สร้าง API Key ให้เว็บอื่น', 'Issue an API key')); ?></h2>
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="create_client">
                <label class="md:col-span-2 text-sm text-gray-300"><?php echo $h($t('ชื่อเว็บไซต์/ลูกค้า', 'Website or client name')); ?><input class="field mt-1" name="name" maxlength="190" required placeholder="online.spwz.online"></label>
                <label class="md:col-span-2 text-sm text-gray-300"><?php echo $h($t('วิธีหักเงิน', 'Billing mode')); ?>
                    <select class="field mt-1" name="billing_mode" id="newBillingMode" data-billing-mode>
                        <option value="api_balance"><?php echo $h($t('ยอดเครดิต API (ระบบเดิม)', 'API balance (legacy)')); ?></option>
                        <option value="reseller_wallet"><?php echo $h($t('ยอดเงินบัญชีตัวแทนบนเว็บไซต์', 'Linked reseller website wallet')); ?></option>
                    </select>
                </label>
                <label id="newLinkedResellerWrap" class="md:col-span-2 text-sm text-gray-300 hidden"><?php echo $h($t('ผูกกับบัญชีตัวแทน', 'Linked reseller account')); ?>
                    <select class="field mt-1" name="linked_user_id">
                        <option value="0"><?php echo $h($t('เลือกบัญชีตัวแทน', 'Select reseller account')); ?></option>
                        <?php foreach ($resellerAccounts as $reseller): ?>
                            <option value="<?php echo (int) $reseller['id']; ?>" <?php echo (string) ($reseller['status'] ?? '') !== 'active' ? 'disabled' : ''; ?>>#<?php echo (int) $reseller['id']; ?> <?php echo $h($reseller['username']); ?> · <?php echo $h(formatCurrency((float) $reseller['balance'])); ?><?php echo (string) ($reseller['status'] ?? '') !== 'active' ? ' · INACTIVE' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-xs text-gray-500 mt-1"><?php echo $h($t('โหมดนี้หัก users.balance โดยตรง และใช้ราคาตัวแทนจริงของบัญชี รวมราคาพิเศษราย Variant', 'This mode debits users.balance and uses the linked account effective reseller price, including per-variant overrides.')); ?></div>
                </label>
                <label class="text-sm text-gray-300" data-api-balance-only><?php echo $h($t('ระดับราคา (ใช้กับเครดิต API)', 'Price tier (API balance mode)')); ?>
                    <select class="field mt-1" name="price_tier" data-api-balance-input><option value="reseller"><?php echo $h($t('ราคาตัวแทน', 'Reseller price')); ?></option><option value="user"><?php echo $h($t('ราคาผู้ใช้', 'User price')); ?></option><option value="cost"><?php echo $h($t('ราคาทุน', 'Cost price')); ?></option></select>
                </label>
                <label class="text-sm text-gray-300" data-api-balance-only><?php echo $h($t('ตัวคูณราคา (ใช้กับเครดิต API)', 'Price multiplier (API balance mode)')); ?><input class="field mt-1" type="number" step="0.0001" min="0.01" max="100" name="price_multiplier" data-api-balance-input value="1.0000" required></label>
                <label class="text-sm text-gray-300" data-api-balance-only><?php echo $h($t('ยอดเริ่มต้น (เฉพาะเครดิต API)', 'Opening balance (API balance only)')); ?><input class="field mt-1" type="number" step="0.01" min="0" name="balance" value="0" required data-api-balance-input></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('จำกัดคำขอทั้งหมด/นาที', 'All requests per minute')); ?><input class="field mt-1" type="number" min="10" max="5000" name="rate_limit_per_minute" value="120" required></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('จำกัดคำสั่งซื้อ/นาที', 'Order requests per minute')); ?><input class="field mt-1" type="number" min="1" max="1000" name="order_rate_limit_per_minute" value="10" required></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('ยอดสูงสุดต่อ Order (0 = ไม่จำกัด)', 'Maximum amount per order (0 = unlimited)')); ?><input class="field mt-1" type="number" step="0.01" min="0" name="max_order_amount" value="0"></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('วงเงินซื้อ/วัน (0 = ไม่จำกัด)', 'Daily spending limit (0 = unlimited)')); ?><input class="field mt-1" type="number" step="0.01" min="0" name="daily_spend_limit" value="0"></label>
                <label class="md:col-span-2 text-sm text-gray-300"><?php echo $h($t('IP/CIDR ที่อนุญาต (เว้นว่าง = ไม่จำกัด IP, คั่นด้วยบรรทัดหรือ comma)', 'Allowed server IP/CIDR (blank = unrestricted, newline or comma separated)')); ?><textarea class="field mt-1" rows="3" name="allowed_ips" placeholder="103.70.5.234
203.0.113.0/24"></textarea></label>
                <div class="md:col-span-2 rounded-lg border border-sky-500/20 bg-sky-500/10 p-3 text-xs text-sky-100"><?php echo $h($t('Billing Mode ถูกล็อกไว้กับ API Client ฝั่งเรา ผู้เรียก API ไม่สามารถส่งพารามิเตอร์มาเลือกว่าจะหักกระเป๋าไหนในแต่ละ Order ได้', 'Billing mode is fixed on the provider-side API client. The caller cannot choose a different wallet per order.')); ?></div>
                <div class="md:col-span-2"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i><?php echo $h($t('สร้าง API Key', 'Create API key')); ?></button></div>
            </form>
        </div>

        <div class="glass rounded-xl p-5 md:p-6">
            <h2 class="text-xl font-bold mb-4"><i class="bi bi-cloud-download mr-2 text-sky-300"></i><?php echo $h($t('เพิ่ม API Supplier', 'Add supplier API')); ?></h2>
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="create_connection">
                <label class="text-sm text-gray-300"><?php echo $h($t('ชื่อ Supplier', 'Supplier name')); ?><input class="field mt-1" name="name" maxlength="190" required placeholder="Sakazuki Main"></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('หมวดหมู่/โปรโตคอล API', 'API category/protocol')); ?>
                    <select class="field mt-1" name="provider_type">
                        <?php foreach ($providerTypes as $typeKey => $typeData): ?><option value="<?php echo $h($typeKey); ?>"><?php echo $h($isTh ? $typeData['label_th'] : $typeData['label_en']); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="md:col-span-2 text-sm text-gray-300"><?php echo $h($t('Endpoint URL ของเว็บต้นทาง', 'Source website endpoint URL')); ?><input class="field mt-1" type="url" name="endpoint_url" maxlength="1000" required placeholder="https://starkmods.my.id/"></label>
                <label class="md:col-span-2 text-sm text-gray-300"><?php echo $h($t('Credential จากเว็บต้นทาง', 'Source provider credential')); ?><input class="field mt-1 font-mono" type="password" name="api_key" maxlength="500" autocomplete="new-password" required><span class="block mt-1 text-xs text-gray-500"><?php echo $h($t('Sakazuki ใช้ API Key ตามเดิม · VIPSTORE ใช้ JSON {email,password,currency} · StarkMods ใช้ JSON {username,password,currency} ถ้าหน่วยราคาต้นทางต่างจากสกุลเว็บให้เพิ่ม local_rate (สกุลเว็บต่อ 1 หน่วย Supplier) ระบบเข้ารหัสก่อนเก็บ', 'Sakazuki uses its API key as before. VIPSTORE uses JSON {email,password,currency}; StarkMods uses JSON {username,password,currency}. When source price units differ from the website currency, also set local_rate (local currency per supplier unit). The credential is encrypted before storage.')); ?></span></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('ลำดับความสำคัญ (เลขน้อยมาก่อน)', 'Priority (lower first)')); ?><input class="field mt-1" type="number" name="priority" value="100" required></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('โหมดการซื้อ', 'Purchase mode')); ?><select class="field mt-1" name="purchase_mode"><option value="live"><?php echo $h($t('Live - ซื้อจริง', 'Live - real purchases')); ?></option><option value="test"><?php echo $h($t('Test - เฉพาะ Admin', 'Test - admin only')); ?></option><option value="disabled"><?php echo $h($t('Disabled - ไม่ซื้อ', 'Disabled - no purchases')); ?></option></select><span class="block mt-1 text-xs text-amber-300/80"><?php echo $h($t('VIPSTORE และ StarkMods ใหม่จะถูกบังคับเป็น Disabled ครั้งแรก ไม่ว่าค่านี้เป็นอะไร เพื่อกันเปิดซื้อจริงโดยไม่ตั้งใจ', 'New VIPSTORE and StarkMods connections are forced to Disabled on first save to prevent accidental live purchasing.')); ?></span></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('ราคาผู้ใช้เริ่มต้น', 'Default user pricing')); ?><select class="field mt-1" name="user_price_mode"><option value="source"><?php echo $h($t('ตามราคาแนะนำเว็บต้นทาง', 'Match source suggested price')); ?></option><option value="markup"><?php echo $h($t('ต้นทุน API + เปอร์เซ็นต์', 'API cost + markup')); ?></option><option value="keep"><?php echo $h($t('คงราคาปัจจุบัน', 'Keep current local price')); ?></option></select></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('ราคาตัวแทนเริ่มต้น', 'Default reseller pricing')); ?><select class="field mt-1" name="reseller_price_mode"><option value="source"><?php echo $h($t('ตามราคาแนะนำเว็บต้นทาง', 'Match source suggested price')); ?></option><option value="markup"><?php echo $h($t('ต้นทุน API + เปอร์เซ็นต์', 'API cost + markup')); ?></option><option value="keep"><?php echo $h($t('คงราคาปัจจุบัน', 'Keep current local price')); ?></option></select></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('กำไรผู้ใช้เมื่อใช้โหมดบวกเปอร์เซ็นต์ (%)', 'User markup when markup mode (%)')); ?><input class="field mt-1" type="number" step="0.01" min="0" max="10000" name="user_markup_percent" value="20" required></label>
                <label class="text-sm text-gray-300"><?php echo $h($t('กำไรตัวแทนเมื่อใช้โหมดบวกเปอร์เซ็นต์ (%)', 'Reseller markup when markup mode (%)')); ?><input class="field mt-1" type="number" step="0.01" min="0" max="10000" name="reseller_markup_percent" value="10" required></label>
                <div class="md:col-span-2 text-sm text-gray-300 grid grid-cols-1 md:grid-cols-2 gap-2 pt-2">
                    <label><input type="checkbox" name="auto_publish" checked class="mr-2"><?php echo $h($t('สร้าง/รวมสินค้าเข้าหน้าร้านอัตโนมัติ', 'Auto-publish or merge into storefront')); ?></label>
                    <label><input type="checkbox" name="sync_details" checked class="mr-2"><?php echo $h($t('ซิงก์ชื่อ/รายละเอียด/รูป', 'Sync name/details/image')); ?></label>
                    <label><input type="checkbox" name="sync_prices" checked class="mr-2"><?php echo $h($t('ใช้กฎราคาอัตโนมัติทุกครั้งที่ซิงก์', 'Apply pricing rules on every sync')); ?></label>
                    <label><input type="checkbox" name="protect_below_cost" checked class="mr-2"><?php echo $h($t('ป้องกันราคาขายต่ำกว่าทุน API', 'Prevent selling below API cost')); ?></label>
                </div>
                <div class="md:col-span-2"><button class="btn btn-primary" type="submit"><i class="bi bi-plug"></i><?php echo $h($t('บันทึกและทดสอบการเชื่อมต่อ', 'Save and test connection')); ?></button></div>
            </form>
        </div>
    </section>

    <section class="glass rounded-xl overflow-hidden">
        <div class="p-5 border-b border-white/10">
            <h2 class="text-xl font-bold"><i class="bi bi-key-fill mr-2 text-amber-300"></i><?php echo $h($t('API Key ที่ออกให้', 'Issued API clients')); ?></h2>
            <p class="text-xs text-gray-500 mt-2"><?php echo $h($t('API balance = เครดิตแยกแบบเดิม · Reseller wallet = หักยอด users.balance ของบัญชีที่ผูกแบบสด ๆ', 'API balance = legacy separate credit · Reseller wallet = live debit from the linked users.balance.')); ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1320px] text-sm">
                <thead class="bg-white/5 text-gray-300"><tr><th class="p-3 text-left">ID / <?php echo $h($t('ชื่อ', 'Name')); ?></th><th class="p-3 text-left">Key</th><th class="p-3 text-left"><?php echo $h($t('การหักเงิน', 'Billing')); ?></th><th class="p-3 text-left"><?php echo $h($t('ราคา', 'Pricing')); ?></th><th class="p-3 text-right"><?php echo $h($t('ยอดคงเหลือ', 'Balance')); ?></th><th class="p-3 text-center"><?php echo $h($t('คำสั่งซื้อ', 'Orders')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ', 'Status')); ?></th><th class="p-3 text-left"><?php echo $h($t('จัดการ', 'Actions')); ?></th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if ($clients === []): ?><tr><td colspan="8" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มี API Key', 'No API clients yet.')); ?></td></tr><?php endif; ?>
                <?php foreach ($clients as $client): ?>
                    <?php
                        $clientBilling = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
                        $clientIsWallet = $clientBilling === 'reseller_wallet';
                        $clientSourceAccess = storeBridgeClientSourceAccess($client);
                        $clientSupplierIds = array_fill_keys(storeBridgeClientAllowedSupplierConnectionIds($client), true);
                    ?>
                    <tr class="align-top">
                        <td class="p-3">
                            <div class="font-semibold">#<?php echo (int) $client['id']; ?> <?php echo $h($client['name']); ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?php echo $h((string) ($client['client_type'] ?? 'admin')); ?> · <?php echo $h((string) ($client['last_used_at'] ?: $t('ยังไม่เคยใช้งาน', 'Never used'))); ?></div>
                            <?php if (trim((string) ($client['website_name'] ?? '')) !== ''): ?><div class="text-xs text-violet-200 mt-2"><i class="bi bi-globe2 mr-1"></i><?php echo $h((string) $client['website_name']); ?></div><?php endif; ?>
                            <?php if (trim((string) ($client['website_url'] ?? '')) !== ''): ?><div class="text-[11px] text-gray-500 mt-1 break-all"><?php echo $h((string) $client['website_url']); ?></div><?php endif; ?>
                            <?php if (trim((string) ($client['webhook_url'] ?? '')) !== ''): ?>
                                <?php $webhookHttp = (int) ($client['webhook_last_http_code'] ?? 0); $webhookOk = $webhookHttp >= 200 && $webhookHttp < 300; ?>
                                <div class="text-[11px] mt-2 <?php echo $webhookOk ? 'text-emerald-300' : ($webhookHttp > 0 ? 'text-red-300' : 'text-amber-300'); ?>">
                                    <i class="bi bi-broadcast mr-1"></i>Webhook <?php echo !empty($client['webhook_last_test_at']) ? $h((string) $client['webhook_last_test_at']) : $h($t('ยังไม่เคยทดสอบ', 'not tested')); ?><?php if ($webhookHttp > 0): ?> · HTTP <?php echo $webhookHttp; ?><?php endif; ?>
                                </div>
                                <?php if (!empty($client['webhook_last_error'])): ?><div class="text-[11px] text-red-300/90 mt-1 break-words"><?php echo $h((string) $client['webhook_last_error']); ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 font-mono text-xs"><?php echo $h($client['key_prefix'] . '••••' . $client['key_last4']); ?></td>
                        <td class="p-3">
                            <span class="badge <?php echo $clientIsWallet ? 'bg-amber-500/15 text-amber-200' : 'bg-sky-500/15 text-sky-200'; ?>"><?php echo $h($clientBilling); ?></span>
                            <?php if ($clientIsWallet): ?><div class="text-xs mt-2 text-gray-300">#<?php echo (int) ($client['linked_user_id'] ?? 0); ?> <?php echo $h((string) ($client['linked_username'] ?? '-')); ?></div><div class="text-xs text-gray-500"><?php echo $h((string) ($client['linked_email'] ?? '')); ?> · <?php echo $h((string) ($client['linked_user_status'] ?? 'missing')); ?></div><?php endif; ?>
                        </td>
                        <td class="p-3">
                            <?php if ($clientIsWallet): ?><div class="text-amber-200"><?php echo $h($t('ราคาตัวแทนของบัญชี', 'Linked account effective price')); ?></div><div class="text-xs text-gray-500"><?php echo $h($t('รวมราคาพิเศษราย Variant', 'Includes per-variant overrides')); ?></div><?php else: ?><?php echo $h($client['price_tier']); ?> × <?php echo $h(number_format((float) $client['price_multiplier'], 4)); ?><?php endif; ?>
                        </td>
                        <td class="p-3 text-right font-semibold text-emerald-300">
                            <?php $displayBalance = $clientIsWallet ? (float) ($client['linked_wallet_balance'] ?? 0) : (float) ($client['balance'] ?? 0); ?>
                            <?php echo $h(formatCurrency($displayBalance)); ?><div class="text-xs text-gray-500"><?php echo $h($client['currency']); ?> · <?php echo $h($clientIsWallet ? $t('บัญชีตัวแทน', 'reseller wallet') : $t('เครดิต API', 'API credit')); ?></div>
                        </td>
                        <td class="p-3 text-center"><?php echo (int) $client['order_count']; ?><div class="text-xs text-gray-500"><?php echo $h(formatCurrency((float) $client['total_spent'])); ?></div></td>
                        <td class="p-3"><span class="badge <?php echo $client['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>"><?php echo $h($client['status']); ?></span><div class="text-xs text-gray-500 mt-2">all <?php echo (int) $client['rate_limit_per_minute']; ?>/min · order <?php echo (int) ($client['order_rate_limit_per_minute'] ?? 10); ?>/min</div></td>
                        <td class="p-3 space-y-2 min-w-[500px]">
                            <?php if (!$clientIsWallet): ?><div class="flex flex-wrap gap-2"><a class="btn btn-primary !py-2" href="api_client_products.php?client_id=<?php echo (int) $client['id']; ?>"><i class="bi bi-tags"></i><?php echo $h($t('กำหนดราคาสินค้ารายชิ้น', 'Per-product API pricing')); ?></a></div><?php else: ?><div class="text-xs rounded-lg border border-amber-500/20 bg-amber-500/10 p-2 text-amber-100"><?php echo $h($t('โหมดบัญชีตัวแทนใช้สินค้าที่ Store API เปิดให้และราคาตัวแทนของบัญชีที่ผูก โดยไม่ใช้ Custom API price/Multiplier เดิม แหล่งสต็อกใช้ LOCAL ก่อน แล้วจึง CGO/Supplier เฉพาะแหล่งที่ Admin อนุญาตให้ API Client นี้', 'Linked-wallet mode uses the active Store API catalogue and linked reseller pricing, ignoring legacy custom API price/multiplier. Fulfillment is LOCAL first, then only CGO/supplier sources explicitly allowed for this API client.')); ?></div><?php endif; ?>
                            <?php if (trim((string) ($client['webhook_url'] ?? '')) !== ''): ?>
                                <?php
                                    $webhookDebugRaw = trim((string) ($client['webhook_last_debug_json'] ?? ''));
                                    if ($webhookDebugRaw === '') {
                                        $legacyDebug = [
                                            'debug_version' => 0,
                                            'note' => 'Detailed webhook debug was not stored by the previous version. Run Test Webhook again.',
                                            'client_id' => (int) $client['id'],
                                            'last_test_at' => $client['webhook_last_test_at'] ?? null,
                                            'http_code' => isset($client['webhook_last_http_code']) ? (int) $client['webhook_last_http_code'] : null,
                                            'last_error' => (string) ($client['webhook_last_error'] ?? ''),
                                        ];
                                        $webhookDebugRaw = (string) json_encode($legacyDebug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    } else {
                                        $decodedWebhookDebug = json_decode($webhookDebugRaw, true);
                                        if (is_array($decodedWebhookDebug)) {
                                            $prettyWebhookDebug = json_encode($decodedWebhookDebug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                                            if (is_string($prettyWebhookDebug)) $webhookDebugRaw = $prettyWebhookDebug;
                                        }
                                    }
                                    $webhookDebugId = 'webhookDebug' . (int) $client['id'];
                                    $webhookHttpCode = (int) ($client['webhook_last_http_code'] ?? 0);
                                ?>
                                <div class="rounded-lg border <?php echo $webhookHttpCode >= 200 && $webhookHttpCode < 300 ? 'border-emerald-500/25 bg-emerald-500/10' : 'border-red-500/25 bg-red-500/10'; ?> p-3">
                                    <div class="flex flex-wrap items-center gap-2 justify-between">
                                        <div>
                                            <div class="text-xs font-bold"><?php echo $h($t('Webhook Server-to-Server Debug', 'Webhook server-to-server debug')); ?></div>
                                            <div class="text-[11px] text-gray-400 mt-1 break-all"><?php echo $h((string) $client['webhook_url']); ?></div>
                                            <?php if ($webhookHttpCode === 403): ?><div class="text-[11px] text-amber-200 mt-2"><?php echo $h($t('HTTP 403 = เชื่อมถึง Server ปลายทางแล้ว แต่ปลายทางปฏิเสธ Request ให้ตรวจ Route/Auth Middleware/WAF/CSRF ของ DEVNOOD ไม่ใช่ TCP Timeout', 'HTTP 403 means the target server was reached but rejected the request. Check the DEVNOOD route, auth middleware, WAF or CSRF. This is not a TCP timeout.')); ?></div><?php endif; ?>
                                        </div>
                                        <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="test_client_webhook"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>"><button class="btn btn-primary !py-2" type="submit"><i class="bi bi-broadcast"></i><?php echo $h($t('ทดสอบ Webhook ใหม่', 'Retest webhook')); ?></button></form>
                                    </div>
                                    <details class="mt-3 rounded-lg border border-white/10 bg-black/20">
                                        <summary class="p-2 text-xs cursor-pointer"><?php echo $h($t('ดู Debug JSON / Response Header / Body', 'View Debug JSON / response headers / body')); ?></summary>
                                        <div class="p-3 border-t border-white/10">
                                            <pre id="<?php echo $h($webhookDebugId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[520px] overflow-auto"><?php echo $h($webhookDebugRaw); ?></pre>
                                            <button type="button" class="btn btn-soft !py-2 mt-2" onclick="copyText('<?php echo $h($webhookDebugId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก Debug JSON', 'Copy Debug JSON')); ?></button>
                                        </div>
                                    </details>
                                </div>
                            <?php endif; ?>
                            <details class="rounded-lg border border-white/10 bg-black/20"><summary class="p-2 text-xs text-gray-300"><?php echo $h($t('แก้ไขข้อมูลคีย์และข้อจำกัด', 'Edit client and limits')); ?></summary>
                                <form method="post" class="p-3 grid grid-cols-2 gap-2">
                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="update_client"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>">
                                    <input class="field !py-2 col-span-2" name="name" value="<?php echo $h($client['name']); ?>" required>
                                    <select class="field !py-2 col-span-2" name="billing_mode" data-billing-mode><option value="api_balance" <?php echo !$clientIsWallet?'selected':''; ?>><?php echo $h($t('ยอดเครดิต API (ระบบเดิม)', 'API balance (legacy)')); ?></option><option value="reseller_wallet" <?php echo $clientIsWallet?'selected':''; ?>><?php echo $h($t('ยอดบัญชีตัวแทนเว็บไซต์', 'Linked reseller wallet')); ?></option></select>
                                    <select class="field !py-2 col-span-2" name="linked_user_id" data-reseller-wallet-input><option value="0"><?php echo $h($t('ไม่ผูกบัญชี / เลือกบัญชี', 'No link / select reseller')); ?></option><?php foreach ($resellerAccounts as $reseller): ?><option value="<?php echo (int) $reseller['id']; ?>" <?php echo (int) ($client['linked_user_id'] ?? 0)===(int)$reseller['id']?'selected':''; ?> <?php echo ((string) ($reseller['status'] ?? '') !== 'active' && (int) ($client['linked_user_id'] ?? 0)!==(int)$reseller['id']) ? 'disabled' : ''; ?>>#<?php echo (int) $reseller['id']; ?> <?php echo $h($reseller['username']); ?> · <?php echo $h(formatCurrency((float) $reseller['balance'])); ?></option><?php endforeach; ?></select>
                                    <select class="field !py-2" name="price_tier" data-api-balance-input><option value="reseller" <?php echo $client['price_tier']==='reseller'?'selected':''; ?>><?php echo $h($t('ราคาตัวแทน', 'Reseller')); ?></option><option value="user" <?php echo $client['price_tier']==='user'?'selected':''; ?>><?php echo $h($t('ราคาผู้ใช้', 'User')); ?></option><option value="cost" <?php echo $client['price_tier']==='cost'?'selected':''; ?>><?php echo $h($t('ราคาทุน', 'Cost')); ?></option></select>
                                    <input class="field !py-2" type="number" step="0.0001" min="0.01" max="100" name="price_multiplier" data-api-balance-input value="<?php echo $h($client['price_multiplier']); ?>" required>
                                    <input class="field !py-2 col-span-2" name="allowed_ips" value="<?php echo $h($client['allowed_ips']); ?>" placeholder="IP/CIDR; blank = unrestricted">
                                    <label class="text-[11px] text-gray-500">All req/min<input class="field !py-2 mt-1" type="number" min="10" max="5000" name="rate_limit_per_minute" value="<?php echo (int) $client['rate_limit_per_minute']; ?>"></label>
                                    <label class="text-[11px] text-gray-500">Order req/min<input class="field !py-2 mt-1" type="number" min="1" max="1000" name="order_rate_limit_per_minute" value="<?php echo (int) ($client['order_rate_limit_per_minute'] ?? 10); ?>"></label>
                                    <label class="text-[11px] text-gray-500">Max / order (0=∞)<input class="field !py-2 mt-1" type="number" min="0" step="0.01" name="max_order_amount" value="<?php echo $h((string) ($client['max_order_amount'] ?? '0')); ?>"></label>
                                    <label class="text-[11px] text-gray-500">Daily limit (0=∞)<input class="field !py-2 mt-1" type="number" min="0" step="0.01" name="daily_spend_limit" value="<?php echo $h((string) ($client['daily_spend_limit'] ?? '0')); ?>"></label>
                                    <div class="col-span-2 rounded-lg border border-violet-500/20 bg-violet-500/5 p-3">
                                        <div class="text-xs font-bold text-violet-100"><?php echo $h($t('แหล่งสต็อกที่ API Client นี้อนุญาต', 'Stock sources allowed for this API client')); ?></div>
                                        <div class="text-[11px] text-gray-400 mt-1"><?php echo $h($t('LOCAL เปิดเสมอ ระบบจะไม่รวมสต็อกหลายแหล่งเข้าด้วยกัน และจะเลือกแหล่งเดียวที่ส่งจำนวนทั้งหมดได้', 'LOCAL is always available. Stock pools are never summed; one source must fulfill the entire quantity.')); ?></div>
                                        <label class="mt-3 flex items-center gap-2 text-xs">
                                            <input type="checkbox" name="allow_cgo" value="1" <?php echo !empty($clientSourceAccess['cgo']) ? 'checked' : ''; ?>>
                                            <span>CGO</span>
                                        </label>
                                        <div class="mt-3 grid gap-2">
                                            <?php foreach ($connections as $sourceConnection): ?>
                                                <?php
                                                    $sourceConnectionId = (int)($sourceConnection['id'] ?? 0);
                                                    if ($sourceConnectionId < 1) continue;
                                                    $sourceChecked = isset($clientSupplierIds[$sourceConnectionId]);
                                                    $sourceLive = (string)($sourceConnection['status'] ?? '') === 'active'
                                                        && strtolower(trim((string)($sourceConnection['purchase_mode'] ?? 'live'))) === 'live';
                                                ?>
                                                <label class="flex items-start gap-2 rounded border border-white/10 bg-black/10 px-2 py-2 text-xs">
                                                    <input type="checkbox" name="supplier_connection_ids[]" value="<?php echo $sourceConnectionId; ?>"
                                                        <?php echo $sourceChecked ? 'checked' : ''; ?>>
                                                    <span>
                                                        <strong><?php echo $h((string)($sourceConnection['name'] ?? ('Supplier #'.$sourceConnectionId))); ?></strong>
                                                        <span class="text-gray-500"> · <?php echo $h((string)($sourceConnection['provider_type'] ?? 'generic')); ?> · <?php echo $sourceLive ? 'LIVE' : $h($t('ยังไม่พร้อมขาย', 'not live')); ?></span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if ($connections === []): ?><div class="text-[11px] text-gray-500"><?php echo $h($t('ยังไม่มี Supplier Connection', 'No supplier connections yet.')); ?></div><?php endif; ?>
                                        </div>
                                        <div class="text-[11px] text-gray-400 mt-2"><?php echo $h($t('ใช้ได้ทั้งยอดเครดิต API และบัญชีตัวแทนเว็บไซต์ โดย Store API parent จะหัก/คืนเงินกลับไปยังแหล่งเครดิตตาม Billing Mode ของ Client นี้', 'Available with both API credit and linked reseller wallet billing. The Store API parent debits/refunds the balance owned by this client billing mode.')); ?></div>
                                    </div>
                                    <button class="btn btn-soft !py-2 col-span-2" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึก', 'Save')); ?></button>
                                </form>
                            </details>
                            <?php if (!$clientIsWallet): ?>
                                <form method="post" class="flex gap-2 items-center"><?php echo csrfField(); ?><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>"><input class="field !py-2 w-28" type="number" step="0.01" name="amount" placeholder="+/-" required><input class="field !py-2 w-44" name="note" maxlength="500" placeholder="<?php echo $h($t('หมายเหตุ', 'Note')); ?>"><button class="btn btn-soft !py-2" type="submit"><i class="bi bi-wallet2"></i></button></form>
                            <?php else: ?>
                                <div class="text-xs text-gray-500"><?php echo $h($t('การเติม/หักเงินทำผ่านบัญชีตัวแทนที่ผูก ไม่แก้ยอดจาก API Hub เพื่อไม่ให้เกิดสองกระเป๋าที่ต้อง sync กัน', 'Credit/debit the linked reseller account instead of API Hub; balances are intentionally not mirrored.')); ?></div>
                            <?php endif; ?>
                            <div class="flex flex-wrap gap-2"><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_client_status"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>"><input type="hidden" name="status" value="<?php echo $client['status'] === 'active' ? 'inactive' : 'active'; ?>"><button class="btn <?php echo $client['status'] === 'active' ? 'btn-danger' : 'btn-soft'; ?> !py-2" type="submit"><?php echo $h($client['status'] === 'active' ? $t('ระงับคีย์', 'Disable') : $t('เปิดคีย์', 'Enable')); ?></button></form><form method="post" onsubmit="return confirm('<?php echo $h($t('คีย์เก่าจะใช้งานไม่ได้ทันที ยืนยันหรือไม่?', 'The old key will stop working immediately. Continue?')); ?>')"><?php echo csrfField(); ?><input type="hidden" name="action" value="regenerate_client_key"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>"><button class="btn btn-soft !py-2" type="submit"><i class="bi bi-arrow-clockwise"></i><?php echo $h($t('สร้างคีย์ใหม่', 'Regenerate')); ?></button></form><form method="post" onsubmit="return confirm('<?php echo $h($t('ลบสิทธิ์ API Key นี้หรือไม่? ประวัติคำสั่งซื้อจะยังคงอยู่', 'Delete this API key access? Order history will be retained.')); ?>')"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_client"><input type="hidden" name="client_id" value="<?php echo (int) $client['id']; ?>"><button class="btn btn-danger !py-2" type="submit"><i class="bi bi-trash"></i><?php echo $h($t('ลบคีย์', 'Delete key')); ?></button></form></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass rounded-xl overflow-hidden border border-cyan-500/20">
        <div class="p-5 border-b border-white/10">
            <h2 class="text-xl font-bold"><i class="bi bi-bug mr-2 text-cyan-300"></i><?php echo $h($t('Webhook Test History / Debug JSON', 'Webhook test history / Debug JSON')); ?></h2>
            <p class="text-sm text-gray-400 mt-1"><?php echo $h($t('เก็บผลการทดสอบ Server-to-Server แต่ละรอบไว้ตรวจย้อนหลัง โดยไม่บันทึก API Key และจะตัด Query String ของ Webhook URL ออกจาก Debug JSON', 'Each server-to-server test is retained for troubleshooting. API keys are never logged, and webhook URL query strings are redacted from Debug JSON.')); ?></p>
        </div>
        <div class="overflow-x-auto max-h-[760px]">
            <table class="w-full min-w-[1050px] text-sm">
                <thead class="sticky top-0 bg-gray-900 text-gray-300"><tr><th class="p-3 text-left">Time</th><th class="p-3 text-left">Client</th><th class="p-3 text-left">Result</th><th class="p-3 text-left">Network</th><th class="p-3 text-left">Debug</th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if ($webhookTestLogs === []): ?><tr><td colspan="5" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มีประวัติ Webhook Test ให้กดทดสอบจากรายการ API Client ก่อน', 'No webhook test history yet. Run a webhook test from an API client first.')); ?></td></tr><?php endif; ?>
                <?php foreach ($webhookTestLogs as $webhookLog): ?>
                    <?php
                        $logId = (int) ($webhookLog['id'] ?? 0);
                        $logHttp = (int) ($webhookLog['http_code'] ?? 0);
                        $logOk = (int) ($webhookLog['success'] ?? 0) === 1;
                        $logJsonRaw = trim((string) ($webhookLog['debug_json'] ?? '{}'));
                        $logDecoded = json_decode($logJsonRaw, true);
                        if (is_array($logDecoded)) {
                            $logPretty = json_encode($logDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                            if (is_string($logPretty)) $logJsonRaw = $logPretty;
                        }
                        $logDomId = 'webhookHistoryDebug' . $logId;
                    ?>
                    <tr class="align-top">
                        <td class="p-3 text-xs whitespace-nowrap"><?php echo $h((string) ($webhookLog['created_at'] ?? '')); ?><div class="text-gray-500 mt-1">#<?php echo $logId; ?></div></td>
                        <td class="p-3"><div class="font-semibold">#<?php echo (int) ($webhookLog['client_id'] ?? 0); ?> <?php echo $h((string) ($webhookLog['client_name'] ?? '')); ?></div><?php if (!empty($webhookLog['website_name'])): ?><div class="text-xs text-violet-200 mt-1"><?php echo $h((string) $webhookLog['website_name']); ?></div><?php endif; ?></td>
                        <td class="p-3"><span class="badge <?php echo $logOk ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>"><?php echo $h((string) ($webhookLog['result_code'] ?? 'unknown')); ?></span><div class="text-xs mt-2">HTTP <strong class="<?php echo $logHttp >= 200 && $logHttp < 300 ? 'status-ok' : ($logHttp > 0 ? 'status-bad' : 'status-warn'); ?>"><?php echo $logHttp ?: '-'; ?></strong> · <?php echo (int) ($webhookLog['duration_ms'] ?? 0); ?> ms</div><?php if ($logHttp === 403): ?><div class="text-[11px] text-amber-200 mt-2"><?php echo $h($t('ถึงปลายทางแล้ว แต่ถูกปฏิเสธ', 'Target reached, request rejected')); ?></div><?php endif; ?></td>
                        <td class="p-3 text-xs"><div>DNS/Pinned: <span class="font-mono"><?php echo $h((string) ($webhookLog['resolved_ip'] ?? '-')); ?></span></div><div class="mt-1">Connected: <span class="font-mono"><?php echo $h((string) ($webhookLog['connected_ip'] ?? '-')); ?></span></div></td>
                        <td class="p-3 min-w-[420px]"><details class="rounded-lg border border-white/10 bg-black/20"><summary class="p-2 text-xs cursor-pointer"><?php echo $h($t('เปิด JSON หลักฐาน', 'Open evidence JSON')); ?></summary><div class="p-3 border-t border-white/10"><pre id="<?php echo $h($logDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[460px] overflow-auto"><?php echo $h($logJsonRaw); ?></pre><button type="button" class="btn btn-soft !py-2 mt-2" onclick="copyText('<?php echo $h($logDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button></div></details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="glass rounded-xl overflow-hidden border border-sky-500/20">
        <div class="p-5 border-b border-white/10">
            <h2 class="text-xl font-bold"><i class="bi bi-radar mr-2 text-sky-300"></i><?php echo $h($t('Diagnostic Probe Hit History', 'Diagnostic probe hit history')); ?> (<?php echo count($diagnosticProbeLogs); ?>)</h2>
            <p class="text-sm text-gray-400 mt-1"><?php echo $h($t('บันทึกทุกครั้งที่ Probe มาถึง PHP แยกเป็นรอบ ไม่ทับค่าก่อนหน้า เพื่อพิสูจน์ REMOTE_ADDR, Client IP ที่ตรวจพบ, Trusted Proxy, IP Allowlist และ CF-Ray ย้อนหลังได้', 'Every probe hit that reaches PHP is retained as an append-only event so REMOTE_ADDR, detected client IP, trusted proxy, IP allowlist and CF-Ray can be reviewed later.')); ?></p>
            <?php if (!$diagnosticSchemaReady): ?><div class="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/10 p-3 text-xs text-amber-100"><?php echo $h($t('Advanced Diagnostic History ยังไม่พร้อม (เช่น ตารางเสริมยังสร้างไม่ได้) แต่ Store API หลักและ Request Log เดิมยังทำงานต่อ ตรวจ PHP error log สำหรับสาเหตุ schema/permission', 'Advanced diagnostic history is unavailable (for example, optional tables could not be created). Core Store API and the legacy request log remain operational; inspect the PHP error log for schema/permission details.')); ?></div><?php endif; ?>
        </div>
        <div class="overflow-x-auto max-h-[680px]">
            <table class="w-full min-w-[1180px] text-sm">
                <thead class="sticky top-0 bg-gray-900 text-gray-300"><tr><th class="p-3 text-left">Time</th><th class="p-3 text-left">Client / Probe</th><th class="p-3 text-left">Network</th><th class="p-3 text-left">Allowlist / Edge</th><th class="p-3 text-left">Evidence</th></tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if ($diagnosticProbeLogs === []): ?><tr><td colspan="5" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มี Probe ที่มาถึง PHP', 'No diagnostic probe has reached PHP yet.')); ?></td></tr><?php endif; ?>
                <?php foreach ($diagnosticProbeLogs as $probeLog): ?>
                    <?php
                        $probeLogId = (int) ($probeLog['id'] ?? 0);
                        $probeEvidence = is_array($probeLog['diagnostic'] ?? null) ? $probeLog['diagnostic'] : [];
                        $probePretty = json_encode($probeEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        if (!is_string($probePretty)) $probePretty = '{}';
                        $probeDomId = 'probeEvidence' . $probeLogId;
                    ?>
                    <tr class="align-top">
                        <td class="p-3 text-xs whitespace-nowrap"><?php echo $h((string) ($probeLog['created_at'] ?? '')); ?><div class="text-gray-500 mt-1">#<?php echo $probeLogId; ?></div></td>
                        <td class="p-3"><div class="font-semibold">#<?php echo (int) ($probeLog['client_id'] ?? 0); ?> <?php echo $h((string) ($probeLog['client_name'] ?? '-')); ?></div><div class="text-xs text-gray-400 mt-1">Probe #<?php echo (int) ($probeLog['probe_id'] ?? 0); ?></div><div class="font-mono text-[11px] text-gray-500 mt-1"><?php echo $h((string) ($probeLog['request_id'] ?? '-')); ?></div></td>
                        <td class="p-3 text-xs"><div>REMOTE_ADDR: <span class="font-mono"><?php echo $h((string) ($probeLog['remote_addr'] ?? '-')); ?></span></div><div class="mt-1">Detected: <span class="font-mono"><?php echo $h((string) ($probeLog['detected_client_ip'] ?? '-')); ?></span></div><div class="mt-1">Trusted proxy: <strong class="<?php echo !empty($probeLog['trusted_proxy']) ? 'status-ok' : 'status-warn'; ?>"><?php echo !empty($probeLog['trusted_proxy']) ? 'YES' : 'NO'; ?></strong></div></td>
                        <td class="p-3 text-xs"><div>IP allowlist: <strong class="<?php echo !empty($probeLog['ip_allowed']) ? 'status-ok' : 'status-bad'; ?>"><?php echo !empty($probeLog['ip_allowed']) ? 'MATCH' : 'NOT MATCH'; ?></strong></div><div class="mt-1">CF-Ray: <span class="font-mono"><?php echo $h((string) ($probeLog['cf_ray'] ?? '-')); ?></span></div></td>
                        <td class="p-3 min-w-[420px]"><details class="rounded-lg border border-white/10 bg-black/20"><summary class="p-2 text-xs cursor-pointer"><?php echo $h($t('เปิด JSON หลักฐาน Probe', 'Open probe evidence JSON')); ?></summary><div class="p-3 border-t border-white/10"><pre id="<?php echo $h($probeDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[420px] overflow-auto"><?php echo $h($probePretty); ?></pre><button type="button" class="btn btn-soft !py-2 mt-2" onclick="copyText('<?php echo $h($probeDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button></div></details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <details class="guide-toggle glass rounded-xl overflow-hidden border border-violet-500/20">
        <summary class="p-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
                <h2 class="text-xl font-bold"><i class="bi bi-journal-code mr-2 text-violet-300"></i><?php echo $h($t('คู่มือเชื่อม Store API สำหรับส่งให้ตัวแทน/นักพัฒนา', 'Store API integration guide')); ?></h2>
                <p class="text-sm text-gray-400 mt-1"><?php echo $h($t('ซ่อนไว้เป็นค่าเริ่มต้นเพื่อลดความรก กดแสดงเมื่อต้องการอ่านหรือคัดลอกส่งให้นักพัฒนา', 'Collapsed by default to keep this page tidy. Expand it only when you need to read or copy the integration guide.')); ?></p>
            </div>
            <span class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-violet-400/20 bg-violet-500/10 px-3 py-2 text-xs font-bold text-violet-200">
                <span class="guide-when-closed"><i class="bi bi-chevron-down mr-1"></i><?php echo $h($t('แสดงคู่มือ', 'Show guide')); ?></span>
                <span class="guide-when-open"><i class="bi bi-chevron-up mr-1"></i><?php echo $h($t('ซ่อนคู่มือ', 'Hide guide')); ?></span>
            </span>
        </summary>
        <div class="border-t border-white/10 p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="text-xs text-gray-500"><?php echo $h($t('เนื้อหาสร้างจาก Endpoint ปัจจุบันและพร้อมส่งต่อได้ทันที', 'The text is generated from the current endpoint and is ready to share.')); ?></div>
                <button type="button" class="btn btn-primary shrink-0" onclick="copyText('apiIntegrationDocs')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอกคู่มือทั้งหมด', 'Copy full guide')); ?></button>
            </div>
            <pre id="apiIntegrationDocs" class="whitespace-pre-wrap break-words rounded-xl bg-black/35 border border-white/10 p-4 text-xs md:text-sm leading-relaxed text-gray-200 overflow-auto max-h-[760px]"><?php echo $h($apiDocsText); ?></pre>
        </div>
    </details>

    <section class="space-y-4">
        <h2 class="text-xl font-bold"><i class="bi bi-hdd-network mr-2 text-sky-300"></i><?php echo $h($t('การเชื่อมต่อ Supplier', 'Supplier connections')); ?></h2>
        <?php if ($connections === []): ?><div class="glass rounded-xl p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่ได้เชื่อม API Supplier', 'No supplier API is connected.')); ?></div><?php endif; ?>
        <?php foreach ($connections as $connection): ?>
            <details class="glass rounded-xl" <?php echo !empty($connection['last_error']) ? 'open' : ''; ?>>
                <summary class="p-5 flex flex-wrap gap-4 items-center justify-between">
                    <div><div class="font-bold text-lg">#<?php echo (int) $connection['id']; ?> <?php echo $h($connection['name']); ?></div><div class="text-xs text-gray-500 mt-1 break-all"><?php echo $h($connection['endpoint_url']); ?></div></div>
                    <div class="flex flex-wrap gap-2 items-center"><span class="badge bg-sky-500/20 text-sky-200"><?php echo $h($connection['provider_type']); ?></span><span class="badge <?php echo $connection['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-200' : 'bg-red-500/20 text-red-200'; ?>"><?php echo $h($connection['status']); ?></span><span class="badge bg-amber-500/15 text-amber-200"><?php echo $h('purchase:' . ($connection['purchase_mode'] ?? 'live')); ?></span><span class="text-sm text-gray-400"><?php echo (int) $connection['product_count']; ?> <?php echo $h($t('สินค้า', 'products')); ?></span></div>
                </summary>
                <div class="border-t border-white/10 p-5">
                    <?php if (!empty($connection['last_error'])): ?><div class="mb-4 rounded-lg border border-red-500/30 bg-red-900/20 p-3 text-sm text-red-200"><?php echo $h($connection['last_error']); ?></div><?php endif; ?>
                    <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-4">
                        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <?php echo csrfField(); ?><input type="hidden" name="action" value="update_connection"><input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>">
                            <label class="text-xs text-gray-400"><?php echo $h($t('ชื่อ', 'Name')); ?><input class="field mt-1" name="name" value="<?php echo $h($connection['name']); ?>" required></label>
                            <label class="md:col-span-2 text-xs text-gray-400">Endpoint<input class="field mt-1" type="url" name="endpoint_url" value="<?php echo $h($connection['endpoint_url']); ?>" required></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('Credential ใหม่ (เว้นว่างเพื่อใช้เดิม)', 'New credential (blank keeps current)')); ?><input class="field mt-1" type="password" name="api_key" autocomplete="new-password"></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('ลำดับ', 'Priority')); ?><input class="field mt-1" type="number" name="priority" value="<?php echo (int) $connection['priority']; ?>"></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('โหมดการซื้อ', 'Purchase mode')); ?><select class="field mt-1" name="purchase_mode"><?php foreach (supplierBridgePurchaseModes() as $purchaseMode): ?><option value="<?php echo $h($purchaseMode); ?>" <?php echo (($connection['purchase_mode'] ?? 'live') === $purchaseMode) ? 'selected' : ''; ?>><?php echo $h($purchaseMode); ?></option><?php endforeach; ?></select></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('โหมดราคาผู้ใช้เริ่มต้น', 'Default user price mode')); ?><select class="field mt-1" name="user_price_mode"><option value="source" <?php echo ($connection['user_price_mode']??'source')==='source'?'selected':''; ?>><?php echo $h($t('ตามต้นทาง', 'Match source')); ?></option><option value="markup" <?php echo ($connection['user_price_mode']??'')==='markup'?'selected':''; ?>><?php echo $h($t('ทุน + %', 'Cost + %')); ?></option><option value="keep" <?php echo ($connection['user_price_mode']??'')==='keep'?'selected':''; ?>><?php echo $h($t('คงราคาเดิม', 'Keep current')); ?></option></select></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('โหมดราคาตัวแทนเริ่มต้น', 'Default reseller price mode')); ?><select class="field mt-1" name="reseller_price_mode"><option value="source" <?php echo ($connection['reseller_price_mode']??'source')==='source'?'selected':''; ?>><?php echo $h($t('ตามต้นทาง', 'Match source')); ?></option><option value="markup" <?php echo ($connection['reseller_price_mode']??'')==='markup'?'selected':''; ?>><?php echo $h($t('ทุน + %', 'Cost + %')); ?></option><option value="keep" <?php echo ($connection['reseller_price_mode']??'')==='keep'?'selected':''; ?>><?php echo $h($t('คงราคาเดิม', 'Keep current')); ?></option></select></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('กำไรผู้ใช้เมื่อใช้ทุน + %', 'User markup for cost + %')); ?><input class="field mt-1" type="number" step="0.01" min="0" max="10000" name="user_markup_percent" value="<?php echo $h($connection['user_markup_percent']); ?>"></label>
                            <label class="text-xs text-gray-400"><?php echo $h($t('กำไรตัวแทนเมื่อใช้ทุน + %', 'Reseller markup for cost + %')); ?><input class="field mt-1" type="number" step="0.01" min="0" max="10000" name="reseller_markup_percent" value="<?php echo $h($connection['reseller_markup_percent']); ?>"></label>
                            <div class="md:col-span-3 flex flex-wrap gap-4 text-sm text-gray-300 items-center"><label><input type="checkbox" name="auto_publish" <?php echo (int) $connection['auto_publish'] === 1 ? 'checked' : ''; ?> class="mr-2"><?php echo $h($t('เผยแพร่อัตโนมัติ', 'Auto publish')); ?></label><label><input type="checkbox" name="sync_details" <?php echo (int) $connection['sync_details'] === 1 ? 'checked' : ''; ?> class="mr-2"><?php echo $h($t('ซิงก์รายละเอียด', 'Sync details')); ?></label><label><input type="checkbox" name="sync_prices" <?php echo (int) $connection['sync_prices'] === 1 ? 'checked' : ''; ?> class="mr-2"><?php echo $h($t('ใช้กฎราคาทุกครั้งที่ซิงก์', 'Apply pricing rules on sync')); ?></label><label><input type="checkbox" name="protect_below_cost" <?php echo (int) ($connection['protect_below_cost']??1) === 1 ? 'checked' : ''; ?> class="mr-2"><?php echo $h($t('ห้ามต่ำกว่าทุน API', 'Prevent below-cost sales')); ?></label></div>
                            <div class="md:col-span-3 flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i><?php echo $h($t('บันทึก', 'Save')); ?></button><a class="btn btn-soft" href="api_products.php?connection_id=<?php echo (int) $connection['id']; ?>"><i class="bi bi-box-seam"></i><?php echo $h($t('ดูสินค้า ตั้งราคา และรวมหน้าหลัก', 'Products, pricing and storefront mapping')); ?></a></div>
                        </form>
                        <div class="flex lg:flex-col gap-2">
                            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="sync_connection"><input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>"><button class="btn btn-soft w-full" type="submit"><i class="bi bi-arrow-repeat"></i><?php echo $h($t('ซิงก์ตอนนี้', 'Sync now')); ?></button></form>
                            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_connection_status"><input type="hidden" name="connection_id" value="<?php echo (int) $connection['id']; ?>"><input type="hidden" name="status" value="<?php echo $connection['status'] === 'active' ? 'inactive' : 'active'; ?>"><button class="btn <?php echo $connection['status'] === 'active' ? 'btn-danger' : 'btn-soft'; ?> w-full" type="submit"><?php echo $h($connection['status'] === 'active' ? $t('หยุดเชื่อมต่อ', 'Disable') : $t('เปิดเชื่อมต่อ', 'Enable')); ?></button></form>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-5 text-sm"><div><div class="text-gray-500"><?php echo $h($t('ยอด Supplier', 'Supplier balance')); ?></div><div><?php echo $connection['last_balance'] !== null ? $h(number_format((float) $connection['last_balance'], 2) . ' ' . (string) $connection['currency']) : '-'; ?></div></div><div><div class="text-gray-500"><?php echo $h($t('ซิงก์ล่าสุด', 'Last sync')); ?></div><div><?php echo $h($connection['last_sync_at'] ?: '-'); ?></div></div><div><div class="text-gray-500"><?php echo $h($t('สำเร็จล่าสุด', 'Last success')); ?></div><div><?php echo $h($connection['last_success_at'] ?: '-'); ?></div></div><div><div class="text-gray-500"><?php echo $h($t('คำสั่งซื้อ', 'Orders')); ?></div><div><?php echo (int) $connection['order_count']; ?></div></div><div><div class="text-gray-500"><?php echo $h($t('ชนิด API', 'API type')); ?></div><div><?php echo $h($connection['provider_type']); ?></div></div></div>
                </div>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="glass rounded-xl overflow-hidden border border-sky-500/20">
        <div class="p-5 border-b border-white/10 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold"><i class="bi bi-diagram-3 mr-2 text-sky-300"></i><?php echo $h($t('Supplier API Attempt Evidence', 'Supplier API attempt evidence')); ?> (<?php echo count($supplierApiAttemptLogs); ?>)</h2>
                <p class="text-xs text-gray-500 mt-1"><?php echo $h($t('บันทึกต่อ Attempt: DNS/Connect/TLS/TTFB, primary/local IP, HTTP metadata, response hash และผล retry โดยไม่เก็บ API Key หรือ response body/คีย์ที่ Supplier ส่งกลับ', 'Per-attempt evidence: DNS/connect/TLS/TTFB, primary/local IP, HTTP metadata, response hash and retry result without storing API keys or supplier response bodies/delivered keys.')); ?></p>
            </div>
            <?php if (!$supplierAttemptSchemaReady): ?><span class="badge bg-amber-500/20 text-amber-200"><?php echo $h($t('Advanced attempt log ยังไม่พร้อม', 'Advanced attempt log unavailable')); ?></span><?php endif; ?>
        </div>
        <div class="overflow-auto max-h-[680px]">
            <table class="w-full min-w-[1380px] text-sm">
                <thead class="sticky top-0 bg-gray-900"><tr>
                    <th class="p-3 text-left"><?php echo $h($t('เวลา', 'Time')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('Supplier / Attempt', 'Supplier / attempt')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('คำขอ', 'Request')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('เครือข่าย', 'Network')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('ผล', 'Result')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('หลักฐาน JSON', 'JSON evidence')); ?></th>
                </tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if ($supplierApiAttemptLogs === []): ?><tr><td colspan="6" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มี Supplier API Attempt ที่บันทึกไว้', 'No supplier API attempts have been recorded yet.')); ?></td></tr><?php endif; ?>
                <?php foreach ($supplierApiAttemptLogs as $attemptRow): ?>
                    <?php
                        $attemptEvidence = is_array($attemptRow['evidence'] ?? null) ? $attemptRow['evidence'] : [];
                        $attemptPretty = json_encode($attemptEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        if (!is_string($attemptPretty)) $attemptPretty = '{}';
                        $attemptDomId = 'supplierAttemptEvidence' . (int) ($attemptRow['id'] ?? 0);
                        $attemptFile = 'supplier-api-attempt-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($attemptRow['attempt_id'] ?? $attemptRow['id'] ?? 'evidence')) . '.json';
                    ?>
                    <tr class="align-top">
                        <td class="p-3 text-xs whitespace-nowrap"><?php echo $h((string) ($attemptRow['created_at'] ?? '')); ?></td>
                        <td class="p-3"><div class="font-semibold"><?php echo $h((string) (($attemptRow['connection_name'] ?? '') ?: ('#' . (int) ($attemptRow['connection_id'] ?? 0)))); ?></div><div class="font-mono text-[11px] text-gray-500 mt-1"><?php echo $h((string) ($attemptRow['attempt_id'] ?? '-')); ?></div><div class="text-xs text-gray-500 mt-1">#<?php echo (int) ($attemptRow['attempt_number'] ?? 1); ?>/<?php echo (int) ($attemptRow['max_attempts'] ?? 1); ?> · group <?php echo $h((string) ($attemptRow['attempt_group_id'] ?? '-')); ?></div></td>
                        <td class="p-3"><div><?php echo $h(trim((string) ($attemptRow['http_method'] ?? '') . ' ' . (string) ($attemptRow['action'] ?? ''))); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo $h((string) (($attemptRow['target_host'] ?? '') ?: '-')); ?></div></td>
                        <td class="p-3 text-xs"><div>Primary: <span class="font-mono"><?php echo $h((string) (($attemptRow['primary_ip'] ?? '') ?: '-')); ?></span></div><div class="mt-1">Local: <span class="font-mono"><?php echo $h((string) (($attemptRow['local_ip'] ?? '') ?: '-')); ?></span></div><div class="mt-1"><?php echo (int) ($attemptRow['duration_ms'] ?? 0); ?> ms</div></td>
                        <td class="p-3"><span class="badge <?php echo !empty($attemptRow['success']) ? 'bg-emerald-500/20 text-emerald-200' : (!empty($attemptRow['transport_error']) ? 'bg-red-500/20 text-red-200' : 'bg-amber-500/20 text-amber-200'); ?>"><?php echo !empty($attemptRow['success']) ? 'SUCCESS' : 'FAILED'; ?></span><div class="text-xs mt-2">HTTP <?php echo (int) ($attemptRow['http_code'] ?? 0); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo $h((string) (($attemptRow['error_code'] ?? '') ?: '-')); ?></div></td>
                        <td class="p-3 min-w-[440px]">
                            <details class="rounded-lg border border-white/10 bg-black/20">
                                <summary class="p-2 text-xs cursor-pointer"><?php echo $h($t('เปิด Supplier Attempt JSON', 'Open supplier attempt JSON')); ?></summary>
                                <div class="p-3 border-t border-white/10">
                                    <pre id="<?php echo $h($attemptDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[440px] overflow-auto"><?php echo $h($attemptPretty); ?></pre>
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        <button type="button" class="btn btn-soft !py-2" onclick="copyText('<?php echo $h($attemptDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button>
                                        <button type="button" class="btn btn-soft !py-2" onclick="downloadJsonFromElement('<?php echo $h($attemptDomId); ?>','<?php echo $h($attemptFile); ?>')"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลด JSON', 'Download JSON')); ?></button>
                                    </div>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid grid-cols-1 2xl:grid-cols-2 gap-6">
        <div class="glass rounded-xl overflow-hidden">
            <div class="p-5 border-b border-white/10"><h2 class="font-bold text-lg"><i class="bi bi-box-arrow-up-right mr-2 text-amber-300"></i><?php echo $h($t('คำสั่งซื้อที่เว็บอื่นซื้อจากเรา', 'Provider orders')); ?></h2></div>
            <div class="overflow-auto max-h-[560px]"><table class="w-full min-w-[980px] text-sm"><thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left">ID / Ref</th><th class="p-3 text-left"><?php echo $h($t('ลูกค้า', 'Client')); ?></th><th class="p-3 text-left"><?php echo $h($t('สินค้า', 'Product')); ?></th><th class="p-3 text-left"><?php echo $h($t('การหักเงิน', 'Billing')); ?></th><th class="p-3 text-right"><?php echo $h($t('ยอด', 'Total')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ', 'Status')); ?></th></tr></thead><tbody class="divide-y divide-white/5"><?php foreach ($providerOrders as $order): ?><tr><td class="p-3">#<?php echo (int) $order['id']; ?><div class="text-xs text-gray-500"><?php echo $h($order['external_ref']); ?></div></td><td class="p-3"><?php echo $h($order['client_name']); ?></td><td class="p-3"><?php echo $h($order['remote_product_id']); ?> × <?php echo (int) $order['quantity']; ?><div class="text-xs text-gray-500"><?php echo (int) $order['delivered_count']; ?> keys</div></td><td class="p-3"><span class="badge bg-white/10"><?php echo $h(storeBridgeNormalizeBillingMode($order['billing_mode'] ?? 'api_balance')); ?></span><?php if (!empty($order['billing_user_id'])): ?><div class="text-xs text-gray-500 mt-1">#<?php echo (int) $order['billing_user_id']; ?> <?php echo $h((string) ($order['billing_username'] ?? '')); ?></div><?php endif; ?><?php if ($order['balance_before'] !== null || $order['balance_after'] !== null): ?><div class="text-xs text-gray-500 mt-1"><?php echo $order['balance_before'] === null ? '-' : $h(number_format((float) $order['balance_before'], 2)); ?> → <?php echo $order['balance_after'] === null ? '-' : $h(number_format((float) $order['balance_after'], 2)); ?></div><?php endif; ?></td><td class="p-3 text-right"><?php echo $h(number_format((float) $order['total_price'], 2)); ?></td><td class="p-3"><span class="badge bg-white/10"><?php echo $h($order['status']); ?></span></td></tr><?php endforeach; ?><?php if ($providerOrders === []): ?><tr><td colspan="6" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มีคำสั่งซื้อ', 'No orders yet.')); ?></td></tr><?php endif; ?></tbody></table></div>
        </div>

        <div class="glass rounded-xl overflow-hidden">
            <div class="p-5 border-b border-white/10"><h2 class="font-bold text-lg"><i class="bi bi-box-arrow-in-down-right mr-2 text-sky-300"></i><?php echo $h($t('คำสั่งซื้อที่เว็บนี้ซื้อจาก Supplier', 'Supplier orders')); ?></h2></div>
            <div class="overflow-auto max-h-[560px]">
                <table class="w-full min-w-[980px] text-sm">
                    <thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left">ID / Ref</th><th class="p-3 text-left"><?php echo $h($t('Supplier', 'Supplier')); ?></th><th class="p-3 text-left"><?php echo $h($t('ผู้ซื้อ/สินค้า', 'Buyer / product')); ?></th><th class="p-3 text-right"><?php echo $h($t('ยอดขาย', 'Sale total')); ?></th><th class="p-3 text-left"><?php echo $h($t('สถานะ', 'Status')); ?></th><th class="p-3"><?php echo $h($t('จัดการ', 'Actions')); ?></th></tr></thead>
                    <tbody class="divide-y divide-white/5">
                    <?php foreach ($supplierOrders as $order): ?>
                        <?php $isProtectedManual = supplierBridgeProviderRequiresProtectedPurchase((string) ($order['provider_type'] ?? '')) && in_array((string) $order['status'], ['unknown','processing','manual_review','submitting'], true); ?>
                        <tr class="align-top">
                            <td class="p-3">#<?php echo (int) $order['id']; ?><div class="text-xs text-gray-500"><?php echo $h($order['external_ref']); ?></div></td>
                            <td class="p-3"><?php echo $h($order['connection_name']); ?><div class="text-xs text-gray-500"><?php echo $h($order['provider_type'] ?? ''); ?></div></td>
                            <td class="p-3"><?php echo $h($order['username']); ?><div class="text-xs text-gray-400"><?php echo $h($order['product_name'] . ' - ' . $order['duration']); ?> × <?php echo (int) $order['quantity']; ?></div></td>
                            <td class="p-3 text-right"><?php echo $h(number_format((float) $order['total_price_base'], 2)); ?><div class="text-xs text-gray-500">cost <?php echo $h(number_format((float) $order['total_cost_base'], 2)); ?></div></td>
                            <td class="p-3"><span class="badge bg-white/10"><?php echo $h($order['status']); ?></span><div class="text-xs text-gray-500 mt-1"><?php echo (int) $order['delivered_count']; ?>/<?php echo (int) $order['quantity']; ?> keys</div></td>
                            <td class="p-3 min-w-[300px]">
                                <?php if ($isProtectedManual): ?>
                                    <details class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-2">
                                        <summary class="cursor-pointer text-amber-200"><?php echo $h($t('แก้ Manual Review', 'Resolve manual review')); ?></summary>
                                        <div class="mt-3 space-y-3">
                                            <div class="text-xs text-gray-400"><?php echo $h($t('ตรวจสอบบัญชี Supplier ด้วยตนเองก่อน ถ้าพบว่าซื้อสำเร็จให้ใส่ Key ครบตามจำนวน ห้ามเดาค่า', 'Verify the supplier account manually first. If the purchase succeeded, enter exactly the delivered key count. Do not guess.')); ?></div>
                                            <form method="post" class="space-y-2">
                                                <?php echo csrfField(); ?><input type="hidden" name="action" value="manual_confirm_supplier_order"><input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                                <textarea class="field font-mono text-xs" name="manual_keys" rows="3" required placeholder="1 key per line"></textarea>
                                                <button class="btn btn-primary !py-2" type="submit"><i class="bi bi-key"></i><?php echo $h($t('ยืนยันสำเร็จและส่ง Key', 'Confirm success and deliver')); ?></button>
                                            </form>
                                            <form method="post" class="space-y-2" onsubmit="return confirm('<?php echo $h($t('ยืนยันว่าตรวจ Supplier แล้วและไม่มีออเดอร์จริง? การคืนยอดผิดจะทำให้ลูกค้าได้เงินคืนทั้งที่ Supplier อาจส่ง Key แล้ว', 'Confirm that the supplier was checked and no order exists? A wrong refund can return funds even if the supplier already delivered a key.')); ?>')">
                                                <?php echo csrfField(); ?><input type="hidden" name="action" value="manual_refund_supplier_order"><input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                                <label class="text-xs text-red-200"><input type="checkbox" name="confirmed_no_supplier_order" required class="mr-2"><?php echo $h($t('ฉันตรวจแล้วว่า Supplier ไม่ได้สร้างออเดอร์', 'I verified that the supplier did not create the order')); ?></label>
                                                <button class="btn btn-danger !py-2" type="submit"><i class="bi bi-arrow-counterclockwise"></i><?php echo $h($t('คืนยอดแบบ Manual', 'Manual refund')); ?></button>
                                            </form>
                                        </div>
                                    </details>
                                <?php elseif (in_array((string) $order['status'], ['unknown','processing','manual_review','submitting'], true)): ?>
                                    <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="reconcile_order"><input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>"><button class="btn btn-soft !py-2" type="submit"><i class="bi bi-search"></i><?php echo $h($t('ตรวจสอบ', 'Reconcile')); ?></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($supplierOrders === []): ?><tr><td colspan="6" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มีคำสั่งซื้อ', 'No orders yet.')); ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="glass rounded-xl overflow-hidden">
        <div class="p-5 border-b border-white/10 flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="font-bold"><i class="bi bi-braces-asterisk mr-2 text-cyan-300"></i><?php echo $h($t('ดีบัก JSON การซื้อคีย์ผ่าน API ตัวแทน', 'Reseller API purchase Debug JSON')); ?> (<?php echo count($orderRequestRows); ?>)</div>
                <div class="text-xs text-gray-400 mt-1"><?php echo $h($t('รวมเคสสำเร็จ, กำลังประมวลผล และล้มเหลว พร้อม Billing, Fulfillment Local/CGO, Upstream attempt, Timeline และ Integrity โดยไม่แสดง API Key หรือคีย์สินค้าแบบ plaintext', 'Success, processing and failure evidence with billing, Local/CGO fulfillment, upstream attempts, timeline and integrity. API credentials and plaintext product keys are excluded.')); ?></div>
            </div>
        </div>
        <div class="overflow-auto max-h-[760px]">
            <table class="w-full min-w-[1180px] text-sm">
                <thead class="sticky top-0 bg-gray-900"><tr>
                    <th class="p-3 text-left"><?php echo $h($t('เวลา', 'Time')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('ตัวแทน', 'Reseller')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('Order / สินค้า', 'Order / product')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('เส้นทาง', 'Fulfillment')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('ผลลัพธ์', 'Result')); ?></th>
                    <th class="p-3 text-left"><?php echo $h($t('Debug JSON', 'Debug JSON')); ?></th>
                </tr></thead>
                <tbody class="divide-y divide-white/5">
                <?php if ($orderRequestRows === []): ?><tr><td colspan="6" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มีคำสั่งซื้อผ่าน Store API ที่บันทึก Debug JSON', 'No Store API purchase Debug JSON has been recorded yet.')); ?></td></tr><?php endif; ?>
                <?php foreach ($orderRequestRows as $row): ?>
                    <?php
                    $purchaseDiag = is_array($row['diagnostic'] ?? null) ? $row['diagnostic'] : [];
                    $purchaseEvidence = isset($purchaseDiag['operation_diagnostic']['order_evidence']) && is_array($purchaseDiag['operation_diagnostic']['order_evidence'])
                        ? $purchaseDiag['operation_diagnostic']['order_evidence'] : [];
                    $purchaseJson = $purchaseEvidence !== [] ? $purchaseEvidence : $purchaseDiag;
                    $purchasePretty = json_encode($purchaseJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                    if (!is_string($purchasePretty)) $purchasePretty = '{}';
                    $purchaseDomId = 'purchaseDebug' . (int) ($row['id'] ?? 0);
                    $purchaseOrderId = (int) ($purchaseEvidence['order']['order_id'] ?? 0);
                    $purchaseExternalRef = (string) ($purchaseEvidence['request']['external_ref'] ?? '');
                    $purchaseProductId = (string) ($purchaseEvidence['request']['product_id'] ?? '');
                    $purchaseSource = (string) ($purchaseEvidence['fulfillment']['source'] ?? '');
                    $purchaseReason = (string) ($purchaseEvidence['fulfillment']['reason'] ?? '');
                    $purchaseUpstreamStatus = (string) ($purchaseEvidence['fulfillment']['upstream_status'] ?? '');
                    $purchaseOrderStatus = strtolower((string) ($purchaseEvidence['order']['status'] ?? ''));
                    $purchaseHttp = (int) ($row['http_code'] ?? 0);
                    $purchaseResultCode = (string) (($row['result_code'] ?? '') ?: ($purchaseEvidence['result']['code'] ?? ''));
                    if ($purchaseOrderStatus === 'success') { $purchaseLabel = 'SUCCESS'; $purchaseBadge = 'bg-emerald-500/20 text-emerald-200'; }
                    elseif (in_array($purchaseOrderStatus, ['processing','pending','manual_review'], true) || $purchaseHttp === 202) { $purchaseLabel = 'PROCESSING'; $purchaseBadge = 'bg-amber-500/20 text-amber-200'; }
                    else { $purchaseLabel = 'FAILED'; $purchaseBadge = 'bg-red-500/20 text-red-200'; }
                    $purchaseFile = 'store-api-purchase-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $purchaseOrderId > 0 ? (string) $purchaseOrderId : ((string) (($row['request_id'] ?? '') ?: ($row['id'] ?? 'debug')))) . '.json';
                    ?>
                    <tr class="align-top">
                        <td class="p-3 text-xs whitespace-nowrap"><?php echo $h((string) ($row['created_at'] ?? '')); ?><div class="text-gray-500 mt-1"><?php echo (int) ($row['duration_ms'] ?? 0); ?> ms</div></td>
                        <td class="p-3"><?php echo $h((string) (($row['client_name'] ?? '') ?: '-')); ?><div class="text-xs text-gray-500 mt-1">Client #<?php echo (int) ($row['client_id'] ?? 0); ?></div></td>
                        <td class="p-3"><div><?php echo $purchaseOrderId > 0 ? '#' . $purchaseOrderId : '-'; ?> <span class="font-mono text-xs text-gray-400"><?php echo $h($purchaseExternalRef); ?></span></div><div class="text-xs text-gray-500 mt-1"><?php echo $h($purchaseProductId); ?></div></td>
                        <td class="p-3"><span class="badge bg-white/10"><?php echo $h($purchaseSource !== '' ? strtoupper($purchaseSource) : '-'); ?></span><?php if ($purchaseUpstreamStatus !== ''): ?><div class="text-xs text-violet-200 mt-2">upstream: <?php echo $h($purchaseUpstreamStatus); ?></div><?php endif; ?><?php if ($purchaseReason !== ''): ?><div class="text-xs text-gray-500 mt-1"><?php echo $h($purchaseReason); ?></div><?php endif; ?></td>
                        <td class="p-3"><span class="badge <?php echo $purchaseBadge; ?>"><?php echo $purchaseLabel; ?></span><div class="text-xs mt-2">HTTP <?php echo $purchaseHttp; ?> · <?php echo $h($purchaseResultCode !== '' ? $purchaseResultCode : '-'); ?></div></td>
                        <td class="p-3 min-w-[430px]"><details class="rounded-lg border border-cyan-500/20 bg-cyan-500/5"><summary class="p-2 text-xs cursor-pointer text-cyan-100"><?php echo $h($t('เปิด JSON วิเคราะห์ Order', 'Open order analysis JSON')); ?></summary><div class="p-3 border-t border-cyan-500/20"><pre id="<?php echo $h($purchaseDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[500px] overflow-auto"><?php echo $h($purchasePretty); ?></pre><div class="flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-soft !py-2" onclick="copyText('<?php echo $h($purchaseDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button><button type="button" class="btn btn-soft !py-2" onclick="downloadJsonFromElement('<?php echo $h($purchaseDomId); ?>','<?php echo $h($purchaseFile); ?>')"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลด JSON', 'Download JSON')); ?></button></div></div></details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid grid-cols-1 2xl:grid-cols-2 gap-6">
        <details class="glass rounded-xl"><summary class="p-5 font-bold"><i class="bi bi-journal-text mr-2 text-emerald-300"></i><?php echo $h($t('ประวัติยอดเครดิต API', 'API credit ledger')); ?> (<?php echo count($ledgerRows); ?>)</summary><div class="border-t border-white/10 overflow-auto max-h-[500px]"><table class="w-full min-w-[720px] text-sm"><thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left"><?php echo $h($t('เวลา', 'Time')); ?></th><th class="p-3 text-left"><?php echo $h($t('ลูกค้า', 'Client')); ?></th><th class="p-3 text-left"><?php echo $h($t('ชนิด', 'Type')); ?></th><th class="p-3 text-right"><?php echo $h($t('จำนวน', 'Amount')); ?></th><th class="p-3 text-right"><?php echo $h($t('คงเหลือ', 'Balance')); ?></th></tr></thead><tbody class="divide-y divide-white/5"><?php foreach ($ledgerRows as $row): ?><tr><td class="p-3"><?php echo $h($row['created_at']); ?></td><td class="p-3"><?php echo $h($row['client_name']); ?></td><td class="p-3"><?php echo $h($row['entry_type']); ?><div class="text-xs text-gray-500"><?php echo $h($row['note']); ?></div></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['amount'], 2)); ?></td><td class="p-3 text-right"><?php echo $h(number_format((float) $row['balance_after'], 2)); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details class="glass rounded-xl"><summary class="p-5 font-bold"><i class="bi bi-activity mr-2 text-violet-300"></i><?php echo $h($t('Request Log + Diagnostic Evidence ของ Store API', 'Store API request log + diagnostic evidence')); ?> (<?php echo count($requestRows); ?>)</summary><div class="border-t border-white/10 overflow-auto max-h-[680px]"><table class="w-full min-w-[1280px] text-sm"><thead class="sticky top-0 bg-gray-900"><tr><th class="p-3 text-left"><?php echo $h($t('เวลา', 'Time')); ?></th><th class="p-3 text-left"><?php echo $h($t('ลูกค้า', 'Client')); ?></th><th class="p-3 text-left"><?php echo $h($t('คำขอ / Stage', 'Request / stage')); ?></th><th class="p-3 text-left"><?php echo $h($t('เครือข่าย', 'Network')); ?></th><th class="p-3 text-left"><?php echo $h($t('ผลลัพธ์', 'Result')); ?></th><th class="p-3 text-left"><?php echo $h($t('หลักฐาน', 'Evidence')); ?></th></tr></thead><tbody class="divide-y divide-white/5"><?php if ($requestRows === []): ?><tr><td colspan="6" class="p-6 text-center text-gray-500"><?php echo $h($t('ยังไม่มี Request ที่ PHP บันทึกได้', 'No PHP-level Store API requests have been recorded yet.')); ?></td></tr><?php endif; ?><?php foreach ($requestRows as $row): ?><?php $reqDiag = is_array($row['diagnostic'] ?? null) ? $row['diagnostic'] : []; $reqPretty = json_encode($reqDiag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); if (!is_string($reqPretty)) $reqPretty = '{}'; $reqDomId = 'requestEvidence' . (int) ($row['id'] ?? 0); $reqFile = 'store-api-request-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) (($row['request_id'] ?? '') ?: ($row['id'] ?? 'evidence'))) . '.json'; $orderEvidence = isset($reqDiag['operation_diagnostic']['order_evidence']) && is_array($reqDiag['operation_diagnostic']['order_evidence']) ? $reqDiag['operation_diagnostic']['order_evidence'] : []; $orderPretty = $orderEvidence !== [] ? json_encode($orderEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : ''; if ($orderEvidence !== [] && !is_string($orderPretty)) $orderPretty = '{}'; $orderDomId = 'orderEvidence' . (int) ($row['id'] ?? 0); $orderFile = 'store-api-order-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) (($orderEvidence['order']['order_id'] ?? '') ?: ($row['request_id'] ?? $row['id'] ?? 'evidence'))) . '.json'; ?><tr class="align-top"><td class="p-3 text-xs whitespace-nowrap"><?php echo $h((string) ($row['created_at'] ?? '')); ?></td><td class="p-3"><?php echo $h((string) (($row['client_name'] ?? '') ?: '-')); ?><div class="text-xs text-gray-500 mt-1">#<?php echo (int) ($row['client_id'] ?? 0); ?></div></td><td class="p-3"><div><?php echo $h(trim((string) ($row['http_method'] ?? '') . ' ' . (string) ($row['action'] ?? ''))); ?></div><div class="text-xs text-violet-200 mt-1"><?php echo $h((string) (($row['stage'] ?? '') ?: '-')); ?></div><div class="font-mono text-[11px] text-gray-500 mt-1"><?php echo $h((string) ($row['request_id'] ?? '-')); ?></div></td><td class="p-3 text-xs"><div>Detected: <span class="font-mono"><?php echo $h((string) ($row['detected_client_ip'] ?? $row['client_ip'] ?? '-')); ?></span></div><div class="mt-1">REMOTE_ADDR: <span class="font-mono"><?php echo $h((string) (($row['remote_addr'] ?? '') ?: '-')); ?></span></div><div class="mt-1">Trusted proxy: <strong class="<?php echo !empty($row['trusted_proxy']) ? 'status-ok' : 'status-warn'; ?>"><?php echo !empty($row['trusted_proxy']) ? 'YES' : 'NO'; ?></strong></div><?php if (!empty($row['cf_ray'])): ?><div class="mt-1">CF-Ray: <span class="font-mono"><?php echo $h((string) $row['cf_ray']); ?></span></div><?php endif; ?></td><td class="p-3"><div><span class="badge <?php echo (int) ($row['http_code'] ?? 0) < 400 ? 'bg-emerald-500/20 text-emerald-200' : ((int) ($row['http_code'] ?? 0) >= 500 ? 'bg-red-500/20 text-red-200' : 'bg-amber-500/20 text-amber-200'); ?>">HTTP <?php echo (int) ($row['http_code'] ?? 0); ?></span></div><div class="text-xs mt-2"><?php echo $h((string) (($row['result_code'] ?? '') ?: '-')); ?></div><div class="text-xs text-gray-500 mt-1"><?php echo (int) ($row['duration_ms'] ?? 0); ?> ms</div></td><td class="p-3 min-w-[420px]"><details class="rounded-lg border border-white/10 bg-black/20"><summary class="p-2 text-xs cursor-pointer"><?php echo $h($t('เปิด JSON หลักฐาน Request', 'Open request evidence JSON')); ?></summary><div class="p-3 border-t border-white/10"><pre id="<?php echo $h($reqDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[420px] overflow-auto"><?php echo $h($reqPretty); ?></pre><div class="flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-soft !py-2" onclick="copyText('<?php echo $h($reqDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก JSON', 'Copy JSON')); ?></button><button type="button" class="btn btn-soft !py-2" onclick="downloadJsonFromElement('<?php echo $h($reqDomId); ?>','<?php echo $h($reqFile); ?>')"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลด JSON', 'Download JSON')); ?></button></div></div></details><?php if ($orderEvidence !== []): ?><details class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 mt-2"><summary class="p-2 text-xs cursor-pointer text-emerald-200"><?php echo $h($t('เปิด Order Evidence JSON', 'Open order evidence JSON')); ?></summary><div class="p-3 border-t border-emerald-500/20"><pre id="<?php echo $h($orderDomId); ?>" class="codebox whitespace-pre-wrap break-words max-h-[460px] overflow-auto"><?php echo $h($orderPretty); ?></pre><div class="flex flex-wrap gap-2 mt-2"><button type="button" class="btn btn-soft !py-2" onclick="copyText('<?php echo $h($orderDomId); ?>')"><i class="bi bi-clipboard"></i><?php echo $h($t('คัดลอก Order JSON', 'Copy order JSON')); ?></button><button type="button" class="btn btn-soft !py-2" onclick="downloadJsonFromElement('<?php echo $h($orderDomId); ?>','<?php echo $h($orderFile); ?>')"><i class="bi bi-download"></i><?php echo $h($t('ดาวน์โหลด Order JSON', 'Download order JSON')); ?></button></div></div></details><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></details>
    </section>

    <section class="rounded-xl border border-amber-500/25 bg-amber-900/10 p-5 text-sm text-amber-100 leading-relaxed">
        <div class="font-bold mb-2"><i class="bi bi-shield-check mr-2"></i><?php echo $h($t('ข้อควรรู้ก่อนใช้งานจริง', 'Before production use')); ?></div>
        <p><?php echo $h($t(
            'Provider Store API ใช้ Local ก่อน จากนั้นใช้ CGO และ Store Bridge Supplier เฉพาะแหล่งที่ Admin อนุญาตให้ API Client นั้น โดยไม่บวกสต็อกหลายแหล่งและไม่ผสมคีย์หลายแหล่งใน Order เดียว การหักเงินเกิดที่ Store API parent เพียงครั้งเดียว ส่วน CGO/VIPSTORE/StarkMods ทำหน้าที่ procurement เท่านั้น หากสถานะ upstream ยังไม่แน่นอน Order จะค้าง processing/manual review และห้ามยิงซื้อซ้ำ',
            'The provider Store API is LOCAL-first, then uses only CGO and Store Bridge supplier connections explicitly allowed for that API client. Independent stock pools are never summed and one order never mixes sources. Store API parent billing is charged once while CGO/VIPSTORE/StarkMods act only as procurement. Ambiguous upstream states remain processing/manual review and must not be resubmitted.'
        )); ?></p>
    </section>
</main>
<script>
function syncBillingForm(mode){
    if(!mode)return;
    const form=mode.closest('form');
    if(!form)return;
    const isWallet=mode.value==='reseller_wallet';
    if(mode.id==='newBillingMode'){
        const wrap=document.getElementById('newLinkedResellerWrap');
        if(wrap)wrap.classList.toggle('hidden',!isWallet);
    }
    form.querySelectorAll('[data-api-balance-only]').forEach(node=>node.classList.toggle('hidden',isWallet));
    form.querySelectorAll('[data-api-balance-input]').forEach(input=>{
        input.classList.toggle('opacity-50',isWallet);
        input.setAttribute('aria-disabled',isWallet?'true':'false');
    });
    form.querySelectorAll('[data-reseller-wallet-input]').forEach(input=>input.classList.toggle('hidden',!isWallet));
}
document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('[data-billing-mode]').forEach(mode=>{
        syncBillingForm(mode);
        mode.addEventListener('change',()=>syncBillingForm(mode));
    });
});
async function copyText(id){
    const node=document.getElementById(id); if(!node) return;
    const text=node.textContent.trim();
    try{await navigator.clipboard.writeText(text);}
    catch(e){const area=document.createElement('textarea');area.value=text;document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();}
}
function downloadJsonFromElement(id,filename){
    const node=document.getElementById(id); if(!node) return;
    const text=(node.textContent||'').trim();
    if(!text) return;
    let normalized=text;
    try{normalized=JSON.stringify(JSON.parse(text),null,2);}catch(e){}
    const safeName=(String(filename||'diagnostic.json').replace(/[^A-Za-z0-9._-]+/g,'-').replace(/^-+|-+$/g,'')||'diagnostic.json');
    const finalName=safeName.toLowerCase().endsWith('.json')?safeName:(safeName+'.json');
    const blob=new Blob([normalized+'\n'],{type:'application/json;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    const link=document.createElement('a');
    link.href=url; link.download=finalName; document.body.appendChild(link); link.click(); link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
}
</script>
</body>
</html>
