param(
  [int]$Port = 8000
)

Write-Host "Starting local PHP dev server for public_html on http://localhost:$Port" -ForegroundColor Cyan

$DocRoot = Join-Path $PSScriptRoot 'public_html'
if (!(Test-Path $DocRoot)) {
  Write-Error "Docroot not found: $DocRoot"
  exit 1
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
  Write-Error "PHP is not installed or not in PATH. Install PHP 7.4+ and try again."
  exit 1
}

# Quick check for required extensions
$mods = & php -m | Out-String
$hasPDO = ($mods -match "\bPDO\b")
$hasPdoMySQL = ($mods -match "\bpdo_mysql\b")
$hasOpenSSL = ($mods -match "\bopenssl\b")

if (-not $hasPdoMySQL) {
  Write-Warning "PHP extension pdo_mysql is not enabled. API/database calls will fail until it's enabled in php.ini (extension=pdo_mysql)."
}
if (-not $hasOpenSSL) {
  Write-Warning "PHP extension openssl is not enabled. Clerk JWT verification will fail until it's enabled (extension=openssl)."
}

Write-Host "Docroot : $DocRoot"
Write-Host "Open    : http://localhost:$Port/index.html" -ForegroundColor Green
Write-Host "Customer : http://localhost:$Port/customer/orders.php (requires Clerk token)" -ForegroundColor Green

Push-Location $DocRoot
try {
  & php -S "localhost:$Port" -t .
} finally {
  Pop-Location
}

