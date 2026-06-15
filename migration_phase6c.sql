-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 6C Migration: Delivery Platform
--  Additive only — orders table မထိ
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

CREATE TABLE IF NOT EXISTS drivers (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120)    NOT NULL,
    phone           VARCHAR(30)     NOT NULL,
    vehicle_type    ENUM('motorbike','bicycle','car','walk') NOT NULL DEFAULT 'motorbike',
    status          ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
    pin             VARCHAR(10)     DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    branch_id       INT UNSIGNED    NOT NULL DEFAULT 1,
    total_deliveries INT UNSIGNED   NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status  (status),
    KEY idx_branch  (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_zones (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    zone_name       VARCHAR(80)     NOT NULL,
    township        VARCHAR(80)     DEFAULT NULL,
    fee             INT UNSIGNED    NOT NULL DEFAULT 1500 COMMENT 'MMK',
    estimated_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    branch_id       INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_branch  (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_tracking (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    order_id        INT UNSIGNED    NOT NULL,
    driver_id       INT UNSIGNED    DEFAULT NULL,
    status          ENUM('pending','assigned','picked_up','delivering','delivered','cancelled')
                                    NOT NULL DEFAULT 'pending',
    assigned_at     DATETIME        DEFAULT NULL,
    picked_up_at    DATETIME        DEFAULT NULL,
    delivered_at    DATETIME        DEFAULT NULL,
    delivery_notes  TEXT            DEFAULT NULL,
    customer_rating TINYINT UNSIGNED DEFAULT NULL COMMENT '1-5 stars',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order (order_id),
    KEY idx_driver  (driver_id),
    KEY idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO delivery_zones (id, zone_name, township, fee, estimated_min) VALUES
    (1, 'Nearby', 'Kamayut', 1500, 20),
    (2, 'Mid-range', 'Hlaing', 2000, 30),
    (3, 'Far', 'Insein', 3000, 45),
    (4, 'Downtown', 'Pabedan', 2500, 35);

INSERT IGNORE INTO drivers (id, name, phone, vehicle_type, pin) VALUES
    (1, 'Ko Thura', '09881112222', 'motorbike', '1111'),
    (2, 'Mg Zaw', '09883334444', 'motorbike', '2222'),
    (3, 'Ko Htet', '09885556666', 'bicycle', '3333');

SELECT 'drivers' AS tbl, COUNT(*) AS total FROM drivers
UNION ALL SELECT 'delivery_zones', COUNT(*) FROM delivery_zones
UNION ALL SELECT 'delivery_tracking', COUNT(*) FROM delivery_tracking;
