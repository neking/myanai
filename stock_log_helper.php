<?php
/**
 * FIXED: this used to INSERT into a table called `stock_logs` (plural) using
 * column names (item_id, action, qty_before, qty_after, qty_change, unit,
 * user_id, user_name, branch_name) that don't match the real `stock_log`
 * (singular) table at all — confirmed via SHOW CREATE TABLE. Every call to
 * this function was silently failing (caught by the try/catch below) and
 * logging an error, so every "restock" done through admin.php has been
 * invisible in stock history. Rewritten to write to the real table/columns,
 * with the same function signature so admin.php's call site didn't need to
 * change. tenant_id is looked up from the item itself rather than trusted
 * from session, since that's the more reliable source of truth.
 */
function write_stock_log(PDO $pdo, int $item_id, string $item_name, string $action, float $qty_before, float $qty_after, string $unit='', string $reason='', int $user_id=0, string $user_name='System', int $branch_id=1, string $branch_name=''):bool {
    try {
        $validReasons = ['restock','manual_adjust','order_deduct','waste','correction','returned'];
        $reasonNorm = strtolower(trim($reason));
        $reasonEnum = in_array($reasonNorm, $validReasons, true)
            ? $reasonNorm
            : (($qty_after >= $qty_before) ? 'restock' : 'correction');

        $tRow = $pdo->prepare("SELECT tenant_id FROM menu_items WHERE id = ?");
        $tRow->execute([$item_id]);
        $tenantId = (int)($tRow->fetchColumn() ?: 1);

        $stmt = $pdo->prepare("INSERT INTO stock_log (tenant_id, branch_id, menu_item_id, item_name, change_qty, new_qty, reason, note, staff_name) VALUES (?,?,?,?,?,?,?,?,?)");
        return $stmt->execute([
            $tenantId, $branch_id, $item_id, $item_name,
            (int)round($qty_after - $qty_before), (int)round($qty_after),
            $reasonEnum, $reason ?: null, $user_name,
        ]);
    } catch(Exception $e) {
        error_log('stock_log error: '.$e->getMessage());
        return false;
    }
}
