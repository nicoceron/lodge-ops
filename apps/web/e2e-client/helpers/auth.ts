import { expect, Page } from "@playwright/test";

export async function signIn(
  page: Page,
  email = process.env.INN_UAT_EMAIL ?? "admin@example.com",
  password = process.env.INN_UAT_PASSWORD ?? "password",
): Promise<void> {
  await page.goto("/manage/workspace/demo-lodge");
  if (page.url().includes("/manage/login")) {
    await page.getByLabel("Email address").fill(email);
    await page.locator('input[type="password"]').fill(password);
    await page.getByRole("button", { name: /sign in/i }).click();
    await expect(page).not.toHaveURL(/\/manage\/login/);
  }
  await expect(page.locator("main")).toBeVisible();
}
