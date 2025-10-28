<?php
// /api/_delivery_lib.php — helpers to queue delivery to BD inbound team

require_once __DIR__ . '/lib.php';

function _rand_code($len=6){
  $al='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $o=''; for($i=0;$i<$len;$i++) $o.=$al[random_int(0,strlen($al)-1)];
  return $o;
}

/**
 * Queue a delivery request for specific cartons from an order.
 * Preconditions: cartons belong to order_id and are paid (bd_payment_status='verified').
 * Returns delivery_id (int).
 */
function queue_delivery_for_cartons(PDO $pdo, int $orderId, array $cartonIds, ?int $createdBy=null, string $note='auto from payment'): int {
  if (!$cartonIds) { return 0; }
  // Validate: unpaid or foreign cartons are ignored
  $in = implode(',', array_fill(0,count($cartonIds),'?'));
  $args = $cartonIds; array_unshift($args, $orderId);
  $q = $pdo->prepare("
     SELECT c.id
       FROM inbound_cartons c
       JOIN inbound_packing_lists p ON p.id=c.packing_list_id
      WHERE p.order_id=? AND c.id IN ($in)
        AND COALESCE(c.bd_payment_status,'pending')='verified'
  ");
  $q->execute($args);
  $valid = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
  if (!$valid) return 0;

  // Create header
  $code = 'DLV-'. _rand_code(6);
  $ins = $pdo->prepare("INSERT INTO deliveries (order_id, request_code, created_at, created_by, team, status, notes)
                        VALUES (?, ?, NOW(), ?, 'bd_inbound', 'queued', ?)");
  $ins->execute([$orderId, $code, $createdBy, $note]);
  $did = (int)$pdo->lastInsertId();

  // Items
  $insI = $pdo->prepare("INSERT IGNORE INTO delivery_items (delivery_id, carton_id) VALUES (?, ?)");
  foreach ($valid as $cid) { $insI->execute([$did, $cid]); }

  // Mark cartons
  $in2 = implode(',', array_map('intval', $valid));
  $pdo->exec("UPDATE inbound_cartons SET delivery_status='queued' WHERE id IN ($in2)");

  return $did;
}
