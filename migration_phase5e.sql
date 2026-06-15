-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 5E Migration: Stock Management
--  Additive only — menu_items table မထိ (stock_qty ရှိပြီးသား)
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

CREATE TABLE IF NOT EXISTS stock_log (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    menu_item_id    INT UNSIGNED    NOT NULL,
    item_name       VARCHAR(120)    NOT NULL COMMENT 'Snapshot',
    change_qty      INT             NOT NULL COMMENT 'Positive=add, Negative=deduct',
    new_qty         INT UNSIGNED    NOT NULL COMMENT 'Stock after change',
    reason          ENUM('restock','manual_adjust','order_deduct','waste','correction','returned')
                                    NOT NULL DEFAULT 'manual_adjust',
    note            VARCHAR(255)    DEFAULT NULL,
    staff_name      VARCHAR(120)    DEFAULT NULL,
    order_id        INT UNSIGNED    DEFAULT NULL COMMENT 'If deducted by order',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item        (menu_item_id),
    KEY idx_reason      (reason),
    KEY idx_created     (created_at),
    KEY idx_order       (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'stock_log' AS tbl, COUNT(*) AS total FROM stock_log;
