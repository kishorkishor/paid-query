<?php
// ---- DB ----
define('DB_HOST', 'localhost');                 
define('DB_NAME', 'u966125597_cosmictrd');      
define('DB_USER', 'u966125597_admin');          
define('DB_PASS', 'All@h1154');                 

// ---- CORS ----
define('ALLOWED_ORIGIN', 'https://cosmictrd.io'); 

// ---- Clerk (from Dashboard) ----
define('CLERK_PEM_PUBLIC_KEY', <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1FPVphPGcGldCqZf/HJ/
CbGrj68iVUbQRD7yfv2EpvjwcOMw7Jz+Ghav5e1ojGDb2qv2t9eUQBIbHSwYrmRU
LLAsobuEOxLbxeFoAJw5FWujh+XS/EgMh2RzBtv01o5Z6dOD+Ri1M/kuw2l8S8/s
zUKN4nChSgyECFtyVCarkl/coVlh/S+vGs/7xpr7QeRWf7HXgOHXgNR7uL2s47y1
vMaynVzUuIYOnidc94RMY/P4XDbkTvMG8I+UJ5ZJGc6Emvr/4N/30RP1pmSOM/nd
m0qfr1xJpS/qJcICBBjXjhhympOfCx9QrVxQ86FCmHGtemoLuPw5hvVrEjTkz1hQ
ywIDAQAB
-----END PUBLIC KEY-----
PEM);

// Optional: verify azp (authorized party/origin)
define('AUTHORIZED_PARTIES', serialize([ALLOWED_ORIGIN]));

// Issuer check
define('CLERK_ISSUER', 'https://suited-grouper-99.clerk.accounts.dev');

// Uploads dir
define('UPLOAD_DIR', __DIR__ . '/uploads');
if (!is_dir(UPLOAD_DIR)) { mkdir(UPLOAD_DIR, 0755, true); }