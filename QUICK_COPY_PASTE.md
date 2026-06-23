# QUICK COPY-PASTE FOR PuTTY
## All commands ready to run

---

## 📋 OPTION 1: Automated Script (RECOMMENDED)

**One command to run everything:**

```bash
cd /var/www/myanai && bash deploy-week1.sh
```

That's it! The script handles:
- Database backup
- Git pull
- All 3 migrations
- File permissions
- PHP restart
- Verification

---

## 📋 OPTION 2: Step-by-Step Commands

**Copy each section one by one:**

### Section 1: Setup & Backup
```bash
cd /var/www/myanai
mkdir -p backups
mysqldump -h localhost -u myanai_user -p noodlehaus > backups/myanai_backup_$(date +%Y%m%d_%H%M%S).sql
```
(Password: i0It2cUUSHiIbr3v1wZquVWOIZaHuudY)

### Section 2: Pull Code
```bash
sudo git config --global --add safe.directory /var/www/myanai
git fetch origin main
git pull origin main
```

### Section 3: Database Migration 1 - Stock Constraints
```bash
mysql -h localhost -u myanai_user -p noodlehaus << 'EOF'
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
EOF
```

### Section 4: Database Migration 2 - Audit Logs
```bash
mysql -h localhost -u myanai_user -p noodlehaus << 'EOF'
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
EOF
```

### Section 5: Database Migration 3 - PIN Tracking
```bash
mysql -h localhost -u myanai_user -p noodlehaus << 'EOF'
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
EOF
```

### Section 6: Fix Permissions & Restart
```bash
sudo chown -R ubuntu:ubuntu /var/www/myanai
chmod -R 755 /var/www/myanai
sudo systemctl restart php8.3-fpm
```

### Section 7: Verify
```bash
# Test all 3 migrations exist
mysql -h localhost -u myanai_user -p noodlehaus -e "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME IN ('admin_audit_logs', 'pin_login_attempts', 'stock_audit_log');"

# Should show 3 tables
```

---

## 🎯 PASSWORD REFERENCE

**Database password when prompted:**
```
i0It2cUUSHiIbr3v1wZquVWOIZaHuudY
```

**Database user:**
```
myanai_user
```

**Database name:**
```
noodlehaus
```

---

## ✅ WHAT GETS DEPLOYED

After running these commands:

✅ **Atomic Transactions** - Signup can't partially fail
✅ **Stock Validation** - Can't oversell items
✅ **Concurrent Locks** - Safe under load
✅ **Audit Logging** - Track all admin actions
✅ **Rate Limiting** - Protect PIN login
✅ **Session Timeout** - Auto-logout after 30 min
✅ **Database Constraints** - Enforce valid data

---

## 🆘 IF SOMETHING GOES WRONG

### Rollback (restore from backup):
```bash
# Find your backup file
ls -la backups/

# Restore (replace YYYYMMDD_HHMMSS with actual time)
mysql -h localhost -u myanai_user -p noodlehaus < backups/myanai_backup_YYYYMMDD_HHMMSS.sql
```

### Check PHP errors:
```bash
tail -f /var/log/php8.3-fpm.log
```

### Check database connection:
```bash
mysql -h localhost -u myanai_user -p -e "SELECT 1;"
```

---

## 🚀 NEXT: Verify in Browser

After deployment, check:

```
https://52.77.24.135/admin.php
```

Login with:
```
user: admin
pass: GGttgg123!
```

Or test signup at:
```
https://52.77.24.135/signup.html
```

---

**Ready? Just copy & paste the first command!**

```bash
cd /var/www/myanai && bash deploy-week1.sh
```

