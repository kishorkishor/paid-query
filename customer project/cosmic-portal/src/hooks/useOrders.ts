import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api';
import { Order } from '@/types';

// Get orders
export function useOrders() {
  return useQuery<Order[]>({
    queryKey: ['orders'],
    queryFn: () => apiClient.getOrders(),
    staleTime: 60000, // 1 minute
  });
}

// Get order details
export function useOrderDetails(id: string) {
  return useQuery<Order | null>({
    queryKey: ['order', id],
    queryFn: () => apiClient.getOrderDetails(id),
    enabled: !!id,
  });
}

