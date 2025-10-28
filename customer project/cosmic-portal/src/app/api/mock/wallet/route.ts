import { NextResponse } from 'next/server';

export async function GET() {
  const wallet = {
    balance: 2570.50,
    currency: 'USD',
    transactions: [
      {
        id: '1',
        type: 'credit',
        amount: 1000.00,
        description: 'Top up via bank transfer',
        order_id: null,
        carton_id: null,
        created_at: '2024-01-15T10:00:00Z',
      },
      {
        id: '2',
        type: 'debit',
        amount: 250.00,
        description: 'Payment for Order #ORD001',
        order_id: 'ORD001',
        carton_id: 'C001',
        created_at: '2024-01-14T14:30:00Z',
      },
      {
        id: '3',
        type: 'credit',
        amount: 500.00,
        description: 'Refund for cancelled order',
        order_id: 'ORD002',
        carton_id: null,
        created_at: '2024-01-13T09:15:00Z',
      },
    ],
  };

  return NextResponse.json(wallet);
}

