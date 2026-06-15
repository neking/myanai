<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();
if(session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok(array $d): never { echo json_encode(['ok'=>true]+$d); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function tenantId(): int {
    $tid = (int)($_GET['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
    return $tid > 0 ? $tid : 1;
}

$action = $_GET['action'] ?? '';

// ── Branch Analytics ─────────────────────────────────────────
if ($action === 'branches') {
    $tid  = tenantId();
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    $rows = $pdo->prepare("
        SELECT
            b.id, b.name, b.code,
            COUNT(o.id)                          AS total_orders,
            COALESCE(SUM(o.total_amount), 0)     AS revenue,
            COALESCE(AVG(o.total_amount), 0)     AS avg_order,
            COUNT(CASE WHEN o.status='cancelled' THEN 1 END) AS cancelled,
            MAX(o.created_at)                    AS last_order
        FROM branches b
        LEFT JOIN orders o
            ON  o.branch_id   = b.id
            AND o.tenant_id   = ?
            AND o.deleted_at  IS NULL
            AND DATE(o.created_at) BETWEEN ? AND ?
        WHERE b.tenant_id = ?
        GROUP BY b.id, b.name, b.code
        ORDER BY revenue DESC
    ");
    $rows->execute([$tid, $from, $to, $tid]);
    ok(['branches' => $rows->fetchAll(PDO::FETCH_ASSOC), 'from' => $from, 'to' => $to]);
}

// ── Daily Summary ────────────────────────────────────────────
if ($action === 'daily') {
    $tid  = tenantId();
    $date = $_GET['date'] ?? date('Y-m-d');
    $rows = $pdo->prepare("
        SELECT
            COUNT(*)                         AS total_orders,
            COALESCE(SUM(total_amount), 0)   AS revenue,
            COALESCE(SUM(subtotal), 0)       AS subtotal,
            COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled,
            COUNT(CASE WHEN order_type='dine_in' THEN 1 END) AS dine_in,
            COUNT(CASE WHEN order_type='delivery' THEN 1 END) AS delivery
        FROM orders
        WHERE tenant_id=? AND DATE(created_at)=? AND deleted_at IS NULL
    ");
    $rows->execute([$tid, $date]);
    ok(['summary' => $rows->fetch(PDO::FETCH_ASSOC), 'date' => $date]);
}

// ── Top Items ────────────────────────────────────────────────
if ($action === 'top_items') {
    $tid  = tenantId();
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    $rows = $pdo->prepare("
        SELECT oi.item_name, SUM(oi.qty) AS qty, SUM(oi.qty*oi.unit_price) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.tenant_id=? AND o.deleted_at IS NULL
          AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY oi.item_name
        ORDER BY qty DESC
        LIMIT 20
    ");
    $rows->execute([$tid, $from, $to]);
    ok(['items' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}

fail('Unknown action');
