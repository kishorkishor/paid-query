<?php
require_once __DIR__.'/auth.php'; require_perm('manage_admins');

$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['create'])) {
    $name=trim($_POST['name']??'');
    $email=trim($_POST['email']??'');
    $pass=$_POST['password']??'';
    if (!$name || !$email || !$pass) $err='All fields required';
    else {
      $st=db()->prepare("INSERT INTO admin_users (name,email,password_hash) VALUES (?,?,?)");
      $st->execute([$name,$email,password_hash($pass, PASSWORD_BCRYPT)]);
      $ok='Admin created';
    }
  }
  if (isset($_POST['role_set'])) {
    $uid=(int)$_POST['uid']; $rid=(int)$_POST['role_id'];
    db()->prepare("DELETE FROM admin_user_roles WHERE admin_user_id=?")->execute([$uid]);
    db()->prepare("INSERT INTO admin_user_roles (admin_user_id, role_id) VALUES (?,?)")->execute([$uid,$rid]);
    $ok='Role updated';
  }
  if (isset($_POST['perms_set'])) {
    $rid=(int)$_POST['rid']; $perms=$_POST['perms']??[];
    db()->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$rid]);
    $st=db()->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)");
    foreach ($perms as $pid) $st->execute([$rid,(int)$pid]);
    $ok='Permissions updated';
  }
}

$admins = db()->query("SELECT u.id,u.name,u.email,COALESCE(r.name,'(none)') as role_name, r.id as rid
  FROM admin_users u
  LEFT JOIN admin_user_roles ur ON ur.admin_user_id=u.id
  LEFT JOIN roles r ON r.id=ur.role_id
  ORDER BY u.id DESC")->fetchAll();

$roles = db()->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$perms = db()->query("SELECT * FROM permissions ORDER BY id")->fetchAll();

$title='Admins & Roles';
ob_start(); ?>
<h2>Admins & Roles</h2>
<?php if($err): ?><div style="color:#b91c1c"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<?php if($ok): ?><div style="color:green"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

<h3>Create Admin</h3>
<form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
  <input name="name" placeholder="Name" required>
  <input type="email" name="email" placeholder="email@example.com" required>
  <input type="password" name="password" placeholder="Password" required>
  <button class="btn" name="create" value="1">Create</button>
</form>

<hr>
<h3>Admins</h3>
<table>
  <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Change role</th></tr></thead>
  <tbody>
    <?php foreach($admins as $a): ?>
      <tr>
        <td><?= (int)$a['id'] ?></td>
        <td><?= htmlspecialchars($a['name']) ?></td>
        <td><?= htmlspecialchars($a['email']) ?></td>
        <td><?= htmlspecialchars($a['role_name']) ?></td>
        <td>
          <form method="post" style="display:flex;gap:6px">
            <input type="hidden" name="uid" value="<?= (int)$a['id'] ?>">
            <select name="role_id">
              <?php foreach($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= ($a['rid']==$r['id'])?'selected':'' ?>>
                  <?= htmlspecialchars($r['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn" name="role_set" value="1">Set</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<hr>
<h3>Role Permissions</h3>
<?php foreach($roles as $r): 
  $rolePerms = db()->prepare("SELECT permission_id FROM role_permissions WHERE role_id=?");
  $rolePerms->execute([$r['id']]);
  $curr = array_column($rolePerms->fetchAll(),'permission_id');
?>
  <form method="post" style="margin-bottom:12px;border:1px solid #eee;padding:10px;border-radius:10px">
    <strong><?= htmlspecialchars($r['label']) ?></strong>
    <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px">
      <?php foreach($perms as $p): ?>
        <label style="border:1px solid #ddd;padding:6px 8px;border-radius:8px">
          <input type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>" <?= in_array($p['id'],$curr)?'checked':'' ?>>
          <?= htmlspecialchars($p['label']) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:8px"><button class="btn" name="perms_set" value="1">Save permissions</button></div>
  </form>
<?php endforeach; ?>
<?php $content=ob_get_clean(); include __DIR__.'/layout.php';
