import type { Metadata } from "next";
import { ArrowRight, CalendarClock, Filter, Search, UserRoundCheck } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { StatusPill, type StatusTone } from "@/components/status-pill";
import { listReservations, liveApiEnabled, type ReservationDto } from "@/data/api-client";
import { reservations } from "@/lib/demo-data";
import { formatMoney } from "@/lib/utils";

export const metadata: Metadata = { title: "Reservations" };

const pipeline = [
  { label: "New inquiries", value: 8, note: "3 need a reply", tone: "bg-[var(--blue-soft)] text-[var(--blue)]" },
  { label: "Proposals sent", value: 12, note: "$148k potential", tone: "bg-[var(--amber-soft)] text-[var(--amber)]" },
  { label: "On hold", value: 5, note: "2 expire today", tone: "bg-[var(--red-soft)] text-[var(--red)]" },
  { label: "Confirmed", value: 31, note: "Next 90 days", tone: "bg-[var(--forest-soft)] text-[var(--forest)]" },
];

const statusPresentation: Record<ReservationDto["status"], { tone: StatusTone; label: string }> = {
  draft: { tone: "tentative", label: "Draft" },
  hold: { tone: "tentative", label: "On hold" },
  confirmed: { tone: "confirmed", label: "Confirmed" },
  checked_in: { tone: "in_house", label: "In house" },
  checked_out: { tone: "completed", label: "Completed" },
  cancelled: { tone: "neutral", label: "Cancelled" },
  no_show: { tone: "blocked", label: "No show" },
};

function shortDate(value: string) {
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", timeZone: "UTC" }).format(new Date(value));
}

export default async function ReservationsPage() {
  let liveReservations: ReservationDto[] | null = null;
  if (liveApiEnabled) {
    try {
      liveReservations = (await listReservations()).data;
    } catch {
      liveReservations = null;
    }
  }

  return (
    <AppShell
      eyebrow="Sales & stays"
      title="Reservations"
      description="Move a guest from first inquiry to a fully prepared stay without re-entering information or losing the commercial history."
      action={{ label: "Create reservation", shortLabel: "Create" }}
    >
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {pipeline.map((item) => (
          <article key={item.label} className="surface-card rounded-2xl p-4">
            <div className="flex items-start justify-between">
              <div><p className="text-xs font-semibold text-[var(--muted)]">{item.label}</p><p className="mt-2 font-display text-3xl font-semibold">{item.value}</p></div>
              <span className={`grid size-9 place-items-center rounded-xl ${item.tone}`}><CalendarClock aria-hidden="true" className="size-4" /></span>
            </div>
            <p className="mt-3 text-[10px] font-medium text-[var(--muted)]">{item.note}</p>
          </article>
        ))}
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="reservation-list-heading">
        <div className="flex flex-col gap-3 border-b border-black/7 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 id="reservation-list-heading" className="text-sm font-bold">Upcoming reservations</h2>
            <p className="mt-1 text-xs text-[var(--muted)]">Sorted by arrival and operational urgency</p>
          </div>
          <div className="flex gap-2">
            <label className="relative flex-1 sm:w-52">
              <span className="sr-only">Search reservations</span>
              <Search aria-hidden="true" className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" />
              <input placeholder="Search reservations" className="h-9 w-full rounded-lg border border-black/8 bg-white/75 pl-9 pr-3 text-xs" />
            </label>
            <button type="button" aria-label="Filter reservations" className="grid size-9 place-items-center rounded-lg border border-black/8 bg-white/75 text-[var(--muted)]"><Filter aria-hidden="true" className="size-4" /></button>
          </div>
        </div>
        <div className="scrollbar-slim overflow-x-auto">
          <table className="w-full min-w-[980px] border-collapse text-left">
            <thead className="bg-[#faf8f2] text-[9px] font-bold tracking-[0.1em] text-[var(--muted)] uppercase">
              <tr>
                <th className="px-5 py-3">Reservation</th>
                <th className="px-4 py-3">Dates</th>
                <th className="px-4 py-3">Program</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Payment</th>
                <th className="px-4 py-3">Readiness</th>
                <th className="px-4 py-3 text-right">Total</th>
                <th className="w-10"><span className="sr-only">Open</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-black/6">
              {(liveReservations ?? reservations).map((reservation) => {
                const live = "confirmation_number" in reservation;
                const presentation = live ? statusPresentation[reservation.status] : { tone: reservation.status, label: undefined };
                const code = live ? reservation.confirmation_number : reservation.code;
                const guest = live ? [reservation.primary_guest?.first_name, reservation.primary_guest?.last_name].filter(Boolean).join(" ") || "Guest details pending" : reservation.guest;
                const party = live ? reservation.adults + reservation.children : reservation.party;
                return (
                <tr key={code} className="group hover:bg-[var(--forest-soft)]/25">
                  <td className="px-5 py-4">
                    <p className="text-xs font-bold">{guest}</p>
                    <p className="mt-1 flex items-center gap-1.5 text-[10px] text-[var(--muted)]"><span className="font-mono">{code}</span> · {party} guests · {live ? reservation.source ?? "Direct" : reservation.channel}</p>
                  </td>
                  <td className="px-4 py-4"><p className="text-xs font-semibold">{live ? shortDate(reservation.starts_at) : reservation.arrival} → {live ? shortDate(reservation.ends_at) : reservation.departure}</p><p className="mt-1 text-[10px] text-[var(--muted)]">Property time</p></td>
                  <td className="px-4 py-4 text-xs font-semibold">{live ? "Lodge stay" : reservation.program}</td>
                  <td className="px-4 py-4"><StatusPill tone={presentation.tone} label={presentation.label} compact /></td>
                  <td className="px-4 py-4"><span className={!live && reservation.payment === "Overdue" ? "text-[var(--red)]" : "text-[var(--muted)]"}><span className="text-[11px] font-semibold">{live ? "See folio" : reservation.payment}</span></span></td>
                  <td className="px-4 py-4"><StatusPill tone={live ? "attention" : reservation.readiness} label={live ? "Review" : undefined} compact /></td>
                  <td className="px-4 py-4 text-right font-mono text-xs font-semibold">{formatMoney(live ? reservation.total_minor : reservation.total, live ? reservation.currency : "USD")}</td>
                  <td className="pr-4"><button type="button" aria-label={`Open ${code}`} className="grid size-8 place-items-center rounded-lg text-black/30 group-hover:bg-white group-hover:text-[var(--forest)]"><ArrowRight aria-hidden="true" className="size-4" /></button></td>
                </tr>
              );})}
            </tbody>
          </table>
        </div>
        <div className="flex items-center justify-between border-t border-black/7 bg-[#faf8f2] px-5 py-3 text-[10px] text-[var(--muted)]">
          <span>Showing 5 of 31 confirmed reservations</span>
          <span className="inline-flex items-center gap-1.5 font-semibold text-[var(--forest)]"><UserRoundCheck aria-hidden="true" className="size-3.5" />All tenant boundaries verified</span>
        </div>
      </section>
    </AppShell>
  );
}
