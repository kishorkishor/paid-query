<?php
// DIAGNOSTIC VERSION - Shows all debug info in response
error_reporting(E_ALL);
ini_set('display_errors','0');
header('Content-Type: application/json');

define('DB_HOST', 'localhost');
define('DB_NAME', 'u966125597_cosmictrd');
define('DB_USER', 'u966125597_admin');
define('DB_PASS', 'All@h1154');

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function scalar(PDO $pdo, string $sql, array $args = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $val = $stmt->fetchColumn();
        return ($val === false) ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function table_has_col(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = $table . '|' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE ?");
        $s->execute([$col]);
        $cache[$key] = (bool)$s->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

try {
    $pdo = db();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed.', 'debug' => $e->getMessage()]);
    exit;
}

// Collect inputs
$orderId   = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$amountIn  = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
$cartonIds = [];
if (!empty($_POST['carton_ids']) && is_array($_POST['carton_ids'])) {
    foreach ($_POST['carton_ids'] as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) $cartonIds[] = $cid;
    }
    $cartonIds = array_values(array_unique($cartonIds));
}

$debug = [
    'received_post' => $_POST,
    'parsed_order_id' => $orderId,
    'parsed_amount' => $amountIn,
    'parsed_carton_ids' => $cartonIds,
];

if ($orderId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing order_id.', 'debug' => $debug]);
    exit;
}

// Check if columns exist
$hasTotalDue = table_has_col($pdo, 'inbound_cartons', 'total_due');
$hasTotalPaid = table_has_col($pdo, 'inbound_cartons', 'total_paid');

$debug['schema'] = [
    'has_total_due' => $hasTotalDue,
    'has_total_paid' => $hasTotalPaid,
];

// Get cartons for this order
$sql = "SELECT c.id, c.carton_code,
               COALESCE(c.bd_total_price, 0) AS bd_total_price,
               COALESCE(c.total_due, 0) AS total_due,
               COALESCE(c.total_paid, 0) AS total_paid,
               c.bd_payment_status
          FROM inbound_cartons c
          JOIN inbound_packing_lists p ON p.id = c.packing_list_id
         WHERE p.order_id = ?";
if ($cartonIds && count($cartonIds)) {
    $sql .= " AND c.id IN (" . implode(',', $cartonIds) . ")";
}
$stmt = $pdo->prepare($sql);
$stmt->execute([$orderId]);
$cartonsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$debug['cartons_in_db'] = $cartonsData;

// Calculate sum of unpaid
$sumUnpaid = 0.0;
foreach ($cartonsData as $c) {
    if ($hasTotalDue) {
        $due = (float)$c['total_due'];
        if ($due > 0) $sumUnpaid += $due;
    }
}

$debug['calculated_sum_unpaid'] = $sumUnpaid;

// Get wallet balance
$stmt = $pdo->prepare("SELECT o.*, q.clerk_user_id FROM orders o LEFT JOIN queries q ON q.id=o.query_id WHERE o.id=? LIMIT 1");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo json_encode(['ok' => false, 'error' => 'Order not found.', 'debug' => $debug]);
    exit;
}

$clerkUserId = $order['clerk_user_id'] ?? null;
$debug['clerk_user_id'] = $clerkUserId;

if (!$clerkUserId) {
    echo json_encode(['ok' => false, 'error' => 'Wallet owner not resolvable.', 'debug' => $debug]);
    exit;
}

$walletId = scalar($pdo, "SELECT id FROM customer_wallets WHERE clerk_user_id=? LIMIT 1", [$clerkUserId], null);
$debug['wallet_id'] = $walletId;

if (!$walletId) {
    echo json_encode(['ok' => false, 'error' => 'Wallet not found.', 'debug' => $debug]);
    exit;
}

$hasBalanceView = (bool)scalar(
    $pdo,
    "SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_balances' LIMIT 1",
    [],
    false
);

if ($hasBalanceView) {
    $walletBalance = (float)scalar($pdo, "SELECT balance FROM wallet_balances WHERE wallet_id=?", [$walletId], 0.0);
} else {
    $walletBalance = (float)scalar(
        $pdo,
        "SELECT
            COALESCE(SUM(CASE WHEN entry_type IN ('topup_verified','manual_credit','adjustment_credit','refund') THEN amount ELSE 0 END),0)
          - COALESCE(SUM(CASE WHEN entry_type IN ('charge_shipping_captured','charge_sourcing_captured') THEN amount ELSE 0 END),0)
         FROM wallet_ledger WHERE wallet_id=?",
        [$walletId],
        0.0
    );
}

$debug['wallet_balance'] = $walletBalance;

// Determine mode and desired charge
$mode = null;
$desiredCharge = 0.0;

if ($amountIn !== null && $cartonIds) {
    $mode = 'amount_cartons';
    $desiredCharge = min(max(0.0, $amountIn), $sumUnpaid);
} elseif ($cartonIds) {
    $mode = 'cartons';
    $desiredCharge = $sumUnpaid;
} elseif ($amountIn !== null) {
    $mode = 'amount_all';
    $desiredCharge = min(max(0.0, $amountIn), $sumUnpaid);
} else {
    $mode = 'all_cartons';
    $desiredCharge = $sumUnpaid;
}

$debug['payment_mode'] = $mode;
$debug['desired_charge'] = $desiredCharge;

if ($desiredCharge <= 0.0) {
    echo json_encode([
        'ok' => false, 
        'error' => 'Nothing to pay. Check the debug info below.', 
        'debug' => $debug
    ]);
    exit;
}

// If we get here, show success with all debug info
echo json_encode([
    'ok' => true,
    'message' => 'Diagnostic successful - payment would proceed with these values',
    'debug' => $debug,
    'would_charge' => $desiredCharge,
    'from_wallet_balance' => $walletBalance
]);