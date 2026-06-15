<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = getPDO();
$action = trim($_GET['action'] ?? '');

function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function requireAdmin(): void { if(session_status()===PHP_SESSION_NONE)session_start();

// ── Branch/Tenant context from request ──────────────────────────────
$_REQ_BRANCH = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
$_REQ_TENANT = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
function branchWhere(string $alias='o'): string {
    global $_REQ_BRANCH, $_REQ_TENANT;
    $w = [];
    if($_REQ_BRANCH > 0) $w[] = "$alias.branch_id = $_REQ_BRANCH";
    if($_REQ_TENANT > 0) $w[] = "$alias.tenant_id = $_REQ_TENANT";
    return $w ? ' AND '.implode(' AND ',$w) : '';
}
// ─────────────────────────────────────────────────────────────────────
 if(empty($_SESSION['admin']))fail('Unauthorized',401); }

/* LIST expenses */
if ($action === 'list') {
    requireAdmin();
    $month = trim($_GET['month'] ?? date('Y-m'));
    $cat   = trim($_GET['category'] ?? '');
    $where = ["DATE_FORMAT(e.expense_date,'%Y-%m') = ?"];
    $params = [$month];
    if ($cat) { $where[] = 'e.category = ?'; $params[] = $cat; }
    $w = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT e.*, s.name AS supplier_name FROM expenses e LEFT JOIN suppliers s ON s.id=e.supplier_id WHERE $w ORDER BY e.expense_date DESC, e.id DESC");
    $stmt->execute($params);
    ok(['expenses' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'month' => $month]);
}

/* SUMMARY (P&L) */
if ($action === 'summary') {
    requireAdmin();
    $month = trim($_GET['month'] ?? date('Y-m'));
    // Revenue
    $rev = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS orders FROM orders WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND deleted_at IS NULL");
    $rev->execute([$month]);
    $r = $rev->fetch(PDO::FETCH_ASSOC);
    // Expenses by category
    $exp = $pdo->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY category ORDER BY total DESC");
    $exp->execute([$month]);
    $cats = $exp->fetchAll(PDO::FETCH_ASSOC);
    $totalExp = array_sum(array_column($cats, 'total'));
    ok(['month'=>$month, 'revenue'=>(int)$r['revenue'], 'orders'=>(int)$r['orders'], 'total_expense'=>$totalExp, 'profit'=>(int)$r['revenue']-$totalExp, 'by_category'=>$cats]);
}

/* CREATE expense */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $cat = trim($d['category'] ?? 'other');
    $amount = (int)($d['amount'] ?? 0);
    $desc = trim($d['description'] ?? '');
    $date = trim($d['expense_date'] ?? date('Y-m-d'));
    $suppId = !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null;
    $ref = trim($d['receipt_ref'] ?? '') ?: null;
    $by = trim($d['recorded_by'] ?? 'Admin');
    if ($amount <= 0) fail('Amount required');
    $pdo->prepare("INSERT INTO expenses (supplier_id,category,amount,description,receipt_ref,expense_date,recorded_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$suppId,$cat,$amount,$desc?:null,$ref,$date,$by]);
    ok(['expense_id' => (int)$pdo->lastInsertId()]);
}

/* DELETE expense */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
    ok();
}

/* SUPPLIERS */
if ($action === 'suppliers') {
    $rows = $pdo->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    ok(['suppliers' => $rows]);
}

if ($action === 'supplier_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    if (!$name) fail('Name required');
    $pdo->prepare("INSERT INTO suppliers (name,phone,category) VALUES (?,?,?)")
        ->execute([$name, trim($d['phone']??'')?:null, trim($d['category']??'')?:null]);
    ok(['supplier_id' => (int)$pdo->lastInsertId()]);
}

fail('Unknown action');
