"use client";

import { useMemo, useState } from "react";
import {
  AlertTriangle,
  CalendarRange,
  ChevronLeft,
  ChevronRight,
  Filter,
  Layers3,
  Search,
} from "lucide-react";
import { calendarDays, calendarLanes } from "@/lib/demo-data";
import { cn } from "@/lib/utils";

const eventTone = {
  stag: "border-[#8c4438]/25 bg-[#f3d8d2] text-[#71372e]",
  double: "border-[#315e64]/25 bg-[#d9e9e8] text-[#274e53]",
  stay: "border-[#6c7f6e]/25 bg-[#e4ece3] text-[#455b47]",
  activity: "border-[#b56a2c]/25 bg-[#f7e1c6] text-[#844d20]",
  block: "border-black/8 bg-[#e7e6e1] text-[#646762]",
};

const groupOptions = ["All resources", "Rooms", "Guides", "Equipment"] as const;

export function MasterCalendar({ compact = false }: { compact?: boolean }) {
  const [group, setGroup] = useState<(typeof groupOptions)[number]>("All resources");
  const [query, setQuery] = useState("");
  const [weekOffset, setWeekOffset] = useState(0);

  const lanes = useMemo(() => {
    return calendarLanes.filter((lane) => {
      const groupMatch = group === "All resources" || lane.group === group;
      const queryMatch = `${lane.label} ${lane.detail}`.toLowerCase().includes(query.toLowerCase());
      return groupMatch && queryMatch;
    });
  }, [group, query]);

  const visibleLanes = compact ? lanes.slice(0, 4) : lanes;
  const dateLabel = weekOffset === 0 ? "10–16 August 2026" : weekOffset < 0 ? "3–9 August 2026" : "17–23 August 2026";

  return (
    <section className="surface-card overflow-hidden rounded-2xl" aria-labelledby="calendar-title">
      <div className="flex flex-col gap-4 border-b border-black/7 px-4 py-4 sm:px-5 xl:flex-row xl:items-center xl:justify-between">
        <div className="flex items-center gap-3">
          <span className="grid size-10 place-items-center rounded-xl bg-[var(--forest-soft)] text-[var(--forest)]">
            <CalendarRange aria-hidden="true" className="size-5" />
          </span>
          <div>
            <h2 id="calendar-title" className="text-sm font-bold">{compact ? "This week at a glance" : "Unified resource plan"}</h2>
            <p className="mt-0.5 text-xs text-[var(--muted)]">Property time · {dateLabel}</p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {!compact ? (
            <label className="relative">
              <span className="sr-only">Search resources</span>
              <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-black/35" />
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Find a resource"
                className="h-9 w-44 rounded-lg border border-black/8 bg-white/75 pl-9 pr-3 text-xs placeholder:text-black/35"
              />
            </label>
          ) : null}
          <label className="relative">
            <span className="sr-only">Filter by resource type</span>
            <Filter aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-black/40" />
            <select
              value={group}
              onChange={(event) => setGroup(event.target.value as (typeof groupOptions)[number])}
              className="h-9 appearance-none rounded-lg border border-black/8 bg-white/75 pl-8 pr-7 text-xs font-semibold"
            >
              {groupOptions.map((option) => <option key={option}>{option}</option>)}
            </select>
          </label>
          <div className="flex h-9 items-center rounded-lg border border-black/8 bg-white/75 p-0.5">
            <button
              type="button"
              aria-label="Previous week"
              onClick={() => setWeekOffset((value) => Math.max(-1, value - 1))}
              className="grid size-8 place-items-center rounded-md text-[var(--muted)] hover:bg-black/5"
            >
              <ChevronLeft aria-hidden="true" className="size-4" />
            </button>
            <button type="button" onClick={() => setWeekOffset(0)} className="px-2 text-[11px] font-bold">Today</button>
            <button
              type="button"
              aria-label="Next week"
              onClick={() => setWeekOffset((value) => Math.min(1, value + 1))}
              className="grid size-8 place-items-center rounded-md text-[var(--muted)] hover:bg-black/5"
            >
              <ChevronRight aria-hidden="true" className="size-4" />
            </button>
          </div>
        </div>
      </div>

      {weekOffset !== 0 ? (
        <div className="border-b border-[var(--amber)]/15 bg-[var(--amber-soft)]/45 px-5 py-2 text-center text-xs text-[#84552d]">
          Demo data is pinned to 10–16 August. Return to <button type="button" onClick={() => setWeekOffset(0)} className="font-bold underline">today</button> to see allocations.
        </div>
      ) : null}

      <div className="scrollbar-slim overflow-x-auto">
        <div className="min-w-[980px]">
          <div className="grid grid-cols-[220px_repeat(7,minmax(105px,1fr))] border-b border-black/7 bg-[#faf8f2]">
            <div className="flex items-center gap-2 border-r border-black/7 px-4 py-3 text-[10px] font-bold tracking-[0.12em] text-[var(--muted)] uppercase">
              <Layers3 aria-hidden="true" className="size-3.5" /> Resource
            </div>
            {calendarDays.map((day) => (
              <div key={day.key} className={cn("border-r border-black/7 px-3 py-2.5 text-center last:border-r-0", day.today && "bg-[var(--forest-soft)]/65")}>
                <span className="block text-[10px] font-semibold text-[var(--muted)] uppercase">{day.weekday}</span>
                <span className={cn("mx-auto mt-1 grid size-7 place-items-center rounded-full text-xs font-bold", day.today && "bg-[var(--forest)] text-white")}>{day.day}</span>
              </div>
            ))}
          </div>

          {visibleLanes.length ? visibleLanes.map((lane, laneIndex) => {
            const previousGroup = visibleLanes[laneIndex - 1]?.group;
            const showGroup = previousGroup !== lane.group;
            return (
              <div key={lane.id}>
                {showGroup ? (
                  <div className="border-b border-black/6 bg-[#f5f2eb]/75 px-4 py-1.5 text-[9px] font-bold tracking-[0.15em] text-[var(--muted)] uppercase">{lane.group}</div>
                ) : null}
                <div className="relative grid min-h-[68px] grid-cols-[220px_repeat(7,minmax(105px,1fr))] border-b border-black/6 last:border-0">
                  <div className="z-10 flex items-center gap-3 border-r border-black/7 bg-[var(--surface)] px-4 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-xs font-bold">{lane.label}</p>
                      <p className="mt-1 truncate text-[10px] text-[var(--muted)]">{lane.detail}</p>
                    </div>
                    <span className="text-[9px] font-semibold text-[var(--muted)]">{lane.utilization}%</span>
                  </div>
                  {calendarDays.map((day) => <div key={day.key} className={cn("border-r border-black/6 last:border-r-0", day.today && "bg-[var(--forest-soft)]/22")} />)}
                  {weekOffset === 0 ? lane.events.map((event) => (
                    <button
                      key={event.id}
                      type="button"
                      style={{ gridColumn: `${event.start + 2} / span ${event.span}`, gridRow: 1 }}
                      aria-label={`${event.label}, ${event.sublabel}, ${event.span} day${event.span === 1 ? "" : "s"}`}
                      className={cn(
                        "z-20 m-1.5 min-w-0 overflow-hidden rounded-lg border px-2.5 py-2 text-left shadow-[0_1px_2px_rgb(0_0_0/5%)] transition-transform hover:-translate-y-0.5 hover:shadow-md",
                        eventTone[event.tone],
                      )}
                    >
                      <span className="flex items-center gap-1.5 truncate text-[10px] font-bold">
                        {event.warning ? <AlertTriangle aria-hidden="true" className="size-3 shrink-0" /> : null}
                        {event.label}
                      </span>
                      {event.span > 1 ? <span className="mt-1 block truncate text-[9px] opacity-70">{event.sublabel}</span> : null}
                    </button>
                  )) : null}
                </div>
              </div>
            );
          }) : (
            <div className="px-5 py-12 text-center">
              <p className="text-sm font-semibold">No matching resources</p>
              <p className="mt-1 text-xs text-[var(--muted)]">Try a different resource type or search term.</p>
            </div>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-black/7 bg-[#faf8f2] px-4 py-3 text-[10px] text-[var(--muted)] sm:px-5">
        {[
          ["bg-[#f3d8d2]", "Red Stag"],
          ["bg-[#d9e9e8]", "Patagonian Double"],
          ["bg-[#e4ece3]", "Lodge stay"],
          ["bg-[#f7e1c6]", "Activity"],
          ["bg-[#e7e6e1]", "Unavailable"],
        ].map(([classes, label]) => (
          <span key={label} className="inline-flex items-center gap-1.5"><span className={cn("size-2 rounded-sm border border-black/8", classes)} />{label}</span>
        ))}
        <span className="ml-auto hidden font-medium sm:block">All times shown in property timezone</span>
      </div>
    </section>
  );
}
