import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type Request, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { protectedUiEnvironment } from '../../playwright.protected-ui.config';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';
import { localPhpBinary } from './support/test-environment';

const PAGE = '/daily-roster';

const VERSION_URL = '**/api/daily-roster/version';
const SECTIONS_URL = '**/daily-roster/sections*';
const BACKGROUND_HEADER = 'x-hub-background-refresh';
const LOCAL_TIME = /\b\d{1,2}:\d{2} (AM|PM) (EDT|EST)\b/;

function rosterFixture(...args: string[]): void {
  execFileSync(localPhpBinary('PROTECTED_UI_E2E_PHP'), ['tests/e2e/support/daily-roster-fixture.php', ...args], {
    cwd: process.cwd(), stdio: 'inherit', env: protectedUiEnvironment,
  });
}

function publishRoster(name: string): void {
  rosterFixture('publish', name);
}

const pathIs = (path: string) => (request: Request) => new URL(request.url()).pathname === path;
/** The same path the page's timer takes: the check runs without a member having done anything. */
const backgroundCheck = (page: Page) => page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange')); });
const lastUpdated = (page: Page) => page.locator('[data-roster-fact="updated"]');

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

  // Controls are comfortable to touch. A member's name is a text link whose hit area grows past the line it sits on.
  const small = await page.locator('main button:visible:not([data-roster-member]), main a.hub-btn:visible, main summary:visible').evaluateAll(elements => elements
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

test('the roster starts right under the last update', async ({ page }) => {
  await openRoster(page);

  const updatedBottom = await page.locator('.roster-last-updated').evaluate(element => element.getBoundingClientRect().bottom);
  const firstSectionTop = await sections(page).first().evaluate(element => element.getBoundingClientRect().top);
  expect(firstSectionTop - updatedBottom).toBeLessThanOrEqual(16);
  await expect(sections(page).first()).toBeInViewport();

  await expect(page.getByRole('searchbox')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Expand all|Collapse all/ })).toHaveCount(0);
  const footerTop = await page.locator('.roster-footer').evaluate(element => element.getBoundingClientRect().top);
  const lastSectionBottom = await sections(page).last().evaluate(element => element.getBoundingClientRect().bottom);
  expect(footerTop).toBeGreaterThanOrEqual(lastSectionBottom);
});

test('a name opens the member card, which clears when it closes', async ({ page }) => {
  await openRoster(page);
  const dialog = page.getByRole('dialog', { name: 'Member details' });
  const name = page.getByRole('button', { name: 'TRIAL, HANA' });
  await expect(page.locator('.roster-row--vacant [data-roster-member]')).toHaveCount(0);

  await name.click();
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('[data-roster-card]')).toContainText('TRIAL, HANA');
  await expect(dialog.locator('[data-roster-card]')).toContainText('Engine 1');
  await expect(dialog.locator('.roster-card__certifications')).toContainText('AO');
  await expectNoHorizontalOverflow(page);
  const results = await new AxeBuilder({ page }).include('[data-roster-card-dialog]').analyze();
  expect(results.violations.map(({ id, nodes }) => ({ id, nodes: nodes.map(node => node.target) }))).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(page.locator('[data-roster-card]')).toHaveCount(0);
  await expect(name).toBeFocused();

  // Inside the card nothing closes it; only the button, Escape or a click outside does.
  await name.click();
  await expect(dialog.locator('[data-roster-card]')).toBeVisible();
  await dialog.locator('.roster-card-dialog__inner').click({ position: { x: 4, y: 4 } });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Close' }).click();
  await expect(dialog).toBeHidden();

  // If a roster update replaced the name while the card was open, focus still returns to that member.
  await name.click();
  await expect(dialog.locator('[data-roster-card]')).toBeVisible();
  await page.locator('[data-roster-member="90009"]').evaluate(element => { element.replaceWith(element.cloneNode(true)); });
  await page.keyboard.press('Escape');
  await expect(name).toBeFocused();

  // A session that ended takes the card with it, and never shows the login page inside it.
  await page.route('**/daily-roster/members/**', route => route.fulfill({ status: 401, body: '' }));
  const login = page.waitForRequest(request => new URL(request.url()).pathname === '/login');
  await name.click();
  await login;
});

test('a new roster swaps in without a page reload and keeps the member where they were', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the swap.');
  rosterFixture('age-pointer');
  await openRoster(page);
  await expect(lastUpdated(page)).toHaveText(LOCAL_TIME);
  const updatedBefore = await lastUpdated(page).textContent();
  await page.evaluate(() => { (window as unknown as { rosterMarker: string }).rosterMarker = 'same page'; });
  let navigations = 0;
  page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations += 1; });

  // Unchanged version: the server answers 304 and nothing moves.
  const unchanged = page.waitForResponse(response => response.url().includes('/api/daily-roster/version'));
  await page.getByRole('button', { name: 'Refresh' }).click();
  const notModified = await unchanged;
  expect(notModified.status()).toBe(304);
  expect(notModified.headers()['cache-control']).toContain('no-store');
  // The member asked: this one is real activity.
  expect(notModified.request().headers()[BACKGROUND_HEADER]).toBeUndefined();
  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster is up to date');

  // Member state: one section closed, scrolled down.
  const name = `SWAPPED ${viewportWidth(testInfo)}, MEMBER`;
  await page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' }).locator('summary').click();
  await expect(page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' })).not.toHaveJSProperty('open', true);
  await page.evaluate(() => window.scrollTo(0, 120));
  const scrollBefore = await page.evaluate(() => window.scrollY);

  publishRoster(name);
  const manualFragment = page.waitForRequest(request => pathIs('/daily-roster/sections')(request) || pathIs('/daily-roster/changes')(request));
  // Refresh sits under the roster, and a real click would scroll to it: this checks that the swap itself keeps the place.
  await page.getByRole('button', { name: 'Refresh' }).evaluate(button => (button as HTMLButtonElement).click());
  expect((await manualFragment).headers()[BACKGROUND_HEADER]).toBeUndefined();

  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster updated', { timeout: 30_000 });
  await expect(lastUpdated(page)).not.toHaveText(updatedBefore!);
  await expect(lastUpdated(page)).toHaveText(LOCAL_TIME);
  await expect(page.locator('[data-roster-announce]')).toHaveText('Roster updated.');
  await expect(page.getByText(name)).toHaveCount(1);
  await expect(page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' })).not.toHaveJSProperty('open', true);
  expect(await page.evaluate(() => (window as unknown as { rosterMarker: string }).rosterMarker)).toBe('same page');
  expect(navigations).toBe(0);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(scrollBefore);
  await expectNoHorizontalOverflow(page);

  // A tab coming back to the foreground checks on its own.
  const second = `${name} AGAIN`;
  publishRoster(second);
  const backgroundVersion = page.waitForRequest(pathIs('/api/daily-roster/version'));
  const backgroundFragment = page.waitForRequest(request => pathIs('/daily-roster/sections')(request) || pathIs('/daily-roster/changes')(request));
  await backgroundCheck(page);
  expect((await backgroundVersion).headers()[BACKGROUND_HEADER]).toBe('1');
  expect((await backgroundFragment).headers()[BACKGROUND_HEADER]).toBe('1');
  await expect(page.getByText(second)).toHaveCount(1, { timeout: 30_000 });
  expect(navigations).toBe(0);
});

test('one changed seat replaces only that spot and never downloads the whole roster', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the spot update.');
  // Earlier tests publish into the same database, so this one starts from a roster it knows.
  rosterFixture('publish-seat', `BASELINE ${viewportWidth(testInfo)}, MEMBER`);
  await openRoster(page);
  const whole: string[] = [];
  page.on('request', request => { if (pathIs('/daily-roster/sections')(request)) whole.push(request.url()); });

  // Member state, and a marker on a seat that must survive because it is never touched.
  await page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' }).locator('summary').click();
  const kept = page.locator('[data-roster-section]', { hasText: 'Station 1 / Engine 1' }).locator('[data-roster-seat]').first();
  await kept.evaluate((seat: HTMLElement) => { seat.dataset.marker = 'kept'; });
  await page.evaluate(() => window.scrollTo(0, 200));
  const scrollBefore = await page.evaluate(() => window.scrollY);

  const name = `SPOT ${viewportWidth(testInfo)}, MEMBER`;
  rosterFixture('publish-seat', name);
  // The timer's path, because clicking Refresh would scroll the button back into view on its own.
  const changes = page.waitForResponse(response => pathIs('/daily-roster/changes')(response.request()));
  await backgroundCheck(page);
  const payload = await (await changes).json();

  expect(payload.mode).toBe('changes');
  expect(payload.changed).toBe(1);
  await expect(page.getByText(name)).toHaveCount(1, { timeout: 30_000 });
  await expect(page.locator('[data-roster-section]', { hasText: 'Float / Rescue Float' }).locator('[data-roster-seat].roster-updated')).toHaveCount(1);
  await expect(page.locator('[data-marker="kept"]')).toHaveCount(1);
  await expect(page.locator('[data-roster-section]', { hasText: 'Station 1 / Rescue 1' })).toHaveJSProperty('open', false);
  await expect(page.locator('[data-roster-announce]')).toHaveText('Roster updated.');
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(scrollBefore);
  await expectNoHorizontalOverflow(page);
  expect(whole).toEqual([]);

  // The next export changes nothing: only the times move, and no roster travels at all.
  rosterFixture('age-pointer');
  await page.reload();
  await page.waitForLoadState('networkidle');
  const updatedBefore = await lastUpdated(page).textContent();
  const asked: string[] = [];
  page.on('request', request => {
    if (pathIs('/daily-roster/sections')(request) || pathIs('/daily-roster/changes')(request)) asked.push(request.url());
  });
  rosterFixture('confirm');
  const version = page.waitForResponse(response => pathIs('/api/daily-roster/version')(response.request()));
  await page.getByRole('button', { name: 'Refresh' }).click();
  expect((await version).status()).toBe(200);
  await expect(lastUpdated(page)).not.toHaveText(updatedBefore!);
  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster is up to date');
  expect(asked).toEqual([]);
});

test('the entire roster exports as a PDF and codes wear their TeleStaff colors', async ({ page }, testInfo) => {
  test.skip(![320, 390, 1440].includes(viewportWidth(testInfo)), 'Narrow phone, phone and desktop cover the button.');
  await openRoster(page);

  const overtime = page.locator('.roster-pill--ot').first();
  await expect(overtime).toHaveCSS('background-color', 'rgb(0, 255, 0)');
  await expect(overtime).toHaveCSS('color', 'rgb(0, 0, 0)');
  await expect(page.locator('.roster-pill--aday').first()).toHaveCSS('background-color', 'rgb(0, 142, 229)');
  await expect(page.locator('.roster-pill--exchange').first()).toHaveCSS('background-color', 'rgb(255, 170, 86)');
  await expect(page.locator('.roster-pill--leave').first()).toHaveCSS('background-color', 'rgb(230, 0, 0)');

  const exportLink = page.getByRole('link', { name: 'Export PDF' });
  await expect(exportLink).toBeVisible();
  await expectNoHorizontalOverflow(page);
  await expectAccessible(page);

  const download = page.waitForEvent('download');
  await exportLink.click();
  const file = await download;
  expect(file.suggestedFilename()).toMatch(/^MBFD-Daily-Roster-\d{4}-\d{2}-\d{2}\.pdf$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Daily Roster' })).toBeVisible();
});

test('a page that does not match what the server expects takes the whole roster instead', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the fallback.');
  await openRoster(page);
  // A seat this page cannot find, so the spot update must leave everything alone and fall back.
  await page.locator('[data-roster-section]', { hasText: 'Float / Rescue Float' }).locator('[data-roster-seat]').evaluate((seat: HTMLElement) => seat.remove());

  const name = `FALLBACK ${viewportWidth(testInfo)}, MEMBER`;
  rosterFixture('publish-seat', name);
  const full = page.waitForRequest(pathIs('/daily-roster/sections'));
  await page.getByRole('button', { name: 'Refresh' }).click();
  await full;

  await expect(page.getByText(name)).toHaveCount(1, { timeout: 30_000 });
  await expect(page.locator('[data-roster-connection]')).toHaveText('Roster updated');
});

test('a section this page has not seen renders open and the one the member closed stays closed', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the swap.');
  await openRoster(page);
  const section = (title: string) => page.locator('[data-roster-section]', { hasText: title });
  await section('Station 4 / Fire Boat 5').locator('summary').click();
  await expect(section('Station 4 / Fire Boat 5')).toHaveJSProperty('open', false);

  rosterFixture('publish-section', `NEWSECTION ${viewportWidth(testInfo)}, MEMBER`);
  await page.getByRole('button', { name: 'Refresh' }).click();

  await expect(section('Station 6 / Engine 6')).toHaveJSProperty('open', true, { timeout: 30_000 });
  await expect(section('Station 4 / Fire Boat 5')).toHaveJSProperty('open', false);
  await expect(section('Station 1 / Engine 1')).toHaveJSProperty('open', true);

  // The first roster to arrive on an empty page opens in full, too.
  await page.locator('[data-roster-body]').evaluate((body: HTMLElement) => {
    body.innerHTML = '<p>No roster has been published yet</p>';
  });
  rosterFixture('publish-section', `FIRST ${viewportWidth(testInfo)}, MEMBER`);
  await backgroundCheck(page);
  await expect(sections(page).first()).toBeAttached({ timeout: 30_000 });
  await expect(page.locator('[data-roster-section]:not([open])')).toHaveCount(0);
});

test('an expired session on a background check takes the roster off the screen', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the failure paths.');
  await openRoster(page);
  await expect(rows(page).first()).toBeVisible();

  // Recorded as the page leaves: the roster is gone before the browser reaches sign-in, however slowly that loads.
  await page.evaluate(() => {
    window.addEventListener('pagehide', () => {
      const leaving = {
        rows: document.querySelectorAll('[data-roster-row]').length,
        message: document.querySelector('main')?.textContent?.includes('Your session has ended'),
      };
      sessionStorage.setItem('roster-at-exit', JSON.stringify(leaving));
    });
  });
  await page.route('**/login', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Sign in</h1>' }));
  await page.route(VERSION_URL, route => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Unauthenticated."}' }));
  await backgroundCheck(page);

  await expect(page).toHaveURL(/\/login$/);
  expect(JSON.parse(await page.evaluate(() => sessionStorage.getItem('roster-at-exit') ?? '{}'))).toEqual({ rows: 0, message: true });
});

test('access withdrawn mid-shift removes the roster and leaves a way home', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover the failure paths.');
  const expectRemoved = async () => {
    await expect(page.getByRole('heading', { name: 'You no longer have access to the Daily Roster' })).toBeVisible();
    await expect(page.locator('[data-roster-body]')).toHaveCount(0);
    await expect(page.locator('[data-roster-row]')).toHaveCount(0);
    await expect(page.locator('main').getByRole('link', { name: 'Hub Home' })).toHaveAttribute('href', /^http:\/\/[^/]+\/?$/);
    await expect(page.locator('[data-roster-announce]')).toHaveText('You no longer have access to the Daily Roster');
  };
  const refused = { status: 403, contentType: 'application/json', body: '{"message":"You do not have access to the Daily Roster."}' };

  await openRoster(page);
  await page.route(VERSION_URL, route => route.fulfill(refused));
  await page.getByRole('button', { name: 'Refresh' }).click();
  await expectRemoved();
  await expectAccessible(page);
  await expectNoHorizontalOverflow(page);

  // Nothing keeps asking once the roster is gone, and a later event cannot throw on the missing body.
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  let asked = 0;
  page.on('request', request => { if (pathIs('/api/daily-roster/version')(request)) asked += 1; });
  await backgroundCheck(page);
  await page.evaluate(() => { window.dispatchEvent(new Event('online')); window.dispatchEvent(new Event('beforeprint')); });
  await expectRemoved();
  expect(asked).toBe(0);
  expect(errors).toEqual([]);
  await page.unroute(VERSION_URL);

  // The same when the refusal arrives on the roster request itself.
  await openRoster(page);
  await page.route(VERSION_URL, route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"version":"newer"}' }));
  await page.route(SECTIONS_URL, route => route.fulfill({ status: 403, contentType: 'text/html', body: 'Forbidden' }));
  await backgroundCheck(page);
  await expectRemoved();
});

test('a continuation row groups under its seat and keeps the rank for a screen reader', async ({ page }, testInfo) => {
  await openRoster(page);
  const width = viewportWidth(testInfo);
  const rank = (row: ReturnType<Page['locator']>) => row.locator(width >= 1024 ? '.roster-cell--rank .roster-rank-continued' : '.roster-member__meta .roster-rank-continued');
  const shownWidth = (row: ReturnType<Page['locator']>) => rank(row).evaluate(element => element.getBoundingClientRect().width);
  const seat = page.locator('[data-roster-section]', { hasText: 'Division Chief / Division Chief 300' }).locator('[data-roster-row]');

  // Grouped under the first row, yet the rank is there for a screen reader.
  await expect(seat.nth(0)).toContainText('Division Chief');
  for (const index of [1, 2]) {
    await expect(seat.nth(index).locator('.roster-cell--rank')).toContainText('Division Chief, same seat');
    expect(await shownWidth(seat.nth(index))).toBeLessThanOrEqual(1);
  }
});

test('station-list cards and the 300 page refresh without counting as member activity', async ({ page }, testInfo) => {
  test.skip(viewportWidth(testInfo) !== 1440, 'One width covers the request headers.');
  const summary = page.waitForRequest(pathIs('/api/captain5/summary'));
  await page.goto('/daily/stations');
  expect((await summary).headers()[BACKGROUND_HEADER]).toBeUndefined();
  await page.waitForLoadState('networkidle');

  const refreshed = page.waitForRequest(pathIs('/api/captain5/summary'));
  await backgroundCheck(page);
  expect((await refreshed).headers()[BACKGROUND_HEADER]).toBe('1');

  const shift = page.waitForRequest(pathIs('/api/command-300/shift'));
  await page.goto('/daily/command-300');
  expect((await shift).headers()[BACKGROUND_HEADER]).toBeUndefined();
  await page.waitForLoadState('networkidle');

  const polled = page.waitForRequest(pathIs('/api/command-300/shift'));
  await backgroundCheck(page);
  expect((await polled).headers()[BACKGROUND_HEADER]).toBe('1');

  // The page's Refresh button is the member asking.
  const asked = page.waitForRequest(pathIs('/api/command-300/shift'));
  await page.getByRole('button', { name: 'Refresh' }).click();
  expect((await asked).headers()[BACKGROUND_HEADER]).toBeUndefined();
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

  const name = page.getByRole('button', { name: 'TRIAL, HANA' });
  await name.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog', { name: 'Member details' }).locator('[data-roster-card]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(name).toBeFocused();

  const summary = sections(page).first().locator('summary');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(sections(page).first()).toHaveJSProperty('open', false);
  await page.keyboard.press('Enter');
  await expect(sections(page).first()).toHaveJSProperty('open', true);
});

test('home has the Daily Roster button and earlier rosters are one link away', async ({ page }, testInfo) => {
  test.skip(![390, 1440].includes(viewportWidth(testInfo)), 'Phone and desktop cover navigation.');
  await page.goto('/');
  await page.locator('.hub-page-header__actions').getByRole('link', { name: 'Daily Roster', exact: true }).click();
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
