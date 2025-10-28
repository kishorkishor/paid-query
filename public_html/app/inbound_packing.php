<?php
require_once __DIR__ . '/auth.php';
require_login();
require_perm('handoff_bd_inbound');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$packing = $pdo->query("SELECT * FROM inbound_packing_lists WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if (!$packing) { echo "Packing list not found"; exit; }

$cartons = $pdo->query("SELECT * FROM inbound_cartons WHERE packing_list_id=$id ORDER BY carton_no ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Mark Shipped — <?= htmlspecialchars($packing['shipping_mark']) ?></title>
<style>
body{font-family:system-ui;margin:20px;background:#f9fafb;}
table{width:100%;border-collapse:collapse;margin-top:20px;background:#fff;border-radius:8px;overflow:hidden;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
th{background:#f2f2f2;}
button{padding:8px 14px;border:none;border-radius:6px;background:#111827;color:#fff;cursor:pointer;}
</style>
</head>
<body>
<h2>Packing List — <?= htmlspecialchars($packing['shipping_mark']) ?></h2>
<p>Total cartons: <?= (int)$packing['total_cartons'] ?> | 
   Shipped: <?= (int)$packing['shipped_cartons'] ?> | 
   Status: <b><?= htmlspecialchars($packing['shipped_status']) ?></b></p>

<table>
<thead>
<tr><th><input type="checkbox" id="selectAll"></th><th>Carton No</th><th>Weight</th><th>Status</th><th>Shipped At</th></tr>
</thead>
<tbody>
<?php foreach ($cartons as $c): ?>
<tr>
  <td><input type="checkbox" class="carton" value="<?= $c['id'] ?>" <?= $c['status']=='shipped'?'disabled':'' ?>></td>
  <td><?= htmlspecialchars($c['carton_no']) ?></td>
  <td><?= htmlspecialchars($c['weight_kg']) ?></td>
  <td><?= htmlspecialchars($c['status']) ?></td>
  <td><?= htmlspecialchars($c['shipped_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div style="margin-top:20px;">
  <input id="courier" placeholder="Courier name">
  <input id="tracking" placeholder="Tracking no">
  <button id="shipBtn">Mark Selected as Shipped</button>
</div>

<div id="msg" style="margin-top:10px;"></div>

<script>
document.getElementById('selectAll').addEventListener('change',e=>{
  document.querySelectorAll('.carton:not(:disabled)').forEach(ch=>ch.checked=e.target.checked);
});
document.getElementById('shipBtn').addEventListener('click',async()=>{
  const ids=Array.from(document.querySelectorAll('.carton:checked')).map(x=>parseInt(x.value));
  if(ids.length===0){alert('Select cartons first');return;}
  const payload={
    packing_list_id: <?= (int)$id ?>,
    carton_ids: ids,
    courier: document.getElementById('courier').value,
    tracking: document.getElementById('tracking').value
  };
  const r=await fetch('/api/mark_shipped.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const j=await r.json();
  document.getElementById('msg').textContent=j.ok?`Shipped ${j.shipped}/${j.total} cartons (${j.status})`:'Error: '+j.error;
  if(j.ok)location.reload();
});
</script>
</body>
</html>
