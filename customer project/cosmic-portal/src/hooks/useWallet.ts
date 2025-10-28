import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/api';
import { Wallet, WalletTransaction } from '@/types';

// Wallet balance
export function useWalletBalance() {
  return useQuery<Wallet>({
    queryKey: ['wallet'],
    queryFn: () => apiClient.getWalletBalance(),
    staleTime: 30000, // 30 seconds
  });
}

// Wallet history
export function useWalletHistory() {
  return useQuery<WalletTransaction[]>({
    queryKey: ['walletHistory'],
    queryFn: () => apiClient.getWalletHistory(),
    staleTime: 60000, // 1 minute
  });
}

// Wallet payment mutation
export function useWalletPayment() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: { orderId: string; amount: number }) => 
      apiClient.walletCapture(data),
    onSuccess: () => {
      // Invalidate wallet data
      queryClient.invalidateQueries({ queryKey: ['wallet'] });
      queryClient.invalidateQueries({ queryKey: ['walletHistory'] });
    },
  });
}

