<?php
/**
 * Two-Factor Authentication for Admin
 * Uses Time-based OTP (TOTP) compatible with Google Authenticator
 * Fallback: Email OTP
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mailer.php';

class TwoFactor {

    // ── TOTP Generation (Google Authenticator compatible) ──
    public static function generateSecret(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    public static function getTOTP(string $secret, int $offset = 0): string {
        $time  = floor(time() / 30) + $offset;
        $key   = self::base32Decode($secret);
        $msg   = pack('N*', 0, $time);
        $hash  = hash_hmac('SHA1', $msg, $key, true);
        $off   = ord($hash[19]) & 0x0f;
        $code  = (
            (ord($hash[$off]) & 0x7f) << 24 |
            (ord($hash[$off+1]) & 0xff) << 16 |
            (ord($hash[$off+2]) & 0xff) << 8 |
            (ord($hash[$off+3]) & 0xff)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    public static function verifyTOTP(string $secret, string $userCode): bool {
        // Check current and adjacent windows (±30s tolerance)
        for ($i = -1; $i <= 1; $i++) {
            if (self::getTOTP($secret, $i) === $userCode) return true;
        }
        return false;
    }

    public static function getQRUrl(string $secret, string $email, string $issuer = 'MyanAi POS'): string {
        $label  = urlencode("{$issuer}:{$email}");
        $params = "secret={$secret}&issuer=".urlencode($issuer)."&algorithm=SHA1&digits=6&period=30";
        $otpauth = urlencode("otpauth://totp/{$label}?{$params}");
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$otpauth}";
    }

    private static function base32Decode(string $secret): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits  = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }

    // ── Email OTP Fallback ──
    public static function sendEmailOTP(string $email, string $name): string {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $mail = getMailer();
            $mail->addAddress($email, $name);
            $mail->Subject = "MyanAi POS — Login OTP: {$otp}";
            $mail->isHTML(true);
            $mail->Body = "
            <div style='font-family:sans-serif;max-width:400px;margin:0 auto;text-align:center;padding:2rem'>
              <div style='font-size:2rem;margin-bottom:1rem'>🔐</div>
              <h2 style='color:#1f2937'>Admin Login Code</h2>
              <div style='font-size:2.5rem;font-weight:700;letter-spacing:.4rem;color:#E8593C;background:#fef2f2;padding:1rem;border-radius:8px;margin:1.5rem 0'>{$otp}</div>
              <p style='color:#6b7280;font-size:.9rem'>This code expires in 5 minutes.<br>Never share it with anyone.</p>
            </div>";
            $mail->send();
        } catch (Exception $e) {
            error_log("OTP email failed: " . $e->getMessage());
        }

        return $otp;
    }
}

// ── API Endpoints ──
session_start();
header('Content-Type: application/json');
$pdo    = getPDO();
$action = $_GET['action'] ?? '';

// ── SETUP 2FA ──
if ($action === 'setup' && !empty($_SESSION['admin'])) {
    $email  = $_SESSION['admin']['user'] ?? 'admin';
    $secret = TwoFactor::generateSecret();

    // Store in session temporarily until verified
    $_SESSION['2fa_setup_secret'] = $secret;

    echo json_encode([
        'ok'     => true,
        'secret' => $secret,
        'qr_url' => TwoFactor::getQRUrl($secret, $email),
        'instructions' => 'Scan QR with Google Authenticator or Authy, then verify below.',
    ]);
    exit;
}

// ── VERIFY AND ENABLE 2FA ──
if ($action === 'enable' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['admin'])) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = trim($data['code'] ?? '');

    if (!isset($_SESSION['2fa_setup_secret'])) {
        echo json_encode(['ok'=>false,'msg'=>'No setup in progress. Run setup first.']); exit;
    }

    $secret = $_SESSION['2fa_setup_secret'];
    if (!TwoFactor::verifyTOTP($secret, $code)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid code. Check your authenticator app.']); exit;
    }

    // Save to admin settings
    $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value, label)
        VALUES ('admin_2fa_secret', ?, '2FA Secret')
        ON DUPLICATE KEY UPDATE setting_value=?
    ")->execute([$secret, $secret]);

    $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value, label)
        VALUES ('admin_2fa_enabled', '1', '2FA Enabled')
        ON DUPLICATE KEY UPDATE setting_value='1'
    ")->execute();

    unset($_SESSION['2fa_setup_secret']);
    $_SESSION['2fa_verified'] = true;

    echo json_encode(['ok'=>true,'msg'=>'2FA enabled successfully!']);
    exit;
}

// ── VERIFY OTP (during login) ──
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $code   = trim($data['code'] ?? '');
    $method = $data['method'] ?? 'totp'; // totp or email

    if (empty($_SESSION['2fa_pending'])) {
        echo json_encode(['ok'=>false,'msg'=>'No login in progress']); exit;
    }

    $verified = false;

    if ($method === 'totp') {
        $stmt = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='admin_2fa_secret'");
        $secret = $stmt->fetchColumn();
        if ($secret) $verified = TwoFactor::verifyTOTP($secret, $code);
    } elseif ($method === 'email') {
        // Compare with stored OTP (expires 5 min)
        $stored = $_SESSION['2fa_email_otp'] ?? '';
        $storedAt = $_SESSION['2fa_email_otp_time'] ?? 0;
        if ($stored && $code === $stored && (time() - $storedAt) < 300) {
            $verified = true;
        }
    }

    if ($verified) {
        $_SESSION['2fa_verified'] = true;
        $_SESSION['2fa_pending']  = false;
        $_SESSION['admin'] = $_SESSION['2fa_admin_data'];
        unset($_SESSION['2fa_pending'], $_SESSION['2fa_admin_data'], $_SESSION['2fa_email_otp']);
        echo json_encode(['ok'=>true,'msg'=>'Verified! Logging in...']);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Invalid or expired code']);
    }
    exit;
}

// ── SEND EMAIL OTP ──
if ($action === 'send_otp' && !empty($_SESSION['2fa_pending'])) {
    $email = $_SESSION['2fa_admin_email'] ?? '';
    if (!$email) { echo json_encode(['ok'=>false,'msg'=>'No email on record']); exit; }

    $otp = TwoFactor::sendEmailOTP($email, 'Admin');
    $_SESSION['2fa_email_otp']      = $otp;
    $_SESSION['2fa_email_otp_time'] = time();

    echo json_encode(['ok'=>true,'msg'=>'OTP sent to '.$email]);
    exit;
}

// ── DISABLE 2FA ──
if ($action === 'disable' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['admin'])) {
    $pdo->query("UPDATE site_settings SET setting_value='0' WHERE setting_key='admin_2fa_enabled'");
    echo json_encode(['ok'=>true,'msg'=>'2FA disabled']);
    exit;
}

// ── STATUS ──
if ($action === 'status') {
    $enabled = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='admin_2fa_enabled'")->fetchColumn();
    echo json_encode(['ok'=>true,'enabled'=> $enabled === '1', 'verified'=> !empty($_SESSION['2fa_verified'])]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
