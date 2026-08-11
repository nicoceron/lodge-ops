import type { Metadata } from "next";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { ReservationComposer } from "@/components/staff/reservation-composer";
import { demoModeEnabled } from "@/data/api-client";
import {
  listGuests,
  listPrograms,
  listProperties,
  listResources,
  type GuestDto,
  type ProgramDto,
  type PropertyDto,
  type ResourceDto,
} from "@/data/staff-api";

export const metadata: Metadata = { title: "Create reservation" };

export default async function NewReservationPage({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  const params = await searchParams;
  let error: string | null = null;
  let properties: PropertyDto[] = [];
  let programs: ProgramDto[] = [];
  let guests: GuestDto[] = [];
  let resources: ResourceDto[] = [];

  if (demoModeEnabled) {
    properties = [{ id: "demo-property", name: "Estancia Viento Sur", timezone: "America/Bogota", is_active: true }];
    programs = [{ id: "demo-program", property_id: "demo-property", name: "Patagonia Explorer", description: null, default_duration_minutes: 2880, capacity: 8, price_minor: 320000, currency: "USD", is_active: true }];
    guests = [{ id: "demo-guest", first_name: "Sofia", last_name: "Martinez", full_name: "Sofia Martinez", email: "sofia@example.com", phone: null, document_type: null, document_number: null, language: "es", preferences: null, marketing_consent: true, created_at: new Date().toISOString(), updated_at: new Date().toISOString() }];
    resources = [{ id: "demo-room", property_id: "demo-property", name: "Coihue Suite", code: "COI-01", type: "room" as const, capacity: 2, attributes: null, is_active: true }];
  } else {
    try {
      const [propertyResponse, programResponse, guestResponse, resourceResponse] = await Promise.all([listProperties(), listPrograms(), listGuests(), listResources()]);
      properties = propertyResponse.data;
      programs = programResponse.data;
      guests = guestResponse.data;
      resources = resourceResponse.data;
    } catch (reason) {
      error = reason instanceof Error ? reason.message : "Composer data could not be loaded.";
    }
  }

  return (
    <AppShell eyebrow="Reservations" title="Create reservation" description="Compose the guest party, program, dates, price, and operational resources in one pass.">
      <Link href="/reservations" className="mb-4 inline-flex items-center gap-2 text-xs font-bold text-[var(--forest)]"><ArrowLeft className="size-3.5" />Back to reservations</Link>
      {error ? <DataState kind="error" title="Reservation composer unavailable" description={error} /> : <ReservationComposer properties={properties} programs={programs} guests={guests} resources={resources} initialHold={params.status === "hold"} demo={demoModeEnabled} />}
    </AppShell>
  );
}
