-- Ranking and deposit-bonus schema for sakazuki.spwz.online
-- Import once if the application database account cannot CREATE TABLE.
-- This file contains structure only. It contains no user data or credentials.

CREATE TABLE IF NOT EXISTS rank_deposit_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deposit_transaction_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role_at_deposit VARCHAR(20) NOT NULL,
    credited_amount DECIMAL(14,2) NOT NULL,
    qualifying_amount_thb DECIMAL(14,2) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'deposit',
    qualification_method VARCHAR(32) NOT NULL DEFAULT 'native_thb',
    deposited_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rank_deposit_transaction (deposit_transaction_id),
    KEY idx_rank_deposit_user_time (user_id, deposited_at),
    KEY idx_rank_deposit_role_time (role_at_deposit, deposited_at),
    KEY idx_rank_deposit_time (deposited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rank_bonus_awards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deposit_transaction_id BIGINT UNSIGNED NOT NULL,
    bonus_transaction_id BIGINT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    rank_code VARCHAR(16) NOT NULL,
    bonus_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    base_amount DECIMAL(14,2) NOT NULL,
    bonus_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    qualifying_total_thb DECIMAL(14,2) NOT NULL,
    status VARCHAR(24) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rank_bonus_deposit (deposit_transaction_id),
    UNIQUE KEY uq_rank_bonus_transaction (bonus_transaction_id),
    KEY idx_rank_bonus_user_period (user_id, period_start),
    KEY idx_rank_bonus_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rank_system_meta (
    meta_key VARCHAR(64) NOT NULL,
    meta_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
