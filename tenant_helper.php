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

/**
 * Shared tenant-authorization check for write/list/delete actions.
 * Unlike getCurrentTenantId(), this does NOT trust a client-supplied
 * tenant_id over the session — it uses the session as the source of truth
 * and only lets a client-supplied tenant_id through if it matches.
 *
 * - Super-admin session ($_SESSION['admin']): may act on the tenant_id supplied
 *   in the request (0 = no filter / all tenants), since super-admins are trusted
 *   to act across tenants.
 * - Tenant-admin session ($_SESSION['tenant_admin'] + $_SESSION['tenant_id']):
 *   may ONLY act on their own tenant. If the request also supplies a tenant_id
 *   and it doesn't match the session's, the request is rejected — this is what
 *   stops one tenant from reading/writing another tenant's data by changing a
 *   URL or POST parameter.
 * - No valid session: rejected.
 *
 * Never returns on failure — sends a 401/403 JSON response and exits, the same
 * way this codebase's per-file fail()/jErr() helpers do.
 */
function requireTenantAccess(int $requestedTid = 0): int {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!empty($_SESSION['admin'])) {
        return $requestedTid; // super-admin: trusted to act across tenants
    }
    if (!empty($_SESSION['tenant_admin']) && !empty($_SESSION['tenant_id'])) {
        $sessionTid = (int)$_SESSION['tenant_id'];
        if ($requestedTid > 0 && $requestedTid !== $sessionTid) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Forbidden: tenant mismatch']);
            exit;
        }
        return $sessionTid;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}
