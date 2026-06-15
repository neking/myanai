<?php
/**
 * Tenant isolation helper
 * Usage: require_once 'tenant_helper.php';
 *        $tid = getCurrentTenantId();
 */

function getCurrentTenantId(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    // GET param always wins (public ordering page with ?tenant_id=X)
    if (!empty($_GET['tenant_id'])) return (int)$_GET['tenant_id'];
    
    // Platform admin (super) sees all → tenant 0 = no filter
    if (!empty($_SESSION['super_admin'])) return 0;
    
    // Tenant admin → their tenant
    if (!empty($_SESSION['tenant_id'])) return (int)$_SESSION['tenant_id'];
    
    // Regular admin session → default tenant 1 (NoodleHaus Main)
    if (!empty($_SESSION['admin'])) return 1;
    
    // Public requests → POST or header
    $tid = (int)($_POST['tenant_id'] ?? $_SERVER['HTTP_X_TENANT_ID'] ?? 1);
    return max(1, $tid);
}

function tenantWhere(string $alias = ''): string {
    $tid = getCurrentTenantId();
    if ($tid === 0) return '1=1'; // super admin sees all
    $col = $alias ? "{$alias}.tenant_id" : 'tenant_id';
    return "{$col} = {$tid}";
}

function tenantId(): int {
    $tid = getCurrentTenantId();
    return $tid === 0 ? 1 : $tid;
}
