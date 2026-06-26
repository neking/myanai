-- Reviews table
CREATE TABLE IF NOT EXISTS reviews (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  order_id      INT DEFAULT NULL,
  customer_name VARCHAR(100) DEFAULT NULL,
  customer_phone VARCHAR(20) DEFAULT NULL,
  rating        TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment       TEXT DEFAULT NULL,
  is_public     TINYINT(1) DEFAULT 1,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_rating (rating),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Opening hours stored in tenant.settings JSON (no separate table needed)
-- receipt_settings stored in tenant.settings JSON
