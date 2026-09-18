<?php
/**
 * Sakazuki Store Bridge
 *
 * One codebase can act as both:
 *  - an API provider that sells its local product keys to another website; and
 *  - an API consumer that imports products and orders keys from another website.
 *
 * Provider tables use store_api_* and consumer tables use supplier_* so this
 * integration remains independent from the existing CHEATGAME cgo_* tables.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/commerce_center.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/account_verification.php';

if (!defined('STORE_BRIDGE_VERSION')) define('STORE_BRIDGE_VERSION', '2.6');
if (!defined('STORE_BRIDGE_MAX_RESPONSE')) define('STORE_BRIDGE_MAX_RESPONSE', 2 * 1024 * 1024);
require_once __DIR__ . '/store_bridge_vipstore.php';
require_once __DIR__ . '/store_bridge_starkmods.php';

function storeBridgeBase64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function storeBridgeBase64UrlDecode(string $value)
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
    return base64_decode($value, true);
}

function storeBridgeArrayIsList(array $value): bool
{
    if (function_exists('array_is_list')) return array_is_list($value);
    if ($value === []) return true;
    return array_keys($value) === range(0, count($value) - 1);
}

function storeBridgeTableExists(string $table): bool
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !isset($conn) || !($conn instanceof mysqli)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0) > 0;
}

function storeBridgeColumnExists(string $table, string $column): bool
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['c'] ?? 0) > 0;
}

function storeBridgeEnsureColumn(string $table, string $column, string $definition): bool
{
    global $conn;
    if (storeBridgeColumnExists($table, $column)) return true;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) return false;
    if ($conn->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition)) return true;
    // Deployment can race when two storefront requests are the first to hit a
    // new version. If the other request added the column milliseconds earlier,
    // treat the migration as complete instead of taking this request offline.
    return storeBridgeColumnExists($table, $column);
}

function storeBridgeIndexInfo(string $table, string $index): ?array
{
    global $conn;
    if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $index)) return null;
    $stmt = $conn->prepare('SELECT INDEX_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * Runtime Store Bridge readiness must validate the schema shape used by this
 * deployment, not merely that a few legacy tables exist. HTTP requests never
 * migrate. They fail closed before authentication/checkout if the private cron
 * has not applied the current schema yet.
 */
function storeBridgeRuntimeSchemaReady(bool $refresh = false): bool
{
    static $ready = null;
    if ($refresh) $ready = null;
    if ($ready !== null) return $ready;
    if (!function_exists('sakazukiTableReady') || !function_exists('sakazukiTableColumnsReady')) return $ready = false;
    foreach ([
        'store_api_clients', 'store_api_orders', 'store_api_order_keys', 'store_api_balance_ledger',
        'supplier_connections', 'supplier_products', 'supplier_catalog_links', 'supplier_orders', 'supplier_order_keys',
    ] as $table) {
        if (!sakazukiTableReady($table, $refresh)) return $ready = false;
    }
    $required = [
        'store_api_clients' => [
            'id','key_hash','status','deleted_at','client_type','billing_mode','linked_user_id','balance','currency',
            'price_tier','price_multiplier','rate_limit_per_minute','order_rate_limit_per_minute','max_order_amount','daily_spend_limit',
            'source_access_json',
        ],
        'store_api_orders' => [
            'id','client_id','external_ref','remote_product_id','source_product_id','source_variant_id','duration','quantity',
            'unit_price','total_price','status','customer_name','customer_email','origin_site_id','origin_user_id','customer_ref',
            'billing_mode','billing_user_id','billing_transaction_id','balance_before','balance_after','request_fingerprint',
            'fulfillment_source','fulfillment_reason','upstream_order_id','upstream_reference','upstream_status','procurement_cost',
            'refunded_amount','refunded_at','error_message','created_at','updated_at','completed_at',
        ],
        'store_api_order_keys' => ['id','order_id','source_type','source_key_id','source_order_id','key_code','key_hash','created_at'],
        'supplier_connections' => ['id','provider_type','purchase_mode','endpoint_url','api_key_ciphertext','status','priority','connect_timeout','request_timeout'],
        'supplier_catalog_links' => ['id','supplier_product_id','local_product_id','local_variant_id','api_fallback_enabled','source_priority','max_supplier_cost','sync_duration'],
        'supplier_orders' => ['id','external_ref','source_kind','source_order_id','connection_id','supplier_product_id','user_id','status','transaction_id','response_json','error_message'],
    ];
    foreach ($required as $table => $columns) {
        if (!sakazukiTableColumnsReady($table, $columns, $refresh)) return $ready = false;
    }
    return $ready = true;
}

function storeBridgeColumnNullable(string $table, string $column, bool $refresh = false): bool
{
    global $conn;
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/iD', $table) || !preg_match('/^[a-z0-9_]+$/iD', $column)) return false;
    $key = $table . '.' . $column;
    if ($refresh) unset($cache[$key]);
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!isset($conn) || !($conn instanceof mysqli)) return $cache[$key] = false;
    try {
        $stmt = $conn->prepare('SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        if (!$stmt) return $cache[$key] = false;
        $stmt->bind_param('ss', $table, $column);
        if (!$stmt->execute()) { $stmt->close(); return $cache[$key] = false; }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $cache[$key] = is_array($row) && strtoupper((string) ($row['IS_NULLABLE'] ?? 'NO')) === 'YES';
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function storeBridgeCgoDeliverySchemaReady(bool $refresh = false): bool
{
    if (!storeBridgeRuntimeSchemaReady($refresh)) return false;
    return storeBridgeColumnNullable('store_api_order_keys', 'source_key_id', $refresh);
}

function storeBridgeEnsureSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    $migrationsAllowed = !function_exists('sakazukiSchemaMigrationsAllowed') || sakazukiSchemaMigrationsAllowed();
    $prepared = function_exists('sakazukiStorefrontSchemaPrepared') && sakazukiStorefrontSchemaPrepared();
    if (!$migrationsAllowed) return $ready = storeBridgeRuntimeSchemaReady();
    // A marker is an optimization, not proof. If the recorded marker survives a
    // partial deployment/database restore, the CLI worker repairs the schema.
    if ($prepared && storeBridgeRuntimeSchemaReady()) return $ready = true;

    $queries = [
        "CREATE TABLE IF NOT EXISTS store_api_clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            key_prefix VARCHAR(20) NOT NULL,
            key_last4 CHAR(4) NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            client_type ENUM('admin','reseller_self_service') NOT NULL DEFAULT 'admin',
            billing_mode ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance',
            linked_user_id BIGINT UNSIGNED NULL,
            website_name VARCHAR(190) NULL,
            website_url VARCHAR(1000) NULL,
            webhook_url VARCHAR(1000) NULL,
            webhook_last_test_at DATETIME NULL,
            webhook_last_http_code SMALLINT UNSIGNED NULL,
            webhook_last_error VARCHAR(1000) NULL,
            webhook_last_debug_json MEDIUMTEXT NULL,
            balance DECIMAL(16,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'THB',
            price_tier ENUM('reseller','user','cost') NOT NULL DEFAULT 'reseller',
            price_multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            allowed_ips TEXT NULL,
            rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 120,
            order_rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 10,
            max_order_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
            daily_spend_limit DECIMAL(16,2) NOT NULL DEFAULT 0,
            source_access_json TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            last_used_at DATETIME NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_key_hash (key_hash),
            KEY idx_store_api_client_status (status),
            KEY idx_store_api_client_name (name),
            KEY idx_store_api_client_linked_user (linked_user_id, client_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_webhook_test_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            result_code VARCHAR(80) NOT NULL,
            http_code SMALLINT UNSIGNED NULL,
            resolved_ip VARCHAR(45) NULL,
            connected_ip VARCHAR(45) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            debug_json MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_api_webhook_client (client_id, created_at),
            KEY idx_store_api_webhook_http (http_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            external_ref VARCHAR(120) NOT NULL,
            remote_product_id VARCHAR(190) NOT NULL,
            source_product_id INT NOT NULL,
            source_variant_id INT NULL,
            duration VARCHAR(120) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(16,2) NOT NULL,
            total_price DECIMAL(16,2) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'processing',
            customer_name VARCHAR(190) NULL,
            customer_email VARCHAR(190) NULL,
            origin_site_id VARCHAR(100) NULL,
            origin_user_id VARCHAR(190) NULL,
            customer_ref VARCHAR(255) NULL,
            billing_mode ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance',
            billing_user_id BIGINT UNSIGNED NULL,
            billing_transaction_id BIGINT UNSIGNED NULL,
            balance_before DECIMAL(16,2) NULL,
            balance_after DECIMAL(16,2) NULL,
            request_fingerprint CHAR(64) NULL,
            fulfillment_source VARCHAR(20) NOT NULL DEFAULT 'local',
            fulfillment_reason VARCHAR(80) NULL,
            upstream_order_id BIGINT UNSIGNED NULL,
            upstream_reference VARCHAR(190) NULL,
            upstream_status VARCHAR(40) NULL,
            procurement_cost DECIMAL(16,2) NULL,
            refunded_amount DECIMAL(16,2) NULL,
            refunded_at DATETIME NULL,
            error_message VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_external_ref (client_id, external_ref),
            KEY idx_store_api_order_client (client_id, created_at),
            KEY idx_store_api_order_status (status),
            KEY idx_store_api_order_product (source_product_id, source_variant_id),
            KEY idx_store_api_order_billing_user (billing_user_id, created_at),
            KEY idx_store_api_order_billing_transaction (billing_transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_order_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'local',
            source_key_id BIGINT UNSIGNED NULL,
            source_order_id BIGINT UNSIGNED NULL,
            key_code TEXT NOT NULL,
            key_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_order_key (order_id, key_hash),
            UNIQUE KEY uq_store_api_source_key (source_key_id),
            KEY idx_store_api_order_keys_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_balance_ledger (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            entry_type VARCHAR(40) NOT NULL,
            amount DECIMAL(16,2) NOT NULL,
            balance_after DECIMAL(16,2) NOT NULL,
            note VARCHAR(500) NULL,
            admin_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_api_ledger_client (client_id, id),
            KEY idx_store_api_ledger_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_request_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NULL,
            request_id VARCHAR(40) NOT NULL,
            action VARCHAR(40) NOT NULL,
            client_ip VARCHAR(64) NOT NULL,
            http_method VARCHAR(10) NOT NULL,
            http_code SMALLINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_api_request_client (client_id, created_at),
            KEY idx_store_api_request_action (action, created_at),
            KEY idx_store_api_request_id (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_diagnostic_probes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'waiting',
            expires_at DATETIME NOT NULL,
            received_at DATETIME NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            remote_addr VARCHAR(64) NULL,
            detected_client_ip VARCHAR(64) NULL,
            trusted_proxy TINYINT(1) NULL,
            ip_allowed TINYINT(1) NULL,
            cf_ray VARCHAR(100) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_probe_token (token_hash),
            KEY idx_store_api_probe_client (client_id, created_at),
            KEY idx_store_api_probe_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_shared_claims (
            namespace VARCHAR(32) NOT NULL,
            reference_hash CHAR(64) NOT NULL,
            claimant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'processing',
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (namespace, reference_hash),
            KEY idx_store_shared_status (status, expires_at),
            KEY idx_store_shared_claimant (claimant_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_client_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            source_variant_id INT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            custom_price DECIMAL(16,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_client_variant (client_id, source_variant_id),
            KEY idx_store_api_client_products_enabled (client_id, enabled),
            KEY idx_store_api_client_products_variant (source_variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_connections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            provider_type VARCHAR(60) NOT NULL DEFAULT 'sakazuki_v1',
            purchase_mode VARCHAR(20) NOT NULL DEFAULT 'live',
            endpoint_url VARCHAR(1000) NOT NULL,
            api_key_ciphertext TEXT NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            priority INT NOT NULL DEFAULT 100,
            auto_publish TINYINT(1) NOT NULL DEFAULT 1,
            sync_details TINYINT(1) NOT NULL DEFAULT 1,
            sync_prices TINYINT(1) NOT NULL DEFAULT 1,
            user_price_mode VARCHAR(20) NOT NULL DEFAULT 'source',
            reseller_price_mode VARCHAR(20) NOT NULL DEFAULT 'source',
            user_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 20.00,
            reseller_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 10.00,
            protect_below_cost TINYINT(1) NOT NULL DEFAULT 1,
            connect_timeout INT UNSIGNED NOT NULL DEFAULT 8,
            request_timeout INT UNSIGNED NOT NULL DEFAULT 25,
            currency CHAR(3) NULL,
            last_balance DECIMAL(16,2) NULL,
            last_sync_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_error VARCHAR(1000) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_supplier_connection_status (status, priority),
            KEY idx_supplier_connection_provider (provider_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            remote_product_id VARCHAR(190) NOT NULL,
            remote_source_product_id VARCHAR(190) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            image_url VARCHAR(1000) NULL,
            categories_json TEXT NULL,
            platform VARCHAR(60) NULL,
            duration VARCHAR(120) NOT NULL,
            remote_stock INT NOT NULL DEFAULT 0,
            remote_status VARCHAR(60) NULL,
            currency CHAR(3) NULL,
            cost_base DECIMAL(16,2) NOT NULL DEFAULT 0,
            source_price_user DECIMAL(16,2) NULL,
            source_price_reseller DECIMAL(16,2) NULL,
            user_price_mode VARCHAR(20) NOT NULL DEFAULT 'connection',
            reseller_price_mode VARCHAR(20) NOT NULL DEFAULT 'connection',
            user_markup_percent DECIMAL(8,2) NULL,
            reseller_markup_percent DECIMAL(8,2) NULL,
            user_fixed_price DECIMAL(16,2) NULL,
            reseller_fixed_price DECIMAL(16,2) NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            supplier_removed_at DATETIME NULL,
            raw_json LONGTEXT NULL,
            inventory_checked_at DATETIME NULL,
            inventory_revision VARCHAR(190) NULL,
            inventory_last_success_at DATETIME NULL,
            inventory_last_error_code VARCHAR(80) NULL,
            inventory_last_error_message VARCHAR(1000) NULL,
            inventory_last_error_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_remote_product (connection_id, remote_product_id),
            KEY idx_supplier_product_connection (connection_id, enabled),
            KEY idx_supplier_product_source (connection_id, remote_source_product_id),
            KEY idx_supplier_product_stock (remote_stock)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_catalog_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            remote_source_product_id VARCHAR(190) NOT NULL,
            local_product_id INT NOT NULL,
            sync_details TINYINT(1) NOT NULL DEFAULT 1,
            local_categories_override_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_catalog_source (connection_id, remote_source_product_id),
            KEY idx_supplier_catalog_local_product (local_product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_catalog_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_product_id BIGINT UNSIGNED NOT NULL,
            local_product_id INT NOT NULL,
            local_variant_id INT NOT NULL,
            api_fallback_enabled TINYINT(1) NOT NULL DEFAULT 1,
            source_priority INT NOT NULL DEFAULT 100,
            max_supplier_cost DECIMAL(16,2) NULL,
            sync_duration TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_catalog_product (supplier_product_id),
            KEY idx_supplier_catalog_variant (local_variant_id),
            KEY idx_supplier_catalog_local (local_product_id, local_variant_id),
            KEY idx_supplier_catalog_fallback (api_fallback_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_category_mappings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            remote_category VARCHAR(255) NOT NULL,
            local_categories_json TEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_category_mapping (connection_id, remote_category),
            KEY idx_supplier_category_connection (connection_id, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_ref VARCHAR(120) NOT NULL,
            source_kind VARCHAR(20) NOT NULL DEFAULT 'storefront',
            source_order_id BIGINT UNSIGNED NULL,
            connection_id BIGINT UNSIGNED NOT NULL,
            supplier_product_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            local_product_id INT NOT NULL,
            local_variant_id INT NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_cost_base DECIMAL(16,2) NOT NULL,
            total_cost_base DECIMAL(16,2) NOT NULL,
            unit_price_base DECIMAL(16,2) NOT NULL,
            total_price_base DECIMAL(16,2) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'submitting',
            supplier_order_id VARCHAR(190) NULL,
            transaction_id BIGINT UNSIGNED NULL,
            response_json LONGTEXT NULL,
            error_message VARCHAR(2000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_order_external_ref (external_ref),
            KEY idx_supplier_order_user (user_id, created_at),
            KEY idx_supplier_order_status (status),
            KEY idx_supplier_order_transaction (transaction_id),
            KEY idx_supplier_order_connection (connection_id, created_at),
            KEY idx_supplier_order_source (source_kind, source_order_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_order_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            key_code TEXT NOT NULL,
            key_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_order_key (order_id, key_hash),
            KEY idx_supplier_order_key_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS supplier_inventory_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            supplier_product_id BIGINT UNSIGNED NULL,
            remote_product_id VARCHAR(190) NOT NULL,
            check_type VARCHAR(40) NOT NULL,
            requested_quantity INT UNSIGNED NOT NULL DEFAULT 1,
            success TINYINT(1) NOT NULL DEFAULT 0,
            confirmed_stock INT NULL,
            http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_code VARCHAR(80) NULL,
            error_message VARCHAR(1000) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_supplier_inventory_connection (connection_id, created_at),
            KEY idx_supplier_inventory_product (supplier_product_id, created_at),
            KEY idx_supplier_inventory_remote (connection_id, remote_product_id, created_at),
            KEY idx_supplier_inventory_failure (success, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($queries as $sql) {
        try {
            if (!$conn->query($sql)) {
                error_log('Store Bridge schema failed: ' . $conn->error);
                return $ready = false;
            }
        } catch (Throwable $e) {
            error_log('Store Bridge schema exception: ' . $e->getMessage());
            return $ready = false;
        }
    }

    $columns = [
        // Some Store Bridge installations predate the transaction link. Ensure
        // the column exists before the non-unique lookup index is added below.
        ['supplier_orders', 'transaction_id', 'BIGINT UNSIGNED NULL'],
        ['supplier_orders', 'source_kind', "VARCHAR(20) NOT NULL DEFAULT 'storefront' AFTER external_ref"],
        ['supplier_orders', 'source_order_id', 'BIGINT UNSIGNED NULL AFTER source_kind'],
        ['store_api_clients', 'deleted_at', 'DATETIME NULL AFTER last_used_at'],
        ['store_api_clients', 'client_type', "ENUM('admin','reseller_self_service') NOT NULL DEFAULT 'admin' AFTER status"],
        ['store_api_clients', 'billing_mode', "ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance' AFTER client_type"],
        ['store_api_clients', 'linked_user_id', 'BIGINT UNSIGNED NULL AFTER billing_mode'],
        ['store_api_clients', 'website_name', 'VARCHAR(190) NULL AFTER linked_user_id'],
        ['store_api_clients', 'website_url', 'VARCHAR(1000) NULL AFTER website_name'],
        ['store_api_clients', 'webhook_url', 'VARCHAR(1000) NULL AFTER website_url'],
        ['store_api_clients', 'webhook_last_test_at', 'DATETIME NULL AFTER webhook_url'],
        ['store_api_clients', 'webhook_last_http_code', 'SMALLINT UNSIGNED NULL AFTER webhook_last_test_at'],
        ['store_api_clients', 'webhook_last_error', 'VARCHAR(1000) NULL AFTER webhook_last_http_code'],
        ['store_api_clients', 'webhook_last_debug_json', 'MEDIUMTEXT NULL AFTER webhook_last_error'],
        ['store_api_clients', 'order_rate_limit_per_minute', 'INT UNSIGNED NOT NULL DEFAULT 10 AFTER rate_limit_per_minute'],
        ['store_api_clients', 'max_order_amount', 'DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER order_rate_limit_per_minute'],
        ['store_api_clients', 'daily_spend_limit', 'DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER max_order_amount'],
        ['store_api_clients', 'source_access_json', 'TEXT NULL AFTER daily_spend_limit'],
        ['store_api_orders', 'billing_mode', "ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance'"],
        ['store_api_orders', 'billing_user_id', 'BIGINT UNSIGNED NULL'],
        ['store_api_orders', 'billing_transaction_id', 'BIGINT UNSIGNED NULL'],
        ['store_api_orders', 'balance_before', 'DECIMAL(16,2) NULL'],
        ['store_api_orders', 'balance_after', 'DECIMAL(16,2) NULL'],
        ['store_api_orders', 'request_fingerprint', 'CHAR(64) NULL'],
        ['store_api_orders', 'fulfillment_source', "VARCHAR(20) NOT NULL DEFAULT 'local' AFTER request_fingerprint"],
        ['store_api_orders', 'fulfillment_reason', 'VARCHAR(80) NULL AFTER fulfillment_source'],
        ['store_api_orders', 'upstream_order_id', 'BIGINT UNSIGNED NULL AFTER fulfillment_reason'],
        ['store_api_orders', 'upstream_reference', 'VARCHAR(190) NULL AFTER upstream_order_id'],
        ['store_api_orders', 'upstream_status', 'VARCHAR(40) NULL AFTER upstream_reference'],
        ['store_api_orders', 'procurement_cost', 'DECIMAL(16,2) NULL AFTER upstream_status'],
        ['store_api_orders', 'refunded_amount', 'DECIMAL(16,2) NULL AFTER procurement_cost'],
        ['store_api_orders', 'refunded_at', 'DATETIME NULL AFTER refunded_amount'],
        ['store_api_order_keys', 'source_type', "VARCHAR(20) NOT NULL DEFAULT 'local' AFTER order_id"],
        ['store_api_order_keys', 'source_order_id', 'BIGINT UNSIGNED NULL AFTER source_key_id'],
        ['supplier_connections', 'user_price_mode', "VARCHAR(20) NOT NULL DEFAULT 'source' AFTER sync_prices"],
        ['supplier_connections', 'reseller_price_mode', "VARCHAR(20) NOT NULL DEFAULT 'source' AFTER user_price_mode"],
        ['supplier_connections', 'protect_below_cost', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER reseller_markup_percent'],
        ['supplier_connections', 'purchase_mode', "VARCHAR(20) NOT NULL DEFAULT 'live'"],
        ['supplier_products', 'source_price_user', 'DECIMAL(16,2) NULL AFTER cost_base'],
        ['supplier_products', 'source_price_reseller', 'DECIMAL(16,2) NULL AFTER source_price_user'],
        ['supplier_products', 'user_price_mode', "VARCHAR(20) NOT NULL DEFAULT 'connection' AFTER source_price_reseller"],
        ['supplier_products', 'reseller_price_mode', "VARCHAR(20) NOT NULL DEFAULT 'connection' AFTER user_price_mode"],
        ['supplier_products', 'user_markup_percent', 'DECIMAL(8,2) NULL AFTER reseller_price_mode'],
        ['supplier_products', 'reseller_markup_percent', 'DECIMAL(8,2) NULL AFTER user_markup_percent'],
        ['supplier_products', 'user_fixed_price', 'DECIMAL(16,2) NULL AFTER reseller_markup_percent'],
        ['supplier_products', 'reseller_fixed_price', 'DECIMAL(16,2) NULL AFTER user_fixed_price'],
        ['supplier_products', 'inventory_revision', 'VARCHAR(190) NULL AFTER inventory_checked_at'],
        ['supplier_products', 'inventory_last_success_at', 'DATETIME NULL AFTER inventory_revision'],
        ['supplier_products', 'inventory_last_error_code', 'VARCHAR(80) NULL AFTER inventory_last_success_at'],
        ['supplier_products', 'inventory_last_error_message', 'VARCHAR(1000) NULL AFTER inventory_last_error_code'],
        ['supplier_products', 'inventory_last_error_at', 'DATETIME NULL AFTER inventory_last_error_message'],
        ['supplier_catalog_products', 'sync_details', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER local_product_id'],
        ['supplier_catalog_products', 'local_categories_override_json', 'TEXT NULL'],
        // Do not request a physical column position on existing databases.
        // Position-independent ADD COLUMN has the best chance of using an instant
        // DDL path on shared hosting and keeps deployment lock time minimal.
        ['supplier_catalog_links', 'source_priority', 'INT NOT NULL DEFAULT 100'],
        ['supplier_catalog_links', 'max_supplier_cost', 'DECIMAL(16,2) NULL'],
        ['supplier_catalog_links', 'sync_duration', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER api_fallback_enabled'],
    ];
    foreach ($columns as $column) {
        try {
            if (!storeBridgeEnsureColumn($column[0], $column[1], $column[2])) {
                error_log('Store Bridge column migration failed: ' . $column[0] . '.' . $column[1] . '; ' . $conn->error);
                return $ready = false;
            }
        } catch (Throwable $e) {
            error_log('Store Bridge column migration exception: ' . $e->getMessage());
            return $ready = false;
        }
    }

    // CGO-delivered Store API keys do not have a local keys.id. Existing
    // installations created source_key_id as NOT NULL, so make it nullable once.
    try {
        $nullability = $conn->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='store_api_order_keys' AND COLUMN_NAME='source_key_id' LIMIT 1");
        $nullRow = $nullability ? $nullability->fetch_assoc() : null;
        if ($nullRow && strtoupper((string) ($nullRow['IS_NULLABLE'] ?? 'NO')) !== 'YES') {
            if (!$conn->query('ALTER TABLE `store_api_order_keys` MODIFY COLUMN `source_key_id` BIGINT UNSIGNED NULL')) {
                error_log('Store API key source nullability migration failed: ' . $conn->error);
                return $ready = false;
            }
        }
    } catch (Throwable $e) {
        error_log('Store API key source nullability migration exception: ' . $e->getMessage());
        return $ready = false;
    }

    // Version 1.0 accidentally made local_variant_id globally unique. That
    // defeated supplier priority/fallback because one storefront variant could
    // never point to more than one API supplier. Convert it to a normal index.
    try {
        $legacyIndex = storeBridgeIndexInfo('supplier_catalog_links', 'uq_supplier_catalog_variant');
        if ($legacyIndex !== null) {
            if (!$conn->query('ALTER TABLE `supplier_catalog_links` DROP INDEX `uq_supplier_catalog_variant`')) {
                error_log('Store Bridge legacy supplier index removal failed: ' . $conn->error);
                return $ready = false;
            }
        }
        if (storeBridgeIndexInfo('supplier_catalog_links', 'idx_supplier_catalog_variant') === null) {
            if (!$conn->query('ALTER TABLE `supplier_catalog_links` ADD INDEX `idx_supplier_catalog_variant` (`local_variant_id`)')) {
                error_log('Store Bridge supplier variant index creation failed: ' . $conn->error);
                return $ready = false;
            }
        }
        if (storeBridgeIndexInfo('supplier_orders', 'idx_supplier_order_transaction') === null) {
            if (!$conn->query('ALTER TABLE `supplier_orders` ADD INDEX `idx_supplier_order_transaction` (`transaction_id`)')) {
                // Optional performance index. Keep Store Bridge available when a
                // shared-hosting database user lacks INDEX/ALTER permission.
                error_log('Store Bridge transaction index creation warning: ' . $conn->error);
            }
        }
        if (storeBridgeIndexInfo('supplier_orders', 'idx_supplier_order_source') === null) {
            if (!$conn->query('ALTER TABLE `supplier_orders` ADD INDEX `idx_supplier_order_source` (`source_kind`,`source_order_id`,`created_at`)')) {
                error_log('Store Bridge supplier source index creation warning: ' . $conn->error);
            }
        }
        if (storeBridgeIndexInfo('store_api_clients', 'idx_store_api_client_linked_user') === null) {
            if (!$conn->query('ALTER TABLE `store_api_clients` ADD INDEX `idx_store_api_client_linked_user` (`linked_user_id`,`client_type`)')) {
                error_log('Store Bridge linked reseller index creation warning: ' . $conn->error);
            }
        }
        if (storeBridgeIndexInfo('store_api_orders', 'idx_store_api_order_billing_user') === null) {
            if (!$conn->query('ALTER TABLE `store_api_orders` ADD INDEX `idx_store_api_order_billing_user` (`billing_user_id`,`created_at`)')) {
                error_log('Store Bridge billing user index creation warning: ' . $conn->error);
            }
        }
        if (storeBridgeIndexInfo('store_api_orders', 'idx_store_api_order_upstream') === null) {
            if (!$conn->query('ALTER TABLE `store_api_orders` ADD INDEX `idx_store_api_order_upstream` (`fulfillment_source`,`upstream_order_id`)')) {
                error_log('Store Bridge upstream order index creation warning: ' . $conn->error);
            }
        }
        if (storeBridgeIndexInfo('store_api_orders', 'idx_store_api_order_billing_transaction') === null) {
            if (!$conn->query('ALTER TABLE `store_api_orders` ADD INDEX `idx_store_api_order_billing_transaction` (`billing_transaction_id`)')) {
                error_log('Store Bridge billing transaction index creation warning: ' . $conn->error);
            }
        }
    } catch (Throwable $e) {
        error_log('Store Bridge supplier index migration exception: ' . $e->getMessage());
        return $ready = false;
    }

    // These identity columns are now part of every Store API order INSERT and
    // idempotency lookup. Treat them as mandatory schema, not an optional
    // cosmetic migration. Failing closed here produces a clear admin/readiness
    // error instead of allowing checkout to reach a later SQL "unknown column"
    // failure after a customer has already attempted an order.
    foreach ([
        ['origin_site_id', 'VARCHAR(100) NULL AFTER customer_email'],
        ['origin_user_id', 'VARCHAR(190) NULL AFTER origin_site_id'],
        ['customer_ref', 'VARCHAR(255) NULL AFTER origin_user_id'],
    ] as $identityColumn) {
        try {
            if (!storeBridgeEnsureColumn('store_api_orders', $identityColumn[0], $identityColumn[1])) {
                error_log('Store Bridge identity migration failed: store_api_orders.' . $identityColumn[0] . '; ' . $conn->error);
                return $ready = false;
            }
        } catch (Throwable $e) {
            error_log('Store Bridge identity migration exception: ' . $e->getMessage());
            return $ready = false;
        }
    }
    if (!storeBridgeRuntimeSchemaReady(true)) {
        error_log('Store Bridge schema migration completed but runtime readiness validation failed');
        return $ready = false;
    }
    // CGO delivery needs NULL source_key_id because supplier keys have no local
    // keys.id. Migration above makes it nullable; validate the property before
    // the schema marker can be recorded as successful.
    if (!storeBridgeColumnNullable('store_api_order_keys', 'source_key_id', true)) {
        error_log('Store Bridge schema migration completed but store_api_order_keys.source_key_id is not nullable');
        return $ready = false;
    }
    return $ready = true;
}

/**
 * Advanced diagnostics must never become a hard dependency of checkout/API.
 * If the hosting DB user cannot CREATE these optional tables, core Store API
 * behavior remains available and the older request log continues to work.
 */
function storeBridgeEnsureDiagnosticSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    $queries = [
        "CREATE TABLE IF NOT EXISTS store_api_request_diagnostics (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id VARCHAR(40) NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            result_code VARCHAR(80) NULL,
            stage VARCHAR(80) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            remote_addr VARCHAR(64) NULL,
            detected_client_ip VARCHAR(64) NULL,
            trusted_proxy TINYINT(1) NOT NULL DEFAULT 0,
            cf_ray VARCHAR(100) NULL,
            http_protocol VARCHAR(30) NULL,
            content_type VARCHAR(120) NULL,
            content_length INT UNSIGNED NULL,
            user_agent VARCHAR(255) NULL,
            request_path VARCHAR(500) NULL,
            detail_json MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_request_diag_request (request_id),
            KEY idx_store_api_request_diag_client (client_id, created_at),
            KEY idx_store_api_request_diag_result (result_code, created_at),
            KEY idx_store_api_request_diag_stage (stage, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS store_api_diagnostic_probe_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            probe_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            request_id VARCHAR(40) NOT NULL,
            remote_addr VARCHAR(64) NULL,
            detected_client_ip VARCHAR(64) NULL,
            trusted_proxy TINYINT(1) NOT NULL DEFAULT 0,
            ip_allowed TINYINT(1) NOT NULL DEFAULT 0,
            cf_ray VARCHAR(100) NULL,
            user_agent VARCHAR(255) NULL,
            detail_json MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_store_api_probe_log_probe (probe_id, created_at),
            KEY idx_store_api_probe_log_client (client_id, created_at),
            KEY idx_store_api_probe_log_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    $tables = ['store_api_request_diagnostics', 'store_api_diagnostic_probe_logs'];
    foreach ($queries as $index => $sql) {
        try {
            $table = $tables[$index] ?? '';
            if ($table !== '' && storeBridgeTableExists($table)) continue;
            if (!$conn->query($sql)) {
                error_log('Store API optional diagnostic schema unavailable table=' . $table . ': ' . $conn->error);
                return $ready = false;
            }
        } catch (Throwable $e) {
            error_log('Store API optional diagnostic schema exception table=' . ($tables[$index] ?? '') . ': ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
            return $ready = false;
        }
    }
    return $ready = true;
}


/**
 * Record a bounded, secret-free diagnostic timeline event.
 *
 * Timelines are evidence, not application state. A logging failure must never
 * alter the Store API result, so this helper deliberately accepts only scalar
 * metadata and silently truncates oversized values.
 */
function storeBridgeTimelineMark(array &$timeline, string $stage, float $startedAt, array $meta = []): void
{
    if (count($timeline) >= 80) return;
    $stage = substr(strtolower(trim($stage)), 0, 80);
    if ($stage === '') return;
    $safeMeta = [];
    foreach ($meta as $key => $value) {
        if (count($safeMeta) >= 12 || !is_scalar($key) || !is_scalar($value)) continue;
        $safeKey = substr(strtolower(trim((string) $key)), 0, 60);
        if ($safeKey === '' || preg_match('/(?:key|token|secret|password|authorization|cookie)/i', $safeKey) === 1) continue;
        if (is_bool($value)) $safeValue = $value;
        elseif (is_int($value) || is_float($value)) $safeValue = $value;
        else $safeValue = storeBridgeDiagnosticSanitizeMessage((string) $value, 300);
        $safeMeta[$safeKey] = $safeValue;
    }
    $row = [
        'stage' => $stage,
        'elapsed_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
    ];
    if ($safeMeta !== []) $row['meta'] = $safeMeta;
    $timeline[] = $row;
}

function storeBridgeSafeTimeline(array $timeline): array
{
    $safe = [];
    foreach ($timeline as $row) {
        if (count($safe) >= 80 || !is_array($row)) break;
        $stage = substr(strtolower(trim((string) ($row['stage'] ?? ''))), 0, 80);
        if ($stage === '') continue;
        $item = [
            'stage' => $stage,
            'elapsed_ms' => max(0, min(86400000, (int) ($row['elapsed_ms'] ?? 0))),
        ];
        if (isset($row['meta']) && is_array($row['meta'])) {
            $meta = [];
            foreach ($row['meta'] as $key => $value) {
                if (count($meta) >= 12 || !is_scalar($key) || !is_scalar($value)) continue;
                $safeKey = substr(strtolower(trim((string) $key)), 0, 60);
                if ($safeKey === '' || preg_match('/(?:key|token|secret|password|authorization|cookie)/i', $safeKey) === 1) continue;
                if (is_bool($value)) $meta[$safeKey] = $value;
                elseif (is_int($value) || is_float($value)) $meta[$safeKey] = $value;
                else $meta[$safeKey] = storeBridgeDiagnosticSanitizeMessage((string) $value, 300);
            }
            if ($meta !== []) $item['meta'] = $meta;
        }
        $safe[] = $item;
    }
    return $safe;
}

/**
 * Supplier-attempt evidence is optional just like the Store API advanced
 * diagnostic tables. It must never become a dependency of ordering/sync.
 */
function supplierBridgeEnsureApiAttemptDiagnosticSchema(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!isset($conn) || !($conn instanceof mysqli)) return $ready = false;
    try {
        if (storeBridgeTableExists('supplier_api_attempt_logs')) return $ready = true;
        $sql = "CREATE TABLE IF NOT EXISTS supplier_api_attempt_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempt_group_id VARCHAR(40) NOT NULL,
            attempt_id VARCHAR(40) NOT NULL,
            connection_id BIGINT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            http_method VARCHAR(10) NOT NULL,
            attempt_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            success TINYINT(1) NOT NULL DEFAULT 0,
            transport_error TINYINT(1) NOT NULL DEFAULT 0,
            http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error_code VARCHAR(100) NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            target_host VARCHAR(255) NULL,
            primary_ip VARCHAR(64) NULL,
            local_ip VARCHAR(64) NULL,
            detail_json MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_api_attempt_id (attempt_id),
            KEY idx_supplier_api_attempt_group (attempt_group_id, attempt_number),
            KEY idx_supplier_api_attempt_connection (connection_id, created_at),
            KEY idx_supplier_api_attempt_result (success, error_code, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            error_log('Supplier API optional attempt diagnostic schema unavailable: ' . $conn->error);
            return $ready = false;
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Supplier API optional attempt diagnostic schema exception: ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
        return $ready = false;
    }
}

function supplierBridgeLogApiAttemptEvidence(array $evidence): void
{
    global $conn;
    if (!supplierBridgeEnsureApiAttemptDiagnosticSchema()) return;
    try {
        $groupId = substr(trim((string) ($evidence['attempt_group_id'] ?? '')), 0, 40);
        $attemptId = substr(trim((string) ($evidence['attempt_id'] ?? '')), 0, 40);
        if ($groupId === '' || $attemptId === '') return;
        $connectionId = isset($evidence['connection']['id']) && is_numeric($evidence['connection']['id'])
            ? max(0, (int) $evidence['connection']['id']) : 0;
        $action = substr(strtolower(trim((string) ($evidence['request']['action'] ?? 'unknown'))), 0, 60);
        $method = substr(strtoupper(trim((string) ($evidence['request']['method'] ?? 'GET'))), 0, 10);
        $attemptNumber = max(1, min(65535, (int) ($evidence['attempt']['number'] ?? 1)));
        $maxAttempts = max(1, min(65535, (int) ($evidence['attempt']['max_attempts'] ?? 1)));
        $success = !empty($evidence['result']['success']) ? 1 : 0;
        $transportError = !empty($evidence['result']['transport_error']) ? 1 : 0;
        $httpCode = max(0, min(65535, (int) ($evidence['response']['http_code'] ?? 0)));
        $errorCode = substr(trim((string) ($evidence['result']['error_code'] ?? '')), 0, 100);
        $durationMs = max(0, min(4294967295, (int) ($evidence['transport']['total_ms'] ?? 0)));
        $targetHost = substr(trim((string) ($evidence['target']['host'] ?? '')), 0, 255);
        $primaryIp = substr(trim((string) ($evidence['transport']['primary_ip'] ?? '')), 0, 64);
        $localIp = substr(trim((string) ($evidence['transport']['local_ip'] ?? '')), 0, 64);
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) return;
        $stmt = $conn->prepare("INSERT IGNORE INTO supplier_api_attempt_logs
            (attempt_group_id,attempt_id,connection_id,action,http_method,attempt_number,max_attempts,success,transport_error,http_code,error_code,duration_ms,target_host,primary_ip,local_ip,detail_json)
            VALUES (?,?,NULLIF(?,0),?,?,?,?,?,?,?,NULLIF(?,''),?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),?)");
        if (!$stmt) return;
        $connectionBind = $connectionId;
        $stmt->bind_param(
            'ssissiiiiisissss',
            $groupId, $attemptId, $connectionBind, $action, $method,
            $attemptNumber, $maxAttempts, $success, $transportError, $httpCode,
            $errorCode, $durationMs, $targetHost, $primaryIp, $localIp, $json
        );
        $inserted = $stmt->execute();
        $insertId = $inserted ? (int) $conn->insert_id : 0;
        if (!$inserted) error_log('Supplier API attempt evidence insert failed attempt_id=' . $attemptId . ': ' . $stmt->error);
        $stmt->close();
        if ($insertId > 0 && $insertId % 500 === 0) {
            try {
                $conn->query('DELETE FROM supplier_api_attempt_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY) LIMIT 3000');
            } catch (Throwable $ignored) {
                // Diagnostic retention must never affect supplier operations.
            }
        }
    } catch (Throwable $e) {
        error_log('Supplier API attempt evidence exception: ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
    }
}

function supplierBridgeGetApiAttemptLogs(int $limit = 100, int $connectionId = 0): array
{
    global $conn;
    $limit = max(1, min(300, $limit));
    if (!supplierBridgeEnsureApiAttemptDiagnosticSchema()) return [];
    try {
        if ($connectionId > 0) {
            $stmt = $conn->prepare("SELECT l.*,c.name AS connection_name FROM supplier_api_attempt_logs l LEFT JOIN supplier_connections c ON c.id=l.connection_id WHERE l.connection_id=? ORDER BY l.id DESC LIMIT ?");
            if (!$stmt) return [];
            $stmt->bind_param('ii', $connectionId, $limit);
        } else {
            $stmt = $conn->prepare("SELECT l.*,c.name AS connection_name FROM supplier_api_attempt_logs l LEFT JOIN supplier_connections c ON c.id=l.connection_id ORDER BY l.id DESC LIMIT ?");
            if (!$stmt) return [];
            $stmt->bind_param('i', $limit);
        }
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['detail_json'] ?? ''), true);
            $row['evidence'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('Supplier API attempt evidence read failed: ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
        return [];
    }
}

function storeBridgeDatabaseFingerprint(): string
{
    global $conn;
    static $fingerprint = null;
    if (is_string($fingerprint)) return $fingerprint;
    $database = '';
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $result = $conn->query('SELECT DATABASE() AS database_name');
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) $result->free();
            $database = trim((string) ($row['database_name'] ?? ''));
        } catch (Throwable $e) {
            $database = '';
        }
    }
    if ($database === '') $database = 'unknown-database';
    return $fingerprint = substr(hash('sha256', $database), 0, 16);
}

function storeBridgeLockName(string $scope, string $identity): string
{
    $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($scope))) ?: 'lock';
    return 'sb:' . substr($scope, 0, 16) . ':' . storeBridgeDatabaseFingerprint() . ':'
        . substr(hash('sha256', $identity), 0, 24);
}

function storeBridgeSecretFile(): string
{
    return dirname(__DIR__, 2) . '/private/store_bridge_secret.php';
}

function storeBridgeLegacySecretFile(): string
{
    return dirname(__DIR__, 2) . '/private/store_bridge_legacy_keys.php';
}

function storeBridgeDecodeEncryptionKey(string $encoded): ?string
{
    $encoded = trim($encoded);
    if ($encoded === '') return null;
    $decoded = base64_decode($encoded, true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
}

function storeBridgeReadEncryptionKeysFromFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) return [];
    try {
        $config = require $path;
    } catch (Throwable $e) {
        error_log('Store Bridge secret file could not be read: ' . basename($path) . '; ' . $e->getMessage());
        return [];
    }
    if (!is_array($config)) return [];
    $encodedKeys = [];
    if (isset($config['encryption_key']) && is_scalar($config['encryption_key'])) {
        $encodedKeys[] = (string) $config['encryption_key'];
    }
    if (isset($config['encryption_keys']) && is_array($config['encryption_keys'])) {
        foreach ($config['encryption_keys'] as $encoded) {
            if (is_scalar($encoded)) $encodedKeys[] = (string) $encoded;
        }
    }
    $keys = [];
    foreach ($encodedKeys as $encoded) {
        $key = storeBridgeDecodeEncryptionKey($encoded);
        if ($key === null) continue;
        $fingerprint = hash('sha256', $key);
        $keys[$fingerprint] = $key;
    }
    return array_values($keys);
}

function storeBridgeExistingEncryptedConnectionCount(): int
{
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return 0;
    try {
        $table = $conn->query("SHOW TABLES LIKE 'supplier_connections'");
        $exists = $table && $table->num_rows > 0;
        if ($table) $table->free();
        if (!$exists) return 0;
        $result = $conn->query("SELECT COUNT(*) AS total FROM supplier_connections WHERE TRIM(COALESCE(api_key_ciphertext,'')) <> ''");
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        return max(0, (int) ($row['total'] ?? 0));
    } catch (Throwable $e) {
        error_log('Store Bridge encrypted-connection check failed: ' . $e->getMessage());
        return 0;
    }
}

function storeBridgeDecryptionKeys(): array
{
    static $cached = null;
    if (is_array($cached)) return $cached;
    $keys = [];
    $primary = storeBridgeEncryptionKey(false);
    if ($primary !== null) $keys[hash('sha256', $primary)] = $primary;
    if (defined('STORE_BRIDGE_LEGACY_KEYS_B64') && is_array(STORE_BRIDGE_LEGACY_KEYS_B64)) {
        foreach (STORE_BRIDGE_LEGACY_KEYS_B64 as $encoded) {
            if (!is_scalar($encoded)) continue;
            $legacy = storeBridgeDecodeEncryptionKey((string) $encoded);
            if ($legacy !== null) $keys[hash('sha256', $legacy)] = $legacy;
        }
    }
    foreach (storeBridgeReadEncryptionKeysFromFile(storeBridgeLegacySecretFile()) as $legacy) {
        $keys[hash('sha256', $legacy)] = $legacy;
    }
    return $cached = array_values($keys);
}

function storeBridgeEncryptionKeyInfo(): array
{
    $source = '';
    $keyId = '';
    $key = null;

    if (defined('STORE_BRIDGE_ENCRYPTION_KEY_B64')) {
        $configKey = storeBridgeDecodeEncryptionKey((string) STORE_BRIDGE_ENCRYPTION_KEY_B64);
        if ($configKey !== null) {
            $source = 'database_config';
            $keyId = defined('STORE_BRIDGE_KEY_ID') ? (string) STORE_BRIDGE_KEY_ID : '';
            $key = $configKey;
        }
    }
    if ($key === null) {
        $rawEnv = trim((string) (getenv('STORE_BRIDGE_ENCRYPTION_KEY') ?: ''));
        $envKey = storeBridgeDecodeEncryptionKey($rawEnv);
        if ($envKey !== null) {
            $source = 'environment';
            $keyId = trim((string) (getenv('STORE_BRIDGE_KEY_ID') ?: 'environment'));
            $key = $envKey;
        }
    }

    if ($key === null) {
        $fileKeys = storeBridgeReadEncryptionKeysFromFile(storeBridgeSecretFile());
        if ($fileKeys !== []) {
            $source = 'legacy_file';
            $keyId = 'legacy-file';
            $key = $fileKeys[0];
        }
    }

    return [
        'configured' => is_string($key) && strlen($key) === 32,
        'site_id' => defined('APP_SITE_ID') ? (string) APP_SITE_ID : (defined('DB_NAME') ? (string) DB_NAME : ''),
        'site_domain' => defined('APP_SITE_DOMAIN') ? (string) APP_SITE_DOMAIN : '',
        'site_label' => defined('APP_SITE_LABEL') ? (string) APP_SITE_LABEL : '',
        'key_id' => $keyId,
        'fingerprint' => is_string($key) ? substr(hash('sha256', $key), 0, 16) : '',
        'source' => $source,
        'key_count' => count(storeBridgeDecryptionKeys()),
        'legacy_file_exists' => is_file(storeBridgeSecretFile()),
    ];
}

function storeBridgeEncryptionKey(bool $create = false): ?string
{
    static $cached = false;
    static $value = null;
    if ($cached && !($create && $value === null)) return $value;
    if ($create && $value === null) $cached = false;

    // private/database.php is authoritative. Environment variables are only a
    // fallback when the config file deliberately leaves the key empty.
    if (defined('STORE_BRIDGE_ENCRYPTION_KEY_B64')) {
        $decoded = storeBridgeDecodeEncryptionKey((string) STORE_BRIDGE_ENCRYPTION_KEY_B64);
        if ($decoded !== null) {
            $cached = true;
            return $value = $decoded;
        }
    }

    $rawEnv = trim((string) (getenv('STORE_BRIDGE_ENCRYPTION_KEY') ?: ''));
    if ($rawEnv !== '') {
        $decoded = base64_decode($rawEnv, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            $cached = true;
            return $value = $decoded;
        }
    }

    $path = storeBridgeSecretFile();
    $fileKeys = storeBridgeReadEncryptionKeysFromFile($path);
    if ($fileKeys !== []) {
        $cached = true;
        return $value = $fileKeys[0];
    }

    if (!$create) {
        $cached = true;
        return null;
    }

    // Never silently generate a replacement key while encrypted supplier
    // credentials already exist. Doing so makes every API connection unreadable.
    $encryptedConnections = storeBridgeExistingEncryptedConnectionCount();
    if ($encryptedConnections > 0) {
        error_log('CRITICAL: Store Bridge encryption key is missing or invalid while '
            . $encryptedConnections . ' encrypted supplier connection(s) exist. Key rotation was refused.');
        $cached = true;
        return null;
    }

    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log('Store Bridge private directory is not writable: ' . $dir);
        $cached = true;
        return null;
    }

    // Serialize first-time key creation. Without this lock, two simultaneous
    // admin requests could generate different keys and invalidate one another.
    $lockHandle = @fopen($path . '.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        if (is_resource($lockHandle)) fclose($lockHandle);
        error_log('Store Bridge secret lock could not be acquired.');
        $cached = true;
        return null;
    }
    try {
        $fileKeys = storeBridgeReadEncryptionKeysFromFile($path);
        if ($fileKeys !== []) {
            $cached = true;
            return $value = $fileKeys[0];
        }
        $encryptedConnections = storeBridgeExistingEncryptedConnectionCount();
        if ($encryptedConnections > 0) {
            error_log('CRITICAL: Store Bridge refused to replace a missing encryption key because encrypted supplier credentials exist.');
            $cached = true;
            return null;
        }
        $key = random_bytes(32);
        $php = "<?php\nreturn ['encryption_key' => '" . base64_encode($key) . "'];\n";
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            error_log('Store Bridge secret file could not be written atomically.');
            $cached = true;
            return null;
        }
        @chmod($path, 0600);
        $cached = true;
        return $value = $key;
    } catch (Throwable $e) {
        error_log('Store Bridge secret generation failed: ' . $e->getMessage());
        $cached = true;
        return null;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @chmod($path . '.lock', 0600);
    }
}

function storeBridgeEncryptSecret(string $plaintext): ?string
{
    $plaintext = trim($plaintext);
    if ($plaintext === '' || strlen($plaintext) > 5000 || !function_exists('openssl_encrypt')) return null;
    $key = storeBridgeEncryptionKey(true);
    if ($key === null) return null;
    try {
        $nonce = random_bytes(12);
    } catch (Throwable $e) {
        return null;
    }
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'supplier_connections.api_key');
    if (!is_string($ciphertext) || strlen($tag) !== 16) return null;
    return 'v1.' . storeBridgeBase64UrlEncode($nonce . $tag . $ciphertext);
}

function storeBridgeDecryptSecret(string $encoded): ?string
{
    static $legacyLogged = [];
    static $failureLogged = [];
    if (strncmp($encoded, 'v1.', 3) !== 0 || !function_exists('openssl_decrypt')) return null;
    $blob = storeBridgeBase64UrlDecode(substr($encoded, 3));
    if (!is_string($blob) || strlen($blob) < 29) return null;
    $nonce = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ciphertext = substr($blob, 28);
    $keys = storeBridgeDecryptionKeys();
    foreach ($keys as $index => $key) {
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'supplier_connections.api_key');
        if (!is_string($plain) || $plain === '') continue;
        if ($index > 0) {
            $fingerprint = substr(hash('sha256', $key), 0, 12);
            if (!isset($legacyLogged[$fingerprint])) {
                $legacyLogged[$fingerprint] = true;
                error_log('Store Bridge decrypted a supplier credential with a legacy key; fingerprint=' . $fingerprint);
            }
        }
        return $plain;
    }
    $cipherFingerprint = substr(hash('sha256', $encoded), 0, 12);
    if (!isset($failureLogged[$cipherFingerprint])) {
        $failureLogged[$cipherFingerprint] = true;
        error_log('CRITICAL: Store Bridge supplier credential cannot be decrypted; ciphertext=' . $cipherFingerprint
            . '; available_keys=' . count($keys) . '. Restore the original private/store_bridge_secret.php.');
    }
    return null;
}

function storeBridgeCurrency(): string
{
    $name = strtoupper(trim((string) getSetting('currency_name', 'THB')));
    return in_array($name, ['THB', 'USD'], true) ? $name : 'THB';
}

function storeBridgeNormalizeBillingMode($value): string
{
    $mode = strtolower(trim((string) $value));
    return $mode === 'reseller_wallet' ? 'reseller_wallet' : 'api_balance';
}

function storeBridgeNormalizeSourceAccess($value): array
{
    $policy = ['cgo' => true, 'supplier_connection_ids' => []];
    if ($value === null || $value === '') return $policy;

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return $policy;
        $value = $decoded;
    }
    if (!is_array($value)) return $policy;

    if (array_key_exists('cgo', $value)) $policy['cgo'] = !empty($value['cgo']);
    $ids = [];
    foreach ((array) ($value['supplier_connection_ids'] ?? []) as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit(trim($id)))) {
            $id = (int) $id;
            if ($id > 0) $ids[$id] = $id;
        }
        if (count($ids) >= 100) break;
    }
    $policy['supplier_connection_ids'] = array_values($ids);
    sort($policy['supplier_connection_ids'], SORT_NUMERIC);
    return $policy;
}

function storeBridgeClientSourceAccess(array $client): array
{
    // NULL/blank is the backwards-compatible policy: LOCAL is always available,
    // CGO remains enabled, and Store Bridge suppliers stay opt-in.
    return storeBridgeNormalizeSourceAccess($client['source_access_json'] ?? null);
}

function storeBridgeEncodeSourceAccess(array $input): string
{
    $policy = storeBridgeNormalizeSourceAccess($input);
    $encoded = json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '{"cgo":true,"supplier_connection_ids":[]}';
}

function storeBridgeClientAllowsCgo(array $client): bool
{
    $policy = storeBridgeClientSourceAccess($client);
    return !empty($policy['cgo']);
}

function storeBridgeClientAllowedSupplierConnectionIds(array $client): array
{
    // Supplier access is opt-in per API client regardless of billing mode.
    // The Store API parent owns the debit/refund: api_balance uses the client's
    // isolated API credit, while reseller_wallet uses the linked users.balance.
    $policy = storeBridgeClientSourceAccess($client);
    return array_values(array_filter(array_map('intval', (array) ($policy['supplier_connection_ids'] ?? [])), static fn($id) => $id > 0));
}

function storeBridgeClientAllowsSupplierConnection(array $client, int $connectionId): bool
{
    if ($connectionId < 1) return false;
    return in_array($connectionId, storeBridgeClientAllowedSupplierConnectionIds($client), true);
}

/**
 * Legacy per-client product rules belong only to the isolated API-credit mode.
 * Linked reseller-wallet clients intentionally use the Store API provider's
 * active Store API product/variant catalogue plus the linked account's effective
 * reseller price. Eligible cached CGO capacity can extend a variant when local
 * stock cannot satisfy the whole order; one order is fulfilled by one source.
 *
 * Keeping this decision in one helper prevents old custom-price/disabled rules
 * from silently leaking into reseller-wallet clients after a billing-mode switch.
 */
function storeBridgeClientUsesLegacyProductRules(array $client): bool
{
    return storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance') === 'api_balance';
}

function storeBridgeGetResellerAccount(int $userId, bool $forUpdate = false): ?array
{
    global $conn;
    if ($userId < 1) return null;
    $sql = "SELECT id,username,email,role,balance,status FROM users WHERE id=? AND role='reseller' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function storeBridgeResellerApiSettings(): array
{
    $mode = strtolower(trim((string) getSetting('store_reseller_api_program_mode', 'off')));
    if (!in_array($mode, ['off', 'pilot', 'all'], true)) $mode = 'off';
    $pilotRaw = trim((string) getSetting('store_reseller_api_pilot_user_ids', ''));
    $pilotIds = [];
    foreach (preg_split('/[\s,;]+/', $pilotRaw) ?: [] as $value) {
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) $pilotIds[(int) $value] = true;
    }

    $defaultRate = max(10, min(5000, (int) getSetting('store_reseller_api_default_rate_limit', '120')));
    $defaultOrderRate = max(1, min(1000, (int) getSetting('store_reseller_api_default_order_rate_limit', '10')));
    $defaultMaxOrder = round((float) getSetting('store_reseller_api_default_max_order_amount', '0'), 2);
    $defaultDailyLimit = round((float) getSetting('store_reseller_api_default_daily_spend_limit', '0'), 2);
    if (!is_finite($defaultMaxOrder) || $defaultMaxOrder < 0 || $defaultMaxOrder > 1000000000) $defaultMaxOrder = 0.0;
    if (!is_finite($defaultDailyLimit) || $defaultDailyLimit < 0 || $defaultDailyLimit > 1000000000) $defaultDailyLimit = 0.0;

    return [
        'mode' => $mode,
        'menu_visible' => getSetting('store_reseller_api_menu_visible', '0') === '1',
        'key_generation' => getSetting('store_reseller_api_key_generation', '0') === '1',
        'pilot_user_ids' => array_keys($pilotIds),
        'pilot_raw' => $pilotRaw,
        'default_rate_limit' => $defaultRate,
        'default_order_rate_limit' => $defaultOrderRate,
        'default_max_order_amount' => $defaultMaxOrder,
        'default_daily_spend_limit' => $defaultDailyLimit,
    ];
}

function storeBridgeResellerApiUserAllowed(int $userId, ?array $settings = null): bool
{
    if ($userId < 1) return false;
    $settings = $settings ?? storeBridgeResellerApiSettings();
    $mode = (string) ($settings['mode'] ?? 'off');
    if ($mode === 'all') return true;
    if ($mode !== 'pilot') return false;
    return in_array($userId, array_map('intval', (array) ($settings['pilot_user_ids'] ?? [])), true);
}

function storeBridgeNormalizeAllowedIpsInput(string $input): array
{
    $input = trim($input);
    if ($input === '') return ['success' => true, 'value' => ''];
    if (strlen($input) > 4000) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP list is too long'];
    $rules = [];
    foreach (preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
        $rule = trim((string) $rule);
        if ($rule === '') continue;
        if (strpos($rule, '/') === false) {
            if (!filter_var($rule, FILTER_VALIDATE_IP)) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid IP address: ' . $rule];
            $rule = storeBridgeCanonicalIp($rule);
            if ($rule === '') return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid IP address'];
        } else {
            [$network, $prefixText] = array_pad(explode('/', $rule, 2), 2, '');
            if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($prefixText)) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid CIDR rule: ' . $rule];
            $packed = @inet_pton($network);
            if ($packed === false) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid CIDR rule: ' . $rule];
            $bits = strlen($packed) * 8;
            $prefix = (int) $prefixText;
            if ($prefix < 0 || $prefix > $bits) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid CIDR prefix: ' . $rule];
            $canonicalNetwork = @inet_ntop($packed);
            if (!is_string($canonicalNetwork) || $canonicalNetwork === '') return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP contains an invalid CIDR rule'];
            $rule = strtolower($canonicalNetwork) . '/' . $prefix;
        }
        $rules[$rule] = true;
        if (count($rules) > 200) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Too many allowed IP/CIDR rules'];
    }
    if ($rules === []) return ['success' => false, 'code' => 'invalid_ip_allowlist', 'message' => 'Allowed IP list does not contain a valid IP/CIDR rule'];
    return ['success' => true, 'value' => implode("\n", array_keys($rules))];
}

/** Normalize optional reseller website metadata shown in the Developer API UI. */
function storeBridgeNormalizeWebsiteName(string $name): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '') return ['success' => true, 'value' => ''];
    if (strlen($name) > 190) return ['success' => false, 'code' => 'invalid_website_name', 'message' => 'Website name is too long'];
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) return ['success' => false, 'code' => 'invalid_website_name', 'message' => 'Website name contains invalid control characters'];
    return ['success' => true, 'value' => $name];
}

/**
 * Validate an external HTTPS URL without making a network request.
 * Server-side requests are validated again and DNS-pinned immediately before
 * cURL to prevent SSRF/DNS-rebinding against private or reserved addresses.
 */
function storeBridgeNormalizeExternalHttpsUrl(string $url, string $field = 'URL'): array
{
    $url = trim($url);
    if ($url === '') return ['success' => true, 'value' => ''];
    if (strlen($url) > 1000) return ['success' => false, 'code' => 'invalid_url', 'message' => $field . ' is too long'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['success' => false, 'code' => 'invalid_url', 'message' => $field . ' is not a valid URL'];
    $parts = parse_url($url);
    if (!is_array($parts)) return ['success' => false, 'code' => 'invalid_url', 'message' => $field . ' is not a valid URL'];
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') return ['success' => false, 'code' => 'https_required', 'message' => $field . ' must use HTTPS'];
    if (isset($parts['user']) || isset($parts['pass'])) return ['success' => false, 'code' => 'url_credentials_not_allowed', 'message' => $field . ' must not contain URL credentials'];
    if (isset($parts['fragment'])) return ['success' => false, 'code' => 'url_fragment_not_allowed', 'message' => $field . ' must not contain a URL fragment'];
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($port !== 443) return ['success' => false, 'code' => 'https_port_required', 'message' => $field . ' must use HTTPS port 443'];
    $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
    if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') $host = substr($host, 1, -1);
    if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9.:\-]+$/', $host)) {
        return ['success' => false, 'code' => 'invalid_url_host', 'message' => $field . ' contains an invalid host'];
    }
    if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
        return ['success' => false, 'code' => 'private_url_not_allowed', 'message' => $field . ' cannot target a local/private host'];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($host, FILTER_VALIDATE_IP, $flags)) {
            return ['success' => false, 'code' => 'private_url_not_allowed', 'message' => $field . ' cannot target a private or reserved IP address'];
        }
    }
    return ['success' => true, 'value' => $url, 'host' => $host, 'port' => 443];
}

function storeBridgeResolvePublicHttpsTarget(string $url, string $field = 'Webhook URL'): array
{
    $field = trim($field) !== '' ? trim($field) : 'URL';
    $normalized = storeBridgeNormalizeExternalHttpsUrl($url, $field);
    if (empty($normalized['success'])) return $normalized;
    $host = (string) ($normalized['host'] ?? '');
    if ($host === '') return ['success' => false, 'code' => 'invalid_url_host', 'message' => $field . ' host is missing'];

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[$host] = true;
    } else {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    foreach (['ip', 'ipv6'] as $key) {
                        $candidate = trim((string) ($record[$key] ?? ''));
                        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) $ips[$candidate] = true;
                    }
                }
            }
        }
        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                foreach ($fallback as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) $ips[$candidate] = true;
                }
            }
        }
    }

    if ($ips === []) return ['success' => false, 'code' => 'dns_resolution_failed', 'message' => $field . ' host could not be resolved'];
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    $public = [];
    foreach (array_keys($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            // Fail closed if even one current answer is private/reserved. This
            // avoids split-DNS hosts where one record could reach internal space.
            return ['success' => false, 'code' => 'private_url_not_allowed', 'message' => $field . ' DNS resolves to a private/reserved address'];
        }
        $packed = @inet_pton($ip);
        $canonical = $packed === false ? false : @inet_ntop($packed);
        if (is_string($canonical) && $canonical !== '') $public[strtolower($canonical)] = true;
    }
    if ($public === []) return ['success' => false, 'code' => 'dns_resolution_failed', 'message' => $field . ' host has no usable public IP address'];
    return ['success' => true, 'url' => (string) $normalized['value'], 'host' => $host, 'port' => 443, 'ips' => array_keys($public)];
}

function storeBridgeUpdateResellerSelfServiceProfile(int $userId, array $input): array
{
    global $conn;
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'API client was not found'];

    $nameResult = storeBridgeNormalizeWebsiteName(is_scalar($input['website_name'] ?? null) ? (string) $input['website_name'] : '');
    if (empty($nameResult['success'])) return $nameResult;
    $websiteResult = storeBridgeNormalizeExternalHttpsUrl(is_scalar($input['website_url'] ?? null) ? (string) $input['website_url'] : '', 'Website URL');
    if (empty($websiteResult['success'])) return $websiteResult;
    $webhookResult = storeBridgeNormalizeExternalHttpsUrl(is_scalar($input['webhook_url'] ?? null) ? (string) $input['webhook_url'] : '', 'Webhook URL');
    if (empty($webhookResult['success'])) return $webhookResult;

    $websiteName = (string) ($nameResult['value'] ?? '');
    $websiteUrl = (string) ($websiteResult['value'] ?? '');
    $webhookUrl = (string) ($webhookResult['value'] ?? '');
    $clientId = (int) $client['id'];
    $stmt = $conn->prepare("UPDATE store_api_clients SET website_name=NULLIF(?,''),website_url=NULLIF(?,''),webhook_url=NULLIF(?,''),webhook_last_test_at=NULL,webhook_last_http_code=NULL,webhook_last_error=NULL,webhook_last_debug_json=NULL WHERE id=? AND linked_user_id=? AND client_type='reseller_self_service' AND deleted_at IS NULL");
    if (!$stmt) return ['success' => false, 'code' => 'profile_update_failed', 'message' => 'Unable to update API website profile'];
    $stmt->bind_param('sssii', $websiteName, $websiteUrl, $webhookUrl, $clientId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true] : ['success' => false, 'code' => 'profile_update_failed', 'message' => 'Unable to update API website profile'];
}

/**
 * Build a redacted URL for diagnostics. Query strings and fragments may carry
 * customer secrets, so they are deliberately excluded from copied debug JSON.
 */
function storeBridgeWebhookDebugUrl(string $url): string
{
    $parts = @parse_url($url);
    if (!is_array($parts)) return '[invalid-url]';
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    $host = (string) ($parts['host'] ?? '');
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') $path = '/';
    $suffix = isset($parts['query']) ? '?[redacted]' : '';
    return $scheme . '://' . $host . $port . $path . $suffix;
}

function storeBridgeRedactWebhookHeaders(string $headers): string
{
    $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
    foreach ($lines as &$line) {
        if (!is_string($line)) continue;
        if (preg_match('/^\s*(set-cookie|cookie|authorization|proxy-authorization|x-api-key)\s*:/i', $line, $m)) {
            $line = $m[1] . ': [redacted]';
        }
    }
    unset($line);
    return trim(implode("\n", $lines));
}

function storeBridgeWebhookHttpVersionName(int $value): string
{
    $map = [];
    foreach ([
        'CURL_HTTP_VERSION_1_0' => 'HTTP/1.0',
        'CURL_HTTP_VERSION_1_1' => 'HTTP/1.1',
        'CURL_HTTP_VERSION_2_0' => 'HTTP/2',
        'CURL_HTTP_VERSION_2' => 'HTTP/2',
        'CURL_HTTP_VERSION_3' => 'HTTP/3',
    ] as $constant => $label) {
        if (defined($constant)) $map[(int) constant($constant)] = $label;
    }
    return $map[$value] ?? ($value > 0 ? 'curl_http_version_' . $value : 'unknown');
}

function storeBridgeDetectWebhookBlockingLayer(int $httpCode, string $headers, string $body): array
{
    $haystack = strtolower($headers . "\n" . $body);
    $checks = [
        ['needle' => 'imunify360', 'name' => 'Imunify360 bot-protection', 'code' => 'webhook_blocked_imunify360'],
        ['needle' => 'modsecurity', 'name' => 'ModSecurity', 'code' => 'webhook_blocked_modsecurity'],
        ['needle' => 'mod_security', 'name' => 'ModSecurity', 'code' => 'webhook_blocked_modsecurity'],
        ['needle' => 'cloudflare', 'name' => 'Cloudflare', 'code' => 'webhook_blocked_cloudflare'],
    ];
    foreach ($checks as $check) {
        if (strpos($haystack, $check['needle']) !== false) {
            return [
                'detected' => true,
                'name' => $check['name'],
                'code' => $check['code'],
                'http_code' => $httpCode,
            ];
        }
    }
    if ($httpCode === 403 && (strpos($haystack, 'access denied') !== false || strpos($haystack, 'forbidden') !== false)) {
        return ['detected' => true, 'name' => 'HTTP access-control/WAF layer', 'code' => 'webhook_blocked_access_control', 'http_code' => $httpCode];
    }
    return ['detected' => false, 'name' => '', 'code' => '', 'http_code' => $httpCode];
}

/** Persist the latest summary plus an append-only admin diagnostic row. */
function storeBridgePersistWebhookTestDebug(int $clientId, int $httpCode, string $storedError, array $debug): string
{
    global $conn;
    $debugJson = json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($debugJson)) $debugJson = '{"debug_version":2,"encoding_error":true}';

    $success = !empty($debug['result']['success']) ? 1 : 0;
    $resultCode = substr(trim((string) ($debug['result']['code'] ?? 'unknown')), 0, 80);
    $resolvedIp = substr(trim((string) ($debug['target']['dns_pinned_ip'] ?? '')), 0, 45);
    if ($resolvedIp === '' && isset($debug['target']['resolved_ips'][0])) $resolvedIp = substr(trim((string) $debug['target']['resolved_ips'][0]), 0, 45);
    $connectedIp = substr(trim((string) ($debug['transport']['primary_ip'] ?? '')), 0, 45);
    $durationMs = max(0, min(4294967295, (int) ($debug['transport']['wall_ms'] ?? $debug['transport']['total_ms'] ?? 0)));
    $storedError = substr(trim($storedError), 0, 1000);

    try {
        $stmt = $conn->prepare("UPDATE store_api_clients SET webhook_last_test_at=NOW(),webhook_last_http_code=NULLIF(?,0),webhook_last_error=NULLIF(?,''),webhook_last_debug_json=? WHERE id=?");
        if ($stmt) {
            $stmt->bind_param('issi', $httpCode, $storedError, $debugJson, $clientId);
            if (!$stmt->execute()) error_log('Store API webhook latest-result update failed client_id=' . $clientId . ': ' . $stmt->error);
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Store API webhook latest-result exception client_id=' . $clientId . ': ' . $e->getMessage());
    }

    try {
        $logStmt = $conn->prepare("INSERT INTO store_api_webhook_test_logs (client_id,success,result_code,http_code,resolved_ip,connected_ip,duration_ms,debug_json) VALUES (?,?,?,NULLIF(?,0),NULLIF(?,''),NULLIF(?,''),?,?)");
        if ($logStmt) {
            $logStmt->bind_param('iisissis', $clientId, $success, $resultCode, $httpCode, $resolvedIp, $connectedIp, $durationMs, $debugJson);
            if (!$logStmt->execute()) error_log('Store API webhook history insert failed client_id=' . $clientId . ': ' . $logStmt->error);
            $logId = (int) $conn->insert_id;
            $logStmt->close();
            if ($logId > 0 && $logId % 100 === 0) {
                try { $conn->query('DELETE FROM store_api_webhook_test_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1000'); }
                catch (Throwable $ignored) {}
            }
        }
    } catch (Throwable $e) {
        error_log('Store API webhook history exception client_id=' . $clientId . ': ' . $e->getMessage());
    }
    return $debugJson;
}

function storeBridgeGetWebhookTestLogs(int $limit = 100): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $limit = max(1, min(500, $limit));
    $result = $conn->query("SELECT l.*,c.name AS client_name,c.website_name,c.webhook_url FROM store_api_webhook_test_logs l LEFT JOIN store_api_clients c ON c.id=l.client_id ORDER BY l.id DESC LIMIT " . $limit);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Execute one bounded server-to-server webhook reachability test for a client.
 * The debug result intentionally contains no Store API key and redacts any
 * webhook query string. Response headers/body are capped to keep DB storage
 * bounded while still exposing WAF/auth/route errors such as HTTP 403.
 */
function storeBridgeTestWebhookClientRow(array $client): array
{
    global $conn;
    $clientId = (int) ($client['id'] ?? 0);
    $url = trim((string) ($client['webhook_url'] ?? ''));
    if ($clientId < 1) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'API client was not found'];
    if ($url === '') return ['success' => false, 'code' => 'webhook_url_missing', 'message' => 'Save a Webhook URL before testing'];

    $testedAt = date(DATE_ATOM);
    if (!function_exists('curl_init')) {
        $debug = [
            'debug_version' => 2, 'tested_at' => $testedAt, 'client_id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'target' => ['url' => storeBridgeWebhookDebugUrl($url)],
            'stage' => 'runtime_prerequisite',
            'result' => ['success' => false, 'code' => 'curl_unavailable', 'message' => 'Server cURL extension is unavailable', 'diagnosis' => 'The provider PHP runtime cannot start the webhook transport because the cURL extension is unavailable.'],
        ];
        $debugJson = storeBridgePersistWebhookTestDebug($clientId, 0, 'Server cURL extension is unavailable', $debug);
        return ['success' => false, 'code' => 'curl_unavailable', 'stage' => 'runtime_prerequisite', 'message' => 'Server cURL extension is unavailable', 'debug' => $debug, 'debug_json' => $debugJson, 'http_code' => 0];
    }
    $target = storeBridgeResolvePublicHttpsTarget($url);
    if (empty($target['success'])) {
        $debug = [
            'debug_version' => 2,
            'tested_at' => $testedAt,
            'client_id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'target' => ['url' => storeBridgeWebhookDebugUrl($url)],
            'stage' => 'target_validation',
            'result' => [
                'success' => false,
                'code' => (string) ($target['code'] ?? 'target_validation_failed'),
                'message' => (string) ($target['message'] ?? 'Webhook target validation failed'),
            ],
        ];
        $httpCode = 0;
        $storedError = (string) ($target['message'] ?? 'Webhook target validation failed');
        $debugJson = storeBridgePersistWebhookTestDebug($clientId, $httpCode, $storedError, $debug);
        $target['debug'] = $debug;
        $target['debug_json'] = $debugJson;
        $target['http_code'] = $httpCode;
        return $target;
    }

    $host = (string) $target['host'];
    $ips = array_values((array) ($target['ips'] ?? []));
    $selectedIp = (string) ($ips[0] ?? '');
    if ($selectedIp === '') {
        $debug = [
            'debug_version' => 2, 'tested_at' => $testedAt, 'client_id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'target' => ['url' => storeBridgeWebhookDebugUrl($url), 'host' => $host, 'resolved_ips' => $ips, 'port' => 443],
            'stage' => 'dns_resolution',
            'result' => ['success' => false, 'code' => 'dns_resolution_failed', 'message' => 'Webhook host has no usable public IP', 'diagnosis' => 'Target validation completed but no usable public destination IP remained for a pinned HTTPS connection.'],
        ];
        $debugJson = storeBridgePersistWebhookTestDebug($clientId, 0, 'Webhook host has no usable public IP', $debug);
        return ['success' => false, 'code' => 'dns_resolution_failed', 'stage' => 'dns_resolution', 'message' => 'Webhook host has no usable public IP', 'debug' => $debug, 'debug_json' => $debugJson, 'http_code' => 0];
    }

    try { $nonce = storeBridgeBase64UrlEncode(random_bytes(18)); }
    catch (Throwable $e) { $nonce = substr(hash('sha256', microtime(true) . mt_rand()), 0, 24); }
    $payload = [
        'event' => 'store_api.webhook_test',
        'test' => true,
        'client_id' => $clientId,
        'website_name' => (string) ($client['website_name'] ?? ''),
        'nonce' => $nonce,
        'sent_at' => $testedAt,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) {
        $debug = [
            'debug_version' => 2, 'tested_at' => $testedAt, 'client_id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'target' => ['url' => storeBridgeWebhookDebugUrl($url), 'host' => $host, 'resolved_ips' => $ips, 'dns_pinned_ip' => $selectedIp, 'port' => 443],
            'stage' => 'payload_encoding',
            'result' => ['success' => false, 'code' => 'encoding_failed', 'message' => 'Unable to encode webhook test payload'],
        ];
        $debugJson = storeBridgePersistWebhookTestDebug($clientId, 0, 'Unable to encode webhook test payload', $debug);
        return ['success' => false, 'code' => 'encoding_failed', 'stage' => 'payload_encoding', 'message' => 'Unable to encode webhook test payload', 'debug' => $debug, 'debug_json' => $debugJson, 'http_code' => 0];
    }

    $requestHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: Sakazuki-Store-API-Webhook-Test/2.1',
        'X-Store-API-Event: store_api.webhook_test',
        'X-Store-API-Test: 1',
    ];
    $responsePreview = '';
    $responseHeaders = '';
    $ch = curl_init($url);
    if (!$ch) {
        $debug = [
            'debug_version' => 2, 'tested_at' => $testedAt, 'client_id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'target' => ['url' => storeBridgeWebhookDebugUrl($url), 'host' => $host, 'resolved_ips' => $ips, 'dns_pinned_ip' => $selectedIp, 'port' => 443],
            'stage' => 'transport_initialization',
            'result' => ['success' => false, 'code' => 'curl_unavailable', 'message' => 'Unable to initialize cURL'],
        ];
        $debugJson = storeBridgePersistWebhookTestDebug($clientId, 0, 'Unable to initialize cURL', $debug);
        return ['success' => false, 'code' => 'curl_unavailable', 'stage' => 'transport_initialization', 'message' => 'Unable to initialize cURL', 'debug' => $debug, 'debug_json' => $debugJson, 'http_code' => 0];
    }
    $resolveIp = strpos($selectedIp, ':') !== false ? '[' . $selectedIp . ']' : $selectedIp;
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$host . ':443:' . $resolveIp],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $chunk) use (&$responseHeaders): int {
            if (strlen($responseHeaders) < 8192) $responseHeaders .= substr($chunk, 0, 8192 - strlen($responseHeaders));
            return strlen($chunk);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responsePreview): int {
            if (strlen($responsePreview) < 4096) $responsePreview .= substr($chunk, 0, 4096 - strlen($responsePreview));
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    curl_setopt_array($ch, $options);
    $started = microtime(true);
    $execOk = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = trim((string) curl_error($ch));
    $info = curl_getinfo($ch);
    $httpCode = (int) ($info['http_code'] ?? 0);
    $primaryIp = trim((string) ($info['primary_ip'] ?? ''));
    $primaryPort = (int) ($info['primary_port'] ?? 0);
    $localIp = trim((string) ($info['local_ip'] ?? ''));
    $localPort = (int) ($info['local_port'] ?? 0);
    $connectTime = (float) ($info['connect_time'] ?? 0);
    $totalTime = (float) ($info['total_time'] ?? 0);
    $nameLookupTime = (float) ($info['namelookup_time'] ?? 0);
    $appConnectTime = (float) ($info['appconnect_time'] ?? 0);
    $startTransferTime = (float) ($info['starttransfer_time'] ?? 0);
    $contentType = trim((string) ($info['content_type'] ?? ''));
    $httpVersion = (int) ($info['http_version'] ?? 0);
    $sslVerifyResult = isset($info['ssl_verify_result']) ? (int) $info['ssl_verify_result'] : null;
    $redirectCount = isset($info['redirect_count']) ? (int) $info['redirect_count'] : 0;
    $effectiveUrl = storeBridgeWebhookDebugUrl((string) ($info['url'] ?? $url));
    $downloadBytes = isset($info['size_download']) ? (int) round((float) $info['size_download']) : 0;
    curl_close($ch);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    $safeResponseHeaders = storeBridgeRedactWebhookHeaders(substr($responseHeaders, 0, 8192));

    $canonicalPrimary = storeBridgeCanonicalIp($primaryIp);
    $canonicalAllowed = array_values(array_filter(array_map('storeBridgeCanonicalIp', $ips)));
    if ($canonicalPrimary !== '' && !in_array($canonicalPrimary, $canonicalAllowed, true)) {
        $execOk = false;
        $errno = -1;
        $error = 'Connected IP did not match the DNS-pinned public target';
        $httpCode = 0;
    }

    $blockingLayer = storeBridgeDetectWebhookBlockingLayer($httpCode, $safeResponseHeaders, $responsePreview);
    $success = $execOk !== false && $errno === 0 && $httpCode >= 200 && $httpCode < 300;
    if ($success) {
        $code = 'webhook_reachable';
        $stage = 'complete';
        $diagnosis = 'Webhook endpoint accepted the server-to-server test.';
    } elseif ($errno !== 0) {
        $code = 'webhook_connection_failed';
        $stage = $connectTime <= 0 ? 'tcp_tls_connect' : 'transport';
        $diagnosis = 'Network/TLS/cURL failed before a successful HTTP response was received.';
    } else {
        $code = !empty($blockingLayer['detected']) ? (string) ($blockingLayer['code'] ?? 'webhook_http_error') : 'webhook_http_error';
        $stage = 'http_response';
        if (!empty($blockingLayer['detected'])) {
            $diagnosis = 'The target server was reached, but ' . (string) ($blockingLayer['name'] ?? 'an access-control layer') . ' rejected the webhook request with HTTP ' . $httpCode . '. This is an HTTP/security-layer rejection, not a TCP timeout.';
        } elseif ($httpCode === 403) {
            $diagnosis = 'The target server was reached and explicitly returned HTTP 403. Check the webhook route, authentication middleware, WAF/ModSecurity, CSRF protection, or a hosting access rule. This is not a TCP timeout.';
        } elseif ($httpCode === 401) {
            $diagnosis = 'The target server was reached but the webhook route requires authentication. A server webhook receiver must accept the configured test event without a browser/admin session.';
        } elseif ($httpCode === 404) {
            $diagnosis = 'The target server was reached but the configured webhook path was not found. Verify the deployed receiver route.';
        } elseif ($httpCode === 405) {
            $diagnosis = 'The target path exists but does not accept POST. Webhook receivers must accept POST JSON.';
        } else {
            $diagnosis = 'The target server returned a non-2xx HTTP response. Inspect response headers/body below for the rejecting layer.';
        }
    }
    $storedError = $success ? '' : ($error !== '' ? $error : ($httpCode > 0 ? 'Webhook returned HTTP ' . $httpCode : 'Webhook test failed'));

    $debug = [
        'debug_version' => 2,
        'tested_at' => $testedAt,
        'client_id' => $clientId,
        'client_type' => (string) ($client['client_type'] ?? ''),
        'website_name' => (string) ($client['website_name'] ?? ''),
        'target' => [
            'url' => storeBridgeWebhookDebugUrl($url),
            'host' => $host,
            'resolved_ips' => $ips,
            'dns_pinned_ip' => $selectedIp,
            'port' => 443,
        ],
        'request' => [
            'method' => 'POST',
            'headers' => $requestHeaders,
            'payload' => $payload,
            'body_bytes' => strlen($body),
        ],
        'transport' => [
            'curl_errno' => $errno,
            'curl_error' => $error,
            'primary_ip' => $primaryIp,
            'primary_port' => $primaryPort,
            'local_ip' => $localIp,
            'local_port' => $localPort,
            'http_version' => storeBridgeWebhookHttpVersionName($httpVersion),
            'ssl_verify_result' => $sslVerifyResult,
            'effective_url' => $effectiveUrl,
            'redirect_count' => $redirectCount,
            'download_bytes' => $downloadBytes,
            'dns_ms' => (int) round($nameLookupTime * 1000),
            'connect_ms' => (int) round($connectTime * 1000),
            'tls_complete_ms' => $appConnectTime > 0 ? (int) round($appConnectTime * 1000) : null,
            'tls_handshake_ms' => $appConnectTime > 0 && $connectTime > 0 ? max(0, (int) round(($appConnectTime - $connectTime) * 1000)) : null,
            'start_transfer_ms' => $startTransferTime > 0 ? (int) round($startTransferTime * 1000) : null,
            'total_ms' => (int) round($totalTime * 1000),
            'wall_ms' => $durationMs,
        ],
        'response' => [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'headers_raw' => $safeResponseHeaders,
            'body_preview' => trim(substr($responsePreview, 0, 4096)),
            'blocking_layer' => !empty($blockingLayer['detected']) ? (string) ($blockingLayer['name'] ?? '') : null,
        ],
        'result' => [
            'success' => $success,
            'code' => $code,
            'stage' => $stage,
            'message' => $success ? 'Webhook endpoint returned a successful HTTP response' : $storedError,
            'diagnosis' => $diagnosis,
            'blocking_layer_detected' => !empty($blockingLayer['detected']),
        ],
    ];
    $debugJson = storeBridgePersistWebhookTestDebug($clientId, $httpCode, $storedError, $debug);

    return [
        'success' => $success,
        'code' => $code,
        'stage' => $stage,
        'message' => $success ? 'Webhook endpoint returned a successful HTTP response' : $storedError,
        'diagnosis' => $diagnosis,
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'connect_ms' => (int) round($connectTime * 1000),
        'total_ms' => (int) round($totalTime * 1000),
        'resolved_ip' => $selectedIp,
        'connected_ip' => $primaryIp,
        'response_headers' => $safeResponseHeaders,
        'response_preview' => trim(substr($responsePreview, 0, 4096)),
        'debug' => $debug,
        'debug_json' => $debugJson,
    ];
}

/** Admin-only caller: retest any active/non-deleted client by id. */
function storeBridgeTestClientWebhook(int $clientId): array
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'API client was not found'];
    $stmt = $conn->prepare('SELECT * FROM store_api_clients WHERE id=? AND deleted_at IS NULL LIMIT 1');
    if (!$stmt) return ['success' => false, 'code' => 'api_client_lookup_failed', 'message' => 'Unable to load API client'];
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$client) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'API client was not found'];
    return storeBridgeTestWebhookClientRow($client);
}

/** Reseller self-service wrapper preserving ownership checks. */
function storeBridgeTestResellerWebhook(int $userId): array
{
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'API client was not found'];
    return storeBridgeTestWebhookClientRow($client);
}

function storeBridgeEndpointSibling(string $filename): string
{
    $endpoint = supplierBridgeCurrentEndpoint();
    $pos = strrpos($endpoint, '/');
    if ($pos === false) return $filename;
    return substr($endpoint, 0, $pos + 1) . ltrim($filename, '/');
}

function storeBridgeCreateDiagnosticProbe(int $userId): array
{
    global $conn;
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return ['success' => false, 'code' => 'api_client_not_found', 'message' => 'Create an API client before creating a server probe'];
    try { $token = 'diag_' . storeBridgeBase64UrlEncode(random_bytes(24)); }
    catch (Throwable $e) { return ['success' => false, 'code' => 'probe_generation_failed', 'message' => 'Unable to generate a diagnostic token']; }
    $hash = hash('sha256', $token);
    $clientId = (int) $client['id'];
    $conn->begin_transaction();
    try {
        // Keep diagnostics bounded. Tokens are short-lived and historical rows
        // older than 30 days have no operational value.
        $conn->query("DELETE FROM store_api_diagnostic_probes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $expireOld = $conn->prepare("UPDATE store_api_diagnostic_probes SET status='expired',expires_at=LEAST(expires_at,NOW()) WHERE client_id=? AND status='waiting' AND expires_at>NOW()");
        if ($expireOld) { $expireOld->bind_param('i', $clientId); $expireOld->execute(); $expireOld->close(); }
        $stmt = $conn->prepare("INSERT INTO store_api_diagnostic_probes (client_id,created_by_user_id,token_hash,status,expires_at) VALUES (?,?,?,'waiting',DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        if (!$stmt) throw new RuntimeException('prepare_failed');
        $stmt->bind_param('iis', $clientId, $userId, $hash);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('insert_failed'); }
        $probeId = (int) $conn->insert_id;
        $stmt->close();
        $conn->commit();
        $base = storeBridgeEndpointSibling('diagnostic.php');
        return ['success' => true, 'probe_id' => $probeId, 'token' => $token, 'url' => $base . '?token=' . rawurlencode($token), 'expires_minutes' => 10];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Store API diagnostic probe creation failed: ' . $e->getMessage());
        return ['success' => false, 'code' => 'probe_generation_failed', 'message' => 'Unable to create diagnostic probe'];
    }
}

function storeBridgeDiagnosticIpAllowed(array $client, string $clientIp): bool
{
    $allowed = trim((string) ($client['allowed_ips'] ?? ''));
    if ($allowed === '') return true;
    foreach (preg_split('/[\s,;]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
        if (storeBridgeIpMatchesRule($clientIp, (string) $rule)) return true;
    }
    return false;
}

function storeBridgeConsumeDiagnosticProbe(string $token, string $requestId = ''): array
{
    global $conn;
    $token = trim($token);
    if ($token === '' || strlen($token) > 200 || substr($token, 0, 5) !== 'diag_') return ['success' => false, 'http_code' => 404, 'code' => 'probe_not_found', 'message' => 'Diagnostic probe was not found'];
    if ($requestId === '') {
        try { $requestId = 'probe_' . substr(bin2hex(random_bytes(10)), 0, 20); }
        catch (Throwable $e) { $requestId = 'probe_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 20); }
    }
    $requestId = substr(trim($requestId), 0, 40);
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT p.*,c.allowed_ips,c.status AS client_status,c.website_name FROM store_api_diagnostic_probes p JOIN store_api_clients c ON c.id=p.client_id WHERE p.token_hash=? AND p.expires_at>=NOW() AND c.deleted_at IS NULL LIMIT 1");
    if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'probe_unavailable', 'message' => 'Diagnostic service is unavailable', 'request_id' => $requestId];
    $stmt->bind_param('s', $hash);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'http_code' => 503, 'code' => 'probe_unavailable', 'message' => 'Diagnostic service is unavailable', 'request_id' => $requestId]; }
    $result = $stmt->get_result();
    $probe = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$probe) return ['success' => false, 'http_code' => 404, 'code' => 'probe_not_found', 'message' => 'Diagnostic probe is invalid or expired', 'request_id' => $requestId];

    $remoteAddr = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $detectedIp = function_exists('getClientIp') ? substr(trim((string) getClientIp()), 0, 64) : $remoteAddr;
    $trusted = function_exists('isTrustedProxyAddress') && isTrustedProxyAddress($remoteAddr);
    $allowed = storeBridgeDiagnosticIpAllowed($probe, $detectedIp);
    $cfRay = substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100);
    $userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $probeId = (int) $probe['id'];
    $clientId = (int) $probe['client_id'];
    $trustedInt = $trusted ? 1 : 0;
    $allowedInt = $allowed ? 1 : 0;
    $allowedRules = preg_split('/[\s,;]+/', trim((string) ($probe['allowed_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $details = [
        'diagnostic_version' => 2,
        'request_id' => $requestId,
        'probe_id' => $probeId,
        'client_id' => $clientId,
        'received_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'request' => [
            'method' => substr(strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))), 0, 10),
            'path' => storeBridgeDiagnosticRequestPath(),
            'host' => substr(trim((string) ($_SERVER['HTTP_HOST'] ?? '')), 0, 255),
            'http_protocol' => substr(trim((string) ($_SERVER['SERVER_PROTOCOL'] ?? '')), 0, 30),
            'https' => function_exists('requestIsHttps') ? requestIsHttps() : null,
            'user_agent' => $userAgent,
        ],
        'network' => [
            'remote_addr' => $remoteAddr,
            'detected_client_ip' => $detectedIp,
            'remote_addr_is_trusted_proxy' => $trusted,
            'trusted_proxy_source' => function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'unknown',
            'trusted_proxy_rule_count' => function_exists('trustedProxyRules') ? count(trustedProxyRules()) : 0,
            'cf_connecting_ip' => $trusted ? substr(trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')), 0, 64) : '',
            'x_forwarded_for' => $trusted ? substr(trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), 0, 500) : '',
            'x_real_ip' => $trusted ? substr(trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')), 0, 64) : '',
            'cf_ray' => $cfRay,
        ],
        'allowlist' => [
            'configured' => $allowedRules !== [],
            'rule_count' => count($allowedRules),
            'match' => $allowed,
        ],
        'interpretation' => $allowed
            ? 'The probe reached PHP. The provider-observed backend IP passes the current allowlist configuration.'
            : 'The probe reached PHP, but the provider-observed backend IP does not match the current allowlist configuration.',
    ];
    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($detailsJson)) $detailsJson = '{"diagnostic_version":2,"encoding_error":true}';

    try {
        $update = $conn->prepare("UPDATE store_api_diagnostic_probes SET status='received',received_at=NOW(),request_count=request_count+1,remote_addr=?,detected_client_ip=?,trusted_proxy=?,ip_allowed=?,cf_ray=NULLIF(?,''),user_agent=NULLIF(?, '') WHERE id=?");
        if ($update) {
            $update->bind_param('ssiissi', $remoteAddr, $detectedIp, $trustedInt, $allowedInt, $cfRay, $userAgent, $probeId);
            if (!$update->execute()) error_log('Store API diagnostic probe summary update failed probe_id=' . $probeId . ': ' . $update->error);
            $update->close();
        }
    } catch (Throwable $e) {
        error_log('Store API diagnostic probe summary exception probe_id=' . $probeId . ': ' . $e->getMessage());
    }

    if (storeBridgeEnsureDiagnosticSchema()) {
        try {
            $log = $conn->prepare("INSERT INTO store_api_diagnostic_probe_logs (probe_id,client_id,request_id,remote_addr,detected_client_ip,trusted_proxy,ip_allowed,cf_ray,user_agent,detail_json) VALUES (?,?,?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),?)");
            if ($log) {
                $log->bind_param('iisssiisss', $probeId, $clientId, $requestId, $remoteAddr, $detectedIp, $trustedInt, $allowedInt, $cfRay, $userAgent, $detailsJson);
                if (!$log->execute()) error_log('Store API diagnostic probe history insert failed probe_id=' . $probeId . ': ' . $log->error);
                $logId = (int) $conn->insert_id;
                $log->close();
                if ($logId > 0 && $logId % 100 === 0) {
                    try { $conn->query('DELETE FROM store_api_diagnostic_probe_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY) LIMIT 1000'); }
                    catch (Throwable $ignored) {}
                }
            }
        } catch (Throwable $e) {
            error_log('Store API diagnostic probe history exception probe_id=' . $probeId . ': ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
        }
    }

    return [
        'success' => true,
        'http_code' => 200,
        'request_id' => $requestId,
        'probe_id' => $probeId,
        'client_id' => $clientId,
        'website_name' => (string) ($probe['website_name'] ?? ''),
        'client_status' => (string) ($probe['client_status'] ?? ''),
        'remote_addr' => $remoteAddr,
        'detected_client_ip' => $detectedIp,
        'remote_addr_is_trusted_proxy' => $trusted,
        'trusted_proxy_source' => function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'unknown',
        'ip_allowlist_configured' => $allowedRules !== [],
        'ip_allowlist_rule_count' => count($allowedRules),
        'ip_allowlist_match' => $allowed,
        'cf_ray' => $cfRay,
        'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'diagnostic' => $details,
    ];
}

function storeBridgeLatestDiagnosticProbe(int $userId): ?array
{
    global $conn;
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return null;
    $clientId = (int) $client['id'];
    $stmt = $conn->prepare('SELECT * FROM store_api_diagnostic_probes WHERE client_id=? AND created_by_user_id=? ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $clientId, $userId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function storeBridgeDecodeDiagnosticJson($raw): ?array
{
    if (!is_string($raw) || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function storeBridgeGetDiagnosticProbeLogs(int $limit = 100, ?int $clientId = null): array
{
    global $conn;
    if (!storeBridgeEnsureSchema() || !storeBridgeEnsureDiagnosticSchema()) return [];
    $limit = max(1, min(500, $limit));
    $baseSql = 'SELECT l.*,p.status AS probe_status,p.created_at AS probe_created_at,c.name AS client_name,c.website_name '
        . 'FROM store_api_diagnostic_probe_logs l '
        . 'LEFT JOIN store_api_diagnostic_probes p ON p.id=l.probe_id '
        . 'LEFT JOIN store_api_clients c ON c.id=l.client_id ';
    if ($clientId !== null && $clientId > 0) {
        $stmt = $conn->prepare($baseSql . 'WHERE l.client_id=? ORDER BY l.id DESC LIMIT ' . $limit);
        if (!$stmt) return [];
        $stmt->bind_param('i', $clientId);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $result = $conn->query($baseSql . 'ORDER BY l.id DESC LIMIT ' . $limit);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach ($rows as &$row) {
        $row['diagnostic'] = storeBridgeDecodeDiagnosticJson($row['detail_json'] ?? null);
        unset($row['detail_json']);
    }
    unset($row);
    return $rows;
}

function storeBridgeGetRecentRequestLogs(int $limit = 100, ?int $clientId = null): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $limit = max(1, min(500, $limit));
    $advanced = storeBridgeEnsureDiagnosticSchema();
    $baseSql = $advanced
        ? 'SELECT r.*,c.name AS client_name,c.website_name,d.result_code,d.stage,d.duration_ms,d.remote_addr,d.detected_client_ip,d.trusted_proxy,d.cf_ray,d.http_protocol,d.content_type,d.content_length,d.user_agent,d.request_path,d.detail_json FROM store_api_request_logs r LEFT JOIN store_api_clients c ON c.id=r.client_id LEFT JOIN store_api_request_diagnostics d ON d.request_id=r.request_id '
        : 'SELECT r.*,c.name AS client_name,c.website_name FROM store_api_request_logs r LEFT JOIN store_api_clients c ON c.id=r.client_id ';
    if ($clientId !== null && $clientId > 0) {
        $stmt = $conn->prepare($baseSql . 'WHERE r.client_id=? ORDER BY r.id DESC LIMIT ' . $limit);
        if (!$stmt) return [];
        $stmt->bind_param('i', $clientId);
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $result = $conn->query($baseSql . 'ORDER BY r.id DESC LIMIT ' . $limit);
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach ($rows as &$row) {
        $row['diagnostic'] = $advanced ? storeBridgeDecodeDiagnosticJson($row['detail_json'] ?? null) : null;
        unset($row['detail_json']);
        $row['result_code'] = $row['result_code'] ?? null;
        $row['stage'] = $row['stage'] ?? null;
        $row['duration_ms'] = isset($row['duration_ms']) ? (int) $row['duration_ms'] : null;
        $row['remote_addr'] = $row['remote_addr'] ?? null;
        $row['detected_client_ip'] = $row['detected_client_ip'] ?? ($row['client_ip'] ?? null);
        $row['trusted_proxy'] = isset($row['trusted_proxy']) ? ((int) $row['trusted_proxy'] === 1) : null;
        $row['cf_ray'] = $row['cf_ray'] ?? null;
    }
    unset($row);
    return $rows;
}

function storeBridgeRecentClientRequestLogs(int $clientId, int $limit = 20): array
{
    if ($clientId < 1) return [];
    return storeBridgeGetRecentRequestLogs(max(1, min(50, $limit)), $clientId);
}

function storeBridgeClientCurrentBalance(array $client, bool $forUpdate = false): ?float
{
    $mode = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
    if ($mode === 'reseller_wallet') {
        $userId = (int) ($client['linked_user_id'] ?? 0);
        $user = storeBridgeGetResellerAccount($userId, $forUpdate);
        if (!$user || (string) ($user['status'] ?? '') !== 'active') return null;
        return round((float) ($user['balance'] ?? 0), 2);
    }
    return round((float) ($client['balance'] ?? 0), 2);
}

/**
 * Reseller-wallet orders need an auditable wallet ledger and a transaction type
 * that can represent the debit without pretending it was a direct local-key
 * purchase. Do not ALTER the shared transactions table from an API request.
 * Instead fail closed and let the admin run the explicit Transaction Integrity
 * migration when an old ENUM schema is still installed.
 *
 * @return array{ready:bool,transaction_type_ready:bool,wallet_ledger_ready:bool,code:string,message:string}
 */
function storeBridgeResellerWalletBillingReadiness(bool $ensureLedger = false): array
{
    $transactionReady = function_exists('transactionIntegrityTypeColumnSupports')
        && transactionIntegrityTypeColumnSupports('store_api_purchase');
    $walletReady = function_exists('ensureWalletLedgerSchema')
        && (!$ensureLedger || ensureWalletLedgerSchema());

    $ready = $transactionReady && $walletReady;
    $message = '';
    if (!$transactionReady) {
        $message = 'transactions.type does not support store_api_purchase; run Transaction Integrity schema migration before enabling reseller-wallet API orders';
    } elseif (!$walletReady) {
        $message = 'Reseller wallet audit ledger is unavailable';
    }
    return [
        'ready' => $ready,
        'transaction_type_ready' => $transactionReady,
        'wallet_ledger_ready' => $walletReady,
        'code' => $transactionReady ? ($walletReady ? '' : 'wallet_ledger_unavailable') : 'billing_schema_unavailable',
        'message' => $message,
    ];
}

function storeBridgeGetResellerSelfServiceClient(int $userId): ?array
{
    global $conn;
    if ($userId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare("SELECT * FROM store_api_clients WHERE linked_user_id=? AND client_type='reseller_self_service' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function storeBridgeCreateResellerSelfServiceClient(int $userId, string $allowedIps = '', array $profile = []): array
{
    global $conn;
    if ($userId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'code' => 'api_unavailable', 'message' => 'API service is unavailable'];
    $settings = storeBridgeResellerApiSettings();
    if (!storeBridgeResellerApiUserAllowed($userId, $settings)) return ['success' => false, 'code' => 'api_program_disabled', 'message' => 'Reseller API access is not enabled for this account'];
    if (empty($settings['key_generation'])) return ['success' => false, 'code' => 'key_generation_disabled', 'message' => 'API key generation is currently disabled'];
    $billingReady = storeBridgeResellerWalletBillingReadiness(true);
    if (empty($billingReady['ready'])) {
        return [
            'success' => false,
            'code' => (string) ($billingReady['code'] ?? 'billing_schema_unavailable'),
            'message' => 'Reseller wallet billing is temporarily unavailable. Contact the administrator.',
        ];
    }
    $ipResult = storeBridgeNormalizeAllowedIpsInput($allowedIps);
    if (empty($ipResult['success'])) return $ipResult;
    $allowedIps = (string) ($ipResult['value'] ?? '');
    $nameResult = storeBridgeNormalizeWebsiteName(is_scalar($profile['website_name'] ?? null) ? (string) $profile['website_name'] : '');
    if (empty($nameResult['success'])) return $nameResult;
    $websiteResult = storeBridgeNormalizeExternalHttpsUrl(is_scalar($profile['website_url'] ?? null) ? (string) $profile['website_url'] : '', 'Website URL');
    if (empty($websiteResult['success'])) return $websiteResult;
    $webhookResult = storeBridgeNormalizeExternalHttpsUrl(is_scalar($profile['webhook_url'] ?? null) ? (string) $profile['webhook_url'] : '', 'Webhook URL');
    if (empty($webhookResult['success'])) return $webhookResult;
    $websiteName = (string) ($nameResult['value'] ?? '');
    $websiteUrl = (string) ($websiteResult['value'] ?? '');
    $webhookUrl = (string) ($webhookResult['value'] ?? '');
    $material = storeBridgeGenerateApiKeyMaterial();
    if ($material === null) return ['success' => false, 'code' => 'key_generation_failed', 'message' => 'Unable to generate a secure API key'];

    $conn->begin_transaction();
    try {
        $user = storeBridgeGetResellerAccount($userId, true);
        if (!$user || (string) ($user['status'] ?? '') !== 'active') throw new RuntimeException('account_inactive');
        if (storeBridgeGetResellerSelfServiceClient($userId)) throw new RuntimeException('api_key_exists');

        $name = 'Reseller: ' . trim((string) ($user['username'] ?? ('#' . $userId)));
        $name = substr($name, 0, 190);
        $currency = storeBridgeCurrency();
        $clientType = 'reseller_self_service';
        $billingMode = 'reseller_wallet';
        $priceTier = 'reseller';
        $multiplier = 1.0;
        $rate = (int) ($settings['default_rate_limit'] ?? 120);
        $orderRate = (int) ($settings['default_order_rate_limit'] ?? 10);
        $maxOrder = round((float) ($settings['default_max_order_amount'] ?? 0), 2);
        $dailyLimit = round((float) ($settings['default_daily_spend_limit'] ?? 0), 2);
        $zero = 0.0;
        $stmt = $conn->prepare("INSERT INTO store_api_clients
            (name,key_hash,key_prefix,key_last4,status,client_type,billing_mode,linked_user_id,website_name,website_url,webhook_url,balance,currency,price_tier,price_multiplier,allowed_ips,rate_limit_per_minute,order_rate_limit_per_minute,max_order_amount,daily_spend_limit,created_by)
            VALUES (?,?,?,?,'active',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) throw new RuntimeException('create_failed');
        $stmt->bind_param(
            'ssssssisssdssdsiiddi',
            $name, $material['hash'], $material['prefix'], $material['last4'],
            $clientType, $billingMode, $userId, $websiteName, $websiteUrl, $webhookUrl,
            $zero, $currency, $priceTier, $multiplier, $allowedIps, $rate, $orderRate,
            $maxOrder, $dailyLimit, $userId
        );
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('create_failed'); }
        $clientId = (int) $conn->insert_id;
        $stmt->close();
        if ($clientId < 1) throw new RuntimeException('create_failed');
        $conn->commit();
        return ['success' => true, 'client_id' => $clientId, 'api_key' => $material['plain']];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $reason = $e->getMessage();
        if ($reason === 'account_inactive') return ['success' => false, 'code' => 'account_inactive', 'message' => 'Reseller account is not active'];
        if ($reason === 'api_key_exists') return ['success' => false, 'code' => 'api_key_exists', 'message' => 'This reseller account already has an API key'];
        error_log('Reseller self-service API creation failed: ' . $reason);
        return ['success' => false, 'code' => 'create_failed', 'message' => 'Unable to create reseller API client'];
    }
}

function storeBridgeUpdateResellerSelfServiceNetwork(int $userId, string $allowedIps): array
{
    global $conn;
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return ['success' => false, 'message' => 'API client was not found'];
    $ipResult = storeBridgeNormalizeAllowedIpsInput($allowedIps);
    if (empty($ipResult['success'])) return $ipResult;
    $allowedIps = (string) ($ipResult['value'] ?? '');
    $clientId = (int) $client['id'];
    $stmt = $conn->prepare("UPDATE store_api_clients SET allowed_ips=? WHERE id=? AND linked_user_id=? AND client_type='reseller_self_service' AND deleted_at IS NULL");
    if (!$stmt) return ['success' => false, 'message' => 'Unable to update IP allowlist'];
    $stmt->bind_param('sii', $allowedIps, $clientId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Unable to update IP allowlist'];
}

function storeBridgeRegenerateResellerSelfServiceKey(int $userId): array
{
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return ['success' => false, 'message' => 'API client was not found'];
    $settings = storeBridgeResellerApiSettings();
    if (!storeBridgeResellerApiUserAllowed($userId, $settings) || empty($settings['key_generation'])) {
        return ['success' => false, 'message' => 'API key generation is currently disabled'];
    }
    $billingReady = storeBridgeResellerWalletBillingReadiness(true);
    if (empty($billingReady['ready'])) {
        return [
            'success' => false,
            'code' => (string) ($billingReady['code'] ?? 'billing_schema_unavailable'),
            'message' => 'Reseller wallet billing is temporarily unavailable. Contact the administrator.',
        ];
    }
    return storeBridgeRegenerateClientKey((int) $client['id']);
}

function storeBridgeSetResellerSelfServiceStatus(int $userId, string $status): bool
{
    global $conn;
    $client = storeBridgeGetResellerSelfServiceClient($userId);
    if (!$client) return false;
    $status = $status === 'active' ? 'active' : 'inactive';
    $clientId = (int) $client['id'];
    $stmt = $conn->prepare("UPDATE store_api_clients SET status=? WHERE id=? AND linked_user_id=? AND client_type='reseller_self_service' AND deleted_at IS NULL");
    if (!$stmt) return false;
    $stmt->bind_param('sii', $status, $clientId, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function storeBridgeGenerateApiKeyMaterial(): ?array
{
    try {
        $plainKey = 'sk_live_' . storeBridgeBase64UrlEncode(random_bytes(32));
    } catch (Throwable $e) {
        return null;
    }
    return [
        'plain' => $plainKey,
        'hash' => hash('sha256', $plainKey),
        'prefix' => substr($plainKey, 0, 16),
        'last4' => substr($plainKey, -4),
    ];
}

function storeBridgeCreateClient(array $input, int $adminId): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Unable to prepare API tables'];
    $name = trim((string) ($input['name'] ?? ''));
    $billingMode = storeBridgeNormalizeBillingMode($input['billing_mode'] ?? 'api_balance');
    $linkedUserId = (int) ($input['linked_user_id'] ?? 0);
    $priceTier = strtolower(trim((string) ($input['price_tier'] ?? 'reseller')));
    $multiplier = round((float) ($input['price_multiplier'] ?? 1), 4);
    $balance = round((float) ($input['balance'] ?? 0), 2);
    $allowedIpsInput = trim((string) ($input['allowed_ips'] ?? ''));
    $rate = max(10, min(5000, (int) ($input['rate_limit_per_minute'] ?? 120)));
    $orderRate = max(1, min(1000, (int) ($input['order_rate_limit_per_minute'] ?? 10)));
    $maxOrder = round((float) ($input['max_order_amount'] ?? 0), 2);
    $dailyLimit = round((float) ($input['daily_spend_limit'] ?? 0), 2);
    if ($name === '' || strlen($name) > 190) return ['success' => false, 'message' => 'Client name is invalid'];
    if (!in_array($priceTier, ['reseller', 'user', 'cost'], true)) return ['success' => false, 'message' => 'Price tier is invalid'];
    if (!is_finite($multiplier) || $multiplier < 0.01 || $multiplier > 100) return ['success' => false, 'message' => 'Price multiplier must be between 0.01 and 100'];
    if (!is_finite($balance) || $balance < 0 || $balance > 1000000000) return ['success' => false, 'message' => 'Opening balance is invalid'];
    if (!is_finite($maxOrder) || $maxOrder < 0 || $maxOrder > 1000000000) return ['success' => false, 'message' => 'Maximum order amount is invalid'];
    if (!is_finite($dailyLimit) || $dailyLimit < 0 || $dailyLimit > 1000000000) return ['success' => false, 'message' => 'Daily spending limit is invalid'];
    $ipResult = storeBridgeNormalizeAllowedIpsInput($allowedIpsInput);
    if (empty($ipResult['success'])) return ['success' => false, 'message' => (string) ($ipResult['message'] ?? 'Allowed IP list is invalid')];
    $allowedIps = (string) ($ipResult['value'] ?? '');
    if ($billingMode === 'reseller_wallet') {
        $billingReady = storeBridgeResellerWalletBillingReadiness(true);
        if (empty($billingReady['ready'])) return ['success' => false, 'message' => (string) ($billingReady['message'] ?? 'Reseller wallet billing is not ready')];
        $linked = storeBridgeGetResellerAccount($linkedUserId, false);
        if (!$linked || (string) ($linked['status'] ?? '') !== 'active') return ['success' => false, 'message' => 'Linked reseller account is invalid or inactive'];
        $balance = 0.0;
    } else {
        $linkedUserId = 0;
    }

    $keyMaterial = storeBridgeGenerateApiKeyMaterial();
    if ($keyMaterial === null) return ['success' => false, 'message' => 'Unable to generate a secure API key'];
    $currency = storeBridgeCurrency();
    $clientType = 'admin';
    $linkedValue = $linkedUserId > 0 ? $linkedUserId : 0;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO store_api_clients
            (name,key_hash,key_prefix,key_last4,client_type,billing_mode,linked_user_id,balance,currency,price_tier,price_multiplier,allowed_ips,rate_limit_per_minute,order_rate_limit_per_minute,max_order_amount,daily_spend_limit,created_by)
            VALUES (?,?,?,?,?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) throw new RuntimeException('Unable to prepare API client');
        $stmt->bind_param(
            'ssssssidssdsiiddi',
            $name, $keyMaterial['hash'], $keyMaterial['prefix'], $keyMaterial['last4'],
            $clientType, $billingMode, $linkedValue, $balance, $currency, $priceTier,
            $multiplier, $allowedIps, $rate, $orderRate, $maxOrder, $dailyLimit, $adminId
        );
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to create API client'); }
        $clientId = (int) $conn->insert_id;
        $stmt->close();
        if ($clientId < 1) throw new RuntimeException('Unable to create API client');

        if ($billingMode === 'api_balance' && $balance > 0) {
            $ledger = $conn->prepare("INSERT INTO store_api_balance_ledger (client_id, entry_type, amount, balance_after, note, admin_id) VALUES (?, 'opening_balance', ?, ?, 'Opening balance', ?)");
            if (!$ledger) throw new RuntimeException('Unable to prepare opening balance ledger');
            $ledger->bind_param('iddi', $clientId, $balance, $balance, $adminId);
            if (!$ledger->execute()) { $ledger->close(); throw new RuntimeException('Unable to save opening balance ledger'); }
            $ledger->close();
        }
        $conn->commit();
        return ['success' => true, 'client_id' => $clientId, 'api_key' => $keyMaterial['plain']];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Store API client creation failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to create API client'];
    }
}

function storeBridgeAdjustClientBalance(int $clientId, float $amount, string $note, int $adminId): array
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema() || !is_finite($amount) || abs($amount) < 0.005 || abs($amount) > 1000000000) {
        return ['success' => false, 'message' => 'Invalid balance adjustment'];
    }
    $client = storeBridgeGetClient($clientId);
    if (!$client) return ['success' => false, 'message' => 'API client not found'];
    if (storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance') !== 'api_balance') {
        return ['success' => false, 'message' => 'This client uses the linked reseller wallet. Adjust the reseller account balance instead.'];
    }
    $note = substr(trim($note), 0, 500);
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT balance,billing_mode FROM store_api_clients WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('Unable to lock API client');
        $lock->bind_param('i', $clientId);
        $lock->execute();
        $result = $lock->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$row) throw new RuntimeException('API client not found');
        if (storeBridgeNormalizeBillingMode($row['billing_mode'] ?? 'api_balance') !== 'api_balance') {
            throw new RuntimeException('This client uses the linked reseller wallet. Adjust the reseller account balance instead.');
        }
        $newBalance = round((float) $row['balance'] + $amount, 2);
        if ($newBalance < 0) throw new RuntimeException('Balance cannot become negative');
        $update = $conn->prepare('UPDATE store_api_clients SET balance = ? WHERE id = ?');
        if (!$update) throw new RuntimeException('Unable to prepare balance update');
        $update->bind_param('di', $newBalance, $clientId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('Unable to update balance');
        }
        $update->close();
        $type = $amount > 0 ? 'admin_credit' : 'admin_debit';
        $ledger = $conn->prepare('INSERT INTO store_api_balance_ledger (client_id, entry_type, amount, balance_after, note, admin_id) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$ledger) throw new RuntimeException('Unable to prepare balance ledger');
        $ledger->bind_param('isddsi', $clientId, $type, $amount, $newBalance, $note, $adminId);
        if (!$ledger->execute()) {
            $ledger->close();
            throw new RuntimeException('Unable to save balance ledger');
        }
        $ledger->close();
        $conn->commit();
        return ['success' => true, 'balance' => $newBalance];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function storeBridgeGetClient(int $clientId): ?array
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare('SELECT * FROM store_api_clients WHERE id=? AND deleted_at IS NULL LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function storeBridgeUpdateClient(int $clientId, array $input): array
{
    global $conn;
    $client = storeBridgeGetClient($clientId);
    if (!$client) return ['success' => false, 'message' => 'API client was not found'];
    $name = trim((string) ($input['name'] ?? ''));
    $billingMode = storeBridgeNormalizeBillingMode($input['billing_mode'] ?? ($client['billing_mode'] ?? 'api_balance'));
    $linkedUserId = (int) ($input['linked_user_id'] ?? ($client['linked_user_id'] ?? 0));
    $priceTier = strtolower(trim((string) ($input['price_tier'] ?? 'reseller')));
    $multiplier = round((float) ($input['price_multiplier'] ?? 1), 4);
    $allowedIpsInput = trim((string) ($input['allowed_ips'] ?? ''));
    $rate = max(10, min(5000, (int) ($input['rate_limit_per_minute'] ?? 120)));
    $orderRate = max(1, min(1000, (int) ($input['order_rate_limit_per_minute'] ?? 10)));
    $maxOrder = round((float) ($input['max_order_amount'] ?? 0), 2);
    $dailyLimit = round((float) ($input['daily_spend_limit'] ?? 0), 2);
    $sourcePolicy = array_key_exists('source_access', $input) && is_array($input['source_access'])
        ? storeBridgeNormalizeSourceAccess($input['source_access'])
        : storeBridgeClientSourceAccess($client);
    if ($name === '' || strlen($name) > 190) return ['success' => false, 'message' => 'Client name is invalid'];
    if (!in_array($priceTier, ['reseller', 'user', 'cost'], true)) return ['success' => false, 'message' => 'Price tier is invalid'];
    if (!is_finite($multiplier) || $multiplier < 0.01 || $multiplier > 100) return ['success' => false, 'message' => 'Price multiplier must be between 0.01 and 100'];
    if (!is_finite($maxOrder) || $maxOrder < 0 || $maxOrder > 1000000000) return ['success' => false, 'message' => 'Maximum order amount is invalid'];
    if (!is_finite($dailyLimit) || $dailyLimit < 0 || $dailyLimit > 1000000000) return ['success' => false, 'message' => 'Daily spending limit is invalid'];
    $ipResult = storeBridgeNormalizeAllowedIpsInput($allowedIpsInput);
    if (empty($ipResult['success'])) return ['success' => false, 'message' => (string) ($ipResult['message'] ?? 'Allowed IP list is invalid')];
    $allowedIps = (string) ($ipResult['value'] ?? '');
    if ((string) ($client['client_type'] ?? 'admin') === 'reseller_self_service') {
        $billingMode = 'reseller_wallet';
        $linkedUserId = (int) ($client['linked_user_id'] ?? 0);
    }
    $currentBillingMode = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
    if ($currentBillingMode === 'api_balance' && $billingMode === 'reseller_wallet' && abs((float) ($client['balance'] ?? 0)) >= 0.005) {
        return ['success' => false, 'message' => 'Set the legacy API balance to 0 before switching this client to reseller_wallet. This prevents hidden/stranded API credit.'];
    }
    if ($billingMode === 'reseller_wallet') {
        $billingReady = storeBridgeResellerWalletBillingReadiness(true);
        if (empty($billingReady['ready'])) return ['success' => false, 'message' => (string) ($billingReady['message'] ?? 'Reseller wallet billing is not ready')];
        $linked = storeBridgeGetResellerAccount($linkedUserId, false);
        if (!$linked || (string) ($linked['status'] ?? '') !== 'active') return ['success' => false, 'message' => 'Linked reseller account is invalid or inactive'];
    } else {
        $linkedUserId = 0;
    }
    $sourceAccessJson = storeBridgeEncodeSourceAccess($sourcePolicy);
    $linkedValue = $linkedUserId > 0 ? $linkedUserId : 0;
    $conn->begin_transaction();
    try {
        // Re-check the authoritative row under lock. The earlier validation is
        // only for fast feedback; without this lock an admin balance adjustment
        // racing a billing-mode switch could strand legacy API credit inside a
        // reseller_wallet client.
        $clientLock = $conn->prepare('SELECT billing_mode,balance,client_type,linked_user_id FROM store_api_clients WHERE id=? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        if (!$clientLock) throw new RuntimeException('Unable to lock API client');
        $clientLock->bind_param('i', $clientId);
        if (!$clientLock->execute()) { $clientLock->close(); throw new RuntimeException('Unable to lock API client'); }
        $clientLockResult = $clientLock->get_result();
        $lockedClient = $clientLockResult ? $clientLockResult->fetch_assoc() : null;
        $clientLock->close();
        if (!$lockedClient) throw new RuntimeException('API client was not found');

        $lockedCurrentBillingMode = storeBridgeNormalizeBillingMode($lockedClient['billing_mode'] ?? 'api_balance');
        if ((string) ($lockedClient['client_type'] ?? 'admin') === 'reseller_self_service') {
            $billingMode = 'reseller_wallet';
            $linkedUserId = (int) ($lockedClient['linked_user_id'] ?? 0);
            $linkedValue = $linkedUserId > 0 ? $linkedUserId : 0;
        }
        if ($lockedCurrentBillingMode === 'api_balance' && $billingMode === 'reseller_wallet' && abs((float) ($lockedClient['balance'] ?? 0)) >= 0.005) {
            throw new RuntimeException('Set the legacy API balance to 0 before switching this client to reseller_wallet. This prevents hidden/stranded API credit.');
        }

        $stmt = $conn->prepare('UPDATE store_api_clients SET name=?,billing_mode=?,linked_user_id=NULLIF(?,0),price_tier=?,price_multiplier=?,allowed_ips=?,rate_limit_per_minute=?,order_rate_limit_per_minute=?,max_order_amount=?,daily_spend_limit=?,source_access_json=? WHERE id=? AND deleted_at IS NULL');
        if (!$stmt) throw new RuntimeException('Unable to prepare API client update');
        $stmt->bind_param('ssisdsiiddsi', $name, $billingMode, $linkedValue, $priceTier, $multiplier, $allowedIps, $rate, $orderRate, $maxOrder, $dailyLimit, $sourceAccessJson, $clientId);
        if (!$stmt->execute() || $stmt->affected_rows < 0) {
            $stmt->close();
            throw new RuntimeException('Unable to update API client');
        }
        $stmt->close();

        // Linked-wallet clients intentionally use the Store API local catalogue.
        // Remove legacy per-key visibility/custom-price rules atomically while
        // wallet mode is active and on either billing-mode transition. This also
        // fences a stale rule write that raced an earlier mode switch from
        // reappearing when the client returns to API credit.
        $clearedRules = 0;
        if ($billingMode === 'reseller_wallet' || $lockedCurrentBillingMode !== $billingMode) {
            $deleteRules = $conn->prepare('DELETE FROM store_api_client_products WHERE client_id=?');
            if (!$deleteRules) throw new RuntimeException('Unable to clear legacy API product rules');
            $deleteRules->bind_param('i', $clientId);
            if (!$deleteRules->execute()) {
                $deleteRules->close();
                throw new RuntimeException('Unable to clear legacy API product rules');
            }
            $clearedRules = max(0, (int) $deleteRules->affected_rows);
            $deleteRules->close();
        }

        $conn->commit();
        return ['success' => true, 'cleared_legacy_product_rules' => $clearedRules];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Store API client update failed for #' . $clientId . ': ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function storeBridgeRegenerateClientKey(int $clientId): array
{
    global $conn;
    if (!storeBridgeGetClient($clientId)) return ['success' => false, 'message' => 'API client was not found'];
    $material = storeBridgeGenerateApiKeyMaterial();
    if ($material === null) return ['success' => false, 'message' => 'Unable to generate a secure API key'];
    $stmt = $conn->prepare('UPDATE store_api_clients SET key_hash=?,key_prefix=?,key_last4=?,status=\'active\' WHERE id=? AND deleted_at IS NULL');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare API key regeneration'];
    $stmt->bind_param('sssi', $material['hash'], $material['prefix'], $material['last4'], $clientId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true, 'api_key' => $material['plain']] : ['success' => false, 'message' => 'Unable to regenerate API key'];
}

function storeBridgeDeleteClient(int $clientId): array
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Invalid API client'];
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id,name FROM store_api_clients WHERE id=? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        if (!$lock) throw new RuntimeException('Unable to lock API client');
        $lock->bind_param('i', $clientId);
        $lock->execute();
        $result = $lock->get_result();
        $client = $result ? $result->fetch_assoc() : null;
        $lock->close();
        if (!$client) throw new RuntimeException('API client was not found');
        $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM store_api_orders WHERE client_id=?');
        if (!$countStmt) throw new RuntimeException('Unable to inspect API order history');
        $countStmt->bind_param('i', $clientId);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $orderCount = (int) (($countResult ? $countResult->fetch_assoc() : [])['c'] ?? 0);
        $countStmt->close();
        $deleteRules = $conn->prepare('DELETE FROM store_api_client_products WHERE client_id=?');
        if (!$deleteRules) throw new RuntimeException('Unable to remove API pricing rules');
        $deleteRules->bind_param('i', $clientId);
        if (!$deleteRules->execute()) { $deleteRules->close(); throw new RuntimeException('Unable to remove API pricing rules'); }
        $deleteRules->close();
        if ($orderCount === 0) {
            foreach (['store_api_request_logs', 'store_api_balance_ledger'] as $table) {
                $stmt = $conn->prepare('DELETE FROM `' . $table . '` WHERE client_id=?');
                if (!$stmt) throw new RuntimeException('Unable to clean API client history');
                $stmt->bind_param('i', $clientId);
                if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to clean API client history'); }
                $stmt->close();
            }
            $delete = $conn->prepare('DELETE FROM store_api_clients WHERE id=?');
            if (!$delete) throw new RuntimeException('Unable to delete API client');
            $delete->bind_param('i', $clientId);
            if (!$delete->execute() || $delete->affected_rows !== 1) { $delete->close(); throw new RuntimeException('Unable to delete API client'); }
            $delete->close();
            $mode = 'hard';
        } else {
            try { $tombstone = hash('sha256', 'deleted:' . $clientId . ':' . bin2hex(random_bytes(24))); }
            catch (Throwable $e) { $tombstone = hash('sha256', 'deleted:' . $clientId . ':' . microtime(true)); }
            $deletedName = substr((string) $client['name'], 0, 160) . ' [deleted #' . $clientId . ']';
            $prefix = 'deleted_' . $clientId;
            $last4 = '----';
            $soft = $conn->prepare("UPDATE store_api_clients SET name=?,key_hash=?,key_prefix=?,key_last4=?,status='inactive',allowed_ips='',deleted_at=NOW() WHERE id=?");
            if (!$soft) throw new RuntimeException('Unable to revoke API client');
            $soft->bind_param('ssssi', $deletedName, $tombstone, $prefix, $last4, $clientId);
            if (!$soft->execute() || $soft->affected_rows !== 1) { $soft->close(); throw new RuntimeException('Unable to revoke API client'); }
            $soft->close();
            $mode = 'revoked';
        }
        $conn->commit();
        return ['success' => true, 'mode' => $mode, 'order_count' => $orderCount];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function storeBridgeGetClientCatalogue(int $clientId): array
{
    global $conn;
    $client = storeBridgeGetClient($clientId);
    if (!$client) return [];
    $useLegacyProductRules = storeBridgeClientUsesLegacyProductRules($client);
    ensureProductArchiveTables();
    ensureProductPlatformsTable();
    ensureProductCategoryLinksTable();

    $sql = "SELECT p.id AS source_product_id,p.name,p.status AS product_status,p.category AS legacy_category,
                   pcl.categories_concat,
                   pv.id AS source_variant_id,pv.duration,pv.status AS variant_status,
                   pv.price_user,pv.price_reseller,pv.cost_price,COALESCE(pp.platform,'both') AS platform,
                   rule.enabled AS rule_enabled,rule.custom_price,
                   COUNT(k.id) AS remote_stock
            FROM products p
            JOIN product_variants pv ON pv.product_id=p.id
            LEFT JOIN product_platforms pp ON pp.product_id=p.id
            LEFT JOIN (
                SELECT product_id,GROUP_CONCAT(category ORDER BY id SEPARATOR '||') AS categories_concat
                FROM product_category_links
                GROUP BY product_id
            ) pcl ON pcl.product_id=p.id
            LEFT JOIN product_admin_archives paa ON paa.product_id=p.id
            LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
            LEFT JOIN store_api_client_products rule ON rule.client_id=? AND rule.source_variant_id=pv.id
            LEFT JOIN `keys` k ON k.product_id=p.id AND k.status='available'
                 AND (k.variant_id=pv.id OR (k.variant_id IS NULL AND k.duration=pv.duration))
            WHERE paa.product_id IS NULL AND pva.variant_id IS NULL
            GROUP BY p.id,p.name,p.status,p.category,pcl.categories_concat,
                     pv.id,pv.duration,pv.status,pv.price_user,pv.price_reseller,pv.cost_price,
                     pp.platform,rule.enabled,rule.custom_price
            ORDER BY (COUNT(k.id) > 0) DESC,p.name ASC,pv.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $clientId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    foreach ($rows as &$row) {
        if (!$useLegacyProductRules) {
            // Linked-wallet clients use the Store API local catalogue. Legacy
            // API price/visibility rules are deliberately ignored in this mode.
            $row['custom_price'] = null;
            $row['rule_enabled'] = 1;
        }
        $baseRow = $row;
        $baseRow['custom_price'] = null;
        $row['default_api_price'] = storeBridgeClientPrice($baseRow, $client);
        $row['final_api_price'] = storeBridgeClientPrice($row, $client);
        $row['rule_enabled'] = $row['rule_enabled'] === null ? 1 : (int) $row['rule_enabled'];
        $row['categories'] = supplierBridgeNormalizeCategories(
            (string) ($row['categories_concat'] ?? ''),
            (string) ($row['legacy_category'] ?? '')
        );
        $row['category_text'] = implode(' · ', $row['categories']);
    }
    unset($row);
    return $rows;
}


function storeBridgeSaveClientProductRule(int $clientId, int $variantId, bool $enabled, $customPrice): array
{
    global $conn;
    $client = storeBridgeGetClient($clientId);
    if (!$client) return ['success' => false, 'message' => 'API client was not found'];
    if (!storeBridgeClientUsesLegacyProductRules($client)) {
        return ['success' => false, 'message' => 'Per-product API rules are disabled for reseller_wallet clients. Linked-wallet APIs use the active local Store API catalogue and the linked account effective reseller price.'];
    }
    $variantStmt = $conn->prepare('SELECT id FROM product_variants WHERE id=? LIMIT 1');
    if (!$variantStmt) return ['success' => false, 'message' => 'Unable to inspect product variant'];
    $variantStmt->bind_param('i', $variantId);
    $variantStmt->execute();
    $variantResult = $variantStmt->get_result();
    $variant = $variantResult ? $variantResult->fetch_assoc() : null;
    $variantStmt->close();
    if (!$variant || isProductVariantAdminArchived($variantId)) return ['success' => false, 'message' => 'Product variant was not found'];
    $price = null;
    if ($customPrice !== null && trim((string) $customPrice) !== '') {
        if (!is_numeric($customPrice)) return ['success' => false, 'message' => 'Custom API price is invalid'];
        $price = round((float) $customPrice, 2);
        if (!is_finite($price) || $price < 0 || $price > 1000000000) return ['success' => false, 'message' => 'Custom API price is invalid'];
    }
    $enabledInt = $enabled ? 1 : 0;
    if ($price === null) {
        $stmt = $conn->prepare('INSERT INTO store_api_client_products (client_id,source_variant_id,enabled,custom_price) VALUES (?,?,?,NULL) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),custom_price=NULL');
        if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare API product rule'];
        $stmt->bind_param('iii', $clientId, $variantId, $enabledInt);
    } else {
        $stmt = $conn->prepare('INSERT INTO store_api_client_products (client_id,source_variant_id,enabled,custom_price) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),custom_price=VALUES(custom_price)');
        if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare API product rule'];
        $stmt->bind_param('iiid', $clientId, $variantId, $enabledInt, $price);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Unable to save API product rule'];
}

function storeBridgeResetClientProductRule(int $clientId, int $variantId): bool
{
    global $conn;
    if ($clientId < 1 || $variantId < 1 || !storeBridgeEnsureSchema()) return false;
    $client = storeBridgeGetClient($clientId);
    if (!$client || !storeBridgeClientUsesLegacyProductRules($client)) return false;
    $stmt = $conn->prepare('DELETE FROM store_api_client_products WHERE client_id=? AND source_variant_id=?');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $clientId, $variantId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function storeBridgeSetClientStatus(int $clientId, string $status): bool
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema()) return false;
    $status = $status === 'active' ? 'active' : 'inactive';
    $stmt = $conn->prepare('UPDATE store_api_clients SET status = ? WHERE id = ? AND deleted_at IS NULL');
    if (!$stmt) return false;
    $stmt->bind_param('si', $status, $clientId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function storeBridgeGetClients(): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $result = $conn->query("SELECT c.*,u.username AS linked_username,u.email AS linked_email,u.balance AS linked_wallet_balance,u.status AS linked_user_status,
        (SELECT COUNT(*) FROM store_api_orders o WHERE o.client_id = c.id) AS order_count,
        (SELECT COALESCE(SUM(o.total_price),0) FROM store_api_orders o WHERE o.client_id = c.id AND o.status = 'success') AS total_spent
        FROM store_api_clients c
        LEFT JOIN users u ON u.id=c.linked_user_id AND u.role='reseller'
        WHERE c.deleted_at IS NULL ORDER BY c.id DESC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function storeBridgeGetProviderOrders(int $limit = 100): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $limit = max(1, min(500, $limit));
    $result = $conn->query("SELECT o.*, c.name AS client_name, u.username AS billing_username,
        (SELECT COUNT(*) FROM store_api_order_keys ok WHERE ok.order_id = o.id) AS delivered_count
        FROM store_api_orders o JOIN store_api_clients c ON c.id = o.client_id
        LEFT JOIN users u ON u.id=o.billing_user_id
        ORDER BY o.id DESC LIMIT " . $limit);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function storeBridgeCanonicalIp(string $ip): string
{
    $packed = @inet_pton(trim($ip));
    if ($packed === false) return '';
    $canonical = @inet_ntop($packed);
    return is_string($canonical) ? strtolower($canonical) : '';
}

function storeBridgeIpMatchesRule(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') return false;
    $canonicalIp = storeBridgeCanonicalIp($ip);
    if ($canonicalIp === '') return false;
    if (strpos($rule, '/') === false) {
        $canonicalRule = storeBridgeCanonicalIp($rule);
        return $canonicalRule !== '' && hash_equals($canonicalRule, $canonicalIp);
    }
    [$network, $prefixText] = array_pad(explode('/', $rule, 2), 2, '');
    if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($prefixText)) return false;
    $ipBin = @inet_pton($canonicalIp);
    $netBin = @inet_pton($network);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $prefix = (int) $prefixText;
    $bits = strlen($ipBin) * 8;
    if ($prefix < 0 || $prefix > $bits) return false;
    $bytes = intdiv($prefix, 8);
    $remain = $prefix % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($remain > 0) {
        $mask = (0xFF << (8 - $remain)) & 0xFF;
        if ((ord($ipBin[$bytes]) & $mask) !== (ord($netBin[$bytes]) & $mask)) return false;
    }
    return true;
}

function storeBridgeAuthenticateRequest(): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [
        'success' => false,
        'http_code' => 503,
        'code' => 'schema_not_ready',
        'message' => 'Store API schema upgrade is not ready yet',
        'order_created' => false,
        'definitive_failure' => true,
    ];
    $key = '';
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) $key = trim($m[1]);
    if ($key === '') $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key === '' || strlen($key) > 500) return ['success' => false, 'http_code' => 401, 'code' => 'missing_api_key', 'message' => 'Missing API key'];
    $hash = hash('sha256', $key);
    $stmt = $conn->prepare("SELECT * FROM store_api_clients WHERE key_hash = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'auth_unavailable', 'message' => 'API authentication is unavailable'];
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$client) return ['success' => false, 'http_code' => 401, 'code' => 'invalid_api_key', 'message' => 'Invalid API key'];

    $clientType = (string) ($client['client_type'] ?? 'admin');
    $linkedUserId = (int) ($client['linked_user_id'] ?? 0);
    if ($clientType === 'reseller_self_service') {
        $settings = storeBridgeResellerApiSettings();
        if (!storeBridgeResellerApiUserAllowed($linkedUserId, $settings)) {
            return ['success' => false, 'http_code' => 403, 'code' => 'api_program_disabled', 'message' => 'Reseller API access is disabled for this account', 'client_id' => (int) $client['id']];
        }
        if (!accountVerificationIsComplete($linkedUserId)) {
            return ['success' => false, 'http_code' => 403, 'code' => 'account_verification_required', 'message' => 'Linked reseller account requires email verification', 'client_id' => (int) $client['id']];
        }
        $apiIp = function_exists('getClientIp') ? getClientIp() : trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $apiBlock = accountVerificationApiAccessBlock($linkedUserId, $apiIp);
        if (!empty($apiBlock['blocked'])) {
            return ['success' => false, 'http_code' => 403, 'code' => 'security_blocked', 'message' => 'Linked reseller account or network is blocked', 'client_id' => (int) $client['id']];
        }
    }
    if (storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance') === 'reseller_wallet') {
        $linkedUser = storeBridgeGetResellerAccount($linkedUserId, false);
        if (!$linkedUser || (string) ($linkedUser['status'] ?? '') !== 'active') {
            return ['success' => false, 'http_code' => 403, 'code' => 'account_inactive', 'message' => 'Linked reseller account is inactive', 'client_id' => (int) $client['id']];
        }
    }

    $ip = function_exists('getClientIp') ? getClientIp() : trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $allowed = trim((string) ($client['allowed_ips'] ?? ''));
    if ($allowed !== '') {
        $matched = false;
        foreach (preg_split('/[\s,;]+/', $allowed) ?: [] as $rule) {
            if (storeBridgeIpMatchesRule($ip, $rule)) { $matched = true; break; }
        }
        if (!$matched) return ['success' => false, 'http_code' => 403, 'code' => 'ip_not_allowed', 'message' => 'Server IP is not allowed', 'client_id' => (int) $client['id']];
    }

    $limit = max(10, min(5000, (int) ($client['rate_limit_per_minute'] ?? 120)));
    if (!storeBridgeCheckClientRateLimit('all', (int) $client['id'], $limit, 60)) {
        return ['success' => false, 'http_code' => 429, 'code' => 'rate_limited', 'message' => 'Rate limit exceeded', 'client_id' => (int) $client['id']];
    }
    $touch = $conn->prepare('UPDATE store_api_clients SET last_used_at = NOW() WHERE id = ?');
    if ($touch) {
        $clientId = (int) $client['id'];
        $touch->bind_param('i', $clientId);
        $touch->execute();
        $touch->close();
    }
    return ['success' => true, 'client' => $client, 'ip' => $ip];
}

/**
 * Store API quotas are per API client, not per source IP. The generic website
 * limiter deliberately includes getClientIp() in its key, which is useful for
 * login abuse but would let one leaked API key multiply its quota across many
 * IPs. Keep Store API quota accounting on one stable client-scoped DB key.
 */
function storeBridgeCheckClientRateLimit(string $scope, int $clientId, int $maxAttempts, int $windowSeconds = 60): bool
{
    global $conn;
    if ($clientId < 1 || !isset($conn) || !($conn instanceof mysqli)) return false;
    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);
    if (!function_exists('ensureRateLimitSchema') || !ensureRateLimitSchema()) return false;

    $scope = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($scope))) ?: 'request';
    $rateKey = hash('sha256', 'store-api:' . $scope . ':client:' . $clientId);
    $stmt = $conn->prepare(
        "INSERT INTO security_rate_limits (rate_key, attempts, first_attempt, last_attempt)
         VALUES (?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            attempts = IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) >= ?, 1, attempts + 1),
            first_attempt = IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) >= ?, NOW(), first_attempt),
            last_attempt = NOW()"
    );
    if (!$stmt) return false;
    $stmt->bind_param('sii', $rateKey, $windowSeconds, $windowSeconds);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return false;

    $read = $conn->prepare('SELECT attempts FROM security_rate_limits WHERE rate_key=? LIMIT 1');
    if (!$read) return false;
    $read->bind_param('s', $rateKey);
    if (!$read->execute()) { $read->close(); return false; }
    $result = $read->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $read->close();
    return $row !== null && (int) ($row['attempts'] ?? ($maxAttempts + 1)) <= $maxAttempts;
}

function storeBridgeCheckActionRateLimit(array $client, string $action): array
{
    $clientId = (int) ($client['id'] ?? 0);
    if ($clientId < 1) return ['success' => false, 'http_code' => 401, 'code' => 'invalid_api_key', 'message' => 'Invalid API client'];
    if ($action === 'order') {
        $limit = max(1, min(1000, (int) ($client['order_rate_limit_per_minute'] ?? 10)));
        if (!storeBridgeCheckClientRateLimit('order', $clientId, $limit, 60)) {
            return ['success' => false, 'http_code' => 429, 'code' => 'order_rate_limited', 'message' => 'Order rate limit exceeded'];
        }
    }
    return ['success' => true];
}

function storeBridgeDiagnosticRequestPath(): string
{
    $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri === '') return '';
    $path = @parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = '/';
    return substr($path, 0, 500);
}

function storeBridgeDiagnosticCredentialSource(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+\S+/i', $authorization) === 1) return 'authorization_bearer';
    if (trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? '')) !== '') return 'x_api_key';
    return 'none';
}

function storeBridgeDiagnosticSanitizeMessage(string $message, int $limit = 1000): string
{
    $message = trim($message);
    if ($message === '') return '';
    $patterns = [
        '/sk_live_[A-Za-z0-9_-]{16,}/i' => '[REDACTED_API_KEY]',
        '/(Bearer)\s+[^\s,;]+/i' => '$1 [REDACTED]',
        '/((?:api[_-]?key|authorization|access[_-]?token|token|password|secret)\s*[:=]\s*)["\']?[^\s,"\';]+/i' => '$1[REDACTED]',
        '/([?&](?:api[_-]?key|token|access_token|secret)=)[^&\s]+/i' => '$1[REDACTED]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $redacted = @preg_replace($pattern, $replacement, $message);
        if (is_string($redacted)) $message = $redacted;
    }
    return substr($message, 0, max(50, min(4000, $limit)));
}

/**
 * Keep request logging useful without turning it into a second secret store.
 * Only operational identifiers are retained. Customer identity, API keys,
 * delivered key material and arbitrary request fields are never copied here.
 */
function storeBridgeSafeRequestSummary(string $action, array $payload): array
{
    $summary = [];
    foreach (['external_ref', 'product_id', 'remote_product_id', 'order_id', 'reference'] as $field) {
        if (!array_key_exists($field, $payload) || !is_scalar($payload[$field])) continue;
        $value = trim((string) $payload[$field]);
        if ($value !== '') $summary[$field] = substr($value, 0, 190);
    }
    if (isset($payload['quantity']) && is_numeric($payload['quantity'])) {
        $summary['quantity'] = max(0, min(100000, (int) $payload['quantity']));
    }
    if ($action === 'order_status' && isset($payload['external_reference']) && is_scalar($payload['external_reference'])) {
        $value = trim((string) $payload['external_reference']);
        if ($value !== '') $summary['external_reference'] = substr($value, 0, 190);
    }
    return $summary;
}

function storeBridgeSafeResponseSummary(string $action, array $payload): array
{
    $summary = ['success' => !empty($payload['success'])];
    if (isset($payload['code']) && is_scalar($payload['code'])) $summary['code'] = substr(trim((string) $payload['code']), 0, 80);
    if (isset($payload['stage']) && is_scalar($payload['stage'])) $summary['stage'] = substr(trim((string) $payload['stage']), 0, 80);
    if (isset($payload['count']) && is_numeric($payload['count'])) $summary['count'] = max(0, (int) $payload['count']);

    $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    foreach (['id', 'order_id', 'external_ref', 'status', 'product_id', 'remote_product_id'] as $field) {
        if (!array_key_exists($field, $data) || !is_scalar($data[$field])) continue;
        $value = trim((string) $data[$field]);
        if ($value !== '') $summary[$field] = substr($value, 0, 190);
    }
    if (isset($data['quantity']) && is_numeric($data['quantity'])) $summary['quantity'] = max(0, (int) $data['quantity']);
    if (isset($data['stock']) && is_numeric($data['stock'])) $summary['stock'] = (int) $data['stock'];
    if (isset($data['available_stock']) && is_numeric($data['available_stock'])) $summary['available_stock'] = (int) $data['available_stock'];
    if (isset($data['keys']) && is_array($data['keys'])) $summary['delivered_key_count'] = count($data['keys']);
    if (isset($data['delivery']) && is_array($data['delivery'])) $summary['delivery_item_count'] = count($data['delivery']);
    return $summary;
}

function storeBridgeSafeOperationDiagnostic(array $diagnostic): array
{
    $safe = [];
    foreach (['failure_class', 'failure_stage', 'error_code', 'fulfillment_source'] as $field) {
        if (isset($diagnostic[$field]) && is_scalar($diagnostic[$field])) {
            $value = trim((string) $diagnostic[$field]);
            if ($value !== '') $safe[$field] = substr($value, 0, 120);
        }
    }
    foreach (['db_code','allocated_order_id','local_stock_locked'] as $field) {
        if (isset($diagnostic[$field]) && is_numeric($diagnostic[$field])) $safe[$field] = (int) $diagnostic[$field];
    }
    foreach (['retryable','transaction_started','commit_attempted','commit_succeeded','rollback_attempted','rollback_succeeded'] as $field) {
        if (array_key_exists($field, $diagnostic)) $safe[$field] = !empty($diagnostic[$field]);
    }
    if (array_key_exists('order_created', $diagnostic)) {
        $safe['order_created'] = $diagnostic['order_created'] === null ? null : !empty($diagnostic['order_created']);
    }
    if (isset($diagnostic['db_error']) && is_scalar($diagnostic['db_error'])) {
        $safe['db_error'] = storeBridgeDiagnosticSanitizeMessage((string) $diagnostic['db_error'], 1000);
    }
    if (isset($diagnostic['error_message']) && is_scalar($diagnostic['error_message'])) {
        $safe['error_message'] = storeBridgeDiagnosticSanitizeMessage((string) $diagnostic['error_message'], 1000);
    }
    if (isset($diagnostic['error_sha256']) && is_scalar($diagnostic['error_sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string) $diagnostic['error_sha256']) === 1) {
        $safe['error_sha256'] = strtolower((string) $diagnostic['error_sha256']);
    }
    if (isset($diagnostic['order_evidence']) && is_array($diagnostic['order_evidence'])) {
        // order_evidence is constructed exclusively by
        // storeBridgeBuildOrderEvidence(), which intentionally excludes
        // plaintext delivered keys, API credentials and customer PII.
        $safe['order_evidence'] = $diagnostic['order_evidence'];
    }
    return $safe;
}

function storeBridgeRequestDiagnosticStage(string $action, int $httpCode, string $resultCode): string
{
    $resultCode = strtolower(trim($resultCode));
    if (in_array($resultCode, ['missing_api_key', 'invalid_api_key', 'auth_unavailable', 'api_program_disabled', 'account_inactive', 'ip_not_allowed', 'shared_auth_failed'], true)) return 'authentication';
    if (strpos($resultCode, 'rate_limit') !== false || $httpCode === 429) return 'rate_limit';
    if (in_array($resultCode, ['method_not_allowed', 'action_required', 'invalid_json', 'request_body_too_large', 'signed_json_required', 'product_id_required', 'invalid_product_id'], true)) return 'request_validation';
    if ($resultCode === 'php_fatal' || $resultCode === 'unhandled_exception') return 'application_failure';
    if ($action === 'diagnostic') return 'diagnostic';
    if ($action === 'products') return 'catalogue';
    if ($action === 'inventory') return 'inventory';
    if ($action === 'balance') return 'balance';
    if ($action === 'order' || $action === 'order_status') return 'order';
    if (strpos($action, 'shared_') === 0) return 'shared_ledger';
    return $httpCode >= 500 ? 'application_failure' : 'response';
}

function storeBridgeLogRequest(?int $clientId, string $requestId, string $action, string $ip, string $method, int $httpCode, array $context = []): void
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return;

    $clientIdValue = $clientId !== null && $clientId > 0 ? $clientId : null;
    $requestId = substr(trim($requestId), 0, 40);
    $action = substr(strtolower(trim($action)), 0, 40);
    if ($action === '') $action = 'unknown';
    $ip = substr(trim($ip), 0, 64);
    $method = substr(strtoupper(trim($method)), 0, 10);
    $httpCode = max(0, min(65535, $httpCode));

    $baseInserted = false;
    $baseId = 0;
    try {
        $stmt = $conn->prepare('INSERT INTO store_api_request_logs (client_id, request_id, action, client_ip, http_method, http_code) VALUES (?, ?, ?, ?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('issssi', $clientIdValue, $requestId, $action, $ip, $method, $httpCode);
            $baseInserted = $stmt->execute();
            if ($baseInserted) $baseId = (int) $conn->insert_id;
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Store API request log insert failed request_id=' . $requestId . ': ' . $e->getMessage());
    }

    $remoteAddr = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $detectedIp = function_exists('getClientIp') ? substr(trim((string) getClientIp()), 0, 64) : $remoteAddr;
    if ($ip !== '') $detectedIp = $ip;
    $trusted = function_exists('isTrustedProxyAddress') && isTrustedProxyAddress($remoteAddr);
    $cfRay = substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100);
    $httpProtocol = substr(trim((string) ($_SERVER['SERVER_PROTOCOL'] ?? '')), 0, 30);
    $contentType = substr(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 0, 120);
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
        ? max(0, min(4294967295, (int) $_SERVER['CONTENT_LENGTH'])) : null;
    $userAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
    $requestPath = storeBridgeDiagnosticRequestPath();
    $durationMs = max(0, min(4294967295, (int) ($context['duration_ms'] ?? 0)));
    $resultCode = substr(trim((string) ($context['result_code'] ?? ($httpCode < 400 ? 'ok' : 'http_' . $httpCode))), 0, 80);
    if ($resultCode === '') $resultCode = $httpCode < 400 ? 'ok' : 'http_' . $httpCode;
    $stage = substr(trim((string) ($context['stage'] ?? storeBridgeRequestDiagnosticStage($action, $httpCode, $resultCode))), 0, 80);
    if ($stage === '') $stage = 'response';

    $requestPayload = isset($context['request_payload']) && is_array($context['request_payload']) ? $context['request_payload'] : [];
    $responsePayload = isset($context['response_payload']) && is_array($context['response_payload']) ? $context['response_payload'] : [];
    $credentialSource = storeBridgeDiagnosticCredentialSource();
    $queryKeys = [];
    foreach (array_keys(is_array($_GET) ? $_GET : []) as $queryKey) {
        if (!is_scalar($queryKey)) continue;
        $queryKeys[] = substr((string) $queryKey, 0, 80);
        if (count($queryKeys) >= 30) break;
    }

    $detail = [
        'diagnostic_version' => 2,
        'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'request_id' => $requestId,
        'client_id' => $clientIdValue,
        'request' => [
            'method' => $method,
            'action' => $action,
            'path' => $requestPath,
            'host' => substr(trim((string) ($_SERVER['HTTP_HOST'] ?? '')), 0, 255),
            'http_protocol' => $httpProtocol,
            'https' => function_exists('requestIsHttps') ? requestIsHttps() : null,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'user_agent' => $userAgent,
            'query_keys' => $queryKeys,
            'credential_source' => $credentialSource,
            'credential_present' => $credentialSource !== 'none',
            'summary' => storeBridgeSafeRequestSummary($action, $requestPayload),
        ],
        'network' => [
            'remote_addr' => $remoteAddr,
            'detected_client_ip' => $detectedIp,
            'remote_addr_is_trusted_proxy' => $trusted,
            'trusted_proxy_source' => function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'unknown',
            'cf_connecting_ip' => $trusted ? substr(trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')), 0, 64) : '',
            'x_forwarded_for' => $trusted ? substr(trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), 0, 500) : '',
            'x_real_ip' => $trusted ? substr(trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')), 0, 64) : '',
            'cf_ray' => $cfRay,
        ],
        'result' => [
            'http_code' => $httpCode,
            'result_code' => $resultCode,
            'stage' => $stage,
            'duration_ms' => $durationMs,
            'response_summary' => storeBridgeSafeResponseSummary($action, $responsePayload),
        ],
    ];
    if (isset($context['timeline']) && is_array($context['timeline'])) {
        $safeTimeline = storeBridgeSafeTimeline($context['timeline']);
        if ($safeTimeline !== []) $detail['timeline'] = $safeTimeline;
    }
    if (isset($context['operation_diagnostic']) && is_array($context['operation_diagnostic'])) {
        $operationDiagnostic = storeBridgeSafeOperationDiagnostic($context['operation_diagnostic']);
        if ($operationDiagnostic !== []) $detail['operation_diagnostic'] = $operationDiagnostic;
    }
    if (isset($context['fatal_error']) && is_array($context['fatal_error'])) {
        $fatal = $context['fatal_error'];
        $detail['fatal_error'] = [
            'type' => isset($fatal['type']) && is_numeric($fatal['type']) ? (int) $fatal['type'] : null,
            'message' => storeBridgeDiagnosticSanitizeMessage((string) ($fatal['message'] ?? ''), 1000),
            'message_sha256' => hash('sha256', (string) ($fatal['message'] ?? '')),
            'file' => substr(basename((string) ($fatal['file'] ?? '')), 0, 255),
            'line' => isset($fatal['line']) && is_numeric($fatal['line']) ? (int) $fatal['line'] : null,
        ];
    }
    if (isset($context['exception']) && is_array($context['exception'])) {
        $detail['exception'] = [
            'class' => substr(trim((string) ($context['exception']['class'] ?? '')), 0, 190),
            'message' => storeBridgeDiagnosticSanitizeMessage((string) ($context['exception']['message'] ?? ''), 1000),
            'message_sha256' => hash('sha256', (string) ($context['exception']['message'] ?? '')),
        ];
    }
    $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($detailJson)) $detailJson = '{"diagnostic_version":2,"encoding_error":true}';

    if (storeBridgeEnsureDiagnosticSchema()) {
        try {
            $diag = $conn->prepare("INSERT INTO store_api_request_diagnostics (request_id,client_id,result_code,stage,duration_ms,remote_addr,detected_client_ip,trusted_proxy,cf_ray,http_protocol,content_type,content_length,user_agent,request_path,detail_json) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), ?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id),result_code=VALUES(result_code),stage=VALUES(stage),duration_ms=VALUES(duration_ms),remote_addr=VALUES(remote_addr),detected_client_ip=VALUES(detected_client_ip),trusted_proxy=VALUES(trusted_proxy),cf_ray=VALUES(cf_ray),http_protocol=VALUES(http_protocol),content_type=VALUES(content_type),content_length=VALUES(content_length),user_agent=VALUES(user_agent),request_path=VALUES(request_path),detail_json=VALUES(detail_json)");
            if ($diag) {
                $trustedInt = $trusted ? 1 : 0;
                $diag->bind_param('sissississsisss', $requestId, $clientIdValue, $resultCode, $stage, $durationMs, $remoteAddr, $detectedIp, $trustedInt, $cfRay, $httpProtocol, $contentType, $contentLength, $userAgent, $requestPath, $detailJson);
                if (!$diag->execute()) error_log('Store API request diagnostic insert failed request_id=' . $requestId . ': ' . $diag->error);
                $diag->close();
            }
        } catch (Throwable $e) {
            error_log('Store API request diagnostic exception request_id=' . $requestId . ': ' . $e->getMessage());
        }

    }

    if ($baseInserted && $baseId > 0 && $baseId % 500 === 0) {
        try {
            if (storeBridgeEnsureDiagnosticSchema()) $conn->query('DELETE FROM store_api_request_diagnostics WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY) LIMIT 3000');
            $conn->query('DELETE FROM store_api_request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY) LIMIT 3000');
        } catch (Throwable $e) {
            // Diagnostics cleanup must never affect the API response.
        }
    }
}

function storeBridgeProductCategories(int $productId): array
{
    $categories = getProductCategories($productId);
    $clean = [];
    foreach ((array) $categories as $category) {
        $category = trim((string) $category);
        if ($category !== '' && strlen($category) <= 255 && !in_array($category, $clean, true)) $clean[] = $category;
        if (count($clean) >= 4) break;
    }
    return $clean;
}

function supplierBridgeNormalizeCategories($value, string $fallback = ''): array
{
    $values = [];
    if (is_array($value)) {
        $values = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $values = $decoded;
        } else {
            $values = preg_split('/\|\||[,\r\n]+/', $value) ?: [];
        }
    }
    if ($fallback !== '') $values[] = $fallback;

    $clean = [];
    foreach ($values as $category) {
        if (!is_scalar($category)) continue;
        $category = trim((string) $category);
        if ($category === '' || strlen($category) > 255 || in_array($category, $clean, true)) continue;
        $clean[] = $category;
        if (count($clean) >= 20) break;
    }
    return $clean;
}


function supplierBridgeUtf8Length(string $value): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
}

function supplierBridgeNormalizeStorefrontCategories($value, string $fallback = ''): array
{
    $values = [];
    if (is_array($value)) {
        $values = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) $values = $decoded;
        else $values = preg_split('/[\r\n,]+/u', $value) ?: [];
    }
    $clean = [];
    foreach ($values as $category) {
        if (!is_scalar($category)) continue;
        $category = trim((string) $category);
        if ($category === '' || supplierBridgeUtf8Length($category) > 255 || preg_match('/[\x00-\x1F\x7F]/', $category)) continue;
        if (!in_array($category, $clean, true)) $clean[] = $category;
        if (count($clean) >= 4) break;
    }
    if ($clean === [] && $fallback !== '') {
        $fallback = trim($fallback);
        if ($fallback !== '' && supplierBridgeUtf8Length($fallback) <= 255 && !preg_match('/[\x00-\x1F\x7F]/', $fallback)) {
            $clean[] = $fallback;
        }
    }
    return $clean;
}

function supplierBridgeGetCategoryMappings(int $connectionId = 0): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $connectionId = max(0, $connectionId);
    $sql = "SELECT scm.*,sc.name AS connection_name
            FROM supplier_category_mappings scm
            JOIN supplier_connections sc ON sc.id=scm.connection_id";
    if ($connectionId > 0) $sql .= ' WHERE scm.connection_id=' . $connectionId;
    $sql .= ' ORDER BY sc.priority ASC,scm.remote_category ASC,scm.id ASC';
    $result = $conn->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$row) {
        $row['local_categories'] = supplierBridgeNormalizeStorefrontCategories($row['local_categories_json'] ?? '');
    }
    unset($row);
    return $rows;
}

function supplierBridgeResolveLocalCategories(int $connectionId, $remoteCategories): array
{
    global $conn;
    $remote = supplierBridgeNormalizeCategories($remoteCategories);
    if ($remote === []) $remote = ['API'];
    if ($connectionId < 1 || !storeBridgeEnsureSchema()) {
        return supplierBridgeNormalizeStorefrontCategories($remote, 'API');
    }

    $stmt = $conn->prepare('SELECT local_categories_json FROM supplier_category_mappings WHERE connection_id=? AND remote_category=? AND enabled=1 LIMIT 1');
    $resolved = [];
    foreach ($remote as $remoteCategory) {
        $mapped = [];
        if ($stmt) {
            $stmt->bind_param('is', $connectionId, $remoteCategory);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) $mapped = supplierBridgeNormalizeStorefrontCategories($row['local_categories_json'] ?? '');
        }
        $source = $mapped !== [] ? $mapped : [$remoteCategory];
        foreach ($source as $category) {
            if (!in_array($category, $resolved, true)) $resolved[] = $category;
            if (count($resolved) >= 4) break 2;
        }
    }
    if ($stmt) $stmt->close();
    return supplierBridgeNormalizeStorefrontCategories($resolved, 'API');
}

function supplierBridgeSaveCategoryMapping(
    int $connectionId,
    string $remoteCategory,
    $localCategories,
    bool $enabled = true,
    bool $applyExisting = true
): array {
    global $conn;
    if (!storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Store Bridge schema is unavailable'];
    $connectionId = max(0, $connectionId);
    $remoteCategory = trim($remoteCategory);
    $localCategories = supplierBridgeNormalizeStorefrontCategories($localCategories);
    if ($connectionId < 1 || $remoteCategory === '' || supplierBridgeUtf8Length($remoteCategory) > 255 || preg_match('/[\x00-\x1F\x7F]/', $remoteCategory)) {
        return ['success' => false, 'message' => 'Invalid supplier category'];
    }
    if ($localCategories === []) return ['success' => false, 'message' => 'At least one local category is required'];
    $check = $conn->prepare('SELECT id FROM supplier_connections WHERE id=? LIMIT 1');
    if (!$check) return ['success' => false, 'message' => 'Unable to inspect supplier connection'];
    $check->bind_param('i', $connectionId);
    $check->execute();
    $checkResult = $check->get_result();
    $exists = $checkResult ? (bool) $checkResult->fetch_assoc() : false;
    $check->close();
    if (!$exists) return ['success' => false, 'message' => 'Supplier connection not found'];

    $json = json_encode($localCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) return ['success' => false, 'message' => 'Unable to encode category mapping'];
    $enabledInt = $enabled ? 1 : 0;
    $stmt = $conn->prepare('INSERT INTO supplier_category_mappings (connection_id,remote_category,local_categories_json,enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE local_categories_json=VALUES(local_categories_json),enabled=VALUES(enabled),updated_at=NOW()');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare category mapping'];
    $stmt->bind_param('issi', $connectionId, $remoteCategory, $json, $enabledInt);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    if (!$ok) return ['success' => false, 'message' => $error !== '' ? $error : 'Unable to save category mapping'];

    $apply = ['success' => true, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    if ($applyExisting) $apply = supplierBridgeApplyCategoryMappings($connectionId, $remoteCategory);
    return ['success' => !empty($apply['success']), 'mapping_saved' => true] + $apply;
}


function supplierBridgeSetGroupCategoryOverride(int $supplierProductId, $localCategories, bool $useAutomatic): array
{
    global $conn;
    $product = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$product) return ['success' => false, 'message' => 'Supplier product was not found'];
    $connectionId = (int) ($product['connection_id'] ?? 0);
    $sourceRef = trim((string) ($product['remote_source_product_id'] ?? ''));
    if ($connectionId < 1 || $sourceRef === '') return ['success' => false, 'message' => 'Supplier product group is invalid'];

    $categories = supplierBridgeNormalizeStorefrontCategories($localCategories);
    if (!$useAutomatic && $categories === []) return ['success' => false, 'message' => 'At least one local category is required'];
    $overrideJson = null;
    if (!$useAutomatic) {
        $overrideJson = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($overrideJson)) return ['success' => false, 'message' => 'Unable to encode category override'];
    }

    $stmt = $conn->prepare('UPDATE supplier_catalog_products SET local_categories_override_json=?,updated_at=NOW() WHERE connection_id=? AND remote_source_product_id=?');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare category override'];
    $stmt->bind_param('sis', $overrideJson, $connectionId, $sourceRef);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) return ['success' => false, 'message' => 'Unable to save category override'];

    $mapStmt = $conn->prepare('SELECT local_product_id FROM supplier_catalog_products WHERE connection_id=? AND remote_source_product_id=? LIMIT 1');
    if (!$mapStmt) return ['success' => true, 'updated' => 0];
    $mapStmt->bind_param('is', $connectionId, $sourceRef);
    $mapStmt->execute();
    $mapResult = $mapStmt->get_result();
    $mapRow = $mapResult ? $mapResult->fetch_assoc() : null;
    $mapStmt->close();
    $localProductId = (int) ($mapRow['local_product_id'] ?? 0);
    if ($localProductId < 1 || isProductAdminArchived($localProductId)) return ['success' => true, 'updated' => 0];

    ensureProductCategoryLinksTable();
    ensureCategoriesTable();
    if ($useAutomatic) {
        $remote = [];
        $remoteStmt = $conn->prepare('SELECT categories_json FROM supplier_products WHERE connection_id=? AND remote_source_product_id=? AND enabled=1 AND supplier_removed_at IS NULL ORDER BY id ASC');
        if (!$remoteStmt) return ['success' => false, 'message' => 'Unable to load supplier categories'];
        $remoteStmt->bind_param('is', $connectionId, $sourceRef);
        $remoteStmt->execute();
        $remoteResult = $remoteStmt->get_result();
        while ($row = $remoteResult ? $remoteResult->fetch_assoc() : null) {
            if (!$row) break;
            foreach (supplierBridgeNormalizeCategories($row['categories_json'] ?? '') as $category) {
                if (!in_array($category, $remote, true)) $remote[] = $category;
            }
        }
        $remoteStmt->close();
        $categories = supplierBridgeResolveLocalCategories($connectionId, $remote);
    }
    if ($categories === []) return ['success' => false, 'message' => 'No storefront category could be resolved'];
    if (!supplierBridgeSetProductCategoriesAtomic($localProductId, $categories)) return ['success' => false, 'message' => 'Unable to update local product categories'];
    return ['success' => true, 'updated' => 1, 'local_product_id' => $localProductId, 'categories' => $categories];
}

function supplierBridgeDeleteCategoryMapping(int $mappingId, int $connectionId = 0): bool
{
    global $conn;
    if ($mappingId < 1 || !storeBridgeEnsureSchema()) return false;
    if ($connectionId > 0) {
        $stmt = $conn->prepare('DELETE FROM supplier_category_mappings WHERE id=? AND connection_id=?');
        if (!$stmt) return false;
        $stmt->bind_param('ii', $mappingId, $connectionId);
    } else {
        $stmt = $conn->prepare('DELETE FROM supplier_category_mappings WHERE id=?');
        if (!$stmt) return false;
        $stmt->bind_param('i', $mappingId);
    }
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $ok && $affected > 0;
}

function supplierBridgeApplyCategoryMappings(int $connectionId, string $remoteCategory = ''): array
{
    global $conn;
    if ($connectionId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'Invalid supplier connection'];
    $remoteCategory = trim($remoteCategory);
    ensureProductCategoryLinksTable();
    ensureCategoriesTable();

    // Load every category attached to each affected local product. Filtering the
    // SQL to only the changed remote category can accidentally erase the other
    // categories of a multi-variant product when the mapping is reapplied.
    $sql = "SELECT scl.local_product_id,sp.categories_json,scp.local_categories_override_json
            FROM supplier_products sp
            JOIN supplier_catalog_links scl ON scl.supplier_product_id=sp.id
            JOIN supplier_catalog_products scp ON scp.connection_id=sp.connection_id
                                              AND scp.remote_source_product_id=sp.remote_source_product_id
                                              AND scp.local_product_id=scl.local_product_id
            WHERE sp.connection_id=? AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
              AND scp.sync_details=1
            ORDER BY scl.local_product_id ASC,sp.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['success' => false, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'Unable to prepare category refresh'];
    $stmt->bind_param('i', $connectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $groups = [];
    foreach ($rows as $row) {
        $localProductId = (int) ($row['local_product_id'] ?? 0);
        if ($localProductId < 1) continue;
        if (!isset($groups[$localProductId])) $groups[$localProductId] = ['remote' => [], 'override' => []];
        $override = supplierBridgeNormalizeStorefrontCategories($row['local_categories_override_json'] ?? '');
        if ($override !== []) $groups[$localProductId]['override'] = $override;
        foreach (supplierBridgeNormalizeCategories($row['categories_json'] ?? '') as $category) {
            if (!in_array($category, $groups[$localProductId]['remote'], true)) $groups[$localProductId]['remote'][] = $category;
        }
    }

    $updated = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($groups as $localProductId => $group) {
        $remoteCategories = (array) ($group['remote'] ?? []);
        if ($remoteCategory !== '' && !in_array($remoteCategory, $remoteCategories, true)) { $skipped++; continue; }
        if (isProductAdminArchived((int) $localProductId)) { $skipped++; continue; }
        // A manual per-product override intentionally outranks category routing.
        if (!empty($group['override'])) { $skipped++; continue; }
        $categories = supplierBridgeResolveLocalCategories($connectionId, $remoteCategories);
        if ($categories === []) { $skipped++; continue; }
        if (supplierBridgeSetProductCategoriesAtomic((int) $localProductId, $categories)) $updated++;
        else $failed++;
    }
    return [
        'success' => $failed === 0,
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
        'message' => $failed > 0 ? 'Some product categories could not be updated' : 'Category mappings applied',
    ];
}

function storeBridgeAbsoluteImageUrl(string $image): string
{
    $image = trim($image);
    if ($image === '') return '';
    if (preg_match('#^https://#i', $image)) return substr($image, 0, 1000);
    if (preg_match('#^(?:http:|data:|//)#i', $image)) return '';
    $base = storeBridgeCurrentBaseUrl();
    if ($base === '') return '';
    return $base . '/' . ltrim($image, '/');
}

function storeBridgeClientPrice(array $row, array $client): float
{
    $billingMode = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
    if ($billingMode === 'reseller_wallet') {
        $base = round((float) ($row['price_reseller'] ?? 0), 2);
        $userId = (int) ($client['linked_user_id'] ?? 0);
        $variantId = (int) ($row['source_variant_id'] ?? 0);
        if ($userId > 0 && $variantId > 0) $base = (float) getEffectiveResellerPrice($userId, $variantId, $base);
        return is_finite($base) ? max(0.0, min(1000000000.0, round($base, 2))) : 0.0;
    }
    if (array_key_exists('custom_price', $row) && $row['custom_price'] !== null && $row['custom_price'] !== '') {
        $custom = round((float) $row['custom_price'], 2);
        return is_finite($custom) ? max(0.0, min(1000000000.0, $custom)) : 0.0;
    }
    $tier = (string) ($client['price_tier'] ?? 'reseller');
    if ($tier === 'user') $base = (float) ($row['price_user'] ?? 0);
    elseif ($tier === 'cost') $base = (float) ($row['cost_price'] ?? 0);
    else $base = (float) ($row['price_reseller'] ?? 0);
    $multiplier = (float) ($client['price_multiplier'] ?? 1);
    $price = round($base * $multiplier, 2);
    return is_finite($price) ? max(0.0, min(1000000000.0, $price)) : 0.0;
}

function storeBridgeEnsureCgoRuntime(): bool
{
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    // Local-only Store API orders may continue when CGO-specific schema is not
    // ready. CGO fallback, however, requires nullable source_key_id so supplier
    // keys can be persisted without inventing a fake local keys.id.
    if (!storeBridgeCgoDeliverySchemaReady()) return $loaded = false;
    $file = __DIR__ . '/cheatgame.php';
    if (!function_exists('cgoEnsureTables')) {
        if (!is_file($file)) return $loaded = false;
        require_once $file;
    }
    if (!function_exists('cgoEnsureTables') || !cgoEnsureTables()) return $loaded = false;
    if (function_exists('cgoLiveOrderEnabled') && !cgoLiveOrderEnabled()) return $loaded = false;
    return $loaded = true;
}

/**
 * Read the cached CGO capacity linked to Store API variants. This deliberately
 * does not call the CGO catalogue over the network during /products or
 * /inventory. The authoritative supplier check remains action=order.
 */
function storeBridgeCgoVariantSnapshots(array $variantIds, array $unitPrices = []): array
{
    global $conn;
    $clean = [];
    foreach ($variantIds as $variantId) {
        $variantId = (int) $variantId;
        if ($variantId > 0) $clean[$variantId] = $variantId;
        if (count($clean) >= 500) break;
    }
    if ($clean === [] || !storeBridgeEnsureCgoRuntime()) return [];
    $idList = implode(',', array_values($clean));
    $sql = "SELECT l.local_product_id,l.local_variant_id,l.cgo_product_id,
                   cp.remote_product_id,cp.remote_stock,cp.remote_status,cp.cost_base,
                   cp.inventory_checked_at,cp.last_synced_at,cp.updated_at
            FROM cgo_catalog_links l
            JOIN cgo_products cp ON cp.id=l.cgo_product_id
            WHERE l.api_fallback_enabled=1 AND cp.enabled=1
              AND cp.supplier_removed_at IS NULL
              AND l.local_variant_id IN ($idList)";
    $result = $conn->query($sql);
    if (!$result) return [];
    $snapshots = [];
    while ($row = $result->fetch_assoc()) {
        $variantId = (int) ($row['local_variant_id'] ?? 0);
        if ($variantId < 1) continue;
        $cost = round((float) ($row['cost_base'] ?? 0), 2);
        $stock = max(0, (int) ($row['remote_stock'] ?? 0));
        $price = array_key_exists($variantId, $unitPrices) ? round((float) $unitPrices[$variantId], 2) : null;
        $priceAllowed = $price === null || ($price > 0 && $price + 0.00001 >= $cost);
        $snapshots[$variantId] = [
            'eligible' => $priceAllowed && $stock > 0,
            'cgo_product_id' => (int) ($row['cgo_product_id'] ?? 0),
            'local_product_id' => (int) ($row['local_product_id'] ?? 0),
            'local_variant_id' => $variantId,
            'remote_product_id' => substr(trim((string) ($row['remote_product_id'] ?? '')), 0, 120),
            'stock' => $priceAllowed ? $stock : 0,
            'raw_stock' => $stock,
            'cost_base' => $cost,
            'remote_status' => substr(trim((string) ($row['remote_status'] ?? '')), 0, 60),
            'inventory_checked_at' => (string) ($row['inventory_checked_at'] ?? ''),
            'last_synced_at' => (string) ($row['last_synced_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'price_allowed' => $priceAllowed,
        ];
    }
    return $snapshots;
}

function storeBridgeCgoCandidate(array $product, int $quantity): ?array
{
    $variantId = (int) ($product['source_variant_id'] ?? 0);
    $unitPrice = round((float) ($product['unit_price'] ?? 0), 2);
    if ($variantId < 1 || $quantity < 1 || $unitPrice <= 0) return null;
    $snapshots = storeBridgeCgoVariantSnapshots([$variantId], [$variantId => $unitPrice]);
    $candidate = $snapshots[$variantId] ?? null;
    if (!is_array($candidate) || empty($candidate['eligible']) || (int) ($candidate['stock'] ?? 0) < $quantity) return null;
    if ((int) ($candidate['local_product_id'] ?? 0) !== (int) ($product['source_product_id'] ?? 0)) return null;
    return $candidate;
}

/**
 * Cached Store Bridge supplier capacity visible to one Store API client.
 * LOCAL is always available; CGO and supplier connections are controlled by
 * source_access_json. The Store API parent remains the single debit/refund
 * authority for both API credit and linked-wallet billing, while Supplier Bridge
 * runs procurement-only for the child order.
 */
function storeBridgeSupplierVariantSnapshots(array $client, array $variantIds, array $unitPrices = []): array
{
    global $conn;
    $allowed = storeBridgeClientAllowedSupplierConnectionIds($client);
    if ($allowed === [] || !storeBridgeEnsureSchema()) return [];

    $variants = [];
    foreach ($variantIds as $variantId) {
        $variantId = (int) $variantId;
        if ($variantId > 0) $variants[$variantId] = $variantId;
        if (count($variants) >= 500) break;
    }
    if ($variants === []) return [];

    $variantList = implode(',', array_values($variants));
    $connectionList = implode(',', array_values(array_unique(array_map('intval', $allowed))));
    if ($connectionList === '') return [];

    $sql = "SELECT scl.local_product_id,scl.local_variant_id,scl.source_priority,scl.max_supplier_cost,
                   sp.id AS supplier_product_id,sp.connection_id,sp.remote_stock,sp.cost_base,
                   sp.inventory_checked_at,sp.updated_at,
                   sc.provider_type,sc.priority,sc.protect_below_cost,sc.purchase_mode,
                   CASE WHEN sp.inventory_last_error_at IS NOT NULL
                              AND (sp.inventory_last_success_at IS NULL OR sp.inventory_last_error_at > sp.inventory_last_success_at)
                              AND sp.inventory_last_error_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        THEN 1 ELSE 0 END AS routing_degraded
            FROM supplier_catalog_links scl
            JOIN supplier_products sp ON sp.id=scl.supplier_product_id
                 AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
            JOIN supplier_connections sc ON sc.id=sp.connection_id
                 AND sc.status='active' AND sc.purchase_mode='live'
            WHERE scl.api_fallback_enabled=1
              AND scl.local_variant_id IN ($variantList)
              AND sc.id IN ($connectionList)
            ORDER BY scl.local_variant_id ASC,routing_degraded ASC,
                     scl.source_priority ASC,sc.priority ASC,sc.id ASC,sp.id ASC";
    $result = $conn->query($sql);
    if (!$result) return [];

    $snapshots = [];
    while ($row = $result->fetch_assoc()) {
        $variantId = (int) ($row['local_variant_id'] ?? 0);
        if ($variantId < 1) continue;
        $cost = round(max(0.0, (float) ($row['cost_base'] ?? 0)), 2);
        $stock = max(0, (int) ($row['remote_stock'] ?? 0));
        $price = array_key_exists($variantId, $unitPrices) ? round((float) $unitPrices[$variantId], 2) : null;
        $providerType = strtolower(trim((string) ($row['provider_type'] ?? '')));
        $maxCost = isset($row['max_supplier_cost']) && is_numeric($row['max_supplier_cost'])
            ? round((float) $row['max_supplier_cost'], 2) : null;

        $costGuard = true;
        if (supplierBridgeProviderRequiresProtectedPurchase($providerType) && ($maxCost === null || $maxCost <= 0)) $costGuard = false;
        if ($maxCost !== null && $maxCost > 0 && $cost > $maxCost + 0.00001) $costGuard = false;
        $priceAllowed = $price === null || (int) ($row['protect_below_cost'] ?? 1) !== 1
            || ($price > 0 && $price + 0.00001 >= $cost);
        $healthy = (int) ($row['routing_degraded'] ?? 0) === 0;
        $eligibleStock = ($costGuard && $priceAllowed && $healthy) ? $stock : 0;

        if (!isset($snapshots[$variantId])) {
            $snapshots[$variantId] = [
                'eligible' => false,
                'stock' => 0,
                'supplier_product_id' => 0,
                'connection_id' => 0,
                'cost_base' => 0.0,
                'inventory_checked_at' => '',
                'updated_at' => '',
            ];
        }
        if ($eligibleStock > (int) $snapshots[$variantId]['stock']) {
            $snapshots[$variantId] = [
                'eligible' => $eligibleStock > 0,
                'stock' => $eligibleStock,
                'supplier_product_id' => (int) ($row['supplier_product_id'] ?? 0),
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'cost_base' => $cost,
                'inventory_checked_at' => (string) ($row['inventory_checked_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
    }
    return $snapshots;
}

function storeBridgeSupplierCandidatesForClient(array $client, array $product, int $quantity): array
{
    $billingMode = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
    $userId = $billingMode === 'reseller_wallet' ? (int) ($client['linked_user_id'] ?? 0) : 0;
    $productId = (int) ($product['source_product_id'] ?? 0);
    $variantId = (int) ($product['source_variant_id'] ?? 0);
    if (($billingMode === 'reseller_wallet' && $userId < 1) || $productId < 1 || $variantId < 1 || $quantity < 1) return [];

    $allowed = array_fill_keys(storeBridgeClientAllowedSupplierConnectionIds($client), true);
    if ($allowed === []) return [];
    $storeApiUnitPrice = round((float) ($product['unit_price'] ?? 0), 2);
    if ($storeApiUnitPrice <= 0) return [];

    $out = [];
    foreach (supplierBridgeGetPurchaseCandidates($productId, $variantId, $quantity, $userId, $storeApiUnitPrice) as $row) {
        $connectionId = (int) ($row['connection_id'] ?? 0);
        if ($connectionId < 1 || !isset($allowed[$connectionId])) continue;
        if (strtolower(trim((string) ($row['purchase_mode'] ?? ''))) !== 'live') continue;
        if ((int) ($row['routing_degraded'] ?? 0) === 1) continue;
        if ((int) ($row['remote_stock'] ?? 0) < $quantity) continue;

        $maxCost = isset($row['max_supplier_cost']) && is_numeric($row['max_supplier_cost'])
            ? round((float) $row['max_supplier_cost'], 2) : null;
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($row['provider_type'] ?? ''))
            && ($maxCost === null || $maxCost <= 0)) continue;
        if ($maxCost !== null && $maxCost > 0 && (float) ($row['cost_base'] ?? 0) > $maxCost + 0.00001) continue;
        if ((int) ($row['protect_below_cost'] ?? 1) === 1
            && $storeApiUnitPrice + 0.00001 < (float) ($row['cost_base'] ?? 0)) continue;
        $out[] = $row;
    }
    return $out;
}

function storeBridgeCgoOrderSnapshot(int $orderId, bool $includeAttempts = false): ?array
{
    global $conn;
    if ($orderId < 1 || !storeBridgeEnsureCgoRuntime()) return null;
    $stmt = $conn->prepare("SELECT id,source_kind,source_order_id,cgo_product_id,remote_product_id,quantity,
                                   unit_cost_base,total_cost_base,unit_price_base,total_price_base,status,
                                   supplier_order_id,external_ref,error_message,created_at,updated_at,completed_at
                            FROM cgo_orders WHERE id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $keys = [];
    $keyStmt = $conn->prepare('SELECT id,key_code,key_hash,created_at FROM cgo_order_keys WHERE order_id=? ORDER BY id ASC');
    if ($keyStmt) {
        $keyStmt->bind_param('i', $orderId);
        if ($keyStmt->execute()) {
            $keyResult = $keyStmt->get_result();
            while ($key = $keyResult ? $keyResult->fetch_assoc() : null) {
                if (!$key) break;
                $keys[] = $key;
                if (count($keys) >= 100) break;
            }
        }
        $keyStmt->close();
    }
    $row['keys'] = $keys;
    if ($includeAttempts && storeBridgeTableExists('cgo_order_api_attempts')) {
        $attempts = [];
        $a = $conn->prepare("SELECT id,phase,lookup_mode,external_ref,supplier_order_id,http_code,transport_error,curl_errno,error_message,
                                    provider_error_code,provider_error_class,provider_status,echoed_external_ref,discovered_supplier_order_id,
                                    delivered_key_count,primary_ip,local_ip,cf_ray,request_id,request_started_at_ms,request_finished_at_ms,
                                    namelookup_time_ms,connect_time_ms,appconnect_time_ms,pretransfer_time_ms,starttransfer_time_ms,total_time_ms,
                                    response_sha256,decision,created_at
                             FROM cgo_order_api_attempts WHERE order_id=? ORDER BY id DESC LIMIT 12");
        if ($a) {
            $a->bind_param('i', $orderId);
            if ($a->execute()) {
                $ar = $a->get_result();
                while ($attempt = $ar ? $ar->fetch_assoc() : null) {
                    if (!$attempt) break;
                    $attempts[] = $attempt;
                }
            }
            $a->close();
        }
        $row['api_attempts'] = $attempts;
    }
    return $row;
}

function storeBridgeUpdateCgoOrderLink(int $storeOrderId, int $cgoOrderId): void
{
    global $conn;
    if ($storeOrderId < 1 || $cgoOrderId < 1) return;
    $snapshot = storeBridgeCgoOrderSnapshot($cgoOrderId, false);
    if (!$snapshot || (string) ($snapshot['source_kind'] ?? '') !== 'store_api' || (int) ($snapshot['source_order_id'] ?? 0) !== $storeOrderId) return;
    $reference = substr(trim((string) ($snapshot['supplier_order_id'] ?? '')), 0, 190);
    if ($reference === '') $reference = substr(trim((string) ($snapshot['external_ref'] ?? '')), 0, 190);
    $status = substr(strtolower(trim((string) ($snapshot['status'] ?? ''))), 0, 40);
    $cost = round((float) ($snapshot['total_cost_base'] ?? 0), 2);
    $stmt = $conn->prepare('UPDATE store_api_orders SET upstream_order_id=?,upstream_reference=NULLIF(?,\'\'),upstream_status=NULLIF(?,\'\'),procurement_cost=? WHERE id=?');
    if (!$stmt) return;
    $stmt->bind_param('issdi', $cgoOrderId, $reference, $status, $cost, $storeOrderId);
    $stmt->execute();
    $stmt->close();
}

function storeBridgeRefundProviderOrder(int $orderId, string $reason, string $upstreamStatus = ''): array
{
    global $conn;
    if ($orderId < 1) return ['success' => false, 'message' => 'Invalid Store API order'];
    $reason = substr(trim($reason), 0, 1000);
    if ($reason === '') $reason = 'Upstream fulfillment failed';
    $upstreamStatus = substr(strtolower(trim($upstreamStatus)), 0, 40);
    // Ensure the append-only reseller wallet ledger exists before opening the financial transaction.
    // Running schema DDL inside this transaction could implicitly commit on MySQL.
    if (function_exists('ensureWalletLedgerSchema')) {
        ensureWalletLedgerSchema();
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM store_api_orders WHERE id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to lock Store API order for refund');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$order) throw new RuntimeException('Store API order not found');
        if ((string) ($order['status'] ?? '') === 'success') {
            $conn->rollback();
            return ['success' => false, 'message' => 'Completed Store API order cannot be refunded automatically'];
        }
        if (!empty($order['refunded_at'])) {
            $conn->rollback();
            return ['success' => true, 'already_refunded' => true, 'data' => storeBridgeProviderOrderPayload($orderId)];
        }
        $clientId = (int) ($order['client_id'] ?? 0);
        $total = round((float) ($order['total_price'] ?? 0), 2);
        $billingMode = storeBridgeNormalizeBillingMode($order['billing_mode'] ?? 'api_balance');
        $refundBalanceAfter = null;
        if ($billingMode === 'reseller_wallet') {
            $userId = (int) ($order['billing_user_id'] ?? 0);
            $u = $conn->prepare('SELECT balance FROM users WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$u) throw new RuntimeException('Unable to lock reseller wallet for refund');
            $u->bind_param('i', $userId);
            $u->execute();
            $ur = $u->get_result();
            $user = $ur ? $ur->fetch_assoc() : null;
            $u->close();
            if (!$user) throw new RuntimeException('Reseller wallet not found for refund');
            $before = round((float) ($user['balance'] ?? 0), 2);
            $refundBalanceAfter = round($before + $total, 2);
            $credit = $conn->prepare('UPDATE users SET balance=balance+? WHERE id=?');
            if (!$credit) throw new RuntimeException('Unable to prepare reseller refund');
            $credit->bind_param('di', $total, $userId);
            if (!$credit->execute() || $credit->affected_rows !== 1) { $credit->close(); throw new RuntimeException('Unable to refund reseller wallet'); }
            $credit->close();
            $txId = (int) ($order['billing_transaction_id'] ?? 0);
            if ($txId > 0 && !updateTransactionStatus($txId, 'failed')) throw new RuntimeException('Unable to mark reseller API transaction failed');
            if (!walletLedgerRecordMovement(
                $userId, $total, $before, $refundBalanceAfter,
                'store_api_refund', 'store_api_refund:' . $orderId,
                $orderId, $txId > 0 ? $txId : null, null,
                'คืนเงินคำสั่งซื้อ Store API #' . $orderId,
                'Automatic Store API refund after upstream fulfillment failure.',
                (string) ($order['external_ref'] ?? ''), true
            )) throw new RuntimeException('Unable to write reseller refund audit');
        } else {
            $c = $conn->prepare('SELECT balance FROM store_api_clients WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$c) throw new RuntimeException('Unable to lock API balance for refund');
            $c->bind_param('i', $clientId);
            $c->execute();
            $cr = $c->get_result();
            $client = $cr ? $cr->fetch_assoc() : null;
            $c->close();
            if (!$client) throw new RuntimeException('API client not found for refund');
            $before = round((float) ($client['balance'] ?? 0), 2);
            $refundBalanceAfter = round($before + $total, 2);
            $credit = $conn->prepare('UPDATE store_api_clients SET balance=balance+? WHERE id=?');
            if (!$credit) throw new RuntimeException('Unable to prepare API balance refund');
            $credit->bind_param('di', $total, $clientId);
            if (!$credit->execute() || $credit->affected_rows !== 1) { $credit->close(); throw new RuntimeException('Unable to refund API balance'); }
            $credit->close();
            $note = 'Automatic refund for order ' . (string) ($order['external_ref'] ?? '');
            $ledger = $conn->prepare("INSERT INTO store_api_balance_ledger (client_id,order_id,entry_type,amount,balance_after,note) VALUES (?,?,'order_refund',?,?,?)");
            if (!$ledger) throw new RuntimeException('Unable to prepare API refund ledger');
            $ledger->bind_param('iidds', $clientId, $orderId, $total, $refundBalanceAfter, $note);
            if (!$ledger->execute()) { $ledger->close(); throw new RuntimeException('Unable to save API refund ledger'); }
            $ledger->close();
        }
        $update = $conn->prepare("UPDATE store_api_orders SET status='failed',upstream_status=NULLIF(?,''),refunded_amount=?,refunded_at=NOW(),error_message=?,completed_at=NULL WHERE id=?");
        if (!$update) throw new RuntimeException('Unable to mark Store API order refunded');
        $update->bind_param('sdsi', $upstreamStatus, $total, $reason, $orderId);
        if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to save Store API refund state'); }
        $update->close();
        if (!$conn->commit()) throw new RuntimeException('Unable to commit Store API refund');
        commerceCenterSyncSafe('store_api_sale', $orderId);
        return ['success' => true, 'refunded' => true, 'balance_after_refund' => $refundBalanceAfter, 'data' => storeBridgeProviderOrderPayload($orderId)];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Store API refund failed order_id=' . $orderId . ': ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
        return ['success' => false, 'message' => 'Automatic refund could not be completed safely'];
    }
}

function storeBridgeApplyCgoOrderState(int $storeOrderId, int $cgoOrderId): array
{
    global $conn;
    $snapshot = storeBridgeCgoOrderSnapshot($cgoOrderId, false);
    if (!$snapshot || (string) ($snapshot['source_kind'] ?? '') !== 'store_api' || (int) ($snapshot['source_order_id'] ?? 0) !== $storeOrderId) {
        return ['success' => false, 'pending' => true, 'code' => 'upstream_link_unavailable', 'message' => 'Upstream order linkage is not available yet'];
    }
    storeBridgeUpdateCgoOrderLink($storeOrderId, $cgoOrderId);
    $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
    $quantity = max(1, (int) ($snapshot['quantity'] ?? 1));
    if (in_array($status, ['success', 'completed'], true)) {
        $keys = (array) ($snapshot['keys'] ?? []);
        if (count($keys) !== $quantity) {
            $message = 'CGO reports completion but the stored key count does not match the Store API order quantity';
            $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status=?,error_message=? WHERE id=? AND status<>'success'");
            if ($u) { $u->bind_param('ssi', $status, $message, $storeOrderId); $u->execute(); $u->close(); }
            return ['success' => false, 'pending' => true, 'code' => 'upstream_key_integrity_pending', 'message' => 'Order is being reconciled; do not submit a duplicate order'];
        }
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT status,billing_transaction_id,quantity,refunded_at FROM store_api_orders WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$lock) throw new RuntimeException('Unable to lock Store API order for CGO completion');
            $lock->bind_param('i', $storeOrderId);
            $lock->execute();
            $lr = $lock->get_result();
            $store = $lr ? $lr->fetch_assoc() : null;
            $lock->close();
            if (!$store) throw new RuntimeException('Store API order not found');
            if (!empty($store['refunded_at'])) throw new RuntimeException('CGO delivered after Store API refund; manual review required');
            $save = $conn->prepare("INSERT INTO store_api_order_keys (order_id,source_type,source_key_id,source_order_id,key_code,key_hash)
                                    VALUES (?,'cgo',NULL,?,?,?)
                                    ON DUPLICATE KEY UPDATE key_code=VALUES(key_code),source_type='cgo',source_order_id=VALUES(source_order_id)");
            if (!$save) throw new RuntimeException('Unable to prepare CGO key delivery');
            foreach ($keys as $key) {
                $keyCode = trim((string) ($key['key_code'] ?? ''));
                if ($keyCode === '') throw new RuntimeException('CGO delivered an empty key');
                $hash = hash('sha256', $keyCode);
                $save->bind_param('iiss', $storeOrderId, $cgoOrderId, $keyCode, $hash);
                if (!$save->execute()) throw new RuntimeException('Unable to store CGO key delivery');
            }
            $save->close();
            $txId = (int) ($store['billing_transaction_id'] ?? 0);
            if ($txId > 0 && !updateTransactionStatus($txId, 'completed')) throw new RuntimeException('Unable to complete reseller API transaction');
            $reference = substr(trim((string) ($snapshot['supplier_order_id'] ?? '')), 0, 190);
            if ($reference === '') $reference = substr(trim((string) ($snapshot['external_ref'] ?? '')), 0, 190);
            $cost = round((float) ($snapshot['total_cost_base'] ?? 0), 2);
            $complete = $conn->prepare("UPDATE store_api_orders SET status='success',upstream_order_id=?,upstream_reference=NULLIF(?,''),upstream_status=?,procurement_cost=?,error_message=NULL,completed_at=COALESCE(completed_at,NOW()) WHERE id=?");
            if (!$complete) throw new RuntimeException('Unable to complete CGO-backed Store API order');
            $complete->bind_param('issdi', $cgoOrderId, $reference, $status, $cost, $storeOrderId);
            if (!$complete->execute()) { $complete->close(); throw new RuntimeException('Unable to save CGO-backed Store API completion'); }
            $complete->close();
            if (!$conn->commit()) throw new RuntimeException('Unable to commit CGO-backed Store API completion');
            commerceCenterSyncSafe('store_api_sale', $storeOrderId);
            return ['success' => true, 'pending' => false, 'data' => storeBridgeProviderOrderPayload($storeOrderId)];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $message = storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000);
            $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='manual_review',error_message=? WHERE id=? AND status<>'success'");
            if ($u) { $u->bind_param('si', $message, $storeOrderId); $u->execute(); $u->close(); }
            error_log('Store API CGO completion pending order_id=' . $storeOrderId . ': ' . $message);
            return ['success' => false, 'pending' => true, 'code' => 'local_completion_pending', 'message' => 'Supplier delivery exists but local completion is still being reconciled; do not submit a duplicate order'];
        }
    }
    if (in_array($status, ['refunded', 'failed', 'cancelled', 'canceled'], true)) {
        $refund = storeBridgeRefundProviderOrder($storeOrderId, (string) ($snapshot['error_message'] ?? 'CGO fulfillment failed'), $status);
        return [
            'success' => false,
            'pending' => empty($refund['success']),
            'refunded' => !empty($refund['success']),
            'code' => !empty($refund['success']) ? 'upstream_failed_refunded' : 'refund_pending',
            'message' => !empty($refund['success']) ? 'Upstream fulfillment failed and the Store API balance was refunded' : 'Upstream fulfillment failed; automatic refund requires attention',
            'data' => $refund['data'] ?? storeBridgeProviderOrderPayload($storeOrderId),
        ];
    }
    $message = trim((string) ($snapshot['error_message'] ?? ''));
    if ($message === '') $message = 'CGO order is still processing';
    $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status=?,error_message=? WHERE id=? AND status<>'success'");
    if ($u) { $u->bind_param('ssi', $status, $message, $storeOrderId); $u->execute(); $u->close(); }
    return ['success' => true, 'pending' => true, 'code' => 'processing', 'message' => 'Order is processing; check order_status with the same order reference', 'data' => storeBridgeProviderOrderPayload($storeOrderId)];
}

function storeBridgeReconcileCgoProviderOrder(int $storeOrderId, bool $force = false): array
{
    global $conn;
    if ($storeOrderId < 1 || !storeBridgeEnsureCgoRuntime()) return ['success' => false, 'pending' => true, 'code' => 'cgo_unavailable', 'message' => 'CGO reconciliation is unavailable'];
    $stmt = $conn->prepare("SELECT id,upstream_order_id,upstream_status,error_message,status,updated_at FROM store_api_orders WHERE id=? AND fulfillment_source='cgo' LIMIT 1");
    if (!$stmt) return ['success' => false, 'pending' => true, 'message' => 'Unable to load Store API order'];
    $stmt->bind_param('i', $storeOrderId);
    $stmt->execute();
    $r = $stmt->get_result();
    $store = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    if (!$store) return ['success' => false, 'pending' => true, 'message' => 'Store API CGO order not found'];
    if ((string) ($store['status'] ?? '') === 'success') return ['success' => true, 'pending' => false, 'data' => storeBridgeProviderOrderPayload($storeOrderId)];
    $cgoOrderId = (int) ($store['upstream_order_id'] ?? 0);
    if ($cgoOrderId < 1) {
        $find = $conn->prepare("SELECT id FROM cgo_orders WHERE source_kind='store_api' AND source_order_id=? ORDER BY id DESC LIMIT 1");
        if ($find) {
            $find->bind_param('i', $storeOrderId);
            $find->execute();
            $fr = $find->get_result();
            $f = $fr ? $fr->fetch_assoc() : null;
            $find->close();
            $cgoOrderId = (int) ($f['id'] ?? 0);
            if ($cgoOrderId > 0) storeBridgeUpdateCgoOrderLink($storeOrderId, $cgoOrderId);
        }
    }
    if ($cgoOrderId < 1) {
        // Procurement never became durable. If the first automatic refund failed,
        // order_status safely retries that refund instead of leaving money reserved forever.
        if (strtolower(trim((string) ($store['upstream_status'] ?? ''))) === 'refund_pending') {
            $refundReason = trim((string) ($store['error_message'] ?? ''));
            if ($refundReason === '') $refundReason = 'CGO procurement did not start';
            $refund = storeBridgeRefundProviderOrder($storeOrderId, $refundReason, 'refund_pending');
            if (!empty($refund['success'])) {
                return [
                    'success' => false,
                    'pending' => false,
                    'refunded' => true,
                    'code' => 'upstream_unavailable_refunded',
                    'message' => 'Upstream order was not created and the Store API balance was refunded',
                    'data' => $refund['data'] ?? storeBridgeProviderOrderPayload($storeOrderId),
                ];
            }
            return ['success' => false, 'pending' => true, 'code' => 'refund_pending', 'message' => 'Upstream order was not created; automatic refund is still pending'];
        }
        return ['success' => false, 'pending' => true, 'code' => 'upstream_order_not_created', 'message' => 'Upstream order has not been created yet'];
    }
    $snapshot = storeBridgeCgoOrderSnapshot($cgoOrderId, false);
    if (!$snapshot) return ['success' => false, 'pending' => true, 'message' => 'Unable to inspect upstream order'];
    $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
    $pendingStatuses = ['submitting','unknown','pending','processing','manual_review'];
    $updatedTs = strtotime((string) ($snapshot['updated_at'] ?? ''));
    $since = $updatedTs === false ? PHP_INT_MAX : max(0, time() - $updatedTs);
    $minInterval = function_exists('cgoOrderReconcileMinIntervalSeconds') ? max(1, (int) cgoOrderReconcileMinIntervalSeconds()) : 3;
    if (in_array($status, $pendingStatuses, true) && ($force || $since >= $minInterval) && function_exists('cgoReconcileOrder')) {
        cgoReconcileOrder($cgoOrderId);
    }
    return storeBridgeApplyCgoOrderState($storeOrderId, $cgoOrderId);
}


function storeBridgeSupplierOrderSnapshot(int $orderId): ?array
{
    global $conn;
    if ($orderId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare("SELECT so.id,so.source_kind,so.source_order_id,so.connection_id,so.supplier_product_id,
                                   so.local_product_id,so.local_variant_id,so.quantity,so.unit_cost_base,so.total_cost_base,
                                   so.unit_price_base,so.total_price_base,so.status,so.supplier_order_id,so.external_ref,
                                   so.error_message,so.created_at,so.updated_at,so.completed_at,sc.provider_type
                            FROM supplier_orders so
                            JOIN supplier_connections sc ON sc.id=so.connection_id
                            WHERE so.id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;

    $keys = [];
    $keyStmt = $conn->prepare('SELECT id,key_code,key_hash,created_at FROM supplier_order_keys WHERE order_id=? ORDER BY id ASC');
    if ($keyStmt) {
        $keyStmt->bind_param('i', $orderId);
        if ($keyStmt->execute()) {
            $kr = $keyStmt->get_result();
            while ($key = $kr ? $kr->fetch_assoc() : null) {
                if (!$key) break;
                $keys[] = $key;
            }
        }
        $keyStmt->close();
    }
    $row['keys'] = $keys;
    return $row;
}

function storeBridgeUpdateSupplierOrderLink(int $storeOrderId, int $supplierOrderId): void
{
    global $conn;
    if ($storeOrderId < 1 || $supplierOrderId < 1) return;
    $snapshot = storeBridgeSupplierOrderSnapshot($supplierOrderId);
    if (!$snapshot || (string) ($snapshot['source_kind'] ?? '') !== 'store_api'
        || (int) ($snapshot['source_order_id'] ?? 0) !== $storeOrderId) return;
    $reference = substr(trim((string) ($snapshot['supplier_order_id'] ?? '')), 0, 190);
    if ($reference === '') $reference = substr(trim((string) ($snapshot['external_ref'] ?? '')), 0, 190);
    $status = substr(strtolower(trim((string) ($snapshot['status'] ?? 'processing'))), 0, 40);
    $stmt = $conn->prepare("UPDATE store_api_orders
                            SET upstream_order_id=?,upstream_reference=NULLIF(?,''),upstream_status=?,updated_at=NOW()
                            WHERE id=? AND fulfillment_source='supplier'");
    if ($stmt) {
        $stmt->bind_param('issi', $supplierOrderId, $reference, $status, $storeOrderId);
        $stmt->execute();
        $stmt->close();
    }
}

function storeBridgeApplySupplierOrderState(int $storeOrderId, int $supplierOrderId): array
{
    global $conn;
    $snapshot = storeBridgeSupplierOrderSnapshot($supplierOrderId);
    if (!$snapshot || (string) ($snapshot['source_kind'] ?? '') !== 'store_api'
        || (int) ($snapshot['source_order_id'] ?? 0) !== $storeOrderId) {
        return ['success'=>false,'pending'=>true,'code'=>'upstream_link_unavailable','message'=>'Supplier order linkage is not available yet'];
    }
    storeBridgeUpdateSupplierOrderLink($storeOrderId, $supplierOrderId);
    $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
    $quantity = max(1, (int) ($snapshot['quantity'] ?? 1));

    if (in_array($status, ['success','completed'], true)) {
        $keys = (array) ($snapshot['keys'] ?? []);
        if (count($keys) !== $quantity) {
            $message = 'Supplier reports completion but the stored key count does not match the Store API order quantity';
            $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='manual_review',error_message=? WHERE id=? AND status<>'success'");
            if ($u) { $u->bind_param('si', $message, $storeOrderId); $u->execute(); $u->close(); }
            return ['success'=>false,'pending'=>true,'code'=>'upstream_key_integrity_pending','message'=>'Supplier delivery requires review; do not submit a duplicate order'];
        }

        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT status,billing_transaction_id,quantity,refunded_at FROM store_api_orders
                                    WHERE id=? AND fulfillment_source='supplier' LIMIT 1 FOR UPDATE");
            if (!$lock) throw new RuntimeException('Unable to lock Store API order for supplier completion');
            $lock->bind_param('i', $storeOrderId);
            $lock->execute();
            $lr = $lock->get_result();
            $store = $lr ? $lr->fetch_assoc() : null;
            $lock->close();
            if (!$store) throw new RuntimeException('Store API order not found');
            if (!empty($store['refunded_at'])) throw new RuntimeException('Supplier delivered after Store API refund; manual review required');

            $save = $conn->prepare("INSERT INTO store_api_order_keys
                    (order_id,source_type,source_key_id,source_order_id,key_code,key_hash)
                    VALUES (?,'supplier',NULL,?,?,?)
                    ON DUPLICATE KEY UPDATE key_code=VALUES(key_code),source_type='supplier',source_order_id=VALUES(source_order_id)");
            if (!$save) throw new RuntimeException('Unable to prepare supplier key delivery');
            foreach ($keys as $key) {
                $keyCode = trim((string) ($key['key_code'] ?? ''));
                if ($keyCode === '') throw new RuntimeException('Supplier delivered an empty key');
                $hash = hash('sha256', $keyCode);
                $save->bind_param('iiss', $storeOrderId, $supplierOrderId, $keyCode, $hash);
                if (!$save->execute()) throw new RuntimeException('Unable to store supplier key delivery');
            }
            $save->close();

            $txId = (int) ($store['billing_transaction_id'] ?? 0);
            if ($txId > 0 && !updateTransactionStatus($txId, 'completed')) throw new RuntimeException('Unable to complete reseller Store API transaction');
            $reference = substr(trim((string) ($snapshot['supplier_order_id'] ?? '')), 0, 190);
            if ($reference === '') $reference = substr(trim((string) ($snapshot['external_ref'] ?? '')), 0, 190);
            $cost = round((float) ($snapshot['total_cost_base'] ?? 0), 2);
            $complete = $conn->prepare("UPDATE store_api_orders
                SET status='success',upstream_order_id=?,upstream_reference=NULLIF(?,''),upstream_status=?,procurement_cost=?,
                    error_message=NULL,completed_at=COALESCE(completed_at,NOW())
                WHERE id=?");
            if (!$complete) throw new RuntimeException('Unable to complete supplier-backed Store API order');
            $complete->bind_param('issdi', $supplierOrderId, $reference, $status, $cost, $storeOrderId);
            if (!$complete->execute()) { $complete->close(); throw new RuntimeException('Unable to save supplier-backed Store API completion'); }
            $complete->close();

            if (!$conn->commit()) throw new RuntimeException('Unable to commit supplier-backed Store API completion');
            commerceCenterSyncSafe('store_api_sale', $storeOrderId);
            return ['success'=>true,'pending'=>false,'data'=>storeBridgeProviderOrderPayload($storeOrderId)];
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $message = storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000);
            $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='manual_review',error_message=? WHERE id=? AND status<>'success'");
            if ($u) { $u->bind_param('si', $message, $storeOrderId); $u->execute(); $u->close(); }
            error_log('Store API supplier completion pending order_id=' . $storeOrderId . ': ' . $message);
            return ['success'=>false,'pending'=>true,'code'=>'local_completion_pending','message'=>'Supplier delivery exists but local completion is still being reconciled; do not submit a duplicate order'];
        }
    }

    if ($status === 'refunded' || in_array($status, ['failed','cancelled','canceled'], true)) {
        $refund = storeBridgeRefundProviderOrder($storeOrderId, (string) ($snapshot['error_message'] ?? 'Supplier fulfillment failed'), $status);
        return [
            'success'=>false,
            'pending'=>empty($refund['success']),
            'refunded'=>!empty($refund['success']),
            'code'=>!empty($refund['success']) ? 'upstream_failed_refunded' : 'refund_pending',
            'message'=>!empty($refund['success'])
                ? 'Supplier fulfillment failed and the Store API balance was refunded'
                : 'Supplier fulfillment failed; automatic refund requires attention',
            'data'=>$refund['data'] ?? storeBridgeProviderOrderPayload($storeOrderId),
        ];
    }

    $message = trim((string) ($snapshot['error_message'] ?? ''));
    if ($message === '') $message = $status === 'manual_review'
        ? 'Supplier order requires manual review'
        : 'Supplier order is still processing';
    $upstreamStatus = $status !== '' ? $status : 'processing';
    $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status=?,error_message=? WHERE id=? AND status<>'success'");
    if ($u) { $u->bind_param('ssi', $upstreamStatus, $message, $storeOrderId); $u->execute(); $u->close(); }
    return [
        'success'=>true,'pending'=>true,
        'code'=>$upstreamStatus === 'manual_review' ? 'manual_review' : 'processing',
        'message'=>$upstreamStatus === 'manual_review'
            ? 'Supplier order requires manual review; do not submit a duplicate order'
            : 'Order is processing; check order_status with the same order reference',
        'data'=>storeBridgeProviderOrderPayload($storeOrderId),
    ];
}

function storeBridgeReconcileSupplierProviderOrder(int $storeOrderId, bool $force = false): array
{
    global $conn;
    if ($storeOrderId < 1 || !storeBridgeEnsureSchema()) {
        return ['success'=>false,'pending'=>true,'code'=>'supplier_unavailable','message'=>'Supplier reconciliation is unavailable'];
    }
    $stmt = $conn->prepare("SELECT id,upstream_order_id,upstream_status,error_message,status,updated_at
                            FROM store_api_orders WHERE id=? AND fulfillment_source='supplier' LIMIT 1");
    if (!$stmt) return ['success'=>false,'pending'=>true,'message'=>'Unable to load Store API order'];
    $stmt->bind_param('i', $storeOrderId);
    $stmt->execute();
    $r = $stmt->get_result();
    $store = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    if (!$store) return ['success'=>false,'pending'=>true,'message'=>'Store API supplier order not found'];
    if ((string) ($store['status'] ?? '') === 'success') return ['success'=>true,'pending'=>false,'data'=>storeBridgeProviderOrderPayload($storeOrderId)];

    $supplierOrderId = (int) ($store['upstream_order_id'] ?? 0);
    if ($supplierOrderId < 1) {
        $find = $conn->prepare("SELECT id FROM supplier_orders
                                WHERE source_kind='store_api' AND source_order_id=?
                                ORDER BY CASE WHEN status IN ('success','completed','manual_review','processing','unknown','submitting') THEN 0 ELSE 1 END ASC,id DESC LIMIT 1");
        if ($find) {
            $find->bind_param('i', $storeOrderId);
            $find->execute();
            $fr = $find->get_result();
            $f = $fr ? $fr->fetch_assoc() : null;
            $find->close();
            $supplierOrderId = (int) ($f['id'] ?? 0);
            if ($supplierOrderId > 0) storeBridgeUpdateSupplierOrderLink($storeOrderId, $supplierOrderId);
        }
    }

    if ($supplierOrderId < 1) {
        if (strtolower(trim((string) ($store['upstream_status'] ?? ''))) === 'refund_pending') {
            $reason = trim((string) ($store['error_message'] ?? ''));
            if ($reason === '') $reason = 'Supplier procurement did not start';
            $refund = storeBridgeRefundProviderOrder($storeOrderId, $reason, 'refund_pending');
            if (!empty($refund['success'])) {
                return ['success'=>false,'pending'=>false,'refunded'=>true,'code'=>'upstream_unavailable_refunded',
                    'message'=>'Supplier order was not created and the Store API balance was refunded',
                    'data'=>$refund['data'] ?? storeBridgeProviderOrderPayload($storeOrderId)];
            }
            return ['success'=>false,'pending'=>true,'code'=>'refund_pending','message'=>'Supplier order was not created; automatic refund is still pending'];
        }
        return ['success'=>false,'pending'=>true,'code'=>'upstream_order_not_created','message'=>'Supplier order has not been created yet'];
    }

    $snapshot = storeBridgeSupplierOrderSnapshot($supplierOrderId);
    if (!$snapshot) return ['success'=>false,'pending'=>true,'message'=>'Unable to inspect supplier order'];
    $status = strtolower(trim((string) ($snapshot['status'] ?? '')));
    $pendingStatuses = ['submitting','unknown','pending','processing'];
    $protected = supplierBridgeProviderRequiresProtectedPurchase((string) ($snapshot['provider_type'] ?? ''));
    $updatedTs = strtotime((string) ($snapshot['updated_at'] ?? ''));
    $since = $updatedTs === false ? PHP_INT_MAX : max(0, time() - $updatedTs);
    if (!$protected && in_array($status, $pendingStatuses, true)
        && ($force || $since >= supplierBridgeOrderReconcileMinIntervalSeconds())) {
        supplierBridgeReconcileOrder($supplierOrderId);
    }
    return storeBridgeApplySupplierOrderState($storeOrderId, $supplierOrderId);
}

function storeBridgeCatalogue(array $client): array
{
    global $conn;
    ensureProductArchiveTables();
    ensureProductPlatformsTable();
    $clientId = (int) ($client['id'] ?? 0);
    $applyLegacyProductRules = storeBridgeClientUsesLegacyProductRules($client) ? 1 : 0;
    $sql = "SELECT p.id AS source_product_id, p.name, p.description, p.image, p.category,
                   COALESCE(pp.platform, 'both') AS platform,
                   pv.id AS source_variant_id, pv.duration, pv.price_user, pv.price_reseller, pv.cost_price,
                   rule.custom_price, rule.enabled AS rule_enabled,
                   COUNT(k.id) AS remote_stock
            FROM products p
            JOIN product_variants pv ON pv.product_id = p.id AND pv.status = 'active'
            LEFT JOIN product_platforms pp ON pp.product_id = p.id
            LEFT JOIN product_admin_archives paa ON paa.product_id = p.id
            LEFT JOIN product_variant_admin_archives pva ON pva.variant_id = pv.id
            LEFT JOIN store_api_client_products rule ON rule.client_id=? AND rule.source_variant_id=pv.id
            LEFT JOIN `keys` k ON k.product_id = p.id AND k.status = 'available'
                 AND (k.variant_id = pv.id OR (k.variant_id IS NULL AND k.duration = pv.duration))
            WHERE p.status = 'active' AND paa.product_id IS NULL AND pva.variant_id IS NULL
              AND (?=0 OR COALESCE(rule.enabled,1)=1)
            GROUP BY p.id,p.name,p.description,p.image,p.category,pp.platform,pv.id,pv.duration,pv.price_user,pv.price_reseller,pv.cost_price,rule.custom_price,rule.enabled
            ORDER BY p.name ASC, pv.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Product catalogue query failed');
    $stmt->bind_param('ii', $clientId, $applyLegacyProductRules);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    // Price each row first, then read all linked CGO snapshots in one query.
    // This keeps /products fast and avoids one supplier/database lookup per row.
    $variantIds = [];
    $unitPrices = [];
    foreach ($rows as $i => $row) {
        $variantId = (int) ($row['source_variant_id'] ?? 0);
        $price = storeBridgeClientPrice($row, $client);
        $rows[$i]['store_api_unit_price'] = $price;
        if ($variantId > 0) {
            $variantIds[] = $variantId;
            $unitPrices[$variantId] = $price;
        }
    }
    $cgoSnapshots = storeBridgeClientAllowsCgo($client)
        ? storeBridgeCgoVariantSnapshots($variantIds, $unitPrices)
        : [];
    $supplierSnapshots = storeBridgeSupplierVariantSnapshots($client, $variantIds, $unitPrices);

    $products = [];
    $currency = (string) ($client['currency'] ?? storeBridgeCurrency());
    foreach ($rows as $row) {
        $productId = (int) $row['source_product_id'];
        $variantId = (int) $row['source_variant_id'];
        $localStock = max(0, (int) ($row['remote_stock'] ?? 0));
        $cgoStock = max(0, (int) (($cgoSnapshots[$variantId]['stock'] ?? 0)));
        $supplierStock = max(0, (int) (($supplierSnapshots[$variantId]['stock'] ?? 0)));
        // Never sum independent stock pools. One Store API order must be
        // fulfillable by one source in full: LOCAL, CGO, or one permitted
        // Store Bridge supplier connection.
        $stock = max($localStock, $cgoStock, $supplierStock);
        $price = round((float) ($row['store_api_unit_price'] ?? 0), 2);
        $duration = trim((string) ($row['duration'] ?? 'Standard'));
        if ($duration === '') $duration = 'Standard';
        $baseName = trim((string) $row['name']);
        $variantName = $duration !== '' && strcasecmp($duration, 'Standard') !== 0
            ? trim($baseName . ' - ' . $duration)
            : $baseName;
        $categories = storeBridgeProductCategories($productId);
        $category = trim((string) ($row['category'] ?? ''));
        $categoryCode = $category !== '' ? strtoupper(substr($category, 0, 40)) : '';
        $imageUrl = storeBridgeAbsoluteImageUrl((string) ($row['image'] ?? ''));
        $isAvailable = $stock > 0;

        $products[] = [
            'id' => 'variant-' . $variantId,
            'remote_product_id' => 'variant-' . $variantId,
            'product_id' => 'variant-' . $variantId,
            'variant_id' => 'variant-' . $variantId,
            'source_product_id' => 'product-' . $productId,
            'source_variant_id' => $variantId,
            'service_id' => 'product-' . $productId,
            'parent_id' => 'product-' . $productId,
            'name' => $baseName,
            'product_name' => $baseName,
            'variant_name' => $variantName,
            'package_name' => $variantName,
            'description' => (string) ($row['description'] ?? ''),
            'image_url' => $imageUrl,
            'image' => $imageUrl,
            'categories' => $categories,
            'category' => $category,
            'category_code' => $categoryCode,
            'platform' => normalizeProductPlatform((string) ($row['platform'] ?? ''), 'both'),
            'duration' => $duration,
            'duration_label' => $duration,
            'stock' => $stock,
            'available_stock' => $stock,
            'available' => $isAvailable,
            'active' => $isAvailable,
            'status' => $isAvailable ? 'available' : 'out_of_stock',
            'stock_status' => $isAvailable ? 'available' : 'out_of_stock',
            'currency' => $currency,
            'price' => $price,
            'cost' => $price,
            'reseller_price' => $price,
            'api_cost' => $price,
            'suggested_user_price' => round((float) ($row['price_user'] ?? 0), 2),
            'suggested_reseller_price' => round((float) ($row['price_reseller'] ?? 0), 2),
            'updated_at' => date(DATE_ATOM),
        ];
    }
    return $products;
}

function storeBridgeFindProviderProduct(string $remoteProductId, array $client, bool $forUpdate = false): ?array
{
    global $conn;
    if (!preg_match('/^variant-(\d+)$/', $remoteProductId, $m)) return null;
    $variantId = (int) $m[1];
    $clientId = (int) ($client['id'] ?? 0);
    if ($variantId < 1 || $clientId < 1) return null;
    $applyLegacyProductRules = storeBridgeClientUsesLegacyProductRules($client) ? 1 : 0;
    ensureProductArchiveTables();
    $sql = "SELECT p.id AS source_product_id, p.name, pv.id AS source_variant_id, pv.duration,
                   pv.price_user, pv.price_reseller, pv.cost_price, rule.custom_price, rule.enabled AS rule_enabled
            FROM product_variants pv
            JOIN products p ON p.id = pv.product_id AND p.status = 'active'
            LEFT JOIN product_admin_archives paa ON paa.product_id = p.id
            LEFT JOIN product_variant_admin_archives pva ON pva.variant_id = pv.id
            LEFT JOIN store_api_client_products rule ON rule.client_id=? AND rule.source_variant_id=pv.id
            WHERE pv.id = ? AND pv.status = 'active' AND paa.product_id IS NULL AND pva.variant_id IS NULL
              AND (?=0 OR COALESCE(rule.enabled,1)=1)
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('iii', $clientId, $variantId, $applyLegacyProductRules);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $row['remote_product_id'] = $remoteProductId;
    $row['unit_price'] = storeBridgeClientPrice($row, $client);
    return $row;
}

function storeBridgeProviderInventory(array $client, string $remoteProductId): array
{
    global $conn;
    $remoteProductId = trim($remoteProductId);
    $product = storeBridgeFindProviderProduct($remoteProductId, $client, false);
    if (!$product) {
        return ['success' => false, 'http_code' => 404, 'code' => 'product_not_found', 'message' => 'Product not found'];
    }

    $productId = (int) ($product['source_product_id'] ?? 0);
    $variantId = (int) ($product['source_variant_id'] ?? 0);
    $duration = trim((string) ($product['duration'] ?? ''));
    $stmt = $conn->prepare("SELECT COUNT(*) AS stock, COALESCE(MAX(k.id),0) AS max_key_id
                            FROM `keys` k
                            WHERE k.product_id=? AND k.status='available'
                              AND (k.variant_id=? OR (k.variant_id IS NULL AND k.duration=?))");
    if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'inventory_query_unavailable', 'message' => 'Inventory is temporarily unavailable'];
    $stmt->bind_param('iis', $productId, $variantId, $duration);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'http_code' => 503, 'code' => 'inventory_query_failed', 'message' => 'Inventory is temporarily unavailable'];
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $localStock = max(0, (int) ($row['stock'] ?? 0));
    $maxKeyId = max(0, (int) ($row['max_key_id'] ?? 0));
    $cgoSnapshots = storeBridgeClientAllowsCgo($client)
        ? storeBridgeCgoVariantSnapshots([$variantId], [$variantId => (float) ($product['unit_price'] ?? 0)])
        : [];
    $cgo = $cgoSnapshots[$variantId] ?? [];
    $cgoStock = max(0, (int) ($cgo['stock'] ?? 0));
    $supplierSnapshots = storeBridgeSupplierVariantSnapshots($client, [$variantId], [$variantId => (float) ($product['unit_price'] ?? 0)]);
    $supplier = $supplierSnapshots[$variantId] ?? [];
    $supplierStock = max(0, (int) ($supplier['stock'] ?? 0));
    $stock = max($localStock, $cgoStock, $supplierStock);
    $revisionParts = [
        $productId, $variantId, $localStock, $maxKeyId, $cgoStock,
        (int) ($cgo['cgo_product_id'] ?? 0),
        (string) ($cgo['inventory_checked_at'] ?? ''),
        (string) ($cgo['updated_at'] ?? ''),
        $supplierStock,
        (int) ($supplier['supplier_product_id'] ?? 0),
        (int) ($supplier['connection_id'] ?? 0),
        (string) ($supplier['inventory_checked_at'] ?? ''),
        (string) ($supplier['updated_at'] ?? ''),
    ];
    return [
        'success' => true,
        'http_code' => 200,
        'data' => [
            'product_id' => $remoteProductId,
            'source_product_id' => 'product-' . $productId,
            'source_variant_id' => $variantId,
            'stock' => $stock,
            'available_stock' => $stock,
            'status' => $stock > 0 ? 'available' : 'out_of_stock',
            'confirmed_at' => date(DATE_ATOM),
            'revision' => 'unified-' . substr(hash('sha256', implode(':', $revisionParts)), 0, 24),
            'currency' => (string) ($client['currency'] ?? storeBridgeCurrency()),
        ],
    ];
}

function storeBridgeProviderOrderPayload(int $orderId): ?array
{
    global $conn;
    $stmt = $conn->prepare("SELECT o.*, c.currency AS client_currency FROM store_api_orders o JOIN store_api_clients c ON c.id = o.client_id WHERE o.id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$order) return null;
    $keys = [];
    $keyStmt = $conn->prepare('SELECT key_code FROM store_api_order_keys WHERE order_id = ? ORDER BY id ASC');
    if ($keyStmt) {
        $keyStmt->bind_param('i', $orderId);
        if ($keyStmt->execute()) {
            $keyResult = $keyStmt->get_result();
            while ($row = $keyResult ? $keyResult->fetch_assoc() : null) {
                if (!$row) break;
                $keys[] = (string) $row['key_code'];
            }
        }
        $keyStmt->close();
    }
    $client = storeBridgeGetClient((int) ($order['client_id'] ?? 0));
    $currentBalance = $client ? storeBridgeClientCurrentBalance($client, false) : null;
    $status = strtolower(trim((string) ($order['status'] ?? '')));
    $pending = in_array($status, ['processing','pending','manual_review'], true);
    $refunded = !empty($order['refunded_at']) || (float) ($order['refunded_amount'] ?? 0) > 0.00001;
    return [
        'success' => $status === 'success',
        'pending' => $pending,
        'refunded' => $refunded,
        'status' => $status,
        'order_id' => (string) $order['id'],
        'external_ref' => (string) $order['external_ref'],
        'product_id' => (string) $order['remote_product_id'],
        'quantity' => (int) $order['quantity'],
        'unit_price' => (float) $order['unit_price'],
        'total' => (float) $order['total_price'],
        'currency' => (string) ($order['client_currency'] ?? storeBridgeCurrency()),
        'billing_mode' => storeBridgeNormalizeBillingMode($order['billing_mode'] ?? 'api_balance'),
        'balance_before' => $order['balance_before'] === null ? null : (float) $order['balance_before'],
        'balance_after' => $order['balance_after'] === null ? null : (float) $order['balance_after'],
        'origin_site_id' => (string) ($order['origin_site_id'] ?? ''),
        'origin_user_id' => (string) ($order['origin_user_id'] ?? ''),
        'customer_ref' => (string) ($order['customer_ref'] ?? ''),
        'customer_name' => (string) ($order['customer_name'] ?? ''),
        'customer_email' => (string) ($order['customer_email'] ?? ''),
        'keys' => $keys,
        'balance' => $currentBalance === null ? null : (float) $currentBalance,
        'retry_after_ms' => $pending ? 1000 : 0,
        'message' => (string) ($order['error_message'] ?? ''),
    ];
}

function storeBridgeBackfillProviderOrderIdentity(int $orderId, string $originSiteId, string $originUserId, string $customerRef): void
{
    global $conn;
    if ($orderId < 1 || $originSiteId === '' || $originUserId === '') return;
    if (!storeBridgeColumnExists('store_api_orders', 'origin_site_id')
        || !storeBridgeColumnExists('store_api_orders', 'origin_user_id')
        || !storeBridgeColumnExists('store_api_orders', 'customer_ref')) return;
    $canonicalRef = commerceCenterCustomerRef($originSiteId, $originUserId);
    if ($canonicalRef !== '') $customerRef = $canonicalRef;
    $stmt = $conn->prepare("UPDATE store_api_orders SET
        origin_site_id=IF(TRIM(COALESCE(origin_site_id,''))='',?,origin_site_id),
        origin_user_id=IF(TRIM(COALESCE(origin_user_id,''))='',?,origin_user_id),
        customer_ref=IF(TRIM(COALESCE(customer_ref,''))='',?,customer_ref)
        WHERE id=?");
    if (!$stmt) return;
    $stmt->bind_param('sssi', $originSiteId, $originUserId, $customerRef, $orderId);
    $stmt->execute();
    $stmt->close();
}

function storeBridgeOrderRequestFingerprint(
    string $remoteProductId,
    int $quantity,
    string $originSiteId = '',
    string $originUserId = '',
    string $customerRef = ''
): string
{
    return hash('sha256', json_encode([
        'product_id' => $remoteProductId,
        'quantity' => $quantity,
        'origin_site_id' => $originSiteId,
        'origin_user_id' => $originUserId,
        'customer_ref' => $customerRef,
    ], JSON_UNESCAPED_SLASHES));
}

function storeBridgeLegacyOrderRequestFingerprint(string $remoteProductId, int $quantity): string
{
    return hash('sha256', json_encode([
        'product_id' => $remoteProductId,
        'quantity' => $quantity,
    ], JSON_UNESCAPED_SLASHES));
}


function storeBridgeBuildOrderEvidence(array $client, array $payload, array $result, array $timeline = []): array
{
    global $conn;
    $clientId = max(0, (int) ($client['id'] ?? 0));
    $remoteRaw = array_key_exists('product_id', $payload) ? $payload['product_id'] : ($payload['remote_product_id'] ?? '');
    $remoteProductId = is_scalar($remoteRaw) ? substr(trim((string) $remoteRaw), 0, 190) : '';
    $externalRef = isset($payload['external_ref']) && is_scalar($payload['external_ref']) ? substr(trim((string) $payload['external_ref']), 0, 120) : '';
    $quantity = isset($payload['quantity']) && is_numeric($payload['quantity']) ? (int) $payload['quantity'] : 1;
    $originSiteId = isset($payload['origin_site_id']) && is_scalar($payload['origin_site_id']) ? substr(strtolower(trim((string) $payload['origin_site_id'])), 0, 100) : '';
    $originUserId = isset($payload['origin_user_id']) && is_scalar($payload['origin_user_id']) ? trim((string) $payload['origin_user_id']) : '';
    $customerRef = isset($payload['customer_ref']) && is_scalar($payload['customer_ref']) ? trim((string) $payload['customer_ref']) : '';
    $resultCode = isset($result['code']) && is_scalar($result['code']) ? substr(trim((string) $result['code']), 0, 80) : (!empty($result['success']) ? 'ok' : 'order_failed');
    $failureDiagnostic = isset($result['diagnostic']) && is_array($result['diagnostic'])
        ? storeBridgeSafeOperationDiagnostic($result['diagnostic'])
        : [];
    // Build-order evidence must not recursively contain itself.
    unset($failureDiagnostic['order_evidence']);

    $evidence = [
        'schema' => 'sakazuki.debug',
        'version' => 2,
        'type' => 'store_api.order',
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'request' => [
            'client_id' => $clientId,
            'external_ref' => $externalRef,
            'product_id' => $remoteProductId,
            'quantity' => $quantity,
            'origin_site_id' => $originSiteId,
            'origin_user_id_present' => $originUserId !== '',
            'origin_user_id_sha256' => $originUserId !== '' ? hash('sha256', $originUserId) : '',
            'customer_ref_present' => $customerRef !== '',
            'customer_ref_sha256' => $customerRef !== '' ? hash('sha256', $customerRef) : '',
        ],
        'result' => [
            'success' => !empty($result['success']),
            'pending' => !empty($result['data']['pending']) || ((int) ($result['http_code'] ?? 0) === 202),
            'http_code' => max(0, min(599, (int) ($result['http_code'] ?? 0))),
            'code' => $resultCode,
            'message' => storeBridgeDiagnosticSanitizeMessage((string) ($result['message'] ?? ''), 500),
        ],
        'order' => ['found' => false],
        'fulfillment' => [
            'source' => '',
            'reason' => '',
            'upstream' => null,
        ],
        'delivery' => [
            'stored_key_count' => 0,
            'keys' => [],
            'plaintext_keys_excluded' => true,
        ],
        'billing' => [],
        'integrity' => ['order_found' => false],
        'failure' => $failureDiagnostic === [] ? null : $failureDiagnostic,
        'timeline' => storeBridgeSafeTimeline($timeline),
        'warnings' => [],
        'privacy' => [
            'api_credentials_excluded' => true,
            'plaintext_keys_excluded' => true,
            'provider_raw_response_excluded' => true,
            'customer_name_excluded' => true,
            'customer_email_excluded' => true,
            'identity_values_hashed' => true,
        ],
    ];

    try {
        if (!isset($conn) || !($conn instanceof mysqli) || $clientId < 1) {
            $evidence['warnings'][] = 'database_or_client_context_unavailable';
            return $evidence;
        }
        $orderId = 0;
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
        if (isset($data['order_id']) && is_scalar($data['order_id']) && ctype_digit((string) $data['order_id'])) $orderId = (int) $data['order_id'];
        if ($orderId < 1 && $externalRef !== '') {
            $lookup = $conn->prepare('SELECT id FROM store_api_orders WHERE client_id=? AND external_ref=? LIMIT 1');
            if ($lookup) {
                $lookup->bind_param('is', $clientId, $externalRef);
                if ($lookup->execute()) {
                    $lr = $lookup->get_result();
                    $lrow = $lr ? $lr->fetch_assoc() : null;
                    $orderId = (int) ($lrow['id'] ?? 0);
                }
                $lookup->close();
            }
        }
        if ($orderId < 1) {
            $evidence['warnings'][] = 'order_row_not_created';
            return $evidence;
        }

        $stmt = $conn->prepare("SELECT id,client_id,external_ref,remote_product_id,source_product_id,source_variant_id,duration,quantity,unit_price,total_price,status,
                                       billing_mode,billing_user_id,billing_transaction_id,balance_before,balance_after,request_fingerprint,
                                       fulfillment_source,fulfillment_reason,upstream_order_id,upstream_reference,upstream_status,procurement_cost,
                                       refunded_amount,refunded_at,error_message,created_at,updated_at,completed_at
                                FROM store_api_orders WHERE id=? AND client_id=? LIMIT 1");
        if (!$stmt) { $evidence['warnings'][] = 'order_evidence_query_prepare_failed'; return $evidence; }
        $stmt->bind_param('ii', $orderId, $clientId);
        if (!$stmt->execute()) { $stmt->close(); $evidence['warnings'][] = 'order_evidence_query_failed'; return $evidence; }
        $or = $stmt->get_result();
        $order = $or ? $or->fetch_assoc() : null;
        $stmt->close();
        if (!$order) return $evidence;

        $storedFingerprint = strtolower(trim((string) ($order['request_fingerprint'] ?? '')));
        $expectedFingerprint = '';
        if ($remoteProductId !== '' && $quantity > 0) {
            $canonicalCustomerRef = commerceCenterCustomerRef($originSiteId, $originUserId);
            if ($canonicalCustomerRef !== '') $customerRef = $canonicalCustomerRef;
            $expectedFingerprint = storeBridgeOrderRequestFingerprint($remoteProductId, $quantity, $originSiteId, $originUserId, $customerRef);
        }
        $billingMode = storeBridgeNormalizeBillingMode($order['billing_mode'] ?? 'api_balance');
        $balanceBefore = $order['balance_before'] === null ? null : round((float) $order['balance_before'], 2);
        $balanceAfter = $order['balance_after'] === null ? null : round((float) $order['balance_after'], 2);
        $total = round((float) ($order['total_price'] ?? 0), 2);
        $expectedQuantity = max(0, (int) ($order['quantity'] ?? 0));
        $refundedAmount = round((float) ($order['refunded_amount'] ?? 0), 2);
        $isRefunded = !empty($order['refunded_at']) || $refundedAmount > 0.00001;
        $fulfillmentSource = substr(strtolower(trim((string) ($order['fulfillment_source'] ?? 'local'))), 0, 20);

        $evidence['order'] = [
            'found' => true,
            'order_id' => (int) $order['id'],
            'client_id' => (int) $order['client_id'],
            'external_ref' => (string) $order['external_ref'],
            'product_id' => (string) $order['remote_product_id'],
            'source_product_id' => (int) $order['source_product_id'],
            'source_variant_id' => (int) ($order['source_variant_id'] ?? 0),
            'duration' => substr((string) ($order['duration'] ?? ''), 0, 120),
            'quantity' => $expectedQuantity,
            'unit_price' => round((float) ($order['unit_price'] ?? 0), 2),
            'total_price' => $total,
            'status' => substr((string) ($order['status'] ?? ''), 0, 40),
            'billing_mode' => $billingMode,
            'billing_user_id' => (int) ($order['billing_user_id'] ?? 0),
            'billing_transaction_id' => (int) ($order['billing_transaction_id'] ?? 0),
            'balance_before' => $balanceBefore,
            'balance_after_debit' => $balanceAfter,
            'refunded_amount' => $refundedAmount,
            'refunded_at' => (string) ($order['refunded_at'] ?? ''),
            'request_fingerprint' => preg_match('/^[a-f0-9]{64}$/D', $storedFingerprint) === 1 ? $storedFingerprint : '',
            'error_message' => storeBridgeDiagnosticSanitizeMessage((string) ($order['error_message'] ?? ''), 500),
            'created_at' => (string) ($order['created_at'] ?? ''),
            'updated_at' => (string) ($order['updated_at'] ?? ''),
            'completed_at' => (string) ($order['completed_at'] ?? ''),
        ];

        $evidence['fulfillment'] = [
            'source' => $fulfillmentSource,
            'reason' => substr((string) ($order['fulfillment_reason'] ?? ''), 0, 80),
            'single_source_policy' => true,
            'local_and_cgo_stock_are_not_summed' => true,
            'procurement_cost' => $order['procurement_cost'] === null ? null : round((float) $order['procurement_cost'], 2),
            'upstream_order_id' => (int) ($order['upstream_order_id'] ?? 0),
            'upstream_reference' => substr((string) ($order['upstream_reference'] ?? ''), 0, 190),
            'upstream_status' => substr((string) ($order['upstream_status'] ?? ''), 0, 40),
            'upstream' => null,
        ];

        $keys = [];
        $keyStmt = $conn->prepare('SELECT id,source_type,source_key_id,source_order_id,key_hash,created_at FROM store_api_order_keys WHERE order_id=? ORDER BY id ASC');
        if ($keyStmt) {
            $keyStmt->bind_param('i', $orderId);
            if ($keyStmt->execute()) {
                $kr = $keyStmt->get_result();
                while ($row = $kr ? $kr->fetch_assoc() : null) {
                    if (!$row) break;
                    $hash = strtolower(trim((string) ($row['key_hash'] ?? '')));
                    $keys[] = [
                        'row_id' => (int) ($row['id'] ?? 0),
                        'source_type' => substr((string) ($row['source_type'] ?? 'local'), 0, 20),
                        'source_key_id' => $row['source_key_id'] === null ? null : (int) $row['source_key_id'],
                        'source_order_id' => $row['source_order_id'] === null ? null : (int) $row['source_order_id'],
                        'key_hash' => preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 ? $hash : '',
                        'created_at' => (string) ($row['created_at'] ?? ''),
                    ];
                    if (count($keys) >= 100) break;
                }
            }
            $keyStmt->close();
        }
        $evidence['delivery']['stored_key_count'] = count($keys);
        $evidence['delivery']['keys'] = $keys;

        if ($fulfillmentSource === 'cgo') {
            $cgoOrderId = (int) ($order['upstream_order_id'] ?? 0);
            if ($cgoOrderId < 1 && storeBridgeEnsureCgoRuntime()) {
                $f = $conn->prepare("SELECT id FROM cgo_orders WHERE source_kind='store_api' AND source_order_id=? ORDER BY id DESC LIMIT 1");
                if ($f) {
                    $f->bind_param('i', $orderId); $f->execute();
                    $fr = $f->get_result(); $found = $fr ? $fr->fetch_assoc() : null; $f->close();
                    $cgoOrderId = (int) ($found['id'] ?? 0);
                }
            }
            if ($cgoOrderId > 0) {
                $cgo = storeBridgeCgoOrderSnapshot($cgoOrderId, true);
                if ($cgo) {
                    $attempts = [];
                    foreach ((array) ($cgo['api_attempts'] ?? []) as $a) {
                        $attempts[] = [
                            'id' => (int) ($a['id'] ?? 0),
                            'phase' => substr((string) ($a['phase'] ?? ''), 0, 40),
                            'lookup_mode' => substr((string) ($a['lookup_mode'] ?? ''), 0, 40),
                            'http_code' => (int) ($a['http_code'] ?? 0),
                            'transport_error' => !empty($a['transport_error']),
                            'curl_errno' => (int) ($a['curl_errno'] ?? 0),
                            'provider_error_code' => substr((string) ($a['provider_error_code'] ?? ''), 0, 190),
                            'provider_error_class' => substr((string) ($a['provider_error_class'] ?? ''), 0, 120),
                            'provider_status' => substr((string) ($a['provider_status'] ?? ''), 0, 80),
                            'delivered_key_count' => (int) ($a['delivered_key_count'] ?? 0),
                            'primary_ip' => substr((string) ($a['primary_ip'] ?? ''), 0, 80),
                            'cf_ray' => substr((string) ($a['cf_ray'] ?? ''), 0, 190),
                            'request_id' => substr((string) ($a['request_id'] ?? ''), 0, 190),
                            'request_started_at_ms' => isset($a['request_started_at_ms']) ? (int) $a['request_started_at_ms'] : null,
                            'request_finished_at_ms' => isset($a['request_finished_at_ms']) ? (int) $a['request_finished_at_ms'] : null,
                            'dns_ms' => (int) ($a['namelookup_time_ms'] ?? 0),
                            'connect_ms' => (int) ($a['connect_time_ms'] ?? 0),
                            'tls_ms' => (int) ($a['appconnect_time_ms'] ?? 0),
                            'pretransfer_ms' => (int) ($a['pretransfer_time_ms'] ?? 0),
                            'ttfb_ms' => (int) ($a['starttransfer_time_ms'] ?? 0),
                            'total_ms' => (int) ($a['total_time_ms'] ?? 0),
                            'response_sha256' => preg_match('/^[a-f0-9]{64}$/i', (string) ($a['response_sha256'] ?? '')) === 1 ? strtolower((string) $a['response_sha256']) : '',
                            'decision' => substr((string) ($a['decision'] ?? ''), 0, 120),
                            'error_message' => storeBridgeDiagnosticSanitizeMessage((string) ($a['error_message'] ?? ''), 500),
                            'created_at' => (string) ($a['created_at'] ?? ''),
                        ];
                    }
                    $evidence['fulfillment']['upstream'] = [
                        'provider' => 'cgo',
                        'order_id' => (int) ($cgo['id'] ?? 0),
                        'source_link_valid' => (string) ($cgo['source_kind'] ?? '') === 'store_api' && (int) ($cgo['source_order_id'] ?? 0) === $orderId,
                        'cgo_product_id' => (int) ($cgo['cgo_product_id'] ?? 0),
                        'remote_product_id' => substr((string) ($cgo['remote_product_id'] ?? ''), 0, 120),
                        'quantity' => (int) ($cgo['quantity'] ?? 0),
                        'status' => substr((string) ($cgo['status'] ?? ''), 0, 40),
                        'supplier_order_id' => substr((string) ($cgo['supplier_order_id'] ?? ''), 0, 190),
                        'external_ref' => substr((string) ($cgo['external_ref'] ?? ''), 0, 120),
                        'unit_cost' => round((float) ($cgo['unit_cost_base'] ?? 0), 2),
                        'total_cost' => round((float) ($cgo['total_cost_base'] ?? 0), 2),
                        'stored_key_count' => count((array) ($cgo['keys'] ?? [])),
                        'error_message' => storeBridgeDiagnosticSanitizeMessage((string) ($cgo['error_message'] ?? ''), 500),
                        'api_attempt_count' => count($attempts),
                        'api_attempts' => $attempts,
                        'created_at' => (string) ($cgo['created_at'] ?? ''),
                        'updated_at' => (string) ($cgo['updated_at'] ?? ''),
                        'completed_at' => (string) ($cgo['completed_at'] ?? ''),
                    ];
                }
            }
        }

        if ($fulfillmentSource === 'supplier') {
            $supplierOrderId = (int) ($order['upstream_order_id'] ?? 0);
            if ($supplierOrderId < 1) {
                $f = $conn->prepare("SELECT id FROM supplier_orders
                    WHERE source_kind='store_api' AND source_order_id=?
                    ORDER BY CASE WHEN status IN ('success','completed','manual_review','processing','unknown','submitting') THEN 0 ELSE 1 END ASC,id DESC LIMIT 1");
                if ($f) {
                    $f->bind_param('i', $orderId);
                    $f->execute();
                    $fr = $f->get_result();
                    $found = $fr ? $fr->fetch_assoc() : null;
                    $f->close();
                    $supplierOrderId = (int) ($found['id'] ?? 0);
                }
            }
            if ($supplierOrderId > 0) {
                $supplier = storeBridgeSupplierOrderSnapshot($supplierOrderId);
                if ($supplier) {
                    $evidence['fulfillment']['upstream'] = [
                        'provider' => substr((string)($supplier['provider_type'] ?? 'supplier'), 0, 80),
                        'order_id' => (int)($supplier['id'] ?? 0),
                        'source_link_valid' => (string)($supplier['source_kind'] ?? '') === 'store_api'
                            && (int)($supplier['source_order_id'] ?? 0) === $orderId,
                        'connection_id' => (int)($supplier['connection_id'] ?? 0),
                        'supplier_product_id' => (int)($supplier['supplier_product_id'] ?? 0),
                        'quantity' => (int)($supplier['quantity'] ?? 0),
                        'status' => substr((string)($supplier['status'] ?? ''), 0, 40),
                        'supplier_order_id' => substr((string)($supplier['supplier_order_id'] ?? ''), 0, 190),
                        'external_ref' => substr((string)($supplier['external_ref'] ?? ''), 0, 120),
                        'unit_cost' => round((float)($supplier['unit_cost_base'] ?? 0), 2),
                        'total_cost' => round((float)($supplier['total_cost_base'] ?? 0), 2),
                        'stored_key_count' => count((array)($supplier['keys'] ?? [])),
                        'error_message' => storeBridgeDiagnosticSanitizeMessage((string)($supplier['error_message'] ?? ''), 500),
                        'created_at' => (string)($supplier['created_at'] ?? ''),
                        'updated_at' => (string)($supplier['updated_at'] ?? ''),
                        'completed_at' => (string)($supplier['completed_at'] ?? ''),
                    ];
                }
            }
        }

        if ($billingMode === 'reseller_wallet') {
            $transactionId = (int) ($order['billing_transaction_id'] ?? 0);
            $transaction = null;
            if ($transactionId > 0 && storeBridgeTableExists('transactions')) {
                $tx = $conn->prepare('SELECT id,user_id,type,amount,status,reference_id,created_at FROM transactions WHERE id=? LIMIT 1');
                if ($tx) {
                    $tx->bind_param('i', $transactionId); $tx->execute();
                    $tr = $tx->get_result(); $transaction = $tr ? $tr->fetch_assoc() : null; $tx->close();
                }
            }
            $walletRows = [];
            if (storeBridgeTableExists('wallet_balance_ledger')) {
                $wl = $conn->prepare("SELECT id,event_key,user_id,direction,amount,delta_amount,balance_before,balance_after,source_type,source_id,transaction_id,reference_code,created_at
                                      FROM wallet_balance_ledger WHERE event_key IN (?,?) ORDER BY id ASC");
                if ($wl) {
                    $debitKey = 'store_api_order:' . $orderId;
                    $refundKey = 'store_api_refund:' . $orderId;
                    $wl->bind_param('ss', $debitKey, $refundKey); $wl->execute();
                    $wr = $wl->get_result();
                    while ($w = $wr ? $wr->fetch_assoc() : null) {
                        if (!$w) break;
                        $walletRows[] = [
                            'id' => (int) ($w['id'] ?? 0), 'event_key' => substr((string) ($w['event_key'] ?? ''), 0, 191),
                            'direction' => substr((string) ($w['direction'] ?? ''), 0, 12), 'delta_amount' => round((float) ($w['delta_amount'] ?? 0), 2),
                            'balance_before' => round((float) ($w['balance_before'] ?? 0), 2), 'balance_after' => round((float) ($w['balance_after'] ?? 0), 2),
                            'source_type' => substr((string) ($w['source_type'] ?? ''), 0, 64), 'source_id' => (int) ($w['source_id'] ?? 0),
                            'transaction_id' => (int) ($w['transaction_id'] ?? 0), 'created_at' => (string) ($w['created_at'] ?? ''),
                        ];
                    }
                    $wl->close();
                }
            }
            $evidence['billing'] = [
                'mode' => $billingMode, 'balance_before' => $balanceBefore, 'debited_amount' => $total, 'balance_after_debit' => $balanceAfter,
                'refunded' => $isRefunded, 'refunded_amount' => $refundedAmount,
                'transaction' => $transaction ? [
                    'id' => (int) ($transaction['id'] ?? 0), 'user_id' => (int) ($transaction['user_id'] ?? 0),
                    'type' => substr((string) ($transaction['type'] ?? ''), 0, 50), 'amount' => round((float) ($transaction['amount'] ?? 0), 2),
                    'status' => substr((string) ($transaction['status'] ?? ''), 0, 30), 'reference_id' => (int) ($transaction['reference_id'] ?? 0),
                    'created_at' => (string) ($transaction['created_at'] ?? ''),
                ] : null,
                'wallet_ledger' => $walletRows,
            ];
            $debitValid = false; $refundValid = !$isRefunded;
            foreach ($walletRows as $w) {
                if (($w['event_key'] ?? '') === 'store_api_order:' . $orderId && abs((float) $w['delta_amount'] + $total) <= 0.01) $debitValid = true;
                if (($w['event_key'] ?? '') === 'store_api_refund:' . $orderId && abs((float) $w['delta_amount'] - $refundedAmount) <= 0.01) $refundValid = true;
            }
            $evidence['integrity']['billing_transaction_link_valid'] = $transactionId < 1 ? null : ($transaction !== null && (int) ($transaction['reference_id'] ?? 0) === $orderId);
            $evidence['integrity']['billing_debit_ledger_valid'] = $walletRows === [] ? null : $debitValid;
            $evidence['integrity']['refund_ledger_valid'] = $isRefunded ? ($walletRows === [] ? null : $refundValid) : null;
        } else {
            $ledgerRows = [];
            if (storeBridgeTableExists('store_api_balance_ledger')) {
                $ls = $conn->prepare('SELECT id,entry_type,amount,balance_after,created_at FROM store_api_balance_ledger WHERE client_id=? AND order_id=? ORDER BY id ASC');
                if ($ls) {
                    $ls->bind_param('ii', $clientId, $orderId); $ls->execute();
                    $lr = $ls->get_result();
                    while ($l = $lr ? $lr->fetch_assoc() : null) {
                        if (!$l) break;
                        $ledgerRows[] = ['id'=>(int)($l['id']??0),'entry_type'=>substr((string)($l['entry_type']??''),0,40),'amount'=>round((float)($l['amount']??0),2),'balance_after'=>round((float)($l['balance_after']??0),2),'created_at'=>(string)($l['created_at']??'')];
                    }
                    $ls->close();
                }
            }
            $evidence['billing'] = [
                'mode'=>$billingMode,'balance_before'=>$balanceBefore,'debited_amount'=>$total,'balance_after_debit'=>$balanceAfter,
                'refunded'=>$isRefunded,'refunded_amount'=>$refundedAmount,'api_balance_ledger'=>$ledgerRows,
            ];
            $debitValid=false; $refundValid=!$isRefunded;
            foreach($ledgerRows as $l){
                if(($l['entry_type']??'')==='order_debit' && abs((float)$l['amount']+$total)<=0.01) $debitValid=true;
                if(($l['entry_type']??'')==='order_refund' && abs((float)$l['amount']-$refundedAmount)<=0.01) $refundValid=true;
            }
            $evidence['integrity']['billing_debit_ledger_valid']=$ledgerRows===[]?null:$debitValid;
            $evidence['integrity']['refund_ledger_valid']=$isRefunded?($ledgerRows===[]?null:$refundValid):null;
        }

        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $balanceMathValid = $balanceBefore !== null && $balanceAfter !== null ? abs(($balanceBefore - $total) - $balanceAfter) <= 0.01 : null;
        $allKeyHashesValid = true;
        foreach ($keys as $keyRow) { if (($keyRow['key_hash'] ?? '') === '') { $allKeyHashesValid = false; break; } }
        $evidence['integrity']['order_found'] = true;
        $evidence['integrity']['request_fingerprint_match'] = $expectedFingerprint !== '' && $storedFingerprint !== '' ? hash_equals($storedFingerprint, $expectedFingerprint) : null;
        $evidence['integrity']['delivered_key_count_matches_expected'] = count($keys) === $expectedQuantity;
        $evidence['integrity']['stored_key_hashes_valid'] = $keys === [] ? null : $allKeyHashesValid;
        $evidence['integrity']['initial_debit_balance_math_valid'] = $balanceMathValid;
        $evidence['integrity']['single_source_delivery_valid'] = $keys === [] ? null : count(array_unique(array_map(static fn($k) => (string) ($k['source_type'] ?? ''), $keys))) === 1;
        $evidence['integrity']['cgo_source_link_valid'] = $fulfillmentSource !== 'cgo' ? null : (is_array($evidence['fulfillment']['upstream'] ?? null) ? !empty($evidence['fulfillment']['upstream']['source_link_valid']) : null);
        $evidence['integrity']['supplier_source_link_valid'] = $fulfillmentSource !== 'supplier' ? null : (is_array($evidence['fulfillment']['upstream'] ?? null) ? !empty($evidence['fulfillment']['upstream']['source_link_valid']) : null);
        $completedTimestampPresent = trim((string) ($order['completed_at'] ?? '')) !== '';
        $evidence['integrity']['completed_timestamp_present'] = $completedTimestampPresent;
        if ($status !== 'success') $evidence['integrity']['success_state_consistent'] = null;
        else $evidence['integrity']['success_state_consistent'] = count($keys) === $expectedQuantity && $completedTimestampPresent && $balanceMathValid !== false;
        $evidence['integrity']['refund_state_consistent'] = !$isRefunded ? null : ($status === 'failed' && abs($refundedAmount - $total) <= 0.01 && ($evidence['integrity']['refund_ledger_valid'] ?? null) !== false);
    } catch (Throwable $e) {
        $evidence['warnings'][] = 'evidence_build_exception';
        $evidence['evidence_error'] = ['message'=>storeBridgeDiagnosticSanitizeMessage($e->getMessage(),500),'sha256'=>hash('sha256',$e->getMessage())];
    }
    return $evidence;
}

function storeBridgeExistingOrderResult(int $clientId, string $externalRef, string $remoteProductId, int $quantity, string $originSiteId, string $originUserId, string $customerRef): ?array
{
    global $conn;
    $stmt = $conn->prepare('SELECT id,remote_product_id,quantity,request_fingerprint,origin_site_id,origin_user_id,customer_ref,status,fulfillment_source FROM store_api_orders WHERE client_id=? AND external_ref=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('is', $clientId, $externalRef);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $fingerprint = storeBridgeOrderRequestFingerprint($remoteProductId, $quantity, $originSiteId, $originUserId, $customerRef);
    $legacyFingerprint = storeBridgeLegacyOrderRequestFingerprint($remoteProductId, $quantity);
    $storedFingerprint = trim((string) ($row['request_fingerprint'] ?? ''));
    $storedOriginSite = trim((string) ($row['origin_site_id'] ?? ''));
    $storedOriginUser = trim((string) ($row['origin_user_id'] ?? ''));
    $storedCustomerRef = trim((string) ($row['customer_ref'] ?? ''));
    $sameProductAndQuantity = hash_equals((string) ($row['remote_product_id'] ?? ''), $remoteProductId)
        && (int) ($row['quantity'] ?? 0) === $quantity;
    $isLegacyIdentity = $storedFingerprint === '' || hash_equals($storedFingerprint, $legacyFingerprint);
    if ($isLegacyIdentity) {
        $identityMatches = ($storedOriginSite === '' || $originSiteId === '' || hash_equals($storedOriginSite, $originSiteId))
            && ($storedOriginUser === '' || $originUserId === '' || hash_equals($storedOriginUser, $originUserId))
            && ($storedCustomerRef === '' || $customerRef === '' || hash_equals($storedCustomerRef, $customerRef));
        $matches = $sameProductAndQuantity && $identityMatches;
    } else {
        $matches = $sameProductAndQuantity && hash_equals($storedFingerprint, $fingerprint);
    }
    if (!$matches) return ['success' => false, 'http_code' => 409, 'code' => 'idempotency_conflict', 'message' => 'external_ref was already used with a different order payload'];
    $orderId = (int) $row['id'];
    if ($isLegacyIdentity) storeBridgeBackfillProviderOrderIdentity($orderId, $originSiteId, $originUserId, $customerRef);
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $existingSource = strtolower(trim((string) ($row['fulfillment_source'] ?? '')));
    if ($existingSource === 'cgo' && in_array($status, ['processing','pending','manual_review'], true)) {
        storeBridgeReconcileCgoProviderOrder($orderId, false);
    } elseif ($existingSource === 'supplier' && in_array($status, ['processing','pending','manual_review'], true)) {
        storeBridgeReconcileSupplierProviderOrder($orderId, false);
    }
    commerceCenterSyncSafe('store_api_sale', $orderId);
    $data = storeBridgeProviderOrderPayload($orderId);
    $status = strtolower(trim((string) ($data['status'] ?? '')));
    if ($status === 'success') return ['success' => true, 'http_code' => 200, 'data' => $data];
    if (!empty($data['pending'])) return ['success' => true, 'http_code' => 202, 'code' => 'processing', 'message' => 'Order is still processing; do not create a duplicate order', 'data' => $data];
    return ['success' => false, 'http_code' => 409, 'code' => !empty($data['refunded']) ? 'order_failed_refunded' : 'order_failed', 'message' => (string) (($data['message'] ?? '') ?: 'Order failed'), 'data' => $data];
}

function storeBridgeCreateProviderOrder(array $client, array $payload): array
{
    global $conn;
    $clientId = (int) ($client['id'] ?? 0);
    $operationStartedAt = microtime(true);
    $orderTimeline = [];
    storeBridgeTimelineMark($orderTimeline, 'order_received', $operationStartedAt);
    $finishOrder = static function (array $result) use ($client, $payload, &$orderTimeline, $operationStartedAt): array {
        $code = isset($result['code']) && is_scalar($result['code'])
            ? substr(trim((string) $result['code']), 0, 80)
            : (!empty($result['success']) ? 'ok' : 'order_failed');
        storeBridgeTimelineMark($orderTimeline, 'business_result_ready', $operationStartedAt, [
            'success' => !empty($result['success']),
            'http_code' => (int) ($result['http_code'] ?? 0),
            'code' => $code,
        ]);
        storeBridgeTimelineMark($orderTimeline, 'evidence_build_started', $operationStartedAt);
        $diagnostic = isset($result['diagnostic']) && is_array($result['diagnostic']) ? $result['diagnostic'] : [];
        $orderEvidence = storeBridgeBuildOrderEvidence($client, $payload, $result, $orderTimeline);
        storeBridgeTimelineMark($orderTimeline, 'evidence_build_completed', $operationStartedAt);
        storeBridgeTimelineMark($orderTimeline, 'order_finished', $operationStartedAt);
        $orderEvidence['timeline'] = storeBridgeSafeTimeline($orderTimeline);
        $diagnostic['order_evidence'] = $orderEvidence;
        $result['diagnostic'] = $diagnostic;
        return $result;
    };

    $stringFields = [
        'product_id' => 190, 'remote_product_id' => 190, 'external_ref' => 120,
        'customer_name' => 190, 'customer_email' => 190, 'origin_site_id' => 100,
        'origin_user_id' => 190, 'customer_ref' => 255,
    ];
    foreach ($stringFields as $field => $maxLength) {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) continue;
        if (!is_scalar($payload[$field]) || strlen(trim((string) $payload[$field])) > $maxLength) {
            storeBridgeTimelineMark($orderTimeline, 'validation_failed', $operationStartedAt, ['field' => $field]);
            return $finishOrder(['success' => false, 'http_code' => 422, 'code' => 'invalid_request', 'message' => 'Invalid order request field: ' . $field]);
        }
    }
    $remoteRaw = array_key_exists('product_id', $payload) ? $payload['product_id'] : ($payload['remote_product_id'] ?? '');
    $remoteProductId = is_scalar($remoteRaw) ? trim((string) $remoteRaw) : '';
    $externalRef = isset($payload['external_ref']) && is_scalar($payload['external_ref']) ? trim((string) $payload['external_ref']) : '';
    $quantityRaw = $payload['quantity'] ?? 1;
    if (is_int($quantityRaw)) $quantity = $quantityRaw;
    elseif (is_string($quantityRaw) && preg_match('/^\d+$/D', trim($quantityRaw)) === 1) $quantity = (int) trim($quantityRaw);
    elseif (is_float($quantityRaw) && is_finite($quantityRaw) && floor($quantityRaw) === $quantityRaw) $quantity = (int) $quantityRaw;
    else {
        storeBridgeTimelineMark($orderTimeline, 'validation_failed', $operationStartedAt, ['field' => 'quantity']);
        return $finishOrder(['success' => false, 'http_code' => 422, 'code' => 'invalid_quantity', 'message' => 'quantity must be an integer from 1 to 100']);
    }
    $customerName = commerceCenterText($payload['customer_name'] ?? '', 190);
    $customerEmail = commerceCenterText($payload['customer_email'] ?? '', 190);
    $originSiteId = strtolower(commerceCenterText($payload['origin_site_id'] ?? '', 100));
    if ($originSiteId !== '' && preg_match('/^[a-z0-9._:-]{1,100}$/D', $originSiteId) !== 1) {
        return $finishOrder(['success' => false, 'http_code' => 422, 'code' => 'invalid_origin_site_id', 'message' => 'origin_site_id contains unsupported characters']);
    }
    $originUserId = commerceCenterText($payload['origin_user_id'] ?? '', 190);
    $customerRef = commerceCenterText($payload['customer_ref'] ?? '', 255);
    $canonicalCustomerRef = commerceCenterCustomerRef($originSiteId, $originUserId);
    if ($canonicalCustomerRef !== '') $customerRef = $canonicalCustomerRef;
    if ($clientId < 1 || preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $externalRef) !== 1
        || preg_match('/^variant-\d+$/D', $remoteProductId) !== 1 || $quantity < 1 || $quantity > 100) {
        storeBridgeTimelineMark($orderTimeline, 'validation_failed', $operationStartedAt);
        return $finishOrder(['success' => false, 'http_code' => 422, 'code' => 'invalid_request', 'message' => 'Invalid order request']);
    }
    if (!storeBridgeRuntimeSchemaReady()) {
        storeBridgeTimelineMark($orderTimeline, 'schema_not_ready', $operationStartedAt);
        return $finishOrder([
            'success' => false, 'http_code' => 503, 'code' => 'schema_not_ready',
            'message' => 'Store API schema upgrade is not ready yet',
            'order_created' => false, 'definitive_failure' => true,
            'diagnostic' => [
                'failure_class' => 'database_schema',
                'failure_stage' => 'preflight_schema',
                'transaction_started' => false,
                'commit_attempted' => false,
                'order_created' => false,
            ],
        ]);
    }
    $existingResult = storeBridgeExistingOrderResult($clientId, $externalRef, $remoteProductId, $quantity, $originSiteId, $originUserId, $customerRef);
    storeBridgeTimelineMark($orderTimeline, 'idempotency_checked', $operationStartedAt, ['existing_order' => $existingResult !== null]);
    if ($existingResult !== null) return $finishOrder($existingResult);
    $requestFingerprint = storeBridgeOrderRequestFingerprint($remoteProductId, $quantity, $originSiteId, $originUserId, $customerRef);

    if (storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance') === 'reseller_wallet') {
        $billingReady = storeBridgeResellerWalletBillingReadiness(true);
        if (empty($billingReady['ready'])) {
            return $finishOrder([
                'success' => false, 'http_code' => 503,
                'code' => (string) ($billingReady['code'] ?? 'billing_schema_unavailable'),
                'message' => ((string) ($billingReady['code'] ?? '') === 'wallet_ledger_unavailable')
                    ? 'Reseller wallet audit service is temporarily unavailable'
                    : 'Reseller wallet billing is temporarily unavailable',
            ]);
        }
    }
    // Load/migrate CGO support before opening the financial transaction. DDL
    // inside a checkout transaction could auto-commit in MySQL.
    $cgoRuntimeReady = storeBridgeEnsureCgoRuntime();
    storeBridgeTimelineMark($orderTimeline, 'billing_readiness_checked', $operationStartedAt, ['cgo_runtime' => $cgoRuntimeReady]);

    $orderId = 0;
    $fulfillmentSource = 'local';
    $cgoCandidate = null;
    $supplierCandidates = [];
    $failureStage = 'transaction_begin';
    $transactionStarted = false;
    $commitAttempted = false;
    $commitSucceeded = false;
    $localStockLocked = 0;
    $conn->begin_transaction();
    $transactionStarted = true;
    storeBridgeTimelineMark($orderTimeline, 'transaction_started', $operationStartedAt);
    try {
        $failureStage = 'client_lock_prepare';
        $clientLock = $conn->prepare("SELECT * FROM store_api_clients WHERE id=? AND status='active' AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
        if (!$clientLock) throw new RuntimeException('Unable to lock API client');
        $failureStage = 'client_lock_execute';
        $clientLock->bind_param('i', $clientId);
        $clientLock->execute();
        $clientResult = $clientLock->get_result();
        $lockedClient = $clientResult ? $clientResult->fetch_assoc() : null;
        $clientLock->close();
        if (!$lockedClient) { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>403,'code'=>'api_disabled','message'=>'API client is inactive']); }

        $billingMode = storeBridgeNormalizeBillingMode($lockedClient['billing_mode'] ?? 'api_balance');
        $billingUserId = $billingMode === 'reseller_wallet' ? (int) ($lockedClient['linked_user_id'] ?? 0) : 0;
        $balanceBefore = 0.0;
        if ($billingMode === 'reseller_wallet') {
            $billingUser = storeBridgeGetResellerAccount($billingUserId, true);
            if (!$billingUser || (string) ($billingUser['status'] ?? '') !== 'active') { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>403,'code'=>'account_inactive','message'=>'Linked reseller account is inactive']); }
            $balanceBefore = round((float) ($billingUser['balance'] ?? 0), 2);
        } else $balanceBefore = round((float) ($lockedClient['balance'] ?? 0), 2);

        $failureStage = 'product_lookup';
        $product = storeBridgeFindProviderProduct($remoteProductId, $lockedClient, true);
        if (!$product) { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>404,'code'=>'product_not_found','message'=>'Product not found']); }
        $productId = (int) $product['source_product_id'];
        $variantId = (int) $product['source_variant_id'];
        $duration = trim((string) $product['duration']);
        $unitPrice = round((float) $product['unit_price'], 2);
        $total = round($unitPrice * $quantity, 2);
        $maxOrder = round((float) ($lockedClient['max_order_amount'] ?? 0), 2);
        if ($maxOrder > 0 && $total > $maxOrder + 0.00001) { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>403,'code'=>'max_order_amount_exceeded','message'=>'Order amount exceeds the configured per-order limit']); }
        $dailyLimit = round((float) ($lockedClient['daily_spend_limit'] ?? 0), 2);
        if ($dailyLimit > 0) {
            $dailyStmt = $conn->prepare("SELECT COALESCE(SUM(total_price),0) AS spent FROM store_api_orders WHERE client_id=? AND status IN ('processing','success') AND created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)");
            if (!$dailyStmt) throw new RuntimeException('Unable to inspect daily spending');
            $dailyStmt->bind_param('i', $clientId); $dailyStmt->execute();
            $dailyResult = $dailyStmt->get_result(); $dailyRow = $dailyResult ? $dailyResult->fetch_assoc() : null; $dailyStmt->close();
            if (round((float) ($dailyRow['spent'] ?? 0), 2) + $total > $dailyLimit + 0.00001) { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>403,'code'=>'daily_spend_limit_exceeded','message'=>'Daily API spending limit would be exceeded']); }
        }
        if ($balanceBefore + 0.00001 < $total) { $conn->rollback(); return $finishOrder(['success'=>false,'http_code'=>402,'code'=>'insufficient_balance','message'=>$billingMode==='reseller_wallet'?'Insufficient reseller wallet balance':'Insufficient API balance']); }

        $keySql = "SELECT k.id,k.key_code FROM `keys` k WHERE k.product_id=? AND k.status='available'
                   AND (k.variant_id=? OR (k.variant_id IS NULL AND k.duration=?)) ORDER BY k.id ASC LIMIT " . $quantity . " FOR UPDATE";
        $failureStage = 'local_stock_lock_prepare';
        $keyStmt = $conn->prepare($keySql);
        if (!$keyStmt) throw new RuntimeException('Unable to lock product stock');
        $failureStage = 'local_stock_lock_execute';
        $keyStmt->bind_param('iis', $productId, $variantId, $duration);
        if (!$keyStmt->execute()) { $keyStmt->close(); throw new RuntimeException('Unable to lock product stock'); }
        $keyResult = $keyStmt->get_result(); $keys = $keyResult ? $keyResult->fetch_all(MYSQLI_ASSOC) : []; $keyStmt->close();
        $localStockLocked = count($keys);
        if ($localStockLocked === $quantity) {
            $fulfillmentSource = 'local';
            $fulfillmentReason = 'local_stock_sufficient';
            $procurementCost = round(max(0.0, (float) ($product['cost_price'] ?? 0)) * $quantity, 2);
        } else {
            $cgoCandidate = (storeBridgeClientAllowsCgo($lockedClient) && $cgoRuntimeReady)
                ? storeBridgeCgoCandidate($product, $quantity)
                : null;
            if ($cgoCandidate) {
                $fulfillmentSource = 'cgo';
                $fulfillmentReason = 'local_stock_insufficient_cgo_capacity';
                $procurementCost = round((float) ($cgoCandidate['cost_base'] ?? 0) * $quantity, 2);
            } else {
                if ($billingMode === 'reseller_wallet' && $billingUserId > 0) {
                    // The Store API parent is committed before network procurement starts.
                    // Block a second parent for the same reseller/variant while the first
                    // supplier-backed parent is still processing, including the tiny gap
                    // before its supplier child row is created.
                    $parentBlock = $conn->prepare("SELECT id FROM store_api_orders
                        WHERE billing_mode='reseller_wallet' AND billing_user_id=? AND source_variant_id=?
                          AND fulfillment_source='supplier'
                          AND status IN ('processing','pending','manual_review')
                          AND refunded_at IS NULL
                        ORDER BY id DESC LIMIT 1");
                    if (!$parentBlock) throw new RuntimeException('Unable to inspect pending Store API supplier orders');
                    $parentBlock->bind_param('ii', $billingUserId, $variantId);
                    $parentBlock->execute();
                    $parentBlockResult = $parentBlock->get_result();
                    $blockingParent = $parentBlockResult ? $parentBlockResult->fetch_assoc() : null;
                    $parentBlock->close();
                    if ($blockingParent) {
                        $conn->rollback();
                        return $finishOrder([
                            'success'=>false,'http_code'=>409,'code'=>'existing_pending_supplier_order',
                            'message'=>'An earlier Store API supplier order for this variant is still unresolved. Resolve it before creating another order.',
                            'data'=>['order_id'=>(int)($blockingParent['id']??0)],
                        ]);
                    }

                    $blockingSupplier = supplierBridgeFindBlockingPendingOrder($billingUserId, $variantId, false);
                    if ($blockingSupplier) {
                        $conn->rollback();
                        return $finishOrder([
                            'success'=>false,'http_code'=>409,'code'=>'existing_pending_supplier_order',
                            'message'=>'An earlier supplier order for this variant is still unresolved. Resolve it before creating another Store API order.',
                        ]);
                    }
                } elseif ($billingMode === 'api_balance') {
                    // API-credit clients have no linked users row. The client row is the
                    // financial owner, so fence unresolved supplier work by client+variant.
                    $parentBlock = $conn->prepare("SELECT id FROM store_api_orders
                        WHERE client_id=? AND source_variant_id=?
                          AND fulfillment_source='supplier'
                          AND status IN ('processing','pending','manual_review')
                          AND refunded_at IS NULL
                        ORDER BY id DESC LIMIT 1");
                    if (!$parentBlock) throw new RuntimeException('Unable to inspect pending Store API supplier orders');
                    $parentBlock->bind_param('ii', $clientId, $variantId);
                    $parentBlock->execute();
                    $parentBlockResult = $parentBlock->get_result();
                    $blockingParent = $parentBlockResult ? $parentBlockResult->fetch_assoc() : null;
                    $parentBlock->close();
                    if ($blockingParent) {
                        $conn->rollback();
                        return $finishOrder([
                            'success'=>false,'http_code'=>409,'code'=>'existing_pending_supplier_order',
                            'message'=>'An earlier Store API supplier order for this variant is still unresolved. Resolve it before creating another order.',
                            'data'=>['order_id'=>(int)($blockingParent['id']??0)],
                        ]);
                    }
                }
                $supplierCandidates = storeBridgeSupplierCandidatesForClient($lockedClient, $product, $quantity);
                if ($supplierCandidates !== []) {
                    $fulfillmentSource = 'supplier';
                    $fulfillmentReason = 'local_cgo_insufficient_supplier_capacity';
                    $procurementCost = round((float) ($supplierCandidates[0]['cost_base'] ?? 0) * $quantity, 2);
                } else {
                    $conn->rollback();
                    storeBridgeTimelineMark($orderTimeline, 'stock_check_failed', $operationStartedAt, [
                        'local_locked'=>$localStockLocked,'requested'=>$quantity,'cgo_available'=>false,'supplier_available'=>false
                    ]);
                    return $finishOrder(['success'=>false,'http_code'=>409,'code'=>'out_of_stock','message'=>'Not enough stock']);
                }
            }
        }
        storeBridgeTimelineMark($orderTimeline, 'fulfillment_selected', $operationStartedAt, [
            'source'=>$fulfillmentSource,'reason'=>$fulfillmentReason,'local_locked'=>$localStockLocked,
            'cgo_cached_stock'=>(int) ($cgoCandidate['stock'] ?? 0),
            'supplier_candidate_count'=>count($supplierCandidates),
        ]);

        $billingUserValue = $billingUserId > 0 ? $billingUserId : null;
        $failureStage = 'order_insert_prepare';
        $order = $conn->prepare("INSERT INTO store_api_orders
            (client_id,external_ref,remote_product_id,source_product_id,source_variant_id,duration,quantity,unit_price,total_price,status,
             customer_name,customer_email,origin_site_id,origin_user_id,customer_ref,billing_mode,billing_user_id,balance_before,request_fingerprint,
             fulfillment_source,fulfillment_reason,procurement_cost)
            VALUES (?,?,?,?,?,?,?,?,?,'processing',?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$order) throw new RuntimeException('Unable to prepare API order');
        $order->bind_param('issiisiddssssssidsssd',
            $clientId,$externalRef,$remoteProductId,$productId,$variantId,$duration,$quantity,$unitPrice,$total,
            $customerName,$customerEmail,$originSiteId,$originUserId,$customerRef,$billingMode,$billingUserValue,$balanceBefore,$requestFingerprint,
            $fulfillmentSource,$fulfillmentReason,$procurementCost
        );
        $failureStage = 'order_insert_execute';
        if (!$order->execute()) {
            $duplicate = (int) $order->errno === 1062; $order->close(); $conn->rollback();
            if ($duplicate) {
                $existingAfterRace = storeBridgeExistingOrderResult($clientId,$externalRef,$remoteProductId,$quantity,$originSiteId,$originUserId,$customerRef);
                if ($existingAfterRace !== null) return $finishOrder($existingAfterRace);
            }
            throw new RuntimeException('Unable to create API order');
        }
        $orderId = (int) $conn->insert_id; $order->close();
        storeBridgeTimelineMark($orderTimeline, 'order_created', $operationStartedAt, ['order_id'=>$orderId,'source'=>$fulfillmentSource]);

        if ($fulfillmentSource === 'local') {
            $failureStage = 'local_delivery_prepare';
            $sell = $conn->prepare("UPDATE `keys` SET status='sold',assigned_to=NULL,purchased_by=NULL,sold_at=NOW() WHERE id=? AND status='available'");
            $saveKey = $conn->prepare("INSERT INTO store_api_order_keys (order_id,source_type,source_key_id,source_order_id,key_code,key_hash) VALUES (?,'local',?,NULL,?,?)");
            if (!$sell || !$saveKey) throw new RuntimeException('Unable to prepare local key delivery');
            foreach ($keys as $key) {
                $keyId=(int)$key['id']; $keyCode=(string)$key['key_code'];
                $failureStage = 'local_key_mark_sold';
                $sell->bind_param('i',$keyId); if(!$sell->execute()||$sell->affected_rows!==1) throw new RuntimeException('Product stock changed during checkout');
                $failureStage = 'local_key_evidence_insert';
                $keyHash=hash('sha256',$keyCode); $saveKey->bind_param('iiss',$orderId,$keyId,$keyCode,$keyHash); if(!$saveKey->execute()) throw new RuntimeException('Unable to save delivered key');
            }
            $sell->close(); $saveKey->close();
        }

        $balanceAfter = round($balanceBefore - $total, 2);
        $billingTransactionId = null;
        if ($billingMode === 'reseller_wallet') {
            $failureStage = 'reseller_debit_prepare';
            $debit=$conn->prepare("UPDATE users SET balance=balance-? WHERE id=? AND role='reseller' AND status='active' AND balance>=?");
            if(!$debit) throw new RuntimeException('Unable to prepare reseller wallet debit');
            $failureStage = 'reseller_debit_execute';
            $debit->bind_param('did',$total,$billingUserId,$total); if(!$debit->execute()||$debit->affected_rows!==1){$debit->close();throw new RuntimeException('Reseller wallet changed during checkout');} $debit->close();
            $description='Store API reseller wallet order '.$externalRef.' (#'.$orderId.') - '.(string)($product['name']??'product').' x'.$quantity;
            $failureStage = 'reseller_transaction_insert';
            $billingTransactionId=(int)createTransaction($billingUserId,'store_api_purchase',$total,$fulfillmentSource==='local'?'completed':'pending',$description,$orderId);
            if($billingTransactionId<1) throw new RuntimeException('Unable to create reseller Store API transaction');
            $failureStage = 'reseller_wallet_ledger';
            if(!walletLedgerRecordMovement($billingUserId,-$total,$balanceBefore,$balanceAfter,'store_api_purchase','store_api_order:'.$orderId,$orderId,$billingTransactionId,null,
                'ซื้อสินค้าผ่าน Store API '.$externalRef,'Reseller wallet debit for Store API order #'.$orderId.'; client #'.$clientId.'.',$externalRef,true)) throw new RuntimeException('Unable to write reseller wallet audit');
        } else {
            $failureStage = 'api_balance_debit_prepare';
            $debit=$conn->prepare('UPDATE store_api_clients SET balance=balance-? WHERE id=? AND balance>=?');
            if(!$debit) throw new RuntimeException('Unable to prepare API balance debit');
            $failureStage = 'api_balance_debit_execute';
            $debit->bind_param('did',$total,$clientId,$total); if(!$debit->execute()||$debit->affected_rows!==1){$debit->close();throw new RuntimeException('API balance changed during checkout');} $debit->close();
            $negative=-$total; $note='Order '.$externalRef;
            $failureStage = 'api_balance_ledger_prepare';
            $ledger=$conn->prepare("INSERT INTO store_api_balance_ledger (client_id,order_id,entry_type,amount,balance_after,note) VALUES (?,?,'order_debit',?,?,?)");
            if(!$ledger) throw new RuntimeException('Unable to prepare API balance ledger');
            $failureStage = 'api_balance_ledger_execute';
            $ledger->bind_param('iidds',$clientId,$orderId,$negative,$balanceAfter,$note); if(!$ledger->execute()){$ledger->close();throw new RuntimeException('Unable to save API balance ledger');} $ledger->close();
        }
        $txValue=$billingTransactionId===null?0:(int)$billingTransactionId;
        if ($fulfillmentSource === 'local') {
            $complete=$conn->prepare("UPDATE store_api_orders SET status='success',billing_transaction_id=NULLIF(?,0),balance_after=?,upstream_status='local',completed_at=NOW() WHERE id=?");
        } else {
            $complete=$conn->prepare("UPDATE store_api_orders SET status='processing',billing_transaction_id=NULLIF(?,0),balance_after=?,upstream_status='queued',completed_at=NULL WHERE id=?");
        }
        $failureStage = 'order_finalize_prepare';
        if(!$complete) throw new RuntimeException('Unable to update Store API order billing state');
        $failureStage = 'order_finalize_execute';
        $complete->bind_param('idi',$txValue,$balanceAfter,$orderId); if(!$complete->execute()){$complete->close();throw new RuntimeException('Unable to save Store API order billing state');} $complete->close();
        $failureStage = 'transaction_commit';
        $commitAttempted = true;
        if (!$conn->commit()) throw new RuntimeException('Unable to commit Store API order transaction');
        $commitSucceeded = true;
        storeBridgeTimelineMark($orderTimeline, 'transaction_committed', $operationStartedAt, ['order_id'=>$orderId,'source'=>$fulfillmentSource]);
    } catch (Throwable $e) {
        $dbErrno = isset($conn) && $conn instanceof mysqli ? (int) $conn->errno : 0;
        $dbErrorRaw = isset($conn) && $conn instanceof mysqli ? trim((string) $conn->error) : '';
        $rollbackAttempted = $transactionStarted && !$commitSucceeded;
        $rollbackSucceeded = false;
        if ($rollbackAttempted) {
            try { $rollbackSucceeded = (bool) $conn->rollback(); } catch (Throwable $ignored) { $rollbackSucceeded = false; }
        }
        $dbCode = $dbErrno !== 0 ? $dbErrno : (int) $e->getCode();
        $message = strtolower($e->getMessage() . ' ' . $dbErrorRaw);
        $retryable = in_array($dbCode,[1205,1213],true)||strpos($message,'deadlock')!==false||strpos($message,'lock wait timeout')!==false;
        $commitUncertain = $commitAttempted && !$commitSucceeded;
        $errorCode = $commitUncertain ? 'order_commit_uncertain' : ($retryable ? 'temporary_database_conflict' : 'order_not_created');
        $failureClass = $commitUncertain ? 'database_commit_uncertain' : (strpos($message,'unknown column') !== false ? 'database_schema' : 'provider_order_exception');
        $diag=[
            'failure_class'=>$failureClass,
            'failure_stage'=>$failureStage,
            'error_code'=>$errorCode,
            'db_code'=>$dbCode,
            'db_error'=>storeBridgeDiagnosticSanitizeMessage($dbErrorRaw,1000),
            'retryable'=>$retryable,
            'transaction_started'=>$transactionStarted,
            'commit_attempted'=>$commitAttempted,
            'commit_succeeded'=>$commitSucceeded,
            'rollback_attempted'=>$rollbackAttempted,
            'rollback_succeeded'=>$rollbackSucceeded,
            'allocated_order_id'=>$orderId,
            'local_stock_locked'=>$localStockLocked,
            'fulfillment_source'=>$fulfillmentSource,
            'order_created'=>!$commitUncertain ? false : null,
            'error_message'=>storeBridgeDiagnosticSanitizeMessage($e->getMessage(),1000),
            'error_sha256'=>hash('sha256',$e->getMessage().'|'.$dbErrorRaw),
        ];
        storeBridgeTimelineMark($orderTimeline, $commitUncertain ? 'transaction_commit_uncertain' : 'transaction_rolled_back', $operationStartedAt, [
            'stage'=>$failureStage,'db_code'=>$dbCode,'rollback'=>$rollbackSucceeded,'order_id'=>$orderId,
        ]);
        error_log('Store API order failed client_id='.$clientId.' external_ref='.$externalRef.' stage='.$failureStage.' db_code='.$dbCode.': '.storeBridgeDiagnosticSanitizeMessage($e->getMessage().' '.$dbErrorRaw,1000));
        if ($commitUncertain) {
            return $finishOrder([
                'success'=>false,'http_code'=>503,'code'=>'order_commit_uncertain',
                'message'=>'Order commit state is uncertain. Check order_status with the same external_ref and do not create a duplicate order.',
                'diagnostic'=>$diag,
            ]);
        }
        return $finishOrder([
            'success'=>false,'http_code'=>$retryable?503:500,'code'=>$errorCode,
            'message'=>$retryable?'Order transaction was rolled back because of a temporary database conflict':'Order was not created; the transaction was rolled back',
            'order_created'=>false,'definitive_failure'=>true,'diagnostic'=>$diag,
        ]);
    }

    if ($fulfillmentSource === 'local') {
        commerceCenterSyncSafe('store_api_sale',$orderId);
        return $finishOrder(['success'=>true,'http_code'=>200,'data'=>storeBridgeProviderOrderPayload($orderId)]);
    }

    if ($fulfillmentSource === 'supplier') {
        // The Store API parent already owns the client debit and audit ledger.
        // Supplier Bridge is invoked in procurement-only mode so it can preserve
        // inventory verification, cost guards, protected-provider manual review,
        // and failover without creating a second financial transaction.
        $lastSupplierResult = [];
        $attemptedSupplierIds = [];
        foreach ($supplierCandidates as $candidate) {
            $candidateProductId = (int) ($candidate['id'] ?? 0);
            if ($candidateProductId < 1) continue;
            $attemptedSupplierIds[] = $candidateProductId;
            storeBridgeTimelineMark($orderTimeline, 'supplier_procurement_started', $operationStartedAt, [
                'order_id'=>$orderId,
                'supplier_product_id'=>$candidateProductId,
                'connection_id'=>(int)($candidate['connection_id']??0),
            ]);
            try {
                $supplierResult = supplierBridgePurchase(
                    $candidateProductId,
                    $billingUserId,
                    $quantity,
                    $productId,
                    $variantId,
                    false,
                    true,
                    'store_api',
                    $orderId,
                    $unitPrice
                );
            } catch (Throwable $e) {
                $safe = storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000);
                error_log('Store API supplier procurement exception order_id='.$orderId.' supplier_product_id='.$candidateProductId.': '.$safe);
                $supplierResult = [
                    'success'=>false,
                    'pending'=>true,
                    'safe_to_failover'=>false,
                    'code'=>'procurement_exception',
                    'message'=>'Supplier procurement state requires reconciliation',
                ];
            }
            $lastSupplierResult = $supplierResult;
            $supplierOrderId = (int) ($supplierResult['order_id'] ?? 0);

            if ($supplierOrderId > 0) {
                $snapshot = storeBridgeSupplierOrderSnapshot($supplierOrderId);
                $belongsToParent = $snapshot
                    && (string)($snapshot['source_kind']??'') === 'store_api'
                    && (int)($snapshot['source_order_id']??0) === $orderId;
                if ($belongsToParent) {
                    if (empty($supplierResult['success']) && !empty($supplierResult['safe_to_failover'])) {
                        // This child definitively failed before delivery and is already
                        // terminal/refunded in procurement-only mode. Do not apply that
                        // child to the parent because doing so would refund the parent
                        // before the next permitted supplier candidate is attempted.
                        continue;
                    }
                    storeBridgeUpdateSupplierOrderLink($orderId, $supplierOrderId);
                    $applied = storeBridgeApplySupplierOrderState($orderId, $supplierOrderId);
                    storeBridgeTimelineMark($orderTimeline, 'supplier_procurement_state_applied', $operationStartedAt, [
                        'supplier_order_id'=>$supplierOrderId,
                        'pending'=>!empty($applied['pending']),
                        'refunded'=>!empty($applied['refunded']),
                    ]);

                    if (!empty($applied['success']) && empty($applied['pending'])) {
                        return $finishOrder([
                            'success'=>true,'http_code'=>200,
                            'data'=>storeBridgeProviderOrderPayload($orderId),
                            'diagnostic'=>['supplier_result_code'=>substr((string)($supplierResult['code']??'success'),0,80)],
                        ]);
                    }
                    if (!empty($applied['pending']) || empty($supplierResult['safe_to_failover'])) {
                        return $finishOrder([
                            'success'=>true,'http_code'=>202,'code'=>'processing',
                            'message'=>'Order is processing; check order_status with the same reference and do not create a duplicate order.',
                            'data'=>storeBridgeProviderOrderPayload($orderId),
                            'diagnostic'=>['supplier_result_code'=>substr((string)($supplierResult['code']??''),0,80)],
                        ]);
                    }
                    // A definitive child failure in procurement-only mode marks
                    // only that child refunded. Parent billing remains reserved
                    // while another permitted supplier candidate is tried.
                } elseif (!empty($supplierResult['pending']) || empty($supplierResult['safe_to_failover'])) {
                    $reason = 'Supplier procurement returned an order that could not be linked safely to the Store API parent';
                    $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='manual_review',error_message=? WHERE id=?");
                    if ($u) { $u->bind_param('si',$reason,$orderId); $u->execute(); $u->close(); }
                    return $finishOrder([
                        'success'=>true,'http_code'=>202,'code'=>'processing',
                        'message'=>'Supplier procurement requires reconciliation. Do not create a duplicate order.',
                        'data'=>storeBridgeProviderOrderPayload($orderId),
                    ]);
                }
            } elseif (!empty($supplierResult['pending']) || empty($supplierResult['safe_to_failover'])) {
                $reason = storeBridgeDiagnosticSanitizeMessage((string)($supplierResult['message']??'Supplier procurement requires reconciliation'),1000);
                $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='manual_review',error_message=? WHERE id=?");
                if ($u) { $u->bind_param('si',$reason,$orderId); $u->execute(); $u->close(); }
                return $finishOrder([
                    'success'=>true,'http_code'=>202,'code'=>'processing',
                    'message'=>'Supplier procurement requires reconciliation. Do not create a duplicate order.',
                    'data'=>storeBridgeProviderOrderPayload($orderId),
                    'diagnostic'=>['supplier_result_code'=>substr((string)($supplierResult['code']??''),0,80)],
                ]);
            }
            // safe_to_failover=true means this candidate proved no upstream
            // delivery exists and its child (if any) is already terminal.
        }

        $reason = storeBridgeDiagnosticSanitizeMessage(
            (string)($lastSupplierResult['message'] ?? 'No permitted supplier could fulfill this order'),
            1000
        );
        $refund = storeBridgeRefundProviderOrder(
            $orderId,
            $reason,
            substr((string)($lastSupplierResult['code'] ?? 'supplier_candidates_exhausted'),0,40)
        );
        if (!empty($refund['success'])) {
            return $finishOrder([
                'success'=>false,'http_code'=>409,'code'=>'upstream_unavailable_refunded',
                'message'=>'No permitted supplier could fulfill the order; Store API balance was refunded.',
                'data'=>storeBridgeProviderOrderPayload($orderId),
                'diagnostic'=>[
                    'supplier_result_code'=>substr((string)($lastSupplierResult['code']??''),0,80),
                    'supplier_products_attempted'=>$attemptedSupplierIds,
                ],
            ]);
        }
        $u = $conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='refund_pending',error_message=? WHERE id=?");
        if ($u) { $u->bind_param('si',$reason,$orderId); $u->execute(); $u->close(); }
        return $finishOrder([
            'success'=>true,'http_code'=>202,'code'=>'refund_pending',
            'message'=>'Supplier procurement failed safely, but the Store API refund is still being reconciled. Do not create a duplicate order.',
            'data'=>storeBridgeProviderOrderPayload($orderId),
            'diagnostic'=>['supplier_products_attempted'=>$attemptedSupplierIds],
        ]);
    }

    // Network procurement happens only after the Store API debit/order reserve
    // is durably committed. CGO external mode never performs a second debit.
    storeBridgeTimelineMark($orderTimeline, 'cgo_procurement_started', $operationStartedAt, ['order_id'=>$orderId,'cgo_product_id'=>(int)($cgoCandidate['cgo_product_id']??0)]);
    try {
        $cgoResult = cgoProcureProductForStoreApi(
            (int) ($cgoCandidate['cgo_product_id'] ?? 0), $orderId, $quantity, $productId, $variantId, $unitPrice, $customerName, $customerEmail
        );
    } catch (Throwable $e) {
        $safe = storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000);
        error_log('Store API CGO procurement exception order_id='.$orderId.': '.$safe);
        $cgoResult = ['success'=>false,'pending'=>true,'code'=>'procurement_exception','message'=>'CGO procurement state requires reconciliation'];
    }
    $cgoOrderId = (int) ($cgoResult['order_id'] ?? 0);
    if (!empty($cgoResult['safe_to_release_parent']) && empty($cgoResult['supplier_submit_attempted'])) {
        $reason = storeBridgeDiagnosticSanitizeMessage((string) ($cgoResult['message'] ?? 'CGO procurement did not reach the supplier'), 1000);
        $refund = storeBridgeRefundProviderOrder($orderId, $reason, substr((string) ($cgoResult['code'] ?? 'pre_submit_release'), 0, 40));
        if (!empty($refund['success'])) {
            return $finishOrder([
                'success'=>false,'http_code'=>409,'code'=>'upstream_not_submitted_refunded',
                'message'=>'Supplier procurement was not submitted. Store API balance was refunded safely.',
                'data'=>storeBridgeProviderOrderPayload($orderId),
                'diagnostic'=>['cgo_result_code'=>substr((string)($cgoResult['code']??''),0,80)],
            ]);
        }
        return $finishOrder([
            'success'=>true,'http_code'=>202,'code'=>'refund_pending',
            'message'=>'Supplier procurement was not submitted, but the local refund is still being reconciled. Do not submit a duplicate order.',
            'data'=>storeBridgeProviderOrderPayload($orderId),
            'diagnostic'=>['cgo_result_code'=>substr((string)($cgoResult['code']??''),0,80)],
        ]);
    }
    if ($cgoOrderId < 1 && storeBridgeEnsureCgoRuntime()) {
        $find=$conn->prepare("SELECT id FROM cgo_orders WHERE source_kind='store_api' AND source_order_id=? ORDER BY id DESC LIMIT 1");
        if($find){$find->bind_param('i',$orderId);$find->execute();$fr=$find->get_result();$f=$fr?$fr->fetch_assoc():null;$find->close();$cgoOrderId=(int)($f['id']??0);}
    }
    if ($cgoOrderId > 0) {
        storeBridgeUpdateCgoOrderLink($orderId,$cgoOrderId);
        $applied=storeBridgeApplyCgoOrderState($orderId,$cgoOrderId);
        storeBridgeTimelineMark($orderTimeline, 'cgo_procurement_state_applied', $operationStartedAt, ['cgo_order_id'=>$cgoOrderId,'pending'=>!empty($applied['pending'])]);
        if (!empty($applied['success']) && empty($applied['pending'])) return $finishOrder(['success'=>true,'http_code'=>200,'data'=>storeBridgeProviderOrderPayload($orderId)]);
        if (!empty($applied['pending'])) return $finishOrder(['success'=>true,'http_code'=>202,'code'=>'processing','message'=>'Order is processing; check order_status with the same reference','data'=>storeBridgeProviderOrderPayload($orderId),
            'diagnostic'=>['cgo_result_code'=>substr((string)($cgoResult['code']??''),0,80)]]);
        return $finishOrder(['success'=>false,'http_code'=>409,'code'=>(string)($applied['code']??'upstream_failed_refunded'),'message'=>(string)($applied['message']??'Upstream fulfillment failed'),'data'=>storeBridgeProviderOrderPayload($orderId)]);
    }

    // No CGO order row means the supplier submit never acquired a durable local
    // upstream order (e.g. lock busy or fresh out-of-stock guard). Refund the
    // Store API reserve immediately. If refund itself fails, preserve processing
    // state and instruct same-ref polling instead of risking a duplicate debit.
    $reason=storeBridgeDiagnosticSanitizeMessage((string)($cgoResult['message']??'CGO procurement did not start'),1000);
    $refund=storeBridgeRefundProviderOrder($orderId,$reason,substr((string)($cgoResult['code']??'not_created'),0,40));
    if (!empty($refund['success'])) {
        return $finishOrder(['success'=>false,'http_code'=>409,'code'=>'upstream_unavailable_refunded','message'=>'Supplier could not fulfill the order; Store API balance was refunded','data'=>storeBridgeProviderOrderPayload($orderId),
            'diagnostic'=>['cgo_result_code'=>substr((string)($cgoResult['code']??''),0,80)]]);
    }
    $u=$conn->prepare("UPDATE store_api_orders SET status='processing',upstream_status='refund_pending',error_message=? WHERE id=?");
    if($u){$u->bind_param('si',$reason,$orderId);$u->execute();$u->close();}
    return $finishOrder(['success'=>true,'http_code'=>202,'code'=>'refund_pending','message'=>'Order state is being reconciled; do not submit a duplicate order','data'=>storeBridgeProviderOrderPayload($orderId)]);
}

function storeBridgeProviderOrderStatus(array $client, array $payload): array
{
    global $conn;
    $clientId = (int) ($client['id'] ?? 0);
    $externalRef = trim((string) ($payload['external_ref'] ?? ''));
    $orderIdText = trim((string) ($payload['order_id'] ?? ''));
    if ($clientId < 1 || ($externalRef === '' && $orderIdText === '')) return ['success'=>false,'http_code'=>422,'code'=>'invalid_request','message'=>'Order reference is required'];
    if ($orderIdText !== '') {
        if (!ctype_digit($orderIdText) || (int)$orderIdText < 1) return ['success'=>false,'http_code'=>422,'code'=>'invalid_order_id','message'=>'order_id must be a positive integer'];
        $orderId=(int)$orderIdText;
        $stmt=$conn->prepare('SELECT id,status,fulfillment_source FROM store_api_orders WHERE id=? AND client_id=? LIMIT 1');
        if($stmt)$stmt->bind_param('ii',$orderId,$clientId);
    } else {
        if (preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$externalRef)!==1) return ['success'=>false,'http_code'=>422,'code'=>'invalid_external_ref','message'=>'external_ref is invalid'];
        $stmt=$conn->prepare('SELECT id,status,fulfillment_source FROM store_api_orders WHERE external_ref=? AND client_id=? LIMIT 1');
        if($stmt)$stmt->bind_param('si',$externalRef,$clientId);
    }
    if(!$stmt)return ['success'=>false,'http_code'=>503,'code'=>'order_lookup_unavailable','message'=>'Unable to load order'];
    $stmt->execute();$result=$stmt->get_result();$row=$result?$result->fetch_assoc():null;$stmt->close();
    if(!$row)return ['success'=>false,'http_code'=>404,'code'=>'order_not_found','message'=>'Order not found'];
    $resolvedOrderId=(int)$row['id'];
    $status=strtolower(trim((string)($row['status']??'')));
    $statusSource = strtolower(trim((string)($row['fulfillment_source']??'')));
    if($statusSource==='cgo' && in_array($status,['processing','pending','manual_review'],true)) {
        storeBridgeReconcileCgoProviderOrder($resolvedOrderId,false);
    } elseif($statusSource==='supplier' && in_array($status,['processing','pending','manual_review'],true)) {
        storeBridgeReconcileSupplierProviderOrder($resolvedOrderId,false);
    }
    commerceCenterSyncSafe('store_api_sale',$resolvedOrderId);
    return ['success'=>true,'http_code'=>200,'data'=>storeBridgeProviderOrderPayload($resolvedOrderId)];
}

function storeBridgeDevnoodLatestProbeForClient(int $clientId): ?array
{
    global $conn;
    if ($clientId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare('SELECT * FROM store_api_diagnostic_probes WHERE client_id=? ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $clientId);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function storeBridgeDevnoodDiagnosticPayload(array $client): array
{
    $clientId = (int) ($client['id'] ?? 0);
    $allowedIps = preg_split('/[\s,;]+/', trim((string) ($client['allowed_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $latestProbe = storeBridgeDevnoodLatestProbeForClient($clientId);
    $proxyRules = function_exists('trustedProxyRules') ? trustedProxyRules() : [];
    $proxySource = function_exists('trustedProxyConfigurationSource') ? trustedProxyConfigurationSource() : 'none';
    $maskedKey = trim((string) ($client['key_prefix'] ?? '')) . '••••' . trim((string) ($client['key_last4'] ?? ''));
    $remoteAddr = substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    $detectedIp = function_exists('getClientIp') ? substr(trim((string) getClientIp()), 0, 64) : $remoteAddr;
    $trusted = function_exists('isTrustedProxyAddress') && isTrustedProxyAddress($remoteAddr);
    $recentRequests = $clientId > 0 ? storeBridgeGetRecentRequestLogs(10, $clientId) : [];
    $recentProbeHits = $clientId > 0 ? storeBridgeGetDiagnosticProbeLogs(10, $clientId) : [];

    return [
        'success' => true,
        'store_api_version' => defined('STORE_BRIDGE_VERSION') ? STORE_BRIDGE_VERSION : '2.2',
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'endpoint' => supplierBridgeCurrentEndpoint(),
        'ping_endpoint' => storeBridgeEndpointSibling('ping.php'),
        'client' => [
            'id' => $clientId,
            'website_name' => (string) ($client['website_name'] ?? ''),
            'billing_mode' => storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance'),
            'status' => (string) ($client['status'] ?? ''),
            'api_key_masked' => $maskedKey,
            'allowed_ips' => array_values(array_map('strval', $allowedIps)),
            'webhook_configured' => trim((string) ($client['webhook_url'] ?? '')) !== '',
            'webhook_last_http_code' => isset($client['webhook_last_http_code']) ? (int) $client['webhook_last_http_code'] : null,
        ],
        'provider_proxy_detection' => [
            'source' => $proxySource,
            'trusted_rule_count' => count($proxyRules),
        ],
        'current_request_observation' => [
            'remote_addr' => $remoteAddr,
            'detected_client_ip' => $detectedIp,
            'remote_addr_is_trusted_proxy' => $trusted,
            'cf_connecting_ip' => $trusted ? substr(trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')), 0, 64) : '',
            'x_forwarded_for' => $trusted ? substr(trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')), 0, 500) : '',
            'cf_ray' => substr(trim((string) ($_SERVER['HTTP_CF_RAY'] ?? '')), 0, 100),
        ],
        'latest_backend_probe' => $latestProbe ? [
            'status' => (string) ($latestProbe['status'] ?? ''),
            'received_at' => (string) ($latestProbe['received_at'] ?? ''),
            'remote_addr' => (string) ($latestProbe['remote_addr'] ?? ''),
            'detected_client_ip' => (string) ($latestProbe['detected_client_ip'] ?? ''),
            'trusted_proxy' => !empty($latestProbe['trusted_proxy']),
            'ip_allowlist_match' => !empty($latestProbe['ip_allowed']),
            'cf_ray' => (string) ($latestProbe['cf_ray'] ?? ''),
        ] : null,
        'recent_probe_hits' => $recentProbeHits,
        'recent_application_requests' => $recentRequests,
        'integration' => 'devnood_shop',
        'note' => 'No plaintext API key, Authorization header, cookies, customer identity or delivered key material is included.',
    ];
}

function storeBridgeDevnoodReadJsonBody(?string &$errorCode = null, ?string &$errorMessage = null): array
{
    $errorCode = '';
    $errorMessage = '';
    $limit = 1024 * 1024;
    $declaredLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
        ? max(0, (int) $_SERVER['CONTENT_LENGTH']) : 0;
    if ($declaredLength > $limit) {
        $errorCode = 'request_body_too_large';
        $errorMessage = 'Request body is too large';
        return [];
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    // Let PHP handle normal form submissions. The fallback Store API parser is
    // intentionally strict only for JSON-like server-to-server payloads.
    if ($contentType !== '' && $contentType !== 'application/json' && substr($contentType, -5) !== '+json') return [];

    $raw = @file_get_contents('php://input');
    if (!is_string($raw)) {
        $errorCode = 'invalid_body';
        $errorMessage = 'Unable to read request body';
        return [];
    }
    if (trim($raw) === '') return [];
    if (strlen($raw) > $limit) {
        $errorCode = 'request_body_too_large';
        $errorMessage = 'Request body is too large';
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        $errorCode = 'invalid_json';
        $errorMessage = 'Request body must be valid JSON';
        return [];
    }
    return $decoded;
}

function storeBridgeDevnoodApiResult(array $client, string $action, array $payload): array
{
    $action = strtolower(trim($action));
    if ($action === 'diagnostic') {
        return ['success' => true, 'http_code' => 200, 'data' => storeBridgeDevnoodDiagnosticPayload($client), 'passthrough' => true];
    }
    if ($action === 'balance') {
        $balance = storeBridgeClientCurrentBalance($client, false);
        if ($balance === null) return ['success' => false, 'http_code' => 503, 'code' => 'balance_unavailable', 'message' => 'Balance is unavailable'];
        $currency = (string) ($client['currency'] ?? storeBridgeCurrency());
        $billingMode = storeBridgeNormalizeBillingMode($client['billing_mode'] ?? 'api_balance');
        return [
            'success' => true,
            'http_code' => 200,
            'data' => [
                'success' => true,
                'balance' => $balance,
                'available_balance' => $balance,
                'currency' => $currency,
                'billing_mode' => $billingMode,
                'integration' => 'devnood_shop',
            ],
            'passthrough' => true,
        ];
    }
    if ($action === 'products') {
        try { $products = storeBridgeCatalogue($client); }
        catch (Throwable $e) {
            error_log('Store API catalogue failed: ' . $e->getMessage());
            return ['success' => false, 'http_code' => 503, 'code' => 'products_unavailable', 'message' => 'Product catalogue is unavailable'];
        }
        return [
            'success' => true,
            'http_code' => 200,
            'data' => [
                'success' => true,
                'products' => $products,
                'items' => $products,
                'count' => count($products),
                'currency' => (string) ($client['currency'] ?? storeBridgeCurrency()),
                'integration' => 'devnood_shop',
            ],
            'passthrough' => true,
        ];
    }
    if ($action === 'inventory') {
        $productId = trim((string) ($payload['product_id'] ?? ''));
        if ($productId === '') return ['success' => false, 'http_code' => 422, 'code' => 'product_id_required', 'message' => 'product_id is required'];
        return storeBridgeProviderInventory($client, $productId);
    }
    if ($action === 'order_status') {
        return storeBridgeProviderOrderStatus($client, $payload);
    }
    if ($action === 'order') {
        return storeBridgeCreateProviderOrder($client, $payload);
    }
    return ['success' => false, 'http_code' => 404, 'code' => 'unknown_action', 'message' => 'Unknown Store API action'];
}

function storeBridgeDevnoodServeApi(): void
{
    $startedAt = microtime(true);
    $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    $requestTimeline = [];
    storeBridgeTimelineMark($requestTimeline, 'request_received', $startedAt, ['method' => $method]);
    $requestId = '';
    try { $requestId = 'dev_' . bin2hex(random_bytes(10)); }
    catch (Throwable $e) { $requestId = 'dev_' . substr(hash('sha256', uniqid('', true)), 0, 20); }
    if (!headers_sent()) header('X-Request-ID: ' . $requestId);

    $payload = [];
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    $emit = static function (array $body, int $status, ?int $clientId, string $logAction, string $ip, ?array $exception = null, ?array $operationDiagnostic = null) use ($requestId, $method, &$payload, &$requestTimeline, $startedAt): void {
        if (!isset($body['request_id'])) $body['request_id'] = $requestId;
        $resultCode = isset($body['code']) && is_scalar($body['code'])
            ? substr(trim((string) $body['code']), 0, 80)
            : ($status < 400 ? 'ok' : 'http_' . $status);
        storeBridgeTimelineMark($requestTimeline, 'response_ready', $startedAt, ['http_code' => $status, 'result_code' => $resultCode]);
        $context = [
            'result_code' => $resultCode,
            'stage' => storeBridgeRequestDiagnosticStage($logAction, $status, $resultCode),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request_payload' => $payload,
            'response_payload' => $body,
            'timeline' => $requestTimeline,
        ];
        if (is_array($exception)) $context['exception'] = $exception;
        if (is_array($operationDiagnostic)) $context['operation_diagnostic'] = $operationDiagnostic;
        storeBridgeLogRequest($clientId, $requestId, $logAction, $ip, $method, $status, $context);
        storeBridgeDevnoodEmitJson($body, $status);
    };

    // Authenticate before reading a potentially large request body. This keeps
    // invalid credentials from consuming the full JSON parsing budget.
    storeBridgeTimelineMark($requestTimeline, 'authentication_started', $startedAt);
    $auth = storeBridgeAuthenticateRequest();
    if (empty($auth['success'])) {
        $status = (int) ($auth['http_code'] ?? 401);
        $authCode = (string) ($auth['code'] ?? 'authentication_failed');
        storeBridgeTimelineMark($requestTimeline, 'authentication_failed', $startedAt, ['code' => $authCode, 'client_id' => (int) ($auth['client_id'] ?? 0)]);
        $remoteIp = function_exists('getClientIp') ? getClientIp() : trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $emit([
            'success' => false,
            'code' => $authCode,
            'message' => (string) ($auth['message'] ?? 'Authentication failed'),
        ], $status, isset($auth['client_id']) ? (int) $auth['client_id'] : null, $action !== '' ? $action : 'auth', $remoteIp);
    }

    storeBridgeTimelineMark($requestTimeline, 'authentication_completed', $startedAt, ['client_id' => (int) (($auth['client']['id'] ?? 0))]);
    $client = (array) ($auth['client'] ?? []);
    $clientId = (int) ($client['id'] ?? 0);
    $ip = (string) ($auth['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

    $bodyErrorCode = '';
    $bodyErrorMessage = '';
    // GET inventory/order_status depend on query parameters. Preserve query
    // values, then merge form/JSON body data for POST so this fallback behaves
    // like /api/store/v1.php instead of silently dropping product/order IDs.
    $payload = is_array($_GET) ? $_GET : [];
    if ($method === 'POST') {
        $jsonPayload = storeBridgeDevnoodReadJsonBody($bodyErrorCode, $bodyErrorMessage);
        if ($_POST) $payload = array_merge($payload, $_POST);
        if ($jsonPayload) $payload = array_merge($payload, $jsonPayload);
    }
    $action = strtolower(trim((string) ($_GET['action'] ?? ($payload['action'] ?? ''))));
    storeBridgeTimelineMark($requestTimeline, 'request_parsed', $startedAt, ['action' => $action !== '' ? $action : 'unknown']);
    if ($bodyErrorCode !== '') {
        $emit([
            'success' => false,
            'code' => $bodyErrorCode,
            'message' => $bodyErrorMessage !== '' ? $bodyErrorMessage : 'Invalid request body',
        ], $bodyErrorCode === 'request_body_too_large' ? 413 : 400, $clientId, $action !== '' ? $action : 'invalid_body', $ip);
    }

    if ($action === '') {
        $emit(['success' => false, 'code' => 'action_required', 'message' => 'action is required'], 422, $clientId, 'unknown', $ip);
    }
    if ($action === 'order' && $method !== 'POST') {
        $emit(['success' => false, 'code' => 'method_not_allowed', 'message' => 'order requires POST'], 405, $clientId, $action, $ip);
    }
    if ($action !== 'order' && !in_array($method, ['GET', 'POST'], true)) {
        $emit(['success' => false, 'code' => 'method_not_allowed', 'message' => 'Method not allowed'], 405, $clientId, $action, $ip);
    }

    $rate = storeBridgeCheckActionRateLimit($client, $action);
    if (empty($rate['success'])) {
        $status = (int) ($rate['http_code'] ?? 429);
        storeBridgeTimelineMark($requestTimeline, 'rate_limit_rejected', $startedAt, ['action' => $action]);
        $emit([
            'success' => false,
            'code' => (string) ($rate['code'] ?? 'rate_limited'),
            'message' => (string) ($rate['message'] ?? 'Rate limit exceeded'),
        ], $status, $clientId, $action, $ip);
    }

    storeBridgeTimelineMark($requestTimeline, 'rate_limit_checked', $startedAt, ['action' => $action]);
    storeBridgeTimelineMark($requestTimeline, 'action_handler_started', $startedAt, ['action' => $action]);
    try {
        $result = storeBridgeDevnoodApiResult($client, $action, $payload);
        storeBridgeTimelineMark($requestTimeline, 'action_handler_completed', $startedAt, ['action' => $action, 'success' => !empty($result['success'])]);
    } catch (Throwable $e) {
        error_log('Store API DEVNOOD fallback exception request_id=' . $requestId . ' action=' . $action . ': ' . storeBridgeDiagnosticSanitizeMessage($e->getMessage(), 1000));
        $emit([
            'success' => false,
            'code' => 'unhandled_exception',
            'message' => 'Store API request failed unexpectedly',
        ], 500, $clientId, $action, $ip, ['class' => get_class($e), 'message' => $e->getMessage()]);
    }
    $status = max(100, min(599, (int) ($result['http_code'] ?? (!empty($result['success']) ? 200 : 400))));
    $operationDiagnostic = isset($result['diagnostic']) && is_array($result['diagnostic']) ? $result['diagnostic'] : null;
    $body = !empty($result['passthrough']) && isset($result['data']) && is_array($result['data'])
        ? $result['data']
        : $result;
    unset($body['http_code'], $body['passthrough'], $body['diagnostic']);
    $emit($body, $status, $clientId, $action, $ip, null, $operationDiagnostic);
}

function storeBridgeDevnoodEmitJson(array $body, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Store-API-Version: ' . (defined('STORE_BRIDGE_VERSION') ? STORE_BRIDGE_VERSION : '2.2'));
        header('X-Content-Type-Options: nosniff');
    }
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) $json = '{"success":false,"code":"json_encode_failed","message":"Unable to encode API response"}';
    echo $json;
    exit;
}

function supplierBridgeProviderTypes(): array
{
    // New supplier protocols are registered here so storefront, checkout, and
    // history pages remain untouched. Each protocol adapter belongs in this
    // single integration file instead of being copied across both websites.
    return [
        'sakazuki_v1' => [
            'label_th' => 'Sakazuki Store API v1',
            'label_en' => 'Sakazuki Store API v1',
        ],
        'vipstore_v1' => [
            'label_th' => 'VIPSTORE v1',
            'label_en' => 'VIPSTORE v1',
        ],
        'starkmods_v1' => [
            'label_th' => 'StarkMods Web Session v1',
            'label_en' => 'StarkMods Web Session v1',
        ],
    ];
}

function supplierBridgeProviderRequiresProtectedPurchase(string $providerType): bool
{
    return in_array(strtolower(trim($providerType)), ['vipstore_v1', 'starkmods_v1'], true);
}

function storeBridgeCurrentBaseUrl(): string
{
    // Prefer the current validated host so the same project can be uploaded to
    // both branded websites without editing a hard-coded domain.
    $host = isset($_SERVER['HTTP_HOST']) && is_scalar($_SERVER['HTTP_HOST'])
        ? strtolower(trim((string) $_SERVER['HTTP_HOST'])) : '';
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    if ($host !== '' && preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) === 1) {
        return 'https://' . $host;
    }
    return normalizeCanonicalBaseUrl((string) getSetting('site_base_url', ''));
}

function supplierBridgeCurrentEndpoint(): string
{
    $base = storeBridgeCurrentBaseUrl();
    return $base !== '' ? $base . '/api/store/v1.php' : '/api/store/v1.php';
}

function supplierBridgeEndpointTargetsCurrentSite(string $url): bool
{
    $candidate = parse_url($url);
    $current = parse_url(supplierBridgeCurrentEndpoint());
    if (!is_array($candidate) || !is_array($current) || empty($candidate['host']) || empty($current['host'])) return false;
    $candidateHost = strtolower(trim((string) $candidate['host'], '[]'));
    $currentHost = strtolower(trim((string) $current['host'], '[]'));
    $candidatePath = rtrim((string) ($candidate['path'] ?? ''), '/');
    $currentPath = rtrim((string) ($current['path'] ?? ''), '/');
    return $candidateHost === $currentHost && $candidatePath === $currentPath;
}

function supplierBridgeValidateEndpoint(string $url): bool
{
    $normalized = storeBridgeNormalizeExternalHttpsUrl($url, 'Supplier endpoint');
    if (empty($normalized['success'])) return false;
    $parts = parse_url((string) ($normalized['value'] ?? ''));
    if (!is_array($parts)) return false;
    $path = (string) ($parts['path'] ?? '');
    return $path !== '';
}

function supplierBridgeConnectionPriceModes(): array
{
    return ['source', 'markup', 'keep'];
}

function supplierBridgeProductPriceModes(): array
{
    return ['connection', 'source', 'markup', 'fixed', 'keep'];
}

function supplierBridgePurchaseModes(): array
{
    return ['disabled', 'test', 'live'];
}

function supplierBridgeConnectionAllowsPurchase(array $row, int $userId = 0): bool
{
    $mode = strtolower(trim((string) ($row['purchase_mode'] ?? 'live')));
    if ($mode === 'live') return true;
    if ($mode !== 'test' || $userId < 1) return false;
    static $adminCache = [];
    if (array_key_exists($userId, $adminCache)) return $adminCache[$userId];
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return $adminCache[$userId] = false;
    $stmt = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return $adminCache[$userId] = false;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $adminCache[$userId] = strtolower(trim((string) ($user['role'] ?? ''))) === 'admin';
}

function supplierBridgeCreateConnection(array $input, int $adminId): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Unable to prepare supplier tables'];
    $name = trim((string) ($input['name'] ?? ''));
    $providerType = strtolower(trim((string) ($input['provider_type'] ?? 'sakazuki_v1')));
    $endpoint = trim((string) ($input['endpoint_url'] ?? ''));
    $apiKey = trim((string) ($input['api_key'] ?? ''));
    $priority = (int) ($input['priority'] ?? 100);
    $purchaseModeDefault = supplierBridgeProviderRequiresProtectedPurchase($providerType) ? 'disabled' : 'live';
    $purchaseMode = strtolower(trim((string) ($input['purchase_mode'] ?? $purchaseModeDefault)));
    $autoPublish = !empty($input['auto_publish']) ? 1 : 0;
    if (supplierBridgeProviderRequiresProtectedPurchase($providerType)) {
        // Non-idempotent session-based suppliers always start fenced. An
        // administrator must explicitly move the saved connection to test or
        // live after mapping and cost guards have been reviewed.
        $purchaseMode = 'disabled';
        $autoPublish = 0;
    }
    $syncDetails = !empty($input['sync_details']) ? 1 : 0;
    $syncPrices = !empty($input['sync_prices']) ? 1 : 0;
    $userMode = strtolower(trim((string) ($input['user_price_mode'] ?? 'source')));
    $resellerMode = strtolower(trim((string) ($input['reseller_price_mode'] ?? 'source')));
    $userMarkup = round((float) ($input['user_markup_percent'] ?? 20), 2);
    $resellerMarkup = round((float) ($input['reseller_markup_percent'] ?? 10), 2);
    $protectBelowCost = !empty($input['protect_below_cost']) ? 1 : 0;
    if ($name === '' || strlen($name) > 190) return ['success' => false, 'message' => 'Supplier name is invalid'];
    if (!isset(supplierBridgeProviderTypes()[$providerType])) return ['success' => false, 'message' => 'Unsupported provider type'];
    if (!supplierBridgeValidateEndpoint($endpoint)) return ['success' => false, 'message' => 'Endpoint must be a valid public HTTPS URL'];
    if (supplierBridgeEndpointTargetsCurrentSite($endpoint)) return ['success' => false, 'message' => 'A website cannot use its own Store API as an upstream supplier'];
    if ($apiKey === '' || strlen($apiKey) > 500) return ['success' => false, 'message' => 'API key is invalid'];
    if (!in_array($purchaseMode, supplierBridgePurchaseModes(), true)) return ['success' => false, 'message' => 'Purchase mode is invalid'];
    if (!in_array($userMode, supplierBridgeConnectionPriceModes(), true) || !in_array($resellerMode, supplierBridgeConnectionPriceModes(), true)) return ['success' => false, 'message' => 'Default price mode is invalid'];
    if (!is_finite($userMarkup) || !is_finite($resellerMarkup) || $userMarkup < 0 || $resellerMarkup < 0 || $userMarkup > 10000 || $resellerMarkup > 10000) {
        return ['success' => false, 'message' => 'Markup is invalid'];
    }
    $cipher = storeBridgeEncryptSecret($apiKey);
    if ($cipher === null) return ['success' => false, 'message' => 'Unable to encrypt API key. Check private directory permissions or STORE_BRIDGE_ENCRYPTION_KEY.'];
    $priority = max(-100000, min(100000, $priority));
    $stmt = $conn->prepare("INSERT INTO supplier_connections
        (name, provider_type, purchase_mode, endpoint_url, api_key_ciphertext, priority, auto_publish, sync_details, sync_prices, user_price_mode, reseller_price_mode, user_markup_percent, reseller_markup_percent, protect_below_cost, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare supplier connection'];
    $stmt->bind_param('sssssiiiissddii', $name, $providerType, $purchaseMode, $endpoint, $cipher, $priority, $autoPublish, $syncDetails, $syncPrices, $userMode, $resellerMode, $userMarkup, $resellerMarkup, $protectBelowCost, $adminId);
    $ok = $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $ok && $id > 0 ? ['success' => true, 'connection_id' => $id] : ['success' => false, 'message' => 'Unable to save supplier connection'];
}

function supplierBridgeUpdateConnection(int $id, array $input): array
{
    global $conn;
    if ($id < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Invalid supplier connection'];
    $current = supplierBridgeGetConnection($id);
    if (!$current) return ['success' => false, 'message' => 'Supplier connection was not found'];
    $name = trim((string) ($input['name'] ?? ''));
    $endpoint = trim((string) ($input['endpoint_url'] ?? ''));
    $apiKey = trim((string) ($input['api_key'] ?? ''));
    $priority = max(-100000, min(100000, (int) ($input['priority'] ?? 100)));
    $purchaseModeInput = strtolower(trim((string) ($input['purchase_mode'] ?? '')));
    $purchaseMode = $purchaseModeInput !== '' ? $purchaseModeInput : strtolower(trim((string) ($current['purchase_mode'] ?? 'live')));
    $autoPublish = !empty($input['auto_publish']) ? 1 : 0;
    $syncDetails = !empty($input['sync_details']) ? 1 : 0;
    $syncPrices = !empty($input['sync_prices']) ? 1 : 0;
    $userMode = strtolower(trim((string) ($input['user_price_mode'] ?? 'source')));
    $resellerMode = strtolower(trim((string) ($input['reseller_price_mode'] ?? 'source')));
    $userMarkup = round((float) ($input['user_markup_percent'] ?? 20), 2);
    $resellerMarkup = round((float) ($input['reseller_markup_percent'] ?? 10), 2);
    $protectBelowCost = !empty($input['protect_below_cost']) ? 1 : 0;
    if ($name === '' || strlen($name) > 190 || !supplierBridgeValidateEndpoint($endpoint) || supplierBridgeEndpointTargetsCurrentSite($endpoint)) return ['success' => false, 'message' => 'Connection settings are invalid or point back to this website'];
    if (!in_array($purchaseMode, supplierBridgePurchaseModes(), true)) return ['success' => false, 'message' => 'Purchase mode is invalid'];
    if (!in_array($userMode, supplierBridgeConnectionPriceModes(), true) || !in_array($resellerMode, supplierBridgeConnectionPriceModes(), true)) return ['success' => false, 'message' => 'Default price mode is invalid'];
    if (!is_finite($userMarkup) || !is_finite($resellerMarkup) || $userMarkup < 0 || $resellerMarkup < 0 || $userMarkup > 10000 || $resellerMarkup > 10000) return ['success' => false, 'message' => 'Markup is invalid'];
    if ($apiKey !== '') {
        $cipher = storeBridgeEncryptSecret($apiKey);
        if ($cipher === null) return ['success' => false, 'message' => 'Unable to encrypt API key'];
        $stmt = $conn->prepare('UPDATE supplier_connections SET name=?,endpoint_url=?,api_key_ciphertext=?,purchase_mode=?,priority=?,auto_publish=?,sync_details=?,sync_prices=?,user_price_mode=?,reseller_price_mode=?,user_markup_percent=?,reseller_markup_percent=?,protect_below_cost=? WHERE id=?');
        if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare supplier update'];
        $stmt->bind_param('ssssiiiissddii', $name, $endpoint, $cipher, $purchaseMode, $priority, $autoPublish, $syncDetails, $syncPrices, $userMode, $resellerMode, $userMarkup, $resellerMarkup, $protectBelowCost, $id);
    } else {
        $stmt = $conn->prepare('UPDATE supplier_connections SET name=?,endpoint_url=?,purchase_mode=?,priority=?,auto_publish=?,sync_details=?,sync_prices=?,user_price_mode=?,reseller_price_mode=?,user_markup_percent=?,reseller_markup_percent=?,protect_below_cost=? WHERE id=?');
        if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare supplier update'];
        $stmt->bind_param('sssiiiissddii', $name, $endpoint, $purchaseMode, $priority, $autoPublish, $syncDetails, $syncPrices, $userMode, $resellerMode, $userMarkup, $resellerMarkup, $protectBelowCost, $id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Unable to update supplier connection'];
}

function supplierBridgeSetConnectionStatus(int $id, string $status): bool
{
    global $conn;
    if ($id < 1 || !storeBridgeEnsureSchema()) return false;
    $status = $status === 'active' ? 'active' : 'inactive';
    $stmt = $conn->prepare('UPDATE supplier_connections SET status = ? WHERE id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('si', $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function supplierBridgeEncryptionHealth(): array
{
    $primary = storeBridgeEncryptionKey(false);
    $keys = storeBridgeDecryptionKeys();
    $connections = supplierBridgeGetConnectionsRawForHealth();
    $decryptable = 0;
    $failed = 0;
    foreach ($connections as $row) {
        $cipher = trim((string) ($row['api_key_ciphertext'] ?? ''));
        if ($cipher === '') continue;
        if (storeBridgeDecryptSecret($cipher) !== null) $decryptable++;
        else $failed++;
    }
    return [
        'primary_key_available' => $primary !== null,
        'key_count' => count($keys),
        'connection_count' => count($connections),
        'decryptable_connections' => $decryptable,
        'failed_connections' => $failed,
        'healthy' => $primary !== null && $failed === 0,
    ];
}

function supplierBridgeGetConnectionsRawForHealth(): array
{
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return [];
    try {
        $table = $conn->query("SHOW TABLES LIKE 'supplier_connections'");
        $exists = $table && $table->num_rows > 0;
        if ($table) $table->free();
        if (!$exists) return [];
        $result = $conn->query('SELECT id, name, status, api_key_ciphertext, last_sync_at, last_success_at, last_error FROM supplier_connections ORDER BY id ASC');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function supplierBridgeGetConnections(): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $result = $conn->query("SELECT c.*,
        (SELECT COUNT(*) FROM supplier_products p WHERE p.connection_id = c.id AND p.supplier_removed_at IS NULL) AS product_count,
        (SELECT COUNT(*) FROM supplier_orders o WHERE o.connection_id = c.id) AS order_count
        FROM supplier_connections c ORDER BY c.priority ASC, c.id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function supplierBridgeGetConnection(int $id): ?array
{
    global $conn;
    if ($id < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare('SELECT * FROM supplier_connections WHERE id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function supplierBridgeApiRequestOnce(
    array $connection,
    string $action,
    string $method = 'GET',
    array $payload = [],
    string $attemptGroupId = '',
    int $attemptNumber = 1,
    int $maxAttempts = 1
): array
{
    $attemptStartedAt = microtime(true);
    $providerType = strtolower(trim((string) ($connection['provider_type'] ?? 'sakazuki_v1')));
    $endpoint = trim((string) ($connection['endpoint_url'] ?? ''));
    $method = strtoupper(trim($method));
    $action = strtolower(trim($action));
    $attemptNumber = max(1, $attemptNumber);
    $maxAttempts = max($attemptNumber, $maxAttempts);
    if ($attemptGroupId === '') {
        try { $attemptGroupId = 'sup_' . substr(bin2hex(random_bytes(12)), 0, 24); }
        catch (Throwable $e) { $attemptGroupId = 'sup_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 24); }
    }
    try { $attemptId = 'sat_' . substr(bin2hex(random_bytes(12)), 0, 24); }
    catch (Throwable $e) { $attemptId = 'sat_' . substr(hash('sha256', microtime(true) . mt_rand() . $attemptNumber), 0, 24); }

    $endpointParts = @parse_url($endpoint);
    $targetHost = is_array($endpointParts) ? strtolower(trim((string) ($endpointParts['host'] ?? ''))) : '';
    $targetScheme = is_array($endpointParts) ? strtolower(trim((string) ($endpointParts['scheme'] ?? ''))) : '';
    $targetPort = is_array($endpointParts) && isset($endpointParts['port']) ? (int) $endpointParts['port'] : ($targetScheme === 'https' ? 443 : 0);
    $targetPath = is_array($endpointParts) ? (string) ($endpointParts['path'] ?? '/') : '';
    if ($targetPath === '') $targetPath = '/';
    $resolvedTargetHost = '';
    $resolvedTargetIps = [];
    $pinnedTargetIp = '';

    $response = '';
    $tooLarge = false;
    $selectedResponseHeaders = [];
    $curlInfo = [];
    $curlNo = 0;
    $curlError = '';
    $httpCode = 0;
    $configuredConnectTimeout = max(2, min(30, (int) ($connection['connect_timeout'] ?? 8)));
    $configuredRequestTimeout = max(5, min(120, (int) ($connection['request_timeout'] ?? 25)));
    $isSharedLedgerAction = strpos($action, 'shared_') === 0;
    $connectTimeout = in_array($action, ['inventory', 'order', 'order_status'], true)
        ? min($configuredConnectTimeout, 3)
        : ($isSharedLedgerAction ? min($configuredConnectTimeout, 2) : $configuredConnectTimeout);
    if ($action === 'inventory') {
        $requestTimeout = min($configuredRequestTimeout, 6);
    } elseif ($action === 'order_status') {
        $requestTimeout = min($configuredRequestTimeout, 4);
    } elseif ($action === 'order') {
        $requestTimeout = min($configuredRequestTimeout, 12);
    } elseif ($action === 'shared_history_import') {
        $requestTimeout = min($configuredRequestTimeout, 8);
    } elseif ($isSharedLedgerAction) {
        $requestTimeout = min($configuredRequestTimeout, 2);
    } else {
        $requestTimeout = $configuredRequestTimeout;
    }

    $finalize = static function (array $result, ?array $decodedData = null) use (
        $connection, $providerType, $action, $method, $payload, $attemptGroupId, $attemptId,
        $attemptNumber, $maxAttempts, $attemptStartedAt, $targetHost, $targetScheme, $targetPort,
        $targetPath, $connectTimeout, $requestTimeout, &$resolvedTargetHost, &$resolvedTargetIps, &$pinnedTargetIp,
        &$response, &$tooLarge, &$selectedResponseHeaders, &$curlInfo, &$curlNo, &$curlError, &$httpCode
    ): array {
        $attemptFinishedAt = microtime(true);
        $elapsedMs = max(0, (int) round(($attemptFinishedAt - $attemptStartedAt) * 1000));
        $timeMs = static function ($value): int {
            return is_numeric($value) ? max(0, (int) round(((float) $value) * 1000)) : 0;
        };
        $dnsMs = $timeMs($curlInfo['namelookup_time'] ?? 0);
        $connectCompleteMs = $timeMs($curlInfo['connect_time'] ?? 0);
        $tlsCompleteMs = $timeMs($curlInfo['appconnect_time'] ?? 0);
        $pretransferMs = $timeMs($curlInfo['pretransfer_time'] ?? 0);
        $ttfbMs = $timeMs($curlInfo['starttransfer_time'] ?? 0);
        $curlTotalMs = $timeMs($curlInfo['total_time'] ?? 0);
        $tlsHandshakeMs = $tlsCompleteMs > 0 && $connectCompleteMs > 0
            ? max(0, $tlsCompleteMs - $connectCompleteMs) : 0;
        $responseBytes = strlen($response);
        $responseHash = $response !== '' ? hash('sha256', $response) : '';
        $jsonValid = is_array($decodedData);
        $topLevelKeys = [];
        if ($jsonValid) {
            foreach (array_keys($decodedData) as $key) {
                if (!is_scalar($key)) continue;
                $topLevelKeys[] = substr((string) $key, 0, 80);
                if (count($topLevelKeys) >= 30) break;
            }
        }

        $providerCode = '';
        $providerMessage = '';
        if ($jsonValid) {
            if (isset($decodedData['code']) && is_scalar($decodedData['code'])) {
                $providerCode = substr(trim((string) $decodedData['code']), 0, 100);
            }
            foreach (['message', 'error', 'detail'] as $field) {
                if (isset($decodedData[$field]) && is_scalar($decodedData[$field])) {
                    $providerMessage = storeBridgeDiagnosticSanitizeMessage((string) $decodedData[$field], 500);
                    if ($providerMessage !== '') break;
                }
            }
        }

        $errorCode = substr(trim((string) ($result['error_code'] ?? '')), 0, 100);
        $transportError = !empty($result['transport_error']);
        $success = !empty($result['ok']);
        if ($success) $providerMessage = '';
        $retryableCondition = $transportError
            || $httpCode === 0 || $httpCode === 408 || $httpCode === 425 || $httpCode === 429 || $httpCode >= 500
            || in_array($errorCode, ['invalid_json', 'response_too_large'], true);
        if ($success) $failureClass = '';
        elseif (in_array($errorCode, ['endpoint_invalid', 'credential_decryption_failed', 'curl_unavailable', 'shared_auth_unavailable'], true)) $failureClass = 'configuration';
        elseif ($transportError || $curlNo !== 0) $failureClass = 'transport';
        elseif ($errorCode === 'invalid_json') $failureClass = 'response_format';
        elseif ($httpCode >= 400) $failureClass = 'http_or_provider';
        else $failureClass = 'application';

        $httpVersion = '';
        $versionValue = (int) ($curlInfo['http_version'] ?? 0);
        $versionMap = [];
        if (defined('CURL_HTTP_VERSION_1_0')) $versionMap[(int) CURL_HTTP_VERSION_1_0] = 'HTTP/1.0';
        if (defined('CURL_HTTP_VERSION_1_1')) $versionMap[(int) CURL_HTTP_VERSION_1_1] = 'HTTP/1.1';
        if (defined('CURL_HTTP_VERSION_2_0')) $versionMap[(int) CURL_HTTP_VERSION_2_0] = 'HTTP/2';
        if (defined('CURL_HTTP_VERSION_2TLS')) $versionMap[(int) CURL_HTTP_VERSION_2TLS] = 'HTTP/2';
        if (defined('CURL_HTTP_VERSION_3')) $versionMap[(int) CURL_HTTP_VERSION_3] = 'HTTP/3';
        if (isset($versionMap[$versionValue])) $httpVersion = $versionMap[$versionValue];

        $evidence = [
            'schema' => 'sakazuki.debug',
            'version' => 1,
            'type' => 'supplier_api.attempt',
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'started_at_unix_ms' => (int) round($attemptStartedAt * 1000),
            'finished_at_unix_ms' => (int) round($attemptFinishedAt * 1000),
            'attempt_group_id' => $attemptGroupId,
            'attempt_id' => $attemptId,
            'connection' => [
                'id' => max(0, (int) ($connection['id'] ?? 0)),
                'name' => substr(trim((string) ($connection['name'] ?? '')), 0, 190),
                'provider_type' => $providerType,
            ],
            'attempt' => [
                'number' => $attemptNumber,
                'max_attempts' => $maxAttempts,
                'automatic_retry_remaining' => max(0, $maxAttempts - $attemptNumber),
                'retry_delay_ms_if_retried' => $attemptNumber < $maxAttempts ? 250 : 0,
            ],
            'request' => [
                'action' => $action,
                'method' => $method,
                'summary' => storeBridgeSafeRequestSummary($action, $payload),
                'connect_timeout_seconds' => $connectTimeout,
                'request_timeout_seconds' => $requestTimeout,
            ],
            'target' => [
                'scheme' => $targetScheme,
                'host' => $resolvedTargetHost !== '' ? $resolvedTargetHost : $targetHost,
                'port' => $targetPort,
                'path' => substr($targetPath, 0, 500),
                'resolved_ips' => array_slice(array_values($resolvedTargetIps), 0, 12),
                'dns_pinned_ip' => $pinnedTargetIp,
                'query_excluded' => true,
            ],
            'transport' => [
                'curl_errno' => $curlNo,
                'curl_error' => storeBridgeDiagnosticSanitizeMessage($curlError, 500),
                'primary_ip' => substr(trim((string) ($curlInfo['primary_ip'] ?? '')), 0, 64),
                'primary_port' => max(0, (int) ($curlInfo['primary_port'] ?? 0)),
                'local_ip' => substr(trim((string) ($curlInfo['local_ip'] ?? '')), 0, 64),
                'local_port' => max(0, (int) ($curlInfo['local_port'] ?? 0)),
                'http_version' => $httpVersion,
                'ssl_verify_result' => isset($curlInfo['ssl_verifyresult']) ? (int) $curlInfo['ssl_verifyresult'] : null,
                'dns_ms' => $dnsMs,
                'connect_complete_ms' => $connectCompleteMs,
                'tls_complete_ms' => $tlsCompleteMs,
                'tls_handshake_ms' => $tlsHandshakeMs,
                'pretransfer_ms' => $pretransferMs,
                'ttfb_ms' => $ttfbMs,
                'curl_total_ms' => $curlTotalMs,
                'total_ms' => $curlTotalMs > 0 ? $curlTotalMs : $elapsedMs,
            ],
            'response' => [
                'http_code' => max(0, min(65535, $httpCode)),
                'content_type' => substr(trim((string) ($curlInfo['content_type'] ?? ($selectedResponseHeaders['content-type'] ?? ''))), 0, 190),
                'download_bytes' => $responseBytes,
                'sha256' => $responseHash,
                'json_valid' => $jsonValid,
                'top_level_keys' => $topLevelKeys,
                'headers' => $selectedResponseHeaders,
                'provider_code' => $providerCode,
                'provider_message' => $providerMessage,
                'body_preview_excluded' => true,
            ],
            'result' => [
                'success' => $success,
                'transport_error' => $transportError,
                'failure_class' => $failureClass,
                'error_code' => $errorCode,
                'error_message' => storeBridgeDiagnosticSanitizeMessage((string) ($result['error'] ?? ''), 500),
                'retryable_condition' => $retryableCondition,
                'automatic_retry_allowed' => $retryableCondition && $attemptNumber < $maxAttempts,
                'response_too_large' => $tooLarge,
            ],
            'privacy' => [
                'api_key_excluded' => true,
                'authorization_headers_excluded' => true,
                'request_body_excluded' => true,
                'response_body_excluded' => true,
                'delivered_plaintext_keys_excluded' => true,
                'url_query_excluded' => true,
            ],
        ];
        supplierBridgeLogApiAttemptEvidence($evidence);
        return $result;
    };

    if (!isset(supplierBridgeProviderTypes()[$providerType])) {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'unsupported_provider_protocol', 'error' => 'Unsupported supplier protocol', 'data' => null]);
    }
    if (!supplierBridgeValidateEndpoint($endpoint)) {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'endpoint_invalid', 'error' => 'Supplier endpoint configuration is invalid', 'data' => null]);
    }
    $ciphertext = (string) ($connection['api_key_ciphertext'] ?? '');
    $key = storeBridgeDecryptSecret($ciphertext);
    if ($key === null) {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'credential_decryption_failed', 'error' => 'Supplier API credential cannot be decrypted', 'data' => null]);
    }
    // Session-based suppliers own their verified endpoint map and must
    // distinguish failures before purchase from failures after the
    // non-idempotent purchase request. Dispatch before the generic Sakazuki
    // DNS/request builder so each adapter owns that boundary precisely.
    if ($providerType === 'vipstore_v1') {
        $vipResult = supplierBridgeVipstoreApiRequest($connection, $action, $method, $payload, $key, $connectTimeout, $requestTimeout);
        $httpCode = (int) ($vipResult['http_code'] ?? 0);
        $decoded = is_array($vipResult['data'] ?? null) ? $vipResult['data'] : null;
        return $finalize($vipResult, $decoded);
    }
    if ($providerType === 'starkmods_v1') {
        $starkmodsResult = supplierBridgeStarkmodsApiRequest($connection, $action, $method, $payload, $key, $connectTimeout, $requestTimeout);
        $httpCode = (int) ($starkmodsResult['http_code'] ?? 0);
        $decoded = is_array($starkmodsResult['data'] ?? null) ? $starkmodsResult['data'] : null;
        return $finalize($starkmodsResult, $decoded);
    }
    if (!function_exists('curl_init')) {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'curl_unavailable', 'error' => 'PHP cURL is unavailable', 'data' => null]);
    }

    $resolvedTarget = storeBridgeResolvePublicHttpsTarget($endpoint, 'Supplier endpoint');
    if (empty($resolvedTarget['success'])) {
        return $finalize([
            'ok' => false,
            'http_code' => 0,
            'transport_error' => false,
            'error_code' => (string) ($resolvedTarget['code'] ?? 'endpoint_dns_invalid'),
            'error' => (string) ($resolvedTarget['message'] ?? 'Supplier endpoint DNS validation failed'),
            'data' => null,
        ]);
    }
    $resolvedTargetHost = (string) ($resolvedTarget['host'] ?? '');
    $resolvedTargetIps = array_values(array_filter((array) ($resolvedTarget['ips'] ?? []), static fn($ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false));
    $pinnedTargetIp = (string) ($resolvedTargetIps[0] ?? '');
    if ($resolvedTargetHost === '' || $pinnedTargetIp === '') {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'dns_resolution_failed', 'error' => 'Supplier endpoint has no usable public IP address', 'data' => null]);
    }

    $url = $endpoint;
    if ($method === 'GET') {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query(array_merge($payload, ['action' => $action]), '', '&', PHP_QUERY_RFC3986);
    } else {
        $payload = array_merge($payload, ['action' => $action]);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => true, 'error_code' => 'curl_init_failed', 'error' => 'Unable to initialize supplier request', 'data' => null]);
    }

    $headers = ['Accept: application/json', 'X-API-Key: ' . $key, 'User-Agent: Sakazuki-StoreBridge/' . STORE_BRIDGE_VERSION];
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $requestTimeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROXY => '',
        CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$selectedResponseHeaders) {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, ':') === false) return $length;
            [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
            $lower = strtolower($name);
            $allowed = ['server', 'content-type', 'cf-ray', 'cf-cache-status', 'x-request-id', 'x-correlation-id', 'retry-after', 'via', 'x-envoy-upstream-service-time'];
            if (!in_array($lower, $allowed, true)) return $length;
            $value = storeBridgeDiagnosticSanitizeMessage($value, 500);
            if ($value === '') return $length;
            $selectedResponseHeaders[$lower] = isset($selectedResponseHeaders[$lower])
                ? substr($selectedResponseHeaders[$lower] . ', ' . $value, 0, 1000)
                : $value;
            return $length;
        },
    ];

    if (!filter_var($resolvedTargetHost, FILTER_VALIDATE_IP)) {
        $resolveIp = strpos($pinnedTargetIp, ':') !== false ? '[' . $pinnedTargetIp . ']' : $pinnedTargetIp;
        $options[CURLOPT_RESOLVE] = [$resolvedTargetHost . ':443:' . $resolveIp];
    }
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;

    if ($method === 'POST') {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            curl_close($ch);
            return $finalize(['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error_code' => 'request_encoding_failed', 'error' => 'Unable to encode supplier payload', 'data' => null]);
        }
        $headers[] = 'Content-Type: application/json';
        if ($isSharedLedgerAction) {
            $timestamp = time();
            $signature = sharedLedgerInternalSignature($action, $json, $timestamp, hash('sha256', $key));
            if ($signature === '') {
                curl_close($ch);
                return $finalize([
                    'ok' => false,
                    'http_code' => 0,
                    'transport_error' => false,
                    'error_code' => 'shared_auth_unavailable',
                    'error' => 'Shared-ledger authentication secret is unavailable',
                    'data' => null,
                ]);
            }
            $headers[] = 'X-SAK-Shared-Timestamp: ' . $timestamp;
            $headers[] = 'X-SAK-Shared-Signature: sha256=' . $signature;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $options);
    configureBoundedCurlResponse($ch, $response, $tooLarge, STORE_BRIDGE_MAX_RESPONSE);
    // configureBoundedCurlResponse() intentionally does not touch the header
    // callback, so the whitelisted response metadata above remains active.
    $executed = curl_exec($ch);
    $curlNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    if (!is_array($curlInfo)) $curlInfo = [];
    $httpCode = max(0, (int) ($curlInfo['http_code'] ?? 0));
    curl_close($ch);

    if ($executed === false || $curlNo !== 0 || $tooLarge) {
        return $finalize([
            'ok' => false,
            'http_code' => $httpCode,
            'transport_error' => true,
            'error_code' => $tooLarge ? 'response_too_large' : 'transport_error',
            'error' => $tooLarge ? 'Supplier response exceeded limit' : ('Network error ' . $curlNo . ': ' . $curlError),
            'data' => null,
            'raw' => $response,
        ]);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return $finalize([
            'ok' => false,
            'http_code' => $httpCode,
            'transport_error' => false,
            'error_code' => 'invalid_json',
            'error' => 'Supplier returned invalid JSON',
            'data' => null,
            'raw' => $response,
        ]);
    }

    $ok = $httpCode >= 200 && $httpCode < 300 && (($data['success'] ?? true) !== false);
    $message = '';
    foreach (['message', 'error', 'detail'] as $field) {
        if (isset($data[$field]) && is_scalar($data[$field])) {
            $message = trim((string) $data[$field]);
            if ($message !== '') break;
        }
    }
    $providerErrorCode = '';
    if (!$ok && isset($data['code']) && is_scalar($data['code'])) {
        $providerErrorCode = substr(strtolower(trim((string) $data['code'])), 0, 80);
    }
    return $finalize([
        'ok' => $ok,
        'http_code' => $httpCode,
        'transport_error' => false,
        'error_code' => $ok ? '' : ($providerErrorCode !== '' ? $providerErrorCode : ('http_' . $httpCode)),
        'error' => $ok ? '' : ($message !== '' ? $message : 'Supplier request failed'),
        'data' => $data,
        'raw' => $response,
    ], $data);
}


function supplierBridgeApiRequest(array $connection, string $action, string $method = 'GET', array $payload = [], int $maxAttemptsOverride = 0): array
{
    $method = strtoupper($method);
    $action = strtolower(trim($action));
    $sharedLedgerAction = strpos($action, 'shared_') === 0;
    $idempotent = $method === 'GET' || $sharedLedgerAction;
    $maxAttempts = $sharedLedgerAction ? 1 : ($idempotent ? 2 : 1);
    if ($maxAttemptsOverride > 0) {
        $maxAttempts = $sharedLedgerAction ? 1 : ($idempotent ? max(1, min(2, $maxAttemptsOverride)) : 1);
    }
    try { $attemptGroupId = 'sup_' . substr(bin2hex(random_bytes(12)), 0, 24); }
    catch (Throwable $e) { $attemptGroupId = 'sup_' . substr(hash('sha256', microtime(true) . mt_rand()), 0, 24); }

    $last = ['ok' => false, 'http_code' => 0, 'transport_error' => false, 'error' => 'Supplier request failed', 'data' => null];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $last = supplierBridgeApiRequestOnce($connection, $action, $method, $payload, $attemptGroupId, $attempt, $maxAttempts);
        $last['attempts'] = $attempt;
        if (!empty($last['ok'])) break;
        $code = (string) ($last['error_code'] ?? '');
        $httpCode = (int) ($last['http_code'] ?? 0);
        $retryable = !empty($last['transport_error'])
            || $httpCode === 0 || $httpCode === 408 || $httpCode === 425 || $httpCode === 429 || $httpCode >= 500
            || in_array($code, ['invalid_json', 'response_too_large'], true);
        if (!$retryable || $attempt >= $maxAttempts) break;
        usleep(250000);
    }
    return $last;
}


function storeBridgeSharedNamespaces(): array
{
    return ['slip_image', 'slip_transaction', 'slip_history_ready', 'binance_tx'];
}

/**
 * Read the centralized migration-readiness markers for every configured site.
 *
 * The marker is deliberately stored in the same authenticated shared ledger as
 * slip references. This lets both websites know when pre-upgrade slip history
 * from every site has finished backfilling before a first-seen EasySlip
 * `isDuplicate=true` response is allowed to proceed automatically.
 */
function storeBridgeSharedHistoryStatusAction(array $input): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) {
        return ['success' => false, 'http_code' => 503, 'message' => 'Shared ledger is unavailable'];
    }
    $rawSiteIds = is_array($input['site_ids'] ?? null) ? $input['site_ids'] : [];
    $siteIds = [];
    foreach ($rawSiteIds as $rawSiteId) {
        if (!is_scalar($rawSiteId)) continue;
        $siteId = strtoupper(trim((string) $rawSiteId));
        if ($siteId === '' || strlen($siteId) > 64 || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) continue;
        $siteIds[$siteId] = true;
        if (count($siteIds) >= 12) break;
    }
    $siteIds = array_keys($siteIds);
    if ($siteIds === []) {
        return ['success' => false, 'http_code' => 422, 'message' => 'No valid site IDs were supplied'];
    }

    $stmt = $conn->prepare(
        "SELECT status FROM store_api_shared_claims WHERE namespace='slip_history_ready' AND reference_hash=? LIMIT 1"
    );
    if (!$stmt) {
        return ['success' => false, 'http_code' => 503, 'message' => 'Shared history status could not be prepared'];
    }
    $completed = [];
    $missing = [];
    foreach ($siteIds as $siteId) {
        $referenceHash = hash('sha256', 'slip_history_ready' . "\0" . $siteId);
        $stmt->bind_param('s', $referenceHash);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'http_code' => 503, 'message' => 'Shared history status could not be read'];
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($row && strtolower(trim((string) ($row['status'] ?? ''))) === 'completed') {
            $completed[] = $siteId;
        } else {
            $missing[] = $siteId;
        }
    }
    $stmt->close();
    return [
        'success' => true,
        'http_code' => 200,
        'ready' => $missing === [],
        'site_ids' => $siteIds,
        'completed_site_ids' => $completed,
        'missing_site_ids' => $missing,
    ];
}

/**
 * Atomic central record used by both websites to prevent a slip/transaction
 * from crediting two separate databases. Only SHA-256/HMAC values are stored.
 */

/**
 * Import already-committed historical slip identifiers into the central ledger.
 *
 * This endpoint is intentionally hash-only, HMAC protected at the HTTP layer,
 * idempotent, and never overwrites a live processing/reserved claim. Historical
 * migration may therefore run in large batches without weakening the financial
 * fence used by live deposits.
 */
function storeBridgeSharedHistoryImportAction(int $clientId, array $input): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) {
        return ['success' => false, 'http_code' => 503, 'message' => 'Shared ledger is unavailable'];
    }

    $namespace = strtolower(trim((string) ($input['namespace'] ?? '')));
    if (!in_array($namespace, ['slip_image', 'slip_transaction'], true)) {
        return ['success' => false, 'http_code' => 422, 'message' => 'Invalid historical namespace'];
    }
    $siteId = strtoupper(trim((string) ($input['site_id'] ?? '')));
    if ($siteId === '' || strlen($siteId) > 64 || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) {
        return ['success' => false, 'http_code' => 422, 'message' => 'Invalid historical site identity'];
    }

    $rawHashes = is_array($input['reference_hashes'] ?? null) ? $input['reference_hashes'] : [];
    $hashes = [];
    foreach ($rawHashes as $rawHash) {
        if (!is_scalar($rawHash)) continue;
        $hash = strtolower(trim((string) $rawHash));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) continue;
        $hashes[$hash] = true;
        if (count($hashes) >= 250) break;
    }
    $hashes = array_keys($hashes);
    if ($hashes === []) {
        return ['success' => false, 'http_code' => 422, 'message' => 'No valid historical references were supplied'];
    }

    $clientId = max(0, $clientId);
    $items = [];
    $imported = 0;
    $alreadyCompleted = 0;
    $recoveredExpiredProcessing = 0;
    $finalizedNonterminal = 0;
    $conflicts = 0;
    try {
        $conn->begin_transaction();
        $insert = $conn->prepare(
            "INSERT IGNORE INTO store_api_shared_claims
             (namespace, reference_hash, claimant_id, owner_hash, status, expires_at, completed_at, updated_at)
             VALUES (?, ?, ?, ?, 'completed', NULL, NOW(), NOW())"
        );
        $check = $conn->prepare(
            'SELECT status, expires_at FROM store_api_shared_claims WHERE namespace = ? AND reference_hash = ? LIMIT 1 FOR UPDATE'
        );
        // Every reference in this endpoint comes from a committed `slip_deposits`
        // row on an HMAC-authenticated owned site. That durable local row is
        // authoritative evidence that the reference has already been financially
        // used. If an older shared claim is still processing/reserved, leaving it
        // non-terminal would deadlock the historical migration forever. Terminalize
        // the claim in place WITHOUT changing its owner, so audit/reconciliation
        // identity is preserved and no monetary operation is replayed here.
        $finalizeHistoricNonterminal = $conn->prepare(
            "UPDATE store_api_shared_claims
             SET status='completed', expires_at=NULL,
                 completed_at=COALESCE(completed_at, NOW()), updated_at=NOW()
             WHERE namespace=? AND reference_hash=?
               AND status IN ('processing','reserved')"
        );
        if (!$insert || !$check || !$finalizeHistoricNonterminal) throw new RuntimeException('Historic import statements could not be prepared');

        foreach ($hashes as $referenceHash) {
            $ownerHash = hash('sha256', "history-import\0" . $siteId . "\0" . $namespace . "\0" . $referenceHash);
            $insert->bind_param('ssis', $namespace, $referenceHash, $clientId, $ownerHash);
            if (!$insert->execute()) throw new RuntimeException('Historic import insert failed');
            if ($insert->affected_rows === 1) {
                $imported++;
                $items[] = ['reference_hash' => $referenceHash, 'status' => 'completed', 'imported' => true];
                continue;
            }

            $check->bind_param('ss', $namespace, $referenceHash);
            if (!$check->execute()) throw new RuntimeException('Historic import conflict lookup failed');
            $result = $check->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === 'completed') {
                $alreadyCompleted++;
                $items[] = ['reference_hash' => $referenceHash, 'status' => 'completed', 'imported' => false];
                continue;
            }

            if (in_array($status, ['processing', 'reserved'], true)) {
                // During historical migration, normal customer credit is fail-closed
                // until every site's marker is READY. Therefore a committed historic
                // row cannot race with a new financial commit here. Converting the
                // non-terminal shared state to completed is a ledger repair only; it
                // never calls addBalance(), creates a transaction, or changes owner.
                $finalizeHistoricNonterminal->bind_param('ss', $namespace, $referenceHash);
                if (!$finalizeHistoricNonterminal->execute()) throw new RuntimeException('Historic non-terminal finalization failed');
                if ($finalizeHistoricNonterminal->affected_rows === 1) {
                    $finalizedNonterminal++;
                    if ($status === 'processing'
                        && !empty($row['expires_at'])
                        && strtotime((string) $row['expires_at']) !== false
                        && strtotime((string) $row['expires_at']) <= time()) {
                        $recoveredExpiredProcessing++;
                    }
                    $items[] = [
                        'reference_hash' => $referenceHash,
                        'status' => 'completed',
                        'imported' => false,
                        'finalized_historic_nonterminal' => true,
                        'previous_status' => $status,
                    ];
                    continue;
                }
            }

            $conflicts++;
            $items[] = [
                'reference_hash' => $referenceHash,
                'status' => $status !== '' ? $status : 'unknown',
                'conflict' => true,
            ];
        }
        $insert->close();
        $check->close();
        $finalizeHistoricNonterminal->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Shared history bulk import failed: ' . $e->getMessage());
        return ['success' => false, 'http_code' => 503, 'message' => 'Historical shared ledger import failed'];
    }

    return [
        'success' => true,
        'http_code' => 200,
        'namespace' => $namespace,
        'site_id' => $siteId,
        'received' => count($hashes),
        'imported' => $imported,
        'already_completed' => $alreadyCompleted,
        'recovered_expired_processing' => $recoveredExpiredProcessing,
        'finalized_nonterminal' => $finalizedNonterminal,
        'conflicts' => $conflicts,
        'items' => $items,
    ];
}

function storeBridgeSharedClaimAction(int $clientId, string $operation, array $input): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) {
        return ['success' => false, 'http_code' => 503, 'message' => 'Shared ledger is unavailable'];
    }
    $namespace = strtolower(trim((string) ($input['namespace'] ?? '')));
    $referenceHash = strtolower(trim((string) ($input['reference_hash'] ?? '')));
    $ownerToken = strtolower(trim((string) ($input['owner_token'] ?? '')));
    $operation = strtolower(trim($operation));
    if (!in_array($namespace, storeBridgeSharedNamespaces(), true)
        || preg_match('/^[a-f0-9]{64}$/D', $referenceHash) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $ownerToken) !== 1
        || !in_array($operation, ['claim', 'reserve', 'complete', 'release'], true)) {
        return ['success' => false, 'http_code' => 422, 'message' => 'Invalid shared-ledger request'];
    }

    $clientId = max(0, $clientId);
    $ownerHash = hash('sha256', $ownerToken);
    $ttlFloor = in_array($namespace, ['slip_image', 'slip_transaction'], true) ? 15 : 60;
    $ttl = max($ttlFloor, min(900, (int) ($input['ttl_seconds'] ?? 300)));
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    try {
        $conn->begin_transaction();
        if ($operation === 'claim') {
            $insert = $conn->prepare(
                "INSERT IGNORE INTO store_api_shared_claims
                 (namespace, reference_hash, claimant_id, owner_hash, status, expires_at)
                 VALUES (?, ?, ?, ?, 'processing', ?)"
            );
            if (!$insert) throw new RuntimeException('Shared claim insert could not be prepared');
            $insert->bind_param('ssiss', $namespace, $referenceHash, $clientId, $ownerHash, $expiresAt);
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('Shared claim insert failed');
            }
            $inserted = $insert->affected_rows === 1;
            $insert->close();
            if ($inserted) {
                $conn->commit();
                return ['success' => true, 'http_code' => 200, 'status' => 'processing', 'new' => true, 'same_owner' => true];
            }

            $select = $conn->prepare(
                'SELECT claimant_id, owner_hash, status, expires_at
                 FROM store_api_shared_claims
                 WHERE namespace = ? AND reference_hash = ? LIMIT 1 FOR UPDATE'
            );
            if (!$select) throw new RuntimeException('Shared claim lookup could not be prepared');
            $select->bind_param('ss', $namespace, $referenceHash);
            if (!$select->execute()) {
                $select->close();
                throw new RuntimeException('Shared claim lookup failed');
            }
            $result = $select->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $select->close();
            if (!$row) throw new RuntimeException('Shared claim disappeared');

            $sameOwner = (int) ($row['claimant_id'] ?? -1) === $clientId
                && hash_equals((string) ($row['owner_hash'] ?? ''), $ownerHash);
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($sameOwner) {
                if ($status === 'completed') {
                    $conn->commit();
                    return [
                        'success' => false,
                        'http_code' => 409,
                        'duplicate' => true,
                        'status' => 'completed',
                        'new' => false,
                        'same_owner' => true,
                        'message' => 'Reference was already used',
                    ];
                }
                $conn->commit();
                return ['success' => true, 'http_code' => 200, 'status' => $status, 'new' => false, 'same_owner' => true];
            }

            $expired = $status === 'processing'
                && !empty($row['expires_at'])
                && strtotime((string) $row['expires_at']) < time();
            if ($expired) {
                $takeover = $conn->prepare(
                    "UPDATE store_api_shared_claims
                     SET claimant_id = ?, owner_hash = ?, status = 'processing',
                         expires_at = ?, completed_at = NULL, updated_at = NOW()
                     WHERE namespace = ? AND reference_hash = ?"
                );
                if (!$takeover) throw new RuntimeException('Shared claim takeover could not be prepared');
                $takeover->bind_param('issss', $clientId, $ownerHash, $expiresAt, $namespace, $referenceHash);
                $ok = $takeover->execute() && $takeover->affected_rows === 1;
                $takeover->close();
                if (!$ok) throw new RuntimeException('Shared claim takeover failed');
                $conn->commit();
                return ['success' => true, 'http_code' => 200, 'status' => 'processing', 'new' => true, 'same_owner' => false];
            }

            $conn->commit();
            return [
                'success' => false,
                'http_code' => 409,
                'duplicate' => true,
                'status' => $status,
                'new' => false,
                'same_owner' => false,
                'message' => $status === 'processing' ? 'Reference is being processed' : 'Reference was already used',
            ];
        }

        if ($operation === 'release') {
            $stmt = $conn->prepare(
                "DELETE FROM store_api_shared_claims
                 WHERE namespace = ? AND reference_hash = ?
                   AND claimant_id = ? AND owner_hash = ?
                   AND status IN ('processing','reserved')"
            );
            if (!$stmt) throw new RuntimeException('Shared release could not be prepared');
            $stmt->bind_param('ssis', $namespace, $referenceHash, $clientId, $ownerHash);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) throw new RuntimeException('Shared release failed');
            $conn->commit();
            return ['success' => true, 'http_code' => 200, 'status' => 'released'];
        }

        $nextStatus = $operation === 'reserve' ? 'reserved' : 'completed';
        $completedSql = $operation === 'complete' ? 'NOW()' : 'completed_at';
        $stmt = $conn->prepare(
            "UPDATE store_api_shared_claims
             SET status = ?, expires_at = NULL, completed_at = {$completedSql}, updated_at = NOW()
             WHERE namespace = ? AND reference_hash = ?
               AND claimant_id = ? AND owner_hash = ?
               AND status IN ('processing','reserved')"
        );
        if (!$stmt) throw new RuntimeException('Shared state update could not be prepared');
        $stmt->bind_param('sssis', $nextStatus, $namespace, $referenceHash, $clientId, $ownerHash);
        $ok = $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$ok) throw new RuntimeException('Shared state update failed');

        if (!$updated) {
            $check = $conn->prepare(
                'SELECT claimant_id, owner_hash, status FROM store_api_shared_claims
                 WHERE namespace = ? AND reference_hash = ? LIMIT 1'
            );
            if (!$check) throw new RuntimeException('Shared state check could not be prepared');
            $check->bind_param('ss', $namespace, $referenceHash);
            $check->execute();
            $result = $check->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $check->close();
            $rowStatus = strtolower(trim((string) ($row['status'] ?? '')));
            // A completed reference is terminal financial evidence, not an
            // idempotent reserve. Treating completed as reserve-success could let a
            // request continue toward credit after historical migration terminalized
            // the same reference between claim and reserve. Complete->completed is
            // idempotent; reserve->reserved is idempotent; nothing else is.
            $expectedIdempotentStatus = $operation === 'complete' ? 'completed' : 'reserved';
            $idempotent = $row
                && (int) ($row['claimant_id'] ?? -1) === $clientId
                && hash_equals((string) ($row['owner_hash'] ?? ''), $ownerHash)
                && $rowStatus === $expectedIdempotentStatus;
            if (!$idempotent) {
                $conn->commit();
                return ['success' => false, 'http_code' => 409, 'duplicate' => true, 'message' => 'Shared claim ownership changed'];
            }
        }

        $conn->commit();
        return ['success' => true, 'http_code' => 200, 'status' => $nextStatus];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Shared ledger operation failed: ' . $e->getMessage());
        return ['success' => false, 'http_code' => 503, 'message' => 'Shared ledger is temporarily unavailable'];
    }
}

function sharedLedgerPrivateSiteConfig(): array
{
    static $loaded = false;
    static $config = [];
    if ($loaded) return $config;
    $loaded = true;
    $configPath = dirname(__DIR__, 2) . '/private/cheatgame.php';
    if (!is_file($configPath) || !is_readable($configPath)) return $config;
    try {
        $value = require $configPath;
        if (is_array($value)) $config = $value;
    } catch (Throwable $e) {
        error_log('Shared ledger site profile could not be read: ' . $e->getMessage());
    }
    return $config;
}

/**
 * Secret used only for cross-site shared-ledger calls.
 *
 * A dedicated `shared_ledger_secret` may be configured, otherwise the existing
 * CHEATGAME webhook secret is reused because the same private config is already
 * synchronized between the two owned websites. Ordinary reseller API clients
 * never receive either secret, so possession of an X-API-Key alone cannot
 * mutate cross-site financial fencing state.
 */
function sharedLedgerInternalSecret(): string
{
    $config = sharedLedgerPrivateSiteConfig();
    foreach (['shared_ledger_secret', 'webhook_secret'] as $field) {
        if (!isset($config[$field]) || !is_scalar($config[$field])) continue;
        $secret = trim((string) $config[$field]);
        if (strlen($secret) >= 16 && strlen($secret) <= 512) return $secret;
    }
    return '';
}

function sharedLedgerInternalSignature(string $action, string $rawBody, int $timestamp, string $apiKeyHash): string
{
    $secret = sharedLedgerInternalSecret();
    $action = strtolower(trim($action));
    $apiKeyHash = strtolower(trim($apiKeyHash));
    if ($secret === '' || $action === '' || $timestamp < 1 || preg_match('/^[a-f0-9]{64}$/D', $apiKeyHash) !== 1) return '';
    $payloadHash = hash('sha256', $rawBody);
    // Bind the HMAC to the authenticated Store API credential as well as the
    // action/body. A captured internal signature therefore cannot be replayed
    // with a different valid reseller API key during the timestamp window.
    return hash_hmac('sha256', $timestamp . '.' . $action . '.' . $payloadHash . '.' . $apiKeyHash, $secret);
}

function sharedLedgerVerifyHttpSignature(string $action, string $rawBody, string $apiKeyHash): bool
{
    $timestampRaw = trim((string) ($_SERVER['HTTP_X_SAK_SHARED_TIMESTAMP'] ?? ''));
    $signatureRaw = trim((string) ($_SERVER['HTTP_X_SAK_SHARED_SIGNATURE'] ?? ''));
    $apiKeyHash = strtolower(trim($apiKeyHash));
    if ($timestampRaw === '' || !ctype_digit($timestampRaw) || strpos($signatureRaw, 'sha256=') !== 0
        || preg_match('/^[a-f0-9]{64}$/D', $apiKeyHash) !== 1) return false;
    $timestamp = (int) $timestampRaw;
    if ($timestamp < 1 || abs(time() - $timestamp) > 120) return false;
    $provided = strtolower(substr($signatureRaw, 7));
    if (preg_match('/^[a-f0-9]{64}$/D', $provided) !== 1) return false;
    $expected = sharedLedgerInternalSignature($action, $rawBody, $timestamp, $apiKeyHash);
    return $expected !== '' && hash_equals($expected, $provided);
}

function sharedLedgerConfiguredMode(): string
{
    $envMode = strtolower(trim((string) (getenv('SHARED_LEDGER_MODE') ?: '')));
    $settingMode = strtolower(trim((string) getSetting('shared_ledger_mode', '')));
    foreach ([$envMode, $settingMode] as $mode) {
        if (in_array($mode, ['local', 'remote'], true)) return $mode;
    }

    // Reuse the existing two-site profile to choose the hub automatically.
    $config = sharedLedgerPrivateSiteConfig();
    $databaseName = defined('DB_NAME') ? (string) DB_NAME : '';
    foreach ((array) ($config['site_profiles'] ?? []) as $profile) {
        if (!is_array($profile)) continue;
        $suffix = trim((string) ($profile['database_suffix'] ?? ''));
        if ($suffix === '' || $databaseName === '' || substr($databaseName, -strlen($suffix)) !== $suffix) continue;
        $role = strtolower(trim((string) ($profile['webhook_role'] ?? '')));
        if ($role === 'hub') return 'local';
        if ($role === 'receiver') return 'remote';
    }
    return 'auto';
}

function sharedLedgerExpectedHubHosts(): array
{
    $hosts = [];
    $config = sharedLedgerPrivateSiteConfig();
    foreach ((array) ($config['site_profiles'] ?? []) as $profile) {
        if (!is_array($profile) || strtolower(trim((string) ($profile['webhook_role'] ?? ''))) !== 'hub') continue;
        foreach ((array) ($profile['hosts'] ?? []) as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $hosts[$host] = true;
            }
        }
    }
    return array_keys($hosts);
}

function sharedLedgerCurrentSiteId(): string
{
    $config = sharedLedgerPrivateSiteConfig();
    $profiles = is_array($config['site_profiles'] ?? null) ? $config['site_profiles'] : [];

    // If CHEATGAME integration is already loaded, reuse its database-bound
    // profile selection. This is the canonical SAK-010 / ONL-005 identity.
    if (function_exists('cgoConfig')) {
        try {
            $profile = strtoupper(trim((string) (cgoConfig()['site_profile'] ?? '')));
            if ($profile !== '' && isset($profiles[$profile])) return $profile;
            foreach (array_keys($profiles) as $key) {
                if (hash_equals(strtoupper((string) $key), $profile)) return strtoupper((string) $key);
            }
        } catch (Throwable $e) {}
    }

    // Store API endpoints load store_bridge.php without cheatgame.php. Resolve
    // the same profile from the connected DB suffix, never from an untrusted Host
    // header when a database identity is available.
    $databaseName = defined('DB_NAME') ? (string) DB_NAME : '';
    if ($databaseName !== '') {
        $matches = [];
        foreach ($profiles as $profileKey => $profile) {
            if (!is_array($profile)) continue;
            $suffix = trim((string) ($profile['database_suffix'] ?? ''));
            if ($suffix !== '' && strlen($databaseName) >= strlen($suffix)
                && substr($databaseName, -strlen($suffix)) === $suffix) {
                $matches[] = strtoupper(trim((string) $profileKey));
            }
        }
        $matches = array_values(array_unique(array_filter($matches)));
        if (count($matches) === 1) return $matches[0];
    }

    // A private APP_SITE_ID may already use the canonical profile key.
    $appSiteId = strtoupper(trim((string) (defined('APP_SITE_ID') ? APP_SITE_ID : '')));
    if ($appSiteId !== '') {
        foreach (array_keys($profiles) as $profileKey) {
            $candidate = strtoupper(trim((string) $profileKey));
            if ($candidate !== '' && hash_equals($candidate, $appSiteId)) return $candidate;
        }
    }
    return '';
}

function sharedLedgerExpectedSiteIds(): array
{
    $siteIds = [];
    $config = sharedLedgerPrivateSiteConfig();
    foreach ((array) ($config['site_profiles'] ?? []) as $profileKey => $profile) {
        if (!is_array($profile)) continue;
        $siteId = strtoupper(trim((string) $profileKey));
        if ($siteId === '' || strlen($siteId) > 64 || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) continue;
        $siteIds[$siteId] = true;
    }
    if ($siteIds === []) {
        $current = sharedLedgerCurrentSiteId();
        if ($current !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $current) === 1) $siteIds[$current] = true;
    }
    return array_keys($siteIds);
}


function sharedLedgerFallbackAllowed(): bool
{
    $env = strtolower(trim((string) (getenv('SHARED_LEDGER_ALLOW_LOCAL_FALLBACK') ?: '')));
    $setting = strtolower(trim((string) getSetting('shared_ledger_allow_local_fallback', '1')));
    foreach ([$env, $setting] as $value) {
        if ($value === '') continue;
        if (in_array($value, ['0', 'false', 'off', 'no', 'disabled'], true)) return false;
        if (in_array($value, ['1', 'true', 'on', 'yes', 'enabled'], true)) return true;
    }
    return true;
}

function sharedLedgerLogFallback(string $reason): void
{
    static $logged = [];
    $reason = trim($reason);
    if ($reason === '') $reason = 'unknown';
    if (isset($logged[$reason])) return;
    $logged[$reason] = true;
    $db = defined('DB_NAME') ? (string) DB_NAME : '';
    error_log('Shared ledger fallback to local mode: ' . $reason . ($db !== '' ? '; db=' . $db : ''));
}

function sharedLedgerRemoteConnection(): array
{
    global $conn;
    static $cached = null;
    if (is_array($cached)) return $cached;
    $mode = sharedLedgerConfiguredMode();
    if ($mode === 'local') return $cached = ['mode' => 'local', 'connection' => null];
    if (!storeBridgeEnsureSchema()) {
        return $cached = ['mode' => $mode, 'connection' => null, 'unavailable' => true];
    }

    $result = $conn->query(
        "SELECT * FROM supplier_connections
         WHERE provider_type = 'sakazuki_v1'
         ORDER BY (status = 'active') DESC, priority ASC, id ASC"
    );
    $expectedHosts = array_fill_keys(sharedLedgerExpectedHubHosts(), true);
    $external = null;
    $fallbackConnections = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $endpoint = (string) ($row['endpoint_url'] ?? '');
        if (supplierBridgeEndpointTargetsCurrentSite($endpoint)) continue;
        $fallbackConnections[] = $row;
        $endpointHost = strtolower(rtrim((string) (parse_url($endpoint, PHP_URL_HOST) ?: ''), '.'));
        if ($expectedHosts !== [] && !isset($expectedHosts[$endpointHost])) continue;
        if ($external === null) $external = $row;
        if ((string) ($row['status'] ?? '') === 'active') {
            $external = $row;
            break;
        }
    }
    if ($result) $result->free();
    // A single external Store Bridge connection is unambiguous even when an
    // administrator used a private alias instead of the public hub hostname.
    if ($external === null && count($fallbackConnections) === 1) {
        $external = $fallbackConnections[0];
    }

    if (!$external) {
        return $cached = ($mode === 'remote'
            ? ['mode' => 'remote', 'connection' => null, 'unavailable' => true]
            : ['mode' => 'local', 'connection' => null]);
    }
    if ((string) ($external['status'] ?? '') !== 'active') {
        return $cached = ['mode' => 'remote', 'connection' => null, 'unavailable' => true];
    }
    return $cached = ['mode' => 'remote', 'connection' => $external];
}

/** Return whether every configured website has completed historical slip backfill. */
function sharedLedgerHistoryReady(array $siteIds = []): array
{
    $normalized = [];
    foreach ($siteIds === [] ? sharedLedgerExpectedSiteIds() : $siteIds as $rawSiteId) {
        if (!is_scalar($rawSiteId)) continue;
        $siteId = strtoupper(trim((string) $rawSiteId));
        if ($siteId === '' || strlen($siteId) > 64 || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) continue;
        $normalized[$siteId] = true;
    }
    $siteIds = array_keys($normalized);
    if ($siteIds === []) return ['success' => false, 'ready' => false, 'message' => 'Shared history site identities are unavailable'];

    $route = sharedLedgerRemoteConnection();
    if (!empty($route['unavailable'])) {
        return ['success' => false, 'ready' => false, 'message' => 'Shared history hub is unavailable'];
    }
    if (($route['mode'] ?? 'local') === 'remote') {
        $connection = is_array($route['connection'] ?? null) ? $route['connection'] : null;
        if (!$connection) return ['success' => false, 'ready' => false, 'message' => 'Shared history connection is unavailable'];
        $api = supplierBridgeApiRequest($connection, 'shared_history_status', 'POST', ['site_ids' => $siteIds]);
        $data = is_array($api['data'] ?? null) ? $api['data'] : [];
        return [
            'success' => !empty($api['ok']) && !empty($data['success']),
            'ready' => !empty($api['ok']) && !empty($data['success']) && !empty($data['ready']),
            'site_ids' => $siteIds,
            'completed_site_ids' => is_array($data['completed_site_ids'] ?? null) ? array_values($data['completed_site_ids']) : [],
            'missing_site_ids' => is_array($data['missing_site_ids'] ?? null) ? array_values($data['missing_site_ids']) : $siteIds,
            'message' => (string) ($data['message'] ?? $api['error'] ?? ''),
        ];
    }
    $local = storeBridgeSharedHistoryStatusAction(['site_ids' => $siteIds]);
    return [
        'success' => !empty($local['success']),
        'ready' => !empty($local['success']) && !empty($local['ready']),
        'site_ids' => $siteIds,
        'completed_site_ids' => is_array($local['completed_site_ids'] ?? null) ? array_values($local['completed_site_ids']) : [],
        'missing_site_ids' => is_array($local['missing_site_ids'] ?? null) ? array_values($local['missing_site_ids']) : $siteIds,
        'message' => (string) ($local['message'] ?? ''),
    ];
}


/**
 * Batch historical references into the same central ledger used by live claims.
 * Raw bank references never leave the originating site; only namespace-scoped
 * SHA-256 values are sent to the HMAC-authenticated internal endpoint.
 */
function sharedLedgerImportHistoryBatch(string $namespace, array $references, string $siteId = ''): array
{
    $namespace = strtolower(trim($namespace));
    if (!in_array($namespace, ['slip_image', 'slip_transaction'], true)) {
        return ['success' => false, 'message' => 'Invalid historical namespace'];
    }
    $siteId = strtoupper(trim($siteId !== '' ? $siteId : sharedLedgerCurrentSiteId()));
    if ($siteId === '' || preg_match('/^[A-Z0-9][A-Z0-9_-]*$/D', $siteId) !== 1) {
        return ['success' => false, 'message' => 'Historical site identity is unavailable'];
    }

    $hashToReference = [];
    foreach ($references as $reference) {
        if (!is_scalar($reference)) continue;
        $reference = trim((string) $reference);
        if ($reference === '' || strlen($reference) > 2048) continue;
        $referenceHash = hash('sha256', $namespace . "\0" . $reference);
        $hashToReference[$referenceHash] = $reference;
        if (count($hashToReference) >= 250) break;
    }
    if ($hashToReference === []) return ['success' => true, 'items' => [], 'imported' => 0, 'already_completed' => 0, 'conflicts' => 0];

    $payload = [
        'namespace' => $namespace,
        'site_id' => $siteId,
        'reference_hashes' => array_keys($hashToReference),
    ];
    $route = sharedLedgerRemoteConnection();
    if (!empty($route['unavailable'])) {
        return ['success' => false, 'unavailable' => true, 'message' => 'Shared history hub is unavailable'];
    }
    if (($route['mode'] ?? 'local') === 'remote') {
        $connection = is_array($route['connection'] ?? null) ? $route['connection'] : null;
        if (!$connection) return ['success' => false, 'unavailable' => true, 'message' => 'Shared history connection is unavailable'];
        $api = supplierBridgeApiRequest($connection, 'shared_history_import', 'POST', $payload);
        $data = is_array($api['data'] ?? null) ? $api['data'] : [];
        if (empty($api['ok']) || empty($data['success'])) {
            return ['success' => false, 'unavailable' => !empty($api['transport_error']), 'message' => (string) ($data['message'] ?? $api['error'] ?? 'Historical import failed')];
        }
        return $data;
    }
    return storeBridgeSharedHistoryImportAction(0, $payload);
}

function sharedLedgerPerform(array $lease, string $operation): array
{
    $namespace = (string) ($lease['namespace'] ?? '');
    $referenceHash = (string) ($lease['reference_hash'] ?? '');
    $ownerToken = (string) ($lease['owner_token'] ?? '');
    if (!in_array($namespace, storeBridgeSharedNamespaces(), true)
        || preg_match('/^[a-f0-9]{64}$/D', $referenceHash) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $ownerToken) !== 1) {
        return ['success' => false, 'unavailable' => true, 'message' => 'Invalid shared claim'];
    }

    $payload = [
        'namespace' => $namespace,
        'reference_hash' => $referenceHash,
        'owner_token' => $ownerToken,
        'ttl_seconds' => max(in_array($namespace, ['slip_image', 'slip_transaction'], true) ? 15 : 60, min(900, (int) ($lease['ttl_seconds'] ?? 300))),
    ];
    if (($lease['mode'] ?? '') === 'remote') {
        $connection = supplierBridgeGetConnection((int) ($lease['connection_id'] ?? 0));
        if (!$connection || (string) ($connection['status'] ?? '') !== 'active') {
            return ['success' => false, 'unavailable' => true, 'message' => 'Shared website connection is unavailable'];
        }
        $api = supplierBridgeApiRequest($connection, 'shared_' . $operation, 'POST', $payload);
        $data = is_array($api['data'] ?? null) ? $api['data'] : [];
        return [
            'success' => !empty($api['ok']) && !empty($data['success']),
            'duplicate' => (int) ($api['http_code'] ?? 0) === 409 || !empty($data['duplicate']),
            'unavailable' => empty($api['ok']) && (int) ($api['http_code'] ?? 0) !== 409,
            'message' => (string) ($data['message'] ?? $api['error'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'new' => !empty($data['new']),
            'same_owner' => !empty($data['same_owner']),
        ];
    }

    $local = storeBridgeSharedClaimAction(0, $operation, $payload);
    return [
        'success' => !empty($local['success']),
        'duplicate' => !empty($local['duplicate']),
        'unavailable' => (int) ($local['http_code'] ?? 500) >= 500,
        'message' => (string) ($local['message'] ?? ''),
        'status' => (string) ($local['status'] ?? ''),
        'new' => !empty($local['new']),
        'same_owner' => !empty($local['same_owner']),
    ];
}

function sharedLedgerBegin(
    string $namespace,
    string $reference,
    int $ttlSeconds = 300,
    string $ownerScope = ''
): array
{
    $namespace = strtolower(trim($namespace));
    $reference = trim($reference);
    $ownerScope = trim($ownerScope);
    if (!in_array($namespace, storeBridgeSharedNamespaces(), true)
        || $reference === ''
        || strlen($reference) > 2048
        || strlen($ownerScope) > 2048) {
        return ['success' => false, 'unavailable' => true, 'message' => 'Invalid shared reference'];
    }
    // A stable attempt-scoped owner token lets one interrupted request recover
    // its own reservation, while two different images that contain the same
    // transaction reference are still treated as separate concurrent attempts.
    $siteSecret = storeBridgeEncryptionKey(true);
    if (is_string($siteSecret) && strlen($siteSecret) === 32) {
        $ownerToken = hash_hmac(
            'sha256',
            'shared-ledger:' . $namespace . "\0" . $reference . "\0" . $ownerScope,
            $siteSecret
        );
    } else {
        try {
            $ownerToken = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $ownerToken = hash('sha256', uniqid('', true) . microtime(true));
        }
    }
    $route = sharedLedgerRemoteConnection();
    $leaseMode = (string) ($route['mode'] ?? 'local');
    $connectionId = (int) (($route['connection']['id'] ?? 0));
    $fallbackReason = '';
    if (!empty($route['unavailable'])) {
        // Slip deposits are financial operations shared by both databases. A
        // local fallback would let each website accept the same bank transfer
        // independently while the hub is unavailable, so fail closed for slips.
        $financialSlipNamespace = in_array($namespace, ['slip_image', 'slip_transaction', 'slip_history_ready'], true);
        if ($financialSlipNamespace || !sharedLedgerFallbackAllowed() || !storeBridgeEnsureSchema()) {
            return ['success' => false, 'unavailable' => true, 'message' => 'Shared website connection is unavailable'];
        }
        $fallbackReason = 'remote_unavailable';
        $leaseMode = 'local';
        $connectionId = 0;
        sharedLedgerLogFallback($fallbackReason);
    }
    $lease = [
        'namespace' => $namespace,
        'reference_hash' => hash('sha256', $namespace . "\0" . $reference),
        'owner_token' => $ownerToken,
        'ttl_seconds' => max(in_array($namespace, ['slip_image', 'slip_transaction'], true) ? 15 : 60, min(900, $ttlSeconds)),
        'mode' => $leaseMode,
        'connection_id' => $connectionId,
    ];
    $result = sharedLedgerPerform($lease, 'claim');
    if ($fallbackReason !== '') {
        $result['fallback_local'] = true;
        $result['fallback_reason'] = $fallbackReason;
    }
    $result['lease'] = $lease;
    return $result;
}

function sharedLedgerReserve(array $lease): array
{
    return sharedLedgerPerform($lease, 'reserve');
}

function sharedLedgerComplete(array $lease): array
{
    return sharedLedgerPerform($lease, 'complete');
}

function sharedLedgerRelease(array $lease): array
{
    return sharedLedgerPerform($lease, 'release');
}

function supplierBridgeExtractProducts(array $data): array
{
    $candidates = [];
    if (isset($data['products']) && is_array($data['products'])) $candidates[] = $data['products'];
    if (isset($data['data']['products']) && is_array($data['data']['products'])) $candidates[] = $data['data']['products'];
    if (isset($data['data']) && is_array($data['data']) && storeBridgeArrayIsList($data['data'])) $candidates[] = $data['data'];
    if (storeBridgeArrayIsList($data)) $candidates[] = $data;
    foreach ($candidates as $candidate) if ($candidate !== []) return $candidate;
    return [];
}

function supplierBridgeNormalizeProduct(array $item): ?array
{
    $remoteId = trim((string) ($item['remote_product_id'] ?? $item['id'] ?? $item['product_id'] ?? ''));
    $sourceId = trim((string) ($item['source_product_id'] ?? $item['group_id'] ?? $item['parent_id'] ?? $remoteId));
    $name = trim((string) ($item['name'] ?? $item['product_name'] ?? ''));
    $duration = trim((string) ($item['duration'] ?? $item['variant'] ?? 'Standard'));
    $price = $item['api_cost'] ?? $item['price'] ?? $item['unit_price'] ?? null;
    $sourceUser = $item['suggested_user_price'] ?? $item['source_price_user'] ?? null;
    $sourceReseller = $item['suggested_reseller_price'] ?? $item['source_price_reseller'] ?? null;
    $stock = $item['stock'] ?? $item['quantity'] ?? 0;
    if ($remoteId === '' || $sourceId === '' || $name === '' || !is_numeric($price) || !is_numeric($stock)) return null;
    if (strlen($remoteId) > 190 || strlen($sourceId) > 190 || strlen($name) > 255 || strlen($duration) > 120) return null;
    $categories = [];
    $rawCategories = $item['categories'] ?? [];
    if (is_string($rawCategories)) $rawCategories = preg_split('/\s*[,|]\s*/', $rawCategories) ?: [];
    if (!is_array($rawCategories)) $rawCategories = [];
    if ($rawCategories === [] && isset($item['category'])) $rawCategories = [(string) $item['category']];
    foreach ($rawCategories as $category) {
        $category = trim((string) $category);
        if ($category !== '' && strlen($category) <= 255 && !in_array($category, $categories, true)) $categories[] = $category;
        if (count($categories) >= 4) break;
    }
    if ($categories === []) $categories[] = 'API';
    $price = round((float) $price, 2);
    if (!is_finite($price) || $price < 0 || $price > 1000000000) return null;
    $sourceUserPrice = is_numeric($sourceUser) ? round((float) $sourceUser, 2) : null;
    $sourceResellerPrice = is_numeric($sourceReseller) ? round((float) $sourceReseller, 2) : null;
    if ($sourceUserPrice !== null && (!is_finite($sourceUserPrice) || $sourceUserPrice < 0 || $sourceUserPrice > 1000000000)) $sourceUserPrice = null;
    if ($sourceResellerPrice !== null && (!is_finite($sourceResellerPrice) || $sourceResellerPrice < 0 || $sourceResellerPrice > 1000000000)) $sourceResellerPrice = null;
    return [
        'remote_product_id' => $remoteId,
        'remote_source_product_id' => $sourceId,
        'name' => $name,
        'description' => substr(trim((string) ($item['description'] ?? '')), 0, 10000),
        'image_url' => substr(trim((string) ($item['image_url'] ?? $item['image'] ?? '')), 0, 1000),
        'categories' => $categories,
        'platform' => normalizeProductPlatform((string) ($item['platform'] ?? ''), 'both'),
        'duration' => $duration === '' ? 'Standard' : $duration,
        'remote_stock' => max(0, min(1000000000, (int) $stock)),
        'remote_status' => substr(trim((string) ($item['status'] ?? '')), 0, 60),
        'currency' => substr(strtoupper(trim((string) ($item['currency'] ?? 'THB'))), 0, 3),
        'cost_base' => $price,
        'source_price_user' => $sourceUserPrice,
        'source_price_reseller' => $sourceResellerPrice,
        'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
    ];
}

function supplierBridgeSetProductCategoriesAtomic(int $productId, array $categories, bool $manageTransaction = true): bool
{
    global $conn;
    if ($productId < 1) return false;
    $normalized = supplierBridgeNormalizeStorefrontCategories($categories);
    if ($normalized === []) return false;

    if ($manageTransaction) {
        try {
            if (!$conn->begin_transaction()) return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    $delete = null;
    $insert = null;
    $ensure = null;
    $legacy = null;
    try {
        $delete = $conn->prepare('DELETE FROM product_category_links WHERE product_id = ?');
        $insert = $conn->prepare('INSERT INTO product_category_links (product_id, category) VALUES (?, ?)');
        $ensure = $conn->prepare("INSERT INTO categories (name, download_url) VALUES (?, '') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $legacy = $conn->prepare('UPDATE products SET category = ? WHERE id = ?');
        if (!$delete || !$insert || !$ensure || !$legacy) throw new RuntimeException('Unable to prepare product category update');

        $delete->bind_param('i', $productId);
        if (!$delete->execute()) throw new RuntimeException('Unable to clear product categories');
        $delete->close();
        $delete = null;

        foreach ($normalized as $category) {
            $insert->bind_param('is', $productId, $category);
            if (!$insert->execute()) throw new RuntimeException('Unable to link product category');
            $ensure->bind_param('s', $category);
            if (!$ensure->execute()) throw new RuntimeException('Unable to ensure category');
        }
        $insert->close();
        $insert = null;
        $ensure->close();
        $ensure = null;

        $first = $normalized[0];
        $legacy->bind_param('si', $first, $productId);
        if (!$legacy->execute()) throw new RuntimeException('Unable to update legacy product category');
        $legacy->close();
        $legacy = null;

        if ($manageTransaction && !$conn->commit()) throw new RuntimeException('Unable to commit product categories');
        return true;
    } catch (Throwable $e) {
        foreach ([$delete, $insert, $ensure, $legacy] as $statement) {
            if ($statement instanceof mysqli_stmt) {
                try { $statement->close(); } catch (Throwable $ignored) {}
            }
        }
        if ($manageTransaction) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
        return false;
    }
}

function supplierBridgeResolveAudiencePrice(array $connection, array $supplierProduct, string $audience, ?float $currentPrice = null): float
{
    $audience = $audience === 'reseller' ? 'reseller' : 'user';
    $productMode = strtolower(trim((string) ($supplierProduct[$audience . '_price_mode'] ?? 'connection')));
    $mode = $productMode === 'connection'
        ? strtolower(trim((string) ($connection[$audience . '_price_mode'] ?? 'source')))
        : $productMode;
    if (!in_array($mode, ['source', 'markup', 'fixed', 'keep'], true)) $mode = 'source';
    $cost = max(0.0, round((float) ($supplierProduct['cost_base'] ?? 0), 2));
    $sourceField = $audience === 'user' ? 'source_price_user' : 'source_price_reseller';
    $sourcePrice = isset($supplierProduct[$sourceField]) && is_numeric($supplierProduct[$sourceField])
        ? round((float) $supplierProduct[$sourceField], 2) : null;
    $productMarkup = $supplierProduct[$audience . '_markup_percent'] ?? null;
    $markup = is_numeric($productMarkup)
        ? round((float) $productMarkup, 2)
        : round((float) ($connection[$audience . '_markup_percent'] ?? 0), 2);
    $fixed = $supplierProduct[$audience . '_fixed_price'] ?? null;
    $fixedPrice = is_numeric($fixed) ? round((float) $fixed, 2) : null;
    $markupPrice = round($cost * (1 + ($markup / 100)), 2);

    if ($mode === 'keep' && $currentPrice !== null) $price = $currentPrice;
    elseif ($mode === 'fixed' && $fixedPrice !== null) $price = $fixedPrice;
    elseif ($mode === 'source' && $sourcePrice !== null) $price = $sourcePrice;
    elseif ($mode === 'keep' && $sourcePrice !== null) $price = $sourcePrice;
    else $price = $markupPrice;

    if (!is_finite($price)) $price = $cost;
    $price = max(0.0, min(1000000000.0, round((float) $price, 2)));
    if ((int) ($connection['protect_below_cost'] ?? 1) === 1 && $price < $cost) $price = $cost;
    return $price;
}


function supplierBridgeFindAutoMergeLocalProductId(int $supplierProductId, string $name, string $duration): int
{
    global $conn;
    if ($supplierProductId < 1) return 0;
    $name = trim($name);
    if ($name === '') return 0;

    // Match at PRODUCT-GROUP level, not at one duration row. A supplier may add
    // a new 30-day variant after 1/3/7-day variants were already mapped. If we
    // required the new duration to exist locally, the first newly added variant
    // could incorrectly create a second product. Only reuse a product when the
    // normalized supplier name points to one unique, already-managed local
    // product; ambiguous names are deliberately left for the administrator.
    $stmt = $conn->prepare("SELECT DISTINCT scl.local_product_id
        FROM supplier_catalog_links scl
        JOIN supplier_products other ON other.id=scl.supplier_product_id
        JOIN products lp ON lp.id=scl.local_product_id
        LEFT JOIN product_admin_archives paa ON paa.product_id=lp.id
        WHERE other.id<>?
          AND other.enabled=1
          AND other.supplier_removed_at IS NULL
          AND paa.product_id IS NULL
          AND LOWER(TRIM(other.name))=LOWER(TRIM(?))
        ORDER BY scl.local_product_id ASC
        LIMIT 2");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $supplierProductId, $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $id = (int) ($row['local_product_id'] ?? 0);
        if ($id > 0 && !isProductAdminArchived($id)) $ids[$id] = $id;
    }
    $stmt->close();
    return count($ids) === 1 ? (int) reset($ids) : 0;
}

function supplierBridgeFindMatchingLocalVariantId(int $localProductId, string $duration): int
{
    global $conn;
    if ($localProductId < 1) return 0;
    $duration = trim($duration);
    if ($duration === '') return 0;
    $stmt = $conn->prepare("SELECT pv.id
        FROM product_variants pv
        LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
        WHERE pv.product_id=?
          AND pva.variant_id IS NULL
          AND LOWER(TRIM(pv.duration))=LOWER(TRIM(?))
        ORDER BY pv.id ASC LIMIT 3");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $localProductId, $duration);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && !isProductVariantAdminArchived($id)) $ids[$id] = $id;
    }
    $stmt->close();
    // Distinguish "not found" (0) from an existing duplicate conflict (-1).
    // A caller must never interpret two existing 7-day variants as permission
    // to create a third one.
    if (count($ids) > 1) return -1;
    return count($ids) === 1 ? (int) reset($ids) : 0;
}

function supplierBridgeIsPrimaryVariantSource(int $supplierProductId, int $localVariantId): bool
{
    global $conn;
    if ($supplierProductId < 1 || $localVariantId < 1) return false;
    $stmt = $conn->prepare("SELECT scl.supplier_product_id
        FROM supplier_catalog_links scl
        JOIN supplier_products sp ON sp.id=scl.supplier_product_id
                                  AND sp.enabled=1
                                  AND sp.supplier_removed_at IS NULL
        JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
        WHERE scl.local_variant_id=? AND scl.api_fallback_enabled=1
        ORDER BY scl.source_priority ASC, sc.priority ASC, sc.id ASC, sp.id ASC
        LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $localVariantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['supplier_product_id'] ?? 0) === $supplierProductId;
}

function supplierBridgePublishProduct(array $connection, array $supplierProduct, bool $forcePriceSync = false): array
{
    global $conn;
    $connectionId = (int) $connection['id'];
    $supplierProductId = (int) $supplierProduct['id'];
    $sourceRef = (string) $supplierProduct['remote_source_product_id'];
    $localProductId = 0;
    $localVariantId = 0;
    $createdProduct = false;
    $createdVariant = false;
    $createdMapping = false;
    $syncDuration = 1;
    $syncProductDetails = 1;
    $categoryOverride = [];
    $defaultMaxSupplierCost = supplierBridgeProviderRequiresProtectedPurchase((string) ($connection['provider_type'] ?? ''))
        && is_numeric($supplierProduct['cost_base'] ?? null)
        ? round((float) $supplierProduct['cost_base'] + 2.0, 2)
        : null;

    ensureProductCategoryLinksTable();
    ensureCategoriesTable();
    ensureProductPlatformsTable();
    ensureProfitColumns();
    ensureProductArchiveTables();

    $map = $conn->prepare('SELECT local_product_id,sync_details,local_categories_override_json FROM supplier_catalog_products WHERE connection_id = ? AND remote_source_product_id = ? LIMIT 1');
    if ($map) {
        $map->bind_param('is', $connectionId, $sourceRef);
        $map->execute();
        $mapResult = $map->get_result();
        $mapRow = $mapResult ? $mapResult->fetch_assoc() : null;
        $map->close();
        $localProductId = (int) ($mapRow['local_product_id'] ?? 0);
        $syncProductDetails = isset($mapRow['sync_details']) ? (int) $mapRow['sync_details'] : 1;
        $categoryOverride = supplierBridgeNormalizeStorefrontCategories($mapRow['local_categories_override_json'] ?? '');
    }

    // An explicit variant link is more specific than a group/product mapping.
    // Read it before synchronizing product details so a later group merge can
    // never make a manually pinned API source rename/update the wrong product.
    $existingLink = $conn->prepare('SELECT local_product_id,local_variant_id,sync_duration,source_priority FROM supplier_catalog_links WHERE supplier_product_id = ? LIMIT 1');
    if ($existingLink) {
        $existingLink->bind_param('i', $supplierProductId);
        $existingLink->execute();
        $existingLinkResult = $existingLink->get_result();
        $existingLinkRow = $existingLinkResult ? $existingLinkResult->fetch_assoc() : null;
        $existingLink->close();
        $localVariantId = (int) ($existingLinkRow['local_variant_id'] ?? 0);
        $syncDuration = isset($existingLinkRow['sync_duration']) ? (int) $existingLinkRow['sync_duration'] : 1;
        if ($localVariantId > 0 && (int) ($existingLinkRow['local_product_id'] ?? 0) > 0) {
            $localProductId = (int) $existingLinkRow['local_product_id'];
        }
    }

    $conn->begin_transaction();
    try {
        // Lock the upstream PRODUCT GROUP before deciding whether a local
        // product exists. The catalog mapping was read above only as a fast
        // path; another automation/admin request may have created it between
        // that read and this transaction. Re-read after the group lock so two
        // workers cannot create two products for the same 1/3/7/30 group.
        $groupLockStmt = $conn->prepare('SELECT id FROM supplier_products WHERE connection_id=? AND remote_source_product_id=? ORDER BY id ASC FOR UPDATE');
        if (!$groupLockStmt) throw new RuntimeException('Unable to lock supplier product group');
        $groupLockStmt->bind_param('is', $connectionId, $sourceRef);
        if (!$groupLockStmt->execute()) { $groupLockStmt->close(); throw new RuntimeException('Unable to lock supplier product group'); }
        $groupLockStmt->store_result();
        $groupLockStmt->close();

        if ($localProductId < 1) {
            $freshMap = $conn->prepare('SELECT local_product_id,sync_details,local_categories_override_json FROM supplier_catalog_products WHERE connection_id=? AND remote_source_product_id=? LIMIT 1');
            if (!$freshMap) throw new RuntimeException('Unable to recheck API group mapping');
            $freshMap->bind_param('is', $connectionId, $sourceRef);
            if (!$freshMap->execute()) { $freshMap->close(); throw new RuntimeException('Unable to recheck API group mapping'); }
            $freshMapResult = $freshMap->get_result();
            $freshMapRow = $freshMapResult ? $freshMapResult->fetch_assoc() : null;
            $freshMap->close();
            $freshLocalProductId = (int) ($freshMapRow['local_product_id'] ?? 0);
            if ($freshLocalProductId > 0) {
                $localProductId = $freshLocalProductId;
                $syncProductDetails = isset($freshMapRow['sync_details']) ? (int) $freshMapRow['sync_details'] : $syncProductDetails;
                $categoryOverride = supplierBridgeNormalizeStorefrontCategories($freshMapRow['local_categories_override_json'] ?? '');
            }
        }

        if ($localProductId < 1 || !getProductById($localProductId) || isProductAdminArchived($localProductId)) {
            // A second Store Bridge connection frequently exposes the same item
            // with a different remote ID. Reuse the already-managed storefront
            // product only when the existing API mapping matches both name and
            // duration uniquely. Otherwise create a new product as before.
            $autoMergeProductId = supplierBridgeFindAutoMergeLocalProductId(
                $supplierProductId,
                (string) ($supplierProduct['name'] ?? ''),
                (string) ($supplierProduct['duration'] ?? '')
            );
            if ($autoMergeProductId > 0) {
                $localProductId = $autoMergeProductId;
                $syncProductDetails = 0;
                $saveMap = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id, remote_source_product_id, local_product_id, sync_details) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=0,local_categories_override_json=NULL');
                if (!$saveMap) throw new RuntimeException('Unable to prepare automatic product mapping');
                $saveMap->bind_param('isi', $connectionId, $sourceRef, $localProductId);
                if (!$saveMap->execute()) { $saveMap->close(); throw new RuntimeException('Unable to save automatic product mapping'); }
                $saveMap->close();
                $createdMapping = true;
            } else {
                $name = (string) $supplierProduct['name'];
                $description = (string) ($supplierProduct['description'] ?? '');
                $image = (string) ($supplierProduct['image_url'] ?? '');
                $categories = $categoryOverride !== []
                    ? $categoryOverride
                    : supplierBridgeResolveLocalCategories($connectionId, $supplierProduct['categories_json'] ?? '');
                $firstCategory = (string) ($categories[0] ?? 'API');
                $insert = $conn->prepare("INSERT INTO products (name, description, image, category, download_url, status) VALUES (?, ?, ?, ?, '', 'active')");
                if (!$insert) throw new RuntimeException('Unable to prepare local product');
                $insert->bind_param('ssss', $name, $description, $image, $firstCategory);
                if (!$insert->execute()) { $insert->close(); throw new RuntimeException('Unable to create local product'); }
                $localProductId = (int) $conn->insert_id;
                $createdProduct = true;
                $insert->close();
                $saveMap = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id, remote_source_product_id, local_product_id, sync_details) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=1');
                if (!$saveMap) throw new RuntimeException('Unable to prepare product mapping');
                $saveMap->bind_param('isi', $connectionId, $sourceRef, $localProductId);
                if (!$saveMap->execute()) { $saveMap->close(); throw new RuntimeException('Unable to save product mapping'); }
                $saveMap->close();
                if ($categories !== [] && !supplierBridgeSetProductCategoriesAtomic($localProductId, $categories, false)) throw new RuntimeException('Unable to save local categories');
                if (!setProductPlatform($localProductId, (string) ($supplierProduct['platform'] ?? 'both'))) throw new RuntimeException('Unable to save local platform');
            }
        } elseif ((int) ($connection['sync_details'] ?? 0) === 1 && $syncProductDetails === 1) {
            $name = (string) $supplierProduct['name'];
            $description = (string) ($supplierProduct['description'] ?? '');
            $image = (string) ($supplierProduct['image_url'] ?? '');
            $update = $conn->prepare("UPDATE products SET name=?, description=?, image=IF(? <> '', ?, image), updated_at=NOW() WHERE id=?");
            if (!$update) throw new RuntimeException('Unable to prepare local product update');
            $update->bind_param('ssssi', $name, $description, $image, $image, $localProductId);
            if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to update local product'); }
            $update->close();
            $categories = $categoryOverride !== []
                ? $categoryOverride
                : supplierBridgeResolveLocalCategories($connectionId, $supplierProduct['categories_json'] ?? '');
            if ($categories !== [] && !supplierBridgeSetProductCategoriesAtomic($localProductId, $categories, false)) throw new RuntimeException('Unable to update local categories');
            if (!setProductPlatform($localProductId, (string) ($supplierProduct['platform'] ?? 'both'))) throw new RuntimeException('Unable to update local platform');
        }

        // Serialize every variant decision for this local product. Admin edits,
        // automation, and another supplier connection can all discover the same
        // missing duration at nearly the same time. Locking the parent product
        // makes the subsequent duration check + INSERT atomic across those paths.
        if ($localProductId > 0) {
            $productLock = $conn->prepare('SELECT id,status FROM products WHERE id=? LIMIT 1 FOR UPDATE');
            if (!$productLock) throw new RuntimeException('Unable to lock local product for variant synchronization');
            $productLock->bind_param('i', $localProductId);
            if (!$productLock->execute()) { $productLock->close(); throw new RuntimeException('Unable to lock local product for variant synchronization'); }
            $productLockResult = $productLock->get_result();
            $lockedProduct = $productLockResult ? $productLockResult->fetch_assoc() : null;
            $productLock->close();
            if (!$lockedProduct || isProductAdminArchived($localProductId)) throw new RuntimeException('Mapped local product is unavailable');
        }

        $duration = trim((string) $supplierProduct['duration']);
        if ($duration === '') throw new RuntimeException('Supplier variant duration is empty');
        if ($localVariantId < 1 && $localProductId > 0) {
            $matchingVariantId = supplierBridgeFindMatchingLocalVariantId($localProductId, $duration);
            if ($matchingVariantId < 0) {
                throw new RuntimeException('Duplicate local variant duration detected for ' . $duration . '; resolve the duplicate before publishing this API variant');
            }
            if ($matchingVariantId > 0) {
                $localVariantId = $matchingVariantId;
                $syncDuration = 0;
                $saveLink = $conn->prepare('INSERT INTO supplier_catalog_links (supplier_product_id,local_product_id,local_variant_id,api_fallback_enabled,source_priority,sync_duration) VALUES (?,?,?,1,100,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),local_variant_id=VALUES(local_variant_id),api_fallback_enabled=1,sync_duration=0');
                if (!$saveLink) throw new RuntimeException('Unable to prepare automatic variant mapping');
                $saveLink->bind_param('iii', $supplierProductId, $localProductId, $localVariantId);
                if (!$saveLink->execute()) { $saveLink->close(); throw new RuntimeException('Unable to save automatic variant mapping'); }
                $saveLink->close();
                $createdMapping = true;
            }
        }

        $currentVariant = null;
        if ($localVariantId > 0 && !isProductVariantAdminArchived($localVariantId)) {
            $currentStmt = $conn->prepare('SELECT id,product_id,duration,price_user,price_reseller,cost_price,status FROM product_variants WHERE id=? LIMIT 1 FOR UPDATE');
            if ($currentStmt) {
                $currentStmt->bind_param('i', $localVariantId);
                $currentStmt->execute();
                $currentResult = $currentStmt->get_result();
                $currentVariant = $currentResult ? $currentResult->fetch_assoc() : null;
                $currentStmt->close();
            }
            if ($currentVariant && (int) $currentVariant['product_id'] !== $localProductId) {
                $localProductId = (int) $currentVariant['product_id'];
            }
        }

        $cost = round((float) $supplierProduct['cost_base'], 2);
        $currentUser = $currentVariant ? (float) $currentVariant['price_user'] : null;
        $currentReseller = $currentVariant ? (float) $currentVariant['price_reseller'] : null;
        $userPrice = supplierBridgeResolveAudiencePrice($connection, $supplierProduct, 'user', $currentUser);
        $resellerPrice = supplierBridgeResolveAudiencePrice($connection, $supplierProduct, 'reseller', $currentReseller);

        if (!$currentVariant) {
            $insertVariant = $conn->prepare("INSERT INTO product_variants (product_id, duration, price_user, price_reseller, cost_price, status) VALUES (?, ?, ?, ?, ?, 'active')");
            if (!$insertVariant) throw new RuntimeException('Unable to prepare local variant');
            $insertVariant->bind_param('isddd', $localProductId, $duration, $userPrice, $resellerPrice, $cost);
            if (!$insertVariant->execute()) { $insertVariant->close(); throw new RuntimeException('Unable to create local variant'); }
            $localVariantId = (int) $conn->insert_id;
            $createdVariant = true;
            $insertVariant->close();
            $saveLink = $conn->prepare('INSERT INTO supplier_catalog_links (supplier_product_id,local_product_id,local_variant_id,api_fallback_enabled,source_priority,sync_duration) VALUES (?,?,?,1,100,1) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),local_variant_id=VALUES(local_variant_id),api_fallback_enabled=1,sync_duration=VALUES(sync_duration)');
            if (!$saveLink) throw new RuntimeException('Unable to prepare variant mapping');
            $saveLink->bind_param('iii', $supplierProductId, $localProductId, $localVariantId);
            if (!$saveLink->execute()) { $saveLink->close(); throw new RuntimeException('Unable to save variant mapping'); }
            $saveLink->close();
        } else {
            // Multiple APIs may point at the same local variant. Only the primary
            // source is allowed to synchronize the shared local price/cost fields;
            // a backup source must never overwrite them merely because it synced
            // later. Checkout still validates the selected source's real cost.
            $primarySource = supplierBridgeIsPrimaryVariantSource($supplierProductId, $localVariantId);
            if ($primarySource) {
                $applyPrices = $forcePriceSync || (int) ($connection['sync_prices'] ?? 0) === 1;
                $durationSql = $syncDuration === 1 ? 'duration=?,' : '';
                if ($applyPrices) {
                    $sql = "UPDATE product_variants SET {$durationSql} price_user=?,price_reseller=?,cost_price=?,updated_at=NOW() WHERE id=? AND product_id=?";
                    $updateVariant = $conn->prepare($sql);
                    if (!$updateVariant) throw new RuntimeException('Unable to prepare local variant update');
                    if ($syncDuration === 1) $updateVariant->bind_param('sdddii', $duration, $userPrice, $resellerPrice, $cost, $localVariantId, $localProductId);
                    else $updateVariant->bind_param('dddii', $userPrice, $resellerPrice, $cost, $localVariantId, $localProductId);
                } else {
                    $sql = "UPDATE product_variants SET {$durationSql} cost_price=?,updated_at=NOW() WHERE id=? AND product_id=?";
                    $updateVariant = $conn->prepare($sql);
                    if (!$updateVariant) throw new RuntimeException('Unable to prepare local variant update');
                    if ($syncDuration === 1) $updateVariant->bind_param('sdii', $duration, $cost, $localVariantId, $localProductId);
                    else $updateVariant->bind_param('dii', $cost, $localVariantId, $localProductId);
                }
                if (!$updateVariant->execute()) { $updateVariant->close(); throw new RuntimeException('Unable to update local variant'); }
                $updateVariant->close();
            }
        }
        if ($defaultMaxSupplierCost !== null && $localVariantId > 0) {
            $maxCostStmt = $conn->prepare('UPDATE supplier_catalog_links SET max_supplier_cost=CASE WHEN max_supplier_cost IS NULL OR max_supplier_cost<=0 THEN ? ELSE max_supplier_cost END WHERE supplier_product_id=?');
            if (!$maxCostStmt) throw new RuntimeException('Unable to prepare automatic max supplier cost');
            $maxCostStmt->bind_param('di', $defaultMaxSupplierCost, $supplierProductId);
            if (!$maxCostStmt->execute()) { $maxCostStmt->close(); throw new RuntimeException('Unable to save automatic max supplier cost'); }
            $maxCostStmt->close();
        }
        $conn->commit();
        return [
            'success' => true,
            'local_product_id' => $localProductId,
            'local_variant_id' => $localVariantId,
            'catalog_changed' => $createdProduct || $createdVariant || $createdMapping,
            'user_price' => $userPrice,
            'reseller_price' => $resellerPrice,
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Supplier product publish failed: ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Providers may explicitly prove that an order never became durable.
 *
 * Sakazuki supplies this proof in the upstream response. Session-based
 * suppliers without verified purchase history set an internal top-level proof
 * only while execution is still before the non-idempotent purchase request.
 * Preflight failures may therefore be safe to refund/fail over, but a lost or
 * malformed response after purchase must go to manual review.
 */
function supplierBridgeProviderProvesOrderNotCreated(array $connection, array $api): bool
{
    $providerType = strtolower(trim((string) ($connection['provider_type'] ?? '')));
    if (supplierBridgeProviderRequiresProtectedPurchase($providerType)) {
        return ($api['order_not_created_proven'] ?? null) === true;
    }
    if ($providerType !== 'sakazuki_v1') return false;
    if (!empty($api['transport_error']) || (int) ($api['http_code'] ?? 0) < 400) return false;
    $data = is_array($api['data'] ?? null) ? $api['data'] : null;
    if (!is_array($data) || (($data['success'] ?? null) !== false)) return false;
    if (($data['order_created'] ?? null) !== false || ($data['definitive_failure'] ?? null) !== true) return false;
    $code = strtolower(trim((string) ($data['code'] ?? $api['error_code'] ?? '')));
    return in_array($code, ['schema_not_ready','order_not_created','temporary_database_conflict'], true);
}

function supplierBridgeClassifyApiFailure(array $api): string
{
    $errorCode = strtolower(trim((string) ($api['error_code'] ?? '')));
    $httpCode = (int) ($api['http_code'] ?? 0);
    if ($errorCode === 'credential_decryption_failed') return 'credential';
    if (in_array($errorCode, ['endpoint_invalid', 'curl_unavailable', 'product_not_found', 'invalid_product_id'], true)) return 'configuration';
    if (in_array($errorCode, ['invalid_json', 'response_too_large'], true)) return 'provider_response';
    if (!empty($api['transport_error']) || $httpCode === 0) return 'transport';
    if ($httpCode === 408 || $httpCode === 425 || $httpCode === 429 || $httpCode >= 500) return 'provider_temporary';
    return 'configuration';
}

function supplierBridgeLogInventoryCheck(
    int $connectionId,
    int $supplierProductId,
    string $remoteProductId,
    string $checkType,
    int $quantity,
    bool $success,
    ?int $stock,
    int $httpCode,
    string $errorCode,
    string $errorMessage,
    int $durationMs
): void {
    global $conn;
    if (!storeBridgeEnsureSchema()) return;
    $remoteProductId = substr(trim($remoteProductId), 0, 190);
    $checkType = substr(trim($checkType), 0, 40);
    $quantity = max(1, min(100, $quantity));
    $successValue = $success ? 1 : 0;
    $httpCode = max(0, min(65535, $httpCode));
    $errorCode = substr(trim($errorCode), 0, 80);
    $errorMessage = substr(trim($errorMessage), 0, 1000);
    $durationMs = max(0, min(4294967295, $durationMs));
    $stmt = $conn->prepare('INSERT INTO supplier_inventory_logs
        (connection_id,supplier_product_id,remote_product_id,check_type,requested_quantity,success,confirmed_stock,http_code,error_code,error_message,duration_ms)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) return;
    $stockValue = $stock;
    $stmt->bind_param('iissiiiissi', $connectionId, $supplierProductId, $remoteProductId, $checkType, $quantity, $successValue, $stockValue, $httpCode, $errorCode, $errorMessage, $durationMs);
    $inserted = $stmt->execute();
    $logId = $inserted ? (int) $conn->insert_id : 0;
    $stmt->close();
    // Keep diagnostics useful without turning a two-site integration into an
    // archaeological dig through millions of old health checks.
    if ($logId > 0 && $logId % 250 === 0) {
        try {
            $conn->query('DELETE FROM supplier_inventory_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY) LIMIT 2000');
        } catch (Throwable $e) {
            // Logging cleanup must never affect checkout.
        }
    }
}

function supplierBridgeRecordInventoryFailure(int $supplierProductId, string $errorCode, string $message): void
{
    global $conn;
    if ($supplierProductId < 1) return;
    $errorCode = substr(trim($errorCode), 0, 80);
    $message = substr(trim($message), 0, 1000);
    $stmt = $conn->prepare('UPDATE supplier_products
                            SET inventory_last_error_code=?,inventory_last_error_message=?,inventory_last_error_at=NOW()
                            WHERE id=?');
    if (!$stmt) return;
    $stmt->bind_param('ssi', $errorCode, $message, $supplierProductId);
    $stmt->execute();
    $stmt->close();
}

function supplierBridgeApplyOrderInventoryFailure(int $supplierProductId, string $errorCode, string $message): void
{
    global $conn;
    $errorCode = strtolower(substr(trim($errorCode), 0, 80));
    $message = substr(trim($message), 0, 1000);
    if ($supplierProductId < 1) return;
    if (in_array($errorCode, ['out_of_stock', 'product_not_found'], true)) {
        $status = $errorCode === 'product_not_found' ? 'removed' : 'out_of_stock';
        $sql = $errorCode === 'product_not_found'
            ? 'UPDATE supplier_products SET remote_stock=0,remote_status=?,supplier_removed_at=NOW(),inventory_checked_at=NOW(),inventory_last_error_code=?,inventory_last_error_message=?,inventory_last_error_at=NOW() WHERE id=?'
            : 'UPDATE supplier_products SET remote_stock=0,remote_status=?,inventory_checked_at=NOW(),inventory_last_error_code=?,inventory_last_error_message=?,inventory_last_error_at=NOW() WHERE id=?';
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sssi', $status, $errorCode, $message, $supplierProductId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
}

function supplierBridgeExactInventoryUnsupported(array $api): bool
{
    $httpCode = (int) ($api['http_code'] ?? 0);
    if (!in_array($httpCode, [400, 404, 405], true)) return false;
    $message = strtolower(trim((string) ($api['error'] ?? '')));
    $data = is_array($api['data'] ?? null) ? $api['data'] : [];
    foreach (['message', 'error', 'detail'] as $field) {
        if (isset($data[$field]) && is_scalar($data[$field])) $message .= ' ' . strtolower(trim((string) $data[$field]));
    }
    return strpos($message, 'invalid api action') !== false
        || strpos($message, 'invalid action') !== false
        || strpos($message, 'method not allowed') !== false;
}

function supplierBridgeExtractInventoryResponse(array $response, string $expectedRemoteProductId): ?array
{
    $payload = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    if (isset($payload['data']) && is_array($payload['data'])) $payload = $payload['data'];
    $productId = trim((string) ($payload['product_id'] ?? $payload['remote_product_id'] ?? ''));
    $stockValue = $payload['stock'] ?? $payload['available_stock'] ?? null;
    $costValue = $payload['api_cost'] ?? $payload['price'] ?? $payload['unit_price'] ?? null;
    if ($productId === '' || !hash_equals($expectedRemoteProductId, $productId) || !is_numeric($stockValue)) return null;
    $stock = max(0, (int) $stockValue);
    $status = strtolower(trim((string) ($payload['status'] ?? ($stock > 0 ? 'available' : 'out_of_stock'))));
    if ($status === '') $status = $stock > 0 ? 'available' : 'out_of_stock';
    return [
        'remote_product_id' => $productId,
        'stock' => $stock,
        'status' => substr($status, 0, 60),
        'confirmed_at' => trim((string) ($payload['confirmed_at'] ?? '')),
        'revision' => substr(trim((string) ($payload['revision'] ?? '')), 0, 190),
        'currency' => substr(strtoupper(trim((string) ($payload['currency'] ?? ''))), 0, 3),
        'cost_base' => is_numeric($costValue) ? round(max(0.0, (float) $costValue), 2) : null,
    ];
}

function supplierBridgeConfirmProductInventory(int $supplierProductId, int $quantity = 1, bool $allowCatalogueFallback = true, bool $retryTransient = true): array
{
    global $conn;
    $quantity = max(1, min(100, $quantity));
    $product = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$product || (string) ($product['connection_status'] ?? '') !== 'active' || (int) ($product['enabled'] ?? 0) !== 1) {
        return [
            'success' => false,
            'error_code' => 'supplier_product_unavailable',
            'failure_class' => 'configuration',
            'message' => 'Supplier product is unavailable',
        ];
    }
    $connectionId = (int) ($product['connection_id'] ?? 0);
    $remoteProductId = trim((string) ($product['remote_product_id'] ?? ''));
    $connection = supplierBridgeGetConnection($connectionId);
    if (!$connection || $remoteProductId === '') {
        return [
            'success' => false,
            'error_code' => 'supplier_connection_unavailable',
            'failure_class' => 'configuration',
            'message' => 'Supplier connection is unavailable',
        ];
    }

    $started = microtime(true);
    $api = supplierBridgeApiRequest($connection, 'inventory', 'GET', ['product_id' => $remoteProductId], $retryTransient ? 0 : 1);
    $durationMs = (int) round((microtime(true) - $started) * 1000);
    if (empty($api['ok']) || !is_array($api['data'] ?? null)) {
        if ($allowCatalogueFallback && supplierBridgeExactInventoryUnsupported($api)) {
            $fallback = supplierBridgeRefreshConnection($connectionId, true, $remoteProductId);
            $fallbackSuccess = !empty($fallback['success']) && !empty($fallback['target_confirmed']);
            $saved = supplierBridgeGetSupplierProduct($supplierProductId);
            $stock = $saved ? max(0, (int) ($saved['remote_stock'] ?? 0)) : null;
            $errorCode = $fallbackSuccess ? '' : (string) ($fallback['error_code'] ?? 'target_not_confirmed');
            $message = $fallbackSuccess ? '' : (string) ($fallback['message'] ?? 'Supplier inventory could not be confirmed');
            supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'catalogue_fallback', $quantity, $fallbackSuccess, $stock, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
            if ($fallbackSuccess) {
                return [
                    'success' => true,
                    'confirmed' => true,
                    'check_type' => 'catalogue_fallback',
                    'stock' => $stock,
                    'status' => $stock > 0 ? 'available' : 'out_of_stock',
                    'required_quantity' => $quantity,
                    'can_purchase' => $stock >= $quantity,
                    'http_code' => (int) ($api['http_code'] ?? 0),
                ];
            }
            supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
            return $fallback + ['check_type' => 'catalogue_fallback'];
        }

        $failureClass = supplierBridgeClassifyApiFailure($api);
        $errorCode = (string) ($api['error_code'] ?? 'inventory_request_failed');
        $message = (string) ($api['error'] ?? 'Supplier inventory request failed');
        supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
        supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, null, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
        return [
            'success' => false,
            'confirmed' => false,
            'check_type' => 'exact',
            'error_code' => $errorCode,
            'failure_class' => $failureClass,
            'transport_error' => !empty($api['transport_error']),
            'http_code' => (int) ($api['http_code'] ?? 0),
            'attempts' => (int) ($api['attempts'] ?? 1),
            'message' => $message,
        ];
    }

    $inventory = supplierBridgeExtractInventoryResponse($api['data'], $remoteProductId);
    if ($inventory === null) {
        $errorCode = 'invalid_inventory_response';
        $message = 'Supplier inventory response is missing the requested product or stock';
        supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
        supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, null, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
        return [
            'success' => false,
            'confirmed' => false,
            'check_type' => 'exact',
            'error_code' => $errorCode,
            'failure_class' => 'provider_response',
            'http_code' => (int) ($api['http_code'] ?? 0),
            'message' => $message,
        ];
    }

    $inventoryCurrency = (string) ($inventory['currency'] ?? '');
    $localCurrency = storeBridgeCurrency();
    if ($inventoryCurrency !== '' && $inventoryCurrency !== $localCurrency) {
        $errorCode = 'inventory_currency_mismatch';
        $message = 'Supplier inventory currency ' . $inventoryCurrency . ' does not match this website currency ' . $localCurrency;
        supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
        supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, null, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
        return [
            'success' => false,
            'confirmed' => false,
            'check_type' => 'exact',
            'error_code' => $errorCode,
            'failure_class' => 'configuration',
            'http_code' => (int) ($api['http_code'] ?? 0),
            'message' => $message,
        ];
    }

    $stock = (int) $inventory['stock'];
    $status = (string) $inventory['status'];
    $revision = (string) $inventory['revision'];
    $update = $conn->prepare('UPDATE supplier_products
                              SET remote_stock=?,remote_status=?,supplier_removed_at=NULL,inventory_checked_at=NOW(),
                                  inventory_revision=?,inventory_last_success_at=NOW(),inventory_last_error_code=NULL,
                                  inventory_last_error_message=NULL,inventory_last_error_at=NULL
                              WHERE id=? AND connection_id=? AND remote_product_id=?');
    if (!$update) {
        $errorCode = 'inventory_db_prepare_failed';
        $message = 'Unable to prepare local inventory update';
        supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
        supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, $stock, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
        return ['success' => false, 'confirmed' => false, 'error_code' => $errorCode, 'failure_class' => 'local_database', 'message' => $message];
    }
    $update->bind_param('issiis', $stock, $status, $revision, $supplierProductId, $connectionId, $remoteProductId);
    $updated = $update->execute() && $update->affected_rows >= 0;
    $dbError = $update->error;
    $update->close();
    if (!$updated) {
        $errorCode = 'inventory_db_update_failed';
        $message = $dbError !== '' ? ('Unable to save inventory: ' . $dbError) : 'Unable to save inventory';
        supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
        supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, $stock, (int) ($api['http_code'] ?? 0), $errorCode, $message, $durationMs);
        return ['success' => false, 'confirmed' => false, 'error_code' => $errorCode, 'failure_class' => 'local_database', 'message' => $message];
    }

    if (isset($inventory['cost_base']) && is_numeric($inventory['cost_base'])) {
        $currentCost = round(max(0.0, (float) $inventory['cost_base']), 2);
        $costUpdate = $conn->prepare('UPDATE supplier_products SET cost_base=? WHERE id=? AND connection_id=?');
        if (!$costUpdate) {
            $errorCode = 'inventory_cost_db_prepare_failed';
            $message = 'Unable to prepare current supplier cost update';
            supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
            supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, $stock, (int) ($api['http_code'] ?? 200), $errorCode, $message, $durationMs);
            return ['success' => false, 'confirmed' => false, 'error_code' => $errorCode, 'failure_class' => 'local_database', 'message' => $message];
        }
        $costUpdate->bind_param('dii', $currentCost, $supplierProductId, $connectionId);
        $costSaved = $costUpdate->execute();
        $costError = $costUpdate->error;
        $costUpdate->close();
        if (!$costSaved) {
            $errorCode = 'inventory_cost_db_update_failed';
            $message = $costError !== '' ? ('Unable to save current supplier cost: ' . $costError) : 'Unable to save current supplier cost';
            supplierBridgeRecordInventoryFailure($supplierProductId, $errorCode, $message);
            supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, false, $stock, (int) ($api['http_code'] ?? 200), $errorCode, $message, $durationMs);
            return ['success' => false, 'confirmed' => false, 'error_code' => $errorCode, 'failure_class' => 'local_database', 'message' => $message];
        }
    }
    supplierBridgeLogInventoryCheck($connectionId, $supplierProductId, $remoteProductId, 'exact', $quantity, true, $stock, (int) ($api['http_code'] ?? 200), '', '', $durationMs);
    return [
        'success' => true,
        'confirmed' => true,
        'check_type' => 'exact',
        'stock' => $stock,
        'status' => $status,
        'revision' => $revision,
        'required_quantity' => $quantity,
        'can_purchase' => $stock >= $quantity,
        'http_code' => (int) ($api['http_code'] ?? 200),
        'duration_ms' => $durationMs,
    ];
}

function supplierBridgeRefreshConnection(int $connectionId, bool $force = true, string $requiredRemoteProductId = ''): array
{
    global $conn;
    if ($connectionId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Invalid supplier connection'];
    $requiredRemoteProductId = substr(trim($requiredRemoteProductId), 0, 190);
    $lockName = storeBridgeLockName('sync', (string) $connectionId);
    $waitSeconds = $force ? 10 : 0;
    $lock = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    if (!$lock) return ['success' => false, 'message' => 'Supplier synchronization lock is unavailable'];
    $lock->bind_param('si', $lockName, $waitSeconds);
    $lock->execute();
    $lockResult = $lock->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lock->close();
    if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
        return $force
            ? ['success' => false, 'busy' => true, 'failure_class' => 'provider_temporary', 'error_code' => 'sync_busy', 'message' => 'This supplier is already synchronizing']
            : ['success' => true, 'cached' => true, 'busy' => true, 'updated' => 0, 'published' => 0];
    }
    try {
        return supplierBridgeRefreshConnectionUnlocked($connectionId, $force, $requiredRemoteProductId);
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) {
            $release->bind_param('s', $lockName);
            $release->execute();
            $release->close();
        }
    }
}

function supplierBridgeRefreshConnectionUnlocked(int $connectionId, bool $force = true, string $requiredRemoteProductId = ''): array
{
    global $conn;
    $requiredRemoteProductId = substr(trim($requiredRemoteProductId), 0, 190);
    $connection = supplierBridgeGetConnection($connectionId);
    if (!$connection || (string) $connection['status'] !== 'active') {
        return ['success' => false, 'failure_class' => 'configuration', 'error_code' => 'connection_inactive', 'message' => 'Supplier connection is inactive'];
    }
    if (!$force && $requiredRemoteProductId === '' && !empty($connection['last_success_at'])) {
        $age = time() - (int) strtotime((string) $connection['last_success_at']);
        $providerType = strtolower(trim((string) ($connection['provider_type'] ?? '')));
        $minimumRefreshAge = $providerType === 'starkmods_v1' ? 180 : 30;
        if ($age >= 0 && $age < $minimumRefreshAge) return ['success' => true, 'cached' => true, 'updated' => 0, 'target_confirmed' => false];
    }

    $api = supplierBridgeApiRequest($connection, 'products', 'GET');
    if (empty($api['ok']) || !is_array($api['data'] ?? null)) {
        $message = substr((string) ($api['error'] ?? 'Supplier product request failed'), 0, 1000);
        $errorCode = substr((string) ($api['error_code'] ?? 'product_request_failed'), 0, 80);
        $httpCode = (int) ($api['http_code'] ?? 0);
        $failureClass = supplierBridgeClassifyApiFailure($api);
        $storedMessage = '[' . $errorCode . '] ' . $message;
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_error=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('si', $storedMessage, $connectionId); $stmt->execute(); $stmt->close(); }
        return [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'failure_class' => $failureClass,
            'transport_error' => !empty($api['transport_error']),
            'http_code' => $httpCode,
            'attempts' => (int) ($api['attempts'] ?? 1),
            'target_confirmed' => false,
        ];
    }

    $catalogueRecognized = (isset($api['data']['products']) && is_array($api['data']['products']))
        || (isset($api['data']['data']['products']) && is_array($api['data']['data']['products']))
        || (isset($api['data']['data']) && is_array($api['data']['data']) && storeBridgeArrayIsList($api['data']['data']))
        || storeBridgeArrayIsList($api['data']);
    if (!$catalogueRecognized) {
        $message = 'Supplier response does not contain a recognized product catalogue';
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_error=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('si', $message, $connectionId); $stmt->execute(); $stmt->close(); }
        return ['success' => false, 'failure_class' => 'provider_response', 'error_code' => 'catalogue_unrecognized', 'message' => $message, 'target_confirmed' => false];
    }

    $responseCurrency = strtoupper(trim((string) ($api['data']['currency'] ?? $api['data']['data']['currency'] ?? '')));
    $localCurrency = storeBridgeCurrency();
    if ($responseCurrency !== '' && $responseCurrency !== $localCurrency) {
        $message = 'Supplier currency ' . $responseCurrency . ' does not match this website currency ' . $localCurrency;
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_error=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('si', $message, $connectionId); $stmt->execute(); $stmt->close(); }
        return ['success' => false, 'failure_class' => 'configuration', 'error_code' => 'currency_mismatch', 'message' => $message, 'target_confirmed' => false];
    }

    $rawProducts = supplierBridgeExtractProducts($api['data']);
    $normalized = [];
    $invalidRows = 0;
    $truncated = false;
    foreach ($rawProducts as $item) {
        if (!is_array($item)) { $invalidRows++; continue; }
        $product = supplierBridgeNormalizeProduct($item);
        if ($product && strtoupper((string) $product['currency']) !== $localCurrency) {
            $message = 'Supplier product currency does not match this website currency';
            $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_error=? WHERE id=?');
            if ($stmt) { $stmt->bind_param('si', $message, $connectionId); $stmt->execute(); $stmt->close(); }
            return ['success' => false, 'failure_class' => 'configuration', 'error_code' => 'product_currency_mismatch', 'message' => $message, 'target_confirmed' => false];
        }
        if (!$product) { $invalidRows++; continue; }
        $normalized[$product['remote_product_id']] = $product;
        if (count($normalized) >= 5000) { $truncated = count($rawProducts) > 5000; break; }
    }
    if ($normalized === [] && $rawProducts !== []) {
        return ['success' => false, 'failure_class' => 'provider_response', 'error_code' => 'no_valid_products', 'message' => 'Supplier returned no valid products', 'received' => count($rawProducts), 'invalid' => $invalidRows, 'target_confirmed' => false];
    }

    $existingBeforeSync = [];
    $existingResult = $conn->prepare('SELECT id, remote_product_id FROM supplier_products WHERE connection_id = ? AND supplier_removed_at IS NULL');
    if ($existingResult) {
        $existingResult->bind_param('i', $connectionId);
        if ($existingResult->execute()) {
            $rows = $existingResult->get_result();
            while ($row = $rows ? $rows->fetch_assoc() : null) {
                if (!$row) break;
                $existingBeforeSync[(int) $row['id']] = (string) $row['remote_product_id'];
            }
        }
        $existingResult->close();
    }
    $missingExisting = [];
    foreach ($existingBeforeSync as $existingRemoteId) {
        if (!isset($normalized[$existingRemoteId])) $missingExisting[$existingRemoteId] = true;
    }
    $confirmationSeen = [];
    $removalConfirmed = true;
    if ($missingExisting !== []) {
        $confirmationApi = supplierBridgeApiRequest($connection, 'products', 'GET');
        $confirmationData = is_array($confirmationApi['data'] ?? null) ? $confirmationApi['data'] : [];
        $confirmationRecognized = !empty($confirmationApi['ok']) && (
            (isset($confirmationData['products']) && is_array($confirmationData['products']))
            || (isset($confirmationData['data']['products']) && is_array($confirmationData['data']['products']))
            || (isset($confirmationData['data']) && is_array($confirmationData['data']) && storeBridgeArrayIsList($confirmationData['data']))
            || storeBridgeArrayIsList($confirmationData)
        );
        $confirmationCurrency = strtoupper(trim((string) ($confirmationData['currency'] ?? $confirmationData['data']['currency'] ?? '')));
        if (!$confirmationRecognized || ($confirmationCurrency !== '' && $confirmationCurrency !== $localCurrency)) {
            $removalConfirmed = false;
        } else {
            $confirmationRaw = supplierBridgeExtractProducts($confirmationData);
            $usableConfirmationItems = 0;
            foreach ($confirmationRaw as $item) {
                if (!is_array($item)) continue;
                $product = supplierBridgeNormalizeProduct($item);
                if (!$product || strtoupper((string) $product['currency']) !== $localCurrency) continue;
                $confirmationSeen[(string) $product['remote_product_id']] = true;
                $usableConfirmationItems++;
            }
            if ($confirmationRaw !== [] && $usableConfirmationItems === 0) $removalConfirmed = false;
        }
    }

    $upsert = $conn->prepare("INSERT INTO supplier_products
        (connection_id, remote_product_id, remote_source_product_id, name, description, image_url, categories_json, platform, duration, remote_stock, remote_status, currency, cost_base, source_price_user, source_price_reseller, enabled, supplier_removed_at, raw_json, inventory_checked_at, inventory_last_success_at, inventory_last_error_code, inventory_last_error_message, inventory_last_error_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, ?, NOW(), NOW(), NULL, NULL, NULL)
        ON DUPLICATE KEY UPDATE remote_source_product_id=VALUES(remote_source_product_id), name=VALUES(name), description=VALUES(description), image_url=VALUES(image_url), categories_json=VALUES(categories_json), platform=VALUES(platform), duration=VALUES(duration), remote_stock=VALUES(remote_stock), remote_status=VALUES(remote_status), currency=VALUES(currency), cost_base=VALUES(cost_base), source_price_user=VALUES(source_price_user), source_price_reseller=VALUES(source_price_reseller), supplier_removed_at=NULL, raw_json=VALUES(raw_json), inventory_checked_at=NOW(), inventory_last_success_at=NOW(), inventory_last_error_code=NULL, inventory_last_error_message=NULL, inventory_last_error_at=NULL");
    if (!$upsert) return ['success' => false, 'failure_class' => 'local_database', 'error_code' => 'product_sync_prepare_failed', 'message' => 'Unable to prepare supplier product sync', 'target_confirmed' => false];

    $seen = [];
    $updated = 0;
    $failed = 0;
    $published = 0;
    $publishFailed = 0;
    $failureSamples = [];
    $targetConfirmed = false;
    foreach ($normalized as $product) {
        $categoriesJson = json_encode($product['categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $sourceUserPrice = $product['source_price_user'];
        $sourceResellerPrice = $product['source_price_reseller'];
        $upsert->bind_param('issssssssissddds',
            $connectionId,
            $product['remote_product_id'], $product['remote_source_product_id'], $product['name'], $product['description'], $product['image_url'], $categoriesJson,
            $product['platform'], $product['duration'], $product['remote_stock'], $product['remote_status'], $product['currency'], $product['cost_base'], $sourceUserPrice, $sourceResellerPrice, $product['raw_json']
        );
        if (!$upsert->execute()) {
            $failed++;
            if (count($failureSamples) < 5) $failureSamples[] = (string) $product['remote_product_id'] . ': ' . substr($upsert->error, 0, 300);
            continue;
        }
        $seen[$product['remote_product_id']] = true;
        $updated++;
        if ($requiredRemoteProductId !== '' && hash_equals($requiredRemoteProductId, (string) $product['remote_product_id'])) $targetConfirmed = true;

        $idStmt = $conn->prepare('SELECT sp.*,CASE WHEN l.supplier_product_id IS NULL THEN 0 ELSE 1 END AS is_mapped FROM supplier_products sp LEFT JOIN supplier_catalog_links l ON l.supplier_product_id=sp.id WHERE sp.connection_id=? AND sp.remote_product_id=? LIMIT 1');
        if (!$idStmt) {
            $publishFailed++;
            continue;
        }
        $idStmt->bind_param('is', $connectionId, $product['remote_product_id']);
        $idStmt->execute();
        $idResult = $idStmt->get_result();
        $saved = $idResult ? $idResult->fetch_assoc() : null;
        $idStmt->close();
        $shouldPublish = $saved && (int) ($saved['enabled'] ?? 0) === 1
            && ((int) ($connection['auto_publish'] ?? 0) === 1 || (int) ($saved['is_mapped'] ?? 0) === 1);
        if ($shouldPublish) {
            $publish = supplierBridgePublishProduct($connection, $saved);
            if (empty($publish['success'])) {
                $publishFailed++;
                if (count($failureSamples) < 5) $failureSamples[] = (string) $product['remote_product_id'] . ': publish failed';
            } elseif (!empty($publish['catalog_changed'])) {
                $published++;
            }
        }
    }
    $upsert->close();

    $removed = 0;
    if ($existingBeforeSync !== [] && $removalConfirmed) {
        $remove = $conn->prepare("UPDATE supplier_products SET remote_stock=0, remote_status='removed', supplier_removed_at=NOW() WHERE id=?");
        foreach ($existingBeforeSync as $id => $existingRemoteId) {
            if (!isset($seen[$existingRemoteId]) && !isset($confirmationSeen[$existingRemoteId]) && $remove) {
                $remove->bind_param('i', $id);
                if ($remove->execute() && $remove->affected_rows > 0) $removed++;
            }
        }
        if ($remove) $remove->close();
    }

    if ($requiredRemoteProductId !== '' && !$targetConfirmed) {
        $message = 'The requested supplier product was not confirmed in the catalogue response';
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_error=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('si', $message, $connectionId); $stmt->execute(); $stmt->close(); }
        return [
            'success' => false,
            'failure_class' => 'provider_response',
            'error_code' => 'target_not_confirmed',
            'message' => $message,
            'target_confirmed' => false,
            'received' => count($rawProducts),
            'normalized' => count($normalized),
            'invalid' => $invalidRows,
            'updated' => $updated,
            'failed' => $failed,
            'published' => $published,
            'publish_failed' => $publishFailed,
            'removed' => $removed,
            'truncated' => $truncated,
            'failure_samples' => $failureSamples,
        ];
    }

    $balance = null;
    $currency = null;
    $balanceError = '';
    $providerType = strtolower(trim((string) ($connection['provider_type'] ?? '')));
    $catalogueBalance = $api['data']['balance'] ?? null;
    $catalogueCurrency = $api['data']['currency'] ?? null;
    if ($providerType === 'starkmods_v1' && is_numeric($catalogueBalance)) {
        // StarkMods renders balance and catalogue in the same authenticated HTML
        // page. Reuse that snapshot so the one-minute sync does not log in and
        // download the full catalogue twice.
        $balance = round((float) $catalogueBalance, 2);
        if (is_scalar($catalogueCurrency)) $currency = substr(strtoupper(trim((string) $catalogueCurrency)), 0, 3);
    } else {
        $balanceApi = supplierBridgeApiRequest($connection, 'balance', 'GET');
        if (!empty($balanceApi['ok']) && is_array($balanceApi['data'] ?? null)) {
            $balanceValue = $balanceApi['data']['balance'] ?? $balanceApi['data']['data']['balance'] ?? null;
            $currencyValue = $balanceApi['data']['currency'] ?? $balanceApi['data']['data']['currency'] ?? null;
            if (is_numeric($balanceValue)) $balance = round((float) $balanceValue, 2);
            if (is_scalar($currencyValue)) $currency = substr(strtoupper(trim((string) $currencyValue)), 0, 3);
        } else {
            $balanceError = substr((string) ($balanceApi['error'] ?? 'Balance refresh failed'), 0, 500);
        }
    }

    $partial = $failed > 0 || $publishFailed > 0 || $invalidRows > 0 || $truncated;
    $syncMessage = '';
    if ($partial) {
        $syncMessage = '[partial_sync] failed=' . $failed . '; publish_failed=' . $publishFailed . '; invalid=' . $invalidRows . '; truncated=' . ($truncated ? '1' : '0');
        if ($failureSamples !== []) $syncMessage .= ' samples=' . implode(' | ', $failureSamples);
        $syncMessage = substr($syncMessage, 0, 1000);
    } elseif ($balanceError !== '') {
        $syncMessage = '[balance_refresh_failed] ' . $balanceError;
    }

    if ($balance !== null && $currency !== null && $currency !== '') {
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_success_at=NOW(), last_error=?, last_balance=?, currency=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('sdsi', $syncMessage, $balance, $currency, $connectionId); $stmt->execute(); $stmt->close(); }
    } elseif ($balance !== null) {
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_success_at=NOW(), last_error=?, last_balance=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('sdi', $syncMessage, $balance, $connectionId); $stmt->execute(); $stmt->close(); }
    } elseif ($currency !== null && $currency !== '') {
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_success_at=NOW(), last_error=?, currency=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('ssi', $syncMessage, $currency, $connectionId); $stmt->execute(); $stmt->close(); }
    } else {
        $stmt = $conn->prepare('UPDATE supplier_connections SET last_sync_at=NOW(), last_success_at=NOW(), last_error=? WHERE id=?');
        if ($stmt) { $stmt->bind_param('si', $syncMessage, $connectionId); $stmt->execute(); $stmt->close(); }
    }

    return [
        'success' => true,
        'partial_failure' => $partial,
        'target_confirmed' => $requiredRemoteProductId !== '' ? $targetConfirmed : false,
        'received' => count($rawProducts),
        'normalized' => count($normalized),
        'invalid' => $invalidRows,
        'updated' => $updated,
        'failed' => $failed,
        'published' => $published,
        'publish_failed' => $publishFailed,
        'removed' => $removed,
        'truncated' => $truncated,
        'failure_samples' => $failureSamples,
        'balance' => $balance,
        'balance_error' => $balanceError,
        'currency' => $currency,
    ];
}

function supplierBridgeRefreshAllEnabled(bool $force = false): array
{
    $results = [
        'success' => true,
        'attempted' => 0,
        'succeeded' => 0,
        'updated' => 0,
        'published' => 0,
        'failed' => 0,
        'partial_connections' => 0,
        'product_failures' => 0,
        'publish_failures' => 0,
        'invalid_rows' => 0,
    ];
    foreach (supplierBridgeGetConnections() as $connection) {
        if ((string) $connection['status'] !== 'active') continue;
        $results['attempted']++;
        $refresh = supplierBridgeRefreshConnection((int) $connection['id'], $force);
        if (!empty($refresh['success'])) {
            $results['succeeded']++;
            $results['updated'] += (int) ($refresh['updated'] ?? 0);
            $results['published'] += (int) ($refresh['published'] ?? 0);
            $results['product_failures'] += (int) ($refresh['failed'] ?? 0);
            $results['publish_failures'] += (int) ($refresh['publish_failed'] ?? 0);
            $results['invalid_rows'] += (int) ($refresh['invalid'] ?? 0);
            if (!empty($refresh['partial_failure'])) $results['partial_connections']++;
        } else {
            $results['failed']++;
            $results['success'] = false;
        }
    }
    if ($results['partial_connections'] > 0) {
        $results['message'] = 'Partial supplier synchronization: connections=' . $results['partial_connections']
            . ', product_failures=' . $results['product_failures']
            . ', publish_failures=' . $results['publish_failures']
            . ', invalid_rows=' . $results['invalid_rows'];
    }
    return $results;
}

function supplierBridgeGetSupplierProduct(int $supplierProductId): ?array
{
    global $conn;
    if ($supplierProductId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare('SELECT sp.*,c.name AS connection_name,c.status AS connection_status,c.provider_type,c.endpoint_url,c.auto_publish,c.sync_details,c.sync_prices,c.user_price_mode AS connection_user_price_mode,c.reseller_price_mode AS connection_reseller_price_mode,c.user_markup_percent AS connection_user_markup_percent,c.reseller_markup_percent AS connection_reseller_markup_percent,c.protect_below_cost,c.id AS connection_id FROM supplier_products sp JOIN supplier_connections c ON c.id=sp.connection_id WHERE sp.id=? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $supplierProductId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return null;
    $row['user_price_mode'] = (string) ($row['user_price_mode'] ?? 'connection');
    $row['reseller_price_mode'] = (string) ($row['reseller_price_mode'] ?? 'connection');
    $row['user_markup_percent'] = $row['user_markup_percent'] ?? null;
    $row['reseller_markup_percent'] = $row['reseller_markup_percent'] ?? null;
    $row['user_fixed_price'] = $row['user_fixed_price'] ?? null;
    $row['reseller_fixed_price'] = $row['reseller_fixed_price'] ?? null;
    $row['user_price_mode_connection'] = $row['connection_user_price_mode'];
    $row['reseller_price_mode_connection'] = $row['connection_reseller_price_mode'];
    $row['user_markup_percent_connection'] = $row['connection_user_markup_percent'];
    $row['reseller_markup_percent_connection'] = $row['connection_reseller_markup_percent'];
    return $row;
}

function supplierBridgeGetManagedProducts(array $filters = [], int $limit = 500, int $offset = 0): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $limit = max(1, min(2000, $limit));
    $offset = max(0, $offset);
    $where = ['1=1'];

    $connectionId = max(0, (int) ($filters['connection_id'] ?? 0));
    if ($connectionId > 0) $where[] = 'sp.connection_id=' . $connectionId;

    $state = strtolower(trim((string) ($filters['state'] ?? 'all')));
    if ($state === 'mapped') $where[] = 'l.local_variant_id IS NOT NULL';
    elseif ($state === 'unmapped') $where[] = 'l.local_variant_id IS NULL';
    elseif ($state === 'enabled') $where[] = 'sp.enabled=1';
    elseif ($state === 'disabled') $where[] = 'sp.enabled=0';
    elseif ($state === 'removed') $where[] = 'sp.supplier_removed_at IS NOT NULL';
    elseif ($state === 'in_stock') $where[] = 'sp.supplier_removed_at IS NULL AND sp.remote_stock>0';
    elseif ($state === 'out_of_stock') $where[] = 'sp.supplier_removed_at IS NULL AND sp.remote_stock<=0';

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $encodedCategory = json_encode($category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $categoryNeedle = $conn->real_escape_string(is_string($encodedCategory) ? $encodedCategory : $category);
        $where[] = "sp.categories_json LIKE '%{$categoryNeedle}%'";
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $escaped = $conn->real_escape_string(substr($search, 0, 190));
        $where[] = "(sp.name LIKE '%{$escaped}%'
            OR sp.duration LIKE '%{$escaped}%'
            OR sp.remote_product_id LIKE '%{$escaped}%'
            OR sp.remote_source_product_id LIKE '%{$escaped}%'
            OR sp.platform LIKE '%{$escaped}%'
            OR sp.categories_json LIKE '%{$escaped}%')";
    }

    $sql = "SELECT sp.*,c.name AS connection_name,c.status AS connection_status,
                   c.user_price_mode AS connection_user_price_mode,
                   c.reseller_price_mode AS connection_reseller_price_mode,
                   c.user_markup_percent AS connection_user_markup_percent,
                   c.reseller_markup_percent AS connection_reseller_markup_percent,
                   c.protect_below_cost,c.sync_prices,c.priority AS connection_priority,
                   l.local_product_id,l.local_variant_id,l.api_fallback_enabled,l.source_priority,l.max_supplier_cost,l.sync_duration,
                   scp.local_product_id AS group_local_product_id,
                   scp.sync_details AS group_sync_details,scp.local_categories_override_json AS group_category_override_json,
                   lp.name AS local_product_name,lp.status AS local_product_status,
                   gp.name AS group_local_product_name,gp.status AS group_local_product_status,
                   lv.duration AS local_duration,lv.price_user AS local_price_user,
                   lv.price_reseller AS local_price_reseller,lv.cost_price AS local_cost_price,
                   lv.status AS local_variant_status
            FROM supplier_products sp
            JOIN supplier_connections c ON c.id=sp.connection_id
            LEFT JOIN supplier_catalog_links l ON l.supplier_product_id=sp.id
            LEFT JOIN supplier_catalog_products scp ON scp.connection_id=sp.connection_id AND scp.remote_source_product_id=sp.remote_source_product_id
            LEFT JOIN products lp ON lp.id=l.local_product_id
            LEFT JOIN products gp ON gp.id=scp.local_product_id
            LEFT JOIN product_variants lv ON lv.id=l.local_variant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY CASE WHEN sp.supplier_removed_at IS NOT NULL THEN 2
                          WHEN sp.remote_stock>0 THEN 0 ELSE 1 END,
                     c.priority ASC,sp.name ASC,sp.remote_source_product_id ASC,sp.id ASC
            LIMIT {$limit} OFFSET {$offset}";
    $result = $conn->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$row) {
        $row['categories'] = supplierBridgeNormalizeCategories($row['categories_json'] ?? '');
        $row['category_text'] = implode(' · ', $row['categories']);
        $row['group_category_override'] = supplierBridgeNormalizeStorefrontCategories($row['group_category_override_json'] ?? '');
        $row['stock_state'] = (int) ($row['remote_stock'] ?? 0) > 0 ? 'in_stock' : 'out_of_stock';
    }
    unset($row);
    return $rows;
}

function supplierBridgeGetManagedProductCategories(int $connectionId = 0): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $connectionId = max(0, $connectionId);
    $sql = "SELECT categories_json FROM supplier_products WHERE supplier_removed_at IS NULL";
    if ($connectionId > 0) $sql .= ' AND connection_id=' . $connectionId;
    $sql .= ' ORDER BY name ASC';

    $result = $conn->query($sql);
    $categories = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach (supplierBridgeNormalizeCategories($row['categories_json'] ?? '') as $category) {
                $categories[$category] = true;
            }
        }
    }
    $names = array_keys($categories);
    natcasesort($names);
    return array_values($names);
}

function supplierBridgeGetManagedProductStats(array $filters = []): array
{
    global $conn;
    $empty = [
        'total' => 0,
        'in_stock' => 0,
        'out_of_stock' => 0,
        'mapped' => 0,
        'unmapped' => 0,
        'enabled' => 0,
        'disabled' => 0,
        'removed' => 0,
    ];
    if (!storeBridgeEnsureSchema()) return $empty;

    $where = ['1=1'];
    $connectionId = max(0, (int) ($filters['connection_id'] ?? 0));
    if ($connectionId > 0) $where[] = 'sp.connection_id=' . $connectionId;

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $encodedCategory = json_encode($category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $categoryNeedle = $conn->real_escape_string(is_string($encodedCategory) ? $encodedCategory : $category);
        $where[] = "sp.categories_json LIKE '%{$categoryNeedle}%'";
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $escaped = $conn->real_escape_string(substr($search, 0, 190));
        $where[] = "(sp.name LIKE '%{$escaped}%'
            OR sp.duration LIKE '%{$escaped}%'
            OR sp.remote_product_id LIKE '%{$escaped}%'
            OR sp.remote_source_product_id LIKE '%{$escaped}%'
            OR sp.platform LIKE '%{$escaped}%'
            OR sp.categories_json LIKE '%{$escaped}%')";
    }

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN sp.supplier_removed_at IS NULL AND sp.remote_stock>0 THEN 1 ELSE 0 END) AS in_stock,
                SUM(CASE WHEN sp.supplier_removed_at IS NULL AND sp.remote_stock<=0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN l.local_variant_id IS NOT NULL THEN 1 ELSE 0 END) AS mapped,
                SUM(CASE WHEN l.local_variant_id IS NULL THEN 1 ELSE 0 END) AS unmapped,
                SUM(CASE WHEN sp.enabled=1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN sp.enabled=0 THEN 1 ELSE 0 END) AS disabled,
                SUM(CASE WHEN sp.supplier_removed_at IS NOT NULL THEN 1 ELSE 0 END) AS removed
            FROM supplier_products sp
            LEFT JOIN supplier_catalog_links l ON l.supplier_product_id=sp.id
            WHERE " . implode(' AND ', $where);
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) return $empty;
    foreach ($empty as $key => $_value) $empty[$key] = (int) ($row[$key] ?? 0);
    return $empty;
}

function supplierBridgeGetLocalCatalog(): array
{
    global $conn;
    ensureProductArchiveTables();
    ensureProductCategoryLinksTable();

    $sql = "SELECT p.id AS product_id,p.name AS product_name,p.status AS product_status,
                   p.category AS legacy_category,pcl.categories_concat,
                   pv.id AS variant_id,pv.duration,pv.price_user,pv.price_reseller,
                   pv.cost_price,pv.status AS variant_status
            FROM products p
            LEFT JOIN product_admin_archives paa ON paa.product_id=p.id
            LEFT JOIN product_variants pv ON pv.product_id=p.id
            LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
            LEFT JOIN (
                SELECT product_id,GROUP_CONCAT(category ORDER BY id SEPARATOR '||') AS categories_concat
                FROM product_category_links
                GROUP BY product_id
            ) pcl ON pcl.product_id=p.id
            WHERE paa.product_id IS NULL AND pva.variant_id IS NULL
            ORDER BY p.name ASC,pv.id ASC";
    $result = $conn->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$row) {
        $row['categories'] = supplierBridgeNormalizeCategories(
            (string) ($row['categories_concat'] ?? ''),
            (string) ($row['legacy_category'] ?? '')
        );
        $row['category_text'] = implode(' · ', $row['categories']);
    }
    unset($row);
    return $rows;
}

function supplierBridgeNullableNumber($value, float $min, float $max, string $label): array
{
    if ($value === null || trim((string) $value) === '') return ['success' => true, 'value' => null];
    if (!is_numeric($value)) return ['success' => false, 'message' => $label . ' is invalid'];
    $number = round((float) $value, 2);
    if (!is_finite($number) || $number < $min || $number > $max) return ['success' => false, 'message' => $label . ' is invalid'];
    return ['success' => true, 'value' => $number];
}

function supplierBridgeUpdateProductPolicy(int $supplierProductId, array $input, bool $applyNow = true): array
{
    global $conn;
    $product = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$product) return ['success' => false, 'message' => 'Supplier product was not found'];
    $userMode = strtolower(trim((string) ($input['user_price_mode'] ?? 'connection')));
    $resellerMode = strtolower(trim((string) ($input['reseller_price_mode'] ?? 'connection')));
    if (!in_array($userMode, supplierBridgeProductPriceModes(), true) || !in_array($resellerMode, supplierBridgeProductPriceModes(), true)) return ['success' => false, 'message' => 'Product price mode is invalid'];
    $userMarkupResult = supplierBridgeNullableNumber($input['user_markup_percent'] ?? null, 0, 10000, 'User markup');
    $resellerMarkupResult = supplierBridgeNullableNumber($input['reseller_markup_percent'] ?? null, 0, 10000, 'Reseller markup');
    $userFixedResult = supplierBridgeNullableNumber($input['user_fixed_price'] ?? null, 0, 1000000000, 'User fixed price');
    $resellerFixedResult = supplierBridgeNullableNumber($input['reseller_fixed_price'] ?? null, 0, 1000000000, 'Reseller fixed price');
    foreach ([$userMarkupResult,$resellerMarkupResult,$userFixedResult,$resellerFixedResult] as $validation) if (empty($validation['success'])) return $validation;
    $userMarkup = $userMarkupResult['value'];
    $resellerMarkup = $resellerMarkupResult['value'];
    $userFixed = $userFixedResult['value'];
    $resellerFixed = $resellerFixedResult['value'];
    if ($userMode === 'fixed' && $userFixed === null) return ['success' => false, 'message' => 'User fixed price is required'];
    if ($resellerMode === 'fixed' && $resellerFixed === null) return ['success' => false, 'message' => 'Reseller fixed price is required'];
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $stmt = $conn->prepare('UPDATE supplier_products SET user_price_mode=?,reseller_price_mode=?,user_markup_percent=?,reseller_markup_percent=?,user_fixed_price=?,reseller_fixed_price=?,enabled=? WHERE id=?');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare product pricing update'];
    $stmt->bind_param('ssddddii', $userMode, $resellerMode, $userMarkup, $resellerMarkup, $userFixed, $resellerFixed, $enabled, $supplierProductId);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'message' => 'Unable to save product pricing']; }
    $stmt->close();
    $fallback = $enabled;
    $link = $conn->prepare('UPDATE supplier_catalog_links SET api_fallback_enabled=? WHERE supplier_product_id=?');
    if ($link) { $link->bind_param('ii', $fallback, $supplierProductId); $link->execute(); $link->close(); }
    if (!$applyNow || !$enabled) return ['success' => true];
    $publish = supplierBridgePublishById($supplierProductId, true);
    if (empty($publish['success']) && empty($publish['unmapped'])) return $publish;
    return ['success' => true, 'publish' => $publish];
}

function supplierBridgePublishById(int $supplierProductId, bool $forcePriceSync = true): array
{
    global $conn;
    $product = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$product) return ['success' => false, 'message' => 'Supplier product was not found'];
    if ((int) ($product['enabled'] ?? 0) !== 1) return ['success' => false, 'message' => 'Supplier product is disabled'];
    $connection = supplierBridgeGetConnection((int) $product['connection_id']);
    if (!$connection) return ['success' => false, 'message' => 'Supplier connection was not found'];
    return supplierBridgePublishProduct($connection, $product, $forcePriceSync);
}


function supplierBridgePublishSelectedProducts(array $supplierProductIds, bool $forcePriceSync = true): array
{
    $ids = [];
    foreach ($supplierProductIds as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
        if (count($ids) >= 200) break;
    }
    if ($ids === []) return ['success' => false, 'published' => 0, 'failed' => 0, 'message' => 'No supplier products were selected'];

    $published = 0;
    $failed = 0;
    $errors = [];
    foreach ($ids as $id) {
        $result = supplierBridgePublishById($id, $forcePriceSync);
        if (!empty($result['success'])) $published++;
        else {
            $failed++;
            if (count($errors) < 5) $errors[] = '#' . $id . ': ' . (string) ($result['message'] ?? 'publish failed');
        }
    }
    return [
        'success' => $failed === 0,
        'published' => $published,
        'failed' => $failed,
        'message' => $failed > 0 ? implode('; ', $errors) : 'Selected supplier products published',
    ];
}

function supplierBridgeSyncGroupVariants(int $connectionId, string $sourceRef, bool $forcePriceSync = false): array
{
    global $conn;
    $sourceRef = trim($sourceRef);
    if ($connectionId < 1 || $sourceRef === '') {
        return ['success' => false, 'message' => 'Supplier product group is invalid'];
    }

    // Serialize the WHOLE group operation, not merely each individual variant.
    // This closes the admin-vs-automation/double-click window where two workers
    // can both decide that a newly added duration (for example 30 days) is
    // missing before either mapping becomes visible.
    $lockName = 'bridge:catalog-group:' . substr(hash('sha256', $connectionId . '|' . $sourceRef), 0, 40);
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 8) AS acquired');
    if (!$lockStmt) return ['success' => false, 'message' => 'Unable to prepare API group synchronization lock'];
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();
    if ((int) ($lockRow['acquired'] ?? 0) !== 1) {
        return ['success' => false, 'busy' => true, 'message' => 'This API product group is already being synchronized. Please wait a moment and retry.'];
    }

    try {
        return supplierBridgeSyncGroupVariantsUnlocked($connectionId, $sourceRef, $forcePriceSync);
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) {
            $release->bind_param('s', $lockName);
            try { $release->execute(); } catch (Throwable $ignored) {}
            $release->close();
        }
    }
}

function supplierBridgeSyncGroupVariantsUnlocked(int $connectionId, string $sourceRef, bool $forcePriceSync = false): array
{
    global $conn;
    $sourceRef = trim($sourceRef);
    if ($connectionId < 1 || $sourceRef === '' || !storeBridgeEnsureSchema()) {
        return ['success' => false, 'message' => 'Supplier product group is invalid'];
    }

    // Work from the full upstream group, not from whichever row the admin
    // happened to click. This is what makes 1/3/7 -> 1/3/7/30 create only the
    // missing 30-day variant instead of republishing each row blindly.
    $groupStmt = $conn->prepare("SELECT sp.id,sp.duration
        FROM supplier_products sp
        WHERE sp.connection_id=? AND sp.remote_source_product_id=?
          AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
        ORDER BY sp.id ASC");
    if (!$groupStmt) return ['success' => false, 'message' => 'Unable to load supplier product group'];
    $groupStmt->bind_param('is', $connectionId, $sourceRef);
    $groupStmt->execute();
    $groupResult = $groupStmt->get_result();
    $groupRows = $groupResult ? $groupResult->fetch_all(MYSQLI_ASSOC) : [];
    $groupStmt->close();
    if ($groupRows === []) return ['success' => false, 'message' => 'No enabled supplier variants are available in this group'];

    $mapStmt = $conn->prepare('SELECT local_product_id FROM supplier_catalog_products WHERE connection_id=? AND remote_source_product_id=? LIMIT 1');
    if (!$mapStmt) return ['success' => false, 'message' => 'Unable to inspect API group mapping'];
    $mapStmt->bind_param('is', $connectionId, $sourceRef);
    $mapStmt->execute();
    $mapResult = $mapStmt->get_result();
    $mapRow = $mapResult ? $mapResult->fetch_assoc() : null;
    $mapStmt->close();
    $localProductId = (int) ($mapRow['local_product_id'] ?? 0);

    // If the group has never been mapped, publish one member first. The normal
    // product-level resolver will reuse one uniquely matching managed product or
    // create a new API-only product. Once that establishes the group mapping,
    // every remaining supplier variant is synchronized into the same product.
    if ($localProductId < 1 || !getProductById($localProductId) || isProductAdminArchived($localProductId)) {
        $bootstrapId = (int) ($groupRows[0]['id'] ?? 0);
        $bootstrap = supplierBridgePublishById($bootstrapId, $forcePriceSync);
        if (empty($bootstrap['success'])) return $bootstrap;
        $localProductId = (int) ($bootstrap['local_product_id'] ?? 0);
        if ($localProductId < 1) return ['success' => false, 'message' => 'Unable to resolve the local product for this API group'];
    }

    ensureProductArchiveTables();
    $dupStmt = $conn->prepare("SELECT LOWER(TRIM(pv.duration)) AS duration_key,COUNT(*) AS c,GROUP_CONCAT(pv.id ORDER BY pv.id) AS ids
        FROM product_variants pv
        LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
        WHERE pv.product_id=? AND pva.variant_id IS NULL
        GROUP BY LOWER(TRIM(pv.duration))
        HAVING COUNT(*)>1
        LIMIT 5");
    if (!$dupStmt) return ['success' => false, 'message' => 'Unable to inspect local variants'];
    $dupStmt->bind_param('i', $localProductId);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();
    $duplicateSamples = [];
    while ($dupRow = $dupResult ? $dupResult->fetch_assoc() : null) {
        if (!$dupRow) break;
        $duplicateSamples[] = (string) ($dupRow['duration_key'] ?? '?') . ' [' . (string) ($dupRow['ids'] ?? '') . ']';
    }
    $dupStmt->close();
    if ($duplicateSamples !== []) {
        return [
            'success' => false,
            'conflict' => true,
            'local_product_id' => $localProductId,
            'message' => 'Duplicate local variant durations must be resolved before API synchronization: ' . implode(', ', $duplicateSamples),
        ];
    }

    $beforeStmt = $conn->prepare("SELECT LOWER(TRIM(pv.duration)) AS duration_key
        FROM product_variants pv
        LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
        WHERE pv.product_id=? AND pva.variant_id IS NULL");
    $before = [];
    if ($beforeStmt) {
        $beforeStmt->bind_param('i', $localProductId);
        $beforeStmt->execute();
        $beforeResult = $beforeStmt->get_result();
        while ($row = $beforeResult ? $beforeResult->fetch_assoc() : null) {
            if (!$row) break;
            $key = trim((string) ($row['duration_key'] ?? ''));
            if ($key !== '') $before[$key] = true;
        }
        $beforeStmt->close();
    }

    $published = 0;
    $failed = 0;
    $errors = [];
    foreach ($groupRows as $groupRow) {
        $id = (int) ($groupRow['id'] ?? 0);
        if ($id < 1) continue;
        $result = supplierBridgePublishById($id, $forcePriceSync);
        if (!empty($result['success'])) {
            $published++;
            continue;
        }
        $failed++;
        if (count($errors) < 5) $errors[] = '#' . $id . ': ' . (string) ($result['message'] ?? 'publish failed');
    }

    $afterStmt = $conn->prepare("SELECT LOWER(TRIM(pv.duration)) AS duration_key
        FROM product_variants pv
        LEFT JOIN product_variant_admin_archives pva ON pva.variant_id=pv.id
        WHERE pv.product_id=? AND pva.variant_id IS NULL");
    $after = [];
    if ($afterStmt) {
        $afterStmt->bind_param('i', $localProductId);
        $afterStmt->execute();
        $afterResult = $afterStmt->get_result();
        while ($row = $afterResult ? $afterResult->fetch_assoc() : null) {
            if (!$row) break;
            $key = trim((string) ($row['duration_key'] ?? ''));
            if ($key !== '') $after[$key] = true;
        }
        $afterStmt->close();
    }
    $createdDurations = array_values(array_diff(array_keys($after), array_keys($before)));
    sort($createdDurations, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'success' => $failed === 0,
        'local_product_id' => $localProductId,
        'supplier_variants' => count($groupRows),
        'published' => $published,
        'failed' => $failed,
        'created_variants' => count($createdDurations),
        'created_durations' => $createdDurations,
        'message' => $failed > 0 ? implode('; ', $errors) : 'Supplier product group synchronized',
    ];
}


function supplierBridgeCreateApiOnlyProduct(
    int $supplierProductId,
    $localCategories = [],
    int $sourcePriority = 100
): array {
    global $conn;
    $supplier = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$supplier) return ['success' => false, 'message' => 'Supplier product was not found'];
    if ((int) ($supplier['enabled'] ?? 0) !== 1 || !empty($supplier['supplier_removed_at'])) {
        return ['success' => false, 'message' => 'Supplier product is disabled or removed'];
    }
    $connectionId = (int) ($supplier['connection_id'] ?? 0);
    $sourceRef = trim((string) ($supplier['remote_source_product_id'] ?? ''));
    if ($connectionId < 1 || $sourceRef === '') return ['success' => false, 'message' => 'Supplier product group is invalid'];
    $sourcePriority = max(-100000, min(100000, $sourcePriority));

    ensureProductCategoryLinksTable();
    ensureCategoriesTable();
    ensureProductPlatformsTable();
    ensureProfitColumns();
    ensureProductArchiveTables();

    $manualCategoryOverride = supplierBridgeNormalizeStorefrontCategories($localCategories);
    $categories = $manualCategoryOverride;
    if ($categories === []) $categories = supplierBridgeResolveLocalCategories($connectionId, $supplier['categories_json'] ?? '');
    if ($categories === []) $categories = ['API'];
    $categoryOverrideJson = null;
    if ($manualCategoryOverride !== []) {
        $encodedOverride = json_encode($manualCategoryOverride, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encodedOverride)) return ['success' => false, 'message' => 'Unable to encode category override'];
        $categoryOverrideJson = $encodedOverride;
    }
    $name = trim((string) ($supplier['name'] ?? ''));
    if ($name === '') return ['success' => false, 'message' => 'Supplier product name is empty'];
    $description = trim((string) ($supplier['description'] ?? ''));
    $image = trim((string) ($supplier['image_url'] ?? ''));
    $firstCategory = $categories[0];
    $platform = normalizeProductPlatform((string) ($supplier['platform'] ?? ''), 'both');

    $ids = [];
    $conn->begin_transaction();
    try {
        // Serialize creation for every variant in this remote product group.
        // Two admin requests (or a double click) must never create two local
        // products before the unique group mapping is visible to the other one.
        $groupLock = $conn->prepare('SELECT id FROM supplier_products WHERE connection_id=? AND remote_source_product_id=? AND enabled=1 AND supplier_removed_at IS NULL ORDER BY id ASC FOR UPDATE');
        if (!$groupLock) throw new RuntimeException('Unable to lock API product group');
        $groupLock->bind_param('is', $connectionId, $sourceRef);
        $groupLock->execute();
        $groupResult = $groupLock->get_result();
        while ($row = $groupResult ? $groupResult->fetch_assoc() : null) {
            if (!$row) break;
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $groupLock->close();
        if ($ids === []) throw new RuntimeException('No enabled supplier variants are available for this API product group');

        // Recheck the group mapping only after the group lock is held. This is
        // the concurrency-safe duplicate check; an earlier read alone is not.
        $mappedStmt = $conn->prepare('SELECT local_product_id FROM supplier_catalog_products WHERE connection_id=? AND remote_source_product_id=? LIMIT 1 FOR UPDATE');
        if (!$mappedStmt) throw new RuntimeException('Unable to inspect existing API group mapping');
        $mappedStmt->bind_param('is', $connectionId, $sourceRef);
        $mappedStmt->execute();
        $mappedResult = $mappedStmt->get_result();
        $mappedRow = $mappedResult ? $mappedResult->fetch_assoc() : null;
        $mappedStmt->close();
        $existingProductId = (int) ($mappedRow['local_product_id'] ?? 0);
        if ($existingProductId > 0 && getProductById($existingProductId) && !isProductAdminArchived($existingProductId)) {
            throw new RuntimeException('This API product group is already mapped to local product #' . $existingProductId);
        }

        $insert = $conn->prepare("INSERT INTO products (name,description,image,category,download_url,status) VALUES (?,?,?,?,'','active')");
        if (!$insert) throw new RuntimeException('Unable to prepare API-only local product');
        $insert->bind_param('ssss', $name, $description, $image, $firstCategory);
        if (!$insert->execute()) { $insert->close(); throw new RuntimeException('Unable to create API-only local product'); }
        $localProductId = (int) $conn->insert_id;
        $insert->close();
        if ($localProductId < 1) throw new RuntimeException('Local product ID was not created');

        if (!supplierBridgeSetProductCategoriesAtomic($localProductId, $categories, false)) {
            throw new RuntimeException('Unable to save local product categories');
        }
        if (!setProductPlatform($localProductId, $platform)) {
            throw new RuntimeException('Unable to save local product platform');
        }

        $map = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id,remote_source_product_id,local_product_id,sync_details,local_categories_override_json) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=1,local_categories_override_json=VALUES(local_categories_override_json)');
        if (!$map) throw new RuntimeException('Unable to prepare API group mapping');
        $map->bind_param('isis', $connectionId, $sourceRef, $localProductId, $categoryOverrideJson);
        if (!$map->execute()) { $map->close(); throw new RuntimeException('Unable to save API group mapping'); }
        $map->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return ['success' => false, 'message' => $e->getMessage()];
    }

    $publish = supplierBridgePublishSelectedProducts($ids, true);
    $priorityStmt = $conn->prepare('UPDATE supplier_catalog_links scl JOIN supplier_products sp ON sp.id=scl.supplier_product_id SET scl.source_priority=? WHERE sp.connection_id=? AND sp.remote_source_product_id=? AND scl.local_product_id=?');
    if ($priorityStmt) {
        $priorityStmt->bind_param('iisi', $sourcePriority, $connectionId, $sourceRef, $localProductId);
        $priorityStmt->execute();
        $priorityStmt->close();
    }

    // Manual categories are stored as a group-level override, so later metadata
    // syncs can still refresh name/description/image without replacing the
    // administrator's category choice.
    if ($manualCategoryOverride !== []) {
        supplierBridgeSetProductCategoriesAtomic($localProductId, $manualCategoryOverride);
    }

    return [
        'success' => !empty($publish['success']) || (int) ($publish['published'] ?? 0) > 0,
        'local_product_id' => $localProductId,
        'published' => (int) ($publish['published'] ?? 0),
        'failed' => (int) ($publish['failed'] ?? 0),
        'message' => (string) ($publish['message'] ?? 'API-only product created'),
    ];
}

function supplierBridgeMapGroupToLocalProduct(int $connectionId, string $sourceRef, int $localProductId): array
{
    global $conn;
    $sourceRef = trim($sourceRef);
    if ($connectionId < 1 || $sourceRef === '' || $localProductId < 1 || !getProductById($localProductId) || isProductAdminArchived($localProductId)) return ['success' => false, 'message' => 'Mapping target is invalid'];
    $stmt = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id,remote_source_product_id,local_product_id,sync_details) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=0,local_categories_override_json=NULL');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to prepare product mapping'];
    $stmt->bind_param('isi', $connectionId, $sourceRef, $localProductId);
    if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'message' => 'Unable to save product mapping']; }
    $stmt->close();

    $get = $conn->prepare('SELECT sp.id,l.local_product_id,l.local_variant_id,l.sync_duration FROM supplier_products sp LEFT JOIN supplier_catalog_links l ON l.supplier_product_id=sp.id WHERE sp.connection_id=? AND sp.remote_source_product_id=? AND sp.enabled=1 ORDER BY sp.id ASC');
    if (!$get) return ['success' => false, 'message' => 'Unable to load supplier variants'];
    $get->bind_param('is', $connectionId, $sourceRef);
    $get->execute();
    $result = $get->get_result();
    $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $get->close();

    $published = 0;
    $moved = 0;
    $skippedManual = 0;
    $errors = [];
    foreach ($items as $item) {
        $id = (int) $item['id'];
        $oldProductId = (int) ($item['local_product_id'] ?? 0);
        $variantId = (int) ($item['local_variant_id'] ?? 0);
        $syncDuration = (int) ($item['sync_duration'] ?? 1);
        if ($variantId > 0 && $oldProductId > 0 && $oldProductId !== $localProductId) {
            if ($syncDuration !== 1) {
                $skippedManual++;
                continue;
            }
            // One physical local variant can now be shared by several API
            // sources. Moving only this supplier's link would leave the other
            // links pointing at the old product while the variant itself moved.
            // Refuse to drag a manually linked backup, otherwise move every
            // automatic source attached to this shared variant atomically.
            $sharedStmt = $conn->prepare("SELECT COUNT(*) AS total_links,
                                                 SUM(CASE WHEN sync_duration<>1 THEN 1 ELSE 0 END) AS manual_links
                                          FROM supplier_catalog_links
                                          WHERE local_variant_id=? AND local_product_id=?");
            if (!$sharedStmt) { $errors[] = 'Unable to inspect shared API variant'; continue; }
            $sharedStmt->bind_param('ii', $variantId, $oldProductId);
            $sharedStmt->execute();
            $sharedResult = $sharedStmt->get_result();
            $sharedRow = $sharedResult ? $sharedResult->fetch_assoc() : null;
            $sharedStmt->close();
            $sharedTotal = max(0, (int) ($sharedRow['total_links'] ?? 0));
            $sharedManual = max(0, (int) ($sharedRow['manual_links'] ?? 0));
            if ($sharedTotal > 1 && $sharedManual > 0) {
                $skippedManual++;
                continue;
            }

            $conn->begin_transaction();
            try {
                $sourceGroups = [];
                $groupsStmt = $conn->prepare("SELECT sp.connection_id,sp.remote_source_product_id
                                              FROM supplier_catalog_links scl
                                              JOIN supplier_products sp ON sp.id=scl.supplier_product_id
                                              WHERE scl.local_variant_id=? AND scl.local_product_id=?
                                              FOR UPDATE");
                if (!$groupsStmt) throw new RuntimeException('Unable to inspect shared API mappings');
                $groupsStmt->bind_param('ii', $variantId, $oldProductId);
                $groupsStmt->execute();
                $groupsResult = $groupsStmt->get_result();
                while ($groupRow = $groupsResult ? $groupsResult->fetch_assoc() : null) {
                    if (!$groupRow) break;
                    $groupConnectionId = (int) ($groupRow['connection_id'] ?? 0);
                    $groupSourceRef = (string) ($groupRow['remote_source_product_id'] ?? '');
                    if ($groupConnectionId > 0 && $groupSourceRef !== '') {
                        $sourceGroups[$groupConnectionId . ':' . $groupSourceRef] = [
                            'connection_id' => $groupConnectionId,
                            'source_ref' => $groupSourceRef,
                        ];
                    }
                }
                $groupsStmt->close();

                $moveVariant = $conn->prepare('UPDATE product_variants SET product_id=?,updated_at=NOW() WHERE id=? AND product_id=?');
                if (!$moveVariant) throw new RuntimeException('Unable to prepare API variant move');
                $moveVariant->bind_param('iii', $localProductId, $variantId, $oldProductId);
                if (!$moveVariant->execute() || $moveVariant->affected_rows !== 1) { $moveVariant->close(); throw new RuntimeException('Unable to move API variant'); }
                $moveVariant->close();

                $moveLinks = $conn->prepare('UPDATE supplier_catalog_links SET local_product_id=? WHERE local_variant_id=? AND local_product_id=?');
                if (!$moveLinks) throw new RuntimeException('Unable to prepare shared API mapping move');
                $moveLinks->bind_param('iii', $localProductId, $variantId, $oldProductId);
                if (!$moveLinks->execute()) { $moveLinks->close(); throw new RuntimeException('Unable to move shared API mappings'); }
                $moveLinks->close();

                $moveGroupMap = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id,remote_source_product_id,local_product_id,sync_details) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=0,local_categories_override_json=NULL');
                if (!$moveGroupMap) throw new RuntimeException('Unable to prepare shared API group mapping move');
                foreach ($sourceGroups as $sourceGroup) {
                    $groupConnectionId = (int) ($sourceGroup['connection_id'] ?? 0);
                    $groupSourceRef = (string) ($sourceGroup['source_ref'] ?? '');
                    if ($groupConnectionId < 1 || $groupSourceRef === '') continue;
                    $moveGroupMap->bind_param('isi', $groupConnectionId, $groupSourceRef, $localProductId);
                    if (!$moveGroupMap->execute()) { $moveGroupMap->close(); throw new RuntimeException('Unable to move shared API group mapping'); }
                }
                $moveGroupMap->close();
                $conn->commit();
                $moved++;
            } catch (Throwable $e) {
                try { $conn->rollback(); } catch (Throwable $ignored) {}
                $errors[] = $e->getMessage();
                continue;
            }
        }
        $publish = supplierBridgePublishById($id, true);
        if (!empty($publish['success'])) $published++;
        else $errors[] = (string) ($publish['message'] ?? ('Product #' . $id . ' failed'));
    }
    if ($errors !== []) return ['success' => false, 'published' => $published, 'moved' => $moved, 'skipped_manual' => $skippedManual, 'message' => implode('; ', array_slice($errors, 0, 3))];
    return ['success' => true, 'published' => $published, 'moved' => $moved, 'skipped_manual' => $skippedManual];
}

function supplierBridgeLinkVariantToLocal(int $supplierProductId, int $localVariantId, int $sourcePriority = 100, ?float $maxSupplierCost = null): array
{
    global $conn;
    $product = supplierBridgeGetSupplierProduct($supplierProductId);
    if (!$product || $localVariantId < 1 || isProductVariantAdminArchived($localVariantId)) return ['success' => false, 'message' => 'Supplier product or local variant was not found'];
    $sourcePriority = max(-100000, min(100000, $sourcePriority));
    if ($maxSupplierCost === null
        && supplierBridgeProviderRequiresProtectedPurchase((string) ($product['provider_type'] ?? ''))
        && is_numeric($product['cost_base'] ?? null)) {
        $maxSupplierCost = round((float) $product['cost_base'] + 2.0, 2);
    }
    if ($maxSupplierCost !== null) {
        if (!is_finite($maxSupplierCost) || $maxSupplierCost <= 0) return ['success' => false, 'message' => 'Max supplier cost must be greater than zero'];
        $maxSupplierCost = round($maxSupplierCost, 2);
    }
    $variantStmt = $conn->prepare('SELECT id,product_id FROM product_variants WHERE id=? LIMIT 1');
    if (!$variantStmt) return ['success' => false, 'message' => 'Unable to inspect local variant'];
    $variantStmt->bind_param('i', $localVariantId);
    $variantStmt->execute();
    $variantResult = $variantStmt->get_result();
    $variant = $variantResult ? $variantResult->fetch_assoc() : null;
    $variantStmt->close();
    if (!$variant || isProductAdminArchived((int) $variant['product_id'])) return ['success' => false, 'message' => 'Local variant is unavailable'];

    // Multiple supplier products are intentionally allowed to point at the same
    // local variant. source_priority decides the preferred route; checkout can
    // fail over to another mapping when the preferred supplier is definitively
    // unavailable before an ambiguous order has been created.
    $localProductId = (int) $variant['product_id'];
    $conn->begin_transaction();
    try {
        $map = $conn->prepare('INSERT INTO supplier_catalog_products (connection_id,remote_source_product_id,local_product_id,sync_details) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),sync_details=0,local_categories_override_json=NULL');
        if (!$map) throw new RuntimeException('Unable to prepare group mapping');
        $connectionId = (int) $product['connection_id'];
        $sourceRef = (string) $product['remote_source_product_id'];
        $map->bind_param('isi', $connectionId, $sourceRef, $localProductId);
        if (!$map->execute()) { $map->close(); throw new RuntimeException('Unable to save group mapping'); }
        $map->close();
        if ($maxSupplierCost === null) {
            $link = $conn->prepare('INSERT INTO supplier_catalog_links (supplier_product_id,local_product_id,local_variant_id,api_fallback_enabled,source_priority,max_supplier_cost,sync_duration) VALUES (?,?,?,1,?,NULL,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),local_variant_id=VALUES(local_variant_id),api_fallback_enabled=1,source_priority=VALUES(source_priority),max_supplier_cost=NULL,sync_duration=0');
            if (!$link) throw new RuntimeException('Unable to prepare variant mapping');
            $link->bind_param('iiii', $supplierProductId, $localProductId, $localVariantId, $sourcePriority);
        } else {
            $link = $conn->prepare('INSERT INTO supplier_catalog_links (supplier_product_id,local_product_id,local_variant_id,api_fallback_enabled,source_priority,max_supplier_cost,sync_duration) VALUES (?,?,?,1,?,?,0) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),local_variant_id=VALUES(local_variant_id),api_fallback_enabled=1,source_priority=VALUES(source_priority),max_supplier_cost=VALUES(max_supplier_cost),sync_duration=0');
            if (!$link) throw new RuntimeException('Unable to prepare variant mapping');
            $link->bind_param('iiiid', $supplierProductId, $localProductId, $localVariantId, $sourcePriority, $maxSupplierCost);
        }
        if (!$link->execute()) { $link->close(); throw new RuntimeException('Unable to save variant mapping'); }
        $link->close();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return ['success' => false, 'message' => $e->getMessage()];
    }
    return supplierBridgePublishById($supplierProductId, true);
}

function supplierBridgeBackfillMaxSupplierCosts(int $connectionId, float $margin = 2.0): array
{
    global $conn;
    if ($connectionId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'updated' => 0, 'message' => 'Supplier connection is invalid'];
    $margin = round(max(0.01, min(1000000, $margin)), 2);
    $stmt = $conn->prepare("UPDATE supplier_catalog_links scl
        JOIN supplier_products sp ON sp.id=scl.supplier_product_id
        JOIN supplier_connections c ON c.id=sp.connection_id
        SET scl.max_supplier_cost=ROUND(sp.cost_base + ?, 2)
        WHERE sp.connection_id=?
          AND c.provider_type IN ('vipstore_v1','starkmods_v1')
          AND (scl.max_supplier_cost IS NULL OR scl.max_supplier_cost<=0)");
    if (!$stmt) return ['success' => false, 'updated' => 0, 'message' => 'Unable to prepare max supplier cost backfill'];
    $stmt->bind_param('di', $margin, $connectionId);
    $ok = $stmt->execute();
    $updated = $ok ? max(0, (int) $stmt->affected_rows) : 0;
    $stmt->close();
    return ['success' => $ok, 'updated' => $updated, 'message' => $ok ? 'Max supplier costs updated' : 'Unable to update max supplier costs'];
}

function supplierBridgeUnlinkProduct(int $supplierProductId): bool
{
    global $conn;
    if ($supplierProductId < 1 || !storeBridgeEnsureSchema()) return false;
    $stmt = $conn->prepare('DELETE FROM supplier_catalog_links WHERE supplier_product_id=?');
    if (!$stmt) return false;
    $stmt->bind_param('i', $supplierProductId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function supplierBridgeSetProductEnabled(int $supplierProductId, bool $enabled): bool
{
    global $conn;
    if ($supplierProductId < 1 || !storeBridgeEnsureSchema()) return false;
    $value = $enabled ? 1 : 0;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE supplier_products SET enabled=? WHERE id=?');
        if (!$stmt) throw new RuntimeException('Unable to prepare supplier product status');
        $stmt->bind_param('ii', $value, $supplierProductId);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to update supplier product status'); }
        $stmt->close();
        $link = $conn->prepare('UPDATE supplier_catalog_links SET api_fallback_enabled=? WHERE supplier_product_id=?');
        if (!$link) throw new RuntimeException('Unable to prepare storefront fallback status');
        $link->bind_param('ii', $value, $supplierProductId);
        if (!$link->execute()) { $link->close(); throw new RuntimeException('Unable to update storefront fallback status'); }
        $link->close();
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return false;
    }
}

function supplierBridgeApplyConnectionPrices(int $connectionId): array
{
    global $conn;
    if ($connectionId < 1 || !supplierBridgeGetConnection($connectionId)) return ['success' => false, 'message' => 'Supplier connection was not found'];
    $stmt = $conn->prepare('SELECT sp.id FROM supplier_products sp JOIN supplier_catalog_links l ON l.supplier_product_id=sp.id WHERE sp.connection_id=? AND sp.enabled=1 ORDER BY sp.id ASC');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to load mapped supplier products'];
    $stmt->bind_param('i', $connectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
    $stmt->close();
    $updated = 0;
    $failed = 0;
    foreach ($ids as $id) {
        $publish = supplierBridgePublishById($id, true);
        if (!empty($publish['success'])) $updated++; else $failed++;
    }
    return ['success' => $failed === 0, 'updated' => $updated, 'failed' => $failed, 'message' => $failed > 0 ? 'Some mapped products could not be repriced' : 'Prices applied'];
}

function supplierBridgePublishConnectionProducts(int $connectionId): array
{
    global $conn;
    $connection = supplierBridgeGetConnection($connectionId);
    if (!$connection) return ['success' => false, 'message' => 'Supplier connection was not found'];
    $stmt = $conn->prepare('SELECT id FROM supplier_products WHERE connection_id=? AND enabled=1 AND supplier_removed_at IS NULL ORDER BY id ASC');
    if (!$stmt) return ['success' => false, 'message' => 'Unable to load supplier products'];
    $stmt->bind_param('i', $connectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result ? $result->fetch_assoc() : null) { if (!$row) break; $ids[] = (int) $row['id']; }
    $stmt->close();
    $published = 0;
    $failed = 0;
    foreach ($ids as $id) {
        $publish = supplierBridgePublishById($id, true);
        if (!empty($publish['success'])) $published++; else $failed++;
    }
    return ['success' => $failed === 0, 'published' => $published, 'failed' => $failed, 'message' => $failed > 0 ? 'Some supplier products could not be published' : 'Products published'];
}

function supplierBridgeGetUnifiedVariants(int $productId, string $role, int $userId = 0, bool $includeUnavailable = false): array
{
    global $conn;
    if ($productId < 1 || !storeBridgeEnsureSchema()) return [];
    $role = $role === 'reseller' ? 'reseller' : 'user';
    $accountMap = $userId > 0 ? getResellerVariantPriceMap($userId) : [];
    $stmt = $conn->prepare("SELECT sp.id AS supplier_product_id, sp.remote_stock, sp.cost_base,
                                  scl.local_variant_id, scl.source_priority, scl.max_supplier_cost,
                                  pv.duration, pv.price_user, pv.price_reseller,
                                  sc.id AS connection_id, sc.priority, sc.protect_below_cost, sc.provider_type, sc.purchase_mode,
                                  CASE WHEN sp.inventory_last_error_at IS NOT NULL
                                            AND (sp.inventory_last_success_at IS NULL OR sp.inventory_last_error_at > sp.inventory_last_success_at)
                                            AND sp.inventory_last_error_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                                       THEN 1 ELSE 0 END AS routing_degraded
                           FROM supplier_catalog_links scl
                           JOIN supplier_products sp ON sp.id = scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
                           JOIN supplier_connections sc ON sc.id = sp.connection_id AND sc.status='active'
                           JOIN products p ON p.id=scl.local_product_id AND p.status='active'
                           JOIN product_variants pv ON pv.id=scl.local_variant_id AND pv.product_id=scl.local_product_id AND pv.status='active'
                           WHERE scl.local_product_id=? AND scl.api_fallback_enabled=1
                           ORDER BY routing_degraded ASC, scl.source_priority ASC, sc.priority ASC, sc.id ASC, sp.id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $variants = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $variantId = (int) $row['local_variant_id'];
        if ($variantId < 1 || !supplierBridgeConnectionAllowsPurchase($row, $userId)) continue;
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($row['provider_type'] ?? '')) && (!is_numeric($row['max_supplier_cost'] ?? null) || (float) $row['max_supplier_cost'] <= 0)) continue;
        $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
        if (array_key_exists($variantId, $accountMap)) $price = (float) $accountMap[$variantId];
        $price = round($price, 2);
        $priceAllowed = (int) ($row['protect_below_cost'] ?? 1) !== 1 || $price + 0.00001 >= (float) $row['cost_base'];
        $stock = $priceAllowed && (int) ($row['routing_degraded'] ?? 0) === 0 ? max(0, (int) $row['remote_stock']) : 0;

        if (!isset($variants[$variantId])) {
            $variants[$variantId] = [
                'variant_id' => $variantId,
                'duration' => (string) $row['duration'],
                'price' => $price,
                'count' => 0,
                'available' => false,
                'source' => 'supplier',
                'supplier_product_id' => 0,
                'connection_id' => 0,
            ];
        }
        // Checkout can use any one supplier that can satisfy the whole request,
        // so storefront capacity is the largest eligible source, never the sum.
        if ($stock > (int) $variants[$variantId]['count']) {
            $variants[$variantId]['count'] = min(100, $stock);
        }
        if ($stock > 0 && empty($variants[$variantId]['available'])) {
            $variants[$variantId]['available'] = true;
            $variants[$variantId]['supplier_product_id'] = (int) $row['supplier_product_id'];
            $variants[$variantId]['connection_id'] = (int) $row['connection_id'];
        }
    }
    $stmt->close();

    $rows = [];
    foreach ($variants as $candidate) {
        if (!empty($candidate['available']) || $includeUnavailable) $rows[] = $candidate;
    }
    return $rows;
}


/**
 * Bulk storefront supplier variants for one rendered catalogue page.
 * This replaces one supplier query per product with one IN(...) query.
 */
function supplierBridgeGetUnifiedVariantsForProducts(
    array $productIds,
    string $role,
    int $userId = 0,
    bool $includeUnavailable = false
): array {
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $ids = [];
    foreach ($productIds as $productId) {
        $productId = (int) $productId;
        if ($productId > 0) $ids[$productId] = $productId;
        if (count($ids) >= 60) break;
    }
    if ($ids === []) return [];

    $role = $role === 'reseller' ? 'reseller' : 'user';
    $accountMap = $userId > 0 ? getResellerVariantPriceMap($userId) : [];
    $list = implode(',', array_values($ids));
    $sql = "SELECT scl.local_product_id,
                   sp.id AS supplier_product_id, sp.remote_stock, sp.cost_base,
                   scl.local_variant_id, scl.source_priority, scl.max_supplier_cost,
                   pv.duration, pv.price_user, pv.price_reseller,
                   sc.id AS connection_id, sc.priority, sc.protect_below_cost, sc.provider_type, sc.purchase_mode,
                   CASE WHEN sp.inventory_last_error_at IS NOT NULL
                              AND (sp.inventory_last_success_at IS NULL OR sp.inventory_last_error_at > sp.inventory_last_success_at)
                              AND sp.inventory_last_error_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        THEN 1 ELSE 0 END AS routing_degraded
            FROM supplier_catalog_links scl
            JOIN supplier_products sp ON sp.id = scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
            JOIN supplier_connections sc ON sc.id = sp.connection_id AND sc.status='active'
            JOIN products p ON p.id=scl.local_product_id AND p.status='active'
            JOIN product_variants pv ON pv.id=scl.local_variant_id AND pv.product_id=scl.local_product_id AND pv.status='active'
            WHERE scl.local_product_id IN ({$list}) AND scl.api_fallback_enabled=1
            ORDER BY scl.local_product_id ASC, routing_degraded ASC,
                     scl.source_priority ASC, sc.priority ASC, sc.id ASC, sp.id ASC";
    $result = $conn->query($sql);
    if (!$result) return [];

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $productId = (int) ($row['local_product_id'] ?? 0);
        $variantId = (int) ($row['local_variant_id'] ?? 0);
        if ($productId < 1 || $variantId < 1 || !supplierBridgeConnectionAllowsPurchase($row, $userId)) continue;
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($row['provider_type'] ?? '')) && (!is_numeric($row['max_supplier_cost'] ?? null) || (float) $row['max_supplier_cost'] <= 0)) continue;
        $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
        if (array_key_exists($variantId, $accountMap)) $price = (float) $accountMap[$variantId];
        $price = round($price, 2);
        $priceAllowed = (int) ($row['protect_below_cost'] ?? 1) !== 1
            || $price + 0.00001 >= (float) $row['cost_base'];
        $stock = $priceAllowed && (int) ($row['routing_degraded'] ?? 0) === 0
            ? max(0, (int) $row['remote_stock'])
            : 0;

        if (!isset($grouped[$productId])) $grouped[$productId] = [];
        if (!isset($grouped[$productId][$variantId])) {
            $grouped[$productId][$variantId] = [
                'variant_id' => $variantId,
                'duration' => (string) $row['duration'],
                'price' => $price,
                'count' => 0,
                'available' => false,
                'source' => 'supplier',
                'supplier_product_id' => 0,
                'connection_id' => 0,
            ];
        }
        if ($stock > (int) $grouped[$productId][$variantId]['count']) {
            $grouped[$productId][$variantId]['count'] = min(100, $stock);
        }
        if ($stock > 0 && empty($grouped[$productId][$variantId]['available'])) {
            $grouped[$productId][$variantId]['available'] = true;
            $grouped[$productId][$variantId]['supplier_product_id'] = (int) $row['supplier_product_id'];
            $grouped[$productId][$variantId]['connection_id'] = (int) $row['connection_id'];
        }
    }
    $result->free();

    $map = [];
    foreach ($grouped as $productId => $variants) {
        foreach ($variants as $candidate) {
            if (!empty($candidate['available']) || $includeUnavailable) {
                $map[$productId][] = $candidate;
            }
        }
    }
    return $map;
}

function supplierBridgeInventorySnapshot(array $variantIds, string $role, int $userId = 0): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $ids = [];
    foreach ($variantIds as $id) { $id = (int) $id; if ($id > 0) $ids[$id] = $id; if (count($ids) >= 300) break; }
    if ($ids === []) return [];
    $role = $role === 'reseller' ? 'reseller' : 'user';
    $accountMap = $userId > 0 ? getResellerVariantPriceMap($userId) : [];
    $list = implode(',', $ids);
    $sql = "SELECT scl.local_product_id, scl.local_variant_id, scl.max_supplier_cost, sp.remote_stock, sp.cost_base,
                   pv.price_user, pv.price_reseller, pv.duration, sc.protect_below_cost, sc.provider_type, sc.purchase_mode,
                   CASE WHEN sp.inventory_last_error_at IS NOT NULL
                              AND (sp.inventory_last_success_at IS NULL OR sp.inventory_last_error_at > sp.inventory_last_success_at)
                              AND sp.inventory_last_error_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        THEN 1 ELSE 0 END AS routing_degraded
            FROM supplier_catalog_links scl
            JOIN supplier_products sp ON sp.id=scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
            JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
            JOIN products p ON p.id=scl.local_product_id AND p.status='active'
            JOIN product_variants pv ON pv.id=scl.local_variant_id AND pv.product_id=scl.local_product_id AND pv.status='active'
            WHERE scl.api_fallback_enabled=1 AND scl.local_variant_id IN ($list)";
    $result = $conn->query($sql);
    if (!$result) return [];
    $snapshot = [];
    $productLocal = [];
    $localPriorityVariants = [];
    while ($row = $result->fetch_assoc()) {
        $variantId = (int) $row['local_variant_id'];
        if ($variantId < 1 || !supplierBridgeConnectionAllowsPurchase($row, $userId)) continue;
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($row['provider_type'] ?? '')) && (!is_numeric($row['max_supplier_cost'] ?? null) || (float) $row['max_supplier_cost'] <= 0)) continue;
        $productId = (int) $row['local_product_id'];
        if (!isset($productLocal[$productId])) {
            $variantSet = [];
            $durationSet = [];
            foreach (getAvailableKeyGroups($productId) as $key) {
                $keyVariant = (int) ($key['variant_id'] ?? 0);
                if ($keyVariant > 0) $variantSet[$keyVariant] = true;
                $d = strtolower(trim((string) ($key['duration'] ?? 'Standard')));
                $durationSet[$d === '' ? 'standard' : $d] = true;
            }
            $productLocal[$productId] = ['variants' => $variantSet, 'durations' => $durationSet];
        }
        $duration = strtolower(trim((string) ($row['duration'] ?? 'Standard')));
        if ($duration === '') $duration = 'standard';
        $local = $productLocal[$productId];
        if (isset($local['variants'][$variantId]) || isset($local['durations'][$duration])) {
            $localPriorityVariants[$variantId] = true;
            $snapshot[(string) $variantId] = ['stock' => 0, 'available' => false];
            continue;
        }
        if (isset($localPriorityVariants[$variantId])) continue;

        $price = $role === 'reseller' ? (float) $row['price_reseller'] : (float) $row['price_user'];
        if (array_key_exists($variantId, $accountMap)) $price = (float) $accountMap[$variantId];
        $priceAllowed = (int) ($row['protect_below_cost'] ?? 1) !== 1 || $price + 0.00001 >= (float) $row['cost_base'];
        $stock = $priceAllowed && (int) ($row['routing_degraded'] ?? 0) === 0 ? max(0, (int) $row['remote_stock']) : 0;
        $key = (string) $variantId;
        if (!isset($snapshot[$key])) $snapshot[$key] = ['stock' => 0, 'available' => false];
        if ($stock > (int) $snapshot[$key]['stock']) $snapshot[$key]['stock'] = min(100, $stock);
        if ($stock > 0) $snapshot[$key]['available'] = true;
    }
    return $snapshot;
}

function supplierBridgeHasEnabledProducts(): bool
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return false;
    $result = $conn->query("SELECT 1 FROM supplier_catalog_links scl
        JOIN supplier_products sp ON sp.id=scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
        JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
        JOIN products p ON p.id=scl.local_product_id AND p.status='active'
        JOIN product_variants pv ON pv.id=scl.local_variant_id AND pv.product_id=scl.local_product_id AND pv.status='active'
        WHERE scl.api_fallback_enabled=1 LIMIT 1");
    return $result && $result->num_rows > 0;
}

function supplierBridgeInventoryCacheTtlSeconds(): int
{
    return 30;
}

function supplierBridgeRoutingCooldownSeconds(): int
{
    // After a live inventory failure, give a backup API a chance immediately
    // instead of hammering the same broken priority source every 3-second poll.
    return 15;
}

function supplierBridgeGetStaleMappedProductIds(array $variantIds, int $maxAgeSeconds = 30, int $limit = 12): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $ids = [];
    foreach ($variantIds as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
        if (count($ids) >= 300) break;
    }
    if ($ids === []) return [];
    $maxAgeSeconds = max(5, min(300, $maxAgeSeconds));
    $limit = max(1, min(30, $limit));
    $list = implode(',', $ids);
    $cooldownSeconds = supplierBridgeRoutingCooldownSeconds();
    $sql = "SELECT sp.id
            FROM supplier_catalog_links scl
            JOIN supplier_products sp ON sp.id=scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
            JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
            WHERE scl.api_fallback_enabled=1
              AND scl.local_variant_id IN ($list)
              AND (sp.inventory_checked_at IS NULL OR sp.inventory_checked_at < DATE_SUB(NOW(), INTERVAL {$maxAgeSeconds} SECOND))
              AND (sp.inventory_last_error_at IS NULL
                   OR sp.inventory_last_success_at >= sp.inventory_last_error_at
                   OR sp.inventory_last_error_at < DATE_SUB(NOW(), INTERVAL {$cooldownSeconds} SECOND))
            ORDER BY scl.source_priority ASC, sc.priority ASC, sc.id ASC, sp.id ASC
            LIMIT {$limit}";
    $result = $conn->query($sql);
    $out = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function supplierBridgeStorefrontInventoryRefreshNeeded(array $variantIds, int $maxAgeSeconds = 30): bool
{
    return supplierBridgeGetStaleMappedProductIds($variantIds, $maxAgeSeconds, 1) !== [];
}

function supplierBridgeProductInventoryNeedsRefresh(int $supplierProductId, int $maxAgeSeconds = 30): bool
{
    global $conn;
    if ($supplierProductId < 1 || !storeBridgeEnsureSchema()) return false;
    $maxAgeSeconds = max(5, min(300, $maxAgeSeconds));
    $cooldownSeconds = supplierBridgeRoutingCooldownSeconds();
    $stmt = $conn->prepare("SELECT 1
        FROM supplier_products sp
        JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
        WHERE sp.id=? AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
          AND (sp.inventory_checked_at IS NULL OR sp.inventory_checked_at < DATE_SUB(NOW(), INTERVAL {$maxAgeSeconds} SECOND))
          AND (sp.inventory_last_error_at IS NULL
               OR sp.inventory_last_success_at >= sp.inventory_last_error_at
               OR sp.inventory_last_error_at < DATE_SUB(NOW(), INTERVAL {$cooldownSeconds} SECOND))
        LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $supplierProductId);
    $stmt->execute();
    $result = $stmt->get_result();
    $needsRefresh = $result && $result->num_rows > 0;
    $stmt->close();
    return $needsRefresh;
}

function supplierBridgeAcquireInventoryRefreshLock(int $supplierProductId): string
{
    global $conn;
    if ($supplierProductId < 1) return '';
    $lockName = storeBridgeLockName('inventory', (string) $supplierProductId);
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
    if (!$stmt) return '';
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['acquired'] ?? 0) === 1 ? $lockName : '';
}

function supplierBridgeReleaseInventoryRefreshLock(string $lockName): void
{
    global $conn;
    if ($lockName === '') return;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if (!$stmt) return;
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

function supplierBridgeRefreshStorefrontInventory(array $variantIds, int $maxAgeSeconds = 30, int $limit = 12): array
{
    $ids = supplierBridgeGetStaleMappedProductIds($variantIds, $maxAgeSeconds, $limit);
    $result = [
        'success' => true,
        'attempted' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'skipped_busy' => 0,
        'skipped_fresh' => 0,
        'remaining_stale' => 0,
    ];
    foreach ($ids as $supplierProductId) {
        $supplierProductId = (int) $supplierProductId;
        // A busy storefront can produce many identical refresh polls at once.
        // Serialize each product check inside this database and re-check freshness
        // after acquiring the lock so 50 customers do not become 50 API calls.
        $lockName = supplierBridgeAcquireInventoryRefreshLock($supplierProductId);
        if ($lockName === '') {
            $result['skipped_busy']++;
            continue;
        }
        try {
            if (!supplierBridgeProductInventoryNeedsRefresh($supplierProductId, $maxAgeSeconds)) {
                $result['skipped_fresh']++;
                continue;
            }
            $result['attempted']++;
            // The Store Bridge v1 provider supports the exact inventory endpoint.
            // Avoid a full catalogue fallback here so a storefront poll stays small
            // and one slow provider cannot turn every user's page into a sync worker.
            $check = supplierBridgeConfirmProductInventory($supplierProductId, 1, false, false);
            if (!empty($check['success'])) $result['succeeded']++;
            else { $result['failed']++; $result['success'] = false; }
        } finally {
            supplierBridgeReleaseInventoryRefreshLock($lockName);
        }
    }
    $result['remaining_stale'] = count(supplierBridgeGetStaleMappedProductIds($variantIds, $maxAgeSeconds, 30));
    return $result;
}

function supplierBridgeGetPurchaseCandidates(int $productId, int $variantId, int $quantity = 1, int $userId = 0, ?float $unitPriceOverride = null): array
{
    global $conn;
    if ($productId < 1 || $variantId < 1 || !storeBridgeEnsureSchema()) return [];
    $quantity = max(1, min(100, $quantity));
    $unitPriceOverride = $unitPriceOverride !== null && is_finite($unitPriceOverride) && $unitPriceOverride > 0
        ? round($unitPriceOverride, 2)
        : null;
    $role = 'user';
    if ($userId > 0) {
        $roleStmt = $conn->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        if ($roleStmt) {
            $roleStmt->bind_param('i', $userId);
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();
            $roleRow = $roleResult ? $roleResult->fetch_assoc() : null;
            $roleStmt->close();
            if ((string) ($roleRow['role'] ?? '') === 'reseller') $role = 'reseller';
        }
    }
    $sql = "SELECT sp.*, scl.local_product_id, scl.local_variant_id, scl.source_priority, scl.max_supplier_cost,
                   sc.endpoint_url, sc.api_key_ciphertext, sc.provider_type, sc.purchase_mode,
                   CASE WHEN sp.inventory_checked_at IS NULL THEN NULL
                        ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, sp.inventory_checked_at, NOW())) END AS inventory_age_seconds,
                   CASE WHEN sp.inventory_last_error_at IS NOT NULL
                              AND (sp.inventory_last_success_at IS NULL OR sp.inventory_last_error_at > sp.inventory_last_success_at)
                              AND sp.inventory_last_error_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                        THEN 1 ELSE 0 END AS routing_degraded,
                   sc.connect_timeout, sc.request_timeout, sc.name AS connection_name, sc.priority,
                   sc.status AS connection_status, sc.protect_below_cost,
                   pv.price_user AS local_price_user,pv.price_reseller AS local_price_reseller
            FROM supplier_catalog_links scl
            JOIN supplier_products sp ON sp.id=scl.supplier_product_id AND sp.enabled=1 AND sp.supplier_removed_at IS NULL
            JOIN supplier_connections sc ON sc.id=sp.connection_id AND sc.status='active'
            JOIN products p ON p.id=scl.local_product_id AND p.status='active'
            JOIN product_variants pv ON pv.id=scl.local_variant_id AND pv.product_id=scl.local_product_id AND pv.status='active'
            WHERE scl.local_product_id=? AND scl.local_variant_id=? AND scl.api_fallback_enabled=1
            ORDER BY CASE WHEN sp.remote_stock >= ? THEN 0 ELSE 1 END ASC,
                     routing_degraded ASC, scl.source_priority ASC, sc.priority ASC, sc.id ASC, sp.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('iii', $productId, $variantId, $quantity);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        if (!supplierBridgeConnectionAllowsPurchase($row, $userId)) continue;
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($row['provider_type'] ?? '')) && (!is_numeric($row['max_supplier_cost'] ?? null) || (float) $row['max_supplier_cost'] <= 0)) continue;
        $unitPrice = $unitPriceOverride !== null
            ? $unitPriceOverride
            : ($role === 'reseller' ? (float) $row['local_price_reseller'] : (float) $row['local_price_user']);
        if ($unitPriceOverride === null && $userId > 0) $unitPrice = (float) getEffectiveResellerPrice($userId, $variantId, $unitPrice);
        if ((int) ($row['protect_below_cost'] ?? 1) === 1 && $unitPrice + 0.00001 < (float) $row['cost_base']) continue;
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function supplierBridgeFindPurchaseLink(int $productId, int $variantId, int $supplierProductId = 0, int $userId = 0, int $quantity = 1, ?float $unitPriceOverride = null): ?array
{
    if ($supplierProductId > 0) {
        foreach (supplierBridgeGetPurchaseCandidates($productId, $variantId, $quantity, $userId, $unitPriceOverride) as $row) {
            if ((int) ($row['id'] ?? 0) === $supplierProductId) return $row;
        }
        // An explicitly selected source may currently fail the below-cost guard.
        // Return null rather than silently routing the caller to another source.
        return null;
    }
    $rows = supplierBridgeGetPurchaseCandidates($productId, $variantId, $quantity, $userId, $unitPriceOverride);
    return $rows[0] ?? null;
}


function supplierBridgeOrderNotFoundGraceSeconds(): int
{
    // Keep the same customer-facing policy as the CHEATGAME fallback without
    // requiring this standalone integration file to depend on cheatgame.php.
    return 40;
}

function supplierBridgeOrderCustomerDeadlineSeconds(): int
{
    return 55;
}

function supplierBridgeOrderReconcileMinIntervalSeconds(): int
{
    return 6;
}

function supplierBridgeOrderStatusDefinitelyNotFound(array $api, string $externalRef): bool
{
    $externalRef = trim($externalRef);
    $data = isset($api['data']) && is_array($api['data']) ? $api['data'] : null;
    if ($externalRef === '' || !is_array($data)) return false;
    if (supplierBridgeExtractKeys($data) !== []) return false;

    $payload = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    $echoedExternalRef = trim((string) ($payload['external_ref'] ?? $data['external_ref'] ?? ''));
    if ($echoedExternalRef !== '' && !hash_equals(strtoupper($externalRef), strtoupper($echoedExternalRef))) {
        return false;
    }

    $httpCode = (int) ($api['http_code'] ?? 0);
    if ($httpCode === 404) return true;

    $code = strtolower(trim((string) ($api['error_code'] ?? $payload['code'] ?? $data['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? $code;
    if (in_array($code, [
        'order_not_found',
        'external_ref_not_found',
        'external_reference_not_found',
        'reference_not_found',
        'not_found',
        'unknown_order',
    ], true)) {
        return true;
    }

    $status = strtolower(trim((string) ($payload['status'] ?? $data['status'] ?? '')));
    if (in_array($status, ['not_found', 'notfound', 'missing', 'unknown_order'], true)) {
        return true;
    }

    $success = $data['success'] ?? null;
    $failed = $success === false || $success === 0 || $success === '0'
        || (is_string($success) && strtolower(trim($success)) === 'false');
    if ($failed || empty($api['ok'])) {
        $message = strtolower(trim((string) ($api['error'] ?? $payload['message'] ?? $data['message'] ?? '')));
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        return preg_match('/^(?:order|order reference|external reference|external_ref|reference) (?:was )?not found[.!]?$/i', $message) === 1;
    }
    return false;
}

function supplierBridgeRefreshBlockingOrderIfStale(?array $row): ?array
{
    global $conn;
    if (!$row) return null;
    $orderId = (int) ($row['id'] ?? 0);
    if ($orderId < 1 || !function_exists('supplierBridgeReconcileOrder')) return $row;

    $createdTs = strtotime((string) ($row['created_at'] ?? ''));
    $updatedTs = strtotime((string) ($row['updated_at'] ?? ''));
    $now = time();
    $ageSeconds = $createdTs === false ? 0 : max(0, $now - $createdTs);
    $sinceUpdate = $updatedTs === false ? PHP_INT_MAX : max(0, $now - $updatedTs);
    if ($ageSeconds < supplierBridgeOrderNotFoundGraceSeconds()
        || $sinceUpdate < supplierBridgeOrderReconcileMinIntervalSeconds()) {
        return $row;
    }

    supplierBridgeReconcileOrder($orderId);
    $stmt = $conn->prepare("SELECT id,status,total_price_base,external_ref,created_at,updated_at
                            FROM supplier_orders
                            WHERE id=?
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

function supplierBridgeFindBlockingPendingOrder(int $userId, int $localVariantId, bool $refreshStale = true): ?array
{
    global $conn;
    if ($userId < 1 || $localVariantId < 1 || !storeBridgeEnsureSchema()) return null;
    $stmt = $conn->prepare("SELECT so.id,so.status,so.total_price_base,so.external_ref,so.created_at,so.updated_at
        FROM supplier_orders so
        WHERE so.local_variant_id=?
          AND so.status IN ('submitting','unknown','pending','processing','manual_review')
          AND (
              so.user_id=?
              OR (
                  so.source_kind='store_api'
                  AND EXISTS (
                      SELECT 1 FROM store_api_orders sao
                      WHERE sao.id=so.source_order_id
                        AND sao.billing_mode='reseller_wallet'
                        AND sao.billing_user_id=?
                        AND sao.refunded_at IS NULL
                  )
              )
          )
        ORDER BY so.id DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $localVariantId, $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row || !$refreshStale) return $row ?: null;
    return supplierBridgeRefreshBlockingOrderIfStale($row);
}

function supplierBridgeGenerateExternalRef(int $connectionId): string
{
    try { $random = bin2hex(random_bytes(10)); } catch (Throwable $e) { $random = hash('sha256', microtime(true) . mt_rand()); }
    return 'SB-' . $connectionId . '-' . date('YmdHis') . '-' . substr($random, 0, 20);
}

function supplierBridgeStoreOrderKeys(int $orderId, array $keys): int
{
    global $conn;
    if ($orderId < 1 || $keys === [] || !storeBridgeEnsureSchema()) return 0;
    $stmt = $conn->prepare('INSERT IGNORE INTO supplier_order_keys (order_id, key_code, key_hash) VALUES (?, ?, ?)');
    if (!$stmt) return 0;
    $count = 0;
    foreach ($keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 5000) continue;
        $hash = hash('sha256', $key);
        $stmt->bind_param('iss', $orderId, $key, $hash);
        if ($stmt->execute() && $stmt->affected_rows > 0) $count++;
    }
    $stmt->close();
    return $count;
}

function supplierBridgeStoredOrderKeyCount(int $orderId): int
{
    global $conn;
    if ($orderId < 1 || !storeBridgeEnsureSchema()) return 0;
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM supplier_order_keys WHERE order_id=?');
    if (!$stmt) return 0;
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return max(0, (int) ($row['total'] ?? 0));
}


function supplierBridgeRedactStoredOrderResponse(array $data, string $providerType): array
{
    if (!supplierBridgeProviderRequiresProtectedPurchase($providerType)) return $data;
    $sensitiveFields = ['codes','keys','key','key_code','apikey','api_key','token','authorization','password','phpsessid'];
    $walk = static function (array $node) use (&$walk, $sensitiveFields): array {
        foreach ($node as $field => $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $field));
            if (in_array($normalized, $sensitiveFields, true)) {
                unset($node[$field]);
                continue;
            }
            if (is_array($value)) $node[$field] = $walk($value);
        }
        return $node;
    };
    return $walk($data);
}

function supplierBridgeExtractKeys(array $data): array
{
    $candidates = [$data['keys'] ?? null, $data['data']['keys'] ?? null, $data['codes'] ?? null, $data['data']['codes'] ?? null, $data['key'] ?? null, $data['data']['key'] ?? null];
    $keys = [];
    foreach ($candidates as $candidate) {
        if (is_string($candidate)) $candidate = preg_split('/\r\n|\r|\n/', $candidate) ?: [];
        if (!is_array($candidate)) continue;
        foreach ($candidate as $value) {
            if (is_array($value)) $value = $value['key'] ?? $value['key_code'] ?? $value['code'] ?? null;
            if (!is_scalar($value)) continue;
            $key = trim((string) $value);
            if ($key !== '' && strlen($key) <= 5000 && !in_array($key, $keys, true)) $keys[] = $key;
        }
        if ($keys !== []) break;
    }
    return $keys;
}

function supplierBridgeCommerceSyncOrder(int $orderId): void
{
    global $conn;
    if ($orderId < 1) return;
    $stmt = $conn->prepare('SELECT source_kind,source_order_id FROM supplier_orders WHERE id=? LIMIT 1');
    if (!$stmt) {
        commerceCenterSyncSafe('supplier_purchase', $orderId);
        return;
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (strtolower(trim((string) ($row['source_kind'] ?? 'storefront'))) === 'store_api'
        && (int) ($row['source_order_id'] ?? 0) > 0) {
        commerceCenterSyncSafe('store_api_sale', (int) $row['source_order_id']);
        return;
    }
    commerceCenterSyncSafe('supplier_purchase', $orderId);
}

function supplierBridgeRefundOrder(int $orderId, string $reason): bool
{
    global $conn;
    if ($orderId < 1 || !storeBridgeEnsureSchema()) return false;

    // Store API procurement children never own billing. For ordinary storefront
    // supplier orders, prepare the wallet audit schema before taking financial
    // locks so a first-run DDL cannot implicitly commit an active transaction.
    $pre = $conn->prepare('SELECT source_kind,source_order_id FROM supplier_orders WHERE id=? LIMIT 1');
    if (!$pre) return false;
    $pre->bind_param('i', $orderId);
    if (!$pre->execute()) { $pre->close(); return false; }
    $preResult = $pre->get_result();
    $preOrder = $preResult ? $preResult->fetch_assoc() : null;
    $pre->close();
    if (!$preOrder) return false;
    $externalBilling = strtolower(trim((string) ($preOrder['source_kind'] ?? 'storefront'))) === 'store_api'
        && (int) ($preOrder['source_order_id'] ?? 0) > 0;
    if (!$externalBilling && !ensureWalletLedgerSchema()) return false;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT user_id,total_price_base,status,transaction_id,external_ref,source_kind,source_order_id FROM supplier_orders WHERE id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to lock supplier order');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$order) throw new RuntimeException('Supplier order not found');
        if ((string) $order['status'] === 'refunded') { $conn->rollback(); return true; }
        if (in_array((string) $order['status'], ['success','completed'], true)) { $conn->rollback(); return false; }
        $keyCheck = $conn->prepare('SELECT COUNT(*) AS total FROM supplier_order_keys WHERE order_id=?');
        if (!$keyCheck) throw new RuntimeException('Unable to verify supplier delivery before refund');
        $keyCheck->bind_param('i', $orderId);
        $keyCheck->execute();
        $keyResult = $keyCheck->get_result();
        $keyRow = $keyResult ? $keyResult->fetch_assoc() : null;
        $keyCheck->close();
        if ((int) ($keyRow['total'] ?? 0) > 0) {
            $conn->rollback();
            return false;
        }

        $sourceKind = strtolower(trim((string) ($order['source_kind'] ?? 'storefront')));
        $sourceOrderId = (int) ($order['source_order_id'] ?? 0);
        $reason = substr(trim($reason), 0, 2000);
        if ($sourceKind === 'store_api' && $sourceOrderId > 0) {
            // Billing belongs to the Store API parent. Mark only the procurement
            // child refundable here; the parent refund is performed exactly once
            // by storeBridgeRefundProviderOrder().
            $update = $conn->prepare("UPDATE supplier_orders SET status='refunded',error_message=?,updated_at=NOW() WHERE id=?");
            if (!$update) throw new RuntimeException('Unable to update external supplier order');
            $update->bind_param('si', $reason, $orderId);
            if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to update external supplier order'); }
            $update->close();
            if (!$conn->commit()) throw new RuntimeException('Unable to commit external supplier release');
            supplierBridgeCommerceSyncOrder($orderId);
            return true;
        }

        $userId = (int) $order['user_id'];
        $amount = round((float) $order['total_price_base'], 2);
        $walletBefore = walletLedgerReadBalance($userId, true);
        if ($walletBefore === null) throw new RuntimeException('Unable to lock supplier refund balance');
        $credit = $conn->prepare("UPDATE users SET balance=balance+? WHERE id=?");
        if (!$credit) throw new RuntimeException('Unable to prepare supplier refund');
        $credit->bind_param('di', $amount, $userId);
        if (!$credit->execute() || $credit->affected_rows !== 1) { $credit->close(); throw new RuntimeException('Unable to refund user'); }
        $credit->close();
        $update = $conn->prepare("UPDATE supplier_orders SET status='refunded',error_message=? WHERE id=?");
        if (!$update) throw new RuntimeException('Unable to update supplier order');
        $update->bind_param('si', $reason, $orderId);
        if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to update supplier order'); }
        $update->close();
        $transactionId = (int) ($order['transaction_id'] ?? 0);
        if ($transactionId > 0) {
            $tx = $conn->prepare("UPDATE transactions SET status='failed', description=CONCAT(description,' | Refunded: ',?) WHERE id=?");
            if ($tx) { $tx->bind_param('si', $reason, $transactionId); $tx->execute(); $tx->close(); }
        }
        $walletAfter = round($walletBefore + $amount, 2);
        if (!walletLedgerRecordMovement(
            $userId, $amount, $walletBefore, $walletAfter,
            'supplier_refund', 'supplier_refund:' . $orderId, $orderId,
            $transactionId > 0 ? $transactionId : null, null,
            'คืนเงินคำสั่งซื้อ Supplier #' . $orderId,
            'Automatic Supplier/Store Bridge refund after a definite refundable state.',
            (string) ($order['external_ref'] ?? ''), true
        )) {
            throw new RuntimeException('Unable to write supplier refund wallet audit');
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to commit supplier refund');
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) $_SESSION['balance'] = getUserBalance($userId);
        logHistory($userId, 'supplier_order_refund', 'Store Bridge order #' . $orderId . ' refunded: ' . $reason);
        supplierBridgeCommerceSyncOrder($orderId);
        return true;
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Supplier order refund failed: ' . $e->getMessage());
        return false;
    }
}

function supplierBridgeAdminConfirmOrderSuccess(int $orderId, string $rawKeys, int $adminId): array
{
    global $conn;
    if ($orderId < 1 || $adminId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Invalid manual supplier resolution request'];
    $keys = supplierBridgeExtractKeys(['keys' => $rawKeys]);
    if ($keys === []) return ['success' => false, 'message' => 'Enter the supplier key(s), one per line'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT so.id,so.status,so.quantity,so.transaction_id,so.supplier_product_id,so.connection_id,
                                       so.source_kind,so.source_order_id,sc.provider_type
                                FROM supplier_orders so
                                JOIN supplier_connections sc ON sc.id=so.connection_id
                                WHERE so.id=? LIMIT 1 FOR UPDATE");
        if (!$stmt) throw new RuntimeException('Unable to lock supplier order');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$order) throw new RuntimeException('Supplier order not found');
        if (!supplierBridgeProviderRequiresProtectedPurchase((string) $order['provider_type'])) throw new RuntimeException('Manual key resolution is reserved for suppliers without verified reconciliation');
        if ((string) $order['status'] === 'refunded') throw new RuntimeException('A refunded supplier order cannot be marked successful');
        if ((string) $order['status'] === 'success') {
            $conn->rollback();
            if ((string)($order['source_kind'] ?? '') === 'store_api' && (int)($order['source_order_id'] ?? 0) > 0) {
                $parentId = (int)$order['source_order_id'];
                $applied = storeBridgeApplySupplierOrderState($parentId, $orderId);
                if (empty($applied['success']) || !empty($applied['pending'])) {
                    return ['success'=>false,'message'=>'Supplier order is already successful, but Store API parent order #'.$parentId.' still requires reconciliation.'];
                }
                return ['success'=>true,'message'=>'Supplier order was already successful and Store API order #'.$parentId.' is now deliverable'];
            }
            return ['success' => true, 'message' => 'Supplier order is already successful'];
        }
        $required = max(1, (int) $order['quantity']);
        supplierBridgeStoreOrderKeys($orderId, $keys);
        $stored = supplierBridgeStoredOrderKeyCount($orderId);
        if ($stored !== $required) throw new RuntimeException('Stored key count must exactly match order quantity (' . $stored . '/' . $required . ')');

        $note = 'Manually confirmed after supplier review by admin #' . $adminId;
        $update = $conn->prepare("UPDATE supplier_orders SET status='success',error_message=?,completed_at=NOW(),updated_at=NOW() WHERE id=?");
        if (!$update) throw new RuntimeException('Unable to prepare manual supplier completion');
        $update->bind_param('si', $note, $orderId);
        if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to mark supplier order successful'); }
        $update->close();
        $transactionId = (int) ($order['transaction_id'] ?? 0);
        if ($transactionId > 0) {
            $tx = $conn->prepare("UPDATE transactions SET status='completed' WHERE id=?");
            if (!$tx) throw new RuntimeException('Unable to prepare supplier transaction completion');
            $tx->bind_param('i', $transactionId);
            if (!$tx->execute()) { $tx->close(); throw new RuntimeException('Unable to complete supplier transaction'); }
            $tx->close();
        }
        $supplierProductId = (int) ($order['supplier_product_id'] ?? 0);
        if ($supplierProductId > 0 && in_array((string) $order['status'], ['submitting','unknown','manual_review'], true)) {
            $stockUpdate = $conn->prepare('UPDATE supplier_products SET remote_stock=GREATEST(remote_stock-?,0),inventory_checked_at=NOW() WHERE id=?');
            if ($stockUpdate) {
                $stockUpdate->bind_param('ii', $required, $supplierProductId);
                $stockUpdate->execute();
                $stockUpdate->close();
            }
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to commit manual supplier completion');
        logHistory($adminId, 'supplier_order_manual_success', 'Manually confirmed protected supplier order #' . $orderId . '; keys=' . $stored);
        supplierBridgeCommerceSyncOrder($orderId);
        if ((string)($order['source_kind'] ?? '') === 'store_api' && (int)($order['source_order_id'] ?? 0) > 0) {
            $parentId = (int)$order['source_order_id'];
            $applied = storeBridgeApplySupplierOrderState($parentId, $orderId);
            if (empty($applied['success']) || !empty($applied['pending'])) {
                return [
                    'success'=>false,
                    'message'=>'Supplier keys were confirmed, but Store API parent order #'.$parentId.' still requires reconciliation before delivery.',
                ];
            }
            return ['success'=>true,'message'=>'Supplier order was confirmed and Store API order #'.$parentId.' is now deliverable'];
        }
        return ['success' => true, 'message' => 'Supplier order was manually confirmed and the stored key(s) are now deliverable'];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function supplierBridgeAdminRefundOrder(int $orderId, int $adminId, bool $confirmedNoSupplierOrder): array
{
    global $conn;
    if ($orderId < 1 || $adminId < 1 || !$confirmedNoSupplierOrder || !storeBridgeEnsureSchema()) {
        return ['success' => false, 'message' => 'Manual refund requires explicit confirmation that the supplier did not create the order'];
    }
    $stmt = $conn->prepare("SELECT so.status,so.source_kind,so.source_order_id,sc.provider_type
                            FROM supplier_orders so
                            JOIN supplier_connections sc ON sc.id=so.connection_id
                            WHERE so.id=? LIMIT 1");
    if (!$stmt) return ['success' => false, 'message' => 'Unable to verify supplier order'];
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$order || !supplierBridgeProviderRequiresProtectedPurchase((string) ($order['provider_type'] ?? ''))) return ['success' => false, 'message' => 'This manual refund action is only for protected supplier review orders'];
    if (supplierBridgeStoredOrderKeyCount($orderId) > 0) return ['success' => false, 'message' => 'Refund blocked because delivery key data is already stored'];
    $reason = 'Manual supplier review confirmed no supplier order; approved by admin #' . $adminId;
    if (!supplierBridgeRefundOrder($orderId, $reason)) return ['success' => false, 'message' => 'Unable to refund this supplier order'];
    logHistory($adminId, 'supplier_order_manual_refund', 'Manually refunded protected supplier order #' . $orderId . ' after upstream verification');
    if ((string)($order['source_kind'] ?? '') === 'store_api' && (int)($order['source_order_id'] ?? 0) > 0) {
        $parentId = (int)$order['source_order_id'];
        $applied = storeBridgeApplySupplierOrderState($parentId, $orderId);
        if (empty($applied['refunded'])) {
            return [
                'success'=>false,
                'message'=>'Supplier child was marked refunded, but Store API order #'.$parentId.' refund is still pending. Do not retry or credit manually yet.',
            ];
        }
        return ['success'=>true,'message'=>'Supplier order was verified as not created and Store API order #'.$parentId.' was refunded exactly once'];
    }
    return ['success' => true, 'message' => 'Supplier order was manually verified as not created and the customer balance was refunded'];
}

function supplierBridgeInventoryFallbackLimit(array $refresh): int
{
    if (!empty($refresh['busy'])) return 180;
    $failureClass = strtolower(trim((string) ($refresh['failure_class'] ?? '')));
    if (in_array($failureClass, ['transport', 'provider_temporary', 'provider_response'], true)) return 900;
    return 0;
}

function supplierBridgeCanUseBoundedInventoryFallback(array $link, int $quantity, array $refresh): array
{
    $quantity = max(1, $quantity);
    $age = isset($link['inventory_age_seconds']) && is_numeric($link['inventory_age_seconds'])
        ? max(0, (int) $link['inventory_age_seconds'])
        : null;
    $stock = max(0, (int) ($link['remote_stock'] ?? 0));
    $limit = supplierBridgeInventoryFallbackLimit($refresh);
    $allowed = $limit > 0 && $age !== null && $age <= $limit && $stock >= $quantity;
    return [
        'allowed' => $allowed,
        'age_seconds' => $age,
        'stock' => $stock,
        'limit_seconds' => $limit,
    ];
}

function supplierBridgePurchase(int $supplierProductId, int $userId, int $quantity, int $localProductId, int $localVariantId, bool $allowBoundedInventoryFallback = false, bool $retryInventoryTransient = true, string $sourceKind = 'storefront', int $sourceOrderId = 0, ?float $storeApiUnitPrice = null): array
{
    global $conn;
    $sourceKind = strtolower(trim($sourceKind));
    if (!in_array($sourceKind, ['storefront', 'store_api'], true)) $sourceKind = 'storefront';
    $externalBilling = $sourceKind === 'store_api';
    if (!$externalBilling) {
        $sourceOrderId = 0;
        $storeApiUnitPrice = null;
    } else {
        $storeApiUnitPrice = $storeApiUnitPrice !== null && is_finite($storeApiUnitPrice) && $storeApiUnitPrice > 0
            ? round($storeApiUnitPrice, 2)
            : null;
    }
    if ($supplierProductId < 1 || (!$externalBilling && $userId < 1) || $quantity < 1 || $quantity > 100 || $localProductId < 1 || $localVariantId < 1
        || ($externalBilling && ($sourceOrderId < 1 || $storeApiUnitPrice === null)) || !storeBridgeEnsureSchema()) {
        return ['success' => false, 'message' => 'Invalid purchase request'];
    }
    if (!$externalBilling && !ensureWalletLedgerSchema()) return ['success' => false, 'message' => 'Financial audit storage is unavailable. No balance was charged.'];

    if (!$externalBilling) {
        $blockingOrder = supplierBridgeFindBlockingPendingOrder($userId, $localVariantId);
        if ($blockingOrder) {
            return [
                'success' => false,
                'pending' => true,
                'code' => 'existing_pending_supplier_order',
                'order_id' => (int) ($blockingOrder['id'] ?? 0),
                'total' => (float) ($blockingOrder['total_price_base'] ?? 0),
                'message' => 'An earlier API order for this variant is still unresolved. Do not submit another order.',
            ];
        }
    }

    $link = supplierBridgeFindPurchaseLink($localProductId, $localVariantId, $supplierProductId, $userId, $quantity, $storeApiUnitPrice);
    if (!$link || (int) $link['id'] !== $supplierProductId) return ['success' => false, 'safe_to_failover' => true, 'code' => 'mapping_unavailable', 'message' => 'Supplier mapping is unavailable'];

    // Confirm this exact product before every API purchase. The old path
    // refreshed the entire catalogue and could report success even when this
    // product was absent or failed to save locally.
    $inventoryDegraded = false;
    $inventoryRefresh = supplierBridgeConfirmProductInventory($supplierProductId, $quantity, true, $retryInventoryTransient);
    $refreshedLink = supplierBridgeFindPurchaseLink($localProductId, $localVariantId, $supplierProductId, $userId, $quantity, $storeApiUnitPrice);
    if ($refreshedLink) $link = $refreshedLink;
    if (empty($inventoryRefresh['success'])) {
        // Unified checkout is fail-closed for stale supplier inventory by default.
        // If the exact product cannot be verified, the router may safely try the
        // next supplier before any money/order is committed. Reusing a stale
        // positive snapshot here would defeat multi-API failover and could keep
        // selecting an unhealthy source while a healthy backup has stock.
        $fallback = $allowBoundedInventoryFallback
            ? supplierBridgeCanUseBoundedInventoryFallback($link, $quantity, $inventoryRefresh)
            : ['allowed' => false, 'age_seconds' => null, 'stock' => 0, 'limit_seconds' => 0];
        if (empty($fallback['allowed'])) {
            $errorCode = (string) ($inventoryRefresh['error_code'] ?? 'inventory_unavailable');
            $code = $errorCode === 'credential_decryption_failed'
                ? 'supplier_credentials_unavailable'
                : 'supplier_inventory_unavailable';
            return [
                'success' => false,
                'safe_to_failover' => true,
                'code' => $code,
                'inventory_error_code' => $errorCode,
                'failure_class' => (string) ($inventoryRefresh['failure_class'] ?? ''),
                'http_code' => (int) ($inventoryRefresh['http_code'] ?? 0),
                'message' => $errorCode === 'credential_decryption_failed'
                    ? 'Supplier API credentials require administrator recovery'
                    : 'Latest supplier stock could not be verified',
            ];
        }
        $inventoryDegraded = true;
        error_log('Store Bridge checkout using bounded inventory fallback; connection=' . (int) $link['connection_id']
            . '; product=' . $supplierProductId
            . '; age=' . (int) ($fallback['age_seconds'] ?? -1)
            . '; stock=' . (int) ($fallback['stock'] ?? 0)
            . '; error=' . (string) ($inventoryRefresh['error_code'] ?? 'unknown')
            . '; class=' . (string) ($inventoryRefresh['failure_class'] ?? 'unknown'));
    }
    if (!$link) return ['success' => false, 'safe_to_failover' => true, 'code' => 'mapping_unavailable', 'message' => 'Supplier mapping is unavailable'];
    $providerType = strtolower(trim((string) ($link['provider_type'] ?? '')));
    $maxSupplierCost = isset($link['max_supplier_cost']) && is_numeric($link['max_supplier_cost'])
        ? round((float) $link['max_supplier_cost'], 2) : null;
    if (supplierBridgeProviderRequiresProtectedPurchase($providerType) && ($maxSupplierCost === null || $maxSupplierCost <= 0)) {
        return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_cost_guard_missing', 'message' => 'Max supplier cost is not configured for this supplier'];
    }
    if ($maxSupplierCost !== null && $maxSupplierCost > 0 && (float) $link['cost_base'] > $maxSupplierCost + 0.00001) {
        return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_cost_guard_exceeded', 'message' => 'Current supplier cost exceeds the configured maximum'];
    }
    $stock = max(0, (int) $link['remote_stock']);
    if ($stock < 1) return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_out_of_stock', 'message' => 'Supplier stock is sold out'];
    if ($quantity > $stock) return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_stock_insufficient', 'available_stock' => $stock, 'message' => 'Supplier stock is lower than requested quantity'];

    $lockName = storeBridgeLockName('order', (string) $link['connection_id'] . ':' . (string) $link['remote_product_id']);
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 12) AS acquired');
    if (!$lockStmt) return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_lock_unavailable', 'message' => 'Supplier order lock is unavailable'];
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();
    if ((int) ($lockRow['acquired'] ?? 0) !== 1) return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_product_busy', 'message' => 'Another order is processing this product'];

    // Re-read after acquiring the cross-request lock. Another checkout on this
    // website may have reduced the cached stock while this request was waiting.
    $link = supplierBridgeFindPurchaseLink($localProductId, $localVariantId, $supplierProductId, $userId, $quantity, $storeApiUnitPrice);
    if (!$link || (int) $link['id'] !== $supplierProductId) {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
        return ['success' => false, 'safe_to_failover' => true, 'code' => 'mapping_unavailable', 'message' => 'Supplier mapping changed during checkout'];
    }
    $lockedMaxSupplierCost = isset($link['max_supplier_cost']) && is_numeric($link['max_supplier_cost'])
        ? round((float) $link['max_supplier_cost'], 2) : null;
    if ((supplierBridgeProviderRequiresProtectedPurchase((string) ($link['provider_type'] ?? '')) && ($lockedMaxSupplierCost === null || $lockedMaxSupplierCost <= 0))
        || ($lockedMaxSupplierCost !== null && $lockedMaxSupplierCost > 0 && (float) $link['cost_base'] > $lockedMaxSupplierCost + 0.00001)) {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
        return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_cost_guard_exceeded', 'message' => 'Supplier cost guard blocked this source'];
    }
    $lockedStock = max(0, (int) $link['remote_stock']);
    if ($quantity > $lockedStock) {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
        return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_stock_insufficient', 'available_stock' => $lockedStock, 'message' => 'Supplier stock changed during checkout'];
    }

    $orderId = 0;
    $transactionId = 0;
    $totalPrice = 0.0;
    $reservationCommitted = false;
    $reservationCommitAttempted = false;
    $orderRequestStarted = false;
    $externalRef = supplierBridgeGenerateExternalRef((int) $link['connection_id']);
    try {
        $conn->begin_transaction();
        $user = null;
        if (!$externalBilling) {
            $userStmt = $conn->prepare("SELECT id,username,email,role,balance,status FROM users WHERE id=? LIMIT 1 FOR UPDATE");
            if (!$userStmt) throw new RuntimeException('Unable to lock user');
            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $user = $userResult ? $userResult->fetch_assoc() : null;
            $userStmt->close();
            if (!$user || (string) $user['status'] !== 'active') {
                $conn->rollback();
                return ['success' => false, 'code' => 'account_unavailable', 'message' => 'User account is inactive'];
            }

            // The user row lock serializes concurrent storefront purchases for this account.
            $blockingOrder = supplierBridgeFindBlockingPendingOrder($userId, $localVariantId, false);
            if ($blockingOrder) {
                $conn->rollback();
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'existing_pending_supplier_order',
                    'order_id' => (int) ($blockingOrder['id'] ?? 0),
                    'total' => (float) ($blockingOrder['total_price_base'] ?? 0),
                    'message' => 'An earlier API order for this variant is still unresolved. Do not submit another order.',
                ];
            }
        }
        $variantStmt = $conn->prepare("SELECT duration,price_user,price_reseller,cost_price,status FROM product_variants WHERE id=? AND product_id=? LIMIT 1 FOR UPDATE");
        if (!$variantStmt) throw new RuntimeException('Unable to lock local variant');
        $variantStmt->bind_param('ii', $localVariantId, $localProductId);
        $variantStmt->execute();
        $variantResult = $variantStmt->get_result();
        $variant = $variantResult ? $variantResult->fetch_assoc() : null;
        $variantStmt->close();
        if (!$variant || (string) $variant['status'] !== 'active') {
            $conn->rollback();
            return ['success' => false, 'code' => 'variant_unavailable', 'message' => 'Local variant is inactive'];
        }

        $providerCustomerName = $externalBilling ? '' : (string) ($user['username'] ?? '');
        $providerCustomerEmail = $externalBilling ? '' : (string) ($user['email'] ?? '');
        $providerOriginSiteId = commerceCenterSiteId();
        $providerOriginUserId = $externalBilling ? '' : (string) $userId;
        $providerCustomerRef = $providerOriginUserId !== '' ? commerceCenterCustomerRef($providerOriginSiteId, $providerOriginUserId) : '';

        if ($externalBilling) {
            $parentStmt = $conn->prepare("SELECT id,status,fulfillment_source,billing_mode,billing_user_id,source_product_id,source_variant_id,quantity,unit_price,total_price,
                                                customer_name,customer_email,origin_site_id,origin_user_id,customer_ref,refunded_at
                                         FROM store_api_orders WHERE id=? LIMIT 1 FOR UPDATE");
            if (!$parentStmt) throw new RuntimeException('Unable to lock parent Store API order');
            $parentStmt->bind_param('i', $sourceOrderId);
            $parentStmt->execute();
            $parentResult = $parentStmt->get_result();
            $parent = $parentResult ? $parentResult->fetch_assoc() : null;
            $parentStmt->close();
            $parentBillingMode = $parent ? storeBridgeNormalizeBillingMode($parent['billing_mode'] ?? 'api_balance') : 'api_balance';
            $parentBillingOwnerValid = $parentBillingMode === 'reseller_wallet'
                ? ($userId > 0 && (int) ($parent['billing_user_id'] ?? 0) === $userId)
                : ($userId === 0 && (int) ($parent['billing_user_id'] ?? 0) === 0);
            if (!$parent
                || strtolower(trim((string) ($parent['status'] ?? ''))) !== 'processing'
                || strtolower(trim((string) ($parent['fulfillment_source'] ?? ''))) !== 'supplier'
                || !$parentBillingOwnerValid
                || (int) ($parent['source_product_id'] ?? 0) !== $localProductId
                || (int) ($parent['source_variant_id'] ?? 0) !== $localVariantId
                || (int) ($parent['quantity'] ?? 0) !== $quantity
                || !empty($parent['refunded_at'])) {
                $conn->rollback();
                return ['success' => false, 'code' => 'parent_order_unavailable', 'message' => 'Store API parent order is not eligible for supplier procurement'];
            }
            $unitPrice = round((float) ($parent['unit_price'] ?? 0), 2);
            $totalPrice = round((float) ($parent['total_price'] ?? 0), 2);
            if ($unitPrice <= 0 || abs($unitPrice - (float) $storeApiUnitPrice) > 0.01 || abs(($unitPrice * $quantity) - $totalPrice) > 0.01) {
                $conn->rollback();
                return ['success' => false, 'code' => 'parent_price_invalid', 'message' => 'Store API parent pricing is invalid'];
            }
            $providerCustomerName = trim((string) ($parent['customer_name'] ?? '')) ?: $providerCustomerName;
            $providerCustomerEmail = trim((string) ($parent['customer_email'] ?? '')) ?: $providerCustomerEmail;
            $providerOriginSiteId = trim((string) ($parent['origin_site_id'] ?? '')) ?: $providerOriginSiteId;
            $providerOriginUserId = trim((string) ($parent['origin_user_id'] ?? '')) ?: $providerOriginUserId;
            $providerCustomerRef = trim((string) ($parent['customer_ref'] ?? ''));
            if ($providerCustomerRef === '') $providerCustomerRef = commerceCenterCustomerRef($providerOriginSiteId, $providerOriginUserId);
        } else {
            $unitPrice = (string) $user['role'] === 'reseller' ? (float) $variant['price_reseller'] : (float) $variant['price_user'];
            $unitPrice = getEffectiveResellerPrice($userId, $localVariantId, $unitPrice);
            $unitPrice = round((float) $unitPrice, 2);
            $totalPrice = round($unitPrice * $quantity, 2);
            if ((float) $user['balance'] + 0.00001 < $totalPrice) {
                $conn->rollback();
                return ['success' => false, 'code' => 'insufficient_balance', 'message' => 'Insufficient balance'];
            }
        }
        if ((int) ($link['protect_below_cost'] ?? 1) === 1 && $unitPrice + 0.00001 < (float) $link['cost_base']) {
            $conn->rollback();
            return ['success' => false, 'safe_to_failover' => true, 'code' => 'supplier_price_blocked', 'message' => 'This supplier source is above the allowed selling price'];
        }
        $cost = round((float) $link['cost_base'], 2);
        $totalCost = round($cost * $quantity, 2);
        $order = $conn->prepare("INSERT INTO supplier_orders
            (external_ref,source_kind,source_order_id,connection_id,supplier_product_id,user_id,local_product_id,local_variant_id,quantity,unit_cost_base,total_cost_base,unit_price_base,total_price_base,status)
            VALUES (?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,'submitting')");
        if (!$order) throw new RuntimeException('Unable to prepare supplier order');
        $connectionId = (int) $link['connection_id'];
        $supplierUserId = $externalBilling ? 0 : $userId;
        $order->bind_param('ssiiiiiiidddd', $externalRef, $sourceKind, $sourceOrderId, $connectionId, $supplierProductId, $supplierUserId, $localProductId, $localVariantId, $quantity, $cost, $totalCost, $unitPrice, $totalPrice);
        if (!$order->execute()) { $order->close(); throw new RuntimeException('Unable to create supplier order'); }
        $orderId = (int) $conn->insert_id;
        $order->close();

        if (!$externalBilling) {
            $debit = $conn->prepare('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?');
            if (!$debit) throw new RuntimeException('Unable to prepare balance debit');
            $debit->bind_param('did', $totalPrice, $userId, $totalPrice);
            if (!$debit->execute() || $debit->affected_rows !== 1) { $debit->close(); throw new RuntimeException('Balance changed during checkout'); }
            $debit->close();
            $description = 'Store Bridge order ' . $externalRef . ': ' . (string) $link['name'] . ' - ' . (string) $variant['duration'] . ' x' . $quantity;
            $transactionId = (int) createTransaction($userId, 'supplier_purchase', $totalPrice, 'pending', $description, $orderId);
            if ($transactionId < 1) throw new RuntimeException('Unable to create purchase transaction');
            $updateOrder = $conn->prepare('UPDATE supplier_orders SET transaction_id=? WHERE id=?');
            if (!$updateOrder) throw new RuntimeException('Unable to link purchase transaction');
            $updateOrder->bind_param('ii', $transactionId, $orderId);
            if (!$updateOrder->execute()) { $updateOrder->close(); throw new RuntimeException('Unable to link purchase transaction'); }
            $updateOrder->close();
            $walletBefore = round((float) $user['balance'], 2);
            $walletAfter = round($walletBefore - $totalPrice, 2);
            if (!walletLedgerRecordMovement(
                $userId, -$totalPrice, $walletBefore, $walletAfter,
                'supplier_purchase', 'transaction:' . $transactionId, $orderId, $transactionId, null,
                'ซื้อสินค้าผ่าน Supplier x' . $quantity,
                'Balance reserved for Store Bridge order #' . $orderId . '.',
                $externalRef, true
            )) {
                throw new RuntimeException('Unable to write supplier purchase wallet audit');
            }
        }
        $reservationCommitAttempted = true;
        if (!$conn->commit()) throw new RuntimeException('Unable to commit supplier order reservation');
        $reservationCommitted = true;
        if (!$externalBilling) $_SESSION['balance'] = getUserBalance($userId);

        $connection = supplierBridgeGetConnection((int) $link['connection_id']);
        if (!$connection) throw new RuntimeException('Supplier connection disappeared');
        // From this line onward, a lost/malformed response is ambiguous: the
        // provider may have committed the external_ref even if we never receive
        // a usable reply. Never fail over to another supplier in that state.
        $orderRequestStarted = true;
        $orderPayload = [
            'external_ref' => $externalRef,
            'product_id' => (string) $link['remote_product_id'],
            'quantity' => $quantity,
            'origin_site_id' => $providerOriginSiteId,
            'origin_user_id' => $providerOriginUserId,
            'customer_ref' => $providerCustomerRef,
            'customer_name' => $providerCustomerName,
            'customer_email' => $providerCustomerEmail,
        ];
        if (supplierBridgeProviderRequiresProtectedPurchase((string) ($connection['provider_type'] ?? ''))) {
            $orderPayload['_max_supplier_cost'] = $lockedMaxSupplierCost;
        }
        $api = supplierBridgeApiRequest($connection, 'order', 'POST', $orderPayload);
        $providerType = strtolower(trim((string) ($connection['provider_type'] ?? '')));
        $storedResponse = is_array($api['data'] ?? null)
            ? supplierBridgeRedactStoredOrderResponse($api['data'], $providerType)
            : null;
        $responseJson = is_array($storedResponse) ? json_encode($storedResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        if (!$api['ok'] || !is_array($api['data'])) {
            $httpCode = (int) ($api['http_code'] ?? 0);
            $apiErrorCode = strtolower(trim((string) ($api['error_code'] ?? '')));
            $definitiveNoOrder = supplierBridgeProviderProvesOrderNotCreated($connection, $api);
            $requiresExplicitNoOrderProof = supplierBridgeProviderRequiresProtectedPurchase($providerType);
            $errorKeys = is_array($api['data'] ?? null) ? supplierBridgeExtractKeys($api['data']) : [];
            if ($errorKeys !== []) {
                supplierBridgeStoreOrderKeys($orderId, $errorKeys);
                $storedKeyCount = supplierBridgeStoredOrderKeyCount($orderId);
                $reviewMessage = 'Supplier returned an error together with delivered key data (' . $storedKeyCount . '/' . $quantity . '). Automatic refund/failover was blocked.';
                $stmt = $conn->prepare("UPDATE supplier_orders SET status='manual_review',response_json=?,error_message=?,updated_at=NOW() WHERE id=?");
                if ($stmt) { $stmt->bind_param('ssi', $responseJson, $reviewMessage, $orderId); $stmt->execute(); $stmt->close(); }
                supplierBridgeCommerceSyncOrder($orderId);
                return [
                    'success' => false,
                    'pending' => true,
                    'manual_review' => true,
                    'code' => 'supplier_delivery_conflict',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => 'Supplier returned delivery data with an error. The order requires manual review and must not be retried.',
                ];
            }
            $ambiguous = !$definitiveNoOrder && (
                $requiresExplicitNoOrderProof
                ||
                !empty($api['transport_error'])
                || $httpCode === 0
                || in_array($httpCode, [408, 425, 429], true)
                || $httpCode >= 500
                // A 2xx reply with an unreadable/truncated body cannot prove the
                // order failed. The provider may already have committed it.
                || ($httpCode >= 200 && $httpCode < 300)
                || in_array($apiErrorCode, ['invalid_json', 'response_too_large'], true)
            );
            if ($ambiguous) {
                $message = substr((string) ($api['error'] ?? 'Supplier response is uncertain'), 0, 2000);
                $uncertainStatus = supplierBridgeProviderRequiresProtectedPurchase($providerType) ? 'manual_review' : 'unknown';
                $stmt = $conn->prepare('UPDATE supplier_orders SET status=?,response_json=?,error_message=? WHERE id=?');
                if ($stmt) { $stmt->bind_param('sssi', $uncertainStatus, $responseJson, $message, $orderId); $stmt->execute(); $stmt->close(); }
                supplierBridgeCommerceSyncOrder($orderId);
                return [
                    'success' => false,
                    'pending' => true,
                    'manual_review' => supplierBridgeProviderRequiresProtectedPurchase($providerType),
                    'code' => 'supplier_response_uncertain',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => 'Supplier response is uncertain. Your balance is reserved for manual review. Do not retry this variant.',
                ];
            }
            $reason = (string) ($api['error'] ?? 'Supplier rejected the order');
            $providerCode = (string) ($api['error_code'] ?? 'supplier_rejected');
            if ($definitiveNoOrder) {
                supplierBridgeRecordInventoryFailure($supplierProductId, $providerCode, $reason);
                $refunded = supplierBridgeRefundOrder($orderId, $reason);
                return [
                    'success' => false,
                    'pending' => !$refunded,
                    'safe_to_failover' => $refunded,
                    'balance_refunded' => $refunded,
                    'code' => $refunded ? 'supplier_order_not_created_refunded' : 'supplier_refund_pending',
                    'inventory_error_code' => $providerCode,
                    'reload_storefront' => false,
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => $refunded
                        ? 'Supplier confirmed that no order was created. Reserved balance was refunded.'
                        : 'Supplier confirmed that no order was created, but the local refund requires reconciliation.',
                ];
            }
            supplierBridgeApplyOrderInventoryFailure($supplierProductId, $providerCode, $reason);
            $refunded = supplierBridgeRefundOrder($orderId, $reason);
            return [
                'success' => false,
                'pending' => !$refunded,
                'safe_to_failover' => $refunded,
                'balance_refunded' => $refunded,
                'code' => $refunded ? 'supplier_rejected' : 'supplier_refund_pending',
                'inventory_error_code' => $providerCode,
                'reload_storefront' => true,
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => $refunded ? $reason : 'Supplier rejected the order and refund requires manual review',
            ];
        }
        $data = $api['data'];
        $payloadData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        $keys = supplierBridgeExtractKeys($data);
        $status = strtolower(trim((string) ($payloadData['status'] ?? ($data['status'] ?? 'success'))));
        $accepted = (($data['success'] ?? true) !== false) && !in_array($status, ['failed','error','rejected','refunded'], true);
        if (!$accepted) {
            $reason = trim((string) ($data['message'] ?? $payloadData['message'] ?? 'Supplier rejected the order'));
            $providerCode = strtolower(trim((string) ($data['code'] ?? $payloadData['code'] ?? $status ?: 'supplier_rejected')));
            if ($keys !== []) {
                supplierBridgeStoreOrderKeys($orderId, $keys);
                $storedKeyCount = supplierBridgeStoredOrderKeyCount($orderId);
                $reviewMessage = 'Supplier rejected the order but also returned key data (' . $storedKeyCount . '/' . $quantity . '). Automatic refund/failover was blocked.';
                $stmt = $conn->prepare("UPDATE supplier_orders SET status='manual_review',response_json=?,error_message=? WHERE id=?");
                if ($stmt) { $stmt->bind_param('ssi', $responseJson, $reviewMessage, $orderId); $stmt->execute(); $stmt->close(); }
                supplierBridgeCommerceSyncOrder($orderId);
                return [
                    'success' => false,
                    'pending' => true,
                    'code' => 'supplier_delivery_conflict',
                    'order_id' => $orderId,
                    'total' => $totalPrice,
                    'message' => 'Supplier returned key data with a rejected status. The order requires reconciliation and must not be retried.',
                ];
            }
            supplierBridgeApplyOrderInventoryFailure($supplierProductId, $providerCode, $reason);
            $refunded = supplierBridgeRefundOrder($orderId, $reason);
            return [
                'success' => false,
                'pending' => !$refunded,
                'safe_to_failover' => $refunded,
                'balance_refunded' => $refunded,
                'code' => $refunded ? 'supplier_rejected' : 'supplier_refund_pending',
                'inventory_error_code' => $providerCode,
                'reload_storefront' => true,
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => $refunded ? $reason : 'Supplier rejected the order and refund requires manual review',
            ];
        }
        $supplierOrderId = trim((string) ($payloadData['order_id'] ?? $payloadData['id'] ?? ''));
        if (supplierBridgeProviderRequiresProtectedPurchase($providerType) && is_numeric($payloadData['price_used'] ?? null)) {
            $actualUnitCost = round(max(0.0, (float) $payloadData['price_used']), 2);
            $actualTotalCost = is_numeric($payloadData['total_deducted'] ?? null)
                ? round(max(0.0, (float) $payloadData['total_deducted']), 2)
                : round($actualUnitCost * $quantity, 2);
            $costUpdate = $conn->prepare('UPDATE supplier_orders SET unit_cost_base=?,total_cost_base=? WHERE id=?');
            if ($costUpdate) {
                $costUpdate->bind_param('ddi', $actualUnitCost, $actualTotalCost, $orderId);
                $costUpdate->execute();
                $costUpdate->close();
            }
            $productCostUpdate = $conn->prepare('UPDATE supplier_products SET cost_base=? WHERE id=?');
            if ($productCostUpdate) {
                $productCostUpdate->bind_param('di', $actualUnitCost, $supplierProductId);
                $productCostUpdate->execute();
                $productCostUpdate->close();
            }
        }
        supplierBridgeStoreOrderKeys($orderId, $keys);
        $storedKeyCount = supplierBridgeStoredOrderKeyCount($orderId);
        $validKeyCount = $storedKeyCount === $quantity;
        $finalStatus = $validKeyCount ? 'success' : ($storedKeyCount === 0 ? 'processing' : 'manual_review');
        $storageMessage = $validKeyCount ? null : ($storedKeyCount === 0 ? null : ('Delivered key count does not match quantity (' . $storedKeyCount . '/' . $quantity . ')'));
        $update = $conn->prepare("UPDATE supplier_orders SET status=?,supplier_order_id=?,response_json=?,error_message=?,completed_at=IF(?='success',NOW(),completed_at) WHERE id=?");
        if ($update) {
            $update->bind_param('sssssi', $finalStatus, $supplierOrderId, $responseJson, $storageMessage, $finalStatus, $orderId);
            $update->execute();
            $update->close();
        }
        if ($finalStatus === 'success' && $transactionId > 0) updateTransactionStatus($transactionId, 'completed');
        $stockUpdate = $conn->prepare('UPDATE supplier_products SET remote_stock=GREATEST(remote_stock-?,0),inventory_checked_at=NOW() WHERE id=?');
        if ($stockUpdate) { $stockUpdate->bind_param('ii', $quantity, $supplierProductId); $stockUpdate->execute(); $stockUpdate->close(); }
        if ($userId > 0) logHistory($userId, 'supplier_order', 'Store Bridge order ' . $externalRef . ' status=' . $finalStatus . '; quantity=' . $quantity);
        supplierBridgeCommerceSyncOrder($orderId);
        return [
            'success' => $finalStatus === 'success',
            'processing' => $finalStatus === 'processing',
            'pending' => $finalStatus === 'manual_review',
            'manual_review' => $finalStatus === 'manual_review',
            'order_id' => $orderId,
            'keys' => $finalStatus === 'success' ? $keys : [],
            'total' => $totalPrice,
            'inventory_degraded' => $inventoryDegraded,
            'message' => $finalStatus === 'success' ? 'Order completed' : 'Order accepted and requires follow-up',
        ];
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $exceptionMessage = substr($e->getMessage(), 0, 2000);
        error_log('Supplier purchase failed: ' . $exceptionMessage);

        // An order id can be allocated inside a transaction that later rolls
        // back. A failed COMMIT is special: the database may have committed
        // before the client learned the result. No supplier POST occurs before
        // this point, so verify external_ref and refund locally if it exists.
        if (!$reservationCommitted && $reservationCommitAttempted) {
            $durableOrderId = 0;
            $lookupCompleted = false;
            try {
                $lookup = $conn->prepare('SELECT id FROM supplier_orders WHERE external_ref=? LIMIT 1');
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
                $orderId = $durableOrderId;
                $refunded = supplierBridgeRefundOrder($orderId, 'Supplier reservation commit was uncertain before any provider request was sent.');
                return [
                    'success' => false, 'pending' => !$refunded, 'safe_to_failover' => $refunded,
                    'balance_refunded' => $refunded,
                    'code' => $refunded ? 'supplier_reservation_commit_refunded' : 'supplier_reservation_commit_uncertain',
                    'order_id' => $orderId, 'total' => $totalPrice,
                    'message' => $refunded
                        ? 'Supplier order was not sent. The uncertain reservation was found and refunded safely.'
                        : 'Supplier order was not sent, but the reservation commit/refund still requires reconciliation. Do not retry yet.',
                ];
            }
            if ($lookupCompleted) {
                return [
                    'success' => false, 'pending' => false, 'safe_to_failover' => true,
                    'code' => 'supplier_reservation_not_committed',
                    'message' => 'Supplier order was not sent and no durable reservation was found. No balance was charged.',
                ];
            }
            return [
                'success' => false, 'pending' => true,
                'code' => 'supplier_reservation_commit_uncertain',
                'order_id' => $orderId > 0 ? $orderId : 0, 'total' => $totalPrice,
                'message' => 'Supplier order was not sent, but the local reservation commit could not be verified. Do not retry yet.',
            ];
        }
        if (!$reservationCommitted) {
            return [
                'success' => false,
                'pending' => false,
                'code' => 'supplier_reservation_failed',
                'message' => 'Unable to reserve supplier order. No balance was charged.',
            ];
        }

        // If the reservation committed but no HTTP order attempt began, upstream
        // cannot have received this order. Refund locally before allowing any
        // failover. This avoids trapping balance in manual review for a purely
        // local post-commit failure (for example a connection configuration race).
        if (!$orderRequestStarted && $orderId > 0) {
            $refunded = supplierBridgeRefundOrder($orderId, $exceptionMessage);
            return [
                'success' => false,
                'pending' => !$refunded,
                'safe_to_failover' => $refunded,
                'balance_refunded' => $refunded,
                'code' => $refunded ? 'supplier_pre_request_failure_refunded' : 'supplier_refund_pending',
                'order_id' => $orderId,
                'total' => $totalPrice,
                'message' => $refunded
                    ? 'Supplier order was not sent and the reserved balance was refunded.'
                    : 'The order was not sent, but the refund requires manual review.',
            ];
        }

        // Once the POST may have left this server, fail closed. Reconciliation by
        // external_ref is the only safe next step; trying another source could
        // create a second delivery.
        if ($orderId > 0) {
            $stmt = $conn->prepare("UPDATE supplier_orders SET status='unknown',error_message=? WHERE id=?");
            if ($stmt) { $stmt->bind_param('si', $exceptionMessage, $orderId); $stmt->execute(); $stmt->close(); }
            supplierBridgeCommerceSyncOrder($orderId);
        }
        return [
            'success' => false,
            'pending' => $orderId > 0,
            'code' => 'supplier_response_uncertain',
            'order_id' => $orderId,
            'total' => $totalPrice,
            'message' => $orderId > 0 ? 'Order requires reconciliation. Do not retry.' : 'Unable to send supplier order.',
        ];
    } finally {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if ($release) { $release->bind_param('s', $lockName); $release->execute(); $release->close(); }
    }
}

function supplierBridgeGetDeliveredKeysForUser(int $userId, ?int $limit = null, int $offset = 0, string $search = ''): array
{
    global $conn;
    if ($userId < 1 || !storeBridgeEnsureSchema()) return [];
    $statusSql = function_exists('commerceSuccessfulStatusSql')
        ? commerceSuccessfulStatusSql('so')
        : "LOWER(TRIM(COALESCE(so.status, ''))) IN ('success','completed')";
    $offset = max(0, $offset);
    $search = substr(trim($search), 0, 180);
    $where = ['so.user_id=?'];
    $types = 'i';
    $params = [$userId];
    if ($search !== '') {
        $where[] = "(LOCATE(?, sok.key_code) > 0 OR LOCATE(?, COALESCE(p.name,sp.name,'')) > 0 OR LOCATE(?, COALESCE(pv.duration,sp.duration,'')) > 0)";
        $types .= 'sss';
        array_push($params, $search, $search, $search);
    }
    $sql = "SELECT sok.id AS order_key_id,sok.key_code,so.id AS order_id,
                   COALESCE(so.transaction_id,0) AS transaction_id,
                   so.unit_price_base,so.local_product_id,
                   COALESCE(so.completed_at,so.updated_at,so.created_at) AS sold_at,
                   COALESCE(p.name,sp.name,'') AS product_name,
                   COALESCE(pv.duration,sp.duration,'') AS duration
            FROM supplier_order_keys sok
            JOIN supplier_orders so ON so.id=sok.order_id AND {$statusSql}
            LEFT JOIN products p ON p.id=so.local_product_id
            LEFT JOIN product_variants pv ON pv.id=so.local_variant_id
            LEFT JOIN supplier_products sp ON sp.id=so.supplier_product_id
            WHERE " . implode(' AND ', $where);
    if ($limit !== null) {
        // Match the legacy unified PHP strcmp() tie-breaker exactly for bounded reads.
        $sql .= " ORDER BY sold_at DESC, CONVERT(CONCAT('supplier-',sok.id) USING utf8mb4) COLLATE utf8mb4_bin DESC";
        $limit = max(1, min(1000, $limit));
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    } else {
        $sql .= ' ORDER BY sold_at DESC,sok.id DESC';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $bind = [$types];
    foreach ($params as $i => $_value) $bind[] = &$params[$i];
    if (!call_user_func_array([$stmt, 'bind_param'], $bind) || !$stmt->execute()) { $stmt->close(); return []; }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $rows[] = [
            'id' => 'supplier-' . (int) $row['order_key_id'],
            'key_code' => (string) $row['key_code'],
            'product_name' => (string) $row['product_name'],
            'duration' => (string) $row['duration'],
            'price_user' => (float) $row['unit_price_base'],
            'price_reseller' => (float) $row['unit_price_base'],
            'purchase_price' => (float) $row['unit_price_base'],
            'sold_at' => (string) $row['sold_at'],
            'source' => 'supplier',
            'order_id' => (int) $row['order_id'],
            'transaction_id' => (int) ($row['transaction_id'] ?? 0),
            'product_id' => (int) $row['local_product_id'],
        ];
    }
    $stmt->close();
    return $rows;
}


function supplierBridgeCountDeliveredKeysForUser(int $userId, string $search = ''): int
{
    global $conn;
    if ($userId < 1 || !storeBridgeEnsureSchema()) return 0;
    $statusSql = function_exists('commerceSuccessfulStatusSql')
        ? commerceSuccessfulStatusSql('so')
        : "LOWER(TRIM(COALESCE(so.status, ''))) IN ('success','completed')";
    $search = substr(trim($search), 0, 180);
    $sql = "SELECT COUNT(*) AS total
            FROM supplier_order_keys sok
            JOIN supplier_orders so ON so.id=sok.order_id AND {$statusSql}
            LEFT JOIN products p ON p.id=so.local_product_id
            LEFT JOIN product_variants pv ON pv.id=so.local_variant_id
            LEFT JOIN supplier_products sp ON sp.id=so.supplier_product_id
            WHERE so.user_id=?";
    $types = 'i';
    $params = [$userId];
    if ($search !== '') {
        $sql .= " AND (LOCATE(?, sok.key_code) > 0 OR LOCATE(?, COALESCE(p.name,sp.name,'')) > 0 OR LOCATE(?, COALESCE(pv.duration,sp.duration,'')) > 0)";
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

function supplierBridgeGetOrders(int $limit = 100): array
{
    global $conn;
    if (!storeBridgeEnsureSchema()) return [];
    $limit = max(1, min(500, $limit));
    $result = $conn->query("SELECT so.*,sc.provider_type,
        COALESCE(sc.name,CONCAT('Supplier #',so.connection_id)) AS connection_name,
        COALESCE(u.username,CONCAT('Deleted user #',so.user_id)) AS username,
        COALESCE(p.name,sp.name,CONCAT('Product #',so.local_product_id)) AS product_name,
        COALESCE(pv.duration,sp.duration,'') AS duration,
        (SELECT COUNT(*) FROM supplier_order_keys sok WHERE sok.order_id=so.id) AS delivered_count
        FROM supplier_orders so
        LEFT JOIN supplier_connections sc ON sc.id=so.connection_id
        LEFT JOIN users u ON u.id=so.user_id
        LEFT JOIN products p ON p.id=so.local_product_id
        LEFT JOIN product_variants pv ON pv.id=so.local_variant_id
        LEFT JOIN supplier_products sp ON sp.id=so.supplier_product_id
        ORDER BY so.id DESC LIMIT " . $limit);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function supplierBridgeReconcileOrder(int $orderId): array
{
    global $conn;
    if ($orderId < 1 || !storeBridgeEnsureSchema()) return ['success' => false, 'message' => 'Invalid order'];
    $stmt = $conn->prepare("SELECT so.*,sc.provider_type,sc.endpoint_url,sc.api_key_ciphertext,sc.connect_timeout,sc.request_timeout FROM supplier_orders so JOIN supplier_connections sc ON sc.id=so.connection_id WHERE so.id=? LIMIT 1");
    if (!$stmt) return ['success' => false, 'message' => 'Unable to load order'];
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$order) return ['success' => false, 'message' => 'Order not found'];
    if (in_array((string) $order['status'], ['success','refunded'], true)) return ['success' => true, 'message' => 'Order is already final'];
    if (supplierBridgeProviderRequiresProtectedPurchase((string) ($order['provider_type'] ?? ''))) {
        if ((string) $order['status'] !== 'manual_review') {
            $message = 'This supplier has no verified purchase-history endpoint. This order requires manual review; automatic retry/refund is blocked.';
            $update = $conn->prepare("UPDATE supplier_orders SET status='manual_review',error_message=?,updated_at=NOW() WHERE id=? AND status<>'success' AND status<>'refunded'");
            if ($update) { $update->bind_param('si', $message, $orderId); $update->execute(); $update->close(); }
        }
        return ['success' => false, 'pending' => true, 'manual_review' => true, 'message' => 'Automatic reconciliation is unavailable; verify the supplier account manually before resolving this order.'];
    }
    $api = supplierBridgeApiRequest($order, 'order_status', 'GET', ['external_ref' => (string) $order['external_ref']], 1);
    if (supplierBridgeOrderStatusDefinitelyNotFound($api, (string) $order['external_ref'])) {
        $createdTs = strtotime((string) ($order['created_at'] ?? ''));
        $ageSeconds = $createdTs === false ? 0 : max(0, time() - $createdTs);
        if ($ageSeconds < supplierBridgeOrderNotFoundGraceSeconds()) {
            $message = 'Supplier has not found this order reference yet; automatic verification will retry during the short grace window.';
            $update = $conn->prepare("UPDATE supplier_orders SET status='unknown',error_message=?,updated_at=NOW() WHERE id=?");
            if ($update) {
                $update->bind_param('si', $message, $orderId);
                $update->execute();
                $update->close();
            }
            return ['success' => false, 'pending' => true, 'message' => $message];
        }
        if (supplierBridgeStoredOrderKeyCount($orderId) > 0) {
            $message = 'Supplier reports that the order reference does not exist, but delivery data is already stored. Automatic refund was blocked.';
            $update = $conn->prepare("UPDATE supplier_orders SET status='manual_review',error_message=?,updated_at=NOW() WHERE id=?");
            if ($update) {
                $update->bind_param('si', $message, $orderId);
                $update->execute();
                $update->close();
            }
            supplierBridgeCommerceSyncOrder($orderId);
            return ['success' => false, 'pending' => true, 'message' => $message];
        }
        $reason = 'Supplier confirmed that the order reference does not exist';
        $refunded = supplierBridgeRefundOrder($orderId, $reason);
        return $refunded
            ? ['success' => true, 'refunded' => true, 'message' => 'Supplier did not receive the order. The customer balance was refunded.']
            : ['success' => false, 'pending' => true, 'message' => 'Supplier did not receive the order, but the refund requires manual review.'];
    }
    if (!$api['ok'] || !is_array($api['data'])) {
        $message = substr(trim((string) ($api['error'] ?? 'Unable to query supplier order')), 0, 2000);
        $nextStatus = (string) ($order['status'] ?? '') === 'manual_review' ? 'manual_review' : 'unknown';
        $update = $conn->prepare("UPDATE supplier_orders SET status=?,error_message=?,updated_at=NOW() WHERE id=? AND status<>'success'");
        if ($update) {
            $update->bind_param('ssi', $nextStatus, $message, $orderId);
            $update->execute();
            $update->close();
        }
        return ['success' => false, 'pending' => true, 'message' => $message];
    }
    $data = $api['data'];
    $payload = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    $status = strtolower(trim((string) ($payload['status'] ?? '')));
    $keys = supplierBridgeExtractKeys($data);
    if ($keys !== []) supplierBridgeStoreOrderKeys($orderId, $keys);
    $storedKeyCount = supplierBridgeStoredOrderKeyCount($orderId);
    $requiredKeyCount = max(1, (int) $order['quantity']);
    if (in_array($status, ['failed','error','rejected','refunded'], true)) {
        $reason = trim((string) ($payload['message'] ?? $data['message'] ?? 'Supplier order failed'));
        if ($storedKeyCount > 0) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $reviewMessage = 'Supplier reports failure but ' . $storedKeyCount . '/' . $requiredKeyCount . ' key(s) are already stored. Automatic refund was blocked.';
            $update = $conn->prepare("UPDATE supplier_orders SET status='manual_review',response_json=?,error_message=? WHERE id=?");
            if ($update) { $update->bind_param('ssi', $json, $reviewMessage, $orderId); $update->execute(); $update->close(); }
            supplierBridgeCommerceSyncOrder($orderId);
            return ['success' => false, 'message' => 'Supplier status conflicts with delivered keys; manual review is required.'];
        }
        return supplierBridgeRefundOrder($orderId, $reason) ? ['success' => true, 'message' => 'Order was refunded'] : ['success' => false, 'message' => 'Order failed but refund requires manual review'];
    }
    if ($storedKeyCount > 0) {
        $final = $storedKeyCount === $requiredKeyCount ? 'success' : 'manual_review';
        $supplierOrderId = trim((string) ($payload['order_id'] ?? $payload['id'] ?? ''));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $reviewMessage = $final === 'success' ? null : ('Delivered key count does not match quantity (' . $storedKeyCount . '/' . $requiredKeyCount . ')');
        $update = $conn->prepare("UPDATE supplier_orders SET status=?,supplier_order_id=?,response_json=?,error_message=?,completed_at=IF(?='success',NOW(),completed_at) WHERE id=?");
        if ($update) { $update->bind_param('sssssi',$final,$supplierOrderId,$json,$reviewMessage,$final,$orderId); $update->execute(); $update->close(); }
        if ($final === 'success' && (int) $order['transaction_id'] > 0) updateTransactionStatus((int) $order['transaction_id'], 'completed');
        // Initial accepted/processing responses already reduce the cached stock.
        // Only an ambiguous request left as unknown/submitting needs that adjustment
        // when reconciliation later confirms delivery.
        if (in_array((string) $order['status'], ['unknown', 'submitting'], true)) {
            $stockUpdate = $conn->prepare('UPDATE supplier_products SET remote_stock=GREATEST(remote_stock-?,0),inventory_checked_at=NOW() WHERE id=?');
            if ($stockUpdate) {
                $quantity = max(0, (int) $order['quantity']);
                $supplierProductId = (int) $order['supplier_product_id'];
                $stockUpdate->bind_param('ii', $quantity, $supplierProductId);
                $stockUpdate->execute();
                $stockUpdate->close();
            }
        }
        return [
            'success' => $final === 'success',
            'keys' => $keys,
            'message' => $final === 'success' ? 'Order completed' : ('Key count requires manual review (' . $storedKeyCount . '/' . $requiredKeyCount . ')'),
        ];
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) $json = null;
    $safeStatus = in_array($status, ['pending','processing'], true) ? $status : 'processing';
    $update = $conn->prepare("UPDATE supplier_orders
                              SET status=IF(status='success','success',?),response_json=?,error_message=NULL,updated_at=NOW()
                              WHERE id=?");
    if ($update) {
        $update->bind_param('ssi', $safeStatus, $json, $orderId);
        $update->execute();
        $update->close();
    }
    supplierBridgeCommerceSyncOrder($orderId);
    return ['success' => false, 'pending' => true, 'message' => 'Supplier order is still processing'];
}

function supplierBridgeGetStorefrontOrderState(int $orderId, int $userId, bool $reconcileIfDue = true): array
{
    global $conn;
    if ($orderId < 1 || $userId < 1 || !storeBridgeEnsureSchema()) {
        return ['success' => false, 'code' => 'invalid_order', 'message' => 'Invalid order'];
    }

    $loadOrder = static function () use ($conn, $orderId, $userId): ?array {
        $stmt = $conn->prepare("SELECT id,user_id,status,total_price_base,created_at,updated_at,completed_at
                                FROM supplier_orders WHERE id=? AND user_id=? LIMIT 1");
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
    if (!$order) return ['success' => false, 'code' => 'order_not_found', 'message' => 'Order not found'];

    $pendingStatuses = ['submitting','unknown','pending','processing','manual_review'];
    $status = strtolower(trim((string) ($order['status'] ?? '')));
    $createdTs = strtotime((string) ($order['created_at'] ?? ''));
    $updatedTs = strtotime((string) ($order['updated_at'] ?? ''));
    $now = time();
    $ageSeconds = $createdTs === false ? 0 : max(0, $now - $createdTs);
    $sinceUpdate = $updatedTs === false ? PHP_INT_MAX : max(0, $now - $updatedTs);

    if ($reconcileIfDue
        && in_array($status, $pendingStatuses, true)
        && $sinceUpdate >= supplierBridgeOrderReconcileMinIntervalSeconds()) {
        supplierBridgeReconcileOrder($orderId);
        $order = $loadOrder() ?: $order;
        $status = strtolower(trim((string) ($order['status'] ?? $status)));
        $createdTs = strtotime((string) ($order['created_at'] ?? ''));
        $ageSeconds = $createdTs === false ? $ageSeconds : max(0, time() - $createdTs);
    }

    // Keep product credentials out of this lightweight polling response.

    $refunded = $status === 'refunded';
    $completed = $status === 'success';
    $pending = in_array($status, $pendingStatuses, true);
    $deadline = supplierBridgeOrderCustomerDeadlineSeconds();
    $remaining = max(0, $deadline - $ageSeconds);

    return [
        'success' => true,
        'order_id' => $orderId,
        'source' => 'supplier',
        'status' => $status,
        'terminal' => $completed || $refunded,
        'completed' => $completed,
        'refunded' => $refunded,
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

