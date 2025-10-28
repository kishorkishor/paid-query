<?php
// /app/dev_switch_login.php — DEV ONLY: Switch/impersonate any admin user in one click.

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/logs/_php_errors.log');

session_start();
require_once __DIR__ . '/auth.php';  // must provide db(), can(), etc.

// -------------------------------------------------------------------------------------
// CONFIG (tweak as you like)
// -------------------------------------------------------------------------------------
const DEV_MODE = true; // set to false in prod

// -------------------------------------------------------------------------------------
// Small helpers
// -------------------------------------------------------------------------------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ensure_access_guard(): void {
  // Allow if DEV_MODE or the current user is super admin or has manage_admins
  $isDev = DEV_MODE === true;

  $loggedIn = !empty($_SESSION['admin']['id']);
  $allow = false;

  if ($isDev) {
    $allow = true;
  } else if ($loggedIn) {
    // If you have a canonical super admin check, use that. Else check permission.
    if (in_array('manage_admins', $_SESSION['perms'] ?? [], true)) {
      $allow = true;
    }
    // Optional: treat admin id 1 as super.
    if ((int)($_SESSION['admin']['id'] ?? 0) === 1) { $allow = true; }
  }

  if (!$allow) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title>
      <style>body{font-family:system-ui;margin:40px} .card{max-width:720px}</style>
      <h1>Forbidden</h1>
      <p>This dev utility is disabled. Ask an admin or enable DEV_MODE.</p>';
    exit;
  }
}

function csrf_get(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}

function csrf_check(string $token): void {
  if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(419);
    echo '<!doctype html><meta charset="utf-8"><title>CSRF</title>
          <p>Invalid CSRF token.</p>';
    exit;
  }
}

/**
 * Build a consistent session for a given admin user id:
 * - loads user row
 * - loads roles, teams
 * - loads permissions (if role_permissions table exists; otherwise skip gracefully)
 */
function switch_to_admin(PDO $pdo, int $adminId): void {
  // 1) Load the admin user
  $st = $pdo->prepare("SELECT id, name, email, is_active FROM admin_users WHERE id=? LIMIT 1");
  $st->execute([$adminId]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) { throw new RuntimeException("Admin user not found."); }
  if ((int)$u['is_active'] !== 1) { throw new RuntimeException("Admin user is inactive."); }

  // 2) Load roles
  $roles = [];
  try {
    $st = $pdo->prepare("
      SELECT r.id, r.name
        FROM admin_user_roles aur
        JOIN roles r ON r.id = aur.role_id
       WHERE aur.admin_user_id = ?
    ");
    $st->execute([$adminId]);
    $roles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // roles table may not exist yet
    $st = $pdo->prepare("SELECT role_id AS id FROM admin_user_roles WHERE admin_user_id=?");
    $st->execute([$adminId]);
    $rids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $roles = array_map(fn($id) => ['id'=>$id, 'name'=>"role#$id"], $rids);
  }

  // 3) Load teams
  $teams = [];
  try {
    $st = $pdo->prepare("
      SELECT t.id, t.name
        FROM admin_user_teams aut
        JOIN teams t ON t.id = aut.team_id
       WHERE aut.admin_user_id = ?
    ");
    $st->execute([$adminId]);
    $teams = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // teams table may not exist yet; fallback to ids
    $st = $pdo->prepare("SELECT team_id AS id FROM admin_user_teams WHERE admin_user_id=?");
    $st->execute([$adminId]);
    $tids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $teams = array_map(fn($id) => ['id'=>$id, 'name'=>"team#$id"], $tids);
  }

  // 4) Load permissions via roles (if mapping exists)
  $perms = [];
  try {
    $st = $pdo->query("SHOW TABLES LIKE 'role_permissions'");
    $hasRolePerms = (bool)$st->fetchColumn();

    if ($hasRolePerms) {
      $ridList = array_map(fn($r) => (int)$r['id'], $roles);
      if ($ridList) {
        $in = implode(',', array_fill(0, count($ridList), '?'));
        $q = $pdo->prepare("
          SELECT DISTINCT p.name
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
           WHERE rp.role_id IN ($in)
        ");
        $q->execute($ridList);
        $perms = array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN) ?: []));
      }
    } else {
      // Fallback: if you store perms directly in session elsewhere, leave empty here.
      $perms = $_SESSION['perms'] ?? [];
    }
  } catch (Throwable $e) {
    $perms = $_SESSION['perms'] ?? [];
  }

  // 5) Reset session and set the new identity
  $_SESSION['admin'] = [
    'id'    => (int)$u['id'],
    'name'  => (string)$u['name'],
    'email' => (string)$u['email'],
  ];
  $_SESSION['roles'] = $roles;
  $_SESSION['teams'] = $teams;
  $_SESSION['perms'] = $perms;
}

/** Fetch list of admins with their role/team labels for UI table */
function list_admins(PDO $pdo): array {
  // Admins
  $admins = $pdo->query("SELECT id, name, email, is_active, created_at FROM admin_users ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);

  // Roles (optional names)
  $roles = [];
  try {
    $roles = $pdo->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
  } catch (Throwable $e) {}

  // Teams (optional names)
  $teams = [];
  try {
    $teams = $pdo->query("SELECT id, name FROM teams")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
  } catch (Throwable $e) {}

  // user→roles
  $aur = $pdo->query("SELECT admin_user_id, role_id FROM admin_user_roles")->fetchAll(PDO::FETCH_ASSOC);
  $userRoles = [];
  foreach ($aur as $row) {
    $uid = (int)$row['admin_user_id'];
    $rid = (int)$row['role_id'];
    $userRoles[$uid][] = $roles[$rid] ?? ("role#$rid");
  }

  // user→teams
  $aut = $pdo->query("SELECT admin_user_id, team_id FROM admin_user_teams")->fetchAll(PDO::FETCH_ASSOC);
  $userTeams = [];
  foreach ($aut as $row) {
    $uid = (int)$row['admin_user_id'];
    $tid = (int)$row['team_id'];
    $userTeams[$uid][] = $teams[$tid] ?? ("team#$tid");
  }

  // Join together
  foreach ($admins as &$a) {
    $uid = (int)$a['id'];
    $a['roles'] = $userRoles[$uid] ?? [];
    $a['teams'] = $userTeams[$uid] ?? [];
  }
  return $admins;
}

/** Decide the correct landing page after switching, based on permissions/teams */
function route_after_switch(array $perms, array $teams): string {
  $P = array_fill_keys($perms, true);

  // Priority order (most specific first)
  if (isset($P['qc_supervisor_access']))    return '/app/qc_supervisor.php';       // QC supervisor
  if (isset($P['qc_access']))               return '/app/qc_dashboard.php';        // QC member
  if (isset($P['chinese_inbound_access']))  return '/app/inbound_dashboard.php';   // Chinese Inbound
  if (isset($P['manage_ledgers']))          return '/app/accounts_payments.php';  // Accounts
  if (isset($P['team_supervisor_access']))  return '/app/query_team_supervisor.php'; // Any supervisor view
  if (isset($P['view_orders']) && isset($P['view_queries'])) return '/app/dashboard_team.php'; // mixed ops
  if (isset($P['view_queries']) || isset($P['submit_price_quote'])) return '/app/query_team_member.php'; // Agent
  if (isset($P['view_orders']))             return '/app/orders.php';              // Read-only orders
  if (isset($P['manage_admins']))           return '/app/users.php';              // Admin management

  // Optional: team-based overrides if you key special team IDs
  $teamIds = array_map(fn($t) => (int)($t['id'] ?? $t['team_id'] ?? 0), $teams);
  if (in_array(12, $teamIds, true)) return '/app/inbound_dashboard.php'; // e.g., team 12 = CN Inbound
  if (in_array(13, $teamIds, true)) return '/app/qc_dashboard.php';      // e.g., team 13 = QC

  // Fallback
  return '/app/';
}

// -------------------------------------------------------------------------------------
// Access guard
// -------------------------------------------------------------------------------------
ensure_access_guard();

$pdo = db();
$csrf = csrf_get();

// -------------------------------------------------------------------------------------
// Handle POST (impersonate)
// -------------------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $token   = $_POST['csrf'] ?? '';
  $target  = (int)($_POST['admin_user_id'] ?? 0);
  csrf_check($token);

  // Keep track of who initiated the impersonation (if any)
  $prevAdminId = (int)($_SESSION['admin']['id'] ?? 0);
  $prevEmail   = (string)($_SESSION['admin']['email'] ?? '');

  try {
    switch_to_admin($pdo, $target);

    // Optional: write an audit log entry if your table exists.
    try {
      $hasAudit = (bool)$pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn();
      if ($hasAudit) {
        $ins = $pdo->prepare("
          INSERT INTO audit_logs(entity_type, entity_id, admin_user_id, action, meta)
          VALUES('admin', ?, ?, 'impersonate', JSON_OBJECT('by_admin_id', ?, 'by_email', ?))
        ");
        $ins->execute([$target, $prevAdminId ?: null, $prevAdminId, $prevEmail]);
      }
    } catch (Throwable $e) {
      // ignore audit failures
    }

    $perms = $_SESSION['perms'] ?? [];
    $teams = $_SESSION['teams'] ?? [];
    header("Location: " . route_after_switch($perms, $teams));
    exit;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

// -------------------------------------------------------------------------------------
// UI render
// -------------------------------------------------------------------------------------
$admins = list_admins($pdo);

// Quick personas (adjust ids to your data dump)
$quickPersonas = [
  ['label' => 'Super Admin',            'id' => 1],
  ['label' => 'Regular Supervisor',     'id' => 5],
  ['label' => 'Regular Agent',          'id' => 6],
  ['label' => 'BD Agent',               'id' => 7],
  ['label' => 'BD Supervisor',          'id' => 8],
  ['label' => 'BD Agent (Shipping)',    'id' => 9],
  ['label' => 'Chinese Inbound',        'id' => 10],
  ['label' => 'QC CN',                  'id' => 12],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Dev: Switch Admin User</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--bg:#f7f7fb;--card:#fff;--line:#e5e7eb;--accent:#2563eb}
    body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:var(--ink);color:#fff}
    .container{max-width:1100px;margin:24px auto;padding:0 16px}
    .grid{display:grid;grid-template-columns:1fr;gap:16px}
    @media(min-width:900px){.grid{grid-template-columns: 320px 1fr}}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;box-shadow:0 6px 22px rgba(0,0,0,.05)}
    .card h2{margin:0;padding:14px 16px;border-bottom:1px solid var(--line);font-size:1.1rem}
    .card .body{padding:16px}
    .pill{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid var(--line);background:#fafafa;font-size:.8rem;color:#111}
    .muted{color:var(--muted)}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;border:1px solid var(--line);background:#fff;cursor:pointer}
    .btn.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
    .btn.small{padding:6px 10px;font-size:.9rem}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
    th{text-align:left;font-weight:600}
    tr:hover{background:#fafafa}
    .row{display:flex;flex-wrap:wrap;gap:8px}
    .danger{color:#b91c1c}
    .notice{padding:10px 12px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;margin-bottom:10px}
    form{display:inline}
  </style>
</head>
<body>
<header>
  <div>🧪 Dev Utility — Switch Admin User</div>
  <div class="muted">
    <?php if (!empty($_SESSION['admin']['id'])): ?>
      Impersonating: <?= e($_SESSION['admin']['name'] ?? '') ?> (ID <?= (int)$_SESSION['admin']['id'] ?>)
    <?php else: ?>
      Not logged in
    <?php endif; ?>
  </div>
</header>

<div class="container grid">

  <div class="card">
    <h2>Quick personas</h2>
    <div class="body">
      <div class="notice">Instantly impersonate common roles to verify full system flows. (CSRF protected)</div>
      <div class="row">
        <?php foreach ($quickPersonas as $p): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"/>
            <input type="hidden" name="admin_user_id" value="<?= (int)$p['id'] ?>"/>
            <button class="btn small primary" type="submit"><?= e($p['label']) ?> (ID <?= (int)$p['id'] ?>)</button>
          </form>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="margin-top:10px">
        You will be auto-routed to the correct dashboard for the selected role.
      </p>
      <?php if (!empty($error)): ?>
        <div class="notice danger" style="margin-top:10px"><?= e($error) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="grid-column: 1 / -1">
    <h2>All admin users</h2>
    <div class="body" style="overflow:auto">
      <table>
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th>User</th>
            <th>Roles</th>
            <th>Teams</th>
            <th>Status</th>
            <th style="width:160px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $a): ?>
            <tr>
              <td>#<?= (int)$a['id'] ?></td>
              <td>
                <div><strong><?= e($a['name']) ?></strong></div>
                <div class="muted"><?= e($a['email']) ?></div>
                <?php if (!empty($a['created_at'])): ?>
                  <div class="muted" style="font-size:.85rem">Created: <?= e($a['created_at']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($a['roles'])): ?>
                  <?php foreach ($a['roles'] as $r): ?>
                    <span class="pill"><?= e($r) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($a['teams'])): ?>
                  <?php foreach ($a['teams'] as $t): ?>
                    <span class="pill"><?= e($t) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$a['is_active'] === 1): ?>
                  <span class="pill">active</span>
                <?php else: ?>
                  <span class="pill danger">inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"/>
                  <input type="hidden" name="admin_user_id" value="<?= (int)$a['id'] ?>"/>
                  <button class="btn small" type="submit">Switch to this user</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="muted" style="margin-top:12px">
        Tip: to restrict this page in production, set <code>DEV_MODE</code> to <code>false</code> and
        gate access behind <code>manage_admins</code> or your super-admin check.
      </p>
    </div>
  </div>

</div>
</body>
</html>
