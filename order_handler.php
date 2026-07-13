<?php
declare(strict_types=1);

function sanitizeStr(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v ?? ''))), ENT_QUOTES, 'UTF-8');
}
function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
function logError(string $msg): void {
    $f = __DIR__ . '/logs/errors.log';
    @mkdir(dirname($f), 0755, true);
    file_put_contents($f, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { jsonError('Method not allowed', 405); }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/tenant_helper.php';
$pdo = getPDO();

// ── Customer Cancel (within 2 min) ──
if (($_GET['action'] ?? '') === 'cancel') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $orderId = (int)($body['order_id'] ?? 0);
    $phone   = trim($body['phone'] ?? '');
    if (!$orderId) jsonError('order_id required');

    $order = $pdo->prepare("
        SELECT * FROM orders
        WHERE id = ? AND deleted_at IS NULL AND customer_phone = ?
          AND created_at >= NOW() - INTERVAL 2 MINUTE
    ");
    $order->execute([$orderId, $phone]);
    $o = $order->fetch(PDO::FETCH_ASSOC);
    if (!$o) jsonError('Order not found or cancel window expired (2 min)');

    // Soft delete
    $pdo->prepare("UPDATE orders SET deleted_at=NOW(), delete_reason='Customer cancelled', deleted_by='customer' WHERE id=?")
        ->execute([$orderId]);

    // Reverse hooks
    require_once __DIR__ . '/order_cancel_hooks.php';
    hookStockRestore($pdo, $orderId);
    hookCrmReverse($pdo, $o['customer_phone'], (int)$o['total_amount']);
    hookDeliveryCancel($pdo, $orderId);
    hookShiftRemove($pdo, $orderId);

    echo json_encode(['success' => true, 'message' => 'Order cancelled']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) jsonError('Invalid JSON');

foreach (['customer','payment_method','items','subtotal','delivery_fee','total'] as $k) {
    if (!isset($body[$k])) jsonError("Missing: {$k}");
}

$customer      = $body['customer'];
$paymentMethod = sanitizeStr($body['payment_method']);
$items         = $body['items'];
$subtotal      = (int)$body['subtotal'];
$deliveryFee   = (int)$body['delivery_fee'];
$total         = (int)$body['total'];
$deviceId      = sanitizeStr($body['device_id'] ?? '');
$branchId      = (int)($body['branch_id'] ?? 0);

// ── Resolve tenant_id from the JSON body ──────────────────────────────────
// tenantId()/getCurrentTenantId() (tenant_helper.php) can't see this: it only
// checks $_GET, $_SESSION, and the raw $_POST superglobal — none of which are
// populated for a JSON request body read via php://input. Previously this
// silently fell through to tenant_id=1 for every single customer order
// regardless of which tenant's storefront it came from, so real tenants never
// saw their own orders. The frontend now sends tenant_id explicitly; validate
// it against the tenants table rather than trusting it blindly.
$requestedTenantId = (int)($body['tenant_id'] ?? 0);
$tenantId = 0;
if ($requestedTenantId > 0) {
    $tRow = $pdo->prepare("SELECT id FROM tenants WHERE id = ? AND is_active = 1");
    $tRow->execute([$requestedTenantId]);
    $tenantId = (int)($tRow->fetchColumn() ?: 0);
}
if (!$tenantId) jsonError('Invalid or inactive tenant');

$orderType     = in_array(($body['order_type']??''), ['delivery','dine_in']) ? $body['order_type'] : 'delivery';
$tableId       = strtoupper(sanitizeStr($body['table_id'] ?? ''));
if ($orderType === 'dine_in' && !$tableId && strpos($deviceId,'kiosk')===false) $orderType = 'delivery';

$requiredFields = $orderType === 'dine_in' ? ['name'] : ['name','phone','address'];
foreach ($requiredFields as $f) {
    if (empty(trim($customer[$f] ?? ''))) jsonError("Customer field required: {$f}");
}
if ($orderType === 'dine_in') $deliveryFee = 0;
if (empty($items) || !is_array($items)) jsonError('No items');
foreach ($items as $i => $item) {
    foreach (['item_id','qty','price'] as $f) {
        if (!isset($item[$f])) jsonError("Item[{$i}] missing: {$f}");
    }
}
$allowed = ['kpay','wave','wavepay','cb','cbpay','aya','ayapay','cod','cash','card'];
if (!in_array($paymentMethod, $allowed, true)) jsonError('Invalid payment method');

try {
    $pdo->beginTransaction();

    $orderId     = 0;
    $isAppend    = false;
    $tableStatus = $orderType === 'dine_in' ? 'open' : null;

    if ($orderType === 'dine_in' && $tableId) {
        $chk = $pdo->prepare("SELECT id FROM orders WHERE table_id=:tid AND tenant_id=:tenant AND table_status='open' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $chk->execute([':tid' => $tableId, ':tenant' => $tenantId]);
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            $orderId  = (int)$existingId;
            $isAppend = true;
            $pdo->prepare("UPDATE orders SET subtotal=subtotal+:sub, total_amount=total_amount+:tot, updated_at=NOW() WHERE id=:id")
                ->execute([':sub'=>$subtotal, ':tot'=>$total, ':id'=>$orderId]);
        }
    }

    if (!$isAppend) {
        $s = $pdo->prepare("
            INSERT INTO orders
                (tenant_id,branch_id,customer_name,customer_phone,delivery_address,township,city,
                 special_notes,payment_method,subtotal,delivery_fee,total_amount,
                 status,device_id,order_type,table_id,table_status,order_uuid,created_at)
            VALUES
                (:tenant_id,:branch_id,:name,:phone,:address,:township,:city,
                 :notes,:payment,:subtotal,:delivery_fee,:total,
                 'pending',:device_id,:order_type,:table_id,:table_status,UUID(),NOW())
        ");
        $s->execute([
            ':tenant_id'    => $tenantId,
            ':branch_id'    => $branchId ?: 1,
            ':name'         => sanitizeStr($customer['name']),
            ':phone'        => sanitizeStr($customer['phone']    ?? ''),
            ':address'      => sanitizeStr($customer['address']  ?? ''),
            ':township'     => sanitizeStr($customer['township'] ?? ''),
            ':city'         => sanitizeStr($customer['city']     ?? ''),
            ':notes'        => sanitizeStr($customer['notes']    ?? ''),
            ':payment'      => $paymentMethod,
            ':subtotal'     => $subtotal,
            ':delivery_fee' => $deliveryFee,
            ':total'        => $total,
            ':device_id'    => $deviceId,
            ':order_type'   => $orderType,
            ':table_id'     => $tableId ?: null,
            ':table_status' => $tableStatus,
        ]);
        $orderId = (int)$pdo->lastInsertId();
    }

    $itemStmt  = $pdo->prepare("INSERT INTO order_items (order_id,menu_item_id,item_name,unit_price,qty,subtotal) VALUES (:order_id,:item_id,:item_name,:item_price,:item_qty,:item_subtotal)");
    $stockStmt = $pdo->prepare("UPDATE menu_items SET stock_qty=stock_qty-:qty WHERE id=:id AND stock_qty>=:qty_check");

    // ★★★ CRITICAL: PRE-VALIDATE ALL STOCK BEFORE ANY DEDUCTION ★★★
    // This prevents partial stock deduction if later items fail
    // Using FOR UPDATE lock to prevent concurrent race conditions
    $stockCheck = $pdo->prepare("
        SELECT id, name, stock_qty FROM menu_items 
        WHERE id=:id 
        FOR UPDATE  -- ★ Lock this row until transaction commits ★
    ");
    foreach ($items as $item) {
        $itemId = (int)$item['item_id'];
        $qty    = (int)$item['qty'];
        
        $stockCheck->execute([':id' => $itemId]);
        $menuItem = $stockCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$menuItem) {
            throw new RuntimeException("Menu item not found: ID {$itemId}");
        }
        
        if ((int)$menuItem['stock_qty'] < $qty) {
            throw new RuntimeException(
                "Insufficient stock for {$menuItem['name']}: " .
                "Need {$qty}, available {$menuItem['stock_qty']}"
            );
        }
    }
    // ★★★ Stock validation passed - now safe to deduct ★★★
    // FOR UPDATE lock ensures no other transaction can modify these items

    foreach ($items as $item) {
        $itemId   = (int)$item['item_id'];
        $qty      = (int)$item['qty'];
        $price    = (int)$item['price'];
        $itemName = sanitizeStr($item['name'] ?? '');

        $stockStmt->execute([':qty'=>$qty, ':qty_check'=>$qty, ':id'=>$itemId]);
        if ($stockStmt->rowCount() === 0) throw new RuntimeException("Insufficient stock for: {$itemName}");

        $itemStmt->execute([
            ':order_id'      => $orderId,
            ':item_id'       => $itemId,
            ':item_name'     => $itemName,
            ':item_price'    => $price,
            ':item_qty'      => $qty,
            ':item_subtotal' => $price * $qty,
        ]);
        $orderItemId = (int)$pdo->lastInsertId();

        $stRow = $pdo->prepare("SELECT station FROM menu_items WHERE id=:id");
        $stRow->execute([':id'=>$itemId]);
        $itemStation = $stRow->fetchColumn() ?: 'kitchen';
        $pdo->prepare("UPDATE order_items SET station=:s WHERE id=:id")->execute([':s'=>$itemStation, ':id'=>$orderItemId]);

        $modifiers = $item['modifiers'] ?? [];
        if (!empty($modifiers)) {
            $modStmt  = $pdo->prepare("INSERT INTO order_item_modifiers (order_item_id,group_id,option_id,group_name,label,price_add,free_text) VALUES (:oiid,:gid,:oid,:gname,:label,:price,:txt)");
            $modTotal = 0;
            foreach ($modifiers as $mod) {
                $priceAdd = (int)($mod['price_add'] ?? 0);
                $modTotal += $priceAdd;
                $modStmt->execute([
                    ':oiid'  => $orderItemId,
                    ':gid'   => $mod['group_id']  ?: null,
                    ':oid'   => $mod['option_id']  ?: null,
                    ':gname' => sanitizeStr($mod['group_name'] ?? ''),
                    ':label' => sanitizeStr($mod['label']      ?? ''),
                    ':price' => $priceAdd,
                    ':txt'   => $mod['free_text'] ? sanitizeStr($mod['free_text']) : null,
                ]);
            }
            $pdo->prepare("UPDATE order_items SET modifier_total=:m WHERE id=:id")->execute([':m'=>$modTotal, ':id'=>$orderItemId]);
        }
    }

    $stRes = $pdo->prepare("SELECT DISTINCT station FROM order_items WHERE order_id=:oid");
    $stRes->execute([':oid'=>$orderId]);
    $stations = $stRes->fetchAll(PDO::FETCH_COLUMN);
    if (empty($stations)) $stations = ['kitchen'];

    foreach ($stations as $st) {
        if ($isAppend) {
            $ex = $pdo->prepare("SELECT id FROM kds_queue WHERE order_id=:oid AND station=:st LIMIT 1");
            $ex->execute([':oid'=>$orderId, ':st'=>$st]);
            $kqId = $ex->fetchColumn();
            if ($kqId) {
                $pdo->prepare("UPDATE kds_queue SET status='pending',pushed_at=NOW() WHERE id=:id")->execute([':id'=>$kqId]);
            } else {
                $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,branch_id,tenant_id,pushed_at) VALUES (:oid,:st,'pending',:bid,:tid,NOW())")->execute([':oid'=>$orderId, ':st'=>$st, ':bid'=>$branchId, ':tid'=>$tenantId]);
            }
        } else {
            $pdo->prepare("INSERT INTO kds_queue (order_id,station,status,branch_id,tenant_id,pushed_at) VALUES (:oid,:st,'pending',:bid,:tid,NOW())")->execute([':oid'=>$orderId, ':st'=>$st, ':bid'=>$branchId, ':tid'=>$tenantId]);
        }
    }

    $pdo->commit();

} catch (RuntimeException $e) {
    $pdo->rollBack();
    jsonError($e->getMessage(), 409);
} catch (PDOException $e) {
    $pdo->rollBack();
    logError('Transaction: '.$e->getMessage());
    jsonError('Order could not be saved', 500);
}

// Get the UUID for this order
$uuidRow = $pdo->prepare("SELECT order_uuid FROM orders WHERE id=?");
$uuidRow->execute([$orderId]);
$orderUuid = $uuidRow->fetchColumn() ?: null;

echo json_encode([
    'success'           => true,
    'order_id'          => 'NH-'.str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
    'db_id'             => $orderId,
    'order_uuid'        => $orderUuid,
    'message'           => $isAppend ? 'Items added to your table order' : 'Order placed successfully',
    'is_append'         => $isAppend,
    'estimated_minutes' => 30,
]);

// Server-side loyalty stamp
$customerPhone = sanitizeStr($customer['phone'] ?? '');
if (!empty($customerPhone)) {
    try {
        $cfg = $pdo->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('loyalty_enabled','loyalty_stamps_required')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($cfg['loyalty_enabled'] ?? '1') === '1') {
            $pdo->prepare("INSERT INTO loyalty_cards(phone,stamps,last_order_id) VALUES(?,1,?)
                ON DUPLICATE KEY UPDATE stamps=stamps+1, last_order_id=?, updated_at=NOW()")
                ->execute([$customerPhone, $orderId, $orderId]);
        }
    } catch(Exception $e) { /* stamp fail သည် order ကို မထိ */ }
}

// ── Order Hooks (direct PHP — no HTTP overhead) ──
require_once __DIR__ . '/order_hooks.php';

hookCrmUpsert($pdo, $customerPhone ?? '', sanitizeStr($customer['name'] ?? ''),
    $paymentMethod, $orderId, $total, $items);

hookShiftAssign($pdo, $orderId);

hookStockDeduct($pdo, $orderId, $items);

hookDeliveryTrack($pdo, $orderId, $orderType);

exit;

