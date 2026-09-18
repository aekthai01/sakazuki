-- New table for USER store title branding
CREATE TABLE IF NOT EXISTS store_branding (
  id INT PRIMARY KEY AUTO_INCREMENT,
  title_text VARCHAR(100) NOT NULL DEFAULT 'STORE',
  title_color VARCHAR(20) NOT NULL DEFAULT '#60a5fa',
  title_style VARCHAR(50) NOT NULL DEFAULT 'font-extrabold',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure a default row exists
INSERT INTO store_branding (id, title_text, title_color, title_style)
VALUES (1, 'STORE', '#60a5fa', 'font-extrabold')
ON DUPLICATE KEY UPDATE id=id;
