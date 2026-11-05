import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import type { EmsMeta } from '../../resources/js/daily-checkout/src/components/captain5/ems/types';
import type {
  CountUnit, EntryMap, MasterItem, MasterSection, OpenOrder, OrderState, ScreenWithdrawal, SupplyScreenData, UnitOption,
} from '../../resources/js/daily-checkout/src/components/captain5/ems/master/types';

/**
 * Captain 5 Master Supply Room Inventory: the view table, Edit mode, ordering, the summary filter, the standalone
 * Admin page and the Monday count form, every API response mocked.
 * Contract: docs/ems/captain5-medical-inventory-workflow.md sections 4-6 and 8, plus the supply room orders brief.
 */

const KEY_PATTERN = /^[A-Za-z0-9._:-]{8,100}$/;
const SUPPLY = '/daily/captain-5?tab=supply';
const COUNT = `${SUPPLY}&count=1`;
const STANDALONE = '/daily/supply-room';
const COUNT_ID = 'c0c0c0c0-1111-4111-8111-c0c0c0c0c0c0';

const META: EmsMeta = {
  permissions: { view: true, act: true, configure: false },
  statuses: ['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'],
  target_kinds: ['rescue_unit', 'other_unit', 'detail_event'],
  units: [],
  close_reasons: [],
};

const UNITS: UnitOption[] = [
  ['each', 'each', 'each'], ['box', 'box', 'boxes'], ['case', 'case', 'cases'], ['pack', 'pack', 'packs'], ['bag', 'bag', 'bags'],
  ['bottle', 'bottle', 'bottles'], ['vial', 'vial', 'vials'], ['kit', 'kit', 'kits'], ['roll', 'roll', 'rolls'], ['pair', 'pair', 'pairs'],
  ['tube', 'tube', 'tubes'], ['carton', 'carton', 'cartons'], ['can', 'can', 'cans'], ['set', 'set', 'sets'], ['sleeve', 'sleeve', 'sleeves'], ['tray', 'tray', 'trays'],
].map(([value, label, plural]) => ({ value: value as CountUnit, label, plural }));

const LONG_NAME = 'Polyisobutylenecopolymerhydrophobicadhesivedressing Extra Large Trauma Kit With A Very Long Name That Must Wrap';

function item(id: number, name: string, shelf: string, overrides: Partial<MasterItem> = {}): MasterItem {
  const base: MasterItem = {
    id, name, aliases: [], location_code: shelf, location_label: shelf, section_key: shelf.charAt(0), section_label: null,
    base_unit: 'each', count_unit: 'each', count_unit_label: 'each', count_unit_label_plural: 'each', units_per_count_unit: 1,
    package_unit: null, units_per_package: null, on_hand_base: 10, on_hand: 10, deducted_base: 0, deducted: 0, counted_at: '2026-10-26T14:00:00Z', counted_by: 'Alex Rivera',
    required_base: null, order_rule_text: null, refrigerated: false, needs_review: false, review_reasons: [], config_problem: null,
    expected: null, to_order: null, order_state: 'ok', open_order: null,
    // Worked out by present(); a test sets reorder_at/critical_at with *_auto false for a person's own line.
    level: null, reorder_at: null, critical_at: null, reorder_at_auto: true, critical_at_auto: true, ...overrides,
  };
  // The expected amount is the old order level in count units unless a test sets it.
  const expected = 'expected' in overrides ? base.expected : base.required_base === null ? null : base.required_base / base.units_per_count_unit;
  return { ...base, expected };
}

const SHELF_LABELS: Record<string, string> = { A1: 'A1 Airway', A2: 'A2 Wound care', B1: 'B1 IV supplies', C1: 'C1 Fluids', D1: 'D1 Splints' };

/** Three items are short: Nasal Airway (2), Gauze (15) and the long-named kit (2). */
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
  batchMode: 'ok' | 'fail422' | 'fail403';
  /** Hold the batch answer this long, to see the screen while a save is in flight. */
  batchDelayMs: number;
  /** Hold GET /supply/master answers this long. The answer is worked out when the request arrives, like a real server. */
  masterDelayMs: number;
  orderForbidden: boolean;
  submitDelayMs: number;
  conflictOnce: boolean;
  writes: Recorded[];
  submitted: boolean;
  nextOrderId: number;
  meta?: EmsMeta;
  /** GET /supply/screen: the supply room kiosk section. */
  screen: SupplyScreenData;
  screenForbidden: boolean;
  screenReads: number;
}

const NO_SCREEN: SupplyScreenData = { withdrawals: [], screens: [], search_words_waiting: 0 };

function newServer(overrides: Partial<Server> = {}): Server {
  return {
    items: catalog(), inactive: [], entries: null, putMode: 'ok', batchMode: 'ok', batchDelayMs: 0, masterDelayMs: 0, orderForbidden: false, submitDelayMs: 0, conflictOnce: false,
    writes: [], submitted: false, nextOrderId: 500, screen: NO_SCREEN, screenForbidden: false, screenReads: 0, ...overrides,
  };
}

const sectionsOf = (server: Server): MasterSection[] => {
  const codes = [...new Set(server.items.map((entry) => entry.location_code).filter((code): code is string => code !== null))].sort();
  return codes.map((code) => ({ code, label: SHELF_LABELS[code] ?? code, item_count: server.items.filter((entry) => entry.location_code === code).length }));
};

/** The server's stock lines: yellow at half the expected amount, red at a quarter, unless a person set their own. */
function linesOf(entry: MasterItem): { reorder: number; critical: number } | null {
  if (entry.expected === null || entry.expected < 1) return null;
  // A person's yellow line above a lowered expected amount sits at the expected amount.
  const reorder = Math.min(Math.floor(entry.expected), entry.reorder_at_auto || entry.reorder_at === null ? Math.floor(entry.expected / 2) : entry.reorder_at);
  const critical = entry.critical_at_auto || entry.critical_at === null ? Math.floor(entry.expected / 4) : entry.critical_at;
  return { reorder, critical: Math.min(critical, reorder) };
}

function levelOf(entry: MasterItem): MasterItem['level'] {
  const lines = linesOf(entry);
  if (lines === null || entry.on_hand === null) return null;
  if (entry.on_hand <= 0 || entry.on_hand <= lines.critical) return 'red';
  return entry.on_hand <= lines.reorder ? 'yellow' : 'green';
}

/** The server's own working: to order, the state (an open order winning) and the stock level. */
function present(entry: MasterItem): MasterItem {
  const toOrder = entry.on_hand === null || entry.expected === null ? null : Math.max(0, Math.ceil(entry.expected - entry.on_hand - 1e-9));
  const state: OrderState = entry.open_order ? entry.open_order.status
    : entry.on_hand === null ? 'not_counted' : entry.expected === null ? 'no_level' : (toOrder ?? 0) > 0 ? 'needs_order' : 'ok';
  const lines = linesOf(entry);
  return {
    ...entry, location_label: entry.location_code ? SHELF_LABELS[entry.location_code] ?? entry.location_code : null, to_order: toOrder, order_state: state,
    level: levelOf(entry), reorder_at: lines?.reorder ?? null, critical_at: lines?.critical ?? null,
    reorder_at_auto: entry.reorder_at_auto || entry.reorder_at === null, critical_at_auto: entry.critical_at_auto || entry.critical_at === null,
  };
}

const presentAll = (server: Server): MasterItem[] => server.items.map(present);

function summaryOf(server: Server) {
  const rows = presentAll(server);
  const count = (state: OrderState) => rows.filter((row) => row.order_state === state).length;
  const level = (name: 'red' | 'yellow' | 'green') => rows.filter((row) => row.level === name).length;
  return { needs_order: count('needs_order'), pending: count('pending'), ordered: count('ordered'), backordered: count('backordered'), red: level('red'), yellow: level('yellow'), green: level('green') };
}

const orderOf = (entry: MasterItem, typed: number): number => {
  if (entry.expected === null) return 0;
  return Math.max(0, Math.ceil(entry.expected - typed - 1e-9));
};

async function json(route: Route, body: unknown, status = 200): Promise<void> {
  await route.fulfill({ status, json: body, headers: { 'Cache-Control': 'private, no-store' } });
}
const ok = (route: Route, data: unknown, status = 200) => json(route, { ok: true, data, meta: {} }, status);
const fail = (route: Route, status: number, code: string, message: string, fields: Record<string, string[]> = {}) => json(route, { ok: false, error: { code, message, fields } }, status);

function setOnHand(target: MasterItem, onHand: number): void {
  target.on_hand = onHand;
  target.on_hand_base = onHand * target.units_per_count_unit;
}

/** PATCH /supply/master/items: every change or none. */
function applyBatch(server: Server, changes: Array<Record<string, unknown>>): MasterItem[] {
  return changes.map((change) => {
    const target = server.items.find((entry) => entry.id === change.id);
    if (!target) throw new Error(`No item ${String(change.id)}`);
    if (typeof change.name === 'string') target.name = change.name;
    if ('location_code' in change) target.location_code = change.location_code as string | null;
    if (typeof change.new_location_label === 'string') { target.location_code = change.new_location_label; SHELF_LABELS[change.new_location_label] = change.new_location_label; }
    if (typeof change.count_unit === 'string' || typeof change.units_per_package === 'number') {
      const unit = (change.count_unit ?? target.count_unit) as CountUnit;
      const words = UNITS.find((entry) => entry.value === unit) ?? UNITS[0];
      const size = unit === 'each' ? 1 : Number(change.units_per_package ?? target.units_per_package ?? 1);
      const base = target.on_hand_base;
      const expectedBase = target.expected === null ? null : target.expected * target.units_per_count_unit;
      Object.assign(target, { count_unit: unit, count_unit_label: words.label, count_unit_label_plural: words.plural, units_per_count_unit: size, units_per_package: unit === 'each' ? null : size, package_unit: unit === 'each' ? null : unit });
      target.on_hand = base === null ? null : base / size;
      target.expected = expectedBase === null ? null : expectedBase / size;
    }
    if ('expected' in change) target.expected = change.expected as number | null;
    // null puts a line back to the preset.
    if ('reorder_at' in change) Object.assign(target, { reorder_at: change.reorder_at as number | null, reorder_at_auto: change.reorder_at === null });
    if ('critical_at' in change) Object.assign(target, { critical_at: change.critical_at as number | null, critical_at_auto: change.critical_at === null });
    if (typeof change.on_hand === 'number') { setOnHand(target, change.on_hand); target.deducted = 0; target.deducted_base = 0; target.estimated = false; }
    return present(target);
  });
}

function findOrder(server: Server, orderId: number): MasterItem | undefined {
  return server.items.find((entry) => entry.open_order?.id === orderId);
}

async function handleOrders(route: Route, server: Server, path: string, recorded: Recorded): Promise<boolean> {
  const place = /^\/supply\/master\/items\/(\d+)\/order$/.exec(path);
  if (place) {
    const target = server.items.find((entry) => entry.id === Number(place[1]));
    if (!target) { await fail(route, 404, 'not_found', 'That item could not be found.'); return true; }
    if (target.open_order) { await fail(route, 409, 'order_open', 'This item already has an open order.'); return true; }
    if (server.orderForbidden) { await fail(route, 403, 'forbidden', 'This account cannot place orders.'); return true; }
    const body = recorded.body;
    const order: OpenOrder = {
      id: server.nextOrderId++, status: body.status as OpenOrder['status'], quantity: Number(body.quantity), unit_label: target.count_unit_label_plural,
      expected_on: (body.expected_on as string | undefined) ?? null, note: (body.note as string | undefined) ?? null,
      placed_at: '2026-11-02T15:00:00Z', placed_by: 'Alex Rivera', updated_at: '2026-11-02T15:00:00Z', updated_by: 'Alex Rivera',
    };
    target.open_order = order;
    await ok(route, present(target), 201);
    return true;
  }
  const change = /^\/supply\/orders\/(\d+)(\/arrived)?$/.exec(path);
  if (!change) return false;
  const target = findOrder(server, Number(change[1]));
  if (!target || !target.open_order) { await fail(route, 404, 'not_found', 'That order could not be found.'); return true; }
  const body = recorded.body;
  if (change[2]) {
    setOnHand(target, (target.on_hand ?? 0) + Number(body.quantity));
    target.open_order = null;
  } else if (body.status === 'cancelled') {
    target.open_order = null;
  } else {
    target.open_order = {
      ...target.open_order,
      ...(typeof body.status === 'string' ? { status: body.status as OpenOrder['status'] } : {}),
      ...(typeof body.quantity === 'number' ? { quantity: body.quantity } : {}),
      ...('expected_on' in body ? { expected_on: body.expected_on as string | null } : {}),
      ...('note' in body ? { note: body.note as string | null } : {}),
      updated_at: '2026-11-02T16:00:00Z',
    };
  }
  await ok(route, present(target));
  return true;
}

async function handleEms(route: Route, server: Server, url: URL): Promise<void> {
  const method = route.request().method();
  const path = url.pathname.replace('/ems/api', '');
  const text = route.request().postData();
  const recorded: Recorded = { method, path, key: route.request().headers()['idempotency-key'] ?? null, body: text ? JSON.parse(text) as Record<string, unknown> : {} };
  if (method !== 'GET') server.writes.push(recorded);

  if (path === '/meta') return ok(route, server.meta ?? META);
  if (path === '/supply/screen') {
    server.screenReads++;
    if (server.screenForbidden) return fail(route, 403, 'forbidden', 'You do not have access to the supply room inventory.');
    return ok(route, server.screen);
  }
  if (path === '/supply/master') {
    const body = {
      sections: sectionsOf(server), items: presentAll(server), inactive: server.inactive,
      draft: server.entries ? { id: COUNT_ID, started_at: '2026-11-02T13:00:00Z', started_by: 'Alex Rivera', counted: Object.keys(server.entries).length } : null,
      last_count: { id: 'f-1', finalized_at: '2026-10-26T14:00:00Z', finalized_name: 'Alex Rivera' }, can_edit: true,
      summary: summaryOf(server), units: UNITS,
    };
    if (server.masterDelayMs > 0) await new Promise((resolve) => setTimeout(resolve, server.masterDelayMs));
    return ok(route, body);
  }
  if (path === '/supply/master/items' && method === 'PATCH') {
    const changes = recorded.body.changes as Array<Record<string, unknown>>;
    if (server.batchDelayMs > 0) await new Promise((resolve) => setTimeout(resolve, server.batchDelayMs));
    if (server.batchMode === 'fail403') return fail(route, 403, 'forbidden', 'This account cannot change the inventory.');
    if (server.batchMode === 'fail422') {
      return fail(route, 422, 'validation_failed', 'Some changes could not be saved.', { 'changes.1.units_per_package': ['That size is too big for a box.'] });
    }
    return ok(route, { items: applyBatch(server, changes) });
  }
  if (path === '/supply/master/items' && method === 'POST') {
    const created = item(100, String(recorded.body.name), String(recorded.body.location_code ?? 'A1'), { on_hand: null, on_hand_base: null });
    server.items.push(created);
    return ok(route, present(created), 201);
  }
  if (await handleOrders(route, server, path, recorded)) return;
  const itemMatch = /^\/supply\/master\/items\/(\d+)(?:\/(count))?$/.exec(path);
  if (itemMatch) {
    const target = server.items.find((entry) => entry.id === Number(itemMatch[1]));
    if (!target) return fail(route, 404, 'not_found', 'That item could not be found.');
    if (itemMatch[2] === 'count') {
      setOnHand(target, Number(recorded.body.on_hand));
      target.deducted = 0;
      target.deducted_base = 0;
      return ok(route, present(target));
    }
    const body = recorded.body;
    if (typeof body.name === 'string') target.name = body.name;
    if ('location_code' in body) target.location_code = body.location_code as string | null;
    if (body.active === false) { server.items = server.items.filter((entry) => entry.id !== target.id); server.inactive.push({ id: target.id, name: target.name, location_code: target.location_code }); }
    return ok(route, present(target));
  }

  if (path === '/supply/count-form/start') {
    const resumed = server.entries !== null;
    server.entries ??= {};
    return ok(route, { count: { id: COUNT_ID, started_at: '2026-11-02T13:00:00Z', started_by: 'Alex Rivera', entries: { ...server.entries } }, resumed, sections: sectionsOf(server), items: presentAll(server) }, resumed ? 200 : 201);
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
const rowById = (page: Page, id: number) => page.locator(`[data-testid="master-row"][data-item-id="${id}"]`);
const editRow = (page: Page, id: number) => page.locator(`[data-testid="edit-row"][data-item-id="${id}"]`);
const countRow = (page: Page, name: string) => page.getByTestId('count-row').filter({ hasText: name });
const countInput = (page: Page, name: string) => countRow(page, name).getByTestId('count-input');
const status = (page: Page) => page.getByTestId('save-status');
const waitSaved = (page: Page) => expect(status(page)).toHaveAttribute('data-status', 'saved');
const batchWrites = (server: Server) => writesTo(server, '/supply/master/items', 'PATCH');

async function openItem(page: Page, id: number): Promise<Locator> {
  await rowById(page, id).getByTestId('open-item').click();
  const sheet = page.getByTestId('item-sheet');
  await expect(sheet).toBeVisible();
  return sheet;
}

// ------------------------------------------------------------------------------ the table

test.describe('master inventory table', () => {
  test('shows only Item, Shelf and On hand, with the last count line, a count button and one Edit button', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await expect(page.getByRole('columnheader')).toHaveText(['Item', 'Shelf', 'On hand']);
    await expect(page.getByTestId('last-count')).toHaveText('Last counted Oct 26, 2026 by Alex Rivera');
    await expect(page.getByTestId('start-count')).toHaveText(/Count Supply Room/);
    await expect(page.getByTestId('edit-mode')).toHaveText('Edit');
    await expect(page.getByTestId('master-row')).toHaveCount(2);
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A1 Airway');
    await expect(page.getByTestId('shelf-position-top')).toHaveText('Shelf 1 of 5');
  });

  test('view mode has no inputs and shows an order badge only on items that need one', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    const table = page.getByTestId('master-table');
    await expect(table.locator('input, select, textarea')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Edit / })).toHaveCount(0);
    await expect(rowFor(page, 'Nasal Airway').getByTestId('order-badge')).toHaveText('Order 2');
    await expect(rowFor(page, 'Nasal Airway').getByTestId('order-badge')).toHaveAttribute('data-state', 'needs_order');
    await expect(rowFor(page, 'Oral Airway').getByTestId('order-badge')).toHaveCount(0);
    await page.getByTestId('shelf-next-top').click();
    const gauze = rowFor(page, 'Gauze 4x4');
    await expect(gauze.getByTestId('on-hand-deducted')).toContainText('-5');
    await expect(gauze.getByTestId('order-badge')).toHaveText('Order 15');
    await expect(rowFor(page, 'Tape 1 inch').getByTestId('order-badge')).toHaveCount(0);
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

  test('a box item shows its unit, an uncounted item says so, and the item sheet shows expected, to order and the state', async ({ page }) => {
    await open(page, newServer(), `${SUPPLY}&shelf=B1`);
    await expect(rowFor(page, 'Saline Flush').getByTestId('on-hand')).toContainText('3');
    await expect(rowFor(page, 'Saline Flush').getByTestId('on-hand')).toContainText('boxes');
    await expect(rowFor(page, 'IV Catheter 20 Gauge').getByTestId('on-hand')).toHaveText('Not counted');
    const sheet = await openItem(page, 7);
    await expect(sheet.getByRole('heading', { name: 'Saline Flush' })).toBeVisible();
    await expect(sheet.getByTestId('sheet-expected')).toHaveText('2 boxes');
    await expect(sheet.getByTestId('sheet-to-order')).toHaveText('Nothing');
    await expect(sheet.getByTestId('sheet-state')).toHaveText('At or above the expected amount. Nothing to order.');
    await expect(sheet.getByTestId('order-new-ordered')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
  });
});

// ------------------------------------------------------------------------------ Edit mode

test.describe('Edit mode', () => {
  test('edits several fields across two items and saves one batch with only the changed fields', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('master-inventory')).toHaveAttribute('data-mode', 'edit');
    await expect(page.getByTestId('start-count')).toHaveCount(0);
    await expect(page.getByTestId('edit-row')).toHaveCount(2);

    const gauze = editRow(page, 3);
    await expect(gauze.getByTestId('edit-on-hand')).toHaveValue('5');
    await expect(gauze.getByTestId('edit-expected')).toHaveValue('20');
    await expect(gauze.getByTestId('edit-to-order')).toHaveText('15');
    await gauze.getByTestId('edit-name').fill('Gauze 4x4 sterile');
    await gauze.getByTestId('edit-expected').fill('25');
    await expect(gauze.getByTestId('edit-to-order')).toHaveText('20');

    const tape = editRow(page, 4);
    await expect(tape.getByText('On hand (single units)')).toBeVisible();
    await tape.getByTestId('edit-unit').selectOption('box');
    await expect(tape.getByText('On hand (boxes)')).toBeVisible();
    await expect(tape.getByText('Expected (boxes)')).toBeVisible();
    await expect(tape.getByText('Single units in one box')).toBeVisible();
    await tape.getByTestId('edit-size').fill('12');
    await tape.getByTestId('edit-on-hand').fill('2');
    await tape.getByTestId('edit-shelf').selectOption('B1');

    await expect(page.getByTestId('edit-save')).toHaveText('Save 2 changes');
    expect(batchWrites(server)).toHaveLength(0);
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toHaveText('Saved 2 items.');
    const patches = batchWrites(server);
    expect(patches).toHaveLength(1);
    expect(patches[0].key).toMatch(KEY_PATTERN);
    expect(patches[0].body).toEqual({
      changes: [
        { id: 3, name: 'Gauze 4x4 sterile', expected: 25 },
        { id: 4, location_code: 'B1', count_unit: 'box', units_per_package: 12, on_hand: 2 },
      ],
    });
    expect(writesTo(server, '/count')).toHaveLength(0);

    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('master-inventory')).toHaveAttribute('data-mode', 'view');
    await expect(rowFor(page, 'Gauze 4x4 sterile').getByTestId('order-badge')).toHaveText('Order 20');
    await expect(rowFor(page, 'Tape 1 inch')).toHaveCount(0);
    await page.getByTestId('shelf-next-top').click();
    await expect(rowFor(page, 'Tape 1 inch').getByTestId('on-hand-number')).toHaveText('2');
    await expect(rowFor(page, 'Tape 1 inch').getByTestId('on-hand')).toContainText('boxes');
  });

  test('a typed on-hand number counts as a physical count and clears the red number', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await expect(rowFor(page, 'Gauze 4x4').getByText('minus 5 since last count')).toHaveCount(1);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 3).getByTestId('edit-on-hand').fill('8');
    await expect(page.getByTestId('edit-save')).toHaveText('Save 1 change');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    expect(batchWrites(server)[0].body).toEqual({ changes: [{ id: 3, on_hand: 8 }] });
    await page.getByTestId('edit-mode').click();
    await expect(rowFor(page, 'Gauze 4x4').getByTestId('on-hand-number')).toHaveText('8');
    await expect(rowFor(page, 'Gauze 4x4').getByTestId('on-hand-deducted')).toHaveCount(0);
  });

  test('a server field error lands on the right row and box, and the client checks first', async ({ page }) => {
    const server = newServer({ batchMode: 'fail422' });
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await page.getByTestId('edit-mode').click();

    await editRow(page, 3).getByTestId('edit-name').fill('  ');
    await page.getByTestId('edit-save').click();
    await expect(editRow(page, 3).getByText('Enter a name.')).toBeVisible();
    await expect(editRow(page, 3)).toHaveAttribute('data-invalid', 'true');
    expect(batchWrites(server)).toHaveLength(0);

    await editRow(page, 3).getByTestId('edit-name').fill('Gauze 4x4 sterile');
    await editRow(page, 4).getByTestId('edit-unit').selectOption('case');
    await editRow(page, 4).getByTestId('edit-size').fill('0');
    await page.getByTestId('edit-save').click();
    await expect(editRow(page, 4).getByText('Enter how many single units are in one case, 1 or more.')).toBeVisible();
    expect(batchWrites(server)).toHaveLength(0);

    await editRow(page, 4).getByTestId('edit-size').fill('5000');
    await page.getByTestId('edit-save').click();
    const tapeSize = editRow(page, 4).getByTestId('edit-size');
    await expect(editRow(page, 4).getByText('That size is too big for a box.')).toBeVisible();
    await expect(tapeSize).toHaveAttribute('aria-invalid', 'true');
    await expect(editRow(page, 4)).toHaveAttribute('data-invalid', 'true');
    await expect(editRow(page, 3)).not.toHaveAttribute('data-invalid', 'true');
    await expect(page.getByTestId('edit-errors')).toContainText('marked in red');
    await expect(page.getByTestId('edit-problems')).toContainText('Tape 1 inch');
    expect(batchWrites(server)).toHaveLength(1);
    expect(server.items.find((entry) => entry.id === 3)?.name).toBe('Gauze 4x4');
  });

  test('Cancel asks first, then throws the changes away and leaves Edit mode', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('edit-done-bottom')).toBeVisible();
    await editRow(page, 1).getByTestId('edit-name').fill('Oral Airway Size 4');
    await page.getByTestId('edit-cancel').click();
    await expect(page.getByTestId('edit-discard-confirm')).toContainText('Discard 1 change?');
    await page.getByTestId('edit-discard-confirm').getByRole('button', { name: 'Go back' }).click();
    await expect(editRow(page, 1).getByTestId('edit-name')).toHaveValue('Oral Airway Size 4');
    await page.getByTestId('edit-mode').click();
    await page.getByTestId('edit-discard-confirm').getByRole('button', { name: 'Discard changes' }).click();
    await expect(page.getByTestId('master-inventory')).toHaveAttribute('data-mode', 'view');
    await expect(rowFor(page, 'Oral Airway Size 3')).toBeVisible();
    expect(batchWrites(server)).toHaveLength(0);
  });

  test('changes stay while moving between shelves and are saved together', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 2).getByTestId('edit-expected').fill('6');
    await page.getByTestId('shelf-next-top').click();
    await editRow(page, 4).getByTestId('edit-expected').fill('3');
    await page.getByTestId('shelf-prev-top').click();
    await expect(editRow(page, 2).getByTestId('edit-expected')).toHaveValue('6');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    expect(batchWrites(server)[0].body).toEqual({ changes: [{ id: 2, expected: 6 }, { id: 4, expected: 3 }] });
  });
});

// ------------------------------------------------------------------------- review fixes

test.describe('Edit mode safeguards', () => {
  test('a count typed in boxes is never sent as single units after the unit changes (10 boxes of 250)', async ({ page }) => {
    const server = newServer();
    Object.assign(server.items[6], { units_per_count_unit: 250, units_per_package: 250, on_hand: 10, on_hand_base: 2500, expected: 12, required_base: 3000 });
    await open(page, server, `${SUPPLY}&shelf=B1`);
    await page.getByTestId('edit-mode').click();
    const row = editRow(page, 7);
    await expect(row.getByTestId('edit-on-hand')).toHaveValue('10');
    await row.getByTestId('edit-on-hand').fill('12');
    await row.getByTestId('edit-on-hand').fill('10');
    await row.getByTestId('edit-unit').selectOption('each');
    await expect(row.getByTestId('edit-on-hand')).toHaveValue('2500');
    await expect(row.getByTestId('edit-expected')).toHaveValue('3000');
    await expect(row.getByTestId('edit-numbers-reset')).toContainText('the numbers you typed were cleared');
    await expect(row.getByTestId('edit-to-order')).toHaveText('500');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    expect(batchWrites(server)[0].body).toEqual({ changes: [{ id: 7, count_unit: 'each' }] });
    expect(server.items[6].on_hand_base).toBe(2500);
  });

  test('a refused save (403) keeps every change, says why, and leaves the tabs alone', async ({ page }) => {
    const server = newServer({ batchMode: 'fail403' });
    await open(page, server, SUPPLY);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 1).getByTestId('edit-name').fill('Oral Airway Size 4');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-errors')).toContainText('This account cannot change the inventory.');
    await expect(editRow(page, 1).getByTestId('edit-name')).toHaveValue('Oral Airway Size 4');
    await expect(page.getByTestId('edit-save')).toHaveText('Save 1 change');
    await expect(page.getByRole('tab', { name: 'Master Supply Room Inventory' })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Submitted Inventory Forms/ })).toBeVisible();
  });

  test('a refused order (403) shows inside the sheet and the inventory stays', async ({ page }) => {
    await open(page, newServer({ orderForbidden: true }), SUPPLY);
    const sheet = await openItem(page, 2);
    await sheet.getByTestId('order-new-ordered').click();
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByText('This account cannot place orders.')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('tab', { name: 'Master Supply Room Inventory' })).toBeVisible();
    await expect(page.getByTestId('master-row')).toHaveCount(2);
  });

  test('an emptied on-hand box is no change, and Undo puts a row back', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await page.getByTestId('edit-mode').click();
    const gauze = editRow(page, 3);
    await gauze.getByTestId('edit-on-hand').fill('');
    await expect(gauze.getByTestId('edit-on-hand')).toHaveAttribute('placeholder', '5');
    await expect(gauze.getByTestId('edit-undo')).toHaveCount(0);
    await expect(page.getByTestId('edit-done-bottom')).toBeVisible();
    await expect(editRow(page, 4).getByTestId('edit-to-order')).toHaveText('No expected amount or count yet');

    await gauze.getByTestId('edit-name').fill('Gauze 4x4 sterile');
    await gauze.getByTestId('edit-expected').fill('30');
    await expect(page.getByTestId('edit-save')).toHaveText('Save 1 change');
    await gauze.getByTestId('edit-undo').click();
    await expect(gauze.getByTestId('edit-name')).toHaveValue('Gauze 4x4');
    await expect(gauze.getByTestId('edit-expected')).toHaveValue('20');
    await expect(page.getByTestId('edit-done-bottom')).toBeVisible();
    expect(batchWrites(server)).toHaveLength(0);
  });

  test('while a save is in flight, Done and the order buttons are disabled', async ({ page }) => {
    const server = newServer({ batchDelayMs: 1500 });
    await open(page, server, SUPPLY);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 1).getByTestId('edit-expected').fill('8');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-mode')).toBeDisabled();
    await expect(editRow(page, 2).getByTestId('edit-state')).toBeDisabled();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    await expect(page.getByTestId('edit-mode')).toBeEnabled();
    await expect(editRow(page, 2).getByTestId('edit-state')).toBeEnabled();
  });

  test('unsaved changes survive leaving the tab and come back in Edit mode', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 1).getByTestId('edit-name').fill('Oral Airway Size 4');
    await page.getByRole('tab', { name: 'Today' }).click();
    await expect(page.getByTestId('master-inventory')).toHaveCount(0);
    await page.getByRole('tab', { name: 'Master Supply Room Inventory' }).click();
    await expect(page.getByTestId('master-inventory')).toHaveAttribute('data-mode', 'edit');
    await expect(page.getByTestId('master-notice')).toContainText('Your unsaved changes from earlier are still here.');
    await expect(editRow(page, 1).getByTestId('edit-name')).toHaveValue('Oral Airway Size 4');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    expect(batchWrites(server)[0].body).toEqual({ changes: [{ id: 1, name: 'Oral Airway Size 4' }] });
  });

  test('the item sheet tells Edit mode users to use the row', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await page.getByTestId('edit-mode').click();
    await editRow(page, 2).getByTestId('edit-state').click();
    await expect(page.getByTestId('sheet-edit-hint')).toHaveText("To change the name, shelf, unit or numbers, close this and use the boxes in this item's row.");
    await page.keyboard.press('Escape');
    await expect(editRow(page, 2).getByTestId('edit-state')).toBeFocused();
  });
});

test.describe('item sheet focus and fresh rows', () => {
  test('forms open on the quantity, a save or Go back lands on the first action, and closing returns to the item', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    const sheet = await openItem(page, 2);
    await sheet.getByTestId('order-new-ordered').click();
    await expect(sheet.getByTestId('order-quantity')).toBeFocused();
    await sheet.getByRole('button', { name: 'Go back' }).click();
    await expect(sheet.getByTestId('order-new-ordered')).toBeFocused();
    await sheet.getByTestId('order-new-ordered').click();
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-arrived')).toBeFocused();
    await sheet.getByTestId('order-arrived').click();
    await expect(sheet.getByTestId('order-quantity')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(rowById(page, 2).getByTestId('open-item')).toBeFocused();
  });

  test('when the row leaves the filter, closing the sheet focuses the summary button', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    await page.getByTestId('summary-needs_order').click();
    const sheet = await openItem(page, 2);
    await sheet.getByTestId('order-new-ordered').click();
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-done')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(rowById(page, 2)).toHaveCount(0);
    await expect(page.getByTestId('summary-needs_order')).toBeFocused();
  });

  test('a refresh that started before an order write cannot put the old row back', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await expect(rowById(page, 2).getByTestId('order-badge')).toHaveText('Order 2');
    server.masterDelayMs = 2500;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    const sheet = await openItem(page, 2);
    await sheet.getByTestId('order-new-ordered').click();
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-done')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(rowById(page, 2).getByTestId('order-badge')).toHaveText('Ordered 2');
    await page.waitForTimeout(3000);
    await expect(rowById(page, 2).getByTestId('order-badge')).toHaveText('Ordered 2');
  });
});

// ------------------------------------------------------------------------------ ordering

test.describe('ordering', () => {
  test('full lifecycle: needs ordering, ordered, back ordered, arrived, and the flag is gone', async ({ page }) => {
    const server = newServer();
    await open(page, server, SUPPLY);
    await expect(page.getByTestId('summary-needs_order')).toHaveText('3 to order');
    await expect(page.getByTestId('summary-ordered')).toHaveCount(0);

    const sheet = await openItem(page, 2);
    await expect(sheet.getByTestId('sheet-to-order')).toHaveText('2');
    await sheet.getByTestId('order-new-ordered').click();
    await expect(sheet.getByTestId('order-quantity')).toHaveValue('2');
    await sheet.getByTestId('order-expected-on').fill('2026-11-06');
    await sheet.getByTestId('order-note').fill('Bound Tree order 4471');
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-done')).toHaveText('Marked ordered: 2.');
    await expect(sheet.getByTestId('open-order-status')).toHaveText('Ordered: 2');
    await expect(sheet.getByTestId('open-order')).toContainText('Expected Nov 6, 2026');
    const placed = writesTo(server, '/items/2/order', 'POST');
    expect(placed).toHaveLength(1);
    expect(placed[0].body).toEqual({ status: 'ordered', quantity: 2, expected_on: '2026-11-06', note: 'Bound Tree order 4471' });
    expect(placed[0].key).toMatch(KEY_PATTERN);
    const orderId = server.items.find((entry) => entry.id === 2)?.open_order?.id;

    await sheet.getByTestId('order-set-backordered').click();
    await expect(sheet.getByTestId('open-order-status')).toHaveText('Back ordered: 2');
    expect(writesTo(server, `/supply/orders/${orderId}`, 'PATCH')[0].body).toEqual({ status: 'backordered' });

    await page.keyboard.press('Escape');
    await expect(rowById(page, 2).getByTestId('order-badge')).toHaveText('Back ordered 2');
    await expect(page.getByTestId('summary-needs_order')).toHaveText('2 to order');
    await expect(page.getByTestId('summary-backordered')).toHaveText('1 back ordered');

    await openItem(page, 2);
    await sheet.getByTestId('order-arrived').click();
    await expect(sheet.getByTestId('order-quantity')).toHaveValue('2');
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-done')).toHaveText('2 added to stock. The order is closed.');
    expect(writesTo(server, `/supply/orders/${orderId}/arrived`, 'POST')[0].body).toEqual({ quantity: 2 });
    await expect(sheet.getByTestId('open-order')).toHaveCount(0);
    await page.keyboard.press('Escape');

    await expect(rowById(page, 2).getByTestId('on-hand-number')).toHaveText('4');
    await expect(rowById(page, 2).getByTestId('order-badge')).toHaveCount(0);
    await expect(rowById(page, 2)).toHaveAttribute('data-state', 'ok');
    await expect(page.getByTestId('summary-backordered')).toHaveCount(0);
    await expect(page.getByTestId('summary-needs_order')).toHaveText('2 to order');
  });

  test('when less arrives than was short, the rest shows as needing an order again; cancelling asks first', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    const sheet = await openItem(page, 3);
    await sheet.getByTestId('order-new-pending').click();
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('open-order-status')).toHaveText('Pending: 15');
    await sheet.getByTestId('order-arrived').click();
    await sheet.getByTestId('order-quantity').fill('10');
    await sheet.getByTestId('order-save').click();
    await expect(sheet.getByTestId('order-done')).toHaveText('10 added to stock. Still short: order 5 more.');
    await expect(sheet.getByTestId('sheet-to-order')).toHaveText('5');

    await sheet.getByTestId('order-new-ordered').click();
    await sheet.getByTestId('order-save').click();
    await sheet.getByTestId('order-cancel').click();
    const orderId = server.items.find((entry) => entry.id === 3)?.open_order?.id;
    expect(writesTo(server, `/supply/orders/${orderId}`, 'PATCH')).toHaveLength(0);
    await sheet.getByTestId('order-cancel-confirm').getByRole('button', { name: 'Cancel order' }).click();
    await expect(sheet.getByTestId('order-done')).toHaveText('Order cancelled.');
    expect(writesTo(server, `/supply/orders/${orderId}`, 'PATCH')[0].body).toEqual({ status: 'cancelled' });
    await page.keyboard.press('Escape');
    await expect(rowById(page, 3).getByTestId('order-badge')).toHaveText('Order 5');
  });

  test('the summary filters every shelf to one state, and clearing goes back to the shelf view', async ({ page }) => {
    const server = newServer();
    server.items[8].open_order = { id: 900, status: 'ordered', quantity: 2, unit_label: 'each', expected_on: null, note: null, placed_at: null, placed_by: null, updated_at: null, updated_by: null };
    await open(page, server, SUPPLY);
    await expect(page.getByTestId('summary-pending')).toHaveCount(0);
    await page.getByTestId('summary-needs_order').click();
    await expect(page.getByTestId('summary-needs_order')).toHaveAttribute('aria-pressed', 'true');
    const results = page.getByTestId('filter-results');
    await expect(results.getByTestId('item-name')).toHaveText(['Nasal Airway 28 Fr', 'Gauze 4x4', LONG_NAME]);
    await expect(page.getByTestId('filter-count')).toHaveText('3 items to order, on all shelves');
    await expect(page.getByTestId('shelf-view')).toHaveCount(0);

    await page.getByTestId('summary-ordered').click();
    await expect(results.getByTestId('item-name')).toHaveText(['Cervical Collar Adult']);
    await page.getByTestId('summary-ordered').click();
    await expect(page.getByTestId('shelf-view')).toBeVisible();

    await page.getByTestId('summary-needs_order').click();
    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('edit-row')).toHaveCount(3);
    await page.getByTestId('edit-mode').click();
    await page.getByTestId('filter-clear').click();
    await expect(page.getByTestId('shelf-view')).toBeVisible();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A1 Airway');
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

  test('the order shows as soon as a number is typed, in the item\'s own unit, worked out from the expected amount', async ({ page }) => {
    await open(page, newServer(), `${COUNT}&shelf=A2`);
    await countInput(page, 'Gauze 4x4').fill('15');
    await expect(countRow(page, 'Gauze 4x4').getByTestId('order-result')).toHaveText('Order 5');
    await countInput(page, 'Gauze 4x4').fill('25');
    await expect(countRow(page, 'Gauze 4x4').getByTestId('order-result')).toHaveText('No order needed');
    await countInput(page, 'Tape 1 inch').fill('3');
    await expect(countRow(page, 'Tape 1 inch').getByTestId('order-result')).toHaveText('No expected amount set');
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

  test('review lists what was not counted for information only, what to order, and Submit is always the main button', async ({ page }) => {
    const server = newServer();
    await open(page, server, COUNT);
    await countInput(page, 'Oral Airway Size 3').fill('3');
    await countInput(page, 'Nasal Airway 28 Fr').fill('9');
    await page.getByTestId('review-bottom').click();
    const review = page.getByTestId('count-review');
    await expect(review).toBeVisible();
    await expect(page.getByTestId('review-counted')).toHaveText('2 items counted.');
    await expect(review.getByRole('heading', { name: '8 items left blank keep their current number (or stay Not counted)' })).toBeVisible();
    await expect(review.getByTestId('missing-group')).toHaveCount(4);
    await expect(review.getByTestId('missing-item')).toHaveCount(8);
    await expect(review.getByTestId('review-orders')).toContainText('Oral Airway Size 3');
    await expect(review.getByTestId('review-orders')).toContainText('Order 1');
    await expect(review.getByTestId('keep-counting')).toHaveCount(0);
    await expect(review.getByTestId('submit-inventory')).toHaveText('Submit inventory');
    await expect(review.getByTestId('submit-inventory')).toHaveClass(/hub-btn--primary/);
    await expect(review.getByTestId('submit-inventory')).toBeEnabled();
    await expect(review.getByTestId('back-to-counting')).toHaveClass(/hub-btn--secondary/);

    await review.getByRole('button', { name: 'Tape 1 inch' }).click();
    await expect(page.getByTestId('shelf-name-top')).toHaveText('A2 Wound care');
    await expect(countInput(page, 'Tape 1 inch')).toBeFocused();

    await page.getByTestId('review-top').click();
    server.conflictOnce = true;
    await page.getByTestId('submit-inventory').click();
    await expect(page.getByTestId('review-changed')).toContainText('review has been reloaded');
    await page.getByTestId('submit-inventory').click();
    await expect(page.getByTestId('count-done')).toBeVisible();
    const submits = writesTo(server, '/submit');
    expect(submits[submits.length - 1].body).toEqual({ expected_uncounted: 8 });
  });

  test('a count with every box left blank but one still submits, with nothing blocking it', async ({ page }) => {
    const server = newServer();
    await open(page, server, COUNT);
    await countInput(page, 'Nasal Airway 28 Fr').fill('1');
    await waitSaved(page);
    await page.getByTestId('review-bottom').click();
    await expect(page.getByTestId('count-review')).toBeVisible();
    await expect(page.getByRole('heading', { name: '9 items left blank keep their current number (or stay Not counted)' })).toBeVisible();
    await page.getByTestId('submit-inventory').click();
    await expect(page.getByTestId('count-done')).toBeVisible();
    expect(writesTo(server, '/submit')).toHaveLength(1);
    expect(writesTo(server, '/submit')[0].body).toEqual({ expected_uncounted: 9 });
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

// ------------------------------------------------------------------- standalone page

test.describe('standalone Admin page', () => {
  test('/daily/supply-room shows the same inventory under its own heading with a same-window link back to Admin', async ({ page }) => {
    const server = newServer({ meta: { ...META, permissions: { view: false, act: false, configure: false }, inventory: { read: true, edit: true } } });
    await open(page, server, STANDALONE);
    await expect(page.getByRole('heading', { level: 1, name: 'Master Supply Room Inventory' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Master Supply Room Inventory' })).toHaveCount(1);
    const back = page.getByTestId('back-to-admin');
    await expect(back).toHaveAttribute('href', '/admin');
    await expect(back).not.toHaveAttribute('target', /.+/);
    await expect(page.getByRole('columnheader')).toHaveText(['Item', 'Shelf', 'On hand']);
    await expect(page.getByTestId('master-row')).toHaveCount(2);
    await expect(page.getByTestId('edit-mode')).toBeVisible();
    await page.getByTestId('start-count').click();
    await expect(page).toHaveURL(/\/daily\/supply-room\?.*count=1/);
    await expect(page.getByTestId('count-supply-room')).toBeVisible();
    await page.getByTestId('count-back').click();
    await expect(page.getByTestId('master-inventory')).toBeVisible();
  });

  test('without inventory access the standalone page says so', async ({ page }) => {
    await open(page, newServer({ meta: { ...META, permissions: { view: false, act: false, configure: false }, inventory: { read: false, edit: false } } }), STANDALONE);
    await expect(page.getByTestId('supply-room-denied')).toBeVisible();
    await expect(page.getByTestId('master-inventory')).toHaveCount(0);
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

for (const width of [320, 390, 1280]) {
  test(`no horizontal scrolling at ${width}px in view mode with badges, Edit mode, the filter, the item sheet and the standalone page`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    const server = newServer();
    server.items[9].open_order = { id: 901, status: 'backordered', quantity: 120, unit_label: 'each', expected_on: '2026-11-20', note: 'Vendor says two weeks', placed_at: null, placed_by: null, updated_at: null, updated_by: null };
    await open(page, server, `${SUPPLY}&shelf=D1`);
    await expect(rowFor(page, 'Polyisobutylene').getByTestId('order-badge')).toHaveText('Back ordered 120');
    await noHorizontalOverflow(page);
    await shot(page, `view-badges-${width}`);

    await page.getByTestId('edit-mode').click();
    await editRow(page, 10).getByTestId('edit-unit').selectOption('carton');
    await editRow(page, 10).getByTestId('edit-shelf').selectOption('__new__');
    await editRow(page, 10).getByTestId('edit-new-shelf').fill('Overflow bay by the back door');
    await expect(page.getByTestId('edit-bar')).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, `edit-${width}`);

    await page.getByTestId('summary-needs_order').click();
    await expect(page.getByTestId('filter-results')).toBeVisible();
    await noHorizontalOverflow(page);
    await page.getByTestId('summary-needs_order').click();
    await page.getByTestId('edit-mode').click();
    await page.getByTestId('edit-discard-confirm').getByRole('button', { name: 'Discard changes' }).click();

    const sheet = await openItem(page, 10);
    await expect(sheet.getByTestId('open-order')).toContainText('Vendor says two weeks');
    await sheet.getByTestId('order-edit').click();
    await noHorizontalOverflow(page);
    await page.keyboard.press('Escape');

    await page.goto(`${STANDALONE}?shelf=D1`);
    await expect(rowFor(page, 'Polyisobutylene')).toBeVisible();
    await noHorizontalOverflow(page);
    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('edit-row')).toHaveCount(2);
    await noHorizontalOverflow(page);
  });
}

test('a best guess says Estimate until a real number replaces it', async ({ page }) => {
  const server = newServer({ items: [item(1, 'Oral Airway Size 3', 'A1', { on_hand: 6, on_hand_base: 6, estimated: true }), item(2, 'Nasal Airway 28 Fr', 'A1', { on_hand: 2, on_hand_base: 2, estimated: false })] });
  await open(page, server, SUPPLY);
  const guess = rowById(page, 1);
  await expect(guess.getByTestId('on-hand-estimate')).toHaveText('Estimate');
  await expect(rowById(page, 2).getByTestId('on-hand-estimate')).toHaveCount(0);

  const sheet = await openItem(page, 1);
  await expect(sheet.getByText('The on-hand number is an estimate until someone counts it.')).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByTestId('edit-mode').click();
  await editRow(page, 1).getByLabel('On hand (single units)').fill('11');
  await page.getByTestId('edit-save').click();
  await expect(page.getByTestId('edit-saved')).toBeVisible();
  await page.getByTestId('edit-mode').click();
  await expect(guess.getByTestId('on-hand-number')).toHaveText('11');
  await expect(guess.getByTestId('on-hand-estimate')).toHaveCount(0);
});

test('an administrator with no other EMS access gets the master inventory and can edit it, but not the forms tab', async ({ page }) => {
  const server = newServer({ meta: { ...META, permissions: { view: false, act: false, configure: false }, inventory: { read: true, edit: true } } });
  await open(page, server, SUPPLY);
  await expect(page.getByRole('tab', { name: 'Master Supply Room Inventory' })).toBeVisible();
  await expect(page.getByRole('tab', { name: /Submitted Inventory Forms/ })).toHaveCount(0);
  await expect(page.getByTestId('edit-mode')).toBeVisible();
  await expect(page.getByRole('button', { name: /Count Supply Room/ }).first()).toBeVisible();
});

test('every control is at least 44px on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, newServer(), SUPPLY);
  await expectTouchTarget(page.getByTestId('start-count'), 'Count Supply Room');
  await expectTouchTarget(page.getByTestId('add-item'), 'Add item');
  await expectTouchTarget(page.getByTestId('edit-mode'), 'Edit');
  await expectTouchTarget(rowById(page, 1).getByTestId('open-item'), 'Item name');
  await expectTouchTarget(page.getByTestId('summary-needs_order'), 'Summary filter');
  await expectTouchTarget(page.getByTestId('shelf-next-top'), 'Next shelf, top');
  await expectTouchTarget(page.getByTestId('shelf-prev-bottom'), 'Previous shelf, bottom');
  await expectTouchTarget(page.getByTestId('shelf-picker'), 'Shelf picker');
  await page.getByTestId('master-search').fill('18g');
  await expectTouchTarget(page.getByTestId('master-search-clear'), 'Clear search');
  await expectTouchTarget(page.locator('[data-testid=shelf-link]:visible').first(), 'Shelf link');
  await page.getByTestId('master-search-clear').click();

  const sheet = await openItem(page, 2);
  await expectTouchTarget(sheet.getByTestId('order-new-ordered'), 'Mark ordered');
  await expectTouchTarget(sheet.getByTestId('order-new-pending'), 'Mark pending');
  await expectTouchTarget(sheet.getByTestId('order-new-backordered'), 'Mark back ordered');
  await sheet.getByTestId('order-new-ordered').click();
  await expectTouchTarget(sheet.getByTestId('order-quantity'), 'Order quantity');
  await expectTouchTarget(sheet.getByTestId('order-expected-on'), 'Expected by');
  await expectTouchTarget(sheet.getByTestId('order-save'), 'Save order');
  await sheet.getByTestId('order-save').click();
  await expectTouchTarget(sheet.getByTestId('order-arrived'), 'Mark arrived');
  await expectTouchTarget(sheet.getByTestId('order-set-backordered'), 'Mark back ordered (open order)');
  await expectTouchTarget(sheet.getByTestId('order-edit'), 'Change the order');
  await expectTouchTarget(sheet.getByTestId('order-cancel'), 'Cancel order');
  await page.keyboard.press('Escape');

  await page.getByTestId('edit-mode').click();
  const row = editRow(page, 1);
  for (const id of ['edit-name', 'edit-shelf', 'edit-unit', 'edit-on-hand', 'edit-expected', 'edit-state']) await expectTouchTarget(row.getByTestId(id), id);
  await row.getByTestId('edit-unit').selectOption('box');
  await expectTouchTarget(row.getByTestId('edit-size'), 'Single units in one box');
  await expectTouchTarget(page.getByTestId('edit-save'), 'Save changes');
  await expectTouchTarget(page.getByTestId('edit-cancel'), 'Cancel');
  await page.getByTestId('edit-cancel').click();
  await page.getByTestId('edit-discard-confirm').getByRole('button', { name: 'Discard changes' }).click();

  await page.goto(COUNT);
  await expectTouchTarget(countInput(page, 'Oral Airway Size 3'), 'Count box');
  await expectTouchTarget(page.getByTestId('count-back'), 'Back');
  await expectTouchTarget(page.getByTestId('shelf-next-top'), 'Count next shelf');
  await expectTouchTarget(page.getByTestId('count-search-toggle'), 'Find an item');
  await expectTouchTarget(page.getByTestId('review-top'), 'Review');
  await expectTouchTarget(page.getByTestId('review-bottom'), 'Review and submit');
  await page.getByTestId('review-bottom').click();
  await expectTouchTarget(page.getByTestId('missing-item').first(), 'Missing item');
  await expectTouchTarget(page.getByTestId('submit-inventory'), 'Submit inventory');
  await expectTouchTarget(page.getByTestId('back-to-counting'), 'Back to counting');
});

// ------------------------------------------------------------------------- stock levels

const backgroundOf = (locator: Locator) => locator.evaluate((element) => getComputedStyle(element).backgroundColor);
const TRANSPARENT = 'rgba(0, 0, 0, 0)';

test.describe('stock levels', () => {
  test('view mode tints each row by level and says it in words; an item with no level stays plain', async ({ page }) => {
    await open(page, newServer(), SUPPLY);
    // Oral Airway 6 of 4 is stocked, Nasal Airway 2 of 4 is at the yellow line (half).
    await expect(rowById(page, 1)).toHaveAttribute('data-level', 'green');
    await expect(rowById(page, 1).getByTestId('stock-level')).toHaveText('Stocked');
    await expect(rowById(page, 2)).toHaveAttribute('data-level', 'yellow');
    await expect(rowById(page, 2).getByTestId('stock-level')).toHaveText('Reorder soon');
    const green = await backgroundOf(rowById(page, 1));
    const yellow = await backgroundOf(rowById(page, 2));

    // Gauze 5 of 20 is at the red line (a quarter); Tape has no expected amount.
    await page.getByTestId('shelf-next-top').click();
    await expect(rowById(page, 3)).toHaveAttribute('data-level', 'red');
    await expect(rowById(page, 3).getByTestId('stock-level')).toHaveText('Low or out');
    await expect(rowById(page, 4)).toHaveAttribute('data-level', 'none');
    await expect(rowById(page, 4).getByTestId('stock-level')).toHaveCount(0);
    const red = await backgroundOf(rowById(page, 3));
    const plain = await backgroundOf(rowById(page, 4));
    expect(plain).toBe(TRANSPARENT);
    expect(new Set([green, yellow, red, plain]).size).toBe(4);
  });

  test('the standalone Admin page colours the rows the same way', async ({ page }) => {
    const server = newServer({ meta: { ...META, permissions: { view: false, act: false, configure: false }, inventory: { read: true, edit: true } } });
    await open(page, server, `${STANDALONE}?shelf=A2`);
    await expect(rowById(page, 3)).toHaveAttribute('data-level', 'red');
    await expect(rowById(page, 3).getByTestId('stock-level')).toHaveText('Low or out');
    await expect(page.getByTestId('low-stock-card').getByRole('heading', { level: 2 })).toHaveText('1 item is low or out');
  });

  test('the Low or out card lists every red item with exactly what to order, or its open order, and opens the item', async ({ page }) => {
    const server = newServer();
    Object.assign(server.items[1], {
      on_hand: 1, on_hand_base: 1,
      open_order: { id: 902, status: 'backordered', quantity: 3, unit_label: 'each', expected_on: '2026-11-09', note: null, placed_at: null, placed_by: null, updated_at: null, updated_by: null },
    });
    Object.assign(server.items[2], { estimated: true });
    Object.assign(server.items[6], { on_hand: 0, on_hand_base: 0 });
    await open(page, server, SUPPLY);

    const card = page.getByTestId('low-stock-card');
    await expect(card.getByTestId('low-stock-heading')).toHaveText('3 items are low or out');
    await expect(card.getByTestId('low-stock-name')).toHaveText(['Nasal Airway 28 Fr', 'Gauze 4x4', 'Saline Flush']);
    const lowItem = (name: string) => card.getByTestId('low-stock-item').filter({ hasText: name });

    const gauze = lowItem('Gauze 4x4');
    await expect(gauze.getByTestId('low-stock-shelf')).toHaveText('A2 Wound care');
    await expect(gauze.getByTestId('low-stock-amount')).toHaveText('5 of 20');
    await expect(gauze.getByTestId('low-stock-advice')).toHaveText('Order 15');
    await expect(gauze.getByTestId('low-stock-estimate')).toHaveText('Estimate');
    await expect(lowItem('Saline Flush').getByTestId('low-stock-amount')).toHaveText('0 of 2 boxes');
    await expect(lowItem('Saline Flush').getByTestId('low-stock-advice')).toHaveText('Order 2 boxes');
    await expect(lowItem('Saline Flush').getByTestId('low-stock-estimate')).toHaveCount(0);
    // An open order is shown instead of asking for another one.
    await expect(lowItem('Nasal Airway').getByTestId('low-stock-advice')).toHaveText('Back ordered 3, expected Nov 9');
    await expect(lowItem('Nasal Airway').getByTestId('low-stock-advice')).toHaveAttribute('data-kind', 'open');

    await gauze.click();
    const sheet = page.getByTestId('item-sheet');
    await expect(sheet.getByRole('heading', { name: 'Gauze 4x4' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
    await expect(gauze).toBeFocused();

    await page.getByTestId('edit-mode').click();
    await expect(page.getByTestId('master-inventory')).toHaveAttribute('data-mode', 'edit');
    await expect(card.getByTestId('low-stock-item')).toHaveCount(3);
  });

  test('with nothing red the card is one short line, and a long list folds after five', async ({ page }) => {
    const server = newServer();
    Object.assign(server.items[2], { on_hand: 15, on_hand_base: 15 });
    await open(page, server, SUPPLY);
    await expect(page.getByTestId('low-stock-none')).toHaveText('Nothing is low or out.');
    await expect(page.getByTestId('low-stock-card')).toHaveCount(0);

    server.items = Array.from({ length: 7 }, (_, index) => item(20 + index, `Splint size ${index + 1}`, 'D1', { on_hand: 0, on_hand_base: 0, required_base: 4 }));
    await page.reload();
    const card = page.getByTestId('low-stock-card');
    await expect(card.getByTestId('low-stock-heading')).toHaveText('7 items are low or out');
    await expect(card.getByTestId('low-stock-item')).toHaveCount(5);
    await expect(card.getByTestId('low-stock-more')).toHaveText('Show all 7');
    await card.getByTestId('low-stock-more').click();
    await expect(card.getByTestId('low-stock-item')).toHaveCount(7);
    await expect(card.getByTestId('low-stock-more')).toHaveText('Show fewer');
    await expect(card.getByTestId('low-stock-more')).toHaveAttribute('aria-expanded', 'true');
  });

  test('Edit mode: Yellow at and Red at start on Auto, recolour the row live, are checked, and save only when changed', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${SUPPLY}&shelf=A2`);
    await page.getByTestId('edit-mode').click();
    const gauze = editRow(page, 3);
    await expect(gauze).toHaveAttribute('data-level', 'red');
    await expect(gauze.getByTestId('edit-level')).toHaveText('Low or out');
    await expect(gauze.getByTestId('edit-reorder')).toHaveValue('');
    await expect(gauze.getByTestId('edit-reorder')).toHaveAttribute('placeholder', '10');
    await expect(gauze.getByTestId('edit-reorder-auto')).toHaveText('Auto: 10');
    await expect(gauze.getByTestId('edit-critical-auto')).toHaveText('Auto: 5');
    await expect(gauze.getByLabel('Yellow at (single units)')).toBeVisible();
    await expect(gauze.getByLabel('Red at (single units)')).toBeVisible();

    // A new expected amount works the Auto lines out again, and the colour follows.
    await gauze.getByTestId('edit-expected').fill('8');
    await expect(gauze.getByTestId('edit-reorder-auto')).toHaveText('Auto: 4');
    await expect(gauze.getByTestId('edit-critical-auto')).toHaveText('Auto: 2');
    await expect(gauze).toHaveAttribute('data-level', 'green');
    await gauze.getByTestId('edit-expected').fill('20');
    await expect(gauze).toHaveAttribute('data-level', 'red');

    // Combinations the server would refuse are refused here first, and nothing is sent.
    await gauze.getByTestId('edit-critical').fill('12');
    await page.getByTestId('edit-save').click();
    await expect(gauze.getByText('Red at cannot be above Yellow at.')).toBeVisible();
    await expect(gauze.getByTestId('edit-critical')).toHaveAttribute('aria-invalid', 'true');
    await gauze.getByTestId('edit-reorder').fill('25');
    await expect(gauze.getByText('Yellow at cannot be above the expected amount.')).toBeVisible();
    await page.getByTestId('edit-save').click();
    expect(batchWrites(server)).toHaveLength(0);

    // Valid lines recolour the row before anything is saved; so does a typed on hand.
    await gauze.getByTestId('edit-reorder').fill('12');
    await gauze.getByTestId('edit-critical').fill('4');
    await expect(gauze).toHaveAttribute('data-level', 'yellow');
    await expect(gauze.getByTestId('edit-level')).toHaveText('Reorder soon');
    await gauze.getByTestId('edit-on-hand').fill('13');
    await expect(gauze).toHaveAttribute('data-level', 'green');
    await gauze.getByTestId('edit-on-hand').fill('');
    await expect(gauze).toHaveAttribute('data-level', 'yellow');

    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toHaveText('Saved 1 item.');
    expect(batchWrites(server)[0].body).toEqual({ changes: [{ id: 3, reorder_at: 12, critical_at: 4 }] });
    await expect(gauze.getByTestId('edit-reorder')).toHaveValue('12');
    await expect(gauze.getByTestId('edit-reorder-auto')).toHaveCount(0);

    // Clearing a box puts that line back to Auto: null is sent.
    await gauze.getByTestId('edit-reorder').fill('');
    await expect(gauze.getByTestId('edit-reorder-auto')).toHaveText('Auto: 10');
    await page.getByTestId('edit-save').click();
    await expect(page.getByTestId('edit-saved')).toBeVisible();
    expect(batchWrites(server)[1].body).toEqual({ changes: [{ id: 3, reorder_at: null }] });

    await page.getByTestId('edit-mode').click();
    await expect(rowById(page, 3)).toHaveAttribute('data-level', 'yellow');
    await expect(rowById(page, 3).getByTestId('stock-level')).toHaveText('Reorder soon');
  });

  test('the count page shows the expected amount, colours each row, and recolours as soon as a number is typed', async ({ page }) => {
    const server = newServer();
    await open(page, server, `${COUNT}&shelf=A2`);
    const gauze = countRow(page, 'Gauze 4x4');
    await expect(gauze.getByTestId('count-expected')).toHaveText('Expected 20');
    // Blank: where the item stands now.
    await expect(gauze).toHaveAttribute('data-level', 'red');
    await expect(gauze.getByTestId('count-level')).toContainText('Low or out');

    await countInput(page, 'Gauze 4x4').fill('15');
    await expect(gauze).toHaveAttribute('data-level', 'green');
    await expect(gauze.getByTestId('count-level')).toHaveText('Stocked');
    await countInput(page, 'Gauze 4x4').fill('8');
    await expect(gauze).toHaveAttribute('data-level', 'yellow');
    await expect(gauze.getByTestId('count-level')).toHaveText('Reorder soon');
    await countInput(page, 'Gauze 4x4').fill('0');
    await expect(gauze).toHaveAttribute('data-level', 'red');
    await expect(gauze.getByTestId('order-result')).toHaveText('Order 20');

    const tape = countRow(page, 'Tape 1 inch');
    await expect(tape.getByTestId('count-expected')).toHaveText('No expected amount');
    await expect(tape).toHaveAttribute('data-level', 'none');
    await expect(tape.getByTestId('count-level')).toHaveCount(0);

    await page.getByTestId('shelf-next-bottom').click();
    await expect(countRow(page, 'Saline Flush').getByTestId('count-expected')).toHaveText('Expected 2 boxes');
    await expect(countRow(page, 'IV Catheter 20 Gauge')).toHaveAttribute('data-level', 'none');

    // Every box stays optional: one number is enough to submit.
    await waitSaved(page);
    await page.getByTestId('review-bottom').click();
    await expect(page.getByTestId('submit-inventory')).toBeEnabled();
  });
});

// ------------------------------------------------------------------ supply room screen

const withdrawal = (n: number, overrides: Partial<ScreenWithdrawal> = {}): ScreenWithdrawal => ({
  uuid: `00000000-aaaa-4aaa-8aaa-${String(n).padStart(12, '0')}`, number: `W-${String(n).padStart(6, '0')}`, employee_name: 'Lt. J. Smith', unit_label: 'R22',
  taken_at: `Nov 3, 9:${String(10 + n).padStart(2, '0')} AM`, taken_at_iso: `2026-11-03T14:${String(10 + n).padStart(2, '0')}:00Z`, status: 'taken', note: null, count_due: false,
  lines: [{ name: 'Gauze 4x4', quantity: 2, unit_label: 'each', count_due: false }], ...overrides,
});

const ONLINE_SCREEN: SupplyScreenData['screens'][number] = {
  id: 1, name: 'Supply room screen', state: 'online', state_label: 'Online', last_seen: 'Nov 3, 9:15 AM', paired_at: 'Nov 1, 8:00 AM', paired_by: 'Alex Rivera',
};

test.describe('supply room screen section', () => {
  test('shows the screen, recent withdrawals with Undone and Count due, the note and the search words; it refreshes with the list', async ({ page }) => {
    const server = newServer({
      screen: {
        screens: [ONLINE_SCREEN, { ...ONLINE_SCREEN, id: 2, name: 'Old screen', state: 'revoked', state_label: 'Revoked' }],
        withdrawals: [
          withdrawal(1, { unit_label: null, note: 'Shelf was empty', count_due: true,
            lines: [{ name: 'IV Catheter 18 Gauge', quantity: 3, unit_label: 'each', count_due: true }, { name: 'Saline Flush', quantity: 1, unit_label: 'box', count_due: false }] }),
          withdrawal(2, { status: 'undone', employee_name: 'FF A. Jones' }),
        ],
        search_words_waiting: 2,
      },
    });
    await open(page, server, SUPPLY);
    const section = page.getByTestId('supply-screen');
    await expect(section.getByTestId('screen-status')).toHaveText(['Supply room screen: Online, last checked in Nov 3, 9:15 AM']);
    await expect(section.getByTestId('screen-status')).toHaveAttribute('data-state', 'online');
    // Collapsed until opened; the master list is still right there.
    await expect(section.getByTestId('screen-withdrawal')).toHaveCount(0);
    await expect(page.getByTestId('master-row').first()).toBeVisible();
    await section.getByTestId('supply-screen-toggle').click();
    await expect(section.getByTestId('supply-screen-toggle')).toHaveAttribute('aria-expanded', 'true');

    const rows = section.getByTestId('screen-withdrawal');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0).getByTestId('screen-withdrawal-time')).toHaveText('Nov 3, 9:11 AM');
    await expect(rows.nth(0).getByTestId('screen-withdrawal-who')).toHaveText('Lt. J. Smith, No unit');
    await expect(rows.nth(0).getByTestId('screen-withdrawal-line')).toHaveText(['IV Catheter 18 Gauge: 3 eachCount due', 'Saline Flush: 1 box']);
    await expect(rows.nth(0).getByTestId('screen-line-count-due')).toHaveCount(1);
    await expect(rows.nth(0).getByTestId('screen-withdrawal-note')).toHaveText('Note: Shelf was empty');
    await expect(rows.nth(0).getByTestId('screen-withdrawal-undone')).toHaveCount(0);
    await expect(rows.nth(1)).toHaveAttribute('data-status', 'undone');
    await expect(rows.nth(1).getByTestId('screen-withdrawal-undone')).toHaveText('Undone');
    await expect(rows.nth(1).getByTestId('screen-withdrawal-who')).toHaveText('FF A. Jones, R22');
    await expect(section.getByTestId('screen-search-words')).toHaveText('2 search words to review in Admin > Medical Supply Room > Supply room screen.');

    // The section follows the master list's refresh.
    const before = server.screenReads;
    server.screen = { ...server.screen, screens: [{ ...ONLINE_SCREEN, state: 'offline', state_label: 'Offline', last_seen: 'Nov 3, 8:40 AM' }] };
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect(section.getByTestId('screen-status')).toHaveText(['Supply room screen: Offline, last checked in Nov 3, 8:40 AM']);
    await expect(section.getByTestId('screen-status')).toHaveAttribute('data-state', 'offline');
    expect(server.screenReads).toBeGreaterThan(before);
  });

  test('with no screen paired it says where to pair one, and a long list folds after ten', async ({ page }) => {
    const server = newServer({ screen: { screens: [], withdrawals: Array.from({ length: 12 }, (_, i) => withdrawal(i + 1)), search_words_waiting: 0 } });
    await open(page, server, SUPPLY);
    const section = page.getByTestId('supply-screen');
    await expect(section.getByTestId('screen-status-none')).toHaveText('No screen is paired yet. Pair one from Admin > Medical Supply Room > Supply room screen.');
    await section.getByTestId('supply-screen-toggle').click();
    await expect(section.getByTestId('screen-withdrawal')).toHaveCount(10);
    await expect(section.getByTestId('screen-search-words')).toHaveCount(0);
    await section.getByTestId('screen-withdrawals-more').click();
    await expect(section.getByTestId('screen-withdrawal')).toHaveCount(12);
  });

  test('a 403 leaves the section out and the master list in place', async ({ page }) => {
    const server = newServer({ screenForbidden: true });
    await open(page, server, SUPPLY);
    await expect(page.getByTestId('master-row').first()).toBeVisible();
    await expect.poll(() => server.screenReads).toBeGreaterThan(0);
    await expect(page.getByTestId('supply-screen')).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Master Supply Room Inventory' })).toBeVisible();
  });

  for (const width of [320, 390]) {
    test(`no horizontal scrolling at ${width}px with the section open`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      const server = newServer({
        screen: { screens: [ONLINE_SCREEN], withdrawals: [withdrawal(1, { note: `${LONG_NAME} note`, lines: [{ name: LONG_NAME, quantity: 120, unit_label: 'boxes of 50', count_due: true }] })], search_words_waiting: 1 },
      });
      await open(page, server, SUPPLY);
      await page.getByTestId('supply-screen-toggle').click();
      await expect(page.getByTestId('screen-withdrawal')).toHaveCount(1);
      await expect(page.getByTestId('screen-search-words')).toHaveText('1 search word to review in Admin > Medical Supply Room > Supply room screen.');
      await expectTouchTarget(page.getByTestId('supply-screen-toggle'), 'Supply room screen toggle');
      await noHorizontalOverflow(page);
    });
  }
});

test('more taken than the ledger held shows Count due by the on-hand number, in view and Edit mode', async ({ page }) => {
  const server = newServer({ items: [item(1, 'Oral Airway Size 3', 'A1', { on_hand: 0, on_hand_base: 0, count_due: true }), item(2, 'Nasal Airway 28 Fr', 'A1', { on_hand: 2, on_hand_base: 2 })] });
  await open(page, server, SUPPLY);
  await expect(rowById(page, 1).getByTestId('on-hand-number')).toHaveText('0');
  await expect(rowById(page, 1).getByTestId('on-hand-count-due')).toHaveText('Count due');
  await expect(rowById(page, 2).getByTestId('on-hand-count-due')).toHaveCount(0);
  await page.getByTestId('edit-mode').click();
  await expect(editRow(page, 1).getByTestId('edit-count-due')).toHaveText('Count due');
  await expect(editRow(page, 2).getByTestId('edit-count-due')).toHaveCount(0);
});
