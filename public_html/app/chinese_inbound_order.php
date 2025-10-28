<?php
// /app/chinese_inbound_order.php — Receive @ warehouse -> Packing -> Forward to QC (server-post + editable draft)
require_once __DIR__ . '/auth.php';

require_login();
require_perm('chinese_inbound_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function team_id_by_name_or_code(PDO $pdo, string $name, string $code = null): ?int {
  $st = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1");
  $st->execute([$name]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return (int)$row['id'];
  if ($code) {
    $st = $pdo->prepare("SELECT id FROM teams WHERE code=? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['id'];
  }
  return null;
}
function flash_set($msg,$type='ok'){ $_SESSION['_flash']=['m'=>$msg,'t'=>$type]; }
function flash_get(){ $x=$_SESSION['_flash']??null; unset($_SESSION['_flash']); return $x; }

// --- Teams ---
$inboundTeamId = team_id_by_name_or_code($pdo, 'Chinese Inbound', 'ch_inbound');
// Per your instruction: QC team id is fixed to 13
$qcTeamId      = 13;

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if (!$orderId) { http_response_code(400); echo "Bad order id"; exit; }

// Load order + query bits for context/logs
$st = $pdo->prepare("SELECT o.*, q.product_name, q.query_type
                       FROM orders o
                  LEFT JOIN queries q ON q.id=o.query_id
                      WHERE o.id=? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo "Order not found"; exit; }
if ((int)$o['current_team_id'] !== (int)$inboundTeamId) {
  http_response_code(403); echo "Order not assigned to Chinese Inbound"; exit;
}

// Helper: check if current status is "Ready to ship" (case-insensitive, trims)
$__readyToShip = (strcasecmp(trim((string)$o['status']), 'Ready to ship') === 0);

// Helper: check if product has been received (status contains "received" or later stages)
function is_received_or_later($status) {
  $s = strtolower(trim((string)$status));
  return (
    strpos($s, 'received') !== false ||
    strpos($s, 'qc') !== false ||
    strpos($s, 'ready') !== false ||
    strpos($s, 'shipped') !== false ||
    strpos($s, 'packing') !== false
  );
}

$__isReceivedOrLater = is_received_or_later($o['status']);

/* ===========================
   POST: Mark received
   =========================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='mark_received') {
  // Guard: do not allow marking "received" if it's already Ready to ship
  if ($__readyToShip) {
    flash_set('This order is already "Ready to ship". You cannot mark it as received again.','err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  }

  require_perm('update_inbound_status');
  try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE orders
                      SET previous_team_id=current_team_id,
                          current_team_id=:tid,
                          status=:st
                    WHERE id=:id")
        ->execute([':tid'=>$inboundTeamId, ':st'=>'product received in warehouse', ':id'=>$orderId]);

    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'inbound_received', JSON_OBJECT('status','product received in warehouse'))")
        ->execute([$orderId, $me]);

    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$o['query_id'], $orderId, $me, 'Inbound: Product received in warehouse']);

    $pdo->commit();
    flash_set('Status updated: product received in warehouse');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  } catch(Exception $e){
    $pdo->rollBack();
    flash_set('Error: '.$e->getMessage(),'err');
  }
}

/* ===========================
   POST: Save packing list (create or update)
   =========================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_packing') {
  // Guard: only allow packing list creation after product is received
  if (!$__isReceivedOrLater) {
    flash_set('Cannot create packing list before product is received.','err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  }

  // Guard: cannot edit packing list when status is "Ready to ship"
  if ($__readyToShip) {
    flash_set('Cannot edit packing list when order is Ready to ship.','err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  }

  $shipping_mark = trim($_POST['shipping_mark'] ?? '');
  $total         = (int)($_POST['total_cartons'] ?? 0);

  if (!$shipping_mark || $total<=0) {
    flash_set('Invalid input.','err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId."&edit=1"); exit;
  }

  // Gather weights w_1..w_N (missing weights become error)
  $cartons = [];
  for ($i=1; $i <= $total; $i++){
    if (!array_key_exists("w_$i", $_POST)) {
      flash_set("Missing weight for carton #$i",'err');
      header("Location: /app/chinese_inbound_order.php?id=".$orderId."&edit=1"); exit;
    }
    $w = (float)$_POST["w_$i"];
    $cartons[] = ['no'=>$i, 'weight_kg'=>$w, 'shipping_mark'=>$shipping_mark];
  }

  try {
    $pdo->beginTransaction();

    // Upsert header
    $pl = $pdo->prepare("SELECT id FROM inbound_packing_lists WHERE order_id=? LIMIT 1");
    $pl->execute([$orderId]);
    $plid = (int)($pl->fetchColumn() ?: 0);

    if ($plid) {
      $pdo->prepare("UPDATE inbound_packing_lists
                        SET shipping_mark=?, total_cartons=?, status='draft'
                      WHERE id=?")
          ->execute([$shipping_mark, $total, $plid]);
      $pdo->prepare("DELETE FROM inbound_cartons WHERE packing_list_id=?")->execute([$plid]);
    } else {
      $pdo->prepare("INSERT INTO inbound_packing_lists (order_id, shipping_mark, total_cartons, status, created_by)
                     VALUES (?,?,?,?,?)")
          ->execute([$orderId, $shipping_mark, $total, 'draft', $me]);
      $plid = (int)$pdo->lastInsertId();
    }

    // Insert cartons
    $ins = $pdo->prepare("INSERT INTO inbound_cartons (packing_list_id, carton_no, weight_kg, shipping_mark)
                          VALUES (?,?,?,?)");
    foreach($cartons as $c){
      $ins->execute([$plid, (int)$c['no'], (float)$c['weight_kg'], $shipping_mark]);
    }

    // Snapshot
    $pdo->prepare("UPDATE orders SET carton_count=? WHERE id=?")->execute([$total, $orderId]);

    // Audit
    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'packing_saved', JSON_OBJECT('cartons', ?, 'shipping_mark', ?))")
        ->execute([$orderId, $me, $total, $shipping_mark]);

    $pdo->commit();
    flash_set('Packing list saved.');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit; // redirect to view mode
  } catch(Exception $e){
    $pdo->rollBack();
    flash_set('DB error: '.$e->getMessage(),'err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId."&edit=1"); exit;
  }
}

/* ===========================
   POST: Mark ready to ship
   =========================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='mark_ready_to_ship') {
  require_perm('update_inbound_status');

  try {
    $pdo->beginTransaction();

    // Check if country_id is not 1, then update inbound_cartons
    if ((int)$o['country_id'] !== 1) {
      // Get packing list
      $pl = $pdo->prepare("SELECT id FROM inbound_packing_lists WHERE order_id=? ORDER BY id DESC LIMIT 1");
      $pl->execute([$orderId]);
      $packing = $pl->fetch(PDO::FETCH_ASSOC);
      
      if ($packing) {
        $plid = (int)$packing['id'];
        $shippingPrice = (float)($o['shipping_price'] ?? 0);
        
        // Update all cartons: set bd_rechecked_weight_kg = weight_kg, bd_price_per_kg = shipping_price, and calculate bd_total_price
        $pdo->prepare("UPDATE inbound_cartons 
                       SET bd_rechecked_weight_kg = weight_kg,
                           bd_price_per_kg = ?,
                           bd_total_price = weight_kg * ?
                       WHERE packing_list_id = ?")
            ->execute([$shippingPrice, $shippingPrice, $plid]);
      }
    }

    // Update order status to "Ready to ship"
    $pdo->prepare("UPDATE orders SET status='Ready to ship' WHERE id=?")
        ->execute([$orderId]);

    // Audit log
    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'mark_ready_to_ship', JSON_OBJECT('status','Ready to ship'))")
        ->execute([$orderId, $me]);

    // Message
    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$o['query_id'], $orderId, $me, 'Inbound: Order marked as Ready to ship']);

    $pdo->commit();
    flash_set('Order marked as Ready to ship.');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  } catch(Exception $e){
    $pdo->rollBack();
    flash_set('Error: '.$e->getMessage(),'err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  }
}

/* ===========================
   POST: Finalize & forward to QC
   =========================== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='finalize_qc') {
  require_perm('forward_to_qc');

  try {
    $pdo->beginTransaction();

    $pl = $pdo->prepare("SELECT id,total_cartons FROM inbound_packing_lists WHERE order_id=? ORDER BY id DESC LIMIT 1");
    $pl->execute([$orderId]);
    $packing = $pl->fetch(PDO::FETCH_ASSOC);
    if(!$packing || (int)$packing['total_cartons']<=0){ throw new Exception('Packing list missing'); }

    $plid = (int)$packing['id'];

    // Check if country_id is not 1, then update inbound_cartons
    if ((int)$o['country_id'] !== 1) {
      $shippingPrice = (float)($o['shipping_price'] ?? 0);
      
      // Update all cartons: set bd_rechecked_weight_kg = weight_kg, bd_price_per_kg = shipping_price, and calculate bd_total_price
      $pdo->prepare("UPDATE inbound_cartons 
                     SET bd_rechecked_weight_kg = weight_kg,
                         bd_price_per_kg = ?,
                         bd_total_price = weight_kg * ?
                     WHERE packing_list_id = ?")
          ->execute([$shippingPrice, $shippingPrice, $plid]);
    }

    // finalize packing list
    $pdo->prepare("UPDATE inbound_packing_lists SET status='finalized', finalized_at=NOW() WHERE id=?")
        ->execute([$plid]);

    // create QC check only if not exists (idempotent)
    $exists = $pdo->prepare("SELECT id FROM qc_checks WHERE order_id=? LIMIT 1");
    $exists->execute([$orderId]);
    if (!$exists->fetchColumn()) {
      $pdo->prepare("INSERT INTO qc_checks (order_id, result, notes, created_by)
                     VALUES (?,?,?,?)")
          ->execute([$orderId, 'pending', 'Auto-created from Inbound', $me]);
    }

    // move order to QC team (team id = 13 as requested)
    $pdo->prepare("UPDATE orders
                      SET previous_team_id=current_team_id,
                          current_team_id=:qc,
                          status='qc_pending'
                   WHERE id=:id")
        ->execute([':qc'=>$qcTeamId, ':id'=>$orderId]);

    // audit + message
    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'forward_to_qc', JSON_OBJECT('from','Chinese Inbound','to','QC'))")
        ->execute([$orderId, $me]);

    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$o['query_id'], $orderId, $me, 'Inbound: Finalized packing list and sent to QC']);

    $pdo->commit();
    flash_set('Packing finalized and sent to QC.');
    header("Location: /app/chinese_inbound.php"); exit;
  } catch(Exception $e){
    $pdo->rollBack();
    flash_set('Forward error: '.$e->getMessage(),'err');
    header("Location: /app/chinese_inbound_order.php?id=".$orderId); exit;
  }
}

/* ======= Load packing for view/edit ======= */
$pl = $pdo->prepare("SELECT * FROM inbound_packing_lists WHERE order_id=? ORDER BY id DESC LIMIT 1");
$pl->execute([$orderId]);
$packing = $pl->fetch(PDO::FETCH_ASSOC);

$cartons = [];
if ($packing) {
  $ct = $pdo->prepare("SELECT * FROM inbound_cartons WHERE packing_list_id=? ORDER BY carton_no ASC");
  $ct->execute([$packing['id']]);
  $cartons = $ct->fetchAll(PDO::FETCH_ASSOC);
}

$flash = flash_get();
$editMode = isset($_GET['edit']) && $packing && $packing['status']==='draft' && !$__readyToShip;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/><title>Chinese Inbound — Order <?= e($o['code']) ?></title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:#0b1220}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
    .container{max-width:1000px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.20)}
    .muted{color:#6b7280}
    label{display:block;margin:10px 0 6px}
    input[type="number"], input[type="text"]{width:100%;padding:10px;border:1px solid #ddd;border-radius:10px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .btn{display:inline-block;padding:10px 14px;border:1px solid #111827;border-radius:10px;text-decoration:none;font-weight:600;color:#111827;background:#fff;cursor:pointer}
    .btn.primary{background:#111827;color:#fff}
    .btn[disabled]{opacity:.5;cursor:not-allowed}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
    .alert{padding:10px 12px;border-radius:10px;margin-bottom:12px}
    .ok{background:#ecfdf5;border:1px solid #10b981;color:#065f46}
    .err{background:#fef2f2;border:1px solid #ef4444;color:#7f1d1d}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
    .disabled-section{opacity:0.5;pointer-events:none;position:relative}
    .disabled-section::after{content:"Product must be received first";position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.75);color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;white-space:nowrap}
  </style>
  <script>
    function renderWeightInputs(n, existing){
      const c = document.getElementById('weights'); if(!c) return;
      let html='';
      for(let i=1;i<=n;i++){
        const val = (existing && existing[i]) ? existing[i] : '';
        html += `<label>Carton #${i} weight (kg)</label><input type="number" step="0.001" name="w_${i}" value="${val}" required>`;
      }
      c.innerHTML = html;
    }
  </script>
</head>
<body>
<header>
  <div><strong>Chinese Inbound</strong> — Order <?= e($o['code']) ?></div>
  <nav><a class="btn" href="/app/chinese_inbound.php">Back</a></nav>
</header>

<div class="container">
  <?php if($flash): ?>
    <div class="alert <?= e($flash['t']) ?>"><?= e($flash['m']) ?></div>
  <?php endif; ?>

  <h2 style="margin:0 0 10px">Customer: <?= e($o['customer_name']) ?> <span class="muted">| Status: <?= e($o['status']) ?></span></h2>
  <div class="muted">Product: <?= e($o['product_name'] ?? '-') ?> · Qty: <?= (int)$o['quantity'] ?> · Order Type: <?= e($o['order_type']) ?></div>

  <form method="post" style="margin-top:16px">
    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
    <button class="btn primary"
            name="action" value="mark_received" type="submit"
            <?= ($__readyToShip || $__isReceivedOrLater) ? 'disabled' : '' ?>
            <?= $__readyToShip ? 'title="Already Ready to ship — cannot mark as received."' : '' ?>
            <?= ($__isReceivedOrLater && !$__readyToShip) ? 'title="Product already received"' : '' ?>>
      Mark "Product received in warehouse"
    </button>
  </form>

  <hr style="margin:20px 0"/>

  <div class="<?= !$__isReceivedOrLater ? 'disabled-section' : '' ?>">
    <div class="actions" style="margin-bottom:10px">
      <?php if($packing && $packing['status']==='draft' && !$editMode && !$__readyToShip): ?>
        <a class="btn" href="/app/chinese_inbound_order.php?id=<?= (int)$orderId ?>&edit=1">Edit packing list</a>
      <?php elseif($packing && $packing['status']==='draft' && $__readyToShip): ?>
        <button class="btn" disabled title="Cannot edit packing list when Ready to ship">Edit packing list</button>
      <?php endif; ?>
      <?php if($editMode): ?>
        <a class="btn" href="/app/chinese_inbound_order.php?id=<?= (int)$orderId ?>">Cancel edit</a>
      <?php endif; ?>
    </div>

    <h3 style="margin:0 0 10px">Packing list</h3>

    <?php if(!$packing || $editMode): ?>
      <?php
        // Prefill values if in edit mode
        $prefMark = $packing['shipping_mark'] ?? '';
        $prefTotal = (int)($packing['total_cartons'] ?? 0);
        $weightsMap = [];
        foreach ($cartons as $c) { $weightsMap[(int)$c['carton_no']] = (float)$c['weight_kg']; }
      ?>
      <form method="post">
        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
        <div class="row">
          <div>
            <label>Shipping mark</label>
            <input type="text" name="shipping_mark" required placeholder="e.g., CHWH/Tasin" value="<?= e($prefMark) ?>">
          </div>
          <div>
            <label>Total cartons</label>
            <input type="number" id="total_cartons" name="total_cartons" min="1" max="999" required
                   value="<?= $prefTotal ?: '' ?>"
                   oninput="renderWeightInputs(parseInt(this.value||'0',10), window._weights)">
          </div>
        </div>
        <p class="muted" style="margin:8px 0 12px">All cartons must share the same shipping mark. Change "Total cartons" to add/remove rows.</p>
        <div id="weights"></div>
        <div style="margin-top:12px">
          <button class="btn primary" type="submit" name="action" value="save_packing">Save packing list</button>
        </div>
      </form>
      <script>
        window._weights = <?= json_encode($weightsMap, JSON_UNESCAPED_UNICODE) ?>;
        (function init(){
          const n = parseInt(document.getElementById('total_cartons').value||'0',10);
          renderWeightInputs(n, window._weights);
        })();
      </script>
    <?php else: ?>
      <p>
        <strong>Shipping mark:</strong> <?= e($packing['shipping_mark']) ?> &nbsp; · &nbsp;
        <strong>Total cartons:</strong> <?= (int)$packing['total_cartons'] ?> &nbsp; · &nbsp;
        <strong>Status:</strong> <?= e($packing['status']) ?>
      </p>
      <table>
        <thead><tr><th>#</th><th>Weight (kg)</th><th>Shipping mark</th></tr></thead>
        <tbody>
          <?php foreach($cartons as $c): ?>
            <tr>
              <td><?= (int)$c['carton_no'] ?></td>
              <td><?= number_format((float)$c['weight_kg'],3) ?></td>
              <td><?= e($c['shipping_mark']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if($packing['status']==='draft' && !$__readyToShip): ?>
        <div style="margin-top:12px;display:flex;gap:10px">
          <form method="post" style="display:inline">
            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
            <button class="btn primary" name="action" value="mark_ready_to_ship" type="submit">Mark Ready to Ship</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
            <button class="btn primary" name="action" value="finalize_qc" type="submit">Finalize & Send to QC</button>
          </form>
        </div>
      <?php elseif($__readyToShip): ?>
        <div style="margin-top:12px;display:flex;gap:10px">
          <button class="btn primary" disabled title="Already marked as Ready to ship">Mark Ready to Ship</button>
          <button class="btn primary" disabled title="Already marked as Ready to ship">Finalize & Send to QC</button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>