<?php
/**
 * NoodleHaus — Promotions API (Phase 7A)
 * Actions: list, check, validate_code, create, update, toggle, usage
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = getPDO();
$action = trim($_GET['action'] ?? '');

function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function requireAdmin(): void { if(session_status()===PHP_SESSION_NONE)session_start(); if(empty($_SESSION['admin']))fail('Unauthorized',401); }


/* ── LIST (admin) ── */
if ($action === 'list') {
    requireAdmin();
    $rows = $pdo->query("SELECT * FROM promotions ORDER BY is_active DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    ok(['promotions' => $rows]);
}


/* ── CHECK (customer: find applicable promos for an order) ── */
if ($action === 'check') {
    $subtotal  = (int)($_GET['subtotal'] ?? 0);
    $category  = trim($_GET['category'] ?? '');
    $now       = date('H:i:s');
    $today     = strtolower(date('D')); // mon,tue,etc
    $dateNow   = date('Y-m-d');

    $rows = $pdo->query("
        SELECT * FROM promotions
        WHERE is_active = 1 AND code IS NULL
          AND (start_date IS NULL OR start_date <= '$dateNow')
          AND (end_date IS NULL OR end_date >= '$dateNow')
          AND (max_uses IS NULL OR used_count < max_uses)
        ORDER BY value DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $applicable = [];
    foreach ($rows as $p) {
        // Min order check
        if ($subtotal < (int)$p['min_order']) continue;

        // Happy hour check
        if ($p['happy_hour_start'] && $p['happy_hour_end']) {
            if ($now < $p['happy_hour_start'] || $now > $p['happy_hour_end']) continue;
        }

        // Day of week check
        if ($p['days_of_week']) {
            $days = array_map('trim', explode(',', $p['days_of_week']));
            if (!in_array($today, $days)) continue;
        }

        // Category check
        if ($p['applies_to'] === 'category' && $p['applies_category'] && $category) {
            if (strtolower($p['applies_category']) !== strtolower($category)) continue;
        }

        $discount = calcDiscount($p, $subtotal);
        $applicable[] = [
            'id'       => $p['id'],
            'name'     => $p['name'],
            'type'     => $p['type'],
            'discount' => $discount,
            'desc'     => promoDesc($p),
        ];
    }
    ok(['promotions' => $applicable]);
}


/* ── VALIDATE CODE ── */
if ($action === 'validate_code') {
    $code     = strtoupper(trim($_GET['code'] ?? ''));
    $subtotal = (int)($_GET['subtotal'] ?? 0);
    if (!$code) fail('Code required');

    $p = $pdo->prepare("SELECT * FROM promotions WHERE code = ? AND is_active = 1");
    $p->execute([$code]);
    $promo = $p->fetch(PDO::FETCH_ASSOC);
    if (!$promo) fail('Invalid promo code');

    $dateNow = date('Y-m-d');
    if ($promo['start_date'] && $promo['start_date'] > $dateNow) fail('Promo not started yet');
    if ($promo['end_date'] && $promo['end_date'] < $dateNow) fail('Promo expired');
    if ($promo['max_uses'] && $promo['used_count'] >= $promo['max_uses']) fail('Promo fully redeemed');
    if ($subtotal < (int)$promo['min_order']) fail('Min order ' . number_format($promo['min_order']) . ' MMK required');

    $discount = calcDiscount($promo, $subtotal);
    ok([
        'promo_id' => $promo['id'],
        'name'     => $promo['name'],
        'discount' => $discount,
        'desc'     => promoDesc($promo),
    ]);
}


/* ── CREATE (admin) ── */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($d['name'] ?? '');
    $type = trim($d['type'] ?? 'percent_off');
    if (!$name) fail('Name required');

    $code = !empty($d['code']) ? strtoupper(trim($d['code'])) : null;
    if ($code) {
        $exists = $pdo->prepare("SELECT id FROM promotions WHERE code = ?");
        $exists->execute([$code]);
        if ($exists->fetchColumn()) fail('Code already exists');
    }

    $pdo->prepare("
        INSERT INTO promotions (name, type, code, value, min_order, max_discount,
            applies_to, applies_category, free_item_id,
            start_date, end_date, happy_hour_start, happy_hour_end, days_of_week, max_uses)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $name, $type, $code,
        (float)($d['value'] ?? 0),
        (int)($d['min_order'] ?? 0),
        !empty($d['max_discount']) ? (int)$d['max_discount'] : null,
        $d['applies_to'] ?? 'all',
        $d['applies_category'] ?? null,
        !empty($d['free_item_id']) ? (int)$d['free_item_id'] : null,
        $d['start_date'] ?: null,
        $d['end_date'] ?: null,
        $d['happy_hour_start'] ?: null,
        $d['happy_hour_end'] ?: null,
        $d['days_of_week'] ?: null,
        !empty($d['max_uses']) ? (int)$d['max_uses'] : null,
    ]);
    ok(['promo_id' => (int)$pdo->lastInsertId()]);
}


/* ── UPDATE (admin) ── */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');

    $fields = []; $params = [];
    foreach (['name','type','value','min_order','max_discount','applies_to','applies_category',
              'start_date','end_date','happy_hour_start','happy_hour_end','days_of_week','max_uses'] as $f) {
        if (isset($d[$f])) { $fields[] = "$f = ?"; $params[] = $d[$f] === '' ? null : $d[$f]; }
    }
    if (empty($fields)) fail('Nothing to update');
    $params[] = $id;
    $pdo->prepare("UPDATE promotions SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    ok(['id' => $id]);
}


/* ── TOGGLE (admin) ── */
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("UPDATE promotions SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    ok();
}


/* ── RECORD USAGE (internal hook) ── */
if ($action === 'record_usage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $promoId = (int)($d['promo_id'] ?? 0);
    $orderId = (int)($d['order_id'] ?? 0);
    $discount = (int)($d['discount'] ?? 0);
    if (!$promoId || !$orderId) fail('promo_id and order_id required');

    $pdo->prepare("INSERT INTO promo_usage (promo_id, order_id, discount_amount) VALUES (?,?,?)")
        ->execute([$promoId, $orderId, $discount]);
    $pdo->prepare("UPDATE promotions SET used_count = used_count + 1 WHERE id = ?")->execute([$promoId]);
    ok();
}


/* ── USAGE STATS (admin) ── */
if ($action === 'usage') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    $where = $id ? "WHERE pu.promo_id = $id" : '';
    $rows = $pdo->query("
        SELECT pu.*, p.name AS promo_name, p.code
        FROM promo_usage pu
        JOIN promotions p ON p.id = pu.promo_id
        $where
        ORDER BY pu.created_at DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats = $pdo->query("
        SELECT COUNT(*) AS total_uses, COALESCE(SUM(discount_amount),0) AS total_discount
        FROM promo_usage" . ($id ? " WHERE promo_id = $id" : '')
    )->fetch(PDO::FETCH_ASSOC);

    ok(['usage' => $rows, 'stats' => $stats]);
}


/* ── HELPERS ── */
function calcDiscount(array $p, int $subtotal): int {
    return match($p['type']) {
        'percent_off' => min(
            (int)round($subtotal * (float)$p['value'] / 100),
            $p['max_discount'] ? (int)$p['max_discount'] : PHP_INT_MAX
        ),
        'fixed_off'   => min((int)$p['value'], $subtotal),
        'bogo'        => 0, // handled at item level
        'free_item'   => 0, // handled at item level
        'combo'       => (int)$p['value'],
        default       => 0,
    };
}

function promoDesc(array $p): string {
    $desc = match($p['type']) {
        'percent_off' => $p['value'] . '% off' . ($p['max_discount'] ? ' (max '.number_format($p['max_discount']).')' : ''),
        'fixed_off'   => number_format($p['value']) . ' MMK off',
        'bogo'        => 'Buy 1 Get 1 Free',
        'free_item'   => 'Free item included',
        'combo'       => 'Combo deal ' . number_format($p['value']) . ' MMK off',
        default       => '',
    };
    if ($p['min_order'] > 0) $desc .= ' (min ' . number_format($p['min_order']) . ')';
    if ($p['happy_hour_start']) $desc .= ' | ' . substr($p['happy_hour_start'],0,5) . '-' . substr($p['happy_hour_end'],0,5);
    return $desc;
}

fail('Unknown action');
