-- Migration: Create PIN login attempts table
-- Date: 2024-06-24
-- Purpose: Track PIN login attempts for brute force protection

CREATE TABLE IF NOT EXISTS pin_login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful BOOLEAN DEFAULT FALSE,
    user_agent TEXT,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_staff_ip_time (staff_id, ip_address, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_recent (attempted_at DESC)
);

-- Set retention: Automatically delete entries older than 24 hours
-- (This would typically be done via a scheduled event)
-- CREATE EVENT IF NOT EXISTS cleanup_old_pin_attempts
-- ON SCHEDULE EVERY 1 HOUR
-- DO
--   DELETE FROM pin_login_attempts 
--   WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
