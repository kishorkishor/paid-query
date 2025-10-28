import { AppShell } from "@/components/layout/AppShell";
import { Card, CardContent, CardHeader, Badge } from "@/components/ui/Card";

export default function OrdersPage() {
  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8 space-y-4">
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <h2 className="font-semibold text-lg">Orders</h2>
              <Badge color="green">2 ACTIVE</Badge>
            </div>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-neutral-500">
                    <th className="py-2">Order ID</th>
                    <th className="py-2">Status</th>
                    <th className="py-2">Total</th>
                    <th className="py-2">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {[1, 2, 3].map((i) => (
                    <tr key={i} className="border-t border-black/5 dark:border-white/5">
                      <td className="py-3">CT-00{i}9</td>
                      <td className="py-3"><Badge color={i % 2 ? "blue" : "green"}>{i % 2 ? "PROCESSING" : "READY"}</Badge></td>
                      <td className="py-3">$ {(1200 + i * 150).toFixed(2)}</td>
                      <td className="py-3 space-x-2">
                        <button className="text-brand-primary">Pay</button>
                        <button className="text-neutral-600">Details</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
