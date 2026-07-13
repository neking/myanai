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
require_once __DIR__ . '/auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrf();
$allowedOrigins = ['https://myanai.net','https://www.myanai.net','http://localhost','http://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if(in_array($origin, $allowedOrigins)) header("Access-Control-Allow-Origin: $origin");
else header('Access-Control-Allow-Origin: https://myanai.net');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo    = getPDO();
// XSS Sanitization helper
function clean(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v ?? ''))), ENT_QUOTES, 'UTF-8');
}

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
    $tid = (int)($_GET['tenant_id'] ?? 0) ?: 1;

    // customers profile (may not exist yet for brand-new phone)
    // As of migration 008, customers is keyed on (tenant_id, phone), so this
    // is now correctly scoped to just this tenant's relationship with them.
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ? AND tenant_id = ?");
    $stmt->execute([$phone, $tid]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // loyalty card — tenant-scoped (loyalty_cards.tenant_id exists)
    $loy = $pdo->prepare("SELECT stamps, total_redeemed FROM loyalty_cards WHERE phone = ? AND tenant_id = ?");
    $loy->execute([$phone, $tid]);
    $loyalty = $loy->fetch(PDO::FETCH_ASSOC) ?: ['stamps' => 0, 'total_redeemed' => 0];

    // top 5 favourite items — customer_favourite_items has no tenant_id column,
    // but menu_item_id always belongs to exactly one tenant's menu, so scope via
    // an INNER JOIN on menu_items.tenant_id instead (also drops any favourite
    // whose item no longer exists, which is correct — nothing to recommend).
    $fav = $pdo->prepare("
        SELECT cfi.item_name, cfi.order_count, cfi.menu_item_id,
               mi.price, mi.emoji
        FROM   customer_favourite_items cfi
        JOIN   menu_items mi ON mi.id = cfi.menu_item_id AND mi.tenant_id = ?
        WHERE  cfi.customer_phone = ?
        ORDER  BY cfi.order_count DESC
        LIMIT  5
    ");
    $fav->execute([$tid, $phone]);
    $favourites = $fav->fetchAll(PDO::FETCH_ASSOC);

    // recent 5 orders — tenant-scoped (orders.tenant_id exists)
    $ord = $pdo->prepare("
        SELECT o.id, o.total_amount, o.status, o.order_type,
               o.payment_method, o.created_at,
               GROUP_CONCAT(oi.item_name, ' x', oi.qty
                   ORDER BY oi.id SEPARATOR ', ') AS items_summary
        FROM   orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE  o.customer_phone = ? AND o.tenant_id = ? AND o.deleted_at IS NULL
        GROUP  BY o.id
        ORDER  BY o.created_at DESC
        LIMIT  5
    ");
    $ord->execute([$phone, $tid]);
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
        LEFT JOIN loyalty_cards lc ON lc.phone = c.phone AND lc.tenant_id = c.tenant_id
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
    $tid = (int)($_GET['tenant_id'] ?? 0) ?: 1;

    $stmt = $pdo->prepare("
        SELECT cfi.menu_item_id, cfi.item_name, cfi.order_count,
               mi.price, mi.emoji, mi.is_active, mi.stock_qty
        FROM   customer_favourite_items cfi
        JOIN   menu_items mi ON mi.id = cfi.menu_item_id AND mi.tenant_id = ?
        WHERE  cfi.customer_phone = ?
        ORDER  BY cfi.order_count DESC
        LIMIT  8
    ");
    $stmt->execute([$tid, $phone]);
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

    $tid = (int)($_GET['tenant_id'] ?? 0) ?: 1;

    // Last order id — scoped to this tenant, since a customer's most recent
    // order platform-wide could belong to a completely different restaurant
    $last = $pdo->prepare("
        SELECT id FROM orders
        WHERE  customer_phone = ? AND tenant_id = ? AND deleted_at IS NULL
        ORDER  BY created_at DESC
        LIMIT  1
    ");
    $last->execute([$phone, $tid]);
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
    $tid     = (int)($d['tenant_id']           ?? 0) ?: 1;
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
            (tenant_id, phone, name, preferred_payment, total_orders, total_spent, last_order_at)
        VALUES
            (?, ?, ?, ?, 1, ?, NOW())
        ON DUPLICATE KEY UPDATE
            name              = IF(? <> '', ?, name),
            preferred_payment = IF(? <> '', ?, preferred_payment),
            total_orders      = total_orders + 1,
            total_spent       = total_spent + ?,
            last_order_at     = NOW()
    ")->execute([$tid, $phone, $name, $payment, $total,
                 $name, $name, $payment, $payment, $total]);

    // Auto-tag: regular (≥3 orders), vip (≥10 orders or ≥100k spent)
    $pdo->prepare("
        UPDATE customers
        SET    tag = CASE
                   WHEN total_orders >= 10 OR total_spent >= 100000 THEN 'vip'
                   WHEN total_orders >= 3                           THEN 'regular'
                   ELSE tag
               END
        WHERE  phone = ? AND tenant_id = ? AND tag NOT IN ('blocked')
    ")->execute([$phone, $tid]);

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
    $tid   = (int)($d['tenant_id']  ?? 0);

    if (!$phone) fail('No phone');
    if (!in_array($tag, ['normal','regular','vip','blocked'])) fail('Invalid tag');

    // tenant_id is optional here for backward compatibility with the current
    // super-admin caller (admin_modules.js), which doesn't send one yet — if
    // provided, scope to that tenant's row; otherwise fall back to matching
    // by phone alone (affects every tenant's row for that phone, same as
    // before migration 008 — fine for a trusted super-admin tool, but should
    // be updated to always pass tenant_id once that UI has a tenant selector).
    if ($tid > 0) {
        $pdo->prepare("
            UPDATE customers SET tag = ?, notes = ?, updated_at = NOW() WHERE phone = ? AND tenant_id = ?
        ")->execute([$tag, $notes ?: null, $phone, $tid]);
    } else {
        $pdo->prepare("
            UPDATE customers SET tag = ?, notes = ?, updated_at = NOW() WHERE phone = ?
        ")->execute([$tag, $notes ?: null, $phone]);
    }

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


// Unknown action handled below


/* ════════════════════════════════════════════════════════════════
   CUSTOMER SEGMENTATION
   GET  segment?tenant_id=&type=vip|regular|at_risk|new|churned
   ════════════════════════════════════════════════════════════════ */
if ($action === 'segment') {
    requireAdmin();
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    $type     = trim($_GET['type'] ?? 'all');
    if (!$tenantId) fail('tenant_id required');

    // Build segment conditions
    $segmentWhere = match($type) {
        'vip'     => "c.tag='vip' OR c.total_spent >= 100000 OR c.total_orders >= 10",
        'regular' => "c.total_orders BETWEEN 3 AND 9 AND c.tag != 'vip'",
        'new'     => "c.total_orders < 3 AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'at_risk' => "c.last_order_at < DATE_SUB(NOW(), INTERVAL 21 DAY) AND c.total_orders >= 3",
        'churned' => "c.last_order_at < DATE_SUB(NOW(), INTERVAL 60 DAY)",
        'blocked' => "c.tag = 'blocked'",
        default   => "1=1",
    };

    $stmt = $pdo->prepare("
        SELECT c.phone, c.name, c.tag, c.total_orders, c.total_spent,
               c.last_order_at, lc.stamps,
               DATEDIFF(NOW(), c.last_order_at) AS days_since_order
        FROM customers c
        LEFT JOIN loyalty_cards lc ON lc.phone=c.phone AND lc.tenant_id=?
        WHERE EXISTS (
            SELECT 1 FROM orders o WHERE o.customer_phone=c.phone AND o.tenant_id=?
        ) AND ($segmentWhere)
        ORDER BY c.total_spent DESC
        LIMIT 100
    ");
    $stmt->execute([$tenantId, $tenantId]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats per segment
    $stats = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN c.tag='vip' OR c.total_spent>=100000 OR c.total_orders>=10 THEN 1 END) AS vip,
            COUNT(CASE WHEN c.total_orders BETWEEN 3 AND 9 AND c.tag!='vip' THEN 1 END)            AS regular,
            COUNT(CASE WHEN c.total_orders < 3 AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) AS new_customers,
            COUNT(CASE WHEN c.last_order_at < DATE_SUB(NOW(), INTERVAL 21 DAY) AND c.total_orders>=3 THEN 1 END) AS at_risk,
            COUNT(CASE WHEN c.last_order_at < DATE_SUB(NOW(), INTERVAL 60 DAY) THEN 1 END)         AS churned,
            COUNT(*) AS total
        FROM customers c
        WHERE EXISTS (SELECT 1 FROM orders o WHERE o.customer_phone=c.phone AND o.tenant_id=?)
    ");
    $stats->execute([$tenantId]);
    $summary = $stats->fetch(PDO::FETCH_ASSOC);

    ok(['customers' => $customers, 'summary' => $summary, 'segment' => $type]);
}

/* ════════════════════════════════════════════════════════════════
   AUTO-TAG customers based on behavior
   POST  auto_tag?tenant_id=
   ════════════════════════════════════════════════════════════════ */
if ($action === 'auto_tag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    if (!$tenantId) fail('tenant_id required');

    // Update VIP: 10+ orders or 100k+ spent
    $pdo->prepare("
        UPDATE customers c SET c.tag='vip'
        WHERE c.tag NOT IN ('blocked')
        AND EXISTS (SELECT 1 FROM orders o WHERE o.customer_phone=c.phone AND o.tenant_id=?)
        AND (c.total_orders >= 10 OR c.total_spent >= 100000)
    ")->execute([$tenantId]);

    // Update regular: 3-9 orders
    $pdo->prepare("
        UPDATE customers c SET c.tag='regular'
        WHERE c.tag NOT IN ('vip','blocked')
        AND EXISTS (SELECT 1 FROM orders o WHERE o.customer_phone=c.phone AND o.tenant_id=?)
        AND c.total_orders BETWEEN 3 AND 9
    ")->execute([$tenantId]);

    ok(['msg' => 'Customer tags updated']);
}

fail('Unknown action');
