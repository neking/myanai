<?php
/**
 * NoodleHaus — Order Cancel Hooks
 * Reverse operations when an order is cancelled/deleted
 * Called from admin.php delete_order flow
 */

declare(strict_types=1);

/**
 * Restore stock quantities
 */
function hookStockRestore(PDO $pdo, int $orderId): void {
    try {
        $items = $pdo->prepare("SELECT menu_item_id, item_name, qty FROM order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemId = (int)$item['menu_item_id'];
            $qty    = (int)$item['qty'];
            if (!$itemId) continue;

            $pdo->prepare("UPDATE menu_items SET stock_qty = stock_qty + ? WHERE id = ?")
                ->execute([$qty, $itemId]);

            $newQty = (int)$pdo->query("SELECT stock_qty FROM menu_items WHERE id = $itemId")->fetchColumn();

            $pdo->prepare("
                INSERT INTO stock_log (menu_item_id, item_name, change_qty, new_qty, reason, note, order_id)
                VALUES (?, ?, ?, ?, 'returned', 'Order cancelled/deleted', ?)
            ")->execute([$itemId, $item['item_name'], $qty, $newQty, $orderId]);
        }
    } catch (Exception $e) { /* stock restore fail — log but don't block */ }
}

/**
 * Adjust CRM customer stats
 */
function hookCrmReverse(PDO $pdo, string $phone, int $totalAmount): void {
    if (!$phone) return;
    try {
        $pdo->prepare("
            UPDATE customers SET
                total_orders = GREATEST(0, total_orders - 1),
                total_spent  = GREATEST(0, total_spent - ?)
            WHERE phone = ?
        ")->execute([$totalAmount, $phone]);

        // Re-evaluate tag
        $pdo->prepare("
            UPDATE customers SET tag = CASE
                WHEN total_orders >= 10 OR total_spent >= 100000 THEN 'vip'
                WHEN total_orders >= 3 THEN 'regular'
                ELSE 'normal'
            END
            WHERE phone = ? AND tag NOT IN ('blocked')
        ")->execute([$phone]);
    } catch (Exception $e) { /* CRM reverse fail */ }
}

/**
 * Cancel delivery tracking
 */
function hookDeliveryCancel(PDO $pdo, int $orderId): void {
    try {
        // Get driver id before cancelling
        $dt = $pdo->prepare("SELECT id, driver_id FROM delivery_tracking WHERE order_id = ? AND status NOT IN ('delivered','cancelled')");
        $dt->execute([$orderId]);
        $row = $dt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $pdo->prepare("UPDATE delivery_tracking SET status = 'cancelled' WHERE id = ?")->execute([$row['id']]);

        // Free driver
        if ($row['driver_id']) {
            $pdo->prepare("UPDATE drivers SET status = 'available' WHERE id = ? AND status = 'busy'")
                ->execute([$row['driver_id']]);
        }
    } catch (Exception $e) { /* delivery cancel fail */ }
}

/**
 * Remove from shift tracking
 */
function hookShiftRemove(PDO $pdo, int $orderId): void {
    try {
        $pdo->prepare("DELETE FROM shift_orders WHERE order_id = ?")->execute([$orderId]);
    } catch (Exception $e) { /* shift remove fail */ }
}
