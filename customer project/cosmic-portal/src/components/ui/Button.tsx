"use client";
import { cn } from "@/lib/utils";
import { Slot } from "@radix-ui/react-slot";
import { forwardRef } from "react";

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  asChild?: boolean;
  variant?: "primary" | "secondary" | "outline" | "ghost";
  size?: "sm" | "md" | "lg";
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ asChild, className, variant = "primary", size = "md", ...props }, ref) => {
    const Comp = asChild ? Slot : "button";
    return (
      <Comp
        ref={ref}
        className={cn(
          "inline-flex items-center justify-center gap-2 rounded-[var(--radius)] font-medium transition-all",
          "active:scale-[.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary",
          {
            primary:
              "brand-gradient text-white shadow-[0_16px_40px_rgba(255,122,50,0.32)] hover:shadow-[0_22px_56px_rgba(255,122,50,0.42)] hover:-translate-y-0.5 transition-transform",
            secondary:
              "bg-white/90 dark:bg-white/10 border border-white/70 dark:border-white/15 text-brand-secondary dark:text-white/90 hover:bg-white hover:-translate-y-0.5 transition-transform",
            outline:
              "border border-[#ffd1ad] dark:border-white/20 text-brand-secondary dark:text-white/90 hover:bg-white/70 dark:hover:bg-white/10",
            ghost:
              "text-brand-muted hover:text-brand-secondary dark:text-white/70 dark:hover:text-white/90 hover:bg-white/40 dark:hover:bg-white/5",
          }[variant],
          {
            sm: "h-9 px-3 text-sm",
            md: "h-11 px-4 text-sm",
            lg: "h-12 px-6 text-base",
          }[size],
          className
        )}
        {...props}
      />
    );
  }
);
Button.displayName = "Button";
