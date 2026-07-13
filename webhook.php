<?php
/**
 * MyanAi GitHub Webhook
 * Auto-deploy on push to main branch
 * GitHub → Settings → Webhooks → https://myanai.net/webhook.php
 *
 * SECURITY FIX: this file used to have the DB password hardcoded in plain
 * text right here, committed to git — meaning it lives in GitHub history
 * permanently (findable by anyone with repo access, even after this fix,
 * unless the history itself is rewritten/purged). Now reads from
 * db_connect.php's existing environment-variable-based config
 * (/etc/myanai.env) instead of duplicating a plaintext copy. The webhook
 * secret below is similarly read from an env var with the current value as
 * a fallback, so this keeps working even if MYANAI_WEBHOOK_SECRET isn't set
 * on the server yet — but it should be set, and the DB password that was
 * exposed here should be rotated.
 */
require_once __DIR__ . '/db_connect.php';

$secret = getenv('MYANAI_WEBHOOK_SECRET') ?: 'myanai_webhook_2026';
$sig    = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$body   = file_get_contents('php://input');
$logFile = '/tmp/webhook_myanai.log';

function wlog(string $msg): void {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ').$msg."\n", FILE_APPEND);
}

// Verify signature
if (!$sig) { http_response_code(403); die('no sig'); }
if (!hash_equals('sha256='.hash_hmac('sha256',$body,$secret), $sig)) {
    wlog("❌ Invalid signature");
    http_response_code(403); die('invalid signature');
}

$payload = json_decode($body, true);
$ref     = $payload['ref'] ?? '';
$pusher  = $payload['pusher']['name'] ?? 'unknown';
$commits = count($payload['commits'] ?? []);
$head    = $payload['head_commit']['message'] ?? '';

// Only deploy on main branch push
if ($ref !== 'refs/heads/main') {
    wlog("Skipped: ref={$ref}");
    echo 'skipped: not main'; exit;
}

wlog("🚀 Deploy triggered by {$pusher} ({$commits} commits: {$head})");

// Pull latest code
chdir('/var/www/myanai');
$home = posix_getpwuid(posix_getuid())['dir'] ?? '/var/www';
@file_put_contents($home.'/.gitconfig', "[safe]\n\tdirectory = /var/www/myanai\n");

$pull = shell_exec("HOME={$home} git pull origin main 2>&1");
wlog("Git pull: ".trim($pull));

// Fix permissions
shell_exec("chmod -R 755 /var/www/myanai 2>&1");

// Restart PHP-FPM (if sudo allowed)
$restart = shell_exec("sudo systemctl restart php8.3-fpm 2>&1");
wlog("PHP restart: ".trim($restart ?: 'done'));

// Check for new migrations and run them
$migrations = glob('/var/www/myanai/migrations/*.sql');
$dbUser = DB_USER;
$dbPass = DB_PASS;
$dbName = DB_NAME;
$dbHost = DB_HOST;

foreach ($migrations as $migration) {
    $fname = basename($migration);
    $ran   = @file_get_contents('/tmp/myanai_migrations.log');
    if (strpos($ran, $fname) !== false) continue; // Already ran
    
    $result = shell_exec("mysql -h {$dbHost} -u{$dbUser} -p{$dbPass} {$dbName} < {$migration} 2>&1");
    if (empty(trim($result))) {
        wlog("✅ Migration: {$fname}");
        file_put_contents('/tmp/myanai_migrations.log', $fname."\n", FILE_APPEND);
    } else {
        wlog("⚠️ Migration {$fname}: ".trim($result));
    }
}

$summary = "✅ Deploy complete: {$commits} commits by {$pusher}";
wlog($summary);
http_response_code(200);
echo $summary;
