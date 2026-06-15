<?php
header('Content-Type: application/json');
$status = ['status'=>'ok','time'=>date('Y-m-d H:i:s'),'checks'=>[]];
$ok = true;

// DB check
try {
    require_once 'db_connect.php';
    getPDO()->query('SELECT 1');
    $status['checks']['db'] = 'ok';
} catch(Exception $e) {
    $status['checks']['db'] = 'fail';
    $ok = false;
}

// Disk check
$free = disk_free_space('/');
$status['checks']['disk_free_gb'] = round($free/1073741824, 1);
if($free < 500*1024*1024) { $status['checks']['disk'] = 'warning'; $ok = false; }
else $status['checks']['disk'] = 'ok';

// Recent errors check
$logFile = __DIR__.'/logs/errors.log';
if(file_exists($logFile)) {
    $lines = file($logFile);
    $recent = array_slice($lines, -5);
    $last1h = array_filter($recent, fn($l) => strtotime(substr($l,1,19)) > time()-3600);
    $status['checks']['recent_errors'] = count($last1h);
} else {
    $status['checks']['recent_errors'] = 0;
}

if(!$ok) http_response_code(503);
$status['status'] = $ok ? 'ok' : 'degraded';
echo json_encode($status, JSON_PRETTY_PRINT);
