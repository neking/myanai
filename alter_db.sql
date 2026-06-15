-- ════════════════════════════════════════════════════════
--  NoodleHaus — DB Alterations
--  phpMyAdmin → noodlehaus → SQL tab → paste → Go
-- ════════════════════════════════════════════════════════

-- 1. orders table မှာ deleted_at နဲ့ delete_reason column ထည့်
ALTER TABLE orders
  ADD COLUMN deleted_at   DATETIME     DEFAULT NULL AFTER updated_at,
  ADD COLUMN delete_reason VARCHAR(300) DEFAULT NULL AFTER deleted_at,
  ADD COLUMN deleted_by   VARCHAR(60)  DEFAULT NULL AFTER delete_reason;

-- 2. menu_items မှာ image_path column ထည့်
ALTER TABLE menu_items
  ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER emoji;

-- 3. deleted_orders_log — archive table (record ထားဖို့)
CREATE TABLE IF NOT EXISTS deleted_orders_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    original_id     INT UNSIGNED NOT NULL COMMENT 'orders.id',
    order_ref       VARCHAR(20)  NOT NULL,
    customer_name   VARCHAR(120) NOT NULL,
    customer_phone  VARCHAR(30)  NOT NULL,
    total_amount    INT UNSIGNED NOT NULL,
    payment_method  VARCHAR(20)  NOT NULL,
    order_status    VARCHAR(30)  NOT NULL,
    items_snapshot  TEXT         NOT NULL COMMENT 'JSON of order items',
    delete_reason   VARCHAR(300) NOT NULL,
    deleted_by      VARCHAR(60)  DEFAULT 'admin',
    deleted_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_original (original_id),
    KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. uploads folder hint (PHP မှာ mkdir လုပ်မည်)
-- htdocs/noodlehaus/uploads/menu/ folder ကို manually ဆောက်ပါ
-- သို့မဟုတ် admin.php upload လုပ်ရင် auto create ဖြစ်မည်
