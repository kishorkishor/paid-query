<?php
// customer/order_details.php — order page with wallet pay (manual amount) + bank + cartons + delivery

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

require_once __DIR__ . '/../api/lib.php';

function abort_page($code, $msg) {
  http_response_code($code);
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui,Arial;margin:40px;color:#111} .box{max-width:980px}</style></head><body>
        <div class="box"><h2>Error</h2><p>'.htmlspecialchars($msg).'</p></div></body></html>';
  exit;
}
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Check if a table has a column */
function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key,$cache)) return $cache[$key];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$key] = (bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $cache[$key] = false;
  }
  return $cache[$key];
}

/** Safe single value select */
function scalar(PDO $pdo, string $sql, array $args=[], $default=null){
  try{ $s=$pdo->prepare($sql); $s->execute($args); $v=$s->fetchColumn(); return ($v===false)?$default:$v; }
  catch(Throwable $e){ return $default; }
}

/** Generate a unique six-digit OTP code for inbound cartons.
 *
 * This helper uses random_int() to produce a 6-digit code and checks the
 * inbound_cartons table to ensure uniqueness.  It will try up to 10
 * times to avoid a collision, then fallback to the trailing digits of
 * microtime() if needed.
 *
 * @param PDO $pdo Database connection
 * @return string A six-digit code as a string
 */
function generate_unique_carton_otp(PDO $pdo): string {
  for ($i = 0; $i < 10; $i++) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $st   = $pdo->prepare("SELECT 1 FROM inbound_cartons WHERE otp_code = ? LIMIT 1");
    $st->execute([$code]);
    if (!$st->fetchColumn()) {
      return $code;
    }
  }
  // Fallback: use the last 6 digits of microtime if collisions persist
  return substr(preg_replace('/[^0-9]/', '', (string)microtime(true)), -6);
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$created = isset($_GET['created']) ? (int)$_GET['created'] : 0;
if ($orderId <= 0) abort_page(400, 'Bad order id.');

try {
  $pdo = db();

  // ---- Order + Query (guard optional columns) ----
  $orderCols = ['o.*'];
  if (!table_has_col($pdo,'orders','amount_total')) $orderCols[] = '0 AS amount_total';
  if (!table_has_col($pdo,'orders','quantity'))     $orderCols[] = '1 AS quantity';
  if (!table_has_col($pdo,'orders','shipping_mode'))$orderCols[] = "NULL AS shipping_mode";
  if (!table_has_col($pdo,'orders','label_type'))   $orderCols[] = "NULL AS label_type";
  if (!table_has_col($pdo,'orders','carton_count')) $orderCols[] = "NULL AS carton_count";
  if (!table_has_col($pdo,'orders','cbm'))          $orderCols[] = "NULL AS cbm";
  if (!table_has_col($pdo,'orders','paid_amount'))  $orderCols[] = "0 AS paid_amount";

  $st = $pdo->prepare("
    SELECT ".implode(',', $orderCols).",
           q.id AS query_id, q.query_code, q.status AS query_status,
           q.submitted_price, q.submitted_price_remark, q.clerk_user_id
      FROM orders o
 LEFT JOIN queries q ON q.id = o.query_id
     WHERE o.id = ?
     LIMIT 1
  ");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) abort_page(404, 'Order not found.');

  // Handle OTP reveal requests.  When the customer clicks "Show Code" on a
  // carton that is ready for delivery, we generate a one-time six-digit
  // code and persist it on that carton record.  Only cartons that belong to
  // this order and are in the "ready for delivery" state will be updated.
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reveal_otp') {
    $cartonId = (int)($_POST['carton_id'] ?? 0);
    if ($cartonId > 0) {
      try {
        $pdo->beginTransaction();
        // Ensure the carton belongs to this order and is ready for delivery
        $checkStmt = $pdo->prepare("SELECT c.id, c.otp_code, c.bd_delivery_status FROM inbound_cartons c JOIN inbound_packing_lists p ON p.id=c.packing_list_id WHERE c.id=? AND p.order_id=? LIMIT 1");
        $checkStmt->execute([$cartonId, $orderId]);
        $cartonRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $statusCheck = strtolower((string)($cartonRow['bd_delivery_status'] ?? ''));
        if ($cartonRow && $statusCheck === 'ready for delivery') {
          if (empty($cartonRow['otp_code'])) {
            $newOtp = generate_unique_carton_otp($pdo);
            $upd = $pdo->prepare("UPDATE inbound_cartons SET otp_code=?, otp_generated_at=NOW() WHERE id=?");
            $upd->execute([$newOtp, $cartonId]);
          }
        }
        $pdo->commit();
      } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[order_details reveal_otp] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
      }
    }
    header('Location: '.$_SERVER['REQUEST_URI']);
    exit;
  }

  // ---- Items JSON (optional) ----
  $items = [];
  if (isset($order['items_json']) && !empty($order['items_json'])) {
    $decoded = json_decode($order['items_json'], true);
    if (is_array($decoded)) $items = $decoded;
  }

  // ---- Cartons (select only existing columns) ----
  $has_w   = table_has_col($pdo,'inbound_cartons','weight_kg');
  $has_v   = table_has_col($pdo,'inbound_cartons','volume_cbm');
  $has_bdt = table_has_col($pdo,'inbound_cartons','bd_total_price');
  $has_bps = table_has_col($pdo,'inbound_cartons','bd_payment_status');
  $has_tot_due  = table_has_col($pdo,'inbound_cartons','total_due');
  $has_tot_paid = table_has_col($pdo,'inbound_cartons','total_paid');

  // BD receipt + delivery support (new + legacy)
  $has_rcv  = table_has_col($pdo,'inbound_cartons','bd_recheck_status'); // new canonical
  $has_del_bd = table_has_col($pdo,'inbound_cartons','bd_delivery_status');
  $has_del_legacy = table_has_col($pdo,'inbound_cartons','delivery_status');

  // OTP and verification fields
  $has_otp_code         = table_has_col($pdo,'inbound_cartons','otp_code');
  $has_otp_generated_at = table_has_col($pdo,'inbound_cartons','otp_generated_at');
  $has_otp_verified_at  = table_has_col($pdo,'inbound_cartons','otp_verified_at');

  $cCols = [
    'c.id',
    $has_w    ? 'c.weight_kg'           : 'NULL AS weight_kg',
    $has_v    ? 'c.volume_cbm'          : 'NULL AS volume_cbm',
    $has_bdt  ? 'c.bd_total_price'      : 'NULL AS bd_total_price',
    $has_tot_paid ? 'c.total_paid'      : 'NULL AS total_paid',
    $has_tot_due  ? 'c.total_due'       : 'NULL AS total_due',
    $has_bps  ? 'c.bd_payment_status'   : "NULL AS bd_payment_status",
    $has_rcv  ? 'c.bd_recheck_status'   : 'NULL AS bd_recheck_status',
    ($has_del_bd ? 'c.bd_delivery_status AS delivery_status' :
      ($has_del_legacy ? 'c.delivery_status' : 'NULL AS delivery_status')),
    (
      $has_bdt
        ? (
            $has_tot_due
              ? 'COALESCE(c.total_due, COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0))'
              : (
                  $has_tot_paid
                    ? 'GREATEST(COALESCE(c.bd_total_price,0) - COALESCE(c.total_paid,0),0)'
                    : 'COALESCE(c.bd_total_price,0)'
                )
          )
        : '0'
    ) . ' AS price'
    ,
    $has_otp_code         ? 'c.otp_code'         : 'NULL AS otp_code',
    $has_otp_generated_at ? 'c.otp_generated_at' : 'NULL AS otp_generated_at',
    $has_otp_verified_at  ? 'c.otp_verified_at'  : 'NULL AS otp_verified_at',
    ($has_del_bd ? 'c.bd_delivery_status' : ($has_del_legacy ? 'c.delivery_status' : 'NULL')) . ' AS bd_delivery_status'
  ];

  $ct = $pdo->prepare("
    SELECT ".implode(',', $cCols)."
      FROM inbound_cartons c
      JOIN inbound_packing_lists p ON p.id=c.packing_list_id
     WHERE p.order_id=?
     ORDER BY c.id ASC
  ");
  $ct->execute([$orderId]);
  $cartons = $ct->fetchAll(PDO::FETCH_ASSOC);

  // ---- Compute BD charges due across cartons ----
  $bdDue = 0.0;
  if ($has_bdt) {
    foreach ($cartons as $c) {
      $due = 0.0;
      if ($has_tot_due) {
        $due = isset($c['total_due']) ? (float)$c['total_due'] : 0.0;
      } elseif ($has_tot_paid) {
        $due = (float)($c['bd_total_price'] ?? 0) - (float)($c['total_paid'] ?? 0);
      } else {
        if (isset($c['bd_payment_status']) && strtolower((string)$c['bd_payment_status']) === 'pending') {
          $due = (float)($c['bd_total_price'] ?? 0);
        }
      }
      if ($due > 0) $bdDue += $due;
    }
  }
  // Auto-update ship_paid to "paid" if no BD charges due
    if ($bdDue <= 0) {
        $pdo->prepare("UPDATE orders SET ship_paid = 'paid' WHERE id = ?")->execute([$order_id]);
    }
  // ---- Wallet lookup (via queries.clerk_user_id -> customer_wallets -> wallet_balances or ledger fallback)
  $walletId = null;
  $walletBal = 0.0;
  $clerkId = $order['clerk_user_id'] ?? null;

  if ($clerkId) {
    $walletId = scalar($pdo, "SELECT id FROM customer_wallets WHERE clerk_user_id=? LIMIT 1", [$clerkId], null);
    if ($walletId) {
      try {
        $hasView = (bool) scalar($pdo, "SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_balances' LIMIT 1", []);
        if ($hasView) {
          $walletBal = (float) scalar($pdo, "SELECT balance FROM wallet_balances WHERE wallet_id=?", [(int)$walletId], 0.0);
        } else {
          $walletBal = (float) scalar(
            $pdo,
            "SELECT
                COALESCE(SUM(CASE WHEN entry_type IN ('topup_verified','manual_credit') THEN amount ELSE 0 END),0)
              - COALESCE(SUM(CASE WHEN entry_type LIKE 'charge_%' THEN amount ELSE 0 END),0)
             FROM wallet_ledger WHERE wallet_id=?",
            [(int)$walletId],
            0.0
          );
        }
      } catch (Throwable $e) {
        $walletBal = 0.0;
      }
    }
  }

} catch (Throwable $e) {
  error_log('[order_details] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
  abort_page(500, 'Server error. Please try again later.');
}

// -----------------------------------------------------------------------------
// Remaining due for the order (sourcing deposit only)
//
// Prior to partial shipping support, orders.amount_total represented the product
// sourcing balance still owed, and orders.paid_amount tracked the cumulative
// verified deposit payments.  With BD (shipping) payments now separate, we
// maintain these columns exclusively for sourcing.  Therefore we set the
// sourcing due directly from amount_total and the paid so far from paid_amount
// without further subtraction.  This ensures BD/transport payments do not
// distort the deposit summary.
$amountTotal = (float)($order['amount_total'] ?? 0.0);
$paidAmt     = (float)($order['paid_amount'] ?? 0.0);
$amountDue   = $amountTotal-$paidAmt;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Order <?= e($order['code'] ?? ('#'.$orderId)) ?> — Cosmic Trading</title>
<style>
  :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--muted:#6b7280;--line:#eee;--ok:#10b981;--warn:#f59e0b;--err:#ef4444;--pri:#111827}
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:#111}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
  .wrap{max-width:1100px;margin:24px auto;padding:0 18px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}
  .muted{color:var(--muted)}
  .grid{display:grid;grid-template-columns:220px 1fr;gap:10px 12px}
  .pill{display:inline-flex;align-items:center;gap:10px;background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;padding:8px 12px;border-radius:999px;font-weight:700}
  .btn{padding:.8rem 1.2rem;border:0;border-radius:12px;background:var(--pri);color:#fff;cursor:pointer;text-decoration:none;display:inline-block}
  .ok{background:var(--ok)}
  .warn{background:var(--warn)}
  .hint{color:var(--muted);font-size:.92rem}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eee;vertical-align:top}
  h2{margin:0 0 8px}
  .actions{display:flex;gap:10px;flex-wrap:wrap}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace}
  .selbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .field{display:flex;align-items:center;gap:8px}
  .field input[type=number]{padding:.55rem .7rem;border:1px solid #e5e7eb;border-radius:10px;width:160px}
  .badge{display:inline-block;padding:4px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:600}
  .toast-wrap{position:fixed;right:18px;top:18px;display:flex;flex-direction:column;gap:8px;z-index:9999}
  .toast{min-width:260px;max-width:360px;padding:12px 14px;border-radius:10px;color:#0b2;box-shadow:0 6px 18px rgba(0,0,0,.1);font-weight:600}
  .toast.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46}
  .toast.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
  .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);display:none;align-items:center;justify-content:center;z-index:10000}
  .modal{background:var(--card);border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.1);padding:20px;max-width:360px;width:90%}
  .modal h4{margin-top:0;margin-bottom:10px;color:var(--ink)}
  .modal input[type=number]{padding:.6rem .8rem;border:1px solid #e5e7eb;border-radius:10px;width:100%}
  .modal-buttons{display:flex;gap:12px;justify-content:flex-end;margin-top:16px}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Trading</strong> — Customer</div>
  <div class="row">
    <a href="/customer/wallet.php?order_id=<?= (int)$orderId ?>" class="btn">Wallet</a>
    <a href="/index.html" class="btn">Dashboard</a>
  </div>
</header>

<div class="wrap">
  <?php if ($created): ?>
    <div class="card" style="border-color:#bbf7d0;background:#ecfdf5"><strong>Order created.</strong> Your order was created successfully.</div>
  <?php endif; ?>

  <div class="card">
    <h2>Order <?= e($order['code'] ?? ('#'.$orderId)) ?></h2>
    <div class="muted">
      Placed on <strong><?= e($order['created_at'] ?? '') ?></strong>
      <?php if (!empty($order['query_code'])): ?> · From Query <strong><?= e($order['query_code']) ?></strong><?php endif; ?>
    </div>

    <div style="margin-top:10px">
      <span class="pill"><span>Status:</span><strong><?= e($order['status'] ?? '—') ?></strong></span>
      <span class="pill" style="margin-left:8px"><span>Payment:</span><strong><?= e($order['payment_status'] ?? 'unpaid') ?></strong></span>
    </div>

    <?php if (!empty($order['submitted_price'])): ?>
      <div style="margin-top:10px" class="pill"><span>Approved price</span><strong>$<?= e(number_format((float)$order['submitted_price'],2)) ?></strong></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Summary</h3>
    <div class="grid">
      <div>Quantity</div><div><?= e($order['quantity'] ?? 1) ?></div>
      <div>Amount to Pay (USD)</div><div><strong class="mono" id="dueNow">$<?= e(number_format($amountDue,2)) ?></strong> <span class="muted">(remaining)</span></div>
      <div>Paid so far</div><div class="mono">$<?= e(number_format($paidAmt,2)) ?></div>
      <div>Shipping</div><div><?= e($order['shipping_mode'] ?? '—') ?></div>
      <div>Label</div><div><?= e($order['label_type'] ?? '—') ?></div>
      <div>Cartons</div><div><?= e($order['carton_count'] ?? '—') ?></div>
      <div>CBM</div><div><?= e($order['cbm'] ?? '—') ?></div>
    </div>
  </div>

  <div class="card">
    <h3>Pay for Sourcing / Order</h3>
    <div class="row" style="justify-content:space-between;align-items:flex-start">
      <div>
        <div class="badge">Pay by Bank</div>
        <p class="muted">Upload your bank proof on the next page.</p>
        <a class="btn ok" href="/customer/payment.php?order_id=<?= (int)$orderId ?>">Pay by Bank</a>
      </div>

      <div>
        <div class="badge">Pay with Wallet</div>
        <div class="muted">Balance: <strong class="mono" id="walBal">$<?= e(number_format($walletBal,2)) ?></strong></div>
        <form class="row" id="walletPayForm" onsubmit="return false" style="margin-top:8px">
          <div class="field">
            <label for="walAmt" class="muted">Amount</label>
            <input id="walAmt" type="number" step="0.01" min="0" placeholder="e.g. 50.00">
          </div>
          <button class="btn" id="walletPayBtn" type="button">Pay with Wallet</button>
        </form>
        <div class="hint">You can pay any amount up to your balance and the remaining due.</div>
      </div>
    </div>
  </div>

  <!-- ITEMS -->
  <div class="card">
    <h3>Items</h3>
    <?php if (!$items): ?>
      <em class="muted">No item details recorded.</em>
    <?php else: ?>
      <table>
        <thead><tr><th style="width:260px">Product</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['product_name'] ?? '—') ?></td>
              <td>
                <?php if (!empty($it['links'])): ?><div><strong>Links:</strong> <?= nl2br(e($it['links'])) ?></div><?php endif; ?>
                <?php if (!empty($it['details'])): ?><div><strong>Details:</strong><br><?= nl2br(e($it['details'])) ?></div><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- CARTONS -->
  <div class="card" id="cartonsCard">
    <h3>Cartons</h3>
    <p class="hint">Select <b>unpaid</b> cartons to pay BD charges now, or select <b>paid</b> cartons to request delivery.</p>

    <?php if (!$cartons): ?>
      <div class="muted">No cartons recorded yet.</div>
    <?php else: ?>
      <form id="cartonForm" method="post" action="/customer/shipment_payment.php?order_id=<?= (int)$orderId ?>&pay=bd_final">
        <table>
          <thead>
            <tr>
              <th style="width:40px">Pay</th>
              <th style="width:40px">Deliver</th>
              <th>Carton</th>
              <th>Status</th>
              <th class="mono">Bill (USD)</th>
              <th class="mono">Paid</th>
              <th class="mono">Due</th>
               <th>Delivery</th>
               <th>OTP</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $selectablePay = 0; $selectableDel = 0;
              foreach ($cartons as $c):
                // Determine BD/partial status based on due and paid columns
                $atBD = isset($c['bd_recheck_status']) && strtolower((string)$c['bd_recheck_status']) === 'received';

                // Determine billing values: total bill, paid so far and due
                $billVal = isset($c['bd_total_price']) ? (float)$c['bd_total_price'] : 0.0;
                $paidVal = 0.0;
                $dueVal  = 0.0;
                if ($has_tot_paid) {
                  $paidVal = isset($c['total_paid']) ? (float)$c['total_paid'] : 0.0;
                }
                if ($has_tot_due) {
                  $dueVal = isset($c['total_due']) ? (float)$c['total_due'] : 0.0;
                }
                if (!$has_tot_due && !$has_tot_paid) {
                  $billVal = isset($c['bd_total_price']) ? (float)$c['bd_total_price'] : 0.0;
                  $statusValRaw = strtolower((string)($c['bd_payment_status'] ?? ''));
                  $dueVal = ($statusValRaw === 'pending') ? $billVal : 0.0;
                  $paidVal = $billVal - $dueVal;
                } else {
                  if (!$has_tot_due && $has_tot_paid) {
                    $dueVal = max(0.0, $billVal - $paidVal);
                  }
                  if ($has_tot_due && !$has_tot_paid) {
                    $paidVal = max(0.0, $billVal - $dueVal);
                  }
                }
                if ($billVal < 0) $billVal = 0.0;
                if ($paidVal < 0) $paidVal = 0.0;
                if ($dueVal  < 0) $dueVal  = 0.0;
                $statusVal = strtolower((string)($c['bd_payment_status'] ?? ''));
                $paidFlag   = false;
                $partialFlag= false;
                if ($has_bps) {
                  if ($statusVal === 'verified' && $dueVal <= 0.0) {
                    $paidFlag = true;
                  } elseif ($statusVal === 'partial') {
                    $partialFlag = true;
                  }
                }
                if (!$paidFlag && !$partialFlag) {
                  if ($paidVal > 0 && $dueVal > 0) {
                    $partialFlag = true;
                  }
                }
                $atBDUnpaidFlag = (!$paidFlag && !$partialFlag && $atBD && $dueVal > 0);
                $priceVal = $dueVal;
                $canPay   = $atBD && $priceVal > 0;
                $canDel   = $paidFlag && (strtolower((string)($c['delivery_status'] ?? '')) !== 'delivered');
                if ($canPay) $selectablePay++;
                if ($canDel) $selectableDel++;
            ?>
              <tr>
                <td>
                  <?php if ($canPay): ?>
                    <input type="checkbox" class="pick paypick" name="carton_ids[]" value="<?= (int)$c['id'] ?>" data-price="<?= e($priceVal) ?>">
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <?php if ($canDel): ?>
                    <input type="checkbox" class="pick delpick" value="<?= (int)$c['id'] ?>">
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <div class="mono">#<?= (int)$c['id'] ?></div>
                  <div class="muted">
                    <?php if (!is_null($c['weight_kg'])): ?>Weight: <?= e($c['weight_kg']) ?> kg · <?php endif; ?>
                    <?php if (!is_null($c['volume_cbm'])): ?>CBM: <?= e($c['volume_cbm']) ?><?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($paidFlag): ?>
                    <span class="pill" style="background:#ecfdf5;border-color:#bbf7d0;color:#065f46">In transit</span>
                  <?php elseif ($partialFlag): ?>
                    <span class="pill" style="background:#fff7ed;border-color:#fed7aa;color:#92400e">Partially Paid</span>
                  <?php elseif ($atBDUnpaidFlag): ?>
                    <span class="pill" style="background:#fff7ed;border-color:#fed7aa;color:#92400e">At Destination Country</span>
                  <?php else: ?>
                    <span class="pill" style="background:#eef2ff;border-color:#e5e7ff;color:#1e3a8a">Pending Payment</span>
                  <?php endif; ?>
                </td>
                <td class="mono">$<?= e(number_format($billVal,2)) ?></td>
                <td class="mono">$<?= e(number_format($paidVal,2)) ?></td>
                <td class="mono">$<?= e(number_format($dueVal,2)) ?></td>
                <td><?= e($c['delivery_status'] ?? '—') ?></td>
                <td>
                  <?php
                    $bdStatusLower = strtolower((string)($c['bd_delivery_status'] ?? $c['delivery_status'] ?? ''));
                    $otpCodeVal    = $c['otp_code'] ?? null;
                    $otpGenVal     = $c['otp_generated_at'] ?? null;
                    $otpVerVal     = $c['otp_verified_at'] ?? null;
                  ?>
                  <?php if ($bdStatusLower === 'ready for delivery'): ?>
                    <?php if (empty($otpCodeVal)): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="reveal_otp">
                        <input type="hidden" name="carton_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn ok" type="submit">Show Code</button>
                      </form>
                    <?php else: ?>
                      <div class="mono">Code: <?= e($otpCodeVal) ?></div>
                      <div class="muted"><?= e($otpGenVal ?? '') ?></div>
                    <?php endif; ?>
                  <?php elseif ($bdStatusLower === 'preperaing for delivery' || $bdStatusLower === 'preparing for delivery'): ?>
                    <span class="muted">Preparing…</span>
                  <?php elseif ($bdStatusLower === 'delivered'): ?>
                    <span class="badge" style="background:#ecfdf5;border-color:#bbf7d0;color:#065f46">Delivered</span>
                    <?php if (!empty($otpVerVal)): ?>
                      <div class="muted"><?= e($otpVerVal) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="selbar">
          <div class="actions">
            <button type="button" class="btn warn" id="reqDeliveryBtn" <?= $selectableDel? '' :'disabled' ?>>Request Delivery (Selected Paid)</button>
          </div>
        </div>
        <input type="hidden" name="bd_final" value="1">
      </form>
    <?php endif; ?>
  </div>

  <?php if ($bdDue > 0): ?>
    <div class="card">
      <h3>Bangladesh Charges Due</h3>
      <p>The total remaining charges on unpaid cartons are:</p>
      <h2>$<?= e(number_format($bdDue,2)) ?></h2>
      <a class="btn ok" href="/customer/shipment_payment.php?order_id=<?= (int)$orderId ?>&pay=bd_final">Pay All Unpaid (Bank)</a>
      <a class="btn" href="#" onclick="payWalletAll(event)">Pay All Unpaid (Wallet)</a>
    </div>
  <?php endif; ?>

  <div class="card" style="display:flex;gap:10px;align-items:center;justify-content:space-between">
    <div class="muted">Need help? Contact support.</div>
    <div><a class="btn" href="/index.html">Back to Dashboard</a></div>
  </div>

</div> <!-- end .wrap -->

<!-- Modal for amount input -->
<div class="modal-overlay" id="amountModalOverlay">
  <div class="modal" id="amountModal">
    <h4>Enter Amount</h4>
    <input type="number" id="modalAmount" step="0.01" min="0">
    <div class="modal-buttons">
      <button class="btn warn" id="modalCancel">Cancel</button>
      <button class="btn ok" id="modalConfirm">Confirm</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toasts"></div>

<script>
(function(){
  const fmt = v => '$' + Number(v||0).toFixed(2);

  const payPicks = Array.from(document.querySelectorAll('.paypick'));
  const delPicks = Array.from(document.querySelectorAll('.delpick'));
  const totalEl  = document.getElementById('selTotal');
  const btnBank  = document.getElementById('payBankBtn');
  const btnWalSel= document.getElementById('payWalletSelectedBtn');
  const btnReq   = document.getElementById('reqDeliveryBtn');

  const walForm  = document.getElementById('walletPayForm');
  const walAmt   = document.getElementById('walAmt');
  const walBtn   = document.getElementById('walletPayBtn');
  const walBalEl = document.getElementById('walBal');
  const dueNowEl = document.getElementById('dueNow');

  const ORDER_ID = <?= (int)$orderId ?>;
  let walletBalance = <?= json_encode((float)$walletBal) ?>;
  let orderDue      = <?= json_encode((float)$amountDue) ?>;

  function toast(msg, ok=true){
    const wrap = document.getElementById('toasts');
    const div = document.createElement('div');
    div.className = 'toast '+(ok?'ok':'err');
    div.textContent = msg;
    wrap.appendChild(div);
    setTimeout(()=>{ div.style.opacity='0'; }, 2800);
    setTimeout(()=>{ wrap.removeChild(div); }, 3300);
  }

  function recalc(){
    let sum=0,cnt=0;
    payPicks.forEach(ch => { if (ch.checked){ sum += Number(ch.dataset.price||0); cnt++; } });
    if (totalEl) totalEl.textContent = fmt(sum);

    const enPay = cnt>0;
    if (btnBank)   btnBank.disabled   = !enPay;
    if (btnWalSel) btnWalSel.disabled = !enPay;

    const enDel = delPicks.some(ch=>ch.checked);
    if (btnReq) btnReq.disabled = !enDel;
  }

  if (payPicks.length || delPicks.length){
    [...payPicks, ...delPicks].forEach(ch=>ch.addEventListener('change', recalc));
  }
  recalc();

  async function walletCapture(payload){
    const fd = new FormData();
    fd.append('order_id', String(ORDER_ID));
    if (payload.amount != null) fd.append('amount', String(payload.amount));
    if (payload.carton_ids && payload.carton_ids.length){
      payload.carton_ids.forEach(id => fd.append('carton_ids[]', id));
    }
    const res = await fetch('/api/wallet_capture.php', { method:'POST', body: fd, credentials:'same-origin' });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch(e){ data = null; }
    if (!res.ok || !data || data.ok !== true){
      const msg = (data && (data.error || data.message)) || text.slice(0,180) || ('HTTP '+res.status);
      throw new Error(msg);
    }
    return data;
  }

  async function walletCaptureShipping(payload){
    const fd = new FormData();
    fd.append('order_id', String(ORDER_ID));
    if (payload && payload.amount != null) fd.append('amount', String(payload.amount));
    if (payload && payload.carton_ids && payload.carton_ids.length){
      payload.carton_ids.forEach(id => fd.append('carton_ids[]', id));
    }
    const res = await fetch('/api/wallet_capture_shipping.php', { method:'POST', body: fd, credentials:'same-origin' });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch(e){ data = null; }
    if (!res.ok || !data || data.ok !== true){
      const msg = (data && (data.error || data.message)) || text.slice(0,180) || ('HTTP '+res.status);
      throw new Error(msg);
    }
    return data;
  }

  // Modal controls
  const modalOverlay = document.getElementById('amountModalOverlay');
  const modalInput   = document.getElementById('modalAmount');
  const modalConfirm = document.getElementById('modalConfirm');
  const modalCancel  = document.getElementById('modalCancel');
  let modalResolver;

  function showAmountModal(defaultAmount, maxAmount) {
    return new Promise((resolve) => {
      modalResolver = resolve;
      if (defaultAmount != null) {
        modalInput.value = Number(defaultAmount).toFixed(2);
      } else {
        modalInput.value = '';
      }
      if (maxAmount != null) {
        modalInput.max = Number(maxAmount).toFixed(2);
      } else {
        modalInput.removeAttribute('max');
      }
      modalOverlay.style.display = 'flex';
      setTimeout(() => {
        modalInput.focus();
        modalInput.select();
      }, 50);
    });
  }

  function hideAmountModal() {
    modalOverlay.style.display = 'none';
  }

  modalConfirm.addEventListener('click', () => {
    let value = parseFloat(modalInput.value);
    if (isNaN(value) || value <= 0) {
      modalResolver(null);
    } else {
      modalResolver(value);
    }
    hideAmountModal();
  });

  modalCancel.addEventListener('click', () => {
    modalResolver(null);
    hideAmountModal();
  });

  modalOverlay.addEventListener('click', (ev) => {
    if (ev.target === modalOverlay) {
      if (modalResolver) {
        modalResolver(null);
      }
      hideAmountModal();
    }
  });

  if (walBtn){
    walBtn.addEventListener('click', async (ev)=>{
      ev.preventDefault();
      const val = Number(walAmt?.value || 0);
      if (val <= 0){ toast('Enter a positive amount.', false); return; }
      const maxPay = Math.min(walletBalance, orderDue);
      if (val > maxPay + 1e-9){
        toast('Amount exceeds available (wallet or remaining due).', false); return;
      }
      walBtn.disabled = true;
      try {
        const out = await walletCapture({ amount: val });
        walletBalance = Number(out.wallet_balance ?? (walletBalance - val));
        orderDue      = Number(out.order_due       ?? (orderDue - val));
        if (walBalEl) walBalEl.textContent = fmt(walletBalance);
        if (dueNowEl) dueNowEl.textContent = fmt(orderDue);
        if (walAmt) walAmt.value='';
        toast('Wallet payment successful.');
      } catch(e){
        toast('Wallet payment failed: '+e.message, false);
      } finally { walBtn.disabled = false; }
    });
  }

  if (btnWalSel){
    btnWalSel.addEventListener('click', async (ev)=>{
      ev.preventDefault();
      const ids = payPicks.filter(ch=>ch.checked).map(ch=>ch.value);
      if (!ids.length) return;
      let need = 0;
      payPicks.forEach(ch=>{ if (ch.checked) need += Number(ch.dataset.price||0); });
      const maxSelectable = Math.min(need, walletBalance);
      const enteredAmount = await showAmountModal(need, maxSelectable);
      if (enteredAmount === null) return;
      let amt = enteredAmount;
      if (amt > need) amt = need;
      if (amt > walletBalance) amt = walletBalance;
      if (amt <= 0){ toast('Please enter a positive amount.', false); return; }
      btnWalSel.disabled = true;
      try {
        const out = await walletCaptureShipping({ amount: amt, carton_ids: ids });
        walletBalance = Number(out.wallet_balance ?? walletBalance);
        orderDue      = Number(out.order_due       ?? orderDue);
        if (walBalEl) walBalEl.textContent = fmt(walletBalance);
        if (dueNowEl) dueNowEl.textContent = fmt(orderDue);
        toast('Wallet payment captured for selected cartons.');
        setTimeout(()=>location.reload(), 900);
      } catch(e){
        toast('Wallet payment failed: '+e.message, false);
      } finally { btnWalSel.disabled = false; }
    });
  }

  if (btnReq){
    btnReq.addEventListener('click', async (ev)=>{
      ev.preventDefault();
      const ids = delPicks.filter(ch=>ch.checked).map(ch=>ch.value);
      if (!ids.length) return;
      const fd = new FormData();
      fd.append('order_id', String(ORDER_ID));
      ids.forEach(id => fd.append('carton_ids[]', id));
      try {
        const res = await fetch('/api/delivery_create.php', { method:'POST', body: fd, credentials:'same-origin' });
        const text = await res.text();
        let data; try { data = JSON.parse(text); } catch(e){ data = null; }
        if (!res.ok || !data || !data.ok) { throw new Error((data && (data.error||data.message)) || text.slice(0,180) || ('HTTP '+res.status)); }
        toast('Delivery queued to BD inbound. Ref #'+data.delivery_id);
        setTimeout(()=>location.reload(), 1000);
      } catch(e){
        toast('Request failed: '+e.message, false);
      }
    });
  }

  window.payWalletAll = async function(ev){
    ev.preventDefault();
    try {
      if (walletBalance <= 0){ toast('Nothing due or insufficient wallet balance.', false); return; }
      const enteredAmount = await showAmountModal(walletBalance, walletBalance);
      let amt = enteredAmount;
      if (amt !== null) {
        if (amt > walletBalance) amt = walletBalance;
        if (amt <= 0){ toast('Please enter a positive amount.', false); return; }
      }
      const payload = {};
      if (amt != null) payload.amount = amt;
      const out = await walletCaptureShipping(payload);
      walletBalance = Number(out.wallet_balance ?? walletBalance);
      orderDue      = Number(out.order_due       ?? orderDue);
      if (walBalEl) walBalEl.textContent = fmt(walletBalance);
      if (dueNowEl) dueNowEl.textContent = fmt(orderDue);
      toast('Wallet payment captured for unpaid cartons.');
      setTimeout(()=>location.reload(), 900);
    } catch(e){
      toast('Wallet payment failed: '+e.message, false);
    }
  };
})();
</script>
</body>
</html>