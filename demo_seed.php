<?php
/**
 * demo_seed.php — Copy tenant_id=1 data to tenant_id=9 (Demo Restaurant)
 * Run once: https://myanai.duckdns.org/demo_seed.php?key=myanai_seed_2026
 */
require_once __DIR__ . '/db_connect.php';
$pdo = getPDO();

$key = $_GET['key'] ?? '';
$cliMode = php_sapi_name() === 'cli';
if (!$cliMode && $key !== 'myanai_seed_2026') {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

$FROM = 1;  // NoodleHaus Main (source)
$TO   = 9;  // Demo Restaurant (destination)
$log  = [];

try {
    /* ── 1. Rename Demo Restaurant ── */
    $pdo->prepare("UPDATE tenants SET name='MyanAi Demo', slug='demo', plan='enterprise', is_active=1 WHERE id=?")
        ->execute([$TO]);
    $log[] = "✅ Renamed tenant $TO to 'MyanAi Demo'";

    /* ── 2. Create demo branch (if not exists) ── */
    $existBranch = $pdo->prepare("SELECT id FROM branches WHERE tenant_id=? LIMIT 1");
    $existBranch->execute([$TO]);
    $demoBranch = $existBranch->fetchColumn();

    if (!$demoBranch) {
        $pdo->prepare("INSERT INTO branches (tenant_id,name,code,address,phone,is_active) VALUES (?,?,?,?,?,1)")
            ->execute([$TO,'Demo Main Branch','DEMO','Yangon, Myanmar','09-000-0000']);
        $demoBranch = $pdo->lastInsertId();
        $log[] = "✅ Created demo branch id=$demoBranch";
    } else {
        $pdo->prepare("UPDATE branches SET name='Demo Main Branch',code='DEMO',is_active=1 WHERE id=?")
            ->execute([$demoBranch]);
        $log[] = "✅ Updated demo branch id=$demoBranch";
    }

    /* ── 3. Clear old demo menu items ── */
    $pdo->prepare("DELETE FROM menu_items WHERE tenant_id=?")->execute([$TO]);
    $log[] = "✅ Cleared old demo menu items";

    /* ── 4. Copy menu items from tenant 1 ── */
    $items = $pdo->prepare("SELECT * FROM menu_items WHERE tenant_id=? ORDER BY category,sort_order,name");
    $items->execute([$FROM]);
    $allItems = $items->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("INSERT INTO menu_items
        (tenant_id,branch_id,name,description,price,category,emoji,image_path,is_active,sort_order,stock_qty)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($allItems as $item) {
        $ins->execute([
            $TO, $demoBranch,
            $item['name'],
            $item['description'] ?? '',
            $item['price'],
            $item['category'] ?? 'Main',
            $item['emoji'] ?? '🍽',
            '',
            1,
            $item['sort_order'] ?? 0,
            100,
        ]);
    }
    $log[] = "✅ Copied ".count($allItems)." menu items";

    /* ── 5. Copy staff ── */
    $pdo->prepare("DELETE FROM staff WHERE branch_id=?")->execute([$demoBranch]);
    $staffRows = $pdo->prepare("SELECT * FROM staff s JOIN branches b ON b.id=s.branch_id WHERE b.tenant_id=? LIMIT 10");
    $staffRows->execute([$FROM]);
    $allStaff = $staffRows->fetchAll(PDO::FETCH_ASSOC);

    $insStaff = $pdo->prepare("INSERT INTO staff (branch_id,name,role,pin,notes,is_active) VALUES (?,?,?,?,?,1)");
    $demoStaff = [
        ['Demo Manager','manager','1234','Demo account manager'],
        ['Demo Cashier','cashier','2222','Demo cashier'],
        ['Demo Waiter 1','waiter','3333','Demo waiter'],
        ['Demo Waiter 2','waiter','4444','Demo kitchen staff'],
    ];
    foreach ($demoStaff as $s) {
        $insStaff->execute([$demoBranch, $s[0], $s[1], $s[2], $s[3]]);
    }
    $log[] = "✅ Created ".count($demoStaff)." demo staff";

    /* ── 6. Copy tables ── */
    $pdo->prepare("DELETE FROM restaurant_tables WHERE tenant_id=?")->execute([$TO]);
    $tableData = [
        ['T1','Table 1',4],['T2','Table 2',4],['T3','Table 3',2],
        ['T4','Table 4',6],['T5','Table 5',4],['T6','Table 6',2],
        ['T7','VIP Room',8],['T8','Outdoor',4],
    ];
    $insTable = $pdo->prepare("INSERT INTO restaurant_tables (tenant_id,branch_id,table_code,table_label,seats,status) VALUES (?,?,?,?,?,'available')");
    foreach ($tableData as $t) {
        $insTable->execute([$TO,$demoBranch,$t[0],$t[1],$t[2]]);
    }
    $log[] = "✅ Created ".count($tableData)." tables";

    /* ── 7. Create demo orders (recent) ── */
    // Get first 3 menu item IDs for demo
    $demoItems = $pdo->prepare("SELECT id,name,price FROM menu_items WHERE tenant_id=? LIMIT 6");
    $demoItems->execute([$TO]);
    $demoItemList = $demoItems->fetchAll(PDO::FETCH_ASSOC);

    if (count($demoItemList) >= 2) {
        $demoOrders = [
            ['pending',  'T1', [0,1]],
            ['preparing','T3', [1,2]],
            ['ready',    'T5', [0,3]],
            ['completed','T2', [2,4]],
            ['completed','T4', [1,5]],
        ];

        $insOrder = $pdo->prepare("INSERT INTO orders
            (tenant_id,branch_id,table_id,customer_name,customer_phone,subtotal,delivery_fee,total_amount,payment_method,order_type,status,created_at)
            VALUES (?,?,?,?,?,?,0,?,?,?,?,NOW() - INTERVAL ? MINUTE)");
        $insItem = $pdo->prepare("INSERT INTO order_items (order_id,menu_item_id,item_name,qty,unit_price,subtotal) VALUES (?,?,?,?,?,?)");

        foreach ($demoOrders as $i => [$status,$table,$itemIdxs]) {
            $tableRow = $pdo->prepare("SELECT id FROM restaurant_tables WHERE tenant_id=? AND table_code=? LIMIT 1");
            $tableRow->execute([$TO,$table]);
            $tableId = $tableRow->fetchColumn() ?: null;

            $subtotal = array_sum(array_map(fn($idx)=>($demoItemList[$idx%count($demoItemList)]['price']??0),$itemIdxs));
            $insOrder->execute([$TO,$demoBranch,$tableId,'Demo Customer','09000000000',$subtotal,$subtotal,'kpay','dine_in',$status,($i*8)]);
            $orderId = $pdo->lastInsertId();

            foreach ($itemIdxs as $idx) {
                $it = $demoItemList[$idx%count($demoItemList)];
                $insItem->execute([$orderId,$it['id'],$it['name'],1,$it['price'],$it['price']]);
            }
        }
        $log[] = "✅ Created ".count($demoOrders)." demo orders";
    }

    /* ── 8. Update tenant settings (owner email/pass for demo login) ── */
    $existSettings = $pdo->prepare("SELECT settings FROM tenants WHERE id=?");
    $existSettings->execute([$TO]);
    $settings = json_decode($existSettings->fetchColumn()||'{}', true) ?: [];
    $settings['admin_pass_hash'] = password_hash('demo1234', PASSWORD_BCRYPT);
    $settings['store_name'] = 'MyanAi Demo';
    $pdo->prepare("UPDATE tenants SET settings=?, owner_email='demo@myanai.net' WHERE id=?")
        ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE), $TO]);
    $log[] = "✅ Updated demo login: demo@myanai.net / demo1234";

    echo json_encode(['ok'=>true,'log'=>$log]);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'log'=>$log]);
}
