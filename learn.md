# Frontend Development Guide - Cosmic Trade Platform

> **Complete reference for building the Next.js frontend that integrates with the existing PHP backend on Hostinger**

---

## 🎯 Project Overview

This document contains all the information needed to build a modern Next.js frontend that connects to the existing PHP backend hosted on Hostinger. The frontend will handle customer queries, order management, wallet payments, and messaging.

---

## 🛠️ Tech Stack (MUST USE)

### Core Framework
- **Next.js 14.0** - React framework with App Router
- **React 18.2** - UI library
- **TypeScript 5.2** - Type safety

### Styling & UI
- **Tailwind CSS 3.3** - Utility-first CSS framework
- **Framer Motion 10.16** - Animation library
- **Lucide React 0.294** - Icon library
- **clsx 2.0** - Conditional className utility

### Forms & Validation
- **React Hook Form 7.61** - Form management
- **React Hot Toast 2.4** - Toast notifications

### Authentication
- **@clerk/nextjs** - Authentication (already configured in backend)

### UI Components & Interactions
- **Embla Carousel React 8.0** - Carousel/slider components
- **Swiper 11.0** - Touch slider
- **React Intersection Observer 9.5** - Scroll animations

### Tailwind Plugins (Required)
- **@tailwindcss/forms 0.5** - Form styling
- **@tailwindcss/typography 0.5** - Typography utilities
- **@tailwindcss/aspect-ratio 0.4** - Aspect ratio utilities

---

## 🎨 Theme System (MUST FOLLOW)

### Color Palette

#### Light Mode Colors
```css
--brand-primary: #E3431F      /* Logo Orange-Red - CTAs, icons, headlines */
--brand-secondary: #000000    /* Deep Black - text, footer, strong contrast */
--brand-accent: #F2F2F2       /* Neutral Gray - dividers, form fields */
--brand-background: #FFFFFF   /* Clean White - general background */
```

#### Dark Mode Colors
```css
--dw-bg: #0f1115              /* background base */
--dw-bg-elevated: #171922     /* elevated surfaces */
--dw-bg-surface: #1d2030      /* surface layer */
--dw-border: #272a3a          /* subtle borders */
--dw-text: #e7eaf3            /* primary text */
--dw-text-muted: #b7bbcc      /* muted text */
--dw-primary: #3b82f6         /* electric blue */
--dw-primary-600: #2563eb     /* hover state */
--dw-accent: #22d3ee          /* cyan accent */
```

### Typography
- **Font Sans**: 'Inter' (body text)
- **Font Heading**: 'Poppins' (headings)
- Import from Google Fonts: `https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800;900&display=swap`

### Component Styles (Use These Classes)

#### Buttons
```css
/* Primary Button */
.btn-primary {
  @apply inline-flex items-center justify-center px-6 py-3 text-base font-medium rounded-lg transition-all duration-200;
  @apply bg-brand-primary text-white hover:bg-opacity-90 focus:ring-brand-primary shadow-brand;
}

/* Dark Mode Primary Button */
.dark .btn-primary {
  background: #3b82f6 !important;
  color: #ffffff !important;
}
.dark .btn-primary:hover {
  background: #2563eb !important;
  box-shadow: 0 0 20px rgba(59, 130, 246, 0.3) !important;
}
```

#### Cards
```css
.card {
  @apply bg-white rounded-2xl shadow-soft border border-gray-100 overflow-hidden;
}

.dark .card {
  background: linear-gradient(180deg, rgba(29,32,48,0.95), rgba(23,25,34,0.95)) !important;
  border: 1px solid var(--dw-border) !important;
  box-shadow: 0 12px 30px rgba(0,0,0,0.5) !important;
}
```

#### Inputs
```css
.input {
  @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent transition-colors duration-200 bg-white text-brand-secondary placeholder-gray-400;
}

.dark .input {
  background: var(--dw-bg-elevated) !important;
  border: 1px solid var(--dw-border) !important;
  color: var(--dw-text) !important;
}
```

---

## 🔌 Backend API Integration

### Backend Configuration
- **Database**: MySQL (u966125597_cosmictrd)
- **Authentication**: Clerk JWT tokens
- **Auth Methods**: 
  - Bearer token in `Authorization` header
  - OR `__session` cookie
- **CORS**: Currently configured for `https://cosmictrd.io`
- **Clerk Issuer**: `https://suited-grouper-99.clerk.accounts.dev`

### Environment Variables Required
```env
# Backend API
NEXT_PUBLIC_API_BASE_URL=https://cosmictrd.io
# OR your Hostinger domain

# Clerk Authentication
NEXT_PUBLIC_CLERK_PUBLISHABLE_KEY=pk_test_...
CLERK_SECRET_KEY=sk_test_...

# Must match backend Clerk configuration
NEXT_PUBLIC_CLERK_ISSUER=https://suited-grouper-99.clerk.accounts.dev
```

---

## 📡 API Endpoints Reference

### 1. Countries (Public)
**Endpoint**: `GET /api/get_countries.php`  
**Auth**: None required  
**Response**:
```json
{
  "ok": true,
  "countries": [
    {"id": 1, "name": "United States"},
    {"id": 2, "name": "Canada"}
  ]
}
```

### 2. Create Query (Authenticated)
**Endpoint**: `POST /api/create_query.php`  
**Auth**: Required (Clerk JWT)  
**Content-Type**: `multipart/form-data` (for file uploads)

**Required Fields**:
- `customer_name` (string)
- `phone` (string)
- `product_details` (string) - Service details
- `country_id` (integer)

**Optional Fields**:
- `query_type` (string) - defaults to "other"
- `shipping_mode` (string)
- `product_name` (string)
- `product_links` (string)
- `quantity` (string)
- `budget` (float)
- `label_type` (string)
- `carton_count` (integer)
- `cbm` (float)
- `address` (string)
- `notes` (string)
- `attachments[]` (file array) - Multiple file uploads

**Response**:
```json
{
  "success": true,
  "query_id": 123,
  "query_code": "Q-24X7A9",
  "current_team_id": 1,
  "query_type": "other"
}
```

### 3. Get My Queries (Authenticated)
**Endpoint**: `GET /api/my_queries.php`  
**Auth**: Required (Clerk JWT)  
**Response**:
```json
{
  "ok": true,
  "rows": [
    {
      "id": 123,
      "query_code": "Q-24X7A9",
      "status": "new",
      "priority": "normal",
      "query_type": "other",
      "created_at": "2024-01-15 10:30:00",
      "team_name": "Sales Team"
    }
  ]
}
```

### 4. Get Query Details (Public)
**Endpoint**: `GET /api/query_details.php?id={query_id}`  
**Auth**: None (but should validate ownership in frontend)  
**Response**:
```json
{
  "ok": true,
  "query": {
    "id": 123,
    "query_code": "Q-24X7A9",
    "customer_name": "John Doe",
    "phone": "+1234567890",
    "product_details": "Product description",
    "country_id": 1,
    "status": "new",
    "priority": "normal",
    "created_at": "2024-01-15 10:30:00"
  },
  "messages": [
    {
      "id": 1,
      "direction": "incoming",
      "medium": "email",
      "body": "Message content",
      "sender_admin_id": null,
      "sender_clerk_user_id": "user_xxx",
      "created_at": "2024-01-15 10:35:00"
    }
  ]
}
```

### 5. Add Customer Message (Authenticated)
**Endpoint**: `POST /api/add_customer_message.php`  
**Auth**: Required (Clerk JWT)  
**Body**:
```json
{
  "query_id": 123,
  "body": "Message text",
  "medium": "email"
}
```

### 6. Wallet Capture Payment (Authenticated)
**Endpoint**: `POST /api/wallet_capture.php`  
**Auth**: Required (via session or Clerk)  
**Body** (Manual Amount):
```json
{
  "order_id": 456,
  "amount": 100.00
}
```
**Body** (Selected Cartons):
```json
{
  "order_id": 456,
  "carton_ids": [1, 2, 3]
}
```
**Response**:
```json
{
  "ok": true,
  "wallet_balance": 500.00,
  "order_due": 50.00,
  "payment_id": 789,
  "cartons_paid": 3
}
```

### 7. Wallet Capture Shipping (Authenticated)
**Endpoint**: `POST /api/wallet_capture_shipping.php`  
**Auth**: Required  
**Similar to wallet_capture.php but for shipping payments**

---

## 🏗️ Project Structure

```
frontend/
├── app/
│   ├── layout.tsx                 # Root layout with Clerk provider
│   ├── page.tsx                   # Home page
│   ├── (auth)/
│   │   ├── sign-in/[[...sign-in]]/page.tsx
│   │   └── sign-up/[[...sign-up]]/page.tsx
│   ├── queries/
│   │   ├── page.tsx               # List all queries
│   │   ├── new/page.tsx           # Create new query
│   │   └── [id]/page.tsx          # Query details & messages
│   ├── orders/
│   │   ├── page.tsx               # List orders
│   │   └── [id]/page.tsx          # Order details
│   └── wallet/
│       └── page.tsx               # Wallet & payments
├── components/
│   ├── ui/
│   │   ├── Button.tsx
│   │   ├── Card.tsx
│   │   ├── Input.tsx
│   │   ├── ThemeToggle.tsx
│   │   └── Toast.tsx
│   ├── forms/
│   │   ├── QueryForm.tsx
│   │   └── MessageForm.tsx
│   ├── queries/
│   │   ├── QueryList.tsx
│   │   ├── QueryCard.tsx
│   │   └── QueryDetails.tsx
│   └── layout/
│       ├── Header.tsx
│       ├── Footer.tsx
│       └── Sidebar.tsx
├── lib/
│   ├── api.ts                     # API client
│   ├── types.ts                   # TypeScript types
│   └── utils.ts                   # Utility functions
├── hooks/
│   ├── useTheme.ts                # Theme management
│   ├── useQueries.ts              # Query data fetching
│   └── useWallet.ts               # Wallet operations
├── middleware.ts                  # Clerk auth middleware
└── next.config.js                 # Next.js config with security headers
```

---

## 🔐 Authentication Implementation

### 1. Install Clerk
```bash
npm install @clerk/nextjs
```

### 2. Middleware Setup
```typescript
// middleware.ts
import { authMiddleware } from "@clerk/nextjs";

export default authMiddleware({
  publicRoutes: ["/", "/sign-in", "/sign-up"],
});

export const config = {
  matcher: ["/((?!.+\\.[\\w]+$|_next).*)", "/", "/(api|trpc)(.*)"],
};
```

### 3. Root Layout with Clerk Provider
```typescript
// app/layout.tsx
import { ClerkProvider } from '@clerk/nextjs';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <ClerkProvider>
      <html lang="en">
        <body>{children}</body>
      </html>
    </ClerkProvider>
  );
}
```

### 4. Get Auth Token for API Calls
```typescript
import { useAuth } from '@clerk/nextjs';

const { getToken } = useAuth();
const token = await getToken();
```

---

## 📝 API Client Implementation

### lib/api.ts
```typescript
import { toast } from 'react-hot-toast';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'https://cosmictrd.io';

interface ApiOptions extends RequestInit {
  token?: string;
}

class ApiClient {
  private baseUrl: string;

  constructor(baseUrl: string) {
    this.baseUrl = baseUrl;
  }

  private async request<T>(
    endpoint: string,
    options: ApiOptions = {}
  ): Promise<T> {
    const { token, ...fetchOptions } = options;

    const headers: HeadersInit = {
      ...fetchOptions.headers,
    };

    // Add auth token if provided
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    // Don't set Content-Type for FormData (browser will set it with boundary)
    if (!(fetchOptions.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }

    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, {
        ...fetchOptions,
        headers,
        credentials: 'include', // Include cookies for __session
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Request failed');
      }

      return data;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'An error occurred';
      toast.error(message);
      throw error;
    }
  }

  // Countries (public)
  async getCountries() {
    return this.request<{ ok: boolean; countries: Array<{ id: number; name: string }> }>(
      '/api/get_countries.php'
    );
  }

  // Create query (authenticated)
  async createQuery(formData: FormData, token: string) {
    return this.request<{
      success: boolean;
      query_id: number;
      query_code: string;
      current_team_id: number;
      query_type: string;
    }>('/api/create_query.php', {
      method: 'POST',
      body: formData,
      token,
    });
  }

  // Get my queries (authenticated)
  async getMyQueries(token: string) {
    return this.request<{
      ok: boolean;
      rows: Array<{
        id: number;
        query_code: string;
        status: string;
        priority: string;
        query_type: string;
        created_at: string;
        team_name: string;
      }>;
    }>('/api/my_queries.php', {
      token,
    });
  }

  // Get query details
  async getQueryDetails(queryId: number) {
    return this.request<{
      ok: boolean;
      query: any;
      messages: Array<any>;
    }>(`/api/query_details.php?id=${queryId}`);
  }

  // Add customer message (authenticated)
  async addCustomerMessage(
    data: { query_id: number; body: string; medium: string },
    token: string
  ) {
    return this.request('/api/add_customer_message.php', {
      method: 'POST',
      body: JSON.stringify(data),
      token,
    });
  }

  // Wallet capture payment (authenticated)
  async captureWalletPayment(
    data: { order_id: number; amount?: number; carton_ids?: number[] },
    token: string
  ) {
    const formData = new FormData();
    formData.append('order_id', data.order_id.toString());
    if (data.amount) formData.append('amount', data.amount.toString());
    if (data.carton_ids) {
      data.carton_ids.forEach(id => formData.append('carton_ids[]', id.toString()));
    }

    return this.request<{
      ok: boolean;
      wallet_balance: number;
      order_due: number;
      payment_id: number;
      cartons_paid: number;
    }>('/api/wallet_capture.php', {
      method: 'POST',
      body: formData,
      token,
    });
  }
}

export const api = new ApiClient(API_BASE_URL);
```

---

## 🎨 Component Examples

### Query Creation Form
```typescript
// components/forms/QueryForm.tsx
'use client';

import { useState } from 'react';
import { useAuth } from '@clerk/nextjs';
import { useForm } from 'react-hook-form';
import { toast } from 'react-hot-toast';
import { api } from '@/lib/api';

interface QueryFormData {
  customer_name: string;
  phone: string;
  product_details: string;
  country_id: number;
  query_type?: string;
  // ... other optional fields
}

export function QueryForm() {
  const { getToken } = useAuth();
  const { register, handleSubmit, formState: { errors } } = useForm<QueryFormData>();
  const [files, setFiles] = useState<FileList | null>(null);
  const [loading, setLoading] = useState(false);

  const onSubmit = async (data: QueryFormData) => {
    setLoading(true);
    try {
      const token = await getToken();
      if (!token) throw new Error('Not authenticated');

      const formData = new FormData();
      Object.entries(data).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          formData.append(key, value.toString());
        }
      });

      // Add files
      if (files) {
        Array.from(files).forEach((file) => {
          formData.append('attachments[]', file);
        });
      }

      const result = await api.createQuery(formData, token);
      toast.success(`Query created: ${result.query_code}`);
      // Redirect to query details
    } catch (error) {
      console.error('Failed to create query:', error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      <div>
        <label className="block text-sm font-medium mb-2">Customer Name *</label>
        <input
          {...register('customer_name', { required: 'Name is required' })}
          className="input"
          placeholder="Enter your name"
        />
        {errors.customer_name && (
          <p className="text-red-500 text-sm mt-1">{errors.customer_name.message}</p>
        )}
      </div>

      <div>
        <label className="block text-sm font-medium mb-2">Phone *</label>
        <input
          {...register('phone', { required: 'Phone is required' })}
          className="input"
          placeholder="+1234567890"
        />
      </div>

      <div>
        <label className="block text-sm font-medium mb-2">Service Details *</label>
        <textarea
          {...register('product_details', { required: 'Details are required' })}
          className="input"
          rows={4}
          placeholder="Describe what you need..."
        />
      </div>

      <div>
        <label className="block text-sm font-medium mb-2">Attachments</label>
        <input
          type="file"
          multiple
          onChange={(e) => setFiles(e.target.files)}
          className="input"
        />
      </div>

      <button
        type="submit"
        disabled={loading}
        className="btn-primary w-full"
      >
        {loading ? 'Creating...' : 'Create Query'}
      </button>
    </form>
  );
}
```

### Query List Component
```typescript
// components/queries/QueryList.tsx
'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@clerk/nextjs';
import { api } from '@/lib/api';
import Link from 'next/link';

export function QueryList() {
  const { getToken } = useAuth();
  const [queries, setQueries] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function fetchQueries() {
      try {
        const token = await getToken();
        if (!token) return;

        const result = await api.getMyQueries(token);
        setQueries(result.rows);
      } catch (error) {
        console.error('Failed to fetch queries:', error);
      } finally {
        setLoading(false);
      }
    }

    fetchQueries();
  }, [getToken]);

  if (loading) {
    return <div className="text-center py-12">Loading...</div>;
  }

  return (
    <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
      {queries.map((query) => (
        <Link
          key={query.id}
          href={`/queries/${query.id}`}
          className="card card-hover p-6"
        >
          <div className="flex items-center justify-between mb-4">
            <span className="text-sm font-mono text-gray-500 dark:text-gray-400">
              {query.query_code}
            </span>
            <span className={`px-3 py-1 rounded-full text-xs font-medium ${
              query.status === 'new' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' :
              query.status === 'in_progress' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
              'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
            }`}>
              {query.status}
            </span>
          </div>
          <h3 className="font-semibold text-lg mb-2">{query.query_type}</h3>
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Team: {query.team_name}
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-500">
            Created: {new Date(query.created_at).toLocaleDateString()}
          </p>
        </Link>
      ))}
    </div>
  );
}
```

---

## 🎨 Next.js Configuration with Security Headers

### next.config.js
```javascript
/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: '**',
      },
    ],
  },
  poweredByHeader: false,
  reactStrictMode: true,
  compress: true,
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          {
            key: 'X-Frame-Options',
            value: 'DENY',
          },
          {
            key: 'X-Content-Type-Options',
            value: 'nosniff',
          },
          {
            key: 'Referrer-Policy',
            value: 'strict-origin-when-cross-origin',
          },
          {
            key: 'X-XSS-Protection',
            value: '1; mode=block',
          },
          ...(process.env.NODE_ENV === 'production'
            ? [
                {
                  key: 'Strict-Transport-Security',
                  value: 'max-age=31536000; includeSubDomains; preload',
                },
                {
                  key: 'Content-Security-Policy',
                  value: "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' wss: https:; frame-ancestors 'none';",
                },
                {
                  key: 'Permissions-Policy',
                  value: 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
                },
              ]
            : []),
        ],
      },
    ];
  },
};

module.exports = nextConfig;
```

---

## 🎯 Key Implementation Steps

### 1. Initialize Project
```bash
npx create-next-app@14.0.0 frontend --typescript --tailwind --app
cd frontend
```

### 2. Install Dependencies
```bash
npm install @clerk/nextjs framer-motion lucide-react clsx react-hook-form react-hot-toast
npm install @tailwindcss/forms @tailwindcss/typography @tailwindcss/aspect-ratio
npm install embla-carousel-react swiper react-intersection-observer
```

### 3. Configure Tailwind (tailwind.config.js)
```javascript
module.exports = {
  content: [
    './pages/**/*.{js,ts,jsx,tsx,mdx}',
    './components/**/*.{js,ts,jsx,tsx,mdx}',
    './app/**/*.{js,ts,jsx,tsx,mdx}',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          primary: '#E3431F',
          secondary: '#000000',
          accent: '#F2F2F2',
          background: '#FFFFFF',
        },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        heading: ['Poppins', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
        'medium': '0 4px 25px -5px rgba(0, 0, 0, 0.1), 0 20px 25px -5px rgba(0, 0, 0, 0.04)',
        'brand': '0 4px 25px -5px rgba(227, 67, 31, 0.2), 0 20px 25px -5px rgba(227, 67, 31, 0.1)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
    require('@tailwindcss/aspect-ratio'),
  ],
};
```

### 4. Add Global Styles (app/globals.css)
```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800;900&display=swap');

@layer base {
  html {
    @apply scroll-smooth;
  }
  
  body {
    @apply bg-brand-background text-brand-secondary font-sans antialiased;
    font-feature-settings: 'rlig' 1, 'calt' 1;
    transition: background-color 0.3s ease, color 0.3s ease;
  }
}

@layer components {
  .btn-primary {
    @apply inline-flex items-center justify-center px-6 py-3 text-base font-medium rounded-lg transition-all duration-200;
    @apply bg-brand-primary text-white hover:bg-opacity-90 focus:ring-2 focus:ring-brand-primary focus:ring-offset-2;
  }

  .card {
    @apply bg-white rounded-2xl shadow-soft border border-gray-100 overflow-hidden;
  }

  .card-hover {
    @apply card transition-all duration-300 hover:shadow-medium hover:-translate-y-1;
  }

  .input {
    @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-transparent transition-colors duration-200 bg-white text-brand-secondary placeholder-gray-400;
  }
}

/* Dark Mode Styles */
.dark {
  --dw-bg: #0f1115;
  --dw-bg-elevated: #171922;
  --dw-bg-surface: #1d2030;
  --dw-border: #272a3a;
  --dw-text: #e7eaf3;
  --dw-text-muted: #b7bbcc;
  --dw-primary: #3b82f6;
  --dw-primary-600: #2563eb;
  --dw-accent: #22d3ee;
  color-scheme: dark;
}

.dark body {
  background: radial-gradient(1200px 600px at 110% -10%, rgba(59,130,246,0.06), transparent 60%),
              radial-gradient(900px 400px at -10% 110%, rgba(34,211,238,0.05), transparent 60%),
              var(--dw-bg) !important;
  color: var(--dw-text);
}

.dark .btn-primary {
  background: #3b82f6 !important;
  color: #ffffff !important;
}

.dark .btn-primary:hover {
  background: #2563eb !important;
  box-shadow: 0 0 20px rgba(59, 130, 246, 0.3) !important;
}

.dark .card {
  background: linear-gradient(180deg, rgba(29,32,48,0.95), rgba(23,25,34,0.95)) !important;
  border: 1px solid var(--dw-border) !important;
  box-shadow: 0 12px 30px rgba(0,0,0,0.5) !important;
}

.dark .input {
  background: var(--dw-bg-elevated) !important;
  border: 1px solid var(--dw-border) !important;
  color: var(--dw-text) !important;
}

.dark .input:focus {
  border-color: var(--dw-primary) !important;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.15) !important;
}
```

### 5. Set Up Environment Variables (.env.local)
```env
NEXT_PUBLIC_API_BASE_URL=https://cosmictrd.io
NEXT_PUBLIC_CLERK_PUBLISHABLE_KEY=pk_test_...
CLERK_SECRET_KEY=sk_test_...
```

---

## 🚀 Deployment Checklist

### Before Deployment
- [ ] Update CORS in backend `api/config.php` to include frontend domain
- [ ] Set all environment variables in deployment platform
- [ ] Test all API endpoints with authentication
- [ ] Verify file uploads work correctly
- [ ] Test dark mode toggle
- [ ] Check responsive design on mobile
- [ ] Verify security headers are applied

### Deployment Options
1. **Vercel** (Recommended)
   - Connect GitHub repo
   - Auto-deploys on push
   - Built-in SSL
   - Edge functions support

2. **Netlify**
   - Similar to Vercel
   - Good Next.js support

3. **Hostinger** (if Node.js hosting available)
   - Same host as backend
   - May need custom configuration

---

## 📋 Feature Checklist

### Core Features
- [ ] User authentication with Clerk
- [ ] Create new queries with file uploads
- [ ] View list of user's queries
- [ ] View query details with messages
- [ ] Add messages to queries
- [ ] View wallet balance
- [ ] Process wallet payments
- [ ] Dark mode toggle
- [ ] Responsive design
- [ ] Toast notifications
- [ ] Loading states
- [ ] Error handling

### Nice-to-Have Features
- [ ] Real-time message updates
- [ ] File preview before upload
- [ ] Query search and filters
- [ ] Export query data
- [ ] Payment history
- [ ] Email notifications
- [ ] Multi-language support

---

## 🎨 Design Guidelines

### Spacing
- Use consistent spacing: 4, 6, 8, 12, 16, 24, 32, 48, 64px
- Container max-width: 1280px (max-w-7xl)
- Section padding: py-16 md:py-24

### Typography
- Headings: font-heading (Poppins)
- Body: font-sans (Inter)
- Line height: 1.5 for body, 1.2 for headings

### Colors
- Use brand colors for CTAs and important elements
- Use gray scale for neutral elements
- Maintain proper contrast ratios (WCAG AA)

### Animations
- Use Framer Motion for complex animations
- Keep transitions smooth (200-300ms)
- Respect `prefers-reduced-motion`

---

## 🔍 Testing Guide

### Manual Testing
1. Sign up / Sign in flow
2. Create query with and without files
3. View query list
4. View query details
5. Add message to query
6. Test wallet payment
7. Toggle dark mode
8. Test on mobile devices

### API Testing
```bash
# Test countries endpoint
curl https://cosmictrd.io/api/get_countries.php

# Test authenticated endpoint (replace TOKEN)
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://cosmictrd.io/api/my_queries.php
```

---

## 📞 Support & Resources

### Backend Info
- **Database**: u966125597_cosmictrd
- **Clerk Issuer**: https://suited-grouper-99.clerk.accounts.dev
- **CORS Domain**: https://cosmictrd.io

### Documentation Links
- [Next.js Docs](https://nextjs.org/docs)
- [Clerk Docs](https://clerk.com/docs)
- [Tailwind CSS](https://tailwindcss.com/docs)
- [Framer Motion](https://www.framer.com/motion/)

---

## 🎯 Quick Start Command

```bash
# Create project
npx create-next-app@14.0.0 cosmic-trade-frontend --typescript --tailwind --app

# Install all dependencies
cd cosmic-trade-frontend
npm install @clerk/nextjs framer-motion lucide-react clsx react-hook-form react-hot-toast @tailwindcss/forms @tailwindcss/typography @tailwindcss/aspect-ratio embla-carousel-react swiper react-intersection-observer

# Create necessary directories
mkdir -p app/queries/new app/queries/[id] app/orders app/wallet components/ui components/forms components/queries components/layout lib hooks

# Start development
npm run dev
```

---

**Last Updated**: January 2025  
**Version**: 1.0.0  
**Backend Version**: PHP/MySQL on Hostinger  
**Frontend Stack**: Next.js 14 + TypeScript + Tailwind CSS

