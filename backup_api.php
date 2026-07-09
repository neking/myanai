<?php
/**
 * backup_api.php — Per-tenant JSON backup + restore
 * GET  ?action=export&tenant_id=N  → download JSON
 * POST ?action=import              → restore from JSON (admin only)
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
session_start();
$pdo = getPDO();

header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

/* ── Auth ── */
$isSuperAdmin = !empty($_SESSION['admin']);
$isTenant     = !empty($_SESSION['tenant_admin']);
$sessionTid   = (int)($_SESSION['tenant_id'] ?? 0);

/* ── EXPORT ── */
if ($action === 'export') {
    $tid = (int)($_GET['tenant_id'] ?? $sessionTid);

    // Auth check: super-admin can export any, tenant can only export own
    if (!$isSuperAdmin && (!$isTenant || $sessionTid !== $tid)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
    }
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

    // Get tenant info
    $tenantRow = $pdo->prepare("SELECT id,name,slug,plan,owner_email,settings FROM tenants WHERE id=?");
    $tenantRow->execute([$tid]);
    $tenant = $tenantRow->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) { echo json_encode(['ok'=>false,'msg'=>'Tenant not found']); exit; }

    // Collect all data
    $backup = [
        'version'    => '1.0',
        'exported_at'=> date('Y-m-d H:i:s'),
        'tenant'     => [
            'id'          => $tenant['id'],
            'name'        => $tenant['name'],
            'slug'        => $tenant['slug'],
            'plan'        => $tenant['plan'],
            'owner_email' => $tenant['owner_email'],
        ],
        'data'       => [],
    ];

    // Helper to query with tenant_id
    $fetch = function(string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $backup['data']['branches']     = $fetch("SELECT * FROM branches WHERE tenant_id=?", [$tid]);
    $backup['data']['menu_items']   = $fetch("SELECT * FROM menu_items WHERE tenant_id=?", [$tid]);
    $backup['data']['staff']        = $fetch("SELECT s.* FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=?", [$tid]);
    $backup['data']['tables']       = $fetch("SELECT * FROM restaurant_tables WHERE tenant_id=?", [$tid]);
    $backup['data']['orders']       = $fetch("SELECT * FROM orders WHERE tenant_id=? ORDER BY id DESC LIMIT 500", [$tid]);
    $backup['data']['order_items']  = $fetch("SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.tenant_id=? ORDER BY oi.id DESC LIMIT 2000", [$tid]);
    $backup['data']['customers']= $fetch("SELECT * FROM customers WHERE phone IN (SELECT DISTINCT customer_phone FROM orders WHERE tenant_id=? AND customer_phone IS NOT NULL) LIMIT 1000", [$tid]);
    $backup['data']['expenses']     = $fetch("SELECT * FROM expenses WHERE tenant_id=? ORDER BY id DESC LIMIT 500", [$tid]);

    // Counts summary
    $backup['summary'] = array_map('count', $backup['data']);
    $backup['summary']['total_records'] = array_sum($backup['summary']);

    // Set download headers
    $filename = 'myanai-backup-' . ($tenant['slug'] ?: 'tenant') . '-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Backup-Tenant: ' . $tenant['name']);
    header('X-Backup-Records: ' . $backup['summary']['total_records']);

    echo json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ── BACKUP INFO (summary only, no download) ── */
if ($action === 'info' || $action === 'list') {
    $tid = (int)($_GET['tenant_id'] ?? $sessionTid);
    if (!$isSuperAdmin && (!$isTenant || $sessionTid !== $tid)) {
        http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
    }
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

    $counts = [];
    $tables = [
        'branches'      => "SELECT COUNT(*) FROM branches WHERE tenant_id=?",
        'menu_items'    => "SELECT COUNT(*) FROM menu_items WHERE tenant_id=?",
        'staff'         => "SELECT COUNT(*) FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=?",
        'tables'        => "SELECT COUNT(*) FROM restaurant_tables WHERE tenant_id=?",
        'orders'        => "SELECT COUNT(*) FROM orders WHERE tenant_id=?",
        'crm_customers' => "SELECT COUNT(*) FROM crm_customers WHERE tenant_id=?",
        'expenses'      => "SELECT COUNT(*) FROM expenses WHERE tenant_id=?",
    ];
    foreach ($tables as $name => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tid]);
            $counts[$name] = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            $counts[$name] = 0;
        }
    }
    $counts['total'] = array_sum($counts);
    echo json_encode(['ok'=>true,'counts'=>$counts]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
