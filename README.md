# MyanAi POS — Myanmar AI Products Platform

Myanmar ဆိုင်တွေအတွက် complete SaaS POS system — myanai.net

## Live URLs
- Landing page: https://myanai.net/landing-page.html
- Admin panel: https://myanai.net/admin.php
- Demo tenant: https://myanai.net/index.html?t=demo
- More Tea demo: https://myanai.net/index.html?t=moretea
- Boba Star demo: https://myanai.net/index.html?t=bobastar

## Tech Stack
- PHP 8.3 + MySQL (PDO)
- Nginx + AWS EC2 Singapore
- Vanilla JS (no framework)
- Service Worker (PWA)

## Architecture
| File | Purpose |
|------|---------|
| `landing-page.html` | Public marketing site with Landing Page Editor support |
| `admin.php` | Platform operator dashboard (tenants, health, growth, LPE) |
| `admin_main.js` | Admin panel JS — all dashboard logic, Landing Page Editor |
| `tenant.php` | Tenant POS app (orders, menu, staff, CRM, analytics) |
| `index.html` | Customer-facing online ordering page |
| `sw.js` | Service worker — network-first caching (v2) |
| `db_connect.php` | PDO database connection |
| `menu_api.php` | Menu CRUD API |
| `tenant_api.php` | Tenant settings, plans, storefront API |
| `notifications_api.php` | Admin notifications API |
| `admin_growth.php` | Growth analytics API (MRR, churn, signups) |
| `reviews_api.php` | Tenant reviews API |

## Admin Panel Pages (13)
Dashboard, Notifications, Growth Analytics (in Dashboard), Tenants,
Revenue, Upgrade Requests, Plans & Pricing, Landing Page Editor,
Demo Control, Announcements, Error Logs, SaaS Dashboard, Settings

## Tenant Panel Pages (23)
Dashboard, Menu, Staff, CRM, Orders, Tables, Reservations, Stock,
Stock Log, Shift, Delivery, Expenses, Promotions, Branches, Schedule,
Analytics, Opening Hours, Receipt/KDS Settings, Reviews, Backup,
Storefront, Upgrade, Settings

## Pricing Plans
| Plan | Price | Branches | Staff | Menu Items |
|------|-------|----------|-------|------------|
| Free | 0 Ks | 1 | 3 | 20 |
| Basic | 50,000 Ks/mo | 1 | 5 | 50 |
| Pro | 150,000 Ks/mo | 3 | 15 | 200 |
| Enterprise | 300,000 Ks/mo | 10 | 50 | 500 |

## Demo Accounts
| Tenant | Email | Password | URL |
|--------|-------|----------|-----|
| MyanAi Demo | demo@myanai.net | demo1234 | ?t=demo |
| More Tea | moretea@myanai.net | moretea2026 | ?t=moretea |
| Boba Star | bobastar@myanai.net | boba2026 | ?t=bobastar |

Admin: admin / GGttgg123!

## Key Features Completed
- ✅ Online ordering page (PWA, KBZPay/Wave/Cash)
- ✅ Landing Page Editor (full visual editor with live preview)
- ✅ Custom Myanmar font upload (ttf/otf/woff/woff2)
- ✅ 8 Feature cards + 8 Trust cards (editable in LPE)
- ✅ Pro vs Enterprise pricing differentiated (limits + features)
- ✅ Growth Analytics on Dashboard (MRR, churn, active tenants)
- ✅ Notifications with viewTenant() navigation
- ✅ Service worker network-first caching (v2)
- ✅ Multi-branch management
- ✅ CRM + Loyalty stamps
- ✅ Delivery management
- ✅ Analytics dashboard (revenue trend, peak hours, top items)
- ✅ Opening hours management
- ✅ Receipt/KDS settings
- ✅ Reviews management

## Server Info
- Host: AWS EC2 Singapore (52.77.24.135)
- Web root: /var/www/myanai/
- DB: noodlehaus (MySQL)
- DB User: myanai_user
- PHP: 8.3 (php8.3-fpm)
- Nginx

## Deploy
```bash
cd /var/www/myanai && git pull origin main
```

## GitHub
https://github.com/neking/myanai
