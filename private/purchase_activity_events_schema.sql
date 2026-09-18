-- Optional manual migration. The application also creates this table safely
-- before the first local checkout when the database account has CREATE access.
CREATE TABLE IF NOT EXISTS purchase_activity_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    source VARCHAR(24) NOT NULL DEFAULT 'local',
    source_reference BIGINT UNSIGNED NOT NULL,
    first_transaction_id BIGINT UNSIGNED NOT NULL,
    last_transaction_id BIGINT UNSIGNED NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_activity_source_ref (source, source_reference),
    KEY idx_purchase_activity_created (created_at, id),
    KEY idx_purchase_activity_user (user_id, created_at),
    KEY idx_purchase_activity_tx_range (source, first_transaction_id, last_transaction_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
