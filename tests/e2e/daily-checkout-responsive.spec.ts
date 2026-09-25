import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { resolve } from 'node:path';

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

async function mockDailySelectorApi(page: Page): Promise<void> {
  await page.route('**/images/**', (route) => {
    if (new URL(route.request().url()).pathname === '/images/mbfd_logo_new.png') {
      return route.fulfill({
        path: resolve('public/images/mbfd_logo_new.png'),
        contentType: 'image/png',
      });
    }

    return route.fulfill({ status: 204 });
  });
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;

    if (path === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 101, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_101, employee_number: 'E101', name: 'Responsive Tester', rank: 'Captain' },
          offline: { security_version: 1 },
          session: { authenticated: true },
        },
      });
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

  if (testInfo.project.name === 'daily-responsive-display-3840') {
    const layout = await page.getByTestId('daily-workspace').evaluate((workspace) => {
      const grid = document.querySelector('[data-testid="daily-station-grid"]');
      return {
        width: workspace.getBoundingClientRect().width,
        columns: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
      };
    });
    expect(layout.width).toBeGreaterThanOrEqual(1_760);
    expect(layout.columns).toBeGreaterThanOrEqual(4);
  }

  if (['daily-responsive-phone-390', 'daily-responsive-display-3840'].includes(testInfo.project.name)) {
    const screenshot = testInfo.outputPath(`${testInfo.project.name}.png`);
    await page.screenshot({ path: screenshot, fullPage: true });
    await testInfo.attach(`${testInfo.project.name} selector`, { path: screenshot, contentType: 'image/png' });
  }

  expect(quality.consoleErrors).toEqual([]);
  expect(quality.failedRequests).toEqual([]);
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

  await page.getByRole('button', { name: 'Apparatus' }).click();
  await expect(page.getByRole('heading', { name: 'Daily Checkout' })).toBeVisible();
  await expect(page.getByText('Unclassified Unit', { exact: true })).toHaveCount(1);
  await expect(page.getByText('Daily Checkout policy needs confirmation')).toBeVisible();
  const unclassified = page.getByRole('article', { name: 'Unclassified Unit' });
  await expect(unclassified.getByText('Classification required', { exact: true })).toBeVisible();
  await expect(unclassified.getByText('Daily Checkout requirement: Unknown', { exact: true })).toBeVisible();
  await expect(unclassified.getByRole('link', { name: 'Start Inspection' })).toHaveCount(0);
  await expectNoHorizontalOverflow(page);

  if (['daily-responsive-phone-390', 'daily-responsive-wide-1440'].includes(testInfo.project.name)) {
    await page.waitForTimeout(750);
    expect(await page.getByRole('img', { name: 'MBFD Logo' }).evaluate((image: HTMLImageElement) => image.naturalWidth)).toBeGreaterThan(0);

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
