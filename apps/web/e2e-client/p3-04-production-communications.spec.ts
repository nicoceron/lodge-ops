import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test } from "@playwright/test";
import { signIn } from "./helpers/auth";

const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

type ScheduleFixture = {
  origin: "deterministic_test_fixture";
  arrival_reservation_id: string;
  survey_reservation_id: string;
  guest_email: string;
  guest_token: string;
};

type ProviderFixture = {
  origin: "deterministic_test_fixture";
  endpoint_key: string;
  provider_message_id: string;
  communication_id: string;
  subject: string;
  recipient: string;
};

type SignedFixture = {
  origin: "deterministic_test_fixture";
  svix_id: string;
  svix_timestamp: string;
  svix_signature: string;
};

type ProviderStatusFixture = {
  origin: "deterministic_test_fixture";
  communication_id: string;
  status: string;
};

type MailpitMessage = {
  Subject: string;
  To: Array<{ Address: string }>;
};

function composeArgs(): string[] {
  return process.env.INN_COMPOSE_PROJECT
    ? ["compose", "-p", process.env.INN_COMPOSE_PROJECT]
    : ["compose"];
}

function runArtisan(...arguments_: string[]): void {
  execFileSync(
    "docker",
    [...composeArgs(), "exec", "-T", "api", "php", "artisan", ...arguments_],
    { cwd: path.resolve(process.cwd(), "../.."), stdio: "inherit" },
  );
}

function runArtisanJson<T>(...arguments_: string[]): T {
  const output = execFileSync(
    "docker",
    [...composeArgs(), "exec", "-T", "api", "php", "artisan", ...arguments_],
    { cwd: path.resolve(process.cwd(), "../.."), encoding: "utf8" },
  );
  const json = output.trim().split("\n").at(-1);
  if (!json) throw new Error(`Artisan command returned no JSON: ${arguments_.join(" ")}`);
  return JSON.parse(json) as T;
}

async function waitForReservationCommunication(
  page: Parameters<typeof signIn>[0],
  reservationId: string,
  subject: string,
): Promise<void> {
  await page.goto(`/manage/workspace/demo-lodge/reservations/${reservationId}`);
  await page.getByRole("tab", { name: "Communications" }).click();
  const row = page.getByRole("row").filter({ hasText: subject });
  await expect(row).toHaveCount(1, { timeout: 90_000 });
}

async function assertProviderStatus(
  page: Parameters<typeof signIn>[0],
  run: string,
  subject: string,
  status: RegExp,
  rawStatus: string,
): Promise<void> {
  const fixture = runArtisanJson<ProviderStatusFixture>(
    "uat:prepare-communication-journey",
    run,
    "--provider",
    `--await-status=${rawStatus}`,
  );
  expect(fixture.origin).toBe("deterministic_test_fixture");
  expect(fixture.status).toBe(rawStatus);
  await page.goto("/manage/workspace/demo-lodge");
  await page.goto("/manage/workspace/demo-lodge/communications");
  await expect(page.getByRole("row").filter({ hasText: subject }).first()).toContainText(status);
}

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test("P3-04 executes scheduled, preference, signed-event, suppression, and marked-test communication paths", async ({ browser, page, request, baseURL }) => {
  test.setTimeout(300_000);
  if (!baseURL) throw new Error("P3-04 requires an API base URL.");
  await page.goto("/manage/workspace/demo-lodge/communications");
  await expect(page.locator("main")).toBeVisible();

  await page.getByRole("button", { name: /authorized test send/i }).click();
  const dialog = page.getByRole("dialog", { name: /authorized test send/i });
  await dialog.getByLabel(/property/i).selectOption({ index: 1 });
  await dialog.getByLabel(/recipient/i).fill("p3-04-uat@example.test");
  await dialog.getByLabel(/subject/i).fill("Production communications UAT");
  await dialog.getByLabel(/body/i).fill("Deterministic marked test-send from the ordinary worker journey.");
  await dialog.getByRole("button", { name: /submit/i }).click();

  await expect(page.getByText("Marked test message queued")).toBeVisible();
  const row = page.getByRole("row").filter({ hasText: "[TEST] Production communications UAT" }).first();
  await expect(row).toBeVisible();
  await row.getByRole("button", { name: /preview/i }).click();
  await expect(page.getByText("TEST MESSAGE — NOT A GUEST COMMUNICATION", { exact: false })).toBeVisible();

  const mailpit = process.env.INN_MAILPIT_URL ?? "http://127.0.0.1:8025";
  await expect.poll(async () => {
    const response = await request.get(`${mailpit}/api/v1/messages`);
    const payload = await response.json();
    return JSON.stringify(payload).includes("[TEST] Production communications UAT");
  }, { timeout: 90_000 }).toBeTruthy();

  // Mailpit receipt is send-path evidence only; it does not establish provider delivery truth.
  await page.goto("/manage/workspace/demo-lodge/communications");
  await expect(page.getByRole("row").filter({ hasText: "[TEST] Production communications UAT" }).first()).not.toContainText("delivered");

  const run = Date.now().toString();
  const schedule = runArtisanJson<ScheduleFixture>("uat:prepare-communication-journey", run);
  expect(schedule.origin).toBe("deterministic_test_fixture");
  for (let attempt = 0; attempt < 3; attempt += 1) {
    runArtisan("reservation-milestones:dispatch", "--batch=100");
    await page.waitForTimeout(500);
    runArtisan("outbox:publish", "--batch=100");
    await page.waitForTimeout(750);
  }

  await expect.poll(async () => {
    const response = await request.get(`${mailpit}/api/v1/messages`);
    const payload = await response.json() as { messages: MailpitMessage[] };
    return payload.messages.filter((message) =>
      ["Arrival instructions", "Thank you and survey"].includes(message.Subject)
      && message.To.some((recipient) => recipient.Address === schedule.guest_email)
    ).map((message) => message.Subject).sort();
  }, { timeout: 90_000 }).toEqual(["Arrival instructions", "Thank you and survey"]);
  await waitForReservationCommunication(page, schedule.arrival_reservation_id, "Arrival instructions");
  await waitForReservationCommunication(page, schedule.survey_reservation_id, "Thank you and survey");

  const guestContext = await browser.newContext({ baseURL, storageState: EMPTY_STORAGE_STATE });
  try {
    const guestPage = await guestContext.newPage();
    await guestPage.goto(`/guest/access/${schedule.guest_token}`);
    await guestPage.getByRole("navigation", { name: "Guest portal" }).getByRole("link", { name: "Messages", exact: true }).click();
    const surveyPreference = guestPage.getByLabel("Post-stay feedback invitations");
    await expect(surveyPreference).toBeChecked();
    await surveyPreference.uncheck();
    await guestPage.getByRole("button", { name: "Save preferences" }).click();
    await expect(guestPage.getByText("Communication preferences saved.")).toBeVisible();
    await guestPage.reload();
    await expect(surveyPreference).not.toBeChecked();
  } finally {
    await guestContext.close();
  }

  const provider = runArtisanJson<ProviderFixture>("uat:prepare-communication-journey", run, "--provider");
  expect(provider.origin).toBe("deterministic_test_fixture");
  const postSignedFixture = async (type: string, suffix: string, data: Record<string, unknown> = {}) => {
    const rawBody = JSON.stringify({
      type,
      created_at: new Date().toISOString(),
      data: { email_id: provider.provider_message_id, to: [provider.recipient], ...data },
    });
    const eventId = `test-origin-${run}-${suffix}`;
    const signed = runArtisanJson<SignedFixture>(
      "uat:sign-communication-event",
      eventId,
      Buffer.from(rawBody).toString("base64"),
    );
    expect(signed.origin).toBe("deterministic_test_fixture");
    const response = await request.post(`/api/v1/communication-webhooks/${provider.endpoint_key}`, {
      data: rawBody,
      headers: {
        "content-type": "application/json",
        "svix-id": signed.svix_id,
        "svix-timestamp": signed.svix_timestamp,
        "svix-signature": signed.svix_signature,
      },
    });
    expect(response.status(), await response.text()).toBe(202);
  };

  // These are authenticated deterministic test-origin fixtures, not provider-origin delivery evidence.
  await postSignedFixture("email.delivered", "delivered");
  await assertProviderStatus(page, run, provider.subject, /Delivered/i, "delivered");
  await postSignedFixture("email.bounced", "hard-bounce", { bounce: { type: "permanent" } });
  await assertProviderStatus(page, run, provider.subject, /hard_bounced/i, "hard_bounced");
  await postSignedFixture("email.complained", "complaint");
  await assertProviderStatus(page, run, provider.subject, /Complained/i, "complained");

  const complainedRow = page.getByRole("row").filter({ hasText: provider.subject }).filter({ hasText: /Complained/i }).first();
  await complainedRow.getByRole("button", { name: "New resend", exact: true }).click();
  const resendDialog = page.getByRole("alertdialog", { name: "New resend" });
  await resendDialog.getByRole("button", { name: "Confirm", exact: true }).click();
  await assertProviderStatus(page, run, provider.subject, /Suppressed/i, "suppressed");
  const fixtureRows = page.getByRole("row").filter({ hasText: provider.subject });
  await expect(fixtureRows.filter({ hasText: /Complained/i })).toHaveCount(1);
  await expect(fixtureRows.filter({ hasText: /Suppressed/i })).toHaveCount(1);
});
