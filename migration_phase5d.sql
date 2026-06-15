-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 5D Migration: Table Reservation
--  Additive only — restaurant_tables မထိ
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

CREATE TABLE IF NOT EXISTS reservations (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    customer_name   VARCHAR(120)    NOT NULL,
    customer_phone  VARCHAR(30)     NOT NULL,
    party_size      TINYINT UNSIGNED NOT NULL DEFAULT 2,
    table_code      VARCHAR(20)     DEFAULT NULL COMMENT 'Assigned table (nullable = auto-assign)',
    reservation_date DATE           NOT NULL,
    reservation_time TIME           NOT NULL,
    duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    status          ENUM('pending','confirmed','seated','completed','cancelled','no_show')
                                    NOT NULL DEFAULT 'pending',
    notes           TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_date        (reservation_date, reservation_time),
    KEY idx_phone       (customer_phone),
    KEY idx_status      (status),
    KEY idx_table       (table_code, reservation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'reservations' AS tbl, COUNT(*) AS total FROM reservations;
