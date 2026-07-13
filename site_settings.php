<?php
/**
 * site_settings.php — CMS Settings API
 * GET  ?action=get  → all settings as key→value object
 * POST ?action=save → save one or multiple settings
 * Admin session required for POST
 */
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();


session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Auth check for POST (write) operations ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAdmin  = !empty($_SESSION['admin']) && ($_SESSION['admin']['role'] === 'superadmin');
    $isTenant = !empty($_SESSION['admin']) && ($_SESSION['admin']['role'] === 'tenant');
    if (!$isAdmin && !$isTenant) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'msg'=>'Unauthorized']);
        exit;
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }



function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

$action = $_GET['action'] ?? 'get';

/* ── GET all settings ── */
if ($action === 'get') {
    try {
        $rows = db()->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
        $out  = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        echo json_encode(['ok'=>true,'settings'=>$out]);
    } catch (PDOException $e) {
        // Table not yet created — return defaults
        echo json_encode(['ok'=>true,'settings'=>[],'note'=>'site_settings table not found']);
    }
    exit;
}

/* ── POST save — admin only ── */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['admin'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'msg'=>'Not logged in']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { echo json_encode(['ok'=>false,'msg'=>'Invalid JSON']); exit; }

    try {
        $stmt = db()->prepare("
            INSERT INTO site_settings (setting_key, setting_value)
            VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()
        ");
        foreach ($body as $k => $v) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k));
            if (!$key) continue;
            $val = substr((string)$v, 0, 2000);
            $stmt->execute([':k'=>$key, ':v'=>$val, ':v2'=>$val]);
        }
        echo json_encode(['ok'=>true]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ── POST upload_image — admin only. Used by the Landing Page Editor
   (admin_lpe.js) for hero background / section images. Was previously
   called by the frontend but never implemented, so uploads silently failed. ── */
if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['admin'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'msg'=>'Not logged in']);
        exit;
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'No file uploaded']); exit;
    }
    $file = $_FILES['file'];
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok'=>false,'msg'=>'Max file size is 5MB']); exit;
    }
    $allowedMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : $file['type'];
    if (!isset($allowedMime[$mime])) {
        echo json_encode(['ok'=>false,'msg'=>'Only JPG/PNG/GIF/WEBP images allowed']); exit;
    }

    $folder = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['folder'] ?? 'site')));
    if (!$folder) $folder = 'site';
    $dir = __DIR__ . '/uploads/site/' . $folder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ext  = $allowedMime[$mime];
    $name = $folder . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok'=>false,'msg'=>'Failed to save file']); exit;
    }

    echo json_encode(['ok'=>true, 'url' => 'uploads/site/' . $folder . '/' . $name]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
