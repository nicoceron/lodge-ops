import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Locator, type Page } from "@playwright/test";

const propertySlug = "estancia-viento-sur";
const mercadoPagoCheckoutUrl = /^https:\/\/[^/]*mercadopago\.com(?:\.[a-z]{2})?(?:\/|$)/;

function isoDate(daysFromToday: number): string {
  const date = new Date();
  date.setHours(12, 0, 0, 0);
  date.setDate(date.getDate() + daysFromToday);

  return date.toISOString().slice(0, 10);
}

function searchUrl(locale: "en" | "es", daysFromToday: number): string {
  const query = new URLSearchParams({
    lang: locale,
    arrival_date: isoDate(daysFromToday),
    departure_date: isoDate(daysFromToday + 2),
    adults: "2",
    children: "0",
    infants: "0",
    currency: "COP",
  });

  return `/book/${propertySlug}?${query.toString()}`;
}

async function expectNoCriticalAccessibilityFindings(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).analyze();
  const criticalOrSerious = results.violations.filter((violation) =>
    ["critical", "serious"].includes(violation.impact ?? ""),
  );
  expect(criticalOrSerious, JSON.stringify(criticalOrSerious, null, 2)).toEqual([]);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const metrics = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(metrics.scrollWidth, "booking page should not overflow horizontally").toBeLessThanOrEqual(metrics.clientWidth);
}

async function expectPrimaryActionInViewport(page: Page, locator: Locator): Promise<void> {
  await locator.scrollIntoViewIfNeeded();
  await expect(locator).toBeVisible();
  const box = await locator.boundingBox();
  const viewport = page.viewportSize();
  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  if (!box || !viewport) return;
  expect(box.x, "primary action should not be clipped on the left").toBeGreaterThanOrEqual(0);
  expect(box.x + box.width, "primary action should not be clipped on the right").toBeLessThanOrEqual(viewport.width);
}

test("real API search, quote, hold, hosted handoff, pending return, and refresh", async ({ page }, testInfo) => {
  const browserRequests: string[] = [];
  let holdRequestCount = 0;
  page.on("request", (request) => browserRequests.push(request.url()));
  page.on("request", (request) => {
    if (request.method() === "POST" && request.url().endsWith("/hold")) holdRequestCount += 1;
  });

  const projectOffset = testInfo.project.name === "mobile" ? 2 : 0;
  const dateOffset = 700 + Math.floor(Math.random() * 365) + projectOffset;
  await page.goto(searchUrl("en", dateOffset));
  await expect(page.getByRole("heading", { name: "Available options" })).toBeVisible();
  await expectNoHorizontalOverflow(page);
  const option = page.locator('input[name="option_key"]:not(:disabled)').first();
  await expect(option).toBeVisible();
  await option.check();
  const reviewButton = page.getByRole("button", { name: "Review this stay" });
  await expectPrimaryActionInViewport(page, reviewButton);
  await expect(reviewButton).toBeEnabled();
  await expect(page.locator("input[name=card_number], input[name=pan], input[name=cvv], input[name=expiry]")).toHaveCount(0);
  await expectNoCriticalAccessibilityFindings(page);

  await reviewButton.click();
  await expect(page).toHaveURL(/\/book\/estancia-viento-sur\/orders\/[0-9A-HJKMNP-TV-Z]{26}\/review$/);
  await expect(page.getByRole("heading", { name: "Your server-priced quote" })).toBeVisible();
  await expect(page.getByText("COP", { exact: false }).first()).toBeVisible();
  await expect(page.getByText("Deposit due to continue").first()).toBeVisible();
  await expect(page.getByText("Policies and consent")).toBeVisible();
  await expect(page.locator("#policy-cancellation summary")).toContainText("cancellation policy");
  await expectNoHorizontalOverflow(page);
  const reviewUrl = page.url();
  const reference = reviewUrl.match(/orders\/([0-9A-HJKMNP-TV-Z]{26})\/review$/)?.[1];
  expect(reference).toBeTruthy();

  const holdButton = page.getByRole("button", { name: "Hold this stay and continue to payment" });
  await expectPrimaryActionInViewport(page, holdButton);
  await holdButton.click();
  await expect(page.getByLabel("First name")).toBeFocused();
  await expect(page.getByRole("heading", { name: "Your server-priced quote" })).toBeVisible();

  await page.getByLabel("First name").fill("Browser");
  await page.getByLabel("Email").fill(`booking-ui-${testInfo.project.name}-${Date.now()}@example.test`);
  await page.locator('input[type="checkbox"][required]').evaluateAll((checkboxes) =>
    checkboxes.forEach((checkbox) => {
      (checkbox as HTMLInputElement).checked = true;
      checkbox.dispatchEvent(new Event("input", { bubbles: true }));
      checkbox.dispatchEvent(new Event("change", { bubbles: true }));
    }),
  );
  await expectPrimaryActionInViewport(page, holdButton);
  await expect(holdButton).toBeEnabled();

  const firstHoldSubmit = page.waitForURL(new RegExp(`/book/${propertySlug}/orders/${reference}/status$`));
  await holdButton.dblclick();
  await firstHoldSubmit;
  expect(holdRequestCount).toBe(1);
  await expect(page.getByRole("heading", { name: "Stay held temporarily" })).toBeVisible();
  await expect(page.getByText("Not paid")).toBeVisible();
  await expect(page.getByText("Policies for this booking")).toBeVisible();
  await expect(page.getByText("cancellation policy", { exact: true })).toBeVisible();
  const payButton = page.getByRole("button", { name: "Continue with selected payment method" });
  await expectPrimaryActionInViewport(page, payButton);
  await expect(payButton).toBeEnabled();
  await expect(page.locator("input[name=card_number], input[name=pan], input[name=cvv], input[name=expiry]")).toHaveCount(0);

  const hostedMethod = page.getByLabel(/Pay securely with Mercado Pago/);
  await expect(hostedMethod).toBeChecked();
  const checkoutRequestPromise = page.waitForRequest(
    (request) => request.method() === "POST" && request.url().endsWith("/checkout"),
  );
  const hostedCheckoutRequestPromise = page.waitForRequest((request) => mercadoPagoCheckoutUrl.test(request.url()));
  await payButton.click({ noWaitAfter: true });
  await checkoutRequestPromise;
  const hostedCheckoutRequest = await hostedCheckoutRequestPromise;
  expect(hostedCheckoutRequest.url()).toMatch(mercadoPagoCheckoutUrl);

  const pendingUrl = `/book/${propertySlug}/orders/${reference}/status?status=approved&payment_id=provider-only`;
  await page.goto(pendingUrl);
  await expect(page.getByRole("heading", { name: "Payment processing" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Reservation confirmed" })).toHaveCount(0);
  await expect(page.getByText("Status is checked with Inn, not inferred from the browser return.")).toBeVisible();
  await expectNoHorizontalOverflow(page);
  await expectPrimaryActionInViewport(page, page.getByRole("link", { name: "Check status now" }));
  const pollRequest = page.waitForRequest((request) => request.url().includes(`/book/${propertySlug}/orders/${reference}/poll`));
  await pollRequest;
  await expect(page.getByRole("heading", { name: "Payment processing" })).toBeVisible();
  await page.reload();
  await expect(page).toHaveURL(new RegExp(`/book/${propertySlug}/orders/${reference}/status\\?status=approved`));
  await expect(page.getByRole("heading", { name: "Payment processing" })).toBeVisible();

  expect(browserRequests.some((url) => url.includes("8096") || url.includes("mock-router.php") || url.includes("fixture_state"))).toBe(false);
  await expectNoCriticalAccessibilityFindings(page);
});

test("real API Spanish chrome stays separate from the API locale", async ({ page }) => {
  await page.goto(searchUrl("es", 600 + Math.floor(Math.random() * 100)));
  await expect(page.locator("html")).toHaveAttribute("lang", "es");
  await expect(page.getByRole("heading", { name: "Opciones disponibles" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Consultar disponibilidad" })).toBeVisible();
  await expectNoHorizontalOverflow(page);
  const option = page.locator('input[name="option_key"]:not(:disabled)').first();
  await option.check();
  const spanishReviewButton = page.getByRole("button", { name: "Revisar esta estadía" });
  await expectPrimaryActionInViewport(page, spanishReviewButton);
  await expect(spanishReviewButton).toBeEnabled();
  await expect(page.locator("input[name=card_number], input[name=pan], input[name=cvv], input[name=expiry]")).toHaveCount(0);
  await expectNoCriticalAccessibilityFindings(page);
});

test("not-ready properties fail closed without quote or hold controls", async ({ page }) => {
  await page.goto("/book/not-ready-lodge");

  await expect(page.getByRole("heading", { name: "Direct booking unavailable" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Check availability" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Review this stay" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Hold this stay and continue to payment" })).toHaveCount(0);
  await expect(page.locator("input[name=card_number], input[name=pan], input[name=cvv], input[name=expiry]")).toHaveCount(0);
  await expectNoCriticalAccessibilityFindings(page);
});

test("unavailable dates show a closed results state without hold or checkout controls", async ({ page }) => {
  await page.goto(searchUrl("en", 1200) + "&adults=50");

  await expect(page.getByRole("heading", { name: "Available options" })).toBeVisible();
  await expect(page.getByText("No published option is available for these dates")).toBeVisible();
  await expect(page.getByRole("button", { name: "Review this stay" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Continue with selected payment method" })).toHaveCount(0);
  await expect(page.locator("input[name=card_number], input[name=pan], input[name=cvv], input[name=expiry]")).toHaveCount(0);
  await expectNoCriticalAccessibilityFindings(page);
});
