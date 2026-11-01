import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { resolve } from 'node:path';
import type { CommandOverview } from '../../resources/js/daily-checkout/src/types';
import { SHIFT_BASE, handleShiftRoute, newShiftServer } from './support/command300-shift';

const stations = [1, 2, 3, 4, 6].map((stationNumber) => ({
  id: stationNumber,
  name: `Station ${stationNumber}`,
  address: `${stationNumber} Test Street`,
  city: 'Miami Beach',
  state: 'FL',
  zip_code: '33139',
  phone: '305-555-0100',
  station_number: stationNumber,
  is_active: true,
  created_at: '2026-08-31T00:00:00.000000Z',
  updated_at: '2026-08-31T00:00:00.000000Z',
}));

const requiredApparatus = {
  id: 101,
  name: 'Engine 1',
  designation: 'E1',
  type: 'engine',
  vehicle_number: 'E1',
  slug: 'engine-1',
  status: 'In Service',
  daily_checkout_requirement: 'required',
};

const unknownApparatus = {
  ...requiredApparatus,
  id: 102,
  name: 'Unclassified Unit',
  designation: 'U1',
  vehicle_number: 'U1',
  slug: 'unclassified-unit',
  daily_checkout_requirement: 'unknown',
};

const emptyStationOverview = {
  shift_window: { starts_at: '2026-10-31T11:00:00+00:00', ends_at: '2026-11-01T12:00:00+00:00', alert_from: '2026-10-31T16:00:00+00:00' },
  units: [],
  checkouts: [],
  requests: [],
  requests_total: 0,
  alert: { active: false, units: [] },
};

async function mockDailySelectorApi(page: Page): Promise<void> {
  await page.route('**/images/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/images/mbfd-official-seal-256.png') {
      return route.fulfill({
        path: resolve('public/images/mbfd-official-seal-256.png'),
        contentType: 'image/png',
      });
    }
    if (path === '/images/icons/checkout-apparatus.svg') {
      return route.fulfill({ path: resolve('public/images/icons/checkout-apparatus.svg'), contentType: 'image/svg+xml' });
    }
    if (/^\/images\/stations\/(station-(1|2|3|4|6)|command-300-wide|captain-5-wide)\.webp$/.test(path)) {
      return route.fulfill({ path: resolve('public', path.slice(1)), contentType: 'image/webp' });
    }

    return route.fulfill({ status: 204 });
  });
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  const shift = newShiftServer();
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;

    // 300: the shift line on the page and on the stations-list card.
    if (path.startsWith(SHIFT_BASE)) return handleShiftRoute(route, path, shift);

    // Captain 5 card: the station list probes the summary; a 200 shows the card in the grid at every viewport.
    if (path === '/api/captain5/summary') {
      return route.fulfill({
        json: {
          access: { view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)' },
          on_duty: { duty_date: '2026-10-23', shift: 'B', source: 'bid_holder', resolved: true, name: 'Responsive Tester', rank: 'Captain', acting_until: null, acting_request_id: null },
          open_issues: 3,
        },
      });
    }

    if (path === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 101, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_101, employee_number: 'E101', name: 'Responsive Tester', rank: 'Captain' },
          offline: { security_version: 1 },
          session: { authenticated: true },
          authorization: { capabilities: { command_300_access: true } },
        },
      });
    }

    if (path === '/api/command-communications/posts') {
      return route.fulfill({ json: { data: [], pinned: [], next_before: null, unread_count: 0 } });
    }
    if (path === '/api/command-communications/summary') {
      return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    }

    if (path === '/api/public/stations') {
      return route.fulfill({ json: { stations } });
    }

    if (path === '/api/public/stations/1') {
      return route.fulfill({
        json: {
          ...stations[0],
          apparatuses: [requiredApparatus, unknownApparatus],
          daily_checkout: {
            required_total: 1,
            checked: 0,
            attention: 0,
            review_pending: 0,
            not_checked: 1,
            completed: 0,
            out_of_service: 0,
            exempt: 0,
            classification_required: 1,
            completion_percent: 0,
            completion_available: true,
            matrix: [
              {
                apparatus_id: requiredApparatus.id,
                state: 'not_checked',
                daily_checkout_requirement: 'required',
                out_of_service: false,
                classification_required: false,
                included_in_required_total: true,
                included_in_completed: false,
                has_pending_submission: false,
                return_checkout_required: false,
                return_checkout_verified: false,
              },
              {
                apparatus_id: unknownApparatus.id,
                state: 'classification_required',
                daily_checkout_requirement: 'unknown',
                out_of_service: false,
                classification_required: true,
                included_in_required_total: false,
                included_in_completed: false,
                has_pending_submission: false,
                return_checkout_required: false,
                return_checkout_verified: false,
              },
            ],
          },
        },
      });
    }

    if (path.endsWith('/requests') || path.endsWith('/service-tickets')) {
      return route.fulfill({ json: { data: [], meta: { total: 0 } } });
    }

    if (path.endsWith('/overview')) {
      return route.fulfill({ json: emptyStationOverview });
    }

    if (path.endsWith('/operational-alerts')) {
      return route.fulfill({ json: { alerts: [] } });
    }

    // The issues page and the inspection read this; nothing is on record for the fixture units.
    if (path.endsWith('/findings')) {
      return route.fulfill({ json: { as_of: '2026-09-21T13:00:00Z', open: [], notes: [], closed_count: 0 } });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked Daily API route: ${path}` } });
  });
}

function captureBrowserQuality(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });
  page.on('pageerror', (error) => consoleErrors.push(error.message));
  page.on('response', (response) => {
    const url = new URL(response.url());
    const isRequiredLocalResource = url.origin === new URL(page.url()).origin
      && (url.pathname.startsWith('/daily/assets/') || url.pathname.startsWith('/api/'));

    if (isRequiredLocalResource && response.status() >= 400) {
      failedRequests.push(`${response.status()} ${url.pathname}`);
    }
  });

  return { consoleErrors, failedRequests };
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));

  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
}

function commandOverview(): CommandOverview {
  return {
    generated_at: new Date().toISOString(),
    total: stations.length,
    stations: stations.map((station, index) => ({
      ...station,
      id: 29 + index,
      apparatus_count: 1,
      open_defects: index,
      open_service_tickets: 1,
      last_checkout_at: null,
      daily_checkout: { required_total: 1, completed: 0, not_checked: 1, review_pending: 0, out_of_service: 0, completion_percent: 0 },
      apparatuses: [{
        id: 100 + index, name: `Command test unit ${station.station_number}`, unit_id: `T${station.station_number}`,
        type: 'engine', status: 'In Service', service_state: 'in_service', open_defects: index,
        open_service_tickets: 1, daily_checkout_state: 'not_checked', last_checkout_at: null,
      }],
    })),
  };
}

test('Command 300 tabs open on Communications, show one station at a time and preserve selection through refresh and profile navigation', async ({ page }, testInfo) => {
  await mockDailySelectorApi(page);
  const quality = captureBrowserQuality(page);
  const data = commandOverview();
  let requests = 0;
  await page.route('**/api/public/command/overview', route => {
    requests += 1;
    return route.fulfill({ json: data });
  });
  await page.route('**/api/public/stations/29', route => route.fulfill({ json: { ...stations[0], id: 29, apparatuses: [], rooms: [] } }));

  await page.goto('/daily/command-300');
  await expect(page.getByRole('heading', { name: '300', exact: true })).toBeVisible();
  await expect(page.getByTestId('command-duty-line')).toHaveText('A Shift 300: Division Chief Jane Smith');
  await expect(page.getByTestId('command-acting-open')).toHaveText('Acting as 300 today? Click here.');
  const commandBoard = page.getByRole('link', { name: /^Open Command Board/ });
  await expect(commandBoard).toHaveCount(1);
  await expect(commandBoard).toHaveAttribute('href', 'https://cmd.mbfdhub.com');
  await expect(commandBoard).toHaveAttribute('target', '_blank');
  await expect(commandBoard).toHaveAttribute('rel', 'noopener noreferrer');
  const tabs = page.getByRole('tablist', { name: 'Communications and stations' });
  await expect(tabs.getByRole('tab')).toHaveCount(7);
  await expect(tabs.getByRole('tab').first()).toHaveText('Communications');
  await expect(tabs.getByRole('tab', { name: 'Communications', exact: true })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('command-stations')).toHaveCount(1);
  await expect(page.getByTestId('command-comms')).toBeVisible();
  await expect(page.getByTestId('command-station')).toHaveCount(0);
  await expectTouchTargets(page, '[role="tab"]');

  for (const station of data.stations) {
    const tab = tabs.getByRole('tab', { name: station.name, exact: true });
    if (testInfo.project.use.hasTouch) await tab.tap();
    else await tab.click();
    await expect(tab).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel')).toHaveCount(1);
    const panel = page.getByRole('tabpanel', { name: station.name, exact: true });
    await expect(panel.getByRole('heading', { name: station.name, exact: true })).toBeVisible();
    await expect(panel).toContainText(station.apparatuses[0].name!);
    await expect(panel).toContainText('0 of 1 checked today');
    await expect(panel).toContainText('1 open service ticket');
    await expect(panel.getByRole('link', { name: `Open ${station.name}`, exact: true })).toHaveAttribute('href', `/daily/stations/${station.id}?return_to=${encodeURIComponent(`/daily/command-300?station=${station.id}`)}`);
    await expectNoHorizontalOverflow(page);
  }
  expect(requests).toBe(1); // Tab switches use the loaded overview, without extra API calls.
  await page.getByTestId('command-overview-refresh').click();
  await expect.poll(() => requests).toBe(2);
  await expect(tabs.getByRole('tab', { name: 'Station 6', exact: true })).toHaveAttribute('aria-selected', 'true');
  await page.reload();
  await expect(page.getByRole('tabpanel', { name: 'Station 6', exact: true })).toBeVisible();

  const last = tabs.getByRole('tab', { name: 'Station 6', exact: true });
  const comms = tabs.getByRole('tab', { name: 'Communications', exact: true });
  await last.focus();
  await page.keyboard.press('ArrowRight');
  await expect(comms).toBeFocused();
  await expect(comms).toHaveAttribute('aria-selected', 'true');
  await expect(page).not.toHaveURL(/station=/);
  await page.keyboard.press('ArrowLeft');
  await expect(last).toBeFocused();
  await expect(last).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('Home');
  await expect(comms).toBeFocused();
  await expect(comms).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('End');
  await expect(last).toBeFocused();
  await expect(last).toHaveAttribute('aria-selected', 'true');
  await expect(tabs.locator('[tabindex="0"]')).toHaveCount(1);

  await tabs.getByRole('tab', { name: 'Station 1', exact: true }).click();
  await page.getByRole('link', { name: 'Open Station 1', exact: true }).click();
  await expect(page).toHaveURL(/\/daily\/stations\/29\?return_to=%2Fdaily%2Fcommand-300%3Fstation%3D29$/);
  await expect(page.getByRole('heading', { name: 'Station 1', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Back to 300', exact: true }).click();
  await expect(page).toHaveURL(/\/daily\/command-300\?station=29$/);
  await expect(page.getByRole('tabpanel', { name: 'Station 1', exact: true })).toBeVisible();
  await expectNoHorizontalOverflow(page);
  await page.screenshot({ path: testInfo.outputPath('command300-station-tabs.png'), fullPage: true });
  expect(quality.consoleErrors).toEqual([]);
  expect(quality.failedRequests).toEqual([]);
});

test('Command 300 station tabs handle changed station lists, empty data, refresh errors and offline state', async ({ page }, testInfo) => {
  test.skip(!['daily-responsive-phone-320', 'daily-responsive-wide-1440'].includes(testInfo.project.name), 'Data transitions at phone and desktop widths.');
  await mockDailySelectorApi(page);
  const data = commandOverview();
  let fail = false;
  await page.route('**/api/public/command/overview', route => fail
    ? route.fulfill({ status: 503, json: { message: 'Service unavailable' } })
    : route.fulfill({ json: data }));
  await page.goto('/daily/command-300?station=invalid');
  await expect(page.getByRole('tabpanel', { name: 'Communications', exact: true })).toBeVisible();
  await page.getByRole('tab', { name: 'Station 6', exact: true }).click();
  data.stations.pop();
  const refresh = page.getByTestId('command-overview-refresh');
  await refresh.click();
  await expect(page.getByRole('tab')).toHaveCount(6);
  await expect(page.getByRole('tabpanel', { name: 'Communications', exact: true })).toBeVisible();
  await page.getByRole('tab', { name: 'Station 1', exact: true }).click();
  fail = true;
  await refresh.click();
  await expect(page.getByRole('alert')).toContainText('The overview could not be refreshed');
  await expect(page.getByRole('tabpanel', { name: 'Station 1', exact: true })).toBeVisible();
  await page.context().setOffline(true);
  await expect(refresh).toBeDisabled();
  await page.getByRole('tab', { name: 'Station 4', exact: true }).click();
  await expect(page.getByRole('tabpanel', { name: 'Station 4', exact: true })).toBeVisible();
  await page.context().setOffline(false);
  fail = false;
  data.stations = [{ ...data.stations[0], apparatuses: [], apparatus_count: 0 }];
  await refresh.click();
  await expect(page.getByRole('tab')).toHaveCount(3);
  await page.getByRole('tab', { name: 'Station 1', exact: true }).click();
  await expect(page.getByText('No apparatus is assigned to this station.')).toBeVisible();
  data.stations = [];
  await refresh.click();
  await expect(page.getByText('No active stations', { exact: true })).toBeVisible();
  // Communications stays available with no stations at all.
  await expect(page.getByRole('tab')).toHaveCount(2);
  await expect(page.getByTestId('command-comms')).toBeVisible();
});

test('station selector stays visible, touchable, and horizontally contained at every required viewport', async ({ page }, testInfo) => {
  await mockDailySelectorApi(page);
  const quality = captureBrowserQuality(page);

  await page.goto('/daily/stations');
  await expect(page.getByRole('heading', { name: 'MBFD Stations' })).toBeVisible();
  await expect(page.getByTestId('daily-station-card')).toHaveCount(stations.length);
  await expectNoHorizontalOverflow(page);

  const cardBoxes = await page.getByTestId('daily-station-card').evaluateAll((cards) => cards.map((card) => {
    const box = card.getBoundingClientRect();
    return { height: box.height, left: box.left, right: box.right };
  }));
  const viewportWidth = await page.evaluate(() => window.innerWidth);
  expect(cardBoxes.every((box) => box.height >= 44 && box.left >= 0 && box.right <= viewportWidth)).toBe(true);

  for (const card of await page.getByTestId('daily-station-card').all()) {
    await card.scrollIntoViewIfNeeded();
    const photo = card.getByRole('img');
    await expect.poll(() => photo.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
    const composition = await photo.evaluate((image: HTMLImageElement) => {
      const box = image.getBoundingClientRect();
      const frame = image.parentElement!.getBoundingClientRect();
      return {
        naturalRatio: image.naturalWidth / image.naturalHeight,
        displayedRatio: box.width / box.height,
        fit: getComputedStyle(image).objectFit,
        frameHeight: frame.height,
        contained: box.top >= frame.top - 1 && box.bottom <= frame.bottom + 1,
      };
    });
    if (viewportWidth < 1_280) {
      expect(Math.abs(composition.displayedRatio - composition.naturalRatio)).toBeLessThan(0.01);
      expect(composition.contained).toBe(true);
    } else {
      expect(composition.fit).toBe('cover');
      expect(composition.frameHeight).toBe(viewportWidth >= 1_536 ? 224 : 192);
    }
    await expect(card.getByRole('heading')).toBeVisible();
    const action = card.getByText('View Station');
    await action.evaluate((element) => element.scrollIntoView({ block: 'center', behavior: 'instant' }));
    await expect(action).toBeVisible();
    expect(await action.evaluate((element) => {
      const box = element.getBoundingClientRect();
      const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
      return hit !== null && element.closest('[data-testid="daily-station-card"]') === hit.closest('[data-testid="daily-station-card"]');
    })).toBe(true);
  }
  // 300 Command frames its photo exactly like the station cards: same fit, same frame height.
  await expect(page.getByTestId('daily-command-duty')).toHaveText('A Shift 300: Division Chief Jane Smith');
  const commandPhoto = page.getByTestId('daily-command-card').getByRole('img');
  await commandPhoto.scrollIntoViewIfNeeded();
  await expect.poll(() => commandPhoto.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
  const commandComposition = await commandPhoto.evaluate((image: HTMLImageElement) => {
    const box = image.getBoundingClientRect();
    const frame = image.parentElement!.getBoundingClientRect();
    return {
      naturalRatio: image.naturalWidth / image.naturalHeight,
      displayedRatio: box.width / box.height,
      fit: getComputedStyle(image).objectFit,
      frameHeight: frame.height,
      frameWidth: frame.width,
      boxWidth: box.width,
      src: image.currentSrc,
    };
  });
  expect(commandComposition.src).toContain('/images/stations/command-300-wide.webp');
  expect(commandComposition.boxWidth).toBeCloseTo(commandComposition.frameWidth, 0);
  if (viewportWidth < 1_280) {
    expect(Math.abs(commandComposition.displayedRatio - commandComposition.naturalRatio)).toBeLessThan(0.01);
  } else {
    expect(commandComposition.fit).toBe('cover');
    expect(commandComposition.frameHeight).toBe(viewportWidth >= 1_536 ? 224 : 192);
  }

  await page.getByTestId('daily-station-card').first().scrollIntoViewIfNeeded();
  await expectNoHorizontalOverflow(page);

  if (testInfo.project.name === 'daily-responsive-display-3840') {
    const layout = await page.getByTestId('daily-workspace').evaluate((workspace) => {
      const grid = document.querySelector('[data-testid="daily-station-grid"]');
      return {
        width: workspace.getBoundingClientRect().width,
        columns: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
      };
    });
    // The Daily workspace shares the Hub content maximum (--hub-content-max, 1600px) on every display.
    expect(layout.width).toBeLessThanOrEqual(1_600);
    expect(layout.width).toBeGreaterThanOrEqual(1_590);
    expect(layout.columns).toBeGreaterThanOrEqual(4);
  }

  const screenshot = testInfo.outputPath(`${testInfo.project.name}.png`);
  await page.evaluate(() => {
    if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
    window.scrollTo(0, 0);
  });
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
  await page.screenshot({ path: screenshot, fullPage: true });
  await testInfo.attach(`${testInfo.project.name} selector`, { path: screenshot, contentType: 'image/png' });

  expect(quality.consoleErrors).toEqual([]);
  expect(quality.failedRequests).toEqual([]);
});

test('station list refresh re-fetches stations in place with a 44px control', async ({ page }, testInfo) => {
  test.skip(!['daily-responsive-phone-320', 'daily-responsive-desktop-1280'].includes(testInfo.project.name), 'Refresh is exercised at the narrowest and a desktop viewport.');
  await mockDailySelectorApi(page);
  const quality = captureBrowserQuality(page);
  let stationRequests = 0;
  const addedStation = { ...stations[0], id: 7, station_number: 7, name: 'Station 7' };
  await page.route('**/api/public/stations', (route) => {
    stationRequests += 1;
    return route.fulfill({ json: { stations: stationRequests === 1 ? stations : [...stations, addedStation] } });
  });

  await page.goto('/daily/stations');
  await expect(page.getByTestId('daily-station-card')).toHaveCount(stations.length);

  const refresh = page.getByTestId('daily-station-refresh');
  await expect(refresh).toHaveAccessibleName('Refresh stations');
  const box = await refresh.boundingBox();
  expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);
  expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);

  await refresh.click();
  await expect(page.getByTestId('daily-station-card')).toHaveCount(stations.length + 1);
  await expect(page.getByRole('status').filter({ hasText: 'Station list updated.' })).toHaveCount(1);
  expect(stationRequests).toBe(2);
  await expectNoHorizontalOverflow(page);

  await page.unroute('**/api/public/stations');
  await page.route('**/api/public/stations', (route) => route.fulfill({ status: 503, json: { message: 'Service unavailable' } }));
  await refresh.click();
  await expect(page.getByRole('alert')).toContainText('Stations could not be refreshed');
  // A failed refresh keeps the cards the member already had.
  await expect(page.getByTestId('daily-station-card')).toHaveCount(stations.length + 1);

  await page.context().setOffline(true);
  await expect(refresh).toBeDisabled();
  await expect(refresh).toHaveAccessibleName('Refresh unavailable while offline');
  await page.context().setOffline(false);

  expect(quality.consoleErrors.filter((message) => !message.includes('503'))).toEqual([]);
});

test('representative viewports preserve station navigation and fail closed for an unknown apparatus', async ({ page }, testInfo) => {
  test.skip(![
    'daily-responsive-phone-390',
    'daily-responsive-tablet-768',
    'daily-responsive-wide-1440',
    'daily-responsive-display-3840',
  ].includes(testInfo.project.name), 'Critical Daily navigation runs at the representative viewport set only.');

  await mockDailySelectorApi(page);
  const quality = captureBrowserQuality(page);

  await page.goto('/daily/stations');
  await page.getByTestId('daily-station-card').first().focus();
  await expect(page.getByTestId('daily-station-card').first()).toBeFocused();
  await page.getByTestId('daily-station-card').first().click();
  await expect(page).toHaveURL(/\/daily\/stations\/1$/);
  await expect(page.getByRole('heading', { name: 'Station 1' })).toBeVisible();

  await page.getByRole('tab', { name: 'Apparatus' }).click();
  await expect(page.getByRole('heading', { name: 'Daily Checkout' })).toBeVisible();
  await expect(page.getByText('Unclassified Unit', { exact: true })).toHaveCount(1);
  await expect(page.getByText('Daily Checkout policy needs confirmation')).toBeVisible();
  const unclassified = page.getByRole('article', { name: 'Unclassified Unit' });
  await expect(unclassified.getByText('Classification required', { exact: true })).toBeVisible();
  await expect(unclassified.getByText('Daily Checkout requirement: Unknown', { exact: true })).toBeVisible();
  await expect(unclassified.getByRole('link', { name: 'Start Inspection' })).toHaveCount(0);
  await expectNoHorizontalOverflow(page);

  if (['daily-responsive-phone-390', 'daily-responsive-wide-1440'].includes(testInfo.project.name)) {
    const logo = page.getByRole('link', { name: 'MBFD Hub home', exact: true }).locator('img');
    await expect.poll(() => logo.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);

    const stationScreenshot = testInfo.outputPath(`${testInfo.project.name}-station-profile.png`);
    await page.screenshot({ path: stationScreenshot, fullPage: true });
    await testInfo.attach(`${testInfo.project.name} station profile`, { path: stationScreenshot, contentType: 'image/png' });

    await page.goto('/daily/forms-hub');
    await expect(page.getByRole('heading', { name: 'Forms Hub' })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await page.waitForTimeout(750);

    const formsScreenshot = testInfo.outputPath(`${testInfo.project.name}-forms-hub.png`);
    await page.screenshot({ path: formsScreenshot, fullPage: true });
    await testInfo.attach(`${testInfo.project.name} Forms Hub`, { path: formsScreenshot, contentType: 'image/png' });
  }

  expect(quality.consoleErrors).toEqual([]);
  expect(quality.failedRequests).toEqual([]);
});

// Production station DB ids (29-33) differ from station numbers (1, 2, 3, 4, 6); Station 2 here has id 31.
const stationTwo = { ...stations[1], id: 31, station_number: 2, name: 'Station 2' };

async function expectTouchTargets(page: Page, selector: string): Promise<void> {
  const undersized = await page.locator(selector).evaluateAll((elements) => elements
    .filter((element) => (element as HTMLElement).offsetParent !== null)
    .map((element) => {
      const box = element.getBoundingClientRect();
      return { html: element.outerHTML.slice(0, 120), width: Math.round(box.width), height: Math.round(box.height) };
    })
    .filter(({ width, height }) => width < 44 || height < 44));
  expect(undersized).toEqual([]);
}

test('station profile stays contained at 320px with 44px controls, keyboard tabs and id-based station links', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'daily-responsive-phone-320', 'The narrowest supported phone width covers the station profile contract.');

  await mockDailySelectorApi(page);
  await page.route('**/api/public/stations/31**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/stations/31') return route.fulfill({ json: { ...stationTwo, apparatuses: [requiredApparatus], rooms: [] } });
    if (path === '/api/public/stations/31/gas-meters') return route.fulfill({ json: { gas_meters: [] } });
    if (path === '/api/public/stations/31/inspections') return route.fulfill({ json: { inspections: [] } });
    if (path === '/api/public/stations/31/activity') return route.fulfill({ json: { activity: [] } });
    if (path === '/api/public/stations/31/overview') return route.fulfill({ json: emptyStationOverview });
    return route.fulfill({ json: { data: [], meta: { total: 0 } } });
  });
  const quality = captureBrowserQuality(page);

  await page.goto('/daily/stations/31');
  await expect(page.getByRole('heading', { name: 'Station 2', exact: true })).toBeVisible();

  const actions = page.getByRole('navigation', { name: 'Station actions' });
  await expect(actions.getByRole('link', { name: 'Morning Lineup Video Conference — Station 2' })).toHaveAttribute('href', '/video-conferencing/stations/2');
  await expect(actions.getByRole('link', { name: 'Morning Lineup — 300 Command' })).toHaveCount(0);
  await expect(actions.getByRole('link', { name: 'Station Request' })).toHaveAttribute('href', '/daily/forms-hub/station-request?station_id=31&return_to=%2Fstations%2F31');
  await expect(actions.getByRole('link', { name: 'Station Inspection' })).toHaveAttribute('href', '/daily/forms-hub/station-inspection?station_id=31&station_number=2&return_to=%2Fstations%2F31');
  await expect(actions.getByRole('link', { name: 'Apparatus Service' })).toHaveAttribute('href', `/employee/apparatus-service-request?station_id=31&return_to=${encodeURIComponent('/daily/stations/31')}`);

  await expectNoHorizontalOverflow(page);
  await expectTouchTargets(page, '[data-hub-back], nav[aria-label="Station actions"] a, [role="tab"]');

  // Arrow keys move between tabs and activate them; one tab stop for the tab list.
  const tabs = page.getByRole('tab');
  await expect(tabs).toHaveCount(8);
  await expect(page.locator('[role="tab"][tabindex="0"]')).toHaveCount(1);
  await page.getByRole('tab', { name: 'Overview' }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Requests' })).toBeFocused();
  await expect(page.getByRole('tab', { name: 'Requests' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('tabpanel', { name: 'Requests' })).toBeVisible();
  await page.keyboard.press('End');
  await expect(page.getByRole('tab', { name: 'Activity' })).toHaveAttribute('aria-selected', 'true');

  for (const name of ['Overview', 'Requests', 'Service / Repair', 'Rooms', 'Apparatus', 'Gas meters', 'Inspections', 'Activity']) {
    await page.getByRole('tab', { name, exact: name !== 'Service / Repair' }).click();
    const panel = page.getByRole('tabpanel');
    await expect(panel).toBeVisible();
    await expect(panel.locator('.hub-skeleton')).toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await expectTouchTargets(page, '[role="tabpanel"] a[href], [role="tabpanel"] button');
  }

  await page.getByRole('tab', { name: 'Apparatus', exact: true }).click();
  await expect(page.getByRole('article', { name: 'Engine 1' }).getByRole('link', { name: 'Start Inspection' }))
    .toHaveAttribute('href', '/daily/vehicle-inspections/engine-1?return_to=%2Fstations%2F31');

  const screenshot = testInfo.outputPath('station-profile-320.png');
  await page.screenshot({ path: screenshot, fullPage: true });
  await testInfo.attach('station profile 320', { path: screenshot, contentType: 'image/png' });
  expect(quality.consoleErrors).toEqual([]);
  expect(quality.failedRequests).toEqual([]);
});

test('room profile labels meet 4.5:1 against their surface at every width', async ({ page }, testInfo) => {
  await mockDailySelectorApi(page);
  await page.route('**/api/public/stations/31/rooms/7/profile', (route) => route.fulfill({ json: {
    room: { id: 7, station_id: 31, name: 'Day Room', floor: 'First', type: 'common_area', is_active: true },
    current_assets: [{ id: 1, room_id: 7, name: 'Recliner', quantity: 2, unit: 'each', condition: 'fair', category: 'furniture' }],
    open_requests: [], request_history: [], asset_events: [],
  } }));
  await page.goto('/daily/stations/31/rooms/7');
  await expect(page.getByRole('heading', { level: 1, name: 'Day Room' })).toBeVisible();
  const results = await page.evaluate(() => {
    const channel = (value: number) => { const c = value / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const luminance = (rgb: number[]) => 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
    const parse = (value: string) => (value.match(/[\d.]+/g) ?? []).map(Number);
    return [...document.querySelectorAll('dl dt, header > p:first-child')].map((element) => {
      let background = [255, 255, 255];
      const layers: Element[] = [];
      for (let node: Element | null = element; node; node = node.parentElement) layers.unshift(node);
      for (const node of layers) {
        const color = parse(getComputedStyle(node).backgroundColor);
        if (color.length >= 3) { const alpha = color[3] ?? 1; background = background.map((base, i) => color[i] * alpha + base * (1 - alpha)); }
      }
      const text = parse(getComputedStyle(element).color);
      const alpha = text[3] ?? 1;
      const foreground = text.slice(0, 3).map((value, i) => value * alpha + background[i] * (1 - alpha));
      const [light, dark] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
      return { label: element.textContent?.trim(), ratio: Number(((light + 0.05) / (dark + 0.05)).toFixed(2)) };
    });
  });
  await testInfo.attach('room-label-contrast', { body: JSON.stringify(results, null, 2), contentType: 'application/json' });
  expect(results.length).toBeGreaterThanOrEqual(4);
  for (const item of results) expect(item.ratio, JSON.stringify(item)).toBeGreaterThanOrEqual(4.5);
});
