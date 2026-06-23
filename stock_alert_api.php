<?php
/**
 * Low Stock Alert System
 * Check stock levels and send email alerts
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mailer.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$pdo    = getPDO();
$action = $_GET['action'] ?? 'check';

function getTenantEmail(PDO $pdo, int $tenantId): ?string {
    $row = $pdo->prepare("SELECT owner_email, owner_name, name FROM tenants WHERE id=?");
    $row->execute([$tenantId]);
    return $row->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── CHECK LOW STOCK (tenant facing) ──
if ($action === 'check') {
    if (session_status()===PHP_SESSION_NONE) session_start();
    $tid = (int)($_GET['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

    $threshold = (int)($_GET['threshold'] ?? 5);

    $stmt = $pdo->prepare("
        SELECT id, name, category, stock_qty, emoji
        FROM menu_items
        WHERE tenant_id=? AND is_active=1 AND stock_qty <= ?
        ORDER BY stock_qty ASC
    ");
    $stmt->execute([$tid, $threshold]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out_of_stock = array_filter($items, fn($i) => (int)$i['stock_qty'] === 0);
    $low_stock    = array_filter($items, fn($i) => (int)$i['stock_qty'] > 0);

    echo json_encode([
        'ok'           => true,
        'threshold'    => $threshold,
        'total_alerts' => count($items),
        'out_of_stock' => array_values($out_of_stock),
        'low_stock'    => array_values($low_stock),
    ]);
    exit;
}

// ── SEND ALERT EMAIL (cron or manual) ──
if ($action === 'send_alerts') {
    if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

    $tid = (int)($_GET['tenant_id'] ?? 0);
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

    $tenant = getTenantEmail($pdo, $tid);
    if (!$tenant) { echo json_encode(['ok'=>false,'msg'=>'Tenant not found']); exit; }

    // Get low stock items
    $stmt = $pdo->prepare("
        SELECT name, category, stock_qty, emoji
        FROM menu_items
        WHERE tenant_id=? AND is_active=1 AND stock_qty <= 5
        ORDER BY stock_qty ASC
    ");
    $stmt->execute([$tid]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo json_encode(['ok'=>true,'msg'=>'No low stock items','sent'=>false]);
        exit;
    }

    // Build email
    $rows = '';
    foreach ($items as $item) {
        $status = (int)$item['stock_qty'] === 0 ? '🔴 OUT' : '🟡 LOW';
        $rows .= "<tr>
            <td style='padding:8px;border-bottom:1px solid #eee'>{$item['emoji']} {$item['name']}</td>
            <td style='padding:8px;border-bottom:1px solid #eee;color:#666'>{$item['category']}</td>
            <td style='padding:8px;border-bottom:1px solid #eee;font-weight:700;color:" . ((int)$item['stock_qty']===0?'#dc2626':'#d97706') . "'>{$item['stock_qty']}</td>
            <td style='padding:8px;border-bottom:1px solid #eee'>{$status}</td>
        </tr>";
    }

    $html = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
        <h2 style='color:#dc2626'>⚠️ Low Stock Alert — {$tenant['name']}</h2>
        <p>The following items need restocking:</p>
        <table style='width:100%;border-collapse:collapse;background:#f9fafb;border-radius:8px'>
            <thead>
                <tr style='background:#fee2e2'>
                    <th style='padding:10px;text-align:left'>Item</th>
                    <th style='padding:10px;text-align:left'>Category</th>
                    <th style='padding:10px;text-align:left'>Stock</th>
                    <th style='padding:10px;text-align:left'>Status</th>
                </tr>
            </thead>
            <tbody>$rows</tbody>
        </table>
        <p style='margin-top:1.5rem;color:#666;font-size:.9rem'>
            Login to <a href='https://myanai.net/tenant.php'>MyanAi POS</a> to update stock levels.
        </p>
    </div>";

    try {
        $mail = getMailer();
        $mail->addAddress($tenant['owner_email'], $tenant['owner_name']);
        $mail->Subject = "⚠️ Low Stock Alert — {$tenant['name']} (" . count($items) . " items)";
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->send();

        // Log alert sent
        error_log("Stock alert sent to {$tenant['owner_email']} for {$tenant['name']} - " . count($items) . " items");
        echo json_encode(['ok'=>true,'sent'=>true,'items_count'=>count($items)]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>'Email failed: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
