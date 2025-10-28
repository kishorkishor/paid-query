<?php
require_once __DIR__.'/auth.php';
require_perm('assign_users');

$id = (int)($_POST['id'] ?? 0);
$team_id = (int)($_POST['team_id'] ?? 0);
$priority = $_POST['priority'] ?? 'default';
$allowedP = ['default','low','medium','high'];
if (!$id || !$team_id || !in_array($priority,$allowedP,true)) { http_response_code(400); exit('Bad'); }

// verify team active
$st = db()->prepare("SELECT is_active FROM teams WHERE id=?");
$st->execute([$team_id]);
$t = $st->fetch();
if (!$t || (int)$t['is_active'] !== 1) { http_response_code(400); exit('Team inactive'); }

// update query
$now = date('Y-m-d H:i:s');
$st = db()->prepare("UPDATE queries SET current_team_id=?, priority=?, status='assigned', sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=?");
$st->execute([$team_id, $priority, $id]);

// record assignment
$st = db()->prepare("INSERT INTO query_assignments (query_id, team_id, assigned_by, assigned_at, priority, note)
                     VALUES (?, ?, ?, ?, ?, 'Forwarded')");
$st->execute([$id, $team_id, $_SESSION['admin']['id'] ?? null, $now, $priority]);

// audit
$meta = json_encode(['to_team'=>$team_id, 'priority'=>$priority], JSON_UNESCAPED_SLASHES);
db()->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
               VALUES ('query', ?, ?, 'assigned', ?)")
  ->execute([$id, $_SESSION['admin']['id'] ?? null, $meta]);

header("Location: /app/query.php?id=".$id);
