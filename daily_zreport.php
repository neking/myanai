<?php
/**
 * daily_zreport.php — Daily Z-Report cron job
 * Run: php8.3 /var/www/myanai/daily_zreport.php
 * Cron: 0 22 * * * php8.3 /var/www/myanai/daily_zreport.php >> /var/log/myanai_zreport.log 2>&1
 */
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mailer.php';

$pdo  = getPDO();
$date = date('Y-m-d');
$dateDisplay = date('d M Y');

// Get all active tenants with owner email
$tenants = $pdo->query("SELECT id, name, owner_email, slug FROM tenants WHERE is_active=1 AND owner_email IS NOT NULL AND owner_email != ''")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tenants as $tenant) {
    $tid = $tenant['id'];

    // Get daily summary
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total_amount), 0) AS revenue,
            COALESCE(SUM(subtotal), 0) AS subtotal,
            COALESCE(SUM(delivery_fee), 0) AS delivery_fees,
            COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled,
            COUNT(CASE WHEN payment_method='kpay' THEN 1 END) AS kpay_orders,
            COUNT(CASE WHEN payment_method='wave' THEN 1 END) AS wave_orders,
            COUNT(CASE WHEN payment_method='cash' THEN 1 END) AS cash_orders
        FROM orders
        WHERE tenant_id=? AND DATE(created_at)=? AND deleted_at IS NULL
    ");
    $stmt->execute([$tid, $date]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get top 5 items
    $topStmt = $pdo->prepare("
        SELECT oi.item_name, SUM(oi.qty) AS qty, SUM(oi.qty*oi.unit_price) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id=oi.order_id
        WHERE o.tenant_id=? AND DATE(o.created_at)=? AND o.deleted_at IS NULL
        GROUP BY oi.item_name ORDER BY qty DESC LIMIT 5
    ");
    $topStmt->execute([$tid, $date]);
    $topItems = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $revenue  = number_format((int)$s['revenue']);
    $orders   = (int)$s['total_orders'];
    $avgOrder = $orders > 0 ? number_format((int)$s['revenue'] / $orders) : 0;

    // Build top items HTML
    $topHtml = '';
    foreach ($topItems as $i => $item) {
        $topHtml .= "<tr style='border-bottom:1px solid #f1f5f9'>
            <td style='padding:.5rem;color:#64748b'>" . ($i+1) . "</td>
            <td style='padding:.5rem;font-weight:500'>" . htmlspecialchars($item['item_name']) . "</td>
            <td style='padding:.5rem;text-align:center'>" . $item['qty'] . "</td>
            <td style='padding:.5rem;text-align:right'>" . number_format($item['revenue']) . " K</td>
        </tr>";
    }
    if (!$topHtml) $topHtml = "<tr><td colspan='4' style='padding:1rem;color:#94a3b8;text-align:center'>ယနေ့ orders မရှိဘဲ</td></tr>";

    $body = "
    <div style='font-family:sans-serif;max-width:500px;margin:0 auto;background:#f8fafc;padding:1.5rem'>
      <div style='background:#1565C0;color:#fff;padding:1.5rem;border-radius:12px 12px 0 0;text-align:center'>
        <div style='font-size:1.5rem;font-weight:700'>{$tenant['name']}</div>
        <div style='opacity:.85;margin-top:.3rem'>📊 Daily Z-Report — {$dateDisplay}</div>
      </div>
      <div style='background:#fff;padding:1.5rem;border-radius:0 0 12px 12px;box-shadow:0 2px 8px rgba(0,0,0,.08)'>

        <div style='display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem'>
          <div style='text-align:center;background:#f0fdf4;border-radius:10px;padding:1rem'>
            <div style='font-size:1.4rem;font-weight:700;color:#16a34a'>{$revenue} K</div>
            <div style='font-size:.75rem;color:#64748b;margin-top:.2rem'>Total Revenue</div>
          </div>
          <div style='text-align:center;background:#eff6ff;border-radius:10px;padding:1rem'>
            <div style='font-size:1.4rem;font-weight:700;color:#1565C0'>{$orders}</div>
            <div style='font-size:.75rem;color:#64748b;margin-top:.2rem'>Orders</div>
          </div>
          <div style='text-align:center;background:#fdf4ff;border-radius:10px;padding:1rem'>
            <div style='font-size:1.4rem;font-weight:700;color:#7c3aed'>{$avgOrder} K</div>
            <div style='font-size:.75rem;color:#64748b;margin-top:.2rem'>Avg Order</div>
          </div>
        </div>

        <div style='margin-bottom:1.2rem'>
          <div style='font-weight:600;margin-bottom:.5rem;color:#1e293b'>Payment Breakdown</div>
          <div style='display:flex;gap:.5rem;flex-wrap:wrap'>
            <span style='background:#f1f5f9;padding:.3rem .75rem;border-radius:99px;font-size:.82rem'>KPay: {$s['kpay_orders']}</span>
            <span style='background:#f1f5f9;padding:.3rem .75rem;border-radius:99px;font-size:.82rem'>Wave: {$s['wave_orders']}</span>
            <span style='background:#f1f5f9;padding:.3rem .75rem;border-radius:99px;font-size:.82rem'>Cash: {$s['cash_orders']}</span>
            <span style='background:#fef2f2;padding:.3rem .75rem;border-radius:99px;font-size:.82rem;color:#dc2626'>Cancelled: {$s['cancelled']}</span>
          </div>
        </div>

        <div>
          <div style='font-weight:600;margin-bottom:.5rem;color:#1e293b'>Top Items</div>
          <table style='width:100%;border-collapse:collapse;font-size:.85rem'>
            <tr style='background:#f8fafc'>
              <th style='padding:.5rem;text-align:left;color:#64748b'>#</th>
              <th style='padding:.5rem;text-align:left;color:#64748b'>Item</th>
              <th style='padding:.5rem;text-align:center;color:#64748b'>Qty</th>
              <th style='padding:.5rem;text-align:right;color:#64748b'>Revenue</th>
            </tr>
            {$topHtml}
          </table>
        </div>

        <div style='margin-top:1.5rem;text-align:center'>
          <a href='https://myanai.net/tenant.php' style='background:#1565C0;color:#fff;padding:.6rem 1.5rem;border-radius:8px;text-decoration:none;font-size:.88rem'>Dashboard ဝင်ကြည့်မည်</a>
        </div>
      </div>
      <div style='text-align:center;color:#94a3b8;font-size:.75rem;margin-top:1rem'>MyanAi POS — noreply@myanai.net</div>
    </div>";

    $result = sendMail($tenant['owner_email'], "📊 {$tenant['name']} — Daily Report ({$dateDisplay})", $body);
    echo date('H:i:s') . " [{$tenant['name']}] email to {$tenant['owner_email']}: " . ($result ? "✅ sent" : "❌ failed") . "\n";
}

echo date('H:i:s') . " Done — " . count($tenants) . " tenants processed\n";
