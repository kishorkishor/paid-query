import { Sidebar } from "./Sidebar";
import { Header } from "./Header";
import { ChatPopup } from "@/components/chat/ChatPopup";
import { ReactNode } from "react";
import Link from "next/link";
import { LayoutGrid, MessageSquare, ShoppingCart, CreditCard } from "lucide-react";

export function AppShell({ children }: { children: ReactNode }) {
  return (
    <div className="relative min-h-dvh overflow-hidden">
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -top-32 -left-10 h-72 w-72 rounded-full bg-brand-primary/18 blur-[160px]" />
        <div className="absolute top-1/3 left-1/2 -translate-x-1/2 h-96 w-96 rounded-full bg-white/40 blur-[180px]" />
        <div className="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-[#ffd5c0]/25 blur-[240px] dark:bg-[#ff7a52]/20" />
      </div>

      <div className="relative z-10 grid min-h-dvh gap-4 md:grid-cols-[17rem_1fr] grid-rows-[auto_1fr]">
        <div className="md:col-span-2">
          <Header />
        </div>
        <Sidebar />
        <main className="p-4 pb-24 md:pb-8 md:p-8">
          <div className="space-y-6 max-w-6xl w-full mx-auto">
            {children}
          </div>
        </main>
      </div>

      {/* Mobile bottom nav */}
      <nav className="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-white/90 dark:bg-[#0b101d]/95 border-t border-white/60 dark:border-white/10 backdrop-blur supports-[backdrop-filter]:backdrop-blur-sm safe-area-inset-bottom">
        <div className="mx-auto max-w-3xl grid grid-cols-4">
          <Link href="/dashboard" className="flex flex-col items-center py-3 text-xs font-semibold text-[#2b48c6] dark:text-[#7aa2ff]">
            <LayoutGrid size={18} />
            <span>Home</span>
          </Link>
          <Link href="/queries" className="flex flex-col items-center py-3 text-xs font-semibold text-[#2b48c6] dark:text-[#7aa2ff]">
            <MessageSquare size={18} />
            <span>Queries</span>
          </Link>
          <Link href="/orders" className="flex flex-col items-center py-3 text-xs font-semibold text-[#2b48c6] dark:text-[#7aa2ff]">
            <ShoppingCart size={18} />
            <span>Orders</span>
          </Link>
          <Link href="/wallet" className="flex flex-col items-center py-3 text-xs font-semibold text-[#2b48c6] dark:text-[#7aa2ff]">
            <CreditCard size={18} />
            <span>Wallet</span>
          </Link>
        </div>
      </nav>

      <ChatPopup />
    </div>
  );
}
