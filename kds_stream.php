<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';
// KDS: no session (SSE session locking ဖြစ်မည်) - GET param only
$tenantId  = max(1, (int)($_GET['tenant_id'] ?? 1));
$branchId     = (int)($_GET['branch_id'] ?? 0);
$branchFilter = $branchId > 0 ? "AND kq.branch_id = " . (int)$branchId : ''; // ★ cast prevents SQL injection
$pdo = getPDO();


set_time_limit(0);
ignore_user_abort(false);
ini_set('output_buffering',        'off');
ini_set('zlib.output_compression', 'off');
ini_set('implicit_flush',          '1');
while (ob_get_level() > 0) ob_end_clean();


define('DB_CHARSET', 'utf8mb4');

header('Content-Type: text/event-stream; charset=utf-8');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');  // disable gzip for SSE
ini_set('zlib.output_compression', 'Off');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

/* ── DB connect ─────────────────────────────────────────────────────────────── */


/* ── Station filter (Phase 1C) ──────────────────────────────────────────────
 * ?station=kitchen  → kitchen station သာ ပြမည်
 * ?station=drinks   → drinks station သာ ပြမည်
 * ?station=all      → အကုန် ပြမည် (default)
 * station param မပါ → အကုန် ပြမည် (backward compatible)
 */
$stationParam = trim($_GET['station'] ?? 'all');
$validStations = ['kitchen', 'drinks', 'counter', 'bar', 'grill', 'all'];
if (!in_array($stationParam, $validStations, true)) $stationParam = 'all';
$stationFilter = ($stationParam !== 'all')
    ? "AND kq.station = '" . addslashes($stationParam) . "'"
    : "";

/*
 * Strategy: SQL ကို original နဲ့ အတူတူ simple ထားမည်။
 * Modifier fetch ကို PHP side မှာ သပ်သပ် လုပ်မည် —
 * MySQL version compatibility ပြဿနာ မဖြစ်အောင်။
 *
 * Flow:
 *   1. kds_queue rows fetch (original query + oi_id ထပ်ထည့်)
 *   2. ထို rows အတွက် order_item ids စုပြီး
 *      modifier ကို batch query တစ်ကြိမ်တည်း fetch
 *   3. buildOrder() မှာ merge လုပ်မည်
 */

/* ── Main KDS query (original structure, oi_id ထပ်ထည့်) ─────────────────── */
$ACTIVE_SQL = "
    SELECT
        kq.id          AS kds_id,
        kq.order_id,
        kq.status,
        kq.station,
        kq.pushed_at,
        o.customer_name,
        o.special_notes,
        o.order_type,
        o.table_id,
        GROUP_CONCAT(
            JSON_OBJECT('qty', oi.qty, 'name', oi.item_name, 'oi_id', oi.id)
            ORDER BY oi.id SEPARATOR '|||'
        ) AS items_raw
    FROM kds_queue   kq
    JOIN orders      o  ON o.id = kq.order_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.status IN ('pending','preparing','ready') AND kq.tenant_id = {$tenantId} AND o.deleted_at IS NULL
    {$branchFilter}
    {$stationFilter}
    GROUP BY kq.id
    ORDER BY kq.pushed_at ASC
";

$NEW_SQL = "
    SELECT
        kq.id          AS kds_id,
        kq.order_id,
        kq.status,
        kq.station,
        kq.pushed_at,
        o.customer_name,
        o.special_notes,
        o.order_type,
        o.table_id,
        GROUP_CONCAT(
            JSON_OBJECT('qty', oi.qty, 'name', oi.item_name, 'oi_id', oi.id)
            ORDER BY oi.id SEPARATOR '|||'
        ) AS items_raw
    FROM kds_queue   kq
    JOIN orders      o  ON o.id = kq.order_id
    JOIN order_items oi ON oi.order_id = o.id
    WHERE kq.id > :last_id AND kq.tenant_id = :tid AND o.deleted_at IS NULL
    {$branchFilter}
    {$stationFilter}
    GROUP BY kq.id
    ORDER BY kq.id ASC
";

/* ── Modifier batch query (IN clause, PHP side merge) ───────────────────── */
// order_item id list ပေးရင် သက်ဆိုင်တဲ့ modifier အကုန် ပြန်ပေးမည်
$MOD_SQL = "
    SELECT
        oim.order_item_id,
        oim.label        AS mod_name,
        oim.price_add
    FROM order_item_modifiers oim
    WHERE oim.order_item_id IN (%s)
    ORDER BY oim.order_item_id, oim.id
";

/* ── Step 1: active orders ──────────────────────────────────────────────── */
$active = $pdo->query($ACTIVE_SQL)->fetchAll();
sseOut('meta', ['station' => $stationParam]);
sseOut('init', array_values(buildOrderBatch($pdo, $active, $MOD_SQL)));

/* ── Step 2: lastId baseline ────────────────────────────────────────────── */
$lastId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM kds_queue WHERE tenant_id=$tenantId")->fetchColumn();

/* ── Step 3: polling loop ───────────────────────────────────────────────── */
$newStmt = $pdo->prepare($NEW_SQL);
$pingAt  = time();

while (true) {
    if (connection_aborted()) exit;

    try {
        $newStmt->execute([':last_id' => $lastId, ':tid' => $tenantId]);
        $rows = $newStmt->fetchAll();

        if (!empty($rows)) {
            $built = buildOrderBatch($pdo, $rows, $MOD_SQL);
            foreach ($rows as $row) {
                $kdsId = (int)$row['kds_id'];
                if (in_array($row['status'], ['pending','preparing','ready'])
                    && isset($built[$kdsId])) {
                    sseOut('new_order', $built[$kdsId]);
                }
                $lastId = max($lastId, $kdsId);
            }
        }
    } catch (PDOException $e) {
        sleep(2);
        continue;
    }

    if (time() - $pingAt >= 15) {
        echo ": ping\n\n";
        flush();
        $pingAt = time();
    }

    sleep(1);
}

/* ─── helpers ───────────────────────────────────────────────────────────── */

function sseOut(string $event, mixed $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/**
 * Batch: rows တွေအတွက် modifier ကို တစ်ကြိမ်တည်း fetch ပြီး merge လုပ်မည်။
 * Return: [ kds_id => orderArray ]
 */
function buildOrderBatch(PDO $pdo, array $rows, string $modSql): array
{
    if (empty($rows)) return [];

    /* ── oi_id စုဆောင်း ──────────────────────────────────────────────────── */
    $oiIds = [];
    $parsed = [];  // kds_id => [items array (no modifiers yet)]

    foreach ($rows as $row) {
        $items = [];
        foreach (explode('|||', $row['items_raw'] ?? '') as $j) {
            $obj = json_decode($j, true);
            if (!$obj) continue;
            $oiIds[] = (int)$obj['oi_id'];
            $items[] = [
                'qty'       => (int)$obj['qty'],
                'name'      => $obj['name'],
                'oi_id'     => (int)$obj['oi_id'],
                'modifiers' => [],   // fill below
            ];
        }
        $parsed[(int)$row['kds_id']] = [
            'row'   => $row,
            'items' => $items,
        ];
    }

    /* ── Modifier batch fetch ────────────────────────────────────────────── */
    $modMap = [];   // oi_id => [ {name, price_delta}, ... ]
    if (!empty($oiIds)) {
        $unique = array_values(array_unique($oiIds));
        $ph     = implode(',', array_fill(0, count($unique), '?'));
        $stmt   = $pdo->prepare(sprintf($modSql, $ph));
        $stmt->execute($unique);
        foreach ($stmt->fetchAll() as $m) {
            $modMap[(int)$m['order_item_id']][] = [
                'name'        => $m['mod_name'],
                'price_delta' => (int)$m['price_add'],
            ];
        }
    }

    /* ── Merge + build final array ──────────────────────────────────────── */
    $result = [];
    foreach ($parsed as $kdsId => $p) {
        $row   = $p['row'];
        $items = $p['items'];

        foreach ($items as &$item) {
            $item['modifiers'] = $modMap[$item['oi_id']] ?? [];
        }
        unset($item);

        $result[$kdsId] = [
            'kds_id'     => $kdsId,
            'order_id'   => (int)$row['order_id'],
            'order_ref'  => 'NH-' . str_pad((string)$row['order_id'], 6, '0', STR_PAD_LEFT),
            'status'     => $row['status'],
            'station'    => $row['station']    ?? 'kitchen',
            'order_type' => $row['order_type'] ?? 'delivery',
            'table_id'   => $row['table_id']   ?? '',
            'customer'   => $row['customer_name'],
            'notes'      => $row['special_notes'] ?? '',
            'items'      => $items,
            'time'       => $row['pushed_at'],
        ];
    }

    return $result;
}