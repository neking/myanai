<?php
/**
 * table_api.php — Table/Dine-in API
 * GET  ?action=status&table=T01     → table current order status
 * GET  ?action=list                 → all tables + current orders (admin)
 * POST ?action=add_items            → add items to existing dine-in order
 * POST ?action=request_bill         → customer requests bill
 * POST ?action=close_table          → admin closes table (mark paid)
 * POST ?action=open_table           → admin opens new session for table
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

$_BID = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
$_TID = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
$_BWHERE = $_BID > 0 ? " AND t.branch_id = $_BID" : ($_TID > 0 ? " AND t.tenant_id = $_TID" : "");
$pdo = getPDO();

session_start();

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

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }



function db(): PDO {
    static $pdo = null;
    if (!$pdo) $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $pdo;
}
function jOk(mixed $data=[]): void  { echo json_encode(['ok'=>true]+$data,JSON_UNESCAPED_UNICODE); exit; }
function jErr(string $msg, int $c=400): void { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$msg]); exit; }

$action = $_GET['action'] ?? '';
$b = $_SERVER['REQUEST_METHOD']==='POST' ? (json_decode(file_get_contents('php://input'),true)??[]) : [];

/* ── GET: table status (public) ── */
if ($action === 'status') {
    $code = strtoupper(trim($_GET['table'] ?? ''));
    if (!$code) jErr('No table code');

    // Table exists?
    $tbl = db()->prepare("SELECT * FROM restaurant_tables WHERE table_code=:c AND is_active=1");
    $tbl->execute([':c'=>$code]);
    $t = $tbl->fetch();
    if (!$t) jErr('Table not found', 404);

    // Active open order for this table
    $ord = db()->prepare("
        SELECT o.id, o.status, o.table_status, o.created_at,
               SUM(oi.qty) AS item_count, SUM(oi.subtotal) AS subtotal
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.table_id = :c
          AND o.order_type = 'dine_in'
          AND o.table_status IN ('open','billed')
          AND o.deleted_at IS NULL
        GROUP BY o.id
        ORDER BY o.id DESC LIMIT 1
    ");
    $ord->execute([':c'=>$code]);
    $activeOrder = $ord->fetch();

    jOk([
        'table'        => $t,
        'active_order' => $activeOrder ?: null,
    ]);
}

/* ── GET: list all tables + current orders (admin only) ── */
if ($action === 'list') {
    if (empty($_SESSION['admin'])) jErr('Not logged in', 401);
    $tables = db()->query("SELECT * FROM restaurant_tables WHERE is_active=1" . ($_BID > 0 ? " AND branch_id=$_BID" : ($_TID > 0 ? " AND tenant_id=$_TID" : "")) . " ORDER BY table_code")->fetchAll();
    $result = [];
    foreach ($tables as $t) {
        // Generate QR URL for this table
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $qrUrl = $baseUrl . '/order.php?table=' . urlencode($t['table_code']) . '&branch=' . $t['branch_id'];
        $t['qr_url'] = $qrUrl;
        $t['qr_img'] = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrUrl);
        $ord = db()->prepare("
            SELECT o.id, o.table_status, o.created_at, o.total_amount,
                   COUNT(oi.id) AS line_count,
                   SUM(oi.qty) AS item_count,
                   SUM(oi.subtotal) AS subtotal,
                   GROUP_CONCAT(oi.item_name,'x',oi.qty SEPARATOR ', ') AS items_summary
            FROM orders o JOIN order_items oi ON oi.order_id=o.id
            WHERE o.table_id COLLATE utf8mb4_unicode_ci=:c AND o.order_type='dine_in'
              AND o.table_status IN ('open','billed') AND o.deleted_at IS NULL
            GROUP BY o.id ORDER BY o.id DESC LIMIT 1
        ");
        $ord->execute([':c'=>$t['table_code']]);
        $active = $ord->fetch(PDO::FETCH_ASSOC);
        // Flat format — merge table + order fields
        $row = [
            'id'           => $t['id'],
            'table_code'   => $t['table_code'],
            'label'        => $t['label'],
            'seats'        => $t['seats'],
            'is_active'    => $t['is_active'],
            'order_id'     => $active ? $active['id'] : null,
            'order_status' => $active ? $active['table_status'] : null,
            'table_status' => $active ? $active['table_status'] : 'empty',
            'total_amount' => $active ? $active['total_amount'] : 0,
            'item_count'   => $active ? $active['item_count'] : 0,
            'subtotal'     => $active ? $active['subtotal'] : 0,
            'items_summary'=> $active ? $active['items_summary'] : '',
        ];
        // Add QR + branch info to result
    $row['qr_url']   = $t['qr_url']  ?? '';
    $row['qr_img']   = $t['qr_img']  ?? '';
    $row['branch_id']= $t['branch_id'] ?? 0;
    $row['tenant_id']= $t['tenant_id'] ?? 1;
    $row['seats']    = $t['seats']    ?? 4;
    $row['label']    = $t['label']    ?? $t['table_code'];
    $result[] = $row;
    }
    jOk(['tables'=>$result]);
}

/* ── POST: request bill (customer) ── */
if ($action === 'request_bill') {
    $orderId = (int)($b['order_id'] ?? 0);
    if (!$orderId) jErr('No order_id');
    db()->prepare("UPDATE orders SET table_status='billed' WHERE id=:id AND order_type='dine_in'")
        ->execute([':id'=>$orderId]);
    jOk(['msg'=>'Bill requested']);
}

/* ── POST: close table / mark paid (admin) ── */
if ($action === 'close_table') {
    if (empty($_SESSION['admin'])) jErr('Not logged in', 401);
    $orderId     = (int)($b['order_id'] ?? 0);
    if (!$orderId) jErr('No order_id');
    // Split bill support
    $payMethod   = trim($b['payment_method'] ?? 'cash');
    $splitMethod = trim($b['split_method'] ?? '');   // e.g. 'kpay'
    $splitAmount = (float)($b['split_amount'] ?? 0); // amount paid by split_method
    // Build payment_method string
    if ($splitMethod && $splitAmount > 0) {
        $payMethod = $payMethod . '+' . $splitMethod . ':' . $splitAmount;
    }
    db()->prepare("UPDATE orders SET table_status='paid', status='delivered', payment_status='paid', payment_method=:pay WHERE id=:id")
        ->execute([':id'=>$orderId]);
    // Mark KDS as served
    db()->prepare("UPDATE kds_queue SET status='served' WHERE order_id=:id AND status!='served'")
        ->execute([':id'=>$orderId]);
    jOk(['msg'=>'Table closed']);
}

/* ── POST: open new table session (admin) ── */
if ($action === 'open_table') {
    if (empty($_SESSION['admin'])) jErr('Not logged in', 401);
    $code = strtoupper(trim($b['table_code'] ?? ''));
    if (!$code) jErr('No table_code');
    // Close any existing open orders for this table
    db()->prepare("UPDATE orders SET table_status='paid', status='delivered' WHERE table_id=:c AND table_status='open' AND deleted_at IS NULL")
        ->execute([':c'=>$code]);
    jOk(['msg'=>'Table reset, ready for new orders']);
}

/* ── POST: add table to restaurant_tables (admin) ── */
if ($action === 'add_table') {
    if (empty($_SESSION['admin'])) jErr('Not logged in', 401);
    $code  = strtoupper(trim($b['code']  ?? ''));
    $label = trim($b['label'] ?? '');
    $seats = (int)($b['seats'] ?? 4);
    if (!$code) jErr('No code');
    db()->prepare("INSERT INTO restaurant_tables (table_code,label,seats,branch_id,tenant_id) VALUES (:c,:l,:s,:b,:t) ON DUPLICATE KEY UPDATE label=:l2,seats=:s2,is_active=1")
        ->execute([':c'=>$code,':l'=>$label,':s'=>$seats,':b'=>(int)($b['branch_id']??$_BID??1),':t'=>(int)($b['tenant_id']??$_TID??1),':l2'=>$label,':s2'=>$seats]);
    jOk(['msg'=>'Table saved']);
}

/* ── POST: remove table (admin) ── */
if ($action === 'remove_table') {
    if (empty($_SESSION['admin'])) jErr('Not logged in', 401);
    $code = strtoupper(trim($b['table_code'] ?? ''));
    db()->prepare("UPDATE restaurant_tables SET is_active=0 WHERE table_code=:c")->execute([':c'=>$code]);
    jOk(['msg'=>'Table removed']);
}

jErr('Unknown action');
