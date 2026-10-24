import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { readDrafts } from './support/daily-checkout-drafts';
import {
  DIST,
  FIXTURE_SLUG,
  startDailyOrigin,
  type DailyOrigin,
  type WorkerGeneration,
} from './support/daily-pwa-origin';

/**
 * Daily Checkout service-worker upgrade safety (PWA architecture Phase 2).
 *
 * A local origin serves the real built Daily app and lets each test change which worker
 * bytes /daily/sw.js answers (the exact production worker, the worker on main, or the new
 * one), the release, the signed-in member and the network, while the browser keeps its
 * registration, Cache Storage and IndexedDB. Every test asserts the release-blocking rules:
 * one registration on the plain URL and scope, no reinstall or data clearing, offline drafts
 * and queued submissions survive, and a page is never reloaded under unsaved work.
 *
 * Run: npm run test:daily-pwa-upgrade-e2e
 */

const BANNER = '#mbfd-pwa-banner';
const BUSY_MESSAGE = 'An update is ready. It will apply when you finish.';
const RELEASE_A = 'aaaaaaaaaaaa';
const RELEASE_B = 'bbbbbbbbbbbb';
const NEW_CACHE = 'mbfd-checkout-v12';
const NEW_API_CACHE = 'mbfd-api-cache-v8';
const PAGE = `/daily/vehicle-inspections/${FIXTURE_SLUG}`;
const QUEUED_ID = '11111111-2222-4333-8444-555555555555';
// The queued record is for the same apparatus but an earlier checklist revision, so it waits in the
// queue without blocking the draft the member is working on (same apparatus and version would).
const QUEUED_CHECKLIST_VERSION = 'b'.repeat(64);

interface OldWorker {
  generation: WorkerGeneration;
  cache: string;
}

const OLD_WORKERS: OldWorker[] = [
  { generation: 'live-v10', cache: 'mbfd-checkout-v10' },
  { generation: 'main-v11', cache: 'mbfd-checkout-v11' },
];

// ---------------------------------------------------------------------------
// Browser plumbing
// ---------------------------------------------------------------------------

async function newPage(browser: Browser, origin: DailyOrigin): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({
    serviceWorkers: 'allow',
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
    baseURL: origin.url,
  });
  const page = await context.newPage();
  await page.addInitScript(() => {
    // Headless tabs are always visible; let the tests flip document.hidden.
    Object.defineProperty(document, 'hidden', {
      configurable: true,
      get: () => Boolean((window as unknown as { __forceHidden?: boolean }).__forceHidden),
    });
    // Every register() call, across reloads: the Daily page must register exactly once, on the plain URL.
    const original = ServiceWorkerContainer.prototype.register;
    ServiceWorkerContainer.prototype.register = function register(this: ServiceWorkerContainer, ...args: Parameters<typeof original>) {
      try {
        const calls = JSON.parse(sessionStorage.getItem('e2e-register-calls') ?? '[]') as unknown[];
        calls.push([String(args[0]), args[1]?.scope ?? null]);
        sessionStorage.setItem('e2e-register-calls', JSON.stringify(calls));
      } catch { /* storage unavailable */ }
      return original.apply(this, args);
    };
  });

  return { context, page };
}

const registerCalls = (page: Page) => page.evaluate(() => JSON.parse(sessionStorage.getItem('e2e-register-calls') ?? '[]') as Array<[string, string | null]>);

async function waitForControl(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(async () => {
    await navigator.serviceWorker.ready;
    return navigator.serviceWorker.controller?.scriptURL.endsWith('/daily/sw.js') ?? false;
  }), { timeout: 20_000 }).toBe(true);
}

async function waitForShellCache(page: Page, cacheName: string): Promise<void> {
  await expect.poll(() => page.evaluate(async (name) => {
    const cache = await caches.open(name);
    const paths = (await cache.keys()).map((request) => new URL(request.url).pathname);
    return Boolean(await cache.match('/daily/index.html')) && paths.some((path) => path.startsWith('/daily/assets/'));
  }, cacheName), { timeout: 20_000 }).toBe(true);
}

async function install(page: Page, origin: DailyOrigin, cacheName: string, path = PAGE): Promise<void> {
  await page.goto(`${origin.url}${path}`);
  await waitForControl(page);
  await waitForShellCache(page, cacheName);
}

const cacheNames = (page: Page) => page.evaluate(async () => (await caches.keys()).sort());

/** Worker identity through the shared core's GET_VERSION; null for workers that predate it. */
const workerBuild = (page: Page) => page.evaluate(async () => {
  const registration = await navigator.serviceWorker.getRegistration('/daily/');
  const worker = registration?.active;
  if (!worker) return null;
  return new Promise<string | null>((resolve) => {
    const channel = new MessageChannel();
    const timer = setTimeout(() => resolve(null), 500);
    channel.port1.onmessage = (event) => {
      clearTimeout(timer);
      resolve(event.data?.build ?? null);
    };
    worker.postMessage({ type: 'GET_VERSION' }, [channel.port2]);
  });
});

const registrations = (page: Page) => page.evaluate(async () => {
  const all = await navigator.serviceWorker.getRegistrations();
  return all.map((registration) => ({
    scope: new URL(registration.scope).pathname,
    script: new URL((registration.active ?? registration.waiting ?? registration.installing)?.scriptURL ?? 'http://none.invalid/').pathname,
  }));
});

/** Ask the browser to check /daily/sw.js now; reports whether the check itself succeeded. */
const updateWorker = (page: Page) => page.evaluate(async () => {
  const registration = await navigator.serviceWorker.getRegistration('/daily/');
  if (!registration) return 'no registration';
  try {
    await registration.update();
    return 'ok';
  } catch (error) {
    return String(error);
  }
});

async function updateTo(page: Page, build: string): Promise<void> {
  await updateWorker(page);
  await expect.poll(() => workerBuild(page), { timeout: 20_000, message: `the worker should become ${build}` }).toBe(build);
}

// ---------------------------------------------------------------------------
// IndexedDB: drafts and the offline queue (the data that must survive everything)
// ---------------------------------------------------------------------------

async function addQueuedInspection(page: Page, ownerUserId = 101): Promise<void> {
  await page.evaluate(async ({ id, version, owner }) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const request = database.transaction('dailyCheckoutSubmissions', 'readwrite').objectStore('dailyCheckoutSubmissions').add({
      id,
      apparatusId: 101,
      checklistVersion: version,
      createdAt: new Date(),
      updatedAt: new Date(),
      status: 'pending',
      retryCount: 0,
      ownerUserId: owner,
      ownerSecurityVersion: 1,
      ownershipState: 'owned',
      data: { client_submission_id: id, checklist_version: version, operator_name: 'PWA Member', compartments: [] },
    });
    await new Promise<void>((resolve, reject) => { request.onsuccess = () => resolve(); request.onerror = () => reject(request.error); });
    database.close();
  }, { id: QUEUED_ID, version: QUEUED_CHECKLIST_VERSION, owner: ownerUserId });
}

const queuedInspections = (page: Page) => page.evaluate(async () => {
  const database = await new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open('mbfd-daily-checkout');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  const request = database.transaction('dailyCheckoutSubmissions', 'readonly').objectStore('dailyCheckoutSubmissions').getAll();
  const rows = await new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
    request.onsuccess = () => resolve(request.result as Array<Record<string, unknown>>);
    request.onerror = () => reject(request.error);
  });
  database.close();
  return rows;
});

const databases = (page: Page) => page.evaluate(async () => (await indexedDB.databases()).map((entry) => `${entry.name}@${entry.version}`).sort());

/** Open the wizard, mark one item damaged, and wait until the draft is on disk. */
async function createDraft(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: /Offline Fixture Apparatus/ })).toBeVisible({ timeout: 20_000 });
  const row = page.locator('.equipment-row').first();
  await row.locator('.equipment-expand').click();
  await row.getByRole('button', { name: 'Damaged', exact: true }).click();
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments[0]?.items[0]?.status, { timeout: 10_000 }).toBe('Damaged');
}

/** What must survive: which draft, and what was entered. (The wizard may re-save a restored draft with a newer timestamp.) */
const draftFingerprint = async (page: Page) => (await readDrafts(page)).map((draft) => ({
  key: draft.key,
  slug: draft.apparatusSlug,
  statuses: draft.data.compartments.flatMap((compartment) => compartment.items.map((item) => item.status)),
}));

const itemStatuses = async (page: Page) => (await readDrafts(page))[0]?.data.compartments[0]?.items.map((item) => item.status);

// ---------------------------------------------------------------------------
// Banner / reload probes (same contract as tests/e2e/pwa-update.spec.ts)
// ---------------------------------------------------------------------------

const registrar = (page: Page) => page.evaluate(() => {
  const api = (window as unknown as { __mbfdUpdateRegistrar?: { api: { currentRelease(): string | null; pendingRelease(): string | null } } }).__mbfdUpdateRegistrar?.api;
  return api ? { current: api.currentRelease(), pending: api.pendingRelease() } : null;
});

const checkNow = (page: Page) => page.evaluate(() => (window as unknown as { __mbfdUpdateRegistrar: { api: { checkNow(force: boolean): Promise<void> } } }).__mbfdUpdateRegistrar.api.checkNow(true));
const busyReasons = (page: Page) => page.evaluate(() => Array.from((window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy ?? []).sort());
const setHidden = (page: Page, hidden: boolean) => page.evaluate((value) => {
  (window as unknown as { __forceHidden?: boolean }).__forceHidden = value;
  document.dispatchEvent(new Event('visibilitychange'));
}, hidden);
const guardKeys = (page: Page) => page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('mbfd-reloaded-for:')).sort());

const armUnloadProbe = (page: Page) => page.evaluate(() => {
  const target = window as unknown as { __e2eUnloading?: boolean };
  target.__e2eUnloading = false;
  window.addEventListener('beforeunload', () => { target.__e2eUnloading = true; });
});
const unloadStarted = (page: Page) => page.evaluate(() => Boolean((window as unknown as { __e2eUnloading?: boolean }).__e2eUnloading)).catch(() => true);

/** Document loads of the main frame (a reload or a navigation), not single-page route changes. */
function trackNavigations(page: Page): () => number {
  let count = 0;
  page.on('load', () => { count += 1; });
  return () => count;
}

const nextLoad = (page: Page, timeout = 20_000) => page.waitForEvent('load', { timeout });

async function openAt(page: Page, origin: DailyOrigin, path = PAGE): Promise<void> {
  await page.goto(`${origin.url}${path}`);
  await expect.poll(() => registrar(page).then((value) => value?.current), { timeout: 20_000 }).toBe(origin.state.release);
}

// ---------------------------------------------------------------------------
// (a) + upgrade: the production worker becomes the new worker in place
// ---------------------------------------------------------------------------

for (const old of OLD_WORKERS) {
  test(`upgrade from the ${old.generation} production worker keeps the offline draft and the queued submission, then the queue syncs once`, async ({ browser }) => {
    const origin = await startDailyOrigin({ worker: old.generation, submitMode: 'fail' });
    const { context, page } = await newPage(browser, origin);
    try {
      await install(page, origin, old.cache);
      expect(await cacheNames(page)).toContain(old.cache);
      expect(await workerBuild(page), 'the old worker predates GET_VERSION').toBeNull();
      await createDraft(page);

      // An offline submission waits in the queue; the server keeps refusing it (503) so it stays queued.
      await context.setOffline(true);
      await addQueuedInspection(page);
      await context.setOffline(false);
      await page.evaluate(() => window.dispatchEvent(new Event('online')));
      const draftsBefore = await draftFingerprint(page);
      const queueBefore = await queuedInspections(page);
      const databasesBefore = await databases(page);
      expect(draftsBefore).toHaveLength(1);
      expect(draftsBefore[0].statuses).toContain('Damaged');
      expect(queueBefore).toHaveLength(1);
      await page.evaluate(() => { (window as unknown as { __stillHere: boolean }).__stillHere = true; });
      const registrationBefore = await registrations(page);

      // Deploy: the same URL starts answering with the new worker.
      origin.state.worker = 'current';
      await updateTo(page, NEW_CACHE);

      // One registration, same scope and plain URL, no unregister, no second register().
      expect(await registrations(page)).toEqual(registrationBefore);
      expect(registrationBefore).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
      expect(await registerCalls(page)).toEqual([['/daily/sw.js', '/daily/']]);

      // The cache contract moved on: old shell and API caches are gone, new ones exist.
      await expect.poll(() => cacheNames(page), { timeout: 15_000 }).not.toContain(old.cache);
      const names = await cacheNames(page);
      expect(names).toContain(NEW_CACHE);
      expect(names).not.toContain('mbfd-api-cache-v7');
      expect(names.filter((name) => name.startsWith('mbfd-api-cache-')).every((name) => name === NEW_API_CACHE)).toBe(true);

      // Nothing the member typed or queued was touched.
      expect(await databases(page)).toEqual(databasesBefore);
      expect(await draftFingerprint(page)).toEqual(draftsBefore);
      expect(await queuedInspections(page)).toMatchObject([{ id: QUEUED_ID, status: 'pending', ownerUserId: 101, data: { client_submission_id: QUEUED_ID } }]);
      expect(await page.evaluate(() => (window as unknown as { __stillHere?: boolean }).__stillHere), 'a worker update must not reload the page').toBe(true);

      // The app still works through the new worker, offline included.
      await context.setOffline(true);
      await page.reload();
      await expect(page.getByText(/Restored from autosave/)).toBeVisible({ timeout: 15_000 });
      expect(await queuedInspections(page)).toHaveLength(1);
      await context.setOffline(false);

      // Connectivity and an accepting server: the queued submission is sent exactly once.
      origin.state.submitMode = 'ok';
      await page.evaluate(() => window.dispatchEvent(new Event('online')));
      await expect.poll(async () => (await queuedInspections(page)).length, { timeout: 20_000 }).toBe(0);
      expect(origin.submissions).toHaveLength(1);
      expect(origin.submissions[0]).toMatchObject({ client_submission_id: QUEUED_ID });
      await page.evaluate(() => { window.dispatchEvent(new Event('online')); window.dispatchEvent(new Event('online')); });
      await page.waitForTimeout(800);
      expect(origin.submissions).toHaveLength(1);
      for (const call of await registerCalls(page)) {
        expect(call, 'each page load registers once, on the plain URL and scope').toEqual(['/daily/sw.js', '/daily/']);
      }
    } finally {
      await context.close();
      await origin.close();
    }
  });
}

// ---------------------------------------------------------------------------
// (f) rollback: the old production bytes replace the new worker; the data still works
// ---------------------------------------------------------------------------

for (const old of OLD_WORKERS) {
  test(`rollback to the ${old.generation} production worker still works with the data written by the new one`, async ({ browser }) => {
    const origin = await startDailyOrigin({ worker: 'current', submitMode: 'fail' });
    const { context, page } = await newPage(browser, origin);
    try {
      await install(page, origin, NEW_CACHE);
      await createDraft(page);
      await context.setOffline(true);
      await addQueuedInspection(page);
      await context.setOffline(false);
      const draftsBefore = await draftFingerprint(page);
      expect(await workerBuild(page)).toBe(NEW_CACHE);

      // Roll back: the previous bytes are served at the same URL.
      origin.state.worker = old.generation;
      await updateWorker(page);
      await expect.poll(() => cacheNames(page), { timeout: 20_000 }).toContain(old.cache);
      await expect.poll(() => workerBuild(page), { timeout: 20_000 }).toBeNull();
      await expect.poll(() => cacheNames(page)).not.toContain(NEW_CACHE);

      expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
      expect(await draftFingerprint(page)).toEqual(draftsBefore);
      expect(await queuedInspections(page)).toHaveLength(1);

      // Offline launch from the rolled-back worker's shell: the draft is restored.
      await waitForShellCache(page, old.cache);
      await context.setOffline(true);
      await page.reload();
      await expect(page.getByText(/Restored from autosave/)).toBeVisible({ timeout: 15_000 });
      await context.setOffline(false);

      // And the queued submission still syncs exactly once.
      origin.state.submitMode = 'ok';
      await page.evaluate(() => window.dispatchEvent(new Event('online')));
      await expect.poll(async () => (await queuedInspections(page)).length, { timeout: 20_000 }).toBe(0);
      await page.waitForTimeout(500);
      expect(origin.submissions).toHaveLength(1);
    } finally {
      await context.close();
      await origin.close();
    }
  });
}

// ---------------------------------------------------------------------------
// (b) a deploy while the app is open with unsaved work
// ---------------------------------------------------------------------------

test('a deploy while a draft has unsaved edits: banner, no reload, then exactly one reload once the draft is saved', async ({ browser }) => {
  const origin = await startDailyOrigin({ release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  const navigations = trackNavigations(page);
  try {
    await install(page, origin, NEW_CACHE);
    await openAt(page, origin);
    await createDraft(page);
    await expect.poll(() => busyReasons(page), { message: 'a saved draft is not busy', timeout: 10_000 }).toEqual([]);

    // Disk writes for the draft start failing (full storage): the next edit stays unsaved.
    await page.evaluate(() => {
      const original = IDBObjectStore.prototype.put;
      (window as unknown as { __restorePut: () => void }).__restorePut = () => { IDBObjectStore.prototype.put = original; };
      IDBObjectStore.prototype.put = function put(this: IDBObjectStore, ...args: Parameters<typeof original>) {
        if (this.name === 'dailyCheckoutDrafts') throw new DOMException('quota', 'QuotaExceededError');
        return original.apply(this, args);
      };
    });
    const rows = page.locator('.equipment-row');
    await rows.nth(1).locator('.equipment-expand').click();
    await rows.nth(1).getByRole('button', { name: 'Missing', exact: true }).click();
    await expect(page.getByText(/Changes could not be saved on this device/)).toBeVisible({ timeout: 10_000 });
    expect(await busyReasons(page)).toEqual(['unsaved-draft']);

    // A new release is deployed while the member is mid-inspection.
    origin.state.release = RELEASE_B;
    await checkNow(page);
    await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);
    const banner = page.locator(BANNER);
    await expect(banner).toContainText(BUSY_MESSAGE);
    const refresh = banner.getByRole('button', { name: 'Refresh now' });
    await expect(refresh).toHaveAttribute('aria-disabled', 'true');
    expect((await refresh.boundingBox())?.height ?? 0, 'touch target').toBeGreaterThanOrEqual(44);

    // Hidden or not, a page with unsaved edits is never reloaded, and the disabled button does nothing.
    const before = navigations();
    await armUnloadProbe(page);
    await setHidden(page, true);
    await page.waitForTimeout(1500);
    expect(await unloadStarted(page)).toBe(false);
    await refresh.click({ force: true });
    await page.waitForTimeout(400);
    expect(await unloadStarted(page)).toBe(false);
    expect(navigations()).toBe(before);

    // The edit is saved: the page is free, and exactly one reload follows.
    await page.evaluate(() => (window as unknown as { __restorePut: () => void }).__restorePut());
    const reloaded = nextLoad(page);
    await setHidden(page, false);
    await rows.nth(1).getByRole('button', { name: 'Present', exact: true }).click();
    await expect.poll(async () => (await itemStatuses(page))?.[1], { timeout: 10_000 }).toBe('Present');
    await setHidden(page, true);
    await reloaded;
    await page.waitForLoadState('load');
    expect(navigations()).toBe(before + 1);
    expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_B}`]);

    // The reloaded page is current (its shell says B) and keeps the draft.
    await expect.poll(() => registrar(page).then((value) => value?.current)).toBe(RELEASE_B);
    await expect(page.getByText(/Restored from autosave/)).toBeVisible({ timeout: 15_000 });
    const afterReload = navigations();
    await setHidden(page, true);
    await page.waitForTimeout(1500);
    expect(navigations(), 'no loop').toBe(afterReload);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('an edit is busy from the moment it is made until the draft is confirmed on disk, then free again', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await openAt(page, origin);
    await expect(page.getByRole('heading', { name: /Offline Fixture Apparatus/ })).toBeVisible({ timeout: 20_000 });

    // Record every busy-state change, so the short debounce window cannot be missed by polling.
    await page.evaluate(() => {
      const log: string[][] = [];
      (window as unknown as { __busyLog: string[][] }).__busyLog = log;
      window.addEventListener('mbfd:busy-change', (event) => log.push([...((event as CustomEvent<{ reasons: string[] }>).detail.reasons)]));
    });
    const row = page.locator('.equipment-row').first();
    await row.locator('.equipment-expand').click();
    await row.getByRole('button', { name: 'Damaged', exact: true }).click();
    await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments[0]?.items[0]?.status, { timeout: 10_000 }).toBe('Damaged');
    await expect.poll(() => busyReasons(page), { timeout: 10_000 }).toEqual([]);

    const log = await page.evaluate(() => (window as unknown as { __busyLog: string[][] }).__busyLog);
    expect(log[0], 'busy as soon as the edit is scheduled').toEqual(['unsaved-draft']);
    expect(log.at(-1), 'free once the write is confirmed').toEqual([]);
    expect(log.filter((entry) => entry.length > 0).every((entry) => entry.join() === 'unsaved-draft')).toBe(true);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('a deploy while a submission is queued: the banner waits, the queue syncs, then the update applies once', async ({ browser }) => {
  const origin = await startDailyOrigin({ release: RELEASE_A, submitMode: 'fail' });
  const { context, page } = await newPage(browser, origin);
  const navigations = trackNavigations(page);
  try {
    await install(page, origin, NEW_CACHE);
    await openAt(page, origin);
    await context.setOffline(true);
    await addQueuedInspection(page);
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect.poll(() => busyReasons(page), { timeout: 10_000 }).toEqual(['queued-submission']);

    origin.state.release = RELEASE_B;
    await checkNow(page);
    await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);
    await expect(page.locator(BANNER)).toContainText(BUSY_MESSAGE);

    const before = navigations();
    await armUnloadProbe(page);
    await setHidden(page, true);
    await page.waitForTimeout(1500);
    expect(await unloadStarted(page), 'a queued submission blocks the reload').toBe(false);

    // The server recovers; the queue drains; the busy reason clears; the update applies once.
    origin.state.submitMode = 'ok';
    const reloaded = nextLoad(page, 40_000);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    // The update applies the moment the queue drains, so a read can land in the middle of that reload.
    await reloaded;
    await page.waitForLoadState('load');
    expect(await queuedInspections(page)).toHaveLength(0);
    expect(navigations()).toBe(before + 1);
    expect(origin.submissions).toHaveLength(1);
    expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_B}`]);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('an in-memory form with typed text blocks the update until it is cleared or removed', async ({ browser }) => {
  const origin = await startDailyOrigin({ release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  const navigations = trackNavigations(page);
  try {
    await install(page, origin, NEW_CACHE, '/daily/stations');
    await openAt(page, origin, '/daily/stations');
    await expect(page.locator('#main-content')).toBeVisible({ timeout: 20_000 });

    // Station forms keep their state in memory. A plain field in the workspace stands in for them: what
    // matters is that real typing marks the page busy and that the mark releases itself.
    const addField = (id: string, placeholder = '') => page.evaluate(({ fieldId, hint }) => {
      const field = document.createElement(hint ? 'input' : 'textarea');
      field.id = fieldId;
      field.setAttribute('aria-label', hint || 'Describe the problem');
      if (hint) field.setAttribute('placeholder', hint);
      document.querySelector('#main-content')!.appendChild(field);
    }, { fieldId: id, hint: placeholder });
    await addField('e2e-request-text');
    const field = page.locator('#e2e-request-text');
    await field.click();
    await field.pressSequentially('Replace the dock light');
    await expect.poll(() => busyReasons(page)).toEqual(['unsaved-draft']);

    origin.state.release = RELEASE_B;
    await checkNow(page);
    await expect(page.locator(BANNER)).toContainText(BUSY_MESSAGE);
    const before = navigations();
    await armUnloadProbe(page);
    await setHidden(page, true);
    await page.waitForTimeout(1500);
    expect(await unloadStarted(page)).toBe(false);

    // The text is cleared (the form was reset or abandoned): the page is free and exactly one reload follows.
    const reloaded = nextLoad(page);
    await setHidden(page, false);
    await field.fill('');
    await expect.poll(() => busyReasons(page), { timeout: 10_000 }).toEqual([]);
    await setHidden(page, true);
    await reloaded;
    expect(navigations()).toBe(before + 1);

    // A form that disappears (route change, submitted and unmounted) also releases the mark.
    await page.evaluate(() => { (window as unknown as { __forceHidden?: boolean }).__forceHidden = false; });
    await addField('e2e-request-text-2');
    await page.locator('#e2e-request-text-2').click();
    await page.locator('#e2e-request-text-2').pressSequentially('Another');
    await expect.poll(() => busyReasons(page)).toEqual(['unsaved-draft']);
    await page.evaluate(() => document.querySelector('#e2e-request-text-2')!.remove());
    await expect.poll(() => busyReasons(page), { timeout: 10_000 }).toEqual([]);

    // A search box is never "unsaved work".
    await addField('e2e-filter', 'Search stations');
    await page.locator('#e2e-filter').pressSequentially('engine');
    await page.waitForTimeout(400);
    expect(await busyReasons(page)).toEqual([]);
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// (c) the network drops during an update
// ---------------------------------------------------------------------------

test('a network drop during the update check, and a failed install, leave the old worker and all data intact and are retried later', async ({ browser }) => {
  const origin = await startDailyOrigin({ worker: 'main-v11', release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, 'mbfd-checkout-v11');
    await openAt(page, origin);
    await createDraft(page);
    const draftsBefore = await draftFingerprint(page);
    const stableNames = await cacheNames(page);

    // 1. The worker script request itself is cut off.
    origin.state.worker = 'current';
    origin.state.dropWorkerScripts = true;
    expect(await updateWorker(page)).not.toBe('ok');
    expect(await cacheNames(page)).toEqual(stableNames);
    expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
    expect(await workerBuild(page), 'still the old worker').toBeNull();

    // 2. The script arrives but a precache download fails: the new worker never installs.
    origin.state.dropWorkerScripts = false;
    origin.state.failAssets = true;
    await updateWorker(page);
    await page.waitForTimeout(800);
    expect(await workerBuild(page), 'a failed install must not replace the working worker').toBeNull();
    expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
    expect(await cacheNames(page)).toContain('mbfd-checkout-v11');
    await waitForShellCache(page, 'mbfd-checkout-v11');

    // The app still works on the old worker and the data is untouched.
    await context.setOffline(true);
    await page.reload();
    await expect(page.getByText(/Restored from autosave/)).toBeVisible({ timeout: 15_000 });
    await context.setOffline(false);
    expect(await draftFingerprint(page)).toEqual(draftsBefore);

    // 3. A release is announced while updates keep failing: the registrar shows the banner and retries by itself.
    await openAt(page, origin);
    origin.state.release = RELEASE_B;
    await checkNow(page);
    await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);
    expect(await workerBuild(page)).toBeNull();

    // 4. The network is healthy again; the next check (here: the browser coming back online) completes the update.
    origin.state.failAssets = false;
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect.poll(() => workerBuild(page), { timeout: 30_000 }).toBe(NEW_CACHE);
    expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
    expect(await draftFingerprint(page)).toEqual(draftsBefore);
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// (d) a device that was offline for days wakes up with a stale worker and shell
// ---------------------------------------------------------------------------

test('a stale worker and shell after days offline: one update, one recovery reload for a chunk that no cache and no server has, no loop, no broken page', async ({ browser }) => {
  const origin = await startDailyOrigin({ worker: 'main-v11', release: RELEASE_A });
  // This device never stored the Forms Hub chunk (the old worker's precache list lacked it), so the upgrade
  // has nothing to carry over for it; see the next test for a chunk that WAS cached.
  origin.state.omitFromPrecache.add(origin.chunk('FormsHub'));
  const { context, page } = await newPage(browser, origin);
  const navigations = trackNavigations(page);
  try {
    await install(page, origin, 'mbfd-checkout-v11', '/daily/stations');
    await openAt(page, origin, '/daily/stations');

    // Days pass offline: the app launches from the stored shell on the old worker.
    await context.setOffline(true);
    await page.reload();
    await expect(page.locator('#root')).not.toBeEmpty({ timeout: 15_000 });
    expect(await registrar(page).then((value) => value?.current)).toBe(RELEASE_A);

    // Meanwhile the server deployed release B: a new worker whose precache list lacks the
    // Forms Hub chunk under its old name, and the old file is gone from the server.
    const staleChunk = origin.chunk('FormsHub');
    origin.state.release = RELEASE_B;
    origin.state.worker = 'current';
    origin.state.omitFromPrecache.add(staleChunk);
    origin.state.goneAssets.add(staleChunk);

    // Back online: the registrar notices the release and the worker updates once.
    const before = navigations();
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect.poll(() => registrar(page).then((value) => value?.pending), { timeout: 20_000 }).toBe(RELEASE_B);
    await expect.poll(() => workerBuild(page), { timeout: 30_000 }).toBe(NEW_CACHE);
    expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
    await expect.poll(() => cacheNames(page), { timeout: 15_000 }).not.toContain('mbfd-checkout-v11');
    expect(navigations(), 'the update itself does not reload an idle visible page').toBe(before);

    // The stale page now asks for a chunk that exists in neither cache nor server: it recovers with one reload.
    const reloaded = nextLoad(page);
    await page.evaluate(() => {
      window.history.pushState({}, '', '/daily/forms-hub');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    await reloaded;
    await page.waitForLoadState('load');
    expect(navigations()).toBe(before + 1);
    expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_B}`]);

    // After the reload: the fresh shell says B, the page renders, and nothing keeps reloading.
    await expect.poll(() => registrar(page).then((value) => value?.current), { timeout: 20_000 }).toBe(RELEASE_B);
    await expect(page.locator('#root')).not.toBeEmpty();
    const settled = navigations();
    await setHidden(page, true);
    await page.waitForTimeout(2500);
    expect(navigations(), 'no reload loop').toBe(settled);
    expect(await registrations(page)).toEqual([{ scope: '/daily/', script: '/daily/sw.js' }]);
    expect((await registerCalls(page)).every((call) => call[0] === '/daily/sw.js' && call[1] === '/daily/')).toBe(true);
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// (e) shared tablets: the next member never reads the previous member's cached data
// ---------------------------------------------------------------------------

const cachedApparatuses = (page: Page) => page.evaluate(async (name) => {
  if (!(await caches.keys()).includes(name)) return null;
  const cache = await caches.open(name);
  const match = await cache.match('/api/public/apparatuses');
  return match ? await match.text() : null;
}, NEW_API_CACHE);

test('a different member signing in purges the previous member\'s cached API data but never touches IndexedDB', async ({ browser }) => {
  const origin = await startDailyOrigin({ submitMode: 'fail' }); // A's queued work must still be queued when B arrives
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await page.reload(); // now controlled: the page's API reads pass through the worker and are cached
    await expect.poll(() => cachedApparatuses(page), { timeout: 15_000 }).toContain('Member A');
    await createDraft(page);
    await context.setOffline(true);
    await addQueuedInspection(page, 101);
    await context.setOffline(false);
    const draftsBefore = await draftFingerprint(page);
    const databasesBefore = await databases(page);

    // The marker remembers an opaque hash, never the member.
    const marker = await page.evaluate(() => localStorage.getItem('mbfd-daily-device-marker'));
    expect(marker).toMatch(/^[0-9a-f]{8,64}\.[0-9a-f]{8,64}$/);
    expect(JSON.stringify(await page.evaluate(() => ({ ...localStorage })))).not.toMatch(/Member A|101|E101/);

    // Member B signs in on the same device.
    origin.state.member = { id: 202, label: 'Member B' };
    await page.goto(`${origin.url}/daily/stations`);
    await expect.poll(async () => page.evaluate(() => localStorage.getItem('mbfd-daily-device-marker')), { timeout: 15_000 }).not.toBe(marker);
    await expect.poll(async () => {
      const cached = await cachedApparatuses(page);
      return cached === null || !cached.includes('Member A');
    }, { timeout: 15_000 }).toBe(true);

    // Offline, B can only ever be served B's data (or nothing), never A's.
    await page.goto(`${origin.url}${PAGE}`);
    await expect.poll(() => cachedApparatuses(page), { timeout: 15_000 }).toContain('Member B');
    expect(await cachedApparatuses(page)).not.toContain('Member A');
    await context.setOffline(true);
    const offlineBody = await page.evaluate(async () => (await fetch('/api/public/apparatuses')).text());
    expect(offlineBody).toContain('Member B');
    expect(offlineBody).not.toContain('Member A');
    await context.setOffline(false);

    // A's drafts and queued work are owner-bound IndexedDB records: still there, untouched.
    expect(await databases(page)).toEqual(databasesBefore);
    expect(await draftFingerprint(page), "A's draft is still there (B may have started its own)").toEqual(expect.arrayContaining(draftsBefore));
    expect(await queuedInspections(page)).toHaveLength(1);
    expect(origin.submissions, 'B must not send A\'s queued work').toHaveLength(0);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('a lapsed session purges the cached member data, drops the member shell, and still leaves IndexedDB alone', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await page.reload(); // now controlled: the page's API reads pass through the worker and are cached
    await expect.poll(() => cachedApparatuses(page), { timeout: 15_000 }).toContain('Member A');
    await createDraft(page);
    const draftsBefore = await draftFingerprint(page);

    origin.state.member = null; // the session ended
    await page.goto(`${origin.url}/daily/stations`).catch(() => undefined);
    await expect.poll(() => page.url(), { timeout: 15_000 }).toContain('/login');

    // Inspect storage from a non-Daily page of the same origin.
    await page.goto(`${origin.url}/daily/manifest.json`);
    await expect.poll(() => cachedApparatuses(page), { timeout: 15_000 }).toBeNull();
    expect(await page.evaluate(async (name) => {
      const cache = await caches.open(name);
      return Boolean(await cache.match('/daily/index.html'));
    }, NEW_CACHE), 'the signed-out device keeps no member shell').toBe(false);
    expect(await draftFingerprint(page)).toEqual(draftsBefore);
    expect(await databases(page)).toContain('mbfd-daily-checkout@60') /* Dexie schema 6 is IndexedDB version 60 */;
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// Notification deep links through the shared registrar
// ---------------------------------------------------------------------------

test('MBFD_NAVIGATE from a notification click is acknowledged, followed when idle and deferred behind a link when work is unsaved', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE, '/daily/stations');
    await openAt(page, origin, '/daily/stations');

    const deliver = (url: string) => page.evaluate((target) => new Promise<{ deferred: boolean | null }>((resolve) => {
      const channel = new MessageChannel();
      channel.port1.onmessage = (event) => resolve({ deferred: event.data?.deferred ?? null });
      navigator.serviceWorker.dispatchEvent(new MessageEvent('message', { data: { type: 'MBFD_NAVIGATE', url: target }, ports: [channel.port2] }));
    }), url);

    // Busy page: acknowledged as deferred, no navigation, an "Open notification" link instead.
    await page.evaluate(() => {
      const target = window as unknown as { __mbfdBusy?: Set<string> };
      (target.__mbfdBusy ??= new Set()).add('unsaved-draft');
      window.dispatchEvent(new CustomEvent('mbfd:busy-change'));
    });
    expect(await deliver('/daily/forms-hub')).toEqual({ deferred: true });
    await expect(page.locator(BANNER).getByRole('link', { name: 'Open notification' })).toHaveAttribute('href', /\/daily\/forms-hub$/);
    await expect(page).toHaveURL(/\/daily\/stations$/);

    // Idle page: followed.
    await page.evaluate(() => {
      (window as unknown as { __mbfdBusy: Set<string> }).__mbfdBusy.clear();
      window.dispatchEvent(new CustomEvent('mbfd:busy-change'));
    });
    const navigated = page.waitForURL(/\/daily\/forms-hub$/);
    expect(await deliver('/daily/forms-hub')).toEqual({ deferred: false });
    await navigated;
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// /daily/service-worker.js is the real worker, byte for byte
// ---------------------------------------------------------------------------

test('the legacy /daily/service-worker.js URL ships the same bytes as /daily/sw.js, never the unsubstituted template', async () => {
  const worker = readFileSync(join(DIST, 'sw.js'), 'utf8');
  const legacy = readFileSync(join(DIST, 'service-worker.js'), 'utf8');

  expect(legacy).toBe(worker);
  expect(legacy).not.toContain('/* DAILY_BUILD_ASSETS */');
  expect(legacy).toMatch(/const BUILD_ASSETS = \["\/daily\/assets\//);
  expect(legacy).toContain('MBFD_WORKER_CORE_VERSION');
  expect(legacy).toContain(`const CACHE_NAME = '${NEW_CACHE}'`);
});

test('a device still registered to /daily/service-worker.js receives the real worker', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await page.goto(`${origin.url}/daily/manifest.json`);
    const result = await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.register('/daily/service-worker.js', { scope: '/daily/' });
      await navigator.serviceWorker.ready;
      return { scope: new URL(registration.scope).pathname, script: new URL((registration.active ?? registration.installing)!.scriptURL).pathname };
    });
    expect(result).toEqual({ scope: '/daily/', script: '/daily/service-worker.js' });
    await expect.poll(() => cacheNames(page), { timeout: 15_000 }).toContain(NEW_CACHE);
    await expect.poll(() => workerBuild(page)).toBe(NEW_CACHE);
    // The precache list is real: the build assets are stored.
    expect(await page.evaluate(async (name) => (await (await caches.open(name)).keys()).filter((request) => new URL(request.url).pathname.startsWith('/daily/assets/')).length, NEW_CACHE)).toBeGreaterThan(10);
  } finally {
    await context.close();
    await origin.close();
  }
});

// ---------------------------------------------------------------------------
// Review follow-ups
// ---------------------------------------------------------------------------

const MARKER_KEY = 'mbfd-daily-device-marker';

/** Request paths (with query) in the worker's API cache, sorted. */
const apiCacheKeys = (page: Page) => page.evaluate(async (name) => {
  if (!(await caches.keys()).includes(name)) return [] as string[];
  const cache = await caches.open(name);
  return (await cache.keys()).map((request) => { const url = new URL(request.url); return url.pathname + url.search; }).sort();
}, NEW_API_CACHE);

const cachedBody = (page: Page, cacheName: string, path: string) => page.evaluate(async ({ cacheName: name, path: key }) => {
  if (!(await caches.keys()).includes(name)) return null;
  const match = await (await caches.open(name)).match(key);
  return match ? await match.text() : null;
}, { cacheName, path });

test('M1: the first sighting of a member on a device keeps what the first online visit stored, and the offline launch still has it', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    // A device that just upgraded: the new code has never seen a member here, so there is no marker yet.
    await page.evaluate((key) => localStorage.removeItem(key), MARKER_KEY);
    await page.reload(); // controlled: the apparatus list and the checklist are read through the worker and stored
    await expect.poll(() => page.evaluate((key) => localStorage.getItem(key), MARKER_KEY), { timeout: 15_000 }).not.toBeNull();
    await expect.poll(() => apiCacheKeys(page), { timeout: 15_000 }).toEqual(expect.arrayContaining(['/api/public/apparatuses', '/api/public/apparatuses/101/checklist']));

    // A purge on first sighting would have emptied the cache by now (nothing re-fetches it afterwards).
    await page.waitForTimeout(1500);
    expect(await apiCacheKeys(page)).toEqual(expect.arrayContaining(['/api/public/apparatuses', '/api/public/apparatuses/101/checklist']));

    // Driving to a bay with no signal: the app opens and the checklist is there.
    await context.setOffline(true);
    await page.reload();
    await expect(page.getByRole('heading', { name: /Offline Fixture Apparatus/ })).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('.equipment-row').first()).toBeVisible();
  } finally {
    await context.close();
    await origin.close();
  }
});

test('M1: after a user switch the new member gets the offline read models fetched again, without opening those screens', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await page.reload();
    await expect.poll(() => cachedBody(page, NEW_API_CACHE, '/api/public/apparatuses/101/checklist'), { timeout: 15_000 }).toContain('Member A');

    origin.state.member = { id: 202, label: 'Member B' };
    await page.goto(`${origin.url}/daily/stations`); // B never opens the apparatus screen
    // The purge removed A's checklist; the worker fetched the lists and that checklist again, as B.
    await expect.poll(() => cachedBody(page, NEW_API_CACHE, '/api/public/apparatuses/101/checklist'), { timeout: 20_000 }).toContain('Member B');
    expect(await cachedBody(page, NEW_API_CACHE, '/api/public/apparatuses')).toContain('Member B');
    for (const path of await apiCacheKeys(page)) {
      expect(await cachedBody(page, NEW_API_CACHE, path), path).not.toContain('Member A');
    }
    // Offline, B opens the checklist.
    await context.setOffline(true);
    const offlineBody = await page.evaluate(async () => (await fetch('/api/public/apparatuses/101/checklist')).text());
    expect(offlineBody).toContain('Member B');
  } finally {
    await context.close();
    await origin.close();
  }
});

test('M2: station-wide reads the Daily screens use still work offline (inspection history, gas meters, room profile, requests, activity); live service data does not', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await page.reload();
    const cached = [
      '/api/public/stations/3/inspections',
      '/api/public/stations/3/gas-meters',
      '/api/public/stations/3/rooms/9/profile',
      '/api/public/stations/3/requests?scope=all&per_page=200&page=1',
      '/api/public/stations/3/equipment-requests',
      '/api/public/stations/3/activity',
      '/api/public/stations/3/rooms/9/assets',
    ];
    const live = ['/api/public/stations/3/service-tickets?scope=open&per_page=1', '/api/public/apparatuses/101/service-notices'];
    const read = (paths: string[]) => page.evaluate(async (list) => Promise.all(list.map(async (path) => {
      try {
        return { path, status: (await fetch(path, { headers: { Accept: 'application/json' } })).status };
      } catch {
        return { path, status: 'network error' as const };
      }
    })), paths);

    expect((await read([...cached, ...live])).every((entry) => entry.status === 200)).toBe(true);
    await context.setOffline(true);
    expect(await read(cached)).toEqual(cached.map((path) => ({ path, status: 200 })));
    expect(await read(live), 'service tickets and notices are time-sensitive and never come from a stored copy').toEqual(live.map((path) => ({ path, status: 'network error' })));
  } finally {
    await context.close();
    await origin.close();
  }
});

test('M3: a page still running the previous JavaScript can lazy-load its chunk after the worker upgraded and deleted the old cache', async ({ browser }) => {
  const origin = await startDailyOrigin({ worker: 'main-v11', release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  const navigations = trackNavigations(page);
  try {
    await install(page, origin, 'mbfd-checkout-v11', '/daily/stations');
    await openAt(page, origin, '/daily/stations');

    // Release B renames/removes the Forms Hub chunk: the new worker neither precaches nor can fetch it.
    const staleChunk = origin.chunk('FormsHub');
    origin.state.release = RELEASE_B;
    origin.state.worker = 'current';
    origin.state.omitFromPrecache.add(staleChunk);
    origin.state.goneAssets.add(staleChunk);

    // The installed app stays open (resident for days) while the worker upgrades underneath it.
    const before = navigations();
    await updateTo(page, NEW_CACHE);
    await expect.poll(() => cacheNames(page), { timeout: 15_000 }).not.toContain('mbfd-checkout-v11');
    expect(await page.evaluate(async ({ name, chunk }) => Boolean(await (await caches.open(name)).match(chunk)), { name: NEW_CACHE, chunk: staleChunk }),
      'the previous release chunk was carried into the new cache').toBe(true);

    // The open page navigates inside the app and needs that chunk: it comes from the cache, with no reload.
    const hitsBefore = origin.hits.get(staleChunk) ?? 0;
    const served = page.waitForResponse((response) => response.url().endsWith(staleChunk));
    await page.evaluate(() => {
      window.history.pushState({}, '', '/daily/forms-hub');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    const response = await served;
    expect(response.status()).toBe(200);
    expect(response.fromServiceWorker()).toBe(true);
    await expect(page.locator('#root')).not.toBeEmpty();
    expect(origin.hits.get(staleChunk) ?? 0, 'answered from the cache, not the network').toBe(hitsBefore);
    expect(navigations(), 'no recovery reload was needed').toBe(before);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('M3: assets are pruned after two releases but the previous release stays, across real worker upgrades', async ({ browser }) => {
  const origin = await startDailyOrigin({ worker: 'current', release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE, '/daily/stations');
    const forms = origin.chunk('FormsHub');
    const stylesheet = origin.assets().find((asset) => asset.endsWith('.css'));
    if (!stylesheet) throw new Error('The Daily build has no stylesheet to use as the second renamed asset');
    const stored = () => page.evaluate(async (name) => (await (await caches.open(name)).keys()).map((request) => new URL(request.url).pathname), NEW_CACHE);
    const recordedCurrent = () => page.evaluate(async (name) => {
      const match = await (await caches.open(name)).match('/__mbfd/daily-asset-generations');
      return match ? ((await match.json()) as { current: string[] }).current : null;
    }, NEW_CACHE);

    // Release A: every build asset is stored and recorded as the current generation.
    await expect.poll(async () => (await recordedCurrent())?.length ?? 0, { timeout: 15_000 }).toBe(origin.assets().length);
    expect(await stored()).toContain(forms);

    // Release B no longer has the Forms Hub chunk: it stays, because pages of release A may still be open.
    origin.state.release = RELEASE_B;
    origin.state.omitFromPrecache.add(forms);
    await updateWorker(page);
    await expect.poll(async () => (await recordedCurrent())?.includes(forms), { timeout: 20_000 }).toBe(false);
    expect(await stored(), 'the previous release is kept').toContain(forms);

    // Release C: Forms Hub is two releases old and goes; the stylesheet (still in B's list) stays.
    origin.state.release = 'cccccccccccc';
    origin.state.omitFromPrecache.add(stylesheet);
    await updateWorker(page);
    await expect.poll(async () => (await recordedCurrent())?.includes(stylesheet), { timeout: 20_000 }).toBe(false);
    await expect.poll(async () => (await stored()).includes(forms), { timeout: 15_000, message: 'two releases old: pruned' }).toBe(false);
    expect(await stored()).toContain(stylesheet);
    expect((await stored()).length).toBeGreaterThan(10);
  } finally {
    await context.close();
    await origin.close();
  }
});
test('L1: the stored offline shell is the Laravel-rendered one with its release meta, and the raw static file is never what the worker fetches', async ({ browser }) => {
  const origin = await startDailyOrigin({ release: RELEASE_A });
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);

    for (const key of ['/daily/index.html', '/daily/']) {
      const stored = await cachedBody(page, NEW_CACHE, key);
      expect(stored, key).toContain(`<meta name="mbfd-release" content="${RELEASE_A}">`);
    }
    expect(origin.hits.get('/daily/index.html'), 'the raw file carries no release meta and is not fetched').toBeUndefined();

    // After an offline launch the registrar knows the stored shell's release, so a stale shell is recognised later.
    await context.setOffline(true);
    await page.reload();
    await expect.poll(() => registrar(page).then((value) => value?.current), { timeout: 20_000 }).toBe(RELEASE_A);
  } finally {
    await context.close();
    await origin.close();
  }
});

test('L2: a Daily navigation redirected anywhere but sign-in (forced password change, city e-mail review, canonical host) keeps the cached data and the offline shell', async ({ browser }) => {
  const origin = await startDailyOrigin();
  const { context, page } = await newPage(browser, origin);
  try {
    await install(page, origin, NEW_CACHE);
    await page.reload();
    await expect.poll(() => cachedBody(page, NEW_API_CACHE, '/api/public/apparatuses'), { timeout: 15_000 }).toContain('Member A');

    origin.state.redirectDaily = '/employee/set-password';
    await page.goto(`${origin.url}/daily/stations`);
    await expect(page).toHaveURL(/\/employee\/set-password$/);
    await page.waitForTimeout(1500); // the worker's own check of where the redirect leads has long finished

    // Inspect from a non-Daily-shell page of the same origin.
    await page.goto(`${origin.url}/daily/manifest.json`);
    expect(await cachedBody(page, NEW_API_CACHE, '/api/public/apparatuses'), 'not a sign-out: the API cache stays').toContain('Member A');
    expect(await cachedBody(page, NEW_CACHE, '/daily/index.html'), 'and so does the offline shell').toContain('<div id="root">');

    // Once the redirect is gone the app opens offline from that shell.
    origin.state.redirectDaily = null;
    await context.setOffline(true);
    await page.goto(`${origin.url}${PAGE}`);
    await expect(page.getByRole('heading', { name: /Offline Fixture Apparatus/ })).toBeVisible({ timeout: 20_000 });
  } finally {
    await context.close();
    await origin.close();
  }
});