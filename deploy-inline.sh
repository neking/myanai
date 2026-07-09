#!/bin/bash
set -e

# Database Backup
mkdir -p backups
mysqldump -h localhost -u myanai_user -pi0It2cUUSHiIbr3v1wZquVWOIZaHuudY noodlehaus > backups/backup_$(date +%Y%m%d_%H%M%S).sql

# Migration 1: Stock Constraints
mysql -h localhost -u myanai_user -pi0It2cUUSHiIbr3v1wZquVWOIZaHuudY noodlehaus << 'MIGRATION1'
UPDATE menu_items SET stock_qty = 0 WHERE stock_qty < 0;
ALTER TABLE menu_items ADD CONSTRAINT chk_stock_qty_non_negative CHECK (stock_qty >= 0);
ALTER TABLE menu_items ADD INDEX idx_stock_status (stock_qty, is_active);
CREATE TABLE IF NOT EXISTS stock_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_item_id INT NOT NULL,
    old_qty INT,
    new_qty INT,
    change_reason VARCHAR(100),
    changed_by VARCHAR(100),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_item_date (menu_item_id, changed_at)
);
MIGRATION1

# Migration 2: Audit Logs
mysql -h localhost -u myanai_user -pi0It2cUUSHiIbr3v1wZquVWOIZaHuudY noodlehaus << 'MIGRATION2'
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
MIGRATION2

# Migration 3: PIN Attempts
mysql -h localhost -u myanai_user -pi0It2cUUSHiIbr3v1wZquVWOIZaHuudY noodlehaus << 'MIGRATION3'
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
MIGRATION3

# Fix Permissions
sudo chown -R ubuntu:ubuntu /var/www/myanai
chmod -R 755 /var/www/myanai

# Restart PHP
sudo systemctl restart php8.3-fpm

echo "✅ Week 1 Deployment Complete!"
