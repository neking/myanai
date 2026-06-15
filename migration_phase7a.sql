USE noodlehaus;

CREATE TABLE IF NOT EXISTS promotions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    type            ENUM('percent_off','fixed_off','bogo','free_item','combo') NOT NULL DEFAULT 'percent_off',
    code            VARCHAR(30)  DEFAULT NULL COMMENT 'Promo code (null=auto-apply)',
    value           DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Discount value (% or MMK)',
    min_order       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Min order amount to qualify',
    max_discount    INT UNSIGNED DEFAULT NULL COMMENT 'Cap for percent_off',
    applies_to      ENUM('all','category','item') NOT NULL DEFAULT 'all',
    applies_id      INT UNSIGNED DEFAULT NULL COMMENT 'menu_item_id or category name ref',
    applies_category VARCHAR(60) DEFAULT NULL,
    free_item_id    INT UNSIGNED DEFAULT NULL COMMENT 'For bogo/free_item: which item is free',
    start_date      DATE DEFAULT NULL,
    end_date        DATE DEFAULT NULL,
    happy_hour_start TIME DEFAULT NULL COMMENT 'Daily time window start',
    happy_hour_end   TIME DEFAULT NULL COMMENT 'Daily time window end',
    days_of_week    VARCHAR(20)  DEFAULT NULL COMMENT 'CSV: mon,tue,wed,thu,fri,sat,sun',
    max_uses        INT UNSIGNED DEFAULT NULL COMMENT 'Total uses allowed (null=unlimited)',
    used_count      INT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    branch_id       INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code),
    KEY idx_active (is_active, start_date, end_date),
    KEY idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_usage (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promo_id        INT UNSIGNED NOT NULL,
    order_id        INT UNSIGNED NOT NULL,
    discount_amount INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_promo (promo_id),
    KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sample promos
INSERT IGNORE INTO promotions (id, name, type, code, value, min_order, max_discount) VALUES
    (1, 'Welcome 10% Off', 'percent_off', 'WELCOME10', 10, 5000, 3000),
    (2, 'Free Delivery over 15K', 'fixed_off', NULL, 1500, 15000, NULL),
    (3, 'Happy Hour Noodles 20%', 'percent_off', NULL, 20, 0, 5000);

UPDATE promotions SET happy_hour_start='17:00', happy_hour_end='19:00', days_of_week='mon,tue,wed,thu,fri', applies_to='category', applies_category='Noodles' WHERE id=3;

SELECT 'promotions' AS tbl, COUNT(*) AS total FROM promotions;
