<?php
require_once __DIR__.'/lib.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_out(['error'=>'Use GET'],405); }

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = (stripos($auth,'Bearer ')===0) ? substr($auth,7) : '';
if (!$token && isset($_COOKIE['__session'])) $token = $_COOKIE['__session'];
if (!$token) json_out(['error'=>'Missing auth token'],401);

try { $claims = verify_clerk_jwt($token); } catch (Exception $e) { json_out(['error'=>'Auth failed'],401); }
$uid = $claims['sub'] ?? null;
if (!$uid) json_out(['error'=>'Bad token'],401);

$st = db()->prepare("
 SELECT q.id, q.query_code, q.status, q.priority, q.query_type, q.created_at,
        t.name AS team_name
 FROM queries q
 LEFT JOIN teams t ON t.id=q.current_team_id
 WHERE q.clerk_user_id=?
 ORDER BY q.id DESC
");
$st->execute([$uid]);
json_out(['ok'=>true,'rows'=>$st->fetchAll()]);
