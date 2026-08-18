import { chromium, FullConfig } from "@playwright/test";
import { signIn } from "./helpers/auth";

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = config.projects[0]?.use.baseURL as string | undefined;
  const storageStatePath = process.env.INN_PLAYWRIGHT_AUTH_STATE ?? "/tmp/inn-playwright-client-auth.json";
  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });

  try {
    await signIn(page);
    await page.context().storageState({ path: storageStatePath });
  } finally {
    await browser.close();
  }
}
