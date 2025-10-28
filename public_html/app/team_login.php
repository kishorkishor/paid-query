<?php
require_once __DIR__.'/auth.php';
session_start();

$pdo = db();
$error = '';

function get_user_team_id($pdo, $userId) {
    $st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=? LIMIT 1");
    $st->execute([$userId]);
    return (int)($st->fetchColumn() ?: 0);
}

function get_user_roles($pdo, $userId) {
    $st = $pdo->prepare("
        SELECT r.name
          FROM roles r
          JOIN admin_user_roles ur ON ur.role_id = r.id
         WHERE ur.admin_user_id = ?
    ");
    $st->execute([$userId]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'name');
}

// If already logged in, redirect based on role/team
if (!empty($_SESSION['admin'])) {
    $uid   = $_SESSION['admin']['id'];
    $roles = get_user_roles($pdo, $uid);
    $teamId = get_user_team_id($pdo, $uid);

    if ($teamId && in_array('country_team_supervisor', $roles, true)) {
        header("Location: /app/team_supervisor.php?team_id=$teamId");
        exit;
    }
    if (in_array('regular_sales_supervisor', $roles, true)) {
        header("Location: /app/supervisor.php");
        exit;
    }
    if ($teamId && in_array('team_agent', $roles, true)) {
        header("Location: /app/queries_team.php");
        exit;
    }
    // fall back to default backoffice
    header("Location: /app/");
    exit;
}

// Handle login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $user = admin_by_email($email);
    if ($user && password_verify($pass, $user['password_hash'])) {
        // Set session and permissions
        $_SESSION['admin'] = ['id'=>$user['id'], 'email'=>$user['email'], 'name'=>$user['name']];
        $_SESSION['perms'] = admin_permissions($user['id']);

        $roles  = get_user_roles($pdo, $user['id']);
        $teamId = get_user_team_id($pdo, $user['id']);

        if ($teamId && in_array('country_team_supervisor', $roles, true)) {
            header("Location: /app/team_supervisor.php?team_id=$teamId");
            exit;
        }
        if (in_array('regular_sales_supervisor', $roles, true)) {
            header("Location: /app/supervisor.php");
            exit;
        }
        if ($teamId && in_array('team_agent', $roles, true)) {
            header("Location: /app/queries.php");
            exit;
        }
        header("Location: /app/");
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Admin Login</title>
<style>
  body{font-family:system-ui;display:grid;place-items:center;height:100vh;background:#f5f6fb}
  .card{background:#fff;padding:22px;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.08);width:360px}
  input{width:100%;padding:.8rem;border:1px solid #e5e7eb;border-radius:10px;margin:.5rem 0}
  button{width:100%;padding:.8rem;border:0;border-radius:10px;background:#0f172a;color:#fff}
  .err{color:#b91c1c;margin:.5rem 0}
</style>
</head>
<body>
  <div class="card">
    <h2>Backoffice Login</h2>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input name="email" type="email" placeholder="Email" required>
      <input name="password" type="password" placeholder="Password" required>
      <button type="submit">Sign in</button>
    </form>
  </div>
</body>
</html>
