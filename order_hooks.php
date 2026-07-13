<?php
/**
 * NoodleHaus — Order Hooks  (Performance Fix)
 * Direct PHP function calls — replaces 4x HTTP file_get_contents
 * Called from order_handler.php after order is placed
 * 
 * All hooks are fire-and-forget: exceptions caught, order never affected
 */

declare(strict_types=1);

/**
 * Hook 1: CRM Profile Sync (Phase 5A)
 * tenant_id required as of migration 008 — customers is now keyed on
 * (tenant_id, phone), so a customer ordering from a new tenant gets their
 * own separate row instead of updating another tenant's record.
 */
function hookCrmUpsert(PDO $pdo, int $tenantId, string $phone, string $name, string $payment, int $orderId, int $total, array $items): void {
    if (!$phone) return;
    try {
        // Upsert customer
        $pdo->prepare("
            INSERT INTO customers (tenant_id, phone, name, preferred_payment, total_orders, total_spent, last_order_at)
            VALUES (?, ?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name              = IF(? <> '', ?, name),
                preferred_payment = IF(? <> '', ?, preferred_payment),
                total_orders      = total_orders + 1,
                total_spent       = total_spent + ?,
                last_order_at     = NOW()
        ")->execute([$tenantId, $phone, $name, $payment, $total,
                     $name, $name, $payment, $payment, $total]);

        // Auto-tag
        $pdo->prepare("
            UPDATE customers SET tag = CASE
                WHEN total_orders >= 10 OR total_spent >= 100000 THEN 'vip'
                WHEN total_orders >= 3 THEN 'regular'
                ELSE tag END
            WHERE phone = ? AND tenant_id = ? AND tag NOT IN ('blocked')
        ")->execute([$phone, $tenantId]);

        // Update favourite items — no tenant_id column here, but menu_item_id
        // always belongs to exactly one tenant's menu so no cross-tenant
        // collision risk on the (phone, menu_item_id) unique key.
        foreach ($items as $item) {
            $menuItemId = (int)($item['item_id'] ?? 0);
            $itemName   = trim($item['name'] ?? '');
            $qty        = max(1, (int)($item['qty'] ?? 1));
            if (!$menuItemId) continue;
            $pdo->prepare("
                INSERT INTO customer_favourite_items
                    (customer_phone, menu_item_id, item_name, order_count, last_ordered_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    item_name = VALUES(item_name), order_count = order_count + ?, last_ordered_at = NOW()
            ")->execute([$phone, $menuItemId, $itemName, $qty, $qty]);
        }
    } catch (Exception $e) { /* CRM fail — order unaffected */ }
}

/**
 * Hook 2: Shift Order Assign (Phase 5B)
 */
function hookShiftAssign(PDO $pdo, int $orderId): void {
    try {
        $shiftId = $pdo->query("SELECT id FROM shifts WHERE status='open' ORDER BY opened_at DESC LIMIT 1")->fetchColumn();
        if (!$shiftId) return;
        $pdo->prepare("INSERT IGNORE INTO shift_orders (shift_id, order_id) VALUES (?, ?)")
            ->execute([$shiftId, $orderId]);
    } catch (Exception $e) { /* shift fail — order unaffected */ }
}

/**
 * Hook 3: Stock Auto-Deduct (Phase 5E)
 */
/**
 * Hook 3: Stock Deduction Log (Phase 5C)
 *
 * IMPORTANT: this does NOT deduct stock. order_handler.php's own transaction
 * already deducts stock_qty safely (with a FOR UPDATE lock and a stock_qty
 * check, to prevent overselling under concurrent orders) before this hook
 * ever runs. This hook used to deduct AGAIN on top of that — confirmed via a
 * live test (ordering 1 unit dropped stock by 2) — silently halving every
 * item's real remaining stock on every single order since this hook was
 * added. Now it only reads the already-correct stock_qty and writes the
 * stock_log entry, since the transaction itself doesn't log.
 */
function hookStockDeduct(PDO $pdo, int $orderId, array $items): void {
    try {
        foreach ($items as $item) {
            $itemId = (int)($item['item_id'] ?? 0);
            $name   = trim($item['name'] ?? '');
            $qty    = max(1, (int)($item['qty'] ?? 1));
            if (!$itemId) continue;

            $newQty = (int)$pdo->query("SELECT stock_qty FROM menu_items WHERE id = $itemId")->fetchColumn();

            $pdo->prepare("
                INSERT INTO stock_log (menu_item_id, item_name, change_qty, new_qty, reason, order_id, staff_name)
                VALUES (?, ?, ?, ?, 'order_deduct', ?, 'System (Auto)')
            ")->execute([$itemId, $name, -$qty, $newQty, $orderId]);
        }
    } catch (Exception $e) { /* stock fail — order unaffected */ }
}

/**
 * Hook 4: Delivery Auto-Track (Phase 6C)
 */
function hookDeliveryTrack(PDO $pdo, int $orderId, string $orderType): void {
    if ($orderType !== 'delivery') return;
    try {
        $exists = $pdo->prepare("SELECT id FROM delivery_tracking WHERE order_id = ?");
        $exists->execute([$orderId]);
        if ($exists->fetchColumn()) return;
        $pdo->prepare("INSERT INTO delivery_tracking (order_id) VALUES (?)")->execute([$orderId]);
    } catch (Exception $e) { /* delivery fail — order unaffected */ }
}
