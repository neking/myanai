-- Migration: Create admin audit logging table
-- Date: 2024-06-24
-- Purpose: Track all admin actions for security & compliance

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_username VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username_date (admin_username, created_at),
    INDEX idx_action_date (action, created_at),
    INDEX idx_created (created_at)
);

-- Optional: Add constraints if needed
ALTER TABLE admin_audit_logs
ADD CONSTRAINT fk_admin_audit_cleanup
FOREIGN KEY (id) REFERENCES admin_audit_logs(id) ON DELETE CASCADE;

-- Retention policy: Keep logs for 90 days
-- (Run this as a scheduled job/cron task)
-- DELETE FROM admin_audit_logs 
-- WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
