<?php
require_once __DIR__ . '/db_connect.php';
session_start();
header('Content-Type: application/json');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if (empty($_SESSION['admin']) && empty($_SESSION['tenant_id'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
// Allow tenant to only access their own data
if (!empty($_SESSION['tenant_id'])) {
    $_GET['tenant_id'] = $_SESSION['tenant_id'];
}

$pdo  = getPDO();
$days = max(7, min(90, (int)($_GET['days'] ?? 7)));
$bid  = (int)($_GET['branch_id'] ?? 0);
$tid  = (int)($_GET['tenant_id'] ?? 0);

// ★ Always require tenant_id for isolation ★
if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

// Build safe params array instead of string concatenation
$baseWhere  = "o.deleted_at IS NULL AND o.status != 'cancelled' AND o.tenant_id = ?";
$baseParams = [$tid];
if ($bid > 0) { $baseWhere .= " AND o.branch_id = ?"; $baseParams[] = $bid; }

// 1. Revenue by day
$stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as orders, COALESCE(SUM(total_amount),0) as revenue
    FROM orders o
    WHERE $baseWhere AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
");
$stmt->execute([...$baseParams, $days]);
$revenue_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenue_map = [];
foreach ($revenue_rows as $r) $revenue_map[$r['d']] = $r;
$revenue_data = [];
for ($i = $days-1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $revenue_data[] = [
        'date'    => date('M d', strtotime($date)),
        'orders'  => (int)($revenue_map[$date]['orders'] ?? 0),
        'revenue' => (float)($revenue_map[$date]['revenue'] ?? 0),
    ];
}

// 2. Top 8 items ★ WITH tenant isolation ★
$stmt2 = $pdo->prepare("
    SELECT oi.item_name, SUM(oi.qty) as qty, SUM(oi.qty * oi.unit_price) as revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE $baseWhere
    GROUP BY oi.item_name ORDER BY qty DESC LIMIT 8
");
$stmt2->execute($baseParams);
$top_items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// 3. Hourly distribution
$stmt3 = $pdo->prepare("
    SELECT HOUR(created_at) as hr, COUNT(*) as cnt
    FROM orders o
    WHERE $baseWhere
    GROUP BY HOUR(created_at)
");
$stmt3->execute($baseParams);
$hourly_rows = $stmt3->fetchAll(PDO::FETCH_ASSOC);
$hourly_map = [];
foreach ($hourly_rows as $r) $hourly_map[(int)$r['hr']] = (int)$r['cnt'];
$hourly_data = [];
for ($h = 0; $h < 24; $h++) $hourly_data[] = ['hour'=>$h,'count'=>$hourly_map[$h]??0];

// 4. Payment breakdown
$stmt4 = $pdo->prepare("
    SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total
    FROM orders o
    WHERE $baseWhere
    GROUP BY payment_method ORDER BY cnt DESC
");
$stmt4->execute($baseParams);
$payments = $stmt4->fetchAll(PDO::FETCH_ASSOC);

// 5. Summary ★ WITH date range filter ★
$stmt5 = $pdo->prepare("
    SELECT
        COUNT(*)                         as total_orders,
        COALESCE(SUM(total_amount),0)    as total_revenue,
        COALESCE(AVG(total_amount),0)    as avg_order,
        COUNT(CASE WHEN DATE(created_at)=CURDATE() THEN 1 END) as today_orders,
        COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total_amount END),0) as today_revenue
    FROM orders o
    WHERE $baseWhere AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
");
$stmt5->execute([...$baseParams, $days]);
$summary = $stmt5->fetch(PDO::FETCH_ASSOC);

// 6. ★ NEW: Category breakdown ★
$stmt6 = $pdo->prepare("
    SELECT mi.category, COUNT(oi.id) as orders, SUM(oi.qty) as qty, SUM(oi.qty * oi.unit_price) as revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN menu_items mi ON mi.id = oi.menu_item_id
    WHERE $baseWhere
    GROUP BY mi.category ORDER BY revenue DESC LIMIT 10
");
$stmt6->execute($baseParams);
$categories = $stmt6->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'         => true,
    'days'       => $days,
    'summary'    => $summary,
    'revenue'    => $revenue_data,
    'items'      => $top_items,
    'hourly'     => $hourly_data,
    'payments'   => $payments,
    'categories' => $categories,
]);
