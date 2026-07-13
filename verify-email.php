<?php
/**
 * Email Verification Handler
 * GET: verify-email.php?slug=...&token=...
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

$slug = trim($_GET['slug'] ?? '');
$token = trim($_GET['token'] ?? '');
$verified = false;
$message = '';

if (!$slug || !$token) {
    $message = '❌ Invalid verification link';
} else {
    try {
        $pdo = getPDO();

        // Find tenant by slug
        $stmt = $pdo->prepare("SELECT id, owner_email, settings FROM tenants WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tenant) {
            throw new Exception('Tenant not found');
        }

        $settings = json_decode($tenant['settings'] ?? '{}', true) ?: [];
        $storedToken = $settings['email_verify_token'] ?? '';

        // Compare against the random token generated at signup time — NOT a
        // decoded email. Previously the "token" was just base64(email), so
        // anyone who knew a tenant's slug and email (often publicly visible
        // on their own storefront) could construct a valid-looking link
        // themselves without ever receiving the actual email. hash_equals()
        // for timing-safe comparison.
        if (!$storedToken || !hash_equals($storedToken, $token)) {
            throw new Exception('Invalid or expired verification token');
        }

        $settings['email_verified'] = true;
        $settings['verified_at'] = date('Y-m-d H:i:s');
        unset($settings['email_verify_token']); // one-time use

        $pdo->prepare("UPDATE tenants SET settings = ? WHERE id = ?")
            ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $tenant['id']]);

        $verified = true;
        $message = '✅ Email verified successfully!';
    } catch (Exception $e) {
        $message = '❌ Verification failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MyanAi — Email Verification</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Segoe UI', Tahoma, sans-serif;
      background: linear-gradient(135deg, #f5f3f0 0%, #faf8f5 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }
    .card {
      background: #fff;
      border-radius: 16px;
      padding: 3rem 2rem;
      max-width: 520px;
      width: 100%;
      text-align: center;
      box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
    }
    .icon {
      font-size: 3rem;
      margin-bottom: 1rem;
    }
    h1 {
      font-size: 1.8rem;
      color: #2a2520;
      margin-bottom: 0.5rem;
    }
    .message {
      font-size: 1.1rem;
      color: #2a2520;
      margin: 1.5rem 0;
      line-height: 1.6;
    }
    .status {
      background: #f0f9ff;
      border-left: 4px solid #0ea5e9;
      border-radius: 8px;
      padding: 1rem;
      margin: 1.5rem 0;
      font-weight: 500;
    }
    .status.success {
      background: #eafaf0;
      border-left-color: #27ae60;
      color: #27ae60;
    }
    .status.error {
      background: #fdeaea;
      border-left-color: #e74c3c;
      color: #e74c3c;
    }
    .btn {
      display: inline-block;
      margin-top: 1.5rem;
      padding: 0.9rem 2rem;
      background: linear-gradient(135deg, #e84c2b, #ff6b4a);
      color: #fff;
      border: none;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      font-size: 1rem;
    }
    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(232, 76, 43, 0.3);
    }
    .next-steps {
      background: #f9f7f4;
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 2rem;
      text-align: left;
    }
    .next-steps h3 {
      color: #2a2520;
      margin-bottom: 1rem;
    }
    .next-steps ol {
      margin-left: 1.5rem;
      color: #888;
      line-height: 1.8;
    }
    .next-steps li {
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body>

<div class="card">
  <div class="icon"><?php echo $verified ? '✅' : '❌'; ?></div>
  
  <h1><?php echo $verified ? 'Email Verified!' : 'Verification Failed'; ?></h1>
  
  <p class="message"><?php echo htmlspecialchars($message); ?></p>
  
  <div class="status <?php echo $verified ? 'success' : 'error'; ?>">
    <?php if ($verified): ?>
      Your email has been confirmed. You're all set to start using MyanAi!
    <?php else: ?>
      There was an issue verifying your email. Please try again or contact support.
    <?php endif; ?>
  </div>
  
  <?php if ($verified): ?>
    <div class="next-steps">
      <h3>🚀 What's Next?</h3>
      <ol>
        <li>Log in to your <a href="/admin.php" style="color: #e84c2b; text-decoration: none;">admin panel</a></li>
        <li>Add your menu items</li>
        <li>Create staff accounts</li>
        <li>Start processing orders</li>
      </ol>
    </div>
    
    <a href="/admin.php" class="btn">Go to Dashboard →</a>
  <?php else: ?>
    <div class="next-steps">
      <h3>❓ What Can You Do?</h3>
      <ol>
        <li><a href="/signup.html" style="color: #e84c2b; text-decoration: none;">Create a new account</a></li>
        <li><a href="mailto:support@myanai.net" style="color: #e84c2b; text-decoration: none;">Contact support</a></li>
        <li>Check your email for a new verification link</li>
      </ol>
    </div>
    
    <a href="/signup.html" class="btn">Try Again →</a>
  <?php endif; ?>
</div>

</body>
</html>
