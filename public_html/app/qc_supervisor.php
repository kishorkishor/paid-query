<?php
// /app/qc_supervisor.php — QC Supervisor Dashboard (awaiting approval)
require_once __DIR__.'/auth.php';
require_login();
require_perm('qc_supervisor_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function team_id_by_name_or_code(PDO $pdo, $name, $code=null){
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

/* QC team id with hard fallback = 13 (same behavior as member pages) */
$qcTeamId = 13;
$found = team_id_by_name_or_code($pdo,'QC','qc');
if ($found) $qcTeamId = (int)$found;

/*
  Show orders that:
   - are currently at the QC team, AND
   - have a qc_checks row with result = 'QC Done' and not approved yet
     (approved_by and approved_at are NULL),
  (We also keep orders.status = 'QC Done' as a secondary condition for compatibility.)
*/
$sql = "
  SELECT DISTINCT
         o.id, o.code, o.customer_name, o.status, o.updated_at,
         q.product_name,
         qc.created_at AS qc_done_at
    FROM orders o
    JOIN qc_checks qc ON qc.order_id = o.id
    LEFT JOIN queries q ON q.id = o.query_id
   WHERE o.current_team_id = :tid
     AND (
           qc.result = 'QC Done'
           AND qc.approved_by IS NULL
           AND qc.approved_at IS NULL
         )
     OR (
           o.current_team_id = :tid
           AND o.status = 'QC Done'
         )
ORDER BY COALESCE(qc.created_at, o.updated_at) DESC, o.id DESC
";
$st=$pdo->prepare($sql);
$st->execute([':tid'=>$qcTeamId]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>QC — Supervisor Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--line:#eceef2;}
    *{box-sizing:border-box}
    body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
    .container{max-width:1100px;margin:24px auto;padding:18px;background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
    .empty{padding:16px;border:1px dashed var(--line);border-radius:12px;background:#fff}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #111827;border-radius:10px;text-decoration:none;font-weight:600;color:#111827;background:#fff}
  </style>
</head>
<body>
<header>
  <div><strong>QC</strong> — Supervisor Dashboard</div>
  <nav><a class="btn" href="/app/">Home</a></nav>
</header>

<div class="container">
  <h2 style="margin:0 0 10px">Awaiting Approval (QC Done)</h2>

  <?php if(!$rows): ?>
    <div class="empty">No orders awaiting approval.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Order</th>
          <th>Customer</th>
          <th>Product</th>
          <th>QC Done At</th>
          <th>Last Update</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?= e($r['code']) ?></td>
          <td><?= e($r['customer_name']) ?></td>
          <td><?= e($r['product_name'] ?? '-') ?></td>
          <td><?= e($r['qc_done_at'] ?? '-') ?></td>
          <td><?= e($r['updated_at']) ?></td>
          <td><a class="btn" href="/app/qc_supervisor_order.php?id=<?= (int)$r['id'] ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
