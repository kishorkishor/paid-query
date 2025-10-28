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
 * Recompute paid_amount for an order from VERIFIED direct payments (excluding wallet_topup),
 * write it to orders.paid_amount (if column exists), and update payment/status:
 *   - if paid_sum >= amount_total  => status='paid for sourcing', payment_status='paid_for_sourcing'
 *   - else if paid_sum > 0         => payment_status='partially_paid' AND status='partially_paid'
 *   - else                         => payment_status='verifying' (status unchanged)
 *
 * NOTE: We DO NOT modify amount_total here.
 *
 * Returns ['paid_sum'=>float, 'amount_total'=>float]
 */
function update_paid_and_status(PDO $pdo, int $orderId): array {
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

  if ($hasPaidAmount) {
    $pdo->prepare("UPDATE orders SET paid_amount=? WHERE id=?")->execute([$paidSum, $orderId]);
  }

  if ($paidSum + 1e-9 >= $amountTotal && $amountTotal > 0) {
    $pdo->prepare("UPDATE orders SET status='paid for sourcing', payment_status='paid_for_sourcing', updated_at=NOW() WHERE id=?")
        ->execute([$orderId]);
  } else {
    if ($paidSum > 0) {
      $pdo->prepare("UPDATE orders SET payment_status='partially_paid', status='partially_paid', updated_at=NOW() WHERE id=?")
          ->execute([$orderId]);
    } else {
      $pdo->prepare("UPDATE orders SET payment_status='verifying', updated_at=NOW() WHERE id=?")
          ->execute([$orderId]);
    }
  }

  return ['paid_sum'=>$paidSum, 'amount_total'=>$amountTotal];
}

/* ---- POST: verify / reject direct payments (DEPOSIT ONLY) ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'], $_POST['id'])) {
  $pid = (int)$_POST['id'];
  $action = $_POST['action'] ?? '';

  // Load payment: STRICT to deposit only
  $st = $pdo->prepare(
    "SELECT p.*,
            o.amount_total, o.id AS oid, o.query_id, o.code AS order_code,
            o.status AS order_status, o.payment_status AS order_payment_status
       FROM order_payments p
  LEFT JOIN orders o ON o.id = p.order_id
      WHERE p.id=? AND COALESCE(TRIM(LOWER(p.payment_type)), '') = 'deposit'
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

        // (Kept for compatibility; won't run for deposits)
        if (!empty($p['payment_type']) && $p['payment_type'] === 'bd_final' && !empty($p['oid'])) {
          if ($pdo->query("SHOW TABLES LIKE 'inbound_cartons'")->fetchColumn()
              && $pdo->query("SHOW TABLES LIKE 'inbound_packing_lists'")->fetchColumn()) {
            $pdo->prepare("
              UPDATE inbound_cartons c
              JOIN inbound_packing_lists pl ON pl.id=c.packing_list_id
                 SET c.bd_payment_status='verified', c.bd_payment_verified_at=NOW()
               WHERE pl.order_id=? AND COALESCE(c.bd_payment_status,'pending')='pending'
            ")->execute([(int)$p['oid']]);
          }

          if ($delivery_helper_loaded) {
            $idsStmt = $pdo->prepare("
              SELECT c.id
                FROM inbound_cartons c
                JOIN inbound_packing_lists pl ON pl.id=c.packing_list_id
               WHERE pl.order_id=? AND COALESCE(c.bd_payment_status,'pending')='verified'
            ");
            $idsStmt->execute([(int)$p['oid']]);
            $ids = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) queue_delivery_for_cartons($pdo, (int)$p['oid'], $ids, $me, 'auto from bank verification');
          }
        }

        if (!empty($p['oid'])) {
          $ledger = update_paid_and_status($pdo, (int)$p['oid']);
          $paid   = (float)$ledger['paid_sum'];
          $dueRef = (float)$ledger['amount_total'];

          if (!empty($p['query_id'])) {
            $msg = 'Accounts verified deposit (Txn '.$pid.'). Paid $'.number_format($paid,2).'; Target $'.number_format($dueRef,2)
                 . '. Order '.($p['order_code'] ?: '#'.$p['oid']).'.';
            $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([(int)$p['query_id'], $msg]);
          }

          $meta = json_encode([
            'order_id'   => (int)$p['oid'],
            'payment_id' => $pid,
            'paid_sum'   => $paid,
            'amount_total'=> $dueRef
          ], JSON_UNESCAPED_SLASHES);
          @ $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                           VALUES ('order', ?, ?, 'deposit_verified_update_paid', ?, NOW())")
                ->execute([(int)$p['oid'], $me, $meta]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[direct verify deposit] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }

    } elseif ($action === 'reject') {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE order_payments SET status='rejected', verified_at=NOW(), verified_by=? WHERE id=?")
            ->execute([$me, $pid]);

        // (Compatibility block; won’t run for deposits)
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

        if (!empty($p['oid'])) {
          $ledger = update_paid_and_status($pdo, (int)$p['oid']);
          $paid   = (float)$ledger['paid_sum'];
          $dueRef = (float)$ledger['amount_total'];

          if ($paid > 0) {
            $pdo->prepare("UPDATE orders SET payment_status='rejected', status='partially_paid', updated_at=NOW() WHERE id=?")
                ->execute([(int)$p['oid']]);
          } else {
            $pdo->prepare("UPDATE orders SET payment_status='rejected', updated_at=NOW() WHERE id=?")
                ->execute([(int)$p['oid']]);
          }

          if (!empty($p['query_id'])) {
            $msg = 'Accounts rejected a deposit (Txn '.$pid.'). Paid $'.number_format($paid,2)
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
                           VALUES ('order_payment', ?, ?, 'deposit_rejected_update_paid', ?, NOW())")
                ->execute([$pid, $me, $meta]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[direct reject deposit] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }
    }
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

/* ---- List direct payments — SHOW DEPOSITS ONLY ---- */
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
    LEFT JOIN orders o   ON o.id  = p.order_id
    JOIN bank_accounts b ON b.id  = p.bank_account_id
   WHERE p.status IN ('verifying','verified','rejected')
     AND COALESCE(TRIM(LOWER(p.payment_type)), '') = 'deposit'
   ORDER BY (p.status='verifying') DESC, p.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accounts — Verify Deposits</title>
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
  <div class="brand">Accounts • Verify Deposits</div>
  <div style="display:flex;gap:10px">
    <a href="/app/accounts_payments_wallet.php" style="color:#fff;text-decoration:underline">Wallet Top-ups</a>
    <a href="/app/" style="color:#fff;text-decoration:underline">Admin Home</a>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 12px">Incoming Deposit Payments</h2>
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
            <tr><td colspan="7" class="muted">No deposit payments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
