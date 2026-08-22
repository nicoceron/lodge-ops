import { defineConfig, devices } from "@playwright/test";

if (process.env.DIRECT_BOOKING_UI_ALLOW_FIXTURES === "true") {
  throw new Error("DIRECT_BOOKING_UI_ALLOW_FIXTURES must be false for the direct-booking browser suite");
}
process.env.DIRECT_BOOKING_UI_ALLOW_FIXTURES = "false";

const apiPort = Number(process.env.DIRECT_BOOKING_UI_PORT ?? 8000);
const baseURL = process.env.DIRECT_BOOKING_UI_BASE_URL ?? `http://localhost:${apiPort}`;

export default defineConfig({
  testDir: "./e2e-direct-booking",
  outputDir: "../../docs/evidence/p3-07b-direct-booking-public-ux/playwright",
  fullyParallel: false,
  workers: 1,
  reporter: "line",
  use: {
    baseURL,
    trace: "off",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
    { name: "mobile", use: { ...devices["Pixel 7"], viewport: { width: 390, height: 844 } } },
  ],
});
