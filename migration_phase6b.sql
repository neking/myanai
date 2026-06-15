-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 6B Migration: Multi-Branch
--  SAFE: Adds branch_id DEFAULT 1 — existing data/queries unchanged
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

-- ────────────────────────────────────────────────────────────────
--  1. BRANCHES master table
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS branches (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120)    NOT NULL,
    code            VARCHAR(20)     NOT NULL COMMENT 'Short code e.g. YGN1, MDY1',
    address         TEXT            DEFAULT NULL,
    phone           VARCHAR(30)     DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    timezone        VARCHAR(40)     NOT NULL DEFAULT 'Asia/Yangon',
    currency        VARCHAR(10)     NOT NULL DEFAULT 'MMK',
    opening_time    TIME            DEFAULT '10:00:00',
    closing_time    TIME            DEFAULT '23:00:00',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed branch 1 = current main restaurant
INSERT IGNORE INTO branches (id, name, code, address) VALUES
    (1, 'NoodleHaus Main', 'MAIN', 'Yangon');


-- ────────────────────────────────────────────────────────────────
--  2. ADD branch_id to key tables (IF NOT EXISTS guard)
--     DEFAULT 1 = all existing data belongs to main branch
-- ────────────────────────────────────────────────────────────────

-- orders
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE orders ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "orders.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- menu_items
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='menu_items' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE menu_items ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "menu_items.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- staff
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='staff' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE staff ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "staff.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- restaurant_tables
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='restaurant_tables' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE restaurant_tables ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "restaurant_tables.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- shifts
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='shifts' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE shifts ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "shifts.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reservations
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='reservations' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE reservations ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "reservations.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- kds_queue
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='kds_queue' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE kds_queue ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "kds_queue.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- stock_log
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='stock_log' AND COLUMN_NAME='branch_id');
SET @sql = IF(@col_exists=0,
    'ALTER TABLE stock_log ADD COLUMN branch_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_branch (branch_id)',
    'SELECT "stock_log.branch_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ────────────────────────────────────────────────────────────────
--  3. Verify
-- ────────────────────────────────────────────────────────────────
SELECT 'branches' AS tbl, COUNT(*) AS total FROM branches
UNION ALL SELECT 'orders (branch_id col)',
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='branch_id')
UNION ALL SELECT 'menu_items (branch_id col)',
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='menu_items' AND COLUMN_NAME='branch_id');
