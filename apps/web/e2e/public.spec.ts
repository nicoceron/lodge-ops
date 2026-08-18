import { expect, test } from "@playwright/test";

test("public site links directly to the Filament application", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { name: /Run the whole lodge/i })).toBeVisible();
  await expect(page.locator('a[href="http://localhost:8000/manage/login"]').first()).toHaveAttribute("href", "http://localhost:8000/manage/login");
  await expect(page.getByRole("link", { name: "Open Inn", exact: true }).first()).toHaveAttribute("href", "http://localhost:8000/manage");
});

test("public navigation contains marketing pages and no protected workspace", async ({ page }) => {
  await page.goto("/features");
  await expect(page.getByRole("heading", { name: /Every workflow around the stay/i })).toBeVisible();
  await page.getByRole("link", { name: "Pricing", exact: true }).first().click();
  await expect(page).toHaveURL(/\/pricing$/);
  await expect(page.getByRole("heading", { name: /Software should simplify/i })).toBeVisible();

  for (const route of ["/login", "/reservations", "/operations", "/finance", "/guest/stay"]) {
    const response = await page.request.get(route);
    expect(response.status(), `${route} must not be a public-site route`).toBe(404);
  }
});
