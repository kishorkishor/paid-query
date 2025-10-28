<?php
// /app/inbound_ship.php — Chinese Inbound ships cartons (partial/full)
// Resolves/creates packing list for the order and shows carton UI

require_once __DIR__ . '/auth.php';
require_login();
require_perm('chinese_inbound_access'); // use same perm as dashboard

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) { http_response_code(400); echo "Bad order id"; exit; }

try {
  // 1) Load order (code, customer, carton count from orders/queries)
  $st = $pdo->prepare("
    SELECT o.id, o.code, o.customer_name, o.status, o.updated_at,
           COALESCE(o.carton_count, 0)          AS o_cartons,
           COALESCE(q.carton_count, 0)          AS q_cartons
      FROM orders o
      LEFT JOIN queries q ON q.id = o.query_id
     WHERE o.id=? LIMIT 1");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) { http_response_code(404); echo "Order not found"; exit; }

  $defaultCartons = max((int)$order['o_cartons'], (int)$order['q_cartons']); // use whichever you have

  // 2) Resolve packing list (find latest; else create one with default cartons)
  $pl = $pdo->prepare("SELECT * FROM inbound_packing_lists WHERE order_id=? ORDER BY id DESC LIMIT 1");
  $pl->execute([$orderId]);
  $packing = $pl->fetch(PDO::FETCH_ASSOC);

  if (!$packing) {
    // If we don't know the carton count yet, don't create anything — ask user to set it first.
    if ($defaultCartons <= 0) {
      // Render a minimal page telling operator to finalize/enter cartons on the order page.
      ?>
      <!doctype html><meta charset="utf-8">
      <title>Ship Cartons — Missing cartons</title>
      <style>body{font-family:system-ui;margin:40px;color:#111}a.btn{display:inline-block;padding:8px 14px;border:1px solid #111;border-radius:8px;text-decoration:none}</style>
      <h2>Cannot open Ship page</h2>
      <p>This order has no carton count yet. Please create a packing list with total cartons first.</p>
      <p><a class="btn" href="/app/chinese_inbound_order.php?id=<?= (int)$orderId ?>">Open order</a></p>
      <?php
      exit;
    }

    $shippingMark = ($order['customer_name'] ? strtolower($order['customer_name']) : 'inbound') . '/' . $me;
    $ins = $pdo->prepare("
      INSERT INTO inbound_packing_lists(order_id, shipping_mark, total_cartons, status, created_by, created_at, finalized_at)
      VALUES(?, ?, ?, 'finalized', ?, NOW(), NOW())");
    $ins->execute([$orderId, $shippingMark, $defaultCartons, $me]);

    $pl->execute([$orderId]);
    $packing = $pl->fetch(PDO::FETCH_ASSOC);
  }

  // 3) Seed cartons if none exist and we have a positive total
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM inbound_cartons WHERE packing_list_id=?");
  $cnt->execute([(int)$packing['id']]);
  if ((int)$cnt->fetchColumn() === 0 && (int)$packing['total_cartons'] > 0) {
    $insC = $pdo->prepare("
      INSERT INTO inbound_cartons(packing_list_id, carton_no, weight_kg, shipping_mark, created_at)
      VALUES(?, ?, NULL, ?, NOW())");
    for ($i=1; $i<=(int)$packing['total_cartons']; $i++) {
      $insC->execute([(int)$packing['id'], $i, $packing['shipping_mark']]);
      // Trigger trg_inbound_cartons_set_code sets carton_code automatically
    }
  }

  // 4) Pull fresh cartons and summary
  $cartons = $pdo->prepare("
    SELECT id, carton_no, carton_code, weight_kg, status, shipped_at
      FROM inbound_cartons
     WHERE packing_list_id=?
     ORDER BY carton_no ASC");
  $cartons->execute([(int)$packing['id']]);
  $rows = $cartons->fetchAll(PDO::FETCH_ASSOC);

  $sum = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(status='shipped') AS shipped
      FROM inbound_cartons
     WHERE packing_list_id=?");
  $sum->execute([(int)$packing['id']]);
  $s = $sum->fetch(PDO::FETCH_ASSOC);
  $total   = (int)($s['total'] ?? 0);
  $shipped = (int)($s['shipped'] ?? 0);

  $shipStatus = 'not shipped';
  if ($shipped > 0 && $shipped < $total) $shipStatus = 'partial';
  if ($shipped === $total && $total > 0) $shipStatus = 'fully shipped';

} catch (Throwable $e) {
  error_log('[inbound_ship] '.$e->getMessage());
  http_response_code(500);
  echo "Internal error. Please check logs.";
  exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Chinese Inbound — Ship Cartons (<?= e($order['code']) ?>)</title>
  <style>
    :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--line:#eee}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
    .container{max-width:1000px;margin:30px auto;padding:20px;background:var(--card);border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
    .muted{color:#6b7280}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
    .btn{display:inline-block;padding:8px 14px;border:1px solid var(--ink);border-radius:8px;text-decoration:none;color:var(--ink);background:#fff}
    .btn[disabled]{opacity:.5;pointer-events:none}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    input{padding:8px 10px;border:1px solid #ddd;border-radius:8px}
  </style>
</head>
<body>
<header>
  <div><strong>Chinese Inbound</strong> — Ship Cartons</div>
  <div><a class="btn" href="/app/chinese_inbound.php">Back</a></div>
</header>

<div class="container">
  <h2 style="margin:0 0 8px">Order <?= e($order['code']) ?></h2>
  <p class="muted" style="margin:0 0 16px">
    Customer: <b><?= e($order['customer_name']) ?></b> |
    Total Cartons: <b><?= (int)$packing['total_cartons'] ?></b> |
    Shipped: <b><?= $shipped ?></b> |
    Status: <b><?= e($shipStatus) ?></b>
  </p>

  <h3 style="margin-top:0">Cartons</h3>
  <table>
    <thead>
      <tr>
        <th><input type="checkbox" id="tickAll"></th>
        <th>Carton</th>
        <th>Weight</th>
        <th>Status</th>
        <th>Shipped At</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" class="tick" value="<?= (int)$r['id'] ?>" <?= $r['status']==='shipped'?'disabled':'' ?>></td>
          <td><b><?= e($r['carton_code'] ?: $r['carton_no']) ?></b></td>
          <td><?= e($r['weight_kg'] ?? '') ?></td>
          <td><?= e($r['status']) ?></td>
          <td><?= e($r['shipped_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Mark Selected as Shipped</h3>
  <div class="row">
    <input id="courier" placeholder="Courier name">
    <input id="tracking" placeholder="Tracking no">
    <!-- NEW: Shipment lot input -->
    <input id="lot" placeholder="Shipment lot (e.g., LOT-2025-CHINA-001)">
    <input id="shipdate" type="datetime-local">
    <input id="note" placeholder="Note (optional)" style="flex:1">
    <button class="btn" id="shipBtn" <?= $total === 0 ? 'disabled title="No cartons to ship."' : '' ?>>Ship Selected</button>
  </div>
  <div id="msg" style="margin-top:10px;color:#0a0"></div>
</div>

<script>
const packingListId = <?= (int)$packing['id'] ?>;

// -------- helpers to control button state ----------
function anySelected() {
  return Array.from(document.querySelectorAll('.tick:not([disabled])')).some(ch => ch.checked);
}
function fieldsOk() {
  const courier  = document.getElementById('courier').value.trim();
  const tracking = document.getElementById('tracking').value.trim();
  const lot      = document.getElementById('lot').value.trim();
  return courier && tracking && lot;
}
function updateShipBtnState() {
  const btn = document.getElementById('shipBtn');
  const ok = anySelected() && fieldsOk();
  btn.disabled = !ok;
  btn.title = ok ? '' : 'Select cartons and fill Lot, Courier, Tracking.';
}
updateShipBtnState();

// tick all / checkboxes
document.getElementById('tickAll')?.addEventListener('change', e=>{
  document.querySelectorAll('.tick:not([disabled])').forEach(ch=>ch.checked=e.target.checked);
  updateShipBtnState();
});
document.querySelectorAll('.tick').forEach(ch=>{
  ch.addEventListener('change', updateShipBtnState);
});

// input field listeners
['courier','tracking','lot','shipdate','note'].forEach(id=>{
  const el = document.getElementById(id);
  el && el.addEventListener('input', updateShipBtnState);
});

document.getElementById('shipBtn')?.addEventListener('click', async ()=>{
  const ids = Array.from(document.querySelectorAll('.tick:checked')).map(x=>parseInt(x.value));
  const msg = document.getElementById('msg');

  if (!ids.length) {
    msg.style.color='#a00';
    msg.textContent='Select at least one pending carton.';
    updateShipBtnState();
    return;
  }
  if (!fieldsOk()) {
    msg.style.color='#a00';
    msg.textContent='Shipment Lot, Courier, and Tracking are required.';
    updateShipBtnState();
    return;
  }

  const payload = {
    packing_list_id: packingListId,
    carton_ids: ids,
    courier: document.getElementById('courier').value.trim(),
    tracking: document.getElementById('tracking').value.trim(),
    shipment_lot: document.getElementById('lot').value.trim(),   // NEW
    shipped_at: (document.getElementById('shipdate').value || 'now'),
    note: document.getElementById('note').value.trim()
  };

  const r = await fetch('/api/mark_shipped.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'include',
    body: JSON.stringify(payload)
  });
  let j = {};
  try { j = await r.json(); } catch(e){
    msg.style.color='#a00'; msg.textContent='Server error.'; return;
  }
  if(!j.ok){
    msg.style.color='#a00'; msg.textContent = j.error || 'Failed.'; return;
  }
  msg.style.color='#0a0';
  msg.textContent = `Shipped ${j.shipped}/${j.total} cartons (${j.status}).`;
  setTimeout(()=>location.reload(), 700);
});
</script>
</body>
</html>
