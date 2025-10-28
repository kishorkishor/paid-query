<?php
// Reuse the same DB used by queries
define('DB_HOST', 'localhost');                 
define('DB_NAME', 'u966125597_cosmictrd');      
define('DB_USER', 'u966125597_admin');          
define('DB_PASS', 'All@h1154');

function db() {
  static $pdo=null;
  if(!$pdo){
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
  }
  return $pdo;
}

if (session_status() === PHP_SESSION_NONE) session_start();
