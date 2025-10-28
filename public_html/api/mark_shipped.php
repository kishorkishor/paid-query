<?php
// /api/mark_shipped.php
// Marks selected cartons as shipped, attaches them to a shipment lot,
// updates packing list & order (partially shipped / shipped).

require_once __DIR__ . '/../app/auth.php';
require_login();
require_perm('handoff_bd_inbound'); // Chinese Inbound role

header('Content-Type: application/json');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

// ------- Input -------
$data           = json_decode(file_get_contents('php://input'), true) ?: [];
$packingListId  = (int)($data['packing_list_id'] ?? 0);
$cartonIds      = array_map('intval', $data['carton_ids'] ?? []);
$courier        = trim($data['courier'] ?? '');
$tracking       = trim($data['tracking'] ?? '');
$shipmentLot    = trim($data['shipment_lot'] ?? '');     // NEW (required)
$shippedAtIn    = trim($data['shipped_at'] ?? '');
$note           = trim($data['note'] ?? '');
$now            = date('Y-m-d H:i:s');
$shippedAt      = ($shippedAtIn === '' || strtolower($shippedAtIn) === 'now') ? $now : $shippedAtIn;

// --- Validation ---
if ($packingListId <= 0 || empty($cartonIds)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid input']); exit;
}
if ($courier === '' || $tracking === '') {
  echo json_encode(['ok'=>false,'error'=>'Courier name and tracking number are required']); exit;
}
if ($shipmentLot === '') {
  echo json_encode(['ok'=>false,'error'=>'Shipment lot is required']); exit;
}

try {
  $pdo->beginTransaction();

  // Get packing list + order id
  $plStmt = $pdo->prepare("SELECT id, order_id FROM inbound_packing_lists WHERE id=? LIMIT 1");
  $plStmt->execute([$packingListId]);
  $plRow = $plStmt->fetch(PDO::FETCH_ASSOC);
  if (!$plRow) throw new RuntimeException('Packing list not found.');
  $orderId = (int)$plRow['order_id'];

  // Upsert shipment lot record
  // Origin team: Chinese Inbound; Destination team: Bangladesh Inbound (look up by code)
  $teamOrigin = $pdo->query("SELECT id FROM teams WHERE code='ch_inbound' LIMIT 1")->fetchColumn();
  $teamDest   = $pdo->query("SELECT id FROM teams WHERE code='bd_inbound' LIMIT 1")->fetchColumn();

  $lotId = (int)$pdo->prepare("SELECT id FROM shipment_lots WHERE lot_code=? LIMIT 1")
                   ->execute([$shipmentLot]) ?: 0;

  $st = $pdo->prepare("SELECT id FROM shipment_lots WHERE lot_code=? LIMIT 1");
  $st->execute([$shipmentLot]);
  $lotId = (int)($st->fetchColumn() ?: 0);

  if ($lotId === 0) {
    $ins = $pdo->prepare("INSERT INTO shipment_lots
      (lot_code, courier_name, tracking_no, origin_team_id, dest_team_id, status, shipped_at, created_by, notes)
      VALUES (?, ?, ?, ?, ?, 'shipped', ?, ?, ?)");
    $ins->execute([$shipmentLot, $courier, $tracking, $teamOrigin ?: null, $teamDest ?: null, $shippedAt, $me, $note ?: null]);
    $lotId = (int)$pdo->lastInsertId();
  } else {
    // Update existing lot with latest courier/tracking and mark shipped
    $pdo->prepare("UPDATE shipment_lots
                      SET courier_name=?, tracking_no=?, origin_team_id=?, dest_team_id=?, status='shipped', shipped_at=?
                    WHERE id=?")
        ->execute([$courier, $tracking, $teamOrigin ?: null, $teamDest ?: null, $shippedAt, $lotId]);
  }

  // Link cartons to the lot
  $link = $pdo->prepare("INSERT IGNORE INTO shipment_lot_cartons(lot_id, carton_id) VALUES(?, ?)");
  foreach ($cartonIds as $cid) {
    $link->execute([$lotId, $cid]);
  }

  // Update courier info on packing list (as before)
  $pdo->prepare("UPDATE inbound_packing_lists
                    SET courier_name=?, tracking_no=?
                  WHERE id=?")
      ->execute([$courier, $tracking, $packingListId]);

  // Mark selected cartons shipped (only pending)
  $updCarton = $pdo->prepare("
      UPDATE inbound_cartons
         SET status='shipped', shipped_at=?, shipped_by_admin=?
       WHERE id=? AND packing_list_id=? AND status='pending'
  ");
  foreach ($cartonIds as $cid) {
    $updCarton->execute([$shippedAt, $me, $cid, $packingListId]);
  }

  // Recompute packing-list summary
  $total   = (int)$pdo->query("SELECT COUNT(*) FROM inbound_cartons WHERE packing_list_id={$packingListId}")->fetchColumn();
  $shipped = (int)$pdo->query("SELECT COUNT(*) FROM inbound_cartons WHERE packing_list_id={$packingListId} AND status='shipped'")->fetchColumn();

  $plStatus = 'not shipped';
  if ($shipped > 0 && $shipped < $total) $plStatus = 'partially shipped';
  if ($shipped === $total && $total > 0) $plStatus = 'fully shipped';

  $pdo->prepare("UPDATE inbound_packing_lists
                    SET shipped_cartons=?, shipped_status=?, finalized_at=?
                  WHERE id=?")
      ->execute([$shipped, $plStatus, $now, $packingListId]);

  // Update main order status
  $orderStatus = null;
  if     ($shipped > 0 && $shipped < $total) $orderStatus = 'partially shipped';
  elseif ($shipped === $total)               $orderStatus = 'shipped';

  if ($orderStatus !== null) {
    $pdo->prepare("UPDATE orders SET status=?, updated_at=? WHERE id=?")
        ->execute([$orderStatus, $now, $orderId]);

    $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta)
                   VALUES ('order', ?, ?, 'shipping_progress',
                           JSON_OBJECT('shipped', ?, 'total', ?, 'status', ?, 'packing_list_id', ?, 'lot_code', ?))")
        ->execute([$orderId, $me, $shipped, $total, $orderStatus, $packingListId, $shipmentLot]);

    $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body)
                   SELECT o.query_id, o.id, ?, 'internal', 'note',
                          CONCAT('Inbound: ', ?, ' (', ?, '/', ?, ') — Lot: ', ?, ' — Courier: ', ?, ' — Tracking: ', ?,
                                 IF(?, CONCAT(' — Note: ', ?), ''))
                     FROM orders o
                    WHERE o.id=?")
        ->execute([$me, $orderStatus, $shipped, $total, $shipmentLot, $courier, $tracking, ($note !== ''), $note, $orderId]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true, 'shipped'=>$shipped, 'total'=>$total, 'status'=>$plStatus, 'lot_code'=>$shipmentLot]);
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('[mark_shipped] '.$e->getMessage());
  echo json_encode(['ok'=>false,'error'=>'Database error']);
}
