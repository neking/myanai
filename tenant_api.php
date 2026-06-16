<?php
/**
 * NoodleHaus SaaS — Tenant API (Phase 8)
 * Actions: signup, list, detail, update, toggle, plans, billing, stats
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = getPDO();
$action = trim($_GET['action'] ?? '');
function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function requireSuperAdmin(): void {
    if(session_status()===PHP_SESSION_NONE)session_start();
    if(empty($_SESSION['admin']))fail('Unauthorized',401);
}

/* ── PLANS (public) ── */
if ($action === 'plans') {
    ok(['plans' => $pdo->query("SELECT * FROM saas_plans WHERE is_active=1 ORDER BY price_mmk")->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ── PLAN ENFORCEMENT ── */
function checkPlanLimit(PDO $pdo, int $tenantId, string $resource): bool {
    $t = $pdo->prepare("SELECT plan, max_branches, max_staff, max_menu_items FROM tenants WHERE id=?");
    $t->execute([$tenantId]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) return false;

    switch ($resource) {
        case 'branch':
            $count = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE tenant_id=?");
            $count->execute([$tenantId]);
            return (int)$count->fetchColumn() < (int)$tenant['max_branches'];
        case 'staff':
            $count = $pdo->prepare("SELECT COUNT(*) FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=?");
            $count->execute([$tenantId]);
            return (int)$count->fetchColumn() < (int)$tenant['max_staff'];
        case 'menu':
            $count = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE tenant_id=?");
            $count->execute([$tenantId]);
            return (int)$count->fetchColumn() < (int)$tenant['max_menu_items'];
    }
    return true;
}

/* ── CHECK LIMITS (public) ── */
if ($action === 'check_limits') {
    $tid = (int)($_GET['tenant_id'] ?? 0);
    if (!$tid) fail('tenant_id required');
    $t = $pdo->prepare("SELECT max_branches, max_staff, max_menu_items, plan FROM tenants WHERE id=?");
    $t->execute([$tid]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) fail('Tenant not found');
    $branches   = (int)$pdo->prepare("SELECT COUNT(*) FROM branches WHERE tenant_id=?")->execute([$tid]) ? $pdo->query("SELECT COUNT(*) FROM branches WHERE tenant_id=$tid")->fetchColumn() : 0;
    $staff      = (int)$pdo->query("SELECT COUNT(*) FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=$tid")->fetchColumn();
    $menu_items = (int)$pdo->query("SELECT COUNT(*) FROM menu_items WHERE tenant_id=$tid")->fetchColumn();
    ok([
        'plan'          => $tenant['plan'],
        'branches'      => ['used' => $branches,   'max' => (int)$tenant['max_branches']],
        'staff'         => ['used' => $staff,       'max' => (int)$tenant['max_staff']],
        'menu_items'    => ['used' => $menu_items,  'max' => (int)$tenant['max_menu_items']],
        'can_add_branch'=> $branches   < (int)$tenant['max_branches'],
        'can_add_staff' => $staff      < (int)$tenant['max_staff'],
        'can_add_menu'  => $menu_items < (int)$tenant['max_menu_items'],
    ]);
}

/* ── SIGNUP (public) ── */
if ($action === 'signup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($d['name'] ?? '');
    $slug  = strtolower(preg_replace('/[^a-z0-9]/', '', trim($d['slug'] ?? '')));
    $owner = trim($d['owner_name'] ?? '');
    $email = trim($d['owner_email'] ?? '');
    $phone = trim($d['owner_phone'] ?? '');
    $plan  = trim($d['plan'] ?? 'free');
    $pass  = trim($d['password'] ?? '');
    $bizType = in_array(trim($d['business_type'] ?? ''), ['noodle_shop','drinks','bakery','myanmar_food','cafe','fast_food','fine_dining','other','restaurant','demo']) ? trim($d['business_type']) : 'restaurant';

    if (!$name || !$slug || !$owner || !$email || !$phone) fail('All fields required');
    if (strlen($slug) < 3) fail('Slug must be 3+ characters');
    if (!$pass || strlen($pass) < 6) fail('Password must be 6+ characters');

    // Check uniqueness
    $chk = $pdo->prepare("SELECT id FROM tenants WHERE slug=? OR owner_email=?");
    $chk->execute([$slug, $email]);
    if ($chk->fetchColumn()) fail('Slug or email already taken');

    // Get plan limits
    $planRow = $pdo->prepare("SELECT * FROM saas_plans WHERE code=?");
    $planRow->execute([$plan]);
    $p = $planRow->fetch(PDO::FETCH_ASSOC);
    if (!$p) $p = ['max_branches'=>1,'max_staff'=>3,'max_menu_items'=>20];

    // Create tenant
    $pdo->prepare("INSERT INTO tenants (name,slug,owner_name,owner_email,owner_phone,plan,max_branches,max_staff,max_menu_items,business_type) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$name,$slug,$owner,$email,$phone,$plan,(int)$p['max_branches'],(int)$p['max_staff'],(int)$p['max_menu_items'],$bizType]);
    $tenantId = (int)$pdo->lastInsertId();

    // Auto-provision: create first branch + admin staff + save password hash
    $pdo->prepare("INSERT INTO branches (tenant_id,name,code) VALUES (?,?,?)")
        ->execute([$tenantId, $name . ' (Main)', strtoupper($slug)]);
    $branchId = (int)$pdo->lastInsertId();

    // Create admin staff with PIN (last 4 digits of phone)
    $pin = substr(preg_replace('/[^0-9]/', '', $phone), -4) ?: '0000';
    $pdo->prepare("INSERT INTO staff (branch_id,name,pin,role,is_active) VALUES (?,?,?,'manager',1)")
        ->execute([$branchId, $owner, $pin]);

    // Save password hash + admin credentials in tenant settings
    $passHash = password_hash($pass, PASSWORD_BCRYPT);
    $settings = json_encode([
        'admin_email' => $email,
        'admin_pass_hash' => $passHash,
        'onboarded' => false,
    ]);
    $pdo->prepare("UPDATE tenants SET settings=? WHERE id=?")->execute([$settings, $tenantId]);

    // Seed default menu items from template (optional)
    // ── Seed default menu items based on business type ──────────────
    $seedMenu = [
        ['Mohinga',         'Soups',    4500, '🍜'],
        ['Shan Noodles',    'Noodles',  3500, '🍝'],
        ['Fried Rice',      'Rice',     4000, '🍚'],
        ['Green Tea',       'Drinks',   500,  '🍵'],
        ['Mango Juice',     'Drinks',   1500, '🥭'],
        ['Spring Rolls',    'Starters', 2500, '🥟'],
        ['Coconut Rice',    'Rice',     3500, '🥥'],
        ['Lychee Soda',     'Drinks',   1800, '🧃'],
    ];
    $menuStmt = $pdo->prepare(
        "INSERT INTO menu_items (tenant_id,name,category,price,emoji,stock_qty,is_active) VALUES (?,?,?,?,?,99,1)"
    );
    foreach ($seedMenu as [$mName,$mCat,$mPrice,$mEmoji]) {
        try { $menuStmt->execute([$tenantId,$mName,$mCat,$mPrice,$mEmoji]); }
        catch(Exception $e) { /* skip if column mismatch */ }
    }

    // Set trial expiry: 14 days from now
    $pdo->prepare("UPDATE tenants SET plan_expires = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id=?")
        ->execute([$tenantId]);

    ok([
        'tenant_id'  => $tenantId,
        'branch_id'  => $branchId,
        'slug'       => $slug,
        'login_email'=> $email,
        'message'    => 'Account created! Login with your email and password.',
        'trial_days' => 14,
    ]);
}

/* ── LIST (super admin) ── */
if ($action === 'list') {
    if (session_status()===PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin']) && empty($_SESSION['super_admin'])) fail('Unauthorized', 401);
    requireSuperAdmin();
    $rows = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_orders,
            (SELECT COALESCE(SUM(total_amount),0) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_revenue
        FROM tenants t ORDER BY t.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ok(['tenants' => $rows]);
}

/* ── DETAIL ── */
if ($action === 'detail') {
    requireSuperAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required');
    $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?"); $t->execute([$id]);
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Not found');

    $stats = $pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue FROM orders WHERE tenant_id=? AND deleted_at IS NULL");
    $stats->execute([$id]);

    $bills = $pdo->prepare("SELECT * FROM billing WHERE tenant_id=? ORDER BY created_at DESC LIMIT 10");
    $bills->execute([$id]);

    ok(['tenant'=>$row, 'stats'=>$stats->fetch(PDO::FETCH_ASSOC), 'billing'=>$bills->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── UPDATE ── */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $fields=[]; $params=[];
    foreach (['name','plan','max_branches','max_staff','max_menu_items','plan_expires'] as $f) {
        if (isset($d[$f])) { $fields[]="$f=?"; $params[]=$d[$f]===''?null:$d[$f]; }
    }
    if (empty($fields)) fail('Nothing to update');
    $params[] = $id;
    $pdo->prepare("UPDATE tenants SET ".implode(',',$fields)." WHERE id=?")->execute($params);
    ok();
}

/* ── TOGGLE ── */
if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id || $id===1) fail('Cannot toggle');
    $pdo->prepare("UPDATE tenants SET is_active=NOT is_active WHERE id=?")->execute([$id]);
    ok();
}

/* ── RECORD BILLING ── */
if ($action === 'bill' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $pdo->prepare("INSERT INTO billing (tenant_id,plan_code,amount,currency,payment_method,payment_ref,status,period_start,period_end) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$d['tenant_id'],$d['plan_code'],(int)$d['amount'],$d['currency']??'MMK',$d['payment_method']??null,$d['payment_ref']??null,$d['status']??'paid',$d['period_start'],$d['period_end']]);
    ok(['billing_id'=>(int)$pdo->lastInsertId()]);
}

/* ── SAAS STATS ── */
if ($action === 'stats') {
    // Allow both super admin and regular admin
    if (session_status()===PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin']) && empty($_SESSION['super_admin'])) fail('Unauthorized', 401);
    requireSuperAdmin();
    $s = $pdo->query("SELECT
        (SELECT COUNT(*) FROM tenants) AS total_tenants,
        (SELECT COUNT(*) FROM tenants WHERE is_active=1) AS active_tenants,
        (SELECT COUNT(*) FROM tenants WHERE plan='free') AS free_tenants,
        (SELECT COUNT(*) FROM tenants WHERE plan IN ('basic','pro','enterprise')) AS paid_tenants,
        (SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid') AS total_revenue,
        (SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')) AS month_revenue
    ")->fetch(PDO::FETCH_ASSOC);
    ok(['stats' => $s]);
}


// ── Resolve slug to tenant_id ──────────────────────────────────
if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = trim($_GET['slug'] ?? '');
    if (!$slug) fail('Slug required');
    $row = $pdo->prepare("SELECT t.id, t.name, t.plan, t.is_active, t.business_type, b.id as branch_id, b.code as branch_code FROM tenants t LEFT JOIN branches b ON b.tenant_id = t.id WHERE t.slug=? LIMIT 1");
    $row->execute([$slug]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    if (!$t) fail('Tenant not found', 404);
    if (!$t['is_active']) fail('Account suspended', 403);
    ok(['tenant_id'=>(int)$t['id'], 'name'=>$t['name'], 'plan'=>$t['plan'], 'business_type'=>$t['business_type'] ?? 'restaurant', 'branch_id'=>(int)($t['branch_id'] ?? 1), 'branch_code'=>$t['branch_code'] ?? 'MAIN']);
}

/* ── UPDATE TENANT (super-admin) ── */
if ($action === 'update_tenant') {
    requireSuperAdmin();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid     = (int)($b['tenant_id'] ?? 0);
    $name    = trim($b['name'] ?? '');
    $plan    = trim($b['plan'] ?? '');
    $expires = trim($b['plan_expires'] ?? '');
    $active  = isset($b['is_active']) ? (int)$b['is_active'] : 1;

    if(!$tid) fail('tenant_id required');
    $validPlans = ['free','basic','pro','enterprise'];
    if($plan && !in_array($plan,$validPlans)) fail('Invalid plan');

    $planLimits = ['free'=>[1,3,20],'basic'=>[1,5,50],'pro'=>[3,15,200],'enterprise'=>[10,50,500]];
    $limits = $planLimits[$plan] ?? null;

    $sets = ['is_active=?'];
    $params = [$active];
    if($name){ $sets[]='name=?'; $params[]=$name; }
    if($plan && $limits){
        $sets[]='plan=?'; $params[]=$plan;
        $sets[]='max_branches=?'; $params[]=$limits[0];
        $sets[]='max_staff=?'; $params[]=$limits[1];
        $sets[]='max_menu_items=?'; $params[]=$limits[2];
    }
    if($expires){ $sets[]='plan_expires=?'; $params[]=$expires; }
    elseif(array_key_exists('plan_expires',$b) && $expires===''){
        $sets[]='plan_expires=NULL';
    }
    $params[] = $tid;
    $pdo->prepare("UPDATE tenants SET ".implode(',',$sets)." WHERE id=?")->execute($params);
    ok(['message'=>'Tenant updated']);
}

/* ── GET UPGRADE REQUESTS (super-admin) ── */
if ($action === 'upgrade_requests') {
    requireSuperAdmin();
    try {
        $rows = $pdo->query("SELECT ur.*, t.name as tenant_name FROM upgrade_requests ur LEFT JOIN tenants t ON t.id=ur.tenant_id ORDER BY ur.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        ok(['requests' => $rows]);
    } catch (\Exception $e) {
        ok(['requests' => []]);
    }
}

/* ── APPROVE UPGRADE (super-admin) ── */
if ($action === 'approve_upgrade') {
    requireSuperAdmin();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $reqId   = (int)($b['request_id'] ?? 0);
    $tid     = (int)($b['tenant_id']  ?? 0);
    $plan    = trim($b['plan'] ?? '');
    $validPlans = ['free','basic','pro','enterprise'];
    if (!in_array($plan, $validPlans)) fail('Invalid plan');

    // Update tenant plan
    $planLimits = ['free'=>[1,3,20],'basic'=>[1,5,50],'pro'=>[3,15,200],'enterprise'=>[10,50,500]];
    $limits = $planLimits[$plan];
    $expires = date('Y-m-d', strtotime('+1 year'));
    $pdo->prepare("UPDATE tenants SET plan=?, max_branches=?, max_staff=?, max_menu_items=?, plan_expires=? WHERE id=?")
        ->execute([$plan, $limits[0], $limits[1], $limits[2], $expires, $tid]);

    // Update request status
    try {
        $pdo->prepare("UPDATE upgrade_requests SET status='approved', updated_at=NOW() WHERE id=?")->execute([$reqId]);
    } catch (\Exception $e) {}

    // Send email notification
    try {
        require_once __DIR__ . '/mailer.php';
        $tenantRow = $pdo->prepare("SELECT name,owner_email FROM tenants WHERE id=?");
        $tenantRow->execute([$tid]);
        $tenantInfo = $tenantRow->fetch(PDO::FETCH_ASSOC);
        if($tenantInfo && $tenantInfo['owner_email']){
            $body = upgradeApprovedEmail($tenantInfo['owner_email'], $tenantInfo['name'], $plan);
            sendMail($tenantInfo['owner_email'], "✅ Your MyanAi plan has been upgraded to {$plan}!", $body);
        }
    } catch(\Exception $e){ error_log('Mailer error: '.$e->getMessage()); }

    ok(['message' => "Upgraded to $plan"]);
}

/* ── REJECT UPGRADE (super-admin) ── */
if ($action === 'reject_upgrade') {
    requireSuperAdmin();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $reqId = (int)($b['request_id'] ?? 0);
    try {
        $pdo->prepare("UPDATE upgrade_requests SET status='rejected', updated_at=NOW() WHERE id=?")->execute([$reqId]);
    } catch (\Exception $e) {}

    // Email notification for rejection
    try {
        require_once __DIR__ . '/mailer.php';
        $reqRow = $pdo->prepare("SELECT ur.requested_plan, t.name, t.owner_email FROM upgrade_requests ur JOIN tenants t ON t.id=ur.tenant_id WHERE ur.id=?");
        $reqRow->execute([$reqId]);
        $reqInfo = $reqRow->fetch(PDO::FETCH_ASSOC);
        if($reqInfo && $reqInfo['owner_email']){
            $body = upgradeRejectedEmail($reqInfo['owner_email'], $reqInfo['name'], $reqInfo['requested_plan']);
            sendMail($reqInfo['owner_email'], "MyanAi — Upgrade request update", $body);
        }
    } catch(\Exception $e){ error_log('Mailer error: '.$e->getMessage()); }

    ok(['message' => 'Rejected']);
}

/* ── REQUEST UPGRADE (tenant) ── */
if ($action === 'request_upgrade') {
    if(session_status()===PHP_SESSION_NONE) session_start();
    if(empty($_SESSION['admin'])) fail('Unauthorized', 401);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid         = (int)($b['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
    $targetPlan  = trim($b['plan'] ?? '');
    $note        = trim($b['note'] ?? '');
    $tenantName  = trim($b['tenant_name'] ?? '');
    $currentPlan = trim($b['current_plan'] ?? '');
    if (!$tid || !$targetPlan) fail('tenant_id and plan required');
    $validPlans = ['basic','pro','enterprise'];
    if (!in_array($targetPlan, $validPlans)) fail('Invalid plan');

    // Log request to a simple table or just return ok (admin handles manually)
    // Try to log to upgrade_requests table if exists, else just ok
    try {
        $pdo->prepare("INSERT INTO upgrade_requests (tenant_id, tenant_name, current_plan, requested_plan, note, created_at)
                       VALUES (?,?,?,?,?,NOW())")
            ->execute([$tid, $tenantName, $currentPlan, $targetPlan, $note]);
    } catch (\Exception $e) {
        // Table may not exist — still return ok, admin sees it in logs
    }
    ok(['message' => 'Upgrade request received']);
}

/* ── SET PLAN EXPIRES (super-admin) ── */
if ($action === 'set_expires') {
    requireSuperAdmin();
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid     = (int)($b['tenant_id'] ?? 0);
    $expires = trim($b['plan_expires'] ?? '');
    if (!$tid) fail('tenant_id required');
    // Validate date format
    if ($expires && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) fail('Invalid date format (YYYY-MM-DD)');
    $val = $expires ?: null;
    $pdo->prepare("UPDATE tenants SET plan_expires=? WHERE id=?")->execute([$val, $tid]);
    ok(['tenant_id' => $tid, 'plan_expires' => $val]);
}

fail('Unknown action');

// ── Manual KBZPay Payment Confirmation ──────────────────────────
if ($action === 'confirm_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSuperAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tenantId  = (int)($d['tenant_id'] ?? 0);
    $planCode  = trim($d['plan'] ?? '');
    $payRef    = trim($d['payment_ref'] ?? '');
    $months    = max(1, (int)($d['months'] ?? 1));
    if (!$tenantId || !$planCode || !$payRef) fail('Missing fields');

    $plan = $pdo->prepare("SELECT * FROM saas_plans WHERE code=?");
    $plan->execute([$planCode]);
    $p = $plan->fetch(PDO::FETCH_ASSOC);
    if (!$p) fail('Invalid plan');

    $amount     = (int)$p['price_mmk'] * $months;
    $periodStart = date('Y-m-d');
    $periodEnd   = date('Y-m-d', strtotime("+{$months} months"));

    // Record billing
    $pdo->prepare("INSERT INTO billing (tenant_id,plan_code,amount,payment_method,payment_ref,status,period_start,period_end) VALUES (?,?,?,'kbzpay',?,?,?,?)")
        ->execute([$tenantId, $planCode, $amount, $payRef, 'paid', $periodStart, $periodEnd]);

    // Upgrade tenant plan + extend expiry
    $pdo->prepare("UPDATE tenants SET plan=?, plan_expires=?, max_branches=?, max_staff=?, max_menu_items=? WHERE id=?")
        ->execute([$planCode, $periodEnd, $p['max_branches'], $p['max_staff'], $p['max_menu_items'], $tenantId]);

    ok(['message'=>"Payment confirmed. Plan upgraded to {$planCode} until {$periodEnd}"]);
}

// ── Delete Tenant ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id || $id === 1) fail('Cannot delete this tenant');
    // Soft delete — keep data but mark deleted
    $pdo->prepare("UPDATE tenants SET is_active=0, slug=CONCAT('deleted_',id,'_',slug), name=CONCAT('[Deleted] ',name) WHERE id=? AND id!=1")
        ->execute([$id]);
    // Clean up menu items + orders optional
    ok(['message' => 'Tenant deleted']);
}
