import { expect, test, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { readDrafts } from './support/daily-checkout-drafts';

const apparatus = {
  id: 101,
  name: 'Engine 1',
  designation: 'E1',
  type: 'engine',
  vehicle_number: 'E1',
  slug: 'engine-1',
  status: 'In Service',
  daily_checkout_requirement: 'required',
  current_engine_hours: 100,
  current_miles: 1_000,
};

const CORRECTION_ROUTE = `/api/public/apparatuses/${apparatus.id}/inventory-corrections`;
const QUEUE_TYPE = `apparatuses/${apparatus.id}/inventory-corrections`;
const BASE_VERSION = 'f'.repeat(64);
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

interface PendingCorrection {
  id: number | null;
  compartment_id: string | null;
  item_id: string | null;
  reason: string | null;
  reason_label?: string;
  status: string;
  reported_at?: string;
  mine: boolean;
}

interface ChecklistFixture {
  version: string;
  base: string;
  pending: PendingCorrection[];
  compartments: Array<{ id: string; name: string; items: Array<Record<string, unknown>> }>;
}

const checklistA = (): ChecklistFixture => ({
  version: 'a'.repeat(64),
  base: BASE_VERSION,
  pending: [],
  compartments: [
    {
      id: 'cab',
      name: 'Cab',
      items: [
        { id: 'cab-radio', name: 'Portable Radio', status: 'Present', notes: '' },
        { id: 'cab-flashlight', name: 'Flashlight', status: 'Present', notes: '', expectedQuantity: 3, manufacturer: 'Streamlight', model: 'Stinger 2020', description: 'Rechargeable' },
      ],
    },
    { id: 'b1', name: 'Compartment B1', items: [{ id: 'b1-hose', name: 'Supply Hose', status: 'Present', notes: '' }] },
  ],
});

interface CorrectionApi {
  readonly corrections: Array<Record<string, unknown>>;
  readonly inspections: Array<Record<string, unknown>>;
  /** Status the mocked server answers an inspection with; 503 keeps a queued checkout waiting. */
  inspectionStatus: number;
  fixture: ChecklistFixture;
  /** Status the mocked server answers a correction with; 201 accepts it. */
  respondWith: { status: number; message?: string };
}

async function mockApi(page: Page, fixture: ChecklistFixture = checklistA()): Promise<CorrectionApi> {
  const api: CorrectionApi = { corrections: [], inspections: [], inspectionStatus: 503, fixture, respondWith: { status: 201 } };

  await page.route('**/images/mbfd_logo-256.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo-256.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 101, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_101, employee_number: 'E101', name: 'Captain Browser', rank: 'Captain' },
          offline: { security_version: 1 },
          session: { authenticated: true },
        },
      });
    }
    if (path === '/api/public/fleet-vehicles') return route.fulfill({ json: { vehicles: [] } });
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === `/api/public/apparatuses/${apparatus.id}/service-notices`) return route.fulfill({ json: { data: [] } });
    if (path === `/api/public/apparatuses/${apparatus.id}/checklist`) {
      return route.fulfill({
        json: {
          checklist_version: api.fixture.version,
          checklist_base_version: api.fixture.base,
          pending_corrections: api.fixture.pending,
          checklist: { compartments: api.fixture.compartments },
        },
      });
    }
    if (path === CORRECTION_ROUTE && request.method() === 'POST') {
      api.corrections.push(request.postDataJSON() as Record<string, unknown>);
      const { status, message } = api.respondWith;
      return route.fulfill(status < 300
        ? { status, json: { id: 1, reference: 'IC-000001', status: 'submitted', duplicate: false } }
        : { status, json: { message: message ?? 'Unavailable.' } });
    }

    if (path === `/api/public/apparatuses/${apparatus.id}/inspections` && request.method() === 'POST') {
      api.inspections.push(request.postDataJSON() as Record<string, unknown>);
      return route.fulfill(api.inspectionStatus < 300 ? { status: api.inspectionStatus, json: { success: true, review_status: 'approved' } } : { status: api.inspectionStatus, json: { message: 'Down for maintenance.' } });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  return api;
}

const row = (page: Page, name: string): Locator => page.locator('article.equipment-row').filter({ hasText: name });

async function openItem(page: Page, name: string): Promise<Locator> {
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).click();
  return row(page, name);
}

async function openCorrection(page: Page, itemName: string): Promise<Locator> {
  const item = await openItem(page, itemName);
  await item.getByRole('button', { name: 'Report inventory correction' }).click();
  return item;
}

async function queuedCorrections(page: Page): Promise<Array<{ type: string; status: string; ownerUserId?: number; data: Record<string, unknown> }>> {
  return page.evaluate(async (type) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    try {
      const rows = await new Promise<Array<{ type: string; status: string; ownerUserId?: number; data: Record<string, unknown> }>>((resolve, reject) => {
        const request = database.transaction('pendingSubmissions', 'readonly').objectStore('pendingSubmissions').getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
      return rows.filter((entry) => entry.type === type);
    } finally {
      database.close();
    }
  }, QUEUE_TYPE);
}

async function seedQueuedCorrection(page: Page, data: Record<string, unknown>): Promise<void> {
  await page.evaluate(async ({ type, payload }) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    try {
      await new Promise<void>((resolve, reject) => {
        const request = database.transaction('pendingSubmissions', 'readwrite').objectStore('pendingSubmissions').add({
          type, data: payload, createdAt: new Date(), status: 'pending', retryCount: 0, ownerUserId: 101, ownerSecurityVersion: 1, ownershipState: 'owned',
        });
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
      });
    } finally {
      database.close();
    }
  }, { type: QUEUE_TYPE, payload: data });
}

async function openInspection(page: Page): Promise<void> {
  await page.goto('/daily/apparatus/engine-1');
  await expect(page.getByRole('region', { name: 'Compartment Inspection' })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Portable Radio/ })).toBeVisible();
}

test('reporting that an item moved sends the full report and marks the item pending', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  await expect(item.getByText('Still record what you see today. Use Missing if it should be here but isn\'t.')).toBeVisible();
  await item.getByRole('radio', { name: 'Moved to a different compartment' }).check();
  // The current compartment is not offered as a destination.
  await expect(item.getByLabel('Where is it carried now?').locator('option')).toHaveText(['Choose a compartment', 'Compartment B1', 'Somewhere else']);
  await item.getByLabel('Where is it carried now?').selectOption('b1');
  await item.getByLabel('Note (optional)').fill('Moved last week.');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Sent to Logistics for review' })).toBeVisible();
  await expect.poll(() => api.corrections.length).toBe(1);
  expect(api.corrections[0]).toEqual({
    client_submission_id: expect.stringMatching(UUID),
    checklist_version: api.fixture.version,
    reason: 'moved',
    compartment_id: 'cab',
    item_id: 'cab-flashlight',
    proposal: { to_compartment_id: 'b1' },
    note: 'Moved last week.',
    inspection_draft_id: expect.stringMatching(UUID),
  });
  await expect(item.getByText('Correction pending review')).toBeVisible();

  // The same draft id travels with the autosaved draft.
  await expect.poll(async () => (await readDrafts(page))[0]?.data.draftId).toBe(api.corrections[0].inspection_draft_id);
});

test('a place outside the listed compartments is sent as free text', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  await item.getByRole('radio', { name: 'Moved to a different compartment' }).check();
  await item.getByLabel('Where is it carried now?').selectOption({ label: 'Somewhere else' });
  await item.getByLabel('Where', { exact: true }).fill('Station 3 supply closet');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();

  await expect.poll(() => api.corrections.length).toBe(1);
  expect(api.corrections[0].proposal).toEqual({ to_location_text: 'Station 3 supply closet' });
});

test('required answers are explained before anything is sent', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(item.getByRole('alert')).toContainText('Choose what is wrong.');
  await item.getByRole('radio', { name: 'Something else' }).check();
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(item.getByRole('alert')).toContainText('Tell Logistics what is wrong.');
  await expect(item.getByLabel('Note (required)')).toBeFocused();

  await item.getByRole('radio', { name: 'Quantity is wrong' }).check();
  // Prefilled with the expected quantity.
  await expect(item.getByLabel('Correct quantity')).toHaveValue('3');
  await item.getByLabel('Correct quantity').fill('1000');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(item.getByRole('alert')).toContainText('Enter a whole number from 1 to 999.');
  expect(api.corrections).toHaveLength(0);
});

test('offline reports are saved on the device and replayed exactly once with the same id', async ({ page, context }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await context.setOffline(true);

  const item = await openCorrection(page, 'Flashlight');
  await item.getByRole('radio', { name: 'Quantity is wrong' }).check();
  await item.getByLabel('Correct quantity').fill('2');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();

  const saved = page.getByRole('status').filter({ hasText: 'Saved on this device. It will be sent when you\'re back online.' });
  await expect(saved).toBeVisible();
  await expect(page.getByText('Sent to Logistics for review')).toHaveCount(0);
  await expect(item.getByText('Correction pending review')).toBeVisible();

  const queued = await queuedCorrections(page);
  expect(queued).toHaveLength(1);
  expect(queued[0]).toMatchObject({
    status: 'pending',
    ownerUserId: 101,
    data: { reason: 'wrong_quantity', compartment_id: 'cab', item_id: 'cab-flashlight', proposal: { quantity: 2 }, client_submission_id: expect.stringMatching(UUID) },
  });
  expect(api.corrections).toHaveLength(0);

  await context.setOffline(false);
  // Two overlapping triggers must still send the record once.
  await page.evaluate(() => {
    window.dispatchEvent(new Event('online'));
    window.dispatchEvent(new Event('online'));
  });
  await expect.poll(() => api.corrections.length).toBe(1);
  await expect.poll(async () => (await queuedCorrections(page)).length).toBe(0);
  await page.waitForTimeout(750);
  expect(api.corrections).toHaveLength(1);
  expect(api.corrections[0].client_submission_id).toBe(queued[0].data.client_submission_id);
  await expect(item.getByText('Correction pending review')).toBeVisible();
});

test('a report saved on the device keeps its marker after the page is reloaded', async ({ page }) => {
  const api = await mockApi(page);
  api.respondWith = { status: 503, message: 'Down for maintenance.' };
  await openInspection(page);
  await seedQueuedCorrection(page, {
    client_submission_id: '11111111-2222-4333-8444-555555555555', checklist_version: api.fixture.version, reason: 'no_longer_carried', compartment_id: 'cab', item_id: 'cab-flashlight',
  });
  await seedQueuedCorrection(page, {
    client_submission_id: '66666666-7777-4888-8999-aaaaaaaaaaaa', checklist_version: api.fixture.version, reason: 'new_item', compartment_id: 'cab', proposal: { name: 'Spanner' },
  });

  await page.reload();
  await expect(page.getByRole('button', { name: /^Portable Radio/ })).toBeVisible();
  await expect(row(page, 'Flashlight').getByText('Correction pending review')).toBeVisible();
  await expect(row(page, 'Portable Radio').getByText('Correction pending review')).toHaveCount(0);
  await expect(page.getByText('1 unlisted item reported here, pending review.')).toBeVisible();
});

test('a refused report says it was not sent, is not a rejection, and keeps everything that was typed', async ({ page }) => {
  const api = await mockApi(page);
  api.respondWith = { status: 422, message: 'That item is not on this checklist.' };
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  await item.getByRole('radio', { name: 'Something else' }).check();
  await item.getByLabel('Note (required)').fill('It is a different light.');
  await item.getByRole('button', { name: 'Send to Logistics' }).click();

  await expect(item.getByRole('alert')).toContainText('Your report was not sent. That item is not on this checklist. Nothing was rejected: Logistics has not seen it.');
  await expect(item.getByLabel('Note (required)')).toHaveValue('It is a different light.');
  await expect(item.getByRole('button', { name: 'Send to Logistics' })).toBeEnabled();
  expect(await queuedCorrections(page)).toHaveLength(0);
  await expect(item.getByText('Correction pending review')).toHaveCount(0);

  // Collapsing and reopening the panel keeps the text too, and a corrected retry is accepted.
  await item.getByRole('button', { name: 'Report inventory correction' }).click();
  await item.getByRole('button', { name: 'Report inventory correction' }).click();
  await expect(item.getByLabel('Note (required)')).toHaveValue('It is a different light.');
  api.respondWith = { status: 201 };
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Sent to Logistics for review' })).toBeVisible();
  expect(api.corrections).toHaveLength(2);
});

test('an unlisted item is reported for the compartment without an item id', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  await page.getByRole('button', { name: 'Report an unlisted item' }).click();
  await page.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Enter the name of the item.' })).toBeVisible();

  await page.getByLabel('Item name').fill('Spanner wrench');
  await page.getByLabel('Quantity (optional)').fill('2');
  await page.getByLabel('Manufacturer (optional)').fill('Task Force Tips');
  await page.getByLabel('Note (optional)').fill('Carried since the refit.');
  await page.getByRole('button', { name: 'Send to Logistics' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Sent to Logistics for review' })).toBeVisible();
  expect(api.corrections).toHaveLength(1);
  expect(api.corrections[0]).toEqual({
    client_submission_id: expect.stringMatching(UUID),
    checklist_version: api.fixture.version,
    reason: 'new_item',
    compartment_id: 'cab',
    proposal: { name: 'Spanner wrench', manufacturer: 'Task Force Tips', quantity: 2 },
    note: 'Carried since the refit.',
    inspection_draft_id: expect.stringMatching(UUID),
  });
  expect(api.corrections[0]).not.toHaveProperty('item_id');
  await expect(page.getByText('1 unlisted item reported here, pending review.')).toBeVisible();
});

test('server pending corrections mark their items and compartment', async ({ page }) => {
  const fixture = checklistA();
  const base = { reason_label: 'Moved', reported_at: '2026-10-23T12:00:00Z' };
  fixture.pending = [
    { ...base, id: 7, compartment_id: 'cab', item_id: 'cab-flashlight', reason: 'moved', status: 'submitted', mine: false },
    { ...base, id: 8, compartment_id: 'cab', item_id: 'cab-radio', reason: 'replaced', status: 'clarification_requested', mine: true },
    { ...base, id: 9, compartment_id: 'cab', item_id: null, reason: 'new_item', status: 'submitted', mine: true },
    { ...base, id: 10, compartment_id: 'b1', item_id: 'b1-hose', reason: 'other', status: 'clarification_requested', mine: false },
  ];
  await mockApi(page, fixture);
  await openInspection(page);

  await expect(row(page, 'Flashlight').getByText('Correction pending review')).toBeVisible();
  const radio = row(page, 'Portable Radio');
  await expect(radio.getByText('Logistics asked a question')).toBeVisible();
  await expect(radio.getByRole('link', { name: 'Open the question' })).toHaveAttribute('href', '/employee/my-inventory-corrections/8');
  await expect(page.getByText('1 unlisted item reported here, pending review.')).toBeVisible();

  // A question asked of someone else's report is only a pending marker for this member.
  await page.getByRole('button', { name: /Compartment B1/ }).click();
  await expect(row(page, 'Supply Hose').getByText('Correction pending review')).toBeVisible();
  await expect(row(page, 'Supply Hose').getByText('Logistics asked a question')).toHaveCount(0);
});

test('item details show manufacturer, model and description as one muted line', async ({ page }) => {
  await mockApi(page);
  await openInspection(page);
  const item = await openItem(page, 'Flashlight');
  await expect(item.locator('.equipment-spec')).toHaveText('Streamlight · Stinger 2020 · Rechargeable');
  const radio = await openItem(page, 'Portable Radio');
  await expect(radio.locator('.equipment-spec')).toHaveCount(0);
});

test('a draft saved before an inventory change is carried over; renamed and new items need a look', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  const radio = await openItem(page, 'Portable Radio');
  await radio.getByRole('group', { name: 'Status for Portable Radio' }).getByRole('button', { name: 'Damaged' }).click();
  await radio.getByLabel('Notes (optional)').fill('Cracked lens.');
  await page.getByRole('button', { name: 'Confirm all present' }).click();
  await page.getByRole('button', { name: /Compartment B1/ }).click();
  await page.getByRole('button', { name: 'Confirm all present' }).click();
  await expect.poll(async () => {
    const [draft] = await readDrafts(page);
    return draft?.data.compartments.every((area) => area.items.every((item) => item.observed === true));
  }).toBe(true);
  const [original] = await readDrafts(page);
  expect(original.data.checklist_version).toBe('a'.repeat(64));
  expect(original.data.checklistSnapshot?.checklist_base_version).toBe(BASE_VERSION);

  // Logistics renamed the radio and added a spanner wrench: a new version over the same baseline template.
  api.fixture = checklistA();
  api.fixture.version = 'c'.repeat(64);
  api.fixture.compartments[0].items[0] = { ...api.fixture.compartments[0].items[0], name: 'Portable Radio (XTS)' };
  api.fixture.compartments[0].items.push({ id: 'cab-spanner', name: 'Spanner Wrench', status: 'Present', notes: '' });
  await page.reload();

  await expect(page.getByRole('alert').filter({ hasText: 'This apparatus\'s inventory was updated while you were working. Your answers were kept; 2 items need a look.' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Compartment Inspection' })).toBeVisible();

  const renamed = row(page, 'Portable Radio (XTS)');
  await expect(renamed).toContainText('Needs inspection');
  await renamed.getByRole('button', { name: /^Portable Radio/ }).click();
  await expect(renamed.getByLabel('Notes (optional)')).toHaveValue('Cracked lens.');
  await expect(renamed.getByRole('group', { name: 'Status for Portable Radio (XTS)' }).getByRole('button', { name: 'Damaged' })).toHaveAttribute('aria-pressed', 'false');
  await expect(row(page, 'Flashlight')).toContainText('Inspected');
  await expect(row(page, 'Spanner Wrench')).toContainText('Needs inspection');

  // Answers in the other compartment came along, and only the migrated draft remains.
  await page.getByRole('button', { name: /Compartment B1/ }).click();
  await expect(row(page, 'Supply Hose')).toContainText('Inspected');
  await expect.poll(async () => (await readDrafts(page)).map((draft) => draft.data.checklist_version)).toEqual(['c'.repeat(64)]);
  const [migrated] = await readDrafts(page);
  expect(migrated.data.draftId).toBe(original.data.draftId);
  expect(migrated.data.checklistSnapshot?.checklist_base_version).toBe(BASE_VERSION);
});

test('a draft from a different baseline template is not migrated', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await page.getByRole('button', { name: 'Confirm all present' }).click();
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments[0]?.items.every((item) => item.observed === true)).toBe(true);

  api.fixture = { ...checklistA(), version: 'd'.repeat(64), base: '9'.repeat(64) };
  await page.reload();

  await expect(page.getByRole('alert')).toContainText('A previously saved inspection uses a different checklist version');
  await expect(row(page, 'Portable Radio')).toContainText('Needs inspection');
});
test('the correction control never changes what the member records today', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);

  const item = await openItem(page, 'Flashlight');
  const status = item.getByRole('group', { name: 'Status for Flashlight' });
  for (const name of ['Present', 'Missing', 'Damaged'] as const) {
    await status.getByRole('button', { name }).click();
    await expect(status.getByRole('button', { name })).toHaveAttribute('aria-pressed', 'true');
  }

  await item.getByRole('button', { name: 'Report inventory correction' }).click();
  await item.getByRole('radio', { name: 'No longer carried on this apparatus' }).check();
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Sent to Logistics for review' })).toBeVisible();
  expect(api.corrections[0]).toMatchObject({ reason: 'no_longer_carried', item_id: 'cab-flashlight' });
  expect(api.corrections[0]).not.toHaveProperty('proposal');
  // Still Damaged, still the member's own observation.
  await expect(status.getByRole('button', { name: 'Damaged' })).toHaveAttribute('aria-pressed', 'true');
  await expect(item).toContainText('Damaged');

  await status.getByRole('button', { name: 'Missing' }).click();
  await expect(status.getByRole('button', { name: 'Missing' })).toHaveAttribute('aria-pressed', 'true');
  await expect(status.getByRole('button', { name: 'Damaged' })).toHaveAttribute('aria-pressed', 'false');
});

test('an unanswered item stays unanswered after a correction is reported for it', async ({ page }) => {
  await mockApi(page);
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  await item.getByRole('radio', { name: 'No longer carried on this apparatus' }).check();
  await item.getByRole('button', { name: 'Send to Logistics' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Sent to Logistics for review' })).toBeVisible();
  await expect(item).toContainText('Needs inspection');
  await expect(item.getByRole('group', { name: 'Status for Flashlight' }).getByRole('button', { name: 'Present' })).toHaveAttribute('aria-pressed', 'false');
});

test('the correction controls are labelled and at least 44px tall', async ({ page }) => {
  await mockApi(page);
  await page.setViewportSize({ width: 320, height: 640 });
  await openInspection(page);

  const item = await openCorrection(page, 'Flashlight');
  const controls = [
    item.getByRole('button', { name: 'Report inventory correction' }),
    item.getByRole('radio', { name: 'Something else' }),
  ];
  const group = item.getByRole('group', { name: 'What is wrong with the list?' });
  await expect(group).toBeVisible();
  const toggle = await controls[0].boundingBox();
  expect(toggle?.height).toBeGreaterThanOrEqual(44);
  const option = await item.locator('.ic-option').first().boundingBox();
  expect(option?.height).toBeGreaterThanOrEqual(44);
  await item.getByRole('radio', { name: 'Something else' }).check();
  const note = await item.getByLabel('Note (required)').boundingBox();
  expect(note?.height).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

async function drawSignature(page: Page): Promise<void> {
  const canvas = page.locator('canvas');
  await expect(canvas).toHaveCount(1);
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('The inspection signature canvas is not visible.');
  const startX = box.x + 24;
  const startY = box.y + box.height / 2;
  await page.mouse.move(startX, startY);
  await page.mouse.down();
  for (let step = 1; step <= 8; step += 1) await page.mouse.move(startX + ((box.width - 48) * step) / 8, startY + step);
  await page.mouse.up();
}

/** Completes the checkout and submits it while offline, so it is saved in the Daily Checkout queue. */
async function queueCheckoutOffline(page: Page, context: BrowserContext): Promise<void> {
  await page.getByRole('button', { name: 'Member / Vehicle Info', exact: true }).first().click();
  await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
  await page.getByRole('button', { name: 'Continue: Apparatus' }).click();
  await page.getByRole('button', { name: 'Confirm all present' }).click();
  await page.getByRole('button', { name: /Compartment B1/ }).click();
  await page.getByRole('button', { name: 'Confirm all present' }).click();
  await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
  await drawSignature(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect(page.getByRole('heading', { name: 'Inspection saved on this device' })).toBeVisible();
  await context.setOffline(false);
}

async function queuedCheckouts(page: Page): Promise<Array<{ apparatusId: number; checklistVersion: string; checklistBaseVersion?: string; data: { client_submission_id: string } }>> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    try {
      return await new Promise<Array<{ apparatusId: number; checklistVersion: string; checklistBaseVersion?: string; data: { client_submission_id: string } }>>((resolve, reject) => {
        const request = database.transaction('dailyCheckoutSubmissions', 'readonly').objectStore('dailyCheckoutSubmissions').getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
    } finally {
      database.close();
    }
  });
}

test('a checkout queued before an inventory change still blocks a second checkout on the new version', async ({ page, context }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await queueCheckoutOffline(page, context);

  const [queued] = await queuedCheckouts(page);
  expect(queued).toMatchObject({ apparatusId: apparatus.id, checklistVersion: 'a'.repeat(64), checklistBaseVersion: BASE_VERSION });

  // Logistics approved a correction: a new checklist version over the same baseline template.
  api.fixture = checklistA();
  api.fixture.version = 'c'.repeat(64);
  api.fixture.compartments[0].items[0] = { ...api.fixture.compartments[0].items[0], name: 'Portable Radio (XTS)' };
  await page.goto('/daily/apparatus/engine-1');

  await expect(page.getByRole('heading', { name: 'Saved inspection not sent yet' })).toBeVisible();
  await expect(page.getByText('A saved Daily Checkout using this checklist version is still awaiting synchronization.')).toBeVisible();
  await expect(page.getByRole('region', { name: 'Compartment Inspection' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Submit Inspection' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Confirm all present' })).toHaveCount(0);

  // Only the queued record's own id was ever sent; no second checkout exists, in the queue or on the wire.
  await page.waitForTimeout(750);
  expect((await queuedCheckouts(page)).map((record) => record.data.client_submission_id)).toEqual([queued.data.client_submission_id]);
  expect(new Set(api.inspections.map((submission) => submission.client_submission_id))).toEqual(new Set([queued.data.client_submission_id]));
});

test('a queued checkout does not block a checkout on a different baseline template', async ({ page, context }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await queueCheckoutOffline(page, context);
  expect(await queuedCheckouts(page)).toHaveLength(1);

  api.fixture = { ...checklistA(), version: 'd'.repeat(64), base: '9'.repeat(64) };
  await page.goto('/daily/apparatus/engine-1');

  await expect(page.getByRole('region', { name: 'Compartment Inspection' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Saved inspection not sent yet' })).toHaveCount(0);
  await expect(row(page, 'Portable Radio')).toContainText('Needs inspection');
});

test('another member\'s minimal pending marker still reads "Correction pending review"', async ({ page }) => {
  const fixture = checklistA();
  fixture.pending = [
    { id: null, reason: null, status: 'pending', mine: false, compartment_id: 'cab', item_id: 'cab-flashlight' },
    { id: null, reason: null, status: 'pending', mine: false, compartment_id: 'b1', item_id: null },
  ];
  await mockApi(page, fixture);
  await openInspection(page);

  await expect(row(page, 'Flashlight').getByText('Correction pending review')).toBeVisible();
  await expect(row(page, 'Portable Radio').getByText('Correction pending review')).toHaveCount(0);
  await page.getByRole('button', { name: /Compartment B1/ }).click();
  await expect(page.getByText('1 unlisted item reported here, pending review.')).toBeVisible();
});