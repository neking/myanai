<?php
require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();
$settings = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('store_name','store_emoji')")->fetchAll(PDO::FETCH_KEY_PAIR);
$store = ($settings['store_emoji']??'🍜').' '.($settings['store_name']??'NoodleHaus');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($store) ?> — Order Tracker</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8f4f0;min-height:100vh;padding:1rem}
.wrap{max-width:480px;margin:auto}
.header{text-align:center;padding:1.5rem 0 1rem}
.store-name{font-size:1.3rem;font-weight:700;color:#1a0f05}
.search-box{background:#fff;border-radius:14px;padding:1.2rem;margin-bottom:1rem;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.search-box input{width:100%;padding:.7rem 1rem;border:1.5px solid #ddd;border-radius:10px;font-size:1rem;margin-bottom:.6rem;outline:none}
.search-box input:focus{border-color:#e84c2b}
.search-box button{width:100%;padding:.75rem;background:#e84c2b;color:#fff;border:none;border-radius:10px;font-size:1rem;cursor:pointer;font-weight:600}
.search-box button:hover{background:#d03d20}
.order-card{background:#fff;border-radius:12px;padding:1rem;margin-bottom:.75rem;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.order-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
.order-ref{font-weight:700;font-size:.95rem;color:#1a0f05}
.order-date{font-size:.78rem;color:#888}
.order-items{font-size:.82rem;color:#555;margin-bottom:.5rem;line-height:1.5}
.order-bottom{display:flex;justify-content:space-between;align-items:center}
.order-amount{font-weight:700;color:#e84c2b}
.status-badge{font-size:.75rem;padding:.25rem .75rem;border-radius:20px;font-weight:600}
.status-pending{background:#fff3cd;color:#856404}
.status-preparing{background:#cfe2ff;color:#084298}
.status-ready{background:#d1fae5;color:#065f46}
.status-delivered{background:#e2e3e5;color:#41464b}
.status-cancelled{background:#f8d7da;color:#721c24}
.empty{text-align:center;padding:2rem;color:#888;font-size:.9rem}
.loyalty-box{background:#fff8e8;border:1.5px solid #f0a500;border-radius:12px;padding:1rem;margin-bottom:1rem;text-align:center}
.stamps-row{display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-top:.5rem}
.stamp{width:28px;height:28px;border-radius:50%;border:2px solid #f0a500;display:flex;align-items:center;justify-content:center;font-size:.8rem}
.stamp.filled{background:#f0a500}
.back-btn{display:block;text-align:center;margin-bottom:1rem;color:#e84c2b;font-size:.88rem;cursor:pointer;text-decoration:none}
#results{display:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div class="store-name"><?= htmlspecialchars($store) ?></div>
    <div style="font-size:.85rem;color:#888;margin-top:.25rem">Order Tracker</div>
  </div>

  <div class="search-box" id="search-box">
    <div style="font-size:.88rem;color:#555;margin-bottom:.6rem">ဖုန်းနံပါတ်ဖြင့် orders စစ်ဆေးပါ</div>
    <input type="tel" id="track-phone" placeholder="09xxxxxxxxx" inputmode="numeric">
    <button onclick="trackOrders()">🔍 Orders ကြည့်မည်</button>
  </div>

  <div id="results">
    <a class="back-btn" onclick="document.getElementById('results').style.display='none';document.getElementById('search-box').style.display='block'">← ပြန်သွား</a>
    <div id="loyalty-section"></div>
    <div id="orders-list"></div>
  </div>
</div>

<script>
const STATUS_LABEL = {
  pending:   '⏳ Pending',
  preparing: '👨‍🍳 Preparing',
  ready:     '✅ Ready',
  delivered: '📦 Delivered',
  cancelled: '❌ Cancelled',
};
const STATUS_CLASS = {
  pending:'status-pending', preparing:'status-preparing',
  ready:'status-ready', delivered:'status-delivered', cancelled:'status-cancelled'
};

async function trackOrders() {
  const phone = document.getElementById('track-phone').value.trim();
  if (!phone) { alert('ဖုန်းနံပါတ် ထည့်ပါ'); return; }

  const [histRes, loyRes] = await Promise.all([
    fetch('customer_history.php?phone=' + encodeURIComponent(phone)).then(r=>r.json()),
    fetch('loyalty.php?action=get&phone=' + encodeURIComponent(phone)).then(r=>r.json()),
  ]);

  document.getElementById('search-box').style.display = 'none';
  document.getElementById('results').style.display = 'block';

  // Loyalty section
  const loyEl = document.getElementById('loyalty-section');
  if (loyRes.ok && loyRes.enabled) {
    const stamps = loyRes.stamps || 0;
    const req = loyRes.required || 10;
    const progress = stamps % req;
    loyEl.innerHTML = `
      <div class="loyalty-box">
        <div style="font-weight:600;font-size:.95rem">🎟 Loyalty Stamp Card</div>
        <div style="font-size:.8rem;color:#856404;margin-top:.2rem">${stamps} stamps · ${req-progress} more for reward</div>
        <div class="stamps-row">
          ${Array.from({length:req},(_,i)=>`<div class="stamp ${i<progress?'filled':''}">
            ${i<progress?'⭐':''}</div>`).join('')}
        </div>
      </div>`;
  } else { loyEl.innerHTML = ''; }

  // Orders list
  const listEl = document.getElementById('orders-list');
  if (!histRes.ok || !histRes.orders?.length) {
    listEl.innerHTML = '<div class="empty">📋 Orders မရှိသေး</div>';
    return;
  }
  listEl.innerHTML = histRes.orders.map(o => `
    <div class="order-card">
      <div class="order-top">
        <span class="order-ref">#${String(o.id).padStart(6,'0')}</span>
        <span class="status-badge ${STATUS_CLASS[o.status]||'status-pending'}">${STATUS_LABEL[o.status]||o.status}</span>
      </div>
      <div class="order-items">${o.items_summary||'—'}</div>
      <div class="order-bottom">
        <span class="order-date">${o.created_at?.substring(0,16)||''}</span>
        <span class="order-amount">K${parseInt(o.total_amount).toLocaleString()}</span>
      </div>
    </div>
  `).join('');
}

document.getElementById('track-phone').addEventListener('keydown', e => {
  if (e.key === 'Enter') trackOrders();
});
</script>
</body>
</html>
