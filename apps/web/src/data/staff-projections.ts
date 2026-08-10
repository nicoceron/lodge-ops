import "server-only";

import {
  demoModeEnabled,
  getCalendar,
  getDashboard,
  getFinance,
  getOperations,
  LodgeApiError,
} from "@/data/api-client";
import {
  mapCalendarProjection,
  mapDashboardProjection,
  mapFinanceProjection,
  mapOperationsProjection,
} from "@/data/staff-mappers";
import type {
  CalendarView,
  DashboardView,
  FinanceView,
  OperationsView,
  StaffLoadState,
} from "@/data/staff-types";
import {
  arrivals,
  calendarDays,
  calendarLanes,
  channelPerformance,
  dashboardStats,
  operationalTasks,
  readiness,
  revenueSeries,
  tenant,
} from "@/lib/demo-data";

function addDays(date: string, days: number) {
  const value = new Date(`${date}T00:00:00.000Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function safeError(error: unknown, projection: string) {
  if (error instanceof LodgeApiError) {
    if (error.status === 401) return "Your staff session has expired. Sign in again to load live lodge data.";
    if (error.status === 403) return `Your current role cannot view the live ${projection}.`;
  }
  return `The live ${projection} is temporarily unavailable. No demo or cached guest data has been substituted.`;
}

function demoCalendar(): CalendarView {
  return {
    days: calendarDays.map((day) => ({ ...day, today: day.today ?? false })),
    lanes: calendarLanes.map((lane) => ({ ...lane, events: lane.events.map((event) => ({ ...event })) })),
    timezone: tenant.timezone,
    rangeLabel: "10–16 August 2026",
    summary: { hardConflicts: 0, unassignedReservations: 1, suggestions: 2 },
    isDemo: true,
  };
}

function demoDashboard(): DashboardView {
  return {
    dateLabel: "Monday · 10 August",
    description: "The lodge is calm today. Three arrivals are expected and four details need your attention before service begins.",
    stats: dashboardStats.map((stat) => ({ ...stat })),
    arrivals: arrivals.map((arrival, index) => ({ id: `demo-arrival-${index}`, ...arrival })),
    readiness: {
      percent: 87,
      totalGuests: 24,
      items: readiness.map((item) => ({ ...item })),
    },
    tasks: operationalTasks.map((task, index) => ({ id: `demo-task-${index}`, ...task })),
    calendar: demoCalendar(),
  };
}

export async function loadDashboardProjection(): Promise<StaffLoadState<DashboardView>> {
  if (demoModeEnabled) return { data: demoDashboard(), mode: "demo", error: null };

  try {
    const dashboard = (await getDashboard()).data;
    let calendar: CalendarView = {
      days: [],
      lanes: [],
      timezone: dashboard.timezone,
      rangeLabel: "Current week",
      summary: { hardConflicts: 0, unassignedReservations: 0, suggestions: 0 },
      isDemo: false,
    };
    let notice: string | null = null;
    try {
      calendar = mapCalendarProjection(await getCalendar(dashboard.date, addDays(dashboard.date, 7)));
    } catch {
      notice = "The overview is live, but the calendar projection could not be loaded.";
    }

    return { data: mapDashboardProjection(dashboard, calendar), mode: "live", error: null, notice };
  } catch (error) {
    return { data: null, mode: "live", error: safeError(error, "operations overview") };
  }
}

export async function loadCalendarProjection(startDate = today()): Promise<StaffLoadState<CalendarView>> {
  if (demoModeEnabled) return { data: demoCalendar(), mode: "demo", error: null };

  try {
    const data = mapCalendarProjection(await getCalendar(startDate, addDays(startDate, 7)));
    return { data, mode: "live", error: null };
  } catch (error) {
    return { data: null, mode: "live", error: safeError(error, "master calendar") };
  }
}

function demoOperations(): OperationsView {
  return {
    date: "2026-08-10",
    role: "operations",
    visibleSections: ["tasks", "arrivals", "kitchen", "guide_assignments", "housekeeping"],
    readiness: { complete: 14, total: 18, open: 4 },
    tasks: operationalTasks.map((task, index) => ({ id: `demo-task-${index}`, ...task })),
    restrictions: [
      { label: "Gluten-free", count: 3, note: "1 camp lunch", serious: false },
      { label: "Nut allergy", count: 1, note: "Severe · separate prep", serious: true },
      { label: "Pescatarian", count: 2, note: "Arriving Aug 15", serious: false },
      { label: "No dairy", count: 1, note: "Breakfast only", serious: false },
    ],
    kitchenGuests: 20,
    kitchenIdentityRestricted: true,
    guideAssignments: [
      { id: "demo-guide-1", guide: "Mateo Ríos", program: "Miller · Río Gallegos", time: "07:00", detail: "4 guests · 2:1", status: "Confirmed" },
      { id: "demo-guide-2", guide: "Ana Torres", program: "Alvarez · Ridge trek", time: "08:30", detail: "2 guests · ES", status: "Confirmed" },
      { id: "demo-guide-3", guide: "Unassigned", program: "Northwater · Red Stag", time: "05:45", detail: "1 guest · EN", status: "Action needed" },
    ],
    housekeeping: { available: true, arrivals: 3, turnovers: 2, stayovers: 6, focus: "River Cabin turnover due by 14:00" },
  };
}

export async function loadOperationsProjection(): Promise<StaffLoadState<OperationsView>> {
  if (demoModeEnabled) return { data: demoOperations(), mode: "demo", error: null };

  try {
    return { data: mapOperationsProjection((await getOperations()).data), mode: "live", error: null };
  } catch (error) {
    return { data: null, mode: "live", error: safeError(error, "operations board") };
  }
}

function demoFinance(): FinanceView {
  return {
    periodLabel: "August 2026",
    currency: "USD",
    metrics: [
      { label: "Booked revenue", value: "$184k", note: "+14% vs Jul", tone: "forest" },
      { label: "Cash collected", value: "$125k", note: "68% of booked", tone: "blue" },
      { label: "Receivables", value: "$59k", note: "$9.2k overdue", tone: "red" },
      { label: "Loaded costs", value: "$61k", note: "Actual costs", tone: "amber" },
      { label: "Commissions", value: "$18k", note: "Accrued by channel", tone: "red" },
      { label: "Operating margin", value: "$105k", note: "57% of booked", tone: "forest" },
    ],
    series: revenueSeries.map((value, index) => ({ label: ["Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug"][index], value: value * 100_000 })),
    deposits: { dueCount: 5, dueMinor: 2_100_000, paidCount: 26, paidMinor: 12_500_000, overdueCount: 2 },
    folio: { chargesMinor: 18_400_000, paymentsMinor: 12_500_000, refundsMinor: 240_000, adjustmentsMinor: -80_000 },
    channels: channelPerformance.map((item) => ({ channel: item.channel, bookings: item.bookings, revenueMinor: item.revenue, commissionsMinor: Math.round(item.revenue * 0.1), netRevenueMinor: Math.round(item.revenue * 0.9), collectionPercent: item.margin })),
    programs: [
      { id: "red-stag", name: "Red Stag Hunting", bookings: 5, revenueMinor: 8_600_000, costsMinor: 3_100_000, commissionsMinor: 860_000, marginMinor: 4_640_000 },
      { id: "patagonian-double", name: "The Patagonian Double", bookings: 4, revenueMinor: 6_800_000, costsMinor: 2_400_000, commissionsMinor: 680_000, marginMinor: 3_720_000 },
    ],
    reconciliation: { balanced: true, differenceMinor: 0, policy: "native_currency_only" },
    recentFolios: [
      { id: "demo-folio-1", confirmationNumber: "VS-2641", status: "checked_in", totalMinor: 3_280_000, paidMinor: 3_280_000, balanceMinor: 0 },
      { id: "demo-folio-2", confirmationNumber: "VS-2642", status: "confirmed", totalMinor: 1_840_000, paidMinor: 920_000, balanceMinor: 920_000 },
    ],
  };
}

export async function loadFinanceProjection(): Promise<StaffLoadState<FinanceView>> {
  if (demoModeEnabled) return { data: demoFinance(), mode: "demo", error: null };

  try {
    return { data: mapFinanceProjection((await getFinance()).data), mode: "live", error: null };
  } catch (error) {
    return { data: null, mode: "live", error: safeError(error, "financial projection") };
  }
}
