<?php
/**
 * MyanAi Function Testing Script
 * Tests core functionality of admin.php and tenant.php
 */

require_once 'db_connect.php';
$pdo = getPDO();

$results = [];

// Test 1: Database connection
try {
    $test = $pdo->query("SELECT 1")->fetchColumn();
    $results['DB Connection'] = $test == 1 ? '✓ PASS' : '✗ FAIL';
} catch (Exception $e) {
    $results['DB Connection'] = '✗ FAIL: ' . $e->getMessage();
}

// Test 2: Check tenants table
try {
    $count = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $results['Tenants Count'] = $count . ' tenants found (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Tenants Count'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 3: Check orders table
try {
    $count = $pdo->query("SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL")->fetchColumn();
    $results['Orders Count'] = $count . ' active orders (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Orders Count'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 4: Check staff
try {
    $count = $pdo->query("SELECT COUNT(*) FROM staff WHERE is_active = 1")->fetchColumn();
    $results['Active Staff'] = $count . ' staff members (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Active Staff'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 5: Check menu items
try {
    $count = $pdo->query("SELECT COUNT(*) FROM menu_items WHERE is_active = 1")->fetchColumn();
    $results['Menu Items'] = $count . ' active items (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Menu Items'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 6: Check modifiers
try {
    $count = $pdo->query("SELECT COUNT(*) FROM modifier_groups")->fetchColumn();
    $results['Modifiers'] = $count . ' modifier groups (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Modifiers'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 7: Check loyalty
try {
    $count = $pdo->query("SELECT COUNT(*) FROM loyalty_cards")->fetchColumn();
    $results['Loyalty Cards'] = $count . ' cards (' . ($count > 0 ? '✓' : '⚠') . ')';
} catch (Exception $e) {
    $results['Loyalty Cards'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 8: Check demo tenant
try {
    $demo = $pdo->query("SELECT id, name FROM tenants WHERE owner_email='demo@myanai.net' LIMIT 1")->fetch();
    $results['Demo Tenant'] = $demo ? '✓ Found: ' . $demo['name'] : '✗ Not found';
} catch (Exception $e) {
    $results['Demo Tenant'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 9: Recent orders data integrity
try {
    $stmt = $pdo->query("SELECT id, total_amount, payment_method FROM orders WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
    $orders = $stmt->fetchAll();
    $valid = true;
    foreach ($orders as $o) {
        if (!$o['total_amount'] || !$o['payment_method']) {
            $valid = false;
            break;
        }
    }
    $results['Order Integrity'] = ($valid || count($orders) == 0) ? '✓ PASS' : '✗ Missing data in orders';
} catch (Exception $e) {
    $results['Order Integrity'] = '✗ ERROR: ' . $e->getMessage();
}

// Test 10: Branch-Tenant relationships
try {
    $stmt = $pdo->query("
        SELECT b.id, b.tenant_id, b.name, t.name as tenant_name
        FROM branches b
        LEFT JOIN tenants t ON b.tenant_id = t.id
        LIMIT 3
    ");
    $branches = $stmt->fetchAll();
    $valid = true;
    foreach ($branches as $b) {
        if (!$b['tenant_id'] || !$b['tenant_name']) {
            $valid = false;
            break;
        }
    }
    $results['Branch Relationships'] = ($valid || count($branches) == 0) ? '✓ PASS' : '✗ Orphaned branches found';
} catch (Exception $e) {
    $results['Branch Relationships'] = '✗ ERROR: ' . $e->getMessage();
}

// Output results
echo "=== MyanAi System Health Check ===\n\n";
foreach ($results as $test => $result) {
    echo "$test: $result\n";
}
