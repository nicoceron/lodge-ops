import type { Metadata } from "next";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { TaskForm } from "@/components/staff/task-form";
import { demoModeEnabled, listReservations, type ReservationDto } from "@/data/api-client";
import { listProperties, type PropertyDto } from "@/data/staff-api";

export const metadata: Metadata = { title: "Create task" };

export default async function NewTaskPage({ searchParams }: { searchParams: Promise<{ reservation_id?: string }> }) {
  const params = await searchParams;
  let properties: PropertyDto[] = [];
  let reservations: ReservationDto[] = [];
  let error: string | null = null;
  if (demoModeEnabled) {
    properties = [{ id: "demo-property", name: "Estancia Viento Sur", timezone: "America/Bogota", is_active: true }];
  } else {
    try {
      const [propertyResponse, reservationResponse] = await Promise.all([listProperties(), listReservations()]);
      properties = propertyResponse.data;
      reservations = reservationResponse.data;
    } catch (reason) {
      error = reason instanceof Error ? reason.message : "Task data could not be loaded.";
    }
  }

  return <AppShell eyebrow="Operations" title="Create task" description="Assign preparation or service work to a property and, when relevant, a reservation.">
    <Link href="/operations" className="mb-4 inline-flex items-center gap-2 text-xs font-bold text-[var(--forest)]"><ArrowLeft className="size-3.5" />Back to operations</Link>
    {error ? <DataState kind="error" title="Task composer unavailable" description={error} /> : <TaskForm properties={properties} reservations={reservations} initialReservationId={params.reservation_id} demo={demoModeEnabled} />}
  </AppShell>;
}
