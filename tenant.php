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

        // ★ 4 queries → 1 query (performance optimization) ★
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                        AS today,
                COALESCE(SUM(CASE WHEN status!='cancelled' THEN total_amount END),0) AS revenue,
                COUNT(CASE WHEN status='pending' THEN 1 END)                   AS pending
            FROM orders o
            WHERE o.tenant_id=:tid AND DATE(o.created_at)=CURDATE() AND o.deleted_at IS NULL $bWhere
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE tenant_id=:tid AND stock_qty<=5 AND is_active=1");
        $stmt2->execute([':tid' => $tid]);
        $low = (int)$stmt2->fetchColumn();

        echo json_encode(['ok'=>true,'today'=>(int)$row['today'],'revenue'=>(float)$row['revenue'],'pending'=>(int)$row['pending'],'low'=>$low]);
        exit;
    }

    /* ── UPDATE ORDER STATUS ── */
    if ($_GET['api'] === 'update_order_status') {
        $b   = json_decode(file_get_contents('php://input'), true) ?? [];
        $oid = (int)($b['order_id'] ?? 0);
        $st  = trim($b['status'] ?? '');
        $valid = ['pending','confirmed','preparing','ready','out_for_delivery','delivered','cancelled'];
        if (!$oid || !in_array($st, $valid)) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit;
        }
        $pdo->prepare("UPDATE orders SET status=?,updated_at=NOW() WHERE id=? AND tenant_id=?")
            ->execute([$st,$oid,$tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── ORDERS ── */
    if ($_GET['api'] === 'orders') {
        $bid   = (int)($_GET['branch_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? 50);
        $bWhere = $bid ? "AND o.branch_id=:bid" : "";
        $params = [':tid' => $tid];
        if ($bid) $params[':bid'] = $bid;
        // ★ Pagination + status filter + items_summary ★
        $offset       = max(0, (int)($_GET['offset'] ?? 0));
        $statusFilter = trim($_GET['status_filter'] ?? '');
        $validStatus  = ['pending','confirmed','preparing','ready','out_for_delivery','delivered','cancelled'];
        $sWhere = (in_array($statusFilter, $validStatus)) ? " AND o.status=:status" : "";
        if ($sWhere) $params[':status'] = $statusFilter;

        // Total count
        $cStmt = $pdo->prepare("SELECT COUNT(DISTINCT o.id) FROM orders o WHERE o.tenant_id=:tid AND o.deleted_at IS NULL $bWhere $sWhere");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT o.*,
                COALESCE(GROUP_CONCAT(oi.item_name,'×',oi.qty ORDER BY oi.id SEPARATOR ', '),'—') AS items_summary
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.tenant_id=:tid AND o.deleted_at IS NULL $bWhere $sWhere
            GROUP BY o.id
            ORDER BY o.id DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        echo json_encode(['ok'=>true,'orders'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'total'=>$total]);
        exit;
    }

    /* ── ITEMS ── */
    if ($_GET['api'] === 'items') {
        $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE tenant_id=:tid ORDER BY is_active DESC, sort_order ASC, category, name");
        $stmt->execute([':tid' => $tid]);
        echo json_encode(['ok'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /* ── TABLES ── */
    if ($_GET['api'] === 'tables') {
        $bid = (int)($_GET['branch_id'] ?? 0);
        if ($bid) {
            $stmt = $pdo->prepare("SELECT * FROM restaurant_tables WHERE tenant_id=:t AND branch_id=:b AND is_active=1 ORDER BY table_code");
            $stmt->execute([':t'=>$tid,':b'=>$bid]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM restaurant_tables WHERE tenant_id=:t AND is_active=1 ORDER BY table_code");
            $stmt->execute([':t'=>$tid]);
        }
        echo json_encode(['ok'=>true,'tables'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    /* ── STAFF (tenant) ── */
    if ($_GET['api'] === 'staff') {
        $stmt = $pdo->prepare("SELECT s.* FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=:t AND s.is_active=1 ORDER BY s.name");
        $stmt->execute([':t'=>$tid]);
        echo json_encode(['ok'=>true,'staff'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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

/* HIDDEN - controlled by JS inline style */
.page{}

/* ★ Mobile UX Improvements ★ */
@media(max-width:600px){
  .btn{min-height:40px;padding:.5rem .9rem}
  .btn-sm{min-height:34px;font-size:.78rem}
  .stat-card{padding:.8rem}
  .stat-val{font-size:1.4rem}
  table{font-size:.78rem}
  th,td{padding:6px 8px}
  .page-head{padding:.75rem 1rem}
  #low-stock-list{gap:.3rem}
  #orders-pagination{font-size:.75rem}
}
/* Touch-friendly tap targets */
button,select,input[type=checkbox]{
  touch-action:manipulation;
  -webkit-tap-highlight-color:transparent;
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#E8593C">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MyanAi POS">
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

<!-- IMPERSONATION BANNER (moved to header) -->
<div id="impersonate-banner" style="display:none"></div>

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
      <div class="nav-sect">ကျွန်ုပ်လုပ်ငန်း</div>
      <div class="nav-item active" id="nav-dashboard" onclick="showPage('dashboard')">
        <span class="nav-icon">📊</span> ပင်မစာမျက်နှာ
      </div>
      <div class="nav-item" id="nav-menu" onclick="showPage('menu')">
        <span class="nav-icon">🍜</span> မီနူးစာရင်း
        <span class="nav-badge" id="menu-count-badge"></span>
      </div>
      <div class="nav-item" id="nav-staff" onclick="showPage('staff')">
        <span class="nav-icon">👥</span> ဝန်ထမ်းများ
      </div>
      <div class="nav-item" id="nav-crm" onclick="showPage('crm')">
        <span class="nav-icon">❤️</span> သောက်သုံးသူ
      </div>
      <div class="nav-item" id="nav-stocklog" onclick="showPage('stocklog')">
        <span class="nav-icon">📋</span> ကုန်ပစ္စည်းမှတ်တမ်း
      </div>
      <div class="nav-item" id="nav-promos" onclick="showPage('promos')">
        <span class="nav-icon">🎁</span> ပရိုမိုး
      </div>
      <div class="nav-item" id="nav-branches" onclick="showPage('branches')">
        <span class="nav-icon">🏢</span> ဆိုင်ခွဲများ
      </div>

      <div class="nav-sect">ဆိုင်ငယ်လုပ်ငန်း</div>
      <div style="padding:.3rem .6rem .4rem">
        <select id="branch-select" onchange="switchBranch(this.value)" style="width:100%;font-size:.78rem;padding:.35rem .5rem;border:0.5px solid var(--border);border-radius:7px;background:var(--warm);color:var(--ink)">
          <option value="0">All branches</option>
        </select>
      </div>
      <div class="nav-item" id="nav-orders" onclick="showPage('orders')">
        <span class="nav-icon">🧾</span> မှာယူမှုများ
      </div>
      <div class="nav-item" id="nav-tables" onclick="showPage('tables')">
        <span class="nav-icon">🪑</span> စားပွဲများ
      </div>
      <div class="nav-item" id="nav-reserve" onclick="showPage('reserve')">
        <span class="nav-icon">📅</span> ကြိုတင်ဘွတ်ကင်
      </div>
      <div class="nav-item" id="nav-stock" onclick="showPage('stock')">
        <span class="nav-icon">📦</span> ကုန်တိုက်
      </div>
      <div class="nav-item" id="nav-shift" onclick="showPage('shift')">
        <span class="nav-icon">⏰</span> ဝင်ရောက်ချိန်
      </div>
      <div class="nav-item" id="nav-delivery" onclick="showPage('delivery')">
        <span class="nav-icon">🛵</span> ပို့ဆောင်ရေး
      </div>
      <div class="nav-item" id="nav-expenses" onclick="showPage('expenses')">
        <span class="nav-icon">💰</span> ကုန်ကျစရိတ်
      </div>

      <div class="nav-sect">စီမံခန့်ခွဲမှု</div>
      <div class="nav-item" id="nav-upgrade" onclick="showPage('upgrade')">
        <span class="nav-icon">⬆</span> Plan အဆင့်မြှင့်
        <span class="nav-badge plan-badge" id="nav-plan-badge"></span>
      </div>
      <div class="nav-item" id="nav-backup" onclick="showPage('backup')">
        <span class="nav-icon">💾</span> Data Backup
      </div>
      <div class="nav-item" id="nav-storefront" onclick="showPage('storefront')">
        <span class="nav-icon">🎨</span> ဆိုင်ဝင်ပေါက်
      </div>
      <div class="nav-item" id="nav-settings" onclick="showPage('settings')">
        <span class="nav-icon">⚙️</span> ဆက်တင်
      </div>
      <div class="nav-item" id="nav-schedule" onclick="showPage('schedule')">
        <span class="nav-icon">📅</span> အချိန်ဇယား
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
      <div style="margin-left:auto;display:flex;gap:.75rem;align-items:center">
        <span id="topbar-plan-badge" class="plan-badge"></span>
        <span id="topbar-biz" style="font-size:.78rem;color:var(--muted)"></span>
        <div id="admin-mode-badge" style="display:none;background:#dc2626;color:#fff;padding:.3rem .6rem;border-radius:6px;font-size:.75rem;font-weight:600;white-space:nowrap;display:flex;align-items:center;gap:.4rem">
          <span>👤 Admin</span>
          <button onclick="exitImpersonate()" style="background:rgba(255,255,255,.3);border:none;color:#fff;padding:0 .3rem;border-radius:4px;cursor:pointer;font-size:.7rem;line-height:1">✕</button>
        </div>
        <div id="topbar-action"></div>
      </div>
    </div>

    <!-- Pages -->
    <div id="page-container" style="flex:1;overflow-y:auto">
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
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
          <div style="font-size:.82rem;font-weight:600;color:var(--muted)">📊 Revenue trend</div>
          <select id="dash-chart-days" onchange="loadDashboardChart()" style="font-size:.75rem;padding:2px 6px;border:0.5px solid var(--border);border-radius:6px;background:var(--warm);color:var(--ink)">
            <option value="7">7 ရက်</option>
            <option value="30" selected>30 ရက်</option>
            <option value="90">90 ရက်</option>
          </select>
        </div>
        <div style="position:relative;height:160px"><canvas id="dash-revenue-chart"></canvas></div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-top:.75rem" id="dash-mini-stats"></div>
      </div>
      <div class="table-wrap" style="padding:0">
        <div style="padding:.75rem 1rem;font-size:.82rem;font-weight:600;border-bottom:0.5px solid var(--border)">Recent orders</div>
        <table><thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
        <tbody id="recent-orders-body"><tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--muted)">Loading...</td></tr></tbody></table>
      </div>

      <!-- ★ Low Stock Alert Widget ★ -->
      <div id="low-stock-widget" style="display:none;margin-top:1rem;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:12px;padding:1rem">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
          <h3 style="margin:0;font-size:.9rem;font-weight:600;color:#dc2626">⚠️ Low Stock Alert</h3>
          <button onclick="showPage('stock')" style="font-size:.75rem;color:#dc2626;background:none;border:1px solid #dc2626;border-radius:6px;padding:2px 8px;cursor:pointer">Stock ကြည့်</button>
        </div>
        <div id="low-stock-list" style="display:flex;flex-wrap:wrap;gap:.4rem"></div>
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
    <div class="table-wrap">
      <div class="table-toolbar" style="padding:.6rem 1rem;border-bottom:0.5px solid var(--border);display:flex;gap:.5rem;flex-wrap:wrap">
        <input id="menu-search" placeholder="ရှာဖွေပါ..." oninput="filterMenu()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem;width:180px">
        <select id="menu-cat-filter" onchange="filterMenu()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem">
          <option value="">All categories</option>
        </select>
      </div>
      <table><thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody id="menu-tbody"><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
      </table>
    </div>
  </div>
  <!-- Add/Edit Item Modal -->
  <div class="modal-overlay" id="modal-menu-item">
    <div class="modal" style="max-width:540px">
      <div class="modal-head">
        <span class="modal-title" id="menu-modal-title">Add menu item</span>
        <button class="modal-close" onclick="closeModal('modal-menu-item')">✕</button>
      </div>
      <input type="hidden" id="item-edit-id">
      <div style="display:grid;gap:.75rem">
        <div style="display:grid;grid-template-columns:1fr auto;gap:.5rem;align-items:end">
          <div class="field"><label>Item name *</label><input id="item-name" placeholder="Mohinga"></div>
          <div class="field" style="width:70px"><label>Emoji</label><input id="item-emoji" placeholder="🍜" style="text-align:center;font-size:1.3rem"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
          <div class="field"><label>Category *</label><input id="item-category" placeholder="Main, Drinks, Starters..."></div>
          <div class="field"><label>Price (MMK) *</label><input id="item-price" type="number" placeholder="3500"></div>
        </div>
        <div class="field"><label>Description</label><textarea id="item-desc" rows="2" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-family:inherit;font-size:.85rem" placeholder="Optional description"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
          <div class="field"><label>Initial stock qty</label><input id="item-stock" type="number" placeholder="100" value="100"></div>
          <div class="field" style="display:flex;align-items:center;gap:.5rem;padding-top:1.4rem">
            <input type="checkbox" id="item-active" checked style="width:18px;height:18px">
            <label for="item-active" style="cursor:pointer">Active</label>
          </div>
        </div>
      </div>
      <div style="margin-top:1.2rem;display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-menu-item')">Cancel</button>
        <button class="btn btn-primary" onclick="saveMenuItem()">💾 Save item</button>
      </div>
    </div>
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
      <thead><tr><th>#</th><th>Customer</th><th>Amount</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="orders-tbody"><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>

<div id="page-tables" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <span id="topbar-title" style="font-size:.95rem;font-weight:600">Tables</span>
    </div>
    <div style="display:flex;gap:.5rem">
      <a id="kds-link" href="kds.html" target="_blank" class="btn btn-ghost btn-sm">👨‍🍳 Open KDS</a>
      <button class="btn btn-primary btn-sm" onclick="openAddTable()">+ Add table</button>
    </div>
  </div>
  <div class="content">
    <div id="tables-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem"></div>
  </div>
  <!-- Add Table Modal -->
  <div class="modal-overlay" id="modal-add-table">
    <div class="modal">
      <div class="modal-head"><span class="modal-title">Add table</span><button class="modal-close" onclick="closeModal('modal-add-table')">✕</button></div>
      <div class="form-row"><div class="field"><label>Table code</label><input id="add-table-code" placeholder="T1"></div>
      <div class="field"><label>Label</label><input id="add-table-label" placeholder="Table 1"></div></div>
      <div class="field"><label>Seats</label><input id="add-table-seats" type="number" value="4" min="1" max="50"></div>
      <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-add-table')">Cancel</button>
        <button class="btn btn-primary" onclick="saveNewTable()">Save</button>
      </div>
    </div>
  </div>
</div>
<div id="page-reserve" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">Reservations</span></div>
    <button class="btn btn-primary btn-sm" onclick="openAddReserve()">+ New reservation</button>
  </div>
  <div class="content">
    <div class="table-wrap">
      <table><thead><tr><th>Name</th><th>Phone</th><th>Date/Time</th><th>Party</th><th>Table</th><th>Status</th></tr></thead>
      <tbody id="reserve-tbody"><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
</div>
<div id="page-stock" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">Stock</span></div>
  </div>
  <div class="content">
    <div id="stock-summary" class="stats-grid" style="margin-bottom:1rem"></div>
    <div class="table-wrap">
      <div class="table-toolbar"><input id="stock-search" placeholder="Search items..." oninput="filterStock()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem;width:200px"></div>
      <table><thead><tr><th>Item</th><th>Category</th><th>Stock</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="stock-tbody"><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
  <!-- Adjust Modal -->
  <div class="modal-overlay" id="modal-stock-adj">
    <div class="modal">
      <div class="modal-head"><span class="modal-title" id="adj-title">Adjust stock</span><button class="modal-close" onclick="closeModal('modal-stock-adj')">✕</button></div>
      <input type="hidden" id="adj-item-id">
      <div class="field" style="margin-bottom:.75rem"><label>Change qty (+/-)</label><input id="adj-qty" type="number" placeholder="-5 or +10"></div>
      <div class="field" style="margin-bottom:.75rem"><label>Reason</label><input id="adj-reason" placeholder="Sale / Waste / Restock"></div>
      <div style="display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-stock-adj')">Cancel</button>
        <button class="btn btn-primary" onclick="submitStockAdj()">Save</button>
      </div>
    </div>
  </div>
</div>
<div id="page-stocklog" class="page" style="display:none"><div class="content"><div id="stocklog-content"><div style="color:var(--muted)">Loading...</div></div></div></div>
<div id="page-shift" class="page" style="display:none">
  <div class="content">
    <div class="table-wrap">
      <div class="table-toolbar" style="padding:.6rem 1rem;border-bottom:0.5px solid var(--border);display:flex;gap:.5rem;align-items:center">
        <input id="shift-date-filter" type="date" style="padding:.4rem .6rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem" onchange="loadShifts()">
        <span style="font-size:.82rem;color:var(--muted)">Filter by date</span>
        <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="openAddShift()">+ Add shift</button>
      </div>
      <table><thead><tr><th>Staff</th><th>Date</th><th>Shift</th><th>Time</th><th>Notes</th><th>Action</th></tr></thead>
      <tbody id="shifts-tbody"><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
</div>
<div id="page-delivery" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">Delivery</span></div>
    <a href="driver.html" target="_blank" class="btn btn-ghost btn-sm">🛵 Driver app</a>
  </div>
  <div class="content">
    <div class="table-wrap">
      <table><thead><tr><th>#</th><th>Customer</th><th>Address</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="delivery-tbody"><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
</div>
<div id="page-expenses" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">Expenses</span></div>
    <button class="btn btn-primary btn-sm" onclick="openAddExpense()">+ Add expense</button>
  </div>
  <div class="content">
    <div id="expense-summary" class="stats-grid" style="margin-bottom:1rem"></div>
    <div class="table-wrap">
      <table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr></thead>
      <tbody id="expense-tbody"><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
  <!-- Add Expense Modal -->
  <div class="modal-overlay" id="modal-add-expense">
    <div class="modal">
      <div class="modal-head"><span class="modal-title">Add expense</span><button class="modal-close" onclick="closeModal('modal-add-expense')">✕</button></div>
      <div style="display:grid;gap:.75rem">
        <div class="field"><label>Category</label>
          <select id="exp-cat" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
            <option>Food & Ingredients</option><option>Utilities</option><option>Staff</option><option>Rent</option><option>Equipment</option><option>Other</option>
          </select>
        </div>
        <div class="field"><label>Description</label><input id="exp-desc" placeholder="Tomatoes, electricity bill..."></div>
        <div class="field"><label>Amount (MMK)</label><input id="exp-amount" type="number" placeholder="5000"></div>
        <div class="field"><label>Date</label><input id="exp-date" type="date"></div>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-add-expense')">Cancel</button>
        <button class="btn btn-primary" onclick="saveExpense()">Save</button>
      </div>
    </div>
  </div>
</div>
<div id="page-promos" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">Promotions</span></div>
    <button class="btn btn-primary btn-sm" onclick="openAddPromo()">+ Add promo</button>
  </div>
  <div class="content">
    <div class="table-wrap">
      <table><thead><tr><th>Code</th><th>Discount</th><th>Min order</th><th>Valid</th><th>Status</th></tr></thead>
      <tbody id="promos-tbody"><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr></tbody></table>
    </div>
  </div>
</div>
<div id="page-branches" class="page" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.75rem"><button class="hamburger" onclick="openSidebar()">☰</button><span style="font-size:.95rem;font-weight:600">My branches</span></div>
  </div>
  <div class="content">
    <div id="branches-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem"></div>
  </div>
</div>
<div id="page-schedule" class="page" style="display:none">
  <div class="content">
    <div style="max-width:800px">
      <!-- Week selector -->
      <div class="table-wrap" style="padding:1rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="schedWeek(-1)">← Prev</button>
        <span id="sched-week-label" style="font-weight:600;font-size:.9rem"></span>
        <button class="btn btn-ghost btn-sm" onclick="schedWeek(1)">Next →</button>
        <button class="btn btn-ghost btn-sm" onclick="schedWeek(0)">Today</button>
        <button class="btn btn-primary btn-sm" style="margin-left:auto" onclick="openAddShift()">+ Add shift</button>
      </div>
      <!-- Schedule grid -->
      <div class="table-wrap" style="padding:0;overflow-x:auto">
        <table id="sched-table" style="min-width:600px">
          <thead><tr id="sched-head">
            <th style="width:100px">Staff</th>
          </tr></thead>
          <tbody id="sched-body"></tbody>
        </table>
      </div>
      <!-- Shift legend -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.75rem;font-size:.78rem;color:var(--muted)">
        <span>🟢 Morning</span><span>🔵 Afternoon</span><span>🟣 Evening</span><span>⚫ Day off</span>
      </div>
    </div>
  </div>
  <!-- Add Shift Modal -->
  <div class="modal-overlay" id="modal-add-shift">
    <div class="modal">
      <div class="modal-head"><span class="modal-title">Add Shift</span><button class="modal-close" onclick="closeModal('modal-add-shift')">✕</button></div>
      <div style="display:grid;gap:.75rem">
        <div class="field"><label>Staff member</label>
          <select id="shift-staff" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
            <option value="">Select staff...</option>
          </select>
        </div>
        <div class="field"><label>Date</label><input id="shift-date" type="date"></div>
        <div class="field"><label>Shift type</label>
          <select id="shift-type" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
            <option value="morning">🟢 Morning (6am–2pm)</option>
            <option value="afternoon">🔵 Afternoon (2pm–10pm)</option>
            <option value="evening">🟣 Evening (6pm–2am)</option>
            <option value="off">⚫ Day off</option>
          </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
          <div class="field"><label>Start time</label><input id="shift-start" type="time" value="09:00"></div>
          <div class="field"><label>End time</label><input id="shift-end" type="time" value="17:00"></div>
        </div>
        <div class="field"><label>Notes</label><input id="shift-notes" placeholder="Optional notes"></div>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-add-shift')">Cancel</button>
        <button class="btn btn-primary" onclick="saveShift()">Save shift</button>
      </div>
    </div>
  </div>
</div>
<div id="page-backup" class="page" style="display:none">
  <div class="content">
    <div style="max-width:600px">
      <!-- Info card -->
      <div class="table-wrap" style="padding:1.3rem;margin-bottom:1rem">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:1rem">💾 Data Backup</div>
        <div id="backup-counts" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.75rem;margin-bottom:1.2rem">
          <div style="color:var(--muted);font-size:.83rem">Loading...</div>
        </div>
        <div style="font-size:.82rem;color:var(--muted);margin-bottom:1.2rem;line-height:1.7">
          သင့် business data အကုန်လုံးကို JSON file အဖြစ် download လုပ်ပါ။<br>
          Menu items · Orders · Staff · Tables · CRM · Expenses
        </div>
        <button class="btn btn-primary" onclick="downloadBackup()" id="backup-download-btn">
          ⬇️ Download my data backup
        </button>
        <div id="backup-status" style="margin-top:.75rem;font-size:.82rem;color:var(--muted)"></div>
      </div>
      <!-- Instructions -->
      <div class="table-wrap" style="padding:1.3rem">
        <div style="font-weight:600;font-size:.88rem;margin-bottom:.75rem">📋 Backup includes</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;font-size:.82rem;color:var(--muted)">
          <div>✓ All menu items</div>
          <div>✓ Orders history (500 recent)</div>
          <div>✓ Staff accounts</div>
          <div>✓ Table settings</div>
          <div>✓ Branch info</div>
          <div>✓ CRM customers</div>
          <div>✓ Expenses log</div>
          <div>✓ Order items detail</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="page-storefront" class="page" style="display:none">
  <div class="content">
    <div style="max-width:700px;display:flex;flex-direction:column;gap:1rem">
      <div class="table-wrap" style="padding:1.3rem">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:1rem">🏪 Business info</div>
        <div style="display:grid;gap:.75rem">
          <div class="field"><label>Business name</label><input id="sf-name" placeholder="Demo Restaurant"></div>
          <div class="field"><label>Tagline</label><input id="sf-tagline" placeholder="Authentic flavors"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="field"><label>Phone</label><input id="sf-phone" placeholder="09xxxxxxxxx"></div>
            <div class="field"><label>Address</label><input id="sf-address" placeholder="Yangon"></div>
          </div>
        </div>
      </div>
      <div class="table-wrap" style="padding:1.3rem">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:1rem">🎨 Appearance</div>
        <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:.75rem;align-items:end">
          <div class="field"><label>Brand emoji</label><input id="sf-emoji" placeholder="🍜" style="font-size:1.4rem;width:60px;text-align:center"></div>
          <div class="field"><label>Primary color</label>
            <div style="display:flex;gap:.5rem">
              <input type="color" id="sf-color" value="#e84c2b" style="width:44px;height:36px;border-radius:8px;border:0.5px solid var(--border)">
              <input id="sf-color-hex" placeholder="#e84c2b" oninput="document.getElementById('sf-color').value=this.value">
            </div>
          </div>
          <div class="field"><label>Currency</label>
            <select id="sf-currency" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
              <option value="MMK">MMK (Kyat)</option><option value="USD">USD</option>
            </select>
          </div>
        </div>
      </div>
      <div class="table-wrap" style="padding:1.3rem">
        <div style="font-weight:600;font-size:.9rem;margin-bottom:.75rem">🔗 Customer ordering URL</div>
        <div style="display:flex;align-items:center;gap:.75rem">
          <code id="sf-order-url" style="flex:1;background:var(--warm);padding:.4rem .7rem;border-radius:8px;font-size:.82rem;border:0.5px solid var(--border)">Loading...</code>
          <button class="btn btn-ghost btn-sm" onclick="copyOrderUrl()">Copy</button>
          <a id="sf-order-link" href="#" target="_blank" class="btn btn-ghost btn-sm">Open →</a>
        </div>
      </div>
      <div style="display:flex;gap:.75rem">
        <button class="btn btn-primary" onclick="saveStorefront()">💾 Save</button>
        <a id="sf-preview-btn" href="#" target="_blank" class="btn btn-ghost">👁 Preview</a>
      </div>
    </div>
  </div>
</div>

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
</script>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ── Globals ── */
window.__IS_TENANT      = <?= $loggedIn ? 'true' : 'false' ?>;
window.__IMPERSONATING  = <?= !empty($_SESSION['impersonating']) ? 'true' : 'false' ?>;
window.__IMPERSONATE_ADMIN = <?= json_encode($_SESSION['impersonate_admin'] ?? '') ?>;
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
  'upgrade','backup','storefront','settings','schedule'];

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
  if(el) el.style.display='block';
  const nav=document.getElementById('nav-'+page);
  if(nav) nav.classList.add('active');

  // Update topbar title + right action button
  const titles={dashboard:'Dashboard',menu:'Menu items',staff:'Staff',crm:'CRM / Loyalty',
    stocklog:'Stock log',promos:'Promotions',branches:'My branches',orders:'Orders',
    tables:'Tables',reserve:'Reservations',stock:'Stock',shift:'Shifts',
    delivery:'Delivery',expenses:'Expenses',upgrade:'Plan upgrade',
    storefront:'Storefront',settings:'Settings',schedule:'Scheduling',backup:'My data backup'};
  const tb=document.getElementById('topbar-title');
  if(tb) tb.textContent=titles[page]||page;

  // Per-page action buttons in topbar
  const actions = {
    menu:     `<button class="btn btn-primary btn-sm" onclick="openAddItem()">+ Add item</button>`,
    tables:   `<div style="display:flex;gap:.4rem"><a id="kds-link" href="kds.html?tenant=${window.__TENANT_ID}&branch=${window._currentBranch||0}" target="_blank" class="btn btn-ghost btn-sm">👨‍🍳 KDS</a><button class="btn btn-primary btn-sm" onclick="openAddTable()">+ Add table</button></div>`,
    expenses: `<button class="btn btn-primary btn-sm" onclick="openAddExpense()">+ Add expense</button>`,
    promos:   `<button class="btn btn-primary btn-sm" onclick="openAddPromo()">+ Add promo</button>`,
    reserve:  `<button class="btn btn-primary btn-sm" onclick="openAddReserve()">+ New reservation</button>`,
    delivery: `<a href="driver.html" target="_blank" class="btn btn-ghost btn-sm">🛵 Driver app</a>`,
    shift:    `<button class="btn btn-primary btn-sm" onclick="openAddShift()">+ Add shift</button>`,
    schedule: `<button class="btn btn-primary btn-sm" onclick="openAddShift()">+ Add shift</button>`,
  };
  const tbAction = document.getElementById('topbar-action');
  if(tbAction) tbAction.innerHTML = actions[page] || '';

  // Load page data
  // ★ Clean page load dispatch (no duplicates) ★
  const loaders = {
    dashboard:  ()=> initDashboard(),
    menu:       ()=> loadMenuItems(),
    staff:      ()=> loadStaff(),
    crm:        ()=> loadCRM(),
    stocklog:   ()=> loadStockLogs(),
    orders:     ()=> loadOrders(),
    tables:     ()=> loadTables(),
    reserve:    ()=> resLoad(),
    stock:      ()=> stockLoad(),
    shift:      ()=> loadShifts(),
    schedule:   ()=> loadSchedule(),
    delivery:   ()=> delLoad(),
    expenses:   ()=> { if(typeof expLoad==='function') expLoad(); },
    promos:     ()=> promoLoad(),
    branches:   ()=> branchLoad(),
    upgrade:    ()=> loadUpgradePage(),
    settings:   ()=> loadTenantSettings(),
    backup:     ()=> loadBackupPage(),
    storefront: ()=> loadStorefront(),
  };
  if(loaders[page]) loaders[page]();

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
  loadDashboardChart();
}

async function loadStats(){
  const d = await api('stats', `branch_id=${window._currentBranch}`);
  if(!d.ok) return;
  const sv=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
  sv('stat-orders', d.today||0);
  sv('stat-revenue', (parseInt(d.revenue||0)).toLocaleString()+' K');
  sv('stat-pending', d.pending||0);
  sv('stat-low', d.low||0);

  // ★ Low stock alert widget ★
  if ((d.low||0) > 0) {
    const r = await fetch(`stock_alert_api.php?action=check&tenant_id=${window.__TENANT_ID}&threshold=5`, {credentials:'include'});
    const s = await r.json().catch(()=>({ok:false}));
    if (s.ok && s.total_alerts > 0) {
      const widget = document.getElementById('low-stock-widget');
      const list   = document.getElementById('low-stock-list');
      if (widget && list) {
        widget.style.display = 'block';
        list.innerHTML = [...(s.out_of_stock||[]), ...(s.low_stock||[])].map(i =>
          `<span style="font-size:.75rem;padding:3px 8px;border-radius:99px;background:${i.stock_qty===0?'rgba(220,38,38,.15)':'rgba(217,119,6,.12)'};color:${i.stock_qty===0?'#dc2626':'#d97706'};border:1px solid ${i.stock_qty===0?'rgba(220,38,38,.3)':'rgba(217,119,6,.3)'}">
            ${i.emoji||''} ${i.name} (${i.stock_qty===0?'OUT':i.stock_qty})
          </span>`
        ).join('');
      }
    }
  }
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

async function loadDashboardChart(){
  const days = document.getElementById('dash-chart-days')?.value || 30;
  const from = new Date(Date.now()-days*86400000).toISOString().slice(0,10);
  const to   = new Date().toISOString().slice(0,10);
  const tid  = window.__TENANT_ID;

  const [rev, items] = await Promise.all([
    fetch(`analytics.php?tenant_id=${tid}&days=${days}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
    fetch(`reports_api.php?action=top_items&from=${from}&to=${to}&tenant_id=${tid}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false})),
  ]);

  // ── Revenue chart ──
  const ctx = document.getElementById('dash-revenue-chart');
  if (ctx && rev.ok && rev.revenue?.length) {
    if (window._dashChart) window._dashChart.destroy();
    const labels = rev.revenue.map(r=>r.date);
    const data   = rev.revenue.map(r=>Math.round(r.revenue/1000));
    const isDark = document.documentElement.getAttribute('data-theme')==='dark';
    window._dashChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          data,
          borderColor: '#E8593C',
          backgroundColor: 'rgba(232,89,60,.08)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointRadius: 3,
          pointBackgroundColor: '#E8593C',
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: {display:false}, tooltip: {callbacks:{label:ctx=>`${ctx.parsed.y}K MMK`}} },
        scales: {
          x: { grid:{display:false}, ticks:{font:{size:10}, maxTicksLimit:7, color: isDark?'#888':'#aaa'} },
          y: { grid:{color:isDark?'rgba(255,255,255,.05)':'rgba(0,0,0,.05)'}, ticks:{font:{size:10}, color: isDark?'#888':'#aaa', callback:v=>v+'K'} }
        }
      }
    });
  }

  // ── Mini stats ──
  const mini = document.getElementById('dash-mini-stats');
  if (mini && rev.ok && rev.summary) {
    const s = rev.summary;
    const fmt = n => parseInt(n||0).toLocaleString();
    mini.innerHTML = `
      <div style="background:var(--warm);border:0.5px solid var(--border);border-radius:8px;padding:.5rem .6rem;text-align:center">
        <div style="font-size:.95rem;font-weight:600">${fmt(s.total_orders)}</div>
        <div style="font-size:.68rem;color:var(--muted)">${days}ရက် orders</div>
      </div>
      <div style="background:var(--warm);border:0.5px solid var(--border);border-radius:8px;padding:.5rem .6rem;text-align:center">
        <div style="font-size:.95rem;font-weight:600">${fmt(s.today_orders)}</div>
        <div style="font-size:.68rem;color:var(--muted)">ယနေ့</div>
      </div>
      <div style="background:var(--warm);border:0.5px solid var(--border);border-radius:8px;padding:.5rem .6rem;text-align:center">
        <div style="font-size:.95rem;font-weight:600">${Math.round((s.avg_order||0)/1000)}K</div>
        <div style="font-size:.68rem;color:var(--muted)">avg order</div>
      </div>`;
  }

  // ── Top items (if space) ──
  if (items.ok && items.items?.length) {
    const topDiv = document.getElementById('dash-top-items');
    if (topDiv) {
      topDiv.innerHTML = items.items.slice(0,5).map((it,i)=>`
        <div style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0;border-bottom:0.5px solid var(--border)">
          <span style="font-size:.7rem;color:var(--muted);width:14px">${i+1}</span>
          <span style="flex:1;font-size:.78rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escH(it.item_name)}</span>
          <span style="font-size:.75rem;font-weight:600">${it.qty}x</span>
        </div>`).join('');
    }
  }
}
// Backward compat alias
async function loadCrossBranchChart(){ return loadDashboardChart(); }


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
  const d = await api('staff');
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
// ★ Pagination state ★
window._orderPage = 1;
window._orderLimit = 25;
window._orderStatus = '';

async function loadOrders(page=1){
  window._orderPage = page;
  const status = window._orderStatus ? `&status_filter=${window._orderStatus}` : '';
  const offset  = (page - 1) * window._orderLimit;
  const d = await api('orders', `branch_id=${window._currentBranch}&limit=${window._orderLimit}&offset=${offset}${status}`);
  const tbody = document.getElementById('orders-tbody');
  if (!tbody) return;
  if (!d.ok || !d.orders?.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Orders မရှိသေး</td></tr>';
    renderOrderPagination(0, page);
    return;
  }
  const statusColors = {pending:'#d97706',confirmed:'#2563eb',preparing:'#7c3aed',ready:'#059669',out_for_delivery:'#0891b2',delivered:'#6b7280',cancelled:'#dc2626'};
  const nextStatus   = {pending:'confirmed',confirmed:'preparing',preparing:'ready',ready:'out_for_delivery',out_for_delivery:'delivered'};
  const nextLabel    = {pending:'✓ Confirm',confirmed:'👨‍🍳 Prepare',preparing:'✅ Ready',ready:'🛵 Send',out_for_delivery:'📦 Delivered'};
  tbody.innerHTML = d.orders.map(o => `<tr>
    <td style="font-weight:600">#${o.id}<div style="font-size:.7rem;color:var(--muted)">${o.created_at?.slice(11,16)||''}</div></td>
    <td>
      ${escH(o.customer_name||'Walk-in')}
      <div style="font-size:.72rem;color:var(--muted)">${o.order_type==='dine_in'?'🪑 Dine-in':'🛵 Delivery'}</div>
      ${o.items_summary?`<div style="font-size:.68rem;color:var(--muted);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escH(o.items_summary)}</div>`:''}
    </td>
    <td style="font-weight:600">${parseInt(o.total_amount||0).toLocaleString()} MMK</td>
    <td style="font-size:.8rem">${o.payment_method?.toUpperCase()||'-'}</td>
    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:99px;background:${statusColors[o.status]||'#888'}22;color:${statusColors[o.status]||'#888'};font-weight:600">${o.status}</span></td>
    <td style="display:flex;gap:.3rem;flex-wrap:wrap">
      ${nextStatus[o.status]?`<button class="btn btn-primary btn-sm" onclick="updateOrderStatus(${o.id},'${nextStatus[o.status]}')">${nextLabel[o.status]}</button>`:''}
      ${o.status!=='cancelled'&&o.status!=='delivered'?`<button class="btn btn-ghost btn-sm" onclick="updateOrderStatus(${o.id},'cancelled')" style="color:#dc2626">✗</button>`:''}
    </td>
  </tr>`).join('');
  renderOrderPagination(d.total || d.orders.length, page);
}

function renderOrderPagination(total, page) {
  let el = document.getElementById('orders-pagination');
  if (!el) {
    el = document.createElement('div');
    el.id = 'orders-pagination';
    el.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-top:1px solid var(--border);font-size:.82rem;flex-wrap:wrap;gap:.5rem';
    document.getElementById('orders-tbody')?.closest('table')?.after(el);
  }
  const totalPages = Math.max(1, Math.ceil(total / window._orderLimit));
  el.innerHTML = `
    <span style="color:var(--muted)">စုစုပေါင်း ${total} orders</span>
    <div style="display:flex;gap:.4rem;align-items:center">
      <button onclick="loadOrders(${page-1})" ${page<=1?'disabled':''} style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--card);cursor:pointer;opacity:${page<=1?'.4':'1'}">‹</button>
      <span style="padding:4px 10px;background:var(--accent);color:#fff;border-radius:6px">${page} / ${totalPages}</span>
      <button onclick="loadOrders(${page+1})" ${page>=totalPages?'disabled':''} style="padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--card);cursor:pointer;opacity:${page>=totalPages?'.4':'1'}">›</button>
    </div>
    <select onchange="window._orderStatus=this.value;loadOrders(1)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);font-size:.8rem">
      <option value="">Status အားလုံး</option>
      <option value="pending" ${window._orderStatus==='pending'?'selected':''}>Pending</option>
      <option value="preparing" ${window._orderStatus==='preparing'?'selected':''}>Preparing</option>
      <option value="delivered" ${window._orderStatus==='delivered'?'selected':''}>Delivered</option>
      <option value="cancelled" ${window._orderStatus==='cancelled'?'selected':''}>Cancelled</option>
    </select>
  `;
}

async function updateOrderStatus(orderId, status){
  const d = await fetch('tenant.php?api=update_order_status',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({order_id:orderId, status})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast(`✅ Order #${orderId} → ${status}`,'ok'); await loadOrders(); }
  else toast(d.msg||'Error','err');
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
// schedLoad() removed - use loadSchedule() directly
function loadTables(){const el=document.getElementById('tables-content');if(el)el.innerHTML='<div style="color:var(--muted);padding:1rem">Tables loading...</div>';}
function openEditItem(id){toast('Edit item #'+id);}

/* ── QR Code generator (api.qrserver.com) ── */
function generateQR(url){
  const canvas = document.getElementById('qr-canvas');
  if(!canvas||!url||url==='Loading...') return;
  const img = new Image(); img.crossOrigin='anonymous';
  img.onload = ()=>{
    const ctx=canvas.getContext('2d');
    ctx.clearRect(0,0,160,160);
    ctx.drawImage(img,0,0,160,160);
  };
  img.onerror = ()=>{ /* silently fail - external image */ };
  img.src = `https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${encodeURIComponent(url)}`;
}

function downloadQR(){
  const url = document.getElementById('sf-order-url')?.textContent;
  if(!url||url==='Loading...'){ toast('QR not ready yet','err'); return; }
  const a = document.createElement('a');
  a.href = `https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=${encodeURIComponent(url)}`;
  a.download = 'myanai-order-qr.png'; a.target='_blank';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  toast('✅ QR download started','ok');
}
</script>


<script>
/* ═══ TENANT MODULE JS ═══ */

/* ── Helpers ── */
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openModal(id){ document.getElementById(id).classList.add('open'); }
function fmtK(n){ return parseInt(n||0).toLocaleString(); }
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function today(){ return new Date().toISOString().slice(0,10); }

/* ── Menu functions ── */
var _allMenuItems = [];

// Override loadMenuItems to also populate filter
const _origLoadMenu = loadMenuItems;
async function loadMenuItems(){
  const d = await api('items');
  if(!d.ok) return;
  _allMenuItems = d.items || [];

  // Plan usage
  const planMax = {free:20,basic:50,pro:200,enterprise:500};
  const max = planMax[window.__TENANT_PLAN] || 20;
  const pct = Math.round((_allMenuItems.length/max)*100);
  const bar = document.getElementById('menu-usage-bar');
  const lbl = document.getElementById('menu-usage-lbl');
  if(bar){ bar.style.width=Math.min(pct,100)+'%'; bar.style.background=pct>=100?'#dc2626':pct>=80?'#d97706':'#059669'; }
  if(lbl) lbl.textContent = `${_allMenuItems.length} / ${max} items (${(window.__TENANT_PLAN||'free').toUpperCase()})`;

  // Badge
  const badge = document.getElementById('menu-count-badge');
  if(badge) badge.textContent = _allMenuItems.length;

  // Category filter
  const cats = [...new Set(_allMenuItems.map(i=>i.category).filter(Boolean))];
  const cf = document.getElementById('menu-cat-filter');
  if(cf){ cf.innerHTML='<option value="">All categories</option>'+cats.map(c=>`<option>${c}</option>`).join(''); }

  renderMenuTable(_allMenuItems);
}

function filterMenu(){
  const q    = document.getElementById('menu-search')?.value?.toLowerCase()||'';
  const cat  = document.getElementById('menu-cat-filter')?.value||'';
  const list = _allMenuItems.filter(i=>{
    const mq  = !q  || i.name?.toLowerCase().includes(q)||i.category?.toLowerCase().includes(q);
    const mc  = !cat|| i.category===cat;
    return mq && mc;
  });
  renderMenuTable(list);
}

function renderMenuTable(items){
  const tbody = document.getElementById('menu-tbody');
  if(!tbody) return;
  if(!items.length){
    tbody.innerHTML=`<tr><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--muted)">
      <div style="font-size:2rem;margin-bottom:.5rem">🍽</div>
      Menu items မရှိသေး — <button class="btn btn-primary btn-sm" onclick="openAddItem()">+ Add first item</button>
    </td></tr>`; return;
  }
  tbody.innerHTML = items.map(it=>`<tr>
    <td style="font-weight:500">${it.emoji||'🍽'} ${escH(it.name)}</td>
    <td style="color:var(--muted);font-size:.8rem">${escH(it.category||'—')}</td>
    <td style="font-weight:600">${fmtK(it.price)} MMK</td>
    <td style="font-size:.8rem">${it.stock_qty!=null?it.stock_qty+'':'—'}</td>
    <td><span style="font-size:.72rem;padding:2px 8px;border-radius:99px;background:${it.is_active?'rgba(5,150,105,.1)':'rgba(128,128,128,.1)'};color:${it.is_active?'#065f46':'var(--muted)'}">${it.is_active?'Active':'Off'}</span></td>
    <td style="display:flex;gap:.35rem">
      <button class="btn btn-ghost btn-sm" onclick="openEditItem(${it.id})">Edit</button>
      <button class="btn btn-ghost btn-sm" onclick="toggleItemStatus(${it.id},${it.is_active})">${it.is_active?'Disable':'Enable'}</button>
    </td>
  </tr>`).join('');
}

function openAddItem(){
  document.getElementById('menu-modal-title').textContent = '+ Add menu item';
  document.getElementById('item-edit-id').value = '';
  ['item-name','item-emoji','item-category','item-price','item-desc'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.value='';
  });
  document.getElementById('item-stock').value = '100';
  document.getElementById('item-active').checked = true;
  openModal('modal-menu-item');
}

function openEditItem(id){
  const it = _allMenuItems.find(i=>i.id==id); if(!it) return;
  document.getElementById('menu-modal-title').textContent = 'Edit item';
  document.getElementById('item-edit-id').value = id;
  document.getElementById('item-name').value     = it.name||'';
  document.getElementById('item-emoji').value    = it.emoji||'';
  document.getElementById('item-category').value = it.category||'';
  document.getElementById('item-price').value    = it.price||'';
  document.getElementById('item-desc').value     = it.description||'';
  document.getElementById('item-stock').value    = it.stock_qty||0;
  document.getElementById('item-active').checked = !!it.is_active;
  openModal('modal-menu-item');
}

async function saveMenuItem(){
  const id       = document.getElementById('item-edit-id').value;
  const name     = document.getElementById('item-name').value.trim();
  const price    = parseInt(document.getElementById('item-price').value)||0;
  const category = document.getElementById('item-category').value.trim();
  if(!name||!price||!category){ toast('Name, price, category လိုအပ်သည်','err'); return; }

  const payload = {
    name, price, category,
    emoji:       document.getElementById('item-emoji').value.trim()||'🍽',
    description: document.getElementById('item-desc').value.trim(),
    stock_qty:   parseInt(document.getElementById('item-stock').value)||0,
    is_active:   document.getElementById('item-active').checked?1:0,
    branch_id:   window._currentBranch||0,
  };
  if(id) payload.id = parseInt(id);

  const action = id ? 'edit_item' : 'add_item';
  const d = await fetch(`menu_api.php?action=${action}&tenant_id=${window.__TENANT_ID}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify(payload)
  }).then(r=>r.json()).catch(()=>({ok:false,msg:'Network error'}));

  if(d.ok){
    toast(id?'✅ Item updated':'✅ Item added','ok');
    closeModal('modal-menu-item');
    await loadMenuItems();
  } else {
    toast(d.msg||'Error','err');
  }
}

async function toggleItemStatus(id, current){
  const d = await fetch(`menu_api.php?action=toggle_item&tenant_id=${window.__TENANT_ID}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({id, is_active: current?0:1})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok) await loadMenuItems();
  else toast('Error','err');
}

/* ── Tables ── */
async function loadTables(){
  // Auto-select first branch if none selected
  let bid = window._currentBranch;
  if(!bid){
    const br = await api('branches');
    if(br.ok && br.branches?.length){
      bid = br.branches[0].id;
      window._currentBranch = bid;
      const sel = document.getElementById('branch-select');
      if(sel) sel.value = bid;
    }
  }
  const d = await api('tables', `branch_id=${bid}`);
  const grid = document.getElementById('tables-grid');
  if(!grid) return;

  // Update KDS link
  const kdsLink = document.getElementById('kds-link');
  if(kdsLink) kdsLink.href = `kds.html?branch=${bid}&tenant=${window.__TENANT_ID}`;

  if(!d.ok||!d.tables?.length){
    grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:2.5rem;color:var(--muted)">
      <div style="font-size:2rem;margin-bottom:.5rem">🪑</div>Tables မရှိသေး
    </div>`; return;
  }
  const statusColors = {available:'#059669',occupied:'#dc2626',reserved:'#d97706'};
  const statusEmoji  = {available:'🟢',occupied:'🔴',reserved:'🟡'};
  grid.innerHTML = d.tables.map(t=>`
    <div style="background:var(--card);border:0.5px solid ${statusColors[t.status]||'var(--border)'};border-radius:var(--radius);padding:1.1rem;text-align:center;cursor:pointer;transition:all .15s"
         onclick="viewTable(${t.id},'${t.status}')">
      <div style="font-size:1.8rem;margin-bottom:.3rem">🪑</div>
      <div style="font-weight:600;font-size:.9rem">${escH(t.label||t.table_code)}</div>
      <div style="font-size:.72rem;color:var(--muted);margin:.2rem 0">${t.seats} seats</div>
      <div style="font-size:.78rem;font-weight:500;color:${statusColors[t.status]||'var(--muted)'}">${statusEmoji[t.status]||''} ${t.status||'available'}</div>
    </div>`).join('');
}

function openAddTable(){
  ['add-table-code','add-table-label'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  document.getElementById('add-table-seats').value='4';
  openModal('modal-add-table');
}

async function saveNewTable(){
  const code  = document.getElementById('add-table-code').value.trim();
  const label = document.getElementById('add-table-label').value.trim();
  const seats = parseInt(document.getElementById('add-table-seats').value)||4;
  if(!code||!label){ toast('Code and label required','err'); return; }
  const bid = window._currentBranch||0;
  const d = await fetch(`table_api.php?action=add&tenant_id=${window.__TENANT_ID}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({table_code:code, label, seats, branch_id:bid})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast('✅ Table added','ok'); closeModal('modal-add-table'); await loadTables(); }
  else toast(d.msg||'Error','err');
}

function viewTable(id, status){ toast(`Table #${id} — ${status}`); }

/* ── Stock ── */
var _stockItems = [];
async function stockLoad(){
  let bid = window._currentBranch;
  if(!bid){
    const br = await api('branches');
    if(br.ok && br.branches?.length) bid = br.branches[0].id;
  }
  const d = await fetch(`stock_api.php?action=overview&tenant_id=${window.__TENANT_ID}&branch_id=${bid||0}`,{credentials:'include'}).then(r=>r.json());
  if(!d.ok) return;
  _stockItems = d.items || [];

  // Summary
  const lowItems = _stockItems.filter(i=>i.stock_qty<=5);
  const sumEl = document.getElementById('stock-summary');
  if(sumEl) sumEl.innerHTML = `
    <div class="stat-card"><div class="stat-val">${_stockItems.length}</div><div class="stat-lbl">Total items</div></div>
    <div class="stat-card" style="${lowItems.length?'border-color:#dc2626':''}" onclick="filterStockLow()">
      <div class="stat-val" style="color:${lowItems.length?'#dc2626':'inherit'}">${lowItems.length}</div>
      <div class="stat-lbl">Low stock (≤5)</div>
    </div>`;

  renderStock(_stockItems);
}

function renderStock(items){
  const tbody = document.getElementById('stock-tbody');
  if(!tbody) return;
  if(!items.length){ tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No items</td></tr>'; return; }
  tbody.innerHTML = items.map(it=>`<tr>
    <td style="font-weight:500">${escH(it.name)}</td>
    <td style="color:var(--muted);font-size:.8rem">${escH(it.category||'—')}</td>
    <td style="font-weight:600;color:${it.stock_qty<=5?'#dc2626':it.stock_qty<=15?'#d97706':'inherit'}">${it.stock_qty}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${it.stock_qty<=5?'rgba(220,38,38,.1)':it.stock_qty<=15?'rgba(217,119,6,.1)':'rgba(5,150,105,.1)'};color:${it.stock_qty<=5?'#dc2626':it.stock_qty<=15?'#d97706':'#065f46'}">${it.stock_qty<=5?'⚠️ Low':it.stock_qty<=15?'Medium':'OK'}</span></td>
    <td><button class="btn btn-ghost btn-sm" onclick="openStockAdj(${it.id},'${escH(it.name)}')">Adjust</button></td>
  </tr>`).join('');
}

function filterStock(){ const q=document.getElementById('stock-search')?.value?.toLowerCase()||''; renderStock(_stockItems.filter(i=>!q||i.name?.toLowerCase().includes(q))); }
function filterStockLow(){ renderStock(_stockItems.filter(i=>i.stock_qty<=5)); }

function openStockAdj(id, name){
  document.getElementById('adj-item-id').value = id;
  document.getElementById('adj-title').textContent = `Adjust: ${name}`;
  document.getElementById('adj-qty').value = '';
  document.getElementById('adj-reason').value = '';
  openModal('modal-stock-adj');
}

async function submitStockAdj(){
  const id  = document.getElementById('adj-item-id').value;
  const qty = parseInt(document.getElementById('adj-qty').value)||0;
  const rsn = document.getElementById('adj-reason').value.trim();
  if(!qty){ toast('Qty required','err'); return; }
  const bid = window._currentBranch||0;
  const d = await fetch(`stock_api.php?action=adjust&tenant_id=${window.__TENANT_ID}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({item_id:id, change_qty:qty, reason:rsn, branch_id:bid})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast('✅ Stock adjusted','ok'); closeModal('modal-stock-adj'); await stockLoad(); }
  else toast(d.msg||'Error','err');
}

/* ── Delivery ── */
async function delLoad(){
  const d = await fetch(`delivery_api.php?action=active&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json());
  const tbody = document.getElementById('delivery-tbody');
  if(!tbody) return;
  const orders = d.orders || d.deliveries || [];
  if(!orders.length){ tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No active deliveries</td></tr>'; return; }
  tbody.innerHTML = orders.map(o=>`<tr>
    <td style="font-weight:500">#${o.id}</td>
    <td>${escH(o.customer_name||'—')}</td>
    <td style="font-size:.8rem;color:var(--muted)">${escH(o.delivery_address||'—').slice(0,30)}</td>
    <td style="font-weight:600">${fmtK(o.total_amount)} MMK</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:rgba(128,128,128,.1)">${o.status}</span></td>
    <td><button class="btn btn-ghost btn-sm" onclick="toast('Update status')">Update</button></td>
  </tr>`).join('');
}

/* ── Reservations ── */
async function resLoad(){
  const d = await fetch(`reservation_api.php?action=list&tenant_id=${window.__TENANT_ID}&branch_id=${window._currentBranch||0}`,{credentials:'include'}).then(r=>r.json());
  const tbody = document.getElementById('reserve-tbody');
  if(!tbody) return;
  const list = d.reservations || [];
  if(!list.length){ tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">Reservations မရှိသေး</td></tr>'; return; }
  tbody.innerHTML = list.map(r=>`<tr>
    <td style="font-weight:500">${escH(r.customer_name||'—')}</td>
    <td>${escH(r.customer_phone||'—')}</td>
    <td style="font-size:.8rem">${r.reservation_date||''} ${r.reservation_time||''}</td>
    <td>${r.party_size||1} ဦး</td>
    <td>${r.table_id||'—'}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:rgba(128,128,128,.1)">${r.status||'pending'}</span></td>
  </tr>`).join('');
}

function openAddReserve(){ toast('Reservation form — coming soon'); }

/* ── Expenses ── */
async function expLoad(){
  const d = await fetch(`expense_api.php?action=list&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json());
  const tbody = document.getElementById('expense-tbody');
  if(!tbody) return;
  const list = d.expenses || [];
  const total = list.reduce((s,e)=>s+(parseFloat(e.amount)||0),0);
  const sumEl = document.getElementById('expense-summary');
  if(sumEl) sumEl.innerHTML=`<div class="stat-card"><div class="stat-val">${fmtK(total)}</div><div class="stat-lbl">Total expenses (MMK)</div></div><div class="stat-card"><div class="stat-val">${list.length}</div><div class="stat-lbl">Transactions</div></div>`;
  if(!list.length){ tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">Expenses မရှိသေး</td></tr>'; return; }
  tbody.innerHTML = list.map(e=>`<tr>
    <td style="color:var(--muted);font-size:.8rem">${e.expense_date?.slice(0,10)||'—'}</td>
    <td style="font-size:.8rem">${escH(e.category||'—')}</td>
    <td>${escH(e.description||'—')}</td>
    <td style="font-weight:600">${fmtK(e.amount)} MMK</td>
  </tr>`).join('');
}

function openAddExpense(){
  document.getElementById('exp-date').value = today();
  openModal('modal-add-expense');
}

async function saveExpense(){
  const amt  = parseFloat(document.getElementById('exp-amount').value)||0;
  const desc = document.getElementById('exp-desc').value.trim();
  const cat  = document.getElementById('exp-cat').value;
  const date = document.getElementById('exp-date').value;
  if(!amt||!desc){ toast('Amount and description required','err'); return; }
  const d = await fetch(`expense_api.php?action=add&tenant_id=${window.__TENANT_ID}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({amount:amt, category:cat, description:desc, expense_date:date, branch_id:window._currentBranch||0})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast('✅ Expense saved','ok'); closeModal('modal-add-expense'); await expLoad(); }
  else toast(d.msg||'Error','err');
}

/* ── Branches ── */
async function branchLoad(){
  const d = await api('branches');
  const grid = document.getElementById('branches-grid');
  if(!grid) return;
  if(!d.ok||!d.branches?.length){ grid.innerHTML='<div style="color:var(--muted);padding:2rem">No branches</div>'; return; }
  grid.innerHTML = d.branches.map(b=>`
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:var(--radius);padding:1.2rem">
      <div style="font-size:1.3rem;margin-bottom:.5rem">🏢</div>
      <div style="font-weight:600;margin-bottom:.25rem">${escH(b.name)}</div>
      <div style="font-size:.8rem;color:var(--muted)">${escH(b.address||'')}</div>
      <div style="font-size:.78rem;color:var(--muted);margin-top:.25rem">${escH(b.phone||'')} ${b.open_time?'· '+b.open_time+'-'+b.close_time:''}</div>
      <div style="margin-top:.75rem;display:flex;gap:.35rem">
        <a href="kds.html?branch=${b.id}&tenant=${window.__TENANT_ID}" target="_blank" class="btn btn-ghost btn-sm">👨‍🍳 KDS</a>
        <a href="index.html?t=${window.__TENANT_SLUG||'demo'}&branch=${b.id}" target="_blank" class="btn btn-ghost btn-sm">🛒 Order</a>
      </div>
    </div>`).join('');
}

/* ── Promos ── */
async function promoLoad(){
  const d = await fetch(`promo_api.php?action=list&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json());
  const tbody = document.getElementById('promos-tbody');
  if(!tbody) return;
  const list = d.promos || [];
  if(!list.length){ tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Promotions မရှိသေး</td></tr>'; return; }
  tbody.innerHTML = list.map(p=>`<tr>
    <td style="font-weight:600;font-family:monospace">${escH(p.code)}</td>
    <td>${p.discount_pct||p.discount_flat? (p.discount_pct?p.discount_pct+'%':fmtK(p.discount_flat)+' MMK'):'—'}</td>
    <td>${fmtK(p.min_order_amt)} MMK</td>
    <td style="font-size:.78rem;color:var(--muted)">${p.valid_from?.slice(0,10)||''} ~ ${p.valid_to?.slice(0,10)||''}</td>
    <td><span style="font-size:.72rem;padding:2px 7px;border-radius:99px;background:${p.is_active?'rgba(5,150,105,.1)':'rgba(128,128,128,.1)'};color:${p.is_active?'#065f46':'var(--muted)'}">${p.is_active?'Active':'Off'}</span></td>
  </tr>`).join('');
}

function openAddPromo(){ toast('Promo form — coming soon'); }

/* ── Tenant slug for index.html links ── */
window.__TENANT_SLUG = '<?= $_SESSION["tenant_slug"] ?? "demo" ?>';
</script>
<script>
/* ── Auto-init if already logged in ── */
if(window.__IS_TENANT && window.__TENANT_ID > 0){
  initShell();
  showPage('dashboard');
}

/* ── Impersonation indicator (in header badge) ── */
if(window.__IMPERSONATING){
  const badge = document.getElementById('admin-mode-badge');
  if(badge){
    badge.style.display='flex';
  }
}
function exitImpersonate(){
  fetch('admin.php?api=logout',{method:'POST',credentials:'include'}).then(()=>{
    window.location.href='admin.php';
  });
}

/* ── Backup ── */
async function loadBackupPage(){
  const d = await fetch(`backup_api.php?action=info&tenant_id=${window.__TENANT_ID}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const el = document.getElementById('backup-counts');
  if(!el) return;
  if(!d.ok){ el.innerHTML='<div style="color:var(--muted)">Loading...</div>'; return; }
  const icons = {branches:'🏢',menu_items:'🍜',staff:'👥',tables:'🪑',orders:'🧾',crm_customers:'❤️',expenses:'💰'};
  el.innerHTML = Object.entries(d.counts).filter(([k])=>k!=='total').map(([k,v])=>`
    <div style="background:var(--card);border:0.5px solid var(--border);border-radius:10px;padding:.7rem .9rem;text-align:center">
      <div style="font-size:1.2rem">${icons[k]||'📦'}</div>
      <div style="font-size:1.1rem;font-weight:600;margin:.2rem 0">${v}</div>
      <div style="font-size:.72rem;color:var(--muted)">${k.replace('_',' ')}</div>
    </div>`).join('') +
    `<div style="background:var(--accent);color:var(--warm);border-radius:10px;padding:.7rem .9rem;text-align:center">
      <div style="font-size:1.2rem">📦</div>
      <div style="font-size:1.1rem;font-weight:600;margin:.2rem 0">${d.counts.total}</div>
      <div style="font-size:.72rem;opacity:.8">total records</div>
    </div>`;
}

async function downloadBackup(){
  const btn = document.getElementById('backup-download-btn');
  const status = document.getElementById('backup-status');
  if(btn){ btn.disabled=true; btn.textContent='⏳ Preparing backup...'; }
  try {
    const res = await fetch(`backup_api.php?action=export&tenant_id=${window.__TENANT_ID}`,{credentials:'include'});
    if(!res.ok) throw new Error('Export failed');
    const blob = await res.blob();
    const fname = res.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || `backup-${Date.now()}.json`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href=url; a.download=fname; document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
    const records = res.headers.get('X-Backup-Records') || '?';
    if(status) status.textContent = `✅ Downloaded — ${records} records in backup`;
    toast('✅ Backup downloaded','ok');
  } catch(e) {
    if(status) status.textContent = '❌ Backup failed: '+e.message;
    toast('Backup failed','err');
  } finally {
    if(btn){ btn.disabled=false; btn.textContent='⬇️ Download my data backup'; }
  }
}

/* ── Storefront ── */
async function loadStorefront(){
  // Set order URL
  const slug = window.__TENANT_SLUG || (window._currentTenant ? 'tenant' : 'demo');
  const branchParam = window._currentBranch ? '&branch=' + window._currentBranch : '';
  const url = location.origin + '/index.html?t=' + slug + branchParam;
  const urlEl = document.getElementById('sf-order-url');
  const linkEl = document.getElementById('sf-order-link');
  const previewBtn = document.getElementById('sf-preview-btn');
  if(urlEl) urlEl.textContent = url;
  if(linkEl) linkEl.href = url;
  if(previewBtn) previewBtn.href = url;

  // Load existing settings from tenant settings JSON
  const d = await fetch(`tenant.php?api=get_payment_settings`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok && d.settings){
    const s = d.settings;
    const set=(id,v)=>{const el=document.getElementById(id);if(el&&v)el.value=v;};
    set('sf-name',    s.store_name);
    set('sf-tagline', s.store_tagline);
    set('sf-phone',   s.store_phone);
    set('sf-address', s.store_address);
    set('sf-emoji',   s.store_emoji);
    set('sf-color',   s.brand_color||'#e84c2b');
    set('sf-color-hex', s.brand_color||'#e84c2b');
  }
}

function copyOrderUrl(){
  const url = document.getElementById('sf-order-url')?.textContent;
  if(url) navigator.clipboard.writeText(url).then(()=>toast('✅ URL copied','ok'));
}

async function saveStorefront(){
  const g=(id)=>document.getElementById(id)?.value?.trim()||'';
  const payload = {
    store_name:    g('sf-name'),
    store_tagline: g('sf-tagline'),
    store_phone:   g('sf-phone'),
    store_address: g('sf-address'),
    store_emoji:   g('sf-emoji')||'🍜',
    brand_color:   document.getElementById('sf-color')?.value||'#e84c2b',
  };
  const d = await fetch('tenant.php?api=save_payment_settings',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify(payload)
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok) toast('✅ Storefront saved','ok');
  else toast(d.msg||'Error','err');
}
</script>
<script>
if('serviceWorker' in navigator){
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then(reg => console.log('SW registered'))
      .catch(err => console.log('SW error', err));
  });
}
</script>
</body>
</html>
<script>
/* ═══ SHIFT & SCHEDULE JS ═══ */

var _schedWeekOffset = 0;
var _schedStaff = [];
var _schedShifts = [];

/* ── Week navigation ── */
function getWeekDates(offset=0){
  const now = new Date();
  const day = now.getDay() || 7; // Mon=1
  const mon = new Date(now); mon.setDate(now.getDate()-day+1+offset*7);
  const days = [];
  for(let i=0;i<7;i++){
    const d = new Date(mon); d.setDate(mon.getDate()+i);
    days.push(d);
  }
  return days;
}

function schedWeek(dir){
  if(dir===0) _schedWeekOffset=0;
  else _schedWeekOffset+=dir;
  loadSchedule();
}

function fmtDate(d){ return d.toISOString().slice(0,10); }
function fmtDay(d){ return d.toLocaleDateString('en',{weekday:'short',day:'numeric',month:'short'}); }

/* ── Load Schedule (weekly grid) ── */
async function loadSchedule(){
  const days = getWeekDates(_schedWeekOffset);
  const lbl = document.getElementById('sched-week-label');
  if(lbl) lbl.textContent = `${fmtDay(days[0])} — ${fmtDay(days[6])}`;

  // Build header
  const head = document.getElementById('sched-head');
  if(head) head.innerHTML = '<th style="min-width:100px;font-size:.78rem">Staff</th>' +
    days.map(d=>`<th style="font-size:.75rem;text-align:center;padding:.4rem .3rem">${fmtDay(d)}</th>`).join('');

  // Load staff + shifts in parallel
  const [staffRes, shiftsRes] = await Promise.all([
    api('staff'),
    fetch(`shift_api.php?action=list&week=${fmtDate(days[0]).slice(0,7)}-W${getISOWeek(days[0])}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false,shifts:[]}))
  ]);

  _schedStaff  = staffRes.staff  || [];
  _schedShifts = shiftsRes.shifts || [];

  // Populate shift staff selector
  const sel = document.getElementById('shift-staff');
  if(sel) sel.innerHTML = '<option value="">Select staff...</option>' +
    _schedStaff.map(s=>`<option value="${s.id}">${s.name} (${s.role})</option>`).join('');

  // Build body
  const tbody = document.getElementById('sched-body');
  if(!tbody) return;
  if(!_schedStaff.length){ tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">No staff found</td></tr>'; return; }

  const shiftColors={morning:'#10b981',afternoon:'#3b82f6',evening:'#8b5cf6',off:'#6b7280',custom:'#f59e0b'};
  const shiftEmoji ={morning:'🟢',afternoon:'🔵',evening:'🟣',off:'⚫',custom:'🟡'};

  tbody.innerHTML = _schedStaff.map(st=>`<tr>
    <td style="font-weight:500;font-size:.82rem;white-space:nowrap">${escH(st.name)}<div style="font-size:.7rem;color:var(--muted)">${st.role}</div></td>
    ${days.map(d=>{
      const dateStr = fmtDate(d);
      const shift   = _schedShifts.find(s=>s.staff_id==st.id && s.shift_date===dateStr);
      return `<td style="text-align:center;padding:.3rem">
        ${shift ? `<div style="font-size:.75rem;background:${shiftColors[shift.shift_type]||'#888'}22;color:${shiftColors[shift.shift_type]||'#888'};border-radius:6px;padding:3px 6px;cursor:pointer" onclick="deleteShift(${shift.id})" title="${shift.start_time||''}-${shift.end_time||''}">${shiftEmoji[shift.shift_type]||''}<br>${shift.shift_type}</div>`
        : `<button style="font-size:.75rem;background:none;border:0.5px dashed var(--border);border-radius:6px;padding:3px 8px;color:var(--muted);cursor:pointer" onclick="quickAddShift(${st.id},'${dateStr}')">+</button>`}
      </td>`;
    }).join('')}
  </tr>`).join('');
}

function getISOWeek(d){
  const dt=new Date(d); dt.setHours(0,0,0,0); dt.setDate(dt.getDate()+4-(dt.getDay()||7));
  const y=new Date(dt.getFullYear(),0,1);
  return Math.ceil((((dt-y)/86400000)+1)/7);
}

/* ── Load Shifts (daily list) ── */
async function loadShifts(){
  const dateFilter = document.getElementById('shift-date-filter')?.value || fmtDate(new Date());
  if(!document.getElementById('shift-date-filter')?.value){
    const el = document.getElementById('shift-date-filter');
    if(el) el.value = dateFilter;
  }
  const d = await fetch(`shift_api.php?action=list&date=${dateFilter}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const tbody = document.getElementById('shifts-tbody');
  if(!tbody) return;
  const list = d.shifts||[];
  if(!list.length){ tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No shifts for this date</td></tr>'; return; }
  const shiftEmoji={morning:'🟢',afternoon:'🔵',evening:'🟣',off:'⚫',custom:'🟡'};
  tbody.innerHTML = list.map(s=>`<tr>
    <td style="font-weight:500">${escH(s.staff_name||'—')}</td>
    <td style="font-size:.8rem;color:var(--muted)">${s.shift_date}</td>
    <td>${shiftEmoji[s.shift_type]||''} ${s.shift_type}</td>
    <td style="font-size:.8rem">${s.start_time?.slice(0,5)||''} – ${s.end_time?.slice(0,5)||''}</td>
    <td style="font-size:.78rem;color:var(--muted)">${s.notes||'—'}</td>
    <td><button class="btn btn-ghost btn-sm" onclick="deleteShift(${s.id})" style="color:#dc2626">✗</button></td>
  </tr>`).join('');
}

/* ── Add Shift Modal ── */
function openAddShift(){
  document.getElementById('shift-date').value = fmtDate(new Date());
  if(!_schedStaff.length){
    api('staff').then(d=>{ _schedStaff=d.staff||[];
      const sel=document.getElementById('shift-staff');
      if(sel) sel.innerHTML='<option value="">Select staff...</option>'+_schedStaff.map(s=>`<option value="${s.id}">${s.name}</option>`).join('');
    });
  }
  openModal('modal-add-shift');
}

function quickAddShift(staffId, date){
  document.getElementById('shift-staff').value = staffId;
  document.getElementById('shift-date').value  = date;
  openModal('modal-add-shift');
}

async function saveShift(){
  const staffId = document.getElementById('shift-staff').value;
  const date    = document.getElementById('shift-date').value;
  const type    = document.getElementById('shift-type').value;
  const start   = document.getElementById('shift-start').value;
  const end     = document.getElementById('shift-end').value;
  const notes   = document.getElementById('shift-notes').value.trim();
  if(!staffId||!date){ toast('Staff and date required','err'); return; }
  const d = await fetch('shift_api.php?action=add',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({staff_id:staffId,shift_date:date,shift_type:type,start_time:start,end_time:end,notes})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast('✅ Shift saved','ok'); closeModal('modal-add-shift'); loadSchedule(); loadShifts(); }
  else toast(d.msg||'Error','err');
}

async function deleteShift(id){
  if(!confirm('Delete this shift?')) return;
  const d = await fetch('shift_api.php?action=delete',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({id})
  }).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){ toast('Shift deleted','ok'); loadSchedule(); loadShifts(); }
  else toast('Error','err');
}
</script>
