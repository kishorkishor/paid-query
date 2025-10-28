import { cn } from "@/lib/utils";
import { forwardRef } from "react";

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {}

export const Input = forwardRef<HTMLInputElement, InputProps>(({ className, ...props }, ref) => (
  <input
    ref={ref}
    className={cn(
      "w-full h-11 px-4 rounded-[var(--radius)] border border-neutral-300 bg-white/90 dark:bg-[#171922]/70",
      "placeholder:text-neutral-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary",
      className
    )}
    {...props}
  />
));
Input.displayName = "Input";

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {}
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      "w-full min-h-28 p-4 rounded-[var(--radius)] border border-neutral-300 bg-white/90 dark:bg-[#171922]/70",
      "placeholder:text-neutral-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary",
      className
    )}
    {...props}
  />
));
Textarea.displayName = "Textarea";

