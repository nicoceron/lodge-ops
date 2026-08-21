import { execFileSync } from "node:child_process";
import path from "node:path";
import { Browser, BrowserContext, expect, Page, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const RESERVATION_REFERENCE = "RSV-OP-REVIEW-UAT";
const CHECKLIST_NAME = "Operational review UAT";
const EXCEPTION_TITLE = "Prepare browser-reviewed welcome kit";
const SWAP_RESERVATION_REFERENCE = "RSV-OP-SWAP-UAT";
const OWN_GUIDE_NAME = "Own Guide Availability";
const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

test.use({ trace: "off" });

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

test("operational review workbench assignment and checklist exception regenerate through Filament", async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(120_000);
  if (!baseURL) throw new Error("Operational acceptance browser proof requires an API base URL.");

  runArtisan("db:seed", "--class=Database\\Seeders\\OperationalAcceptanceBrowserSeeder", "--force");
  const manager = await rolePage(browser, baseURL, "manager@example.com");
  await manager.context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  const page = manager.page;
  await page.goto("/manage/workspace/demo-lodge/master-calendar");
  const attentionRow = page.locator('[wire\\:key^="attention-"]').filter({ hasText: RESERVATION_REFERENCE });
  await expect(attentionRow).toContainText("Unassigned");
  const assignButton = attentionRow.getByRole("button", { name: /^Assign / }).first();
  const assignLabel = await assignButton.textContent();
  await assignButton.click();
  await expect(page.getByText("Shared resource assigned", { exact: true }).last()).toBeVisible();
  await expect(attentionRow).toContainText("Healthy");
  await expect(attentionRow.getByRole("button", { name: assignLabel ?? "" })).toHaveCount(0);
  const moveButton = attentionRow.getByRole("button", { name: /^Move to / }).first();
  const moveLabel = await moveButton.textContent();
  await moveButton.click();
  await expect(attentionRow.getByRole("button", { name: moveLabel ?? "" })).toHaveCount(0);
  await expect(page.getByText("Shared resource assigned", { exact: true }).last()).toBeVisible();
  await expect(attentionRow).toContainText("Healthy");
  await attentionRow.getByRole("button", { name: "Swap assignments", exact: true }).click();
  await expect(page.getByText("Shared resources swapped", { exact: true }).last()).toBeVisible();

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
  await page.getByRole("combobox", { name: "Per page" }).selectOption("25");
  const activeExceptionTask = page.getByRole("row").filter({ hasText: EXCEPTION_TITLE })
    .filter({ has: page.getByRole("button", { name: "Start", exact: true }) });
  await expect(activeExceptionTask).toHaveCount(1);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/manage/workspace/demo-lodge/master-calendar");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const mobileAttentionRow = page.locator('[wire\\:key^="attention-"]').filter({ hasText: RESERVATION_REFERENCE });
  page.once("dialog", async (dialog) => dialog.accept());
  await mobileAttentionRow.getByRole("button", { name: "Release", exact: true }).click();
  await expect(page.getByText("Shared resource released", { exact: true }).last()).toBeVisible();
  await expect(mobileAttentionRow).toContainText("Unassigned");
  await mobileAttentionRow.getByRole("button", { name: `Assign ${OWN_GUIDE_NAME}`, exact: true }).click();
  await expect(page.getByText("Shared resource assigned", { exact: true }).last()).toBeVisible();
  await expect(mobileAttentionRow).toContainText("Healthy");
  const screenshotPath = testInfo.outputPath("operational-acceptance-mobile-redacted.png");
  await page.screenshot({
    path: screenshotPath,
    fullPage: true,
    mask: [page.getByText("Operational Review UAT")],
  });
  await testInfo.attach("operational-acceptance-mobile-redacted", { path: screenshotPath, contentType: "image/png" });
  const managerTrace = testInfo.outputPath("operational-acceptance-manager.trace.zip");
  await manager.context.tracing.stop({ path: managerTrace });
  await testInfo.attach("operational-acceptance-manager-trace", { path: managerTrace, contentType: "application/zip" });
  await manager.context.close();

  const guide = await rolePage(browser, baseURL, "guide@example.com");
  await guide.context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  const denied = await guide.page.goto("/manage/workspace/demo-lodge/finance-dashboard");
  expect([403, 404]).toContain(denied?.status());
  for (const pathName of ["guests", "reservations", "payments", "properties"]) {
    const response = await guide.page.goto(`/manage/workspace/demo-lodge/${pathName}`);
    expect([403, 404], `Guide unexpectedly accessed ${pathName}`).toContain(response?.status());
  }
  await guide.page.goto("/manage/workspace/demo-lodge/master-calendar");
  await expect(guide.page.getByText("Shared-resource attention workbench", { exact: true })).toHaveCount(0);
  await expect(guide.page.getByText(RESERVATION_REFERENCE, { exact: false })).toBeVisible();
  await expect(guide.page.getByText(SWAP_RESERVATION_REFERENCE, { exact: false })).toHaveCount(0);
  await expect(guide.page.getByText(OWN_GUIDE_NAME, { exact: false }).first()).toBeVisible();
  const guideTrace = testInfo.outputPath("operational-acceptance-guide-denial.trace.zip");
  await guide.context.tracing.stop({ path: guideTrace });
  await testInfo.attach("operational-acceptance-guide-denial-trace", { path: guideTrace, contentType: "application/zip" });
  await guide.context.close();
});
