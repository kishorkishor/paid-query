<?php
header('Content-Type: application/json');
echo json_encode([
  'has_http_authorization' => isset($_SERVER['HTTP_AUTHORIZATION']),
  'http_authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
]);
