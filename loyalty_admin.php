<?php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ── Update loyalty card ──
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $origPhone = trim($d['orig_phone'] ?? '');
    $phone     = trim($d['phone'] ?? '');
    $stamps    = max(0, (int)($d['stamps'] ?? 0));
    $redeemed  = max(0, (int)($d['total_redeemed'] ?? 0));
    if (!$origPhone || !$phone) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }
    $pdo->prepare("UPDATE loyalty_cards SET phone=?, stamps=?, total_redeemed=?, updated_at=NOW() WHERE phone=?")
        ->execute([$phone, $stamps, $redeemed, $origPhone]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Delete loyalty card ──
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone = trim($d['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false,'msg'=>'No phone']); exit; }
    $pdo->prepare("DELETE FROM loyalty_cards WHERE phone=?")->execute([$phone]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Preview orders for bulk delete ──
if ($action === 'preview_orders') {
    $phone = trim($_GET['phone'] ?? '');
    $from  = $_GET['from'] ?? '';
    $to    = $_GET['to'] ?? '';
    $sql = "SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL";
    $params = [];
    if ($phone) { $sql .= " AND customer_phone=?"; $params[] = $phone; }
    if ($from)  { $sql .= " AND DATE(created_at)>=?"; $params[] = $from; }
    if ($to)    { $sql .= " AND DATE(created_at)<=?"; $params[] = $to; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['ok'=>true, 'count'=>(int)$stmt->fetchColumn()]);
    exit;
}

// ── Bulk delete orders ──
if ($action === 'bulk_delete_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone  = trim($d['phone'] ?? '');
    $from   = $d['from'] ?? '';
    $to     = $d['to'] ?? '';
    $reason = trim($d['reason'] ?? 'Bulk delete by admin');
    $sql = "UPDATE orders SET deleted_at=NOW(), deleted_by='admin', delete_reason=? WHERE deleted_at IS NULL";
    $params = [$reason];
    if ($phone) { $sql .= " AND customer_phone=?"; $params[] = $phone; }
    if ($from)  { $sql .= " AND DATE(created_at)>=?"; $params[] = $from; }
    if ($to)    { $sql .= " AND DATE(created_at)<=?"; $params[] = $to; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['ok'=>true, 'deleted'=>$stmt->rowCount()]);
    exit;
}

// ── KDS pending count ──
if ($action === 'kds_pending') {
    $count = $pdo->query("SELECT COUNT(*) FROM kds_queue WHERE status IN ('pending','preparing','ready')")->fetchColumn();
    echo json_encode(['ok'=>true, 'count'=>(int)$count]);
    exit;
}

// ── KDS clear ──
if ($action === 'kds_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("UPDATE kds_queue SET status='served' WHERE status IN ('pending','preparing','ready')");
    $stmt->execute();
    echo json_encode(['ok'=>true, 'cleared'=>$stmt->rowCount()]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
