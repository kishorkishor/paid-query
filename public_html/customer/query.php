<?php
// customer/query.php — server-rendered details (no JSON/AJAX)

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

// Start very early to prevent stray output issues from includes
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_start();

require_once __DIR__ . '/../api/lib.php';   // << must NOT echo anything

function abort_page($code, $msg) {
  http_response_code($code);
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title>
        <style>body{font-family:system-ui,Arial;margin:40px;color:#111} .box{max-width:680px}</style></head><body>
        <div class="box"><h2>Error</h2><p>'.htmlspecialchars($msg).'</p></div></body></html>';
  exit;
}

// Read params
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tok = $_GET['t'] ?? '';

if ($id <= 0)        abort_page(400, 'Bad query id.');
if (!$tok)           abort_page(401, 'Missing token. Please sign in again.');

// Verify Clerk token (defined in api/lib.php)
try {
  $claims = verify_clerk_jwt($tok);
} catch (Throwable $e) {
  abort_page(401, 'Invalid token: '.$e->getMessage());
}
$uid = $claims['sub'] ?? null; // Clerk user id
if (!$uid) abort_page(401, 'Invalid token (no sub).');

try {
  $pdo = db();

  // Ensure this query belongs to this Clerk user
  $st = $pdo->prepare("
    SELECT q.*, t.name AS team_name
    FROM queries q
    LEFT JOIN teams t ON t.id = q.current_team_id
    WHERE q.id = ? AND q.clerk_user_id = ?
    LIMIT 1
  ");
  $st->execute([$id, $uid]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) abort_page(404, 'Query not found or you do not have access.');

  /* -------------------------------------------------------------
   * Handle customer reply POST (no JS needed)
   * ----------------------------------------------------------- */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_msg') {
    $body = trim($_POST['body'] ?? '');
    if ($body === '') {
      // fall through; we’ll show error below
      $send_error = 'Message cannot be empty.';
    } else {
      $ins = $pdo->prepare("
        INSERT INTO messages (query_id, direction, medium, body, sender_clerk_user_id, created_at)
        VALUES (?, 'inbound', 'portal', ?, ?, NOW())
      ");
      $ins->execute([$id, $body, $uid]);

      // touch query updated_at
      $pdo->prepare("UPDATE queries SET updated_at = NOW() WHERE id = ?")->execute([$id]);

      // Avoid form resubmission
      header('Location: '.$_SERVER['REQUEST_URI']);
      exit;
    }
  }

  /* -------------------------------------------------------------
   * Handle customer NEGOTIATION — store desired prices in DB
   * ----------------------------------------------------------- */
  $neg_error = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'negotiate') {
    // Normalize query type
    $qtRaw = strtolower(trim((string)($q['query_type'] ?? '')));
    $qt = in_array($qtRaw, ['sourcing+shipping','shipping+sourcing'], true) ? 'both' : $qtRaw;

    $desiredProduct = trim($_POST['desired_price'] ?? '');
    $desiredShip    = trim($_POST['desired_ship_price'] ?? '');
    $desiredNote    = trim($_POST['desired_note'] ?? '');

    // Validation per type
    $errs = [];
    if ($qt === 'sourcing' && $desiredProduct === '') { $errs[] = 'Desired product price is required'; }
    if ($qt === 'shipping' && $desiredShip === '')     { $errs[] = 'Desired shipping price is required'; }
    if ($qt === 'both' && $desiredProduct === '' && $desiredShip === '') {
      $errs[] = 'Provide at least one of product or shipping price';
    }

    if ($errs) {
      $neg_error = implode('. ', $errs) . '.';
    } else {
      // Build negotiation message
      $parts = [];
      if ($desiredProduct !== '') { $parts[] = 'product: ' . $desiredProduct; }
      if ($desiredShip    !== '') { $parts[] = 'shipping: ' . $desiredShip; }
      $msg = 'Customer negotiation — desired ' . implode(' | ', $parts);
      if ($desiredNote !== '') { $msg .= '. Note: ' . $desiredNote; }

      // Insert inbound message (customer)
      $pdo->prepare("
        INSERT INTO messages (query_id, direction, medium, body, sender_clerk_user_id, created_at)
        VALUES (?, 'inbound', 'portal', ?, ?, NOW())
      ")->execute([$id, $msg, $uid]);

      // Update only the provided fields; keep others as-is
      $sql = "UPDATE queries SET updated_at = NOW(), status = 'negotiation_pending'";
      $params = [];
      if ($desiredProduct !== '') { $sql .= ", desired_product_price = ?"; $params[] = $desiredProduct; }
      if ($desiredShip    !== '') { $sql .= ", desired_shipping_price = ?"; $params[] = $desiredShip; }
      $sql .= " WHERE id = ? AND clerk_user_id = ?";
      $params[] = $id; $params[] = $uid;

      $u = $pdo->prepare($sql);
      $u->execute($params);

      // Avoid resubmission
      header('Location: '.$_SERVER['REQUEST_URI']);
      exit;
    }
  }

  // Attachments
  $a = $pdo->prepare("
    SELECT id, path, original_name, mime, size, created_at
    FROM query_attachments
    WHERE query_id = ?
    ORDER BY id
  ");
  $a->execute([$id]);
  $attachments = $a->fetchAll(PDO::FETCH_ASSOC);

  // Messages (customer-facing)
  $m = $pdo->prepare("
    SELECT id, direction, medium, body, created_at,
           sender_admin_id, sender_clerk_user_id
    FROM messages
    WHERE query_id = ?
    ORDER BY id ASC
  ");
  $m->execute([$id]);
  $messages = $m->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  abort_page(500, 'Server error: '.$e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Query <?= htmlspecialchars($q['query_code'] ?: '#'.$q['id']) ?> — Cosmic Trading</title>
<style>
  :root{
    --ink:#111827;--bg:#f7f7fb;--card:#fff;--muted:#6b7280;--line:#eee;
    --ok:#16a34a;--ok-soft:#dcfce7;--ok-border:#86efac;
  }
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:#111}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
  .container{max-width:1000px;margin:30px auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
  .note{color:var(--muted)}
  .badge{padding:4px 8px;border-radius:999px;background:#eef;border:1px solid #dde}
  .pill{padding:10px;border-radius:12px;background:#f6f6ff;border:1px solid #e5e7ff}
  .attachments img{max-width:220px;border:1px solid #eee;border-radius:8px;margin:6px 0}
  a.btn{display:inline-block;padding:.6rem 1rem;border-radius:10px;background:#111827;color:#fff;text-decoration:none}
  .msg{margin-bottom:12px}
  .msg .meta{color:#6b7280;font-size:.88rem;margin-bottom:2px}
  .msg.you .bubble{background:#eefcf3;border:1px solid #c7f0d5}
  .msg.team .bubble{background:#f6f7ff;border:1px solid #e8ebff}
  .bubble{padding:8px 10px;border-radius:10px;white-space:pre-wrap}
  textarea{width:100%;border:1px solid #e5e7eb;border-radius:10px;padding:.7rem;background:#fff}
  button{padding:.8rem 1.2rem;border:0;border-radius:12px;background:#111827;color:#fff;cursor:pointer}

  /* Negotiation Modal */
  .modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);display:none;align-items:center;justify-content:center;z-index:50}
  .modal{background:#fff;border:1px solid #e5e7eb;border-radius:14px;max-width:480px;width:90%;padding:16px}
  .modal h4{margin:0 0 8px 0}
  .modal .row{display:grid;grid-template-columns:1fr;gap:10px}
  .modal input,.modal textarea{width:100%;padding:.6rem;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
  .modal .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
  .btnP{background:#0ea5e9}.btnP:hover{background:#0284c7}
  .btnW{background:#f59e0b}.btnW:hover{background:#d97706}
  .btnG{background:#6b7280}.btnG:hover{background:#4b5563}
  .btnD{background:#ef4444}.btnD:hover{background:#dc2626}

  /* Submitted price banner */
  .price-banner{
    margin:10px 0 18px 0; padding:14px 16px; border-radius:12px;
    background:var(--ok-soft); border:1px solid var(--ok-border); color:#065f46;
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
  }
  .price-chip{background:#059669; color:#fff; padding:6px 10px; border-radius:999px; font-weight:700}
  .price-amount{font-size:1.25rem;font-weight:800}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Trading</strong> — Customer</div>
  <div><a class="btn" href="/index.html">Dashboard</a></div>
</header>

<div class="container">
  <p><a href="/index.html" class="btn">← Back</a></p>

  <h2>Query <?= htmlspecialchars($q['query_code'] ?: '#'.$q['id']) ?></h2>
  <p class="note">
    Status <span class="badge"><?= htmlspecialchars($q['status']) ?></span> ·
    Team <strong><?= htmlspecialchars($q['team_name'] ?: '-') ?></strong> ·
    Priority <strong><?= htmlspecialchars($q['priority']) ?></strong> ·
    Type <strong><?= htmlspecialchars($q['query_type']) ?></strong> ·
    Created <strong><?= htmlspecialchars($q['created_at']) ?></strong>
  </p>

  <?php
    // ---- Show submitted price(s) by query type (except while pending price_submitted) ----
    $qtRaw = strtolower(trim((string)($q['query_type'] ?? '')));
    $qt = in_array($qtRaw, ['sourcing+shipping','shipping+sourcing'], true) ? 'both' : $qtRaw;

    $productPrice = isset($q['submitted_price']) && $q['submitted_price'] !== '' ? (float)$q['submitted_price'] : null;
    $shipPrice    = isset($q['submitted_ship_price']) && $q['submitted_ship_price'] !== '' ? (float)$q['submitted_ship_price'] : null;

    $statusNotPending = (($q['status'] ?? '') !== 'price_submitted');

    $shouldShow = $statusNotPending && (
        ($qt === 'both'     && ($productPrice !== null || $shipPrice !== null)) ||
        ($qt === 'sourcing' &&  $productPrice !== null) ||
        ($qt === 'shipping' &&  $shipPrice   !== null)
    );

    if ($shouldShow):
  ?>
    <div class="price-banner" role="status" aria-live="polite">
      <span class="price-chip">Submitted price</span>

      <?php if ($qt === 'both'): ?>
        <span><strong>Product:</strong>
          <span class="price-amount">
            <?= $productPrice !== null ? ('$'.number_format($productPrice, 2, '.', ',')) : '—' ?>
          </span>
        </span>
        <span style="margin-left:12px"><strong>Shipping:</strong>
          <span class="price-amount">
            <?= $shipPrice !== null ? ('$'.number_format($shipPrice, 2, '.', ',')) : '—' ?>
          </span>
        </span>
      <?php elseif ($qt === 'shipping'): ?>
        <span class="price-amount">
          <?= '$'.number_format($shipPrice, 2, '.', ',') ?>
        </span>
        <span class="note">Shipping price</span>
      <?php else: /* sourcing (default) */ ?>
        <span class="price-amount">
          <?= '$'.number_format($productPrice, 2, '.', ',') ?>
        </span>
        <span class="note">Product price</span>
      <?php endif; ?>

      <?php if (!empty($q['currency'])): ?>
        <span class="note">(Currency: <?= htmlspecialchars($q['currency']) ?>)</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h3>Product details</h3>
  <div class="pill"><?= nl2br(htmlspecialchars($q['product_details'] ?: '-')) ?></div>

  <h3>Product links</h3>
  <div class="pill">
    <?php
      $rawLinks = array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)($q['product_links'] ?? ''))));
      if (!$rawLinks) {
        echo '<em>-</em>';
      } else {
        foreach ($rawLinks as $l) {
          $label = htmlspecialchars($l);
          $url = $l;
          if (!preg_match('~^https?://~i', $url)) { $url = 'http://' . $url; }
          $safe = htmlspecialchars($url);
          echo '<div><a href="'.$safe.'" target="_blank" rel="noopener">'.$label.'</a></div>';
        }
      }
    ?>
  </div>

  <h3>Attachments</h3>
  <div class="attachments">
    <?php if (!$attachments) { echo '<em>No attachments</em>'; }
          else foreach ($attachments as $att) {
            $isImg = stripos($att['mime'] ?? '', 'image/') === 0;
            $href = htmlspecialchars($att['path']);
            $name = htmlspecialchars($att['original_name'] ?: basename($att['path']));
            echo '<div>';
            if ($isImg) echo '<img src="'.$href.'" alt="attachment">';
            echo '<div><a href="'.$href.'" target="_blank">'.$name.'</a> <span class="note">'.htmlspecialchars($att['mime'] ?: '').'</span></div>';
            echo '</div>';
          }
    ?>
  </div>

  <h3>Messages</h3>
  <div class="pill">
    <?php
      function contact_line_from_medium($medium) {
        $map = ['whatsapp'=>'WhatsApp','email'=>'Email','voice'=>'Voice Call','other'=>'Other'];
        $label = $map[strtolower($medium)] ?? ucfirst($medium ?: 'Contact');
        return "Our team contacted you via {$label}.";
      }

      $shown = 0;
      foreach ($messages as $msg) {
        if (strtolower($msg['medium']) === 'internal') continue;

        $created = htmlspecialchars($msg['created_at']);

        if ($msg['direction'] === 'inbound' && (string)$msg['sender_clerk_user_id'] === (string)$uid) {
          $text = htmlspecialchars($msg['body'] ?? '');
          if ($text === '') continue;
          echo '<div class="msg you"><div class="meta">'.$created.' — You</div><div class="bubble">'.$text.'</div></div>';
          $shown++;
          continue;
        }

        if ($msg['direction'] === 'outbound') {
          $body = trim((string)$msg['body']);
          if (in_array(strtolower($msg['medium']), ['whatsapp','email','voice','other'], true)) {
            $line = contact_line_from_medium($msg['medium']);
          } else {
            $line = $body !== '' ? $body : contact_line_from_medium($msg['medium']);
          }
          echo '<div class="msg team"><div class="meta">'.$created.' — Team</div><div class="bubble">'.htmlspecialchars($line).'</div></div>';
          $shown++;
          continue;
        }
      }

      if ($shown === 0) echo '<em>No messages yet.</em>';
    ?>
  </div>

  <h3>Send a message</h3>
  <?php if (!empty($send_error)): ?>
    <div class="note" style="color:#b91c1c;margin-bottom:8px;"><?= htmlspecialchars($send_error) ?></div>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:10px;align-items:flex-start;">
    <input type="hidden" name="action" value="send_msg">
    <textarea name="body" rows="2" placeholder="Write your message..." required></textarea>
    <button type="submit">Send</button>
  </form>

  <?php
  if (($q['status'] ?? '') === 'price_approved'):
    $tokSafe   = urlencode($tok);
    $actionUrl = "/app/customer_query_actions.php?id=".(int)$id."&t={$tokSafe}";
  ?>
    <h3>Next steps</h3>

    <?php if (!empty($neg_error)): ?>
      <div class="pill" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;margin-bottom:10px">
        <?= htmlspecialchars($neg_error) ?>
      </div>
    <?php endif; ?>

    <div class="pill" style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="post" action="<?= $actionUrl ?>">
        <input type="hidden" name="action" value="approve_order">
        <button type="submit" class="btnP" style="padding:10px 14px;border:0;border-radius:10px;cursor:pointer;font-weight:600">
          Approve Order
        </button>
      </form>

      <button type="button" class="btnW" onclick="openNegotiate()" style="padding:10px 14px;border:0;border-radius:10px;cursor:pointer;font-weight:600">
        Negotiate Price
      </button>

      <form method="post" action="<?= $actionUrl ?>">
        <input type="hidden" name="action" value="close">
        <button type="submit" class="btnD" style="padding:10px 14px;border:0;border-radius:10px;cursor:pointer;font-weight:600">
          Close Query
        </button>
      </form>
    </div>
    <p class="note" style="margin-top:8px">
      Approving takes you to an order confirmation page to recheck product, amount, address, and quantity and accept the Terms &amp; Conditions.
    </p>

    <?php
      // We already computed $qt above — reuse it here for conditional fields.
    ?>
    <div id="negModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="modal">
        <h4>Propose your price</h4>
        <!-- IMPORTANT: Negotiation posts back to THIS PAGE so we can persist desired prices -->
        <form method="post">
          <input type="hidden" name="action" value="negotiate">
          <div class="row">
            <?php if ($qt === 'both'): ?>
              <div class="note" style="margin-bottom:6px">
                You can negotiate <strong>either or both</strong> of the following prices.
              </div>
              <label>Desired <strong>product</strong> price (USD)
                <input type="number" name="desired_price" step="0.01" min="0" placeholder="e.g., 1750.00">
              </label>
              <label>Desired <strong>shipping</strong> price (USD)
                <input type="number" name="desired_ship_price" step="0.01" min="0" placeholder="e.g., 200.00">
              </label>
            <?php elseif ($qt === 'shipping'): ?>
              <label>Desired <strong>shipping</strong> price (USD)
                <input type="number" name="desired_ship_price" step="0.01" min="0" required>
              </label>
            <?php else: /* sourcing */ ?>
              <label>Desired price (USD)
                <input type="number" name="desired_price" step="0.01" min="0" required>
              </label>
            <?php endif; ?>

            <label>Remarks (optional)
              <textarea name="desired_note" rows="3" placeholder="Add any context, e.g. target budget, delivery constraints..."></textarea>
            </label>
          </div>
          <div class="actions">
            <button type="button" class="btnG" onclick="closeNegotiate()">Cancel</button>
            <button type="submit" class="btnW">Send Negotiation</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
  function openNegotiate(){
    var el = document.getElementById('negModal');
    if (el){ el.style.display = 'flex'; el.setAttribute('aria-hidden','false'); }
  }
  function closeNegotiate(){
    var el = document.getElementById('negModal');
    if (el){ el.style.display = 'none'; el.setAttribute('aria-hidden','true'); }
  }
  (function(){
    var el = document.getElementById('negModal');
    if (!el) return;
    el.addEventListener('click', function(e){
      if (e.target === el) closeNegotiate();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeNegotiate();
    });
  })();
</script>

</body>
</html>
<?php
ob_end_flush();
