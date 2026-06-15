-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Database Schema
--  Run once:  mysql -u root -p < schema.sql
-- ════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS noodlehaus
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE noodlehaus;

-- ── Create application user (restricted, no SUPER) ──
-- CREATE USER IF NOT EXISTS 'nh_app'@'localhost' IDENTIFIED BY 'StrongPass#2024!';
-- GRANT SELECT, INSERT, UPDATE ON noodlehaus.* TO 'nh_app'@'localhost';
-- FLUSH PRIVILEGES;


-- ════════════════════════════════════════════════════════════════
--  MENU ITEMS  (inventory source of truth)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS menu_items (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)    NOT NULL,
    category    VARCHAR(60)     NOT NULL,
    description TEXT,
    price       INT UNSIGNED    NOT NULL COMMENT 'Price in MMK (no decimals)',
    stock_qty   SMALLINT NOT NULL DEFAULT 0,
    emoji       VARCHAR(10)     DEFAULT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_category (category),
    KEY idx_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
--  ORDERS
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS orders (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_name     VARCHAR(120)    NOT NULL,
    customer_phone    VARCHAR(30)     NOT NULL,
    delivery_address  VARCHAR(300)    NOT NULL,
    township          VARCHAR(80)     DEFAULT NULL,
    city              VARCHAR(80)     DEFAULT NULL,
    special_notes     TEXT,
    payment_method    ENUM(
                          'kpay','wavepay','cbpay','ayapay','cod','card'
                      ) NOT NULL DEFAULT 'cod',
    payment_status    ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    subtotal          INT UNSIGNED    NOT NULL,
    delivery_fee      INT UNSIGNED    NOT NULL DEFAULT 1500,
    total_amount      INT UNSIGNED    NOT NULL,
    status            ENUM(
                          'pending','confirmed','preparing',
                          'ready','out_for_delivery','delivered','cancelled'
                      ) NOT NULL DEFAULT 'pending',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status     (status),
    KEY idx_created_at (created_at),
    KEY idx_phone      (customer_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
--  ORDER ITEMS  (line-items, immutable snapshot of price)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS order_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      INT UNSIGNED NOT NULL,
    menu_item_id  INT UNSIGNED NOT NULL,
    item_name     VARCHAR(120) NOT NULL COMMENT 'Snapshot — item may be renamed later',
    unit_price    INT UNSIGNED NOT NULL COMMENT 'Price at time of purchase',
    qty           SMALLINT UNSIGNED NOT NULL,
    subtotal      INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order    (order_id),
    KEY idx_menuitem (menu_item_id),
    CONSTRAINT fk_oi_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_menu
        FOREIGN KEY (menu_item_id) REFERENCES menu_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
--  KDS QUEUE  (Kitchen Display System feed)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS kds_queue (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    status      ENUM('pending','preparing','ready','served') NOT NULL DEFAULT 'pending',
    pushed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at  DATETIME     DEFAULT NULL COMMENT 'Kitchen started cooking',
    ready_at    DATETIME     DEFAULT NULL COMMENT 'Marked ready by chef',
    PRIMARY KEY (id),
    KEY idx_status   (status),
    KEY idx_order_id (order_id),
    CONSTRAINT fk_kds_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
--  AUDIT LOG  (optional — tracks every stock change)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS inventory_log (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_item_id  INT UNSIGNED NOT NULL,
    order_id      INT UNSIGNED DEFAULT NULL,
    change_qty    SMALLINT     NOT NULL COMMENT 'Negative = sold, Positive = restocked',
    reason        VARCHAR(80)  DEFAULT 'sale',
    logged_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item  (menu_item_id),
    KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
--  TRIGGER — auto-log inventory deductions
-- ════════════════════════════════════════════════════════════════
DELIMITER $$

CREATE TRIGGER trg_stock_audit
AFTER UPDATE ON menu_items
FOR EACH ROW
BEGIN
    IF NEW.stock_qty <> OLD.stock_qty THEN
        INSERT INTO inventory_log (menu_item_id, change_qty, reason)
        VALUES (
            NEW.id,
            CAST(NEW.stock_qty AS SIGNED) - CAST(OLD.stock_qty AS SIGNED),
            'order_deduction'
        );
    END IF;
END$$

DELIMITER ;


-- ════════════════════════════════════════════════════════════════
--  SEED DATA  — matches JS menu in index.html
-- ════════════════════════════════════════════════════════════════
INSERT INTO menu_items (id, name, category, description, price, stock_qty, emoji) VALUES
(1,  'Mohinga',            'Noodles',  'Traditional fish-broth noodle soup',      4500, 20, '🍲'),
(2,  'Shan Noodles',       'Noodles',  'Light pork-broth rice noodles',            4000, 15, '🍜'),
(3,  'Beef Kway Teow',     'Noodles',  'Stir-fried flat rice noodles with beef',   5500, 10, '🥡'),
(4,  'Ramen Bowl',         'Noodles',  'Japanese-style ramen with chashu pork',    6000,  8, '🍥'),
(5,  'Char Siu Rice',      'Rice',     'Barbecue pork over steamed jasmine rice',  5000, 12, '🍚'),
(6,  'Coconut Rice',       'Rice',     'Fragrant coconut jasmine rice',            3500, 25, '🌾'),
(7,  'Fried Rice Deluxe',  'Rice',     'Wok-tossed with egg, prawns, vegetables',  5500, 18, '🍳'),
(8,  'Chicken Satay',      'Starters', 'Grilled skewers with peanut sauce',        4000, 30, '🍡'),
(9,  'Spring Rolls 6pc',   'Starters', 'Crispy rolls with glass noodles',          3000, 20, '🥢'),
(10, 'Tom Yum Soup',       'Soups',    'Hot and sour Thai soup with prawns',       4500, 14, '🫕'),
(11, 'Miso Ramen Soup',    'Soups',    'Classic miso broth with tofu',             4000,  3, '🍵'),
(12, 'Mango Sticky Rice',  'Desserts', 'Sweet sticky rice with fresh mango',       3000,  0, '🥭'),
(13, 'Taro Bubble Tea',    'Drinks',   'Creamy taro milk tea with tapioca',        2500, 40, '🧋'),
(14, 'Thai Milk Tea',      'Drinks',   'Spiced black tea with condensed milk',     2000, 50, '🧉'),
(15, 'Lychee Soda',        'Drinks',   'Lychee-flavoured sparkling drink 500ml',   1800, 35, '🫧'),
(16, 'Pad Thai',           'Noodles',  'Stir-fried rice noodles with peanuts',     6000,  2, '🥘')
ON DUPLICATE KEY UPDATE
    name=VALUES(name), price=VALUES(price), stock_qty=VALUES(stock_qty);


-- ════════════════════════════════════════════════════════════════
--  USEFUL VIEWS
-- ════════════════════════════════════════════════════════════════

-- Active orders with item count
CREATE OR REPLACE VIEW v_active_orders AS
SELECT
    o.id,
    CONCAT('NH-', LPAD(o.id, 6, '0')) AS order_ref,
    o.customer_name,
    o.customer_phone,
    o.status,
    o.payment_method,
    o.total_amount,
    COUNT(oi.id)   AS item_lines,
    SUM(oi.qty)    AS total_items,
    o.created_at
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.status NOT IN ('delivered','cancelled')
GROUP BY o.id;

-- KDS live board
CREATE OR REPLACE VIEW v_kds_board AS
SELECT
    kq.id              AS kds_id,
    CONCAT('NH-', LPAD(o.id, 6, '0')) AS order_ref,
    o.customer_name,
    o.special_notes,
    kq.status,
    kq.pushed_at,
    GROUP_CONCAT(
        CONCAT(oi.qty, '× ', oi.item_name)
        ORDER BY oi.id SEPARATOR ' | '
    ) AS items_summary
FROM kds_queue kq
JOIN orders     o  ON o.id  = kq.order_id
JOIN order_items oi ON oi.order_id = o.id
WHERE kq.status IN ('pending','preparing')
GROUP BY kq.id
ORDER BY kq.pushed_at ASC;

-- Low stock alert
CREATE OR REPLACE VIEW v_low_stock AS
SELECT id, name, category, stock_qty
FROM menu_items
WHERE is_active = 1 AND stock_qty <= 5
ORDER BY stock_qty ASC;
