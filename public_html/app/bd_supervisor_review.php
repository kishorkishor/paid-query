<?php
// /app/bd_supervisor_review.php — Bangladesh Inbound: Supervisor detailed review
// - Supervisor only
// - Per-carton: BD weight, BD status (received/missing), price per kg, computed total
// - Auto-save per line (AJAX) with robust schema detection (no fatal if columns missing)
// - Submit as Received (marks lot received_bd / bd_received_at and LOCKS the lot)
// - Recalculate totals button (recomputes and stores totals for all cartons in the lot)
// - Enforces: price/kg required (>0) for every carton before submission
// - Refuses edits/saves after lot locked

require_once __DIR__ . '/auth.php';

require_login();

// ---- permission: supervisor ----
function has_super_perm(): bool {
  if (function_exists('can')) return (bool)can('bd_inbound_supervisor');
  return !empty($_SESSION['admin']['perms']['bd_inbound_supervisor']);
}
if (!has_super_perm()) { http_response_code(403); echo "Forbidden (supervisor access required)"; exit; }

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ------------------ Column discovery helpers ------------------ */
function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = "$table::$col";
  if (isset($cache[$key])) return $cache[$key];
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$col]);
  $cache[$key] = (bool)$st->fetch();
  return $cache[$key];
}

// Shipment lot flexible columns
$lot_status_col   = col_exists($pdo,'shipment_lots','bd_status')      ? 'bd_status'      : (col_exists($pdo,'shipment_lots','status')      ? 'status'      : null);
$lot_received_col = col_exists($pdo,'shipment_lots','bd_received_at') ? 'bd_received_at' : (col_exists($pdo,'shipment_lots','received_at') ? 'received_at' : null);
$lot_cleared_col  = col_exists($pdo,'shipment_lots','custom_cleared_at') ? 'custom_cleared_at' : (col_exists($pdo,'shipment_lots','cleared_at') ? 'cleared_at' : null);
$lot_code_col     = col_exists($pdo,'shipment_lots','lot_code') ? 'lot_code' : null;

// Carton flexible columns
$has_bd_weight    = col_exists($pdo,'inbound_cartons','bd_rechecked_weight_kg');
$has_bd_status    = col_exists($pdo,'inbound_cartons','bd_recheck_status');
$has_price_kg     = col_exists($pdo,'inbound_cartons','bd_price_per_kg');    // REQUIRED for submit
// Your DB uses bd_total_price (not bd_price_total). Detect both but prefer bd_total_price.
$has_total_price  = col_exists($pdo,'inbound_cartons','bd_total_price') || col_exists($pdo,'inbound_cartons','bd_price_total');
$total_price_col  = col_exists($pdo,'inbound_cartons','bd_total_price') ? 'bd_total_price'
                 : (col_exists($pdo,'inbound_cartons','bd_price_total') ? 'bd_price_total' : null);

function set_clause(array $arr): string {
  return implode(', ', array_filter($arr, fn($x)=>$x!==null && $x!==''));
}

/* ------------------ Resolve lot ------------------ */
$lotParam = trim($_GET['lot'] ?? '');
if ($lotParam === '') { http_response_code(400); echo "Missing lot parameter"; exit; }

if (ctype_digit($lotParam)) {
  $st = $pdo->prepare("
    SELECT id,
           ".($lot_code_col ? "$lot_code_col" : "CAST(id AS CHAR)")." AS lot_code,
           courier_name, tracking_no, shipped_at,
           ".($lot_status_col ? "$lot_status_col" : "NULL")." AS lot_status,
           ".($lot_cleared_col ? "$lot_cleared_col" : "NULL")." AS cleared_at,
           ".($lot_received_col ? "$lot_received_col" : "NULL")." AS received_at
    FROM shipment_lots WHERE id=? LIMIT 1
  ");
  $st->execute([(int)$lotParam]);
} else {
  if (!$lot_code_col) { http_response_code(400); echo "This database does not have lot_code column; pass numeric lot id instead."; exit; }
  $st = $pdo->prepare("
    SELECT id,
           $lot_code_col AS lot_code,
           courier_name, tracking_no, shipped_at,
           ".($lot_status_col ? "$lot_status_col" : "NULL")." AS lot_status,
           ".($lot_cleared_col ? "$lot_cleared_col" : "NULL")." AS cleared_at,
           ".($lot_received_col ? "$lot_received_col" : "NULL")." AS received_at
    FROM shipment_lots WHERE $lot_code_col=? LIMIT 1
  ");
  $st->execute([$lotParam]);
}
$L = $st->fetch(PDO::FETCH_ASSOC);
if (!$L) { http_response_code(404); echo "Lot not found"; exit; }

$lotId = (int)$L['id'];
$lotStatus = strtolower((string)($L['lot_status'] ?? ''));
$lockedAt  = $L['received_at'] ?? null;
$isLocked  = !empty($lockedAt) || in_array($lotStatus, ['received','received_bd'], true);

/* ------------------ AJAX saves ------------------ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  try {
    $action = $_POST['action'];

    // Block any saves on locked lots
    if ($isLocked && !in_array($action, ['recompute_preview'], true)) {
      echo json_encode(['ok'=>false,'error'=>'Lot is locked (already received).']); exit;
    }

    if ($action === 'save_row') {
      $cartonId = (int)($_POST['carton_id'] ?? 0);
      if ($cartonId <= 0) throw new Exception('carton_id required');

      // ensure carton belongs to lot
      $bel = $pdo->prepare("SELECT 1 FROM shipment_lot_cartons WHERE lot_id=? AND carton_id=? LIMIT 1");
      $bel->execute([$lotId, $cartonId]);
      if (!$bel->fetchColumn()) throw new Exception('Carton not in this lot');

      // inputs
      $weight = ($_POST['weight'] ?? '') !== '' ? (float)$_POST['weight'] : null;
      $status = strtolower(trim($_POST['status'] ?? 'pending'));
      if (!in_array($status, ['pending','received','missing'], true)) $status = 'pending';
      $priceKg = ($_POST['price_kg'] ?? '') !== '' ? (float)$_POST['price_kg'] : null;

      // compute total using effective weight (bd_rechecked or origin)
      $originWeight = (float)$pdo->query("SELECT weight_kg FROM inbound_cartons WHERE id={$cartonId}")->fetchColumn();
      $effW = $weight !== null ? $weight : (float)$pdo->query("SELECT ".($has_bd_weight?"bd_rechecked_weight_kg":"weight_kg")." FROM inbound_cartons WHERE id={$cartonId}")->fetchColumn();
      if (!$effW) $effW = $originWeight;

      $sets = []; $args = [];
      if ($has_bd_weight) { $sets[] = "bd_rechecked_weight_kg=?"; $args[] = $weight; }
      if ($has_bd_status) { $sets[] = "bd_recheck_status=?";     $args[] = $status; }
      if ($has_price_kg)  { $sets[] = "bd_price_per_kg=?";       $args[] = $priceKg; }

      $total = null;
      if ($has_total_price && $has_price_kg && $priceKg !== null && $total_price_col) {
        $total = round($effW * $priceKg, 2);
        $sets[] = "$total_price_col=?";
        $args[] = $total;
      }

      if ($sets) {
        $sql = "UPDATE inbound_cartons SET ".set_clause($sets)." WHERE id=?";
        $args[] = $cartonId;
        $u = $pdo->prepare($sql);
        $u->execute($args);
      }

      echo json_encode([
        'ok'=>true,
        'computed_total'=>$total
      ]);
      exit;
    }

    if ($action === 'recalc_totals') {
      // recompute totals for all cartons in the lot
      if (!$has_price_kg || !$total_price_col) {
        echo json_encode(['ok'=>false,'error'=>'Price/total columns not present.']); exit;
      }
      $upd = $pdo->prepare("
        UPDATE inbound_cartons c
        JOIN shipment_lot_cartons lc ON lc.carton_id=c.id
        SET c.$total_price_col = ROUND(COALESCE(c.bd_rechecked_weight_kg, c.weight_kg) * c.bd_price_per_kg, 2)
        WHERE lc.lot_id=?
      ");
      $upd->execute([$lotId]);

      // return fresh per-row totals + grand total
      $totals = $pdo->prepare("
        SELECT c.id, c.$total_price_col AS t
        FROM inbound_cartons c
        JOIN shipment_lot_cartons lc ON lc.carton_id=c.id
        WHERE lc.lot_id=?
      ");
      $totals->execute([$lotId]);
      $rows = $totals->fetchAll(PDO::FETCH_KEY_PAIR);

      $grand = 0.0;
      foreach ($rows as $val) { if ($val !== null) $grand += (float)$val; }

      echo json_encode(['ok'=>true,'rows'=>$rows,'grand'=>round($grand,2)]);
      exit;
    }

    if ($action === 'submit_received') {
      // price/kg column must exist
      if (!$has_price_kg) {
        echo json_encode(['ok'=>false,'error'=>"Your database is missing inbound_cartons.bd_price_per_kg. Please add:\nALTER TABLE inbound_cartons ADD COLUMN bd_price_per_kg DECIMAL(10,3) NULL;"]); exit;
      }

      // verify all cartons reviewed (no 'pending')
      $pending = 0;
      if ($has_bd_status) {
        $q = $pdo->prepare("
          SELECT COUNT(*)
          FROM shipment_lot_cartons lc
          JOIN inbound_cartons c ON c.id=lc.carton_id
          WHERE lc.lot_id=? AND COALESCE(c.bd_recheck_status,'pending')='pending'
        ");
        $q->execute([$lotId]);
        $pending = (int)$q->fetchColumn();
      }

      if ($pending>0) { echo json_encode(['ok'=>false,'error'=>"There are $pending pending cartons."]); exit; }

      // require price/kg for every carton (>0)
      $q2 = $pdo->prepare("
        SELECT COUNT(*)
        FROM shipment_lot_cartons lc
        JOIN inbound_cartons c ON c.id=lc.carton_id
        WHERE lc.lot_id=? AND (c.bd_price_per_kg IS NULL OR c.bd_price_per_kg<=0)
      ");
      $q2->execute([$lotId]);
      $missingPrice = (int)$q2->fetchColumn();
      if ($missingPrice>0) {
        echo json_encode(['ok'=>false,'error'=>"Price/kg is required for all cartons (missing: $missingPrice)."]); exit;
      }

      // Ensure totals are computed and saved
      if ($has_total_price && $total_price_col) {
        $upd = $pdo->prepare("
          UPDATE inbound_cartons c
          JOIN shipment_lot_cartons lc ON lc.carton_id=c.id
          SET c.$total_price_col = ROUND(COALESCE(c.bd_rechecked_weight_kg, c.weight_kg) * c.bd_price_per_kg, 2)
          WHERE lc.lot_id=? AND (c.$total_price_col IS NULL OR c.$total_price_col=0)
        ");
        $upd->execute([$lotId]);
      }

      // mark lot received & LOCK (use bd_status / bd_received_at if present)
      if ($lot_status_col) {
        $sql = "UPDATE shipment_lots SET $lot_status_col='received_bd'".($lot_received_col ? ", $lot_received_col=NOW()" : "")." WHERE id=?";
        $pdo->prepare($sql)->execute([$lotId]);
      } elseif ($lot_received_col) {
        $pdo->prepare("UPDATE shipment_lots SET $lot_received_col=NOW() WHERE id=?")->execute([$lotId]);
      }

      echo json_encode(['ok'=>true]);
      exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
  } catch(Throwable $e){
    error_log("BD_SUP_REVIEW_SAVE: ".$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ------------------ Load cartons for UI ------------------ */
$sel = "
  c.id, c.carton_no, c.carton_code,
  c.weight_kg,
  ".($has_bd_weight ? "c.bd_rechecked_weight_kg" : "NULL AS bd_rechecked_weight_kg").",
  ".($has_bd_status ? "COALESCE(c.bd_recheck_status,'pending')" : "'pending'")." AS bd_recheck_status,
  ".($has_price_kg  ? "c.bd_price_per_kg"   : "NULL AS bd_price_per_kg").",
  ".($has_total_price && $total_price_col ? "c.$total_price_col" : "NULL AS bd_total_any")."
";
$ct = $pdo->prepare("
  SELECT $sel
  FROM shipment_lot_cartons lc
  JOIN inbound_cartons c ON c.id=lc.carton_id
  WHERE lc.lot_id=?
  ORDER BY c.carton_no ASC
");
$ct->execute([$lotId]);
$rows = $ct->fetchAll(PDO::FETCH_ASSOC);

$isReceived = $isLocked; // alias for template
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>BD Inbound — Supervisor Review (Lot <?= e($L['lot_code']) ?>)</title>
  <style>
    :root{--ink:#111827;--bg:#0b1220;--card:#fff;--line:#eaeaea}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:#111}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
    .container{max-width:1100px;margin:24px auto;padding:18px;background:var(--card);border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.12)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .btn{display:inline-block;padding:10px 14px;border:1px solid #111827;border-radius:10px;background:#fff;color:#111;text-decoration:none;cursor:pointer}
    .btn[disabled]{opacity:.5;pointer-events:none}
    input,select{padding:8px 10px;border:1px solid #ddd;border-radius:8px}
    .muted{color:#6b7280}
    .chip{font-size:12px;border-radius:999px;padding:3px 8px;border:1px solid #ddd;background:#fafafa}
    .ok{border-color:#16a34a;background:#ecfdf5;color:#065f46}
    .warn{border-color:#f59e0b;background:#fffbeb;color:#7c2d12}
    .err{border-color:#ef4444;background:#fef2f2;color:#7f1d1d}
    .right{text-align:right}
  </style>
</head>
<body>
<header>
  <div><b>Bangladesh Inbound</b> — Supervisor Review</div>
  <nav class="row"><a class="btn" href="/app/bd_supervisor.php">Supervisor Dashboard</a></nav>
</header>

<div class="container">
  <p class="muted" style="margin:0 0 12px">
    Lot: <b><?= e($L['lot_code']) ?></b> ·
    Status: <b><?= e($L['lot_status']) ?></b> ·
    Courier: <?= e($L['courier_name'] ?? '-') ?> ·
    Tracking: <?= e($L['tracking_no'] ?? '-') ?> ·
    Shipped: <?= e($L['shipped_at'] ?? '-') ?> ·
    Cleared: <?= e($L['cleared_at'] ?? '-') ?> ·
    Received: <?= e($L['received_at'] ?? '-') ?> <?= $isLocked ? "· <span class='chip ok'>Locked</span>" : "" ?>
  </p>

  <?php if(!$has_price_kg): ?>
    <div class="chip err" style="margin-bottom:10px">
      Price/kg is required before marking as received, but your DB has no <code>inbound_cartons.bd_price_per_kg</code>.
      Add it (and optional total) once:
      <code>ALTER TABLE inbound_cartons ADD COLUMN bd_price_per_kg DECIMAL(10,3) NULL; ALTER TABLE inbound_cartons ADD COLUMN bd_total_price DECIMAL(12,2) NULL;</code>
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Carton</th>
        <th class="right">Origin (kg)</th>
        <th class="right">BD (kg)</th>
        <th>Status</th>
        <th class="right">Price/kg</th>
        <th class="right">Total</th>
        <th>Saved</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $grand = 0.0;
      foreach($rows as $r):
        $origin = (float)$r['weight_kg'];
        $bd     = $r['bd_rechecked_weight_kg'] !== null ? (float)$r['bd_rechecked_weight_kg'] : null;
        $pKg    = $r['bd_price_per_kg'] !== null ? (float)$r['bd_price_per_kg'] : null;
        $effW   = $bd !== null ? $bd : $origin;
        // Use fetched total column if present (bd_total_price or bd_price_total), else compute preview
        $totalFetched = isset($r['bd_total_any']) ? $r['bd_total_any'] : null;
        $total  = $totalFetched !== null ? (float)$totalFetched : (($pKg!==null)? round($effW*$pKg,2) : null);
        if ($total !== null) $grand += $total;
      ?>
      <tr data-id="<?= (int)$r['id'] ?>">
        <td><?= (int)$r['carton_no'] ?></td>
        <td><?= e($r['carton_code']) ?></td>
        <td class="right"><?= number_format((float)$r['weight_kg'],3) ?></td>
        <td class="right">
          <input type="number" step="0.001" class="w" data-id="<?= (int)$r['id'] ?>"
                 value="<?= $bd!==null ? e($bd) : '' ?>" <?= $isLocked ? 'disabled' : '' ?>>
        </td>
        <td>
          <select class="s" data-id="<?= (int)$r['id'] ?>" <?= $isLocked ? 'disabled' : '' ?>>
            <?php foreach(['pending','received','missing'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($r['bd_recheck_status']===$opt)?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="right">
          <input type="number" step="0.01" class="p" data-id="<?= (int)$r['id'] ?>"
                 value="<?= $pKg!==null ? e($pKg) : '' ?>" <?= $isLocked ? 'disabled' : '' ?> <?= $has_price_kg ? '' : 'disabled' ?>>
        </td>
        <td class="right"><span id="t-<?= (int)$r['id'] ?>"><?= $total!==null?number_format($total,2):'—' ?></span></td>
        <td><span class="chip" id="saved-<?= (int)$r['id'] ?>">—</span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="6" class="right">Grand Total:</th>
        <th class="right" id="grand"><?= number_format($grand,2) ?></th>
        <th></th>
      </tr>
    </tfoot>
  </table>

  <div class="row" style="margin-top:12px">
    <button class="btn" id="recalcBtn" <?= ($isLocked || !$has_price_kg || !$has_total_price) ? 'disabled' : '' ?>>Recalculate totals</button>
    <button class="btn" id="submitBtn" <?= ($isLocked || !$has_price_kg) ? 'disabled' : '' ?>>Submit as Received</button>
    <span id="msg" class="muted"></span>
  </div>
</div>

<script>
const isLocked = <?= $isLocked ? 'true' : 'false' ?>;
const requirePrice = <?= $has_price_kg ? 'true' : 'false' ?>;

function $(q,ctx=document){ return ctx.querySelector(q); }
function $all(q,ctx=document){ return Array.from(ctx.querySelectorAll(q)); }
function chip(id){ return $('#saved-'+id); }
function totalSpan(id){ return $('#t-'+id); }
function fmt2(x){ return (Math.round(x*100)/100).toFixed(2); }

function recomputeRow(id){
  const tr = document.querySelector('tr[data-id="'+id+'"]');
  if(!tr) return;
  const origin = parseFloat(tr.children[2].innerText);
  const w = parseFloat($('.w[data-id="'+id+'"]')?.value || NaN);
  const p = parseFloat($('.p[data-id="'+id+'"]')?.value || NaN);
  const effW = isFinite(w) ? w : origin;
  if (isFinite(effW) && isFinite(p)) {
    totalSpan(id).textContent = fmt2(effW*p);
  } else {
    totalSpan(id).textContent = '—';
  }
  recomputeGrand();
}

function recomputeGrand(){
  let g = 0;
  $all('span[id^="t-"]').forEach(span=>{
    const n = parseFloat(span.textContent);
    if (isFinite(n)) g += n;
  });
  $('#grand').textContent = fmt2(g);
}

async function saveRow(id){
  const ch = chip(id);
  if (ch){ ch.textContent='Saving…'; ch.className='chip warn'; }
  const w = $('.w[data-id="'+id+'"]')?.value ?? '';
  const s = $('.s[data-id="'+id+'"]')?.value ?? 'pending';
  const p = $('.p[data-id="'+id+'"]')?.value ?? '';
  try{
    const form = new FormData();
    form.set('action','save_row');
    form.set('carton_id', id);
    form.set('weight', w);
    form.set('status', s);
    form.set('price_kg', p);
    const r = await fetch(window.location.href, { method:'POST', body: form, credentials:'include' });
    const j = await r.json();
    if(!j.ok){ ch.textContent = j.error || 'Error'; ch.className='chip err'; return; }
    ch.textContent = 'Saved'; ch.className='chip ok';
  }catch(e){
    ch.textContent = 'Error'; ch.className='chip err';
  }
}

function bind(){
  if(!isLocked){
    $all('.w').forEach(inp=>{
      inp.addEventListener('input', ()=>{ recomputeRow(inp.dataset.id); });
      inp.addEventListener('change', ()=>{ recomputeRow(inp.dataset.id); saveRow(inp.dataset.id); });
    });
    $all('.p').forEach(inp=>{
      inp.addEventListener('input', ()=>{ recomputeRow(inp.dataset.id); });
      inp.addEventListener('change', ()=>{ recomputeRow(inp.dataset.id); saveRow(inp.dataset.id); });
    });
    $all('.s').forEach(sel=>{
      sel.addEventListener('change', ()=>{ saveRow(sel.dataset.id); });
    });

    $('#recalcBtn')?.addEventListener('click', async ()=>{
      const msg = $('#msg');
      msg.textContent = 'Recalculating…'; msg.style.color='#6b7280';
      try{
        const form = new FormData();
        form.set('action','recalc_totals');
        const r = await fetch(window.location.href, { method:'POST', body: form, credentials:'include' });
        const j = await r.json();
        if(!j.ok){ msg.textContent = j.error || 'Failed'; msg.style.color='#a00'; return; }
        // update UI totals
        if (j.rows){
          Object.keys(j.rows).forEach(id=>{
            const v = j.rows[id];
            const span = totalSpan(id);
            if (span) span.textContent = (v===null?'—':fmt2(parseFloat(v)));
          });
        }
        if (typeof j.grand !== 'undefined') { $('#grand').textContent = fmt2(parseFloat(j.grand)); }
        msg.textContent = 'Totals recalculated.'; msg.style.color='#065f46';
      }catch(e){
        msg.textContent = 'Failed'; msg.style.color='#a00';
      }
    });

    $('#submitBtn')?.addEventListener('click', async ()=>{
      const msg = $('#msg');
      // client-side validation: no pending, all price/kg set > 0
      const pending = $all('.s').some(s => s.value === 'pending');
      if (pending) { msg.textContent='Please resolve all “pending” cartons.'; msg.style.color='#a00'; return; }
      if (requirePrice) {
        const missing = $all('.p').filter(p => p.value === '' || parseFloat(p.value) <= 0).length;
        if (missing>0) { msg.textContent=`Price/kg is required (>0) for all cartons. Missing: ${missing}`; msg.style.color='#a00'; return; }
      }

      msg.textContent = 'Submitting…'; msg.style.color='#6b7280';
      try{
        const form = new FormData();
        form.set('action','submit_received');
        const r = await fetch(window.location.href, { method:'POST', body: form, credentials:'include' });
        const j = await r.json();
        if(!j.ok){ msg.textContent = j.error || 'Failed'; msg.style.color='#a00'; return; }
        msg.textContent = 'Marked as received. Lot locked.'; msg.style.color='#065f46';
        setTimeout(()=>location.reload(), 800);
      }catch(e){
        msg.textContent = 'Failed'; msg.style.color='#a00';
      }
    });
  }
}
bind();
</script>
</body>
</html>
