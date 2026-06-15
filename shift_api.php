<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';
if(session_status()===PHP_SESSION_NONE) session_start();
$pdo = getPDO();
header('Content-Type: application/json; charset=utf-8');

$tid = 0;
if(!empty($_SESSION['tenant_admin'])) $tid=(int)($_SESSION['tenant_id']??0);
elseif(!empty($_SESSION['admin']))    $tid=(int)($_GET['tenant_id']??0);
if(!$tid){ echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }

$action = $_GET['action']??'';

/* ── CREATE shifts table if not exist ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_shifts (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id  INT UNSIGNED NOT NULL,
        staff_id   INT UNSIGNED NOT NULL,
        shift_date DATE NOT NULL,
        shift_type ENUM('morning','afternoon','evening','off','custom') DEFAULT 'morning',
        start_time TIME DEFAULT '09:00:00',
        end_time   TIME DEFAULT '17:00:00',
        notes      VARCHAR(200) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(tenant_id), INDEX(shift_date), INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(\Exception $e){}

/* ── LIST ── */
if($action==='list'){
    $date  = $_GET['date']??'';
    $week  = $_GET['week']??'';
    if($week){
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date BETWEEN ? AND ?
            ORDER BY ss.shift_date,s.name");
        // week = YYYY-WW, get monday
        $dt = new DateTime(); $dt->setISODate((int)substr($week,0,4),(int)substr($week,5,2));
        $mon = $dt->format('Y-m-d'); $dt->modify('+6 days'); $sun=$dt->format('Y-m-d');
        $stmt->execute([$tid,$mon,$sun]);
    } elseif($date){
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date=? ORDER BY s.name");
        $stmt->execute([$tid,$date]);
    } else {
        $stmt = $pdo->prepare("SELECT ss.*,s.name as staff_name,s.role
            FROM staff_shifts ss JOIN staff s ON s.id=ss.staff_id
            WHERE ss.tenant_id=? AND ss.shift_date>=CURDATE()-7
            ORDER BY ss.shift_date DESC,s.name LIMIT 100");
        $stmt->execute([$tid]);
    }
    echo json_encode(['ok'=>true,'shifts'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

/* ── ADD ── */
if($action==='add' && $_SERVER['REQUEST_METHOD']==='POST'){
    $b = json_decode(file_get_contents('php://input'),true)??[];
    $stmt=$pdo->prepare("INSERT INTO staff_shifts (tenant_id,staff_id,shift_date,shift_type,start_time,end_time,notes)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shift_type=VALUES(shift_type),start_time=VALUES(start_time),end_time=VALUES(end_time),notes=VALUES(notes)");
    $stmt->execute([$tid,(int)$b['staff_id'],$b['shift_date'],$b['shift_type']??'morning',$b['start_time']??'09:00',$b['end_time']??'17:00',$b['notes']??'']);
    echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
    exit;
}

/* ── DELETE ── */
if($action==='delete' && $_SERVER['REQUEST_METHOD']==='POST'){
    $b = json_decode(file_get_contents('php://input'),true)??[];
    $pdo->prepare("DELETE FROM staff_shifts WHERE id=? AND tenant_id=?")->execute([(int)$b['id'],$tid]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
