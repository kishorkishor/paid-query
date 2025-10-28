<?php
// app/forward_review.php — Supervisor approves/rejects a pending forward request.

require_once __DIR__ . '/auth.php';
require_perm('approve_forwarding'); // supervisors only

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /app/supervisor.php'); exit; }

// Determine teams I supervise (leader + membership)
$teamIds = [];
$st = $pdo->prepare("SELECT id FROM teams WHERE leader_admin_user_id=?");
$st->execute([$me]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $teamIds[] = (int)$r['id'];

$st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
$st->execute([$me]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $teamIds[] = (int)$r['team_id'];

$teamIds = array_values(array_unique($teamIds));
if (!$teamIds) $teamIds = [1]; // fallback regular

$inTeams = implode(',', array_map('intval', $teamIds));

// Load the query (must be in my teams)
$qs = $pdo->prepare("
  SELECT q.*, t.name AS team_name, frt.name AS target_team_name,
         au.name AS assigned_name
    FROM queries q
    LEFT JOIN teams t   ON t.id   = q.current_team_id
    LEFT JOIN teams frt ON frt.id = q.forward_request_team_id
    LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
   WHERE q.id=? AND q.current_team_id IN ($inTeams)
   LIMIT 1
");
$qs->execute([$id]);
$q = $qs->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); echo 'Query not found or not in your teams.'; exit; }

if (empty($q['forward_request_team_id'])) {
  http_response_code(400); echo 'No pending forward request for this query.'; exit;
}

$info = $err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (isset($_POST['approve'])) {
      $targetTeam = (int)$q['forward_request_team_id'];
      $priority   = $q['forward_request_priority'] ?: 'default';

      // move the ticket
      $pdo->prepare("
        UPDATE queries
           SET current_team_id=?,
               priority=?,
               status='assigned',
               sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR),
               assigned_admin_user_id=NULL,
               forward_request_team_id=NULL,
               forward_request_priority=NULL,
               forward_request_by=NULL,
               forward_request_at=NULL,
               updated_at=NOW()
         WHERE id=?
      ")->execute([$targetTeam, $priority, $id]);

      // record assignment
      $pdo->prepare("
        INSERT INTO query_assignments (query_id, team_id, assigned_by, assigned_at, priority, note)
        VALUES (?, ?, ?, NOW(), ?, 'Forwarded (approved)')
      ")->execute([$id, $targetTeam, $me, $priority]);

      // audit + message
      $meta = json_encode(['to_team'=>$targetTeam,'priority'=>$priority], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("
        INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
        VALUES ('query', ?, ?, 'assigned', ?, NOW())
      ")->execute([$id, $me, $meta]);

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$id, $me, "Forward approved to team #{$targetTeam} (priority: {$priority})"]);

      header('Location: /app/supervisor.php'); exit;
    }

    if (isset($_POST['reject'])) {
      // Clear request and keep previous assignee (no change), but add a note
      $pdo->prepare("
        UPDATE queries
           SET forward_request_team_id=NULL,
               forward_request_priority=NULL,
               forward_request_by=NULL,
               forward_request_at=NULL,
               updated_at=NOW()
         WHERE id=?
      ")->execute([$id]);

      $prev = (int)$q['assigned_admin_user_id'];
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$id, $me, "Forward request rejected. Re-assigned back to user #{$prev}."]);

      $meta = json_encode(['reason'=>'rejected','back_to'=>$prev], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("
        INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
        VALUES ('query', ?, ?, 'forward_rejected', ?, NOW())
      ")->execute([$id, $me, $meta]);

      header('Location: /app/supervisor.php'); exit;
    }
  } catch (Throwable $ex) {
    $err = 'Action failed. Check logs.';
    error_log('[forward_review] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  }
}

$title = 'Review Forward Request';
ob_start();
?>
<h2>Review forward request</h2>

<?php if ($err): ?>
  <div style="padding:10px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:8px;margin-bottom:12px"><?= e($err) ?></div>
<?php endif; ?>

<div class="card">
  <h3>Query</h3>
  <div><strong>Code:</strong> <?= e($q['query_code'] ?: ('#'.$q['id'])) ?></div>
  <div><strong>Current team:</strong> <?= e($q['team_name'] ?: '-') ?></div>
  <div><strong>Assigned to:</strong> <?= e($q['assigned_name'] ?: '-') ?></div>
  <div><strong>Status:</strong> <?= e($q['status']) ?></div>
</div>

<div class="card">
  <h3>Requested move</h3>
  <div><strong>To team:</strong> <?= e($q['target_team_name'] ?: ('#'.$q['forward_request_team_id'])) ?></div>
  <div><strong>Priority:</strong> <?= e($q['forward_request_priority'] ?: 'default') ?></div>
  <div><strong>Requested by:</strong> #<?= (int)$q['forward_request_by'] ?> at <?= e($q['forward_request_at']) ?></div>
</div>

<form method="post" style="display:flex;gap:10px">
  <button class="btn" name="approve" value="1" type="submit">Approve</button>
  <button class="btn btn-danger" name="reject" value="1" type="submit">Reject</button>
  <a class="btn" href="/app/supervisor.php">Cancel</a>
</form>

<style>
  .card{background:#fff;border:1px solid #eee;border-radius:12px;padding:14px;margin-bottom:14px}
  .btn{background:#111827;color:#fff;border:0;border-radius:10px;padding:.6rem 1rem;cursor:pointer;text-decoration:none;display:inline-block}
  .btn-danger{background:#b91c1c}
</style>

<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
