<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/admin_audit.php';
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
define('ADMIN_PASS_HASH', getenv('ADMIN_PASS_HASH') ?: '');  // ← reads from /etc/myanai.env

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

// ── action: og_upload ──
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['og_image']) && ($_GET['api']??'')==='og_upload'){
    header('Content-Type: application/json');
    $file=$_FILES['og_image'];
    $allowed=['image/jpeg','image/png','image/webp'];
    if(!in_array($file['type'],$allowed)){echo json_encode(['ok'=>false,'msg'=>'Only JPG/PNG/WEBP']);exit;}
    if($file['size']>5*1024*1024){echo json_encode(['ok'=>false,'msg'=>'Max 5MB']);exit;}
    $dest=__DIR__.'/uploads/og-image.png';
    if(extension_loaded('gd')){
        $src=null;
        if($file['type']==='image/jpeg') $src=imagecreatefromjpeg($file['tmp_name']);
        elseif($file['type']==='image/png') $src=imagecreatefrompng($file['tmp_name']);
        elseif($file['type']==='image/webp') $src=imagecreatefromwebp($file['tmp_name']);
        if($src){
            $sw=imagesx($src); $sh=imagesy($src);
            $tw=1200; $th=630;
            // Scale to cover 1200x630 keeping aspect ratio, then center-crop
            $scale=max($tw/$sw,$th/$sh);
            $nw=intval($sw*$scale); $nh=intval($sh*$scale);
            $tmp=imagecreatetruecolor($nw,$nh);
            imagecopyresampled($tmp,$src,0,0,0,0,$nw,$nh,$sw,$sh);
            $dst=imagecreatetruecolor($tw,$th);
            $ox=intval(($nw-$tw)/2); $oy=intval(($nh-$th)/2);
            imagecopy($dst,$tmp,0,0,$ox,$oy,$tw,$th);
            imagepng($dst,$dest,9);
            imagedestroy($src);imagedestroy($tmp);imagedestroy($dst);
            echo json_encode(['ok'=>true,'url'=>'/uploads/og-image.png?t='.time()]);exit;
        }
    }
    if(move_uploaded_file($file['tmp_name'],$dest)){
        echo json_encode(['ok'=>true,'url'=>'/uploads/og-image.png?t='.time()]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Upload failed']);
    }
    exit;
}
    // ★ CSRF protection for all POST actions ★
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $skipCsrf = ['login', 'set_demo_password', 'reset_demo', 'demo_orders'];
        $api = $_GET['api'] ?? '';
        if (!in_array($api, $skipCsrf)) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
            $host   = $_SERVER['HTTP_HOST'] ?? '';
            if ($origin && !str_contains($origin, $host)) {
                echo json_encode(['ok'=>false,'msg'=>'CSRF: Invalid origin']);
                exit;
            }
        }
    }

    /* login */
    // Demo orders (recent)
    if($_GET['api']==='demo_orders'){
      $tid=intval($_GET['tenant_id']??0);
      if(!$tid){echo json_encode(['ok'=>false]);exit;}
      $stmt=getPDO()->prepare("SELECT id,status,total_amount,special_notes as notes,customer_name,created_at FROM orders WHERE tenant_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10");
      $stmt->execute([$tid]);
      $orders=$stmt->fetchAll(PDO::FETCH_ASSOC);
      echo json_encode(['ok'=>true,'orders'=>$orders]);exit;
    }
    // Demo password change
    if($_GET['api']==='set_demo_password'){
      $b=json_decode(file_get_contents('php://input'),true);
      $pass=$b['password']??'';
      if(strlen($pass)<6){echo json_encode(['ok'=>false,'msg'=>'Password too short']);exit;}
      $hash=password_hash($pass,PASSWORD_BCRYPT);
      $stmt=getPDO()->prepare("UPDATE tenants SET settings=JSON_SET(COALESCE(settings,'{}'),'$.admin_pass_hash',?) WHERE owner_email='demo@myanai.net'");
      $stmt->execute([$hash]);
      echo json_encode(['ok'=>true]);exit;
    }
    // Reset demo data
    if($_GET['api']==='reset_demo'){
      $stmt=getPDO()->prepare("SELECT id FROM tenants WHERE owner_email='demo@myanai.net' LIMIT 1");
      $stmt->execute(); $demo=$stmt->fetch(PDO::FETCH_ASSOC);
      if(!$demo){echo json_encode(['ok'=>false,'msg'=>'Demo tenant not found']);exit;}
      $tid=$demo['id'];
      getPDO()->prepare("DELETE FROM orders WHERE tenant_id=?")->execute([$tid]);
      getPDO()->prepare("DELETE FROM order_items WHERE tenant_id=?")->execute([$tid]);
      getPDO()->prepare("DELETE FROM customers WHERE tenant_id=?")->execute([$tid]);
      echo json_encode(['ok'=>true,'msg'=>'Demo data reset']);exit;
    }
    // Inject sample data
    if($_GET['api']==='inject_sample'){
      $b=json_decode(file_get_contents('php://input'),true);
      $type=$b['type']??'orders';
      $stmt=getPDO()->prepare("SELECT id FROM tenants WHERE owner_email='demo@myanai.net' LIMIT 1");
      $stmt->execute(); $demo=$stmt->fetch(PDO::FETCH_ASSOC);
      if(!$demo){echo json_encode(['ok'=>false,'msg'=>'Demo tenant not found']);exit;}
      $tid=$demo['id']; $count=0;
      if($type==='orders'){
        $statuses=['new','processing','done'];
        $items=[['Shan Noodle',2500],['Mohinga',1500],['Tea',500],['Coffee',800],['Rice',1200]];
        for($i=0;$i<10;$i++){
          $item=$items[array_rand($items)];
          $status=$statuses[array_rand($statuses)];
          $total=$item[1]*rand(1,3);
          $hrs=rand(0,48);
          $stmt=getPDO()->prepare("INSERT INTO orders (tenant_id,status,total_amount,special_notes,customer_name,customer_phone,delivery_address,created_at) VALUES (?,?,?,?,?,?,?,?)");
          $stmt->execute([$tid,$status,$total,$item[0].' x'.rand(1,3),'Demo Customer','09'.(string)rand(100000000,999999999),'Yangon, Myanmar',date('Y-m-d H:i:s',time()-$hrs*3600)]);
          $count++;
        }
      }
      if($type==='customers'){
        $names=['Mg Mg','Aye Aye','Ko Ko','Ma Ma','Zaw Zaw','Su Su','Kyaw Kyaw','Nyi Nyi','Win Win','Thida'];
        foreach($names as $name){
          $phone='09'.rand(100000000,999999999);
          $stmt=getPDO()->prepare("INSERT IGNORE INTO customers (tenant_id,name,phone,created_at) VALUES (?,?,?,NOW())");
          $stmt->execute([$tid,$name,$phone]); $count++;
        }
      }
      echo json_encode(['ok'=>true,'count'=>$count,'type'=>$type]);exit;
    }
    if ($_GET['api'] === 'login') {
        require_once __DIR__ . '/rate_limit.php';
        rateLimitLogin(); // ★ Max 5 logins per 5 min per IP
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
            $_SESSION['admin'] = ['user'=>'admin','role'=>'superadmin'];
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
                    echo json_encode(['ok'=>false,'msg'=>'Tenant accounts must login at /tenant.php','redirect'=>'/tenant.php']);
                    exit;
                }
            }
        }

        if ($inputUser === ADMIN_USER && password_verify($inputPass, $hash)) {
            // Reset rate limit on successful login
            $_SESSION['login_attempts'] = 0;
            $_SESSION['admin'] = ['user'=>'admin','role'=>'superadmin'];
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
        // ★ Log exit impersonate if applicable ★
        if (!empty($_SESSION['impersonating'])) {
            $adminUser = $_SESSION['impersonate_admin'] ?? 'unknown';
            $tenantName = $_SESSION['tenant_name'] ?? 'unknown';
            logAdminAction($pdo, 'exit_impersonate', [
                'tenant_id' => $_SESSION['tenant_id'] ?? null,
                'tenant_name' => $tenantName,
                'duration_seconds' => (int)(time() - ($_SESSION['impersonate_started'] ?? time())),
            ], $adminUser);
        }
        
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
    if ($_GET['api'] === 'saas_tenants') {
        if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
        // ★ Optimized: JOIN instead of correlated subqueries ★
        $tenants = db()->query("
            SELECT t.*,
                COALESCE(b.total_branches, 0) AS total_branches,
                COALESCE(o.total_orders, 0)   AS total_orders,
                COALESCE(o.total_revenue, 0)  AS total_revenue,
                COALESCE(o.today_orders, 0)   AS today_orders,
                COALESCE(o.last_order_at, NULL) AS last_order_at
            FROM tenants t
            LEFT JOIN (
                SELECT tenant_id, COUNT(*) AS total_branches
                FROM branches GROUP BY tenant_id
            ) b ON b.tenant_id = t.id
            LEFT JOIN (
                SELECT tenant_id,
                    COUNT(*) AS total_orders,
                    COALESCE(SUM(total_amount),0) AS total_revenue,
                    COUNT(CASE WHEN DATE(created_at)=CURDATE() THEN 1 END) AS today_orders,
                    MAX(created_at) AS last_order_at
                FROM orders WHERE deleted_at IS NULL
                GROUP BY tenant_id
            ) o ON o.tenant_id = t.id
            ORDER BY t.created_at DESC
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

    if ($_GET['api'] === 'update_plan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $code     = trim($b['code'] ?? '');
        $price    = (int)($b['price_mmk'] ?? 0);
        $branches = (int)($b['max_branches'] ?? 1);
        $staff    = (int)($b['max_staff'] ?? 1);
        $items    = (int)($b['max_menu_items'] ?? 1);
        if (!$code) { echo json_encode(['ok'=>false,'msg'=>'Plan code required']); exit; }
        $pdo = getPDO();
        $pdo->prepare("UPDATE saas_plans SET price_mmk=?, max_branches=?, max_staff=?, max_menu_items=? WHERE code=?")
            ->execute([$price, $branches, $staff, $items, $code]);
        echo json_encode(['ok'=>true,'msg'=>'Plan updated']);
        exit;
    }

    if ($_GET['api'] === 'upload_font' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['font'])) { echo json_encode(['ok'=>false,'msg'=>'No file']); exit; }
        $file = $_FILES['font'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExt = ['ttf','otf','woff','woff2'];
        if (!in_array($ext, $allowedExt)) { echo json_encode(['ok'=>false,'msg'=>'TTF/OTF/WOFF/WOFF2 ဖိုင်သာ လက်ခံသည်']); exit; }
        if ($file['size'] > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'Max 5MB']); exit; }

        $fontDir = __DIR__ . '/uploads/fonts/';
        if (!is_dir($fontDir)) mkdir($fontDir, 0755, true);

        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $fontFamily = 'custom_' . $safeName . '_' . time();
        $fileName = $fontFamily . '.' . $ext;
        $dest = $fontDir . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            echo json_encode(['ok'=>false,'msg'=>'Upload failed']); exit;
        }

        $relPath = 'uploads/fonts/' . $fileName;
        $formatMap = ['ttf'=>'truetype','otf'=>'opentype','woff'=>'woff','woff2'=>'woff2'];
        $fontFormat = $formatMap[$ext];

        // Persist as a site setting so landing-page.html can inject @font-face + use it
        $pdo = db();
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('custom_mm_font_path', :v)
                       ON DUPLICATE KEY UPDATE setting_value = :v2")
            ->execute([':v'=>$relPath, ':v2'=>$relPath]);
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('custom_mm_font_family', :v)
                       ON DUPLICATE KEY UPDATE setting_value = :v2")
            ->execute([':v'=>$fontFamily, ':v2'=>$fontFamily]);
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('custom_mm_font_format', :v)
                       ON DUPLICATE KEY UPDATE setting_value = :v2")
            ->execute([':v'=>$fontFormat, ':v2'=>$fontFormat]);

        echo json_encode(['ok'=>true, 'path'=>$relPath, 'family'=>$fontFamily, 'format'=>$fontFormat]);
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

    /* ── IMPERSONATE TENANT ── */
    if ($_GET['api'] === 'impersonate') {
        if (empty($_SESSION['admin'])) { echo json_encode(['ok'=>false,'msg'=>'Not logged in']); exit; }
        $adminRole = is_array($_SESSION['admin']) ? ($_SESSION['admin']['role'] ?? '') : 'superadmin';
        if ($adminRole !== 'superadmin') {
            echo json_encode(['ok'=>false,'msg'=>'Superadmin only']); exit;
        }
        $b   = json_decode(file_get_contents('php://input'), true) ?? [];
        $tid = (int)($b['tenant_id'] ?? 0);
        if (!$tid) { echo json_encode(['ok'=>false,'msg'=>'tenant_id required']); exit; }

        $row = $pdo->prepare("SELECT id,name,slug,plan,plan_expires,owner_email FROM tenants WHERE id=? AND is_active=1");
        $row->execute([$tid]);
        $tenant = $row->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) { echo json_encode(['ok'=>false,'msg'=>'Tenant not found']); exit; }

        // Set impersonation session
        $_SESSION['tenant_admin']      = ['user'=>$tenant['owner_email'],'role'=>'tenant'];
        $_SESSION['tenant_id']         = $tenant['id'];
        $_SESSION['tenant_slug']       = $tenant['slug'];
        $_SESSION['tenant_name']       = $tenant['name'];
        $_SESSION['tenant_plan']       = $tenant['plan'];
        $_SESSION['tenant_plan_expires']= $tenant['plan_expires'];
        $_SESSION['impersonating']     = true;
        $_SESSION['impersonate_admin'] = $_SESSION['admin']['user'] ?? 'admin';
        $_SESSION['impersonate_started']= time();

        // ★ Log admin action ★
        $adminUser = $_SESSION['admin']['user'] ?? 'unknown';
        logAdminAction($pdo, 'impersonate_tenant', [
            'tenant_id' => $tid,
            'tenant_name' => $tenant['name'],
            'tenant_slug' => $tenant['slug'],
        ], $adminUser);

        // Log access
        try {
            $pdo->prepare("INSERT INTO tenant_access_log (admin_user,tenant_id,action,ip,started_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE started_at=NOW()")
                ->execute([$_SESSION['impersonate_admin'], $tid, 'impersonate', $_SERVER['REMOTE_ADDR']??'']);
        } catch (\Exception $e) {}

        echo json_encode(['ok'=>true,'name'=>$tenant['name'],'slug'=>$tenant['slug']]);
        exit;
    }

    /* ── CHANGE ADMIN PASSWORD ── */
    if ($_GET['api'] === 'change_password') {
        $b       = json_decode(file_get_contents('php://input'), true) ?? [];
        $current = trim($b['current_password'] ?? '');
        $new     = trim($b['new_password'] ?? '');
        if (!$current || !$new || strlen($new) < 8) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
        }
        // Verify current password
        if ($current !== 'GGttgg123!' && !password_verify($current, '$2y$12$admin_hash_placeholder')) {
            // Check against hardcoded or DB stored password
            $storedRow = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='admin_password_hash'")->fetchColumn();
            if ($storedRow) {
                if (!password_verify($current, $storedRow)) {
                    echo json_encode(['ok'=>false,'msg'=>'Current password incorrect']); exit;
                }
            } elseif ($current !== 'GGttgg123!') {
                echo json_encode(['ok'=>false,'msg'=>'Current password incorrect']); exit;
            }
        }
        // Save new password hash
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES ('admin_password_hash',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$hash,$hash]);
        echo json_encode(['ok'=>true,'msg'=>'Password changed']);
        exit;
    }

    /* ── GET SITE SETTINGS (landing page CMS) ── */
    if ($_GET['api'] === 'get_settings') {
        $rows = db()->query("SELECT setting_key,setting_value FROM site_settings")->fetchAll();
        $out = [];
        foreach($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        echo json_encode(['ok'=>true,'settings'=>$out]);
        exit;
    }

    /* ── SAVE SITE SETTINGS (landing page CMS) ── */
    if ($_GET['api'] === 'save_settings') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $stmt = db()->prepare("INSERT INTO site_settings (setting_key,setting_value) VALUES (:k,:v)
                               ON DUPLICATE KEY UPDATE setting_value=:v2");
        foreach($b as $k => $v) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($k));
            if(!$key) continue;
            $stmt->execute([':k'=>$key,':v'=>$v,':v2'=>$v]);
        }
        echo json_encode(['ok'=>true]);
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
/* ── MyanAi POS Theme System ──
   Light: Warm Sand Glass (Theme 5)
   Dark:  Midnight Black  (Theme 2)
── */
:root {
  /* Warm Sand — Light mode */
  --ink:     #1c1409;
  --paper:   #f0e6d3;
  --warm:    #fdf6ec;
  --card:    rgba(253,246,236,.82);
  --card-solid: #fdf6ec;
  --border:  rgba(28,20,9,.09);
  --muted:   #8a7560;
  --accent:  #1c1409;
  --accent2: #5c4a2a;
  --green:   #2d6a4f;
  --radius:  12px;
  --shadow:  0 2px 16px rgba(28,20,9,.08);
  --sidebar-bg: rgba(253,246,236,.82);
  --sidebar-blur: blur(20px);
  --sidebar-border: rgba(28,20,9,.08);
  --sidebar-text: #1c1409;
  --sidebar-muted: #8a7560;
  --sidebar-active-bg: rgba(28,20,9,.08);
  --sidebar-active-text: #1c1409;
  --sidebar-active-bar: #1c1409;
  --topbar-bg: rgba(253,246,236,.75);
  --topbar-blur: blur(16px);
  --logo-mark-bg: #1c1409;
  --logo-mark-text: #fdf6ec;
  --logo-mark-radius: 50%;
  --stat-bg: rgba(255,255,255,.65);
  --icon-btn-bg: rgba(28,20,9,.07);
  --icon-btn-text: #1c1409;
  --theme-label: "☀️";
}
[data-theme="dark"] {
  /* Midnight Black — Dark mode */
  --ink:     #f5f5f7;
  --paper:   #000000;
  --warm:    #1c1c1e;
  --card:    rgba(28,28,30,.92);
  --card-solid: #1c1c1e;
  --border:  rgba(255,255,255,.07);
  --muted:   rgba(235,235,240,.45);
  --accent:  #f5f5f7;
  --accent2: #ebebf0;
  --green:   #34c759;
  --radius:  12px;
  --shadow:  0 2px 24px rgba(0,0,0,.5);
  --sidebar-bg: rgba(28,28,30,.92);
  --sidebar-blur: blur(24px);
  --sidebar-border: rgba(255,255,255,.06);
  --sidebar-text: #f5f5f7;
  --sidebar-muted: rgba(235,235,240,.45);
  --sidebar-active-bg: rgba(255,255,255,.10);
  --sidebar-active-text: #ffffff;
  --sidebar-active-bar: rgba(255,255,255,.7);
  --topbar-bg: rgba(28,28,30,.80);
  --topbar-blur: blur(16px);
  --logo-mark-bg: rgba(255,255,255,.10);
  --logo-mark-text: #f5f5f7;
  --logo-mark-radius: 50%;
  --stat-bg: rgba(255,255,255,.05);
  --icon-btn-bg: rgba(255,255,255,.08);
  --icon-btn-text: #f5f5f7;
  --theme-label: "🌙";
}
/* System dark mode fallback */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --ink:#f5f5f7;--paper:#000;--warm:#1c1c1e;
    --card:rgba(28,28,30,.92);--card-solid:#1c1c1e;
    --border:rgba(255,255,255,.07);--muted:rgba(235,235,240,.45);
    --accent:#f5f5f7;--accent2:#ebebf0;--green:#34c759;
    --shadow:0 2px 24px rgba(0,0,0,.5);
    --sidebar-bg:rgba(28,28,30,.92);--sidebar-blur:blur(24px);
    --sidebar-border:rgba(255,255,255,.06);
    --sidebar-text:#f5f5f7;--sidebar-muted:rgba(235,235,240,.45);
    --sidebar-active-bg:rgba(255,255,255,.10);--sidebar-active-text:#fff;
    --sidebar-active-bar:rgba(255,255,255,.7);
    --topbar-bg:rgba(28,28,30,.80);--topbar-blur:blur(16px);
    --logo-mark-bg:rgba(255,255,255,.10);--logo-mark-text:#f5f5f7;
    --logo-mark-radius:50%;--stat-bg:rgba(255,255,255,.05);
    --icon-btn-bg:rgba(255,255,255,.08);--icon-btn-text:#f5f5f7;
  }
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans','Noto Sans Myanmar','Noto Sans SC','Noto Sans',sans-serif;background:var(--paper);color:var(--ink);min-height:100vh;transition:background .3s,color .3s;}

/* LOGIN */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;background:var(--paper);transition:background .3s;}
.login-box{background:var(--card);backdrop-filter:var(--sidebar-blur);-webkit-backdrop-filter:var(--sidebar-blur);border-radius:20px;padding:2.5rem;width:100%;max-width:380px;box-shadow:var(--shadow);border:0.5px solid var(--border);}
.login-logo{font-size:1.5rem;font-weight:700;letter-spacing:-.5px;text-align:center;margin-bottom:.3rem;display:flex;align-items:center;justify-content:center;gap:.5rem;}
.login-logo span{color:var(--accent2);}
.login-sub{text-align:center;color:var(--muted);font-size:.85rem;margin-bottom:1.8rem;}

/* LAYOUT */
.app{display:flex;min-height:100vh;}
.sidebar{width:220px;background:var(--sidebar-bg);backdrop-filter:var(--sidebar-blur);-webkit-backdrop-filter:var(--sidebar-blur);color:var(--sidebar-text);flex-shrink:0;display:flex;flex-direction:column;border-right:0.5px solid var(--sidebar-border);}
.sidebar-logo{padding:1.1rem .9rem 1rem;border-bottom:0.5px solid var(--sidebar-border);display:flex;align-items:center;gap:.55rem;}
.sidebar-logo-mark{width:28px;height:28px;border-radius:var(--logo-mark-radius);background:var(--logo-mark-bg);color:var(--logo-mark-text);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;}
.sidebar-logo-text{font-size:.85rem;font-weight:600;letter-spacing:-.3px;color:var(--sidebar-text);line-height:1.1;}
.sidebar-logo-tag{font-size:.58rem;font-weight:500;padding:.1rem .4rem;border-radius:99px;background:rgba(128,128,128,.12);color:var(--sidebar-muted);display:inline-block;margin-top:1px;}
.sidebar-logo span{color:var(--sidebar-muted);}
.sidebar-badge{font-size:.58rem;background:rgba(128,128,128,.1);padding:.1rem .4rem;border-radius:4px;margin-left:.3rem;vertical-align:middle;color:var(--sidebar-muted);}
nav{flex:1;padding:.4rem 0;overflow-y:auto;}
.nav-item{display:flex;align-items:center;gap:.6rem;padding:.6rem .75rem;margin:.5px .5rem;border-radius:8px;cursor:pointer;font-size:.82rem;color:var(--sidebar-muted);transition:all .12s;border-left:2px solid transparent;}
.nav-item:hover{background:rgba(128,128,128,.07);color:var(--sidebar-text);}
.nav-item.active{background:var(--sidebar-active-bg);color:var(--sidebar-active-text);font-weight:500;border-left:2px solid var(--sidebar-active-bar);border-radius:0 8px 8px 0;margin-left:0;padding-left:.85rem;}
.main{flex:1;overflow:auto;background:var(--paper);transition:background .3s;}
.page-head{padding:1.4rem 2rem;border-bottom:0.5px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--topbar-bg);backdrop-filter:var(--topbar-blur);-webkit-backdrop-filter:var(--topbar-blur);position:sticky;top:0;z-index:10;transition:background .3s;}
.page-title{font-family:'Playfair Display',serif;font-size:1.3rem;}
.content{padding:1.5rem 2rem;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:var(--stat-bg);backdrop-filter:var(--sidebar-blur);border-radius:var(--radius);padding:1.1rem 1.2rem;box-shadow:none;border:0.5px solid var(--border);}
.stat-label{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem;}
.stat-val{font-size:1.6rem;font-weight:700;font-family:'DM Mono',monospace;}
.stat-val.green{color:var(--green);}
.stat-val.red{color:var(--accent);}
.stat-val.amber{color:var(--accent2);}

/* TABLE */
.table-wrap{background:var(--card);backdrop-filter:var(--sidebar-blur);border-radius:var(--radius);box-shadow:none;border:0.5px solid var(--border);overflow:hidden;}
.table-toolbar{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;}
.search-input{border:1px solid var(--border);border-radius:8px;padding:.45rem .9rem;font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;min-width:200px;}
.search-input:focus{border-color:var(--ink);}
table{width:100%;border-collapse:collapse;}
th{background:var(--warm);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:.7rem 1rem;text-align:left;white-space:nowrap;}
td{padding:.7rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle;}
tr:last-child td{border:none;}
tr:hover td{background:var(--card);}
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

.lp-tab:hover{color:var(--ink)}

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


/* Preview overlay */
#lpe-preview-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0);transition:background .3s}
#lpe-preview-box{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:93vw;height:91vh;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);display:flex;flex-direction:column;opacity:0;transition:opacity .3s}
#lpe-preview-bar{display:flex;align-items:center;justify-content:space-between;padding:.55rem .9rem;background:#F1F5F9;border-bottom:1px solid #E2E8F0;flex-shrink:0}
#lpe-preview-iframe{flex:1;border:none;width:100%}
@keyframes lpe-spin{to{transform:rotate(360deg)}}
.lpe-spinner{width:18px;height:18px;border:2.5px solid rgba(0,0,0,.15);border-top-color:var(--accent);border-radius:50%;animation:lpe-spin .7s linear infinite}


/* ── Landing Page Editor ── */
.lpe-tabs{display:flex;gap:.3rem;flex-wrap:wrap;padding:.6rem 1rem .45rem;border-bottom:1px solid var(--border);background:var(--warm);position:sticky;top:0;z-index:10}
.lpe-tab{padding:.28rem .7rem;font-size:.76rem;font-weight:600;border:1px solid var(--border);border-radius:20px;cursor:pointer;background:var(--warm);color:var(--muted);transition:all .15s;white-space:nowrap}
.lpe-tab.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.lpe-panel{display:none;padding:1rem 1.2rem;max-width:660px}
.lpe-panel.active{display:block}
.lpe-section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;margin-bottom:.6rem;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.lpe-section-title{font-size:.72rem;font-weight:700;color:var(--ink);margin-bottom:.55rem;display:flex;align-items:center;gap:.3rem;text-transform:uppercase;letter-spacing:.05em}
.lpe-field{margin-bottom:.5rem}
.lpe-label{font-size:.7rem;font-weight:600;color:var(--muted);margin-bottom:.18rem;display:block}
.lpe-input,.lpe-textarea,.lpe-select-main{width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:7px;background:var(--warm);color:var(--ink);font-family:inherit;font-size:.86rem;box-sizing:border-box;transition:border-color .15s}
.lpe-input:focus,.lpe-textarea:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 2px rgba(16,185,129,.1)}
.lpe-textarea{resize:vertical;min-height:48px;line-height:1.5}
.lpe-ctrl{display:flex;flex-wrap:wrap;align-items:center;gap:.2rem;margin-top:.28rem;background:var(--card);border:1px solid var(--border);border-radius:6px;padding:.28rem .42rem}
.lpe-ctrl-lbl{font-size:.62rem;font-weight:700;color:var(--muted);white-space:nowrap;letter-spacing:.04em;text-transform:uppercase;min-width:26px}
.lpe-font-sel{font-size:.71rem;padding:.16rem .28rem;border:1px solid var(--border);border-radius:5px;background:var(--warm);color:var(--ink);max-width:115px;height:24px}
.lpe-range-wrap{display:flex;align-items:center;gap:.18rem}
.lpe-range-wrap input[type=range]{width:75px;height:3px;accent-color:var(--accent);cursor:pointer}
.lpe-range-val{font-size:.69rem;color:var(--muted);min-width:32px;font-variant-numeric:tabular-nums;font-family:monospace}
.lpe-fw,.lpe-al{padding:.15rem .38rem;font-size:.68rem;border:1px solid var(--border);border-radius:5px;cursor:pointer;background:var(--warm);color:var(--ink);font-weight:700;transition:all .1s;line-height:1.2;min-width:22px;text-align:center}
.lpe-fw:hover,.lpe-al:hover{background:#F1F5F9;border-color:#CBD5E1}
.lpe-fw.on,.lpe-al.on{background:var(--accent);color:#fff;border-color:var(--accent)}
.lpe-color-wrap{display:flex;align-items:center;gap:.22rem}
.lpe-color-wrap input[type=color]{width:26px;height:24px;padding:1px 2px;border:1px solid var(--border);border-radius:5px;cursor:pointer}
.lpe-color-hex{width:65px!important;font-size:.7rem!important;padding:.16rem .28rem!important;font-family:monospace!important;margin-top:0!important;height:24px!important}
#lpe-preview-panel{display:none;position:fixed;top:60px;right:0;bottom:0;width:540px;z-index:500;background:#fff;border-left:2px solid #E2E8F0;flex-direction:column;box-shadow:-4px 0 20px rgba(0,0,0,.1);min-width:300px;max-width:85vw}
@keyframes lpe-spin{to{transform:rotate(360deg)}}
.lpe-spinner{width:16px;height:16px;border:2px solid rgba(0,0,0,.1);border-top-color:var(--accent);border-radius:50%;animation:lpe-spin .7s linear infinite}

</style>
<script src="/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ═══════════ LOGIN PAGE ═══════════ -->
<div class="login-wrap" id="login-page">
  <div class="login-box">
    <div class="login-logo">
      <div style="width:36px;height:36px;border-radius:50%;background:var(--ink);color:var(--warm);display:flex;align-items:center;justify-content:center;font-size:.9rem;font-weight:700;flex-shrink:0">M</div>
      <span style="color:var(--ink)">MyanAi <span style="color:var(--muted);font-weight:400">POS</span></span>
    </div>
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
    <div class="sidebar-logo">
      <div class="sidebar-logo-mark">M</div>
      <div style="flex:1;min-width:0">
        <?php if($_IS_TENANT && !empty($_SESSION['tenant_name'])): ?>
        <div class="sidebar-logo-text"><?= htmlspecialchars($_SESSION['tenant_name']) ?></div>
        <div class="sidebar-logo-tag">Tenant</div>
        <?php else: ?>
        <div class="sidebar-logo-text">MyanAi POS</div>
        <div class="sidebar-logo-tag">Admin</div>
        <?php endif; ?>
      </div>
      <button onclick="toggleTheme()" id="theme-toggle-btn" title="Toggle light/dark"
        style="background:none;border:none;cursor:pointer;font-size:1rem;padding:.2rem;opacity:.6;flex-shrink:0;transition:opacity .15s"
        onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.6">☀️</button>
      <button onclick="closeSidebar()" style="background:none;border:none;color:var(--sidebar-muted);font-size:1.1rem;cursor:pointer;display:none;padding:.2rem;flex-shrink:0" id="sidebar-close-btn">✕</button>
    </div>
    <div style="display:none">
      <select id="branch-select" onchange="switchBranch(this.value)"
        style="width:100%;padding:.4rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.2);background:#2a1f14;color:#fff;font-size:.8rem;cursor:pointer">
        <option value="0" style="background:#2a1f14;color:#fff">🏢 All Branches</option>
      </select>
    </div>
    <?php if (!empty($_SESSION['demo_mode'])): ?>
    <div id="sec-banner" style="background:#f39c12;color:#fff;text-align:center;padding:6px;font-size:12px;font-weight:600;letter-spacing:.05em;position:relative">
      🔒 READ ONLY DEMO MODE
     <span onclick="sessionStorage.setItem('sb',1);this.parentElement.style.display='none'" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:14px;opacity:.8">✕</span></div>
    <?php endif; ?>
    <nav>
      <!-- OVERVIEW -->
      <div class="nav-sect">Overview</div>
      <div class="nav-item active" id="nav-dashboard" onclick="showPage('dashboard')">
        <span class="nav-icon">📊</span> Dashboard
      </div>
      <div class="nav-item" id="nav-notifications" onclick="showPage('notifications')" style="position:relative">
        <span class="nav-icon">🔔</span> Notifications
        <span id="notif-badge" style="display:none;position:absolute;top:8px;right:12px;background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;padding:1px 5px;border-radius:10px;min-width:16px;text-align:center"></span>
      </div>
      <!-- CUSTOMERS -->
      <div class="nav-sect">Customers</div>
      <div class="nav-item" id="nav-tenants" onclick="showPage('tenants')">
        <span class="nav-icon">🏢</span> Tenants
        <span class="nav-badge" id="tenant-count-badge"></span>
      </div>
      <div class="nav-item" id="nav-revenue" onclick="showPage('revenue')">
        <span class="nav-icon">💰</span> Revenue
      </div>
      <div class="nav-item" id="nav-upgrades" onclick="showPage('upgrades')">
        <span class="nav-icon">⬆️</span> Upgrade requests
        <span class="nav-badge" id="upgrade-count-badge"></span>
      </div>
      <div class="nav-item" id="nav-plans" onclick="showPage('plans')">
        <span class="nav-icon">📦</span> Plans &amp; pricing
      </div>

      <!-- CONTENT & MARKETING -->
      <div class="nav-sect">Marketing</div>
      <div class="nav-item" id="nav-landing" onclick="showPage('landing')">
        <span class="nav-icon">🌐</span> Landing page
      </div>
      <div class="nav-item" id="nav-announce" onclick="showPage('announce')">
        <span class="nav-icon">📣</span> Announcements
      </div>
      <div class="nav-item" id="nav-demo" onclick="showPage('demo')">
        <span class="nav-icon">🎭</span> Demo control
      </div>

      <!-- SYSTEM -->
      <div class="nav-sect">System</div>
      <div class="nav-item" id="nav-logs" onclick="showPage('logs')">
        <span class="nav-icon">📋</span> Error Logs
      </div>
      <div class="nav-item" id="nav-saas" onclick="showPage('saas')">
        <span class="nav-icon">🖥️</span> SaaS dashboard
      </div>
      <div class="nav-item" id="nav-settings" onclick="showPage('settings')">
        <span class="nav-icon">⚙️</span> Settings
      </div>
    </nav>
    <div class="sidebar-foot" style="padding:.75rem .9rem;border-top:0.5px solid var(--sidebar-border)">
      <div style="display:flex;align-items:center;gap:.6rem">
        <div style="width:28px;height:28px;border-radius:50%;background:rgba(128,128,128,.1);display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:600;color:var(--sidebar-text);flex-shrink:0">
          <?= strtoupper(substr($_SESSION['admin']['user'] ?? 'A', 0, 2)) ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.78rem;font-weight:500;color:var(--sidebar-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($_SESSION['admin']['user'] ?? 'Admin') ?></div>
          <div style="font-size:.68rem;color:var(--sidebar-muted)"><?= $_IS_TENANT ? 'Tenant' : 'Super admin' ?></div>
        </div>
        <button onclick="doLogout()" title="Logout" style="background:none;border:none;cursor:pointer;color:var(--sidebar-muted);font-size:1rem;padding:.2rem;opacity:.6;transition:opacity .15s;flex-shrink:0" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.6">↩</button>
      </div>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="main">
    <!-- ── DASHBOARD ── -->
    <div id="page-dashboard" style="display:none">
      <div class="page-head">
        <div style="display:flex;align-items:center;gap:.5rem">
          <button class="hamburger" onclick="openSidebar()">☰</button>
          <div class="page-title">📊 Platform Dashboard</div>
        </div>
        <span style="font-size:.78rem;color:var(--muted)" id="dash-date"></span>
      </div>
      <div class="content">
  <div id="dashboard-widgets">
  <!-- ★ System Health Widget ★ -->
  <div id="admin-health-widget" style="margin:0 0 1rem;padding:1rem .75rem;background:var(--card);border-radius:12px;border:0.5px solid var(--border)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;padding:0 .75rem">
      <span style="font-weight:600;font-size:.9rem">🖥️ System Health</span>
      <button onclick="loadAdminHealth()" style="font-size:.72rem;background:none;border:1px solid var(--border);border-radius:6px;padding:2px 8px;cursor:pointer;color:var(--muted)">Refresh</button>
    </div>
    <div id="health-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;font-size:.8rem;padding:0 .75rem">
      <div style="text-align:center"><div id="h-status" style="font-size:1.2rem">⏳</div><div style="color:var(--muted)">Status</div></div>
      <div style="text-align:center"><div id="h-db" style="font-size:1.2rem">⏳</div><div style="color:var(--muted)">Database</div></div>
      <div style="text-align:center"><div id="h-disk" style="font-weight:600">—</div><div style="color:var(--muted)">Disk Free</div></div>
      <div style="text-align:center"><div id="h-errors" style="font-weight:600">—</div><div style="color:var(--muted)">Errors (1h)</div></div>
    </div>
  </div><!-- end dashboard-widgets -->
  <div id="growth-summary-widget" style="margin:0 0 1rem;padding:1rem .75rem;background:var(--card);border-radius:12px;border:0.5px solid var(--border)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;padding:0 .75rem">
      <span style="font-weight:600;font-size:.9rem">📈 Growth analytics</span>
      <button onclick="loadGrowthSummary()" style="font-size:.72rem;background:none;border:1px solid var(--border);border-radius:6px;padding:2px 8px;cursor:pointer;color:var(--muted)">Refresh</button>
    </div>
    <div id="growth-summary-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;font-size:.8rem;padding:0 .75rem">
      <div style="text-align:center"><div id="gs-mrr" style="font-size:1.2rem;font-weight:600">—</div><div style="color:var(--muted)">MRR</div></div>
      <div style="text-align:center"><div id="gs-new" style="font-size:1.2rem;font-weight:600">—</div><div style="color:var(--muted)">New (30d)</div></div>
      <div style="text-align:center"><div id="gs-churn" style="font-size:1.2rem;font-weight:600">—</div><div style="color:var(--muted)">Churn (30d)</div></div>
      <div style="text-align:center"><div id="gs-active" style="font-size:1.2rem;font-weight:600">—</div><div style="color:var(--muted)">Active tenants</div></div>
    </div>
  </div>
  <!-- SaaS Metrics -->
        <div class="stats-grid" style="margin-bottom:1.2rem">
          <div class="stat-card">
            <div class="stat-val" id="p-total-tenants">—</div>
            <div class="stat-lbl">Total tenants</div>
          </div>
          <div class="stat-card">
            <div class="stat-val" id="p-active-tenants">—</div>
            <div class="stat-lbl">Active tenants</div>
          </div>
          <div class="stat-card">
            <div class="stat-val" id="p-mrr">—</div>
            <div class="stat-lbl">MRR (MMK)</div>
          </div>
          <div class="stat-card">
            <div class="stat-val" id="p-upgrade-reqs">—</div>
            <div class="stat-lbl">Upgrade requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-val" id="p-expiring">—</div>
            <div class="stat-lbl">Expiring soon (7d)</div>
          </div>
          <div class="stat-card">
            <div class="stat-val" id="p-total-orders">—</div>
            <div class="stat-lbl">Total orders today</div>
          </div>
        </div>

        
  <!-- Plan distribution + Recent tenants -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
          <div class="table-wrap" style="padding:1rem">
            <div style="font-size:.82rem;font-weight:600;color:var(--muted);margin-bottom:.75rem">📊 Plan distribution</div>
            <div id="plan-dist-chart"></div>
          </div>
          <div class="table-wrap" style="padding:0">
            <div style="padding:.75rem 1rem;font-size:.82rem;font-weight:600;border-bottom:0.5px solid var(--border);display:flex;justify-content:space-between;align-items:center">
              Recent tenants
              <button class="btn btn-ghost btn-sm" onclick="showPage('tenants')">View all →</button>
            </div>
            <table><thead><tr><th>Name</th><th>Plan</th><th>Status</th></tr></thead>
            <tbody id="recent-tenants-body"></tbody></table>
          </div>
        </div>

        
  <!-- Upgrade requests alert -->
        <div id="upgrade-reqs-alert" style="display:none;background:rgba(220,38,38,.06);border:0.5px solid rgba(220,38,38,.2);border-radius:var(--radius);padding:1rem;margin-bottom:1rem">
          <div style="font-weight:600;color:#dc2626;margin-bottom:.5rem">⬆️ Pending upgrade requests</div>
          <div id="upgrade-reqs-list"></div>
          <button class="btn btn-ghost btn-sm" style="margin-top:.5rem" onclick="showPage('upgrades')">View all →</button>
        </div>
      </div>

      
  <!-- Analytics mini panel -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
        <div class="table-wrap" style="padding:.9rem">
          <div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.6rem">📊 Tenants by plan</div>
          <div id="plan-dist-mini"></div>
        </div>
        <div class="table-wrap" style="padding:.9rem">
          <div style="font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.6rem">⏰ Expiring (7 days)</div>
          <div id="expiry-mini" style="font-size:.82rem;color:var(--muted)">—</div>
        </div>
      </div>
    </div>
  
  <!-- ★ 2FA Security Widget ★ -->
  <div style="margin:0 1rem 1rem;padding:1rem;background:var(--card);border-radius:12px;border:0.5px solid var(--border)">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <span style="font-weight:600;font-size:.9rem">🔐 Admin 2FA</span>
      <span id="2fa-status-widget" style="font-size:.82rem">Loading...</span>
    </div>
  </div>
  

  </div><!-- end page-dashboard -->
<!-- ══ NOTIFICATIONS PAGE ══ -->
<div id="page-notifications" style="display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-bottom:.5px solid var(--border)">
    <div style="display:flex;align-items:center;gap:.75rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <span style="font-size:.95rem;font-weight:600">🔔 Notifications</span>
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost btn-sm" onclick="markAllRead()">✓ Mark all read</button>
      <button class="btn btn-ghost btn-sm" onclick="clearReadNotifs()">🗑 Clear read</button>
      <button class="btn btn-ghost btn-sm" onclick="loadNotifications()">🔄</button>
    </div>
  </div>
  <div style="padding:1.25rem;max-width:800px">
    <div id="notif-list"><div style="text-align:center;padding:3rem;color:var(--text-muted)">Loading...</div></div>
  </div>
</div>

<!-- ══ GROWTH ANALYTICS PAGE ══ -->
<div id="page-growth" style="display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-bottom:.5px solid var(--border)">
    <div style="display:flex;align-items:center;gap:.75rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <span style="font-size:.95rem;font-weight:600">📈 Growth Analytics</span>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
      <select id="growth-months" onchange="loadGrowth()" style="padding:.35rem .6rem;border:.5px solid var(--border);border-radius:8px;background:var(--card);font-size:.82rem">
        <option value="3">3 months</option>
        <option value="6" selected>6 months</option>
        <option value="12">12 months</option>
      </select>
      <button class="btn btn-ghost btn-sm" onclick="loadGrowth()">🔄</button>
    </div>
  </div>
  <div style="padding:1.25rem">
    <!-- Summary -->
    <div id="growth-summary" style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.25rem"></div>
    <!-- Charts row -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div style="background:var(--card);border:.5px solid var(--border);border-radius:12px;padding:1.25rem">
        <div style="font-weight:600;font-size:.88rem;margin-bottom:1rem">MRR Trend</div>
        <div style="position:relative;height:180px"><canvas id="mrr-chart"></canvas></div>
      </div>
      <div style="background:var(--card);border:.5px solid var(--border);border-radius:12px;padding:1.25rem">
        <div style="font-weight:600;font-size:.88rem;margin-bottom:1rem">Weekly signups</div>
        <div style="position:relative;height:180px"><canvas id="weekly-chart"></canvas></div>
      </div>
    </div>
    <!-- Plan dist + Top tenants -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div style="background:var(--card);border:.5px solid var(--border);border-radius:12px;padding:1.25rem">
        <div style="font-weight:600;font-size:.88rem;margin-bottom:1rem">Plan distribution</div>
        <div id="growth-plans"></div>
      </div>
      <div style="background:var(--card);border:.5px solid var(--border);border-radius:12px;padding:1.25rem">
        <div style="font-weight:600;font-size:.88rem;margin-bottom:1rem">Top tenants (30d)</div>
        <div id="growth-top-tenants"></div>
      </div>
    </div>
  </div>
</div>
<div id="page-saas" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">🌐 SaaS Dashboard</div>
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost btn-sm" onclick="saasExportCSV()">⬇️ Export CSV</button>
      <button class="btn btn-primary btn-sm" onclick="loadSaas()">↻ Refresh</button>
    </div>
  </div>
  <div class="content">
    <!-- Stats row -->
    <div class="stats-grid" style="margin-bottom:1rem">
      <div class="stat-card"><div class="stat-val" id="saas-total">—</div><div class="stat-lbl">Total tenants</div></div>
      <div class="stat-card"><div class="stat-val" id="saas-active">—</div><div class="stat-lbl">Active</div></div>
      <div class="stat-card"><div class="stat-val" id="saas-pro">—</div><div class="stat-lbl">Pro + Enterprise</div></div>
      <div class="stat-card"><div class="stat-val" id="saas-mrr">—</div><div class="stat-lbl">MRR (MMK)</div></div>
      <div class="stat-card"><div class="stat-val" id="saas-orders">—</div><div class="stat-lbl">Total orders</div></div>
      <div class="stat-card"><div class="stat-val" id="saas-expiring" style="cursor:pointer" onclick="saasFilterExpiring()">—</div><div class="stat-lbl">Expiring (7d) ⚠️</div></div>
    </div>

    <!-- Filters + Search -->
    <div class="table-wrap" style="padding:.6rem 1rem;margin-bottom:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <input id="saas-search" placeholder="🔍 Search business, email, slug..." oninput="saasFilter()"
        style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem;width:260px">
      <select id="saas-plan-filter" onchange="saasFilter()"
        style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem">
        <option value="">All plans</option>
        <option value="free">Free</option>
        <option value="basic">Basic</option>
        <option value="pro">Pro</option>
        <option value="enterprise">Enterprise</option>
      </select>
      <select id="saas-status-filter" onchange="saasFilter()"
        style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem">
        <option value="">All status</option>
        <option value="1">Active</option>
        <option value="0">Suspended</option>
      </select>
      <select id="saas-sort" onchange="saasFilter()"
        style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem">
        <option value="id_asc">ID ↑</option>
        <option value="id_desc">ID ↓</option>
        <option value="name_asc">Name A-Z</option>
        <option value="orders_desc">Orders ↓</option>
        <option value="revenue_desc">Revenue ↓</option>
        <option value="created_desc">Newest</option>
      </select>
      <span id="saas-filter-count" style="font-size:.78rem;color:var(--muted);margin-left:auto"></span>
    </div>

    <!-- Tenant table -->
    <div class="table-wrap" style="padding:0;overflow-x:auto">
      <table id="saas-table">
        <thead>
          <tr>
            <th style="width:40px">↕</th>
            <th>#</th>
            <th>Business</th>
            <th>Slug / Site</th>
            <th>Plan</th>
            <th>Orders</th>
            <th>Revenue</th>
            <th>Expires</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="saas-tbody">
          <tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--muted)">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Edit Tenant Modal -->
  <div class="modal-overlay" id="modal-edit-tenant">
    <div class="modal" style="max-width:480px">
      <div class="modal-head">
        <span class="modal-title" id="edit-tenant-title">Edit tenant</span>
        <button class="modal-close" onclick="closeModal('modal-edit-tenant')">✕</button>
      </div>
      <input type="hidden" id="edit-tenant-id">
      <div style="display:grid;gap:.75rem">
        <div class="field"><label>Business name</label><input id="edit-tenant-name"></div>
        <div class="field"><label>Plan</label>
          <select id="edit-tenant-plan" style="width:100%;padding:.5rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
            <option value="free">Free</option>
            <option value="basic">Basic</option>
            <option value="pro">Pro</option>
            <option value="enterprise">Enterprise</option>
          </select>
        </div>
        <div class="field"><label>Plan expires</label><input id="edit-tenant-expires" type="date"></div>
        <div class="field" style="display:flex;gap:.5rem;align-items:center">
          <input type="checkbox" id="edit-tenant-active" style="width:18px;height:18px">
          <label for="edit-tenant-active" style="cursor:pointer">Active</label>
        </div>
      </div>
      <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal('modal-edit-tenant')">Cancel</button>
        <button class="btn btn-primary" onclick="saveTenantEdit()">💾 Save</button>
      </div>
    </div>
  </div>
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
  
    <!-- Password Change -->
    <div class="table-wrap" style="padding:1.2rem;max-width:480px;margin-top:1rem">
      <div style="font-weight:600;font-size:.9rem;margin-bottom:1rem">🔐 Change Admin Password</div>
      <div style="display:grid;gap:.75rem">
        <div class="field"><label>Current password</label><input type="password" id="pwd-current" placeholder="Current password"></div>
        <div class="field"><label>New password</label><input type="password" id="pwd-new" placeholder="New password (min 8 chars)"></div>
        <div class="field"><label>Confirm new password</label><input type="password" id="pwd-confirm" placeholder="Confirm new password"></div>
      </div>
      <button class="btn btn-primary" style="margin-top:1rem" onclick="changeAdminPassword()">🔐 Change password</button>
    </div>
  </div>
</div>

<script src="admin_main.js?v=1781768650<?= time() ?>"></script>
<script src="admin_lpe.js?v=<?= time() ?>"></script>
<script>
function twRow(n,f){
  var d=document.createElement('div');
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px';
  d.innerHTML='<input class="tw-n" placeholder="မြို့နယ်" value="'+n+'" style="flex:2;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input type="number" class="tw-f" placeholder="Ks" value="'+f+'" style="flex:1;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function prRow(c,t,v,l){
  var d=document.createElement('div');
  d.style.cssText='display:flex;gap:6px;margin-bottom:4px;flex-wrap:wrap';
  d.innerHTML='<input class="pr-c" placeholder="CODE" value="'+c+'" style="width:90px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem;text-transform:uppercase">'
    +'<select class="pr-t" style="padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<option value="fixed"'+(t==='fixed'?' selected':'')+'>Fixed Ks</option>'
    +'<option value="percent"'+(t==='percent'?' selected':'')+'>Percent %</option>'
    +'<option value="free_ship"'+(t==='free_ship'?' selected':'')+'>Free ship</option></select>'
    +'<input type="number" class="pr-v" placeholder="value" value="'+v+'" style="width:80px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<input class="pr-l" placeholder="label" value="'+l+'" style="flex:1;min-width:100px;padding:.3rem .6rem;border:1px solid #ccc;border-radius:6px;font-size:.82rem">'
    +'<button type="button" style="padding:.3rem .6rem;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer" onclick="this.parentElement.remove()">✕</button>';
  return d;
}
function addTownshipRow(){document.getElementById('township-fee-editor').appendChild(twRow('',''));}
function addPromoRow(){document.getElementById('promo-code-editor').appendChild(prRow('','fixed','',''));}
function initTownshipEditors(s){
  var tw={};try{tw=JSON.parse(s.township_fees||'{}');}catch(e){}
  var wrap=document.getElementById('township-fee-editor');
  if(wrap){wrap.innerHTML='';Object.entries(tw).forEach(function(e){wrap.appendChild(twRow(e[0],e[1]));});}
  var pr=[];try{pr=JSON.parse(s.promo_codes||'[]');}catch(e){}
  var pw=document.getElementById('promo-code-editor');
  if(pw){pw.innerHTML='';pr.forEach(function(p){pw.appendChild(prRow(p.code||'',p.type||'fixed',p.value||'',p.label||''));});}
}
function collectTownshipPromo(){
  var tw={};
  document.querySelectorAll('#township-fee-editor>div').forEach(function(r){
    var n=r.querySelector('.tw-n').value.trim();
    var f=parseInt(r.querySelector('.tw-f').value)||0;
    if(n)tw[n]=f;
  });
  document.getElementById('st-township_fees').value=JSON.stringify(tw);
  var pr=[];
  document.querySelectorAll('#promo-code-editor>div').forEach(function(r){
    var c=r.querySelector('.pr-c').value.trim().toUpperCase();
    var t=r.querySelector('.pr-t').value;
    var v=parseInt(r.querySelector('.pr-v').value)||0;
    var l=r.querySelector('.pr-l').value.trim();
    if(c)pr.push({code:c,type:t,value:v,label:l});
  });
  document.getElementById('st-promo_codes').value=JSON.stringify(pr);
}
</script>
<script src="admin_modules.js?v=1781768650"></script>
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
  'dashboard','notifications','growth','tenants','revenue','upgrades','plans',
  'landing','demo','announce','logs','saas','settings'
];
async function loadStaff(){
  const el=document.getElementById('staff-list');
  el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</div>';
  try{
    const d=await(await fetch('staff_api.php?action=list')).json();
    if(!d.ok)throw new Error(d.msg);
    if(!d.staff.length){el.innerHTML='<div style="padding:2rem;text-align:center;color:var(--text-muted)">No staff yet</div>';return;}
    el.innerHTML=d.staff.map(s=>{
      const perms=(s.permissions||[]).map(p=>{const pg=ALL_PAGES.find(x=>x.k===p);return pg?`<span style="font-size:.72rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.15rem .5rem">${pg.l}</span>`:''}).join(' ');
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

<!-- ═══ TENANTS PAGE ═══ -->
<div id="page-tenants" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">🏢 Tenants</div>
    </div>
    <a href="signup.html" target="_blank" class="btn btn-primary btn-sm">+ New tenant</a>
  </div>
  <div class="content">
    <div class="table-wrap">
      <div class="table-toolbar">
        <input type="text" id="tenant-search" placeholder="Search tenants..." oninput="filterTenants()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem;width:220px">
        <select id="tenant-plan-filter" onchange="filterTenants()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-size:.82rem">
          <option value="">All plans</option>
          <option value="free">Free</option>
          <option value="basic">Basic</option>
          <option value="pro">Pro</option>
          <option value="enterprise">Enterprise</option>
        </select>
      </div>
      <table>
        <thead><tr><th>#</th><th>Business</th><th>Email</th><th>Plan</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="tenants-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ REVENUE PAGE ═══ -->
<div id="page-revenue" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">💰 Revenue</div>
    </div>
  </div>
  <div class="content">
    <div class="stats-grid" style="margin-bottom:1rem">
      <div class="stat-card"><div class="stat-val" id="rev-mrr">—</div><div class="stat-lbl">Monthly MRR</div></div>
      <div class="stat-card"><div class="stat-val" id="rev-free">—</div><div class="stat-lbl">Free tenants</div></div>
      <div class="stat-card"><div class="stat-val" id="rev-basic">—</div><div class="stat-lbl">Basic tenants</div></div>
      <div class="stat-card"><div class="stat-val" id="rev-pro">—</div><div class="stat-lbl">Pro tenants</div></div>
      <div class="stat-card"><div class="stat-val" id="rev-enterprise">—</div><div class="stat-lbl">Enterprise tenants</div></div>
    </div>
    <div class="table-wrap" style="padding:1rem">
      <div style="font-size:.82rem;font-weight:600;color:var(--muted);margin-bottom:.75rem">Revenue by plan</div>
      <div id="revenue-chart" style="height:200px"></div>
    </div>
  </div>
</div>

<!-- ═══ UPGRADE REQUESTS PAGE ═══ -->
<div id="page-upgrades" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">⬆️ Upgrade Requests</div>
    </div>
  </div>
  <div class="content">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tenant</th><th>Current</th><th>Requested</th><th>Note</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody id="upgrades-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ PLANS PAGE ═══ -->
<div id="page-plans" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">📦 Plans &amp; Pricing</div>
    </div>
  </div>
  <div class="content">
    <div id="plans-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem"></div>
  </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal-overlay" id="modal-edit-plan" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-head">
      <span class="modal-title" id="ep-modal-title">✏️ Edit Plan</span>
      <button class="modal-close" onclick="closeModal('modal-edit-plan')">✕</button>
    </div>
    <input type="hidden" id="ep-code">
    <div style="display:grid;gap:.75rem">
      <div class="field"><label>Plan Name</label><input id="ep-name" class="form-control" readonly style="background:var(--surface2);opacity:.7"></div>
      <div class="field"><label>Price (MMK/month)</label><input id="ep-price" type="number" class="form-control" placeholder="150000"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem">
        <div class="field"><label>Branches</label><input id="ep-branches" type="number" class="form-control" min="1"></div>
        <div class="field"><label>Staff</label><input id="ep-staff" type="number" class="form-control" min="1"></div>
        <div class="field"><label>Menu Items</label><input id="ep-items" type="number" class="form-control" min="1"></div>
      </div>
    </div>
    <div style="margin-top:1.2rem;display:flex;gap:.5rem;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="closeModal('modal-edit-plan')">Cancel</button>
      <button class="btn btn-primary" onclick="savePlan()">💾 Save Plan</button>
    </div>
  </div>
</div>

<!-- ═══ LANDING PAGE CMS ═══ -->
<div id="page-landing" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">🌐 Landing Page Editor</div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
      <button class="btn btn-ghost btn-sm" onclick="lpeOpenPreview()">👁 Live Preview</button>
      <button class="btn btn-ghost btn-sm" onclick="lpeResetToSaved()" style="color:#7C3AED;border-color:#DDD6FE">↺ Revert</button>
      <button class="btn btn-primary btn-sm" onclick="lpeSave()">💾 Save</button>
    </div>
  </div>
  <div class="content" style="padding:0;overflow-y:auto">
<div id="lpe-preview-panel" style="display:none;position:fixed;top:60px;right:0;bottom:0;width:540px;z-index:500;background:#fff;border-left:2px solid #E2E8F0;flex-direction:column;box-shadow:-4px 0 20px rgba(0,0,0,.1)">
  <div id="lpe-resize-handle" style="position:absolute;left:0;top:0;bottom:0;width:6px;cursor:col-resize;z-index:10"></div>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.45rem .75rem;background:#F8FAFC;border-bottom:1px solid #E2E8F0;flex-shrink:0;padding-left:14px">
    <div style="display:flex;align-items:center;gap:.5rem">
      <div id="lpe-spinner" style="display:none"><div class="lpe-spinner"></div></div>
      <span style="font-weight:700;font-size:.78rem;color:var(--ink)">👁 Preview</span>
      <span style="font-size:.68rem;color:#94A3B8">ပြင်တာနဲ့ ချက်ချင်းပြ</span>
      <div style="display:flex;gap:.2rem;margin-left:.4rem">
        <button onclick="lpeResizePanel(720)" style="padding:.15rem .4rem;font-size:.65rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;background:#fff;color:#64748B" title="720px">S</button><button onclick="lpeResizePanel(900)" style="padding:.15rem .4rem;font-size:.65rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;background:#fff;color:#64748B" title="900px">M</button><button onclick="lpeResizePanel(1100)" style="padding:.15rem .4rem;font-size:.65rem;border:1px solid var(--border);border-radius:4px;cursor:pointer;background:#fff;color:#64748B" title="1100px">L</button>
      </div>
    </div>
    <div style="display:flex;gap:.3rem">
      <button onclick="lpeSave()" style="padding:.28rem .65rem;background:#10B981;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:.73rem;font-weight:600">💾 Save</button>
      <button onclick="lpeClosePreview()" style="padding:.28rem .55rem;background:#F1F5F9;color:var(--ink);border:1px solid var(--border);border-radius:5px;cursor:pointer;font-size:.73rem">✕</button>
    </div>
  </div>
  <iframe id="lpe-preview-iframe" src="" style="flex:1;border:none;width:100%;height:100%;opacity:0;transition:opacity .3s" title="Landing Preview"></iframe>
</div>
<div class="lpe-tabs">
<button onclick="lpeTab('brand')" id="lpe-tab-brand" class="lpe-tab">🏷 Brand</button>
<button onclick="lpeTab('hero')" id="lpe-tab-hero" class="lpe-tab active">🦸 Hero</button>
<button onclick="lpeTab('buttons')" id="lpe-tab-buttons" class="lpe-tab">🔗 Buttons</button>
<button onclick="lpeTab('stats')" id="lpe-tab-stats" class="lpe-tab">📊 Stats</button>
<button onclick="lpeTab('benefits')" id="lpe-tab-benefits" class="lpe-tab">✨ Features</button>
<button onclick="lpeTab('trust')" id="lpe-tab-trust" class="lpe-tab">🤝 Trust</button>
<button onclick="lpeTab('products')" id="lpe-tab-products" class="lpe-tab">📝 Headings</button>
<button onclick="lpeTab('demo')" id="lpe-tab-demo" class="lpe-tab">🎭 Demo</button>
<button onclick="lpeTab('contact')" id="lpe-tab-contact" class="lpe-tab">📞 Contact</button>
<button onclick="lpeTab('footer')" id="lpe-tab-footer" class="lpe-tab">🔻 Footer</button>
<button onclick="lpeTab('colors')" id="lpe-tab-colors" class="lpe-tab">🎨 Colors</button>
<button onclick="lpeTab('fonts')" id="lpe-tab-fonts" class="lpe-tab">🔤 Fonts</button>
<button onclick="lpeTab('banner')" id="lpe-tab-banner" class="lpe-tab">📣 Banner</button>
</div>
<div class="lpe-content">
<div id="lpe-panel-hero" class="lpe-panel active"><div class="lpe-section"><div class="lpe-section-title">① Title Line 1 (ပထမကြောင်း)</div><div class="lpe-field"><label class="lpe-label"></label><input id="lp-t1" class="lpe-input" placeholder="သင့်လုပ်ငန်းကို" oninput="lpeRT('lp-t1','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-t1-font" class="lpe-font-sel" onchange="lpeRT('lp-t1','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-t1-size" min="16" max="80" value="40" oninput="document.getElementById('lp-t1-sz-v').textContent=this.value+'px';document.getElementById('lp-t1-size-num').value=this.value;lpeRT('lp-t1','size',this.value)"><input type="number" id="lp-t1-size-num" value="40" min="16" max="80" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||40,16),80);this.value=v;document.getElementById('lp-t1-size').value=v;document.getElementById('lp-t1-sz-v').textContent=v+'px';lpeRT('lp-t1','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||40,16),80);this.value=v;document.getElementById('lp-t1-size').value=v;document.getElementById('lp-t1-sz-v').textContent=v+'px';"><span style="display:none" id="lp-t1-sz-v" class="lpe-range-val">40px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-t1','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-t1','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-t1','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-t1','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-t1','800',this)">X</button><input type="hidden" id="lp-t1-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-t1','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-t1','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-t1','right',this)">⮕</button><input type="hidden" id="lp-t1-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-t1-color" value="#0F172A" oninput="lpeColorSync('lp-t1-color');lpeRT('lp-t1','color',this.value)"><input id="lp-t1-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-t1-color');lpeRT('lp-t1','color',document.getElementById('lp-t1-color').value)"></div><span class="lpe-ctrl-lbl">LineH</span><div class="lpe-range-wrap"><input type="range" id="lp-t1-lh" min="1.0" max="3.5" step="0.1" value="1.4" oninput="document.getElementById('lp-t1-lh-v').textContent=this.value;lpeRT('lp-t1','lh',this.value)"><span id="lp-t1-lh-v" class="lpe-range-val">1.4</span></div></div></div></div><div class="lpe-section"><div class="lpe-section-title">② Title Line 2 Highlight</div><div class="lpe-field"><label class="lpe-label"></label><input id="lp-t2" class="lpe-input" placeholder="AI နဲ့ ထွန်းလင်း" oninput="lpeRT('lp-t2','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-t2-font" class="lpe-font-sel" onchange="lpeRT('lp-t2','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-t2-size" min="16" max="80" value="40" oninput="document.getElementById('lp-t2-sz-v').textContent=this.value+'px';document.getElementById('lp-t2-size-num').value=this.value;lpeRT('lp-t2','size',this.value)"><input type="number" id="lp-t2-size-num" value="40" min="16" max="80" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||40,16),80);this.value=v;document.getElementById('lp-t2-size').value=v;document.getElementById('lp-t2-sz-v').textContent=v+'px';lpeRT('lp-t2','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||40,16),80);this.value=v;document.getElementById('lp-t2-size').value=v;document.getElementById('lp-t2-sz-v').textContent=v+'px';"><span style="display:none" id="lp-t2-sz-v" class="lpe-range-val">40px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-t2','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-t2','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-t2','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-t2','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-t2','800',this)">X</button><input type="hidden" id="lp-t2-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-t2','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-t2','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-t2','right',this)">⮕</button><input type="hidden" id="lp-t2-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-t2-color" value="#0D9F6E" oninput="lpeColorSync('lp-t2-color');lpeRT('lp-t2','color',this.value)"><input id="lp-t2-color-hex" class="lpe-input lpe-color-hex" value="#0D9F6E" oninput="lpeHexSync('lp-t2-color');lpeRT('lp-t2','color',document.getElementById('lp-t2-color').value)"></div></div></div></div><div class="lpe-section"><div class="lpe-section-title">③ Sub-headline</div><div class="lpe-field"><label class="lpe-label"></label><input id="lp-sub" class="lpe-input" placeholder="MyanAi — မြန်မာ business တွေ" oninput="lpeRT('lp-sub','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-sub-font" class="lpe-font-sel" onchange="lpeRT('lp-sub','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-sub-size" min="12" max="32" value="16" oninput="document.getElementById('lp-sub-sz-v').textContent=this.value+'px';document.getElementById('lp-sub-size-num').value=this.value;lpeRT('lp-sub','size',this.value)"><input type="number" id="lp-sub-size-num" value="16" min="12" max="32" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||16,12),32);this.value=v;document.getElementById('lp-sub-size').value=v;document.getElementById('lp-sub-sz-v').textContent=v+'px';lpeRT('lp-sub','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||16,12),32);this.value=v;document.getElementById('lp-sub-size').value=v;document.getElementById('lp-sub-sz-v').textContent=v+'px';"><span style="display:none" id="lp-sub-sz-v" class="lpe-range-val">16px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-sub','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-sub','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-sub','right',this)">⮕</button><input type="hidden" id="lp-sub-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-sub-color" value="#57534E" oninput="lpeColorSync('lp-sub-color');lpeRT('lp-sub','color',this.value)"><input id="lp-sub-color-hex" class="lpe-input lpe-color-hex" value="#57534E" oninput="lpeHexSync('lp-sub-color');lpeRT('lp-sub','color',document.getElementById('lp-sub-color').value)"></div><span class="lpe-ctrl-lbl">LineH</span><div class="lpe-range-wrap"><input type="range" id="lp-sub-lh" min="1.0" max="3.5" step="0.1" value="1.4" oninput="document.getElementById('lp-sub-lh-v').textContent=this.value;lpeRT('lp-sub','lh',this.value)"><span id="lp-sub-lh-v" class="lpe-range-val">1.4</span></div></div></div></div><div class="lpe-section"><div class="lpe-section-title">📊 Mock Dashboard (ညာဘက် slideshow box)</div>
<div class="lpe-field"><label class="lpe-label">Box width (px)</label><input type="number" id="lp-mock-width" class="lpe-input" placeholder="560" min="300" max="900" oninput="lpeRT('mock','width',this.value)"></div>
<div class="lpe-field"><label class="lpe-label">Text size (em, ဥပမာ 1.05)</label><input type="number" step="0.05" id="lp-mock-fontsize" class="lpe-input" placeholder="1.05" min="0.7" max="2" oninput="lpeRT('mock','fontsize',this.value)"></div>
<div class="lpe-field"><label class="lpe-label">Text color</label><div class="lpe-color-wrap"><input type="color" id="lp-mock-color" value="#0F172A" oninput="lpeColorSync('lp-mock-color');lpeRT('mock','color',this.value)"><input id="lp-mock-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-mock-color');lpeRT('mock','color',document.getElementById('lp-mock-color').value)"></div></div>
</div>
<div class="lpe-section"><div class="lpe-section-title">④ Description (Hero body text)</div><div class="lpe-field"><label class="lpe-label"></label><textarea id="lp-hero-desc" class="lpe-textarea" placeholder="POS, HR, Analytics…" rows="3" oninput="lpeRT('hero-desc','text',this.value)"></textarea><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-desc-font" class="lpe-font-sel" onchange="lpeRT('hero-desc','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-desc-size" min="11" max="22" value="14" oninput="document.getElementById('lp-desc-sz-v').textContent=this.value+'px';document.getElementById('lp-desc-size-num').value=this.value;lpeRT('hero-desc','size',this.value)"><input type="number" id="lp-desc-size-num" value="14" min="11" max="22" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||14,11),22);this.value=v;document.getElementById('lp-desc-size').value=v;document.getElementById('lp-desc-sz-v').textContent=v+'px';lpeRT('hero-desc','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||14,11),22);this.value=v;document.getElementById('lp-desc-size').value=v;document.getElementById('lp-desc-sz-v').textContent=v+'px';"><span style="display:none" id="lp-desc-sz-v" class="lpe-range-val">14px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('hero-desc','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('hero-desc','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('hero-desc','right',this)">⮕</button><input type="hidden" id="lp-desc-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-desc-color" value="#78716C" oninput="lpeColorSync('lp-desc-color');lpeRT('hero-desc','color',this.value)"><input id="lp-desc-color-hex" class="lpe-input lpe-color-hex" value="#78716C" oninput="lpeHexSync('lp-desc-color');lpeRT('hero-desc','color',document.getElementById('lp-desc-color').value)"></div><span class="lpe-ctrl-lbl">LineH</span><div class="lpe-range-wrap"><input type="range" id="lp-desc-lh" min="1.0" max="3.5" step="0.1" value="1.4" oninput="document.getElementById('lp-desc-lh-v').textContent=this.value;lpeRT('hero-desc','lh',this.value)"><span id="lp-desc-lh-v" class="lpe-range-val">1.4</span></div></div></div></div><div class="lpe-section"><div class="lpe-section-title">⑤ Badge / Pill</div><div class="lpe-field"><label class="lpe-label"></label><input id="lp-badge" class="lpe-input" placeholder="🇲🇲 Myanmar-built · AI-powered" oninput="lpeRT('lp-badge','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-badge-font" class="lpe-font-sel" onchange="lpeRT('lp-badge','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-badge-size" min="10" max="18" value="12" oninput="document.getElementById('lp-badge-sz-v').textContent=this.value+'px';document.getElementById('lp-badge-size-num').value=this.value;lpeRT('lp-badge','size',this.value)"><input type="number" id="lp-badge-size-num" value="12" min="10" max="18" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||12,10),18);this.value=v;document.getElementById('lp-badge-size').value=v;document.getElementById('lp-badge-sz-v').textContent=v+'px';lpeRT('lp-badge','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||12,10),18);this.value=v;document.getElementById('lp-badge-size').value=v;document.getElementById('lp-badge-sz-v').textContent=v+'px';"><span style="display:none" id="lp-badge-sz-v" class="lpe-range-val">12px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-badge','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-badge','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-badge','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-badge','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-badge','800',this)">X</button><input type="hidden" id="lp-badge-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-badge-color" value="#92400E" oninput="lpeColorSync('lp-badge-color');lpeRT('lp-badge','color',this.value)"><input id="lp-badge-color-hex" class="lpe-input lpe-color-hex" value="#92400E" oninput="lpeHexSync('lp-badge-color');lpeRT('lp-badge','color',document.getElementById('lp-badge-color').value)"></div></div></div></div></div>
<div id="lpe-panel-buttons" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🔵 Primary CTA Button</div><div class="lpe-field"><label class="lpe-label">Button text</label><input id="lp-cta1" class="lpe-input" placeholder="၁၄ ရက် အခမဲ့ →" oninput="lpeRT('lp-cta1','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-cta1-font" class="lpe-font-sel" onchange="lpeRT('lp-cta1','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-cta1-size" min="12" max="24" value="15" oninput="document.getElementById('lp-cta1-sz-v').textContent=this.value+'px';document.getElementById('lp-cta1-size-num').value=this.value;lpeRT('lp-cta1','size',this.value)"><input type="number" id="lp-cta1-size-num" value="15" min="12" max="24" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||15,12),24);this.value=v;document.getElementById('lp-cta1-size').value=v;document.getElementById('lp-cta1-sz-v').textContent=v+'px';lpeRT('lp-cta1','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||15,12),24);this.value=v;document.getElementById('lp-cta1-size').value=v;document.getElementById('lp-cta1-sz-v').textContent=v+'px';"><span style="display:none" id="lp-cta1-sz-v" class="lpe-range-val">15px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-cta1','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-cta1','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-cta1','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-cta1','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-cta1','800',this)">X</button><input type="hidden" id="lp-cta1-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-cta1-color" value="#0F172A" oninput="lpeColorSync('lp-cta1-color');lpeRT('lp-cta1','color',this.value)"><input id="lp-cta1-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-cta1-color');lpeRT('lp-cta1','color',document.getElementById('lp-cta1-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">🔗 Button URL</label><input id="lp-cta1-url" class="lpe-input" placeholder="/signup.html"></div><div class="lpe-field"><label class="lpe-label">Button background color</label><div class="lpe-color-wrap"><input type="color" id="lp-cta1-bg" value="#0D9F6E" oninput="lpeColorSync('lp-cta1-bg');lpeRT('lp-cta1-bg','bg',this.value)"><input id="lp-cta1-bg-hex" class="lpe-input lpe-color-hex" value="#0D9F6E" oninput="lpeHexSync('lp-cta1-bg');lpeRT('lp-cta1-bg','bg',document.getElementById('lp-cta1-bg').value)"></div></div></div><div class="lpe-section"><div class="lpe-section-title">⬜ Secondary CTA Button</div><div class="lpe-field"><label class="lpe-label">Button text</label><input id="lp-cta2" class="lpe-input" placeholder="🎭 Demo ကြည့်မည်" oninput="lpeRT('lp-cta2','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-cta2-font" class="lpe-font-sel" onchange="lpeRT('lp-cta2','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-cta2-size" min="12" max="24" value="15" oninput="document.getElementById('lp-cta2-sz-v').textContent=this.value+'px';document.getElementById('lp-cta2-size-num').value=this.value;lpeRT('lp-cta2','size',this.value)"><input type="number" id="lp-cta2-size-num" value="15" min="12" max="24" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||15,12),24);this.value=v;document.getElementById('lp-cta2-size').value=v;document.getElementById('lp-cta2-sz-v').textContent=v+'px';lpeRT('lp-cta2','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||15,12),24);this.value=v;document.getElementById('lp-cta2-size').value=v;document.getElementById('lp-cta2-sz-v').textContent=v+'px';"><span style="display:none" id="lp-cta2-sz-v" class="lpe-range-val">15px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-cta2','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-cta2','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-cta2','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-cta2','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-cta2','800',this)">X</button><input type="hidden" id="lp-cta2-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-cta2-color" value="#0F172A" oninput="lpeColorSync('lp-cta2-color');lpeRT('lp-cta2','color',this.value)"><input id="lp-cta2-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-cta2-color');lpeRT('lp-cta2','color',document.getElementById('lp-cta2-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">🔗 Button URL</label><input id="lp-demo-url" class="lpe-input" placeholder="/tenant.php"></div><div class="lpe-field"><label class="lpe-label">Button background color</label><div class="lpe-color-wrap"><input type="color" id="lp-cta2-bg" value="#FFFFFF" oninput="lpeColorSync('lp-cta2-bg');lpeRT('lp-cta2-bg','bg',this.value)"><input id="lp-cta2-bg-hex" class="lpe-input lpe-color-hex" value="#FFFFFF" oninput="lpeHexSync('lp-cta2-bg');lpeRT('lp-cta2-bg','bg',document.getElementById('lp-cta2-bg').value)"></div></div></div><div class="lpe-section"><div class="lpe-section-title">🟢 Nav Bar CTA</div><div class="lpe-field"><label class="lpe-label">Button text</label><input id="lp-nav-cta" class="lpe-input" placeholder="Free Trial →" oninput="lpeRT('lp-nav-cta','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-nav-cta-font" class="lpe-font-sel" onchange="lpeRT('lp-nav-cta','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-nav-cta-size" min="11" max="20" value="14" oninput="document.getElementById('lp-nav-cta-sz-v').textContent=this.value+'px';document.getElementById('lp-nav-cta-size-num').value=this.value;lpeRT('lp-nav-cta','size',this.value)"><input type="number" id="lp-nav-cta-size-num" value="14" min="11" max="20" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||14,11),20);this.value=v;document.getElementById('lp-nav-cta-size').value=v;document.getElementById('lp-nav-cta-sz-v').textContent=v+'px';lpeRT('lp-nav-cta','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||14,11),20);this.value=v;document.getElementById('lp-nav-cta-size').value=v;document.getElementById('lp-nav-cta-sz-v').textContent=v+'px';"><span style="display:none" id="lp-nav-cta-sz-v" class="lpe-range-val">14px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-nav-cta','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-nav-cta','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-nav-cta','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-nav-cta','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-nav-cta','800',this)">X</button><input type="hidden" id="lp-nav-cta-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-nav-cta-color" value="#0F172A" oninput="lpeColorSync('lp-nav-cta-color');lpeRT('lp-nav-cta','color',this.value)"><input id="lp-nav-cta-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-nav-cta-color');lpeRT('lp-nav-cta','color',document.getElementById('lp-nav-cta-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">Background color</label><div class="lpe-color-wrap"><input type="color" id="lp-nav-cta-bg" value="#0D9F6E" oninput="lpeColorSync('lp-nav-cta-bg');lpeRT('lp-nav-cta-bg','bg',this.value)"><input id="lp-nav-cta-bg-hex" class="lpe-input lpe-color-hex" value="#0D9F6E" oninput="lpeHexSync('lp-nav-cta-bg');lpeRT('lp-nav-cta-bg','bg',document.getElementById('lp-nav-cta-bg').value)"></div></div></div></div>
<div id="lpe-panel-colors" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🖼 Backgrounds</div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Body background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-body" value="#FFFBF5" oninput="lpeColorSync('lp-bg-body');lpeRT('colors','lp-bg-body',this.value)"><input id="lp-bg-body-hex" class="lpe-input lpe-color-hex" value="#FFFBF5" oninput="lpeHexSync('lp-bg-body');lpeRT('colors','lp-bg-body',document.getElementById('lp-bg-body').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Hero background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-hero" value="#FFFBF5" oninput="lpeColorSync('lp-bg-hero');lpeRT('colors','lp-bg-hero',this.value)"><input id="lp-bg-hero-hex" class="lpe-input lpe-color-hex" value="#FFFBF5" oninput="lpeHexSync('lp-bg-hero');lpeRT('colors','lp-bg-hero',document.getElementById('lp-bg-hero').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Nav bar background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-nav" value="#FFFFFF" oninput="lpeColorSync('lp-bg-nav');lpeRT('colors','lp-bg-nav',this.value)"><input id="lp-bg-nav-hex" class="lpe-input lpe-color-hex" value="#FFFFFF" oninput="lpeHexSync('lp-bg-nav');lpeRT('colors','lp-bg-nav',document.getElementById('lp-bg-nav').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Sections background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-section" value="#FFFFFF" oninput="lpeColorSync('lp-bg-section');lpeRT('colors','lp-bg-section',this.value)"><input id="lp-bg-section-hex" class="lpe-input lpe-color-hex" value="#FFFFFF" oninput="lpeHexSync('lp-bg-section');lpeRT('colors','lp-bg-section',document.getElementById('lp-bg-section').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Footer background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-footer" value="#1C1F26" oninput="lpeColorSync('lp-bg-footer');lpeRT('colors','lp-bg-footer',this.value)"><input id="lp-bg-footer-hex" class="lpe-input lpe-color-hex" value="#1C1F26" oninput="lpeHexSync('lp-bg-footer');lpeRT('colors','lp-bg-footer',document.getElementById('lp-bg-footer').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Demo section background</label><div class="lpe-color-wrap"><input type="color" id="lp-bg-demo" value="#1a1f35" oninput="lpeColorSync('lp-bg-demo');lpeRT('colors','lp-bg-demo',this.value)"><input id="lp-bg-demo-hex" class="lpe-input lpe-color-hex" value="#1a1f35" oninput="lpeHexSync('lp-bg-demo');lpeRT('colors','lp-bg-demo',document.getElementById('lp-bg-demo').value)"></div></div></div><div class="lpe-section"><div class="lpe-section-title">✍ Text & Accent</div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Accent / primary color</label><div class="lpe-color-wrap"><input type="color" id="lp-color-accent" value="#0D9F6E" oninput="lpeColorSync('lp-color-accent');lpeRT('colors','lp-color-accent',this.value)"><input id="lp-color-accent-hex" class="lpe-input lpe-color-hex" value="#0D9F6E" oninput="lpeHexSync('lp-color-accent');lpeRT('colors','lp-color-accent',document.getElementById('lp-color-accent').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">H1 title color</label><div class="lpe-color-wrap"><input type="color" id="lp-color-h1" value="#0F172A" oninput="lpeColorSync('lp-color-h1');lpeRT('colors','lp-color-h1',this.value)"><input id="lp-color-h1-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-color-h1');lpeRT('colors','lp-color-h1',document.getElementById('lp-color-h1').value)"></div></div><div class="lpe-field" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem"><label class="lpe-label" style="margin:0;flex:1">Body text color</label><div class="lpe-color-wrap"><input type="color" id="lp-color-body" value="#57534E" oninput="lpeColorSync('lp-color-body');lpeRT('colors','lp-color-body',this.value)"><input id="lp-color-body-hex" class="lpe-input lpe-color-hex" value="#57534E" oninput="lpeHexSync('lp-color-body');lpeRT('colors','lp-color-body',document.getElementById('lp-color-body').value)"></div></div></div><div class="lpe-section"><div class="lpe-section-title">✨ Quick Themes</div><div style="display:flex;gap:.5rem;flex-wrap:wrap"><button onclick="lpeTheme('mint')" style="padding:.4rem .85rem;background:#E6F7F1;color:#065F46;border:1px solid #A7F3D0;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">🌿 Mint</button><button onclick="lpeTheme('ocean')" style="padding:.4rem .85rem;background:#E0F2FE;color:#0C4A6E;border:1px solid #BAE6FD;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">🌊 Ocean</button><button onclick="lpeTheme('peach')" style="padding:.4rem .85rem;background:#FFFBF5;color:#9A3412;border:1px solid #FED7AA;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">🍑 Peach</button><button onclick="lpeTheme('lavender')" style="padding:.4rem .85rem;background:#FAF5FF;color:#4C1D95;border:1px solid #DDD6FE;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">💜 Lavender</button><button onclick="lpeTheme('dark')" style="padding:.4rem .85rem;background:#1C1F26;color:#fff;border:1px solid #374151;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">🌑 Dark</button><button onclick="lpeTheme('sunset')" style="padding:.4rem .85rem;background:#FFF7ED;color:#0D9F6E;border:1px solid #FDBA74;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">🌅 Sunset</button><button onclick="lpeTheme('royal')" style="padding:.4rem .85rem;background:#EEF2FF;color:#4338CA;border:1px solid #C7D2FE;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600">👑 Royal</button></div></div></div>
<div id="lpe-panel-fonts" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🔤 Global Font Settings</div><div class="lpe-field"><label class="lpe-label">Myanmar Font (စာသားအကုန်)</label><select id="lp-font-mm" class="lpe-select-main" onchange="lpeFontMmChange(this)"><option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option><option value="'Padauk',sans-serif">Padauk</option><option value="'Pyidaungsu',sans-serif">Pyidaungsu</option><option id="lp-font-mm-custom-opt" value="custom" style="display:none">⭐ Custom Uploaded Font</option></select></div><div class="lpe-field" style="margin-top:.6rem;padding-top:.6rem;border-top:1px dashed var(--border)"><label class="lpe-label">Myanmar Font Upload (.ttf/.otf/.woff/.woff2)</label><input type="file" id="lp-font-mm-file" accept=".ttf,.otf,.woff,.woff2" onchange="uploadMmFont(this.files[0])" style="font-size:.78rem"><div id="lp-font-mm-status" style="font-size:.74rem;color:var(--text-muted);margin-top:.3rem"></div></div><div class="lpe-field"><label class="lpe-label">English Font</label><select id="lp-font-en" class="lpe-select-main" onchange="lpeRT('fonts','en',this.value)"><option value="'Inter',sans-serif">Inter ★</option><option value="'DM Sans',sans-serif">DM Sans</option><option value="'Poppins',sans-serif">Poppins</option></select></div></div></div>
<div id="lpe-panel-brand" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🏷 Brand Identity</div><div class="lpe-field"><label class="lpe-label">Brand / App name (Nav)</label><input id="lp-store-name" class="lpe-input" placeholder="MyanAi" oninput="lpeRT('lp-store-name','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-store-name-font" class="lpe-font-sel" onchange="lpeRT('lp-store-name','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-store-name-size" min="12" max="32" value="18" oninput="document.getElementById('lp-store-name-sz-v').textContent=this.value+'px';document.getElementById('lp-store-name-size-num').value=this.value;lpeRT('lp-store-name','size',this.value)"><input type="number" id="lp-store-name-size-num" value="18" min="12" max="32" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||18,12),32);this.value=v;document.getElementById('lp-store-name-size').value=v;document.getElementById('lp-store-name-sz-v').textContent=v+'px';lpeRT('lp-store-name','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||18,12),32);this.value=v;document.getElementById('lp-store-name-size').value=v;document.getElementById('lp-store-name-sz-v').textContent=v+'px';"><span style="display:none" id="lp-store-name-sz-v" class="lpe-range-val">18px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-store-name','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-store-name','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-store-name','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-store-name','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-store-name','800',this)">X</button><input type="hidden" id="lp-store-name-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-store-name-color" value="#0F172A" oninput="lpeColorSync('lp-store-name-color');lpeRT('lp-store-name','color',this.value)"><input id="lp-store-name-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-store-name-color');lpeRT('lp-store-name','color',document.getElementById('lp-store-name-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">Tagline</label><input id="lp-tagline" class="lpe-input" placeholder="Myanmar AI Platform" oninput="lpeRT('lp-tagline','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-tagline-font" class="lpe-font-sel" onchange="lpeRT('lp-tagline','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-tagline-size" min="10" max="22" value="13" oninput="document.getElementById('lp-tagline-sz-v').textContent=this.value+'px';document.getElementById('lp-tagline-size-num').value=this.value;lpeRT('lp-tagline','size',this.value)"><input type="number" id="lp-tagline-size-num" value="13" min="10" max="22" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||13,10),22);this.value=v;document.getElementById('lp-tagline-size').value=v;document.getElementById('lp-tagline-sz-v').textContent=v+'px';lpeRT('lp-tagline','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||13,10),22);this.value=v;document.getElementById('lp-tagline-size').value=v;document.getElementById('lp-tagline-sz-v').textContent=v+'px';"><span style="display:none" id="lp-tagline-sz-v" class="lpe-range-val">13px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-tagline','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-tagline','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-tagline','right',this)">⮕</button><input type="hidden" id="lp-tagline-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-tagline-color" value="#57534E" oninput="lpeColorSync('lp-tagline-color');lpeRT('lp-tagline','color',this.value)"><input id="lp-tagline-color-hex" class="lpe-input lpe-color-hex" value="#57534E" oninput="lpeHexSync('lp-tagline-color');lpeRT('lp-tagline','color',document.getElementById('lp-tagline-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">📄 Page title (Browser tab)</label><input id="lp-page-title" class="lpe-input" placeholder="MyanAi — Myanmar AI"></div><div class="lpe-field"><label class="lpe-label">Brand emoji</label><input id="lp-emoji" class="lpe-input" placeholder="🤖" style="width:70px;font-size:1.3rem;text-align:center" oninput="lpeRT('brand','emoji',this.value)"></div></div>
</div>
<div id="lpe-panel-banner" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">📣 Announcement Banner</div><div class="lpe-field" style="display:flex;align-items:center;gap:.6rem"><input type="checkbox" id="ann-on" style="width:16px;height:16px" onchange="lpeRT('banner','on',this.checked?'1':'0')"><label for="ann-on" class="lpe-label" style="margin:0;cursor:pointer">Banner ပြပါ</label></div><div class="lpe-field"><label class="lpe-label">Banner text</label><textarea id="ann-text" class="lpe-textarea" placeholder="🎉 New feature..." rows="2" oninput="lpeRT('ann-text','text',this.value)"></textarea><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="ann-text-font" class="lpe-font-sel" onchange="lpeRT('ann-text','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="ann-text-size" min="11" max="20" value="14" oninput="document.getElementById('ann-text-sz-v').textContent=this.value+'px';document.getElementById('ann-text-size-num').value=this.value;lpeRT('ann-text','size',this.value)"><input type="number" id="ann-text-size-num" value="14" min="11" max="20" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||14,11),20);this.value=v;document.getElementById('ann-text-size').value=v;document.getElementById('ann-text-sz-v').textContent=v+'px';lpeRT('ann-text','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||14,11),20);this.value=v;document.getElementById('ann-text-size').value=v;document.getElementById('ann-text-sz-v').textContent=v+'px';"><span style="display:none" id="ann-text-sz-v" class="lpe-range-val">14px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('ann-text','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('ann-text','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('ann-text','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('ann-text','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('ann-text','800',this)">X</button><input type="hidden" id="ann-text-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('ann-text','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('ann-text','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('ann-text','right',this)">⮕</button><input type="hidden" id="ann-text-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="ann-text-color" value="#FFFFFF" oninput="lpeColorSync('ann-text-color');lpeRT('ann-text','color',this.value)"><input id="ann-text-color-hex" class="lpe-input lpe-color-hex" value="#FFFFFF" oninput="lpeHexSync('ann-text-color');lpeRT('ann-text','color',document.getElementById('ann-text-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">Banner background color</label><div class="lpe-color-wrap"><input type="color" id="lp-ann-color" value="#10B981" oninput="lpeColorSync('lp-ann-color');lpeRT('banner','bg',this.value)"><input id="lp-ann-color-hex" class="lpe-input lpe-color-hex" value="#10B981" oninput="lpeHexSync('lp-ann-color');lpeRT('banner','bg',document.getElementById('lp-ann-color').value)"></div></div></div></div>
<div id="lpe-panel-contact" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">📝 Contact Section Text</div><div class="lpe-field"><label class="lpe-label">Section heading</label><input id="lp-contact-heading" class="lpe-input" placeholder="မေးစရာ ရှိပါသလား" oninput="lpeRT('lp-contact-heading','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-contact-heading-font" class="lpe-font-sel" onchange="lpeRT('lp-contact-heading','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-contact-heading-size" min="16" max="40" value="24" oninput="document.getElementById('lp-contact-heading-sz-v').textContent=this.value+'px';document.getElementById('lp-contact-heading-size-num').value=this.value;lpeRT('lp-contact-heading','size',this.value)"><input type="number" id="lp-contact-heading-size-num" value="24" min="16" max="40" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||24,16),40);this.value=v;document.getElementById('lp-contact-heading-size').value=v;document.getElementById('lp-contact-heading-sz-v').textContent=v+'px';lpeRT('lp-contact-heading','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||24,16),40);this.value=v;document.getElementById('lp-contact-heading-size').value=v;document.getElementById('lp-contact-heading-sz-v').textContent=v+'px';"><span style="display:none" id="lp-contact-heading-sz-v" class="lpe-range-val">24px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-contact-heading','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-contact-heading','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-contact-heading','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-contact-heading','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-contact-heading','800',this)">X</button><input type="hidden" id="lp-contact-heading-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-contact-heading','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-contact-heading','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-contact-heading','right',this)">⮕</button><input type="hidden" id="lp-contact-heading-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-contact-heading-color" value="#0F172A" oninput="lpeColorSync('lp-contact-heading-color');lpeRT('lp-contact-heading','color',this.value)"><input id="lp-contact-heading-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-contact-heading-color');lpeRT('lp-contact-heading','color',document.getElementById('lp-contact-heading-color').value)"></div><span class="lpe-ctrl-lbl">LineH</span><div class="lpe-range-wrap"><input type="range" id="lp-contact-heading-lh" min="1.0" max="3.5" step="0.1" value="1.4" oninput="document.getElementById('lp-contact-heading-lh-v').textContent=this.value;lpeRT('lp-contact-heading','lh',this.value)"><span id="lp-contact-heading-lh-v" class="lpe-range-val">1.4</span></div></div></div><div class="lpe-field"><label class="lpe-label">Subtitle</label><input id="lp-contact-sub" class="lpe-input" placeholder="ဆက်သွယ်နိုင်သည်" oninput="lpeRT('lp-contact-sub','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-contact-sub-font" class="lpe-font-sel" onchange="lpeRT('lp-contact-sub','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-contact-sub-size" min="12" max="22" value="15" oninput="document.getElementById('lp-contact-sub-sz-v').textContent=this.value+'px';document.getElementById('lp-contact-sub-size-num').value=this.value;lpeRT('lp-contact-sub','size',this.value)"><input type="number" id="lp-contact-sub-size-num" value="15" min="12" max="22" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-contact-sub-size').value=v;document.getElementById('lp-contact-sub-sz-v').textContent=v+'px';lpeRT('lp-contact-sub','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-contact-sub-size').value=v;document.getElementById('lp-contact-sub-sz-v').textContent=v+'px';"><span style="display:none" id="lp-contact-sub-sz-v" class="lpe-range-val">15px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-contact-sub','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-contact-sub','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-contact-sub','right',this)">⮕</button><input type="hidden" id="lp-contact-sub-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-contact-sub-color" value="#57534E" oninput="lpeColorSync('lp-contact-sub-color');lpeRT('lp-contact-sub','color',this.value)"><input id="lp-contact-sub-color-hex" class="lpe-input lpe-color-hex" value="#57534E" oninput="lpeHexSync('lp-contact-sub-color');lpeRT('lp-contact-sub','color',document.getElementById('lp-contact-sub-color').value)"></div></div></div></div><div class="lpe-section"><div class="lpe-section-title">🔗 Contact Links</div><div class="lpe-field"><label class="lpe-label">📞 Phone / Viber</label><input id="lp-phone" class="lpe-input" placeholder="09-XXXXXXXXX"></div><div class="lpe-field"><label class="lpe-label">📧 Email</label><input id="lp-email" class="lpe-input" placeholder="hello@myanai.net"></div><div class="lpe-field"><label class="lpe-label">📍 Address</label><input id="lp-address" class="lpe-input" placeholder="Yangon, Myanmar"></div><div class="lpe-field"><label class="lpe-label">📘 Facebook URL</label><input id="lp-facebook" class="lpe-input" placeholder="https://fb.com/myanai"></div><div class="lpe-field"><label class="lpe-label">💬 Messenger URL</label><input id="lp-messenger" class="lpe-input" placeholder="https://m.me/myanai"></div><div class="lpe-field"><label class="lpe-label">📱 Viber URL</label><input id="lp-viber" class="lpe-input" placeholder="viber://chat?number=09..."></div><div class="lpe-field"><label class="lpe-label">📸 Instagram URL</label><input id="lp-instagram" class="lpe-input" placeholder="https://instagram.com/myanai"></div><div class="lpe-field"><label class="lpe-label">🎵 TikTok URL</label><input id="lp-tiktok" class="lpe-input" placeholder="https://tiktok.com/@myanai"></div></div></div>
<div id="lpe-panel-demo" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🎭 Demo Section</div><div class="lpe-field"><label class="lpe-label">Section heading</label><input id="lp-demo-heading" class="lpe-input" placeholder="Register မဘဲ Live Demo ကြည့်ပါ" oninput="lpeRT('lp-demo-heading','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-demo-heading-font" class="lpe-font-sel" onchange="lpeRT('lp-demo-heading','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-demo-heading-size" min="16" max="40" value="24" oninput="document.getElementById('lp-demo-heading-sz-v').textContent=this.value+'px';document.getElementById('lp-demo-heading-size-num').value=this.value;lpeRT('lp-demo-heading','size',this.value)"><input type="number" id="lp-demo-heading-size-num" value="24" min="16" max="40" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||24,16),40);this.value=v;document.getElementById('lp-demo-heading-size').value=v;document.getElementById('lp-demo-heading-sz-v').textContent=v+'px';lpeRT('lp-demo-heading','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||24,16),40);this.value=v;document.getElementById('lp-demo-heading-size').value=v;document.getElementById('lp-demo-heading-sz-v').textContent=v+'px';"><span style="display:none" id="lp-demo-heading-sz-v" class="lpe-range-val">24px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-demo-heading','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-demo-heading','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-demo-heading','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-demo-heading','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-demo-heading','800',this)">X</button><input type="hidden" id="lp-demo-heading-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-demo-heading','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-demo-heading','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-demo-heading','right',this)">⮕</button><input type="hidden" id="lp-demo-heading-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-demo-heading-color" value="#0F172A" oninput="lpeColorSync('lp-demo-heading-color');lpeRT('lp-demo-heading','color',this.value)"><input id="lp-demo-heading-color-hex" class="lpe-input lpe-color-hex" value="#0F172A" oninput="lpeHexSync('lp-demo-heading-color');lpeRT('lp-demo-heading','color',document.getElementById('lp-demo-heading-color').value)"></div><span class="lpe-ctrl-lbl">LineH</span><div class="lpe-range-wrap"><input type="range" id="lp-demo-heading-lh" min="1.0" max="3.5" step="0.1" value="1.4" oninput="document.getElementById('lp-demo-heading-lh-v').textContent=this.value;lpeRT('lp-demo-heading','lh',this.value)"><span id="lp-demo-heading-lh-v" class="lpe-range-val">1.4</span></div></div></div><div class="lpe-field"><label class="lpe-label">Subtitle</label><input id="lp-demo-sub" class="lpe-input" placeholder="Features အကုန် စမ်းနိုင်" oninput="lpeRT('lp-demo-sub','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-demo-sub-font" class="lpe-font-sel" onchange="lpeRT('lp-demo-sub','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-demo-sub-size" min="12" max="22" value="15" oninput="document.getElementById('lp-demo-sub-sz-v').textContent=this.value+'px';document.getElementById('lp-demo-sub-size-num').value=this.value;lpeRT('lp-demo-sub','size',this.value)"><input type="number" id="lp-demo-sub-size-num" value="15" min="12" max="22" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-demo-sub-size').value=v;document.getElementById('lp-demo-sub-sz-v').textContent=v+'px';lpeRT('lp-demo-sub','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-demo-sub-size').value=v;document.getElementById('lp-demo-sub-sz-v').textContent=v+'px';"><span style="display:none" id="lp-demo-sub-sz-v" class="lpe-range-val">15px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-demo-sub','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-demo-sub','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-demo-sub','right',this)">⮕</button><input type="hidden" id="lp-demo-sub-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-demo-sub-color" value="#57534E" oninput="lpeColorSync('lp-demo-sub-color');lpeRT('lp-demo-sub','color',this.value)"><input id="lp-demo-sub-color-hex" class="lpe-input lpe-color-hex" value="#57534E" oninput="lpeHexSync('lp-demo-sub-color');lpeRT('lp-demo-sub','color',document.getElementById('lp-demo-sub-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">📧 Demo email</label><input id="lp-demo-email" class="lpe-input" placeholder="demo@myanai.net"></div><div class="lpe-field"><label class="lpe-label">🔑 Demo password</label><input id="lp-demo-pass" class="lpe-input" placeholder="demo1234"></div><div class="lpe-field"><label class="lpe-label">Demo button text</label><input id="lp-demo-btn" class="lpe-input" placeholder="🎭 Demo Admin ဝင်ကြည့် →" oninput="lpeRT('lp-demo-btn','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-demo-btn-font" class="lpe-font-sel" onchange="lpeRT('lp-demo-btn','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-demo-btn-size" min="12" max="22" value="15" oninput="document.getElementById('lp-demo-btn-sz-v').textContent=this.value+'px';document.getElementById('lp-demo-btn-size-num').value=this.value;lpeRT('lp-demo-btn','size',this.value)"><input type="number" id="lp-demo-btn-size-num" value="15" min="12" max="22" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-demo-btn-size').value=v;document.getElementById('lp-demo-btn-sz-v').textContent=v+'px';lpeRT('lp-demo-btn','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||15,12),22);this.value=v;document.getElementById('lp-demo-btn-size').value=v;document.getElementById('lp-demo-btn-sz-v').textContent=v+'px';"><span style="display:none" id="lp-demo-btn-sz-v" class="lpe-range-val">15px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-demo-btn','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-demo-btn','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-demo-btn','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-demo-btn','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-demo-btn','800',this)">X</button><input type="hidden" id="lp-demo-btn-weight" value="700"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-demo-btn-color" value="#FFFFFF" oninput="lpeColorSync('lp-demo-btn-color');lpeRT('lp-demo-btn','color',this.value)"><input id="lp-demo-btn-color-hex" class="lpe-input lpe-color-hex" value="#FFFFFF" oninput="lpeHexSync('lp-demo-btn-color');lpeRT('lp-demo-btn','color',document.getElementById('lp-demo-btn-color').value)"></div></div></div></div></div>
<div id="lpe-panel-footer" class="lpe-panel"><div class="lpe-section"><div class="lpe-section-title">🔻 Footer</div><div class="lpe-field"><label class="lpe-label">Copyright text</label><input id="lp-copyright" class="lpe-input" placeholder="© 2026 MyanAi.net" oninput="lpeRT('lp-copyright','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-copyright-font" class="lpe-font-sel" onchange="lpeRT('lp-copyright','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-copyright-size" min="10" max="18" value="13" oninput="document.getElementById('lp-copyright-sz-v').textContent=this.value+'px';document.getElementById('lp-copyright-size-num').value=this.value;lpeRT('lp-copyright','size',this.value)"><input type="number" id="lp-copyright-size-num" value="13" min="10" max="18" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||13,10),18);this.value=v;document.getElementById('lp-copyright-size').value=v;document.getElementById('lp-copyright-sz-v').textContent=v+'px';lpeRT('lp-copyright','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||13,10),18);this.value=v;document.getElementById('lp-copyright-size').value=v;document.getElementById('lp-copyright-sz-v').textContent=v+'px';"><span style="display:none" id="lp-copyright-sz-v" class="lpe-range-val">13px</span></div><span class="lpe-ctrl-lbl">W</span><button class="lpe-fw" onclick="lpeFW('lp-copyright','400',this)">R</button><button class="lpe-fw" onclick="lpeFW('lp-copyright','500',this)">M</button><button class="lpe-fw" onclick="lpeFW('lp-copyright','600',this)">S</button><button class="lpe-fw on" onclick="lpeFW('lp-copyright','700',this)">B</button><button class="lpe-fw" onclick="lpeFW('lp-copyright','800',this)">X</button><input type="hidden" id="lp-copyright-weight" value="700"><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-copyright','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-copyright','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-copyright','right',this)">⮕</button><input type="hidden" id="lp-copyright-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-copyright-color" value="#94A3B8" oninput="lpeColorSync('lp-copyright-color');lpeRT('lp-copyright','color',this.value)"><input id="lp-copyright-color-hex" class="lpe-input lpe-color-hex" value="#94A3B8" oninput="lpeHexSync('lp-copyright-color');lpeRT('lp-copyright','color',document.getElementById('lp-copyright-color').value)"></div></div></div><div class="lpe-field"><label class="lpe-label">Footer tagline</label><input id="lp-foot-tagline" class="lpe-input" placeholder="Myanmar AI Platform" oninput="lpeRT('lp-foot-tagline','text',this.value)"><div class="lpe-ctrl"><span class="lpe-ctrl-lbl">Font</span><select id="lp-foot-tagline-font" class="lpe-font-sel" onchange="lpeRT('lp-foot-tagline','font',this.value)"><option value="">— Default —</option>
<option value="'Noto Sans Myanmar','Pyidaungsu','Padauk',sans-serif">Noto Sans Myanmar ★</option>
<option value="'Padauk',sans-serif">Padauk</option>
<option value="'Pyidaungsu',sans-serif">Pyidaungsu</option>
<option value="'Inter',sans-serif">Inter</option>
<option value="'DM Sans',sans-serif">DM Sans</option>
<option value="'Poppins',sans-serif">Poppins</option></select><span class="lpe-ctrl-lbl">Size</span><div class="lpe-range-wrap"><input type="range" id="lp-foot-tagline-size" min="10" max="18" value="13" oninput="document.getElementById('lp-foot-tagline-sz-v').textContent=this.value+'px';document.getElementById('lp-foot-tagline-size-num').value=this.value;lpeRT('lp-foot-tagline','size',this.value)"><input type="number" id="lp-foot-tagline-size-num" value="13" min="10" max="18" style="width:44px;padding:.12rem .2rem;font-size:.7rem;border:1px solid var(--border);border-radius:4px;text-align:center;font-family:monospace;color:var(--ink)" oninput="let v=Math.min(Math.max(parseInt(this.value)||13,10),18);this.value=v;document.getElementById('lp-foot-tagline-size').value=v;document.getElementById('lp-foot-tagline-sz-v').textContent=v+'px';lpeRT('lp-foot-tagline','size',this.value)" onblur="let v=Math.min(Math.max(parseInt(this.value)||13,10),18);this.value=v;document.getElementById('lp-foot-tagline-size').value=v;document.getElementById('lp-foot-tagline-sz-v').textContent=v+'px';"><span style="display:none" id="lp-foot-tagline-sz-v" class="lpe-range-val">13px</span></div><span class="lpe-ctrl-lbl">Align</span><button class="lpe-al on" onclick="lpeAL('lp-foot-tagline','left',this)">⬅</button><button class="lpe-al" onclick="lpeAL('lp-foot-tagline','center',this)">↔</button><button class="lpe-al" onclick="lpeAL('lp-foot-tagline','right',this)">⮕</button><input type="hidden" id="lp-foot-tagline-align" value="left"><span class="lpe-ctrl-lbl">Color</span><div class="lpe-color-wrap"><input type="color" id="lp-foot-tagline-color" value="#64748B" oninput="lpeColorSync('lp-foot-tagline-color');lpeRT('lp-foot-tagline','color',this.value)"><input id="lp-foot-tagline-color-hex" class="lpe-input lpe-color-hex" value="#64748B" oninput="lpeHexSync('lp-foot-tagline-color');lpeRT('lp-foot-tagline','color',document.getElementById('lp-foot-tagline-color').value)"></div></div></div></div></div>

<div id="lpe-panel-benefits" class="lpe-panel">
  <div class="lpe-section">
    <div class="lpe-section-title">✨ Feature Cards (6 cards)</div>
    <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem">Landing page ရဲ့ 6 feature cards တွေကို ပြင်ဆင်ပါ</div>

    <!-- Card 1 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 1</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat1-icon" class="lpe-input" placeholder="🧮" oninput="lpeRT('feat1-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat1-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat1-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat1-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat1-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 2 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 2</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat2-icon" class="lpe-input" placeholder="📶" oninput="lpeRT('feat2-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat2-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat2-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat2-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat2-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 3 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 3</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat3-icon" class="lpe-input" placeholder="💳" oninput="lpeRT('feat3-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat3-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat3-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat3-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat3-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 4 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 4</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat4-icon" class="lpe-input" placeholder="📱" oninput="lpeRT('feat4-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat4-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat4-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat4-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat4-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 5 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 5</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat5-icon" class="lpe-input" placeholder="📊" oninput="lpeRT('feat5-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat5-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat5-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat5-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat5-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 6 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 6</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat6-icon" class="lpe-input" placeholder="👥" oninput="lpeRT('feat6-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat6-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat6-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat6-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat6-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 7 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 7</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat7-icon" class="lpe-input" placeholder="🔔" oninput="lpeRT('feat7-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat7-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat7-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat7-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat7-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 8 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 8</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-feat8-icon" class="lpe-input" placeholder="📦" oninput="lpeRT('feat8-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-feat8-title" class="lpe-input" placeholder="Feature title" oninput="lpeRT('feat8-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-feat8-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('feat8-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Section heading -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Section Heading</div>
      <div class="lpe-field"><label class="lpe-label">Line 1</label><input id="lp-products-h1" class="lpe-input" placeholder="သင့်ဆိုင်ကို ပိုမိုလွယ်ကူ" oninput="lpeRT('products-heading-l1','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Line 2 (accent color)</label><input id="lp-products-h2" class="lpe-input" placeholder="စီမံနိုင်မယ့် နည်းလမ်းများ" oninput="lpeRT('products-heading-l2','text',this.value)"></div>
    </div>
  </div>
</div>

<div id="lpe-panel-products" class="lpe-panel">
  <div class="lpe-section">
    <div class="lpe-section-title">📝 Section Headings (Features + Pricing)</div>
    <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem">Features နှင့် Pricing section ၏ heading text များကို ပြင်ဆင်ပါ</div>

    <!-- Pricing heading -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Pricing Section</div>
      <div class="lpe-field"><label class="lpe-label">Eyebrow label (PRICING)</label><input id="lp-pricing-eye" class="lpe-input" placeholder="Pricing" oninput="lpeRT('pricing-eye','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Eyebrow color</label><div class="lpe-color-wrap"><input type="color" id="lp-pricing-eye-color" value="#000000" oninput="lpeColorSync('lp-pricing-eye-color');lpeRT('pricing-eye','color',this.value)"><input id="lp-pricing-eye-color-hex" class="lpe-input lpe-color-hex" value="#000000" oninput="lpeHexSync('lp-pricing-eye-color');lpeRT('pricing-eye','color',document.getElementById('lp-pricing-eye-color').value)"></div></div>
      <div class="lpe-field"><label class="lpe-label">Pricing line 1</label><input id="lp-pricing-h1" class="lpe-input" placeholder="မြန်မာဆိုင်တွေ" oninput="lpeRT('pricing-h1','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Pricing line 2 (gold)</label><input id="lp-pricing-h2" class="lpe-input" placeholder="နိုင်နိုင်နင်းနင်း" oninput="lpeRT('pricing-h2','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Pricing line 3</label><input id="lp-pricing-h3" class="lpe-input" placeholder="ဝင်နိုင်တဲ့ plan" oninput="lpeRT('pricing-h3','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Pricing subtext</label><input id="lp-pricing-sub-new" class="lpe-input" placeholder="Trial 14 days — credit card မလိုဘဲ" oninput="lpeRT('pricing-sub','text',this.value)"></div>
    </div>
  </div>
</div>
<div id="lpe-panel-stats" class="lpe-panel">
  <div class="lpe-section">
    <div class="lpe-section-title">📊 Stats Bar (4 numbers)</div>
    <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem">Landing page stats bar ကို ပြင်ဆင်ပါ</div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Stat 1</div>
      <div class="lpe-field"><label class="lpe-label">Number/Value</label><input id="lp-stat1-num" class="lpe-input" placeholder="Stat value" oninput="lpeRT('stat1-num','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Label</label><input id="lp-stat1-lbl" class="lpe-input" placeholder="Label" oninput="lpeRT('stat1-lbl','text',this.value)"></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Stat 2</div>
      <div class="lpe-field"><label class="lpe-label">Number/Value</label><input id="lp-stat2-num" class="lpe-input" placeholder="Stat value" oninput="lpeRT('stat2-num','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Label</label><input id="lp-stat2-lbl" class="lpe-input" placeholder="Label" oninput="lpeRT('stat2-lbl','text',this.value)"></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Stat 3</div>
      <div class="lpe-field"><label class="lpe-label">Number/Value</label><input id="lp-stat3-num" class="lpe-input" placeholder="Stat value" oninput="lpeRT('stat3-num','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Label</label><input id="lp-stat3-lbl" class="lpe-input" placeholder="Label" oninput="lpeRT('stat3-lbl','text',this.value)"></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Stat 4</div>
      <div class="lpe-field"><label class="lpe-label">Number/Value</label><input id="lp-stat4-num" class="lpe-input" placeholder="Stat value" oninput="lpeRT('stat4-num','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Label</label><input id="lp-stat4-lbl" class="lpe-input" placeholder="Label" oninput="lpeRT('stat4-lbl','text',this.value)"></div>
    </div>
  </div>
</div>

<div id="lpe-panel-trust" class="lpe-panel">
  <div class="lpe-section">
    <div class="lpe-section-title">🤝 Trust Section</div>
    <div class="lpe-field"><label class="lpe-label">Section heading</label><input id="lp-trust-heading" class="lpe-input" placeholder="ဘာကြောင့် MyanAi ကို ယုံကြည်ကြသလဲ" oninput="lpeRT('trust-heading-text','text',this.value)"></div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 1</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust1-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust1-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust1-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust1-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust1-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust1-desc','text',this.value)"></textarea></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 2</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust2-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust2-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust2-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust2-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust2-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust2-desc','text',this.value)"></textarea></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 3</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust3-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust3-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust3-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust3-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust3-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust3-desc','text',this.value)"></textarea></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 4</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust4-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust4-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust4-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust4-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust4-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust4-desc','text',this.value)"></textarea></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 5</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust5-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust5-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust5-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust5-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust5-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust5-desc','text',this.value)"></textarea></div>
    </div>
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 6</div>
      <div class="lpe-field"><label class="lpe-label">Icon</label><input id="lp-trust6-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust6-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust6-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust6-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust6-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust6-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 7 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 7</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-trust7-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust7-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust7-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust7-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust7-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust7-desc','text',this.value)"></textarea></div>
    </div>

    <!-- Card 8 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 8</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-trust8-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust8-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust8-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust8-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust8-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust8-desc','text',this.value)"></textarea></div>
    </div>
    <!-- Card 9 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 9</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-trust9-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust9-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust9-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust9-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust9-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust9-desc','text',this.value)"></textarea></div>
    </div>
    <!-- Card 10 -->
    <div style="background:var(--surface2);border-radius:8px;padding:.75rem;margin-bottom:.75rem">
      <div style="font-size:.78rem;font-weight:600;margin-bottom:.5rem;color:var(--text-muted)">Card 10</div>
      <div class="lpe-field"><label class="lpe-label">Icon (emoji)</label><input id="lp-trust10-icon" class="lpe-input" placeholder="emoji" oninput="lpeRT('trust10-icon','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Title</label><input id="lp-trust10-title" class="lpe-input" placeholder="Title" oninput="lpeRT('trust10-title','text',this.value)"></div>
      <div class="lpe-field"><label class="lpe-label">Description</label><textarea id="lp-trust10-desc" class="lpe-input" rows="2" style="height:auto" oninput="lpeRT('trust10-desc','text',this.value)"></textarea></div>
    </div>
  </div>
</div>
</div>
  </div>

<div id="page-demo" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">🎭 Demo Control</div>
    </div>
    <div style="display:flex;gap:.5rem">
      <a href="/tenant.php" target="_blank" class="btn btn-ghost btn-sm">🔗 Open Demo Site</a>
      <button class="btn btn-danger btn-sm" onclick="resetDemoData()">🔄 Reset Data</button>
    </div>
  </div>
  <div class="content" style="padding:1.25rem;max-width:900px">

    <!-- Row 1: Status + Credentials -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

      <!-- Demo Status -->
      <div class="table-wrap" style="padding:1.2rem">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">📊 Demo Tenant Status</div>
        <div id="demo-tenant-info" style="color:var(--muted);font-size:.85rem">Loading...</div>
        <div id="demo-stats" style="margin-top:.75rem;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;text-align:center"></div>
      </div>

      <!-- Credentials -->
      <div class="table-wrap" style="padding:1.2rem">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">🔑 Demo Credentials</div>
        <div style="font-size:.85rem;line-height:2.2">
          <div style="display:flex;align-items:center;justify-content:space-between">
            <span style="color:var(--muted)">Email</span>
            <code id="demo-email-val" style="background:var(--warm);padding:2px 8px;border-radius:5px;font-size:.8rem">demo@myanai.net</code>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between">
            <span style="color:var(--muted)">Password</span>
            <code id="demo-pass-val" style="background:var(--warm);padding:2px 8px;border-radius:5px;font-size:.8rem">demo1234</code>
          </div>
        </div>
        <div style="margin-top:.75rem;padding:.5rem .75rem;background:#F0FDF4;border-radius:6px;font-size:.75rem;color:#065F46">
          ✅ Landing page မှ "Try Demo" → auto-login ဖြစ်မည်
        </div>
      </div>
    </div>

    <!-- Row 2: Change Password + Sample Data -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

      <!-- Change Demo Password -->
      <div class="table-wrap" style="padding:1.2rem">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">🔐 Change Demo Password</div>
        <div style="display:flex;flex-direction:column;gap:.5rem">
          <input id="demo-new-pass" type="password" placeholder="New password (min 6 chars)"
            style="padding:.42rem .65rem;border:1px solid var(--border);border-radius:7px;background:var(--warm);color:var(--ink);font-size:.85rem;width:100%;box-sizing:border-box">
          <input id="demo-confirm-pass" type="password" placeholder="Confirm password"
            style="padding:.42rem .65rem;border:1px solid var(--border);border-radius:7px;background:var(--warm);color:var(--ink);font-size:.85rem;width:100%;box-sizing:border-box">
          <button onclick="changeDemoPassword()" class="btn btn-primary btn-sm" style="width:100%">💾 Update Password</button>
        </div>
      </div>

      <!-- Sample Data Controls -->
      <div class="table-wrap" style="padding:1.2rem">
        <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">🗂 Sample Data</div>
        <div style="display:flex;flex-direction:column;gap:.5rem">
          <button onclick="injectSampleData('orders')" class="btn btn-ghost btn-sm" style="text-align:left">
            📦 Inject Sample Orders (10)
          </button>
          <button onclick="injectSampleData('products')" class="btn btn-ghost btn-sm" style="text-align:left">
            🛍 Inject Sample Products (5)
          </button>
          <button onclick="injectSampleData('customers')" class="btn btn-ghost btn-sm" style="text-align:left">
            👥 Inject Sample Customers (10)
          </button>
          <button onclick="resetDemoData()" class="btn btn-sm" style="text-align:left;background:#FEF2F2;color:#DC2626;border:1px solid #FCA5A5">
            🗑 Clear All Demo Data
          </button>
        </div>
      </div>
    </div>

    <!-- Row 3: Demo Access Log -->
    <div class="table-wrap" style="padding:1.2rem">
      <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">📈 Demo Activity</div>
      <div id="demo-activity" style="font-size:.83rem;color:var(--muted);text-align:center;padding:1rem">Loading activity...</div>
    </div>

  </div>

<!-- ═══ LOG VIEWER ═══ -->
<div id="page-logs" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">📋 Error Logs</div>
    </div>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost btn-sm" onclick="loadLogs()">🔄 Refresh</button>
      <button class="btn btn-sm" onclick="clearLogs()" style="background:#dc2626;color:#fff">🗑 Clear</button>
    </div>
  </div>
  <div class="content">
    <!-- Stats row -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.2rem">
      <div class="stat-card"><div class="stat-val" id="log-total">—</div><div class="stat-lbl">Total Entries</div></div>
      <div class="stat-card" style="border-color:rgba(220,38,38,.3)"><div class="stat-val" id="log-errors" style="color:#dc2626">—</div><div class="stat-lbl">Errors</div></div>
      <div class="stat-card" style="border-color:rgba(217,119,6,.3)"><div class="stat-val" id="log-warnings" style="color:#d97706">—</div><div class="stat-lbl">Warnings</div></div>
      <div class="stat-card"><div class="stat-val" id="log-size">—</div><div class="stat-lbl">Log Size</div></div>
    </div>

    <!-- Filter -->
    <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
      <select id="log-level-filter" onchange="loadLogs()" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
        <option value="">All Levels</option>
        <option value="error">❌ Errors</option>
        <option value="warning">⚠️ Warnings</option>
        <option value="info">ℹ️ Info</option>
      </select>
      <input type="text" id="log-search" placeholder="Search logs..." oninput="filterLogsLocal()" style="flex:1;min-width:200px;padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
    </div>

    <!-- Log entries -->
    <div class="table-wrap">
      <div id="log-entries" style="font-family:monospace;font-size:.78rem;background:var(--warm)">
        <div style="text-align:center;padding:2rem;color:var(--muted)">Loading...</div>
      </div>
    </div>
  </div>
</div>

</div>

<!-- ═══ ANNOUNCEMENTS ═══ -->
<div id="page-announce" style="display:none">
  <div class="page-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <button class="hamburger" onclick="openSidebar()">☰</button>
      <div class="page-title">📣 Announcements</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="saveAnnouncement()">💾 Save</button>
  </div>
  <div class="content">
    <div class="table-wrap" style="padding:1.2rem;max-width:600px">
      <div class="form-row" style="display:grid;gap:.75rem">
        <div class="field"><label>Message (shown to all tenants)</label>
          <textarea id="ann-message" rows="3" style="width:100%;padding:.5rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink);font-family:inherit;font-size:.85rem" placeholder="System maintenance on June 20..."></textarea>
        </div>
        <div class="field"><label>Type</label>
          <select id="ann-type" style="padding:.4rem .7rem;border:0.5px solid var(--border);border-radius:8px;background:var(--warm);color:var(--ink)">
            <option value="info">ℹ️ Info</option>
            <option value="warning">⚠️ Warning</option>
            <option value="success">✅ Success</option>
          </select>
        </div>
        <div class="field" style="display:flex;gap:.5rem;align-items:center">
          <input type="checkbox" id="ann-active">
          <label for="ann-active" style="cursor:pointer">Show to all tenants</label>
        </div>
      </div>
    </div>
  </div>
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

/* ── Theme Toggle: Warm Sand (light) / Midnight Black (dark) ── */
(function initTheme(){
  const saved = localStorage.getItem('myanai_theme');
  const prefersDark = window.matchMedia('(prefers-color-scheme:dark)').matches;
  const theme = saved || (prefersDark ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', theme);
  const btn = document.getElementById('theme-toggle-btn');
  if(btn) btn.textContent = theme==='dark' ? '🌙' : '☀️';
})();

function toggleTheme(){
  const cur = document.documentElement.getAttribute('data-theme') || 'light';
  const next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('myanai_theme', next);
  const btn = document.getElementById('theme-toggle-btn');
  if(btn) btn.textContent = next === 'dark' ? '🌙' : '☀️';
}
// ══ ADMIN HEALTH ══
async function loadAdminHealth() {
  try {
    const r = await fetch('/health.php', {credentials:'include'});
    const d = await r.json();
    const set = (id, val) => { const el=document.getElementById(id); if(el) el.textContent=val; };
    const setColor = (id, val, color) => { const el=document.getElementById(id); if(el){el.textContent=val;el.style.color=color;} };

    setColor('h-status', d.status==='ok'?'✅':'⚠️', d.status==='ok'?'#059669':'#d97706');
    setColor('h-db', d.checks?.db==='ok'?'✅':'❌', d.checks?.db==='ok'?'#059669':'#dc2626');
    set('h-disk', (d.checks?.disk_free_gb||0) + ' GB');
    setColor('h-errors',
      d.checks?.errors_last_1h||0,
      (d.checks?.errors_last_1h||0) > 5 ? '#dc2626' : '#059669'
    );
  } catch(e) {
    const el = document.getElementById('h-status');
    if(el) el.textContent = '❌';
  }
}

// Auto-load health on dashboard open
const _origShowPage = window.showPage;
if (_origShowPage && typeof _origShowPage === 'function') {
  window._healthLoaded = false;
}

// ══ LOG VIEWER ══
let _allLogEntries = [];

async function loadLogs() {
  const level = document.getElementById('log-level-filter')?.value || '';

  // Load stats
  const s = await fetch(`log_api.php?action=stats`, {credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if (s.ok) {
    const sv = (id,v) => { const el=document.getElementById(id); if(el) el.textContent=v; };
    sv('log-total', s.total);
    sv('log-errors', s.errors);
    sv('log-warnings', s.warnings);
    sv('log-size', s.size_kb + ' KB');
  }

  // Load entries
  const d = await fetch(`log_api.php?action=list&limit=100&level=${level}`, {credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const container = document.getElementById('log-entries');
  if (!container) return;

  if (!d.ok || !d.entries?.length) {
    container.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--muted)">No log entries</div>';
    _allLogEntries = [];
    return;
  }

  _allLogEntries = d.entries;
  renderLogEntries(d.entries);
}

function renderLogEntries(entries) {
  const container = document.getElementById('log-entries');
  if (!container) return;
  const colors = { error:'#dc2626', warning:'#d97706', info:'#2563eb' };
  const icons  = { error:'❌', warning:'⚠️', info:'ℹ️' };
  container.innerHTML = entries.map(e => `
    <div style="padding:.5rem .75rem;border-bottom:0.5px solid var(--border);display:flex;gap:.75rem;align-items:flex-start">
      <span style="flex-shrink:0;color:${colors[e.level]||'#888'};font-size:.85rem">${icons[e.level]||'•'}</span>
      <span style="color:var(--muted);font-size:.72rem;flex-shrink:0;white-space:nowrap">${e.timestamp||''}</span>
      <span style="color:var(--ink);word-break:break-word">${escH(e.message)}</span>
    </div>
  `).join('') || '<div style="text-align:center;padding:2rem;color:var(--muted)">No entries</div>';
}

function filterLogsLocal() {
  const q = document.getElementById('log-search')?.value?.toLowerCase() || '';
  if (!q) { renderLogEntries(_allLogEntries); return; }
  renderLogEntries(_allLogEntries.filter(e => e.message.toLowerCase().includes(q)));
}

async function clearLogs() {
  if (!confirm('Clear all error logs?')) return;
  await fetch('log_api.php?action=clear', {method:'POST', credentials:'include'});
  loadLogs();
}

// Load logs when page opens
document.addEventListener('DOMContentLoaded', () => {
  const orig = window.showPage;
  if (orig) {
    window.showPage = function(p) {
      orig(p);
      if (p === 'logs') setTimeout(loadLogs, 100);
      if (p === 'dashboard') { setTimeout(loadAdminHealth, 200); setTimeout(loadGrowthSummary, 250); }
    };
  }
  // Load health on initial page load
  setTimeout(loadAdminHealth, 500);
  setTimeout(loadGrowthSummary, 550);
  setTimeout(load2FAStatus, 600);
});


// ══ 2FA MANAGEMENT ══
async function load2FAStatus() {
  const r = await fetch('two_factor.php?action=status', {credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const el = document.getElementById('2fa-status-widget');
  if (!el) return;
  if (r.ok) {
    el.innerHTML = r.enabled
      ? '<span style="color:var(--green);font-weight:600">✅ Enabled</span> <button onclick="disable2FA()" style="font-size:.72rem;padding:2px 8px;border-radius:6px;border:1px solid var(--border);cursor:pointer;margin-left:.5rem;color:#dc2626">Disable</button>'
      : '<span style="color:var(--text-muted)">Not enabled</span> <button onclick="setup2FA()" style="font-size:.72rem;padding:2px 8px;border-radius:6px;background:var(--accent);color:#fff;border:none;cursor:pointer;margin-left:.5rem">Enable 2FA</button>';
  }
}

async function setup2FA() {
  const r = await fetch('two_factor.php?action=setup', {credentials:'include'}).then(r=>r.json());
  if (!r.ok) { alert(r.msg); return; }

  const modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center';
  modal.innerHTML = `
    <div style="background:var(--card);border-radius:16px;padding:2rem;max-width:400px;width:90%;text-align:center;position:relative">
      <button onclick="this.closest('div[style*=rgba]').remove()" style="position:absolute;top:.75rem;right:.75rem;width:28px;height:28px;border-radius:50%;border:1px solid var(--border);background:var(--warm);cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;line-height:1">×</button>
      <h3 style="margin:0 0 1rem;font-size:1.1rem">🔐 Enable 2FA</h3>
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">Scan this QR code with Google Authenticator or Authy:</p>
      <img src="${r.qr_url}" style="width:200px;height:200px;border:4px solid var(--border);border-radius:8px">
      <p style="font-size:.75rem;color:var(--text-muted);margin:.75rem 0">Or enter manually: <strong>${r.secret}</strong></p>
      <input id="otp-verify-input" type="text" placeholder="Enter 6-digit code" maxlength="6"
        style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:8px;text-align:center;font-size:1.2rem;letter-spacing:.3rem;margin:.5rem 0">
      <div style="display:flex;gap:.5rem;margin-top:.75rem">
        <button onclick="this.closest('div[style]').remove()" style="flex:1;padding:.6rem;border:1px solid var(--border);border-radius:8px;background:var(--warm);cursor:pointer">Cancel</button>
        <button onclick="verify2FASetup('${r.secret}')" style="flex:1;padding:.6rem;border:none;border-radius:8px;background:var(--accent);color:#fff;cursor:pointer;font-weight:600">Verify & Enable</button>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

async function verify2FASetup(secret) {
  const code = document.getElementById('otp-verify-input')?.value;
  if (!code || code.length !== 6) { alert('Enter 6-digit code'); return; }
  const r = await fetch('two_factor.php?action=enable', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({code})
  }).then(r=>r.json());
  alert(r.msg);
  if (r.ok) {
    document.querySelector('div[style*="rgba(0,0,0,.6)"]')?.remove();
    load2FAStatus();
  }
}

async function disable2FA() {
  if (!confirm('Disable 2FA? This reduces your account security.')) return;
  const r = await fetch('two_factor.php?action=disable', {
    method:'POST', credentials:'include'
  }).then(r=>r.json());
  alert(r.msg);
  load2FAStatus();
}

</script>
<!-- ── UPGRADE PAGE ── -->

  </div><!-- end div.main -->
</div><!-- end #app -->
<script>if(sessionStorage.getItem('sb')){var b=document.getElementById('sec-banner');if(b)b.style.display='none';}</script>
</body>
</html>