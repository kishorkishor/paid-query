<?php
// app/query_supervisor.php
// Supervisor view with Approve/Reject + Regular Sales assignment.
// On approve: keeps status='forwarded' (so the destination team can accept it).
require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // supervisors only

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo     = db();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bail($msg,$code=500){ http_response_code($code); echo $msg; exit; }
function redirect_here(){ header('Location: ' . $_SERVER['REQUEST_URI']); exit; }

// ----- Resolve teams this supervisor can see -----
$teamIds = [];
try {
  $st = $pdo->prepare("SELECT id FROM teams WHERE leader_admin_user_id=?");
  $st->execute([$adminId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $teamIds[] = (int)$r['id'];

  $st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
  $st->execute([$adminId]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $teamIds[] = (int)$r['team_id'];
} catch (Throwable $ex) {
  error_log('[query_supervisor] load teamIds: '.$ex->getMessage());
}
$teamIds = array_values(array_unique(array_filter($teamIds, fn($x)=>$x>0)));
if (!$teamIds) $teamIds = [1]; // fallback: Regular Sales

// ----- Load query (guard against empty IN) -----
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) bail('Bad ID', 400);

try {
  $place = implode(',', array_fill(0, count($teamIds), '?'));
  $sql = "
    SELECT q.*, t.name AS team_name, au.name AS assigned_name,
           frt.name AS forward_team_name, fu.name AS forward_by_name
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
      LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
      LEFT JOIN teams frt ON frt.id = q.forward_request_team_id
      LEFT JOIN admin_users fu ON fu.id = q.forward_request_by
     WHERE q.id=? AND q.current_team_id IN ($place)
     LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$id], $teamIds));
  $q = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
  error_log('[query_supervisor] load query: '.$ex->getMessage());
  $q = null;
}
if (!$q) bail('Query not found (or not under your teams).', 404);

// ----- POST actions -----
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Assign to a *Regular Sales* agent (team_id=1, role_id=2)
    if (isset($_POST['assign_action'])) {
      $uid = (int)($_POST['assign_to'] ?? 0);
      if ($uid > 0) {
        $chk = $pdo->prepare("
          SELECT 1
            FROM admin_users u
            JOIN admin_user_roles r ON r.admin_user_id=u.id AND r.role_id=2
            JOIN admin_user_teams t ON t.admin_user_id=u.id AND t.team_id=1
           WHERE u.id=? AND u.is_active=1
           LIMIT 1
        ");
        $chk->execute([$uid]);
        if ($chk->fetch()) {
          $pdo->prepare("UPDATE queries SET assigned_admin_user_id=?, updated_at=NOW() WHERE id=?")
              ->execute([$uid, $id]);
          $pdo->prepare("
            INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
            VALUES (?, ?, 'internal', 'note', ?, NOW())
          ")->execute([$id, $adminId, "Assigned to user #{$uid}"]);
        }
      }
      redirect_here();
    }

    // Auto-assign inside Regular Sales (team_id=1, role_id=2)
    if (isset($_POST['auto_assign_action'])) {
      $members = $pdo->query("
        SELECT u.id
          FROM admin_users u
          JOIN admin_user_roles r ON r.admin_user_id=u.id AND r.role_id=2
          JOIN admin_user_teams t ON t.admin_user_id=u.id AND t.team_id=1
         WHERE u.is_active=1
      ")->fetchAll(PDO::FETCH_COLUMN);
      $members = array_map('intval', $members);
      if ($members) {
        $ids = implode(',', $members);
        $counts = array_fill_keys($members, 0);
        if ($ids) {
          $res = $pdo->query("
            SELECT assigned_admin_user_id uid, COUNT(*) cnt
              FROM queries
             WHERE assigned_admin_user_id IN ($ids)
               AND status NOT IN ('closed','converted','red_flag','forwarded')
             GROUP BY assigned_admin_user_id
          ")->fetchAll(PDO::FETCH_KEY_PAIR);
          foreach (($res ?: []) as $u=>$c) $counts[(int)$u] = (int)$c;
        }
        asort($counts, SORT_NUMERIC);
        $selected = (int)array_key_first($counts);
        if ($selected) {
          $pdo->prepare("UPDATE queries SET assigned_admin_user_id=?, updated_at=NOW() WHERE id=?")
              ->execute([$selected, $id]);
          $pdo->prepare("
            INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
            VALUES (?, ?, 'internal', 'note', ?, NOW())
          ")->execute([$id, $adminId, "Auto-assigned to user #{$selected}"]);
        }
      }
      redirect_here();
    }

    // Approve forward (→ move to requested team and keep status='forwarded')
    if (isset($_POST['approve_forward_action']) && can('approve_forwarding')) {
      if (!empty($q['forward_request_team_id'])) {
        $targetTeam = (int)$q['forward_request_team_id'];
        $priority   = $q['forward_request_priority'] ?: 'default';

        $pdo->prepare("
          UPDATE queries
             SET current_team_id=?,
                 priority=?,
                 status='forwarded',
                 sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR),
                 assigned_admin_user_id=NULL,
                 forward_request_team_id=NULL,
                 forward_request_priority=NULL,
                 forward_request_by=NULL,
                 forward_request_at=NULL,
                 updated_at=NOW()
           WHERE id=?
        ")->execute([$targetTeam, $priority, $id]);

        $pdo->prepare("
          INSERT INTO query_assignments (query_id, team_id, assigned_by, assigned_at, priority, note)
          VALUES (?, ?, ?, NOW(), ?, 'Forwarded (approved)')
        ")->execute([$id, $targetTeam, $adminId, $priority]);

        $meta = json_encode(['to_team'=>$targetTeam,'priority'=>$priority], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("
          INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('query', ?, ?, 'assigned', ?, NOW())
        ")->execute([$id, $adminId, $meta]);

        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'internal', 'note', ?, NOW())
        ")->execute([$id, $adminId, "Forward approved to team #{$targetTeam} (priority: {$priority}); status set to forwarded"]);
      }
      header('Location: /app/supervisor.php'); exit;
    }

    // Reject forward — set back to 'assigned' and restore previous assignee (if recorded)
    if (isset($_POST['reject_forward_action']) && can('approve_forwarding')) {
      $prev = (int)$q['assigned_admin_user_id'];

      if ($prev > 0) {
        $pdo->prepare("
          UPDATE queries
             SET status='assigned',
                 assigned_admin_user_id=?,
                 forward_request_team_id=NULL,
                 forward_request_priority=NULL,
                 forward_request_by=NULL,
                 forward_request_at=NULL,
                 updated_at=NOW()
           WHERE id=?
        ")->execute([$prev, $id]);
      } else {
        $pdo->prepare("
          UPDATE queries
             SET status='assigned',
                 forward_request_team_id=NULL,
                 forward_request_priority=NULL,
                 forward_request_by=NULL,
                 forward_request_at=NULL,
                 updated_at=NOW()
           WHERE id=?
        ")->execute([$id]);
      }

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$id, $adminId, "Forward request rejected. Re-assigned back to user #{$prev}."]);

      $meta = json_encode(['reason'=>'rejected','back_to'=>$prev], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("
        INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
        VALUES ('query', ?, ?, 'forward_rejected', ?, NOW())
      ")->execute([$id, $adminId, $meta]);

      header('Location: /app/supervisor.php'); exit;
    }
  }
} catch (Throwable $ex) {
  error_log('[query_supervisor] POST: '.$ex->getMessage());
  bail('Unexpected error', 500);
}

// ----- Attachments (support both `attachments` and `query_attachments`) -----
function table_exists(PDO $pdo, $name){
  try{
    $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, $table, $col){
  try{
    $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table,$col]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}

$files = [];
try {
  $attTable = table_exists($pdo,'attachments') ? 'attachments' :
              (table_exists($pdo,'query_attachments') ? 'query_attachments' : null);
  if ($attTable) {
    // Resolve columns (path/url/name/mime/size/created_at)
    $pathCol = null; foreach (['path','file_path','url','link'] as $c) if (col_exists($pdo,$attTable,$c)) { $pathCol=$c; break; }
    $nameCol = null; foreach (['original_name','file_name','filename','name'] as $c) if (col_exists($pdo,$attTable,$c)) { $nameCol=$c; break; }
    $mimeCol = null; foreach (['mime','content_type','file_type'] as $c) if (col_exists($pdo,$attTable,$c)) { $mimeCol=$c; break; }
    $sizeCol = null; foreach (['size','file_size','bytes'] as $c) if (col_exists($pdo,$attTable,$c)) { $sizeCol=$c; break; }
    $timeCol = col_exists($pdo,$attTable,'created_at') ? 'created_at' : null;

    $cols = "id, query_id";
    if ($pathCol) $cols .= ", `$pathCol`";
    if ($nameCol) $cols .= ", `$nameCol`";
    if ($mimeCol) $cols .= ", `$mimeCol`";
    if ($sizeCol) $cols .= ", `$sizeCol`";
    if ($timeCol) $cols .= ", `$timeCol`";

    $st = $pdo->prepare("SELECT $cols FROM {$attTable} WHERE query_id=? ORDER BY id");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $path = $pathCol ? (string)$row[$pathCol] : '';
      $name = $nameCol ? (string)$row[$nameCol] : ($path ? basename($path) : ('#'.$row['id']));
      $mime = $mimeCol ? (string)$row[$mimeCol] : '';
      if (!$mime && $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = in_array($ext,['jpg','jpeg','png','gif','webp']) ? 'image/'.$ext : '';
      }
      $files[] = [
        'id'   => (int)$row['id'],
        'name' => $name,
        'path' => $path,
        'mime' => $mime,
        'size' => $sizeCol ? (int)$row[$sizeCol] : null,
        'time' => $timeCol ? (string)$row[$timeCol] : null,
      ];
    }
  }
} catch (Throwable $ex) {
  error_log('[query_supervisor] attachments: '.$ex->getMessage());
}

// ----- Messages -----
$messages = [];
try {
  $ms = $pdo->prepare("
    SELECT m.id, m.direction, m.medium, m.body, m.created_at,
           m.sender_admin_id, m.sender_clerk_user_id, au.name AS admin_name
      FROM messages m
      LEFT JOIN admin_users au ON au.id = m.sender_admin_id
     WHERE m.query_id=?
     ORDER BY m.id ASC
  ");
  $ms->execute([$id]);
  $messages = $ms->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
  error_log('[query_supervisor] messages: '.$ex->getMessage());
}

// ----- Eligible Regular Sales agents -----
$regUsers = [];
try {
  $regUsers = $pdo->query("
    SELECT u.id, u.name
      FROM admin_users u
      JOIN admin_user_roles r ON r.admin_user_id=u.id AND r.role_id=2
      JOIN admin_user_teams t ON t.admin_user_id=u.id AND t.team_id=1
     WHERE u.is_active=1
     ORDER BY u.name
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
  error_log('[query_supervisor] regUsers: '.$ex->getMessage());
}

$title = 'Supervisor — Query '.$id;
ob_start();
?>
<h2>Query #<?= (int)$q['id'] ?> — <?= e($q['query_code'] ?: '') ?></h2>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
  <div>
    <div class="card">
      <h3>Summary</h3>
      <div><strong>Status:</strong> <?= e($q['status']) ?></div>
      <div><strong>Priority:</strong> <?= e($q['priority'] ?: 'default') ?></div>
      <div><strong>Team:</strong> <?= e($q['team_name'] ?: ('#'.$q['current_team_id'])) ?></div>
      <div><strong>Assigned To:</strong> <?= e($q['assigned_name'] ?: '-') ?></div>
      <div><strong>Created:</strong> <?= e($q['created_at']) ?></div>
      <div><strong>SLA Due:</strong> <?= e($q['sla_due_at'] ?: '—') ?></div>
    </div>

    <div class="card">
      <h3>Customer</h3>
      <div><strong>Name:</strong> <?= e($q['customer_name'] ?: '-') ?></div>
      <div><strong>Phone:</strong> <?= e($q['phone'] ?: '-') ?></div>
      <div><strong>Email:</strong> <?= e($q['email'] ?: '-') ?></div>
      <div><strong>Country ID:</strong> <?= (int)$q['country_id'] ?: '-' ?></div>
      <div><strong>Address:</strong> <?= e($q['address'] ?: '-') ?></div>
    </div>

    <div class="card">
      <h3>Query</h3>
      <div><strong>Type:</strong> <?= e($q['query_type'] ?: '-') ?></div>
      <div><strong>Shipping Mode:</strong> <?= e($q['shipping_mode'] ?: '-') ?></div>
      <div><strong>Product:</strong> <?= e($q['product_name'] ?: '-') ?></div>
      <div><strong>Details:</strong> <div class="mono"><?= nl2br(e($q['product_details'] ?: '-')) ?></div></div>
      <div class="grid four mt8">
        <div><strong>Quantity:</strong> <?= e($q['quantity'] ?: '-') ?></div>
        <div><strong>Budget:</strong> <?= e($q['budget'] ?: '-') ?></div>
        <div><strong>CBM:</strong> <?= e($q['cbm'] ?: '-') ?></div>
        <div><strong>Cartons:</strong> <?= e($q['carton_count'] ?: '-') ?></div>
      </div>
      <div class="mt8"><strong>Label:</strong> <?= e($q['label_type'] ?: '-') ?></div>
      <div class="mt8"><strong>Links:</strong> <?= e($q['product_links'] ?: '-') ?></div>
      <div class="mt8"><strong>Notes:</strong> <div class="mono"><?= nl2br(e($q['notes'] ?: '-')) ?></div></div>
    </div>

    <div class="card">
      <h3>Attachments</h3>
      <?php if (!$files): ?>
        <em>No attachments.</em>
      <?php else: ?>
        <div class="att-grid">
          <?php foreach ($files as $f):
            $href = $f['path'] ?: '#';
            $isImg = false;
            if (!empty($f['mime'])) {
              $isImg = (stripos($f['mime'],'image/') === 0);
            } else {
              $ext = strtolower(pathinfo($href, PATHINFO_EXTENSION));
              $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp']);
            }
          ?>
            <?php if ($isImg): ?>
              <a class="att-img" href="<?= e($href) ?>" target="_blank" rel="noopener">
                <img src="<?= e($href) ?>" alt="<?= e($f['name']) ?>">
                <div class="att-cap"><?= e($f['name']) ?><?php if ($f['size']!==null) echo ' • '.number_format((int)$f['size']).' bytes'; ?></div>
              </a>
            <?php else: ?>
              <a class="att-file" href="<?= e($href) ?>" target="_blank" rel="noopener">
                <span class="att-icon">📄</span>
                <span class="att-label">
                  <?= e($f['name']) ?>
                  <?php if ($f['size']!==null): ?><span class="att-size">(<?= number_format((int)$f['size']) ?> bytes)</span><?php endif; ?>
                </span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($q['forward_request_team_id']) && can('approve_forwarding')): ?>
      <div class="card" style="border-color:#ffe7a8;background:#fff9e6">
        <h3>Forward request pending</h3>
        <div><strong>Requested by:</strong> <?= e($q['forward_by_name'] ?: ('#'.$q['forward_request_by'])) ?></div>
        <div><strong>Requested at:</strong> <?= e($q['forward_request_at'] ?: '-') ?></div>
        <div><strong>Target team:</strong> <?= e($q['forward_team_name'] ?: ('#'.$q['forward_request_team_id'])) ?></div>
        <div><strong>Priority:</strong> <?= e($q['forward_request_priority'] ?: 'default') ?></div>
        <div class="mt8" style="display:flex;gap:8px">
          <form method="post"><button class="btn" name="approve_forward_action" value="1">Approve</button></form>
          <form method="post"><button class="btn danger" name="reject_forward_action" value="1">Reject</button></form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card" id="thread">
      <h3>Message Thread</h3>
      <?php if (!$messages): ?>
        <em>No messages yet.</em>
      <?php else: foreach ($messages as $m):
        $who = $m['sender_clerk_user_id'] ? 'Customer' : ($m['admin_name'] ?: 'Team'); ?>
        <div class="msg">
          <div class="meta"><?= e($m['created_at']) ?> — <?= e($who) ?> — <?= e($m['direction']) ?>/<?= e($m['medium']) ?></div>
          <div class="body mono"><?= nl2br(e($m['body'] ?? '')) ?></div>
        </div>
      <?php endforeach; endif; ?>

      <div class="composer mt8">
        <div class="grid two">
          <div>
            <label>Kind</label>
            <select id="msgKind">
              <option value="internal">Internal note (hidden)</option>
              <option value="outbound">Message to customer</option>
              <option value="whatsapp">Contacted via WhatsApp</option>
              <option value="email">Contacted via Email</option>
              <option value="voice">Contacted via Voice Call</option>
              <option value="other">Contacted via Other</option>
            </select>
          </div>
          <div>
            <label>&nbsp;</label>
            <button id="btnAddMsg" class="btn" type="button">Add</button>
          </div>
        </div>
        <label class="mt8">Text</label>
        <textarea id="msgBody" rows="2" placeholder="Write a note or message..."></textarea>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>Assign to Regular Sales</h3>
      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <select name="assign_to">
          <option value="">— Select Regular agent (team=1, role=2) —</option>
          <?php foreach ($regUsers as $ru): ?>
            <option value="<?= (int)$ru['id'] ?>" <?= ((int)$q['assigned_admin_user_id']===(int)$ru['id'])?'selected':'' ?>>
              <?= e($ru['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn" name="assign_action" value="1" type="submit">Assign</button>
        <button class="btn muted" name="auto_assign_action" value="1" type="submit">Auto</button>
      </form>
      <p class="hint">Only active members of <em>Regular Sales</em> (team_id=1, role_id=2) are eligible.</p>
    </div>

    <div class="card">
      <a class="btn secondary" href="/app/supervisor.php">← Back to dashboard</a>
    </div>
  </div>
</div>

<style>
  .card{background:#fff;border:1px solid #eee;border-radius:12px;padding:14px;margin-bottom:14px}
  .grid{display:grid;gap:10px}
  .grid.two{grid-template-columns:1fr 1fr}
  .grid.four{grid-template-columns:repeat(4, 1fr)}
  .mono{white-space:pre-wrap;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
  .btn{background:#111827;color:#fff;border:0;border-radius:10px;padding:.55rem .9rem;cursor:pointer;text-decoration:none;display:inline-block}
  .btn.secondary{background:#374151}
  .btn.muted{background:#f3f4f6;color:#111827}
  .btn.danger{background:#b91c1c}
  .msg{margin-bottom:10px}
  .msg .meta{color:#6b7280;font-size:.9em;margin-bottom:2px}
  .hint{color:#6b7280;font-size:.9em;margin-top:8px}
  @media (max-width:900px){ .grid.two,.grid.four{grid-template-columns:1fr} }

  /* Attachments */
  .att-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:10px;
  }
  @media (max-width:900px){ .att-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
  .att-img, .att-file{
    display:block;
    border:1px solid #eee;
    border-radius:10px;
    background:#fafafa;
    padding:8px;
    text-decoration:none;
    color:#111827;
  }
  .att-img img{
    width:100%;
    height:140px;
    object-fit:cover;
    border-radius:8px;
    display:block;
    border:1px solid #e5e7eb;
    background:#fff;
  }
  .att-cap{
    font-size:.9rem;
    margin-top:6px;
    color:#111827;
    word-break:break-word;
  }
  .att-file{
    display:flex;
    align-items:center;
    gap:8px;
    min-height:56px;
  }
  .att-icon{font-size:22px}
  .att-size{color:#6b7280;font-size:.85rem;margin-left:4px}
</style>

<script>
  const QID = <?= (int)$id ?>;
  function kindToDirectionMedium(kind){
    if (kind === 'internal') return {direction:'internal', medium:'note'};
    if (kind === 'outbound') return {direction:'outbound', medium:'message'};
    if (['whatsapp','email','voice','other'].includes(kind)) return {direction:'outbound', medium:kind};
    return {direction:'internal', medium:'note'};
  }
  document.getElementById('btnAddMsg').addEventListener('click', async () => {
    const kind = document.getElementById('msgKind').value;
    const body = document.getElementById('msgBody').value.trim();
    if (!body) { alert('Message cannot be empty'); return; }
    const map = kindToDirectionMedium(kind);
    const fd  = new FormData();
    fd.append('id', QID);
    fd.append('direction', map.direction);
    fd.append('medium', map.medium);
    fd.append('body', body);
    try {
      const res = await fetch('/api/add_admin_message.php', { method:'POST', body:fd, credentials:'include' });
      const data = await res.json();
      if (data.ok) location.reload(); else alert(data.error || 'Failed');
    } catch(e){ alert('Network error'); }
  });
</script>
<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
