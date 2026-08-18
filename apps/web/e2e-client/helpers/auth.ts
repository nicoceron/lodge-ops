import { expect, Page } from "@playwright/test";

export async function signIn(page: Page): Promise<void> {
  await page.goto("/manage/workspace/demo-lodge");
  if (page.url().includes("/manage/login")) {
    await page.getByLabel("Email address").fill(process.env.INN_UAT_EMAIL ?? "admin@example.com");
    await page.locator('input[type="password"]').fill(process.env.INN_UAT_PASSWORD ?? "password");
    await page.getByRole("button", { name: /sign in/i }).click();
    await expect(page).not.toHaveURL(/\/manage\/login/);
  }
  await expect(page.locator("main")).toBeVisible();
}
