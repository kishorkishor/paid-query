<?php
// app/team_supervisor_review.php
require_once __DIR__ . '/auth.php';

/**
 * Team Supervisor Review Page
 * - Shows full query details, attachments, and message thread
 * - Highlights latest submitted price(s) (product + shipping)
 * - Supervisor can approve or reject
 */

// ---- Debug/Error logging ----
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/logs/_php_errors.log');

// Public URL prefix for uploaded files (adjust if your site serves from a different prefix)
$PUBLIC_URL_PREFIX = '/public'; // results in /public/uploads/... links

// Session & permission
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_perm('view_queries');

$pdo     = db();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

// ---- Helpers ----
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

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
      $url = $raw;
    }
    return '<a href="'.e($url).'" target="_blank" rel="noopener">'.e($raw).'</a>';
  }, $escaped);
}

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

// ---- Inputs ----
$qid    = (int)($_GET['id'] ?? 0);
$teamId = (int)($_GET['team_id'] ?? 0);
if ($qid <= 0) { http_response_code(400); exit('Invalid query ID'); }

// ---- Load query ----
try {
  $q = $pdo->prepare("
    SELECT q.*,
           t.name AS team_name,
           au_assigned.name AS assigned_name,
           au_last.name     AS last_assigned_name
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
      LEFT JOIN admin_users au_assigned ON au_assigned.id = q.assigned_admin_user_id
      LEFT JOIN admin_users au_last     ON au_last.id     = q.last_assigned_admin_user_id
     WHERE q.id = ?
     LIMIT 1
  ");
  $q->execute([$qid]);
  $query = $q->fetch(PDO::FETCH_ASSOC);
  if (!$query) { exit('Query not found.'); }
} catch (Throwable $e) {
  error_log('Load query failed: '.$e->getMessage());
  http_response_code(500);
  exit('Failed to load query.');
}

/* ------------------------------------------------------------------
   Normalize query_type for logic
------------------------------------------------------------------- */
$qtRaw = strtolower(trim((string)($query['query_type'] ?? '')));
$qt = $qtRaw;
if (in_array($qtRaw, ['sourcing+shipping','shipping+sourcing'], true)) { $qt = 'both'; }

/* ------------------------------------------------------------------
   Fetch latest submitted prices (prefer queries.*; fallback to message)
------------------------------------------------------------------- */
$priceData = [
  'product_price' => null, // queries.submitted_price
  'ship_price'    => null, // queries.submitted_ship_price
  'remark'        => null,
  'submitted_at'  => null,
  'by_name'       => null,
];

try {
  $cols = $pdo->query("SHOW COLUMNS FROM queries")->fetchAll(PDO::FETCH_COLUMN);
  $hasSubmittedCols = in_array('submitted_price', $cols, true);

  if ($hasSubmittedCols) {
    if ($query['submitted_price'] !== null && $query['submitted_price'] !== '') {
      $priceData['product_price'] = (float)$query['submitted_price'];
    }
    if (in_array('submitted_ship_price', $cols, true) &&
        $query['submitted_ship_price'] !== null && $query['submitted_ship_price'] !== '') {
      $priceData['ship_price'] = (float)$query['submitted_ship_price'];
    }
    $priceData['remark']       = (string)($query['submitted_price_remark'] ?? '');
    $priceData['submitted_at'] = (string)($query['submitted_price_submitted_at'] ?? '');

    if (!empty($query['submitted_price_by_admin_user_id'])) {
      $au = $pdo->prepare("SELECT name FROM admin_users WHERE id=?");
      $au->execute([(int)$query['submitted_price_by_admin_user_id']]);
      $priceData['by_name'] = $au->fetchColumn() ?: null;
    }
  }
} catch (Throwable $e) {
  error_log('Price column check failed: '.$e->getMessage());
}

// Fallback to most recent "quote" message if DB fields empty
if ($priceData['product_price'] === null && $priceData['ship_price'] === null) {
  try {
    $qs = $pdo->prepare("
      SELECT m.*, au.name AS admin_name
        FROM messages m
        LEFT JOIN admin_users au ON au.id = m.sender_admin_id
       WHERE m.query_id=? AND m.medium='quote'
       ORDER BY m.id DESC
       LIMIT 1
    ");
    $qs->execute([$qid]);
    if ($qrow = $qs->fetch(PDO::FETCH_ASSOC)) {
      $body = (string)($qrow['body'] ?? '');

      // Try to parse "product: 100 | shipping: 50"
      if (preg_match('/product:\s*\$?([0-9]+(?:\.[0-9]{1,2})?)/i', $body, $m1)) {
        $priceData['product_price'] = (float)$m1[1];
      }
      if (preg_match('/shipping:\s*\$?([0-9]+(?:\.[0-9]{1,2})?)/i', $body, $m2)) {
        $priceData['ship_price'] = (float)$m2[1];
      }

      // Older plain format: "Price quote: $123.45"
      if ($priceData['product_price'] === null &&
          preg_match('/Price\s*quote:\s*\$?([0-9]+(?:\.[0-9]{1,2})?)/i', $body, $mSingle)) {
        $priceData['product_price'] = (float)$mSingle[1];
      }

      // Remarks (anything after first period)
      if (preg_match('/Price\s*quote:[^\n]*\.\s*(.+)$/is', $body, $r)) {
        $priceData['remark'] = trim($r[1]);
      }
      $priceData['submitted_at'] = $qrow['created_at'] ?? null;
      $priceData['by_name']      = $qrow['admin_name'] ?? null;
    }
  } catch (Throwable $e) {
    error_log('Fetch fallback quote failed: '.$e->getMessage());
  }
}

/* ----------------------------- POST ACTIONS ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action  = $_POST['action'] ?? '';
  $remarks = trim($_POST['remarks'] ?? '');

  $lastAgentId = (int)($query['last_assigned_admin_user_id'] ?? 0);
  $fallbackAssignee = $lastAgentId > 0 ? $lastAgentId : (int)($query['assigned_admin_user_id'] ?? 0);

  try {
    if ($action === 'approve') {
      // Send latest quote to customer
      $quoteBody = null;

      // First, try last stored quote message
      $msg = $pdo->prepare("
        SELECT body FROM messages
         WHERE query_id=? AND medium='quote'
         ORDER BY id DESC LIMIT 1
      ");
      $msg->execute([$qid]);
      $quoteBody = $msg->fetchColumn();

      // If no quote message exists, compose one from priceData
      if (!$quoteBody) {
        $parts = [];
        if ($priceData['product_price'] !== null) { $parts[] = 'product: $'.number_format((float)$priceData['product_price'], 2); }
        if ($priceData['ship_price'] !== null)    { $parts[] = 'shipping: $'.number_format((float)$priceData['ship_price'], 2); }
        if ($parts) {
          $quoteBody = 'Price quote: ' . implode(' | ', $parts) .
                       ($priceData['remark'] ? ('. ' . $priceData['remark']) : '');
        }
      }

      if ($quoteBody) {
        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'outbound','message', ?, NOW())
        ")->execute([$qid, $adminId, $quoteBody]);
      }

      $pdo->prepare("
        UPDATE queries
           SET status='price_approved',
               assigned_admin_user_id = NULLIF(?, 0),
               sla_reply_due_at = NULL,
               updated_at = NOW()
         WHERE id=?
      ")->execute([$fallbackAssignee, $qid]);

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$qid, $adminId, 'Price approved by supervisor and sent to customer' . ($remarks ? " — {$remarks}" : '')]);

      header("Location: team_supervisor_review.php?id=$qid&team_id=$teamId&ok=1");
      exit;

    } elseif ($action === 'reject') {

      $pdo->prepare("
        UPDATE queries
           SET status='price_rejected',
               assigned_admin_user_id = NULLIF(?, 0),
               sla_reply_due_at = DATE_ADD(NOW(), INTERVAL 36 HOUR),
               updated_at = NOW()
         WHERE id=?
      ")->execute([$fallbackAssignee, $qid]);

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$qid, $adminId, 'Price rejected by supervisor' . ($remarks ? ": {$remarks}" : '')]);

      header("Location: team_supervisor_review.php?id=$qid&team_id=$teamId&ok=1");
      exit;
    }
  } catch (Throwable $e) {
    error_log('Supervisor action failed: '.$e->getMessage());
    header("Location: team_supervisor_review.php?id=$qid&team_id=$teamId&err=1");
    exit;
  }
}

// ---- Fetch attachments ----
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
      'url  '=> $pathCol ? ($row[$pathCol] ?? '#') : '#'
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Supervisor Review — Query #<?= (int)$qid ?></title>
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
html,body{margin:0;padding:0;font-family:ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto; color:var(--text); background:var(--bg)}
.container{max-width:1100px;margin:24px auto;padding:0 16px}
.header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}
.title{margin:0;font-weight:700;font-size:1.35rem}
.subtle{color:var(--muted);font-size:.95rem}
.notice{margin:10px 0;padding:10px 12px;border-radius:10px;font-size:.95rem}
.notice.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46}
.notice.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}

.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.card + .card{margin-top:16px}
.grid{display:grid;gap:12px}
.grid-2{grid-template-columns:1fr 1fr}
@media (max-width: 800px){ .grid-2{grid-template-columns:1fr} }
.kv{display:grid;grid-template-columns:180px 1fr;gap:8px 12px;align-items:start}
.kv .k{color:var(--muted)}
.badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:.8rem;border:1px solid var(--line);background:#f8fafc}
.badge--primary{background:#e0f2fe;border-color:#bae6fd;color:#075985}
.badge--info{background:#eef2ff;border-color:#e0e7ff;color:#3730a3}
.badge--success{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
.badge--warning{background:#fffbeb;border-color:#fde68a;color:#92400e}
.badge--danger{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.badge--indigo{background:#eef2ff;border-color:#e0e7ff;color:#4338ca}
.badge--muted{background:#f1f5f9;border-color:#e2e8f0;color:#334155}
.badge--default{background:#f8fafc;border-color:#e5e7eb;color:#374151}

.price-card{border:2px solid #0ea5e9;background:#e0f2fe;color:#075985;border-radius:14px;padding:14px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.price-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
.price-amount{font-weight:800;font-size:1.6rem}
.price-label{font-size:.85rem;color:#0c4a6e}
.price-meta{font-size:.9rem;color:#0c4a6e;text-align:right}

.thread{display:flex;flex-direction:column;gap:10px;max-height:380px;overflow:auto}
.msg{border:1px solid var(--line);border-radius:12px;padding:10px}
.msg small{display:block;color:var(--muted);margin-bottom:6px}
.msg__internal{background:#fbfdff}
.msg__outbound{background:#fffdf8}

.form-row{display:grid;grid-template-columns:1fr;gap:12px}
textarea, input, select{width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;background:#fff}
label{display:block;font-size:.9rem;margin-bottom:6px;color:#334155}
.form-actions{display:flex;gap:8px;align-items:center;margin-top:10px}
.btn{appearance:none;border:1px solid var(--line);background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:600}
.btn:hover{background:var(--accent-strong)}
.btn--reject{background:#ef4444}
.btn--reject:hover{background:#dc2626}
.btn--ghost{background:#fff;color:var(--accent);border-color:#bae6fd}
.btn--ghost:hover{background:#f0f9ff}
.small-note{font-size:.85rem;color:var(--muted)}
.link-list{margin:0;padding-left:18px}
.link-list li{margin:4px 0}
a{color:var(--accent-strong);text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div>
        <h1 class="title">Supervisor Review — Query #<?= (int)$qid ?></h1>
        <div class="subtle">Team: <?= e($query['team_name'] ?: '—') ?> • Assigned: <?= e($query['assigned_name'] ?: '—') ?> • Last assigned: <?= e($query['last_assigned_name'] ?: '—') ?></div>
      </div>
      <div class="badges">
        <span class="badge <?= badgeClass($query['status'],$STATUS_CLASS) ?>"><strong>Status:</strong> <?= e($query['status'] ?: '—') ?></span>
        <span class="badge <?= badgeClass(($query['priority']?:'default'),$PRIORITY_CLASS) ?>"><strong>Priority:</strong> <?= e($query['priority'] ?: 'default') ?></span>
        <!-- NEW: show Query Type -->
        <span class="badge"><strong>Type:</strong> <?= e($query['query_type'] ?: '—') ?></span>
      </div>
    </div>

    <?php if (isset($_GET['ok'])): ?>
      <div class="notice ok">Action completed.</div>
    <?php elseif (isset($_GET['err'])): ?>
      <div class="notice err">Something went wrong. Check server logs.</div>
    <?php endif; ?>

    <?php
      $showProduct = in_array($qt, ['sourcing','both'], true);
      $showShip    = in_array($qt, ['shipping','both'], true);
      $hasAnyPrice = ($showProduct && $priceData['product_price'] !== null) || ($showShip && $priceData['ship_price'] !== null);
    ?>
    <?php if ($hasAnyPrice): ?>
      <div class="card">
        <div class="price-card">
          <div class="price-grid">
            <?php if ($showProduct): ?>
              <div>
                <div class="price-amount">
                  <?= $priceData['product_price'] !== null ? ('$' . number_format((float)$priceData['product_price'], 2)) : '—' ?>
                </div>
                <div class="price-label">Product price</div>
              </div>
            <?php endif; ?>
            <?php if ($showShip): ?>
              <div>
                <div class="price-amount">
                  <?= $priceData['ship_price'] !== null ? ('$' . number_format((float)$priceData['ship_price'], 2)) : '—' ?>
                </div>
                <div class="price-label">Shipping price</div>
              </div>
            <?php endif; ?>
            <?php if (!empty($priceData['remark'])): ?>
              <div>
                <div class="price-label"><strong>Remarks:</strong> <?= e($priceData['remark']) ?></div>
              </div>
            <?php endif; ?>
          </div>
          <div class="price-meta">
            <div>Submitted by: <strong><?= e($priceData['by_name'] ?: 'Agent') ?></strong></div>
            <div><?= e($priceData['submitted_at'] ?: '') ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Customer & Query Details</h3>
      <div class="grid grid-2">
        <div class="kv">
          <div class="k">Customer</div><div><?= e($query['customer_name'] ?: '—') ?></div>
          <div class="k">Phone</div><div><?= e($query['phone'] ?: '—') ?></div>
          <div class="k">Email</div><div><?= e($query['email'] ?: '—') ?></div>
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
      <div class="kv" style="margin-top:12px">
        <div class="k">Product details</div><div><?= nl2br(e($query['product_details'] ?: '—')) ?></div>
        <div class="k">Notes</div><div><?= nl2br(e($query['notes'] ?: '—')) ?></div>
      </div>
    </div>

    <div class="card">
      <h3>Attachments</h3>
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
      <h3>Message Thread</h3>
      <?php if (!$messages): ?>
        <p class="small-note"><em>No messages yet.</em></p>
      <?php else: ?>
        <div class="thread">
          <?php foreach ($messages as $m): ?>
            <?php
              $isCustomer = !empty($m['sender_clerk_user_id']);
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

    <div class="card">
      <h3>Supervisor Decision</h3>
      <form method="post" class="form-row">
        <div>
          <label>Remarks (optional)</label>
          <textarea name="remarks" rows="3" placeholder="Add a note for the agent (visible internally)"></textarea>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit" name="action" value="approve">Approve Price</button>
          <button class="btn btn--reject" type="submit" name="action" value="reject">Reject Price</button>
          <a class="btn btn--ghost" href="team_supervisor.php?team_id=<?= (int)$teamId ?>">Back to Dashboard</a>
        </div>
        <div class="small-note">Approve sends the price to the customer. Reject returns it to the previous agent and restores the SLA.</div>
      </form>
    </div>

  </div>
</body>
</html>
