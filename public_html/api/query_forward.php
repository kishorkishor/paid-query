<?php
require_once __DIR__.'/auth.php';
require_perm('assign_users');

/**
 * Forward a query to another team. Non-supervisors will create a forward request
 * instead of immediately transferring the query.
 */

$id      = (int)($_POST['id'] ?? 0);
$team_id = (int)($_POST['team_id'] ?? 0);
$priority= $_POST['priority'] ?? 'default';
$allowedP= ['default','low','medium','high','urgent'];
if (!$id || !$team_id || !in_array($priority, $allowedP, true)) {
  http_response_code(400); exit('Bad');
}

// verify team active
$st = db()->prepare("SELECT is_active FROM teams WHERE id=?");
$st->execute([$team_id]);
$t = $st->fetch();
if (!$t || (int)$t['is_active'] !== 1) {
  http_response_code(400); exit('Team inactive');
}

// Determine if the current user can approve forwarding directly
$canApprove = can('approve_forwarding');
$pdo = db();

if (!$canApprove) {
  // Create a forward request instead of immediate forward
  $pdo->prepare(
    "UPDATE queries SET forward_request_team_id=?, forward_request_priority=?, forward_request_by=?, forward_request_at=NOW()
     WHERE id=?"
  )->execute([$team_id, $priority, ($_SESSION['admin']['id'] ?? null), $id]);
  // Add internal note about request
  $pdo->prepare(
    "INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
     VALUES (?, ?, 'internal', 'note', ?, NOW())"
  )->execute([$id, ($_SESSION['admin']['id'] ?? null), "Forward request to team #{$team_id} (priority: {$priority})"]);
  // Audit log
  $meta = json_encode(['to_team'=>$team_id,'priority'=>$priority,'requested'=>true], JSON_UNESCAPED_SLASHES);
  $pdo->prepare(
    "INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
     VALUES ('query', ?, ?, 'forward_requested', ?)"
  )->execute([$id, ($_SESSION['admin']['id'] ?? null), $meta]);
  // Redirect back to query page with pending flag
  header("Location: /app/query.php?id=".$id."#thread");
  exit;
}

// Supervisor approval: proceed with immediate forward
$now = date('Y-m-d H:i:s');
// update query to new team
$pdo->prepare(
  "UPDATE queries SET current_team_id=?, priority=?, status='assigned',
                      sla_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR), assigned_admin_user_id=NULL
     WHERE id=?"
)->execute([$team_id, $priority, $id]);
// clear any pending request fields
$pdo->prepare(
  "UPDATE queries SET forward_request_team_id=NULL, forward_request_priority=NULL, forward_request_by=NULL, forward_request_at=NULL WHERE id=?"
)->execute([$id]);
// record assignment
$pdo->prepare(
  "INSERT INTO query_assignments (query_id, team_id, assigned_by, assigned_at, priority, note)
   VALUES (?, ?, ?, ?, ?, 'Forwarded')"
)->execute([$id, $team_id, ($_SESSION['admin']['id'] ?? null), $now, $priority]);
// audit
$meta = json_encode(['to_team'=>$team_id,'priority'=>$priority], JSON_UNESCAPED_SLASHES);
$pdo->prepare(
  "INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
   VALUES ('query', ?, ?, 'assigned', ?)"
)->execute([$id, ($_SESSION['admin']['id'] ?? null), $meta]);
// internal note
$pdo->prepare(
  "INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
   VALUES (?, ?, 'internal', 'note', ?, NOW())"
)->execute([$id, ($_SESSION['admin']['id'] ?? null), "Forwarded to team #{$team_id} (priority: {$priority})"]);

header("Location: /app/query.php?id=".$id);
