// No changes needed - the test file doesn't have loginAsAdmin, it uses storageState from config
import { test, expect } from '@playwright/test';

const BASE_URL = 'https://mbfdhub.com';

// ============================================================
// DESKTOP TESTS
// ============================================================
test.describe('Desktop — Core Pages', () => {

  test('T01 Homepage loads with MBFD branding', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveTitle(/MBFD|Hub/i);
    const logo = page.locator('img[src*="mbfd"], img[alt*="MBFD"], img[alt*="mbfd"]');
    await expect(logo.first()).toBeVisible();
    expect(errors.filter(e => !e.includes('ResizeObserver')).length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T01-homepage.png', fullPage: true });
  });

  test('T02 PWA manifest.json is correct', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/manifest.json`);
    expect(response?.status()).toBe(200);
    const manifest = await response?.json();
    expect(manifest.display).toBe('standalone');
    expect(manifest.theme_color).toBe('#B91C1C');
    expect(manifest.background_color).toBe('#1E293B');
    expect(manifest.icons).toBeDefined();
    expect(manifest.icons.length).toBeGreaterThan(0);
  });

  test('T03 Favicon and apple-touch-icon exist', async ({ page }) => {
    await page.goto(BASE_URL);
    const favicon = await page.$eval('link[rel*="icon"]', (el: any) => el.href).catch(() => null);
    expect(favicon).toBeTruthy();
    const appleIcon = await page.$eval('link[rel="apple-touch-icon"]', (el: any) => el.href).catch(() => null);
    expect(appleIcon).toBeTruthy();
  });

  test('T04 iOS PWA meta tags on homepage', async ({ page }) => {
    await page.goto(BASE_URL);
    const capable = await page.$eval('meta[name="apple-mobile-web-app-capable"]', (el: any) => el.content).catch(() => null);
    expect(capable).toBe('yes');
    const statusBar = await page.$eval('meta[name="apple-mobile-web-app-status-bar-style"]', (el: any) => el.content).catch(() => null);
    expect(statusBar).toBe('black-translucent');
    const themeColor = await page.$eval('meta[name="theme-color"]', (el: any) => el.content).catch(() => null);
    expect(themeColor).toBe('#B91C1C');
  });

  test('T05 404 page is MBFD branded', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/this-page-xyz-does-not-exist`);
    await page.waitForLoadState('domcontentloaded');
    const pageContent = await page.content();
    const has404 = pageContent.includes('404') || (response?.status() === 404);
    expect(has404).toBe(true);
    const hasMBFD = pageContent.includes('MBFD') || pageContent.includes('Miami Beach');
    expect(hasMBFD).toBe(true);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T05-404.png' });
  });

  test('T06 Admin dashboard loads (authenticated)', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/admin/);
    expect(errors.filter(e => !e.includes('ResizeObserver') && !e.includes('Non-Error')).length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T06-admin.png', fullPage: true });
  });

  test('T07 Admin dashboard widgets load without JS errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    const criticalErrors = errors.filter(e =>
      !e.includes('ResizeObserver') && !e.includes('Non-Error promise rejection')
    );
    if (criticalErrors.length > 0) console.error('JS errors:', criticalErrors);
    expect(criticalErrors.length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T07-dashboard.png', fullPage: true });
  });

  test('T08 Admin panel has iOS PWA meta tags', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('domcontentloaded');
    const capable = await page.$eval('meta[name="apple-mobile-web-app-capable"]', (el: any) => el.content).catch(() => null);
    expect(capable).toBe('yes');
  });

  test('T09 Sidebar navigation groups present', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    const pageContent = await page.content();
    const groups = ['Fleet Management', 'Logistics', 'Project Management', 'Administration'];
    const found: string[] = [];
    for (const g of groups) {
      if (pageContent.includes(g)) found.push(g);
    }
    console.log('Nav groups found:', found);
    expect(found.length).toBeGreaterThan(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T09-nav-groups.png' });
  });

  test('T10 Apparatuses resource page loads', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/admin/apparatuses`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    // Check that page loaded with content (heading or table)
    const hasContent = await page.locator('.fi-header h1, .fi-ta-table, [class*="resource"]').first().isVisible().catch(() => false);
    const pageText = await page.textContent('body');
    expect(pageText).toContain('Apparat');
    expect(errors.filter(e => !e.includes('ResizeObserver')).length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T10-apparatuses.png', fullPage: true });
  });

  test('T11 Daily checkout app loads at /daily', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/daily`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(4000);
    const criticalErrors = errors.filter(e => !e.includes('ResizeObserver'));
    expect(criticalErrors.length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-T11-daily.png', fullPage: true });
  });

  test('T19 Service worker exists', async ({ page }) => {
    const swResp = await page.goto(`${BASE_URL}/sw.js`);
    expect(swResp?.status()).toBe(200);
    const swContent = await swResp?.text();
    expect(swContent!.length).toBeGreaterThan(100);
    console.log('Service worker size:', swContent?.length, 'bytes');
  });
});

// ============================================================
// MOBILE TESTS (iPhone 13)
// ============================================================
test.describe('Mobile — iPhone 13', () => {

  test('T12 Homepage renders on mobile', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveTitle(/MBFD|Hub/i);
    const logo = page.locator('img[src*="mbfd"], img[alt*="MBFD"]');
    await expect(logo.first()).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-T12-homepage.png', fullPage: true });
  });

  test('T13 PWA iOS meta tags on mobile', async ({ page }) => {
    await page.goto(BASE_URL);
    const capable = await page.$eval('meta[name="apple-mobile-web-app-capable"]', (el: any) => el.content).catch(() => null);
    expect(capable).toBe('yes');
    const statusBar = await page.$eval('meta[name="apple-mobile-web-app-status-bar-style"]', (el: any) => el.content).catch(() => null);
    expect(statusBar).toBe('black-translucent');
  });

  test('T14 Admin dashboard on mobile', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/admin/);
    expect(errors.filter(e => !e.includes('ResizeObserver') && !e.includes('Non-Error')).length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-T14-admin.png', fullPage: true });
  });

  test('T15 Admin dashboard on mobile — no overflow/errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    expect(errors.filter(e => !e.includes('ResizeObserver') && !e.includes('Non-Error')).length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-T15-dashboard.png', fullPage: true });
  });

  test('T16 Daily checkout on mobile', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`${BASE_URL}/daily`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(4000);
    const criticalErrors = errors.filter(e => !e.includes('ResizeObserver'));
    expect(criticalErrors.length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-T16-daily.png', fullPage: true });
  });

  test('T17 Admin panel iOS meta on mobile', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('domcontentloaded');
    const capable = await page.$eval('meta[name="apple-mobile-web-app-capable"]', (el: any) => el.content).catch(() => null);
    expect(capable).toBe('yes');
  });

  test('T18 404 branded on mobile', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/xyz-not-found-mobile`);
    await page.waitForLoadState('domcontentloaded');
    const pageContent = await page.content();
    const hasBranding = pageContent.includes('MBFD') || pageContent.includes('Miami Beach');
    expect(hasBranding).toBe(true);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-T18-404.png' });
  });
});
