import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { BrowserContext, expect, Locator, Page, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const DEMO_GUEST_TOKEN = "g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA";
const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

async function chooseOption(page: Page, label: RegExp, option: string): Promise<void> {
  const select = page.getByRole("combobox", { name: label });
  if (await select.evaluate((element) => element.tagName === "SELECT")) {
    await select.selectOption({ label: option });
    return;
  }

  await select.click();
  await page.getByRole("option", { name: option, exact: true }).click();
}

async function waitForDownloadAction(page: Page, tabOrPage: string, rowText: string, existingUrls: Set<string>): Promise<Locator> {
  for (let attempt = 0; attempt < 30; attempt += 1) {
    await page.reload();
    if (tabOrPage === "Documents") await page.getByRole("tab", { name: "Documents" }).click();
    const actions = page.getByRole("row")
      .filter({ hasText: new RegExp(rowText, "i") })
      .getByRole("link", { name: "Download", exact: true });
    try {
      await actions.first().waitFor({ state: "visible", timeout: 1_500 });
    } catch {
      await page.waitForTimeout(500);
      continue;
    }
    const count = await actions.count();
    for (let index = 0; index < count; index += 1) {
      const action = actions.nth(index);
      const url = await action.getAttribute("href");
      if (url && !existingUrls.has(url)) return action;
    }
    await page.waitForTimeout(500);
  }
  throw new Error("Queued artifact did not become downloadable.");
}

test("P3-03 generates and opens private PDF, CSV, XLSX, and guest artifacts", async ({ browser, page, baseURL }) => {
  test.setTimeout(240_000);
  if (!baseURL) throw new Error("P3-03 requires an API base URL.");
  const openedContexts: BrowserContext[] = [];

  try {
    page.setDefaultTimeout(15_000);
    await signIn(page);
    await page.goto("/manage/workspace/demo-lodge/reservations");
    await page.getByRole("searchbox", { name: "Search", exact: true }).fill("RSV-DEMO-001");
    const reservationRow = page.getByRole("row").filter({ hasText: "RSV-DEMO-001" }).first();
    await reservationRow.getByRole("link", { name: "RSV-DEMO-001", exact: true }).click();
    await page.getByRole("tab", { name: "Documents" }).click();
    await page.locator("table").waitFor();
    const existingDocumentUrls = new Set(await page.getByRole("link", { name: "Download", exact: true }).evaluateAll((links) => links.map((link) => (link as HTMLAnchorElement).href)));
    await page.getByRole("button", { name: "Generate document", exact: true }).click();
    const documentDialog = page.getByRole("dialog", { name: "Generate document" });
    await chooseOption(page, /Kind/, "Reservation confirmation");
    await chooseOption(page, /Locale/, "English");
    await documentDialog.getByRole("button", { name: "Submit", exact: true }).click();

    const documentDownloadAction = await waitForDownloadAction(page, "Documents", "Reservation Confirmation", existingDocumentUrls);
    const documentDownloadPromise = page.waitForEvent("download");
    await documentDownloadAction.click();
    const documentDownload = await documentDownloadPromise;
    const pdfPath = await documentDownload.path();
    expect(pdfPath).toBeTruthy();
    const pdf = readFileSync(pdfPath!);
    expect(pdf.subarray(0, 5).toString()).toBe("%PDF-");
    expect(documentDownload.suggestedFilename()).toMatch(/reservation-confirmation.*\.pdf$/);
    const pdfInfo = execFileSync("pdfinfo", [pdfPath!], { encoding: "utf8" });
    expect(pdfInfo).toMatch(/Pages:\s+[1-9]/);
    const pdfText = execFileSync("pdftotext", [pdfPath!, "-"], { encoding: "utf8" });
    expect(pdfText).toContain("Reservation confirmation");

    await page.goto("/manage/workspace/demo-lodge/report-exports");
    await page.locator("table").waitFor();
    for (const [kind, format] of [["Arrivals", "Csv"], ["Revenue", "Xlsx"]] as const) {
      const existingExportUrls = new Set(await page.getByRole("link", { name: "Download", exact: true }).evaluateAll((links) => links.map((link) => (link as HTMLAnchorElement).href)));
      await page.getByRole("button", { name: "Request report export", exact: true }).click();
      const exportDialog = page.getByRole("dialog", { name: "Request report export" });
      await chooseOption(page, /Property/, "Estancia Viento Sur");
      await chooseOption(page, /Kind/, kind);
      await chooseOption(page, /Format/, format);
      await exportDialog.getByRole("button", { name: "Submit", exact: true }).click();
      const exportDownloadAction = await waitForDownloadAction(page, "Reports", kind, existingExportUrls);
      const exportDownloadPromise = page.waitForEvent("download");
      await exportDownloadAction.click();
      const exportDownload = await exportDownloadPromise;
      const exportPath = await exportDownload.path();
      expect(exportPath).toBeTruthy();
      const artifact = readFileSync(exportPath!);
      if (format === "Csv") {
        expect(artifact.toString("utf8")).toContain("Confirmation");
      } else {
        expect(artifact.subarray(0, 4).toString("hex")).toBe("504b0304");
        execFileSync("unzip", ["-t", exportPath!]);
      }
    }

    const guestContext = await browser.newContext({ baseURL, viewport: { width: 390, height: 844 }, storageState: EMPTY_STORAGE_STATE });
    openedContexts.push(guestContext);
    const guestPage = await guestContext.newPage();
    await guestPage.goto(`/guest/access/${DEMO_GUEST_TOKEN}`);
    await guestPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Documents", exact: true }).click();
    await expect(guestPage.getByRole("heading", { name: "Generated stay documents" })).toBeVisible();
    expect(await guestPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const guestDownloadPromise = guestPage.waitForEvent("download");
    await guestPage.getByRole("link", { name: /Download .*\.pdf/ }).first().click();
    const guestDownload = await guestDownloadPromise;
    expect(readFileSync((await guestDownload.path())!).subarray(0, 5).toString()).toBe("%PDF-");
  } finally {
    await Promise.all(openedContexts.map((context) => context.close()));
  }
});
