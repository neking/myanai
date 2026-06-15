<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
$pdo = getPDO();
$csrfToken = generateCsrfToken();

/* ── ADMIN LOGIN ──
   Password ပြောင်းချင်ရင်:
   1. admin.php ဖွင့်ပါ
   2. ADMIN_PASS_HASH ကို PHP တွင် password_hash('yourpassword', PASSWORD_BCRYPT) ဖြင့် generate လုပ်
   3. ဒါမှမဟုတ် http://localhost/myanai/genhash.php မှ copy ပါ
── */
define('ADMIN_USER', 'admin');
// bcrypt hash of 'myanai2024' — genhash.php သုံးပြီး ပြောင်းနိုင်
define('ADMIN_PASS_HASH', '$2y$12$DwR3F2j7J6W7kOwP2upfF.jaE7O64EBTjimp8UO4qI2bueDIwDtV2');  // ← blank ဆိုရင် auto-set ဖြစ်မည်

/* ── DB ── */

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function parseCsvLine(string $line): array
{
    // Strip trailing \r (Windows line endings)
    $line   = rtrim($line, "\r\n");
    $result = [];
    $len    = mb_strlen($line,'UTF-8');
    $i      = 0;
    $field  = '';

    while ($i < $len) {
        $ch = mb_substr($line,$i,1,'UTF-8');
        if ($ch === '"') {
            $i++;
            while ($i < $len) {
                $c2 = mb_substr($line,$i,1,'UTF-8');
                if ($c2 === '"' && mb_substr($line,$i+1,1,'UTF-8') === '"') {
                    $field .= '"'; $i += 2;          // escaped ""
                } elseif ($c2 === '"') {
                    $i++; break;                     // closing quote
                } else {
                    $field .= $c2; $i++;
                }
            }
        } elseif ($ch === ',') {
            $result[] = $field;                      // save field as-is (no trim — preserves Unicode)
            $field    = '';
            $i++;
        } else {
            $field .= $ch;
            $i++;
        }
    }
    $result[] = $field;

    // Trim only leading/trailing ASCII whitespace from each field (not Unicode chars)
    return array_map(fn($f) => trim($f, " \t\r\n\0\x0B"), $result);
}

function sanitize(mixed $v): string {
    return htmlspecialchars(strip_tags(trim((string)($v??''))), ENT_QUOTES, 'UTF-8');
}

/* ── HANDLE ACTIONS (JSON API) ── */
if (isset($_GET['api'])) { // GET+POST both handled
    header('Content-Type: application/json; charset=utf-8');

    /* login */
    if ($_GET['api'] === 'login') {
        $b = json_decode(file_get_contents('php://input'), true);
        $inputUser = $b['user'] ?? '';
        $inputPass = $b['pass'] ?? '';

        // ── Brute-force lockout ──────────────────────────────
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $lockFile  = sys_get_temp_dir() . '/nh_fail_' . md5($ip) . '.json';
        $maxFails  = 5;
        $lockMins  = 15;
        $lockData  = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : ['fails'=>0,'until'=>0];
        if (time() < ($lockData['until'] ?? 0)) {
            $remaining = ceil(($lockData['until'] - time()) / 60);
            echo json_encode(['ok'=>false,'msg'=>"Too many failed attempts. Try again in {$remaining} minute(s)."]);
            exit;
        }
        // ────────────────────────────────────────────────────

        $hash = ADMIN_PASS_HASH ?: password_hash('noodlehaus2024', PASSWORD_BCRYPT);
        // Demo user (read-only demo access)
        $demoHash = '$2y$12$m/erGB4KFb4H/x/f6EA/zuri0Ekl8aXe88FF6nk2kpwppTuYI0kFq';
        if ($inputUser === 'demo' && password_verify($inputPass, $demoHash)) {
            $_SESSION['admin'] = true;
            $_SESSION['demo_mode'] = true;
            $_SESSION['login_time'] = time();
            @unlink($lockFile);
            echo json_encode(['ok'=>true,'demo'=>true]);
            exit;
        }
        // Tenant login: email + password stored in tenants.settings
        $isTenantLogin = false;
        if (!empty($inputUser) && str_contains($inputUser, '@')) {
            $tRow = getPDO()->prepare("SELECT id, name, slug, plan, plan_expires, is_active, settings FROM tenants WHERE owner_email=? AND is_active=1");
            $tRow->execute([$inputUser]);
            $tenant = $tRow->fetch(PDO::FETCH_ASSOC);
            if ($tenant) {
                $tSettings = json_decode($tenant['settings'] ?? '{}', true);
                $tHash = $tSettings['admin_pass_hash'] ?? '';
                if ($tHash && password_verify($inputPass, $tHash)) {
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['admin'] = ['user' => $inputUser, 'role' => 'tenant'];
                    $_SESSION['tenant_id'] = $tenant['id'];
                    $_SESSION['tenant_slug'] = $tenant['slug'];
                    $_SESSION['tenant_name'] = $tenant['name'];
                    $_SESSION['tenant_plan'] = $tenant['plan'];
                    $_SESSION['tenant_plan_expires'] = $tenant['plan_expires'] ?? null;
                    $_SESSION['login_time'] = time();
                    echo json_encode(['ok' => true, 'role' => 'tenant', 'tenant' => $tenant['slug']]);
                    exit;
                }
            }
        }

        if ($inputUser === ADMIN_USER && password_verify($inputPass, $hash)) {
            // Reset rate limit on successful login
            $_SESSION['login_attempts'] = 0;
            $_SESSION['admin'] = true;
            $_SESSION['login_time'] = time();
            // Reset fail counter on success
            @unlink($lockFile);
            echo json_encode(['ok'=>true]);
        } else {
            usleep(200000);
            $lockData['fails'] = ($lockData['fails'] ?? 0) + 1;
            $remaining = $maxFails - $lockData['fails'];
            if ($lockData['fails'] >= $maxFails) {
                $lockData['until'] = time() + ($lockMins * 60);
                $lockData['fails'] = 0;
                file_put_contents($lockFile, json_encode($lockData));
                echo json_encode(['ok'=>false,'msg'=>"Too many failed attempts. Locked for {$lockMins} minutes."]);
            } else {
                file_put_contents($lockFile, json_encode($lockData));
                echo json_encode(['ok'=>false,'msg'=>"Wrong username or password. {$remaining} attempt(s) remaining."]);
            }
        }
        exit;
    }

    /* logout — auth check မတိုင်မီ စစ် */
    if ($_GET['api'] === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Auth check
    if (empty($_SESSION['admin'])) { http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Not logged in']); exit; }
    if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Session expired']); exit;
    }

    /* get items */
    if ($_GET['api'] === 'items') {
        $tid = $GLOBALS['_SESS_TENANT_ID'] ?? 0;
        if ($tid > 0) {
            // Tenant login — show only their items
            $stmt = db()->prepare("SELECT * FROM menu_items WHERE tenant_id=:tid ORDER BY is_active DESC, sort_order ASC, category, name");
            $stmt->execute([':tid' => $tid]);
        } else {
            // Super-admin — show all (optionally filtered by tenant_id param)
            $filterTid = (int)($_GET['tenant_id'] ?? 0);
            if ($filterTid > 0) {
                $stmt = db()->prepare("SELECT * FROM menu_items WHERE tenant_id=:tid ORDER BY is_active DESC, sort_order ASC, category, name");
                $stmt->execute([':tid' => $filterTid]);
            } else {
                $stmt = db()->query("SELECT * FROM menu_items ORDER BY is_active DESC, sort_order ASC, category, name");
            }
        }
        $rows = $stmt->fetchAll();
        echo json_encode(['ok'=>true,'items'=>$rows]);
        exit;
    }

    /* add item */
    if ($_GET['api'] === 'add') {
        $b = json_decode(file_get_contents('php://input'), true);
        $tid = $GLOBALS['_SESS_TENANT_ID'] ?? 0;

        // Plan limit check for tenant
        if ($tid > 0) {
            $limitRow = db()->prepare("SELECT max_menu_items FROM tenants WHERE id=?");
            $limitRow->execute([$tid]);
            $maxItems = (int)($limitRow->fetchColumn() ?: 20);
            $curCount = (int)db()->prepare("SELECT COUNT(*) FROM menu_items WHERE tenant_id=? AND is_active=1")->execute([$tid]) ? 0 : 0;
            $cntStmt  = db()->prepare("SELECT COUNT(*) FROM menu_items WHERE tenant_id=? AND is_active=1");
            $cntStmt->execute([$tid]);
            $curCount = (int)$cntStmt->fetchColumn();
            if ($curCount >= $maxItems) {
                echo json_encode(['ok'=>false,'msg'=>"Plan limit reached ({$curCount}/{$maxItems} items). Please upgrade your plan."]);
                exit;
            }
        }

        $s = db()->prepare("INSERT INTO menu_items (tenant_id,name,category,description,price,stock_qty,emoji,is_active) VALUES (:tid,:n,:c,:d,:p,:s,:e,1)");
        $s->execute([
            ':tid'=>($tid > 0 ? $tid : 1),
            ':n'=>sanitize($b['name']),   ':c'=>sanitize($b['category']),
            ':d'=>sanitize($b['desc']),   ':p'=>(int)$b['price'],
            ':s'=>(int)$b['stock'],       ':e'=>sanitize($b['emoji']),
        ]);
        echo json_encode(['ok'=>true,'id'=>db()->lastInsertId()]);
        exit;
    }

    /* update item */
    if ($_GET['api'] === 'update') {
        $b = json_decode(file_get_contents('php://input'), true);
        $tid = $GLOBALS['_SESS_TENANT_ID'] ?? 0;

        // Tenant ownership check
        if ($tid > 0) {
            $own = db()->prepare("SELECT id FROM menu_items WHERE id=? AND tenant_id=?");
            $own->execute([(int)$b['id'], $tid]);
            if (!$own->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Not authorized']); exit; }
        }

        $station = in_array($b['station']??'', ['kitchen','counter','bar','all']) ? $b['station'] : 'kitchen';
        $s = db()->prepare("UPDATE menu_items SET name=:n,category=:c,description=:d,price=:p,stock_qty=:s,emoji=:e,is_active=:a,station=:st WHERE id=:id");
        $s->execute([
            ':n'=>sanitize($b['name']),  ':c'=>sanitize($b['category']),
            ':d'=>sanitize($b['desc']), ':p'=>(int)$b['price'],
            ':s'=>(int)$b['stock'],     ':e'=>sanitize($b['emoji']),
            ':a'=>(int)$b['active'],    ':st'=>$station, ':id'=>(int)$b['id'],
        ]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* restock only */
    if ($_GET['api'] === 'restock') {
        $b  = json_decode(file_get_contents('php://input'), true);
        $item_id = (int)$b['id'];
        $qty_add = (int)$b['qty'];
        // get current qty and name before update
        $cur = db()->prepare("SELECT name, stock_qty, unit FROM menu_items WHERE id=:id");
        $cur->execute([':id'=>$item_id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        $qty_before = (float)($row['stock_qty'] ?? 0);
        $qty_after  = $qty_before + $qty_add;
        // update stock
        $s = db()->prepare("UPDATE menu_items SET stock_qty = stock_qty + :qty WHERE id = :id");
        $s->execute([':qty'=>$qty_add, ':id'=>$item_id]);
        // write log
        require_once __DIR__.'/stock_log_helper.php';
        write_stock_log(
            db(),
            $item_id,
            $row['name'] ?? 'Unknown',
            $qty_add >= 0 ? 'add' : 'remove',
            $qty_before,
            $qty_after,
            $row['unit'] ?? '',
            $b['reason'] ?? 'Restock',
            (int)($_SESSION['user_id'] ?? 0),
            $_SESSION['user_name'] ?? 'Admin',
            (int)($_SESSION['branch_id'] ?? 1),
            $_SESSION['branch_name'] ?? ''
        );
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* toggle active */
    if ($_GET['api'] === 'toggle') {
        $b = json_decode(file_get_contents('php://input'), true);
        db()->prepare("UPDATE menu_items SET is_active = NOT is_active WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* delete */
    if ($_GET['api'] === 'delete') {
        $b = json_decode(file_get_contents('php://input'), true);
        $tid = $GLOBALS['_SESS_TENANT_ID'] ?? 0;

        // Tenant ownership check
        if ($tid > 0) {
            $own = db()->prepare("SELECT id FROM menu_items WHERE id=? AND tenant_id=?");
            $own->execute([(int)$b['id'], $tid]);
            if (!$own->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Not authorized']); exit; }
        }

        // Soft delete — FK constraint ကြောင့် hard delete မဖြစ်
        $check = db()->prepare("SELECT COUNT(*) FROM order_items WHERE menu_item_id=:id");
        $check->execute([':id'=>(int)$b['id']]);
        if ((int)$check->fetchColumn() > 0) {
            // Has order history — deactivate only
            db()->prepare("UPDATE menu_items SET is_active=0 WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        } else {
            // No orders — safe to hard delete
            db()->prepare("DELETE FROM modifier_groups WHERE menu_item_id=:id")->execute([':id'=>(int)$b['id']]);
            db()->prepare("DELETE FROM menu_items WHERE id=:id")->execute([':id'=>(int)$b['id']]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* reorder menu items */
    if ($_GET['api'] === 'reorder') {
        $b    = json_decode(file_get_contents('php://input'), true);
        $ids  = $b['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) { echo json_encode(['ok'=>false,'msg'=>'No ids']); exit; }
        $stmt = db()->prepare("UPDATE menu_items SET sort_order=:o WHERE id=:id");
        foreach ($ids as $order => $id) {
            $stmt->execute([':o' => ($order + 1) * 10, ':id' => (int)$id]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* ── MODIFIER APIs ── */

    /* get modifiers for a menu item */
    if ($_GET['api'] === 'get_modifiers') {
        $itemId = (int)($_GET['item_id'] ?? 0);
        if (!$itemId) { echo json_encode(['ok'=>false,'msg'=>'No item_id']); exit; }
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
        }
        echo json_encode(['ok'=>true,'groups'=>$groups]);
        exit;
    }

    /* save modifier group (add or update) */
    if ($_GET['api'] === 'save_modifier_group') {
        $b = json_decode(file_get_contents('php://input'), true);
        $itemId   = (int)($b['menu_item_id'] ?? 0);
        $name     = trim($b['name'] ?? '');
        $type     = in_array($b['type']??'', ['single','multi','text']) ? $b['type'] : 'single';
        $required = (int)($b['required'] ?? 0);
        $sortOrder= (int)($b['sort_order'] ?? 0);
        $gid      = (int)($b['id'] ?? 0);
        if (!$itemId || !$name) { echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit; }
        if ($gid) {
            db()->prepare("UPDATE modifier_groups SET name=:n,type=:t,required=:r,sort_order=:s WHERE id=:id AND menu_item_id=:mid")
                ->execute([':n'=>$name,':t'=>$type,':r'=>$required,':s'=>$sortOrder,':id'=>$gid,':mid'=>$itemId]);
        } else {
            $stmt = db()->prepare("INSERT INTO modifier_groups (menu_item_id,name,type,required,sort_order) VALUES (:mid,:n,:t,:r,:s)");
            $stmt->execute([':mid'=>$itemId,':n'=>$name,':t'=>$type,':r'=>$required,':s'=>$sortOrder]);
            $gid = (int)db()->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$gid]);
        exit;
    }

    /* delete modifier group */
    if ($_GET['api'] === 'delete_modifier_group') {
        $b = json_decode(file_get_contents('php://input'), true);
        $gid = (int)($b['id'] ?? 0);
        if (!$gid) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        db()->prepare("DELETE FROM modifier_groups WHERE id=:id")->execute([':id'=>$gid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* save modifier option (add or update) */
    if ($_GET['api'] === 'save_modifier_option') {
        $b = json_decode(file_get_contents('php://input'), true);
        $gid       = (int)($b['group_id'] ?? 0);
        $label     = trim($b['label'] ?? '');
        $priceAdd  = (int)($b['price_add'] ?? 0);
        $isDefault = (int)($b['is_default'] ?? 0);
        $sortOrder = (int)($b['sort_order'] ?? 0);
        $oid       = (int)($b['id'] ?? 0);
        if (!$gid || !$label) { echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit; }
        if ($oid) {
            db()->prepare("UPDATE modifier_options SET label=:l,price_add=:p,is_default=:d,sort_order=:s WHERE id=:id AND group_id=:gid")
                ->execute([':l'=>$label,':p'=>$priceAdd,':d'=>$isDefault,':s'=>$sortOrder,':id'=>$oid,':gid'=>$gid]);
        } else {
            $stmt = db()->prepare("INSERT INTO modifier_options (group_id,label,price_add,is_default,sort_order) VALUES (:gid,:l,:p,:d,:s)");
            $stmt->execute([':gid'=>$gid,':l'=>$label,':p'=>$priceAdd,':d'=>$isDefault,':s'=>$sortOrder]);
            $oid = (int)db()->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$oid]);
        exit;
    }

    /* delete modifier option */
    if ($_GET['api'] === 'delete_modifier_option') {
        $b = json_decode(file_get_contents('php://input'), true);
        $oid = (int)($b['id'] ?? 0);
        if (!$oid) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        db()->prepare("DELETE FROM modifier_options WHERE id=:id")->execute([':id'=>$oid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* update menu item station */
    if ($_GET['api'] === 'update_station') {
        $b = json_decode(file_get_contents('php://input'), true);
        $id      = (int)($b['id'] ?? 0);
        $station = trim($b['station'] ?? 'kitchen');
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'No id']); exit; }
        $allowed = ['kitchen','counter','bar','all'];
        if (!in_array($station, $allowed)) $station = 'kitchen';
        db()->prepare("UPDATE menu_items SET station=:s WHERE id=:id")->execute([':s'=>$station,':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* orders list — deleted_at IS NULL သာ ပြ */
    if ($_GET['api'] === 'orders') {
        $rows = db()->query("
            SELECT o.id, o.branch_id, o.tenant_id, o.customer_name, o.customer_phone, o.total_amount,
                   o.payment_method, o.status, o.created_at, o.delete_reason,
                   COALESCE(GROUP_CONCAT(oi.item_name,'×',oi.qty SEPARATOR ', '),'—') AS items,
                   COALESCE(b.name,'—') AS branch_name,
                   COALESCE(b.code,'—') AS branch_code
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN branches b ON b.id = o.branch_id
            WHERE (o.deleted_at IS NULL OR o.status = 'cancelled')
            GROUP BY o.id ORDER BY o.id DESC LIMIT 200
        ")->fetchAll();
        $bid = (int)($_GET['branch_id'] ?? 0);
        $tid = (int)($_GET['tenant_id'] ?? 0);
        if($bid > 0) $rows = array_values(array_filter($rows, fn($r) => (int)$r['branch_id'] === $bid));
        if($tid > 0) $rows = array_values(array_filter($rows, fn($r) => (int)$r['tenant_id'] === $tid));
        echo json_encode(['ok'=>true,'orders'=>$rows]); exit;
    }

    /* saas tenant list */
    if ($api === 'saas_tenants') {
        requireAdmin();
        $tenants = db()->query("
            SELECT t.*,
                (SELECT COUNT(*) FROM branches b WHERE b.tenant_id=t.id) AS total_branches,
                (SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_orders,
                (SELECT COALESCE(SUM(total_amount),0) FROM orders o WHERE o.tenant_id=t.id AND o.deleted_at IS NULL) AS total_revenue
            FROM tenants t ORDER BY t.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        // Remove sensitive settings (password hash)
        foreach($tenants as &$t) {
            $s = json_decode($t['settings']??'{}',true);
            unset($s['admin_pass_hash']);
            $t['settings'] = json_encode($s);
        }
        echo json_encode(['ok'=>true,'tenants'=>$tenants]); exit;
    }

    /* deleted orders log */
    if ($_GET['api'] === 'deleted_orders') {
        $rows = db()->query("
            SELECT * FROM deleted_orders_log ORDER BY deleted_at DESC LIMIT 100
        ")->fetchAll();
        echo json_encode(['ok'=>true,'orders'=>$rows]);
        exit;
    }

    /* delete order — soft delete + archive */
    if ($_GET['api'] === 'delete_order') {
        $b      = json_decode(file_get_contents('php://input'), true);
        $id     = (int)($b['id'] ?? 0);
        $reason = trim($b['reason'] ?? '');
        if ($id <= 0 || !$reason) { echo json_encode(['ok'=>false,'msg'=>'ID နဲ့ reason လိုသည်']); exit; }

        $pdo = db();
        // 1. order + items snapshot ယူ
        $order = $pdo->prepare("SELECT * FROM orders WHERE id=:id AND deleted_at IS NULL");
        $order->execute([':id'=>$id]);
        $o = $order->fetch();
        if (!$o) { echo json_encode(['ok'=>false,'msg'=>'Order not found']); exit; }

        $items = $pdo->prepare("SELECT item_name,qty,unit_price,subtotal FROM order_items WHERE order_id=:id");
        $items->execute([':id'=>$id]);
        $itemsData = $items->fetchAll();

        // 2. deleted_orders_log ထဲ archive
        $pdo->prepare("
            INSERT INTO deleted_orders_log
                (original_id,order_ref,customer_name,customer_phone,
                 total_amount,payment_method,order_status,items_snapshot,
                 delete_reason,deleted_by,deleted_at)
            VALUES
                (:oid,:ref,:name,:phone,
                 :total,:pay,:status,:items,
                 :reason,'admin',NOW())
        ")->execute([
            ':oid'    => $id,
            ':ref'    => 'NH-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
            ':name'   => $o['customer_name'],
            ':phone'  => $o['customer_phone'],
            ':total'  => $o['total_amount'],
            ':pay'    => $o['payment_method'],
            ':status' => $o['status'],
            ':items'  => json_encode($itemsData, JSON_UNESCAPED_UNICODE),
            ':reason' => $reason,
        ]);

        // 3. orders table မှာ soft delete
        $pdo->prepare("
            UPDATE orders SET deleted_at=NOW(), delete_reason=:reason, deleted_by='admin'
            WHERE id=:id
        ")->execute([':reason'=>$reason, ':id'=>$id]);

        // 4. Cancel hooks — stock restore, CRM adjust, delivery cancel, shift remove
        require_once __DIR__ . '/order_cancel_hooks.php';
        hookStockRestore($pdo, $id);
        hookCrmReverse($pdo, $o['customer_phone'] ?? '', (int)($o['total_amount'] ?? 0));
        hookDeliveryCancel($pdo, $id);
        hookShiftRemove($pdo, $id);

        echo json_encode(['ok'=>true]);
        exit;
    }

    /* image upload */
    /* batch upload CSV/Excel */
    if ($_GET['api'] === 'batch_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['csv'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file = $_FILES['csv'];
        $allowed = ['text/csv','application/csv','application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/plain'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','txt'])) {
            echo json_encode(['ok'=>false,'msg'=>'CSV file သာ upload လုပ်ပါ (.csv)']); exit;
        }
        $raw = file_get_contents($file['tmp_name']);
        // BOM (UTF-8/UTF-16) ဖယ်
        if (substr($raw,0,3) === "\xEF\xBB\xBF") $raw = substr($raw,3);
        if (substr($raw,0,2) === "\xFF\xFE")     $raw = substr($raw,2);
        if (substr($raw,0,2) === "\xFE\xFF")     $raw = substr($raw,2);
        // Windows encoding → UTF-8
        if (!mb_check_encoding($raw,'UTF-8')) {
            $raw = mb_convert_encoding($raw,'UTF-8','auto');
        }
        $content = $raw;
        $lines   = preg_split('/\r\n|\r|\n/', trim($content));
        if (count($lines) < 2) { echo json_encode(['ok'=>false,'msg'=>'Data မပါပါ']); exit; }

        $header = parseCsvLine(array_shift($lines));
        $header = array_map(fn($h) => strtolower(trim($h)), $header);
        $required = ['name','category','price'];
        foreach ($required as $r) {
            if (!in_array($r, $header)) {
                echo json_encode(['ok'=>false,'msg'=>"Column မပါ: {$r}"]); exit;
            }
        }

        $validCats = ['Noodles','Rice','Starters','Soups','Desserts','Drinks'];
        $rows = []; $errors = [];

        foreach ($lines as $lineNum => $line) {
            if (!trim($line)) continue;
            $row = parseCsvLine($line);
            if (count($row) < count($header)) {
                $errors[] = ['row'=>$lineNum+2, 'msg'=>'Column count မကိုက်'];
                continue;
            }
            if (count($row) !== count($header)) {
                // Pad or trim to match header count
                while (count($row) < count($header)) $row[] = '';
                $row = array_slice($row, 0, count($header));
            }
            $data = array_combine($header, $row);
            $name  = $data['name'] ?? '';
            $cat   = $data['category'] ?? '';
            $price = (int)preg_replace('/[^0-9.]/','',$data['price'] ?? '0');
            if (str_contains((string)($data['price']??''), '.')) {
                // dollar.cents format (e.g. 4.50) → multiply by 100 if needed
                // Keep as-is since DB stores display value
                $price = (int)round((float)preg_replace('/[^0-9.]/','',$data['price']??'0'));
            }
            $stock = (int)($data['stock'] ?? $data['stock_qty'] ?? 0);
            $emoji = ($data['emoji'] ?? '') ?: '🍽️';
            $desc  = $data['description'] ?? $data['desc'] ?? '';

            if (!$name)  { $errors[] = ['row'=>$lineNum+2,'msg'=>'Name ဗလာ']; continue; }
            if ($price<0){ $errors[] = ['row'=>$lineNum+2,'msg'=>'Price မမှန်']; continue; }
            // Category mapping — English + Myanmar aliases
            $catAliases = [
                'Noodles'  => ['noodles','noodle','ခေါက်ဆွဲ','မုန့်','မုန်','noodle dish'],
                'Rice'     => ['rice','ထမင်း','ကြော်ထမင်း'],
                'Starters' => ['starters','starter','appetizer','appetisers','အစာဦး','ဆာလောင်မွတ်သိပ်'],
                'Soups'    => ['soups','soup','ဟင်းချို','ဟင်းရည်','ဟင်း'],
                'Desserts' => ['desserts','dessert','အချိုပွဲ','မုန့်ချို','dessert'],
                'Drinks'   => ['drinks','drink','beverage','beverages','အချိုရည်','ဖျော်ရည်','လက်ဖက်ရည်','ကော်ဖီ'],
            ];
            $catMatch = '';
            $catLower = mb_strtolower(trim($cat), 'UTF-8');
            foreach ($catAliases as $canonical => $aliases) {
                // Exact match (case-insensitive)
                if (mb_strtolower($canonical,'UTF-8') === $catLower) {
                    $catMatch = $canonical; break;
                }
                foreach ($aliases as $alias) {
                    if (mb_strtolower($alias,'UTF-8') === $catLower ||
                        mb_stripos($catLower, $alias, 0, 'UTF-8') !== false ||
                        mb_stripos($alias, $catLower, 0, 'UTF-8') !== false) {
                        $catMatch = $canonical; break 2;
                    }
                }
            }
            if (!$catMatch) {
                // Still no match — add error note but use Noodles as default
                $errors[] = ['row'=>$lineNum+2, 'msg'=>"Category မသိ: '{$cat}' → Noodles ထားသည်"];
                $catMatch = 'Noodles';
            }
            $cat = $catMatch;
            $rows[] = compact('name','cat','price','stock','emoji','desc');
        }

        if (empty($rows)) {
            echo json_encode(['ok'=>false,'msg'=>'Valid row မရှိ','errors'=>$errors]); exit;
        }

        // Preview mode (no DB write)
        if (!empty($_POST['preview'])) {
            echo json_encode(['ok'=>true,'preview'=>true,'rows'=>$rows,'errors'=>$errors], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Insert to DB
        $pdo  = db();
        $stmt = $pdo->prepare("
            INSERT INTO menu_items (name,category,description,price,stock_qty,emoji,is_active,sort_order)
            VALUES (:n,:c,:d,:p,:s,:e,1,
                (SELECT COALESCE(MAX(m2.sort_order),0)+10 FROM menu_items m2))
        ");
        $inserted = 0; $skipped = 0;
        foreach ($rows as $r) {
            // Check duplicate name
            $chk = $pdo->prepare("SELECT id FROM menu_items WHERE name=:n LIMIT 1");
            $chk->execute([':n'=>$r['name']]);
            if ($chk->fetch()) { $skipped++; continue; }
            $stmt->execute([
                ':n'=>$r['name'], ':c'=>$r['cat'], ':d'=>$r['desc'],
                ':p'=>$r['price'], ':s'=>$r['stock'], ':e'=>$r['emoji'],
            ]);
            $inserted++;
        }
        echo json_encode([
            'ok'       => true,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_GET['api'] === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP သာ']); exit; }
        if ($file['size'] > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'Max 5MB']); exit; }

        $dir    = __DIR__ . '/uploads/menu/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $itemId = (int)($_POST['item_id'] ?? time());
        $name   = 'item_' . $itemId . '_' . time() . '.jpg';
        $dest   = $dir . $name;

        if (!function_exists('imagecreatefromjpeg')) {
            // GD မရှိ — original ကိုသိမ်း
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['ok'=>false,'msg'=>'Upload failed']); exit;
            }
        } else {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if (!$src) { echo json_encode(['ok'=>false,'msg'=>'Image read failed']); exit; }
            $origW = imagesx($src); $origH = imagesy($src);
            $targetW = 400; $targetH = 300;
            $origR = $origW/$origH; $targetR = $targetW/$targetH;
            if ($origR > $targetR) {
                $cropH=$origH; $cropW=(int)round($origH*$targetR);
                $cropX=(int)round(($origW-$cropW)/2); $cropY=0;
            } else {
                $cropW=$origW; $cropH=(int)round($origW/$targetR);
                $cropX=0; $cropY=(int)round(($origH-$cropH)/2);
            }
            $dst = imagecreatetruecolor($targetW,$targetH);
            imagefill($dst,0,0,imagecolorallocate($dst,255,255,255));
            imagecopyresampled($dst,$src,0,0,$cropX,$cropY,$targetW,$targetH,$cropW,$cropH);
            imagejpeg($dst,$dest,88);
            imagedestroy($src); imagedestroy($dst);
        }

        $relPath = 'uploads/menu/'.$name;
        if ($itemId > 0) {
            $chk = db()->prepare("SELECT id FROM menu_items WHERE id=:id");
            $chk->execute([':id'=>$itemId]);
            if ($chk->fetch()) {
                db()->prepare("UPDATE menu_items SET image_path=:p WHERE id=:id")
                    ->execute([':p'=>$relPath, ':id'=>$itemId]);
            }
        }
        echo json_encode(['ok'=>true,'path'=>$relPath]);
        exit;
    }

    /* upload KPay QR image */
    if ($_GET['api'] === 'upload_kpay_qr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP only']); exit; }
        if ($file['size'] > 3*1024*1024) { echo json_encode(['ok'=>false,'msg'=>'Max 3MB']); exit; }
        $dir  = __DIR__ . '/uploads/kpay/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'kpay_qr_' . time() . '.jpg';
        $dest = $dir . $name;
        $relPath = 'uploads/kpay/' . $name;
        if (function_exists('imagecreatefromjpeg')) {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $dst = imagecreatetruecolor($w, $h);
                imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
                imagejpeg($dst, $dest, 90);
                imagedestroy($src); imagedestroy($dst);
            } else { move_uploaded_file($file['tmp_name'], $dest); }
        } else { move_uploaded_file($file['tmp_name'], $dest); }
        // site_settings မှာ သိမ်း
        $chk = db()->prepare("SELECT setting_key FROM site_settings WHERE setting_key='kpay_qr_image'");
        $chk->execute();
        if ($chk->fetch()) {
            db()->prepare("UPDATE site_settings SET setting_value=:v WHERE setting_key='kpay_qr_image'")->execute([':v'=>$relPath]);
        } else {
            db()->prepare("INSERT INTO site_settings(setting_key,setting_value,label) VALUES('kpay_qr_image',:v,'KPay QR Image')")->execute([':v'=>$relPath]);
        }
        echo json_encode(['ok'=>true,'path'=>$relPath]);
        exit;
    }

    /* upload footer image (bg or logo) */
    if ($_GET['api'] === 'upload_footer_img' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file    = $_FILES['img'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) { echo json_encode(['ok'=>false,'msg'=>'JPG/PNG/GIF/WEBP only']); exit; }
        if ($file['size'] > 3*1024*1024) { echo json_encode(['ok'=>false,'msg'=>'Max 3MB']); exit; }

        $rawType = $_POST['type'] ?? 'bg';
        $type = in_array($rawType, ['logo','bg','header']) ? $rawType : 'bg';
        $subdir = $type === 'header' ? 'uploads/header/' : 'uploads/footer/';
        $dir  = __DIR__ . '/' . $subdir;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $prefix = $type === 'header' ? 'header' : 'footer_'.$type;
        $name = $prefix . '_' . time() . '.jpg';
        $dest = $dir . $name;

        if (function_exists('imagecreatefromjpeg')) {
            $src = match($file['type']) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                'image/webp' => imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                // Max 1200×400 for bg, 400×200 for logo
                $maxW = $type==='bg' ? 1200 : 400;
                $maxH = $type==='bg' ? 400  : 200;
                $scale = min(1, $maxW/$w, $maxH/$h);
                $nw = (int)round($w*$scale); $nh = (int)round($h*$scale);
                $dst = imagecreatetruecolor($nw, $nh);
                // Preserve transparency for PNG
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
                imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
                imagejpeg($dst,$dest,90);
                imagedestroy($src); imagedestroy($dst);
            } else { move_uploaded_file($file['tmp_name'], $dest); }
        } else {
            move_uploaded_file($file['tmp_name'], $dest);
        }
        echo json_encode(['ok'=>true,'path'=>$subdir.$name]);
        exit;
    }

    /* remove image */
    if ($_GET['api'] === 'remove_image') {
        $b  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($b['id'] ?? 0);
        $row = db()->prepare("SELECT image_path FROM menu_items WHERE id=:id");
        $row->execute([':id'=>$id]);
        $r = $row->fetch();
        if ($r && $r['image_path']) {
            $fullPath = __DIR__ . '/' . $r['image_path'];
            if (file_exists($fullPath)) unlink($fullPath);
        }
        db()->prepare("UPDATE menu_items SET image_path=NULL WHERE id=:id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    /* dashboard stats */
    if ($_GET['api'] === 'stats') {
        $pdo = db();
        $bid = (int)($_GET['branch_id'] ?? 0);
        $tid = (int)($_GET['tenant_id'] ?? 0);

        // Build WHERE clause for branch/tenant filter
        $where = "deleted_at IS NULL AND status != 'cancelled'";
        $params = [];
        if ($bid > 0) { $where .= " AND branch_id = ?"; $params[] = $bid; }
        if ($tid > 0) { $where .= " AND tenant_id = ?"; $params[] = $tid; }

        $s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND $where");
        $s->execute($params);
        $today = $s->fetchColumn();

        $s2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at)=CURDATE() AND $where");
        $s2->execute($params);
        $revenue = $s2->fetchColumn();

        // Stock + KDS — always global (not branch-specific)
        $lowstock = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE stock_qty<=5 AND is_active=1")->fetchColumn();
        $pending  = $pdo->query("SELECT COUNT(*) FROM kds_queue WHERE status='pending'")->fetchColumn();

        // Pending orders for this branch
        $pendingWhere = "status='pending'";
        if ($bid > 0) { $pendingWhere .= " AND branch_id=$bid"; }
        $pending = $pdo->query("SELECT COUNT(*) FROM orders WHERE $pendingWhere AND deleted_at IS NULL")->fetchColumn();

        echo json_encode(['ok'=>true,'today'=>$today,'revenue'=>$revenue,'low'=>$lowstock,'pending'=>$pending,'branch_id'=>$bid]);
        exit;
    }

    /* get tenant payment settings */
    if ($_GET['api'] === 'get_payment_settings') {
        $tid = (int)($_SESSION['tenant_id'] ?? 0);
        if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'Tenant only']); exit; }
        $row = db()->prepare("SELECT settings FROM tenants WHERE id=?");
        $row->execute([$tid]);
        $s = json_decode($row->fetchColumn() ?: '{}', true) ?: [];
        echo json_encode(['ok'=>true,'settings'=>[
            'kpay_merchant_id' => $s['kpay_merchant_id'] ?? '',
            'kpay_qr_image'    => $s['kpay_qr_image']    ?? '',
            'wave_merchant_id' => $s['wave_merchant_id'] ?? '',
            'wave_qr_image'    => $s['wave_qr_image']    ?? '',
        ]]);
        exit;
    }

    /* save tenant payment settings */
    if ($_GET['api'] === 'save_payment_settings') {
        $tid = (int)($_SESSION['tenant_id'] ?? 0);
        $isSuperAdmin = !empty($_SESSION['admin']) && ($_SESSION['admin']['role'] ?? '') !== 'tenant';
        if (!$tid && !$isSuperAdmin) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!$tid) $tid = (int)($b['tenant_id'] ?? 0);
        if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

        $row = db()->prepare("SELECT settings FROM tenants WHERE id=?");
        $row->execute([$tid]);
        $existing = json_decode($row->fetchColumn() ?: '{}', true) ?: [];

        foreach (['kpay_merchant_id','kpay_qr_image','wave_merchant_id','wave_qr_image'] as $field) {
            if (array_key_exists($field, $b)) {
                $existing[$field] = sanitize($b[$field]);
            }
        }

        db()->prepare("UPDATE tenants SET settings=? WHERE id=?")->execute([json_encode($existing, JSON_UNESCAPED_UNICODE), $tid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

/* ── GET: serve HTML ── */
$loggedIn = !empty($_SESSION['admin']);

// Tenant isolation: if logged in as tenant, restrict to their tenant_id
$_SESS_TENANT_ID   = (int)($_SESSION['tenant_id'] ?? 0);
$_SESS_TENANT_NAME = $_SESSION['tenant_name'] ?? '';
$_SESS_TENANT_PLAN = $_SESSION['tenant_plan'] ?? '';
$_SESS_PLAN_EXPIRES = $_SESSION['tenant_plan_expires'] ?? null;
$_IS_TENANT        = $_SESS_TENANT_ID > 0 && ($_SESSION['admin']['role'] ?? '') === 'tenant';

// For API calls: auto-inject tenant_id filter when tenant is logged in
if ($_IS_TENANT && !isset($_GET['tenant_id'])) {
    $_GET['tenant_id'] = $_SESS_TENANT_ID;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MyanAi POS — Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&family=Noto+Sans+Myanmar:wght@400;500&family=Noto+Sans+SC:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --ink:#1a1209;--paper:#fdf6ec;--warm:#f5ede0;--accent:#e84c2b;--accent2:#f0a500;
  --muted:#8a7560;--border:#e2d5c3;--card:#ffffff;--green:#2d7a4f;
  --radius:12px;--shadow:0 4px 24px rgba(26,18,9,.10);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans','Noto Sans Myanmar','Noto Sans SC','Noto Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;}

/* LOGIN */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;}
.login-box{background:var(--card);border-radius:20px;padding:2.5rem;width:100%;max-width:380px;box-shadow:var(--shadow);border:1px solid var(--border);}
.login-logo{font-family:'Playfair Display',serif;font-size:1.8rem;text-align:center;margin-bottom:.3rem;}
.login-logo span{color:var(--accent2);}
.login-sub{text-align:center;color:var(--muted);font-size:.85rem;margin-bottom:1.8rem;}

/* LAYOUT */
.app{display:flex;min-height:100vh;}
.sidebar{width:220px;background:var(--ink);color:var(--paper);flex-shrink:0;display:flex;flex-direction:column;}
.sidebar-logo{padding:1.4rem 1.2rem;font-family:'Playfair Display',serif;font-size:1.2rem;border-bottom:1px solid rgba(255,255,255,.1);}
.sidebar-logo span{color:var(--accent2);}
.sidebar-badge{font-size:.7rem;background:rgba(255,255,255,.1);padding:.15rem .5rem;border-radius:4px;margin-left:.4rem;vertical-align:middle;}
nav{flex:1;padding:.8rem 0;}
.nav-item{display:flex;align-items:center;gap:.7rem;padding:.75rem 1.2rem;cursor:pointer;font-size:.88rem;color:rgba(255,255,255,.7);transition:all .15s;border-left:3px solid transparent;}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff;}
.nav-item.active{background:rgba(255,255,255,.1);color:#fff;border-left-color:var(--accent2);}
.nav-icon{font-size:1rem;width:20px;text-align:center;}
.nav-section-header{padding:.75rem 1rem .25rem;font-size:.72rem;font-weight:700;letter-spacing:.08em;color:#f0a500;text-transform:uppercase;user-select:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;transition:color .15s;}
.nav-section-divider{height:1px;background:rgba(255,255,255,.09);margin:.4rem .8rem .3rem;}
#branch-ops-selector{padding:.25rem .7rem .45rem;}
#branch-ops-selector select{width:100%;padding:.32rem .55rem;border-radius:6px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.09);color:#fff;font-size:.76rem;cursor:pointer;outline:none;}

.sidebar-foot{padding:1rem 1.2rem;border-top:1px solid rgba(255,255,255,.1);}
.logout-btn{width:100%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.7);padding:.5rem;border-radius:8px;cursor:pointer;font-size:.82rem;font-family:'DM Sans',sans-serif;transition:all .15s;}
.logout-btn:hover{background:var(--accent);border-color:var(--accent);color:#fff;}

/* MAIN */
.main{flex:1;overflow:auto;}
.page-head{padding:1.4rem 2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--card);position:sticky;top:0;z-index:10;}
.page-title{font-family:'Playfair Display',serif;font-size:1.3rem;}
.content{padding:1.5rem 2rem;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:var(--card);border-radius:var(--radius);padding:1.1rem 1.2rem;box-shadow:var(--shadow);border:1px solid var(--border);}
.stat-label{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;}
.stat-val{font-size:1.6rem;font-weight:700;font-family:'DM Mono',monospace;}
.stat-val.green{color:var(--green);}
.stat-val.red{color:var(--accent);}
.stat-val.amber{color:var(--accent2);}

/* TABLE */
.table-wrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden;}
.table-toolbar{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;}
.search-input{border:1px solid var(--border);border-radius:8px;padding:.45rem .9rem;font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;min-width:200px;}
.search-input:focus{border-color:var(--ink);}
table{width:100%;border-collapse:collapse;}
th{background:var(--warm);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:.7rem 1rem;text-align:left;white-space:nowrap;}
td{padding:.7rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle;}
tr:last-child td{border:none;}
tr:hover td{background:#fef9f4;}
.emoji-cell{font-size:1.4rem;text-align:center;}
.price-cell{font-family:'DM Mono',monospace;white-space:nowrap;}
.stock-pill{display:inline-block;padding:.2rem .6rem;border-radius:50px;font-size:.75rem;font-weight:600;}
.stock-ok  {background:#d1fae5;color:#065f46;}
.stock-low {background:#fef3c7;color:#92400e;}
.stock-out {background:#fee2e2;color:#991b1b;}
.active-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
.dot-on{background:var(--green);}
.dot-off{background:var(--muted);}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;border:none;transition:all .15s;}
.btn-primary{background:var(--ink);color:#fff;}
.btn-primary:hover{background:#333;}
.btn-success{background:var(--green);color:#fff;}
.btn-success:hover{background:#235f3d;}
.btn-warn{background:var(--accent2);color:var(--ink);}
.btn-warn:hover{opacity:.85;}
.btn-danger{background:var(--accent);color:#fff;}
.btn-danger:hover{background:#c8351a;}
.btn-ghost{background:none;border:1px solid var(--border);color:var(--ink);}
.btn-ghost:hover{background:var(--warm);}
.btn-sm{padding:.3rem .7rem;font-size:.78rem;}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;}
@media(max-width:500px){.form-grid{grid-template-columns:1fr;}}
.form-group{margin-bottom:.8rem;}
.form-group label{display:block;font-size:.8rem;font-weight:600;margin-bottom:.3rem;}
.form-group input,.form-group select,.form-group textarea{
  width:100%;border:1.5px solid var(--border);background:var(--card);
  padding:.6rem .9rem;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;outline:none;
  transition:border-color .15s;
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--ink);}
.form-group textarea{resize:vertical;min-height:60px;}
.full-width{grid-column:1/-1;}

/* MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(26,18,9,.5);backdrop-filter:blur(3px);z-index:200;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-bg.open{opacity:1;pointer-events:all;}
.modal{background:var(--paper);border-radius:18px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(26,18,9,.3);transform:translateY(16px);transition:transform .2s;}
.modal-bg.open .modal{transform:translateY(0);}
.modal-head{background:var(--ink);color:var(--paper);padding:1rem 1.3rem;border-radius:18px 18px 0 0;display:flex;align-items:center;justify-content:space-between;}
.modal-head h3{font-family:'Playfair Display',serif;font-size:1.05rem;}
.modal-body{padding:1.3rem;}
.modal-foot{padding:.9rem 1.3rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.6rem;}
.x-btn{background:none;border:none;color:var(--paper);font-size:1.2rem;cursor:pointer;opacity:.7;}
.x-btn:hover{opacity:1;}

/* CATEGORY TABS */
.cat-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;}
.cat-tab{padding:.35rem .9rem;border-radius:50px;border:1px solid var(--border);background:var(--card);font-size:.8rem;font-weight:500;cursor:pointer;transition:all .15s;}
.cat-tab:hover,.cat-tab.on{background:var(--ink);color:#fff;border-color:var(--ink);}

/* TOAST */
.toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:999;background:var(--ink);color:var(--paper);padding:.65rem 1.1rem;border-radius:10px;font-size:.84rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.2);transform:translateY(70px);opacity:0;transition:all .3s ease;max-width:280px;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.ok{border-left:4px solid var(--green);}
.toast.err{border-left:4px solid var(--accent);}

/* ORDERS */
.order-status{display:inline-block;padding:.2rem .6rem;border-radius:50px;font-size:.73rem;font-weight:600;}
.os-pending  {background:#fff3cd;color:#856404;}
.os-preparing{background:#cce5ff;color:#004085;}
.os-ready    {background:#d4edda;color:#155724;}
.os-delivered{background:#d1fae5;color:#065f46;}
.os-cancelled{background:#fee2e2;color:#991b1b;}
.drag-handle{cursor:grab;color:var(--muted);font-size:1rem;padding:0 6px;user-select:none;line-height:1}
.drag-handle:hover{color:var(--ink);}
tr.drag-over td{background:var(--color-background-info,#e6f1fb);}
tr.dragging{opacity:.4;}
tr.drop-above{box-shadow:0 -2px 0 var(--accent);}
tr.drop-below{box-shadow:0 2px 0 var(--accent);}

/* ════ MOBILE RESPONSIVE ════ */
/* Bottom nav bar (mobile only) */
.mobile-nav{
  display:none;
  position:fixed;bottom:0;left:0;right:0;z-index:200;
  background:var(--ink);border-top:1px solid rgba(255,255,255,.15);
  padding:.4rem 0 calc(.4rem + env(safe-area-inset-bottom));
}
.mobile-nav-inner{display:flex;justify-content:space-around;align-items:center;}
.mnav-btn{
  display:flex;flex-direction:column;align-items:center;gap:2px;
  background:none;border:none;color:rgba(255,255,255,.6);
  font-family:'DM Sans',sans-serif;font-size:.62rem;font-weight:500;
  cursor:pointer;padding:.35rem .6rem;border-radius:8px;
  transition:color .15s;min-width:56px;
}
.mnav-btn:hover,.mnav-btn.active{color:#fff;}
.mnav-btn.active{color:var(--accent2);}
.mnav-icon{font-size:1.25rem;line-height:1;}

/* Hamburger button (tablet) */
.hamburger{
  display:none;background:none;border:none;
  color:var(--paper);font-size:1.4rem;cursor:pointer;
  padding:.3rem .5rem;margin-left:.5rem;
}

/* Overlay when sidebar open on mobile */
.sidebar-overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,.5);z-index:149;
}
.sidebar-overlay.open{display:block;}

@media(max-width:900px){
  .sidebar{
    position:fixed;left:-240px;top:0;bottom:0;
    z-index:150;width:240px;
    transition:left .25s ease;
    box-shadow:4px 0 20px rgba(0,0,0,.3);
  }
  .sidebar.open{left:0;}
  .main{width:100%;}
  .hamburger{display:block;}
  .content{padding:1rem;}
  .stats-grid{grid-template-columns:1fr 1fr;}
  .page-head{padding:1rem;}
  .table-wrap{overflow-x:auto;}
  table{min-width:600px;}
}

@media(max-width:600px){
  /* ── NAV ── */
  .mobile-nav{display:block;}
  .main{padding-bottom:72px;}
  .hamburger{display:none;}
  .sidebar{display:none !important;}
  .sidebar-overlay{display:none !important;}

  /* ── STATS ── */
  .stats-grid{grid-template-columns:1fr 1fr;gap:.5rem;}
  .stat-card{padding:.75rem .8rem;}
  .stat-val{font-size:1.2rem;}
  .stat-label{font-size:.72rem;}

  /* ── PAGE HEAD ── */
  .page-head{padding:.75rem 1rem;flex-wrap:wrap;gap:.4rem;min-height:auto;}
  .page-title{font-size:1rem;}

  /* ── CONTENT ── */
  .content{padding:.7rem;}
  .btn{padding:.4rem .65rem;font-size:.75rem;}
  .btn-sm{padding:.3rem .6rem;font-size:.72rem;}

  /* ── TABLES ── */
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  table{min-width:420px;}
  th,td{padding:.45rem .5rem;font-size:.75rem;white-space:nowrap;}
  .drag-handle{display:none;}
  .hide-mobile{display:none;}

  /* ── MODALS (bottom sheet) ── */
  .modal{
    border-radius:18px 18px 0 0;
    max-height:92vh;
    position:fixed;bottom:0;left:0;right:0;
    width:100%;max-width:100%;
    overflow-y:auto;
  }
  .modal-bg{align-items:flex-end;padding:0;}
  .modal-head{padding:.9rem 1.2rem .7rem;position:sticky;top:0;z-index:1;background:var(--ink);}
  .modal-body{padding:1rem 1.2rem;}
  .modal-foot{padding:.8rem 1.2rem;position:sticky;bottom:0;background:var(--card);}

  /* ── FORMS ── */
  .form-grid{grid-template-columns:1fr;}
  .full-width{grid-column:1!important;}
  .reason-grid{grid-template-columns:1fr 1fr;gap:.4rem;}
  .reason-btn{padding:.45rem .5rem;font-size:.75rem;}

  /* ── MENU ITEMS ── */
  .cat-tabs{gap:.35rem;flex-wrap:wrap;}
  .cat-tab{padding:.28rem .65rem;font-size:.73rem;}
  .payment-grid{grid-template-columns:repeat(3,1fr);}
  .emoji-cell{width:36px;}
  .emoji-cell img{width:32px;height:32px;}

  /* ── SETTINGS ── */
  .form-group label{font-size:.78rem;}
  input[type=color]{height:36px;}
  input[type=range]{margin-top:4px;}

  /* ── PREVIEW BOXES ── */
  #header-preview-box{height:48px;}
  #hero-preview-box{padding:1rem;}
  #footer-preview-box{padding:.8rem;}

  /* ── BATCH MODAL ── */
  #batch-preview-body td{font-size:.72rem;padding:.35rem .5rem;}

  /* ── QR GRID ── */
  #tables-grid{grid-template-columns:1fr 1fr;}
  #qr-grid{grid-template-columns:1fr 1fr;}
}

/* DELETE ORDER MODAL */
.reason-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.8rem;}
.reason-btn{padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:8px;background:var(--card);
  font-size:.8rem;font-weight:500;cursor:pointer;transition:all .15s;text-align:left;font-family:'DM Sans',sans-serif;}
.reason-btn:hover{border-color:var(--accent);background:#fff5f3;}
.reason-btn.picked{border-color:var(--accent);background:#fff5f3;color:var(--accent);}

/* IMAGE UPLOAD */
.img-upload-area{border:2px dashed var(--border);border-radius:10px;padding:1.2rem;text-align:center;
  cursor:pointer;transition:border-color .15s;background:var(--warm);}
.img-upload-area:hover{border-color:var(--ink);}
.img-upload-area input[type=file]{display:none;}
.img-preview{width:100%;max-height:140px;object-fit:cover;border-radius:8px;margin-top:.6rem;}
.img-current{width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid var(--border);}

/* DELETED ORDERS TAB */
.tab-row{display:flex;gap:.5rem;margin-bottom:1rem;}
.tab-pill{padding:.4rem 1rem;border-radius:50px;border:1px solid var(--border);
  font-size:.82rem;font-weight:500;cursor:pointer;transition:all .15s;background:var(--card);}
.tab-pill.on{background:var(--ink);color:#fff;border-color:var(--ink);}
.del-badge{background:#fee2e2;color:#991b1b;font-size:.72rem;padding:.15rem .5rem;
  border-radius:4px;font-weight:500;}
/* Modifier modal */
.modifier-group-card{transition:box-shadow .15s;}
.modifier-group-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);}
#modifier-groups-list .btn-danger{background:#fee2e2;color:#dc2626;border:none;}
#modifier-groups-list .btn-danger:hover{background:#fecaca;}

/* ── TABLET (768px) ── */
@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .form-grid{grid-template-columns:1fr 1fr;}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
}

/* ── SMALL MOBILE (≤380px) ── */
@media(max-width:380px){
  .stats-grid{grid-template-columns:1fr 1fr;gap:.4rem;}
  .stat-card{padding:.6rem .7rem;}
  .stat-val{font-size:1rem;}
  .btn{padding:.35rem .55rem;font-size:.72rem;}
  .btn-sm{padding:.28rem .5rem;font-size:.7rem;}
  .page-head{padding:.6rem .8rem;}
  .content{padding:.5rem;}
  .cat-tab{padding:.25rem .55rem;font-size:.7rem;}
  th,td{padding:.4rem .4rem;font-size:.72rem;}
  .mobile-nav-inner button{font-size:.55rem;}
  .mnav-icon{font-size:1.1rem;}
  #tables-grid{grid-template-columns:1fr;}
  #qr-grid{grid-template-columns:1fr 1fr;}
  .reason-grid{grid-template-columns:1fr;}
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ═══════════ LOGIN PAGE ═══════════ -->
<div class="login-wrap" id="login-page">
  <div class="login-box">
    <div class="login-logo">🤖 Myan<span>Ai</span> POS</div>
    <div class="login-sub">Admin Dashboard</div>
    <div class="form-group">
      <label>Username</label>
      <input type="text" id="l-user" placeholder="admin" autocomplete="username">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" id="l-pass" placeholder="••••••••" autocomplete="current-password"
             onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <div id="l-err" style="color:var(--accent);font-size:.82rem;margin-bottom:.8rem;display:none"></div>
    <button class="btn btn-primary" style="width:100%;padding:.75rem;font-size:.95rem;border-radius:10px" onclick="doLogin()">
      Login →
    </button>
  </div>
</div>
<script>
async function doLogin() {
  const user = document.getElementById('l-user')?.value.trim();
  const pass = document.getElementById('l-pass')?.value.trim();
  const err  = document.getElementById('l-err');
  if(!user||!pass){if(err){err.textContent='Username and password required';err.style.display='block';} return;}
  try {
    const r = await fetch('admin.php?api=login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({user,pass})});
    const d = await r.json();
    if(d.ok){location.reload();}
    else{if(err){err.textContent=d.msg||'Login failed';err.style.display='block';}}
  } catch(e){if(err){err.textContent='Connection error';err.style.display='block';}}
}
</script>
<?php else: ?>
<!-- ═══════════ ADMIN APP ═══════════ -->
<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

<div class="app" id="app">
  <!-- SIDEBAR -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-logo" style="display:flex;align-items:center;justify-content:space-between">
      <span>🍜 <?php if($_IS_TENANT && !empty($_SESSION['tenant_name'])): ?><span><?= htmlspecialchars($_SESSION['tenant_name']) ?></span><span class="sidebar-badge">Tenant</span><?php else: ?>Myan<span>Ai</span> POS<span class="sidebar-badge">Admin</span><?php endif; ?></span>
      <button onclick="closeSidebar()" style="background:none;border:none;color:rgba(255,255,255,.6);font-size:1.2rem;cursor:pointer;display:none" id="sidebar-close-btn">✕</button>
    </div>
    <div style="display:none">
      <select id="branch-select" onchange="switchBranch(this.value)"
        style="width:100%;padding:.4rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:#2a1f14;color:#fff;font-size:.8rem;cursor:pointer">
        <option value="0" style="background:#2a1f14;color:#fff">🏢 All Branches</option>
      </select>
    </div>
    <?php if (!empty($_SESSION['demo_mode'])): ?>
    <div style="background:#f39c12;color:#fff;text-align:center;padding:6px;font-size:12px;font-weight:600;letter-spacing:.05em">
      🔒 READ ONLY DEMO MODE
    </div>
    <?php endif; ?>
    <nav>
      <!-- ── BUSINESS ── -->
      <div class="nav-section-header" onclick="toggleNavSection('business')">
        Business <span class="nav-chev" id="chev-business">▾</span>
      </div>
      <div class="nav-section-body" id="section-business">
        <div class="nav-item" onclick="showPage('dashboard')" id="nav-dashboard">
          <span class="nav-icon">📊</span> Dashboard
        </div>
        <div class="nav-item" onclick="showPage('menu')" id="nav-menu">
          <span class="nav-icon">🍜</span> Menu Items
        </div>
        <div class="nav-item" onclick="showPage('staff')" id="nav-staff">
          <span class="nav-icon">👥</span> Staff
        </div>
        <div class="nav-item" onclick="showPage('crm')" id="nav-crm">
          <span class="nav-icon">🤝</span> CRM
        </div>
        <div class="nav-item" onclick="showPage('stocklog')" id="nav-stocklog">
          <span class="nav-icon">📋</span> Stock Log
        </div>
        <div class="nav-item" onclick="showPage('promos')" id="nav-promos">
          <span class="nav-icon">🏷️</span> Promotions
        </div>
        <div class="nav-item" onclick="showPage('branches')" id="nav-branches">
          <span class="nav-icon">🏢</span> Branches
        </div>
      </div>
      <!-- ── BRANCH OPS ── -->
      <div class="nav-section-divider"></div>
      <div class="nav-section-header" onclick="toggleNavSection('branch-ops')">
        Branch Ops <span class="nav-chev" id="chev-branch-ops">▾</span>
      </div>
      <div class="nav-section-body" id="section-branch-ops">
        <div id="branch-ops-selector">
          <select id="branch-select-ops" onchange="switchBranch(this.value)">
            <option value="0">🏢 All Branches</option>
          </select>
        </div>
        <div class="nav-item" onclick="showPage('orders')" id="nav-orders">
          <span class="nav-icon">📋</span> Orders
        </div>
        <div class="nav-item" onclick="window.open('kds.html','_blank')" id="nav-kds">
          <span class="nav-icon">🍳</span> KDS
        </div>
        <div class="nav-item" onclick="showPage('tables')" id="nav-tables">
          <span class="nav-icon">🍽️</span> Tables
        </div>
        <div class="nav-item" onclick="showPage('reserve')" id="nav-reserve">
          <span class="nav-icon">📅</span> Reservations
        </div>
        <div class="nav-item" onclick="showPage('stock')" id="nav-stock">
          <span class="nav-icon">📦</span> Stock
        </div>
        <div class="nav-item" onclick="showPage('shift')" id="nav-shift">
          <span class="nav-icon">🕐</span> Shifts
        </div>
        <div class="nav-item" onclick="showPage('delivery')" id="nav-delivery">
          <span class="nav-icon">🛵</span> Delivery
        </div>
        <div class="nav-item" onclick="showPage('expenses')" id="nav-expenses">
          <span class="nav-icon">💰</span> Expenses
        </div>
      </div>
      <!-- ── ADMIN ── -->
      <div class="nav-section-divider"></div>
      <div class="nav-section-header" onclick="toggleNavSection('admin')">
        Admin <span class="nav-chev" id="chev-admin">›</span>
      </div>
      <div class="nav-section-body" id="section-admin" style="display:none">
        <?php if(!$_IS_TENANT): ?>
        <div class="nav-item" onclick="showPage('saas')" id="nav-saas">
          <span class="nav-icon">🌐</span> SaaS
        </div>
        <?php endif; ?>
        <?php if($_IS_TENANT): ?>
        <div class="nav-item" onclick="showPage('upgrade')" id="nav-upgrade">
          <span class="nav-icon">⬆</span> Plan Upgrade
        </div>
        <?php endif; ?>
        <div class="nav-item" onclick="showPage('schedule')" id="nav-schedule">
          <span class="nav-icon">📅</span> Scheduling
        </div>
        <div class="nav-item" onclick="showPage('storefront')" id="nav-storefront">
          <span class="nav-icon">🎨</span> Storefront
        </div>
        <div class="nav-item" onclick="showPage('settings')" id="nav-settings">
          <span class="nav-icon">⚙️</span> Settings
        </div>
      </div>
    </nav>
    <div class="sidebar-foot">
      <button class="logout-btn" onclick="doLogout()">↩ Logout</button>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="main">
    <!-- ── DASHBOARD ── -->
    <div id="page-dashboard">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">Dashboard</div>
        </div>
        <span style="font-size:.82rem;color:var(--muted)" id="dash-date"></span>
      </div>
      <div class="content">
        <div class="stats-grid" id="stats-grid">
          <div class="stat-card" onclick="showPage('orders')" style="cursor:pointer">
            <div class="stat-label">📋 Today's Orders</div>
            <div class="stat-val" id="s-orders">—</div>
          </div>
          <div class="stat-card" onclick="showPage('orders')" style="cursor:pointer">
            <div class="stat-label">💰 Today's Revenue</div>
            <div class="stat-val green" id="s-revenue">—</div>
          </div>
          <div class="stat-card" onclick="showPage('menu')" style="cursor:pointer">
            <div class="stat-label">⚠️ Low Stock Items</div>
            <div class="stat-val" id="s-low">—</div>
          </div>
          <div class="stat-card" onclick="filterPending()" style="cursor:pointer">
            <div class="stat-label">⏳ Pending Orders</div>
            <div class="stat-val" id="s-pending">—</div>
          </div>
        </div>

        
        
        
        <!-- Bulk Delete Modal -->
        <div id="bulk-delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:380px;width:92%;position:relative">
            <button onclick="document.getElementById('bulk-delete-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
            <div style="font-weight:600;margin-bottom:1rem">🗑 Bulk Delete Orders</div>
            <div style="margin-bottom:.75rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Phone number (ဒီ phone ရဲ့ orders အကုန်ဖျက်)</label>
              <input type="text" id="bulk-phone" placeholder="09xxxxxxxxx" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem">
            </div>
            <div style="margin-bottom:.75rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Date range ဖျက် (ဗလာ = အကုန်)</label>
              <div style="display:flex;gap:.5rem">
                <input type="date" id="bulk-date-from" style="flex:1;padding:.4rem .5rem;border:1px solid #ddd;border-radius:6px;font-size:.82rem">
                <input type="date" id="bulk-date-to" style="flex:1;padding:.4rem .5rem;border:1px solid #ddd;border-radius:6px;font-size:.82rem">
              </div>
            </div>
            <div style="margin-bottom:1rem">
              <label style="font-size:.8rem;color:var(--muted);display:block;margin-bottom:3px">Delete reason</label>
              <input type="text" id="bulk-reason" value="Bulk delete by admin" style="width:100%;padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.88rem">
            </div>
            <div id="bulk-preview" style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem"></div>
            <div style="display:flex;gap:.5rem">
              <button onclick="previewBulkDelete()" style="flex:1;padding:.6rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🔍 Preview</button>
              <button onclick="confirmBulkDelete()" style="flex:1;padding:.6rem;background:#dc3545;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">🗑 Delete All</button>
            </div>
          </div>
        </div>

        <!-- KDS Clear Modal -->
        <div id="kds-clear-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:340px;width:92%;position:relative">
            <button onclick="document.getElementById('kds-clear-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
            <div style="font-weight:600;margin-bottom:1rem">🧹 KDS Queue Clear</div>
            <div style="margin-bottom:1rem;font-size:.88rem;color:var(--muted)">KDS queue ထဲမှာ pending/preparing/ready tickets တွေကို served အဖြစ် mark လုပ်မည်</div>
            <div id="kds-pending-count" style="font-size:1.1rem;font-weight:600;margin-bottom:1rem;text-align:center"></div>
            <div style="display:flex;gap:.5rem">
              <button onclick="document.getElementById('kds-clear-modal').style.display='none'" style="flex:1;padding:.6rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer">Cancel</button>
              <button onclick="clearKDSQueue()" style="flex:1;padding:.6rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer">🧹 Clear Now</button>
            </div>
          </div>
        </div>

<!-- Split Bill Modal -->
<div id="split-bill-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--bg);border-radius:14px;padding:1.5rem;max-width:360px;width:92%;position:relative">
    <button onclick="document.getElementById('split-bill-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer">✕</button>
    <div style="font-weight:600;font-size:1rem;margin-bottom:1rem">💳 Split Bill</div>
    <div id="split-order-info" style="font-size:.85rem;color:var(--muted);margin-bottom:1rem"></div>
    <div style="margin-bottom:.75rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Primary payment</label>
      <select id="split-primary" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
        <option value="cash">💵 Cash</option>
        <option value="kpay">💜 KPay</option>
        <option value="wave">🌊 Wave Pay</option>
        <option value="cb">🏦 CB Pay</option>
        <option value="aya">🟢 AYA Pay</option>
        <option value="card">💳 Card</option>
      </select>
    </div>
    <div style="margin-bottom:.75rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Split with (optional)</label>
      <select id="split-secondary" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
        <option value="">— None (single payment) —</option>
        <option value="cash">💵 Cash</option>
        <option value="kpay">💜 KPay</option>
        <option value="wave">🌊 Wave Pay</option>
        <option value="cb">🏦 CB Pay</option>
        <option value="aya">🟢 AYA Pay</option>
        <option value="card">💳 Card</option>
      </select>
    </div>
    <div id="split-amount-row" style="display:none;margin-bottom:1rem">
      <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:.4rem">Amount paid by secondary (Ks)</label>
      <input type="number" id="split-amount" min="0" placeholder="0" style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:8px;font-size:.88rem">
    </div>
    <div style="display:flex;gap:.5rem">
      <button onclick="document.getElementById('split-bill-modal').style.display='none'" style="flex:1;padding:.65rem;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer">Cancel</button>
      <button onclick="openSplitBill(${o.id},${o.total_amount})" style="flex:1;padding:.65rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">💳 Split & Close</button>
    </div>
  </div>
</div>
<!-- Customer History Modal -->
        <div id="cust-history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center">
          <div style="background:var(--bg);border-radius:16px;padding:1.5rem;max-width:500px;width:95%;max-height:85vh;overflow-y:auto;position:relative">
            <button onclick="document.getElementById('cust-history-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.3rem;cursor:pointer">✕</button>
            <div style="font-weight:600;font-size:1rem;margin-bottom:1rem">👤 Customer Order History</div>
            <div style="display:flex;gap:.5rem;margin-bottom:1rem">
              <input type="text" id="cust-phone-input" placeholder="09xxxxxxxxx" style="flex:1;padding:.5rem .75rem;border:1px solid #ddd;border-radius:8px;font-size:.9rem">
              <button onclick="loadCustHistory()" style="padding:.5rem 1rem;background:#e84c2b;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem">Search</button>
            </div>
            <div id="cust-history-result"></div>
          </div>
        </div>
<!-- ═══ ANALYTICS SECTION ═══ -->
        <div id="analytics-section" style="margin-top:1.2rem">

          <!-- Date range selector -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.5rem">
            <div style="font-weight:600;font-size:.95rem">📈 Analytics</div>
            <div style="display:flex;gap:.4rem">
              <button onclick="loadAnalytics(7)"  id="abtn-7"  class="btn btn-sm btn-ghost" style="font-size:.78rem">7D</button>
              <button onclick="loadAnalytics(14)" id="abtn-14" class="btn btn-sm btn-ghost" style="font-size:.78rem">14D</button>
              <button onclick="loadAnalytics(30)" id="abtn-30" class="btn btn-sm btn-ghost" style="font-size:.78rem">30D</button>
            </div>
          </div>

          <!-- Summary mini cards -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-bottom:1rem">
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Total Orders</div>
              <div style="font-size:1.3rem;font-weight:700;color:var(--accent)" id="an-total-orders">—</div>
            </div>
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Total Revenue</div>
              <div style="font-size:1.1rem;font-weight:700;color:#28a745" id="an-total-rev">—</div>
            </div>
            <div class="stat-card" style="padding:.7rem;text-align:center">
              <div style="font-size:.72rem;color:var(--muted)">Avg Order</div>
              <div style="font-size:1.1rem;font-weight:700;color:var(--accent2)" id="an-avg-order">—</div>
            </div>
          </div>

          <!-- Revenue chart -->
          <div class="stat-card" style="padding:1rem;margin-bottom:.8rem">
            <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">💰 Daily Revenue</div>
            <div style="position:relative;height:180px">
              <canvas id="chart-revenue"></canvas>
            </div>
          </div>

          <!-- Top items + Payment breakdown -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem">
            <div class="stat-card" style="padding:1rem">
              <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">🍜 Top Items</div>
              <div style="position:relative;height:200px">
                <canvas id="chart-items"></canvas>
              </div>
            </div>
            <div class="stat-card" style="padding:1rem">
              <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">💳 Payment Split</div>
              <div style="position:relative;height:200px">
                <canvas id="chart-payments"></canvas>
              </div>
            </div>
          </div>

          <!-- Hourly heatmap -->
          <div class="stat-card" style="padding:1rem;margin-bottom:1rem">
            <div style="font-size:.82rem;font-weight:600;margin-bottom:.6rem">🕐 Peak Hours</div>
            <div style="position:relative;height:120px">
              <canvas id="chart-hourly"></canvas>
            </div>
          </div>

        </div>
        <!-- ═══ END ANALYTICS ═══ -->

<!-- Cross-Branch Analytics -->
        <div id="cross-branch-analytics" style="<?= $_IS_TENANT ? '' : '' ?>margin-top:1rem;display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
            <div style="font-weight:600;font-size:.95rem">📊 Branch Revenue Comparison</div>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
              <select id="cba-range" onchange="loadCrossBranchAnalytics()" style="font-size:.8rem;padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:var(--card);color:var(--text)">
                <option value="7">Last 7 days</option>
                <option value="30" selected>Last 30 days</option>
                <option value="90">Last 90 days</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem" class="cba-grid">
            <!-- Revenue by Branch Bar Chart -->
            <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem">
              <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem;font-weight:600">💰 Revenue by Branch (MMK)</div>
              <div style="position:relative;height:180px"><canvas id="chart-branch-revenue"></canvas></div>
            </div>
            <!-- Orders by Branch -->
            <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem">
              <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem;font-weight:600">🧾 Orders by Branch</div>
              <div style="position:relative;height:180px"><canvas id="chart-branch-orders"></canvas></div>
            </div>
          </div>
          <!-- Branch table -->
          <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem;margin-top:1rem;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.83rem" id="cba-table">
              <thead><tr style="color:var(--muted);text-align:left;border-bottom:1px solid var(--border)">
                <th style="padding:.4rem .6rem">Branch</th>
                <th style="padding:.4rem .6rem;text-align:right">Orders</th>
                <th style="padding:.4rem .6rem;text-align:right">Revenue</th>
                <th style="padding:.4rem .6rem;text-align:right">Avg Order</th>
                <th style="padding:.4rem .6rem;text-align:right">Cancelled</th>
              </tr></thead>
              <tbody id="cba-tbody"><tr><td colspan="5" style="text-align:center;padding:1rem;color:var(--muted)">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>

<!-- Recent Orders (Dashboard) -->
        <div class="table-wrap" style="margin-top:1rem">
          <div class="table-toolbar">
            <span style="font-weight:600;font-size:.9rem">📋 Recent Orders</span>
            <button class="btn btn-ghost btn-sm" onclick="showPage('orders')">View All →</button>
            <button class="btn btn-ghost btn-sm" onclick="openDailyReport()">📊 Daily Report</button>
            <button class="btn btn-ghost btn-sm" onclick="openKDSClear()" style="color:#e84c2b">🧹 KDS</button>
          </div>
          <div style="overflow-x:auto">
            <table>
              <thead><tr>
                <th>Ref</th><th>Customer</th><th>Items</th>
                <th>Amount</th><th>Payment</th><th>Status</th><th>Time</th><th></th>
              </tr></thead>
              <tbody id="dash-orders-body">
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ── MENU ITEMS ── -->
    <div id="page-menu" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()" title="Menu">☰</button>
          <div class="page-title">Menu Items</div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm" onclick="downloadTemplate()">⬇ CSV</button>
          <button class="btn btn-ghost btn-sm" onclick="openBatchModal()">📥 Batch</button>
          <button class="btn btn-primary" onclick="openAddModal()">+ Add</button>
        </div>
      </div>
      <div class="content">
        <div class="cat-tabs" id="cat-tabs"></div>
        <div class="table-toolbar" style="border:none;padding:0 0 .8rem">
          <input class="search-input" id="menu-search" placeholder="🔍  Search items…" oninput="renderMenuTable()">
          <span style="font-size:.82rem;color:var(--muted)" id="menu-count"></span>
        </div>
        <div class="table-wrap">
          <div style="overflow-x:auto">
            <table>
              <thead><tr>
                <th style="width:32px" title="Drag to reorder"></th>
                <th style="width:48px"></th>
                <th>Name</th><th>Category</th>
                <th>Price</th><th>Stock</th><th>Status</th><th>Actions</th>
              </tr></thead>
              <tbody id="menu-body"><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ── TABLES PAGE ── -->
    <div id="page-tables" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()">☰</button>
          <div class="page-title">Tables & QR Codes</div>
        </div>
        <button class="btn btn-primary" onclick="openAddTableModal()">+ Add Table</button>
      </div>
      <div class="content">
        <!-- Live table status -->
        <div id="tables-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem"></div>

        <!-- QR section -->
        <div style="background:var(--card);border-radius:var(--radius);border:1px solid var(--border);padding:1.2rem">
          <div style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:.8rem">📱 QR Codes — Print & Place on Tables</div>
          <div id="qr-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem"></div>
          <button class="btn btn-ghost" style="margin-top:1rem;width:100%" onclick="printAllQR()">🖨️ Print All QR Codes</button>
        </div>
      </div>
    </div>

    <!-- Add Table Modal -->
    <div class="modal-bg" id="add-table-modal">
      <div class="modal" style="max-width:360px">
        <div class="modal-head"><h3>+ Add Table</h3>
          <button class="x-btn" onclick="document.getElementById('add-table-modal').classList.remove('open')">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Branch</label>
            <select id="new-table-branch" style="width:100%;padding:.5rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="">-- Select Branch --</option>
            </select>
          </div>
          <div class="form-group">
            <label>Table Code (e.g. T01, VIP1)</label>
            <input type="text" id="new-table-code" placeholder="T09" style="text-transform:uppercase">
          </div>
          <div class="form-group">
            <label>Label</label>
            <input type="text" id="new-table-label" placeholder="Window Seat">
          </div>
          <div class="form-group">
            <label>Seats</label>
            <input type="number" id="new-table-seats" value="4" min="1" max="20">
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-ghost" onclick="document.getElementById('add-table-modal').classList.remove('open')">Cancel</button>
          <button class="btn btn-primary" onclick="saveNewTable()">Save Table</button>
        </div>
      </div>
    </div>

    <!-- ── RESERVATIONS PAGE ── -->
    <div id="page-reserve" style="display:none">
      <div class="page-head">
        <h1 class="page-title">📅 Table Reservations</h1>
      </div>

      <!-- Date picker + New button -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center">
        <input id="res-date" type="date" onchange="resLoad()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
        <select id="res-status-filter" onchange="resLoad()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <option value="">All Status</option>
          <option value="pending">⏳ Pending</option>
          <option value="confirmed">✅ Confirmed</option>
          <option value="seated">🪑 Seated</option>
          <option value="completed">✔️ Completed</option>
          <option value="cancelled">❌ Cancelled</option>
          <option value="no_show">👻 No Show</option>
        </select>
        <span id="res-count" style="color:var(--text-muted);font-size:.85rem"></span>
        <button class="btn btn-primary" onclick="resOpenNew()" style="margin-left:auto;padding:.6rem 1.2rem">
          + New Reservation
        </button>
      </div>

      <!-- Reservations Table -->
      <div class="card" style="overflow-x:auto;padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
              <th style="padding:.7rem 1rem;text-align:left">Time</th>
              <th style="padding:.7rem 1rem;text-align:left">Customer</th>
              <th style="padding:.7rem 1rem;text-align:center">Party</th>
              <th style="padding:.7rem 1rem;text-align:left">Table</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:left">Notes</th>
              <th style="padding:.7rem 1rem;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="res-tbody">
            <tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Reservation Create/Edit Modal -->
    <div id="res-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:480px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('res-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="res-modal-title">📅 New Reservation</div>
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Name *</label>
              <input id="res-name" type="text" placeholder="Customer name"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Phone *</label>
              <input id="res-phone" type="tel" placeholder="09xxxxxxxxx"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Date *</label>
              <input id="res-date-input" type="date"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Time *</label>
              <input id="res-time" type="time"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Party Size</label>
              <input id="res-party" type="number" min="1" max="20" value="2"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Table</label>
              <select id="res-table"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
                <option value="">Auto-assign</option>
              </select>
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Duration (min)</label>
              <input id="res-duration" type="number" min="30" step="30" value="90"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Notes</label>
            <input id="res-notes" type="text" placeholder="Special requests..."
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="resCreate()" style="padding:.7rem;font-size:1rem">
            ✅ Save Reservation
          </button>
        </div>
      </div>
    </div>

    <!-- ── STOCK PAGE ── -->
    <div id="page-stock" style="display:none">
      <div class="page-head">
        <h1 class="page-title">📦 Stock Management</h1>
      </div>

      <!-- Summary Cards -->
      <div id="stock-summary" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem">
      </div>

      <!-- Stock Table -->
      <div class="card" style="padding:0;overflow-x:auto">
        <div style="padding:1rem 1.2rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)">
          <span style="font-weight:700">📋 All Items</span>
          <input id="stock-search" type="text" placeholder="🔍 Search..."
            style="padding:.4rem .8rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.85rem;width:200px"
            oninput="stockFilter()">
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.7rem 1rem;text-align:left">Item</th>
              <th style="padding:.7rem 1rem;text-align:left">Category</th>
              <th style="padding:.7rem 1rem;text-align:right">Stock</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody id="stock-tbody">
            <tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Stock Log -->
      <div class="card" style="padding:0;overflow-x:auto;margin-top:1.5rem">
        <div style="padding:1rem 1.2rem;font-weight:700;border-bottom:1px solid var(--border)">📝 Recent Stock Changes</div>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.6rem 1rem;text-align:left">Time</th>
              <th style="padding:.6rem 1rem;text-align:left">Item</th>
              <th style="padding:.6rem 1rem;text-align:right">Change</th>
              <th style="padding:.6rem 1rem;text-align:right">New Qty</th>
              <th style="padding:.6rem 1rem;text-align:left">Reason</th>
              <th style="padding:.6rem 1rem;text-align:left">Note</th>
            </tr>
          </thead>
          <tbody id="stock-log-tbody">
            <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:var(--text-muted)">No changes yet</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- STOCK LOG PAGE -->
    <div id="page-stocklog" style="display:none">
      <div class="page-head"><h1 class="page-title">📋 Stock Log</h1></div>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:16px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end">
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">ရှာဖွေ</label><input id="sl-search" type="text" placeholder="Item, reason, staff..." oninput="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">Action</label><select id="sl-action" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"><option value="">All</option><option value="add">ထည့်သွင်း</option><option value="remove">ထုတ်ယူ</option><option value="adjust">ပြင်ဆင်</option><option value="waste">ဖျက်ဆီး</option><option value="order_deduct">Order နုတ်</option></select></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">မှ ရက်</label><input id="sl-date-from" type="date" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div><label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px">ထိ ရက်</label><input id="sl-date-to" type="date" onchange="loadStockLogs()" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--surface);color:var(--text)"></div>
          <div style="display:flex;gap:8px"><button onclick="loadStockLogs()" style="flex:1;padding:7px;border:none;border-radius:6px;background:#3b82f6;color:#fff;font-size:13px;cursor:pointer">🔍 Search</button><button onclick="exportSL()" style="flex:1;padding:7px;border:none;border-radius:6px;background:#16a34a;color:#fff;font-size:13px;cursor:pointer">⬇ CSV</button></div>
        </div>
      </div>
      <div id="sl-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px"></div>
      <div style="overflow-x:auto;border:1px solid var(--border);border-radius:10px">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)"><th style="padding:10px 12px;text-align:left">#</th><th style="padding:10px 12px;text-align:left">Item</th><th style="padding:10px 12px;text-align:center">Action</th><th style="padding:10px 12px;text-align:center">အဟောင်း</th><th style="padding:10px 12px;text-align:center">အသစ်</th><th style="padding:10px 12px;text-align:center">ပြောင်းလဲ</th><th style="padding:10px 12px;text-align:left">အကြောင်း</th><th style="padding:10px 12px;text-align:left">ဝန်ထမ်း</th><th style="padding:10px 12px;text-align:left">ရက်/အချိန်</th></tr></thead>
          <tbody id="sl-tbody"><tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:8px"><span id="sl-count" style="font-size:13px;color:var(--text-muted)"></span><div style="display:flex;gap:8px"><button id="sl-prev" onclick="slPage(-1)" style="padding:6px 14px;border:1px solid var(--border);border-radius:6px;font-size:13px;cursor:pointer;background:var(--surface);color:var(--text)">← Prev</button><button id="sl-next" onclick="slPage(1)" style="padding:6px 14px;border:1px solid var(--border);border-radius:6px;font-size:13px;cursor:pointer;background:var(--surface);color:var(--text)">Next →</button></div></div>
    </div>


    <!-- STAFF PAGE -->
    <div id="page-staff" style="display:none">
      <div class="page-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <h1 class="page-title">👥 Staff Management</h1>
        <button onclick="staffOpenAdd()" style="padding:.6rem 1.2rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.88rem;cursor:pointer;font-weight:600">+ Add Staff</button>
      </div>
      <div id="staff-list" style="display:grid;gap:10px;margin-top:1rem"></div>
    </div>

    <!-- STAFF MODAL -->
    <div id="staff-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9100;align-items:center;justify-content:center">
      <div style="background:var(--surface);border-radius:16px;padding:1.5rem;max-width:460px;width:93%;max-height:88vh;overflow-y:auto;position:relative">
        <button onclick="document.getElementById('staff-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:#e74c3c;color:#fff;border:none;width:28px;height:28px;border-radius:50%;font-size:14px;cursor:pointer">✕</button>
        <div id="staff-modal-title" style="font-size:1.1rem;font-weight:700;margin-bottom:1.2rem">Add Staff</div>
        <input type="hidden" id="sf-id">
        <div style="display:grid;gap:.85rem">
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Name *</label>
            <input id="sf-name" type="text" placeholder="Ko Aung" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">PIN * (4-6 digits)</label>
            <input id="sf-pin" type="password" placeholder="••••" maxlength="6" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem;letter-spacing:.2em">
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Role</label>
            <select id="sf-role" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
              <option value="waiter">🧑‍🍳 Waiter</option>
              <option value="manager">👔 Manager</option>
            </select>
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.5rem">Permissions — ဘယ် page သုံးခွင့်ပေးမလဲ</label>
            <div id="sf-perms" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem"></div>
          </div>
          <div>
            <label style="font-size:.8rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Notes</label>
            <input id="sf-notes" type="text" placeholder="Optional notes" style="width:100%;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text);font-size:.9rem">
          </div>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:1.2rem">
          <button onclick="staffSave()" style="flex:1;padding:.7rem;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer">Save</button>
          <button onclick="document.getElementById('staff-modal').style.display='none'" style="padding:.7rem 1.2rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;font-size:.9rem;cursor:pointer;color:var(--text)">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Stock Adjust Modal -->
    <div id="stock-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:420px;margin:3rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('stock-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="stock-modal-title">Adjust Stock</div>
        <input type="hidden" id="stock-adj-id">
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Quantity Change</label>
            <div style="display:flex;gap:.5rem;margin-top:.3rem">
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=10" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+10</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=50" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+50</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=100" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.4rem .8rem;cursor:pointer">+100</button>
              <button class="btn btn-sm" onclick="document.getElementById('stock-adj-qty').value=-5" style="background:var(--surface2);border:1px solid var(--border);color:#e74c3c;border-radius:8px;padding:.4rem .8rem;cursor:pointer">-5</button>
            </div>
            <input id="stock-adj-qty" type="number" placeholder="e.g. 50 or -10"
              style="width:100%;margin-top:.5rem;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:1.1rem">
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Reason</label>
            <select id="stock-adj-reason" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="restock">📥 Restock</option>
              <option value="manual_adjust">✏️ Manual Adjust</option>
              <option value="waste">🗑 Waste</option>
              <option value="correction">🔧 Correction</option>
              <option value="returned">↩ Returned</option>
            </select>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Note (optional)</label>
            <input id="stock-adj-note" type="text" placeholder="e.g. Morning restock"
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="stockDoAdjust()" style="padding:.7rem;font-size:1rem">
            ✅ Apply
          </button>
        </div>
      </div>
    </div>

    <!-- ── SHIFT PAGE ── -->
    <div id="page-shift" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🕐 Shift Management</h1>
      </div>

      <!-- Current Shift Status Card -->
      <div id="shift-status-card" class="card" style="margin-bottom:1.5rem;padding:1.5rem">
        <div id="shift-status-body">
          <div style="text-align:center;color:var(--text-muted);padding:1rem">Loading...</div>
        </div>
      </div>

      <!-- Open Shift Form (shown when no shift open) -->
      <div id="shift-open-form" class="card" style="display:none;padding:1.5rem;margin-bottom:1.5rem">
        <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">🔓 Shift ဖွင့်မည်</div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:150px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Staff PIN</label>
            <input id="shift-pin" type="password" maxlength="6" placeholder="••••"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:1rem;letter-spacing:.3em">
          </div>
          <div style="flex:1;min-width:180px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Opening Cash (MMK)</label>
            <input id="shift-opening-cash" type="number" min="0" placeholder="50000"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn btn-primary" onclick="shiftOpen()" style="padding:.6rem 1.5rem">
            ▶ Open Shift
          </button>
        </div>
      </div>

      <!-- Close Shift Form (shown when shift open) -->
      <div id="shift-close-form" class="card" style="display:none;padding:1.5rem;margin-bottom:1.5rem">
        <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">🔒 Shift ပိတ်မည်</div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:1;min-width:180px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Closing Cash (MMK)</label>
            <input id="shift-closing-cash" type="number" min="0" placeholder="0"
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <div style="flex:2;min-width:200px">
            <label style="font-size:.82rem;color:var(--text-muted);display:block;margin-bottom:.3rem">Notes</label>
            <input id="shift-close-notes" type="text" placeholder="Optional notes..."
              style="width:100%;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <button class="btn" onclick="shiftClose()"
            style="padding:.6rem 1.5rem;background:#e74c3c;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">
            ■ Close Shift
          </button>
        </div>
      </div>

      <!-- Shift History -->
      <div class="card" style="padding:0;overflow-x:auto">
        <div style="padding:1rem 1.2rem;font-weight:700;border-bottom:1px solid var(--border)">📋 Shift History</div>
        <table style="width:100%;border-collapse:collapse;font-size:.87rem">
          <thead>
            <tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
              <th style="padding:.7rem 1rem;text-align:left">Staff</th>
              <th style="padding:.7rem 1rem;text-align:left">Opened</th>
              <th style="padding:.7rem 1rem;text-align:left">Duration</th>
              <th style="padding:.7rem 1rem;text-align:right">Orders</th>
              <th style="padding:.7rem 1rem;text-align:right">Revenue</th>
              <th style="padding:.7rem 1rem;text-align:right">Cash Diff</th>
              <th style="padding:.7rem 1rem;text-align:center">Status</th>
              <th style="padding:.7rem 1rem;text-align:center">Detail</th>
            </tr>
          </thead>
          <tbody id="shift-history-tbody">
            <tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Shift Detail Modal -->
    <div id="shift-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:680px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('shift-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div id="shift-modal-body">Loading...</div>
      </div>
    </div>

    <!-- ── CRM PAGE ── -->
    <div id="page-crm" style="display:none">
      <div class="page-head">
        <h1 class="page-title">👥 Customer CRM</h1>
      </div>

      <!-- Search + Filter Bar -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:center">
        <input id="crm-search" type="text" placeholder="🔍 Phone / Name ရှာမည်..."
          style="flex:1;min-width:200px;padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.9rem"
          oninput="crmSearchDebounce()">
        <select id="crm-tag-filter" onchange="crmLoadCustomers()"
          style="padding:.6rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.9rem">
          <option value="">All Tags</option>
          <option value="vip">⭐ VIP</option>
          <option value="regular">🔄 Regular</option>
          <option value="normal">👤 Normal</option>
          <option value="blocked">🚫 Blocked</option>
        </select>
        <span id="crm-count" style="color:var(--text-muted);font-size:.85rem"></span>
      </div>

      <!-- Customer Table -->
      <div class="card" style="overflow-x:auto;padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
              <th style="padding:.75rem 1rem;text-align:left">Customer</th>
              <th style="padding:.75rem 1rem;text-align:left">Tag</th>
              <th style="padding:.75rem 1rem;text-align:right">Orders</th>
              <th style="padding:.75rem 1rem;text-align:right">Total Spent</th>
              <th style="padding:.75rem 1rem;text-align:left">Last Order</th>
              <th style="padding:.75rem 1rem;text-align:left">Loyalty</th>
              <th style="padding:.75rem 1rem;text-align:center">Action</th>
            </tr>
          </thead>
          <tbody id="crm-tbody">
            <tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div id="crm-pagination" style="display:flex;gap:.5rem;justify-content:center;margin-top:1rem;flex-wrap:wrap"></div>
    </div>

    <!-- CRM Profile Modal -->
    <div id="crm-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:620px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('crm-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div id="crm-modal-body">Loading...</div>
      </div>
    </div>

    <!-- __ SCHEDULE PAGE __ -->
    <div id="page-schedule" style="display:none">
      <div class="page-head"><h1 class="page-title">📆 Staff Schedule</h1></div>
      <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="schedPrevWeek()">◀ Prev</button>
        <span id="sched-week-label" style="font-weight:700"></span>
        <button class="btn btn-ghost btn-sm" onclick="schedNextWeek()">Next ▶</button>
        <div id="sched-cost" style="margin-left:auto;font-size:.85rem;color:var(--text-muted)"></div>
        <button class="btn btn-primary" onclick="schedOpenAssign()" style="padding:.5rem 1rem">+ Assign Shift</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem">
          <thead><tr id="sched-header" style="background:var(--surface2);border-bottom:1px solid var(--border)"></tr></thead>
          <tbody id="sched-tbody"><tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Schedule Assign Modal -->
    <div id="sched-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6)">
      <div style="max-width:420px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('sched-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">&#10005;</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1rem">📆 Assign Shift</div>
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <select id="sched-staff" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></select>
          <input id="sched-date" type="date" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Start</label><input id="sched-start" type="time" value="09:00" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">End</label><input id="sched-end" type="time" value="17:00" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <select id="sched-role" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="waiter">Waiter</option><option value="kitchen">Kitchen</option><option value="cashier">Cashier</option><option value="manager">Manager</option>
            </select>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Rate/hr</label><input id="sched-rate" type="number" value="1500" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <button class="btn btn-primary" onclick="schedSave()" style="padding:.7rem;font-size:1rem">✅ Assign</button>
        </div>
      </div>
    </div>

    <!-- __ EXPENSES PAGE __ -->
    <div id="page-expenses" style="display:none">
      <div class="page-head"><h1 class="page-title">💼 Expense Tracking</h1></div>
      <div id="exp-pnl" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem"></div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center">
        <input id="exp-month" type="month" onchange="expLoad()" style="padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
        <select id="exp-cat-filter" onchange="expLoad()" style="padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <option value="">All Categories</option>
          <option value="ingredients">🥬 Ingredients</option><option value="packaging">📦 Packaging</option>
          <option value="utilities">⚡ Utilities</option><option value="rent">🏠 Rent</option>
          <option value="salary">👥 Salary</option><option value="equipment">🔧 Equipment</option>
          <option value="marketing">📢 Marketing</option><option value="other">📌 Other</option>
        </select>
        <button class="btn btn-primary" onclick="expOpenNew()" style="margin-left:auto;padding:.5rem 1.2rem">+ Add Expense</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
            <th style="padding:.7rem 1rem;text-align:left">Date</th>
            <th style="padding:.7rem 1rem;text-align:left">Category</th>
            <th style="padding:.7rem 1rem;text-align:left">Description</th>
            <th style="padding:.7rem 1rem;text-align:left">Supplier</th>
            <th style="padding:.7rem 1rem;text-align:right">Amount</th>
            <th style="padding:.7rem 1rem;text-align:center">Actions</th>
          </tr></thead>
          <tbody id="exp-tbody"><tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Expense Modal -->
    <div id="exp-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:450px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('exp-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">&#10005;</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem">💼 Add Expense</div>
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <select id="exp-category" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            <option value="ingredients">🥬 Ingredients</option><option value="packaging">📦 Packaging</option>
            <option value="utilities">⚡ Utilities</option><option value="rent">🏠 Rent</option>
            <option value="salary">👥 Salary</option><option value="equipment">🔧 Equipment</option>
            <option value="marketing">📢 Marketing</option><option value="other">📌 Other</option>
          </select>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Amount (MMK)</label><input id="exp-amount" type="number" min="0" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Date</label><input id="exp-date" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <input id="exp-desc" type="text" placeholder="Description" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <select id="exp-supplier" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"><option value="">No supplier</option></select>
          <input id="exp-ref" type="text" placeholder="Receipt ref (optional)" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <button class="btn btn-primary" onclick="expSave()" style="padding:.7rem;font-size:1rem">✅ Save</button>
        </div>
      </div>
    </div>

    <!-- __ PROMOTIONS PAGE __ -->
    <div id="page-promos" style="display:none">
      <div class="page-head"><h1 class="page-title">🎁 Promotions</h1></div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
        <div id="promo-stats" style="display:flex;gap:1rem;flex-wrap:wrap"></div>
        <button class="btn btn-primary" onclick="promoOpenNew()" style="padding:.5rem 1.2rem">+ New Promotion</button>
      </div>
      <div class="card" style="padding:0;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead><tr style="background:var(--surface2);border-bottom:1px solid var(--border)">
            <th style="padding:.7rem 1rem;text-align:left">Name</th>
            <th style="padding:.7rem 1rem;text-align:left">Type</th>
            <th style="padding:.7rem 1rem;text-align:left">Code</th>
            <th style="padding:.7rem 1rem;text-align:right">Value</th>
            <th style="padding:.7rem 1rem;text-align:left">Conditions</th>
            <th style="padding:.7rem 1rem;text-align:center">Used</th>
            <th style="padding:.7rem 1rem;text-align:center">Status</th>
            <th style="padding:.7rem 1rem;text-align:center">Actions</th>
          </tr></thead>
          <tbody id="promo-tbody"><tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Promo Modal -->
    <div id="promo-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:520px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('promo-modal').style.display='none'" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="promo-modal-title">🎁 New Promotion</div>
        <input type="hidden" id="promo-edit-id">
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <input id="promo-name" type="text" placeholder="Promotion name" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <select id="promo-type" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
              <option value="percent_off">% Off</option><option value="fixed_off">Fixed MMK Off</option>
              <option value="bogo">Buy 1 Get 1</option><option value="combo">Combo Deal</option>
            </select>
            <input id="promo-code" type="text" placeholder="Code (empty=auto)" style="padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);text-transform:uppercase">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Value</label><input id="promo-value" type="number" value="10" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Min Order</label><input id="promo-min" type="number" value="0" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Max Discount</label><input id="promo-max" type="number" placeholder="No cap" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Start Date</label><input id="promo-start" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">End Date</label><input id="promo-end" type="date" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem">
            <div><label style="font-size:.78rem;color:var(--text-muted)">Happy Hour Start</label><input id="promo-hh-start" type="time" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
            <div><label style="font-size:.78rem;color:var(--text-muted)">Happy Hour End</label><input id="promo-hh-end" type="time" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></div>
          </div>
          <input id="promo-days" type="text" placeholder="Days: mon,tue,wed,thu,fri (empty=all)" style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          <button class="btn btn-primary" onclick="promoSave()" style="padding:.7rem;font-size:1rem">✅ Save</button>
        </div>
      </div>
    </div>

    <!-- ── DELIVERY PAGE ── -->

    <div id="page-delivery" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🛵 Delivery Management</h1>
      </div>

      <!-- Drivers Strip -->
      <div class="card" style="padding:1rem;margin-bottom:1rem">
        <div style="font-weight:700;margin-bottom:.6rem">🧑‍✈️ Drivers</div>
        <div id="dlv-drivers" style="display:flex;gap:.8rem;flex-wrap:wrap"></div>
      </div>

      <!-- Two columns: Pending + Active -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <!-- Pending -->
        <div class="card" style="padding:0">
          <div style="padding:1rem;font-weight:700;border-bottom:1px solid var(--border)">📋 Unassigned Orders</div>
          <div id="dlv-pending" style="padding:.8rem;max-height:500px;overflow-y:auto">
            <div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>
          </div>
        </div>
        <!-- Active -->
        <div class="card" style="padding:0">
          <div style="padding:1rem;font-weight:700;border-bottom:1px solid var(--border)">🛵 Active Deliveries</div>
          <div id="dlv-active" style="padding:.8rem;max-height:500px;overflow-y:auto">
            <div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── BRANCHES PAGE ── -->
    <div id="page-branches" style="display:none">
      <div class="page-head">
        <h1 class="page-title">🏢 Branch Management</h1>
      </div>

      <!-- Cross-branch Dashboard -->
      <div id="branch-dashboard" style="margin-bottom:1.5rem"></div>

      <!-- Branch Cards -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <span style="font-weight:700;font-size:1rem">All Branches</span>
        <button class="btn btn-primary" onclick="branchOpenNew()" style="padding:.5rem 1.2rem">+ New Branch</button>
      </div>
      <div id="branch-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
      </div>
    </div>

    <!-- Branch Create/Edit Modal -->
    <div id="branch-modal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.6);overflow-y:auto">
      <div style="max-width:480px;margin:2rem auto;background:var(--surface);border-radius:16px;padding:2rem;position:relative">
        <button onclick="document.getElementById('branch-modal').style.display='none'"
          style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--text-muted);font-size:1.4rem;cursor:pointer">✕</button>
        <div style="font-weight:700;font-size:1.1rem;margin-bottom:1.2rem" id="branch-modal-title">🏢 New Branch</div>
        <input type="hidden" id="branch-edit-id">
        <div style="display:flex;flex-direction:column;gap:1rem">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Branch Name *</label>
              <input id="branch-name" type="text" placeholder="MyanAi POS Mandalay"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Code *</label>
              <input id="branch-code" type="text" placeholder="MDY1" maxlength="20"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);text-transform:uppercase">
            </div>
          </div>
          <div>
            <label style="font-size:.82rem;color:var(--text-muted)">Address</label>
            <input id="branch-address" type="text" placeholder="Street, City"
              style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem">
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Phone</label>
              <input id="branch-phone" type="tel" placeholder="09xxx"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Open</label>
              <input id="branch-open" type="time" value="10:00"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
            <div>
              <label style="font-size:.82rem;color:var(--text-muted)">Close</label>
              <input id="branch-close" type="time" value="23:00"
                style="width:100%;padding:.6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)">
            </div>
          </div>
          <button class="btn btn-primary" onclick="branchSave()" style="padding:.7rem;font-size:1rem">✅ Save Branch</button>
        </div>
      </div>
    </div>

        <!-- __ SAAS ADMIN __ -->
    <div <div id="page-saas" style="display:none">
  <div class="page-head"><h1 class="page-title">🌐 SaaS Dashboard</h1>
    <a href="signup.html" target="_blank" style="background:#e84c2b;color:#fff;padding:.4rem 1rem;border-radius:8px;text-decoration:none;font-size:.85rem">+ New Tenant</a>
  </div>

  <!-- Stats row -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem" id="saas-stat-cards">
    <div class="stat-card"><div class="stat-label">Total Tenants</div><div class="stat-val" id="sc-tenants">—</div></div>
    <div class="stat-card"><div class="stat-label">Active Today</div><div class="stat-val" id="sc-active">—</div></div>
    <div class="stat-card"><div class="stat-label">Pro+ Plans</div><div class="stat-val" id="sc-pro">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Revenue</div><div class="stat-val" id="sc-revenue">—</div></div>
  </div>

  <!-- Tenants table -->
  <div class="card" style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.85rem" id="saas-tenants-table">
      <thead>
        <tr style="border-bottom:2px solid var(--border);text-align:left">
          <th style="padding:.6rem">Business</th>
          <th>Plan</th>
          <th>Branches</th>
          <th>Orders</th>
          <th>Revenue</th>
          <th>Trial Expires</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="saas-tenants-body">
        <tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--muted)">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── ADD / EDIT MODAL ── -->
<div class="modal-bg" id="item-modal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modal-title">Add Menu Item</h3>
      <button class="x-btn" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f-id">
      <div class="form-grid">
        <div class="form-group">
          <label>Emoji</label>
          <input type="text" id="f-emoji" placeholder="🍜" maxlength="4" style="font-size:1.4rem;text-align:center">
        </div>
        <div class="form-group">
          <label>Category</label>
          <select id="f-cat">
            <option>Noodles</option><option>Rice</option><option>Starters</option>
            <option>Soups</option><option>Desserts</option><option>Drinks</option>
          </select>
        </div>
        <div class="form-group full-width">
          <label>Item Name *</label>
          <input type="text" id="f-name" placeholder="e.g. Mohinga">
        </div>
        <div class="form-group full-width">
          <label>Description</label>
          <textarea id="f-desc" placeholder="Short description…"></textarea>
        </div>
        <div class="form-group">
          <label>Price (USD $) *</label>
          <input type="number" id="f-price" placeholder="4500" min="0">
        </div>
        <div class="form-group">
          <label>Stock Qty *</label>
          <input type="number" id="f-stock" placeholder="20" min="0">
        </div>
        <div class="form-group full-width" id="active-row" style="display:none">
          <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
            <input type="checkbox" id="f-active" style="width:16px;height:16px">
            Show on menu (Active)
          </label>
        </div>
        <div class="form-group" id="station-row">
          <label>Kitchen Station</label>
          <select id="f-station">
            <option value="kitchen">🍳 Kitchen</option>
            <option value="counter">🥤 Counter</option>
            <option value="bar">🍹 Bar</option>
            <option value="all">📋 All Stations</option>
          </select>
        </div>
        <div class="form-group full-width" id="img-upload-row" style="display:none">
          <label>ဓာတ်ပုံ (optional)</label>
          <div style="display:flex;align-items:center;gap:.8rem;flex-wrap:wrap">
            <img id="img-current-preview" class="img-current" src="" alt="" style="display:none">
            <div style="flex:1">
              <div class="img-upload-area" onclick="document.getElementById('img-file-input').click()">
                <input type="file" id="img-file-input" accept="image/*" onchange="previewImg(this)">
                <div id="img-upload-label">📷 ဓာတ်ပုံရွေးချယ်ရန် နှိပ်ပါ<br><small style="color:var(--muted)">JPG/PNG/GIF/WEBP — Max 2MB</small></div>
                <img id="img-new-preview" class="img-preview" src="" alt="" style="display:none">
              </div>
              <div style="display:flex;gap:.5rem;margin-top:.5rem">
                <button type="button" class="btn btn-ghost btn-sm" onclick="uploadImg()" id="img-upload-btn" style="display:none">↑ Upload</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeImg()" id="img-remove-btn" style="display:none">✕ Remove</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="justify-content:space-between">
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button class="btn btn-ghost" id="modifier-btn" onclick="openModifierModal()" style="display:none">⚙️ Modifiers</button>
      </div>
      <button class="btn btn-primary" id="modal-save-btn" onclick="saveItem()">Save Item</button>
    </div>
  </div>
</div>

<!-- ── MODIFIER SECTION (shown below item modal when editing) ── -->
<div class="modal-bg" id="modifier-modal">
  <div class="modal" style="max-width:680px">
    <div class="modal-head">
      <h3>⚙️ Modifiers — <span id="mod-item-name"></span></h3>
      <button class="x-btn" onclick="closeModifierModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:1rem">
        Modifier group တွေ ထည့်ပြီး customer မှာတဲ့အချိန် ရွေးချယ်နိုင်အောင် လုပ်ပေးပါ။
      </p>
      <div id="modifier-groups-list"></div>
      <button class="btn btn-ghost" style="width:100%;margin-top:.8rem" onclick="openAddGroupForm()">
        + Add Modifier Group
      </button>

      <!-- Add/Edit Group Form -->
      <div id="group-form" style="display:none;margin-top:1rem;padding:1rem;background:var(--surface);border-radius:10px;border:1px solid var(--border)">
        <input type="hidden" id="gf-id">
        <div class="form-grid">
          <div class="form-group">
            <label>Group Name *</label>
            <input type="text" id="gf-name" placeholder="e.g. Size, Ice Level">
          </div>
          <div class="form-group">
            <label>Type</label>
            <select id="gf-type">
              <option value="single">Single Select (တစ်ခုပဲရွေး)</option>
              <option value="multi">Multi Select (တစ်ခုထက်ပိုရွေး)</option>
              <option value="text">Free Text (မှတ်ချက်)</option>
            </select>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
              <input type="checkbox" id="gf-required" style="width:16px;height:16px">
              Required (မဖြစ်မနေ ရွေးရမည်)
            </label>
          </div>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:.5rem">
          <button class="btn btn-primary btn-sm" onclick="saveModifierGroup()">Save Group</button>
          <button class="btn btn-ghost btn-sm" onclick="cancelGroupForm()">Cancel</button>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-primary" onclick="closeModifierModal()">Done</button>
    </div>
  </div>
</div>

<!-- ── OPTION FORM MODAL ── -->
<div class="modal-bg" id="option-modal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head">
      <h3 id="opt-modal-title">Add Option</h3>
      <button class="x-btn" onclick="closeOptionModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="of-id">
      <input type="hidden" id="of-group-id">
      <div class="form-group">
        <label>Option Label *</label>
        <input type="text" id="of-label" placeholder="e.g. Large, No Ice, Extra Egg">
      </div>
      <div class="form-group">
        <label>Extra Price (ကျပ်) — 0 = free</label>
        <input type="number" id="of-price" placeholder="0" min="0" value="0">
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" id="of-default" style="width:16px;height:16px">
          Default selection
        </label>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeOptionModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveModifierOption()">Save</button>
    </div>
  </div>
</div>
<div class="modal-bg" id="batch-modal">
  <div class="modal" style="max-width:720px">
    <div class="modal-head">
      <h3>📥 Batch Upload Menu Items</h3>
      <button class="x-btn" onclick="closeBatchModal()">✕</button>
    </div>
    <div class="modal-body" id="batch-modal-body">

      <!-- Step 1: Upload -->
      <div id="batch-step1">
        <div style="background:var(--warm);border-radius:10px;padding:1rem;margin-bottom:1rem;font-size:.85rem;line-height:1.8;border:1px solid var(--border)">
          <strong>CSV format (ပထမ row = header) —</strong><br>
          <code style="font-size:.8rem">name, category, price, stock, emoji, description</code><br>
          <span style="color:var(--muted)">Category (English): Noodles / Rice / Starters / Soups / Desserts / Drinks</span><br>
          <span style="color:var(--muted)">Myanmar aliases: ခေါက်ဆွဲ=Noodles · ထမင်း=Rice · ဟင်းချို=Soups · အချိုရည်=Drinks</span><br>
          <span style="color:var(--muted)">Price: dollar cents မဟုတ်ဘဲ display value (e.g. 4.50)</span>
        </div>

        <!-- Drop zone -->
        <div id="batch-dropzone"
          style="border:2px dashed var(--border);border-radius:12px;padding:2.5rem;text-align:center;cursor:pointer;transition:border-color .2s;background:var(--warm)"
          onclick="document.getElementById('batch-file').click()"
          ondragover="event.preventDefault();this.style.borderColor='var(--ink)'"
          ondragleave="this.style.borderColor='var(--border)'"
          ondrop="event.preventDefault();this.style.borderColor='var(--border)';handleBatchFile(event.dataTransfer.files[0])">
          <div style="font-size:2.5rem;margin-bottom:.5rem">📂</div>
          <div style="font-weight:600;margin-bottom:.3rem">CSV ဖိုင် ဒီနေရာတွင် ချထားပါ</div>
          <div style="font-size:.82rem;color:var(--muted)">သို့မဟုတ် နှိပ်ပြီး ရွေးပါ (.csv only)</div>
          <input type="file" id="batch-file" accept=".csv,.txt" style="display:none"
            onchange="handleBatchFile(this.files[0])">
        </div>

        <div style="margin-top:.8rem;text-align:center">
          <button class="btn btn-ghost btn-sm" onclick="downloadTemplate()">⬇ CSV Template ဒေါင်းလုဒ်</button>
        </div>
      </div>

      <!-- Step 2: Preview -->
      <div id="batch-step2" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.5rem">
          <div id="batch-summary" style="font-size:.88rem"></div>
          <button class="btn btn-ghost btn-sm" onclick="resetBatch()">↺ ပြန်ရွေး</button>
        </div>

        <!-- Errors -->
        <div id="batch-errors" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.8rem 1rem;margin-bottom:.8rem;font-size:.82rem;color:#991b1b;max-height:100px;overflow-y:auto"></div>

        <!-- Preview table -->
        <div style="overflow-x:auto;max-height:320px;overflow-y:auto;border-radius:8px;border:1px solid var(--border)">
          <table style="width:100%;border-collapse:collapse;font-size:.82rem">
            <thead style="position:sticky;top:0;background:var(--warm);z-index:1">
              <tr>
                <th style="padding:.5rem .8rem;text-align:left;font-size:.72rem;text-transform:uppercase;color:var(--muted)">Row</th>
                <th style="padding:.5rem .8rem;text-align:left">Name</th>
                <th style="padding:.5rem .8rem;text-align:left">Category</th>
                <th style="padding:.5rem .8rem;text-align:right">Price</th>
                <th style="padding:.5rem .8rem;text-align:right">Stock</th>
                <th style="padding:.5rem .8rem;text-align:center">Emoji</th>
                <th style="padding:.5rem .8rem;text-align:left">Description</th>
              </tr>
            </thead>
            <tbody id="batch-preview-body"></tbody>
          </table>
        </div>
      </div>

    </div>
    <div class="modal-foot" id="batch-modal-foot">
      <button class="btn btn-ghost" onclick="closeBatchModal()">Cancel</button>
    </div>
  </div>
</div>

<!-- ── RESTOCK MODAL ── -->
<div class="modal-bg" id="restock-modal">
  <div class="modal" style="max-width:360px">
    <div class="modal-head">
      <h3>↑ Restock</h3>
      <button class="x-btn" onclick="closeRestock()">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-size:.9rem;color:var(--muted);margin-bottom:.8rem" id="restock-name"></div>
      <div style="font-size:.85rem;margin-bottom:.8rem">Current stock: <strong id="restock-current"></strong></div>
      <div class="form-group">
        <label>Add Qty</label>
        <input type="number" id="restock-qty" placeholder="e.g. 50" min="1" style="font-size:1rem">
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="setRestock(10)">+10</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(20)">+20</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(50)">+50</button>
        <button class="btn btn-ghost btn-sm" onclick="setRestock(100)">+100</button>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeRestock()">Cancel</button>
      <button class="btn btn-success" onclick="doRestock()">✓ Add Stock</button>
    </div>
  </div>
</div>

<?php endif; ?>
<!-- DELETE ORDER MODAL -->
<div class="modal-bg" id="del-order-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-head" style="background:#991b1b">
      <h3>🗑 Delete Order</h3>
      <button class="x-btn" onclick="closeDelOrder()">✕</button>
    </div>
    <div class="modal-body">
      <div style="font-size:.9rem;margin-bottom:1rem">
        Order <strong id="del-order-ref"></strong> ကို ဖျက်မည်။
        <span style="color:var(--muted);font-size:.82rem">Record ကိုတော့ archive ထားမည်။</span>
      </div>
      <div style="font-size:.82rem;font-weight:600;margin-bottom:.5rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Reason ရွေးပါ</div>
      <div class="reason-grid" id="reason-grid"></div>
      <div class="form-group" style="margin-top:.5rem">
        <label>Remark (ထပ်ဖြည့်ရန်)</label>
        <textarea id="del-remark" placeholder="ပိုရှင်းလင်းသော အကြောင်းပြချက်…" style="min-height:60px"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="closeDelOrder()">Cancel</button>
      <button class="btn btn-danger" onclick="confirmDelOrder()">🗑 Delete</button>
    </div>
  </div>
</div>

<!-- DELETED ORDERS LOG MODAL -->
<div class="modal-bg" id="deleted-log-modal">
  <div class="modal" style="max-width:700px">
    <div class="modal-head">
      <h3>📁 Deleted Orders Archive</h3>
      <button class="x-btn" onclick="document.getElementById('deleted-log-modal').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body" style="padding:.8rem">
      <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <th>Order</th><th>Customer</th><th>Total</th>
            <th>Reason</th><th>Deleted At</th>
          </tr></thead>
          <tbody id="deleted-log-body">
            <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- MOBILE BOTTOM NAV -->
<div class="mobile-nav" id="mobile-nav">
  <div class="mobile-nav-inner">
    <button class="mnav-btn active" id="mnav-dashboard" onclick="showPage('dashboard')">
      <span class="mnav-icon">📊</span>Dashboard
    </button>
    <button class="mnav-btn" id="mnav-menu" onclick="showPage('menu')">
      <span class="mnav-icon">🍜</span>Menu
    </button>
    <button class="mnav-btn" id="mnav-orders" onclick="showPage('orders')">
      <span class="mnav-icon">📋</span>Orders
    </button>
    <button class="mnav-btn" id="mnav-tables" onclick="showPage('tables')">
      <span class="mnav-icon">🍽️</span>Tables
    </button>
    <button class="mnav-btn" id="mnav-reserve" onclick="showPage('reserve')">
      <span class="mnav-icon">📅</span>Reserve
    </button>
    <button class="mnav-btn" id="mnav-stock" onclick="showPage('stock')">
      <span class="mnav-icon">📦</span>Stock
    </button>
    <button class="mnav-btn" id="mnav-crm" onclick="showPage('crm')">
      <span class="mnav-icon">👥</span>CRM
    </button>
    <button class="mnav-btn" id="mnav-shift" onclick="showPage('shift')">
      <span class="mnav-icon">🕐</span>Shifts
    </button>
    <button class="mnav-btn" id="mnav-delivery" onclick="showPage('delivery')">
      <span class="mnav-icon">🛵</span>Delivery
    </button>
    <button class="mnav-btn" id="mnav-branches" onclick="showPage('branches')">
      <span class="mnav-icon">🏢</span>Branches
    </button>
    <button class="mnav-btn" id="mnav-settings" onclick="showPage('settings')">
      <span class="mnav-icon">⚙️</span>Settings
    </button>
    <button class="mnav-btn" onclick="doLogout()">
      <span class="mnav-icon">↩</span>Logout
    </button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// Set CSRF token for admin_main.js
window.__CSRF_TOKEN    = '<?= $csrfToken ?>';
window.__TENANT_ID     = <?= json_encode($_SESS_TENANT_ID) ?>;
window.__TENANT_NAME   = <?= json_encode($_SESS_TENANT_NAME) ?>;
window.__TENANT_PLAN   = <?= json_encode($_SESS_TENANT_PLAN) ?>;
window.__PLAN_EXPIRES  = <?= json_encode($_SESS_PLAN_EXPIRES) ?>;
window.__IS_TENANT     = <?= json_encode($_IS_TENANT) ?>;
window.__USER_ROLE     = <?= json_encode($_SESSION['admin']['role'] ?? 'superadmin') ?>;
// Auto-set branch context for tenant login
if(window.__TENANT_ID > 0 && window.__IS_TENANT) {
  window._currentTenant = window.__TENANT_ID;
}
</script>
<!-- ── UPGRADE PAGE ── -->
<div id="page-upgrade" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">⬆ Plan Upgrade</div>
    </div>
  </div>
  <div class="content">
    <!-- Current plan banner -->
    <div id="upgrade-current-plan" style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem">
      <span style="font-size:2rem">📋</span>
      <div>
        <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em">လက်ရှိ Plan</div>
        <div style="font-size:1.1rem;font-weight:700" id="upgrade-plan-label">Loading…</div>
        <div style="font-size:.8rem;color:var(--muted)" id="upgrade-expires-label"></div>
      </div>
    </div>

    <!-- Plan cards -->
    <div id="upgrade-plans-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem">
      <div style="text-align:center;padding:2rem;color:var(--muted)">Loading plans…</div>
    </div>

    <!-- Request note -->
    <div id="upgrade-request-section" style="display:none;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem">
      <div style="font-weight:600;margin-bottom:.6rem">📩 Upgrade Request ပို့မည် — <span id="upgrade-target-plan-name"></span></div>
      <textarea id="upgrade-note" placeholder="မှတ်ချက် (ရွေးချယ်နိုင်သည်)…" style="width:100%;min-height:80px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.6rem;color:var(--text);font-size:.85rem;resize:vertical;box-sizing:border-box"></textarea>
      <div style="display:flex;gap:.5rem;margin-top:.8rem">
        <button class="btn btn-primary" onclick="submitUpgradeRequest()" id="upgrade-submit-btn">📩 Request ပို့မည်</button>
        <button class="btn btn-ghost" onclick="document.getElementById('upgrade-request-section').style.display='none'">Cancel</button>
      </div>
    </div>

    <!-- Success msg -->
    <div id="upgrade-success" style="display:none;background:#064e3b;color:#d1fae5;border-radius:var(--radius);padding:1rem 1.2rem;margin-top:1rem">
      ✅ Upgrade request ပေးပို့ပြီးပါပြီ။ Admin မှ မကြာမီ ဆက်သွယ်ပါမည်။
    </div>
  </div>
</div>

<div id="page-orders" style="display:none">
  <div class="page-head"><h1 class="page-title">📋 Orders</h1></div>
  <div id="orders-container"><p style="color:var(--muted);padding:2rem">Loading orders...</p></div>
</div>

<div id="page-settings" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <h1 class="page-title">⚙️ Settings</h1>
    </div>
  </div>
  <div class="content">

    <!-- KBZPay / Payment Settings -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem;margin-bottom:1rem">
      <div style="font-weight:700;font-size:.95rem;margin-bottom:1rem">💜 KBZPay (KPay) Settings</div>
      <div style="display:grid;gap:.8rem">
        <div>
          <label style="font-size:.82rem;color:var(--muted);display:block;margin-bottom:.3rem">Merchant ID / Phone Number</label>
          <input id="set-kpay-merchant" type="text" placeholder="09xxxxxxxxx" style="width:100%;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:.88rem;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:.82rem;color:var(--muted);display:block;margin-bottom:.3rem">QR Code Image URL</label>
          <input id="set-kpay-qr" type="text" placeholder="https://... သို့မဟုတ် base64 image" style="width:100%;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:.88rem;box-sizing:border-box">
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
          <div id="set-kpay-preview" style="display:none;border:1px solid var(--border);border-radius:8px;overflow:hidden;width:100px;height:100px;background:var(--bg2)">
            <img id="set-kpay-preview-img" src="" style="width:100%;height:100%;object-fit:contain">
          </div>
          <div>
            <input type="file" id="set-kpay-file" accept="image/*" style="display:none" onchange="previewKpayQR(this)">
            <button onclick="document.getElementById('set-kpay-file').click()" class="btn btn-ghost" style="font-size:.82rem">📷 QR Image Upload</button>
            <div style="font-size:.75rem;color:var(--muted);margin-top:.3rem">PNG/JPG — customer ကို checkout မှာပြမည်</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Wave Money Settings -->
    <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem;margin-bottom:1rem">
      <div style="font-weight:700;font-size:.95rem;margin-bottom:1rem">🌊 Wave Money Settings</div>
      <div style="display:grid;gap:.8rem">
        <div>
          <label style="font-size:.82rem;color:var(--muted);display:block;margin-bottom:.3rem">Merchant ID / Phone Number</label>
          <input id="set-wave-merchant" type="text" placeholder="09xxxxxxxxx" style="width:100%;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:.88rem;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:.82rem;color:var(--muted);display:block;margin-bottom:.3rem">QR Code Image URL</label>
          <input id="set-wave-qr" type="text" placeholder="https://..." style="width:100%;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:.88rem;box-sizing:border-box">
        </div>
      </div>
    </div>

    <button class="btn btn-primary" onclick="savePaymentSettings()" style="min-width:140px">💾 Save Settings</button>
    <div id="settings-saved-msg" style="display:none;margin-top:.75rem;color:#059669;font-size:.85rem">✅ Settings saved successfully!</div>
  </div>
</div>

<div id="page-storefront" style="display:none">
  <div class="page-head"><h1 class="page-title">🛍 Storefront</h1></div>
  <p style="color:var(--muted);padding:1rem">Storefront customisation coming soon.</p>
</div>

<script src="admin_main.js?v=<?= time() ?>"></script>
<script>
function twRow(n,f){
  var d=document.createElement('div'+branchParams());
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px';
  d.innerHTML='<input class="tw-n" placeholder="မြို့နယ်" value="'+n+'" style="flex:2;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input type="number" class="tw-f" placeholder="Ks" value="'+f+'" style="flex:1;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function prRow(c,t,v,l){
  var d=document.createElement('div'+branchParams());
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px;flex-wrap:wrap';
  d.innerHTML='<input class="pr-c" placeholder="CODE" value="'+c+'" style="width:90px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem;text-transform:uppercase">'
    +'<select class="pr-t" style="padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<option value="fixed"'+(t==='fixed'?' selected':''+branchParams())+'>Fixed Ks</option>'
    +'<option value="percent"'+(t==='percent'?' selected':''+branchParams())+'>Percent %</option>'
    +'<option value="free_ship"'+(t==='free_ship'?' selected':''+branchParams())+'>Free ship</option></select>'
    +'<input type="number" class="pr-v" placeholder="value" value="'+v+'" style="width:80px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input class="pr-l" placeholder="label" value="'+l+'" style="flex:1;min-width:100px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function addTownshipRow(){document.getElementById('township-fee-editor'+branchParams()).appendChild(twRow('',''+branchParams()));}
function addPromoRow(){document.getElementById('promo-code-editor'+branchParams()).appendChild(prRow('','fixed','',''+branchParams()));}
function initTownshipEditors(s){
  var tw={};try{tw=JSON.parse(s.township_fees||'{}'+branchParams());}catch(e){}
  var wrap=document.getElementById('township-fee-editor'+branchParams());
  if(wrap){wrap.innerHTML='';Object.entries(tw).forEach(function(e){wrap.appendChild(twRow(e[0],e[1]));});}
  var pr=[];try{pr=JSON.parse(s.promo_codes||'[]'+branchParams());}catch(e){}
  var pw=document.getElementById('promo-code-editor'+branchParams());
  if(pw){pw.innerHTML='';pr.forEach(function(p){pw.appendChild(prRow(p.code||'',p.type||'fixed',p.value||'',p.label||''+branchParams()));});}
}
function collectTownshipPromo(){
  var tw={};
  document.querySelectorAll('#township-fee-editor>div'+branchParams()).forEach(function(r){
    var n=r.querySelector('.tw-n'+branchParams()).value.trim();
    var f=parseInt(r.querySelector('.tw-f'+branchParams()).value)||0;
    if(n)tw[n]=f;
  });
  document.getElementById('st-township_fees'+branchParams()).value=JSON.stringify(tw);
  var pr=[];
  document.querySelectorAll('#promo-code-editor>div'+branchParams()).forEach(function(r){
    var c=r.querySelector('.pr-c'+branchParams()).value.trim().toUpperCase();
    var t=r.querySelector('.pr-t'+branchParams()).value;
    var v=parseInt(r.querySelector('.pr-v'+branchParams()).value)||0;
    var l=r.querySelector('.pr-l'+branchParams()).value.trim();
    if(c)pr.push({code:c,type:t,value:v,label:l});
  });
  document.getElementById('st-promo_codes'+branchParams()).value=JSON.stringify(pr);
}
</script>
<script src="admin_modules.js?v=1781166732"></script>
<script>
(function(){
var slOff=0,slTotal=0,SL_LIMIT=50;
var AC={add:{bg:"#dcfce7",c:"#166534",l:"ထည့်သွင်း"},remove:{bg:"#fee2e2",c:"#991b1b",l:"ထုတ်ယူ"},adjust:{bg:"#dbeafe",c:"#1e40af",l:"ပြင်ဆင်"},waste:{bg:"#fef9c3",c:"#854d0e",l:"ဖျက်ဆီး"},order_deduct:{bg:"#f3e8ff",c:"#6b21a8",l:"Order နုတ်"},initial:{bg:"#f0f9ff",c:"#0c4a6e",l:"စတင်"}};
function esc(s){return String(s||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
window.loadStockLogs=function(r){
  if(r!==false)slOff=0;
  var today=new Date(),fd=new Date(today.getFullYear(),today.getMonth(),1);
  document.getElementById("sl-date-from").value=fd.toISOString().split("T")[0];
  document.getElementById("sl-date-to").value=today.toISOString().split("T")[0];
  var p=new URLSearchParams({action:"list",limit:SL_LIMIT,offset:slOff,search:document.getElementById("sl-search").value,action_type:document.getElementById("sl-action").value,date_from:document.getElementById("sl-date-from").value,date_to:document.getElementById("sl-date-to").value});
  document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:#aaa>Loading...</td></tr>";
  fetch("stock_log_api.php?"+p).then(function(r){return r.json();}).then(function(d){
    slTotal=d.total||0;
    var rows=d.logs||[];
    if(!rows.length){document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:#aaa>မှတ်တမ်း မရှိသေး</td></tr>";}
    else{document.getElementById("sl-tbody").innerHTML=rows.map(function(r,i){
      var chg=parseFloat(r.change_qty),cc=chg>0?"#16a34a":chg<0?"#dc2626":"#888",cs=chg>0?"+":"";
      var rL={restock:"📥 Restock",manual_adjust:"✏️ Adjust",order_deduct:"🛒 Order",waste:"🗑 Waste",correction:"🔧 Fix",returned:"↩ Return"};
      var rBg=chg>0?"#dcfce7":chg<0?"#fee2e2":"#f3f4f6",rC=chg>0?"#166534":chg<0?"#991b1b":"#555";
      return "<tr style=border-bottom:1px solid #eee;"+(i%2?"background:#fafafa":"")+">"
        +"<td style=padding:8px 12px;color:#aaa>"+r.id+"</td>"
        +"<td style=padding:8px 12px;font-weight:500>"+esc(r.item_name)+"</td>"
        +"<td style=padding:8px 12px;text-align:center><span style=background:"+rBg+";color:"+rC+";padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600>"+(rL[r.reason]||r.reason||"—")+"</span></td>"
        +"<td style=padding:8px 12px;text-align:center;color:#888>—</td>"
        +"<td style=padding:8px 12px;text-align:center;font-weight:600>"+r.new_qty+"</td>"
        +"<td style=padding:8px 12px;text-align:center;font-weight:600;color:"+cc+">"+cs+chg+"</td>"
        +"<td style=padding:8px 12px;color:#666;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap>"+esc(r.note||"—")+"</td>"
        +"<td style=padding:8px 12px;color:#555>"+esc(r.staff_name||"—")+"</td>"
        +"<td style=padding:8px 12px;color:#888;font-size:12px;white-space:nowrap>"+r.created_fmt+"</td></tr>";
    }).join("");}
    document.getElementById("sl-count").textContent=(slOff+1)+"–"+Math.min(slOff+SL_LIMIT,slTotal)+" / "+slTotal+" records";
    document.getElementById("sl-prev").disabled=slOff===0;
    document.getElementById("sl-next").disabled=slOff+SL_LIMIT>=slTotal;
  }).catch(function(){document.getElementById("sl-tbody").innerHTML="<tr><td colspan=9 style=text-align:center;padding:30px;color:red>API error</td></tr>";});
  fetch("stock_log_api.php?action=summary&date_from="+document.getElementById("sl-date-from").value+"&date_to="+document.getElementById("sl-date-to").value).then(function(r){return r.json();}).then(function(d){
    var el=document.getElementById("sl-summary");
    if(!d.summary||!d.summary.length){el.innerHTML="";return;}
    el.innerHTML=d.summary.map(function(s){var st=AC[s.action]||{bg:"#f5f5f5",c:"#333",l:s.action};return "<div style=background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center><div style=font-size:10px;font-weight:600;color:"+st.c+";background:"+st.bg+";padding:2px 8px;border-radius:10px;display:inline-block;margin-bottom:6px>"+st.l+"</div><div style=font-size:22px;font-weight:700>"+s.total_entries+"</div><div style=font-size:11px;color:#888>entries</div></div>";}).join("");
  });
};
window.slPage=function(d){slOff=Math.max(0,slOff+d*SL_LIMIT);loadStockLogs(false);};
window.exportSL=function(){window.open("stock_log_api.php?action=export_csv&date_from="+document.getElementById("sl-date-from").value+"&date_to="+document.getElementById("sl-date-to").value,"_blank");};
})();
</script>

<script>
/* ══ STAFF MANAGEMENT ══ */
const ALL_PAGES = [
  {k:'dashboard',l:'📊 Dashboard'},{k:'orders',l:'📋 Orders'},
  {k:'menu',l:'🍜 Menu'},{k:'tables',l:'🍽️ Tables'},
  {k:'stock',l:'📦 Stock'},{k:'stocklog',l:'📋 Stock Log'},
  {k:'crm',l:'👥 CRM'},{k:'shift',l:'🕐 Shifts'},
  {k:'reserve',l:'📅 Reservations'},{k:'delivery',l:'🛵 Delivery'},
  {k:'branches',l:'🏢 Branches'},{k:'analytics',l:'📈 Analytics'},
  {k:'settings',l:'⚙️ Settings'}
];
async function loadStaff(){
  const el=document.getElementById('staff-list'+branchParams());
  el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>';
  try{
    const d=await(await fetch('staff_api.php?action=list'+branchParams())).json();
    if(!d.ok)throw new Error(d.msg);
    if(!d.staff.length){el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">No staff yet</div>';return;}
    el.innerHTML=d.staff.map(s=>{
      const perms=(s.permissions||[]).map(p=>{const pg=ALL_PAGES.find(x=>x.k===p);return pg?`<span style="font-size:.72rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.15rem .5rem">${pg.l}</span>`:''}).join(' '+branchParams());
      const roleBadge=s.role==='manager'?'<span style="background:#dbeafe;color:#1e40af;padding:.2rem .6rem;border-radius:10px;font-size:.75rem;font-weight:600">👔 Manager</span>':'<span style="background:#f3f4f6;color:#555;padding:.2rem .6rem;border-radius:10px;font-size:.75rem;font-weight:600">🧑‍🍳 Waiter</span>';
      const activeBadge=s.is_active?'<span style="color:#27ae60;font-size:.75rem">● Active</span>':'<span style="color:#e74c3c;font-size:.75rem">● Inactive</span>';
      const sData=encodeURIComponent(JSON.stringify(s));
      return `<div class="card" style="padding:1rem 1.2rem;${!s.is_active?'opacity:.55':''}">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem">
          <div style="display:flex;align-items:center;gap:.75rem">
            <div style="width:40px;height:40px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:1.2rem">${s.role==='manager'?'👔':'🧑‍🍳'}</div>
            <div><div style="font-weight:700">${escHtml(s.name)}</div><div style="font-size:.78rem;color:var(--text-muted);margin-top:.1rem">${roleBadge} ${activeBadge}</div></div>
          </div>
          <div style="display:flex;gap:.5rem">
            <button onclick="staffOpenEdit('${sData}')" style="padding:.4rem .8rem;background:var(--surface2);border:1px solid var(--border);border-radius:7px;font-size:.8rem;cursor:pointer;color:var(--text)">✏️ Edit</button>
            <button onclick="staffToggle(${s.id},${s.is_active})" style="padding:.4rem .8rem;background:var(--surface2);border:1px solid var(--border);border-radius:7px;font-size:.8rem;cursor:pointer;color:${s.is_active?'#e74c3c':'#27ae60'}">${s.is_active?'🚫 Disable':'✅ Enable'}</button>
            <button onclick="staffDelete(${s.id},'${escHtml(s.name)}')" style="padding:.4rem .8rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:7px;font-size:.8rem;cursor:pointer;color:#991b1b">🗑</button>
          </div>
        </div>
        ${perms?`<div style="display:flex;flex-wrap:wrap;gap:.3rem">${perms}</div>`:'<div style="font-size:.75rem;color:var(--text-muted)">No permissions set</div>'}
        ${s.notes?`<div style="font-size:.78rem;color:var(--text-muted);margin-top:.4rem">📝 ${escHtml(s.notes)}</div>`:''}
      </div>`;
    }).join('');
  }catch(e){el.innerHTML=`<div style="color:#e74c3c;padding:2rem;text-align:center">${e.message}</div>`;}
}
function staffRenderPerms(selected=[]){
  document.getElementById('sf-perms').innerHTML=ALL_PAGES.map(p=>
    `<label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.3rem .5rem;border:1px solid var(--border);border-radius:7px;background:${selected.includes(p.k)?'rgba(232,160,48,.1)':'var(--surface2)'}">
      <input type="checkbox" value="${p.k}" ${selected.includes(p.k)?'checked':''} style="accent-color:var(--accent)">${p.l}
    </label>`
  ).join('');
}
function staffOpenAdd(){
  document.getElementById('sf-id').value='';
  document.getElementById('sf-name').value='';
  document.getElementById('sf-pin').value='';
  document.getElementById('sf-role').value='waiter';
  document.getElementById('sf-notes').value='';
  document.getElementById('staff-modal-title').textContent='+ Add Staff';
  staffRenderPerms(['orders','tables']);
  document.getElementById('staff-modal').style.display='flex';
}
function staffOpenEdit(encoded){
  const s=JSON.parse(decodeURIComponent(encoded));
  document.getElementById('sf-id').value=s.id;
  document.getElementById('sf-name').value=s.name;
  document.getElementById('sf-pin').value='';
  document.getElementById('sf-role').value=s.role;
  document.getElementById('sf-notes').value=s.notes||'';
  document.getElementById('staff-modal-title').textContent='✏️ Edit — '+s.name;
  staffRenderPerms(s.permissions||[]);
  document.getElementById('staff-modal').style.display='flex';
}
async function staffSave(){
  const id=document.getElementById('sf-id').value;
  const perms=Array.from(document.querySelectorAll('#sf-perms input:checked')).map(i=>i.value);
  const payload={name:document.getElementById('sf-name').value.trim(),pin:document.getElementById('sf-pin').value.trim(),role:document.getElementById('sf-role').value,permissions:perms,notes:document.getElementById('sf-notes').value.trim()};
  if(id)payload.id=parseInt(id);
  const action=id?'update':'add';
  try{
    const d=await(await fetch('staff_api.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).json();
    if(!d.ok){alert('Error: '+d.msg);return;}
    document.getElementById('staff-modal').style.display='none';
    loadStaff();
  }catch(e){alert('Error: '+e.message);}
}
async function staffToggle(id,current){
  const d=await(await fetch('staff_api.php?action=update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,is_active:current?0:1})})).json();
  if(d.ok)loadStaff(); else alert(d.msg);
}
async function staffDelete(id,name){
  if(!confirm(name+' ကို ဖျက်မည် — သေချာပါသလား?'))return;
  const d=await(await fetch('staff_api.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();
  if(d.ok)loadStaff(); else alert(d.msg);
}
/* ══ END STAFF ══ */
</script>

<?php if (!empty($_SESSION['demo_mode'])): ?>
<script>
// READ ONLY MODE - disable all save/delete/add buttons
document.addEventListener('DOMContentLoaded', function() {
  // Intercept all fetch POST requests
  var origFetch = window.fetch;
  window.fetch = function(url, opts) {
    if (opts && opts.method && opts.method.toUpperCase() === 'POST') {
      var u = String(url);
      // Allow login/logout only
      if (!u.includes('api=login') && !u.includes('api=logout')) {
        showToast('🔒 Read-only demo — Data မပြောင်းနိုင်ပါ', true);
        return Promise.resolve(new Response(JSON.stringify({ok:false,msg:'Demo mode - read only'}), {status:200, headers:{'Content-Type':'application/json'}}));
      }
    }
    return origFetch.apply(this, arguments);
  };
  // Visual: grey out all save/action buttons
  setTimeout(function() {
    document.querySelectorAll('.btn-primary, .btn-danger, [onclick*="save"], [onclick*="delete"], [onclick*="Delete"]').forEach(function(el) {
      el.style.opacity = '0.4';
      el.style.cursor = 'not-allowed';
      el.title = '🔒 Read-only demo';
    });
  }, 2000);
});
</script>
<?php endif; ?>
</body>
</html>
