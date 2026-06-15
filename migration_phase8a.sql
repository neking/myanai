USE noodlehaus;

-- Tenants (SaaS clients)
CREATE TABLE IF NOT EXISTS tenants (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL COMMENT 'Business name',
    slug        VARCHAR(60)  NOT NULL COMMENT 'URL slug: myshop.noodlehaus.com',
    owner_name  VARCHAR(120) NOT NULL,
    owner_email VARCHAR(120) NOT NULL,
    owner_phone VARCHAR(30)  NOT NULL,
    plan        ENUM('free','basic','pro','enterprise') NOT NULL DEFAULT 'free',
    plan_expires DATE DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    max_branches TINYINT     NOT NULL DEFAULT 1,
    max_staff   TINYINT      NOT NULL DEFAULT 5,
    max_menu_items INT       NOT NULL DEFAULT 50,
    settings    JSON         DEFAULT NULL COMMENT 'Tenant-specific config',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    UNIQUE KEY uq_email (owner_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plans definition
CREATE TABLE IF NOT EXISTS saas_plans (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(40)  NOT NULL,
    code        VARCHAR(20)  NOT NULL,
    price_mmk   INT UNSIGNED NOT NULL DEFAULT 0,
    price_usd   DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_branches TINYINT     NOT NULL DEFAULT 1,
    max_staff   TINYINT      NOT NULL DEFAULT 5,
    max_menu_items INT       NOT NULL DEFAULT 50,
    features    JSON         DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Billing records
CREATE TABLE IF NOT EXISTS billing (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   INT UNSIGNED NOT NULL,
    plan_code   VARCHAR(20)  NOT NULL,
    amount      INT NOT NULL,
    currency    VARCHAR(5)   NOT NULL DEFAULT 'MMK',
    payment_method VARCHAR(20) DEFAULT NULL,
    payment_ref VARCHAR(60)  DEFAULT NULL,
    status      ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant (tenant_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add tenant_id to orders (for SaaS routing)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='noodlehaus' AND TABLE_NAME='orders' AND COLUMN_NAME='tenant_id');
SET @sql = IF(@col=0, 'ALTER TABLE orders ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER branch_id, ADD KEY idx_tenant (tenant_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed plans
INSERT IGNORE INTO saas_plans (id, name, code, price_mmk, price_usd, max_branches, max_staff, max_menu_items, features) VALUES
    (1, 'Free', 'free', 0, 0, 1, 3, 20, '{"pos":true,"kds":true,"loyalty":false,"crm":false,"delivery":false,"multi_branch":false}'),
    (2, 'Basic', 'basic', 50000, 16, 1, 5, 50, '{"pos":true,"kds":true,"loyalty":true,"crm":true,"delivery":false,"multi_branch":false}'),
    (3, 'Pro', 'pro', 150000, 50, 3, 15, 200, '{"pos":true,"kds":true,"loyalty":true,"crm":true,"delivery":true,"multi_branch":true}'),
    (4, 'Enterprise', 'enterprise', 300000, 100, 10, 50, 500, '{"pos":true,"kds":true,"loyalty":true,"crm":true,"delivery":true,"multi_branch":true}');

-- Seed tenant 1 = current restaurant (self)
INSERT IGNORE INTO tenants (id, name, slug, owner_name, owner_email, owner_phone, plan, max_branches, max_staff, max_menu_items) VALUES
    (1, 'NoodleHaus Main', 'main', 'Admin', 'admin@noodlehaus.com', '09000000000', 'enterprise', 10, 50, 500);

SELECT 'tenants' AS tbl, COUNT(*) AS total FROM tenants
UNION ALL SELECT 'saas_plans', COUNT(*) FROM saas_plans
UNION ALL SELECT 'billing', COUNT(*) FROM billing;
