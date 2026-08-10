import type { Metadata } from "next";
import Link from "next/link";
import { ArrowRight, BedDouble, ContactRound, Search, TentTree } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { demoModeEnabled, listReservations, type ReservationDto } from "@/data/api-client";
import { listGuests, listResources, type GuestDto, type ResourceDto } from "@/data/staff-api";

export const metadata: Metadata = { title: "Search" };

export default async function SearchPage({ searchParams }: { searchParams: Promise<{ q?: string }> }) {
  const query = (await searchParams).q?.trim() ?? "";
  let guests: GuestDto[] = [];
  let reservations: ReservationDto[] = [];
  let resources: ResourceDto[] = [];
  let error: string | null = null;
  if (query && !demoModeEnabled) {
    try {
      const [guestResponse, reservationResponse, resourceResponse] = await Promise.all([listGuests(query), listReservations(), listResources()]);
      guests = guestResponse.data;
      const lowered = query.toLowerCase();
      reservations = reservationResponse.data.filter((reservation) => `${reservation.confirmation_number} ${reservation.primary_guest?.first_name ?? ""} ${reservation.primary_guest?.last_name ?? ""} ${reservation.program?.name ?? ""}`.toLowerCase().includes(lowered));
      resources = resourceResponse.data.filter((resource) => `${resource.name} ${resource.code} ${resource.type}`.toLowerCase().includes(lowered));
    } catch (reason) { error = reason instanceof Error ? reason.message : "Search could not be completed."; }
  }
  const total = guests.length + reservations.length + resources.length;

  return <AppShell eyebrow="Workspace" title="Search" description="Find guests, reservation records, and operational resources across the active tenant.">
    <form action="/search" role="search" className="surface-card flex gap-2 rounded-2xl p-3"><label className="relative flex-1"><span className="sr-only">Search workspace</span><Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input autoFocus name="q" defaultValue={query} placeholder="Name, confirmation number, room, guide…" className="field-input pl-10" /></label><button className="rounded-xl bg-[var(--forest)] px-5 text-xs font-bold text-white">Search</button></form>
    {error ? <div className="mt-5"><DataState kind="error" title="Search unavailable" description={error} /></div> : null}
    {!query ? <div className="mt-5"><DataState kind="empty" title="Start with a name or reference" description="Search the live tenant without exposing records from another lodge." /></div> : null}
    {query && !error ? <div className="mt-5 grid gap-5 lg:grid-cols-3">
      <ResultSection icon={<ContactRound className="size-4" />} title="Guests" count={guests.length}>{guests.map((guest) => <ResultLink key={guest.id} href={`/guests/${guest.id}`} title={guest.full_name} note={guest.email ?? guest.phone ?? "Guest profile"} />)}</ResultSection>
      <ResultSection icon={<TentTree className="size-4" />} title="Reservations" count={reservations.length}>{reservations.map((reservation) => <ResultLink key={reservation.id} href={`/reservations/${reservation.id}`} title={reservation.confirmation_number} note={`${reservation.primary_guest?.first_name ?? "Guest"} · ${reservation.status.replaceAll("_", " ")}`} />)}</ResultSection>
      <ResultSection icon={<BedDouble className="size-4" />} title="Resources" count={resources.length}>{resources.map((resource) => <ResultLink key={resource.id} href="/calendar" title={resource.name} note={`${resource.code} · ${resource.type}`} />)}</ResultSection>
    </div> : null}
    {query && !error && total === 0 ? <p className="mt-5 rounded-xl bg-white/60 px-4 py-8 text-center text-xs text-[var(--muted)]">No live records match “{query}”.</p> : null}
  </AppShell>;
}

function ResultSection({ icon, title, count, children }: { icon: React.ReactNode; title: string; count: number; children: React.ReactNode }) { return <section className="surface-card rounded-2xl p-4"><div className="mb-3 flex items-center justify-between"><h2 className="flex items-center gap-2 text-xs font-bold">{icon}{title}</h2><span className="rounded-full bg-black/5 px-2 py-1 text-[9px] font-bold">{count}</span></div><div className="space-y-2">{count ? children : <p className="rounded-xl bg-[#faf8f2] px-3 py-6 text-center text-[10px] text-[var(--muted)]">No matching {title.toLowerCase()}</p>}</div></section>; }
function ResultLink({ href, title, note }: { href: string; title: string; note: string }) { return <Link href={href} className="group flex items-center justify-between gap-3 rounded-xl bg-[#faf8f2] p-3 hover:bg-[var(--forest-soft)]"><span className="min-w-0"><span className="block truncate text-xs font-bold">{title}</span><span className="mt-1 block truncate text-[10px] text-[var(--muted)]">{note}</span></span><ArrowRight className="size-3.5 shrink-0 text-black/30 group-hover:text-[var(--forest)]" /></Link>; }
