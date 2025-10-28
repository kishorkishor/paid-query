<?php
// /app/order_team_member.php — Team member actions + full query/attachments view
require_once __DIR__ . '/auth.php';

// Accept either permission (agents typically have team_supervisor_access)
require_login();
if (!(can('view_orders') || can('team_supervisor_access'))) {
  http_response_code(403); echo "Forbidden"; exit;
}

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

/* ---------- Helpers ---------- */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Lookup a team id by name; returns null if not found */
function team_id_by_name(PDO $pdo, string $name): ?int {
  $st = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1");
  $st->execute([$name]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ? (int)$row['id'] : null;
}

/** Add an audit log entry */
function audit(PDO $pdo, string $entityType, int $entityId, ?int $adminId, string $action, array $meta=[]): void {
  if (!empty($meta)) {
    $st = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                         VALUES (?,?,?,?,?, NOW())");
    $st->execute([$entityType, $entityId, $adminId, $action, json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
  } else {
    $st = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                         VALUES (?,?,?,?,NULL, NOW())");
    $st->execute([$entityType, $entityId, $adminId, $action]);
  }
}

/** Safe url for attachment path (handles /uploads → /public/uploads and absolute links) */
function att_url(string $path): string {
  $p = trim($path);
  if ($p === '') return '#';
  if (preg_match('~^https?://~i', $p)) return $p;
  if (strpos($p, '/uploads/') === 0) return '/public'.$p;    // typical deployment
  if ($p[0] !== '/') return '/public/uploads/'.$p;           // fallback relative
  return $p;
}

/** Fetch a single query row by id with useful joined fields when available */
function fetch_query(PDO $pdo, int $queryId): ?array {
  if ($queryId <= 0) return null;
  try {
    $st = $pdo->prepare("
      SELECT q.*,
             c.name AS country_name
        FROM queries q
        LEFT JOIN countries c ON c.id=q.country_id
       WHERE q.id=? LIMIT 1
    ");
    $st->execute([$queryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    error_log('[order_team_member:fetch_query] '.$e->getMessage());
    $st = $pdo->prepare("SELECT * FROM queries WHERE id=? LIMIT 1");
    $st->execute([$queryId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

/** Fetch attachments for a query_id, robust to column naming */
function fetch_query_attachments(PDO $pdo, int $queryId): array {
  if ($queryId <= 0) return [];
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM query_attachments")->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) {
    error_log('[order_team_member:attachments] table missing');
    return [];
  }
  $pathCol = null; foreach (['path','file_path','url','link'] as $c) if (in_array($c, $cols, true)) { $pathCol=$c; break; }
  $nameCol = null; foreach (['original_name','file_name','filename','name','title'] as $c) if (in_array($c, $cols, true)) { $nameCol=$c; break; }
  $mimeCol = null; foreach (['mime','content_type','file_type','type'] as $c) if (in_array($c, $cols, true)) { $mimeCol=$c; break; }
  $sizeCol = null; foreach (['size','file_size','bytes'] as $c) if (in_array($c, $cols, true)) { $sizeCol=$c; break; }
  $timeCol = in_array('created_at', $cols, true) ? 'created_at' : (in_array('uploaded_at',$cols,true) ? 'uploaded_at' : null);

  $sel = ['id','query_id'];
  if ($pathCol) $sel[] = "`$pathCol`";
  if ($nameCol) $sel[] = "`$nameCol`";
  if ($mimeCol) $sel[] = "`$mimeCol`";
  if ($sizeCol) $sel[] = "`$sizeCol`";
  if ($timeCol) $sel[] = "`$timeCol`";
  $sql = "SELECT ".implode(',', $sel)." FROM query_attachments WHERE query_id=? ORDER BY id DESC";
  $st = $pdo->prepare($sql);
  $st->execute([$queryId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) {
    $path = $pathCol ? (string)$r[$pathCol] : '';
    $name = $nameCol ? (string)$r[$nameCol] : ($path ? basename($path) : ('#'.$r['id']));
    $mime = $mimeCol ? (string)$r[$mimeCol] : '';
    $size = $sizeCol ? (string)$r[$sizeCol] : '';
    $time = $timeCol ? (string)$r[$timeCol] : '';
    $out[] = [
      'name' => $name ?: basename($path),
      'url'  => att_url($path),
      'mime' => $mime,
      'size' => $size,
      'time' => $time,
    ];
  }
  return $out;
}

/** Fetch orders assigned to this agent */
function fetch_my_orders(PDO $pdo, int $adminId): array {
  $st = $pdo->prepare("
    SELECT o.*, q.query_code
      FROM orders o
      LEFT JOIN queries q ON q.id = o.query_id
     WHERE o.assigned_admin_user_id = ?
     ORDER BY o.id DESC
  ");
  $st->execute([$adminId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Ensure we only mutate an order this agent currently owns */
function find_my_order(PDO $pdo, int $adminId, int $orderId): ?array {
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=? AND assigned_admin_user_id=? LIMIT 1");
  $st->execute([$orderId, $adminId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  return $o ?: null;
}

/** Post a note message into the thread — includes order->query_id to satisfy NOT NULL */
function add_note(PDO $pdo, array $orderRow, string $text, ?int $adminId): void {
  $queryId = (int)($orderRow['query_id'] ?? 0);
  $orderId = (int)$orderRow['id'];
  $st = $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body, created_at)
                       VALUES (?, ?, ?, 'internal', 'note', ?, NOW())");
  $st->execute([$queryId ?: null, $orderId, $adminId, $text]);
}

/* ---------- POST actions (agent) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  require_perm('update_status'); // keep actions protected
}

$notice = $error = null;

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['order_id'], $_POST['act'])) {
    $oid = (int)$_POST['order_id'];
    $act = trim((string)$_POST['act']);

    $order = find_my_order($pdo, $me, $oid);
    if (!$order) { throw new RuntimeException('Order not found or not assigned to you.'); }

    if ($act === 'order_placed') {
      // Guard: can only mark as order placed when status is 'processing'
      if ((string)$order['status'] !== 'processing') {
        throw new RuntimeException('You can only mark "Order Placed" when status is processing.');
      }
      // NEW WORKFLOW: set status to 'order_placing' and hand over to supervisor for approval
      $pdo->beginTransaction();
      $upd = $pdo->prepare("UPDATE orders
                               SET status='order_placing',
                                   last_assigned_admin_user_id = assigned_admin_user_id,
                                   assigned_admin_user_id      = NULL,
                                   updated_at                  = NOW()
                             WHERE id=?");
      $upd->execute([$oid]);

      audit($pdo, 'order', $oid, $me, 'order_placed', ['status' => 'order_placing', 'next' => 'supervisor_approval']);
      add_note($pdo, $order, 'Marked as ORDER PLACING by agent (sent to Supervisor for approval).', $me);
      $pdo->commit();
      $notice = 'Marked as Order Placing; sent to Supervisor for approval.';
    }
    elseif ($act === 'order_paid') {
      // Guard: team agent cannot mark paid when status is 'processing'
      if ((string)$order['status'] === 'processing') {
        throw new RuntimeException('You cannot mark "Order Paid" while status is processing.');
      }
      // Forward to Chinese Inbound team if present — and set status to ORDER PROCESSING
      $chinaInboundTeamId = team_id_by_name($pdo, 'Chinese Inbound');
      $pdo->beginTransaction();

      if ($chinaInboundTeamId) {
        $upd = $pdo->prepare("UPDATE orders
                                 SET current_team_id = ?,
                                     last_assigned_admin_user_id = assigned_admin_user_id,
                                     assigned_admin_user_id = NULL,
                                     status = 'order processing',
                                     updated_at = NOW()
                               WHERE id = ?");
        $upd->execute([$chinaInboundTeamId, $oid]);
      }
      audit($pdo, 'order', $oid, $me, 'supervisor_forward', ['to' => 'Chinese Inbound', 'status' => 'order processing']);
      add_note($pdo, $order, 'Marked as ORDER PAID and forwarded to Chinese Inbound (status: order processing).', $me);

      $pdo->commit();
      $notice = $chinaInboundTeamId ? 'Forwarded to Chinese Inbound (status set to order processing).' : 'Marked as paid (Chinese Inbound team not found).';
    }
    else {
      throw new RuntimeException('Unknown action.');
    }
  }
} catch (Throwable $ex) {
  error_log("order_team_member.php error: ".$ex->getMessage());
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  $error = $ex->getMessage();
}

/* ---------- View: my orders ---------- */
$orders = fetch_my_orders($pdo, $me);

/* ---------- Render ---------- */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>My Orders — Team Member</title>
  <style>
    :root{
      --ink:#111827;--bg:#f7f7fb;--card:#fff;--muted:#6b7280;--line:#eee;
      --ok:#10b981;--warn:#f59e0b;--err:#ef4444;--btn:#2563eb;
    }
    *{box-sizing:border-box}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
    header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#0f172a;color:#fff}
    header h1{margin:0;font-size:18px}
    .wrap{max-width:1100px;margin:24px auto;padding:0 14px}
    .note{padding:10px 12px;border-radius:10px;margin:12px 0;font-size:14px}
    .ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .err{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px}
    .card{background:var(--card);border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:14px;border:1px solid var(--line)}
    .muted{color:var(--muted)}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .btn{appearance:none;border:1px solid var(--btn);background:#fff;color:#2563eb;padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:600}
    .btn:hover{background:#f3f7ff}
    .btn.ok{border-color:var(--ok);color:#10b981}
    .btn.ok:hover{background:#ecfdf5}
    .btn.warn{border-color:var(--warn);color:#f59e0b}
    .kvs{display:grid;grid-template-columns:140px 1fr;gap:6px;font-size:14px;margin:6px 0}
    code{background:#f6f6f8;padding:2px 6px;border-radius:6px}
    h4{margin:12px 0 8px 0}
    .att-list{list-style:none;margin:8px 0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .att-list a{display:block;border:1px solid #eee;border-radius:10px;padding:8px;background:#fafafa;text-decoration:none;color:#111827}
    .att-list small{display:block;color:#6b7280}
    .disabled{opacity:.6;cursor:not-allowed}
  </style>
</head>
<body>
<header>
  <h1>My Orders — Team Member</h1>
  <div class="muted">Logged in as <strong><?= e($_SESSION['admin']['email'] ?? 'unknown') ?></strong></div>
</header>

<div class="wrap">
  <?php if($notice): ?><div class="note ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if($error):  ?><div class="note err"><?= e($error)  ?></div><?php endif; ?>

  <?php if(!$orders): ?>
    <p class="muted">No orders assigned to you yet.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach($orders as $o):
        $q = fetch_query($pdo, (int)($o['query_id'] ?? 0));
        $atts = fetch_query_attachments($pdo, (int)($o['query_id'] ?? 0));
        $currentStatus = (string)$o['status'];
        // Disable Order Placed button when status is NOT 'processing'
        $disablePlaced = ($currentStatus !== 'processing');
        // Disable Order Paid button when status is 'processing'
        $disablePaid = ($currentStatus === 'processing');
      ?>
        <div class="card">
          <div class="row">
            <div>
              <div><strong>Order:</strong> <?= e($o['code']) ?></div>
              <div class="muted">Query: <?= e($o['query_code'] ?: '-') ?></div>
            </div>
            <div><span class="muted">Status:</span> <code><?= e($o['status']) ?></code></div>
          </div>

          <div class="kvs">
            <div>Customer</div><div><?= e($o['customer_name'] ?: '-') ?></div>
            <div>Email</div><div><?= e($o['email'] ?: '-') ?></div>
            <div>Phone</div><div><?= e($o['phone'] ?: '-') ?></div>
            <div>Type</div><div><?= e($o['order_type'] ?: 'regular') ?></div>
            <div>Product $</div><div><?= is_null($o['product_price']) ? '-' : number_format((float)$o['product_price'],2) ?></div>
            <div>Shipping $</div><div><?= is_null($o['shipping_price']) ? '-' : number_format((float)$o['shipping_price'],2) ?></div>
            <div>Paid $</div><div><?= number_format((float)$o['paid_amount'],2) ?> (<?= e($o['payment_status']) ?>)</div>
          </div>

          <?php if ($q): ?>
            <h4>Query Details</h4>
            <div class="kvs">
              <div>Type</div><div><?= e($q['query_type'] ?? '') ?></div>
              <div>Shipping Mode</div><div><?= e($q['shipping_mode'] ?? '') ?></div>
              <div>Country</div><div><?= e($q['country_name'] ?? ($q['country'] ?? '')) ?></div>
              <div>Product</div><div><?= e($q['product_name'] ?? '') ?></div>
              <div>Details</div><div><?= nl2br(e($q['product_details'] ?? '')) ?></div>
              <div>Links</div><div><?= nl2br(e($q['product_links'] ?? '')) ?></div>
              <div>Quantity</div><div><?= e($q['quantity'] ?? '') ?></div>
              <div>Budget</div><div><?= e($q['budget'] ?? '') ?></div>
              <div>Cartons</div><div><?= e($q['carton_count'] ?? '') ?></div>
              <div>CBM</div><div><?= e($q['cbm'] ?? '') ?></div>
              <div>Label</div><div><?= e($q['label_type'] ?? '') ?></div>
              <div>Address</div><div><?= nl2br(e($q['address'] ?? '')) ?></div>
              <div>Notes</div><div><?= nl2br(e($q['notes'] ?? '')) ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($atts)): ?>
            <h4>Attachments</h4>
            <ul class="att-list">
              <?php foreach($atts as $a): ?>
                <li>
                  <a href="<?= e($a['url']) ?>" target="_blank" rel="noopener">
                    <strong><?= e($a['name']) ?></strong>
                    <small><?= e($a['mime'] ?: '') ?> <?= e($a['size'] ? '• '.$a['size'] : '') ?> <?= e($a['time'] ? '• '.$a['time'] : '') ?></small>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div class="row" style="gap:8px;margin-top:10px">
            <form method="post">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="order_placed">
              <button class="btn <?= $disablePlaced ? 'disabled' : '' ?>" type="submit" <?= $disablePlaced ? 'disabled title="Can only mark as Order Placed when status is processing"' : '' ?>>
                Mark "Order Placed" (to Supervisor)
              </button>
            </form>
            <form method="post">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="order_paid">
              <button class="btn ok <?= $disablePaid ? 'disabled' : '' ?>" type="submit" <?= $disablePaid ? 'disabled title="Cannot mark paid when status is processing"' : '' ?>>
                Mark "Order Paid" (to Chinese Inbound)
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>