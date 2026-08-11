import type { Metadata } from "next";
import Link from "next/link";
import { ArrowRight, CalendarClock, Search, UserRoundCheck } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { StatusPill, type StatusTone } from "@/components/status-pill";
import { demoModeEnabled, listReservations, type ReservationDto } from "@/data/api-client";
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

export default async function ReservationsPage({ searchParams }: { searchParams: Promise<{ q?: string; status?: string }> }) {
  const params = await searchParams;
  const query = params.q?.trim().toLowerCase() ?? "";
  let liveReservations: ReservationDto[] | null = null;
  let liveError = false;
  if (!demoModeEnabled) {
    try {
      liveReservations = (await listReservations({ status: params.status })).data;
    } catch {
      liveReservations = [];
      liveError = true;
    }
  }
  const allReservations = demoModeEnabled ? reservations : liveReservations ?? [];
  const displayedReservations = query ? allReservations.filter((reservation) => {
    const live = "confirmation_number" in reservation;
    const haystack = live
      ? `${reservation.confirmation_number} ${reservation.primary_guest?.first_name ?? ""} ${reservation.primary_guest?.last_name ?? ""} ${reservation.program?.name ?? ""}`
      : `${reservation.code} ${reservation.guest} ${reservation.program}`;
    return haystack.toLowerCase().includes(query);
  }) : allReservations;
  const pipelineCards = demoModeEnabled ? pipeline : [
    { label: "Draft", value: liveReservations?.filter((item) => item.status === "draft").length ?? 0, note: "Live tenant", tone: "bg-[var(--blue-soft)] text-[var(--blue)]" },
    { label: "On hold", value: liveReservations?.filter((item) => item.status === "hold").length ?? 0, note: "Awaiting confirmation", tone: "bg-[var(--amber-soft)] text-[var(--amber)]" },
    { label: "Confirmed", value: liveReservations?.filter((item) => item.status === "confirmed").length ?? 0, note: "Upcoming stays", tone: "bg-[var(--forest-soft)] text-[var(--forest)]" },
    { label: "In house", value: liveReservations?.filter((item) => item.status === "checked_in").length ?? 0, note: "Current guests", tone: "bg-[var(--red-soft)] text-[var(--red)]" },
  ];

  return (
    <AppShell
      eyebrow="Sales & stays"
      title="Reservations"
      description="Move a guest from first inquiry to a fully prepared stay without re-entering information or losing the commercial history."
      action={{ label: "Create reservation", shortLabel: "Create", href: "/reservations/new" }}
    >
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {pipelineCards.map((item) => (
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
          <form action="/reservations" className="flex gap-2">
            <label className="relative flex-1 sm:w-52">
              <span className="sr-only">Search reservations</span>
              <Search aria-hidden="true" className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" />
              <input name="q" defaultValue={params.q} placeholder="Search reservations" className="h-9 w-full rounded-lg border border-black/8 bg-white/75 pl-9 pr-3 text-xs" />
            </label>
            <select name="status" defaultValue={params.status ?? ""} aria-label="Filter by status" className="h-9 rounded-lg border border-black/8 bg-white/75 px-3 text-xs"><option value="">All statuses</option>{Object.entries(statusPresentation).map(([value, item]) => <option key={value} value={value}>{item.label}</option>)}</select>
            <button className="h-9 rounded-lg bg-[var(--forest)] px-3 text-xs font-bold text-white">Apply</button>
          </form>
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
              {displayedReservations.map((reservation) => {
                const live = "confirmation_number" in reservation;
                const presentation = live ? statusPresentation[reservation.status] : { tone: reservation.status, label: undefined };
                const code = live ? reservation.confirmation_number : reservation.code;
                const guest = live ? [reservation.primary_guest?.first_name, reservation.primary_guest?.last_name].filter(Boolean).join(" ") || "Guest details pending" : reservation.guest;
                const party = live ? reservation.adults + reservation.children : reservation.party;
                const href = `/reservations/${live ? reservation.id : encodeURIComponent(reservation.code)}`;
                return (
                <tr key={code} className="group hover:bg-[var(--forest-soft)]/25">
                  <td className="px-5 py-4">
                    <p className="text-xs font-bold">{guest}</p>
                    <p className="mt-1 flex items-center gap-1.5 text-[10px] text-[var(--muted)]"><span className="font-mono">{code}</span> · {party} guests · {live ? reservation.source ?? "Direct" : reservation.channel}</p>
                  </td>
                  <td className="px-4 py-4"><p className="text-xs font-semibold">{live ? shortDate(reservation.starts_at) : reservation.arrival} → {live ? shortDate(reservation.ends_at) : reservation.departure}</p><p className="mt-1 text-[10px] text-[var(--muted)]">Property time</p></td>
                  <td className="px-4 py-4 text-xs font-semibold">{live ? reservation.program?.name ?? "Custom lodge stay" : reservation.program}</td>
                  <td className="px-4 py-4"><StatusPill tone={presentation.tone} label={presentation.label} compact /></td>
                  <td className="px-4 py-4"><span className={!live && reservation.payment === "Overdue" ? "text-[var(--red)]" : "text-[var(--muted)]"}><span className="text-[11px] font-semibold">{live ? "See folio" : reservation.payment}</span></span></td>
                  <td className="px-4 py-4"><StatusPill tone={live ? "attention" : reservation.readiness} label={live ? "Review" : undefined} compact /></td>
                  <td className="px-4 py-4 text-right font-mono text-xs font-semibold">{formatMoney(live ? reservation.total_minor : reservation.total, live ? reservation.currency : "USD")}</td>
                  <td className="pr-4"><Link href={href} aria-label={`Open ${code}`} className="grid size-8 place-items-center rounded-lg text-black/30 group-hover:bg-white group-hover:text-[var(--forest)]"><ArrowRight aria-hidden="true" className="size-4" /></Link></td>
                </tr>
              );})}
              {!displayedReservations.length ? <tr><td colSpan={8} className="px-5 py-12 text-center"><p className="text-sm font-semibold">{liveError ? "Live reservations unavailable" : "No reservations found"}</p><p className="mt-1 text-xs text-[var(--muted)]">{liveError ? "No demo reservations have been substituted. Try again after checking your session and API connection." : "Create the first reservation for this tenant to begin planning."}</p></td></tr> : null}
            </tbody>
          </table>
        </div>
        <div className="flex items-center justify-between border-t border-black/7 bg-[#faf8f2] px-5 py-3 text-[10px] text-[var(--muted)]">
          <span>Showing {displayedReservations.length} reservation{displayedReservations.length === 1 ? "" : "s"}</span>
          <span className="inline-flex items-center gap-1.5 font-semibold text-[var(--forest)]"><UserRoundCheck aria-hidden="true" className="size-3.5" />All tenant boundaries verified</span>
        </div>
      </section>
    </AppShell>
  );
}
