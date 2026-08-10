import type { Metadata } from "next";
import { AlertCircle, ArrowUpRight, HeartHandshake, Search, Sparkles, UsersRound } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { guests } from "@/lib/demo-data";
import { formatMoney, initials } from "@/lib/utils";

export const metadata: Metadata = { title: "Guests & CRM" };

export default function GuestsPage() {
  return (
    <AppShell
      eyebrow="Relationships"
      title="Guests & CRM"
      description="A complete, privacy-aware memory of every guest, preference, conversation, visit, and referral."
      action={{ label: "Add guest", shortLabel: "Guest" }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><UsersRound aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">1,284</p><p className="text-[10px] font-semibold text-[var(--muted)]">Guest profiles</p></div></article>
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--amber-soft)] text-[var(--amber)]"><HeartHandshake aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">38%</p><p className="text-[10px] font-semibold text-[var(--muted)]">Repeat guest rate</p></div></article>
        <article className="surface-card flex items-center gap-4 rounded-2xl p-4"><span className="grid size-11 place-items-center rounded-xl bg-[var(--blue-soft)] text-[var(--blue)]"><Sparkles aria-hidden="true" className="size-5" /></span><div><p className="font-display text-2xl font-semibold">12</p><p className="text-[10px] font-semibold text-[var(--muted)]">Re-engagement opportunities</p></div></article>
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="guest-directory-heading">
        <div className="flex flex-col gap-3 border-b border-black/7 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div><h2 id="guest-directory-heading" className="text-sm font-bold">Guest directory</h2><p className="mt-1 text-xs text-[var(--muted)]">Recent and upcoming guests</p></div>
          <label className="relative sm:w-64"><span className="sr-only">Search guests</span><Search aria-hidden="true" className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" /><input placeholder="Name, email, interest…" className="h-9 w-full rounded-lg border border-black/8 bg-white/75 pl-9 pr-3 text-xs" /></label>
        </div>
        <div className="grid divide-y divide-black/6 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
          {guests.map((guest, index) => (
            <article key={guest.name} className={`group p-5 hover:bg-[var(--forest-soft)]/20 ${index > 1 ? "lg:border-t lg:border-black/6" : ""}`}>
              <div className="flex items-start gap-4">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[var(--forest)] text-xs font-bold text-white">{initials(guest.name)}</span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-start justify-between gap-3">
                    <div><h3 className="text-sm font-bold">{guest.name}</h3><p className="mt-1 text-[10px] text-[var(--muted)]">{guest.email} · {guest.country}</p></div>
                    <button type="button" aria-label={`Open ${guest.name}`} className="grid size-8 place-items-center rounded-lg text-black/30 group-hover:bg-white group-hover:text-[var(--forest)]"><ArrowUpRight aria-hidden="true" className="size-4" /></button>
                  </div>
                  <div className="mt-4 grid grid-cols-3 gap-3 border-y border-black/6 py-3">
                    <div><p className="font-mono text-xs font-bold">{guest.visits}</p><p className="mt-1 text-[9px] text-[var(--muted)]">Visits</p></div>
                    <div><p className="text-xs font-bold">{guest.lastStay}</p><p className="mt-1 text-[9px] text-[var(--muted)]">Current</p></div>
                    <div><p className="font-mono text-xs font-bold">{formatMoney(guest.value)}</p><p className="mt-1 text-[9px] text-[var(--muted)]">Lifetime</p></div>
                  </div>
                  <div className="mt-3 flex flex-wrap items-center gap-1.5">
                    {guest.preferences.map((preference) => <span key={preference} className="rounded-full bg-black/5 px-2 py-1 text-[9px] font-medium text-[var(--muted)]">{preference}</span>)}
                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[9px] font-semibold ${guest.dietary === "No restrictions" ? "bg-[var(--forest-soft)] text-[var(--forest)]" : "bg-[var(--red-soft)] text-[var(--red)]"}`}><AlertCircle aria-hidden="true" className="size-3" />{guest.dietary}</span>
                  </div>
                </div>
              </div>
            </article>
          ))}
        </div>
      </section>
    </AppShell>
  );
}
