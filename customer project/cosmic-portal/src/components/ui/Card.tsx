import { cn } from "@/lib/utils";

export function Card({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("glass-card text-brand-secondary dark:text-white/90", className)} {...props} />;
}

export function CardHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("p-5 pb-3", className)} {...props} />;
}

export function CardContent({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn("p-5 pt-0", className)} {...props} />;
}

export function Badge({ className, color = "gray", children }: { className?: string; color?: "orange" | "blue" | "green" | "red" | "gray"; children: React.ReactNode }) {
  const colors: Record<string, string> = {
    orange: "bg-[#ffe0c7]",
    blue: "bg-[#dfe6ff]",
    green: "bg-[#dff7ea]",
    red: "bg-[#ffe0e0]",
    gray: "bg-white/80 border border-black/5",
  };
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold shadow-sm",
        colors[color],
        className
      )}
      style={{ color: "#111111" }}
    >
      {children}
    </span>
  );
}
