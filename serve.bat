@echo off
set PORT=8000
echo Starting local PHP dev server for public_html on http://localhost:%PORT%

set DOCROOT=%~dp0public_html
if not exist "%DOCROOT%" (
  echo Docroot not found: %DOCROOT%
  exit /b 1
)

php -v >nul 2>&1
if errorlevel 1 (
  echo PHP is not installed or not in PATH. Install PHP 7.4+ and try again.
  exit /b 1
)

echo Open    : http://localhost:%PORT%/index.html
echo Customer: http://localhost:%PORT%/customer/orders.php (requires Clerk token)

pushd "%DOCROOT%"
php -S localhost:%PORT% -t .
popd

