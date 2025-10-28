Cosmic Trading Customer Portal v2.0

Overview
- Next.js 14 (App Router) + TypeScript
- Tailwind CSS (v4 inline theme)
- Framer Motion, Radix UI, React Query, Axios, next-themes
- Clerk.js ready (provider included; add env keys to enable)

Quick Start
- Copy `.env.local.example` to `.env.local` and set values
- Run `npm run dev` to start local dev
- Build with `npm run build` and `npm run start`

Structure
- `src/app/*` routes: dashboard, queries, orders, wallet
- `src/components/layout/*` header, sidebar, shell
- `src/components/chat/ChatPopup.tsx` floating chat popup
- `src/components/ui/*` small UI primitives
- `src/lib/*` axios client, query client, utils
- `src/types/*` shared types

Theme
- Brand palette via CSS variables (`globals.css`)
- Light/dark via `next-themes` and class toggling

Integrations
- API: set `NEXT_PUBLIC_API_BASE_URL` to your PHP `/api` base
- Clerk: add publishable/secret keys and wrap protected routes as needed

Notes
- Chat popup currently simulates replies. Hook it to `/add_customer_message.php` for production.
- Query form is wired for progressive disclosure and basic validation; integrate submit to `/create_query.php`.

