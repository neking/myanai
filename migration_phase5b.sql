-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 5B Migration: Shift Management
--  Additive only — staff / orders / existing tables မထိ
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

-- ────────────────────────────────────────────────────────────────
--  1. SHIFTS  (one row per shift)
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shifts (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    staff_id        INT UNSIGNED    NOT NULL            COMMENT 'Who opened the shift',
    staff_name      VARCHAR(120)    NOT NULL            COMMENT 'Snapshot',
    opened_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at       DATETIME        DEFAULT NULL,
    opening_cash    INT UNSIGNED    NOT NULL DEFAULT 0  COMMENT 'Cash in drawer at open (MMK)',
    closing_cash    INT UNSIGNED    DEFAULT NULL        COMMENT 'Actual cash at close (MMK)',
    notes           TEXT            DEFAULT NULL,
    status          ENUM('open','closed') NOT NULL DEFAULT 'open',
    -- Calculated at close time (denormalised for speed)
    total_orders    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_revenue   INT UNSIGNED    NOT NULL DEFAULT 0,
    cash_revenue    INT UNSIGNED    NOT NULL DEFAULT 0,
    digital_revenue INT UNSIGNED    NOT NULL DEFAULT 0,
    cash_difference INT             DEFAULT NULL        COMMENT 'closing_cash - opening_cash - cash_revenue',
    PRIMARY KEY (id),
    KEY idx_status      (status),
    KEY idx_staff       (staff_id),
    KEY idx_opened_at   (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
--  2. SHIFT_ORDERS  (link table — which orders belong to a shift)
--     Read-only join — orders table ကို မထိ
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shift_orders (
    shift_id    INT UNSIGNED NOT NULL,
    order_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (shift_id, order_id),
    KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
--  3. Verify
-- ────────────────────────────────────────────────────────────────
SELECT 'shifts'       AS tbl, COUNT(*) AS total FROM shifts
UNION ALL
SELECT 'shift_orders', COUNT(*) FROM shift_orders;
