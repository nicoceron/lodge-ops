import type { Metadata } from "next";
import { AlertTriangle, Lightbulb, ShieldCheck } from "lucide-react";
import { AppShell } from "@/components/app-shell";
import { MasterCalendar } from "@/components/master-calendar";

export const metadata: Metadata = { title: "Master calendar" };

export default function CalendarPage() {
  return (
    <AppShell
      eyebrow="Operations"
      title="Master calendar"
      description="See rooms, people, activities, and equipment on the same timeline. Every assignment is checked before it reaches this plan."
      action={{ label: "Place a hold", shortLabel: "New hold" }}
    >
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]"><ShieldCheck aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">No hard conflicts</p><p className="mt-1 text-[10px] text-[var(--muted)]">All exclusive resources fit</p></div>
        </div>
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--amber-soft)] text-[var(--amber)]"><AlertTriangle aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">1 missing assignment</p><p className="mt-1 text-[10px] text-[var(--muted)]">Spanish hunting guide · Aug 11–15</p></div>
        </div>
        <div className="surface-card flex items-center gap-3 rounded-2xl p-4">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--blue-soft)] text-[var(--blue)]"><Lightbulb aria-hidden="true" className="size-5" /></span>
          <div><p className="text-xs font-bold">2 smart suggestions</p><p className="mt-1 text-[10px] text-[var(--muted)]">Available alternatives are ready</p></div>
        </div>
      </div>
      <div className="mt-5"><MasterCalendar /></div>
    </AppShell>
  );
}
