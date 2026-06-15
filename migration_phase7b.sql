USE noodlehaus;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL, phone VARCHAR(30) DEFAULT NULL,
    category VARCHAR(60) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    branch_id INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED DEFAULT NULL,
    category ENUM('ingredients','packaging','utilities','rent','salary','equipment','marketing','other') NOT NULL DEFAULT 'other',
    amount INT NOT NULL DEFAULT 0,
    description VARCHAR(255) DEFAULT NULL,
    receipt_ref VARCHAR(60) DEFAULT NULL,
    expense_date DATE NOT NULL,
    recorded_by VARCHAR(60) DEFAULT NULL,
    branch_id INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_date (expense_date), KEY idx_cat (category), KEY idx_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO suppliers (id, name, phone, category) VALUES
    (1, 'Nyaung U Market', '09111222333', 'ingredients'),
    (2, 'City Mart', '09444555666', 'packaging'),
    (3, 'YESB Electric', '09777888999', 'utilities');

SELECT 'suppliers' AS tbl, COUNT(*) AS total FROM suppliers
UNION ALL SELECT 'expenses', COUNT(*) FROM expenses;
