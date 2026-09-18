-- Slip Deposits Table (ป้องกันการใช้สลิปซ้ำ)
CREATE TABLE IF NOT EXISTS slip_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_ref VARCHAR(100) UNIQUE COMMENT 'Transaction reference from bank',
    amount DECIMAL(10,2) NOT NULL,
    sender_name VARCHAR(100),
    sender_account VARCHAR(50),
    receiver_name VARCHAR(100),
    receiver_account VARCHAR(50),
    bank_code VARCHAR(20),
    transfer_date VARCHAR(50),
    slip_image LONGTEXT COMMENT 'Base64 encoded slip image',
    api_response TEXT COMMENT 'Full API response for reference',
    verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_transaction_ref (transaction_ref),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
