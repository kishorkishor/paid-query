<?php
// /app/qc_order.php — QC Member order page (pro UI/UX)
// Preserves: auth, qc_access, team fallback=13, mandatory photo upload,
// order -> query_id -> attachments (exclude payments), audit + messages.
// Change requested: UPDATE qc_checks.result to 'QC Done' (no duplicate rows).

require_once __DIR__.'/auth.php';
require_login();
require_perm('qc_access');

error_reporting(E_ALL);
ini_set('display_errors','0');          // set '1' temporarily to debug, then back to '0'
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

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
function flash_set($m,$t='ok'){ $_SESSION['_f']=['m'=>$m,'t'=>$t]; }
function flash_get(){ $x=$_SESSION['_f']??null; unset($_SESSION['_f']); return $x; }
function is_image_path($p){
  $ext = strtolower(pathinfo((string)$p, PATHINFO_EXTENSION));
  return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
}

/* --- Resolve QC team id (hard fallback = 13) --- */
$qcTeamId = 13;
$found = team_id_by_name_or_code($pdo,'QC','qc');
if ($found) { $qcTeamId = (int)$found; }
if (!$qcTeamId) { http_response_code(500); echo 'QC team misconfigured.'; exit; }

/* --- Order load & guard --- */
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
if(!$orderId){ http_response_code(400); echo 'Bad order id'; exit; }

$st=$pdo->prepare("
  SELECT o.*, q.id AS qid, q.product_name
    FROM orders o
LEFT JOIN queries q ON q.id = o.query_id
   WHERE o.id = ?
   LIMIT 1
");
$st->execute([$orderId]);
$o=$st->fetch(PDO::FETCH_ASSOC);

if(!$o){ http_response_code(404); echo 'Order not found'; exit; }
if((int)$o['current_team_id'] !== (int)$qcTeamId){ http_response_code(403); echo 'Order not in QC'; exit; }
$qid = (int)$o['qid'];

/* ===========================
   Upload photo(s)
   =========================== */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload'){
  if(!isset($_FILES['photos'])){ flash_set('No files provided','err'); header("Location: /app/qc_order.php?id=$orderId"); exit; }

  $root = realpath(__DIR__.'/..');
  $uploadDir = $root ? ($root . '/uploads') : __DIR__.'/../uploads';
  if(!is_dir($uploadDir)){ @mkdir($uploadDir,0775,true); }

  $count = count($_FILES['photos']['name']);
  $ins = $pdo->prepare("INSERT INTO qc_photos (order_id,path,original_name,uploaded_by) VALUES (?,?,?,?)");
  $uploaded = 0;

  for($i=0;$i<$count;$i++){
    if(($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $name = (string)$_FILES['photos']['name'][$i];
    $tmp  = (string)$_FILES['photos']['tmp_name'][$i];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if(!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) continue;

    $new  = 'qc_'.$orderId.'_'.time().'_'.bin2hex(random_bytes(3)).'.'.$ext;
    $dest = rtrim($uploadDir,'/').'/'.$new;

    if(move_uploaded_file($tmp,$dest)){
      $ins->execute([$orderId, '/uploads/'.$new, $name, $me]);
      $uploaded++;
    }
  }

  flash_set($uploaded ? 'Photos uploaded.' : 'No valid files uploaded.','ok');
  header("Location: /app/qc_order.php?id=$orderId"); exit;
}

/* ===========================
   Mark QC Done (requires photos) — UPDATE qc_checks to 'QC Done'
   =========================== */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='qc_done'){
  require_perm('qc_mark_done');

  // Ensure at least one photo uploaded
  $chk = $pdo->prepare("SELECT COUNT(*) FROM qc_photos WHERE order_id=?");
  $chk->execute([$orderId]);
  if((int)$chk->fetchColumn() === 0){
    flash_set('At least one photo must be uploaded before marking QC Done.','err');
    header("Location: /app/qc_order.php?id=$orderId"); exit;
  }

  try{
    $pdo->beginTransaction();

    // 1️⃣ Update order status
    $pdo->prepare("UPDATE orders
                      SET previous_team_id=current_team_id,
                          current_team_id=:qc,
                          status='QC Done'
                    WHERE id=:id")
        ->execute([':qc'=>$qcTeamId, ':id'=>$orderId]);

    // 2️⃣ Check existing qc_checks entry
    $sel = $pdo->prepare("SELECT id FROM qc_checks WHERE order_id=? LIMIT 1");
    $sel->execute([$orderId]);
    $qcId = (int)($sel->fetchColumn() ?: 0);

    if ($qcId) {
      // ✅ Update only result and notes — do NOT modify created_by
      $pdo->prepare("
          UPDATE qc_checks
             SET result='QC Done',
                 notes='QC Done by member',
                 approved_by=NULL,
                 approved_at=NULL
           WHERE id=:id
      ")->execute([':id'=>$qcId]);
    } else {
      // ✅ Insert new row (created_by set only once)
      $pdo->prepare("
        INSERT INTO qc_checks (order_id, result, notes, created_by)
        VALUES (?, 'QC Done', 'QC Done by member', ?)
      ")->execute([$orderId, $me]);
    }

    // 3️⃣ Audit + internal note
    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'qc_done', JSON_OBJECT('stage','member'))")
        ->execute([$orderId, $me]);

    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   VALUES (?, ?, ?, 'internal', 'note', ?)")
        ->execute([$qid, $orderId, $me, 'QC member marked QC Done (awaiting supervisor).']);

    $pdo->commit();
    flash_set('QC Done successfully. Sent to supervisor.');
  }catch(Exception $e){
    $pdo->rollBack();
    flash_set('Error: '.$e->getMessage(),'err');
  }

  header("Location: /app/qc_order.php?id=$orderId"); exit;
}


/* --- Load QC photos (thumbnails) --- */
$ph=$pdo->prepare("SELECT * FROM qc_photos WHERE order_id=? ORDER BY id DESC");
$ph->execute([$orderId]);
$photos=$ph->fetchAll(PDO::FETCH_ASSOC);

/* --- Load attachments for this Query (trace order -> query_id), exclude payments --- */
$attachments = [];
try {
  $att = $pdo->prepare("
    SELECT id, path, original_name, created_at, mime, size
      FROM query_attachments
     WHERE query_id = :qid
       AND path NOT LIKE '%/payments/%'
     ORDER BY created_at DESC, id DESC
  ");
  $att->execute([':qid'=>$qid]);
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC);

  // Exclude payment proofs from order_payments.proof_path
  $pay = $pdo->prepare("SELECT proof_path FROM order_payments WHERE order_id = ? AND proof_path IS NOT NULL");
  $pay->execute([$orderId]);
  $rows = $pay->fetchAll(PDO::FETCH_ASSOC);
  $paymentPaths = [];
  foreach ($rows as $r) { if (!empty($r['proof_path'])) $paymentPaths[] = $r['proof_path']; }

  if($paymentPaths && $attachments){
    $filtered = [];
    foreach ($attachments as $a) {
      $keep = true;
      foreach ($paymentPaths as $p) {
        if ($p && strpos($a['path'], $p) !== false) { $keep = false; break; }
      }
      if ($keep) $filtered[] = $a;
    }
    $attachments = $filtered;
  }
} catch(Exception $e){
  error_log('qc_order.php attachments fetch error: '.$e->getMessage());
}

$flash=flash_get();
$hasPhotos = !empty($photos);
$isQcDone = strtolower($o['status'] ?? '') === 'qc done';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>QC — Order <?=e($o['code'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --ink:#111827; --bg:#f6f7fb; --card:#fff; --muted:#6b7280; --line:#eaeaea;
  --ok:#10b981; --ok-ink:#065f46; --err:#ef4444; --err-ink:#7f1d1d;
}
*{box-sizing:border-box}
body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
header{position:sticky;top:0;z-index:10;display:flex;gap:12px;justify-content:space-between;align-items:center;padding:14px 18px;background:var(--ink);color:#fff}
header .crumb{opacity:.9;font-weight:600}
header .right a.btn{border-color:#fff;color:#fff}
.container{max-width:1080px;margin:22px auto;padding:0 14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.05);padding:16px}
.grid{display:grid;gap:14px}
.grid.two{grid-template-columns:1fr}
@media(min-width:900px){ .grid.two{grid-template-columns:1.2fr .8fr} }
.h{margin:0 0 10px}
.sub{color:var(--muted);font-size:13px}
.kv{display:flex;flex-wrap:wrap;gap:10px 16px}
.kv .pill{background:#f3f4f6;border:1px solid var(--line);padding:6px 10px;border-radius:999px;font-size:13px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid var(--ink);border-radius:10px;text-decoration:none;font-weight:600;color:var(--ink);background:#fff;cursor:pointer;transition:.15s transform}
.btn:hover{transform:translateY(-1px)}
.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.btn[disabled]{opacity:.6;cursor:not-allowed;transform:none}
hr.sep{border:0;border-top:1px solid var(--line);margin:16px 0}
.alert{padding:10px 12px;border-radius:10px;margin:0 0 12px;display:flex;align-items:flex-start;gap:10px}
.alert.ok{background:#ecfdf5;border:1px solid var(--ok);color:var(--ok-ink)}
.alert.err{background:#fef2f2;border:1px solid var(--err);color:var(--err-ink)}
.alert .close{margin-left:auto;border:0;background:transparent;color:inherit;font-weight:700;cursor:pointer}
.uploader{border:1.5px dashed var(--line);border-radius:12px;background:#fafafa;padding:14px;display:flex;align-items:center;gap:12px}
.uploader input[type=file]{flex:1}
.uploader .hint{font-size:12px;color:var(--muted)}
.photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.photo{position:relative;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff}
.photo img{width:100%;height:140px;object-fit:cover;display:block;transition:transform .2s}
.photo:hover img{transform:scale(1.02)}
.badge{position:absolute;top:8px;left:8px;background:rgba(17,24,39,.8);color:#fff;padding:2px 6px;border-radius:999px;font-size:11px}

/* Attachments + thumbnails */
.attach-list{list-style:none;margin:0;padding:0}
.attach-list li{display:flex;align-items:center;gap:10px;padding:8px 6px;border-bottom:1px dashed var(--line)}
.attach-list li:last-child{border-bottom:0}
.attach-list a{font-weight:600;text-decoration:none;color:var(--ink)}
.attach-small{margin-left:auto;color:var(--muted);font-size:12px}
.thumb{width:56px;height:42px;object-fit:cover;border:1px solid var(--line);border-radius:6px;flex:0 0 auto}
.attach-name{display:inline-flex;gap:8px;align-items:center}

.footer-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.8);display:none;align-items:center;justify-content:center;padding:20px;z-index:50}
.lightbox.open{display:flex}
.lightbox img{max-width:min(1200px,90vw);max-height:85vh;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.5)}
.lightbox .x{position:absolute;top:14px;right:18px;color:#fff;font-size:22px;cursor:pointer}
.help{font-size:12px;color:var(--muted)}
</style>
</head>
<body>
<header>
  <div class="crumb">QC ▸ Order <strong><?=e($o['code'])?></strong></div>
  <div class="right">
    <a class="btn" href="/app/qc.php" title="Back to QC dashboard">← Back</a>
  </div>
</header>

<div class="container">
  <?php if($flash): ?>
    <div class="alert <?=$flash['t']?>">
      <div><?=e($flash['m'])?></div>
      <button class="close" onclick="this.closest('.alert').remove()">×</button>
    </div>
  <?php endif; ?>

  <div class="grid two">
    <!-- LEFT: Photos & Upload -->
    <section class="card">
      <h2 class="h">Product Photos (Mandatory)</h2>
      <p class="sub">Customer: <strong><?=e($o['customer_name'])?></strong> · Status: <strong><?=e($o['status'])?></strong></p>
      <div class="kv" style="margin-bottom:12px">
        <span class="pill">Product: <?=e($o['product_name']??'-')?></span>
        <span class="pill">Qty: <?= (int)$o['quantity'] ?></span>
        <span class="pill">Order ID: <?= (int)$o['id'] ?></span>
      </div>

      <!-- Uploader -->
      <form method="post" enctype="multipart/form-data" class="uploader" id="uploadForm">
        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
        <input id="photoInput" type="file" name="photos[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif" required>
        <button class="btn" type="submit" name="action" value="upload">Upload</button>
      </form>
      <div class="help" style="margin-top:6px">Tip: You can also drag & drop files onto the file field.</div>

      <?php if($photos): ?>
        <hr class="sep">
        <div class="photos" id="photoGrid">
          <?php foreach($photos as $idx=>$p): ?>
            <figure class="photo">
              <img src="<?=e($p['path'])?>" alt="QC photo" data-full="<?=e($p['path'])?>">
              <figcaption class="badge">#<?= (int)$p['id'] ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <hr class="sep">
        <p class="sub">No photos uploaded yet. Please upload at least one photo before marking QC Done.</p>
      <?php endif; ?>
    </section>

    <!-- RIGHT: Attachments -->
    <aside class="card">
      <h3 class="h">Query Attachments (Excl. Payments)</h3>
      <?php if(!$attachments): ?>
        <p class="sub">No non-payment attachments found for this query.</p>
      <?php else: ?>
        <ul class="attach-list">
          <?php foreach($attachments as $a): ?>
            <?php $apath = (string)$a['path']; $isImg = is_image_path($apath); ?>
            <li>
              <?php if($isImg): ?>
                <img class="thumb" src="<?= e($apath) ?>" alt="thumb" loading="lazy" data-full="<?= e($apath) ?>">
              <?php else: ?>
                <span aria-hidden="true">📄</span>
              <?php endif; ?>

              <span class="attach-name">
                <a href="<?= e($apath) ?>" target="_blank" rel="noopener">
                  <?= e($a['original_name'] ?: basename($apath)) ?>
                </a>
              </span>

              <?php if(!empty($a['created_at'])): ?>
                <span class="attach-small"><?= e($a['created_at']) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </aside>
  </div>

  <section class="card" style="margin-top:14px">
    <div class="footer-actions">
      <form method="post" onsubmit="return confirmQcDone();">
        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
        <button
          class="btn primary"
          type="submit"
          name="action"
          value="qc_done"
          <?php
            if ($isQcDone) {
              echo 'disabled title="Already marked as QC Done"';
            } elseif (!$hasPhotos) {
              echo 'disabled title="Upload at least one photo to proceed"';
            }
          ?>
        >
          ✅ Mark QC Done & Send to Supervisor
        </button>
      </form>
      <?php if ($isQcDone): ?>
        <span class="help" style="color:#059669">✔ This order is already marked as <strong>QC Done</strong>.</span>
      <?php else: ?>
        <span class="help">This will log an internal note and move the order forward. Photos are required.</span>
      <?php endif; ?>
    </div>
  </section>
</div>

<!-- Lightweight Lightbox (no external libs) -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo preview">
  <span class="x" id="lbClose" aria-label="Close">×</span>
  <img id="lbImg" src="" alt="Preview">
</div>

<script>
(function(){
  // Drag & drop helper (progressive; uses the same input + form)
  const input = document.getElementById('photoInput');
  const form  = document.getElementById('uploadForm');

  const uploader = form;
  ['dragenter','dragover'].forEach(evt =>
    uploader.addEventListener(evt, e => { e.preventDefault(); uploader.style.background='#f0f0f5'; }, false)
  );
  ['dragleave','drop'].forEach(evt =>
    uploader.addEventListener(evt, e => { e.preventDefault(); uploader.style.background='#fafafa'; }, false)
  );

  uploader.addEventListener('drop', (e) => {
    if (!e.dataTransfer || !e.dataTransfer.files) return;
    input.files = e.dataTransfer.files; // assign to the same input
  }, false);

  // Simple lightbox for QC photos
  const grid = document.getElementById('photoGrid');
  const lb   = document.getElementById('lightbox');
  const lbImg= document.getElementById('lbImg');
  const lbX  = document.getElementById('lbClose');

  if(grid){
    grid.addEventListener('click', (e)=>{
      const img = e.target.closest('img');
      if(!img) return;
      lbImg.src = img.getAttribute('data-full') || img.src;
      lb.classList.add('open');
    });
  }
  lbX.addEventListener('click', ()=> lb.classList.remove('open'));
  lb.addEventListener('click', (e)=>{ if(e.target === lb) lb.classList.remove('open'); });

  // Thumbnails in attachments also open lightbox
  const attachList = document.querySelector('.attach-list');
  if (attachList) {
    attachList.addEventListener('click', (e) => {
      const img = e.target.closest('img.thumb');
      if (!img) return;
      lbImg.src = img.getAttribute('data-full') || img.src;
      lb.classList.add('open');
      e.preventDefault();
    });
  }

  // Guard confirm for QC Done
  window.confirmQcDone = function(){
    <?php if(!$hasPhotos || $isQcDone): ?>
      return false;
    <?php else: ?>
      return confirm('Mark QC Done and send to supervisor?');
    <?php endif; ?>
  };
})();
</script>
</body>
</html>
