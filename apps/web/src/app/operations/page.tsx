import type { Metadata } from "next";
import { ChefHat, Clock3, CookingPot, House, Languages, UtensilsCrossed } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { DataState } from "@/components/data-state";
import { TaskStatusButton } from "@/components/staff/task-status-button";
import { loadOperationsProjection } from "@/data/staff-projections";
import { cn } from "@/lib/utils";

export const metadata: Metadata = { title: "Operations" };

export default async function OperationsPage() {
  const state = await loadOperationsProjection();
  const operations = state.data;
  const showKitchen = operations?.visibleSections.includes("kitchen") ?? false;
  const showGuides = operations?.visibleSections.includes("guide_assignments") ?? false;
  const showHousekeeping = operations?.visibleSections.includes("housekeeping") ?? false;

  return (
    <AppShell
      eyebrow="Daily service"
      title="Operations"
      description="Role-specific briefs for hosts, guides, kitchen, and housekeeping—with only the guest information each team needs."
      action={{ label: "Create task", shortLabel: "Task", href: "/operations/tasks/new" }}
    >
      {!operations ? <DataState kind="error" title="Operations board unavailable" description={state.error ?? "The live role-specific brief could not be loaded."} /> : null}
      {operations ? <>
      <div className={showKitchen ? "grid gap-5 xl:grid-cols-[1.15fr_0.85fr]" : "grid gap-5"}>
        <section className="surface-card rounded-2xl" aria-labelledby="task-board-heading">
          <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
            <div><h2 id="task-board-heading" className="text-sm font-bold">Today&apos;s readiness board</h2><p className="mt-1 text-xs text-[var(--muted)]">{operations.readiness.complete} of {operations.readiness.total} tasks complete</p></div>
            <span className="rounded-full bg-[var(--amber-soft)] px-2.5 py-1 text-[10px] font-bold text-[var(--amber)]">{operations.readiness.open} open</span>
          </div>
          <div className="divide-y divide-black/6">
            {operations.tasks.map((task) => (
              <div key={task.id} className="flex items-center gap-3 px-5 py-4">
                <TaskStatusButton id={task.id} title={task.title} done={task.done} demo={state.mode === "demo"} />
                <div className="min-w-0 flex-1"><p className={cn("truncate text-xs font-bold", task.done && "text-[var(--muted)] line-through")}>{task.title}</p><p className="mt-1 flex items-center gap-1 truncate text-[10px] text-[var(--muted)]"><Clock3 aria-hidden="true" className="size-3" />{task.meta}</p></div>
                <span className="grid size-8 place-items-center rounded-lg bg-black/5 text-[9px] font-bold">{task.owner}</span>
              </div>
            ))}
            {!operations.tasks.length ? <div className="px-5 py-10 text-center"><p className="text-sm font-semibold">Readiness queue is clear</p><p className="mt-1 text-xs text-[var(--muted)]">No tasks are due in the current service window.</p></div> : null}
          </div>
          <div className="border-t border-black/7 bg-[#faf8f2] px-5 py-3 text-[10px] text-[var(--muted)]">Generated from program templates · manual edits are preserved</div>
        </section>

        {showKitchen ? <section className="surface-card overflow-hidden rounded-2xl" aria-labelledby="kitchen-heading">
          <div className="flex items-center justify-between border-b border-black/7 px-5 py-4">
            <div><h2 id="kitchen-heading" className="text-sm font-bold">Kitchen brief</h2><p className="mt-1 text-xs text-[var(--muted)]">Current service window · {operations.kitchenGuests} guests</p></div>
            <span className="grid size-10 place-items-center rounded-xl bg-[var(--red-soft)] text-[var(--red)]"><ChefHat aria-hidden="true" className="size-5" /></span>
          </div>
          <div className="grid grid-cols-2 gap-px bg-black/6">
            {operations.restrictions.map((item) => (
              <div key={item.label} className="bg-[var(--surface)] p-4"><p className="font-display text-2xl font-semibold">{item.count}</p><p className="mt-1 text-xs font-bold">{item.label}</p><p className="mt-1 text-[9px] text-[var(--muted)]">{item.note}</p></div>
            ))}
            {!operations.restrictions.length ? <div className="col-span-2 bg-[var(--surface)] p-6 text-center text-xs text-[var(--muted)]">No dietary restrictions recorded for the current service window.</div> : null}
          </div>
          <div className="p-5">
            <div className="flex items-start gap-3 rounded-xl border border-[var(--red)]/15 bg-[var(--red-soft)]/65 p-3"><UtensilsCrossed aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[var(--red)]" /><div><p className="text-[11px] font-bold text-[var(--red)]">{operations.kitchenIdentityRestricted ? "Identity restricted" : "Operational access"}</p><p className="mt-1 text-[10px] leading-4 text-[#825249]">{operations.kitchenIdentityRestricted ? "Guest identity stays hidden; the brief exposes only the dietary details needed for safe preparation." : "This role may coordinate dietary follow-up with the guest profile when necessary."}</p></div></div>
          </div>
        </section> : null}
      </div>

      {showGuides || showHousekeeping ? <div className="mt-5 grid gap-5 lg:grid-cols-2">
        {showGuides ? <section className="surface-card rounded-2xl p-5" aria-labelledby="guides-heading">
          <div className="flex items-center justify-between"><div><h2 id="guides-heading" className="text-sm font-bold">Guide dispatch</h2><p className="mt-1 text-xs text-[var(--muted)]">Tomorrow&apos;s assignments</p></div><Languages aria-hidden="true" className="size-5 text-[var(--blue)]" /></div>
          <div className="mt-4 divide-y divide-black/6">
            {operations.guideAssignments.map((assignment) => (
              <div key={assignment.id} className="flex items-center gap-3 py-3">
                <span className={cn("grid size-9 place-items-center rounded-xl text-[10px] font-bold", assignment.guide === "Unassigned" ? "bg-[var(--red-soft)] text-[var(--red)]" : "bg-[var(--blue-soft)] text-[var(--blue)]")}>{assignment.time}</span>
                <div className="min-w-0 flex-1"><p className="truncate text-xs font-bold">{assignment.guide}</p><p className="mt-1 truncate text-[10px] text-[var(--muted)]">{assignment.program} · {assignment.detail}</p></div>
                <span className={cn("text-[9px] font-bold", assignment.status === "Confirmed" ? "text-[var(--forest)]" : "text-[var(--red)]")}>{assignment.status}</span>
              </div>
            ))}
            {!operations.guideAssignments.length ? <div className="py-8 text-center text-xs text-[var(--muted)]">No guided occurrences scheduled tomorrow.</div> : null}
          </div>
        </section> : null}

        {showHousekeeping ? <section className="surface-card rounded-2xl p-5" aria-labelledby="housekeeping-heading">
          <div className="flex items-center justify-between"><div><h2 id="housekeeping-heading" className="text-sm font-bold">Housekeeping flow</h2><p className="mt-1 text-xs text-[var(--muted)]">Rooms today</p></div><House aria-hidden="true" className="size-5 text-[var(--forest)]" /></div>
          <div className="mt-5 grid grid-cols-3 gap-3 text-center"><div className="rounded-xl bg-[var(--forest-soft)] p-4"><p className="font-display text-3xl font-semibold">{operations.housekeeping.arrivals}</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Arrivals</p></div><div className="rounded-xl bg-[var(--amber-soft)] p-4"><p className="font-display text-3xl font-semibold">{operations.housekeeping.turnovers}</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Turnovers</p></div><div className="rounded-xl bg-[var(--blue-soft)] p-4"><p className="font-display text-3xl font-semibold">{operations.housekeeping.stayovers}</p><p className="mt-1 text-[9px] font-bold text-[var(--muted)]">Stayovers</p></div></div>
          <div className="mt-4 flex items-center gap-3 rounded-xl border border-black/7 bg-[#faf8f2] p-3"><CookingPot aria-hidden="true" className="size-4 text-[var(--amber)]" /><p className="text-[10px] text-[var(--muted)]">{operations.housekeeping.focus ?? "No high-priority housekeeping focus is currently open."}</p></div>
        </section> : null}
      </div> : null}
      </> : null}
    </AppShell>
  );
}
