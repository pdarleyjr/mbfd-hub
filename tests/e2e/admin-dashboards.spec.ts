import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

/*
 * Admin dashboards acceptance: layout contract per viewport, pager, honest freshness, action panel and
 * display-mode isolation. Rows are synthetic fixtures (tests/Support/Dashboards), never production data.
 */
const views = [
  { slug: 'overview', path: '/admin', label: 'Admin Overview', position: '1 of 3', heading: 'Admin Overview' },
  { slug: 'vehicle-checkout', path: '/admin/dashboards/vehicle-checkout', label: 'Vehicle Checkout', position: '2 of 3', heading: 'Vehicle Checkout Dashboard' },
  { slug: 'station-operations', path: '/admin/dashboards/station-operations', label: 'Station Operations', position: '3 of 3', heading: 'Station Operations Dashboard' },
] as const;

const screensDir = process.env.ADMIN_DASHBOARDS_SCREENS_DIR ?? 'test-results/admin-dashboards/screens';
const screenshotSizes = new Set(['390x844', '768x1024', '1366x768', '1920x1080']);

async function openView(page: Page, path: string) {
  const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.locator('.mbfd-dash-board')).toBeVisible();
  await expect(page.locator('[data-freshness]')).toHaveAttribute('data-state', 'live');
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
}

async function overflow(page: Page) {
  return page.evaluate(() => {
    const doc = document.scrollingElement as HTMLElement;
    return { scrollHeight: doc.scrollHeight, scrollWidth: doc.scrollWidth, innerHeight: window.innerHeight, innerWidth: window.innerWidth };
  });
}

for (const view of views) {
  test(`${view.label}: layout contract, labels and screenshot`, async ({ page }, testInfo) => {
    const { width, height } = page.viewportSize()!;
    await openView(page, view.path);

    await expect(page.getByRole('heading', { level: 1, name: view.heading })).toBeVisible();
    await expect(page.locator('.mbfd-dash-pager__label')).toContainText(view.label);
    await expect(page.locator('.mbfd-dash-pager__label')).toContainText(view.position);
    await expect(page.locator('.mbfd-dash-banner')).toContainText('America/New_York · records at 08:00 begin the new display day');
    await expect(page.locator('[data-freshness]')).toHaveText('Live');
    await expect(page.locator('[data-issue-ref]').first()).toBeVisible();

    const metrics = await overflow(page);
    expect(metrics.scrollWidth, 'no horizontal page overflow').toBeLessThanOrEqual(metrics.innerWidth);
    if (width >= 1366) {
      expect(metrics.scrollHeight, 'desktop dashboards fit the viewport').toBeLessThanOrEqual(metrics.innerHeight + 1);
    }

    // Every interactive control on a phone or tablet is at least 44px tall.
    if (width < 1024) {
      const small = await page.locator('.mbfd-dash :is(button, a[href], [role="link"])').evaluateAll(nodes => nodes
        .filter(node => (node as HTMLElement).offsetParent !== null)
        .map(node => node.getBoundingClientRect())
        .filter(rect => rect.height < 43.5).length);
      expect(small).toBe(0);
    }

    if (screenshotSizes.has(testInfo.project.name)) {
      mkdirSync(screensDir, { recursive: true });
      await page.screenshot({ path: join(screensDir, `${view.slug}-${width}x${height}.png`), fullPage: width < 1024 });
    }
  });
}

test('pager links are real links with explicit labels and disabled ends', async ({ page }) => {
  await openView(page, '/admin');
  await expect(page.getByRole('link', { name: 'No previous dashboard' })).toHaveAttribute('aria-disabled', 'true');
  await page.getByRole('link', { name: 'Next dashboard: Vehicle Checkout (2 of 3)' }).click();
  await expect(page).toHaveURL(/\/admin\/dashboards\/vehicle-checkout$/);
  await page.getByRole('link', { name: 'Next dashboard: Station Operations (3 of 3)' }).click();
  await expect(page).toHaveURL(/\/admin\/dashboards\/station-operations$/);
  await expect(page.getByRole('link', { name: 'No next dashboard' })).toHaveAttribute('aria-disabled', 'true');
  await page.goBack();
  await expect(page).toHaveURL(/\/admin\/dashboards\/vehicle-checkout$/);
  await expect(page.locator('.mbfd-dash-pager__label')).toContainText('2 of 3');
});

test('filter chips narrow the list with exact counts', async ({ page }) => {
  await openView(page, '/admin/dashboards/vehicle-checkout');
  const marine = page.getByRole('button', { name: /^Marine · \d+ open$/ });
  await marine.click();
  await expect(marine).toHaveAttribute('aria-pressed', 'true');
  await expect(page).toHaveURL(/filter=marine/);
  await expect(page.locator('[data-card="priority-apparatus"] [data-issue-ref]')).toHaveCount(1);
});

test('the Overview shows apparatus, stations and personnel separately or together', async ({ page }) => {
  await openView(page, '/admin');
  const lanes = ['lane-vehicle', 'lane-station', 'lane-messages'];
  for (const lane of lanes) await expect(page.locator(`[data-card="${lane}"]`)).toBeVisible();

  for (const [name, lane, query] of [['Apparatus', 'lane-vehicle', 'apparatus'], ['Stations', 'lane-station', 'stations'], ['Personnel', 'lane-messages', 'personnel']] as const) {
    const chip = page.getByRole('button', { name: new RegExp(`^${name} · \\d+`) });
    await chip.click();
    await expect(chip).toHaveAttribute('aria-pressed', 'true');
    await expect(page).toHaveURL(new RegExp(`filter=${query}`));
    await expect(page.locator(`[data-card="${lane}"]`)).toBeVisible();
    for (const other of lanes.filter(item => item !== lane)) await expect(page.locator(`[data-card="${other}"]`)).toHaveCount(0);
    const metrics = await overflow(page);
    expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.innerWidth);
  }

  await page.getByRole('button', { name: /^All · \d+/ }).click();
  for (const lane of lanes) await expect(page.locator(`[data-card="${lane}"]`)).toBeVisible();
});

test('one unit is one row on the dashboard, and its page fits the window', async ({ page }, testInfo) => {
  const { width, height } = page.viewportSize()!;
  await openView(page, '/admin');
  const unit = page.locator('[data-card="lane-vehicle"] [data-group-key="apparatus:2"]');
  await expect(unit).toHaveCount(1);
  await expect(unit).toContainText('E 2');
  await expect(unit).toContainText(/\d+ open issues/);
  await expect(page.locator('[data-card="lane-vehicle"]')).not.toContainText('Missing: Gated Wye');

  if (width < 1024) {
    // A stacked Overview holds five rows per list; the rest are one tap away.
    await expect(page.locator('[data-card="lane-vehicle"] .mbfd-dash-row:visible')).toHaveCount(5);
    if (width < 640) {
      // A row stays short enough that a phone screen shows a real list, not one blown-up item.
      const tallest = await page.locator('.mbfd-dash-row:visible').evaluateAll(rows => Math.max(...rows.map(row => row.getBoundingClientRect().height)));
      expect(tallest).toBeLessThanOrEqual(110);
    }
    await page.locator('[data-card="lane-vehicle"] .mbfd-dash-card__more').click();
    await expect(page).toHaveURL(/filter=apparatus/);
    await expect(page.locator('[data-card="lane-station"]')).toHaveCount(0);
    expect(await page.locator('[data-card="lane-vehicle"] .mbfd-dash-row:visible').count()).toBeGreaterThan(5);
    await page.goBack();
    await expect(unit).toBeVisible();
  }

  if (screenshotSizes.has(testInfo.project.name)) {
    mkdirSync(screensDir, { recursive: true });
    await page.screenshot({ path: join(screensDir, `overview-grouped-${width}x${height}.png`), fullPage: width < 1024 });
  }

  await unit.click();
  await expect(page).toHaveURL(/dashboards\/item\?group=apparatus%3A2/);
  await expect(page.getByRole('heading', { level: 1, name: 'E 2' })).toBeVisible();
  await expect(page.locator('[data-issue-group] .mbfd-dash-group__row')).toHaveCount(8);

  const metrics = await overflow(page);
  expect(metrics.scrollWidth, 'no horizontal page overflow').toBeLessThanOrEqual(metrics.innerWidth);
  if (width >= 1366) expect(metrics.scrollHeight, 'desktop page fits the viewport').toBeLessThanOrEqual(metrics.innerHeight + 1);
  if (width < 1024) {
    const small = await page.locator('.mbfd-dash :is(button, a[href], label)').evaluateAll(nodes => nodes
      .filter(node => (node as HTMLElement).offsetParent !== null)
      .map(node => node.getBoundingClientRect())
      .filter(rect => rect.height < 43.5).length);
    expect(small).toBe(0);
  }
  if (screenshotSizes.has(testInfo.project.name)) {
    await page.screenshot({ path: join(screensDir, `item-page-${width}x${height}.png`), fullPage: width < 1024 });
  }

  // Any one issue still opens its own panel from here.
  await page.locator('[data-issue-group] [data-issue-ref="fixture_engine:203"]').click();
  await expect(page.locator('[data-issue-panel]')).toBeVisible();
  await expect(page.locator('[data-issue-panel]').getByRole('heading', { level: 2 })).toContainText('Gated Wye');
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-issue-panel]')).toBeHidden();
});

test('one action reaches every selected issue on a unit', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== '1440x900', 'Mutating flow runs once.');
  await page.goto('/admin/dashboards/item?group=apparatus%3A2');
  const section = page.locator('[data-group-section="fixture_engine"]');
  await expect(section.getByRole('button', { name: 'Acknowledge 8' })).toBeVisible();

  await section.getByRole('checkbox', { name: 'Select Missing: Hydrant Wrench' }).uncheck();
  await section.getByRole('button', { name: 'Acknowledge 7' }).click();
  await expect(page.locator('[data-group-notice]')).toContainText('7 issues updated');
  await expect(section.locator('.mbfd-dash-group__row', { hasText: 'Gated Wye' })).toContainText('In progress');
  await expect(section.locator('.mbfd-dash-group__row', { hasText: 'Hydrant Wrench' })).toContainText('New');

  await openView(page, '/admin');
  await expect(page.locator('[data-group-key="apparatus:2"]')).toContainText('8 open issues');
});

test('freshness says Offline without a network and never fakes Live', async ({ page, context }) => {
  await openView(page, '/admin');
  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await expect(page.locator('[data-freshness]')).toHaveText('Offline');
  await context.setOffline(false);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(page.locator('[data-freshness]')).toHaveText('Live');
});

test('action panel: opens as a dialog, saves, and returns focus', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== '1440x900' && testInfo.project.name !== '390x844', 'Mutating flow runs on one desktop and one phone size.');
  await openView(page, '/admin/dashboards/vehicle-checkout');

  const row = page.locator('[data-card="priority-apparatus"] [data-issue-ref="fixture_apparatus:108"]');
  await row.click();
  const dialog = page.locator('[data-issue-panel]');
  await expect(dialog).toHaveAttribute('role', 'dialog');
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('heading', { level: 2 })).toContainText('Rescue 31');
  await expect(dialog).toContainText('Synthetic test data');
  expect(await page.evaluate(() => document.querySelector('[data-issue-panel]')?.contains(document.activeElement))).toBe(true);

  // Escape closes and focus returns to the row that opened it.
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(row).toBeFocused();

  await row.click();
  await expect(dialog.locator('[data-panel-action="reply"]')).toBeVisible();
  const reply = dialog.locator('[data-panel-action="reply"]');
  await reply.getByRole('textbox', { name: /Reply/ }).fill('Fixture reply from the browser suite');
  await reply.getByRole('button', { name: 'Send reply' }).click();
  await expect(dialog.locator('[data-panel-notice]')).toHaveText('Reply sent.');
  await expect(dialog).toContainText('Reply: Fixture reply from the browser suite');

  if (testInfo.project.name === '1440x900' || testInfo.project.name === '390x844') {
    mkdirSync(screensDir, { recursive: true });
    await page.screenshot({ path: join(screensDir, `action-panel-${testInfo.project.name}.png`) });
  }

  await dialog.getByRole('button', { name: 'Close panel' }).click();
  await expect(dialog).toBeHidden();
});

test('display mode renders only the wall board, with no dashboard UI or panel', async ({ page }) => {
  const response = await page.goto('/admin?display=1', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.locator('body')).toHaveClass(/mbfd-command-display/);
  await expect(page.getByText('Exit Display Mode')).toBeVisible();
  await expect(page.locator('.mbfd-dash')).toHaveCount(0);
  await expect(page.locator('[data-issue-panel]')).toHaveCount(0);
  await expect(page.locator('[data-issue-ref]')).toHaveCount(0);
});

test('a member without admin access cannot open the dashboards', async ({ browser }) => {
  const context = await browser.newContext({ storageState: 'test-results/admin-dashboards-auth/member.json', serviceWorkers: 'block' });
  const page = await context.newPage();
  for (const path of ['/admin', '/admin/dashboards/vehicle-checkout', '/admin/dashboards/station-operations', '/admin/logistics-board']) {
    const response = await page.goto(`http://127.0.0.1:8133${path}`);
    expect(response?.status(), path).toBe(403);
  }
  await context.close();
});
