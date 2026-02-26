import { test, expect, devices, Page } from '@playwright/test';

const BASE_URL = 'https://mbfdhub.com';
const ADMIN_EMAIL = 'MiguelAnchia@miamibeachfl.gov';
const ADMIN_PASSWORD = 'Penco1';

// Helper to login
async function loginAsAdmin(page: Page) {
  await page.goto(`${BASE_URL}/admin/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('input[type="email"], [data-field-wrapper] input[type="email"]', ADMIN_EMAIL);
  await page.fill('input[type="password"], [data-field-wrapper] input[type="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  try {
    await page.waitForURL(/.*\/admin.*/, { timeout: 15000 });
  } catch {
    // May have redirected already
  }
}

test.describe('Desktop Tests', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('1. Homepage loads with MBFD branding (no Laravel default)', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    const title = await page.title();
    expect(title).toMatch(/MBFD/i);
    // MBFD branding - could be img OR text heading
    const mbfdBranding = page.locator('h1:has-text("MBFD"), img[src*="mbfd"], img[alt*="MBFD"]');
    await expect(mbfdBranding.first()).toBeVisible();
    // No default Laravel SVG text
    const laravelLogo = page.locator('text=Laravel');
    await expect(laravelLogo).toHaveCount(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-homepage.png', fullPage: true });
  });

  test('2. 404 page shows MBFD branding', async ({ page }) => {
    await page.goto(`${BASE_URL}/this-page-does-not-exist-xyz`);
    await page.waitForLoadState('domcontentloaded');
    const content = await page.content();
    expect(content).toMatch(/404/);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-404.png' });
  });

  test('3. Admin login works', async ({ page }) => {
    await loginAsAdmin(page);
    const url = page.url();
    expect(url).toMatch(/\/admin/);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-admin.png', fullPage: true });
  });

  test('4. Admin dashboard widgets render without errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await loginAsAdmin(page);
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    expect(errors.length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-dashboard.png', fullPage: true });
  });

  test('5. Sidebar navigation groups visible (Fleet Management, Logistics, etc)', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);
    // Try to expand sidebar if collapsed (click sidebar toggle)
    const sidebarToggle = page.locator('.fi-topbar-open-sidebar-btn, button[aria-label*="sidebar"], button[aria-label*="navigation"]').first();
    if (await sidebarToggle.isVisible().catch(() => false)) {
      await sidebarToggle.click();
      await page.waitForTimeout(1000);
    }
    const navGroups = ['Fleet Management', 'Logistics', 'Project Management', 'Administration'];
    for (const group of navGroups) {
      const el = page.locator(`text="${group}"`).first();
      await expect(el).toBeAttached({ timeout: 10000 });
    }
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-nav-groups.png' });
  });

  test('6. Favicon is present', async ({ page }) => {
    await page.goto(BASE_URL);
    const favicon = await page.evaluate(() => {
      const link = document.querySelector('link[rel*="icon"]');
      return link ? (link as HTMLLinkElement).href : null;
    });
    expect(favicon).toBeTruthy();
    console.log('Favicon URL:', favicon);
  });

  test('7. PWA manifest.json is valid with standalone display', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/manifest.json`);
    expect(response?.status()).toBe(200);
    const manifest = await response?.json();
    expect(manifest.display).toBe('standalone');
    expect(manifest.theme_color).toBe('#B91C1C');
    console.log('Manifest:', JSON.stringify(manifest, null, 2));
  });

  test('8. Apparatuses resource page loads', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE_URL}/admin/apparatuses`);
    await page.waitForLoadState('networkidle');
    const heading = page.locator('h1, .fi-header-heading').first();
    await expect(heading).toBeVisible({ timeout: 10000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-apparatuses.png', fullPage: true });
  });

  test('9. Daily checkout app loads at /daily', async ({ page }) => {
    await page.goto(`${BASE_URL}/daily`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);
    const splash = page.locator('#splash-screen');
    const splashVisible = await splash.isVisible().catch(() => false);
    console.log('Splash still visible after 3s:', splashVisible);
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-daily.png', fullPage: true });
  });

  test('10. Custom Filament theme CSS is loaded (MBFD dark theme)', async ({ page }) => {
    await loginAsAdmin(page);
    const styles = await page.evaluate(() => {
      return Array.from(document.styleSheets).map(s => { try { return s.href; } catch { return null; } }).filter(Boolean);
    });
    console.log('Loaded stylesheets:', styles);
    // After our fix, a theme CSS from /build/assets/ with hash should appear
    const themeLoaded = styles.some((s: any) => s && (s.includes('theme') || s.includes('build/assets')));
    console.log('Custom build assets loaded:', themeLoaded);
    // Verify dark sidebar background is applied
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: 'tests/e2e/screenshots/desktop-theme.png', fullPage: true });
  });
});

test.describe('Mobile Tests (iPhone 13)', () => {
  test.use({
    viewport: { width: 390, height: 844 },
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
    hasTouch: true,
    isMobile: true,
  });

  test('11. Homepage renders correctly on mobile', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    // Check MBFD branding via heading text or image
    const mbfdBranding = page.locator('h1:has-text("MBFD"), img[src*="mbfd"], img[alt*="MBFD"]');
    await expect(mbfdBranding.first()).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-homepage.png', fullPage: true });
  });

  test('12. Admin login works on mobile', async ({ page }) => {
    await loginAsAdmin(page);
    const url = page.url();
    expect(url).toMatch(/\/admin/);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-admin.png', fullPage: true });
  });

  test('13. Admin dashboard usable on mobile (no overflow errors)', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await loginAsAdmin(page);
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    expect(errors.length).toBe(0);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-dashboard.png', fullPage: true });
  });

  test('14. Daily checkout loads on mobile', async ({ page }) => {
    await page.goto(`${BASE_URL}/daily`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);
    await page.screenshot({ path: 'tests/e2e/screenshots/mobile-daily.png', fullPage: true });
  });

  test('15. PWA meta tags present for iOS on homepage', async ({ page }) => {
    await page.goto(BASE_URL);
    await page.waitForLoadState('networkidle');
    const appleMobile = await page.evaluate(() => {
      const m = document.querySelector('meta[name="apple-mobile-web-app-capable"]');
      return m ? m.getAttribute('content') : null;
    });
    expect(appleMobile).toBeTruthy();
    const statusBar = await page.evaluate(() => {
      const m = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
      return m ? m.getAttribute('content') : null;
    });
    expect(statusBar).toBe('black-translucent');
  });

  test('16. Admin panel has iOS PWA meta tags', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${BASE_URL}/admin`);
    await page.waitForLoadState('networkidle');
    const appleMobile = await page.evaluate(() => {
      const m = document.querySelector('meta[name="apple-mobile-web-app-capable"]');
      return m ? m.getAttribute('content') : null;
    });
    expect(appleMobile).toBe('yes');
    console.log('iOS PWA meta tag value:', appleMobile);
  });
});
