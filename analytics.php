<?php
require_once __DIR__ . '/db_connect.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$pdo  = getPDO();
$days = max(7, min(30, (int)($_GET['days'] ?? 7)));
$bid  = (int)($_GET['branch_id'] ?? 0);
$tid  = (int)($_GET['tenant_id'] ?? 0);
$bWhere = $bid > 0 ? " AND branch_id = $bid" : "";
$tWhere = $tid > 0 ? " AND tenant_id = $tid" : "";
$bFilter = $bWhere . $tWhere;

// 1. Revenue by day
$revenue_rows = $pdo->query("
    SELECT DATE(created_at) as d, COUNT(*) as orders, COALESCE(SUM(total_amount),0) as revenue
    FROM orders WHERE deleted_at IS NULL AND status != 'cancelled'{$bFilter}
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
")->fetchAll(PDO::FETCH_ASSOC);

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

// 2. Top 8 items
$top_items = $pdo->query("
    SELECT oi.item_name, SUM(oi.qty) as qty, SUM(oi.qty * oi.unit_price) as revenue
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
    WHERE o.deleted_at IS NULL AND o.status != 'cancelled'
    GROUP BY oi.item_name ORDER BY qty DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Hourly distribution
$hourly_rows = $pdo->query("
    SELECT HOUR(created_at) as hr, COUNT(*) as cnt
    FROM orders WHERE deleted_at IS NULL AND status != 'cancelled'{$bFilter}
    GROUP BY HOUR(created_at)
")->fetchAll(PDO::FETCH_ASSOC);
$hourly_map = [];
foreach ($hourly_rows as $r) $hourly_map[(int)$r['hr']] = (int)$r['cnt'];
$hourly_data = [];
for ($h = 0; $h < 24; $h++) $hourly_data[] = ['hour'=>$h,'count'=>$hourly_map[$h]??0];

// 4. Payment breakdown
$payments = $pdo->query("
    SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total
    FROM orders WHERE deleted_at IS NULL AND status != 'cancelled'{$bFilter}
    GROUP BY payment_method ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Summary
$summary = $pdo->query("
    SELECT COUNT(*) as total_orders,
           COALESCE(SUM(total_amount),0) as total_revenue,
           COALESCE(AVG(total_amount),0) as avg_order
    FROM orders WHERE deleted_at IS NULL AND status != 'cancelled'{$bFilter}
")->fetch(PDO::FETCH_ASSOC);

echo json_encode(['ok'=>true,'days'=>$days,'summary'=>$summary,
    'revenue'=>$revenue_data,'items'=>$top_items,'hourly'=>$hourly_data,'payments'=>$payments]);
