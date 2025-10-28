<?php
// /app/bd_delivery_supervisor.php — BD Inbound • Supervisor (Approve cartons for delivery)

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/_php_errors.log');

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth & DB
require_once __DIR__.'/config.php';
require_once __DIR__.'/auth.php';
if (function_exists('require_login')) { require_login(); }
if (function_exists('require_perm')) {
  @require_perm('bd_inbound_access');
  @require_perm('bd_inbound_supervisor');
}

$pdo = db();

$msg = '';
$msgOk = false;

/** Generate a unique six-digit OTP code for inbound cartons */
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

// POST: Approve selected cartons (change preparing -> ready for delivery + generate OTP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
  $ids = array_filter(array_map('intval', $_POST['ids']));
  if (!empty($ids)) {
    try {
      $pdo->beginTransaction();
      
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      
      // First, get cartons that are "preparing for delivery"
      $selectSql = "SELECT id FROM inbound_cartons 
                    WHERE id IN ($placeholders) 
                    AND (bd_delivery_status LIKE '%preparing%' OR delivery_status LIKE '%preparing%')";
      $stmt = $pdo->prepare($selectSql);
      $stmt->execute($ids);
      $validCartons = $stmt->fetchAll(PDO::FETCH_COLUMN);
      
      $affected = 0;
      $otpGenerated = 0;
      
      // Update each carton with status and OTP
      foreach ($validCartons as $cartonId) {
        // Generate unique OTP
        $otp = generate_unique_carton_otp($pdo);
        
        // Update carton with new status and OTP
        $updateStmt = $pdo->prepare("
          UPDATE inbound_cartons 
          SET bd_delivery_status = 'ready for delivery',
              delivery_status = 'ready for delivery',
              otp_code = ?,
              otp_generated_at = NOW()
          WHERE id = ?
        ");
        $updateStmt->execute([$otp, $cartonId]);
        
        if ($updateStmt->rowCount() > 0) {
          $affected++;
          $otpGenerated++;
        }
      }
      
      $pdo->commit();
      
      if ($affected > 0) {
        $msg = "Successfully approved $affected carton(s) for delivery and generated $otpGenerated OTP code(s).";
        $msgOk = true;
      } else {
        $msg = "No cartons were approved. Please ensure selected cartons are in 'preparing for delivery' status.";
        $msgOk = false;
      }
      
    } catch (Throwable $e) {
      $pdo->rollBack();
      $msg = 'Error approving cartons: ' . $e->getMessage();
      $msgOk = false;
      error_log('[bd_delivery_supervisor APPROVE] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
    }
  }
}

// ------------ helpers ------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = "$table|$col";
  if (array_key_exists($k, $cache)) return $cache[$k];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$k] = (bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $cache[$k] = false;
  }
  return $cache[$k];
}
/** Collapse any status string to a stable token we can filter on */
function status_token(?string $raw): string {
  $s = strtolower(trim((string)$raw));
  if ($s === '') return 'empty';
  
  // Match "preparing for delivery" or any variation with "prepar"
  if (str_contains($s,'preparing') || strpos($s,'prepar') !== false) return 'preparing';
  
  // Match "ready" variations
  if (str_contains($s,'ready') || strpos($s,'ready') !== false) return 'ready';
  
  // Match "queued" or "queue"
  if (str_contains($s,'queue') || strpos($s,'queue') !== false) return 'queued';
  
  // Match "pending" or "pend"
  if (str_contains($s,'pend') || strpos($s,'pend') !== false) return 'pending';
  
  // Match "rejected" or "reject"
  if (str_contains($s,'reject') || strpos($s,'reject') !== false) return 'rejected';
  
  // Match "verified" or "verify"
  if (str_contains($s,'verif') || strpos($s,'verify') !== false) return 'verified';
  
  // Match "expected" or "expect"
  if (str_contains($s,'expect') || strpos($s,'expect') !== false) return 'expected';
  
  return $s; // fallback (use exact lowercase)
}

// ------------ probe columns (defensive) ------------
$has_delivery_status    = has_col($pdo,'inbound_cartons','delivery_status');
$has_bd_delivery_status = has_col($pdo,'inbound_cartons','bd_delivery_status');
$has_received_bd_at     = has_col($pdo,'inbound_cartons','received_bd_at');
$has_bd_payment_status  = has_col($pdo,'inbound_cartons','bd_payment_status');
$has_bd_verified_at     = has_col($pdo,'inbound_cartons','bd_payment_verified_at');
$has_bd_total_price     = has_col($pdo,'inbound_cartons','bd_total_price');
$has_total_due          = has_col($pdo,'inbound_cartons','total_due');

$has_pl_shipping_mark   = has_col($pdo,'inbound_packing_lists','shipping_mark');
$has_pl_order_id        = has_col($pdo,'inbound_packing_lists','order_id');

// ------------ build SAFE select list ------------
$cols = ['c.id'];
$cols[] = $has_delivery_status    ? 'c.delivery_status'    : "'(n/a)' AS delivery_status";
$cols[] = $has_bd_delivery_status ? 'c.bd_delivery_status' : "'(n/a)' AS bd_delivery_status";
$cols[] = $has_received_bd_at     ? 'c.received_bd_at'     : 'NULL AS received_bd_at';
if ($has_bd_payment_status) $cols[] = 'c.bd_payment_status';
if ($has_bd_verified_at)    $cols[] = 'c.bd_payment_verified_at';
if ($has_bd_total_price)    $cols[] = 'c.bd_total_price';
if ($has_total_due)         $cols[] = 'c.total_due';
$cols[] = $has_pl_shipping_mark ? 'pl.shipping_mark AS packing_code' : "'(n/a)' AS packing_code";
$cols[] = 'o.id   AS order_id';
$cols[] = 'o.code AS order_code';

// ------------ SELECT with WHERE clause to filter statuses ------------
$whereConditions = [];
if ($has_bd_delivery_status) {
  $whereConditions[] = "(c.bd_delivery_status LIKE '%preparing%' OR c.bd_delivery_status LIKE '%ready%')";
}
if ($has_delivery_status && !$has_bd_delivery_status) {
  $whereConditions[] = "(c.delivery_status LIKE '%preparing%' OR c.delivery_status LIKE '%ready%')";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(' OR ', $whereConditions) : "";

$sql = "
  SELECT ".implode(',', $cols)."
    FROM inbound_cartons c
    LEFT JOIN inbound_packing_lists pl ON pl.id = c.packing_list_id
    LEFT JOIN orders o ON o.id = ".($has_pl_order_id ? "pl.order_id" : "pl.id")."
   $whereClause
   ORDER BY c.id DESC
   LIMIT 300
";

// Debug banner numbers
$debug = ['db_total' => null, 'fetched' => 0, 'sql_ok' => true, 'error' => null];
try {
  $debug['db_total'] = (int)$pdo->query("SELECT COUNT(*) FROM inbound_cartons")->fetchColumn();
} catch (Throwable $e) {
  $debug['db_total'] = null;
}

$rows = [];
try {
  $stmt = $pdo->query($sql);
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  $debug['fetched'] = count($rows);
} catch (Throwable $e) {
  $debug['sql_ok'] = false;
  $debug['error']  = $e->getMessage();
  error_log('[bd_delivery_supervisor SELECT] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
  $rows = [];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>BD Inbound — Supervisor</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--ok:#10b981;--bg:#f8fafc}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{padding:16px 20px;background:#0f172a;color:#fff;display:flex;justify-content:space-between;align-items:center}
  .wrap{max-width:1150px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}
  tr:hover td{background:#fafafa}
  .muted{color:var(--muted)}
  .btn{border:0;border-radius:10px;padding:.55rem .8rem;cursor:pointer;font-weight:600}
  .ok{background:var(--ok);color:#fff}
  .ghost{background:#fff;border:1px solid var(--line)}
  .mono{font-family:ui-monospace, Menlo, Consolas, monospace}
  .filterbar{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
  select{padding:6px 10px;border-radius:8px;border:1px solid var(--line);font-size:14px}
  .status-badge{display:inline-block;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600}
  .status-preparing{background:#fff7ed;border:1px solid #fed7aa;color:#92400e}
  .status-ready{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46}
</style>
</head>
<body>
<header>
  <div><strong>BD Inbound • Supervisor</strong></div>
  <nav><a href="/app/" style="color:#fff;text-decoration:underline">Admin Home</a></nav>
</header>

<div class="wrap">

  <?php if ($msg): ?>
    <div class="card" style="border-color:<?= $msgOk ? '#bbf7d0' : '#fecaca' ?>;background:<?= $msgOk ? '#ecfdf5' : '#fef2f2' ?>">
      <strong><?= $msgOk ? 'Success' : 'Error' ?></strong>: <?= e($msg) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="filterbar">
      <h2 style="margin:0;">Cartons Ready for Delivery</h2>
    </div>

    <form method="post">
      <div style="overflow:auto;border-radius:10px">
        <table>
          <thead>
            <tr>
              <th style="width:40px"><input type="checkbox" onclick="document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked)"></th>
              <th>Carton</th>
              <th>Order</th>
              <th>Current Status</th>
              <th>BD Payment</th>
              <th class="mono">BD $ / Due</th>
              <th>Received @ BD</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $bd  = $r['bd_delivery_status'] ?? '';
                $del = $r['delivery_status']     ?? '';
                $token = status_token($bd !== '' ? $bd : $del);
                
                // Determine if this carton can be approved (is "preparing")
                $canApprove = ($token === 'preparing');
                $isReady = ($token === 'ready');
              ?>
              <tr class="data-row"
                  data-delivery="<?= e($del) ?>"
                  data-bd="<?= e($bd) ?>"
                  data-status="<?= e($token) ?>"
                  data-debug="token:<?= e($token) ?>|bd:<?= e($bd) ?>|del:<?= e($del) ?>">
                <td>
                  <?php if ($canApprove): ?>
                    <input class="chk" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>">
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="mono">#<?= (int)$r['id'] ?> · <?= e($r['packing_code'] ?? '') ?></td>
                <td>
                  <?php if (!empty($r['order_id'])): ?>
                    <a class="mono" href="/customer/order_details.php?order_id=<?= (int)$r['order_id'] ?>" target="_blank">
                      <?= e($r['order_code'] ?: '#'.$r['order_id']) ?>
                    </a>
                  <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($canApprove): ?>
                    <span class="status-badge status-preparing">Preparing for delivery</span>
                  <?php elseif ($isReady): ?>
                    <span class="status-badge status-ready">Ready for delivery</span>
                  <?php else: ?>
                    <div>Delivery: <b><?= e($del) ?></b></div>
                    <div>BD Delivery: <b><?= e($bd) ?></b></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= e($r['bd_payment_status'] ?? 'pending') ?>
                  <?php if (!empty($r['bd_payment_verified_at'])): ?>
                    <div class="muted"><?= e($r['bd_payment_verified_at']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono">
                  <?= isset($r['bd_total_price']) ? number_format((float)$r['bd_total_price'],2) : '—' ?> /
                  <?= isset($r['total_due']) ? number_format((float)$r['total_due'],2) : '—' ?>
                </td>
                <td class="mono"><?= e($r['received_bd_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="muted">No cartons found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex;gap:10px;margin-top:12px;align-items:center">
        <button class="btn ok" type="submit">Approve Selected for Delivery</button>
        <span class="muted">Select "Preparing" cartons and approve them to make them "Ready for delivery"</span>
      </div>
    </form>

    <p class="muted" style="margin-top:10px">
      <strong>Workflow:</strong> Cartons with "preparing for delivery" status can be approved by checking them and clicking "Approve Selected for Delivery". 
      Once approved, they will change to "ready for delivery" status and a 6-digit OTP code will be automatically generated and saved. 
      Customers can view their OTP codes on the order details page. BD members can then verify the OTP to complete delivery.
      <br><strong>Security:</strong> OTP codes are only visible to customers, not displayed on this supervisor page.
    </p>
  </div>
</div>

<script>
// Page loaded confirmation
document.addEventListener('DOMContentLoaded', function() {
  console.log('=== PAGE LOADED ===');
  console.log('Total data rows:', document.querySelectorAll('tbody tr.data-row').length);
  
  // Log status distribution
  const rows = document.querySelectorAll('tbody tr.data-row');
  const statusCounts = {};
  rows.forEach(row => {
    const stat = row.dataset.status || 'empty';
    statusCounts[stat] = (statusCounts[stat] || 0) + 1;
  });
  console.log('Status breakdown:', statusCounts);
});
</script>
</body>
</html>