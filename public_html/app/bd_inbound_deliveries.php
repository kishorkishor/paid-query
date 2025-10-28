<?php
// /app/bd_inbound_deliveries.php — Console for BD Inbound team (supervisor + members)

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/lib.php';

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

// ---- Permissions ----
// View permission is required to access. Update permission is required to change status.
$CAN_VIEW   = has_perm('bd_inbound_view') || has_perm('assign_team_member');   // fallback so you don't get locked out
$CAN_UPDATE = has_perm('bd_inbound_update') || has_perm('assign_team_member');

if (!$CAN_VIEW) {
  http_response_code(403);
  echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui;margin:40px;color:#111}</style><h2>Forbidden</h2><p>You do not have permission to view BD Inbound console.</p>';
  exit;
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/* ---------- Helpers ---------- */

function update_cartons_delivery_status(PDO $pdo, array $cartonIds, string $deliveryStatus): void {
  if (!$cartonIds) return;
  $in = implode(',', array_map('intval',$cartonIds));
  $allowed = ['queued','out_for_delivery','delivered','pending'];
  if (!in_array($deliveryStatus, $allowed, true)) return;
  $pdo->exec("UPDATE inbound_cartons SET delivery_status='{$deliveryStatus}' WHERE id IN ($in)");
}

function fetch_delivery_carton_ids(PDO $pdo, int $deliveryId): array {
  $q = $pdo->prepare("SELECT carton_id FROM delivery_items WHERE delivery_id=?");
  $q->execute([$deliveryId]);
  return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- POST actions (status transitions) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && $CAN_UPDATE) {
  $action = $_POST['action'] ?? '';
  if ($action === 'set_status') {
    $deliveryId = (int)($_POST['delivery_id'] ?? 0);
    $newStatus  = $_POST['new_status'] ?? '';
    $note       = trim((string)($_POST['note'] ?? ''));

    $allowed = ['queued','preparing','out_for_delivery','delivered','canceled'];
    if ($deliveryId <=0 || !in_array($newStatus,$allowed,true)) { header('Location: '.$_SERVER['REQUEST_URI']); exit; }

    // fetch
    $st = $pdo->prepare("SELECT id, order_id, status FROM deliveries WHERE id=?");
    $st->execute([$deliveryId]);
    $D = $st->fetch(PDO::FETCH_ASSOC);
    if ($D) {
      $pdo->beginTransaction();
      try{
        // update header
        $upd = $pdo->prepare("UPDATE deliveries SET status=?, notes=CONCAT(COALESCE(notes,''), CASE WHEN ?<>'' THEN CONCAT('\n',NOW(),' - ',?) ELSE '' END) WHERE id=?");
        $upd->execute([$newStatus, $note, $note, $deliveryId]);

        // map to carton delivery_status
        $cartonIds = fetch_delivery_carton_ids($pdo, $deliveryId);
        if ($cartonIds) {
          if ($newStatus === 'queued' || $newStatus === 'preparing') {
            update_cartons_delivery_status($pdo, $cartonIds, 'queued');
          } elseif ($newStatus === 'out_for_delivery') {
            update_cartons_delivery_status($pdo, $cartonIds, 'out_for_delivery');
          } elseif ($newStatus === 'delivered') {
            update_cartons_delivery_status($pdo, $cartonIds, 'delivered');
          } elseif ($newStatus === 'canceled') {
            // Put cartons back to pending so they can be re-queued on a new request
            update_cartons_delivery_status($pdo, $cartonIds, 'pending');
          }
        }

        // optional audit
        $meta = json_encode(['delivery_id'=>$deliveryId,'new_status'=>$newStatus,'cartons'=>$cartonIds], JSON_UNESCAPED_SLASHES);
        @ $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                         VALUES ('delivery', ?, ?, 'status_change', ?, NOW())")
              ->execute([$deliveryId, (int)($_SESSION['admin']['id'] ?? 0), $meta]);

        $pdo->commit();
      } catch(Throwable $e) {
        $pdo->rollBack();
        error_log('[bd_inbound:set_status] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }
    }
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

/* ---------- Filters ---------- */
$statuses = ['queued','preparing','out_for_delivery','delivered','canceled'];
$S = [];
$S['status'] = ($_GET['status'] ?? 'queued');
if (!in_array($S['status'],$statuses,true)) $S['status']='queued';

$S['q'] = trim((string)($_GET['q'] ?? '')); // search in request_code, order code, carton id
$S['from'] = trim((string)($_GET['from'] ?? ''));
$S['to']   = trim((string)($_GET['to']   ?? ''));

$params = [];
$where = ["d.status = ?"]; $params[] = $S['status'];

if ($S['from'] !== '') { $where[] = "d.created_at >= ?"; $params[] = $S['from'].' 00:00:00'; }
if ($S['to']   !== '') { $where[] = "d.created_at <= ?"; $params[] = $S['to'].' 23:59:59'; }

if ($S['q'] !== '') {
  $like = '%'.$S['q'].'%';
  $where[] = "(d.request_code LIKE ? OR o.code LIKE ? OR EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id=d.id AND di.carton_id LIKE ?))";
  $params[] = $like; $params[]=$like; $params[]=$like;
}

$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* ---------- Fetch list ---------- */
$sql = "
  SELECT d.id, d.order_id, d.request_code, d.created_at, d.team, d.status, d.notes,
         o.code AS order_code,
         (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id=d.id) AS carton_count
    FROM deliveries d
    JOIN orders o ON o.id=d.order_id
    $whereSql
ORDER BY d.id DESC
LIMIT 400
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- If opening one delivery ---------- */
$openId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$open = null; $openCartons = [];
if ($openId > 0) {
  $s1 = $pdo->prepare("SELECT d.*, o.code AS order_code FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE d.id=? LIMIT 1");
  $s1->execute([$openId]);
  $open = $s1->fetch(PDO::FETCH_ASSOC);

  if ($open) {
    $s2 = $pdo->prepare("
      SELECT c.id, c.weight_kg, c.volume_cbm, c.bd_total_price, c.bd_payment_status, c.delivery_status
        FROM delivery_items di
        JOIN inbound_cartons c ON c.id=di.carton_id
       WHERE di.delivery_id=?
       ORDER BY c.id ASC
    ");
    $s2->execute([$openId]);
    $openCartons = $s2->fetchAll(PDO::FETCH_ASSOC);
  }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BD Inbound — Deliveries</title>
<style>
  :root{
    --ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --ok:#10b981; --no:#ef4444; --chip:#eef2ff; --chipb:#dde3ff; --warn:#f59e0b;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
  header a{color:#fff;text-decoration:none;opacity:.9}
  header a:hover{opacity:1}
  .wrap{max-width:1240px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
  .toolbar{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap}
  .frow{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
  label{font-size:.9rem;color:var(--muted);display:block;margin-bottom:4px}
  input,select,textarea{border:1px solid var(--line);border-radius:10px;padding:.5rem .6rem}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:12px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top}
  tr:hover td{background:#fafafa}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:.88rem}
  .b-queued{background:#fff7ed;border-color:#fed7aa;color:#92400e}
  .b-preparing{background:#fffbeb;border-color:#fde68a;color:#92400e}
  .b-ofd{background:#eef2ff;border-color:#c7d2fe;color:#1e40af}
  .b-done{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
  .b-cancel{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .btn{border:0;border-radius:8px;padding:.55rem .8rem;cursor:pointer;color:#fff;font-weight:600}
  .btn.ok{background:var(--ok)} .btn.no{background:var(--no)} .btn.warn{background:var(--warn)}
  .btn.gray{background:#334155}
  .actions{display:flex;gap:6px;flex-wrap:wrap}
  .mono{font-family:ui-monospace,Menlo,Consolas,monospace}
  .muted{color:var(--muted)}
</style>
</head>
<body>
<header>
  <div><strong>BD Inbound • Deliveries</strong></div>
  <div><a href="/app/">Back to Admin</a></div>
</header>

<div class="wrap">
  <div class="card">
    <div class="toolbar">
      <form method="get" class="frow">
        <div>
          <label>Status</label>
          <select name="status">
            <?php foreach ($statuses as $st): ?>
              <option value="<?= e($st) ?>" <?= $S['status']===$st?'selected':'' ?>>
                <?= e(ucwords(str_replace('_',' ',$st))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>From</label>
          <input type="date" name="from" value="<?= e($S['from']) ?>">
        </div>
        <div>
          <label>To</label>
          <input type="date" name="to" value="<?= e($S['to']) ?>">
        </div>
        <div>
          <label>Search</label>
          <input type="search" name="q" placeholder="Request code / Order code / Carton id" value="<?= e($S['q']) ?>">
        </div>
        <div>
          <button class="btn gray" type="submit">Apply</button>
        </div>
      </form>
      <div class="muted">Showing <?= count($rows) ?> deliveries</div>
    </div>

    <div style="overflow:auto;border-radius:10px">
      <table>
        <thead>
          <tr>
            <th style="min-width:120px">Request</th>
            <th style="min-width:120px">Order</th>
            <th>Created</th>
            <th>Team</th>
            <th>Cartons</th>
            <th>Status</th>
            <th style="min-width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div class="mono"><a href="?status=<?= e($S['status']) ?>&id=<?= (int)$r['id'] ?>"><?= e($r['request_code']) ?></a></div>
              </td>
              <td><div class="mono"><?= e($r['order_code'] ?: '#'.$r['order_id']) ?></div></td>
              <td class="muted"><?= e($r['created_at']) ?></td>
              <td>BD Inbound</td>
              <td><?= (int)$r['carton_count'] ?></td>
              <td>
                <?php
                  $st = $r['status'];
                  $cls = $st==='queued' ? 'b-queued' : ($st==='preparing' ? 'b-preparing' : ($st==='out_for_delivery' ? 'b-ofd' : ($st==='delivered' ? 'b-done' : 'b-cancel')));
                ?>
                <span class="badge <?= $cls ?>"><?= e(str_replace('_',' ',$st)) ?></span>
              </td>
              <td>
                <?php if ($CAN_UPDATE): ?>
                  <div class="actions">
                    <form method="post">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="delivery_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="new_status" value="queued">
                      <button class="btn gray" type="submit" <?= $r['status']==='queued'?'disabled':'' ?>>Queued</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="delivery_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="new_status" value="preparing">
                      <button class="btn warn" type="submit" <?= $r['status']==='preparing'?'disabled':'' ?>>Preparing</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="delivery_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="new_status" value="out_for_delivery">
                      <button class="btn" style="background:#2563eb" type="submit" <?= $r['status']==='out_for_delivery'?'disabled':'' ?>>Out for Delivery</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="delivery_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="new_status" value="delivered">
                      <button class="btn ok" type="submit" <?= $r['status']==='delivered'?'disabled':'' ?>>Delivered</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Cancel this delivery? Cartons will become pending again.');">
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="delivery_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="new_status" value="canceled">
                      <button class="btn no" type="submit" <?= $r['status']==='canceled'?'disabled':'' ?>>Cancel</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($open): ?>
    <div class="card" style="margin-top:14px">
      <h2 style="margin:0 0 6px">Delivery <?= e($open['request_code']) ?></h2>
      <div class="muted">Order <strong><?= e($open['order_code'] ?: '#'.$open['order_id']) ?></strong> • Created <?= e($open['created_at']) ?></div>
      <div style="margin-top:10px">
        <?php
          $st = $open['status'];
          $cls = $st==='queued' ? 'b-queued' : ($st==='preparing' ? 'b-preparing' : ($st==='out_for_delivery' ? 'b-ofd' : ($st==='delivered' ? 'b-done' : 'b-cancel')));
        ?>
        <span class="badge <?= $cls ?>"><?= e(str_replace('_',' ',$st)) ?></span>
      </div>

      <?php if ($CAN_UPDATE): ?>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="delivery_id" value="<?= (int)$open['id'] ?>">
          <label>Add note (optional)</label>
          <textarea name="note" rows="2" style="width:100%;margin:6px 0"></textarea>
          <div class="actions">
            <button class="btn gray"  name="new_status" value="queued"          type="submit">Queued</button>
            <button class="btn warn"  name="new_status" value="preparing"       type="submit">Preparing</button>
            <button class="btn" style="background:#2563eb" name="new_status" value="out_for_delivery" type="submit">Out for Delivery</button>
            <button class="btn ok"    name="new_status" value="delivered"       type="submit">Delivered</button>
            <button class="btn no"    name="new_status" value="canceled"        type="submit" onclick="return confirm('Cancel this delivery?')">Cancel</button>
          </div>
        </form>
      <?php endif; ?>

      <h3 style="margin-top:16px">Cartons</h3>
      <?php if (!$openCartons): ?>
        <div class="muted">No cartons linked.</div>
      <?php else: ?>
        <div style="overflow:auto;border-radius:10px">
          <table>
            <thead><tr><th>Carton</th><th>Weight</th><th>CBM</th><th>Price</th><th>Paid</th><th>Delivery</th></tr></thead>
            <tbody>
              <?php foreach ($openCartons as $c): ?>
                <tr>
                  <td class="mono">#<?= (int)$c['id'] ?></td>
                  <td><?= e($c['weight_kg'] ?? '—') ?></td>
                  <td><?= e($c['volume_cbm'] ?? '—') ?></td>
                  <td class="mono">$<?= e(number_format((float)$c['bd_total_price'],2)) ?></td>
                  <td><?= e($c['bd_payment_status'] ?? '—') ?></td>
                  <td><?= e($c['delivery_status'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
