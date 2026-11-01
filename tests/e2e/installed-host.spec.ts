import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { APEX_ORIGIN, PRIMARY_ORIGIN } from './installed-host.setup';

/**
 * Devices are installed on both www.mbfdhub.com and mbfdhub.com. A window installed on the apex must keep every
 * page on that host; crossing to www leaves the app's scope and opens Safari or Chrome. Browser tabs behave as before.
 *
 * "Installed" is simulated through navigator.standalone, the signal iOS gives. This proves host, session and storage
 * behavior in Chromium; it does not prove what an iPhone Home Screen app shows on screen.
 *
 * Run: npx playwright test --config=playwright.installed-host.config.ts
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID ?? 'E215';
const password = process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1';
const SAVED_WORK = '[data-testid="daily-saved-work-elsewhere"]';

const asInstalledApp = async (context: BrowserContext): Promise<void> => {
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'standalone', { configurable: true, get: () => true });
  });
};

const signIn = async (page: Page, origin: string): Promise<void> => {
  await page.goto(`${origin}/login`);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 30_000 });
};

const seedPrimaryStorage = async (page: Page): Promise<void> => {
  await page.goto(`${PRIMARY_ORIGIN}/daily/stations`);
  await page.getByTestId('daily-workspace').waitFor();
  await page.waitForFunction(async () => (await indexedDB.databases()).some((entry) => entry.name === 'mbfd-daily-checkout'));
  // Seed from a page with no Daily app running: its queue processor would act on a record it finds.
  await page.goto(`${PRIMARY_ORIGIN}/daily-saved-work-probe`);
  await page.evaluate(() => new Promise<void>((resolve, reject) => {
    const open = indexedDB.open('mbfd-daily-checkout');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const database = open.result;
      const transaction = database.transaction(['dailyCheckoutDrafts', 'dailyCheckoutSubmissions'], 'readwrite');
      transaction.objectStore('dailyCheckoutDrafts').put({ key: 'seed-recent', apparatusSlug: 'e1', timestamp: Date.now() });
      transaction.objectStore('dailyCheckoutDrafts').put({ key: 'seed-abandoned', apparatusSlug: 'e2', timestamp: Date.now() - 40 * 86_400_000 });
      const now = new Date();
      transaction.objectStore('dailyCheckoutSubmissions').put({ id: 'seed-pending', apparatusId: 1, checklistVersion: 'seed', status: 'pending', createdAt: now, updatedAt: now });
      transaction.objectStore('dailyCheckoutSubmissions').put({ id: 'seed-parked', apparatusId: 2, checklistVersion: 'seed', status: 'requires_attention', createdAt: now, updatedAt: now });
      transaction.oncomplete = () => { database.close(); resolve(); };
      transaction.onerror = () => reject(transaction.error);
    };
  }));
};

const clearPrimaryStorage = async (page: Page): Promise<void> => {
  await page.goto(`${PRIMARY_ORIGIN}/daily-saved-work-probe`);
  await page.evaluate(() => new Promise<void>((resolve, reject) => {
    const open = indexedDB.open('mbfd-daily-checkout');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const database = open.result;
      const transaction = database.transaction(['dailyCheckoutDrafts', 'dailyCheckoutSubmissions'], 'readwrite');
      transaction.objectStore('dailyCheckoutDrafts').clear();
      transaction.objectStore('dailyCheckoutSubmissions').clear();
      transaction.oncomplete = () => { database.close(); resolve(); };
      transaction.onerror = () => reject(transaction.error);
    };
  }));
};

test('a browser tab on the apex still goes to the primary host', async ({ page }) => {
  await signIn(page, PRIMARY_ORIGIN);
  await page.goto(`${APEX_ORIGIN}/daily/stations`);
  await expect(page).toHaveURL(`${PRIMARY_ORIGIN}/daily/stations`);
});

test('an installed window on the apex stays there, with a working session and its own worker', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, APEX_ORIGIN);
  expect(new URL(page.url()).origin, 'sign-in stays on the installed host').toBe(APEX_ORIGIN);
  expect((await context.cookies(APEX_ORIGIN)).find((cookie) => cookie.name === 'mbfd_installed_host')?.domain, 'host-only').toBe('mbfd.localhost');
  expect(await page.evaluate(() => document.cookie), 'set on this host').toContain('mbfd_installed_host=1');
  // Ask the browser itself: Playwright's cookies(url) filter also matches a host-only cookie on subdomains.
  await page.goto(`${PRIMARY_ORIGIN}/login`);
  expect(await page.evaluate(() => document.cookie), 'never reaches www').not.toContain('mbfd_installed_host');

  const memberContext = page.waitForResponse((response) => response.url().includes('/api/me/context'));
  await page.goto(`${APEX_ORIGIN}/daily/stations`);
  await expect(page).toHaveURL(`${APEX_ORIGIN}/daily/stations`);
  await page.getByTestId('daily-workspace').waitFor();
  expect((await memberContext).status(), 'the API accepts the session from the apex').toBe(200);

  const registrations = await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
    return (await navigator.serviceWorker.getRegistrations()).map((registration) => ({
      scope: registration.scope,
      script: (registration.active ?? registration.waiting ?? registration.installing)?.scriptURL,
    }));
  });
  expect(registrations, 'one plain registration, same URL and scope as everywhere').toEqual([
    { scope: `${APEX_ORIGIN}/daily/`, script: `${APEX_ORIGIN}/daily/sw.js` },
  ]);

  await page.goto(`${APEX_ORIGIN}/daily-roster`);
  expect(new URL(page.url()).origin, 'Daily Roster answers on this host too').toBe(APEX_ORIGIN);
});

test('an install that opens straight into Daily on the apex stays there, with no Hub page visited first', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, PRIMARY_ORIGIN);
  await context.clearCookies({ name: 'mbfd_installed_host' });
  await page.goto(`${APEX_ORIGIN}/daily/stations?from=shortcut`);
  await expect(page).toHaveURL(`${APEX_ORIGIN}/daily/stations?from=shortcut`);
  await page.getByTestId('daily-workspace').waitFor();
  expect(await page.evaluate(() => document.cookie)).toContain('mbfd_installed_host=1');
});

test('a browser tab opening Daily on the apex lands on the primary host with its path and query', async ({ page }) => {
  await signIn(page, PRIMARY_ORIGIN);
  await page.goto(`${APEX_ORIGIN}/daily/stations?from=bookmark`);
  await expect(page).toHaveURL(`${PRIMARY_ORIGIN}/daily/stations?from=bookmark`);
  expect(await page.evaluate(() => document.cookie)).not.toContain('mbfd_installed_host');
});

test('a link written with the other host is followed on this host', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, APEX_ORIGIN);
  await page.goto(`${APEX_ORIGIN}/account`);
  await page.evaluate((primary) => {
    const link = document.createElement('a');
    link.id = 'stored-link';
    link.href = `${primary}/updates?from=notification#top`;
    link.textContent = 'Stored notification link';
    document.body.prepend(link);
  }, PRIMARY_ORIGIN);
  await page.locator('#stored-link').click();
  await expect(page).toHaveURL(`${APEX_ORIGIN}/updates?from=notification#top`);
});

test('a window on the primary host is not affected', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, PRIMARY_ORIGIN);
  await page.goto(`${PRIMARY_ORIGIN}/daily/stations`);
  await page.getByTestId('daily-workspace').waitFor();
  await expect(page).toHaveURL(`${PRIMARY_ORIGIN}/daily/stations`);
  await expect(page.locator(SAVED_WORK)).toHaveCount(0);
  expect(await page.evaluate(() => document.cookie)).not.toContain('mbfd_installed_host');
});

test('unsent work left on the primary host is found, counted and reachable, and nothing is moved or deleted', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, APEX_ORIGIN);
  await seedPrimaryStorage(page);

  await page.goto(`${APEX_ORIGIN}/daily/stations`);
  const notice = page.locator(SAVED_WORK);
  await expect(notice, 'the recent draft and the queued inspection; not the abandoned draft or the parked record').toContainText('2 saved Daily Checkout items');
  await expect(page.locator('iframe[src*="daily-saved-work-probe"]'), 'the frame is gone once it answered').toHaveCount(0);
  await notice.getByRole('link', { name: 'Open saved work' }).click();
  await expect(page, 'this one link really does cross to where the work is').toHaveURL(`${PRIMARY_ORIGIN}/daily/stations`);

  await page.goto(`${PRIMARY_ORIGIN}/daily-saved-work-probe`);
  const kept = await page.evaluate(() => new Promise<number>((resolve, reject) => {
    const open = indexedDB.open('mbfd-daily-checkout');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const count = open.result.transaction('dailyCheckoutDrafts').objectStore('dailyCheckoutDrafts').count();
      count.onsuccess = () => { open.result.close(); resolve(count.result); };
    };
  }));
  expect(kept, 'the probe changed nothing').toBe(2);

  await clearPrimaryStorage(page);
  await page.goto(`${APEX_ORIGIN}/daily/stations`);
  await page.getByTestId('daily-workspace').waitFor();
  await page.waitForFunction(() => document.querySelector('iframe[src*="daily-saved-work-probe"]') === null);
  await expect(page.locator(SAVED_WORK), 'no notice once it is sent').toHaveCount(0);
});

test('a dismissed notice stays away for the session', async ({ page, context }) => {
  await asInstalledApp(context);
  await signIn(page, APEX_ORIGIN);
  await seedPrimaryStorage(page);
  await page.goto(`${APEX_ORIGIN}/daily/stations`);
  await page.locator(SAVED_WORK).getByRole('button', { name: 'Not now' }).click();
  await expect(page.locator(SAVED_WORK)).toHaveCount(0);
  await page.reload();
  await page.getByTestId('daily-workspace').waitFor();
  await expect(page.locator(SAVED_WORK)).toHaveCount(0);
});
