-- Migration: Email drip campaign log
CREATE TABLE IF NOT EXISTS email_drip_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    day_sequence INT NOT NULL,
    subject VARCHAR(200),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_day (tenant_id, day_sequence),
    INDEX idx_tenant (tenant_id),
    INDEX idx_sent (sent_at)
);
