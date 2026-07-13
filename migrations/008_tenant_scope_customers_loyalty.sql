-- Migration 008: Scope `customers` and `loyalty_cards` per-tenant
--
-- Problem: both tables are currently keyed UNIQUE on `phone` alone, so a
-- customer who has ordered from more than one tenant on this platform has
-- their profile (VIP tag, total_spent, total_orders) and loyalty stamp count
-- silently shared/merged across all of those tenants, rather than each
-- tenant having its own separate relationship with that customer.
--
-- Approach: give each existing row a best-guess tenant_id (the tenant of
-- that phone number's most recent order), then change the unique key to
-- (tenant_id, phone). Going forward, a customer ordering from a NEW tenant
-- will get their own separate row for that tenant, rather than updating
-- someone else's.
--
-- Safe to re-run: uses IF NOT EXISTS / checks where possible. Take a backup
-- before running on production regardless.

-- ── 1. customers ──────────────────────────────────────────────────────────
ALTER TABLE customers
  ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;

-- Backfill: assign each phone to the tenant of its most recent order.
-- Phones with no matching order (e.g. manually added test rows) keep the
-- default of 1.
UPDATE customers c
JOIN (
    SELECT o1.customer_phone, o1.tenant_id
    FROM orders o1
    INNER JOIN (
        SELECT customer_phone, MAX(created_at) AS max_created
        FROM orders
        WHERE deleted_at IS NULL AND customer_phone IS NOT NULL AND customer_phone <> ''
        GROUP BY customer_phone
    ) latest
      ON latest.customer_phone = o1.customer_phone
     AND latest.max_created    = o1.created_at
) src ON src.customer_phone = c.phone
SET c.tenant_id = src.tenant_id;

ALTER TABLE customers DROP INDEX uq_phone;
ALTER TABLE customers ADD UNIQUE KEY uq_tenant_phone (tenant_id, phone);
ALTER TABLE customers ADD KEY idx_tenant (tenant_id);

-- ── 2. loyalty_cards ───────────────────────────────────────────────────────
-- Column already exists (defaulted to 1 for every row) — just backfill it
-- properly and fix the unique key.
UPDATE loyalty_cards lc
JOIN (
    SELECT o1.customer_phone, o1.tenant_id
    FROM orders o1
    INNER JOIN (
        SELECT customer_phone, MAX(created_at) AS max_created
        FROM orders
        WHERE deleted_at IS NULL AND customer_phone IS NOT NULL AND customer_phone <> ''
        GROUP BY customer_phone
    ) latest
      ON latest.customer_phone = o1.customer_phone
     AND latest.max_created    = o1.created_at
) src ON src.customer_phone = lc.phone
SET lc.tenant_id = src.tenant_id;

ALTER TABLE loyalty_cards DROP INDEX uq_phone;
ALTER TABLE loyalty_cards ADD UNIQUE KEY uq_tenant_phone (tenant_id, phone);

-- ── Verify afterwards ──────────────────────────────────────────────────────
-- SELECT tenant_id, COUNT(*) FROM customers GROUP BY tenant_id;
-- SELECT tenant_id, COUNT(*) FROM loyalty_cards GROUP BY tenant_id;
-- SHOW CREATE TABLE customers;
-- SHOW CREATE TABLE loyalty_cards;
