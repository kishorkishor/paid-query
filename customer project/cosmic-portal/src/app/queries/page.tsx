"use client";
import { AppShell } from "@/components/layout/AppShell";
import { Card, CardContent, CardHeader, Badge } from "@/components/ui/Card";
import { QueryForm } from "@/components/forms/QueryForm";
import type { Country, QueryFormData } from "@/types";
import { useState } from "react";

const mockCountries: Country[] = [
  { code: "CN", name: "China" },
  { code: "US", name: "United States" },
  { code: "AE", name: "United Arab Emirates" },
  { code: "IN", name: "India" },
];

export default function QueriesPage() {
  const [submitting, setSubmitting] = useState(false);
  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <h2 className="font-semibold text-lg">Create Query</h2>
          </CardHeader>
          <CardContent>
            <QueryForm
              countries={mockCountries}
              isLoading={submitting}
              onSubmit={async (data: QueryFormData) => {
                setSubmitting(true);
                try {
                  // TODO: Integrate with PHP API via axios + React Query
                  await new Promise((r) => setTimeout(r, 800));
                  alert("Query submitted successfully");
                } finally {
                  setSubmitting(false);
                }
              }}
              onFileUpload={(files) => console.log(files)}
            />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <h2 className="font-semibold text-lg">My Queries</h2>
              <Badge color="blue">4 OPEN</Badge>
            </div>
          </CardHeader>
          <CardContent>
            <ul className="space-y-3">
              {[1, 2, 3, 4].map((i) => (
                <li key={i} className="flex items-center justify-between">
                  <div>
                    <div className="font-medium">Electronics wholesale (#{i}28{i})</div>
                    <div className="text-xs text-neutral-500">Last update 2h ago</div>
                  </div>
                  <Badge color={i % 2 ? "orange" : "green"}>{i % 2 ? "NEW" : "ASSIGNED"}</Badge>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
