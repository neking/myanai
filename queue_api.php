<?php
/**
 * NoodleHaus — Queue Display API  (Phase 5C)
 * Endpoint: /queue_api.php
 *
 * Returns current order queue for TV display
 * READ ONLY — no writes, no modifications
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');

$pdo = getPDO();

// SECURITY FIX: this file had NO tenant scoping and NO auth requirement at
// all - confirmed it was showing every tenant's customer names, order
// details, and table numbers mixed together to anyone who reached this
// public URL (meant for an unattended TV display, no login). Not currently
// linked from any page, but a reachable public URL with no filter is not a
// real security boundary. Now requires tenant_id explicitly.
$tid = (int)($_GET['tenant_id'] ?? 0);
if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

// NOW SERVING: ready orders (just completed)
$ready = $pdo->prepare("
    SELECT
        kq.order_id,
        o.customer_name,
        o.order_type,
        o.table_id,
        kq.status,
        kq.pushed_at,
        GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR ', ') AS items
    FROM kds_queue kq
    JOIN orders o ON o.id = kq.order_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.status = 'ready'
      AND o.deleted_at IS NULL
      AND kq.tenant_id = ?
      AND kq.pushed_at >= NOW() - INTERVAL 2 HOUR
    GROUP BY kq.id
    ORDER BY kq.pushed_at DESC
    LIMIT 8
");
$_tmp0 = $ready; $_tmp0->execute([$tid]); $ready = $_tmp0->fetchAll(PDO::FETCH_ASSOC);

// PREPARING: pending + preparing orders
$preparing = $pdo->prepare("
    SELECT
        kq.order_id,
        o.customer_name,
        o.order_type,
        o.table_id,
        kq.status,
        kq.pushed_at,
        GROUP_CONCAT(oi.item_name ORDER BY oi.id SEPARATOR ', ') AS items
    FROM kds_queue kq
    JOIN orders o ON o.id = kq.order_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.status IN ('pending','preparing')
      AND o.deleted_at IS NULL
      AND kq.tenant_id = ?
      AND kq.pushed_at >= NOW() - INTERVAL 2 HOUR
    GROUP BY kq.id
    ORDER BY kq.pushed_at ASC
    LIMIT 12
");
$_tmp1 = $preparing; $_tmp1->execute([$tid]); $preparing = $_tmp1->fetchAll(PDO::FETCH_ASSOC);

// SERVED: recently served (last 30 min)
$served = $pdo->prepare("
    SELECT
        kq.order_id,
        o.customer_name,
        o.order_type,
        kq.pushed_at
    FROM kds_queue kq
    JOIN orders o ON o.id = kq.order_id
    WHERE kq.status = 'served'
      AND o.deleted_at IS NULL
      AND kq.tenant_id = ?
      AND kq.pushed_at >= NOW() - INTERVAL 30 MINUTE
    ORDER BY kq.pushed_at DESC
    LIMIT 6
");
$_tmp2 = $served; $_tmp2->execute([$tid]); $served = $_tmp2->fetchAll(PDO::FETCH_ASSOC);

// Settings for branding — tenant's own storefront name, not the global default
$tRow = $pdo->prepare("SELECT name, settings FROM tenants WHERE id=?");
$tRow->execute([$tid]);
$tRowData = $tRow->fetch(PDO::FETCH_ASSOC);
$tSettingsData = json_decode($tRowData['settings'] ?? '{}', true) ?: [];
$storefront = $tSettingsData['storefront'] ?? [];
$settings = [
    'restaurant_name' => $storefront['store_name'] ?? $tRowData['name'] ?? 'NoodleHaus',
    'logo_url' => $storefront['logo_url'] ?? '',
];

echo json_encode([
    'ok'        => true,
    'ready'     => $ready,
    'preparing' => $preparing,
    'served'    => $served,
    'name'      => $settings['restaurant_name'] ?? 'NoodleHaus',
    'logo'      => $settings['logo_url'] ?? '',
    'time'      => date('H:i'),
], JSON_UNESCAPED_UNICODE);
