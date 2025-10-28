<?php
// /app/partials/menu.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function can(string $perm): bool {
  return in_array($perm, $_SESSION['perms'] ?? [], true);
}

function menu_items(): array {
  // Each item: [label, path, required_perms (any match shows it)]
  return [
    ['Dashboard',                 '/app/',                              []],
    // Supervisors
    ['Supervisor — Queries',      '/app/query_team_supervisor.php',     ['team_supervisor_access']],
    ['Supervisor — Metrics',      '/app/metrics.php',                   ['view_metrics','team_supervisor_access']],
    ['Supervisor — Notifications','/app/notifications.php',             ['view_notifications','team_supervisor_access']],
    // Agents (Regular/BD)
    ['My Queries',                '/app/query_team_member.php',         ['view_queries','submit_price_quote']],
    ['Orders (Read)',             '/app/orders.php',                     ['view_orders']],
    // Accounts
    ['Accounts — Payments',       '/app/accounts_dashboard.php',         ['manage_ledgers']],
    // Chinese Inbound
    ['Chinese Inbound',           '/app/inbound_dashboard.php',          ['chinese_inbound_access']],
    ['Create Packing List',       '/app/inbound_packing_list.php',       ['create_packing_list']],
    // QC
    ['QC Board',                  '/app/qc_dashboard.php',               ['qc_access']],
    ['QC Supervisor',             '/app/qc_supervisor.php',              ['qc_supervisor_access']],
    // Admin
    ['Admins & Roles',            '/app/admins.php',                     ['manage_admins']],
    // Dev tools (hide in prod)
    ['🧪 Switch User',            '/app/dev_switch_login.php',           []],
  ];
}

function render_menu(): void {
  $items = menu_items();
  echo '<nav style="padding:12px;border-right:1px solid #eee;min-width:240px">';
  echo '<div style="font-weight:700;margin-bottom:12px">Menu</div>';
  foreach ($items as [$label, $href, $reqPerms]) {
    $show = true;
    if ($reqPerms) {
      $show = false;
      foreach ($reqPerms as $p) { if (can($p)) { $show = true; break; } }
    }
    if ($show) {
      echo '<div style="margin:6px 0"><a href="'.htmlspecialchars($href,ENT_QUOTES).'" style="text-decoration:none;color:#111">'.htmlspecialchars($label).'</a></div>';
    }
  }
  echo '</nav>';
}
