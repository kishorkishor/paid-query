<?php
// app/query_team_member.php (debug-safe build, attachments saved + clickable links, /public prefix in URLs)
require_once __DIR__ . '/auth.php';

// ---- Debug/Error logging (safe defaults) ----
error_reporting(E_ALL);
ini_set('display_errors','0');             // keep off in prod
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/logs/_php_errors.log');

// File system web root and public URL prefix
$PUBLIC_WEBROOT    = realpath(__DIR__ . '/../public'); // /public_html/public
$PUBLIC_URL_PREFIX = '/public'; // URL path prefix

// The URL base to build absolute links for attachments
$SCHEME = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$HOST   = $_SERVER['HTTP_HOST'] ?? '';

// Log some env info (useful for 500s)
$max_upload    = ini_get('upload_max_filesize');
$max_post      = ini_get('post_max_size');
$max_execution = ini_get('max_execution_time');
error_log("Upload config - max_upload: $max_upload, max_post: $max_post, max_execution: $max_execution");
error_log("Resolved PUBLIC_WEBROOT: " . ($PUBLIC_WEBROOT ?: 'NULL') . " | PUBLIC_URL_PREFIX: $PUBLIC_URL_PREFIX");

// Some hosts need session before require_perm
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_perm('view_queries');

$pdo     = db();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

// ---- Helpers ----
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Make raw URLs clickable (<a>) inside plain text. Supports http/https/www and /uploads/... paths.
 * When encountering /uploads/... we rewrite to /public/uploads/... for correct public URL.
 */
function autolink($text){
  global $PUBLIC_URL_PREFIX;
  $escaped = e((string)$text);
  $pattern = '~(?<!href="|">)((https?://|www\.)[^\s<]+|/uploads/[^\s<]+)~i';
  return preg_replace_callback($pattern, function($m) use ($PUBLIC_URL_PREFIX){
    $raw = $m[1];
    if (stripos($raw, 'www.') === 0) {
      $url = 'http://' . $raw;
    } elseif (stripos($raw, '/uploads/') === 0) {
      $url = rtrim($PUBLIC_URL_PREFIX, '/') . $raw; // -> /public/uploads/...
    } else {
      $url = $raw; // http/https
    }
    return '<a href="'.e($url).'" target="_blank" rel="noopener">'.e($raw).'</a>';
  }, $escaped);
}

/**
 * Render a UL of product links from newline/space/comma separated text
 */
function render_product_links($raw){
  $raw = trim((string)$raw);
  if ($raw === '') return '<em>—</em>';
  $parts = preg_split('/[,\r\n\t ]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
  $links = [];
  foreach ($parts as $p){
    $p = trim($p);
    if ($p === '') continue;
    if (!preg_match('~^(https?://)~i', $p)) {
      if (preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(/.*)?$~i', $p)) {
        $p = 'http://' . $p;
      } else {
        continue;
      }
    }
    $links[] = '<li><a href="'.e($p).'" target="_blank" rel="noopener">'.e($p).'</a></li>';
  }
  if (!$links) return '<em>—</em>';
  return '<ul class="link-list">'.implode('', $links).'</ul>';
}

// ---- Validate query ID ----
$qid = (int)($_GET['id'] ?? 0);
if ($qid <= 0) {
  http_response_code(400);
  exit('Invalid query ID');
}

// ---- Load query only if assigned to this agent ----
try {
  $q = $pdo->prepare("
    SELECT q.*,
           t.name AS team_name,
           au.name AS assigned_name,
           q.desired_product_price,
           q.desired_shipping_price
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
      LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
     WHERE q.id = ? AND q.assigned_admin_user_id = ?
     LIMIT 1
  ");
  $q->execute([$qid, $adminId]);
  $query = $q->fetch(PDO::FETCH_ASSOC);
  if (!$query) { exit('Query not found or not assigned to you.'); }
} catch (Throwable $e) {
  error_log('Load query failed: '.$e->getMessage());
  http_response_code(500);
  exit('Failed to load query.');
}

// Small flash error for the quote form (added)
$quote_error = '';

// ---- Actions ----
try {
  // ============================================================
  // Submit price quote  (supports submitted_ship_price)
  // ============================================================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_price'])) {
    $price      = trim($_POST['price'] ?? '');
    $shipPrice  = trim($_POST['ship_price'] ?? ''); // NEW
    $remark     = trim($_POST['remark'] ?? '');

    // Normalize query_type and decide required fields
    $qtRaw = strtolower(trim((string)($query['query_type'] ?? '')));
    $qt    = ($qtRaw === 'sourcing+shipping') ? 'both' : $qtRaw;

    $needPrice     = in_array($qt, ['sourcing','both'], true);
    $needShipPrice = in_array($qt, ['shipping','both'], true);

    $errs = [];
    if ($needPrice && $price === '')        { $errs[] = 'Price (USD) is required'; }
    if ($needShipPrice && $shipPrice === ''){ $errs[] = 'Shipping Price (USD) is required'; }

    if ($errs) {
      // Show error inline; do not redirect.
      $quote_error = implode('. ', $errs) . '.';
    } else {
      // Compose quote message including whichever values were provided
      $parts = [];
      if ($price !== '')     { $parts[] = "product: $price"; }
      if ($shipPrice !== '') { $parts[] = "shipping: $shipPrice"; }
      $msgText = "Price quote: " . implode(' | ', $parts) . ($remark !== '' ? ". $remark" : '');

      // 1) Save an internal message for audit history (keep medium='quote')
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'quote', ?, NOW())
      ")->execute([$qid, $adminId, $msgText]);

      // 2) Persist to queries table (now saving both fields)
      $pdo->prepare("
        UPDATE queries
           SET status='price_submitted',
               last_assigned_admin_user_id = assigned_admin_user_id,
               submitted_price = ?,
               submitted_ship_price = ?,
               submitted_price_remark = ?,
               submitted_price_submitted_at = NOW(),
               submitted_price_by_admin_user_id = ?
         WHERE id=?
      ")->execute([
        ($price !== '' ? $price : null),
        ($shipPrice !== '' ? $shipPrice : null),
        $remark,
        $adminId,
        $qid
      ]);

      // 3) Small internal note (keep)
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal','note','Price submitted by agent', NOW())
      ")->execute([$qid, $adminId]);

      header("Location: query_team_member.php?id=$qid");
      exit;
    }
  }

  // Post message with optional attachment
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_msg'])) {
    $direction = $_POST['direction'] ?? 'internal';
    $medium    = $_POST['medium'] ?? 'note';
    $body      = trim($_POST['body'] ?? '');

    if (!empty($_FILES['attachment']['name'])) {
      // Check for upload errors first
      if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
          UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
          UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
          UPLOAD_ERR_PARTIAL => 'File partially uploaded',
          UPLOAD_ERR_NO_FILE => 'No file uploaded',
          UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory',
          UPLOAD_ERR_CANT_WRITE => 'Cannot write file',
          UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
        ];
        $error_msg = $upload_errors[$_FILES['attachment']['error']] ?? 'Unknown upload error';
        error_log("File upload error: $error_msg");
        http_response_code(500);
        exit("Upload failed: $error_msg");
      }

      $tmp  = $_FILES['attachment']['tmp_name'];
      $name = basename($_FILES['attachment']['name']);
      $destRel  = '/uploads/' . time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/','',$name);

      // Save into your actual public webroot so https://domain/public/uploads/... works
      $destFull = rtrim($PUBLIC_WEBROOT ?: (__DIR__ . '/../public'), '/\\') . $destRel;

      // Ensure directory exists & writable
      $uploadDir = dirname($destFull);
      if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        error_log("Failed to create upload directory: $uploadDir");
        http_response_code(500);
        exit('Upload directory creation failed.');
      }
      if (!is_writable($uploadDir)) {
        error_log("Upload directory not writable: $uploadDir");
        http_response_code(500);
        exit('Upload directory not writable.');
      }

      // Move + optional DB record
      if (is_uploaded_file($tmp) && move_uploaded_file($tmp, $destFull)) {

        // Record in DB if table exists
        try {
          $tableCheck = $pdo->query("SHOW TABLES LIKE 'query_attachments'")->fetch();
          if (!empty($tableCheck)) {
            $pdo->prepare("
              INSERT INTO query_attachments (query_id, file_path, created_at)
              VALUES (?, ?, NOW())
            ")->execute([$qid, $destRel]); // keep relative path in DB
          }
        } catch (Throwable $e) {
          error_log("Failed to save attachment to database: " . $e->getMessage());
        }

        // Build a full absolute URL with /public prefix
        $publicUrl = $HOST ? ($SCHEME . '://' . $HOST . rtrim($PUBLIC_URL_PREFIX, '/') . $destRel) : (rtrim($PUBLIC_URL_PREFIX, '/') . $destRel);
        $body .= ($body ? "\n" : "") . "Attachment: " . $publicUrl;

      } else {
        error_log("Failed to move uploaded file from $tmp to $destFull");
        http_response_code(500);
        exit('File upload failed.');
      }
    }

    if ($body !== '') {
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
      ")->execute([$qid, $adminId, $direction, $medium, $body]);
    }
    header("Location: query_team_member.php?id=$qid");
    exit;
  }
} catch (Throwable $e) {
  error_log('POST action failed: '.$e->getMessage());
  http_response_code(500);
  exit('Action failed.');
}

// ---- Fetch attachments (tolerant to schema) ----
$attachments = [];
try {
  $cols = $pdo->query("SHOW COLUMNS FROM query_attachments")->fetchAll(PDO::FETCH_COLUMN);
  $nameCol = null; $pathCol = null;
  foreach (['file_name','filename','name','original_name','path','file_path'] as $c) {
    if (in_array($c, $cols, true)) { $nameCol = $c; break; }
  }
  foreach (['file_path','path','url','file'] as $c) {
    if (in_array($c, $cols, true)) { $pathCol = $c; break; }
  }
  $as = $pdo->prepare("SELECT * FROM query_attachments WHERE query_id=? ORDER BY id DESC");
  $as->execute([$qid]);
  foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $attachments[] = [
      'name' => $nameCol ? ($row[$nameCol] ?? ('#' . (int)($row['id'] ?? 0))) : ('#' . (int)($row['id'] ?? 0)),
      'url'  => $pathCol ? ($row[$pathCol] ?? '#') : '#'
    ];
  }
} catch (Throwable $e) {
  // ignore
}

// ---- Fetch messages ----
try {
  $msgStmt = $pdo->prepare("
    SELECT m.*, au.name AS admin_name
      FROM messages m
      LEFT JOIN admin_users au ON au.id = m.sender_admin_id
     WHERE m.query_id=?
     ORDER BY m.id ASC
  ");
  $msgStmt->execute([$qid]);
  $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Fetch messages failed: '.$e->getMessage());
  $messages = [];
}

// ---- UI helpers ----
function badgeClass($value, $map){
  $v = strtolower((string)$value);
  return $map[$v] ?? 'badge--default';
}
$STATUS_CLASS = [
  'assigned'            => 'badge--info',
  'price_rejected'      => 'badge--warning',
  'negotiation_pending' => 'badge--indigo',
  'price_submitted'     => 'badge--primary',
  'price_approved'      => 'badge--success',
  'closed'              => 'badge--muted',
  'resolved'            => 'badge--success',
  'red_flag'            => 'badge--danger'
];
$PRIORITY_CLASS = [
  'low'     => 'badge--muted',
  'default' => 'badge--info',
  'normal'  => 'badge--info',
  'high'    => 'badge--warning',
  'urgent'  => 'badge--danger'
];

function fmt_money($v){
  if ($v === null || $v === '') return '—';
  return '$'.number_format((float)$v, 2);
}
function avail_label($prod, $ship){
  $p = $prod !== null && $prod !== '';
  $s = $ship !== null && $ship !== '';
  if ($p && $s) return 'both';
  if ($p) return 'product only';
  if ($s) return 'shipping only';
  return 'none';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Query #<?= (int)$qid ?> — Team Agent</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f4f6f9;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#64748b;
  --line:#e5e7eb;
  --accent:#0ea5e9;
  --accent-strong:#0284c7;
  --success:#10b981;
  --warning:#f59e0b;
  --danger:#ef4444;
  --indigo:#6366f1;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto; color:var(--text); background:#f4f6f9}
.container{max-width:1100px;margin:24px auto;padding:0 16px}
.header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}
.title{margin:0;font-weight:700;font-size:1.35rem}
.subtle{color:#64748b;font-size:.95rem}
.card{background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
.card + .card{margin-top:16px}
.grid{display:grid;gap:12px}
.grid-2{grid-template-columns:1fr 1fr}
@media (max-width: 800px){ .grid-2{grid-template-columns:1fr} }
.kv{display:grid;grid-template-columns:180px 1fr;gap:8px 12px;align-items:start}
.kv .k{color:#64748b}
.badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:.8rem;border:1px solid #e5e7eb;background:#f8fafc}
.badge--primary{background:#e0f2fe;border-color:#bae6fd;color:#075985}
.badge--info{background:#eef2ff;border-color:#e0e7ff;color:#3730a3}
.badge--success{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
.badge--warning{background:#fffbeb;border-color:#fde68a;color:#92400e}
.badge--danger{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.badge--indigo{background:#eef2ff;border-color:#e0e7ff;color:#4338ca}
.badge--muted{background:#f1f5f9;border-color:#e2e8f0;color:#334155}
.badge--default{background:#f8fafc;border-color:#e5e7eb;color:#374151}

.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{appearance:none;border:1px solid #e5e7eb;background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:600}
.btn:hover{background:#0284c7}
.btn--ghost{background:#fff;color:#0ea5e9;border-color:#bae6fd}
.btn--ghost:hover{background:#f0f9ff}
.btn:disabled{opacity:.6;cursor:not-allowed}

.section-title{margin:0 0 12px 0;font-size:1.05rem}
.divider{height:1px;background:#e5e7eb;margin:12px 0}
.link-list{margin:0;padding-left:18px}
.link-list li{margin:4px 0}

.thread{display:flex;flex-direction:column;gap:10px;max-height:380px;overflow:auto}
.msg{border:1px solid #e5e7eb;border-radius:12px;padding:10px}
.msg small{display:block;color:#64748b;margin-bottom:6px}
.msg__internal{background:#fbfdff}
.msg__outbound{background:#fffdf8}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 800px){ .form-row{grid-template-columns:1fr} }
textarea, input, select{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
label{display:block;font-size:.9rem;margin-bottom:6px;color:#334155}
.form-actions{display:flex;gap:8px;align-items:center;margin-top:10px}
.meta{color:#64748b}

.small-note{font-size:.85rem;color:#64748b}
a{color:#0284c7;text-decoration:none}
a:hover{text-decoration:underline}

/* small inline alert for quote form */
.alert{padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;margin-bottom:10px}

/* Snapshot card */
.snap-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width: 800px){ .snap-grid{grid-template-columns:1fr} }
.snap{border:1px dashed #e5e7eb;border-radius:12px;padding:12px}
.snap h4{margin:0 0 6px 0;font-size:1rem}
.snap .row{display:flex;justify-content:space-between;gap:10px;margin:6px 0}
.snap .label{color:#64748b}
.snap .val{font-weight:700}
</style>
</head>
<body>
  <div class="container">

    <div class="header">
      <div>
        <h1 class="title">Query #<?= (int)$qid ?></h1>
        <div class="subtle">Team: <?= e($query['team_name'] ?: '—') ?> • Assigned to: <?= e($query['assigned_name'] ?: '—') ?></div>
      </div>
      <div class="badges">
        <span class="badge <?= badgeClass($query['status'],$STATUS_CLASS) ?>"><strong>Status:</strong> <?= e($query['status'] ?: '—') ?></span>
        <span class="badge <?= badgeClass(($query['priority']?:'default'),$PRIORITY_CLASS) ?>"><strong>Priority:</strong> <?= e($query['priority'] ?: 'default') ?></span>
        <!-- show query type as a badge -->
        <span class="badge"><strong>Type:</strong> <?= e($query['query_type'] ?: '—') ?></span>
      </div>
    </div>

    <div class="card">
      <h3 class="section-title">Customer & Query Details</h3>
      <div class="grid grid-2">
        <div class="kv">
          <div class="k">Customer</div><div><?= e($query['customer_name'] ?: '—') ?></div>
          <div class="k">Phone</div><div>
            <?php if(!empty($query['phone'])): ?>
              <a href="tel:<?= e($query['phone']) ?>"><?= e($query['phone']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </div>
          <div class="k">Email</div><div>
            <?php if(!empty($query['email'])): ?>
              <a href="mailto:<?= e($query['email']) ?>"><?= e($query['email']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </div>
          <div class="k">Country</div><div><?= e($query['country_id'] ?: '—') ?></div>
          <div class="k">Address</div><div><?= nl2br(e($query['address'] ?: '—')) ?></div>
        </div>

        <div class="kv">
          <div class="k">Product</div><div><?= e($query['product_name'] ?: '—') ?></div>
          <div class="k">Product Links</div><div><?= render_product_links($query['product_links'] ?? '') ?></div>
          <div class="k">Quantity</div><div><?= e($query['quantity'] ?: '—') ?></div>
          <div class="k">Budget (USD)</div><div><?= e($query['budget'] ?: '—') ?></div>
          <div class="k">Query type</div><div><?= e($query['query_type'] ?: '—') ?></div>
          <div class="k">Shipping mode</div><div><?= e($query['shipping_mode'] ?: '—') ?></div>
          <div class="k">Label type</div><div><?= e($query['label_type'] ?: '—') ?></div>
          <div class="k">Carton count</div><div><?= e($query['carton_count'] ?: '—') ?></div>
          <div class="k">CBM</div><div><?= e($query['cbm'] ?: '—') ?></div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="kv">
        <div class="k">Product details</div><div><?= nl2br(e($query['product_details'] ?: '—')) ?></div>
        <div class="k">Notes</div><div><?= nl2br(e($query['notes'] ?: '—')) ?></div>
      </div>
    </div>

    <!-- Price Snapshot -->
    <div class="card">
      <h3 class="section-title">Price Snapshot</h3>
      <div class="snap-grid">
        <div class="snap">
          <h4>Agent submitted</h4>
          <?php
            $hasSubProd = ($query['submitted_price'] !== null && $query['submitted_price'] !== '');
            $hasSubShip = ($query['submitted_ship_price'] !== null && $query['submitted_ship_price'] !== '');
            if ($hasSubProd): ?>
              <div class="row"><div class="label">Product</div><div class="val"><?= fmt_money($query['submitted_price']) ?></div></div>
          <?php endif; ?>
          <?php if ($hasSubShip): ?>
              <div class="row"><div class="label">Shipping</div><div class="val"><?= fmt_money($query['submitted_ship_price']) ?></div></div>
          <?php endif; ?>
          <div class="small-note">Available: <strong><?= e(avail_label($hasSubProd ? $query['submitted_price'] : null, $hasSubShip ? $query['submitted_ship_price'] : null)) ?></strong></div>
          <?php if (!empty($query['submitted_price_submitted_at'])): ?>
            <div class="small-note">Submitted at: <?= e($query['submitted_price_submitted_at']) ?></div>
          <?php endif; ?>
        </div>

        <?php
          $hasDesProd = ($query['desired_product_price'] !== null && $query['desired_product_price'] !== '');
          $hasDesShip = ($query['desired_shipping_price'] !== null && $query['desired_shipping_price'] !== '');
          if ($hasDesProd || $hasDesShip):
        ?>
        <div class="snap">
          <h4>Customer desired</h4>
          <?php if ($hasDesProd): ?>
            <div class="row"><div class="label">Product</div><div class="val"><?= fmt_money($query['desired_product_price']) ?></div></div>
          <?php endif; ?>
          <?php if ($hasDesShip): ?>
            <div class="row"><div class="label">Shipping</div><div class="val"><?= fmt_money($query['desired_shipping_price']) ?></div></div>
          <?php endif; ?>
          <div class="small-note">Available: <strong><?= e(avail_label($hasDesProd ? $query['desired_product_price'] : null, $hasDesShip ? $query['desired_shipping_price'] : null)) ?></strong></div>
        </div>
        <?php endif; ?>
      </div>
      <div class="small-note" style="margin-top:8px">
        Desired values are taken from the query record (not parsed from messages).
      </div>
    </div>

    <div class="card">
      <h3 class="section-title">Attachments</h3>
      <?php if (!$attachments): ?>
        <p class="small-note"><em>No attachments.</em></p>
      <?php else: ?>
        <ul class="link-list">
          <?php foreach ($attachments as $a): ?>
            <li><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener"><?= e($a['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 class="section-title">Message Thread</h3>
      <?php if (!$messages): ?>
        <p class="small-note"><em>No messages yet.</em></p>
      <?php else: ?>
        <div class="thread">
          <?php foreach ($messages as $m): ?>
            <?php
              $isCustomer = !empty($m['sender_clerk_user_id']); // tolerate missing column
              $who = $isCustomer ? 'Customer' : ($m['admin_name'] ?? 'Team');
              $cls = (isset($m['direction']) && $m['direction']==='internal') ? 'msg__internal' : 'msg__outbound';
            ?>
            <div class="msg <?= $cls ?>">
              <small><?= e($m['created_at'] ?? '') ?> — <?= e($who) ?> — <?= e($m['direction'] ?? '') ?>/<?= e($m['medium'] ?? '') ?></small>
              <div><?= autolink($m['body'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (in_array(($query['status'] ?? ''), ['assigned','price_rejected','negotiation_pending'], true)): ?>
    <div class="card">
      <h3 class="section-title">Submit Price Quote</h3>

      <?php if ($quote_error): ?>
        <div class="alert"><?= e($quote_error) ?></div>
      <?php endif; ?>

      <p class="small-note">
        Query type: <strong><?= e($query['query_type'] ?: '-') ?></strong>.
        Requirements — <em>shipping</em>: Shipping Price only; <em>sourcing</em>: Price (USD) only; <em>both</em>: both fields.
      </p>

      <form method="post">
        <div class="form-row">
          <div>
            <label>Price (USD)</label>
            <input name="price" type="number" step="0.01" min="0">
          </div>
          <div>
            <label>Shipping Price (USD)</label> <!-- NEW FIELD -->
            <input name="ship_price" type="number" step="0.01" min="0">
          </div>
        </div>
        <div class="form-row" style="margin-top:12px">
          <div>
            <label>Remarks (optional)</label>
            <input name="remark" type="text" placeholder="Any note with the quote">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit" name="submit_price">Submit Quote</button>
          <span class="small-note">Submits a quote and updates status to <strong>price_submitted</strong>.</span>
        </div>
      </form>
    </div>
    <?php elseif (($query['status'] ?? '') === 'price_submitted'): ?>
      <div class="card"><strong>Price submitted.</strong> Awaiting supervisor review.</div>
    <?php elseif (($query['status'] ?? '') === 'price_approved'): ?>
      <div class="card"><strong>Price approved.</strong> Awaiting customer response.</div>
    <?php endif; ?>

    <div class="card">
      <h3 class="section-title">Send Message</h3>
      <form method="post" enctype="multipart/form-data">
        <div class="form-row">
          <div>
            <label>Direction</label>
            <select name="direction">
              <option value="internal">Internal</option>
              <option value="outbound">Customer</option>
            </select>
          </div>
          <div>
            <label>Medium</label>
            <select name="medium">
              <option value="note">Note</option>
              <option value="message">Message</option>
              <option value="email">Email</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="voice">Voice</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>

        <div style="margin-top:10px">
          <label>Message</label>
          <textarea name="body" rows="3" placeholder="Type your message..." required></textarea>
        </div>

        <div class="form-row" style="margin-top:10px">
          <div>
            <label>Attach file (optional)</label>
            <input type="file" name="attachment">
          </div>
          <div class="meta" style="align-self:end">Max file size: <?= e($max_upload) ?> | Post max: <?= e($max_post) ?></div>
        </div>

        <div class="form-actions">
          <button class="btn" type="submit" name="post_msg">Send</button>
          <button class="btn btn--ghost" type="button" onclick="window.location.href='/app/queries.php'">Back to List</button>
        </div>
      </form>
    </div>

  </div>
</body>
</html>
