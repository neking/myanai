<?php
/**
 * NoodleHaus — Shift API  (Phase 5B)
 * Endpoint: /shift_api.php?action=...
 *
 * Actions:
 *   GET  current        — open shift ရှိမရှိ + live stats
 *   POST open           — shift ဖွင့် { pin, opening_cash }
 *   POST close          — shift ပိတ် { shift_id, closing_cash, notes }
 *   GET  history        — past shifts (paginated)
 *   GET  detail         — single shift full detail
 *   POST assign_order   — order_handler hook — order ကို current shift နဲ့ link
 *
 * Rule: orders / staff / existing tables ကို READ သာ — မထိ
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

/* ── helpers ── */
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

/* helper: calculate live stats for a shift */
function shiftLiveStats(PDO $pdo, int $shiftId, string $openedAt): array {
    // Orders placed after shift opened that are linked OR (fallback) by time
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id)                        AS total_orders,
            COALESCE(SUM(o.total_amount), 0)            AS total_revenue,
            COALESCE(SUM(CASE WHEN o.payment_method IN ('cash','cod') THEN o.total_amount ELSE 0 END), 0) AS cash_revenue,
            COALESCE(SUM(CASE WHEN o.payment_method NOT IN ('cash','cod') THEN o.total_amount ELSE 0 END), 0) AS digital_revenue
        FROM shift_orders so
        JOIN orders o ON o.id = so.order_id
        WHERE so.shift_id = ?
          AND o.deleted_at IS NULL
          AND o.status != 'cancelled'
    ");
    $stmt->execute([$shiftId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total_orders'    => (int)$stats['total_orders'],
        'total_revenue'   => (int)$stats['total_revenue'],
        'cash_revenue'    => (int)$stats['cash_revenue'],
        'digital_revenue' => (int)$stats['digital_revenue'],
    ];
}


/* ════════════════════════════════════════════════════════════════
   GET  current
   Open shift ရှိမရှိ + live stats
   ════════════════════════════════════════════════════════════════ */
if ($action === 'current' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $shift = $pdo->query("
        SELECT s.*, st.name AS staff_name_live
        FROM   shifts s
        LEFT JOIN staff st ON st.id = s.staff_id
        WHERE  s.status = 'open'
        ORDER  BY s.opened_at DESC
        LIMIT  1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        ok(['shift' => null, 'is_open' => false]);
    }

    $stats = shiftLiveStats($pdo, (int)$shift['id'], $shift['opened_at']);

    ok([
        'is_open' => true,
        'shift'   => $shift,
        'stats'   => $stats,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST open
   Body: { pin, opening_cash }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d            = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin          = trim($d['pin']          ?? '');
    $openingCash  = max(0, (int)($d['opening_cash'] ?? 0));

    if (!$pin) fail('PIN လိုသည်');

    // Verify staff PIN
    $staff = $pdo->prepare("SELECT id, name, role FROM staff WHERE pin = ? AND is_active = 1");
    $staff->execute([$pin]);
    $staffRow = $staff->fetch(PDO::FETCH_ASSOC);
    if (!$staffRow) fail('PIN မှားနေသည်');

    // Check no shift already open
    $open = $pdo->query("SELECT id FROM shifts WHERE status='open' LIMIT 1")->fetchColumn();
    if ($open) fail('Shift တစ်ခု ဖွင့်ထားပြီး — အရင်ပိတ်ပါ');

    $pdo->prepare("
        INSERT INTO shifts (staff_id, staff_name, opening_cash, status, opened_at)
        VALUES (?, ?, ?, 'open', NOW())
    ")->execute([$staffRow['id'], $staffRow['name'], $openingCash]);

    $shiftId = (int)$pdo->lastInsertId();

    ok([
        'shift_id'   => $shiftId,
        'staff_name' => $staffRow['name'],
        'opened_at'  => date('Y-m-d H:i:s'),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST close
   Body: { shift_id, closing_cash, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d           = json_decode(file_get_contents('php://input'), true) ?? [];
    $shiftId     = (int)($d['shift_id']    ?? 0);
    $closingCash = max(0, (int)($d['closing_cash'] ?? 0));
    $notes       = trim($d['notes']        ?? '');

    if (!$shiftId) fail('shift_id လိုသည်');

    $shift = $pdo->prepare("SELECT * FROM shifts WHERE id = ? AND status = 'open'");
    $shift->execute([$shiftId]);
    $row = $shift->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Open shift မတွေ့ပါ');

    // Calculate final stats from linked orders
    $stats = shiftLiveStats($pdo, $shiftId, $row['opened_at']);
    $cashDiff = $closingCash - (int)$row['opening_cash'] - $stats['cash_revenue'];

    $pdo->prepare("
        UPDATE shifts SET
            status          = 'closed',
            closed_at       = NOW(),
            closing_cash    = ?,
            notes           = ?,
            total_orders    = ?,
            total_revenue   = ?,
            cash_revenue    = ?,
            digital_revenue = ?,
            cash_difference = ?
        WHERE id = ?
    ")->execute([
        $closingCash, $notes ?: null,
        $stats['total_orders'], $stats['total_revenue'],
        $stats['cash_revenue'], $stats['digital_revenue'],
        $cashDiff, $shiftId,
    ]);

    ok([
        'shift_id'      => $shiftId,
        'stats'         => $stats,
        'closing_cash'  => $closingCash,
        'cash_diff'     => $cashDiff,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  history?page=1&per=20
   ════════════════════════════════════════════════════════════════ */
if ($action === 'history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(50, max(10, (int)($_GET['per'] ?? 20)));
    $offset = ($page - 1) * $per;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM shifts")->fetchColumn();

    $rows = $pdo->prepare("
        SELECT s.*,
               TIMESTAMPDIFF(MINUTE, s.opened_at, COALESCE(s.closed_at, NOW())) AS duration_min
        FROM   shifts s
        ORDER  BY s.opened_at DESC
        LIMIT  $per OFFSET $offset
    ");
    $rows->execute();
    $shifts = $rows->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'shifts' => $shifts,
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int)ceil($total / $per),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  detail?shift_id=X
   ════════════════════════════════════════════════════════════════ */
if ($action === 'detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $shiftId = (int)($_GET['shift_id'] ?? 0);
    if (!$shiftId) fail('shift_id လိုသည်');

    $shift = $pdo->prepare("SELECT * FROM shifts WHERE id = ?");
    $shift->execute([$shiftId]);
    $row = $shift->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Shift မတွေ့ပါ');

    // Orders in this shift
    $orders = $pdo->prepare("
        SELECT o.id, o.total_amount, o.payment_method, o.status,
               o.order_type, o.created_at,
               GROUP_CONCAT(oi.item_name, ' x', oi.qty ORDER BY oi.id SEPARATOR ', ') AS items
        FROM   shift_orders so
        JOIN   orders o  ON o.id = so.order_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE  so.shift_id = ?
          AND  o.deleted_at IS NULL
        GROUP  BY o.id
        ORDER  BY o.created_at ASC
    ");
    $orders->execute([$shiftId]);
    $orderList = $orders->fetchAll(PDO::FETCH_ASSOC);

    // Live stats if still open
    $stats = ($row['status'] === 'open')
        ? shiftLiveStats($pdo, $shiftId, $row['opened_at'])
        : [
            'total_orders'    => (int)$row['total_orders'],
            'total_revenue'   => (int)$row['total_revenue'],
            'cash_revenue'    => (int)$row['cash_revenue'],
            'digital_revenue' => (int)$row['digital_revenue'],
          ];

    ok([
        'shift'  => $row,
        'stats'  => $stats,
        'orders' => $orderList,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST assign_order
   order_handler.php မှာ order placed ပြီးတိုင်း call
   Body: { order_id }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'assign_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d       = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($d['order_id'] ?? 0);
    if (!$orderId) fail('order_id လိုသည်');

    // Find open shift
    $shiftId = $pdo->query("SELECT id FROM shifts WHERE status='open' ORDER BY opened_at DESC LIMIT 1")->fetchColumn();
    if (!$shiftId) ok(['assigned' => false, 'msg' => 'No open shift']);

    // Insert ignore — duplicate safe
    $pdo->prepare("
        INSERT IGNORE INTO shift_orders (shift_id, order_id) VALUES (?, ?)
    ")->execute([$shiftId, $orderId]);

    ok(['assigned' => true, 'shift_id' => $shiftId]);
}


fail('Unknown action');
