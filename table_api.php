<?php
/**
 * table_api.php — Table/Dine-in API
 * GET  ?action=status&table=T01     → table current order status (public — QR scan)
 * GET  ?action=list                 → all tables + current orders (tenant/admin)
 * POST ?action=request_bill         → customer requests bill (public — QR scan)
 * POST ?action=close_table          → admin closes table (mark paid)
 * POST ?action=open_table           → admin opens new session for table
 * POST ?action=add_table            → add/update a table
 * POST ?action=remove_table         → deactivate a table
 *
 * Admin/tenant actions are tenant-scoped via requireTenantAccess() (tenant_helper.php):
 * a tenant session may only act on its own tables/orders. Previously these checked
 * $_SESSION['admin'] only, which meant ordinary tenant owners (not the platform
 * super-admin) could not manage their own tables at all — that's fixed here too.
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$_BID = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
$_REQ_TENANT_PARAM = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);

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

/* ── GET: table status (public — QR scan, no login) ── */
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

/* ── GET: list all tables + current orders (tenant/admin) ── */
if ($action === 'list') {
    $tid = requireTenantAccess((int)($b['tenant_id'] ?? 0) ?: $_REQ_TENANT_PARAM);
    $tables = db()->prepare("SELECT * FROM restaurant_tables WHERE is_active=1" . ($_BID > 0 ? " AND branch_id=?" : "") . " AND tenant_id=? ORDER BY table_code");
    $params = $_BID > 0 ? [$_BID, $tid] : [$tid];
    $tables->execute($params);
    $tables = $tables->fetchAll();
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
              AND o.table_status IN ('open','billed') AND o.deleted_at IS NULL AND o.tenant_id=:t
            GROUP BY o.id ORDER BY o.id DESC LIMIT 1
        ");
        $ord->execute([':c'=>$t['table_code'], ':t'=>$tid]);
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

/* ── POST: request bill (public — QR scan, no login) ── */
if ($action === 'request_bill') {
    $orderId = (int)($b['order_id'] ?? 0);
    if (!$orderId) jErr('No order_id');
    db()->prepare("UPDATE orders SET table_status='billed' WHERE id=:id AND order_type='dine_in'")
        ->execute([':id'=>$orderId]);
    jOk(['msg'=>'Bill requested']);
}

/* ── POST: close table / mark paid (tenant/admin) ── */
if ($action === 'close_table') {
    $tid = requireTenantAccess((int)($b['tenant_id'] ?? 0) ?: $_REQ_TENANT_PARAM);
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
    $upd = db()->prepare("UPDATE orders SET table_status='paid', status='delivered', payment_status='paid', payment_method=:pay WHERE id=:id AND tenant_id=:t");
    $upd->execute([':id'=>$orderId, ':t'=>$tid, ':pay'=>$payMethod]);
    // Confirmed live: without this check, the endpoint always returned
    // "Table closed" success even when the WHERE clause matched zero rows
    // (e.g. a different tenant's session naming another tenant's order_id) —
    // misleading, since nothing was actually closed/paid.
    if ($upd->rowCount() === 0) { jErr('Order not found or not yours'); }
    // Mark KDS as served — CRITICAL FIX: this previously had NO tenant check
    // at all (WHERE order_id=:id only). Confirmed live exploitable: a
    // different tenant's session could mark another tenant's real,
    // still-unpaid kitchen order as 'served', making that tenant's kitchen
    // staff think the order was done and stop paying attention to it, even
    // though the customer never received it and payment was never taken.
    db()->prepare("UPDATE kds_queue SET status='served' WHERE order_id=:id AND tenant_id=:t AND status!='served'")
        ->execute([':id'=>$orderId, ':t'=>$tid]);
    jOk(['msg'=>'Table closed']);
}

/* ── POST: open new table session (tenant/admin) ── */
if ($action === 'open_table') {
    $tid = requireTenantAccess((int)($b['tenant_id'] ?? 0) ?: $_REQ_TENANT_PARAM);
    $code = strtoupper(trim($b['table_code'] ?? ''));
    if (!$code) jErr('No table_code');
    // Close any existing open orders for this table
    db()->prepare("UPDATE orders SET table_status='paid', status='delivered' WHERE table_id=:c AND table_status='open' AND deleted_at IS NULL AND tenant_id=:t")
        ->execute([':c'=>$code, ':t'=>$tid]);
    jOk(['msg'=>'Table reset, ready for new orders']);
}

/* ── POST: add table to restaurant_tables (tenant/admin) ── */
if ($action === 'add_table' || $action === 'add') { // 'add' alias kept for tenant.php compatibility
    $tid = requireTenantAccess((int)($b['tenant_id'] ?? 0) ?: $_REQ_TENANT_PARAM);
    $code  = strtoupper(trim($b['code'] ?? $b['table_code'] ?? ''));
    $label = trim($b['label'] ?? '');
    $seats = (int)($b['seats'] ?? 4);
    if (!$code) jErr('No code');
    db()->prepare("INSERT INTO restaurant_tables (table_code,label,seats,branch_id,tenant_id) VALUES (:c,:l,:s,:b,:t) ON DUPLICATE KEY UPDATE label=:l2,seats=:s2,is_active=1")
        ->execute([':c'=>$code,':l'=>$label,':s'=>$seats,':b'=>(int)($b['branch_id']??$_BID?:1),':t'=>$tid,':l2'=>$label,':s2'=>$seats]);
    jOk(['msg'=>'Table saved']);
}

/* ── POST: remove table (tenant/admin) ── */
if ($action === 'remove_table') {
    $tid = requireTenantAccess((int)($b['tenant_id'] ?? 0) ?: $_REQ_TENANT_PARAM);
    $code = strtoupper(trim($b['table_code'] ?? ''));
    if (!$code) jErr('No table_code');
    db()->prepare("UPDATE restaurant_tables SET is_active=0 WHERE table_code=:c AND tenant_id=:t")
        ->execute([':c'=>$code, ':t'=>$tid]);
    jOk(['msg'=>'Table removed']);
}

jErr('Unknown action');
