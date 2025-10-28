"use client";
import * as Dialog from "@radix-ui/react-dialog";
import * as ScrollArea from "@radix-ui/react-scroll-area";
import { AnimatePresence, motion } from "framer-motion";
import { Paperclip, Send, X, MessageSquare } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";

type PopupMessage = {
  id: string;
  role: "customer" | "team" | "system";
  content: string;
  createdAt: string;
};

export function ChatPopup() {
  const [open, setOpen] = useState(false);
  const [input, setInput] = useState("");
  const [messages, setMessages] = useState<PopupMessage[]>(() => [
    { id: "m1", role: "system", content: "You're chatting with Cosmic Trading.", createdAt: new Date().toISOString() },
    { id: "m2", role: "team", content: "Hi! How can we help with your order?", createdAt: new Date().toISOString() },
  ]);

  const endRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages, open]);

  const onSend = () => {
    if (!input.trim()) return;
    const userMsg: PopupMessage = {
      id: crypto.randomUUID(),
      role: "customer",
      content: input,
      createdAt: new Date().toISOString(),
    };
    setMessages((m) => [...m, userMsg]);
    setInput("");
    // Fake reply
    setTimeout(() => {
      setMessages((m) => [
        ...m,
        {
          id: crypto.randomUUID(),
          role: "team",
          content: "Thanks! We'll get back shortly.",
          createdAt: new Date().toISOString(),
        },
      ]);
    }, 600);
  };

  const fab = (
    <button
      aria-label="Open chat"
      onClick={() => setOpen(true)}
      className={cn(
        "fixed bottom-5 right-5 z-40 h-14 w-14 rounded-full brand-gradient text-white shadow-[0_12px_30px_rgba(227,67,31,.35)]",
        "grid place-items-center hover:brightness-110 transition"
      )}
    >
      <MessageSquare size={22} />
    </button>
  );

  return (
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <AnimatePresence initial={false}>
        {!open && (
          <motion.div key="fab" initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.9, opacity: 0 }}>
            {fab}
          </motion.div>
        )}
      </AnimatePresence>

      <Dialog.Portal>
        <AnimatePresence>
          {open && (
            <Dialog.Overlay asChild>
              <motion.div className="fixed inset-0 bg-black/20" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} />
            </Dialog.Overlay>
          )}
        </AnimatePresence>

        <AnimatePresence>
          {open && (
            <Dialog.Content asChild>
              <motion.div
                className="fixed bottom-5 right-5 z-50 w-[min(95vw,420px)] overflow-hidden glass-panel glass-orange"
                initial={{ y: 40, opacity: 0 }}
                animate={{ y: 0, opacity: 1 }}
                exit={{ y: 20, opacity: 0 }}
                transition={{ type: "spring", stiffness: 300, damping: 30 }}
              >
                <div className="brand-gradient text-white px-4 py-3 flex items-center justify-between">
                  <div>
                    <Dialog.Title className="font-semibold leading-tight">Customer Support</Dialog.Title>
                    <p className="text-xs opacity-90">Typically replies within minutes</p>
                  </div>
                  <button onClick={() => setOpen(false)} className="h-8 w-8 grid place-items-center rounded-full hover:bg-white/20">
                    <X size={16} />
                  </button>
                </div>

                <ScrollArea.Root className="h-[360px] bg-white/75 dark:bg-black/30">
                  <ScrollArea.Viewport className="h-full w-full p-4 space-y-3">
                    {messages.map((m) => (
                      <ChatBubble key={m.id} role={m.role} content={m.content} />
                    ))}
                    <div ref={endRef} />
                  </ScrollArea.Viewport>
                  <ScrollArea.Scrollbar className="p-0.5" orientation="vertical">
                    <ScrollArea.Thumb className="bg-neutral-300 rounded-full" />
                  </ScrollArea.Scrollbar>
                </ScrollArea.Root>

                <div className="p-3 bg-white/95 dark:bg-[#0f1115] border-t border-white/40 dark:border-white/10">
                  <div className="flex items-center gap-2">
                    <label className="h-10 w-10 grid place-items-center rounded-full bg-white/70 dark:bg-white/5 border border-white/70 dark:border-white/15 hover:bg-white/90 dark:hover:bg-white/10 cursor-pointer">
                      <Paperclip size={18} />
                      <input type="file" className="hidden" multiple onChange={(e) => console.log(e.target.files)} />
                    </label>
                    <input
                      value={input}
                      onChange={(e) => setInput(e.target.value)}
                      onKeyDown={(e) => e.key === "Enter" && !e.shiftKey && (e.preventDefault(), onSend())}
                      placeholder="Type your message..."
                      className="flex-1 h-11 px-4 rounded-full border border-neutral-300 bg-white/90 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                    />
                    <button aria-label="Send" onClick={onSend} className="h-11 px-4 rounded-full brand-gradient text-white font-medium shadow-[0_15px_35px_rgba(255,95,42,0.35)] hover:brightness-110">
                      <Send size={16} />
                    </button>
                  </div>
                </div>
              </motion.div>
            </Dialog.Content>
          )}
        </AnimatePresence>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

function ChatBubble({ role, content }: { role: PopupMessage["role"]; content: string }) {
  const me = role === "customer";
  return (
    <div className={cn("flex w-full", me ? "justify-end" : "justify-start")}>
      <div
        className={cn(
          "max-w-[80%] rounded-2xl px-4 py-2 text-sm shadow-sm",
          me
            ? "brand-gradient text-white rounded-br-sm"
            : "bg-white/90 dark:bg-white/10 border border-white/60 dark:border-white/10 text-brand-secondary dark:text-white"
        )}
      >
        {content}
      </div>
    </div>
  );
}
