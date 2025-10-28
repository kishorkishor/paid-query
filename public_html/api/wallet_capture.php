<?php
// /api/wallet_capture.php
// Process wallet payments without relying on lib.php.  This handler supports
// three payment modes:
//
// 1) Manual order capture (via POST `amount`): deducts a user‐specified amount
//    from the customer's wallet and applies it to the order's remaining
//    sourcing balance.  The charge is capped by both the wallet balance and
//    the order's remaining due.
// 2) Selected carton capture (via POST `carton_ids[]`): deducts the Bangladesh
//    inbound charges (bd_total_price) for the specified cartons.  Cartons must
//    belong to the order and be pending payment.  The capture fails if the
//    wallet balance is insufficient.
// 3) All unpaid cartons capture (no `amount` and no `carton_ids`): deducts the
//    Bangladesh inbound charges for all unpaid cartons on the order.
//
// On success the endpoint responds with JSON:
//
//     {"ok":true, "wallet_balance":<float>, "order_due":<float>,
//      "payment_id":<int>, "cartons_paid":<int>}
//
// On failure it responds with JSON:
//
//     {"ok":false, "error":"message"}
//
// This script connects to the database directly using PDO via the `db()` helper.
// The helper uses constants defined above (DB_HOST, DB_NAME, DB_USER, DB_PASS)
// rather than environment variables.  Update those constants if your
// database configuration changes.
//
error_reporting(E_ALL);
ini_set('display_errors','0');
header('Content-Type: application/json');

// -----------------------------------------------------------------------------
// Database configuration and connection helper
define('DB_HOST', 'localhost');
define('DB_NAME', 'u966125597_cosmictrd');
define('DB_USER', 'u966125597_admin');
define('DB_PASS', 'All@h1154');

/** @return PDO */
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

function respond_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function respond_ok(array $data): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

/** scalar helper */
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

/** schema helper */
function table_has_col(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = $table . '|' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $s->execute([$col]);
        $cache[$key] = (bool)$s->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Distribute a payment amount across cartons belonging to an order.
 *
 * When partial payment columns (total_paid/total_due) exist, the payment
 * amount is applied to each unpaid carton in ascending ID order until the
 * amount is exhausted.  The function updates total_paid, total_due and
 * bd_payment_status accordingly on inbound_cartons.  If no partial payment
 * columns exist, it simply marks selected cartons as verified.
 *
 * @param PDO $pdo Database handle
 * @param int $orderId The order ID whose cartons to update
 * @param float $amount The amount to distribute
 * @param array|null $cartonIds Optional list of carton IDs to target (apply in order)
 * @return array ['applied_total'=>float, 'per_carton'=>list of ['id'=>int,'applied'=>float,'new_paid'=>float,'new_due'=>float]]
 */
function apply_payment_to_cartons(PDO $pdo, int $orderId, float $amount, ?array $cartonIds = null): array {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return ['applied_total' => 0.0, 'per_carton' => []];

    // Check if partial payment columns exist
    $hasTotalDue  = table_has_col($pdo, 'inbound_cartons', 'total_due');
    $hasTotalPaid = table_has_col($pdo, 'inbound_cartons', 'total_paid');
    $hasVerifiedAt = table_has_col($pdo, 'inbound_cartons', 'bd_payment_verified_at');

    // Build filter for selected cartons
    $filter = '';
    if ($cartonIds && count($cartonIds)) {
        $filter = ' AND c.id IN (' . implode(',', array_map('intval', $cartonIds)) . ')';
    }

    // Expression for remaining due per carton
    $dueExpr = ($hasTotalDue || $hasTotalPaid)
        ? ($hasTotalDue
            ? "COALESCE(c.total_due, COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0))"
            : "(COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0))")
        : "GREATEST(COALESCE(c.bd_total_price,0),0)";

    // Lock rows for update
    $sql = "
        SELECT c.id,
               " . $dueExpr . " AS due,
               COALESCE(c.total_paid,0) AS paid,
               COALESCE(c.bd_total_price,0) AS price
          FROM inbound_cartons c
          JOIN inbound_packing_lists p ON p.id = c.packing_list_id
         WHERE p.order_id = ?
           AND " . $dueExpr . " > 0
           " . $filter . "
         ORDER BY c.id ASC
         FOR UPDATE
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['applied_total' => 0.0, 'per_carton' => []];
    }

    // Build update SQL to add to total_paid/total_due and set statuses
    $updateSql = "UPDATE inbound_cartons SET
            total_paid = ROUND(COALESCE(total_paid,0) + :apply, 2),
            total_due  = ROUND(GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0), 2),
            bd_payment_status = CASE
              WHEN GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0) = 0 THEN 'verified'
              WHEN (COALESCE(total_paid,0) + :apply) > 0 THEN 'partial'
              ELSE COALESCE(bd_payment_status,'pending')
            END";
    if ($hasVerifiedAt) {
        $updateSql .= ",\n            bd_payment_verified_at = CASE\n              WHEN GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0) = 0 THEN NOW()\n              ELSE bd_payment_verified_at\n            END";
    }
    $updateSql .= "\n          WHERE id = :id\n          LIMIT 1";
    $updStmt = $pdo->prepare($updateSql);

    $remaining = $amount;
    $appliedTotal = 0.0;
    $perCarton = [];
    foreach ($rows as $r) {
        if ($remaining <= 0) break;
        $due = round((float)$r['due'], 2);
        if ($due <= 0) continue;
        $apply = min($due, $remaining);
        $updStmt->execute([':apply' => $apply, ':id' => (int)$r['id']]);
        $appliedTotal += $apply;
        $remaining = round($remaining - $apply, 2);
        $newPaid = round((float)$r['paid'] + $apply, 2);
        $newDue  = round((float)$r['price'] - $newPaid, 2);
        if ($newDue < 0) $newDue = 0.0;
        $perCarton[] = ['id' => (int)$r['id'], 'applied' => $apply, 'new_paid' => $newPaid, 'new_due' => $newDue];
    }

    // If no partial columns exist, mark selected cartons as verified
    if (!$hasTotalDue && !$hasTotalPaid) {
        // Mark bd_payment_status = 'verified' on targeted cartons
        $where = '';
        if ($cartonIds && count($cartonIds)) {
            $where = ' AND c.id IN (' . implode(',', array_map('intval', $cartonIds)) . ')';
        }
        $sqlUpdate = "
          UPDATE inbound_cartons c
          JOIN inbound_packing_lists p ON p.id = c.packing_list_id
             SET c.bd_payment_status='verified'";
        if ($hasVerifiedAt) {
            $sqlUpdate .= ", c.bd_payment_verified_at=NOW()";
        }
        $sqlUpdate .= "
           WHERE p.order_id=?
             AND COALESCE(c.bd_payment_status,'pending')='pending'";
        $sqlUpdate .= $where;
        $pdo->prepare($sqlUpdate)->execute([$orderId]);
        // Build perCarton list based on price for legacy
        foreach ($rows as $r) {
            $perCarton[] = ['id' => (int)$r['id'], 'applied' => (float)$r['price'], 'new_paid' => (float)$r['price'], 'new_due' => 0.0];
            $appliedTotal += (float)$r['price'];
        }
    }

    return ['applied_total' => round($appliedTotal, 2), 'per_carton' => $perCarton];
}

try {
    $pdo = db();
} catch (Throwable $e) {
    respond_error('Database connection failed.', 500);
}

// Inputs
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

if ($orderId <= 0) respond_error('Missing order_id.');

// Order + query (for wallet owner)
$stmt = $pdo->prepare("
    SELECT o.*, q.clerk_user_id, q.id AS query_id
      FROM orders o
 LEFT JOIN queries q ON q.id = o.query_id
     WHERE o.id = ?
     LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) respond_error('Order not found.', 404);

$clerkUserId = $order['clerk_user_id'] ?? null;
if (!$clerkUserId) respond_error('Wallet owner not resolvable for this order.', 403);

// Wallet lookup / create
$walletId = scalar($pdo, "SELECT id FROM customer_wallets WHERE clerk_user_id=? LIMIT 1", [$clerkUserId], null);
if (!$walletId) {
    try {
        $ins = $pdo->prepare("INSERT INTO customer_wallets (customer_id, clerk_user_id, currency, created_at) VALUES (NULL, ?, 'USD', NOW())");
        $ins->execute([$clerkUserId]);
        $walletId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        respond_error('Could not create wallet.', 500);
    }
}

// Current wallet balance
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

// In this schema, orders.amount_total is treated as remaining due.
$amountTotal = (float)($order['amount_total'] ?? 0.0);
// paid_amount will be recalculated later from order_payments (summation)

// Helper to sum unpaid BD charges
// Helper to sum the remaining BD charges across cartons.  When total_due/total_paid
// columns exist we use those values, otherwise we fall back to bd_total_price
// where payment_status is pending.
$sumUnpaidCartons = function(array $ids = null) use ($pdo, $orderId): float {
    $hasPrice   = table_has_col($pdo, 'inbound_cartons', 'bd_total_price');
    $hasTotDue  = table_has_col($pdo, 'inbound_cartons', 'total_due');
    $hasTotPaid = table_has_col($pdo, 'inbound_cartons', 'total_paid');
    $hasStatus  = table_has_col($pdo, 'inbound_cartons', 'bd_payment_status');
    if (!$hasPrice) return 0.0;

    // Build due expression
    $dueExpr = $hasTotDue
        ? "COALESCE(c.total_due, COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0))"
        : ($hasTotPaid
            ? "(COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0))"
            : "COALESCE(c.bd_total_price,0)");
    $sql = "SELECT COALESCE(SUM($dueExpr),0) AS s
              FROM inbound_cartons c
              JOIN inbound_packing_lists p ON p.id=c.packing_list_id
             WHERE p.order_id = ?";
    $args = [$orderId];
    // Only count due amounts > 0
    $sql .= " AND $dueExpr > 0";
    if ($ids && count($ids)) {
        $sql .= " AND c.id IN (" . implode(',', array_map('intval', $ids)) . ")";
    }
    return (float)scalar($pdo, $sql, $args, 0.0);
};

// Decide mode
$mode       = null;
$charge     = 0.0;
$notes      = '';
$ledgerType = 'charge_sourcing_captured'; // default for manual amount
$paymentType = 'deposit';

if ($cartonIds) {
    $mode = 'cartons';
    $charge = $sumUnpaidCartons($cartonIds);
    $notes = 'wallet capture: selected cartons';
    $ledgerType = 'charge_shipping_captured';
    $paymentType = 'bd_final';
} elseif ($amountIn !== null) {
    $mode = 'amount';
    $charge = (float)$amountIn;
    $notes = 'wallet capture: manual amount';
    $ledgerType = 'charge_sourcing_captured';
    $paymentType = 'deposit';
} else {
    $mode = 'all_cartons';
    $charge = $sumUnpaidCartons(null);
    $notes = 'wallet capture: all unpaid cartons';
    $ledgerType = 'charge_shipping_captured';
    $paymentType = 'bd_final';
}

$charge = max(0.0, $charge);
if ($charge <= 0.0) respond_error('Nothing to pay.');

// Caps / checks
$orderDue = max(0.0, $amountTotal);
if ($mode === 'amount') {
    $maxPay = min($walletBalance, $orderDue);
    if ($maxPay <= 0) respond_error('Insufficient wallet balance or nothing is due.');
    if ($charge > $maxPay) $charge = $maxPay;
} else {
    if ($walletBalance + 1e-9 < $charge) {
        respond_error('Insufficient wallet balance for selected cartons.');
    }
}

// Pick a bank account id (fallback to 1)
$bankAccountId = (int)scalar(
    $pdo,
    "SELECT id FROM bank_accounts WHERE is_active=1 ORDER BY id ASC LIMIT 1",
    [],
    0
);
if ($bankAccountId <= 0) $bankAccountId = 1;

try {
    $pdo->beginTransaction();

    // Create order_payments (verified immediately)
    $txnCode = 'WAL-' . strtoupper(bin2hex(random_bytes(4)));
    $insPay = $pdo->prepare("
        INSERT INTO order_payments
        (order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type,
         created_at, verified_at, verified_by)
        VALUES (?, ?, ?, ?, NULL, 'verified', ?, NOW(), NOW(), 0)
    ");
    $insPay->execute([$orderId, $bankAccountId, $txnCode, $charge, $paymentType]);
    $paymentId = (int)$pdo->lastInsertId();

    // Carton marking + link rows, when applicable
    $cartonsPaid = 0;
    if (($mode === 'cartons' || $mode === 'all_cartons') && $ledgerType === 'charge_shipping_captured') {
        // Apply the payment to cartons using partial payment logic
        $result = apply_payment_to_cartons($pdo, $orderId, $charge, ($mode === 'cartons' ? $cartonIds : null));
        $cartonsPaid = count($result['per_carton']);

        // Insert mapping rows if the link table exists
        $hasPayCartonLinkTbl = false;
        try {
            $hasPayCartonLinkTbl = (bool)$pdo->query("SHOW TABLES LIKE 'order_payment_cartons'")->fetchColumn();
        } catch (Throwable $e) {
            $hasPayCartonLinkTbl = false;
        }
        if ($hasPayCartonLinkTbl && $cartonsPaid > 0) {
            $insCart = $pdo->prepare("INSERT INTO order_payment_cartons (payment_id, carton_id, amount, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($result['per_carton'] as $cr) {
                $insCart->execute([$paymentId, (int)$cr['id'], (float)$cr['applied']]);
            }
        }
        // The actual applied_total may be less than requested charge (if wallet balance > due)
        $appliedTotalToOrder = (float)$result['applied_total'];
    } else {
        // Default: full amount applies to order due
        $appliedTotalToOrder = $charge;
    }

    // Wallet ledger entry (single row per capture)
    $insLedger = $pdo->prepare("
        INSERT INTO wallet_ledger
        (wallet_id, entry_type, amount, currency, order_id, carton_id,
         payment_id, notes, created_at, created_by)
        VALUES
        (?, ?, ?, 'USD', ?, NULL, ?, ?, NOW(), NULL)
    ");
    $insLedger->execute([$walletId, $ledgerType, $charge, $orderId, $paymentId, $notes]);

    // =========================
    // UPDATE ORDER TOTALS
    // =========================

    // 1) Recalculate orders.paid_amount by SUMMATION (requested change)
    //    We only sum verified 'deposit' payments so BD-final carton payments
    //    don't inflate sourcing paid_amount.
    $recalcPaid = (float)scalar(
        $pdo,
        "SELECT COALESCE(SUM(amount),0)
           FROM order_payments
          WHERE order_id = ?
            AND status = 'verified'
            AND payment_type = 'deposit'",
        [$orderId],
        0.0
    );
    if (table_has_col($pdo, 'orders', 'paid_amount')) {
        $pdo->prepare("UPDATE orders SET paid_amount = ? WHERE id = ?")
            ->execute([$recalcPaid, $orderId]);
    }

    // 2) Reduce remaining sourcing due (orders.amount_total) only when capturing a deposit.
    //    Shipping (bd_final) payments should NOT decrease the product sourcing due.  In our
    //    schema, deposit captures use the ledger type 'charge_sourcing_captured' and
    //    payment_type 'deposit', while shipping captures use 'charge_shipping_captured'
    //    and payment_type 'bd_final'.  To avoid mixing BD charges with deposit due,
    //    only deduct from orders.amount_total when the capture is a deposit.
    $reduceDue = 0.0;
    if ($ledgerType === 'charge_sourcing_captured') {
        // For manual amount capture, we cap the deduction by the current order due (handled above).
        $reduceDue = $charge;
    } else {
        // For shipping captures, leave amount_total unchanged.
        $reduceDue = 0.0;
    }

    if ($reduceDue > 0) {
        if (table_has_col($pdo, 'orders', 'amount_total')) {
            $newDue = max(0.0, $amountTotal - $reduceDue);
            $pdo->prepare("UPDATE orders SET amount_total = ? WHERE id = ?")
                ->execute([$newDue, $orderId]);
            $amountTotal = $newDue;
        } else {
            $amountTotal = max(0.0, $amountTotal - $reduceDue);
        }
    }

    // =========================
    // STATUS TRANSITIONS
    // =========================
    $orderType     = strtolower((string)($order['order_type'] ?? ''));
    $productPrice  = isset($order['product_price']) ? (float)$order['product_price'] : null;
    $newStatus         = $order['status'];
    $newPaymentStatus  = $order['payment_status'];

    // Use recalculated paid amount for sourcing progress.
    $isSourcing = in_array($orderType, ['sourcing','both'], true);
    if ($isSourcing && $productPrice !== null && $productPrice > 0) {
        if ($recalcPaid + 1e-9 >= $productPrice) {
            $newStatus = 'paid_for_sourcing';
            $newPaymentStatus = 'paid_for_sourcing';
        } else {
            $newStatus = 'partially_paid';
            $newPaymentStatus = 'partially_paid';
        }
    } else {
        if ($amountTotal > 0) {
            $newStatus = 'partially_paid';
            $newPaymentStatus = 'partially_paid';
        } else {
            $newStatus = 'paid';
            $newPaymentStatus = 'paid';
        }
    }

    $pdo->prepare("UPDATE orders SET status=?, payment_status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $newPaymentStatus, $orderId]);

    $pdo->commit();

    // Fresh wallet balance for response
    if ($hasBalanceView) {
        $newWalletBalance = (float)scalar($pdo, "SELECT balance FROM wallet_balances WHERE wallet_id=?", [$walletId], 0.0);
    } else {
        $newWalletBalance = (float)scalar(
            $pdo,
            "SELECT
                COALESCE(SUM(CASE WHEN entry_type IN ('topup_verified','manual_credit','adjustment_credit','refund') THEN amount ELSE 0 END),0)
              - COALESCE(SUM(CASE WHEN entry_type IN ('charge_shipping_captured','charge_sourcing_captured') THEN amount ELSE 0 END),0)
             FROM wallet_ledger WHERE wallet_id=?",
            [$walletId],
            0.0
        );
    }

    // Response order due (remaining)
    $newOrderDue = max(0.0, $amountTotal);

    respond_ok([
        'wallet_balance' => round($newWalletBalance, 2),
        'order_due'      => round($newOrderDue, 2),
        'payment_id'     => $paymentId,
        'cartons_paid'   => $cartonsPaid,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[wallet_capture] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    respond_error('Server error. Please try again later.', 500);
}
