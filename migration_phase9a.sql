-- Phase 9A: Upgrade requests table
CREATE TABLE IF NOT EXISTS upgrade_requests (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT NOT NULL,
  tenant_name   VARCHAR(255) DEFAULT '',
  current_plan  VARCHAR(50)  DEFAULT '',
  requested_plan VARCHAR(50) NOT NULL,
  note          TEXT,
  status        ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
