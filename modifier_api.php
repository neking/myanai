<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/* ── DB CONFIG ── */


function db(): PDO {
    static $pdo;
    if (!$pdo) $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',DB_HOST,DB_PORT,DB_NAME),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $pdo;
}

$action  = $_GET['action'] ?? '';
$itemId  = (int)($_GET['item_id'] ?? 0);

/* get modifiers for a menu item */
if ($action === 'get_modifiers' && $itemId) {
    $groups = db()->prepare("
        SELECT * FROM modifier_groups WHERE menu_item_id=:id ORDER BY sort_order,id
    ");
    $groups->execute([':id'=>$itemId]);
    $groups = $groups->fetchAll();
    foreach ($groups as &$g) {
        $opts = db()->prepare("
            SELECT * FROM modifier_options WHERE group_id=:gid ORDER BY sort_order,id
        ");
        $opts->execute([':gid'=>$g['id']]);
        $g['options'] = $opts->fetchAll();
        // Cast types
        $g['required']   = (bool)$g['required'];
        $g['sort_order'] = (int)$g['sort_order'];
        foreach ($g['options'] as &$o) {
            $o['price_add']  = (int)$o['price_add'];
            $o['is_default'] = (bool)$o['is_default'];
            $o['sort_order'] = (int)$o['sort_order'];
        }
    }
    echo json_encode(['ok'=>true,'groups'=>$groups]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Invalid request']);
