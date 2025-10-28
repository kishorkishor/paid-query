<?php
require_once __DIR__.'/auth.php';
require_login();

$pdo = db();
$orderId = (int)($_GET['order_id'] ?? 0);
if(!$orderId){ http_response_code(400); echo "Missing order_id"; exit; }

function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$st = $pdo->prepare("SELECT id, code, customer_name, status FROM orders WHERE id=? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ echo "Order not found"; exit; }

$rows = $pdo->prepare("
  SELECT c.id, c.carton_code, c.status, c.weight_kg, c.shipped_at,
         p.shipping_mark, l.lot_code, l.courier_name, l.tracking_no
  FROM inbound_packing_lists p
  JOIN inbound_cartons c ON c.packing_list_id = p.id
  LEFT JOIN shipment_lot_cartons lc ON lc.carton_id = c.id
  LEFT JOIN shipment_lots l ON l.id = lc.lot_id
  WHERE p.order_id=?
  ORDER BY c.carton_no
");
$rows->execute([$orderId]);
$cartons = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><meta charset="utf-8"><title>Order Cartons — <?= e($o['code']) ?></title>
<style>body{font-family:system-ui;margin:0;background:#f7f7fb}
header{padding:16px 20px;background:#111827;color:#fff}
.container{max-width:1100px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
.muted{color:#6b7280} a.btn{padding:8px 12px;border:1px solid #111827;border-radius:8px;text-decoration:none;color:#111827}
</style>
<header><b>Order Cartons</b> — <?= e($o['code']) ?> <span class="muted">· <?= e($o['status']) ?></span></header>
<div class="container">
  <table>
    <thead><tr><th>Carton</th><th>Status</th><th>Shipped At</th><th>Lot</th><th>Courier</th><th>Tracking</th><th>Weight(kg)</th></tr></thead>
    <tbody>
      <?php foreach($cartons as $c): ?>
      <tr>
        <td><?= e($c['carton_code']) ?></td>
        <td><?= e($c['status']) ?></td>
        <td><?= e($c['shipped_at']) ?></td>
        <td><?= e($c['lot_code']) ?></td>
        <td><?= e($c['courier_name']) ?></td>
        <td><?= e($c['tracking_no']) ?></td>
        <td><?= e($c['weight_kg']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
