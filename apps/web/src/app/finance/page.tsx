import type { Metadata } from "next";
import { ArrowUpRight, BadgeDollarSign, ChartNoAxesCombined, Coins, Landmark, ReceiptText, WalletCards } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { loadFinanceProjection } from "@/data/staff-projections";
import { formatMoney } from "@/lib/utils";

export const metadata: Metadata = { title: "Finance" };

const metricIcons = [BadgeDollarSign, Landmark, ReceiptText, WalletCards, Coins, ChartNoAxesCombined];
const metricTones = {
  forest: "bg-[var(--forest-soft)] text-[var(--forest)]",
  blue: "bg-[var(--blue-soft)] text-[var(--blue)]",
  red: "bg-[var(--red-soft)] text-[var(--red)]",
  amber: "bg-[var(--amber-soft)] text-[var(--amber)]",
};

export default async function FinancePage() {
  const state = await loadFinanceProjection();
  const finance = state.data;
  const max = Math.max(...(finance?.series.map((item) => item.value) ?? []), 1);
  const points = finance?.series.map((item, index) => `${(index / Math.max(1, finance.series.length - 1)) * 100},${96 - (item.value / max) * 82}`).join(" ") ?? "";

  return (
    <AppShell
      eyebrow="Owner view"
      title="Financial pulse"
      description="Revenue, collections, deposits, and folio balances reconciled to tenant-owned reservations. Guest identity is intentionally excluded."
    >
      {!finance ? <DataState kind="error" title="Financial projection unavailable" description={state.error ?? "The live tenant ledger could not be loaded."} /> : null}
      {finance ? <>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">
        {finance.metrics.map((item, index) => {
          const Icon = metricIcons[index] ?? WalletCards;
          return (
            <article key={item.label} className="surface-card rounded-2xl p-4">
              <div className="flex items-start justify-between gap-3">
                <div><p className="text-[10px] font-semibold text-[var(--muted)]">{item.label}</p><p className="mt-2 font-display text-3xl font-semibold">{item.value}</p></div>
                <span className={`grid size-9 place-items-center rounded-xl ${metricTones[item.tone]}`}><Icon aria-hidden="true" className="size-4" /></span>
              </div>
              <p className="mt-3 text-[10px] font-semibold text-[var(--muted)]">{item.note}</p>
            </article>
          );
        })}
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="program-margin-heading">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-black/7 px-5 py-4"><div><h2 id="program-margin-heading" className="text-sm font-bold">Program profitability</h2><p className="mt-1 text-xs text-[var(--muted)]">Booked revenue less actual costs and accrued channel commissions</p></div><span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${finance.reconciliation.balanced ? "bg-[var(--forest-soft)] text-[var(--forest)]" : "bg-[var(--red-soft)] text-[var(--red)]"}`}>{finance.reconciliation.balanced ? "Ledger reconciled" : `Review ${formatMoney(finance.reconciliation.differenceMinor, finance.currency)}`}</span></div>
        {finance.programs.length ? <div className="scrollbar-slim overflow-x-auto"><table className="w-full min-w-[760px] text-left text-xs"><thead className="bg-[#faf8f2] text-[9px] tracking-[0.1em] text-[var(--muted)] uppercase"><tr><th className="px-5 py-3">Program</th><th className="px-4 py-3">Bookings</th><th className="px-4 py-3">Revenue</th><th className="px-4 py-3">Costs</th><th className="px-4 py-3">Commissions</th><th className="px-5 py-3 text-right">Margin</th></tr></thead><tbody className="divide-y divide-black/6">{finance.programs.map((program) => <tr key={program.id}><td className="px-5 py-4 font-bold">{program.name}</td><td className="px-4 py-4 font-mono">{program.bookings}</td><td className="px-4 py-4 font-mono">{formatMoney(program.revenueMinor, finance.currency)}</td><td className="px-4 py-4 font-mono">{formatMoney(program.costsMinor, finance.currency)}</td><td className="px-4 py-4 font-mono">{formatMoney(program.commissionsMinor, finance.currency)}</td><td className="px-5 py-4 text-right font-mono font-semibold">{formatMoney(program.marginMinor, finance.currency)}</td></tr>)}</tbody></table></div> : <div className="px-5 py-10 text-center text-xs text-[var(--muted)]">No program revenue is available for this period.</div>}
      </section>

      <div className="mt-5 grid gap-5 xl:grid-cols-[1.35fr_0.8fr]">
        <section className="surface-card rounded-2xl p-5" aria-labelledby="revenue-heading">
          <div className="flex items-start justify-between"><div><h2 id="revenue-heading" className="text-sm font-bold">Booked revenue</h2><p className="mt-1 text-xs text-[var(--muted)]">Seven-month arrival view · {finance.currency}</p></div><span className="inline-flex items-center gap-1 rounded-full bg-[var(--forest-soft)] px-2 py-1 text-[10px] font-bold text-[var(--forest)]"><ArrowUpRight aria-hidden="true" className="size-3" />{finance.periodLabel}</span></div>
          <div className="mt-8 h-56 w-full">
            {finance.series.some((item) => item.value > 0) ? (
              <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Seven month booked revenue trend" className="h-full w-full overflow-visible">
                {[20, 40, 60, 80].map((y) => <line key={y} x1="0" x2="100" y1={y} y2={y} stroke="currentColor" className="text-black/6" vectorEffect="non-scaling-stroke" />)}
                <polyline points={points} fill="none" stroke="var(--forest)" strokeWidth="3" vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" />
                {finance.series.map((item, index) => <circle key={`${item.label}-${index}`} cx={(index / Math.max(1, finance.series.length - 1)) * 100} cy={96 - (item.value / max) * 82} r="1.2" fill="var(--surface)" stroke="var(--forest)" strokeWidth="0.8" />)}
              </svg>
            ) : <div className="grid h-full place-items-center text-center"><div><p className="text-sm font-semibold">No booked revenue yet</p><p className="mt-1 text-xs text-[var(--muted)]">Live revenue will appear after a confirmed arrival enters the period.</p></div></div>}
          </div>
          <div className="mt-3 flex justify-between text-[9px] font-semibold text-[var(--muted)]">{finance.series.map((item) => <span key={item.label}>{item.label}</span>)}</div>
        </section>

        <section className="surface-card rounded-2xl p-5" aria-labelledby="ledger-heading">
          <div><h2 id="ledger-heading" className="text-sm font-bold">Ledger position</h2><p className="mt-1 text-xs text-[var(--muted)]">Deposit obligations and posted folio movements</p></div>
          <div className="mt-5 space-y-3">
            {[
              ["Deposits due", finance.deposits.dueMinor, `${finance.deposits.dueCount} open`],
              ["Deposits paid", finance.deposits.paidMinor, `${finance.deposits.paidCount} settled`],
              ["Folio charges", finance.folio.chargesMinor, "Posted this period"],
              ["Folio payments", finance.folio.paymentsMinor, "Credits received"],
            ].map(([label, amount, note]) => (
              <div key={String(label)} className="flex items-center justify-between rounded-xl border border-black/7 bg-[#faf8f2] p-3">
                <div><p className="text-xs font-bold">{label}</p><p className="mt-1 text-[9px] text-[var(--muted)]">{note}</p></div>
                <span className="font-mono text-xs font-semibold">{formatMoney(Number(amount), finance.currency)}</span>
              </div>
            ))}
          </div>
          {finance.deposits.overdueCount ? <p className="mt-4 rounded-xl bg-[var(--red-soft)] p-3 text-[10px] font-semibold text-[var(--red)]">{finance.deposits.overdueCount} overdue deposit{finance.deposits.overdueCount === 1 ? " requires" : "s require"} follow-up.</p> : null}
        </section>
      </div>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="channels-heading">
        <div className="border-b border-black/7 px-5 py-4"><h2 id="channels-heading" className="text-sm font-bold">Channel collections</h2><p className="mt-1 text-xs text-[var(--muted)]">Booked value and collection progress by source</p></div>
        {finance.channels.length ? <div className="scrollbar-slim overflow-x-auto"><table className="w-full min-w-[760px] text-left text-xs"><thead className="bg-[#faf8f2] text-[9px] tracking-[0.1em] text-[var(--muted)] uppercase"><tr><th className="px-5 py-3">Channel</th><th className="px-4 py-3">Bookings</th><th className="px-4 py-3">Revenue</th><th className="px-4 py-3">Commission</th><th className="px-4 py-3">Net revenue</th><th className="px-5 py-3 text-right">Collected</th></tr></thead><tbody className="divide-y divide-black/6">{finance.channels.map((item) => <tr key={item.channel}><td className="px-5 py-4 font-bold">{item.channel}</td><td className="px-4 py-4 font-mono">{item.bookings}</td><td className="px-4 py-4 font-mono">{formatMoney(item.revenueMinor, finance.currency)}</td><td className="px-4 py-4 font-mono">{formatMoney(item.commissionsMinor, finance.currency)}</td><td className="px-4 py-4 font-mono font-semibold">{formatMoney(item.netRevenueMinor, finance.currency)}</td><td className="px-5 py-4 text-right font-mono font-semibold">{item.collectionPercent}%</td></tr>)}</tbody></table></div> : <div className="px-5 py-10 text-center text-xs text-[var(--muted)]">No channel bookings in the current period.</div>}
      </section>

      <section className="surface-card mt-5 overflow-hidden rounded-2xl" aria-labelledby="folios-heading">
        <div className="border-b border-black/7 px-5 py-4"><h2 id="folios-heading" className="text-sm font-bold">Recent folio balances</h2><p className="mt-1 text-xs text-[var(--muted)]">Reservation references only · guest PII is excluded</p></div>
        {finance.recentFolios.length ? <div className="scrollbar-slim overflow-x-auto"><table className="w-full min-w-[680px] text-left text-xs"><thead className="bg-[#faf8f2] text-[9px] tracking-[0.1em] text-[var(--muted)] uppercase"><tr><th className="px-5 py-3">Reservation</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Total</th><th className="px-4 py-3">Paid</th><th className="px-5 py-3 text-right">Balance</th></tr></thead><tbody className="divide-y divide-black/6">{finance.recentFolios.map((folio) => <tr key={folio.id}><td className="px-5 py-4 font-mono font-bold">{folio.confirmationNumber}</td><td className="px-4 py-4 capitalize">{folio.status.replaceAll("_", " ")}</td><td className="px-4 py-4 font-mono">{formatMoney(folio.totalMinor, finance.currency)}</td><td className="px-4 py-4 font-mono">{formatMoney(folio.paidMinor, finance.currency)}</td><td className="px-5 py-4 text-right font-mono font-semibold">{formatMoney(folio.balanceMinor, finance.currency)}</td></tr>)}</tbody></table></div> : <div className="px-5 py-10 text-center text-xs text-[var(--muted)]">No folios are available for this period.</div>}
      </section>
      </> : null}
    </AppShell>
  );
}
