<?php
/**
 * NoodleHaus — Delivery API  (Phase 6C)
 * Endpoint: /delivery_api.php?action=...
 *
 * Actions:
 *   GET  drivers         — driver list
 *   POST driver_create   — new driver
 *   POST driver_status   — update driver status
 *   GET  zones           — delivery zones
 *   POST zone_save       — create/update zone
 *   GET  active          — active delivery orders
 *   GET  pending_orders   — unassigned pending orders (awaiting driver)
 *   POST assign          — assign driver to order (accepts tracking_id OR order_id)
 *   POST update_status   — driver updates delivery status
 *   POST auto_track      — order_handler hook: create tracking for delivery orders
 *   GET  driver_orders   — driver's current assignments (driver app)
 *   POST driver_login    — driver PIN login
 *   GET  stats           — delivery stats
 *   POST webhook         — external platform push orders
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = getPDO();
$action = trim($_GET['action'] ?? '');

function ok(mixed $data = []): never {
    echo json_encode(array_merge(['ok' => true], (array)$data), JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}
function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

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

    if (empty($_SESSION['admin'])) fail('Unauthorized', 401);
}


/* ── DRIVERS ── */
if ($action === 'drivers') {
    $rows = $pdo->query("
        SELECT d.*,
            (SELECT COUNT(*) FROM delivery_tracking dt WHERE dt.driver_id=d.id AND dt.status NOT IN ('delivered','cancelled')) AS active_orders
        FROM drivers d WHERE d.is_active=1 ORDER BY d.status='available' DESC, d.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['drivers' => $rows]);
}

if ($action === 'driver_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? ''); $phone = trim($d['phone'] ?? '');
    $vehicle = trim($d['vehicle_type'] ?? 'motorbike');
    $pin = trim($d['pin'] ?? '');
    if (!$name || !$phone) fail('Name and phone required');
    $pdo->prepare("INSERT INTO drivers (name,phone,vehicle_type,pin) VALUES (?,?,?,?)")
        ->execute([$name,$phone,$vehicle,$pin?:null]);
    ok(['driver_id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'driver_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0); $status = trim($d['status'] ?? '');
    if (!$id || !in_array($status, ['available','busy','offline'])) fail('Invalid');
    $pdo->prepare("UPDATE drivers SET status=? WHERE id=?")->execute([$status,$id]);
    ok();
}


/* ── ZONES ── */
if ($action === 'zones') {
    ok(['zones' => $pdo->query("SELECT * FROM delivery_zones WHERE is_active=1 ORDER BY fee")->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'zone_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    $name = trim($d['zone_name'] ?? ''); $township = trim($d['township'] ?? '');
    $fee = max(0,(int)($d['fee'] ?? 1500)); $est = max(10,(int)($d['estimated_min'] ?? 30));
    if (!$name) fail('Zone name required');
    if ($id) {
        $pdo->prepare("UPDATE delivery_zones SET zone_name=?,township=?,fee=?,estimated_min=? WHERE id=?")
            ->execute([$name,$township?:null,$fee,$est,$id]);
    } else {
        $pdo->prepare("INSERT INTO delivery_zones (zone_name,township,fee,estimated_min) VALUES (?,?,?,?)")
            ->execute([$name,$township?:null,$fee,$est]);
        $id = (int)$pdo->lastInsertId();
    }
    ok(['zone_id' => $id]);
}


/* ── ACTIVE DELIVERIES ── */
if ($action === 'active') {
    requireAdmin();
    $rows = $pdo->prepare("
        SELECT dt.*, o.customer_name, o.customer_phone, o.total_amount,
               o.payment_method, o.special_notes,
               d.name AS driver_name, d.phone AS driver_phone, d.vehicle_type,
               GROUP_CONCAT(oi.item_name,' x',oi.qty ORDER BY oi.id SEPARATOR ', ') AS items
        FROM delivery_tracking dt
        JOIN orders o ON o.id = dt.order_id
        LEFT JOIN drivers d ON d.id = dt.driver_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE dt.status NOT IN ('delivered','cancelled')
          AND o.deleted_at IS NULL
        GROUP BY dt.id
        ORDER BY dt.created_at DESC
    ");
    $rows->execute();
    ok(['deliveries' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ── PENDING ORDERS (unassigned — awaiting driver) ── */
if ($action === 'pending_orders') {
    requireAdmin();
    $rows = $pdo->prepare("
        SELECT dt.id AS tracking_id, dt.order_id, dt.status, dt.created_at,
               o.customer_name, o.customer_phone, o.total_amount,
               o.payment_method, o.special_notes,
               GROUP_CONCAT(oi.item_name,' x',oi.qty ORDER BY oi.id SEPARATOR ', ') AS items
        FROM delivery_tracking dt
        JOIN orders o ON o.id = dt.order_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE dt.status = 'pending'
          AND dt.driver_id IS NULL
          AND o.deleted_at IS NULL
        GROUP BY dt.id
        ORDER BY dt.created_at ASC
    ");
    $rows->execute();
    ok(['orders' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ── ASSIGN DRIVER ── */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $driverId   = (int)($d['driver_id'] ?? 0);
    if (!$driverId) fail('driver_id required');

    // Accept either tracking_id (direct) or order_id (from dlvAssign in admin_modules.js)
    $trackingId = (int)($d['tracking_id'] ?? 0);
    if (!$trackingId && isset($d['order_id'])) {
        $r = $pdo->prepare("SELECT id FROM delivery_tracking WHERE order_id=?");
        $r->execute([(int)$d['order_id']]);
        $trackingId = (int)$r->fetchColumn();
        // Auto-create tracking row if order exists but has no tracking yet
        if (!$trackingId) {
            $chk = $pdo->prepare("SELECT id FROM orders WHERE id=? AND deleted_at IS NULL");
            $chk->execute([(int)$d['order_id']]);
            if (!$chk->fetchColumn()) fail('Order not found');
            $pdo->prepare("INSERT INTO delivery_tracking (order_id) VALUES (?)")->execute([(int)$d['order_id']]);
            $trackingId = (int)$pdo->lastInsertId();
        }
    }
    if (!$trackingId) fail('tracking_id or order_id required');

    $pdo->prepare("UPDATE delivery_tracking SET driver_id=?, status='assigned', assigned_at=NOW() WHERE id=?")
        ->execute([$driverId, $trackingId]);
    $pdo->prepare("UPDATE drivers SET status='busy' WHERE id=?")->execute([$driverId]);

    // Return driver name for toast message
    $drv = $pdo->prepare("SELECT name FROM drivers WHERE id=?");
    $drv->execute([$driverId]);
    $driverName = $drv->fetchColumn() ?: 'Driver';

    ok(['assigned' => true, 'driver_name' => $driverName]);
}


/* ── UPDATE DELIVERY STATUS (driver app) ── */
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $trackingId = (int)($d['tracking_id'] ?? 0);
    $status = trim($d['status'] ?? '');
    $notes = trim($d['notes'] ?? '');
    if (!$trackingId) fail('tracking_id required');
    $valid = ['picked_up','delivering','delivered','cancelled'];
    if (!in_array($status, $valid)) fail('Invalid status');

    $timeCol = match($status) {
        'picked_up' => ', picked_up_at=NOW()',
        'delivered'  => ', delivered_at=NOW()',
        default      => '',
    };
    $noteUpdate = $notes ? ", delivery_notes=CONCAT(IFNULL(delivery_notes,''),' | '," . $pdo->quote($notes) . ")" : '';

    $pdo->prepare("UPDATE delivery_tracking SET status=?{$timeCol}{$noteUpdate} WHERE id=?")
        ->execute([$status, $trackingId]);

    // Free driver on delivered/cancelled
    if (in_array($status, ['delivered','cancelled'])) {
        $driverId = $pdo->prepare("SELECT driver_id FROM delivery_tracking WHERE id=?");
        $driverId->execute([$trackingId]);
        $did = $driverId->fetchColumn();
        if ($did) {
            $pdo->prepare("UPDATE drivers SET status='available', total_deliveries=total_deliveries+1 WHERE id=?")
                ->execute([$did]);
        }
    }

    ok(['status' => $status]);
}


/* ── AUTO TRACK (order_handler hook) ── */
if ($action === 'auto_track' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($d['order_id'] ?? 0);
    if (!$orderId) fail('order_id required');
    // Only create if not exists
    $exists = $pdo->prepare("SELECT id FROM delivery_tracking WHERE order_id=?");
    $exists->execute([$orderId]);
    if ($exists->fetchColumn()) ok(['exists' => true]);

    $pdo->prepare("INSERT INTO delivery_tracking (order_id) VALUES (?)")->execute([$orderId]);
    ok(['tracking_id' => (int)$pdo->lastInsertId()]);
}


/* ── DRIVER LOGIN ── */
if ($action === 'driver_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim($d['pin'] ?? '');
    if (!$pin) fail('PIN required');
    $driver = $pdo->prepare("SELECT id,name,phone,vehicle_type FROM drivers WHERE pin=? AND is_active=1");
    $driver->execute([$pin]);
    $row = $driver->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('PIN မှားနေသည်');
    // Set available
    $pdo->prepare("UPDATE drivers SET status='available' WHERE id=?")->execute([$row['id']]);
    ok(['driver' => $row]);
}


/* ── DRIVER'S ORDERS ── */
if ($action === 'driver_orders') {
    $driverId = (int)($_GET['driver_id'] ?? 0);
    if (!$driverId) fail('driver_id required');
    $rows = $pdo->prepare("
        SELECT dt.*, o.customer_name, o.customer_phone, o.total_amount,
               o.payment_method, o.special_notes,
               CONCAT(IFNULL(o.customer_phone,''),' ',IFNULL(o.special_notes,'')) AS address_info,
               GROUP_CONCAT(oi.item_name,' x',oi.qty ORDER BY oi.id SEPARATOR ', ') AS items
        FROM delivery_tracking dt
        JOIN orders o ON o.id = dt.order_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE dt.driver_id = ? AND dt.status NOT IN ('delivered','cancelled')
          AND o.deleted_at IS NULL
        GROUP BY dt.id
        ORDER BY dt.assigned_at ASC
    ");
    $rows->execute([$driverId]);
    ok(['orders' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ── STATS ── */
if ($action === 'stats') {
    requireAdmin();
    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM delivery_tracking WHERE status NOT IN ('delivered','cancelled')) AS active,
            (SELECT COUNT(*) FROM delivery_tracking WHERE status='delivered' AND DATE(delivered_at)=CURDATE()) AS today_delivered,
            (SELECT COUNT(*) FROM drivers WHERE status='available' AND is_active=1) AS drivers_available,
            (SELECT COUNT(*) FROM delivery_tracking WHERE status='pending') AS pending_assign
    ")->fetch(PDO::FETCH_ASSOC);
    ok(['stats' => $stats]);
}


/* ── WEBHOOK (external platforms) ── */
if ($action === 'webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // API key validation — set in site_settings or env
    $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '');
    $validKey = 'nh_webhook_' . md5('noodlehaus_secret_2026');
    if ($apiKey !== $validKey) fail('Invalid API key', 403);

    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $platform = trim($d['platform'] ?? 'external');
    $extId    = trim($d['external_id'] ?? '');
    $name     = trim($d['customer_name'] ?? 'External Customer');
    $phone    = trim($d['customer_phone'] ?? '');
    $address  = trim($d['address'] ?? '');
    $items    = $d['items'] ?? [];
    $total    = (int)($d['total'] ?? 0);
    $payment  = trim($d['payment_method'] ?? 'cod');
    $notes    = trim($d['notes'] ?? '');

    if (empty($items) || !$total) fail('Items and total required');

    // Create order via internal flow
    $orderItems = [];
    foreach ($items as $item) {
        $orderItems[] = [
            'item_id'  => (int)($item['item_id'] ?? 0),
            'name'     => trim($item['name'] ?? ''),
            'price'    => (int)($item['price'] ?? 0),
            'qty'      => max(1, (int)($item['qty'] ?? 1)),
            'subtotal' => (int)($item['subtotal'] ?? 0),
            'modifiers'=> [],
        ];
    }

    $payload = json_encode([
        'device_id'    => 'webhook-' . $platform,
        'order_type'   => 'delivery',
        'table_id'     => '',
        'customer'     => ['name'=>$name,'phone'=>$phone,'address'=>$address,'township'=>'','city'=>'','notes'=>$notes.' [' . strtoupper($platform) . ($extId?" #$extId":'') . ']'],
        'payment_method'=> $payment,
        'items'        => $orderItems,
        'subtotal'     => $total,
        'delivery_fee' => 0,
        'promo_code'   => '',
        'discount'     => 0,
        'total'        => $total,
    ]);

    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => 'Content-Type: application/json',
        'content' => $payload, 'timeout' => 5,
    ]]);
    $result = @file_get_contents('http://localhost/order_handler.php', false, $ctx);
    $orderResult = json_decode($result ?: '{}', true);

    if (!empty($orderResult['success'])) {
        // Auto-create tracking
        $orderId = (int)$orderResult['db_id'];
        $pdo->prepare("INSERT IGNORE INTO delivery_tracking (order_id) VALUES (?)")->execute([$orderId]);
        ok(['order_id' => $orderResult['order_id'], 'db_id' => $orderId, 'platform' => $platform]);
    } else {
        fail($orderResult['message'] ?? 'Order creation failed');
    }
}


fail('Unknown action');
