<?php
// /customer/payment.php  — Customer payment intake with partial payments and proof uploads
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

require_once __DIR__ . '/../api/lib.php'; // must NOT echo anything
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function abort_page($msg, $code=500){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui;margin:40px;color:#0f172a} h1{margin:0 0 8px}</style>
        <h1>Error</h1><p>'.e($msg).'</p>';
  exit;
}

/** Generate short unique transaction code: TXN-XXXXXX */
function generate_txn_code(PDO $pdo, int $maxAttempts=10): string {
  for ($i=0;$i<$maxAttempts;$i++){
    $rand = strtoupper(substr(base_convert(random_int(36**5, 36**6 - 1), 10, 36), 0, 6));
    $code = 'TXN-' . $rand;
    $st = $pdo->prepare("SELECT 1 FROM order_payments WHERE txn_code=? LIMIT 1");
    $st->execute([$code]);
    if (!$st->fetch()) return $code;
  }
  throw new RuntimeException('Could not generate a unique transaction code.');
}

function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$key] = (bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $cache[$key] = false; }
  return $cache[$key];
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $s = $pdo->prepare("SHOW TABLES LIKE ?");
    $s->execute([$table]);
    return (bool)$s->fetch(PDO::FETCH_NUM);
  } catch (Throwable $e) {
    return false;
  }
}

function orders_has_col(PDO $pdo, string $col): bool { return table_has_col($pdo,'orders',$col); }
function payments_has_col(PDO $pdo, string $col): bool { return table_has_col($pdo,'order_payments',$col); }

/** Does inbound_cartons have BD billing columns? */
function cartons_has_bd_cols(PDO $pdo): bool {
  static $has = null;
  if ($has !== null) return $has;
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM inbound_cartons")->fetchAll(PDO::FETCH_COLUMN);
    $has = in_array('bd_total_price', $cols, true) && in_array('bd_payment_status', $cols, true);
  } catch (Throwable $e) { $has = false; }
  return $has;
}

/** Sum of BD charges still pending for this order */
function carton_bd_due(PDO $pdo, int $orderId): float {
  if (!cartons_has_bd_cols($pdo)) return 0.0;
  $sql = "
    SELECT COALESCE(SUM(c.bd_total_price),0) AS due
      FROM inbound_cartons c
      JOIN inbound_packing_lists p ON p.id = c.packing_list_id
     WHERE p.order_id = ?
       AND COALESCE(c.bd_payment_status,'pending') = 'pending'
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$orderId]);
  return (float)($st->fetchColumn() ?: 0);
}

/** Recalc order status based on verified payments and order_type (legacy behavior preserved) */
function recalc_order_payment_state(PDO $pdo, int $orderId): void {
  $cols = ['id','status','payment_status'];
  if (orders_has_col($pdo, 'order_type'))    $cols[] = 'order_type';
  if (orders_has_col($pdo, 'product_price')) $cols[] = 'product_price';
  if (orders_has_col($pdo, 'shipping_price'))$cols[] = 'shipping_price';

  $sql = "SELECT ".implode(',', $cols)." FROM orders WHERE id=? LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([$orderId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) return;

  $orderType     = strtolower((string)($o['order_type'] ?? ''));
  $productPrice  = isset($o['product_price']) ? (float)$o['product_price'] : null;

  $sum = (float)($pdo->query("SELECT COALESCE(SUM(amount),0) AS s FROM order_payments WHERE order_id=".(int)$orderId." AND status='verified'")
                    ->fetch(PDO::FETCH_ASSOC)['s'] ?? 0);

  $newStatus = null; $newPay = null;
  if (in_array($orderType, ['sourcing','both'], true)) {
    if ($productPrice !== null && $productPrice > 0 && $sum + 1e-9 >= $productPrice) {
      $newStatus = 'paid for sourcing';
      $newPay    = 'paid_for_sourcing';
    }
  }
  if ($newStatus) {
    $pdo->prepare("UPDATE orders SET status=?, payment_status=? WHERE id=?")
        ->execute([$newStatus, $newPay, $orderId]);
    try {
      $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                     SELECT query_id, 'internal', 'note', CONCAT('Auto-update: ', ?, ' (sum verified: $', FORMAT(?,2), ')'), NOW()
                       FROM orders WHERE id=?")
          ->execute([$newStatus, $sum, $orderId]);
    } catch (Throwable $ignore) {}
  }
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) abort_page('Bad order id.', 400);

// bd_final detection via GET or POST (keep old behavior but accept POST too)
$isBdFinal = (isset($_GET['pay']) && strtolower((string)$_GET['pay']) === 'bd_final');
if (isset($_POST['bd_final'])) { $isBdFinal = true; }

try {
  $pdo = db();

  $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) abort_page('Order not found.', 404);

  // Calculate caps for UI + server validation
  $bdDue = $isBdFinal ? carton_bd_due($pdo, $orderId) : 0.0;
  $orderAmountDue = (float)($order['amount_due'] ?? 0);
  $maxPayable = $isBdFinal ? $bdDue : $orderAmountDue;

  $q = null;
  if (!empty($order['query_id'])) {
    $s2 = $pdo->prepare("SELECT id, query_code FROM queries WHERE id=?");
    $s2->execute([$order['query_id']]);
    $q = $s2->fetch(PDO::FETCH_ASSOC);
  }

  $banks = $pdo->query("SELECT id, account_number, account_name, bank_name, bank_district, bank_branch
                        FROM bank_accounts WHERE is_active=1 ORDER BY bank_name, bank_branch")->fetchAll(PDO::FETCH_ASSOC);
  if (!$banks) abort_page('No active bank accounts available. Please contact support.', 500);

  $errors = []; $oknote = '';

  // --- Capture selected cartons (when this page is reached from order_details.php) ---
  $paymentType = ($isBdFinal ? 'bd_final' : null);
  if (isset($_POST['bd_final'])) { $paymentType = 'bd_final'; }
  $submittedCartonIds = [];
  if (!empty($_POST['carton_ids']) && is_array($_POST['carton_ids'])) {
    foreach ($_POST['carton_ids'] as $cid) {
      $cid = (int)$cid; if ($cid > 0) $submittedCartonIds[] = $cid;
    }
  }
  $appliedCartonsJson = $submittedCartonIds ? json_encode($submittedCartonIds) : null;
  $appliedOrderId = $orderId;

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bankIds = $_POST['bank_id'] ?? [];
    $amounts = $_POST['amount']  ?? [];

    if (!is_array($bankIds) || !is_array($amounts)) $errors[] = 'Invalid submission.';

    $lines = [];
    $count = max(count($bankIds), count($amounts));
    $running = 0.0;
    for ($i=0; $i<$count; $i++) {
      $bid = (int)($bankIds[$i] ?? 0);
      $amt = (float)($amounts[$i] ?? 0);
      if ($bid <= 0 && $amt <= 0) continue;

      if ($bid <= 0)  { $errors[] = "Row ".($i+1).": please select a bank."; }
      if ($amt <= 0) { $errors[] = "Row ".($i+1).": amount must be greater than 0."; }

      // SERVER-SIDE CAP: per-row and cumulative must not exceed maxPayable
      if ($maxPayable > 0) {
        if ($amt - 1e-9 > $maxPayable) {
          $errors[] = "Row ".($i+1).": amount exceeds payable limit ($".number_format($maxPayable,2).").";
        }
        $running += $amt;
        if ($running - 1e-9 > $maxPayable) {
          $allowedLeft = max(0, $maxPayable - ($running - $amt));
          $errors[] = "Total exceeds payable amount. You can pay up to $".number_format($allowedLeft,2)." more.";
        }
      }

      $hasTmp = isset($_FILES['proof']['tmp_name'][$i]) && $_FILES['proof']['tmp_name'][$i] !== '';
      $hasName= isset($_FILES['proof']['name'][$i])     && $_FILES['proof']['name'][$i]     !== '';
      $isValidUpload = $hasTmp && is_uploaded_file($_FILES['proof']['tmp_name'][$i]);
      if (!($hasName && $isValidUpload)) {
        $errors[] = "Row ".($i+1).": proof attachment is required.";
      }

      $lines[] = ['bank_id'=>$bid,'amount'=>$amt,'file_idx'=>$i];
    }
    if (!$lines) $errors[] = 'Please add at least one payment line.';

    $uploadDir   = realpath(__DIR__ . '/../public/uploads') ?: (__DIR__ . '/../public/uploads');
    $uploadDir  .= '/payments';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
    $publicBase  = '/public/uploads/payments';

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        $hasPaymentType       = payments_has_col($pdo, 'payment_type');
        $hasAppliedOrder      = payments_has_col($pdo, 'applied_order_id');
        $hasAppliedCartonIds  = payments_has_col($pdo, 'applied_carton_ids');
        $hasPayCartonLinkTbl  = table_exists($pdo, 'order_payment_cartons');

        foreach ($lines as $idx => $L) {
          $txnCode = generate_txn_code($pdo);

          $tmp  = $_FILES['proof']['tmp_name'][$L['file_idx']] ?? '';
          $orig = $_FILES['proof']['name'][$L['file_idx']] ?? '';
          if (!($tmp && $orig && is_uploaded_file($tmp))) {
            throw new RuntimeException('Missing proof on row '.($idx+1));
          }
          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          $okExt = ['jpg','jpeg','png','pdf','webp','heic'];
          if (!$ext || !in_array($ext,$okExt,true)) {
            throw new RuntimeException('Unsupported file type on row '.($idx+1));
          }
          $new  = $txnCode . '_' . time() . '.' . $ext;
          $dest = rtrim($uploadDir,'/').'/'.$new;
          if (!@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Failed to store uploaded file on row '.($idx+1));
          }
          $proofPath = rtrim($publicBase,'/').'/'.$new;

          if ($hasPaymentType && $hasAppliedOrder && $isBdFinal) {
            // STRICT binding: bd_final must be bound to this order via applied_order_id
            if ($hasAppliedCartonIds) {
              $ins = $pdo->prepare("
                INSERT INTO order_payments
                  (order_id, applied_order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type, applied_carton_ids, created_at)
                VALUES
                  (?,        ?,                ?,               ?,        ?,      ?,          'verifying', 'bd_final', ?,                 NOW())
              ");
              $ins->execute([$orderId, $orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath, $appliedCartonsJson]);
            } else {
              $ins = $pdo->prepare("
                INSERT INTO order_payments
                  (order_id, applied_order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type, created_at)
                VALUES
                  (?,        ?,                ?,               ?,        ?,      ?,          'verifying', 'bd_final', NOW())
              ");
              $ins->execute([$orderId, $orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath]);
            }

            // Optional mapping rows for analytics
            if ($hasPayCartonLinkTbl && !empty($submittedCartonIds)) {
              $pid = (int)$pdo->lastInsertId();
              $rowIns = $pdo->prepare("INSERT IGNORE INTO order_payment_cartons (payment_id, carton_id, amount, created_at)
                                       VALUES (?, ?, 0.00, NOW())");
              foreach ($submittedCartonIds as $cid) { $rowIns->execute([$pid, (int)$cid]); }
            }

          } else if ($hasPaymentType) {
            // Legacy deposit (or other) — retain behavior
            if ($hasAppliedCartonIds) {
              $ins = $pdo->prepare("
                INSERT INTO order_payments
                  (order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type, applied_carton_ids, created_at)
                VALUES
                  (?,        ?,               ?,        ?,      ?,          'verifying', ?,            ?,                 NOW())
              ");
              $ins->execute([$orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath, 'deposit', $appliedCartonsJson]);
            } else {
              $ins = $pdo->prepare("
                INSERT INTO order_payments
                  (order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type, created_at)
                VALUES
                  (?,        ?,               ?,        ?,      ?,          'verifying', ?,            NOW())
              ");
              $ins->execute([$orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath, 'deposit']);
            }
          } else {
            // Very old schema, fallback
            $ins = $pdo->prepare("
              INSERT INTO order_payments
                (order_id, bank_account_id, txn_code, amount, proof_path, status, created_at)
              VALUES
                (?,        ?,               ?,        ?,      ?,          'verifying', NOW())
            ");
            $ins->execute([$orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath]);
          }
        }

        if (!$isBdFinal) {
          $orderType = strtolower((string)($order['order_type'] ?? ''));
          if ($orderType === 'shipping') {
            $pdo->prepare("UPDATE orders SET payment_status='verifying' WHERE id=?")->execute([$orderId]);
          } else {
            $pdo->prepare("UPDATE orders SET status='verifying_payment', payment_status='verifying' WHERE id=?")->execute([$orderId]);
          }
        }

        if (!empty($order['query_id'])) {
          $pdo->prepare("
            INSERT INTO messages (query_id, direction, medium, body, created_at)
            VALUES (?, 'internal', 'note',
              ?, NOW())
          ")->execute([$order['query_id'], $isBdFinal ? 'Customer submitted BD charges payment for verification.' : 'Customer submitted payment for verification.']);
        }

        $pdo->commit();
        $oknote = $isBdFinal
          ? 'Your BD charges payment has been submitted for verification. Delivery will be enabled once verified by Accounts.'
          : 'Payment submitted for verification. Our Accounts team will verify and update the status.';
        $redir = $_SERVER['REQUEST_URI'];
        $redir .= (strpos($redir, '?') === false ? '?' : '&') . 'ok=1';
        header('Location: '.$redir);
        exit;

      } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[payment_submit] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->Line());
        $errors[] = 'Server error while submitting payment. Please try again.';
      }
    }
  }

  $tx = $pdo->prepare("SELECT p.*, b.bank_name, b.bank_branch, b.account_name
                         FROM order_payments p
                         JOIN bank_accounts b ON b.id=p.bank_account_id
                        WHERE p.order_id=?
                        ORDER BY p.id DESC");
  $tx->execute([$orderId]);
  $transactions = $tx->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
  error_log('[payment_page] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  abort_page('Server error. Please try again later.', 500);
}

$prefillAmount = ($isBdFinal && $bdDue > 0) ? number_format($bdDue, 2, '.', '') : '';
$displayAmount = $isBdFinal ? (float)$bdDue : (float)($order['amount_due'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Pay for Order <?= e($order['code'] ?? ('#'.$orderId)) ?></title>
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--bg:#f6f7fb;--ok:#10b981;--brand:#0ea5e9}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;background:#0f172a;color:#fff}
  header a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:8px 12px;border-radius:10px}
  header a:hover{background:rgba(255,255,255,.08)}
  .container{max-width:1100px;margin:28px auto;padding:0 18px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 6px 14px rgba(2,8,20,.04)}
  .card h1,.card h2{margin:0 0 10px}
  .lead{font-size:1.05rem}
  .pill{display:inline-flex;gap:8px;background:#f1f5ff;border:1px solid #e5e7ff;padding:6px 10px;border-radius:999px;margin-right:8px}
  .stat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .stat .blk{background:#fcfcff;border:1px solid var(--line);border-radius:12px;padding:14px}
  .stat .lbl{color:var(--muted);font-size:.9rem;margin-bottom:4px}
  .stat .val{font-weight:700}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;margin-bottom:10px}
  .good{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:10px;padding:10px;margin-bottom:10px}
  .bank-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
  @media (max-width:900px){ .bank-grid{grid-template-columns:1fr} }
  .bank{border:1px solid var(--line);border-radius:14px;padding:14px;display:grid;grid-template-columns:56px 1fr auto;gap:12px;align-items:center;background:#fff}
  .bank:hover{box-shadow:0 8px 16px rgba(15,23,42,.06)}
  .brand-badge{width:56px;height:56px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#eef6ff;border:1px solid #dbeafe;font-weight:800;color:#1d4ed8}
  .bank h4{margin:0 0 4px;font-size:1rem}
  .meta{color:var(--muted);font-size:.92rem}
  .rowline{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:600;letter-spacing:.3px}
  .copy{border:1px solid var(--line);background:#fff;padding:6px 10px;border-radius:8px;cursor:pointer;font-size:.86rem;color:#0f172a}
  .copy:hover{background:#f8fafc}
  table{width:100%;border-collapse:separate;border-spacing:0 8px}
  thead th{font-weight:600;color:#0f172a;padding:10px 12px;border-bottom:1px solid var(--line);background:#f8fafc;border-top-left-radius:10px;border-top-right-radius:10px}
  tbody td{padding:10px 12px;background:#fff;border:1px solid var(--line);border-top:none;border-bottom-left-radius:10px;border-bottom-right-radius:10px}
  tbody tr td:first-child{border-top-left-radius:10px;border-bottom-left-radius:10px}
  tbody tr td:last-child{border-top-right-radius:10px;border-bottom-right-radius:10px}
  input,select{width:100%;padding:.66rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  input[type="file"]{padding:.45rem}
  .controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .btn{appearance:none;border:1px solid var(--line);background:var(--brand);color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:600}
  .btn:hover{filter:brightness(.95)}
  .btn.outline{background:#fff;color:#0f172a}
  .btn.add{background:var(--ok)}
  .btn.danger{background:#ef4444}
  .btn.small{padding:7px 10px;font-size:.9rem}
  .tx th,.tx td{padding:10px;border-bottom:1px solid #eef2f7}
  .badge{display:inline-block;padding:.25rem .6rem;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;font-size:.78rem}
  .badge.v{border-color:#fde68a;background:#fffbeb;color:#92400e}
  .badge.ok{border-color:#bbf7d0;background:#ecfdf5;color:#065f46}
  .badge.r{border-color:#fecaca;background:#fef2f2;color:#991b1b}
  /* Toast */
  #toast{position:fixed;right:20px;bottom:20px;z-index:9999;display:none;max-width:360px;background:#0f172a;color:#fff;padding:12px 14px;border-radius:12px;box-shadow:0 10px 24px rgba(2,8,20,.25)}
  #toast.show{display:block;animation:fadein .18s ease-out}
  @keyframes fadein{from{transform:translateY(6px);opacity:.6}to{transform:translateY(0);opacity:1}}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Trading</strong> — Customer</div>
  <div><a href="/customer/order_details.php?order_id=<?= (int)$orderId ?>">Back to Order</a></div>
</header>

<div id="toast"></div>

<div class="container">
  <div class="card">
    <h1 class="lead">
      <?= $isBdFinal ? 'Pay BD Delivery Charges for Order ' : 'Pay for Order ' ?>
      <strong><?= e($order['code'] ?? ('#'.$orderId)) ?></strong>
    </h1>
    <div class="meta">
      From Query <strong><?= e($q['query_code'] ?? ('#'.$order['query_id'])) ?></strong> ·
      Created <strong><?= e($order['created_at'] ?? '') ?></strong>
    </div>
    <div style="margin-top:10px">
      <span class="pill">Status: <strong><?= e($order['status'] ?? '-') ?></strong></span>
      <span class="pill">Payment: <strong><?= e($order['payment_status'] ?? '-') ?></strong></span>
      <?php if ($displayAmount > 0): ?>
        <span class="pill">Max Payable: <strong>$<?= e(number_format($displayAmount,2)) ?></strong></span>
      <?php endif; ?>
    </div>

    <?php if ($isBdFinal): ?>
      <div style="margin-top:12px;padding:10px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb">
        <strong>Bangladesh delivery charges</strong> — You’re paying the final per-kg carton charges.
        <?php if ($bdDue > 0): ?>
          <div class="meta" style="margin-top:6px">Total BD charges due now: <strong>$<?= e(number_format($bdDue,2)) ?></strong></div>
        <?php else: ?>
          <div class="meta" style="margin-top:6px">No BD charges are currently outstanding.</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="stat">
      <div class="blk">
        <div class="lbl"><?= $isBdFinal ? 'Amount Due (USD)' : 'Amount (USD)' ?></div>
        <div class="val">$<?= e(number_format($displayAmount, 2)) ?></div>
      </div>
      <div class="blk">
        <div class="lbl">Quantity</div>
        <div class="val"><?= e($order['quantity']) ?></div>
      </div>
      <div class="blk">
        <div class="lbl">Shipping</div>
        <div class="val"><?= e($order['shipping_mode'] ?? '-') ?></div>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['ok'])): ?>
    <div class="good">
      <?= $isBdFinal
        ? 'Your BD charges payment has been submitted for verification. Delivery will be enabled once verified by Accounts.'
        : 'Payment submitted for verification. Our Accounts team will verify and update the status.' ?>
    </div>
  <?php endif; ?>

  <?php foreach ($errors as $eMsg): ?>
    <div class="err"><?= e($eMsg) ?></div>
  <?php endforeach; ?>

  <!-- ✅ Our Bank Accounts (kept exactly as your UI) -->
  <div class="card">
    <h2>Our Bank Accounts</h2>
    <p class="meta" style="margin:6px 0 14px">Choose any account(s) to pay. Click the copy buttons to copy details.</p>
    <div class="bank-grid">
      <?php foreach ($banks as $b): ?>
        <div class="bank">
          <div class="brand-badge"><?= e(substr($b['bank_name'],0,2)) ?></div>
          <div>
            <h4><?= e($b['bank_name']) ?> — <?= e($b['bank_branch']) ?></h4>
            <div class="meta"><?= e($b['bank_district']) ?></div>
            <div class="rowline">
              <div><div class="meta">Account Name</div><div class="mono"><?= e($b['account_name']) ?></div></div>
              <button class="copy small" type="button" data-copy="<?= e($b['account_name']) ?>">Copy</button>
              <div><div class="meta">Account Number</div><div class="mono"><?= e($b['account_number']) ?></div></div>
              <button class="copy small" type="button" data-copy="<?= e($b['account_number']) ?>">Copy</button>
            </div>
          </div>
          <div><a class="btn outline small payto" href="#payForm" data-bank-id="<?= (int)$b['id'] ?>">Pay to this</a></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ✅ Submit Payment with caps + toast -->
  <div class="card" id="payForm" data-max="<?= e(number_format($displayAmount, 2, '.', '')) ?>">
    <h2>Submit Payment</h2>
    <form method="post" enctype="multipart/form-data" id="paymentForm">
      <input type="hidden" id="MAX_DUE" value="<?= e(number_format($displayAmount, 2, '.', '')) ?>">
      <table>
        <thead>
          <tr>
            <th style="width:40%">Bank Account</th>
            <th style="width:20%">Amount (USD)</th>
            <th style="width:28%">Proof (image/pdf) <span class="meta">— required</span></th>
            <th style="width:12%"></th>
          </tr>
        </thead>
        <tbody id="lines">
          <tr>
            <td>
              <select name="bank_id[]">
                <option value="">— Select bank —</option>
                <?php foreach ($banks as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"><?= e($b['bank_name'].' — '.$b['bank_branch'].' — '.$b['account_number']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" class="amt" name="amount[]" step="0.01" min="0" placeholder="0.00" value="<?= e($prefillAmount) ?>"></td>
            <td><input type="file" name="proof[]" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"></td>
            <td><button type="button" class="btn danger small" onclick="delRow(this)">Remove</button></td>
          </tr>
        </tbody>
      </table>

      <div class="controls">
        <button type="button" class="btn add" onclick="addRow()">+ Add another payment</button>
        <button type="submit" class="btn">Submit for Verification</button>
      </div>
      <div class="meta" style="margin-top:8px">
        You may split the total into multiple banks and submit multiple lines.
        <strong>Proof is required for each line.</strong>
        <?php if ($isBdFinal): ?> <br>Tip: The first line is prefilled with your BD charges due. <?php endif; ?>
        <br><span id="remainHint"></span>
      </div>
    </form>
  </div>

  <?php if ($transactions): ?>
    <div class="card">
      <h2>Your Submitted Payments</h2>
      <table class="tx">
        <thead><tr><th>Txn ID</th><th>Bank</th><th>Amount</th><th>Status</th><th>Proof</th><th>Submitted</th></tr></thead>
        <tbody>
          <?php foreach ($transactions as $t): ?>
            <tr>
              <td class="mono"><?= e($t['txn_code']) ?></td>
              <td><?= e($t['bank_name'].' — '.$t['bank_branch'].' ('.$t['account_name'].')') ?></td>
              <td>$<?= e(number_format((float)$t['amount'],2)) ?></td>
              <td>
                <?php $s = strtolower($t['status'] ?? ''); $cls = $s==='verified' ? 'ok' : ($s==='rejected' ? 'r' : 'v'); ?>
                <span class="badge <?= $cls ?>"><?= e($t['status']) ?></span>
              </td>
              <td><?php if (!empty($t['proof_path'])): ?><a class="copy" style="text-decoration:none" href="<?= e($t['proof_path']) ?>" target="_blank">View</a><?php else: ?><span class="meta">—</span><?php endif; ?></td>
              <td class="meta"><?= e($t['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>

<script>
function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(showToast._timer);
  showToast._timer = setTimeout(()=> t.classList.remove('show'), 2500);
}

function addRow(){
  const banks = `<?php
    $opt = '<option value="">— Select bank —</option>';
    foreach ($banks as $b) { $opt .= '<option value="'.(int)$b['id'].'">'.e($b['bank_name'].' — '.$b['bank_branch'].' — '.$b['account_number']).'</option>'; }
    echo $opt;
  ?>`;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select name="bank_id[]">${banks}</select></td>
    <td><input type="number" class="amt" name="amount[]" step="0.01" min="0" placeholder="0.00"></td>
    <td><input type="file" name="proof[]" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"></td>
    <td><button type="button" class="btn danger small" onclick="delRow(this)">Remove</button></td>
  `;
  document.querySelector('#lines').appendChild(tr);
  bindAmt(tr.querySelector('.amt'));
  updateRemaining();
  return tr;
}
function delRow(btn){
  const tr = btn.closest('tr'); const tbody = tr.parentNode;
  if (tbody.children.length > 1) tbody.removeChild(tr);
  updateRemaining();
}
document.addEventListener('click', (e)=>{
  const tgt = e.target.closest('.copy'); if (!tgt || !tgt.dataset.copy) return;
  const val = tgt.dataset.copy;
  navigator.clipboard.writeText(val).then(()=>{
    const old = tgt.textContent; tgt.textContent = 'Copied';
    setTimeout(()=>{ tgt.textContent = old || 'Copy'; }, 1200);
  });
});
document.addEventListener('click', (e)=>{
  const link = e.target.closest('.payto'); if (!link) return; e.preventDefault();
  const bankId = link.getAttribute('data-bank-id'); if (!bankId) return;
  document.getElementById('payForm')?.scrollIntoView({behavior:'smooth', block:'start'});
  let select = null, row = null;
  document.querySelectorAll('#lines select[name="bank_id[]"]').forEach(s=>{
    if (!select && (!s.value || s.value === '')) { select = s; row = s.closest('tr'); }
  });
  if (!select) { row = addRow(); select = row.querySelector('select[name="bank_id[]"]'); }
  if (select) { select.value = bankId; row.querySelector('input[name="amount[]"]')?.focus(); }
});

// ---- Amount capping (per-line and total) with toast ----
const MAX = parseFloat(document.getElementById('MAX_DUE').value || '0') || 0;

function round2(n){ return Math.max(0, Math.floor(n * 100 + 0.5) / 100); }

function currentTotal(){
  let sum = 0;
  document.querySelectorAll('#lines .amt').forEach(inp=>{
    const v = parseFloat(inp.value || '0');
    if (!isNaN(v)) sum += v;
  });
  return round2(sum);
}

function updateRemaining(){
  if (!MAX) { document.getElementById('remainHint').textContent = ''; return; }
  const left = round2(MAX - currentTotal());
  document.getElementById('remainHint').textContent = `Remaining you can pay now: $${left.toFixed(2)} (Max: $${MAX.toFixed(2)})`;
}

function bindAmt(inp){
  if (!inp) return;
  inp.addEventListener('input', ()=>{
    if (!MAX) return updateRemaining();

    // cap per-line by remaining
    const others = currentTotal() - (parseFloat(inp.value||'0')||0);
    const left = round2(MAX - others);
    let val = parseFloat(inp.value || '0') || 0;

    if (val > left + 1e-9) {
      inp.value = left.toFixed(2);
      showToast(`Amount adjusted. You can pay up to $${left.toFixed(2)} in this line.`);
    } else {
      if (inp.value && /^-?\d+(\.\d{3,})$/.test(inp.value)) {
        inp.value = val.toFixed(2);
      }
    }
    updateRemaining();
  });
}

document.querySelectorAll('#lines .amt').forEach(bindAmt);
updateRemaining();

document.getElementById('paymentForm').addEventListener('submit', (e)=>{
  if (!MAX) return;

  const total = currentTotal();
  if (total - 1e-9 > MAX) {
    e.preventDefault();
    showToast(`Total exceeds allowed amount ($${MAX.toFixed(2)}). Please reduce by $${(total-MAX).toFixed(2)}.`);
  } else if (total <= 0) {
    e.preventDefault();
    showToast('Please enter a valid amount greater than $0.00.');
  }
});
</script>
</body>
</html>
<?php ob_end_flush();
