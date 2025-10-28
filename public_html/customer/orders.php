<?php
// /customer/orders.php — Customer’s order list (token-protected)

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
require_once __DIR__ . '/../api/lib.php';   // must not echo anything

// Clean any stray output buffers very early
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function abort_page($msg,$code=401){
  http_response_code($code);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui;margin:40px;color:#111}</style>
        <h2>Error</h2><p>'.e($msg).'</p>';
  exit;
}

// --- Verify Clerk token (same as customer/query.php) ---
$tok = $_GET['t'] ?? '';
if ($tok === '') abort_page('Missing token. Please open this page from the customer portal link.');

try {
  $claims = verify_clerk_jwt($tok);
} catch (Throwable $e) {
  abort_page('Invalid token: '.$e->getMessage(), 401);
}
$clerkId = (string)($claims['sub'] ?? '');
if ($clerkId === '') abort_page('Invalid token (no sub).', 401);

$emailFromToken = (string)($claims['email'] ?? '');

// --- Fetch orders for this customer ---
try {
  $pdo = db();

  // Primary: join orders → queries using query_id and filter by the *same* clerk user
  $st = $pdo->prepare("
    SELECT o.*
      FROM orders o
 LEFT JOIN queries q ON q.id = o.query_id
     WHERE q.clerk_user_id = ?
  ORDER BY o.id DESC
  ");
  $st->execute([$clerkId]);
  $orders = $st->fetchAll(PDO::FETCH_ASSOC);

  // Fallback: if nothing by clerk_user_id, try by email from token (in case of legacy rows)
  if (!$orders && $emailFromToken !== '') {
    $st2 = $pdo->prepare("SELECT * FROM orders WHERE email = ? ORDER BY id DESC");
    $st2->execute([$emailFromToken]);
    $orders = $st2->fetchAll(PDO::FETCH_ASSOC);
  }

} catch (Throwable $e) {
  error_log('[orders_list] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
  abort_page('Server error. Please try again later.', 500);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>My Orders — Cosmic Trading</title>
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--bg:#f7f8fb;--pill:#eef2ff}
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#0f172a;color:#fff}
  .container{max-width:1100px;margin:28px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:14px}
  h2{margin:0 0 10px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top}
  .pill{display:inline-flex;gap:6px;padding:4px 8px;border-radius:999px;background:#eef;border:1px solid #dde}
  .btn{display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:8px 12px;border-radius:10px;font-weight:600}
  .btn:hover{background:#0284c7}
  .muted{color:var(--muted)}
  .empty{padding:18px;border:1px dashed #e2e8f0;border-radius:12px;background:#fafafa}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Trading</strong> — Customer</div>
  <div><a href="/index.html" style="color:#fff;text-decoration:none">Dashboard</a></div>
</header>

<div class="container">
  <div class="card">
    <h2>My Orders</h2>
    <?php if (!$orders): ?>
      <div class="empty">No orders found for your account yet.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Order Code</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Payment</th>
            <th>Created</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><strong><?= e($o['code'] ?: ('#'.$o['id'])) ?></strong></td>
            <td>$<?= e(number_format((float)$o['amount_total'],2)) ?></td>
            <td><span class="pill"><?= e($o['status']) ?></span></td>
            <td><span class="pill"><?= e($o['payment_status']) ?></span></td>
            <td><?= e($o['created_at'] ?? '') ?></td>
            <td>
              <a class="btn" href="/customer/order_details.php?order_id=<?= (int)$o['id'] ?>&t=<?= urlencode($tok) ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="muted" style="margin-top:8px">Signed in via token for user: <strong><?= e($clerkId) ?></strong></div>
  </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
