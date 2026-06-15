<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
$pdo = getPDO();
$csrfToken = generateCsrfToken();

/* ── TENANT LOGIN API ── */
if (isset($_GET['api'])) {

    /* Parse body for POST requests */
    $body = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : [];
    if ($_GET['api'] === 'login') {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $attempts = $_SESSION['login_attempts'] ?? 0;
        if ($attempts >= 5) {
            echo json_encode(['ok'=>false,'msg'=>'Too many attempts. Try again later.']);
            exit;
        }
        $inputUser = trim($body['username'] ?? '');
        $inputPass = trim($body['password'] ?? '');

        /* Tenant login: email + password in tenants.settings */
        if (!empty($inputUser) && str_contains($inputUser,'@')) {
            $tRow = $pdo->prepare("SELECT id,name,slug,plan,plan_expires,is_active,settings FROM tenants WHERE owner_email=? AND is_active=1");
            $tRow->execute([$inputUser]);
            $tenant = $tRow->fetch(PDO::FETCH_ASSOC);
            if ($tenant) {
                $tSettings = json_decode($tenant['settings'] ?? '{}', true);
                $tHash = $tSettings['admin_pass_hash'] ?? '';
                if ($tHash && password_verify($inputPass, $tHash)) {
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['tenant_admin'] = ['user'=>$inputUser,'role'=>'tenant'];
                    $_SESSION['tenant_id']    = $tenant['id'];
                    $_SESSION['tenant_slug']  = $tenant['slug'];
                    $_SESSION['tenant_name']  = $tenant['name'];
                    $_SESSION['tenant_plan']  = $tenant['plan'];
                    $_SESSION['tenant_plan_expires'] = $tenant['plan_expires'] ?? null;
                    $_SESSION['login_time']   = time();
                    echo json_encode(['ok'=>true,'role'=>'tenant','name'=>$tenant['name'],'plan'=>$tenant['plan']]);
                    exit;
                }
            }
        }
        $_SESSION['login_attempts'] = ($attempts + 1);
        echo json_encode(['ok'=>false,'msg'=>'Invalid email or password']);
        exit;
    }

    if ($_GET['api'] === 'logout') {
        session_destroy();
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── TENANT API CALLS ── */
    if (empty($_SESSION['tenant_admin'])) {
        echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
    }
    $tid = (int)($_SESSION['tenant_id'] ?? 0);

    /* get_payment_settings */
    if ($_GET['api'] === 'get_payment_settings') {
        $row = $pdo->prepare("SELECT settings FROM tenants WHERE id=?");
        $row->execute([$tid]);
        $s = json_decode($row->fetchColumn() ?: '{}', true) ?: [];
        echo json_encode(['ok'=>true,'settings'=>[
            'kpay_merchant_id' => $s['kpay_merchant_id'] ?? '',
            'kpay_qr_image'    => $s['kpay_qr_image']    ?? '',
            'wave_merchant_id' => $s['wave_merchant_id'] ?? '',
            'wave_qr_image'    => $s['wave_qr_image']    ?? '',
        ]]);
        exit;
    }

    /* save_payment_settings */
    if ($_GET['api'] === 'save_payment_settings') {
        $b = $body;
        $row = $pdo->prepare("SELECT settings FROM tenants WHERE id=?");
        $row->execute([$tid]);
        $existing = json_decode($row->fetchColumn() ?: '{}', true) ?: [];
        foreach (['kpay_merchant_id','kpay_qr_image','wave_merchant_id','wave_qr_image'] as $field) {
            if (array_key_exists($field, $b)) $existing[$field] = htmlspecialchars($b[$field]);
        }
        $pdo->prepare("UPDATE tenants SET settings=? WHERE id=?")->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── STATS ── */
    if ($_GET['api'] === 'stats') {
        $bid = (int)($_GET['branch_id'] ?? 0);
        $bWhere = $bid ? "AND o.branch_id=:bid" : "";
        $params = [':tid' => $tid];
        if ($bid) $params[':bid'] = $bid;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE o.tenant_id=:tid AND DATE(o.created_at)=CURDATE() $bWhere");
        $stmt->execute($params);
        $today = (int)$stmt->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders o WHERE o.tenant_id=:tid AND DATE(o.created_at)=CURDATE() AND o.status!='cancelled' $bWhere");
        $stmt2->execute($params);
        $revenue = (float)$stmt2->fetchColumn();

        $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE o.tenant_id=:tid AND o.status='pending' $bWhere");
        $stmt3->execute($params);
        $pending = (int)$stmt3->fetchColumn();

        $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM branch_stock bs JOIN branches b ON b.id=bs.branch_id WHERE b.tenant_id=:tid AND bs.stock_qty<=5");
        $stmt4->execute([':tid' => $tid]);
        $low = (int)$stmt4->fetchColumn();

        echo json_encode(['ok'=>true,'today'=>$today,'revenue'=>$revenue,'pending'=>$pending,'low'=>$low]);
        exit;
    }

    /* ── ORDERS ── */
    if ($_GET['api'] === 'orders') {
        $bid   = (int)($_GET['branch_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? 50);
        $bWhere = $bid ? "AND branch_id=:bid" : "";
        $params = [':tid' => $tid];
        if ($bid) $params[':bid'] = $bid;
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE tenant_id=:tid $bWhere ORDER BY id DESC LIMIT $limit");
        $stmt->execute($params);
        echo json_encode(['ok'=>true,'orders'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /* ── ITEMS ── */
    if ($_GET['api'] === 'items') {
        $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE tenant_id=:tid ORDER BY is_active DESC, sort_order ASC, category, name");
        $stmt->execute([':tid' => $tid]);
        echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /* ── BRANCHES ── */
    if ($_GET['api'] === 'branches') {
        $stmt = $pdo->prepare("SELECT * FROM branches WHERE tenant_id=:tid AND is_active=1 ORDER BY name");
        $stmt->execute([':tid' => $tid]);
        echo json_encode(['ok'=>true,'branches'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

/* ── GET: serve HTML ── */
$loggedIn = !empty($_SESSION['tenant_admin']);
$tid      = (int)($_SESSION['tenant_id']   ?? 0);
$tname    = $_SESSION['tenant_name']        ?? '';
$tplan    = $_SESSION['tenant_plan']        ?? '';
$texpires = $_SESSION['tenant_plan_expires']?? null;

/* Redirect non-logged-in to login page (handled via JS) */
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MyanAi POS — Business Admin</title>
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
/* ── MyanAi Tenant Admin Theme ──
   Light: Warm Sand Glass (Theme 5)
   Dark:  Midnight Black  (Theme 2)
── */
:root {
  --ink:#1c1409;--paper:#f0e6d3;--warm:#fdf6ec;
  --card:rgba(253,246,236,.82);--card-solid:#fdf6ec;
  --border:rgba(28,20,9,.09);--muted:#8a7560;
  --accent:#1c1409;--accent2:#5c4a2a;--green:#2d6a4f;
  --radius:12px;--shadow:0 2px 16px rgba(28,20,9,.08);
  --sidebar-bg:rgba(253,246,236,.82);--sidebar-blur:blur(20px);
  --sidebar-border:rgba(28,20,9,.08);
  --sidebar-text:#1c1409;--sidebar-muted:#8a7560;
  --sidebar-active-bg:rgba(28,20,9,.08);
  --sidebar-active-text:#1c1409;--sidebar-active-bar:#1c1409;
  --topbar-bg:rgba(253,246,236,.75);--topbar-blur:blur(16px);
  --logo-mark-bg:#1c1409;--logo-mark-text:#fdf6ec;--logo-mark-radius:50%;
  --stat-bg:rgba(255,255,255,.65);--icon-btn-bg:rgba(28,20,9,.07);
}
[data-theme="dark"] {
  --ink:#f5f5f7;--paper:#000;--warm:#1c1c1e;
  --card:rgba(28,28,30,.92);--card-solid:#1c1c1e;
  --border:rgba(255,255,255,.07);--muted:rgba(235,235,240,.45);
  --accent:#f5f5f7;--accent2:#ebebf0;--green:#34c759;
  --shadow:0 2px 24px rgba(0,0,0,.5);
  --sidebar-bg:rgba(28,28,30,.92);--sidebar-blur:blur(24px);
  --sidebar-border:rgba(255,255,255,.06);
  --sidebar-text:#f5f5f7;--sidebar-muted:rgba(235,235,240,.45);
  --sidebar-active-bg:rgba(255,255,255,.10);
  --sidebar-active-text:#fff;--sidebar-active-bar:rgba(255,255,255,.7);
  --topbar-bg:rgba(28,28,30,.80);--topbar-blur:blur(16px);
  --logo-mark-bg:rgba(255,255,255,.10);--logo-mark-text:#f5f5f7;
  --stat-bg:rgba(255,255,255,.05);--icon-btn-bg:rgba(255,255,255,.08);
}
@media(prefers-color-scheme:dark){
  :root:not([data-theme="light"]){
    --ink:#f5f5f7;--paper:#000;--warm:#1c1c1e;
    --card:rgba(28,28,30,.92);--border:rgba(255,255,255,.07);
    --muted:rgba(235,235,240,.45);--sidebar-bg:rgba(28,28,30,.92);
    --sidebar-border:rgba(255,255,255,.06);--sidebar-text:#f5f5f7;
    --sidebar-muted:rgba(235,235,240,.45);--sidebar-active-bg:rgba(255,255,255,.10);
    --sidebar-active-text:#fff;--sidebar-active-bar:rgba(255,255,255,.7);
    --topbar-bg:rgba(28,28,30,.80);--logo-mark-bg:rgba(255,255,255,.10);
    --stat-bg:rgba(255,255,255,.05);
  }
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans','Noto Sans Myanmar',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;transition:background .3s,color .3s}

/* LOGIN */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;background:var(--paper)}
.login-box{background:var(--card);backdrop-filter:var(--sidebar-blur);border-radius:20px;padding:2.5rem;width:100%;max-width:380px;box-shadow:var(--shadow);border:0.5px solid var(--border)}
.login-logo{font-size:1.4rem;font-weight:600;letter-spacing:-.4px;text-align:center;margin-bottom:.25rem;display:flex;align-items:center;justify-content:center;gap:.6rem}
.login-logo-mark{width:34px;height:34px;border-radius:50%;background:var(--logo-mark-bg);color:var(--logo-mark-text);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}
.login-sub{text-align:center;font-size:.83rem;color:var(--muted);margin-bottom:1.6rem}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.8rem;font-weight:500;margin-bottom:.35rem;color:var(--ink)}
.form-group input{width:100%;padding:.6rem .85rem;border:0.5px solid var(--border);border-radius:10px;background:var(--warm);color:var(--ink);font-size:.9rem;transition:border .15s;font-family:inherit}
.form-group input:focus{outline:none;border-color:var(--accent);background:var(--card)}
.btn-login{width:100%;padding:.72rem;background:var(--ink);color:var(--warm);border:none;border-radius:12px;font-size:.9rem;font-weight:600;cursor:pointer;margin-top:.5rem;transition:opacity .15s;font-family:inherit}
.btn-login:hover{opacity:.85}
.login-err{color:#dc2626;font-size:.8rem;text-align:center;min-height:1.2rem;margin-top:.5rem}

/* SHELL */
.shell{display:flex;min-height:100vh}
.sidebar{width:220px;background:var(--sidebar-bg);backdrop-filter:var(--sidebar-blur);-webkit-backdrop-filter:var(--sidebar-blur);color:var(--sidebar-text);flex-shrink:0;display:flex;flex-direction:column;border-right:0.5px solid var(--sidebar-border);position:fixed;top:0;left:0;bottom:0;z-index:200;transition:transform .25s}
.sidebar-logo{padding:1.1rem .9rem 1rem;border-bottom:0.5px solid var(--sidebar-border);display:flex;align-items:center;gap:.55rem}
.sidebar-logo-mark{width:28px;height:28px;border-radius:var(--logo-mark-radius);background:var(--logo-mark-bg);color:var(--logo-mark-text);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0}
.sidebar-logo-text{font-size:.83rem;font-weight:600;letter-spacing:-.3px;color:var(--sidebar-text);line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.sidebar-logo-tag{font-size:.58rem;font-weight:500;padding:.1rem .4rem;border-radius:99px;background:rgba(128,128,128,.12);color:var(--sidebar-muted);display:inline-block;margin-top:1px}
nav{flex:1;padding:.4rem 0;overflow-y:auto}
.nav-sect{font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;padding:8px 14px 3px;color:var(--sidebar-muted);opacity:.6}
.nav-item{display:flex;align-items:center;gap:.6rem;padding:.58rem .75rem;margin:.5px .5rem;border-radius:8px;cursor:pointer;font-size:.82rem;color:var(--sidebar-muted);transition:all .12s;border-left:2px solid transparent}
.nav-item:hover{background:rgba(128,128,128,.07);color:var(--sidebar-text)}
.nav-item.active{background:var(--sidebar-active-bg);color:var(--sidebar-active-text);font-weight:500;border-left:2px solid var(--sidebar-active-bar);border-radius:0 8px 8px 0;margin-left:0;padding-left:.85rem}
.nav-icon{font-size:1rem;width:18px;text-align:center;flex-shrink:0}
.nav-badge{font-size:.65rem;padding:1px 5px;border-radius:99px;margin-left:auto;background:rgba(128,128,128,.12);color:var(--sidebar-muted)}
.sidebar-foot{padding:.75rem .9rem;border-top:0.5px solid var(--sidebar-border);flex-shrink:0}
.sidebar-foot-inner{display:flex;align-items:center;gap:.6rem}
.foot-avatar{width:28px;height:28px;border-radius:50%;background:rgba(128,128,128,.1);display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:600;color:var(--sidebar-text);flex-shrink:0}
.foot-name{font-size:.78rem;font-weight:500;color:var(--sidebar-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.foot-role{font-size:.65rem;color:var(--sidebar-muted)}

/* MAIN */
.main{flex:1;margin-left:220px;display:flex;flex-direction:column;min-height:100vh;background:var(--paper)}
.page-head{padding:1.1rem 1.5rem;border-bottom:0.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--topbar-bg);backdrop-filter:var(--topbar-blur);position:sticky;top:0;z-index:10}
.page-title{font-size:1rem;font-weight:600;letter-spacing:-.3px}
.content{padding:1.4rem 1.8rem}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.2rem}
.stat-card{background:var(--stat-bg);backdrop-filter:var(--sidebar-blur);border-radius:var(--radius);padding:1rem 1.1rem;border:0.5px solid var(--border);cursor:pointer;transition:border-color .15s}
.stat-card:hover{border-color:var(--accent)}
.stat-val{font-size:1.5rem;font-weight:600;letter-spacing:-.5px;line-height:1}
.stat-lbl{font-size:.75rem;color:var(--muted);margin-top:.3rem}

/* TRIAL BANNER */
#trial-banner{display:none;padding:.6rem 1rem;align-items:center;gap:.75rem;font-size:.83rem;font-weight:500}

/* PLAN BADGE */
.plan-badge{font-size:.65rem;padding:.15rem .5rem;border-radius:99px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.plan-free{background:rgba(128,128,128,.12);color:var(--muted)}
.plan-basic{background:rgba(59,130,246,.12);color:#1d4ed8}
.plan-pro{background:rgba(16,185,129,.12);color:#065f46}
.plan-enterprise{background:rgba(139,92,246,.12);color:#4c1d95}

/* TABLE */
.table-wrap{background:var(--card);backdrop-filter:var(--sidebar-blur);border-radius:var(--radius);border:0.5px solid var(--border);overflow:hidden;margin-top:.75rem}
.table-toolbar{padding:.75rem 1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;border-bottom:0.5px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:.83rem}
thead th{padding:.6rem .9rem;text-align:left;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:0.5px solid var(--border)}
tbody tr{border-bottom:0.5px solid var(--border);transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(128,128,128,.04)}
td{padding:.65rem .9rem;color:var(--ink)}

/* FORMS */
.form-row{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem}
.field{flex:1;min-width:140px}
.field label{display:block;font-size:.75rem;font-weight:500;margin-bottom:.25rem;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.85rem;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--accent)}

/* BUTTONS */
.btn{padding:.5rem 1rem;border-radius:8px;font-size:.82rem;font-weight:500;cursor:pointer;border:none;transition:opacity .15s;font-family:inherit}
.btn:hover{opacity:.85}
.btn-primary{background:var(--ink);color:var(--warm)}
.btn-ghost{background:rgba(128,128,128,.1);color:var(--ink)}
.btn-danger{background:#dc2626;color:#fff}
.btn-sm{padding:.3rem .7rem;font-size:.76rem}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-overlay.open{display:flex}
.modal{background:var(--card-solid);border-radius:16px;padding:1.5rem;width:100%;max-width:480px;border:0.5px solid var(--border);max-height:90vh;overflow-y:auto}
.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.modal-title{font-size:.95rem;font-weight:600}
.modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted)}

/* TOAST */
.toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:var(--ink);color:var(--warm);padding:.6rem 1.2rem;border-radius:10px;font-size:.83rem;font-weight:500;z-index:999;opacity:0;transition:opacity .25s;pointer-events:none;white-space:nowrap}
.toast.show{opacity:1}

/* HAMBURGER */
.hamburger{display:none;background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--ink);padding:.2rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:150}

/* RESPONSIVE */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .hamburger{display:flex}
  .sidebar-overlay.show{display:block}
  .stats-grid{grid-template-columns:1fr 1fr}
  .content{padding:1rem}
}

/* HIDDEN */
.page{display:none}
</style>
</head>
<body>

<!-- LOGIN PAGE -->
<div id="login-wrap" class="login-wrap" <?= $loggedIn ? 'style="display:none"' : '' ?>>
  <div class="login-box">
    <div class="login-logo">
      <div class="login-logo-mark">M</div>
      <span>MyanAi <span style="color:var(--muted);font-weight:400">POS</span></span>
    </div>
    <div class="login-sub">Business Admin Portal</div>
    <div class="form-group">
      <label>Email</label>
      <input type="email" id="l-email" placeholder="your@email.com" autocomplete="email">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" id="l-pass" placeholder="••••••••" autocomplete="current-password">
    </div>
    <button class="btn-login" onclick="doTenantLogin()">Login →</button>
    <div class="login-err" id="login-err"></div>
  </div>
</div>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sb-overlay" onclick="closeSidebar()"></div>

<!-- ADMIN SHELL -->
<div class="shell" id="shell" <?= !$loggedIn ? 'style="display:none"' : '' ?>>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-mark">M</div>
      <div style="flex:1;min-width:0">
        <div class="sidebar-logo-text" id="sb-biz-name"><?= htmlspecialchars($tname ?: 'MyanAi POS') ?></div>
        <div class="sidebar-logo-tag">Business</div>
      </div>
      <button onclick="toggleTheme()" id="theme-btn" title="Toggle theme" style="background:none;border:none;cursor:pointer;font-size:.95rem;opacity:.55;flex-shrink:0;transition:opacity .15s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.55">☀️</button>
      <button onclick="closeSidebar()" id="sb-close" style="display:none;background:none;border:none;cursor:pointer;color:var(--sidebar-muted);font-size:1rem;flex-shrink:0">✕</button>
    </div>

    <!-- Trial banner inside sidebar -->
    <div id="trial-banner" style="font-size:.75rem;padding:.5rem .9rem;background:rgba(220,38,38,.08);color:#dc2626;border-bottom:0.5px solid rgba(220,38,38,.15)"></div>

    <nav>
      <div class="nav-sect">My Business</div>
      <div class="nav-item active" id="nav-dashboard" onclick="showPage('dashboard')">
        <span class="nav-icon">📊</span> Dashboard
      </div>
      <div class="nav-item" id="nav-menu" onclick="showPage('menu')">
        <span class="nav-icon">🍜</span> Menu items
        <span class="nav-badge" id="menu-count-badge"></span>
      </div>
      <div class="nav-item" id="nav-staff" onclick="showPage('staff')">
        <span class="nav-icon">👥</span> Staff
      </div>
      <div class="nav-item" id="nav-crm" onclick="showPage('crm')">
        <span class="nav-icon">❤️</span> CRM / Loyalty
      </div>
      <div class="nav-item" id="nav-stocklog" onclick="showPage('stocklog')">
        <span class="nav-icon">📋</span> Stock log
      </div>
      <div class="nav-item" id="nav-promos" onclick="showPage('promos')">
        <span class="nav-icon">🎁</span> Promotions
      </div>
      <div class="nav-item" id="nav-branches" onclick="showPage('branches')">
        <span class="nav-icon">🏢</span> My branches
      </div>

      <div class="nav-sect">Branch ops</div>
      <div style="padding:.3rem .6rem .4rem">
        <select id="branch-select" onchange="switchBranch(this.value)" style="width:100%;font-size:.78rem;padding:.35rem .5rem;border:0.5px solid var(--border);border-radius:7px;background:var(--warm);color:var(--ink)">
          <option value="0">All branches</option>
        </select>
      </div>
      <div class="nav-item" id="nav-orders" onclick="showPage('orders')">
        <span class="nav-icon">🧾</span> Orders
      </div>
      <div class="nav-item" id="nav-tables" onclick="showPage('tables')">
        <span class="nav-icon">🪑</span> Tables
      </div>
      <div class="nav-item" id="nav-reserve" onclick="showPage('reserve')">
        <span class="nav-icon">📅</span> Reservations
      </div>
      <div class="nav-item" id="nav-stock" onclick="showPage('stock')">
        <span class="nav-icon">📦</span> Stock
      </div>
      <div class="nav-item" id="nav-shift" onclick="showPage('shift')">
        <span class="nav-icon">⏰</span> Shifts
      </div>
      <div class="nav-item" id="nav-delivery" onclick="showPage('delivery')">
        <span class="nav-icon">🛵</span> Delivery
      </div>
      <div class="nav-item" id="nav-expenses" onclick="showPage('expenses')">
        <span class="nav-icon">💰</span> Expenses
      </div>

      <div class="nav-sect">Admin</div>
      <div class="nav-item" id="nav-upgrade" onclick="showPage('upgrade')">
        <span class="nav-icon">⬆</span> Plan upgrade
        <span class="nav-badge plan-badge" id="nav-plan-badge"></span>
      </div>
      <div class="nav-item" id="nav-storefront" onclick="showPage('storefront')">
        <span class="nav-icon">🎨</span> Storefront
      </div>
      <div class="nav-item" id="nav-settings" onclick="showPage('settings')">
        <span class="nav-icon">⚙️</span> Settings
      </div>
      <div class="nav-item" id="nav-schedule" onclick="showPage('schedule')">
        <span class="nav-icon">📅</span> Scheduling
      </div>
    </nav>

    <div class="sidebar-foot">
      <div class="sidebar-foot-inner">
        <div class="foot-avatar" id="foot-avatar">?</div>
        <div style="flex:1;min-width:0">
          <div class="foot-name" id="foot-name">Loading...</div>
          <div class="foot-role" id="foot-role">Tenant</div>
        </div>
        <button onclick="doTenantLogout()" title="Logout" style="background:none;border:none;cursor:pointer;color:var(--sidebar-muted);font-size:1rem;opacity:.55;transition:opacity .15s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.55">↩ Logout</button>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main" id="main-content">
    <div class="hamburger-bar" style="display:flex;align-items:center;gap:.75rem;padding:.7rem 1.2rem;background:var(--topbar-bg);backdrop-filter:var(--topbar-blur);border-bottom:0.5px solid var(--border);position:sticky;top:0;z-index:10" id="topbar">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <span id="topbar-title" style="font-size:.95rem;font-weight:600;letter-spacing:-.2px">Dashboard</span>
      <div style="margin-left:auto;display:flex;gap:.5rem;align-items:center">
        <span id="topbar-plan-badge" class="plan-badge"></span>
        <span id="topbar-biz" style="font-size:.78rem;color:var(--muted)"></span>
      </div>
    </div>

    <!-- Pages injected by JS -->
    <div id="page-container" style="flex:1"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ── Globals ── */
window.__IS_TENANT    = <?= $loggedIn ? 'true' : 'false' ?>;
window.__TENANT_ID    = <?= json_encode($tid) ?>;
window.__TENANT_NAME  = <?= json_encode($tname) ?>;
window.__TENANT_PLAN  = <?= json_encode($tplan) ?>;
window.__PLAN_EXPIRES = <?= json_encode($texpires) ?>;
window.__USER_ROLE    = 'tenant';
window._currentBranch = 0;
window._currentTenant = <?= json_encode($tid) ?>;

/* ── Theme ── */
(function initTheme(){
  const saved = localStorage.getItem('myanai_theme');
  const dark  = window.matchMedia('(prefers-color-scheme:dark)').matches;
  const t = saved || (dark ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', t);
  const btn = document.getElementById('theme-btn');
  if(btn) btn.textContent = t==='dark'?'🌙':'☀️';
})();
function toggleTheme(){
  const cur = document.documentElement.getAttribute('data-theme')||'light';
  const nxt = cur==='dark'?'light':'dark';
  document.documentElement.setAttribute('data-theme',nxt);
  localStorage.setItem('myanai_theme',nxt);
  const btn=document.getElementById('theme-btn');
  if(btn) btn.textContent=nxt==='dark'?'🌙':'☀️';
}

/* ── Sidebar ── */
function openSidebar(){
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sb-overlay').classList.add('show');
  const c=document.getElementById('sb-close'); if(c)c.style.display='block';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sb-overlay').classList.remove('show');
  const c=document.getElementById('sb-close'); if(c)c.style.display='none';
}

/* ── Toast ── */
function toast(msg, type){
  const el=document.getElementById('toast');
  el.textContent=msg;
  el.style.background=type==='err'?'#dc2626':type==='ok'?'#059669':'var(--ink)';
  el.classList.add('show');
  setTimeout(()=>el.classList.remove('show'),2800);
}

/* ── API helper ── */
async function api(action, params=''){
  const sep = params?'&':'';
  const r = await fetch(`tenant.php?api=${action}${sep}${params}`,{credentials:'include'});
  return r.json();
}
async function tenantApi(action, body=null){
  const opt = body ? {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),credentials:'include'} : {credentials:'include'};
  const r = await fetch(`tenant.php?api=${action}`,opt);
  return r.json();
}

/* ── showPage ── */
const ALL_PAGES = ['dashboard','menu','staff','crm','stocklog','promos','branches',
  'orders','tables','reserve','stock','shift','delivery','expenses',
  'upgrade','storefront','settings','schedule'];

function showPage(page){
  // Hide all pages
  ALL_PAGES.forEach(p=>{
    const el=document.getElementById('page-'+p);
    if(el) el.style.display='none';
    const nav=document.getElementById('nav-'+p);
    if(nav) nav.classList.remove('active');
  });
  // Show target
  const el=document.getElementById('page-'+page);
  if(el) el.style.display='';
  const nav=document.getElementById('nav-'+page);
  if(nav) nav.classList.add('active');

  // Update topbar title
  const titles={dashboard:'Dashboard',menu:'Menu items',staff:'Staff',crm:'CRM / Loyalty',
    stocklog:'Stock log',promos:'Promotions',branches:'My branches',orders:'Orders',
    tables:'Tables',reserve:'Reservations',stock:'Stock',shift:'Shifts',
    delivery:'Delivery',expenses:'Expenses',upgrade:'Plan upgrade',
    storefront:'Storefront',settings:'Settings',schedule:'Scheduling'};
  const tb=document.getElementById('topbar-title');
  if(tb) tb.textContent=titles[page]||page;

  // Load page data
  if(page==='dashboard')  initDashboard();
  if(page==='menu')       loadMenuItems();
  if(page==='staff')      loadStaff();
  if(page==='crm')        loadCRM();
  if(page==='stocklog')   loadStockLogs();
  if(page==='orders')     loadOrders();
  if(page==='tables')     loadTables();
  if(page==='reserve')    resLoad();
  if(page==='stock')      stockLoad();
  if(page==='shift')      schedLoad();
  if(page==='delivery')   delLoad();
  if(page==='expenses')   if(typeof expLoad==='function') expLoad();
  if(page==='promos')     promoLoad();
  if(page==='branches')   branchLoad();
  if(page==='upgrade')    loadUpgradePage();
  if(page==='settings')   loadTenantSettings();
  if(page==='schedule')   if(typeof schedLoad==='function') schedLoad();

  closeSidebar();
}

/* ── Branch selector ── */
function switchBranch(val){
  window._currentBranch = parseInt(val)||0;
  const page = document.querySelector('.nav-item.active')?.id?.replace('nav-','');
  if(page) showPage(page);
}
function branchParams(){
  const t = window._currentTenant ? `&tenant_id=${window._currentTenant}` : '';
  const b = window._currentBranch ? `&branch_id=${window._currentBranch}` : '';
  return t+b;
}

/* ── Login / Logout ── */
async function doTenantLogin(){
  const email = document.getElementById('l-email').value.trim();
  const pass  = document.getElementById('l-pass').value;
  const err   = document.getElementById('login-err');
  err.textContent='';
  if(!email||!pass){err.textContent='Email and password required';return;}
  const r = await fetch('tenant.php?api=login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:email,password:pass})});
  const d = await r.json();
  if(d.ok){
    window.__TENANT_NAME = d.name;
    window.__TENANT_PLAN = d.plan;
    window._currentTenant = d.tenant_id || window.__TENANT_ID;
    document.getElementById('login-wrap').style.display='none';
    document.getElementById('shell').style.display='flex';
    initShell();
    showPage('dashboard');
  } else {
    err.textContent = d.msg || 'Login failed';
  }
}
async function doTenantLogout(){
  await fetch('tenant.php?api=logout',{method:'POST',credentials:'include'});
  location.reload();
}

/* ── Init shell after login ── */
function initShell(){
  // Business name
  const name = window.__TENANT_NAME || '';
  const el=document.getElementById('sb-biz-name'); if(el) el.textContent=name;
  const topBiz=document.getElementById('topbar-biz'); if(topBiz) topBiz.textContent=name;
  // Avatar
  const av=document.getElementById('foot-avatar');
  if(av) av.textContent=(name||'T').slice(0,2).toUpperCase();
  const fn=document.getElementById('foot-name');
  if(fn) fn.textContent=name;
  // Plan badge
  const plan=window.__TENANT_PLAN||'free';
  const planBadge=document.getElementById('nav-plan-badge');
  if(planBadge){planBadge.textContent=plan.toUpperCase();planBadge.className='nav-badge plan-badge plan-'+plan;}
  const tbBadge=document.getElementById('topbar-plan-badge');
  if(tbBadge){tbBadge.textContent=plan.toUpperCase();tbBadge.className='plan-badge plan-'+plan;}
  // Trial expiry banner
  checkTrialBanner();
  // Load branch selector
  loadBranchOptions();
}

/* ── Trial banner ── */
function checkTrialBanner(){
  if(!window.__PLAN_EXPIRES) return;
  const diff = Math.ceil((new Date(window.__PLAN_EXPIRES)-new Date())/86400000);
  if(diff>7) return;
  const banner=document.getElementById('trial-banner');
  if(!banner) return;
  let color='#dc2626', icon='🔴', msg='';
  if(diff<=0){msg='သင့် plan သက်တမ်းကုန်သွားပြီ။ Upgrade လုပ်ပါ။';}
  else if(diff<=3){icon='🟠';msg=`Plan သက်တမ်း ${diff} ရက် သာကျန်တော့သည်။`;}
  else{color='#d97706';icon='🟡';msg=`Plan သက်တမ်း ${diff} ရက် ကျန် (${new Date(window.__PLAN_EXPIRES).toLocaleDateString('en-GB')})`;}
  banner.style.display='flex';
  banner.style.color=color;
  banner.innerHTML=`<span>${icon}</span><span style="flex:1">${msg}</span><button onclick="showPage('upgrade')" style="font-size:.7rem;padding:2px 8px;border:0.5px solid ${color};color:${color};border-radius:5px;background:none;cursor:pointer">Upgrade</button>`;
}

/* ── Load branch options ── */
async function loadBranchOptions(){
  const d = await api('branches');
  if(!d.ok||!d.branches) return;
  const sel=document.getElementById('branch-select');
  if(!sel) return;
  sel.innerHTML='<option value="0">All branches</option>';
  d.branches.forEach(b=>{
    sel.innerHTML+=`<option value="${b.id}">${b.name}</option>`;
  });
}

/* ── Dashboard ── */
function initDashboard(){
  loadStats();
  loadRecentOrders();
  loadCrossBranchChart();
}

async function loadStats(){
  const d = await api('stats', `branch_id=${window._currentBranch}`);
  if(!d.ok) return;
  const sv=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
  sv('stat-orders', d.today||0);
  sv('stat-revenue', (parseInt(d.revenue||0)).toLocaleString()+' K');
  sv('stat-pending', d.pending||0);
  sv('stat-low', d.low||0);
}

async function loadRecentOrders(){
  const d = await api('orders', `branch_id=${window._currentBranch}&limit=10`);
  if(!d.ok) return;
  const tbody=document.getElementById('recent-orders-body');
  if(!tbody) return;
  if(!d.orders?.length){tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--muted)">Orders မရှိသေး</td></tr>';return;}
  tbody.innerHTML=d.orders.map(o=>`<tr>
    <td style="font-weight:500">#${o.id}</td>
    <td>${o.customer_name||'Walk-in'}</td>
    <td>${parseInt(o.total_amount||0).toLocaleString()} MMK</td>
    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:99px;background:rgba(128,128,128,.1)">${o.status}</span></td>
    <td style="color:var(--muted);font-size:.78rem">${o.created_at?.slice(11,16)||''}</td>
  </tr>`).join('');
}

async function loadCrossBranchChart(){
  const to=new Date().toISOString().slice(0,10);
  const from=new Date(Date.now()-30*86400000).toISOString().slice(0,10);
  const d=await fetch(`reports_api.php?action=branches&from=${from}&to=${to}&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const area=document.getElementById('branch-chart-area');
  if(!area) return;
  if(!d.ok||!d.branches?.length){area.innerHTML='<div style="color:var(--muted);font-size:.82rem;padding:1rem">Branch data မရသေး</div>';return;}
  const max=Math.max(...d.branches.map(b=>parseFloat(b.revenue)||0))||1;
  area.innerHTML=d.branches.map((b,i)=>{
    const pct=Math.round((parseFloat(b.revenue)||0)/max*100);
    const colors=['#1c1409','#5c4a2a','#8a7560','#b4a99a'];
    return `<div style="margin-bottom:.6rem">
      <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:3px">
        <span style="color:var(--ink);font-weight:500">${b.name||b.code}</span>
        <span style="color:var(--muted)">${parseInt(b.revenue||0).toLocaleString()} MMK</span>
      </div>
      <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:${colors[i%colors.length]};border-radius:99px;transition:width .4s"></div>
      </div>
    </div>`;
  }).join('');
}

/* ── Menu ── */
async function loadMenuItems(){
  const d = await api('items');
  if(!d.ok) return;
  allItems = d.items||[];
  // Plan usage bar
  const planMax={free:20,basic:50,pro:200,enterprise:500};
  const max=planMax[window.__TENANT_PLAN]||20;
  const pct=Math.round((allItems.length/max)*100);
  const bar=document.getElementById('menu-usage-bar');
  const lbl=document.getElementById('menu-usage-lbl');
  if(bar) bar.style.width=Math.min(pct,100)+'%';
  if(bar) bar.style.background=pct>=100?'#dc2626':pct>=80?'#d97706':'#059669';
  if(lbl) lbl.textContent=`${allItems.length} / ${max} items (${window.__TENANT_PLAN?.toUpperCase()})`;
  // Menu count badge
  const badge=document.getElementById('menu-count-badge');
  if(badge) badge.textContent=allItems.length;
  renderMenuItems();
}

var allItems=[];
function renderMenuItems(){
  const tbody=document.getElementById('menu-tbody');
  if(!tbody) return;
  if(!allItems.length){tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Menu items မရှိသေး</td></tr>';return;}
  tbody.innerHTML=allItems.map(it=>`<tr>
    <td>${it.emoji||'🍽'} ${it.name}</td>
    <td style="color:var(--muted)">${it.category||'-'}</td>
    <td style="font-weight:600">${parseInt(it.price||0).toLocaleString()}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${it.is_active?'rgba(5,150,105,.1)':'rgba(128,128,128,.1)'};color:${it.is_active?'#065f46':'var(--muted)'}">${it.is_active?'Active':'Inactive'}</span></td>
    <td><button class="btn btn-ghost btn-sm" onclick="openEditItem(${it.id})">Edit</button></td>
  </tr>`).join('');
}

/* ── Staff ── */
async function loadStaff(){
  const d=await fetch(`staff_api.php?action=list${branchParams()}`,{credentials:'include'}).then(r=>r.json());
  const tbody=document.getElementById('staff-tbody');
  if(!tbody) return;
  if(!d.ok||!d.staff?.length){tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Staff မရှိသေး</td></tr>';return;}
  tbody.innerHTML=d.staff.map(s=>`<tr>
    <td style="font-weight:500">${s.name}</td>
    <td style="color:var(--muted)">${s.role}</td>
    <td style="color:var(--muted)">${s.pin?'****':'-'}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${s.is_active?'rgba(5,150,105,.1)':'rgba(128,128,128,.1)'};color:${s.is_active?'#065f46':'var(--muted)'}">${s.is_active?'Active':'Off'}</span></td>
  </tr>`).join('');
}

/* ── Orders ── */
async function loadOrders(){
  const d=await api('orders',`branch_id=${window._currentBranch}`);
  const tbody=document.getElementById('orders-tbody');
  if(!tbody) return;
  if(!d.ok||!d.orders?.length){tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Orders မရှိသေး</td></tr>';return;}
  tbody.innerHTML=d.orders.map(o=>`<tr>
    <td style="font-weight:500">#${o.id}</td>
    <td>${o.customer_name||'Walk-in'}</td>
    <td style="font-weight:600">${parseInt(o.total_amount||0).toLocaleString()} MMK</td>
    <td>${o.payment_method||'-'}</td>
    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:99px;background:rgba(128,128,128,.1)">${o.status}</span></td>
  </tr>`).join('');
}

/* ── Upgrade ── */
async function loadUpgradePage(){
  // Current plan display
  const plan = window.__TENANT_PLAN||'free';
  const planColors={free:'#6b7280',basic:'#2563eb',pro:'#059669',enterprise:'#7c3aed'};
  const el=document.getElementById('cur-plan-display');
  if(el) el.innerHTML=`<span style="background:${planColors[plan]||'#888'};color:#fff;padding:2px 12px;border-radius:99px;font-size:.9rem">${plan.toUpperCase()}</span>`;
  const expEl=document.getElementById('cur-expires-display');
  if(expEl && window.__PLAN_EXPIRES){
    const d=new Date(window.__PLAN_EXPIRES);
    const diff=Math.ceil((d-new Date())/86400000);
    expEl.textContent=diff<=0?'⚠️ သက်တမ်းကုန်သွားပြီ':`သက်တမ်း: ${d.toLocaleDateString('en-GB')} (${diff} ရက် ကျန်)`;
  }
  const d=await fetch('tenant_api.php?action=plans',{credentials:'include'}).then(r=>r.json());
  const grid=document.getElementById('upgrade-grid');
  if(!grid||!d.ok) return;
  const planOrder=['free','basic','pro','enterprise'];
  const cur=window.__TENANT_PLAN||'free';
  const curIdx=planOrder.indexOf(cur);
  const em={free:'🆓',basic:'⭐',pro:'🚀',enterprise:'🏢'};
  const feats={free:['1 branch','3 staff','20 items'],basic:['1 branch','5 staff','50 items'],pro:['3 branches','15 staff','200 items'],enterprise:['10 branches','50 staff','500 items']};
  grid.innerHTML=d.plans.map(p=>{
    const isCur=p.code===cur;
    const isDown=planOrder.indexOf(p.code)<curIdx;
    const mmk=parseInt(p.price_mmk||0).toLocaleString();
    return `<div style="background:var(--card);border:${isCur?'2px solid var(--accent)':'0.5px solid var(--border)'};border-radius:var(--radius);padding:1.2rem;display:flex;flex-direction:column;gap:.5rem">
      <div style="font-size:1.3rem">${em[p.code]||'📦'}</div>
      <div style="font-weight:600">${p.name}</div>
      <div style="font-size:1rem;font-weight:600">${mmk==='0'?'Free':mmk+' MMK'}</div>
      <ul style="list-style:none;padding:0;font-size:.8rem;color:var(--muted)">${(feats[p.code]||[]).map(f=>`<li>✓ ${f}</li>`).join('')}</ul>
      ${isCur?`<div style="margin-top:auto;text-align:center;font-size:.8rem;font-weight:600;color:var(--muted);padding:.4rem;background:rgba(128,128,128,.08);border-radius:7px">✓ Current plan</div>`:isDown?`<div style="margin-top:auto;text-align:center;font-size:.75rem;color:var(--muted);padding:.4rem">Downgrade မရနိုင်</div>`:`<button onclick="requestUpgrade('${p.code}','${p.name}')" class="btn btn-primary" style="margin-top:auto">⬆ ${p.name} သို့ Upgrade</button>`}
    </div>`;
  }).join('');
}

async function requestUpgrade(code,name){
  if(!confirm(`${name} plan သို့ upgrade request ပို့မလား?`)) return;
  const d=await fetch('tenant_api.php?action=request_upgrade',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({plan:code,tenant_id:window.__TENANT_ID,tenant_name:window.__TENANT_NAME,current_plan:window.__TENANT_PLAN})}).then(r=>r.json());
  if(d.ok) toast('✅ Upgrade request ပေးပို့ပြီး','ok');
  else toast(d.msg||'Error','err');
}

/* ── Tenant Settings (KBZPay) ── */
async function loadTenantSettings(){
  const d=await tenantApi('get_payment_settings');
  if(!d.ok) return;
  const s=d.settings;
  const set=(id,v)=>{const el=document.getElementById(id);if(el)el.value=v||'';};
  set('t-kpay-merchant',s.kpay_merchant_id);
  set('t-kpay-qr',s.kpay_qr_image);
  set('t-wave-merchant',s.wave_merchant_id);
  set('t-wave-qr',s.wave_qr_image);
}
async function saveTenantSettings(){
  const get=id=>document.getElementById(id)?.value?.trim()||'';
  const d=await tenantApi('save_payment_settings',{kpay_merchant_id:get('t-kpay-merchant'),kpay_qr_image:get('t-kpay-qr'),wave_merchant_id:get('t-wave-merchant'),wave_qr_image:get('t-wave-qr')});
  if(d.ok) toast('✅ Settings saved','ok');
  else toast(d.msg||'Error','err');
}

/* ── CRM stub ── */
async function loadCRM(){
  const d=await fetch(`crm_api.php?action=list&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json());
  const tbody=document.getElementById('crm-tbody');
  if(!tbody) return;
  if(!d.ok||!d.customers?.length){tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Customers မရှိသေး</td></tr>';return;}
  tbody.innerHTML=d.customers.map(c=>`<tr>
    <td style="font-weight:500">${c.name||'-'}</td>
    <td>${c.phone||'-'}</td>
    <td>${c.stamps||0} stamps</td>
    <td style="color:var(--muted);font-size:.78rem">${c.last_visit?.slice(0,10)||'-'}</td>
  </tr>`).join('');
}

/* ── Stock Log stub ── */
async function loadStockLogs(){
  const el=document.getElementById('stocklog-content');
  if(el) el.innerHTML='<div style="color:var(--muted);padding:1rem">Stock log loading...</div>';
}
/* ── Placeholders for shared functions ── */
function resLoad(){const el=document.getElementById('reserve-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Reservations loading...</div>';}
function stockLoad(){const el=document.getElementById('stock-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Stock loading...</div>';}
function delLoad(){const el=document.getElementById('delivery-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Delivery loading...</div>';}
function promoLoad(){const el=document.getElementById('promos-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Promos loading...</div>';}
function branchLoad(){const el=document.getElementById('branches-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Branches loading...</div>';}
function schedLoad(){const el=document.getElementById('schedule-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Schedule loading...</div>';}
function loadTables(){const el=document.getElementById('tables-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Tables loading...</div>';}
function openEditItem(id){toast('Edit item #'+id);}
</script>

<!-- ═══ PAGE HTML ═══ -->
<div id="page-dashboard" class="page" style="display:none">
  <div class="content">
    <div class="stats-grid">
      <div class="stat-card" onclick="showPage('orders')"><div class="stat-val" id="stat-orders">—</div><div class="stat-lbl">Orders today</div></div>
      <div class="stat-card"><div class="stat-val" id="stat-revenue">—</div><div class="stat-lbl">Revenue</div></div>
      <div class="stat-card" onclick="showPage('orders')"><div class="stat-val" id="stat-pending">—</div><div class="stat-lbl">Pending</div></div>
      <div class="stat-card" onclick="showPage('stock')"><div class="stat-val" id="stat-low">—</div><div class="stat-lbl">Low stock</div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="table-wrap" style="padding:1rem">
        <div style="font-size:.82rem;font-weight:600;margin-bottom:.75rem;color:var(--muted)">📊 Branch revenue (30d)</div>
        <div id="branch-chart-area">Loading…</div>
      </div>
      <div class="table-wrap" style="padding:0">
        <div style="padding:.75rem 1rem;font-size:.82rem;font-weight:600;border-bottom:0.5px solid var(--border)">Recent orders</div>
        <table><thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
        <tbody id="recent-orders-body"><tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--muted)">Loading...</td></tr></tbody></table>
      </div>
    </div>
  </div>
</div>

<div id="page-menu" class="page" style="display:none">
  <div class="content">
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:.9rem 1.1rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem">
      <div style="flex:1">
        <div style="font-size:.8rem;font-weight:600;color:var(--ink);margin-bottom:4px" id="menu-usage-lbl">Loading…</div>
        <div style="height:5px;background:var(--border);border-radius:99px;overflow:hidden"><div id="menu-usage-bar" style="height:100%;width:0;border-radius:99px;transition:width .4s"></div></div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="toast('Add item — coming soon')">+ Add item</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Category</th><th>Price (MMK)</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="menu-tbody"><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>

<div id="page-staff" class="page" style="display:none">
  <div class="content">
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Role</th><th>PIN</th><th>Status</th></tr></thead>
      <tbody id="staff-tbody"><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>

<div id="page-crm" class="page" style="display:none">
  <div class="content">
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Phone</th><th>Loyalty</th><th>Last visit</th></tr></thead>
      <tbody id="crm-tbody"><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>

<div id="page-orders" class="page" style="display:none">
  <div class="content">
    <div class="table-wrap"><table>
      <thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Payment</th><th>Status</th></tr></thead>
      <tbody id="orders-tbody"><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>

<div id="page-tables" class="page" style="display:none"><div class="content"><div id="tables-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-reserve" class="page" style="display:none"><div class="content"><div id="reserve-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-stock" class="page" style="display:none"><div class="content"><div id="stock-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-stocklog" class="page" style="display:none"><div class="content"><div id="stocklog-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-shift" class="page" style="display:none"><div class="content"><div id="shift-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-delivery" class="page" style="display:none"><div class="content"><div id="delivery-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-expenses" class="page" style="display:none"><div class="content"><div id="expenses-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-promos" class="page" style="display:none"><div class="content"><div id="promos-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-branches" class="page" style="display:none"><div class="content"><div id="branches-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-schedule" class="page" style="display:none"><div class="content"><div id="schedule-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-storefront" class="page" style="display:none"><div class="content"><p style="color:var(--muted)">Storefront customisation — coming soon.</p></div></div>

<div id="page-upgrade" class="page" style="display:none">
  <div class="content">
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:1rem 1.2rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem">
      <span style="font-size:1.8rem">📋</span>
      <div>
        <div style="font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em">လက်ရှိ Plan</div>
        <div style="font-size:1rem;font-weight:700" id="cur-plan-display">Loading…</div>
        <div style="font-size:.78rem;color:var(--muted)" id="cur-expires-display"></div>
      </div>
    </div>
    <div id="upgrade-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
      <div style="color:var(--muted);padding:1rem">Loading plans…</div>
    </div>
  </div>
</div>

<div id="page-settings" class="page" style="display:none">
  <div class="content">
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:1.2rem;margin-bottom:1rem">
      <div style="font-weight:600;margin-bottom:1rem">💜 KBZPay (KPay) Settings</div>
      <div class="form-row">
        <div class="field"><label>Merchant ID / Phone</label><input id="t-kpay-merchant" type="text" placeholder="09xxxxxxxxx"></div>
        <div class="field"><label>QR Code URL / Base64</label><input id="t-kpay-qr" type="text" placeholder="https://..."></div>
      </div>
    </div>
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:1.2rem;margin-bottom:1rem">
      <div style="font-weight:600;margin-bottom:1rem">🌊 Wave Money Settings</div>
      <div class="form-row">
        <div class="field"><label>Merchant ID / Phone</label><input id="t-wave-merchant" type="text" placeholder="09xxxxxxxxx"></div>
        <div class="field"><label>QR Code URL</label><input id="t-wave-qr" type="text" placeholder="https://..."></div>
      </div>
    </div>
    <button class="btn btn-primary" onclick="saveTenantSettings()">💾 Save Settings</button>
  </div>
</div>

<script>
/* ── Auto-init if already logged in ── */
if(window.__IS_TENANT && window.__TENANT_ID > 0){
  initShell();
  showPage('dashboard');
}
</script>
</body>
</html>
