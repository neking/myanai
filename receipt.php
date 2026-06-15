<?php
require_once __DIR__ . '/db_connect.php';

$order_id  = (int)($_GET['id'] ?? 0);
$branchId  = (int)($_GET['branch_id'] ?? 0);
$branchName = '';
if (!$order_id) { echo 'No order ID'; exit; }

$pdo = getPDO();

// Fetch branch name if branch_id provided
if ($branchId > 0) {
    $br = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $br->execute([$branchId]);
    $branchName = $br->fetchColumn() ?: '';
}

$order = $pdo->prepare("SELECT o.*,
    COALESCE(o.customer_name,'') as customer_name,
    COALESCE(o.customer_phone,'') as customer_phone,
    COALESCE(o.delivery_address,'') as delivery_address,
    COALESCE(o.township,'') as township,
    COALESCE(o.table_id,'') as table_id,
    COALESCE(o.order_type,'delivery') as order_type
    FROM orders o WHERE o.id=?");
$order->execute([$order_id]);
$o = $order->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo 'Order not found'; exit; }

$items = $pdo->prepare("
    SELECT oi.*, GROUP_CONCAT(oim.label SEPARATOR ', ') as modifiers
    FROM order_items oi
    LEFT JOIN order_item_modifiers oim ON oim.order_item_id = oi.id
    WHERE oi.order_id=?
    GROUP BY oi.id
");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$settings = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('store_name','store_emoji','footer_phone','footer_address','loyalty_stamps_required')")->fetchAll(PDO::FETCH_KEY_PAIR);

$store_name = $settings['store_name'] ?? 'MyanAi POS';
$store_emoji = $settings['store_emoji'] ?? '🍜';
$store_phone = $settings['footer_phone'] ?? '';
$store_addr  = $settings['footer_address'] ?? '';

$subtotal  = array_sum(array_map(fn($i)=>$i['qty']*$i['unit_price'], $items));
$discount  = 0;
$delivery  = (float)($o['delivery_fee'] ?? 0);
$total     = (float)$o['total_amount'];
$pay_method = strtoupper($o['payment_method'] ?? '');
$ref       = str_pad($o['id'], 6, '0', STR_PAD_LEFT);
$date      = date('d M Y H:i', strtotime($o['created_at']));
$is_dine   = $o['order_type'] === 'dine_in';
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt #<?= $ref ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Courier New', monospace; font-size: 12px; width: 80mm; max-width: 80mm; padding: 4mm 4mm; background:#fff; color:#000; }
  .center { text-align:center; }
  .bold { font-weight:bold; }
  .lg { font-size:15px; }
  .xl { font-size:18px; }
  .divider { border-top:1px dashed #000; margin:6px 0; }
  .divider-solid { border-top:1px solid #000; margin:6px 0; }
  .row { display:flex; justify-content:space-between; margin:2px 0; }
  .row-item { margin:3px 0; }
  .indent { padding-left:8px; font-size:11px; color:#555; }
  .total-row { display:flex; justify-content:space-between; font-weight:bold; font-size:14px; margin:4px 0; }
  .barcode { font-size:28px; letter-spacing:2px; margin:4px 0; }
  @media print {
    body { width:80mm; }
    @page { margin:0; size:80mm auto; }
  }
</style>
</head>
<body>
<div class="center">
  <div class="xl bold"><?= htmlspecialchars($store_emoji.' '.$store_name) ?></div>
  <?php if($store_addr): ?><div style="font-size:10px;margin-top:2px"><?= htmlspecialchars($store_addr) ?></div><?php endif; ?>
  <?php if($store_phone): ?><div style="font-size:10px"><?= htmlspecialchars($store_phone) ?></div><?php endif; ?>
</div>

<div class="divider-solid"></div>

<div class="row"><span>Receipt:</span><span class="bold">#<?= $ref ?></span></div>
<div class="row"><span>Date:</span><span><?= $date ?></span></div>
<div class="row"><span>Payment:</span><span class="bold"><?= $pay_method ?></span></div>
<?php if($is_dine && $o['table_id']): ?>
<div class="row"><span>Table:</span><span class="bold"><?= htmlspecialchars($o['table_id']) ?></span></div>
<?php else: ?>
<div class="row"><span>Customer:</span><span><?= htmlspecialchars($o['customer_name']) ?></span></div>
<div class="row"><span>Phone:</span><span><?= htmlspecialchars($o['customer_phone']) ?></span></div>
<?php if($o['township']): ?><div class="row"><span>Area:</span><span><?= htmlspecialchars($o['township']) ?></span></div><?php endif; ?>
<?php endif; ?>

<div class="divider"></div>
<div class="bold" style="margin-bottom:4px">ITEMS</div>

<?php foreach($items as $item): ?>
<div class="row-item">
  <div class="row">
    <span><?= htmlspecialchars($item['item_name']) ?> x<?= $item['qty'] ?></span>
    <span><?= number_format($item['qty']*$item['unit_price']) ?> Ks</span>
  </div>
  <?php if(!empty($item['modifiers'])): ?>
  <div class="indent">+ <?= htmlspecialchars($item['modifiers']) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="divider"></div>
<div class="row"><span>Subtotal</span><span><?= number_format($subtotal) ?> Ks</span></div>
<?php if($discount > 0): ?>
<div class="row"><span>Discount <?= '' ?></span><span>-<?= number_format($discount) ?> Ks</span></div>
<?php endif; ?>
<?php if($delivery > 0): ?>
<div class="row"><span>Delivery</span><span><?= number_format($delivery) ?> Ks</span></div>
<?php endif; ?>
<div class="divider-solid"></div>
<div class="total-row"><span>TOTAL</span><span><?= number_format($total) ?> Ks</span></div>

<div class="divider"></div>
<div class="center">
  <div class="barcode"><?= '|||' . $ref . '|||' ?></div>
  <div style="font-size:10px">Order #<?= $ref ?></div>
</div>
<div class="divider"></div>
<div class="center" style="font-size:11px">
  <div>မှာယူပေးသောကြောင့် ကျေးဇူးတင်ပါသည်</div>
  <div>Thank you for your order!</div>
  <?php
  $req = (int)($settings['loyalty_stamps_required'] ?? 10);
  if($o['customer_phone']):
  ?>
  <div style="margin-top:4px;font-size:10px">⭐ Loyalty stamp <?= $req ?> ကြိမ်ဆို reward ရမည်</div>
  <?php endif; ?>
</div>
<br><br>
<script>window.onload=()=>{ window.print(); }</script>
</body>
</html>
