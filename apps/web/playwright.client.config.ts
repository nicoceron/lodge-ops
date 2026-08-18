import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e-client",
  fullyParallel: false,
  reporter: "line",
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.INN_API_URL ?? "http://127.0.0.1:8000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
