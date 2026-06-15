<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';
$pdo = getPDO();


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
header('Access-Control-Allow-Origin: *');
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
    $stmt = $pdo->prepare("
        SELECT id, name, category, description, price, stock_qty, emoji, image_path
        FROM menu_items
        WHERE is_active = 1 AND tenant_id = :tid
        ORDER BY sort_order ASC, category, name
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
    'image_path' => $r['image_path'] ?: null,
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
