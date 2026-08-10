import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { MasterCalendar } from "@/components/master-calendar";
import type { CalendarView } from "@/data/staff-types";
import { calendarDays, calendarLanes, tenant } from "@/lib/demo-data";

const calendar: CalendarView = {
  days: calendarDays.map((day) => ({ ...day, today: day.today ?? false })),
  lanes: calendarLanes,
  timezone: tenant.timezone,
  rangeLabel: "10–16 August 2026",
  summary: { hardConflicts: 0, unassignedReservations: 1, suggestions: 2 },
  isDemo: true,
};

describe("MasterCalendar", () => {
  it("shows the unified plan and current allocations", () => {
    render(<MasterCalendar calendar={calendar} />);

    expect(screen.getByRole("heading", { name: "Unified resource plan" })).toBeInTheDocument();
    expect(screen.getByText("Andes Suite")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Miller · 4, Patagonian Double/ })).toBeInTheDocument();
  });

  it("filters the grid by resource type", async () => {
    const user = userEvent.setup();
    render(<MasterCalendar calendar={calendar} />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Filter by resource type" }), "Guides");

    expect(screen.getByText("Mateo Ríos")).toBeInTheDocument();
    expect(screen.queryByText("Andes Suite")).not.toBeInTheDocument();
  });

  it("renders a safe live empty state without demo allocations", () => {
    render(<MasterCalendar calendar={{ ...calendar, lanes: [], isDemo: false }} />);

    expect(screen.getByText("No matching resources")).toBeInTheDocument();
    expect(screen.queryByText("Andes Suite")).not.toBeInTheDocument();
  });
});
