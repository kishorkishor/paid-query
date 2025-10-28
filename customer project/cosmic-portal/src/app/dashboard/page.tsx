import Link from "next/link";
import { AppShell } from "@/components/layout/AppShell";
import { Card, CardContent, CardHeader, Badge } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { formatCurrency } from "@/lib/utils";
import { Plus } from "lucide-react";

const stats = [
  { label: "Total Queries", value: 128, color: "blue" as const },
  { label: "New", value: 12, color: "orange" as const },
  { label: "Assigned", value: 24, color: "green" as const },
  { label: "In Process", value: 9, color: "blue" as const },
  { label: "Red Flags", value: 2, color: "red" as const },
];

export default function DashboardPage() {
  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8 flex flex-col gap-6">
        <div className="flex items-center justify-between gap-6 flex-wrap">
          <div>
            <h1 className="font-[var(--font-heading)] text-2xl font-semibold">Welcome back</h1>
            <p className="text-sm text-neutral-500">Your latest activity at a glance</p>
          </div>
          <div className="flex gap-3">
            <Link href="/wallet">
              <Button variant="outline" className="hover:-translate-y-0.5 transition-transform">
                Top Up Wallet
              </Button>
            </Link>
          </div>
        </div>

        <section className="glass-card p-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between border border-brand-border/40 shadow-[0_20px_55px_rgba(255,122,50,0.18)]">
          <div>
            <h2 className="font-[var(--font-heading)] text-xl font-semibold text-brand-secondary">
              Launch a new sourcing query
            </h2>
            <p className="text-sm text-brand-muted">
              Tell us what you need and get matched with the right suppliers in minutes.
            </p>
          </div>
          <Link href="/queries/new">
            <Button className="brand-gradient-ember px-6 h-12 rounded-full shadow-[0_18px_45px_rgba(255,122,50,0.28)] hover:shadow-[0_22px_55px_rgba(255,122,50,0.38)]">
              <Plus size={18} />
              New Query
            </Button>
          </Link>
        </section>

        <section className="grid gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-5">
          {stats.map((s) => (
            <Card key={s.label}>
              <CardHeader className="pb-1 flex items-center justify-between">
                <span className="text-sm text-neutral-500">{s.label}</span>
                <Badge color={s.color}>{s.color.toUpperCase()}</Badge>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold">{s.value}</div>
              </CardContent>
            </Card>
          ))}
        </section>

        <section className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <h3 className="font-semibold">Recent Queries</h3>
                <Button variant="ghost">View All</Button>
              </div>
            </CardHeader>
            <CardContent>
              <ul className="space-y-3">
                {[1, 2, 3, 4].map((i) => (
                  <li key={i} className="flex items-center justify-between">
                    <div>
                      <div className="font-medium">Smartwatch bulk order</div>
                      <div className="text-xs text-neutral-500">Added today - Qty 100</div>
                    </div>
                    <Badge color={i % 2 ? "orange" : "blue"}>{i % 2 ? "NEW" : "ASSIGNED"}</Badge>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <h3 className="font-semibold">Wallet</h3>
                <Button variant="ghost">View</Button>
              </div>
            </CardHeader>
            <CardContent>
              <div className="text-sm text-neutral-500">Current Balance</div>
              <div className="text-4xl font-bold mt-1">{formatCurrency(2570)}</div>
              <div className="mt-4 grid grid-cols-2 gap-3">
                <Button>Top Up</Button>
                <Button variant="outline">History</Button>
              </div>
            </CardContent>
          </Card>
        </section>
      </div>

    </AppShell>
  );
}
