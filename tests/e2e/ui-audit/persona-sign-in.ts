import { expect, type Browser } from '@playwright/test';

const origin = 'http://127.0.0.1:8127';

/**
 * Signs a disposable fixture persona in through the supported /login form and
 * writes its storage state. Hub sessions end after an hour, so each persona
 * crawl signs in when it starts instead of reusing state from global setup.
 */
export async function signInPersona(browser: Browser, persona: string, employeeId: string, statePath: string): Promise<string> {
  const password = process.env.PROTECTED_UI_E2E_PASSWORD ?? '';
  expect(password.length >= 24, 'Persona sign-in requires the random disposable fixture password').toBe(true);
  // Inside a test, browser.newContext() inherits the config's canonical storageState;
  // an explicit empty state keeps this a real sign-in instead of an already-signed-in /login.
  const context = await browser.newContext({ serviceWorkers: 'block', storageState: { cookies: [], origins: [] } });
  try {
    const page = await context.newPage();
    page.setDefaultTimeout(30_000);
    await page.goto(`${origin}/login`);
    await page.getByLabel('Employee ID or email').fill(employeeId);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url => url.pathname !== '/login', { timeout: 15_000 });
    const landing = new URL(page.url()).pathname;
    expect(landing, `${persona} must not be held at an onboarding/password gate`).not.toMatch(/set-password|city-email|member-onboarding/);
    await context.storageState({ path: statePath });
    return landing;
  } finally {
    await context.close();
  }
}
