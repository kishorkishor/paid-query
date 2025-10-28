<?php
// /app/qc_supervisor_order.php — QC Supervisor review (handoff to Chinese Inbound on approval)
require_once __DIR__.'/auth.php';
require_login();
require_perm('qc_supervisor_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

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
function flash_set($m,$t='ok'){ $_SESSION['_f']=['m'=>$m,'t'=>$t]; }
function flash_get(){ $x=$_SESSION['_f']??null; unset($_SESSION['_f']); return $x; }
function is_image_path($p){
  $ext=strtolower(pathinfo((string)$p, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
}

/* --- Team ids with safe fallbacks --- */
$qcTeamId = team_id_by_name_or_code($pdo,'QC','qc');
if(!$qcTeamId) $qcTeamId = 13; // hard fallback used elsewhere

$chinaInboundTeamId = team_id_by_name_or_code($pdo,'Chinese Inbound','ch_inbound');

/* --- Order load & guard --- */
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if(!$orderId){ http_response_code(400); echo 'Bad order id'; exit; }

$st=$pdo->prepare("
  SELECT o.*, q.product_name, q.id AS qid
    FROM orders o
    LEFT JOIN queries q ON q.id=o.query_id
   WHERE o.id=?
   LIMIT 1
");
$st->execute([$orderId]);
$o=$st->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); echo 'Order not found'; exit; }
if((int)$o['current_team_id'] !== (int)$qcTeamId){ http_response_code(403); echo 'Order not in QC'; exit; }
$qid = (int)($o['qid'] ?? 0);

/* ===== Approve QC -> Handoff to Chinese Inbound (status Ready to ship) ===== */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='approve_qc'){
  require_perm('qc_approve');

  if(!$chinaInboundTeamId){
    flash_set('Chinese Inbound team is not configured (name: "Chinese Inbound", code: "ch_inbound").','err');
    header("Location: /app/qc_supervisor_order.php?id=$orderId"); exit;
  }

  try{
    $pdo->beginTransaction();

    // Update most recent qc_checks row: mark as passed (keep created_by unchanged)
    $pdo->prepare("
      UPDATE qc_checks
         SET result='passed',
             approved_by=?,
             approved_at=NOW()
       WHERE order_id=?
       ORDER BY id DESC
       LIMIT 1
    ")->execute([$me,$orderId]);

    // Handoff to Chinese Inbound; set status
    $pdo->prepare("
      UPDATE orders
         SET previous_team_id=current_team_id,
             current_team_id=:next,
             status='Ready to ship'
       WHERE id=:id
    ")->execute([':next'=>$chinaInboundTeamId, ':id'=>$orderId]);

    // Audit + internal note
    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'qc_approved_handoff', JSON_OBJECT('to_team','Chinese Inbound','to_status','Ready to ship'))")
        ->execute([$orderId,$me]);
    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$qid, $orderId, $me, 'QC supervisor approved. Handoff to Chinese Inbound (Ready to ship).']);

    $pdo->commit();
    flash_set('Approved & handed off to Chinese Inbound (Ready to ship).');
    header("Location: /app/qc_supervisor.php"); exit;
  }catch(Exception $e){
    $pdo->rollBack();
    flash_set('Error: '.$e->getMessage(),'err');
    header("Location: /app/qc_supervisor_order.php?id=$orderId"); exit;
  }
}

/* ===== Reject (only change order status to Rejected) ===== */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reject_qc'){
  require_perm('qc_approve'); // same privilege level as approve
  try{
    $pdo->beginTransaction();

    // Only update status; keep team in QC and do not modify qc_checks/created_by
    $pdo->prepare("
      UPDATE orders
         SET previous_team_id=current_team_id,
             current_team_id=:qc,
             status='Rejected'
       WHERE id=:id
    ")->execute([':qc'=>$qcTeamId, ':id'=>$orderId]);

    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'qc_rejected', JSON_OBJECT('to_status','Rejected'))")
        ->execute([$orderId,$me]);
    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$qid, $orderId, $me, 'QC supervisor rejected. Status set to Rejected.']);

    $pdo->commit();
    flash_set('QC rejected. Status set to Rejected.','err');
  }catch(Exception $e){
    $pdo->rollBack();
    flash_set('Error: '.$e->getMessage(),'err');
  }
  header("Location: /app/qc_supervisor_order.php?id=$orderId"); exit;
}

/* --- Load photos (by order) --- */
$ph=$pdo->prepare("SELECT * FROM qc_photos WHERE order_id=? ORDER BY id DESC");
$ph->execute([$orderId]);
$photos=$ph->fetchAll(PDO::FETCH_ASSOC);

/* --- Load attachments (by query), excluding payments --- */
$attachments = [];
try{
  if($qid){
    $att=$pdo->prepare("
      SELECT id, path, original_name, created_at
        FROM query_attachments
       WHERE query_id=:qid
         AND path NOT LIKE '%/payments/%'
       ORDER BY created_at DESC, id DESC
    ");
    $att->execute([':qid'=>$qid]);
    $attachments=$att->fetchAll(PDO::FETCH_ASSOC);

    // Exclude paths used in order_payments.proof_path
    $pay=$pdo->prepare("SELECT proof_path FROM order_payments WHERE order_id=? AND proof_path IS NOT NULL");
    $pay->execute([$orderId]);
    $paymentPaths=array_map(fn($r)=>$r['proof_path'],$pay->fetchAll(PDO::FETCH_ASSOC));
    if($paymentPaths && $attachments){
      $attachments = array_values(array_filter($attachments, function($a) use ($paymentPaths){
        foreach($paymentPaths as $p){ if($p && strpos($a['path'],$p)!==false) return false; }
        return true;
      }));
    }
  }
}catch(Exception $e){ error_log('qc_supervisor_order attachments fetch error: '.$e->getMessage()); }

$flash=flash_get();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>QC Supervisor — Order <?=e($o['code'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--line:#eceef2;--muted:#6b7280;}
    *{box-sizing:border-box}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
    .container{max-width:1024px;margin:24px auto;padding:18px;background:var(--card);border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06);border:1px solid var(--line)}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #111827;border-radius:10px;text-decoration:none;font-weight:600;color:#111827;background:#fff;cursor:pointer}
    .btn.primary{background:#111827;color:#fff}
    .alert{padding:10px;border-radius:10px;margin-bottom:12px}
    .ok{background:#ecfdf5;border:1px solid #10b981;color:#065f46}
    .err{background:#fef2f2;border:1px solid #ef4444;color:#7f1d1d}
    .grid{display:grid;gap:14px}
    .grid.two{grid-template-columns:1fr}
    @media(min-width:900px){ .grid.two{grid-template-columns:1.2fr .8fr} }
    .photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
    .photos img{width:100%;height:120px;object-fit:cover;border-radius:10px;border:1px solid var(--line)}
    .card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px}
    .attach-list{list-style:none;margin:0;padding:0}
    .attach-list li{display:flex;align-items:center;gap:10px;padding:8px 6px;border-bottom:1px dashed var(--line)}
    .attach-list li:last-child{border-bottom:0}
    .thumb{width:56px;height:42px;object-fit:cover;border:1px solid var(--line);border-radius:6px;flex:0 0 auto}
    .muted{color:var(--muted);font-size:13px}
    .actions{display:flex;gap:10px;flex-wrap:wrap}
  </style>
</head>
<body>
<header>
  <div><strong>QC</strong> — Supervisor Review (<?=e($o['code'])?>)</div>
  <nav><a class="btn" href="/app/qc_supervisor.php">Back</a></nav>
</header>

<div class="container">
  <?php if($flash): ?><div class="alert <?=$flash['t']?>"><?=e($flash['m'])?></div><?php endif; ?>

  <h2 style="margin:0 0 6px">Customer: <?=e($o['customer_name'])?> | Status: <?=e($o['status'])?></h2>
  <div class="muted" style="margin-bottom:14px">Product: <?=e($o['product_name']??'-')?> · Qty: <?= (int)$o['quantity'] ?></div>

  <div class="grid two">
    <!-- Left: QC photos -->
    <section class="card">
      <h3 style="margin:0 0 8px">QC Photos</h3>
      <?php if(!$photos): ?>
        <p class="muted">No photos uploaded yet.</p>
      <?php else: ?>
        <div class="photos">
          <?php foreach($photos as $p): ?>
            <div><img src="<?=e($p['path'])?>" alt=""></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Right: Attachments (by query), exclude payments -->
    <aside class="card">
      <h3 style="margin:0 0 8px">Attachments (Query)</h3>
      <?php if(!$attachments): ?>
        <p class="muted">No non-payment attachments found.</p>
      <?php else: ?>
        <ul class="attach-list">
          <?php foreach($attachments as $a): ?>
            <?php $apath=(string)$a['path']; $isImg=is_image_path($apath); ?>
            <li>
              <?php if($isImg): ?>
                <img class="thumb" src="<?=e($apath)?>" alt="">
              <?php else: ?>
                <span aria-hidden="true">📄</span>
              <?php endif; ?>
              <a href="<?=e($apath)?>" target="_blank" rel="noopener">
                <?= e($a['original_name'] ?: basename($apath)) ?>
              </a>
              <?php if(!empty($a['created_at'])): ?>
                <span class="muted" style="margin-left:auto"><?=e($a['created_at'])?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </aside>
  </div>

  <hr style="margin:18px 0">

  <div class="actions">
    <form method="post">
      <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
      <button class="btn primary" type="submit" name="action" value="approve_qc">Approve & Assign to Chinese Inbound</button>
    </form>

    <form method="post">
      <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
      <button class="btn" type="submit" name="action" value="reject_qc">Reject (status → Rejected)</button>
    </form>

    <!-- Shipping and Custom-Cleared are intentionally NOT here; handled by Chinese Inbound -->
  </div>
</div>
</body>
</html>
