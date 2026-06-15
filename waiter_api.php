<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');
$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ── PIN login ──
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d   = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim($d['pin'] ?? '');
    if (!$pin) { echo json_encode(['ok'=>false,'msg'=>'PIN မထည့်ရသေး']); exit; }
    $stmt = $pdo->prepare("SELECT id,name,role FROM staff WHERE pin=? AND is_active=1");
    $stmt->execute([$pin]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) { echo json_encode(['ok'=>false,'msg'=>'PIN မှားနေသည်']); exit; }
    echo json_encode(['ok'=>true,'staff'=>$staff]);
    exit;
}

// ── Table list ──
if ($action === 'tables') {
    $tables = $pdo->query("
        SELECT t.*, 
               o.id as order_id, o.status as order_status,
               COUNT(oi.id) as item_count,
               COALESCE(SUM(oi.qty * oi.unit_price),0) as subtotal
        FROM restaurant_tables t
        LEFT JOIN orders o ON o.table_id COLLATE utf8mb4_unicode_ci = t.table_code COLLATE utf8mb4_unicode_ci
            AND o.order_type='dine_in' 
            AND o.deleted_at IS NULL 
            AND o.status NOT IN ('delivered','cancelled')
        LEFT JOIN order_items oi ON oi.order_id=o.id
        GROUP BY t.id, o.id
        ORDER BY t.table_code
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'tables'=>$tables]);
    exit;
}

// ── Menu items ──
if ($action === 'menu') {
    $items = $pdo->query("
        SELECT id, name, category, price, stock_qty, emoji, image_path
        FROM menu_items WHERE is_active=1 AND stock_qty>0
        ORDER BY category, sort_order, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'items'=>$items]);
    exit;
}

// ── Place order (waiter) ──
if ($action === 'order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d       = json_decode(file_get_contents('php://input'), true) ?? [];
    $table   = trim($d['table_code'] ?? '');
    $staffId = (int)($d['staff_id'] ?? 0);
    $items   = $d['items'] ?? [];
    $note    = trim($d['note'] ?? '');

    if (!$table || !$staffId || empty($items)) {
        echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit;
    }

    // Staff verify
    $s = $pdo->prepare("SELECT name FROM staff WHERE id=? AND is_active=1");
    $s->execute([$staffId]);
    $staffName = $s->fetchColumn();
    if (!$staffName) { echo json_encode(['ok'=>false,'msg'=>'Staff not found']); exit; }

    // Table verify
    $t = $pdo->prepare("SELECT id FROM restaurant_tables WHERE table_code=?");
    $t->execute([$table]);
    if (!$t->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'Table not found']); exit; }

    // Calc total
    $subtotal = 0;
    $itemRows = [];
    foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $qty    = max(1,(int)($item['qty'] ?? 1));
        $mi = $pdo->prepare("SELECT name,price,stock_qty FROM menu_items WHERE id=? AND is_active=1");
        $mi->execute([$itemId]);
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

        // Check existing open order for table
        $ex = $pdo->prepare("SELECT id FROM orders WHERE table_id=? AND order_type='dine_in' AND deleted_at IS NULL AND status NOT IN ('delivered','cancelled') LIMIT 1");
        $ex->execute([$table]);
        $existingOrderId = $ex->fetchColumn();

        if ($existingOrderId) {
            // Append to existing order
            $orderId   = $existingOrderId;
            $isAppend  = true;
        } else {
            // New order
            $pdo->prepare("INSERT INTO orders (customer_name,customer_phone,delivery_address,township,special_notes,payment_method,subtotal,delivery_fee,total_amount,status,order_type,table_id) VALUES (?,?,?,?,?,'cash',?,0,?,  'pending','dine_in',?)")
                ->execute([$staffName.' (Waiter)','','','', $note, $subtotal, $subtotal, $table]);
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

        // KDS push
        $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,pushed_at) VALUES (?,'kitchen','pending',NOW())")
            ->execute([$orderId]);

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
    if (!$table) { echo json_encode(['ok'=>false,'msg'=>'No table']); exit; }
    $order = $pdo->prepare("
        SELECT o.id, o.status, o.total_amount, o.created_at,
               GROUP_CONCAT(oi.qty,'x ',oi.item_name ORDER BY oi.id SEPARATOR ' · ') as items_summary
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id=o.id
        WHERE o.table_id COLLATE utf8mb4_unicode_ci=? AND o.order_type='dine_in' 
          AND o.deleted_at IS NULL AND o.status NOT IN ('delivered','cancelled')
        GROUP BY o.id ORDER BY o.id DESC LIMIT 1
    ");
    $order->execute([$table]);
    $row = $order->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'order'=>$row]);
    exit;
}


// ── Add staff ──
if ($action === 'add_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    $pin  = trim($d['pin'] ?? '');
    $role = in_array($d['role']??'', ['waiter','manager']) ? $d['role'] : 'waiter';
    if (!$name || !$pin) { echo json_encode(['ok'=>false,'msg'=>'Name + PIN required']); exit; }
    if (!preg_match('/^\d{4,6}$/', $pin)) { echo json_encode(['ok'=>false,'msg'=>'PIN must be 4-6 digits']); exit; }
    // Check duplicate PIN
    $exist = $pdo->prepare("SELECT id FROM staff WHERE pin=?");
    $exist->execute([$pin]);
    if ($exist->fetch()) { echo json_encode(['ok'=>false,'msg'=>'PIN already in use']); exit; }
    $pdo->prepare("INSERT INTO staff (name,pin,role,is_active) VALUES (?,?,?,1)")->execute([$name,$pin,$role]);
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
    session_start();
    if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
    $rows = $pdo->query("SELECT id,name,pin,role,is_active FROM staff ORDER BY role,name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'staff'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
