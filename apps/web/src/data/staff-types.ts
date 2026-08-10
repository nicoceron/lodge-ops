export type CalendarDayView = {
  key: string;
  weekday: string;
  day: number;
  today: boolean;
};

export type CalendarLaneView = {
  id: string;
  group: "Rooms" | "Guides" | "Equipment" | "Operations";
  label: string;
  detail: string;
  utilization: number;
  events: Array<{
    id: string;
    label: string;
    sublabel: string;
    start: number;
    span: number;
    tone: "stag" | "double" | "stay" | "activity" | "block";
    warning?: string;
  }>;
};

export type CalendarView = {
  days: CalendarDayView[];
  lanes: CalendarLaneView[];
  timezone: string;
  rangeLabel: string;
  summary: { hardConflicts: number; unassignedReservations: number; suggestions: number };
  isDemo: boolean;
};

export type DashboardView = {
  dateLabel: string;
  description: string;
  stats: Array<{ label: string; value: string; detail: string; tone: "forest" | "amber" | "red" | "blue" }>;
  arrivals: Array<{
    id: string;
    time: string;
    party: string;
    guests: number;
    program: string;
    stay: string;
    readiness: "ready" | "attention" | "blocked";
    transfer: string;
  }>;
  readiness: { percent: number; totalGuests: number; items: Array<{ label: string; complete: number; total: number }> };
  tasks: Array<{ id: string; title: string; meta: string; owner: string; done: boolean }>;
  calendar: CalendarView;
};

export type OperationsView = {
  date: string;
  readiness: { complete: number; total: number; open: number };
  tasks: Array<{ id: string; title: string; meta: string; owner: string; done: boolean }>;
  restrictions: Array<{ label: string; count: number; note: string; serious: boolean }>;
  kitchenGuests: number;
  guideAssignments: Array<{
    id: string;
    guide: string;
    program: string;
    time: string;
    detail: string;
    status: "Confirmed" | "Action needed";
  }>;
  housekeeping: { arrivals: number; turnovers: number; stayovers: number; focus: string | null };
};

export type FinanceView = {
  periodLabel: string;
  currency: string;
  metrics: Array<{ label: string; value: string; note: string; tone: "forest" | "blue" | "red" | "amber" }>;
  series: Array<{ label: string; value: number }>;
  deposits: { dueCount: number; dueMinor: number; paidCount: number; paidMinor: number; overdueCount: number };
  folio: { chargesMinor: number; paymentsMinor: number; refundsMinor: number; adjustmentsMinor: number };
  channels: Array<{ channel: string; bookings: number; revenueMinor: number; collectionPercent: number }>;
  recentFolios: Array<{
    id: string;
    confirmationNumber: string;
    status: string;
    totalMinor: number;
    paidMinor: number;
    balanceMinor: number;
  }>;
};

export type StaffLoadState<T> = {
  data: T | null;
  mode: "demo" | "live";
  error: string | null;
  notice?: string | null;
};
