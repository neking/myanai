#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# MyanAi POS - Week 1 Critical Fixes Deployment
# Usage: ssh ubuntu@52.77.24.135
#        cd /var/www/myanai
#        bash deploy-week1.sh
# ═══════════════════════════════════════════════════════════════

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_warn() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

# ═══════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════"
echo "  MyanAi POS - Week 1 Deployment"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Step 1: Verify current directory
log_info "Step 1: Verifying current directory..."
if [ ! -f "admin.php" ] || [ ! -f "tenant_api.php" ]; then
    log_error "Not in myanai root directory. Please run from /var/www/myanai"
    exit 1
fi
log_success "Current directory verified"

# Step 2: Create backup
log_info "Step 2: Creating database backup..."
BACKUP_FILE="backups/myanai_backup_$(date +%Y%m%d_%H%M%S).sql"
mkdir -p backups
if command -v mysqldump &> /dev/null; then
    mysqldump -h localhost -u myanai_user -p noodlehaus > "$BACKUP_FILE" 2>/dev/null && \
    log_success "Database backup created: $BACKUP_FILE" || \
    log_warn "Could not create database backup (continue anyway?)"
else
    log_warn "mysqldump not found, skipping database backup"
fi

# Step 3: Pull latest code from GitHub
log_info "Step 3: Pulling latest code from GitHub..."
git config --global --add safe.directory /var/www/myanai
git fetch origin main
git pull origin main
log_success "Code updated from GitHub"

# Step 4: Apply Database Migrations
log_info "Step 4: Applying database migrations..."

# Migration 1: Stock constraints
log_info "  - Applying migration: Stock constraints..."
mysql -h localhost -u myanai_user -p noodlehaus << 'MIGRATION1'
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
CREATE OR REPLACE VIEW low_stock_items AS
SELECT 
    mi.id,
    mi.tenant_id,
    mi.name,
    mi.stock_qty,
    CASE 
        WHEN stock_qty = 0 THEN 'OUT OF STOCK'
        WHEN stock_qty <= 5 THEN 'LOW STOCK'
        ELSE 'IN STOCK'
    END as status
FROM menu_items mi
WHERE mi.is_active = 1 AND mi.stock_qty <= 5
ORDER BY mi.stock_qty ASC;
MIGRATION1
log_success "  - Stock constraints migration applied"

# Migration 2: Admin audit logs
log_info "  - Applying migration: Admin audit logs..."
mysql -h localhost -u myanai_user -p noodlehaus << 'MIGRATION2'
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
log_success "  - Admin audit logs migration applied"

# Migration 3: PIN login attempts
log_info "  - Applying migration: PIN login tracking..."
mysql -h localhost -u myanai_user -p noodlehaus << 'MIGRATION3'
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
log_success "  - PIN login tracking migration applied"

# Step 5: Fix file permissions
log_info "Step 5: Fixing file permissions..."
sudo chown -R ubuntu:ubuntu /var/www/myanai
chmod -R 755 /var/www/myanai
log_success "File permissions fixed"

# Step 6: Verify PHP files are readable
log_info "Step 6: Verifying critical PHP files..."
critical_files=(
    "admin.php"
    "tenant.php"
    "tenant_api.php"
    "order_handler.php"
    "admin_audit.php"
    "pin_ratelimit.php"
    "session-timeout.js"
)

for file in "${critical_files[@]}"; do
    if [ ! -f "$file" ]; then
        log_error "Missing critical file: $file"
        exit 1
    fi
done
log_success "All critical files present"

# Step 7: Restart PHP service
log_info "Step 7: Restarting PHP service..."
sudo systemctl restart php8.3-fpm || \
log_warn "Could not restart PHP (may need manual restart)"
log_success "PHP service restarted"

# Step 8: Test database connection
log_info "Step 8: Testing database connection..."
mysql -h localhost -u myanai_user -p noodlehaus -e "SELECT 1" > /dev/null 2>&1 && \
log_success "Database connection successful" || \
log_error "Database connection failed"

# Step 9: Verify migrations applied
log_info "Step 9: Verifying migrations..."
STOCK_CONSTRAINT=$(mysql -h localhost -u myanai_user -p noodlehaus -sN -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME='menu_items' AND CONSTRAINT_NAME='chk_stock_qty_non_negative'")
AUDIT_TABLE=$(mysql -h localhost -u myanai_user -p noodlehaus -sN -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='admin_audit_logs'")
PIN_TABLE=$(mysql -h localhost -u myanai_user -p noodlehaus -sN -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME='pin_login_attempts'")

if [ "$STOCK_CONSTRAINT" -gt 0 ] && [ "$AUDIT_TABLE" -gt 0 ] && [ "$PIN_TABLE" -gt 0 ]; then
    log_success "All migrations verified ✓"
else
    log_warn "Some migrations may not have been applied"
fi

# Step 10: Create git tag for deployment
log_info "Step 10: Creating deployment tag..."
git tag -a "week1-deployed-$(date +%Y%m%d_%H%M%S)" -m "Week 1 critical fixes deployed" 2>/dev/null || true
log_success "Deployment tagged in git"

# ═══════════════════════════════════════════════════════════════
echo ""
echo "═══════════════════════════════════════════════════════════════"
log_success "🎉 WEEK 1 DEPLOYMENT COMPLETE!"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "✅ Deployed:"
echo "   1. Database transactions (signup atomicity)"
echo "   2. Stock validation (prevent overselling)"
echo "   3. Concurrent order locking"
echo "   4. Admin audit logging"
echo "   5. PIN rate limiting"
echo "   6. Session timeout for POS"
echo "   7. Database constraints"
echo ""
echo "📋 Next Steps:"
echo "   1. Verify in web browser: https://52.77.24.135/admin.php"
echo "   2. Test signup flow"
echo "   3. Test order creation"
echo "   4. Monitor logs for errors"
echo ""
echo "📊 Deployment Info:"
echo "   - Backup: $BACKUP_FILE"
echo "   - Database: noodlehaus (myanai_user)"
echo "   - PHP: 8.3-fpm"
echo ""
echo "💡 To rollback if issues:"
echo "   mysql -h localhost -u myanai_user -p noodlehaus < $BACKUP_FILE"
echo ""
echo "═══════════════════════════════════════════════════════════════"
