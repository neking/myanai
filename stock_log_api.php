<?php
/**
 * stock_log_api.php — Stock change history (list/summary/CSV export)
 *
 * FIXED: this file previously used column names (item_id, action, qty_before,
 * qty_after, qty_change, unit, user_id, user_name, branch_name) that do not
 * exist in the real `stock_log` table (confirmed via SHOW CREATE TABLE — the
 * real columns are menu_item_id, reason, change_qty, new_qty, staff_name,
 * tenant_id, order_id). Every query here was throwing a SQL error on every
 * call, so this endpoint has never actually worked — both callers
 * (tenant.php's stock log tab, admin.php's stock log tab) already expected
 * the CORRECT column names in their rendering JS (change_qty, new_qty,
 * reason, staff_name), they just never got a working response. Rewritten to
 * match the real schema; also wires up the tenant/branch scoping that was
 * defined (branchWhere()) but never actually used anywhere.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
$_REQ_BRANCH = (int)($_GET['branch_id'] ?? 0);
$_REQ_TENANT = (int)($_GET['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);
function branchWhere(string $alias='sl'): string {
    global $_REQ_BRANCH, $_REQ_TENANT;
    $w = [];
    if ($_REQ_BRANCH > 0) $w[] = "$alias.branch_id = $_REQ_BRANCH";
    if ($_REQ_TENANT > 0) $w[] = "$alias.tenant_id = $_REQ_TENANT";
    return $w ? ' AND '.implode(' AND ', $w) : '';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once 'db_connect.php';
$pdo = getPDO();

$action = $_REQUEST['action'] ?? 'list';

function write_stock_log(PDO $pdo, int $tenant_id, int $menu_item_id, string $item_name, string $reason, int $change_qty, int $new_qty, string $note = '', string $staff_name = 'System', int $branch_id = 1, ?int $order_id = null): bool {
    $stmt = $pdo->prepare("INSERT INTO stock_log (tenant_id, branch_id, menu_item_id, item_name, change_qty, new_qty, reason, note, staff_name, order_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
    return $stmt->execute([$tenant_id, $branch_id, $menu_item_id, $item_name, $change_qty, $new_qty, $reason, $note ?: null, $staff_name, $order_id]);
}

if ($action === 'list') {
    $where  = ['1=1' . branchWhere('sl')];
    $params = [];
    if (!empty($_GET['search'])) { $where[] = '(sl.item_name LIKE :s OR sl.reason LIKE :s OR sl.staff_name LIKE :s OR sl.note LIKE :s)'; $params[':s'] = '%'.$_GET['search'].'%'; }
    if (!empty($_GET['action_type'])) { $where[] = 'sl.reason = :rt'; $params[':rt'] = $_GET['action_type']; }
    if (!empty($_GET['date_from'])) { $where[] = 'DATE(sl.created_at) >= :df'; $params[':df'] = $_GET['date_from']; }
    if (!empty($_GET['date_to']))   { $where[] = 'DATE(sl.created_at) <= :dt'; $params[':dt'] = $_GET['date_to']; }
    $limit  = min(500, (int)($_GET['limit'] ?? 100));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $w = implode(' AND ', $where);

    $c = $pdo->prepare("SELECT COUNT(*) FROM stock_log sl WHERE $w");
    $c->execute($params);
    $total = (int)$c->fetchColumn();

    $stmt = $pdo->prepare("SELECT sl.*, DATE_FORMAT(sl.created_at,'%d/%m/%Y %H:%i') AS created_fmt FROM stock_log sl WHERE $w ORDER BY sl.created_at DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode(['success' => true, 'total' => $total, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'summary') {
    $df = $_GET['date_from'] ?? date('Y-m-01');
    $dt = $_GET['date_to'] ?? date('Y-m-d');
    $where = ['DATE(created_at) BETWEEN :df AND :dt' . branchWhere('stock_log')];
    $stmt = $pdo->prepare("SELECT reason AS action, COUNT(*) AS total_entries, SUM(ABS(change_qty)) AS total_qty FROM stock_log WHERE " . implode(' AND ', $where) . " GROUP BY reason ORDER BY total_entries DESC");
    $stmt->execute([':df' => $df, ':dt' => $dt]);
    echo json_encode(['success' => true, 'summary' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'export_csv') {
    $df = $_GET['date_from'] ?? date('Y-m-01');
    $dt = $_GET['date_to'] ?? date('Y-m-d');
    $where = ['DATE(created_at) BETWEEN :df AND :dt' . branchWhere('stock_log')];
    $stmt = $pdo->prepare("SELECT id, item_name, reason, new_qty, change_qty, note, staff_name, branch_id, created_at FROM stock_log WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC");
    $stmt->execute([':df' => $df, ':dt' => $dt]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_log_'.$df.'_to_'.$dt.'.csv"');
    echo "\xEF\xBB\xBF"; $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Item','Reason','New Qty','Change','Note','Staff','Branch','DateTime']);
    foreach ($rows as $r) fputcsv($out, [$r['id'],$r['item_name'],$r['reason'],$r['new_qty'],$r['change_qty'],$r['note'],$r['staff_name'],$r['branch_id'],$r['created_at']]);
    fclose($out);
    exit;
}

if ($action === 'add_log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $tid = (int)($d['tenant_id'] ?? $_REQ_TENANT ?? 1);
    $ok = write_stock_log(
        $pdo, $tid,
        (int)($d['menu_item_id'] ?? 0),
        (string)($d['item_name'] ?? ''),
        (string)($d['reason'] ?? 'manual_adjust'),
        (int)($d['change_qty'] ?? 0),
        (int)($d['new_qty'] ?? 0),
        (string)($d['note'] ?? ''),
        (string)($d['staff_name'] ?? 'System'),
        (int)($d['branch_id'] ?? 1),
        isset($d['order_id']) ? (int)$d['order_id'] : null
    );
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
