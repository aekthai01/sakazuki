-- V3 to V4 audit migration. Run only if automatic migration is unavailable.
-- If a column/index already exists, skip that specific statement.
-- errors can be ignored when a column or index already exists.
ALTER TABLE binance_giftcard_redemptions ADD COLUMN request_id VARCHAR(24) NOT NULL DEFAULT '' AFTER id;
ALTER TABLE binance_giftcard_redemptions ADD COLUMN request_stage VARCHAR(40) NOT NULL DEFAULT 'received' AFTER status;
ALTER TABLE binance_giftcard_redemptions ADD COLUMN http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER api_message_safe;
ALTER TABLE binance_giftcard_redemptions ADD COLUMN provider_message_safe VARCHAR(255) NULL AFTER http_code;
ALTER TABLE binance_giftcard_redemptions ADD COLUMN provider_requested_at DATETIME NULL AFTER provider_message_safe;
ALTER TABLE binance_giftcard_redemptions ADD KEY idx_giftcard_request_id (request_id);
