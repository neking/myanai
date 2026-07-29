<?php
/**
 * error_alert_cron.php — NEW standalone script, does not modify any
 * existing file. Checks the app's own error log (logs/errors.log) and the
 * nginx error log for new PHP Fatal errors since the last run, and emails a
 * summary to the admin contact if any are found. Designed to run via cron
 * every 10 minutes. Safe to fail silently (never touches request-serving
 * code paths) - if this script errors, it just logs to its own file and
 * exits, it cannot break the live site.
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';  // loads /etc/myanai.env via putenv() - was missing, causing SMTP auth to silently fail with an empty password
require_once __DIR__ . '/mailer.php';

$stateFile = __DIR__ . '/logs/error_alert_state.json';
$alertTo   = 'naymintint7@gmail.com';

$sources = [
    __DIR__ . '/logs/errors.log',
    '/var/log/nginx/error.log',
];

$state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$newState = $state;
$newLines = [];

foreach ($sources as $path) {
    if (!is_readable($path)) continue;
    $size = filesize($path);
    $lastSize = $state[$path]['size'] ?? 0;

    // File was rotated/truncated (e.g. logrotate) - start fresh from 0 rather
    // than erroring or missing everything.
    if ($size < $lastSize) $lastSize = 0;

    if ($size > $lastSize) {
        $fh = fopen($path, 'r');
        fseek($fh, $lastSize);
        $chunk = fread($fh, $size - $lastSize);
        fclose($fh);
        foreach (explode("\n", $chunk) as $line) {
            if (stripos($line, 'PHP Fatal error') !== false || stripos($line, 'PHP Parse error') !== false) {
                $newLines[] = "[" . basename($path) . "] " . trim($line);
            }
        }
    }
    $newState[$path] = ['size' => $size];
}

file_put_contents($stateFile, json_encode($newState));

if (empty($newLines)) {
    exit; // nothing new, nothing to do
}

// Cap how much we email at once (a crash loop shouldn't send a novel-length email)
$newLines = array_slice($newLines, 0, 20);
$body = "<h3>MyanAi POS — New server errors detected</h3><p>" . count($newLines) . " new fatal/parse error(s) since last check:</p><pre style='white-space:pre-wrap;font-size:.85rem;background:#f5f5f5;padding:1rem;border-radius:8px'>"
    . htmlspecialchars(implode("\n\n", $newLines))
    . "</pre><p style='color:#888;font-size:.8rem'>This is an automated alert from error_alert_cron.php, checked every 10 minutes.</p>";

sendMail($alertTo, '🚨 MyanAi POS — Server Error Alert', $body);
