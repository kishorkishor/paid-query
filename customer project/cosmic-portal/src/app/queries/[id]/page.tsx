"use client";

import { useParams } from "next/navigation";
import { useQueryDetails, useSendMessage } from "@/hooks/useQueries";
import { AppShell } from "@/components/layout/AppShell";
import { Card, CardContent, CardHeader, Badge } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Textarea } from "@/components/ui/Input";
import { useState } from "react";
import { formatCurrency } from "@/lib/utils";
import { toast } from "sonner";

export default function QueryDetailsPage() {
  const params = useParams<{ id: string }>();
  const queryId = Array.isArray(params?.id) ? params?.id[0] : params?.id;
  const { data, isLoading } = useQueryDetails(queryId || "");
  const sendMessage = useSendMessage();
  const [message, setMessage] = useState("");

  const currentQuery = data?.query;

  const handleSend = async () => {
    if (!queryId || !message.trim()) return;
    try {
      await sendMessage.mutateAsync({
        queryId,
        customerId: currentQuery?.customer_id,
        message: message.trim(),
      });
      setMessage("");
      toast.success("Message sent");
    } catch (error) {
      toast.error("Failed to send message");
      console.error(error);
    }
  };

  if (!queryId) {
    return (
      <AppShell>
        <div className="glass-panel p-6 md:p-8">
          <p className="text-neutral-500">Invalid query id.</p>
        </div>
      </AppShell>
    );
  }

  if (isLoading || !currentQuery) {
    return (
      <AppShell>
        <div className="glass-panel p-6 md:p-8 space-y-4 animate-pulse">
          <div className="h-6 bg-neutral-200 dark:bg-neutral-700 rounded w-1/3" />
          <div className="h-32 bg-neutral-200 dark:bg-neutral-700 rounded" />
          <div className="h-48 bg-neutral-200 dark:bg-neutral-700 rounded" />
        </div>
      </AppShell>
    );
  }

  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8 space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-neutral-500">Query #{currentQuery.query_code}</p>
            <h1 className="font-[var(--font-heading)] text-2xl font-semibold">{currentQuery.product_name || currentQuery.product_details}</h1>
            <div className="mt-2 flex gap-3">
              <Badge color="orange">{currentQuery.status.toUpperCase()}</Badge>
              {currentQuery.priority && <Badge color="blue">{currentQuery.priority.toUpperCase()}</Badge>}
            </div>
          </div>
          <div className="text-right text-sm text-neutral-500">
            <div>Created: {new Date(currentQuery.created_at).toLocaleString()}</div>
            <div>Type: {currentQuery.query_type || 'N/A'}</div>
          </div>
        </div>

        <Card>
          <CardHeader>
            <h3 className="font-semibold">Summary</h3>
          </CardHeader>
          <CardContent className="space-y-2 text-sm text-neutral-600 dark:text-neutral-300">
            <div><strong>Customer:</strong> {currentQuery.customer_name}</div>
            <div><strong>Phone:</strong> {currentQuery.phone}</div>
            <div><strong>Country:</strong> {currentQuery.country_name || 'N/A'}</div>
            <div><strong>Quantity:</strong> {currentQuery.quantity || 'N/A'}</div>
            <div><strong>Budget:</strong> {currentQuery.budget ? formatCurrency(currentQuery.budget) : 'N/A'}</div>
            <div><strong>Team:</strong> {currentQuery.team_name || 'Not assigned'}</div>
            <div>
              <strong>Details:</strong>
              <p className="mt-1 whitespace-pre-line text-neutral-600 dark:text-neutral-300">{currentQuery.product_details}</p>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <h3 className="font-semibold">Conversation</h3>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="max-h-[360px] overflow-y-auto space-y-3 pr-2">
                {data?.messages?.length ? (
                  data.messages.map((msg) => (
                    <div
                      key={msg.id}
                      className={`rounded-2xl px-4 py-2 text-sm shadow-sm ${msg.direction === 'inbound' ? 'bg-white/90 dark:bg-white/5' : 'brand-gradient text-white ml-auto'}`}
                      style={{ maxWidth: '80%' }}
                    >
                      <div className="text-xs opacity-70 mb-1">
                        {new Date(msg.created_at).toLocaleString()}{msg.direction === 'inbound' ? ' • You' : ' • Team'}
                      </div>
                      <div>{msg.body}</div>
                    </div>
                  ))
                ) : (
                  <p className="text-neutral-500 text-sm">No messages yet.</p>
                )}
              </div>
              <div className="space-y-2">
                <label className="text-sm text-neutral-600 dark:text-neutral-300">Send a message</label>
                <Textarea
                  rows={3}
                  value={message}
                  onChange={(e) => setMessage(e.target.value)}
                  placeholder="Write your update for the team..."
                />
                <div className="text-right">
                  <Button
                    onClick={handleSend}
                    disabled={sendMessage.isPending || !message.trim()}
                  >
                    {sendMessage.isPending ? 'Sending...' : 'Send Message'}
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <h3 className="font-semibold">Meta</h3>
            </CardHeader>
            <CardContent className="space-y-3 text-sm text-neutral-600 dark:text-neutral-300">
              <div><strong>Query ID:</strong> {currentQuery.id}</div>
              <div><strong>Status:</strong> {currentQuery.status}</div>
              <div><strong>Priority:</strong> {currentQuery.priority || 'N/A'}</div>
              <div><strong>Shipping Mode:</strong> {currentQuery.shipping_mode || 'Unknown'}</div>
              <div><strong>Updated:</strong> {new Date(currentQuery.updated_at).toLocaleString()}</div>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppShell>
  );
}
