<?php
require_once __DIR__.'/auth.php';
require_perm('view_queries');

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$pdo      = db();
$adminId  = (int)($_SESSION['admin']['id'] ?? 0); // logged in agent

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function chipStatusClass(string $s): string {
  $k = strtolower(trim($s));
  return match ($k) {
    'new'                    => 'chip--neutral',
    'forwarded'              => 'chip--purple',
    'assigned'               => 'chip--brand',
    'price_submitted'        => 'chip--info',
    'negotiation_pending'    => 'chip--warning',
    'price_rejected'         => 'chip--danger',
    'closed', 'converted'    => 'chip--success',
    default                  => 'chip--muted',
  };
}
function chipPriorityClass(?string $p): string {
  $k = strtolower(trim((string)$p));
  return match ($k) {
    'urgent' => 'chip--danger',
    'high'   => 'chip--warning',
    'medium' => 'chip--brand',
    'low'    => 'chip--neutral',
    default  => 'chip--muted',
  };
}

// Fetch only queries assigned to this team agent **and** currently in status 'assigned'
$stmt = $pdo->prepare("
  SELECT q.id, q.query_code, q.customer_name, q.email, q.phone,
         q.query_type, q.status, q.priority,
         t.name AS team_name
    FROM queries q
    LEFT JOIN teams t ON t.id = q.current_team_id
   WHERE q.assigned_admin_user_id = ?
     AND q.status = 'assigned'
   ORDER BY q.id DESC
");
$stmt->execute([$adminId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build filter options (from the already-filtered dataset)
$statuses = [];
$types    = [];
$teams    = [];
foreach ($rows as $r) {
  if (!empty($r['status']))     { $statuses[$r['status']] = true; }
  if (!empty($r['query_type'])) { $types[$r['query_type']] = true; }
  if (!empty($r['team_name']))  { $teams[$r['team_name']] = true; }
}
ksort($statuses); ksort($types); ksort($teams);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Your Assigned Queries</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{
      --bg:#0b1020;            /* deep navy background for header strip */
      --page:#f5f7fb;          /* page background */
      --card:#ffffff;
      --text:#0f172a;          /* slate-900 */
      --muted:#6b7280;         /* gray-500 */
      --line:#e5e7eb;          /* gray-200 */
      --brand:#1d4ed8;         /* blue-700 */
      --brand-600:#2563eb;
      --success:#059669;       /* emerald-600 */
      --warning:#b45309;       /* amber-700 */
      --danger:#b91c1c;        /* red-700 */
      --info:#0369a1;          /* sky-800 */
      --purple:#6d28d9;        /* violet-700 */
      --neutral:#475569;       /* slate-600 */
    }

    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;
      color:var(--text);
      background:var(--page);
      line-height:1.45;
    }

    /* Top bar */
    .topbar{
      background:linear-gradient(90deg,var(--bg),#101736 60%,#131a3f);
      color:#fff;
      padding:14px 18px;
      display:flex;align-items:center;justify-content:space-between;gap:12px;
    }
    .brand{
      display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:.2px
    }
    .brand .logo{
      width:28px;height:28px;border-radius:8px;background:#fff1;border:1px solid #fff3;
      display:grid;place-items:center;font-size:14px
    }
    .topbar .actions a, .topbar .actions button{
      color:#fff;text-decoration:none;border:1px solid #fff3;background:#ffffff14;
      padding:6px 10px;border-radius:8px;cursor:pointer
    }
    .wrap{max-width:1200px;margin:20px auto;padding:0 16px}

    /* Card + controls */
    .card{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:14px;
      box-shadow:0 1px 2px rgba(16,24,40,.03);
      padding:16px;
    }
    .headline{
      display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 10px 0
    }
    .headline h2{font-size:20px;margin:0}
    .meta-muted{color:var(--muted);font-size:12px}

    .controls{
      display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 6px 0
    }
    .input, .select{
      appearance:none;
      border:1px solid var(--line);
      background:#fff;
      border-radius:10px;
      padding:10px 12px;
      font:inherit;
      min-width:180px;
    }
    .input{flex:1 1 260px}
    .btn{
      border:1px solid var(--brand);
      background:var(--brand);
      color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;
    }
    .btn.secondary{
      border-color:var(--line);background:#fff;color:var(--text)
    }
    .btn.ghost{
      border-color:transparent;background:transparent;color:var(--brand)
    }

    /* Table */
    .table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}
    table{border-collapse:separate;border-spacing:0;width:100%;min-width:960px;background:#fff}
    thead th{
      position:sticky;top:0;background:#fafafa;border-bottom:1px solid var(--line);
      text-align:left;font-weight:600;font-size:.9rem;padding:12px
    }
    tbody td{border-top:1px solid var(--line);padding:12px;vertical-align:top}
    tbody tr:hover{background:#fafcff}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}

    /* Chips */
    .chip{
      display:inline-flex;align-items:center;gap:6px;
      padding:4px 8px;border-radius:999px;font-size:.8rem;font-weight:600;border:1px solid transparent
    }
    .chip--brand{color:var(--brand);background:#eff6ff;border-color:#dbeafe}
    .chip--success{color:var(--success);background:#ecfdf5;border-color:#d1fae5}
    .chip--warning{color:var(--warning);background:#fffbeb;border-color:#fef3c7}
    .chip--danger{color:var(--danger);background:#fef2f2;border-color:#fee2e2}
    .chip--info{color:var(--info);background:#f0f9ff;border-color:#e0f2fe}
    .chip--purple{color:var(--purple);background:#f5f3ff;border-color:#ede9fe}
    .chip--neutral{color:var(--neutral);background:#f1f5f9;border-color:#e2e8f0}
    .chip--muted{color:#475569;background:#f8fafc;border-color:#e2e8f0}

    .kicker{font-size:.85rem;color:var(--muted)}
    .actions-cell{display:flex;gap:8px;align-items:center}
    .link-btn{
      text-decoration:none;display:inline-block;padding:8px 12px;border-radius:10px;
      background:var(--brand);color:#fff;border:1px solid var(--brand-600)
    }
    .sub{font-size:.85rem;color:var(--muted);margin-top:3px}

    /* Footer row */
    .foot{
      display:flex;justify-content:space-between;align-items:center;margin-top:10px;color:var(--muted);font-size:.9rem
    }

    @media (max-width: 720px){
      .topbar{flex-direction:column;align-items:flex-start}
      .input{flex:1 1 100%}
      .select{min-width:140px}
    }
  </style>
</head>
<body>

  <div class="topbar">
    <div class="brand">
      <div class="logo">CQ</div>
      <div>Cosmic Query Desk</div>
    </div>
    <div class="actions">
      <a href="/app/queries.php">All Queries</a>
      <a href="/app/logout.php">Logout</a>
    </div>
  </div>

  <div class="wrap">
    <div class="card">
      <div class="headline">
        <h2>My Assigned Queries</h2>
        <div class="meta-muted" id="countMeta">
          <?= count($rows) ?> total
        </div>
      </div>

      <div class="controls">
        <input class="input" id="search" placeholder="Search by code, customer, phone, email…">
        <select class="select" id="fStatus">
          <option value="">Status: All</option>
          <?php foreach(array_keys($statuses) as $s): ?>
            <option value="<?= e($s) ?>"><?= e(ucwords(str_replace('_',' ',$s))) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" id="fType">
          <option value="">Type: All</option>
          <?php foreach(array_keys($types) as $t): ?>
            <option value="<?= e($t) ?>"><?= e(ucwords(str_replace('_',' ',$t))) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" id="fTeam">
          <option value="">Team: All</option>
          <?php foreach(array_keys($teams) as $tm): ?>
            <option value="<?= e($tm) ?>"><?= e($tm) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn secondary" id="reset">Reset</button>
      </div>

      <div class="table-wrap">
        <table id="tbl">
          <thead>
            <tr>
              <th style="width:72px">ID</th>
              <th>Code</th>
              <th>Customer</th>
              <th>Contact</th>
              <th>Type</th>
              <th>Team</th>
              <th>Priority</th>
              <th>Status</th>
              <th style="width:120px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:22px">No queries assigned to you yet.</td></tr>
            <?php else: ?>
              <?php foreach($rows as $q):
                $status = (string)($q['status'] ?? '');
                $type   = (string)($q['query_type'] ?? '');
                $team   = (string)($q['team_name'] ?? '');
                $prio   = (string)($q['priority'] ?? '');
                $statusClass = chipStatusClass($status);
                $prioClass   = chipPriorityClass($prio);
              ?>
              <tr
                data-status="<?= e($status) ?>"
                data-type="<?= e($type) ?>"
                data-team="<?= e($team) ?>"
                data-text="<?= e(mb_strtolower(($q['query_code'] ?? '').' '.($q['customer_name'] ?? '').' '.($q['phone'] ?? '').' '.($q['email'] ?? ''))) ?>"
              >
                <td class="mono">#<?= (int)$q['id'] ?></td>
                <td class="mono"><?= e($q['query_code'] ?: '-') ?></td>
                <td>
                  <div><?= e($q['customer_name'] ?: '-') ?></div>
                </td>
                <td>
                  <div><?= e($q['phone'] ?: '-') ?></div>
                  <div class="sub"><?= e($q['email'] ?: '') ?></div>
                </td>
                <td><?= e($q['query_type'] ?: '-') ?></td>
                <td><?= e($q['team_name'] ?: '-') ?></td>
                <td><span class="chip <?= $prioClass ?>"><?= e($prio ?: 'default') ?></span></td>
                <td><span class="chip <?= $statusClass ?>"><?= e($status ?: '-') ?></span></td>
                <td class="actions-cell">
                  <a class="link-btn" href="/app/query_team_member.php?id=<?= (int)$q['id'] ?>">Open</a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="foot">
        <div id="visibleCount">Showing <?= count($rows) ?> of <?= count($rows) ?></div>
        <div class="kicker">Tip: Use the search box and filters together for precise results.</div>
      </div>
    </div>
  </div>

<script>
(function(){
  const $ = (s, el=document) => el.querySelector(s);
  const $$ = (s, el=document) => Array.from(el.querySelectorAll(s));

  const search = $('#search');
  const fStatus = $('#fStatus');
  const fType = $('#fType');
  const fTeam = $('#fTeam');
  const reset = $('#reset');
  const rows = $$('#tbl tbody tr');
  const visibleCount = $('#visibleCount');
  const countMeta = $('#countMeta');

  function normalize(v){ return (v||'').toString().trim().toLowerCase(); }

  function applyFilters(){
    const q = normalize(search.value);
    const st = normalize(fStatus.value);
    const tp = normalize(fType.value);
    const tm = normalize(fTeam.value);

    let shown = 0;
    rows.forEach(tr=>{
      if (tr.children.length===1) return; // skip empty state row
      const text = tr.getAttribute('data-text') || '';
      const rs = normalize(tr.getAttribute('data-status'));
      const rt = normalize(tr.getAttribute('data-type'));
      const rtm= normalize(tr.getAttribute('data-team'));

      const okQ  = q === '' || text.includes(q);
      const okS  = st === '' || rs === st;
      const okT  = tp === '' || rt === tp;
      const okTm = tm === '' || rtm === tm;

      const show = okQ && okS && okT && okTm;
      tr.style.display = show ? '' : 'none';
      if (show) shown++;
    });

    visibleCount.textContent = `Showing ${shown} of <?= count($rows) ?>`;
    countMeta.textContent = `${shown} visible • <?= count($rows) ?> total`;
  }

  [search, fStatus, fType, fTeam].forEach(el=>{
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

  reset.addEventListener('click', ()=>{
    search.value = '';
    fStatus.value = '';
    fType.value = '';
    fTeam.value = '';
    applyFilters();
  });

  // Initial apply (in case of persisted values by browser)
  applyFilters();
})();
</script>
</body>
</html>
