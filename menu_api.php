<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';
$pdo = getPDO();

// XSS Sanitization helper
function clean(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v ?? ''))), ENT_QUOTES, 'UTF-8');
}



// Error တွေကို JSON ထဲပါအောင် catch လုပ်
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// PHP fatal errors ပါ catch ဖို့
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'message'=>"PHP Error: $errstr (line $errline)"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'PHP Fatal: '.$e['message'].' line '.$e['line']]);
    }
});

header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Cache-Control: no-cache');



/* ── DB Connect ── */


/* ── Menu items ── */
try {
    // Support slug-based tenant detection
if (!empty($_GET['slug'])) {
    $row = getPDO()->prepare("SELECT id FROM tenants WHERE slug=? AND is_active=1");
    $row->execute([trim($_GET['slug'])]);
    $slugTenant = $row->fetchColumn();
    if ($slugTenant) $_GET['tenant_id'] = $slugTenant;
}
$tid = tenantId();

/* ═══════════════════════════════════════════
   WRITE ACTIONS (POST) — add/edit/toggle/delete menu items
   Requires valid tenant session or tenant_id param
═══════════════════════════════════════════ */
if(session_status()===PHP_SESSION_NONE) session_start();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Auth: must have tenant session OR valid tenant_id
    $authTid = 0;
    if (!empty($_SESSION['tenant_admin'])) {
        $authTid = (int)($_SESSION['tenant_id'] ?? 0);
    } elseif (!empty($_SESSION['admin'])) {
        $authTid = $tid; // super-admin acting on behalf
    }
    if (!$authTid) {
        echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
    }

    $b = json_decode(file_get_contents('php://input'), true) ?? [];

    /* ── ADD ITEM ── */
    if ($action === 'add_item') {
        $name     = clean($b['name'] ?? '');
        $price    = (int)($b['price'] ?? 0);
        $category = clean($b['category'] ?? 'Main');
        $emoji    = clean($b['emoji'] ?? '🍽');
        $desc     = clean($b['description'] ?? '');
        $stock    = (int)($b['stock_qty'] ?? 100);
        $active   = isset($b['is_active']) ? (int)$b['is_active'] : 1;
        $bid      = (int)($b['branch_id'] ?? 0);

        if (!$name || !$price) {
            echo json_encode(['ok'=>false,'msg'=>'Name and price required']); exit;
        }

        // Check plan limit
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE tenant_id=?");
        $countStmt->execute([$authTid]);
        $current = (int)$countStmt->fetchColumn();

        $planStmt = $pdo->prepare("SELECT plan FROM tenants WHERE id=?");
        $planStmt->execute([$authTid]);
        $plan = $planStmt->fetchColumn() ?: 'free';
        $limits = ['free'=>20,'basic'=>50,'pro'=>200,'enterprise'=>500];
        $limit = $limits[$plan] ?? 20;
        if ($current >= $limit) {
            echo json_encode(['ok'=>false,'msg'=>"Plan limit reached ($current/$limit). Upgrade to add more items."]); exit;
        }

        $popular  = (int)($b['is_popular']  ?? 0);
        $featured = (int)($b['is_featured'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO menu_items
            (tenant_id,branch_id,name,description,price,category,emoji,is_active,is_popular,is_featured,stock_qty,sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$authTid,$bid,$name,$desc,$price,$category,$emoji,$active,$popular,$featured,$stock,$current+1]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId(),'msg'=>'Item added']);
        exit;
    }

    /* ── EDIT ITEM ── */
    if ($action === 'edit_item') {
        $id         = (int)($b['id'] ?? 0);
        $name       = clean($b['name'] ?? '');
        $price      = (int)($b['price'] ?? 0);
        $category   = clean($b['category'] ?? 'Main');
        $emoji      = clean($b['emoji'] ?? '🍽');
        $desc       = clean($b['description'] ?? '');
        $stock      = (int)($b['stock_qty'] ?? 0);
        $active     = isset($b['is_active']) ? (int)$b['is_active'] : 1;
        $image_path = isset($b['image_path']) ? trim($b['image_path']) : null;

        if (!$id || !$name || !$price) {
            echo json_encode(['ok'=>false,'msg'=>'ID, name, price required']); exit;
        }

        if ($image_path !== null) {
            $stmt = $pdo->prepare("UPDATE menu_items
                SET name=?,description=?,price=?,category=?,emoji=?,is_active=?,is_popular=?,is_featured=?,stock_qty=?,image_path=?,updated_at=NOW()
                WHERE id=? AND tenant_id=?");
            $popular  = (int)($b['is_popular']  ?? 0);
            $featured = (int)($b['is_featured'] ?? 0);
            $stmt->execute([$name,$desc,$price,$category,$emoji,$active,$popular,$featured,$stock,$image_path,$id,$authTid]);
        } else {
            $stmt = $pdo->prepare("UPDATE menu_items
                SET name=?,description=?,price=?,category=?,emoji=?,is_active=?,is_popular=?,is_featured=?,stock_qty=?,updated_at=NOW()
                WHERE id=? AND tenant_id=?");
            $popular  = (int)($b['is_popular']  ?? 0);
            $featured = (int)($b['is_featured'] ?? 0);
            $stmt->execute([$name,$desc,$price,$category,$emoji,$active,$popular,$featured,$stock,$id,$authTid]);
        }
        echo json_encode(['ok'=>true,'msg'=>'Item updated']);
        exit;
    }

    /* ── TOGGLE ITEM STATUS ── */
    if ($action === 'toggle_item') {
        $id     = (int)($b['id'] ?? 0);
        $active = (int)($b['is_active'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID required']); exit; }
        $pdo->prepare("UPDATE menu_items SET is_active=?,updated_at=NOW() WHERE id=? AND tenant_id=?")
            ->execute([$active,$id,$authTid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── DELETE ITEM ── */
    if ($action === 'delete_item') {
        $id = (int)($b['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID required']); exit; }
        $pdo->prepare("DELETE FROM menu_items WHERE id=? AND tenant_id=?")->execute([$id,$authTid]);
        echo json_encode(['ok'=>true,'msg'=>'Item deleted']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}


    $stmt = $pdo->prepare("
        SELECT id, name, category, description, price, stock_qty, emoji, image_path, is_popular, is_featured
        FROM menu_items
        WHERE is_active = 1 AND tenant_id = :tid
        ORDER BY is_featured DESC, is_popular DESC, sort_order ASC, category, name
    ");
    $stmt->execute([':tid' => $tid]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    echo json_encode([
        'ok'      => false,
        'message' => 'menu_items query failed: ' . $e->getMessage(),
        'hint'    => 'Run seed_menu.sql in phpMyAdmin'
    ]);
    exit;
}

$items = array_map(fn($r) => [
    'id'         => (int)$r['id'],
    'name'       => $r['name'],
    'cat'        => $r['category'],
    'desc'       => $r['description'] ?? '',
    'price'      => (int)$r['price'],
    'stock'      => (int)$r['stock_qty'],
    'emoji'      => $r['emoji'] ?: '🍽️',
    'image_path'  => $r['image_path'] ?: null,
    'is_popular'  => (int)($r['is_popular'] ?? 0),
    'is_featured' => (int)($r['is_featured'] ?? 0),
], $rows);

/* ── Site settings ── */
$settings = [];
try {
    $sRows = $pdo->query(
        "SELECT setting_key, setting_value FROM site_settings"
    )->fetchAll();
    foreach ($sRows as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
} catch (PDOException $e) {
    // site_settings table မရှိသေးလျှင် empty array — not fatal
    $settings = [];
}

// Output buffering ရှင်းပြီး clean output ပို့
if (ob_get_level()) ob_end_clean();

// Per-tenant KBZPay settings
$tenantKpay = [];
try {
    $tRow = $pdo->prepare("SELECT settings FROM tenants WHERE id=?");
    $tRow->execute([$tid]);
    $tSettings = json_decode($tRow->fetchColumn() ?: '{}', true) ?: [];
    if (!empty($tSettings['kpay_merchant_id'])) $tenantKpay['kpay_merchant_id'] = $tSettings['kpay_merchant_id'];
    if (!empty($tSettings['kpay_qr_image']))    $tenantKpay['kpay_qr_image']    = $tSettings['kpay_qr_image'];
    if (!empty($tSettings['wave_merchant_id'])) $tenantKpay['wave_merchant_id'] = $tSettings['wave_merchant_id'];
    if (!empty($tSettings['wave_qr_image']))    $tenantKpay['wave_qr_image']    = $tSettings['wave_qr_image'];
} catch (\Exception $e) {}

// Merge tenant kpay into settings (tenant overrides global)
$settings = array_merge($settings, $tenantKpay);

$json = json_encode([
    'ok'       => true,
    'items'    => $items,
    'settings' => $settings,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

header('Content-Length: ' . strlen($json));
echo $json;
