import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { MasterCalendar } from "@/components/master-calendar";

describe("MasterCalendar", () => {
  it("shows the unified plan and current allocations", () => {
    render(<MasterCalendar />);

    expect(screen.getByRole("heading", { name: "Unified resource plan" })).toBeInTheDocument();
    expect(screen.getByText("Andes Suite")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Miller · 4, Patagonian Double/ })).toBeInTheDocument();
  });

  it("filters the grid by resource type", async () => {
    const user = userEvent.setup();
    render(<MasterCalendar />);

    await user.selectOptions(screen.getByRole("combobox", { name: "Filter by resource type" }), "Guides");

    expect(screen.getByText("Mateo Ríos")).toBeInTheDocument();
    expect(screen.queryByText("Andes Suite")).not.toBeInTheDocument();
  });
});
