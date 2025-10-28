<?php
// app/team_supervisor_negotiation.php — Supervisor negotiation review & actions
// Mirrors query_supervisor.php include style to avoid db() redeclarations.

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // supervisors only

// ===== Debug toggles (keep display off in prod) =====
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n){ return '$' . number_format((float)$n, 2, '.', ''); }

$err  = '';
$info = '';

try {
  // ---- input
  $qid    = (int)($_GET['id'] ?? 0);
  $teamId = (int)($_GET['team_id'] ?? 0);
  if ($qid <= 0) { header('Location: /app/team_supervisor.php?team_id='.$teamId); exit; }

  // ---- load query (brings all pricing columns we need)
  $st = $pdo->prepare("
    SELECT q.*,
           t.name AS team_name,
           c.name AS country_name,
           au.name AS assigned_name, au.email AS assigned_email
      FROM queries q
      LEFT JOIN teams t ON t.id = q.current_team_id
      LEFT JOIN countries c ON c.id = q.country_id
      LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
     WHERE q.id = ?
     LIMIT 1
  ");
  $st->execute([$qid]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) { header('Location: /app/team_supervisor.php?team_id='.$teamId); exit; }

  // normalize query type
  $qtRaw = strtolower(trim((string)($q['query_type'] ?? '')));
  $qt = in_array($qtRaw, ['sourcing+shipping', 'shipping+sourcing'], true) ? 'both' : $qtRaw;

  // ---- load messages (kept for history/thread)
  $msg = $pdo->prepare("
    SELECT m.*, a.email AS sender_email, a.name AS admin_name
      FROM messages m
 LEFT JOIN admin_users a ON a.id = m.sender_admin_id
     WHERE m.query_id = ?
  ORDER BY m.id ASC");
  $msg->execute([$qid]);
  $messages = $msg->fetchAll(PDO::FETCH_ASSOC);

  // ---- actions
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $remark = trim($_POST['remark'] ?? '');

    // Helper to update submitted prices only for provided values
    $applySubmitted = function($product, $shipping) use ($pdo, $qid){
      $sql = "UPDATE queries SET updated_at = NOW(), status='price_approved'";
      $p = [];
      if ($product !== '' && is_numeric($product)) {
        $sql .= ", submitted_price = ?";
        $p[] = number_format((float)$product, 2, '.', '');
      }
      if ($shipping !== '' && is_numeric($shipping)) {
        $sql .= ", submitted_ship_price = ?";
        $p[] = number_format((float)$shipping, 2, '.', '');
      }
      $sql .= " WHERE id = ?";
      $p[] = $qid;
      $s = $pdo->prepare($sql);
      $s->execute($p);
    };

    if ($action === 'forward') {
      // Reassign back to the previous agent (last_assigned_admin_user_id), set status=assigned,
      // and move current assignee into last_assigned_admin_user_id for breadcrumbing.
      $prevAgentId    = (int)($q['last_assigned_admin_user_id'] ?? 0);
      $currentAgentId = (int)($q['assigned_admin_user_id'] ?? 0);

      // Resolve agent names for logging
      $nameById = function($id) use ($pdo){
        if (!$id) return null;
        $s = $pdo->prepare("SELECT name FROM admin_users WHERE id=? LIMIT 1");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? (string)$r['name'] : null;
      };
      $prevName    = $nameById($prevAgentId) ?: ('#'.$prevAgentId);
      $currentName = $nameById($currentAgentId) ?: ($currentAgentId ? '#'.$currentAgentId : '—');

      if ($prevAgentId) {
        // swap breadcrumb and assign back
        $pdo->prepare("
          UPDATE queries
             SET last_assigned_admin_user_id = ?,
                 assigned_admin_user_id      = ?,
                 status = 'assigned',
                 updated_at = NOW()
           WHERE id = ?
        ")->execute([$currentAgentId ?: $prevAgentId, $prevAgentId, $qid]);

        $note = "Supervisor forwarded negotiation to previous agent ({$prevName}).";
      } else {
        // no previous; keep current assignee but mark assigned
        $pdo->prepare("
          UPDATE queries
             SET status='assigned',
                 updated_at=NOW()
           WHERE id=?
        ")->execute([$qid]);

        $note = "Supervisor attempted to forward to previous agent, but none recorded. Kept current assignee ({$currentName}).";
      }

      if ($remark !== '') { $note .= " Note: ".$remark; }

      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$qid, $me, $note]);

      // Optional audit log
      $meta = json_encode([
        'from'   => $currentAgentId,
        'to'     => $prevAgentId ?: $currentAgentId,
        'reason' => 'negotiation_forward'
      ], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("
        INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
        VALUES ('query', ?, ?, 'forwarded_to_previous_agent', ?, NOW())
      ")->execute([$qid, $me, $meta]);

      header("Location: /app/team_supervisor.php?team_id=".$teamId); exit;

    } elseif ($action === 'counter') {
      // Supervisor counter — accept product and/or shipping based on query type
      $pp = trim($_POST['product_price'] ?? '');
      $sp = trim($_POST['ship_price'] ?? '');

      // validate by type
      $vErr = '';
      if ($qt === 'sourcing' && ($pp === '' || !is_numeric($pp))) $vErr = 'Please enter a valid product price.';
      if ($qt === 'shipping' && ($sp === '' || !is_numeric($sp))) $vErr = 'Please enter a valid shipping price.';
      if ($qt === 'both' && ($pp === '' && $sp === '')) $vErr = 'Provide at least one of product or shipping price.';

      if ($vErr !== '') {
        $err = $vErr;
      } else {
        // Build message to customer
        $pieces = [];
        if ($pp !== '' && is_numeric($pp)) $pieces[] = "product: ".money_fmt($pp);
        if ($sp !== '' && is_numeric($sp)) $pieces[] = "shipping: ".money_fmt($sp);
        $body = "Counter offer — " . implode(' | ', $pieces) . ($remark !== '' ? ". ".$remark : '');

        // 1) outbound message
        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'outbound', 'message', ?, NOW())
        ")->execute([$qid, $me, $body]);

        // 2) update DB: submitted_price / submitted_ship_price
        $applySubmitted($pp, $sp);

        // 3) internal note
        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'internal', 'note', ?, NOW())
        ")->execute([$qid, $me, "Supervisor sent counter offer."]);

        header("Location: /app/team_supervisor.php?team_id=".$teamId); exit;
      }

    } elseif ($action === 'final') {
      // Supervisor final — accept product and/or shipping based on query type
      $pp = trim($_POST['product_price'] ?? '');
      $sp = trim($_POST['ship_price'] ?? '');

      // validate by type (at least one for 'both')
      $vErr = '';
      if ($qt === 'sourcing' && ($pp === '' || !is_numeric($pp))) $vErr = 'Please enter a valid product price.';
      if ($qt === 'shipping' && ($sp === '' || !is_numeric($sp))) $vErr = 'Please enter a valid shipping price.';
      if ($qt === 'both' && ($pp === '' && $sp === '')) $vErr = 'Provide at least one of product or shipping price.';

      if ($vErr !== '') {
        $err = $vErr;
      } else {
        // Build message
        $pieces = [];
        if ($pp !== '' && is_numeric($pp)) $pieces[] = "product: ".money_fmt($pp);
        if ($sp !== '' && is_numeric($sp)) $pieces[] = "shipping: ".money_fmt($sp);
        $msg = "Final price — " . implode(' | ', $pieces);
        $body = $msg . ($remark !== '' ? ". ".$remark : "");

        // 1) outbound message
        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'outbound', 'message', ?, NOW())
        ")->execute([$qid, $me, $body]);

        // 2) update DB: submitted_price / submitted_ship_price
        $applySubmitted($pp, $sp);

        // 3) internal note
        $pdo->prepare("
          INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
          VALUES (?, ?, 'internal', 'note', ?, NOW())
        ")->execute([$qid, $me, "Supervisor sent FINAL price."]);

        header("Location: /app/team_supervisor.php?team_id=".$teamId); exit;
      }
    }
  }

  // ---- attachments (portable)
  $att = $pdo->prepare("SELECT * FROM query_attachments WHERE query_id=? ORDER BY id ASC");
  $att->execute([$qid]);
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC);

  // helpers for attachment display
  function att_name(array $a){
    foreach (['file_name','filename','name','original_name','title'] as $k) if (!empty($a[$k])) return (string)$a[$k];
    foreach (['file_path','path','url','file','stored_name'] as $k) if (!empty($a[$k])) return basename((string)$a[$k]);
    return 'attachment';
  }
  function att_href(array $a){
    foreach (['file_path','path','url','file'] as $k) if (!empty($a[$k])) return (string)$a[$k];
    if (!empty($a['stored_name'])) return '/uploads/'.ltrim($a['stored_name'],'/');
    return '#';
  }

} catch (Throwable $ex) {
  $err = 'Server error. Details logged.';
  error_log('[team_supervisor_negotiation] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
}

$title = "Supervisor • Negotiation Review";
ob_start();
?>
<h2>Negotiation Review — Query #<?= isset($q['id'])?(int)$q['id']:0 ?> <?= isset($q['query_code'])?'('.e($q['query_code']).')':'' ?></h2>

<?php if ($err): ?>
  <div style="padding:10px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:8px;margin-bottom:10px">
    <?= e($err) ?>
  </div>
<?php elseif ($info): ?>
  <div style="padding:10px;border:1px solid #b7eb8f;background:#f6ffed;color:#135200;border-radius:8px;margin-bottom:10px">
    <?= e($info) ?>
  </div>
<?php endif; ?>

<?php if (!empty($q)): ?>
<div style="display:grid;grid-template-columns:1.2fr .8fr;gap:16px">
  <div>
    <div class="card">
      <h3>Summary</h3>
      <div><strong>Status:</strong> <?= e($q['status'] ?? '') ?></div>
      <div><strong>Priority:</strong> <?= e($q['priority'] ?? '') ?></div>
      <div><strong>Team:</strong> <?= e($q['team_name'] ?? '-') ?></div>
      <div><strong>Assigned To:</strong> <?= e($q['assigned_name'] ?? '-') ?> <?= !empty($q['assigned_email'])?('('.e($q['assigned_email']).')'):'' ?></div>

      <?php
        // Current offer/prices directly from DB (submitted_*). Show whichever are available.
        $sp = $q['submitted_price'] !== null && $q['submitted_price'] !== '' ? $q['submitted_price'] : null;
        $ss = $q['submitted_ship_price'] !== null && $q['submitted_ship_price'] !== '' ? $q['submitted_ship_price'] : null;
      ?>
      <?php if ($qt === 'both' && ($sp !== null || $ss !== null)): ?>
        <div><strong>Current Offer:</strong>
          <?php if ($sp !== null): ?>
            <span style="font-weight:700;margin-left:6px">Product <?= money_fmt($sp) ?></span>
          <?php endif; ?>
          <?php if ($ss !== null): ?>
            <span style="font-weight:700;margin-left:12px">Shipping <?= money_fmt($ss) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($qt === 'sourcing' && $sp !== null): ?>
        <div><strong>Current Offer:</strong> <span style="font-weight:700"><?= money_fmt($sp) ?></span></div>
      <?php elseif ($qt === 'shipping' && $ss !== null): ?>
        <div><strong>Current Offer:</strong> <span style="font-weight:700"><?= money_fmt($ss) ?></span></div>
      <?php endif; ?>

      <?php if (isset($q['approved_price']) && $q['approved_price'] !== '' && is_numeric($q['approved_price'])): ?>
        <div style="margin-top:6px"><strong>Legacy Approved Price:</strong>
          <span style="font-weight:700"><?= money_fmt($q['approved_price']) ?></span>
        </div>
      <?php endif; ?>

      <div><strong>Created:</strong> <?= e($q['created_at'] ?? '') ?></div>
    </div>

    <div class="card">
      <h3>Customer</h3>
      <div><strong>Name:</strong> <?= e($q['customer_name'] ?? '') ?></div>
      <div><strong>Phone:</strong> <?= e($q['phone'] ?? '') ?></div>
      <div><strong>Email:</strong> <?= e($q['email'] ?? '') ?></div>
      <div><strong>Country:</strong> <?= e($q['country_name'] ?? '-') ?></div>
      <div><strong>Address:</strong> <?= nl2br(e($q['address'] ?? '')) ?></div>
    </div>

    <div class="card">
      <h3>Query</h3>
      <div><strong>Type:</strong> <?= e($q['query_type'] ?? '') ?></div>
      <div><strong>Shipping Mode:</strong> <?= e($q['shipping_mode'] ?? '') ?></div>
      <div><strong>Product:</strong> <?= e($q['product_name'] ?? '') ?></div>
      <div><strong>Details:</strong><br><?= nl2br(e($q['product_details'] ?? '')) ?></div>
      <?php if (!empty($q['product_links'])): ?>
        <div><strong>Links:</strong><br>
          <?php
            $rawLinks = array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string)($q['product_links'] ?? ''))));
            if (!$rawLinks) echo '<em>-</em>';
            else foreach ($rawLinks as $l) {
              $label = e($l);
              $u = preg_match('~^https?://~i', $l) ? $l : 'http://'.$l;
              echo '<div><a href="'.e($u).'" target="_blank" rel="noopener">'.$label.'</a></div>';
            }
          ?>
        </div>
      <?php endif; ?>
      <div><strong>Quantity:</strong> <?= e($q['quantity'] ?? '') ?> &nbsp; <strong>Budget:</strong> <?= e($q['budget'] ?? '') ?></div>
      <div><strong>Cartons:</strong> <?= e($q['carton_count'] ?? '') ?> &nbsp; <strong>CBM:</strong> <?= e($q['cbm'] ?? '') ?> &nbsp; <strong>Label:</strong> <?= e($q['label_type'] ?? '') ?></div>
      <div><strong>Notes:</strong><br><?= nl2br(e($q['notes'] ?? '')) ?></div>
    </div>

    <div class="card">
      <h3>Customer Negotiation</h3>
      <?php
        $dp = $q['desired_product_price'] !== null && $q['desired_product_price'] !== '' ? $q['desired_product_price'] : null;
        $ds = $q['desired_shipping_price'] !== null && $q['desired_shipping_price'] !== '' ? $q['desired_shipping_price'] : null;
      ?>
      <?php if ($qt === 'both' && ($dp !== null || $ds !== null)): ?>
        <div style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc">
          <?php if ($dp !== null): ?>
            <div style="margin-bottom:6px"><strong>Desired product:</strong> <?= money_fmt($dp) ?></div>
          <?php endif; ?>
          <?php if ($ds !== null): ?>
            <div><strong>Desired shipping:</strong> <?= money_fmt($ds) ?></div>
          <?php endif; ?>
        </div>
      <?php elseif ($qt === 'sourcing' && $dp !== null): ?>
        <div style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc">
          <strong>Desired price:</strong> <?= money_fmt($dp) ?>
        </div>
      <?php elseif ($qt === 'shipping' && $ds !== null): ?>
        <div style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc">
          <strong>Desired shipping price:</strong> <?= money_fmt($ds) ?>
        </div>
      <?php else: ?>
        <em>No desired prices recorded.</em>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Approved / Offered Price</h3>
      <?php if ($qt === 'both' && ($sp !== null || $ss !== null)): ?>
        <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb">
          <?php if ($sp !== null): ?>
            <div style="font-size:1.05rem;margin-bottom:6px"><strong>Product:</strong> <?= money_fmt($sp) ?></div>
          <?php endif; ?>
          <?php if ($ss !== null): ?>
            <div style="font-size:1.05rem"><strong>Shipping:</strong> <?= money_fmt($ss) ?></div>
          <?php endif; ?>
        </div>
      <?php elseif ($qt === 'sourcing' && $sp !== null): ?>
        <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb">
          <strong>Product:</strong> <?= money_fmt($sp) ?>
        </div>
      <?php elseif ($qt === 'shipping' && $ss !== null): ?>
        <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb">
          <strong>Shipping:</strong> <?= money_fmt($ss) ?>
        </div>
      <?php else: ?>
        <em>No current offer recorded.</em>
      <?php endif; ?>
    </div>

    <div class="card" id="messages">
      <h3>Message Thread</h3>
      <?php if (empty($messages)): ?>
        <em>No messages yet.</em>
      <?php else: foreach ($messages as $m): ?>
        <div style="margin-bottom:10px">
          <div class="muted">
            <?= e($m['created_at']) ?>
            — <?= e($m['direction']) ?>/<?= e($m['medium']) ?>
            <?php if(!empty($m['admin_name'])): ?> — <?= e($m['admin_name']) ?><?php endif; ?>
          </div>
          <div style="white-space:pre-wrap;"><?= e($m['body']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div>
    <div class="card" style="position:sticky;top:12px">
      <h3>Supervisor Actions</h3>

      <form method="post" style="margin-bottom:14px">
        <input type="hidden" name="action" value="forward">
        <label>Remarks to agent (internal)</label>
        <textarea name="remark" rows="3" placeholder="Any instruction for the agent"></textarea>
        <div style="margin-top:6px"><button class="btn" type="submit">Forward to Agent</button></div>
        <div class="muted" style="margin-top:6px">This will reassign the query to the <strong>previous agent</strong> and set status to <strong>assigned</strong>.</div>
      </form>

      <!-- COUNTER: fields shown based on query type -->
      <form method="post" style="margin-bottom:14px">
        <input type="hidden" name="action" value="counter">
        <?php if ($qt === 'sourcing'): ?>
          <label>Counter product price (USD)</label>
          <input type="number" step="0.01" min="0" name="product_price" required>
        <?php elseif ($qt === 'shipping'): ?>
          <label>Counter shipping price (USD)</label>
          <input type="number" step="0.01" min="0" name="ship_price" required>
        <?php else: // both ?>
          <label>Counter product price (USD) — optional</label>
          <input type="number" step="0.01" min="0" name="product_price">
          <label style="margin-top:6px">Counter shipping price (USD) — optional</label>
          <input type="number" step="0.01" min="0" name="ship_price">
        <?php endif; ?>
        <label style="margin-top:6px">Remarks to customer (optional)</label>
        <textarea name="remark" rows="3" placeholder="Explain the counter offer"></textarea>
        <div style="margin-top:6px"><button class="btn" type="submit">Send Counter Price</button></div>
      </form>

      <!-- FINAL: fields shown based on query type -->
      <form method="post">
        <input type="hidden" name="action" value="final">
        <?php if ($qt === 'sourcing'): ?>
          <label>Final product price (USD)</label>
          <input type="number" step="0.01" min="0" name="product_price" required>
        <?php elseif ($qt === 'shipping'): ?>
          <label>Final shipping price (USD)</label>
          <input type="number" step="0.01" min="0" name="ship_price" required>
        <?php else: // both ?>
          <label>Final product price (USD) — optional</label>
          <input type="number" step="0.01" min="0" name="product_price">
          <label style="margin-top:6px">Final shipping price (USD) — optional</label>
          <input type="number" step="0.01" min="0" name="ship_price">
        <?php endif; ?>
        <label style="margin-top:6px">Message to customer</label>
        <textarea name="remark" rows="3" placeholder="e.g., This is our best and final price."></textarea>
        <div style="margin-top:6px"><button class="btn" type="submit">Send Final Price</button></div>
      </form>

      <p class="muted" style="margin-top:8px"><a href="/app/team_supervisor.php?team_id=<?= (int)$teamId ?>">&larr; Back to Dashboard</a></p>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
.card{background:#fff;border:1px solid #eee;border-radius:12px;padding:14px;margin-bottom:14px}
h3{margin:0 0 8px}
.muted{color:#6b7280;font-size:.9rem;margin-left:4px}
.btn{background:#0f172a;color:#fff;border:0;border-radius:8px;padding:.5rem .8rem;cursor:pointer}
textarea,input{width:100%;padding:.6rem;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
</style>

<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
