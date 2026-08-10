import type {
  CalendarProjectionDto,
  DashboardDto,
  FinanceDto,
  OperationsDto,
} from "@/data/api-client";
import type {
  CalendarLaneView,
  CalendarView,
  DashboardView,
  FinanceView,
  OperationsView,
} from "@/data/staff-types";
import { formatMoney, initials } from "@/lib/utils";

function dateKey(value: string) {
  return value.slice(0, 10);
}

function addDays(value: string, days: number) {
  const date = new Date(`${dateKey(value)}T00:00:00.000Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function dayOffset(start: string, value: string) {
  const from = new Date(`${dateKey(start)}T00:00:00.000Z`).getTime();
  const to = new Date(`${dateKey(value)}T00:00:00.000Z`).getTime();
  return Math.floor((to - from) / 86_400_000);
}

function calendarGroup(type: CalendarProjectionDto["resources"][number]["type"]): CalendarLaneView["group"] {
  if (type === "room") return "Rooms";
  if (type === "guide" || type === "staff") return "Guides";
  return "Equipment";
}

function eventTone(type: string, status?: string | null): CalendarLaneView["events"][number]["tone"] {
  if (type === "resource_block") return "block";
  if (type === "activity" || type === "task") return "activity";
  if (status === "checked_in") return "double";
  if (status === "cancelled" || status === "no_show") return "block";
  return "stay";
}

export function mapCalendarProjection(dto: CalendarProjectionDto, isDemo = false): CalendarView {
  const rangeStart = dateKey(dto.range.start);
  const dayCount = Math.min(7, Math.max(1, dayOffset(dto.range.start, dto.range.end)));
  const today = new Intl.DateTimeFormat("en-CA", {
    timeZone: dto.range.timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
  const days = Array.from({ length: dayCount }, (_, index) => {
    const key = addDays(rangeStart, index);
    const date = new Date(`${key}T00:00:00.000Z`);
    return {
      key,
      weekday: new Intl.DateTimeFormat("en-US", { weekday: "short", timeZone: "UTC" }).format(date),
      day: date.getUTCDate(),
      today: key === today,
    };
  });
  const toLaneEvent = (event: CalendarProjectionDto["data"][number]) => {
    const start = Math.max(0, dayOffset(rangeStart, event.start));
    const rawEnd = Math.max(start + 1, dayOffset(rangeStart, event.end) + (event.end.includes("T00:00:00") ? 0 : 1));
    return {
      id: event.id,
      label: event.title,
      sublabel: event.type === "activity" ? "Activity" : event.type === "task" ? "Operational task" : event.status ?? "Scheduled",
      start,
      span: Math.max(1, Math.min(dayCount - start, rawEnd - start)),
      tone: eventTone(event.type, event.status),
      warning: event.status === "blocked" || event.status === "cancelled" ? "Attention needed" : undefined,
      href: event.type === "reservation"
        ? `/reservations/${event.id}`
        : event.type === "task"
          ? `/operations?task=${event.id}`
          : `/calendar/events/${event.type}/${event.id}`,
    };
  };
  const lanes: CalendarLaneView[] = dto.resources.map((resource) => ({
    id: resource.id,
    group: calendarGroup(resource.type),
    label: resource.name,
    detail: `${resource.code} · capacity ${resource.capacity}`,
    utilization: resource.utilization_percent,
    events: dto.data
      .filter((event) => event.resource_ids?.includes(resource.id))
      .map(toLaneEvent),
  }));
  const unassigned = dto.data.filter((event) => !event.resource_ids?.length);
  if (unassigned.length) {
    lanes.push({
      id: "operations-unassigned",
      group: "Operations",
      label: "Unassigned & timed work",
      detail: `${unassigned.length} item${unassigned.length === 1 ? "" : "s"} awaiting a resource`,
      utilization: 0,
      events: unassigned.map(toLaneEvent),
    });
  }
  const first = days.at(0);
  const last = days.at(-1);
  const rangeLabel = first && last
    ? `${first.day}–${last.day} ${new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${last.key}T00:00:00.000Z`))}`
    : "Selected range";

  return {
    days,
    lanes,
    timezone: dto.range.timezone,
    rangeLabel,
    summary: {
      hardConflicts: dto.summary.hard_conflicts,
      unassignedReservations: dto.summary.unassigned_reservations,
      suggestions: dto.summary.suggestions,
    },
    isDemo,
  };
}

export function mapDashboardProjection(dto: DashboardDto, calendar: CalendarView): DashboardView {
  const arrivalGuests = dto.arrival_parties.reduce((sum, arrival) => sum + arrival.party_size, 0);
  return {
    dateLabel: new Intl.DateTimeFormat("en-US", { weekday: "long", day: "numeric", month: "long", timeZone: "UTC" }).format(new Date(`${dto.date}T00:00:00.000Z`)),
    description: `${dto.arrivals} arrival ${dto.arrivals === 1 ? "party is" : "parties are"} expected and ${dto.needs_attention} item${dto.needs_attention === 1 ? " needs" : "s need"} attention.`,
    stats: [
      { label: "Occupied tonight", value: `${dto.occupied_rooms} / ${dto.active_rooms}`, detail: `${dto.occupancy_percent}% room occupancy`, tone: "forest" },
      { label: "Arriving today", value: String(arrivalGuests), detail: `${dto.arrivals} ${dto.arrivals === 1 ? "party" : "parties"}`, tone: "amber" },
      { label: "Needs attention", value: String(dto.needs_attention), detail: `${dto.open_tasks} open tasks`, tone: "red" },
      { label: "In house", value: String(dto.in_house), detail: `${dto.departures} departing today`, tone: "blue" },
    ],
    arrivals: dto.arrival_parties.map((arrival) => ({
      id: arrival.id,
      time: new Intl.DateTimeFormat("en-US", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: dto.timezone }).format(new Date(arrival.starts_at)),
      party: arrival.guest_name || `Reservation ${arrival.confirmation_number}`,
      guests: arrival.party_size,
      program: arrival.confirmation_number,
      stay: `${arrival.nights} ${arrival.nights === 1 ? "night" : "nights"}`,
      readiness: arrival.readiness,
      transfer: arrival.room_names.length ? arrival.room_names.join(", ") : "Room assignment pending",
    })),
    readiness: { percent: dto.readiness.percent, totalGuests: arrivalGuests, items: dto.readiness.items },
    tasks: dto.tasks.map((task) => ({
      id: task.id,
      title: task.title,
      meta: task.due_at ? `Due ${new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit", timeZone: dto.timezone }).format(new Date(task.due_at))}` : "No due time",
      owner: task.assignee ? initials(task.assignee.name) : "—",
      done: task.status === "done" || task.status === "cancelled",
    })),
    calendar,
  };
}

export function mapOperationsProjection(dto: OperationsDto): OperationsView {
  return {
    date: dto.date,
    readiness: dto.readiness,
    tasks: dto.tasks.map((task) => ({
      id: task.id,
      title: task.title,
      meta: task.due_at ? `Due ${new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit", timeZone: dto.timezone }).format(new Date(task.due_at))}` : "No due time",
      owner: task.owner_initials,
      done: task.status === "done" || task.status === "cancelled",
    })),
    restrictions: dto.kitchen.restrictions.map((item) => ({
      ...item,
      note: item.serious ? "Separate preparation required" : "Current service window",
    })),
    kitchenGuests: dto.kitchen.guest_count,
    guideAssignments: dto.guide_assignments.map((assignment) => ({
      id: assignment.id,
      guide: assignment.guide || "Unassigned",
      program: assignment.program,
      time: new Intl.DateTimeFormat("en-US", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: dto.timezone }).format(new Date(assignment.starts_at)),
      detail: `${assignment.party_size} guest${assignment.party_size === 1 ? "" : "s"}`,
      status: assignment.status === "confirmed" ? "Confirmed" : "Action needed",
    })),
    housekeeping: dto.housekeeping,
  };
}

export function mapFinanceProjection(dto: FinanceDto): FinanceView {
  return {
    periodLabel: dto.period.label,
    currency: dto.currency,
    metrics: [
      { label: "Booked revenue", value: formatMoney(dto.summary.booked_revenue_minor, dto.currency), note: dto.period.label, tone: "forest" },
      { label: "Cash collected", value: formatMoney(dto.summary.cash_collected_minor, dto.currency), note: `${dto.summary.collection_percent}% of booked`, tone: "blue" },
      { label: "Receivables", value: formatMoney(dto.summary.receivables_minor, dto.currency), note: `${dto.deposits.overdue_count} overdue deposits`, tone: "red" },
      { label: "Deposit position", value: formatMoney(dto.deposits.due_minor, dto.currency), note: `${dto.deposits.due_count} still due`, tone: "amber" },
    ],
    series: dto.revenue_series.map((item) => ({ label: item.label, value: item.value_minor })),
    deposits: {
      dueCount: dto.deposits.due_count,
      dueMinor: dto.deposits.due_minor,
      paidCount: dto.deposits.paid_count,
      paidMinor: dto.deposits.paid_minor,
      overdueCount: dto.deposits.overdue_count,
    },
    folio: {
      chargesMinor: dto.folio.charges_minor,
      paymentsMinor: dto.folio.payments_minor,
      refundsMinor: dto.folio.refunds_minor,
      adjustmentsMinor: dto.folio.adjustments_minor,
    },
    channels: dto.channels.map((channel) => ({
      channel: channel.channel,
      bookings: channel.bookings,
      revenueMinor: channel.revenue_minor,
      collectionPercent: channel.collection_percent,
    })),
    recentFolios: dto.recent_folios.map((folio) => ({
      id: folio.reservation_id,
      confirmationNumber: folio.confirmation_number,
      status: folio.status,
      totalMinor: folio.total_minor,
      paidMinor: folio.paid_minor,
      balanceMinor: folio.balance_minor,
    })),
  };
}
