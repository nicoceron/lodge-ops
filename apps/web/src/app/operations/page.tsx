import type { Metadata } from "next";
import { ChefHat, CircleCheck, Clock3, CookingPot, House, Languages, UtensilsCrossed } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { operationalTasks } from "@/lib/demo-data";
import { cn } from "@/lib/utils";

export const metadata: Metadata = { title: "Operations" };

const restrictions = [
  { label: "Gluten-free", count: 3, note: "1 camp lunch" },
  { label: "Nut allergy", count: 1, note: "Severe · separate prep" },
  { label: "Pescatarian", count: 2, note: "Arriving Aug 15" },
  { label: "No dairy", count: 1, note: "Breakfast only" },
];

const guideAssignments = [
  { guide: "Mateo Ríos", program: "Miller · Río Gallegos", time: "07:00", detail: "4 guests · 2:1", status: "Confirmed" },
  { guide: "Ana Torres", program: "Alvarez · Ridge trek", time: "08:30", detail: "2 guests · ES", status: "Confirmed" },
  { guide: "Unassigned", program: "Northwater · Red Stag", time: "05:45", detail: "1 guest · EN", status: "Action needed" },
];

export default function OperationsPage() {
  return (
    <AppShell
      eyebrow="Daily service"
      title="Operations"
      description="Role-specific briefs for hosts, guides, kitchen, and housekeeping—with only the guest information each team needs."
      action={{ label: "Create task", shortLabel: "Task" }}
    >
      <div className="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
        <section className="surface-card rounded-2xl" aria-labelledby="task-board-heading">
          <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
            <div><h2 id="task-board-heading" className="text-sm font-bold">Today&apos;s readiness board</h2><p className="mt-1 text-xs text-[var(--muted)]">14 of 18 tasks complete</p></div>
            <span className="rounded-full bg-[var(--amber-soft)] px-2.5 py-1 text-[10px] font-bold text-[var(--amber)]">4 open</span>
          </div>
          <div className="divide-y divide-black/6">
            {operationalTasks.map((task) => (
              <div key={task.title} className="flex items-center gap-3 px-5 py-4">
                <button type="button" aria-label={`${task.done ? "Reopen" : "Complete"} ${task.title}`} className={cn("grid size-8 place-items-center rounded-full border", task.done ? "border-[var(--forest)] bg-[var(--forest)] text-white" : "border-black/12 bg-white text-black/20")}><CircleCheck aria-hidden="true" className="size-4" /></button>
                <div className="min-w-0 flex-1"><p className={cn("truncate text-xs font-bold", task.done && "text-[var(--muted)] line-through")}>{task.title}</p><p className="mt-1 flex items-center gap-1 truncate text-[10px] text-[var(--muted)]"><Clock3 aria-hidden="true" className="size-3" />{task.meta}</p></div>
                <span className="grid size-8 place-items-center rounded-lg bg-black/5 text-[9px] font-bold">{task.owner}</span>
              </div>
            ))}
          </div>
          <div className="border-t border-black/7 bg-[#faf8f2] px-5 py-3 text-[10px] text-[var(--muted)]">Generated from program templates · manual edits are preserved</div>
        </section>

        <section className="surface-card overflow-hidden rounded-2xl" aria-labelledby="kitchen-heading">
          <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
            <div><h2 id="kitchen-heading" className="text-sm font-bold">Kitchen brief</h2><p className="mt-1 text-xs text-[var(--muted)]">Dinner · 20 guests · 3 staff</p></div>
            <span className="grid size-10 place-items-center rounded-xl bg-[var(--red-soft)] text-[var(--red)]"><ChefHat aria-hidden="true" className="size-5" /></span>
          </div>
          <div className="grid grid-cols-2 gap-px bg-black/6">
            {restrictions.map((item) => (
              <div key={item.label} className="bg-[var(--surface)] p-4"><p className="font-display text-2xl font-semibold">{item.count}</p><p className="mt-1 text-xs font-bold">{item.label}</p><p className="mt-1 text-[9px] text-[var(--muted)]">{item.note}</p></div>
            ))}
          </div>
          <div className="p-5">
            <div className="flex items-start gap-3 rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)]/65 p-3"><UtensilsCrossed aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--red)]" /><div><p className="text-[11px] font-bold text-[var(--red)]">Identity restricted</p><p className="mt-1 text-[10px] leading-4 text-[#825249]">Guest names are shown only when the kitchen must prevent a serious allergen exposure.</p></div></div>
          </div>
        </section>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <section className="surface-card rounded-2xl p-5" aria-labelledby="guides-heading">
          <div className="flex items-center justify-between"><div><h2 id="guides-heading" className="text-sm font-bold">Guide dispatch</h2><p className="mt-1 text-xs text-[var(--muted)]">Tomorrow&apos;s assignments</p></div><Languages aria-hidden="true" className="size-5 text-[var(--blue)]" /></div>
          <div className="mt-4 divide-y divide-black/6">
            {guideAssignments.map((assignment) => (
              <div key={assignment.program} className="flex items-center gap-3 py-3">
                <span className={cn("grid size-9 place-items-center rounded-xl text-[10px] font-bold", assignment.guide === "Unassigned" ? "bg-[var(--red-soft)] text-[var(--red)]" : "bg-[var(--blue-soft)] text-[var(--blue)]")}>{assignment.time}</span>
                <div className="min-w-0 flex-1"><p className="truncate text-xs font-bold">{assignment.guide}</p><p className="mt-1 truncate text-[10px] text-[var(--muted)]">{assignment.program} · {assignment.detail}</p></div>
                <span className={cn("text-[9px] font-bold", assignment.status === "Confirmed" ? "text-[var(--forest)]" : "text-[var(--red)]")}>{assignment.status}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="surface-card rounded-2xl p-5" aria-labelledby="housekeeping-heading">
          <div className="flex items-center justify-between"><div><h2 id="housekeeping-heading" className="text-sm font-bold">Housekeeping flow</h2><p className="mt-1 text-xs text-[var(--muted)]">Rooms today</p></div><House aria-hidden="true" className="size-5 text-[var(--forest)]" /></div>
          <div className="mt-5 grid grid-cols-3 gap-3 text-center"><div className="rounded-xl bg-[var(--forest-soft)] p-4"><p className="font-display text-3xl font-semibold">3</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Arrivals</p></div><div className="rounded-xl bg-[var(--amber-soft)] p-4"><p className="font-display text-3xl font-semibold">2</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Turnovers</p></div><div className="rounded-xl bg-[var(--blue-soft)] p-4"><p className="font-display text-3xl font-semibold">6</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Stayovers</p></div></div>
          <div className="mt-4 flex items-center gap-3 rounded-xl border border-black/7 bg-[#faf8f2] p-3"><CookingPot aria-hidden="true" className="size-4 text-[var(--amber)]" /><p className="text-[10px] text-[var(--muted)]"><strong className="text-[var(--foreground)]">River Cabin</strong> turnover due by 14:00 · picnic basket pickup at 13:30</p></div>
        </section>
      </div>
    </AppShell>
  );
}
