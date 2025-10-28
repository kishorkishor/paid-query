import { NextResponse } from 'next/server';

export async function GET() {
  const orders = [
    {
      id: '1',
      code: 'ORD001',
      query_id: '1',
      amount_total: 2500.00,
      status: 'confirmed',
      payment_status: 'paid',
      created_at: '2024-01-15T10:00:00Z',
      updated_at: '2024-01-15T10:00:00Z',
      items: [
        {
          id: '1',
          order_id: '1',
          product_name: 'Smartwatch',
          links: 'https://example.com/smartwatch',
          details: 'High quality smartwatch with GPS',
          quantity: 100,
          unit_price: 25.00,
          total_price: 2500.00,
        },
      ],
      cartons: [
        {
          id: '1',
          order_id: '1',
          weight_kg: 15.5,
          volume_cbm: 0.25,
          bd_delivery_status: 'pending',
          delivery_status: 'processing',
          bd_final_amount: 2500.00,
          paid_amount: 2500.00,
          due_amount: 0.00,
          otp_code: null,
          otp_generated_at: null,
          otp_verified_at: null,
        },
      ],
    },
    {
      id: '2',
      code: 'ORD002',
      query_id: '2',
      amount_total: 1200.00,
      status: 'in_progress',
      payment_status: 'partial',
      created_at: '2024-01-14T14:30:00Z',
      updated_at: '2024-01-15T11:30:00Z',
      items: [
        {
          id: '2',
          order_id: '2',
          product_name: 'LED Strip Lights',
          links: 'https://example.com/led-lights',
          details: 'RGB LED strip lights 5m length',
          quantity: 50,
          unit_price: 24.00,
          total_price: 1200.00,
        },
      ],
      cartons: [
        {
          id: '2',
          order_id: '2',
          weight_kg: 8.2,
          volume_cbm: 0.15,
          bd_delivery_status: 'in_transit',
          delivery_status: 'shipped',
          bd_final_amount: 1200.00,
          paid_amount: 600.00,
          due_amount: 600.00,
          otp_code: '123456',
          otp_generated_at: '2024-01-15T10:00:00Z',
          otp_verified_at: null,
        },
      ],
    },
  ];

  return NextResponse.json(orders);
}

