import { test, expect, type Page } from '@playwright/test';

/**
 * Non-admin regression smoke.
 *
 * Runs against three viewports (mobile 390×844, tablet 768×1024, desktop
 * 1920×1080) via the projects defined in playwright.config.ts. The point
 * is to prove that the desktop modernization work has not regressed the
 * public-facing or mobile-shaped routes.
 *
 * IMPORTANT: this file is read-only of the app. It must NEVER post data,
 * mutate state, or require authentication. Anything that needs auth lives
 * in mbfd-full-verification.spec.ts.
 */

interface PublicRoute {
  readonly path: string;
  readonly expectedText: RegExp;
  readonly label: string;
}

const publicRoutes: ReadonlyArray<PublicRoute> = [
  // Home / marketing
  { path: '/', expectedText: /MBFD|Hub|Support/i, label: 'home' },
  // The pump simulator is a public training tool — must work on every viewport
  { path: '/pump-simulator', expectedText: /pump|simulator|training/i, label: 'pump-simulator' },
];

async function captureBaseline(page: Page, name: string): Promise<void> {
  // Wait for fonts + critical CSS before snapshotting so screenshots are stable.
  await page.evaluate(() => document.fonts.ready);
  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => {
    // networkidle can be flaky behind Cloudflare; fall back to a short pause.
  });
  await page.screenshot({
    path: `test-results/regression/${name}.png`,
    fullPage: true,
  });
}

test.describe('non-admin routes regression', () => {
  for (const route of publicRoutes) {
    test(`${route.label} renders at current viewport`, async ({ page }, testInfo) => {
      const response = await page.goto(route.path, { waitUntil: 'domcontentloaded' });

      // 200 OR redirect chain that lands on a 200 is acceptable; 5xx is not.
      const status = response?.status() ?? 0;
      expect(status, `Non-OK response for ${route.path}`).toBeLessThan(500);

      // Critical: no JavaScript exceptions during page load.
      const errors: string[] = [];
      page.on('pageerror', (err) => errors.push(err.message));
      await page.waitForTimeout(500); // give late JS errors a chance to surface

      // Critical: page must contain the expected marker text.
      await expect(page.locator('body')).toContainText(route.expectedText, { timeout: 10_000 });

      // Critical: no admin-PWA install prompt should ever fire on mobile/tablet.
      // (It is gated by `matchMedia('(min-width: 1280px) and (pointer: fine)')`.)
      if (testInfo.project.name === 'regression-mobile' || testInfo.project.name === 'regression-tablet') {
        const installBannerVisible = await page
          .locator('[data-admin-pwa-install]')
          .first()
          .isVisible()
          .catch(() => false);
        expect(
          installBannerVisible,
          'Admin PWA install banner must NEVER appear on mobile/tablet',
        ).toBe(false);
      }

      await captureBaseline(page, `${testInfo.project.name}-${route.label}`);

      expect(errors, `Page errors on ${route.path}: ${errors.join(', ')}`).toHaveLength(0);
    });
  }

  test('removed apparatus layout does not appear in navigation or load its manifest', async ({ page }) => {
    const requests: string[] = [];
    const errors: string[] = [];

    page.on('request', (request) => requests.push(request.url()));
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('a[href="/apparatus-layout"]')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('Apparatus Equipment Planner');
    expect(requests.some((url) => url.includes('/data/tool-manifest.json'))).toBe(false);
    expect(errors).toHaveLength(0);

    const response = await page.goto('/apparatus-layout', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(404);
  });

  test('admin PWA service worker is NOT registered on non-admin routes', async ({ page }) => {
    await page.goto('/');
    const adminSwRegistered = await page.evaluate(async () => {
      if (!('serviceWorker' in navigator)) return false;
      const regs = await navigator.serviceWorker.getRegistrations();
      return regs.some((r) => r.scope.includes('/admin'));
    });
    expect(
      adminSwRegistered,
      'Admin service worker leaked outside /admin/* scope',
    ).toBe(false);
  });
});
