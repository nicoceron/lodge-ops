import type { Metadata } from "next";
import { ArrowDownRight, ArrowUpRight, BadgeDollarSign, Landmark, ReceiptText, WalletCards } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { channelPerformance, revenueSeries } from "@/lib/demo-data";
import { formatMoney } from "@/lib/utils";

export const metadata: Metadata = { title: "Finance" };

export default function FinancePage() {
  const max = Math.max(...revenueSeries);
  const points = revenueSeries.map((value, index) => `${(index / (revenueSeries.length - 1)) * 100},${96 - (value / max) * 82}`).join(" ");

  return (
    <AppShell
      eyebrow="Owner view"
      title="Financial pulse"
      description="Revenue, collections, costs, and channel margin reconciled to the reservation and folio ledger. No operational edits from this view."
    >
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          { label: "Booked revenue", value: "$184k", note: "+14% vs Jul", icon: BadgeDollarSign, tone: "bg-[var(--forest-soft)] text-[var(--forest)]" },
          { label: "Cash collected", value: "$125k", note: "68% of booked", icon: Landmark, tone: "bg-[var(--blue-soft)] text-[var(--blue)]" },
          { label: "Receivables", value: "$59k", note: "$9.2k overdue", icon: ReceiptText, tone: "bg-[var(--red-soft)] text-[var(--red)]" },
          { label: "Gross margin", value: "64%", note: "+3.1 points", icon: WalletCards, tone: "bg-[var(--amber-soft)] text-[var(--amber)]" },
        ].map((item) => { const Icon = item.icon; return <article key={item.label} className="surface-card rounded-2xl p-4"><div className="flex items-start justify-between"><div><p className="text-[10px] font-semibold text-[var(--muted)]">{item.label}</p><p className="mt-2 font-display text-3xl font-semibold">{item.value}</p></div><span className={`grid size-9 place-items-center rounded-xl ${item.tone}`}><Icon aria-hidden="true" className="size-4" /></span></div><p className="mt-3 text-[10px] font-semibold text-[var(--muted)]">{item.note}</p></article>; })}
      </div>

      <div className="mt-5 grid gap-5 xl:grid-cols-[1.35fr_0.8fr]">
        <section className="surface-card rounded-2xl p-5" aria-labelledby="revenue-heading">
          <div className="flex items-start justify-between"><div><h2 id="revenue-heading" className="text-sm font-bold">Booked revenue</h2><p className="mt-1 text-xs text-[var(--muted)]">February–August · USD equivalent at booking rate</p></div><span className="inline-flex items-center gap-1 rounded-full bg-[var(--forest-soft)] px-2 py-1 text-[10px] font-bold text-[var(--forest)]"><ArrowUpRight aria-hidden="true" className="size-3" />22% season to date</span></div>
          <div className="mt-8 h-56 w-full">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Booked revenue increased from 92 thousand dollars in February to 184 thousand in August" className="h-full w-full overflow-visible">
              {[20, 40, 60, 80].map((y) => <line key={y} x1="0" x2="100" y1={y} y2={y} stroke="currentColor" className="text-black/6" vectorEffect="non-scaling-stroke" />)}
              <polyline points={points} fill="none" stroke="var(--forest)" strokeWidth="3" vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" />
              {revenueSeries.map((value, index) => <circle key={value} cx={(index / (revenueSeries.length - 1)) * 100} cy={96 - (value / max) * 82} r="1.2" fill="var(--surface)" stroke="var(--forest)" strokeWidth="0.8" />)}
            </svg>
          </div>
          <div className="mt-3 flex justify-between text-[9px] font-semibold text-[var(--muted)]">{["Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug"].map((month) => <span key={month}>{month}</span>)}</div>
        </section>

        <section className="surface-card rounded-2xl p-5" aria-labelledby="program-margin-heading">
          <div><h2 id="program-margin-heading" className="text-sm font-bold">Margin by program</h2><p className="mt-1 text-xs text-[var(--muted)]">After guide, agency, and variable costs</p></div>
          <div className="mt-5 space-y-5">{[
            ["Patagonian Double", 72, "$81k"], ["Red Stag Hunting", 61, "$54k"], ["Field & Table", 68, "$28k"], ["Lodge stays", 79, "$21k"],
          ].map(([label, margin, revenue]) => <div key={label as string}><div className="mb-2 flex items-center justify-between text-[11px]"><span className="font-semibold">{label}</span><span className="font-mono text-[var(--muted)]">{revenue} · {margin}%</span></div><div className="h-2 rounded-full bg-black/6"><div className="h-full rounded-full bg-[var(--forest)]" style={{ width: `${margin}%` }} /></div></div>)}</div>
        </section>
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="channels-heading">
        <div className="border-b border-black/7 px-5 py-4"><h2 id="channels-heading" className="text-sm font-bold">Channel performance</h2><p className="mt-1 text-xs text-[var(--muted)]">Commission-aware view of where profitable demand comes from</p></div>
        <div className="scrollbar-slim overflow-x-auto"><table className="w-full min-w-[680px] text-left text-xs"><thead className="bg-[#faf8f2] text-[9px] tracking-[0.1em] text-[var(--muted)] uppercase"><tr><th className="px-5 py-3">Channel</th><th className="px-4 py-3">Bookings</th><th className="px-4 py-3">Revenue</th><th className="px-4 py-3">Margin</th><th className="px-5 py-3 text-right">Signal</th></tr></thead><tbody className="divide-y divide-black/6">{channelPerformance.map((item, index) => <tr key={item.channel}><td className="px-5 py-4 font-bold">{item.channel}</td><td className="px-4 py-4 font-mono">{item.bookings}</td><td className="px-4 py-4 font-mono">{formatMoney(item.revenue)}</td><td className="px-4 py-4 font-mono">{item.margin}%</td><td className="px-5 py-4 text-right"><span className={`inline-flex items-center gap-1 font-bold ${index < 2 ? "text-[var(--forest)]" : "text-[var(--red)]"}`}>{index < 2 ? <ArrowUpRight aria-hidden="true" className="size-3" /> : <ArrowDownRight aria-hidden="true" className="size-3" />}{index < 2 ? "Growing" : "Review"}</span></td></tr>)}</tbody></table></div>
      </section>
    </AppShell>
  );
}
