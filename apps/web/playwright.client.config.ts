import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e-client",
  globalSetup: "./e2e-client/global-setup.ts",
  fullyParallel: false,
  workers: 1,
  reporter: "line",
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.INN_API_URL ?? "http://127.0.0.1:8000",
    storageState: process.env.INN_PLAYWRIGHT_AUTH_STATE ?? "/tmp/inn-playwright-client-auth.json",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
