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

async function recordTender(
  page: Page,
  reservationId: string,
  channel: "cash" | "bank_transfer" | "external_terminal",
  amountMinor: number,
  details: Record<string, string> = {},
): Promise<void> {
  await page.goto(`/manage/workspace/demo-lodge/reservations/${reservationId}`);
  await page.getByRole("tab", { name: "Payments" }).click();
  await page.getByRole("button", { name: "Record front-desk payment", exact: true }).click();
  const dialog = page.getByRole("alertdialog", { name: "Record front-desk payment" });
  await dialog.getByRole("combobox", { name: "Channel*" }).selectOption(channel);
  await dialog.getByRole("spinbutton", { name: "Amount (minor units)*" }).fill(String(amountMinor));
  for (const [label, value] of Object.entries(details)) {
    await dialog.getByRole("textbox", { name: label, exact: true }).fill(value);
  }
  await dialog.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page.getByText("Front-desk tender recorded truthfully", { exact: true })).toBeVisible();
}

test.use({ trace: "off" });

test("P3-06B authenticated PostgreSQL tender, evidence, refund, checkout, and cash-close journey", async ({ page, baseURL }) => {
  test.setTimeout(300_000);
  if (!baseURL) throw new Error("P3-06B requires an API base URL.");
  page.setDefaultTimeout(15_000);

  const uat = prepareReservation();
  if (!uat.access_token || !uat.access_token_id) throw new Error("Front-desk UAT handoff omitted its temporary API credential.");
  const suffix = uat.reservation_id.replaceAll("-", "").slice(-12);
  const headers = { Accept: "application/json", Authorization: `Bearer ${uat.access_token}`, "X-Tenant-ID": uat.tenant_id };
  try {
  await page.goto("/manage/workspace/demo-lodge/cash-shifts");
  await page.getByRole("button", { name: "Open cash shift", exact: true }).click();
  const openShift = page.getByRole("dialog", { name: "Open cash shift" });
  await openShift.getByRole("combobox", { name: "Property id*" }).selectOption(uat.property_id);
  await openShift.getByRole("textbox", { name: "Currency*" }).fill(uat.currency);
  await openShift.getByRole("spinbutton", { name: "Opening float minor*" }).fill("10000");
  await openShift.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(page.getByText("Cash shift opened", { exact: true })).toBeVisible();

  await recordTender(page, uat.reservation_id, "cash", 10_000);
  await recordTender(page, uat.reservation_id, "bank_transfer", 20_000, {
    "Transaction / authorization reference": `UAT-WIRE-${suffix}`,
  });

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
  await recordTender(page, uat.reservation_id, "external_terminal", 70_000, {
    "Processor alias*": terminalPayload.processor_alias,
    "Merchant account alias*": terminalPayload.merchant_account_alias,
    "Terminal identifier*": terminalPayload.terminal_identifier,
    "Transaction / authorization reference*": terminalPayload.transaction_reference,
    "Authorization reference": terminalPayload.authorization_reference,
    "Batch reference": terminalPayload.batch_reference,
    "Card brand": terminalPayload.card_brand,
    "Card last four": terminalPayload.card_last_four,
  });

  const paymentsResponse = await page.request.get(`/api/v1/payments?reservation_id=${uat.reservation_id}`, { headers });
  expect(paymentsResponse.status(), await paymentsResponse.text()).toBe(200);
  const payments = ((await paymentsResponse.json()) as { data: Record<string, unknown>[] }).data;
  const cash = payments.find((payment) => payment.channel === "cash");
  const bank = payments.find((payment) => payment.channel === "bank_transfer");
  const terminal = payments.find((payment) => payment.channel === "external_terminal");
  expect(cash).toBeTruthy();
  expect(bank).toMatchObject({ channel: "bank_transfer", amount_minor: 20_000, reference: `uat-wire-${suffix}`.toLowerCase() });
  expect(terminal).toMatchObject({ channel: "external_terminal", amount_minor: 70_000, reference: `uat-terminal-${suffix}`.toLowerCase() });

  const duplicate = (await post(page, uat, `/api/v1/reservations/${uat.reservation_id}/front-desk-payments`, `uat-duplicate-${suffix}`, terminalPayload, 201)).data!;
  expect(duplicate).toMatchObject({ state: "duplicate_review", payment_id: null });
  await post(page, uat, `/api/v1/tender-details/${String(duplicate.id)}/resolve`, `uat-duplicate-review-${suffix}`, {
    decision: "confirmed_duplicate",
    reason: "Matched the already-recorded synthetic standalone receipt.",
  });

  const credited = prepareReservation(uat.reservation_id, 20_000);
  expect(credited.balance_minor).toBe(-20_000);
  const externalRefund = (await post(page, uat, `/api/v1/payments/${String(terminal!.id)}/manual-refunds`, `uat-terminal-refund-request-${suffix}`, {
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

  await page.goto("/manage/workspace/demo-lodge/payment-evidence");
  const evidenceRow = page.getByRole("row").filter({ hasText: uat.confirmation_number }).first();
  await evidenceRow.getByRole("link", { name: "View", exact: true }).click();
  await page.waitForURL(/\/payment-evidence\/[0-9a-f-]+$/);
  await page.getByRole("button", { name: "Approve refund evidence", exact: true }).click();
  const approveReason = page.getByRole("textbox", { name: "Reason*" });
  await expect(approveReason).toBeVisible();
  await approveReason.fill("Matched the synthetic external execution receipt to the exact refund request.");
  await page.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(page.getByText("Refund execution evidence approved", { exact: true })).toBeVisible();

  const evidenceHref = await page.getByRole("link", { name: "Download", exact: true }).getAttribute("href");
  expect(evidenceHref).toBeTruthy();
  const evidenceUrl = new URL(evidenceHref!, baseURL);
  const evidenceDownload = await page.request.get(`${evidenceUrl.pathname}${evidenceUrl.search}`, { headers });
  expect(evidenceDownload.status(), `${evidenceUrl.pathname}: ${await evidenceDownload.text()}`).toBe(200);
  expect(evidenceDownload.headers()["cache-control"]).toContain("no-store");
  expect(evidenceDownload.headers()["content-type"]).toContain("application/pdf");

  await page.reload();
  await page.getByRole("button", { name: "Complete manual refund", exact: true }).click();
  const executionReference = page.getByRole("textbox", { name: "External execution reference*" });
  await expect(executionReference).toBeVisible();
  await executionReference.fill(`UAT-TERMINAL-REFUND-${suffix}`);
  await page.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(page.getByText("Manual refund completion recorded from approved evidence", { exact: true })).toBeVisible();

  await page.goto(`/manage/workspace/demo-lodge/reservations/${uat.reservation_id}`);
  await page.getByRole("button", { name: "Check in", exact: true }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByText("Reservation updated: Check in", { exact: true })).toBeVisible();
  await page.reload();
  await page.getByRole("button", { name: "Check out", exact: true }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByText("Reservation updated: Check out", { exact: true })).toBeVisible();

  await page.goto(`/manage/workspace/demo-lodge/payments/${String(cash!.id)}`);
  await page.getByRole("button", { name: "Request manual refund", exact: true }).evaluate((button: HTMLButtonElement) => button.click());
  const requestCashRefund = page.getByRole("alertdialog", { name: "Request manual refund" });
  const refundAmount = requestCashRefund.getByRole("spinbutton", { name: "Amount (minor units)*" });
  await expect(refundAmount).toBeVisible();
  await refundAmount.fill("10000");
  await requestCashRefund.getByRole("textbox", { name: "Reason*" }).fill("Synthetic post-checkout cash overpayment return.");
  await requestCashRefund.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(page.getByText("Manual refund requested; no refund is completed yet", { exact: true })).toBeVisible();
  await page.reload();
  await page.getByRole("button", { name: "Dispense cash refund", exact: true }).evaluate((button: HTMLButtonElement) => button.click());
  const dispenseCash = page.getByRole("alertdialog", { name: "Dispense cash refund" });
  const openCashRefund = dispenseCash.getByRole("combobox", { name: "Open cash refund*" });
  await expect(openCashRefund).toBeVisible();
  await openCashRefund.selectOption({ index: 1 });
  await dispenseCash.getByRole("combobox", { name: "Open cash shift*" }).selectOption({ index: 1 });
  await dispenseCash.getByRole("textbox", { name: "Drawer slip reference*" }).fill(`UAT-DRAWER-SLIP-${suffix}`);
  await dispenseCash.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(page.getByText("Cash refund dispensed with an exact drawer movement", { exact: true })).toBeVisible();

  await page.goto("/manage/workspace/demo-lodge/cash-shifts");
  let shiftRow = page.getByRole("row").filter({ hasText: /USD/ }).filter({ hasText: /Open/i }).filter({ hasText: /\$100\.00/ }).first();
  await shiftRow.getByRole("button", { name: "Close", exact: true }).click();
  const closeShift = page.getByRole("alertdialog", { name: "Close" });
  await closeShift.getByRole("spinbutton", { name: "Counted cash minor*" }).fill("9900");
  await closeShift.getByRole("textbox", { name: "Reason" }).fill("Synthetic UAT count documents a one-dollar shortage.");
  await closeShift.getByRole("button", { name: "Confirm", exact: true }).click();
  shiftRow = page.getByRole("row").filter({ has: page.getByRole("button", { name: "Approve variance", exact: true }) }).first();
  const approveVarianceButton = shiftRow.getByRole("button", { name: "Approve variance", exact: true });
  await expect(approveVarianceButton).toBeVisible();
  await approveVarianceButton.click();
  const approveVariance = page.getByRole("alertdialog", { name: "Approve variance" });
  await approveVariance.getByRole("textbox", { name: "Reason*" }).fill("Finance approved the documented synthetic shortage.");
  await approveVariance.getByRole("button", { name: "Confirm", exact: true }).click();
  shiftRow = page.getByRole("row").filter({ hasText: /USD/ }).filter({ hasText: /Closed/i }).filter({ hasText: /\$100\.00/ }).first();
  await expect(shiftRow).toContainText(/Closed/i);

  const folio = await page.request.get(`/api/v1/reservations/${uat.reservation_id}/folio`, { headers });
  expect(folio.status()).toBe(200);
  expect(((await folio.json()) as ApiBody).summary?.balance_minor).toBe(0);
  await page.goto(`/manage/workspace/demo-lodge/reservations/${uat.reservation_id}`);
  await page.getByRole("button", { name: "Close folio", exact: true }).click();
  const closeFolio = page.getByRole("alertdialog", { name: "Close folio" });
  await closeFolio.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(page.getByText("Folio closed", { exact: true })).toBeVisible();

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
  shiftRow = page.getByRole("row").filter({ hasText: /USD/ }).filter({ hasText: /Closed/i }).first();
  await expect(shiftRow).toContainText("$100.00");
  await expect(page.locator("main")).not.toContainText(/server error|exception|trace/i);
  } finally {
    artisan("payments:front-desk-compose-uat", `--revoke-token=${uat.access_token_id}`);
  }
});
