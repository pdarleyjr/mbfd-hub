import { expect, test } from '@playwright/test';

/*
 * Home layout contract (WP6a):
 *   npx playwright test -c playwright.shell-alignment.config.ts home-layout
 * One column below 1280px; from 1280px up Department Updates take ~62% and Quick Access is a
 * compact glass rail on the right. The document never overflows horizontally.
 */

test('Home lays out as one column below 1280px and as updates plus a Quick Access rail from 1280px', async ({ page }, testInfo) => {
  await page.goto('/', { waitUntil: 'load' });
  const viewport = page.viewportSize()!;
  const layout = page.locator('.home-layout');
  const updates = page.locator('[data-home-section="department-updates"]');
  const quick = page.locator('[data-home-section="quick-access"]');
  await expect(page.locator('[data-home-column="primary"]')).toBeVisible();
  await expect(updates).toBeVisible();
  await expect(quick).toBeVisible();

  const [layoutBox, updateBox, quickBox] = await Promise.all([layout.boundingBox(), updates.boundingBox(), quick.boundingBox()]);
  expect(layoutBox && updateBox && quickBox).toBeTruthy();

  if (viewport.width >= 1280) {
    expect(quickBox!.x).toBeGreaterThanOrEqual(updateBox!.x + updateBox!.width - 1);
    expect(Math.abs(quickBox!.y - updateBox!.y)).toBeLessThanOrEqual(2);
    const share = (updateBox!.width) / layoutBox!.width;
    expect(share).toBeGreaterThan(0.55);
    expect(share).toBeLessThan(0.68);
    const tiles = page.locator('[data-quick-access-card]');
    const count = await tiles.count();
    expect(count).toBeGreaterThan(0);
    const xs = await tiles.evaluateAll(nodes => nodes.map(node => Math.round(node.getBoundingClientRect().x)));
    expect(new Set(xs).size, 'rail tiles stack in a single column').toBe(1);
  } else {
    expect(Math.abs(updateBox!.width - layoutBox!.width)).toBeLessThanOrEqual(1);
    expect(updateBox!.y + updateBox!.height).toBeLessThanOrEqual(quickBox!.y + 1);
  }

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'document must not scroll horizontally').toBeLessThanOrEqual(0);
  await page.screenshot({ path: testInfo.outputPath('home.png') });
});

test('the 404 page is a glass card with the shared primary button and no horizontal overflow', async ({ page }) => {
  const response = await page.goto('/wp6-no-such-page', { waitUntil: 'load' });
  expect(response?.status()).toBe(404);
  const card = page.locator('main.hub-panel');
  await expect(card).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

  const styles = await page.evaluate(() => {
    const card = getComputedStyle(document.querySelector('main.hub-panel')!);
    const button = getComputedStyle(document.querySelector('a.hub-btn--primary')!);
    return {
      radius: card.borderTopLeftRadius,
      background: card.backgroundColor,
      buttonBackground: button.backgroundColor,
      buttonRadius: button.borderTopLeftRadius,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(styles.radius).toBe('12px');
  expect(styles.background).not.toBe('rgba(0, 0, 0, 0)');
  expect(styles.buttonBackground).toBe('rgb(30, 78, 140)');
  expect(styles.buttonRadius).toBe('8px');
  expect(styles.overflow).toBeLessThanOrEqual(0);
});
