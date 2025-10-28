<?php
// /api/delivery_create.php — customer requests delivery for paid cartons
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/_delivery_lib.php';
header('Content-Type: application/json');

function jerr($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

try{
  $pdo = db();
  $orderId = (int)($_POST['order_id'] ?? 0);
  if ($orderId<=0) jerr('Bad order id');

  $ids = $_POST['carton_ids'] ?? [];
  if (!is_array($ids) || !count($ids)) jerr('No cartons selected');

  $cartonIds = [];
  foreach ($ids as $v){ $v=(int)$v; if($v>0) $cartonIds[]=$v; }
  if (!$cartonIds) jerr('No valid cartons');

  $did = queue_delivery_for_cartons($pdo, $orderId, $cartonIds, null, 'customer requested');
  if ($did<=0) jerr('No eligible paid cartons to queue', 400);

  echo json_encode(['ok'=>true,'delivery_id'=>$did]);
}catch(Throwable $e){
  error_log('[delivery_create] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
  jerr('Server error',500);
}
