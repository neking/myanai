<?php
require_once __DIR__ . '/db_connect.php';
session_start();
if (empty($_SESSION['admin'])) { header('HTTP/1.1 403 Forbidden'); echo 'Unauthorized'; exit; }

$pdo  = getPDO();
$date = $_GET['date'] ?? date('Y-m-d');
$dateLabel = date('d M Y', strtotime($date));

$summary = $pdo->prepare("
    SELECT COUNT(*) as total_orders,
           COALESCE(SUM(total_amount),0) as total_revenue,
           COALESCE(AVG(total_amount),0) as avg_order,
           COUNT(CASE WHEN status='cancelled' THEN 1 END) as cancelled,
           COUNT(CASE WHEN order_type='dine_in' THEN 1 END) as dine_in,
           COUNT(CASE WHEN order_type!='dine_in' THEN 1 END) as delivery
    FROM orders WHERE DATE(created_at)=? AND deleted_at IS NULL
");
$summary->execute([$date]);
$s = $summary->fetch(PDO::FETCH_ASSOC);

$top_items = $pdo->prepare("
    SELECT oi.item_name, SUM(oi.qty) as qty, SUM(oi.qty*oi.unit_price) as revenue
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    WHERE DATE(o.created_at)=? AND o.deleted_at IS NULL AND o.status!='cancelled'
    GROUP BY oi.item_name ORDER BY qty DESC LIMIT 8
");
$top_items->execute([$date]);
$items = $top_items->fetchAll(PDO::FETCH_ASSOC);

$payments = $pdo->prepare("
    SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total
    FROM orders WHERE DATE(created_at)=? AND deleted_at IS NULL AND status!='cancelled'
    GROUP BY payment_method
");
$payments->execute([$date]);
$pays = $payments->fetchAll(PDO::FETCH_ASSOC);

$hourly = $pdo->prepare("
    SELECT HOUR(created_at) as hr, COUNT(*) as cnt, SUM(total_amount) as rev
    FROM orders WHERE DATE(created_at)=? AND deleted_at IS NULL AND status!='cancelled'
    GROUP BY HOUR(created_at) ORDER BY hr
");
$hourly->execute([$date]);
$hours = $hourly->fetchAll(PDO::FETCH_ASSOC);

$settings = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('store_name','store_emoji')")->fetchAll(PDO::FETCH_KEY_PAIR);
$store = ($settings['store_emoji']??'🍜').' '.($settings['store_name']??'NoodleHaus');
?><!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<title>Daily Report — <?= $dateLabel ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Segoe UI', sans-serif; font-size:13px; color:#1a1209; background:#fff; padding:20px; max-width:800px; margin:auto; }
.header { text-align:center; border-bottom:2px solid #e84c2b; padding-bottom:12px; margin-bottom:20px; }
.store-name { font-size:22px; font-weight:700; color:#e84c2b; }
.report-title { font-size:14px; color:#666; margin-top:4px; }
.stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:20px; }
.stat-box { background:#fdf6f0; border:1px solid #f0d0b0; border-radius:8px; padding:12px; text-align:center; }
.stat-n { font-size:24px; font-weight:700; color:#e84c2b; }
.stat-l { font-size:11px; color:#888; margin-top:2px; }
.section { margin-bottom:20px; }
.sec-title { font-size:13px; font-weight:600; color:#333; border-left:3px solid #e84c2b; padding-left:8px; margin-bottom:10px; }
table { width:100%; border-collapse:collapse; }
th { background:#fdf6f0; padding:6px 10px; text-align:left; font-size:11px; color:#888; font-weight:600; text-transform:uppercase; }
td { padding:6px 10px; border-bottom:1px solid #f0f0f0; }
.text-right { text-align:right; }
.total-row td { font-weight:700; background:#fff8f0; border-top:2px solid #e84c2b; }
.footer { text-align:center; margin-top:20px; font-size:11px; color:#999; border-top:1px dashed #ddd; padding-top:12px; }
.no-print { margin-bottom:16px; display:flex; gap:8px; }
.btn { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-size:13px; }
.btn-print { background:#e84c2b; color:#fff; }
.btn-date { background:#f0f0f0; color:#333; }
@media print { .no-print { display:none; } body { padding:0; } }
</style>
</head><body>

<div class="no-print">
  <input type="date" value="<?= $date ?>" onchange="window.location='daily_report.php?date='+this.value" class="btn btn-date">
  <button onclick="window.print()" class="btn btn-print">🖨️ Print / Save PDF</button>
  <button onclick="window.close()" class="btn btn-date">✕ Close</button>
</div>

<div class="header">
  <div class="store-name"><?= htmlspecialchars($store) ?></div>
  <div class="report-title">Daily Closing Report — <?= $dateLabel ?></div>
  <div style="font-size:11px;color:#999;margin-top:4px">Generated: <?= date('d M Y H:i') ?></div>
</div>

<div class="stats-grid">
  <div class="stat-box"><div class="stat-n"><?= $s['total_orders'] ?></div><div class="stat-l">Total Orders</div></div>
  <div class="stat-box"><div class="stat-n"><?= number_format($s['total_revenue']) ?></div><div class="stat-l">Revenue (Ks)</div></div>
  <div class="stat-box"><div class="stat-n"><?= number_format($s['avg_order']) ?></div><div class="stat-l">Avg Order (Ks)</div></div>
  <div class="stat-box"><div class="stat-n"><?= $s['dine_in'] ?></div><div class="stat-l">Dine-in</div></div>
  <div class="stat-box"><div class="stat-n"><?= $s['delivery'] ?></div><div class="stat-l">Delivery</div></div>
  <div class="stat-box"><div class="stat-n"><?= $s['cancelled'] ?></div><div class="stat-l">Cancelled</div></div>
</div>

<?php if($items): ?>
<div class="section">
  <div class="sec-title">Top Selling Items</div>
  <table>
    <thead><tr><th>Item</th><th class="text-right">Qty</th><th class="text-right">Revenue (Ks)</th></tr></thead>
    <tbody>
    <?php foreach($items as $i): ?>
    <tr><td><?= htmlspecialchars($i['item_name']) ?></td><td class="text-right"><?= $i['qty'] ?></td><td class="text-right"><?= number_format($i['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="total-row"><td>TOTAL</td><td class="text-right"><?= array_sum(array_column($items,'qty')) ?></td><td class="text-right"><?= number_format(array_sum(array_column($items,'revenue'))) ?> Ks</td></tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if($pays): ?>
<div class="section">
  <div class="sec-title">Payment Breakdown</div>
  <table>
    <thead><tr><th>Method</th><th class="text-right">Orders</th><th class="text-right">Amount (Ks)</th></tr></thead>
    <tbody>
    <?php foreach($pays as $p): ?>
    <tr><td><?= strtoupper($p['payment_method']) ?></td><td class="text-right"><?= $p['cnt'] ?></td><td class="text-right"><?= number_format($p['total']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if($hours): ?>
<div class="section">
  <div class="sec-title">Hourly Breakdown</div>
  <table>
    <thead><tr><th>Hour</th><th class="text-right">Orders</th><th class="text-right">Revenue (Ks)</th></tr></thead>
    <tbody>
    <?php foreach($hours as $h): ?>
    <tr><td><?= str_pad($h['hr'],2,'0',STR_PAD_LEFT) ?>:00 – <?= str_pad($h['hr'],2,'0',STR_PAD_LEFT) ?>:59</td><td class="text-right"><?= $h['cnt'] ?></td><td class="text-right"><?= number_format($h['rev']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if(!$s['total_orders']): ?>
<div style="text-align:center;padding:2rem;color:#999">
  <?= $dateLabel ?> မှာ order မရှိသေး
</div>
<?php endif; ?>

<div class="footer">
  <?= htmlspecialchars($store) ?> · <?= $dateLabel ?> · NoodleHaus POS
</div>
</body></html>
