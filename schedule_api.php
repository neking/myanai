<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = getPDO();
$action = trim($_GET['action'] ?? '');
function ok(mixed $d=[]): never { echo json_encode(array_merge(['ok'=>true],(array)$d),JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'msg'=>$m]); exit; }
function requireAdmin(): void { if(session_status()===PHP_SESSION_NONE)session_start(); if(empty($_SESSION['admin']))fail('Unauthorized',401); }

/* WEEK VIEW */
if ($action === 'week') {
    requireAdmin();
    $startDate = trim($_GET['start'] ?? '');
    if (!$startDate) {
        $dow = (int)date('w'); // 0=sun
        $startDate = date('Y-m-d', strtotime("-{$dow} days"));
    }
    $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));

    $staff = $pdo->query("SELECT id, name, role FROM staff WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    $sched = $pdo->prepare("
        SELECT sc.*, s.name AS staff_name FROM schedules sc
        JOIN staff s ON s.id = sc.staff_id
        WHERE sc.work_date BETWEEN ? AND ?
        ORDER BY sc.work_date, sc.start_time
    ");
    $sched->execute([$startDate, $endDate]);
    $rows = $sched->fetchAll(PDO::FETCH_ASSOC);

    // Labor cost for the week
    $cost = 0;
    foreach ($rows as $r) {
        $hours = (strtotime($r['end_time']) - strtotime($r['start_time'])) / 3600;
        $cost += $hours * (int)$r['hourly_rate'];
    }

    ok(['staff'=>$staff, 'schedules'=>$rows, 'start'=>$startDate, 'end'=>$endDate, 'labor_cost'=>(int)$cost, 'total_shifts'=>count($rows)]);
}

/* CREATE / ASSIGN */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $staffId = (int)($d['staff_id'] ?? 0);
    $date    = trim($d['work_date'] ?? '');
    $start   = trim($d['start_time'] ?? '09:00');
    $end     = trim($d['end_time'] ?? '17:00');
    $role    = trim($d['role'] ?? '') ?: null;
    $rate    = (int)($d['hourly_rate'] ?? 1500);
    $notes   = trim($d['notes'] ?? '') ?: null;
    if (!$staffId || !$date) fail('Staff and date required');

    $pdo->prepare("
        INSERT INTO schedules (staff_id, work_date, start_time, end_time, role, hourly_rate, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time),
            role=VALUES(role), hourly_rate=VALUES(hourly_rate), notes=VALUES(notes), status='scheduled'
    ")->execute([$staffId, $date, $start, $end, $role, $rate, $notes]);
    ok(['id' => (int)$pdo->lastInsertId()]);
}

/* UPDATE STATUS */
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    $status = trim($d['status'] ?? '');
    if (!$id || !in_array($status, ['scheduled','confirmed','completed','absent','cancelled'])) fail('Invalid');
    $pdo->prepare("UPDATE schedules SET status=? WHERE id=?")->execute([$status, $id]);
    ok();
}

/* DELETE */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('id required');
    $pdo->prepare("DELETE FROM schedules WHERE id=?")->execute([$id]);
    ok();
}

/* LABOR SUMMARY */
if ($action === 'labor') {
    requireAdmin();
    $month = trim($_GET['month'] ?? date('Y-m'));
    $rows = $pdo->prepare("
        SELECT s.name, s.role, COUNT(sc.id) AS shifts,
            SUM(TIMESTAMPDIFF(HOUR, sc.start_time, sc.end_time)) AS total_hours,
            SUM(TIMESTAMPDIFF(HOUR, sc.start_time, sc.end_time) * sc.hourly_rate) AS total_cost
        FROM schedules sc JOIN staff s ON s.id=sc.staff_id
        WHERE DATE_FORMAT(sc.work_date,'%Y-%m')=? AND sc.status NOT IN ('cancelled')
        GROUP BY sc.staff_id ORDER BY total_cost DESC
    ");
    $rows->execute([$month]);
    ok(['labor' => $rows->fetchAll(PDO::FETCH_ASSOC), 'month' => $month]);
}

fail('Unknown action');
