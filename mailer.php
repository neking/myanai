<?php
/**
 * mailer.php — Simple email helper using PHP mail()
 * Usage: require_once 'mailer.php'; sendMail($to, $subject, $body);
 */
declare(strict_types=1);

function sendMail(string $to, string $subject, string $htmlBody, string $from = 'noreply@myanai.net'): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: MyanAi Platform <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $result = @mail($to, $subject, $htmlBody, $headers);
    if(!$result) error_log("MyanAi mailer failed: to={$to} subject={$subject}");
    return $result;
}

function upgradeApprovedEmail(string $to, string $tenantName, string $plan): string {
    $planNames = ['free'=>'Free','basic'=>'Basic','pro'=>'Pro','enterprise'=>'Enterprise'];
    $planName  = $planNames[$plan] ?? strtoupper($plan);
    return "
<!DOCTYPE html><html><body style='font-family:sans-serif;background:#f5f5f5;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden'>
  <div style='background:#6366f1;padding:1.5rem 2rem;text-align:center'>
    <div style='font-size:1.5rem;color:#fff;font-weight:700'>🤖 MyanAi</div>
  </div>
  <div style='padding:2rem'>
    <h2 style='color:#1a1a2e;margin-bottom:.5rem'>Plan Upgrade Approved! 🎉</h2>
    <p>Hi <strong>{$tenantName}</strong>,</p>
    <p>Your plan has been upgraded to <strong style='color:#6366f1'>{$planName}</strong> plan.</p>
    <div style='background:#f0f0ff;border-radius:8px;padding:1rem;margin:1rem 0;font-size:.9rem'>
      <strong>✓</strong> More branches, staff, and menu items unlocked<br>
      <strong>✓</strong> Priority support enabled<br>
      <strong>✓</strong> Valid for 1 year from today
    </div>
    <a href='https://myanai.duckdns.org/tenant.php' style='display:inline-block;background:#6366f1;color:#fff;padding:.75rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600'>Login to your portal →</a>
    <p style='color:#888;font-size:.82rem;margin-top:1.5rem'>MyanAi — Myanmar AI Products Platform</p>
  </div>
</div>
</body></html>";
}

function upgradeRejectedEmail(string $to, string $tenantName, string $plan): string {
    return "
<!DOCTYPE html><html><body style='font-family:sans-serif;background:#f5f5f5;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden'>
  <div style='background:#6366f1;padding:1.5rem 2rem;text-align:center'>
    <div style='font-size:1.5rem;color:#fff;font-weight:700'>🤖 MyanAi</div>
  </div>
  <div style='padding:2rem'>
    <h2>Upgrade Request Update</h2>
    <p>Hi <strong>{$tenantName}</strong>,</p>
    <p>Your request to upgrade to <strong>{$plan}</strong> could not be processed at this time.</p>
    <p>Please contact us for more information or try again.</p>
    <a href='https://myanai.duckdns.org/tenant.php' style='display:inline-block;background:#6366f1;color:#fff;padding:.75rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600'>Contact support →</a>
  </div>
</div>
</body></html>";
}
