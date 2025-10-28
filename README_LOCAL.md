Local Run (PHP + Frontend)

Overview
- Docroot is `public_html`. It contains the frontend (`index.html`) and customer PHP pages under `public_html/customer`, plus API endpoints under `public_html/api`.
- The customer frontend already links to the PHP pages (e.g., the "My Orders" button routes to `/customer/orders.php?t=...`).

Prerequisites
- PHP 7.4+ installed and on PATH
- PHP extensions enabled: `pdo_mysql`, `openssl`
  - On Windows, edit your `php.ini` and ensure lines exist (uncomment if needed):
    - `extension=openssl`
    - `extension=pdo_mysql`

Start the local server
- Option A (PowerShell): `./serve.ps1`
- Option B (CMD): `serve.bat`
- This serves `public_html` at `http://localhost:8000/`.

Using the app
- Open `http://localhost:8000/index.html`.
- Sign in via Clerk (uses the configured test publishable key in `index.html`).
  - Ensure your Clerk project allows `http://localhost:8000` as an authorized origin in its dashboard.
- The frontend calls API endpoints at `/api/...` and links to customer pages under `/customer/...`.
- "My Orders" button navigates to `/customer/orders.php?t=<ClerkToken>`.

Database
- DB settings are in:
  - `public_html/api/config.php`
  - `public_html/app/config.php`
- These must point to a reachable MySQL instance. Without a working DB and `pdo_mysql`, API and server-rendered pages that query data will error.

Diagnostics
- Visit `http://localhost:8000/app/_diag/db_ping.php` to verify DB connectivity.

Common issues
- Missing `pdo_mysql`: enable it in `php.ini` (Windows: `extension=pdo_mysql`).
- Missing `openssl`: enable it in `php.ini`.
- Clerk token errors locally:
  - Add `http://localhost:8000` to allowed origins in Clerk settings.
  - Ensure `CLERK_PEM_PUBLIC_KEY` in `public_html/api/config.php` matches your Clerk project's public key.

