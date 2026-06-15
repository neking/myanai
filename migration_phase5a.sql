-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 5A Migration: Customer CRM
--  Safe to run multiple times (IF NOT EXISTS / IF EXISTS guards)
--  Does NOT touch: orders, loyalty_cards, order_items, or any
--  existing table column — additive only.
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

-- ────────────────────────────────────────────────────────────────
--  1. CUSTOMERS  (master profile, keyed by phone)
--     phone is the single source of truth — matches orders.customer_phone
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    phone           VARCHAR(30)     NOT NULL,
    name            VARCHAR(120)    NOT NULL DEFAULT '',
    email           VARCHAR(120)    DEFAULT NULL,
    notes           TEXT            DEFAULT NULL        COMMENT 'Staff private notes',
    tag             ENUM(
                        'normal','regular','vip','blocked'
                    )               NOT NULL DEFAULT 'normal',
    preferred_payment VARCHAR(20)   DEFAULT NULL        COMMENT 'Last used payment method',
    -- denormalised counters (updated by crm_api.php on each order)
    total_orders    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_spent     INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT 'MMK',
    last_order_at   DATETIME        DEFAULT NULL,
    -- timestamps
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone (phone),
    KEY idx_tag         (tag),
    KEY idx_last_order  (last_order_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
--  2. CUSTOMER_FAVOURITE_ITEMS  (top items per customer)
--     Rebuilt by crm_api.php — no manual inserts needed.
--     Separate table = orders table never touched.
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customer_favourite_items (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_phone  VARCHAR(30)     NOT NULL,
    menu_item_id    INT UNSIGNED    NOT NULL,
    item_name       VARCHAR(120)    NOT NULL COMMENT 'Snapshot name',
    order_count     SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Times ordered',
    last_ordered_at DATETIME        DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone_item (customer_phone, menu_item_id),
    KEY idx_phone   (customer_phone),
    KEY idx_count   (order_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
--  3. REORDER_TEMPLATES  (saved "quick reorder" carts)
--     One row per saved cart item — customer can save multiple.
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reorder_templates (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_phone  VARCHAR(30)     NOT NULL,
    label           VARCHAR(80)     NOT NULL DEFAULT 'My Usual'
                                    COMMENT 'Customer-facing name',
    menu_item_id    INT UNSIGNED    NOT NULL,
    item_name       VARCHAR(120)    NOT NULL COMMENT 'Snapshot',
    qty             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_phone   (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
--  4. BACK-FILL customers from existing orders
--     Uses MAX(name) so most recent name wins.
--     Runs only if customers table is empty.
-- ────────────────────────────────────────────────────────────────
INSERT INTO customers (phone, name, total_orders, total_spent, last_order_at, preferred_payment)
SELECT
    o.customer_phone                        AS phone,
    MAX(o.customer_name)                    AS name,
    COUNT(DISTINCT o.id)                    AS total_orders,
    COALESCE(SUM(o.total_amount), 0)        AS total_spent,
    MAX(o.created_at)                       AS last_order_at,
    (
        SELECT payment_method
        FROM   orders o2
        WHERE  o2.customer_phone = o.customer_phone
          AND  o2.deleted_at IS NULL
        ORDER  BY o2.created_at DESC
        LIMIT  1
    )                                       AS preferred_payment
FROM orders o
WHERE o.deleted_at IS NULL
  AND o.customer_phone <> ''
GROUP BY o.customer_phone
ON DUPLICATE KEY UPDATE
    name              = VALUES(name),
    total_orders      = VALUES(total_orders),
    total_spent       = VALUES(total_spent),
    last_order_at     = VALUES(last_order_at),
    preferred_payment = VALUES(preferred_payment);


-- ────────────────────────────────────────────────────────────────
--  5. BACK-FILL customer_favourite_items from existing order_items
-- ────────────────────────────────────────────────────────────────
INSERT INTO customer_favourite_items
    (customer_phone, menu_item_id, item_name, order_count, last_ordered_at)
SELECT
    o.customer_phone,
    oi.menu_item_id,
    MAX(oi.item_name)       AS item_name,
    COUNT(*)                AS order_count,
    MAX(o.created_at)       AS last_ordered_at
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE o.deleted_at IS NULL
  AND o.customer_phone <> ''
GROUP BY o.customer_phone, oi.menu_item_id
ON DUPLICATE KEY UPDATE
    item_name       = VALUES(item_name),
    order_count     = VALUES(order_count),
    last_ordered_at = VALUES(last_ordered_at);


-- ────────────────────────────────────────────────────────────────
--  6. Auto-tag VIP customers (≥10 orders or ≥100,000 MMK spent)
-- ────────────────────────────────────────────────────────────────
UPDATE customers
SET    tag = 'vip'
WHERE  tag = 'normal'
  AND  (total_orders >= 10 OR total_spent >= 100000);


-- ────────────────────────────────────────────────────────────────
--  7. Verify
-- ────────────────────────────────────────────────────────────────
SELECT
    'customers'                 AS tbl, COUNT(*) AS total FROM customers
UNION ALL SELECT
    'customer_favourite_items', COUNT(*) FROM customer_favourite_items
UNION ALL SELECT
    'reorder_templates',        COUNT(*) FROM reorder_templates;
