USE noodlehaus;

CREATE TABLE IF NOT EXISTS schedules (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id    INT UNSIGNED NOT NULL,
    work_date   DATE NOT NULL,
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    role        VARCHAR(40) DEFAULT NULL COMMENT 'waiter,kitchen,cashier,manager',
    status      ENUM('scheduled','confirmed','completed','absent','cancelled') NOT NULL DEFAULT 'scheduled',
    hourly_rate INT UNSIGNED NOT NULL DEFAULT 1500 COMMENT 'MMK per hour',
    notes       VARCHAR(255) DEFAULT NULL,
    branch_id   INT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_date (staff_id, work_date),
    KEY idx_date (work_date),
    KEY idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'schedules' AS tbl, COUNT(*) AS total FROM schedules;
