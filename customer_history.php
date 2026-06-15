<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$phone = trim($_GET['phone'] ?? '');
if (!$phone) { echo json_encode(['ok'=>false,'msg'=>'No phone']); exit; }

$pdo = getPDO();

$orders = $pdo->prepare("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.order_type,
           o.table_id, o.created_at,
           GROUP_CONCAT(oi.item_name, ' x', oi.qty ORDER BY oi.id SEPARATOR ', ') as items_summary,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.customer_phone = ? AND o.deleted_at IS NULL
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 20
");
$orders->execute([$phone]);
$rows = $orders->fetchAll(PDO::FETCH_ASSOC);

$loyalty = $pdo->prepare("SELECT stamps, total_redeemed FROM loyalty_cards WHERE phone=?");
$loyalty->execute([$phone]);
$loy = $loyalty->fetch(PDO::FETCH_ASSOC);

$stats = $pdo->prepare("
    SELECT COUNT(*) as total_orders,
           COALESCE(SUM(total_amount),0) as total_spent,
           COALESCE(AVG(total_amount),0) as avg_order
    FROM orders WHERE customer_phone=? AND deleted_at IS NULL AND status != 'cancelled'
");
$stats->execute([$phone]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'     => true,
    'phone'  => $phone,
    'stats'  => $s,
    'loyalty'=> $loy ?: ['stamps'=>0,'total_redeemed'=>0],
    'orders' => $rows,
]);
