<?php
require_once __DIR__ . '/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
function ok($d=[]){ echo json_encode(['ok'=>true]+$d); exit; }
function fail($m){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }

if (empty($_SESSION['admin'])) {
    fail('Unauthorized');
}

$months = max(3, min(12, (int)($_GET['months'] ?? 6)));

/* ── MRR by month ── */
$mrr = $pdo->query("
    SELECT 
        DATE_FORMAT(t.created_at, '%Y-%m') as month,
        COUNT(*) as new_tenants,
        SUM(CASE WHEN p.price_mmk IS NOT NULL THEN p.price_mmk ELSE 0 END) as mrr_mmk
    FROM tenants t
    LEFT JOIN saas_plans p ON p.code = t.plan
    WHERE t.is_active=1 AND t.created_at >= DATE_SUB(NOW(), INTERVAL {$months} MONTH)
    GROUP BY month ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Plan distribution ── */
$plans = $pdo->query("
    SELECT t.plan, COUNT(*) as count, SUM(COALESCE(p.price_mmk,0)) as mrr
    FROM tenants t
    LEFT JOIN saas_plans p ON p.code=t.plan
    WHERE t.is_active=1
    GROUP BY t.plan ORDER BY mrr DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Total MRR ── */
$totalMrr = $pdo->query("
    SELECT SUM(COALESCE(p.price_mmk,0)) 
    FROM tenants t LEFT JOIN saas_plans p ON p.code=t.plan 
    WHERE t.is_active=1
")->fetchColumn();

/* ── Churn: tenants deactivated this month ── */
$churn = $pdo->query("
    SELECT COUNT(*) FROM tenants 
    WHERE is_active=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetchColumn();

/* ── New signups last 30 days ── */
$newSignups = $pdo->query("
    SELECT COUNT(*) FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetchColumn();

/* ── Activation rate: tenants with orders / total active ── */
$totalActive = $pdo->query("SELECT COUNT(*) FROM tenants WHERE is_active=1")->fetchColumn();
$activated   = $pdo->query("SELECT COUNT(DISTINCT tenant_id) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$activationRate = $totalActive > 0 ? round($activated / $totalActive * 100) : 0;

/* ── Weekly signups trend (last 8 weeks) ── */
$weekly = $pdo->query("
    SELECT 
        YEARWEEK(created_at,1) as yw,
        DATE_FORMAT(MIN(created_at),'%m/%d') as week_start,
        COUNT(*) as signups
    FROM tenants
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
    GROUP BY yw ORDER BY yw
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Top active tenants (by orders last 30d) ── */
$topTenants = $pdo->query("
    SELECT t.name, t.plan, COUNT(o.id) as orders, COALESCE(SUM(o.total_amount),0) as revenue
    FROM tenants t
    LEFT JOIN orders o ON o.tenant_id=t.id AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    WHERE t.is_active=1
    GROUP BY t.id ORDER BY orders DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

ok([
    'summary' => [
        'total_mrr'       => (float)$totalMrr,
        'total_active'    => (int)$totalActive,
        'new_signups_30d' => (int)$newSignups,
        'churn_30d'       => (int)$churn,
        'activation_rate' => $activationRate,
        'activated_30d'   => (int)$activated,
    ],
    'mrr_trend'   => $mrr,
    'plan_dist'   => $plans,
    'weekly'      => $weekly,
    'top_tenants' => $topTenants,
]);
