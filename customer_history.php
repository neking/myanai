<?php
require_once __DIR__ . '/db_connect.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// ★ Require tenant session ★
if (empty($_SESSION['tenant_id']) && empty($_SESSION['admin'])) {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

$phone = trim($_GET['phone'] ?? '');
if (!$phone) { echo json_encode(['ok'=>false,'msg'=>'No phone']); exit; }

$pdo = getPDO();

// ★ Always filter by tenant_id ★
$tid = (int)($_SESSION['tenant_id'] ?? 0);

$orders = $pdo->prepare("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.order_type,
           o.table_id, o.created_at,
           GROUP_CONCAT(oi.item_name,'×',oi.qty ORDER BY oi.id SEPARATOR ', ') as items_summary,
           COUNT(oi.id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.customer_phone=? AND o.tenant_id=? AND o.deleted_at IS NULL
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 20
");
$orders->execute([$phone, $tid]);
$rows = $orders->fetchAll(PDO::FETCH_ASSOC);

$loyalty = $pdo->prepare("SELECT stamps, total_redeemed FROM loyalty_cards WHERE phone=? AND tenant_id=?");
$loyalty->execute([$phone, $tid]);
$loy = $loyalty->fetch(PDO::FETCH_ASSOC);

$stats = $pdo->prepare("
    SELECT COUNT(*) as total_orders,
           COALESCE(SUM(total_amount),0) as total_spent,
           COALESCE(AVG(total_amount),0) as avg_order,
           MAX(created_at) as last_visit
    FROM orders WHERE customer_phone=? AND tenant_id=? AND deleted_at IS NULL AND status!='cancelled'
");
$stats->execute([$phone, $tid]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'     => true,
    'phone'  => $phone,
    'stats'  => $s,
    'loyalty'=> $loy ?: ['stamps'=>0,'total_redeemed'=>0],
    'orders' => $rows,
]);
