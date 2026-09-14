-- Sakazuki Store Bridge schema v2.1
-- Run this only when the database account used by PHP cannot create tables.
-- The application creates the same tables automatically from admin/api_hub.php.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS store_api_clients (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_webhook_test_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_orders (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_order_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            source_key_id BIGINT UNSIGNED NOT NULL,
            key_code TEXT NOT NULL,
            key_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_api_order_key (order_id, key_hash),
            UNIQUE KEY uq_store_api_source_key (source_key_id),
            KEY idx_store_api_order_keys_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_balance_ledger (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_request_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_diagnostic_probes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_shared_claims (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_api_client_products (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_connections (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            provider_type VARCHAR(60) NOT NULL DEFAULT 'sakazuki_v1',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_products (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_catalog_products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            remote_source_product_id VARCHAR(190) NOT NULL,
            local_product_id INT NOT NULL,
            sync_details TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_catalog_source (connection_id, remote_source_product_id),
            KEY idx_supplier_catalog_local_product (local_product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_catalog_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_product_id BIGINT UNSIGNED NOT NULL,
            local_product_id INT NOT NULL,
            local_variant_id INT NOT NULL,
            api_fallback_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sync_duration TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_catalog_product (supplier_product_id),
            KEY idx_supplier_catalog_variant (local_variant_id),
            KEY idx_supplier_catalog_local (local_product_id, local_variant_id),
            KEY idx_supplier_catalog_fallback (api_fallback_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_ref VARCHAR(120) NOT NULL,
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
            KEY idx_supplier_order_connection (connection_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_order_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            key_code TEXT NOT NULL,
            key_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_supplier_order_key (order_id, key_hash),
            KEY idx_supplier_order_key_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_inventory_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations need these columns as well. MariaDB 10.11 supports
-- ADD COLUMN IF NOT EXISTS, making this file safe on both project databases.
ALTER TABLE supplier_products ADD COLUMN IF NOT EXISTS inventory_revision VARCHAR(190) NULL AFTER inventory_checked_at;
ALTER TABLE supplier_products ADD COLUMN IF NOT EXISTS inventory_last_success_at DATETIME NULL AFTER inventory_revision;
ALTER TABLE supplier_products ADD COLUMN IF NOT EXISTS inventory_last_error_code VARCHAR(80) NULL AFTER inventory_last_success_at;
ALTER TABLE supplier_products ADD COLUMN IF NOT EXISTS inventory_last_error_message VARCHAR(1000) NULL AFTER inventory_last_error_code;
ALTER TABLE supplier_products ADD COLUMN IF NOT EXISTS inventory_last_error_at DATETIME NULL AFTER inventory_last_error_message;


-- Stable customer identity for Store API orders created before v1.6.
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS origin_site_id VARCHAR(100) NULL AFTER customer_email;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS origin_user_id VARCHAR(190) NULL AFTER origin_site_id;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS customer_ref VARCHAR(255) NULL AFTER origin_user_id;

-- Store API v2 billing / reseller self-service fields for existing installations.
-- These ALTER statements mirror storeBridgeEnsureSchema(). They are useful when
-- the PHP database account cannot ALTER tables and an administrator applies the
-- migration manually with a privileged database account.
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER last_used_at;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS client_type ENUM('admin','reseller_self_service') NOT NULL DEFAULT 'admin' AFTER status;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS billing_mode ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance' AFTER client_type;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS linked_user_id BIGINT UNSIGNED NULL AFTER billing_mode;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS website_name VARCHAR(190) NULL AFTER linked_user_id;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS website_url VARCHAR(1000) NULL AFTER website_name;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS webhook_url VARCHAR(1000) NULL AFTER website_url;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS webhook_last_test_at DATETIME NULL AFTER webhook_url;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS webhook_last_http_code SMALLINT UNSIGNED NULL AFTER webhook_last_test_at;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS webhook_last_error VARCHAR(1000) NULL AFTER webhook_last_http_code;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS webhook_last_debug_json MEDIUMTEXT NULL AFTER webhook_last_error;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS order_rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 10 AFTER rate_limit_per_minute;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS max_order_amount DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER order_rate_limit_per_minute;
ALTER TABLE store_api_clients ADD COLUMN IF NOT EXISTS daily_spend_limit DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER max_order_amount;

ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS billing_mode ENUM('api_balance','reseller_wallet') NOT NULL DEFAULT 'api_balance';
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS billing_user_id BIGINT UNSIGNED NULL;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS billing_transaction_id BIGINT UNSIGNED NULL;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS balance_before DECIMAL(16,2) NULL;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS balance_after DECIMAL(16,2) NULL;
ALTER TABLE store_api_orders ADD COLUMN IF NOT EXISTS request_fingerprint CHAR(64) NULL;

ALTER TABLE store_api_clients ADD INDEX IF NOT EXISTS idx_store_api_client_linked_user (linked_user_id, client_type);
ALTER TABLE store_api_orders ADD INDEX IF NOT EXISTS idx_store_api_order_billing_user (billing_user_id, created_at);
ALTER TABLE store_api_orders ADD INDEX IF NOT EXISTS idx_store_api_order_billing_transaction (billing_transaction_id);

-- IMPORTANT: reseller_wallet also requires transactions.type to accept
-- 'store_api_purchase' and requires wallet_balance_ledger. Use the Admin
-- Transaction Integrity page to back up/migrate that shared financial schema;
-- this Store Bridge schema intentionally does not rewrite the shared
-- transactions column behind the application's back.
