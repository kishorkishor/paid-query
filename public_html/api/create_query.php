<?php
// api/create_query.php — Create a new customer query (only 4 fields required)
// Required: customer_name, phone, product_details, country_id
// Optional: everything else. Handles Clerk auth and file uploads.
// Defaults:
//   - query_type: 'other'
//   - current_team_id: a sensible default team so supervisors can see it

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../logs')) { @mkdir(__DIR__ . '/../logs', 0775, true); }
ini_set('error_log', __DIR__ . '/../logs/_php_errors.log');

require_once __DIR__ . '/lib.php';
cors();
header('Content-Type: application/json');

function jfail($code, $msg){ http_response_code($code); echo json_encode(['success'=>false,'error'=>$msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jfail(405, 'Use POST');

// ---- Auth (Clerk JWT from Authorization: Bearer or __session cookie) ----
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = (stripos($auth,'Bearer ')===0) ? substr($auth,7) : '';
if (!$token && isset($_COOKIE['__session'])) $token = $_COOKIE['__session'];
if (!$token) jfail(401, 'Missing auth token');

try { $claims = verify_clerk_jwt($token); }
catch (Throwable $e) { jfail(401, 'Auth failed'); }

$clerk_user_id = $claims['sub'] ?? null;
if (!$clerk_user_id) jfail(401, 'Bad token');

// ---- Helpers ----
function _val($k){ return trim((string)($_POST[$k] ?? '')); }
function _null_or($s){ $s=trim((string)$s); return ($s==='')? null : $s; }
function generate_query_code(PDO $pdo){
  // Short human code like Q-24X7A9 (unique-ish)
  $base = 'Q-' . strtoupper(bin2hex(random_bytes(3)));
  // Ensure not already used
  $st = $pdo->prepare('SELECT 1 FROM queries WHERE query_code=? LIMIT 1');
  for ($i=0; $i<5; $i++){
    $code = ($i===0) ? $base : ('Q-'.strtoupper(bin2hex(random_bytes(3))));
    $st->execute([$code]);
    if (!$st->fetchColumn()) return $code;
  }
  return $base;
}
function table_exists(PDO $pdo, $name){
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$name]); return (bool)$st->fetchColumn();
}
function col_exists(PDO $pdo, $table, $col){
  $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]); return (bool)$st->fetchColumn();
}
/**
 * Choose a default team id so supervisors can see the new query.
 * Tries a few common names, else MIN(id), else 1 as a last resort.
 */
function get_default_team_id(PDO $pdo){
  try {
    $st = $pdo->prepare("SELECT id FROM teams WHERE name IN ('Regular Sales','Sales','Default') ORDER BY id ASC LIMIT 1");
    if ($st->execute() && ($row = $st->fetch(PDO::FETCH_ASSOC))) return (int)$row['id'];
    $row = $pdo->query("SELECT MIN(id) AS id FROM teams")->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['id'])) return (int)$row['id'];
  } catch (Throwable $e) { /* fall through */ }
  return 1;
}

// ---- Read inputs ----
$customer_name   = _val('customer_name');
$phone           = _val('phone');
$product_details = _val('product_details');
$country_id      = (int)($_POST['country_id'] ?? 0);

// Optional (with defaults)
$query_type      = _null_or($_POST['query_type'] ?? 'other'); // default to "other"
$shipping_mode   = _null_or($_POST['shipping_mode'] ?? null);
$product_name    = _null_or($_POST['product_name'] ?? null);
$product_links   = _null_or($_POST['product_links'] ?? null);
$quantity        = _null_or($_POST['quantity'] ?? null);
$budget          = isset($_POST['budget']) && $_POST['budget']!=='' ? (float)$_POST['budget'] : null;
$label_type      = _null_or($_POST['label_type'] ?? null);
$carton_count    = isset($_POST['carton_count']) && $_POST['carton_count']!=='' ? (int)$_POST['carton_count'] : null;
$cbm             = isset($_POST['cbm']) && $_POST['cbm']!=='' ? (float)$_POST['cbm'] : null;
$address         = _null_or($_POST['address'] ?? null);
$notes           = _null_or($_POST['notes'] ?? null);

// ---- Validate only the four required fields ----
if ($customer_name==='')  jfail(422, 'Customer name is required');
if ($phone==='')          jfail(422, 'Phone is required');
if ($product_details==='')jfail(422, 'Service details are required');
if ($country_id<=0)       jfail(422, 'Country is required');

try {
  $pdo = db();
  $pdo->beginTransaction();

  // Ensure query lands with a team for supervisor visibility
  $current_team_id = get_default_team_id($pdo);

  // Insert query (others optional, may be NULL)
  $query_code = generate_query_code($pdo);
  $ins = $pdo->prepare("
    INSERT INTO queries
      (query_code, clerk_user_id, customer_name, phone, product_details, country_id,
       query_type, shipping_mode, product_name, product_links, quantity, budget,
       label_type, carton_count, cbm, address, notes,
       current_team_id, status, priority, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?,
       ?, ?, ?, ?, ?, ?,
       ?, ?, ?, ?, ?,
       ?, 'new', 'normal', NOW(), NOW())
  ");
  $ins->execute([
    $query_code, $clerk_user_id, $customer_name, $phone, $product_details, $country_id,
    $query_type, $shipping_mode, $product_name, $product_links, $quantity, $budget,
    $label_type, $carton_count, $cbm, $address, $notes,
    $current_team_id
  ]);
  $query_id = (int)$pdo->lastInsertId();

  // ---- Save attachments (optional) ----
  if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $uploadDirFs  = __DIR__ . '/uploads';
    $uploadDirUrl = '/api/uploads';
    if (!is_dir($uploadDirFs)) { @mkdir($uploadDirFs, 0775, true); }

    $rowsToInsert = [];
    $count = count($_FILES['attachments']['name']);
    for ($i=0; $i<$count; $i++) {
      if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
      $tmp  = $_FILES['attachments']['tmp_name'][$i] ?? null;
      $name = $_FILES['attachments']['name'][$i] ?? 'file';
      if (!$tmp || !is_uploaded_file($tmp)) continue;

      $ext  = pathinfo($name, PATHINFO_EXTENSION);
      $safe = bin2hex(random_bytes(8)) . ($ext ? ('.'.preg_replace('/[^a-zA-Z0-9_.-]/','',$ext)) : '');
      $dest = $uploadDirFs . '/' . $safe;
      if (!move_uploaded_file($tmp, $dest)) continue;

      // Try to preserve MIME/size/original_name if table supports it
      $url  = $uploadDirUrl . '/' . $safe;
      $mime = $_FILES['attachments']['type'][$i] ?? null;
      $size = (int)($_FILES['attachments']['size'][$i] ?? 0);
      $rowsToInsert[] = ['path'=>$url, 'mime'=>$mime, 'size'=>$size, 'original_name'=>$name];
    }

    if ($rowsToInsert) {
      // Prefer 'attachments' table if present, else try 'query_attachments'
      $table = table_exists($pdo, 'attachments') ? 'attachments' : (table_exists($pdo,'query_attachments') ? 'query_attachments' : null);
      if ($table) {
        $hasOriginal = col_exists($pdo, $table, 'original_name');
        $hasMime     = col_exists($pdo, $table, 'mime');
        $hasSize     = col_exists($pdo, $table, 'size');

        foreach ($rowsToInsert as $row) {
          $cols = ['query_id','path'];
          $vals = [$query_id, $row['path']];
          if ($hasOriginal){ $cols[]='original_name'; $vals[]=$row['original_name']; }
          if ($hasMime){     $cols[]='mime';          $vals[]=$row['mime']; }
          if ($hasSize){     $cols[]='size';          $vals[]=$row['size']; }
          $cols[]='created_at'; $vals[] = date('Y-m-d H:i:s');

          $sql = 'INSERT INTO '.$table.' ('.implode(',', $cols).') VALUES ('.rtrim(str_repeat('?,', count($vals)),',').')';
          $st = $pdo->prepare($sql);
          $st->execute($vals);
        }
      }
    }
  }

  $pdo->commit();

  echo json_encode([
    'success'=>true,
    'query_id'=>$query_id,
    'query_code'=>$query_code,
    'current_team_id'=>$current_team_id,
    'query_type'=>$query_type ?: 'other'
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('create_query error: '.$e->getMessage());
  jfail(500, 'Server error');
}
