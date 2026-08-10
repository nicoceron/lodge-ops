import type { Metadata } from "next";
import Link from "next/link";
import { ArrowUpRight, HeartHandshake, Search, Sparkles, UsersRound } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { demoModeEnabled } from "@/data/api-client";
import { listGuests, type GuestDto } from "@/data/staff-api";
import { guests as demoGuests } from "@/lib/demo-data";
import { initials } from "@/lib/utils";

export const metadata: Metadata = { title: "Guests & CRM" };

export default async function GuestsPage({ searchParams }: { searchParams: Promise<{ q?: string }> }) {
  const query = (await searchParams).q?.trim() ?? "";
  let liveGuests: GuestDto[] = [];
  let error = "";
  if (!demoModeEnabled) {
    try { liveGuests = (await listGuests(query)).data; } catch (reason) { error = reason instanceof Error ? reason.message : "The guest directory is unavailable."; }
  }
  const records = demoModeEnabled
    ? demoGuests.map((guest, index) => ({ id: `demo-${index}`, full_name: guest.name, email: guest.email, phone: null, language: guest.country, preferences: Object.fromEntries(guest.preferences.map((value) => [value, true])), marketing_consent: true }))
    : liveGuests;
  const withPreferences = records.filter((guest) => guest.preferences && Object.keys(guest.preferences).length).length;

  return (
    <AppShell eyebrow="Relationships" title="Guests & CRM" description="Search live guest profiles, preserve service preferences, and open the complete stay history without leaving the tenant workspace." action={{ label: "Add guest", shortLabel: "Guest", href: "/guests/new" }}>
      {error ? <DataState kind="error" title="Guest directory unavailable" description={error} /> : null}
      <div className="grid gap-3 sm:grid-cols-3">
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><UsersRound aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">{records.length}</p><p className="text-[10px] font-semibold text-[var(--muted)]">Visible profiles</p></div></article>
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--amber-soft)] text-[var(--amber)]"><HeartHandshake aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">{records.filter((guest) => guest.marketing_consent).length}</p><p className="text-[10px] font-semibold text-[var(--muted)]">Marketing consent</p></div></article>
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--blue-soft)] text-[var(--blue)]"><Sparkles aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">{withPreferences}</p><p className="text-[10px] font-semibold text-[var(--muted)]">Profiles with preferences</p></div></article>
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="guest-directory-heading">
        <div className="flex flex-col gap-3 border-b border-black/7 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 id="guest-directory-heading" className="text-sm font-bold">Guest directory</h2><p className="mt-1 text-xs text-[var(--muted)]">{query ? `Results for “${query}”` : "Current tenant profiles"}</p></div>
          <form role="search" className="flex gap-2">
            <label className="relative sm:w-64"><span className="sr-only">Search guests</span><Search aria-hidden="true" className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input name="q" defaultValue={query} placeholder="Name, email or phone" className="h-9 w-full rounded-lg border border-black/8 bg-white/75 pl-9 pr-3 text-xs" /></label>
            <button className="rounded-lg bg-[var(--forest)] px-3 text-xs font-bold text-white" type="submit">Search</button>
          </form>
        </div>
        <div className="grid divide-y divide-black/6 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
          {records.map((guest, index) => (
            <Link href={`/guests/${guest.id}`} key={guest.id} className={`group p-5 hover:bg-[var(--forest-soft)]/20 ${index > 1 ? "lg:border-t lg:border-black/6" : ""}`}>
              <div className="flex items-start gap-4">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[var(--forest)] text-xs font-bold text-white">{initials(guest.full_name)}</span>
                <div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-3"><div><h3 className="text-sm font-bold">{guest.full_name}</h3><p className="mt-1 text-[10px] text-[var(--muted)]">{guest.email || "No email"}{guest.phone ? ` · ${guest.phone}` : ""}</p></div><ArrowUpRight aria-hidden="true" className="size-4 text-black/30 group-hover:text-[var(--forest)]" /></div><div className="mt-4 flex flex-wrap gap-1.5">{Object.entries(guest.preferences ?? {}).slice(0, 4).map(([label, value]) => <span key={label} className="rounded-full bg-black/5 px-2 py-1 text-[9px] text-[var(--muted)]">{label}: {String(value)}</span>)}{guest.language ? <span className="rounded-full bg-[var(--blue-soft)] px-2 py-1 text-[9px] font-semibold text-[var(--blue)]">{guest.language.toUpperCase()}</span> : null}</div></div>
              </div>
            </Link>
          ))}
          {!records.length && !error ? <div className="col-span-2 px-5 py-14 text-center"><p className="text-sm font-semibold">No guests found</p><p className="mt-1 text-xs text-[var(--muted)]">Try another search or add a guest profile.</p></div> : null}
        </div>
      </section>
    </AppShell>
  );
}
