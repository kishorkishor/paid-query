<?php
// /customer/carton_payment.php  — Final payment page for Bangladesh per‑kg carton charges
// Allows a customer to pay the total due based on carton weights and price per kg.  Uses the
// existing order_payments table and marks the payment_type as 'bd_final'.

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

require_once __DIR__ . '/../api/lib.php';
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

/** Escape HTML */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Abort helper: outputs a minimal HTML error page */
function abort_page($msg, $code=500){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<style>body{font-family:system-ui;margin:40px;color:#0f172a} h1{margin:0 0 8px}</style>'
       . '<h1>Error</h1><p>'.e($msg).'</p>';
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

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) abort_page('Bad order id.', 400);

try {
  $pdo = db();

  // Load order
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) abort_page('Order not found.', 404);

  // Compute total due based on cartons that require payment
  $dueStmt = $pdo->prepare("SELECT SUM(c.bd_total_price) AS due\n                               FROM inbound_cartons c\n                               JOIN inbound_packing_lists p ON p.id = c.packing_list_id\n                              WHERE p.order_id = ?\n                                AND c.bd_total_price IS NOT NULL\n                                AND c.bd_payment_status = 'pending'");
  $dueStmt->execute([$orderId]);
  $dueRow = $dueStmt->fetch(PDO::FETCH_ASSOC);
  $due = isset($dueRow['due']) ? (float)$dueRow['due'] : 0;

  // Load active bank accounts for selection
  $banks = $pdo->query("SELECT id, account_number, account_name, bank_name, bank_district, bank_branch\n                            FROM bank_accounts WHERE is_active=1 ORDER BY bank_name, bank_branch")
                 ->fetchAll(PDO::FETCH_ASSOC);

  if (!$banks) {
    abort_page('No active bank accounts available. Please contact support.', 500);
  }

  $errors = [];
  $oknote = '';

  // Handle POST submission
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate due; recompute to avoid manipulation
    $dueStmt->execute([$orderId]);
    $dueRow2 = $dueStmt->fetch(PDO::FETCH_ASSOC);
    $due2 = isset($dueRow2['due']) ? (float)$dueRow2['due'] : 0;
    if ($due2 <= 0) {
      $errors[] = 'Nothing due at this time.';
    }

    $bankId = (int)($_POST['bank_id'] ?? 0);
    if ($bankId <= 0) $errors[] = 'Please select a bank.';

    // Require file upload proof
    $hasTmp  = isset($_FILES['proof']['tmp_name']) && $_FILES['proof']['tmp_name'] !== '';
    $hasName = isset($_FILES['proof']['name'])     && $_FILES['proof']['name']     !== '';
    $isValidUpload = $hasTmp && is_uploaded_file($_FILES['proof']['tmp_name']);
    if (!($hasName && $isValidUpload)) {
      $errors[] = 'Proof attachment is required.';
    }

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        $txnCode = generate_txn_code($pdo);

        // Move uploaded file
        $uploadDir   = realpath(__DIR__ . '/../public/uploads') ?: (__DIR__ . '/../public/uploads');
        $uploadDir  .= '/payments';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
        $publicBase  = '/public/uploads/payments';

        $ext  = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        $okExt = ['jpg','jpeg','png','pdf','webp','heic'];
        if (!$ext || !in_array($ext, $okExt, true)) {
          throw new RuntimeException('Unsupported file type.');
        }
        $new  = $txnCode . '_' . time() . '.' . $ext;
        $dest = rtrim($uploadDir,'/').'/'.$new;
        if (!@move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
          throw new RuntimeException('Failed to store uploaded file.');
        }
        $proofPath = rtrim($publicBase,'/').'/'.$new;

        // Insert payment record as verifying, with payment_type bd_final
        $ins = $pdo->prepare("INSERT INTO order_payments\n              (order_id, bank_account_id, txn_code, amount, proof_path, payment_type, status, created_at)\n            VALUES\n              (?, ?, ?, ?, ?, 'bd_final', 'verifying', NOW())");
        $ins->execute([$orderId, $bankId, $txnCode, $due2, $proofPath]);

        // Optional: update order payment_status to verifying
        $pdo->prepare("UPDATE orders SET payment_status='verifying' WHERE id=?")
            ->execute([$orderId]);

        // Log internal note on query (if exists)
        if (!empty($order['query_id'])) {
          $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)\n                         VALUES (?, 'internal', 'note', ?, NOW())")
              ->execute([(int)$order['query_id'], 'Customer submitted final payment for BD cartons.']);
        }

        $pdo->commit();
        // Redirect to same page with success
        header('Location: '.$_SERVER['REQUEST_URI'].'&ok=1');
        exit;
      } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[carton_payment_submit] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
        $errors[] = 'Server error while submitting payment. Please try again.';
      }
    }
  }
} catch (Throwable $ex) {
  error_log('[carton_payment_page] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  abort_page('Server error. Please try again later.', 500);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Pay BD Charges for Order <?= e($order['code'] ?? ('#'.$orderId)) ?></title>
<style>
  :root{
    --ink:#0f172a;
    --muted:#64748b;
    --line:#e5e7eb;
    --bg:#f6f7fb;
    --ok:#10b981;
    --brand:#0ea5e9;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;background:#0f172a;color:#fff}
  header a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:8px 12px;border-radius:10px}
  header a:hover{background:rgba(255,255,255,.08)}
  .container{max-width:700px;margin:28px auto;padding:0 18px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 6px 14px rgba(2,8,20,.04)}
  .card h1{margin:0 0 10px}
  .good{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:10px;padding:10px;margin-bottom:10px}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;margin-bottom:10px}
  input,select{width:100%;padding:.66rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  input[type="file"]{padding:.45rem}
  .btn{appearance:none;border:1px solid var(--line);background:var(--brand);color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:600}
  .btn[disabled]{opacity:.5;cursor:not-allowed}
</style>
</head>
<body>
<header>
  <div><b>Pay BD Charges</b></div>
  <div><a href="/customer/orders.php">Back to Orders</a></div>
</header>
<div class="container">
  <?php if(isset($_GET['ok']) && !$errors): ?>
    <div class="good">Payment submitted for verification. Our Accounts team will verify and update the status.</div>
  <?php endif; ?>
  <?php if($errors): ?>
    <div class="err">
      <?php foreach($errors as $e): echo e($e).'<br/>'; endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="card">
    <h1>BD Charges for Order <?= e($order['code'] ?? ('#'.$orderId)) ?></h1>
    <p>The total due based on your carton weights and price per kg is:</p>
    <h2>$<?= number_format($due,2) ?></h2>
    <?php if($due <= 0): ?>
      <p>No payment is due at this time.</p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data">
        <div style="margin:10px 0">
          <label for="bank_id"><b>Select Bank Account</b></label><br/>
          <select name="bank_id" id="bank_id" required>
            <option value="">-- choose bank --</option>
            <?php foreach($banks as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= e($b['bank_name'].' - '.$b['bank_branch'].' ('.$b['account_name'].')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin:10px 0">
          <label for="proof"><b>Upload Payment Proof</b></label><br/>
          <input type="file" name="proof" id="proof" accept="image/*,.pdf" required />
        </div>
        <button type="submit" class="btn">Submit Payment</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>