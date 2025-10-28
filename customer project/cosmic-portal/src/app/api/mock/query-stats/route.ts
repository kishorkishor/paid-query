import { NextResponse } from 'next/server';

export async function GET() {
  const stats = {
    total: 128,
    new: 12,
    assigned: 24,
    in_process: 9,
    red_flags: 2,
  };

  return NextResponse.json(stats);
}

