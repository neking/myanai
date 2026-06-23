<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';
if(session_status()===PHP_SESSION_NONE) session_start();
$pdo = getPDO();
header('Content-Type: application/json; charset=utf-8');

$tid = 0;
if(!empty($_SESSION['tenant_admin'])) $tid=(int)($_SESSION['tenant_id']??0);
elseif(!empty($_SESSION['admin']))    $tid=(int)($_GET['tenant_id']??0);
if(!$tid){ echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$action = $_GET['action']??'';

/* ── CREATE shifts table if not exist ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_shifts (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id  INT UNSIGNED NOT NULL,
        staff_id   INT UNSIGNED NOT NULL,
        shift_date DATE NOT NULL,
        shift_type ENUM('morning','afternoon','evening','off','custom') DEFAULT 'morning',
        start_time TIME DEFAULT '09:00:00',
        end_time   TIME DEFAULT '17:00:00',
        notes      VARCHAR(200) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(tenant_id), INDEX(shift_date), INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(\Exception $e){}

/* ── LIST ── */
if($action==='list'){
    $date  = $_GET['date']??'';
    $week  = $_GET['week']??'';
    if($week){
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date BETWEEN ? AND ?
            ORDER BY ss.shift_date,s.name");
        // week = YYYY-WW, get monday
        $dt = new DateTime(); $dt->setISODate((int)substr($week,0,4),(int)substr($week,5,2));
        $mon = $dt->format('Y-m-d'); $dt->modify('+6 days'); $sun=$dt->format('Y-m-d');
        $stmt->execute([$tid,$mon,$sun]);
    } elseif($date){
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date=? ORDER BY s.name");
        $stmt->execute([$tid,$date]);
    } else {
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date>=CURDATE()-7
            ORDER BY ss.shift_date DESC,s.name LIMIT 100");
        $stmt->execute([$tid]);
    }
    echo json_encode(['ok'=>true,'shifts'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

/* ── ADD ── */
if($action==='add' && $_SERVER['REQUEST_METHOD']==='POST'){
    $b = json_decode(file_get_contents('php://input'),true)??[];
    $stmt=$pdo->prepare("INSERT INTO staff_shifts (tenant_id,staff_id,shift_date,shift_type,start_time,end_time,notes)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shift_type=VALUES(shift_type),start_time=VALUES(start_time),end_time=VALUES(end_time),notes=VALUES(notes)");
    $stmt->execute([$tid,(int)$b['staff_id'],$b['shift_date'],$b['shift_type']??'morning',$b['start_time']??'09:00',$b['end_time']??'17:00',$b['notes']??'']);
    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    exit;
}

/* ── DELETE ── */
if($action==='delete' && $_SERVER['REQUEST_METHOD']==='POST'){
    $b = json_decode(file_get_contents('php://input'),true)??[];
    $pdo->prepare("DELETE FROM staff_shifts WHERE id=? AND tenant_id=?")->execute([(int)$b['id'],$tid]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);

/* ─── CURRENT SHIFT (open/closed status) ─── */
if ($action === 'current') {
    $bid = (int)($_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
    // Find open shift for this branch
    $stmt = $bid
        ? $pdo->prepare("SELECT s.*, st.name as staff_name FROM shifts s LEFT JOIN staff st ON st.id=s.staff_id WHERE s.branch_id=? AND s.status='open' ORDER BY s.id DESC LIMIT 1")
        : $pdo->prepare("SELECT s.*, st.name as staff_name FROM shifts s LEFT JOIN staff st ON st.id=s.staff_id WHERE s.status='open' ORDER BY s.id DESC LIMIT 1");
    $bid ? $stmt->execute([$bid]) : $stmt->execute();
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        echo json_encode(['ok'=>true,'is_open'=>false,'shift'=>null,'stats'=>null]);
        exit;
    }

    // Get stats for open shift
    $statsStmt = $pdo->prepare("
        SELECT COUNT(*) as total_orders,
               COALESCE(SUM(total_amount),0) as total_revenue,
               COALESCE(SUM(CASE WHEN payment_method='cash' THEN total_amount END),0) as cash_revenue,
               COALESCE(SUM(CASE WHEN payment_method!='cash' THEN total_amount END),0) as digital_revenue
        FROM orders WHERE branch_id=? AND created_at >= ? AND deleted_at IS NULL AND status!='cancelled'
    ");
    $statsStmt->execute([$shift['branch_id'], $shift['opened_at']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'is_open'=>true,'shift'=>$shift,'stats'=>$stats]);
    exit;
}

/* ─── SHIFT HISTORY ─── */
if ($action === 'history') {
    $bid = (int)($_GET['branch_id'] ?? 0);
    $per = min(50, (int)($_GET['per'] ?? 20));
    $where = $bid ? "WHERE s.branch_id=?" : "WHERE 1=1";
    $params = $bid ? [$bid] : [];
    $stmt = $pdo->prepare("
        SELECT s.*, st.name as staff_name,
            TIMESTAMPDIFF(MINUTE, s.opened_at, COALESCE(s.closed_at, NOW())) as duration_min,
            (s.closing_cash - s.opening_cash) as cash_difference
        FROM shifts s LEFT JOIN staff st ON st.id=s.staff_id
        $where ORDER BY s.id DESC LIMIT $per
    ");
    $stmt->execute($params);
    echo json_encode(['ok'=>true,'shifts'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

/* ─── OPEN SHIFT ─── */
if ($action === 'open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin  = trim($d['pin'] ?? '');
    $cash = (int)($d['opening_cash'] ?? 0);
    $bid  = (int)($d['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
    if (!$pin) { echo json_encode(['ok'=>false,'msg'=>'PIN required']); exit; }
    // Verify PIN
    $staff = $pdo->prepare("SELECT id,name FROM staff WHERE pin=? AND is_active=1".($bid?" AND branch_id=$bid":""));
    $staff->execute([$pin]);
    $st = $staff->fetch(PDO::FETCH_ASSOC);
    if (!$st) { echo json_encode(['ok'=>false,'msg'=>'Invalid PIN']); exit; }
    // Check no open shift
    $open = $pdo->prepare("SELECT id FROM shifts WHERE branch_id=? AND status='open'");
    $open->execute([$bid ?: 1]);
    if ($open->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'Shift already open']); exit; }
    // Create shift
    $pdo->prepare("INSERT INTO shifts (branch_id, staff_id, opening_cash, status, opened_at) VALUES (?,?,?,'open',NOW())")
        ->execute([$bid ?: 1, $st['id'], $cash]);
    echo json_encode(['ok'=>true,'msg'=>'Shift opened','staff_name'=>$st['name']]);
    exit;
}

/* ─── CLOSE SHIFT ─── */
if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d        = json_decode(file_get_contents('php://input'), true) ?? [];
    $shiftId  = (int)($d['shift_id'] ?? 0);
    $cash     = (int)($d['closing_cash'] ?? 0);
    $notes    = trim($d['notes'] ?? '');
    if (!$shiftId) { echo json_encode(['ok'=>false,'msg'=>'shift_id required']); exit; }
    $shift = $pdo->prepare("SELECT * FROM shifts WHERE id=? AND status='open'");
    $shift->execute([$shiftId]);
    $s = $shift->fetch(PDO::FETCH_ASSOC);
    if (!$s) { echo json_encode(['ok'=>false,'msg'=>'Shift not found or already closed']); exit; }
    $diff = $cash - (int)$s['opening_cash'];
    $pdo->prepare("UPDATE shifts SET status='closed', closing_cash=?, cash_difference=?, close_notes=?, closed_at=NOW() WHERE id=?")
        ->execute([$cash, $diff, $notes, $shiftId]);
    echo json_encode(['ok'=>true,'msg'=>'Shift closed','cash_diff'=>$diff]);
    exit;
}

/* ─── SHIFT DETAIL ─── */
if ($action === 'detail') {
    $sid = (int)($_GET['shift_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'shift_id required']); exit; }
    $stmt = $pdo->prepare("SELECT s.*, st.name as staff_name FROM shifts s LEFT JOIN staff st ON st.id=s.staff_id WHERE s.id=?");
    $stmt->execute([$sid]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) { echo json_encode(['ok'=>false,'msg'=>'Shift not found']); exit; }
    // Orders in this shift
    $orders = $pdo->prepare("SELECT id, total_amount, payment_method, GROUP_CONCAT(oi.item_name SEPARATOR ', ') as items FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE o.branch_id=? AND o.created_at>=? AND (o.closed_at<=? OR o.status!='cancelled') AND o.deleted_at IS NULL GROUP BY o.id ORDER BY o.id DESC LIMIT 50");
    $orders->execute([$shift['branch_id'], $shift['opened_at'], $shift['closed_at'] ?? date('Y-m-d H:i:s')]);
    $stats = ['total_orders'=>0,'total_revenue'=>0];
    $orderList = $orders->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orderList as $o) { $stats['total_orders']++; $stats['total_revenue'] += $o['total_amount']; }
    echo json_encode(['ok'=>true,'shift'=>$shift,'stats'=>$stats,'orders'=>$orderList]);
    exit;
}
