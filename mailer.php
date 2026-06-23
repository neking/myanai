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

/**
 * ── ONBOARDING EMAILS ──
 */
function welcomeOnboardingEmail(string $tenantName, string $slug, string $email, string $password = ''): string {
    $verifyLink = "https://myanai.net/verify-email.php?slug={$slug}&token=" . urlencode(base64_encode($email));
    $loginLink = "https://myanai.net/admin.php";
    
    return "
<!DOCTYPE html>
<html>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
</head>
<body style='font-family:\"Segoe UI\",Tahoma,sans-serif;background:#f5f5f5;color:#333;line-height:1.6'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.08)'>
  
  <!-- Header -->
  <div style='background:linear-gradient(135deg,#e84c2b 0%,#ff6b4a 100%);padding:2rem;text-align:center;color:#fff'>
    <div style='font-size:2.5rem;margin-bottom:.5rem'>🤖</div>
    <div style='font-size:1.8rem;font-weight:700;margin-bottom:.3rem'>MyanAi POS</div>
    <div style='font-size:.9rem;opacity:.9'>Myanmar's AI-Powered Platform</div>
  </div>
  
  <!-- Content -->
  <div style='padding:2rem'>
    <h2 style='color:#2a2520;margin-bottom:.5rem'>🎉 Welcome, {$tenantName}!</h2>
    <p>Your MyanAi account has been successfully created and is ready to use.</p>
    
    <div style='background:#f0f9ff;border-left:4px solid #0ea5e9;border-radius:8px;padding:1rem;margin:1.5rem 0'>
      <h3 style='color:#0ea5e9;margin-top:0;font-size:.95rem'>🚀 Quick Setup (2 minutes)</h3>
      <ol style='margin:.5rem 0;padding-left:1.2rem;font-size:.9rem'>
        <li>Login to your dashboard</li>
        <li>Add your menu items</li>
        <li>Create staff accounts</li>
        <li>Start selling! 📊</li>
      </ol>
    </div>
    
    <table style='width:100%;border-collapse:collapse;margin:1.5rem 0;font-size:.95rem'>
      <tr style='border-bottom:1px solid #eee'>
        <td style='padding:.5rem 0;color:#888'>Business URL:</td>
        <td style='padding:.5rem 0;font-weight:600'>{$slug}</td>
      </tr>
      <tr style='border-bottom:1px solid #eee'>
        <td style='padding:.5rem 0;color:#888'>Login Email:</td>
        <td style='padding:.5rem 0;font-weight:600'>{$email}</td>
      </tr>
      <tr>
        <td style='padding:.5rem 0;color:#888'>Password:</td>
        <td style='padding:.5rem 0;font-weight:600'>Securely set during signup ✓</td>
      </tr>
    </table>
    
    <div style='text-align:center;margin:2rem 0'>
      <a href='{$loginLink}' style='display:inline-block;background:linear-gradient(135deg,#e84c2b 0%,#ff6b4a 100%);color:#fff;padding:.9rem 2rem;border-radius:10px;text-decoration:none;font-weight:600;font-size:.95rem'>
        ➜ Go to Admin Dashboard
      </a>
    </div>
    
    <div style='background:#fff9e6;border-left:4px solid #f59e0b;border-radius:8px;padding:1rem;margin:1.5rem 0'>
      <p style='margin:0;font-size:.9rem'><strong>💡 Pro Tip:</strong> Download our mobile app or use tablet mode for POS features.</p>
    </div>
    
    <hr style='border:none;border-top:1px solid #eee;margin:2rem 0'>
    
    <h3 style='color:#2a2520;font-size:.95rem;margin-bottom:.5rem'>Getting Started Links</h3>
    <ul style='list-style:none;padding:0;margin:0;font-size:.9rem'>
      <li style='margin:.3rem 0'><a href='https://myanai.net/docs' style='color:#e84c2b;text-decoration:none'>📖 Full Documentation</a></li>
      <li style='margin:.3rem 0'><a href='https://myanai.net/video-guide' style='color:#e84c2b;text-decoration:none'>🎥 Video Tutorial (2 min)</a></li>
      <li style='margin:.3rem 0'><a href='mailto:support@myanai.net' style='color:#e84c2b;text-decoration:none'>💬 Contact Support</a></li>
      <li style='margin:.3rem 0'><a href='https://myanai.net/faq' style='color:#e84c2b;text-decoration:none'>❓ FAQ</a></li>
    </ul>
    
    <div style='background:#f9f3ff;border-radius:8px;padding:1rem;margin:1.5rem 0;font-size:.85rem'>
      <strong style='color:#7c3aed'>🎁 Your Free Trial:</strong>
      <p style='margin:.3rem 0;color:#666'>14 days unlimited access. No credit card required. Upgrade anytime.</p>
    </div>
    
    <p style='color:#888;font-size:.85rem;margin-top:2rem;text-align:center'>
      Questions? Reply to this email or visit <a href='https://myanai.net' style='color:#e84c2b;text-decoration:none'>myanai.net</a>
    </p>
  </div>
  
  <!-- Footer -->
  <div style='background:#f5f5f5;padding:1.5rem;text-align:center;border-top:1px solid #eee;font-size:.8rem;color:#888'>
    <p style='margin:0'>© 2024 MyanAi. All rights reserved.</p>
    <p style='margin:.3rem 0'>If you didn't create this account, please contact us immediately.</p>
  </div>
  
</div>
</body>
</html>";
}

/**
 * Welcome email (simplified version for sending immediately)
 */
function sendWelcomeEmail(string $to, string $tenantName, string $slug): bool {
    $subject = "🎉 Welcome to MyanAi — Your POS System is Ready!";
    $body = welcomeOnboardingEmail($tenantName, $slug, $to);
    return sendMail($to, $subject, $body);
}

/**
 * Verification email
 */
function sendVerificationEmail(string $to, string $tenantName, string $verifyLink): bool {
    $subject = "Verify Your MyanAi Email Address";
    $body = "
<!DOCTYPE html>
<html>
<body style='font-family:sans-serif;background:#f5f5f5;padding:2rem'>
<div style='max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.1)'>
  <h2 style='color:#e84c2b;text-align:center;margin-bottom:1.5rem'>📧 Verify Your Email</h2>
  <p>Hi <strong>{$tenantName}</strong>,</p>
  <p>Click the button below to verify your email address:</p>
  <div style='text-align:center;margin:2rem 0'>
    <a href='{$verifyLink}' style='background:#e84c2b;color:#fff;padding:.9rem 2rem;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block'>✓ Verify Email Address</a>
  </div>
  <p style='color:#888;font-size:.9rem;text-align:center'>Or copy this link: <br><span style='word-break:break-all;font-family:monospace;font-size:.8rem'>{$verifyLink}</span></p>
</div>
</body>
</html>";
    return sendMail($to, $subject, $body);
}
