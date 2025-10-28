Cosmic Trading Customer Portal — File System and How It Works

Structure
- `public_html/`
  - `index.html` — legacy landing/dashboard
  - `customer/` — legacy customer PHP pages (orders, wallet, query details)
  - `api/` — PHP JSON endpoints used by the SPA
  - `uploads/`, `logs/` — runtime assets and logs

- `customer project/cosmic-portal/` (Next.js app)
  - `src/app/` — App Router pages (dashboard, queries, orders, wallet)
  - `src/components/` — UI, layout, chat popup
  - `src/lib/api.ts` — API client; reads `NEXT_PUBLIC_API_BASE_URL`
  - `src/types/` — shared types
  - `src/app/api/mock/*` — mock endpoints where PHP JSON isn’t available

How It Works
- The SPA calls PHP APIs at `NEXT_PUBLIC_API_BASE_URL`.
- Clerk JWT is passed to PHP (Authorization header or `__session` cookie).
- Real endpoints wired: queries list/stats/details, create query, add message, countries, wallet capture.
- Still mocked until PHP JSON exists: wallet balance/history, orders list/details.

Environment
- `NEXT_PUBLIC_API_BASE_URL` = base URL of `public_html` (e.g. `http://localhost:8000`).

Mobile
- Phone view includes a bottom nav; chat button floats above the nav.

