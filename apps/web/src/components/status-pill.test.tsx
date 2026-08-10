import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { StatusPill } from "@/components/status-pill";

describe("StatusPill", () => {
  it("exposes status in text instead of relying on color", () => {
    render(<StatusPill tone="blocked" />);
    expect(screen.getByText("Blocked")).toBeVisible();
  });
});
