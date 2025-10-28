<?php
require_once __DIR__.'/auth.php'; require_perm('manage_admins');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['toggle'])) {
    db()->prepare("UPDATE teams SET is_active = IF(is_active=1,0,1) WHERE id=?")
      ->execute([(int)$_POST['id']]);
  }
  if (isset($_POST['leader_set'])) {
    db()->prepare("UPDATE teams SET leader_admin_user_id=? WHERE id=?")
      ->execute([($_POST['leader_admin_user_id']? (int)$_POST['leader_admin_user_id'] : null), (int)$_POST['id']]);
  }
  header("Location: /app/teams.php"); exit;
}

$teams = db()->query("SELECT * FROM teams ORDER BY id")->fetchAll();
$admins = db()->query("SELECT id,email FROM admin_users ORDER BY email")->fetchAll();

$title='Teams';
ob_start(); ?>
<h2>Teams</h2>
<table>
  <thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Active</th><th>Leader</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($teams as $t): ?>
    <tr>
      <td><?= (int)$t['id'] ?></td>
      <td><?= e($t['name']) ?></td>
      <td><?= e($t['code']) ?></td>
      <td><?= $t['is_active']?'Yes':'No' ?></td>
      <td><?= $t['leader_admin_user_id'] ? e(db()->query("SELECT email FROM admin_users WHERE id=".(int)$t['leader_admin_user_id'])->fetchColumn()): '-' ?></td>
      <td style="display:flex;gap:8px">
        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn" name="toggle" value="1"><?= $t['is_active']?'Deactivate':'Activate' ?></button>
        </form>
        <form method="post" style="display:flex;gap:6px">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <select name="leader_admin_user_id">
            <option value="">— none —</option>
            <?php foreach($admins as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ((int)$t['leader_admin_user_id']===(int)$a['id'])?'selected':'' ?>>
                <?= e($a['email']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn" name="leader_set" value="1">Set leader</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php $content=ob_get_clean(); include __DIR__.'/layout.php';
