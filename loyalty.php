<?php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ★ Always get tenant_id from session or param ★
$tid = (int)($_SESSION['tenant_id'] ?? $_GET['tenant_id'] ?? 0);

function loyaltyConfig(PDO $pdo, int $tid): array {
    // site_settings is global (no tenant_id column)
    $stmt = $pdo->prepare("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_enabled','loyalty_stamps_required','loyalty_reward_label')");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

// ── GET card info by phone ──
if ($action === 'get') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone || !$tid) { echo json_encode(['ok'=>false,'msg'=>'Missing phone or tenant']); exit; }

    $cfg  = loyaltyConfig($pdo, $tid);
    $stmt = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=? AND tenant_id=?");
    $stmt->execute([$phone, $tid]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'       => true,
        'enabled'  => ($cfg['loyalty_enabled'] ?? '1') === '1',
        'required' => (int)($cfg['loyalty_stamps_required'] ?? 10),
        'reward'   => $cfg['loyalty_reward_label'] ?? 'Free item တစ်ခု',
        'stamps'   => $card ? (int)$card['stamps'] : 0,
        'card'     => $card ?: null,
    ]);
    exit;
}

// ── ADD stamp ──
if ($action === 'stamp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone    = trim($data['phone'] ?? '');
    $order_id = (int)($data['order_id'] ?? 0);
    $tid      = $tid ?: (int)($data['tenant_id'] ?? 0);
    if (!$phone || !$order_id || !$tid) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }

    $cfg      = loyaltyConfig($pdo, $tid);
    if (($cfg['loyalty_enabled'] ?? '1') !== '1') { echo json_encode(['ok'=>false,'msg'=>'Loyalty disabled']); exit; }
    $required = (int)($cfg['loyalty_stamps_required'] ?? 10);

    $pdo->prepare("INSERT INTO loyalty_cards (phone, tenant_id, stamps, last_order_id) VALUES (?,?,1,?)
        ON DUPLICATE KEY UPDATE stamps=stamps+1, last_order_id=?, updated_at=NOW()")
        ->execute([$phone, $tid, $order_id, $order_id]);

    $row      = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=? AND tenant_id=?");
    $row->execute([$phone, $tid]);
    $card     = $row->fetch(PDO::FETCH_ASSOC);
    $stamps   = (int)$card['stamps'];

    echo json_encode([
        'ok'         => true,
        'stamps'     => $stamps,
        'required'   => $required,
        'redeemable' => floor($stamps / $required),
        'progress'   => $stamps % $required,
    ]);
    exit;
}

// ── REDEEM reward ──
if ($action === 'redeem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone = trim($data['phone'] ?? '');
    $tid   = $tid ?: (int)($data['tenant_id'] ?? 0);
    if (!$phone || !$tid) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }

    $cfg      = loyaltyConfig($pdo, $tid);
    $required = (int)($cfg['loyalty_stamps_required'] ?? 10);

    $stmt = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=? AND tenant_id=?");
    $stmt->execute([$phone, $tid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['stamps'] < $required) { echo json_encode(['ok'=>false,'msg'=>'Not enough stamps']); exit; }

    $pdo->prepare("UPDATE loyalty_cards SET stamps=stamps-?, total_redeemed=total_redeemed+1, updated_at=NOW() WHERE phone=? AND tenant_id=?")
        ->execute([$required, $phone, $tid]);

    echo json_encode(['ok'=>true,'msg'=>'Redeemed!','stamps_deducted'=>$required]);
    exit;
}

// ── Admin: list all cards (all tenants) ──
if ($action === 'admin_list') {
    if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
    $filterTid = (int)($_GET['tenant_id'] ?? 0);
    $where = $filterTid ? "WHERE tenant_id=$filterTid" : '';
    $rows = $pdo->query("SELECT * FROM loyalty_cards $where ORDER BY stamps DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'cards'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
