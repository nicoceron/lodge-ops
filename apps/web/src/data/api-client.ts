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
  active_rooms: number;
  occupied_rooms: number;
  needs_attention: number;
  arrival_parties: Array<{
    id: string;
    confirmation_number: string;
    starts_at: string;
    ends_at: string;
    party_size: number;
    nights: number;
    readiness: "ready" | "attention" | "blocked";
    room_names: string[];
    guest_name?: string | null;
  }>;
  readiness: {
    complete: number;
    total: number;
    percent: number;
    items: Array<{ key: string; label: string; complete: number; total: number }>;
  };
  tasks: Array<{
    id: string;
    title: string;
    status: string;
    priority: string;
    due_at: string | null;
    assignee: { id: number; name: string } | null;
  }>;
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

export type CalendarProjectionDto = {
  data: CalendarEventDto[];
  range: { start: string; end: string; timezone: string };
  resources: Array<{
    id: string;
    property_id: string;
    name: string;
    code: string;
    type: "room" | "guide" | "horse" | "boat" | "vehicle" | "venue" | "equipment" | "staff";
    capacity: number;
    utilization_percent: number;
  }>;
  allocations: Array<{
    id: string;
    reservation_id: string;
    service_occurrence_id: string | null;
    resource_id: string | null;
    status: string;
    start: string;
    end: string;
    quantity: number;
  }>;
  summary: { hard_conflicts: number; unassigned_reservations: number; suggestions: number };
};

export type OperationsDto = {
  date: string;
  timezone: string;
  role_scope: { role: string; visible_sections: string[] };
  privacy: {
    can_view_guest_identity: boolean;
    can_view_dietary_details: boolean;
    restricted_fields: string[];
  };
  readiness: { complete: number; total: number; open: number };
  tasks: Array<{
    id: string;
    title: string;
    status: string;
    priority: string;
    due_at: string | null;
    owner_initials: string;
  }>;
  arrivals: Array<{
    id: string;
    confirmation_number: string;
    starts_at: string;
    ends_at: string;
    party_size: number;
    status: string;
    guest_name?: string | null;
    dietary?: string[];
  }>;
  kitchen: {
    available: boolean;
    guest_count: number;
    restrictions: Array<{ label: string; count: number; serious: boolean }>;
    identity_restricted: boolean;
    dietary_details_restricted: boolean;
  };
  guide_assignments: Array<{
    id: string;
    guide_resource_id: string | null;
    guide: string | null;
    program: string;
    starts_at: string;
    party_size: number;
    status: "confirmed" | "action_needed";
  }>;
  housekeeping: { available: boolean; arrivals: number; turnovers: number; stayovers: number; focus: string | null };
};

export type FinanceDto = {
  currency: string;
  timezone: string;
  period: { start: string; end: string; label: string };
  summary: {
    booked_revenue_minor: number;
    cash_collected_minor: number;
    receivables_minor: number;
    loaded_costs_minor: number;
    commission_accruals_minor: number;
    margin_minor: number;
    margin_percent: number;
    overdue_deposits_minor: number;
    collection_percent: number;
  };
  deposits: { due_count: number; due_minor: number; paid_count: number; paid_minor: number; overdue_count: number };
  folio: { charges_minor: number; payments_minor: number; refunds_minor: number; adjustments_minor: number };
  revenue_series: Array<{ label: string; value_minor: number }>;
  programs: Array<{
    program_id: string | null;
    program: string;
    bookings: number;
    revenue_minor: number;
    loaded_costs_minor: number;
    commission_accruals_minor: number;
    margin_minor: number;
  }>;
  channels: Array<{
    channel: string;
    bookings: number;
    revenue_minor: number;
    collected_minor: number;
    commission_accruals_minor: number;
    net_revenue_minor: number;
    collection_percent: number;
  }>;
  reconciliation: {
    currency: string;
    currency_policy: string;
    formula: string;
    difference_minor: number;
    program_difference_minor: number;
    is_balanced: boolean;
  };
  recent_folios: Array<{
    reservation_id: string;
    confirmation_number: string;
    status: string;
    total_minor: number;
    paid_minor: number;
    balance_minor: number;
  }>;
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
  hold_expires_at?: string | null;
  program_id?: string | null;
  program?: { id: string; name: string; display_color?: string | null } | null;
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

export async function staffApiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  const apiUrl = process.env.API_INTERNAL_URL ?? "http://localhost:8000";
  const cookieStore = await cookies();
  const tenantId = cookieStore.get("lodgeops_tenant_id")?.value ?? process.env.LODGEOPS_TENANT_ID;

  if (!tenantId) {
    throw new LodgeApiError("No active tenant is selected.", 400, null);
  }

  const headers = new Headers(init?.headers);
  const appOrigin = process.env.APP_ORIGIN ?? "http://localhost:3000";
  headers.set("Accept", "application/json");
  headers.set("Cookie", cookieStore.toString());
  headers.set("Origin", appOrigin);
  headers.set("Referer", `${appOrigin.replace(/\/$/, "")}/`);
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
  return staffApiRequest<{ data: DashboardDto }>("/dashboard");
}

export async function getCalendar(from: string, to: string) {
  const query = new URLSearchParams({ start: from, end: to });
  return staffApiRequest<CalendarProjectionDto>(`/calendar?${query.toString()}`);
}

export async function getOperations() {
  return staffApiRequest<{ data: OperationsDto }>("/operations");
}

export async function getFinance() {
  return staffApiRequest<{ data: FinanceDto }>("/finance");
}

export async function listReservations(filters: { status?: string; propertyId?: string; from?: string; to?: string } = {}) {
  const query = new URLSearchParams({ per_page: "100" });
  if (filters.status) query.set("status", filters.status);
  if (filters.propertyId) query.set("property_id", filters.propertyId);
  if (filters.from) query.set("from", filters.from);
  if (filters.to) query.set("to", filters.to);
  return staffApiRequest<{ data: ReservationDto[]; meta: Record<string, unknown> }>(`/reservations?${query}`);
}

export const demoModeEnabled = process.env.NEXT_PUBLIC_DEMO_MODE === "true";
