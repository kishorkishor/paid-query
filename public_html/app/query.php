<?php
// /public_html/app/query.php
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

session_start();

// ---- AUTH GUARD ----
if (empty($_SESSION['admin']['id'])) {
  header('Location: /app/login.php');
  exit;
}
if (!in_array('view_queries', $_SESSION['perms'] ?? [], true)) {
  http_response_code(403);
  echo 'Forbidden'; exit;
}

require_once __DIR__ . '/../api/lib.php';
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'Bad id'; exit; }

/* ---------- fetch query ---------- */
$qs = $pdo->prepare("
  SELECT q.*, t.name AS team_name
  FROM queries q
  LEFT JOIN teams t ON t.id = q.current_team_id
  WHERE q.id = ?
  LIMIT 1
");
$qs->execute([$id]);
$q = $qs->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); echo 'Query not found'; exit; }

/* ---------- load messages with admin name ---------- */
$ms = $pdo->prepare("
  SELECT m.id, m.direction, m.medium, m.body, m.created_at,
         m.sender_admin_id, m.sender_clerk_user_id,
         au.name AS admin_name
  FROM messages m
  LEFT JOIN admin_users au ON au.id = m.sender_admin_id
  WHERE m.query_id = ?
  ORDER BY m.id ASC
");
$ms->execute([$id]);
$messages = $ms->fetchAll(PDO::FETCH_ASSOC);

/* ---------- priority label ---------- */
$currentPriority = $q['priority'] ?: 'default';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Query #<?= htmlspecialchars($q['query_code'] ?: $q['id']) ?> — Backoffice</title>
<style>
  :root{--ink:#111827;--bg:#f7f7fb;--card:#fff;--muted:#6b7280;--line:#eee}
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:#111}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
  .container{max-width:1000px;margin:30px auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
  .note{color:var(--muted)}
  .pill{padding:10px;border-radius:12px;background:#f6f6ff;border:1px solid #e5e7ff}
  .btn{display:inline-block;padding:.6rem 1rem;border-radius:10px;background:#111827;color:#fff;text-decoration:none;border:none;cursor:pointer}
  label{display:block;margin:.6rem 0 .25rem;font-weight:600}
  input,select,textarea{width:100%;padding:.6rem;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .mb16{margin-bottom:16px}
  .mt8{margin-top:8px}
  .mt16{margin-top:16px}
</style>
</head>
<body>
<header>
  <div><strong>Cosmic Backoffice</strong> — Query</div>
  <div><a class="btn" href="/app/queries.php">List</a></div>
</header>

<div class="container">
  <h3>Query</h3>
  <div class="pill mb16">
    <div><strong>Type:</strong> <?= htmlspecialchars($q['query_type']) ?></div>
    <div><strong>Budget:</strong> <?= htmlspecialchars($q['budget'] ?? '0.00') ?></div>
    <div><strong>Shipping mode:</strong> <?= htmlspecialchars($q['shipping_mode'] ?: 'unknown') ?></div>
  </div>

  <h3>Product details</h3>
  <div class="pill mb16" style="white-space:pre-wrap"><?= htmlspecialchars($q['product_details'] ?: '-') ?></div>

  <h3>Meta</h3>
  <div class="pill mb16">
    <div><strong>Created at:</strong> <?= htmlspecialchars($q['created_at'] ?: '-') ?></div>
    <div><strong>Updated at:</strong> <?= htmlspecialchars($q['updated_at'] ?: '-') ?></div>
    <div><strong>Status:</strong> <?= htmlspecialchars($q['status']) ?></div>
    <div><strong>Team:</strong> <?= htmlspecialchars($q['team_name'] ?: '-') ?></div>
    <div><strong>Priority:</strong> <?= htmlspecialchars($currentPriority) ?></div>
  </div>

  <!-- Forward action is now a simple button that routes to fill.php -->
  <div class="mt8 mb16">
    <a class="btn" href="/app/fill.php?id=<?= (int)$id ?>">Forward</a>
  </div>

  <h3 id="thread">Message thread</h3>
  <div id="threadBox" class="pill mb16">
    <?php if (!$messages): ?>
      <em>No messages yet.</em>
    <?php else: ?>
      <?php foreach ($messages as $msg): ?>
        <?php $who = $msg['sender_clerk_user_id'] ? 'Customer' : ($msg['admin_name'] ?? 'Team'); ?>
        <div style="margin-bottom:10px">
          <div class="note">
            <?= htmlspecialchars($msg['created_at']) ?>
            — <?= htmlspecialchars($who) ?>
            — <?= htmlspecialchars($msg['direction']) ?>/<?= htmlspecialchars($msg['medium']) ?>
          </div>
          <div style="white-space:pre-wrap"><?= htmlspecialchars($msg['body'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Composer -->
  <div class="row">
    <div>
      <label>Kind</label>
      <select id="msgKind">
        <option value="internal">Internal note (hidden)</option>
        <option value="outbound">Message to customer</option>
        <option value="whatsapp">Contacted via WhatsApp</option>
        <option value="email">Contacted via Email</option>
        <option value="voice">Contacted via Voice Call</option>
        <option value="other">Contacted via Other</option>
      </select>
    </div>
    <div>
      <label>&nbsp;</label>
      <button id="btnAddMsg" class="btn" type="button">Add</button>
    </div>
  </div>
  <div class="mt8">
    <label>Text</label>
    <textarea id="msgBody" rows="2" placeholder="Write a note or message..."></textarea>
  </div>

  <div class="mt16">
    <a class="btn" href="/app/queries.php">← Back to list</a>
  </div>
</div>

<script>
  const QID = <?= (int)$id ?>;

  function kindToDirectionMedium(kind) {
    if (kind === 'internal') return { direction: 'internal', medium: 'note' };
    if (kind === 'outbound') return { direction: 'outbound', medium: 'message' };
    if (['whatsapp','email','voice','other'].includes(kind)) {
      return { direction: 'outbound', medium: kind };
    }
    return { direction: 'internal', medium: 'note' };
  }

  document.getElementById('btnAddMsg').addEventListener('click', async () => {
    const kind = document.getElementById('msgKind').value;
    const body = document.getElementById('msgBody').value.trim();
    if (!body) { alert('Message cannot be empty'); return; }

    const map  = kindToDirectionMedium(kind);
    const fd = new FormData();
    fd.append('id', QID);
    fd.append('direction', map.direction);
    fd.append('medium', map.medium);
    fd.append('body', body);

    try {
      const res = await fetch('/api/add_admin_message.php', {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      const data = await res.json();
      if (data.ok) {
        document.getElementById('msgBody').value = '';
        location.hash = '#thread';
        location.reload();
      } else {
        alert(data.error || 'Failed to add message');
      }
    } catch (e) {
      alert('Network error');
    }
  });
</script>
</body>
</html>
