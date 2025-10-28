Cosmic Trading Customer Portal

Production-ready Next.js 16 app (TypeScript + Tailwind) with a PHP backend in `public_html`. This repository is prepared for Netlify deployment.

Tech Stack
- Next.js 16 (App Router), React 19, TypeScript
- Tailwind CSS 4, Framer Motion
- React Query (@tanstack/react-query)
- Clerk (auth)
- PHP 7.4+ for legacy APIs under `public_html/api`

Run Locally
1) PHP dev server (serves real APIs):
   - PowerShell: `./serve.ps1 -Port 8000`
   - CMD: `serve.bat`
   - URL: `http://localhost:8000`
2) Next.js app:
   - `cd "customer project/cosmic-portal"`
   - Set `NEXT_PUBLIC_API_BASE_URL=http://localhost:8000`
   - `npm run dev` → `http://localhost:3000`

Netlify Deployment
- Base directory: `customer project/cosmic-portal`
- Build command: `npm run build`
- Publish directory: `.next`
- Environment:
  - `NEXT_PUBLIC_API_BASE_URL` = your PHP host (e.g. `https://yourdomain.com`)
  - `NEXT_TELEMETRY_DISABLED=1`
  - Clerk keys if using auth
- `netlify.toml` and `@netlify/plugin-nextjs` are included in the Next app directory.

Notes
- Some modules still use mock routes until corresponding PHP JSON endpoints are added (orders, wallet history). Real query APIs are wired.

