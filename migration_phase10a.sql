-- Phase 10a: tenant_access_log table
CREATE TABLE IF NOT EXISTS tenant_access_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user  VARCHAR(60) NOT NULL,
    tenant_id   INT UNSIGNED NOT NULL,
    action      VARCHAR(30) NOT NULL DEFAULT 'impersonate',
    ip          VARCHAR(45) DEFAULT NULL,
    started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME DEFAULT NULL,
    INDEX (tenant_id),
    INDEX (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 10a: update upgrade_requests if not exists
CREATE TABLE IF NOT EXISTS upgrade_requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id      INT UNSIGNED NOT NULL,
    tenant_name    VARCHAR(120) DEFAULT '',
    current_plan   VARCHAR(20)  DEFAULT 'free',
    requested_plan VARCHAR(20)  NOT NULL,
    note           TEXT,
    status         ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (tenant_id),
    INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
