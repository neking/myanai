<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$pdo    = db();

function ok($d=[]){ echo json_encode(['ok'=>true]+$d); exit; }
function fail($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }

// ── List reviews (tenant) ──────────────────────────────────────
if ($action === 'list') {
    $tid  = (int)($_GET['tenant_id'] ?? 0);
    $limit= min((int)($_GET['limit'] ?? 20), 100);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $off  = ($page-1)*$limit;
    if (!$tid) fail('tenant_id required');

    $rows = $pdo->prepare("SELECT * FROM reviews WHERE tenant_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $rows->execute([$tid, $limit, $off]);
    $reviews = $rows->fetchAll(PDO::FETCH_ASSOC);

    $total = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE tenant_id=?");
    $total->execute([$tid]);
    $count = (int)$total->fetchColumn();

    $avg = $pdo->prepare("SELECT AVG(rating), COUNT(*) FROM reviews WHERE tenant_id=?");
    $avg->execute([$tid]);
    [$avgRating, $totalCount] = $avg->fetch(PDO::FETCH_NUM);

    ok(['reviews'=>$reviews, 'total'=>$count, 'avg_rating'=>round((float)$avgRating,1), 'total_count'=>(int)$totalCount]);
}

// ── Submit review (public — from ordering page) ────────────────
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid    = (int)($b['tenant_id'] ?? 0);
    $rating = (int)($b['rating'] ?? 0);
    $name   = trim($b['customer_name'] ?? '');
    $phone  = trim($b['customer_phone'] ?? '');
    $comment= trim($b['comment'] ?? '');
    $orderId= (int)($b['order_id'] ?? 0) ?: null;

    if (!$tid)   fail('tenant_id required');
    if ($rating < 1 || $rating > 5) fail('Rating must be 1-5');

    // Duplicate check — same phone + same order
    if ($phone && $orderId) {
        $dup = $pdo->prepare("SELECT id FROM reviews WHERE tenant_id=? AND customer_phone=? AND order_id=?");
        $dup->execute([$tid, $phone, $orderId]);
        if ($dup->fetchColumn()) fail('Already reviewed this order');
    }

    $pdo->prepare("INSERT INTO reviews (tenant_id,order_id,customer_name,customer_phone,rating,comment) VALUES (?,?,?,?,?,?)")
        ->execute([$tid, $orderId, $name ?: null, $phone ?: null, $rating, $comment ?: null]);

    ok(['msg' => 'Review submitted. Thank you!']);
}

// ── Toggle public/private ──────────────────────────────────────
if ($action === 'toggle_public' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireTenantAuth();
    $b  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($b['id'] ?? 0);
    $tid= (int)($_SESSION['tenant_id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("UPDATE reviews SET is_public=NOT is_public WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
    ok(['msg'=>'Updated']);
}

// ── Delete review ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireTenantAuth();
    $b  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($b['id'] ?? 0);
    $tid= (int)($_SESSION['tenant_id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("DELETE FROM reviews WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
    ok(['msg'=>'Deleted']);
}

// ── Public reviews (for ordering page) ────────────────────────
if ($action === 'public') {
    $tid   = (int)($_GET['tenant_id'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    if (!$tid) fail('tenant_id required');
    $rows = $pdo->prepare("SELECT customer_name, rating, comment, created_at FROM reviews WHERE tenant_id=? AND is_public=1 ORDER BY created_at DESC LIMIT ?");
    $rows->execute([$tid, $limit]);
    $reviews = $rows->fetchAll(PDO::FETCH_ASSOC);
    $avg = $pdo->prepare("SELECT AVG(rating), COUNT(*) FROM reviews WHERE tenant_id=? AND is_public=1");
    $avg->execute([$tid]);
    [$avgRating, $cnt] = $avg->fetch(PDO::FETCH_NUM);
    ok(['reviews'=>$reviews, 'avg_rating'=>round((float)$avgRating,1), 'count'=>(int)$cnt]);
}

fail('Unknown action');
