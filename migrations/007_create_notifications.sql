CREATE TABLE IF NOT EXISTS admin_notifications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(50) NOT NULL,
  level       ENUM('info','warning','danger') DEFAULT 'info',
  title       VARCHAR(200) NOT NULL,
  body        TEXT DEFAULT NULL,
  tenant_id   INT DEFAULT NULL,
  is_read     TINYINT(1) DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_read (is_read),
  INDEX idx_created (created_at),
  INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
