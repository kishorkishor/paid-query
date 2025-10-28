<?php
// app/team_supervisor.php
require_once __DIR__ . '/auth.php';
require_perm('team_supervisor_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo     = db();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// CSRF token for logout
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Determine team
$teamId = (int)($_GET['team_id'] ?? 0);
if ($teamId <= 0) {
  $st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=? LIMIT 1");
  $st->execute([$adminId]);
  $teamId = (int)$st->fetchColumn();
}
if ($teamId <= 0) { http_response_code(400); die('No team selected'); }

// Fetch team agents
$agents = $pdo->prepare("
  SELECT au.id, au.name
    FROM admin_users au
    JOIN admin_user_roles aur ON aur.admin_user_id = au.id
    JOIN roles r             ON r.id = aur.role_id
    JOIN admin_user_teams ut ON ut.admin_user_id = au.id
   WHERE ut.team_id = ?
     AND r.name = 'team_agent'
     AND au.is_active = 1
   ORDER BY au.name
");
$agents->execute([$teamId]);
$teamAgents = $agents->fetchAll(PDO::FETCH_ASSOC);

// ------------ Form handlers ------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Accept forward
  if (isset($_POST['accept_forward'])) {
    $qid = (int)$_POST['qid'];
    $pdo->prepare("
      UPDATE queries
         SET status='assigned',
             forward_request_team_id=NULL,
             forward_request_priority=NULL,
             forward_request_by=NULL,
             forward_request_at=NULL,
             assigned_admin_user_id=NULL,
             updated_at=NOW()
       WHERE id=? AND current_team_id=? AND status='forwarded'
    ")->execute([$qid, $teamId]);

    $pdo->prepare("
      INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note','Forward accepted by country supervisor', NOW())
    ")->execute([$qid, $adminId]);
  }

  // Reject forward
  if (isset($_POST['reject_forward'])) {
    $qid     = (int)$_POST['qid'];
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $st = $pdo->prepare("SELECT last_assigned_admin_user_id FROM queries WHERE id=?");
    $st->execute([$qid]);
    $prev = (int)$st->fetchColumn();

    $pdo->prepare("
      UPDATE queries
         SET current_team_id = 1,
             status = 'new',
             assigned_admin_user_id = NULLIF(?, 0),
             forward_request_team_id=NULL,
             forward_request_priority=NULL,
             forward_request_by=NULL,
             forward_request_at=NULL,
             updated_at=NOW()
       WHERE id=? AND current_team_id=? AND status='forwarded'
    ")->execute([$prev, $qid, $teamId]);

    $pdo->prepare("
      INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note', ?, NOW())
    ")->execute([$qid, $adminId, "Forward rejected by country supervisor: {$remarks}"]);
  }

  // Assign agent
  if (isset($_POST['assign'])) {
    $qid = (int)$_POST['qid'];
    $uid = (int)$_POST['agent_id'];

    $found = false;
    foreach ($teamAgents as $a) {
      if ((int)$a['id'] === $uid) { $found = true; break; }
    }

    if ($found && $uid > 0) {
      $pdo->prepare("
        UPDATE queries
           SET assigned_admin_user_id=?,
               status='assigned',
               last_assigned_admin_user_id=?,
               sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
               updated_at=NOW()
         WHERE id=? AND current_team_id=?
      ")->execute([$uid, $uid, $qid, $teamId]);

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal','note', ?, NOW())
      ")->execute([$qid, $adminId, "Assigned to agent #{$uid}"]);
    }
  }

  // Auto assign (FIFO)
  if (isset($_POST['auto_assign'])) {
    $qid = (int)$_POST['qid'];
    if ($teamAgents) {
      $workload = [];
      $cntSt = $pdo->prepare("
        SELECT COUNT(*) cnt, MIN(created_at) oldest
          FROM queries
         WHERE assigned_admin_user_id=? 
           AND status IN ('assigned','price_submitted','negotiation_pending')
      ");
      foreach ($teamAgents as $a) {
        $uid = (int)$a['id'];
        $cntSt->execute([$uid]);
        $row = $cntSt->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0, 'oldest'=>null];
        $workload[$uid] = [(int)$row['cnt'], $row['oldest'] ? strtotime($row['oldest']) : PHP_INT_MAX];
      }
      asort($workload, SORT_REGULAR);
      $best = (int)array_key_first($workload);

      if ($best > 0) {
        $pdo->prepare("
          UPDATE queries
             SET assigned_admin_user_id=?,
                 status='assigned',
                 last_assigned_admin_user_id=?,
                 sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
                 updated_at=NOW()
           WHERE id=? AND current_team_id=?
        ")->execute([$best, $best, $qid, $teamId]);

        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'internal','note', ?, NOW())
        ")->execute([$qid, $adminId, "Auto-assigned to agent #{$best}"]);
      }
    }
  }

  // Approve price
  if (isset($_POST['approve_price'])) {
    $qid = (int)$_POST['qid'];

    $msg = $pdo->prepare("
      SELECT body FROM messages
       WHERE query_id=? AND medium='quote'
       ORDER BY id DESC LIMIT 1
    ");
    $msg->execute([$qid]);
    $quote = $msg->fetchColumn();

    if ($quote) {
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'outbound','message', ?, NOW())
      ")->execute([$qid, $adminId, $quote]);
    }

    $pdo->prepare("
      UPDATE queries
         SET status='price_approved',
             updated_at=NOW(),
             sla_reply_due_at=NULL
       WHERE id=?
    ")->execute([$qid]);

    $pdo->prepare("
      INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note','Price approved and sent to customer', NOW())
    ")->execute([$qid, $adminId]);
  }

  // Reject price
  if (isset($_POST['reject_price'])) {
    $qid     = (int)$_POST['qid'];
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $st = $pdo->prepare("SELECT last_assigned_admin_user_id FROM queries WHERE id=?");
    $st->execute([$qid]);
    $prev = (int)$st->fetchColumn();

    $pdo->prepare("
      UPDATE queries
         SET status='price_rejected',
             assigned_admin_user_id=NULLIF(?,0),
             sla_reply_due_at=DATE_ADD(NOW(), INTERVAL 36 HOUR),
             updated_at=NOW()
       WHERE id=?
    ")->execute([$prev, $qid]);

    $pdo->prepare("
      INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
      VALUES (?, ?, 'internal','note', ?, NOW())
    ")->execute([$qid, $adminId, "Price rejected by supervisor: {$remarks}"]);
  }
}

// ----------------- Fetch queries list -----------------
$st = $pdo->prepare("
  SELECT
    q.id, q.query_code, q.customer_name, q.phone, q.query_type,
    q.status, q.priority,
    q.assigned_admin_user_id, au.name AS assigned_name,
    q.forward_request_team_id, q.forward_request_priority, q.forward_request_at,
    q.last_assigned_admin_user_id
  FROM queries q
  LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
  WHERE q.current_team_id = ?
    AND q.status IN ('forwarded','assigned','price_submitted','price_rejected','negotiation_pending')
  ORDER BY q.id DESC
");
$st->execute([$teamId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Team Supervisor Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;background:#f6f7fb;margin:0;padding:20px}
    .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .topbar h2{margin:0}
    .topbar form{margin:0}
    table{width:100%;border-collapse:collapse;margin-bottom:20px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
    th,td{padding:8px;border-bottom:1px solid #e5e7eb;font-size:.92rem}
    th{background:#f3f4f6;text-align:left}
    td.actions form{display:inline-block;margin-right:6px}
    select,input[type="text"]{padding:.35rem .5rem;border:1px solid #e5e7eb;border-radius:8px}
    button{padding:.4rem .7rem;border:0;border-radius:8px;background:#111827;color:#fff;cursor:pointer}
    .btn-secondary{background:#374151}
    button + button{margin-left:4px}
  </style>
</head>
<body>

<div class="topbar">
  <h2>Team Supervisor Dashboard (Team ID <?= (int)$teamId ?>)</h2>
  <form method="post" action="/app/logout_sourcing.php">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <li><a href="/app/order_supervisor.php">Orders</a></li>
    <li><a href="/app/order_china_accounts.php">Chinese Accounts</a></li>
    <button class="btn-secondary" type="submit">Logout</button>
  </form>
</div>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Code</th>
      <th>Customer</th>
      <th>Contact</th>
      <th>Type</th>
      <th>Status</th>
      <th>Priority</th>
      <th>Assigned</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $q): ?>
      <tr>
        <td>#<?= (int)$q['id'] ?></td>
        <td><?= e($q['query_code']) ?></td>
        <td><?= e($q['customer_name']) ?></td>
        <td><?= e($q['phone']) ?></td>
        <td><?= e($q['query_type']) ?></td>
        <td><?= e($q['status']) ?></td>
        <td><?= e($q['priority']) ?></td>
        <td><?= e($q['assigned_name'] ?: '-') ?></td>
        <td class="actions">
          <?php if ($q['status'] === 'forwarded'): ?>
            <a class="btn" href="/app/query_team_supervisor.php?id=<?= (int)$q['id'] ?>&team_id=<?= (int)$teamId ?>">View</a>
          <?php elseif ($q['status'] === 'assigned'): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
              <select name="agent_id">
                <option value="">--Select--</option>
                <?php foreach ($teamAgents as $a): ?>
                  <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button name="assign" value="1">Assign</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="qid" value="<?= (int)$q['id'] ?>">
              <button name="auto_assign" value="1">Auto</button>
            </form>
          <?php elseif ($q['status'] === 'price_submitted'): ?>
            <a class="btn" href="/app/team_supervisor_review.php?id=<?= (int)$q['id'] ?>&team_id=<?= (int)$teamId ?>">Review</a>
          <?php elseif ($q['status'] === 'negotiation_pending'): ?>
            <a class="btn" href="/app/team_supervisor_negotiation.php?id=<?= (int)$q['id'] ?>&team_id=<?= (int)$teamId ?>">Review Negotiation</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
