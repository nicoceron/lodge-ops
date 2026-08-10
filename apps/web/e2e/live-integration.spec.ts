import { expect, test } from "@playwright/test";

const liveBaseUrl = process.env.LIVE_BASE_URL ?? "http://localhost:3000";

test.describe("live Laravel integration", () => {
  test.skip(process.env.LIVE_E2E !== "true", "requires the seeded Docker Compose stack");

  test("staff signs in and receives the persisted tenant dashboard", async ({ page }) => {
    await page.goto(`${liveBaseUrl}/login`);
    await page.getByLabel("Email address").fill("admin@example.com");
    await page.getByLabel("Password", { exact: true }).fill("password");
    await page.getByRole("button", { name: "Sign in securely" }).click();

    await expect(page).toHaveURL(/\/$/);
    await expect.poll(async () => (await page.context().cookies()).map((cookie) => cookie.name))
      .toEqual(expect.arrayContaining(["lodgeops-session", "lodgeops_tenant_id"]));
    const session = await page.request.get("http://localhost:8000/api/v1/auth/me", {
      headers: { Accept: "application/json", Origin: liveBaseUrl, Referer: `${liveBaseUrl}/` },
    });
    expect(session.ok()).toBe(true);
    await expect(page.getByRole("heading", { name: "Operations overview" })).toBeVisible();
    await expect(page.getByText("Overview unavailable")).toHaveCount(0);
  });

  test("guest exchanges the seeded one-time link for a persisted portal session", async ({ page }) => {
    await page.goto(`${liveBaseUrl}/guest/access/g_7JvK2pQ9xR4mN8tW3cD6hF1sB5yE0uA`);
    await page.getByRole("button", { name: "Open my stay" }).click();

    await expect(page).toHaveURL(/\/guest\/stay$/);
    await expect(page.getByText("RSV-DEMO-001")).toBeVisible();
    await expect(page.getByText("Private access unavailable")).toHaveCount(0);
  });
});
