-- ════════════════════════════════════════════════════════
--  NoodleHaus — Table / Dine-in Migration (MySQL 8)
--  PuTTY: mysql -u root noodlehaus < ~/migrate_table.sql
-- ════════════════════════════════════════════════════════

-- 1. orders: order_type column
SET @c=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='order_type');
SET @s=IF(@c=0,"ALTER TABLE orders ADD COLUMN order_type ENUM('delivery','dine_in') NOT NULL DEFAULT 'delivery' AFTER status","SELECT 'order_type exists'");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 2. orders: table_id column
SET @c=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='table_id');
SET @s=IF(@c=0,"ALTER TABLE orders ADD COLUMN table_id VARCHAR(20) DEFAULT NULL AFTER order_type","SELECT 'table_id exists'");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 3. orders: table_status column
SET @c=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='table_status');
SET @s=IF(@c=0,"ALTER TABLE orders ADD COLUMN table_status ENUM('open','billed','paid') DEFAULT NULL AFTER table_id","SELECT 'table_status exists'");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 4. tables config table
CREATE TABLE IF NOT EXISTS restaurant_tables (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_code  VARCHAR(20)  NOT NULL UNIQUE COMMENT 'e.g. T01, T02, VIP1',
    label       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'e.g. Window Seat, Counter',
    seats       TINYINT      NOT NULL DEFAULT 4,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_code (table_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Default tables (T01–T08)
INSERT IGNORE INTO restaurant_tables (table_code, label, seats) VALUES
('T01','Table 1',4),('T02','Table 2',4),('T03','Table 3',4),('T04','Table 4',4),
('T05','Table 5',4),('T06','Table 6',4),('T07','Table 7',2),('T08','Table 8',2);

-- 6. Verify
SELECT table_code, label, seats, is_active FROM restaurant_tables ORDER BY table_code;
