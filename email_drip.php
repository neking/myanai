<?php
/**
 * Email Drip Campaign System
 * Sends onboarding emails to new tenants after signup
 * 
 * Day 0: Welcome (sent immediately via tenant_api.php)
 * Day 1: Getting started tips
 * Day 3: Feature spotlight (menu modifiers, loyalty)
 * Day 7: Check-in + support offer
 * Day 14: Trial ending reminder (if free plan)
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mailer.php';

$pdo    = getPDO();
$isCLI  = php_sapi_name() === 'cli';
$secret = $_GET['secret'] ?? '';
$action = $_GET['action'] ?? 'cron';

$dripSecret = getenv('MYANAI_DRIP_SECRET') ?: 'myanai_drip_2026';
if (!$isCLI && !hash_equals($dripSecret, $secret)) {
    http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

// ── EMAIL TEMPLATES ──
function dripTemplate(string $title, string $body, string $cta_text, string $cta_url): string {
    return "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto'>
      <div style='background:#E8593C;color:#fff;padding:24px;border-radius:12px 12px 0 0;text-align:center'>
        <div style='font-size:28px'>🍜</div>
        <h1 style='margin:8px 0 0;font-size:20px'>MyanAi POS</h1>
      </div>
      <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
        <h2 style='font-size:18px;color:#1f2937;margin:0 0 12px'>{$title}</h2>
        {$body}
        <div style='text-align:center;margin:24px 0'>
          <a href='{$cta_url}' style='background:#E8593C;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block'>{$cta_text}</a>
        </div>
        <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0'>
        <p style='font-size:12px;color:#9ca3af;text-align:center'>
          MyanAi POS · <a href='https://myanai.net' style='color:#E8593C'>myanai.net</a>
        </p>
      </div>
    </div>";
}

$DRIP_SEQUENCES = [
    1 => [
        'subject' => '🚀 Getting started with MyanAi POS',
        'fn' => function(array $tenant): string {
            $slug = $tenant['slug'];
            return dripTemplate(
                'Ready to take your first order?',
                "<p style='color:#374151'>Hi {$tenant['owner_name']},</p>
                <p style='color:#374151'>Your MyanAi POS is all set up. Here's what to do first:</p>
                <ol style='color:#374151;line-height:1.8'>
                  <li><strong>Add your menu items</strong> — photos, prices, categories</li>
                  <li><strong>Set up your tables</strong> — for dine-in orders</li>
                  <li><strong>Add your staff</strong> — create PINs for your team</li>
                  <li><strong>Take a test order</strong> — on the POS terminal</li>
                </ol>
                <p style='color:#6b7280;font-size:13px'>Need help? Reply to this email anytime.</p>",
                '→ Open Dashboard',
                "https://myanai.net/tenant.php"
            );
        }
    ],
    3 => [
        'subject' => '💡 Pro tip: Boost your revenue with these features',
        'fn' => function(array $tenant): string {
            return dripTemplate(
                '3 features most businesses miss',
                "<p style='color:#374151'>Hi {$tenant['owner_name']},</p>
                <div style='background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0'>
                  <strong>⭐ Loyalty Stamps</strong><br>
                  <span style='font-size:13px;color:#92400e'>Customers collect stamps → get free rewards. Enable in Settings → Loyalty.</span>
                </div>
                <div style='background:#eff6ff;border-left:4px solid #3b82f6;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0'>
                  <strong>🎛️ Menu Modifiers</strong><br>
                  <span style='font-size:13px;color:#1e40af'>Add options like size, spice level, extras. Customers choose when ordering.</span>
                </div>
                <div style='background:#f0fdf4;border-left:4px solid #22c55e;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0'>
                  <strong>🎫 Promotions</strong><br>
                  <span style='font-size:13px;color:#15803d'>Happy hour discounts, promo codes, category deals. Set up in Promotions.</span>
                </div>",
                '→ Explore Features',
                "https://myanai.net/tenant.php"
            );
        }
    ],
    7 => [
        'subject' => '👋 How\'s MyanAi POS working for you?',
        'fn' => function(array $tenant): string {
            return dripTemplate(
                'One week in — how are things going?',
                "<p style='color:#374151'>Hi {$tenant['owner_name']},</p>
                <p style='color:#374151'>You've been using MyanAi POS for a week now. We'd love to know:</p>
                <ul style='color:#374151;line-height:1.8'>
                  <li>Is everything working smoothly?</li>
                  <li>Any features you wish we had?</li>
                  <li>Any issues with your orders or reports?</li>
                </ul>
                <p style='color:#374151'>Just reply to this email — we read every response personally.</p>
                <p style='color:#6b7280;font-size:13px'>Also: check your <strong>Reports</strong> section for your weekly summary!</p>",
                '→ View My Reports',
                "https://myanai.net/tenant.php"
            );
        }
    ],
    14 => [
        'subject' => '⏰ Your free trial ends soon — upgrade for full access',
        'fn' => function(array $tenant): string {
            return dripTemplate(
                'Your 14-day trial is ending',
                "<p style='color:#374151'>Hi {$tenant['owner_name']},</p>
                <p style='color:#374151'>Your free trial ends in a few days. Here's what you'll keep with a paid plan:</p>
                <ul style='color:#374151;line-height:1.8'>
                  <li>✅ Unlimited orders & customers</li>
                  <li>✅ Advanced reports & analytics</li>
                  <li>✅ Multiple branches</li>
                  <li>✅ Daily email reports</li>
                  <li>✅ Priority support</li>
                </ul>
                <p style='color:#6b7280;font-size:13px'>Plans start from just 10,000 MMK/month. No hidden fees.</p>",
                '→ Upgrade Now',
                "https://myanai.net/tenant.php#upgrade"
            );
        }
    ],
];

// ── SEND DRIP FOR TENANT ──
function sendDrip(PDO $pdo, array $tenant, int $day, array $sequence): bool {
    $tplFn  = $sequence['fn'];
    $html   = $tplFn($tenant);
    $subject = $sequence['subject'];

    try {
        $mail = getMailer();
        $mail->addAddress($tenant['owner_email'], $tenant['owner_name']);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->send();

        // Record sent
        $pdo->prepare("
            INSERT INTO email_drip_log (tenant_id, day_sequence, subject, sent_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE sent_at=NOW()
        ")->execute([$tenant['id'], $day, $subject]);

        error_log("Drip day{$day} sent to {$tenant['owner_email']}");
        return true;
    } catch (Exception $e) {
        error_log("Drip email failed: " . $e->getMessage());
        return false;
    }
}

// ── CRON: CHECK AND SEND ──
global $DRIP_SEQUENCES;

$sent = 0;
$today = date('Y-m-d');

foreach ($DRIP_SEQUENCES as $day => $sequence) {
    // Find tenants who signed up exactly $day days ago and haven't received this drip
    $tenants = $pdo->prepare("
        SELECT t.* FROM tenants t
        WHERE t.is_active = 1
        AND DATE(t.created_at) = DATE_SUB(CURDATE(), INTERVAL ? DAY)
        AND NOT EXISTS (
            SELECT 1 FROM email_drip_log edl
            WHERE edl.tenant_id = t.id AND edl.day_sequence = ?
        )
    ");
    $tenants->execute([$day, $day]);
    $batch = $tenants->fetchAll(PDO::FETCH_ASSOC);

    foreach ($batch as $tenant) {
        if (sendDrip($pdo, $tenant, $day, $sequence)) $sent++;
    }
}

$msg = "Drip campaigns: {$sent} emails sent for {$today}";
error_log($msg);
if (!$isCLI) echo json_encode(['ok'=>true,'msg'=>$msg,'sent'=>$sent]);
else echo $msg . PHP_EOL;
