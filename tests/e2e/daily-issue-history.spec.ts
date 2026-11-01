import { expect, test, type Locator, type Page } from '@playwright/test';
import { readDrafts } from './support/daily-checkout-drafts';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';

/**
 * Apparatus issue history on the Daily Checkout (docs/apparatus-issue-history.md): the live summary, issue cards
 * with a lazily loaded timeline, refresh on focus without touching answers, the offline snapshot, concerns in the
 * payload, and the read-only issues page. The API is mocked per test; nothing here proves production data.
 */

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

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

const issue = (extra: Record<string, unknown> = {}) => ({
  id: 41,
  compartment: 'Driver L4',
  item: "Driver's Gear",
  compartment_id: 'l4',
  item_id: 'drivers-gear',
  issue_type: 'missing',
  severity: 'routine',
  description: 'The gear bag was not in the compartment.',
  reporter_name: 'Dana Ortiz',
  first_reported_at: '2026-10-30T15:00:00-04:00',
  action_status: 'replacement_available',
  action_label: 'Replacement available',
  closure_kind: null,
  latest_action: { label: 'Replacement ordered', actor_name: 'Sam Lee', at: '2026-10-31T09:00:00-04:00', text: 'Ordered from the vendor.' },
  last_observation: 'confirmed_again',
  last_observed_at: '2026-11-01T08:00:00-04:00',
  service_status: 'open',
  recurrence_of_defect_id: null,
  history_count: 2,
  ...extra,
});

const unmatched = issue({
  id: 52,
  compartment: 'Old Combined Compartment',
  item: 'Legacy Rescue Bag',
  compartment_id: null,
  item_id: null,
  issue_type: 'damaged',
  severity: 'significant',
  description: 'Strap torn.',
  reporter_name: null,
  first_reported_at: null,
  action_status: 'awaiting_verification',
  action_label: 'Awaiting verification',
  latest_action: null,
  history_count: 1,
});

const checklistFinding = {
  id: 41,
  compartment: 'Driver L4',
  item: "Driver's Gear",
  compartment_id: 'l4',
  item_id: 'drivers-gear',
  issue_type: 'missing',
  operational_impact: 'unclassified',
  last_observation: 'confirmed_again',
  last_observed_at: '2026-11-01T08:00:00-04:00',
  service_status: 'open',
  first_reported_at: '2026-10-30T15:00:00-04:00',
};

const checklist = {
  checklist_version: 'a'.repeat(64),
  open_findings: [checklistFinding],
  checklist: {
    compartments: [
      { id: 'cab', name: 'Cab', items: [{ id: 'portable-radio', name: 'Portable Radio', status: 'Present', notes: '' }, { id: 'siren', name: 'Siren', status: 'Present', notes: '' }] },
      { id: 'l4', name: 'Driver L4', items: [{ id: 'drivers-gear', name: "Driver's Gear", status: 'Present', notes: '' }, { id: 'spanner', name: 'Spanner Wrench', status: 'Present', notes: '' }] },
    ],
  },
};

const timeline = [
  { id: 'event-1', kind: 'event', type: 'reported', label: 'Reported', lines: ['Marked missing on the daily checkout'], text: null, actor_name: 'Dana Ortiz', at: '2026-10-30T15:00:00-04:00', photo_url: '/api/public/apparatus-issues/41/photo' },
  { id: 'message-2', kind: 'message', type: 'update', label: 'Update from staff', lines: [], text: 'Replacement ordered.', actor_name: null, at: '2026-10-31T09:00:00-04:00', photo_url: null },
];

const closedPages: Record<string, { data: unknown[]; next_cursor: string | null }> = {
  first: { data: [issue({ id: 70, item: 'Pike pole', compartment: 'Rear', closure_kind: 'resolved', action_status: 'resolved_replaced', action_label: 'Replaced', latest_action: null })], next_cursor: 'c2' },
  c2: { data: [issue({ id: 71, item: 'Door latch', compartment: 'Cab', closure_kind: 'closed_no_action', action_status: 'closed_no_action', action_label: 'Closed with no action', latest_action: null })], next_cursor: null },
};

interface Api {
  submissions: Array<Record<string, any>>;
  findingsRequests: number;
  historyRequests: number[];
  closedRequests: Array<string | null>;
  findings: { as_of: string; open: unknown[]; notes: unknown[]; closed_count: number };
  /** The next this many history requests fail. */
  failHistory: number;
  findingsDelayMs: number;
  findingsMode: 'ok' | 'abort';
}

async function mockApi(page: Page): Promise<Api> {
  const api: Api = {
    submissions: [],
    findingsRequests: 0,
    historyRequests: [],
    closedRequests: [],
    findings: {
      as_of: '2026-11-01T18:00:00Z',
      open: [issue(), unmatched],
      notes: [{ id: 9, description: 'Hose bed latch is stiff', location: 'Rear', reporter_name: 'Kim Alvarez', reported_at: '2026-10-31T07:00:00-04:00', inspection_reference: null, photo_url: null }],
      closed_count: 2,
    },
    failHistory: 0,
    findingsDelayMs: 0,
    findingsMode: 'ok',
  };
  await page.route('**/images/mbfd_logo-256.png', route => route.fulfill({ path: 'public/images/mbfd_logo-256.png' }));
  await page.route('**/api/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;

    if (path === '/api/me/context') {
      return route.fulfill({ json: {
        identity: { user_id: 401, has_personnel_profile: true },
        personnel: { employee_profile_id: 501, employee_number: 'E501', name: 'Captain Browser', rank: 'Captain' },
        offline: { security_version: 1 }, session: { authenticated: true },
      } });
    }
    if (path === '/api/public/fleet-vehicles') return route.fulfill({ json: { vehicles: [] } });
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [apparatus] });
    if (path === `/api/public/apparatuses/${apparatus.id}/checklist`) return route.fulfill({ json: checklist });
    if (path === `/api/public/apparatuses/${apparatus.id}/service-notices`) return route.fulfill({ json: { data: [] } });
    if (path === `/api/public/apparatuses/${apparatus.id}/findings`) {
      api.findingsRequests += 1;
      if (api.findingsMode === 'abort') return route.abort('failed');
      if (api.findingsDelayMs > 0) await new Promise(resolve => setTimeout(resolve, api.findingsDelayMs));
      return route.fulfill({ json: api.findings });
    }
    if (path === `/api/public/apparatuses/${apparatus.id}/findings/closed`) {
      const cursor = url.searchParams.get('cursor');
      api.closedRequests.push(cursor);
      return route.fulfill({ json: closedPages[cursor ?? 'first'] });
    }
    const history = /^\/api\/public\/apparatus-issues\/(\d+)\/history$/.exec(path);
    if (history) {
      api.historyRequests.push(Number(history[1]));
      if (api.failHistory > 0) {
        api.failHistory -= 1;
        return route.fulfill({ status: 503, json: { message: 'Busy.' } });
      }
      return route.fulfill({ json: { issue: issue(), timeline } });
    }
    if (path.endsWith('/photo')) return route.fulfill({ contentType: 'image/png', body: PNG });
    if (path === `/api/public/apparatuses/${apparatus.id}/inspections` && request.method() === 'POST') {
      api.submissions.push(request.postDataJSON());
      return route.fulfill({ status: 201, json: { success: true, review_status: 'approved' } });
    }
    if (path === '/api/public/stations/1') {
      return route.fulfill({ json: { id: 1, name: 'Station 1', station_number: 1, address: '1 Test Street', city: 'Miami Beach', state: 'FL', zip_code: '33139', phone: '', is_active: true, apparatuses: [apparatus] } });
    }
    if (path === '/api/public/inspection-revisions') return route.fulfill({ json: { data: [] } });
    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });
  return api;
}

async function openInspection(page: Page) {
  await page.goto('/daily/vehicle-inspections/engine-1');
  await expect(page.getByRole('heading', { name: 'Engine 1', exact: true })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Compartment Inspection' })).toBeVisible();
}

const summaryOf = (page: Page, name: string | RegExp) => page.getByRole('region', { name });
const cardFor = (page: Page, id: number) => page.locator(`[data-issue-id="${id}"].issue-card`);

async function showAreaPicker(page: Page) {
  const change = page.getByRole('button', { name: 'Change area', exact: true });
  if (await change.isVisible()) await change.click();
}

async function jumpToDriversGear(page: Page): Promise<Locator> {
  await summaryOf(page, /open issue/).getByRole('button', { name: /Driver's Gear/ }).click();
  const card = cardFor(page, 41).first();
  await expect(card).toBeVisible();
  return card;
}

async function sign(page: Page) {
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('The signature canvas is not visible.');
  await page.mouse.move(box.x + 24, box.y + box.height / 2);
  await page.mouse.down();
  for (let step = 1; step <= 8; step += 1) await page.mouse.move(box.x + 24 + (box.width - 48) * step / 8, box.y + box.height / 2 + step);
  await page.mouse.up();
}

/** Member step, every area confirmed, then Review & Sign. */
async function reachReview(page: Page) {
  await page.getByRole('button', { name: 'Member / Vehicle Info', exact: true }).first().click();
  await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('A');
  await page.getByRole('button', { name: 'Continue: Apparatus' }).click();
  for (const id of ['cab', 'l4']) {
    await showAreaPicker(page);
    await page.locator(`button[data-area-id="${id}"]`).click();
    await page.getByRole('button', { name: 'Confirm all present' }).click();
  }
  await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Review & Sign Inspection' })).toBeVisible();
}

test('the summary counts open issues, jumps to the item, and keeps an unmatched issue under Other recorded findings', async ({ page }) => {
  await mockApi(page);
  await openInspection(page);

  const summary = summaryOf(page, '2 open issues on this apparatus');
  await expect(summary).toBeVisible();
  await expect(summary.locator('[data-live-line]')).toContainText(/^Updated .*1:00 PM$/);
  const row = summary.getByRole('button', { name: /Driver's Gear/ });
  expect((await row.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  // The unmatched issue is never attached to a guess: it has no jump button and no item.
  await expect(summary.getByRole('button', { name: /Legacy Rescue Bag/ })).toHaveCount(0);

  await row.click();
  await expect(page.locator('[data-item-id="drivers-gear"]')).toHaveAttribute('aria-expanded', 'true');
  const card = page.locator('[data-previous-issues="drivers-gear"] [data-issue-id="41"]');
  await expect(card).toBeVisible();
  await expect(card).toContainText("Driver's Gear");
  await expect(card).toContainText('Driver L4');
  await expect(card).toContainText('The gear bag was not in the compartment.');
  await expect(card).toContainText('Reported by Dana Ortiz on Oct 30, 2026');
  await expect(card).toContainText('Replacement available');
  await expect(card).toContainText('Latest action: Replacement ordered by Sam Lee on Oct 31, 2026');

  const other = summary.locator('details.inspection-unlocated-findings');
  await expect(other.locator(':scope > summary')).toHaveText('Other recorded findings (1)');
  await other.locator(':scope > summary').click();
  await expect(other.locator('[data-issue-id="52"]')).toContainText('Legacy Rescue Bag');
  await expect(other.locator('[data-issue-id="52"]')).toContainText('Reported by Not recorded');
  await expect(other.locator('[data-issue-id="52"]')).toContainText('Significant');
  await expect(page.locator('[data-previous-issues]').filter({ hasText: 'Legacy Rescue Bag' })).toHaveCount(0);

  await summary.locator('details.findings-notes > summary').click();
  await expect(summary.locator('.findings-note')).toContainText('Hose bed latch is stiff');
  await expect(summary.locator('.findings-note')).toContainText('Noted by Kim Alvarez on Oct 31, 2026');
});

test('an issue card loads its timeline only when opened, and can retry a failed load', async ({ page }) => {
  const api = await mockApi(page);
  api.failHistory = 1;
  await openInspection(page);
  const card = await jumpToDriversGear(page);

  expect(api.historyRequests).toEqual([]);
  await card.locator('summary').click();
  await expect(card.getByRole('alert')).toContainText('could not be loaded');
  expect(api.historyRequests).toEqual([41]);
  await card.getByRole('button', { name: 'Try again' }).click();

  const entries = card.getByTestId('issue-timeline');
  await expect(entries).toBeVisible();
  expect(api.historyRequests).toEqual([41, 41]);
  await expect(entries.locator('li').nth(0)).toContainText('Reported');
  await expect(entries.locator('li').nth(0)).toContainText('Dana Ortiz');
  await expect(entries.locator('li').nth(0)).toContainText('Oct 30, 2026, 3:00 PM');
  await expect(entries.locator('li').nth(0)).toContainText('Marked missing on the daily checkout');
  await expect(entries.locator('li').nth(0).getByRole('img', { name: 'Photo attached to this update' })).toBeAttached();
  // Oldest first, and a missing actor is printed as such rather than guessed.
  await expect(entries.locator('li').nth(1)).toContainText('Replacement ordered.');
  await expect(entries.locator('li').nth(1)).toContainText('Not recorded');
  await expect(card.locator('summary')).toContainText('Hide history');
});

test('a status change delivered by a refresh on focus updates the card while typed notes and answers stay', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  const card = await jumpToDriversGear(page);
  await page.locator('#note-drivers-gear').fill('Bag found in the wrong bay');
  await page.getByRole('button', { name: 'Pass Spanner Wrench', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Pass Spanner Wrench', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments.flatMap(area => area.items).find(item => item.id === 'drivers-gear')?.notes).toBe('Bag found in the wrong bay');
  const before = (await readDrafts(page))[0].data;
  const requestsBefore = api.findingsRequests;

  api.findings = {
    ...api.findings,
    as_of: '2026-11-01T18:05:00Z',
    open: [issue({ action_status: 'installed', action_label: 'Replacement installed, awaiting verification', history_count: 3, latest_action: { label: 'Replacement installed', actor_name: 'Sam Lee', at: '2026-11-01T13:00:00-04:00', text: null } })],
  };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));

  await expect(card).toContainText('Replacement installed, awaiting verification');
  await expect(card).toContainText('Latest action: Replacement installed by Sam Lee');
  await expect(summaryOf(page, '1 open issue on this apparatus')).toBeVisible();
  expect(api.findingsRequests).toBe(requestsBefore + 1);
  // Nothing the member typed or answered moved.
  await expect(page.locator('#note-drivers-gear')).toHaveValue('Bag found in the wrong bay');
  await expect(page.getByRole('button', { name: 'Pass Spanner Wrench', exact: true })).toHaveAttribute('aria-pressed', 'true');
  const after = (await readDrafts(page))[0].data;
  expect(after.compartments).toEqual(before.compartments);
  expect(after.checklistSnapshot?.open_findings).toEqual(before.checklistSnapshot?.open_findings);
  expect(JSON.stringify(after)).not.toContain('Replacement installed');
  expect(Object.keys(after)).not.toContain('findings');
});

test('an issue the live list no longer has open stays visible, marked, and is not counted', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await expect(summaryOf(page, '2 open issues on this apparatus')).toBeVisible();

  api.findings = { ...api.findings, open: [] };
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));

  await expect(summaryOf(page, 'No open issues on this apparatus')).toBeVisible();
  await page.locator('button[data-area-id="l4"]').click();
  const known = page.locator('[data-previous-issues="drivers-gear"]');
  await expect(known).toContainText('Previously reported: Missing');
  await expect(known).toContainText('No longer listed as open. It may have been resolved since this page loaded.');
});

test('overlapping refreshes are dropped while one is still running', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await expect(summaryOf(page, '2 open issues on this apparatus')).toBeVisible();
  const before = api.findingsRequests;

  api.findingsDelayMs = 400;
  await page.evaluate(() => { for (let index = 0; index < 5; index += 1) window.dispatchEvent(new Event('focus')); });
  await expect.poll(() => api.findingsRequests).toBe(before + 1);
  await page.waitForTimeout(600);
  expect(api.findingsRequests).toBe(before + 1);
});

test('offline shows the last snapshot with its time, never as live', async ({ page, context }) => {
  const api = await mockApi(page);
  await openInspection(page);
  const summary = summaryOf(page, '2 open issues on this apparatus');
  await expect(summary.locator('[data-live-line]')).toContainText('Updated');
  const requests = api.findingsRequests;

  await context.setOffline(true);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(summary).toHaveAttribute('data-live-state', 'stale');
  await expect(summary.locator('[data-live-line]')).toContainText(/^Offline\. Showing the issues last saved .*1:00 PM\.$/);
  await expect(summary.getByRole('button', { name: /Driver's Gear/ })).toBeVisible();
  expect(api.findingsRequests).toBe(requests);

  await context.setOffline(false);
  await expect(summary.locator('[data-live-line]')).toContainText(/^Updated /);
  await expect(summary).toHaveAttribute('data-live-state', 'live');
});

test('the last answer is kept on this device and labelled when the next request fails', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  await expect(summaryOf(page, '2 open issues on this apparatus')).toBeVisible();

  api.findingsMode = 'abort';
  await page.reload();
  const summary = summaryOf(page, '2 open issues on this apparatus');
  await expect(summary.locator('[data-live-line]')).toContainText(/^Could not refresh\. Showing the issues last saved/);
  await expect(summary.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(summary.getByRole('button', { name: /Driver's Gear/ })).toBeVisible();
});

test('earlier closed issues load on demand, page, and say Resolved or Closed with no action', async ({ page }) => {
  const api = await mockApi(page);
  await openInspection(page);
  const closed = page.locator('details.findings-closed');
  await expect(closed.locator(':scope > summary')).toHaveText('Earlier closed issues (2)');
  expect(api.closedRequests).toEqual([]);

  await closed.locator(':scope > summary').click();
  await expect(cardFor(page, 70)).toContainText('Resolved');
  await expect(cardFor(page, 70)).toContainText('Replaced');
  expect(api.closedRequests).toEqual([null]);
  await closed.getByRole('button', { name: 'Show more closed issues' }).click();
  await expect(cardFor(page, 71)).toContainText('Closed with no action');
  await expect(cardFor(page, 71)).not.toContainText('Resolved');
  expect(api.closedRequests).toEqual([null, 'c2']);
  await expect(closed.getByRole('button', { name: 'Show more closed issues' })).toHaveCount(0);
});

test('marking an item present keeps the verification note and resolves nothing', async ({ page }) => {
  await mockApi(page);
  await openInspection(page);
  await jumpToDriversGear(page);
  await page.getByRole('button', { name: "Pass Driver's Gear", exact: true }).click();

  const known = page.locator('[data-previous-issues="drivers-gear"]');
  await expect(known.getByText('Marked present. Logistics will verify the correction.', { exact: true })).toBeVisible();
  await expect(known).toContainText('Replacement available');
  await expect(summaryOf(page, '2 open issues on this apparatus')).toBeVisible();
});

test('concerns are posted with the checkout: a problem with a photo, an update to an issue, and a note', async ({ page }) => {
  test.setTimeout(90_000);
  const api = await mockApi(page);
  await openInspection(page);
  await reachReview(page);

  const section = page.getByRole('region', { name: 'Concerns, Damage & Additional Notes' });
  await expect(section).toBeVisible();
  await section.getByRole('button', { name: 'Add a concern or note' }).click();
  const first = section.locator('[data-concern-entry="1"]');
  await first.getByLabel('What did you notice?').fill('Cracked mirror housing');
  await first.getByLabel('Where is it? (optional)').fill('Officer side');
  await first.getByLabel('Photo (optional)').setInputFiles({ name: 'mirror.png', mimeType: 'image/png', buffer: PNG });
  await expect(first.getByRole('img', { name: 'Photo for entry 1' })).toBeVisible();
  await expect(first.getByRole('radio', { name: 'No, this is something new' })).toBeChecked();
  await first.getByRole('radio', { name: 'Problem that needs attention' }).check();

  await section.getByRole('button', { name: 'Add another' }).click();
  const second = section.locator('[data-concern-entry="2"]');
  await second.getByRole('radio', { name: "Driver's Gear · Driver L4 · Replacement available" }).check();
  await second.getByLabel('What did you notice?').fill('Still not in the bay this morning');
  // An update to a known issue needs no second choice.
  await expect(second.getByRole('radio', { name: 'Problem that needs attention' })).toHaveCount(0);

  await section.getByRole('button', { name: 'Add another' }).click();
  const third = section.locator('[data-concern-entry="3"]');
  await third.getByLabel('What did you notice?').fill('Hose bed latch is stiff');
  await sign(page);
  // The third entry has no kind yet: nothing is sent and the entry says why.
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect(third.getByRole('alert')).toContainText('Choose note for the record or problem that needs attention.');
  expect(api.submissions).toHaveLength(0);
  await third.getByRole('radio', { name: 'Note for the record: no action needed' }).check();

  // Entries are part of the autosaved draft.
  await expect.poll(async () => (await readDrafts(page))[0]?.data.concerns?.length).toBe(3);
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect.poll(() => api.submissions.length).toBe(1);
  expect(api.submissions[0].concerns).toEqual([
    { client_id: expect.stringMatching(UUID), kind: 'issue', description: 'Cracked mirror housing', location: 'Officer side', photo: expect.stringMatching(/^data:image\/png;base64,/) },
    { client_id: expect.stringMatching(UUID), kind: 'issue', description: 'Still not in the bay this morning', existing_issue_id: 41 },
    { client_id: expect.stringMatching(UUID), kind: 'note', description: 'Hose bed latch is stiff' },
  ]);
});

test('a checkout with no concerns has no concerns key', async ({ page }) => {
  test.setTimeout(90_000);
  const api = await mockApi(page);
  await openInspection(page);
  await reachReview(page);
  await expect(page.getByRole('heading', { name: 'Concerns, Damage & Additional Notes' })).toBeVisible();
  // An entry opened and left empty is the same as none.
  await page.getByRole('button', { name: 'Add a concern or note' }).click();
  await sign(page);
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect.poll(() => api.submissions.length).toBe(1);
  expect(Object.keys(api.submissions[0])).not.toContain('concerns');
});

test('a description close to an open issue suggests it, and entries stop at 20', async ({ page }) => {
  test.setTimeout(90_000);
  await mockApi(page);
  await openInspection(page);
  await reachReview(page);
  const section = page.getByRole('region', { name: 'Concerns, Damage & Additional Notes' });
  await section.getByRole('button', { name: 'Add a concern or note' }).click();
  const first = section.locator('[data-concern-entry="1"]');
  await first.getByLabel('What did you notice?').fill('drivers gear is missing again');
  const hint = first.getByRole('status');
  await expect(hint).toContainText("This looks like an issue already on record: Driver's Gear");
  await hint.getByRole('button', { name: 'Add it to that issue' }).click();
  await expect(first.getByRole('radio', { name: /Driver's Gear · Driver L4/ })).toBeChecked();
  await expect(hint).toHaveCount(0);

  for (let index = 1; index < 20; index += 1) await section.getByRole('button', { name: 'Add another' }).click();
  await expect(section.locator('[data-concern-entry]')).toHaveCount(20);
  await expect(section.getByRole('button', { name: 'Add another' })).toBeDisabled();
  await expect(section).toContainText('You have added the most entries allowed (20).');
});

test('the read-only issues page lists full cards, has a way back, and the station links to it', async ({ page }) => {
  await mockApi(page);
  await page.goto('/daily/stations/1');
  await page.getByRole('tab', { name: /Apparatus/ }).click();
  const link = page.getByRole('link', { name: 'Issues & history' });
  await expect(link).toHaveAttribute('href', '/daily/vehicle-inspections/engine-1/issues?return_to=%2Fstations%2F1');
  await link.click();

  await expect(page.getByRole('heading', { name: 'Issues & history', level: 1 })).toBeVisible();
  const summary = summaryOf(page, '2 open issues on this apparatus');
  await expect(cardFor(page, 41)).toContainText("Driver's Gear");
  await expect(cardFor(page, 52)).toContainText('Legacy Rescue Bag');
  await expect(summary.getByRole('button', { name: /Go to item/ })).toHaveCount(0);
  await cardFor(page, 41).locator('summary').click();
  await expect(cardFor(page, 41).getByTestId('issue-timeline')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Back to station' })).toBeVisible();
  await page.getByRole('button', { name: 'Back to station' }).click();
  await expect(page).toHaveURL(/\/daily\/stations\/1$/);
});

for (const viewport of [{ width: 320, height: 568 }, { width: 1280, height: 800 }]) {
  test(`issue history and concerns do not overflow sideways at ${viewport.width}px and keep 44px targets`, async ({ page }, testInfo) => {
    test.setTimeout(90_000);
    const api = await mockApi(page);
    const long = 'LongUnbrokenReferenceNumber'.repeat(4);
    api.findings = {
      ...api.findings,
      open: [issue({ description: `The gear bag was not in the compartment. ${long}`, latest_action: { label: 'Replacement ordered', actor_name: 'Sam Lee', at: '2026-10-31T09:00:00-04:00', text: `Ordered. ${long}` } }), { ...unmatched, item: `Legacy ${long}` }],
      notes: [{ id: 9, description: `Hose bed latch is stiff ${long}`, location: long, reporter_name: 'Kim Alvarez', reported_at: '2026-10-31T07:00:00-04:00', inspection_reference: null, photo_url: null }],
    };
    await page.setViewportSize(viewport);
    await openInspection(page);
    const card = await jumpToDriversGear(page);
    await card.locator('summary').click();
    await expect(card.getByTestId('issue-timeline')).toBeVisible();
    const summary = summaryOf(page, /open issue/);
    await summary.locator('details.inspection-unlocated-findings > summary').click();
    await summary.locator('details.findings-notes > summary').click();
    await summary.locator('details.findings-closed > summary').click();
    await expect(cardFor(page, 70)).toBeVisible();
    for (const control of [summary.getByRole('button', { name: /Driver's Gear/ }), card.locator('summary'), summary.locator('details.findings-closed > summary')]) {
      expect((await control.first().boundingBox())?.height ?? 0, 'findings controls').toBeGreaterThanOrEqual(43.9);
    }
    // The page and each new block stay inside the viewport. (The workspace tab strip is its own horizontal scroller.)
    await expectNoHorizontalOverflow(page, '.findings-summary');
    await expectNoHorizontalOverflow(page, '.equipment-known');
    await page.screenshot({ path: testInfo.outputPath(`issues-${viewport.width}.png`), fullPage: true });

    await reachReview(page);
    const section = page.getByRole('region', { name: 'Concerns, Damage & Additional Notes' });
    await section.getByRole('button', { name: 'Add a concern or note' }).click();
    const entry = section.locator('[data-concern-entry="1"]');
    await entry.getByLabel('What did you notice?').fill(`Cracked housing ${long}`);
    await entry.getByLabel('Where is it? (optional)').fill(long);
    await entry.getByRole('radio', { name: "Driver's Gear · Driver L4 · Replacement available" }).check();
    await entry.getByLabel('Photo (optional)').setInputFiles({ name: 'mirror.png', mimeType: 'image/png', buffer: PNG });
    await expect(entry.getByRole('img', { name: 'Photo for entry 1' })).toBeVisible();
    for (const control of [section.getByRole('button', { name: 'Add another' }), entry.getByRole('button', { name: 'Remove entry 1' }), entry.getByRole('radio', { name: 'No, this is something new' }).locator('xpath=ancestor::label')]) {
      expect((await control.boundingBox())?.height ?? 0, 'concern controls').toBeGreaterThanOrEqual(43.9);
    }
    await expectNoHorizontalOverflow(page, '.concerns-section');
    await page.screenshot({ path: testInfo.outputPath(`concerns-${viewport.width}.png`), fullPage: true });
  });
}
