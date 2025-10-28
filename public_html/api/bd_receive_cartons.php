<?php
// /api/bd_receive_carton.php — save ONE carton's BD weight/status and (if applicable) close the lot
require_once __DIR__ . '/../app/auth.php';
require_login();
require_perm('bd_inbound_access');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../app/_php_errors.log');

header('Content-Type: application/json');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

// Accept JSON body or classic form-POST
$raw = file_get_contents('php://input');
$in  = $raw ? json_decode($raw, true) : $_POST;

$lotCode  = trim($in['lot_code'] ?? '');
$cartonId = (int)($in['carton_id'] ?? 0);
$weight   = (isset($in['weight']) && $in['weight'] !== '') ? (float)$in['weight'] : null;
$status   = strtolower(trim($in['status'] ?? 'pending'));
if (!in_array($status, ['pending','received','missing'], true)) {
  $status = 'pending';
}

if ($lotCode === '' || $cartonId <= 0) {
  echo json_encode(['ok'=>false,'error'=>'lot_code and carton_id required']); exit;
}

try {
  // 1) Resolve lot id from code
  $st = $pdo->prepare("SELECT id FROM shipment_lots WHERE lot_code=? LIMIT 1");
  $st->execute([$lotCode]);
  $lotId = (int)$st->fetchColumn();
  if (!$lotId) {
    echo json_encode(['ok'=>false,'error'=>'Lot not found']); exit;
  }

  // 2) Ensure carton belongs to this lot
  $chk = $pdo->prepare("SELECT 1 FROM shipment_lot_cartons WHERE lot_id=? AND carton_id=? LIMIT 1");
  $chk->execute([$lotId, $cartonId]);
  if (!$chk->fetchColumn()) {
    echo json_encode(['ok'=>false,'error'=>'Carton not in this lot']); exit;
  }

  // 3) Detect columns available in inbound_cartons (schema-safe)
  $cartonCols = $pdo->query("SHOW COLUMNS FROM inbound_cartons")->fetchAll(PDO::FETCH_COLUMN);
  $has_bd_w = in_array('bd_rechecked_weight_kg', $cartonCols, true);
  $has_bd_s = in_array('bd_recheck_status', $cartonCols, true);

  if (!$has_bd_w && !$has_bd_s) {
    // Nothing to write — tell caller clearly so you can add columns
    echo json_encode([
      'ok'    => false,
      'error' => "Schema missing: add columns bd_rechecked_weight_kg (DECIMAL(10,3) NULL) and bd_recheck_status (ENUM('pending','received','missing') DEFAULT 'pending') to inbound_cartons."
    ]);
    exit;
  }

  // 4) Update the single carton
  if ($has_bd_w && $has_bd_s) {
    $u = $pdo->prepare("UPDATE inbound_cartons SET bd_rechecked_weight_kg = :w, bd_recheck_status = :s WHERE id=:id");
    $u->execute([':w'=>$weight, ':s'=>$status, ':id'=>$cartonId]);
  } elseif ($has_bd_w) {
    $u = $pdo->prepare("UPDATE inbound_cartons SET bd_rechecked_weight_kg = :w WHERE id=:id");
    $u->execute([':w'=>$weight, ':id'=>$cartonId]);
  } else { // only bd_recheck_status exists
    $u = $pdo->prepare("UPDATE inbound_cartons SET bd_recheck_status = :s WHERE id=:id");
    $u->execute([':s'=>$status, ':id'=>$cartonId]);
  }

  // 5) If we can read per-carton BD status, check if lot is fully received (no 'pending' left)
  $lotCols = $pdo->query("SHOW COLUMNS FROM shipment_lots")->fetchAll(PDO::FETCH_COLUMN);
  $lotStatusCol = in_array('bd_status', $lotCols, true) ? 'bd_status' : (in_array('status', $lotCols, true) ? 'status' : null);
  $receivedCol  = in_array('bd_received_at', $lotCols, true) ? 'bd_received_at' : (in_array('received_at', $lotCols, true) ? 'received_at' : null);

  $pending = null;
  $lot_status_after = null;

  if ($has_bd_s) {
    $q = $pdo->prepare("
      SELECT COUNT(*)
      FROM shipment_lot_cartons lc
      JOIN inbound_cartons c ON c.id = lc.carton_id
      WHERE lc.lot_id = ? AND COALESCE(c.bd_recheck_status,'pending') = 'pending'
    ");
    $q->execute([$lotId]);
    $pending = (int)$q->fetchColumn();

    if ($pending === 0 && $lotStatusCol) {
      // Mark lot as received in BD and stamp received time (if column exists)
      if ($receivedCol) {
        $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='received_bd', $receivedCol=NOW() WHERE id=?")->execute([$lotId]);
      } else {
        $pdo->prepare("UPDATE shipment_lots SET $lotStatusCol='received_bd' WHERE id=?")->execute([$lotId]);
      }
      $lot_status_after = 'received_bd';
    }
  }

  echo json_encode([
    'ok'         => true,
    'pending'    => $pending,        // null if we can't compute (missing bd_recheck_status)
    'lot_status' => $lot_status_after
  ]);
} catch (Throwable $e) {
  // Log full details and return message so the UI can show it
  error_log("BD_RECEIVE_CARTON ERROR: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine());
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
