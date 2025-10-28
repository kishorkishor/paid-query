import axios from "axios";
import { Query, QueryDetailResponse, QueryFormData, QueryStatsSummary, Country, Wallet, WalletTransaction, Order } from "@/types";

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL || "http://localhost:8080",
  withCredentials: true,
});

api.interceptors.request.use((config) => {
  // Attach auth token if Clerk is used; placeholder
  // config.headers.Authorization = `Bearer ${token}`
  return config;
});

api.interceptors.response.use(
  (res) => res,
  (err) => {
    // Centralized error logging
    return Promise.reject(err);
  }
);

// Real PHP API endpoints
export const endpoints = {
  createQuery: () => `/api/create_query.php`,
  myQueries: () => `/api/my_queries.php`,
  myQueryStats: () => `/api/my_query_stats.php`,
  queryDetails: (id: string) => `/api/query_details.php?id=${id}`,
  addCustomerMessage: () => `/api/add_customer_message.php`,
  walletCapture: () => `/api/wallet_capture.php`,
  walletCaptureShipping: () => `/api/wallet_capture_shipping.php`,
  deliveryCreate: () => `/api/delivery_create.php`,
  getCountries: () => `/api/get_countries.php`,
  // These don't exist yet, so we'll create mock endpoints for them
  walletBalance: () => `/api/mock/wallet`,
  walletHistory: () => `/api/mock/wallet-history`,
  getOrders: () => `/api/mock/orders`,
  getOrderDetails: (id: string) => `/api/mock/orders/${id}`,
};

// API client functions
export const apiClient = {
  async createQuery(data: QueryFormData, token?: string) {
    const formData = new FormData();
    
    // Add all form fields
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        if (key === 'attachments' && Array.isArray(value)) {
          value.forEach((file) => formData.append('attachments[]', file));
        } else {
          formData.append(key, String(value));
        }
      }
    });

    const response = await api.post(endpoints.createQuery(), formData, {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    return response.data;
  },

  async getMyQueries(token?: string): Promise<Query[]> {
    const response = await api.get(endpoints.myQueries(), {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Failed to fetch queries');
    }
    
    return response.data.rows || [];
  },

  async getQueryStats(token?: string): Promise<QueryStatsSummary> {
    const response = await api.get(endpoints.myQueryStats(), {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Failed to fetch stats');
    }
    
    const counts = response.data.counts || {};
    return {
      total: Number(counts.total_cnt || 0),
      new: Number(counts.new_cnt || 0),
      assigned: Number(counts.assigned_cnt || 0),
      in_process: Number(counts.inproc_cnt || 0),
      red_flags: Number(counts.red_cnt || 0),
      recent: response.data.recent || [],
    };
  },

  async getQueryDetails(id: string, token?: string): Promise<QueryDetailResponse> {
    const response = await api.get(endpoints.queryDetails(id), {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Query not found');
    }
    
    return {
      query: response.data.query,
      messages: response.data.messages || [],
    };
  },

  async sendMessage(data: { queryId: string; customerId?: number; message: string }, token?: string) {
    const formData = new FormData();
    formData.append('id', data.queryId);
    formData.append('body', data.message);
    if (data.customerId) {
      formData.append('customer_id', String(data.customerId));
    }

    const response = await api.post(endpoints.addCustomerMessage(), formData, {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Failed to send message');
    }
    
    return response.data;
  },

  async getWalletBalance(token?: string): Promise<Wallet> {
    const response = await api.get(endpoints.walletBalance());
    return response.data;
  },

  async getWalletHistory(token?: string): Promise<WalletTransaction[]> {
    const response = await api.get(endpoints.walletHistory());
    return response.data;
  },

  async getOrders(token?: string): Promise<Order[]> {
    const response = await api.get(endpoints.getOrders());
    return response.data;
  },

  async getOrderDetails(id: string, token?: string): Promise<Order | null> {
    const response = await api.get(endpoints.getOrderDetails(id));
    return response.data;
  },

  async getCountries(token?: string): Promise<Country[]> {
    const response = await api.get(endpoints.getCountries());
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Failed to fetch countries');
    }
    
    return response.data.countries || [];
  },

  async walletCapture(data: { orderId: string; amount?: number; cartonIds?: number[] }, token?: string) {
    const formData = new FormData();
    formData.append('order_id', data.orderId);
    if (data.amount) formData.append('amount', String(data.amount));
    if (data.cartonIds) {
      data.cartonIds.forEach(id => formData.append('carton_ids[]', String(id)));
    }

    const response = await api.post(endpoints.walletCapture(), formData, {
      headers: {
        ...(token && { Authorization: `Bearer ${token}` }),
      },
    });
    
    if (!response.data?.ok) {
      throw new Error(response.data?.error || 'Wallet capture failed');
    }
    
    return response.data;
  },
};

