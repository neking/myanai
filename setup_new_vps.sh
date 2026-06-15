#!/bin/bash
# =======================================================
# MyanAi Platform — New VPS Setup Script
# Ubuntu 24 LTS | Run as root or with sudo
# Usage: sudo bash setup_new_vps.sh yourdomain.com
# =======================================================
set -e

DOMAIN=${1:-"yourdomain.com"}
WEBROOT="/var/www/myanai"
DB_NAME="noodlehaus"
DB_USER="myanai_user"
DB_PASS="$(openssl rand -base64 24 | tr -d '=+/')"
REPO="https://github.com/neking/myanai.git"

echo "======================================"
echo " MyanAi VPS Setup — $DOMAIN"
echo "======================================"

# 1. System update
echo "→ Updating system..."
apt update && apt upgrade -y

# 2. Install packages
echo "→ Installing packages..."
apt install -y \
  nginx \
  mysql-server \
  php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-zip \
  git \
  certbot python3-certbot-nginx \
  unzip curl wget

# 3. MySQL setup
echo "→ Setting up MySQL..."
mysql -u root << MYSQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
MYSQL

# 4. PHP config
echo "→ Configuring PHP..."
PHP_INI="/etc/php/8.3/fpm/php.ini"
sed -i 's/^upload_max_filesize.*/upload_max_filesize = 20M/'  $PHP_INI
sed -i 's/^post_max_size.*/post_max_size = 25M/'              $PHP_INI
sed -i 's/^max_execution_time.*/max_execution_time = 120/'    $PHP_INI
sed -i 's/^memory_limit.*/memory_limit = 256M/'               $PHP_INI
sed -i 's/^;date.timezone.*/date.timezone = Asia\/Rangoon/'   $PHP_INI
systemctl restart php8.3-fpm

# 5. Clone codebase
echo "→ Cloning codebase..."
mkdir -p /var/www
git clone $REPO $WEBROOT
chown -R www-data:www-data $WEBROOT
chmod -R 755 $WEBROOT

# 6. Update db_connect.php
echo "→ Updating DB credentials..."
cat > $WEBROOT/db_connect.php << PHP
<?php
function getPDO(): PDO {
    static \$pdo = null;
    if (\$pdo) return \$pdo;
    \$pdo = new PDO(
        'mysql:host=localhost;dbname=${DB_NAME};charset=utf8mb4',
        '${DB_USER}',
        '${DB_PASS}',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return \$pdo;
}
PHP

# 7. Nginx config
echo "→ Configuring Nginx..."
cat > /etc/nginx/sites-available/myanai << NGINX
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${WEBROOT};
    index landing-page.html index.php index.html;

    location = / { try_files /landing-page.html =404; }
    location / { try_files \$uri \$uri/ =404; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    location ~ /\.git  { deny all; }
    location ~ /\.ht   { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/myanai /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# 8. SSL
echo "→ Getting SSL certificate..."
certbot --nginx -d $DOMAIN -d www.$DOMAIN --non-interactive --agree-tos \
  --email admin@$DOMAIN --redirect || echo "⚠️  SSL setup failed — run manually"

# 9. Cron jobs
echo "→ Setting up cron jobs..."
mkdir -p /home/ubuntu/db_backups
(crontab -l 2>/dev/null; \
  echo "0 0 * * * mysqldump -u ${DB_USER} -p${DB_PASS} ${DB_NAME} > ~/db_backups/${DB_NAME}_\$(date +\%F).sql"; \
  echo "0 2 * * * ${WEBROOT}/demo_reset_cron.sh >> /var/log/myanai_demo_reset.log 2>&1") | crontab -
chmod +x $WEBROOT/demo_reset_cron.sh

# 10. Summary
echo ""
echo "======================================"
echo " ✅ MyanAi Setup Complete"
echo "======================================"
echo " Domain:   https://$DOMAIN"
echo " Webroot:  $WEBROOT"
echo " DB Name:  $DB_NAME"
echo " DB User:  $DB_USER"
echo " DB Pass:  $DB_PASS  ← SAVE THIS"
echo ""
echo " Next steps:"
echo " 1. Import DB backup: mysql -u root $DB_NAME < backup.sql"
echo " 2. Update domain refs in mailer.php"
echo " 3. Update GitHub webhook URL"
echo " 4. Test: curl https://$DOMAIN/health.php"
echo "======================================"
