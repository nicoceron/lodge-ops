import { expect, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test("P3-04 previews and queues an explicitly marked test communication", async ({ page, request }) => {
  await page.goto("/manage/workspace/demo-lodge/communications");
  await expect(page.locator("main")).toBeVisible();

  await page.getByRole("button", { name: /authorized test send/i }).click();
  const dialog = page.getByRole("dialog", { name: /authorized test send/i });
  await dialog.getByLabel(/property/i).selectOption({ index: 1 });
  await dialog.getByLabel(/recipient/i).fill("p3-04-uat@example.test");
  await dialog.getByLabel(/subject/i).fill("Production communications UAT");
  await dialog.getByLabel(/body/i).fill("Deterministic marked test-send from the ordinary worker journey.");
  await dialog.getByRole("button", { name: /submit/i }).click();

  await expect(page.getByText("Marked test message queued")).toBeVisible();
  const row = page.getByRole("row").filter({ hasText: "[TEST] Production communications UAT" }).first();
  await expect(row).toBeVisible();
  await row.getByRole("button", { name: /preview/i }).click();
  await expect(page.getByText("TEST MESSAGE — NOT A GUEST COMMUNICATION", { exact: false })).toBeVisible();

  const mailpit = process.env.INN_MAILPIT_URL ?? "http://127.0.0.1:8025";
  await expect.poll(async () => {
    const response = await request.get(`${mailpit}/api/v1/messages`);
    const payload = await response.json();
    return JSON.stringify(payload).includes("[TEST] Production communications UAT");
  }, { timeout: 90_000 }).toBeTruthy();

  // Mailpit receipt is send-path evidence only; it does not establish provider delivery truth.
  await page.goto("/manage/workspace/demo-lodge/communications");
  await expect(page.getByRole("row").filter({ hasText: "[TEST] Production communications UAT" }).first()).not.toContainText("delivered");
});
