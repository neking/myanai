<?php
/**
 * NoodleHaus — Reservation API  (Phase 5D)
 * Endpoint: /reservation_api.php?action=...
 *
 * Actions:
 *   POST create          — new reservation
 *   GET  list            — admin list (date filter, paginated)
 *   GET  today           — today's reservations
 *   GET  availability    — check time slots for a date
 *   POST update_status   — confirm/seat/complete/cancel/no_show
 *   POST update          — edit reservation details
 *   GET  by_phone        — customer's reservations
 *
 * Rule: restaurant_tables READ only — never modified
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

$_BID = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
$_TID = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
$_BWHERE_R = $_BID > 0 ? " AND branch_id = $_BID" : ($_TID > 0 ? " AND tenant_id = $_TID" : "");

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


/* ════════════════════════════════════════════════════════════════
   POST create
   Body: { customer_name, customer_phone, party_size, table_code,
           reservation_date, reservation_time, duration_min, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($d['customer_name']  ?? '');
    $phone = trim($d['customer_phone'] ?? '');
    $size  = max(1, (int)($d['party_size'] ?? 2));
    $table = trim($d['table_code']     ?? '') ?: null;
    $date  = trim($d['reservation_date'] ?? '');
    $time  = trim($d['reservation_time'] ?? '');
    $dur   = max(30, (int)($d['duration_min'] ?? 90));
    $notes  = trim($d['notes']          ?? '') ?: null;
    $bid    = (int)($d['branch_id'] ?? $_BID ?? 1);
    $tid    = (int)($d['tenant_id'] ?? $_TID ?? 1);

    if (!$name || !$phone) fail('Name and phone required');
    if (!$date || !$time)  fail('Date and time required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date format');
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time .= ':00';

    // Check no double booking on same table+date+overlapping time
    if ($table) {
        $overlap = $pdo->prepare("
            SELECT id FROM reservations
            WHERE table_code = ?
              AND reservation_date = ?
              AND status NOT IN ('cancelled','no_show','completed')
              AND (
                  (reservation_time <= ? AND ADDTIME(reservation_time, SEC_TO_TIME(duration_min*60)) > ?)
                  OR
                  (reservation_time < ADDTIME(?, SEC_TO_TIME(?*60)) AND reservation_time >= ?)
              )
            LIMIT 1
        ");
        $overlap->execute([$table, $date, $time, $time, $time, $dur, $time]);
        if ($overlap->fetchColumn()) fail('Table already reserved for this time slot');
    }

    $pdo->prepare("
        INSERT INTO reservations
            (customer_name, customer_phone, party_size, table_code,
             reservation_date, reservation_time, duration_min, notes, branch_id, tenant_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$name, $phone, $size, $table, $date, $time, $dur, $notes, $bid, $tid]);

    $id = (int)$pdo->lastInsertId();
    ok(['reservation_id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   GET  list?date=&status=&page=1&per=20
   ════════════════════════════════════════════════════════════════ */
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $date   = trim($_GET['date']   ?? '');
    $status = trim($_GET['status'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(50, max(10, (int)($_GET['per'] ?? 20)));
    $offset = ($page - 1) * $per;

    $where  = ['1=1'];
    $params = [];
    if ($_BID > 0) { $where[] = 'branch_id = ?'; $params[] = $_BID; }

    if ($date) { $where[] = 'r.reservation_date = ?'; $params[] = $date; }
    if ($status && in_array($status, ['pending','confirmed','seated','completed','cancelled','no_show'])) {
        $where[] = 'r.status = ?'; $params[] = $status;
    }

    $whereSQL = implode(' AND ', $where);

    $total = $pdo->prepare("SELECT COUNT(*) FROM reservations r WHERE $whereSQL");
    $total->execute($params);
    $totalRows = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT r.*
        FROM   reservations r
        WHERE  $whereSQL
        ORDER  BY r.reservation_date DESC, r.reservation_time ASC
        LIMIT  $per OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'reservations' => $rows,
        'total'        => $totalRows,
        'page'         => $page,
        'pages'        => (int)ceil($totalRows / $per),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  today
   ════════════════════════════════════════════════════════════════ */
if ($action === 'today' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query("
        SELECT r.*
        FROM   reservations r
        WHERE  r.reservation_date = CURDATE()
          AND  r.status NOT IN ('cancelled','no_show')
        ORDER  BY r.reservation_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get tables for reference
    $tables = $pdo->query("
        SELECT table_code, seats FROM restaurant_tables WHERE is_active = 1 ORDER BY table_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'reservations' => $rows,
        'tables'       => $tables,
        'date'         => date('Y-m-d'),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  availability?date=YYYY-MM-DD
   Returns available time slots and tables
   ════════════════════════════════════════════════════════════════ */
if ($action === 'availability' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date');

    // Get all active tables
    $tables = $pdo->query("
        SELECT table_code, seats FROM restaurant_tables WHERE is_active = 1 ORDER BY table_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get all reservations for that date
    $existing = $pdo->prepare("
        SELECT table_code, reservation_time, duration_min, status, party_size, customer_name
        FROM   reservations
        WHERE  reservation_date = ?
          AND  status NOT IN ('cancelled','no_show','completed')
        ORDER  BY reservation_time
    ");
    $existing->execute([$date]);
    $booked = $existing->fetchAll(PDO::FETCH_ASSOC);

    // Generate time slots (10:00 - 21:00, 30-min intervals)
    $slots = [];
    for ($h = 10; $h <= 21; $h++) {
        foreach (['00','30'] as $m) {
            $t = sprintf('%02d:%s', $h, $m);
            $slots[] = $t;
        }
    }

    ok([
        'date'    => $date,
        'tables'  => $tables,
        'booked'  => $booked,
        'slots'   => $slots,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST update_status
   Body: { id, status }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = (int)($d['id'] ?? 0);
    $status = trim($d['status'] ?? '');

    if (!$id) fail('id required');
    $valid = ['pending','confirmed','seated','completed','cancelled','no_show'];
    if (!in_array($status, $valid)) fail('Invalid status');

    $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ?")
        ->execute([$status, $id]);

    ok(['id' => $id, 'status' => $status]);
}


/* ════════════════════════════════════════════════════════════════
   POST update
   Body: { id, customer_name, party_size, table_code, reservation_time, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');

    $fields = [];
    $params = [];

    foreach (['customer_name','party_size','table_code','reservation_time','duration_min','notes'] as $f) {
        if (isset($d[$f])) {
            $fields[] = "$f = ?";
            $params[] = $d[$f] === '' ? null : $d[$f];
        }
    }
    if (empty($fields)) fail('Nothing to update');
    $params[] = $id;

    $pdo->prepare("UPDATE reservations SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);

    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   GET  by_phone?phone=09xxx
   ════════════════════════════════════════════════════════════════ */
if ($action === 'by_phone' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) fail('Phone required');

    $rows = $pdo->prepare("
        SELECT * FROM reservations
        WHERE customer_phone = ?
        ORDER BY reservation_date DESC, reservation_time DESC
        LIMIT 10
    ");
    $rows->execute([$phone]);

    ok(['reservations' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


fail('Unknown action');
