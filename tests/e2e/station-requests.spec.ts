import { expect, test, type Page } from '@playwright/test';

const station = {
  id: 1,
  station_number: '1',
  name: 'Station 1',
  address: '1051 Jefferson Avenue',
  is_active: true,
};

const employee = { id: 41, name: 'Firefighter Browser Test', rank: 'Firefighter' };
const room = { id: 11, station_id: 1, name: 'Kitchen', room_type: 'kitchen' };
const asset = { id: 101, room_id: 11, name: 'Refrigerator', category: 'appliance', quantity: 1, condition: 'needs_repair' };

async function mockStationRequestApi(page: Page): Promise<() => Record<string, unknown> | undefined> {
  let submittedPayload: Record<string, unknown> | undefined;

  await page.route('**/images/mbfd_logo_new.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/public/stations') {
      return route.fulfill({ json: { stations: [station] } });
    }
    if (path === '/api/public/employees/list') {
      return route.fulfill({ json: [employee] });
    }
    if (path === '/api/public/stations/1/rooms') {
      return route.fulfill({ json: { rooms: [room] } });
    }
    if (path === '/api/public/stations/1/rooms/11/assets') {
      return route.fulfill({ json: { assets: [asset] } });
    }
    if (path === '/api/public/station_request' && request.method() === 'POST') {
      submittedPayload = request.postDataJSON() as Record<string, unknown>;
      return route.fulfill({
        status: 201,
        json: {
          data: {
            id: 501,
            request_number: 'SR-2026-000501',
            station_id: 1,
            room_id: 11,
            request_type: 'repair_service',
            status: 'pending',
          },
        },
      });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  return () => submittedPayload;
}

test('repair request is touch-safe, responsive, and submits the canonical payload', async ({ page }, testInfo) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=1&return_to=/stations/1');

  await expect(page.getByRole('heading', { name: 'Tell Support Services what the station needs.' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Repair / service Report a facility, room, or asset issue.' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByLabel('Selected station')).toHaveText('Station 1');
  await expect(page.getByLabel('Station *')).toHaveCount(0);
  await page.getByLabel('Search employees').fill('Browser Test');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByLabel(/Room/).selectOption('11');

  const continueButton = page.getByRole('button', { name: 'Continue' });
  const buttonBox = await continueButton.boundingBox();
  expect(buttonBox?.height).toBeGreaterThanOrEqual(48);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  await continueButton.click();
  await page.getByRole('button', { name: 'Existing room asset Repair or service an item already tracked in this room.' }).click();
  await page.getByLabel('Short title *').fill('Kitchen refrigerator is not cooling');
  await page.getByLabel('Description and operational impact *').fill('Temperature rose above the safe range during morning checks.');
  await page.getByLabel(/Existing room asset/).selectOption('101');
  await page.getByLabel('Requested action').selectOption('repair');
  await continueButton.click();

  await expect(page.getByRole('heading', { name: 'Review and submit' })).toBeVisible();
  await expect(page.getByText('1× Refrigerator')).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('station-request-review.png'), fullPage: true });
  const submitButton = page.getByRole('button', { name: 'Submit station request' });
  await expect(submitButton).toHaveClass(/bg-orange-600/);
  await submitButton.click();

  await expect(page.getByRole('heading', { name: 'Request submitted' })).toBeVisible();
  await expect(page.getByText(/SR-2026-000501/)).toBeVisible();
  await expect(page.getByRole('link', { name: 'Return to station' })).toHaveAttribute('href', '/daily/stations/1');

  const payload = submittedPayload();
  expect(payload).toMatchObject({
    station_id: 1,
    room_id: 11,
    room_name_snapshot: null,
    requested_by_employee_id: 41,
    request_type: 'repair_service',
    subject_type: 'existing_asset',
    title: 'Kitchen refrigerator is not cooling',
  });
  expect(payload?.client_submission_id).toMatch(/^[0-9a-f-]{36}$/);
  expect((payload?.items as Array<Record<string, unknown>>)[0]).toMatchObject({
    room_asset_id: 101,
    item_name: 'Refrigerator',
    requested_action: 'repair',
  });

  await page.screenshot({ path: testInfo.outputPath('station-request-complete.png'), fullPage: true });
});

test('unlisted room stays a snapshot and is not treated as a canonical room id', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByLabel(/Room/).selectOption('other');
  await page.getByLabel('Room or location *').fill('Rear storage alcove');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Item not in inventory Report an item without creating an inventory record.' }).click();
  await page.getByLabel('Short title *').fill('Storage shelf needs repair');
  await page.getByLabel('Description and operational impact *').fill('The wall-mounted shelf is pulling away from the block wall.');
  await page.getByLabel('Item name *').fill('Wall-mounted shelf');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Rear storage alcove')).toBeVisible();
  await page.getByRole('button', { name: 'Submit station request' }).click();
  await expect(page.getByRole('heading', { name: 'Request submitted' })).toBeVisible();

  expect(submittedPayload()).toMatchObject({
    room_id: null,
    room_name_snapshot: 'Rear storage alcove',
    subject_type: 'other',
  });
});

test('offline request is queued with its durable client id', async ({ page, context }) => {
  await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Short title *').fill('Bay light is out');
  await page.getByLabel('Description and operational impact *').fill('The light over the reserve apparatus parking position does not illuminate.');
  await page.getByLabel('Item name *').fill('Bay light');
  await page.getByRole('button', { name: 'Continue' }).click();
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit station request' }).click();

  await expect(page.getByRole('heading', { name: 'Request saved offline' })).toBeVisible();
  await expect(page.getByText(/Offline reference/)).toBeVisible();
  const queued = await page.evaluate(async () => new Promise<Record<string, unknown> | undefined>((resolve, reject) => {
    const request = indexedDB.open('mbfd-daily-checkout');
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      const transaction = database.transaction('pendingSubmissions', 'readonly');
      const getAll = transaction.objectStore('pendingSubmissions').getAll();
      getAll.onerror = () => reject(getAll.error);
      getAll.onsuccess = () => resolve(getAll.result[0]);
    };
  }));
  expect(queued?.type).toBe('station_request');
  expect((queued?.data as Record<string, unknown>).client_submission_id).toMatch(/^[0-9a-f-]{36}$/);
});

test('equipment branch enforces police case and both signatures before multi-item submission', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?type=equipment&station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByLabel('Short title *').fill('Portable equipment replacement');
  await page.getByLabel('Description and operational impact *').fill('Replace stolen radio and add a thermal camera for the reserve complement.');
  await page.getByLabel('Item name *').fill('Portable radio');
  await page.getByLabel('Reason').selectOption('Stolen');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('alert')).toContainText('police case number');
  await page.getByLabel('Police case number *').fill('MBPD-2026-0501');

  await page.getByRole('button', { name: '+ Add item' }).click();
  await page.getByLabel('Item name *').nth(1).fill('Thermal camera');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Required signatures' })).toBeVisible();

  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('alert')).toContainText('requesting member signature');
  await drawSignature(page, 'Requesting member signature pad');
  await drawSignature(page, 'Company officer signature pad');
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page.getByRole('heading', { name: 'Review and submit' })).toBeVisible();
  await page.getByRole('button', { name: 'Submit station request' }).click();
  await expect(page.getByRole('heading', { name: 'Request submitted' })).toBeVisible();

  const payload = submittedPayload();
  expect(payload).toMatchObject({ request_type: 'equipment', station_id: 1, requested_by_employee_id: 41 });
  expect(payload?.member_signature).toMatch(/^data:image\/png;base64,/);
  expect(payload?.officer_signature).toMatch(/^data:image\/png;base64,/);
  expect(payload?.items).toHaveLength(2);
  expect((payload?.items as Array<Record<string, unknown>>)[0]).toMatchObject({
    item_name: 'Portable radio',
    reason: 'Stolen',
    pd_case_number: 'MBPD-2026-0501',
  });
});

test('legacy entry replaces history and rejects an external return target', async ({ page }) => {
  await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub');
  await page.getByRole('link', { name: /Station Request/ }).click();
  await expect(page).toHaveURL(/\/daily\/forms-hub\/station-request$/);
  await page.goBack();
  await expect(page).toHaveURL(/\/daily\/forms-hub$/);

  await page.goto('/daily/forms-hub/big-ticket-request?station_id=1&return_to=https://example.com/phish');

  await expect(page).toHaveURL(/\/daily\/forms-hub\/station-request/);
  const redirected = new URL(page.url());
  expect(redirected.searchParams.get('station_id')).toBe('1');
  expect(redirected.searchParams.get('type')).toBe('repair_service');
  expect(redirected.searchParams.get('return_to')).toBe('/forms-hub');
  expect(page.url()).not.toContain('example.com');
  await expect(page.getByRole('link', { name: /Back/ })).toHaveAttribute('href', '/daily/forms-hub');
  await expect(page.getByRole('button', { name: 'Repair / service Report a facility, room, or asset issue.' })).toHaveAttribute('aria-pressed', 'true');

  await page.goto('/daily/forms-hub/equipment-request?station_id=1');
  await expect(page).toHaveURL(/\/daily\/forms-hub\/station-request/);
  expect(new URL(page.url()).searchParams.get('type')).toBe('equipment');
  await expect(page.getByRole('button', { name: 'Equipment Request one or more station equipment items.' })).toHaveAttribute('aria-pressed', 'true');
});

async function drawSignature(page: Page, label: string): Promise<void> {
  const canvas = page.locator(`canvas[aria-label="${label}"]`);
  await canvas.evaluate((element) => {
    const box = element.getBoundingClientRect();
    const mouseEvent = (type: string, x: number, y: number, buttons: number) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, buttons, clientX: x, clientY: y });
      Object.defineProperty(event, 'which', { value: 1 });
      return event;
    };
    element.dispatchEvent(mouseEvent('mousedown', box.left + 20, box.top + box.height / 2, 1));
    for (let step = 1; step <= 8; step += 1) {
      element.dispatchEvent(mouseEvent(
        'mousemove',
        box.left + 20 + ((box.width - 40) * step) / 8,
        box.top + box.height / 2 + step,
        1,
      ));
    }
    document.dispatchEvent(mouseEvent('mouseup', box.right - 20, box.top + box.height / 2 + 8, 0));
  });
}
