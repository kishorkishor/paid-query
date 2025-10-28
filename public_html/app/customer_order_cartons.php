<?php
require_once __DIR__.'/auth.php'; // if your customer login is different, switch includes accordingly
// If you don't want auth here, you can skip require_login();

$pdo = db();
function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$code = trim($_GET['order_code'] ?? '');
if ($code===''){ http_response_code(400); echo "Missing order_code"; exit; }

$st = $pdo->prepare("SELECT id, code, customer_name, status FROM orders WHERE code=? LIMIT 1");
$st->execute([$code]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ echo "Order not found"; exit; }

$status = strtolower($o['status'] ?? '');
$canShow = in_array($status, ['partially shipped','shipped']);

$rows = [];
if ($canShow){
  $q = $pdo->prepare("
    SELECT c.carton_code, c.status, c.shipped_at,
           l.lot_code, l.courier_name, l.tracking_no
      FROM inbound_packing_lists p
      JOIN inbound_cartons c ON c.packing_list_id = p.id
      LEFT JOIN shipment_lot_cartons lc ON lc.carton_id = c.id
      LEFT JOIN shipment_lots l ON l.id = lc.lot_id
     WHERE p.order_id=?
     ORDER BY c.carton_no");
  $q->execute([(int)$o['id']]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html><meta charset="utf-8"><title>My Order — <?= e($o['code']) ?></title>
<style>body{font-family:system-ui;margin:0;background:#f7f7fb}
header{padding:16px 20px;background:#111827;color:#fff}
.container{max-width:900px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
.muted{color:#6b7280}
</style>
<header><b>Order</b> — <?= e($o['code']) ?></header>
<div class="container">
  <p class="muted">Customer: <?= e($o['customer_name']) ?> · Status: <b><?= e($o['status']) ?></b></p>

  <?php if (!$canShow): ?>
    <p>We’ll display your carton-by-carton shipment details once your order has shipped.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Carton</th><th>Status</th><th>Shipped At</th><th>Lot</th><th>Courier</th><th>Tracking</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= e($r['carton_code']) ?></td>
            <td><?= e($r['status']) ?></td>
            <td><?= e($r['shipped_at']) ?></td>
            <td><?= e($r['lot_code']) ?></td>
            <td><?= e($r['courier_name']) ?></td>
            <td><?= e($r['tracking_no']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
