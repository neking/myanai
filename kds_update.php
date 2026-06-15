<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$b = json_decode(file_get_contents('php://input'), true) ?? [];
$kdsId  = (int)($b['kds_id'] ?? 0);
$status = trim($b['status'] ?? '');
$note   = trim($b['note'] ?? '');
$reason = trim($b['cancel_reason'] ?? '');

if (!$kdsId) { echo json_encode(['ok'=>false,'msg'=>'No kds_id']); exit; }





// ── Save note ──
if ($note && !$status) {
    // Append note to order special_notes
    $row = $pdo->prepare("SELECT order_id FROM kds_queue WHERE id=:id")->execute([':id'=>$kdsId]);
    $row = $pdo->prepare("SELECT order_id FROM kds_queue WHERE id=:id");
    $row->execute([':id'=>$kdsId]);
    $r = $row->fetch();
    if ($r) {
        $pdo->prepare("UPDATE orders SET special_notes = CONCAT(IFNULL(special_notes,''), ' | Kitchen: ', :note) WHERE id=:oid")
            ->execute([':note'=>$note, ':oid'=>$r['order_id']]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── Cancel order ──
if ($status === 'cancelled') {
    $row = $pdo->prepare("SELECT order_id FROM kds_queue WHERE id=:id");
    $row->execute([':id'=>$kdsId]);
    $r = $row->fetch();
    if ($r) {
        $pdo->prepare("UPDATE orders SET status='cancelled', delete_reason=:reason WHERE id=:oid")
            ->execute([':reason'=>($reason ?: 'Cancelled by kitchen'), ':oid'=>$r['order_id']]);
        $pdo->prepare("UPDATE kds_queue SET status='served' WHERE id=:id")
            ->execute([':id'=>$kdsId]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── Status update ──
$valid = ['preparing','ready','served'];
if (!in_array($status, $valid)) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }

$timeCol = match($status) {
    'preparing' => ', started_at=NOW()',
    'ready'     => ', ready_at=NOW()',
    default     => '',
};

$pdo->prepare("UPDATE kds_queue SET status=:s{$timeCol} WHERE id=:id")
    ->execute([':s'=>$status, ':id'=>$kdsId]);

// Sync orders table for every status change
$orderStatus = match($status) {
    'preparing' => 'preparing',
    'ready'     => 'ready',
    'served'    => 'delivered',
    default     => null,
};

if ($orderStatus) {
    $row = $pdo->prepare("SELECT order_id FROM kds_queue WHERE id=:id");
    $row->execute([':id'=>$kdsId]);
    $r = $row->fetch();
    if ($r) {
        $pdo->prepare("UPDATE orders SET status=:s WHERE id=:oid")
            ->execute([':s'=>$orderStatus, ':oid'=>$r['order_id']]);
    }
}

echo json_encode(['ok'=>true, 'status'=>$status]);