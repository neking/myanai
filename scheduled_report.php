<?php
/**
 * Scheduled Daily Report
 * Run via cron: 0 8 * * * php /var/www/myanai/scheduled_report.php
 * Or trigger manually: ?action=send&tenant_id=X&secret=KEY
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mailer.php';

$pdo    = getPDO();
$action = $_GET['action'] ?? 'cron';
$secret = $_GET['secret'] ?? '';

// Security: allow cron (CLI) or secret key
$isCLI    = php_sapi_name() === 'cli';
$reportSecret = getenv('MYANAI_REPORT_SECRET') ?: 'myanai_report_2026';
$validKey = hash_equals($reportSecret, $secret);
if (!$isCLI && !$validKey) {
    http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

// ── BUILD REPORT ──
function buildDailyReport(PDO $pdo, int $tid, string $date): array {
    $yesterday = $date;

    $summary = $pdo->prepare("
        SELECT
            COUNT(*)                                                          AS total_orders,
            COALESCE(SUM(CASE WHEN status!='cancelled' THEN total_amount END),0) AS revenue,
            COUNT(CASE WHEN status='cancelled' THEN 1 END)                   AS cancelled,
            COALESCE(AVG(CASE WHEN status!='cancelled' THEN total_amount END),0) AS avg_order,
            COUNT(CASE WHEN order_type='dine_in' THEN 1 END)                 AS dine_in,
            COUNT(CASE WHEN order_type!='dine_in' THEN 1 END)                AS delivery
        FROM orders
        WHERE tenant_id=? AND DATE(created_at)=? AND deleted_at IS NULL
    ");
    $summary->execute([$tid, $yesterday]);
    $s = $summary->fetch(PDO::FETCH_ASSOC);

    $topItems = $pdo->prepare("
        SELECT oi.item_name, SUM(oi.qty) as qty, SUM(oi.qty*oi.unit_price) as revenue
        FROM order_items oi
        JOIN orders o ON o.id=oi.order_id
        WHERE o.tenant_id=? AND DATE(o.created_at)=? AND o.deleted_at IS NULL AND o.status!='cancelled'
        GROUP BY oi.item_name ORDER BY qty DESC LIMIT 5
    ");
    $topItems->execute([$tid, $yesterday]);
    $items = $topItems->fetchAll(PDO::FETCH_ASSOC);

    $payments = $pdo->prepare("
        SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total
        FROM orders WHERE tenant_id=? AND DATE(created_at)=? AND deleted_at IS NULL AND status!='cancelled'
        GROUP BY payment_method ORDER BY cnt DESC
    ");
    $payments->execute([$tid, $yesterday]);
    $pays = $payments->fetchAll(PDO::FETCH_ASSOC);

    return ['summary'=>$s, 'top_items'=>$items, 'payments'=>$pays, 'date'=>$date];
}

// ── BUILD HTML EMAIL ──
function buildReportHTML(array $data, string $tenantName): string {
    $s    = $data['summary'];
    $date = date('d M Y', strtotime($data['date']));
    $fmt  = fn($n) => number_format((int)$n);

    $itemsHTML = '';
    foreach ($data['top_items'] as $i => $item) {
        $medals = ['🥇','🥈','🥉','4️⃣','5️⃣'];
        $itemsHTML .= "<tr>
            <td style='padding:6px 8px'>{$medals[$i]} ".htmlspecialchars($item['item_name'])."</td>
            <td style='padding:6px 8px;text-align:right'>{$item['qty']}x</td>
            <td style='padding:6px 8px;text-align:right'>".$fmt($item['revenue'])." Ks</td>
        </tr>";
    }

    $paysHTML = '';
    foreach ($data['payments'] as $p) {
        $paysHTML .= "<tr>
            <td style='padding:4px 8px'>".strtoupper($p['payment_method'])."</td>
            <td style='padding:4px 8px;text-align:right'>{$p['cnt']} orders</td>
            <td style='padding:4px 8px;text-align:right'>".$fmt($p['total'])." Ks</td>
        </tr>";
    }

    $noOrders = (int)$s['total_orders'] === 0;

    return "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#f9fafb;padding:20px'>
      <div style='background:#E8593C;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0'>
        <h1 style='margin:0;font-size:22px'>📊 Daily Report</h1>
        <p style='margin:4px 0 0;opacity:.85;font-size:14px'>{$tenantName} — {$date}</p>
      </div>
      <div style='background:#fff;padding:20px 24px;border-radius:0 0 12px 12px;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
        ".($noOrders ? "<p style='text-align:center;color:#888;padding:20px'>No orders yesterday.</p>" : "
        <div style='display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px'>
          <div style='background:#f0fdf4;border-radius:8px;padding:12px;text-align:center'>
            <div style='font-size:24px;font-weight:700;color:#16a34a'>".$fmt($s['revenue'])."</div>
            <div style='font-size:12px;color:#666'>Revenue (Ks)</div>
          </div>
          <div style='background:#eff6ff;border-radius:8px;padding:12px;text-align:center'>
            <div style='font-size:24px;font-weight:700;color:#2563eb'>{$s['total_orders']}</div>
            <div style='font-size:12px;color:#666'>Orders</div>
          </div>
          <div style='background:#fefce8;border-radius:8px;padding:12px;text-align:center'>
            <div style='font-size:24px;font-weight:700;color:#ca8a04'>".$fmt($s['avg_order'])."</div>
            <div style='font-size:12px;color:#666'>Avg Order (Ks)</div>
          </div>
        </div>
        <div style='margin-bottom:6px;font-size:12px;color:#666'>🍽️ Dine-in: <strong>{$s['dine_in']}</strong> &nbsp;|&nbsp; 🛵 Delivery: <strong>{$s['delivery']}</strong> &nbsp;|&nbsp; ❌ Cancelled: <strong>{$s['cancelled']}</strong></div>
        ".($itemsHTML ? "
        <h3 style='font-size:14px;font-weight:600;margin:16px 0 8px'>Top Items</h3>
        <table style='width:100%;border-collapse:collapse;font-size:13px'>
          <thead><tr style='background:#f3f4f6'><th style='padding:6px 8px;text-align:left'>Item</th><th style='padding:6px 8px;text-align:right'>Qty</th><th style='padding:6px 8px;text-align:right'>Revenue</th></tr></thead>
          <tbody>{$itemsHTML}</tbody>
        </table>" : "")."
        ".($paysHTML ? "
        <h3 style='font-size:14px;font-weight:600;margin:16px 0 8px'>Payments</h3>
        <table style='width:100%;border-collapse:collapse;font-size:13px'>
          <tbody>{$paysHTML}</tbody>
        </table>" : "")."")."
        <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0'>
        <p style='font-size:12px;color:#9ca3af;text-align:center'>
          Sent by <a href='https://myanai.net' style='color:#E8593C'>MyanAi POS</a> · 
          <a href='https://myanai.net/tenant.php' style='color:#E8593C'>Login to dashboard</a>
        </p>
      </div>
    </div>";
}

// ── SEND REPORT TO ONE TENANT ──
function sendReportToTenant(PDO $pdo, array $tenant, string $date): bool {
    // Check if reports enabled in tenant settings
    $settings = json_decode($tenant['settings'] ?? '{}', true);
    if (($settings['daily_report_enabled'] ?? '1') === '0') return false;

    $data     = buildDailyReport($pdo, (int)$tenant['id'], $date);
    $html     = buildReportHTML($data, $tenant['name']);
    $subject  = "📊 Daily Report — {$tenant['name']} (" . date('d M', strtotime($date)) . ")";

    try {
        $mail = getMailer();
        $mail->addAddress($tenant['owner_email'], $tenant['owner_name']);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->send();
        error_log("Daily report sent to {$tenant['owner_email']} for {$tenant['name']}");
        return true;
    } catch (Exception $e) {
        error_log("Report email failed for {$tenant['name']}: " . $e->getMessage());
        return false;
    }
}

// ── SEND FOR SPECIFIC TENANT ──
if ($action === 'send') {
    $tid  = (int)($_GET['tenant_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
    if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

    $t = $pdo->prepare("SELECT * FROM tenants WHERE id=? AND is_active=1");
    $t->execute([$tid]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) { echo json_encode(['ok'=>false,'msg'=>'Tenant not found']); exit; }

    $sent = sendReportToTenant($pdo, $tenant, $date);
    echo json_encode(['ok'=>true,'sent'=>$sent,'date'=>$date,'tenant'=>$tenant['name']]);
    exit;
}

// ── CRON: SEND ALL ACTIVE TENANTS ──
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tenants = $pdo->query("SELECT * FROM tenants WHERE is_active=1 AND plan != 'free'")->fetchAll(PDO::FETCH_ASSOC);

$sent = 0; $failed = 0;
foreach ($tenants as $tenant) {
    if (sendReportToTenant($pdo, $tenant, $yesterday)) $sent++;
    else $failed++;
    usleep(200000); // 200ms delay between emails
}

$msg = "Daily reports: {$sent} sent, {$failed} failed for {$yesterday}";
error_log($msg);
if (!$isCLI) echo json_encode(['ok'=>true,'msg'=>$msg,'sent'=>$sent,'failed'=>$failed]);
else echo $msg . PHP_EOL;
