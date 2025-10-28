<?php
require_once __DIR__.'/auth.php';
require_perm('view_queries');

$id = (int)($_POST['id'] ?? 0);
$medium = $_POST['medium'] ?? 'internal';
$direction = $_POST['direction'] ?? 'internal';
$body = trim($_POST['body'] ?? '');

$allowedM = ['internal','email','whatsapp','voice','other','customer_portal'];
$allowedD = ['internal','outbound','inbound'];

if (!$id || !$body || !in_array($medium,$allowedM,true) || !in_array($direction,$allowedD,true)) {
  http_response_code(400); exit('Bad');
}

/* RULE: only the CURRENT TEAM of the query may contact the customer.
   - when direction != internal, we treat it as an admin↔customer touch
*/
$q = db()->prepare("SELECT current_team_id FROM queries WHERE id=?");
$q->execute([$id]);
$currentTeam = (int)$q->fetchColumn();

if ($direction !== 'internal') {
  // find admin's teams
  $teams = db()->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
  $teams->execute([$_SESSION['admin']['id'] ?? 0]);
  $allowedTeams = array_map('intval', array_column($teams->fetchAll(), 'team_id'));
  if ($currentTeam && !in_array($currentTeam, $allowedTeams, true)) {
    http_response_code(403); exit('Only the currently assigned team may reply.');
  }
}

db()->prepare("INSERT INTO messages (query_id, sender_admin_id, direction, medium, body)
               VALUES (?, ?, ?, ?, ?)")
  ->execute([$id, $_SESSION['admin']['id'] ?? null, $direction, $medium, $body]);

db()->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
               VALUES ('query', ?, ?, 'message_added', JSON_OBJECT('medium', ?, 'direction', ?))")
  ->execute([$id, $_SESSION['admin']['id'] ?? null, $medium, $direction]);

header("Location: /app/query.php?id=".$id."#thread");
