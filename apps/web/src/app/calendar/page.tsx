import type { Metadata } from "next";
import { AlertTriangle, Lightbulb, ShieldCheck } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { MasterCalendar } from "@/components/master-calendar";
import { loadCalendarProjection } from "@/data/staff-projections";

export const metadata: Metadata = { title: "Master calendar" };

export default async function CalendarPage({ searchParams }: { searchParams: Promise<{ start?: string }> }) {
  const params = await searchParams;
  const start = params.start && /^\d{4}-\d{2}-\d{2}$/.test(params.start) ? params.start : undefined;
  const state = await loadCalendarProjection(start);
  const calendar = state.data;

  return (
    <AppShell
      eyebrow="Operations"
      title="Master calendar"
      description="See rooms, people, activities, and equipment on the same timeline. Every assignment is checked before it reaches this plan."
      action={{ label: "Place a hold", shortLabel: "New hold", href: "/reservations/new?status=hold" }}
    >
      {!calendar ? <DataState kind="error" title="Calendar unavailable" description={state.error ?? "The live resource plan could not be loaded."} /> : null}
      {calendar ? <>
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><ShieldCheck aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">{calendar.summary.hardConflicts ? `${calendar.summary.hardConflicts} hard conflicts` : "No hard conflicts"}</p><p className="mt-1 text-[10px] text-[var(--muted)]">{calendar.summary.hardConflicts ? "Overlapping exclusive allocations need review" : "All exclusive resources fit"}</p></div>
        </div>
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--amber-soft)] text-[var(--amber)]"><AlertTriangle aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">{calendar.summary.unassignedReservations} missing assignment{calendar.summary.unassignedReservations === 1 ? "" : "s"}</p><p className="mt-1 text-[10px] text-[var(--muted)]">Reservations without a resource allocation</p></div>
        </div>
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--blue-soft)] text-[var(--blue)]"><Lightbulb aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">{calendar.summary.suggestions} smart suggestion{calendar.summary.suggestions === 1 ? "" : "s"}</p><p className="mt-1 text-[10px] text-[var(--muted)]">Available alternatives are ready</p></div>
        </div>
      </div>
      <div className="mt-5"><MasterCalendar calendar={calendar} /></div>
      </> : null}
    </AppShell>
  );
}
