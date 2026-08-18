import { execFileSync } from "node:child_process";
import path from "node:path";
import { Browser, BrowserContext, expect, Locator, Page, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const CROSS_PROPERTY_RESERVATION_ID = "22222222-2222-4222-8222-222222222222";
const EXPIRED_GUEST_TOKEN = "g_expired_client_uat_link_00000001";
const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };
const pad = (value: number) => String(value).padStart(2, "0");

function localDateTime(daysFromNow: number, hour: number): string {
  const date = new Date();
  date.setDate(date.getDate() + daysFromNow);
  date.setHours(hour, 0, 0, 0);

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(hour)}:00`;
}

function runArtisan(...arguments_: string[]): void {
  execFileSync(
    "docker",
    ["compose", "exec", "-T", "api", "php", "artisan", ...arguments_],
    { cwd: path.resolve(process.cwd(), "../.."), stdio: "inherit" },
  );
}

async function chooseOption(page: Page | Locator, label: string, option: string): Promise<void> {
  await page.getByRole("combobox", { name: label }).click();
  await page.getByRole("option", { name: option, exact: true }).click();
}

async function clickHeaderAction(page: Page, name: string): Promise<void> {
  await page.getByRole("button", { name, exact: true }).evaluate((button: HTMLButtonElement) => button.click());
}

async function communicationRow(page: Page, requiredText: string[]): Promise<Locator> {
  for (let attempt = 0; attempt < 12; attempt += 1) {
    await page.reload();
    await page.getByRole("tab", { name: "Communications" }).click();
    let row = page.getByRole("row");
    for (const text of requiredText) row = row.filter({ hasText: text });
    try {
      await row.first().waitFor({ state: "attached", timeout: 1_500 });
      return row.first();
    } catch {
      // The relation manager is lazy and can still be waiting on the outbox worker.
    }
    await page.waitForTimeout(500);
  }

  throw new Error(`Communication did not appear with: ${requiredText.join(", ")}`);
}

async function rolePage(
  browser: Browser,
  baseURL: string,
  email: string,
  viewport = { width: 1440, height: 1000 },
): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL, viewport, storageState: EMPTY_STORAGE_STATE });
  context.setDefaultTimeout(15_000);
  const page = await context.newPage();
  await signIn(page, email, "password");

  return { context, page };
}

test("P3-02 closes the staff, guest, finance, stay, folio, and survey loop", async ({ browser, page, baseURL }) => {
  test.setTimeout(300_000);
  if (!baseURL) throw new Error("P3-02 requires an API base URL.");

  const run = Date.now();
  const stayOffset = 400 + (run % 10_000);
  const arrival = localDateTime(stayOffset, 15);
  const departure = localDateTime(stayOffset + 2, 11);
  const guestEmail = `p3-02-${run}@example.test`;
  const transferReferenceV1 = `p3-02-transfer-${run}-v1`;
  const transferReferenceV2 = `p3-02-transfer-${run}-v2`;
  const extraPaymentReference = `p3-02-extra-${run}`;
  const evidenceV1 = path.resolve(process.cwd(), "e2e-client/fixtures/transfer-evidence-v1.pdf");
  const evidenceV2 = path.resolve(process.cwd(), "e2e-client/fixtures/transfer-evidence-v2.pdf");
  const openedContexts: BrowserContext[] = [];

  try {
    page.setDefaultTimeout(15_000);
    runArtisan("cache:clear");
    await page.goto("/manage/workspace/demo-lodge/reservations/create");
    await chooseOption(page, "Property*", "Estancia Viento Sur");
    await chooseOption(page, "Accommodation category*", "Cabin");
    await page.getByRole("textbox", { name: "Arrival*" }).fill(arrival);
    await page.getByRole("textbox", { name: "Departure*" }).fill(departure);
    await page.getByRole("spinbutton", { name: "Adults*" }).fill("2");
    await chooseOption(page, "Rate plan*", "Flexible lodge rate · USD");
    await page.getByRole("textbox", { name: "New guest first name*" }).fill(`P3-02 ${run}`);
    await page.getByRole("textbox", { name: "New guest last name" }).fill("Closed Loop");
    await page.getByRole("textbox", { name: "New guest email" }).fill(guestEmail);
    await page.getByRole("textbox", { name: "Source" }).fill(`p3-02-playwright-${run}`);
    await page.getByRole("button", { name: "Create", exact: true }).click();
    await expect(page).toHaveURL(/\/reservations\/[0-9a-f-]{36}$/);

    const reservationUrl = page.url();
    const reservationId = reservationUrl.split("/").at(-1)!;
    const heading = (await page.getByRole("heading", { level: 1 }).innerText()).trim();
    const confirmation = heading.replace(/^View /, "").trim();
    const folioSummary = page.getByRole("region", { name: "Folio summary" });
    await expect(folioSummary).toContainText(/Total/);
    await page.getByRole("tab", { name: "Allocations" }).click();
    await expect(page.getByText("Cabin", { exact: false }).first()).toBeVisible();

    await clickHeaderAction(page, "Confirm");
    const confirmDialog = page.getByRole("alertdialog", { name: "Confirm" });
    await confirmDialog.getByRole("button", { name: "Confirm", exact: true }).click();
    await expect(confirmDialog).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole("button", { name: "Check in", exact: true })).toBeVisible();
    await clickHeaderAction(page, "Move room");
    const roomAssignment = page.getByRole("dialog", { name: "Move room" });
    await roomAssignment.getByRole("combobox", { name: "Current assignment*" }).selectOption({ index: 1 });
    await chooseOption(roomAssignment, "New resource*", "River Cabin");
    await roomAssignment.getByRole("textbox", { name: "Move reason" }).fill("P3-02 assign exact stay place");
    await roomAssignment.getByRole("button", { name: "Submit", exact: true }).click();
    await expect(roomAssignment).toHaveCount(0);

    await page.goto("/manage/workspace/demo-lodge/master-calendar");
    await page.getByLabel("From").fill(arrival.slice(0, 10));
    await page.getByLabel("Through").fill(departure.slice(0, 10));
    await expect(page.getByText(confirmation, { exact: false }).first()).toBeVisible();
    await page.goto(reservationUrl);
    await page.getByRole("tab", { name: "Allocations" }).click();
    await expect(page.getByText("River Cabin", { exact: true })).toBeVisible();
    const assignedRoom = "River Cabin";

    runArtisan("outbox:publish", "--batch=100");
    const confirmationMessage = await communicationRow(page, [confirmation, "Reservation confirmation"]);
    const confirmationBody = await confirmationMessage.innerText();
    const guestLinkMatch = confirmationBody.match(/https?:\/\/\S+\/guest\/access\/\S+/);
    expect(guestLinkMatch, confirmationBody).not.toBeNull();
    const guestLink = guestLinkMatch![0].replace("http://localhost:8000", baseURL);

    const guestContext = await browser.newContext({
      baseURL,
      viewport: { width: 390, height: 844 },
      storageState: EMPTY_STORAGE_STATE,
    });
    guestContext.setDefaultTimeout(15_000);
    openedContexts.push(guestContext);
    const guestPage = await guestContext.newPage();
    await guestPage.goto(guestLink);
    await expect(guestPage.getByRole("heading", { name: /Welcome/ })).toBeVisible();
    expect(await guestPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    const replayContext = await browser.newContext({ baseURL, storageState: EMPTY_STORAGE_STATE });
    openedContexts.push(replayContext);
    const replayPage = await replayContext.newPage();
    await replayPage.goto(guestLink);
    await expect(replayPage.getByRole("heading", { name: "Guest portal unavailable" })).toBeVisible();
    await replayPage.goto(`/guest/access/${EXPIRED_GUEST_TOKEN}`);
    await expect(replayPage.getByRole("heading", { name: "Guest portal unavailable" })).toBeVisible();
    await replayPage.close();

    await guestPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Pre-arrival", exact: true }).click();
    await guestPage.getByLabel("Preferred name").fill("P3-02 Guest");
    await guestPage.getByLabel("Email").fill(guestEmail);
    await guestPage.getByLabel("Mobile").fill("+15550102030");
    await guestPage.getByLabel("Emergency contact name").fill("UAT Contact");
    await guestPage.getByLabel("Emergency contact phone").fill("+15550102031");
    await guestPage.getByLabel("Arrival method").selectOption("car");
    await guestPage.getByLabel("Arrival reference").fill("UAT rental car");
    await guestPage.getByLabel("Arrival time").fill(arrival);
    await guestPage.getByLabel("Departure reference").fill("UAT rental car");
    await guestPage.getByLabel("Departure time").fill(departure);
    await guestPage.getByLabel("Dietary style").fill("Vegetarian");
    await guestPage.getByLabel("Allergies").fill("None");
    await guestPage.getByLabel("Accessibility or mobility needs").fill("None");
    await guestPage.getByLabel(/I consent/).check();
    await guestPage.getByRole("button", { name: "Save pre-arrival details" }).click();
    await expect(guestPage.getByText("Pre-arrival details saved.")).toBeVisible();
    await guestPage.reload();
    await expect(guestPage.getByLabel("Preferred name")).toHaveValue("P3-02 Guest");

    await guestPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Documents", exact: true }).click();
    await guestPage.getByLabel("Type your full name as your signature").fill("P3-02 Guest");
    await guestPage.getByLabel("I have read and accept this document.").check();
    await guestPage.getByRole("button", { name: "Acknowledge document" }).click();
    await expect(guestPage.getByText("Document acknowledged.")).toBeVisible();

    await guestPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Payment", exact: true }).click();
    const originalBalance = await guestPage.locator(".stat").first().innerText();
    await guestPage.getByLabel("Bank transfer reference").fill(transferReferenceV1);
    await guestPage.getByLabel("Receipt or transfer evidence").setInputFiles(evidenceV1);
    await guestPage.getByRole("button", { name: "Submit evidence" }).click();
    await expect(guestPage.getByText("Payment evidence submitted for review.")).toBeVisible();
    await guestPage.getByLabel("Bank transfer reference").fill(transferReferenceV1);
    await guestPage.getByLabel("Receipt or transfer evidence").setInputFiles(evidenceV1);
    await guestPage.getByRole("button", { name: "Submit evidence" }).click();
    await expect(guestPage.locator(".stat").first()).toHaveText(originalBalance);

    const finance = await rolePage(browser, baseURL, "finance@example.com");
    openedContexts.push(finance.context);
    await finance.page.goto("/manage/workspace/demo-lodge/payment-evidence");
    const firstEvidenceRow = finance.page.getByRole("row").filter({ hasText: transferReferenceV1 });
    await expect(firstEvidenceRow).toHaveCount(1);
    await firstEvidenceRow.getByRole("link", { name: "View", exact: true }).click();
    await expect(finance.page).toHaveURL(/\/payment-evidence\/[0-9a-f-]{36}$/);
    const firstEvidenceDetail = finance.page;
    const evidenceDownload = firstEvidenceDetail.getByRole("link", { name: "Download", exact: true });
    const evidenceDownloadUrl = await evidenceDownload.getAttribute("href");
    expect(evidenceDownloadUrl).toBeTruthy();
    const previewPagePromise = finance.context.waitForEvent("page");
    await evidenceDownload.click();
    const previewPage = await previewPagePromise;
    await previewPage.waitForLoadState();
    expect(previewPage.url()).toBe(evidenceDownloadUrl);
    await previewPage.close();
    await firstEvidenceDetail.getByRole("button", { name: "Request information", exact: true }).click();
    const informationDialog = firstEvidenceDetail.getByRole("dialog", { name: "Request information" });
    await informationDialog.getByRole("textbox", { name: "Note*" }).fill("Please upload the bank confirmation page.");
    await informationDialog.getByRole("button", { name: "Submit", exact: true }).click();
    await expect(informationDialog).toHaveCount(0);

    await guestPage.reload();
    await expect(guestPage.locator(".stat").first()).toHaveText(originalBalance);
    await expect(guestPage.getByText("Please upload the bank confirmation page.")).toBeVisible();
    await guestPage.getByLabel("Bank transfer reference").fill(transferReferenceV2);
    await guestPage.getByLabel("Receipt or transfer evidence").setInputFiles(evidenceV2);
    await guestPage.getByRole("button", { name: "Submit evidence" }).click();

    await finance.page.goto("/manage/workspace/demo-lodge/payment-evidence");
    const validEvidenceRow = finance.page.getByRole("row").filter({ hasText: transferReferenceV2 });
    await validEvidenceRow.getByRole("link", { name: "View", exact: true }).click();
    await expect(finance.page).toHaveURL(/\/payment-evidence\/[0-9a-f-]{36}$/);
    const validEvidenceDetail = finance.page;
    await validEvidenceDetail.getByRole("button", { name: "Approve & reconcile", exact: true }).click();
    const approvalDialog = validEvidenceDetail.getByRole("alertdialog", { name: "Approve & reconcile" });
    await approvalDialog.getByRole("combobox", { name: "Apply to deposit" }).selectOption({ index: 1 });
    await approvalDialog.getByRole("textbox", { name: "Reconciliation note" }).fill("Matched P3-02 transfer.");
    await approvalDialog.getByRole("button", { name: "Confirm", exact: true }).click();
    await expect(approvalDialog).toHaveCount(0);
    await validEvidenceDetail.reload();
    await expect(validEvidenceDetail.getByRole("button", { name: "Approve & reconcile", exact: true })).toHaveCount(0);

    await guestPage.reload();
    await expect(guestPage.locator(".stat").first()).toHaveText(/USD 0\.00/);

    const operations = await rolePage(browser, baseURL, "operations@example.com");
    openedContexts.push(operations.context);
    await operations.page.goto(reservationUrl);
    await clickHeaderAction(operations.page, "Check in");
    await expect(operations.page.getByText("Reservation updated: Check in", { exact: true })).toBeVisible();
    await operations.page.reload();
    await expect(operations.page.getByRole("button", { name: "Check out", exact: true })).toBeVisible();

    await operations.page.goto("/manage/workspace/demo-lodge/operational-tasks/create");
    await operations.page.getByRole("textbox", { name: "Title*" }).fill(`Complete P3-02 welcome task ${run}`);
    await chooseOption(operations.page, "Property id*", "Estancia Viento Sur");
    await chooseOption(operations.page, "Reservation", confirmation);
    await operations.page.getByRole("button", { name: "Create", exact: true }).click();
    await expect(operations.page).toHaveURL(/\/operational-tasks\/[0-9a-f-]{36}$/);
    await operations.page.getByRole("link", { name: "Edit", exact: true }).click();
    await chooseOption(operations.page, "Status*", "Done");
    await operations.page.getByRole("button", { name: "Save changes", exact: true }).click();
    await expect(operations.page.getByText("Done", { exact: true }).first()).toBeVisible();

    await operations.page.goto("/manage/workspace/demo-lodge/folio-lines");
    await operations.page.getByRole("button", { name: "Post folio entry", exact: true }).click();
    const extraDialog = operations.page.getByRole("dialog", { name: "Post folio entry" });
    await extraDialog.getByRole("combobox", { name: "Reservation id*" }).click();
    await extraDialog.getByPlaceholder("Start typing to search...").fill(confirmation);
    await operations.page.getByRole("option", { name: confirmation, exact: true }).click();
    await extraDialog.getByRole("combobox", { name: "Type*" }).selectOption("charge");
    await extraDialog.getByRole("textbox", { name: "Description*" }).fill("P3-02 late checkout extra");
    await extraDialog.getByRole("spinbutton", { name: "Quantity*" }).fill("1");
    await extraDialog.getByRole("spinbutton", { name: "Unit amount (minor units)*" }).fill("2500");
    await extraDialog.getByRole("spinbutton", { name: "Tax total (minor units)*" }).fill("0");
    await extraDialog.getByRole("button", { name: "Submit", exact: true }).click();

    await operations.page.goto(reservationUrl);
    await clickHeaderAction(operations.page, "Check out");
    await expect(operations.page.getByText("Reservation updated: Check out", { exact: true })).toBeVisible();
    await operations.page.reload();
    await expect(operations.page.getByRole("button", { name: "Check out", exact: true })).toHaveCount(0);
    await expect(operations.page.getByText("Not checked out", { exact: true })).toHaveCount(0);

    await finance.page.goto("/manage/workspace/demo-lodge/payments");
    await finance.page.getByRole("button", { name: "Record manual payment", exact: true }).click();
    const extraPayment = finance.page.getByRole("dialog", { name: "Record manual payment" });
    await extraPayment.getByRole("combobox", { name: "Reservation id*" }).click();
    await extraPayment.getByPlaceholder("Start typing to search...").fill(confirmation);
    await finance.page.getByRole("option", { name: confirmation, exact: true }).click();
    await extraPayment.getByRole("combobox", { name: "Method*" }).selectOption("bank_transfer");
    await extraPayment.getByRole("spinbutton", { name: "Amount (minor units)*" }).fill("2500");
    await extraPayment.getByRole("textbox", { name: "External processor", exact: true }).fill("manual-bank");
    await extraPayment.getByRole("textbox", { name: "External reference", exact: true }).fill(extraPaymentReference);
    await extraPayment.getByRole("button", { name: "Submit", exact: true }).click();
    const extraPaymentRow = finance.page.getByRole("row").filter({ hasText: extraPaymentReference });
    await extraPaymentRow.getByRole("link", { name: "View", exact: true }).click();
    await finance.page.getByRole("button", { name: "Reconcile", exact: true }).click();
    const reconcileDialog = finance.page.getByRole("alertdialog", { name: "Reconcile" });
    await reconcileDialog.getByRole("button", { name: "Confirm", exact: true }).click();
    await expect(finance.page.getByText("Payment reconciled and folio credit posted", { exact: true })).toBeVisible();

    await operations.page.goto(reservationUrl);
    await expect(operations.page.getByRole("region", { name: "Folio summary" })).toContainText("$0.00");
    await clickHeaderAction(operations.page, "Close folio");
    const closeDialog = operations.page.getByRole("alertdialog", { name: "Close folio" });
    await closeDialog.getByRole("button", { name: "Confirm", exact: true }).click();
    await expect(operations.page.getByText("Folio closed", { exact: true })).toBeVisible();
    await operations.page.reload();
    await expect(operations.page.getByRole("button", { name: "Close folio", exact: true })).toHaveCount(0);
    await expect(operations.page.getByRole("button", { name: "Reopen folio", exact: true })).toBeVisible();

    await operations.page.goto("/manage/workspace/demo-lodge/resources");
    const roomRow = operations.page.getByRole("row").filter({ hasText: assignedRoom! });
    await roomRow.getByRole("button", { name: "Housekeeping", exact: true }).click();
    const housekeepingDialog = operations.page.getByRole("dialog", { name: "Housekeeping" });
    await housekeepingDialog.getByRole("combobox", { name: "Status*" }).selectOption("inspected");
    await housekeepingDialog.getByRole("button", { name: "Submit", exact: true }).click();
    await expect(roomRow).toContainText("Inspected");

    runArtisan("reservation-milestones:dispatch");
    runArtisan("outbox:publish", "--batch=100");
    await page.goto(reservationUrl);
    const surveyMessage = await communicationRow(page, ["Thank you and survey"]);
    const surveyBody = await surveyMessage.innerText();
    const surveyLinkMatch = surveyBody.match(/https?:\/\/\S+\/guest\/access\/\S+/);
    expect(surveyLinkMatch, surveyBody).not.toBeNull();
    const surveyContext = await browser.newContext({
      baseURL,
      viewport: { width: 390, height: 844 },
      storageState: EMPTY_STORAGE_STATE,
    });
    surveyContext.setDefaultTimeout(15_000);
    openedContexts.push(surveyContext);
    const surveyPage = await surveyContext.newPage();
    await surveyPage.goto(surveyLinkMatch![0].replace("http://localhost:8000", baseURL));
    await surveyPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Feedback", exact: true }).click();
    await surveyPage.getByLabel("Overall stay").selectOption("5");
    await surveyPage.getByLabel("Guide experience").selectOption("4");
    await surveyPage.getByRole("textbox", { name: "Comments", exact: true }).fill(`P3-02 closed-loop response ${run}`);
    await surveyPage.getByLabel("Share my comments with the lodge team.").check();
    await surveyPage.getByRole("button", { name: "Submit feedback" }).click();
    await expect(surveyPage.getByText("Thank you. Your feedback was submitted.")).toBeVisible();
    await surveyPage.reload();
    await expect(surveyPage.getByText("Your feedback has already been submitted.")).toBeVisible();

    await operations.page.goto("/manage/workspace/demo-lodge/survey-responses");
    await expect(operations.page.getByRole("row").filter({ hasText: `P3-02 closed-loop response ${run}` })).toBeVisible();

    const viewer = await rolePage(browser, baseURL, "viewer@example.com");
    openedContexts.push(viewer.context);
    const financeDenied = await viewer.page.goto("/manage/workspace/demo-lodge/payment-evidence");
    expect(financeDenied?.status()).toBe(403);
    const crossPropertyDenied = await viewer.page.goto(`/manage/workspace/demo-lodge/reservations/${CROSS_PROPERTY_RESERVATION_ID}`);
    expect([403, 404]).toContain(crossPropertyDenied?.status());

    expect(reservationId).toMatch(/^[0-9a-f-]{36}$/);
  } finally {
    await Promise.allSettled(openedContexts.map((context) => context.close()));
  }
});
