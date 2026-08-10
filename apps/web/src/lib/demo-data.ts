export type ReservationStatus =
  | "confirmed"
  | "tentative"
  | "in_house"
  | "completed";

export type Readiness = "ready" | "attention" | "blocked";

export const tenant = {
  name: "Estancia Viento Sur",
  shortName: "Viento Sur",
  location: "Santa Cruz, Patagonia",
  timezone: "America/Argentina/Rio_Gallegos",
  initials: "VS",
};

export const dashboardStats = [
  { label: "Occupied tonight", value: "18 / 24", detail: "75% · +8% vs last week", tone: "forest" },
  { label: "Arriving today", value: "7", detail: "3 parties · first at 14:20", tone: "amber" },
  { label: "Needs attention", value: "4", detail: "2 assignments · 2 balances", tone: "red" },
  { label: "August revenue", value: "$184k", detail: "68% collected", tone: "blue" },
] as const;

export const arrivals = [
  {
    time: "14:20",
    party: "Miller party",
    guests: 4,
    program: "Patagonian Double",
    stay: "5 nights",
    readiness: "ready" as Readiness,
    transfer: "Flight AR 1892 · driver confirmed",
  },
  {
    time: "16:45",
    party: "Sofía Alvarez",
    guests: 2,
    program: "Field & Table",
    stay: "3 nights",
    readiness: "attention" as Readiness,
    transfer: "Dietary form missing",
  },
  {
    time: "18:10",
    party: "Northwater Outfitters",
    guests: 1,
    program: "Red Stag Hunting",
    stay: "8 nights",
    readiness: "blocked" as Readiness,
    transfer: "Balance overdue · guide pending",
  },
];

export const readiness = [
  { label: "Guest details", complete: 21, total: 24 },
  { label: "Room assignments", complete: 24, total: 24 },
  { label: "Guide assignments", complete: 17, total: 20 },
  { label: "Payments", complete: 19, total: 24 },
  { label: "Kitchen brief", complete: 23, total: 24 },
];

export const operationalTasks = [
  { title: "Confirm Spanish fishing guide", meta: "Miller party · due 11:00", owner: "OC", priority: "high", done: false },
  { title: "Review gluten-free camp menu", meta: "Alvarez · due 12:30", owner: "MR", priority: "medium", done: false },
  { title: "Attach bank transfer receipt", meta: "Northwater · overdue", owner: "LA", priority: "high", done: false },
  { title: "Prepare River Cabin turnover", meta: "Housekeeping · due 14:00", owner: "EV", priority: "normal", done: true },
] as const;

export const calendarDays = [
  { key: "2026-08-10", weekday: "Mon", day: 10, today: true },
  { key: "2026-08-11", weekday: "Tue", day: 11 },
  { key: "2026-08-12", weekday: "Wed", day: 12 },
  { key: "2026-08-13", weekday: "Thu", day: 13 },
  { key: "2026-08-14", weekday: "Fri", day: 14 },
  { key: "2026-08-15", weekday: "Sat", day: 15 },
  { key: "2026-08-16", weekday: "Sun", day: 16 },
];

export type CalendarLane = {
  id: string;
  group: "Rooms" | "Guides" | "Equipment";
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

export const calendarLanes: CalendarLane[] = [
  {
    id: "room-andes",
    group: "Rooms",
    label: "Andes Suite",
    detail: "2 guests · King",
    utilization: 86,
    events: [
      { id: "ev-1", label: "Miller · 4", sublabel: "Patagonian Double", start: 0, span: 4, tone: "double" },
      { id: "ev-2", label: "Chen · 2", sublabel: "Lodge stay", start: 5, span: 2, tone: "stay" },
    ],
  },
  {
    id: "room-river",
    group: "Rooms",
    label: "River Cabin",
    detail: "3 guests · Twin",
    utilization: 71,
    events: [
      { id: "ev-3", label: "Alvarez · 2", sublabel: "Field & Table", start: 1, span: 3, tone: "activity" },
      { id: "ev-4", label: "Turnover", sublabel: "Housekeeping block", start: 4, span: 1, tone: "block" },
    ],
  },
  {
    id: "room-condor",
    group: "Rooms",
    label: "Condor 1",
    detail: "2 guests · Twin",
    utilization: 100,
    events: [
      { id: "ev-5", label: "Northwater", sublabel: "Red Stag Hunting", start: 0, span: 7, tone: "stag", warning: "Guide pending" },
    ],
  },
  {
    id: "guide-mateo",
    group: "Guides",
    label: "Mateo Ríos",
    detail: "Fishing · ES/EN",
    utilization: 64,
    events: [
      { id: "ev-6", label: "Miller party", sublabel: "2:1 · Río Gallegos", start: 1, span: 3, tone: "double" },
    ],
  },
  {
    id: "guide-ana",
    group: "Guides",
    label: "Ana Torres",
    detail: "Trekking · ES/EN/FR",
    utilization: 43,
    events: [
      { id: "ev-7", label: "Alvarez", sublabel: "Trekking · 1:2", start: 2, span: 2, tone: "activity" },
      { id: "ev-8", label: "Leave", sublabel: "Unavailable", start: 5, span: 2, tone: "block" },
    ],
  },
  {
    id: "horse-north",
    group: "Equipment",
    label: "North horse pool",
    detail: "8 horses · 2 spare",
    utilization: 75,
    events: [
      { id: "ev-9", label: "Miller · 6", sublabel: "Ridge ride", start: 3, span: 1, tone: "double" },
      { id: "ev-10", label: "Alvarez · 4", sublabel: "Half-day ride", start: 5, span: 1, tone: "activity" },
    ],
  },
  {
    id: "boat-aurora",
    group: "Equipment",
    label: "Boat Aurora",
    detail: "6 seats · Rafael",
    utilization: 29,
    events: [
      { id: "ev-11", label: "Miller · 4", sublabel: "Lake fishing", start: 2, span: 2, tone: "double" },
    ],
  },
];

export const reservations = [
  { code: "VS-2641", guest: "Ethan Miller", party: 4, arrival: "Aug 10", departure: "Aug 15", program: "Patagonian Double", status: "in_house" as ReservationStatus, payment: "Paid", total: 3280000, readiness: "ready" as Readiness, channel: "Direct" },
  { code: "VS-2642", guest: "Sofía Alvarez", party: 2, arrival: "Aug 10", departure: "Aug 13", program: "Field & Table", status: "confirmed" as ReservationStatus, payment: "Deposit paid", total: 1840000, readiness: "attention" as Readiness, channel: "Virtuoso" },
  { code: "VS-2643", guest: "Noah Campbell", party: 1, arrival: "Aug 10", departure: "Aug 18", program: "Red Stag Hunting", status: "confirmed" as ReservationStatus, payment: "Overdue", total: 9200000, readiness: "blocked" as Readiness, channel: "Northwater" },
  { code: "VS-2644", guest: "Mei Chen", party: 2, arrival: "Aug 15", departure: "Aug 17", program: "Lodge stay", status: "tentative" as ReservationStatus, payment: "Unpaid", total: 960000, readiness: "attention" as Readiness, channel: "Direct" },
  { code: "VS-2645", guest: "Tomás Ibarra", party: 3, arrival: "Aug 19", departure: "Aug 24", program: "Rivers & Ridges", status: "confirmed" as ReservationStatus, payment: "Deposit paid", total: 4120000, readiness: "ready" as Readiness, channel: "Agency" },
];

export const guests = [
  { name: "Ethan Miller", email: "ethan@example.com", country: "United States", visits: 3, lastStay: "In house", value: 7280000, preferences: ["Fly fishing", "Quiet room"], dietary: "No restrictions" },
  { name: "Sofía Alvarez", email: "sofia@example.com", country: "Argentina", visits: 1, lastStay: "Arrives today", value: 1840000, preferences: ["Horse riding", "Red wine"], dietary: "Gluten-free" },
  { name: "Noah Campbell", email: "noah@example.com", country: "Canada", visits: 2, lastStay: "Arrives today", value: 14600000, preferences: ["Stag hunting", "Early breakfast"], dietary: "Nut allergy" },
  { name: "Mei Chen", email: "mei@example.com", country: "Singapore", visits: 1, lastStay: "Aug 15", value: 960000, preferences: ["Photography", "Spa"], dietary: "Pescatarian" },
] as const;

export const revenueSeries = [92, 108, 124, 118, 146, 162, 184];

export const channelPerformance = [
  { channel: "Direct", bookings: 18, revenue: 8640000, margin: 71 },
  { channel: "Partner agencies", bookings: 11, revenue: 7120000, margin: 58 },
  { channel: "Virtuoso", bookings: 6, revenue: 4380000, margin: 61 },
  { channel: "Outfitters", bookings: 4, revenue: 3920000, margin: 52 },
] as const;
