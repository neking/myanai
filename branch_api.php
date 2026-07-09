<?php
/**
 * NoodleHaus — Branch API  (Phase 6B)
 * Endpoint: /branch_api.php?action=...
 *
 * Actions:
 *   GET  list            — all branches
 *   GET  detail          — single branch + stats
 *   POST create          — new branch (super-admin)
 *   POST update          — edit branch
 *   POST toggle          — activate/deactivate
 *   GET  dashboard       — cross-branch analytics
 *   GET  compare         — branch comparison
 *
 * Rule: existing tables READ + branch_id filter only
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

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
function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin'])) fail('Unauthorized', 401);
}


/* ════════════════════════════════════════════════════════════════
   GET  list
   ════════════════════════════════════════════════════════════════ */
if ($action === 'list') {
    $tid = (int)($_GET['tenant_id'] ?? 0);
    $rows = $pdo->query("
        SELECT b.*,
            (SELECT COUNT(*) FROM orders o WHERE o.branch_id = b.id AND o.deleted_at IS NULL) AS total_orders,
            (SELECT COUNT(*) FROM staff s WHERE s.branch_id = b.id AND s.is_active = 1) AS total_staff,
            (SELECT COUNT(*) FROM menu_items m WHERE m.branch_id = b.id AND m.is_active = 1) AS total_menu
        FROM branches b
        " . ($tid > 0 ? 'WHERE b.tenant_id='.$tid : '') . "
        ORDER BY b.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    ok(['branches' => $rows]);
}


/* ════════════════════════════════════════════════════════════════
   GET  detail?id=X
   ════════════════════════════════════════════════════════════════ */
if ($action === 'detail') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');

    $branch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $branch->execute([$id]);
    $row = $branch->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Branch not found');

    // Stats
    $stats = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            (SELECT COUNT(*) FROM staff WHERE branch_id = ? AND is_active = 1) AS staff_count,
            (SELECT COUNT(*) FROM menu_items WHERE branch_id = ? AND is_active = 1) AS menu_count,
            (SELECT COUNT(*) FROM restaurant_tables WHERE branch_id = ?) AS table_count
        FROM orders o
        WHERE o.branch_id = ? AND o.deleted_at IS NULL
    ");
    $stats->execute([$id, $id, $id, $id]);
    $s = $stats->fetch(PDO::FETCH_ASSOC);

    // Today's stats
    $today = $pdo->prepare("
        SELECT COUNT(*) AS today_orders,
               COALESCE(SUM(total_amount), 0) AS today_revenue
        FROM orders
        WHERE branch_id = ? AND deleted_at IS NULL AND DATE(created_at) = CURDATE()
    ");
    $today->execute([$id]);
    $t = $today->fetch(PDO::FETCH_ASSOC);

    ok([
        'branch' => $row,
        'stats'  => $s,
        'today'  => $t,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST create
   Body: { name, code, address, phone, opening_time, closing_time }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    $code = strtoupper(trim($d['code'] ?? ''));
    $addr = trim($d['address'] ?? '') ?: null;
    $phone = trim($d['phone'] ?? '') ?: null;
    $open  = trim($d['opening_time'] ?? '10:00');
    $close = trim($d['closing_time'] ?? '23:00');

    if (!$name || !$code) fail('Name and code required');
    if (strlen($code) > 20) fail('Code too long (max 20)');

    // Check unique code
    $exists = $pdo->prepare("SELECT id FROM branches WHERE code = ?");
    $exists->execute([$code]);
    if ($exists->fetchColumn()) fail('Branch code already exists');

    $pdo->prepare("
        INSERT INTO branches (name, code, address, phone, opening_time, closing_time)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$name, $code, $addr, $phone, $open, $close]);

    $branchId = (int)$pdo->lastInsertId();

    ok(['branch_id' => $branchId, 'code' => $code]);
}


/* ════════════════════════════════════════════════════════════════
   POST update
   Body: { id, name, address, phone, opening_time, closing_time }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');

    $fields = [];
    $params = [];
    foreach (['name','address','phone','opening_time','closing_time'] as $f) {
        if (isset($d[$f])) {
            $fields[] = "$f = ?";
            $params[] = $d[$f];
        }
    }
    if (empty($fields)) fail('Nothing to update');
    $params[] = $id;

    $pdo->prepare("UPDATE branches SET " . implode(', ', $fields) . " WHERE id = ?")
        ->execute($params);

    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   POST toggle
   Body: { id }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    if ($id === 1) fail('Cannot deactivate main branch');

    $pdo->prepare("UPDATE branches SET is_active = NOT is_active WHERE id = ?")
        ->execute([$id]);

    ok(['id' => $id]);
}


/* ════════════════════════════════════════════════════════════════
   GET  dashboard
   Cross-branch overview for super-admin
   ════════════════════════════════════════════════════════════════ */
if ($action === 'dashboard') {
    requireAdmin();

    $branches = $pdo->query("
        SELECT
            b.id, b.name, b.code, b.is_active,
            COUNT(DISTINCT o.id) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            (SELECT COUNT(*) FROM orders o2
             WHERE o2.branch_id = b.id AND o2.deleted_at IS NULL
               AND DATE(o2.created_at) = CURDATE()) AS today_orders,
            (SELECT COALESCE(SUM(o3.total_amount), 0) FROM orders o3
             WHERE o3.branch_id = b.id AND o3.deleted_at IS NULL
               AND DATE(o3.created_at) = CURDATE()) AS today_revenue,
            (SELECT COUNT(*) FROM staff s WHERE s.branch_id = b.id AND s.is_active = 1) AS total_staff,
            (SELECT COUNT(*) FROM menu_items m WHERE m.branch_id = b.id AND m.is_active = 1) AS total_menu
        FROM branches b
        LEFT JOIN orders o ON o.branch_id = b.id AND o.deleted_at IS NULL
        GROUP BY b.id
        ORDER BY b.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Grand totals
    $grand = $pdo->query("
        SELECT
            COUNT(DISTINCT o.id) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            (SELECT COUNT(DISTINCT o2.id) FROM orders o2 WHERE o2.deleted_at IS NULL AND DATE(o2.created_at) = CURDATE()) AS today_orders,
            (SELECT COALESCE(SUM(o3.total_amount), 0) FROM orders o3 WHERE o3.deleted_at IS NULL AND DATE(o3.created_at) = CURDATE()) AS today_revenue
        FROM orders o
        WHERE o.deleted_at IS NULL
    ")->fetch(PDO::FETCH_ASSOC);

    ok([
        'branches' => $branches,
        'grand'    => $grand,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  compare?days=7
   Branch performance comparison
   ════════════════════════════════════════════════════════════════ */
if ($action === 'compare') {
    requireAdmin();

    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));

    $rows = $pdo->prepare("
        SELECT
            b.id, b.name, b.code,
            COUNT(DISTINCT o.id) AS orders,
            COALESCE(SUM(o.total_amount), 0) AS revenue,
            COALESCE(AVG(o.total_amount), 0) AS avg_order,
            COUNT(DISTINCT DATE(o.created_at)) AS active_days
        FROM branches b
        LEFT JOIN orders o ON o.branch_id = b.id
            AND o.deleted_at IS NULL
            AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        WHERE b.is_active = 1
        GROUP BY b.id
        ORDER BY revenue DESC
    ");
    $rows->execute([$days]);

    ok([
        'comparison' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'days'       => $days,
    ]);
}


fail('Unknown action');
