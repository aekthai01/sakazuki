-- Optional fallback schema for the Transaction Integrity repair UI.
-- Import only if the PHP database account cannot CREATE TABLE automatically.
-- This file does not alter balances, transactions, orders, or keys.

CREATE TABLE IF NOT EXISTS transaction_type_repair_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id CHAR(36) NOT NULL,
    admin_user_id INT UNSIGNED NULL,
    schema_before LONGTEXT NULL,
    schema_after LONGTEXT NULL,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    repaired_count INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'started',
    error_message VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transaction_type_batch (batch_id),
    KEY idx_transaction_type_batch_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_type_repair_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id CHAR(36) NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    old_type VARCHAR(100) NOT NULL DEFAULT '',
    new_type VARCHAR(50) NOT NULL,
    reason VARCHAR(80) NOT NULL,
    description_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transaction_type_repair_row (batch_id, transaction_id),
    KEY idx_transaction_type_repair_tx (transaction_id),
    KEY idx_transaction_type_repair_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_integrity_debug_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(32) NOT NULL,
    batch_id CHAR(36) NOT NULL DEFAULT '',
    admin_user_id INT UNSIGNED NULL,
    action VARCHAR(32) NOT NULL,
    stage VARCHAR(48) NOT NULL DEFAULT '',
    level VARCHAR(16) NOT NULL DEFAULT 'info',
    code VARCHAR(80) NOT NULL DEFAULT '',
    message VARCHAR(1000) NOT NULL DEFAULT '',
    db_errno INT NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transaction_integrity_debug_created (created_at),
    KEY idx_transaction_integrity_debug_batch (batch_id, created_at),
    KEY idx_transaction_integrity_debug_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
