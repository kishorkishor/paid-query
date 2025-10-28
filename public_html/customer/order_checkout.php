<?php
// customer/order_checkout.php — customer-facing order confirmation & creation

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

require_once __DIR__ . '/../api/lib.php'; // must NOT echo
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n){ return number_format((float)$n, 2, '.', ','); }
function abort_page($msg, $code=500){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui;margin:40px} h1{margin:0 0 8px}</style>
        <h1>Error</h1><p>'.e($msg).'</p>';
  exit;
}

/** Generate a short unique order code: ORD-XXXXXX (Base36 6 chars) */
function generate_unique_order_code(PDO $pdo, int $maxAttempts = 10): string {
  // keep range small enough for 32-bit too
  $min = 36**5;               // 60,466,176
  $max = (36**6) - 1;         // 2,176,782,335
  for ($i = 0; $i < $maxAttempts; $i++) {
    $rand = random_int($min, $max);
    $code = 'ORD-' . strtoupper(substr(base_convert($rand, 10, 36), 0, 6));
    $st = $pdo->prepare("SELECT 1 FROM orders WHERE code=? LIMIT 1");
    $st->execute([$code]);
    if (!$st->fetch()) return $code;
  }
  throw new RuntimeException('Could not generate a unique order code.');
}

/** Check if a table has a column (to avoid SQL errors on missing optional fields) */
function table_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table.'|'.$column;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    error_log("[order_checkout] SHOW COLUMNS failed for $table.$column: ".$e->getMessage());
    $cache[$key] = false;
  }
  return $cache[$key];
}

// ---------- Input & Auth (Clerk JWT) ----------
$queryId = (int)($_GET['query_id'] ?? 0);
$tok     = $_GET['t'] ?? '';
if ($queryId <= 0) abort_page('Bad request: missing query id.', 400);
if (!$tok)          abort_page('Missing token. Please sign in again.', 401);

try {
  $claims = verify_clerk_jwt($tok); // from api/lib.php
} catch (Throwable $e) {
  abort_page('Invalid token: '.$e->getMessage(), 401);
}
$clerkUserId = $claims['sub'] ?? null;
if (!$clerkUserId) abort_page('Invalid token (no subject).', 401);

try {
  $pdo = db();

  // Ensure this query belongs to this Clerk user
  $st = $pdo->prepare("
    SELECT q.*
    FROM queries q
    WHERE q.id = ? AND q.clerk_user_id = ?
    LIMIT 1
  ");
  $st->execute([$queryId, $clerkUserId]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) abort_page('Query not found or you do not have access.', 404);

  // Normalize query type
  $qtRaw     = strtolower(trim((string)($q['query_type'] ?? '')));
  $orderType = in_array($qtRaw, ['sourcing+shipping','shipping+sourcing'], true) ? 'both' : $qtRaw; // 'sourcing' | 'shipping' | 'both'

  // For display (badge): prefer approved_price; fallback to submitted_price
  $badgeApproved = null;
  if (isset($q['approved_price']) && is_numeric($q['approved_price']) && (float)$q['approved_price'] > 0) {
    $badgeApproved = (float)$q['approved_price'];
  } elseif (isset($q['submitted_price']) && is_numeric($q['submitted_price']) && (float)$q['submitted_price'] > 0) {
    $badgeApproved = (float)$q['submitted_price'];
  }

  // Quantities
  $qty = (int)($q['quantity'] ?? 0);
  if ($qty <= 0) $qty = 1;

  // Source-of-truth inputs from query for composing the order
  $productTotalFromQuery = (isset($q['submitted_price']) && is_numeric($q['submitted_price']) && (float)$q['submitted_price'] > 0)
                           ? (float)$q['submitted_price'] : null;
  $shippingPerKgFromQuery = (isset($q['submitted_ship_price']) && is_numeric($q['submitted_ship_price']) && (float)$q['submitted_ship_price'] > 0)
                           ? (float)$q['submitted_ship_price'] : null;

  // Derivatives for display
  $productPerPiece = null;
  if (in_array($orderType, ['sourcing','both'], true) && $productTotalFromQuery !== null && $qty > 0) {
    $productPerPiece = $productTotalFromQuery / $qty;
  }
  $shipPerKg = $shippingPerKgFromQuery;

  // Amount to charge NOW depends on order type:
  // - sourcing: charge product total now
  // - shipping: charge 0 now (pay on delivery)
  // - both:     charge product total now; shipping is paid on delivery
  $amountToPayNow = 0.0;
  if ($orderType === 'sourcing') {
    if ($productTotalFromQuery === null) {
      abort_page('Order cannot be created: product (sourcing) price is missing.', 400);
    }
    $amountToPayNow = (float)$productTotalFromQuery;
  } elseif ($orderType === 'both') {
    if ($productTotalFromQuery === null) {
      abort_page('Order cannot be created: product (sourcing) price is missing.', 400);
    }
    $amountToPayNow = (float)$productTotalFromQuery;
  } else { // shipping only
    $amountToPayNow = 0.0;
  }

  $errors = [];

  // ======================= CREATE ORDER =======================
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agree = isset($_POST['agree']) ? 1 : 0;
    if (!$agree) $errors[] = 'You must agree to the terms and conditions.';

    $quantity     = (int)($q['quantity'] ?? 1);
    if ($quantity <= 0) $quantity = 1;

    // Determine initial order + payment statuses by order type
    if ($orderType === 'shipping') {
      $orderStatus    = 'processing';     // directly processing; no upfront payment
      $paymentStatus  = 'unpaid';
    } else { // sourcing or both
      $orderStatus    = 'pending_payment';
      $paymentStatus  = 'unpaid';
    }

    $amountTotal  = $amountToPayNow;        // what we charge now
    $customerName = (string)($q['customer_name'] ?? '');
    $email        = (string)($q['email'] ?? '');
    $phone        = (string)($q['phone'] ?? '');
    $address      = (string)($q['address'] ?? '');

    if (!$errors) {
      $pdo->beginTransaction();

      $orderCode = generate_unique_order_code($pdo);

      $items = [[
        'product_name' => (string)($q['product_name'] ?? ''),
        'links'        => (string)($q['product_links'] ?? ''),
        'details'      => (string)($q['product_details'] ?? '')
      ]];
      $jsonFlags = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;

      // Build INSERT dynamically based on available columns
      $cols = [
        'code'          => $orderCode,
        'query_id'      => $queryId,
        'customer_name' => $customerName,
        'email'         => $email,
        'phone'         => $phone,
        'address'       => $address,
        'country_id'    => $q['country_id'] ?? null,
        'items_json'    => json_encode($items, $jsonFlags),
        'quantity'      => $quantity,
        'amount_total'  => $amountTotal,                      // amount charged now
        'shipping_mode' => $q['shipping_mode'] ?? null,
        'label_type'    => $q['label_type'] ?? null,
        'carton_count'  => $q['carton_count'] ?? null,
        'cbm'           => $q['cbm'] ?? null,
        'status'        => $orderStatus,
        'payment_status'=> $paymentStatus,
        'created_at'    => date('Y-m-d H:i:s'),
      ];

      // Optional columns present in your DB
      if (table_has_column($pdo, 'orders', 'clerk_user_id')) {
        $cols['clerk_user_id'] = $clerkUserId;
      }
      if (table_has_column($pdo, 'orders', 'order_type')) {
        $cols['order_type'] = $orderType;
      }
      // Persist the prices captured from the query at order time
      if (table_has_column($pdo, 'orders', 'product_price')) {
        $cols['product_price'] = $productTotalFromQuery;     // total product price (sourcing)
      }
      if (table_has_column($pdo, 'orders', 'shipping_price')) {
        $cols['shipping_price'] = $shippingPerKgFromQuery;   // per-kg shipping price
      }
      // >>> Take team + agent from the query so they're NOT NULL in orders
      if (table_has_column($pdo, 'orders', 'current_team_id')) {
        $cols['current_team_id'] = $q['current_team_id'] ?? null;
      }
      if (table_has_column($pdo, 'orders', 'assigned_admin_user_id')) {
        $cols['assigned_admin_user_id'] = $q['assigned_admin_user_id'] ?? null;
      }
      if (table_has_column($pdo, 'orders', 'last_assigned_admin_user_id')) {
        $cols['last_assigned_admin_user_id'] = $q['last_assigned_admin_user_id'] ?? null;
      }

      // Prepare SQL
      $colNames  = array_keys($cols);
      $place     = rtrim(str_repeat('?,', count($cols)), ',');
      $sql       = "INSERT INTO orders (".implode(',', $colNames).") VALUES ($place)";
      $stmt      = $pdo->prepare($sql);
      $stmt->execute(array_values($cols));
      $orderId = (int)$pdo->lastInsertId();

      // Link back & close query
      $pdo->prepare("
        UPDATE queries
           SET status='closed',
               closed_reason='converted_to_order',
               order_id=?,
               updated_at=NOW()
         WHERE id=?
      ")->execute([$orderId, $queryId]);

      // Message log (internal)
      $note = ($orderType === 'shipping')
              ? 'Customer created SHIPPING-ONLY order (no upfront payment).'
              : 'Customer created order and will pay product (sourcing) amount now.';
      $pdo->prepare("
        INSERT INTO messages (query_id, direction, medium, body, created_at)
        VALUES (?, 'internal', 'note', CONCAT(?, ' Order ', ?), NOW())
      ")->execute([$queryId, $note, $orderCode]);

      $pdo->commit();

      header("Location: /customer/order_details.php?order_id={$orderId}&created=1");
      exit;
    }
  }

} catch (Throwable $ex) {
  error_log('[order_checkout] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  abort_page('Server error. Please try again later.', 500);
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Confirm Your Order</title>
<style>
  :root{
    --ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--ok:#10b981;--brand:#0ea5e9;
  }
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f7f8fb;color:var(--ink)}
  .container{max-width:900px;margin:28px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}
  h1{margin:0 0 8px;font-size:1.6rem}
  .badge{display:inline-flex;align-items:center;gap:10px;background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;padding:8px 12px;border-radius:999px;font-weight:700}
  .kv{display:grid;grid-template-columns:220px 1fr;gap:10px 14px}
  label{display:block;margin-bottom:6px;color:#334155}
  input,textarea{width:100%;padding:.7rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  input[readonly], input[disabled]{background:#f8fafc;color:#111}
  .btn{appearance:none;border:1px solid var(--line);background:var(--brand);color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:600}
  .btn:hover{background:#0284c7}
  .err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:10px;padding:10px;margin-bottom:10px}
  .terms{margin-top:12px}
  .checkline{display:flex;align-items:center;gap:10px}
  .checkline input[type=checkbox]{width:18px;height:18px;accent-color:var(--brand);cursor:pointer}
  .muted{color:#64748b}
  .pill{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:4px 10px;font-weight:600;text-transform:capitalize}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>Confirm Your Order</h1>
    <?php if ($badgeApproved !== null): ?>
      <div class="badge">Approved price <span>$<?= e(money_fmt($badgeApproved)) ?></span></div>
    <?php endif; ?>

    <?php foreach ($errors as $eMsg): ?>
      <div class="err"><?= e($eMsg) ?></div>
    <?php endforeach; ?>

    <form method="post">
      <div class="kv" style="margin-top:12px">
        <div>Order Type</div>
        <div><span class="pill"><?= e($orderType ?: '—') ?></span></div>

        <div>Product</div><div><?= e($q['product_name'] ?? '—') ?></div>
        <div>Query Code</div><div><?= e($q['query_code'] ?? ('#'.$q['id'])) ?></div>

        <div><label>Quantity</label></div>
        <div>
          <input type="number" value="<?= e($q['quantity'] ?? 1) ?>" readonly>
          <input type="hidden" name="qty_shadow" value="<?= e($q['quantity'] ?? 1) ?>">
        </div>

        <div>
          <label>Amount to Pay (USD)</label>
          <div class="muted">
            <?php if ($orderType === 'shipping'): ?>
              pay on delivery
            <?php else: ?>
              charged now
            <?php endif; ?>
          </div>
        </div>
        <div>
          <input type="number" value="<?= e($amountToPayNow) ?>" readonly>
          <input type="hidden" name="amount_shadow" value="<?= e($amountToPayNow) ?>">
        </div>

        <?php if ($productPerPiece !== null): ?>
          <div>Product Price (per pc)</div>
          <div>$<?= e(money_fmt($productPerPiece)) ?></div>
        <?php endif; ?>

        <?php if ($shipPerKg !== null && in_array($orderType, ['shipping','both'], true)): ?>
          <div>Shipping Price (per kg)</div>
          <div>$<?= e(money_fmt($shipPerKg)) ?></div>
        <?php endif; ?>

        <div><label>Full Name</label></div>
        <div><input type="text" value="<?= e($q['customer_name'] ?? '') ?>" readonly></div>

        <div><label>Email</label></div>
        <div><input type="email" value="<?= e($q['email'] ?? '') ?>" readonly></div>

        <div><label>Phone</label></div>
        <div><input type="text" value="<?= e($q['phone'] ?? '') ?>" readonly></div>

        <div><label>Delivery Address</label></div>
        <div><textarea rows="3" readonly><?= e($q['address'] ?? '') ?></textarea></div>
      </div>

      <div class="terms">
        <label class="checkline">
          <input type="checkbox" name="agree" value="1">
          <span>I agree to the <a href="/terms" target="_blank">terms and conditions</a>.</span>
        </label>
      </div>

      <div style="margin-top:12px">
        <button class="btn" type="submit">Create Order</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
