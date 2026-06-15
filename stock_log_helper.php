<?php
function write_stock_log(PDO $pdo, int $item_id, string $item_name, string $action, float $qty_before, float $qty_after, string $unit='', string $reason='', int $user_id=0, string $user_name='System', int $branch_id=1, string $branch_name=''):bool {
    try {
        $stmt = $pdo->prepare("INSERT INTO stock_logs (item_id,item_name,action,qty_before,qty_after,qty_change,unit,reason,user_id,user_name,branch_id,branch_name) VALUES (:item_id,:item_name,:action,:qty_before,:qty_after,:qty_change,:unit,:reason,:user_id,:user_name,:branch_id,:branch_name)");
        return $stmt->execute([':item_id'=>$item_id,':item_name'=>$item_name,':action'=>$action,':qty_before'=>$qty_before,':qty_after'=>$qty_after,':qty_change'=>$qty_after-$qty_before,':unit'=>$unit,':reason'=>$reason,':user_id'=>$user_id,':user_name'=>$user_name,':branch_id'=>$branch_id,':branch_name'=>$branch_name]);
    } catch(Exception $e) {
        error_log('stock_log error: '.$e->getMessage());
        return false;
    }
}
