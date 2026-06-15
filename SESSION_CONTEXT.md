# MyanAi Platform — Session Context
**Last updated:** 2026-06-15

## URLs
- Live: https://myanai.duckdns.org
- Admin: https://myanai.duckdns.org/admin.php
- Tenant: https://myanai.duckdns.org/tenant.php
- Landing: https://myanai.duckdns.org/landing-page.html
- GitHub: github.com/neking/myanai

## Server
- AWS EC2: 13.236.66.72, Ubuntu 24
- Webroot: /var/www/myanai/
- PHP: 8.5-fpm
- DB: noodlehaus (MySQL), root/GGttgg123!
- Webhook secret: myanai_webhook_2026

## Admin Credentials
- Super admin: admin / GGttgg123!
- Demo tenant: demo@myanai.net / demo1234 (tenant_id=9)
- Test tenant: aye@shankitchen.com / Shan1234! (tenant_id=14)

## Architecture
```
admin.php    → Super admin (Platform management only)
tenant.php   → All tenant business admins (demo + real customers)
landing-page.html → Public landing page (CMS-driven from site_settings DB)
```

## Key Files
| File | Purpose |
|------|---------|
| admin.php | Super admin portal — SaaS management |
| admin_main.js | Platform JS (Tenants, Revenue, Upgrades, Landing CMS) |
| admin_modules.js | Shared helpers |
| tenant.php | Tenant business admin (all tenants) |
| landing-page.html | Public landing page — dynamic from site_settings |
| site_settings.php | CMS settings API (GET/POST site_settings table) |
| menu_api.php | Menu items GET + CRUD (add/edit/toggle/delete) |
| tenant_api.php | SaaS tenant API (list, plans, upgrade_requests, approve/reject) |
| webhook.php | Auto-deploy secret: myanai_webhook_2026 |
| demo_seed.php | One-time seed: ?key=myanai_seed_2026 |
| migration_phase10a.sql | tenant_access_log + upgrade_requests tables |

## Theme System
- Light: Warm Sand Glass (Theme 5) — #f0e6d3 bg, rgba(253,246,236,.82) sidebar
- Dark: Midnight Black (Theme 2) — #000 bg, rgba(28,28,30,.92) sidebar
- Toggle: ☀️/🌙 button in sidebar
- localStorage: 'myanai_theme'

## Admin.php Sidebar
```
Platform: Dashboard · Tenants · Revenue · Upgrade requests · Plans & pricing
Marketing: Landing page · Demo control · Announcements
System: SaaS dashboard · Settings
```

## Tenant.php Sidebar
```
My Business: Dashboard · Menu items · Staff · CRM/Loyalty · Stock log · Promotions · My branches
Branch ops: [branch selector] · Orders · Tables · Reservations · Stock · Shifts · Delivery · Expenses
Admin: Plan upgrade · Storefront · Settings · Scheduling
```

## Demo Tenant (id=9)
- Name: MyanAi Demo
- Slug: demo
- Plan: enterprise
- Data: 22 menu items, 8 tables, 4 staff, 5+ orders, 1 branch (id=12)
- Login: demo@myanai.net / demo1234

## DB Tables (key)
- tenants (id, name, slug, plan, plan_expires, owner_email, settings JSON, is_active)
- menu_items (id, tenant_id, branch_id, name, description, price, category, emoji, is_active, stock_qty, sort_order)
- orders (id, tenant_id, branch_id, customer_name, customer_phone, delivery_address, subtotal, delivery_fee, total_amount, payment_method, order_type, status, created_at)
- order_items (id, order_id, menu_item_id, item_name, qty, unit_price, subtotal)
- restaurant_tables (id, tenant_id, branch_id, table_code, label, seats, is_active)
- staff (id, branch_id, name, role enum('waiter','manager'), pin, is_active)
- site_settings (setting_key, setting_value) — 66 keys
- upgrade_requests (id, tenant_id, tenant_name, current_plan, requested_plan, note, status)
- tenant_access_log (id, admin_user, tenant_id, action, ip, started_at, ended_at)
- branches (id, tenant_id, name, code, address, phone, open_time, close_time, is_active)

## APIs
### tenant.php APIs (GET)
- ?api=stats → today orders, revenue, pending, low stock
- ?api=orders&branch_id=N → orders list
- ?api=items → menu items
- ?api=branches → tenant branches
- ?api=update_order_status (POST) → status flow

### tenant.php APIs (POST)
- ?api=login → email/password → session
- ?api=logout
- ?api=get_payment_settings / save_payment_settings

### admin.php APIs
- ?api=stats, items, orders, branches, tenants
- ?api=get_settings / save_settings → site_settings
- ?api=impersonate (POST) → set tenant session + log

### External APIs
- menu_api.php?tenant_id=N → public menu (customer ordering)
- menu_api.php?action=add_item (POST) → add item (auth required)
- menu_api.php?action=edit_item (POST) → edit item
- menu_api.php?action=toggle_item (POST) → toggle active
- menu_api.php?action=delete_item (POST) → delete
- tenant_api.php?action=list → all tenants
- tenant_api.php?action=plans → SaaS plans
- tenant_api.php?action=upgrade_requests → pending upgrades
- tenant_api.php?action=approve_upgrade (POST) → approve + update plan
- tenant_api.php?action=reject_upgrade (POST)
- tenant_api.php?action=request_upgrade (POST) → tenant requests upgrade
- site_settings.php?action=get → landing page settings
- site_settings.php?action=save (POST) → save settings (admin session)

## Phase 10 Progress
### ✅ Done
- neking/myanai repo (migrated from neking/noodlehaus)
- myanai.duckdns.org live + SSL
- Full rebrand NoodleHaus → MyanAi POS
- Theme system: Warm Sand (light) / Midnight Black (dark) + toggle
- admin.php → Platform-only portal (Tenants, Revenue, Plans, Landing CMS)
- tenant.php → Full business portal (all modules)
- landing-page.html → Dark AI theme, dynamic CMS, contact section
- Demo tenant (id=9) seeded with real data
- site_settings → 66 keys, CMS-driven landing page
- Menu CRUD API (add/edit/toggle/delete)
- Order status update (pending→confirmed→preparing→ready→delivered)
- Impersonate system (admin → view as tenant) + audit log
- Customer ordering flow tested end-to-end ✅

### ⏳ Remaining
- Menu add/edit form save (front-end calls menu_api.php — test needed)
- Tables/Stock branch_id auto-select (filter by first branch)
- Storefront page (tenant branding customization)
- Per-tenant backup/export (JSON download)
- Demo auto-reset cron
- HR product (hr.myanai.duckdns.org) — future phase
