import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { protectedUiEnvironment } from '../../playwright.protected-ui.config';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';
import { localPhpBinary } from './support/test-environment';

const PAGE = '/daily-roster';

function publishRoster(name: string): void {
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), ['tests/e2e/support/daily-roster-fixture.php', 'publish', name], {
    cwd: process.cwd(), stdio: 'inherit', env: protectedUiEnvironment,
  });
}

function viewportWidth(testInfo: TestInfo): number {
  return testInfo.project.use.viewport!.width;
}

async function expectAccessible(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).include('main').analyze();
  expect(results.violations.map(({ id, impact, nodes }) => ({ id, impact, nodes: nodes.map(node => node.target) }))).toEqual([]);
}

async function openRoster(page: Page): Promise<void> {
  await page.goto(PAGE);
  await expect(page.getByRole('heading', { level: 1, name: 'Daily Roster' })).toBeVisible();
  await page.waitForLoadState('networkidle');
}

const search = (page: Page) => page.getByRole('searchbox', { name: 'Search' });
const rows = (page: Page) => page.locator('[data-roster-row]:visible');
const sections = (page: Page) => page.locator('[data-roster-section]');

test('fits the viewport, stays accessible and shows real unit images', async ({ page }, testInfo) => {
  await openRoster(page);
  const width = viewportWidth(testInfo);

  await expectNoHorizontalOverflow(page);
  await expectAccessible(page);

  await expect(page.locator('[data-roster-freshness]')).toHaveText('Data current');
  await expect(page.locator('.roster-summary')).toContainText('Entries');
  await expect(page.locator('.roster-summary')).not.toContainText('Personnel');

  // Table on desktop, cards below it; one DOM either way.
  const display = await page.locator('.roster-table').first().evaluate(element => getComputedStyle(element).display);
  expect(display).toBe(width >= 1024 ? 'table' : 'grid');
  const tracks = await page.locator('.roster-table').first().evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length);
  if (width < 1024) expect(tracks).toBe(width >= 640 ? 2 : 1);

  // Nothing is clipped: long certifications and names wrap, never ellipsize.
  const clipped = await page.locator('.roster-member__certifications, .roster-member__name, .roster-pill').evaluateAll(elements => elements
    .filter(element => element.scrollWidth > element.clientWidth + 1 || getComputedStyle(element).textOverflow === 'ellipsis')
    .map(element => element.textContent));
  expect(clipped).toEqual([]);

  // Cutouts for named units, a small photo in a well for 300 and Captain 5, a pictogram otherwise.
  const unit = (title: string) => page.locator('[data-roster-section]', { hasText: title }).locator('.roster-unit');
  await expect(unit('Station 1 / Engine 1')).toHaveAttribute('data-unit-kind', 'cutout');
  await expect(unit('Division Chief / Division Chief 300')).toHaveAttribute('data-unit-kind', 'photo');
  await expect(unit('Float / Rescue Float')).toHaveAttribute('data-unit-kind', 'pictogram');
  await expect(unit('Station 2 / Air Truck 810')).toHaveAttribute('data-unit-kind', 'pictogram');
  await expect(unit('Float / Rescue Float').locator('img')).toHaveCount(0);
  await unit('Station 1 / Engine 1').scrollIntoViewIfNeeded();
  await expect.poll(() => unit('Station 1 / Engine 1').locator('img').evaluate(element => (element as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
  await expect(unit('Station 1 / Engine 1').locator('img')).toHaveAttribute('alt', '');
  await expect(unit('Station 1 / Engine 1').locator('img')).toHaveAttribute('loading', 'lazy');
  await expect(unit('Division Chief / Division Chief 300').locator('img')).not.toHaveAttribute('loading', 'lazy');

  // Vacancies and unknown codes read as what they are.
  await expect(page.locator('.roster-row--vacant', { hasText: 'Open seat' })).toContainText('TeleStaff shows ?,?');
  await expect(page.locator('.roster-row', { hasText: 'PILOT, IAN' })).toContainText('Status not recognized');

  // The legend only lists what is on this roster and is collapsed on phones.
  const legend = page.locator('[data-roster-legend]');
  await expect(legend).toHaveJSProperty('open', width >= 640);
  await legend.locator('summary').click();
  await legend.locator('summary').click();
  await expect(legend).toHaveJSProperty('open', width >= 640);
  await expect(legend).not.toContainText('MILNP');
  await expect(legend).toContainText('A-Day');

  // Controls are comfortable to touch.
  const small = await page.locator('main button:visible, main a.hub-btn:visible, main summary:visible, main input:visible, main select:visible').evaluateAll(elements => elements
    .filter(element => element.getBoundingClientRect().height < 44)
    .map(element => element.outerHTML.slice(0, 80)));
  expect(small).toEqual([]);

  await expect(page.getByRole('button', { name: /export|email|print/i })).toHaveCount(0);

  if ([390, 1440].includes(width)) {
    const directory = process.env.DAILY_ROSTER_SHOTS ?? testInfo.outputPath('shots');
    mkdirSync(directory, { recursive: true });
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: join(directory, `roster-${width}-top.png`), animations: 'disabled' });
    await page.locator('[data-roster-section]').evaluateAll(elements => elements.forEach(element => element.querySelectorAll('img').forEach(image => { image.loading = 'eager'; })));
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: join(directory, `roster-${width}-full.png`), fullPage: true, animations: 'disabled' });
  }
});

test('holds up at 200 percent zoom', async ({ page }, testInfo) => {
  test.skip(viewportWidth(testInfo) !== 1440, 'Each size is reached by halving a common desktop viewport.');
  await openRoster(page);
  for (const { width, height } of [{ width: 720, height: 450 }, { width: 640, height: 360 }, { width: 512, height: 384 }]) {
    await page.setViewportSize({ width, height });
    await expectNoHorizontalOverflow(page);
    await expect(rows(page).first()).toBeVisible();
  }
});

test('search and status filters narrow the roster and clearing restores it', async ({ page }) => {
  await openRoster(page);
  const total = await page.locator('[data-roster-row]').count();
  const sectionTotal = await sections(page).count();

  await page.getByRole('button', { name: 'Collapse all' }).click();
  await expect(page.locator('[data-roster-section][open]')).toHaveCount(0);

  await search(page).fill('sample, brett');
  await expect(rows(page)).toHaveCount(3);
  await expect(page.locator('[data-roster-section]:not([hidden])')).toHaveCount(2);
  await expect(page.locator('[data-roster-section]:not([hidden])[open]')).toHaveCount(2);
  await expect(page.locator('[data-roster-count]')).toHaveText(`Showing 3 of ${total} entries in 2 sections`);
  await expectNoHorizontalOverflow(page);

  await search(page).fill('90009');
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('TRIAL, HANA');

  await search(page).fill('fire boat');
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('GAMMA, KAI');

  await search(page).fill('nobody like this');
  await expect(page.locator('[data-roster-no-match]')).toBeVisible();
  await expect(rows(page)).toHaveCount(0);

  await page.getByRole('button', { name: 'Clear' }).click();
  await expect(search(page)).toHaveValue('');
  await expect(page.locator('[data-roster-no-match]')).toBeHidden();
  await expect(page.locator('[data-roster-section]:not([hidden])')).toHaveCount(sectionTotal);
  await expect(page.locator('[data-roster-section][open]')).toHaveCount(0);

  await page.getByRole('button', { name: 'Expand all' }).click();
  await expect(rows(page)).toHaveCount(total);

  await page.getByLabel('Status').selectOption('Open seat');
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('Open seat');
  await page.getByLabel('Status').selectOption('');
  await expect(rows(page)).toHaveCount(total);
});

test('a new roster swaps in without a page reload and keeps the member where they were', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the swap.');
  await openRoster(page);
  await page.evaluate(() => { (window as unknown as { rosterMarker: string }).rosterMarker = 'same page'; });
  let navigations = 0;
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations += 1; });

  // Unchanged version: the server answers 304 and nothing moves.
  const unchanged = page.waitForResponse(response => response.url().includes('/api/daily-roster/version'));
  await page.getByRole('button', { name: 'Refresh' }).click();
  const notModified = await unchanged;
  expect(notModified.status()).toBe(304);
  expect(notModified.headers()['cache-control']).toContain('no-store');
  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster is up to date');

  // Member state: a search, one section closed, scrolled down.
  const name = `SWAPPED ${viewportWidth(testInfo)}, MEMBER`;
  await search(page).fill('rescue 1');
  await page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' }).locator('summary').click();
  await expect(page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' })).not.toHaveJSProperty('open', true);
  await page.evaluate(() => window.scrollTo(0, 120));
  const scrollBefore = await page.evaluate(() => window.scrollY);

  publishRoster(name);
  await page.getByRole('button', { name: 'Refresh' }).click();

  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster updated', { timeout: 30_000 });
  await expect(page.locator('[data-roster-announce]')).toHaveText('Roster updated.');
  await expect(page.getByText(name)).toHaveCount(1);
  await expect(search(page)).toHaveValue('rescue 1');
  await expect(page.locator('[data-roster-section]:not([hidden])')).toHaveCount(1);
  expect(await page.evaluate(() => (window as unknown as { rosterMarker: string }).rosterMarker)).toBe('same page');
  expect(navigations).toBe(0);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(scrollBefore);
  await expectNoHorizontalOverflow(page);

  // A tab coming back to the foreground checks on its own.
  const second = `${name} AGAIN`;
  publishRoster(second);
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await expect(page.getByText(second)).toHaveCount(1, { timeout: 30_000 });
  expect(navigations).toBe(0);
});

test('offline, failed and expired checks keep the roster and say so', async ({ page, context }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the failure paths.');
  await openRoster(page);
  const firstRow = await rows(page).first().textContent();

  await context.setOffline(true);
  await expect(page.locator('[data-roster-connection]')).toHaveText('Requires connection');
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(page.locator('[data-roster-connection]')).toHaveText('Requires connection');
  expect(await rows(page).first().textContent()).toBe(firstRow);
  await context.setOffline(false);

  await page.route('**/api/daily-roster/version', route => route.fulfill({ status: 500, body: '{}' }));
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expect(page.locator('[data-roster-connection]')).toContainText('Could not check for updates');
  expect(await rows(page).first().textContent()).toBe(firstRow);
  await page.unroute('**/api/daily-roster/version');

  await page.route('**/api/daily-roster/version', route => route.fulfill({ status: 401, body: '{}' }));
  const login = page.waitForRequest(request => new URL(request.url()).pathname === '/login');
  await page.getByRole('button', { name: 'Refresh' }).click();
  await login;
});

test('works from the keyboard', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the keyboard path.');
  await openRoster(page);

  await page.keyboard.press('Tab');
  await expect(page.locator('.hub-skip-link')).toBeFocused();

  await search(page).focus();
  await page.keyboard.type('trial');
  await expect(rows(page)).toHaveCount(1);
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Status')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Clear' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(search(page)).toBeFocused();
  await expect(search(page)).toHaveValue('');

  const summary = sections(page).first().locator('summary');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(sections(page).first()).toHaveJSProperty('open', false);
  await page.keyboard.press('Enter');
  await expect(sections(page).first()).toHaveJSProperty('open', true);
});

test('home has the Daily Roster tile and earlier rosters are one link away', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover navigation.');
  await page.goto('/');
  await page.getByRole('link', { name: /Daily Roster/ }).click();
  await expect(page).toHaveURL(/\/daily-roster$/);

  await page.getByRole('link', { name: /^Previous:/ }).click();
  await expect(page).toHaveURL(/\?date=\d{4}-\d{2}-\d{2}$/);
  await expect(page.getByText('YESTERDAY, NOA')).toBeVisible();
  await expect(page.locator('[data-roster-freshness]')).toHaveText('Earlier roster');
  await expectNoHorizontalOverflow(page);
  await page.getByRole('link', { name: 'Go to the current roster' }).click();
  await expect(page).toHaveURL(/\/daily-roster$/);
});

test('an unknown date falls back to the current roster', async ({ page }) => {
  await page.goto(`${PAGE}?date=1999-01-01`);
  await expect(page.locator('[data-roster-freshness]')).toHaveText('Data current');
  await expect(page.getByText('TRIAL, HANA')).toBeVisible();
});
