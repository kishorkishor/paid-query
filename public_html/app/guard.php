<?php
require_once __DIR__.'/auth.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['_token'])) {
  // very light CSRF
  http_response_code(400); echo "Bad Request"; exit;
}
