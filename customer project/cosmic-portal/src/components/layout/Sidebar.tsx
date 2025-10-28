"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/Card";
import { MessageSquare, CreditCard, ShoppingCart, LayoutGrid } from "lucide-react";

const nav = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutGrid },
  { href: "/queries", label: "Queries", icon: MessageSquare },
  { href: "/orders", label: "Orders", icon: ShoppingCart },
  { href: "/wallet", label: "Wallet", icon: CreditCard },
];

export function Sidebar() {
  const pathname = usePathname();
  return (
    <aside className="hidden md:flex md:w-72 flex-col gap-6 p-6 glass-panel sidebar-surface ambient-blur text-brand-secondary dark:text-white/90">
      <div>
        <div className="text-gradient font-[var(--font-heading)] text-2xl font-extrabold tracking-tight">Cosmic Trading</div>
        <Badge className="mt-3 bg-white/90 text-brand-secondary shadow-none">Customer Portal</Badge>
      </div>
      <nav className="flex-1 space-y-2">
        {nav.map(({ href, label, icon: Icon }) => {
          const active = pathname.startsWith(href);
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                "group flex items-center gap-3 rounded-2xl px-4 py-3 text-base font-semibold transition-all",
                active
                  ? "bg-white text-brand-secondary shadow-xl shadow-[rgba(255,122,50,0.18)] dark:bg-white/10 dark:text-white"
                  : "text-[#2b48c6] bg-white/70 hover:bg-white/90 hover:text-black dark:text-[#7aa2ff] dark:bg-white/8 dark:hover:bg-white/14 dark:hover:text-white"
              )}
            >
              <Icon
                size={20}
                className={cn(
                  active
                    ? "text-brand-primary"
                    : "text-[#2b48c6] group-hover:text-black dark:text-[#7aa2ff] dark:group-hover:text-white"
                )}
              />
              <span>{label}</span>
            </Link>
          );
        })}
      </nav>
      <div className="text-xs uppercase tracking-[0.3em] text-brand-muted">v2.0</div>
    </aside>
  );
}
