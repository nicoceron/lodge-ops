import { createHmac } from "node:crypto";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { expect, Locator, Page, test } from "@playwright/test";

type ProviderUat = {
  attempt_id: string;
  reservation_id: string;
  confirmation_number: string;
  payment_token: string;
  external_reference: string;
  provider_payment_id: string;
  webhook_key: string;
};

type RefundUat = {
  provider_refund_model_id: string;
  provider_refund_id: string;
  confirmation_number: string;
};

const enabled = process.env.INN_PROVIDER_COMPOSE_UAT === "1";
const composeProject = process.env.INN_COMPOSE_PROJECT ?? "inn";
const webhookSecret = "local-fixture-webhook-secret";

function prepareProviderJourney(): ProviderUat {
  const output = execFileSync(
    "docker",
    ["compose", "-p", composeProject, "exec", "-T", "api", "php", "artisan", "payments:provider-compose-uat"],
    { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
  );
  const handle = output.split("\n").find((line) => line.startsWith("UAT_HANDLE="))?.slice(11);
  if (!handle || !/^[0-9a-f-]{36}$/.test(handle)) throw new Error("Provider UAT command did not return a valid opaque handoff.");
  const container = execFileSync(
    "docker",
    ["compose", "-p", composeProject, "ps", "-q", "api"],
    { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
  ).trim();
  const directory = mkdtempSync(path.join(tmpdir(), "inn-provider-uat-"));
  const localPath = path.join(directory, "handoff.json");
  const remotePath = `/tmp/inn-provider-uat-${handle}.json`;

  try {
    execFileSync("docker", ["cp", `${container}:${remotePath}`, localPath]);
    return JSON.parse(readFileSync(localPath, "utf8")) as ProviderUat;
  } finally {
    execFileSync("docker", ["exec", container, "rm", "-f", remotePath]);
    rmSync(directory, { recursive: true, force: true });
  }
}

function prepareRefund(attemptId: string): RefundUat {
  const output = execFileSync(
    "docker",
    ["compose", "-p", composeProject, "exec", "-T", "api", "php", "artisan", "payments:provider-compose-uat-refund", attemptId],
    { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
  );
  const encoded = output.split("\n").find((line) => line.startsWith("REFUND_UAT="))?.slice(11);
  if (!encoded) throw new Error(`Provider refund UAT command did not return its redacted descriptor: ${output}`);

  return JSON.parse(encoded) as RefundUat;
}

async function waitForDocument(page: Page, confirmation: string, kind: RegExp): Promise<Locator> {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    await page.reload();
    const row = page.getByRole("row").filter({ hasText: confirmation }).filter({ hasText: kind }).first();
    if (await row.getByRole("link", { name: "Download", exact: true }).count()) return row;
    await page.waitForTimeout(500);
  }

  throw new Error(`Generated ${kind} receipt did not become downloadable for ${confirmation}.`);
}

async function downloadAndAssertPdf(page: Page, row: Locator, expectedText: string): Promise<void> {
  const downloadPromise = page.waitForEvent("download");
  await row.getByRole("link", { name: "Download", exact: true }).click();
  const download = await downloadPromise;
  const pdfPath = await download.path();
  expect(pdfPath).toBeTruthy();
  expect(readFileSync(pdfPath!).subarray(0, 5).toString()).toBe("%PDF-");
  expect(execFileSync("pdftotext", [pdfPath!, "-"], { encoding: "utf8" })).toContain(expectedText);
}

test("P3-06A mobile checkout, informational return, ordinary webhook worker, and settlement review", async ({ browser, page, request, baseURL }) => {
  test.skip(!enabled, "Run explicitly against the isolated deterministic provider Compose stack.");
  test.setTimeout(120_000);
  if (!baseURL) throw new Error("P3-06A requires an API base URL.");

  const journey = prepareProviderJourney();
  const mobileContext = await browser.newContext({
    baseURL,
    storageState: { cookies: [], origins: [] },
    viewport: { width: 390, height: 844 },
  });
  const mobile = await mobileContext.newPage();

  try {
    await mobile.goto(`/pay/${journey.payment_token}`);
    await expect(mobile.getByRole("heading", { name: /Reservation/ })).toBeVisible();
    await expect(mobile.getByText("Final confirmation is based on the payment provider, not the browser return.")).toBeVisible();
    expect(await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await mobile.getByRole("button", { name: "Pay securely with Mercado Pago" }).click();
    await expect(mobile).toHaveURL(/^https:\/\/sandbox\.mercadopago\.com(?::\d+)?\/checkout\/v1\/redirect/);

    await mobile.goto(`/pay/return/${journey.external_reference}`);
    await expect(mobile.getByText("Checkout ready", { exact: true })).toBeVisible();
    await expect(mobile.getByText("the browser return itself never records money", { exact: false })).toBeVisible();

    const timestamp = String(Date.now());
    const requestId = `playwright-${Date.now()}`;
    const manifest = `id:${journey.provider_payment_id};request-id:${requestId};ts:${timestamp};`;
    const signature = createHmac("sha256", webhookSecret).update(manifest).digest("hex");
    const response = await request.post(
      `/api/v1/payment-webhooks/${journey.webhook_key}?data.id=${encodeURIComponent(journey.provider_payment_id)}`,
      {
        headers: {
          "x-request-id": requestId,
          "x-signature": `ts=${timestamp},v1=${signature}`,
        },
        data: { type: "payment", action: "payment.updated", data: { id: journey.provider_payment_id } },
      },
    );
    expect(response.status()).toBe(200);

    await expect.poll(async () => {
      await mobile.reload();
      return mobile.locator(".state").innerText();
    }, { timeout: 20_000 }).toBe("Approved");

    await page.goto("/manage/workspace/demo-lodge/payment-attempts");
    const attemptRow = page.getByRole("row").filter({ hasText: journey.provider_payment_id });
    await expect(attemptRow).toHaveCount(1);
    await expect(attemptRow).toContainText("Approved");

    await page.goto("/manage/workspace/demo-lodge/settlement-entries");
    const settlementRow = page.getByRole("row").filter({ hasText: journey.provider_payment_id });
    await expect(settlementRow).toHaveCount(1);
    await expect(settlementRow).toContainText("Variance");
    await settlementRow.getByRole("button", { name: /investigate/i }).click();
    const dialog = page.getByRole("dialog", { name: /investigate/i });
    await dialog.getByRole("textbox", { name: /notes/i }).fill("P3-06A deterministic Compose variance review.");
    await dialog.getByRole("button", { name: /submit/i }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText("Variance investigation recorded")).toBeVisible();

    await page.goto("/manage/workspace/demo-lodge/generated-documents");
    const paymentReceipt = await waitForDocument(page, journey.confirmation_number, /payment.?receipt/i);
    await expect(page.getByRole("row").filter({ hasText: journey.confirmation_number }).filter({ hasText: /payment.?receipt/i })).toHaveCount(1);
    await downloadAndAssertPdf(page, paymentReceipt, "Payment receipt");

    const refund = prepareRefund(journey.attempt_id);
    await page.goto("/manage/workspace/demo-lodge/provider-refunds");
    const refundRow = page.getByRole("row").filter({ hasText: journey.provider_payment_id });
    await expect(refundRow).toHaveCount(1);
    await expect(refundRow).toContainText("Processing");
    await refundRow.getByRole("button", { name: /recover/i }).click();
    const recoveryDialog = page.getByRole("alertdialog", { name: /recover/i });
    await recoveryDialog.getByRole("textbox", { name: /provider refund id/i }).fill(refund.provider_refund_id);
    await recoveryDialog.getByRole("button", { name: "Confirm", exact: true }).click();
    await expect(recoveryDialog).toHaveCount(0);
    await expect(page.getByText("Provider refund recovered authoritatively")).toBeVisible();
    await expect(refundRow).toContainText("Succeeded");

    await page.goto(`/manage/workspace/demo-lodge/reservations/${journey.reservation_id}`);
    await page.getByRole("tab", { name: "Change ledger" }).click();
    const completedRefundRow = page.getByRole("row").filter({ hasText: "Refund Completed" });
    await expect(completedRefundRow).toHaveCount(1);
    await completedRefundRow.getByRole("button", { name: "Generate refund receipt", exact: true }).click();
    await expect(page.getByText("Refund receipt queued")).toBeVisible();

    await page.goto("/manage/workspace/demo-lodge/generated-documents");
    const refundReceipt = await waitForDocument(page, refund.confirmation_number, /refund.?receipt/i);
    await downloadAndAssertPdf(page, refundReceipt, "Refund receipt");
  } finally {
    await mobileContext.close();
  }
});
