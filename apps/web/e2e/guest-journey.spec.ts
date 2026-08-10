import { expect, test } from "@playwright/test";

const guestPortal = "/guest/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA";

test("guest prepares for a stay through a private reservation link", async ({ page }) => {
  await page.goto(guestPortal);

  await expect(page.getByRole("heading", { name: "A clear view of your stay" })).toBeVisible();
  await expect(page.getByRole("navigation", { name: "Guest portal navigation" })).toBeVisible();
  await expect(page.getByLabel("Private reservation link")).toBeVisible();

  await page.getByRole("link", { name: /Pre-arrival|Prepare/ }).click();
  await expect(page).toHaveURL(/\/pre-arrival$/);
  await page.getByLabel("Emergency contact", { exact: true }).fill("Jamie Morgan");
  await page.getByLabel("Emergency contact phone").fill("+1 415 555 0120");
  await page.getByLabel("Departure reference").fill("LA 897");
  await page.getByLabel("Expected departure").fill("2026-08-17T13:20");
  await page.getByLabel(/I consent to sharing these essential details/).check();
  await page.getByRole("button", { name: "Save pre-arrival details" }).click();
  await expect(page.getByRole("status")).toContainText("Pre-arrival details saved");

  await page.getByRole("link", { name: /Documents|Docs/ }).click();
  await page.getByLabel(/I have read, understood and agree/).check();
  await page.getByLabel(/Electronic signature/).fill("Alex Morgan");
  await page.getByRole("button", { name: "Sign waiver" }).click();
  await expect(page.getByRole("button", { name: "Waiver signed" })).toBeDisabled();

  await page.getByRole("link", { name: /Your trip|Trip/ }).click();
  await expect(page.getByText("3 of 6 trip steps complete")).toBeVisible();
});

test("guest portal remains usable on mobile", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "mobile", "mobile project only");
  await page.goto(`${guestPortal}/payments`);

  await expect(page.getByRole("heading", { name: "Unambiguous payment details" })).toBeVisible();
  await expect(page.getByRole("navigation", { name: "Guest portal navigation" })).toBeVisible();
  await page.getByRole("link", { name: /Feedback/ }).click();
  await expect(page.getByRole("heading", { name: "How did Patagonia feel?" })).toBeVisible();
});

test("invalid private links reveal no reservation lookup", async ({ page }) => {
  await page.goto("/guest/g_expired-or-invalid-token");

  await expect(page.getByRole("heading", { name: "This private link is unavailable" })).toBeVisible();
  await expect(page.getByText(/cannot be recovered by surname/)).toBeVisible();
  await expect(page.locator('meta[name="robots"]').first()).toHaveAttribute("content", /noindex/);
});
