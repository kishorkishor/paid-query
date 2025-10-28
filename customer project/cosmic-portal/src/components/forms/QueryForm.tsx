"use client";
import { useState } from "react";
import { Input, Textarea } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import type { Country, QueryFormData } from "@/types";
import { ChevronDown, ChevronUp, Link as LinkIcon, Package } from "lucide-react";

export function QueryForm({ countries, onSubmit, isLoading, onFileUpload }: {
  countries: Country[];
  onSubmit: (data: QueryFormData) => void;
  isLoading?: boolean;
  onFileUpload?: (files: File[]) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const [form, setForm] = useState<QueryFormData>({
    name: "",
    phone: "",
    serviceDetails: "",
    country: countries?.[0]?.code || "",
    productName: "",
    productLink: "",
    quantity: "",
    specs: "",
  });

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    const { name, value } = e.target as { name: keyof QueryFormData; value: string };
    setForm((f) => ({ ...f, [name]: value }));
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(form);
  };

  return (
    <form className="space-y-4" onSubmit={submit}>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label className="text-sm text-neutral-600">Name</label>
          <Input name="name" value={form.name} onChange={handleChange} required />
        </div>
        <div>
          <label className="text-sm text-neutral-600">Phone</label>
          <Input name="phone" value={form.phone} onChange={handleChange} required />
        </div>
        <div className="md:col-span-2">
          <label className="text-sm text-neutral-600">Service Details</label>
          <Textarea name="serviceDetails" value={form.serviceDetails} onChange={handleChange} required />
        </div>
        <div>
          <label className="text-sm text-neutral-600">Country</label>
          <select name="country" value={form.country} onChange={handleChange} className="h-11 w-full rounded-[var(--radius)] border border-neutral-300 px-3">
            {countries.map((c) => (
              <option key={c.code} value={c.code}>{c.name}</option>
            ))}
          </select>
        </div>
      </div>

      <button type="button" className="flex items-center gap-2 text-sm text-brand-primary" onClick={() => setExpanded((v) => !v)}>
        {expanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />} Add product details
      </button>

      {expanded && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className="text-sm text-neutral-600 flex items-center gap-2"><Package size={14}/> Product Name</label>
            <Input name="productName" value={form.productName} onChange={handleChange} />
          </div>
          <div>
            <label className="text-sm text-neutral-600 flex items-center gap-2"><LinkIcon size={14}/> Product Link</label>
            <Input name="productLink" value={form.productLink} onChange={handleChange} placeholder="https://..." />
          </div>
          <div>
            <label className="text-sm text-neutral-600">Quantity</label>
            <Input name="quantity" value={form.quantity} onChange={handleChange} />
          </div>
          <div className="md:col-span-2">
            <label className="text-sm text-neutral-600">Specifications</label>
            <Textarea name="specs" value={form.specs} onChange={handleChange} />
          </div>
          <div className="md:col-span-2">
            <label className="text-sm text-neutral-600">Attachments</label>
            <div className="glass rounded-[var(--radius)] p-4 text-sm text-neutral-600 border-dashed border-2 border-neutral-300 flex items-center justify-between">
              <span>Drag & drop files or click to upload</span>
              <input type="file" multiple onChange={(e) => onFileUpload?.(Array.from(e.target.files || []))} />
            </div>
          </div>
        </div>
      )}

      <div className="pt-2">
        <Button type="submit" disabled={isLoading}>{isLoading ? "Submitting..." : "Submit Query"}</Button>
      </div>
    </form>
  );
}

