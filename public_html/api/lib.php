<?php
require_once __DIR__ . '/config.php';

function db() {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

function b64url_decode($d){ $m=strlen($d)%4; if($m){$d.=str_repeat('=',4-$m);} return base64_decode(strtr($d,'-_','+/')); }

function verify_clerk_jwt($jwt){
  if(!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/',$jwt)) throw new Exception('Malformed JWT');
  [$h64,$p64,$s64]=explode('.',$jwt);
  $header=json_decode(b64url_decode($h64),true);
  $payload=json_decode(b64url_decode($p64),true);
  $sig=b64url_decode($s64);
  if(!$header||!$payload) throw new Exception('Bad JWT');

  $ok=openssl_verify($h64.'.'.$p64,$sig,CLERK_PEM_PUBLIC_KEY,OPENSSL_ALGO_SHA256);
  if($ok!==1) throw new Exception('Invalid signature');

  $now=time();
  if(isset($payload['exp']) && $payload['exp']<$now) throw new Exception('Token expired');
  if(isset($payload['nbf']) && $payload['nbf']>$now) throw new Exception('Token not yet valid');

  if(CLERK_ISSUER && (($payload['iss']??'')!==CLERK_ISSUER)) throw new Exception('Invalid issuer');

  return $payload; // includes sub (user id), email, etc.
}

function cors(){
  header('Vary: Origin');
  if(($_SERVER['HTTP_ORIGIN']??'')===ALLOWED_ORIGIN){
    header('Access-Control-Allow-Origin: '.ALLOWED_ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
  }
  if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(204); exit; }
}

function json_out($d,$c=200){ http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
