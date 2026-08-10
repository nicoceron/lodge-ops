import type { Metadata } from "next";
import Link from "next/link";
import { ArrowRight, CircleCheck, Clock3, MapPin, Plane, UsersRound } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { MasterCalendar } from "@/components/master-calendar";
import { MetricCard } from "@/components/metric-card";
import { StatusPill } from "@/components/status-pill";
import { arrivals, dashboardStats, operationalTasks, readiness } from "@/lib/demo-data";
import { cn } from "@/lib/utils";

export const metadata: Metadata = { title: "Overview" };

export default function OverviewPage() {
  return (
    <AppShell
      eyebrow="Monday · 10 August"
      title="Good morning, Nico"
      description="The lodge is calm today. Three arrivals are expected and four details need your attention before service begins."
      action={{ label: "New reservation", shortLabel: "Reservation" }}
    >
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        {dashboardStats.map((stat) => <MetricCard key={stat.label} {...stat} />)}
      </div>

      <div className="mt-5 animate-enter">
        <MasterCalendar compact />
      </div>

      <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(340px,0.8fr)]">
        <section className="surface-card overflow-hidden rounded-2xl animate-enter-delay" aria-labelledby="arrivals-heading">
          <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
            <div>
              <h2 id="arrivals-heading" className="text-sm font-bold">Today&apos;s arrivals</h2>
              <p className="mt-1 text-xs text-[var(--muted)]">7 guests across 3 parties</p>
            </div>
            <Link href="/reservations" className="inline-flex items-center gap-1 text-xs font-bold text-[var(--forest)] hover:underline">
              All reservations <ArrowRight aria-hidden="true" className="size-3.5" />
            </Link>
          </div>
          <div className="divide-y divide-black/6">
            {arrivals.map((arrival) => (
              <article key={arrival.party} className="grid gap-4 px-5 py-4 sm:grid-cols-[64px_minmax(0,1fr)_auto] sm:items-center">
                <div>
                  <p className="font-mono text-sm font-bold">{arrival.time}</p>
                  <p className="mt-1 text-[9px] font-bold tracking-[0.1em] text-[var(--muted)] uppercase">Arrival</p>
                </div>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-sm font-bold">{arrival.party}</h3>
                    <span className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]"><UsersRound aria-hidden="true" className="size-3" />{arrival.guests}</span>
                  </div>
                  <p className="mt-1 text-xs text-[var(--muted)]">{arrival.program} · {arrival.stay}</p>
                  <p className="mt-2 flex items-center gap-1.5 text-[10px] text-[var(--muted)]">
                    {arrival.readiness === "ready" ? <Plane aria-hidden="true" className="size-3" /> : <Clock3 aria-hidden="true" className="size-3" />}
                    {arrival.transfer}
                  </p>
                </div>
                <StatusPill tone={arrival.readiness} compact />
              </article>
            ))}
          </div>
        </section>

        <section className="surface-card rounded-2xl p-5" aria-labelledby="readiness-heading">
          <div className="flex items-center justify-between">
            <div>
              <h2 id="readiness-heading" className="text-sm font-bold">Arrival readiness</h2>
              <p className="mt-1 text-xs text-[var(--muted)]">Next 7 days · 24 guests</p>
            </div>
            <div className="grid size-11 place-items-center rounded-full border-[5px] border-[var(--forest)] border-r-[var(--amber-soft)] font-mono text-[10px] font-bold">87%</div>
          </div>
          <div className="mt-5 space-y-4">
            {readiness.map((item) => {
              const percent = Math.round((item.complete / item.total) * 100);
              return (
                <div key={item.label}>
                  <div className="mb-1.5 flex justify-between text-[11px]">
                    <span className="font-semibold">{item.label}</span>
                    <span className="font-mono text-[var(--muted)]">{item.complete}/{item.total}</span>
                  </div>
                  <div className="h-1.5 overflow-hidden rounded-full bg-black/6">
                    <div className={cn("h-full rounded-full", percent === 100 ? "bg-[var(--forest)]" : "bg-[var(--amber)]")} style={{ width: `${percent}%` }} />
                  </div>
                </div>
              );
            })}
          </div>
          <Link href="/operations" className="mt-5 flex items-center justify-center gap-1.5 rounded-xl border border-black/8 bg-white/65 py-2.5 text-xs font-bold text-[var(--forest)] hover:bg-white">
            Open readiness board <ArrowRight aria-hidden="true" className="size-3.5" />
          </Link>
        </section>
      </div>

      <section className="surface-card mt-5 rounded-2xl" aria-labelledby="tasks-heading">
        <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
          <div>
            <h2 id="tasks-heading" className="text-sm font-bold">Operations pulse</h2>
            <p className="mt-1 text-xs text-[var(--muted)]">What needs a decision next</p>
          </div>
          <Link href="/operations" className="text-xs font-bold text-[var(--forest)] hover:underline">View task board</Link>
        </div>
        <div className="grid divide-y divide-black/6 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
          <div className="divide-y divide-black/6">
            {operationalTasks.map((task) => (
              <div key={task.title} className="flex items-center gap-3 px-5 py-3.5">
                <span className={cn("grid size-7 place-items-center rounded-full border", task.done ? "border-[var(--forest)] bg-[var(--forest)] text-white" : "border-black/12 bg-white text-black/25")}>
                  <CircleCheck aria-hidden="true" className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className={cn("truncate text-xs font-bold", task.done && "text-[var(--muted)] line-through")}>{task.title}</p>
                  <p className="mt-1 truncate text-[10px] text-[var(--muted)]">{task.meta}</p>
                </div>
                <span className="grid size-7 place-items-center rounded-lg bg-black/5 text-[9px] font-bold">{task.owner}</span>
              </div>
            ))}
          </div>
          <div className="subtle-grid flex min-h-48 flex-col items-center justify-center p-8 text-center">
            <span className="grid size-12 place-items-center rounded-2xl bg-[var(--forest-soft)] text-[var(--forest)]"><MapPin aria-hidden="true" className="size-5" /></span>
            <p className="mt-4 font-display text-2xl font-semibold">No capacity conflicts</p>
            <p className="mt-1 max-w-sm text-xs leading-5 text-[var(--muted)]">All confirmed rooms and shared resources fit. One guide requirement is still unassigned.</p>
          </div>
        </div>
      </section>
    </AppShell>
  );
}
