import { describe, expect, it } from "vitest";
import type { CalendarProjectionDto, DashboardDto, FinanceDto, OperationsDto } from "@/data/api-client";
import {
  mapCalendarProjection,
  mapDashboardProjection,
  mapFinanceProjection,
  mapOperationsProjection,
} from "@/data/staff-mappers";

const calendarDto: CalendarProjectionDto = {
  data: [
    {
      id: "reservation-1",
      type: "reservation",
      title: "RSV-LIVE-1",
      start: "2026-08-10T15:00:00Z",
      end: "2026-08-12T11:00:00Z",
      status: "confirmed",
      property_id: "property-1",
      resource_ids: ["room-1"],
    },
    {
      id: "task-1",
      type: "task",
      title: "Prepare room",
      start: "2026-08-10T12:00:00Z",
      end: "2026-08-10T12:00:00Z",
      status: "todo",
      property_id: "property-1",
      resource_ids: [],
    },
  ],
  range: { start: "2026-08-10T00:00:00Z", end: "2026-08-17T00:00:00Z", timezone: "UTC" },
  resources: [{ id: "room-1", property_id: "property-1", name: "Live Room", code: "101", type: "room", capacity: 2, utilization_percent: 29 }],
  allocations: [{ id: "allocation-1", reservation_id: "reservation-1", service_occurrence_id: null, resource_id: "room-1", status: "confirmed", start: "2026-08-10T15:00:00Z", end: "2026-08-12T11:00:00Z", quantity: 1 }],
  summary: { hard_conflicts: 0, unassigned_reservations: 0, suggestions: 0 },
};

describe("staff projection mappers", () => {
  it("maps only live calendar resources and keeps unassigned work explicit", () => {
    const calendar = mapCalendarProjection(calendarDto);

    expect(calendar.isDemo).toBe(false);
    expect(calendar.lanes.map((lane) => lane.label)).toEqual(["Live Room", "Unassigned & timed work"]);
    expect(calendar.lanes[0].events[0].label).toBe("RSV-LIVE-1");
    expect(calendar.lanes[1].group).toBe("Operations");
  });

  it("uses the reservation reference when the API correctly redacts guest identity", () => {
    const dashboardDto: DashboardDto = {
      date: "2026-08-10",
      timezone: "UTC",
      arrivals: 1,
      departures: 0,
      in_house: 0,
      active_resources: 1,
      active_rooms: 1,
      occupied_rooms: 0,
      open_tasks: 0,
      occupancy_percent: 0,
      needs_attention: 1,
      arrival_parties: [{ id: "reservation-1", confirmation_number: "RSV-LIVE-1", starts_at: "2026-08-10T15:00:00Z", ends_at: "2026-08-12T11:00:00Z", party_size: 2, nights: 2, readiness: "attention", room_names: [] }],
      readiness: { complete: 2, total: 5, percent: 40, items: [{ key: "guest_details", label: "Guest details", complete: 1, total: 1 }] },
      tasks: [],
    };

    const dashboard = mapDashboardProjection(dashboardDto, mapCalendarProjection(calendarDto));

    expect(dashboard.arrivals[0].party).toBe("Reservation RSV-LIVE-1");
    expect(dashboard.arrivals[0]).not.toHaveProperty("guest_name");
    expect(dashboard.stats[2].value).toBe("1");
  });

  it("maps role-minimal operations and finance DTOs without inventing identities", () => {
    const operationsDto: OperationsDto = {
      date: "2026-08-10",
      timezone: "UTC",
      readiness: { complete: 0, total: 1, open: 1 },
      tasks: [{ id: "task-1", title: "Prepare service", status: "todo", priority: "high", due_at: null, owner_initials: "—" }],
      arrivals: [{ id: "reservation-1", confirmation_number: "RSV-LIVE-1", starts_at: "2026-08-10T15:00:00Z", ends_at: "2026-08-12T11:00:00Z", party_size: 2, status: "confirmed", dietary: ["Gluten-free"] }],
      kitchen: { guest_count: 2, restrictions: [{ label: "Gluten-free", count: 1, serious: false }], identity_restricted: true },
      guide_assignments: [],
      housekeeping: { arrivals: 1, turnovers: 0, stayovers: 0, focus: null },
    };
    const financeDto: FinanceDto = {
      currency: "USD",
      timezone: "UTC",
      period: { start: "2026-08-01T00:00:00Z", end: "2026-09-01T00:00:00Z", label: "August 2026" },
      summary: { booked_revenue_minor: 10000, cash_collected_minor: 4000, receivables_minor: 6000, overdue_deposits_minor: 1000, collection_percent: 40 },
      deposits: { due_count: 1, due_minor: 1000, paid_count: 0, paid_minor: 0, overdue_count: 1 },
      folio: { charges_minor: 10000, payments_minor: 4000, refunds_minor: 0, adjustments_minor: 0 },
      revenue_series: [{ label: "Aug", value_minor: 10000 }],
      channels: [{ channel: "Direct", bookings: 1, revenue_minor: 10000, collected_minor: 4000, collection_percent: 40 }],
      recent_folios: [{ reservation_id: "reservation-1", confirmation_number: "RSV-LIVE-1", status: "confirmed", total_minor: 10000, paid_minor: 4000, balance_minor: 6000 }],
    };

    expect(mapOperationsProjection(operationsDto).restrictions[0].label).toBe("Gluten-free");
    expect(mapFinanceProjection(financeDto).recentFolios[0]).toEqual(expect.objectContaining({ confirmationNumber: "RSV-LIVE-1", balanceMinor: 6000 }));
    expect(JSON.stringify(mapFinanceProjection(financeDto))).not.toContain("guest");
  });
});
