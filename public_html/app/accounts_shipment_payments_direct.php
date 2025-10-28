<?php
// /app/accounts_payments_direct.php — Accounts UI to verify direct deposits (order-related payments only)

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // existing gate

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

// db() lives in /app/config.php — avoid double definition
require_once __DIR__ . '/config.php';

/* Optional: delivery helper for auto-queue after bd_final verification */
$delivery_helper_loaded = false;
if (is_file($delivery_helper_path)) {
  require_once $delivery_helper_path;
  $delivery_helper_loaded = function_exists('queue_delivery_for_cartons');
}

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key,$cache)) return $cache[$key];
  try { $s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $s->execute([$col]); $cache[$key]=(bool)$s->fetch(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ $cache[$key]=false; }
  return $cache[$key];
}

/**
 * Distribute a payment amount across cartons belonging to an order.
 *
 * This helper mirrors the logic used in wallet payments: it uses the
 * `total_paid`/`total_due` columns when present to derive the amount still
 * owed per carton.  The payment amount is applied to cartons in
 * ascending ID order.  After application, `total_paid`, `total_due` and
 * `bd_payment_status` are updated accordingly on each carton.  The
 * function returns the total amount actually applied (which may be less
 * than the requested amount) and an array of per-carton application
 * details.
 *
 * @param PDO $pdo
 * @param int $orderId The order whose cartons to update
 * @param float $amount The payment amount to distribute
 * @param array|null $cartonIds Optional list of specific carton IDs to target
 * @return array [ 'applied_total' => float, 'per_carton' => array ]
 */
function apply_payment_to_cartons(PDO $pdo, int $orderId, float $amount, ?array $cartonIds = null): array {
  $amount = round((float)$amount, 2);
  if ($amount <= 0) {
    return ['applied_total' => 0.0, 'per_carton' => []];
  }

  // Check for per-carton due columns
  $hasTotalDue  = table_has_col($pdo, 'inbound_cartons', 'total_due');
  $hasTotalPaid = table_has_col($pdo, 'inbound_cartons', 'total_paid');
  $hasVerifiedAt = table_has_col($pdo, 'inbound_cartons', 'bd_payment_verified_at');

  // Build filter
  $filter = '';
  if ($cartonIds && count($cartonIds)) {
    $filter = ' AND c.id IN (' . implode(',', array_map('intval', $cartonIds)) . ')';
  }
  // Expression for remaining due per carton
  $dueExpr = ($hasTotalDue || $hasTotalPaid)
      ? "COALESCE(c.total_due, (COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0)))"
      : "GREATEST(COALESCE(c.bd_total_price,0),0)";
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

  // Build update SQL, optionally updating bd_payment_verified_at
  $updateSql = "UPDATE inbound_cartons SET
        total_paid = ROUND(COALESCE(total_paid,0) + :apply, 2),
        total_due  = ROUND(GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0), 2),
        bd_payment_status = CASE
          WHEN GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0) = 0 THEN 'verified'
          WHEN (COALESCE(total_paid,0) + :apply) > 0 THEN 'partial'
          ELSE COALESCE(bd_payment_status,'pending')
        END";
  if ($hasVerifiedAt) {
    $updateSql .= ",\n        bd_payment_verified_at = CASE\n          WHEN GREATEST(COALESCE(bd_total_price,0) - (COALESCE(total_paid,0) + :apply), 0) = 0 THEN NOW()\n          ELSE bd_payment_verified_at\n        END";
  }
  $updateSql .= "\n      WHERE id = :id\n      LIMIT 1";
  $updateStmt = $pdo->prepare($updateSql);

  $remaining = $amount;
  $appliedTotal = 0.0;
  $perCarton = [];
  foreach ($rows as $r) {
    if ($remaining <= 0) break;
    $due = round((float)$r['due'], 2);
    if ($due <= 0) continue;
    $apply = min($due, $remaining);
    $updateStmt->execute([':apply' => $apply, ':id' => (int)$r['id']]);
    $appliedTotal += $apply;
    $remaining = round($remaining - $apply, 2);
    $newPaid = round((float)$r['paid'] + $apply, 2);
    $newDue  = round((float)$r['price'] - $newPaid, 2);
    if ($newDue < 0) $newDue = 0.0;
    $perCarton[] = ['id' => (int)$r['id'], 'applied' => $apply, 'new_paid' => $newPaid, 'new_due' => $newDue];
  }
  return ['applied_total' => round($appliedTotal, 2), 'per_carton' => $perCarton];
}

/**
 * Recompute paid_amount for an order from VERIFIED direct payments (excluding wallet_topup),
 * write it to orders.paid_amount (if column exists), and update payment/status:
 *   - if paid_sum >= amount_total  => status='paid for sourcing', payment_status='paid_for_sourcing'
 *   - else if paid_sum > 0         => payment_status='partially_paid' AND status='partially_paid'  <-- (updated per request)
 *   - else                         => payment_status='verifying' (status unchanged)
 *
 * NOTE: We DO NOT modify amount_total here (per your requirement).
 *
 * Returns ['paid_sum'=>float, 'amount_total'=>float]
 */
function update_paid_and_status(PDO $pdo, int $orderId): array {
  // Get due target and flags
  $cols = ['id','amount_total','status','payment_status'];
  $hasPaidAmount = table_has_col($pdo,'orders','paid_amount');
  $hasOrderType  = table_has_col($pdo,'orders','order_type');
  if ($hasPaidAmount) $cols[] = 'paid_amount';
  if ($hasOrderType)  $cols[] = 'order_type';

  $st = $pdo->prepare("SELECT ".implode(',', $cols)." FROM orders WHERE id=? LIMIT 1");
  $st->execute([$orderId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) return ['paid_sum'=>0.0,'amount_total'=>0.0];

  $amountTotal = (float)($o['amount_total'] ?? 0);

  // Sum only VERIFIED direct payments (exclude wallet_topup)
  $sumRow = $pdo->query("
    SELECT COALESCE(SUM(amount),0) AS s
      FROM order_payments
     WHERE order_id=".(int)$orderId."
       AND status='verified'
       AND COALESCE(TRIM(LOWER(payment_type)), '') <> 'wallet_topup'
  ")->fetch(PDO::FETCH_ASSOC);
  $paidSum = (float)($sumRow['s'] ?? 0);

  // Persist paid_amount if column is present
  if ($hasPaidAmount) {
    $pdo->prepare("UPDATE orders SET paid_amount=? WHERE id=?")->execute([$paidSum, $orderId]);
  }

  // Update statuses by comparison to amount_total ONLY (do not touch amount_total)
  if ($paidSum + 1e-9 >= $amountTotal && $amountTotal > 0) {
    $pdo->prepare("UPDATE orders SET status='paid for sourcing', payment_status='paid_for_sourcing', updated_at=NOW() WHERE id=?")
        ->execute([$orderId]);
  } else {
    if ($paidSum > 0) {
      // >>> Per request: when partially paid, also set status to 'partially_paid'
      $pdo->prepare("UPDATE orders SET payment_status='partially_paid', status='partially_paid', updated_at=NOW() WHERE id=?")
          ->execute([$orderId]);
    } else {
      $pdo->prepare("UPDATE orders SET payment_status='verifying', updated_at=NOW() WHERE id=?")
          ->execute([$orderId]);
    }
  }

  return ['paid_sum'=>$paidSum, 'amount_total'=>$amountTotal];
}

/* ---- POST: verify / reject direct payments ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'], $_POST['id'])) {
  $pid = (int)$_POST['id'];
  $action = $_POST['action'] ?? '';

  // load payment (exclude wallet_topup here explicitly). Use TRIM/LOWER to ensure case/space insensitive comparison
  $st = $pdo->prepare(
    "SELECT p.*,
            o.amount_total, o.id AS oid, o.query_id, o.code AS order_code,
            o.status AS order_status, o.payment_status AS order_payment_status
       FROM order_payments p
  LEFT JOIN orders o ON o.id = p.order_id
      WHERE p.id=? AND COALESCE(TRIM(LOWER(p.payment_type)), '') <> 'wallet_topup'
      LIMIT 1"
  );
  $st->execute([$pid]);
  $p = $st->fetch(PDO::FETCH_ASSOC);

  if ($p) {
    if ($action === 'verify') {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE order_payments SET status='verified', verified_at=NOW(), verified_by=? WHERE id=?")
            ->execute([$me, $pid]);

        // per-kg BD final: distribute payment across cartons and queue delivery
        if (!empty($p['payment_type']) && $p['payment_type'] === 'bd_final' && !empty($p['oid'])) {
          $orderIdFinal = (int)$p['oid'];
          // Determine targeted cartons from applied_carton_ids if present
          $targetIds = null;
          $hasAppliedCart = table_has_col($pdo, 'order_payments', 'applied_carton_ids');
          if ($hasAppliedCart && !empty($p['applied_carton_ids'])) {
            $dec = json_decode($p['applied_carton_ids'], true);
            if (is_array($dec)) {
              $tmp = [];
              foreach ($dec as $cid) { $cid = (int)$cid; if ($cid > 0) $tmp[] = $cid; }
              if ($tmp) $targetIds = $tmp;
            }
          }
          // Apply payment amount to cartons
          $amt = (float)$p['amount'];
          $dist = apply_payment_to_cartons($pdo, $orderIdFinal, $amt, $targetIds);
          $applied = (float)($dist['applied_total'] ?? 0.0);
          // Update payment amount to actual applied total
          if (abs($applied - $amt) > 0.0001) {
            $pdo->prepare("UPDATE order_payments SET amount=? WHERE id=?")->execute([$applied, $pid]);
            $p['amount'] = $applied;
          }
          // Insert mapping rows if table exists
          $hasLinkTbl = false;
          try {
            $hasLinkTbl = (bool)$pdo->query("SHOW TABLES LIKE 'order_payment_cartons'")->fetchColumn();
          } catch (Throwable $ign) { $hasLinkTbl = false; }
          if ($hasLinkTbl && !empty($dist['per_carton'])) {
            $ins = $pdo->prepare("INSERT INTO order_payment_cartons (payment_id, carton_id, amount, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($dist['per_carton'] as $cr) {
              $ins->execute([$pid, (int)$cr['id'], (float)$cr['applied']]);
            }
          }
          // Queue delivery for all verified cartons if helper is available
          if ($delivery_helper_loaded) {
            $idsStmt = $pdo->prepare("SELECT c.id
                FROM inbound_cartons c
                JOIN inbound_packing_lists pl ON pl.id=c.packing_list_id
               WHERE pl.order_id=? AND COALESCE(c.bd_payment_status,'pending')='verified'");
            $idsStmt->execute([$orderIdFinal]);
            $ids = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) queue_delivery_for_cartons($pdo, $orderIdFinal, $ids, $me, 'auto from bank verification');
          }
        }

        // Recalculate paid + status against amount_total (no change to amount_total)
        if (!empty($p['oid'])) {
          $ledger = update_paid_and_status($pdo, (int)$p['oid']);
          $paid   = (float)$ledger['paid_sum'];
          $dueRef = (float)$ledger['amount_total']; // comparison target

          if (!empty($p['query_id'])) {
            $msg = 'Accounts verified payment (Txn '.$pid.'). Paid $'.number_format($paid,2).'; Target $'.number_format($dueRef,2)
                 . '. Order '.($p['order_code'] ?: '#'.$p['oid']).'.';
            $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([(int)$p['query_id'], $msg]);
          }

          $meta = json_encode([
            'order_id' => (int)$p['oid'],
            'payment_id' => $pid,
            'paid_sum' => $paid,
            'amount_total'  => $dueRef
          ], JSON_UNESCAPED_SLASHES);
          @ $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                           VALUES ('order', ?, ?, 'payment_verified_update_paid', ?, NOW())")
                ->execute([(int)$p['oid'], $me, $meta]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[direct verify] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }

    } elseif ($action === 'reject') {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE order_payments SET status='rejected', verified_at=NOW(), verified_by=? WHERE id=?")
            ->execute([$me, $pid]);

        if (!empty($p['payment_type']) && $p['payment_type'] === 'bd_final' && !empty($p['oid'])) {
          if ($pdo->query("SHOW TABLES LIKE 'inbound_cartons'")->fetchColumn()
              && $pdo->query("SHOW TABLES LIKE 'inbound_packing_lists'")->fetchColumn()) {
            $pdo->prepare("
              UPDATE inbound_cartons c
              JOIN inbound_packing_lists pl ON pl.id=c.packing_list_id
                 SET c.bd_payment_status='rejected'
               WHERE pl.order_id=?
            ")->execute([(int)$p['oid']]);
          }
        }

        // Recompute paid + status after rejection (no change to amount_total)
        if (!empty($p['oid'])) {
          $ledger = update_paid_and_status($pdo, (int)$p['oid']); // This may set 'partially_paid' status depending on paid_sum
          $paid   = (float)$ledger['paid_sum'];
          $dueRef = (float)$ledger['amount_total'];

          // >>> Per request: on reject, set payment_status='rejected'
          // and set status='partially_paid' ONLY if paid_amount > 0.
          if ($paid > 0) {
            $pdo->prepare("UPDATE orders SET payment_status='rejected', status='partially_paid', updated_at=NOW() WHERE id=?")
                ->execute([(int)$p['oid']]);
          } else {
            // No paid amount — keep status as-is, but payment_status must reflect rejection
            $pdo->prepare("UPDATE orders SET payment_status='rejected', updated_at=NOW() WHERE id=?")
                ->execute([(int)$p['oid']]);
          }

          if (!empty($p['query_id'])) {
            $msg = 'Accounts rejected a customer payment (Txn '.$pid.'). Paid $'.number_format($paid,2)
                 . '; Target $'.number_format($dueRef,2).'.';
            $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([(int)$p['query_id'], $msg]);
          }

          $meta = json_encode([
            'order_id'=>(int)$p['oid'],
            'payment_id'=>$pid,
            'paid_sum'=>$paid,
            'amount_total'=>$dueRef
          ], JSON_UNESCAPED_SLASHES);
          @ $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                           VALUES ('order_payment', ?, ?, 'payment_rejected_update_paid', ?, NOW())")
                ->execute([$pid, $me, $meta]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[direct reject] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }
    }
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

/* ---- List direct payments (exclude wallet_topup) ---- */
$hasPaidCol = table_has_col($pdo,'orders','paid_amount');
$paidSelect = $hasPaidCol
  ? 'o.paid_amount AS paid_amount'
  : '(SELECT COALESCE(SUM(amount),0)
        FROM order_payments op
       WHERE op.order_id=o.id
         AND op.status="verified"
         AND COALESCE(TRIM(LOWER(op.payment_type)), "") <> "wallet_topup") AS paid_amount';

$rows = $pdo->query("
  SELECT p.*, o.code AS order_code, o.amount_total, o.id AS order_id,
         $paidSelect,
         b.bank_name, b.bank_branch, b.account_name
    FROM order_payments p
    LEFT JOIN orders o        ON o.id  = p.order_id
    JOIN bank_accounts b      ON b.id = p.bank_account_id
   WHERE p.status IN ('verifying','verified','rejected')
     AND COALESCE(TRIM(LOWER(p.payment_type)), '') <> 'wallet_topup'
   ORDER BY (p.status='verifying') DESC, p.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accounts — Verify Direct Deposits</title>
<style>
  :root{--ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --ok:#10b981; --no:#ef4444; --chip:#eef2ff; --chipb:#dde3ff;}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:#0f172a}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#0f172a;color:#fff}
  header .brand{font-weight:700}
  .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
  h2{margin:0 0 10px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;text-align:left}
  tr:hover td{background:#fafafa}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:.88rem}
  .b-ver{background:#fff7ed;border-color:#fed7aa;color:#92400e}
  .b-ok{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
  .b-no{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;background:var(--chip);border:1px solid var(--chipb);font-weight:600}
  .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace}
  .muted{color:#64748b}
  .actions{display:flex;gap:6px}
  .btn{border:0;border-radius:8px;padding:.55rem .8rem;cursor:pointer;color:#fff;font-weight:600}
  .btn.ok{background:var(--ok)}
  .btn.no{background:var(--no)}
</style>
</head>
<body>
<header>
  <div class="brand">Accounts • Verify Direct Deposits</div>
  <div style="display:flex;gap:10px">
    <a href="/app/accounts_payments_wallet.php" style="color:#fff;text-decoration:underline">Wallet Top-ups</a>
    <a href="/app/" style="color:#fff;text-decoration:underline">Admin Home</a>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 12px">Incoming Direct Payments</h2>
    <div style="overflow:auto;border-radius:10px">
      <table>
        <thead>
          <tr>
            <th style="min-width:170px">Transaction</th>
            <th style="min-width:240px">Order</th>
            <th style="min-width:240px">Bank</th>
            <th style="min-width:120px">Amount</th>
            <th style="min-width:130px">Status</th>
            <th style="min-width:110px">Proof</th>
            <th style="min-width:160px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div class="chip mono"><?= e($r['txn_code']) ?></div>
                <div class="muted"><?= e($r['created_at']) ?></div>
              </td>
              <td>
                <div><strong><?= e($r['order_code'] ?: ('#'.$r['order_id'])) ?></strong></div>
                <div class="muted">Target $<?= e(number_format((float)($r['amount_total'] ?? 0),2)) ?> · Paid $<?= e(number_format((float)($r['paid_amount'] ?? 0),2)) ?></div>
              </td>
              <td><?= e(($r['bank_name'] ?? '').' — '.($r['bank_branch'] ?? '')) ?><br><span class="muted"><?= e($r['account_name'] ?? '') ?></span></td>
              <td class="mono">$<?= e(number_format((float)($r['amount'] ?? 0),2)) ?></td>
              <td>
                <?php if (($r['status'] ?? '')==='verifying'): ?>
                  <span class="badge b-ver">verifying</span>
                <?php elseif (($r['status'] ?? '')==='verified'): ?>
                  <span class="badge b-ok">verified</span>
                  <div class="muted"><?= e($r['verified_at'] ?? '') ?></div>
                <?php else: ?>
                  <span class="badge b-no">rejected</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['proof_path'])): ?>
                  <a class="mono" href="<?= e($r['proof_path']) ?>" target="_blank">View</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td>
                <?php if (($r['status'] ?? '')==='verifying'): ?>
                  <div class="actions">
                    <form method="post" style="display:inline">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="verify">
                      <button class="btn ok" type="submit">Verify</button>
                    </form>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <button class="btn no" type="submit">Reject</button>
                    </form>
                  </div>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="muted">No direct payments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
