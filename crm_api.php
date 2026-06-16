<?php
/**
 * NoodleHaus — CRM API  (Phase 5A)
 * Endpoint: /crm_api.php?action=...
 *
 * Actions:
 *   GET  profile          — phone တစ်ခုရဲ့ full profile
 *   GET  list             — admin customer list (paginated, searchable)
 *   GET  top_items        — customer ရဲ့ favourite items
 *   GET  last_order       — reorder အတွက် last order items
 *   POST upsert           — order_handler ကနေ call — profile sync
 *   POST update_tag       — admin: tag/notes update
 *   POST save_reorder     — customer: template သိမ်း
 *   GET  reorder_template — saved template ထုတ်
 *
 * Rule: orders / loyalty_cards / order_items တွေကို READ သာ — မထိ
 */

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = getPDO();
$action = trim($_GET['action'] ?? '');

/* ── helpers ── */
function ok(mixed $data = []): never {
    echo json_encode(array_merge(['ok' => true], (array)$data), JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}
function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Accept both super-admin and tenant sessions
    $isAdmin  = !empty($_SESSION['admin']);
    $isTenant = !empty($_SESSION['tenant_admin']);
    if (!$isAdmin && !$isTenant) fail('Unauthorized', 401);
}
function cleanPhone(string $p): string {
    return trim(preg_replace('/\s+/', '', $p));
}


/* ════════════════════════════════════════════════════════════════
   GET  profile?phone=09xxx
   Returns full CRM profile + loyalty + stats
   ════════════════════════════════════════════════════════════════ */
if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = cleanPhone($_GET['phone'] ?? '');
    if (!$phone) fail('No phone');

    // customers profile (may not exist yet for brand-new phone)
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // loyalty card (existing table — read only)
    $loy = $pdo->prepare("SELECT stamps, total_redeemed FROM loyalty_cards WHERE phone = ?");
    $loy->execute([$phone]);
    $loyalty = $loy->fetch(PDO::FETCH_ASSOC) ?: ['stamps' => 0, 'total_redeemed' => 0];

    // top 5 favourite items
    $fav = $pdo->prepare("
        SELECT cfi.item_name, cfi.order_count, cfi.menu_item_id,
               mi.price, mi.emoji
        FROM   customer_favourite_items cfi
        LEFT JOIN menu_items mi ON mi.id = cfi.menu_item_id
        WHERE  cfi.customer_phone = ?
        ORDER  BY cfi.order_count DESC
        LIMIT  5
    ");
    $fav->execute([$phone]);
    $favourites = $fav->fetchAll(PDO::FETCH_ASSOC);

    // recent 5 orders (read-only from orders table)
    $ord = $pdo->prepare("
        SELECT o.id, o.total_amount, o.status, o.order_type,
               o.payment_method, o.created_at,
               GROUP_CONCAT(oi.item_name, ' x', oi.qty
                   ORDER BY oi.id SEPARATOR ', ') AS items_summary
        FROM   orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE  o.customer_phone = ? AND o.deleted_at IS NULL
        GROUP  BY o.id
        ORDER  BY o.created_at DESC
        LIMIT  5
    ");
    $ord->execute([$phone]);
    $recent_orders = $ord->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'profile'       => $profile ?: null,
        'loyalty'       => $loyalty,
        'favourites'    => $favourites,
        'recent_orders' => $recent_orders,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  list?search=&tag=&page=1&per=20
   Admin customer list
   ════════════════════════════════════════════════════════════════ */
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $search = trim($_GET['search'] ?? '');
    $tag    = trim($_GET['tag']    ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $per    = min(100, max(10, (int)($_GET['per'] ?? 20)));
    $offset = ($page - 1) * $per;

    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $where  = ['1=1'];
    $params = [];
    if ($tenantId > 0) {
        $where[]  = 'c.tenant_id = ?';
        $params[] = $tenantId;
    }

    if ($search) {
        $where[]  = '(c.phone LIKE ? OR c.name LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($tag && in_array($tag, ['normal','regular','vip','blocked'])) {
        $where[]  = 'c.tag = ?';
        $params[] = $tag;
    }

    $whereSQL = implode(' AND ', $where);

    $total = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSQL");
    $total->execute($params);
    $totalRows = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.*,
               lc.stamps,
               lc.total_redeemed
        FROM   customers c
        LEFT JOIN loyalty_cards lc ON lc.phone = c.phone
        WHERE  $whereSQL
        ORDER  BY c.last_order_at DESC, c.total_spent DESC
        LIMIT  $per OFFSET $offset
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'customers' => $customers,
        'total'     => $totalRows,
        'page'      => $page,
        'pages'     => (int)ceil($totalRows / $per),
    ]);
}


/* ════════════════════════════════════════════════════════════════
   GET  top_items?phone=09xxx
   Customer ရဲ့ top ordered items (reorder UI အတွက်)
   ════════════════════════════════════════════════════════════════ */
if ($action === 'top_items' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = cleanPhone($_GET['phone'] ?? '');
    if (!$phone) fail('No phone');

    $stmt = $pdo->prepare("
        SELECT cfi.menu_item_id, cfi.item_name, cfi.order_count,
               mi.price, mi.emoji, mi.is_active, mi.stock_qty
        FROM   customer_favourite_items cfi
        LEFT JOIN menu_items mi ON mi.id = cfi.menu_item_id
        WHERE  cfi.customer_phone = ?
        ORDER  BY cfi.order_count DESC
        LIMIT  8
    ");
    $stmt->execute([$phone]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ok(['items' => $items]);
}


/* ════════════════════════════════════════════════════════════════
   GET  last_order?phone=09xxx
   Last order ရဲ့ items (one-click reorder)
   ════════════════════════════════════════════════════════════════ */
if ($action === 'last_order' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = cleanPhone($_GET['phone'] ?? '');
    if (!$phone) fail('No phone');

    // Last order id
    $last = $pdo->prepare("
        SELECT id FROM orders
        WHERE  customer_phone = ? AND deleted_at IS NULL
        ORDER  BY created_at DESC
        LIMIT  1
    ");
    $last->execute([$phone]);
    $orderId = $last->fetchColumn();
    if (!$orderId) ok(['items' => [], 'order_id' => null]);

    // Items from that order — join menu_items to get current stock/price
    $items = $pdo->prepare("
        SELECT oi.menu_item_id, oi.item_name, oi.qty,
               mi.price AS current_price, mi.emoji,
               mi.is_active, mi.stock_qty
        FROM   order_items oi
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE  oi.order_id = ?
    ");
    $items->execute([$orderId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'order_id' => $orderId,
        'items'    => $rows,
    ]);
}


/* ════════════════════════════════════════════════════════════════
   POST upsert
   order_handler.php မှာ order placed ပြီးတိုင်း call မည်
   customers + customer_favourite_items ကို sync လုပ်
   Body: { phone, name, payment_method, order_id, total, items:[{menu_item_id,item_name,qty}] }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'upsert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d       = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone   = cleanPhone($d['phone']          ?? '');
    $name    = trim($d['name']                 ?? '');
    $payment = trim($d['payment_method']       ?? '');
    $orderId = (int)($d['order_id']            ?? 0);
    $total   = (int)($d['total']               ?? 0);
    $items   = $d['items']                     ?? [];

    if (!$phone || !$orderId) fail('Missing phone or order_id');

    // Upsert customer profile — counters increment, name/payment update
    $pdo->prepare("
        INSERT INTO customers
            (phone, name, preferred_payment, total_orders, total_spent, last_order_at)
        VALUES
            (?, ?, ?, 1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            name              = IF(? <> '', ?, name),
            preferred_payment = IF(? <> '', ?, preferred_payment),
            total_orders      = total_orders + 1,
            total_spent       = total_spent + ?,
            last_order_at     = NOW()
    ")->execute([$phone, $name, $payment, $total,
                 $name, $name, $payment, $payment, $total]);

    // Auto-tag: regular (≥3 orders), vip (≥10 orders or ≥100k spent)
    $pdo->prepare("
        UPDATE customers
        SET    tag = CASE
                   WHEN total_orders >= 10 OR total_spent >= 100000 THEN 'vip'
                   WHEN total_orders >= 3                           THEN 'regular'
                   ELSE tag
               END
        WHERE  phone = ? AND tag NOT IN ('blocked')
    ")->execute([$phone]);

    // Update favourite items
    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $itemName   = trim($item['item_name']     ?? '');
        $qty        = max(1, (int)($item['qty']   ?? 1));
        if (!$menuItemId) continue;

        $pdo->prepare("
            INSERT INTO customer_favourite_items
                (customer_phone, menu_item_id, item_name, order_count, last_ordered_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                item_name       = VALUES(item_name),
                order_count     = order_count + ?,
                last_ordered_at = NOW()
        ")->execute([$phone, $menuItemId, $itemName, $qty, $qty]);
    }

    ok(['synced' => true]);
}


/* ════════════════════════════════════════════════════════════════
   POST update_tag   (admin only)
   Body: { phone, tag, notes }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'update_tag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone = cleanPhone($d['phone'] ?? '');
    $tag   = trim($d['tag']         ?? '');
    $notes = trim($d['notes']       ?? '');

    if (!$phone) fail('No phone');
    if (!in_array($tag, ['normal','regular','vip','blocked'])) fail('Invalid tag');

    $pdo->prepare("
        UPDATE customers SET tag = ?, notes = ?, updated_at = NOW() WHERE phone = ?
    ")->execute([$tag, $notes ?: null, $phone]);

    ok();
}


/* ════════════════════════════════════════════════════════════════
   POST save_reorder
   Customer သည် "Save as My Usual" နှိပ်တဲ့အခါ
   Body: { phone, label, items:[{menu_item_id,item_name,qty}] }
   ════════════════════════════════════════════════════════════════ */
if ($action === 'save_reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $phone = cleanPhone($d['phone']  ?? '');
    $label = trim($d['label']        ?? 'My Usual');
    $items = $d['items']             ?? [];

    if (!$phone || empty($items)) fail('Missing phone or items');

    // Delete old template for this phone+label
    $pdo->prepare("DELETE FROM reorder_templates WHERE customer_phone=? AND label=?")
        ->execute([$phone, $label]);

    $stmt = $pdo->prepare("
        INSERT INTO reorder_templates (customer_phone, label, menu_item_id, item_name, qty)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $menuItemId = (int)($item['menu_item_id'] ?? 0);
        $itemName   = trim($item['item_name']     ?? '');
        $qty        = max(1, (int)($item['qty']   ?? 1));
        if (!$menuItemId || !$itemName) continue;
        $stmt->execute([$phone, $label, $menuItemId, $itemName, $qty]);
    }

    ok(['label' => $label]);
}


/* ════════════════════════════════════════════════════════════════
   GET  reorder_template?phone=09xxx&label=My+Usual
   Saved template ထုတ် (index.html reorder button)
   ════════════════════════════════════════════════════════════════ */
if ($action === 'reorder_template' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $phone = cleanPhone($_GET['phone'] ?? '');
    $label = trim($_GET['label']       ?? 'My Usual');
    if (!$phone) fail('No phone');

    $stmt = $pdo->prepare("
        SELECT rt.menu_item_id, rt.item_name, rt.qty,
               mi.price AS current_price, mi.emoji,
               mi.is_active, mi.stock_qty
        FROM   reorder_templates rt
        LEFT JOIN menu_items mi ON mi.id = rt.menu_item_id
        WHERE  rt.customer_phone = ? AND rt.label = ?
        ORDER  BY rt.id
    ");
    $stmt->execute([$phone, $label]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // All saved template labels for this phone
    $labels = $pdo->prepare("
        SELECT DISTINCT label FROM reorder_templates WHERE customer_phone = ?
    ");
    $labels->execute([$phone]);
    $allLabels = $labels->fetchAll(PDO::FETCH_COLUMN);

    ok([
        'label'  => $label,
        'labels' => $allLabels,
        'items'  => $items,
    ]);
}


fail('Unknown action');
