import { generateKeyPairSync } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { stripTypeScriptTypes } from 'node:module';
import type { AddressInfo } from 'node:net';
import { resolve } from 'node:path';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * Upgrade safety for devices that already have the Hub installed.
 *
 * A tiny local origin serves the PRODUCTION worker bytes captured before this
 * change (tests/e2e/fixtures), the page registers exactly the way production
 * pages do, then the same URL starts serving the NEW worker. The registration
 * must update in place: same scope, same script URL, same registration, no
 * duplicate, no unregister. A synthetic push is then delivered through
 * Chromium's devtools protocol to prove the new handler never goes silent.
 */

const root = process.cwd();
const production = {
  root: readFileSync(resolve(root, 'tests/e2e/fixtures/production-sw-2026-10-23.js'), 'utf8'),
  admin: readFileSync(resolve(root, 'tests/e2e/fixtures/production-admin-sw-2026-10-23.js'), 'utf8'),
};
const next = () => ({
  root: readFileSync(resolve(root, 'public/sw.js'), 'utf8'),
  admin: readFileSync(resolve(root, 'public/admin-pwa/service-worker.js'), 'utf8'),
});

const ROOT_BUILD = 'mbfd-root-push-2026-10-23.1';
const ADMIN_BUILD = 'mbfd-admin-v4';

interface FixtureOrigin {
  url: string;
  serve(which: 'old' | 'new'): void;
  /** The VAPID key the Notification settings block on /settings carries. */
  setVapidKey(key: string): void;
  close(): Promise<void>;
}

/** The Notification settings block, as the Hub page renders it (see resources/js/pwa/push-enrollment.ts). */
const settingsPage = (key: string) => `<!doctype html><meta charset="utf-8"><title>settings</title><body>
<div data-push-enrollment data-vapid-key="${key}" data-endpoint-hashes="[]">
  <p data-role="summary"></p><p data-role="message"></p>
  <button data-role="enable">enable</button><button data-role="disable">disable</button><button data-role="test">test</button>
</div>
<script type="module" src="/push-enrollment.js"></script></body>`;

async function startOrigin(): Promise<FixtureOrigin> {
  let generation: 'old' | 'new' = 'old';
  let vapidKey = '';
  const html = '<!doctype html><meta charset="utf-8"><title>fixture</title><body>fixture</body>';
  const server: Server = createServer((request, response) => {
    const path = new URL(request.url ?? '/', 'http://fixture.invalid').pathname;
    const scripts = generation === 'old' ? production : next();
    const send = (body: string, type: string, headers: Record<string, string> = {}) => {
      response.writeHead(200, { 'content-type': type, 'cache-control': 'no-cache', ...headers });
      response.end(body);
    };
    if (path === '/' || path === '/admin/') return send(html, 'text/html; charset=utf-8');
    if (path === '/settings') return send(settingsPage(vapidKey), 'text/html; charset=utf-8');
    if (path === '/push-enrollment.js') {
      // The real module, types stripped, exactly as the Hub bundle would run it.
      return send(stripTypeScriptTypes(readFileSync(resolve(root, 'resources/js/pwa/push-enrollment.ts'), 'utf8')), 'text/javascript; charset=utf-8');
    }
    if (path === '/sw.js') return send(scripts.root, 'text/javascript; charset=utf-8');
    if (path === '/admin/service-worker.js') {
      return send(scripts.admin, 'application/javascript; charset=utf-8', { 'service-worker-allowed': '/admin/' });
    }
    response.writeHead(404).end();
  });
  await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
  const { port } = server.address() as AddressInfo;

  return {
    url: `http://127.0.0.1:${port}`,
    serve: (which) => {
      generation = which;
    },
    setVapidKey: (key) => {
      vapidKey = key;
    },
    close: () =>
      new Promise<void>((done) => {
        server.close(() => done());
        server.closeAllConnections();
      }),
  };
}

/** A real P-256 public key in the uncompressed form PushManager.subscribe expects. */
function vapidStylePublicKey(): string {
  const jwk = generateKeyPairSync('ec', { namedCurve: 'P-256' }).publicKey.export({ format: 'jwk' });
  const point = Buffer.concat([Buffer.from([4]), Buffer.from(jwk.x ?? '', 'base64url'), Buffer.from(jwk.y ?? '', 'base64url')]);

  return point.toString('base64url');
}

interface Probe {
  scope: string;
  script: string;
  endpoint: string | null;
  subscribeError: string | null;
}

/** Register exactly as production code does (see push-notification-widget.js and admin-pwa/main.ts). */
async function registerLikeProduction(page: Page, script: string, scope: string, key: string): Promise<Probe> {
  return page.evaluate(
    async ({ script, scope, key }) => {
      const registration = await navigator.serviceWorker.register(script, { scope, updateViaCache: 'none' });
      await navigator.serviceWorker.ready;
      (window as unknown as { __registration: ServiceWorkerRegistration }).__registration = registration;

      let endpoint: string | null = null;
      let subscribeError: string | null = null;
      try {
        const bytes = Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
        endpoint = (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes })).endpoint;
      } catch (error) {
        subscribeError = String(error);
      }
      const scriptUrl = new URL(registration.active?.scriptURL ?? 'http://none.invalid/');

      return {
        scope: new URL(registration.scope).pathname,
        script: scriptUrl.pathname + scriptUrl.search,
        endpoint,
        subscribeError,
      };
    },
    { script, scope, key },
  );
}

async function updateInPlace(page: Page, scope: string, expectedBuild: string) {
  return page.evaluate(
    async ({ scope, expectedBuild }) => {
      const registration = (window as unknown as { __registration: ServiceWorkerRegistration }).__registration;
      await registration.update();

      const askVersion = (worker: ServiceWorker | null) =>
        new Promise<string | null>((resolveVersion) => {
          if (!worker) return resolveVersion(null);
          const channel = new MessageChannel();
          const timer = setTimeout(() => resolveVersion(null), 400);
          channel.port1.onmessage = (event) => {
            clearTimeout(timer);
            resolveVersion(event.data?.build ?? null);
          };
          worker.postMessage({ type: 'GET_VERSION' }, [channel.port2]);
        });

      let build: string | null = null;
      for (let attempt = 0; attempt < 40 && build !== expectedBuild; attempt += 1) {
        build = await askVersion(registration.active);
        if (build !== expectedBuild) await new Promise((r) => setTimeout(r, 250));
      }
      // The new worker answers while still activating; wait for activation (cache cleanup) to finish.
      for (let attempt = 0; attempt < 40 && registration.active?.state !== 'activated'; attempt += 1) {
        await new Promise((r) => setTimeout(r, 100));
      }

      const all = await navigator.serviceWorker.getRegistrations();
      const same = await navigator.serviceWorker.getRegistration(scope);
      const scriptUrl = new URL(registration.active?.scriptURL ?? 'http://none.invalid/');

      return {
        build,
        registrations: all.length,
        sameObject: same === registration,
        scope: new URL(registration.scope).pathname,
        script: scriptUrl.pathname + scriptUrl.search,
        endpoint: (await registration.pushManager.getSubscription())?.endpoint ?? null,
        caches: (await caches.keys()).sort(),
      };
    },
    { scope, expectedBuild },
  );
}

async function openContext(browser: Browser, origin: string): Promise<BrowserContext> {
  const context = await browser.newContext({ serviceWorkers: 'allow' });
  await context.grantPermissions(['notifications'], { origin });

  return context;
}

test.describe('installed workers upgrade in place', () => {
  let origin: FixtureOrigin;

  test.beforeEach(async () => {
    origin = await startOrigin();
  });

  test.afterEach(async () => {
    await origin.close();
  });

  test('root /sw.js: same registration, URL and scope; the push subscription survives when the browser can create one', async ({ browser }, testInfo) => {
    const context = await openContext(browser, origin.url);
    const page = await context.newPage();
    await page.goto(`${origin.url}/`);

    const before = await registerLikeProduction(page, '/sw.js', '/', vapidStylePublicKey());
    expect(before).toMatchObject({ scope: '/', script: '/sw.js' });

    origin.serve('new');
    const after = await updateInPlace(page, '/', ROOT_BUILD);

    expect(after.build, 'the new worker took over the existing registration').toBe(ROOT_BUILD);
    expect(after).toMatchObject({ scope: '/', script: '/sw.js', registrations: 1, sameObject: true });

    if (before.endpoint === null) {
      testInfo.annotations.push({
        type: 'NOT VERIFIED',
        description: `Push subscription survival: Chromium could not create a subscription here (${before.subscribeError}).`,
      });
    } else {
      expect(after.endpoint, 'the push subscription is untouched by a worker update').toBe(before.endpoint);
    }
    await context.close();
  });

  test('admin /admin/service-worker.js: in-place update from v3 to v4, old caches cleaned, scope untouched', async ({ browser }) => {
    const context = await openContext(browser, origin.url);
    const page = await context.newPage();
    await page.goto(`${origin.url}/admin/`);

    const before = await registerLikeProduction(page, '/admin/service-worker.js', '/admin/', vapidStylePublicKey());
    expect(before).toMatchObject({ scope: '/admin/', script: '/admin/service-worker.js' });
    expect(await page.evaluate(() => caches.keys())).toContain('mbfd-admin-v3-static');

    origin.serve('new');
    const after = await updateInPlace(page, '/admin/', ADMIN_BUILD);

    expect(after.build).toBe(ADMIN_BUILD);
    expect(after).toMatchObject({ scope: '/admin/', script: '/admin/service-worker.js', registrations: 1, sameObject: true });
    expect(after.caches).not.toContain('mbfd-admin-v3-static');
    expect(after.caches).toContain('mbfd-admin-v4-static');
    if (before.endpoint !== null) expect(after.endpoint).toBe(before.endpoint);
    await context.close();
  });

  test('the upgraded root worker shows a notification for every push, however broken the payload', async ({ browser }) => {
    const context = await openContext(browser, origin.url);
    const page = await context.newPage();
    await page.goto(`${origin.url}/`);
    await registerLikeProduction(page, '/sw.js', '/', vapidStylePublicKey());
    origin.serve('new');
    await updateInPlace(page, '/', ROOT_BUILD);

    const worker = context.serviceWorkers().find((candidate) => candidate.url() === `${origin.url}/sw.js`);
    expect(worker, 'the upgraded root worker is running').toBeTruthy();
    // Record what the worker asks the browser to display, in the worker's own realm.
    await worker!.evaluate(() => {
      const scope = self as unknown as { __shown: Array<{ title: string; tag: string; url: string; error: string | null }>; registration: ServiceWorkerRegistration };
      scope.__shown = [];
      const original = scope.registration.showNotification.bind(scope.registration);
      scope.registration.showNotification = (title: string, options?: NotificationOptions) => {
        const entry = { title, tag: String(options?.tag), url: String((options?.data as { url?: string } | undefined)?.url), error: null as string | null };
        scope.__shown.push(entry);

        return original(title, options).catch((error: unknown) => {
          entry.error = String(error);
          throw error;
        });
      };
    });

    const cdp = await context.newCDPSession(page);
    const registrationIds: Record<string, string> = {};
    cdp.on('ServiceWorker.workerRegistrationUpdated', ({ registrations }) => {
      for (const registration of registrations) registrationIds[registration.scopeURL] = registration.registrationId;
    });
    await cdp.send('ServiceWorker.enable');
    await expect.poll(() => registrationIds[`${origin.url}/`]).toBeTruthy();
    const registrationId = registrationIds[`${origin.url}/`];

    const shown = () =>
      worker!.evaluate(() => (self as unknown as { __shown: Array<{ title: string; tag: string; url: string; error: string | null }> }).__shown);
    const payloads = [
      '',
      'Engine 5 is out of service',
      '{"title": "Half a payload',
      JSON.stringify({ title: 'New request', body: 'Station 3', tag: 'mbfd-submission-supply', data: { url: '/employee/forms' } }),
      JSON.stringify({ web_push: 8030, notification: { title: 'Declarative', navigate: `${origin.url}/updates/1`, tag: 'dwp-1' }, mutable: false }),
    ];

    for (const [index, data] of payloads.entries()) {
      await cdp.send('ServiceWorker.deliverPushMessage', { origin: origin.url, registrationId, data });
      await expect
        .poll(async () => (await shown()).length, { message: `payload #${index} must call showNotification once` })
        .toBe(index + 1);

    }

    const calls = await shown();
    expect(calls.map((call) => call.title)).toEqual(['MBFD Hub Update', 'MBFD Hub Update', 'MBFD Hub Update', 'New request', 'Declarative']);
    expect(calls.map((call) => call.url)).toEqual(['/', '/', '/', '/employee/forms', '/updates/1']);
    expect(calls[3].tag).toBe('mbfd-submission-supply');
    expect(calls[4].tag).toBe('dwp-1');
    // Untagged alerts get a content tag, so the same alert delivered twice would replace itself.
    expect(new Set(calls.slice(0, 3).map((call) => call.tag)).size).toBe(3);
    expect(calls.filter((call) => call.error !== null), 'the browser accepted every notification').toEqual([]);

    const delivered = await page.evaluate(async () => (await (await navigator.serviceWorker.ready).getNotifications()).length);
    test.info().annotations.push({
      type: 'info',
      description: `Browser-side notification list size after 5 pushes: ${delivered} (headless Chromium may not retain displayed notifications).`,
    });
    await context.close();
  });
});

/**
 * Headless Chromium refuses PushManager.subscribe here ("permission denied"), so the push SERVICE is faked while
 * everything else is real: real service-worker registrations, real navigator.serviceWorker.getRegistrations(), the real
 * push-enrollment module and its DOM. Subscriptions are kept per registration scope in localStorage so they survive
 * the navigation from the admin page to the settings page.
 */
const FAKE_PUSH_SERVICE = `(() => {
  const read = () => { try { return JSON.parse(localStorage.getItem('e2e-push') || '{}'); } catch { return {}; } };
  const write = (value) => localStorage.setItem('e2e-push', JSON.stringify(value));
  const bytes = (seed) => new Uint8Array(16).fill(seed).buffer;
  const make = (scope, entry) => ({
    endpoint: entry.endpoint,
    options: { applicationServerKey: Uint8Array.from(atob(entry.key), (c) => c.charCodeAt(0)).buffer },
    getKey: (name) => (name === 'p256dh' ? bytes(3) : bytes(7)),
    unsubscribe: async () => { const all = read(); delete all[scope]; write(all); return true; },
  });
  Object.defineProperty(ServiceWorkerRegistration.prototype, 'pushManager', {
    configurable: true,
    get() {
      const scope = new URL(this.scope).pathname;
      return {
        getSubscription: async () => { const entry = read()[scope]; return entry ? make(scope, entry) : null; },
        subscribe: async (options) => {
          const all = read();
          const key = btoa(String.fromCharCode(...new Uint8Array(options.applicationServerKey)));
          all[scope] = { endpoint: 'https://push.example.test/' + scope.replaceAll('/', '_') + '-' + Math.random().toString(36).slice(2), key };
          write(all);
          return make(scope, all[scope]);
        },
      };
    },
  });
})();`;

test.describe('M6: push enrollment looks at every registration', () => {
  let origin: FixtureOrigin;

  test.beforeEach(async () => {
    origin = await startOrigin();
  });

  test.afterEach(async () => {
    await origin.close();
  });

  async function openSettingsDevice(browser: Browser) {
    const key = vapidStylePublicKey();
    origin.setVapidKey(key);
    origin.serve('new');
    const context = await openContext(browser, origin.url);
    const page = await context.newPage();
    await page.addInitScript(FAKE_PUSH_SERVICE);
    const posts: string[] = [];
    const deletes: string[] = [];
    await page.route('**/api/push-subscriptions', async (route) => {
      const request = route.request();
      const body = JSON.parse(request.postData() ?? '{}') as { endpoint?: string };
      (request.method() === 'DELETE' ? deletes : posts).push(String(body.endpoint));
      await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });
    const subscriptions = () =>
      page.evaluate(async () => {
        const all = await navigator.serviceWorker.getRegistrations();
        const found: Array<{ scope: string; endpoint: string | null }> = [];
        for (const registration of all) {
          found.push({ scope: new URL(registration.scope).pathname, endpoint: (await registration.pushManager.getSubscription())?.endpoint ?? null });
        }

        return found.sort((a, b) => a.scope.localeCompare(b.scope));
      });

    return { key, context, page, posts, deletes, subscriptions };
  }

  test('a device subscribed through the admin registration reads as enabled without a second subscription, and "Turn off" removes it', async ({ browser }) => {
    const { key, context, page, posts, deletes, subscriptions } = await openSettingsDevice(browser);

    // Enrolled earlier through the admin page: the subscription lives on the /admin/ registration.
    await page.goto(`${origin.url}/admin/`);
    const admin = await registerLikeProduction(page, '/admin/service-worker.js', '/admin/', key);
    expect(admin.endpoint).not.toBeNull();

    await page.goto(`${origin.url}/settings`);
    await expect(page.locator('[data-push-enrollment]')).toHaveAttribute('data-view', 'enabled', { timeout: 30_000 });
    await expect(page.locator('[data-role="enable"]')).toBeHidden();
    await expect(page.locator('[data-role="disable"]')).toBeVisible();

    expect(await subscriptions(), 'the root registration exists (new enrollments use it) but holds no second subscription').toEqual([
      { scope: '/', endpoint: null },
      { scope: '/admin/', endpoint: admin.endpoint },
    ]);
    expect(posts, 'the existing subscription is synced to the server').toEqual([admin.endpoint]);

    // Turn off: the subscription on the other registration goes too, and the server is told.
    await page.locator('[data-role="disable"]').click();
    await expect(page.locator('[data-push-enrollment]')).toHaveAttribute('data-view', 'ready', { timeout: 15_000 });
    expect((await subscriptions()).every((entry) => entry.endpoint === null)).toBe(true);
    expect(deletes).toEqual([admin.endpoint]);
    await context.close();
  });

  test('with no subscription anywhere, "Turn on" creates exactly one, on the root registration with the plain /sw.js URL', async ({ browser }) => {
    const { context, page, posts, subscriptions } = await openSettingsDevice(browser);
    await page.goto(`${origin.url}/settings`);
    await expect(page.locator('[data-push-enrollment]')).toHaveAttribute('data-view', 'ready', { timeout: 30_000 });

    // Nothing anywhere: one new subscription, on the root registration (scope / , plain /sw.js).
    await page.locator('[data-role="enable"]').click();
    await expect(page.locator('[data-push-enrollment]')).toHaveAttribute('data-view', 'enabled', { timeout: 15_000 });
    const created = (await subscriptions()).filter((entry) => entry.endpoint !== null);
    expect(created).toHaveLength(1);
    expect(created[0].scope).toBe('/');
    expect(posts).toEqual([created[0].endpoint]);
    expect(
      await page.evaluate(async () =>
        (await navigator.serviceWorker.getRegistrations()).map((registration) => new URL((registration.active ?? registration.installing ?? registration.waiting)!.scriptURL).pathname),
      ),
      'no new worker URL was invented',
    ).toEqual(['/sw.js']);
    await context.close();
  });
});