<?php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$pdo    = getPDO();
$action = $_GET['action'] ?? '';

function ok($d=[]){ echo json_encode(['ok'=>true]+$d); exit; }
function fail($m){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function isAdmin(){ return !empty($_SESSION['admin']); }

/* ── Auto-generate alerts from current system state ── */
function generateAlerts(PDO $pdo): array {
    $alerts = [];
    $now = date('Y-m-d H:i:s');

    // 1. Plans expiring in 7 days
    $exp = $pdo->query("SELECT id,name,owner_email as email,plan_expires FROM tenants WHERE is_active=1 AND plan_expires IS NOT NULL AND plan_expires BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exp as $t) {
        $days = (int)ceil((strtotime($t['plan_expires']) - time()) / 86400);
        $alerts[] = ['type'=>'plan_expiry','level'=>$days<=2?'danger':'warning','title'=>"Plan expiring: {$t['name']}",'body'=>"Plan expires in {$days} days ({$t['plan_expires']})",'tenant_id'=>$t['id']];
    }

    // 2. Disk usage > 80%
    $disk = disk_free_space('/');
    $diskTotal = disk_total_space('/');
    $usedPct = $diskTotal > 0 ? round((1 - $disk/$diskTotal)*100) : 0;
    if ($usedPct >= 80) {
        $alerts[] = ['type'=>'disk_usage','level'=>$usedPct>=90?'danger':'warning','title'=>"Disk usage: {$usedPct}%",'body'=>"Free: ".round($disk/1073741824,1)."GB — Consider cleanup or expansion",'tenant_id'=>null];
    }

    // 3. Error log spikes (>20 errors in last hour)
    try {
        $errCount = $pdo->query("SELECT COUNT(*) FROM error_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        if ($errCount > 20) {
            $alerts[] = ['type'=>'error_spike','level'=>'danger','title'=>"Error spike: {$errCount} errors/hour",'body'=>"Unusually high error rate detected. Check Error Logs.",'tenant_id'=>null];
        }
    } catch (Exception $e) {}

    // 4. New signups today
    $newToday = $pdo->query("SELECT COUNT(*) FROM tenants WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    if ($newToday > 0) {
        $alerts[] = ['type'=>'new_signup','level'=>'info','title'=>"{$newToday} new signup(s) today",'body'=>"New tenant(s) registered today",'tenant_id'=>null];
    }

    // 5. Tenants with no orders in 14+ days (churn risk)
    $dormant = $pdo->query("SELECT t.id,t.name FROM tenants t WHERE t.is_active=1 AND t.plan!='free' AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.tenant_id=t.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)) LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dormant as $t) {
        $alerts[] = ['type'=>'churn_risk','level'=>'warning','title'=>"Churn risk: {$t['name']}",'body'=>"No orders in 14+ days. Consider reaching out.",'tenant_id'=>$t['id']];
    }

    return $alerts;
}

/* ── LIST notifications ── */
if ($action === 'list') {
    if (!isAdmin()) fail('Unauthorized');
    $limit = min((int)($_GET['limit'] ?? 50), 100);

    // Auto-save new alerts (dedup by type+tenant_id today)
    $fresh = generateAlerts($pdo);
    foreach ($fresh as $a) {
        $dup = $pdo->prepare("SELECT id FROM admin_notifications WHERE type=? AND (tenant_id=? OR (tenant_id IS NULL AND ? IS NULL)) AND DATE(created_at)=CURDATE()");
        $dup->execute([$a['type'], $a['tenant_id'], $a['tenant_id']]);
        if (!$dup->fetchColumn()) {
            $pdo->prepare("INSERT INTO admin_notifications (type,level,title,body,tenant_id) VALUES (?,?,?,?,?)")
                ->execute([$a['type'],$a['level'],$a['title'],$a['body'],$a['tenant_id']]);
        }
    }

    $rows = $pdo->prepare("SELECT * FROM admin_notifications ORDER BY is_read ASC, created_at DESC LIMIT ?");
    $rows->execute([$limit]);
    $notifications = $rows->fetchAll(PDO::FETCH_ASSOC);
    $unread = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0")->fetchColumn();
    ok(['notifications'=>$notifications, 'unread'=>(int)$unread]);
}

/* ── MARK READ ── */
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdmin()) fail('Unauthorized');
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($b['id'] ?? 0);
    if ($id) {
        $pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE id=?")->execute([$id]);
    } else {
        $pdo->exec("UPDATE admin_notifications SET is_read=1");
    }
    ok(['msg'=>'Marked read']);
}

/* ── DELETE ── */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdmin()) fail('Unauthorized');
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($b['id'] ?? 0);
    if ($id) $pdo->prepare("DELETE FROM admin_notifications WHERE id=?")->execute([$id]);
    else $pdo->exec("DELETE FROM admin_notifications WHERE is_read=1");
    ok(['msg'=>'Deleted']);
}

/* ── UNREAD COUNT (for badge) ── */
if ($action === 'unread_count') {
    if (!isAdmin()) ok(['count'=>0]);
    $count = $pdo->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0")->fetchColumn();
    ok(['count'=>(int)$count]);
}

fail('Unknown action');
