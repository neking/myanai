<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ── GET card info by phone ──
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = trim($_GET['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false,'msg'=>'No phone']); exit; }

    $cfg = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_enabled','loyalty_stamps_required','loyalty_reward_label')")->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=?");
    $stmt->execute([$phone]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'       => true,
        'enabled'  => ($cfg['loyalty_enabled'] ?? '1') === '1',
        'required' => (int)($cfg['loyalty_stamps_required'] ?? 10),
        'reward'   => $cfg['loyalty_reward_label'] ?? 'Free item တစ်ခု',
        'card'     => $card ?: null,
        'stamps'   => $card ? (int)$card['stamps'] : 0,
    ]);
    exit;
}

// ── ADD stamp (called after order placed) ──
if ($action === 'stamp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone    = trim($data['phone'] ?? '');
    $order_id = (int)($data['order_id'] ?? 0);
    if (!$phone || !$order_id) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }

    $cfg = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_enabled','loyalty_stamps_required')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($cfg['loyalty_enabled'] ?? '1') !== '1') { echo json_encode(['ok'=>false,'msg'=>'Loyalty disabled']); exit; }
    $required = (int)($cfg['loyalty_stamps_required'] ?? 10);

    // upsert
    $pdo->prepare("INSERT INTO loyalty_cards(phone,stamps,last_order_id) VALUES(?,1,?)
        ON DUPLICATE KEY UPDATE stamps=stamps+1, last_order_id=?, updated_at=NOW()")
        ->execute([$phone, $order_id, $order_id]);

    $card = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=?");
    $card->execute([$phone]);
    $row = $card->fetch(PDO::FETCH_ASSOC);
    $stamps   = (int)$row['stamps'];
    $redeemable = floor($stamps / $required);

    echo json_encode([
        'ok'         => true,
        'stamps'     => $stamps,
        'required'   => $required,
        'redeemable' => $redeemable,
        'progress'   => $stamps % $required,
    ]);
    exit;
}

// ── REDEEM reward ──
if ($action === 'redeem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone = trim($data['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok'=>false,'msg'=>'No phone']); exit; }

    $cfg = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_stamps_required')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $required = (int)($cfg['loyalty_stamps_required'] ?? 10);

    $stmt = $pdo->prepare("SELECT * FROM loyalty_cards WHERE phone=?");
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['stamps'] < $required) { echo json_encode(['ok'=>false,'msg'=>'Not enough stamps']); exit; }

    $pdo->prepare("UPDATE loyalty_cards SET stamps=stamps-?, total_redeemed=total_redeemed+1, updated_at=NOW() WHERE phone=?")
        ->execute([$required, $phone]);

    echo json_encode(['ok'=>true,'msg'=>'Redeemed!','stamps_deducted'=>$required]);
    exit;
}

// ── Admin: list all cards ──
if ($action === 'admin_list') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
    $rows = $pdo->query("SELECT * FROM loyalty_cards ORDER BY stamps DESC, updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'cards'=>$rows]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
