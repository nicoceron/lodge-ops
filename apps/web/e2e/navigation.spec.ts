import { expect, test } from "@playwright/test";

test("moves from the operating overview to the master calendar", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Good morning, Nico" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Today's arrivals" })).toBeVisible();

  await page.getByRole("link", { name: "Master calendar" }).first().click();

  await expect(page).toHaveURL(/\/calendar$/);
  await expect(page.getByRole("heading", { name: "Master calendar" })).toBeVisible();
  await expect(page.getByText("No hard conflicts")).toBeVisible();
});

test("keeps primary workflows available on a mobile viewport", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "mobile", "mobile project only");
  await page.goto("/");

  await expect(page.getByRole("navigation", { name: "Mobile navigation" })).toBeVisible();
  await page.getByRole("link", { name: "Operations" }).click();
  await expect(page.getByRole("heading", { name: "Operations" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Kitchen brief" })).toBeVisible();
});
