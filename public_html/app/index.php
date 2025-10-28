<?php
require_once __DIR__.'/auth.php'; require_login();
$title='Dashboard';
$stats = db()->query("
  SELECT 
    SUM(status='new') as new_cnt,
    SUM(status='elaborated') as elaborated_cnt,
    SUM(status='in_process') as inproc_cnt,
    SUM(status='red_flag') as red_cnt
  FROM queries
")->fetch();
ob_start(); ?>
<h2>Dashboard</h2>
<p>Quick stats:</p>
<ul>
  <li>New: <strong><?= (int)$stats['new_cnt'] ?></strong></li>
  <li>Elaborated: <strong><?= (int)$stats['elaborated_cnt'] ?></strong></li>
  <li>In Process: <strong><?= (int)$stats['inproc_cnt'] ?></strong></li>
  <li>Red Flags: <strong><?= (int)$stats['red_cnt'] ?></strong></li>
</ul>
<?php $content=ob_get_clean(); include __DIR__.'/layout.php';
