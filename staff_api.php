<?php
require_once 'db_connect.php';
require_once 'auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();

// CRITICAL FIX: this file previously had NO authentication check at all —
// requireCsrf() only defends against cross-site request forgery (a victim's
// browser being tricked into firing a request from another site); it does
// nothing to stop a deliberate direct attacker, who can simply visit the
// site themselves, obtain their own valid session + CSRF token, and call
// this endpoint directly. That meant 'list' leaked every staff member's PIN
// code (the entire authentication credential for the waiter app) across
// every tenant to any unauthenticated visitor, and 'add'/'update'/'delete'
// let anyone inject, modify, or remove staff records for any branch. Only
// admin.php (super-admin panel) actually calls this file — tenant.php
// doesn't — so gating on the super-admin session matches its only real use.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$pdo = getPDO();

function jsonOut($d){ echo json_encode($d); exit; }
function fail($m){ http_response_code(400); jsonOut(['ok'=>false,'msg'=>$m]); }

// LIST
if ($action === 'list') {
    $branch   = (int)($_GET['branch_id'] ?? 0);
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $where    = 'WHERE 1=1';
    if ($tenantId > 0) $where .= " AND s.branch_id IN (SELECT id FROM branches WHERE tenant_id=$tenantId)";
    if ($branch > 0)   $where .= ' AND s.branch_id = :b';
    $stmt = $pdo->prepare("SELECT s.id,s.branch_id,s.name,s.pin,s.role,s.is_active,s.permissions,s.notes,created_at FROM staff s $where ORDER BY branch_id,role DESC,name");
    $branch ? $stmt->execute([':b'=>$branch]) : $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'],true) : [];
    }
    jsonOut(['ok'=>true,'staff'=>$rows]);
}

// ADD
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'),true);
    if (empty($d['name'])) fail('Name required');
    if (empty($d['pin']) || strlen($d['pin']) < 4) fail('PIN min 4 digits');
    if (!preg_match('/^\d+$/', $d['pin'])) fail('PIN numbers only');
    // Check duplicate PIN in same branch
    $dup = $pdo->prepare("SELECT id FROM staff WHERE pin=:p AND branch_id=:b");
    $dup->execute([':p'=>$d['pin'],':b'=>(int)($d['branch_id']??1)]);
    if ($dup->fetch()) fail('PIN already used in this branch');
    $perms = json_encode($d['permissions'] ?? []);
    $stmt = $pdo->prepare("INSERT INTO staff (branch_id,name,pin,role,is_active,permissions,notes) VALUES (:b,:n,:p,:r,1,:perms,:notes)");
    $stmt->execute([':b'=>(int)($d['branch_id']??1),':n'=>trim($d['name']),':p'=>$d['pin'],':r'=>$d['role']??'waiter',':perms'=>$perms,':notes'=>$d['notes']??'']);
    jsonOut(['ok'=>true,'id'=>$pdo->lastInsertId()]);
}

// UPDATE
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'),true);
    $id = (int)($d['id']??0);
    if (!$id) fail('ID required');
    if (!empty($d['pin'])) {
        if (!preg_match('/^\d{4,6}$/', $d['pin'])) fail('PIN must be 4-6 digits');
        $dup = $pdo->prepare("SELECT id FROM staff WHERE pin=:p AND branch_id=(SELECT branch_id FROM staff WHERE id=:id) AND id!=:id");
        $dup->execute([':p'=>$d['pin'],':id'=>$id]);
        if ($dup->fetch()) fail('PIN already used');
    }
    $fields = []; $params = [':id'=>$id];
    if (isset($d['name']))        { $fields[] = 'name=:n';       $params[':n']  = trim($d['name']); }
    if (isset($d['pin'])&&$d['pin'])  { $fields[] = 'pin=:p';    $params[':p']  = $d['pin']; }
    if (isset($d['role']))        { $fields[] = 'role=:r';        $params[':r']  = $d['role']; }
    if (isset($d['is_active']))   { $fields[] = 'is_active=:a';   $params[':a']  = (int)$d['is_active']; }
    if (isset($d['permissions'])) { $fields[] = 'permissions=:perms'; $params[':perms'] = json_encode($d['permissions']); }
    if (isset($d['notes']))       { $fields[] = 'notes=:notes';   $params[':notes'] = $d['notes']; }
    if (!$fields) fail('Nothing to update');
    $pdo->prepare("UPDATE staff SET ".implode(',',$fields)." WHERE id=:id")->execute($params);
    jsonOut(['ok'=>true]);
}

// DELETE
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'),true);
    $id = (int)($d['id']??0);
    if (!$id) fail('ID required');
    $pdo->prepare("DELETE FROM staff WHERE id=:id")->execute([':id'=>$id]);
    jsonOut(['ok'=>true]);
}

fail('Unknown action');
