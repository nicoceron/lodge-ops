import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { expect, Page, test } from "@playwright/test";

type UatReservation = {
  tenant_id: string;
  property_id: string;
  reservation_id: string;
  confirmation_number: string;
  currency: string;
  total_minor: number;
  credit_minor: number;
  balance_minor: number;
  access_token?: string;
  access_token_id?: number;
};

type ApiBody = { data?: Record<string, unknown>; summary?: Record<string, unknown> };

const enabled = process.env.INN_FRONT_DESK_COMPOSE_UAT === "1";
const composeProject = process.env.INN_COMPOSE_PROJECT ?? "inn";
const apiContainer = process.env.INN_API_CONTAINER;

function artisan(...args: string[]): string {
  if (apiContainer) {
    return execFileSync("docker", ["exec", apiContainer, "php", "artisan", ...args], { encoding: "utf8" });
  }

  return execFileSync(
    "docker",
    ["compose", "-p", composeProject, "exec", "-T", "api", "php", "artisan", ...args],
    { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
  );
}

function prepareReservation(reservationId?: string, creditMinor = 0): UatReservation {
  const args = ["payments:front-desk-compose-uat"];
  if (reservationId) args.push(reservationId, `--credit=${creditMinor}`);
  const output = artisan(...args);
  if (!reservationId) {
    const handle = output.split("\n").find((line) => line.startsWith("FRONT_DESK_UAT_HANDLE="))?.slice(22);
    if (!handle || !/^[0-9a-f-]{36}$/.test(handle)) throw new Error("Front-desk UAT command did not return a valid opaque handoff.");
    const container = apiContainer ?? execFileSync(
      "docker",
      ["compose", "-p", composeProject, "ps", "-q", "api"],
      { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
    ).trim();
    const directory = mkdtempSync(path.join(tmpdir(), "inn-front-desk-uat-"));
    const localPath = path.join(directory, "handoff.json");
    const remotePath = `/tmp/inn-front-desk-uat-${handle}.json`;

    try {
      execFileSync("docker", ["cp", `${container}:${remotePath}`, localPath]);
      return JSON.parse(readFileSync(localPath, "utf8")) as UatReservation;
    } finally {
      execFileSync("docker", ["exec", container, "rm", "-f", remotePath]);
      rmSync(directory, { recursive: true, force: true });
    }
  }
  const encoded = output.split("\n").find((line) => line.startsWith("FRONT_DESK_UAT="))?.slice(15);
  if (!encoded) throw new Error(`Front-desk UAT command did not return its redacted descriptor: ${output}`);

  return JSON.parse(encoded) as UatReservation;
}

async function post(
  page: Page,
  uat: UatReservation,
  route: string,
  key: string,
  data: Record<string, unknown>,
  expectedStatus = 200,
): Promise<ApiBody> {
  const response = await page.request.post(route, {
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${uat.access_token}`,
      "X-Tenant-ID": uat.tenant_id,
      "Idempotency-Key": key,
    },
    data,
  });
  expect(response.status(), `${route}: ${await response.text()}`).toBe(expectedStatus);

  return (await response.json()) as ApiBody;
}

test.use({ trace: "off" });

test("P3-06B authenticated PostgreSQL tender, evidence, refund, checkout, and cash-close journey", async ({ page, baseURL }) => {
  test.skip(!enabled, "Run explicitly against the isolated P3-06B Compose/PostgreSQL stack.");
  test.setTimeout(120_000);
  if (!baseURL) throw new Error("P3-06B requires an API base URL.");

  const uat = prepareReservation();
  if (!uat.access_token || !uat.access_token_id) throw new Error("Front-desk UAT handoff omitted its temporary API credential.");
  const suffix = uat.reservation_id.replaceAll("-", "").slice(-12);
  const headers = { Accept: "application/json", Authorization: `Bearer ${uat.access_token}`, "X-Tenant-ID": uat.tenant_id };
  try {
  const shift = (await post(page, uat, "/api/v1/cash-shifts", `uat-open-${suffix}`, {
    property_id: uat.property_id,
    currency: uat.currency,
    opening_float_minor: 10_000,
  }, 201)).data!;
  const shiftId = String(shift.id);

  const cash = (await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/front-desk-payments`, `uat-cash-${suffix}`, {
    channel: "cash",
    amount_minor: 10_000,
  }, 201)).data!;
  expect(cash.state).toBe("posted");

  const bank = (await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/front-desk-payments`, `uat-bank-${suffix}`, {
    channel: "bank_transfer",
    amount_minor: 20_000,
    transaction_reference: `UAT-WIRE-${suffix}`,
  }, 201)).data!;
  expect(bank).toMatchObject({ state: "posted", channel: "bank_transfer", amount_minor: 20_000 });

  const terminalPayload = {
    channel: "external_terminal",
    amount_minor: 70_000,
    processor_alias: "Synthetic standalone processor",
    merchant_account_alias: "Demo Lodge front desk",
    terminal_identifier: "UAT terminal 01",
    transaction_reference: `UAT-TERMINAL-${suffix}`,
    authorization_reference: `UAT-AUTH-${suffix}`,
    batch_reference: `UAT-BATCH-${suffix}`,
    card_brand: "Test brand",
    card_last_four: "0042",
  };
  const terminal = (await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/front-desk-payments`, `uat-terminal-${suffix}`, terminalPayload, 201)).data!;
  expect(terminal).toMatchObject({ state: "posted", channel: "external_terminal", amount_minor: 70_000, card_last_four: "0042" });

  const duplicate = (await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/front-desk-payments`, `uat-duplicate-${suffix}`, terminalPayload, 201)).data!;
  expect(duplicate).toMatchObject({ state: "duplicate_review", payment_id: null, duplicate_of_id: terminal.id });
  await post(page, uat, `/api/v1/tender-details/${String(duplicate.id)}/resolve`, `uat-duplicate-review-${suffix}`, {
    decision: "confirmed_duplicate",
    reason: "Matched the already-recorded synthetic standalone receipt.",
  });

  const credited = prepareReservation(uat.reservation_id, 20_000);
  expect(credited.balance_minor).toBe(-20_000);
  const externalRefund = (await post(page, uat, `/api/v1/payments/${String(terminal.payment_id)}/manual-refunds`, `uat-terminal-refund-request-${suffix}`, {
    amount_minor: 10_000,
    reason: "Synthetic standalone-terminal overpayment return.",
  }, 201)).data!;
  const evidenceResponse = await page.request.post(`/api/v1/manual-refunds/${String(externalRefund.id)}/evidence`, {
    headers: { ...headers, "Idempotency-Key": `uat-refund-evidence-${suffix}` },
    multipart: {
      evidence: {
        name: "synthetic-terminal-refund-evidence.pdf",
        mimeType: "application/pdf",
        buffer: readFileSync(path.resolve(process.cwd(), "e2e-client/fixtures/transfer-evidence-v1.pdf")),
      },
    },
  });
  expect(evidenceResponse.status(), await evidenceResponse.text()).toBe(201);
  const evidence = ((await evidenceResponse.json()) as ApiBody).data!;
  expect(evidence.scan_state).toBe("accepted");
  await post(page, uat, `/api/v1/manual-refund-evidence/${String(evidence.id)}/review`, `uat-refund-evidence-review-${suffix}`, {
    decision: "approved",
    reason: "Matched the synthetic external execution receipt to the exact refund request.",
  });

  await page.goto("/manage/workspace/demo-lodge/payment-evidence");
  const evidenceRow = page.getByRole("row").filter({ hasText: uat.confirmation_number }).first();
  await expect(evidenceRow).toContainText(/approved/i);
  const evidenceHref = await evidenceRow.getByRole("link", { name: "Download", exact: true }).getAttribute("href");
  expect(evidenceHref).toBeTruthy();
  const evidenceUrl = new URL(evidenceHref!, baseURL);
  const evidenceDownload = await page.request.get(`${evidenceUrl.pathname}${evidenceUrl.search}`, { headers });
  expect(evidenceDownload.status(), `${evidenceUrl.pathname}: ${await evidenceDownload.text()}`).toBe(200);
  expect(evidenceDownload.headers()["cache-control"]).toContain("no-store");
  expect(evidenceDownload.headers()["content-type"]).toContain("application/pdf");

  await post(page, uat, `/api/v1/manual-refunds/${String(externalRefund.id)}/complete`, `uat-terminal-refund-complete-${suffix}`, {
    execution_reference: `UAT-TERMINAL-REFUND-${suffix}`,
    evidence_id: evidence.id,
  });
  await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/transition`, `uat-check-in-${suffix}`, { status: "checked_in" });
  await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/transition`, `uat-check-out-${suffix}`, { status: "checked_out" });

  const cashRefund = (await post(page, uat, `/api/v1/payments/${String(cash.payment_id)}/manual-refunds`, `uat-cash-refund-request-${suffix}`, {
    amount_minor: 10_000,
    reason: "Synthetic post-checkout cash overpayment return.",
  }, 201)).data!;
  await post(page, uat, `/api/v1/manual-refunds/${String(cashRefund.id)}/complete`, `uat-cash-refund-complete-${suffix}`, {
    execution_reference: `UAT-DRAWER-SLIP-${suffix}`,
    cash_shift_id: shiftId,
  });

  const openShiftResponse = await page.request.get(`/api/v1/cash-shifts/${shiftId}`, { headers });
  expect(openShiftResponse.status()).toBe(200);
  const openShift = ((await openShiftResponse.json()) as ApiBody).data!;
  expect(openShift.current_expected_minor).toBe(10_000);
  expect(openShift.movements).toEqual(expect.arrayContaining([
    expect.objectContaining({ type: "payment", amount_minor: 10_000 }),
    expect.objectContaining({ type: "refund", amount_minor: -10_000 }),
  ]));

  const closed = (await post(page, uat, `/api/v1/cash-shifts/${shiftId}/close`, `uat-close-${suffix}`, {
    counted_cash_minor: 9_900,
    reason: "Synthetic UAT count documents a one-dollar shortage.",
  })).data!;
  expect(closed).toMatchObject({ state: "variance_review", expected_cash_minor: 10_000, counted_cash_minor: 9_900, variance_minor: -100 });
  const approved = (await post(page, uat, `/api/v1/cash-shifts/${shiftId}/approve-variance`, `uat-approve-${suffix}`, {
    reason: "Finance approved the documented synthetic shortage.",
  })).data!;
  expect(approved.state).toBe("closed");

  const folio = await page.request.get(`/api/v1/reservations/${uat.reservation_id}/folio`, { headers });
  expect(folio.status()).toBe(200);
  expect(((await folio.json()) as ApiBody).summary?.balance_minor).toBe(0);
  const closedFolio = await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/folio/close`, `uat-folio-close-${suffix}`, {});
  expect(closedFolio.data).toMatchObject({ status: "closed", balance_minor: 0 });

  await page.goto(`/manage/workspace/demo-lodge/reservations/${uat.reservation_id}`);
  await expect(page.getByText("Checked out", { exact: true })).toBeVisible();
  await page.getByRole("tab", { name: "Payments" }).click();
  await expect(page.getByRole("row").filter({ hasText: /Bank Transfer/i })).toHaveCount(1);
  await expect(page.getByRole("row").filter({ hasText: /External Terminal/i })).toHaveCount(1);

  await page.goto("/manage/workspace/demo-lodge/generated-documents");
  const receiptRow = page.getByRole("row").filter({ hasText: uat.confirmation_number }).filter({ hasText: /payment.?receipt/i }).first();
  await expect(receiptRow).toBeVisible();
  const receiptDownload = page.waitForEvent("download");
  await receiptRow.getByRole("link", { name: "Download", exact: true }).click();
  const receipt = await receiptDownload;
  const receiptPath = await receipt.path();
  expect(receiptPath).toBeTruthy();
  expect(execFileSync("pdftotext", [receiptPath!, "-"], { encoding: "utf8" })).toContain("Recorded external terminal payment; Inn did not charge the card");

  await page.goto("/manage/workspace/demo-lodge/cash-shifts");
  const shiftRow = page.getByRole("row").filter({ hasText: /USD/ }).filter({ hasText: /Closed/i }).first();
  await expect(shiftRow).toContainText("$100.00");
  await expect(page.locator("main")).not.toContainText(/server error|exception|trace/i);
  } finally {
    artisan("payments:front-desk-compose-uat", `--revoke-token=${uat.access_token_id}`);
  }
});
