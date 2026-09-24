<?php
require_once __DIR__ . '/store_bridge.php';

/**
 * CHEATGAME reseller API integration.
 *
 * The API key is loaded only from a private file or environment variables.
 * Nothing in this file exposes credentials to JavaScript or HTML.
 */

if (!function_exists('cgoConfig')) {
    function cgoConfig(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }

        $loaded = [];
        $explicit = trim((string) getenv('CGO_CONFIG_FILE'));
        $candidates = [];
        if ($explicit !== '') {
            $candidates[] = $explicit;
        }
        $candidates[] = dirname(__DIR__, 2) . '/private/cheatgame.php';

        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $value = require $candidate;
            if (is_array($value)) {
                $loaded = $value;
                break;
            }
        }

        // Prefer the explicit private configuration file when it contains a value.
        // This prevents stale hosting environment variables from overriding secrets.
        $fileEndpoint = trim((string) ($loaded['endpoint'] ?? ''));
        $fileApiKey = trim((string) ($loaded['api_key'] ?? ''));
        $fileWebhookSecret = trim((string) ($loaded['webhook_secret'] ?? ''));
        $fileAllowedServerIp = trim((string) ($loaded['allowed_server_ip'] ?? ''));
        $fileProductImageBaseUrl = trim((string) ($loaded['product_image_base_url'] ?? ''));

        $envEndpoint = trim((string) (getenv('CGO_API_ENDPOINT') ?: ''));
        $envApiKey = trim((string) (getenv('CGO_API_KEY') ?: ''));
        $envWebhookSecret = trim((string) (getenv('CGO_WEBHOOK_SECRET') ?: ''));
        $envAllowedServerIp = trim((string) (getenv('CGO_ALLOWED_SERVER_IP') ?: ''));
        $envProductImageBaseUrl = trim((string) (getenv('CGO_PRODUCT_IMAGE_BASE_URL') ?: ''));

        $endpoint = $fileEndpoint !== '' ? $fileEndpoint : ($envEndpoint !== '' ? $envEndpoint : 'https://cheatgame.online/reseller_api.php');
        $apiKey = $fileApiKey !== '' ? $fileApiKey : $envApiKey;
        $webhookSecret = $fileWebhookSecret !== '' ? $fileWebhookSecret : $envWebhookSecret;
        $allowedServerIp = $fileAllowedServerIp !== '' ? $fileAllowedServerIp : $envAllowedServerIp;
        $productImageBaseUrl = $fileProductImageBaseUrl !== '' ? $fileProductImageBaseUrl : $envProductImageBaseUrl;
        $connectTimeout = (int) ($loaded['connect_timeout'] ?? 8);
        $requestTimeout = (int) ($loaded['request_timeout'] ?? 25);

        $parseBoolean = static function ($value, bool $default): bool {
            if (is_bool($value)) return $value;
            if (is_int($value)) return $value === 1;
            if (is_string($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) return $parsed;
            }
            return $default;
        };

        $fileForceIpv4 = null;
        if (array_key_exists('force_ipv4', $loaded)) {
            $fileForceIpv4 = filter_var($loaded['force_ipv4'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        $envForceIpv4Raw = getenv('CGO_FORCE_IPV4');
        $envForceIpv4 = null;
        if ($envForceIpv4Raw !== false && trim((string) $envForceIpv4Raw) !== '') {
            $envForceIpv4 = filter_var($envForceIpv4Raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        $forceIpv4 = $fileForceIpv4 !== null ? $fileForceIpv4 : ($envForceIpv4 !== null ? $envForceIpv4 : true);

        // Resolve the copied website profile from the database actually connected.
        // Host matching is only a fallback, so a forged Host header cannot enable a
        // profile that belongs to a different database.
        $profiles = isset($loaded['site_profiles']) && is_array($loaded['site_profiles']) ? $loaded['site_profiles'] : [];
        $selectedProfileKey = '';
        $selectedProfile = [];
        $profileOverride = trim((string) (getenv('CGO_SITE_PROFILE') ?: ($loaded['site_profile'] ?? '')));
        if ($profileOverride !== '' && isset($profiles[$profileOverride]) && is_array($profiles[$profileOverride])) {
            $selectedProfileKey = $profileOverride;
            $selectedProfile = $profiles[$profileOverride];
        }

        $databaseName = defined('DB_NAME') ? (string) DB_NAME : trim((string) (getenv('DB_NAME') ?: ''));
        if ($selectedProfile === [] && $databaseName !== '') {
            foreach ($profiles as $profileKey => $profile) {
                if (!is_array($profile)) continue;
                $suffix = trim((string) ($profile['database_suffix'] ?? ''));
                if ($suffix !== '' && strlen($databaseName) >= strlen($suffix)
                    && substr($databaseName, -strlen($suffix)) === $suffix) {
                    $selectedProfileKey = (string) $profileKey;
                    $selectedProfile = $profile;
                    break;
                }
            }
        }

        $requestHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (strpos($requestHost, ':') !== false) {
            $requestHost = explode(':', $requestHost, 2)[0];
        }
        if ($selectedProfile === [] && $databaseName === '' && $requestHost !== '') {
            foreach ($profiles as $profileKey => $profile) {
                if (!is_array($profile)) continue;
                $hosts = isset($profile['hosts']) && is_array($profile['hosts']) ? $profile['hosts'] : [];
                foreach ($hosts as $host) {
                    if (hash_equals(strtolower(trim((string) $host)), $requestHost)) {
                        $selectedProfileKey = (string) $profileKey;
                        $selectedProfile = $profile;
                        break 2;
                    }
                }
            }
        }

        $siteCode = strtoupper(trim((string) ($selectedProfile['site_code'] ?? ($loaded['site_code'] ?? ''))));
        $databaseCode = strtoupper(trim((string) ($selectedProfile['database_code'] ?? ($loaded['database_code'] ?? ''))));
        $sitePrefix = ($siteCode !== '' && $databaseCode !== '') ? $siteCode . '-' . $databaseCode : '';
        $liveOrderEnabled = $parseBoolean(
            $selectedProfile['live_order_enabled'] ?? ($loaded['live_order_enabled'] ?? false),
            false
        );
        $liveOrderAdminOnly = $parseBoolean(
            $selectedProfile['live_order_admin_only'] ?? ($loaded['live_order_admin_only'] ?? false),
            false
        );
        $webhookRole = strtolower(trim((string) ($selectedProfile['webhook_role'] ?? ($loaded['webhook_role'] ?? 'local'))));
        $webhookPublicUrl = trim((string) ($loaded['webhook_public_url'] ?? ''));
        $webhookRoutes = isset($loaded['webhook_routes']) && is_array($loaded['webhook_routes']) ? $loaded['webhook_routes'] : [];
        $webhookForwardConnectTimeout = max(2, min(20, (int) ($loaded['webhook_forward_connect_timeout'] ?? 5)));
        $webhookForwardTimeout = max(5, min(60, (int) ($loaded['webhook_forward_timeout'] ?? 15)));
        // The storefront keeps a short shared stock cache. Checkout may reuse only
        // a very recent snapshot while holding the cross-site product lock; the
        // supplier order endpoint remains authoritative before delivery.
        $inventoryCacheTtlSeconds = max(30, min(300, (int) ($loaded['inventory_cache_ttl_seconds'] ?? 30)));
        $inventoryPurchaseMaxAgeSeconds = max(5, min(
            $inventoryCacheTtlSeconds,
            (int) ($loaded['inventory_purchase_max_age_seconds'] ?? 5)
        ));
        // When the catalogue endpoint is temporarily partial, checkout may use a
        // previously confirmed positive stock snapshot for a bounded period. The
        // supplier order endpoint remains authoritative and rejected orders follow
        // the existing refund path. Shorter limits apply to transport failures.
        $inventoryBusyFallbackSeconds = max(15, min(300, (int) ($loaded['inventory_busy_fallback_seconds'] ?? 90)));
        $inventoryPartialFallbackSeconds = max(60, min(3600, (int) ($loaded['inventory_partial_fallback_seconds'] ?? 900)));
        $inventoryTransportFallbackSeconds = max(5, min(120, (int) ($loaded['inventory_transport_fallback_seconds'] ?? 20)));
        $inventoryRetryDelayMs = max(100, min(1500, (int) ($loaded['inventory_retry_delay_ms'] ?? 350)));
        $inventoryRequestTimeoutSeconds = max(3, min(15, (int) ($loaded['inventory_request_timeout_seconds'] ?? 7)));

        // Checkout is intentionally bounded for a fast storefront. Product
        // catalogue refreshes run outside the purchase critical path; the order
        // endpoint is the authority for final stock acceptance/rejection.
        $checkoutLiveInventoryRefresh = $parseBoolean($loaded['checkout_live_inventory_refresh'] ?? false, false);
        $orderSubmitTimeoutSeconds = max(6, min(8, (int) ($loaded['order_submit_timeout_seconds'] ?? 8)));
        $orderStatusTimeoutSeconds = max(2, min(3, (int) ($loaded['order_status_timeout_seconds'] ?? 3)));
        $orderConnectTimeoutSeconds = max(2, min(3, (int) ($loaded['order_connect_timeout_seconds'] ?? 3)));
        // order_not_found is explicitly non-final. The short grace is kept
        // only for stale-order retry throttling; money moves only after final=true
        // or a successful order_cancel fencing seal.
        $orderNotFoundGraceSeconds = max(8, min(10, (int) ($loaded['order_not_found_grace_seconds'] ?? 10)));
        $orderCancelTimeoutSeconds = max(2, min(3, (int) ($loaded['order_cancel_timeout_seconds'] ?? 3)));
        $orderCustomerDeadlineSeconds = max(12, min(18, (int) ($loaded['order_customer_deadline_seconds'] ?? 18)));
        $orderReconcileMinIntervalSeconds = max(2, min(3, (int) ($loaded['order_reconcile_min_interval_seconds'] ?? 3)));
        $orderFastRecoveryEnabled = $parseBoolean($loaded['order_fast_recovery_enabled'] ?? true, true);

        $config = [
            'endpoint' => $endpoint,
            'api_key' => $apiKey,
            'webhook_secret' => $webhookSecret,
            'allowed_server_ip' => $allowedServerIp,
            'product_image_base_url' => $productImageBaseUrl,
            'connect_timeout' => max(2, min(20, $connectTimeout)),
            'request_timeout' => max(5, min(60, $requestTimeout)),
            'force_ipv4' => $forceIpv4,
            'site_profile' => $selectedProfileKey,
            'site_code' => $siteCode,
            'database_code' => $databaseCode,
            'site_prefix' => $sitePrefix,
            'live_order_enabled' => $liveOrderEnabled,
            'live_order_admin_only' => $liveOrderAdminOnly,
            'webhook_role' => $webhookRole,
            'webhook_public_url' => $webhookPublicUrl,
            'webhook_routes' => $webhookRoutes,
            'webhook_forward_connect_timeout' => $webhookForwardConnectTimeout,
            'webhook_forward_timeout' => $webhookForwardTimeout,
            'inventory_cache_ttl_seconds' => $inventoryCacheTtlSeconds,
            'inventory_purchase_max_age_seconds' => $inventoryPurchaseMaxAgeSeconds,
            'inventory_busy_fallback_seconds' => $inventoryBusyFallbackSeconds,
            'inventory_partial_fallback_seconds' => $inventoryPartialFallbackSeconds,
            'inventory_transport_fallback_seconds' => $inventoryTransportFallbackSeconds,
            'inventory_retry_delay_ms' => $inventoryRetryDelayMs,
            'inventory_request_timeout_seconds' => $inventoryRequestTimeoutSeconds,
            'checkout_live_inventory_refresh' => $checkoutLiveInventoryRefresh,
            'order_submit_timeout_seconds' => $orderSubmitTimeoutSeconds,
            'order_status_timeout_seconds' => $orderStatusTimeoutSeconds,
            'order_connect_timeout_seconds' => $orderConnectTimeoutSeconds,
            'order_cancel_timeout_seconds' => $orderCancelTimeoutSeconds,
            'order_not_found_grace_seconds' => $orderNotFoundGraceSeconds,
            'order_customer_deadline_seconds' => $orderCustomerDeadlineSeconds,
            'order_reconcile_min_interval_seconds' => $orderReconcileMinIntervalSeconds,
            'order_fast_recovery_enabled' => $orderFastRecoveryEnabled,
            'endpoint_source' => $fileEndpoint !== '' ? 'private_file' : ($envEndpoint !== '' ? 'environment' : 'default'),
            'api_key_source' => $fileApiKey !== '' ? 'private_file' : ($envApiKey !== '' ? 'environment' : 'missing'),
            'webhook_secret_source' => $fileWebhookSecret !== '' ? 'private_file' : ($envWebhookSecret !== '' ? 'environment' : 'missing'),
            'allowed_server_ip_source' => $fileAllowedServerIp !== '' ? 'private_file' : ($envAllowedServerIp !== '' ? 'environment' : 'missing'),
            'product_image_base_url_source' => $fileProductImageBaseUrl !== '' ? 'private_file' : ($envProductImageBaseUrl !== '' ? 'environment' : 'missing'),
            'force_ipv4_source' => $fileForceIpv4 !== null ? 'private_file' : ($envForceIpv4 !== null ? 'environment' : 'default'),
        ];
        return $config;
    }
}

if (!function_exists('cgoSitePrefix')) {
    function cgoSitePrefix(): string
    {
        $prefix = strtoupper(trim((string) (cgoConfig()['site_prefix'] ?? '')));
        return preg_match('/^[A-Z0-9]{2,12}-[A-Z0-9]{1,12}$/', $prefix) === 1 ? $prefix : '';
    }
}


if (!function_exists('cgoCheckoutLiveInventoryRefreshEnabled')) {
    function cgoCheckoutLiveInventoryRefreshEnabled(): bool
    {
        return !empty(cgoConfig()['checkout_live_inventory_refresh']);
    }
}

if (!function_exists('cgoOrderSubmitTimeoutSeconds')) {
    function cgoOrderSubmitTimeoutSeconds(): int
    {
        return max(6, min(8, (int) (cgoConfig()['order_submit_timeout_seconds'] ?? 8)));
    }
}

if (!function_exists('cgoOrderStatusTimeoutSeconds')) {
    function cgoOrderStatusTimeoutSeconds(): int
    {
        return max(2, min(3, (int) (cgoConfig()['order_status_timeout_seconds'] ?? 3)));
    }
}

if (!function_exists('cgoOrderCancelTimeoutSeconds')) {
    function cgoOrderCancelTimeoutSeconds(): int
    {
        // Keep the fencing step short enough that 8s submit + status + cancel
        // remains inside the customer-facing <20s recovery budget.
        return max(2, min(3, (int) (cgoConfig()['order_cancel_timeout_seconds'] ?? 3)));
    }
}

if (!function_exists('cgoOrderNotFoundGraceSeconds')) {
    function cgoOrderNotFoundGraceSeconds(): int
    {
        return max(8, min(10, (int) (cgoConfig()['order_not_found_grace_seconds'] ?? 10)));
    }
}

if (!function_exists('cgoOrderCustomerDeadlineSeconds')) {
    function cgoOrderCustomerDeadlineSeconds(): int
    {
        return max(12, min(18, (int) (cgoConfig()['order_customer_deadline_seconds'] ?? 18)));
    }
}

if (!function_exists('cgoOrderReconcileMinIntervalSeconds')) {
    function cgoOrderReconcileMinIntervalSeconds(): int
    {
        return max(2, min(3, (int) (cgoConfig()['order_reconcile_min_interval_seconds'] ?? 3)));
    }
}

if (!function_exists('cgoOrderFastRecoveryEnabled')) {
    function cgoOrderFastRecoveryEnabled(): bool
    {
        return !array_key_exists('order_fast_recovery_enabled', cgoConfig()) || !empty(cgoConfig()['order_fast_recovery_enabled']);
    }
}

if (!function_exists('cgoLiveOrderEnabled')) {
    function cgoLiveOrderEnabled(): bool
    {
        return cgoSitePrefix() !== '' && !empty(cgoConfig()['live_order_enabled']);
    }
}

if (!function_exists('cgoLiveOrderAdminOnly')) {
    function cgoLiveOrderAdminOnly(): bool
    {
        return cgoLiveOrderEnabled() && !empty(cgoConfig()['live_order_admin_only']);
    }
}

if (!function_exists('cgoLiveOrderVisibleToCurrentSession')) {
    function cgoLiveOrderVisibleToCurrentSession(): bool
    {
        if (!cgoLiveOrderEnabled()) return false;
        if (!cgoLiveOrderAdminOnly()) return true;
        return isset($_SESSION['role']) && hash_equals('admin', (string) $_SESSION['role']);
    }
}

if (!function_exists('cgoLiveOrderAllowedForRole')) {
    function cgoLiveOrderAllowedForRole(string $role): bool
    {
        if (!cgoLiveOrderEnabled()) return false;
        return !cgoLiveOrderAdminOnly() || hash_equals('admin', strtolower(trim($role)));
    }
}

if (!function_exists('cgoLiveOrderDisabledMessage')) {
    function cgoLiveOrderDisabledMessage(): string
    {
        $prefix = cgoSitePrefix();
        if ($prefix === '') {
            return 'CHEATGAME live ordering is disabled because the website identity is not configured.';
        }
        if (cgoLiveOrderAdminOnly()) {
            return 'CHEATGAME live ordering is currently restricted to administrator test purchases for ' . $prefix . '.';
        }
        return 'CHEATGAME live ordering is disabled for ' . $prefix . '. Product sync and safe API tests remain available.';
    }
}

if (!function_exists('cgoIssuePurchaseToken')) {
    function cgoIssuePurchaseToken(string $scope, int $ttlSeconds = 1800): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        $scope = trim($scope);
        if ($scope === '' || strlen($scope) > 120) return '';
        $ttlSeconds = max(60, min(3600, $ttlSeconds));
        $bucket = 'cgo_purchase_request_tokens';
        if (!isset($_SESSION[$bucket]) || !is_array($_SESSION[$bucket])) {
            $_SESSION[$bucket] = [];
        }
        $now = time();
        foreach ($_SESSION[$bucket] as $existingToken => $tokenData) {
            if (!is_array($tokenData) || (int) ($tokenData['expires_at'] ?? 0) < $now) {
                unset($_SESSION[$bucket][$existingToken]);
            }
        }
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            error_log('CGO purchase token generation failed: ' . $e->getMessage());
            return '';
        }
        $_SESSION[$bucket][$token] = [
            'scope' => $scope,
            'expires_at' => $now + $ttlSeconds,
        ];
        if (count($_SESSION[$bucket]) > 40) {
            $_SESSION[$bucket] = array_slice($_SESSION[$bucket], -40, null, true);
        }
        return $token;
    }
}

if (!function_exists('cgoConsumePurchaseToken')) {
    function cgoConsumePurchaseToken(string $scope, string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return false;
        $scope = trim($scope);
        $token = strtolower(trim($token));
        if ($scope === '' || strlen($scope) > 120 || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return false;
        }
        $bucket = 'cgo_purchase_request_tokens';
        $tokenData = isset($_SESSION[$bucket][$token]) && is_array($_SESSION[$bucket][$token])
            ? $_SESSION[$bucket][$token]
            : null;
        unset($_SESSION[$bucket][$token]);
        if (!is_array($tokenData)) return false;
        if ((int) ($tokenData['expires_at'] ?? 0) < time()) return false;
        return hash_equals($scope, (string) ($tokenData['scope'] ?? ''));
    }
}

if (!function_exists('cgoConfigurationStatus')) {
    function cgoConfigurationStatus(): array
    {
        $config = cgoConfig();
        $url = parse_url((string) $config['endpoint']);
        $validEndpoint = is_array($url)
            && strtolower((string) ($url['scheme'] ?? '')) === 'https'
            && strtolower((string) ($url['host'] ?? '')) === 'cheatgame.online'
            && (string) ($url['path'] ?? '') === '/reseller_api.php';
        $validApiKey = preg_match('/^cgo_rsl_[A-Za-z0-9_\-]{20,200}$/', (string) $config['api_key']) === 1;
        $validWebhookSecret = strlen((string) $config['webhook_secret']) >= 32;
        $validIp = $config['allowed_server_ip'] === '' || filter_var($config['allowed_server_ip'], FILTER_VALIDATE_IP) !== false;
        $imageBaseUrl = trim((string) ($config['product_image_base_url'] ?? ''));
        $validImageBaseUrl = $imageBaseUrl === '' || (
            filter_var($imageBaseUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($imageBaseUrl, PHP_URL_SCHEME)) === 'https'
        );
        $sitePrefix = cgoSitePrefix();
        $siteIdentityReady = $sitePrefix !== '';
        $webhookRole = strtolower(trim((string) ($config['webhook_role'] ?? '')));
        $validWebhookRole = in_array($webhookRole, ['hub', 'receiver', 'local'], true);
        $webhookPublicUrl = trim((string) ($config['webhook_public_url'] ?? ''));
        $validWebhookPublicUrl = $webhookPublicUrl !== ''
            && filter_var($webhookPublicUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($webhookPublicUrl, PHP_URL_SCHEME)) === 'https';

        return [
            'ready' => $validEndpoint && $validApiKey,
            'order_ready' => $validEndpoint && $validApiKey && $siteIdentityReady && !empty($config['live_order_enabled']),
            'endpoint_valid' => $validEndpoint,
            'api_key_valid' => $validApiKey,
            'webhook_ready' => $validWebhookSecret && $siteIdentityReady && $validWebhookRole,
            'webhook_secret_configured' => $validWebhookSecret,
            'allowed_ip_valid' => $validIp,
            'product_image_base_url_valid' => $validImageBaseUrl,
            'product_image_base_url_configured' => $imageBaseUrl !== '' && $validImageBaseUrl,
            'site_identity_ready' => $siteIdentityReady,
            'site_profile' => (string) ($config['site_profile'] ?? ''),
            'site_code' => (string) ($config['site_code'] ?? ''),
            'database_code' => (string) ($config['database_code'] ?? ''),
            'site_prefix' => $sitePrefix,
            'live_order_enabled' => !empty($config['live_order_enabled']) && $siteIdentityReady,
            'live_order_admin_only' => !empty($config['live_order_admin_only']) && !empty($config['live_order_enabled']) && $siteIdentityReady,
            'webhook_role' => $validWebhookRole ? $webhookRole : 'invalid',
            'webhook_public_url' => $validWebhookPublicUrl ? $webhookPublicUrl : '',
            'endpoint' => (string) $config['endpoint'],
            'allowed_server_ip' => (string) $config['allowed_server_ip'],
            'api_key_source' => (string) ($config['api_key_source'] ?? 'unknown'),
            'webhook_secret_source' => (string) ($config['webhook_secret_source'] ?? 'unknown'),
            'allowed_server_ip_source' => (string) ($config['allowed_server_ip_source'] ?? 'unknown'),
            'product_image_base_url' => $imageBaseUrl,
            'product_image_base_url_source' => (string) ($config['product_image_base_url_source'] ?? 'unknown'),
            'force_ipv4' => !empty($config['force_ipv4']),
            'force_ipv4_source' => (string) ($config['force_ipv4_source'] ?? 'unknown'),
            'api_key_fingerprint' => $config['api_key'] === '' ? '' : substr(hash('sha256', (string) $config['api_key']), 0, 12),
        ];
    }
}

if (!function_exists('cgoMaskSecret')) {
    function cgoMaskSecret(string $value): string
    {
        $length = strlen($value);
        if ($length < 12) {
            return $value === '' ? '' : str_repeat('•', $length);
        }
        return substr($value, 0, 8) . str_repeat('•', min(24, $length - 12)) . substr($value, -4);
    }
}

if (!function_exists('cgoRuntimeSchemaReady')) {
    function cgoRuntimeSchemaReady(bool $refresh = false): bool
    {
        static $ready = null;
        if ($refresh) $ready = null;
        if ($ready !== null) return $ready;
        if (!function_exists('sakazukiTableReady') || !function_exists('sakazukiTableColumnsReady')) return $ready = false;
        foreach (['cgo_products','cgo_orders','cgo_order_keys','cgo_order_api_attempts','cgo_inventory_state','cgo_catalog_links'] as $table) {
            if (!sakazukiTableReady($table, $refresh)) return $ready = false;
        }
        if (!sakazukiTableColumnsReady('cgo_orders', [
            'id','external_ref','user_id','cgo_product_id','remote_product_id','quantity','status','supplier_order_id','transaction_id',
            'source_kind','source_order_id','local_product_id','local_variant_id','unit_cost_base','total_cost_base','unit_price_base',
            'total_price_base','response_json','error_message','created_at','updated_at','completed_at','inventory_adjusted',
        ], $refresh)) return $ready = false;
        if (!sakazukiTableColumnsReady('cgo_order_api_attempts', [
            'id','order_id','phase','request_id','request_started_at_ms','request_finished_at_ms','pretransfer_time_ms','diagnostics_json','decision'
        ], $refresh)) return $ready = false;
        return $ready = true;
    }
}

if (!function_exists('cgoEnsureTables')) {
    function cgoEnsureTables(): bool
    {
        global $conn;
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
        $migrationsAllowed = !function_exists('sakazukiSchemaMigrationsAllowed') || sakazukiSchemaMigrationsAllowed();
        $prepared = function_exists('sakazukiStorefrontSchemaPrepared') && sakazukiStorefrontSchemaPrepared();
        if (!$migrationsAllowed) return $ready = cgoRuntimeSchemaReady();
        if ($prepared && cgoRuntimeSchemaReady()) return $ready = true;
        $queries = [
            "CREATE TABLE IF NOT EXISTS cgo_products (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                remote_product_id VARCHAR(120) NOT NULL,
                name VARCHAR(255) NOT NULL,
                brand VARCHAR(190) NULL,
                description TEXT NULL,
                image_path VARCHAR(500) NULL,
                cached_image_path VARCHAR(500) NULL,
                category VARCHAR(190) NULL,
                duration VARCHAR(120) NULL,
                platform VARCHAR(60) NULL,
                remote_status VARCHAR(60) NULL,
                remote_stock INT NULL,
                currency VARCHAR(12) NULL,
                price_usd DECIMAL(16,6) NOT NULL,
                price_idr DECIMAL(20,2) NULL,
                exchange_rate_idr DECIMAL(20,6) NULL,
                cost_base DECIMAL(16,2) NOT NULL,
                user_price_base DECIMAL(16,2) NOT NULL,
                reseller_price_base DECIMAL(16,2) NOT NULL,
                price_sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
                manual_price_saved TINYINT(1) NOT NULL DEFAULT 0,
                saved_user_price_base DECIMAL(16,2) NULL,
                saved_reseller_price_base DECIMAL(16,2) NULL,
                price_warning_code VARCHAR(60) NULL,
                price_warning_at DATETIME NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                raw_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_synced_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                inventory_checked_at DATETIME NULL,
                local_inventory_adjusted_at DATETIME NULL,
                supplier_removed_at DATETIME NULL,
                enabled_before_supplier_removal TINYINT(1) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_remote_product (remote_product_id),
                KEY idx_cgo_enabled (enabled),
                KEY idx_cgo_remote_status (remote_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                external_ref VARCHAR(120) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                cgo_product_id BIGINT UNSIGNED NOT NULL,
                remote_product_id VARCHAR(120) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                customer_name VARCHAR(190) NOT NULL,
                customer_email VARCHAR(190) NOT NULL,
                local_product_id INT NULL,
                local_variant_id INT NULL,
                unit_cost_base DECIMAL(16,2) NOT NULL,
                total_cost_base DECIMAL(16,2) NOT NULL,
                unit_price_base DECIMAL(16,2) NOT NULL,
                total_price_base DECIMAL(16,2) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'submitting',
                supplier_order_id VARCHAR(190) NULL,
                transaction_id BIGINT UNSIGNED NULL,
                source_kind VARCHAR(40) NOT NULL DEFAULT 'storefront',
                source_order_id BIGINT UNSIGNED NULL,
                response_json LONGTEXT NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                inventory_adjusted TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_external_ref (external_ref),
                KEY idx_cgo_order_user (user_id, created_at),
                KEY idx_cgo_supplier_order (supplier_order_id),
                KEY idx_cgo_order_transaction (transaction_id),
                KEY idx_cgo_order_status (status),
                UNIQUE KEY uq_cgo_order_source (source_kind, source_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_order_keys (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                key_code TEXT NOT NULL,
                key_hash CHAR(64) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_order_key (order_id, key_hash),
                KEY idx_cgo_key_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_order_api_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                phase VARCHAR(40) NOT NULL,
                lookup_mode VARCHAR(40) NULL,
                external_ref VARCHAR(120) NULL,
                supplier_order_id VARCHAR(190) NULL,
                http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                transport_error TINYINT(1) NOT NULL DEFAULT 0,
                curl_errno INT NOT NULL DEFAULT 0,
                error_message VARCHAR(1000) NULL,
                provider_error_code VARCHAR(190) NULL,
                provider_error_class VARCHAR(120) NULL,
                provider_status VARCHAR(80) NULL,
                echoed_external_ref VARCHAR(120) NULL,
                discovered_supplier_order_id VARCHAR(190) NULL,
                delivered_key_count INT UNSIGNED NOT NULL DEFAULT 0,
                primary_ip VARCHAR(80) NULL,
                local_ip VARCHAR(80) NULL,
                cf_ray VARCHAR(190) NULL,
                request_id VARCHAR(190) NULL,
                request_started_at_ms BIGINT UNSIGNED NULL,
                request_finished_at_ms BIGINT UNSIGNED NULL,
                namelookup_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                connect_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                appconnect_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                pretransfer_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                starttransfer_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                total_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
                response_sha256 CHAR(64) NULL,
                diagnostics_json LONGTEXT NULL,
                decision VARCHAR(120) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cgo_api_attempt_order (order_id, id),
                KEY idx_cgo_api_attempt_ref (external_ref, id),
                KEY idx_cgo_api_attempt_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_webhook_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(190) NOT NULL,
                event_name VARCHAR(100) NOT NULL,
                event_timestamp BIGINT NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                processing_status VARCHAR(40) NOT NULL DEFAULT 'received',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_event_id (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_global_webhook_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(190) NOT NULL,
                event_name VARCHAR(100) NOT NULL,
                event_timestamp BIGINT NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                route_prefix VARCHAR(40) NOT NULL,
                processing_status VARCHAR(40) NOT NULL DEFAULT 'processing',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
                response_http SMALLINT UNSIGNED NULL,
                last_error TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_global_event_id (event_id),
                KEY idx_cgo_global_event_status (processing_status, updated_at),
                KEY idx_cgo_global_event_route (route_prefix, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_inventory_state (
                id TINYINT UNSIGNED NOT NULL,
                last_attempt_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                lock_token CHAR(32) NULL,
                lock_expires_at DATETIME NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cgo_catalog_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cgo_product_id BIGINT UNSIGNED NOT NULL,
                local_product_id INT NOT NULL,
                local_variant_id INT NOT NULL,
                link_mode VARCHAR(20) NOT NULL DEFAULT 'existing',
                sync_details TINYINT(1) NOT NULL DEFAULT 0,
                sync_price TINYINT(1) NOT NULL DEFAULT 0,
                api_fallback_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cgo_catalog_product (cgo_product_id),
                UNIQUE KEY uq_cgo_catalog_variant (local_variant_id),
                KEY idx_cgo_catalog_local_product (local_product_id),
                KEY idx_cgo_catalog_fallback (api_fallback_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $sql) {
            try {
                if (!$conn->query($sql)) {
                    error_log('CGO table creation failed: ' . $conn->error);
                    $ready = false;
                    return false;
                }
            } catch (Throwable $e) {
                error_log('CGO table creation exception: ' . $e->getMessage());
                $ready = false;
                return false;
            }
        }

        // Small forward-compatible migration for installations that created the
        // order table with an earlier integration build.
        $requiredColumns = [
            // Older CGO builds created orders before the authoritative transaction
            // link was introduced. Add the link before creating its index so a
            // legacy production schema cannot fail during initialization.
            'transaction_id' => "ALTER TABLE cgo_orders ADD COLUMN transaction_id BIGINT UNSIGNED NULL",
            'source_kind' => "ALTER TABLE cgo_orders ADD COLUMN source_kind VARCHAR(40) NOT NULL DEFAULT 'storefront' AFTER transaction_id",
            'source_order_id' => "ALTER TABLE cgo_orders ADD COLUMN source_order_id BIGINT UNSIGNED NULL AFTER source_kind",
            'local_product_id' => "ALTER TABLE cgo_orders ADD COLUMN local_product_id INT NULL AFTER customer_email",
            'local_variant_id' => "ALTER TABLE cgo_orders ADD COLUMN local_variant_id INT NULL AFTER local_product_id",
            'unit_cost_base' => "ALTER TABLE cgo_orders ADD COLUMN unit_cost_base DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER local_variant_id",
            'total_cost_base' => "ALTER TABLE cgo_orders ADD COLUMN total_cost_base DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER unit_cost_base",
            'inventory_adjusted' => "ALTER TABLE cgo_orders ADD COLUMN inventory_adjusted TINYINT(1) NOT NULL DEFAULT 0 AFTER completed_at",
        ];

        $requiredProductColumns = [
            'brand' => "ALTER TABLE cgo_products ADD COLUMN brand VARCHAR(190) NULL AFTER name",
            'image_path' => "ALTER TABLE cgo_products ADD COLUMN image_path VARCHAR(500) NULL AFTER description",
            'cached_image_path' => "ALTER TABLE cgo_products ADD COLUMN cached_image_path VARCHAR(500) NULL AFTER image_path",
            'currency' => "ALTER TABLE cgo_products ADD COLUMN currency VARCHAR(12) NULL AFTER remote_stock",
            'price_sync_enabled' => "ALTER TABLE cgo_products ADD COLUMN price_sync_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER reseller_price_base",
            'manual_price_saved' => "ALTER TABLE cgo_products ADD COLUMN manual_price_saved TINYINT(1) NOT NULL DEFAULT 0 AFTER price_sync_enabled",
            'saved_user_price_base' => "ALTER TABLE cgo_products ADD COLUMN saved_user_price_base DECIMAL(16,2) NULL AFTER manual_price_saved",
            'saved_reseller_price_base' => "ALTER TABLE cgo_products ADD COLUMN saved_reseller_price_base DECIMAL(16,2) NULL AFTER saved_user_price_base",
            'price_warning_code' => "ALTER TABLE cgo_products ADD COLUMN price_warning_code VARCHAR(60) NULL AFTER saved_reseller_price_base",
            'price_warning_at' => "ALTER TABLE cgo_products ADD COLUMN price_warning_at DATETIME NULL AFTER price_warning_code",
            'inventory_checked_at' => "ALTER TABLE cgo_products ADD COLUMN inventory_checked_at DATETIME NULL AFTER last_synced_at",
            'local_inventory_adjusted_at' => "ALTER TABLE cgo_products ADD COLUMN local_inventory_adjusted_at DATETIME NULL AFTER inventory_checked_at",
            'supplier_removed_at' => "ALTER TABLE cgo_products ADD COLUMN supplier_removed_at DATETIME NULL AFTER local_inventory_adjusted_at",
            'enabled_before_supplier_removal' => "ALTER TABLE cgo_products ADD COLUMN enabled_before_supplier_removal TINYINT(1) NULL AFTER supplier_removed_at",
        ];
        $requiredAttemptColumns = [
            'request_started_at_ms' => "ALTER TABLE cgo_order_api_attempts ADD COLUMN request_started_at_ms BIGINT UNSIGNED NULL AFTER request_id",
            'request_finished_at_ms' => "ALTER TABLE cgo_order_api_attempts ADD COLUMN request_finished_at_ms BIGINT UNSIGNED NULL AFTER request_started_at_ms",
            'pretransfer_time_ms' => "ALTER TABLE cgo_order_api_attempts ADD COLUMN pretransfer_time_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER appconnect_time_ms",
        ];
        foreach ($requiredAttemptColumns as $column => $alterSql) {
            $check = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cgo_order_api_attempts' AND COLUMN_NAME = ?");
            if (!$check) { $ready = false; return false; }
            $check->bind_param('s', $column);
            $check->execute();
            $result = $check->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $check->close();
            if (!$row || (int) $row['c'] === 0) {
                if (!$conn->query($alterSql)) {
                    error_log('CGO API-attempt column migration failed for ' . $column . ': ' . $conn->error);
                    $ready = false;
                    return false;
                }
            }
        }

        $addedProductColumns = [];
        foreach ($requiredProductColumns as $column => $alterSql) {
            $check = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cgo_products' AND COLUMN_NAME = ?");
            if (!$check) { $ready = false; return false; }
            $check->bind_param('s', $column);
            $check->execute();
            $result = $check->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $check->close();
            if (!$row || (int) $row['c'] === 0) {
                if (!$conn->query($alterSql)) {
                    error_log('CGO product column migration failed for ' . $column . ': ' . $conn->error);
                    $ready = false;
                    return false;
                }
                $addedProductColumns[$column] = true;
            }
        }

        // V7 migration: older builds did not record whether a number was entered
        // manually. Snapshot every existing row once, because the current database
        // values are the only factual prices available and discarding any of them
        // would risk erasing administrator work. New products remain automatic
        // until their prices are saved.
        if (isset($addedProductColumns['manual_price_saved'])
            || isset($addedProductColumns['saved_user_price_base'])
            || isset($addedProductColumns['saved_reseller_price_base'])) {
            if (!$conn->query("UPDATE cgo_products
                SET manual_price_saved = 1,
                    saved_user_price_base = user_price_base,
                    saved_reseller_price_base = reseller_price_base,
                    price_sync_enabled = 0
                WHERE saved_user_price_base IS NULL
                  AND saved_reseller_price_base IS NULL")) {
                error_log('CGO manual-price migration failed: ' . $conn->error);
                $ready = false;
                return false;
            }
        }

        $platformLengthResult = $conn->query("SELECT CHARACTER_MAXIMUM_LENGTH AS max_len FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cgo_products' AND COLUMN_NAME = 'platform' LIMIT 1");
        $platformLengthRow = $platformLengthResult ? $platformLengthResult->fetch_assoc() : null;
        if ($platformLengthRow && (int) ($platformLengthRow['max_len'] ?? 0) < 60) {
            if (!$conn->query("ALTER TABLE cgo_products MODIFY COLUMN platform VARCHAR(60) NULL")) {
                error_log('CGO product platform migration failed: ' . $conn->error);
                $ready = false;
                return false;
            }
        }
        $addedOrderColumns = [];
        foreach ($requiredColumns as $column => $alterSql) {
            $check = $conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cgo_orders' AND COLUMN_NAME = ?");
            if (!$check) { $ready = false; return false; }
            $check->bind_param('s', $column);
            $check->execute();
            $result = $check->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $check->close();
            if (!$row || (int) $row['c'] === 0) {
                if (!$conn->query($alterSql)) {
                    error_log('CGO column migration failed for ' . $column . ': ' . $conn->error);
                    $ready = false;
                    return false;
                }
                $addedOrderColumns[$column] = true;
            }
        }

        // Existing accepted orders predate the local inventory-adjustment flag.
        // Mark them handled during migration; supplier stock already reflects
        // those historical purchases and replaying a late webhook must not reduce
        // the new cache a second time.
        if (isset($addedOrderColumns['inventory_adjusted'])) {
            if (!$conn->query("UPDATE cgo_orders
                SET inventory_adjusted = 1
                WHERE status IN ('success', 'completed', 'processing', 'manual_review')")) {
                error_log('CGO historical inventory migration failed: ' . $conn->error);
                $ready = false;
                return false;
            }
        }

        if (!$conn->query("INSERT IGNORE INTO cgo_inventory_state (id) VALUES (1)")) {
            error_log('CGO inventory state initialization failed: ' . $conn->error);
            $ready = false;
            return false;
        }

        $txIndexCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cgo_orders' AND INDEX_NAME='idx_cgo_order_transaction'");
        $txIndexRow = $txIndexCheck ? $txIndexCheck->fetch_assoc() : null;
        if (!$txIndexRow || (int) ($txIndexRow['c'] ?? 0) === 0) {
            if (!$conn->query("ALTER TABLE cgo_orders ADD INDEX idx_cgo_order_transaction (transaction_id)")) {
                // This index improves History/Transactions/Profit lookups but is
                // not required for correctness. Do not disable purchasing merely
                // because a restricted hosting account cannot add an index.
                error_log('CGO transaction index migration warning: ' . $conn->error);
            }
        }

        $sourceIndexCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cgo_orders' AND INDEX_NAME='uq_cgo_order_source'");
        $sourceIndexRow = $sourceIndexCheck ? $sourceIndexCheck->fetch_assoc() : null;
        if (!$sourceIndexRow || (int) ($sourceIndexRow['c'] ?? 0) === 0) {
            if (!$conn->query("ALTER TABLE cgo_orders ADD UNIQUE INDEX uq_cgo_order_source (source_kind, source_order_id)")) {
                // Existing rows use NULL source_order_id, so this should normally
                // succeed. If hosting blocks ALTER, the application idempotency
                // guards still apply and this warning remains visible in logs.
                error_log('CGO source-link unique index migration warning: ' . $conn->error);
            }
        }

        $webhookUpdatedAtCheck = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cgo_webhook_events' AND COLUMN_NAME = 'updated_at'");
        $webhookUpdatedAtRow = $webhookUpdatedAtCheck ? $webhookUpdatedAtCheck->fetch_assoc() : null;
        if (!$webhookUpdatedAtRow || (int) ($webhookUpdatedAtRow['c'] ?? 0) === 0) {
            if (!$conn->query("ALTER TABLE cgo_webhook_events ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at")) {
                error_log('CGO webhook updated_at migration failed: ' . $conn->error);
                $ready = false;
                return false;
            }
        }
        if (!cgoRuntimeSchemaReady(true)) {
            error_log('CGO schema migration completed but runtime readiness validation failed');
            $ready = false;
            return false;
        }
        $ready = true;
        return true;
    }
}

if (!function_exists('cgoGetOrderSourceLink')) {
    function cgoGetOrderSourceLink(int $orderId): array
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return ['source_kind' => 'storefront', 'source_order_id' => 0];
        $stmt = $conn->prepare("SELECT source_kind,source_order_id FROM cgo_orders WHERE id=? LIMIT 1");
        if (!$stmt) return ['source_kind' => 'storefront', 'source_order_id' => 0];
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return [
            'source_kind' => strtolower(trim((string) ($row['source_kind'] ?? 'storefront'))),
            'source_order_id' => max(0, (int) ($row['source_order_id'] ?? 0)),
        ];
    }
}

if (!function_exists('cgoCommerceCenterSyncSafe')) {
    /**
     * Store-API procurement is an upstream cost/delivery leg of the Store API
     * sale, not a second customer sale. Route commerce sync to the parent order
     * so revenue and delivered keys are not double-counted.
     */
    function cgoCommerceCenterSyncSafe(int $orderId): void
    {
        if ($orderId < 1 || !function_exists('commerceCenterSyncSafe')) return;
        $source = cgoGetOrderSourceLink($orderId);
        if (($source['source_kind'] ?? '') === 'store_api' && (int) ($source['source_order_id'] ?? 0) > 0) {
            commerceCenterSyncSafe('store_api_sale', (int) $source['source_order_id']);
            return;
        }
        commerceCenterSyncSafe('cgo_purchase', $orderId);
    }
}

if (!function_exists('cgoOrderCancelApiResponseIsSuccessful')) {
    /**
     * Normalize CHEATGAME's successful order_cancel response. The provider uses
     * an informational error code such as order_cancelled even when success=true,
     * final=true and the external_ref is sealed. Generic error detection must not
     * turn that successful fencing result into ok=false.
     */
    function cgoOrderCancelApiResponseIsSuccessful(array $data, string $requestedExternalRef, int $httpCode): bool
    {
        if ($httpCode < 200 || $httpCode >= 300) return false;
        if (cgoFindRecursiveBoolean($data, 'success') !== true) return false;
        if (cgoExtractKeys($data) !== []) return false;

        $requestedExternalRef = trim($requestedExternalRef);
        $echoedExternalRef = trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? ''));
        if ($requestedExternalRef !== '') {
            if ($echoedExternalRef === '') return false;
            if (!hash_equals(strtoupper($requestedExternalRef), strtoupper($echoedExternalRef))) return false;
        }

        $status = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? '')));
        $final = cgoFindRecursiveBoolean($data, 'final');
        $sealed = cgoFindRecursiveBoolean($data, 'sealed');
        $safeToRefund = cgoFindRecursiveBoolean($data, 'safe_to_refund');

        if (in_array($status, ['success', 'completed'], true)) return false;
        return $final === true
            && ($sealed === true || $safeToRefund === true || in_array($status, ['cancelled', 'canceled', 'sealed'], true));
    }
}

if (!function_exists('cgoApiRequest')) {
    function cgoApiRequest(string $action, string $method = 'GET', array $payload = [], ?int $timeoutOverrideSeconds = null): array
    {
        $action = trim($action);
        $method = strtoupper(trim($method));
        $status = cgoConfigurationStatus();
        $config = cgoConfig();

        $baseResult = [
            'ok' => false,
            'http_code' => 0,
            'data' => null,
            'raw' => '',
            'error' => '',
            'transport_error' => false,
            'curl_errno' => 0,
            'curl_error' => '',
            'primary_ip' => '',
            'local_ip' => '',
            'effective_url' => '',
            'namelookup_time_ms' => 0,
            'connect_time_ms' => 0,
            'appconnect_time_ms' => 0,
            'pretransfer_time_ms' => 0,
            'starttransfer_time_ms' => 0,
            'total_time_ms' => 0,
            'action' => $action,
            'ip_mode' => !empty($config['force_ipv4']) ? 'IPv4 forced' : 'automatic',
            'response_headers' => [],
            'provider_error_code' => '',
            'provider_error_class' => '',
            'cf_ray' => '',
            'request_id' => '',
            'request_started_at_ms' => 0,
            'request_finished_at_ms' => 0,
            'cf_error_type' => '',
            'cf_error_origin' => '',
            'retry_after' => '',
        ];

        if (!$status['ready']) {
            return array_merge($baseResult, ['error' => 'CHEATGAME API configuration is incomplete']);
        }
        $allowedActions = ['products', 'balance', 'exchange_rate', 'order', 'order_status', 'order_cancel'];
        if (!in_array($action, $allowedActions, true)) {
            return array_merge($baseResult, ['error' => 'Invalid API action']);
        }
        if ($action === 'order' && !cgoLiveOrderEnabled()) {
            return array_merge($baseResult, ['error' => cgoLiveOrderDisabledMessage()]);
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return array_merge($baseResult, ['error' => 'Invalid HTTP method']);
        }
        if (!function_exists('curl_init')) {
            return array_merge($baseResult, ['error' => 'PHP cURL extension is not available']);
        }

        $url = (string) $config['endpoint'];
        if ($method === 'GET') {
            $query = array_merge($payload, ['action' => $action]);
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } else {
            $payload = array_merge($payload, ['action' => $action]);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return array_merge($baseResult, ['error' => 'Unable to initialize PHP cURL']);
        }

        $response = '';
        $tooLarge = false;
        $responseHeaders = [];
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . (string) $config['api_key'],
            'User-Agent: Sakazuki-CHEATGAME/1.1',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => in_array($action, ['order', 'order_status', 'order_cancel'], true)
                ? min((int) $config['connect_timeout'], (int) ($config['order_connect_timeout_seconds'] ?? 3), $timeoutOverrideSeconds === null ? PHP_INT_MAX : max(2, $timeoutOverrideSeconds))
                : min((int) $config['connect_timeout'], $timeoutOverrideSeconds === null ? (int) $config['connect_timeout'] : max(2, $timeoutOverrideSeconds)),
            CURLOPT_TIMEOUT => $timeoutOverrideSeconds === null
                ? (int) $config['request_timeout']
                : max(3, min(60, $timeoutOverrideSeconds)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, ':') === false) {
                    return $length;
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                if (in_array($name, ['cf-ray', 'cf-error-type', 'cf-error-origin', 'retry-after', 'x-request-id', 'x-correlation-id', 'server', 'content-type'], true)) {
                    $responseHeaders[$name] = $value;
                }
                return $length;
            },
        ];
        if (!empty($config['force_ipv4']) && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if ($method === 'POST') {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) {
                curl_close($ch);
                return array_merge($baseResult, ['error' => 'Unable to encode API payload']);
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $options);

        if (function_exists('configureBoundedCurlResponse')) {
            configureBoundedCurlResponse($ch, $response, $tooLarge, 2 * 1024 * 1024);
        } else {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 2 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            });
        }

        $requestStartedAtMs = (int) round(microtime(true) * 1000);
        $executed = curl_exec($ch);
        $requestFinishedAtMs = (int) round(microtime(true) * 1000);
        $curlNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $localIp = defined('CURLINFO_LOCAL_IP') ? (string) curl_getinfo($ch, CURLINFO_LOCAL_IP) : '';
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $nameLookupTimeMs = defined('CURLINFO_NAMELOOKUP_TIME') ? (int) round(((float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME)) * 1000) : 0;
        $connectTimeMs = defined('CURLINFO_CONNECT_TIME') ? (int) round(((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME)) * 1000) : 0;
        $appConnectTimeMs = defined('CURLINFO_APPCONNECT_TIME') ? (int) round(((float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME)) * 1000) : 0;
        $pretransferTimeMs = defined('CURLINFO_PRETRANSFER_TIME') ? (int) round(((float) curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME)) * 1000) : 0;
        $startTransferTimeMs = defined('CURLINFO_STARTTRANSFER_TIME') ? (int) round(((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME)) * 1000) : 0;
        $totalTimeMs = (int) round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        curl_close($ch);

        $diagnostics = [
            'http_code' => $httpCode,
            'raw' => $response,
            'curl_errno' => $curlNo,
            'curl_error' => $curlError,
            'primary_ip' => $primaryIp,
            'local_ip' => $localIp,
            'effective_url' => $effectiveUrl,
            'namelookup_time_ms' => $nameLookupTimeMs,
            'connect_time_ms' => $connectTimeMs,
            'appconnect_time_ms' => $appConnectTimeMs,
            'pretransfer_time_ms' => $pretransferTimeMs,
            'starttransfer_time_ms' => $startTransferTimeMs,
            'total_time_ms' => $totalTimeMs,
            'request_started_at_ms' => $requestStartedAtMs,
            'request_finished_at_ms' => $requestFinishedAtMs,
            'response_headers' => $responseHeaders,
            'cf_ray' => (string) ($responseHeaders['cf-ray'] ?? ''),
            'request_id' => (string) ($responseHeaders['x-request-id'] ?? ($responseHeaders['x-correlation-id'] ?? '')),
            'cf_error_type' => (string) ($responseHeaders['cf-error-type'] ?? ''),
            'cf_error_origin' => (string) ($responseHeaders['cf-error-origin'] ?? ''),
            'retry_after' => (string) ($responseHeaders['retry-after'] ?? ''),
        ];

        if ($executed === false || $curlNo !== 0 || $tooLarge) {
            $message = $tooLarge ? 'API response exceeded the size limit' : ('Network error ' . $curlNo . ($curlError !== '' ? ': ' . $curlError : ''));
            $result = array_merge($baseResult, $diagnostics, [
                'error' => $message,
                'transport_error' => true,
            ]);
            error_log('CGO API transport failure action=' . $action . ' http=' . $httpCode . ' curl=' . $curlNo . ' message=' . $message);
            return $result;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $result = array_merge($baseResult, $diagnostics, [
                'error' => 'API returned invalid JSON',
                'transport_error' => false,
            ]);
            error_log('CGO API invalid JSON action=' . $action . ' http=' . $httpCode . ' primary_ip=' . $primaryIp);
            return $result;
        }

        $httpOk = $httpCode >= 200 && $httpCode < 300;
        $providerFailure = cgoDetectProviderFailure($data);
        // CHEATGAME intentionally returns error=order_cancelled inside a successful
        // final cancellation response. Treat that as action success only when the
        // fencing invariants are satisfied; keep the provider code for auditing.
        if ($action === 'order_cancel'
            && cgoOrderCancelApiResponseIsSuccessful($data, (string) ($payload['external_ref'] ?? ''), $httpCode)) {
            $providerFailure = null;
        }
        $providerErrorCode = cgoExtractProviderErrorCode($data);
        $providerErrorClass = '';
        if ($httpCode >= 500 && preg_match('/^auth_[a-z0-9_]*failed$/i', $providerErrorCode) === 1) {
            $providerErrorClass = 'provider_auth_internal';
        } elseif (in_array($httpCode, [401, 403], true)) {
            $providerErrorClass = 'authentication_rejected';
        } elseif ($httpCode >= 500) {
            $providerErrorClass = 'provider_internal';
        }
        $ok = $httpOk && $providerFailure === null;
        $error = '';
        if (!$httpOk) {
            $error = cgoExtractErrorMessage($data, 'HTTP ' . $httpCode);
        } elseif ($providerFailure !== null) {
            $error = $providerFailure;
        }

        $result = array_merge($baseResult, $diagnostics, [
            'ok' => $ok,
            'data' => $data,
            'error' => $error,
            'transport_error' => false,
            'provider_error_code' => $providerErrorCode,
            'provider_error_class' => $providerErrorClass,
        ]);
        if (!$ok) {
            error_log('CGO API provider failure action=' . $action . ' http=' . $httpCode . ' primary_ip=' . $primaryIp . ' message=' . $error);
        }
        return $result;
    }
}

if (!function_exists('cgoOrderResponseJson')) {
    /**
     * Keep the provider payload unchanged when it is valid JSON so all existing
     * key/account extractors remain compatible. For network/HTML failures keep a
     * small diagnostic snapshot instead of losing the HTTP evidence entirely.
     */
    function cgoOrderResponseJson(array $api): ?string
    {
        if (isset($api['data']) && is_array($api['data'])) {
            $json = json_encode($api['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($json) ? $json : null;
        }

        $rawPreview = trim((string) ($api['raw'] ?? ''));
        if ($rawPreview !== '') {
            $rawPreview = html_entity_decode(strip_tags($rawPreview), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rawPreview = preg_replace('/\s+/u', ' ', $rawPreview) ?? $rawPreview;
            $rawPreview = substr(trim($rawPreview), 0, 2000);
        }

        $snapshot = [
            '_transport' => [
                'http_code' => (int) ($api['http_code'] ?? 0),
                'transport_error' => !empty($api['transport_error']),
                'curl_errno' => (int) ($api['curl_errno'] ?? 0),
                'curl_error' => substr(trim((string) ($api['curl_error'] ?? '')), 0, 1000),
                'error' => substr(trim((string) ($api['error'] ?? '')), 0, 1000),
                'cf_ray' => substr(trim((string) ($api['cf_ray'] ?? '')), 0, 190),
                'request_id' => substr(trim((string) ($api['request_id'] ?? '')), 0, 190),
                'cf_error_type' => substr(trim((string) ($api['cf_error_type'] ?? '')), 0, 100),
                'cf_error_origin' => substr(trim((string) ($api['cf_error_origin'] ?? '')), 0, 190),
                'retry_after' => substr(trim((string) ($api['retry_after'] ?? '')), 0, 60),
                'primary_ip' => substr(trim((string) ($api['primary_ip'] ?? '')), 0, 80),
                'local_ip' => substr(trim((string) ($api['local_ip'] ?? '')), 0, 80),
                'namelookup_time_ms' => max(0, (int) ($api['namelookup_time_ms'] ?? 0)),
                'connect_time_ms' => max(0, (int) ($api['connect_time_ms'] ?? 0)),
                'appconnect_time_ms' => max(0, (int) ($api['appconnect_time_ms'] ?? 0)),
                'pretransfer_time_ms' => max(0, (int) ($api['pretransfer_time_ms'] ?? 0)),
                'starttransfer_time_ms' => max(0, (int) ($api['starttransfer_time_ms'] ?? 0)),
                'total_time_ms' => max(0, (int) ($api['total_time_ms'] ?? 0)),
                'raw_preview' => $rawPreview,
            ],
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : null;
    }
}

if (!function_exists('cgoSanitizeOrderAttemptValue')) {
    /**
     * Keep provider diagnostics useful without duplicating delivered license
     * keys or authentication material in the diagnostic table.
     */
    function cgoSanitizeOrderAttemptValue($value, int $depth = 0, array $redactedValues = [])
    {
        if ($depth > 7) return '[max-depth]';
        if (is_array($value)) {
            $clean = [];
            $sensitiveNames = [
                'api_key', 'apikey', 'authorization', 'auth', 'token', 'access_token',
                'refresh_token', 'secret', 'signature', 'password', 'webhook_secret',
                'key', 'key_code', 'license', 'license_key', 'activation_key',
            ];
            $sensitiveCollections = ['keys', 'licenses', 'license_keys', 'activation_keys'];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= 80) { $clean['_truncated'] = true; break; }
                $normalizedKey = is_string($key) ? strtolower(trim($key)) : '';
                if ($normalizedKey !== '' && in_array($normalizedKey, $sensitiveCollections, true)) {
                    $clean[$key] = is_array($item) ? ['_redacted_count' => count($item)] : '[redacted]';
                    $count++;
                    continue;
                }
                if ($normalizedKey !== '' && in_array($normalizedKey, $sensitiveNames, true)) {
                    $clean[$key] = '[redacted]';
                    $count++;
                    continue;
                }
                $clean[$key] = cgoSanitizeOrderAttemptValue($item, $depth + 1, $redactedValues);
                $count++;
            }
            return $clean;
        }
        if (is_string($value)) {
            foreach ($redactedValues as $redactedValue) {
                $redactedValue = (string) $redactedValue;
                if ($redactedValue !== '' && hash_equals($redactedValue, $value)) return '[redacted-delivered-key]';
            }
            if (strlen($value) > 2000) return substr($value, 0, 2000) . '...[truncated]';
            return $value;
        }
        if (is_scalar($value) || $value === null) return $value;
        return (string) $value;
    }
}

if (!function_exists('cgoRecordOrderApiAttempt')) {
    /**
     * Persist one diagnostic row for each supplier order/status/cancel request.
     * Product keys are never copied into this table; only structural evidence and
     * timing metadata are stored so an administrator can reconstruct the timeline.
     */
    function cgoRecordOrderApiAttempt(int $orderId, string $phase, array $api, array $context = [], string $decision = ''): int
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return 0;

        $phase = substr(trim($phase), 0, 40);
        if ($phase === '') $phase = 'unknown';
        $lookupMode = substr(trim((string) ($context['lookup_mode'] ?? '')), 0, 40);
        $externalRef = substr(trim((string) ($context['external_ref'] ?? '')), 0, 120);
        $supplierOrderId = substr(trim((string) ($context['supplier_order_id'] ?? '')), 0, 190);
        $httpCode = max(0, min(65535, (int) ($api['http_code'] ?? 0)));
        $transportError = !empty($api['transport_error']) ? 1 : 0;
        $curlNo = (int) ($api['curl_errno'] ?? 0);
        $errorMessage = substr(trim((string) ($api['error'] ?? ($api['curl_error'] ?? ''))), 0, 1000);
        $providerCode = substr(trim((string) ($api['provider_error_code'] ?? '')), 0, 190);

        $data = isset($api['data']) && is_array($api['data']) ? $api['data'] : [];
        $providerStatus = $data !== []
            ? substr(strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? ''))), 0, 80)
            : '';
        $echoedExternalRef = $data !== []
            ? substr(trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? '')), 0, 120)
            : '';
        $discoveredSupplierOrderId = $data !== [] ? (string) (cgoExtractSupplierOrderId($data) ?? '') : '';
        $discoveredSupplierOrderId = substr(trim($discoveredSupplierOrderId), 0, 190);
        $keyCount = $data !== [] ? count(cgoExtractKeys($data)) : 0;

        $nameLookupMs = max(0, (int) ($api['namelookup_time_ms'] ?? 0));
        $connectMs = max(0, (int) ($api['connect_time_ms'] ?? 0));
        $appConnectMs = max(0, (int) ($api['appconnect_time_ms'] ?? 0));
        $pretransferMs = max(0, (int) ($api['pretransfer_time_ms'] ?? 0));
        $startTransferMs = max(0, (int) ($api['starttransfer_time_ms'] ?? 0));
        $totalMs = max(0, (int) ($api['total_time_ms'] ?? 0));
        $primaryIp = substr(trim((string) ($api['primary_ip'] ?? '')), 0, 80);
        $localIp = substr(trim((string) ($api['local_ip'] ?? '')), 0, 80);
        $cfRay = substr(trim((string) ($api['cf_ray'] ?? '')), 0, 190);
        $requestId = substr(trim((string) ($api['request_id'] ?? '')), 0, 190);
        $requestStartedAtMs = max(0, (int) ($api['request_started_at_ms'] ?? 0));
        $requestFinishedAtMs = max(0, (int) ($api['request_finished_at_ms'] ?? 0));
        $providerErrorClass = substr(trim((string) ($api['provider_error_class'] ?? '')), 0, 120);
        $decision = substr(trim($decision), 0, 120);
        $responseTopLevelFields = $data !== [] ? array_slice(array_map('strval', array_keys($data)), 0, 50) : [];
        $diagnostics = [
            'request' => [
                'phase' => $phase,
                'lookup_mode' => $lookupMode !== '' ? $lookupMode : null,
                'external_ref' => $externalRef !== '' ? $externalRef : null,
                'supplier_order_id' => $supplierOrderId !== '' ? $supplierOrderId : null,
            ],
            'transport' => [
                'primary_ip' => $primaryIp,
                'local_ip' => $localIp,
                'effective_url' => substr(trim((string) ($api['effective_url'] ?? '')), 0, 500),
                'cf_ray' => $cfRay,
                'request_id' => $requestId,
                'request_started_at_ms' => $requestStartedAtMs ?: null,
                'request_finished_at_ms' => $requestFinishedAtMs ?: null,
                'ip_mode' => substr(trim((string) ($api['ip_mode'] ?? '')), 0, 40),
                'namelookup_time_ms' => $nameLookupMs,
                'connect_time_ms' => $connectMs,
                'appconnect_time_ms' => $appConnectMs,
                'pretransfer_time_ms' => $pretransferMs,
                'starttransfer_time_ms' => $startTransferMs,
                'total_time_ms' => $totalMs,
                'checkout_timing' => isset($context['checkout_timing']) && is_array($context['checkout_timing'])
                    ? $context['checkout_timing'] : null,
            ],
            'provider' => [
                'error_code' => $providerCode !== '' ? $providerCode : null,
                'error_class' => $providerErrorClass !== '' ? $providerErrorClass : null,
                'status' => $providerStatus !== '' ? $providerStatus : null,
                'final' => $data !== [] ? cgoFindRecursiveBoolean($data, 'final') : null,
                'sealed' => $data !== [] ? cgoFindRecursiveBoolean($data, 'sealed') : null,
                'safe_to_refund' => $data !== [] ? cgoFindRecursiveBoolean($data, 'safe_to_refund') : null,
                'echoed_external_ref' => $echoedExternalRef !== '' ? $echoedExternalRef : null,
                'discovered_supplier_order_id' => $discoveredSupplierOrderId !== '' ? $discoveredSupplierOrderId : null,
                'delivered_key_count' => $keyCount,
                'response_top_level_fields' => $responseTopLevelFields,
                'sanitized_response' => $data !== [] ? cgoSanitizeOrderAttemptValue($data, 0, cgoExtractKeys($data)) : null,
            ],
            'decision' => $decision !== '' ? $decision : null,
        ];
        $diagnosticsJson = json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($diagnosticsJson)) $diagnosticsJson = '{}';
        $raw = isset($api['raw']) && is_string($api['raw']) ? $api['raw'] : '';
        if ($raw === '' && $data !== []) {
            $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($raw)) $raw = '';
        }
        $responseSha = $raw !== '' ? hash('sha256', $raw) : '';

        $stmt = $conn->prepare("INSERT INTO cgo_order_api_attempts
            (order_id, phase, lookup_mode, external_ref, supplier_order_id,
             http_code, transport_error, curl_errno, error_message, provider_error_code,
             provider_error_class, provider_status, echoed_external_ref, discovered_supplier_order_id,
             delivered_key_count, primary_ip, local_ip, cf_ray, request_id,
             request_started_at_ms, request_finished_at_ms,
             namelookup_time_ms, connect_time_ms, appconnect_time_ms, pretransfer_time_ms, starttransfer_time_ms, total_time_ms,
             diagnostics_json, response_sha256, decision)
            VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''),
                    NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?,
                    NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, 0),
                    ?, ?, ?, ?, ?, ?,
                    ?, NULLIF(?, ''), NULLIF(?, ''))");
        if (!$stmt) return 0;
        $stmt->bind_param(
            'issssiiissssssissssiiiiiiiisss',
            $orderId, $phase, $lookupMode, $externalRef, $supplierOrderId,
            $httpCode, $transportError, $curlNo, $errorMessage, $providerCode,
            $providerErrorClass, $providerStatus, $echoedExternalRef, $discoveredSupplierOrderId,
            $keyCount, $primaryIp, $localIp, $cfRay, $requestId,
            $requestStartedAtMs, $requestFinishedAtMs,
            $nameLookupMs, $connectMs, $appConnectMs, $pretransferMs, $startTransferMs, $totalMs,
            $diagnosticsJson, $responseSha, $decision
        );
        $ok = $stmt->execute();
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('cgoUpdateOrderApiAttemptCheckoutTiming')) {
    function cgoUpdateOrderApiAttemptCheckoutTiming(int $attemptId, array $timing): bool
    {
        global $conn;
        if ($attemptId < 1 || !cgoEnsureTables()) return false;
        $stmt = $conn->prepare('SELECT diagnostics_json FROM cgo_order_api_attempts WHERE id=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('i', $attemptId);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return false;
        $diag = json_decode((string) ($row['diagnostics_json'] ?? ''), true);
        if (!is_array($diag)) $diag = [];
        if (!isset($diag['transport']) || !is_array($diag['transport'])) $diag['transport'] = [];
        $clean = [];
        foreach ($timing as $key => $value) {
            if (!is_string($key) || $key === '') continue;
            if ($value === null) { $clean[$key] = null; continue; }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $clean[$key] = max(0, (int) round((float) $value));
                continue;
            }
            if (is_string($value)) $clean[$key] = substr(trim($value), 0, 80);
        }
        $diag['transport']['checkout_timing'] = $clean;
        $json = json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return false;
        $update = $conn->prepare('UPDATE cgo_order_api_attempts SET diagnostics_json=? WHERE id=?');
        if (!$update) return false;
        $update->bind_param('si', $json, $attemptId);
        $ok = $update->execute();
        $update->close();
        return $ok;
    }
}

if (!function_exists('cgoFinalizeOrderCheckoutTiming')) {
    function cgoFinalizeOrderCheckoutTiming(int $orderId, string $source): bool
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return false;
        $stmt = $conn->prepare("SELECT id, diagnostics_json FROM cgo_order_api_attempts WHERE order_id=? AND phase='submit' ORDER BY id DESC LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return false;
        $diag = json_decode((string) ($row['diagnostics_json'] ?? ''), true);
        $transport = is_array($diag) && is_array($diag['transport'] ?? null) ? $diag['transport'] : [];
        $timing = is_array($transport['checkout_timing'] ?? null) ? $transport['checkout_timing'] : [];
        $checkoutStart = max(0, (int) ($timing['checkout_started_at_ms'] ?? 0));
        $lastRequestFinished = 0;
        $latest = $conn->prepare('SELECT MAX(request_finished_at_ms) AS finished_ms FROM cgo_order_api_attempts WHERE order_id=?');
        if ($latest) {
            $latest->bind_param('i', $orderId);
            if ($latest->execute()) {
                $latestResult = $latest->get_result();
                $latestRow = $latestResult ? $latestResult->fetch_assoc() : null;
                $lastRequestFinished = max(0, (int) ($latestRow['finished_ms'] ?? 0));
            }
            $latest->close();
        }
        $finished = (int) round(microtime(true) * 1000);
        if ($checkoutStart > 0) $timing['total_checkout_ms'] = max(0, $finished - $checkoutStart);
        if ($lastRequestFinished > 0) $timing['finalize_ms'] = max(0, $finished - $lastRequestFinished);
        $timing['checkout_finished_at_ms'] = $finished;
        $timing['final_state_source'] = substr(trim($source), 0, 80);
        return cgoUpdateOrderApiAttemptCheckoutTiming((int) $row['id'], $timing);
    }
}

if (!function_exists('cgoGetRecentOrderApiAttempts')) {
    function cgoGetRecentOrderApiAttempts(int $limit = 30, int $orderId = 0): array
    {
        global $conn;
        if (!cgoEnsureTables()) return [];
        $limit = max(1, min(100, $limit));
        if ($orderId > 0) {
            $stmt = $conn->prepare("SELECT * FROM cgo_order_api_attempts WHERE order_id=? ORDER BY id DESC LIMIT ?");
            if (!$stmt) return [];
            $stmt->bind_param('ii', $orderId, $limit);
        } else {
            $stmt = $conn->prepare("SELECT * FROM cgo_order_api_attempts ORDER BY id DESC LIMIT ?");
            if (!$stmt) return [];
            $stmt->bind_param('i', $limit);
        }
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $diag = json_decode((string) ($row['diagnostics_json'] ?? ''), true);
            $row['diagnostics'] = is_array($diag) ? $diag : [];
            unset($row['diagnostics_json']);
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('cgoClearOrderApiAttemptLogs')) {
    /**
     * Clear historical diagnostic supplier request logs only. Attempts belonging
     * to unresolved orders are operational recovery evidence (including the
     * non-final not-found and order_cancel fencing sequence), so they are retained.
     * Business records and webhook de-duplication events are never touched.
     */
    function cgoClearOrderApiAttemptLogs(): array
    {
        global $conn;
        if (!cgoEnsureTables()) return ['success' => false, 'deleted' => 0, 'preserved_pending' => 0, 'message' => 'Diagnostic log storage is unavailable'];
        $activeStatuses = "'submitting','unknown','pending','processing','manual_review'";
        $eligibleSql = "FROM cgo_order_api_attempts a
                        LEFT JOIN cgo_orders o ON o.id=a.order_id
                        WHERE o.id IS NULL OR LOWER(TRIM(COALESCE(o.status,''))) NOT IN ({$activeStatuses})";
        $count = 0;
        $result = $conn->query('SELECT COUNT(*) AS c ' . $eligibleSql);
        if ($result) {
            $row = $result->fetch_assoc();
            $count = max(0, (int) ($row['c'] ?? 0));
            $result->free();
        }
        $preservedPending = 0;
        $pendingResult = $conn->query("SELECT COUNT(*) AS c
            FROM cgo_order_api_attempts a
            INNER JOIN cgo_orders o ON o.id=a.order_id
            WHERE LOWER(TRIM(COALESCE(o.status,''))) IN ({$activeStatuses})");
        if ($pendingResult) {
            $row = $pendingResult->fetch_assoc();
            $preservedPending = max(0, (int) ($row['c'] ?? 0));
            $pendingResult->free();
        }
        if (!$conn->query("DELETE a FROM cgo_order_api_attempts a
                           LEFT JOIN cgo_orders o ON o.id=a.order_id
                           WHERE o.id IS NULL OR LOWER(TRIM(COALESCE(o.status,''))) NOT IN ({$activeStatuses})")) {
            return ['success' => false, 'deleted' => 0, 'preserved_pending' => $preservedPending, 'message' => 'Unable to clear diagnostic API logs'];
        }
        return [
            'success' => true,
            'deleted' => $count,
            'preserved_pending' => $preservedPending,
            'message' => 'Historical diagnostic API logs cleared; unresolved-order recovery evidence was preserved',
        ];
    }
}

if (!function_exists('cgoOrderStrongNotFoundAttemptCount')) {
    function cgoOrderStrongNotFoundAttemptCount(int $orderId): int
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return 0;
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM cgo_order_api_attempts
                                WHERE order_id=? AND phase='status'
                                  AND decision IN ('status_strong_not_found_confirmation_1','status_strong_not_found_confirmation_2','status_strong_not_found_refund')");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) { $stmt->close(); return 0; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return max(0, (int) ($row['c'] ?? 0));
    }
}

if (!function_exists('cgoOrderRequestDefinitelyNotDelivered')) {
    /**
     * Return true only for failures that happen before an HTTP request can reach
     * the supplier application. These are safe to refund immediately. Timeouts
     * after a request may have been sent remain deliberately ambiguous.
     */
    function cgoOrderRequestDefinitelyNotDelivered(array $api): bool
    {
        $httpCode = (int) ($api['http_code'] ?? 0);
        // Cloudflare 521/523/525/526 fail before an HTTP request can reach
        // the origin application. Error 522 is deliberately excluded: Cloudflare
        // documents both pre-connect and post-connect ACK timeout variants, so an
        // order may already have reached the supplier. 520/524 are ambiguous too.
        if (in_array($httpCode, [521, 523, 525, 526], true)) {
            return true;
        }
        if (empty($api['transport_error'])) {
            return false;
        }

        $curlNo = (int) ($api['curl_errno'] ?? 0);
        $safeCurlErrors = [];
        foreach ([
            'CURLE_COULDNT_RESOLVE_PROXY',
            'CURLE_COULDNT_RESOLVE_HOST',
            'CURLE_COULDNT_CONNECT',
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
        ] as $constantName) {
            if (defined($constantName)) {
                $safeCurlErrors[] = (int) constant($constantName);
            }
        }
        return in_array($curlNo, array_values(array_unique($safeCurlErrors)), true);
    }
}

if (!function_exists('cgoStoredOrderFailureDefinitelyNotDelivered')) {
    /**
     * Compatibility recovery for older unknown rows that were created before
     * HTTP diagnostics were stored. Only provider diagnostics that are unambiguously
     * pre-request are eligible. Cloudflare 522 is intentionally NOT auto-refunded.
     */
    function cgoStoredOrderFailureDefinitelyNotDelivered(array $order): bool
    {
        $responseJson = trim((string) ($order['response_json'] ?? ''));
        if ($responseJson !== '') {
            $decoded = json_decode($responseJson, true);
            if (is_array($decoded)) {
                $httpCode = 0;
                if (isset($decoded['_transport']['http_code'])) {
                    $httpCode = (int) $decoded['_transport']['http_code'];
                } elseif (isset($decoded['http_code'])) {
                    $httpCode = (int) $decoded['http_code'];
                } elseif (!empty($decoded['cloudflare_error']) && isset($decoded['status'])) {
                    $httpCode = (int) $decoded['status'];
                } elseif (!empty($decoded['cloudflare_error']) && isset($decoded['error_code'])) {
                    $httpCode = (int) $decoded['error_code'];
                }
                if (in_array($httpCode, [521, 523, 525, 526], true)) {
                    return true;
                }
            }
        }

        // Text alone is not enough to prove non-delivery. In particular, the
        // generic Cloudflare 522 wording can represent more than one timeout stage.
        return false;
    }
}

if (!function_exists('cgoExtractErrorMessage')) {
    function cgoExtractErrorMessage(array $data, string $fallback = 'API request failed'): string
    {
        foreach (['message', 'error', 'detail', 'error_message'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '') {
                    return substr($value, 0, 1000);
                }
            }
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return cgoExtractErrorMessage($data['data'], $fallback);
        }
        return $fallback;
    }
}

if (!function_exists('cgoExtractProviderErrorCode')) {
    function cgoExtractProviderErrorCode(array $data): string
    {
        foreach (['error_code', 'code', 'error'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '' && strlen($value) <= 190) {
                    return $value;
                }
            }
        }
        if (isset($data['data']) && is_array($data['data']) && !cgoArrayIsListCompat($data['data'])) {
            return cgoExtractProviderErrorCode($data['data']);
        }
        return '';
    }
}

if (!function_exists('cgoDetectProviderFailure')) {
    function cgoDetectProviderFailure(array $data): ?string
    {
        foreach (['success', 'ok'] as $flagKey) {
            if (!array_key_exists($flagKey, $data)) {
                continue;
            }
            $flag = $data[$flagKey];
            $isFalse = $flag === false || $flag === 0 || $flag === '0'
                || (is_string($flag) && strtolower(trim($flag)) === 'false');
            if ($isFalse) {
                return cgoExtractErrorMessage($data, 'CHEATGAME rejected the API request');
            }
        }

        if (isset($data['status']) && is_scalar($data['status'])) {
            $status = strtolower(trim((string) $data['status']));
            if (in_array($status, ['error', 'failed', 'failure', 'unauthorized', 'forbidden', 'denied', 'invalid_api_key', 'invalid_key'], true)) {
                return cgoExtractErrorMessage($data, 'CHEATGAME rejected the API request: ' . $status);
            }
        }

        if (isset($data['code']) && is_numeric($data['code'])) {
            $code = (int) $data['code'];
            if (in_array($code, [401, 403], true)) {
                return cgoExtractErrorMessage($data, 'CHEATGAME authentication failed');
            }
        }

        if (isset($data['error']) && $data['error'] !== null && $data['error'] !== '' && $data['error'] !== false) {
            return cgoExtractErrorMessage($data, 'CHEATGAME returned an API error');
        }

        if (isset($data['data']) && is_array($data['data']) && !cgoArrayIsListCompat($data['data'])) {
            return cgoDetectProviderFailure($data['data']);
        }
        return null;
    }
}

if (!function_exists('cgoDiagnosticExcerpt')) {
    function cgoDiagnosticExcerpt(array $result, int $maxLength = 700): string
    {
        $raw = isset($result['raw']) && is_string($result['raw']) ? $result['raw'] : '';
        if ($raw === '') {
            return '';
        }
        $config = cgoConfig();
        foreach ([(string) ($config['api_key'] ?? ''), (string) ($config['webhook_secret'] ?? '')] as $secret) {
            if ($secret !== '') {
                $raw = str_replace($secret, '[REDACTED]', $raw);
            }
        }
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
        $raw = trim($raw);
        if (function_exists('mb_substr')) {
            return mb_substr($raw, 0, max(100, min(2000, $maxLength)), 'UTF-8');
        }
        return substr($raw, 0, max(100, min(2000, $maxLength)));
    }
}

if (!function_exists('cgoDetectPublicOutboundIp')) {
    function cgoDetectPublicOutboundIp(): array
    {
        $result = ['ok' => false, 'ip' => '', 'error' => ''];
        if (!function_exists('curl_init')) {
            $result['error'] = 'PHP cURL extension is not available';
            return $result;
        }
        $ch = curl_init('https://api4.ipify.org?format=json');
        if ($ch === false) {
            $result['error'] = 'Unable to initialize public-IP check';
            return $result;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Sakazuki-CHEATGAME-Diagnostic/1.1'],
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
        ]);
        $raw = curl_exec($ch);
        $curlNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $curlNo !== 0 || $httpCode < 200 || $httpCode >= 300 || strlen($raw) > 512) {
            $result['error'] = $curlNo !== 0 ? ('Public-IP check network error ' . $curlNo . ': ' . $curlError) : ('Public-IP check HTTP ' . $httpCode);
            return $result;
        }
        $decoded = json_decode($raw, true);
        $ip = is_array($decoded) && isset($decoded['ip']) && is_scalar($decoded['ip']) ? trim((string) $decoded['ip']) : '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $result['error'] = 'Public-IP service returned an invalid IP address';
            return $result;
        }
        return ['ok' => true, 'ip' => $ip, 'error' => ''];
    }
}

if (!function_exists('cgoExtractObservedClientIp')) {
    function cgoExtractObservedClientIp(array $data): string
    {
        $keys = ['client_ip', 'request_ip', 'source_ip', 'server_ip', 'ip'];
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                    return $value;
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && !cgoArrayIsListCompat($value)) {
                $found = cgoExtractObservedClientIp($value);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }
}

if (!function_exists('cgoArrayIsListCompat')) {
    function cgoArrayIsListCompat(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }
        return array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('cgoExtractProductList')) {
    function cgoExtractProductList(array $data): array
    {
        $candidates = [];
        if (isset($data['products']) && is_array($data['products'])) {
            $candidates[] = $data['products'];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            if (isset($data['data']['products']) && is_array($data['data']['products'])) {
                $candidates[] = $data['data']['products'];
            }
            if (cgoArrayIsListCompat($data['data'])) {
                $candidates[] = $data['data'];
            }
        }
        if (cgoArrayIsListCompat($data)) {
            $candidates[] = $data;
        }

        foreach ($candidates as $candidate) {
            $valid = [];
            foreach ($candidate as $item) {
                if (is_array($item) && (array_key_exists('price_usd', $item) || array_key_exists('price', $item))) {
                    $valid[] = $item;
                }
            }
            if ($valid !== []) {
                return $valid;
            }
        }
        return [];
    }
}

if (!function_exists('cgoFirstScalar')) {
    function cgoFirstScalar(array $data, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return $default;
    }
}

if (!function_exists('cgoFirstNumber')) {
    function cgoFirstNumber(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                $value = (float) $data[$key];
                if (is_finite($value)) {
                    return $value;
                }
            }
        }
        return null;
    }
}

if (!function_exists('cgoNormalizeProduct')) {
    function cgoNormalizeProduct(array $item): ?array
    {
        $remoteId = cgoFirstScalar($item, ['product_id', 'id']);
        $name = cgoFirstScalar($item, ['name', 'product_name', 'title']);
        $priceUsd = cgoFirstNumber($item, ['price_usd', 'price']);
        if ($remoteId === '' || !ctype_digit($remoteId) || (int) $remoteId < 1 || $name === '' || $priceUsd === null || $priceUsd < 0 || $priceUsd > 10000000) {
            return null;
        }

        $priceIdr = cgoFirstNumber($item, ['price_idr']);
        $exchangeRate = cgoFirstNumber($item, ['exchange_rate']);
        if (isset($item['exchange_rate']) && is_array($item['exchange_rate'])) {
            $exchangeRate = cgoFirstNumber($item['exchange_rate'], ['rate']);
        }
        $stock = cgoFirstNumber($item, ['stock', 'available_stock']);

        // Keep provider values exactly as supplied. CHEATGAME currently returns
        // platform values such as "android" and "account"; restricting this to
        // android/ios/both caused valid metadata to disappear.
        $platform = strtolower(cgoFirstScalar($item, ['platform']));
        $platform = preg_replace('/[\x00-\x1F\x7F]/u', '', $platform) ?? '';
        $currency = strtoupper(cgoFirstScalar($item, ['currency']));
        $currency = preg_replace('/[^A-Z0-9_-]/', '', $currency) ?? '';

        return [
            'remote_product_id' => substr($remoteId, 0, 120),
            'name' => substr($name, 0, 255),
            'brand' => substr(cgoFirstScalar($item, ['brand']), 0, 190),
            'description' => cgoFirstScalar($item, ['description']),
            'image_path' => substr(cgoFirstScalar($item, ['image_url', 'image', 'image_filename']), 0, 500),
            'category' => substr(cgoFirstScalar($item, ['category']), 0, 190),
            'duration' => substr(cgoFirstScalar($item, ['duration']), 0, 120),
            'platform' => substr($platform, 0, 60),
            'remote_status' => substr(strtolower(cgoFirstScalar($item, ['status'])), 0, 60),
            'remote_stock' => $stock === null ? null : max(0, (int) floor($stock)),
            'currency' => substr($currency, 0, 12),
            'price_usd' => round($priceUsd, 6),
            'price_idr' => $priceIdr === null ? null : round($priceIdr, 2),
            'exchange_rate_idr' => $exchangeRate === null ? null : round($exchangeRate, 6),
            'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }
}


if (!function_exists('cgoInventoryCacheTtlSeconds')) {
    function cgoInventoryCacheTtlSeconds(): int
    {
        return max(30, min(300, (int) (cgoConfig()['inventory_cache_ttl_seconds'] ?? 30)));
    }
}

if (!function_exists('cgoInventoryPurchaseMaxAgeSeconds')) {
    function cgoInventoryPurchaseMaxAgeSeconds(): int
    {
        return max(5, min(
            cgoInventoryCacheTtlSeconds(),
            (int) (cgoConfig()['inventory_purchase_max_age_seconds'] ?? 5)
        ));
    }
}

if (!function_exists('cgoInventoryBusyFallbackSeconds')) {
    function cgoInventoryBusyFallbackSeconds(): int
    {
        return max(15, min(300, (int) (cgoConfig()['inventory_busy_fallback_seconds'] ?? 90)));
    }
}

if (!function_exists('cgoInventoryPartialFallbackSeconds')) {
    function cgoInventoryPartialFallbackSeconds(): int
    {
        return max(60, min(3600, (int) (cgoConfig()['inventory_partial_fallback_seconds'] ?? 900)));
    }
}

if (!function_exists('cgoInventoryTransportFallbackSeconds')) {
    function cgoInventoryTransportFallbackSeconds(): int
    {
        return max(5, min(120, (int) (cgoConfig()['inventory_transport_fallback_seconds'] ?? 20)));
    }
}

if (!function_exists('cgoInventoryRetryDelayMicroseconds')) {
    function cgoInventoryRetryDelayMicroseconds(): int
    {
        return max(100000, min(1500000, (int) (cgoConfig()['inventory_retry_delay_ms'] ?? 350) * 1000));
    }
}

if (!function_exists('cgoInventoryRequestTimeoutSeconds')) {
    function cgoInventoryRequestTimeoutSeconds(): int
    {
        return max(3, min(15, (int) (cgoConfig()['inventory_request_timeout_seconds'] ?? 7)));
    }
}

if (!function_exists('cgoInventoryState')) {
    function cgoInventoryState(bool $reload = false): array
    {
        global $conn;
        static $cached = null;
        if (!$reload && is_array($cached)) {
            return $cached;
        }

        $cached = [
            'last_attempt_at' => null,
            'last_success_at' => null,
            'last_error' => '',
            'age_seconds' => null,
            'fresh' => false,
            'locked' => false,
        ];
        if (!cgoEnsureTables()) {
            $cached['last_error'] = 'Inventory state table is unavailable';
            return $cached;
        }

        $result = $conn->query("SELECT last_attempt_at, last_success_at, last_error,
                                      CASE WHEN last_success_at IS NULL THEN NULL
                                           ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, last_success_at, NOW())) END AS age_seconds,
                                      CASE WHEN lock_token IS NOT NULL AND lock_expires_at >= NOW() THEN 1 ELSE 0 END AS locked
                               FROM cgo_inventory_state WHERE id = 1 LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            return $cached;
        }

        // Compute age inside MySQL so PHP and database timezone settings cannot
        // make a fresh snapshot look hours old (or, worse, permanently fresh).
        $age = isset($row['age_seconds']) && is_numeric($row['age_seconds'])
            ? max(0, (int) $row['age_seconds'])
            : null;
        $cached = [
            'last_attempt_at' => ($row['last_attempt_at'] ?? null),
            'last_success_at' => ($row['last_success_at'] ?? null),
            'last_error' => trim((string) ($row['last_error'] ?? '')),
            'age_seconds' => $age,
            'fresh' => $age !== null && $age <= cgoInventoryCacheTtlSeconds(),
            'locked' => (int) ($row['locked'] ?? 0) === 1,
        ];
        return $cached;
    }
}

if (!function_exists('cgoInventoryCacheIsFresh')) {
    function cgoInventoryCacheIsFresh(?int $maxAgeSeconds = null): bool
    {
        $state = cgoInventoryState();
        $age = $state['age_seconds'] ?? null;
        $maxAgeSeconds = $maxAgeSeconds === null
            ? cgoInventoryCacheTtlSeconds()
            : max(1, min(600, $maxAgeSeconds));
        return is_int($age) && $age <= $maxAgeSeconds;
    }
}

if (!function_exists('cgoProductInventoryCacheIsFresh')) {
    /**
     * Verify freshness for the exact supplier product being purchased. A global
     * refresh can succeed with a partial response, so checkout must not assume an
     * omitted row was refreshed merely because the shared timestamp is recent.
     */
    function cgoProductInventoryCacheIsFresh(int $productId, ?int $maxAgeSeconds = null): bool
    {
        global $conn;
        if ($productId < 1 || !cgoEnsureTables()) return false;
        $maxAgeSeconds = $maxAgeSeconds === null
            ? cgoInventoryPurchaseMaxAgeSeconds()
            : max(1, min(600, $maxAgeSeconds));
        $stmt = $conn->prepare("SELECT CASE
                WHEN inventory_checked_at IS NOT NULL
                 AND inventory_checked_at <= NOW()
                 AND TIMESTAMPDIFF(SECOND, inventory_checked_at, NOW()) <= ?
                THEN 1 ELSE 0 END AS fresh
            FROM cgo_products WHERE id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $maxAgeSeconds, $productId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return (int) ($row['fresh'] ?? 0) === 1;
    }
}

if (!function_exists('cgoGetProductInventoryStatus')) {
    function cgoGetProductInventoryStatus(int $productId): array
    {
        global $conn;
        $fallback = [
            'exists' => false,
            'stock' => null,
            'status' => '',
            'checked_at' => null,
            'age_seconds' => null,
            'remote_product_id' => '',
        ];
        if ($productId < 1 || !cgoEnsureTables()) return $fallback;
        $stmt = $conn->prepare("SELECT remote_product_id, remote_stock, remote_status, inventory_checked_at,
                                      CASE WHEN inventory_checked_at IS NULL THEN NULL
                                           WHEN inventory_checked_at > NOW() THEN NULL
                                           ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, inventory_checked_at, NOW())) END AS age_seconds
                               FROM cgo_products WHERE id = ? LIMIT 1");
        if (!$stmt) return $fallback;
        $stmt->bind_param('i', $productId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $fallback;
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return $fallback;
        return [
            'exists' => true,
            'stock' => $row['remote_stock'] === null ? null : max(0, (int) $row['remote_stock']),
            'status' => strtolower(trim((string) ($row['remote_status'] ?? ''))),
            'checked_at' => $row['inventory_checked_at'] ?? null,
            'age_seconds' => isset($row['age_seconds']) && is_numeric($row['age_seconds'])
                ? max(0, (int) $row['age_seconds'])
                : null,
            'remote_product_id' => trim((string) ($row['remote_product_id'] ?? '')),
        ];
    }
}

if (!function_exists('cgoInventoryRequirementIsFresh')) {
    function cgoInventoryRequirementIsFresh(int $requiredProductId, int $maxAgeSeconds): bool
    {
        $maxAgeSeconds = max(1, min(600, $maxAgeSeconds));
        return $requiredProductId > 0
            ? cgoProductInventoryCacheIsFresh($requiredProductId, $maxAgeSeconds)
            : cgoInventoryCacheIsFresh($maxAgeSeconds);
    }
}

if (!function_exists('cgoStorefrontVariantInventoryRefreshNeeded')) {
    function cgoStorefrontVariantInventoryRefreshNeeded(array $variantIds, ?int $maxAgeSeconds = null): bool
    {
        global $conn;
        if (!cgoLiveOrderVisibleToCurrentSession() || !cgoEnsureTables()) return false;
        $clean = [];
        foreach ($variantIds as $variantId) {
            $variantId = (int) $variantId;
            if ($variantId > 0) $clean[$variantId] = $variantId;
            if (count($clean) >= 300) break;
        }
        $maxAgeSeconds = $maxAgeSeconds === null
            ? cgoInventoryCacheTtlSeconds()
            : max(1, min(600, $maxAgeSeconds));
        if ($clean === []) return !cgoInventoryCacheIsFresh($maxAgeSeconds);
        $ids = implode(',', array_values($clean));
        $sql = "SELECT 1
                FROM cgo_catalog_links l
                JOIN cgo_products cp ON cp.id = l.cgo_product_id
                WHERE l.api_fallback_enabled = 1
                  AND cp.enabled = 1
                  AND l.local_variant_id IN ($ids)
                  AND (cp.inventory_checked_at IS NULL
                       OR cp.inventory_checked_at > NOW()
                       OR TIMESTAMPDIFF(SECOND, cp.inventory_checked_at, NOW()) > " . (int) $maxAgeSeconds . ")
                LIMIT 1";
        $result = $conn->query($sql);
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('cgoGetStaleStorefrontInventoryProductIds')) {
    function cgoGetStaleStorefrontInventoryProductIds(
        array $variantIds,
        array $directProductIds = [],
        ?int $maxAgeSeconds = null,
        int $limit = 5
    ): array {
        global $conn;
        if (!cgoLiveOrderVisibleToCurrentSession() || !cgoEnsureTables()) return [];
        $cleanVariants = [];
        foreach ($variantIds as $id) {
            $id = (int) $id;
            if ($id > 0) $cleanVariants[$id] = $id;
            if (count($cleanVariants) >= 300) break;
        }
        $cleanProducts = [];
        foreach ($directProductIds as $id) {
            $id = (int) $id;
            if ($id > 0) $cleanProducts[$id] = $id;
            if (count($cleanProducts) >= 300) break;
        }
        if ($cleanVariants === [] && $cleanProducts === []) return [];
        $maxAgeSeconds = $maxAgeSeconds === null
            ? cgoInventoryCacheTtlSeconds()
            : max(1, min(600, $maxAgeSeconds));
        $limit = max(1, min(20, $limit));
        $conditions = [];
        if ($cleanProducts !== []) {
            $conditions[] = 'cp.id IN (' . implode(',', array_values($cleanProducts)) . ')';
        }
        $join = '';
        if ($cleanVariants !== []) {
            $join = 'LEFT JOIN cgo_catalog_links l ON l.cgo_product_id = cp.id AND l.api_fallback_enabled = 1';
            $conditions[] = 'l.local_variant_id IN (' . implode(',', array_values($cleanVariants)) . ')';
        }
        $sql = "SELECT DISTINCT cp.id
                FROM cgo_products cp
                {$join}
                WHERE cp.enabled = 1
                  AND cp.supplier_removed_at IS NULL
                  AND (" . implode(' OR ', $conditions) . ")
                  AND (cp.inventory_checked_at IS NULL
                       OR cp.inventory_checked_at > NOW()
                       OR TIMESTAMPDIFF(SECOND, cp.inventory_checked_at, NOW()) > " . (int) $maxAgeSeconds . ")
                ORDER BY cp.inventory_checked_at IS NULL DESC, cp.inventory_checked_at ASC, cp.id ASC
                LIMIT " . (int) $limit;
        $result = $conn->query($sql);
        $ids = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) $ids[] = $id;
            }
            $result->free();
        }
        return $ids;
    }
}

if (!function_exists('cgoAcquireInventoryRefreshLock')) {
    function cgoAcquireInventoryRefreshLock(int $waitSeconds = 0, ?int $freshMaxAgeSeconds = null, int $requiredProductId = 0): string
    {
        global $conn;
        $waitSeconds = max(0, min(15, $waitSeconds));
        $freshMaxAgeSeconds = $freshMaxAgeSeconds === null
            ? cgoInventoryCacheTtlSeconds()
            : max(1, min(600, $freshMaxAgeSeconds));
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            error_log('CGO inventory lock token generation failed: ' . $e->getMessage());
            return '';
        }

        $deadline = microtime(true) + $waitSeconds;
        do {
            $stmt = $conn->prepare("UPDATE cgo_inventory_state
                SET lock_token = ?, lock_expires_at = DATE_ADD(NOW(), INTERVAL 90 SECOND), last_attempt_at = NOW()
                WHERE id = 1
                  AND (lock_token IS NULL OR lock_expires_at IS NULL OR lock_expires_at < NOW())");
            if (!$stmt) return '';
            $stmt->bind_param('s', $token);
            $ok = $stmt->execute();
            $acquired = $ok && $stmt->affected_rows === 1;
            $stmt->close();
            if ($acquired) {
                cgoInventoryState(true);
                return $token;
            }
            if ($waitSeconds <= 0 || microtime(true) >= $deadline) break;
            usleep(250000);
            cgoInventoryState(true);
            if (cgoInventoryRequirementIsFresh($requiredProductId, $freshMaxAgeSeconds)) break;
        } while (true);

        return '';
    }
}

if (!function_exists('cgoReleaseInventoryRefreshLock')) {
    function cgoReleaseInventoryRefreshLock(string $token): void
    {
        global $conn;
        if ($token === '') return;
        $stmt = $conn->prepare("UPDATE cgo_inventory_state
            SET lock_token = NULL, lock_expires_at = NULL
            WHERE id = 1 AND lock_token = ?");
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
        }
        cgoInventoryState(true);
    }
}

if (!function_exists('cgoSetInventoryRefreshError')) {
    function cgoSetInventoryRefreshError(string $token, string $message): void
    {
        global $conn;
        $message = trim($message);
        if (strlen($message) > 1000) $message = substr($message, 0, 1000);
        $stmt = $conn->prepare("UPDATE cgo_inventory_state
            SET last_error = NULLIF(?, ''), lock_token = NULL, lock_expires_at = NULL
            WHERE id = 1 AND lock_token = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $message, $token);
            $stmt->execute();
            $stmt->close();
        }
        cgoInventoryState(true);
    }
}

if (!function_exists('cgoInvalidateInventoryCache')) {
    function cgoInvalidateInventoryCache(string $reason = ''): void
    {
        global $conn;
        if (!cgoEnsureTables()) return;
        $reason = trim($reason);
        if (strlen($reason) > 1000) $reason = substr($reason, 0, 1000);
        $stmt = $conn->prepare("UPDATE cgo_inventory_state
            SET last_success_at = NULL, last_error = NULLIF(?, '')
            WHERE id = 1");
        if ($stmt) {
            $stmt->bind_param('s', $reason);
            $stmt->execute();
            $stmt->close();
        }
        cgoInventoryState(true);
    }
}

if (!function_exists('cgoFetchInventoryPayload')) {
    function cgoFetchInventoryPayload(): array
    {
        $api = cgoApiRequest('products', 'GET', [], cgoInventoryRequestTimeoutSeconds());
        if (empty($api['ok']) || !is_array($api['data'])) {
            $failureClass = !empty($api['transport_error'])
                ? 'transport'
                : ((int) ($api['http_code'] ?? 0) >= 400 ? 'provider_http' : 'provider_response');
            return [
                'success' => false,
                'failure_class' => $failureClass,
                'http_code' => (int) ($api['http_code'] ?? 0),
                'message' => trim((string) ($api['error'] ?? '')) ?: 'Unable to load supplier inventory',
                'inventory' => [],
                'remote_ids' => [],
                'invalid' => 0,
            ];
        }

        $items = cgoExtractProductList($api['data']);
        if ($items === []) {
            return [
                'success' => false,
                'failure_class' => 'invalid_payload',
                'http_code' => (int) ($api['http_code'] ?? 0),
                'message' => 'Supplier response did not contain a valid product list',
                'inventory' => [],
                'remote_ids' => [],
                'invalid' => 0,
            ];
        }

        $inventory = [];
        $remoteIds = [];
        $invalid = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                $invalid++;
                continue;
            }
            $remoteId = cgoFirstScalar($item, ['product_id', 'id']);
            if ($remoteId === '' || !ctype_digit($remoteId) || (int) $remoteId < 1) {
                $invalid++;
                continue;
            }
            $remoteId = substr($remoteId, 0, 120);
            $remoteIds[$remoteId] = true;
            $stockValue = cgoFirstNumber($item, ['stock', 'available_stock']);
            // A malformed row must never overwrite a previously confirmed stock.
            if ($stockValue === null) {
                $invalid++;
                continue;
            }
            $stock = max(0, (int) floor($stockValue));
            $status = substr(strtolower(cgoFirstScalar($item, ['status'])), 0, 60);
            if ($status === '' && $stock < 1) $status = 'out_of_stock';
            $rawJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($rawJson)) $rawJson = '{}';
            $inventory[$remoteId] = [
                'stock' => $stock,
                'status' => $status,
                'raw_json' => $rawJson,
            ];
        }

        if ($inventory === []) {
            return [
                'success' => false,
                'failure_class' => 'invalid_payload',
                'http_code' => (int) ($api['http_code'] ?? 0),
                'message' => 'No usable supplier inventory records were returned',
                'inventory' => [],
                'remote_ids' => array_keys($remoteIds),
                'invalid' => $invalid,
            ];
        }

        return [
            'success' => true,
            'failure_class' => '',
            'http_code' => (int) ($api['http_code'] ?? 0),
            'message' => '',
            'inventory' => $inventory,
            'remote_ids' => array_keys($remoteIds),
            'invalid' => $invalid,
        ];
    }
}

if (!function_exists('cgoRefreshRemoteInventory')) {
    /**
     * Refresh supplier stock/status without changing prices or catalogue links.
     * Suspiciously partial responses are retried and never mark the whole cache
     * fresh. Checkout can require confirmation for one exact product.
     */
    function cgoRefreshRemoteInventory(
        bool $force = false,
        int $waitSeconds = 0,
        ?int $maxAgeSeconds = null,
        int $requiredProductId = 0
    ): array {
        global $conn;
        if (!cgoEnsureTables()) {
            return ['success' => false, 'failure_class' => 'storage', 'message' => 'Unable to prepare inventory tables'];
        }

        $requiredProductId = max(0, $requiredProductId);
        $maxAgeSeconds = $maxAgeSeconds === null
            ? cgoInventoryCacheTtlSeconds()
            : max(1, min(600, $maxAgeSeconds));
        if (!$force && cgoInventoryRequirementIsFresh($requiredProductId, $maxAgeSeconds)) {
            return [
                'success' => true,
                'cached' => true,
                'updated' => 0,
                'missing' => 0,
                'invalid' => 0,
                'partial' => false,
                'required_confirmed' => true,
                'catalog_refresh_needed' => false,
                'state' => cgoInventoryState(),
                'message' => 'Inventory cache is current',
            ];
        }

        $token = cgoAcquireInventoryRefreshLock($waitSeconds, $maxAgeSeconds, $requiredProductId);
        if ($token === '') {
            cgoInventoryState(true);
            if (cgoInventoryRequirementIsFresh($requiredProductId, $maxAgeSeconds)) {
                return [
                    'success' => true,
                    'cached' => true,
                    'updated' => 0,
                    'missing' => 0,
                    'invalid' => 0,
                    'partial' => false,
                    'required_confirmed' => true,
                    'catalog_refresh_needed' => false,
                    'state' => cgoInventoryState(),
                    'message' => 'Inventory was refreshed by another request',
                ];
            }
            return [
                'success' => false,
                'busy' => true,
                'failure_class' => 'busy',
                'retry_after' => 2,
                'required_confirmed' => false,
                'state' => cgoInventoryState(),
                'message' => 'Inventory refresh is already running',
            ];
        }

        try {
            if (!$force && cgoInventoryRequirementIsFresh($requiredProductId, $maxAgeSeconds)) {
                cgoReleaseInventoryRefreshLock($token);
                $token = '';
                return [
                    'success' => true,
                    'cached' => true,
                    'updated' => 0,
                    'missing' => 0,
                    'invalid' => 0,
                    'partial' => false,
                    'required_confirmed' => true,
                    'catalog_refresh_needed' => false,
                    'state' => cgoInventoryState(true),
                    'message' => 'Inventory cache is current',
                ];
            }

            $currentByRemote = [];
            $enabledRemoteIds = [];
            $requiredRemoteId = '';
            $current = $conn->query('SELECT id, remote_product_id, enabled FROM cgo_products WHERE supplier_removed_at IS NULL');
            if ($current) {
                while ($row = $current->fetch_assoc()) {
                    $remoteId = trim((string) ($row['remote_product_id'] ?? ''));
                    if ($remoteId === '') continue;
                    $localId = (int) ($row['id'] ?? 0);
                    $currentByRemote[$remoteId] = $localId;
                    if ((int) ($row['enabled'] ?? 0) === 1) $enabledRemoteIds[$remoteId] = true;
                    if ($requiredProductId > 0 && $localId === $requiredProductId) $requiredRemoteId = $remoteId;
                }
                $current->free();
            }
            if ($requiredProductId > 0 && $requiredRemoteId === '') {
                cgoSetInventoryRefreshError($token, 'Requested supplier product was not found locally');
                $token = '';
                return [
                    'success' => false,
                    'failure_class' => 'product_missing',
                    'required_confirmed' => false,
                    'message' => 'Requested supplier product was not found locally',
                    'state' => cgoInventoryState(true),
                ];
            }

            $attempts = [];
            $mergedInventory = [];
            $seenRemoteIds = [];
            $invalid = 0;
            $first = cgoFetchInventoryPayload();
            $attempts[] = $first;
            if (!empty($first['success'])) {
                $mergedInventory = $first['inventory'];
                foreach ($first['remote_ids'] as $remoteId) $seenRemoteIds[(string) $remoteId] = true;
                $invalid += (int) ($first['invalid'] ?? 0);
            }

            $enabledCount = count($enabledRemoteIds);
            $matchedFirst = 0;
            foreach ($enabledRemoteIds as $remoteId => $_) {
                if (isset($mergedInventory[$remoteId])) $matchedFirst++;
            }
            $requiredFoundFirst = $requiredRemoteId === '' || isset($mergedInventory[$requiredRemoteId]);
            $firstLooksPartial = $enabledCount >= 5 && $matchedFirst < (int) ceil($enabledCount * 0.85);
            $retryNeeded = empty($first['success']) || !$requiredFoundFirst || $firstLooksPartial;
            // Checkout should not make a customer wait through two full network
            // timeouts. A transport failure can use the bounded recent snapshot;
            // partial/invalid successful responses still receive a second attempt.
            if ($requiredProductId > 0
                && empty($first['success'])
                && in_array((string) ($first['failure_class'] ?? ''), ['transport', 'provider_http'], true)) {
                $retryNeeded = false;
            }

            if ($retryNeeded) {
                usleep(cgoInventoryRetryDelayMicroseconds());
                $second = cgoFetchInventoryPayload();
                $attempts[] = $second;
                if (!empty($second['success'])) {
                    foreach ($second['inventory'] as $remoteId => $row) $mergedInventory[$remoteId] = $row;
                    foreach ($second['remote_ids'] as $remoteId) $seenRemoteIds[(string) $remoteId] = true;
                    $invalid += (int) ($second['invalid'] ?? 0);
                }
            }

            if ($mergedInventory === []) {
                $last = end($attempts);
                $failureClass = (string) ($last['failure_class'] ?? $first['failure_class'] ?? 'provider_response');
                $message = trim((string) ($last['message'] ?? $first['message'] ?? 'Unable to load supplier inventory'));
                cgoSetInventoryRefreshError($token, $message !== '' ? $message : 'Unable to load supplier inventory');
                $token = '';
                return [
                    'success' => false,
                    'failure_class' => $failureClass,
                    'http_code' => (int) ($last['http_code'] ?? 0),
                    'attempts' => count($attempts),
                    'required_confirmed' => false,
                    'message' => $message !== '' ? $message : 'Unable to load supplier inventory',
                    'state' => cgoInventoryState(true),
                ];
            }

            $matchedEnabled = 0;
            foreach ($enabledRemoteIds as $remoteId => $_) {
                if (isset($mergedInventory[$remoteId])) $matchedEnabled++;
            }
            $coverage = $enabledCount > 0 ? ($matchedEnabled / $enabledCount) : 1.0;
            $partial = $enabledCount >= 5 && $coverage < 0.75;
            $requiredConfirmed = $requiredRemoteId === '' || isset($mergedInventory[$requiredRemoteId]);
            $unknownRemoteIds = [];
            foreach ($seenRemoteIds as $remoteId => $_) {
                if (!isset($currentByRemote[$remoteId])) $unknownRemoteIds[$remoteId] = true;
            }

            $updated = 0;
            $conn->begin_transaction();
            try {
                $update = $conn->prepare("UPDATE cgo_products
                    SET remote_stock = ?, remote_status = ?, raw_json = ?, inventory_checked_at = NOW()
                    WHERE remote_product_id = ? AND supplier_removed_at IS NULL");
                if (!$update) throw new RuntimeException('inventory update prepare failed');
                foreach ($mergedInventory as $remoteId => $row) {
                    if (!isset($currentByRemote[$remoteId])) continue;
                    $stock = (int) $row['stock'];
                    $status = (string) $row['status'];
                    $rawJson = (string) $row['raw_json'];
                    $update->bind_param('isss', $stock, $status, $rawJson, $remoteId);
                    if (!$update->execute()) throw new RuntimeException('inventory update failed: ' . $update->error);
                    $updated++;
                }
                $update->close();

                if ($partial) {
                    $partialMessage = 'Partial supplier inventory response: confirmed ' . $matchedEnabled . ' of ' . $enabledCount . ' enabled products';
                    $finish = $conn->prepare("UPDATE cgo_inventory_state
                        SET last_error = ?, lock_token = NULL, lock_expires_at = NULL
                        WHERE id = 1 AND lock_token = ?");
                    if (!$finish) throw new RuntimeException('partial inventory state update prepare failed');
                    $finish->bind_param('ss', $partialMessage, $token);
                } else {
                    $finish = $conn->prepare("UPDATE cgo_inventory_state
                        SET last_success_at = NOW(), last_error = NULL,
                            lock_token = NULL, lock_expires_at = NULL
                        WHERE id = 1 AND lock_token = ?");
                    if (!$finish) throw new RuntimeException('inventory state update prepare failed');
                    $finish->bind_param('s', $token);
                }
                if (!$finish->execute() || $finish->affected_rows !== 1) {
                    $finish->close();
                    throw new RuntimeException('inventory state update failed');
                }
                $finish->close();
                $conn->commit();
                $token = '';
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                throw $e;
            }

            $state = cgoInventoryState(true);
            return [
                'success' => true,
                'cached' => false,
                'updated' => $updated,
                'missing' => max(0, $enabledCount - $matchedEnabled),
                'invalid' => $invalid,
                'attempts' => count($attempts),
                'partial' => $partial,
                'coverage_percent' => round($coverage * 100, 1),
                'required_confirmed' => $requiredConfirmed,
                'catalog_refresh_needed' => $unknownRemoteIds !== [],
                'unknown_products' => count($unknownRemoteIds),
                'next_interval_seconds' => ($partial || !$requiredConfirmed) ? 30 : cgoInventoryCacheTtlSeconds(),
                'state' => $state,
                'message' => $partial
                    ? 'Supplier inventory was partially refreshed and will retry automatically'
                    : 'Supplier inventory refreshed',
            ];
        } catch (Throwable $e) {
            error_log('CGO inventory refresh failed: ' . $e->getMessage());
            if ($token !== '') {
                cgoSetInventoryRefreshError($token, 'Inventory refresh failed');
                $token = '';
            }
            return [
                'success' => false,
                'failure_class' => 'storage',
                'required_confirmed' => false,
                'message' => 'Inventory refresh failed',
                'state' => cgoInventoryState(true),
            ];
        } finally {
            if ($token !== '') cgoReleaseInventoryRefreshLock($token);
        }
    }
}

if (!function_exists('cgoVerifyProductInventoryForPurchase')) {
    /**
     * Verify one exact product before checkout. A bounded stale-positive fallback
     * is allowed only when the last confirmed stock can satisfy this order. The
     * provider order endpoint remains authoritative and rejection/refund handling
     * is unchanged.
     */
    function cgoVerifyProductInventoryForPurchase(int $productId, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $maxAge = cgoInventoryPurchaseMaxAgeSeconds();
        $before = cgoGetProductInventoryStatus($productId);
        if (!empty($before['exists'])
            && is_int($before['age_seconds'])
            && $before['age_seconds'] <= $maxAge) {
            return [
                'success' => true,
                'degraded' => false,
                'mode' => 'fresh_cache',
                'inventory' => $before,
            ];
        }

        $refresh = cgoRefreshRemoteInventory(false, 4, $maxAge, $productId);
        $after = cgoGetProductInventoryStatus($productId);
        if (!empty($after['exists'])
            && is_int($after['age_seconds'])
            && $after['age_seconds'] <= $maxAge) {
            return [
                'success' => true,
                'degraded' => false,
                'mode' => 'live_refresh',
                'inventory' => $after,
                'refresh' => $refresh,
            ];
        }

        $failureClass = strtolower(trim((string) ($refresh['failure_class'] ?? '')));
        if (!empty($refresh['busy'])) $failureClass = 'busy';
        if (!empty($refresh['success']) && empty($refresh['required_confirmed'])) {
            $failureClass = !empty($refresh['partial']) ? 'partial' : 'target_missing';
        }
        $fallbackAge = cgoInventoryTransportFallbackSeconds();
        if ($failureClass === 'busy') {
            $fallbackAge = cgoInventoryBusyFallbackSeconds();
        } elseif (in_array($failureClass, ['partial', 'target_missing', 'invalid_payload'], true)) {
            $fallbackAge = cgoInventoryPartialFallbackSeconds();
        }

        $age = $after['age_seconds'] ?? null;
        $stock = $after['stock'] ?? null;
        $positiveEnough = is_int($stock) && $stock >= $quantity;
        $recentEnough = is_int($age) && $age <= $fallbackAge;
        if (!empty($after['exists']) && $positiveEnough && $recentEnough) {
            error_log('CGO checkout using bounded inventory fallback; product=' . $productId
                . '; age=' . $age . '; stock=' . $stock . '; class=' . ($failureClass !== '' ? $failureClass : 'unknown'));
            return [
                'success' => true,
                'degraded' => true,
                'mode' => 'bounded_stale_positive',
                'fallback_age_limit' => $fallbackAge,
                'failure_class' => $failureClass,
                'inventory' => $after,
                'refresh' => $refresh,
            ];
        }

        return [
            'success' => false,
            'code' => 'supplier_inventory_unavailable',
            'failure_class' => $failureClass !== '' ? $failureClass : 'unconfirmed',
            'inventory' => $after,
            'refresh' => $refresh,
            'message' => 'Latest supplier stock could not be verified. No balance was charged.',
        ];
    }
}

if (!function_exists('cgoGetEnabledInventorySnapshot')) {
    /**
     * Return the small, non-sensitive stock snapshot used by storefront AJAX.
     * Product metadata, supplier responses, credentials, and prices stay server-side.
     */
    function cgoGetEnabledInventorySnapshot(): array
    {
        global $conn;
        if (!cgoEnsureTables()) return [];

        $snapshot = [];
        $result = $conn->query("SELECT id, remote_stock, remote_status
                               FROM cgo_products
                               WHERE enabled = 1");
        if (!$result) return [];

        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) continue;
            $stock = $row['remote_stock'] === null ? 0 : max(0, (int) $row['remote_stock']);
            $status = strtolower(trim((string) ($row['remote_status'] ?? '')));
            // Store visibility follows explicit stock only. Provider status text is
            // retained for diagnostics but no longer overrides a positive quantity.
            $available = $stock > 0;
            $snapshot[(string) $id] = [
                'stock' => $available ? $stock : 0,
                'available' => $available,
            ];
        }
        return $snapshot;
    }
}


if (!function_exists('cgoGetStorefrontInventorySnapshot')) {
    /**
     * Return stock only for the local variants rendered by the requesting
     * storefront. Supplier identifiers, costs, prices, raw responses, and
     * catalogue metadata are deliberately excluded from the response.
     */
    function cgoGetStorefrontInventorySnapshot(array $variantIds, string $role, int $userId = 0): array
    {
        global $conn;

        $cleanIds = [];
        foreach ($variantIds as $variantId) {
            if (!is_scalar($variantId)) continue;
            $variantId = (int) $variantId;
            if ($variantId > 0) $cleanIds[$variantId] = $variantId;
            if (count($cleanIds) >= 300) break;
        }
        if ($cleanIds === []) return [];

        $role = $role === 'reseller' ? 'reseller' : 'user';
        $supplierSnapshot = supplierBridgeInventorySnapshot(array_values($cleanIds), $role, $userId);
        if (!cgoLiveOrderVisibleToCurrentSession() || !cgoEnsureTables()) return $supplierSnapshot;
        $accountId = max(0, $userId);
        $specialPriceReady = $accountId > 0 && ensureResellerVariantPricesTable();
        $overrideFields = $specialPriceReady
            ? ", rvp.custom_price AS account_custom_price,
                 rvp.below_cost_confirmed AS account_below_cost_confirmed,
                 rvp.confirmed_cost AS account_confirmed_cost"
            : ", NULL AS account_custom_price,
                 0 AS account_below_cost_confirmed,
                 NULL AS account_confirmed_cost";
        $overrideJoin = $specialPriceReady
            ? "LEFT JOIN reseller_variant_prices rvp
                   ON rvp.variant_id = l.local_variant_id
                  AND rvp.reseller_id = " . (int) $accountId . "
                  AND rvp.status = 'active'"
            : '';
        $idList = implode(',', array_values($cleanIds));
        $sql = "SELECT l.local_product_id, l.local_variant_id,
                       cp.remote_stock, cp.remote_status, cp.cost_base,
                       pv.price_user, pv.price_reseller, pv.duration
                       {$overrideFields}
                FROM cgo_catalog_links l
                JOIN cgo_products cp ON cp.id = l.cgo_product_id
                JOIN products p ON p.id = l.local_product_id AND p.status = 'active'
                JOIN product_variants pv ON pv.id = l.local_variant_id
                                        AND pv.product_id = l.local_product_id
                                        AND pv.status = 'active'
                {$overrideJoin}
                WHERE l.api_fallback_enabled = 1
                  AND cp.enabled = 1
                  AND l.local_variant_id IN ($idList)";
        $result = $conn->query($sql);
        if (!$result) return $supplierSnapshot;

        $rows = [];
        $productIds = [];
        while ($row = $result->fetch_assoc()) {
            $variantId = (int) ($row['local_variant_id'] ?? 0);
            $productId = (int) ($row['local_product_id'] ?? 0);
            if ($variantId < 1 || $productId < 1) continue;
            $rows[$variantId] = $row;
            $productIds[$productId] = $productId;
        }

        $localAvailability = [];
        // getAvailableKeyGroupsForProducts() intentionally caps one query at 60
        // products. Inventory snapshots accept up to 300 variant IDs, so batch
        // the distinct products instead of silently treating product #61+ as
        // having no local stock.
        $localKeyGroups = [];
        foreach (array_chunk(array_values($productIds), 60) as $productIdBatch) {
            foreach (getAvailableKeyGroupsForProducts($productIdBatch) as $batchProductId => $groups) {
                $localKeyGroups[(int) $batchProductId] = $groups;
            }
        }
        foreach ($productIds as $productId) {
            $variantSet = [];
            $durationSet = [];
            foreach ($localKeyGroups[$productId] ?? [] as $key) {
                $localVariantId = (int) ($key['variant_id'] ?? 0);
                if ($localVariantId > 0) $variantSet[$localVariantId] = true;
                $duration = strtolower(trim((string) ($key['duration'] ?? 'Standard')));
                if ($duration === '') $duration = 'standard';
                $durationSet[$duration] = true;
            }
            $localAvailability[$productId] = [
                'variants' => $variantSet,
                'durations' => $durationSet,
            ];
        }

        $snapshot = [];
        foreach ($rows as $variantId => $row) {
            $productId = (int) $row['local_product_id'];
            $duration = strtolower(trim((string) ($row['duration'] ?? 'Standard')));
            if ($duration === '') $duration = 'standard';
            $local = $localAvailability[$productId] ?? ['variants' => [], 'durations' => []];
            $localHasPriority = isset($local['variants'][$variantId]) || isset($local['durations'][$duration]);

            $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
            $overrideDetails = null;
            if ($row['account_custom_price'] !== null) {
                $price = (float) $row['account_custom_price'];
                $overrideDetails = [
                    'custom_price' => $price,
                    'below_cost_confirmed' => (int) ($row['account_below_cost_confirmed'] ?? 0) === 1,
                    'confirmed_cost' => $row['account_confirmed_cost'] ?? null,
                ];
            }
            $price = round($price, 2);
            $cost = round((float) ($row['cost_base'] ?? 0), 2);
            $priceAllowed = resellerVariantPriceAllowsCost($overrideDetails, $price, $cost);

            $stock = max(0, (int) ($row['remote_stock'] ?? 0));
            $available = !$localHasPriority
                && $priceAllowed
                && $stock > 0;

            $snapshot[(string) $variantId] = [
                'stock' => $available ? min(100, $stock) : 0,
                'available' => $available,
            ];
        }
        // Merge remote sources by usable capacity. An unavailable Store Bridge
        // placeholder must never hide a CHEATGAME source that still has stock.
        // Stock is the largest single source, not a sum, because checkout never
        // splits one order across suppliers.
        foreach ($snapshot as $variantId => $remoteRow) {
            if (!isset($supplierSnapshot[$variantId])) {
                $supplierSnapshot[$variantId] = $remoteRow;
                continue;
            }
            $supplierStock = max(0, (int) ($supplierSnapshot[$variantId]['stock'] ?? 0));
            $remoteStock = max(0, (int) ($remoteRow['stock'] ?? 0));
            $supplierSnapshot[$variantId]['stock'] = max($supplierStock, $remoteStock);
            $supplierSnapshot[$variantId]['available'] = !empty($supplierSnapshot[$variantId]['available']) || !empty($remoteRow['available']);
        }
        return $supplierSnapshot;
    }
}

if (!function_exists('cgoStorefrontHasEnabledApiProducts')) {
    function cgoStorefrontHasEnabledApiProducts(): bool
    {
        global $conn;
        if (supplierBridgeHasEnabledProducts()) return true;
        if (!cgoLiveOrderVisibleToCurrentSession() || !cgoEnsureTables()) {
            return false;
        }
        // Any enabled supplier product can appear either through the merged
        // catalogue or the dedicated API store.
        $result = $conn->query("SELECT 1 FROM cgo_products WHERE enabled = 1 LIMIT 1");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('cgoStorefrontInventoryRefreshNeeded')) {
    function cgoStorefrontInventoryRefreshNeeded(): bool
    {
        return cgoStorefrontHasEnabledApiProducts() && !cgoInventoryCacheIsFresh();
    }
}

if (!function_exists('cgoAdjustInventoryForAcceptedOrder')) {
    /**
     * Apply an accepted order to the local supplier snapshot once, then invalidate
     * that snapshot so a background products refresh confirms the shared stock.
     * The order flag keeps synchronous responses, webhooks, and reconciliation
     * from applying the same order more than once.
     */
    function cgoAdjustInventoryForAcceptedOrder(int $orderId): bool
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return false;

        $conn->begin_transaction();
        try {
            $select = $conn->prepare("SELECT cgo_product_id, quantity, inventory_adjusted
                FROM cgo_orders WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$select) throw new RuntimeException('inventory order lock prepare failed');
            $select->bind_param('i', $orderId);
            if (!$select->execute()) {
                $select->close();
                throw new RuntimeException('inventory order lock failed');
            }
            $result = $select->get_result();
            $order = $result ? $result->fetch_assoc() : null;
            $select->close();
            if (!$order) throw new RuntimeException('inventory order not found');
            if ((int) ($order['inventory_adjusted'] ?? 0) === 1) {
                // Historical orders are marked adjusted during migration so their
                // already-consumed supplier stock is not subtracted from a newly
                // fetched cache. A later success webhook/reconciliation must still
                // invalidate that cache, because the order may have completed after
                // the last products snapshot was taken.
                $invalidateExisting = $conn->prepare("UPDATE cgo_inventory_state
                    SET last_success_at = NULL, last_error = NULL
                    WHERE id = 1");
                if (!$invalidateExisting || !$invalidateExisting->execute()) {
                    if ($invalidateExisting) $invalidateExisting->close();
                    throw new RuntimeException('existing order inventory invalidation failed');
                }
                $invalidateExisting->close();
                $conn->commit();
                cgoInventoryState(true);
                return true;
            }

            $productId = (int) ($order['cgo_product_id'] ?? 0);
            $quantity = max(1, (int) ($order['quantity'] ?? 1));
            $updateProduct = $conn->prepare("UPDATE cgo_products
                SET remote_status = CASE
                        WHEN remote_stock IS NOT NULL AND GREATEST(remote_stock - ?, 0) = 0 THEN 'out_of_stock'
                        ELSE remote_status
                    END,
                    remote_stock = CASE
                        WHEN remote_stock IS NULL THEN NULL
                        ELSE GREATEST(remote_stock - ?, 0)
                    END,
                    local_inventory_adjusted_at = NOW()
                WHERE id = ?");
            if (!$updateProduct) throw new RuntimeException('inventory decrement prepare failed');
            $updateProduct->bind_param('iii', $quantity, $quantity, $productId);
            if (!$updateProduct->execute()) {
                $updateProduct->close();
                throw new RuntimeException('inventory decrement failed');
            }
            $updateProduct->close();

            $mark = $conn->prepare('UPDATE cgo_orders SET inventory_adjusted = 1 WHERE id = ?');
            if (!$mark) throw new RuntimeException('inventory order mark prepare failed');
            $mark->bind_param('i', $orderId);
            if (!$mark->execute()) {
                $mark->close();
                throw new RuntimeException('inventory order mark failed');
            }
            $mark->close();

            // A local decrement gives immediate feedback, but it is still only an
            // estimate because the same supplier account is shared by two sites
            // and other resellers can buy concurrently. Invalidate the global
            // snapshot so the next storefront poll confirms the shared stock.
            $invalidate = $conn->prepare("UPDATE cgo_inventory_state
                SET last_success_at = NULL, last_error = NULL
                WHERE id = 1");
            if (!$invalidate || !$invalidate->execute()) {
                if ($invalidate) $invalidate->close();
                throw new RuntimeException('inventory invalidation after order failed');
            }
            $invalidate->close();

            $conn->commit();
            cgoInventoryState(true);
            return true;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('CGO accepted-order inventory adjustment failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cgoProductDisplayTitle')) {
    function cgoProductDisplayTitle(array $product): string
    {
        $brand = trim((string) ($product['brand'] ?? ''));
        $name = trim((string) ($product['name'] ?? ''));
        return $brand !== '' ? $brand : $name;
    }
}

if (!function_exists('cgoProductVariantLabel')) {
    function cgoProductVariantLabel(array $product): string
    {
        $brand = trim((string) ($product['brand'] ?? ''));
        $name = trim((string) ($product['name'] ?? ''));
        if ($brand === '' || $name === '' || strcasecmp($brand, $name) === 0) {
            return '';
        }
        return $name;
    }
}

if (!function_exists('cgoEncodeUrlPath')) {
    function cgoEncodeUrlPath(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $encoded = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return '';
            }
            $encoded[] = rawurlencode($segment);
        }
        return implode('/', $encoded);
    }
}

if (!function_exists('cgoProductImageUrl')) {
    function cgoProductImageUrl(array $product): string
    {
        $image = trim((string) ($product['image_path'] ?? ''));
        if ($image === '') {
            return '';
        }

        // Absolute HTTPS URLs from the API are safe to use directly.
        if (filter_var($image, FILTER_VALIDATE_URL) !== false) {
            return strtolower((string) parse_url($image, PHP_URL_SCHEME)) === 'https' ? $image : '';
        }
        if (substr($image, 0, 2) === '//') {
            $candidate = 'https:' . $image;
            return filter_var($candidate, FILTER_VALIDATE_URL) !== false ? $candidate : '';
        }

        $config = cgoConfig();
        $base = trim((string) ($config['product_image_base_url'] ?? ''));

        // A leading slash is an explicit provider-relative path, so it can be
        // resolved against the documented API origin without inventing a folder.
        if (substr($image, 0, 1) === '/') {
            $endpoint = parse_url((string) ($config['endpoint'] ?? ''));
            $host = is_array($endpoint) ? (string) ($endpoint['host'] ?? '') : '';
            if ($host === '') {
                return '';
            }
            $encoded = cgoEncodeUrlPath($image);
            return $encoded === '' ? '' : 'https://' . $host . '/' . $encoded;
        }

        // CHEATGAME currently returns bare filenames (for example
        // 1780613271_brand_52.png). A filename alone does not reveal its real
        // public folder. Use it only when the provider's exact base URL has been
        // configured; do not guess /uploads, /assets, or another path.
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https') {
            return '';
        }
        $encoded = cgoEncodeUrlPath($image);
        return $encoded === '' ? '' : rtrim($base, '/') . '/' . $encoded;
    }
}


if (!function_exists('cgoProviderImageHost')) {
    function cgoProviderImageHost(): string
    {
        $host = strtolower((string) parse_url((string) (cgoConfig()['endpoint'] ?? ''), PHP_URL_HOST));
        return trim($host);
    }
}

if (!function_exists('cgoIsProviderManagedImage')) {
    function cgoIsProviderManagedImage(string $image): bool
    {
        $image = trim($image);
        if ($image === '') {
            return false;
        }

        // Files cached by this integration are safe to refresh from the supplier.
        if (preg_match('#^assets/uploads/products/cgo_[a-f0-9]{64}\\.(?:jpe?g|png|webp|gif)$#i', ltrim($image, '/')) === 1) {
            return true;
        }

        if (substr($image, 0, 2) === '//') {
            $image = 'https:' . $image;
        }
        if (filter_var($image, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($image, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($image, PHP_URL_HOST));
        return $scheme === 'https' && $host !== '' && hash_equals(cgoProviderImageHost(), $host);
    }
}

if (!function_exists('cgoCacheProviderImage')) {
    function cgoCacheProviderImage(string $url): string
    {
        static $memo = [];
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (array_key_exists($url, $memo)) {
            return $memo[$url];
        }
        $memo[$url] = '';

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || $host === '' || !hash_equals(cgoProviderImageHost(), $host)) {
            return '';
        }

        $uploadDirFs = dirname(__DIR__) . '/assets/uploads/products/';
        $uploadDirRel = 'assets/uploads/products/';
        if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
            error_log('CGO image cache directory could not be created: ' . $uploadDirFs);
            return '';
        }

        $hash = hash('sha256', $url);
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $existingExt) {
            $existing = $uploadDirFs . 'cgo_' . $hash . '.' . $existingExt;
            if (!is_file($existing) || filesize($existing) < 1) continue;

            // Older cached supplier artwork may be several megabytes. Upgrade
            // it lazily when this product is synchronized again, but keep the
            // old file so legacy database rows cannot suddenly point at 404.
            $existingInfo = @getimagesize($existing);
            $existingSize = (int) @filesize($existing);
            if (function_exists('sakazukiWriteOptimizedProductImage')
                && is_array($existingInfo)
                && (($existingInfo[0] ?? 0) > 1024 || ($existingInfo[1] ?? 0) > 1024 || $existingSize > 360 * 1024)) {
                $existingBytes = @file_get_contents($existing);
                if (is_string($existingBytes) && $existingBytes !== '') {
                    $optimized = sakazukiWriteOptimizedProductImage($existingBytes, $uploadDirFs, 'cgo_tmp_', 1024);
                    if (!empty($optimized['success']) && !empty($optimized['filename'])) {
                        $tempOptimized = $uploadDirFs . $optimized['filename'];
                        $optimizedExt = strtolower((string) pathinfo($tempOptimized, PATHINFO_EXTENSION));
                        $finalOptimized = $uploadDirFs . 'cgo_' . $hash . '.' . $optimizedExt;
                        if ($tempOptimized === $finalOptimized || @rename($tempOptimized, $finalOptimized)) {
                            @chmod($finalOptimized, 0644);
                            return $memo[$url] = $uploadDirRel . basename($finalOptimized);
                        }
                        @unlink($tempOptimized);
                    }
                }
            }
            return $memo[$url] = $uploadDirRel . basename($existing);
        }

        if (!function_exists('curl_init')) {
            return '';
        }

        $config = cgoConfig();
        $buffer = '';
        $tooLarge = false;
        $maxBytes = 5 * 1024 * 1024;
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(2, min(15, (int) ($config['connect_timeout'] ?? 8))),
            CURLOPT_TIMEOUT => max(5, min(30, (int) ($config['request_timeout'] ?? 25))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Sakazuki-CHEATGAME-ImageSync/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: image/jpeg,image/png,image/webp,image/gif,image/*;q=0.8',
                'Cache-Control: no-cache',
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$tooLarge, $maxBytes): int {
                $newLength = strlen($buffer) + strlen($chunk);
                if ($newLength > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (!empty($config['force_ipv4']) && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $tooLarge || $httpCode !== 200 || $buffer === '') {
            error_log('CGO image cache fetch failed: HTTP ' . $httpCode . ($curlError !== '' ? ' / ' . $curlError : '') . ' / ' . $url);
            return '';
        }

        $contentType = trim(explode(';', $contentType, 2)[0]);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $finfoMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $buffer);
                if (is_string($detected)) {
                    $finfoMime = strtolower(trim($detected));
                }
                finfo_close($finfo);
            }
        }
        $mime = isset($allowed[$finfoMime]) ? $finfoMime : $contentType;
        if (!isset($allowed[$mime])) {
            error_log('CGO image cache rejected non-image MIME ' . $mime . ' / ' . $url);
            return '';
        }

        $imageInfo = @getimagesizefromstring($buffer);
        if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])
            || (int) $imageInfo[0] > 6000 || (int) $imageInfo[1] > 6000
            || ((int) $imageInfo[0] * (int) $imageInfo[1]) > 24000000) {
            error_log('CGO image cache rejected invalid image dimensions / ' . $url);
            return '';
        }

        // Resize/convert supplier artwork before publishing it to the shop.
        // This is server-side maintenance, not browser work, and requires no
        // ImageMagick/root access on shared hosting.
        if (function_exists('sakazukiWriteOptimizedProductImage')) {
            $optimized = sakazukiWriteOptimizedProductImage($buffer, $uploadDirFs, 'cgo_tmp_', 1024);
            if (!empty($optimized['success']) && !empty($optimized['filename'])) {
                $tempOptimized = $uploadDirFs . $optimized['filename'];
                $optimizedExt = strtolower((string) pathinfo($tempOptimized, PATHINFO_EXTENSION));
                $destination = $uploadDirFs . 'cgo_' . $hash . '.' . $optimizedExt;
                if ($tempOptimized === $destination || @rename($tempOptimized, $destination)) {
                    @chmod($destination, 0644);
                    return $memo[$url] = $uploadDirRel . basename($destination);
                }
                @unlink($tempOptimized);
            }
        }

        // Compatibility fallback for hosting without GD support.
        $destination = $uploadDirFs . 'cgo_' . $hash . '.' . $allowed[$mime];
        try {
            $tempSuffix = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $tempSuffix = str_replace('.', '', uniqid('', true));
        }
        $temp = $destination . '.tmp-' . $tempSuffix;
        if (file_put_contents($temp, $buffer, LOCK_EX) !== strlen($buffer)) {
            @unlink($temp);
            return '';
        }
        @chmod($temp, 0644);
        if (!@rename($temp, $destination)) {
            @unlink($temp);
            return '';
        }
        @chmod($destination, 0644);
        return $memo[$url] = $uploadDirRel . basename($destination);
    }
}

if (!function_exists('cgoResolveCatalogImage')) {
    function cgoResolveCatalogImage(array $product): string
    {
        $remoteUrl = cgoProductImageUrl($product);
        if ($remoteUrl === '') {
            return '';
        }

        // Cache the exact URL supplied by CHEATGAME locally. This avoids browser
        // hotlink restrictions and keeps the shop usable when the supplier image
        // server is temporarily slow. If caching is unavailable, keep the verified
        // HTTPS URL as a non-destructive fallback.
        $cached = cgoCacheProviderImage($remoteUrl);
        return $cached !== '' ? $cached : $remoteUrl;
    }
}

if (!function_exists('cgoShouldReplaceLocalImage')) {
    function cgoShouldReplaceLocalImage(string $currentImage): bool
    {
        $currentImage = trim($currentImage);
        return $currentImage === '' || cgoIsProviderManagedImage($currentImage);
    }
}

if (!function_exists('cgoGetBaseCurrency')) {
    function cgoGetBaseCurrency(): ?string
    {
        $base = strtoupper(trim((string) getSetting('currency_name', 'THB')));
        return in_array($base, ['THB', 'USD'], true) ? $base : null;
    }
}

if (!function_exists('cgoUsdToBase')) {
    function cgoUsdToBase(float $usd): ?float
    {
        if (!is_finite($usd) || $usd < 0) {
            return null;
        }
        $base = cgoGetBaseCurrency();
        if ($base === 'USD') {
            return round($usd, 2);
        }
        if ($base === 'THB') {
            $thbToUsd = getExchangeRateThbToUsd();
            if (!is_numeric($thbToUsd) || (float) $thbToUsd <= 0) {
                return null;
            }
            return round($usd / (float) $thbToUsd, 2);
        }
        return null;
    }
}

if (!function_exists('cgoGetMarkupSettings')) {
    function cgoGetMarkupSettings(): array
    {
        $user = getSetting('cgo_user_markup_percent', '0');
        $reseller = getSetting('cgo_reseller_markup_percent', '0');
        $userValue = is_numeric($user) ? (float) $user : 0.0;
        $resellerValue = is_numeric($reseller) ? (float) $reseller : 0.0;
        return [
            'user' => max(0.0, min(1000.0, $userValue)),
            'reseller' => max(0.0, min(1000.0, $resellerValue)),
        ];
    }
}

if (!function_exists('cgoSetMarkupSettings')) {
    function cgoSetMarkupSettings(float $userMarkup, float $resellerMarkup): bool
    {
        global $conn;
        if (!is_finite($userMarkup) || !is_finite($resellerMarkup) || $userMarkup < 0 || $resellerMarkup < 0 || $userMarkup > 1000 || $resellerMarkup > 1000) {
            return false;
        }
        try {
            $conn->begin_transaction();
            $ok = upsertSetting('cgo_user_markup_percent', number_format($userMarkup, 4, '.', ''))
                && upsertSetting('cgo_reseller_markup_percent', number_format($resellerMarkup, 4, '.', ''));
            if (!$ok) throw new RuntimeException('Unable to save markup settings');
            $conn->commit();
            return true;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('CGO markup settings update failed: ' . $e->getMessage());
            return false;
        }
    }
}


if (!function_exists('cgoApplyMarkupPricing')) {
    /**
     * Recalculate stored selling prices from the current global markup settings.
     * When $includeManual is true, manual overrides are intentionally cleared so
     * one admin action can bring the whole supplier catalogue back to automatic
     * pricing. Every computed value is clamped to the current supplier cost.
     */
    function cgoApplyMarkupPricing(bool $includeManual = true): array
    {
        global $conn;
        if (!cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Unable to prepare CHEATGAME database tables'];
        }
        if (cgoGetBaseCurrency() === null) {
            return ['success' => false, 'message' => 'Store base currency must be THB or USD'];
        }

        $markups = cgoGetMarkupSettings();
        $where = $includeManual ? '' : ' WHERE manual_price_saved = 0';
        $countResult = $conn->query('SELECT COUNT(*) AS c FROM cgo_products' . $where);
        if (!$countResult) {
            error_log('CGO markup target count failed: ' . $conn->error);
            return ['success' => false, 'message' => 'Unable to count products for markup update'];
        }
        $countRow = $countResult->fetch_assoc();
        $countResult->free();
        $targetCount = (int) ($countRow['c'] ?? 0);

        $sql = "UPDATE cgo_products
                SET user_price_base = GREATEST(ROUND(cost_base, 2), ROUND(cost_base * (1 + (? / 100)), 2)),
                    reseller_price_base = GREATEST(ROUND(cost_base, 2), ROUND(cost_base * (1 + (? / 100)), 2)),
                    price_sync_enabled = 1,
                    manual_price_saved = 0,
                    saved_user_price_base = NULL,
                    saved_reseller_price_base = NULL,
                    price_warning_code = NULL,
                    price_warning_at = NULL" . $where;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to prepare markup price update'];
        }
        $userMarkup = (float) $markups['user'];
        $resellerMarkup = (float) $markups['reseller'];
        $stmt->bind_param('dd', $userMarkup, $resellerMarkup);

        try {
            $conn->begin_transaction();
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error !== '' ? $stmt->error : 'Markup price update failed');
            }
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            try { $stmt->close(); } catch (Throwable $ignored) {}
            error_log('CGO markup price update failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to apply markup pricing'];
        }

        $linkedSynced = 0;
        foreach (cgoGetCatalogLinks() as $linkedCgoId => $link) {
            if (empty($link['sync_price'])) continue;
            if (cgoApplyLinkedCatalogueSync((int) $linkedCgoId)) {
                $linkedSynced++;
            }
        }

        return [
            'success' => true,
            'updated' => $targetCount,
            'linked_synced' => $linkedSynced,
            'manual_overrides_cleared' => $includeManual,
        ];
    }
}

if (!function_exists('cgoSyncProducts')) {
    function cgoSyncProducts(): array
    {
        global $conn;
        $syncStage = 'schema';
        if (!cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Unable to prepare CHEATGAME database tables', 'error_code' => 'cgo_schema_unavailable', 'error_stage' => $syncStage];
        }
        $syncStage = 'currency';
        if (cgoGetBaseCurrency() === null) {
            return ['success' => false, 'message' => 'Store base currency must be THB or USD', 'error_code' => 'cgo_base_currency_invalid', 'error_stage' => $syncStage];
        }

        $syncStage = 'provider_products';
        $api = cgoApiRequest('products');
        if (!$api['ok'] || !is_array($api['data'])) {
            return [
                'success' => false,
                'message' => $api['error'] ?: 'Unable to load products',
                'http_code' => $api['http_code'],
                'error_code' => 'cgo_provider_products_failed',
                'error_stage' => $syncStage,
                'error_detail' => (string) ($api['error'] ?? ''),
            ];
        }
        $items = cgoExtractProductList($api['data']);
        if ($items === []) {
            return ['success' => false, 'message' => 'API response did not contain a valid product list', 'error_code' => 'cgo_product_list_invalid', 'error_stage' => $syncStage];
        }

        // Load the current local state before calculating replacements. The API is
        // authoritative for cost, stock, and metadata. Manual selling prices are
        // preserved unless they fall below the new supplier cost; those values are
        // raised to cost automatically so a large catalogue never needs hand fixes.
        $existingByRemoteId = [];
        $existingResult = $conn->query("SELECT id, remote_product_id, image_path, cached_image_path, enabled,
                                              supplier_removed_at, enabled_before_supplier_removal,
                                              manual_price_saved, saved_user_price_base, saved_reseller_price_base,
                                              user_price_base, reseller_price_base
                                       FROM cgo_products");
        if ($existingResult) {
            while ($row = $existingResult->fetch_assoc()) {
                $existingByRemoteId[(string) $row['remote_product_id']] = $row;
            }
        }

        // A full manual catalogue sync is authoritative, but the supplier endpoint
        // may occasionally return an incomplete list. Before archiving any local
        // product that is absent from the first response, request the catalogue a
        // second time and merge both responses. Only IDs absent from both successful
        // responses are treated as removed by the supplier.
        $itemsByRemoteId = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $remoteId = cgoFirstScalar($item, ['product_id', 'id']);
            if ($remoteId === '' || !ctype_digit($remoteId) || (int) $remoteId < 1) continue;
            $itemsByRemoteId[substr($remoteId, 0, 120)] = $item;
        }

        $firstPassMissingIds = [];
        foreach ($existingByRemoteId as $existingRemoteId => $existingRow) {
            if (!empty($existingRow['supplier_removed_at'])) continue;
            if (!isset($itemsByRemoteId[(string) $existingRemoteId])) {
                $firstPassMissingIds[(string) $existingRemoteId] = true;
            }
        }

        $removalConfirmationSucceeded = true;
        $removalConfirmationAttempted = $firstPassMissingIds !== [];
        $removalConfirmationError = '';
        if ($removalConfirmationAttempted) {
            $confirmApi = cgoApiRequest('products');
            $confirmItems = !empty($confirmApi['ok']) && is_array($confirmApi['data'])
                ? cgoExtractProductList($confirmApi['data'])
                : [];
            if ($confirmItems === []) {
                $removalConfirmationSucceeded = false;
                $removalConfirmationError = trim((string) ($confirmApi['error'] ?? ''));
                if ($removalConfirmationError === '') {
                    $removalConfirmationError = 'The second catalogue response was unavailable or invalid';
                }
            } else {
                $confirmedItemsByRemoteId = [];
                foreach ($confirmItems as $item) {
                    if (!is_array($item)) continue;
                    $remoteId = cgoFirstScalar($item, ['product_id', 'id']);
                    if ($remoteId === '' || !ctype_digit($remoteId) || (int) $remoteId < 1) continue;
                    $confirmedItemsByRemoteId[substr($remoteId, 0, 120)] = $item;
                }
                if ($confirmedItemsByRemoteId === []) {
                    $removalConfirmationSucceeded = false;
                    $removalConfirmationError = 'The second catalogue response did not contain usable product IDs';
                } else {
                    foreach ($confirmedItemsByRemoteId as $remoteId => $item) {
                        $itemsByRemoteId[$remoteId] = $item;
                    }
                }
            }
        }
        if ($itemsByRemoteId !== []) {
            $items = array_values($itemsByRemoteId);
        }

        $supplierRemovedIds = [];
        if ($removalConfirmationSucceeded) {
            foreach ($existingByRemoteId as $existingRemoteId => $existingRow) {
                if (!empty($existingRow['supplier_removed_at'])) continue;
                if (!isset($itemsByRemoteId[(string) $existingRemoteId])) {
                    $supplierRemovedIds[(string) $existingRemoteId] = true;
                }
            }
        }

        $markups = cgoGetMarkupSettings();
        $normalized = [];
        $invalid = 0;
        $preservedPrices = 0;
        $priceCorrections = [];
        foreach ($items as $item) {
            $product = cgoNormalizeProduct($item);
            if ($product === null) {
                $invalid++;
                continue;
            }
            $baseCost = cgoUsdToBase((float) $product['price_usd']);
            if ($baseCost === null) {
                return ['success' => false, 'message' => 'THB/USD exchange rate is unavailable; product prices were not changed', 'error_code' => 'cgo_exchange_rate_unavailable', 'error_stage' => 'price_conversion'];
            }

            $autoUserPrice = round($baseCost * (1 + $markups['user'] / 100), 2);
            $autoResellerPrice = round($baseCost * (1 + $markups['reseller'] / 100), 2);
            // A zero markup is valid, but a floating-point conversion must never
            // leave a computed selling price fractionally below cost.
            $autoUserPrice = max(round($baseCost, 2), $autoUserPrice);
            $autoResellerPrice = max(round($baseCost, 2), $autoResellerPrice);

            $remoteId = (string) $product['remote_product_id'];
            $existing = $existingByRemoteId[$remoteId] ?? null;
            $product['cached_image_path'] = '';
            if (is_array($existing)
                && trim((string) ($existing['image_path'] ?? '')) === trim((string) $product['image_path'])) {
                $product['cached_image_path'] = trim((string) ($existing['cached_image_path'] ?? ''));
            }

            $product['cost_base'] = round($baseCost, 2);
            $product['user_price_base'] = $autoUserPrice;
            $product['reseller_price_base'] = $autoResellerPrice;
            $product['price_warning_code'] = '';

            if (is_array($existing) && (int) ($existing['manual_price_saved'] ?? 0) === 1) {
                $savedUser = is_numeric($existing['saved_user_price_base'] ?? null)
                    ? round((float) $existing['saved_user_price_base'], 2)
                    : round((float) ($existing['user_price_base'] ?? 0), 2);
                $savedReseller = is_numeric($existing['saved_reseller_price_base'] ?? null)
                    ? round((float) $existing['saved_reseller_price_base'], 2)
                    : round((float) ($existing['reseller_price_base'] ?? 0), 2);

                $correctedUser = max($product['cost_base'], $savedUser);
                $correctedReseller = max($product['cost_base'], $savedReseller);
                $product['user_price_base'] = $correctedUser;
                $product['reseller_price_base'] = $correctedReseller;
                $preservedPrices++;

                if ($correctedUser !== $savedUser || $correctedReseller !== $savedReseller) {
                    $priceCorrections[] = [
                        'remote_product_id' => $remoteId,
                        'title' => trim((string) $product['brand']) !== ''
                            ? trim((string) $product['brand']) . ' · ' . trim((string) $product['name'])
                            : trim((string) $product['name']),
                        'cost_base' => $product['cost_base'],
                        'old_user_price_base' => $savedUser,
                        'old_reseller_price_base' => $savedReseller,
                        'new_user_price_base' => $correctedUser,
                        'new_reseller_price_base' => $correctedReseller,
                    ];
                }
            }
            $normalized[] = $product;
        }
        if ($normalized === []) {
            return ['success' => false, 'message' => 'No valid products were returned by the API'];
        }

        $sql = "INSERT INTO cgo_products
            (remote_product_id, name, brand, description, image_path, cached_image_path, category, duration, platform, remote_status, remote_stock,
             currency, price_usd, price_idr, exchange_rate_idr, cost_base, user_price_base, reseller_price_base,
             price_warning_code, raw_json, last_synced_at, inventory_checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name), brand = VALUES(brand), description = VALUES(description),
                image_path = VALUES(image_path), cached_image_path = VALUES(cached_image_path),
                enabled = IF(supplier_removed_at IS NOT NULL,
                             COALESCE(enabled_before_supplier_removal, 0),
                             enabled),
                supplier_removed_at = NULL, enabled_before_supplier_removal = NULL,
                category = VALUES(category), duration = VALUES(duration), platform = VALUES(platform), remote_status = VALUES(remote_status),
                remote_stock = COALESCE(VALUES(remote_stock), remote_stock), currency = VALUES(currency), price_usd = VALUES(price_usd), price_idr = VALUES(price_idr),
                exchange_rate_idr = VALUES(exchange_rate_idr), cost_base = VALUES(cost_base),
                user_price_base = VALUES(user_price_base), reseller_price_base = VALUES(reseller_price_base),
                price_warning_code = VALUES(price_warning_code),
                price_warning_at = IF(VALUES(price_warning_code) IS NULL, NULL, NOW()),
                raw_json = VALUES(raw_json), last_synced_at = NOW(),
                inventory_checked_at = IF(VALUES(remote_stock) IS NULL, inventory_checked_at, NOW())";
        $syncStage = 'prepare_product_upsert';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('CGO product sync prepare failed: ' . $conn->error);
            return [
                'success' => false,
                'message' => 'Unable to prepare product sync',
                'error_code' => 'cgo_product_sync_prepare_failed',
                'error_stage' => $syncStage,
                'error_detail' => (string) $conn->error,
            ];
        }

        $synced = 0;
        $removed = 0;
        $conn->begin_transaction();
        try {
            $syncStage = 'sync_product_rows';
            foreach ($normalized as $p) {
                $remoteStock = $p['remote_stock'];
                $priceIdr = $p['price_idr'];
                $rateIdr = $p['exchange_rate_idr'];
                $remoteProductId = (string) $p['remote_product_id'];
                $name = (string) $p['name'];
                $brand = (string) $p['brand'];
                $description = (string) $p['description'];
                $imagePath = (string) $p['image_path'];
                $cachedImagePath = (string) $p['cached_image_path'];
                $category = (string) $p['category'];
                $duration = (string) $p['duration'];
                $platform = (string) $p['platform'];
                $remoteStatus = (string) $p['remote_status'];
                $currency = (string) $p['currency'];
                $priceUsd = (float) $p['price_usd'];
                $costBase = (float) $p['cost_base'];
                $userPriceBase = (float) $p['user_price_base'];
                $resellerPriceBase = (float) $p['reseller_price_base'];
                $priceWarningCode = (string) $p['price_warning_code'];
                $rawJson = (string) $p['raw_json'];
                $stmt->bind_param(
                    'ssssssssssisddddddss',
                    $remoteProductId,
                    $name,
                    $brand,
                    $description,
                    $imagePath,
                    $cachedImagePath,
                    $category,
                    $duration,
                    $platform,
                    $remoteStatus,
                    $remoteStock,
                    $currency,
                    $priceUsd,
                    $priceIdr,
                    $rateIdr,
                    $costBase,
                    $userPriceBase,
                    $resellerPriceBase,
                    $priceWarningCode,
                    $rawJson
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('Product sync failed: ' . $stmt->error);
                }
                $synced++;
            }
            $stmt->close();

            $syncStage = 'price_corrections';
            if ($priceCorrections !== []) {
                $correctionStmt = $conn->prepare("UPDATE cgo_products
                    SET user_price_base = ?, reseller_price_base = ?,
                        saved_user_price_base = ?, saved_reseller_price_base = ?,
                        price_warning_code = NULL, price_warning_at = NULL
                    WHERE remote_product_id = ? AND manual_price_saved = 1");
                if (!$correctionStmt) {
                    throw new RuntimeException('Corrected price update prepare failed');
                }
                foreach ($priceCorrections as $correction) {
                    $correctedUser = (float) $correction['new_user_price_base'];
                    $correctedReseller = (float) $correction['new_reseller_price_base'];
                    $correctedRemoteId = (string) $correction['remote_product_id'];
                    $correctionStmt->bind_param(
                        'dddds',
                        $correctedUser,
                        $correctedReseller,
                        $correctedUser,
                        $correctedReseller,
                        $correctedRemoteId
                    );
                    if (!$correctionStmt->execute()) {
                        throw new RuntimeException('Corrected price update failed: ' . $correctionStmt->error);
                    }
                }
                $correctionStmt->close();
            }

            // Remove confirmed supplier deletions from the live catalogue without
            // deleting their database rows. Historical orders and catalogue links
            // still reference the internal product ID, so hard deletion would break
            // transaction evidence. A later full sync automatically restores the
            // product and its previous enabled state if the supplier publishes it again.
            $syncStage = 'supplier_removal';
            if ($supplierRemovedIds !== []) {
                $archiveStmt = $conn->prepare("UPDATE cgo_products
                    SET enabled_before_supplier_removal = IF(supplier_removed_at IS NULL, enabled, enabled_before_supplier_removal),
                        enabled = 0, remote_stock = 0, remote_status = 'supplier_removed',
                        supplier_removed_at = COALESCE(supplier_removed_at, NOW()),
                        inventory_checked_at = NOW(), last_synced_at = NOW()
                    WHERE remote_product_id = ? AND supplier_removed_at IS NULL");
                if (!$archiveStmt) {
                    throw new RuntimeException('Supplier removal archive prepare failed');
                }
                foreach (array_keys($supplierRemovedIds) as $removedRemoteId) {
                    $archiveStmt->bind_param('s', $removedRemoteId);
                    if (!$archiveStmt->execute()) {
                        throw new RuntimeException('Supplier removal archive failed: ' . $archiveStmt->error);
                    }
                    $removed += max(0, $archiveStmt->affected_rows);
                }
                $archiveStmt->close();
            }
            $missingProducts = count($supplierRemovedIds);

            $syncStage = 'inventory_state';
            $inventoryState = $conn->prepare("UPDATE cgo_inventory_state
                SET last_attempt_at = NOW(), last_success_at = NOW(), last_error = NULL
                WHERE id = 1");
            if (!$inventoryState || !$inventoryState->execute()) {
                if ($inventoryState) $inventoryState->close();
                throw new RuntimeException('Inventory state update failed during full sync');
            }
            $inventoryState->close();
            $conn->commit();
            cgoInventoryState(true);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            try { $stmt->close(); } catch (Throwable $ignored) {}
            error_log('CGO product sync failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Product sync could not be completed',
                'error_code' => 'cgo_product_sync_transaction_failed',
                'error_stage' => $syncStage,
                'error_detail' => $e->getMessage(),
            ];
        }

        $links = cgoGetCatalogLinks();
        $linkedSynced = 0;
        foreach ($links as $linkedCgoId => $link) {
            if (cgoApplyLinkedCatalogueSync((int) $linkedCgoId)) $linkedSynced++;
        }

        return [
            'success' => true,
            'synced' => $synced,
            'invalid' => $invalid,
            'missing' => $missingProducts ?? 0,
            'removed' => $removed,
            'removal_confirmation_attempted' => $removalConfirmationAttempted,
            'removal_confirmation_succeeded' => $removalConfirmationSucceeded,
            'removal_confirmation_error' => $removalConfirmationError,
            'removal_skipped' => $removalConfirmationAttempted && !$removalConfirmationSucceeded
                ? count($firstPassMissingIds)
                : 0,
            'linked_synced' => $linkedSynced,
            'preserved_prices' => $preservedPrices,
            'price_warning_count' => 0,
            'price_warnings' => [],
            'price_correction_count' => count($priceCorrections),
            'price_corrections' => $priceCorrections,
            'message' => 'Products synchronized',
        ];
    }
}

if (!function_exists('cgoGetProducts')) {
    function cgoGetProducts(bool $enabledOnly = false): array
    {
        global $conn;
        if (!cgoEnsureTables()) {
            return [];
        }
        $sql = 'SELECT * FROM cgo_products WHERE supplier_removed_at IS NULL';
        if ($enabledOnly) {
            $sql .= " AND enabled = 1 AND remote_stock > 0";
        }
        $sql .= " ORDER BY COALESCE(NULLIF(brand, ''), name) ASC, name ASC, id ASC";
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('cgoGetProductsForStorefront')) {
    /**
     * Keep enabled products visible even when the saved stock is zero. This lets
     * background polling show restocks without requiring an administrator sync or
     * hiding the product card completely.
     */
    function cgoGetProductsForStorefront(): array
    {
        global $conn;
        if (!cgoEnsureTables()) return [];
        $result = $conn->query("SELECT * FROM cgo_products
                               WHERE supplier_removed_at IS NULL AND enabled = 1
                               ORDER BY COALESCE(NULLIF(brand, ''), name) ASC, name ASC, id ASC");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('cgoGetProductById')) {
    function cgoGetProductById(int $id, bool $enabledOnly = false): ?array
    {
        global $conn;
        if ($id < 1 || !cgoEnsureTables()) {
            return null;
        }
        $sql = 'SELECT * FROM cgo_products WHERE id = ?';
        if ($enabledOnly) {
            $sql .= " AND enabled = 1 AND remote_stock > 0";
        }
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('cgoSaveProductPricing')) {
    function cgoSaveProductPricing(
        int $id,
        float $userPrice,
        float $resellerPrice,
        bool $enabled,
        bool $automaticMode = false
    ): array {
        global $conn;
        if ($id < 1 || !is_finite($userPrice) || !is_finite($resellerPrice)
            || $userPrice < 0 || $resellerPrice < 0
            || $userPrice > 10000000 || $resellerPrice > 10000000
            || ($enabled && ($userPrice <= 0 || $resellerPrice <= 0))
            || !cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Invalid product pricing'];
        }

        $product = cgoGetProductById($id, false);
        if (!$product) {
            return ['success' => false, 'message' => 'CHEATGAME product not found'];
        }
        if (!empty($product['supplier_removed_at'])
            || strtolower(trim((string) ($product['remote_status'] ?? ''))) === 'supplier_removed') {
            return ['success' => false, 'message' => 'This supplier product is no longer available'];
        }
        $cost = round((float) ($product['cost_base'] ?? 0), 2);
        $currentUserPrice = round((float) ($product['user_price_base'] ?? 0), 2);
        $currentResellerPrice = round((float) ($product['reseller_price_base'] ?? 0), 2);
        $userPrice = round($userPrice, 2);
        $resellerPrice = round($resellerPrice, 2);

        // Editing either price is an explicit manual override even if an old form
        // still submitted the automatic-mode checkbox. This prevents a reviewed
        // price from being silently discarded on the next sync.
        if ($automaticMode
            && (abs($userPrice - $currentUserPrice) >= 0.005
                || abs($resellerPrice - $currentResellerPrice) >= 0.005)) {
            $automaticMode = false;
        }

        if ($userPrice < $cost || $resellerPrice < $cost) {
            return [
                'success' => false,
                'message' => 'Selling prices cannot be lower than the current supplier cost',
                'cost_base' => $cost,
            ];
        }

        $enabledInt = $enabled ? 1 : 0;
        $autoInt = $automaticMode ? 1 : 0;
        if ($automaticMode) {
            $stmt = $conn->prepare("UPDATE cgo_products
                SET user_price_base = ?, reseller_price_base = ?, enabled = ?,
                    price_sync_enabled = 1, manual_price_saved = 0,
                    saved_user_price_base = NULL, saved_reseller_price_base = NULL,
                    price_warning_code = NULL, price_warning_at = NULL
                WHERE id = ?");
            if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare product pricing'];
            $stmt->bind_param('ddii', $userPrice, $resellerPrice, $enabledInt, $id);
        } else {
            $stmt = $conn->prepare("UPDATE cgo_products
                SET user_price_base = ?, reseller_price_base = ?, enabled = ?,
                    price_sync_enabled = 0, manual_price_saved = 1,
                    saved_user_price_base = ?, saved_reseller_price_base = ?,
                    price_warning_code = NULL, price_warning_at = NULL
                WHERE id = ?");
            if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare product pricing'];
            $stmt->bind_param('ddiddi', $userPrice, $resellerPrice, $enabledInt, $userPrice, $resellerPrice, $id);
        }
        $ok = $stmt->execute() && $stmt->affected_rows >= 0;
        $error = $stmt->error;
        $stmt->close();
        return $ok
            ? ['success' => true, 'automatic_mode' => $autoInt === 1]
            : ['success' => false, 'message' => $error !== '' ? $error : 'Unable to save product pricing'];
    }
}

if (!function_exists('cgoUpdateProductPricing')) {
    function cgoUpdateProductPricing(int $id, float $userPrice, float $resellerPrice, bool $enabled, bool $priceSyncEnabled = false): bool
    {
        $result = cgoSaveProductPricing($id, $userPrice, $resellerPrice, $enabled, $priceSyncEnabled);
        return !empty($result['success']);
    }
}

if (!function_exists('cgoNormalizeCategoryInput')) {
    function cgoNormalizeCategoryInput($input): array
    {
        $values = is_array($input) ? $input : preg_split('/[\r\n,]+/u', (string) $input);
        $categories = [];
        foreach ((array) $values as $value) {
            if (!is_scalar($value)) continue;
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 100 || preg_match('/[\x00-\x1F\x7F]/', $value)) continue;
            if (!in_array($value, $categories, true)) $categories[] = $value;
            if (count($categories) >= 4) break;
        }
        return $categories;
    }
}

if (!function_exists('cgoReplaceLocalProductCategories')) {
    function cgoReplaceLocalProductCategories(int $productId, array $categories): bool
    {
        global $conn;
        if ($productId < 1) return false;
        $categories = cgoNormalizeCategoryInput($categories);

        $delete = $conn->prepare('DELETE FROM product_category_links WHERE product_id = ?');
        if (!$delete) return false;
        $delete->bind_param('i', $productId);
        if (!$delete->execute()) {
            $delete->close();
            return false;
        }
        $delete->close();

        if ($categories === []) {
            $clear = $conn->prepare("UPDATE products SET category = '' WHERE id = ?");
            if (!$clear) return false;
            $clear->bind_param('i', $productId);
            $ok = $clear->execute();
            $clear->close();
            return $ok;
        }

        $insert = $conn->prepare('INSERT INTO product_category_links (product_id, category) VALUES (?, ?)');
        $ensure = $conn->prepare("INSERT INTO categories (name, download_url) VALUES (?, '') ON DUPLICATE KEY UPDATE name = VALUES(name)");
        if (!$insert || !$ensure) {
            if ($insert) $insert->close();
            if ($ensure) $ensure->close();
            return false;
        }
        foreach ($categories as $category) {
            $insert->bind_param('is', $productId, $category);
            if (!$insert->execute()) {
                $insert->close();
                $ensure->close();
                return false;
            }
            $ensure->bind_param('s', $category);
            if (!$ensure->execute()) {
                $insert->close();
                $ensure->close();
                return false;
            }
        }
        $insert->close();
        $ensure->close();

        $first = $categories[0];
        $update = $conn->prepare('UPDATE products SET category = ? WHERE id = ?');
        if (!$update) return false;
        $update->bind_param('si', $first, $productId);
        $ok = $update->execute();
        $update->close();
        return $ok;
    }
}

if (!function_exists('cgoGetCatalogLinks')) {
    function cgoGetCatalogLinks(): array
    {
        global $conn;
        if (!cgoEnsureTables()) return [];
        $sql = "SELECT l.*, p.name AS local_product_name, p.image AS local_product_image, p.status AS local_product_status,
                       pv.duration AS local_variant_name, pv.status AS local_variant_status,
                       pv.price_user AS local_price_user, pv.price_reseller AS local_price_reseller
                FROM cgo_catalog_links l
                LEFT JOIN products p ON p.id = l.local_product_id
                LEFT JOIN product_variants pv ON pv.id = l.local_variant_id
                ORDER BY l.cgo_product_id ASC";
        $result = $conn->query($sql);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['cgo_product_id']] = $row;
        }
        return $map;
    }
}

if (!function_exists('cgoGetCatalogLinkByProduct')) {
    function cgoGetCatalogLinkByProduct(int $cgoProductId): ?array
    {
        global $conn;
        if ($cgoProductId < 1 || !cgoEnsureTables()) return null;
        $stmt = $conn->prepare("SELECT l.*, p.name AS local_product_name, p.image AS local_product_image, p.status AS local_product_status,
                                      pv.duration AS local_variant_name, pv.status AS local_variant_status,
                                      pv.price_user AS local_price_user, pv.price_reseller AS local_price_reseller
                               FROM cgo_catalog_links l
                               LEFT JOIN products p ON p.id = l.local_product_id
                               LEFT JOIN product_variants pv ON pv.id = l.local_variant_id
                               WHERE l.cgo_product_id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $cgoProductId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('cgoPublishCatalogProduct')) {
    function cgoPublishCatalogProduct(
        int $cgoProductId,
        string $mode,
        int $localProductId,
        int $localVariantId,
        array $categories,
        string $manualImagePath,
        bool $syncDetails,
        bool $syncPrice,
        bool $apiFallbackEnabled,
        ?float $initialUserPrice = null,
        ?float $initialResellerPrice = null
    ): array {
        global $conn;
        if ($cgoProductId < 1 || !cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Invalid CHEATGAME product'];
        }
        $cgo = cgoGetProductById($cgoProductId, false);
        if (!$cgo) return ['success' => false, 'message' => 'CHEATGAME product not found'];
        if (!empty($cgo['supplier_removed_at'])
            || strtolower(trim((string) ($cgo['remote_status'] ?? ''))) === 'supplier_removed') {
            return ['success' => false, 'message' => 'CHEATGAME product was removed by the supplier'];
        }

        $mode = $mode === 'create' ? 'create' : 'existing';
        $categories = cgoNormalizeCategoryInput($categories);
        $existingCatalogLink = cgoGetCatalogLinkByProduct($cgoProductId);
        if ($categories === [] && ($mode === 'create' || !$existingCatalogLink)) {
            $categories = cgoNormalizeCategoryInput((string) ($cgo['category'] ?? ''));
        }
        $manualImagePath = trim($manualImagePath);
        $createdProduct = false;
        $createdVariant = false;
        $existingVariantSnapshot = null;
        $supplierCost = round((float) ($cgo['cost_base'] ?? 0), 2);
        if (!is_finite($supplierCost) || $supplierCost < 0 || $supplierCost > 10000000) {
            return ['success' => false, 'message' => 'Supplier cost is invalid'];
        }

        // Prepare legacy catalogue tables before opening the transaction. MySQL
        // DDL can commit implicitly, so CREATE/ALTER statements must not occur
        // while the product/link transaction is active.
        ensureProductCategoryLinksTable();
        ensureCategoriesTable();
        ensureProductPlatformsTable();
        ensureProfitColumns();
        if (!ensureProductArchiveTables()) {
            return ['success' => false, 'message' => 'Unable to prepare product archive tables'];
        }

        $conn->begin_transaction();
        try {
            if ($mode === 'create') {
                $productName = trim(cgoProductDisplayTitle($cgo));
                $description = trim((string) ($cgo['description'] ?? ''));
                $apiCatalogImage = $manualImagePath === '' ? cgoResolveCatalogImage($cgo) : '';
                $image = $manualImagePath !== '' ? $manualImagePath : $apiCatalogImage;
                $firstCategory = $categories[0] ?? '';
                if ($productName === '') throw new RuntimeException('API product name is empty');

                $insertProduct = $conn->prepare("INSERT INTO products (name, description, image, category, download_url, status) VALUES (?, ?, ?, ?, '', 'active')");
                if (!$insertProduct) throw new RuntimeException('Unable to prepare local product');
                $insertProduct->bind_param('ssss', $productName, $description, $image, $firstCategory);
                if (!$insertProduct->execute()) {
                    $insertProduct->close();
                    throw new RuntimeException('Unable to create local product');
                }
                $localProductId = (int) $conn->insert_id;
                $insertProduct->close();
                $createdProduct = true;

                if (!cgoReplaceLocalProductCategories($localProductId, $categories)) {
                    throw new RuntimeException('Unable to save local categories');
                }
                $platform = normalizeProductPlatform((string) ($cgo['platform'] ?? ''), 'both');
                if (!setProductPlatform($localProductId, $platform)) {
                    throw new RuntimeException('Unable to save local platform');
                }
            } else {
                $localProduct = getProductById($localProductId);
                if (!$localProduct || isProductAdminArchived($localProductId)) throw new RuntimeException('Local product not found or removed from the catalogue');
                $currentLocalImage = trim((string) ($localProduct['image'] ?? ''));
                $imageToSave = '';
                if ($manualImagePath !== '') {
                    $imageToSave = $manualImagePath;
                } elseif (cgoShouldReplaceLocalImage($currentLocalImage)) {
                    // Preserve images uploaded or selected manually on the main
                    // product. Supplier sync may fill a blank image or refresh an
                    // image that was previously managed by CHEATGAME.
                    $apiCatalogImage = cgoResolveCatalogImage($cgo);
                    if ($apiCatalogImage !== '') {
                        $imageToSave = $apiCatalogImage;
                    }
                }
                if ($imageToSave !== '' && !hash_equals($currentLocalImage, $imageToSave)) {
                    $updateImage = $conn->prepare('UPDATE products SET image = ?, updated_at = NOW() WHERE id = ?');
                    if (!$updateImage) throw new RuntimeException('Unable to prepare image update');
                    $updateImage->bind_param('si', $imageToSave, $localProductId);
                    if (!$updateImage->execute()) {
                        $updateImage->close();
                        throw new RuntimeException('Unable to save local image');
                    }
                    $updateImage->close();
                }
                if ($categories !== [] && !cgoReplaceLocalProductCategories($localProductId, $categories)) {
                    throw new RuntimeException('Unable to update local categories');
                }
            }

            if ($localVariantId > 0) {
                // Lock and snapshot the current selling prices. Manual selling
                // prices are preserved when price sync is disabled, except that
                // prices below the latest supplier cost are raised to cost so a
                // stale local value cannot create an immediate loss.
                $variantCheck = $conn->prepare('SELECT pv.id, pv.price_user, pv.price_reseller, pv.cost_price FROM product_variants pv LEFT JOIN product_variant_admin_archives va ON va.variant_id = pv.id WHERE pv.id = ? AND pv.product_id = ? AND va.variant_id IS NULL LIMIT 1 FOR UPDATE');
                if (!$variantCheck) throw new RuntimeException('Unable to verify local variant');
                $variantCheck->bind_param('ii', $localVariantId, $localProductId);
                if (!$variantCheck->execute()) {
                    $variantCheck->close();
                    throw new RuntimeException('Unable to verify local variant');
                }
                $variantResult = $variantCheck->get_result();
                $variantFound = $variantResult ? $variantResult->fetch_assoc() : null;
                $variantCheck->close();
                if (!$variantFound) throw new RuntimeException('Selected variant does not belong to the selected product');
                $existingVariantSnapshot = [
                    'price_user' => round((float) ($variantFound['price_user'] ?? 0), 2),
                    'price_reseller' => round((float) ($variantFound['price_reseller'] ?? 0), 2),
                    'cost_price' => round((float) ($variantFound['cost_price'] ?? 0), 2),
                ];
            } else {
                $variantName = trim((string) ($cgo['name'] ?? ''));
                if ($variantName === '') $variantName = trim((string) ($cgo['duration'] ?? ''));
                if ($variantName === '') throw new RuntimeException('API variant name is empty');

                if ($syncPrice) {
                    $priceUser = round((float) ($cgo['user_price_base'] ?? 0), 2);
                    $priceReseller = round((float) ($cgo['reseller_price_base'] ?? 0), 2);
                } else {
                    if ($initialUserPrice === null || $initialResellerPrice === null
                        || !is_finite($initialUserPrice) || !is_finite($initialResellerPrice)) {
                        throw new RuntimeException('Enter the initial customer and reseller prices when creating a new variant without API price sync');
                    }
                    $priceUser = round($initialUserPrice, 2);
                    $priceReseller = round($initialResellerPrice, 2);
                }
                if ($priceUser <= 0 || $priceReseller <= 0 || $priceUser > 10000000 || $priceReseller > 10000000) {
                    throw new RuntimeException('Initial selling prices are invalid');
                }
                if ($priceUser + 0.00001 < $supplierCost || $priceReseller + 0.00001 < $supplierCost) {
                    throw new RuntimeException('Initial selling prices cannot be lower than the current supplier cost');
                }
                $insertVariant = $conn->prepare('INSERT INTO product_variants (product_id, duration, price_user, price_reseller, cost_price) VALUES (?, ?, ?, ?, ?)');
                if (!$insertVariant) throw new RuntimeException('Unable to prepare local variant');
                $insertVariant->bind_param('isddd', $localProductId, $variantName, $priceUser, $priceReseller, $supplierCost);
                if (!$insertVariant->execute()) {
                    $insertVariant->close();
                    throw new RuntimeException('Unable to create local variant');
                }
                $localVariantId = (int) $conn->insert_id;
                $insertVariant->close();
                $createdVariant = true;
            }

            $conflict = $conn->prepare('SELECT cgo_product_id FROM cgo_catalog_links WHERE local_variant_id = ? AND cgo_product_id <> ? LIMIT 1');
            if (!$conflict) throw new RuntimeException('Unable to verify supplier mapping');
            $conflict->bind_param('ii', $localVariantId, $cgoProductId);
            $conflict->execute();
            $conflictResult = $conflict->get_result();
            $conflictingLink = $conflictResult ? $conflictResult->fetch_assoc() : null;
            $conflict->close();
            if ($conflictingLink) throw new RuntimeException('This local variant is already connected to another API product');

            if ($syncDetails) {
                $disableOther = $conn->prepare('UPDATE cgo_catalog_links SET sync_details = 0 WHERE local_product_id = ? AND cgo_product_id <> ?');
                if (!$disableOther) throw new RuntimeException('Unable to update detail sync source');
                $disableOther->bind_param('ii', $localProductId, $cgoProductId);
                if (!$disableOther->execute()) {
                    $disableOther->close();
                    throw new RuntimeException('Unable to update detail sync source');
                }
                $disableOther->close();
            }

            $syncDetailsInt = $syncDetails ? 1 : 0;
            $syncPriceInt = $syncPrice ? 1 : 0;
            $fallbackInt = $apiFallbackEnabled ? 1 : 0;
            $linkMode = $createdProduct ? 'created' : 'existing';
            $upsert = $conn->prepare("INSERT INTO cgo_catalog_links
                (cgo_product_id, local_product_id, local_variant_id, link_mode, sync_details, sync_price, api_fallback_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE local_product_id = VALUES(local_product_id), local_variant_id = VALUES(local_variant_id),
                    link_mode = VALUES(link_mode), sync_details = VALUES(sync_details), sync_price = VALUES(sync_price),
                    api_fallback_enabled = VALUES(api_fallback_enabled), updated_at = NOW()");
            if (!$upsert) throw new RuntimeException('Unable to prepare supplier mapping');
            $upsert->bind_param('iiisiii', $cgoProductId, $localProductId, $localVariantId, $linkMode, $syncDetailsInt, $syncPriceInt, $fallbackInt);
            if (!$upsert->execute()) {
                $upsert->close();
                throw new RuntimeException('Unable to save supplier mapping');
            }
            $upsert->close();

            if ($syncDetails) {
                $productName = trim(cgoProductDisplayTitle($cgo));
                $description = trim((string) ($cgo['description'] ?? ''));
                $variantName = trim((string) ($cgo['name'] ?? ''));
                if ($productName === '' || $variantName === '') throw new RuntimeException('API details are incomplete');
                $updateProduct = $conn->prepare('UPDATE products SET name = ?, description = ?, updated_at = NOW() WHERE id = ?');
                if (!$updateProduct) throw new RuntimeException('Unable to prepare detail sync');
                $updateProduct->bind_param('ssi', $productName, $description, $localProductId);
                if (!$updateProduct->execute()) {
                    $updateProduct->close();
                    throw new RuntimeException('Unable to sync local product details');
                }
                $updateProduct->close();
                $updateVariant = $conn->prepare('UPDATE product_variants SET duration = ?, updated_at = NOW() WHERE id = ? AND product_id = ?');
                if (!$updateVariant) throw new RuntimeException('Unable to prepare variant detail sync');
                $updateVariant->bind_param('sii', $variantName, $localVariantId, $localProductId);
                if (!$updateVariant->execute()) {
                    $updateVariant->close();
                    throw new RuntimeException('Unable to sync local variant details');
                }
                $updateVariant->close();
                $platform = normalizeProductPlatform((string) ($cgo['platform'] ?? ''), 'both');
                if (!setProductPlatform($localProductId, $platform)) throw new RuntimeException('Unable to sync local platform');
            }

            if ($syncPrice) {
                $priceUser = round((float) ($cgo['user_price_base'] ?? 0), 2);
                $priceReseller = round((float) ($cgo['reseller_price_base'] ?? 0), 2);
                if ($priceUser + 0.00001 < $supplierCost || $priceReseller + 0.00001 < $supplierCost) {
                    throw new RuntimeException('API selling price is below the current supplier cost; local prices were not changed');
                }
                $updatePrice = $conn->prepare('UPDATE product_variants SET price_user = ?, price_reseller = ?, cost_price = ?, updated_at = NOW() WHERE id = ? AND product_id = ?');
                if (!$updatePrice) throw new RuntimeException('Unable to prepare price sync');
                $updatePrice->bind_param('dddii', $priceUser, $priceReseller, $supplierCost, $localVariantId, $localProductId);
                if (!$updatePrice->execute()) {
                    $updatePrice->close();
                    throw new RuntimeException('Unable to sync local prices');
                }
                $updatePrice->close();
            } elseif (is_array($existingVariantSnapshot)) {
                // Selling prices remain manual when price sync is disabled, but
                // supplier cost is accounting data and must always reflect the
                // current API cost. If an old manual selling price has fallen
                // below that cost, clamp it to cost instead of leaving a product
                // that is guaranteed to sell at a loss.
                $updateCost = $conn->prepare('UPDATE product_variants
                    SET price_user = GREATEST(COALESCE(price_user, 0), ?),
                        price_reseller = GREATEST(COALESCE(price_reseller, 0), ?),
                        cost_price = ?,
                        updated_at = NOW()
                    WHERE id = ? AND product_id = ?');
                if (!$updateCost) throw new RuntimeException('Unable to sync supplier cost');
                $updateCost->bind_param('dddii', $supplierCost, $supplierCost, $supplierCost, $localVariantId, $localProductId);
                if (!$updateCost->execute()) {
                    $updateCost->close();
                    throw new RuntimeException('Unable to sync supplier cost');
                }
                $updateCost->close();
            }

            // Local inventory stores its own duration and price snapshot. Keep
            // only genuinely unsold keys aligned with the final variant values;
            // sold, assigned, or purchased rows remain untouched as history.
            $finalVariantStmt = $conn->prepare('SELECT duration, price_user, price_reseller, cost_price
                FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1');
            if (!$finalVariantStmt) throw new RuntimeException('Unable to read final local variant values');
            $finalVariantStmt->bind_param('ii', $localVariantId, $localProductId);
            if (!$finalVariantStmt->execute()) {
                $finalVariantStmt->close();
                throw new RuntimeException('Unable to read final local variant values');
            }
            $finalVariantResult = $finalVariantStmt->get_result();
            $finalVariant = $finalVariantResult ? $finalVariantResult->fetch_assoc() : null;
            $finalVariantStmt->close();
            if (!$finalVariant) throw new RuntimeException('Final local variant values were not found');

            $finalDuration = trim((string) ($finalVariant['duration'] ?? ''));
            $finalPriceUser = round((float) ($finalVariant['price_user'] ?? 0), 2);
            $finalPriceReseller = round((float) ($finalVariant['price_reseller'] ?? 0), 2);
            $finalCostPrice = round((float) ($finalVariant['cost_price'] ?? 0), 2);
            $syncAvailableKeys = $conn->prepare("UPDATE `keys`
                SET duration = ?, price_user = ?, price_reseller = ?, cost_price = ?
                WHERE product_id = ? AND variant_id = ?
                  AND status = 'available' AND assigned_to IS NULL AND purchased_by IS NULL");
            if (!$syncAvailableKeys) throw new RuntimeException('Unable to prepare unsold key price sync');
            $syncAvailableKeys->bind_param('sdddii', $finalDuration, $finalPriceUser, $finalPriceReseller, $finalCostPrice, $localProductId, $localVariantId);
            if (!$syncAvailableKeys->execute()) {
                $syncAvailableKeys->close();
                throw new RuntimeException('Unable to sync unsold key prices');
            }
            $syncAvailableKeys->close();

            $enable = $conn->prepare('UPDATE cgo_products SET enabled = 1 WHERE id = ?');
            if (!$enable) throw new RuntimeException('Unable to enable API product');
            $enable->bind_param('i', $cgoProductId);
            if (!$enable->execute()) {
                $enable->close();
                throw new RuntimeException('Unable to enable API product');
            }
            $enable->close();

            $conn->commit();
            return [
                'success' => true,
                'local_product_id' => $localProductId,
                'local_variant_id' => $localVariantId,
                'created_product' => $createdProduct,
                'created_variant' => $createdVariant,
                'message' => $createdProduct ? 'API product was added to the main catalogue' : 'API product was linked to the main catalogue',
            ];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('CGO catalogue mapping failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('cgoUnlinkCatalogProduct')) {
    function cgoUnlinkCatalogProduct(int $cgoProductId): bool
    {
        global $conn;
        if ($cgoProductId < 1 || !cgoEnsureTables()) return false;
        $stmt = $conn->prepare('DELETE FROM cgo_catalog_links WHERE cgo_product_id = ?');
        if (!$stmt) return false;
        $stmt->bind_param('i', $cgoProductId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('cgoApplyLinkedCatalogueSync')) {
    function cgoApplyLinkedCatalogueSync(int $cgoProductId): bool
    {
        $link = cgoGetCatalogLinkByProduct($cgoProductId);
        $cgo = cgoGetProductById($cgoProductId, false);
        if (!$link || !$cgo
            || !empty($cgo['supplier_removed_at'])
            || strtolower(trim((string) ($cgo['remote_status'] ?? ''))) === 'supplier_removed') {
            return false;
        }
        $categories = !empty($link['sync_details'])
            ? cgoNormalizeCategoryInput((string) ($cgo['category'] ?? ''))
            : [];
        return !empty(cgoPublishCatalogProduct(
            $cgoProductId,
            'existing',
            (int) $link['local_product_id'],
            (int) $link['local_variant_id'],
            $categories,
            '',
            !empty($link['sync_details']),
            !empty($link['sync_price']),
            !empty($link['api_fallback_enabled'])
        )['success']);
    }
}

if (!function_exists('cgoGetUnifiedStoreVariants')) {
    function cgoGetUnifiedStoreVariants(int $productId, string $role, int $userId = 0, bool $includeUnavailableApi = false): array
    {
        global $conn;
        if ($productId < 1) return [];
        $cgoReady = cgoEnsureTables();
        $supplierReady = storeBridgeEnsureSchema();
        if (!$cgoReady && !$supplierReady) return [];
        $role = $role === 'reseller' ? 'reseller' : 'user';
        $localKeys = getAvailableKeyGroups($productId);
        $groups = [];
        $localVariantIds = [];
        $localDurations = [];
        $accountPriceMap = $userId > 0 ? getResellerVariantPriceMap($userId) : [];

        foreach ($localKeys as $key) {
            $duration = trim((string) ($key['duration'] ?? 'Standard'));
            if ($duration === '') $duration = 'Standard';
            $variantId = (int) ($key['variant_id'] ?? 0);
            $groupKey = $variantId > 0 ? 'v:' . $variantId : 'd:' . strtolower($duration);
            $defaultPrice = $role === 'reseller' ? (float) ($key['price_reseller'] ?? 0) : (float) ($key['price_user'] ?? 0);
            $price = ($variantId > 0 && array_key_exists($variantId, $accountPriceMap))
                ? (float) $accountPriceMap[$variantId]
                : $defaultPrice;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'variant_id' => $variantId,
                    'duration' => $duration,
                    'price' => round(max(0.0, $price), 2),
                    'count' => 0,
                    'available' => true,
                    'source' => 'local',
                    'cgo_product_id' => 0,
                ];
            }
            $groups[$groupKey]['count'] += max(0, (int) ($key['available_count'] ?? 0));
            if ($variantId > 0) $localVariantIds[$variantId] = true;
            $localDurations[strtolower($duration)] = true;
        }

        // Merge other Sakazuki-compatible suppliers before CHEATGAME. A local
        // key still has absolute priority. If two remote providers map to the
        // same variant, supplier_connections.priority decides the first one.
        foreach (supplierBridgeGetUnifiedVariants($productId, $role, $userId, $includeUnavailableApi) as $supplierVariant) {
            $variantId = (int) ($supplierVariant['variant_id'] ?? 0);
            $duration = trim((string) ($supplierVariant['duration'] ?? 'Standard'));
            if ($duration === '') $duration = 'Standard';
            $groupKey = $variantId > 0 ? 'v:' . $variantId : 'd:' . strtolower($duration);
            if (isset($groups[$groupKey]) || isset($localVariantIds[$variantId]) || isset($localDurations[strtolower($duration)])) {
                continue;
            }
            $groups[$groupKey] = $supplierVariant;
        }

        // Storefront pages may request unavailable placeholders. This allows a
        // product whose saved stock is zero to become selectable as soon as the
        // background refresh reports stock, without forcing a full page reload.
        // The final purchase still performs an authoritative server-side check.
        if ($cgoReady && cgoLiveOrderVisibleToCurrentSession()) {
            $accountId = max(0, $userId);
            $specialPriceReady = $accountId > 0 && ensureResellerVariantPricesTable();
            $overrideFields = $specialPriceReady
                ? ", rvp.custom_price AS account_custom_price,
                     rvp.below_cost_confirmed AS account_below_cost_confirmed,
                     rvp.confirmed_cost AS account_confirmed_cost"
                : ", NULL AS account_custom_price,
                     0 AS account_below_cost_confirmed,
                     NULL AS account_confirmed_cost";
            $overrideJoin = $specialPriceReady
                ? "LEFT JOIN reseller_variant_prices rvp
                       ON rvp.variant_id = l.local_variant_id
                      AND rvp.reseller_id = " . (int) $accountId . "
                      AND rvp.status = 'active'"
                : '';
            $stmt = $conn->prepare("SELECT l.cgo_product_id, l.local_variant_id, cp.remote_stock, cp.remote_status, cp.cost_base,
                                          pv.price_user, pv.price_reseller, pv.duration
                                          {$overrideFields}
                                   FROM cgo_catalog_links l
                                   JOIN cgo_products cp ON cp.id = l.cgo_product_id
                                   JOIN product_variants pv ON pv.id = l.local_variant_id AND pv.product_id = l.local_product_id
                                   {$overrideJoin}
                                   WHERE l.local_product_id = ? AND l.api_fallback_enabled = 1
                                     AND cp.enabled = 1
                                     AND pv.status = 'active'
                                   ORDER BY pv.id ASC");
            if ($stmt) {
                $stmt->bind_param('i', $productId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $variantId = (int) $row['local_variant_id'];
                        $duration = trim((string) ($row['duration'] ?? 'Standard'));
                        if ($duration === '') $duration = 'Standard';
                        if (isset($localVariantIds[$variantId]) || isset($localDurations[strtolower($duration)])) {
                            continue;
                        }
                        $groupKey = 'v:' . $variantId;
                        $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
                        $overrideDetails = null;
                        if ($row['account_custom_price'] !== null) {
                            $price = (float) $row['account_custom_price'];
                            $overrideDetails = [
                                'custom_price' => $price,
                                'below_cost_confirmed' => (int) ($row['account_below_cost_confirmed'] ?? 0) === 1,
                                'confirmed_cost' => $row['account_confirmed_cost'] ?? null,
                            ];
                        }
                        $price = round($price, 2);
                        $cost = round((float) ($row['cost_base'] ?? 0), 2);
                        // A below-cost override is visible only when an administrator
                        // explicitly confirmed at least the current supplier cost.
                        if (!resellerVariantPriceAllowsCost($overrideDetails, $price, $cost)) {
                            continue;
                        }
                        $stock = max(0, (int) ($row['remote_stock'] ?? 0));
                        $available = $stock > 0;
                        $candidate = [
                            'variant_id' => $variantId,
                            'duration' => $duration,
                            'price' => $price,
                            'count' => $available ? min(100, $stock) : 0,
                            'available' => $available,
                            'source' => 'cgo',
                            'cgo_product_id' => (int) $row['cgo_product_id'],
                        ];

                        if (isset($groups[$groupKey])) {
                            // Store Bridge and CHEATGAME may both back the same
                            // variant. Keep whichever source is currently usable,
                            // and advertise the largest single-source capacity.
                            if ($available && empty($groups[$groupKey]['available'])) {
                                $groups[$groupKey] = $candidate;
                            } elseif ($available && !empty($groups[$groupKey]['available'])) {
                                $groups[$groupKey]['count'] = max(
                                    (int) ($groups[$groupKey]['count'] ?? 0),
                                    (int) $candidate['count']
                                );
                            }
                            continue;
                        }
                        if (!$available && !$includeUnavailableApi) continue;
                        $groups[$groupKey] = $candidate;
                    }
                }
                $stmt->close();
            }
        }

        uasort($groups, static function (array $a, array $b): int {
            $priceCompare = ((float) $a['price']) <=> ((float) $b['price']);
            if ($priceCompare !== 0) return $priceCompare;
            return strnatcasecmp((string) $a['duration'], (string) $b['duration']);
        });
        return array_values($groups);
    }
}


if (!function_exists('cgoGetUnifiedStoreVariantsForProducts')) {
    /**
     * Bulk equivalent of cgoGetUnifiedStoreVariants() for one storefront page.
     * Local keys, Store Bridge variants and CGO variants are each loaded once.
     */
    function cgoGetUnifiedStoreVariantsForProducts(
        array $productIds,
        string $role,
        int $userId = 0,
        bool $includeUnavailableApi = false
    ): array {
        global $conn;
        $ids = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0) $ids[$productId] = $productId;
            if (count($ids) >= 60) break;
        }
        if ($ids === []) return [];

        $cgoReady = cgoEnsureTables();
        $supplierReady = storeBridgeEnsureSchema();
        $role = $role === 'reseller' ? 'reseller' : 'user';
        $accountPriceMap = $userId > 0 ? getResellerVariantPriceMap($userId) : [];
        $localMap = getAvailableKeyGroupsForProducts(array_values($ids));
        $supplierMap = $supplierReady
            ? supplierBridgeGetUnifiedVariantsForProducts(array_values($ids), $role, $userId, $includeUnavailableApi)
            : [];

        $groupsByProduct = [];
        $localVariantIdsByProduct = [];
        $localDurationsByProduct = [];
        foreach ($ids as $productId) {
            $groupsByProduct[$productId] = [];
            $localVariantIdsByProduct[$productId] = [];
            $localDurationsByProduct[$productId] = [];
            foreach ($localMap[$productId] ?? [] as $key) {
                $duration = trim((string) ($key['duration'] ?? 'Standard'));
                if ($duration === '') $duration = 'Standard';
                $variantId = (int) ($key['variant_id'] ?? 0);
                $groupKey = $variantId > 0 ? 'v:' . $variantId : 'd:' . strtolower($duration);
                $defaultPrice = $role === 'reseller'
                    ? (float) ($key['price_reseller'] ?? 0)
                    : (float) ($key['price_user'] ?? 0);
                $price = ($variantId > 0 && array_key_exists($variantId, $accountPriceMap))
                    ? (float) $accountPriceMap[$variantId]
                    : $defaultPrice;
                if (!isset($groupsByProduct[$productId][$groupKey])) {
                    $groupsByProduct[$productId][$groupKey] = [
                        'variant_id' => $variantId,
                        'duration' => $duration,
                        'price' => round(max(0.0, $price), 2),
                        'count' => 0,
                        'available' => true,
                        'source' => 'local',
                        'cgo_product_id' => 0,
                    ];
                }
                $groupsByProduct[$productId][$groupKey]['count'] += max(0, (int) ($key['available_count'] ?? 0));
                if ($variantId > 0) $localVariantIdsByProduct[$productId][$variantId] = true;
                $localDurationsByProduct[$productId][strtolower($duration)] = true;
            }

            foreach ($supplierMap[$productId] ?? [] as $supplierVariant) {
                $variantId = (int) ($supplierVariant['variant_id'] ?? 0);
                $duration = trim((string) ($supplierVariant['duration'] ?? 'Standard'));
                if ($duration === '') $duration = 'Standard';
                $groupKey = $variantId > 0 ? 'v:' . $variantId : 'd:' . strtolower($duration);
                if (isset($groupsByProduct[$productId][$groupKey])
                    || ($variantId > 0 && isset($localVariantIdsByProduct[$productId][$variantId]))
                    || isset($localDurationsByProduct[$productId][strtolower($duration)])) {
                    continue;
                }
                $groupsByProduct[$productId][$groupKey] = $supplierVariant;
            }
        }

        if ($cgoReady && cgoLiveOrderVisibleToCurrentSession()) {
            $accountId = max(0, $userId);
            $specialPriceReady = $accountId > 0 && ensureResellerVariantPricesTable();
            $overrideFields = $specialPriceReady
                ? ", rvp.custom_price AS account_custom_price,
                     rvp.below_cost_confirmed AS account_below_cost_confirmed,
                     rvp.confirmed_cost AS account_confirmed_cost"
                : ", NULL AS account_custom_price,
                     0 AS account_below_cost_confirmed,
                     NULL AS account_confirmed_cost";
            $overrideJoin = $specialPriceReady
                ? "LEFT JOIN reseller_variant_prices rvp
                       ON rvp.variant_id = l.local_variant_id
                      AND rvp.reseller_id = " . (int) $accountId . "
                      AND rvp.status = 'active'"
                : '';
            $list = implode(',', array_values($ids));
            $sql = "SELECT l.local_product_id, l.cgo_product_id, l.local_variant_id,
                           cp.remote_stock, cp.remote_status, cp.cost_base,
                           pv.price_user, pv.price_reseller, pv.duration
                           {$overrideFields}
                    FROM cgo_catalog_links l
                    JOIN cgo_products cp ON cp.id = l.cgo_product_id
                    JOIN product_variants pv ON pv.id = l.local_variant_id AND pv.product_id = l.local_product_id
                    {$overrideJoin}
                    WHERE l.local_product_id IN ({$list}) AND l.api_fallback_enabled = 1
                      AND cp.enabled = 1 AND pv.status = 'active'
                    ORDER BY l.local_product_id ASC, pv.id ASC";
            $result = $conn->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $productId = (int) ($row['local_product_id'] ?? 0);
                    $variantId = (int) ($row['local_variant_id'] ?? 0);
                    if ($productId < 1 || $variantId < 1 || !isset($groupsByProduct[$productId])) continue;
                    $duration = trim((string) ($row['duration'] ?? 'Standard'));
                    if ($duration === '') $duration = 'Standard';
                    if (isset($localVariantIdsByProduct[$productId][$variantId])
                        || isset($localDurationsByProduct[$productId][strtolower($duration)])) {
                        continue;
                    }

                    $groupKey = 'v:' . $variantId;
                    $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
                    $overrideDetails = null;
                    if ($row['account_custom_price'] !== null) {
                        $price = (float) $row['account_custom_price'];
                        $overrideDetails = [
                            'custom_price' => $price,
                            'below_cost_confirmed' => (int) ($row['account_below_cost_confirmed'] ?? 0) === 1,
                            'confirmed_cost' => $row['account_confirmed_cost'] ?? null,
                        ];
                    }
                    $price = round($price, 2);
                    $cost = round((float) ($row['cost_base'] ?? 0), 2);
                    if (!resellerVariantPriceAllowsCost($overrideDetails, $price, $cost)) continue;
                    $stock = max(0, (int) ($row['remote_stock'] ?? 0));
                    $available = $stock > 0;
                    $candidate = [
                        'variant_id' => $variantId,
                        'duration' => $duration,
                        'price' => $price,
                        'count' => $available ? min(100, $stock) : 0,
                        'available' => $available,
                        'source' => 'cgo',
                        'cgo_product_id' => (int) $row['cgo_product_id'],
                    ];
                    if (isset($groupsByProduct[$productId][$groupKey])) {
                        if ($available && empty($groupsByProduct[$productId][$groupKey]['available'])) {
                            $groupsByProduct[$productId][$groupKey] = $candidate;
                        } elseif ($available && !empty($groupsByProduct[$productId][$groupKey]['available'])) {
                            $groupsByProduct[$productId][$groupKey]['count'] = max(
                                (int) ($groupsByProduct[$productId][$groupKey]['count'] ?? 0),
                                (int) $candidate['count']
                            );
                        }
                        continue;
                    }
                    if (!$available && !$includeUnavailableApi) continue;
                    $groupsByProduct[$productId][$groupKey] = $candidate;
                }
                $result->free();
            }
        }

        $map = [];
        foreach ($groupsByProduct as $productId => $groups) {
            uasort($groups, static function (array $a, array $b): int {
                $priceCompare = ((float) $a['price']) <=> ((float) $b['price']);
                if ($priceCompare !== 0) return $priceCompare;
                return strnatcasecmp((string) $a['duration'], (string) $b['duration']);
            });
            $map[$productId] = array_values($groups);
        }
        return $map;
    }
}

if (!function_exists('cgoStorefrontPurchaseErrorMessage')) {
    /**
     * Convert checkout failures into customer-safe messages. Internal provider
     * names, lock design, credentials, endpoints, and raw API errors must never
     * be rendered in user or reseller pages.
     */
    function cgoStorefrontPurchaseErrorMessage(array $purchase): string
    {
        $isThai = function_exists('getAppLang') && getAppLang() === 'th';
        $code = strtolower(trim((string) ($purchase['code'] ?? '')));
        $source = strtolower(trim((string) ($purchase['source'] ?? '')));

        // A manual-review order may have committed upstream. Its balance stays
        // reserved until an administrator verifies the supplier side, so never
        // tell the customer that an automatic history lookup will resolve it.
        if (!empty($purchase['manual_review'])) {
            $orderId = max(0, (int) ($purchase['order_id'] ?? 0));
            $suffix = $orderId > 0 ? ' #' . $orderId : '';
            return $isThai
                ? 'คำสั่งซื้อ' . $suffix . ' ต้องตรวจสอบกับผู้ให้บริการโดยแอดมิน ยอดถูกพักไว้เพื่อป้องกันการหักซ้ำ กรุณาอย่ากดซื้อรายการเดิมซ้ำจนกว่าสถานะจะถูกยืนยัน'
                : 'Order' . $suffix . ' requires administrator verification with the supplier. Your balance remains reserved to prevent a duplicate charge; do not retry the same item until it is resolved.';
        }

        // A pending remote order is NOT a normal checkout failure. Its balance
        // was already reserved, so explicitly stop the customer from retrying.
        if (!empty($purchase['pending']) || !empty($purchase['processing']) || in_array($code, ['existing_pending_order', 'existing_pending_supplier_order'], true)) {
            $orderId = max(0, (int) ($purchase['order_id'] ?? 0));
            $suffix = $orderId > 0 ? ' #' . $orderId : '';
            return $isThai
                ? 'คำสั่งซื้อ' . $suffix . ' กำลังตรวจสอบอัตโนมัติ ระบบจะตรวจรายการต้นทางซ้ำ และถ้าต้นทางยืนยันว่าไม่พบออเดอร์จะคืนยอดให้อัตโนมัติ โดยปกติจะรู้ผลภายในประมาณ 1 นาที กรุณาอย่ากดซ้ำระหว่างตรวจสอบ'
                : 'Order' . $suffix . ' is being checked automatically. The system will re-check the upstream order and refund automatically if the supplier confirms that no order exists. This normally resolves within about one minute; do not retry while the check is running.';
        }
        if (!empty($purchase['balance_refunded'])) {
            if ($code === 'supplier_unreachable_refunded') {
                return $isThai
                    ? 'ผู้ให้บริการไม่พร้อมรับคำสั่งซื้อในขณะนี้ ระบบคืนยอดให้เรียบร้อยแล้ว กรุณารอให้บริการกลับมาปกติก่อนสั่งใหม่'
                    : 'The supplier could not receive the order. Your balance was refunded automatically. Please wait until the service is available before ordering again.';
            }
            return $isThai
                ? 'คำสั่งซื้อไม่สำเร็จ ระบบคืนยอดให้เรียบร้อยแล้ว กรุณาตรวจสอบยอดก่อนทำรายการใหม่'
                : 'The order was not completed and your balance was refunded automatically. Check your balance before placing a new order.';
        }
        if ($code === 'supplier_out_of_stock') {
            return $isThai ? 'สินค้าหมดชั่วคราว กรุณารอเติมสต็อก' : 'This item is temporarily sold out.';
        }
        if ($code === 'supplier_stock_insufficient') {
            $available = max(0, (int) ($purchase['available_stock'] ?? 0));
            return $isThai
                ? 'สต็อกล่าสุดไม่พอตามจำนวนที่เลือก กรุณาเลือกใหม่ (คงเหลือ ' . $available . ')'
                : 'The latest stock is lower than the selected quantity (available: ' . $available . ').';
        }
        if ($code === 'supplier_product_busy') {
            return $isThai
                ? 'มีคำสั่งซื้ออื่นกำลังประมวลผลผ่านผู้ให้บริการ ระบบยังไม่หักยอด กรุณาลองใหม่อีกครั้ง'
                : 'Another supplier order is being processed. No balance was charged. Please try again.';
        }
        if (in_array($code, ['supplier_lock_unavailable', 'supplier_inventory_unavailable', 'remote_ordering_unavailable'], true)) {
            return $isThai
                ? 'ยังไม่สามารถตรวจสอบสต็อกล่าสุดได้ ระบบยังไม่หักยอด กรุณาลองใหม่อีกครั้ง'
                : 'The latest stock could not be verified. No balance was charged. Please try again.';
        }
        if (in_array($code, ['product_unavailable', 'mapping_unavailable'], true)) {
            return $isThai ? 'สินค้านี้ยังไม่พร้อมจำหน่าย' : 'This item is not available.';
        }

        // Local-stock errors are safe and useful to show. Remote failures use a
        // generic fallback even if an upstream message accidentally reaches here.
        if (!in_array($source, ['api', 'cgo', 'supplier', 'store_api'], true)) {
            $message = trim((string) ($purchase['message'] ?? ''));
            if ($message !== '' && stripos($message, 'CHEATGAME') === false && stripos($message, 'supplier') === false && stripos($message, 'api') === false) {
                return $message;
            }
        }
        return $isThai
            ? 'ไม่สามารถทำรายการได้ ระบบยังไม่หักยอด กรุณาลองใหม่อีกครั้ง'
            : 'The purchase could not be completed. No balance was charged. Please try again.';
    }
}

if (!function_exists('cgoPurchaseUnifiedVariant')) {
    function cgoPurchaseUnifiedVariant(int $productId, string $duration, int $quantity, int $userId, int $variantId = 0): array
    {
        global $conn;
        $duration = trim($duration);
        if ($productId < 1 || $userId < 1 || $variantId < 0 || $quantity < 1 || $quantity > 100 || $duration === '' || (!cgoEnsureTables() && !storeBridgeEnsureSchema())) {
            return ['success' => false, 'message' => 'Invalid purchase request'];
        }

        // Never trust the posted duration when a concrete variant was selected.
        if ($variantId > 0) {
            $variantStmt = $conn->prepare("SELECT duration, status FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1");
            if (!$variantStmt) return ['success' => false, 'message' => 'Unable to verify the selected variant'];
            $variantStmt->bind_param('ii', $variantId, $productId);
            if (!$variantStmt->execute()) {
                $variantStmt->close();
                return ['success' => false, 'message' => 'Unable to verify the selected variant'];
            }
            $variantResult = $variantStmt->get_result();
            $variant = $variantResult ? $variantResult->fetch_assoc() : null;
            $variantStmt->close();
            if (!$variant || (string) ($variant['status'] ?? '') !== 'active') {
                return ['success' => false, 'message' => 'The selected variant is not available'];
            }
            $canonicalDuration = trim((string) ($variant['duration'] ?? ''));
            if ($canonicalDuration === '') {
                return ['success' => false, 'message' => 'The selected variant has no valid duration'];
            }
            $duration = $canonicalDuration;
        }

        // Block retries across *all* stock sources before even considering
        // newly replenished local stock. An unresolved remote order may still
        // deliver later, so switching to local/another API would create a
        // duplicate purchase despite each individual provider being idempotent.
        if ($variantId > 0) {
            $blockingCgoOrder = cgoFindBlockingPendingOrderForVariant($userId, $variantId);
            if ($blockingCgoOrder) {
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'existing_pending_order',
                    'source' => 'cgo',
                    'order_id' => (int) ($blockingCgoOrder['id'] ?? 0),
                    'total' => (float) ($blockingCgoOrder['total_price_base'] ?? 0),
                    'message' => 'An earlier API order for this variant is still unresolved. Do not submit another order.',
                ];
            }
            $blockingSupplierOrder = supplierBridgeFindBlockingPendingOrder($userId, $variantId);
            if ($blockingSupplierOrder) {
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'existing_pending_supplier_order',
                    'source' => 'supplier',
                    'order_id' => (int) ($blockingSupplierOrder['id'] ?? 0),
                    'total' => (float) ($blockingSupplierOrder['total_price_base'] ?? 0),
                    'message' => 'An earlier API order for this variant is still unresolved. Do not submit another order.',
                ];
            }
        }

        $variantFilter = $variantId > 0 ? ' AND (k.variant_id = ? OR k.variant_id IS NULL)' : '';
        $sql = "SELECT COUNT(*) AS total FROM `keys` k
                JOIN products p ON p.id = k.product_id AND p.status = 'active'
                LEFT JOIN product_variants pv ON pv.id = k.variant_id
                WHERE k.product_id = ? AND k.duration = ? AND k.status = 'available'"
                . $variantFilter . " AND (k.variant_id IS NULL OR pv.status = 'active')";
        $countStmt = $conn->prepare($sql);
        if (!$countStmt) return ['success' => false, 'message' => 'Unable to inspect local stock'];
        if ($variantId > 0) $countStmt->bind_param('isi', $productId, $duration, $variantId);
        else $countStmt->bind_param('is', $productId, $duration);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult ? $countResult->fetch_assoc() : null;
        $countStmt->close();
        $localCount = (int) ($countRow['total'] ?? 0);

        // Local stock always wins and is transactional inside this database.
        if ($localCount > 0) {
            if ($quantity > $localCount) {
                return ['success' => false, 'message' => 'Only ' . $localCount . ' local key(s) remain. Buy local stock first; API fallback starts after local stock reaches zero.'];
            }
            $result = purchaseKeysAtomic($productId, $duration, $quantity, $userId, $variantId);
            $result['source'] = 'local';
            return $result;
        }

        $lastSafeSupplierFailure = null;
        $supplierAttempts = 0;
        if ($variantId > 0 && storeBridgeEnsureSchema()) {
            $supplierCandidates = supplierBridgeGetPurchaseCandidates($productId, $variantId, $quantity, $userId);
            // When a real backup source exists, one authoritative inventory GET
            // per source is faster and safer than retrying a sick primary twice.
            // No order has been posted yet, so moving to the next source here
            // cannot create a duplicate supplier order.
            // Customer checkout is latency-bounded. A transient inventory
            // failure is safe to fail over before any supplier order is posted,
            // so do not spend another network timeout retrying the same source.
            $retrySupplierInventoryTransient = false;
            foreach ($supplierCandidates as $supplierCandidate) {
                $supplierProductId = (int) ($supplierCandidate['id'] ?? 0);
                if ($supplierProductId < 1) continue;
                $supplierAttempts++;
                $purchase = supplierBridgePurchase(
                    $supplierProductId,
                    $userId,
                    $quantity,
                    $productId,
                    $variantId,
                    false,
                    $retrySupplierInventoryTransient
                );
                $purchase['source'] = 'supplier';
                $purchase['supplier_attempts'] = $supplierAttempts;

                if (!empty($purchase['success'])) return $purchase;
                // Once an order might exist upstream, never switch suppliers.
                if (!empty($purchase['pending']) || !empty($purchase['processing']) || empty($purchase['safe_to_failover'])) {
                    return $purchase;
                }
                $lastSafeSupplierFailure = $purchase;
            }
        }

        if (!cgoLiveOrderVisibleToCurrentSession()) {
            if (is_array($lastSafeSupplierFailure)) return $lastSafeSupplierFailure;
            return [
                'success' => false,
                'code' => 'remote_ordering_unavailable',
                'source' => 'cgo',
                'message' => cgoLiveOrderDisabledMessage(),
            ];
        }

        // CHEATGAME is the next independent source after all Store Bridge
        // candidates failed in a way that proved no ambiguous order remained.
        $stmt = $conn->prepare("SELECT l.cgo_product_id
                               FROM cgo_catalog_links l
                               JOIN cgo_products cp ON cp.id = l.cgo_product_id
                               JOIN product_variants pv ON pv.id = l.local_variant_id AND pv.product_id = l.local_product_id
                               WHERE l.local_product_id = ? AND l.local_variant_id = ? AND l.api_fallback_enabled = 1
                                 AND cp.enabled = 1
                                 AND pv.status = 'active'
                               LIMIT 1");
        if (!$stmt) {
            if (is_array($lastSafeSupplierFailure)) return $lastSafeSupplierFailure;
            return ['success' => false, 'message' => 'Unable to inspect API fallback'];
        }
        $stmt->bind_param('ii', $productId, $variantId);
        $stmt->execute();
        $result = $stmt->get_result();
        $link = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$link) {
            if (is_array($lastSafeSupplierFailure)) {
                $lastSafeSupplierFailure['reload_storefront'] = true;
                return $lastSafeSupplierFailure;
            }
            return ['success' => false, 'message' => 'No local key or active API fallback is available'];
        }

        $purchase = cgoPurchaseProduct((int) $link['cgo_product_id'], $userId, $quantity, $productId, $variantId);
        $purchase['source'] = 'cgo';
        if ($supplierAttempts > 0) $purchase['supplier_attempts'] = $supplierAttempts;
        return $purchase;
    }
}

if (!function_exists('cgoDeliveredKeyStorageIsPageSafe')) {
    /**
     * A bounded key-row read is only equivalent to the legacy reader when every
     * successful order has exactly the stored key-row count it declares. Older
     * account deliveries can be represented as several label/value rows, while
     * older recovery cases can have fewer stored rows than quantity; those cases
     * must keep the complete legacy normalization path.
     */
    function cgoDeliveredKeyStorageIsPageSafe(int $userId): bool
    {
        global $conn;
        static $cache = [];
        if ($userId < 1 || !cgoEnsureTables()) return false;
        if (array_key_exists($userId, $cache)) return $cache[$userId];
        $statusSql = function_exists('commerceSuccessfulStatusSql')
            ? commerceSuccessfulStatusSql('o')
            : "LOWER(TRIM(COALESCE(o.status, ''))) IN ('success','completed')";
        $stmt = $conn->prepare(
            "SELECT o.id
             FROM cgo_orders o
             LEFT JOIN cgo_order_keys ok ON ok.order_id=o.id
             WHERE o.user_id=? AND {$statusSql}
             GROUP BY o.id,o.quantity
             HAVING COUNT(ok.id) <> GREATEST(1,COALESCE(o.quantity,1))
                 OR SUM(CASE WHEN TRIM(COALESCE(ok.key_code,'')) <> '' THEN 1 ELSE 0 END) <> GREATEST(1,COALESCE(o.quantity,1))
             LIMIT 1"
        );
        if (!$stmt) return $cache[$userId] = false;
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) { $stmt->close(); return $cache[$userId] = false; }
        $result = $stmt->get_result();
        $mismatch = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($mismatch !== null) return $cache[$userId] = false;

        // Account products may normalize response_json into one structured
        // account payload even when the stored row count already equals quantity.
        // Bounded raw-row pagination must not bypass that legacy normalization.
        $productStmt = $conn->prepare(
            "SELECT DISTINCT cp.id,cp.name,cp.brand,cp.category,cp.platform,cp.description
             FROM cgo_orders o
             JOIN cgo_products cp ON cp.id=o.cgo_product_id
             WHERE o.user_id=? AND {$statusSql}"
        );
        if (!$productStmt) return $cache[$userId] = false;
        $productStmt->bind_param('i', $userId);
        if (!$productStmt->execute()) { $productStmt->close(); return $cache[$userId] = false; }
        $productResult = $productStmt->get_result();
        while ($product = $productResult ? $productResult->fetch_assoc() : null) {
            if (!$product) break;
            if (function_exists('cgoProductLooksLikeAccount') && cgoProductLooksLikeAccount($product)) {
                $productStmt->close();
                return $cache[$userId] = false;
            }
        }
        $productStmt->close();
        return $cache[$userId] = true;
    }
}

if (!function_exists('cgoGetDeliveredKeysForUser')) {
    function cgoGetDeliveredKeysForUser(int $userId, ?int $limit = null, int $offset = 0, string $search = ''): array
    {
        global $conn;
        if ($userId < 1 || !cgoEnsureTables()) return [];
        $statusSql = function_exists('commerceSuccessfulStatusSql')
            ? commerceSuccessfulStatusSql('o')
            : "LOWER(TRIM(COALESCE(o.status, ''))) IN ('success','completed')";
        $offset = max(0, $offset);
        $search = substr(trim($search), 0, 180);
        $boundedRead = $limit !== null;
        if ($boundedRead && !cgoDeliveredKeyStorageIsPageSafe($userId)) {
            // Preserve legacy account/recovery normalization when storage rows
            // are not one-to-one with delivered items. This is intentionally a
            // slower fallback rather than returning a subtly wrong page.
            $legacyRows = cgoGetDeliveredKeysForUser($userId);
            if ($search !== '') {
                $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $legacyRows = array_values(array_filter($legacyRows, static function (array $row) use ($needle): bool {
                    $haystack = trim((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['key_code'] ?? '') . ' ' . (string) ($row['duration'] ?? ''));
                    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                    return strpos($haystack, $needle) !== false;
                }));
            }
            $safeLimit = max(1, min(1000, (int) $limit));
            return array_slice($legacyRows, $offset, $safeLimit);
        }
        $where = ["o.user_id = ?", $statusSql];
        $types = 'i';
        $params = [$userId];
        if ($search !== '') {
            $where[] = "(LOCATE(?, ok.key_code) > 0 OR LOCATE(?, COALESCE(NULLIF(lp.name,''),CASE WHEN COALESCE(NULLIF(cp.brand,''),'') <> '' THEN CONCAT(cp.brand, ' - ', cp.name) ELSE cp.name END,'')) > 0 OR LOCATE(?, COALESCE(NULLIF(pv.duration,''),NULLIF(cp.duration,''),'')) > 0)";
            $types .= 'sss';
            array_push($params, $search, $search, $search);
        }
        $sql = "SELECT ok.id AS order_key_id, ok.key_code, o.id AS order_id,
                       COALESCE(o.transaction_id,0) AS transaction_id,
                       COALESCE(o.quantity,1) AS quantity,
                       o.response_json,
                       o.unit_price_base, o.local_product_id,
                       COALESCE(o.completed_at, o.updated_at, o.created_at) AS sold_at,
                       COALESCE(NULLIF(lp.name,''),
                           CASE WHEN COALESCE(NULLIF(cp.brand, ''), '') <> ''
                                THEN CONCAT(cp.brand, ' - ', cp.name)
                                ELSE cp.name END,
                           '') AS product_name,
                       COALESCE(NULLIF(pv.duration,''), NULLIF(cp.duration,''), '') AS duration,
                       COALESCE(cp.name,'') AS remote_name,
                       COALESCE(cp.brand,'') AS remote_brand,
                       COALESCE(cp.category,'') AS remote_category,
                       COALESCE(cp.platform,'') AS remote_platform,
                       COALESCE(cp.description,'') AS remote_description
                FROM cgo_order_keys ok
                JOIN cgo_orders o ON o.id = ok.order_id
                JOIN cgo_products cp ON cp.id = o.cgo_product_id
                LEFT JOIN products lp ON lp.id = o.local_product_id
                LEFT JOIN product_variants pv ON pv.id = o.local_variant_id
                WHERE " . implode(' AND ', $where);
        if ($limit !== null) {
            // Match cgoGetUnifiedUserKeys()'s PHP strcmp() tie-breaker for the
            // bounded source candidate set.
            $sql .= " ORDER BY sold_at DESC, CONVERT(CONCAT('cgo-',ok.id) USING utf8mb4) COLLATE utf8mb4_bin DESC";
            $limit = max(1, min(1000, $limit));
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        } else {
            $sql .= ' ORDER BY sold_at DESC, o.id DESC, ok.id ASC';
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $bind = [$types];
        foreach ($params as $i => $_value) $bind[] = &$params[$i];
        if (!call_user_func_array([$stmt, 'bind_param'], $bind) || !$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();

        if ($boundedRead) {
            $rows = [];
            $requiresLegacyNormalization = false;
            while ($row = $result ? $result->fetch_assoc() : null) {
                if (!$row) break;
                $value = trim((string) ($row['key_code'] ?? ''));
                if ($value === '') continue;
                if (cgoDeliveryValueIsAccount($value)) {
                    $requiresLegacyNormalization = true;
                    break;
                }
                $rows[] = [
                    'id' => 'cgo-' . (int) ($row['order_key_id'] ?? 0),
                    'key_code' => $value,
                    'delivery_type' => 'key',
                    'delivery_payload' => null,
                    'product_name' => (string) ($row['product_name'] ?? ''),
                    'duration' => (string) ($row['duration'] ?? ''),
                    'price_user' => (float) ($row['unit_price_base'] ?? 0),
                    'price_reseller' => (float) ($row['unit_price_base'] ?? 0),
                    'purchase_price' => (float) ($row['unit_price_base'] ?? 0),
                    'sold_at' => (string) ($row['sold_at'] ?? ''),
                    'source' => 'cgo',
                    'order_id' => (int) ($row['order_id'] ?? 0),
                    'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                    'product_id' => (int) ($row['local_product_id'] ?? 0),
                ];
            }
            $stmt->close();
            if ($requiresLegacyNormalization) {
                $legacyRows = cgoGetDeliveredKeysForUser($userId);
                if ($search !== '') {
                    $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                    $legacyRows = array_values(array_filter($legacyRows, static function (array $legacyRow) use ($needle): bool {
                        $haystack = trim((string) ($legacyRow['product_name'] ?? '') . ' ' . (string) ($legacyRow['key_code'] ?? '') . ' ' . (string) ($legacyRow['duration'] ?? ''));
                        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                        return strpos($haystack, $needle) !== false;
                    }));
                }
                $safeLimit = max(1, min(1000, (int) $limit));
                return array_slice($legacyRows, $offset, $safeLimit);
            }
            return $rows;
        }

        $orders = [];
        while ($row = $result ? $result->fetch_assoc() : null) {
            if (!$row) break;
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId < 1) continue;
            if (!isset($orders[$orderId])) {
                $orders[$orderId] = [
                    'meta' => $row,
                    'stored' => [],
                ];
            }
            $value = trim((string) ($row['key_code'] ?? ''));
            if ($value !== '') {
                $orders[$orderId]['stored'][] = [
                    'id' => (int) ($row['order_key_id'] ?? 0),
                    'type' => cgoDeliveryValueIsAccount($value) ? 'account' : 'key',
                    'value' => $value,
                    'payload' => null,
                ];
            }
        }
        $stmt->close();

        $rows = [];
        foreach ($orders as $orderId => $bundle) {
            $meta = $bundle['meta'];
            $stored = array_values($bundle['stored']);
            $expected = max(1, (int) ($meta['quantity'] ?? 1));
            $productContext = [
                'name' => (string) ($meta['remote_name'] ?? ''),
                'brand' => (string) ($meta['remote_brand'] ?? ''),
                'category' => (string) ($meta['remote_category'] ?? ''),
                'platform' => (string) ($meta['remote_platform'] ?? ''),
                'description' => (string) ($meta['remote_description'] ?? ''),
            ];

            $items = $stored;
            $responseJson = trim((string) ($meta['response_json'] ?? ''));
            $shouldNormalize = count($stored) !== $expected || cgoProductLooksLikeAccount($productContext);
            if (!$shouldNormalize) {
                foreach ($stored as $storedItem) {
                    if (($storedItem['type'] ?? '') === 'account') {
                        $shouldNormalize = true;
                        break;
                    }
                }
            }

            if ($responseJson !== '' && $shouldNormalize) {
                $decoded = json_decode($responseJson, true);
                if (is_array($decoded)) {
                    $recovered = cgoExtractDeliveryItems($decoded);
                    $accountItems = array_values(array_filter(
                        $recovered,
                        static fn(array $item): bool => (string) ($item['type'] ?? '') === 'account'
                    ));
                    if (count($recovered) === $expected && ($accountItems !== [] || count($stored) !== $expected)) {
                        $items = $recovered;
                    }
                }
            }

            foreach ($items as $index => $item) {
                $value = trim((string) ($item['value'] ?? ''));
                if ($value === '') continue;
                $storedId = (int) ($stored[$index]['id'] ?? 0);
                $rows[] = [
                    'id' => 'cgo-' . ($storedId > 0 ? $storedId : ((int) $orderId . '-' . ($index + 1))),
                    'key_code' => $value,
                    'delivery_type' => (string) ($item['type'] ?? (cgoDeliveryValueIsAccount($value) ? 'account' : 'key')),
                    'delivery_payload' => isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : null,
                    'product_name' => (string) ($meta['product_name'] ?? ''),
                    'duration' => (string) ($meta['duration'] ?? ''),
                    'price_user' => (float) ($meta['unit_price_base'] ?? 0),
                    'price_reseller' => (float) ($meta['unit_price_base'] ?? 0),
                    'purchase_price' => (float) ($meta['unit_price_base'] ?? 0),
                    'sold_at' => (string) ($meta['sold_at'] ?? ''),
                    'source' => 'cgo',
                    'order_id' => (int) $orderId,
                    'transaction_id' => (int) ($meta['transaction_id'] ?? 0),
                    'product_id' => (int) ($meta['local_product_id'] ?? 0),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['sold_at'] ?? ''), (string) ($a['sold_at'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });
        return $rows;
    }
}


if (!function_exists('cgoCountDeliveredKeysForUser')) {
    function cgoCountDeliveredKeysForUser(int $userId, string $search = ''): int
    {
        global $conn;
        if ($userId < 1 || !cgoEnsureTables()) return 0;
        $statusSql = function_exists('commerceSuccessfulStatusSql')
            ? commerceSuccessfulStatusSql('o')
            : "LOWER(TRIM(COALESCE(o.status, ''))) IN ('success','completed')";
        $search = substr(trim($search), 0, 180);
        if (!cgoDeliveredKeyStorageIsPageSafe($userId)) {
            $legacyRows = cgoGetDeliveredKeysForUser($userId);
            if ($search === '') return count($legacyRows);
            $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
            $matched = array_filter($legacyRows, static function (array $row) use ($needle): bool {
                $haystack = trim((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['key_code'] ?? '') . ' ' . (string) ($row['duration'] ?? ''));
                $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                return strpos($haystack, $needle) !== false;
            });
            return count($matched);
        }
        $sql = "SELECT COUNT(*) AS total
                FROM cgo_order_keys ok
                JOIN cgo_orders o ON o.id=ok.order_id
                JOIN cgo_products cp ON cp.id=o.cgo_product_id
                LEFT JOIN products lp ON lp.id=o.local_product_id
                LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                WHERE o.user_id=? AND {$statusSql}";
        $types = 'i';
        $params = [$userId];
        if ($search !== '') {
            $sql .= " AND (LOCATE(?, ok.key_code) > 0 OR LOCATE(?, COALESCE(NULLIF(lp.name,''),CASE WHEN COALESCE(NULLIF(cp.brand,''),'') <> '' THEN CONCAT(cp.brand, ' - ', cp.name) ELSE cp.name END,'')) > 0 OR LOCATE(?, COALESCE(NULLIF(pv.duration,''),NULLIF(cp.duration,''),'')) > 0)";
            $types .= 'sss';
            array_push($params, $search, $search, $search);
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $bind = [$types];
        foreach ($params as $i => $_value) $bind[] = &$params[$i];
        if (!call_user_func_array([$stmt, 'bind_param'], $bind) || !$stmt->execute()) { $stmt->close(); return 0; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return max(0, (int) ($row['total'] ?? 0));
    }
}

if (!function_exists('cgoFindRecursiveValue')) {
    function cgoFindRecursiveValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '') return $value;
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = cgoFindRecursiveValue($value, $keys);
                if ($found !== null && $found !== '') return $found;
            }
        }
        return null;
    }
}

if (!function_exists('cgoExtractSupplierOrderId')) {
    function cgoExtractSupplierOrderId(array $data): ?string
    {
        $value = cgoFindRecursiveValue($data, ['order_id', 'reseller_order_id', 'supplier_order_id']);
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, 190);
    }
}

if (!function_exists('cgoNormalizeDeliveryFieldName')) {
    function cgoNormalizeDeliveryFieldName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower((string) $name);
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        $name = trim((string) $name, '_');

        $aliases = [
            'account_id' => 'account',
            'account_name' => 'account',
            'login' => 'account',
            'login_id' => 'account',
            'username' => 'account',
            'user_name' => 'account',
            'userid' => 'account',
            'user_id' => 'account',
            'account_password' => 'password_account',
            'password' => 'password_account',
            'pass' => 'password_account',
            'passcode' => 'password_account',
            'mail' => 'email',
            'e_mail' => 'email',
            'email_address' => 'email',
            'mail_password' => 'password_email',
            'email_password' => 'password_email',
            'password_mail' => 'password_email',
            'recovery_mail' => 'recovery_email',
            'recovery_email_address' => 'recovery_email',
            'recovery_mail_password' => 'recovery_password',
            'recovery_email_password' => 'recovery_password',
            'note' => 'notes',
            'instruction' => 'notes',
            'instructions' => 'notes',
            'detail' => 'notes',
            'details' => 'notes',
            'information' => 'notes',
            'link' => 'url',
        ];

        return $aliases[$name] ?? $name;
    }
}

if (!function_exists('cgoDeliveryAccountFieldDefinitions')) {
    /** @return array<string,array{label:string,kind:string}> */
    function cgoDeliveryAccountFieldDefinitions(): array
    {
        return [
            'account' => ['label' => 'account', 'kind' => 'identity'],
            'email' => ['label' => 'email', 'kind' => 'identity'],
            'phone' => ['label' => 'phone', 'kind' => 'identity'],
            'password_account' => ['label' => 'password_account', 'kind' => 'secret'],
            'password_email' => ['label' => 'password_email', 'kind' => 'secret'],
            'recovery_email' => ['label' => 'recovery_email', 'kind' => 'identity'],
            'recovery_password' => ['label' => 'recovery_password', 'kind' => 'secret'],
            'pin' => ['label' => 'pin', 'kind' => 'secret'],
            'secret' => ['label' => 'secret', 'kind' => 'secret'],
            'token' => ['label' => 'token', 'kind' => 'secret'],
            'url' => ['label' => 'url', 'kind' => 'support'],
            'notes' => ['label' => 'notes', 'kind' => 'support'],
            'server' => ['label' => 'server', 'kind' => 'support'],
            'region' => ['label' => 'region', 'kind' => 'support'],
        ];
    }
}

if (!function_exists('cgoBuildAccountDeliveryItem')) {
    /**
     * @param array<int,array{name:string,label:string,value:string}> $fields
     * @return array{type:string,value:string,label:string,payload:array}|null
     */
    function cgoBuildAccountDeliveryItem(array $fields): ?array
    {
        $definitions = cgoDeliveryAccountFieldDefinitions();
        $normalized = [];
        $hasIdentity = false;
        $hasSecret = false;

        foreach ($fields as $field) {
            $name = cgoNormalizeDeliveryFieldName((string) ($field['name'] ?? ''));
            if (!isset($definitions[$name])) continue;

            $value = trim((string) ($field['value'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') $label = $definitions[$name]['label'];

            // Keep an explicitly empty e-mail/account field when the provider
            // supplied its label. It is still useful context for the customer,
            // but empty support fields add noise and are dropped.
            if ($value === '' && $definitions[$name]['kind'] === 'support') continue;

            if ($definitions[$name]['kind'] === 'identity' && $value !== '') $hasIdentity = true;
            if ($definitions[$name]['kind'] === 'secret' && $value !== '') $hasSecret = true;

            $normalized[] = [
                'name' => $name,
                'label' => preg_replace('/[\x00-\x1F\x7F]+/u', '', $label) ?: $definitions[$name]['label'],
                'value' => function_exists('mb_substr') ? mb_substr($value, 0, 12000, 'UTF-8') : substr($value, 0, 12000),
            ];
        }

        if (count($normalized) < 2 || !$hasIdentity || !$hasSecret) return null;

        $parts = [];
        foreach ($normalized as $field) {
            $parts[] = $field['label'] . "\n" . $field['value'];
        }
        $display = trim(implode("\n\n", $parts));
        if ($display === '' || strlen($display) > 50000) return null;

        return [
            'type' => 'account',
            'value' => $display,
            'label' => 'Account',
            'payload' => ['fields' => $normalized],
        ];
    }
}

if (!function_exists('cgoParseAccountDeliveryText')) {
    /** @return array{type:string,value:string,label:string,payload:array}|null */
    function cgoParseAccountDeliveryText(string $text): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '' || strlen($text) > 50000) return null;

        $definitions = cgoDeliveryAccountFieldDefinitions();
        $lines = explode("\n", $text);
        $fields = [];
        $currentName = '';
        $currentLabel = '';
        $currentValueLines = [];

        $flush = static function () use (&$fields, &$currentName, &$currentLabel, &$currentValueLines): void {
            if ($currentName === '') return;
            while ($currentValueLines && trim((string) $currentValueLines[0]) === '') array_shift($currentValueLines);
            while ($currentValueLines && trim((string) $currentValueLines[count($currentValueLines) - 1]) === '') array_pop($currentValueLines);
            $fields[] = [
                'name' => $currentName,
                'label' => $currentLabel,
                'value' => trim(implode("\n", $currentValueLines)),
            ];
            $currentName = '';
            $currentLabel = '';
            $currentValueLines = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            $normalized = cgoNormalizeDeliveryFieldName($trimmed);
            if ($trimmed !== '' && isset($definitions[$normalized])) {
                $flush();
                $currentName = $normalized;
                $currentLabel = $trimmed;
                continue;
            }
            if ($currentName !== '') $currentValueLines[] = (string) $line;
        }
        $flush();

        return cgoBuildAccountDeliveryItem($fields);
    }
}

if (!function_exists('cgoAccountDeliveryFromArray')) {
    /** @return array{type:string,value:string,label:string,payload:array}|null */
    function cgoAccountDeliveryFromArray(array $data): ?array
    {
        $definitions = cgoDeliveryAccountFieldDefinitions();
        $fields = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || is_bool($value)) continue;
            $name = cgoNormalizeDeliveryFieldName($key);
            if (!isset($definitions[$name])) continue;
            $fields[] = [
                'name' => $name,
                'label' => $key,
                'value' => trim((string) $value),
            ];
        }
        return cgoBuildAccountDeliveryItem($fields);
    }
}

if (!function_exists('cgoExtractDeliveryItems')) {
    /**
     * Extract one delivered unit per purchased item.
     *
     * License products return one item per key. Account products return one
     * structured multi-line item even when the provider serializes account,
     * passwords, e-mail and notes on separate lines. This prevents a single
     * game account from being miscounted as nine delivered keys.
     *
     * @return array<int,array{type:string,value:string,label:string,payload:?array}>
     */
    function cgoExtractDeliveryItems(array $data): array
    {
        $found = [];
        $seen = [];

        $normalizeName = static function ($name): string {
            $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $name);
            $name = strtolower((string) $name);
            $name = preg_replace('/[^a-z0-9]+/', '_', $name);
            return trim((string) $name, '_');
        };

        $strongSingular = [
            'key', 'key_code', 'delivered_key', 'license', 'license_key', 'license_code',
            'product_key', 'serial', 'serial_number', 'serial_key', 'serial_code',
            'activation_key', 'activation_code', 'redeem_key', 'redeem_code',
            'voucher', 'voucher_code', 'credential', 'account_data', 'account_info',
        ];
        $pluralContainers = [
            'keys', 'key_codes', 'licenses', 'license_keys', 'license_codes',
            'product_keys', 'serials', 'serial_keys', 'serial_codes',
            'activation_keys', 'activation_codes', 'redeem_keys', 'redeem_codes',
            'vouchers', 'voucher_codes', 'credentials', 'accounts', 'delivered_keys',
        ];
        $deliveryContexts = array_fill_keys(array_merge(
            $strongSingular,
            $pluralContainers,
            ['delivery', 'delivered', 'item', 'items', 'product', 'product_data',
             'license_data', 'order', 'order_data', 'result', 'data', 'details']
        ), true);
        $ignoredValues = array_fill_keys([
            'success', 'successful', 'completed', 'complete', 'ok', 'pending',
            'processing', 'failed', 'failure', 'error', 'cancelled', 'canceled',
            'true', 'false', 'null', 'none', 'unknown',
        ], true);

        $appendItem = static function (array $item) use (&$found, &$seen): void {
            $type = strtolower(trim((string) ($item['type'] ?? 'key')));
            if (!in_array($type, ['key', 'account'], true)) $type = 'key';
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '' || strlen($value) > 50000) return;
            $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : null;
            $canonical = $payload !== null
                ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                : $value;
            if (!is_string($canonical)) $canonical = $value;
            $fingerprint = hash('sha256', $type . "\0" . $canonical);
            if (isset($seen[$fingerprint])) return;
            $seen[$fingerprint] = true;
            $found[] = [
                'type' => $type,
                'value' => $value,
                'label' => trim((string) ($item['label'] ?? ($type === 'account' ? 'Account' : ''))),
                'payload' => $payload,
            ];
        };

        $appendScalar = static function ($value, bool $generic = false, bool $trustedContext = false) use (
            $ignoredValues,
            $appendItem
        ): void {
            if (!is_scalar($value) || is_bool($value)) return;
            $candidate = trim((string) $value);
            $length = strlen($candidate);
            if ($candidate === '' || $length > 5000) return;
            if (isset($ignoredValues[strtolower($candidate)])) return;
            if (preg_match('#^https?://#i', $candidate) === 1) return;
            if (preg_match('/^(?:err(?:or)?|http|status)[-_: ]?\d+$/i', $candidate) === 1) return;
            if ($generic) {
                if ($length < 5) return;
                if (preg_match('/^\d+$/', $candidate) === 1) {
                    if (!$trustedContext || $length < 8) return;
                } else {
                    $hasLetter = preg_match('/[A-Za-z]/', $candidate) === 1;
                    $hasDigit = preg_match('/\d/', $candidate) === 1;
                    $hasCredentialSeparator = strpbrk($candidate, ':|/\\') !== false;
                    $hasStructuredHyphens = substr_count($candidate, '-') >= 2;
                    if (!$hasLetter || (!$hasDigit && !$hasCredentialSeparator && !$hasStructuredHyphens)) return;
                }
            }
            $appendItem(['type' => 'key', 'value' => $candidate, 'label' => '', 'payload' => null]);
        };

        $walk = static function ($value, string $parentKey = '') use (
            &$walk,
            $normalizeName,
            $strongSingular,
            $pluralContainers,
            $deliveryContexts,
            $appendItem,
            $appendScalar
        ): void {
            if (!is_array($value)) return;

            $accountFromObject = cgoAccountDeliveryFromArray($value);
            if ($accountFromObject !== null) {
                $appendItem($accountFromObject);
                // Continue only into nested arrays. Scalar account fields have
                // already been consumed and must not become separate keys.
                foreach ($value as $nested) {
                    if (is_array($nested)) $walk($nested, $parentKey);
                }
                return;
            }

            foreach ($value as $key => $item) {
                $name = $normalizeName($key);
                $parent = $normalizeName($parentKey);

                if (is_string($item)) {
                    $trimmedItem = trim($item);
                    if ($trimmedItem !== '' && in_array($trimmedItem[0], ['{', '['], true)) {
                        $decodedItem = json_decode($trimmedItem, true);
                        if (is_array($decodedItem)) {
                            $walk($decodedItem, $name);
                            continue;
                        }
                    }

                    $accountFromText = cgoParseAccountDeliveryText($item);
                    if ($accountFromText !== null) {
                        $appendItem($accountFromText);
                        continue;
                    }
                }

                if (in_array($name, $strongSingular, true) && is_scalar($item)) {
                    $appendScalar($item, false);
                    continue;
                }

                if (in_array($name, $pluralContainers, true)) {
                    if (is_scalar($item)) {
                        $parts = preg_split('/\R+/', trim((string) $item)) ?: [];
                        foreach ($parts as $part) $appendScalar($part, false);
                    } elseif (is_array($item)) {
                        $scalarParts = [];
                        $allScalar = true;
                        foreach ($item as $candidate) {
                            if (is_scalar($candidate) && !is_bool($candidate)) {
                                $scalarParts[] = (string) $candidate;
                            } else {
                                $allScalar = false;
                                break;
                            }
                        }
                        if ($allScalar && $scalarParts !== []) {
                            $accountFromList = cgoParseAccountDeliveryText(implode("\n", $scalarParts));
                            if ($accountFromList !== null) {
                                $appendItem($accountFromList);
                                continue;
                            }
                        }
                        foreach ($item as $candidate) {
                            if (is_scalar($candidate)) $appendScalar($candidate, false);
                            elseif (is_array($candidate)) $walk($candidate, $name);
                        }
                    }
                    continue;
                }

                if (in_array($name, ['code', 'value'], true) && is_scalar($item)) {
                    $appendScalar($item, true, isset($deliveryContexts[$parent]));
                    continue;
                }

                if (in_array($name, ['data', 'result', 'delivery', 'delivered'], true) && is_scalar($item)) {
                    $appendScalar($item, true, true);
                    continue;
                }

                if (is_int($key) && is_scalar($item) && isset($deliveryContexts[$parent])) {
                    $appendScalar($item, false, true);
                    continue;
                }

                if (is_array($item)) $walk($item, $name);
            }
        };

        $walk($data);
        return array_values($found);
    }
}

if (!function_exists('cgoExtractKeys')) {
    /** @return string[] */
    function cgoExtractKeys(array $data): array
    {
        return array_values(array_map(
            static fn(array $item): string => (string) ($item['value'] ?? ''),
            cgoExtractDeliveryItems($data)
        ));
    }
}

if (!function_exists('cgoDeliveryValueIsAccount')) {
    function cgoDeliveryValueIsAccount(string $value): bool
    {
        return cgoParseAccountDeliveryText($value) !== null;
    }
}

if (!function_exists('cgoProductLooksLikeAccount')) {
    function cgoProductLooksLikeAccount(array $product): bool
    {
        $platform = strtolower(trim((string) ($product['platform'] ?? '')));
        if ($platform === 'account') return true;
        $text = strtolower(trim(implode(' ', [
            (string) ($product['name'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (string) ($product['category'] ?? ''),
            (string) ($product['description'] ?? ''),
        ])));
        return preg_match('/(?:\baccount\b|\bakun\b|บัญชี|login\s*account|game\s*account)/iu', $text) === 1;
    }
}

if (!function_exists('cgoFindRecursiveBoolean')) {
    function cgoFindRecursiveBoolean(array $data, string $key): ?bool
    {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
            if (is_bool($value)) return $value;
            if ($value === 1 || $value === '1' || (is_string($value) && strtolower(trim($value)) === 'true')) return true;
            if ($value === 0 || $value === '0' || (is_string($value) && strtolower(trim($value)) === 'false')) return false;
        }
        foreach ($data as $value) {
            if (!is_array($value)) continue;
            $found = cgoFindRecursiveBoolean($value, $key);
            if ($found !== null) return $found;
        }
        return null;
    }
}

if (!function_exists('cgoApiExplicitlyRejected')) {
    function cgoApiExplicitlyRejected(array $data): bool
    {
        $success = cgoFindRecursiveBoolean($data, 'success');
        if ($success === false) return true;
        $status = strtolower((string) (cgoFindRecursiveValue($data, ['status']) ?? ''));
        return in_array($status, ['failed', 'error', 'rejected', 'cancelled', 'canceled'], true);
    }
}

if (!function_exists('cgoOrderStatusFinalFlag')) {
    function cgoOrderStatusFinalFlag(array $data): ?bool
    {
        return cgoFindRecursiveBoolean($data, 'final');
    }
}

if (!function_exists('cgoOrderStatusTerminalFailure')) {
    /**
     * Provider contract: a failed/cancelled status is financially final only
     * when the supplier explicitly returns final=true. A plain failed-looking
     * response must remain reserved until finality or a successful order_cancel
     * seal is obtained.
     */
    function cgoOrderStatusTerminalFailure(array $data): bool
    {
        $status = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? '')));
        $final = cgoOrderStatusFinalFlag($data);
        return $final === true && in_array($status, ['failed', 'failure', 'rejected', 'cancelled', 'canceled'], true);
    }
}

if (!function_exists('cgoOrderStatusProcessing')) {
    function cgoOrderStatusProcessing(array $data): bool
    {
        $status = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? '')));
        return in_array($status, ['pending', 'processing', 'queued', 'in_progress', 'in-progress'], true);
    }
}

if (!function_exists('cgoOrderStatusNonFinalNotFound')) {
    /**
     * Detect the provider's machine-readable order_not_found response. This is
     * deliberately NOT a refund authority. CHEATGAME explicitly documents that
     * order_not_found is non-final after an ambiguous POST; it only authorizes
     * the next fencing step: order_cancel(external_ref).
     */
    function cgoOrderStatusNonFinalNotFound(array $api, ?array $data, string $externalRef, bool $lookupByExternalRef = true): bool
    {
        if (!$lookupByExternalRef || !is_array($data)) return false;
        if (cgoExtractSupplierOrderId($data) !== null || cgoExtractKeys($data) !== []) return false;

        $externalRef = trim($externalRef);
        if ($externalRef === '') return false;
        $echoedExternalRef = trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? ''));
        if ($echoedExternalRef !== '' && !hash_equals(strtoupper($externalRef), strtoupper($echoedExternalRef))) {
            return false;
        }

        $providerCode = strtolower(trim((string) ($api['provider_error_code'] ?? cgoExtractProviderErrorCode($data))));
        $providerCode = preg_replace('/[^a-z0-9]+/', '_', $providerCode) ?? $providerCode;
        $status = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? '')));
        $notFoundCodes = [
            'order_not_found', 'external_ref_not_found', 'external_reference_not_found',
            'reference_not_found', 'not_found', 'unknown_order',
        ];
        $notFoundStatuses = ['not_found', 'notfound', 'missing', 'unknown_order'];
        return in_array($providerCode, $notFoundCodes, true)
            || in_array($status, $notFoundStatuses, true);
    }
}

if (!function_exists('cgoOrderStatusDefinitelyNotFound')) {
    /**
     * Compatibility safety wrapper. Under the current provider contract,
     * order_not_found is never sufficient by itself to move money. Callers must
     * use order_cancel as a fencing step or require failed/cancelled + final=true.
     */
    function cgoOrderStatusDefinitelyNotFound(array $api, ?array $data, string $externalRef, bool $lookupByExternalRef = true): bool
    {
        return false;
    }
}

if (!function_exists('cgoOrderCancelClassification')) {
    /**
     * Classify order_cancel without assuming one exact response schema.
     * A successful action means the external_ref is sealed according to the
     * provider contract. Any signal that the order already exists/succeeded wins
     * over refund. Unknown/transport states never authorize a refund.
     */
    function cgoOrderCancelClassification(array $api, ?array $data, string $externalRef): array
    {
        if (!is_array($data)) {
            return ['state' => 'unknown', 'safe_to_refund' => false, 'reason' => (string) ($api['error'] ?? 'order_cancel returned no JSON')];
        }
        $status = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? '')));
        $providerCode = strtolower(trim((string) ($api['provider_error_code'] ?? cgoExtractProviderErrorCode($data))));
        $providerCode = preg_replace('/[^a-z0-9]+/', '_', $providerCode) ?? $providerCode;
        $success = cgoFindRecursiveBoolean($data, 'success');
        $final = cgoFindRecursiveBoolean($data, 'final');
        $sealed = cgoFindRecursiveBoolean($data, 'sealed');
        $safeToRefund = cgoFindRecursiveBoolean($data, 'safe_to_refund');
        $supplierOrderId = cgoExtractSupplierOrderId($data);
        $keys = cgoExtractKeys($data);
        $echoedExternalRef = trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? ''));
        $externalRef = trim($externalRef);
        $externalRefMismatch = $externalRef !== '' && $echoedExternalRef !== ''
            && !hash_equals(strtoupper($externalRef), strtoupper($echoedExternalRef));

        if ($externalRefMismatch) {
            return ['state' => 'conflict', 'safe_to_refund' => false, 'reason' => 'order_cancel echoed a different external_ref', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }
        if ($externalRef !== '' && $echoedExternalRef === '') {
            return ['state' => 'unknown', 'safe_to_refund' => false, 'reason' => 'order_cancel did not echo the external_ref; the fence cannot be bound safely to this order', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }

        $alreadyExistsCodes = [
            'order_already_success', 'order_already_succeeded', 'order_already_completed',
            'already_success', 'already_succeeded', 'already_completed',
            'cancel_rejected_order_success', 'order_exists',
        ];
        if ($keys !== [] || in_array($providerCode, $alreadyExistsCodes, true)) {
            return ['state' => 'order_exists', 'safe_to_refund' => false, 'reason' => 'Supplier indicates the order already exists or has delivery.', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }

        if (in_array($status, ['processing', 'pending', 'queued', 'in_progress', 'in-progress'], true)) {
            return ['state' => 'processing', 'safe_to_refund' => false, 'reason' => 'Supplier reports that the order is still processing.', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }

        // Refund authority is deliberately strict and matches the verified
        // CHEATGAME contract. Generic HTTP success or a cancelled-looking string
        // alone is never enough to move money.
        if (in_array($status, ['success', 'completed'], true)) {
            return ['state' => 'order_exists', 'safe_to_refund' => false, 'reason' => 'Supplier indicates that the order already exists.', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }

        $httpCode = (int) ($api['http_code'] ?? 0);
        $httpOk = $httpCode >= 200 && $httpCode < 300;
        if ($httpOk && $success === true && $final === true
            && ($safeToRefund === true || $sealed === true || in_array($status, ['cancelled', 'canceled', 'sealed'], true))) {
            return ['state' => 'sealed', 'safe_to_refund' => true, 'reason' => 'Supplier successfully cancelled/sealed the external_ref.', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys, 'final' => $final];
        }

        if ($supplierOrderId !== null) {
            return ['state' => 'order_exists', 'safe_to_refund' => false, 'reason' => 'Supplier indicates that the order already exists.', 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
        }

        return ['state' => 'unknown', 'safe_to_refund' => false, 'reason' => cgoExtractErrorMessage($data, (string) ($api['error'] ?? 'order_cancel result is unresolved')), 'supplier_order_id' => $supplierOrderId, 'keys' => $keys];
    }
}

if (!function_exists('cgoAttemptOrderCancelSeal')) {
    function cgoAttemptOrderCancelSeal(int $orderId, string $externalRef): array
    {
        $externalRef = trim($externalRef);
        if ($orderId < 1 || $externalRef === '') {
            return ['state' => 'unknown', 'safe_to_refund' => false, 'reason' => 'Missing order ID or external_ref for order_cancel.'];
        }
        $api = cgoApiRequest('order_cancel', 'POST', ['external_ref' => $externalRef], cgoOrderCancelTimeoutSeconds());
        $data = isset($api['data']) && is_array($api['data']) ? $api['data'] : null;
        $classification = cgoOrderCancelClassification($api, $data, $externalRef);
        $state = (string) ($classification['state'] ?? 'unknown');
        $decision = [
            'sealed' => 'cancel_sealed_safe_to_refund',
            'order_exists' => 'cancel_rejected_order_exists',
            'processing' => 'cancel_processing',
            'conflict' => 'cancel_response_conflict',
        ][$state] ?? (!empty($api['transport_error']) ? 'cancel_transport_unresolved' : 'cancel_provider_unresolved');
        cgoRecordOrderApiAttempt($orderId, 'cancel', $api, [
            'lookup_mode' => 'external_ref',
            'external_ref' => $externalRef,
            'supplier_order_id' => (string) ($classification['supplier_order_id'] ?? ''),
        ], $decision);
        $classification['api'] = $api;
        $classification['data'] = $data;
        $classification['decision'] = $decision;
        return $classification;
    }
}

if (!function_exists('cgoOrderStatusClassifierSelfTest')) {
    /**
     * Pure regression tests for financial-finality classification. No network or
     * database writes are performed.
     */
    function cgoOrderStatusClassifierSelfTest(): array
    {
        $ref = 'SAK-DIAG-CLASSIFIER-0001';
        $cases = [];

        $cases['order_not_found_is_nonfinal'] = [
            'expected' => true,
            'actual' => cgoOrderStatusNonFinalNotFound(
                ['http_code' => 404, 'provider_error_code' => 'order_not_found'],
                ['success' => false, 'error' => 'order_not_found', 'final' => false],
                $ref,
                true
            ),
        ];
        $cases['order_not_found_never_direct_refund'] = [
            'expected' => false,
            'actual' => cgoOrderStatusDefinitelyNotFound(
                ['http_code' => 404, 'provider_error_code' => 'order_not_found'],
                ['success' => false, 'external_ref' => $ref, 'error' => 'order_not_found'],
                $ref,
                true
            ),
        ];
        $cases['failed_without_final_is_not_terminal'] = [
            'expected' => false,
            'actual' => cgoOrderStatusTerminalFailure(['success' => false, 'status' => 'failed', 'final' => false]),
        ];
        $cases['failed_with_final_is_terminal'] = [
            'expected' => true,
            'actual' => cgoOrderStatusTerminalFailure(['success' => false, 'status' => 'failed', 'final' => true]),
        ];
        $cases['cancel_success_seals_ref'] = [
            'expected' => true,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => true, 'http_code' => 200, 'provider_error_code' => ''],
                ['success' => true, 'status' => 'cancelled', 'final' => true, 'external_ref' => $ref],
                $ref
            )['safe_to_refund']),
        ];
        $cases['cancel_processing_blocks_refund'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => true, 'http_code' => 200, 'provider_error_code' => ''],
                ['success' => true, 'status' => 'processing', 'final' => false],
                $ref
            )['safe_to_refund']),
        ];
        $cases['cancel_existing_delivery_blocks_refund'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => false, 'http_code' => 409, 'provider_error_code' => 'order_already_success'],
                ['success' => false, 'status' => 'success', 'order_id' => 'RSAPI-DIAG-1', 'delivery' => [['key' => 'DIAG-KEY']]],
                $ref
            )['safe_to_refund']),
        ];
        $cases['cancel_http500_cancelled_without_final_blocks_refund'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => false, 'http_code' => 500, 'provider_error_code' => 'provider_error'],
                ['success' => false, 'status' => 'cancelled', 'final' => false],
                $ref
            )['safe_to_refund']),
        ];
        $cases['cancel_external_ref_mismatch_blocks_refund'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => true, 'http_code' => 200, 'provider_error_code' => ''],
                ['success' => true, 'status' => 'cancelled', 'external_ref' => $ref . '-OTHER'],
                $ref
            )['safe_to_refund']),
        ];

        $cases['cancel_success_status_beats_refund_flag'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => true, 'http_code' => 200, 'provider_error_code' => ''],
                ['success' => true, 'status' => 'success', 'final' => true, 'safe_to_refund' => true, 'external_ref' => $ref],
                $ref
            )['safe_to_refund']),
        ];

        $cases['cancel_missing_external_ref_blocks_refund'] = [
            'expected' => false,
            'actual' => !empty(cgoOrderCancelClassification(
                ['ok' => true, 'http_code' => 200, 'provider_error_code' => 'order_cancelled'],
                ['success' => true, 'status' => 'cancelled', 'final' => true],
                $ref
            )['safe_to_refund']),
        ];

        $cases['cancel_success_with_informational_error_normalizes_ok'] = [
            'expected' => true,
            'actual' => cgoOrderCancelApiResponseIsSuccessful(
                [
                    'success' => true,
                    'final' => true,
                    'data' => [
                        'external_ref' => $ref,
                        'status' => 'cancelled',
                        'error' => 'order_cancelled',
                        'message' => 'Order cancelled before becoming final.',
                    ],
                ],
                $ref,
                200
            ),
        ];

        $results = [];
        $passed = 0;
        foreach ($cases as $name => $case) {
            $ok = $case['actual'] === $case['expected'];
            if ($ok) $passed++;
            $results[$name] = ['passed' => $ok, 'expected' => $case['expected'], 'actual' => $case['actual']];
        }
        return ['passed' => $passed === count($cases), 'passed_count' => $passed, 'total_count' => count($cases), 'cases' => $results];
    }
}


if (!function_exists('cgoApiAccepted')) {
    function cgoApiAccepted(array $data): bool
    {
        $success = cgoFindRecursiveBoolean($data, 'success');
        if ($success === false) return false;
        if ($success === true) return true;
        $status = strtolower((string) (cgoFindRecursiveValue($data, ['status']) ?? ''));
        if (in_array($status, ['failed', 'error', 'rejected', 'cancelled', 'canceled'], true)) {
            return false;
        }
        if (in_array($status, ['success', 'ok', 'completed', 'processing', 'pending'], true)) {
            return true;
        }
        return cgoExtractSupplierOrderId($data) !== null || cgoExtractKeys($data) !== [];
    }
}

if (!function_exists('cgoGenerateExternalRef')) {
    function cgoGenerateExternalRef(): string
    {
        $prefix = cgoSitePrefix();
        if ($prefix === '') return '';
        try {
            $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Ymd');
            return $prefix . '-' . $date . '-' . strtoupper(bin2hex(random_bytes(8)));
        } catch (Throwable $e) {
            error_log('CGO external reference generation failed: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('cgoStoreOrderKeys')) {
    function cgoStoreOrderKeys(int $orderId, array $keys): int
    {
        global $conn;
        if ($orderId < 1 || $keys === [] || !cgoEnsureTables()) {
            return 0;
        }

        $normalized = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > 50000) continue;
            $normalized[$key] = $key;
        }
        $normalized = array_values($normalized);
        if ($normalized === []) return 0;

        // Legacy builds may already have stored one account as separate label
        // and value rows. Do not add a tenth row when a webhook/reconcile later
        // returns the correctly grouped account block.
        if (count($normalized) === 1 && cgoDeliveryValueIsAccount($normalized[0])) {
            $existingStmt = $conn->prepare('SELECT key_code FROM cgo_order_keys WHERE order_id = ? ORDER BY id ASC LIMIT 100');
            if ($existingStmt) {
                $existingStmt->bind_param('i', $orderId);
                if ($existingStmt->execute()) {
                    $existingResult = $existingStmt->get_result();
                    $legacyParts = [];
                    while ($existingRow = $existingResult ? $existingResult->fetch_assoc() : null) {
                        if (!$existingRow) break;
                        $part = trim((string) ($existingRow['key_code'] ?? ''));
                        if ($part !== '') $legacyParts[] = $part;
                    }
                    if (count($legacyParts) > 1
                        && cgoParseAccountDeliveryText(implode("\n", $legacyParts)) !== null) {
                        $existingStmt->close();
                        return 0;
                    }
                }
                $existingStmt->close();
            }
        }

        $stmt = $conn->prepare('INSERT IGNORE INTO cgo_order_keys (order_id, key_code, key_hash) VALUES (?, ?, ?)');
        if (!$stmt) return 0;
        $count = 0;
        foreach ($normalized as $key) {
            $hash = hash('sha256', $key);
            $stmt->bind_param('iss', $orderId, $key, $hash);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $count++;
            }
        }
        $stmt->close();
        return $count;
    }
}

if (!function_exists('cgoOrderKeyCount')) {
    function cgoOrderKeyCount(int $orderId): int
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) {
            return 0;
        }
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM cgo_order_keys WHERE order_id = ?');
        if (!$stmt) return 0;
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) { $stmt->close(); return 0; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ? max(0, (int) $row['total']) : 0;
    }
}

if (!function_exists('cgoOrderHasKeys')) {
    function cgoOrderHasKeys(int $orderId, array $keys): bool
    {
        global $conn;
        if ($orderId < 1 || $keys === [] || !cgoEnsureTables()) {
            return false;
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $keys
        ), static fn(string $value): bool => $value !== '' && strlen($value) <= 50000)));
        if ($normalized === []) return false;

        $stmt = $conn->prepare('SELECT 1 FROM cgo_order_keys WHERE order_id = ? AND key_hash = ? LIMIT 1');
        if (!$stmt) return false;
        foreach ($normalized as $key) {
            $hash = hash('sha256', $key);
            $stmt->bind_param('is', $orderId, $hash);
            if (!$stmt->execute()) { $stmt->close(); return false; }
            $result = $stmt->get_result();
            if ($result && $result->fetch_assoc()) continue;

            // Accept the factual legacy representation where one account was
            // split into label/value rows. The reader normalizes it to one item.
            if (count($normalized) === 1 && cgoDeliveryValueIsAccount($key)) {
                $legacyStmt = $conn->prepare('SELECT key_code FROM cgo_order_keys WHERE order_id = ? ORDER BY id ASC LIMIT 100');
                if ($legacyStmt) {
                    $legacyStmt->bind_param('i', $orderId);
                    if ($legacyStmt->execute()) {
                        $legacyResult = $legacyStmt->get_result();
                        $parts = [];
                        while ($legacyRow = $legacyResult ? $legacyResult->fetch_assoc() : null) {
                            if (!$legacyRow) break;
                            $part = trim((string) ($legacyRow['key_code'] ?? ''));
                            if ($part !== '') $parts[] = $part;
                        }
                        $legacyStmt->close();
                        if (count($parts) > 1
                            && cgoParseAccountDeliveryText(implode("\n", $parts)) !== null) {
                            continue;
                        }
                    } else {
                        $legacyStmt->close();
                    }
                }
            }

            $stmt->close();
            return false;
        }
        $stmt->close();
        return true;
    }
}

if (!function_exists('cgoMarkTransactionPendingIfOrderUnresolved')) {
    /**
     * Do not let a stale status/recovery request downgrade a completed or refunded
     * transaction after a webhook/refund won the race. The order state check and
     * transaction update happen in one SQL statement.
     */
    function cgoMarkTransactionPendingIfOrderUnresolved(int $orderId, int $transactionId): bool
    {
        global $conn;
        if ($orderId < 1 || $transactionId < 1) return true;
        $stmt = $conn->prepare("UPDATE transactions t\n            INNER JOIN cgo_orders o ON o.id = ? AND o.transaction_id = t.id\n            SET t.status = 'pending'\n            WHERE t.id = ?\n              AND LOWER(TRIM(COALESCE(o.status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $orderId, $transactionId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('cgoRefundOrder')) {
    function cgoRefundOrder(int $orderId, string $reason, string $authority = ''): bool
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) return false;
        $authority = strtolower(trim($authority));
        $allowedAuthorities = ['provider_cancel_seal', 'provider_failed_final', 'pre_delivery_transport_proof'];
        if (!in_array($authority, $allowedAuthorities, true)) {
            error_log('CGO refund blocked: invalid refund authority for order_id=' . $orderId);
            return false;
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT user_id, total_price_base, status, transaction_id, external_ref, source_kind, source_order_id FROM cgo_orders WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) throw new RuntimeException('refund order lock prepare failed');
            $stmt->bind_param('i', $orderId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('refund order lock failed'); }
            $result = $stmt->get_result();
            $order = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$order) throw new RuntimeException('order not found');
            if (in_array((string) $order['status'], ['refunded', 'refunded_conflict', 'success', 'completed'], true)) {
                $conn->rollback();
                return in_array((string) $order['status'], ['refunded', 'refunded_conflict'], true);
            }

            // Financial invariant: a local delivery record and an automatic refund
            // may never coexist. The order row is already locked, so webhook
            // processing for this order must wait until this decision commits.
            $keyStmt = $conn->prepare('SELECT COUNT(*) AS total FROM cgo_order_keys WHERE order_id = ?');
            if (!$keyStmt) throw new RuntimeException('refund key invariant prepare failed');
            $keyStmt->bind_param('i', $orderId);
            if (!$keyStmt->execute()) { $keyStmt->close(); throw new RuntimeException('refund key invariant check failed'); }
            $keyResult = $keyStmt->get_result();
            $keyRow = $keyResult ? $keyResult->fetch_assoc() : null;
            $keyStmt->close();
            $storedKeyCount = max(0, (int) ($keyRow['total'] ?? 0));
            if ($storedKeyCount > 0) {
                $conflictReason = 'Automatic refund blocked because ' . $storedKeyCount . ' delivered key record(s) already exist locally.';
                $review = $conn->prepare("UPDATE cgo_orders SET status='manual_review', error_message=?, updated_at=NOW() WHERE id=?");
                if (!$review) throw new RuntimeException('refund conflict update prepare failed');
                $review->bind_param('si', $conflictReason, $orderId);
                if (!$review->execute()) { $review->close(); throw new RuntimeException('refund conflict update failed'); }
                $review->close();
                $transactionId = (int) ($order['transaction_id'] ?? 0);
                if ($transactionId > 0) {
                    $txPending = $conn->prepare("UPDATE transactions SET status='pending' WHERE id=?");
                    if (!$txPending) throw new RuntimeException('refund conflict transaction prepare failed');
                    $txPending->bind_param('i', $transactionId);
                    if (!$txPending->execute()) { $txPending->close(); throw new RuntimeException('refund conflict transaction update failed'); }
                    $txPending->close();
                }
                if (!$conn->commit()) throw new RuntimeException('refund conflict commit failed');
                cgoCommerceCenterSyncSafe($orderId);
                return false;
            }

            $sourceKind = strtolower(trim((string) ($order['source_kind'] ?? 'storefront')));
            if ($sourceKind === 'store_api') {
                $reason = substr(trim($reason), 0, 2000);
                $update = $conn->prepare("UPDATE cgo_orders SET status='refunded', error_message=?, updated_at=NOW() WHERE id=?");
                if (!$update) throw new RuntimeException('external procurement refund state prepare failed');
                $update->bind_param('si', $reason, $orderId);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    $update->close();
                    throw new RuntimeException('external procurement refund state failed');
                }
                $update->close();
                if (!$conn->commit()) throw new RuntimeException('external procurement refund commit failed');
                cgoCommerceCenterSyncSafe($orderId);
                cgoFinalizeOrderCheckoutTiming($orderId, 'external_release');
                return true;
            }
            if (!ensureWalletLedgerSchema()) throw new RuntimeException('refund wallet audit unavailable');
            $userId = (int) $order['user_id'];
            $amount = round((float) $order['total_price_base'], 2);
            $walletBefore = walletLedgerReadBalance($userId, true);
            if ($walletBefore === null) throw new RuntimeException('refund balance lock failed');
            $credit = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ? AND status = 'active'");
            if (!$credit) throw new RuntimeException('refund user prepare failed');
            $credit->bind_param('di', $amount, $userId);
            if (!$credit->execute() || $credit->affected_rows !== 1) {
                $credit->close();
                throw new RuntimeException('refund user failed');
            }
            $credit->close();

            $reason = substr(trim($reason), 0, 2000);
            $update = $conn->prepare("UPDATE cgo_orders SET status = 'refunded', error_message = ?, updated_at = NOW() WHERE id = ?");
            if (!$update) throw new RuntimeException('refund order update prepare failed');
            $update->bind_param('si', $reason, $orderId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('refund order update failed');
            }
            $update->close();

            $transactionId = (int) ($order['transaction_id'] ?? 0);
            if ($transactionId > 0) {
                $tx = $conn->prepare("UPDATE transactions SET status = 'failed', description = CONCAT(description, ' | Refunded: ', ?) WHERE id = ?");
                if (!$tx) throw new RuntimeException('refund transaction prepare failed');
                $tx->bind_param('si', $reason, $transactionId);
                if (!$tx->execute() || $tx->affected_rows !== 1) {
                    $tx->close();
                    throw new RuntimeException('refund transaction update failed');
                }
                $tx->close();
            }
            $walletAfter = round($walletBefore + $amount, 2);
            if (!walletLedgerRecordMovement(
                $userId, $amount, $walletBefore, $walletAfter,
                'cgo_refund', 'cgo_refund:' . $orderId, $orderId,
                $transactionId > 0 ? $transactionId : null, null,
                'คืนเงินคำสั่งซื้อ CGO #' . $orderId,
                'Automatic CGO refund authority: ' . $authority . '.',
                (string) ($order['external_ref'] ?? ''), true
            )) {
                throw new RuntimeException('refund wallet audit failed');
            }
            if (!$conn->commit()) throw new RuntimeException('refund commit failed');
            if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
                $_SESSION['balance'] = getUserBalance($userId);
            }
            logHistory($userId, 'cgo_order_refund', 'CHEATGAME order #' . $orderId . ' refunded: ' . $reason);
            cgoCommerceCenterSyncSafe($orderId);
            cgoFinalizeOrderCheckoutTiming($orderId, 'refund');
            return true;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('CGO refund failed: ' . $e->getMessage());
            return false;
        }
    }
}


if (!function_exists('cgoRefreshBlockingOrderIfStale')) {
    /**
     * A retry should not remain blocked by a stale ambiguous order once provider
     * finality can be resolved. Reconcile stale rows once before returning the
     * blocker; successful or safely refunded rows disappear from the guard.
     */
    function cgoRefreshBlockingOrderIfStale(?array $row): ?array
    {
        global $conn;
        if (!$row) return null;
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId < 1 || !function_exists('cgoReconcileOrder')) return $row;

        $createdTs = strtotime((string) ($row['created_at'] ?? ''));
        $updatedTs = strtotime((string) ($row['updated_at'] ?? ''));
        $now = time();
        $ageSeconds = $createdTs === false ? 0 : max(0, $now - $createdTs);
        $sinceUpdate = $updatedTs === false ? PHP_INT_MAX : max(0, $now - $updatedTs);
        if ($ageSeconds < cgoOrderNotFoundGraceSeconds()
            || $sinceUpdate < cgoOrderReconcileMinIntervalSeconds()) {
            return $row;
        }

        cgoReconcileOrder($orderId);

        $stmt = $conn->prepare("SELECT id, external_ref, status, total_price_base, created_at, updated_at
                                FROM cgo_orders
                                WHERE id = ?
                                  AND status IN ('submitting','unknown','pending','processing','manual_review')
                                LIMIT 1");
        if (!$stmt) return $row;
        $stmt->bind_param('i', $orderId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $row;
        }
        $result = $stmt->get_result();
        $fresh = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $fresh ?: null;
    }
}

if (!function_exists('cgoFindBlockingPendingOrder')) {
    /**
     * Prevent a customer from creating a second order for the same supplier SKU
     * while the first one is unresolved. The shared account-level MySQL lock
     * serializes concurrent supplier checkouts, closing the double-tap/retry race.
     */
    function cgoFindBlockingPendingOrder(int $userId, int $cgoProductId): ?array
    {
        global $conn;
        if ($userId < 1 || $cgoProductId < 1 || !cgoEnsureTables()) return null;
        $stmt = $conn->prepare("SELECT id, external_ref, status, total_price_base, created_at, updated_at
                               FROM cgo_orders
                               WHERE user_id = ? AND cgo_product_id = ?
                                 AND status IN ('submitting','unknown','pending','processing','manual_review')
                               ORDER BY id DESC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ii', $userId, $cgoProductId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return cgoRefreshBlockingOrderIfStale($row ?: null);
    }
}


if (!function_exists('cgoFindBlockingPendingOrderForVariant')) {
    /**
     * Cross-source retry guard. A pending CHEATGAME order must block Store
     * Bridge and local checkout for the same storefront variant as well, or a
     * customer retry could receive two deliveries from different sources.
     */
    function cgoFindBlockingPendingOrderForVariant(int $userId, int $localVariantId): ?array
    {
        global $conn;
        if ($userId < 1 || $localVariantId < 1 || !cgoEnsureTables()) return null;
        $stmt = $conn->prepare("SELECT id, external_ref, status, total_price_base, created_at, updated_at
                               FROM cgo_orders
                               WHERE user_id = ? AND local_variant_id = ?
                                 AND status IN ('submitting','unknown','pending','processing','manual_review')
                               ORDER BY id DESC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('ii', $userId, $localVariantId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return cgoRefreshBlockingOrderIfStale($row ?: null);
    }
}

if (!function_exists('cgoSupplierProductLockName')) {
    function cgoSupplierProductLockName(string $remoteProductId): string
    {
        $remoteProductId = trim($remoteProductId);
        if ($remoteProductId === '') return '';
        $config = cgoConfig();
        $accountFingerprint = hash('sha256', (string) ($config['api_key'] ?? ''));
        // Only one supplier API account exists for both websites. Serialize ALL
        // mutating order checkouts for that account, not merely the same SKU.
        // This prevents database 010 and 005 from posting two supplier orders at
        // the same instant while still keeping the databases fully independent.
        // MySQL named locks are server-wide and limited to 64 characters.
        return 'cgo_ord_account_' . substr($accountFingerprint, 0, 40);
    }
}

if (!function_exists('cgoAcquireSupplierProductLock')) {
    function cgoAcquireSupplierProductLock(string $remoteProductId, int $waitSeconds = 12): array
    {
        global $conn;
        $lockName = cgoSupplierProductLockName($remoteProductId);
        if ($lockName === '') {
            return ['acquired' => false, 'busy' => false, 'lock_name' => '', 'message' => 'Supplier product lock name is invalid'];
        }
        $waitSeconds = max(0, min(60, $waitSeconds));
        $stmt = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        if (!$stmt) {
            error_log('CGO supplier account order lock prepare failed: ' . $conn->error);
            return ['acquired' => false, 'busy' => false, 'lock_name' => $lockName, 'message' => 'Supplier order lock is unavailable'];
        }
        $stmt->bind_param('si', $lockName, $waitSeconds);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            error_log('CGO supplier account order lock execute failed: ' . $error);
            return ['acquired' => false, 'busy' => false, 'lock_name' => $lockName, 'message' => 'Supplier order lock is unavailable'];
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $value = $row['acquired'] ?? null;
        if ((string) $value === '1') {
            return ['acquired' => true, 'busy' => false, 'lock_name' => $lockName, 'message' => ''];
        }
        if ((string) $value === '0') {
            return ['acquired' => false, 'busy' => true, 'lock_name' => $lockName, 'message' => 'Another order on this supplier API account is being processed'];
        }
        error_log('CGO supplier account order lock returned NULL for ' . $lockName);
        return ['acquired' => false, 'busy' => false, 'lock_name' => $lockName, 'message' => 'Supplier order lock is unavailable'];
    }
}

if (!function_exists('cgoReleaseSupplierProductLock')) {
    function cgoReleaseSupplierProductLock(string $lockName): void
    {
        global $conn;
        $lockName = trim($lockName);
        if ($lockName === '') return;
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?) AS released');
        if (!$stmt) {
            error_log('CGO supplier product unlock prepare failed: ' . $conn->error);
            return;
        }
        $stmt->bind_param('s', $lockName);
        if (!$stmt->execute()) {
            error_log('CGO supplier product unlock execute failed: ' . $stmt->error);
        }
        $stmt->close();
    }
}

if (!function_exists('cgoPurchaseProductLocked')) {
    function cgoPurchaseProductLocked(int $productId, int $userId, int $quantity = 1, int $localProductId = 0, int $localVariantId = 0, array $checkoutTiming = [], array $purchaseContext = []): array
    {
        global $conn;
        $lockedStartedAtMs = (int) round(microtime(true) * 1000);
        $checkoutStartedAtMs = max(0, (int) ($checkoutTiming['checkout_started_at_ms'] ?? $lockedStartedAtMs));
        $externalBilling = strtolower(trim((string) ($purchaseContext['billing_mode'] ?? ''))) === 'external_store_api';
        $sourceKind = $externalBilling ? 'store_api' : 'storefront';
        $sourceOrderId = $externalBilling ? max(0, (int) ($purchaseContext['source_order_id'] ?? 0)) : 0;
        if ($productId < 1 || (!$externalBilling && $userId < 1) || ($externalBilling && $sourceOrderId < 1) || $quantity < 1 || $quantity > 100 || $localProductId < 0 || $localVariantId < 0 || !cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Invalid purchase request'];
        }
        if (!$externalBilling && !ensureWalletLedgerSchema()) {
            return ['success' => false, 'message' => 'Financial audit storage is unavailable. No balance was charged.'];
        }
        if (!cgoLiveOrderEnabled()) {
            return ['success' => false, 'message' => cgoLiveOrderDisabledMessage()];
        }

        $blockingOrder = $externalBilling ? null : cgoFindBlockingPendingOrder($userId, $productId);
        if ($blockingOrder) {
            return [
                'success' => false,
                'pending' => true,
                'code' => 'existing_pending_order',
                'order_id' => (int) ($blockingOrder['id'] ?? 0),
                'total' => (float) ($blockingOrder['total_price_base'] ?? 0),
                'message' => 'An earlier order for this supplier item is still unresolved. Do not submit another order.',
            ];
        }

        // Fast checkout: never block a purchase on a full-catalogue network
        // refresh. The cached snapshot is used only as a fresh negative guard;
        // stale/unknown stock proceeds to action=order, which is authoritative.
        if (cgoCheckoutLiveInventoryRefreshEnabled()) {
            $inventoryVerification = cgoVerifyProductInventoryForPurchase($productId, $quantity);
            if (empty($inventoryVerification['success'])) {
                return [
                    'success' => false,
                    'code' => 'supplier_inventory_unavailable',
                    'message' => 'Latest supplier stock could not be verified. No balance was charged. Please try again shortly.',
                ];
            }
        }
        $product = cgoGetProductById($productId, false);
        if (!$product || (int) ($product['enabled'] ?? 0) !== 1) {
            return ['success' => false, 'code' => 'product_unavailable', 'message' => 'Product is not available'];
        }
        $inventorySnapshot = cgoGetProductInventoryStatus($productId);
        $snapshotAge = $inventorySnapshot['age_seconds'] ?? null;
        $snapshotStock = $inventorySnapshot['stock'] ?? null;
        $freshNegativeSnapshot = !cgoCheckoutLiveInventoryRefreshEnabled()
            && !empty($inventorySnapshot['exists'])
            && is_int($snapshotAge)
            && $snapshotAge <= cgoInventoryCacheTtlSeconds()
            && is_int($snapshotStock)
            && $snapshotStock < $quantity;
        if ($freshNegativeSnapshot) {
            return [
                'success' => false,
                'code' => $snapshotStock < 1 ? 'supplier_out_of_stock' : 'supplier_stock_insufficient',
                'inventory_refreshed' => false,
                'reload_storefront' => false,
                'available_stock' => max(0, $snapshotStock),
                'message' => $snapshotStock < 1
                    ? 'The latest cached supplier snapshot is sold out. Stock refresh continues in the background.'
                    : 'The latest cached supplier stock is lower than the requested quantity.',
            ];
        }
        $user = null;
        $customerName = '';
        $customerEmail = '';
        $role = 'reseller';
        if ($externalBilling) {
            $customerName = trim((string) ($purchaseContext['customer_name'] ?? 'Store API'));
            if ($customerName === '') $customerName = 'Store API';
            $customerName = substr($customerName, 0, 190);
            $customerEmail = trim((string) ($purchaseContext['customer_email'] ?? ''));
            if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) $customerEmail = '';
        } else {
            $user = getUserById($userId);
            if (!$user || (string) ($user['status'] ?? '') !== 'active' || !in_array((string) ($user['role'] ?? ''), ['user', 'reseller', 'admin'], true)) {
                return ['success' => false, 'message' => 'User account is not active'];
            }
            if (!cgoLiveOrderAllowedForRole((string) ($user['role'] ?? ''))) {
                return ['success' => false, 'message' => cgoLiveOrderDisabledMessage()];
            }
            $role = (string) $user['role'];
            $customerName = trim((string) ($user['username'] ?? ''));
            $customerEmail = trim((string) ($user['email'] ?? ''));
            if ($customerName === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'A valid account name and email are required before ordering'];
            }
        }

        $mapping = null;
        if ($localProductId > 0 || $localVariantId > 0) {
            if ($localProductId < 1 || $localVariantId < 1) {
                return ['success' => false, 'message' => 'The local product mapping is incomplete'];
            }
            $mapping = cgoGetCatalogLinkByProduct($productId);
            if (!$mapping
                || (int) $mapping['local_product_id'] !== $localProductId
                || (int) $mapping['local_variant_id'] !== $localVariantId
                || empty($mapping['api_fallback_enabled'])
                || (string) ($mapping['local_product_status'] ?? '') !== 'active'
                || (string) ($mapping['local_variant_status'] ?? '') !== 'active') {
                return ['success' => false, 'message' => 'The API product is not connected to an active local variant'];
            }
        }

        // The merged storefront owns the selling price. Supplier sync updates the
        // local variant only when the administrator enables price synchronization;
        // otherwise manually edited local prices remain authoritative.
        if ($externalBilling) {
            $unitPrice = round((float) ($purchaseContext['unit_price'] ?? 0), 2);
        } elseif ($mapping) {
            $unitPrice = $role === 'reseller'
                ? (float) ($mapping['local_price_reseller'] ?? 0)
                : (float) ($mapping['local_price_user'] ?? 0);
        } else {
            $unitPrice = $role === 'reseller'
                ? (float) $product['reseller_price_base']
                : (float) $product['user_price_base'];
        }
        $overrideDetails = null;
        if (!$externalBilling && in_array($role, ['user', 'reseller'], true) && $localVariantId > 0) {
            $overrideDetails = getResellerVariantPriceDetails($userId, $localVariantId);
            if ($overrideDetails) {
                $unitPrice = (float) $overrideDetails['custom_price'];
            }
        }
        $unitPrice = round($unitPrice, 2);
        if (!is_finite($unitPrice) || $unitPrice <= 0 || $unitPrice > 10000000) {
            return ['success' => false, 'message' => 'Product price is invalid'];
        }
        $unitCost = round((float) $product['cost_base'], 2);
        $priceCoversCost = $externalBilling ? ($unitPrice + 0.00001 >= $unitCost) : resellerVariantPriceAllowsCost($overrideDetails, $unitPrice, $unitCost);
        if (!is_finite($unitCost) || $unitCost < 0 || !$priceCoversCost) {
            return ['success' => false, 'code' => 'price_below_supplier_cost', 'message' => 'This API item is temporarily unavailable while its selling price is reviewed. No balance was charged.'];
        }
        $totalPrice = round($unitPrice * $quantity, 2);
        $totalCost = round($unitCost * $quantity, 2);
        if (!is_finite($totalPrice) || $totalPrice <= 0 || $totalPrice > 100000000) {
            return ['success' => false, 'message' => 'Order total is invalid'];
        }

        $externalRef = cgoGenerateExternalRef();
        if ($externalRef === '') {
            return ['success' => false, 'message' => 'Unable to create a site-specific external reference.'];
        }
        $orderId = 0;
        $transactionId = 0;
        $reservationStartedAtMs = (int) round(microtime(true) * 1000);
        $checkoutTiming['local_validation_ms'] = max(0, $reservationStartedAtMs - $lockedStartedAtMs);
        $reservationCommitAttempted = false;
        $reservationCommitSucceeded = false;

        $conn->begin_transaction();
        try {
            $remoteProductId = (string) $product['remote_product_id'];
            if ($externalBilling) {
                $insert = $conn->prepare("INSERT INTO cgo_orders
                    (external_ref, user_id, cgo_product_id, remote_product_id, quantity, customer_name, customer_email,
                     local_product_id, local_variant_id, unit_cost_base, total_cost_base, unit_price_base, total_price_base,
                     status, source_kind, source_order_id)
                    VALUES (?,0,?,?,?, ?, ?, NULLIF(?,0),NULLIF(?,0),?,?,?,?, 'submitting','store_api',?)");
                if (!$insert) throw new RuntimeException('external order insert prepare failed');
                $insert->bind_param('sisissiiddddi', $externalRef, $productId, $remoteProductId, $quantity, $customerName, $customerEmail, $localProductId, $localVariantId, $unitCost, $totalCost, $unitPrice, $totalPrice, $sourceOrderId);
                if (!$insert->execute()) {
                    $insert->close();
                    throw new RuntimeException('external order insert failed');
                }
                $orderId = (int) $conn->insert_id;
                $insert->close();
                $reservationCommitAttempted = true;
                if (!$conn->commit()) throw new RuntimeException('external order reservation commit failed');
                $reservationCommitSucceeded = true;
                $reservationFinishedAtMs = (int) round(microtime(true) * 1000);
                $checkoutTiming['external_reservation_ms'] = max(0, $reservationFinishedAtMs - $reservationStartedAtMs);
            } else {
                $lock = $conn->prepare("SELECT balance, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                if (!$lock) throw new RuntimeException('user lock prepare failed');
                $lock->bind_param('i', $userId);
                $lock->execute();
                $result = $lock->get_result();
                $lockedUser = $result ? $result->fetch_assoc() : null;
                $lock->close();
                if (!$lockedUser || (string) $lockedUser['status'] !== 'active') {
                    throw new RuntimeException('user unavailable');
                }
                if ((float) $lockedUser['balance'] + 0.00001 < $totalPrice) {
                    $conn->rollback();
                    return ['success' => false, 'message' => 'Insufficient balance'];
                }

                $insert = $conn->prepare("INSERT INTO cgo_orders
                    (external_ref, user_id, cgo_product_id, remote_product_id, quantity, customer_name, customer_email,
                     local_product_id, local_variant_id, unit_cost_base, total_cost_base, unit_price_base, total_price_base, status, source_kind)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, 'submitting','storefront')");
                if (!$insert) throw new RuntimeException('order insert prepare failed');
                $insert->bind_param('siisissiidddd', $externalRef, $userId, $productId, $remoteProductId, $quantity, $customerName, $customerEmail, $localProductId, $localVariantId, $unitCost, $totalCost, $unitPrice, $totalPrice);
                if (!$insert->execute()) {
                    $insert->close();
                    throw new RuntimeException('order insert failed');
                }
                $orderId = (int) $conn->insert_id;
                $insert->close();

                $debit = $conn->prepare('UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?');
                if (!$debit) throw new RuntimeException('debit prepare failed');
                $debit->bind_param('did', $totalPrice, $userId, $totalPrice);
                if (!$debit->execute() || $debit->affected_rows !== 1) {
                    $debit->close();
                    throw new RuntimeException('debit failed');
                }
                $debit->close();

                $productLabel = cgoProductDisplayTitle($product);
                $variantLabel = cgoProductVariantLabel($product);
                if ($variantLabel !== '') { $productLabel .= ' - ' . $variantLabel; }
                $description = 'CHEATGAME order ' . $externalRef . ': ' . $productLabel . ' x' . $quantity;
                $transactionId = (int) createTransaction($userId, 'cgo_purchase', $totalPrice, 'pending', $description, $orderId);
                if ($transactionId < 1) {
                    throw new RuntimeException('transaction insert failed');
                }
                $link = $conn->prepare('UPDATE cgo_orders SET transaction_id = ? WHERE id = ?');
                if (!$link) throw new RuntimeException('transaction link prepare failed');
                $link->bind_param('ii', $transactionId, $orderId);
                if (!$link->execute()) {
                    $link->close();
                    throw new RuntimeException('transaction link failed');
                }
                $link->close();
                $walletBefore = round((float) $lockedUser['balance'], 2);
                $walletAfter = round($walletBefore - $totalPrice, 2);
                if (!walletLedgerRecordMovement(
                    $userId, -$totalPrice, $walletBefore, $walletAfter,
                    'cgo_purchase', 'transaction:' . $transactionId, $orderId, $transactionId, null,
                    'ซื้อสินค้าผ่าน CGO x' . $quantity,
                    'Balance reserved for CGO order #' . $orderId . '.',
                    $externalRef, true
                )) {
                    throw new RuntimeException('purchase wallet audit failed');
                }
                $reservationCommitAttempted = true;
                if (!$conn->commit()) throw new RuntimeException('wallet reservation commit failed');
                $reservationCommitSucceeded = true;
                $reservationFinishedAtMs = (int) round(microtime(true) * 1000);
                $checkoutTiming['wallet_reservation_ms'] = max(0, $reservationFinishedAtMs - $reservationStartedAtMs);
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $commitUncertain = $reservationCommitAttempted && !$reservationCommitSucceeded;
            error_log('CGO order reservation failed' . ($commitUncertain ? ' (commit uncertain)' : '') . ': ' . $e->getMessage());

            // No supplier POST is sent before this commit. If COMMIT itself is
            // uncertain, resolve the local external_ref and release it safely.
            if ($commitUncertain) {
                $durableOrderId = 0;
                $lookupCompleted = false;
                try {
                    $lookup = $conn->prepare('SELECT id FROM cgo_orders WHERE external_ref = ? LIMIT 1');
                    if ($lookup) {
                        $lookup->bind_param('s', $externalRef);
                        if ($lookup->execute()) {
                            $lookupResult = $lookup->get_result();
                            $lookupRow = $lookupResult ? $lookupResult->fetch_assoc() : null;
                            $durableOrderId = (int) ($lookupRow['id'] ?? 0);
                            $lookupCompleted = true;
                        }
                        $lookup->close();
                    }
                } catch (Throwable $ignored) {}

                if ($durableOrderId > 0) {
                    $released = cgoRefundOrder($durableOrderId, 'Local reservation commit was uncertain before any supplier request was sent.', 'pre_delivery_transport_proof');
                    return [
                        'success' => false,
                        'pending' => !$released,
                        'code' => $released ? 'reservation_commit_released' : 'reservation_commit_uncertain',
                        'order_id' => $durableOrderId,
                        'supplier_submit_attempted' => false,
                        'safe_to_release_parent' => $externalBilling,
                        'message' => $released
                            ? 'The supplier order was not sent. The uncertain local reservation was released safely.'
                            : 'The supplier order was not sent, but the local reservation still requires reconciliation. Do not retry yet.',
                    ];
                }
                if ($lookupCompleted) {
                    return [
                        'success' => false,
                        'pending' => false,
                        'code' => 'reservation_not_committed',
                        'supplier_submit_attempted' => false,
                        'safe_to_release_parent' => $externalBilling,
                        'message' => 'The supplier order was not sent and no durable reservation was found.',
                    ];
                }
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'reservation_commit_uncertain',
                    'order_id' => $orderId > 0 ? $orderId : 0,
                    'supplier_submit_attempted' => false,
                    'safe_to_release_parent' => $externalBilling,
                    'message' => 'The supplier order was not sent, but the local reservation commit could not be verified. Do not retry yet.',
                ];
            }
            return ['success' => false, 'message' => 'Unable to reserve the order'];
        }

        if (!$externalBilling) $_SESSION['balance'] = getUserBalance($userId);
        $supplierSubmitStartedAtMs = (int) round(microtime(true) * 1000);
        $checkoutTiming['post_reservation_pre_submit_ms'] = isset($reservationFinishedAtMs)
            ? max(0, $supplierSubmitStartedAtMs - $reservationFinishedAtMs) : null;
        $api = cgoApiRequest('order', 'POST', [
            'external_ref' => $externalRef,
            'product_id' => is_numeric($product['remote_product_id']) ? (int) $product['remote_product_id'] : (string) $product['remote_product_id'],
            'quantity' => $quantity,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
        ], cgoOrderSubmitTimeoutSeconds());

        $responseJson = cgoOrderResponseJson($api);
        $submitDecision = 'submit_provider_failure';
        if (is_array($api['data'] ?? null) && cgoOrderStatusTerminalFailure($api['data'])) {
            $submitDecision = 'submit_failed_final';
        } elseif (!empty($api['ok']) && is_array($api['data'])) {
            $submitDecision = cgoApiExplicitlyRejected($api['data']) ? 'submit_rejected_nonfinal' : 'submit_response_received';
        } elseif (cgoOrderRequestDefinitelyNotDelivered($api)) {
            $submitDecision = 'submit_not_delivered';
        } elseif (!empty($api['transport_error']) || (int) ($api['http_code'] ?? 0) >= 500 || (int) ($api['http_code'] ?? 0) === 429 || (int) ($api['http_code'] ?? 0) === 0) {
            $submitDecision = 'submit_ambiguous';
        }
        $supplierSubmitFinishedAtMs = (int) round(microtime(true) * 1000);
        $checkoutTiming['supplier_submit_ms'] = max(0, (int) ($api['total_time_ms'] ?? ($supplierSubmitFinishedAtMs - $supplierSubmitStartedAtMs)));
        $checkoutTiming['supplier_request_started_at_ms'] = max(0, (int) ($api['request_started_at_ms'] ?? $supplierSubmitStartedAtMs));
        $checkoutTiming['supplier_request_finished_at_ms'] = max(0, (int) ($api['request_finished_at_ms'] ?? $supplierSubmitFinishedAtMs));
        $checkoutTiming['elapsed_to_submit_response_ms'] = max(0, $supplierSubmitFinishedAtMs - $checkoutStartedAtMs);
        $submitAttemptId = cgoRecordOrderApiAttempt($orderId, 'submit', $api, [
            'lookup_mode' => 'external_ref',
            'external_ref' => $externalRef,
            'checkout_timing' => $checkoutTiming,
        ], $submitDecision);

        if (!$api['ok'] || !is_array($api['data'])) {
            $httpCode = (int) ($api['http_code'] ?? 0);
            $reason = substr((string) ($api['error'] ?: ('Supplier request failed with HTTP ' . $httpCode)), 0, 2000);

            // Never refund a response that also contains evidence of an order or
            // delivery. Upstream error flags and delivery signals together are a
            // conflict, not a clean rejection. Preserve the evidence for review.
            if (is_array($api['data'])) {
                $failureSupplierOrderId = cgoExtractSupplierOrderId($api['data']);
                $failureKeys = cgoExtractKeys($api['data']);
                // A supplier order_id can legitimately accompany failed+final.
                // Delivery data, however, is incompatible with an automatic
                // refund and always wins as a conflict signal.
                if ($failureKeys !== []) {
                    cgoStoreOrderKeys($orderId, $failureKeys);
                    $conflictMessage = 'Supplier response contains both failure and delivery signals. Automatic refund is disabled pending reconciliation.';
                    $stmt = $conn->prepare("UPDATE cgo_orders SET status = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN status ELSE 'manual_review' END, supplier_order_id = COALESCE(?, supplier_order_id), response_json = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN response_json ELSE ? END, error_message = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN error_message ELSE ? END, updated_at=NOW() WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('sssi', $failureSupplierOrderId, $responseJson, $conflictMessage, $orderId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
                    if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Conflicting supplier response requires inventory refresh');
                    cgoCommerceCenterSyncSafe($orderId);
                    return [
                        'success' => false,
                        'pending' => true,
                        'code' => 'supplier_conflict',
                        'order_id' => $orderId,
                        'total' => $totalPrice,
                        'message' => $conflictMessage,
                    ];
                }
                if ($failureSupplierOrderId !== null && trim($failureSupplierOrderId) !== '') {
                    $track = $conn->prepare("UPDATE cgo_orders SET supplier_order_id = COALESCE(?, supplier_order_id), response_json = ? WHERE id = ?");
                    if ($track) {
                        $track->bind_param('ssi', $failureSupplierOrderId, $responseJson, $orderId);
                        $track->execute();
                        $track->close();
                    }
                }
            }

            // A provable DNS/connect/TLS failure before the supplier application
            // receives the POST cannot create an order, so it is safe to refund.
            // Ambiguous timeouts such as Cloudflare 522 deliberately stay pending.
            if (cgoOrderRequestDefinitelyNotDelivered($api)) {
                $stmt = $conn->prepare("UPDATE cgo_orders SET status = 'unknown', response_json = ?, error_message = ? WHERE id = ? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
                if ($stmt) {
                    $stmt->bind_param('ssi', $responseJson, $reason, $orderId);
                    $stmt->execute();
                    $stmt->close();
                }
                $refunded = cgoRefundOrder($orderId, $reason, 'pre_delivery_transport_proof');
                cgoInvalidateInventoryCache('Supplier origin was unreachable before order delivery');
                return [
                    'success' => false,
                    'pending' => !$refunded,
                    'balance_refunded' => $refunded,
                    'code' => $refunded ? 'supplier_unreachable_refunded' : 'supplier_refund_pending',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'reload_storefront' => true,
                    'message' => $refunded
                        ? 'The supplier could not receive the order. The reserved balance was refunded.'
                        : 'The supplier could not receive the order, but the automatic refund could not be verified. Do not retry until this order is reviewed.',
                ];
            }

            $ambiguous = !empty($api['transport_error']) || $httpCode >= 500 || $httpCode === 429 || $httpCode === 0;
            if ($ambiguous) {
                $stmt = $conn->prepare("UPDATE cgo_orders SET status = 'unknown', response_json = ?, error_message = ? WHERE id = ? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
                if ($stmt) {
                    $stmt->bind_param('ssi', $responseJson, $reason, $orderId);
                    $stmt->execute();
                    $stmt->close();
                }
                cgoInvalidateInventoryCache('Supplier order response was uncertain');
                cgoCommerceCenterSyncSafe($orderId);

                // Fast recovery stays inside the same customer request. The
                // provider contract now supplies a real fencing primitive:
                // order_not_found -> order_cancel(external_ref) -> sealed -> refund.
                // A processing result gets one short re-check, still within the
                // <20 second customer budget. We never refund from not_found alone.
                if (cgoOrderFastRecoveryEnabled()) {
                    $recovery = cgoReconcileOrder($orderId);
                    if (($recovery['code'] ?? '') === 'supplier_processing') {
                        $retryAfterMs = max(250, min(750, (int) ($recovery['retry_after_ms'] ?? 500)));
                        usleep($retryAfterMs * 1000);
                        $recovery = cgoReconcileOrder($orderId);
                    }
                    if (!empty($recovery['refunded'])) {
                        if (!$externalBilling) $_SESSION['balance'] = getUserBalance($userId);
                        return [
                            'success' => false,
                            'pending' => false,
                            'balance_refunded' => true,
                            'code' => (string) ($recovery['code'] ?? 'supplier_safe_fast_refund'),
                            'refund_authority' => $recovery['refund_authority'] ?? null,
                            'order_id' => $orderId,
                            'total' => $totalPrice,
                            'reload_storefront' => true,
                            'message' => (string) ($recovery['message'] ?? 'Supplier finality was verified. Balance refunded automatically.'),
                        ];
                    }
                    if (!empty($recovery['success']) && !empty($recovery['keys']) && is_array($recovery['keys'])) {
                        return [
                            'success' => true,
                            'pending' => false,
                            'order_id' => $orderId,
                            'keys' => $recovery['keys'],
                            'total' => $totalPrice,
                            'message' => 'Order recovered and completed after the initial response timeout.',
                        ];
                    }
                }

                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'supplier_response_uncertain',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => 'The supplier response is uncertain. Automatic fenced recovery is running; order_not_found alone will never trigger a refund.',
                ];
            }

            // A direct provider rejection is refundable only when the response
            // is explicitly final. Otherwise reserve the balance and use the same
            // status -> order_cancel fencing flow as an ambiguous timeout.
            if (is_array($api['data']) && cgoOrderStatusTerminalFailure($api['data'])) {
                $finalReason = cgoExtractErrorMessage($api['data'], $reason);
                $refunded = cgoRefundOrder($orderId, $finalReason, 'provider_failed_final');
                cgoInvalidateInventoryCache('Supplier returned a final failed order response');
                return [
                    'success' => false,
                    'pending' => !$refunded,
                    'balance_refunded' => $refunded,
                    'code' => $refunded ? 'supplier_failed_final_refunded' : 'supplier_refund_pending',
                    'refund_authority' => 'provider_failed_final',
                    'inventory_refreshed' => false,
                    'reload_storefront' => true,
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => $refunded ? ($finalReason . ' Balance refunded.') : ($finalReason . ' Final failure was confirmed, but the local refund could not be committed.'),
                ];
            }

            $stmt = $conn->prepare("UPDATE cgo_orders SET status='unknown', response_json=?, error_message=?, updated_at=NOW() WHERE id=? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
            if ($stmt) {
                $stmt->bind_param('ssi', $responseJson, $reason, $orderId);
                $stmt->execute();
                $stmt->close();
            }
            $recovery = cgoReconcileOrder($orderId);
            if (!empty($recovery['refunded'])) {
                if (!$externalBilling) $_SESSION['balance'] = getUserBalance($userId);
                return [
                    'success' => false,
                    'pending' => false,
                    'balance_refunded' => true,
                    'code' => (string) ($recovery['code'] ?? 'supplier_safe_refund'),
                    'refund_authority' => $recovery['refund_authority'] ?? null,
                    'inventory_refreshed' => false,
                    'reload_storefront' => true,
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => (string) ($recovery['message'] ?? 'Supplier finality was verified and the balance was refunded.'),
                ];
            }
            return [
                'success' => false,
                'pending' => true,
                'balance_refunded' => false,
                'code' => (string) ($recovery['code'] ?? 'supplier_rejection_nonfinal'),
                'inventory_refreshed' => false,
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => (string) ($recovery['message'] ?? 'Supplier rejection is not final yet; automatic fenced recovery is continuing.'),
            ];
        }

        $data = $api['data'];
        if (cgoApiExplicitlyRejected($data)) {
            $message = cgoExtractErrorMessage($data, 'Supplier rejected the order');
            $conflictingOrderId = cgoExtractSupplierOrderId($data);
            $conflictingKeys = cgoExtractKeys($data);
            if ($conflictingKeys !== []) {
                cgoStoreOrderKeys($orderId, $conflictingKeys);
                $conflictMessage = 'Supplier response contains both rejection and delivery signals. Automatic refund is disabled pending manual review.';
                $stmt = $conn->prepare("UPDATE cgo_orders SET status = CASE WHEN status IN ('refunded','refunded_conflict') THEN 'refunded_conflict' WHEN status IN ('success','completed') THEN status ELSE 'manual_review' END, supplier_order_id = COALESCE(?, supplier_order_id), response_json = CASE WHEN status IN ('success','completed') THEN response_json ELSE ? END, error_message = CASE WHEN status IN ('success','completed') THEN error_message ELSE ? END WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('sssi', $conflictingOrderId, $responseJson, $conflictMessage, $orderId);
                    $stmt->execute();
                    $stmt->close();
                }
                cgoInvalidateInventoryCache('Supplier returned conflicting order signals');
                cgoCommerceCenterSyncSafe($orderId);
                return ['success' => false, 'pending' => true, 'code' => 'supplier_conflict', 'order_id' => $orderId, 'total' => $totalPrice, 'message' => $conflictMessage];
            }
            if ($conflictingOrderId !== null && trim($conflictingOrderId) !== '') {
                $track = $conn->prepare("UPDATE cgo_orders SET supplier_order_id = COALESCE(?, supplier_order_id), response_json = ? WHERE id = ?");
                if ($track) {
                    $track->bind_param('ssi', $conflictingOrderId, $responseJson, $orderId);
                    $track->execute();
                    $track->close();
                }
            }
            if (cgoOrderStatusTerminalFailure($data)) {
                $refunded = cgoRefundOrder($orderId, $message, 'provider_failed_final');
                cgoInvalidateInventoryCache('Supplier returned a final failed order response');
                return [
                    'success' => false,
                    'pending' => !$refunded,
                    'balance_refunded' => $refunded,
                    'code' => $refunded ? 'supplier_failed_final_refunded' : 'supplier_refund_pending',
                    'refund_authority' => 'provider_failed_final',
                    'inventory_refreshed' => false,
                    'reload_storefront' => true,
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => $refunded ? ($message . ' Balance refunded.') : ($message . ' Final failure was confirmed, but the local refund could not be committed.'),
                ];
            }
            $stmt = $conn->prepare("UPDATE cgo_orders SET status='unknown', response_json=?, error_message=?, updated_at=NOW() WHERE id=? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
            if ($stmt) {
                $stmt->bind_param('ssi', $responseJson, $message, $orderId);
                $stmt->execute();
                $stmt->close();
            }
            $recovery = cgoReconcileOrder($orderId);
            return [
                'success' => !empty($recovery['success']) && !empty($recovery['keys']),
                'pending' => empty($recovery['refunded']) && empty($recovery['keys']),
                'balance_refunded' => !empty($recovery['refunded']),
                'code' => (string) ($recovery['code'] ?? 'supplier_rejection_nonfinal'),
                'refund_authority' => $recovery['refund_authority'] ?? null,
                'inventory_refreshed' => false,
                'reload_storefront' => !empty($recovery['refunded']),
                'order_id' => $orderId,
                'keys' => isset($recovery['keys']) && is_array($recovery['keys']) ? $recovery['keys'] : [],
                'total' => $totalPrice,
                'message' => (string) ($recovery['message'] ?? 'Supplier rejection is not final yet; automatic fenced recovery is continuing.'),
            ];
        }

        if (!cgoApiAccepted($data)) {
            $message = 'Supplier returned an unrecognized response. The balance remains reserved for manual verification.';
            $stmt = $conn->prepare("UPDATE cgo_orders SET status = 'unknown', response_json = ?, error_message = ? WHERE id = ? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
            if ($stmt) {
                $stmt->bind_param('ssi', $responseJson, $message, $orderId);
                $stmt->execute();
                $stmt->close();
            }
            cgoInvalidateInventoryCache('Supplier returned an unrecognized order response');
            cgoCommerceCenterSyncSafe($orderId);
            return ['success' => false, 'pending' => true, 'code' => 'supplier_response_unrecognized', 'order_id' => $orderId, 'total' => $totalPrice, 'message' => $message];
        }

        $supplierOrderId = cgoExtractSupplierOrderId($data);
        $keys = cgoExtractKeys($data);
        $keyStorageOk = true;
        if ($keys !== []) {
            cgoStoreOrderKeys($orderId, $keys);
            $keyStorageOk = cgoOrderHasKeys($orderId, $keys);
        }
        $keyCountMatches = $keys === [] || count($keys) === $quantity;
        $trackingMissing = $keys === [] && ($supplierOrderId === null || trim($supplierOrderId) === '');
        $statusName = $keys !== []
            ? (($keyStorageOk && $keyCountMatches) ? 'success' : 'manual_review')
            : ($trackingMissing ? 'unknown' : 'processing');
        $storageError = null;
        if (!$keyStorageOk) {
            $storageError = 'Supplier returned product keys, but local key storage could not be verified.';
        } elseif (!$keyCountMatches) {
            $storageError = 'Supplier returned ' . count($keys) . ' key(s) for an order quantity of ' . $quantity . '. Manual review is required.';
        } elseif ($trackingMissing) {
            $storageError = 'Supplier accepted or queued the request without a supplier order ID or delivery. The balance remains reserved while external_ref/webhook recovery checks the order.';
        }
        // Commit the local order state and its financial transaction status as
        // one unit. Lock the row first because order.success webhook can arrive
        // before this original POST response returns. A stale processing response
        // must never downgrade a webhook-completed transaction to pending, and a
        // late accepted response after a refund must become refunded_conflict
        // rather than silently reviving the order.
        $desiredTxStatus = $statusName === 'success' ? 'completed' : 'pending';
        $localCommitOk = false;
        $localCommitError = '';
        $terminalRaceStatus = '';
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT status FROM cgo_orders WHERE id = ? LIMIT 1 FOR UPDATE");
            if (!$lock) throw new RuntimeException('accepted order state lock prepare failed');
            $lock->bind_param('i', $orderId);
            if (!$lock->execute()) { $lock->close(); throw new RuntimeException('accepted order state lock failed'); }
            $lockResult = $lock->get_result();
            $lockedRow = $lockResult ? $lockResult->fetch_assoc() : null;
            $lock->close();
            if (!$lockedRow) throw new RuntimeException('accepted order disappeared');
            $lockedStatus = strtolower(trim((string) ($lockedRow['status'] ?? '')));

            if (in_array($lockedStatus, ['success', 'completed'], true)) {
                $terminalRaceStatus = 'success';
                // Webhook/status reconciliation already finalized the order.
                // Only fill a missing supplier ID; preserve its authoritative
                // final response/error fields and keep the transaction completed.
                if ($supplierOrderId !== null && trim($supplierOrderId) !== '') {
                    $track = $conn->prepare("UPDATE cgo_orders SET supplier_order_id = COALESCE(NULLIF(?, ''), supplier_order_id) WHERE id = ?");
                    if (!$track) throw new RuntimeException('accepted terminal tracking prepare failed');
                    $track->bind_param('si', $supplierOrderId, $orderId);
                    if (!$track->execute()) { $track->close(); throw new RuntimeException('accepted terminal tracking failed'); }
                    $track->close();
                }
                if ($transactionId > 0 && !updateTransactionStatus($transactionId, 'completed')) {
                    throw new RuntimeException('accepted terminal transaction completion failed');
                }
            } elseif (in_array($lockedStatus, ['refunded', 'refunded_conflict'], true)) {
                $terminalRaceStatus = 'refunded_conflict';
                $conflictMessage = 'Supplier accepted or delivered an order after the local order had already been refunded. Automatic re-debit is prohibited.';
                $conflict = $conn->prepare("UPDATE cgo_orders SET status='refunded_conflict', supplier_order_id = COALESCE(NULLIF(?, ''), supplier_order_id), response_json = ?, error_message = ?, updated_at=NOW() WHERE id = ?");
                if (!$conflict) throw new RuntimeException('accepted-after-refund conflict prepare failed');
                $supplierOrderIdValue = (string) ($supplierOrderId ?? '');
                $conflict->bind_param('sssi', $supplierOrderIdValue, $responseJson, $conflictMessage, $orderId);
                if (!$conflict->execute()) { $conflict->close(); throw new RuntimeException('accepted-after-refund conflict update failed'); }
                $conflict->close();
                // Keep the already-failed transaction/refund ledger untouched.
            } else {
                $stmt = $conn->prepare("UPDATE cgo_orders SET status = ?, supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = ?, completed_at = IF(? = 'success', COALESCE(completed_at, NOW()), completed_at) WHERE id = ?");
                if (!$stmt) throw new RuntimeException('accepted order update prepare failed');
                $stmt->bind_param('sssssi', $statusName, $supplierOrderId, $responseJson, $storageError, $statusName, $orderId);
                if (!$stmt->execute() || $stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('accepted order update failed'); }
                $stmt->close();
                if ($transactionId > 0 && !updateTransactionStatus($transactionId, $desiredTxStatus)) {
                    throw new RuntimeException('accepted order transaction update failed');
                }
            }
            if (!$conn->commit()) throw new RuntimeException('accepted order local commit failed');
            $localCommitOk = true;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $localCommitError = $e->getMessage();
        }
        if (!$localCommitOk) {
            error_log('CGO supplier accepted order ' . $externalRef . ' but local order/transaction commit failed: ' . $localCommitError);
            $safeMessage = 'Supplier accepted the order, but local completion recording failed. Automatic reconciliation will continue; this order must not be submitted again.';
            $review = $conn->prepare("UPDATE cgo_orders SET status = IF(status IN ('refunded','refunded_conflict','success','completed'), status, 'manual_review'), error_message = IF(status IN ('success','completed'), error_message, ?), updated_at=NOW() WHERE id = ?");
            if ($review) {
                $review->bind_param('si', $safeMessage, $orderId);
                $review->execute();
                $review->close();
            }
            if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
            if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Accepted order inventory adjustment failed');
            cgoCommerceCenterSyncSafe($orderId);
            return [
                'success' => false,
                'pending' => true,
                'code' => 'local_order_recording_pending',
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => $safeMessage,
            ];
        }
        if ($terminalRaceStatus === 'refunded_conflict') {
            cgoInvalidateInventoryCache('Supplier accepted an order after local refund; provider/local state conflict');
            cgoCommerceCenterSyncSafe($orderId);
            return [
                'success' => false,
                'pending' => true,
                'code' => 'supplier_after_refund_conflict',
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => 'Supplier reported an accepted order after the local refund. The refund was not reversed automatically; reconciliation evidence was preserved.',
            ];
        }
        if ($terminalRaceStatus === 'success') {
            // A webhook/status request won the race and completed the order first.
            // Return the authoritative locally stored keys rather than an older
            // processing response that may not contain delivery data.
            $storedKeys = [];
            $keyStmt = $conn->prepare('SELECT key_code FROM cgo_order_keys WHERE order_id = ? ORDER BY id ASC');
            if ($keyStmt) {
                $keyStmt->bind_param('i', $orderId);
                if ($keyStmt->execute()) {
                    $keyResult = $keyStmt->get_result();
                    while ($row = $keyResult ? $keyResult->fetch_assoc() : null) {
                        if (!$row) break;
                        $value = trim((string) ($row['key_code'] ?? ''));
                        if ($value !== '') $storedKeys[] = $value;
                    }
                }
                $keyStmt->close();
            }
            $keys = array_values(array_unique($storedKeys));
            if (count($keys) !== $quantity) {
                cgoInvalidateInventoryCache('Terminal success won the race but the authoritative local key set could not be read completely');
                cgoCommerceCenterSyncSafe($orderId);
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'terminal_success_key_read_pending',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => 'The order completed in another request, but the delivered key set could not be read completely yet. Reload the order instead of submitting a new purchase.',
                ];
            }
            $statusName = 'success';
            $trackingMissing = false;
            $storageError = null;
        }
        if (!cgoAdjustInventoryForAcceptedOrder($orderId)) {
            cgoInvalidateInventoryCache('Accepted order inventory adjustment failed');
        }
        if (!$externalBilling && $userId > 0) logHistory($userId, 'cgo_order', 'CHEATGAME order ' . $externalRef . ' accepted; status=' . $statusName . '; quantity=' . $quantity);
        cgoCommerceCenterSyncSafe($orderId);
        $checkoutFinishedAtMs = (int) round(microtime(true) * 1000);
        $checkoutTiming['local_finalize_ms'] = max(0, $checkoutFinishedAtMs - $supplierSubmitFinishedAtMs);
        $checkoutTiming['total_checkout_ms'] = max(0, $checkoutFinishedAtMs - $checkoutStartedAtMs);
        $checkoutTiming['checkout_finished_at_ms'] = $checkoutFinishedAtMs;
        $checkoutTiming['finalize_ms'] = $checkoutTiming['local_finalize_ms'];
        $checkoutTiming['final_state_source'] = $terminalRaceStatus === 'success' ? 'webhook_or_status_race' : 'submit';
        if ($submitAttemptId > 0) cgoUpdateOrderApiAttemptCheckoutTiming($submitAttemptId, $checkoutTiming);

        $isProcessing = $statusName === 'processing';
        $isPendingReview = in_array($statusName, ['unknown', 'manual_review'], true);
        return [
            'success' => in_array($statusName, ['success', 'processing'], true),
            'processing' => $isProcessing,
            'pending' => $isPendingReview,
            'code' => $trackingMissing ? 'supplier_tracking_missing' : ($statusName === 'manual_review' ? 'supplier_delivery_review' : ''),
            'order_id' => $orderId,
            'keys' => $statusName === 'success' ? $keys : [],
            'total' => $totalPrice,
            'message' => $statusName === 'success'
                ? 'Order completed'
                : ($statusName === 'processing'
                    ? 'Order accepted and is processing'
                    : ($statusName === 'unknown'
                        ? 'Order acceptance could not be tracked safely. The balance is reserved and automatic recovery is running; do not retry.'
                        : 'Order accepted, but the delivered key count requires manual review')),
        ];

    }
}


if (!function_exists('cgoProcureProductForStoreApi')) {
    /**
     * Procure one CGO product for an already-created Store API order. Billing is
     * owned by store_api_orders; this function never debits users.balance and
     * never creates a second customer transaction.
     */
    function cgoProcureProductForStoreApi(
        int $productId,
        int $storeApiOrderId,
        int $quantity,
        int $localProductId,
        int $localVariantId,
        float $unitPrice,
        string $customerName = '',
        string $customerEmail = ''
    ): array {
        $checkoutStartedAtMs = (int) round(microtime(true) * 1000);
        if ($productId < 1 || $storeApiOrderId < 1 || $quantity < 1 || $quantity > 100 || $localProductId < 1 || $localVariantId < 1 || $unitPrice <= 0 || !is_finite($unitPrice) || !cgoEnsureTables()) {
            return ['success' => false, 'code' => 'invalid_procurement_request', 'message' => 'Invalid CGO procurement request'];
        }
        $preLockProduct = cgoGetProductById($productId, false);
        if (!$preLockProduct) return ['success' => false, 'code' => 'product_unavailable', 'message' => 'CGO product is not available'];
        $remoteProductId = trim((string) ($preLockProduct['remote_product_id'] ?? ''));
        if ($remoteProductId === '') return ['success' => false, 'code' => 'product_unavailable', 'message' => 'CGO supplier product ID is missing'];

        $lockWaitStartedAtMs = (int) round(microtime(true) * 1000);
        $lock = cgoAcquireSupplierProductLock($remoteProductId, 1);
        $lockWaitFinishedAtMs = (int) round(microtime(true) * 1000);
        if (empty($lock['acquired'])) {
            return [
                'success' => false,
                'pending' => false,
                'code' => !empty($lock['busy']) ? 'supplier_product_busy' : 'supplier_lock_unavailable',
                'message' => !empty($lock['busy']) ? 'CGO is processing another order for this product; retry the same Store API order shortly.' : 'CGO order lock is unavailable.',
            ];
        }
        try {
            return cgoPurchaseProductLocked($productId, 0, $quantity, $localProductId, $localVariantId, [
                'checkout_started_at_ms' => $checkoutStartedAtMs,
                'prelock_lookup_ms' => max(0, $lockWaitStartedAtMs - $checkoutStartedAtMs),
                'lock_wait_ms' => max(0, $lockWaitFinishedAtMs - $lockWaitStartedAtMs),
            ], [
                'billing_mode' => 'external_store_api',
                'source_order_id' => $storeApiOrderId,
                'unit_price' => round($unitPrice, 2),
                'customer_name' => substr(trim($customerName), 0, 190),
                'customer_email' => substr(trim($customerEmail), 0, 190),
            ]);
        } finally {
            cgoReleaseSupplierProductLock((string) ($lock['lock_name'] ?? ''));
        }
    }
}

if (!function_exists('cgoPurchaseProduct')) {
    function cgoPurchaseProduct(int $productId, int $userId, int $quantity = 1, int $localProductId = 0, int $localVariantId = 0): array
    {
        $checkoutStartedAtMs = (int) round(microtime(true) * 1000);
        if ($productId < 1 || $userId < 1 || $quantity < 1 || $quantity > 100 || $localProductId < 0 || $localVariantId < 0 || !cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Invalid purchase request'];
        }
        $preLockProduct = cgoGetProductById($productId, false);
        if (!$preLockProduct) {
            return ['success' => false, 'code' => 'product_unavailable', 'message' => 'Product is not available'];
        }
        $remoteProductId = trim((string) ($preLockProduct['remote_product_id'] ?? ''));
        if ($remoteProductId === '') {
            return ['success' => false, 'code' => 'product_unavailable', 'message' => 'Supplier product ID is missing'];
        }

        $lockWaitStartedAtMs = (int) round(microtime(true) * 1000);
        $lock = cgoAcquireSupplierProductLock($remoteProductId, 1);
        $lockWaitFinishedAtMs = (int) round(microtime(true) * 1000);
        if (empty($lock['acquired'])) {
            return [
                'success' => false,
                'code' => !empty($lock['busy']) ? 'supplier_product_busy' : 'supplier_lock_unavailable',
                'message' => !empty($lock['busy'])
                    ? 'Another supplier order is being submitted right now. No balance was charged; retry is available immediately.'
                    : 'The cross-site supplier order lock is unavailable. No balance was charged.',
            ];
        }

        try {
            return cgoPurchaseProductLocked($productId, $userId, $quantity, $localProductId, $localVariantId, [
                'checkout_started_at_ms' => $checkoutStartedAtMs,
                'prelock_lookup_ms' => max(0, $lockWaitStartedAtMs - $checkoutStartedAtMs),
                'lock_wait_ms' => max(0, $lockWaitFinishedAtMs - $lockWaitStartedAtMs),
            ]);
        } finally {
            cgoReleaseSupplierProductLock((string) ($lock['lock_name'] ?? ''));
        }
    }
}

if (!function_exists('cgoGetUnifiedUserKeys')) {
    /**
     * Return local and CHEATGAME-delivered keys in one newest-first list.
     * The original local-key records are left untouched; a source marker is
     * added only to the returned array for display purposes.
     */
    function cgoGetUnifiedUserKeys(int $userId): array
    {
        if ($userId < 1) return [];
        $local = getUserKeys($userId);
        foreach ($local as &$row) {
            if (!isset($row['source'])) $row['source'] = 'local';
        }
        unset($row);
        $api = cgoGetDeliveredKeysForUser($userId);
        $storeApi = supplierBridgeGetDeliveredKeysForUser($userId);
        $rows = array_merge($local, $api, $storeApi);
        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['sold_at'] ?? ''), (string) ($a['sold_at'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });
        return $rows;
    }
}


if (!function_exists('cgoCountUnifiedUserKeys')) {
    /**
     * Count stored delivered-key rows across local, CGO and Store Bridge
     * sources without loading the key payloads themselves.
     */
    function cgoCountUnifiedUserKeys(int $userId, string $search = ''): int
    {
        if ($userId < 1) return 0;
        $search = substr(trim($search), 0, 180);
        $local = function_exists('countUserKeys') ? countUserKeys($userId, $search) : count(getUserKeys($userId));
        $cgo = function_exists('cgoCountDeliveredKeysForUser') ? cgoCountDeliveredKeysForUser($userId, $search) : count(cgoGetDeliveredKeysForUser($userId));
        $supplier = function_exists('supplierBridgeCountDeliveredKeysForUser') ? supplierBridgeCountDeliveredKeysForUser($userId, $search) : count(supplierBridgeGetDeliveredKeysForUser($userId));
        return max(0, $local + $cgo + $supplier);
    }
}

if (!function_exists('cgoGetUnifiedUserKeysPage')) {
    /**
     * Read one globally sorted page without loading a user's entire historical
     * key collection into PHP. Each source only returns enough newest rows to
     * prove the requested global slice.
     *
     * @return array{rows: array<int,array<string,mixed>>, total:int, limit:int, offset:int, has_more:bool}
     */
    function cgoGetUnifiedUserKeysPage(int $userId, int $limit = 50, int $offset = 0, string $search = ''): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $search = substr(trim($search), 0, 180);
        if ($userId < 1) return ['rows' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'has_more' => false];

        // To determine global rows [offset, offset+limit), no source can
        // contribute an item ranked lower than offset+limit within that source.
        // Keep the common newest pages bounded. Very deep pages deliberately
        // fall back to the legacy complete read rather than silently returning
        // an incorrect/inaccessible history slice.
        $candidateLimit = $offset + $limit + 1;
        if ($candidateLimit <= 1000) {
            $local = getUserKeys($userId, $candidateLimit, 0, $search);
            foreach ($local as &$row) {
                if (!isset($row['source'])) $row['source'] = 'local';
            }
            unset($row);
            $cgo = cgoGetDeliveredKeysForUser($userId, $candidateLimit, 0, $search);
            $supplier = supplierBridgeGetDeliveredKeysForUser($userId, $candidateLimit, 0, $search);
        } else {
            $local = getUserKeys($userId);
            foreach ($local as &$row) {
                if (!isset($row['source'])) $row['source'] = 'local';
            }
            unset($row);
            $cgo = cgoGetDeliveredKeysForUser($userId);
            $supplier = supplierBridgeGetDeliveredKeysForUser($userId);
            if ($search !== '') {
                $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $filter = static function (array $row) use ($needle): bool {
                    $haystack = trim((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['key_code'] ?? '') . ' ' . (string) ($row['duration'] ?? ''));
                    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                    return strpos($haystack, $needle) !== false;
                };
                $local = array_values(array_filter($local, $filter));
                $cgo = array_values(array_filter($cgo, $filter));
                $supplier = array_values(array_filter($supplier, $filter));
            }
        }

        $rows = array_merge($local, $cgo, $supplier);
        usort($rows, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['sold_at'] ?? ''), (string) ($a['sold_at'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });

        $total = cgoCountUnifiedUserKeys($userId, $search);
        $pageRows = array_slice($rows, $offset, $limit);
        return [
            'rows' => $pageRows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($pageRows)) < $total,
        ];
    }
}

if (!function_exists('cgoGetUnifiedPurchaseGroups')) {
    /**
     * Build one history group per real checkout/order. New local purchases use
     * purchase_activity_events; legacy local rows fall back to one transaction
     * per group instead of guessing that every purchase in the same minute was
     * one checkout.
     */
    function cgoGetUnifiedPurchaseGroups(int $userId, string $role): array
    {
        global $conn;
        if ($userId < 1) return ['groups' => [], 'total_keys' => 0];
        $role = $role === 'reseller' ? 'reseller' : 'user';
        $user = getUserById($userId);
        if (!$user) return ['groups' => [], 'total_keys' => 0];

        $transactions = [];
        $txById = [];
        $txByTypeReference = [];
        $txStmt = $conn->prepare('SELECT id,type,amount,status,reference_id,created_at FROM transactions WHERE user_id=? ORDER BY id DESC');
        if ($txStmt) {
            $txStmt->bind_param('i', $userId);
            if ($txStmt->execute()) {
                $txResult = $txStmt->get_result();
                $transactions = $txResult ? $txResult->fetch_all(MYSQLI_ASSOC) : [];
            }
            $txStmt->close();
        }

        $currentBalance = (float) ($user['balance'] ?? 0);
        $balanceAfterTx = [];
        $paidByTx = [];
        foreach ($transactions as $transaction) {
            if (strtolower(trim((string) ($transaction['status'] ?? ''))) !== 'completed') continue;
            $txId = (int) ($transaction['id'] ?? 0);
            $type = strtolower(trim((string) ($transaction['type'] ?? '')));
            $referenceId = (int) ($transaction['reference_id'] ?? 0);
            $amount = round((float) ($transaction['amount'] ?? 0), 2);
            if ($txId > 0) {
                $txById[$txId] = $transaction;
                $balanceAfterTx[$txId] = $currentBalance;
                $paidByTx[$txId] = $amount;
            }
            if ($referenceId > 0 && $type !== '') {
                $mapKey = $type . ':' . $referenceId;
                if (!isset($txByTypeReference[$mapKey])) $txByTypeReference[$mapKey] = $txId;
            }

            if (in_array($type, ['deposit', 'manual_add'], true)) {
                $currentBalance -= $amount;
            } elseif (in_array($type, ['purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_purchase', 'manual_deduct'], true)) {
                $currentBalance += $amount;
            }
        }

        $orderTransactions = ['cgo' => [], 'supplier' => []];
        $loadOrderLinks = static function (string $table, string $type, string $source) use ($conn, $userId, $txById, $txByTypeReference, &$orderTransactions): void {
            if (!function_exists('commerceContextTableColumns')) return;
            $cols = commerceContextTableColumns($table);
            if (!commerceContextHasColumns($cols, ['id', 'user_id', 'status'])) return;
            $txSelect = !empty($cols['transaction_id']) ? 'COALESCE(o.transaction_id,0)' : '0';
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $stmt = $conn->prepare("SELECT o.id, {$txSelect} AS transaction_id FROM `{$table}` o WHERE o.user_id=? AND {$statusSql}");
            if (!$stmt) return;
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result ? $result->fetch_assoc() : null) {
                    if (!$row) break;
                    $orderId = (int) ($row['id'] ?? 0);
                    $txId = (int) ($row['transaction_id'] ?? 0);
                    if ($txId < 1 || !isset($txById[$txId])) {
                        $txId = (int) ($txByTypeReference[$type . ':' . $orderId] ?? 0);
                    }
                    if ($orderId > 0) $orderTransactions[$source][$orderId] = $txId;
                }
            }
            $stmt->close();
        };
        if (cgoEnsureTables()) $loadOrderLinks('cgo_orders', 'cgo_purchase', 'cgo');
        if (storeBridgeEnsureSchema()) $loadOrderLinks('supplier_orders', 'supplier_purchase', 'supplier');

        $keys = cgoGetUnifiedUserKeys($userId);
        $localTxIds = [];
        $remoteOrderCounts = [];
        foreach ($keys as $candidate) {
            $source = function_exists('commerceNormalizeSource')
                ? commerceNormalizeSource((string) ($candidate['source'] ?? 'local'), (string) ($candidate['id'] ?? ''))
                : (string) ($candidate['source'] ?? 'local');
            if ($source === '') $source = 'local';
            if ($source === 'local') {
                $txId = (int) ($candidate['transaction_id'] ?? 0);
                if ($txId > 0) $localTxIds[$txId] = $txId;
                continue;
            }
            if (!in_array($source, ['cgo', 'supplier'], true)) continue;
            $orderId = (int) ($candidate['order_id'] ?? 0);
            if ($orderId > 0) {
                $countKey = $source . ':' . $orderId;
                $remoteOrderCounts[$countKey] = ($remoteOrderCounts[$countKey] ?? 0) + 1;
            }
        }

        $localEventByTx = [];
        if ($localTxIds !== [] && function_exists('publicPurchaseActivityTableExists') && publicPurchaseActivityTableExists('purchase_activity_events')) {
            // Resolve transaction-to-checkout relationships in SQL once. The old
            // nested PHP loop compared every event with every key transaction and
            // became noticeably slower as a reseller's history grew.
            $eventStmt = $conn->prepare(
                "SELECT t.id AS transaction_id, MAX(e.id) AS event_id
                 FROM transactions t
                 JOIN purchase_activity_events e
                   ON e.user_id=t.user_id
                  AND LOWER(TRIM(COALESCE(e.source,'')))='local'
                  AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                 WHERE t.user_id=?
                   AND LOWER(TRIM(COALESCE(t.type,'')))='purchase'
                   AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                 GROUP BY t.id"
            );
            if ($eventStmt) {
                $eventStmt->bind_param('i', $userId);
                if ($eventStmt->execute()) {
                    $eventResult = $eventStmt->get_result();
                    while ($event = $eventResult ? $eventResult->fetch_assoc() : null) {
                        if (!$event) break;
                        $txId = (int) ($event['transaction_id'] ?? 0);
                        $eventId = (int) ($event['event_id'] ?? 0);
                        if ($txId > 0 && $eventId > 0 && isset($localTxIds[$txId])) {
                            $localEventByTx[$txId] = $eventId;
                        }
                    }
                }
                $eventStmt->close();
            }
        }

        $groups = [];
        foreach ($keys as $key) {
            $source = function_exists('commerceNormalizeSource')
                ? commerceNormalizeSource((string) ($key['source'] ?? 'local'), (string) ($key['id'] ?? ''))
                : (string) ($key['source'] ?? 'local');
            if ($source === '') $source = 'local';
            $soldAt = trim((string) ($key['sold_at'] ?? ''));
            $productId = (int) ($key['product_id'] ?? 0);
            $productName = trim((string) ($key['product_name'] ?? ''));
            $duration = trim((string) ($key['duration'] ?? ''));
            $fullName = function_exists('commerceComposeProductLabel')
                ? commerceComposeProductLabel($productName, $duration)
                : trim($productName . ($duration !== '' && strcasecmp($duration, 'Standard') !== 0 ? ' ' . $duration : ''));

            $orderId = (int) ($key['order_id'] ?? 0);
            $txId = (int) ($key['transaction_id'] ?? 0);
            if (in_array($source, ['cgo', 'supplier'], true)) {
                if ($txId < 1 && $orderId > 0) $txId = (int) ($orderTransactions[$source][$orderId] ?? 0);
                $groupKey = $source . ':order:' . $orderId;
            } else {
                $keyId = (int) ($key['id'] ?? 0);
                $eventId = $txId > 0 ? (int) ($localEventByTx[$txId] ?? 0) : 0;
                if ($eventId > 0) $groupKey = 'local:event:' . $eventId;
                elseif ($txId > 0) $groupKey = 'local:tx:' . $txId;
                else $groupKey = 'local:key:' . $keyId;
            }

            if (!isset($groups[$groupKey])) {
                $downloadUrl = '';
                if ($productId > 0) {
                    $download = getProductDownloadUrl($productId);
                    $downloadUrl = is_array($download) ? (string) ($download['url'] ?? '') : '';
                }
                $groups[$groupKey] = [
                    'product_name' => $fullName,
                    'date' => $soldAt,
                    'display_date' => $soldAt !== '' ? date('Y-m-d H:i', strtotime($soldAt)) : '',
                    'keys' => [],
                    'total_price' => 0.0,
                    'price_per_item' => 0.0,
                    'balance_after' => $txId > 0 && array_key_exists($txId, $balanceAfterTx) ? $balanceAfterTx[$txId] : null,
                    'download_url' => $downloadUrl,
                    'source' => $source,
                    'order_id' => $orderId,
                    'transaction_id' => $txId,
                    '_latest_tx_id' => $txId,
                ];
            }

            $groups[$groupKey]['keys'][] = (string) ($key['key_code'] ?? '');
            $price = isset($key['purchase_price']) && is_numeric($key['purchase_price'])
                ? (float) $key['purchase_price']
                : ($role === 'reseller' ? (float) ($key['price_reseller'] ?? 0) : (float) ($key['price_user'] ?? 0));

            if (in_array($source, ['cgo', 'supplier'], true) && $orderId > 0) {
                $countKey = $source . ':' . $orderId;
                $count = max(1, (int) ($remoteOrderCounts[$countKey] ?? 1));
                if ($txId > 0 && isset($paidByTx[$txId])) $price = round((float) $paidByTx[$txId] / $count, 2);
            } elseif ($txId > 0 && isset($paidByTx[$txId])) {
                $price = (float) $paidByTx[$txId];
            }
            $price = round(max(0.0, $price), 2);
            $groups[$groupKey]['total_price'] = round((float) $groups[$groupKey]['total_price'] + $price, 2);

            // For a multi-key local checkout, the highest transaction ID is the
            // last audit row and therefore corresponds to the real post-checkout
            // balance after the one atomic wallet debit.
            if ($txId > (int) ($groups[$groupKey]['_latest_tx_id'] ?? 0)) {
                $groups[$groupKey]['_latest_tx_id'] = $txId;
                $groups[$groupKey]['transaction_id'] = $txId;
                if (array_key_exists($txId, $balanceAfterTx)) $groups[$groupKey]['balance_after'] = $balanceAfterTx[$txId];
            }
            if ($soldAt !== '' && strcmp($soldAt, (string) ($groups[$groupKey]['date'] ?? '')) > 0) {
                $groups[$groupKey]['date'] = $soldAt;
                $groups[$groupKey]['display_date'] = date('Y-m-d H:i', strtotime($soldAt));
            }
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $count = max(1, count($group['keys'] ?? []));
            $group['price_per_item'] = round((float) ($group['total_price'] ?? 0) / $count, 2);
            unset($group['_latest_tx_id']);
        }
        unset($group);
        usort($groups, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return (int) ($b['transaction_id'] ?? 0) <=> (int) ($a['transaction_id'] ?? 0);
        });
        return ['groups' => $groups, 'total_keys' => count($keys)];
    }
}

if (!function_exists('cgoGetUnifiedPurchaseGroupsPage')) {
    /**
     * Return one checkout/order-safe purchase-history page. The normal path
     * pages lightweight group summaries first and hydrates key payloads only for
     * the selected groups, so one checkout can never be split across pages.
     *
     * Search is applied to lightweight source summaries first, then the selected
     * matching groups are hydrated in full so one checkout is never truncated.
     * Legacy storage anomalies or optimized-query failures fall back to the complete builder.
     *
     * @return array{groups:array<int,array<string,mixed>>,total_keys:int,limit:int,offset:int,has_more:bool,optimized:bool,fallback_reason:string}
     */
    function cgoGetUnifiedPurchaseGroupsPage(int $userId, string $role, int $limit = 50, int $offset = 0, string $search = ''): array
    {
        global $conn;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $search = substr(trim($search), 0, 180);
        $role = $role === 'reseller' ? 'reseller' : 'user';
        $empty = [
            'groups' => [], 'total_keys' => 0, 'limit' => $limit, 'offset' => $offset,
            'has_more' => false, 'optimized' => true, 'fallback_reason' => '',
        ];
        if ($userId < 1) return $empty;
        $user = getUserById($userId);
        if (!$user) return $empty;

        $legacyPageResult = static function (string $reason) use ($userId, $role, $limit, $offset, $search): array {
            $legacy = cgoGetUnifiedPurchaseGroups($userId, $role);
            $groups = is_array($legacy['groups'] ?? null) ? $legacy['groups'] : [];
            if ($search !== '') {
                $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
                $groups = array_values(array_filter($groups, static function (array $group) use ($needle): bool {
                    $haystack = trim((string) ($group['product_name'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($group['keys'] ?? []))));
                    $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                    return strpos($haystack, $needle) !== false;
                }));
            }
            $page = array_slice($groups, $offset, $limit);
            return [
                'groups' => $page,
                'total_keys' => (int) ($legacy['total_keys'] ?? 0),
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($page)) < count($groups),
                'optimized' => false,
                'fallback_reason' => $reason,
            ];
        };

        $fallbackReason = '';
        if (function_exists('cgoDeliveredKeyStorageIsPageSafe') && !cgoDeliveredKeyStorageIsPageSafe($userId)) {
            $fallbackReason = 'legacy_cgo_key_storage';
        }
        if ($fallbackReason !== '') return $legacyPageResult($fallbackReason);

        $candidateLimit = $offset + $limit + 1;
        $optimizedSearchFailed = false;
        $summaries = [];
        $activityAvailable = function_exists('publicPurchaseActivityTableExists')
            && publicPurchaseActivityTableExists('purchase_activity_events');

        // Modern local purchases: one purchase_activity_events row is the
        // authoritative checkout boundary. Only summary fields are read here.
        if ($activityAvailable) {
            $sql = "SELECT e.id AS group_id,MAX(t.id) AS transaction_id,MAX(k.sold_at) AS group_date
                    FROM purchase_activity_events e
                    JOIN transactions t
                      ON t.user_id=e.user_id
                     AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                     AND LOWER(TRIM(COALESCE(t.type,'')))='purchase'
                     AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                    JOIN `keys` k ON k.id=t.reference_id AND k.assigned_to=e.user_id
                    WHERE e.user_id=? AND LOWER(TRIM(COALESCE(e.source,'')))='local'";
            $eventTypes = 'i';
            $eventValues = [$userId];
            if ($search !== '') {
                $sql .= " AND EXISTS (
                            SELECT 1
                            FROM transactions st
                            JOIN `keys` sk ON sk.id=st.reference_id AND sk.assigned_to=e.user_id
                            LEFT JOIN products sp ON sp.id=sk.product_id
                            WHERE st.user_id=e.user_id
                              AND st.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                              AND LOWER(TRIM(COALESCE(st.type,'')))='purchase'
                              AND LOWER(TRIM(COALESCE(st.status,'')))='completed'
                              AND (LOCATE(?,COALESCE(sp.name,''))>0
                                   OR LOCATE(?,COALESCE(sk.duration,''))>0
                                   OR LOCATE(?,COALESCE(sk.key_code,''))>0)
                        )";
                $eventTypes .= 'sss';
                array_push($eventValues, $search, $search, $search);
            }
            $sql .= " GROUP BY e.id
                      ORDER BY group_date DESC,transaction_id DESC,e.id DESC
                      LIMIT " . (int) $candidateLimit;
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $summaryQueryOk = sakazukiBindStatementValues($stmt, $eventTypes, $eventValues) && $stmt->execute();
                if ($summaryQueryOk) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $summaries[] = [
                            'key' => 'local:event:' . (int) $row['group_id'],
                            'source' => 'local_event',
                            'id' => (int) $row['group_id'],
                            'date' => (string) ($row['group_date'] ?? ''),
                            'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                        ];
                    }
                } elseif ($search !== '') {
                    $optimizedSearchFailed = true;
                }
                $stmt->close();
            } elseif ($search !== '') {
                $optimizedSearchFailed = true;
            }
        }

        // Legacy local rows are one transaction/key per group unless an
        // authoritative purchase_activity_events range already owns the row.
        $legacySql = "SELECT lk.key_id,lk.transaction_id,lk.group_date
                      FROM (
                          SELECT k.id AS key_id,k.sold_at AS group_date,COALESCE(MAX(t.id),0) AS transaction_id
                          FROM `keys` k
                          LEFT JOIN transactions t
                            ON t.user_id=?
                           AND t.reference_id=k.id
                           AND LOWER(TRIM(COALESCE(t.type,'')))='purchase'
                           AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                          LEFT JOIN products sp ON sp.id=k.product_id
                          WHERE k.assigned_to=?";
        $legacyTypes = 'ii';
        $legacyValues = [$userId, $userId];
        if ($search !== '') {
            $legacySql .= " AND (LOCATE(?,COALESCE(sp.name,''))>0
                                 OR LOCATE(?,COALESCE(k.duration,''))>0
                                 OR LOCATE(?,COALESCE(k.key_code,''))>0)";
            $legacyTypes .= 'sss';
            array_push($legacyValues, $search, $search, $search);
        }
        $legacySql .= " GROUP BY k.id,k.sold_at
                      ) lk";
        if ($activityAvailable) {
            $legacySql .= " WHERE lk.transaction_id=0 OR NOT EXISTS (
                                SELECT 1 FROM purchase_activity_events e
                                WHERE e.user_id=?
                                  AND LOWER(TRIM(COALESCE(e.source,'')))='local'
                                  AND lk.transaction_id BETWEEN e.first_transaction_id AND e.last_transaction_id
                            )";
            $legacyTypes .= 'i';
            $legacyValues[] = $userId;
        }
        $legacySql .= " ORDER BY lk.group_date DESC,lk.transaction_id DESC,lk.key_id DESC LIMIT " . (int) $candidateLimit;
        $stmt = $conn->prepare($legacySql);
        if ($stmt) {
            $summaryQueryOk = sakazukiBindStatementValues($stmt, $legacyTypes, $legacyValues) && $stmt->execute();
            if ($summaryQueryOk) {
                $result = $stmt->get_result();
                while ($row = $result ? $result->fetch_assoc() : null) {
                    if (!$row) break;
                    $txId = (int) ($row['transaction_id'] ?? 0);
                    $keyId = (int) ($row['key_id'] ?? 0);
                    if ($keyId < 1) continue;
                    $summaries[] = [
                        'key' => $txId > 0 ? ('local:tx:' . $txId) : ('local:key:' . $keyId),
                        'source' => $txId > 0 ? 'local_tx' : 'local_key',
                        'id' => $txId > 0 ? $txId : $keyId,
                        'date' => (string) ($row['group_date'] ?? ''),
                        'transaction_id' => $txId,
                        'key_id' => $keyId,
                    ];
                }
            } elseif ($search !== '') {
                $optimizedSearchFailed = true;
            }
            $stmt->close();
        } elseif ($search !== '') {
            $optimizedSearchFailed = true;
        }

        if (cgoEnsureTables()) {
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $sql = "SELECT o.id,
                           COALESCE(NULLIF(o.transaction_id,0),(
                               SELECT MAX(t.id) FROM transactions t
                               WHERE t.user_id=o.user_id
                                 AND LOWER(TRIM(COALESCE(t.type,'')))='cgo_purchase'
                                 AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                                 AND t.reference_id=o.id
                           ),0) AS transaction_id,
                           COALESCE(o.completed_at,o.updated_at,o.created_at) AS group_date
                    FROM cgo_orders o
                    JOIN cgo_products cp ON cp.id=o.cgo_product_id
                    LEFT JOIN products lp ON lp.id=o.local_product_id
                    LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                    WHERE o.user_id=? AND {$statusSql}
                      AND EXISTS (SELECT 1 FROM cgo_order_keys ok WHERE ok.order_id=o.id)";
            $cgoTypes = 'i';
            $cgoValues = [$userId];
            if ($search !== '') {
                $sql .= " AND (
                            LOCATE(?,COALESCE(NULLIF(lp.name,''),CASE WHEN COALESCE(NULLIF(cp.brand,''),'')<>'' THEN CONCAT(cp.brand,' - ',cp.name) ELSE cp.name END,''))>0
                            OR LOCATE(?,COALESCE(NULLIF(pv.duration,''),NULLIF(cp.duration,''),''))>0
                            OR EXISTS (SELECT 1 FROM cgo_order_keys sok WHERE sok.order_id=o.id AND LOCATE(?,COALESCE(sok.key_code,''))>0)
                        )";
                $cgoTypes .= 'sss';
                array_push($cgoValues, $search, $search, $search);
            }
            $sql .= " ORDER BY group_date DESC,transaction_id DESC,o.id DESC
                      LIMIT " . (int) $candidateLimit;
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $summaryQueryOk = sakazukiBindStatementValues($stmt, $cgoTypes, $cgoValues) && $stmt->execute();
                if ($summaryQueryOk) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $orderId = (int) ($row['id'] ?? 0);
                        if ($orderId < 1) continue;
                        $summaries[] = [
                            'key' => 'cgo:order:' . $orderId,
                            'source' => 'cgo',
                            'id' => $orderId,
                            'date' => (string) ($row['group_date'] ?? ''),
                            'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                        ];
                    }
                } elseif ($search !== '') {
                    $optimizedSearchFailed = true;
                }
                $stmt->close();
            } elseif ($search !== '') {
                $optimizedSearchFailed = true;
            }
        }

        if (storeBridgeEnsureSchema()) {
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $sql = "SELECT o.id,
                           COALESCE(NULLIF(o.transaction_id,0),(
                               SELECT MAX(t.id) FROM transactions t
                               WHERE t.user_id=o.user_id
                                 AND LOWER(TRIM(COALESCE(t.type,'')))='supplier_purchase'
                                 AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                                 AND t.reference_id=o.id
                           ),0) AS transaction_id,
                           COALESCE(o.completed_at,o.updated_at,o.created_at) AS group_date
                    FROM supplier_orders o
                    LEFT JOIN products lp ON lp.id=o.local_product_id
                    LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                    LEFT JOIN supplier_products sp ON sp.id=o.supplier_product_id
                    WHERE o.user_id=? AND {$statusSql}
                      AND EXISTS (SELECT 1 FROM supplier_order_keys ok WHERE ok.order_id=o.id)";
            $supplierTypes = 'i';
            $supplierValues = [$userId];
            if ($search !== '') {
                $sql .= " AND (
                            LOCATE(?,COALESCE(NULLIF(lp.name,''),NULLIF(sp.name,''),''))>0
                            OR LOCATE(?,COALESCE(NULLIF(pv.duration,''),NULLIF(sp.duration,''),''))>0
                            OR EXISTS (SELECT 1 FROM supplier_order_keys sok WHERE sok.order_id=o.id AND LOCATE(?,COALESCE(sok.key_code,''))>0)
                        )";
                $supplierTypes .= 'sss';
                array_push($supplierValues, $search, $search, $search);
            }
            $sql .= " ORDER BY group_date DESC,transaction_id DESC,o.id DESC
                      LIMIT " . (int) $candidateLimit;
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $summaryQueryOk = sakazukiBindStatementValues($stmt, $supplierTypes, $supplierValues) && $stmt->execute();
                if ($summaryQueryOk) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $orderId = (int) ($row['id'] ?? 0);
                        if ($orderId < 1) continue;
                        $summaries[] = [
                            'key' => 'supplier:order:' . $orderId,
                            'source' => 'supplier',
                            'id' => $orderId,
                            'date' => (string) ($row['group_date'] ?? ''),
                            'transaction_id' => (int) ($row['transaction_id'] ?? 0),
                        ];
                    }
                } elseif ($search !== '') {
                    $optimizedSearchFailed = true;
                }
                $stmt->close();
            } elseif ($search !== '') {
                $optimizedSearchFailed = true;
            }
        }

        if ($search !== '' && $optimizedSearchFailed) {
            return $legacyPageResult('search_query_failed');
        }

        usort($summaries, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            $txCompare = (int) ($b['transaction_id'] ?? 0) <=> (int) ($a['transaction_id'] ?? 0);
            if ($txCompare !== 0) return $txCompare;
            return strcmp((string) ($b['key'] ?? ''), (string) ($a['key'] ?? ''));
        });

        // Defensive de-duplication: a local transaction must never be emitted as
        // both an event-owned group and a legacy group even if malformed event
        // ranges overlap. The legacy query already excludes this in SQL; the key
        // guard makes a future query change fail safe rather than duplicate UI.
        $uniqueSummaries = [];
        foreach ($summaries as $summary) {
            $summaryKey = (string) ($summary['key'] ?? '');
            if ($summaryKey === '' || isset($uniqueSummaries[$summaryKey])) continue;
            $uniqueSummaries[$summaryKey] = $summary;
        }
        $summaries = array_values($uniqueSummaries);
        usort($summaries, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            $txCompare = (int) ($b['transaction_id'] ?? 0) <=> (int) ($a['transaction_id'] ?? 0);
            if ($txCompare !== 0) return $txCompare;
            return strcmp((string) ($b['key'] ?? ''), (string) ($a['key'] ?? ''));
        });

        $selectedSummaries = array_slice($summaries, $offset, $limit);
        $hasMore = count($summaries) > ($offset + count($selectedSummaries));
        if ($selectedSummaries === []) {
            return [
                'groups' => [],
                'total_keys' => function_exists('cgoCountUnifiedUserKeys') ? cgoCountUnifiedUserKeys($userId) : 0,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => false,
                'optimized' => true,
                'fallback_reason' => '',
            ];
        }

        $eventIds = $localTxIds = $localKeyIds = $cgoOrderIds = $supplierOrderIds = [];
        foreach ($selectedSummaries as $summary) {
            $id = (int) ($summary['id'] ?? 0);
            if ($id < 1) continue;
            switch ((string) ($summary['source'] ?? '')) {
                case 'local_event': $eventIds[$id] = $id; break;
                case 'local_tx': $localTxIds[$id] = $id; break;
                case 'local_key': $localKeyIds[$id] = $id; break;
                case 'cgo': $cgoOrderIds[$id] = $id; break;
                case 'supplier': $supplierOrderIds[$id] = $id; break;
            }
        }

        $detailRows = [];
        $appendDetail = static function (string $groupKey, array $row) use (&$detailRows): void {
            if ($groupKey === '') return;
            if (!isset($detailRows[$groupKey])) $detailRows[$groupKey] = [];
            $detailRows[$groupKey][] = $row;
        };

        if ($eventIds !== []) {
            $idList = implode(',', array_map('intval', array_values($eventIds)));
            $sql = "SELECT e.id AS event_id,t.id AS transaction_id,t.amount,
                           k.id AS key_id,k.key_code,k.product_id,k.sold_at,k.price_user,k.price_reseller,k.duration,
                           COALESCE(p.name,'') AS product_name
                    FROM purchase_activity_events e
                    JOIN transactions t
                      ON t.user_id=e.user_id
                     AND t.id BETWEEN e.first_transaction_id AND e.last_transaction_id
                     AND LOWER(TRIM(COALESCE(t.type,'')))='purchase'
                     AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                    JOIN `keys` k ON k.id=t.reference_id AND k.assigned_to=e.user_id
                    LEFT JOIN products p ON p.id=k.product_id
                    WHERE e.user_id=? AND e.id IN ({$idList})
                    ORDER BY e.id ASC,k.sold_at DESC,t.id DESC,k.id DESC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $appendDetail('local:event:' . (int) $row['event_id'], $row);
                    }
                }
                $stmt->close();
            }
        }

        if ($localTxIds !== []) {
            $idList = implode(',', array_map('intval', array_values($localTxIds)));
            $sql = "SELECT t.id AS transaction_id,t.amount,
                           k.id AS key_id,k.key_code,k.product_id,k.sold_at,k.price_user,k.price_reseller,k.duration,
                           COALESCE(p.name,'') AS product_name
                    FROM transactions t
                    JOIN `keys` k ON k.id=t.reference_id AND k.assigned_to=t.user_id
                    LEFT JOIN products p ON p.id=k.product_id
                    WHERE t.user_id=? AND t.id IN ({$idList})
                      AND LOWER(TRIM(COALESCE(t.type,'')))='purchase'
                      AND LOWER(TRIM(COALESCE(t.status,'')))='completed'
                    ORDER BY t.id DESC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $appendDetail('local:tx:' . (int) $row['transaction_id'], $row);
                    }
                }
                $stmt->close();
            }
        }

        if ($localKeyIds !== []) {
            $idList = implode(',', array_map('intval', array_values($localKeyIds)));
            $sql = "SELECT 0 AS transaction_id,NULL AS amount,
                           k.id AS key_id,k.key_code,k.product_id,k.sold_at,k.price_user,k.price_reseller,k.duration,
                           COALESCE(p.name,'') AS product_name
                    FROM `keys` k
                    LEFT JOIN products p ON p.id=k.product_id
                    WHERE k.assigned_to=? AND k.id IN ({$idList})
                    ORDER BY k.sold_at DESC,k.id DESC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $appendDetail('local:key:' . (int) $row['key_id'], $row);
                    }
                }
                $stmt->close();
            }
        }

        if ($cgoOrderIds !== []) {
            $idList = implode(',', array_map('intval', array_values($cgoOrderIds)));
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $sql = "SELECT o.id AS order_id,COALESCE(o.transaction_id,0) AS transaction_id,o.unit_price_base,o.local_product_id,
                           COALESCE(o.completed_at,o.updated_at,o.created_at) AS sold_at,
                           ok.id AS key_id,ok.key_code,
                           COALESCE(NULLIF(lp.name,''),CASE WHEN COALESCE(NULLIF(cp.brand,''),'')<>'' THEN CONCAT(cp.brand,' - ',cp.name) ELSE cp.name END,'') AS product_name,
                           COALESCE(NULLIF(pv.duration,''),NULLIF(cp.duration,''),'') AS duration
                    FROM cgo_orders o
                    JOIN cgo_products cp ON cp.id=o.cgo_product_id
                    JOIN cgo_order_keys ok ON ok.order_id=o.id
                    LEFT JOIN products lp ON lp.id=o.local_product_id
                    LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                    WHERE o.user_id=? AND o.id IN ({$idList}) AND {$statusSql}
                    ORDER BY sold_at DESC,o.id DESC,ok.id ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $appendDetail('cgo:order:' . (int) $row['order_id'], $row);
                    }
                }
                $stmt->close();
            }
        }

        if ($supplierOrderIds !== []) {
            $idList = implode(',', array_map('intval', array_values($supplierOrderIds)));
            $statusSql = function_exists('commerceSuccessfulStatusSql')
                ? commerceSuccessfulStatusSql('o')
                : "LOWER(TRIM(COALESCE(o.status,''))) IN ('success','completed')";
            $sql = "SELECT o.id AS order_id,COALESCE(o.transaction_id,0) AS transaction_id,o.unit_price_base,o.local_product_id,
                           COALESCE(o.completed_at,o.updated_at,o.created_at) AS sold_at,
                           ok.id AS key_id,ok.key_code,
                           COALESCE(NULLIF(lp.name,''),NULLIF(sp.name,''),'') AS product_name,
                           COALESCE(NULLIF(pv.duration,''),NULLIF(sp.duration,''),'') AS duration
                    FROM supplier_orders o
                    JOIN supplier_order_keys ok ON ok.order_id=o.id
                    LEFT JOIN products lp ON lp.id=o.local_product_id
                    LEFT JOIN product_variants pv ON pv.id=o.local_variant_id
                    LEFT JOIN supplier_products sp ON sp.id=o.supplier_product_id
                    WHERE o.user_id=? AND o.id IN ({$idList}) AND {$statusSql}
                    ORDER BY sold_at DESC,o.id DESC,ok.id ASC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result ? $result->fetch_assoc() : null) {
                        if (!$row) break;
                        $appendDetail('supplier:order:' . (int) $row['order_id'], $row);
                    }
                }
                $stmt->close();
            }
        }

        // Summary queries already resolve the same completed type/reference
        // fallback used by the legacy builder. Propagate that authoritative
        // transaction ID into the hydrated key rows when old order rows did not
        // persist transaction_id themselves.
        foreach ($selectedSummaries as $summary) {
            $source = (string) ($summary['source'] ?? '');
            if (!in_array($source, ['cgo', 'supplier'], true)) continue;
            $txId = (int) ($summary['transaction_id'] ?? 0);
            if ($txId < 1) continue;
            $groupKey = $source . ':order:' . (int) ($summary['id'] ?? 0);
            if (!isset($detailRows[$groupKey]) || !is_array($detailRows[$groupKey])) continue;
            foreach ($detailRows[$groupKey] as &$detailRow) {
                if ((int) ($detailRow['transaction_id'] ?? 0) < 1) $detailRow['transaction_id'] = $txId;
            }
            unset($detailRow);
        }

        $selectedTxIds = [];
        foreach ($detailRows as $rows) {
            foreach ($rows as $row) {
                $txId = (int) ($row['transaction_id'] ?? 0);
                if ($txId > 0) $selectedTxIds[$txId] = $txId;
            }
        }

        $currentBalance = (float) ($user['balance'] ?? 0);
        $balanceAfterTx = [];
        $paidByTx = [];
        if ($selectedTxIds !== []) {
            $minTxId = min(array_values($selectedTxIds));
            $stmt = $conn->prepare("SELECT id,type,amount,status FROM transactions WHERE user_id=? AND id>=? AND LOWER(TRIM(COALESCE(status,'')))='completed' ORDER BY id DESC");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $minTxId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($tx = $result ? $result->fetch_assoc() : null) {
                        if (!$tx) break;
                        $txId = (int) ($tx['id'] ?? 0);
                        $amount = round((float) ($tx['amount'] ?? 0), 2);
                        if (isset($selectedTxIds[$txId])) {
                            $balanceAfterTx[$txId] = $currentBalance;
                            $paidByTx[$txId] = $amount;
                        }
                        $type = strtolower(trim((string) ($tx['type'] ?? '')));
                        if (in_array($type, ['deposit', 'manual_add'], true)) {
                            $currentBalance -= $amount;
                        } elseif (in_array($type, ['purchase', 'cgo_purchase', 'supplier_purchase', 'store_api_purchase', 'manual_deduct'], true)) {
                            $currentBalance += $amount;
                        }
                    }
                }
                $stmt->close();
            }
        }

        $groupsByKey = [];
        foreach ($selectedSummaries as $summary) {
            $groupKey = (string) ($summary['key'] ?? '');
            $rows = $detailRows[$groupKey] ?? [];
            if ($groupKey === '' || $rows === []) continue;
            $source = (string) ($summary['source'] ?? '');
            $first = $rows[0];
            $productId = (int) ($first['product_id'] ?? $first['local_product_id'] ?? 0);
            $productName = trim((string) ($first['product_name'] ?? ''));
            $duration = trim((string) ($first['duration'] ?? ''));
            $fullName = function_exists('commerceComposeProductLabel')
                ? commerceComposeProductLabel($productName, $duration)
                : trim($productName . ($duration !== '' && strcasecmp($duration, 'Standard') !== 0 ? ' ' . $duration : ''));
            $downloadUrl = '';
            if ($productId > 0) {
                $download = getProductDownloadUrl($productId);
                $downloadUrl = is_array($download) ? (string) ($download['url'] ?? '') : '';
            }

            $keys = [];
            $totalPrice = 0.0;
            $latestTxId = 0;
            $latestDate = '';
            foreach ($rows as $row) {
                $keyCode = (string) ($row['key_code'] ?? '');
                if ($keyCode !== '') $keys[] = $keyCode;
                $txId = (int) ($row['transaction_id'] ?? 0);
                if ($txId > $latestTxId) $latestTxId = $txId;
                $soldAt = trim((string) ($row['sold_at'] ?? ''));
                if ($soldAt !== '' && ($latestDate === '' || strcmp($soldAt, $latestDate) > 0)) $latestDate = $soldAt;

                if (in_array($source, ['cgo', 'supplier'], true)) {
                    continue; // Remote total is applied once below.
                }
                $price = $txId > 0 && isset($paidByTx[$txId])
                    ? (float) $paidByTx[$txId]
                    : ($role === 'reseller' ? (float) ($row['price_reseller'] ?? 0) : (float) ($row['price_user'] ?? 0));
                $totalPrice = round($totalPrice + max(0.0, $price), 2);
            }

            if (in_array($source, ['cgo', 'supplier'], true)) {
                $txId = $latestTxId;
                $remoteCount = max(1, count($keys));
                if ($txId > 0 && isset($paidByTx[$txId])) {
                    // Preserve the legacy history display exactly: it divided
                    // the completed transaction amount per delivered key, rounded
                    // that unit value to 2 decimals, then summed the key rows.
                    $unit = round((float) $paidByTx[$txId] / $remoteCount, 2);
                    $totalPrice = round($unit * $remoteCount, 2);
                } else {
                    $unit = (float) ($first['unit_price_base'] ?? 0);
                    $totalPrice = round(max(0.0, $unit) * $remoteCount, 2);
                }
            }

            $count = max(1, count($keys));
            $groupsByKey[$groupKey] = [
                'product_name' => $fullName,
                'date' => $latestDate !== '' ? $latestDate : (string) ($summary['date'] ?? ''),
                'display_date' => ($latestDate !== '' || (string) ($summary['date'] ?? '') !== '')
                    ? date('Y-m-d H:i', strtotime($latestDate !== '' ? $latestDate : (string) $summary['date']))
                    : '',
                'keys' => $keys,
                'total_price' => $totalPrice,
                'price_per_item' => round($totalPrice / $count, 2),
                'balance_after' => $latestTxId > 0 && array_key_exists($latestTxId, $balanceAfterTx) ? $balanceAfterTx[$latestTxId] : null,
                'download_url' => $downloadUrl,
                'source' => $source === 'local_event' || $source === 'local_tx' || $source === 'local_key' ? 'local' : $source,
                'order_id' => in_array($source, ['cgo', 'supplier'], true) ? (int) ($summary['id'] ?? 0) : 0,
                'transaction_id' => $latestTxId,
            ];
        }

        $pageGroups = [];
        foreach ($selectedSummaries as $summary) {
            $key = (string) ($summary['key'] ?? '');
            if ($key !== '' && isset($groupsByKey[$key])) $pageGroups[] = $groupsByKey[$key];
        }

        // A summary without hydrated detail means one source query failed or
        // legacy data is inconsistent. Never hide a checkout just to keep the
        // optimized page fast; fall back to the complete legacy builder.
        if (count($pageGroups) !== count($selectedSummaries)) {
            return $legacyPageResult('page_hydration_incomplete');
        }

        return [
            'groups' => $pageGroups,
            'total_keys' => function_exists('cgoCountUnifiedUserKeys') ? cgoCountUnifiedUserKeys($userId) : 0,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $hasMore,
            'optimized' => true,
            'fallback_reason' => '',
        ];
    }
}

if (!function_exists('cgoGetOrdersForUser')) {
    function cgoGetOrdersForUser(int $userId, int $limit = 100): array
    {
        global $conn;
        $limit = max(1, min(500, $limit));
        if ($userId < 1 || !cgoEnsureTables()) return [];
        $stmt = $conn->prepare("SELECT o.*, CASE WHEN COALESCE(NULLIF(p.brand, ''), '') <> '' THEN CONCAT(p.brand, ' - ', p.name) ELSE p.name END AS product_name
            FROM cgo_orders o
            JOIN cgo_products p ON p.id = o.cgo_product_id
            WHERE o.user_id = ? ORDER BY o.id DESC LIMIT ?");
        if (!$stmt) return [];
        $stmt->bind_param('ii', $userId, $limit);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $orders = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        if ($orders === []) return [];

        $keyStmt = $conn->prepare('SELECT key_code FROM cgo_order_keys WHERE order_id = ? ORDER BY id ASC');
        foreach ($orders as &$order) {
            $order['keys'] = [];
            if ($keyStmt) {
                $orderId = (int) $order['id'];
                $keyStmt->bind_param('i', $orderId);
                if ($keyStmt->execute()) {
                    $keysResult = $keyStmt->get_result();
                    while ($row = $keysResult ? $keysResult->fetch_assoc() : null) {
                        if (!$row) break;
                        $order['keys'][] = (string) $row['key_code'];
                    }
                }
            }
        }
        unset($order);
        if ($keyStmt) $keyStmt->close();
        return $orders;
    }
}

if (!function_exists('cgoGetAllOrders')) {
    function cgoGetAllOrders(int $limit = 200): array
    {
        global $conn;
        $limit = max(1, min(1000, $limit));
        if (!cgoEnsureTables()) return [];
        $result = $conn->query("SELECT o.*, CASE WHEN COALESCE(NULLIF(p.brand, ''), '') <> '' THEN CONCAT(p.brand, ' - ', p.name) ELSE p.name END AS product_name,
                   COALESCE(u.username, CASE WHEN o.source_kind='store_api' THEN CONCAT('Store API #',COALESCE(o.source_order_id,0)) ELSE '' END) AS username
            FROM cgo_orders o
            JOIN cgo_products p ON p.id = o.cgo_product_id
            LEFT JOIN users u ON u.id = o.user_id
            ORDER BY o.id DESC LIMIT " . (int) $limit);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('cgoReconcileOrder')) {
    function cgoReconcileOrder(int $orderId): array
    {
        global $conn;
        if ($orderId < 1 || !cgoEnsureTables()) {
            return ['success' => false, 'message' => 'Invalid order'];
        }
        $stmt = $conn->prepare('SELECT * FROM cgo_orders WHERE id = ? LIMIT 1');
        if (!$stmt) return ['success' => false, 'message' => 'Unable to load order'];
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$order) return ['success' => false, 'message' => 'Order not found'];

        $currentStatus = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($currentStatus, ['refunded', 'refunded_conflict'], true)) {
            return ['success' => false, 'message' => 'This order was already refunded and requires manual review; automatic reconciliation is disabled.'];
        }
        if (in_array($currentStatus, ['success', 'completed'], true)) {
            return ['success' => true, 'message' => 'Order is already completed'];
        }

        $supplierOrderId = trim((string) ($order['supplier_order_id'] ?? ''));
        $externalRef = trim((string) ($order['external_ref'] ?? ''));
        $transactionId = (int) ($order['transaction_id'] ?? 0);
        $quantity = max(1, (int) ($order['quantity'] ?? 1));

        // Repair only legacy Cloudflare failures that are provably pre-request.
        // Error 522 remains pending because it can also occur after TCP connects;
        // refunding it without supplier confirmation could create a free order.
        if ($supplierOrderId === '' && cgoStoredOrderFailureDefinitelyNotDelivered($order)) {
            $originalError = trim((string) ($order['error_message'] ?? 'Supplier origin connection failed before order submission'));
            $refunded = cgoRefundOrder($orderId, $originalError, 'pre_delivery_transport_proof');
            return [
                'success' => $refunded,
                'refunded' => $refunded,
                'message' => $refunded
                    ? 'The original order request never reached the supplier origin. Balance refunded.'
                    : 'The original order request never reached the supplier origin, but the refund requires manual review.',
            ];
        }

        $lookupByExternalRef = $supplierOrderId === '';
        if ($lookupByExternalRef && $externalRef === '') {
            if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
            return ['success' => false, 'pending' => true, 'message' => 'Neither supplier order ID nor external reference is available; manual review is required.'];
        }

        // A missing supplier ID is recovered first through order_status using the
        // globally unique external_ref. If the provider returns non-final
        // order_not_found, order_cancel is used as the fencing step before refund.
        $lookupPayload = $lookupByExternalRef
            ? ['external_ref' => $externalRef]
            : ['order_id' => $supplierOrderId];
        $api = cgoApiRequest('order_status', 'GET', $lookupPayload, cgoOrderStatusTimeoutSeconds());
        $responseJson = cgoOrderResponseJson($api);
        $data = isset($api['data']) && is_array($api['data']) ? $api['data'] : null;
        $httpCode = (int) ($api['http_code'] ?? 0);
        $httpOk = $httpCode >= 200 && $httpCode < 300;

        $createdAt = strtotime((string) ($order['created_at'] ?? ''));
        $ageSeconds = $createdAt === false ? 0 : max(0, time() - $createdAt);

        $markUnresolvedLookup = static function (string $message) use ($conn, $orderId, $responseJson, $transactionId, $ageSeconds, $currentStatus): array {
            // Network/provider uncertainty is a normal automated-recovery
            // state, not an administrator workflow. Preserve manual_review only
            // if an invariant conflict already put the order there; otherwise keep
            // retrying automatically as unknown until provider finality is known.
            $nextStatus = $currentStatus === 'manual_review' ? 'manual_review' : 'unknown';
            $message = substr(trim($message), 0, 2000);
            $update = $conn->prepare("UPDATE cgo_orders
                                      SET status = ?,
                                          response_json = CASE
                                              WHEN response_json IS NULL OR response_json = '' THEN ?
                                              ELSE response_json
                                          END,
                                          error_message = ?,
                                          updated_at = NOW()
                                      WHERE id = ?
                                        AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('success','completed','refunded','refunded_conflict')");
            if ($update) {
                $update->bind_param('sssi', $nextStatus, $responseJson, $message, $orderId);
                $update->execute();
                $update->close();
            }
            if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
            cgoCommerceCenterSyncSafe($orderId);
            return [
                'success' => false,
                'pending' => true,
                'manual_review' => $nextStatus === 'manual_review',
                'message' => $message,
            ];
        };

        if ($data === null) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_no_json_or_transport');
            $message = $api['error'] ?: 'Unable to check order status';
            return $markUnresolvedLookup(
                $lookupByExternalRef
                    ? ('Automatic lookup by external reference is not available yet: ' . $message)
                    : $message
            );
        }

        $discoveredSupplierOrderId = cgoExtractSupplierOrderId($data);
        if ($discoveredSupplierOrderId !== null) {
            $discoveredSupplierOrderId = trim($discoveredSupplierOrderId);
            if ($discoveredSupplierOrderId === '') $discoveredSupplierOrderId = null;
        }
        $echoedExternalRef = strtoupper(trim((string) (cgoFindRecursiveValue($data, ['external_ref']) ?? '')));
        $externalRefMatches = $externalRef !== '' && $echoedExternalRef !== ''
            && hash_equals(strtoupper($externalRef), $echoedExternalRef);
        $keys = cgoExtractKeys($data);

        // Provider contract: order_not_found is explicitly NON-final. It may
        // only trigger the order_cancel fencing step; it can never authorize a
        // refund by itself, even if repeated many times.
        if (cgoOrderStatusNonFinalNotFound($api, $data, $externalRef, $lookupByExternalRef)) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => 'external_ref',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_not_found_nonfinal');

            if (cgoOrderKeyCount($orderId) > 0) {
                $conflictMessage = 'Supplier returned non-final order_not_found, but local delivery data already exists. Automatic cancellation/refund was blocked.';
                $update = $conn->prepare("UPDATE cgo_orders SET status = CASE WHEN status IN ('refunded','refunded_conflict') THEN 'refunded_conflict' WHEN status IN ('success','completed') THEN status ELSE 'manual_review' END, response_json = CASE WHEN status IN ('success','completed') THEN response_json ELSE ? END, error_message = CASE WHEN status IN ('success','completed') THEN error_message ELSE ? END, updated_at=NOW() WHERE id=?");
                if ($update) {
                    $update->bind_param('ssi', $responseJson, $conflictMessage, $orderId);
                    $update->execute();
                    $update->close();
                }
                if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
                cgoCommerceCenterSyncSafe($orderId);
                return ['success' => false, 'pending' => true, 'manual_review' => true, 'code' => 'not_found_local_delivery_conflict', 'message' => $conflictMessage];
            }

            $cancel = cgoAttemptOrderCancelSeal($orderId, $externalRef);
            $cancelState = (string) ($cancel['state'] ?? 'unknown');
            if ($cancelState === 'sealed' && !empty($cancel['safe_to_refund'])) {
                $reason = 'CHEATGAME order_cancel sealed external_ref ' . $externalRef . '; delayed submissions can no longer create an order.';
                $refunded = cgoRefundOrder($orderId, $reason, 'provider_cancel_seal');
                cgoInvalidateInventoryCache('Supplier cancellation seal made an ambiguous order safely refundable');
                return [
                    'success' => $refunded,
                    'refunded' => $refunded,
                    'pending' => !$refunded,
                    'code' => $refunded ? 'supplier_cancel_sealed_refunded' : 'supplier_refund_pending',
                    'refund_authority' => 'provider_cancel_seal',
                    'message' => $refunded
                        ? 'Supplier sealed the external reference. Balance refunded safely.'
                        : 'Supplier sealed the external reference, but the local refund could not be committed.',
                ];
            }

            if ($cancelState === 'order_exists') {
                $cancelSupplierId = trim((string) ($cancel['supplier_order_id'] ?? ''));
                $cancelKeys = isset($cancel['keys']) && is_array($cancel['keys']) ? $cancel['keys'] : [];
                if ($cancelKeys !== []) cgoStoreOrderKeys($orderId, $cancelKeys);
                if ($cancelSupplierId !== '') {
                    $update = $conn->prepare("UPDATE cgo_orders SET supplier_order_id = COALESCE(NULLIF(?, ''), supplier_order_id), status = CASE WHEN status IN ('refunded','refunded_conflict') THEN 'refunded_conflict' WHEN status IN ('success','completed') THEN status ELSE 'processing' END, error_message = CASE WHEN status IN ('refunded','refunded_conflict') THEN 'Supplier order existence was confirmed after a local refund; automatic re-debit is prohibited.' ELSE NULL END, updated_at=NOW() WHERE id=?");
                    if ($update) {
                        $update->bind_param('si', $cancelSupplierId, $orderId);
                        $update->execute();
                        $update->close();
                    }
                    // The cancellation fence lost the race because the order now
                    // exists. Query that proven supplier order immediately; never
                    // refund this branch.
                    return cgoReconcileOrder($orderId);
                }
                return $markUnresolvedLookup('order_cancel was rejected because the supplier indicates that the order already exists. Automatic status reconciliation will continue.');
            }

            if ($cancelState === 'processing') {
                $pending = $markUnresolvedLookup('The supplier reports that the order is still processing; cancellation was not finalized.');
                $pending['code'] = 'supplier_processing';
                $pending['retry_after_ms'] = 500;
                return $pending;
            }

            $cancelReason = trim((string) ($cancel['reason'] ?? 'order_cancel could not establish a safe refund fence'));
            $pending = $markUnresolvedLookup('order_not_found is non-final and order_cancel did not produce a verified seal: ' . $cancelReason);
            $pending['code'] = 'cancel_seal_unresolved';
            return $pending;
        }

        $terminalFailure = cgoOrderStatusTerminalFailure($data);
        $processingStatus = cgoOrderStatusProcessing($data);
        $externalRefMismatch = $lookupByExternalRef && $externalRef !== '' && $echoedExternalRef !== ''
            && !hash_equals(strtoupper($externalRef), $echoedExternalRef);

        // When querying by external_ref, positive order identity is preferred, but
        // provider-final failure or processing is also meaningful for the exact
        // query as long as the provider did not echo a conflicting reference.
        $lookupIdentifiedOrder = !$lookupByExternalRef
            || $discoveredSupplierOrderId !== null
            || $externalRefMatches
            || $keys !== []
            || (($terminalFailure || $processingStatus) && !$externalRefMismatch);
        if ($lookupByExternalRef && !$lookupIdentifiedOrder) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => 'external_ref',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_external_ref_unverified');
            $message = $api['error'] ?: 'Supplier did not return a result that can be safely bound to the external reference';
            return $markUnresolvedLookup($message);
        }

        // Delivery data plus a failed/error status is contradictory. Keep any
        // returned keys, but do not mark the order completed or refund it.
        if ($keys !== [] && ($terminalFailure || empty($api['ok']))) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_delivery_conflict');
            cgoStoreOrderKeys($orderId, $keys);
            $storageOk = cgoOrderHasKeys($orderId, $keys);
            $countMatches = count($keys) === $quantity;
            $conflictMessage = !$storageOk
                ? 'Order-status response contained delivery data, but local storage could not be verified.'
                : (!$countMatches
                    ? 'Order-status response contained ' . count($keys) . ' key(s) for quantity ' . $quantity . ' together with a failure signal.'
                    : 'Order-status response contains both delivery data and a failure signal. Automatic refund/completion is disabled pending review.');
            $candidateSupplierId = $discoveredSupplierOrderId ?? ($supplierOrderId !== '' ? $supplierOrderId : null);
            $update = $conn->prepare("UPDATE cgo_orders SET status = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN status ELSE 'manual_review' END, supplier_order_id = COALESCE(?, supplier_order_id), response_json = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN response_json ELSE ? END, error_message = CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('success','completed','refunded','refunded_conflict') THEN error_message ELSE ? END, updated_at=NOW() WHERE id = ?");
            if ($update) {
                $update->bind_param('sssi', $candidateSupplierId, $responseJson, $conflictMessage, $orderId);
                $update->execute();
                $update->close();
            }
            if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
            if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Conflicting order-status delivery requires inventory refresh');
            cgoCommerceCenterSyncSafe($orderId);
            return ['success' => false, 'pending' => true, 'message' => $conflictMessage];
        }

        if ((!$httpOk || empty($api['ok'])) && !$terminalFailure) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_provider_error_unresolved');
            $message = $api['error'] ?: ('Unable to check order status; HTTP ' . $httpCode);
            return $markUnresolvedLookup($message);
        }

        if ($discoveredSupplierOrderId !== null && $supplierOrderId === '') {
            $supplierOrderId = $discoveredSupplierOrderId;
        }

        if ($keys !== []) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_delivery_received');
            cgoStoreOrderKeys($orderId, $keys);
            $storageOk = cgoOrderHasKeys($orderId, $keys);
            $countMatches = count($keys) === $quantity;
            if (!$storageOk || !$countMatches) {
                $storageError = !$storageOk
                    ? 'Supplier returned product keys, but local key storage could not be verified.'
                    : 'Supplier returned ' . count($keys) . ' key(s) for an order quantity of ' . $quantity . '. Manual review is required.';
                $update = $conn->prepare("UPDATE cgo_orders
                                          SET status = CASE WHEN status IN ('refunded','refunded_conflict') THEN 'refunded_conflict' WHEN status IN ('success','completed') THEN status ELSE 'manual_review' END,
                                              supplier_order_id = COALESCE(?, supplier_order_id),
                                              response_json = ?,
                                              error_message = CASE WHEN status IN ('success','completed') THEN error_message ELSE ? END,
                                              updated_at=NOW()
                                          WHERE id = ?");
                if ($update) {
                    $update->bind_param('sssi', $supplierOrderId, $responseJson, $storageError, $orderId);
                    $update->execute();
                    $update->close();
                }
                if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
                cgoCommerceCenterSyncSafe($orderId);
                return ['success' => false, 'pending' => true, 'message' => $storageError];
            }

            $completionError = '';
            $conn->begin_transaction();
            try {
                $update = $conn->prepare("UPDATE cgo_orders
                                          SET status = 'success', supplier_order_id = COALESCE(?, supplier_order_id),
                                              response_json = ?, error_message = NULL,
                                              completed_at = COALESCE(completed_at, NOW())
                                          WHERE id = ? AND status NOT IN ('refunded','refunded_conflict')");
                if (!$update) throw new RuntimeException('order completion prepare failed');
                $update->bind_param('ssi', $supplierOrderId, $responseJson, $orderId);
                if (!$update->execute() || $update->affected_rows < 1) {
                    $update->close();
                    throw new RuntimeException('order completion update failed');
                }
                $update->close();
                if ($transactionId > 0 && !updateTransactionStatus($transactionId, 'completed')) {
                    throw new RuntimeException('transaction completion update failed');
                }
                if (!$conn->commit()) throw new RuntimeException('order reconciliation completion commit failed');
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                $completionError = 'Supplier delivery was stored, but final local completion could not be committed: ' . $e->getMessage();
                $review = $conn->prepare("UPDATE cgo_orders SET status = IF(status IN ('refunded','refunded_conflict','success','completed'), status, 'manual_review'), error_message = IF(status IN ('success','completed'), error_message, ?), updated_at=NOW() WHERE id = ?");
                if ($review) {
                    $review->bind_param('si', $completionError, $orderId);
                    $review->execute();
                    $review->close();
                }
                if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
                cgoCommerceCenterSyncSafe($orderId);
                return ['success' => false, 'pending' => true, 'message' => $completionError];
            }
            if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Reconciled order inventory adjustment failed');
            cgoCommerceCenterSyncSafe($orderId);
            cgoFinalizeOrderCheckoutTiming($orderId, 'status_reconcile');
            return ['success' => true, 'message' => 'Order completed', 'keys' => $keys];
        }

        if ($terminalFailure) {
            cgoRecordOrderApiAttempt($orderId, 'status', $api, [
                'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
                'external_ref' => $externalRef,
                'supplier_order_id' => $supplierOrderId,
            ], 'status_failed_final');
            $message = cgoExtractErrorMessage($data, 'Supplier marked the order as failed');
            if ($currentStatus === 'manual_review') {
                $update = $conn->prepare("UPDATE cgo_orders
                                          SET supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = ?, updated_at=NOW()
                                          WHERE id = ?");
                if ($update) {
                    $update->bind_param('sssi', $supplierOrderId, $responseJson, $message, $orderId);
                    $update->execute();
                    $update->close();
                }
                if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
                cgoCommerceCenterSyncSafe($orderId);
                return ['success' => false, 'pending' => true, 'message' => $message . ' Automatic refund is disabled because this order already required manual review.'];
            }
            $refunded = cgoRefundOrder($orderId, $message, 'provider_failed_final');
            // Refund completion is customer-critical. Never hold the response open
            // for a catalogue refresh after money has already been settled.
            cgoInvalidateInventoryCache('Reconciled order was rejected; stock refresh is pending');
            return [
                'success' => $refunded,
                'refunded' => $refunded,
                'pending' => !$refunded,
                'code' => $refunded ? 'supplier_failed_final_refunded' : 'supplier_refund_pending',
                'refund_authority' => 'provider_failed_final',
                'message' => $refunded ? ($message . ' Balance refunded.') : ($message . ' Final failure was confirmed, but the local refund could not be committed.'),
            ];
        }

        cgoRecordOrderApiAttempt($orderId, 'status', $api, [
            'lookup_mode' => $lookupByExternalRef ? 'external_ref' : 'order_id',
            'external_ref' => $externalRef,
            'supplier_order_id' => $supplierOrderId,
        ], 'status_identified_pending');
        $statusValue = strtolower(trim((string) (cgoFindRecursiveValue($data, ['order_status', 'status']) ?? 'processing')));
        $statusValue = in_array($statusValue, ['pending', 'processing', 'success', 'completed'], true) ? $statusValue : 'processing';
        $statusError = null;
        if (in_array($statusValue, ['success', 'completed'], true)) {
            $statusValue = 'manual_review';
            $statusError = 'Supplier reports completion, but no recognized product key was returned.';
        } elseif ($currentStatus === 'manual_review') {
            $statusValue = 'manual_review';
            $statusError = (string) ($order['error_message'] ?? 'Manual review is required.');
        }
        $update = $conn->prepare("UPDATE cgo_orders
                                  SET status = CASE WHEN status IN ('success','completed','refunded','refunded_conflict') THEN status ELSE ? END,
                                      supplier_order_id = COALESCE(?, supplier_order_id),
                                      response_json = CASE WHEN status IN ('success','completed','refunded','refunded_conflict') THEN response_json ELSE ? END,
                                      error_message = CASE WHEN status IN ('success','completed','refunded','refunded_conflict') THEN error_message ELSE ? END,
                                      updated_at = NOW()
                                  WHERE id = ?");
        if ($update) {
            $update->bind_param('ssssi', $statusValue, $supplierOrderId, $responseJson, $statusError, $orderId);
            $update->execute();
            $update->close();
        }
        if ($transactionId > 0) cgoMarkTransactionPendingIfOrderUnresolved($orderId, $transactionId);
        cgoCommerceCenterSyncSafe($orderId);
        $result = ['success' => true, 'pending' => $statusValue !== 'success', 'message' => 'Order status: ' . $statusValue];
        if (in_array($statusValue, ['pending', 'processing'], true)) {
            $result['code'] = 'supplier_processing';
            $result['retry_after_ms'] = 500;
        }
        return $result;
    }
}


if (!function_exists('cgoGetStorefrontOrderState')) {
    /**
     * Customer-safe status for one owned CHEATGAME order. It may perform one
     * throttled fenced reconciliation (including order_cancel after non-final
     * order_not_found); raw provider payloads never leave PHP.
     */
    function cgoGetStorefrontOrderState(int $orderId, int $userId, bool $reconcileIfDue = true): array
    {
        global $conn;
        if ($orderId < 1 || $userId < 1 || !cgoEnsureTables()) {
            return ['success' => false, 'code' => 'invalid_order', 'message' => 'Invalid order'];
        }

        $loadOrder = static function () use ($conn, $orderId, $userId): ?array {
            $stmt = $conn->prepare("SELECT id,user_id,status,total_price_base,created_at,updated_at,completed_at
                                    FROM cgo_orders WHERE id=? AND user_id=? LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('ii', $orderId, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            return $row ?: null;
        };

        $order = $loadOrder();
        if (!$order) {
            return ['success' => false, 'code' => 'order_not_found', 'message' => 'Order not found'];
        }

        $pendingStatuses = ['submitting', 'unknown', 'pending', 'processing', 'manual_review'];
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $createdTs = strtotime((string) ($order['created_at'] ?? ''));
        $updatedTs = strtotime((string) ($order['updated_at'] ?? ''));
        $now = time();
        $ageSeconds = $createdTs === false ? 0 : max(0, $now - $createdTs);
        $sinceUpdate = $updatedTs === false ? PHP_INT_MAX : max(0, $now - $updatedTs);

        if ($reconcileIfDue
            && in_array($status, $pendingStatuses, true)
            && $sinceUpdate >= cgoOrderReconcileMinIntervalSeconds()) {
            cgoReconcileOrder($orderId);
            $order = $loadOrder() ?: $order;
            $status = strtolower(trim((string) ($order['status'] ?? $status)));
            $createdTs = strtotime((string) ($order['created_at'] ?? ''));
            $ageSeconds = $createdTs === false ? $ageSeconds : max(0, time() - $createdTs);
        }

        // Do not expose delivery credentials through the polling endpoint.
        // Completed orders redirect to the existing authenticated key-history page.

        $refunded = $status === 'refunded';
        $conflict = $status === 'refunded_conflict';
        $completed = in_array($status, ['success', 'completed'], true);
        $pending = in_array($status, $pendingStatuses, true);
        $deadline = cgoOrderCustomerDeadlineSeconds();
        $remaining = max(0, $deadline - $ageSeconds);

        return [
            'success' => true,
            'order_id' => $orderId,
            'source' => 'cgo',
            'status' => $status,
            'terminal' => $completed || $refunded || $conflict,
            'completed' => $completed,
            'refunded' => $refunded,
            'conflict' => $conflict,
            'pending' => $pending,
            'total' => round((float) ($order['total_price_base'] ?? 0), 2),
            'balance' => round((float) getUserBalance($userId), 2),
            'age_seconds' => $ageSeconds,
            'deadline_seconds' => $deadline,
            'deadline_remaining_seconds' => $remaining,
            'deadline_exceeded' => $pending && $remaining <= 0,
            'retry_after_seconds' => $pending ? 3 : 0,
        ];
    }
}

if (!function_exists('cgoExtractBalance')) {
    function cgoExtractBalance(array $data): ?float
    {
        if (isset($data['balance']) && is_numeric($data['balance'])) {
            return (float) $data['balance'];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = cgoExtractBalance($value);
                if ($found !== null) return $found;
            }
        }
        return null;
    }
}

if (!function_exists('cgoExtractExchangeRate')) {
    function cgoExtractExchangeRate(array $data): ?array
    {
        if (isset($data['rate']) && is_numeric($data['rate'])) {
            return [
                'rate' => (float) $data['rate'],
                'rate_field' => isset($data['rate_field']) && is_scalar($data['rate_field']) ? (string) $data['rate_field'] : '',
                'source' => isset($data['source']) && is_scalar($data['source']) ? (string) $data['source'] : '',
                'status' => isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : '',
            ];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return cgoExtractExchangeRate($data['data']);
        }
        return null;
    }
}

if (!function_exists('cgoVerifyWebhookSignature')) {
    function cgoVerifyWebhookSignature(string $timestamp, string $eventId, string $rawBody, string $providedSignature): bool
    {
        $secret = (string) (cgoConfig()['webhook_secret'] ?? '');
        if ($secret === '' || !preg_match('/^sha256=([a-f0-9]{64})$/i', trim($providedSignature), $match)) {
            return false;
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $rawBody, $secret);
        return hash_equals(strtolower($expected), strtolower($match[1]));
    }
}

if (!function_exists('cgoWebhookExternalRef')) {
    function cgoWebhookExternalRef(array $payload): string
    {
        $value = cgoFindRecursiveValue($payload, ['external_ref']);
        if (!is_string($value)) return '';
        $value = strtoupper(trim($value));
        return strlen($value) <= 120 ? $value : '';
    }
}

if (!function_exists('cgoWebhookRoutePrefix')) {
    function cgoWebhookRoutePrefix(array $payload): string
    {
        $externalRef = cgoWebhookExternalRef($payload);
        if ($externalRef === '') return '';

        if (preg_match('/^([A-Z0-9]{2,12}-[A-Z0-9]{1,12})-\d{8}-[A-F0-9]{8,64}$/', $externalRef, $match) === 1) {
            return $match[1];
        }

        // Backward compatibility for orders created before database_code was
        // added to the external reference on the original SAK website.
        if (preg_match('/^SAK-\d{14}-[A-F0-9]{12}$/', $externalRef) === 1) {
            return 'SAK-010';
        }
        return '';
    }
}

if (!function_exists('cgoWebhookRouteTarget')) {
    function cgoWebhookRouteTarget(string $routePrefix): string
    {
        $routePrefix = strtoupper(trim($routePrefix));
        $routes = cgoConfig()['webhook_routes'] ?? [];
        if (!is_array($routes) || !array_key_exists($routePrefix, $routes)) return '';
        $target = trim((string) $routes[$routePrefix]);
        if ($target === 'local') return 'local';
        if (filter_var($target, FILTER_VALIDATE_URL) === false) return '';
        if (strtolower((string) parse_url($target, PHP_URL_SCHEME)) !== 'https') return '';
        return $target;
    }
}

if (!function_exists('cgoClaimGlobalWebhookEvent')) {
    function cgoClaimGlobalWebhookEvent(string $eventId, string $eventName, int $eventTimestamp, string $payloadHash, string $routePrefix): array
    {
        global $conn;
        if (!cgoEnsureTables()) {
            return ['claimed' => false, 'status' => 500, 'message' => 'Global webhook table unavailable'];
        }

        $eventId = trim($eventId);
        $eventName = trim($eventName);
        $routePrefix = strtoupper(trim($routePrefix));
        if ($eventId === '' || strlen($eventId) > 190 || $eventName === '' || strlen($eventName) > 100
            || preg_match('/^[A-Z0-9\-]{2,40}$/', $routePrefix) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) {
            return ['claimed' => false, 'status' => 400, 'message' => 'Invalid global webhook event'];
        }

        $conn->begin_transaction();
        try {
            $insert = $conn->prepare("INSERT IGNORE INTO cgo_global_webhook_events
                (event_id, event_name, event_timestamp, payload_hash, route_prefix, processing_status, attempt_count)
                VALUES (?, ?, ?, ?, ?, 'processing', 1)");
            if (!$insert) throw new RuntimeException('global event insert prepare failed');
            $insert->bind_param('ssiss', $eventId, $eventName, $eventTimestamp, $payloadHash, $routePrefix);
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('global event insert failed');
            }
            $newEvent = $insert->affected_rows === 1;
            $insert->close();
            if ($newEvent) {
                if (!$conn->commit()) throw new RuntimeException('global event claim commit failed');
                return ['claimed' => true, 'status' => 200, 'message' => 'Event claimed'];
            }

            $select = $conn->prepare("SELECT payload_hash, route_prefix, processing_status,
                                             TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_seconds
                                      FROM cgo_global_webhook_events WHERE event_id = ? LIMIT 1 FOR UPDATE");
            if (!$select) throw new RuntimeException('global event select prepare failed');
            $select->bind_param('s', $eventId);
            if (!$select->execute()) {
                $select->close();
                throw new RuntimeException('global event select failed');
            }
            $result = $select->get_result();
            $existing = $result ? $result->fetch_assoc() : null;
            $select->close();
            if (!$existing) throw new RuntimeException('global event disappeared');

            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)
                || !hash_equals(strtoupper((string) $existing['route_prefix']), $routePrefix)) {
                $conflict = $conn->prepare("UPDATE cgo_global_webhook_events
                    SET processing_status = 'conflict', last_error = 'Event ID reused with different payload or route', processed_at = NOW()
                    WHERE event_id = ?");
                if (!$conflict) throw new RuntimeException('global conflict prepare failed');
                $conflict->bind_param('s', $eventId);
                if (!$conflict->execute()) {
                    $conflict->close();
                    throw new RuntimeException('global conflict update failed');
                }
                $conflict->close();
                if (!$conn->commit()) throw new RuntimeException('global event conflict commit failed');
                return ['claimed' => false, 'status' => 409, 'message' => 'Event ID payload conflict'];
            }

            $processingStatus = strtolower((string) $existing['processing_status']);
            $ageSeconds = max(0, (int) ($existing['age_seconds'] ?? 0));
            if (in_array($processingStatus, ['processed', 'ignored', 'conflict'], true)) {
                $conn->rollback();
                return ['claimed' => false, 'status' => 200, 'message' => 'Duplicate event ignored'];
            }
            if ($processingStatus === 'processing' && $ageSeconds < 600) {
                $conn->rollback();
                return ['claimed' => false, 'status' => 202, 'message' => 'Duplicate event is already processing'];
            }

            $retry = $conn->prepare("UPDATE cgo_global_webhook_events
                SET processing_status = 'processing', attempt_count = attempt_count + 1,
                    response_http = NULL, last_error = NULL, processed_at = NULL
                WHERE event_id = ?");
            if (!$retry) throw new RuntimeException('global retry prepare failed');
            $retry->bind_param('s', $eventId);
            if (!$retry->execute()) {
                $retry->close();
                throw new RuntimeException('global retry failed');
            }
            $retry->close();
            if (!$conn->commit()) throw new RuntimeException('global event retry commit failed');
            return ['claimed' => true, 'status' => 200, 'message' => 'Event retry claimed'];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('CGO global webhook claim failed: ' . $e->getMessage());
            return ['claimed' => false, 'status' => 500, 'message' => 'Unable to claim webhook event'];
        }
    }
}

if (!function_exists('cgoMarkGlobalWebhookEvent')) {
    function cgoMarkGlobalWebhookEvent(string $eventId, string $status, int $httpCode, string $message = ''): bool
    {
        global $conn;
        $allowed = ['processed', 'ignored', 'error', 'conflict'];
        if (!in_array($status, $allowed, true)) return false;
        $message = trim($message);
        if (!in_array($status, ['error', 'conflict'], true)) $message = '';
        if (strlen($message) > 2000) $message = substr($message, 0, 2000);
        $stmt = $conn->prepare("UPDATE cgo_global_webhook_events
            SET processing_status = ?, response_http = NULLIF(?, 0), last_error = NULLIF(?, ''),
                processed_at = CASE WHEN ? IN ('processed','ignored','conflict') THEN NOW() ELSE NULL END
            WHERE event_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('sisss', $status, $httpCode, $message, $status, $eventId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('cgoForwardWebhook')) {
    function cgoForwardWebhook(string $url, string $eventName, string $eventId, string $timestamp, string $signature, string $rawBody): array
    {
        $config = cgoConfig();
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['success' => false, 'status' => 500, 'message' => 'Invalid webhook route URL'];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 500, 'message' => 'PHP cURL extension is unavailable'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 500, 'message' => 'Unable to initialize webhook forward'];
        }
        $response = '';
        $tooLarge = false;
        $secret = (string) ($config['webhook_secret'] ?? '');
        if ($secret === '') {
            curl_close($ch);
            return ['success' => false, 'status' => 500, 'message' => 'Webhook secret is unavailable for forwarding'];
        }
        // Re-sign with a fresh timestamp after the hub has verified the provider
        // request. This avoids the receiver rejecting a valid event that reached
        // the edge of the five-minute replay window while being forwarded.
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $eventId . '.' . $rawBody, $secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CGO-Event: ' . $eventName,
            'X-CGO-Event-ID: ' . $eventId,
            'X-CGO-Timestamp: ' . $timestamp,
            'X-CGO-Signature: ' . $signature,
            'User-Agent: CHEATGAME-Webhook-Hub/1.0',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => (int) ($config['webhook_forward_connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT => (int) ($config['webhook_forward_timeout'] ?? 15),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 65536) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if (!empty($config['force_ipv4']) && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        curl_setopt_array($ch, $options);
        $executed = curl_exec($ch);
        $curlNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($executed === false || $curlNo !== 0 || $tooLarge) {
            $message = $tooLarge ? 'Webhook receiver response exceeded the limit' : ('Webhook forwarding failed: ' . $curlNo . ($curlError !== '' ? ' ' . $curlError : ''));
            error_log('CGO webhook forward transport failure url=' . $url . ' message=' . $message);
            return ['success' => false, 'status' => 502, 'message' => $message];
        }

        $decoded = json_decode($response, true);
        $message = is_array($decoded) && isset($decoded['message']) && is_scalar($decoded['message'])
            ? trim((string) $decoded['message'])
            : trim($response);
        if ($message === '') $message = 'Webhook receiver returned HTTP ' . $httpCode;
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('CGO webhook receiver rejected event url=' . $url . ' http=' . $httpCode . ' message=' . substr($message, 0, 500));
            return ['success' => false, 'status' => $httpCode > 0 ? $httpCode : 502, 'message' => substr($message, 0, 2000)];
        }
        return ['success' => true, 'status' => $httpCode, 'message' => substr($message, 0, 2000)];
    }
}

if (!function_exists('cgoProcessWebhook')) {
    function cgoProcessWebhook(string $eventName, string $eventId, int $eventTimestamp, array $payload, string $rawBody): array
    {
        global $conn;
        if (!cgoEnsureTables()) {
            return ['success' => false, 'status' => 500, 'message' => 'Database unavailable'];
        }
        if ($eventName !== 'order.success') {
            return ['success' => true, 'status' => 202, 'message' => 'Event ignored'];
        }
        if ($eventId === '' || strlen($eventId) > 190) {
            return ['success' => false, 'status' => 400, 'message' => 'Invalid event ID'];
        }

        $payloadHash = hash('sha256', $rawBody);
        $insert = $conn->prepare("INSERT IGNORE INTO cgo_webhook_events (event_id, event_name, event_timestamp, payload_hash, processing_status) VALUES (?, ?, ?, ?, 'processing')");
        if (!$insert) return ['success' => false, 'status' => 500, 'message' => 'Unable to record event'];
        $insert->bind_param('ssis', $eventId, $eventName, $eventTimestamp, $payloadHash);
        $insertOk = $insert->execute();
        $newEvent = $insertOk && $insert->affected_rows > 0;
        $insert->close();
        if (!$insertOk) {
            return ['success' => false, 'status' => 500, 'message' => 'Unable to record event'];
        }

        if (!$newEvent) {
            $existingStmt = $conn->prepare("SELECT payload_hash, processing_status,
                                                       TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_seconds
                                                FROM cgo_webhook_events WHERE event_id = ? LIMIT 1");
            if (!$existingStmt) return ['success' => false, 'status' => 500, 'message' => 'Unable to read event state'];
            $existingStmt->bind_param('s', $eventId);
            $existingStmt->execute();
            $existingResult = $existingStmt->get_result();
            $existing = $existingResult ? $existingResult->fetch_assoc() : null;
            $existingStmt->close();
            if (!$existing) return ['success' => false, 'status' => 500, 'message' => 'Unable to read event state'];
            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                return ['success' => false, 'status' => 409, 'message' => 'Event ID payload conflict'];
            }
            $existingStatus = strtolower((string) $existing['processing_status']);
            if (in_array($existingStatus, ['processed', 'conflict'], true)) {
                return ['success' => true, 'status' => 200, 'message' => 'Duplicate event ignored'];
            }
            $ageSeconds = max(0, (int) ($existing['age_seconds'] ?? 0));
            if ($existingStatus === 'processing' && $ageSeconds < 600) {
                return ['success' => true, 'status' => 202, 'message' => 'Duplicate event is already processing'];
            }
            $retry = $conn->prepare("UPDATE cgo_webhook_events
                SET processing_status = 'processing', processed_at = NULL, updated_at = NOW()
                WHERE event_id = ?
                  AND (processing_status IN ('error','unmatched')
                       OR (processing_status = 'processing' AND updated_at < NOW() - INTERVAL 10 MINUTE))");
            if (!$retry) return ['success' => false, 'status' => 500, 'message' => 'Unable to retry event'];
            $retry->bind_param('s', $eventId);
            $retryOk = $retry->execute() && $retry->affected_rows === 1;
            $retry->close();
            if (!$retryOk) {
                return ['success' => true, 'status' => 202, 'message' => 'Duplicate event was not claimed'];
            }
        }

        $markEvent = static function (string $status) use ($conn, $eventId): bool {
            $stmt = $conn->prepare('UPDATE cgo_webhook_events SET processing_status = ?, processed_at = CASE WHEN ? IN (\'processed\',\'conflict\') THEN NOW() ELSE processed_at END WHERE event_id = ?');
            if (!$stmt) return false;
            $stmt->bind_param('sss', $status, $status, $eventId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        };

        $externalRef = cgoWebhookExternalRef($payload);
        $supplierOrderId = cgoExtractSupplierOrderId($payload);
        $order = null;
        if (is_string($externalRef) && $externalRef !== '') {
            $stmt = $conn->prepare('SELECT * FROM cgo_orders WHERE external_ref = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $externalRef);
                $stmt->execute();
                $result = $stmt->get_result();
                $order = $result ? $result->fetch_assoc() : null;
                $stmt->close();
            }
        }
        if (!$order && $supplierOrderId !== null) {
            $stmt = $conn->prepare('SELECT * FROM cgo_orders WHERE supplier_order_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $supplierOrderId);
                $stmt->execute();
                $result = $stmt->get_result();
                $order = $result ? $result->fetch_assoc() : null;
                $stmt->close();
            }
        }
        if (!$order) {
            $markEvent('unmatched');
            // A routed order.success event should always have a local reservation,
            // because the order row is committed before action=order is sent. Return
            // a retryable error instead of acknowledging and permanently losing it.
            return ['success' => false, 'status' => 503, 'message' => 'Order is not available locally yet; retry later'];
        }

        $orderId = (int) $order['id'];
        cgoRecordOrderApiAttempt($orderId, 'webhook', [
            'ok' => true,
            'http_code' => 200,
            'data' => $payload,
            'raw' => $rawBody,
            'error' => '',
            'transport_error' => false,
            'curl_errno' => 0,
            'curl_error' => '',
            'provider_error_code' => '',
            'provider_error_class' => '',
            'primary_ip' => '',
            'local_ip' => '',
            'effective_url' => '',
            'namelookup_time_ms' => 0,
            'connect_time_ms' => 0,
            'appconnect_time_ms' => 0,
            'pretransfer_time_ms' => 0,
            'starttransfer_time_ms' => 0,
            'total_time_ms' => 0,
        ], [
            'lookup_mode' => 'webhook',
            'external_ref' => is_string($externalRef) ? $externalRef : '',
            'supplier_order_id' => $supplierOrderId ?? '',
        ], 'webhook_order_success_matched');
        $responseJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($responseJson)) {
            $markEvent('error');
            return ['success' => false, 'status' => 500, 'message' => 'Unable to encode webhook payload'];
        }

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT status, transaction_id, quantity FROM cgo_orders WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$lock) throw new RuntimeException('order lock prepare failed');
            $lock->bind_param('i', $orderId);
            if (!$lock->execute()) { $lock->close(); throw new RuntimeException('order lock failed'); }
            $lockedResult = $lock->get_result();
            $lockedOrder = $lockedResult ? $lockedResult->fetch_assoc() : null;
            $lock->close();
            if (!$lockedOrder) throw new RuntimeException('order disappeared');

            if (in_array((string) $lockedOrder['status'], ['refunded', 'refunded_conflict'], true)) {
                $conflictMessage = 'Supplier reported success after the local order was refunded. Manual reconciliation is required.';
                $conflict = $conn->prepare("UPDATE cgo_orders SET status = 'refunded_conflict', supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = ? WHERE id = ?");
                if (!$conflict) throw new RuntimeException('conflict update prepare failed');
                $conflict->bind_param('sssi', $supplierOrderId, $responseJson, $conflictMessage, $orderId);
                if (!$conflict->execute()) { $conflict->close(); throw new RuntimeException('conflict update failed'); }
                $conflict->close();
                if (!$markEvent('conflict')) throw new RuntimeException('event conflict update failed');
                if (!$conn->commit()) throw new RuntimeException('webhook conflict commit failed');
                return ['success' => true, 'status' => 202, 'message' => 'Order requires manual reconciliation'];
            }

            $keys = cgoExtractKeys($payload);
            $expectedQuantity = max(1, (int) ($lockedOrder['quantity'] ?? 1));
            $deliveryComplete = false;
            $reviewMessage = '';
            if ($keys !== []) {
                cgoStoreOrderKeys($orderId, $keys);
                $storageOk = cgoOrderHasKeys($orderId, $keys);
                $countMatches = count($keys) === $expectedQuantity;
                if ($storageOk && $countMatches) {
                    $deliveryComplete = true;
                    $update = $conn->prepare("UPDATE cgo_orders SET status = IF(status IN ('success','completed'), status, 'success'), supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = NULL, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?");
                    if (!$update) throw new RuntimeException('order success update prepare failed');
                    $update->bind_param('ssi', $supplierOrderId, $responseJson, $orderId);
                } else {
                    $reviewMessage = !$storageOk
                        ? 'order.success webhook contained delivery data, but local storage could not be verified.'
                        : 'order.success webhook returned ' . count($keys) . ' key(s) for quantity ' . $expectedQuantity . '. Manual review is required.';
                    $update = $conn->prepare("UPDATE cgo_orders SET status = IF(status IN ('success','completed'), status, 'manual_review'), supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = IF(status IN ('success','completed'), NULL, ?) WHERE id = ?");
                    if (!$update) throw new RuntimeException('manual review update prepare failed');
                    $update->bind_param('sssi', $supplierOrderId, $responseJson, $reviewMessage, $orderId);
                }
            } else {
                $reviewMessage = 'order.success webhook did not contain a recognized product key. Check order_status and the raw response.';
                $update = $conn->prepare("UPDATE cgo_orders SET status = IF(status IN ('success','completed'), status, 'manual_review'), supplier_order_id = COALESCE(?, supplier_order_id), response_json = ?, error_message = IF(status IN ('success','completed'), NULL, ?) WHERE id = ?");
                if (!$update) throw new RuntimeException('manual review update prepare failed');
                $update->bind_param('sssi', $supplierOrderId, $responseJson, $reviewMessage, $orderId);
            }
            if (!$update->execute()) { $update->close(); throw new RuntimeException('order update failed'); }
            $update->close();

            $transactionId = (int) ($lockedOrder['transaction_id'] ?? 0);
            $lockedWasCompleted = in_array(strtolower(trim((string) ($lockedOrder['status'] ?? ''))), ['success', 'completed'], true);
            $targetTransactionStatus = ($deliveryComplete || $lockedWasCompleted) ? 'completed' : 'pending';
            if ($transactionId > 0 && !updateTransactionStatus($transactionId, $targetTransactionStatus)) {
                throw new RuntimeException('transaction status update failed');
            }
            if (!$markEvent('processed')) throw new RuntimeException('event completion update failed');
            if (!$conn->commit()) throw new RuntimeException('webhook completion commit failed');
            if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Webhook order inventory adjustment failed');
            if ($deliveryComplete) cgoFinalizeOrderCheckoutTiming($orderId, 'webhook');
            return [
                'success' => true,
                'status' => $deliveryComplete ? 200 : 202,
                'message' => $deliveryComplete ? 'Webhook processed' : 'Webhook accepted for manual reconciliation',
            ];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}

            // If COMMIT returned an uncertain result, re-read the durable order
            // before downgrading the webhook event. A committed success/manual
            // review must remain acknowledged; otherwise the provider may retry
            // an event that was already applied successfully.
            $durableStatus = '';
            $durableQuantity = 0;
            try {
                $verify = $conn->prepare('SELECT status,quantity FROM cgo_orders WHERE id=? LIMIT 1');
                if ($verify) {
                    $verify->bind_param('i', $orderId);
                    if ($verify->execute()) {
                        $verifyResult = $verify->get_result();
                        $verifyRow = $verifyResult ? $verifyResult->fetch_assoc() : null;
                        $durableStatus = strtolower(trim((string) ($verifyRow['status'] ?? '')));
                        $durableQuantity = max(0, (int) ($verifyRow['quantity'] ?? 0));
                    }
                    $verify->close();
                }
            } catch (Throwable $ignored) {}

            if (in_array($durableStatus, ['success','completed'], true)
                && $durableQuantity > 0
                && cgoOrderKeyCount($orderId) >= $durableQuantity) {
                $markEvent('processed');
                if (!cgoAdjustInventoryForAcceptedOrder($orderId)) cgoInvalidateInventoryCache('Webhook commit verification inventory adjustment failed');
                cgoFinalizeOrderCheckoutTiming($orderId, 'webhook_commit_verified');
                error_log('CGO webhook commit result was uncertain but durable success was verified for order #' . $orderId);
                return ['success' => true, 'status' => 200, 'message' => 'Webhook processed'];
            }
            if ($durableStatus === 'refunded_conflict') {
                $markEvent('conflict');
                error_log('CGO webhook commit result was uncertain but durable refund conflict was verified for order #' . $orderId);
                return ['success' => true, 'status' => 202, 'message' => 'Order requires manual reconciliation'];
            }
            if ($durableStatus === 'manual_review') {
                $markEvent('processed');
                error_log('CGO webhook commit result was uncertain but durable manual-review state was verified for order #' . $orderId);
                return ['success' => true, 'status' => 202, 'message' => 'Webhook accepted for manual reconciliation'];
            }

            $markEvent('error');
            error_log('CGO webhook processing failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 500, 'message' => 'Webhook processing failed'];
        }
    }
}
