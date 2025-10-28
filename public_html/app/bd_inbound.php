<?php
require_once __DIR__.'/auth.php';
require_login();
require_perm('bd_inbound_access');

$pdo = db();
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$lots = $pdo->query("
  SELECT id, lot_code, courier_name, tracking_no, status, shipped_at, cleared_at, received_at
  FROM shipment_lots
  ORDER BY COALESCE(received_at, cleared_at, shipped_at) DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><meta charset="utf-8"><title>Bangladesh Inbound — Lots</title>
<style>body{font-family:system-ui;margin:0;background:#f7f7fb}
header{padding:16px 20px;background:#111827;color:#fff}
.container{max-width:1100px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
a.btn{padding:8px 12px;border:1px solid #111827;border-radius:8px;text-decoration:none;color:#111827}
</style>
<header><b>Bangladesh Inbound</b> — Lots</header>
<div class="container">
  <table>
    <thead><tr><th>Lot</th><th>Courier</th><th>Tracking</th><th>Status</th><th>Shipped</th><th>Cleared</th><th>Received</th><th></th></tr></thead>
    <tbody>
      <?php foreach($lots as $r): ?>
      <tr>
        <td><?= e($r['lot_code']) ?></td>
        <td><?= e($r['courier_name']) ?></td>
        <td><?= e($r['tracking_no']) ?></td>
        <td><?= e($r['status']) ?></td>
        <td><?= e($r['shipped_at']) ?></td>
        <td><?= e($r['cleared_at']) ?></td>
        <td><?= e($r['received_at']) ?></td>
        <td><a class="btn" href="/app/bd_lot.php?lot=<?= urlencode($r['lot_code']) ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
