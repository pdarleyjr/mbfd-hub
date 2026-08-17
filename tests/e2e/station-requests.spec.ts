import { expect, test, type Page } from '@playwright/test';

const station = {
  id: 1,
  station_number: '1',
  name: 'Station 1',
  address: '1051 Jefferson Avenue',
  is_active: true,
};
const stationSix = {
  id: 33,
  station_number: '6',
  name: 'Station 6',
  address: 'Indian Creek Waterway',
  is_active: true,
};

const employee = { id: 41, name: 'Firefighter Browser Test', rank: 'Firefighter' };
const room = { id: 11, station_id: 1, name: 'Kitchen', type: 'kitchen', blueprint_key: 'kitchen.main', is_active: true };
const blueprintRooms = [
  room,
  { id: 12, station_id: 1, name: 'Combat firefighter dorm room', type: 'dormitory', blueprint_key: 'dorm.combat_firefighters', capacity: 6, is_active: true },
  { id: 13, station_id: 1, name: 'Combat officer dorm room', type: 'dormitory', blueprint_key: 'dorm.combat_officers', capacity: 2, is_active: true },
  { id: 14, station_id: 1, name: 'Rescue dorm room', type: 'dormitory', blueprint_key: 'dorm.rescue', capacity: 6, is_active: true },
  { id: 15, station_id: 1, name: 'E1 apparatus bay position', type: 'combat_apparatus_bay', blueprint_key: 'combat_apparatus_bay.e1', is_active: true },
  { id: 16, station_id: 1, name: 'R1 apparatus bay position', type: 'rescue_apparatus_bay', blueprint_key: 'rescue_apparatus_bay.r1', is_active: true },
];
const stationSixRooms = [
  { id: 61, station_id: 33, name: 'Fire Boat 6 berth / apparatus area', type: 'fireboat_apparatus_area', blueprint_key: 'fireboat_apparatus_area.fb6', sort_order: 200, is_active: true },
  { id: 62, station_id: 33, name: 'Dock', type: 'fireboat_apparatus_area', blueprint_key: 'fireboat_apparatus_area.dock', sort_order: 210, is_active: true },
  { id: 63, station_id: 33, name: 'Boat lift', type: 'fireboat_apparatus_area', blueprint_key: 'fireboat_apparatus_area.boat_lift', sort_order: 220, is_active: true },
];
const asset = { id: 101, room_id: 11, name: 'Refrigerator', category: 'appliance', quantity: 1, condition: 'needs_repair' };

interface MockApiOptions {
  submitStatus?: number;
  submitBody?: Record<string, unknown>;
  failInitialOptionsOnce?: boolean;
}

async function mockStationRequestApi(page: Page, options: MockApiOptions = {}): Promise<() => Record<string, unknown> | undefined> {
  let submittedPayload: Record<string, unknown> | undefined;
  let stationOptionRequests = 0;

  await page.route('**/images/mbfd_logo_new.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/public/stations') {
      stationOptionRequests += 1;
      if (options.failInitialOptionsOnce && stationOptionRequests === 1) {
        return route.fulfill({ status: 503, json: { message: 'Options temporarily unavailable.' } });
      }
      return route.fulfill({ json: { stations: [station, stationSix] } });
    }
    if (path === '/api/public/employees/list') {
      return route.fulfill({ json: [employee] });
    }
    if (path === '/api/public/stations/1/rooms') {
      return route.fulfill({ json: { rooms: blueprintRooms } });
    }
    if (path === '/api/public/stations/33/rooms') {
      return route.fulfill({ json: { rooms: stationSixRooms } });
    }
    if (path === '/api/public/stations/1/rooms/11/assets') {
      return route.fulfill({ json: { assets: [asset] } });
    }
    if (path === '/api/public/station_request' && request.method() === 'POST') {
      submittedPayload = request.postDataJSON() as Record<string, unknown>;
      if (options.submitStatus && options.submitStatus !== 201) {
        return route.fulfill({
          status: options.submitStatus,
          json: options.submitBody ?? { message: 'Submission failed.' },
        });
      }
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
  await page.getByLabel('Room area').selectOption('kitchen');
  await page.getByLabel('Specific room / area *').selectOption('11');

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
  await page.getByLabel('Priority').selectOption('high');
  await page.getByLabel(/unusable \/ out of service/).check();
  await page.getByLabel(/Damage photo/).setInputFiles('public/images/mbfd_logo_new.png');
  await expect(page.getByText('Photo attached')).toBeVisible();
  await continueButton.click();

  await expect(page.getByRole('heading', { name: 'Review and submit' })).toBeVisible();
  await expect(page.getByText('1× Refrigerator')).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('station-request-review.png'), fullPage: true });
  const submitButton = page.getByRole('button', { name: 'Submit station request' });
  await expect(submitButton).toHaveClass(/bg-orange-600/);
  await submitButton.click();

  await expect(page.getByRole('heading', { name: 'Request submitted' })).toBeVisible();
  await expect(page.getByText(/SR-2026-000501/)).toBeVisible();
  await page.getByRole('button', { name: 'Back to previous page' }).click();
  await expect(page).toHaveURL(/\/daily\/stations\/1$/);

  const payload = submittedPayload();
  expect(payload).toMatchObject({
    station_id: 1,
    room_id: 11,
    room_name_snapshot: null,
    requested_by_employee_id: 41,
    request_type: 'repair_service',
    subject_type: 'existing_asset',
    title: 'Kitchen refrigerator is not cooling',
    priority: 'high',
  });
  expect(payload?.client_submission_id).toMatch(/^[0-9a-f-]{36}$/);
  expect((payload?.items as Array<Record<string, unknown>>)[0]).toMatchObject({
    room_asset_id: 101,
    item_name: 'Refrigerator',
    requested_action: 'repair',
    condition: 'out_of_service',
  });
  expect((payload?.items as Array<Record<string, unknown>>)[0].photo).toMatch(/^data:image\//);

  await page.screenshot({ path: testInfo.outputPath('station-request-complete.png'), fullPage: true });
});

test('unlisted room stays a snapshot and is not treated as a canonical room id', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByLabel('Room area').selectOption('other');
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

test('room blueprint keeps station-wide and exposes valid dorm details without a rescue officer room', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  const area = page.getByLabel('Room area');
  await expect(area.locator('option').first()).toHaveText('Station-wide / no single room');
  await expect(area.locator('option')).toContainText([
    'Station-wide / no single room',
    'Kitchen',
    'Dorm',
    'Combat apparatus bay',
    'Rescue apparatus bay',
    'Room not listed / Other',
  ]);

  await area.selectOption('dormitory');
  const detail = page.getByLabel('Specific room / area *');
  await expect(detail.locator('option')).toContainText([
    'Select a dorm',
    'Combat firefighter dorm room',
    'Combat officer dorm room',
    'Rescue dorm room',
  ]);
  await expect(detail).not.toContainText('Rescue officer dorm room');
  await detail.selectOption('12');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Short title *').fill('Dorm light needs service');
  await page.getByLabel('Description and operational impact *').fill('The overhead fixture flickers during evening hours.');
  await page.getByLabel('Item name *').fill('Overhead light fixture');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Combat firefighter dorm room')).toBeVisible();
  await page.getByRole('button', { name: 'Submit station request' }).click();

  expect(submittedPayload()).toMatchObject({
    room_id: 12,
    room_name_snapshot: null,
    subject_type: 'room',
  });
});

test('Station 6 request can target the dock or boat lift as a marine service location', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);

  await page.goto('/daily/forms-hub/station-request?station_id=33');
  await expect(page.getByLabel('Selected station')).toHaveText('Station 6');
  await page.getByLabel('Requesting employee *').selectOption('41');

  const area = page.getByLabel('Room area');
  await expect(area).toContainText('Fireboat berth / apparatus area');
  await area.selectOption('fireboat_apparatus_area');

  const detail = page.getByLabel('Specific room / area *');
  await expect(detail.locator('option')).toContainText([
    'Select a fireboat apparatus area',
    'Fire Boat 6 berth / apparatus area',
    'Dock',
    'Boat lift',
  ]);
  await detail.selectOption('63');

  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Short title *').fill('Boat lift needs service');
  await page.getByLabel('Description and operational impact *').fill('The boat lift requires inspection and repair before the next marine operation.');
  await page.getByLabel('Item name *').fill('Boat lift');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByText('Boat lift', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Submit station request' }).click();

  expect(submittedPayload()).toMatchObject({
    station_id: 33,
    room_id: 63,
    room_name_snapshot: null,
    subject_type: 'room',
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
  expect(redirected.searchParams.get('return_to')).toBe('/stations/1');
  expect(page.url()).not.toContain('example.com');
  await expect(page.getByRole('button', { name: 'Back to previous page' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Repair / service Report a facility, room, or asset issue.' })).toHaveAttribute('aria-pressed', 'true');

  await page.goto('/daily/forms-hub/equipment-request?station_id=1');
  await expect(page).toHaveURL(/\/daily\/forms-hub\/station-request/);
  expect(new URL(page.url()).searchParams.get('type')).toBe('equipment');
  expect(new URL(page.url()).searchParams.get('return_to')).toBe('/stations/1');
  await expect(page.getByRole('button', { name: 'Equipment Request one or more station equipment items.' })).toHaveAttribute('aria-pressed', 'true');

  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await expect(page.getByRole('button', { name: 'Back to previous page' })).toBeVisible();
});

test('generic entry retries option loading and exposes keyboard-safe selectors and type toggles', async ({ page }) => {
  await mockStationRequestApi(page, { failInitialOptionsOnce: true });
  await page.goto('/daily/forms-hub/station-request');

  await expect(page.getByRole('alert')).toContainText('could not be loaded');
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByLabel('Station *')).toBeEnabled();
  await page.getByLabel('Station *').selectOption('1');
  await page.getByLabel('Search employees').fill('No Such Employee');
  await expect(page.getByLabel('Requesting employee *').locator('option').first()).toHaveText('No matching employees');
  await page.getByLabel('Search employees').fill('Browser');
  await page.getByLabel('Requesting employee *').selectOption('41');

  const equipment = page.getByRole('button', { name: 'Equipment Request one or more station equipment items.' });
  await equipment.focus();
  await page.keyboard.press('Enter');
  await expect(equipment).toHaveAttribute('aria-pressed', 'true');
  const repair = page.getByRole('button', { name: 'Repair / service Report a facility, room, or asset issue.' });
  await repair.focus();
  await page.keyboard.press('Space');
  await expect(repair).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Back to previous page' }).click();
  await expect(page).toHaveURL(/\/daily\/stations$/);

  const unnamedControls = await page.locator('button, a[href], input, select, textarea').evaluateAll((elements) => elements
    .filter((element) => {
      const node = element as HTMLElement;
      const style = getComputedStyle(node);
      return style.display !== 'none' && style.visibility !== 'hidden' && node.getBoundingClientRect().height > 0;
    })
    .filter((element) => {
      const input = element as HTMLInputElement;
      const labels = 'labels' in input ? Array.from(input.labels ?? []).map((label) => label.textContent).join(' ') : '';
      return !(element.getAttribute('aria-label') || element.getAttribute('title') || labels?.trim() || element.textContent?.trim());
    })
    .map((element) => element.outerHTML));
  expect(unnamedControls).toEqual([]);
  const undersizedTargets = await page.locator('button, a[href], input, select, textarea').evaluateAll((elements) => elements
    .filter((element) => {
      const node = element as HTMLElement;
      const style = getComputedStyle(node);
      return style.display !== 'none' && style.visibility !== 'hidden' && node.getBoundingClientRect().height > 0;
    })
    .map((element) => {
      const input = element as HTMLInputElement;
      const target = input.type === 'checkbox' ? input.closest('label') ?? input : input;
      const rect = target.getBoundingClientRect();
      return { html: element.outerHTML, width: rect.width, height: rect.height };
    })
    .filter(({ width, height }) => width < 44 || height < 44));
  expect(undersizedTargets).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('permanent 403 and 422 responses show useful errors and are never queued', async ({ context }) => {
  for (const failure of [
    { status: 403, body: { message: 'You are not permitted to submit this request.' }, expected: 'not permitted' },
    { status: 422, body: { message: 'Validation failed.', errors: { 'items.0.quantity': ['Every quantity must be 100 or less.'] } }, expected: '100 or less' },
  ]) {
    const page = await context.newPage();
    await mockStationRequestApi(page, { submitStatus: failure.status, submitBody: failure.body });
    await fillBasicRepair(page);
    await page.getByRole('button', { name: 'Submit station request' }).click();
    await expect(page.getByRole('alert')).toContainText(failure.expected);
    await expect(page.getByRole('heading', { name: /Request (submitted|saved offline)/ })).toHaveCount(0);
    expect(await queuedSubmissionCount(page)).toBe(0);
    await page.close();
  }
});

test('recoverable 429 and 500 responses retain the request in the durable retry queue', async ({ context }) => {
  for (const [index, status] of [429, 500].entries()) {
    const page = await context.newPage();
    await mockStationRequestApi(page, { submitStatus: status, submitBody: { message: `Recoverable ${status}` } });
    await fillBasicRepair(page);
    await page.getByRole('button', { name: 'Submit station request' }).click();
    await expect(page.getByRole('heading', { name: 'Request saved offline' })).toBeVisible();
    expect(await queuedSubmissionCount(page)).toBe(index + 1);
    await page.close();
  }
});

test('equipment add remove quantity and signature controls preserve state across back navigation', async ({ page }) => {
  const submittedPayload = await mockStationRequestApi(page);
  await page.goto('/daily/forms-hub/station-request?type=equipment&station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByLabel('Short title *').fill('Reserve equipment request');
  await page.getByLabel('Description and operational impact *').fill('Stock reserve portable lights for extended operations.');
  await page.getByLabel('Item name *').fill('Portable scene light');
  await page.getByLabel('Quantity').fill('101');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('alert')).toContainText('100 or less');
  await page.getByLabel('Quantity').fill('3');
  await page.getByLabel('Priority').selectOption('critical');
  await page.getByLabel('Reason').selectOption('End of Service Life');
  await page.getByRole('button', { name: '+ Add item' }).click();
  await expect(page.getByLabel('Item name *')).toHaveCount(2);
  await page.getByRole('button', { name: 'Remove item' }).last().click();
  await expect(page.getByLabel('Item name *')).toHaveCount(1);
  await page.getByRole('button', { name: 'Continue' }).click();

  await drawSignature(page, 'Requesting member signature pad');
  await drawSignature(page, 'Company officer signature pad');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Review and submit' })).toBeVisible();
  await page.getByRole('button', { name: 'Back', exact: true }).click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Review and submit' })).toBeVisible();
  await page.getByRole('button', { name: 'Back', exact: true }).click();
  await page.getByRole('button', { name: 'Clear' }).first().click();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('alert')).toContainText('requesting member signature');
  await drawSignature(page, 'Requesting member signature pad');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Submit station request' }).click();

  await expect(page.getByRole('heading', { name: 'Request submitted' })).toBeVisible();
  expect(submittedPayload()).toMatchObject({ priority: 'critical' });
  expect((submittedPayload()?.items as Array<Record<string, unknown>>)[0]).toMatchObject({
    quantity: 3,
    reason: 'End of Service Life',
  });
});

test('station request and activity tabs filter history and preserve station and room navigation', async ({ page }) => {
  const now = '2026-08-16T12:00:00.000Z';
  const openRequest = {
    id: 501, request_number: 'SR-2026-000501', station_id: 1, room_id: 11,
    room: { id: 11, name: 'Kitchen' }, request_type: 'repair_service', subject_type: 'appliance',
    title: 'Open refrigerator repair', description: 'Refrigerator is warm.', priority: 'high',
    status: 'under_review', is_open: true, current_public_response: 'Vendor contacted.',
    created_at: now, updated_at: now,
    updates: [
      { id: 1, status: 'pending', public_note: 'Request submitted.', created_at: now },
      { id: 2, status: 'under_review', public_note: 'Vendor contacted.', created_at: now },
    ],
  };
  const closedRequest = {
    ...openRequest, id: 502, request_number: 'SR-2026-000502', title: 'Closed radio request',
    request_type: 'equipment', status: 'completed', is_open: false, current_public_response: 'Delivered.',
  };
  const fullStation = {
    ...station, station_number: 1, city: 'Miami Beach', state: 'FL', zip_code: '33139', phone: '',
    created_at: now, updated_at: now, rooms: blueprintRooms, apparatuses: [],
    assigned_apparatus_count: null, assigned_personnel_count: null, dorm_beds_count: null,
    assigned_units: [], staffing_known: false,
  };

  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/stations') return route.fulfill({ json: [fullStation] });
    if (path === '/api/public/stations/1') return route.fulfill({ json: fullStation });
    if (path === '/api/public/stations/1/apparatus-inspections') return route.fulfill({ json: { inspections: [] } });
    if (path === '/api/public/stations/1/requests') return route.fulfill({ json: { data: [openRequest, closedRequest] } });
    if (path === '/api/public/stations/1/activity') return route.fulfill({ json: { station_id: 1, activity: [{ type: 'station_request', label: 'SR-2026-000501 — Open refrigerator repair', status: 'under_review', occurred_at: now, request_number: 'SR-2026-000501' }] } });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/stations?view=active');
  await page.getByRole('link', { name: /Station 1/ }).click();
  await page.getByRole('button', { name: 'Back to previous page' }).click();
  await expect(page).toHaveURL(/\/daily\/stations\?view=active$/);
  await page.getByRole('link', { name: /Station 1/ }).click();
  await expect(page.getByText('Open refrigerator repair')).toBeVisible();
  await expect(page.getByText('Closed radio request')).toHaveCount(0);
  await page.getByRole('button', { name: 'All history' }).click();
  await expect(page.getByText('Closed radio request')).toBeVisible();
  const firstHistory = page.locator('details').first();
  await firstHistory.locator('summary').click();
  await expect(firstHistory.getByText('Vendor contacted.', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Station Request' })).toHaveAttribute('href', '/daily/forms-hub/station-request?station_id=1&return_to=%2Fstations%2F1');

  await page.getByRole('button', { name: 'Overview' }).click();
  await expect(page.getByText('E1 · L1 · R1 · R11')).toBeVisible();
  await expect(page.getByText('4', { exact: true })).toBeVisible();
  await expect(page.getByText('14', { exact: true })).toHaveCount(2);

  await page.getByRole('button', { name: 'Rooms' }).click();
  await expect(page.getByRole('heading', { name: 'Dorm', exact: true })).toBeVisible();
  await expect(page.getByText('14 dorm positions')).toBeVisible();
  await expect(page.getByRole('link', { name: /Kitchen/ })).toHaveAttribute('href', '/daily/stations/1/rooms/11');
  await page.getByRole('button', { name: 'Activity' }).click();
  await expect(page.getByText('SR-2026-000501 — Open refrigerator repair')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('room profile retries failures and every asset request and event tab works', async ({ page }) => {
  const now = '2026-08-16T12:00:00.000Z';
  let profileAttempts = 0;
  const request = {
    id: 501, request_number: 'SR-2026-000501', station_id: 1, room_id: 11,
    request_type: 'repair_service', title: 'Refrigerator repair', description: 'Not cooling.',
    priority: 'high', status: 'under_review', is_open: true, current_public_response: 'Vendor scheduled.',
    created_at: now, updated_at: now,
  };
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/public/stations/1/rooms/11/profile') {
      profileAttempts += 1;
      if (profileAttempts === 1) return route.fulfill({ status: 503, json: { message: 'Temporary failure.' } });
      return route.fulfill({ json: {
        room: { ...room, type: 'kitchen', floor: '1st', is_active: true },
        current_assets: [asset],
        open_requests: [request],
        request_history: [request, { ...request, id: 502, request_number: 'SR-2026-000502', title: 'Prior repair', status: 'completed', is_open: false }],
        asset_events: [{ id: 901, room_asset_id: 101, asset_name: 'Refrigerator', request_number: 'SR-2026-000501', event_type: 'repair_completed', event_at: now }],
      } });
    }
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.goto('/daily/stations/1/rooms/11');
  await expect(page.getByText('Failed to fetch room profile')).toBeVisible();
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(page.getByRole('heading', { name: 'Kitchen' })).toBeVisible();
  await expect(page.getByText('Refrigerator')).toBeVisible();
  await page.getByRole('tab', { name: 'Open requests 1' }).click();
  await expect(page.getByText('Vendor scheduled.')).toBeVisible();
  await page.getByRole('tab', { name: 'Request history 2' }).click();
  await expect(page.getByText('Prior repair')).toBeVisible();
  await page.getByRole('tab', { name: 'Asset events 1' }).click();
  await expect(page.getByText('repair completed')).toBeVisible();
  await expect(page.getByRole('link', { name: 'New room request' })).toHaveAttribute('href', '/daily/forms-hub/station-request?station_id=1&return_to=%2Fstations%2F1%2Frooms%2F11');
  await expect(page.getByRole('button', { name: 'Back to previous page' })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

async function fillBasicRepair(page: Page): Promise<void> {
  await page.goto('/daily/forms-hub/station-request?station_id=1');
  await page.getByLabel('Requesting employee *').selectOption('41');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Short title *').fill('Basic repair request');
  await page.getByLabel('Description and operational impact *').fill('A fixture needs service before the next shift.');
  await page.getByLabel('Item name *').fill('Station fixture');
  await page.getByRole('button', { name: 'Continue' }).click();
}

async function queuedSubmissionCount(page: Page): Promise<number> {
  return page.evaluate(async () => new Promise<number>((resolve, reject) => {
    const request = indexedDB.open('mbfd-daily-checkout');
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const database = request.result;
      if (!database.objectStoreNames.contains('pendingSubmissions')) return resolve(0);
      const transaction = database.transaction('pendingSubmissions', 'readonly');
      const count = transaction.objectStore('pendingSubmissions').count();
      count.onerror = () => reject(count.error);
      count.onsuccess = () => resolve(count.result);
    };
  }));
}

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
