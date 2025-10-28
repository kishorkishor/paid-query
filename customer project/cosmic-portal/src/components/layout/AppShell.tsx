import { Sidebar } from "./Sidebar";
import { Header } from "./Header";
import { ChatPopup } from "@/components/chat/ChatPopup";
import { ReactNode } from "react";

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
        <main className="p-4 md:p-8">
          <div className="space-y-6 max-w-6xl w-full mx-auto">
            {children}
          </div>
        </main>
      </div>

      <ChatPopup />
    </div>
  );
}
