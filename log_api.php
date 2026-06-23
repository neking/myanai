<?php
/**
 * Error Log Viewer API
 * Admin only - view server error logs
 */
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$action = $_GET['action'] ?? 'list';
$logFile = __DIR__ . '/logs/errors.log';

// ── LIST LOG ENTRIES ──
if ($action === 'list') {
    $limit = min(200, (int)($_GET['limit'] ?? 50));
    $level = $_GET['level'] ?? ''; // error, warning, info

    if (!file_exists($logFile)) {
        echo json_encode(['ok'=>true,'entries'=>[],'total'=>0,'msg'=>'No log file yet']);
        exit;
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines); // newest first

    $entries = [];
    foreach ($lines as $line) {
        // Parse: [2024-06-23 12:00:00] MESSAGE
        preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (.+)$/', $line, $m);
        $timestamp = $m[1] ?? '';
        $message   = $m[2] ?? $line;

        // Detect level
        $lvl = 'info';
        if (stripos($message, 'error') !== false || stripos($message, 'fail') !== false) $lvl = 'error';
        elseif (stripos($message, 'warn') !== false) $lvl = 'warning';

        if ($level && $lvl !== $level) continue;

        $entries[] = ['timestamp'=>$timestamp,'message'=>$message,'level'=>$lvl];
        if (count($entries) >= $limit) break;
    }

    echo json_encode(['ok'=>true,'entries'=>$entries,'total'=>count($lines)]);
    exit;
}

// ── CLEAR LOGS ──
if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    echo json_encode(['ok'=>true,'msg'=>'Log cleared']);
    exit;
}

// ── STATS ──
if ($action === 'stats') {
    if (!file_exists($logFile)) {
        echo json_encode(['ok'=>true,'total'=>0,'errors'=>0,'warnings'=>0,'size_kb'=>0]);
        exit;
    }
    $lines    = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $errors   = count(array_filter($lines, fn($l) => stripos($l,'error')!==false || stripos($l,'fail')!==false));
    $warnings = count(array_filter($lines, fn($l) => stripos($l,'warn')!==false));
    $sizeKb   = round(filesize($logFile) / 1024, 1);
    echo json_encode(['ok'=>true,'total'=>count($lines),'errors'=>$errors,'warnings'=>$warnings,'size_kb'=>$sizeKb]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
