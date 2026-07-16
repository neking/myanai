<?php
/**
 * waiter_api.php — Waiter/POS app backend
 *
 * IMPORTANT: this whole file previously had almost no tenant/branch scoping
 * anywhere except an optional filter on login. waiter.html never sent
 * branch_id at all, so 'tables' showed every tenant's tables/orders mixed
 * together, 'menu' had a SQL bug (used $pdo->query() with an unbound :tid
 * placeholder, which cannot work — this action was completely broken and
 * would either error or return zero rows every time), and 'order' created
 * new orders WITHOUT setting tenant_id at all (same class of bug fixed
 * earlier this session in order_handler.php). Fixed by requiring branch_id
 * (already returned by 'login' and now sent by waiter.html on every
 * subsequent call) and resolving/verifying tenant_id from it everywhere.
 *
 * NOTE ON PIN LOGIN: `staff.pin` has no tenant/branch-scoped uniqueness in
 * the database — 'add_staff' enforces global PIN uniqueness across ALL
 * tenants on this platform. That's intentional here, not an oversight: since
 * waiter.html has no URL-based tenant context (no ?t=slug like the customer
 * ordering page), a PIN is the ONLY way this app identifies which
 * tenant/branch a staff member belongs to. If PINs were scoped per-branch
 * instead, two different tenants' staff could pick the same PIN and there
 * would be no way for 'login' to know which one is logging in. Do not relax
 * the global-uniqueness check in 'add_staff' without also adding a tenant
 * selector to the login screen.
 */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/pin_ratelimit.php';
header('Content-Type: application/json');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$pdo    = getPDO();
$action = $_GET['action'] ?? '';

/** Resolve a branch_id to its tenant_id, or null if the branch doesn't exist. */
function resolveTenantFromBranch(PDO $pdo, int $branchId): ?int {
    if ($branchId <= 0) return null;
    $row = $pdo->prepare("SELECT tenant_id FROM branches WHERE id = ?");
    $row->execute([$branchId]);
    $tid = $row->fetchColumn();
    return $tid !== false ? (int)$tid : null;
}

// ── PIN login ──
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // RATE LIMITING FIX: this login previously had NO rate limiting at all —
    // confirmed live, 8 wrong PINs in a row with zero lockout/delay. PINs
    // are only 4-6 digits, making this brute-forceable. pin_ratelimit.php
    // already existed with well-built lockout logic but was never wired up
    // anywhere in the codebase. Its function signature assumes a known
    // staff_id up front (like a username+password flow), which doesn't fit
    // here since PIN alone determines identity — an attacker guessing PINs
    // blindly doesn't know which staff_id each guess might match. Using
    // staff_id=0 as a shared bucket for "no match yet" so IP-based brute
    // force is still caught regardless of which staff account is targeted.
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rl = checkPINRateLimit($pdo, 0, $ip);
    if ($rl['limited']) {
        http_response_code(429);
        echo json_encode(['ok'=>false,'msg'=>'ကြိုးစားမှု များနေသည်။ '.ceil($rl['retry_after_seconds']/60).' မိနစ်အကြာမှ ထပ်ကြိုးစားပါ']);
        exit;
    }

    $d   = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim($d['pin'] ?? '');
    if (!$pin) { echo json_encode(['ok'=>false,'msg'=>'PIN မထည့်ရသေး']); exit; }
    $bid  = (int)($d['branch_id'] ?? 0);
    $sql  = $bid
        ? "SELECT id,name,role,branch_id FROM staff WHERE pin=? AND branch_id=? AND is_active=1"
        : "SELECT id,name,role,branch_id FROM staff WHERE pin=? AND is_active=1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bid ? [$pin, $bid] : [$pin]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        recordPINAttempt($pdo, 0, $ip, false);
        echo json_encode(['ok'=>false,'msg'=>'PIN မှားနေသည်']);
        exit;
    }
    recordPINAttempt($pdo, (int)$staff['id'], $ip, true);
    echo json_encode(['ok'=>true,'staff'=>$staff]);
    exit;
}

// ── Table list ──
if ($action === 'tables') {
    $bid = (int)($_GET['branch_id'] ?? 0);
    if (!$bid) { echo json_encode(['ok'=>false,'msg'=>'branch_id required']); exit; }
    $tables = $pdo->prepare("
        SELECT t.*,
               o.id as order_id, o.status as order_status,
               COUNT(oi.id) as item_count,
               COALESCE(SUM(oi.qty * oi.unit_price),0) as subtotal
        FROM restaurant_tables t
        LEFT JOIN orders o ON o.table_id COLLATE utf8mb4_unicode_ci = t.table_code COLLATE utf8mb4_unicode_ci
            AND o.branch_id = t.branch_id
            AND o.order_type='dine_in'
            AND o.deleted_at IS NULL
            AND o.status NOT IN ('delivered','cancelled')
        LEFT JOIN order_items oi ON oi.order_id=o.id
        WHERE t.branch_id = ?
        GROUP BY t.id, o.id
        ORDER BY t.table_code
    ");
    $tables->execute([$bid]);
    echo json_encode(['ok'=>true,'tables'=>$tables->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Menu items ──
if ($action === 'menu') {
    $bid = (int)($_GET['branch_id'] ?? 0);
    if (!$bid) { echo json_encode(['ok'=>false,'msg'=>'branch_id required']); exit; }
    $tid = resolveTenantFromBranch($pdo, $bid);
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'Branch not found']); exit; }
    // Previously this used $pdo->query() with an unbound ':tid' placeholder,
    // which is not valid — query() doesn't support parameter binding at all.
    // This action has been broken (error or zero rows) until this fix.
    $stmt = $pdo->prepare("
        SELECT id, name, category, price, stock_qty, emoji, image_path
        FROM menu_items WHERE is_active=1 AND stock_qty>0 AND tenant_id=?
        ORDER BY category, sort_order, name
    ");
    $stmt->execute([$tid]);
    echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Place order (waiter) ──
if ($action === 'order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d       = json_decode(file_get_contents('php://input'), true) ?? [];
    $table   = trim($d['table_code'] ?? '');
    $staffId = (int)($d['staff_id'] ?? 0);
    $bid     = (int)($d['branch_id'] ?? 0);
    $items   = $d['items'] ?? [];
    $note    = trim($d['note'] ?? '');

    if (!$table || !$staffId || !$bid || empty($items)) {
        echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit;
    }
    $tid = resolveTenantFromBranch($pdo, $bid);
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'Branch not found']); exit; }

    // Staff verify — scoped to this branch, so a PIN valid at one branch
    // can't place orders against a different branch's table/menu.
    $s = $pdo->prepare("SELECT name FROM staff WHERE id=? AND branch_id=? AND is_active=1");
    $s->execute([$staffId, $bid]);
    $staffName = $s->fetchColumn();
    if (!$staffName) { echo json_encode(['ok'=>false,'msg'=>'Staff not found']); exit; }

    // Table verify — scoped to this branch
    $t = $pdo->prepare("SELECT id FROM restaurant_tables WHERE table_code=? AND branch_id=?");
    $t->execute([$table, $bid]);
    if (!$t->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'Table not found']); exit; }

    // Calc total — items must belong to this tenant's menu
    $subtotal = 0;
    $itemRows = [];
    foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $qty    = max(1,(int)($item['qty'] ?? 1));
        $mi = $pdo->prepare("SELECT name,price,stock_qty FROM menu_items WHERE id=? AND tenant_id=? AND is_active=1");
        $mi->execute([$itemId, $tid]);
        $mi = $mi->fetch(PDO::FETCH_ASSOC);
        if (!$mi) continue;
        if ($mi['stock_qty'] < $qty) {
            echo json_encode(['ok'=>false,'msg'=>$mi['name'].' stock မလုံ့လောက်']); exit;
        }
        $subtotal += $mi['price'] * $qty;
        $itemRows[] = ['id'=>$itemId,'name'=>$mi['name'],'price'=>$mi['price'],'qty'=>$qty];
    }
    if (empty($itemRows)) { echo json_encode(['ok'=>false,'msg'=>'Valid items မရှိ']); exit; }

    try {
        $pdo->beginTransaction();

        // Check existing open order for table — scoped to this branch, since
        // table_code alone can collide across tenants/branches.
        $ex = $pdo->prepare("SELECT id FROM orders WHERE table_id=? AND branch_id=? AND order_type='dine_in' AND deleted_at IS NULL AND status NOT IN ('delivered','cancelled') LIMIT 1");
        $ex->execute([$table, $bid]);
        $existingOrderId = $ex->fetchColumn();

        if ($existingOrderId) {
            // Append to existing order
            $orderId   = $existingOrderId;
            $isAppend  = true;
        } else {
            // New order — tenant_id/branch_id set explicitly (previously
            // omitted entirely, so every waiter-placed order fell back to
            // the tenant_id column default, same bug class fixed earlier
            // this session for the customer ordering page).
            $pdo->prepare("INSERT INTO orders (tenant_id,branch_id,customer_name,customer_phone,delivery_address,township,special_notes,payment_method,subtotal,delivery_fee,total_amount,status,order_type,table_id) VALUES (?,?,?,?,?,?,?,'cash',?,0,?,  'pending','dine_in',?)")
                ->execute([$tid, $bid, $staffName.' (Waiter)','','','', $note, $subtotal, $subtotal, $table]);
            $orderId  = (int)$pdo->lastInsertId();
            $isAppend = false;
        }

        // Insert items
        $itemStmt  = $pdo->prepare("INSERT INTO order_items (order_id,menu_item_id,item_name,unit_price,qty,subtotal) VALUES (?,?,?,?,?,?)");
        $stockStmt = $pdo->prepare("UPDATE menu_items SET stock_qty=stock_qty-? WHERE id=? AND stock_qty>=?");
        foreach ($itemRows as $row) {
            $itemStmt->execute([$orderId,$row['id'],$row['name'],$row['price'],$row['qty'],$row['price']*$row['qty']]);
            $stockStmt->execute([$row['qty'],$row['id'],$row['qty']]);
        }

        // Update total if append
        if ($isAppend) {
            $pdo->prepare("UPDATE orders SET total_amount=total_amount+?, subtotal=subtotal+? WHERE id=?")
                ->execute([$subtotal,$subtotal,$orderId]);
        }

        // KDS push — tenant_id/branch_id set explicitly (same reasoning as above)
        $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,tenant_id,branch_id,pushed_at) VALUES (?,'kitchen','pending',?,?,NOW())")
            ->execute([$orderId, $tid, $bid]);

        $pdo->commit();

        $ref = 'NH-'.str_pad($orderId,6,'0',STR_PAD_LEFT);
        echo json_encode(['ok'=>true,'order_id'=>$ref,'db_id'=>$orderId,'is_append'=>$isAppend,'table'=>$table]);

    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── Table current order ──
if ($action === 'table_order') {
    $table = trim($_GET['table'] ?? '');
    $bid   = (int)($_GET['branch_id'] ?? 0);
    if (!$table || !$bid) { echo json_encode(['ok'=>false,'msg'=>'No table']); exit; }
    $order = $pdo->prepare("
        SELECT o.id, o.status, o.total_amount, o.created_at,
               GROUP_CONCAT(oi.qty,'x ',oi.item_name ORDER BY oi.id SEPARATOR ' · ') as items_summary
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id=o.id
        WHERE o.table_id COLLATE utf8mb4_unicode_ci=? AND o.branch_id=? AND o.order_type='dine_in'
          AND o.deleted_at IS NULL AND o.status NOT IN ('delivered','cancelled')
        GROUP BY o.id ORDER BY o.id DESC LIMIT 1
    ");
    $order->execute([$table, $bid]);
    $row = $order->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'order'=>$row]);
    exit;
}


// ── Add staff ──
// NOTE: PIN uniqueness is intentionally GLOBAL (see file-level note above) —
// do not scope this check to branch_id without also giving waiter.html a
// tenant-selection step at login.
if ($action === 'add_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    $pin  = trim($d['pin'] ?? '');
    $bid  = (int)($d['branch_id'] ?? 1);
    $role = in_array($d['role']??'', ['waiter','manager']) ? $d['role'] : 'waiter';
    if (!$name || !$pin) { echo json_encode(['ok'=>false,'msg'=>'Name + PIN required']); exit; }
    if (!preg_match('/^\d{4,6}$/', $pin)) { echo json_encode(['ok'=>false,'msg'=>'PIN must be 4-6 digits']); exit; }
    // Check duplicate PIN (global on purpose)
    $exist = $pdo->prepare("SELECT id FROM staff WHERE pin=?");
    $exist->execute([$pin]);
    if ($exist->fetch()) { echo json_encode(['ok'=>false,'msg'=>'PIN already in use']); exit; }
    $pdo->prepare("INSERT INTO staff (name,pin,role,branch_id,is_active) VALUES (?,?,?,?,1)")->execute([$name,$pin,$role,$bid]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Toggle staff active ──
if ($action === 'toggle_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    $active = (int)($d['is_active'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
    $pdo->prepare("UPDATE staff SET is_active=? WHERE id=?")->execute([$active, $id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Delete staff ──
if ($action === 'delete_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
    $pdo->prepare("DELETE FROM staff WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Staff list (for admin) ──
if ($action === 'staff_list') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
    $rows = $pdo->query("SELECT id,name,pin,role,is_active,branch_id FROM staff ORDER BY role,name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'staff'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
