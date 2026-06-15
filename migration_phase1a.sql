-- ============================================================
-- NoodleHaus Phase 1A Migration
-- Modifiers + Station + Dine-in enhancements
-- Safe to run on production — ADD ONLY, no existing data touched
-- ============================================================

-- 1. menu_items: station field ထည့်
ALTER TABLE menu_items
  ADD COLUMN station VARCHAR(30) NOT NULL DEFAULT 'kitchen'
    COMMENT 'kitchen | counter | bar | all'
    AFTER sort_order;

-- 2. modifier_groups table
CREATE TABLE modifier_groups (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  menu_item_id INT UNSIGNED    NOT NULL,
  name         VARCHAR(80)     NOT NULL,
  type         ENUM('single','multi','text') NOT NULL DEFAULT 'single',
  required     TINYINT(1)      NOT NULL DEFAULT 0,
  sort_order   INT             NOT NULL DEFAULT 0,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_menu_item (menu_item_id),
  CONSTRAINT fk_mg_menu_item
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. modifier_options table
CREATE TABLE modifier_options (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  group_id   INT UNSIGNED    NOT NULL,
  label      VARCHAR(80)     NOT NULL,
  price_add  INT             NOT NULL DEFAULT 0,
  is_default TINYINT(1)      NOT NULL DEFAULT 0,
  sort_order INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_group (group_id),
  CONSTRAINT fk_mo_group
    FOREIGN KEY (group_id) REFERENCES modifier_groups(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. order_item_modifiers table
CREATE TABLE order_item_modifiers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id INT UNSIGNED NOT NULL,
  group_id      INT UNSIGNED NULL,
  option_id     INT UNSIGNED NULL,
  group_name    VARCHAR(80)  NOT NULL,
  label         VARCHAR(80)  NOT NULL,
  price_add     INT          NOT NULL DEFAULT 0,
  free_text     VARCHAR(300) NULL,
  PRIMARY KEY (id),
  KEY idx_order_item (order_item_id),
  CONSTRAINT fk_oim_order_item
    FOREIGN KEY (order_item_id) REFERENCES order_items(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. order_items: modifier_total ထည့်
ALTER TABLE order_items
  ADD COLUMN modifier_total INT NOT NULL DEFAULT 0
    COMMENT 'Sum of all modifier price_add for this item'
    AFTER subtotal,
  ADD COLUMN station VARCHAR(30) NOT NULL DEFAULT 'kitchen'
    AFTER modifier_total,
  ADD COLUMN station_status ENUM('pending','preparing','ready','served')
    NOT NULL DEFAULT 'pending'
    AFTER station;

-- 6. kds_queue: station field ထည့်
ALTER TABLE kds_queue
  ADD COLUMN station VARCHAR(30) NOT NULL DEFAULT 'all'
    AFTER order_id,
  ADD COLUMN order_item_id INT UNSIGNED NULL
    COMMENT 'NULL = whole order, NOT NULL = per-item station ticket'
    AFTER station;

-- 7. View update — v_kds_board ကို drop/recreate
DROP VIEW IF EXISTS v_kds_board;
CREATE VIEW v_kds_board AS
SELECT
  kq.id            AS kds_id,
  kq.order_id,
  kq.station,
  kq.order_item_id,
  kq.status,
  kq.pushed_at,
  kq.started_at,
  kq.ready_at,
  o.id             AS order_ref_id,
  CONCAT('NH-', LPAD(o.id,6,'0')) AS order_ref,
  o.customer_name  AS customer,
  o.special_notes  AS notes,
  o.order_type,
  o.table_id,
  o.created_at     AS order_time
FROM kds_queue kq
JOIN orders o ON o.id = kq.order_id
WHERE o.deleted_at IS NULL;

-- ============================================================
-- Verify
-- ============================================================
SELECT 'modifier_groups'       AS tbl, COUNT(*) AS rows FROM modifier_groups
UNION ALL
SELECT 'modifier_options',       COUNT(*) FROM modifier_options
UNION ALL
SELECT 'order_item_modifiers',   COUNT(*) FROM order_item_modifiers;

SHOW COLUMNS FROM menu_items    LIKE 'station';
SHOW COLUMNS FROM order_items   LIKE 'modifier_total';
SHOW COLUMNS FROM order_items   LIKE 'station%';
SHOW COLUMNS FROM kds_queue     LIKE 'station';
