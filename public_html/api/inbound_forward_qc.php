<?php
// /api/inbound_forward_qc.php — Finalize packing & forward to QC
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../app/auth.php';

require_login();
require_perm('forward_to_qc');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function team_id_by_name_or_code(PDO $pdo, string $name, string $code = null): ?int {
  $st = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1");
  $st->execute([$name]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return (int)$row['id'];
  if ($code) {
    $st = $pdo->prepare("SELECT id FROM teams WHERE code=? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['id'];
  }
  return null;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if(!$orderId){ http_response_code(400); echo "Bad order id"; exit; }

$inboundTeamId = team_id_by_name_or_code($pdo, 'Chinese Inbound', 'ch_inbound');
$qcTeamId      = team_id_by_name_or_code($pdo, 'QC', 'qc');

$st = $pdo->prepare("SELECT id,current_team_id,query_id FROM orders WHERE id=?");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); echo "Order not found"; exit; }
if((int)$o['current_team_id'] !== (int)$inboundTeamId){
  http_response_code(403); echo "Order not at Chinese Inbound"; exit;
}

// packing list checks
$pl = $pdo->prepare("SELECT * FROM inbound_packing_lists WHERE order_id=? ORDER BY id DESC LIMIT 1");
$pl->execute([$orderId]);
$packing = $pl->fetch(PDO::FETCH_ASSOC);
if(!$packing || (int)$packing['total_cartons'] <= 0){
  http_response_code(422); echo "Packing list missing"; exit;
}

$pdo->beginTransaction();
try {
  $pdo->prepare("UPDATE inbound_packing_lists SET status='finalized', finalized_at=NOW() WHERE id=?")
      ->execute([(int)$packing['id']]);

  $qc = $pdo->prepare("INSERT INTO qc_checks (order_id, result, notes, created_by)
                       VALUES (?,?,?,?)");
  $qc->execute([$orderId, 'pending', 'Auto-created from Inbound', $me]);

  $upd = $pdo->prepare("UPDATE orders
                           SET previous_team_id=current_team_id,
                               current_team_id=:qc,
                               status=:st
                         WHERE id=:id");
  $upd->execute([':qc'=>$qcTeamId, ':st'=>'qc_pending', ':id'=>$orderId]);

  $al = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                       VALUES ('order', ?, ?, 'forward_to_qc', JSON_OBJECT('from','Chinese Inbound','to','QC'))");
  $al->execute([$orderId, $me]);

  $msg = $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                        VALUES (?, ?, ?, 'internal', 'note', ?)");
  $msg->execute([$o['query_id'], $orderId, $me, 'Inbound: Finalized packing list and sent to QC']);

  $pdo->commit();
  http_response_code(200); echo "ok";
} catch(Exception $e){
  $pdo->rollBack();
  error_log("inbound_forward_qc error: ".$e->getMessage());
  http_response_code(500); echo "DB error: ".$e->getMessage();
}
