<?php
// /app/_diag/db_ping.php  (temporary diagnostics)
// Reads your existing db() helper from config.php to avoid any re-declare issues.
error_reporting(E_ALL);
ini_set('display_errors','1');

$root = __DIR__ . '/..';   // -> /app
require_once $root . '/config.php';   // must define function db()
$info = ['ok' => false, 'errors' => [], 'checks' => []];

try {
  if (!function_exists('db')) {
    throw new RuntimeException('db() helper is missing. Check /app/config.php is loaded.');
  }
  $pdo = db();

  // 1) Simple ping
  $v = $pdo->query('SELECT 1')->fetchColumn();
  $info['checks']['select_1'] = ($v == 1) ? 'OK' : 'FAILED';

  // 2) Server/version
  $srv = $pdo->query('SELECT DATABASE() db, VERSION() ver')->fetch(PDO::FETCH_ASSOC);
  $info['checks']['server'] = $srv;

  // 3) Table existence
  $hasCartons = $pdo->query("SHOW TABLES LIKE 'inbound_cartons'")->fetchColumn();
  $info['checks']['has_inbound_cartons'] = (bool)$hasCartons ? 'YES' : 'NO';

  // 4) Row counts (read-only)
  if ($hasCartons) {
    $info['checks']['inbound_cartons_total'] = (int)$pdo->query("SELECT COUNT(*) FROM inbound_cartons")->fetchColumn();
    $info['checks']['queued_delivery_status'] = (int)$pdo->query("
      SELECT COUNT(*) FROM inbound_cartons
      WHERE LOWER(TRIM(delivery_status)) = 'queued'
    ")->fetchColumn();
    $info['checks']['bd_status_preparing'] = (int)$pdo->query("
      SELECT COUNT(*) FROM inbound_cartons
      WHERE LOWER(TRIM(bd_delivery_status)) IN ('preparing for delivery','preperaing for delivery')
    ")->fetchColumn();
  }

  $info['ok'] = true;
} catch (Throwable $e) {
  $info['ok'] = false;
  $info['errors'][] = $e->getMessage();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($info, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
