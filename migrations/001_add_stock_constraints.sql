-- Migration: Add stock quantity constraint
-- Date: 2024-06-24
-- Purpose: Prevent stock_qty from going negative

-- 1. First, fix any existing negative values
UPDATE menu_items SET stock_qty = 0 WHERE stock_qty < 0;

-- 2. Add check constraint (if supported)
-- Note: MySQL 8.0.16+ supports CHECK constraints
-- For older MySQL versions, this can be enforced in application code
ALTER TABLE menu_items
ADD CONSTRAINT chk_stock_qty_non_negative CHECK (stock_qty >= 0);

-- 3. Add index for faster stock queries
ALTER TABLE menu_items 
ADD INDEX idx_stock_status (stock_qty, is_active);

-- 4. Create audit table for stock changes (optional but recommended)
CREATE TABLE IF NOT EXISTS stock_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_item_id INT NOT NULL,
    old_qty INT,
    new_qty INT,
    change_reason VARCHAR(100),
    changed_by VARCHAR(100),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    INDEX idx_item_date (menu_item_id, changed_at)
);

-- 5. Optional: Create view for low stock items
CREATE OR REPLACE VIEW low_stock_items AS
SELECT 
    mi.id,
    mi.tenant_id,
    mi.name,
    mi.stock_qty,
    CASE 
        WHEN stock_qty = 0 THEN 'OUT OF STOCK'
        WHEN stock_qty <= 5 THEN 'LOW STOCK'
        ELSE 'IN STOCK'
    END as status
FROM menu_items mi
WHERE mi.is_active = 1 AND mi.stock_qty <= 5
ORDER BY mi.stock_qty ASC;
