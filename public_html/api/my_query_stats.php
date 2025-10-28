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

$db = db();

$cnt = $db->prepare("SELECT
    SUM(status='new') new_cnt,
    SUM(status='assigned') assigned_cnt,
    SUM(status='in_process') inproc_cnt,
    SUM(status='red_flag') red_cnt,
    COUNT(*) total_cnt
  FROM queries WHERE clerk_user_id=?");
$cnt->execute([$uid]);
$counts = $cnt->fetch() ?: ['new_cnt'=>0,'assigned_cnt'=>0,'inproc_cnt'=>0,'red_cnt'=>0,'total_cnt'=>0];

$recent = $db->prepare("SELECT id, query_code, status, priority, query_type, created_at
  FROM queries WHERE clerk_user_id=? ORDER BY id DESC LIMIT 5");
$recent->execute([$uid]);

json_out(['ok'=>true,'counts'=>$counts,'recent'=>$recent->fetchAll()]);
