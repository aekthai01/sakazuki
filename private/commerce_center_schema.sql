-- Commerce Center schema v1.2
-- Additive read model. Safe to run on both sakazuki and online databases.
-- It does not modify wallet balances, stock or authoritative order rows.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS commerce_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_uuid CHAR(36) NOT NULL,
    source_site_id VARCHAR(100) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_record_id VARCHAR(190) NOT NULL,
    status VARCHAR(40) NOT NULL,
    source_updated_at DATETIME NULL,
    origin_site_id VARCHAR(100) NULL,
    origin_user_id VARCHAR(190) NULL,
    customer_ref VARCHAR(255) NULL,
    local_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    commercial_buyer_type VARCHAR(40) NOT NULL DEFAULT '',
    commercial_buyer_id VARCHAR(190) NOT NULL DEFAULT '',
    buyer_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    buyer_email_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    currency CHAR(3) NOT NULL DEFAULT 'THB',
    subtotal DECIMAL(16,2) NOT NULL DEFAULT 0,
    total DECIMAL(16,2) NOT NULL DEFAULT 0,
    cost_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    external_ref VARCHAR(190) NOT NULL DEFAULT '',
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    delivery_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_created_at DATETIME NULL,
    source_completed_at DATETIME NULL,
    snapshot_json LONGTEXT NULL,
    last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_order_uuid (order_uuid),
    UNIQUE KEY uq_commerce_order_source (source_site_id, source_type, source_record_id),
    KEY idx_commerce_order_status (status, source_completed_at),
    KEY idx_commerce_order_customer (origin_site_id, origin_user_id),
    KEY idx_commerce_order_local_user (local_user_id, source_created_at),
    KEY idx_commerce_order_external_ref (external_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    item_no INT UNSIGNED NOT NULL DEFAULT 1,
    local_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    local_variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    remote_product_id VARCHAR(190) NOT NULL DEFAULT '',
    product_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
    duration_snapshot VARCHAR(120) NOT NULL DEFAULT '',
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(16,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(16,2) NOT NULL DEFAULT 0,
    total_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
    snapshot_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_order_item (order_id, item_no),
    KEY idx_commerce_item_product (local_product_id, local_variant_id),
    KEY idx_commerce_item_remote (remote_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_site_id VARCHAR(100) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_key_record_id VARCHAR(190) NOT NULL,
    source_inventory_key_id VARCHAR(190) NOT NULL DEFAULT '',
    key_hash CHAR(64) NOT NULL,
    key_mask VARCHAR(190) NOT NULL DEFAULT '',
    delivered_to_site_id VARCHAR(100) NULL,
    delivered_to_user_id VARCHAR(190) NULL,
    customer_ref VARCHAR(255) NULL,
    delivered_name_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    delivered_email_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    ownership_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_delivery_source (source_site_id, source_type, source_key_record_id),
    KEY idx_commerce_delivery_order (order_id, id),
    KEY idx_commerce_delivery_hash (key_hash),
    KEY idx_commerce_delivery_owner (delivered_to_site_id, delivered_to_user_id),
    KEY idx_commerce_delivery_customer_ref (customer_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_sync_failures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_site_id VARCHAR(100) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_record_id VARCHAR(190) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    error_code VARCHAR(80) NOT NULL DEFAULT 'sync_failed',
    error_message VARCHAR(1000) NOT NULL DEFAULT '',
    first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_sync_failure (source_site_id, source_type, source_record_id),
    KEY idx_commerce_sync_unresolved (resolved_at, last_failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing Store API orders gain stable origin identity. These statements are
-- safe on MariaDB 10.11 and can be run before the PHP files are uploaded.
ALTER TABLE IF EXISTS store_api_orders ADD COLUMN IF NOT EXISTS origin_site_id VARCHAR(100) NULL AFTER customer_email;
ALTER TABLE IF EXISTS store_api_orders ADD COLUMN IF NOT EXISTS origin_user_id VARCHAR(190) NULL AFTER origin_site_id;
ALTER TABLE IF EXISTS store_api_orders ADD COLUMN IF NOT EXISTS customer_ref VARCHAR(255) NULL AFTER origin_user_id;

ALTER TABLE commerce_deliveries ADD COLUMN IF NOT EXISTS source_inventory_key_id VARCHAR(190) NOT NULL DEFAULT '' AFTER source_key_record_id;
ALTER TABLE commerce_orders ADD INDEX IF NOT EXISTS idx_commerce_order_updated (updated_at, id);


CREATE TABLE IF NOT EXISTS commerce_order_financials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'THB',
    sale_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    cost_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    fee_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    refund_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    net_revenue DECIMAL(16,2) NOT NULL DEFAULT 0,
    profit_total DECIMAL(16,2) NULL,
    margin_percent DECIMAL(10,4) NULL,
    financial_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    cost_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
    calculation_version VARCHAR(20) NOT NULL DEFAULT '1.0',
    snapshot_json LONGTEXT NULL,
    last_calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_financial_order (order_id),
    KEY idx_commerce_financial_status (financial_status, last_calculated_at),
    KEY idx_commerce_financial_currency (currency, financial_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_uuid CHAR(36) NOT NULL,
    entry_key CHAR(64) NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_site_id VARCHAR(100) NOT NULL,
    source_type VARCHAR(60) NOT NULL,
    source_record_id VARCHAR(190) NOT NULL,
    source_event_id VARCHAR(190) NOT NULL DEFAULT '',
    entry_type VARCHAR(60) NOT NULL,
    account_type VARCHAR(60) NOT NULL DEFAULT '',
    account_id VARCHAR(190) NOT NULL DEFAULT '',
    account_label_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    counterparty_type VARCHAR(60) NOT NULL DEFAULT '',
    counterparty_id VARCHAR(190) NOT NULL DEFAULT '',
    counterparty_label_snapshot VARCHAR(190) NOT NULL DEFAULT '',
    direction VARCHAR(10) NOT NULL,
    amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    reporting_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'THB',
    balance_before DECIMAL(16,2) NULL,
    balance_after DECIMAL(16,2) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'completed',
    description VARCHAR(1000) NOT NULL DEFAULT '',
    occurred_at DATETIME NULL,
    snapshot_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_commerce_ledger_uuid (entry_uuid),
    UNIQUE KEY uq_commerce_ledger_key (entry_key),
    KEY idx_commerce_ledger_order (order_id, id),
    KEY idx_commerce_ledger_source (source_site_id, source_type, source_record_id),
    KEY idx_commerce_ledger_account (account_type, account_id, occurred_at),
    KEY idx_commerce_ledger_type (entry_type, status, occurred_at),
    KEY idx_commerce_ledger_occurred (occurred_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
