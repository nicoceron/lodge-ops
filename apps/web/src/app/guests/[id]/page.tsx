import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, CalendarDays, Mail, Phone } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { demoModeEnabled } from "@/data/api-client";
import { getGuestHistory, type GuestHistoryDto } from "@/data/staff-api";
import { guests as demoGuests } from "@/lib/demo-data";
import { formatMoney } from "@/lib/utils";

export const metadata: Metadata = { title: "Guest profile" };
export default async function GuestPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  let history: GuestHistoryDto;
  if (demoModeEnabled) {
    const record = demoGuests[Number(id.replace("demo-", ""))];
    if (!record) notFound();
    history = { guest: { id, first_name: record.name.split(" ")[0], last_name: record.name.split(" ").slice(1).join(" "), full_name: record.name, email: record.email, phone: null, document_type: null, document_number: null, language: "en", preferences: Object.fromEntries(record.preferences.map((preference) => [preference, true])), marketing_consent: true, created_at: new Date().toISOString(), updated_at: new Date().toISOString() }, reservations: [], stats: { stays: 0, lifetime_value_minor: 0, currency: "USD", last_stay_at: null } };
  } else { try { history = (await getGuestHistory(id)).data; } catch { notFound(); } }
  const { guest, reservations, stats } = history;
  return <AppShell eyebrow="Guest profile" title={guest.full_name} description="Contact details, preferences, and every stay linked to this tenant-owned profile." action={{ label: "Edit guest", href: `/guests/${id}/edit` }}>
    <Link href="/guests" className="mb-4 inline-flex items-center gap-1 text-xs font-bold text-[var(--forest)]"><ArrowLeft className="size-3.5" />Guest directory</Link>
    <div className="grid gap-5 xl:grid-cols-[0.7fr_1.3fr]">
      <aside className="surface-card rounded-2xl p-5"><div className="space-y-3 text-xs"><p className="flex items-center gap-2"><Mail className="size-4 text-[var(--muted)]" />{guest.email || "No email recorded"}</p><p className="flex items-center gap-2"><Phone className="size-4 text-[var(--muted)]" />{guest.phone || "No phone recorded"}</p></div><div className="mt-5 border-t border-black/7 pt-5"><h2 className="text-xs font-bold">Preferences</h2><dl className="mt-3 space-y-2">{Object.entries(guest.preferences ?? {}).map(([key, value]) => <div key={key} className="flex justify-between gap-4 text-[11px]"><dt className="capitalize text-[var(--muted)]">{key.replaceAll("_", " ")}</dt><dd className="text-right font-semibold">{String(value)}</dd></div>)}{!guest.preferences || !Object.keys(guest.preferences).length ? <p className="text-xs text-[var(--muted)]">No preferences recorded.</p> : null}</dl></div></aside>
      <section className="surface-card overflow-hidden rounded-2xl"><div className="grid grid-cols-3 gap-px bg-black/6"><Metric label="Stays" value={String(stats.stays)} /><Metric label="Lifetime value" value={formatMoney(stats.lifetime_value_minor, stats.currency)} /><Metric label="Last stay" value={stats.last_stay_at ? new Date(stats.last_stay_at).toLocaleDateString() : "—"} /></div><div className="border-t border-black/7 px-5 py-4"><h2 className="text-sm font-bold">Stay history</h2></div><div className="divide-y divide-black/6">{reservations.map((reservation) => <Link key={reservation.id} href={`/reservations/${reservation.id}`} className="flex items-center gap-4 px-5 py-4 hover:bg-[var(--forest-soft)]/25"><span className="grid size-9 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><CalendarDays className="size-4" /></span><span className="min-w-0 flex-1"><span className="block font-mono text-xs font-bold">{reservation.confirmation_number}</span><span className="mt-1 block text-[10px] text-[var(--muted)]">{new Date(reservation.starts_at).toLocaleDateString()} → {new Date(reservation.ends_at).toLocaleDateString()}</span></span><span className="text-[10px] font-bold capitalize">{reservation.status.replaceAll("_", " ")}</span></Link>)}{!reservations.length ? <p className="px-5 py-10 text-center text-xs text-[var(--muted)]">No stays linked yet.</p> : null}</div></section>
    </div>
  </AppShell>;
}
function Metric({ label, value }: { label: string; value: string }) { return <div className="bg-[var(--surface)] p-4"><p className="font-display text-xl font-semibold">{value}</p><p className="mt-1 text-[9px] text-[var(--muted)]">{label}</p></div>; }
