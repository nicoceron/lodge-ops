import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { ReservationWorkspace } from "@/components/staff/reservation-workspace";
import { demoModeEnabled, LodgeApiError } from "@/data/api-client";
import {
  getReservation,
  listDeposits,
  listFolio,
  listPayments,
  listResources,
  listTasks,
  requireStaffUser,
  type DepositDto,
  type FolioLineDto,
  type PaymentDto,
  type ReservationDetailDto,
  type ResourceDto,
  type TaskDto,
} from "@/data/staff-api";

export const metadata: Metadata = { title: "Reservation workspace" };

export default async function ReservationPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  let error: string | null = null;
  let reservation: ReservationDetailDto | null = null;
  let resources: ResourceDto[] = [];
  let tasks: TaskDto[] = [];
  let deposits: DepositDto[] = [];
  let payments: PaymentDto[] = [];
  let folio: FolioLineDto[] = [];
  let role = "viewer";

  if (demoModeEnabled) {
    reservation = { id, property_id: "demo-property", confirmation_number: "VS-2642", status: "confirmed", source: "direct", starts_at: "2026-08-13T15:00:00Z", ends_at: "2026-08-16T11:00:00Z", adults: 2, children: 0, currency: "USD", subtotal_minor: 184000, tax_minor: 0, total_minor: 184000, revision: 1, notes: "Demo reservation workspace. Changes are intentionally disabled.", primary_guest: { id: "demo-guest", first_name: "Sofia", last_name: "Martinez" }, guests: [], allocations: [], program: { id: "demo-program", name: "Patagonia Explorer" } };
    resources = [{ id: "demo-room", property_id: "demo-property", name: "Coihue Suite", code: "COI-01", type: "room" as const, capacity: 2, attributes: null, is_active: true }];
  } else {
    try {
      role = (await requireStaffUser()).membership?.role ?? "viewer";
      const response = await getReservation(id);
      reservation = response.data;
      const [resourceResponse, taskResponse, depositResponse, paymentResponse, folioResponse] = await Promise.all([
        listResources(reservation.property_id), listTasks(id), listDeposits(id), listPayments(id), listFolio(id),
      ]);
      resources = resourceResponse.data;
      tasks = taskResponse.data;
      deposits = depositResponse.data;
      payments = paymentResponse.data;
      folio = folioResponse.data;
    } catch (reason) {
      if (reason instanceof LodgeApiError && reason.status === 404) notFound();
      error = reason instanceof Error ? reason.message : "Reservation data could not be loaded.";
    }
  }

  return <AppShell eyebrow="Reservation workspace" title={reservation?.confirmation_number ?? "Reservation"} description={reservation ? `${reservation.primary_guest?.first_name ?? "Guest"} · ${reservation.status.replaceAll("_", " ")}` : "Live reservation details"}>
    <Link href="/reservations" className="mb-4 inline-flex items-center gap-2 text-xs font-bold text-[var(--forest)]"><ArrowLeft className="size-3.5" />Back to reservations</Link>
    {error || !reservation ? <DataState kind="error" title="Reservation unavailable" description={error ?? "This reservation could not be found."} /> : <ReservationWorkspace reservation={reservation} resources={resources} tasks={tasks} deposits={deposits} payments={payments} folio={folio} canManageGuestMoney={["owner", "manager", "operations", "finance"].includes(role)} canManageOperations={["owner", "manager", "operations"].includes(role)} demo={demoModeEnabled} />}
  </AppShell>;
}
