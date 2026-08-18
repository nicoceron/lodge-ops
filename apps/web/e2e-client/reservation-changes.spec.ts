import { expect, Page, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const pad = (value: number) => String(value).padStart(2, "0");

function localDateTime(daysFromNow: number, hour: number): string {
  const date = new Date();
  date.setDate(date.getDate() + daysFromNow);
  date.setHours(hour, 0, 0, 0);

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(hour)}:00`;
}

async function chooseOption(page: Page, label: string, option: string): Promise<void> {
  await page.getByRole("combobox", { name: label }).click();
  await page.getByRole("option", { name: option, exact: true }).click();
}

function moneyToMinor(value: string): number {
  return Math.round(Number(value.replaceAll(",", "")) * 100);
}

async function clickHeaderAction(page: Page, name: string): Promise<void> {
  await page.getByRole("button", { name, exact: true }).evaluate((button: HTMLButtonElement) => button.click());
}

test("N2 guarded amendment, room move, cancellation fee, and refund close the financial loop", async ({ page }) => {
  test.setTimeout(120_000);
  await signIn(page);

  const run = Date.now();
  const arrival = localDateTime(13, 15);
  const departure = localDateTime(16, 11);
  const amendedDeparture = localDateTime(17, 11);

  await page.goto("/manage/workspace/demo-lodge/reservations/create");
  await chooseOption(page, "Property*", "Estancia Viento Sur");
  await chooseOption(page, "Accommodation category*", "Cabin");
  await page.getByRole("textbox", { name: "Arrival*" }).fill(arrival);
  await page.getByRole("textbox", { name: "Departure*" }).fill(departure);
  await page.getByRole("spinbutton", { name: "Adults*" }).fill("2");
  await chooseOption(page, "Rate plan*", "Flexible lodge rate · USD");
  await page.getByRole("textbox", { name: "New guest first name*" }).fill(`N2 ${run}`);
  await page.getByRole("textbox", { name: "New guest last name" }).fill("Browser UAT");
  await page.getByRole("textbox", { name: "New guest email" }).fill(`n2-${run}@example.test`);
  await page.getByRole("textbox", { name: "Source" }).fill(`n2-playwright-${run}`);
  await page.getByRole("button", { name: "Create", exact: true }).click();
  await expect(page).toHaveURL(/\/reservations\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);

  const reservationUrl = page.url();
  const pageHeading = page.getByRole("heading", { level: 1 });
  await expect(pageHeading).toContainText(/View RSV-/);
  const heading = (await pageHeading.innerText()).trim();
  const confirmation = heading.replace(/^View /, "").trim();
  await expect(page.getByText("$", { exact: false }).first()).toBeVisible();

  await clickHeaderAction(page, "Confirm");
  const confirmationDialog = page.getByRole("alertdialog", { name: "Confirm" });
  await confirmationDialog.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(confirmationDialog).toHaveCount(0);
  await page.reload();
  await expect(page.getByRole("button", { name: "Check in", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Edit", exact: true })).toHaveCount(0);

  await clickHeaderAction(page, "Amend stay");
  const amendment = page.getByRole("dialog", { name: "Amend stay" });
  await amendment.getByRole("textbox", { name: "Departure*" }).fill(amendedDeparture);
  await amendment.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(amendment).toHaveCount(0);
  await page.reload();

  await clickHeaderAction(page, "Move room");
  const move = page.getByRole("dialog", { name: "Move room" });
  await move.getByRole("combobox", { name: "Current assignment*" }).selectOption({ index: 1 });
  await chooseOption(page, "New resource*", "Lenga Suite");
  await move.getByRole("textbox", { name: "Move reason" }).fill("Playwright N2 room move");
  await move.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(move).toHaveCount(0);
  await page.reload();
  await page.getByRole("tab", { name: "Allocations" }).click();
  await expect(page.getByText("Lenga Suite", { exact: true })).toBeVisible();

  const summaryBeforePayment = await page.getByRole("region", { name: "Folio summary" }).innerText();
  const totalMatch = summaryBeforePayment.match(/Total\s+\$([\d,]+\.\d{2})/);
  expect(totalMatch).not.toBeNull();
  const totalMinor = moneyToMinor(totalMatch![1]);

  await page.goto("/manage/workspace/demo-lodge/payments");
  await page.getByRole("button", { name: "Record manual payment", exact: true }).click();
  const payment = page.getByRole("dialog", { name: "Record manual payment" });
  await payment.getByRole("combobox", { name: "Reservation id*" }).click();
  await payment.getByPlaceholder("Start typing to search...").fill(confirmation);
  await page.getByRole("option", { name: confirmation, exact: true }).click();
  await payment.getByRole("combobox", { name: "Method*" }).selectOption("bank_transfer");
  await payment.getByRole("spinbutton", { name: "Amount (minor units)*" }).fill(String(totalMinor));
  await payment.getByRole("textbox", { name: "Provider", exact: true }).fill("playwright");
  const paymentReference = `n2-payment-${run}`;
  await payment.getByRole("textbox", { name: "Provider reference", exact: true }).fill(paymentReference);
  await payment.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(payment).toHaveCount(0);

  const paymentRow = page.getByRole("row").filter({ hasText: paymentReference });
  await paymentRow.getByRole("link", { name: "View", exact: true }).click();
  await page.getByRole("button", { name: "Reconcile", exact: true }).click();
  const reconciliationDialog = page.getByRole("alertdialog", { name: "Reconcile" });
  await reconciliationDialog.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(reconciliationDialog).toHaveCount(0);
  await page.reload();
  await expect(page.getByText("Not reconciled", { exact: true })).toHaveCount(0);

  await page.goto(reservationUrl);
  await expect(page.getByRole("region", { name: "Folio summary" })).toBeVisible();
  await page.waitForTimeout(750);
  await clickHeaderAction(page, "Cancel");
  const cancellation = page.getByRole("alertdialog", { name: "Cancel" });
  await cancellation.getByRole("textbox", { name: "Cancellation reason*" }).fill("Playwright N2 cancellation");
  await cancellation.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(cancellation).toHaveCount(0);
  await page.reload();
  await expect(page.getByText("Playwright N2 cancellation", { exact: true })).toBeVisible();

  const cancelledSummary = await page.getByRole("region", { name: "Folio summary" }).innerText();
  const creditMatch = cancelledSummary.match(/Open balance\s+-\$([\d,]+\.\d{2})/);
  expect(creditMatch).not.toBeNull();
  const refundMinor = moneyToMinor(creditMatch![1]);
  expect(refundMinor).toBe(totalMinor / 2);

  await clickHeaderAction(page, "Request refund");
  const refundRequest = page.getByRole("dialog", { name: "Request refund" });
  await refundRequest.getByRole("combobox", { name: "Source payment*" }).selectOption({ index: 1 });
  await refundRequest.getByRole("spinbutton", { name: "Amount · minor units*" }).fill(String(refundMinor));
  await refundRequest.getByRole("textbox", { name: "Reason*" }).fill("Return refundable Playwright balance");
  await refundRequest.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(refundRequest).toHaveCount(0);
  await page.reload();

  await clickHeaderAction(page, "Complete refund");
  const completion = page.getByRole("dialog", { name: "Complete refund" });
  await completion.getByRole("combobox", { name: "Open refund request*" }).selectOption({ index: 1 });
  await completion.getByRole("textbox", { name: "Internal / provider reference*" }).fill(`n2-refund-${run}`);
  await completion.getByRole("button", { name: "Submit", exact: true }).click();
  await expect(completion).toHaveCount(0);
  await page.reload();

  await expect(page.getByRole("region", { name: "Folio summary" })).toContainText("$0.00");
  await expect(page.getByRole("button", { name: "Complete refund", exact: true })).toHaveCount(0);
  await page.getByRole("tab", { name: "Change ledger" }).click();
  await expect(page.getByText("Amendment", { exact: true })).toBeVisible();
  await expect(page.getByText("Cancellation", { exact: true })).toBeVisible();
  await expect(page.getByText("Refund Completed", { exact: true })).toBeVisible();
  await expect(page.getByText("Playwright N2 room move", { exact: true })).toBeVisible();
  await expect(page.getByText("Return refundable Playwright balance", { exact: true }).first()).toBeVisible();
});
