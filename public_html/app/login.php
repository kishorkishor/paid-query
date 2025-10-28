<?php
require_once __DIR__.'/auth.php';
if (!empty($_SESSION['admin'])) {
  // Redirect supervisors straight to their dashboard
  if (in_array('assign_team_member', $_SESSION['perms'] ?? [], true)) {
    header("Location: /app/supervisor.php"); exit;
  }
  header("Location: /app/"); exit;
}

$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email=trim($_POST['email']??'');
  $pass=$_POST['password']??'';
  $u=admin_by_email($email);
  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['admin']=['id'=>$u['id'],'email'=>$u['email'],'name'=>$u['name']];
    $_SESSION['perms']=admin_permissions($u['id']);
    // ✅ supervisors go to supervisor dashboard
    if (in_array('assign_team_member', $_SESSION['perms'] ?? [], true)) {
      header("Location: /app/supervisor.php"); exit;
    }
    header("Location: /app/"); exit;
  } else $error='Invalid credentials';
}
?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login</title>
<style>body{font-family:system-ui;display:grid;place-items:center;height:100vh;background:#f5f6fb}
.card{background:#fff;padding:22px;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.08);width:360px}
input{width:100%;padding:.8rem;border:1px solid #e5e7eb;border-radius:10px;margin:.5rem 0}
button{width:100%;padding:.8rem;border:0;border-radius:10px;background:#0f172a;color:#fff}
.err{color:#b91c1c;margin:.5rem 0}</style></head><body>
<div class="card">
  <h2>Backoffice Login</h2>
  <?php if($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input name="email" type="email" placeholder="admin@cosmictrd.io" required>
    <input name="password" type="password" placeholder="Password" required>
    <button type="submit">Sign in</button>
  </form>
</div>
</body></html>
