import "server-only";

import { redirect } from "next/navigation";
import {
  demoModeEnabled,
  LodgeApiError,
  staffApiRequest,
  type ReservationDto,
} from "@/data/api-client";

export type StaffTenant = {
  id: string;
  name: string;
  slug: string;
  role?: string | null;
  property_id?: string | null;
  property?: { id: string; name: string } | null;
};

export type StaffUser = {
  id: number;
  name: string;
  email: string;
  tenants: StaffTenant[];
  membership?: { role: string; property_id?: string | null; property?: { id: string; name: string } | null } | null;
};

export type GuestDto = {
  id: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  email: string | null;
  phone: string | null;
  document_type: string | null;
  document_number: string | null;
  language: string | null;
  preferences: Record<string, unknown> | null;
  marketing_consent: boolean;
  created_at: string;
  updated_at: string;
};

export type PropertyDto = {
  id: string;
  name: string;
  timezone: string;
  address?: string | null;
  is_active: boolean;
};

export type ProgramDto = {
  id: string;
  property_id: string;
  name: string;
  description: string | null;
  default_duration_minutes: number;
  capacity: number;
  price_minor: number;
  currency: string;
  is_active: boolean;
};

export type ResourceDto = {
  id: string;
  property_id: string;
  name: string;
  code: string;
  type: "room" | "guide" | "horse" | "boat" | "vehicle" | "venue" | "equipment" | "staff";
  capacity: number;
  attributes: Record<string, unknown> | null;
  is_active: boolean;
};

export type ServiceOccurrenceDto = {
  id: string;
  program_id: string;
  property_id: string;
  starts_at: string;
  ends_at: string;
  capacity: number;
  is_cancelled: boolean;
  meeting_point: string | null;
  program?: { id: string; name: string; display_color?: string | null } | null;
};

export type ResourceBlockDto = {
  id: string;
  resource_id: string;
  starts_at: string;
  ends_at: string;
  reason: string;
  notes: string | null;
  resource?: { id: string; name: string; code: string; type: string } | null;
};

export type TaskDto = {
  id: string;
  property_id: string;
  reservation_id: string | null;
  assignee_id: number | null;
  title: string;
  description: string | null;
  status: "todo" | "in_progress" | "blocked" | "done" | "cancelled";
  priority: string;
  due_at: string | null;
  completed_at: string | null;
};

export type DepositDto = {
  id: string;
  reservation_id: string;
  payment_id: string | null;
  status: "due" | "paid" | "waived" | "refunded";
  currency: string;
  amount_minor: number;
  due_at: string | null;
  paid_at: string | null;
};

export type PaymentDto = {
  id: string;
  reservation_id: string;
  status: "pending" | "succeeded" | "failed" | "reversed";
  method: string;
  provider: string | null;
  provider_reference: string | null;
  currency: string;
  amount_minor: number;
  processed_at: string | null;
};

export type FolioLineDto = {
  id: string;
  reservation_id: string;
  type: "charge" | "payment" | "refund" | "adjustment" | "discount" | "gratuity";
  description: string;
  quantity: string | number;
  unit_amount_minor: number;
  amount_minor: number;
  currency: string;
  posted_at: string;
};

export type ReservationDetailDto = ReservationDto & {
  program_id?: string | null;
  program?: { id: string; name: string } | null;
  notes?: string | null;
  guests?: GuestDto[];
  allocations?: Array<{
    id: string;
    resource_id: string | null;
    service_occurrence_id: string | null;
    status: string;
    starts_at: string;
    ends_at: string;
    quantity: number;
    resource: { id: string; name: string; code: string } | null;
  }>;
};

export type GuestHistoryDto = {
  guest: GuestDto;
  reservations: ReservationDto[];
  stats: { stays: number; lifetime_value_minor: number; currency: string; last_stay_at: string | null };
};

export function selectedTenant(user: StaffUser, tenantId?: string | null) {
  return user.tenants.find((tenant) => tenant.id === tenantId) ?? user.tenants[0] ?? null;
}

export async function requireStaffUser(): Promise<StaffUser> {
  if (demoModeEnabled) {
    return {
      id: 1,
      name: "Nico Ceron",
      email: "admin@example.com",
      tenants: [{ id: "demo", name: "Estancia Viento Sur", slug: "demo-lodge", role: "owner" }],
      membership: { role: "owner" },
    };
  }

  try {
    return (await staffApiRequest<{ data: StaffUser }>("/auth/me")).data;
  } catch (error) {
    if (error instanceof LodgeApiError && error.status === 401) redirect("/login");
    throw error;
  }
}

export async function listGuests(search = "") {
  const query = new URLSearchParams({ per_page: "100" });
  if (search) query.set("search", search);
  return staffApiRequest<{ data: GuestDto[]; meta: Record<string, unknown> }>(`/guests?${query}`);
}

export async function getGuest(id: string) {
  return staffApiRequest<{ data: GuestDto }>(`/guests/${encodeURIComponent(id)}`);
}

export async function getGuestHistory(id: string) {
  return staffApiRequest<{ data: GuestHistoryDto }>(`/guests/${encodeURIComponent(id)}/history`);
}

export async function listProperties() {
  return staffApiRequest<{ data: PropertyDto[] }>("/properties?per_page=100");
}

export async function listPrograms(propertyId?: string) {
  const query = new URLSearchParams({ per_page: "100" });
  if (propertyId) query.set("property_id", propertyId);
  return staffApiRequest<{ data: ProgramDto[] }>(`/programs?${query}`);
}

export async function listResources(propertyId?: string) {
  const query = new URLSearchParams({ per_page: "100" });
  if (propertyId) query.set("property_id", propertyId);
  return staffApiRequest<{ data: ResourceDto[] }>(`/resources?${query}`);
}

export async function getServiceOccurrence(id: string) {
  return staffApiRequest<{ data: ServiceOccurrenceDto }>(`/service-occurrences/${encodeURIComponent(id)}`);
}

export async function getResourceBlock(id: string) {
  return staffApiRequest<{ data: ResourceBlockDto }>(`/resource-blocks/${encodeURIComponent(id)}`);
}

export async function getReservation(id: string) {
  return staffApiRequest<{ data: ReservationDetailDto }>(`/reservations/${encodeURIComponent(id)}`);
}

export async function listTasks(reservationId?: string) {
  const query = new URLSearchParams({ per_page: "100" });
  if (reservationId) query.set("reservation_id", reservationId);
  return staffApiRequest<{ data: TaskDto[] }>(`/tasks?${query}`);
}

export async function listDeposits(reservationId?: string) {
  const query = new URLSearchParams({ per_page: "100" });
  if (reservationId) query.set("reservation_id", reservationId);
  return staffApiRequest<{ data: DepositDto[] }>(`/deposits?${query}`);
}

export async function listPayments(reservationId?: string) {
  const query = new URLSearchParams({ per_page: "100" });
  if (reservationId) query.set("reservation_id", reservationId);
  return staffApiRequest<{ data: PaymentDto[] }>(`/payments?${query}`);
}

export async function listFolio(id: string) {
  return staffApiRequest<{ data: FolioLineDto[] }>(`/reservations/${encodeURIComponent(id)}/folio`);
}
