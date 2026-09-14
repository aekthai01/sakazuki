-- Optional manual schema for the admin slip diagnostic logger.
-- The application also creates this table automatically when the first event is written.
CREATE TABLE IF NOT EXISTS slip_verification_debug_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) NOT NULL,
    attempt_uuid CHAR(36) NOT NULL DEFAULT '',
    slip_hash CHAR(64) NOT NULL DEFAULT '',
    user_id INT NOT NULL DEFAULT 0,
    site_id VARCHAR(100) NOT NULL DEFAULT '',
    site_host VARCHAR(255) NOT NULL DEFAULT '',
    stage VARCHAR(80) NOT NULL,
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    event_message VARCHAR(500) NOT NULL DEFAULT '',
    error_code VARCHAR(100) NOT NULL DEFAULT '',
    http_code SMALLINT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    request_ciphertext LONGTEXT NULL,
    response_ciphertext LONGTEXT NULL,
    context_ciphertext LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_slip_debug_event (event_uuid),
    KEY idx_slip_debug_attempt (attempt_uuid, id),
    KEY idx_slip_debug_hash (slip_hash, id),
    KEY idx_slip_debug_user (user_id, created_at),
    KEY idx_slip_debug_stage (stage, created_at),
    KEY idx_slip_debug_error (error_code, created_at),
    KEY idx_slip_debug_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional one-time diagnostic control. An admin can arm a single fresh
-- EasySlip request for a previously checked image; the flag is consumed atomically.
CREATE TABLE IF NOT EXISTS slip_verification_debug_controls (
    slip_hash CHAR(64) NOT NULL,
    force_provider_refresh_once TINYINT(1) NOT NULL DEFAULT 0,
    requested_by INT NOT NULL DEFAULT 0,
    requested_at DATETIME(6) NULL,
    consumed_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (slip_hash),
    KEY idx_slip_debug_control_pending (force_provider_refresh_once, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
