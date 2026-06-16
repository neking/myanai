

// ── CSRF Token ──
// CSRF_TOKEN is set by inline PHP snippet in admin.php
const CSRF_TOKEN = window.__CSRF_TOKEN || '';

// Tenant context init
if(window.__IS_TENANT && window.__TENANT_ID > 0) {
  window._currentTenant = window.__TENANT_ID;
  console.log('Tenant login:', window.__TENANT_NAME, '(ID:', window.__TENANT_ID, ', Plan:', window.__TENANT_PLAN, ')');
}

/* ── Trial Expiry Banner ── */
(function checkTrialExpiry() {
  if (!window.__IS_TENANT || !window.__PLAN_EXPIRES) return;
  const expires  = new Date(window.__PLAN_EXPIRES);
  const now      = new Date();
  const diffDays = Math.ceil((expires - now) / 86400000);
  if (diffDays > 7) return;

  let bgColor, icon, msg;
  if (diffDays <= 0) {
    bgColor = '#7f1d1d'; icon = '🔴';
    msg = 'သင့် plan သက်တမ်းကုန်သွားပြီ။ ဝန်ဆောင်မှုဆက်လက်ရရှိရန် Plan upgrade လုပ်ပါ။';
  } else if (diffDays <= 3) {
    bgColor = '#7c2d12'; icon = '🟠';
    msg = `သတိပေးချက်: သင့် plan သက်တမ်း <strong>${diffDays} ရက်</strong> သာကျန်တော့သည်။`;
  } else {
    bgColor = '#713f12'; icon = '🟡';
    msg = `သင့် plan သက်တမ်း <strong>${diffDays} ရက်</strong> ကျန်တော့သည် (${expires.toLocaleDateString('en-GB')})`;
  }

  const banner = document.createElement('div');
  banner.id = 'trial-expiry-banner';
  banner.style.cssText = `position:fixed;top:0;left:0;right:0;z-index:9999;background:${bgColor};color:#fff;padding:.55rem 1rem;display:flex;align-items:center;gap:.75rem;font-size:.84rem;box-shadow:0 2px 8px rgba(0,0,0,.35)`;
  banner.innerHTML = `
    <span style="font-size:1.1rem">${icon}</span>
    <span style="flex:1">${msg}</span>
    <button onclick="showPage('upgrade')" style="background:#fff;color:${bgColor};border:none;padding:3px 12px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.78rem;white-space:nowrap">⬆ Upgrade</button>
    <button onclick="document.getElementById('trial-expiry-banner').remove()" style="background:none;border:none;color:rgba(255,255,255,.8);font-size:1.2rem;cursor:pointer;line-height:1;padding:0 2px">✕</button>
  `;
  document.body.prepend(banner);
})();

// ── Branch Selector ──
let currentBranchId = 0; // 0 = all branches
(function loadBranchSelector() {
  fetch('branch_api.php?action=list' + (window.__IS_TENANT && window.__TENANT_ID ? '&tenant_id='+window.__TENANT_ID : '')).then(r=>r.json()).then(d=>{
    if (!d.ok) return;
    const selectors = ['branch-select','branch-select-ops'].map(id=>document.getElementById(id)).filter(Boolean);
    if(!selectors.length) return;
    selectors.forEach(sel => {
      while(sel.options.length > 1) sel.remove(1);
      d.branches.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = (b.is_active?'🏢 ':'🔴 ') + b.name;
        opt.dataset.tenant = b.tenant_id || 1;
        opt.style.background = '#2a1f14';
        opt.style.color = '#fff';
        sel.appendChild(opt);
      });
      // Sync selector to current branch after populating
      if(window._currentBranch > 0) sel.value = window._currentBranch;
    });
  }).catch(()=>{});
})();

function toggleNavSection(id) {
  const body = document.getElementById('section-' + id);
  const chev = document.getElementById('chev-' + id);
  if (!body) return;
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : '';
  if (chev) chev.textContent = isOpen ? '›' : '▾';
}

function switchBranch(id) {
  currentBranchId = parseInt(id) || 0;
  window._currentBranch = currentBranchId;
  // Get tenant_id from option data
  const sel = document.getElementById('branch-select');
  const opt = sel?.querySelector('option[value="'+id+'"]');
  window._currentTenant = parseInt(opt?.dataset?.tenant || 0);
  // Reload orders
  ['branch-select','branch-select-ops'].forEach(sid=>{const s=document.getElementById(sid);if(s)s.value=id;});
  if(typeof loadOrders === 'function') loadOrders();
  if(typeof loadStats === 'function') loadStats();
  toast('🏢 ' + (currentBranchId ? (sel?.selectedOptions[0]?.textContent||'Branch') : 'All Branches'));
}

// ── KPay QR Upload ──────────────────────────────
async function uploadKpayQr(input) {
  if (!input.files[0]) return;
  const fd = new FormData();
  fd.append('img', input.files[0]);
  const btn = document.getElementById('kpay-qr-remove-btn');
  const preview = document.getElementById('kpay-qr-preview');
  const noImg = document.getElementById('kpay-qr-no-img');
  const status = document.getElementById('kpay-qr-status');
  if (status) status.textContent = 'Uploading...';
  try {
    const r = await fetch('admin.php?api=upload_kpay_qr', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      document.getElementById('kpay-qr-img').src = '/' + d.path + '?t=' + Date.now();
      if (preview) preview.style.display = 'block';
      if (btn) btn.style.display = 'inline-block';
      if (noImg) noImg.style.display = 'none';
      if (status) status.textContent = 'QR image saved';
    } else { alert('Upload failed: ' + d.msg); }
  } catch(e) { alert('Upload error: ' + e); }
}

async function removeKpayQr() {
  if (!confirm('KPay QR image ဖျက်မှာ သေချာပါသလား?')) return;
  const r = await fetch('site_settings.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({kpay_qr_image: ''})
  });
  const d = await r.json();
  if (d.ok) {
    document.getElementById('kpay-qr-img').src = '';
    document.getElementById('kpay-qr-preview').style.display = 'none';
    document.getElementById('kpay-qr-remove-btn').style.display = 'none';
    document.getElementById('kpay-qr-no-img').style.display = 'block';
    document.getElementById('kpay-qr-input').value = '';
  }
}

// ── KPay QR loadSettings populate ───────────────
function populateKpayQrPreview(s) {
  const kpayUrl = s.kpay_qr_image || '';
  const kpayImg = document.getElementById('kpay-qr-img');
  const kpayPreview = document.getElementById('kpay-qr-preview');
  const kpayRemoveBtn = document.getElementById('kpay-qr-remove-btn');
  const kpayNoImg = document.getElementById('kpay-qr-no-img');
  if (kpayImg && kpayUrl) {
    kpayImg.src = '/' + kpayUrl + '?t=' + Date.now();
    if (kpayPreview) kpayPreview.style.display = 'block';
    if (kpayRemoveBtn) kpayRemoveBtn.style.display = 'inline-block';
    if (kpayNoImg) kpayNoImg.style.display = 'none';
  } else {
    if (kpayNoImg) kpayNoImg.style.display = 'block';
    if (kpayPreview) kpayPreview.style.display = 'none';
  }
}

/* ═══════════════════════════════════════
   STATE
═══════════════════════════════════════ */
let allItems   = [];
let activeCat  = 'All';
let restockId  = null;
let toastTmr;

/* ═══════════════════════════════════════
   API HELPER
═══════════════════════════════════════ */
async function api(action, body = null) {
  const opts = { method: body ? 'POST' : 'GET', headers: {} };
  if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  try {
    // Append branch/tenant filter if selected
  let apiUrl = 'admin.php?api=' + action;
  if(window._currentBranch > 0) apiUrl += '&branch_id=' + window._currentBranch;
  if(window._currentTenant > 0) apiUrl += '&tenant_id=' + window._currentTenant;
  const r = await fetch(apiUrl, opts);
    if (r.status === 401) { location.href = 'admin.php'; return { ok: false, msg: 'Session expired' }; }
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) {
      const txt = await r.text();
      console.error('Non-JSON:', txt.slice(0,200));
      return { ok: false, msg: 'Server error — check PHP logs' };
    }
    return r.json();
  } catch(e) {
    console.error('api() error:', e);
    return { ok: false, msg: e.message };
  }
}

const fmt = n => '$' + Number(n).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:2});

/* ═══════════════════════════════════════
   LOGIN / LOGOUT
═══════════════════════════════════════ */
async function doLogin() {
  const user = document.getElementById('l-user')?.value.trim();
  const pass = document.getElementById('l-pass')?.value.trim();
  const err  = document.getElementById('l-err');
  const d = await api('login', { user, pass });
  if (d.ok) { location.reload(); }
  else { err.textContent = d.msg; err.style.display = 'block'; }
}

async function doLogout() {
  try { await api('logout'); } catch(e) { /* ignore */ }
  location.href = 'admin.php';
}

/* ═══════════════════════════════════════
   PAGE NAV
═══════════════════════════════════════ */
function showPage(page) {
  // Platform admin pages only
  const PAGES = ['dashboard','tenants','revenue','upgrades','plans','landing','demo','announce','saas','settings'];

  PAGES.forEach(p => {
    const pageEl = document.getElementById('page-'+p);
    const navEl  = document.getElementById('nav-'+p);
    if(pageEl) pageEl.style.display = p===page ? 'block' : 'none';
    if(navEl)  navEl.classList.toggle('active', p===page);
  });

  // Update page title
  const titles = {
    dashboard:'Platform Dashboard', tenants:'Tenants', revenue:'Revenue',
    upgrades:'Upgrade Requests', plans:'Plans & Pricing', landing:'Landing Page',
    demo:'Demo Control', announce:'Announcements', saas:'SaaS Dashboard', settings:'Settings'
  };
  const ptEl = document.querySelector('.page-title');
  if(ptEl) ptEl.textContent = titles[page] || page;

  // Load page data
  if(page==='dashboard')  loadPlatformDashboard();
  if(page==='tenants')    loadTenants();
  if(page==='revenue')    loadRevenue();
  if(page==='upgrades')   loadUpgradeRequests();
  if(page==='plans')      loadPlans();
  if(page==='landing')    loadLandingPage();
  if(page==='demo')       loadDemoInfo();
  if(page==='announce')   loadAnnouncementPage();
  if(page==='saas')       loadSaas();
  if(page==='settings')   loadSettings();
}

/* ── Sidebar open/close (tablet/mobile) ── */
function openSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebar-overlay');
  const cl  = document.getElementById('sidebar-close-btn');
  if (sb) sb.classList.add('open');
  if (ov) ov.classList.add('open');
  if (cl) cl.style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebar-overlay');
  const cl  = document.getElementById('sidebar-close-btn');
  if (sb) sb.classList.remove('open');
  if (ov) ov.classList.remove('open');
  if (cl) cl.style.display = 'none';
  document.body.style.overflow = '';
}

/* ═══════════════════════════════════════
   DASHBOARD
═══════════════════════════════════════ */
async function filterPending() {
  showPage('orders');
  setTimeout(() => {
    document.querySelectorAll('#orders-table tr').forEach(row => {
      row.style.background = row.textContent.includes('pending') ? '#fff3cd' : '';
    });
  }, 600);
}


// ══ ANALYTICS ══
let _charts = {};
function fmtK(v){v=parseFloat(v);if(v>=1000000)return(v/1000000).toFixed(1)+'M';if(v>=1000)return(v/1000).toFixed(1)+'K';return v.toLocaleString();}
function destroyChart(id){if(_charts[id]){_charts[id].destroy();delete _charts[id];}}



// ══ LOYALTY CARD EDIT ══
function openLoyaltyEdit(phone, stamps, redeemed) {
  document.getElementById('loy-edit-phone-orig').value = phone;
  document.getElementById('loy-edit-phone').value = phone;
  document.getElementById('loy-edit-stamps').value = stamps;
  document.getElementById('loy-edit-redeemed').value = redeemed;
  document.getElementById('loy-edit-modal').style.display = 'flex';
}

async function saveLoyaltyEdit() {
  const origPhone = document.getElementById('loy-edit-phone-orig').value;
  const phone    = document.getElementById('loy-edit-phone').value.trim();
  const stamps   = parseInt(document.getElementById('loy-edit-stamps').value) || 0;
  const redeemed = parseInt(document.getElementById('loy-edit-redeemed').value) || 0;
  if (!phone) { toast('Phone ထည့်ပါ','err'); return; }
  const r = await fetch('loyalty_admin.php?action=update', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({orig_phone: origPhone, phone, stamps, total_redeemed: redeemed})
  });
  const d = await r.json();
  if (d.ok) {
    toast('✅ Saved');
    document.getElementById('loy-edit-modal').style.display = 'none';
    loadLoyaltyCards();
  } else { toast(d.msg || 'Error','err'); }
}

async function deleteLoyaltyCard() {
  const phone = document.getElementById('loy-edit-phone-orig').value;
  if (!confirm('Loyalty card (' + phone + ') ဖျက်မှာ သေချာပါသလား?')) return;
  const r = await fetch('loyalty_admin.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({phone})
  });
  const d = await r.json();
  if (d.ok) {
    toast('🗑 Deleted');
    document.getElementById('loy-edit-modal').style.display = 'none';
    loadLoyaltyCards();
  } else { toast(d.msg || 'Error','err'); }
}

// ══ BULK DELETE ORDERS ══
function openBulkDelete() {
  document.getElementById('bulk-preview').innerHTML = '';
  document.getElementById('bulk-delete-modal').style.display = 'flex';
}

async function previewBulkDelete() {
  const phone  = document.getElementById('bulk-phone').value.trim();
  const from   = document.getElementById('bulk-date-from').value;
  const to     = document.getElementById('bulk-date-to').value;
  let url = 'loyalty_admin.php?action=preview_orders';
  if (phone) url += '&phone=' + encodeURIComponent(phone);
  if (from)  url += '&from=' + from;
  if (to)    url += '&to=' + to;
  const d = await fetch(url).then(r=>r.json());
  const el = document.getElementById('bulk-preview');
  if (d.ok) {
    el.innerHTML = `<div style="background:#fff3cd;border-radius:6px;padding:.5rem .75rem;color:#856404">⚠️ ${d.count} orders found — delete မည်</div>`;
  } else { el.innerHTML = '<div style="color:red">' + d.msg + '</div>'; }
}

async function confirmBulkDelete() {
  const phone  = document.getElementById('bulk-phone').value.trim();
  const from   = document.getElementById('bulk-date-from').value;
  const to     = document.getElementById('bulk-date-to').value;
  const reason = document.getElementById('bulk-reason').value.trim() || 'Bulk delete by admin';
  if (!confirm('Orders ဖျက်မှာ သေချာပါသလား? ပြန်မရနိုင်ပါ')) return;
  const r = await fetch('loyalty_admin.php?action=bulk_delete_orders', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({phone, from, to, reason})
  });
  const d = await r.json();
  if (d.ok) {
    toast('🗑 ' + d.deleted + ' orders deleted');
    document.getElementById('bulk-delete-modal').style.display = 'none';
    loadOrders(); loadStats();
  } else { toast(d.msg || 'Error','err'); }
}

// ══ KDS CLEAR ══
async function openKDSClear() {
  const d = await fetch('loyalty_admin.php?action=kds_pending').then(r=>r.json());
  document.getElementById('kds-pending-count').innerHTML =
    d.ok ? `<span style="color:#e84c2b">${d.count} pending tickets</span>` : 'Error';
  document.getElementById('kds-clear-modal').style.display = 'flex';
}

async function clearKDSQueue() {
  const r = await fetch('loyalty_admin.php?action=kds_clear', {method:'POST'});
  const d = await r.json();
  if (d.ok) {
    toast('🧹 KDS queue cleared (' + d.cleared + ' tickets)');
    document.getElementById('kds-clear-modal').style.display = 'none';
    loadStats();
  } else { toast(d.msg || 'Error','err'); }
}


// ══ SPLIT BILL ══
let _splitOrderId = null;
let _splitTotal = 0;

function openSplitBill(orderId, total) {
  _splitOrderId = orderId;
  _splitTotal = total;
  document.getElementById('split-order-info').textContent = 
    'Order #' + String(orderId).padStart(6,'0') + ' — Total: ' + parseInt(total).toLocaleString() + ' Ks';
  document.getElementById('split-amount-row').style.display = 'none';
  document.getElementById('split-secondary').value = '';
  document.getElementById('split-amount').value = '';
  document.getElementById('split-bill-modal').style.display = 'flex';
}

document.addEventListener('change', function(e) {
  if (e.target.id === 'split-secondary') {
    document.getElementById('split-amount-row').style.display = e.target.value ? 'block' : 'none';
    if (e.target.value) {
      document.getElementById('split-amount').placeholder = 
        'e.g. ' + Math.round(_splitTotal / 2).toLocaleString();
    }
  }
});

async function confirmSplitBill() {
  const primary  = document.getElementById('split-primary').value;
  const secondary = document.getElementById('split-secondary').value;
  const amount   = parseFloat(document.getElementById('split-amount').value) || 0;

  if (secondary && amount <= 0) {
    toast('Secondary payment amount ထည့်ပါ', 'err'); return;
  }
  if (secondary && amount > _splitTotal) {
    toast('Amount သည် total ထက် မကျော်ရ', 'err'); return;
  }

  const r = await fetch('table_api.php?action=close_table', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      order_id: _splitOrderId,
      payment_method: primary,
      split_method: secondary,
      split_amount: amount,
    })
  });
  const d = await r.json();
  if (d.ok) {
    toast('✅ Table closed · ' + (secondary ? primary+'+'+secondary : primary));
    document.getElementById('split-bill-modal').style.display = 'none';
    loadTables(); loadOrders(); loadStats();
  } else { toast(d.msg || 'Error', 'err'); }
}


// ══ STAFF MANAGEMENT ══
async function loadStaffList() {
  const d = await fetch('waiter_api.php?action=staff_list').then(r=>r.json());
  const el = document.getElementById('staff-list-admin');
  if (!el) return;
  if (!d.ok || !d.staff?.length) { el.innerHTML='<div style="color:var(--muted);font-size:.85rem">Staff မရှိသေး</div>'; return; }
  el.innerHTML = `<table style="width:100%;font-size:.82rem;border-collapse:collapse">
    <tr style="color:var(--muted);font-size:.75rem"><th style="text-align:left;padding:.25rem 0">Name</th><th>PIN</th><th>Role</th><th>Status</th><th></th></tr>
    ${d.staff.map(s=>`<tr style="border-top:.5px solid var(--border)">
      <td style="padding:.35rem 0">${s.name}</td>
      <td style="text-align:center">${s.pin}</td>
      <td style="text-align:center">${s.role}</td>
      <td style="text-align:center"><span style="color:${s.is_active?'#3b6d11':'#a32d2d'}">${s.is_active?'Active':'Inactive'}</span></td>
      <td style="text-align:right">
        <button onclick="toggleStaff(${s.id},${s.is_active})" style="font-size:.72rem;padding:2px 8px;border:1px solid var(--border);border-radius:4px;cursor:pointer;background:none">${s.is_active?'Deactivate':'Activate'}</button>
        <button onclick="deleteStaff(${s.id},'${s.name}')" style="font-size:.72rem;padding:2px 8px;border:1px solid #dc3545;border-radius:4px;cursor:pointer;background:none;color:#dc3545;margin-left:4px">Delete</button>
      </td>
    </tr>`).join('')}
  </table>`;
}

async function addStaff() {
  const name = document.getElementById('new-staff-name')?.value.trim();
  const pin  = document.getElementById('new-staff-pin')?.value.trim();
  if (!name || !pin) { toast('Name + PIN ထည့်ပါ','err'); return; }
  if (!/^\d{4,6}$/.test(pin)) { toast('PIN 4-6 digits ဖြစ်ရမည်','err'); return; }
  const r = await fetch('waiter_api.php?action=add_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({name, pin, role:'waiter'})
  }).then(r=>r.json());
  if (r.ok) {
    toast('✅ '+name+' added');
    document.getElementById('new-staff-name').value='';
    document.getElementById('new-staff-pin').value='';
    loadStaffList();
  } else { toast(r.msg||'Error','err'); }
}

async function toggleStaff(id, isActive) {
  const r = await fetch('waiter_api.php?action=toggle_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id, is_active: isActive ? 0 : 1})
  }).then(r=>r.json());
  if (r.ok) { toast('✅ Staff updated'); loadStaffList(); }
  else { toast(r.msg||'Error','err'); }
}

async function deleteStaff(id, name) {
  if (!confirm(name+' ကို delete မှာ သေချာလား?')) return;
  const r = await fetch('waiter_api.php?action=delete_staff',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({id})
  }).then(r=>r.json());
  if (r.ok) { toast('🗑 Deleted'); loadStaffList(); }
  else { toast(r.msg||'Error','err'); }
}

async function loadLoyaltyCards() {
  const r = await fetch('loyalty.php?action=admin_list');
  const d = await r.json();
  const el = document.getElementById('loyalty-cards-list');
  if (!el) return;
  if (!d.ok || !d.cards.length) { el.innerHTML = '<div style="color:var(--muted)">Stamp cards မရှိသေး</div>'; return; }
  el.innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:.8rem">' +
    '<thead><tr style="border-bottom:1px solid #eee"><th style="text-align:left;padding:.3rem">Phone</th><th>Stamps</th><th>Redeemed</th><th>Last order</th></tr></thead>' +
    '<tbody>' + d.cards.map(c => `<tr style="border-bottom:.5px solid #f0f0f0">
      <td style="padding:.35rem 0">${c.phone}</td>
      <td style="text-align:center">⭐ ${c.stamps}</td>
      <td style="text-align:center">🎁 ${c.total_redeemed}</td>
      <td style="text-align:center;color:#999">#${c.last_order_id||'—'}</td>
      <td><button onclick="openLoyaltyEdit('${c.phone}',${c.stamps},${c.total_redeemed})" style="padding:2px 8px;font-size:.75rem;background:none;border:1px solid #ddd;border-radius:4px;cursor:pointer">✏️</button></td>
    </tr>`).join('') + '</tbody></table>';
}


function printReceipt(orderId) {
  const w = window.open('receipt.php?id=' + orderId, '_blank', 'width=420,height=650,scrollbars=yes');
  if (!w) alert('Popup blocked — please allow popups for this site');
}


function openDailyReport() {
  const today = new Date().toISOString().split('T')[0];
  window.open('daily_report.php?date='+today, '_blank', 'width=900,height=700,scrollbars=yes');
}

function openCustHistory() {
  document.getElementById('cust-history-modal').style.display = 'flex';
  document.getElementById('cust-history-result').innerHTML = '';
  document.getElementById('cust-phone-input').focus();
}

async function loadCustHistory() {
  const phone = document.getElementById('cust-phone-input').value.trim();
  if (!phone) { toast('Phone number ထည့်ပါ','err'); return; }
  const el = document.getElementById('cust-history-result');
  el.innerHTML = '<div style="color:var(--muted);text-align:center;padding:1rem">Loading...</div>';
  const r = await fetch('customer_history.php?phone=' + encodeURIComponent(phone));
  const d = await r.json();
  if (!d.ok) { el.innerHTML = '<div style="color:red">Error: '+d.msg+'</div>'; return; }

  const s = d.stats;
  const loy = d.loyalty;
  el.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:1rem">
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#e84c2b">${s.total_orders}</div>
        <div style="font-size:.75rem;color:#888">Orders</div>
      </div>
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.1rem;font-weight:700;color:#e84c2b">K${parseInt(s.total_spent).toLocaleString()}</div>
        <div style="font-size:.75rem;color:#888">Total Spent</div>
      </div>
      <div style="background:#fdf6f0;border-radius:8px;padding:.6rem;text-align:center">
        <div style="font-size:1.1rem;font-weight:700;color:#f0a500">⭐ ${loy.stamps}</div>
        <div style="font-size:.75rem;color:#888">Stamps</div>
      </div>
    </div>
    ${d.orders.length === 0 ? '<div style="text-align:center;color:var(--muted);padding:1rem">Order မရှိသေး</div>' :
      '<div style="font-size:.82rem;font-weight:600;margin-bottom:.5rem">Order History</div>' +
      d.orders.map(o => `
        <div style="border:0.5px solid #eee;border-radius:8px;padding:.6rem .8rem;margin-bottom:.4rem">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:600;font-size:.85rem">#${String(o.id).padStart(6,'0')}</span>
            <span style="font-size:.78rem;color:#888">${o.created_at.substring(0,16)}</span>
          </div>
          <div style="font-size:.78rem;color:#555;margin:.2rem 0">${o.items_summary||'—'}</div>
          <div style="display:flex;justify-content:space-between">
            <span style="font-size:.8rem;color:#888">${o.payment_method?.toUpperCase()||''} · ${o.order_type==='dine_in'?'Dine-in':'Delivery'}</span>
            <span style="font-weight:600;font-size:.85rem;color:#e84c2b">K${parseInt(o.total_amount).toLocaleString()}</span>
          </div>
        </div>
      `).join('')
    }
  `;
}

async function loadAnalytics(days=7){
  // Empty state helper
  const setEmpty = (id, msg='No data yet') => {
    const el = document.getElementById(id);
    if(el) el.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--muted);font-size:.85rem">📊 ${msg}</div>`;
  };
  [7,14,30].forEach(d=>{
    const b=document.getElementById('abtn-'+d);
    if(b){b.style.background=d===days?'var(--accent)':'';b.style.color=d===days?'#fff':'';}
  });
  let aUrl='analytics.php?days='+days;
  if(window._currentBranch>0) aUrl+='&branch_id='+window._currentBranch;
  if(window._currentTenant>0) aUrl+='&tenant_id='+window._currentTenant;
  const r=await fetch(aUrl);
  const d=await r.json();
  if(!d.ok)return;

  document.getElementById('an-total-orders').textContent=d.summary.total_orders;
  document.getElementById('an-total-rev').textContent='K '+fmtK(d.summary.total_revenue);
  document.getElementById('an-avg-order').textContent='K '+fmtK(d.summary.avg_order);

  const ac='#e84c2b', ac2='#f0a500';

  destroyChart('revenue');
  const rC=document.getElementById('chart-revenue')?.getContext('2d');
  if(rC) _charts['revenue']=new Chart(rC,{type:'bar',data:{labels:d.revenue.map(r=>r.date),datasets:[{label:'Revenue',data:d.revenue.map(r=>r.revenue),backgroundColor:ac+'99',borderColor:ac,borderWidth:1,borderRadius:4},{label:'Orders',data:d.revenue.map(r=>r.orders),type:'line',borderColor:ac2,backgroundColor:'transparent',pointBackgroundColor:ac2,pointRadius:3,tension:0.4,yAxisID:'y2'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{font:{size:10},color:'#888'}}},scales:{x:{ticks:{font:{size:9},color:'#888'},grid:{display:false}},y:{ticks:{font:{size:9},color:'#888',callback:v=>'K'+fmtK(v)},grid:{color:'#f0f0f0'}},y2:{position:'right',ticks:{font:{size:9},color:ac2},grid:{display:false}}}}});

  destroyChart('items');
  const iC=document.getElementById('chart-items')?.getContext('2d');
  if(iC&&d.items.length) _charts['items']=new Chart(iC,{type:'bar',data:{labels:d.items.map(i=>i.item_name.length>14?i.item_name.substring(0,14)+'…':i.item_name),datasets:[{label:'Qty',data:d.items.map(i=>i.qty),backgroundColor:['#e84c2bcc','#f0a500cc','#28a745cc','#17a2b8cc','#6f42c1cc','#fd7e14cc','#20c997cc','#e83e8ccc'],borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:9},color:'#888'},grid:{color:'#f0f0f0'}},y:{ticks:{font:{size:9},color:'#555'},grid:{display:false}}}}});

  destroyChart('payments');
  const pC=document.getElementById('chart-payments')?.getContext('2d');
  if(pC&&d.payments.length){
    const pc={kpay:'#9b59b6',wave:'#3498db',cb:'#e74c3c',aya:'#2ecc71',cod:'#95a5a6',card:'#f39c12'};
    _charts['payments']=new Chart(pC,{type:'doughnut',data:{labels:d.payments.map(p=>p.payment_method.toUpperCase()),datasets:[{data:d.payments.map(p=>p.cnt),backgroundColor:d.payments.map(p=>pc[p.payment_method]||'#bbb'),borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:9},color:'#555',padding:8}},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.raw} orders`}}}}});
  }

  destroyChart('hourly');
  const hC=document.getElementById('chart-hourly')?.getContext('2d');
  if(hC){
    const mx=Math.max(...d.hourly.map(h=>h.count),1);
    _charts['hourly']=new Chart(hC,{type:'bar',data:{labels:d.hourly.map(h=>h.hour%6===0?h.hour+'h':''),datasets:[{label:'Orders',data:d.hourly.map(h=>h.count),backgroundColor:d.hourly.map(h=>{const r=h.count/mx;return r>0.7?'#e84c2b':r>0.3?'#f0a500':'#ddd';}),borderRadius:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{font:{size:8},color:'#888'},grid:{display:false}},y:{ticks:{font:{size:8},color:'#888',stepSize:1},grid:{color:'#f0f0f0'}}}}});
  }
}
// ══ END ANALYTICS ══


// ── Session timeout + CSRF handler ──
const _origFetch = window.fetch;
window.fetch = async function(...args) {
  // Inject CSRF token into POST requests
  if (args[1]?.method?.toUpperCase() === 'POST') {
    args[1].headers = args[1].headers || {};
    if (typeof args[1].headers === 'object' && !(args[1].headers instanceof Headers)) {
      args[1].headers['X-CSRF-Token'] = CSRF_TOKEN;
    }
  }
  const res = await _origFetch.apply(this, args);
  if (res.status === 401) {
    const clone = res.clone();
    try {
      const d = await clone.json();
      if (d.msg === 'Not logged in' || d.msg === 'Session expired' || d.msg === 'Unauthorized') {
        if (!document.getElementById('session-expired-banner')) {
          const banner = document.createElement('div');
          banner.id = 'session-expired-banner';
          banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#dc3545;color:#fff;text-align:center;padding:.75rem;font-size:.9rem;font-weight:500';
          banner.innerHTML = '⚠️ Session expired — <a href="admin.php" style="color:#fff;text-decoration:underline">Click here to login again</a>';
          document.body.prepend(banner);
          setTimeout(()=>{ window.location.href = 'admin.php'; }, 3000);
        }
      }
    } catch(e) {}
  }
  return res;
};


// ══ LOAD MENU (admin reference) ══
async function loadMenu() {
  const d = await fetch('menu_api.php').then(r=>r.json());
  return d.items || [];
}

async function loadStats() {
  document.getElementById('dash-date').textContent =
    new Date().toLocaleDateString('en-GB', {weekday:'long',day:'numeric',month:'long'});
  const d = await api('stats');
  if (!d.ok) return;
  document.getElementById('s-orders').textContent  = d.today;
  document.getElementById('s-revenue').textContent = fmt(d.revenue);
  document.getElementById('s-low').textContent     = d.low;
  document.getElementById('s-pending').textContent = d.pending;
}

/* ═══════════════════════════════════════
   ORDERS
═══════════════════════════════════════ */
async function loadOrders() {
  const d = await api('orders');
  if (!d.ok) return;

  const orderRow = (o, isDash) => {
    const ref  = 'NH-' + String(o.id).padStart(6,'0');
    const time = new Date(o.created_at).toLocaleString('en-GB',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'});
    const branchBadge = (window._currentBranch > 0) ? '' : `<span style="display:inline-block;background:var(--surface2);color:var(--muted);font-size:.68rem;padding:.1rem .45rem;border-radius:10px;margin-left:.3rem;font-weight:600">${o.branch_code||''}</span>`;
    return `<tr>
      <td><strong style="font-family:'DM Mono',monospace">${ref}</strong>${branchBadge}</td>
      <td>${o.customer_name}</td>
      ${isDash ? '' : `<td class="hide-mobile" style="font-size:.8rem">${o.customer_phone}</td>`}
      <td style="font-size:.78rem;color:var(--muted);max-width:160px">${o.items}</td>
      <td class="price-cell">${fmt(o.total_amount)}</td>
      <td style="text-transform:uppercase;font-size:.78rem">${o.payment_method}</td>
      <td>
        <span class="order-status os-${o.status}">${o.status}</span>
        ${o.status==='cancelled' && o.delete_reason ? `<div style="font-size:.7rem;color:#991b1b;margin-top:.2rem">📝 ${o.delete_reason}</div>` : ''}
      </td>
      <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">${time}</td>
      <td style="display:flex;gap:4px">
        <button class="btn btn-ghost btn-sm" onclick="printReceipt(${o.id})" title="Print Receipt">🖨️</button>
        <button class="btn btn-danger btn-sm" onclick="openDelOrder(${o.id},'${ref}')">🗑</button>
      </td>
    </tr>`;
  };

  const rows    = d.orders.map(o => orderRow(o, false)).join('');
  const dashRows= d.orders.slice(0,10).map(o => orderRow(o, true)).join('');
  const empty8  = '<tr><td colspan="9" style="text-align:center;color:var(--muted);padding:2rem">No orders yet</td></tr>';
  const empty9  = '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No orders yet</td></tr>';

  const ob = document.getElementById('orders-body');
  const db = document.getElementById('dash-orders-body');
  if (ob) ob.innerHTML = rows || empty8;
  if (db) db.innerHTML = dashRows || empty9;
}

/* ── DELETE ORDER ── */
const DEL_REASONS = [
  { label:'🧪 Test order', val:'Test order — စစ်ဆေးမှုအတွက်' },
  { label:'❌ မှားယွင်းထည့်', val:'မှားယွင်းရိုက်ထည့်မိသောကြောင့်' },
  { label:'📦 ပစ္စည်းမရှိ', val:'မှာယူသောပစ္စည်း stock မရှိ' },
  { label:'📞 Customer ပယ်ဖျက်', val:'Customer မှ cancel လုပ်ကြောင်းဆက်သွယ်' },
  { label:'🔁 Order ထပ်ခါ', val:'Customer မှ order ထပ်တူပေးပို့' },
  { label:'📍 Address မမှန်', val:'Delivery address မှားယွင်း' },
  { label:'⏱ Expired', val:'Order ကုန်ဆုံးချိန်ကျော်' },
  { label:'✏️ အခြား', val:'' },
];
let delOrderId = null;
let pickedReason = '';

function openDelOrder(id, ref) {
  delOrderId = id;
  pickedReason = '';
  document.getElementById('del-order-ref').textContent = ref;
  document.getElementById('del-remark').value = '';
  document.getElementById('reason-grid').innerHTML = DEL_REASONS.map((r,i) =>
    `<button class="reason-btn" onclick="pickReason(${i},'${r.val.replace(/'/g,"\\'")}',this)">${r.label}</button>`
  ).join('');
  document.getElementById('del-order-modal').classList.add('open');
}

function pickReason(idx, val, btn) {
  document.querySelectorAll('.reason-btn').forEach(b => b.classList.remove('picked'));
  btn.classList.add('picked');
  pickedReason = val;
  if (idx === DEL_REASONS.length-1) {
    document.getElementById('del-remark').focus();
  } else {
    document.getElementById('del-remark').value = val;
  }
}

function closeDelOrder() {
  document.getElementById('del-order-modal').classList.remove('open');
  delOrderId = null;
}

async function confirmDelOrder() {
  const remark = document.getElementById('del-remark').value.trim();
  const reason = remark || pickedReason;
  if (!reason) { toast('Reason ရွေးပါ သို့မဟုတ် ရိုက်ထည့်ပါ','err'); return; }
  const d = await api('delete_order', { id: delOrderId, reason });
  if (d.ok) {
    toast('Order deleted & archived ✓','ok');
    closeDelOrder();
    loadOrders();
    loadStats();
  } else {
    toast(d.msg || 'Error','err');
  }
}

/* ── DELETED ORDERS LOG ── */
async function showDeletedLog() {
  document.getElementById('deleted-log-modal').classList.add('open');
  const d = await api('deleted_orders');
  if (!d.ok) { toast('Load failed','err'); return; }
  const rows = d.orders.map(o => `<tr>
    <td><strong style="font-family:'DM Mono',monospace">${o.order_ref}</strong>
      <div style="font-size:.72rem;color:var(--muted)">${o.customer_name} · ${o.customer_phone}</div></td>
    <td>${o.customer_name}</td>
    <td class="price-cell">${fmt(o.total_amount)}</td>
    <td style="font-size:.8rem;max-width:180px"><span class="del-badge">🗑</span> ${o.delete_reason}</td>
    <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">
      ${new Date(o.deleted_at).toLocaleString('en-GB',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'short'})}
    </td>
  </tr>`).join('');
  document.getElementById('deleted-log-body').innerHTML =
    rows || '<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Deleted records မရှိသေးပါ</td></tr>';
}

/* ── IMAGE UPLOAD ── */
let currentEditId = null;

function previewImg(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('img-new-preview');
    prev.src = e.target.result;
    prev.style.display = 'block';
    document.getElementById('img-upload-label').style.display = 'none';
    document.getElementById('img-upload-btn').style.display = 'inline-flex';
  };
  reader.readAsDataURL(input.files[0]);
}

async function uploadImg() {
  const file = document.getElementById('img-file-input').files[0];
  if (!file || !currentEditId) { toast('File ရွေးပါ','err'); return; }
  const btn = document.getElementById('img-upload-btn');
  btn.disabled = true; btn.textContent = 'Uploading…';

  const fd = new FormData();
  fd.append('img', file);
  fd.append('item_id', currentEditId);

  try {
    const r = await fetch('admin.php?api=upload_image', { method:'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      toast('ဓာတ်ပုံ upload ပြီး ✓','ok');
      // Update preview
      document.getElementById('img-current-preview').src = d.path + '?t=' + Date.now();
      document.getElementById('img-current-preview').style.display = 'block';
      document.getElementById('img-remove-btn').style.display = 'inline-flex';
      loadMenuItems();
    } else {
      toast(d.msg || 'Upload failed','err');
    }
  } catch(e) {
    toast('Upload error: ' + e.message,'err');
  }
  btn.disabled = false; btn.textContent = '↑ Upload';
}

async function removeImg() {
  if (!currentEditId) return;
  if (!confirm('ဓာတ်ပုံ ဖျက်မည်သေချာပါသလား?')) return;
  const d = await api('remove_image', {id: currentEditId});
  if (d.ok) {
    toast('ဓာတ်ပုံ ဖျက်ပြီ','ok');
    document.getElementById('img-current-preview').style.display = 'none';
    document.getElementById('img-remove-btn').style.display = 'none';
    document.getElementById('img-new-preview').style.display = 'none';
    document.getElementById('img-upload-label').style.display = 'block';
    document.getElementById('img-file-input').value = '';
    loadMenuItems();
  }
}

/* ═══════════════════════════════════════
   MENU ITEMS
═══════════════════════════════════════ */
async function loadMenuItems() {
  const d = await api('items');
  if (!d.ok) return;
  allItems = d.items;

  // Show plan usage banner for tenant
  if (window.__IS_TENANT) {
    const planLimits = {free:20,basic:50,pro:200,enterprise:500};
    const max = planLimits[window.__TENANT_PLAN] || 20;
    const used = allItems.length;
    const pct  = Math.round((used/max)*100);
    const color = pct >= 100 ? '#dc2626' : pct >= 80 ? '#d97706' : '#059669';
    let banner = document.getElementById('menu-plan-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'menu-plan-banner';
      banner.style.cssText = 'margin-bottom:.8rem;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--card);display:flex;align-items:center;gap:.75rem;font-size:.83rem';
      const catTabs = document.getElementById('cat-tabs');
      catTabs?.parentNode?.insertBefore(banner, catTabs);
    }
    banner.innerHTML = `
      <span style="color:${color};font-size:1.1rem">${pct>=100?'🔴':pct>=80?'🟡':'🟢'}</span>
      <div style="flex:1">
        <div style="font-weight:600;color:var(--text)">Menu Items: ${used} / ${max}
          <span style="font-weight:400;color:var(--muted);font-size:.78rem">(${window.__TENANT_PLAN?.toUpperCase()} Plan)</span>
        </div>
        <div style="margin-top:4px;height:5px;background:var(--border);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:${Math.min(pct,100)}%;background:${color};border-radius:99px;transition:width .4s"></div>
        </div>
      </div>
      ${pct>=80 ? `<button onclick="showPage('upgrade')" style="font-size:.75rem;padding:4px 10px;border:1px solid ${color};color:${color};border-radius:6px;background:transparent;cursor:pointer;white-space:nowrap">⬆ Upgrade</button>` : ''}
    `;
  }

  buildCatTabs();
  renderMenuTable();
}

function buildCatTabs() {
  const cats = ['All', ...new Set(allItems.map(i => i.category))];
  document.getElementById('cat-tabs').innerHTML = cats.map(c =>
    `<div class="cat-tab${c===activeCat?' on':''}" onclick="setCat('${c}')">${c}</div>`
  ).join('');
}

function setCat(c) {
  activeCat = c; buildCatTabs(); renderMenuTable();
}

function renderMenuTable() {
  const q = document.getElementById('menu-search')?.value.toLowerCase() || '';
  const filtered = allItems.filter(i =>
    (activeCat==='All' || i.category===activeCat) &&
    (!q || i.name.toLowerCase().includes(q) || i.category.toLowerCase().includes(q))
  );
  document.getElementById('menu-count').textContent = filtered.length + ' items';

  const stockPill = s => {
    if (s==0)  return `<span class="stock-pill stock-out">Out</span>`;
    if (s<=5)  return `<span class="stock-pill stock-low">${s} low</span>`;
    return             `<span class="stock-pill stock-ok">${s}</span>`;
  };

  const rows = filtered.map(i => `
    <tr data-id="${i.id}" draggable="true"
        ondragstart="onDragStart(event)" ondragover="onDragOver(event)"
        ondragleave="onDragLeave(event)" ondrop="onDrop(event)" ondragend="onDragEnd(event)">
      <td class="drag-handle" title="Drag to reorder">⠿</td>
      <td class="emoji-cell">
        ${i.image_path
          ? `<img src="${i.image_path}" style="width:38px;height:38px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">`
          : (i.emoji||'🍽️')}
      </td>
      <td><strong>${i.name}</strong><div style="font-size:.75rem;color:var(--muted);margin-top:1px">${(i.description||'').slice(0,45)}${(i.description||'').length>45?'…':''}</div></td>
      <td><span style="font-size:.78rem;background:var(--warm);padding:.2rem .6rem;border-radius:50px">${i.category}</span></td>
      <td class="price-cell">${fmt(i.price)}</td>
      <td>${stockPill(i.stock_qty)}</td>
      <td><span class="active-dot ${i.is_active==1?'dot-on':'dot-off'}"></span> ${i.is_active==1?'Active':'Hidden'}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-warn btn-sm" onclick="openRestock(${i.id},'${i.name.replace(/'/g,"\\'")}',${i.stock_qty})">↑ Stock</button>
        <button class="btn btn-ghost btn-sm" onclick="openEditModal(${i.id})" style="margin:0 3px">✎ Edit</button>
        <button class="btn btn-danger btn-sm" onclick="deleteItem(${i.id},'${i.name.replace(/'/g,"\\'")}')">✕</button>
      </td>
    </tr>`).join('');
  document.getElementById('menu-body').innerHTML = rows ||
    '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No items found</td></tr>';
}

/* ═══════════════════════════════════════
   ADD / EDIT MODAL
═══════════════════════════════════════ */
function openAddModal() {
  document.getElementById('modal-title').textContent = 'Add Menu Item';
  document.getElementById('modal-save-btn').textContent = 'Add Item';
  document.getElementById('f-id').value    = '';
  document.getElementById('f-emoji').value = '';
  document.getElementById('f-name').value  = '';
  document.getElementById('f-cat').value   = 'Noodles';
  document.getElementById('f-desc').value  = '';
  document.getElementById('f-price').value = '';
  document.getElementById('f-stock').value = '';
  document.getElementById('active-row').style.display = 'none';
  document.getElementById('item-modal').classList.add('open');
}

function openEditModal(id) {
  const item = allItems.find(i => i.id == id);
  if (!item) return;
  currentEditId = id;
  document.getElementById('modal-title').textContent = 'Edit: ' + item.name;
  document.getElementById('modal-save-btn').textContent = 'Save Changes';
  document.getElementById('f-id').value       = item.id;
  document.getElementById('f-emoji').value    = item.emoji || '';
  document.getElementById('f-name').value     = item.name;
  document.getElementById('f-cat').value      = item.category;
  document.getElementById('f-desc').value     = item.description || '';
  document.getElementById('f-price').value    = item.price;
  document.getElementById('f-stock').value    = item.stock_qty;
  document.getElementById('f-active').checked = item.is_active == 1;
  document.getElementById('f-station').value  = item.station || 'kitchen';
  document.getElementById('active-row').style.display    = '';
  document.getElementById('img-upload-row').style.display = '';
  document.getElementById('modifier-btn').style.display  = '';

  // ဓာတ်ပုံ preview reset
  const cur  = document.getElementById('img-current-preview');
  const newP = document.getElementById('img-new-preview');
  const rmBtn= document.getElementById('img-remove-btn');
  const upBtn= document.getElementById('img-upload-btn');
  const lbl  = document.getElementById('img-upload-label');
  document.getElementById('img-file-input').value = '';
  newP.style.display = 'none';
  upBtn.style.display = 'none';
  lbl.style.display = 'block';
  if (item.image_path) {
    cur.src = item.image_path + '?t=' + Date.now();
    cur.style.display = 'block';
    rmBtn.style.display = 'inline-flex';
  } else {
    cur.style.display = 'none';
    rmBtn.style.display = 'none';
  }
  document.getElementById('item-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('item-modal').classList.remove('open');
  document.getElementById('modifier-btn').style.display = 'none';
}

/* ══════════════════════════════════════
   MODIFIER MODAL JS
══════════════════════════════════════ */
let currentModItemId   = null;
let currentModItemName = '';
let currentGroupId     = null;

async function openModifierModal() {
  const id = document.getElementById('f-id').value;
  const name = document.getElementById('f-name').value;
  if (!id) return;
  currentModItemId   = id;
  currentModItemName = name;
  document.getElementById('mod-item-name').textContent = name;
  document.getElementById('modifier-modal').classList.add('open');
  document.getElementById('group-form').style.display = 'none';
  await loadModifierGroups();
}

function closeModifierModal() {
  document.getElementById('modifier-modal').classList.remove('open');
}

async function loadModifierGroups() {
  const r = await fetch(`admin.php?api=get_modifiers&item_id=${currentModItemId}`);
  const d = await r.json();
  const el = document.getElementById('modifier-groups-list');
  if (!d.ok || !d.groups.length) {
    el.innerHTML = '<p style="color:var(--muted);font-size:.82rem;text-align:center;padding:1rem">Modifier မရှိသေးပါ</p>';
    return;
  }
  el.innerHTML = d.groups.map(g => `
    <div class="modifier-group-card" style="border:1px solid var(--border);border-radius:10px;padding:.8rem;margin-bottom:.8rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
        <div>
          <strong style="font-size:.9rem">${g.name}</strong>
          <span style="font-size:.72rem;color:var(--muted);margin-left:.4rem">
            ${g.type === 'single' ? 'Single' : g.type === 'multi' ? 'Multi' : 'Text'}
            ${g.required ? ' · <span style="color:var(--danger)">Required</span>' : ''}
          </span>
        </div>
        <div style="display:flex;gap:.3rem">
          <button class="btn btn-ghost btn-sm" onclick="editGroupForm(${g.id},'${g.name.replace(/'/g,"\\'")}','${g.type}',${g.required})">Edit</button>
          <button class="btn btn-danger btn-sm" onclick="deleteModifierGroup(${g.id})">✕</button>
        </div>
      </div>
      ${g.type !== 'text' ? `
        <div style="margin-left:.5rem">
          ${g.options.map(o => `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:.25rem .4rem;border-radius:6px;background:var(--surface);margin-bottom:.25rem">
              <span style="font-size:.82rem">
                ${o.is_default ? '✓ ' : ''}<strong>${o.label}</strong>
                ${o.price_add > 0 ? `<span style="color:var(--accent2);font-size:.75rem">+${o.price_add.toLocaleString()}ks</span>` : ''}
              </span>
              <div style="display:flex;gap:.25rem">
                <button class="btn btn-ghost btn-sm" style="padding:.15rem .4rem;font-size:.7rem"
                  onclick="openOptionModal(${g.id},'edit',${o.id},'${o.label.replace(/'/g,"\\'")}',${o.price_add},${o.is_default})">Edit</button>
                <button class="btn btn-danger btn-sm" style="padding:.15rem .4rem;font-size:.7rem"
                  onclick="deleteModifierOption(${o.id})">✕</button>
              </div>
            </div>
          `).join('')}
          <button class="btn btn-ghost btn-sm" style="width:100%;margin-top:.3rem"
            onclick="openOptionModal(${g.id},'add')">+ Add Option</button>
        </div>
      ` : `<p style="font-size:.78rem;color:var(--muted);margin-left:.5rem">Customer မှာ free text ရေးနိုင်မည်</p>`}
    </div>
  `).join('');
}

function openAddGroupForm() {
  currentGroupId = null;
  document.getElementById('gf-id').value      = '';
  document.getElementById('gf-name').value    = '';
  document.getElementById('gf-type').value    = 'single';
  document.getElementById('gf-required').checked = false;
  document.getElementById('group-form').style.display = 'block';
  document.getElementById('gf-name').focus();
}

function editGroupForm(id, name, type, required) {
  currentGroupId = id;
  document.getElementById('gf-id').value         = id;
  document.getElementById('gf-name').value       = name;
  document.getElementById('gf-type').value       = type;
  document.getElementById('gf-required').checked = !!required;
  document.getElementById('group-form').style.display = 'block';
  document.getElementById('gf-name').focus();
}

function cancelGroupForm() {
  document.getElementById('group-form').style.display = 'none';
}

async function saveModifierGroup() {
  const name     = document.getElementById('gf-name').value.trim();
  const type     = document.getElementById('gf-type').value;
  const required = document.getElementById('gf-required').checked ? 1 : 0;
  const id       = document.getElementById('gf-id').value;
  if (!name) { toast('Group name ထည့်ပါ', 'err'); return; }
  const body = { menu_item_id: parseInt(currentModItemId), name, type, required, sort_order: 0 };
  if (id) body.id = parseInt(id);
  const r = await fetch('admin.php?api=save_modifier_group', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.ok) { toast('Group saved!', 'ok'); cancelGroupForm(); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function deleteModifierGroup(gid) {
  if (!confirm('Modifier group ကို ဖျက်မည်။ Options အကုန်ပါ ဖျက်မည်။ သေချာလား?')) return;
  const d = await (await fetch('admin.php?api=delete_modifier_group', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: gid})
  })).json();
  if (d.ok) { toast('Deleted', 'ok'); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

function openOptionModal(groupId, mode, id='', label='', priceAdd=0, isDefault=0) {
  currentGroupId = groupId;
  document.getElementById('of-group-id').value = groupId;
  document.getElementById('of-id').value       = id;
  document.getElementById('of-label').value    = label;
  document.getElementById('of-price').value    = priceAdd;
  document.getElementById('of-default').checked= !!isDefault;
  document.getElementById('opt-modal-title').textContent = mode === 'add' ? 'Add Option' : 'Edit Option';
  document.getElementById('option-modal').classList.add('open');
  setTimeout(() => document.getElementById('of-label').focus(), 100);
}

function closeOptionModal() {
  document.getElementById('option-modal').classList.remove('open');
}

async function saveModifierOption() {
  const label     = document.getElementById('of-label').value.trim();
  const priceAdd  = parseInt(document.getElementById('of-price').value) || 0;
  const isDefault = document.getElementById('of-default').checked ? 1 : 0;
  const groupId   = parseInt(document.getElementById('of-group-id').value);
  const id        = document.getElementById('of-id').value;
  if (!label) { toast('Label ထည့်ပါ', 'err'); return; }
  const body = { group_id: groupId, label, price_add: priceAdd, is_default: isDefault, sort_order: 0 };
  if (id) body.id = parseInt(id);
  const d = await (await fetch('admin.php?api=save_modifier_option', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
  })).json();
  if (d.ok) { toast('Saved!', 'ok'); closeOptionModal(); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function deleteModifierOption(oid) {
  const d = await (await fetch('admin.php?api=delete_modifier_option', {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id: oid})
  })).json();
  if (d.ok) { toast('Deleted', 'ok'); await loadModifierGroups(); }
  else { toast(d.msg || 'Error', 'err'); }
}

async function saveItem() {
  const id    = document.getElementById('f-id').value;
  const name  = document.getElementById('f-name').value.trim();
  const price = document.getElementById('f-price').value;
  const stock = document.getElementById('f-stock').value;
  if (!name)  { toast('Name ထည့်ပါ', 'err'); return; }
  if (!price) { toast('Price ထည့်ပါ', 'err'); return; }
  if (stock==='') { toast('Stock ထည့်ပါ', 'err'); return; }

  const body = {
    name, price, stock,
    emoji:    document.getElementById('f-emoji').value.trim() || '🍽️',
    category: document.getElementById('f-cat').value,
    desc:     document.getElementById('f-desc').value.trim(),
    active:   document.getElementById('f-active').checked ? 1 : 0,
    station:  document.getElementById('f-station').value || 'kitchen',
  };

  const btn = document.getElementById('modal-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';

  let d;
  if (id) { body.id = id; d = await api('update', body); }
  else    { d = await api('add', body); }

  btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Add Item';
  if (d.ok) { toast(id ? 'Updated!' : 'Item added!', 'ok'); closeModal(); loadMenuItems(); }
  else       { toast(d.msg || 'Error', 'err'); }
}

async function deleteItem(id, name) {
  if (!confirm(`"${name}" ကိုဖျက်မည်။ သေချာပါသလား?`)) return;
  const d = await api('delete', {id});
  if (d.ok) { toast('Deleted', 'ok'); loadMenuItems(); }
  else       { toast(d.msg||'Error','err'); }
}

/* ═══════════════════════════════════════
   RESTOCK
═══════════════════════════════════════ */
function openRestock(id, name, current) {
  restockId = id;
  document.getElementById('restock-name').textContent    = name;
  document.getElementById('restock-current').textContent = current;
  document.getElementById('restock-qty').value           = '';
  document.getElementById('restock-modal').classList.add('open');
  setTimeout(() => document.getElementById('restock-qty').focus(), 200);
}
function closeRestock() {
  document.getElementById('restock-modal').classList.remove('open');
  restockId = null;
}
function setRestock(n) { document.getElementById('restock-qty').value = n; }
async function doRestock() {
  const qty = parseInt(document.getElementById('restock-qty').value);
  if (!qty || qty < 1) { toast('Qty ထည့်ပါ','err'); return; }
  const d = await api('restock', {id: restockId, qty});
  if (d.ok) { toast(`+${qty} added!`, 'ok'); closeRestock(); loadMenuItems(); }
  else       { toast(d.msg||'Error','err'); }
}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function toast(msg, type='') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show ' + type;
  clearTimeout(toastTmr);
  toastTmr = setTimeout(() => el.classList.remove('show'), 2800);
}

/* ═══════════════════════════════════════
   BATCH UPLOAD
═══════════════════════════════════════ */
const CSV_TEMPLATE = `name,category,price,stock,emoji,description
မုန့်ဟင်းခါး,Noodles,4500,20,🍲,ငါးဆောက်ဟင်းချို မုန့်ဟင်းခါး
Shan Noodles,Noodles,4000,15,🍜,Light pork-broth rice noodles
Ramen Bowl,Noodles,6000,8,🍥,Japanese-style ramen with chashu pork
ကြက်သားကင်,Starters,4000,30,🍡,Grilled skewers with peanut sauce
Tom Yum Soup,Soups,4500,14,🫕,Hot and sour Thai soup with prawns
Taro Bubble Tea,Drinks,2500,40,🧋,Creamy taro milk tea with tapioca`;

// Category valid values: Noodles, Rice, Starters, Soups, Desserts, Drinks
// Myanmar aliases also accepted: ခေါက်ဆွဲ=Noodles, ထမင်း=Rice, ဟင်းချို=Soups, အချိုရည်=Drinks

let batchPreviewRows = [];

function downloadTemplate() {
  const blob = new Blob([CSV_TEMPLATE], {type:'text/csv;charset=utf-8;'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = 'menu_template.csv';
  a.click(); URL.revokeObjectURL(url);
}

function openBatchModal() {
  resetBatch();
  document.getElementById('batch-modal').classList.add('open');
}

function closeBatchModal() {
  document.getElementById('batch-modal').classList.remove('open');
}

function resetBatch() {
  batchPreviewRows = [];
  document.getElementById('batch-step1').style.display = '';
  document.getElementById('batch-step2').style.display = 'none';
  document.getElementById('batch-file').value = '';
  document.getElementById('batch-modal-foot').innerHTML =
    '<button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>';
}

async function handleBatchFile(file) {
  if (!file) return;
  if (!file.name.match(/\.(csv|txt)$/i)) {
    toast('CSV ဖိုင်သာ လက်ခံသည်','err'); return;
  }

  const fd = new FormData();
  fd.append('csv', file);
  fd.append('preview', '1');

  try {
    const r = await fetch('admin.php?api=batch_upload', {method:'POST', body:fd});
    const d = await r.json();

    if (!d.ok) { toast(d.msg||'Parse failed','err'); return; }

    batchPreviewRows = d.rows || [];
    renderBatchPreview(d.rows, d.errors);
  } catch(e) {
    toast('Error: '+e.message,'err');
  }
}

function renderBatchPreview(rows, errors) {
  // Switch to step 2
  document.getElementById('batch-step1').style.display = 'none';
  document.getElementById('batch-step2').style.display = '';

  // Summary
  const sumEl = document.getElementById('batch-summary');
  sumEl.innerHTML =
    `<span style="color:var(--green);font-weight:600">✓ ${rows.length} items ready</span>` +
    (errors?.length ? ` &nbsp; <span style="color:var(--accent)">⚠ ${errors.length} rows skipped</span>` : '');

  // Errors
  const errEl = document.getElementById('batch-errors');
  if (errors?.length) {
    errEl.style.display = 'block';
    errEl.innerHTML = errors.map(e =>
      `Row ${e.row}: ${e.msg}`).join('<br>');
  } else {
    errEl.style.display = 'none';
  }

  // Preview table
  const CATS = {Noodles:'#e8f4fd',Rice:'#f0faf0',Starters:'#fff9e6',
                Soups:'#fef0f0',Desserts:'#fdf0ff',Drinks:'#f0fff4'};
  document.getElementById('batch-preview-body').innerHTML = rows.map((r,i) => `
    <tr style="border-bottom:1px solid var(--border)">
      <td style="padding:.4rem .8rem;color:var(--muted)">${i+1}</td>
      <td style="padding:.4rem .8rem;font-weight:500">${r.name}</td>
      <td style="padding:.4rem .8rem">
        <span style="background:${CATS[r.cat]||'var(--warm)'};padding:.15rem .5rem;border-radius:50px;font-size:.75rem">${r.cat}</span>
      </td>
      <td style="padding:.4rem .8rem;text-align:right;font-family:'DM Mono',monospace">${fmt(r.price)}</td>
      <td style="padding:.4rem .8rem;text-align:right">${r.stock}</td>
      <td style="padding:.4rem .8rem;text-align:center;font-size:1.2rem">${r.emoji}</td>
      <td style="padding:.4rem .8rem;color:var(--muted);font-size:.78rem">${(r.desc||'').slice(0,40)}${(r.desc||'').length>40?'…':''}</td>
    </tr>`).join('');

  // Footer buttons
  document.getElementById('batch-modal-foot').innerHTML = `
    <button class="btn btn-ghost" onclick="resetBatch()">↺ ပြန်ရွေး</button>
    <button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>
    <button class="btn btn-primary" onclick="confirmBatchUpload()" id="batch-confirm-btn">
      ✓ ${rows.length} items ထည့်မည်
    </button>`;
}

async function confirmBatchUpload() {
  const btn = document.getElementById('batch-confirm-btn');
  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Uploading…';

  const file = document.getElementById('batch-file').files[0];
  if (!file) { toast('File မရှိတော့ပါ — ပြန်ရွေးပါ','err'); resetBatch(); return; }

  const fd = new FormData();
  fd.append('csv', file);
  // preview မပါ = real insert

  try {
    const r = await fetch('admin.php?api=batch_upload', {method:'POST', body:fd});
    const d = await r.json();

    if (!d.ok) { toast(d.msg||'Upload failed','err'); btn.disabled=false; btn.textContent='Retry'; return; }

    toast(`✓ ${d.inserted} items ထည့်ပြီ${d.skipped?` (${d.skipped} ကျော်)`:''}`,'ok');
    closeBatchModal();
    loadMenuItems();
  } catch(e) {
    toast('Error: '+e.message,'err');
    btn.disabled=false; btn.textContent='Retry';
  }
}

/* ═══════════════════════════════════════
   DRAG & DROP SORT
═══════════════════════════════════════ */
let dragSrcRow = null;
let reorderTimer = null;

function onDragStart(e) {
  dragSrcRow = e.currentTarget;
  dragSrcRow.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragSrcRow.dataset.id);
}

function onDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const row = e.currentTarget;
  if (row === dragSrcRow) return;
  document.querySelectorAll('#menu-body tr').forEach(r =>
    r.classList.remove('drop-above','drop-below'));
  const rect  = row.getBoundingClientRect();
  const midY  = rect.top + rect.height / 2;
  if (e.clientY < midY) row.classList.add('drop-above');
  else                   row.classList.add('drop-below');
}

function onDragLeave(e) {
  e.currentTarget.classList.remove('drop-above','drop-below');
}

function onDrop(e) {
  e.preventDefault();
  const target = e.currentTarget;
  if (!dragSrcRow || target === dragSrcRow) return;
  target.classList.remove('drop-above','drop-below');

  const tbody  = document.getElementById('menu-body');
  const isAbove = target.getBoundingClientRect().top +
                  target.getBoundingClientRect().height / 2 > e.clientY;
  if (isAbove) tbody.insertBefore(dragSrcRow, target);
  else         tbody.insertBefore(dragSrcRow, target.nextSibling);

  // Update allItems order to match DOM
  const newOrder = [...tbody.querySelectorAll('tr[data-id]')].map(r => parseInt(r.dataset.id));
  allItems.sort((a,b) => newOrder.indexOf(a.id) - newOrder.indexOf(b.id));

  // Debounce save
  clearTimeout(reorderTimer);
  reorderTimer = setTimeout(() => saveOrder(newOrder), 600);
}

function onDragEnd(e) {
  e.currentTarget.classList.remove('dragging');
  document.querySelectorAll('#menu-body tr').forEach(r =>
    r.classList.remove('drop-above','drop-below','drag-over'));
  dragSrcRow = null;
}

async function saveOrder(ids) {
  try {
    const d = await api('reorder', { ids });
    if (d.ok) toast('Order saved ✓','ok');
    else      toast(d.msg||'Save failed','err');
  } catch(e) { toast('Error: '+e.message,'err'); }
}

/* ═══════════════════════════════════════
   TABLES & QR CODES
═══════════════════════════════════════ */
const BASE_URL = window.location.origin + window.location.pathname.replace('admin.php','');

async function loadTables() {
  // Populate branch selector in Add Table modal
  const branchSel = document.getElementById('new-table-branch');
  if (branchSel && branchSel.options.length <= 1) {
    fetch('branch_api.php?action=list').then(r=>r.json()).then(d=>{
      if(!d.ok) return;
      d.branches.forEach(b=>{
        const o = document.createElement('option');
        o.value = b.id; o.textContent = b.name;
        if (b.id == (window._currentBranch||0)) o.selected = true;
        branchSel.appendChild(o);
      });
      if(window._currentBranch > 0) branchSel.value = window._currentBranch;
    });
  }
  const r = await fetch('table_api.php?action=list'+branchParams());
  const d = await r.json();
  if (!d.ok) { toast('Tables load failed','err'); return; }
  renderTablesGrid(d.tables);
  renderQRGrid(d.tables);
}

function renderTablesGrid(tables) {
  const STATUS_COLOR = { open:'#d1fae5', billed:'#fef3c7', empty:'#f0f0f0' };
  const STATUS_LABEL = { open:'🟢 Open', billed:'🧾 Bill Requested', empty:'⬜ Empty' };
  document.getElementById('tables-grid').innerHTML = tables.map(t => {
    const hasOrder = !!t.order_id;
    const status = t.table_status || 'empty';
    const bg = STATUS_COLOR[status] || '#f0f0f0';
    return `<div style="background:${bg};border-radius:10px;padding:1rem;border:1px solid rgba(0,0,0,.08)">
      <div style="font-weight:700;font-size:1rem;margin-bottom:.3rem">${t.table_code}
        <span style="font-size:.75rem;font-weight:400;color:#666"> ${t.label}</span>
        ${t.qr_img ? `<a href="${t.qr_img}" target="_blank" title="QR Code" style="float:right;font-size:.9rem;text-decoration:none">📱</a>` : ''}
      </div>
      <div style="font-size:.82rem;margin-bottom:.5rem">${STATUS_LABEL[status]||status}</div>
      ${hasOrder ? `
        <div style="font-size:.78rem;color:#555;margin-bottom:.6rem">
          ${t.item_count||0} items · ${fmt(t.subtotal||0)}
        </div>
        ${status!=='empty'?`
        <button class="btn btn-primary btn-sm" style="width:100%;margin-bottom:.3rem"
          onclick="openSplitBill(${t.order_id},${t.total_amount||0})">💳 Split & Close</button>
        `:''}
      ` : ''}
      <button class="btn btn-ghost btn-sm" style="width:100%;font-size:.72rem"
        onclick="resetTable('${t.table_code}')">↺ Reset Table</button>
    </div>`;
  }).join('');
}

function renderQRGrid(tables) {
  const SITE = BASE_URL + 'index.html';
  document.getElementById('qr-grid').innerHTML = tables.map(t => {
    const url = `${SITE}?table=${t.table_code}`;
    // Use QR API (Google Charts or local)
    const qrSrc = `https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=${encodeURIComponent(url)}`;
    return `<div style="text-align:center;background:var(--warm);border-radius:10px;padding:1rem;border:1px solid var(--border)">
      <img src="${qrSrc}" alt="${t.table_code}" style="width:120px;height:120px;border-radius:6px">
      <div style="font-weight:700;margin-top:.5rem;font-size:.9rem">${t.table_code}</div>
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:.5rem">${t.label||''} · ${t.seats} seats</div>
      <a href="${url}" target="_blank" style="font-size:.72rem;color:var(--ink);text-decoration:none;word-break:break-all">${url}</a>
    </div>`;
  }).join('');
}

function printAllQR() {
  const SITE = BASE_URL + 'index.html';
  const tables = document.querySelectorAll('#qr-grid > div');
  const win = window.open('','_blank');
  win.document.write(`
    <html><head><title>NoodleHaus QR Codes</title>
    <style>
      body{font-family:sans-serif;margin:0;}
      .page{width:9cm;height:9cm;border:1px solid #ddd;border-radius:12px;
            display:inline-flex;flex-direction:column;align-items:center;justify-content:center;
            margin:8px;padding:12px;text-align:center;page-break-inside:avoid;}
      img{width:120px;height:120px;}
      h2{margin:6px 0 2px;font-size:1.1rem;}
      p{margin:0;font-size:.65rem;color:#666;word-break:break-all;}
      @media print{body{margin:0;} .no-print{display:none;}}
    </style></head><body>
    <div class="no-print" style="padding:12px">
      <button onclick="window.print()">🖨️ Print</button>
    </div>
    ${Array.from(tables).map(el => {
      const code = el.querySelector('div[style*="font-weight:700"]')?.textContent?.trim();
      if (!code) return '';
      const url  = `${SITE}?table=${code}`;
      const qr   = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(url)}`;
      return `<div class="page"><img src="${qr}"><h2>${code}</h2><p>${url}</p></div>`;
    }).join('')}
</body></html>`);
  win.document.close();
}

async function closeTable(orderId, code) {
  if (!confirm(`Table ${code} ကို Close & Paid မှတ်မည်သေချာသလား?`)) return;
  const r = await fetch('table_api.php?action=close_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({order_id: orderId})
  });
  const d = await r.json();
  if (d.ok) { toast(`Table ${code} closed ✓`,'ok'); loadTables(); }
  else toast(d.msg||'Error','err');
}

async function resetTable(code) {
  const r = await fetch('table_api.php?action=open_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({table_code: code})
  });
  const d = await r.json();
  if (d.ok) { toast(`Table ${code} reset ✓`,'ok'); loadTables(); }
  else toast(d.msg||'Error','err');
}

function openAddTableModal() {
  document.getElementById('add-table-modal').classList.add('open');
}

async function saveNewTable() {
  const code  = document.getElementById('new-table-code').value.trim().toUpperCase();
  const label = document.getElementById('new-table-label').value.trim();
  const seats = parseInt(document.getElementById('new-table-seats').value) || 4;
  if (!code) { toast('Table code လိုသည်','err'); return; }
  const r = await fetch('table_api.php?action=add_table', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({code, label, seats, branch_id: parseInt(document.getElementById('new-table-branch')?.value||'0'), tenant_id: window._currentTenant||1 })
  });
  const d = await r.json();
  if (d.ok) {
    toast(`Table ${code} saved ✓`,'ok');
    document.getElementById('add-table-modal').classList.remove('open');
    loadTables();
  } else toast(d.msg||'Error','err');
}

/* ═══════════════════════════════════════
   CMS SETTINGS
═══════════════════════════════════════ */
async function loadSettings() {
  try {
    const r = await fetch('site_settings.php?action=get');
    const d = await r.json();
    if (!d.ok) { toast('Settings load failed','err'); return; }
    const s = d.settings || {};
    const keys = [
      'store_name','store_emoji','open_hours','delivery_fee','township_fees','promo_codes',
      'hero_badge','delivery_label','hero_title_line1','hero_title_line2','hero_subtitle',
      'announcement_text','announcement_color','announcement_on',
      'header_bg_color','header_logo_text_color','header_text_color','header_bg_img_opacity',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
      'footer_phone','footer_address','footer_facebook','footer_instagram','footer_tiktok',
      'footer_copyright','footer_bg_color','footer_bg_opacity'
    ];
    keys.forEach(k => {
      const el = document.getElementById('st-'+k);
      if (!el) return;
      if (s[k] !== undefined && s[k] !== null) el.value = s[k];
    });

    // Header opacity label
    const hOpEl  = document.getElementById('st-header_bg_img_opacity');
    const hOpLbl = document.getElementById('hdr-opacity-val');
    if (hOpEl && hOpLbl) hOpLbl.textContent = hOpEl.value;

    // Hero opacity label
    const heroOpEl  = document.getElementById('st-hero_bg_img_opacity');
    const heroOpLbl = document.getElementById('hero-opacity-val');
    if (heroOpEl && heroOpLbl) heroOpLbl.textContent = heroOpEl.value;

    // Hero image preview
    if (s.hero_bg_image) {
      const prev = document.getElementById('hero-bg-preview');
      const rBtn = document.getElementById('hero-bg-remove-btn');
      const hBg  = document.getElementById('hbp-bg');
      if (prev) { prev.src = s.hero_bg_image; prev.style.display='block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      if (hBg)  { hBg.style.backgroundImage=`url('${s.hero_bg_image}')`; hBg.style.display='block'; }
    }

    // Header image preview
    if (s.header_bg_image) {
      const prev = document.getElementById('header-bg-preview');
      const rBtn = document.getElementById('header-bg-remove-btn');
      const hpBg = document.getElementById('hp-bg-img');
      if (prev) { prev.src = s.header_bg_image; prev.style.display='block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      if (hpBg) { hpBg.style.backgroundImage=`url('${s.header_bg_image}')`; hpBg.style.display='block'; }
    }

    // Footer opacity label
    const opEl = document.getElementById('st-footer_bg_opacity');
    const opLbl = document.getElementById('footer-opacity-val');
    if (opEl && opLbl) opLbl.textContent = opEl.value;

    // Footer bg image preview
    if (s.footer_bg_image) {
      const prev = document.getElementById('footer-bg-preview');
      const rBtn = document.getElementById('footer-bg-remove-btn');
      if (prev) { prev.src = s.footer_bg_image; prev.style.display = 'block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
      const fpBg = document.getElementById('fp-bg');
      if (fpBg) { fpBg.style.backgroundImage = `url('${s.footer_bg_image}')`; fpBg.style.display='block'; }
    }
    // Footer logo image preview
    if (s.footer_logo_image) {
      const prev = document.getElementById('footer-logo-preview');
      const rBtn = document.getElementById('footer-logo-remove-btn');
      if (prev) { prev.src = s.footer_logo_image; prev.style.display = 'block'; }
      if (rBtn)   rBtn.style.display = 'inline-flex';
    }
    updateAnnPreview();
    updateHeroPreview();
    updateFooterPreview();
    updateHeaderPreview();
    populateKpayQrPreview(s);
    if(typeof initTownshipEditors==='function') initTownshipEditors(s);
  } catch(e) {
    toast('Settings load error: '+e.message,'err');
  }
}

/* ── Generic section image upload/remove ── */
async function uploadSectionImg(input, section) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3*1024*1024) { toast('Max 3MB','err'); return; }
  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', section);
  try {
    const r = await fetch('admin.php?api=upload_footer_img', {method:'POST',body:fd});
    const d = await r.json();
    if (!d.ok) { toast(d.msg||'Upload failed','err'); return; }
    const key  = section + '_bg_image';
    const prev = document.getElementById(section+'-bg-preview');
    const rBtn = document.getElementById(section+'-bg-remove-btn');
    const bgEl = document.getElementById(section === 'hero' ? 'hbp-bg' : section+'-bg-img');
    if (prev) { prev.src = d.path; prev.style.display='block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';
    if (bgEl) { bgEl.style.backgroundImage=`url('${d.path}')`; bgEl.style.display='block'; }
    await fetch('site_settings.php?action=save',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({[key]: d.path})
    });
    toast(section+' image uploaded ✓','ok');
    if (section==='hero') updateHeroPreview();
  } catch(e) { toast('Error: '+e.message,'err'); }
}

async function removeSectionImg(section) {
  const key  = section + '_bg_image';
  const prev = document.getElementById(section+'-bg-preview');
  const rBtn = document.getElementById(section+'-bg-remove-btn');
  const inp  = document.getElementById(section+'-bg-file');
  const bgEl = document.getElementById(section === 'hero' ? 'hbp-bg' : section+'-bg-img');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display='none';
  if (inp)    inp.value='';
  if (bgEl) { bgEl.style.backgroundImage=''; bgEl.style.display='none'; }
  await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({[key]:''})
  });
  toast(section+' image removed','ok');
}

/* ── Hero live preview ── */
function updateHeroPreview() {
  const bg      = document.getElementById('st-hero_bg_color')?.value     || '#1a1209';
  const tColor  = document.getElementById('st-hero_title_color')?.value  || '#ffffff';
  const sColor  = document.getElementById('st-hero_subtitle_color')?.value || '#b8a48a';
  const bColor  = document.getElementById('st-hero_badge_color')?.value  || '#f0a500';
  const opacity = document.getElementById('st-hero_bg_img_opacity')?.value || '0.3';
  const badge   = document.getElementById('st-hero_badge')?.value        || '🔥 Live Kitchen';
  const line1   = document.getElementById('st-hero_title_line1')?.value  || 'Authentic Asian';
  const line2   = document.getElementById('st-hero_title_line2')?.value  || 'Noodles & More';
  const sub     = document.getElementById('st-hero_subtitle')?.value     || 'Freshly prepared, delivered hot.';
  const emoji   = document.getElementById('st-hero_emoji')?.value        || '🍜';

  const box     = document.getElementById('hero-preview-box');
  const hBadge  = document.getElementById('hbp-badge');
  const hTitle  = document.getElementById('hbp-title');
  const hSub    = document.getElementById('hbp-sub');
  const hEmoji  = document.getElementById('hbp-emoji');
  const hBg     = document.getElementById('hbp-bg');

  if (box)    box.style.background   = bg;
  if (hBadge) { hBadge.textContent = badge; hBadge.style.color=bColor; hBadge.style.borderColor=bColor; }
  if (hTitle) {
    hTitle.style.color = tColor;
    hTitle.innerHTML   = line1 + '<br><em style="color:'+bColor+';font-style:normal">'+line2+'</em>';
  }
  if (hSub)   { hSub.textContent = sub;   hSub.style.color   = sColor; }
  if (hEmoji)   hEmoji.textContent = emoji;
  if (hBg)      hBg.style.opacity  = opacity;
}

async function uploadHeaderImg(input) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3*1024*1024) { toast('Max 3MB','err'); return; }
  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', 'header');
  try {
    const r = await fetch('admin.php?api=upload_footer_img', { method:'POST', body:fd });
    const d = await r.json();
    if (!d.ok) { toast(d.msg||'Upload failed','err'); return; }
    const prev = document.getElementById('header-bg-preview');
    const rBtn = document.getElementById('header-bg-remove-btn');
    if (prev) { prev.src = d.path; prev.style.display = 'block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';
    // Update preview
    const hpBg = document.getElementById('hp-bg-img');
    if (hpBg) { hpBg.style.backgroundImage=`url('${d.path}')`; hpBg.style.display='block'; }
    // Save to DB
    await fetch('site_settings.php?action=save',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({header_bg_image: d.path})
    });
    toast('Header image uploaded ✓','ok');
  } catch(e) { toast('Error: '+e.message,'err'); }
}

async function removeHeaderImg() {
  const prev = document.getElementById('header-bg-preview');
  const rBtn = document.getElementById('header-bg-remove-btn');
  const inp  = document.getElementById('header-bg-file');
  const hpBg = document.getElementById('hp-bg-img');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display = 'none';
  if (inp)    inp.value = '';
  if (hpBg) { hpBg.style.backgroundImage=''; hpBg.style.display='none'; }
  await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({header_bg_image:''})
  });
  toast('Header image removed','ok');
}

function updateHeaderPreview() {
  const color     = document.getElementById('st-header_bg_color')?.value     || '#1a1209';
  const accent    = document.getElementById('st-header_logo_text_color')?.value || '#f0a500';
  const textColor = document.getElementById('st-header_text_color')?.value    || '#b8a48a';
  const opacity   = document.getElementById('st-header_bg_img_opacity')?.value || '0.2';
  const emoji     = document.getElementById('st-store_emoji')?.value           || '🍜';

  const box     = document.getElementById('header-preview-box');
  const hpAcc   = document.getElementById('hp-accent');
  const hpStat  = document.getElementById('hp-status');
  const hpEmoji = document.getElementById('hp-emoji');
  const hpBg    = document.getElementById('hp-bg-img');

  if (box)     box.style.background   = color;
  if (hpAcc)   hpAcc.style.color      = accent;
  if (hpStat)  hpStat.style.color     = textColor;
  if (hpEmoji) hpEmoji.textContent    = emoji;
  if (hpBg)    hpBg.style.opacity     = opacity;
}

async function uploadFooterImg(input, type) {
  if (!input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3 * 1024 * 1024) { toast('Max 3MB','err'); return; }

  const fd = new FormData();
  fd.append('img', file);
  fd.append('type', type); // 'bg' or 'logo'

  try {
    const r = await fetch('admin.php?api=upload_footer_img', { method:'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { toast(d.msg || 'Upload failed','err'); return; }

    const key  = type === 'bg' ? 'footer_bg_image' : 'footer_logo_image';
    const prev = document.getElementById('footer-'+type+'-preview');
    const rBtn = document.getElementById('footer-'+type+'-remove-btn');
    if (prev) { prev.src = d.path; prev.style.display = 'block'; }
    if (rBtn)   rBtn.style.display = 'inline-flex';

    // Save path to DB
    await fetch('site_settings.php?action=save', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({[key]: d.path})
    });
    toast((type==='bg'?'Background':'Logo') + ' uploaded ✓','ok');
    updateFooterPreview();
  } catch(e) { toast('Upload error: '+e.message,'err'); }
}

async function removeFooterImg(type) {
  const key  = type === 'bg' ? 'footer_bg_image' : 'footer_logo_image';
  const prev = document.getElementById('footer-'+type+'-preview');
  const rBtn = document.getElementById('footer-'+type+'-remove-btn');
  const inp  = document.getElementById('footer-'+type+'-file');
  if (prev) { prev.src=''; prev.style.display='none'; }
  if (rBtn)   rBtn.style.display = 'none';
  if (inp)    inp.value = '';
  await fetch('site_settings.php?action=save', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({[key]: ''})
  });
  if (type === 'bg') {
    const fpBg = document.getElementById('fp-bg');
    if (fpBg) fpBg.style.display = 'none';
  }
  toast('Image removed','ok');
}

function updateFooterPreview() {
  const color   = document.getElementById('st-footer_bg_color')?.value || '#1a1209';
  const opacity = document.getElementById('st-footer_bg_opacity')?.value || '1';
  const store   = document.getElementById('st-store_name')?.value || 'NoodleHaus';
  const copy    = document.getElementById('st-footer_copyright')?.value || '';
  const overlay = document.getElementById('fp-overlay');
  const fpStore = document.getElementById('fp-store');
  const fpCopy  = document.getElementById('fp-copy');
  if (overlay) { overlay.style.background = color; overlay.style.opacity = opacity; }
  if (fpStore)  fpStore.textContent = store;
  if (fpCopy)   fpCopy.textContent  = copy;
}

// Wire up live preview inputs
document.addEventListener('DOMContentLoaded', () => {
  ['st-footer_bg_color','st-footer_bg_opacity','st-store_name','st-footer_copyright'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateFooterPreview);
  });
  ['st-header_bg_color','st-header_logo_text_color','st-header_text_color',
   'st-header_bg_img_opacity','st-store_emoji'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateHeaderPreview);
  });
  ['st-hero_bg_color','st-hero_title_color','st-hero_subtitle_color',
   'st-hero_badge_color','st-hero_emoji','st-hero_bg_img_opacity',
   'st-hero_badge','st-hero_title_line1','st-hero_title_line2','st-hero_subtitle'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateHeroPreview);
  });
});

function updateAnnPreview() {
  const text  = document.getElementById('st-announcement_text')?.value || '';
  const color = document.getElementById('st-announcement_color')?.value || '#e84c2b';
  const on    = document.getElementById('st-announcement_on')?.value === '1';
  const prev  = document.getElementById('ann-preview');
  const hiddenNote = document.getElementById('ann-preview-hidden');
  if (!prev) return;
  if (on && text) {
    prev.textContent      = text;
    prev.style.background = color;
    prev.style.display    = 'block';
    if (hiddenNote) hiddenNote.style.display = 'none';
  } else {
    prev.style.display = 'none';
    if (hiddenNote) hiddenNote.style.display = 'block';
  }
}

/* Live preview listeners */
['st-announcement_text','st-announcement_color','st-announcement_on'].forEach(id => {
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateAnnPreview);
  });
});

async function saveSettings() {
  const keys = [
    'store_name','store_emoji','open_hours','delivery_fee','township_fees','promo_codes',
    'hero_badge','delivery_label','hero_title_line1','hero_title_line2','hero_subtitle',
    'announcement_text','announcement_color','announcement_on',
    'header_bg_color','header_logo_text_color','header_text_color','header_bg_img_opacity',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'hero_bg_color','hero_bg_img_opacity','hero_title_color','hero_subtitle_color',
    'hero_badge_color','hero_emoji',
    'footer_phone','footer_address','footer_facebook','footer_instagram','footer_tiktok',
    'footer_copyright','footer_bg_color','footer_bg_opacity'
  ];
  const payload = {};
  keys.forEach(k => {
    const el = document.getElementById('st-'+k);
    if (el) payload[k] = el.value;
  });
  try {
    const r = await fetch('site_settings.php?action=save', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.ok) { toast('Settings saved ✓','ok'+branchParams()); updateAnnPreview(); }
    else       { toast(d.msg || 'Save failed','err'+branchParams()); }
  } catch(e) {
    toast('Save error: '+e.message,'err'+branchParams());
  }
}

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
''


function showToast(msg, isErr=false) {
  if (typeof toast === 'function'+branchParams()) { toast(msg, isErr ? 'err' : 'ok'+branchParams()); }
}

function escHtml(s) {
  return String(s||''+branchParams()).replace(/&/g,'&amp;'+branchParams()).replace(/</g,'&lt;'+branchParams()).replace(/>/g,'&gt;'+branchParams()).replace(/"/g,'&quot;'+branchParams());
}

showPage('dashboard'+branchParams());
''

/* ── SaaS Dashboard ── */
async function loadSaas() {
  if(window.__IS_TENANT) { toast('Super-admin only'); return; }
  const d = await fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if(!d.ok) return;
  window._saasTenants = (d.tenants || []).sort((a,b)=>a.id-b.id);
  saasRender(window._saasTenants);
}

function saasRender(list){
  const planPrices = {free:0,basic:50000,pro:150000,enterprise:300000};
  const planColors = {free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
  const now        = new Date();

  // Stats
  const active   = list.filter(t=>t.is_active);
  const proCount = active.filter(t=>['pro','enterprise'].includes(t.plan)).length;
  const mrr      = active.reduce((s,t)=>s+(planPrices[t.plan]||0),0);
  const totalOrders  = list.reduce((s,t)=>s+(parseInt(t.total_orders)||0),0);
  const expiring = list.filter(t=>{
    if(!t.plan_expires) return false;
    const diff = Math.ceil((new Date(t.plan_expires)-now)/86400000);
    return diff>=0 && diff<=7;
  });

  const sv=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
  sv('saas-total',   list.length);
  sv('saas-active',  active.length);
  sv('saas-pro',     proCount);
  sv('saas-mrr',     (mrr/1000).toFixed(0)+'K');
  sv('saas-orders',  totalOrders.toLocaleString());
  sv('saas-expiring',expiring.length);

  // Also update dashboard stats
  sv('p-total-tenants', list.length);
  sv('p-active-tenants', active.length);

  // Render table
  const tbody = document.getElementById('saas-tbody');
  if(!tbody) return;

  if(!list.length){
    tbody.innerHTML='<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--muted)">No tenants found</td></tr>';
    return;
  }

  tbody.innerHTML = list.map((t,idx)=>{
    const orderingUrl = location.origin + '/index.html?t=' + (t.slug||t.id);
    const adminUrl    = location.origin + '/tenant.php';
    const exp         = t.plan_expires?.slice(0,10);
    const expDays     = exp ? Math.ceil((new Date(exp)-now)/86400000) : null;
    const expColor    = expDays!==null && expDays<=7 ? '#dc2626' : expDays!==null && expDays<=30 ? '#d97706' : 'var(--muted)';
    const revenue     = parseInt(t.total_revenue||0);
    return \`<tr data-id="\${t.id}" data-idx="\${idx}">
      <td style="text-align:center;padding:.3rem .5rem">
        <div style="display:flex;flex-direction:column;gap:1px">
          <button onclick="saasMoveRow(\${t.id},-1)" title="Move up"
            style="background:none;border:0.5px solid var(--border);border-radius:4px;cursor:pointer;color:var(--muted);font-size:.7rem;padding:1px 4px;line-height:1">▲</button>
          <button onclick="saasMoveRow(\${t.id},1)" title="Move down"
            style="background:none;border:0.5px solid var(--border);border-radius:4px;cursor:pointer;color:var(--muted);font-size:.7rem;padding:1px 4px;line-height:1">▼</button>
        </div>
      </td>
      <td style="color:var(--muted);font-size:.78rem;font-weight:500">\${t.id}</td>
      <td>
        <div style="font-weight:500;font-size:.88rem">\${escH(t.name)}</div>
        <div style="font-size:.72rem;color:var(--muted)">\${t.owner_email||''}</div>
        <div style="font-size:.7rem;color:var(--muted)">\${t.owner_phone||''}</div>
      </td>
      <td>
        <div style="font-family:monospace;font-size:.78rem;color:var(--accent)">\${t.slug||'—'}</div>
        <div style="display:flex;gap:.3rem;margin-top:.3rem">
          <a href="\${orderingUrl}" target="_blank"
            style="font-size:.68rem;padding:1px 6px;border-radius:4px;background:rgba(99,102,241,.1);color:var(--accent);text-decoration:none">🛒 Order</a>
          <a href="\${adminUrl}" target="_blank"
            style="font-size:.68rem;padding:1px 6px;border-radius:4px;background:rgba(99,102,241,.1);color:var(--accent);text-decoration:none">👤 Admin</a>
        </div>
      </td>
      <td>
        <span style="font-size:.72rem;padding:2px 8px;border-radius:99px;background:\${planColors[t.plan]||'#888'}22;color:\${planColors[t.plan]||'#888'};font-weight:600">
          \${(t.plan||'').toUpperCase()}
        </span>
      </td>
      <td style="font-size:.82rem;text-align:right">\${parseInt(t.total_orders||0).toLocaleString()}</td>
      <td style="font-size:.82rem;text-align:right;font-weight:\${revenue>0?'600':'400'}">\${revenue>0?(revenue/1000).toFixed(0)+'K':'—'}</td>
      <td style="font-size:.78rem;color:\${expColor}">\${exp||'—'}\${expDays!==null?'<div style="font-size:.68rem">'+expDays+'d</div>':''}</td>
      <td>
        <span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:\${t.is_active?'rgba(5,150,105,.1)':'rgba(220,38,38,.1)'};color:\${t.is_active?'#059669':'#dc2626'}">
          \${t.is_active?'✓ Active':'✗ Off'}
        </span>
      </td>
      <td>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm" onclick="openEditTenant(\${t.id})" title="Edit">✏️</button>
          <button class="btn btn-ghost btn-sm" onclick="impersonateAsTenant(\${t.id},'\${escH(t.name)}')" title="View as tenant">👤</button>
          <button class="btn btn-ghost btn-sm" onclick="downloadTenantBackup(\${t.id},'\${escH(t.name)}')" title="Download backup">💾</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleTenant(\${t.id},\${t.is_active})"
            style="color:\${t.is_active?'#dc2626':'#059669'}" title="\${t.is_active?'Suspend':'Activate'}">
            \${t.is_active?'⊘':'✓'}
          </button>
        </div>
      </td>
    </tr>\`;
  }).join('');

  const countEl = document.getElementById('saas-filter-count');
  if(countEl) countEl.textContent = list.length + ' tenants';
}

function saasFilter(){
  const q      = document.getElementById('saas-search')?.value?.toLowerCase()||'';
  const plan   = document.getElementById('saas-plan-filter')?.value||'';
  const status = document.getElementById('saas-status-filter')?.value;
  const sort   = document.getElementById('saas-sort')?.value||'id_asc';

  let list = [...(window._saasTenants||[])];

  // Filter
  if(q)      list = list.filter(t=> (t.name||'').toLowerCase().includes(q)||(t.owner_email||'').toLowerCase().includes(q)||(t.slug||'').toLowerCase().includes(q));
  if(plan)   list = list.filter(t=> t.plan===plan);
  if(status!==''&&status!==undefined) list = list.filter(t=> String(t.is_active)===status);

  // Sort
  const sortFns = {
    id_asc:      (a,b)=>a.id-b.id,
    id_desc:     (a,b)=>b.id-a.id,
    name_asc:    (a,b)=>(a.name||'').localeCompare(b.name||''),
    orders_desc: (a,b)=>(parseInt(b.total_orders)||0)-(parseInt(a.total_orders)||0),
    revenue_desc:(a,b)=>(parseInt(b.total_revenue)||0)-(parseInt(a.total_revenue)||0),
    created_desc:(a,b)=>new Date(b.created_at||0)-new Date(a.created_at||0),
  };
  list.sort(sortFns[sort]||sortFns.id_asc);

  saasRender(list);
}

function saasFilterExpiring(){
  document.getElementById('saas-sort').value='id_asc';
  const now=new Date();
  const exp = (window._saasTenants||[]).filter(t=>{
    if(!t.plan_expires) return false;
    const d=Math.ceil((new Date(t.plan_expires)-now)/86400000);
    return d>=0&&d<=7;
  });
  saasRender(exp);
  toast(exp.length+' expiring tenants');
}

function saasMoveRow(id, dir){
  if(!window._saasTenants) return;
  const idx = window._saasTenants.findIndex(t=>t.id===id);
  if(idx===-1) return;
  const newIdx = idx+dir;
  if(newIdx<0||newIdx>=window._saasTenants.length) return;
  // Swap
  [window._saasTenants[idx], window._saasTenants[newIdx]] = [window._saasTenants[newIdx], window._saasTenants[idx]];
  saasRender(window._saasTenants);
}

function openEditTenant(id){
  const t = (window._saasTenants||[]).find(t=>t.id===id);
  if(!t) return;
  document.getElementById('edit-tenant-id').value    = id;
  document.getElementById('edit-tenant-title').textContent = 'Edit: '+t.name;
  document.getElementById('edit-tenant-name').value  = t.name||'';
  document.getElementById('edit-tenant-plan').value  = t.plan||'free';
  document.getElementById('edit-tenant-expires').value = t.plan_expires?.slice(0,10)||'';
  document.getElementById('edit-tenant-active').checked = !!t.is_active;
  openModal('modal-edit-tenant');
}

async function saveTenantEdit(){
  const id      = parseInt(document.getElementById('edit-tenant-id').value);
  const name    = document.getElementById('edit-tenant-name').value.trim();
  const plan    = document.getElementById('edit-tenant-plan').value;
  const expires = document.getElementById('edit-tenant-expires').value;
  const active  = document.getElementById('edit-tenant-active').checked ? 1 : 0;

  const d = await fetch('tenant_api.php?action=update_tenant',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({tenant_id:id, name, plan, plan_expires:expires, is_active:active})
  }).then(r=>r.json()).catch(()=>({ok:false}));

  if(d.ok){
    toast('✅ Tenant updated','ok');
    closeModal('modal-edit-tenant');
    await loadSaas();
  } else {
    toast(d.msg||'Error','err');
  }
}

function saasExportCSV(){
  const list = window._saasTenants||[];
  if(!list.length){ toast('No data','err'); return; }
  const headers = ['ID','Name','Email','Phone','Slug','Plan','Expires','Active','Orders','Revenue','Created'];
  const rows = list.map(t=>[
    t.id, t.name, t.owner_email, t.owner_phone, t.slug,
    t.plan, t.plan_expires?.slice(0,10)||'', t.is_active?'Yes':'No',
    t.total_orders||0, t.total_revenue||0, t.created_at?.slice(0,10)||''
  ]);
  const csv = [headers,...rows].map(r=>r.map(v=>'"'+(v||'').toString().replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob = new Blob([csv],{type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'myanai-tenants-'+new Date().toISOString().slice(0,10)+'.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  toast('✅ CSV exported','ok');
}

async function toggleTenant(tenantId, currentActive) {
  if(!confirm(`${currentActive ? 'Disable' : 'Enable'} this tenant?`)) return;
  const d = await fetch(`tenant_api.php?action=toggle&tenant_id=${tenantId}`, {method:'POST'}).then(r=>r.json());
  if(d.ok) { toast(d.message||'Updated'); loadSaas(); }
  else toast('Error: ' + (d.msg||'unknown'));
}


/* ═══════════════════════════════════════
   PLAN UPGRADE
═══════════════════════════════════════ */
var _upgradePlans = [];
var _upgradeTargetPlan = null;

async function loadUpgrade() {
  const planColors = {free:'#6b7280',basic:'#2563eb',pro:'#059669',enterprise:'#7c3aed'};
  const planLabel    = document.getElementById('upgrade-plan-label');
  const expiresLabel = document.getElementById('upgrade-expires-label');
  if (planLabel) {
    const plan = window.__TENANT_PLAN || 'free';
    planLabel.innerHTML = `<span style="background:${planColors[plan]||'#888'};color:#fff;padding:2px 10px;border-radius:99px;font-size:.9rem">${plan.toUpperCase()}</span>`;
  }
  if (expiresLabel) {
    if (window.__PLAN_EXPIRES) {
      const d = new Date(window.__PLAN_EXPIRES);
      const diff = Math.ceil((d - new Date()) / 86400000);
      expiresLabel.textContent = diff <= 0
        ? '⚠️ သက်တမ်းကုန်သွားပြီ'
        : `သက်တမ်း: ${d.toLocaleDateString('en-GB')} (${diff} ရက် ကျန်)`;
    } else {
      expiresLabel.textContent = '';
    }
  }
  document.getElementById('upgrade-request-section').style.display = 'none';
  document.getElementById('upgrade-success').style.display = 'none';

  const grid = document.getElementById('upgrade-plans-grid');
  if (!grid) return;
  grid.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--muted)">Loading…</div>';

  const d = await fetch('tenant_api.php?action=plans').then(r=>r.json()).catch(()=>({ok:false}));
  if (!d.ok) { grid.innerHTML = '<div style="color:red;padding:1rem">Plans load မဖြစ်ဘူး</div>'; return; }

  _upgradePlans = d.plans || [];
  const currentPlan = window.__TENANT_PLAN || 'free';
  const planOrder   = ['free','basic','pro','enterprise'];
  const currentIdx  = planOrder.indexOf(currentPlan);
  const planEmoji   = {free:'🆓',basic:'⭐',pro:'🚀',enterprise:'🏢'};
  const planFeatures = {
    free:       ['1 branch','3 staff','20 menu items'],
    basic:      ['1 branch','5 staff','50 menu items'],
    pro:        ['3 branches','15 staff','200 menu items'],
    enterprise: ['10 branches','50 staff','500 menu items'],
  };

  grid.innerHTML = _upgradePlans.map(p => {
    const pIdx   = planOrder.indexOf(p.code);
    const isCur  = p.code === currentPlan;
    const isDown = pIdx < currentIdx;
    const border = isCur ? 'var(--primary)' : 'var(--border)';
    const features = (planFeatures[p.code]||[]).map(f=>
      `<li style="padding:.2rem 0;font-size:.82rem;color:var(--muted)">✓ ${f}</li>`
    ).join('');
    const priceMMK = parseInt(p.price_mmk||0).toLocaleString();
    const priceUSD = p.price_usd ? `($${p.price_usd}/mo)` : '';
    return `<div style="background:var(--card);border:2px solid ${border};border-radius:var(--radius);padding:1.2rem;display:flex;flex-direction:column;gap:.5rem${isCur?';box-shadow:0 0 0 3px rgba(232,76,43,.15)':''}">
      <div style="font-size:1.4rem">${planEmoji[p.code]||'📦'}</div>
      <div style="font-weight:700;font-size:1rem">${p.name}</div>
      <div style="font-size:1.1rem;font-weight:600;color:var(--primary)">${priceMMK==='0'?'Free':priceMMK+' MMK'} <span style="font-size:.75rem;color:var(--muted);font-weight:400">${priceUSD}</span></div>
      <ul style="list-style:none;padding:0;margin:0">${features}</ul>
      ${isCur
        ? `<div style="margin-top:auto;padding:.45rem;text-align:center;background:var(--warm);border-radius:6px;font-size:.8rem;font-weight:600">✓ လက်ရှိ Plan</div>`
        : isDown
        ? `<div style="margin-top:auto;padding:.45rem;text-align:center;color:var(--muted);font-size:.78rem">Downgrade မရနိုင်</div>`
        : `<button onclick="selectUpgradePlan('${p.code}','${p.name}')" style="margin-top:auto;padding:.5rem;border:none;border-radius:6px;background:var(--primary);color:#fff;font-weight:600;cursor:pointer;font-size:.85rem">⬆ ${p.name} သို့ Upgrade</button>`
      }</div>`;
  }).join('');
}

function selectUpgradePlan(code, name) {
  _upgradeTargetPlan = code;
  document.getElementById('upgrade-target-plan-name').textContent = name;
  document.getElementById('upgrade-note').value = '';
  document.getElementById('upgrade-request-section').style.display = '';
  document.getElementById('upgrade-success').style.display = 'none';
  document.getElementById('upgrade-request-section').scrollIntoView({behavior:'smooth',block:'nearest'});
}

async function submitUpgradeRequest() {
  if (!_upgradeTargetPlan) return;
  const note = document.getElementById('upgrade-note').value.trim();
  const btn  = document.getElementById('upgrade-submit-btn');
  btn.disabled = true; btn.textContent = 'Sending…';
  const d = await fetch('tenant_api.php?action=request_upgrade', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({plan:_upgradeTargetPlan, note, tenant_id:window.__TENANT_ID, tenant_name:window.__TENANT_NAME, current_plan:window.__TENANT_PLAN})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  btn.disabled = false; btn.textContent = '📩 Request ပို့မည်';
  if (d.ok) {
    document.getElementById('upgrade-request-section').style.display = 'none';
    document.getElementById('upgrade-success').style.display = '';
    toast('✅ Upgrade request ပေးပို့ပြီးပါပြီ');
  } else { toast(d.msg||'Error occurred','err'); }
}

/* ═══════════════════════════════════════
   CROSS-BRANCH ANALYTICS
═══════════════════════════════════════ */
var _cbaChartRevenue = null;
var _cbaChartOrders  = null;

async function loadCrossBranchAnalytics() {
  const section = document.getElementById('cross-branch-analytics');
  if (!section) return;

  // Show section only if multi-branch context
  const tid = window.__TENANT_ID || window._currentTenant || 0;
  if (tid <= 0 && !window.__IS_TENANT) {
    // Super-admin viewing all — still show
  }
  section.style.display = '';

  const days  = document.getElementById('cba-range')?.value || 30;
  const to    = new Date().toISOString().slice(0,10);
  const from  = new Date(Date.now() - days * 86400000).toISOString().slice(0,10);
  const qs    = `action=branches&from=${from}&to=${to}${tid > 0 ? '&tenant_id='+tid : ''}`;

  const d = await fetch('reports_api.php?' + qs).then(r=>r.json()).catch(()=>({ok:false}));
  if (!d.ok || !d.branches?.length) {
    document.getElementById('cba-tbody').innerHTML =
      '<tr><td colspan="5" style="text-align:center;padding:1rem;color:var(--muted)">No data for this period</td></tr>';
    return;
  }

  const branches = d.branches;
  const labels   = branches.map(b => b.name || b.code);
  const revenues = branches.map(b => Math.round(b.revenue));
  const orders   = branches.map(b => parseInt(b.total_orders));

  // Color palette
  const palette = ['#e84c2b','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f97316'];
  const colors  = branches.map((_, i) => palette[i % palette.length]);

  // ── Revenue Chart ──
  const ctxR = document.getElementById('chart-branch-revenue');
  if (ctxR) {
    if (_cbaChartRevenue) { _cbaChartRevenue.destroy(); _cbaChartRevenue = null; }
    _cbaChartRevenue = new Chart(ctxR, {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Revenue (MMK)', data: revenues, backgroundColor: colors, borderRadius: 6, borderSkipped: false }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: {
          callbacks: { label: ctx => ' ' + ctx.raw.toLocaleString() + ' MMK' }
        }},
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#9ca3af' } },
          y: { grid: { color: 'rgba(0,0,0,.06)' }, ticks: { font: { size: 10 }, color: '#9ca3af',
            callback: v => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v
          }}
        }
      }
    });
  }

  // ── Orders Chart ──
  const ctxO = document.getElementById('chart-branch-orders');
  if (ctxO) {
    if (_cbaChartOrders) { _cbaChartOrders.destroy(); _cbaChartOrders = null; }
    _cbaChartOrders = new Chart(ctxO, {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Orders', data: orders, backgroundColor: colors, borderRadius: 6, borderSkipped: false }] },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#9ca3af' } },
          y: { grid: { color: 'rgba(0,0,0,.06)' }, ticks: { font: { size: 10 }, color: '#9ca3af', stepSize: 1 } }
        }
      }
    });
  }

  // ── Table ──
  const tbody = document.getElementById('cba-tbody');
  if (tbody) {
    const total_rev = revenues.reduce((a,b)=>a+b, 0);
    tbody.innerHTML = branches.map((b, i) => {
      const pct = total_rev > 0 ? Math.round((b.revenue / total_rev) * 100) : 0;
      return `<tr style="border-bottom:1px solid var(--border)">
        <td style="padding:.5rem .6rem;display:flex;align-items:center;gap:.5rem">
          <span style="width:10px;height:10px;border-radius:50%;background:${colors[i]};display:inline-block;flex-shrink:0"></span>
          <span>${b.name || b.code}</span>
          <span style="font-size:.72rem;color:var(--muted)">${pct}%</span>
        </td>
        <td style="padding:.5rem .6rem;text-align:right">${parseInt(b.total_orders).toLocaleString()}</td>
        <td style="padding:.5rem .6rem;text-align:right;font-weight:600">${Math.round(b.revenue).toLocaleString()} <span style="font-size:.72rem;color:var(--muted)">MMK</span></td>
        <td style="padding:.5rem .6rem;text-align:right">${Math.round(b.avg_order).toLocaleString()}</td>
        <td style="padding:.5rem .6rem;text-align:right;color:${parseInt(b.cancelled)>0?'#dc2626':'var(--muted)'}">${b.cancelled}</td>
      </tr>`;
    }).join('') + `<tr style="font-weight:700;border-top:2px solid var(--border)">
      <td style="padding:.5rem .6rem">Total</td>
      <td style="padding:.5rem .6rem;text-align:right">${orders.reduce((a,b)=>a+b,0).toLocaleString()}</td>
      <td style="padding:.5rem .6rem;text-align:right">${total_rev.toLocaleString()} <span style="font-size:.72rem;color:var(--muted)">MMK</span></td>
      <td style="padding:.5rem .6rem;text-align:right">—</td>
      <td style="padding:.5rem .6rem;text-align:right">—</td>
    </tr>`;
  }
}

/* ═══════════════════════════════════════
   TENANT PAYMENT SETTINGS (KBZPay / Wave)
═══════════════════════════════════════ */
async function loadSettings() {
  if (!window.__IS_TENANT) return;
  const d = await fetch('admin.php?api=get_payment_settings', {credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if (!d.ok) return;
  const s = d.settings;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
  set('set-kpay-merchant', s.kpay_merchant_id);
  set('set-kpay-qr',       s.kpay_qr_image);
  set('set-wave-merchant', s.wave_merchant_id);
  set('set-wave-qr',       s.wave_qr_image);
  // Preview QR
  if (s.kpay_qr_image) {
    const prev = document.getElementById('set-kpay-preview');
    const img  = document.getElementById('set-kpay-preview-img');
    if (prev && img) { img.src = s.kpay_qr_image; prev.style.display = ''; }
  }
}

async function savePaymentSettings() {
  const get = id => document.getElementById(id)?.value?.trim() || '';
  const payload = {
    kpay_merchant_id: get('set-kpay-merchant'),
    kpay_qr_image:    get('set-kpay-qr'),
    wave_merchant_id: get('set-wave-merchant'),
    wave_qr_image:    get('set-wave-qr'),
  };
  const d = await fetch('admin.php?api=save_payment_settings', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(r=>r.json()).catch(()=>({ok:false}));

  const msg = document.getElementById('settings-saved-msg');
  if (d.ok) {
    toast('✅ Payment settings saved');
    if (msg) { msg.style.display = ''; setTimeout(() => msg.style.display = 'none', 3000); }
  } else {
    toast(d.msg || 'Save failed', 'err');
  }
}

function previewKpayQR(input) {
  const file = input?.files?.[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const base64 = e.target.result;
    const qrInput = document.getElementById('set-kpay-qr');
    if (qrInput) qrInput.value = base64;
    const prev = document.getElementById('set-kpay-preview');
    const img  = document.getElementById('set-kpay-preview-img');
    if (prev && img) { img.src = base64; prev.style.display = ''; }
    toast('✅ QR image loaded — Save Settings ကို နှိပ်ပါ');
  };
  reader.readAsDataURL(file);
}

/* ═══════════════════════════════════════
   PLATFORM ADMIN FUNCTIONS
═══════════════════════════════════════ */

/* ── Platform Dashboard ── */
async function loadPlatformDashboard() {
  const date = document.getElementById('dash-date');
  if(date) date.textContent = new Date().toLocaleDateString('en-GB',{weekday:'long',year:'numeric',month:'long',day:'numeric'});

  const [tenants, upgReqs] = await Promise.all([
    fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
    fetch('tenant_api.php?action=upgrade_requests',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
  ]);

  if(tenants.ok) {
    const list = tenants.tenants || [];
    const active = list.filter(t=>t.is_active==1);
    const now = new Date();
    const expiring = list.filter(t=>{
      if(!t.plan_expires) return false;
      const d = Math.ceil((new Date(t.plan_expires)-now)/86400000);
      return d>=0 && d<=7;
    });

    // MRR calc
    const planPrices = {free:0,basic:50000,pro:150000,enterprise:300000};
    const mrr = active.reduce((sum,t)=>sum+(planPrices[t.plan]||0),0);

    const sv = (id,v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
    sv('p-total-tenants', list.length);
    sv('p-active-tenants', active.length);
    sv('p-mrr', (mrr/1000).toFixed(0)+'K');
    sv('p-expiring', expiring.length);

    // Badge
    const badge = document.getElementById('tenant-count-badge');
    if(badge) badge.textContent = list.length;

    // Plan distribution chart
    const planDist = {free:0,basic:0,pro:0,enterprise:0};
    list.forEach(t=>planDist[t.plan]=(planDist[t.plan]||0)+1);
    const distEl = document.getElementById('plan-dist-chart');
    if(distEl) {
      const planColors = {free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
      const total = list.length || 1;
      distEl.innerHTML = Object.entries(planDist).map(([plan,count])=>`
        <div style="margin-bottom:.6rem">
          <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:3px">
            <span style="font-weight:500;text-transform:capitalize">${plan}</span>
            <span style="color:var(--muted)">${count} tenants</span>
          </div>
          <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:${Math.round(count/total*100)}%;background:${planColors[plan]};border-radius:99px;transition:width .4s"></div>
          </div>
        </div>`).join('');
    }

    // Recent tenants
    const tbody = document.getElementById('recent-tenants-body');
    if(tbody) {
      const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
      tbody.innerHTML = list.slice(-8).reverse().map(t=>`<tr>
        <td style="font-weight:500">${t.name}</td>
        <td><span style="font-size:.7rem;padding:1px 7px;border-radius:99px;background:${planColors[t.plan]||'#888'}22;color:${planColors[t.plan]||'#888'};font-weight:600">${(t.plan||'').toUpperCase()}</span></td>
        <td><span style="font-size:.7rem;color:${t.is_active?'#059669':'#dc2626'}">${t.is_active?'Active':'Suspended'}</span></td>
      </tr>`).join('');
    }

    // Expiring alert
    if(expiring.length > 0) {
      const alert = document.getElementById('upgrade-reqs-alert');
      if(alert) {
        alert.style.display='';
        const listEl = document.getElementById('upgrade-reqs-list');
        if(listEl) listEl.innerHTML = expiring.map(t=>`
          <div style="font-size:.82rem;padding:3px 0">• ${t.name} — expires ${t.plan_expires}</div>`).join('');
      }
    }

    // Analytics mini: plan distribution bars
    const miniDist = document.getElementById('plan-dist-mini');
    if(miniDist){
      const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
      const total2 = list.length||1;
      miniDist.innerHTML = ['free','basic','pro','enterprise'].map(plan=>{
        const cnt = list.filter(t=>t.plan===plan).length;
        const pct = Math.round(cnt/total2*100);
        return `<div style="display:flex;align-items:center;gap:.4rem;margin-bottom:4px;font-size:.75rem">
          <span style="width:55px;color:var(--muted);text-transform:capitalize">${plan}</span>
          <div style="flex:1;height:6px;background:var(--border);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:${pct}%;background:${planColors[plan]};border-radius:99px"></div>
          </div>
          <span style="width:20px;text-align:right;color:var(--muted)">${cnt}</span>
        </div>`;
      }).join('');
    }

    // Expiry mini
    const expiryMini = document.getElementById('expiry-mini');
    if(expiryMini){
      if(expiring.length){
        expiryMini.innerHTML = expiring.map(t=>`
          <div style="font-size:.78rem;padding:2px 0;color:#dc2626">• ${t.name}</div>`).join('');
        expiryMini.style.color='';
      } else {
        expiryMini.textContent = '✅ No plans expiring soon';
        expiryMini.style.color='#059669';
      }
    }
  }

  // Upgrade requests count
  if(upgReqs.ok) {
    const pending = (upgReqs.requests||[]).filter(r=>r.status==='pending');
    const sv = (id,v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
    sv('p-upgrade-reqs', pending.length);
    const badge = document.getElementById('upgrade-req-badge');
    if(badge) { badge.textContent = pending.length; badge.style.display = pending.length ? '' : 'none'; }
  }
}

/* ── Tenants ── */
var _allTenants = [];
async function loadTenants() {
  const d = await fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if(!d.ok) return;
  _allTenants = d.tenants || [];
  renderTenants(_allTenants);
}

function renderTenants(list) {
  const tbody = document.getElementById('tenants-tbody');
  if(!tbody) return;
  const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
  if(!list.length){ tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No tenants</td></tr>'; return; }
  list.sort((a,b)=>a.id-b.id);
  tbody.innerHTML = list.map(t=>{
    const expires = t.plan_expires ? t.plan_expires.slice(0,10) : '—';
    const diff = t.plan_expires ? Math.ceil((new Date(t.plan_expires)-new Date())/86400000) : null;
    const expColor = diff!==null && diff<=7 ? '#dc2626' : 'var(--muted)';
    return `<tr>
      <td style="color:var(--muted);font-size:.78rem">${t.id}</td>
      <td style="font-weight:500">${t.name}<div style="font-size:.72rem;color:var(--muted)">${t.owner_email||''}</div></td>
      <td style="font-size:.78rem;color:var(--muted)">${t.owner_email||'—'}</td>
      <td><span style="font-size:.7rem;padding:2px 8px;border-radius:99px;background:${planColors[t.plan]||'#888'}22;color:${planColors[t.plan]||'#888'};font-weight:600">${(t.plan||'').toUpperCase()}</span></td>
      <td style="font-size:.78rem;color:${expColor}">${expires}</td>
      <td><span style="font-size:.72rem;color:${t.is_active?'#059669':'#dc2626'}">${t.is_active?'✓ Active':'✗ Off'}</span></td>
      <td>
        <button class="btn btn-ghost btn-sm" onclick="impersonateAsTenant(${t.id},'${t.name}')">👤 View</button>
        <button class="btn btn-ghost btn-sm" onclick="downloadTenantBackup(${t.id},'${t.name}')">💾</button>
        <button class="btn btn-ghost btn-sm" onclick="toggleTenant(${t.id},${t.is_active})">${t.is_active?'Disable':'Enable'}</button>
        <button class="btn btn-ghost btn-sm" onclick="setTenantExpiry(${t.id},'${t.name}')">📅</button>
      </td>
    </tr>`;
  }).join('');
  const badge = document.getElementById('tenant-count-badge');
  if(badge) badge.textContent = list.length;
}

function filterTenants() {
  const q = document.getElementById('tenant-search')?.value?.toLowerCase()||'';
  const plan = document.getElementById('tenant-plan-filter')?.value||'';
  const filtered = _allTenants.filter(t=>{
    const matchQ = !q || t.name?.toLowerCase().includes(q) || t.owner_email?.toLowerCase().includes(q);
    const matchP = !plan || t.plan===plan;
    return matchQ && matchP;
  });
  renderTenants(filtered);
}

async function impersonateAsTenant(tid, name){
  if(!confirm(`"${name}" tenant အဖြစ် ဝင်မလား?\n\nAudit log မှာ မှတ်တမ်းတင်မည်။`)) return;
  const d = await fetch('admin.php?api=impersonate',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({tenant_id:tid})
  }).then(r=>r.json());
  if(d.ok){
    toast(`✅ ${name} အဖြစ် ဝင်နေပြီ — tenant.php သို့ redirect မည်...`,'ok');
    setTimeout(()=>window.open('tenant.php','_blank'),800);
  } else toast(d.msg||'Error','err');
}

async function setTenantExpiry(tid, name) {
  const d = prompt(`${name} — Set plan expiry date (YYYY-MM-DD):`);
  if(!d) return;
  const r = await fetch('tenant_api.php?action=set_expires',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({tenant_id:tid, plan_expires:d})
  }).then(r=>r.json());
  if(r.ok){ toast('✅ Expiry updated'); loadTenants(); }
  else toast(r.msg||'Error','err');
}

/* ── Revenue ── */
async function loadRevenue() {
  const d = await fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if(!d.ok) return;
  const list = d.tenants||[];
  const planPrices={free:0,basic:50000,pro:150000,enterprise:300000};
  const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
  const counts={free:0,basic:0,pro:0,enterprise:0};
  list.filter(t=>t.is_active).forEach(t=>counts[t.plan]=(counts[t.plan]||0)+1);
  const mrr = Object.entries(counts).reduce((s,[p,c])=>s+(planPrices[p]||0)*c,0);

  const sv=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
  sv('rev-mrr',(mrr).toLocaleString()+' MMK');
  sv('rev-free', counts.free);
  sv('rev-basic', counts.basic);
  sv('rev-pro', counts.pro);
  sv('rev-enterprise', counts.enterprise);

  const chart = document.getElementById('revenue-chart');
  if(chart) {
    const max = Math.max(...Object.values(counts))||1;
    chart.innerHTML = `<div style="display:flex;align-items:flex-end;gap:1rem;height:100%;padding:.5rem 0">` +
      Object.entries(counts).map(([plan,count])=>`
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px">
          <div style="font-size:.78rem;font-weight:600">${((planPrices[plan]||0)*count/1000).toFixed(0)}K</div>
          <div style="width:100%;background:${planColors[plan]};border-radius:6px 6px 0 0;height:${Math.round(count/max*140)}px;transition:height .4s;min-height:8px"></div>
          <div style="font-size:.72rem;color:var(--muted);text-transform:capitalize">${plan}<br>${count}</div>
        </div>`).join('') + `</div>`;
  }
}

/* ── Upgrade Requests ── */
async function loadUpgradeRequests() {
  const d = await fetch('tenant_api.php?action=upgrade_requests',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const tbody = document.getElementById('upgrades-tbody');
  if(!tbody) return;
  if(!d.ok||!d.requests?.length){
    tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No upgrade requests</td></tr>'; return;
  }
  const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
  tbody.innerHTML = d.requests.map(r=>`<tr>
    <td style="font-weight:500">${r.tenant_name||'#'+r.tenant_id}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${planColors[r.current_plan]||'#888'}22;color:${planColors[r.current_plan]||'#888'}">${(r.current_plan||'').toUpperCase()}</span></td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${planColors[r.requested_plan]||'#888'}22;color:${planColors[r.requested_plan]||'#888'}">${(r.requested_plan||'').toUpperCase()}</span></td>
    <td style="font-size:.78rem;color:var(--muted)">${r.note||'—'}</td>
    <td style="font-size:.78rem;color:var(--muted)">${r.created_at?.slice(0,10)||'—'}</td>
    <td>
      ${r.status==='pending' ? `
        <button class="btn btn-primary btn-sm" onclick="approveUpgrade(${r.id},${r.tenant_id},'${r.requested_plan}')">✓ Approve</button>
        <button class="btn btn-ghost btn-sm" onclick="rejectUpgrade(${r.id})">✗ Reject</button>
      ` : `<span style="font-size:.75rem;color:var(--muted)">${r.status}</span>`}
    </td>
  </tr>`).join('');
}

async function approveUpgrade(reqId, tenantId, plan) {
  if(!confirm(`Approve upgrade to ${plan.toUpperCase()}?`)) return;
  const r = await fetch('tenant_api.php?action=approve_upgrade',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({request_id:reqId, tenant_id:tenantId, plan})
  }).then(r=>r.json());
  if(r.ok){ toast('✅ Upgrade approved'); loadUpgradeRequests(); }
  else toast(r.msg||'Error','err');
}

async function rejectUpgrade(reqId) {
  if(!confirm('Reject this upgrade request?')) return;
  const r = await fetch('tenant_api.php?action=reject_upgrade',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({request_id:reqId})
  }).then(r=>r.json());
  if(r.ok){ toast('Rejected'); loadUpgradeRequests(); }
  else toast(r.msg||'Error','err');
}

/* ── Plans ── */
async function loadPlans() {
  const d = await fetch('tenant_api.php?action=plans',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const grid = document.getElementById('plans-grid');
  if(!grid||!d.ok) return;
  const planColors={free:'#6b7280',basic:'#3b82f6',pro:'#10b981',enterprise:'#8b5cf6'};
  const planEmoji={free:'🆓',basic:'⭐',pro:'🚀',enterprise:'🏢'};
  grid.innerHTML = d.plans.map(p=>`
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:1.2rem">
      <div style="font-size:1.3rem">${planEmoji[p.code]||'📦'}</div>
      <div style="font-weight:600;margin:.4rem 0">${p.name}</div>
      <div style="font-size:1rem;font-weight:600;color:${planColors[p.code]||'#888'}">${parseInt(p.price_mmk||0).toLocaleString()} MMK</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:.4rem">
        ${p.max_branches} branches · ${p.max_staff} staff · ${p.max_menu_items} items
      </div>
    </div>`).join('');
}

/* ── Landing Page CMS ── */
function lpTab(tab){
  // Hide all panels
  ['hero','brand','cta','announce','contact','demo','footer'].forEach(t=>{
    const panel=document.getElementById('lp-panel-'+t);
    const btn=document.getElementById('lp-tab-'+t);
    if(panel) panel.style.display='none';
    if(btn) btn.classList.remove('active');
  });
  // Show selected
  const p=document.getElementById('lp-panel-'+tab);
  const b=document.getElementById('lp-tab-'+tab);
  if(p) p.style.display='';
  if(b) b.classList.add('active');
}

async function loadLandingPage() {
  const d = await fetch('site_settings.php?action=get').then(r=>r.json()).catch(()=>({ok:false}));
  if(!d.ok||!d.settings) return;
  const s = d.settings;
  const set=(id,v)=>{const el=document.getElementById(id);if(el)el.value=v||'';};

  // Hero
  set('lp-title1',   s.hero_title_line1);
  set('lp-title2',   s.hero_title_line2);
  set('lp-subtitle', s.hero_subtitle);
  set('lp-desc',     s.hero_desc);
  set('lp-label',    s.hero_label);

  // Brand
  set('lp-store-name',  s.store_name);
  set('lp-emoji',       s.store_emoji);
  set('lp-page-title',  s.page_title);
  set('lp-tagline',     s.tagline);

  // CTA
  set('lp-cta1',     s.cta_primary_text);
  set('lp-cta1-url', s.cta_primary_url);
  set('lp-cta2',     s.cta_demo_text);
  set('lp-demo-url', s.cta_demo_url);
  set('lp-nav-cta',  s.nav_cta_text);

  // Announce
  set('lp-ann-text',      s.announcement_text);
  set('lp-ann-color-hex', s.announcement_color||'#6366f1');
  const annColor = document.getElementById('lp-ann-color');
  if(annColor) annColor.value = s.announcement_color||'#6366f1';
  const annOn = document.getElementById('lp-ann-on');
  if(annOn) annOn.checked = s.announcement_on==='1';

  // Contact
  set('lp-phone',     s.contact_phone);
  set('lp-email',     s.contact_email);
  set('lp-address',   s.contact_address);
  set('lp-facebook',  s.contact_facebook);
  set('lp-messenger', s.contact_messenger);
  set('lp-viber',     s.contact_viber);
  set('lp-instagram', s.contact_instagram);
  set('lp-tiktok',    s.contact_tiktok);

  // Demo
  set('lp-demo-heading', s.demo_heading);
  set('lp-demo-sub',     s.demo_sub);
  set('lp-demo-email',   s.demo_email);
  set('lp-demo-pass',    s.demo_password);
  set('lp-demo-btn',     s.demo_btn_text);

  // Footer
  set('lp-copyright',   s.footer_copyright);
  set('lp-foot-tagline',s.footer_tagline);
}

async function saveLandingPage() {
  const g=(id)=>document.getElementById(id)?.value?.trim()||'';
  const cb=(id)=>document.getElementById(id)?.checked?'1':'0';

  const payload = {
    // Hero
    hero_title_line1: g('lp-title1'),
    hero_title_line2: g('lp-title2'),
    hero_subtitle:    g('lp-subtitle'),
    hero_desc:        g('lp-desc'),
    hero_label:       g('lp-label'),
    // Brand
    store_name:       g('lp-store-name'),
    store_emoji:      g('lp-emoji'),
    page_title:       g('lp-page-title'),
    tagline:          g('lp-tagline'),
    // CTA
    cta_primary_text: g('lp-cta1'),
    cta_primary_url:  g('lp-cta1-url'),
    cta_demo_text:    g('lp-cta2'),
    cta_demo_url:     g('lp-demo-url'),
    nav_cta_text:     g('lp-nav-cta'),
    // Announce
    announcement_text:  g('lp-ann-text'),
    announcement_color: g('lp-ann-color-hex')||document.getElementById('lp-ann-color')?.value||'#6366f1',
    announcement_on:    cb('lp-ann-on'),
    // Contact
    contact_phone:    g('lp-phone'),
    contact_email:    g('lp-email'),
    contact_address:  g('lp-address'),
    contact_facebook: g('lp-facebook'),
    contact_messenger:g('lp-messenger'),
    contact_viber:    g('lp-viber'),
    contact_instagram:g('lp-instagram'),
    contact_tiktok:   g('lp-tiktok'),
    // Demo
    demo_heading:  g('lp-demo-heading'),
    demo_sub:      g('lp-demo-sub'),
    demo_email:    g('lp-demo-email'),
    demo_password: g('lp-demo-pass'),
    demo_btn_text: g('lp-demo-btn'),
    // Footer
    footer_copyright: g('lp-copyright'),
    footer_tagline:   g('lp-foot-tagline'),
  };

  const r = await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify(payload)
  }).then(r=>r.json());

  if(r.ok){
    toast('✅ Landing page saved');
    setTimeout(()=>window.open('landing-page.html?t='+Date.now(),'_blank'),500);
  } else toast(r.msg||'Error','err');
}

/* ── Demo Control ── */
async function loadDemoInfo() {
  const d = await fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const el = document.getElementById('demo-tenant-info');
  if(!el||!d.ok) return;
  const demo = (d.tenants||[]).find(t=>t.slug==='demo'||t.id==1);
  if(!demo){ el.textContent='Demo tenant (slug=demo) မရှိသေး'; return; }
  el.innerHTML=`
    <div style="line-height:2">
      Name: <strong>${demo.name}</strong><br>
      Plan: <strong>${demo.plan?.toUpperCase()}</strong><br>
      Status: <strong style="color:${demo.is_active?'#059669':'#dc2626'}">${demo.is_active?'Active':'Suspended'}</strong><br>
      ID: ${demo.id}
    </div>`;
}

async function resetDemoData() {
  if(!confirm('Demo tenant data ကို reset လုပ်မလား? (test orders, data clear ဖြစ်မည်)')) return;
  toast('🔄 Reset feature coming soon');
}

/* ── Announcement save ── */
function loadAnnouncementPage(){} // placeholder — form is static
async function saveAnnouncement() {
  const msg = document.getElementById('ann-message')?.value?.trim()||'';
  const type = document.getElementById('ann-type')?.value||'info';
  const active = document.getElementById('ann-active')?.checked||false;
  const r = await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({announcement_text:msg, announcement_on:active?'1':'0'})
  }).then(r=>r.json());
  if(r.ok) toast('✅ Announcement saved');
  else toast(r.msg||'Error','err');
}

/* ── Per-tenant backup (admin) ── */
async function downloadTenantBackup(tid, name){
  toast(`⏳ ${name} backup ပြင်ဆင်နေသည်...`);
  try {
    const res = await fetch(`backup_api.php?action=export&tenant_id=${tid}`,{credentials:'include'});
    if(!res.ok) throw new Error('Export failed');
    const blob = await res.blob();
    const fname = res.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || `backup-${name}-${Date.now()}.json`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href=url; a.download=fname; document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
    const records = res.headers.get('X-Backup-Records') || '?';
    toast(`✅ ${name} — ${records} records downloaded`,'ok');
  } catch(e) {
    toast('Backup failed: '+e.message,'err');
  }
}

/* ── Change Admin Password ── */
async function changeAdminPassword(){
  const current = document.getElementById('pwd-current')?.value?.trim();
  const newPwd   = document.getElementById('pwd-new')?.value?.trim();
  const confirm  = document.getElementById('pwd-confirm')?.value?.trim();

  if(!current||!newPwd||!confirm){ toast('All fields required','err'); return; }
  if(newPwd.length < 8){ toast('Password must be at least 8 characters','err'); return; }
  if(newPwd !== confirm){ toast('Passwords do not match','err'); return; }

  const d = await fetch('admin.php?api=change_password',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({current_password:current, new_password:newPwd})
  }).then(r=>r.json()).catch(()=>({ok:false,msg:'Network error'}));

  if(d.ok){
    toast('✅ Password changed successfully','ok');
    ['pwd-current','pwd-new','pwd-confirm'].forEach(id=>{
      const el=document.getElementById(id); if(el) el.value='';
    });
  } else {
    toast(d.msg||'Error changing password','err');
  }
}
