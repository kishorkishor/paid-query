<?php
require_once __DIR__.'/auth.php';
require_perm('view_queries');

if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$pdo      = db();
$adminId  = (int)($_SESSION['admin']['id']);
$isSupervisor = in_array('assign_team_member', $_SESSION['perms'] ?? [], true);

// We only want "assigned" rows here
const LIST_STATUS = 'assigned';

if ($isSupervisor) {
  // Supervisor: see assigned queries in their team(s)
  $st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
  $st->execute([$adminId]);
  $teamIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'team_id'));
  if (!$teamIds) { $teamIds = [0]; }
  $inTeams = implode(',', $teamIds);

  $rows = $pdo->query("
    SELECT q.id, q.query_code, q.customer_name, q.email, q.phone, q.query_type, q.status, q.priority,
           t.name AS team_name
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
     WHERE q.current_team_id IN ($inTeams)
       AND q.status = '" . LIST_STATUS . "'
     ORDER BY q.id DESC
     LIMIT 200
  ")->fetchAll(PDO::FETCH_ASSOC);

} else {
  // Regular agent: only queries assigned to them, and only if status = assigned
  $st = $pdo->prepare("
    SELECT q.id, q.query_code, q.customer_name, q.email, q.phone, q.query_type, q.status, q.priority,
           t.name AS team_name
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
     WHERE q.assigned_admin_user_id = ?
       AND q.status = '" . LIST_STATUS . "'
     ORDER BY q.id DESC
     LIMIT 200
  ");
  $st->execute([$adminId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$title='Queries';
ob_start(); ?>
<h2>Queries</h2>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Code</th>
      <th>Customer</th>
      <th>Contact</th>
      <th>Type</th>
      <th>Team</th>
      <th>Priority</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($rows as $r): ?>
    <tr>
      <td>#<?= (int)$r['id'] ?></td>
      <td><?= e($r['query_code']) ?></td>
      <td><?= e($r['customer_name']) ?></td>
      <td>
        <?php if(!empty($r['phone'])): ?><?= e($r['phone']) ?><?php endif; ?>
        <?php if(!empty($r['email'])): ?><div style="color:#6b7280;font-size:.9em"><?= e($r['email']) ?></div><?php endif; ?>
      </td>
      <td><?= e($r['query_type']) ?></td>
      <td><?= e($r['team_name'] ?: '-') ?></td>
      <td><span class="badge"><?= e($r['priority']) ?></span></td>
      <td><span class="badge"><?= e($r['status']) ?></span></td>
      <td>
        <?php
          $viewHref = $isSupervisor
            ? '/app/query_supervisor.php?id='.(int)$r['id']
            : '/app/query.php?id='.(int)$r['id'];
        ?>
        <a class="btn" href="<?= $viewHref ?>">View</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
