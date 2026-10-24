import { expect, test, type Page } from '@playwright/test';

/**
 * PWA update flow against the real Laravel app (disposable SQLite, port 8031).
 *
 * Covers: exactly one registration per scope with plain worker URLs, the public
 * /app-release probe, busy-aware release reloads with a loop guard, chunk-load
 * recovery, and notification deep links delivered by the worker.
 *
 * Run: npm run test:pwa-update-e2e
 */

const adminEmployeeId = process.env.OPERATIONAL_FORMS_E2E_ADMIN_EMPLOYEE_ID ?? 'E215';
const adminPassword = process.env.OPERATIONAL_FORMS_E2E_ADMIN_PASSWORD ?? 'OperationalFormsAdmin!1';

const RELEASE_A = 'aaaaaaaaaaaa';
const RELEASE_B = 'bbbbbbbbbbbb';
const BUSY_MESSAGE = 'An update is ready. It will apply when you finish.';
const BANNER = '#mbfd-pwa-banner';

interface ReleaseState {
  /** What /app-release answers right now. */
  served: string;
  /** The release written into the page's <meta name="mbfd-release">. */
  rendered: string;
}

async function login(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill(adminEmployeeId);
  await page.getByLabel('Password').fill(adminPassword);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 30_000 });
}

/**
 * Pin the server release (what /app-release says) and the release the Hub home
 * page claims to have been rendered by, so a "stale page, newer server" state
 * can be created at will and survives reloads.
 */
async function pinReleases(page: Page, state: ReleaseState): Promise<void> {
  await page.addInitScript(() => {
    // Headless tabs are always visible; let the tests flip document.hidden.
    Object.defineProperty(document, 'hidden', {
      configurable: true,
      get: () => Boolean((window as unknown as { __forceHidden?: boolean }).__forceHidden),
    });
  });
  await page.route('**/app-release', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      headers: { 'cache-control': 'no-store' },
      body: JSON.stringify({ release: state.served }),
    }),
  );
  await page.route(
    (url) => url.pathname === '/',
    async (route) => {
      if (route.request().resourceType() !== 'document') {
        await route.fallback();

        return;
      }
      const response = await route.fetch();
      const body = (await response.text()).replace(
        /(<meta name="mbfd-release" content=")[^"]*(")/,
        `$1${state.rendered}$2`,
      );
      const headers = { ...response.headers() };
      delete headers['content-length'];
      delete headers['content-encoding'];
      await route.fulfill({ status: response.status(), headers, body });
    },
  );
}

const registrar = (page: Page) =>
  page.evaluate(() => {
    const api = (window as unknown as { __mbfdUpdateRegistrar?: { api: { currentRelease(): string | null; pendingRelease(): string | null } } })
      .__mbfdUpdateRegistrar?.api;

    return api ? { current: api.currentRelease(), pending: api.pendingRelease() } : null;
  });

const checkNow = (page: Page) =>
  page.evaluate(() =>
    (window as unknown as { __mbfdUpdateRegistrar: { api: { checkNow(force: boolean): Promise<void> } } }).__mbfdUpdateRegistrar.api.checkNow(true),
  );

/** Same contract every bundle uses: a reason in window.__mbfdBusy plus the change event. */
const setBusy = (page: Page, busy: boolean) =>
  page.evaluate((on) => {
    const target = window as unknown as { __mbfdBusy?: Set<string> };
    target.__mbfdBusy ??= new Set<string>();
    if (on) target.__mbfdBusy.add('unsaved-draft');
    else target.__mbfdBusy.delete('unsaved-draft');
    window.dispatchEvent(new CustomEvent('mbfd:busy-change'));
  }, busy);

const setHidden = (page: Page, hidden: boolean) =>
  page.evaluate((value) => {
    (window as unknown as { __forceHidden?: boolean }).__forceHidden = value;
    document.dispatchEvent(new Event('visibilitychange'));
  }, hidden);

const guardKeys = (page: Page) =>
  page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('mbfd-reloaded-for:')).sort());

/**
 * `framenavigated` only fires when the new document commits, which a busy
 * single-threaded dev server can delay past any fixed wait. beforeunload fires
 * the instant a reload or navigation is initiated, so "nothing happened" claims
 * are checked with this probe.
 */
const armUnloadProbe = (page: Page) =>
  page.evaluate(() => {
    const target = window as unknown as { __e2eUnloading?: boolean };
    target.__e2eUnloading = false;
    window.addEventListener('beforeunload', () => {
      target.__e2eUnloading = true;
    });
  });

const unloadStarted = (page: Page): Promise<boolean> =>
  page
    .evaluate(() => Boolean((window as unknown as { __e2eUnloading?: boolean }).__e2eUnloading))
    .catch(() => true); // the execution context vanished because the page is navigating

function trackNavigations(page: Page): () => number {
  let count = 0;
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) count += 1;
  });

  return () => count;
}

async function openHome(page: Page, state: ReleaseState): Promise<void> {
  await pinReleases(page, state);
  await login(page);
  await page.goto('/');
  await expect.poll(() => registrar(page).then((value) => value?.current)).toBe(state.rendered);
}

// ---------------------------------------------------------------------------
// Registrations and the release probe
// ---------------------------------------------------------------------------

const registrations = (page: Page) =>
  page.evaluate(async () => {
    const all = await navigator.serviceWorker.getRegistrations();

    return all
      .map((registration) => {
        const worker = registration.active ?? registration.waiting ?? registration.installing;
        const script = worker ? new URL(worker.scriptURL) : null;

        return { scope: new URL(registration.scope).pathname, script: script ? script.pathname + script.search : null };
      })
      .sort((a, b) => a.scope.localeCompare(b.scope));
  });

test('exactly one registration per scope, with plain worker URLs, across Admin and Hub pages', async ({ page }) => {
  await login(page);
  await page.goto('/admin/settings');

  await expect
    .poll(() => registrations(page), { timeout: 30_000 })
    .toEqual([
      { scope: '/', script: '/sw.js' },
      { scope: '/admin/', script: '/admin/service-worker.js' },
    ]);

  // Visiting more Admin and Hub pages must neither add nor duplicate registrations.
  for (const path of ['/', '/admin', '/admin/settings', '/']) {
    await page.goto(path);
    await page.waitForLoadState('load');
    await page.waitForTimeout(500);
    expect(await registrations(page), `registrations after visiting ${path}`).toEqual([
      { scope: '/', script: '/sw.js' },
      { scope: '/admin/', script: '/admin/service-worker.js' },
    ]);
  }
});

test('the registrar never registers a worker on a Hub page that has none', async ({ page }) => {
  await login(page);
  await page.goto('/');
  await expect.poll(() => registrar(page).then((value) => value !== null)).toBe(true);
  await page.waitForTimeout(1000);

  expect(await registrations(page)).toEqual([]);
});

test('/app-release is public, stateless, no-store JSON', async ({ playwright, baseURL }) => {
  const anonymous = await playwright.request.newContext({ baseURL });
  const response = await anonymous.get('/app-release');

  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/json');
  expect(response.headers()['cache-control']).toContain('no-store');
  expect(response.headers()['set-cookie']).toBeUndefined();
  expect((await response.json()).release).toMatch(/^(?:[0-9a-f]{12}|unknown)$/);
  await anonymous.dispose();
});

test('the Hub head carries the release meta tag', async ({ page }) => {
  await page.goto('/login');

  await expect(page.locator('meta[name="mbfd-release"]')).toHaveAttribute('content', /^(?:[0-9a-f]{12}|unknown)$/);
});

// ---------------------------------------------------------------------------
// Release change
// ---------------------------------------------------------------------------

test('a new release never reloads a busy page, shows the banner, reloads once when free, and never loops', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await openHome(page, state);
  expect(await guardKeys(page)).toEqual([]);

  // 1. The member is mid-task and the server moves to a new release.
  await setBusy(page, true);
  state.served = RELEASE_B;
  await checkNow(page);
  await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);

  const banner = page.locator(BANNER);
  await expect(banner).toBeVisible();
  await expect(banner).toHaveAttribute('role', 'status');
  await expect(banner).toContainText(BUSY_MESSAGE);
  const refresh = banner.getByRole('button', { name: 'Refresh now' });
  await expect(refresh).toHaveAttribute('aria-disabled', 'true');
  expect((await refresh.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);

  // 2. Even with the tab hidden, a busy page is never reloaded.
  const before = navigations();
  await armUnloadProbe(page);
  await setHidden(page, true);
  await page.waitForTimeout(1500);
  expect(await unloadStarted(page), 'a busy page must not start a reload').toBe(false);
  expect(navigations()).toBe(before);
  await expect(banner).toBeVisible();
  await refresh.click({ force: true });
  await page.waitForTimeout(500);
  expect(await unloadStarted(page), 'a disabled Refresh now must do nothing').toBe(false);
  expect(navigations()).toBe(before);

  // 3. The work finishes: exactly one reload happens.
  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await setBusy(page, false);
  await reloaded;
  await page.waitForLoadState('load');
  expect(navigations()).toBe(before + 1);
  expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_B}`]);

  // 4. The reloaded page is still stale (its HTML still says A): the guard blocks a second reload.
  await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);
  const afterFirst = navigations();
  await armUnloadProbe(page);
  await setHidden(page, true);
  await page.waitForTimeout(2000);
  expect(await unloadStarted(page), 'no second automatic reload for the same release').toBe(false);
  expect(navigations()).toBe(afterFirst);

  // 5. The banner now offers a manual refresh, which the member may still use.
  const manual = page.locator(BANNER).getByRole('button', { name: 'Refresh now' });
  await expect(manual).not.toHaveAttribute('aria-disabled', 'true');
  const manualReload = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await manual.click();
  await manualReload;
  expect(navigations()).toBe(afterFirst + 1);
});

test('an idle visible page shows the banner and only reloads once it is hidden', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await openHome(page, state);

  state.served = RELEASE_B;
  await checkNow(page);
  const banner = page.locator(BANNER);
  await expect(banner).toContainText('An update is ready.');
  await expect(banner.getByRole('button', { name: 'Refresh now' })).not.toHaveAttribute('aria-disabled', 'true');

  const before = navigations();
  await armUnloadProbe(page);
  await page.waitForTimeout(1500);
  expect(await unloadStarted(page), 'a visible, idle page is not reloaded behind the member').toBe(false);
  expect(navigations()).toBe(before);

  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await setHidden(page, true);
  await reloaded;
  expect(navigations()).toBe(before + 1);
});

test('a page with unsaved typed text is treated as busy even if no feature marked it', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await openHome(page, state);

  await page.evaluate(() => {
    const field = document.createElement('textarea');
    field.id = 'e2e-unsaved-field';
    document.body.appendChild(field);
  });
  await page.locator('#e2e-unsaved-field').fill('Engine 5 pump test notes');

  state.served = RELEASE_B;
  await checkNow(page);
  const before = navigations();
  await armUnloadProbe(page);
  await setHidden(page, true);
  await page.waitForTimeout(2000);

  expect(await unloadStarted(page), 'typed-but-unsaved text must block the automatic reload').toBe(false);
  expect(navigations()).toBe(before);
  await expect(page.locator(BANNER)).toBeVisible();
});

// ---------------------------------------------------------------------------
// M5: edits the DOM comparison cannot see
// ---------------------------------------------------------------------------

async function releaseWaitingBehindHiddenTab(page: Page, state: ReleaseState): Promise<{ before: number; navigations: () => number }> {
  const navigations = trackNavigations(page);
  state.served = RELEASE_B;
  await checkNow(page);
  await expect.poll(() => registrar(page).then((value) => value?.pending)).toBe(RELEASE_B);
  const before = navigations();
  await armUnloadProbe(page);
  await setHidden(page, true);
  await page.waitForTimeout(1500);

  return { before, navigations };
}

test('M5: text typed into a rich-text editor (contenteditable, mirrored into a hidden input) blocks the hidden-tab reload until the editor is gone', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  await openHome(page, state);

  await page.evaluate(() => {
    const form = document.createElement('form');
    form.innerHTML = '<div id="e2e-editor" contenteditable="true" role="textbox" aria-label="Update body" style="min-height:40px;border:1px solid #888"></div><input type="hidden" name="body" id="e2e-editor-mirror" value="">';
    document.body.appendChild(form);
  });
  const editor = page.locator('#e2e-editor');
  await editor.click();
  await page.keyboard.type('Department update: Station 4 dock light replaced');
  // The editor script mirrors its text into the hidden input; the DOM comparison skips hidden inputs.
  await page.evaluate(() => {
    const mirror = document.querySelector<HTMLInputElement>('#e2e-editor-mirror')!;
    mirror.value = document.querySelector('#e2e-editor')!.textContent ?? '';
    mirror.dispatchEvent(new Event('input', { bubbles: true }));
  });
  expect(await page.evaluate(() => Array.from((window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy ?? [])), 'no explicit busy reason was set').toEqual([]);

  const { before, navigations } = await releaseWaitingBehindHiddenTab(page, state);
  expect(await unloadStarted(page), 'a draft in a rich-text editor must not be reloaded away').toBe(false);
  expect(navigations()).toBe(before);
  await expect(page.locator(BANNER)).toBeVisible();

  // The editor leaves the page (saved, or the member moved on): the page is free and reloads once.
  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await page.evaluate(() => document.querySelector('#e2e-editor')!.closest('form')!.remove());
  await setHidden(page, false);
  await setHidden(page, true);
  await reloaded;
  expect(navigations()).toBe(before + 1);
});

test('M5: events a script dispatches (not the member) never mark the page, and a search box is not unsaved work', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  await openHome(page, state);

  await page.evaluate(() => {
    const editor = document.createElement('div');
    editor.id = 'e2e-script-editor';
    editor.setAttribute('contenteditable', 'true');
    document.body.appendChild(editor);
    editor.textContent = 'set by a script';
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    const search = document.createElement('div');
    search.setAttribute('role', 'search');
    search.innerHTML = '<div id="e2e-search-editor" contenteditable="true"></div>';
    document.body.appendChild(search);
  });
  await page.locator('#e2e-search-editor').click();
  await page.keyboard.type('engine');

  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await releaseWaitingBehindHiddenTab(page, state);
  await reloaded;
});

// A file chosen in the picker is covered by the unit tests (tests/Node/daily-pwa-page-side.test.mjs): Playwright's
// setInputFiles dispatches untrusted input/change events (isTrusted === false), which the tracker ignores by design,
// so a real trusted file selection cannot be produced from here.
// ---------------------------------------------------------------------------
// L7: the legacy admin worker URL
// ---------------------------------------------------------------------------

test('L7: both admin worker URLs are served with the /admin/ scope header, so a registration from either can update', async ({ page, playwright, baseURL }) => {
  const anonymous = await playwright.request.newContext({ baseURL });
  for (const path of ['/admin/service-worker.js', '/admin-pwa/service-worker.js']) {
    const response = await anonymous.get(path);
    expect(response.status(), path).toBe(200);
    expect(response.headers()['service-worker-allowed'], path).toBe('/admin/');
    expect(response.headers()['content-type'], path).toMatch(/javascript/);
    expect(response.headers()['cache-control'], path).toContain('no-cache');
  }
  await anonymous.dispose();

  // A device whose /admin/ registration came from the legacy URL: the script and its updates are accepted.
  await page.goto('/login');
  const registered = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.register('/admin-pwa/service-worker.js', { scope: '/admin/', updateViaCache: 'none' });
    // This page is outside /admin/, so serviceWorker.ready would never resolve; poll the registration instead.
    for (let attempt = 0; attempt < 100 && registration.active?.state !== 'activated'; attempt += 1) {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    await registration.update(); // an update check must also be accepted: it re-validates the scope

    return { scope: new URL(registration.scope).pathname, script: new URL((registration.active ?? registration.installing ?? registration.waiting)!.scriptURL).pathname, state: registration.active?.state };
  });
  expect(registered).toEqual({ scope: '/admin/', script: '/admin-pwa/service-worker.js', state: 'activated' });
});
// ---------------------------------------------------------------------------
// Chunk-load recovery
// ---------------------------------------------------------------------------

const OFFLINE_MESSAGE = "You're offline. Some screens will load when you're back online.";
const CHUNK_MESSAGE = 'Part of this page could not load.';
const MISSING_CHUNK = '/build/assets/e2e-missing-chunk-0a1b2c3d.js';
const PRESENT_CHUNK = '/build/assets/e2e-present-chunk-0a1b2c3d.js';

/** What a browser attaches to vite:preloadError: an Error naming the file that failed. */
const fireChunkError = (page: Page, chunk?: string) =>
  page.evaluate((path) => {
    const event = new Event('vite:preloadError', { cancelable: true }) as Event & { payload?: Error };
    if (path) event.payload = new Error(`Failed to fetch dynamically imported module: ${window.location.origin}${path}`);
    window.dispatchEvent(event);

    return event.defaultPrevented;
  }, chunk);

async function serveChunks(page: Page): Promise<void> {
  await page.route(`**${MISSING_CHUNK}`, (route) => route.fulfill({ status: 404, contentType: 'text/plain', body: 'not found' }));
  await page.route(`**${PRESENT_CHUNK}`, (route) => route.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
}

test('a failed lazy chunk whose file is really gone reloads once per release when free, and shows the banner when busy or already tried', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await serveChunks(page);
  await openHome(page, state);

  // Busy: no reload, the banner explains.
  await setBusy(page, true);
  expect(await fireChunkError(page, MISSING_CHUNK), 'the error must be handled (preventDefault)').toBe(true);
  await expect(page.locator(BANNER)).toContainText(CHUNK_MESSAGE);
  const before = navigations();
  await armUnloadProbe(page);
  await page.waitForTimeout(1000);
  expect(await unloadStarted(page), 'a busy page must not reload for a chunk error').toBe(false);
  expect(navigations()).toBe(before);

  // Free: exactly one reload.
  await setBusy(page, false);
  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await fireChunkError(page, MISSING_CHUNK);
  await reloaded;
  await page.waitForLoadState('load');
  expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_A}`]);
  const afterFirst = navigations();

  // Same release again: never a second automatic reload.
  await expect.poll(() => registrar(page).then((value) => value !== null)).toBe(true);
  await armUnloadProbe(page);
  await fireChunkError(page, MISSING_CHUNK);
  await page.waitForTimeout(2000);
  expect(await unloadStarted(page), 'one automatic chunk reload per release').toBe(false);
  expect(navigations()).toBe(afterFirst);
  await expect(page.locator(BANNER)).toContainText(CHUNK_MESSAGE);
});

test('a failed lazy chunk after a deploy (a different release is live) reloads once', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await openHome(page, state);
  state.served = RELEASE_B;

  const before = navigations();
  const reloaded = page.waitForEvent('framenavigated', (frame) => frame === page.mainFrame());
  await fireChunkError(page); // Safari names no file; the changed release is the evidence
  await reloaded;
  await page.waitForLoadState('load');
  expect(navigations()).toBe(before + 1);
  expect(await guardKeys(page)).toEqual([`mbfd-reloaded-for:${RELEASE_B}`]);
});

test('M4: a failed lazy chunk on a page that cannot reach the server never reloads; it says so and clears itself when the network is back', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await serveChunks(page);
  await openHome(page, state);

  // The release probe cannot be reached (offline, or a flaky cell connection).
  await page.unroute('**/app-release');
  await page.route('**/app-release', (route) => route.abort('connectionfailed'));
  const before = navigations();
  await armUnloadProbe(page);

  // Whatever the error says (a missing file, no file named), a failed probe is not evidence a reload would help.
  expect(await fireChunkError(page, MISSING_CHUNK)).toBe(true);
  await expect(page.locator(BANNER)).toContainText(OFFLINE_MESSAGE);
  await fireChunkError(page);
  await page.waitForTimeout(1500);
  expect(await unloadStarted(page), 'offline must not turn a degraded page into the browser error page').toBe(false);
  expect(navigations()).toBe(before);
  expect(await guardKeys(page), 'nothing was reloaded, so nothing is marked as tried').toEqual([]);
  await expect(page.locator(BANNER)).not.toContainText(CHUNK_MESSAGE);
  expect(await registrar(page)).toMatchObject({ pending: null });

  // The member can dismiss it, and it goes away by itself once the server answers again.
  await page.locator(BANNER).getByRole('button', { name: 'Dismiss' }).click();
  await expect(page.locator(BANNER)).toHaveCount(0);
  await fireChunkError(page, MISSING_CHUNK);
  await expect(page.locator(BANNER)).toContainText(OFFLINE_MESSAGE);
  await page.unroute('**/app-release');
  await page.route('**/app-release', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', headers: { 'cache-control': 'no-store' }, body: JSON.stringify({ release: state.served }) }),
  );
  await checkNow(page);
  await expect(page.locator(BANNER)).toHaveCount(0);
  expect(navigations()).toBe(before);
});

test('M4: with the server reachable and the same release, a chunk that is not missing (a blip) shows the banner instead of reloading', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  const navigations = trackNavigations(page);
  await serveChunks(page);
  await openHome(page, state);
  const before = navigations();
  await armUnloadProbe(page);

  await fireChunkError(page, PRESENT_CHUNK);
  await expect(page.locator(BANNER)).toContainText(CHUNK_MESSAGE);
  await page.waitForTimeout(1200);
  expect(await unloadStarted(page)).toBe(false);
  expect(navigations()).toBe(before);
  await expect(page.locator(BANNER).getByRole('button', { name: 'Refresh now' })).not.toHaveAttribute('aria-disabled', 'true');
});
// ---------------------------------------------------------------------------
// Notification deep links (worker -> page)
// ---------------------------------------------------------------------------

const deliverWorkerMessage = (page: Page, url: string) =>
  page.evaluate((target) => {
    navigator.serviceWorker.dispatchEvent(new MessageEvent('message', { data: { type: 'MBFD_NAVIGATE', url: target } }));
  }, url);

test('MBFD_NAVIGATE opens the destination when free, and defers behind a link when busy', async ({ page }) => {
  const state: ReleaseState = { served: RELEASE_A, rendered: RELEASE_A };
  await openHome(page, state);

  // Busy: no navigation, an accessible link appears instead.
  await armUnloadProbe(page);
  await setBusy(page, true);
  await deliverWorkerMessage(page, '/account');
  const link = page.locator(BANNER).getByRole('link', { name: 'Open notification' });
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', /\/account$/);
  await expect(page.locator(BANNER)).toContainText('A notification is waiting.');
  await page.waitForTimeout(1000);
  expect(await unloadStarted(page), 'a busy page must not navigate away').toBe(false);
  expect(new URL(page.url()).pathname).toBe('/');
  expect((await link.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);

  // Cross-origin destinations are ignored entirely.
  await deliverWorkerMessage(page, 'https://evil.example/phish');
  await page.waitForTimeout(500);
  expect(await unloadStarted(page), 'a cross-origin destination must be ignored').toBe(false);
  expect(new URL(page.url()).hostname).not.toBe('evil.example');

  // Free: the page navigates itself (never the worker).
  await setBusy(page, false);
  await deliverWorkerMessage(page, '/account');
  await page.waitForURL((url) => url.pathname === '/account', { timeout: 30_000 });
});
