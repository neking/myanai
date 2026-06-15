<?php
$_REQ_BRANCH = (int)($_GET['branch_id'] ?? 0);
$_REQ_TENANT = (int)($_GET['tenant_id'] ?? $_SESSION['tenant_id'] ?? 1);
function branchWhere(string $alias='l'): string {
    global $_REQ_BRANCH, $_REQ_TENANT;
    $w = [];
    if($_REQ_BRANCH > 0) $w[] = "$alias.branch_id = $_REQ_BRANCH";
    if($_REQ_TENANT > 0) $w[] = "$alias.tenant_id = $_REQ_TENANT";
    return $w ? ' AND '.implode(' AND ',$w) : '';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once 'db_connect.php';
$pdo = getPDO();

$action = $_REQUEST['action'] ?? 'list';

function write_stock_log(PDO $pdo, int $item_id, string $item_name, string $action, float $qty_before, float $qty_after, string $unit='', string $reason='', int $user_id=0, string $user_name='System', int $branch_id=1, string $branch_name=''):bool {
    $stmt = $pdo->prepare("INSERT INTO stock_log (item_id,item_name,action,qty_before,qty_after,qty_change,unit,reason,user_id,user_name,branch_id,branch_name) VALUES (:menu_item_id,:item_name,:action,:qty_before,:qty_after,:qty_change,:unit,:reason,:user_id,:user_name,:branch_id,:branch_name)");
    return $stmt->execute([':menu_item_id'=>$item_id,':item_name'=>$item_name,':action'=>$action,':qty_before'=>$qty_before,':qty_after'=>$qty_after,':qty_change'=>$qty_after-$qty_before,':unit'=>$unit,':reason'=>$reason,':user_id'=>$user_id,':user_name'=>$user_name,':branch_id'=>$branch_id,':branch_name'=>$branch_name]);
}

if($action==='list'){
    $where=['1=1'];$params=[];
    if(!empty($_GET['search'])){$where[]='(sl.item_name LIKE :s OR sl.reason LIKE :s OR sl.user_name LIKE :s)';$params[':s']='%'.$_GET['search'].'%';}
    if(!empty($_GET['action_type'])){$where[]='sl.action=:at';$params[':at']=$_GET['action_type'];}
    if(!empty($_GET['date_from'])){$where[]='DATE(sl.created_at)>=:df';$params[':df']=$_GET['date_from'];}
    if(!empty($_GET['date_to'])){$where[]='DATE(sl.created_at)<=:dt';$params[':dt']=$_GET['date_to'];}
    $limit=min(500,(int)($_GET['limit']??100));$offset=max(0,(int)($_GET['offset']??0));
    $w=implode(' AND ',$where);
    $c=$pdo->prepare("SELECT COUNT(*) FROM stock_log sl WHERE $w");$c->execute($params);$total=(int)$c->fetchColumn();
    $stmt=$pdo->prepare("SELECT sl.*,DATE_FORMAT(sl.created_at,'%d/%m/%Y %H:%i') AS created_fmt FROM stock_log sl WHERE $w ORDER BY sl.created_at DESC LIMIT :lim OFFSET :off");
    foreach($params as $k=>$v)$stmt->bindValue($k,$v);
    $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->bindValue(':off',$offset,PDO::PARAM_INT);$stmt->execute();
    echo json_encode(['success'=>true,'total'=>$total,'logs'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);exit;
}

if($action==='summary'){
    $df=$_GET['date_from']??date('Y-m-01');$dt=$_GET['date_to']??date('Y-m-d');
    $stmt=$pdo->prepare("SELECT action,COUNT(*) AS total_entries,SUM(ABS(qty_change)) AS total_qty FROM stock_log WHERE DATE(created_at) BETWEEN :df AND :dt GROUP BY action ORDER BY total_entries DESC");
    $stmt->execute([':df'=>$df,':dt'=>$dt]);
    echo json_encode(['success'=>true,'summary'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);exit;
}

if($action==='export_csv'){
    $df=$_GET['date_from']??date('Y-m-01');$dt=$_GET['date_to']??date('Y-m-d');
    $stmt=$pdo->prepare("SELECT id,item_name,action,qty_before,qty_after,qty_change,unit,reason,user_name,branch_name,created_at FROM stock_log WHERE DATE(created_at) BETWEEN :df AND :dt ORDER BY created_at DESC");
    $stmt->execute([':df'=>$df,':dt'=>$dt]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_log_'.$df.'_to_'.$dt.'.csv"');
    echo "\xEF\xBB\xBF";$out=fopen('php://output','w');
    fputcsv($out,['ID','Item','Action','Before','After','Change','Unit','Reason','Staff','Branch','DateTime']);
    foreach($rows as $r)fputcsv($out,[$r['id'],$r['item_name'],$r['action'],$r['qty_before'],$r['qty_after'],$r['qty_change'],$r['unit'],$r['reason'],$r['user_name'],$r['branch_name'],$r['created_at']]);
    fclose($out);exit;
}

if($action==='add_log'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $d=json_decode(file_get_contents('php://input'),true)??$_POST;
    $ok=write_stock_log($pdo,(int)$d['menu_item_id'],$d['item_name'],$d['action'],(float)$d['qty_before'],(float)$d['qty_after'],$d['unit']??'',$d['reason']??'',(int)($d['user_id']??0),$d['user_name']??'System',(int)($d['branch_id']??1),$d['branch_name']??'');
    echo json_encode(['success'=>$ok]);exit;
}

http_response_code(400);echo json_encode(['success'=>false,'error'=>'Unknown action']);
