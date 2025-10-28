"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { AppShell } from "@/components/layout/AppShell";
import { QueryForm } from "@/components/forms/QueryForm";
import { useCreateQuery } from "@/hooks/useQueries";
import { apiClient } from "@/lib/api";
import { Country } from "@/types";
import { toast } from "sonner";

export default function NewQueryPage() {
  const router = useRouter();
  const createQuery = useCreateQuery();
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [countries, setCountries] = useState<Country[]>([]);
  const [loadingCountries, setLoadingCountries] = useState(true);

  useEffect(() => {
    const fetchCountries = async () => {
      try {
        const data = await apiClient.getCountries();
        setCountries(data);
      } catch (error) {
        console.error("Failed to fetch countries:", error);
        toast.error("Failed to load countries. Please refresh the page.");
      } finally {
        setLoadingCountries(false);
      }
    };

    fetchCountries();
  }, []);

  const handleSubmit = async (data: any) => {
    setIsSubmitting(true);
    try {
      await createQuery.mutateAsync(data);
      toast.success("Query created successfully!");
      router.push("/queries");
    } catch (error) {
      toast.error("Failed to create query. Please try again.");
      console.error("Query creation error:", error);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleFileUpload = (files: File[]) => {
    // Handle file upload logic here
    console.log("Files uploaded:", files);
  };

  if (loadingCountries) {
    return (
      <AppShell>
        <div className="glass-panel p-6 md:p-8">
          <div className="animate-pulse">
            <div className="h-8 bg-neutral-200 dark:bg-neutral-700 rounded w-1/3 mb-2"></div>
            <div className="h-4 bg-neutral-200 dark:bg-neutral-700 rounded w-1/2 mb-6"></div>
            <div className="space-y-4">
              <div className="h-12 bg-neutral-200 dark:bg-neutral-700 rounded"></div>
              <div className="h-12 bg-neutral-200 dark:bg-neutral-700 rounded"></div>
              <div className="h-24 bg-neutral-200 dark:bg-neutral-700 rounded"></div>
            </div>
          </div>
        </div>
      </AppShell>
    );
  }

  return (
    <AppShell>
      <div className="glass-panel p-6 md:p-8">
        <div className="mb-6">
          <h1 className="font-[var(--font-heading)] text-2xl font-semibold mb-2">
            Create New Query
          </h1>
          <p className="text-sm text-neutral-500">
            Fill out the form below to create a new sourcing or shipping query.
          </p>
        </div>

        <QueryForm 
          countries={countries}
          onSubmit={handleSubmit} 
          isLoading={isSubmitting}
          onFileUpload={handleFileUpload}
        />
      </div>
    </AppShell>
  );
}

