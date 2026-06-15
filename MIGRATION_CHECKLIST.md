# MyanAi Platform — VPS Migration Checklist
**Prepared:** 2026-06-15 | **Tests:** 28/28 API ✅ + 18/18 Functional ✅

---

## 1. Pre-Migration Backup (run on OLD server)

```bash
# Full DB backup
mysqldump -u root -pGGttgg123! --single-transaction --routines --triggers \
  noodlehaus > ~/myanai_backup_$(date +%Y%m%d_%H%M%S).sql

# Full codebase backup
sudo tar -czf ~/myanai_code_$(date +%Y%m%d_%H%M%S).tar.gz \
  -C /var/www myanai --exclude='myanai/.git'

# Verify sizes
ls -lh ~/myanai_backup_*.sql ~/myanai_code_*.tar.gz
```

---

## 2. New VPS — Server Setup

```bash
# Ubuntu 24 LTS
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-gd php8.3-zip git certbot python3-certbot-nginx
```

### MySQL setup
```bash
sudo mysql_secure_installation
mysql -u root -p
```
```sql
CREATE DATABASE noodlehaus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'myanai'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON noodlehaus.* TO 'myanai'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Restore DB
```bash
mysql -u root -p noodlehaus < ~/myanai_backup_XXXXXXXX.sql
```

---

## 3. Code Deployment

```bash
# Option A: From GitHub (recommended)
cd /var/www
sudo git clone https://github.com/neking/myanai.git myanai
sudo chown -R www-data:www-data /var/www/myanai

# Option B: From backup tar
sudo tar -xzf ~/myanai_code_XXXXXXXX.tar.gz -C /var/www/
sudo chown -R www-data:www-data /var/www/myanai
```

### Update db_connect.php with new credentials
```bash
sudo nano /var/www/myanai/db_connect.php
# Update: DB_HOST, DB_USER, DB_PASS, DB_NAME
```

---

## 4. Nginx Config

```nginx
# /etc/nginx/sites-available/myanai
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/myanai;
    index index.php landing-page.html index.html;

    # Redirect to landing page
    location = / {
        try_files $uri /landing-page.html;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location ~ /\.git { deny all; }
    location ~ /\.ht  { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/myanai /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

## 5. SSL Certificate (Let's Encrypt)

```bash
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
sudo systemctl enable certbot.timer
```

---

## 6. File Permissions

```bash
sudo chown -R www-data:www-data /var/www/myanai
sudo chmod -R 755 /var/www/myanai
sudo chmod -R 775 /var/www/myanai/uploads  # if exists
sudo chmod 600 /var/www/myanai/db_connect.php
```

---

## 7. Update Webhook

In `webhook.php`, verify secret matches GitHub:
```php
define('WEBHOOK_SECRET', 'myanai_webhook_2026');
define('REPO_DIR', '/var/www/myanai');
```

Update GitHub webhook URL:
- Go to: github.com/neking/myanai → Settings → Webhooks
- Update Payload URL to: `https://yourdomain.com/webhook.php`

---

## 8. Update Domain References in Code

Files to update with new domain:
```bash
# Search for old domain
grep -r "myanai.duckdns.org" /var/www/myanai --include="*.php" --include="*.html" --include="*.js"

# Update
sudo sed -i 's/myanai.duckdns.org/yourdomain.com/g' /var/www/myanai/mailer.php
sudo sed -i 's/myanai.duckdns.org/yourdomain.com/g' /var/www/myanai/landing-page.html
# Update site_settings DB:
mysql -u root -p noodlehaus -e "UPDATE site_settings SET setting_value=REPLACE(setting_value,'myanai.duckdns.org','yourdomain.com');"
```

---

## 9. Cron Jobs (restore on new server)

```bash
crontab -e
# Add these lines:
0 0 * * * mysqldump -u root noodlehaus > ~/db_backups/noodlehaus_$(date +\%F).sql
0 2 * * * /var/www/myanai/demo_reset_cron.sh >> /var/log/myanai_demo_reset.log 2>&1
```

---

## 10. PHP Config Tweaks

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```
```ini
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
memory_limit = 256M
session.cookie_secure = On
session.cookie_httponly = On
```

---

## 11. Post-Migration Smoke Tests

Run these after migration:

```bash
# 1. Health check
curl https://yourdomain.com/health.php

# 2. Menu API
curl https://yourdomain.com/menu_api.php?tenant_id=9

# 3. Plans API
curl https://yourdomain.com/tenant_api.php?action=plans

# 4. Site settings
curl https://yourdomain.com/site_settings.php?action=get
```

Browser tests:
- [ ] `https://yourdomain.com` → Landing page loads, MyanAi brand
- [ ] `https://yourdomain.com/admin.php` → Super admin login (admin / GGttgg123!)
- [ ] `https://yourdomain.com/tenant.php` → Demo login (demo@myanai.net / demo1234)
- [ ] `https://yourdomain.com/index.html?t=demo` → Customer ordering
- [ ] `https://yourdomain.com/kds.html` → KDS loads

---

## 12. Key Credentials (CHANGE AFTER MIGRATION)

| Item | Current Value | Action |
|------|---------------|--------|
| DB root password | GGttgg123! | **Change** |
| Admin password | GGttgg123! | **Change** |
| Demo password | demo1234 | Keep (demo) |
| Webhook secret | myanai_webhook_2026 | Keep or rotate |
| GitHub token | ghp_W8oa6... | **Revoke & regenerate** |

---

## 13. DNS Update

1. Point domain A record → New VPS IP
2. Wait for propagation (5 min – 48 hrs, check: `dig +short yourdomain.com`)
3. Only after SSL cert obtained — update all domain references

---

## Current Platform Summary (as of 2026-06-15)

- **Tests:** 28/28 API tests + 18/18 functional tests = **46/46 ✅**
- **DB tables:** 15+ tables, ~200+ records
- **Tenants:** 14 active, demo tenant fully seeded
- **Files:** ~30 PHP/HTML/JS files, ~500KB total code

---

## db_connect.php template

```php
<?php
function getPDO(): PDO {
    $host = 'localhost';
    $db   = 'noodlehaus';
    $user = 'myanai';           // or root
    $pass = 'YOUR_NEW_PASSWORD';
    $dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}
```
