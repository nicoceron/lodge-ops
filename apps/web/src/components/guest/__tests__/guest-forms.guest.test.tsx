import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { DocumentsPanel } from "@/components/guest/documents-panel";
import { GuestPortalProvider } from "@/components/guest/guest-state";
import { PaymentPanel } from "@/components/guest/payment-panel";
import { PreArrivalForm } from "@/components/guest/pre-arrival-form";
import { SurveyForm } from "@/components/guest/survey-form";
import { initialGuestPortalState } from "@/data/guest-demo";

let testPortal = 0;

function renderInPortal(component: React.ReactNode) {
  testPortal += 1;
  return render(<GuestPortalProvider token={`unit-test-token-${testPortal}`}>{component}</GuestPortalProvider>);
}

describe("guest self-service forms", () => {
  beforeEach(() => window.sessionStorage.clear());

  it("validates and saves complete pre-arrival information", async () => {
    const user = userEvent.setup();
    renderInPortal(<PreArrivalForm />);

    await user.click(screen.getByRole("button", { name: "Save pre-arrival details" }));
    expect(screen.getByText("Add an emergency contact name.")).toBeInTheDocument();
    expect(screen.getByText("Please review the highlighted details.")).toBeInTheDocument();

    await user.type(screen.getByLabelText("Emergency contact"), "Jamie Morgan");
    await user.type(screen.getByLabelText("Emergency contact phone"), "+1 415 555 0120");
    await user.type(screen.getByLabelText("Departure reference"), "LA 897");
    await user.type(screen.getByLabelText("Expected departure"), "2026-08-17T13:20");
    await user.click(screen.getByLabelText(/I consent to sharing these essential details/));
    await user.click(screen.getByRole("button", { name: "Save pre-arrival details" }));

    expect(screen.getByRole("status")).toHaveTextContent("Pre-arrival details saved");
  });

  it("requires informed consent before signing a waiver", async () => {
    const user = userEvent.setup();
    renderInPortal(<DocumentsPanel />);

    await user.click(screen.getByRole("button", { name: "Sign waiver" }));
    expect(screen.getByText(/Read and accept the waiver/)).toBeInTheDocument();

    await user.click(screen.getByLabelText(/I have read, understood and agree/));
    await user.type(screen.getByLabelText(/Electronic signature/), "Alex Morgan");
    await user.click(screen.getByRole("button", { name: "Sign waiver" }));

    expect(screen.getByRole("status")).toHaveTextContent("Waiver signed securely");
    expect(screen.getByRole("button", { name: "Waiver signed" })).toBeDisabled();
  });

  it("submits a private post-stay survey with both required ratings", async () => {
    const user = userEvent.setup();
    renderInPortal(<SurveyForm />);

    await user.click(screen.getByRole("button", { name: "Send private feedback" }));
    expect(screen.getByRole("alert")).toHaveTextContent("Choose a rating for both");

    const fives = screen.getAllByLabelText("5 out of 5");
    await user.click(fives[0]);
    await user.click(fives[1]);
    await user.type(screen.getByLabelText("What should we keep, change or remember?"), "The guide made every day feel considered.");
    await user.click(screen.getByRole("button", { name: "Send private feedback" }));

    expect(screen.getByRole("heading", { name: "Thank you, Alex." })).toBeInTheDocument();
  });

  it("persists live pre-arrival data through the same-origin server boundary", async () => {
    const user = userEvent.setup();
    const request = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {} }), { status: 200 }));
    vi.stubGlobal("fetch", request);
    const initialState = {
      ...initialGuestPortalState,
      profile: { ...initialGuestPortalState.profile, emergencyName: "Jamie Morgan", emergencyPhone: "+14155550120" },
      travel: { ...initialGuestPortalState.travel, departureReference: "LA 897", departureTime: "2026-08-17T13:20" },
      preferences: { ...initialGuestPortalState.preferences, medicalConsent: true },
    };

    render(
      <GuestPortalProvider token="live-boundary-test" mode="live" initialState={initialState}>
        <PreArrivalForm />
      </GuestPortalProvider>,
    );
    await user.click(screen.getByRole("button", { name: "Save pre-arrival details" }));

    await waitFor(() => expect(request).toHaveBeenCalledWith(
      "/guest/api/pre-arrival",
      expect.objectContaining({ method: "PUT" }),
    ));
    expect(screen.getByRole("status")).toHaveTextContent("Pre-arrival details saved");
    vi.unstubAllGlobals();
  });

  it("forwards the actual evidence file to the same-origin upload boundary", async () => {
    const user = userEvent.setup();
    const request = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { status: "review_pending" } }), { status: 201 }));
    vi.stubGlobal("fetch", request);
    render(
      <GuestPortalProvider token="live-upload-test" mode="live">
        <PaymentPanel />
      </GuestPortalProvider>,
    );

    const evidence = new File(["real binary body"], "transfer.pdf", { type: "application/pdf" });
    await user.upload(screen.getByLabelText("Choose transfer evidence"), evidence);

    await waitFor(() => expect(request).toHaveBeenCalled());
    const [url, options] = request.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/guest/api/payment-evidence");
    expect(options.method).toBe("POST");
    expect(options.body).toBeInstanceOf(FormData);
    expect((options.body as FormData).get("evidence")).toBe(evidence);
    expect(screen.getByRole("status")).toHaveTextContent("attached for secure review");
    vi.unstubAllGlobals();
  });
});
