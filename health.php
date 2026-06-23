<?php
header('Content-Type: application/json');
$status = ['status'=>'ok','time'=>date('Y-m-d H:i:s'),'version'=>'1.0.0','checks'=>[]];
$ok = true;

// DB check + stats
try {
    require_once 'db_connect.php';
    $pdo = getPDO();
    $pdo->query('SELECT 1');
    $status['checks']['db'] = 'ok';
    // Tenant count
    $status['checks']['tenants'] = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE is_active=1")->fetchColumn();
    // Today orders
    $status['checks']['orders_today'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL")->fetchColumn();
} catch(Exception $e) {
    $status['checks']['db'] = 'fail: '.$e->getMessage();
    $ok = false;
}

// PHP version
$status['checks']['php'] = PHP_VERSION;

// Disk check
$free = disk_free_space('/');
$status['checks']['disk_free_gb'] = round($free/1073741824, 1);
if ($free < 500*1024*1024) { $status['checks']['disk'] = 'warning'; $ok = false; }
else $status['checks']['disk'] = 'ok';

// Memory usage
$status['checks']['memory_mb'] = round(memory_get_usage(true)/1048576, 1);

// Recent errors check
$logFile = __DIR__.'/logs/errors.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $recent = array_filter($lines, fn($l) => strtotime(substr($l,1,19)) > time()-3600);
    $status['checks']['errors_last_1h'] = count($recent);
    if (count($recent) > 10) { $status['checks']['error_level'] = 'warning'; }
} else {
    $status['checks']['errors_last_1h'] = 0;
}

// Log file size
$status['checks']['log_size_kb'] = file_exists($logFile) ? round(filesize($logFile)/1024, 1) : 0;

if (!$ok) http_response_code(503);
$status['status'] = $ok ? 'ok' : 'degraded';
echo json_encode($status, JSON_PRETTY_PRINT);
