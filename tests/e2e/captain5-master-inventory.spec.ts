import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import type { EmsMeta } from '../../resources/js/daily-checkout/src/components/captain5/ems/types';
import type { EntryMap, MasterItem, MasterSection } from '../../resources/js/daily-checkout/src/components/captain5/ems/master/types';

/**
 * Captain 5 Master Supply Room Inventory table and Monday count form, every API response mocked.
 * Contract: docs/ems/captain5-medical-inventory-workflow.md sections 5, 6 and 8.
 */

const KEY_PATTERN = /^[A-Za-z0-9._:-]{8,100}$/;
const SUPPLY = '/daily/captain-5?tab=supply';
const COUNT = `${SUPPLY}&count=1`;
const COUNT_ID = 'c0c0c0c0-1111-4111-8111-c0c0c0c0c0c0';

const META: EmsMeta = {
  permissions: { view: true, act: true, configure: false },
  statuses: ['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'],
  target_kinds: ['rescue_unit', 'other_unit', 'detail_event'],
  units: [],
  close_reasons: [],
};

const LONG_NAME = 'Polyisobutylenecopolymerhydrophobicadhesivedressing Extra Large Trauma Kit With A Very Long Name That Must Wrap';

function item(id: number, name: string, shelf: string, overrides: Partial<MasterItem> = {}): MasterItem {
  return {
    id, name, aliases: [], location_code: shelf, location_label: shelf, section_key: shelf.charAt(0), section_label: null,
    base_unit: 'each', count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', units_per_count_unit: 1,
    package_unit: null, units_per_package: null, on_hand_base: 10, on_hand: 10, deducted_base: 0, deducted: 0, counted_at: '2026-10-26T14:00:00Z', counted_by: 'Alex Rivera',
    required_base: null, order_rule_text: null, refrigerated: false, needs_review: false, review_reasons: [], config_problem: null, ...overrides,
  };
}

const SHELF_LABELS: Record<string, string> = { A1: 'A1 Airway', A2: 'A2 Wound care', B1: 'B1 IV supplies', C1: 'C1 Fluids', D1: 'D1 Splints' };

const catalog = (): MasterItem[] => [
  item(1, 'Oral Airway Size 3', 'A1', { on_hand: 6, on_hand_base: 6, required_base: 4 }),
  item(2, 'Nasal Airway 28 Fr', 'A1', { on_hand: 2, on_hand_base: 2, required_base: 4 }),
  item(3, 'Gauze 4x4', 'A2', { aliases: ['4 x 4 sponge'], on_hand: 5, on_hand_base: 5, deducted: 5, deducted_base: 5, required_base: 20 }),
  item(4, 'Tape 1 inch', 'A2', { on_hand: 9, on_hand_base: 9, required_base: null }),
  item(5, 'IV Catheter 18 Gauge', 'B1', { aliases: ['18g'], on_hand: 30, on_hand_base: 30, required_base: 20 }),
  item(6, 'IV Catheter 20 Gauge', 'B1', { aliases: ['20g'], on_hand: null, on_hand_base: null, counted_at: null, counted_by: null, required_base: 20 }),
  item(7, 'Saline Flush', 'B1', {
    count_unit: 'box', count_unit_label: 'box', count_unit_label_plural: 'boxes', units_per_count_unit: 50, package_unit: 'box', units_per_package: 50,
    on_hand: 3, on_hand_base: 150, required_base: 100,
  }),
  item(8, 'Normal Saline 1000 mL', 'C1', { on_hand: 12, on_hand_base: 12, required_base: 10 }),
  item(9, 'Cervical Collar Adult', 'D1', { on_hand: 4, on_hand_base: 4, required_base: 2 }),
  item(10, LONG_NAME, 'D1', { on_hand: 1, on_hand_base: 1, required_base: 3 }),
];

interface Recorded { method: string; path: string; key: string | null; body: Record<string, unknown> }

interface Server {
  items: MasterItem[];
  inactive: Array<{ id: number; name: string; location_code: string | null }>;
  entries: EntryMap | null;
  putMode: 'ok' | 'abort' | 'fail503';
  submitDelayMs: number;
  conflictOnce: boolean;
  writes: Recorded[];
  submitted: boolean;
}

function newServer(overrides: Partial<Server> = {}): Server {
  return { items: catalog(), inactive: [], entries: null, putMode: 'ok', submitDelayMs: 0, conflictOnce: false, writes: [], submitted: false, ...overrides };
}

const sectionsOf = (server: Server): MasterSection[] => {
  const codes = [...new Set(server.items.map((entry) => entry.location_code).filter((code): code is string => code !== null))].sort();
  return codes.map((code) => ({ code, label: SHELF_LABELS[code] ?? code, item_count: server.items.filter((entry) => entry.location_code === code).length }));
};

const withLabels = (server: Server): MasterItem[] => server.items.map((entry) => ({ ...entry, location_label: entry.location_code ? SHELF_LABELS[entry.location_code] ?? entry.location_code : null }));

const orderOf = (entry: MasterItem, typed: number): number => {
  if (entry.required_base === null) return 0;
  const shortfall = entry.required_base - typed * entry.units_per_count_unit;
  return shortfall <= 0 ? 0 : Math.ceil(shortfall / entry.units_per_count_unit);
};

async function json(route: Route, body: unknown, status = 200): Promise<void> {
  await route.fulfill({ status, json: body, headers: { 'Cache-Control': 'private, no-store' } });
}
const ok = (route: Route, data: unknown, status = 200) => json(route, { ok: true, data, meta: {} }, status);
const fail = (route: Route, status: number, code: string, message: string) => json(route, { ok: false, error: { code, message, fields: {} } }, status);

async function handleEms(route: Route, server: Server, url: URL): Promise<void> {
  const method = route.request().method();
  const path = url.pathname.replace('/ems/api', '');
  const text = route.request().postData();
  const recorded: Recorded = { method, path, key: route.request().headers()['idempotency-key'] ?? null, body: text ? JSON.parse(text) as Record<string, unknown> : {} };
  if (method !== 'GET') server.writes.push(recorded);

  if (path === '/meta') return ok(route, META);
  if (path === '/supply/master') {
    return ok(route, {
      sections: sectionsOf(server), items: withLabels(server), inactive: server.inactive,
      draft: server.entries ? { id: COUNT_ID, started_at: '2026-11-02T13:00:00Z', started_by: 'Alex Rivera', counted: Object.keys(server.entries).length } : null,
      last_count: { id: 'f-1', finalized_at: '2026-10-26T14:00:00Z', finalized_name: 'Alex Rivera' }, can_edit: true,
    });
  }
  if (path === '/supply/master/items' && method === 'POST') {
    const created = item(100, String(recorded.body.name), String(recorded.body.location_code ?? 'A1'), { on_hand: null, on_hand_base: null });
    server.items.push(created);
    return ok(route, created, 201);
  }
  const itemMatch = /^\/supply\/master\/items\/(\d+)(?:\/(count))?$/.exec(path);
  if (itemMatch) {
    const target = server.items.find((entry) => entry.id === Number(itemMatch[1]));
    if (!target) return fail(route, 404, 'not_found', 'That item could not be found.');
    if (itemMatch[2] === 'count') {
      target.on_hand = Number(recorded.body.on_hand);
      target.on_hand_base = target.on_hand * target.units_per_count_unit;
      target.deducted = 0;
      target.deducted_base = 0;
      return ok(route, { ...target });
    }
    const body = recorded.body;
    if (typeof body.name === 'string') target.name = body.name;
    if ('location_code' in body) target.location_code = body.location_code as string | null;
    if (body.active === false) { server.items = server.items.filter((entry) => entry.id !== target.id); server.inactive.push({ id: target.id, name: target.name, location_code: target.location_code }); }
    return ok(route, { ...target, location_label: target.location_code ? SHELF_LABELS[target.location_code] ?? target.location_code : null });
  }

  if (path === '/supply/count-form/start') {
    const resumed = server.entries !== null;
    server.entries ??= {};
    return ok(route, { count: { id: COUNT_ID, started_at: '2026-11-02T13:00:00Z', started_by: 'Alex Rivera', entries: { ...server.entries } }, resumed, sections: sectionsOf(server), items: withLabels(server) }, resumed ? 200 : 201);
  }
  if (path === `/supply/count-form/${COUNT_ID}/entries` && method === 'PUT') {
    if (server.putMode === 'abort') return route.abort('failed');
    if (server.putMode === 'fail503') return fail(route, 503, 'unavailable', 'The server is busy.');
    const entries = server.entries ?? {};
    for (const row of recorded.body.entries as Array<{ supply_item_id: number; on_hand: number | null }>) {
      if (row.on_hand === null) delete entries[String(row.supply_item_id)]; else entries[String(row.supply_item_id)] = row.on_hand;
    }
    server.entries = entries;
    return ok(route, { saved: (recorded.body.entries as unknown[]).length, entries: { ...entries } });
  }
  if (path === `/supply/count-form/${COUNT_ID}/review`) {
    const entries = server.entries ?? {};
    const counted = server.items.filter((entry) => String(entry.id) in entries);
    return ok(route, {
      counted: counted.length,
      uncounted: server.items.filter((entry) => !(String(entry.id) in entries)).map((entry) => ({ id: entry.id, name: entry.name, location_code: entry.location_code })),
      orders: counted.filter((entry) => orderOf(entry, entries[String(entry.id)]) > 0).map((entry) => ({
        supply_item_id: entry.id, name: entry.name, location_code: entry.location_code, on_hand: entries[String(entry.id)], order: orderOf(entry, entries[String(entry.id)]),
        unit_label: entry.count_unit === 'each' ? '' : entry.count_unit_label_plural,
      })),
      config_problems: [],
    });
  }
  if (path === `/supply/count-form/${COUNT_ID}/submit`) {
    if (server.submitDelayMs > 0) await new Promise((resolve) => setTimeout(resolve, server.submitDelayMs));
    const entries = server.entries ?? {};
    const uncounted = server.items.filter((entry) => !(String(entry.id) in entries)).length;
    if (server.conflictOnce) { server.conflictOnce = false; return fail(route, 409, 'uncounted_changed', 'The items not counted changed.'); }
    if (recorded.body.expected_uncounted !== uncounted) return fail(route, 409, 'uncounted_changed', 'The items not counted changed.');
    server.submitted = true;
    return ok(route, { id: 'f-2', finalized_at: new Date().toISOString(), counted_items: server.items.length - uncounted, uncounted_items: uncounted, order_items: 3 });
  }
  if (path === `/supply/counts/${COUNT_ID}` && method === 'DELETE') { server.entries = null; return ok(route, { discarded: true }); }
  return fail(route, 404, 'not_found', `Unmocked route ${path}`);
}

async function mockApi(page: Page, server: Server): Promise<void> {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/ems/api/')) return handleEms(route, server, url);
    if (url.pathname === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1, identity: { user_id: 401, has_personnel_profile: true }, personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' },
          offline: { security_version: 1 }, session: { authenticated: true }, authorization: { capabilities: { command_300_access: false } },
        },
      });
    }
    if (url.pathname === '/api/captain5/workspace') {
      const duty = { shift: 'B', source: 'bid_holder', resolved: true, name: 'Alex Rivera', rank: 'Capt.', acting_until: null, acting_request_id: null };
      return route.fulfill({
        json: {
          access: { view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)' },
          on_duty: { duty_date: '2026-11-02', ...duty }, duty_date: '2026-11-02',
          counts: { open: 0, needs_acknowledgement: 0, ordered: 0, ready_for_pickup: 0, overdue: 0, awaiting_verification: 0, significant: 0, rescue: 0, medical: 0 },
          rescue_dailies: [], issues: [], handoff: { duty_date: '2026-11-02', notes: [] },
          acting: { can_approve: false, mine: null, pending: [], choices: { '12h': null, '24h': null } }, generated_at: new Date().toISOString(),
        },
      });
    }
    if (url.pathname === '/api/captain5/communications/summary') return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    if (url.pathname.startsWith('/api/captain5/') || url.pathname.startsWith('/api/command-communications/')) return route.fulfill({ json: { unread_count: 0, data: [], pinned: [], next_before: null, issues: [] } });
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${url.pathname}` } });
  });
}

async function open(page: Page, server: Server, path: string): Promise<void> {
  await mockApi(page, server);
  await page.goto(path);
}

async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.CAPTAIN5_SHOTS_DIR;
  if (!dir) return;
  await page.evaluate(() => document.querySelector('[data-testid=count-supply-room], [data-testid=master-inventory]')?.scrollIntoView());
  await page.screenshot({ path: `${dir}/master-${name}.png`, fullPage: false });
}

async function noHorizontalOverflow(page: Page): Promise<void> {
  const widths = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function expectTouchTarget(locator: Locator, label: string): Promise<void> {
  await expect(locator, label).toBeVisible();
  const box = await locator.boundingBox();
  expect(box, label).not.toBeNull();
  expect(box?.height ?? 0, `${label} height`).toBeGreaterThanOrEqual(43.5);
  expect(box?.width ?? 0, `${label} width`).toBeGreaterThanOrEqual(43.5);
}

const writesTo = (server: Server, suffix: string, method?: string) => server.writes.filter((write) => write.path.endsWith(suffix) && (!method || write.method === method));
const rowFor = (page: Page, name: string) => page.getByTestId('master-row').filter({ hasText: name });
const countRow = (page: Page, name: string) => page.getByTestId('count-row').filter({ hasText: name });
const countInput = (page: Page, name: string) => countRow(page, name).getByTestId('count-input');
const status = (page: Page) => page.getByTestId('save-status');
const waitSaved = (page: Page) => expect(status(page)).toHaveAttribute('data-status', 'saved');

// ------------------------------------------------------------------------------ the table

test.describe('master inventory table', () => {
  test('shows exactly Item, Shelf, On hand and Edit, with the last count line and a count button', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await expect(page.getByRole('columnheader')).toHaveText(['Item', 'Shelf', 'On hand', 'Edit']);
    await expect(page.getByTestId('last-count')).toHaveText('Last counted Oct 26, 2026 by Alex Rivera');
    await expect(page.getByTestId('start-count')).toHaveText(/Count Supply Room/);
    await expect(page.getByTestId('master-row')).toHaveCount(2);
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A1 Airway');
    await expect(page.getByTestId('shelf-position-top')).toHaveText('Shelf 1 of 5');
  });

  test('shelf arrows at the top and the bottom move one shelf, keep it in the address, and end at the first and last shelf', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await page.getByTestId('shelf-next-top').click();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A2 Wound care');
    await expect(page.getByTestId('shelf-name-bottom')).toHaveText('A2 Wound care');
    await expect(page).toHaveURL(/shelf=A2/);
    await page.getByTestId('shelf-next-bottom').click();
    await expect(page.getByTestId('shelf-position-top')).toHaveText('Shelf 3 of 5');
    await expect(rowFor(page, 'IV Catheter 18 Gauge')).toBeVisible();
    await page.getByTestId('shelf-prev-bottom').click();
    await page.getByTestId('shelf-prev-top').click();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A1 Airway');
    await expect(page.getByTestId('shelf-prev-top')).toBeDisabled();
    await page.getByTestId('shelf-picker').selectOption({ label: 'D1 Splints (2)' });
    await expect(page.getByTestId('shelf-next-bottom')).toBeDisabled();
    await page.goBack();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A1 Airway');
    await page.goForward();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('D1 Splints');
    await page.reload();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('D1 Splints');
  });

  test('search reaches every shelf, matches 18g with 18 gauge, and the shelf link jumps and highlights the row', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await page.getByTestId('master-search').fill('18g');
    const results = page.getByTestId('search-results');
    await expect(results.getByTestId('master-row')).toHaveCount(1);
    await expect(results).toContainText('IV Catheter 18 Gauge');
    await page.locator('[data-testid=shelf-link]:visible').first().click();
    await expect(page.getByTestId('master-search')).toHaveValue('');
    await expect(page.getByTestId('shelf-name-top')).toHaveText('B1 IV supplies');
    await expect(rowFor(page, 'IV Catheter 18 Gauge')).toHaveAttribute('data-highlight', 'true');
    await expect(page).toHaveURL(/shelf=B1/);
  });

  test('search puts an exact name first, ignores case and word order, and has a way out when nothing matches', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await page.getByTestId('master-search').fill('catheter IV');
    await expect(page.getByTestId('search-results').getByTestId('item-name')).toHaveText(['IV Catheter 18 Gauge', 'IV Catheter 20 Gauge']);
    await page.getByTestId('master-search').fill('wound');
    await expect(page.getByTestId('search-results').getByTestId('item-name')).toHaveText(['Gauze 4x4', 'Tape 1 inch']);
    await page.getByTestId('master-search').fill('zzzz');
    await expect(page.getByText('Nothing matches that')).toBeVisible();
    await page.getByRole('button', { name: 'Clear search' }).first().click();
    await expect(page.getByTestId('shelf-view')).toBeVisible();
  });

  test('renaming an item and moving its shelf sends only those fields and persists', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await page.getByRole('button', { name: 'Edit Tape 1 inch' }).click();
    const sheet = page.getByTestId('edit-item-sheet');
    await expect(sheet).toBeVisible();
    await sheet.getByTestId('field-name').fill('Tape 2 inch');
    await sheet.getByTestId('field-shelf').selectOption('B1');
    await sheet.getByTestId('edit-item-save').click();
    await expect(sheet).toHaveCount(0);
    const patch = writesTo(server, '/items/4', 'PATCH');
    expect(patch).toHaveLength(1);
    expect(patch[0].body).toEqual({ name: 'Tape 2 inch', location_code: 'B1' });
    expect(patch[0].key).toMatch(KEY_PATTERN);
    expect(writesTo(server, '/count')).toHaveLength(0);
    await expect(rowFor(page, 'Tape 2 inch')).toHaveCount(0);
    await page.reload();
    await page.getByTestId('master-search').fill('tape 2');
    await expect(page.getByTestId('search-results')).toContainText('Tape 2 inch');
    await expect(page.locator('[data-testid=item-shelf]:visible')).toContainText('B1');
  });

  test('typing a new on-hand number posts a physical count and clears the red number', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    const gauze = rowFor(page, 'Gauze 4x4');
    await expect(gauze.getByTestId('on-hand-number')).toHaveText('5');
    await expect(gauze.getByTestId('on-hand-deducted')).toContainText('-5');
    await expect(gauze.getByText('minus 5 since last count')).toHaveCount(1);
    await page.getByRole('button', { name: 'Edit Gauze 4x4' }).click();
    const sheet = page.getByTestId('edit-item-sheet');
    await expect(sheet.getByText('Saving a new number counts as a physical count and clears the red number.')).toBeVisible();
    await sheet.getByTestId('field-on-hand').fill('8');
    await sheet.getByTestId('edit-item-save').click();
    await expect(sheet).toHaveCount(0);
    const counts = writesTo(server, '/items/3/count', 'POST');
    expect(counts).toHaveLength(1);
    expect(counts[0].body).toEqual({ on_hand: 8 });
    expect(counts[0].key).toMatch(KEY_PATTERN);
    expect(writesTo(server, '/items/3', 'PATCH')).toHaveLength(0);
    await expect(gauze.getByTestId('on-hand-number')).toHaveText('8');
    await expect(gauze.getByTestId('on-hand-deducted')).toHaveCount(0);
  });

  test('a box item shows its unit, an uncounted item says so, and the edit sheet keeps the extras under More', async ({ page }) => {
    await open(page, newServer(), `${SUPPLY}&shelf=B1`);
    await expect(rowFor(page, 'Saline Flush').getByTestId('on-hand')).toContainText('3');
    await expect(rowFor(page, 'Saline Flush').getByTestId('on-hand')).toContainText('boxes');
    await expect(rowFor(page, 'IV Catheter 20 Gauge').getByTestId('on-hand')).toHaveText('Not counted');
    await page.getByRole('button', { name: 'Edit Saline Flush' }).click();
    const sheet = page.getByTestId('edit-item-sheet');
    await expect(sheet.getByTestId('field-count-unit')).toBeHidden();
    await sheet.getByText('More', { exact: true }).click();
    await expect(sheet.getByTestId('field-count-unit')).toBeVisible();
    await expect(sheet.getByTestId('field-per-package')).toHaveValue('50');
    await expect(sheet.getByTestId('field-threshold')).toHaveValue('2');
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------- count form

test.describe('Monday count form', () => {
  test('opens from the button, shows the page title, and every box is a plain numeric text input', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await page.getByTestId('start-count').click();
    await expect(page).toHaveURL(/count=1/);
    await expect(page.getByRole('heading', { name: 'Captain 5 Medical Supply Room Inventory' })).toBeVisible();
    const inputs = page.getByTestId('count-input');
    await expect(inputs).toHaveCount(2);
    for (const input of await inputs.all()) {
      await expect(input).toHaveAttribute('type', 'text');
      await expect(input).toHaveAttribute('inputmode', 'numeric');
      await expect(input).toHaveAttribute('pattern', '[0-9]*');
      await expect(input).toHaveAttribute('enterkeyhint', 'next');
      await expect(input).toHaveAccessibleName(/How many do you have on hand\?/);
    }
    await expect(page.locator('input[type=number]')).toHaveCount(0);
    await expect(page.getByTestId('count-list').locator('button, select')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /plus|minus|increase|decrease|\+|−/i })).toHaveCount(0);
    await inputs.first().fill('4x7');
    await expect(inputs.first()).toHaveValue('47');
    await waitSaved(page);
    await expect(page.getByTestId('shelf-position-top')).toHaveText('Shelf 1 of 5');
    await expect(page.getByTestId('shelf-position-bottom')).toHaveText('Shelf 1 of 5');
    await page.getByTestId('count-back').click();
    await expect(page).not.toHaveURL(/count=1/);
    await expect(page.getByTestId('master-inventory')).toBeVisible();
    await expect(page.getByTestId('start-count')).toContainText('Resume count (1 entered)');
  });

  test('blank is not entered and 0 is a real count; clearing removes the number', async ({ page }) => {
    const server = newServer();
    await open(page, server, COUNT);
    const row = countRow(page, 'Oral Airway Size 3');
    await expect(row).toHaveAttribute('data-entered', 'false');
    await expect(page.getByTestId('count-progress')).toHaveText('0 of 10 items entered');
    await countInput(page, 'Oral Airway Size 3').fill('0');
    await expect(row).toHaveAttribute('data-entered', 'true');
    await expect(countInput(page, 'Oral Airway Size 3')).toHaveValue('0');
    await expect(page.getByTestId('count-progress')).toHaveText('1 of 10 items entered');
    await waitSaved(page);
    expect(server.entries).toEqual({ 1: 0 });
    await countInput(page, 'Oral Airway Size 3').fill('');
    await expect(row).toHaveAttribute('data-entered', 'false');
    await waitSaved(page);
    expect(server.entries).toEqual({});
    const puts = writesTo(server, '/entries', 'PUT');
    expect(puts[puts.length - 1].body).toEqual({ entries: [{ supply_item_id: 1, on_hand: null }] });
    expect(new Set(puts.map((put) => put.key)).size).toBe(puts.length);
  });

  test('the order shows as soon as a number is typed, in the item\'s own unit', async ({ page }) => {
    await open(page, newServer(), `${COUNT}&shelf=A2`);
    await countInput(page, 'Gauze 4x4').fill('15');
    await expect(countRow(page, 'Gauze 4x4').getByTestId('order-result')).toHaveText('Order 5');
    await countInput(page, 'Gauze 4x4').fill('25');
    await expect(countRow(page, 'Gauze 4x4').getByTestId('order-result')).toHaveText('No order needed');
    await countInput(page, 'Tape 1 inch').fill('3');
    await expect(countRow(page, 'Tape 1 inch').getByTestId('order-result')).toHaveText('No order level set');
    await page.getByTestId('shelf-next-bottom').click();
    await countInput(page, 'Saline Flush').fill('0');
    await expect(countRow(page, 'Saline Flush').getByTestId('order-result')).toHaveText('Order 2 boxes');
    await countInput(page, 'Saline Flush').fill('1');
    await expect(countRow(page, 'Saline Flush').getByTestId('order-result')).toHaveText('Order 1 box');
    await expect(countRow(page, 'Saline Flush').getByTestId('count-input')).toHaveAccessibleName(/counted in boxes/);
  });

  test('Enter moves to the next item, across shelves, and ends at Review', async ({ page }) => {
    await open(page, newServer(), COUNT);
    await countInput(page, 'Oral Airway Size 3').fill('6');
    await page.keyboard.press('Enter');
    await expect(countInput(page, 'Nasal Airway 28 Fr')).toBeFocused();
    await page.keyboard.type('2');
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A2 Wound care');
    await expect(countInput(page, 'Gauze 4x4')).toBeFocused();
    await page.goto(`${COUNT}&shelf=D1`);
    await countInput(page, LONG_NAME.slice(0, 20)).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('review-bottom')).toBeFocused();
  });

  test('a search from the count header jumps to the item\'s shelf and focuses its box', async ({ page }) => {
    await open(page, newServer(), COUNT);
    await page.getByTestId('count-search-toggle').click();
    await page.locator('#count-search-input').fill('collar');
    await page.getByTestId('count-search-result').first().click();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('D1 Splints');
    await expect(countInput(page, 'Cervical Collar Adult')).toBeFocused();
  });

  test('numbers survive a reload, from the server and from this device when the server never got them', async ({ page }) => {
    const server = newServer();
    await open(page, server, COUNT);
    await countInput(page, 'Oral Airway Size 3').fill('7');
    await waitSaved(page);
    expect(server.entries).toEqual({ 1: 7 });
    await page.reload();
    await expect(countInput(page, 'Oral Airway Size 3')).toHaveValue('7');
    await expect(page.getByTestId('count-progress')).toHaveText('1 of 10 items entered');

    server.putMode = 'fail503';
    await countInput(page, 'Nasal Airway 28 Fr').fill('9');
    await expect(status(page)).toHaveAttribute('data-status', 'error');
    expect(server.entries).toEqual({ 1: 7 });
    await page.reload();
    await expect(countInput(page, 'Nasal Airway 28 Fr')).toHaveValue('9');
    server.putMode = 'ok';
    await waitSaved(page);
    expect(server.entries).toEqual({ 1: 7, 2: 9 });
  });

  test('a failed save keeps every number, says it is saved on the device, and Try again sends it', async ({ page }) => {
    const server = newServer({ putMode: 'abort' });
    await open(page, server, COUNT);
    await countInput(page, 'Oral Airway Size 3').fill('4');
    await expect(status(page)).toHaveText('Offline, saved on this device');
    await expect(page.getByTestId('save-banner')).toBeVisible();
    await expect(countInput(page, 'Oral Airway Size 3')).toHaveValue('4');
    server.putMode = 'ok';
    await page.getByTestId('save-retry').click();
    await waitSaved(page);
    await expect(status(page)).toHaveText('Saved');
    expect(server.entries).toEqual({ 1: 4 });
    await expect(page.getByTestId('save-banner')).toHaveCount(0);
  });

  test('going offline keeps the number and coming back online sends it', async ({ page, context }) => {
    const server = newServer();
    await open(page, server, COUNT);
    await expect(countInput(page, 'Oral Airway Size 3')).toBeVisible();
    await context.setOffline(true);
    await countInput(page, 'Oral Airway Size 3').fill('3');
    await expect(status(page)).toHaveText('Offline, saved on this device');
    await context.setOffline(false);
    await waitSaved(page);
    expect(server.entries).toEqual({ 1: 3 });
  });

  test('review lists what is missing by shelf, what to order, and the deliberate partial submit', async ({ page }) => {
    const server = newServer();
    await open(page, server, COUNT);
    await countInput(page, 'Oral Airway Size 3').fill('3');
    await countInput(page, 'Nasal Airway 28 Fr').fill('9');
    await page.getByTestId('review-bottom').click();
    const review = page.getByTestId('count-review');
    await expect(review).toBeVisible();
    await expect(page.getByTestId('review-counted')).toHaveText('2 items counted.');
    await expect(review.getByTestId('missing-group')).toHaveCount(4);
    await expect(review.getByTestId('missing-item')).toHaveCount(8);
    await expect(review.getByTestId('review-orders')).toContainText('Oral Airway Size 3');
    await expect(review.getByTestId('review-orders')).toContainText('Order 1');
    await expect(review.getByRole('button', { name: 'Submit inventory' })).toHaveCount(0);
    await expect(review.getByRole('button', { name: 'Submit with 8 items not counted' })).toBeVisible();
    await expect(review.getByText('They keep their current numbers.')).toBeVisible();

    await review.getByRole('button', { name: 'Tape 1 inch' }).click();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A2 Wound care');
    await expect(countInput(page, 'Tape 1 inch')).toBeFocused();

    await page.getByTestId('review-top').click();
    server.conflictOnce = true;
    await page.getByTestId('submit-partial').click();
    await expect(page.getByTestId('review-changed')).toContainText('changed while you were reviewing');
    await page.getByTestId('submit-partial').click();
    await expect(page.getByTestId('count-done')).toBeVisible();
    const submits = writesTo(server, '/submit');
    expect(submits[submits.length - 1].body).toEqual({ expected_uncounted: 8 });
  });

  test('submitting twice quickly sends one submit, then confirms and returns to the inventory', async ({ page }) => {
    const entries: EntryMap = Object.fromEntries(catalog().map((entry) => [String(entry.id), 5]));
    const server = newServer({ entries, submitDelayMs: 400 });
    await open(page, server, COUNT);
    await page.getByTestId('review-top').click();
    await expect(page.getByTestId('review-counted')).toHaveText('10 items counted.');
    await expect(page.getByTestId('missing-item')).toHaveCount(0);
    await page.getByTestId('submit-inventory').dblclick();
    await expect(page.getByTestId('count-done')).toBeVisible();
    const submits = writesTo(server, '/submit');
    expect(submits).toHaveLength(1);
    expect(submits[0].body).toEqual({ expected_uncounted: 0 });
    expect(submits[0].key).toMatch(KEY_PATTERN);
    await expect(page.getByTestId('done-counted')).toHaveText('10 items');
    await expect(page.getByTestId('count-done')).toContainText('Submitted');
    expect(await page.evaluate(() => Object.keys(window.localStorage).filter((key) => key.startsWith('mbfd-ems-count')))).toEqual([]);
    await page.getByRole('button', { name: 'Back to inventory' }).click();
    await expect(page.getByTestId('master-inventory')).toBeVisible();
    await expect(page).not.toHaveURL(/count=1/);
  });

  test('discarding the count is tucked away and asks first', async ({ page }) => {
    const server = newServer({ entries: { 1: 4 } });
    await open(page, server, COUNT);
    await page.getByTestId('review-top').click();
    await expect(page.getByTestId('discard-confirm')).toHaveCount(0);
    await page.getByText('More options').click();
    await page.getByRole('button', { name: 'Discard this count' }).click();
    expect(writesTo(server, `/counts/${COUNT_ID}`, 'DELETE')).toHaveLength(0);
    await page.getByTestId('discard-confirm').getByRole('button', { name: 'Discard this count' }).click();
    await expect(page.getByTestId('master-inventory')).toBeVisible();
    expect(writesTo(server, `/counts/${COUNT_ID}`, 'DELETE')).toHaveLength(1);
  });
});

// ------------------------------------------------------------------- layout and targets

for (const width of [320, 360, 390, 768, 1280]) {
  test(`no horizontal scrolling at ${width}px on the table, search results, the count form and the review`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    const server = newServer({ entries: { 1: 4, 9: 1 } });
    await open(page, server, `${SUPPLY}&shelf=D1`);
    await expect(rowFor(page, 'Polyisobutylene')).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, `table-${width}`);
    await page.getByTestId('master-search').fill('a');
    await expect(page.getByTestId('search-results')).toBeVisible();
    await noHorizontalOverflow(page);
    await page.goto(`${COUNT}&shelf=D1`);
    await expect(countRow(page, 'Polyisobutylene')).toBeVisible();
    await noHorizontalOverflow(page);
    await countInput(page, 'Cervical Collar Adult').fill('123456');
    await noHorizontalOverflow(page);
    await shot(page, `count-${width}`);
    await page.getByTestId('review-bottom').click();
    await expect(page.getByTestId('count-review')).toBeVisible();
    await noHorizontalOverflow(page);
  });
}

test('every control is at least 44px on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, newServer(), SUPPLY);
  await expectTouchTarget(page.getByTestId('start-count'), 'Count Supply Room');
  await expectTouchTarget(page.getByTestId('add-item'), 'Add item');
  await expectTouchTarget(page.getByRole('button', { name: 'Edit Oral Airway Size 3' }), 'Edit');
  await expectTouchTarget(page.getByTestId('shelf-next-top'), 'Next shelf, top');
  await expectTouchTarget(page.getByTestId('shelf-prev-bottom'), 'Previous shelf, bottom');
  await expectTouchTarget(page.getByTestId('shelf-picker'), 'Shelf picker');
  await page.getByTestId('master-search').fill('18g');
  await expectTouchTarget(page.getByTestId('master-search-clear'), 'Clear search');
  await expectTouchTarget(page.locator('[data-testid=shelf-link]:visible').first(), 'Shelf link');

  await page.goto(COUNT);
  await expectTouchTarget(countInput(page, 'Oral Airway Size 3'), 'Count box');
  await expectTouchTarget(page.getByTestId('count-back'), 'Back');
  await expectTouchTarget(page.getByTestId('shelf-next-top'), 'Count next shelf');
  await expectTouchTarget(page.getByTestId('count-search-toggle'), 'Find an item');
  await expectTouchTarget(page.getByTestId('review-top'), 'Review');
  await expectTouchTarget(page.getByTestId('review-bottom'), 'Review and submit');
  await page.getByTestId('review-bottom').click();
  await expectTouchTarget(page.getByTestId('missing-item').first(), 'Missing item');
  await expectTouchTarget(page.getByTestId('submit-partial'), 'Submit with items not counted');
});
