<?php
require_once __DIR__.'/config.php';

function admin_by_email($email){
  $st=db()->prepare("SELECT * FROM admin_users WHERE email=? AND is_active=1 LIMIT 1");
  $st->execute([$email]); return $st->fetch();
}
function admin_permissions($admin_id){
  $sql="SELECT p.name FROM permissions p
        JOIN role_permissions rp ON rp.permission_id=p.id
        JOIN admin_user_roles ur ON ur.role_id=rp.role_id
        WHERE ur.admin_user_id=?";
  $st=db()->prepare($sql); $st->execute([$admin_id]);
  return array_column($st->fetchAll(),'name');
}
function can($perm){
  $perms=$_SESSION['perms'] ?? [];
  return in_array($perm,$perms,true) || in_array('manage_admins',$perms,true); // super override
}
function require_login(){
  if (empty($_SESSION['admin'])) { header("Location: /app/login.php"); exit; }
}
function require_perm($perm){
  require_login();
  if (!can($perm)) { http_response_code(403); echo "Forbidden"; exit; }
}
