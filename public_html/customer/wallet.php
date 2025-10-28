<?php
// /customer/wallet.php  — Customer wallet: show balance, submit top-ups (with proof), and view history

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

require_once __DIR__ . '/../api/lib.php';
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

/* ===== helpers ===== */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function abort_page($msg, $code=500){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui;margin:40px;color:#0f172a} h1{margin:0 0 8px}</style>
        <h1>Error</h1><p>'.e($msg).'</p>';
  exit;
}
function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table.'|'.$col;
  if (array_key_exists($k,$cache)) return $cache[$k];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$k] = (bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $cache[$k] = false; }
  return $cache[$k];
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
/** Ensure a wallet exists for clerk user, return wallet_id */
function ensure_wallet_for_clerk(PDO $pdo, string $clerkUserId): int {
  if ($clerkUserId === '') return 0;
  $sel = $pdo->prepare("SELECT id FROM customer_wallets WHERE clerk_user_id=? LIMIT 1");
  $sel->execute([$clerkUserId]);
  $wid = (int)($sel->fetchColumn() ?: 0);
  if ($wid > 0) return $wid;

  // create lazily (your schema allows customer_id NULL)
  $ins = $pdo->prepare("INSERT IGNORE INTO customer_wallets (clerk_user_id, currency) VALUES (?, 'USD')");
  $ins->execute([$clerkUserId]);
  $sel->execute([$clerkUserId]);
  return (int)($sel->fetchColumn() ?: 0);
}

/* ===== inputs / context ===== */
$orderId = (int)($_GET['order_id'] ?? 0);

try {
  $pdo = db();

  // Resolve context: order + query + clerk_user_id
  if ($orderId > 0) {
    $s = $pdo->prepare("SELECT o.id AS order_id, o.code, q.id AS query_id, q.query_code, q.clerk_user_id
                          FROM orders o
                          JOIN queries q ON q.id=o.query_id
                         WHERE o.id=? LIMIT 1");
    $s->execute([$orderId]);
    $ctx = $s->fetch(PDO::FETCH_ASSOC);
    if (!$ctx) abort_page('Order not found.', 404);
  } else {
    // Fallback: pick the most recent order for any customer who has clerk_user_id (you can adapt this)
    $ctx = $pdo->query("SELECT o.id AS order_id, o.code, q.id AS query_id, q.query_code, q.clerk_user_id
                          FROM orders o
                          JOIN queries q ON q.id=o.query_id
                         WHERE q.clerk_user_id IS NOT NULL
                      ORDER BY o.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ctx) abort_page('No orders found to resolve your wallet context. Please open wallet from an order.', 400);
    $orderId = (int)$ctx['order_id'];
  }

  $clerkId = (string)($ctx['clerk_user_id'] ?? '');
  if ($clerkId === '') abort_page('This order has no linked customer (clerk_user_id).', 400);

  // Ensure a wallet exists
  $walletId = ensure_wallet_for_clerk($pdo, $clerkId);
  if ($walletId <= 0) abort_page('Failed to create or fetch wallet.', 500);

  // Load active bank accounts for top-ups
  $banks = $pdo->query("SELECT id, account_number, account_name, bank_name, bank_district, bank_branch
                          FROM bank_accounts WHERE is_active=1 ORDER BY bank_name, bank_branch")->fetchAll(PDO::FETCH_ASSOC);
  if (!$banks) abort_page('No active bank accounts available. Please contact support.', 500);

  $errors = [];
  $oknote = '';

  // ===== Handle POST: submit one or multiple top-ups for verification =====
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Like /customer/payment.php, we accept multiple lines
    $bankIds = $_POST['bank_id'] ?? [];
    $amounts = $_POST['amount']  ?? [];

    if (!is_array($bankIds) || !is_array($amounts)) { $errors[]='Invalid submission.'; }

    $lines = [];
    $count = max(count($bankIds), count($amounts));
    for ($i=0;$i<$count;$i++){
      $bid = (int)($bankIds[$i] ?? 0);
      $amt = (float)($amounts[$i] ?? 0);

      // blank row?
      if ($bid <= 0 && $amt <= 0) continue;

      if ($bid <= 0)  $errors[] = "Row ".($i+1).": please select a bank.";
      if ($amt <= 0)  $errors[] = "Row ".($i+1).": amount must be greater than 0.";

      // proof required
      $hasTmp = isset($_FILES['proof']['tmp_name'][$i]) && $_FILES['proof']['tmp_name'][$i] !== '';
      $hasName= isset($_FILES['proof']['name'][$i])     && $_FILES['proof']['name'][$i]     !== '';
      $isValidUpload = $hasTmp && is_uploaded_file($_FILES['proof']['tmp_name'][$i]);
      if (!($hasName && $isValidUpload)) {
        $errors[] = "Row ".($i+1).": proof attachment is required.";
      }

      $lines[] = ['bank_id'=>$bid, 'amount'=>$amt, 'file_idx'=>$i];
    }

    if (!$lines) $errors[] = 'Please add at least one top-up line.';

    // Prepare upload dir
    $uploadDir   = realpath(__DIR__ . '/../public/uploads') ?: (__DIR__ . '/../public/uploads');
    $uploadDir  .= '/payments';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
    $publicBase  = '/public/uploads/payments';

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        foreach ($lines as $idx => $L) {
          $txnCode = generate_txn_code($pdo);

          // move file
          $tmp  = $_FILES['proof']['tmp_name'][$L['file_idx']] ?? '';
          $orig = $_FILES['proof']['name'][$L['file_idx']] ?? '';
          if (!($tmp && $orig && is_uploaded_file($tmp)))
            throw new RuntimeException('Missing proof on row '.($idx+1));

          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          $okExt = ['jpg','jpeg','png','pdf','webp','heic'];
          if (!$ext || !in_array($ext,$okExt,true))
            throw new RuntimeException('Unsupported file type on row '.($idx+1));

          $new  = $txnCode.'_'.time().'.'.$ext;
          $dest = rtrim($uploadDir,'/').'/'.$new;
          if (!@move_uploaded_file($tmp, $dest))
            throw new RuntimeException('Failed to store uploaded file on row '.($idx+1));

          $proofPath = rtrim($publicBase,'/').'/'.$new;

          // Insert as verifying; strict payment_type = wallet_topup
          $ins = $pdo->prepare("
            INSERT INTO order_payments
              (order_id, bank_account_id, txn_code, amount, proof_path, status, payment_type, created_at)
            VALUES
              (?, ?, ?, ?, ?, 'verifying', 'wallet_topup', NOW())
          ");
          // We still bind order_id so Accounts UI (which joins orders) can show it.
          $ins->execute([$orderId, $L['bank_id'], $txnCode, $L['amount'], $proofPath]);

          // Optional: internal note
          if (!empty($ctx['query_id'])) {
            $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', CONCAT('Wallet top-up submitted: ', ?), NOW())")
                ->execute([(int)$ctx['query_id'], $txnCode]);
          }
        }

        $pdo->commit();
        header('Location: '.$_SERVER['REQUEST_URI'].'&ok=1'); exit;
      } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('[wallet_topup_submit] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
        $errors[] = 'Server error while submitting top-up. Please try again.';
      }
    }
  }

  /* ---- balance (from ledger) ---- */
  $balQ = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN entry_type IN ('topup_verified','adjustment_credit','refund') THEN amount ELSE 0 END),0) -
      COALESCE(SUM(CASE WHEN entry_type IN ('charge_shipping_captured','charge_sourcing_captured','adjustment_debit') THEN amount ELSE 0 END),0) AS bal
    FROM wallet_ledger WHERE wallet_id=?
  ");
  $balQ->execute([$walletId]);
  $balance = (float)$balQ->fetchColumn();

  // Recent ledger items
  $ledger = $pdo->prepare("
    SELECT id, entry_type, amount, currency, order_id, carton_id, notes, created_at
      FROM wallet_ledger
     WHERE wallet_id=?
     ORDER BY id DESC LIMIT 50
  ");
  $ledger->execute([$walletId]);
  $ledgerRows = $ledger->fetchAll(PDO::FETCH_ASSOC);

  // Submitted top-ups (from order_payments) for this customer/order context
  $topups = $pdo->prepare("
    SELECT p.*, b.bank_name, b.bank_branch, b.account_name
      FROM order_payments p
      JOIN bank_accounts b ON b.id=p.bank_account_id
     WHERE p.order_id=? AND p.payment_type='wallet_topup'
     ORDER BY p.id DESC
  ");
  $topups->execute([$orderId]);
  $topupRows = $topups->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
  error_log('[wallet_page] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  abort_page('Server error. Please try again later.', 500);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Wallet — Cosmic Trading</title>
<style>
  :root{
    --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --ok:#10b981; --brand:#0ea5e9;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;background:#0f172a;color:#fff}
  header a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:8px 12px;border-radius:10px}
  header a:hover{background:rgba(255,255,255,.08)}
  .container{max-width:1100px;margin:28px auto;padding:0 18px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 6px 14px rgba(2,8,20,.04)}
  .lead{font-size:1.05rem}
  .pill{display:inline-flex;gap:8px;background:#f1f5ff;border:1px solid #e5e7ff;padding:6px 10px;border-radius:999px;margin-right:8px}
  .stat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .stat .blk{background:#fcfcff;border:1px solid var(--line);border-radius:12px;padding:14px}
  .stat .lbl{color:var(--muted);font-size:.9rem;margin-bottom:4px}
  .stat .val{font-weight:700}
  .good{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;border-radius:10px;padding:10px;margin-bottom:10px}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px;margin-bottom:10px}

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
  .btn.add{background:var(--ok)}
  .btn.danger{background:#ef4444}
  .btn.small{padding:7px 10px;font-size:.9rem}

  .tx th,.tx td{padding:10px;border-bottom:1px solid #eef2f7}
  .badge{display:inline-block;padding:.25rem .6rem;border:1px solid #e5e7eb;border-radius:999px;background:#f8fafc;font-size:.78rem}
  .badge.v{border-color:#fde68a;background:#fffbeb;color:#92400e}
  .badge.ok{border-color:#bbf7d0;background:#ecfdf5;color:#065f46}
  .badge.r{border-color:#fecaca;background:#fef2f2;color:#991b1b}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Trading</strong> — Customer Wallet</div>
  <div>
    <a href="/customer/order_details.php?order_id=<?= (int)$orderId ?>">Back to Order</a>
  </div>
</header>

<div class="container">
  <div class="card">
    <h1 class="lead">Wallet for Query <strong><?= e($ctx['query_code'] ?? ('#'.$ctx['query_id'])) ?></strong></h1>
    <div style="margin-top:10px">
      <span class="pill">Wallet ID: <strong class="mono"><?= (int)$walletId ?></strong></span>
      <span class="pill">Customer: <strong class="mono"><?= e($clerkId) ?></strong></span>
    </div>
  </div>

  <div class="card">
    <div class="stat">
      <div class="blk">
        <div class="lbl">Current Balance</div>
        <div class="val">$<?= e(number_format($balance,2)) ?></div>
      </div>
      <div class="blk">
        <div class="lbl">Currency</div>
        <div class="val">USD</div>
      </div>
      <div class="blk">
        <div class="lbl">Context Order</div>
        <div class="val"><?= e($ctx['code'] ?? ('#'.$orderId)) ?></div>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['ok'])): ?>
    <div class="good">Top-up submitted for verification. Accounts will verify and credit your wallet.</div>
  <?php endif; ?>

  <?php foreach ($errors as $eMsg): ?>
    <div class="err"><?= e($eMsg) ?></div>
  <?php endforeach; ?>

  <!-- Corporate bank cards -->
  <div class="card">
    <h2>Top Up — Pay to Our Bank Accounts</h2>
    <p class="meta" style="margin:6px 0 14px">Choose any account(s) to pay. Proof is required for each line.</p>

    <div class="bank-grid">
      <?php foreach ($banks as $b): ?>
        <div class="bank">
          <div class="brand-badge"><?= e(substr($b['bank_name'],0,2)) ?></div>
          <div>
            <h4><?= e($b['bank_name']) ?> — <?= e($b['bank_branch']) ?></h4>
            <div class="meta"><?= e($b['bank_district']) ?></div>

            <div class="rowline">
              <div>
                <div class="meta">Account Name</div>
                <div class="mono"><?= e($b['account_name']) ?></div>
              </div>
              <button class="copy small" type="button" data-copy="<?= e($b['account_name']) ?>">Copy</button>

              <div>
                <div class="meta">Account Number</div>
                <div class="mono"><?= e($b['account_number']) ?></div>
              </div>
              <button class="copy small" type="button" data-copy="<?= e($b['account_number']) ?>">Copy</button>
            </div>
          </div>
          <div>
            <a class="btn small payto" href="#topForm" data-bank-id="<?= (int)$b['id'] ?>">Pay to this</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Top-up form -->
  <div class="card" id="topForm">
    <h2>Submit Top-Up</h2>
    <form method="post" enctype="multipart/form-data">
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
                  <option value="<?= (int)$b['id'] ?>">
                    <?= e($b['bank_name'].' — '.$b['bank_branch'].' — '.$b['account_number']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" name="amount[]" step="0.01" min="0" placeholder="0.00"></td>
            <td><input type="file" name="proof[]" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"></td>
            <td><button type="button" class="btn danger small" onclick="delRow(this)">Remove</button></td>
          </tr>
        </tbody>
      </table>

      <div class="controls">
        <button type="button" class="btn add" onclick="addRow()">+ Add another payment</button>
        <button type="submit" class="btn">Submit for Verification</button>
      </div>
      <div class="meta" style="margin-top:8px">You may split the top-up into multiple banks and submit multiple lines. <strong>Proof is required for each line.</strong></div>
    </form>
  </div>

  <?php if ($topupRows): ?>
    <div class="card">
      <h2>Your Top-Up Submissions</h2>
      <table class="tx">
        <thead>
          <tr><th>Txn ID</th><th>Bank</th><th>Amount</th><th>Status</th><th>Proof</th><th>Submitted</th></tr>
        </thead>
        <tbody>
          <?php foreach ($topupRows as $t): ?>
            <tr>
              <td class="mono"><?= e($t['txn_code']) ?></td>
              <td><?= e($t['bank_name'].' — '.$t['bank_branch'].' ('.$t['account_name'].')') ?></td>
              <td>$<?= e(number_format((float)$t['amount'],2)) ?></td>
              <td>
                <?php
                  $s = strtolower($t['status'] ?? '');
                  $cls = $s==='verified' ? 'ok' : ($s==='rejected' ? 'r' : 'v');
                ?>
                <span class="badge <?= $cls ?>"><?= e($t['status']) ?></span>
              </td>
              <td>
                <?php if (!empty($t['proof_path'])): ?>
                  <a class="copy" style="text-decoration:none" href="<?= e($t['proof_path']) ?>" target="_blank">View</a>
                <?php else: ?>
                  <span class="meta">—</span>
                <?php endif; ?>
              </td>
              <td class="meta"><?= e($t['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Recent Wallet Activity</h2>
    <?php if (!$ledgerRows): ?>
      <div class="meta">No transactions yet.</div>
    <?php else: ?>
      <table class="tx">
        <thead>
          <tr><th>When</th><th>Type</th><th>Amount</th><th>Order</th><th>Carton</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($ledgerRows as $L): ?>
            <tr>
              <td class="meta"><?= e($L['created_at']) ?></td>
              <td class="mono"><?= e($L['entry_type']) ?></td>
              <td class="mono">$<?= e(number_format((float)$L['amount'],2)) ?></td>
              <td><?= $L['order_id'] ? e('#'.$L['order_id']) : '<span class="meta">—</span>' ?></td>
              <td><?= $L['carton_id'] ? e('#'.$L['carton_id']) : '<span class="meta">—</span>' ?></td>
              <td><?= e($L['notes'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>
function addRow(){
  const banks = `<?php
    $opt = '<option value="">— Select bank —</option>';
    foreach ($banks as $b) {
      $opt .= '<option value="'.(int)$b['id'].'">'.e($b['bank_name'].' — '.$b['bank_branch'].' — '.$b['account_number']).'</option>';
    }
    echo $opt;
  ?>`;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select name="bank_id[]">${banks}</select></td>
    <td><input type="number" name="amount[]" step="0.01" min="0" placeholder="0.00"></td>
    <td><input type="file" name="proof[]" accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"></td>
    <td><button type="button" class="btn danger small" onclick="delRow(this)">Remove</button></td>
  `;
  document.querySelector('#lines').appendChild(tr);
  return tr;
}
function delRow(btn){
  const tr = btn.closest('tr');
  const tbody = tr.parentNode;
  if (tbody.children.length > 1) tbody.removeChild(tr);
}

/* Copy helpers */
document.addEventListener('click', (e)=>{
  const tgt = e.target.closest('.copy');
  if (!tgt || !tgt.dataset.copy) return;
  const val = tgt.dataset.copy;
  navigator.clipboard.writeText(val).then(()=>{
    const old = tgt.textContent;
    tgt.textContent = 'Copied';
    setTimeout(()=>{ tgt.textContent = old || 'Copy'; }, 1200);
  }).catch(()=>{});
});

/* Preselect bank from card */
document.addEventListener('click', (e)=>{
  const link = e.target.closest('.payto');
  if (!link) return;
  e.preventDefault();

  const bankId = link.getAttribute('data-bank-id');
  if (!bankId) return;

  const formCard = document.getElementById('topForm');
  if (formCard) formCard.scrollIntoView({behavior:'smooth', block:'start'});

  let select = null, row = null;
  document.querySelectorAll('#lines select[name="bank_id[]"]').forEach(s=>{
    if (!select && (!s.value || s.value === '')) { select = s; row = s.closest('tr'); }
  });

  if (!select) {
    row = addRow();
    select = row.querySelector('select[name="bank_id[]"]');
  }
  if (select) {
    select.value = bankId;
    const amt = row.querySelector('input[name="amount[]"]');
    if (amt) amt.focus();
  }
});
</script>
</body>
</html>
<?php ob_end_flush();
