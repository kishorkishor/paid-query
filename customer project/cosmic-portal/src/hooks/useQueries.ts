"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@clerk/nextjs";
import { apiClient } from "@/lib/api";
import {
  Query,
  QueryDetailResponse,
  QueryFormData,
  QueryStatsSummary,
} from "@/types";

// Fetch aggregated stats + recent queries
export function useQueryStats() {
  const { getToken, isLoaded } = useAuth();

  return useQuery<QueryStatsSummary>({
    queryKey: ["queryStats"],
    enabled: isLoaded,
    staleTime: 30_000,
    queryFn: async () => {
      const token = await getToken();
      return apiClient.getQueryStats(token ?? undefined);
    },
  });
}

// Fetch the customer's queries
export function useMyQueries() {
  const { getToken, isLoaded } = useAuth();

  return useQuery<Query[]>({
    queryKey: ["queries"],
    enabled: isLoaded,
    staleTime: 60_000,
    queryFn: async () => {
      const token = await getToken();
      return apiClient.getMyQueries(token ?? undefined);
    },
  });
}

// Fetch a single query + message timeline
export function useQueryDetails(id: string) {
  const { getToken, isLoaded } = useAuth();

  return useQuery<QueryDetailResponse>({
    queryKey: ["query", id],
    enabled: Boolean(id) && isLoaded,
    queryFn: async () => {
      const token = await getToken();
      return apiClient.getQueryDetails(id, token ?? undefined);
    },
  });
}

// Submit new query
export function useCreateQuery() {
  const queryClient = useQueryClient();
  const { getToken } = useAuth();

  return useMutation({
    mutationFn: async (data: QueryFormData) => {
      const token = await getToken();
      return apiClient.createQuery(data, token ?? undefined);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["queries"] });
      queryClient.invalidateQueries({ queryKey: ["queryStats"] });
    },
  });
}

// Send a message inside a query thread
export function useSendMessage() {
  const queryClient = useQueryClient();
  const { getToken } = useAuth();

  return useMutation({
    mutationFn: async (data: {
      queryId: string;
      customerId?: number;
      message: string;
    }) => {
      const token = await getToken();
      return apiClient.sendMessage(data, token ?? undefined);
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ["query", variables.queryId] });
    },
  });
}

