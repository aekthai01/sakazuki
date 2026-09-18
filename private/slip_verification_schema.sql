-- Durable EasySlip verification jobs.
-- Safe additive migration: creates a new table and does not rewrite existing
-- users, balances, transactions, slip_deposits, or legacy attempt rows.

CREATE TABLE IF NOT EXISTS slip_verification_jobs (
    attempt_uuid CHAR(36) NOT NULL,
    slip_hash CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'verifying',
    verification_remark VARCHAR(255) NOT NULL DEFAULT '',
    transaction_ref VARCHAR(100) NULL,
    provider_data_ciphertext LONGTEXT NULL,
    provider_is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
    provider_verified_at DATETIME NULL,
    provider_request_count INT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    last_error_code VARCHAR(80) NOT NULL DEFAULT '',
    last_error_message VARCHAR(500) NOT NULL DEFAULT '',
    slip_deposit_id BIGINT UNSIGNED NULL,
    deposit_transaction_id BIGINT UNSIGNED NULL,
    credit_amount DECIMAL(16,2) NULL,
    bonus_amount DECIMAL(16,2) NULL,
    total_credited DECIMAL(16,2) NULL,
    completed_at DATETIME NULL,
    shared_finalized_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_uuid),
    UNIQUE KEY uq_slip_job_hash (slip_hash),
    KEY idx_slip_job_user (user_id, created_at),
    KEY idx_slip_job_status (status, updated_at),
    KEY idx_slip_job_reference (transaction_ref),
    KEY idx_slip_job_shared_finalize (status, shared_finalized_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
