import "server-only";

import { cookies } from "next/headers";

export type MoneyDto = {
  amount_minor: number;
  currency: string;
};

export type DashboardDto = {
  occupancy: Record<string, number>;
  arrivals: Array<{ id: string; confirmation_number: string; starts_at: string }>;
  departures: Array<{ id: string; confirmation_number: string; ends_at: string }>;
  tasks: Array<{ id: string; title: string; status: string; due_at: string | null }>;
  finance: Record<string, number | MoneyDto>;
};

export type CalendarAllocationDto = {
  id: string;
  reservation_id: string;
  resource_id: string | null;
  service_occurrence_id: string | null;
  status: "tentative" | "confirmed" | "released";
  starts_at: string;
  ends_at: string;
  quantity: number;
};

export class LodgeApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly requestId: string | null,
  ) {
    super(message);
    this.name = "LodgeApiError";
  }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const apiUrl = process.env.API_INTERNAL_URL ?? "http://localhost:8000";
  const cookieStore = await cookies();
  const tenantId = cookieStore.get("lodgeops_tenant_id")?.value ?? process.env.LODGEOPS_TENANT_ID;

  if (!tenantId) {
    throw new LodgeApiError("No active tenant is selected.", 400, null);
  }

  const headers = new Headers(init?.headers);
  headers.set("Accept", "application/json");
  headers.set("Cookie", cookieStore.toString());
  headers.set("X-Tenant-ID", tenantId);

  const response = await fetch(new URL(`/api/v1${path}`, apiUrl), {
    ...init,
    headers,
    cache: "no-store",
  });

  if (!response.ok) {
    const body = (await response.json().catch(() => null)) as { message?: string } | null;
    throw new LodgeApiError(
      body?.message ?? "The LodgeOps API request failed.",
      response.status,
      response.headers.get("X-Request-ID"),
    );
  }

  return response.json() as Promise<T>;
}

export async function getDashboard() {
  return request<{ data: DashboardDto }>("/dashboard");
}

export async function getCalendar(from: string, to: string) {
  const query = new URLSearchParams({ from, to });
  return request<{ data: CalendarAllocationDto[] }>(`/calendar?${query.toString()}`);
}
