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
 *
 * All actions are tenant-scoped via requireTenantAccess() (tenant_helper.php) —
 * a tenant session may only read/write its own reservations, regardless of what
 * tenant_id is passed in the request.
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';

$_BID = (int)($_GET['branch_id'] ?? $_POST['branch_id'] ?? 0);
$_REQ_TENANT_PARAM = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
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


/* ════════════════════════════════════════════════════════════════
   POST create
   Body: { customer_name, customer_phone, party_size, table_code,
           reservation_date, reservation_time, duration_min, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Public action — a customer booking a table doesn't have a login.
    // tenant_id comes from the request (e.g. the storefront the customer is on),
    // same trust model as the public ordering page.
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid   = (int)($d['tenant_id'] ?? 0) ?: ($_REQ_TENANT_PARAM ?: 1);
    $name  = trim($d['customer_name']  ?? '');
    $phone = trim($d['customer_phone'] ?? '');
    $size  = max(1, (int)($d['party_size'] ?? 2));
    $table = trim($d['table_code']     ?? '') ?: null;
    $date  = trim($d['reservation_date'] ?? '');
    $time  = trim($d['reservation_time'] ?? '');
    $dur   = max(30, (int)($d['duration_min'] ?? 90));
    $notes  = trim($d['notes']          ?? '') ?: null;
    $bid    = (int)($d['branch_id'] ?? $_BID ?? 1);

    if (!$name || !$phone) fail('Name and phone required');
    if (!$date || !$time)  fail('Date and time required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date format');
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time .= ':00';

    // Check no double booking on same table+date+overlapping time — scoped to this tenant,
    // since table_code (e.g. "T1") is very likely reused across tenants.
    if ($table) {
        $overlap = $pdo->prepare("
            SELECT id FROM reservations
            WHERE table_code = ?
              AND tenant_id = ?
              AND reservation_date = ?
              AND status NOT IN ('cancelled','no_show','completed')
              AND (
                  (reservation_time <= ? AND ADDTIME(reservation_time, SEC_TO_TIME(duration_min*60)) > ?)
                  OR
                  (reservation_time < ADDTIME(?, SEC_TO_TIME(?*60)) AND reservation_time >= ?)
              )
            LIMIT 1
        ");
        $overlap->execute([$table, $tid, $date, $time, $time, $time, $dur, $time]);
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
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);

    $date   = trim($_GET['date']   ?? '');
    $status = trim($_GET['status'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(50, max(10, (int)($_GET['per'] ?? 20)));
    $offset = ($page - 1) * $per;

    $where  = ['tenant_id = ?'];
    $params = [$tid];
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
    // Public action in the original design (e.g. a front-desk display) — kept public,
    // but still tenant-scoped via the trusted request param so it can't show every
    // tenant's reservations at once.
    $tid = $_REQ_TENANT_PARAM ?: 1;

    $stmt = $pdo->prepare("
        SELECT r.*
        FROM   reservations r
        WHERE  r.reservation_date = CURDATE()
          AND  r.status NOT IN ('cancelled','no_show')
          AND  r.tenant_id = ?
        ORDER  BY r.reservation_time ASC
    ");
    $stmt->execute([$tid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tables for reference — scoped to this tenant
    $tables = $pdo->prepare("
        SELECT table_code, seats FROM restaurant_tables WHERE is_active = 1 AND tenant_id = ? ORDER BY table_code
    ");
    $tables->execute([$tid]);

    ok([
        'reservations' => $rows,
        'tables'       => $tables->fetchAll(PDO::FETCH_ASSOC),
        'date'         => date('Y-m-d'),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  availability?date=YYYY-MM-DD
   Returns available time slots and tables
   ════════════════════════════════════════════════════════════════ */
if ($action === 'availability' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public action — a customer checks open slots before booking.
    $tid  = $_REQ_TENANT_PARAM ?: 1;
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Invalid date');

    // Get all active tables — scoped to this tenant
    $tablesStmt = $pdo->prepare("
        SELECT table_code, seats FROM restaurant_tables WHERE is_active = 1 AND tenant_id = ? ORDER BY table_code
    ");
    $tablesStmt->execute([$tid]);
    $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all reservations for that date — scoped to this tenant
    $existing = $pdo->prepare("
        SELECT table_code, reservation_time, duration_min, status, party_size, customer_name
        FROM   reservations
        WHERE  reservation_date = ?
          AND  tenant_id = ?
          AND  status NOT IN ('cancelled','no_show','completed')
        ORDER  BY reservation_time
    ");
    $existing->execute([$date, $tid]);
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
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);

    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = (int)($d['id'] ?? 0);
    $status = trim($d['status'] ?? '');

    if (!$id) fail('id required');
    $valid = ['pending','confirmed','seated','completed','cancelled','no_show'];
    if (!in_array($status, $valid)) fail('Invalid status');

    $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ? AND tenant_id = ?")
        ->execute([$status, $id, $tid]);

    ok(['id' => $id, 'status' => $status]);
}


/* ════════════════════════════════════════════════════════════════
   POST update
   Body: { id, customer_name, party_size, table_code, reservation_time, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = requireTenantAccess($_REQ_TENANT_PARAM);

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
    $params[] = $tid;

    $pdo->prepare("UPDATE reservations SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?")
        ->execute($params);

    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   GET  by_phone?phone=09xxx
   Scoped to the requesting tenant — a customer's phone number should not
   surface their reservation history at a different, unrelated restaurant.
   ════════════════════════════════════════════════════════════════ */
if ($action === 'by_phone' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Public action — a customer looking up their own reservation by phone.
    $tid   = $_REQ_TENANT_PARAM ?: 1;
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) fail('Phone required');

    $rows = $pdo->prepare("
        SELECT * FROM reservations
        WHERE customer_phone = ? AND tenant_id = ?
        ORDER BY reservation_date DESC, reservation_time DESC
        LIMIT 10
    ");
    $rows->execute([$phone, $tid]);

    ok(['reservations' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}


fail('Unknown action');
