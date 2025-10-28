<?php
// /app/bd_lot.php — Bangladesh Inbound lot review & receive
// New in this version:
// - When all cartons are set (no 'pending'), lot -> 'ready_for_review' (NOT auto-received)
// - Only supervisor can edit once in 'ready_for_review' (or when 'received_bd')
// - Supervisor-only "Mark Lot Received" button (sets received timestamp + status)
// - Existing features kept: inline mini-API, deviation guard, Δ column, undo, lock toggle

require_once __DIR__ . '/auth.php';

require_login();
require_perm('bd_inbound_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Supervisor check helper (uses your permission system if available)
function has_super_perm(): bool {
  if (function_exists('can')) return (bool)can('bd_inbound_supervisor');
  return !empty($_SESSION['admin']['perms']['bd_inbound_supervisor']);
}

/* ============================================================
   INLINE MINI-API (POST to same page)
   - action=save_carton     (lot_code, carton_id, weight, status)
   - action=save_bulk       (lot_code, cartons JSON)
   - action=toggle_lock     (lot_code) -> supervisor only
   - action=mark_received   (lot_code) -> supervisor only
============================================================ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');

  try {
    $action  = $_POST['action'];
    $lotCode = trim($_POST['lot_code'] ?? '');
    if ($lotCode==='') throw new Exception('lot_code required');

    // Load lot STRICTLY by lot_code (no id mixing)
    $st = $pdo->prepare("SELECT * FROM shipment_lots WHERE lot_code=? LIMIT 1");
    $st->execute([$lotCode]);
    $lotRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lotRow) throw new Exception('Lot not found');
    $lotId = (int)$lotRow['id'];

    // Column detection
    $lotCols = $pdo->query("SHOW COLUMNS FROM shipment_lots")->fetchAll(PDO::FETCH_COLUMN);
    $lotStatusCol = in_array('bd_status', $lotCols, true) ? 'bd_status' : (in_array('status', $lotCols, true) ? 'status' : null);
    $lotRecvCol   = in_array('bd_received_at', $lotCols, true) ? 'bd_received_at' : (in_array('received_at', $lotCols, true) ? 'received_at' : null);
    $hasLockCol   = in_array('bd_locked', $lotCols, true);

    $cartonCols = $pdo->query("SHOW COLUMNS FROM inbound_cartons")->fetchAll(PDO::FETCH_COLUMN);
    $hasW = in_array('bd_rechecked_weight_kg', $cartonCols, true);
    $hasS = in_array('bd_recheck_status',       $cartonCols, true);
    // Detect price/total/payment/delivery columns on cartons
    $hasPrice = in_array('bd_price_per_kg', $cartonCols, true);
    $hasTotal = in_array('bd_total_price',    $cartonCols, true);
    $hasPay   = in_array('bd_payment_status', $cartonCols, true);
    $hasDel   = in_array('bd_delivery_status', $cartonCols, true);

    // Current lot status (for permission gate)
    $lotStatus = strtolower($lotRow['bd_status'] ?? $lotRow['status'] ?? 'pending');
    $super = has_super_perm();

    // Supervisor lock toggle
    if ($action === 'toggle_lock') {
      if (!$hasLockCol) throw new Exception("Lock column not available (shipment_lots.bd_locked missing)");
      if (!$super) throw new Exception("Permission denied");
      $current = (int)($lotRow['bd_locked'] ?? 0);
      $new = $current ? 0 : 1;
      $pdo->prepare("UPDATE shipment_lots SET bd_locked=? WHERE id=?")->execute([$new, $lotId]);
      echo json_encode(['ok'=>true,'locked'=>$new]); exit;
    }

    // Supervisor marks lot received (explicit step)
    if ($action === 'mark_received') {
      if (!$super) throw new Exception('Permission denied');
      if ($lotRecvCol) {
        $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='received_bd', $lotRecvCol=NOW() WHERE id=?")->execute([$lotId]);
      } else {
        $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='received_bd' WHERE id=?")->execute([$lotId]);
      }
      echo json_encode(['ok'=>true,'lot_status'=>'received_bd']); exit;
    }

    // Editing gate:
    // Non-supervisor cannot edit when lot is already ready_for_review or received
    $editingRestricted = in_array($lotStatus, ['ready_for_review','received_bd','received'], true);
    if ($editingRestricted && !$super) {
      throw new Exception('Editing locked: waiting for supervisor review');
    }

    // Helper to update one carton (and validate carton ∈ lot)
    $updateOne = function(int $lotId, int $cartonId, $weight, string $status) use($pdo,$hasW,$hasS,$hasPrice){
      $chk = $pdo->prepare("SELECT 1 FROM shipment_lot_cartons WHERE lot_id=? AND carton_id=? LIMIT 1");
      $chk->execute([$lotId, $cartonId]);
      if (!$chk->fetchColumn()) throw new Exception("Carton $cartonId not in this lot");

      if ($hasW && $hasS) {
        $u = $pdo->prepare("UPDATE inbound_cartons SET bd_rechecked_weight_kg=?, bd_recheck_status=? WHERE id=?");
        $u->execute([$weight, $status, $cartonId]);
      } elseif ($hasW) {
        $u = $pdo->prepare("UPDATE inbound_cartons SET bd_rechecked_weight_kg=? WHERE id=?");
        $u->execute([$weight, $cartonId]);
      } elseif ($hasS) {
        $u = $pdo->prepare("UPDATE inbound_cartons SET bd_recheck_status=? WHERE id=?");
        $u->execute([$status, $cartonId]);
      }

      // After updating weight/status, update total price if price exists
      if ($hasPrice) {
        $pdo->prepare("UPDATE inbound_cartons SET bd_total_price = CASE WHEN bd_rechecked_weight_kg IS NOT NULL AND bd_price_per_kg IS NOT NULL THEN bd_rechecked_weight_kg * bd_price_per_kg ELSE NULL END WHERE id=?")
            ->execute([$cartonId]);
      }
    };

    // Set price per kg for a specific carton (supervisor only)
    if ($action === 'set_price') {
      if (!$super) throw new Exception('Permission denied');
      $cartonId = (int)($_POST['carton_id'] ?? 0);
      $price    = isset($_POST['price']) && $_POST['price'] !== '' ? (float)$_POST['price'] : 0.0;
      if ($cartonId <= 0) throw new Exception('carton_id required');
      if ($price <= 0) throw new Exception('price must be greater than 0');
      $chk = $pdo->prepare("SELECT 1 FROM shipment_lot_cartons WHERE lot_id=? AND carton_id=? LIMIT 1");
      $chk->execute([$lotId, $cartonId]);
      if (!$chk->fetchColumn()) throw new Exception('Carton not in this lot');
      $pdo->prepare("UPDATE inbound_cartons SET bd_price_per_kg=? WHERE id=?")->execute([$price, $cartonId]);
      $pdo->prepare("UPDATE inbound_cartons SET bd_total_price = CASE WHEN bd_rechecked_weight_kg IS NOT NULL THEN bd_rechecked_weight_kg * ? ELSE NULL END WHERE id=?")
          ->execute([$price, $cartonId]);
      echo json_encode(['ok'=>true]); exit;
    }

    // Deliver a carton (mark delivered) — only allowed if payment verified
    if ($action === 'deliver_carton') {
      $cartonId = (int)($_POST['carton_id'] ?? 0);
      if ($cartonId <= 0) throw new Exception('carton_id required');
      $chk2 = $pdo->prepare("SELECT 1 FROM shipment_lot_cartons WHERE lot_id=? AND carton_id=? LIMIT 1");
      $chk2->execute([$lotId, $cartonId]);
      if (!$chk2->fetchColumn()) throw new Exception('Carton not in this lot');
      if ($hasPay) {
        $stPay = $pdo->prepare("SELECT bd_payment_status FROM inbound_cartons WHERE id=?");
        $stPay->execute([$cartonId]);
        $pStat = strtolower((string)$stPay->fetchColumn());
        if ($pStat !== 'verified') throw new Exception('Payment not verified yet');
      }
      if ($hasDel) {
        $pdo->prepare("UPDATE inbound_cartons SET bd_delivery_status='delivered', bd_delivered_at=NOW() WHERE id=?")
            ->execute([$cartonId]);
      } else {
        $pdo->prepare("UPDATE inbound_cartons SET bd_delivered_at=NOW() WHERE id=?")
            ->execute([$cartonId]);
      }
      echo json_encode(['ok'=>true]); exit;
    }

    // Save one carton
    if ($action === 'save_carton') {
      $cartonId = (int)($_POST['carton_id'] ?? 0);
      if ($cartonId<=0) throw new Exception('carton_id required');
      $weight = ($_POST['weight'] ?? '') !== '' ? (float)$_POST['weight'] : null;
      $status = strtolower(trim($_POST['status'] ?? 'pending'));
      if (!in_array($status, ['pending','received','missing'], true)) $status='pending';

      $updateOne($lotId, $cartonId, $weight, $status);

      $lot_status_after = null; $pending = null;
      if ($hasS && $lotStatusCol) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM shipment_lot_cartons lc JOIN inbound_cartons c ON c.id=lc.carton_id WHERE lc.lot_id=? AND COALESCE(c.bd_recheck_status,'pending')='pending'");
        $q->execute([$lotId]);
        $pending = (int)$q->fetchColumn();
        if ($pending === 0) {
          $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='ready_for_review' WHERE id=?")->execute([$lotId]);
          $lot_status_after = 'ready_for_review';
        }
      }
      echo json_encode(['ok'=>true,'pending'=>$pending,'lot_status'=>$lot_status_after]); exit;
    }

    // Bulk save
    if ($action === 'save_bulk') {
      $cartonsJson = $_POST['cartons'] ?? '';
      $cartons = is_string($cartonsJson) ? json_decode($cartonsJson, true) : (array)$cartonsJson;
      if (!is_array($cartons)) throw new Exception('cartons must be JSON array');

      foreach ($cartons as $c) {
        $cid = (int)($c['carton_id'] ?? 0);
        if ($cid<=0) continue;
        $w = (isset($c['weight']) && $c['weight']!=='') ? (float)$c['weight'] : null;
        $s = strtolower(trim($c['status'] ?? 'pending'));
        if (!in_array($s, ['pending','received','missing'], true)) $s='pending';
        $updateOne($lotId, $cid, $w, $s);
      }

      $lot_status_after = null; $pending = null;
      if ($hasS && $lotStatusCol) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM shipment_lot_cartons lc JOIN inbound_cartons c ON c.id=lc.carton_id WHERE lc.lot_id=? AND COALESCE(c.bd_recheck_status,'pending')='pending'");
        $q->execute([$lotId]);
        $pending = (int)$q->fetchColumn();
        if ($pending === 0) {
          $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='ready_for_review' WHERE id=?")->execute([$lotId]);
          $lot_status_after = 'ready_for_review';
        }
      }
      echo json_encode(['ok'=>true,'pending'=>$pending,'lot_status'=>$lot_status_after]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
  } catch (Throwable $e) {
    error_log("BD_LOT_INLINE_API: ".$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ======================= PAGE (GET) ======================= */
$lotParam = trim($_GET['lot'] ?? '');
if ($lotParam==='') { http_response_code(400); echo "Missing lot parameter"; exit; }

// ✅ STRICT: interpret ?lot= as LOT CODE only (no ID lookup)
$st = $pdo->prepare("SELECT * FROM shipment_lots WHERE lot_code=? LIMIT 1");
$st->execute([$lotParam]);
$L = $st->fetch(PDO::FETCH_ASSOC);
if (!$L) { http_response_code(404); echo "Lot not found"; exit; }

$lotCols = $pdo->query("SHOW COLUMNS FROM shipment_lots")->fetchAll(PDO::FETCH_COLUMN);
$hasLockCol = in_array('bd_locked', $lotCols, true);
$locked = $hasLockCol ? (int)($L['bd_locked'] ?? 0) : 0;

$lotStatus = strtolower($L['bd_status'] ?? $L['status'] ?? 'pending');
$isSupervisor = has_super_perm();
$isReceived = in_array($lotStatus, ['received_bd','received'], true);
$isReadyForReview = ($lotStatus === 'ready_for_review');

// Load cartons
$cartonCols = $pdo->query("SHOW COLUMNS FROM inbound_cartons")->fetchAll(PDO::FETCH_COLUMN);
$has_bd_recheck_w = in_array('bd_rechecked_weight_kg', $cartonCols, true);
$has_bd_recheck_s = in_array('bd_recheck_status',     $cartonCols, true);
// Determine presence of per-kg price, total, payment and delivery columns
$hasPrice = in_array('bd_price_per_kg', $cartonCols, true);
$hasTotal = in_array('bd_total_price',  $cartonCols, true);
$hasPay   = in_array('bd_payment_status', $cartonCols, true);
$hasDel   = in_array('bd_delivery_status', $cartonCols, true);

// Load cartons with flexible columns (price, total, payment, delivery)
$ct = $pdo->prepare(
  "SELECT c.id, c.carton_code, c.carton_no, c.weight_kg,
         " . ($has_bd_recheck_w ? "c.bd_rechecked_weight_kg" : "NULL AS bd_rechecked_weight_kg") . ",
         " . ($has_bd_recheck_s ? "COALESCE(c.bd_recheck_status,'pending')" : "'pending'") . " AS bd_recheck_status,
         " . ($hasPrice ? "c.bd_price_per_kg" : "NULL AS bd_price_per_kg") . ",
         " . ($hasTotal ? "c.bd_total_price"    : "NULL AS bd_total_price") . ",
         " . ($hasPay   ? "COALESCE(c.bd_payment_status,'pending')" : "'pending'") . " AS bd_payment_status,
         " . ($hasDel   ? "COALESCE(c.bd_delivery_status,'pending')" : "'pending'") . " AS bd_delivery_status
    FROM shipment_lot_cartons lc
    JOIN inbound_cartons c ON c.id = lc.carton_id
   WHERE lc.lot_id = ?
   ORDER BY c.carton_no ASC"
);
$ct->execute([(int)$L['id']]);
$rows = $ct->fetchAll(PDO::FETCH_ASSOC);

/* Editing rules for inputs:
   - Always disabled if received
   - If ready_for_review: disabled for non-supervisors; supervisors can still edit
   - Lock switch (bd_locked): if ON, disable for non-supervisors; supervisors can still edit
*/
$readonlyForUser =
  $isReceived ||
  (!$isSupervisor && ($isReadyForReview || ($hasLockCol && $locked)));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>BD Inbound — Lot <?= e($L['lot_code'] ?? $L['id']) ?></title>
  <style>
    :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--line:#eee}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
    .container{max-width:1100px;margin:24px auto;padding:18px;background:var(--card);border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
    .muted{color:#6b7280}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
    .btn{display:inline-block;padding:8px 12px;border:1px solid var(--ink);border-radius:8px;text-decoration:none;color:#111827;background:#fff;cursor:pointer}
    .btn[disabled]{opacity:.5;pointer-events:none}
    input,select{padding:8px 10px;border:1px solid #ddd;border-radius:8px}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .chip{font-size:12px;border-radius:999px;padding:4px 8px;border:1px solid #ddd;background:#fafafa}
    .chip.ok{border-color:#10b981;color:#065f46;background:#ecfdf5}
    .chip.err{border-color:#ef4444;color:#7f1d1d;background:#fef2f2}
    .chip.saving{border-color:#f59e0b;color:#7c2d12;background:#fffbeb}
    tr.warn{background:#fff8dc}
    .mono{font-variant-numeric: tabular-nums;}
  </style>
</head>
<body>
<header>
  <div><b>Bangladesh Inbound</b> — Lot <?= e($L['lot_code'] ?? $L['id']) ?></div>
  <nav class="row">
    <a class="btn" href="/app/bd_inbound.php">Back</a>
    <?php if ($isSupervisor && $hasLockCol): ?>
      <button class="btn" id="toggleLockBtn"><?= $locked ? 'Unlock Editing' : 'Lock Editing' ?></button>
    <?php endif; ?>
    <?php if ($isSupervisor && $isReadyForReview): ?>
      <button class="btn" id="markReceivedBtn">Mark Lot Received</button>
    <?php endif; ?>
  </nav>
</header>

<div class="container">
  <p class="muted" style="margin:0 0 10px">
    Status: <b><?= e($lotStatus) ?></b>
    · Locked: <b><?= $hasLockCol ? ($locked?'Yes':'No') : 'N/A' ?></b>
    · Courier: <?= e($L['courier_name'] ?? '-') ?>
    · Tracking: <?= e($L['tracking_no'] ?? '-') ?>
    · Shipped: <?= e($L['shipped_at'] ?? '-') ?>
    · Cleared: <?= e($L['custom_cleared_at'] ?? $L['cleared_at'] ?? '-') ?>
    · Received: <?= e($L['bd_received_at'] ?? $L['received_at'] ?? '-') ?>
  </p>

  <form method="post" action="/api/bd_lot_custom_clear.php" class="row" style="margin-bottom:16px">
    <input type="hidden" name="lot_code" value="<?= e($L['lot_code']) ?>">
    <button class="btn" <?= ($isReceived || !empty($L['custom_cleared_at']) || !empty($L['cleared_at'])) ? 'disabled' : '' ?>>Mark Custom Cleared</button>
  </form>

  <h3 style="margin:0 0 10px">Receive Cartons</h3>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Carton</th>
        <th>Origin (kg)</th>
        <th>BD (kg)</th>
        <th>Status</th>
        <th>Δ (kg)</th>
        <?php if ($hasPrice): ?>
          <th>Price/kg</th>
        <?php endif; ?>
        <?php if ($hasTotal): ?>
          <th>Total</th>
        <?php endif; ?>
        <?php if ($hasPay): ?>
          <th>Payment</th>
        <?php endif; ?>
        <?php if ($hasDel): ?>
          <th>Delivery</th>
        <?php endif; ?>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r):
        $lastW = $r['bd_rechecked_weight_kg'];
        $lastS = $r['bd_recheck_status'];
        $diff = ($lastW!==null && $r['weight_kg']!==null) ? ((float)$lastW - (float)$r['weight_kg']) : null;
        $warn = ($diff!==null && $r['weight_kg']>0 && abs($diff)/$r['weight_kg']>0.10);
      ?>
        <tr data-row="<?= (int)$r['id'] ?>"
            data-origin="<?= (float)$r['weight_kg'] ?>"
            data-lastw="<?= $lastW!==null ? (float)$lastW : '' ?>"
            data-lasts="<?= e($lastS) ?>"
            <?= $warn?'class="warn"':'' ?>>
          <td><?= (int)$r['carton_no'] ?></td>
          <td><?= e($r['carton_code']) ?></td>
          <td><?= e($r['weight_kg']) ?></td>
          <td>
            <input type="number" step="0.001"
                   value="<?= e($lastW) ?>"
                   data-carton="<?= (int)$r['id'] ?>"
                   class="w"
                   <?= $readonlyForUser ? 'disabled' : '' ?>>
          </td>
          <td>
            <select data-carton="<?= (int)$r['id'] ?>" class="s" <?= $readonlyForUser ? 'disabled' : '' ?>>
              <?php foreach(['pending','received','missing'] as $opt): ?>
                <option value="<?= $opt ?>" <?= $lastS===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="muted diff"><?= $diff!==null?number_format($diff,3):'—' ?></td>
          <?php if ($hasPrice): ?>
          <td>
            <?php if ($isSupervisor && !$readonlyForUser): ?>
              <input type="number" step="0.01" value="<?= $r['bd_price_per_kg'] !== null ? e($r['bd_price_per_kg']) : '' ?>" data-carton="<?= (int)$r['id'] ?>" class="p" <?= $readonlyForUser ? 'disabled' : '' ?>>
            <?php else: ?>
              <?= $r['bd_price_per_kg'] !== null ? '$'.number_format((float)$r['bd_price_per_kg'],2) : '—' ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <?php if ($hasTotal): ?>
          <td class="mono total">
            <?= $r['bd_total_price'] !== null ? '$'.number_format((float)$r['bd_total_price'],2) : '—' ?>
          </td>
          <?php endif; ?>
          <?php if ($hasPay): ?>
          <td>
            <?php
              $ps = strtolower((string)$r['bd_payment_status']);
              $pClass = ($ps==='verified'?'ok':($ps==='rejected'?'err':'saving'));
            ?>
            <span class="chip <?= $pClass ?>"><?= e($ps) ?></span>
          </td>
          <?php endif; ?>
          <?php if ($hasDel): ?>
          <td>
            <?php if (strtolower((string)$r['bd_delivery_status']) === 'delivered'): ?>
              <span class="chip ok">delivered</span>
            <?php elseif ($hasPay && strtolower((string)$r['bd_payment_status']) === 'verified' && !$readonlyForUser): ?>
              <button type="button" class="btn deliver" data-carton="<?= (int)$r['id'] ?>">Deliver</button>
            <?php else: ?>
              <span class="chip saving">pending</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <button class="btn undo" data-carton="<?= (int)$r['id'] ?>" <?= $readonlyForUser ? 'disabled' : '' ?>>Undo</button>
            <span class="chip" id="saved-<?= (int)$r['id'] ?>">—</span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:12px">
    <button class="btn" id="saveAll" <?= $readonlyForUser ? 'disabled' : '' ?>>Save Received</button>
    <span id="msg" class="muted" style="margin-left:10px"></span>
  </div>
</div>

<script>
const lotCode = <?= json_encode($L['lot_code'] ?? '') ?>;
const isSupervisor = <?= $isSupervisor ? 'true' : 'false' ?>;
const readOnly = <?= $readonlyForUser ? 'true' : 'false' ?>;

function debounce(fn, delay=600){ let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), delay); }; }

async function markLotReceived(){
  const ok = confirm('Mark the whole lot as RECEIVED? This will timestamp the lot and close it.');
  if(!ok) return;
  const form = new URLSearchParams();
  form.set('action','mark_received');
  form.set('lot_code', lotCode);
  const r = await fetch(window.location.pathname, {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    credentials:'include', body: form
  });
  const j = await r.json();
  if(!j.ok){ alert(j.error || 'Failed'); return; }
  location.reload();
}

// Save one carton (with >10% deviation confirm)
async function saveCarton(cartonId, weight, status, origin, rowEl){
  const chip = document.getElementById('saved-'+cartonId);
  if (chip){ chip.textContent = 'Saving…'; chip.className = 'chip saving'; }

  if (origin && weight !== '' && weight != null) {
    const dev = Math.abs((parseFloat(weight) - parseFloat(origin)) / parseFloat(origin));
    if (dev > 0.10) {
      const ok = confirm(`⚠️ BD weight deviates >10%\nOrigin: ${origin} kg\nBD: ${weight} kg\nSave anyway?`);
      if (!ok) { if (chip){ chip.textContent='Cancelled'; chip.className='chip err'; } return; }
    }
  }

  try {
    const form = new URLSearchParams();
    form.set('action','save_carton');
    form.set('lot_code',lotCode);
    form.set('carton_id',cartonId);
    if (weight !== '' && weight != null) form.set('weight',weight);
    form.set('status',status || 'pending');

    const r = await fetch(window.location.pathname, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      credentials:'include', body: form
    });
    const j = await r.json();
    if (!j.ok) { if (chip){ chip.textContent=j.error || 'Error'; chip.className='chip err'; } return; }

    if (chip){ chip.textContent = 'Saved'; chip.className='chip ok'; }
    const originVal = parseFloat(origin);
    const bdVal = (weight !== '' && weight != null) ? parseFloat(weight) : NaN;
    const diffCell = rowEl.querySelector('.diff');
    if (!isNaN(originVal) && !isNaN(bdVal)) {
      const diff = (bdVal - originVal).toFixed(3);
      diffCell.textContent = diff;
      rowEl.classList.toggle('warn', Math.abs(bdVal - originVal)/originVal > 0.10);
    } else {
      diffCell.textContent = '—';
      rowEl.classList.remove('warn');
    }
    rowEl.dataset.lastw = (weight !== '' && weight != null) ? String(weight) : '';
    rowEl.dataset.lasts = status || 'pending';

    const priceInput = rowEl.querySelector('input.p');
    if (priceInput) {
      const priceVal = parseFloat(priceInput.value || '');
      if (!isNaN(priceVal)) {
        const weightVal = (weight !== '' && weight != null) ? parseFloat(weight) : originVal;
        if (!isNaN(weightVal)) {
          const totCell = rowEl.querySelector('.total');
          if (totCell) totCell.textContent = '$' + (weightVal * priceVal).toFixed(2);
        }
      }
    }

    if (j.lot_status && j.lot_status === 'ready_for_review') {
      setTimeout(()=>location.reload(), 600);
    }
  } catch (e) {
    if (chip){ chip.textContent='Error'; chip.className='chip err'; }
  }
}

if (!readOnly) {
  document.querySelectorAll('tr[data-row]').forEach(tr=>{
    const id = parseInt(tr.dataset.row,10);
    const origin = parseFloat(tr.dataset.origin);
    const inp = tr.querySelector('input.w');
    const sel = tr.querySelector('select.s');

    if (inp) {
      const handler = debounce(()=>{
        const w = (inp.value !== '') ? parseFloat(inp.value) : '';
        const s = sel ? sel.value : 'pending';
        saveCarton(id, w, s, origin, tr);
      }, 600);
      inp.addEventListener('input', handler);
      inp.addEventListener('change', handler);
    }

    if (sel) {
      sel.addEventListener('change', ()=>{
        const w = (inp && inp.value !== '') ? parseFloat(inp.value) : '';
        saveCarton(id, w, sel.value, origin, tr);
      });
    }

    tr.querySelector('.undo')?.addEventListener('click', ()=>{
      const inp = tr.querySelector('input.w');
      const sel = tr.querySelector('select.s');
      const lastW = tr.dataset.lastw;
      const lastS = tr.dataset.lasts || 'pending';
      if (inp) inp.value = lastW;
      if (sel) sel.value = lastS;
      const w = (lastW !== '') ? parseFloat(lastW) : '';
      saveCarton(id, w, lastS, parseFloat(tr.dataset.origin), tr);
    });

    const priceInp = tr.querySelector('input.p');
    if (priceInp) {
      priceInp.addEventListener('change', async () => {
        const valStr = priceInp.value;
        const priceVal = valStr !== '' ? parseFloat(valStr) : 0;
        if (isNaN(priceVal) || priceVal <= 0) return;
        const form = new URLSearchParams();
        form.set('action','set_price');
        form.set('lot_code', lotCode);
        form.set('carton_id', id);
        form.set('price', priceVal);
        try {
          const r = await fetch(window.location.pathname, {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            credentials:'include', body: form
          });
          const j = await r.json();
          if (!j.ok) { alert(j.error || 'Failed'); return; }
          const weightVal = (inp && inp.value !== '') ? parseFloat(inp.value) : parseFloat(tr.dataset.origin);
          if (!isNaN(weightVal)) {
            const totCell = tr.querySelector('.total');
            if (totCell) totCell.textContent = '$' + (weightVal * priceVal).toFixed(2);
          }
        } catch (e) {
          console.error(e);
        }
      });
    }

    const deliverBtn = tr.querySelector('button.deliver');
    if (deliverBtn) {
      deliverBtn.addEventListener('click', async () => {
        if (!confirm('Mark this carton as delivered?')) return;
        const form = new URLSearchParams();
        form.set('action','deliver_carton');
        form.set('lot_code', lotCode);
        form.set('carton_id', id);
        try {
          const r = await fetch(window.location.pathname, {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            credentials:'include', body: form
          });
          const j = await r.json();
          if (!j.ok) { alert(j.error || 'Failed'); return; }
          deliverBtn.parentElement.innerHTML = '<span class="chip ok">delivered</span>';
        } catch (e) {
          console.error(e);
        }
      });
    }
  });

  document.getElementById('saveAll')?.addEventListener('click', async ()=>{
    const byId = {};
    document.querySelectorAll('tr[data-row]').forEach(tr=>{
      const id = parseInt(tr.dataset.row,10);
      const inp = tr.querySelector('input.w');
      const sel = tr.querySelector('select.s');
      byId[id] = {
        carton_id: id,
        weight: (inp && inp.value !== '') ? parseFloat(inp.value) : '',
        status: sel ? sel.value : 'pending'
      };
    });
    const cartons = Object.values(byId);
    const msg = document.getElementById('msg');

    try{
      const form = new URLSearchParams();
      form.set('action','save_bulk');
      form.set('lot_code',lotCode);
      form.set('cartons', JSON.stringify(cartons));

      const r = await fetch(window.location.pathname, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'include', body: form
      });
      const j = await r.json();
      if(!j.ok){ msg.textContent=j.error||'Failed'; msg.style.color='#a00'; return; }
      msg.textContent='Saved'; msg.style.color='#0a0';
      if (j.lot_status && j.lot_status==='ready_for_review') {
        setTimeout(()=>location.reload(), 600);
      }
    }catch(e){ msg.textContent='Failed'; msg.style.color='#a00'; }
  });
}

document.getElementById('toggleLockBtn')?.addEventListener('click', async ()=>{
  if (!isSupervisor) { alert('Permission denied'); return; }
  const ok = confirm('Toggle editing lock for this lot?');
  if (!ok) return;
  const form = new URLSearchParams();
  form.set('action','toggle_lock');
  form.set('lot_code', lotCode);
  const r = await fetch(window.location.pathname, {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    credentials:'include', body: form
  });
  const j = await r.json();
  if (!j.ok){ alert(j.error || 'Failed'); return; }
  location.reload();
});

document.getElementById('markReceivedBtn')?.addEventListener('click', markLotReceived);
</script>
</body>
</html>
