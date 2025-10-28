<?php
require_once __DIR__.'/../app/auth.php';
require_login();
require_perm('bd_inbound_access');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);
$lotCode = trim($_POST['lot_code'] ?? '');
if ($lotCode===''){ echo "lot_code required"; exit; }

try {
  $st = $pdo->prepare("SELECT id FROM shipment_lots WHERE lot_code=? LIMIT 1");
  $st->execute([$lotCode]);
  $lotId = (int)($st->fetchColumn() ?: 0);
  if(!$lotId){ echo "Lot not found"; exit; }

  // check available columns
  $cols = $pdo->query("SHOW COLUMNS FROM shipment_lots")->fetchAll(PDO::FETCH_COLUMN);
  $statusCol  = in_array('bd_status', $cols, true) ? 'bd_status' : (in_array('status', $cols, true) ? 'status' : null);
  $clearedCol = in_array('custom_cleared_at', $cols, true) ? 'custom_cleared_at' : (in_array('cleared_at', $cols, true) ? 'cleared_at' : null);
  if($statusCol)  $pdo->prepare("UPDATE shipment_lots SET $statusCol='custom_cleared' WHERE id=?")->execute([$lotId]);
  if($clearedCol) $pdo->prepare("UPDATE shipment_lots SET $clearedCol=NOW() WHERE id=?")->execute([$lotId]);

  // Redirect back to lot page
  header("Location: /app/bd_lot.php?lot=".urlencode($lotCode));
  exit;
} catch (Throwable $e) {
  error_log($e->getMessage());
  http_response_code(500);
  echo "Database error: ".$e->getMessage();
  exit;
}
