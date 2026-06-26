# MyanAi POS — Regression Test Baseline
## Date: 2026-06-26

### Tenant Panel (19/19 pages ✅)
dashboard, menu, staff, crm, orders, tables, reserve, stock, stocklog, 
shift, delivery, expenses, promos, branches, schedule, backup, storefront, upgrade, settings

### Tenant APIs (17/17 ✅)
stats, menu, stock, orders, crm, shift, delivery, analytics, backup,
payment, storefront, plans, branches, promos, expenses, reservations, health

### Tenant JS Functions ✅
shiftLoad, crmLoadCustomers, stockLoad, delLoad, resLoad

### Admin Panel (11/11 pages ✅)
dashboard, saas, settings, tenants, revenue, upgrades, plans, landing, demo, logs, announce

### Admin APIs (7/7 ✅)
tenants, plans, orders, logs, health, 2fa, settings

### Admin JS ✅
Chart.js v4.4.0, showPage, loadTenants, lpeSave

### Key IDs to NOT break
tenant.php: page-dashboard, page-menu, page-staff, page-crm, page-orders,
page-tables, page-reserve, page-stock, page-stocklog, page-shift, page-delivery,
page-expenses, page-promos, page-branches, page-schedule, page-backup,
page-storefront, page-upgrade, page-settings

admin.php: page-dashboard, page-saas, page-settings, page-tenants, page-revenue,
page-upgrades, page-plans, page-landing, page-demo, page-logs, page-announce,
dashboard-widgets (2FA+Health), lpe-* (landing editor)

### Critical API files (must return ok:true)
tenant.php, menu_api.php, stock_api.php, crm_api.php, shift_api.php,
delivery_api.php, analytics.php, backup_api.php, tenant_api.php,
branch_api.php, promo_api.php, expense_api.php, reservation_api.php,
health.php, log_api.php, two_factor.php, admin.php

### Run regression check before every commit:
See regression_check.js for browser console test
