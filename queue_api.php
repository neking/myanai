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
header('Access-Control-Allow-Origin: *');

$pdo = getPDO();

// NOW SERVING: ready orders (just completed)
$ready = $pdo->query("
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
      AND kq.pushed_at >= NOW() - INTERVAL 2 HOUR
    GROUP BY kq.id
    ORDER BY kq.pushed_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// PREPARING: pending + preparing orders
$preparing = $pdo->query("
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
      AND kq.pushed_at >= NOW() - INTERVAL 2 HOUR
    GROUP BY kq.id
    ORDER BY kq.pushed_at ASC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// SERVED: recently served (last 30 min)
$served = $pdo->query("
    SELECT
        kq.order_id,
        o.customer_name,
        o.order_type,
        kq.pushed_at
    FROM kds_queue kq
    JOIN orders o ON o.id = kq.order_id
    WHERE kq.status = 'served'
      AND o.deleted_at IS NULL
      AND kq.pushed_at >= NOW() - INTERVAL 30 MINUTE
    ORDER BY kq.pushed_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Settings for branding
$settings = $pdo->query("
    SELECT setting_key, setting_value FROM site_settings
    WHERE setting_key IN ('restaurant_name','logo_url')
")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

echo json_encode([
    'ok'        => true,
    'ready'     => $ready,
    'preparing' => $preparing,
    'served'    => $served,
    'name'      => $settings['restaurant_name'] ?? 'NoodleHaus',
    'logo'      => $settings['logo_url'] ?? '',
    'time'      => date('H:i'),
], JSON_UNESCAPED_UNICODE);
