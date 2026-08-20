import { execFileSync } from "node:child_process";
import path from "node:path";
import { Browser, BrowserContext, expect, Page, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const RESERVATION_REFERENCE = "RSV-OP-REVIEW-UAT";
const CHECKLIST_NAME = "Operational review UAT";
const EXCEPTION_TITLE = "Prepare browser-reviewed welcome kit";
const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

function runArtisan(...arguments_: string[]): void {
  const apiContainer = process.env.INN_API_CONTAINER;

  if (apiContainer) {
    execFileSync("docker", ["exec", apiContainer, "php", "artisan", ...arguments_], { stdio: "inherit" });
    return;
  }

  execFileSync(
    "docker",
    ["compose", "-p", process.env.INN_COMPOSE_PROJECT ?? "inn", "exec", "-T", "api", "php", "artisan", ...arguments_],
    { cwd: path.resolve(process.cwd(), "../.."), stdio: "inherit" },
  );
}

async function rolePage(browser: Browser, baseURL: string, email: string, viewport = { width: 1440, height: 1000 }): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL, viewport, storageState: EMPTY_STORAGE_STATE });
  context.setDefaultTimeout(15_000);
  const page = await context.newPage();
  await signIn(page, email, "password");
  return { context, page };
}

test("operational review workbench assignment and checklist exception regenerate through Filament", async ({ browser, baseURL }) => {
  test.setTimeout(120_000);
  if (!baseURL) throw new Error("Operational acceptance browser proof requires an API base URL.");

  runArtisan("db:seed", "--class=Database\\Seeders\\OperationalAcceptanceBrowserSeeder", "--force");
  const manager = await rolePage(browser, baseURL, "manager@example.com");
  const page = manager.page;
  await page.goto("/manage/workspace/demo-lodge/master-calendar");
  const attentionRow = page.locator('[wire\\:key^="attention-"]').filter({ hasText: RESERVATION_REFERENCE });
  await expect(attentionRow).toContainText("Unassigned");
  await attentionRow.getByRole("button", { name: /^Assign / }).first().click();
  await expect(page.getByText("Shared resource assigned", { exact: true })).toBeVisible();
  await expect(attentionRow).toHaveCount(0);

  await page.goto("/manage/workspace/demo-lodge/reservations");
  await page.getByRole("searchbox", { name: "Search", exact: true }).fill(RESERVATION_REFERENCE);
  const reservationRow = page.getByRole("row").filter({ hasText: RESERVATION_REFERENCE });
  await reservationRow.getByRole("link", { name: "View", exact: true }).click();
  await page.getByRole("button", { name: "Checklist exceptions", exact: true }).click();
  const exceptionDialog = page.getByRole("dialog", { name: "Checklist exceptions" });
  await exceptionDialog.getByRole("button", { name: /Add to Reservation-specific items/i }).click();
  await exceptionDialog.getByRole("combobox", { name: "Operation" }).selectOption("add");
  await exceptionDialog.getByRole("textbox", { name: "Title" }).fill(EXCEPTION_TITLE);
  await exceptionDialog.getByRole("combobox", { name: "Priority" }).selectOption("high");
  await exceptionDialog.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(page.getByText("Checklist exceptions saved", { exact: true })).toBeVisible();

  await page.getByRole("button", { name: "Generate checklist", exact: true }).click();
  const generateDialog = page.getByRole("dialog", { name: "Generate checklist" });
  await generateDialog.getByRole("combobox", { name: "Published checklist version" }).click();
  await page.getByRole("option", { name: new RegExp(CHECKLIST_NAME) }).click();
  await generateDialog.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(page.getByText(/Generated 2 tasks/)).toBeVisible();
  await page.goto("/manage/workspace/demo-lodge/operational-tasks");
  await page.getByRole("searchbox", { name: "Search", exact: true }).fill(EXCEPTION_TITLE);
  const activeExceptionTask = page.getByRole("row").filter({ hasText: EXCEPTION_TITLE })
    .filter({ has: page.getByRole("button", { name: "Start", exact: true }) });
  await expect(activeExceptionTask).toHaveCount(1);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/manage/workspace/demo-lodge/master-calendar");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await manager.context.close();

  const guide = await rolePage(browser, baseURL, "guide@example.com");
  const denied = await guide.page.goto("/manage/workspace/demo-lodge/finance-dashboard");
  expect([403, 404]).toContain(denied?.status());
  await guide.page.goto("/manage/workspace/demo-lodge/master-calendar");
  await expect(guide.page.getByText("Shared-resource attention workbench", { exact: true })).toHaveCount(0);
  await guide.context.close();
});
