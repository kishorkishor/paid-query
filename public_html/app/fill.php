<?php
// app/fill.php — prepare a forward request after fully completing the query details
require_once __DIR__ . '/auth.php';
require_perm('view_queries');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function is_blank($v){ return $v === null || $v === ''; }

// helpers for attachment table detection
function table_exists(PDO $pdo, $name){
  try{
    $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $pdo, $table, $col){
  try{
    $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table,$col]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ return false; }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /app/queries.php'); exit; }

// Load query
$qs = $pdo->prepare("
  SELECT q.*, t.name AS team_name, au.name AS assigned_name
  FROM queries q
  LEFT JOIN teams t ON t.id = q.current_team_id
  LEFT JOIN admin_users au ON au.id = q.assigned_admin_user_id
  WHERE q.id = ?
  LIMIT 1
");
$qs->execute([$id]);
$q = $qs->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); echo 'Query not found'; exit; }

// Allow if I'm assigned OR I'm a supervisor
$isSupervisor = can('assign_team_member');
if (!$isSupervisor && (int)$q['assigned_admin_user_id'] !== $me) {
  http_response_code(403); echo 'Forbidden'; exit;
}

// Dropdown data
$countries  = $pdo->query("SELECT id, name FROM countries ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$teams      = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$priorities = ['default','low','medium','high','urgent'];
$queryTypes = ['sourcing','shipping','both','consultation','other']; // adjust if needed
$shipModes  = ['sea','air','courier','unknown'];

$err = $info = '';
$missing = [];

// ---- Conditional required rules --------------------------------------------
/* Returns the set of required keys based on the query_type */
function required_keys_for($qt){
  // base required fields (business)
  $required = [
    'customer_name','phone','product_details','country_id','product_name',
    'query_type','product_links','quantity','budget','address','notes',
    'shipping_mode','label_type','carton_count','cbm'
  ];

  // apply your rules
  if ($qt === 'sourcing') {
    // shipping_mode, carton_count, cbm, label_type -> optional
    $required = array_diff($required, ['shipping_mode','carton_count','cbm','label_type']);
  } elseif ($qt === 'both') {
    // carton_count, cbm, label_type -> optional
    $required = array_diff($required, ['carton_count','cbm','label_type']);
  } elseif ($qt === 'shipping') {
    // product_links, budget -> optional
    $required = array_diff($required, ['product_links','budget']);
  }
  return array_values($required);
}

$labelMap = [
  'customer_name'=>'Customer name',
  'phone'=>'Phone',
  'email'=>'Email',
  'product_details'=>'Service details',
  'country_id'=>'Country',
  'product_name'=>'Product Name',
  'query_type'=>'Query type',
  'product_links'=>'Product links',
  'quantity'=>'Quantity',
  'budget'=>'Budget (USD)',
  'address'=>'Address',
  'notes'=>'Notes',
  'shipping_mode'=>'Shipping mode',
  'label_type'=>'Label type',
  'carton_count'=>'Carton count',
  'cbm'=>'CBM',
];

// Load existing attachments (from whichever attachment table exists)
$attTable = table_exists($pdo,'attachments') ? 'attachments' : (table_exists($pdo,'query_attachments') ? 'query_attachments' : null);
$attachments = [];
if ($attTable) {
  try{
    $cols = "id, query_id, path";
    if (col_exists($pdo, $attTable, 'original_name')) $cols .= ", original_name";
    if (col_exists($pdo, $attTable, 'mime'))          $cols .= ", mime";
    if (col_exists($pdo, $attTable, 'size'))          $cols .= ", size";
    if (col_exists($pdo, $attTable, 'created_at'))    $cols .= ", created_at";
    $stmt = $pdo->prepare("SELECT $cols FROM {$attTable} WHERE query_id=? ORDER BY id DESC");
    $stmt->execute([$id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }catch(Throwable $e){ /* ignore */ }
}

// Save + request forward
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['request_forward'])) {

  // Collect inputs
  $payload = [
    'customer_name'   => trim($_POST['customer_name'] ?? ''),
    'phone'           => trim($_POST['phone'] ?? ''),
    'email'           => trim($_POST['email'] ?? ''),
    'product_details' => trim($_POST['product_details'] ?? ''),
    'country_id'      => (int)($_POST['country_id'] ?? 0),
    'product_name'    => trim($_POST['product_name'] ?? ''),
    'query_type'      => trim($_POST['query_type'] ?? ''),
    'product_links'   => trim($_POST['product_links'] ?? ''),
    'quantity'        => ($_POST['quantity'] === '' ? '' : (int)$_POST['quantity']),
    'budget'          => ($_POST['budget'] === '' ? '' : (string)$_POST['budget']),
    'address'         => trim($_POST['address'] ?? ''),
    'notes'           => trim($_POST['notes'] ?? ''),
    'shipping_mode'   => trim($_POST['shipping_mode'] ?? ''),
    'label_type'      => trim($_POST['label_type'] ?? ''),
    'carton_count'    => ($_POST['carton_count'] === '' ? '' : (string)$_POST['carton_count']),
    'cbm'             => ($_POST['cbm'] === '' ? '' : (string)$_POST['cbm']),
  ];

  $qt = $payload['query_type'] ?: ($q['query_type'] ?? '');
  $requiredNow = required_keys_for($qt);

  // Validate required values
  foreach ($requiredNow as $k) {
    if ($k === 'country_id') {
      if ((int)$payload[$k] <= 0) $missing[] = $labelMap[$k];
      continue;
    }
    if (is_blank($payload[$k])) {
      $missing[] = $labelMap[$k];
    }
  }

  // Forward target validation
  $forward_team_id = (int)($_POST['forward_team_id'] ?? 0);
  $forward_priority= in_array(($_POST['forward_priority'] ?? ''), $priorities, true)
                     ? $_POST['forward_priority'] : '';
  if ($forward_team_id <= 0) $missing[] = 'Forward team';
  if ($forward_priority === '') $missing[] = 'Forward priority';

  if ($missing) {
    $err = 'Please fill all required fields: '.e(implode(', ', $missing)).'.';
  } else {
    try {
      // Save edits and mark as forwarded
      $up = $pdo->prepare("
        UPDATE queries SET
          customer_name=?, phone=?, email=?,
          product_details=?, country_id=?, product_name=?, query_type=?,
          product_links=?, quantity=?, budget=?, address=?, notes=?,
          shipping_mode=?, label_type=?, carton_count=?, cbm=?,
          status='forwarded',
          updated_at=NOW(),
          forward_request_team_id=?, forward_request_priority=?, forward_request_by=?, forward_request_at=NOW()
        WHERE id=?
      ");
      $up->execute([
        $payload['customer_name'], $payload['phone'], $payload['email'],
        $payload['product_details'], $payload['country_id'], $payload['product_name'], $payload['query_type'],
        $payload['product_links'], $payload['quantity'], $payload['budget'], $payload['address'], $payload['notes'],
        $payload['shipping_mode'], $payload['label_type'], $payload['carton_count'], $payload['cbm'],
        $forward_team_id, $forward_priority, $me, $id
      ]);

      // ---- Attachments (optional) ----
      if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $uploadDirFs  = __DIR__ . '/../api/uploads';
        $uploadDirUrl = '/api/uploads';
        if (!is_dir($uploadDirFs)) { @mkdir($uploadDirFs, 0775, true); }

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

          $url  = $uploadDirUrl . '/' . $safe;
          $mime = $_FILES['attachments']['type'][$i] ?? null;
          $size = (int)($_FILES['attachments']['size'][$i] ?? 0);

          if ($attTable) {
            $cols = ['query_id','path'];
            $vals = [$id, $url];
            if (col_exists($pdo,$attTable,'original_name')) { $cols[]='original_name'; $vals[]=$name; }
            if (col_exists($pdo,$attTable,'mime'))          { $cols[]='mime';          $vals[]=$mime; }
            if (col_exists($pdo,$attTable,'size'))          { $cols[]='size';          $vals[]=$size; }
            if (col_exists($pdo,$attTable,'created_at'))    { $cols[]='created_at';    $vals[] = date('Y-m-d H:i:s'); }
            $sql = 'INSERT INTO '.$attTable.' ('.implode(',', $cols).') VALUES ('.rtrim(str_repeat('?,', count($vals)),',').')';
            $pdo->prepare($sql)->execute($vals);
          }
        }
      }

      // Internal note
      $pdo->prepare("
        INSERT INTO messages (query_id, sender_admin_id, direction, medium, body, created_at)
        VALUES (?, ?, 'internal', 'note', ?, NOW())
      ")->execute([$id, $me, "Forward request submitted to team #{$forward_team_id} (priority: {$forward_priority})"]);

      // Audit breadcrumb
      $meta = json_encode(['req_team'=>$forward_team_id,'priority'=>$forward_priority], JSON_UNESCAPED_SLASHES);
      $pdo->prepare("
        INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
        VALUES ('query', ?, ?, 'forward_requested', ?, NOW())
      ")->execute([$id, $me, $meta]);

      header('Location: /app/query.php?id='.$id.'#thread'); exit;
    } catch (Throwable $ex) {
      $err = 'Save failed. Please try again.';
      error_log('[fill.php] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
    }
  }
}

$title = 'Forward request • complete all fields';
ob_start();
?>
<h2>Forward request — complete all fields</h2>

<?php if ($err): ?>
  <div style="padding:10px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:8px;margin-bottom:12px"><?= $err ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #eee;border-radius:12px;padding:16px">
  <!-- System / read-only -->
  <div class="grid two">
    <div>
      <label>ID</label>
      <input value="#<?= (int)$q['id'] ?>" readonly>
    </div>
    <div>
      <label>Query code</label>
      <input value="<?= e($q['query_code'] ?: '') ?>" readonly>
    </div>
  </div>

  <div class="grid three">
    <div>
      <label>Current team</label>
      <input value="<?= e($q['team_name'] ?: ('#'.$q['current_team_id'])) ?>" readonly>
    </div>
    <div>
      <label>Assigned to</label>
      <input value="<?= e($q['assigned_name'] ?: '-') ?>" readonly>
    </div>
    <div>
      <label>Status</label>
      <input value="<?= e($q['status'] ?: '-') ?>" readonly>
    </div>
  </div>

  <!-- Editable -->
  <div class="grid three">
    <div>
      <label id="lbl_customer_name">Customer name *</label>
      <input id="customer_name" name="customer_name" value="<?= e($q['customer_name'] ?? '') ?>">
    </div>
    <div>
      <label id="lbl_phone">Phone *</label>
      <input id="phone" name="phone" value="<?= e($q['phone'] ?? '') ?>" placeholder="+8801…">
    </div>
    <div>
      <label id="lbl_email">Email</label>
      <input id="email" name="email" value="<?= e($q['email'] ?? '') ?>" placeholder="name@example.com">
    </div>
  </div>

  <label id="lbl_product_details">Service details *</label>
  <textarea id="product_details" name="product_details" rows="3" placeholder="Describe the service…"><?= e($q['product_details'] ?? '') ?></textarea>

  <div class="grid three">
    <div>
      <label id="lbl_country_id">Country *</label>
      <select id="country_id" name="country_id">
        <option value="">— Select Country —</option>
        <?php foreach ($countries as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$q['country_id']===(int)$c['id'])?'selected':'' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label id="lbl_product_name">Product Name *</label>
      <input id="product_name" name="product_name" value="<?= e($q['product_name'] ?? '') ?>">
    </div>
    <div>
      <label id="lbl_query_type">Query type *</label>
      <select id="query_type" name="query_type">
        <?php foreach ($queryTypes as $opt): ?>
          <option value="<?= e($opt) ?>" <?= ($q['query_type']===$opt?'selected':'') ?>><?= e(ucfirst($opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="grid two">
    <div>
      <label id="lbl_product_links">Product links (comma separated) *</label>
      <input id="product_links" name="product_links" value="<?= e($q['product_links'] ?? '') ?>" placeholder="https://…">
    </div>
    <div>
      <label id="lbl_shipping_mode">Shipping mode *</label>
      <select id="shipping_mode" name="shipping_mode">
        <?php foreach ($shipModes as $m): ?>
          <option value="<?= e($m) ?>" <?= ($q['shipping_mode']===$m?'selected':'') ?>><?= e(ucfirst($m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="grid four">
    <div>
      <label id="lbl_quantity">Quantity *</label>
      <input id="quantity" name="quantity" type="number" min="0" value="<?= e((string)($q['quantity'] ?? '')) ?>">
    </div>
    <div>
      <label id="lbl_budget">Budget (USD) *</label>
      <input id="budget" name="budget" type="number" step="0.01" min="0" value="<?= e((string)($q['budget'] ?? '')) ?>">
    </div>
    <div>
      <label id="lbl_carton_count">Carton count *</label>
      <input id="carton_count" name="carton_count" type="number" step="1" min="0" value="<?= e((string)($q['carton_count'] ?? '')) ?>">
    </div>
    <div>
      <label id="lbl_cbm">CBM *</label>
      <input id="cbm" name="cbm" type="number" step="0.001" min="0" value="<?= e((string)($q['cbm'] ?? '')) ?>">
    </div>
  </div>

  <div class="grid two">
    <div>
      <label id="lbl_label_type">Label type *</label>
      <input id="label_type" name="label_type" value="<?= e($q['label_type'] ?? '') ?>" placeholder="e.g., FBA / Custom / Plain">
    </div>
    <div>
      <label>Priority (current)</label>
      <input value="<?= e($q['priority'] ?: 'default') ?>" readonly>
    </div>
  </div>

  <label id="lbl_address">Address *</label>
  <textarea id="address" name="address" rows="2"><?= e($q['address'] ?? '') ?></textarea>

  <label id="lbl_notes">Notes *</label>
  <textarea id="notes" name="notes" rows="2"><?= e($q['notes'] ?? '') ?></textarea>

  <!-- Attachments -->
  <h3 style="margin-top:14px">Attachments</h3>
  <?php if ($attachments): ?>
    <div class="att-list">
      <?php foreach ($attachments as $a): ?>
        <div class="att-item">
          <div class="att-name">
            <a href="<?= e($a['path']) ?>" target="_blank" rel="noopener">
              <?= e($a['original_name'] ?? basename($a['path'])) ?>
            </a>
          </div>
          <?php if (!empty($a['mime'])): ?><div class="att-meta"><?= e($a['mime']) ?></div><?php endif; ?>
          <?php if (!empty($a['size'])): ?><div class="att-meta"><?= number_format((int)$a['size']) ?> bytes</div><?php endif; ?>
          <?php if (!empty($a['created_at'])): ?><div class="att-meta"><?= e($a['created_at']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="note">No attachments yet.</div>
  <?php endif; ?>

  <div style="margin-top:8px">
    <label>Add attachment(s)</label>
    <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.xlsx,.xls,.csv,.doc,.docx,.txt">
    <div class="note">You can attach multiple files. Allowed common types (PDF, images, office docs).</div>
  </div>

  <!-- System info -->
  <div class="grid three muted" style="margin-top:12px">
    <div><label>Created at</label><input value="<?= e($q['created_at'] ?: '-') ?>" readonly></div>
    <div><label>Updated at</label><input value="<?= e($q['updated_at'] ?: '-') ?>" readonly></div>
    <div><label>SLA due at</label><input value="<?= e($q['sla_due_at'] ?: '-') ?>" readonly></div>
  </div>

  <hr style="margin:16px 0;border:none;border-top:1px solid #eee">

  <!-- Forward target -->
  <div class="grid two">
    <div>
      <label>Forward to team *</label>
      <select id="forward_team_id" name="forward_team_id">
        <option value="">— Select team —</option>
        <?php foreach ($teams as $t): ?>
          <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Forward priority *</label>
      <select id="forward_priority" name="forward_priority">
        <?php $cur = $q['priority'] ?: 'default'; foreach ($priorities as $p): ?>
          <option value="<?= e($p) ?>" <?= ($p===$cur?'selected':'') ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="actions">
    <button class="btn" type="submit" name="request_forward" value="1">Send to Supervisor</button>
    <a class="btn secondary" href="/app/query.php?id=<?= (int)$id ?>">Cancel</a>
  </div>
</form>

<style>
  label{display:block;margin:.6rem 0 .25rem;font-weight:600}
  input,select,textarea{width:100%;padding:.6rem;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
  .grid{display:grid;gap:12px}
  .grid.two{grid-template-columns:1fr 1fr}
  .grid.three{grid-template-columns:1fr 1fr 1fr}
  .grid.four{grid-template-columns:1fr 1fr 1fr 1fr}
  .muted input{background:#fafafa}
  .btn{display:inline-block;padding:.6rem 1rem;border-radius:10px;background:#111827;color:#fff;text-decoration:none;border:0;cursor:pointer}
  .btn.secondary{background:#374151}
  .actions{margin-top:16px;display:flex;gap:10px}
  @media (max-width:900px){
    .grid.two,.grid.three,.grid.four{grid-template-columns:1fr}
  }
  .optional{color:#6b7280;font-weight:500}
  .note{color:#6b7280;font-size:.9rem}
  .att-list{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
  .att-item{border:1px solid #eee;border-radius:10px;padding:8px;background:#fafafa}
  .att-name{font-weight:600}
  .att-meta{font-size:.85rem;color:#6b7280}
</style>

<script>
// ---- Client-side: mirror the conditional required rules for better UX ----
const OPTIONAL_BY_TYPE = {
  'sourcing': ['shipping_mode','carton_count','cbm','label_type'],
  'both':     ['carton_count','cbm','label_type'],
  'shipping': ['product_links','budget']
};
const ALL_KEYS = [
  'customer_name','phone','product_details','country_id','product_name','query_type',
  'product_links','quantity','budget','address','notes',
  'shipping_mode','label_type','carton_count','cbm'
];

function setRequired(id, req){
  const input = document.getElementById(id);
  const lbl   = document.getElementById('lbl_'+id);
  if (!input || !lbl) return;
  if (req) {
    input.setAttribute('required','required');
    lbl.innerHTML = lbl.textContent.replace(' (optional)','') + ' *';
    lbl.classList.remove('optional');
  } else {
    input.removeAttribute('required');
    lbl.innerHTML = lbl.textContent.replace(' *','') + ' (optional)';
    lbl.classList.add('optional');
  }
}

function applyRules(){
  const qtEl = document.getElementById('query_type');
  const qt = qtEl ? qtEl.value : '';
  const optional = OPTIONAL_BY_TYPE[qt] || [];
  const requiredSet = new Set(ALL_KEYS.filter(k => !optional.includes(k)));
  ALL_KEYS.forEach(k => setRequired(k, requiredSet.has(k)));
}

// Initial apply + on change
applyRules();
document.getElementById('query_type').addEventListener('change', applyRules);
</script>
<?php
$content = ob_get_clean();
include __DIR__.'/layout.php';
