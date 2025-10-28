<?php
// Return countries as JSON for the customer form dropdown
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

header('Content-Type: application/json');

require_once __DIR__ . '/lib.php';

try {
  $pdo = db();
  $st = $pdo->query("SELECT id, name FROM countries ORDER BY name ASC");
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true, 'countries'=>$rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Failed to load countries']);
}
