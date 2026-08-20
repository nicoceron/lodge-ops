import { expect, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test("UAT-4.1 calendar and operational workspaces render in an authenticated session", async ({ page }) => {
  for (const route of ["master-calendar", "operations-board", "kitchen-dashboard", "finance-dashboard"]) {
    const response = await page.goto(`/manage/workspace/demo-lodge/${route}`);
    expect(response?.ok(), route).toBeTruthy();
    const main = page.locator("main");
    await expect(main).toBeVisible();
    await expect(main).not.toContainText(/server error|exception|trace/i);
  }
});

test("UAT-4.3 staff reservation composer starts with availability and a server price", async ({ page }) => {
  await page.goto("/manage/workspace/demo-lodge/reservations/create");
  await expect(page.getByText("Search live availability", { exact: false })).toBeVisible();
  await expect(page.getByText("Server-priced quote", { exact: false })).toBeVisible();
  await expect(page.getByText("Guest and reservation details", { exact: false })).toBeVisible();
  await expect(page.getByText("Subtotal (minor units)")).toHaveCount(0);
});

test("UAT-4.5 finance can reach transfer review and configured payment policies", async ({ page }) => {
  for (const route of ["payment-evidence", "deposit-policies", "cancellation-policies", "rate-plans", "tax-rules"]) {
    const response = await page.goto(`/manage/workspace/demo-lodge/${route}`);
    expect(response?.ok(), route).toBeTruthy();
    await expect(page.locator("main")).toBeVisible();
  }
});

test("UAT-4.8 through 4.10 existing tasks, folios, guest documents, and surveys remain reachable", async ({ page }) => {
  for (const route of ["operational-tasks", "folio-lines", "generated-documents", "survey-responses"]) {
    const response = await page.goto(`/manage/workspace/demo-lodge/${route}`);
    expect(response?.ok(), route).toBeTruthy();
    await expect(page.locator("main")).toBeVisible();
  }
});
