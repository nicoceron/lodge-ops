import "server-only";

import { cookies } from "next/headers";

export type DashboardDto = {
  date: string;
  timezone: string;
  arrivals: number;
  departures: number;
  in_house: number;
  active_resources: number;
  open_tasks: number;
  occupancy_percent: number;
};

export type CalendarEventDto = {
  id: string;
  type: "reservation" | "activity" | "resource_block" | "task";
  title: string;
  start: string;
  end: string;
  status?: string | null;
  property_id: string;
  resource_ids?: string[];
};

export type ReservationDto = {
  id: string;
  property_id: string;
  confirmation_number: string;
  status: "draft" | "hold" | "confirmed" | "checked_in" | "checked_out" | "cancelled" | "no_show";
  source: string | null;
  starts_at: string;
  ends_at: string;
  adults: number;
  children: number;
  currency: string;
  subtotal_minor: number;
  tax_minor: number;
  total_minor: number;
  revision: number;
  primary_guest?: { id: string; first_name: string; last_name: string | null } | null;
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
  const query = new URLSearchParams({ start: from, end: to });
  return request<{ data: CalendarEventDto[] }>(`/calendar?${query.toString()}`);
}

export async function listReservations() {
  return request<{ data: ReservationDto[]; meta: Record<string, unknown> }>("/reservations?per_page=100");
}

export const liveApiEnabled = process.env.LODGEOPS_USE_API === "true";
