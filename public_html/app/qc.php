<?php
// /app/qc.php — QC Member Dashboard (orders currently at QC team)
require_once __DIR__.'/auth.php';
require_login();
require_perm('qc_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function team_id_by_name_or_code(PDO $pdo, string $name, string $code=null): ?int {
  $st=$pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1");
  $st->execute([$name]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if($row) return (int)$row['id'];
  if($code){
    $st=$pdo->prepare("SELECT id FROM teams WHERE code=? LIMIT 1");
    $st->execute([$code]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if($row) return (int)$row['id'];
  }
  return null;
}

/* --- Resolve QC team id (keep your workflow, add hard fallback) --- */
$qcTeamId = 13; // known QC team id in your DB
$found = team_id_by_name_or_code($pdo,'QC','qc');
if ($found) { $qcTeamId = (int)$found; }

if (!$qcTeamId) {
  // Make the issue explicit instead of showing an empty table silently
  error_log('QC dashboard: QC team id not found. Ensure teams has id=13 or name="QC"/code="qc".');
  http_response_code(500);
  echo 'QC team misconfigured. Please set team name "QC" or code "qc", or ensure team id 13 exists.';
  exit;
}

/* --- Fetch all orders at QC (preserves your existing query & columns) --- */
$sql = "SELECT o.id,o.code,o.customer_name,o.status,o.updated_at,q.product_name,q.query_type
          FROM orders o
     LEFT JOIN queries q ON q.id=o.query_id
         WHERE o.current_team_id = :tid
      ORDER BY o.updated_at DESC";
$st=$pdo->prepare($sql);
$st->execute([':tid'=>$qcTeamId]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>QC — Dashboard</title>
<style>
body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f7f7fb}
header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
.container{max-width:1100px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
.btn{display:inline-block;padding:8px 12px;border:1px solid #111827;border-radius:10px;text-decoration:none;font-weight:600;color:#111827}
</style>
</head>
<body>
<header>
  <div><strong>QC</strong> — Member Dashboard</div>
  <nav><a class="btn" href="/app/">Home</a></nav>
</header>

<div class="container">
  <h2 style="margin:0 0 10px">Orders at QC</h2>
  <?php if(!$rows): ?>
    <p>No orders in QC.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Order</th>
          <th>Customer</th>
          <th>Product</th>
          <th>Status</th>
          <th>Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= e($r['code']) ?></td>
          <td><?= e($r['customer_name']) ?></td>
          <td><?= e($r['product_name'] ?? '-') ?></td>
          <td><?= e($r['status']) ?></td>
          <td><?= e($r['updated_at']) ?></td>
          <td><a class="btn" href="/app/qc_order.php?id=<?= (int)$r['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
