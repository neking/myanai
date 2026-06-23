-- Migration: Add performance indexes
-- Date: 2024-06-23
-- Purpose: Speed up most common queries across the application

-- orders table (most queried)
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_tenant_created   (tenant_id, created_at);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_tenant_status    (tenant_id, status);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_branch_created   (branch_id, created_at);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_tenant_deleted   (tenant_id, deleted_at);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_table_status     (table_id, table_status);
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_tenant_date_status (tenant_id, status, created_at);

-- order_items table
ALTER TABLE order_items ADD INDEX IF NOT EXISTS idx_order_id     (order_id);
ALTER TABLE order_items ADD INDEX IF NOT EXISTS idx_menu_item_id (menu_item_id);

-- menu_items table
ALTER TABLE menu_items ADD INDEX IF NOT EXISTS idx_tenant_active   (tenant_id, is_active);
ALTER TABLE menu_items ADD INDEX IF NOT EXISTS idx_tenant_category (tenant_id, category);
ALTER TABLE menu_items ADD INDEX IF NOT EXISTS idx_tenant_stock    (tenant_id, stock_qty);

-- staff table
ALTER TABLE staff ADD INDEX IF NOT EXISTS idx_branch_active (branch_id, is_active);
ALTER TABLE staff ADD INDEX IF NOT EXISTS idx_branch_pin    (branch_id, pin);

-- tenants table
ALTER TABLE tenants ADD INDEX IF NOT EXISTS idx_slug        (slug);
ALTER TABLE tenants ADD INDEX IF NOT EXISTS idx_owner_email (owner_email);
ALTER TABLE tenants ADD INDEX IF NOT EXISTS idx_active_plan (is_active, plan);

-- branches table
ALTER TABLE branches ADD INDEX IF NOT EXISTS idx_tenant_active (tenant_id, is_active);

-- customers / crm
ALTER TABLE crm_customers ADD INDEX IF NOT EXISTS idx_tenant_phone (tenant_id, phone) ;

-- kds_queue
ALTER TABLE kds_queue ADD INDEX IF NOT EXISTS idx_order_station (order_id, station);
ALTER TABLE kds_queue ADD INDEX IF NOT EXISTS idx_status_station (status, station);
