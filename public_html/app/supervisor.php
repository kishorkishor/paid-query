<?php
// Supervisor dashboard for regular sales team
// Shows team metrics, recent activity, pending forward approvals,
// and a team query list (no inline assignment; only View -> query_supervisor.php).

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // supervisors only

$adminId = $_SESSION['admin']['id'] ?? 0;

// Figure out which teams this supervisor oversees: leader teams + membership teams.
// If none, default to team 1 (Regular Sales).
$teamIds = [];

// Leader teams
$st = db()->prepare("SELECT id FROM teams WHERE leader_admin_user_id=?");
$st->execute([$adminId]);
foreach ($st->fetchAll() as $row) $teamIds[] = (int)$row['id'];

// Membership teams
$st = db()->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
$st->execute([$adminId]);
foreach ($st->fetchAll() as $row) $teamIds[] = (int)$row['team_id'];

$teamIds = array_values(array_unique($teamIds));
if (!$teamIds) $teamIds = [1]; // fallback to Regular Sales

// Helper for redirects
function redirect_back() {
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// ===== Handle only forward-approval actions here (no assign/auto on this page) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo = db();
  $inTeams = implode(',', array_map('intval', $teamIds));

  // Approve forwarding request
  if (isset($_POST['approve_forward_action'])) {
    $qid = (int)($_POST['qid'] ?? 0);
    if ($qid > 0 && can('approve_forwarding')) {
      // Ensure the query is within supervisor's teams
      $q = $pdo->prepare(
        "SELECT forward_request_team_id, forward_request_priority, forward_request_by
           FROM queries
          WHERE id=? AND current_team_id IN ($inTeams)
          LIMIT 1"
      );
      $q->execute([$qid]);
      $req = $q->fetch(PDO::FETCH_ASSOC);

      if ($req && $req['forward_request_team_id']) {
        $targetTeam = (int)$req['forward_request_team_id'];
        $priority   = $req['forward_request_priority'] ?: 'default';

        // Move query, reset SLA, clear request fields
        $pdo->prepare(
          "UPDATE queries
              SET current_team_id=?,
                  priority=?,
                  status='assigned',
                  sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR),
                  assigned_admin_user_id=NULL,
                  forward_request_team_id=NULL,
                  forward_request_priority=NULL,
                  forward_request_by=NULL,
                  forward_request_at=NULL
            WHERE id=?"
        )->execute([$targetTeam, $priority, $qid]);

        // Record assignment
        $pdo->prepare(
          "INSERT INTO query_assignments (query_id, team_id, assigned_by, assigned_at, priority, note)
           VALUES (?, ?, ?, NOW(), ?, 'Forwarded (approved)')"
        )->execute([$qid, $targetTeam, $adminId, $priority]);

        // Audit
        $pdo->prepare(
          "INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
           VALUES ('query', ?, ?, 'assigned', JSON_OBJECT('to_team', ?, 'priority', ?), NOW())"
        )->execute([$qid, $adminId, $targetTeam, $priority]);

        // Internal note
        $pdo->prepare(
          "INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
           VALUES (?, ?, 'internal', 'note', ?, NOW())"
        )->execute([$qid, $adminId, "Forward approved to team #{$targetTeam} (priority: {$priority})"]);
      }
    }
    redirect_back();
  }

  // Reject forwarding request
  if (isset($_POST['reject_forward_action'])) {
    $qid = (int)($_POST['qid'] ?? 0);
    if ($qid > 0 && can('approve_forwarding')) {
      $pdo->prepare(
        "UPDATE queries
            SET forward_request_team_id=NULL,
                forward_request_priority=NULL,
                forward_request_by=NULL,
                forward_request_at=NULL
          WHERE id=?"
      )->execute([$qid]);

      // Audit
      $pdo->prepare(
        "INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
         VALUES ('query', ?, ?, 'forward_rejected', JSON_OBJECT('reason','forward_rejected'), NOW())"
      )->execute([$qid, $adminId]);

      // Internal note
      $pdo->prepare(
        "INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
         VALUES (?, ?, 'internal', 'note', 'Forward request rejected', NOW())"
      )->execute([$qid, $adminId]);
    }
    redirect_back();
  }
}

// ===== Fetch data for dashboard =====
$pdo = db();
$inTeams = implode(',', array_map('intval', $teamIds));

// Team queries
$sqlQueries = "
  SELECT q.id, q.query_code, q.status, q.priority, q.query_type, q.created_at, q.product_name,
         q.assigned_admin_user_id, au.name AS assigned_name,
         q.current_team_id, t.name AS team_name,
         q.forward_request_team_id, q.forward_request_priority, q.forward_request_by, q.forward_request_at,
         frt.name AS forward_team_name,
         fu.name  AS forward_by_name
    FROM queries q
    LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
    LEFT JOIN teams t       ON t.id = q.current_team_id
    LEFT JOIN teams frt     ON frt.id = q.forward_request_team_id
    LEFT JOIN admin_users fu ON fu.id = q.forward_request_by
   WHERE q.current_team_id IN ($inTeams)
   ORDER BY q.id DESC
   LIMIT 500
";
$queries = $pdo->query($sqlQueries)->fetchAll(PDO::FETCH_ASSOC);

// Team members (for metrics)
$teamMembers = [];
$stMem = $pdo->query("
  SELECT au.id, au.name, ut.team_id
    FROM admin_users au
    JOIN admin_user_teams ut ON ut.admin_user_id = au.id
   WHERE ut.team_id IN ($inTeams) AND au.is_active=1
   ORDER BY au.name ASC
");
foreach ($stMem->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $teamMembers[(int)$row['team_id']][] = ['id'=>(int)$row['id'],'name'=>$row['name']];
}

// Metrics per member
$metrics = [];
foreach ($teamMembers as $tid => $members) {
  foreach ($members as $m) {
    $uid = (int)$m['id'];
    $metrics[$uid] = [
      'name' => $m['name'],
      'team_id' => $tid,
      'assigned' => 0,
      'closed' => 0,
      'avg_res_seconds' => null,
    ];
  }
}

if ($metrics) {
  $uids = implode(',', array_keys($metrics));

  // Assigned count
  $resA = $pdo->query("
    SELECT assigned_admin_user_id AS uid, COUNT(*) AS cnt
      FROM queries
     WHERE assigned_admin_user_id IN ($uids)
     GROUP BY assigned_admin_user_id
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($resA as $r) {
    $uid = (int)$r['uid'];
    if (isset($metrics[$uid])) $metrics[$uid]['assigned'] = (int)$r['cnt'];
  }

  // Closed/converted + avg resolution seconds
  $resC = $pdo->query("
    SELECT assigned_admin_user_id AS uid,
           COUNT(*) AS cnt,
           AVG(TIMESTAMPDIFF(SECOND, created_at, IFNULL(updated_at, created_at))) AS avg_sec
      FROM queries
     WHERE assigned_admin_user_id IN ($uids)
       AND status IN ('closed','converted')
     GROUP BY assigned_admin_user_id
  ")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($resC as $r) {
    $uid = (int)$r['uid'];
    if (isset($metrics[$uid])) {
      $metrics[$uid]['closed'] = (int)$r['cnt'];
      $metrics[$uid]['avg_res_seconds'] = $r['avg_sec'] !== null ? (float)$r['avg_sec'] : null;
    }
  }
}

// Summary counts
$counts = ['total'=>0,'new'=>0,'inproc'=>0,'red'=>0,'pending'=>0];
foreach ($queries as $q) {
  $counts['total']++;
  if (in_array($q['status'], ['new','elaborated','assigned'], true)) $counts['new']++;
  elseif ($q['status'] === 'in_process') $counts['inproc']++;
  elseif ($q['status'] === 'red_flag') $counts['red']++;
  if ($q['forward_request_team_id']) $counts['pending']++;
}

// Recent activity logs (latest 20 for these teams)
$logs = $pdo->query("
  SELECT l.id, l.entity_id AS query_id, q.query_code, l.action, l.meta, l.created_at
    FROM audit_logs l
    JOIN queries q ON q.id = l.entity_id
   WHERE l.entity_type='query'
     AND q.current_team_id IN ($inTeams)
   ORDER BY l.id DESC
   LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Supervisor Dashboard';
ob_start();
?>
<h2>Supervisor Dashboard</h2>
<p>This dashboard allows you to monitor your team(s), approve or reject forwarding requests, and review performance metrics.  
To assign a query, click <strong>View</strong> and use the assignment panel on the details page.</p>

<!-- Notification summary -->
<div style="display:flex;gap:20px;margin-bottom:16px">
  <div style="background:#f6f9ff;border:1px solid #dde7ff;border-radius:8px;padding:12px">
    <strong>Total Queries:</strong> <span id="cTotal"><?= (int)$counts['total'] ?></span>
  </div>
  <div style="background:#f6f9ff;border:1px solid #dde7ff;border-radius:8px;padding:12px">
    <strong>New/Assigned:</strong> <span id="cNew"><?= (int)$counts['new'] ?></span>
  </div>
  <div style="background:#f6f9ff;border:1px solid #dde7ff;border-radius:8px;padding:12px">
    <strong>In Process:</strong> <span id="cInproc"><?= (int)$counts['inproc'] ?></span>
  </div>
  <div style="background:#fef5f5;border:1px solid #f8d7da;border-radius:8px;padding:12px;color:#b91c1c">
    <strong>Red Flags:</strong> <span id="cRed"><?= (int)$counts['red'] ?></span>
  </div>
  <div style="background:#fff9e6;border:1px solid #ffe7a8;border-radius:8px;padding:12px;color:#8a6d3b">
    <strong>Pending Forwards:</strong> <span id="cPending"><?= (int)$counts['pending'] ?></span>
  </div>
</div>

<!-- Metrics Table -->
<h3>Team Performance Metrics</h3>
<table>
  <thead>
    <tr>
      <th>User</th>
      <th>Team</th>
      <th>Assigned</th>
      <th>Closed</th>
      <th>Avg Res. Time</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($metrics as $uid => $m): ?>
      <tr data-uid="<?= (int)$uid ?>">
        <td><?= htmlspecialchars($m['name']) ?></td>
        <td><?= htmlspecialchars($m['team_id']) ?></td>
        <td class="assigned"><?= (int)$m['assigned'] ?></td>
        <td class="closed"><?= (int)$m['closed'] ?></td>
        <td class="avg">
          <?php if ($m['avg_res_seconds'] === null): ?>–
          <?php else:
            $days = $m['avg_res_seconds'] / 86400;
            echo number_format($days, 2) . ' days';
          endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Recent Activity -->
<h3 style="margin-top:24px">Recent Activity</h3>
<div id="notifPanel" style="max-height:260px;overflow:auto;border:1px solid #eee;border-radius:8px;padding:10px;background:#fafafa">
  <?php foreach ($logs as $lg): ?>
    <?php
      $metaStr = '';
      if (!empty($lg['meta'])) {
        $metaObj = json_decode($lg['meta'], true);
        if (is_array($metaObj)) {
          foreach ($metaObj as $k=>$v) { $metaStr .= $k.':'.$v.' '; }
        } else {
          $metaStr = $lg['meta'];
        }
      }
      $when = htmlspecialchars(date('Y-m-d H:i', strtotime($lg['created_at'])));
    ?>
    <div style="margin-bottom:6px;font-size:.9rem;color:#555">
      <strong><?= htmlspecialchars($lg['query_code']) ?></strong> — <?= htmlspecialchars($lg['action']) ?>
      <?php if ($metaStr): ?> (<?= htmlspecialchars(trim($metaStr)) ?>)<?php endif; ?>
      <span class="note" style="float:right"><?= $when ?></span>
    </div>
  <?php endforeach; ?>
</div>

<!-- Pending Forward Requests -->
<?php if ($counts['pending']): ?>
  <h3 style="margin-top:24px">Pending Forward Approval</h3>
  <table>
    <thead><tr>
      <th>ID</th>
      <th>Code</th>
      <th>Requested by</th>
      <th>Requested at</th>
      <th>Target Team</th>
      <th>Priority</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($queries as $q): if (!$q['forward_request_team_id']) continue; ?>
  <tr>
    <td>#<?= (int)$q['id'] ?></td>
    <td><?= htmlspecialchars($q['query_code'] ?: '') ?></td>
    <td><?= htmlspecialchars($q['forward_by_name'] ?: ('#' . $q['forward_request_by'])) ?></td>
    <td><?= htmlspecialchars($q['forward_request_at']) ?></td>
    <td><?= htmlspecialchars($q['forward_team_name'] ?: ('#' . $q['forward_request_team_id'])) ?></td>
    <td><?= htmlspecialchars($q['forward_request_priority']) ?></td>
    <td style="display:flex;gap:6px">
      <a class="btn" href="/app/query_supervisor_ar.php?id=<?= (int)$q['id'] ?>">View</a>
    </td>
  </tr>
<?php endforeach; ?>

    </tbody>
  </table>
<?php endif; ?>

<!-- Team Queries (no inline assign; only View) -->
<h3 style="margin-top:24px">Team Queries</h3>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Code</th>
      <th>Type</th>
      <th>Status</th>
      <th>Priority</th>
      <th>Product</th>
      <th>Assigned To</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($queries as $q): ?>
      <tr>
        <td>#<?= (int)$q['id'] ?></td>
        <td><?= htmlspecialchars($q['query_code']) ?></td>
        <td><?= htmlspecialchars($q['query_type']) ?></td>
        <td><?= htmlspecialchars($q['status']) ?></td>
        <td><?= htmlspecialchars($q['priority']) ?></td>
        <td><?= htmlspecialchars($q['product_name'] ?: '-') ?></td>
        <td><?= htmlspecialchars($q['assigned_name'] ?: '-') ?></td>
        <td>
          <a class="btn" href="/app/query_supervisor.php?id=<?= (int)$q['id'] ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script>
// Auto-refresh dashboard counts/metrics/logs every 60s
async function refreshDashboard() {
  try {
    const res = await fetch('/api/supervisor_dashboard_data.php', {credentials:'include'});
    const data = await res.json();
    if (!data.ok) return;

    const c = data.counts || {};
    const set = (id,val)=>{ const n=document.getElementById(id); if(n) n.textContent = val ?? 0; };
    set('cTotal', c.total); set('cNew', c.new); set('cInproc', c.inproc); set('cRed', c.red); set('cPending', c.pending);

    const metrics = data.metrics || {};
    Object.keys(metrics).forEach(uid => {
      const row = document.querySelector('tr[data-uid="'+uid+'"]');
      if (!row) return;
      const m = metrics[uid];
      const a = row.querySelector('.assigned');
      const cl= row.querySelector('.closed');
      const av= row.querySelector('.avg');
      if (a) a.textContent = m.assigned;
      if (cl) cl.textContent = m.closed;
      if (av) {
        av.textContent = (m.avg_res_seconds == null) ? '–' : (m.avg_res_seconds/86400).toFixed(2)+' days';
      }
    });

    // Logs
    if (data.logs) {
      const panel = document.getElementById('notifPanel');
      if (panel) {
        panel.innerHTML = '';
        data.logs.forEach(lg => {
          const when = (new Date(lg.created_at)).toISOString().slice(0,16).replace('T',' ');
          const meta = lg.meta || '';
          const div = document.createElement('div');
          div.style.marginBottom='6px'; div.style.fontSize='.9rem'; div.style.color='#555';
          div.innerHTML = '<strong>'+ (lg.query_code || ('#'+lg.query_id)) +'</strong> — '+ lg.action +
                          (meta ? ' ('+meta+')' : '') +
                          '<span style="float:right;color:#999">'+ when +'</span>';
          panel.appendChild(div);
        });
      }
    }
  } catch (e) { console.error('refresh failed', e); }
}
setInterval(refreshDashboard, 60000);
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
