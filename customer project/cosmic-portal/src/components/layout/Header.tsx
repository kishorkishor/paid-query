"use client";
import { ThemeToggle } from "@/components/ui/ThemeToggle";
import { Button } from "@/components/ui/Button";
import { Bell } from "lucide-react";

export function Header() {
  return (
    <header className="sticky top-0 z-40 px-4 md:px-10 pt-4 text-brand-secondary dark:text-white">
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-2 md:hidden text-gradient font-semibold">Cosmic</div>
        <div className="glass-panel flex flex-wrap items-center justify-between gap-4 rounded-[32px] border border-white/60 dark:border-white/10 px-5 md:px-8 py-4 shadow-[0_25px_70px_rgba(0,0,0,0.08)] dark:shadow-[0_25px_70px_rgba(0,0,0,0.45)] text-brand-secondary dark:text-white">
                 <div className="flex items-center gap-4">
            <div className="h-12 w-12 rounded-[20px] brand-gradient text-white font-semibold grid place-items-center shadow-lg shadow-orange-400/40">
              CT
            </div>
                   <div>
                     <p className="text-xs uppercase tracking-[0.4em] text-brand-muted dark:text-white/70 header-subtitle">Customer Portal</p>
                     <h1 className="text-lg font-semibold text-black dark:text-white header-title">Cosmic Trading</h1>
                   </div>
          </div>

          <div className="flex items-center gap-3">
                   <div className="hidden sm:flex items-center gap-2 rounded-2xl bg-white/70 dark:bg-white/5 border border-white/60 dark:border-white/15 px-3 py-1.5 text-xs font-semibold text-black dark:text-white/80 header-chip">
              <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />
              Live Support
            </div>
                   <Button variant="ghost" className="h-10 w-10 p-0 rounded-full text-black dark:text-white" aria-label="Notifications">
              <Bell size={18} />
            </Button>
                   <div className="hidden sm:flex h-10 w-10 items-center justify-center rounded-full border border-white/70 dark:border-white/20 bg-white/80 dark:bg-white/10 text-sm font-semibold text-brand-secondary dark:text-white/90 header-initials">
              KC
            </div>
            <ThemeToggle />
          </div>
        </div>
      </div>
    </header>
  );
}
