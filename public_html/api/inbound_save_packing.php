<?php
// /api/inbound_save_packing.php — Save/Update packing list and cartons
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../app/auth.php';

require_login();
require_perm('create_packing_list');

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

$orderId       = (int)($_POST['order_id'] ?? 0);
$shipping_mark = trim($_POST['shipping_mark'] ?? '');
$total         = (int)($_POST['total_cartons'] ?? 0);
$cartons_json  = (string)($_POST['cartons_json'] ?? '[]');

if(!$orderId || !$shipping_mark || $total<=0){
  http_response_code(400); echo "Bad input"; exit;
}

$inboundTeamId = team_id_by_name_or_code($pdo, 'Chinese Inbound', 'ch_inbound');

$st = $pdo->prepare("SELECT id,current_team_id,query_id FROM orders WHERE id=?");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if(!$o){
  http_response_code(404); echo "Order not found"; exit;
}
if((int)$o['current_team_id'] !== (int)$inboundTeamId){
  http_response_code(403); echo "Order not at Chinese Inbound"; exit;
}

$cartons = json_decode($cartons_json, true) ?: [];
if(count($cartons)!==$total){ http_response_code(422); echo "Carton count mismatch"; exit; }
foreach($cartons as $c){
  if(($c['shipping_mark'] ?? '') !== $shipping_mark){
    http_response_code(422); echo "All cartons must share the same shipping mark"; exit;
  }
}

$pdo->beginTransaction();
try {
  // Upsert by UNIQUE(order_id)
  $pl = $pdo->prepare("
    INSERT INTO inbound_packing_lists (order_id, shipping_mark, total_cartons, status, created_by)
    VALUES (?,?,?,?,?)
    ON DUPLICATE KEY UPDATE shipping_mark=VALUES(shipping_mark),
                            total_cartons=VALUES(total_cartons),
                            status='draft'
  ");
  $pl->execute([$orderId, $shipping_mark, $total, 'draft', $me]);

  // Stable way to get id
  $gx = $pdo->prepare("SELECT id FROM inbound_packing_lists WHERE order_id=?");
  $gx->execute([$orderId]);
  $plid = (int)$gx->fetchColumn();

  // Replace cartons
  $pdo->prepare("DELETE FROM inbound_cartons WHERE packing_list_id=?")->execute([$plid]);
  $ins = $pdo->prepare("INSERT INTO inbound_cartons (packing_list_id, carton_no, weight_kg, shipping_mark) VALUES (?,?,?,?)");
  foreach($cartons as $c){
    $ins->execute([$plid, (int)$c['no'], (float)$c['weight_kg'], $shipping_mark]);
  }

  // Update orders snapshot (carton_count)
  $pdo->prepare("UPDATE orders SET carton_count=? WHERE id=?")->execute([$total, $orderId]);

  // Audit
  $al = $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                       VALUES ('order', ?, ?, 'packing_saved', JSON_OBJECT('cartons', ?, 'shipping_mark', ?))");
  $al->execute([$orderId, $me, $total, $shipping_mark]);

  $pdo->commit();
  http_response_code(200);
  echo "ok";
} catch(Exception $e){
  $pdo->rollBack();
  error_log("inbound_save_packing error: ".$e->getMessage());
  http_response_code(500);
  echo "DB error: ".$e->getMessage();
}
