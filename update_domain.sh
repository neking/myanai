#!/bin/bash
# =======================================================
# MyanAi — Domain Update Script
# Run on NEW server after migration
# Usage: sudo bash update_domain.sh yourdomain.com
# =======================================================
OLD_DOMAIN=${1:-"myanai.duckdns.org"}
NEW_DOMAIN=${2:-"yourdomain.com"}
WEBROOT="/var/www/myanai"
DB_USER="root"
DB_PASS="YOUR_DB_PASSWORD"
DB_NAME="noodlehaus"

echo "Updating domain: $OLD_DOMAIN → $NEW_DOMAIN"

# Update mailer.php
sed -i "s|${OLD_DOMAIN}|${NEW_DOMAIN}|g" $WEBROOT/mailer.php
echo "✅ mailer.php updated"

# Update landing-page.html
sed -i "s|${OLD_DOMAIN}|${NEW_DOMAIN}|g" $WEBROOT/landing-page.html
echo "✅ landing-page.html updated"

# Update site_settings DB entries
mysql -u $DB_USER -p$DB_PASS $DB_NAME << SQL
UPDATE site_settings
SET setting_value = REPLACE(setting_value, '${OLD_DOMAIN}', '${NEW_DOMAIN}')
WHERE setting_value LIKE '%${OLD_DOMAIN}%';
SELECT COUNT(*) as updated_rows FROM site_settings
WHERE setting_value LIKE '%${NEW_DOMAIN}%';
SQL
echo "✅ DB site_settings updated"

# Check remaining references
echo ""
echo "Remaining references to old domain:"
grep -r "$OLD_DOMAIN" $WEBROOT --include="*.php" --include="*.html" --include="*.js" -l 2>/dev/null || echo "None found ✅"

echo ""
echo "Done! Test: curl https://$NEW_DOMAIN/health.php"
