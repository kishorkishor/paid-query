<?php require_once __DIR__.'/auth.php'; ?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title ?? 'Backoffice') ?></title>
<style>
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f5f6fb}
  header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:#0f172a;color:#fff}
  a{color:#0f172a;text-decoration:none}
  .wrap{max-width:1100px;margin:24px auto;background:#fff;padding:18px;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
  nav a{margin-right:14px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eee}
  .btn{display:inline-block;padding:8px 12px;border-radius:10px;background:#0f172a;color:#fff}
  .badge{padding:4px 8px;border-radius:999px;background:#eef;border:1px solid #dde}
</style>
</head><body>
<header>
  <div><strong>Cosmic Backoffice</strong></div>
  <div>
    <?php if(!empty($_SESSION['admin'])): ?>
      <span class="badge"><?= htmlspecialchars($_SESSION['admin']['email']) ?></span>
      <a class="btn" href="/app/logout.php">Logout</a>
    <?php endif; ?>
  </div>
</header>
<div class="wrap">
  <nav>
    <a href="/app/">Dashboard</a>
    <a href="/app/queries.php">Queries</a>
    <?php if (can('manage_admins')): ?>
  <a href="/app/users.php">Admins & Roles</a>
  <a href="/app/teams.php">Teams</a>
<?php endif; ?>
  </nav>
  <hr>
  <?= $content ?? '' ?>
</div>
</body></html>
