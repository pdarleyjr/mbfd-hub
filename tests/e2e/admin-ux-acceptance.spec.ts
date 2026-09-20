import { test, expect, type Page } from '@playwright/test';

const navigationLabels = [
  'Dashboard', 'Active Operations', 'Fleet Management', 'Inventory & Logistics', 'Workgroup Management',
  'Station Management', 'Bid Administration', 'Communications', 'Administration', 'Monitoring',
];

async function expectCollapsedNavigation(page: Page): Promise<void> {
  for (const label of navigationLabels) {
    const button = page.getByLabel(label).first();
    await expect(button, `missing ${label} navigation control`).toBeVisible();
    await expect(button).toHaveAttribute('aria-expanded', 'false');
  }
}

test.describe('Admin UX acceptance', () => {
  test('navigation starts collapsed, a single group expands, and legacy state is migrated', async ({ browser, page }) => {
    await page.addInitScript(() => localStorage.setItem('collapsedGroups', '[]'));
    await page.goto('/admin');
    await expectCollapsedNavigation(page);

    const fleet = page.getByLabel('Fleet Management').first();
    await fleet.click();
    await expect(fleet).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByLabel('Inventory & Logistics').first()).toHaveAttribute('aria-expanded', 'false');

    const fresh = await browser.newContext({ storageState: 'tests/e2e/.auth/admin.json' });
    await fresh.addInitScript(() => localStorage.setItem('collapsedGroups', '[]'));
    const freshPage = await fresh.newPage();
    await freshPage.goto('/admin');
    await expectCollapsedNavigation(freshPage);
    await fresh.close();
  });

  for (const viewport of [
    { width: 1366, height: 768 }, { width: 1536, height: 864 },
    { width: 1920, height: 1080 }, { width: 2560, height: 1440 },
  ]) {
    test(`dashboard fits ${viewport.width}x${viewport.height}`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto('/admin');
      const dimensions = await page.evaluate(() => ({
        scrollHeight: document.documentElement.scrollHeight,
        clientHeight: document.documentElement.clientHeight,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      expect(dimensions.scrollHeight).toBeLessThanOrEqual(dimensions.clientHeight + 2);
      expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
      await page.screenshot({ path: `test-results/admin-dashboard-${viewport.width}x${viewport.height}.png`, fullPage: false });
    });
  }

  test('desktop account control identifies the signed-in person and exposes only personal destinations', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.goto('/admin');
    const trigger = page.locator('.fi-user-menu-trigger');
    await expect(trigger).toBeVisible();
    await expect(trigger).toContainText(/\S+/);
    await trigger.click();
    await expect(page.getByRole('link', { name: 'My Account' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Change Password' })).toBeVisible();
    await expect(page.getByRole('button', { name: /sign out/i })).toBeVisible();
  });
});
