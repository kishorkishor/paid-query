<?php
// app/query_team_supervisor.php
// Full country-team query view with accept/reject, assignment,
// price approval/rejection, message thread, attachments (thumbnails), and metrics.

require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_perm('team_supervisor_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo     = db();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function urlify($p){
  $p = (string)$p;
  if ($p === '') return '';
  if (preg_match('#^https?://#i', $p)) return $p;
  if ($p[0] === '/') return $p;
  return '/'.ltrim($p,'/'); // ensure absolute path
}

// -------- Input guards --------
$teamId  = (int)($_GET['team_id'] ?? 0);
$queryId = (int)($_GET['id'] ?? 0);
if ($teamId <= 0 || $queryId <= 0) { http_response_code(400); exit('Invalid request'); }

// -------- Load the query --------
$stmt = $pdo->prepare("
  SELECT q.*, au.name AS assigned_name
    FROM `queries` q
    LEFT JOIN `admin_users` au ON au.id = q.assigned_admin_user_id
   WHERE q.id=? AND q.current_team_id=? LIMIT 1
");
$stmt->execute([$queryId, $teamId]);
$query = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$query) { http_response_code(404); exit('Query not found or not in your team.'); }

// -------- Team agents --------
function getTeamAgents(PDO $pdo, int $teamId): array {
  $st = $pdo->prepare("
    SELECT u.id, u.name
      FROM `admin_users` u
      JOIN `admin_user_roles` ur ON ur.admin_user_id=u.id
      JOIN `roles` r ON r.id=ur.role_id AND r.name='team_agent'
      JOIN `admin_user_teams` ut ON ut.admin_user_id=u.id AND ut.team_id=?
     WHERE u.is_active=1
     ORDER BY u.name
  ");
  $st->execute([$teamId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
$agents = getTeamAgents($pdo, $teamId);

// -------- POST actions --------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Accept forwarded
  if (isset($_POST['accept_forward'])) {
    $pdo->prepare("
      UPDATE `queries`
         SET status='assigned',
             forward_request_team_id=NULL,
             forward_request_priority=NULL,
             forward_request_by=NULL,
             forward_request_at=NULL,
             assigned_admin_user_id=NULL,
             updated_at=NOW()
       WHERE id=? AND current_team_id=? AND status='forwarded'
    ")->execute([$queryId, $teamId]);

    $pdo->prepare("
      INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note','Forward accepted', NOW())
    ")->execute([$queryId, $adminId]);

    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }

  // Reject forward
  if (isset($_POST['reject_forward'])) {
    $remark = trim($_POST['remarks'] ?? '');
    $pdo->prepare("
      UPDATE `queries`
         SET current_team_id=1,
             status='new',
             assigned_admin_user_id = last_assigned_admin_user_id,
             forward_request_team_id=NULL,
             forward_request_priority=NULL,
             forward_request_by=NULL,
             forward_request_at=NULL,
             updated_at=NOW()
       WHERE id=?
    ")->execute([$queryId]);

    $pdo->prepare("
      INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note', ?, NOW())
    ")->execute([$queryId, $adminId, "Forward rejected: {$remark}"]);

    header("Location: /app/team_supervisor.php?team_id=$teamId"); exit;
  }

  // Manual assign
  if (isset($_POST['assign_agent'])) {
    $agentId = (int)($_POST['agent_id'] ?? 0);
    if ($agentId > 0) {
      $pdo->prepare("
        UPDATE `queries` SET
          status='assigned',
          assigned_admin_user_id=?,
          last_assigned_admin_user_id=?,
          sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
          updated_at=NOW()
        WHERE id=?
      ")->execute([$agentId, $agentId, $queryId]);

      $pdo->prepare("
        INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal','note', ?, NOW())
      ")->execute([$queryId, $adminId, "Assigned to user #{$agentId}"]);
    }
    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }

  // Auto assign
  if (isset($_POST['auto_assign'])) {
    $bestId  = null; $bestCnt = PHP_INT_MAX; $bestOld = PHP_INT_MAX;
    foreach ($agents as $agent) {
      $uid = (int)$agent['id'];
      $row = $pdo->query("
        SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest
          FROM `queries`
         WHERE assigned_admin_user_id={$uid}
           AND status IN ('assigned','price_submitted','negotiation_pending')
      ")->fetch(PDO::FETCH_ASSOC);
      $cnt = (int)($row['cnt'] ?? 0);
      $old = !empty($row['oldest']) ? (int)strtotime($row['oldest']) : PHP_INT_MAX;
      if ($cnt < $bestCnt || ($cnt === $bestCnt && $old < $bestOld)) {
        $bestCnt = $cnt; $bestOld = $old; $bestId = $uid;
      }
    }
    if ($bestId) {
      $pdo->prepare("
        UPDATE `queries` SET
          status='assigned',
          assigned_admin_user_id=?,
          last_assigned_admin_user_id=?,
          sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
          updated_at=NOW()
        WHERE id=?
      ")->execute([$bestId, $bestId, $queryId]);

      $pdo->prepare("
        INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal','note', ?, NOW())
      ")->execute([$queryId, $adminId, "Auto-assigned to user #{$bestId}"]);
    }
    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }

  // Approve price quote
  if (isset($_POST['approve_price'])) {
    $quote = $pdo->prepare("
      SELECT body FROM `messages`
       WHERE query_id=? AND medium='quote'
       ORDER BY id DESC LIMIT 1
    ");
    $quote->execute([$queryId]);
    $qBody = $quote->fetchColumn();
    if ($qBody) {
      $pdo->prepare("
        INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'outbound','message', ?, NOW())
      ")->execute([$queryId, $adminId, $qBody]);
    }
    $pdo->prepare("
      UPDATE `queries` SET
        status='price_approved',
        sla_reply_due_at=NULL,
        updated_at=NOW()
      WHERE id=?
    ")->execute([$queryId]);

    $pdo->prepare("
      INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note','Price approved and sent to customer', NOW())
    ")->execute([$queryId, $adminId]);

    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }

  // Reject price quote
  if (isset($_POST['reject_price'])) {
    $remarks = trim($_POST['remarks'] ?? '');
    $prev = (int)($query['last_assigned_admin_user_id'] ?? 0);

    $pdo->prepare("
      UPDATE `queries` SET
        status='price_rejected',
        assigned_admin_user_id=?,
        sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
        updated_at=NOW()
      WHERE id=?
    ")->execute([$prev, $queryId]);

    $pdo->prepare("
      INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note', ?, NOW())
    ")->execute([$queryId, $adminId, "Price rejected: {$remarks}"]);

    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }

  // Post a message
  if (isset($_POST['post_msg'])) {
    $dir  = $_POST['direction'] ?? 'internal';
    $med  = $_POST['medium'] ?? 'note';
    $body = trim($_POST['body'] ?? '');

    $dir  = in_array($dir, ['internal','outbound'], true) ? $dir : 'internal';
    $med  = in_array($med, ['note','message','email','whatsapp','voice','other'], true) ? $med : 'note';

    if ($body !== '') {
      $pdo->prepare("
        INSERT INTO `messages` (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
      ")->execute([$queryId, $adminId, $dir, $med, $body]);
    }

    header("Location: query_team_supervisor.php?id=$queryId&team_id=$teamId"); exit;
  }
}

// -------- Attachments (robust across schemas) --------
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

$attachments = [];
try {
  $attTable = table_exists($pdo,'attachments') ? 'attachments' :
              (table_exists($pdo,'query_attachments') ? 'query_attachments' : null);
  if ($attTable) {
    // resolve columns
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
    $st->execute([$queryId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $path = $pathCol ? (string)$row[$pathCol] : '';
      $name = $nameCol ? (string)$row[$nameCol] : ($path ? basename($path) : ('#'.$row['id']));
      $mime = $mimeCol ? (string)$row[$mimeCol] : '';
      // basic mime inference fallback
      if (!$mime && $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext,['jpg','jpeg','png','gif','webp'])) $mime = 'image/'.$ext;
        elseif ($ext === 'pdf') $mime = 'application/pdf';
      }
      $attachments[] = [
        'name' => $name,
        'url'  => urlify($path),
        'mime' => $mime,
        'size' => $sizeCol ? (int)$row[$sizeCol] : null,
        'time' => $timeCol ? (string)$row[$timeCol] : null,
      ];
    }
  }
} catch (Throwable $e) {
  error_log("[attachments] load error: ".$e->getMessage());
  // Do not surface a scary message to the UI; just fall back to empty.
}

// -------- Messages --------
$messages = [];
try {
  $msgStmt = $pdo->prepare("
    SELECT m.id, m.query_id, m.sender_admin_id,
           m.direction, m.medium, m.body, m.created_at,
           au.name AS admin_name
      FROM `messages` m
      LEFT JOIN `admin_users` au ON au.id = m.sender_admin_id
     WHERE m.query_id=?
     ORDER BY m.id ASC
  ");
  $msgStmt->execute([$queryId]);
  $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log("[messages] SQL error: ".$e->getMessage());
}

// -------- Team metrics --------
$agents = $agents ?: [];
$metrics = [];
foreach ($agents as $a) {
  $uid = (int)$a['id'];
  $stat = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM `queries` WHERE assigned_admin_user_id={$uid} AND status IN ('assigned','price_submitted','negotiation_pending')) AS active_cnt,
      (SELECT COUNT(*) FROM `queries` WHERE assigned_admin_user_id={$uid} AND status IN ('closed','converted')) AS closed_cnt,
      (SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, IFNULL(updated_at, created_at)))
         FROM `queries`
        WHERE assigned_admin_user_id={$uid}
          AND status IN ('closed','converted')) AS avg_time
  ")->fetch(PDO::FETCH_ASSOC);
  $metrics[$uid] = $stat ?: [];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Query #<?= (int)$queryId ?> — Team Supervisor</title>
<style>
  body{font-family:system-ui;margin:0;padding:20px;background:#f7f8fa}
  header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:12px}
  .btn{background:#0f172a;color:#fff;border:0;padding:6px 12px;border-radius:6px;cursor:pointer;margin-right:4px;text-decoration:none;display:inline-block}
  .btn.danger{background:#b91c1c}
  table{border-collapse:collapse;width:100%;font-size:.9rem;margin-top:10px}
  th,td{border:1px solid #e5e7eb;padding:6px}
  .small{color:#6b7280;font-size:.9rem}
  .mono{white-space:pre-wrap;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace}
  /* Attachments */
  .att-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  @media (max-width:900px){ .att-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
  .att-img, .att-file{display:block;border:1px solid #eee;border-radius:10px;background:#fafafa;padding:8px;color:#111827;text-decoration:none}
  .att-img img{width:100%;height:140px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;background:#fff;display:block}
  .att-cap{font-size:.9rem;margin-top:6px;color:#111827;word-break:break-word}
  .att-file{display:flex;align-items:center;gap:8px;min-height:56px}
  .att-icon{font-size:22px}
  .att-size{color:#6b7280;font-size:.85rem;margin-left:4px}
</style>
</head>
<body>
<header>
  <h2>Query #<?= (int)$queryId ?> — Team <?= (int)$teamId ?></h2>
  <a class="btn" href="/app/logout_sourcing.php">Logout</a>
</header>

<div class="card">
  <h3>Summary</h3>
  <p>
    <strong>Customer:</strong> <?= e($query['customer_name'] ?? '-') ?><br>
    <strong>Contact:</strong> <?= e($query['phone'] ?? $query['customer_phone'] ?? '-') ?><br>
    <strong>Type:</strong> <?= e($query['query_type'] ?? '-') ?><br>
    <strong>Status:</strong> <?= e($query['status'] ?? '-') ?><br>
    <strong>Priority:</strong> <?= e(($query['priority'] ?? '') !== '' ? $query['priority'] : 'default') ?><br>
    <strong>Assigned to:</strong> <?= e($query['assigned_name'] ?? '-') ?><br>
  </p>
</div>

<?php if (($query['status'] ?? '') === 'forwarded'): ?>
<div class="card">
  <h3>Forward Request</h3>
  <form method="post" style="display:inline;"><button class="btn" name="accept_forward">Accept</button></form>
  <form method="post" style="display:inline;">
    <input type="text" name="remarks" placeholder="Remarks for rejection" required>
    <button class="btn danger" name="reject_forward">Reject</button>
  </form>
</div>
<?php endif; ?>

<?php if (($query['status'] ?? '') === 'assigned'): ?>
<div class="card">
  <h3>Assign to Team Member</h3>
  <form method="post" style="display:inline-flex;align-items:center;gap:8px">
    <select name="agent_id">
      <?php foreach ($agents as $a): ?>
        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" name="assign_agent">Assign</button>
  </form>
  <form method="post" style="display:inline;"><button class="btn" name="auto_assign">Auto</button></form>
</div>
<?php endif; ?>

<?php if (($query['status'] ?? '') === 'price_submitted'): ?>
<div class="card">
  <h3>Price Submitted</h3>
  <form method="post" style="display:inline;"><button class="btn" name="approve_price">Approve Price</button></form>
  <form method="post" style="display:inline;">
    <input type="text" name="remarks" placeholder="Remarks for price rejection">
    <button class="btn danger" name="reject_price">Reject Price</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Message Thread</h3>
  <?php if (!$messages): ?>
    <em>No messages yet.</em>
  <?php else: foreach ($messages as $m):
    $who = $m['admin_name'] ? $m['admin_name'] : 'Customer/System'; ?>
    <div style="margin-bottom:8px">
      <small class="small"><?= e($m['created_at']) ?> — <?= e($who) ?> — <?= e($m['direction']) ?>/<?= e($m['medium']) ?></small><br>
      <div class="mono"><?= nl2br(e($m['body'] ?? '')) ?></div>
    </div>
  <?php endforeach; endif; ?>
</div>

<div class="card">
  <h3>Send Message</h3>
  <form method="post">
    <select name="direction">
      <option value="internal">Internal</option>
      <option value="outbound">Customer</option>
    </select>
    <select name="medium">
      <option value="note">Note</option>
      <option value="message">Message</option>
      <option value="email">Email</option>
      <option value="whatsapp">WhatsApp</option>
      <option value="voice">Voice</option>
      <option value="other">Other</option>
    </select><br>
    <textarea name="body" rows="3" cols="60" placeholder="Type your message..." required></textarea><br>
    <button class="btn" name="post_msg">Send</button>
  </form>
</div>

<div class="card">
  <h3>Attachments</h3>
  <?php if (!$attachments): ?>
    <p>No attachments.</p>
  <?php else: ?>
    <div class="att-grid">
      <?php foreach ($attachments as $f):
        $href  = $f['url'];
        $name  = $f['name'];
        $mime  = strtolower((string)$f['mime']);
        $isImg = $mime ? (strpos($mime,'image/') === 0) :
                 in_array(strtolower(pathinfo($href, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true);
      ?>
        <?php if ($isImg): ?>
          <a class="att-img" href="<?= e($href) ?>" target="_blank" rel="noopener">
            <img src="<?= e($href) ?>" alt="<?= e($name) ?>">
            <div class="att-cap"><?= e($name) ?><?php if ($f['size']!==null) echo ' • '.number_format((int)$f['size']).' bytes'; ?></div>
          </a>
        <?php else: ?>
          <a class="att-file" href="<?= e($href) ?>" target="_blank" rel="noopener">
            <span class="att-icon">📄</span>
            <span><?= e($name) ?><?php if ($f['size']!==null): ?><span class="att-size"> (<?= number_format((int)$f['size']) ?> bytes)</span><?php endif; ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Team Performance</h3>
  <table>
    <thead><tr><th>Agent</th><th>Active</th><th>Closed</th><th>Avg Res (days)</th></tr></thead>
    <tbody>
      <?php foreach ($agents as $a): $uid=(int)$a['id']; ?>
      <tr>
        <td><?= e($a['name']) ?></td>
        <td><?= (int)($metrics[$uid]['active_cnt'] ?? 0) ?></td>
        <td><?= (int)($metrics[$uid]['closed_cnt'] ?? 0) ?></td>
        <td>
          <?php $avg = $metrics[$uid]['avg_time'] ?? null;
            echo $avg ? number_format(((float)$avg)/86400, 2) : '—'; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
