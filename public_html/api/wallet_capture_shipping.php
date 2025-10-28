<?php
// /api/wallet_capture_shipping.php - SIMPLE VERSION: Only checks total_due
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

function respond_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function respond_ok(array $data): void {
    echo json_encode(['ok' => true] + $data);
    exit;
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

/**
 * Apply payment to cartons - SIMPLE VERSION
 * Just uses total_due directly, no status checks
 */
function apply_payment_to_cartons(PDO $pdo, int $orderId, float $amount, ?array $cartonIds = null): array {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return ['applied_total' => 0.0, 'per_carton' => []];

    // Build filter for specific cartons
    $filter = '';
    if ($cartonIds && count($cartonIds)) {
        $filter = ' AND c.id IN (' . implode(',', array_map('intval', $cartonIds)) . ')';
    }

    // Get cartons with total_due > 0 (ignore status completely)
    $sql = "
        SELECT c.id,
               c.total_due,
               c.total_paid,
               c.bd_total_price
          FROM inbound_cartons c
          JOIN inbound_packing_lists p ON p.id = c.packing_list_id
         WHERE p.order_id = ?
           AND c.total_due > 0
           " . $filter . "
         ORDER BY c.id ASC
         FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$rows) {
        return ['applied_total' => 0.0, 'per_carton' => []];
    }

    // Prepare update statement - calculate values in PHP to avoid PDO parameter issues
    $updateSql = "UPDATE inbound_cartons SET
            total_paid = :new_paid,
            total_due  = :new_due,
            bd_payment_status = :new_status,
            bd_payment_verified_at = :verified_at
          WHERE id = :id
          LIMIT 1";
    $updStmt = $pdo->prepare($updateSql);

    $remaining = $amount;
    $appliedTotal = 0.0;
    $perCarton = [];
    
    foreach ($rows as $r) {
        if ($remaining <= 0) break;
        
        $due = round((float)$r['total_due'], 2);
        if ($due <= 0) continue;
        
        // Apply as much as possible to this carton
        $apply = min($due, $remaining);
        
        // Calculate new values
        $newPaid = round((float)$r['total_paid'] + $apply, 2);
        $newDue = round($due - $apply, 2);
        if ($newDue < 0) $newDue = 0.0;
        
        // Determine status
        if ($newDue <= 0) {
            $newStatus = 'verified';
            $verifiedAt = date('Y-m-d H:i:s');
        } elseif ($newPaid > 0) {
            $newStatus = 'partial';
            $verifiedAt = null;
        } else {
            $newStatus = 'pending';
            $verifiedAt = null;
        }
        
        // Execute update
        $updStmt->execute([
            ':new_paid' => $newPaid,
            ':new_due' => $newDue,
            ':new_status' => $newStatus,
            ':verified_at' => $verifiedAt,
            ':id' => (int)$r['id']
        ]);
        
        $appliedTotal += $apply;
        $remaining = round($remaining - $apply, 2);
        
        $perCarton[] = [
            'id' => (int)$r['id'], 
            'applied' => $apply, 
            'new_paid' => $newPaid, 
            'new_due' => $newDue
        ];
    }

    return ['applied_total' => round($appliedTotal, 2), 'per_carton' => $perCarton];
}

try {
    $pdo = db();
} catch (Throwable $e) {
    respond_error('Database connection failed.', 500);
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

if ($orderId <= 0) respond_error('Missing order_id.');

// Fetch order
$stmt = $pdo->prepare("SELECT o.*, q.clerk_user_id FROM orders o LEFT JOIN queries q ON q.id=o.query_id WHERE o.id=? LIMIT 1");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) respond_error('Order not found.', 404);

$clerkUserId = $order['clerk_user_id'] ?? null;
if (!$clerkUserId) respond_error('Wallet owner not resolvable for this order.', 403);

// Get or create wallet
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

// Get wallet balance
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

$amountTotal = (float)($order['amount_total'] ?? 0.0);

// Sum unpaid - SIMPLE: just sum total_due directly
$sumUnpaidCartons = function(?array $ids) use ($pdo, $orderId): float {
    $sql = "SELECT COALESCE(SUM(c.total_due), 0) AS s
              FROM inbound_cartons c
              JOIN inbound_packing_lists p ON p.id = c.packing_list_id
             WHERE p.order_id = ?
               AND c.total_due > 0";
    $args = [$orderId];
    
    if ($ids && count($ids)) {
        $ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));
        if ($ids) {
            $sql .= " AND c.id IN (" . implode(',', $ids) . ")";
        }
    }
    
    $total = (float)scalar($pdo, $sql, $args, 0.0);
    return ($total > 0) ? $total : 0.0;
};

// Determine payment mode
$mode = null;
$desiredCharge = 0.0;
$notes = '';

if ($amountIn !== null && $cartonIds) {
    $mode = 'amount_cartons';
    $due = $sumUnpaidCartons($cartonIds);
    $desiredCharge = min(max(0.0, $amountIn), $due);
    $notes = 'wallet shipping: amount across selected cartons';
} elseif ($cartonIds) {
    $mode = 'cartons';
    $desiredCharge = $sumUnpaidCartons($cartonIds);
    $notes = 'wallet shipping: selected cartons full';
} elseif ($amountIn !== null) {
    $mode = 'amount_all';
    $due = $sumUnpaidCartons(null);
    $desiredCharge = min(max(0.0, $amountIn), $due);
    $notes = 'wallet shipping: amount across all unpaid cartons';
} else {
    $mode = 'all_cartons';
    $desiredCharge = $sumUnpaidCartons(null);
    $notes = 'wallet shipping: all unpaid cartons full';
}

$desiredCharge = max(0.0, $desiredCharge);
if ($desiredCharge <= 0.0) {
    respond_error('Nothing to pay.');
}

// Cap by wallet balance
if ($walletBalance + 1e-9 < $desiredCharge) {
    $desiredCharge = $walletBalance;
    if ($desiredCharge <= 0.0) {
        respond_error('Insufficient wallet balance.');
    }
}

// Cap by total due
if ($mode === 'amount_cartons' || $mode === 'cartons') {
    $dueLimit = $sumUnpaidCartons($cartonIds);
    if ($dueLimit < $desiredCharge) $desiredCharge = $dueLimit;
} else {
    $dueLimit = $sumUnpaidCartons(null);
    if ($dueLimit < $desiredCharge) $desiredCharge = $dueLimit;
}

$targetCartonIds = null;
if ($cartonIds && count($cartonIds)) $targetCartonIds = $cartonIds;

// Get bank account
$bankAccountId = (int)scalar(
    $pdo,
    "SELECT id FROM bank_accounts WHERE is_active=1 ORDER BY id ASC LIMIT 1",
    [],
    0
);
if ($bankAccountId <= 0) $bankAccountId = 1;

try {
    $pdo->beginTransaction();

    // Create payment record
    $txnCode = 'WAL-' . strtoupper(bin2hex(random_bytes(4)));
    $insPay = $pdo->prepare("INSERT INTO order_payments
        (order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type,
         created_at, verified_at, verified_by)
        VALUES
        (?, ?, ?, ?, NULL, 'verified', 'bd_final', NOW(), NOW(), 0)");
    $insPay->execute([$orderId, $bankAccountId, $txnCode, $desiredCharge]);
    $paymentId = (int)$pdo->lastInsertId();

    // Apply payment to cartons
    $result = apply_payment_to_cartons($pdo, $orderId, $desiredCharge, $targetCartonIds);
    $cartonsPaid = count($result['per_carton']);

    // Insert into order_payment_cartons if table exists
    try {
        $hasPayCartonLinkTbl = (bool)$pdo->query("SHOW TABLES LIKE 'order_payment_cartons'")->fetchColumn();
        if ($hasPayCartonLinkTbl && $cartonsPaid > 0) {
            $insCart = $pdo->prepare("INSERT INTO order_payment_cartons (payment_id, carton_id, amount, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($result['per_carton'] as $cr) {
                $insCart->execute([$paymentId, (int)$cr['id'], (float)$cr['applied']]);
            }
        }
    } catch (Throwable $e) {
        // Ignore if table doesn't exist
    }

    // Record wallet ledger entry
    $insLedger = $pdo->prepare("INSERT INTO wallet_ledger
        (wallet_id, entry_type, amount, currency, order_id, carton_id, payment_id, notes, created_at, created_by)
        VALUES
        (?, 'charge_shipping_captured', ?, 'USD', ?, NULL, ?, ?, NOW(), NULL)");
    $insLedger->execute([$walletId, $desiredCharge, $orderId, $paymentId, $notes]);

    // Update order paid_amount (deposit only)
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
    
    try {
        $pdo->prepare("UPDATE orders SET paid_amount = ? WHERE id = ?")
            ->execute([$recalcPaid, $orderId]);
    } catch (Throwable $e) {
        // Ignore if paid_amount column doesn't exist
    }

    // Update order status
    $orderType    = strtolower((string)($order['order_type'] ?? ''));
    $productPrice = isset($order['product_price']) ? (float)$order['product_price'] : null;
    $newStatus        = $order['status'];
    $newPaymentStatus = $order['payment_status'];

    $sourcingDue = max(0.0, $amountTotal);
    $sourcingPaid = $recalcPaid;
    $isSourcing = in_array($orderType, ['sourcing','both'], true);
    if ($isSourcing && $productPrice !== null && $productPrice > 0) {
        if ($sourcingPaid + 1e-9 >= $productPrice) {
            $newStatus = 'paid_for_sourcing';
            $newPaymentStatus = 'paid_for_sourcing';
        } else {
            $newStatus = 'partially_paid';
            $newPaymentStatus = 'partially_paid';
        }
    } else {
        if ($sourcingDue > 0) {
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

    // Get fresh wallet balance
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

    $newOrderDue = max(0.0, $amountTotal);

    respond_ok([
        'wallet_balance' => round($newWalletBalance, 2),
        'order_due'      => round($newOrderDue, 2),
        'payment_id'     => $paymentId,
        'cartons_paid'   => $cartonsPaid,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[wallet_capture_shipping] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    respond_error('Server error: ' . $e->getMessage(), 500);
}