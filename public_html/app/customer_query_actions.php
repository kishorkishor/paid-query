<?php
// /public_html/app/customer_query_actions.php
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/../_php_errors.log');

require_once __DIR__ . '/../api/lib.php';
$pdo = db();

$qid   = (int)($_GET['id'] ?? 0);
$token = $_GET['t'] ?? '';
if ($qid <= 0) { http_response_code(400); exit('Bad ID'); }

// verify customer token (same as in customer/query.php)
try {
  $claims = verify_clerk_jwt($token);
  $uid = $claims['sub'] ?? null;
} catch (Throwable $e) {
  http_response_code(401); exit('Invalid token');
}
if (!$uid) { http_response_code(401); exit('Invalid token'); }

// ensure the query belongs to this customer
$st = $pdo->prepare("SELECT * FROM queries WHERE id=? AND clerk_user_id=? LIMIT 1");
$st->execute([$qid, $uid]);
$query = $st->fetch(PDO::FETCH_ASSOC);
if (!$query) { http_response_code(403); exit('No access'); }

$action = $_POST['action'] ?? '';
if (!$action) {
  header("Location: /customer/query.php?id=$qid&t=".urlencode($token));
  exit;
}

try {
  if ($action === 'close') {

    $pdo->prepare("UPDATE queries SET status='closed', updated_at=NOW() WHERE id=?")->execute([$qid]);
    $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                   VALUES (?, 'internal','note','Customer closed the query.', NOW())")->execute([$qid]);

    header("Location: /customer/query.php?id=$qid&t=".urlencode($token)."&ok=1");
    exit;

  } elseif ($action === 'reject') {

    $pdo->prepare("UPDATE queries SET status='price_rejected', updated_at=NOW() WHERE id=?")->execute([$qid]);
    $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                   VALUES (?, 'internal','note','Customer rejected the approved price.', NOW())")->execute([$qid]);

    header("Location: /customer/query.php?id=$qid&t=".urlencode($token)."&ok=1");
    exit;

  } elseif ($action === 'approve_order') {

    header("Location: /customer/order_checkout.php?query_id=$qid&t=".urlencode($token));
    exit;

  } elseif ($action === 'negotiate') {

    // Read proposed price and note from the modal
    $priceRaw = trim($_POST['desired_price'] ?? '');
    $noteRaw  = trim($_POST['desired_note'] ?? '');

    // Normalize price to 2 decimals if numeric
    $priceTxt = '';
    if ($priceRaw !== '' && is_numeric($priceRaw)) {
      $priceTxt = number_format((float)$priceRaw, 2, '.', '');
    }

    // Build a single message body that reads naturally in the thread
    $parts = [];
    $parts[] = "Negotiation request:";
    if ($priceTxt !== '')   { $parts[] = "Proposed price: \${$priceTxt}."; }
    if ($noteRaw !== '')    { $parts[] = "Remarks: " . $noteRaw; }
    $body = trim(implode(' ', $parts));
    if ($body === 'Negotiation request:') { $body = 'Negotiation request.'; }

    // 1) Insert as a CUSTOMER message so it appears in the thread "from customer"
    $pdo->prepare("
      INSERT INTO messages (query_id, direction, medium, body, sender_clerk_user_id, created_at)
      VALUES (?, 'inbound', 'portal', ?, ?, NOW())
    ")->execute([$qid, $body, $uid]);

    // 2) Update status for supervisor to pick up
    $pdo->prepare("UPDATE queries SET status='negotiation_pending', updated_at=NOW() WHERE id=?")->execute([$qid]);

    // 3) Optional: route to a country team supervisor (uncomment and set ID)
    // $supervisorTeamId = 123;
    // $pdo->prepare("UPDATE queries SET current_team_id=? WHERE id=?")->execute([$supervisorTeamId, $qid]);

    header("Location: /customer/query.php?id=$qid&t=".urlencode($token)."&ok=1");
    exit;

  } else {
    header("Location: /customer/query.php?id=$qid&t=".urlencode($token)."&err=1");
    exit;
  }

} catch (Throwable $e) {
  error_log('customer_query_actions error: '.$e->getMessage());
  header("Location: /customer/query.php?id=$qid&t=".urlencode($token)."&err=1");
  exit;
}
