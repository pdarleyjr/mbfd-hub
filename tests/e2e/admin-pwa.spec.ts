import { test, expect, type APIResponse } from '@playwright/test';

/**
 * Admin PWA smoke — desktop-only.
 *
 * Two sections:
 *  1. Unauthenticated checks — manifest, service worker. These are the same
 *     three smoke-test endpoints the deploy.yml workflow hits.
 *  2. Authenticated checks — uses storageState from auth.setup.ts. These
 *     assert that admin-only resources (login page, dashboard, lookup APIs)
 *     return 200 with the SpotlightPlugin's eager-enumeration class of bug
 *     in mind. If a future plugin or change re-introduces a pre-existing
 *     bug like the one in commit a01b1eba, this spec catches it in PR CI
 *     before deploy.
 *
 * Runs under the `admin-pwa-desktop` project in playwright.config.ts which
 * provides storageState: 'tests/e2e/.auth/admin.json' via the setup project.
 */

interface LookupEnvelope {
  readonly data: ReadonlyArray<{ readonly id: string; readonly label?: string }>;
  readonly meta?: { readonly count?: number; readonly limit?: number };
}

async function expectJsonLookup(res: APIResponse, expectedShape: string): Promise<void> {
  expect(res.status(), `${expectedShape}: expected 200, got ${res.status()}`).toBe(200);
  const body = (await res.json()) as LookupEnvelope;
  expect(Array.isArray(body.data), `${expectedShape}: missing data array`).toBe(true);
  expect(body.meta, `${expectedShape}: missing meta envelope`).toBeDefined();
  // Each row should have an id; label is optional but expected on real data
  for (const row of body.data.slice(0, 5)) {
    expect(typeof row.id).toBe('string');
  }
}

test.describe('admin PWA unauthenticated', () => {
  test('manifest is served with correct content-type', async ({ request }) => {
    const res = await request.get('/admin-pwa/manifest.webmanifest');
    expect(res.status()).toBe(200);
    const ct = res.headers()['content-type'] ?? '';
    expect(ct).toMatch(/manifest\+json|application\/json/);
    const body = (await res.json()) as Record<string, unknown>;
    expect(body.scope).toBe('/admin/');
    expect(String(body.start_url)).toMatch(/^\/admin/);
    expect(Array.isArray(body.icons)).toBe(true);
  });

  test('service worker is served and parses', async ({ request }) => {
    const res = await request.get('/admin/service-worker.js');
    expect(res.status()).toBe(200);
    const ct = res.headers()['content-type'] ?? '';
    expect(ct).toMatch(/javascript/);
    const body = await res.text();
    expect(body).toMatch(/addEventListener\(['"]install['"]/);
    expect(body).toMatch(/addEventListener\(['"]fetch['"]/);
  });

  test('/up health endpoint responds 200', async ({ request }) => {
    const res = await request.get('/up');
    expect(res.status()).toBe(200);
  });

  // Regression guard for commit a01b1eba: unauthenticated /admin/login must
  // never 500. This is the canary that proved the Spotlight plugin's eager
  // resource enumeration was crashing on null user.
  test('regression: /admin/login renders 200 for unauthenticated users', async ({ request }) => {
    const res = await request.get('/admin/login');
    expect(
      res.status(),
      'CRITICAL: /admin/login must never 500 for unauthenticated users (see post-mortem in PHASE_STATUS.md)',
    ).toBe(200);
  });
});

test.describe('admin PWA authenticated', () => {
  test('admin dashboard registers the scoped service worker', async ({ page }) => {
    await page.goto('/admin');
    const swReady = await page.evaluate(async () => {
      if (!('serviceWorker' in navigator)) return false;
      await new Promise((r) => setTimeout(r, 1500));
      const regs = await navigator.serviceWorker.getRegistrations();
      return regs.some((reg) => reg.scope.includes('/admin'));
    });
    expect(swReady, 'Admin service worker should be registered on /admin').toBe(true);
  });

  test('keyboard shortcuts partial is wired into BODY_END', async ({ page }) => {
    await page.goto('/admin');
    const shortcutsRoot = page.locator('[data-admin-shortcuts-root]').first();
    await expect(shortcutsRoot).toBeAttached({ timeout: 5_000 });
  });

  test('status bar render hook produces visible markup', async ({ page }) => {
    await page.goto('/admin');
    const statusBar = page.locator('[data-admin-status-bar]').first();
    await expect(statusBar).toBeAttached({ timeout: 5_000 });
  });

  test('install-prompt mount + context-menu partials are present', async ({ page }) => {
    await page.goto('/admin');
    await expect(page.locator('[data-admin-pwa-install-mount]').first()).toBeAttached();
    await expect(page.locator('[data-admin-context-menu]').first()).toBeAttached();
  });

  test('admin page load fires zero JavaScript errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await page.goto('/admin');
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});

    expect(
      errors,
      `Page errors on /admin: ${errors.join(' | ')}`,
    ).toHaveLength(0);
  });
});

test.describe('admin lookup endpoints', () => {
  test('GET /api/admin/lookups/stations returns 200 envelope', async ({ request }) => {
    const res = await request.get('/api/admin/lookups/stations');
    await expectJsonLookup(res, 'stations');
  });

  test('GET /api/admin/lookups/apparatus returns 200 envelope', async ({ request }) => {
    const res = await request.get('/api/admin/lookups/apparatus');
    await expectJsonLookup(res, 'apparatus');
  });

  test('GET /api/admin/lookups/personnel returns 200 envelope', async ({ request }) => {
    const res = await request.get('/api/admin/lookups/personnel');
    await expectJsonLookup(res, 'personnel');
  });

  test('lookup endpoints accept ?q= filter without erroring', async ({ request }) => {
    const res = await request.get('/api/admin/lookups/stations?q=a');
    expect(res.status()).toBe(200);
    const body = (await res.json()) as LookupEnvelope;
    expect(Array.isArray(body.data)).toBe(true);
  });

  test('lookup endpoints respect 500-row cap', async ({ request }) => {
    const res = await request.get('/api/admin/lookups/apparatus');
    const body = (await res.json()) as LookupEnvelope;
    expect((body.data ?? []).length).toBeLessThanOrEqual(500);
    expect(body.meta?.limit).toBe(500);
  });
});
