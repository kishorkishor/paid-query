<?php
// app/query_supervisor.php — supervisor-only details + restricted assignment (regular team only)
// Now includes message composer to post to the thread as internal/note.
require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // supervisors only

// ===== Debug toggles (turn off after you verify) =====
error_reporting(E_ALL);
ini_set('display_errors', '0');            // keep off in prod
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);
$err = '';
$info= '';

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
  // ---- get query id
  $qid = (int)($_GET['id'] ?? 0);
  if ($qid <= 0) { header('Location: /app/supervisor.php'); exit; }

  // ---- load query with joins
  $st = $pdo->prepare("
    SELECT q.*,
           t.name AS team_name,
           c.name AS country_name,
           au.name AS assigned_name, au.email AS assigned_email
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
      LEFT JOIN countries c ON c.id = q.country_id
      LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
     WHERE q.id = ?
  ");
  $st->execute([$qid]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) { header('Location: /app/supervisor.php'); exit; }

  // ---- ELIGIBLE LIST: team_id=1 AND role_id=2, active, not me, not supervisors
  $eligibleStmt = $pdo->prepare("
    SELECT DISTINCT au.id, au.name, au.email
      FROM admin_users au
      JOIN admin_user_teams ut  ON ut.admin_user_id = au.id AND ut.team_id = 1
      JOIN admin_user_roles aur ON aur.admin_user_id = au.id AND aur.role_id = 2
     WHERE au.is_active = 1
       AND au.id <> :me
       AND NOT EXISTS (
         SELECT 1
           FROM admin_user_roles aur2
           JOIN role_permissions rp2 ON rp2.role_id = aur2.role_id
           JOIN permissions p2       ON p2.id = rp2.permission_id
          WHERE aur2.admin_user_id = au.id
            AND p2.name = 'assign_team_member'
       )
     ORDER BY au.name ASC
  ");
  $eligibleStmt->execute([':me'=>$me]);
  $eligibleUsers = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);

  // ---- POST: manual assign
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_uid'])) {
    $uid = (int)$_POST['assign_uid'];
    $ok = false;
    foreach ($eligibleUsers as $u) { if ((int)$u['id'] === $uid) { $ok = true; break; } }
    if ($ok) {
      $u1 = $pdo->prepare("UPDATE queries
                              SET assigned_admin_user_id=?, status='assigned', updated_at=NOW()
                            WHERE id=?");
      $u1->execute([$uid, $qid]);

      // audit_logs.meta as text (portable)
      $meta = json_encode(['to'=>$uid], JSON_UNESCAPED_SLASHES);
      $al = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                           VALUES ('query', ?, ?, 'assigned', ?, NOW())");
      $al->execute([$qid, $me, $meta]);

      header("Location: /app/query_supervisor.php?id=".$qid);
      exit;
    } else {
      $err = "You can assign only to Regular Sales agents (team_id=1, role_id=2, non-supervisors).";
    }
  }

  // ---- POST: auto assign (among eligible users) by workload + FIFO tie-break
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['auto_assign'])) {
    $work = []; // uid => ['cnt'=>open_count, 'old'=>oldest_job_ts]
    foreach ($eligibleUsers as $u) {
      $uid = (int)$u['id'];
      $row = $pdo->query("
        SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest
          FROM queries
         WHERE assigned_admin_user_id = {$uid}
           AND status IN ('new','elaborated','assigned','in_process')
      ")->fetch(PDO::FETCH_ASSOC);
      $work[$uid] = [
        'cnt' => (int)$row['cnt'],
        'old' => $row['oldest'] ? strtotime($row['oldest']) : PHP_INT_MAX,
      ];
    }

    if ($work) {
      $bestUid=null; $bestCnt=PHP_INT_MAX; $bestOld=PHP_INT_MAX;
      foreach ($work as $uid=>$w) {
        if ($w['cnt'] < $bestCnt || ($w['cnt']===$bestCnt && $w['old'] < $bestOld)) {
          $bestUid=$uid; $bestCnt=$w['cnt']; $bestOld=$w['old'];
        }
      }
      if ($bestUid) {
        $u1 = $pdo->prepare("UPDATE queries
                                SET assigned_admin_user_id=?, status='assigned', updated_at=NOW()
                              WHERE id=?");
        $u1->execute([$bestUid, $qid]);

        $meta = json_encode(['to'=>$bestUid], JSON_UNESCAPED_SLASHES);
        $al = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                             VALUES ('query', ?, ?, 'assigned_auto', ?, NOW())");
        $al->execute([$qid, $me, $meta]);

        header("Location: /app/query_supervisor.php?id=".$qid);
        exit;
      } else {
        $err = "No eligible Regular agent found for auto-assign.";
      }
    } else {
      $err = "No eligible Regular agent found for auto-assign.";
    }
  }

  // ---- POST: add internal note (message thread)
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['post_note'])) {
    $note = trim($_POST['note'] ?? '');
    if ($note === '') {
      $err = 'Message cannot be empty.';
    } else {
      // limit to a sane size
      if (function_exists('mb_substr')) $note = mb_substr($note, 0, 4000);
      else $note = substr($note, 0, 4000);

      $ins = $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ");
      $ins->execute([$qid, $me, $note]);

      $info = 'Message added.';
      // PRG
      header("Location: /app/query_supervisor.php?id=".$qid."#messages");
      exit;
    }
  }

  // ---- load attachments (portable: no assumed column names)
  $att = $pdo->prepare("SELECT * FROM query_attachments WHERE query_id=? ORDER BY id ASC");
  $att->execute([$qid]);
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC);

  // ---- load messages
  $msg = $pdo->prepare("SELECT m.*, a.email AS sender_email
                          FROM messages m
                     LEFT JOIN admin_users a ON a.id = m.sender_admin_id
                         WHERE m.query_id = ?
                      ORDER BY m.id ASC");
  $msg->execute([$qid]);
  $messages = $msg->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
  $err = 'Server error. Details logged.';
  error_log('[query_supervisor] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
}

// helpers for attachment display
function att_name(array $a){
  foreach (['file_name','filename','name','original_name','title'] as $k) {
    if (!empty($a[$k])) return (string)$a[$k];
  }
  foreach (['file_path','path','url','file','stored_name'] as $k) {
    if (!empty($a[$k])) return basename((string)$a[$k]);
  }
  return 'attachment';
}
function att_href(array $a){
  foreach (['file_path','path','url','file'] as $k) {
    if (!empty($a[$k])) return (string)$a[$k];
  }
  if (!empty($a['stored_name'])) return '/uploads/'.ltrim($a['stored_name'],'/');
  return '#';
}

$title = "Supervisor • Query Details";
ob_start();
?>
<h2>Query #<?= isset($q['id'])?(int)$q['id']:0 ?> — <?= isset($q['query_code'])?e($q['query_code']):'' ?></h2>

<?php if ($err): ?>
  <div style="padding:10px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:8px;margin-bottom:10px">
    <?= e($err) ?>
    <div style="color:#6b7280;font-size:.9rem;margin-top:4px">Check <code>app/_php_errors.log</code> if needed.</div>
  </div>
<?php elseif ($info): ?>
  <div style="padding:10px;border:1px solid #b7eb8f;background:#f6ffed;color:#135200;border-radius:8px;margin-bottom:10px">
    <?= e($info) ?>
  </div>
<?php endif; ?>

<?php if (!empty($q)): ?>
<div style="display:grid;grid-template-columns:1.2fr .8fr;gap:16px">
  <div>
    <div class="card">
      <h3>Summary</h3>
      <div><strong>Status:</strong> <?= e($q['status'] ?? '') ?></div>
      <div><strong>Priority:</strong> <?= e($q['priority'] ?? '') ?></div>
      <div><strong>Team:</strong> <?= e($q['team_name'] ?? '-') ?></div>
      <div><strong>Assigned To:</strong> <?= e($q['assigned_name'] ?? '-') ?> <?= !empty($q['assigned_email'])?('('.e($q['assigned_email']).')'):'' ?></div>
      <div><strong>Created:</strong> <?= e($q['created_at'] ?? '') ?></div>
      <div><strong>SLA Due:</strong> <?= e($q['sla_due_at'] ?? '-') ?></div>
    </div>

    <div class="card">
      <h3>Customer</h3>
      <div><strong>Name:</strong> <?= e($q['customer_name'] ?? '') ?></div>
      <div><strong>Phone:</strong> <?= e($q['phone'] ?? '') ?></div>
      <div><strong>Email:</strong> <?= e($q['email'] ?? '') ?></div>
      <div><strong>Country:</strong> <?= e($q['country_name'] ?? '-') ?></div>
      <div><strong>Address:</strong> <?= nl2br(e($q['address'] ?? '')) ?></div>
    </div>

    <div class="card">
      <h3>Query</h3>
      <div><strong>Type:</strong> <?= e($q['query_type'] ?? '') ?></div>
      <div><strong>Shipping Mode:</strong> <?= e($q['shipping_mode'] ?? '') ?></div>
      <div><strong>Product:</strong> <?= e($q['product_name'] ?? '') ?></div>
      <div><strong>Details:</strong><br><?= nl2br(e($q['product_details'] ?? '')) ?></div>
      <?php if (!empty($q['product_links'])): ?>
        <div><strong>Links:</strong> <?= nl2br(e($q['product_links'])) ?></div>
      <?php endif; ?>
      <div><strong>Quantity:</strong> <?= e($q['quantity'] ?? '') ?> &nbsp; <strong>Budget:</strong> <?= e($q['budget'] ?? '') ?></div>
      <div><strong>Cartons:</strong> <?= e($q['carton_count'] ?? '') ?> &nbsp; <strong>CBM:</strong> <?= e($q['cbm'] ?? '') ?> &nbsp; <strong>Label:</strong> <?= e($q['label_type'] ?? '') ?></div>
      <div><strong>Notes:</strong><br><?= nl2br(e($q['notes'] ?? '')) ?></div>
    </div>

    <div class="card">
      <h3>Attachments</h3>
      <?php if (empty($attachments)): ?>
        <em>No attachments.</em>
      <?php else: foreach ($attachments as $a): ?>
        <div style="margin-bottom:6px">
          <a href="<?= e(att_href($a)) ?>" target="_blank"><?= e(att_name($a)) ?></a>
          <?php if (!empty($a['created_at'])): ?>
            <span class="muted"><?= e($a['created_at']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card" id="messages">
      <h3>Message Thread</h3>

      <!-- Composer -->
      <form method="post" style="margin:0 0 12px 0">
        <textarea name="note" rows="3" required
          placeholder="Write an internal note to your team..."
          style="width:100%;padding:.7rem;border:1px solid #e5e7eb;border-radius:8px"></textarea>
        <div style="margin-top:6px;display:flex;gap:8px;align-items:center">
          <button class="btn" type="submit" name="post_note" value="1">Send</button>
          <span class="muted">Sent as <strong>internal / note</strong> from your account.</span>
        </div>
      </form>

      <?php if (empty($messages)): ?>
        <em>No messages yet.</em>
      <?php else: foreach ($messages as $m): ?>
        <div style="margin-bottom:10px">
          <div class="muted">
            <?= e($m['created_at']) ?> — <?= e($m['direction']) ?>/<?= e($m['medium']) ?> — <?= e($m['sender_email'] ?: 'system') ?>
          </div>
          <div style="white-space:pre-wrap;"><?= e($m['body']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div>
    <div class="card" style="position:sticky;top:12px">
      <h3>Assign to Regular Sales</h3>
      <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
        <select name="assign_uid" required style="flex:1">
          <option value="">-- Select Regular agent (team=1, role=2) --</option>
          <?php foreach ($eligibleUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Assign</button>
      </form>

      <form method="post">
        <input type="hidden" name="auto_assign" value="1">
        <button class="btn" type="submit">Auto</button>
      </form>

      <p class="muted" style="margin-top:10px">
        Only active members of <strong>Regular Sales</strong> (team_id=<strong>1</strong>, role_id=<strong>2</strong>) are eligible. Supervisors and yourself are excluded.
      </p>
    </div>
  </div>
</div>
<?php endif; ?>

<p style="margin-top:16px"><a href="/app/supervisor.php">&larr; Back to Supervisor</a></p>

<style>
.card{background:#fff;border:1px solid #eee;border-radius:12px;padding:14px;margin-bottom:14px}
h3{margin:0 0 8px}
.muted{color:#6b7280;font-size:.9rem;margin-left:6px}
.btn{background:#0f172a;color:#fff;border:0;border-radius:8px;padding:.5rem .8rem;cursor:pointer}
select{padding:.45rem;border:1px solid #e5e7eb;border-radius:8px}
</style>

<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
