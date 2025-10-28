export type MessageRole = "customer" | "team" | "system";

export interface Message {
  id: string;
  role: MessageRole;
  content: string;
  createdAt: string; // ISO
  read?: boolean;
  attachments?: { id: string; name: string; url: string }[];
}

export interface Country {
  code: string;
  name: string;
}

export interface QueryFormData {
  name: string;
  phone: string;
  serviceDetails: string;
  country: string;
  productName?: string;
  productLink?: string;
  quantity?: string;
  specs?: string;
}

