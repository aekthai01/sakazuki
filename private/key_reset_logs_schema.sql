-- Key Reset audit/search/debug storage
-- Safe for a new installation. No DROP or DELETE statements are used.
-- Import once with the same database selected as the website.

CREATE TABLE IF NOT EXISTS `xchetos_hwid_reset_system_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` CHAR(32) NULL,
  `level` VARCHAR(16) NOT NULL DEFAULT 'info',
  `event_code` VARCHAR(64) NOT NULL,
  `stage` VARCHAR(64) NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `actor_role` VARCHAR(16) NULL,
  `message` VARCHAR(500) NULL,
  `context_json` LONGTEXT NULL,
  `request_ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_xchetos_system_created` (`created_at`),
  KEY `idx_xchetos_system_request` (`request_id`),
  KEY `idx_xchetos_system_event` (`event_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `xchetos_hwid_reset_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `request_id` CHAR(32) NULL,
  `actor_role` VARCHAR(16) NOT NULL DEFAULT 'reseller',
  `operation_type` VARCHAR(32) NOT NULL DEFAULT 'reset_owned',
  `provider_code` VARCHAR(32) NOT NULL DEFAULT 'xchetos',
  `target_user_id` BIGINT UNSIGNED NULL,
  `owner_kind` VARCHAR(24) NULL,
  `owner_user_id` BIGINT UNSIGNED NULL,
  `owner_username` VARCHAR(190) NULL,
  `owner_email` VARCHAR(190) NULL,
  `owner_role` VARCHAR(32) NULL,
  `owner_source` VARCHAR(32) NULL,
  `related_transaction_id` BIGINT UNSIGNED NULL,
  `related_order_id` BIGINT UNSIGNED NULL,
  `purchase_created_at` DATETIME NULL,
  `product_duration` VARCHAR(120) NULL,
  `external_ref` VARCHAR(190) NULL,
  `key_source` VARCHAR(16) NOT NULL,
  `key_record_id` VARCHAR(64) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `key_masked` VARCHAR(128) NOT NULL,
  `key_ciphertext` TEXT NULL,
  `product_name` VARCHAR(255) NULL,
  `key_duration_days` SMALLINT UNSIGNED NULL,
  `key_reset_limit` SMALLINT UNSIGNED NULL,
  `status` ENUM('processing','success','failed','unknown') NOT NULL DEFAULT 'processing',
  `result_code` VARCHAR(64) NOT NULL DEFAULT 'processing',
  `request_stage` VARCHAR(64) NOT NULL DEFAULT 'created',
  `provider_attempted` TINYINT(1) NOT NULL DEFAULT 0,
  `endpoint` VARCHAR(190) NULL,
  `http_method` VARCHAR(10) NULL,
  `provider_http_status` SMALLINT UNSIGNED NULL,
  `provider_message` VARCHAR(500) NULL,
  `transport_code` VARCHAR(64) NULL,
  `curl_errno` INT UNSIGNED NULL,
  `curl_error` VARCHAR(255) NULL,
  `duration_ms` INT UNSIGNED NULL,
  `connect_ms` INT UNSIGNED NULL,
  `primary_ip` VARCHAR(45) NULL,
  `token_retry` TINYINT(1) NOT NULL DEFAULT 0,
  `provider_license_id` BIGINT UNSIGNED NULL,
  `provider_product_id` BIGINT UNSIGNED NULL,
  `provider_license_status` VARCHAR(32) NULL,
  `debug_json` LONGTEXT NULL,
  `request_ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_xchetos_user_created` (`user_id`, `created_at`),
  KEY `idx_xchetos_key_created` (`key_hash`, `created_at`),
  KEY `idx_xchetos_status_created` (`status`, `created_at`),
  KEY `idx_xchetos_request_id` (`request_id`),
  KEY `idx_xchetos_operation_created` (`operation_type`, `created_at`),
  KEY `idx_xchetos_provider_created` (`provider_code`, `created_at`),
  KEY `idx_xchetos_owner_created` (`owner_user_id`, `created_at`),
  KEY `idx_xchetos_related_tx` (`related_transaction_id`),
  KEY `idx_xchetos_related_order` (`related_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MariaDB 10.11 safe forward migration for installations that already have
-- the audit table. The PHP runtime performs the same migration automatically.
ALTER TABLE `xchetos_hwid_reset_logs`
  ADD COLUMN IF NOT EXISTS `owner_kind` VARCHAR(24) NULL AFTER `target_user_id`,
  ADD COLUMN IF NOT EXISTS `owner_user_id` BIGINT UNSIGNED NULL AFTER `owner_kind`,
  ADD COLUMN IF NOT EXISTS `owner_username` VARCHAR(190) NULL AFTER `owner_user_id`,
  ADD COLUMN IF NOT EXISTS `owner_email` VARCHAR(190) NULL AFTER `owner_username`,
  ADD COLUMN IF NOT EXISTS `owner_role` VARCHAR(32) NULL AFTER `owner_email`,
  ADD COLUMN IF NOT EXISTS `owner_source` VARCHAR(32) NULL AFTER `owner_role`,
  ADD COLUMN IF NOT EXISTS `related_transaction_id` BIGINT UNSIGNED NULL AFTER `owner_source`,
  ADD COLUMN IF NOT EXISTS `related_order_id` BIGINT UNSIGNED NULL AFTER `related_transaction_id`,
  ADD COLUMN IF NOT EXISTS `purchase_created_at` DATETIME NULL AFTER `related_order_id`,
  ADD COLUMN IF NOT EXISTS `product_duration` VARCHAR(120) NULL AFTER `purchase_created_at`,
  ADD COLUMN IF NOT EXISTS `external_ref` VARCHAR(190) NULL AFTER `product_duration`;


CREATE TABLE IF NOT EXISTS `xchetos_hwid_reset_key_search` (
  `token` CHAR(25) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`, `key_hash`),
  KEY `idx_xchetos_search_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
