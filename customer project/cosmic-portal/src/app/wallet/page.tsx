import { AppShell } from "@/components/layout/AppShell";
import { Card, CardContent, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { formatCurrency } from "@/lib/utils";

export default function WalletPage() {
  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <h2 className="font-semibold text-lg">Wallet Balance</h2>
          </CardHeader>
          <CardContent>
            <div className="text-sm text-neutral-500">Available</div>
            <div className="text-4xl font-bold mt-1">{formatCurrency(2570)}</div>
            <div className="mt-4 grid grid-cols-2 gap-3">
              <Button>Top Up</Button>
              <Button variant="outline">Withdraw</Button>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <h2 className="font-semibold text-lg">Transactions</h2>
          </CardHeader>
          <CardContent>
            <ul className="space-y-3 text-sm">
              {[1,2,3,4].map((i) => (
                <li key={i} className="flex items-center justify-between border-b border-black/5 dark:border-white/5 pb-2">
                  <span>Payment for order CT-00{i}9</span>
                  <span className={i % 2 ? "text-green-600" : "text-red-600"}>
                    {i % 2 ? "+" : "-"}{formatCurrency(150)}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
