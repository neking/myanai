<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/tenant_helper.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = getPDO();
$action = trim($_GET['action'] ?? '');

function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }

// Every action below resolves its own tenant via requireTenantAccess() —
// this is the single source of truth for "which tenant can this request touch,"
// it does NOT trust a client-supplied tenant_id over the session (see tenant_helper.php).
$_REQ_TENANT_PARAM = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);

/* LIST expenses */
if ($action === 'list') {
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);
    $month = trim($_GET['month'] ?? date('Y-m'));
    $cat   = trim($_GET['category'] ?? '');
    $where = ["DATE_FORMAT(e.expense_date,'%Y-%m') = ?", 'e.tenant_id = ?'];
    $params = [$month, $tid];
    if ($cat) { $where[] = 'e.category = ?'; $params[] = $cat; }
    $w = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT e.*, s.name AS supplier_name FROM expenses e LEFT JOIN suppliers s ON s.id=e.supplier_id WHERE $w ORDER BY e.expense_date DESC, e.id DESC");
    $stmt->execute($params);
    ok(['expenses' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'month' => $month]);
}

/* SUMMARY (P&L) */
if ($action === 'summary') {
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);
    $month = trim($_GET['month'] ?? date('Y-m'));
    // Revenue — scoped to this tenant only
    $rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS orders FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND tenant_id=? AND deleted_at IS NULL");
    $rev->execute([$month, $tid]);
    $r = $rev->fetch(PDO::FETCH_ASSOC);
    // Expenses by category — scoped to this tenant only
    $exp = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? AND tenant_id=? GROUP BY category ORDER BY total DESC");
    $exp->execute([$month, $tid]);
    $cats = $exp->fetchAll(PDO::FETCH_ASSOC);
    $totalExp = array_sum(array_column($cats, 'total'));
    ok(['month'=>$month, 'revenue'=>(int)$r['revenue'], 'orders'=>(int)$r['orders'], 'total_expense'=>$totalExp, 'profit'=>(int)$r['revenue']-$totalExp, 'by_category'=>$cats]);
}

/* CREATE expense */
if (($action === 'create' || $action === 'add') && $_SERVER['REQUEST_METHOD'] === 'POST') { // ★ 'add' alias for tenant.php compatibility
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $cat = trim($d['category'] ?? 'other');
    $amount = (int)($d['amount'] ?? 0);
    $desc = trim($d['description'] ?? '');
    $date = trim($d['expense_date'] ?? date('Y-m-d'));
    $suppId = !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null;
    $ref = trim($d['receipt_ref'] ?? '') ?: null;
    $by = trim($d['recorded_by'] ?? 'Admin');
    if ($amount <= 0) fail('Amount required');
    $pdo->prepare("INSERT INTO expenses (tenant_id,supplier_id,category,amount,description,receipt_ref,expense_date,recorded_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tid,$suppId,$cat,$amount,$desc?:null,$ref,$date,$by]);
    ok(['expense_id' => (int)$pdo->lastInsertId()]);
}

/* DELETE expense */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("DELETE FROM expenses WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
    ok();
}

/* SUPPLIERS
   NOTE: the `suppliers` table's schema could not be confirmed during this audit
   (no other file in the codebase references it, and DB access wasn't available
   to check for a tenant_id column). Left as a shared/global list for now rather
   than guessing at a column that might not exist and breaking this feature.
   Flagged for a schema check before scoping this per-tenant. */
if ($action === 'suppliers') {
    requireTenantAccess($_REQ_TENANT_PARAM);
    $rows = $pdo->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    ok(['suppliers' => $rows]);
}

if ($action === 'supplier_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireTenantAccess($_REQ_TENANT_PARAM);
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    if (!$name) fail('Name required');
    $pdo->prepare("INSERT INTO suppliers (name,phone,category) VALUES (?,?,?)")
        ->execute([$name, trim($d['phone']??'')?:null, trim($d['category']??'')?:null]);
    ok(['supplier_id' => (int)$pdo->lastInsertId()]);
}

fail('Unknown action');
