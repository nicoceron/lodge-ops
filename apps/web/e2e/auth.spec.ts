import { expect, test } from "@playwright/test";

test("staff sign-in is accessible and does not expose the password", async ({ page }) => {
  await page.goto("/login");

  await expect(page.getByRole("heading", { name: "Sign in to your lodge" })).toBeVisible();
  await expect(page.getByLabel("Email address")).toBeVisible();
  const password = page.getByLabel("Password", { exact: true });
  await expect(password).toHaveAttribute("type", "password");

  await page.getByRole("button", { name: "Show password" }).click();
  await expect(password).toHaveAttribute("type", "text");
  await expect(page.getByRole("button", { name: "Sign in securely" })).toBeEnabled();
});
