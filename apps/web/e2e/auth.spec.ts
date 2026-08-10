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

test("staff can reach the non-enumerating password recovery journey", async ({ page }) => {
  await page.goto("/login");
  await page.getByRole("link", { name: "Forgot your password?" }).click();
  await expect(page.getByRole("heading", { name: "Reset your password" })).toBeVisible();
  await expect(page.getByLabel("Email address")).toBeVisible();

  await page.goto("/reset-password?token=opaque-reset-token&email=owner%40example.com");
  await expect(page.getByRole("heading", { name: "Choose a new password" })).toBeVisible();
  await expect(page.getByText("owner@example.com")).toBeVisible();
  await expect(page.getByLabel("New password", { exact: true })).toHaveAttribute("minlength", "12");
});
