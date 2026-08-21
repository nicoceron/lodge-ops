import { createHmac } from "node:crypto";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { expect, Page, test } from "@playwright/test";

type InPersonUat = {
  reservation_id: string;
  confirmation_number: string;
  attempt_id: string;
  channel: "integrated_terminal" | "qr";
  provider_order_id: string;
  provider_transaction_id: string;
  webhook_key: string;
};

const enabled = process.env.INN_IN_PERSON_COMPOSE_UAT === "1";
const composeProject = process.env.INN_COMPOSE_PROJECT ?? "inn";
const webhookSecret = "local-fixture-webhook-secret";

function prepare(channel: "point" | "qr"): InPersonUat {
  const root = path.resolve(process.cwd(), "../..");
  const output = execFileSync(
    "docker",
    ["compose", "-p", composeProject, "exec", "-T", "api", "php", "artisan", "payments:in-person-compose-uat", `--channel=${channel}`],
    { cwd: root, encoding: "utf8" },
  );
  const handle = output.split("\n").find((line) => line.startsWith("IN_PERSON_UAT_HANDLE="))?.slice(21);
  if (!handle || !/^[0-9a-f-]{36}$/.test(handle)) throw new Error("In-person UAT command did not return a valid opaque handoff.");
  const container = execFileSync("docker", ["compose", "-p", composeProject, "ps", "-q", "api"], { cwd: root, encoding: "utf8" }).trim();
  const directory = mkdtempSync(path.join(tmpdir(), "inn-in-person-uat-"));
  const localPath = path.join(directory, "handoff.json");
  const remotePath = `/tmp/inn-in-person-uat-${handle}.json`;
  try {
    execFileSync("docker", ["cp", `${container}:${remotePath}`, localPath]);
    return JSON.parse(readFileSync(localPath, "utf8")) as InPersonUat;
  } finally {
    execFileSync("docker", ["exec", container, "rm", "-f", remotePath]);
    rmSync(directory, { recursive: true, force: true });
  }
}

async function deliverProcessedOrder(page: Page, uat: InPersonUat): Promise<void> {
  const timestamp = String(Date.now());
  const requestId = `orders-playwright-${Date.now()}`;
  const manifest = `id:${uat.provider_order_id.toLowerCase()};request-id:${requestId};ts:${timestamp};`;
  const signature = createHmac("sha256", webhookSecret).update(manifest).digest("hex");
  const response = await page.request.post(
    `/api/v1/payment-webhooks/${uat.webhook_key}?type=order&data.id=${encodeURIComponent(uat.provider_order_id)}`,
    {
      headers: { "x-request-id": requestId, "x-signature": `ts=${timestamp},v1=${signature}` },
      data: { type: "order", action: "order.processed", data: { id: uat.provider_order_id } },
    },
  );
  expect(response.status(), await response.text()).toBe(200);
}

async function waitForApproved(page: Page, orderId: string): Promise<void> {
  await expect.poll(async () => {
    await page.reload();
    return page.getByRole("row").filter({ hasText: orderId }).first().innerText();
  }, { timeout: 20_000 }).toContain("Approved");
}

test("P3-06C Point virtual order traverses signed HTTP event, ordinary worker, receipt and settlement", async ({ page }) => {
  test.skip(!enabled, "Run explicitly against the isolated deterministic Orders Compose stack.");
  test.setTimeout(120_000);
  const uat = prepare("point");

  await page.goto("/manage/workspace/demo-lodge/payment-attempts");
  const queued = page.getByRole("row").filter({ hasText: uat.provider_order_id }).first();
  await expect(queued).toContainText("Integrated Terminal");
  await expect(queued).toContainText("Virtual Point SBX0000001");
  await expect(queued).toContainText("Queued");

  await deliverProcessedOrder(page, uat);
  await waitForApproved(page, uat.provider_order_id);

  await page.goto("/manage/workspace/demo-lodge/settlement-entries");
  await expect(page.getByRole("row").filter({ hasText: uat.provider_transaction_id })).toHaveCount(1);
  await page.goto("/manage/workspace/demo-lodge/generated-documents");
  await expect.poll(async () => {
    await page.reload();
    return page.getByRole("row").filter({ hasText: uat.confirmation_number }).filter({ hasText: /payment.?receipt/i }).count();
  }, { timeout: 20_000 }).toBe(1);
});

test("P3-06C active QR is usable on phone, tablet and desktop then disappears after approval", async ({ browser, page, baseURL }) => {
  test.skip(!enabled, "Run explicitly against the isolated deterministic Orders Compose stack.");
  test.setTimeout(120_000);
  if (!baseURL) throw new Error("P3-06C requires an API base URL.");
  const uat = prepare("qr");

  for (const viewport of [{ width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1440, height: 900 }]) {
    const context = await browser.newContext({ baseURL, storageState: process.env.INN_PLAYWRIGHT_AUTH_STATE ?? "/tmp/inn-playwright-client-auth.json", viewport });
    const responsive = await context.newPage();
    try {
      await responsive.goto("/manage/workspace/demo-lodge/payment-attempts");
      const row = responsive.getByRole("row").filter({ hasText: uat.provider_order_id }).first();
      await row.getByRole("button", { name: "Display QR", exact: true }).click();
      const qr = responsive.getByTestId("active-order-qr");
      await expect(qr).toBeVisible();
      await expect(qr.getByRole("img", { name: "Mercado Pago payment QR" })).toHaveAttribute("src", /^data:image\/svg\+xml;base64,/);
      expect(await responsive.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally {
      await context.close();
    }
  }

  await deliverProcessedOrder(page, uat);
  await page.goto("/manage/workspace/demo-lodge/payment-attempts");
  await waitForApproved(page, uat.provider_order_id);
  const approved = page.getByRole("row").filter({ hasText: uat.provider_order_id }).first();
  await expect(approved.getByRole("button", { name: "Display QR", exact: true })).toHaveCount(0);
});
