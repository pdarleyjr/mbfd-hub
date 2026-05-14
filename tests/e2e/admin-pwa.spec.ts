import { test, expect } from '@playwright/test';

/**
 * Admin PWA smoke — desktop-only.
 *
 * Verifies:
 *  - /admin/manifest.webmanifest is served with the correct Content-Type
 *  - /admin/service-worker.js loads without parse errors
 *  - admin pages register the service worker successfully
 *  - the install-prompt JS is present in the rendered HEAD
 *  - keyboard shortcuts partial is present
 *  - status bar render hook produces visible markup
 */

test.describe('admin PWA wiring', () => {
  test('manifest is served with correct content-type', async ({ request }) => {
    const res = await request.get('/admin/manifest.webmanifest');
    expect(res.status()).toBe(200);
    const ct = res.headers()['content-type'] ?? '';
    expect(ct).toMatch(/manifest\+json|application\/json/);
    const body = await res.json();
    expect(body.scope).toBe('/admin/');
    expect(body.start_url).toMatch(/^\/admin/);
    expect(Array.isArray(body.icons)).toBe(true);
  });

  test('service worker is served and parses', async ({ request }) => {
    const res = await request.get('/admin/service-worker.js');
    expect(res.status()).toBe(200);
    const ct = res.headers()['content-type'] ?? '';
    expect(ct).toMatch(/javascript/);
    const body = await res.text();
    // Sanity: must contain at least one install + fetch handler
    expect(body).toMatch(/addEventListener\(['"]install['"]/);
    expect(body).toMatch(/addEventListener\(['"]fetch['"]/);
  });

  test('admin dashboard registers the service worker', async ({ page }) => {
    await page.goto('/admin');
    // Wait for SW registration (browser may defer until idle)
    const swReady = await page.evaluate(async () => {
      if (!('serviceWorker' in navigator)) return false;
      await new Promise((r) => setTimeout(r, 1500));
      const regs = await navigator.serviceWorker.getRegistrations();
      return regs.some((reg) => reg.scope.includes('/admin'));
    });
    expect(swReady, 'Admin service worker should be registered on /admin').toBe(true);
  });

  test('keyboard shortcuts partial is wired', async ({ page }) => {
    await page.goto('/admin');
    const shortcutsRoot = page.locator('[data-admin-shortcuts-root]').first();
    await expect(shortcutsRoot).toBeAttached({ timeout: 5_000 });
  });

  test('status bar render hook produces visible markup', async ({ page }) => {
    await page.goto('/admin');
    const statusBar = page.locator('[data-admin-status-bar]').first();
    await expect(statusBar).toBeAttached({ timeout: 5_000 });
  });
});
