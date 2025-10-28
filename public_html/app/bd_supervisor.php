<?php
// /app/bd_supervisor.php — Bangladesh Inbound: Supervisor dashboard
// Lists lots ready for review + search + recent received + all lots.
// Supervisors click through to /app/bd_supervisor_review.php?lot=ID to review/mark received.

require_once __DIR__ . '/auth.php';

require_login();
// Must be supervisor
function has_super_perm(): bool {
  if (function_exists('can')) return (bool)can('bd_inbound_supervisor');
  return !empty($_SESSION['admin']['perms']['bd_inbound_supervisor']);
}
if (!has_super_perm()) {
  http_response_code(403);
  echo "Forbidden (supervisor access required)";
  exit;
}

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Column-flexible field detection (works with bd_* or generic names)
$cols = $pdo->query("SHOW COLUMNS FROM shipment_lots")->fetchAll(PDO::FETCH_COLUMN);
$ST  = in_array('bd_status',      $cols, true) ? 'bd_status'      : (in_array('status',        $cols, true) ? 'status'        : 'status');
$RCV = in_array('bd_received_at', $cols, true) ? 'bd_received_at' : (in_array('received_at',   $cols, true) ? 'received_at'   : 'received_at');
$CLR = in_array('custom_cleared_at',$cols,true)? 'custom_cleared_at':(in_array('cleared_at',$cols,true) ? 'cleared_at' : 'cleared_at');
$CODE= in_array('lot_code',       $cols, true) ? 'lot_code'       : 'id';

// --- Inputs
$q = trim($_GET['q'] ?? '');
$limitAll = max(10, min(200, (int)($_GET['limit'] ?? 50)));

// --- Ready for review
$ready = $pdo->query("
  SELECT id, COALESCE($CODE, id) AS lot_code, courier_name, tracking_no, shipped_at,
         $ST AS lot_status, $CLR AS cleared_at
  FROM shipment_lots
  WHERE LOWER($ST) = 'ready_for_review'
  ORDER BY COALESCE(shipped_at, id) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --- Recently received
$recStmt = $pdo->query("
  SELECT id, COALESCE($CODE, id) AS lot_code, courier_name, tracking_no, shipped_at,
         $ST AS lot_status, $RCV AS received_at
  FROM shipment_lots
  WHERE $RCV IS NOT NULL
  ORDER BY $RCV DESC
  LIMIT 30
");
$recent = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Search (opt-in)
$search = [];
if ($q !== '') {
  $like = '%'.$q.'%';
  $st = $pdo->prepare("
    SELECT id, COALESCE($CODE, id) AS lot_code, courier_name, tracking_no, shipped_at,
           $ST AS lot_status, $CLR AS cleared_at, $RCV AS received_at
    FROM shipment_lots
    WHERE COALESCE($CODE, CAST(id AS CHAR)) LIKE ?
       OR COALESCE(tracking_no,'') LIKE ?
       OR COALESCE(courier_name,'') LIKE ?
    ORDER BY COALESCE(shipped_at, id) DESC
    LIMIT 100
  ");
  $st->execute([$like,$like,$like]);
  $search = $st->fetchAll(PDO::FETCH_ASSOC);
}

// --- All lots (compact snapshot)
$all = $pdo->query("
  SELECT id, COALESCE($CODE, id) AS lot_code, courier_name, tracking_no, shipped_at,
         $ST AS lot_status, $RCV AS received_at
  FROM shipment_lots
  ORDER BY COALESCE(shipped_at, id) DESC
  LIMIT {$limitAll}
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>BD Inbound — Supervisor</title>
  <style>
    :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--line:#eee}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
    .container{max-width:1200px;margin:24px auto;padding:18px;background:var(--card);border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
    h2,h3{margin:0 0 12px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
    .btn{display:inline-block;padding:8px 12px;border:1px solid var(--ink);border-radius:8px;text-decoration:none;color:var(--ink);background:#fff}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .muted{color:#6b7280}
    input[type="text"]{padding:8px 10px;border:1px solid #ddd;border-radius:8px;min-width:280px}
    .chip{font-size:12px;border-radius:999px;padding:4px 8px;border:1px solid #ddd;background:#fafafa}
    .chip.ok{border-color:#10b981;color:#065f46;background:#ecfdf5}
    .chip.warn{border-color:#f59e0b;color:#7c2d12;background:#fffbeb}
  </style>
</head>
<body>
<header>
  <div><b>Bangladesh Inbound</b> — Supervisor Dashboard</div>
  <nav class="row">
    <a class="btn" href="/app/">Home</a>
    <a class="btn" href="/app/bd_inbound.php">All Lots</a>
  </nav>
</header>

<div class="container">
  <form class="row" method="get" action="">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search lot code, tracking, courier…">
    <button class="btn">Search</button>
    <span class="muted">Showing last <?= (int)$limitAll ?> in “All Lots”</span>
  </form>

  <?php if ($q !== ''): ?>
    <h3 style="margin-top:16px">Search results</h3>
    <?php if (!$search): ?>
      <p class="muted">No matches.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Lot</th><th>Status</th><th>Cleared</th><th>Received</th><th>Courier</th><th>Tracking</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($search as $r): ?>
            <tr>
              <td><?= e($r['lot_code']) ?></td>
              <td><?= e($r['lot_status']) ?></td>
              <td><?= e($r['cleared_at']) ?></td>
              <td><?= e($r['received_at']) ?></td>
              <td><?= e($r['courier_name']) ?></td>
              <td><?= e($r['tracking_no']) ?></td>
              <td><a class="btn" href="/app/bd_supervisor_review.php?lot=<?= (int)$r['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <hr style="margin:20px 0">
    <?php endif; ?>
  <?php endif; ?>

  <h3>Ready for Review</h3>
  <?php if (!$ready): ?>
    <p class="muted">No lots are currently waiting for supervisor review.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Lot</th><th>Courier</th><th>Tracking</th><th>Shipped</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($ready as $r): ?>
          <tr>
            <td><?= e($r['lot_code']) ?></td>
            <td><?= e($r['courier_name']) ?></td>
            <td><?= e($r['tracking_no']) ?></td>
            <td><?= e($r['shipped_at']) ?></td>
            <td><span class="chip warn"><?= e($r['lot_status']) ?></span></td>
            <td><a class="btn" href="/app/bd_supervisor_review.php?lot=<?= (int)$r['id'] ?>">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <hr style="margin:20px 0">

  <h3>Recently Received</h3>
  <?php if (!$recent): ?>
    <p class="muted">Nothing received yet.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Lot</th><th>Received</th><th>Status</th><th>Courier</th><th>Tracking</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><?= e($r['lot_code']) ?></td>
            <td><?= e($r['received_at']) ?></td>
            <td><span class="chip ok"><?= e($r['lot_status']) ?></span></td>
            <td><?= e($r['courier_name']) ?></td>
            <td><?= e($r['tracking_no']) ?></td>
            <td><a class="btn" href="/app/bd_supervisor_review.php?lot=<?= (int)$r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <hr style="margin:20px 0">

  <h3>All Lots (latest <?= (int)$limitAll ?>)</h3>
  <table>
    <thead>
      <tr><th>Lot</th><th>Status</th><th>Received</th><th>Courier</th><th>Tracking</th><th>Action</th></tr>
    </thead>
    <tbody>
      <?php foreach ($all as $r): ?>
        <tr>
          <td><?= e($r['lot_code']) ?></td>
          <td><?= e($r['lot_status']) ?></td>
          <td><?= e($r['received_at']) ?></td>
          <td><?= e($r['courier_name']) ?></td>
          <td><?= e($r['tracking_no']) ?></td>
          <td><a class="btn" href="/app/bd_supervisor_review.php?lot=<?= (int)$r['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
