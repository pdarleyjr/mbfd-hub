import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import type { EmsMeta, HistoryEvent, RequestLine } from '../../resources/js/daily-checkout/src/components/captain5/ems/types';
import type {
  FormDetail, FormStatus, FormsPreview, MasterItem, PreviewLine, PreviewTotal, ReconcileResult,
} from '../../resources/js/daily-checkout/src/components/captain5/ems/forms/types';

/**
 * Captain 5 Submitted Inventory Forms tab (?tab=rescue), every API response mocked.
 * Contract: docs/ems/captain5-medical-inventory-workflow.md sections 2, 3, 6 and 8.
 */

const KEY_PATTERN = /^[A-Za-z0-9._:-]{8,100}$/;
const ENGINE = '11111111-1111-4111-8111-111111111111';
const LADDER = '22222222-2222-4222-8222-222222222222';
const BOAT = '33333333-3333-4333-8333-333333333333';
const RESCUE = '44444444-4444-4444-8444-444444444444';
const DONE = '55555555-5555-4555-8555-555555555555';
const OLD = '66666666-6666-4666-8666-666666666666';
const REV1 = '77777777-7777-4777-8777-777777777771';
const REV2 = '77777777-7777-4777-8777-777777777772';
const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();

const META: EmsMeta = {
  permissions: { view: true, act: true, configure: false },
  statuses: ['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'],
  target_kinds: ['rescue_unit', 'other_unit', 'detail_event'],
  units: [{ id: 2, kind: 'rescue_unit', label: 'Rescue 2', designation: 'R2', status: 'In Service', group: 'Rescue units' }],
  close_reasons: [],
};

const GAUZE: MasterItem = {
  id: 71, name: 'Gauze 4x4', aliases: ['4x4 sponge'], location_code: 'A2', location_label: 'Shelf A2', base_unit: 'each', count_unit: 'each', units_per_count_unit: 1,
  package_unit: 'box', units_per_package: 200, on_hand_base: 20, counted_at: '2026-10-26T14:00:00Z',
};
const TAPE: MasterItem = { ...GAUZE, id: 73, name: 'Tape 1 inch', aliases: ['cloth tape'], location_code: 'A3', location_label: 'Shelf A3', on_hand_base: 10 };
const SHEARS: MasterItem = { ...GAUZE, id: 99, name: 'Trauma Shears', aliases: ['EMT scissors'], location_code: 'D4', location_label: 'Shelf D4', on_hand_base: 12 };

function makeLine(id: number, label: string, qty: number, extra: Partial<RequestLine> = {}): RequestLine {
  return {
    id, bag_key: 'als-med-bag', item_key: `item.${id}`, label, section_label: 'Trauma', variant_label: null, min_text: null, unit: null, response_type: 'quantity',
    requested_qty: qty, requested_flag: null, prepared_qty: null, issued_qty: 0, line_status: 'open', line_status_label: 'Waiting', not_issued_reason: null,
    refrigerated: false, controlled_substance: false, ...extra,
  };
}

function event(id: number, action: string, payload: Record<string, unknown> = {}): HistoryEvent {
  return {
    id, action, payload, actor_name: 'Pat Medic', actor_rank: 'Paramedic', actor_context: 'member', actor_context_label: 'Member', request_line_id: null,
    from_status: null, to_status: null, created_at: minutesAgo(60),
  };
}

function makeForm(id: string, number: string, target: string, overrides: Partial<FormDetail> = {}): FormDetail {
  const lines = overrides.lines ?? [makeLine(Number(id.slice(0, 1)) * 10 + 1, 'Gauze 4x4', 5), makeLine(Number(id.slice(0, 1)) * 10 + 2, 'Tape 1"', 2)];
  return {
    id, number, status: 'submitted', status_label: 'Submitted', submitted_at: '2026-10-30T18:05:00Z', submitter_name: 'Pat Medic', submitter_rank: 'Paramedic',
    target_kind: 'other_unit', target_label: target, apparatus_id: 9, event_assignment: null, line_count: lines.length, issued_line_count: 0, has_note: true,
    last_activity_at: minutesAgo(5), closed_at: null, acknowledged: false, acknowledged_at: null, acknowledged_by: null, can_reconcile: false, can_reject: true,
    can_acknowledge: true, return_reason: null, returned_at: null, reconciled_at: null, reconciled_by: null, revision_number: 1,
    items_preview: lines.slice(0, 3).map((line) => ({ label: line.label, quantity: line.requested_qty, unit: null })),
    quantity_total: lines.reduce((sum, line) => sum + (line.requested_qty ?? 0), 0), member_note: 'Short on the bag.', lines,
    history: [event(1, 'submitted')], messages: [], close_reason: null, cancel_reason: null, revisions: [], ...overrides,
  };
}

const ack = { acknowledged: true, acknowledged_at: '2026-10-30T19:00:00Z', acknowledged_by: 'Alex Rivera', can_acknowledge: false, can_reconcile: true, status: 'reviewed' as FormStatus, status_label: 'Acknowledged' };

function startingForms(): FormDetail[] {
  return [
    makeForm(ENGINE, 'MIR-000201', 'Engine 1'),
    makeForm(LADDER, 'MIR-000202', 'Ladder 1', { ...ack, lines: [makeLine(21, 'Gauze 4x4', 5)] }),
    makeForm(BOAT, 'MIR-000203', 'Fireboat 1', { ...ack, lines: [makeLine(31, 'Gauze 4x4', 3), makeLine(32, 'Tape 1"', 2)] }),
    makeForm(RESCUE, 'MIR-000204', 'Rescue 2', { target_kind: 'rescue_unit', apparatus_id: 2 }),
    makeForm(DONE, 'MIR-000190', 'Rescue 3', { status: 'reconciled', status_label: 'Reconciled', acknowledged: true, acknowledged_by: 'Alex Rivera', can_acknowledge: false, can_reject: false, reconciled_at: '2026-10-24T14:00:00Z', reconciled_by: 'Alex Rivera', lines: [makeLine(41, 'Gauze 4x4', 4, { issued_qty: 4, line_status: 'issued', line_status_label: 'Issued' })] }),
    makeForm(OLD, 'MIR-000150', 'Engine 2', { status: 'issued', status_label: 'Issued', can_acknowledge: false, can_reject: false, can_reconcile: false, issued_line_count: 1, lines: [makeLine(51, 'Tape 1"', 2, { issued_qty: 2, line_status: 'issued', line_status_label: 'Issued' })] }),
  ];
}

const ACTIVE = ['submitted', 'reviewed', 'prepared', 'returned'];

interface Recorded { method: string; path: string; search: string; key: string | null; body: Record<string, unknown> }

interface Server {
  forms: FormDetail[];
  master: MasterItem[];
  writes: Recorded[];
  reads: Recorded[];
  movement: number;
  staleOnce: boolean;
  abortOnce: boolean;
  gate: Promise<void> | null;
  lowStock: boolean;
}

const newServer = (overrides: Partial<Server> = {}): Server => ({
  forms: startingForms(), master: [GAUZE, TAPE, SHEARS], writes: [], reads: [], movement: 4, staleOnce: false, abortOnce: false, gate: null, lowStock: false, ...overrides,
});

const ITEMS: Record<number, MasterItem> = { 71: GAUZE, 73: TAPE, 99: SHEARS };
const REF = (item: MasterItem) => ({ id: item.id, name: item.name, location_code: item.location_code, count_unit: item.count_unit });

function previewLinesFor(form: FormDetail): PreviewLine[] {
  const common = { request_id: form.id, number: form.number, target_label: form.target_label, variant_label: null, unit: null, message: null };
  if (form.id === ENGINE) {
    return [
      { ...common, line_id: 61, label: 'Trauma shears', requested_qty: 2, quantity: 2, quantity_base: null, state: 'needs_item', item: null },
      { ...common, line_id: 62, label: 'Narcs Box', requested_qty: 1, quantity: 1, quantity_base: null, state: 'no_stock_effect', item: null },
    ];
  }
  return form.lines.map((line) => {
    const item = line.label.startsWith('Tape') ? TAPE : GAUZE;
    return { ...common, line_id: line.id, label: line.label, requested_qty: line.requested_qty, quantity: line.requested_qty ?? 0, quantity_base: line.requested_qty ?? 0, state: 'ready' as const, item: REF(item) };
  });
}

function buildPreview(server: Server, ids: string[]): FormsPreview {
  const forms = server.forms.filter((form) => ids.includes(form.id));
  const lines = forms.flatMap(previewLinesFor);
  const byItem = new Map<number, number>();
  for (const line of lines) if (line.item && line.state === 'ready') byItem.set(line.item.id, (byItem.get(line.item.id) ?? 0) + (line.quantity_base ?? 0));
  const totals: PreviewTotal[] = [...byItem].map(([itemId, deduct]) => {
    const item = ITEMS[itemId];
    const before = itemId === 71 && server.lowStock ? 4 : item.on_hand_base ?? 0;
    return { supply_item_id: itemId, name: item.name, location_code: item.location_code, count_unit: 'each', units_per_count_unit: 1, deduct_base: deduct, before_base: before, after_base: before - deduct, low: before - deduct < 0, not_counted: false };
  });
  return {
    last_movement_id: server.movement,
    blocked: forms.some((form) => !form.can_reconcile),
    forms: forms.map((form) => ({ id: form.id, number: form.number, target_label: form.target_label, submitter_name: form.submitter_name, submitted_at: form.submitted_at, eligible: form.can_reconcile, ineligible_reason: form.can_reconcile ? null : 'It has not been acknowledged.' })),
    lines,
    totals,
  };
}

const summaryOf = (form: FormDetail) => {
  const { lines: _l, history: _h, messages: _m, member_note: _n, close_reason: _c, cancel_reason: _x, revisions: _r, ...summary } = form;
  void _l; void _h; void _m; void _n; void _c; void _x; void _r;
  return summary;
};

const json = (route: Route, body: unknown, status = 200) => route.fulfill({ status, json: body, headers: { 'Cache-Control': 'private, no-store' } });
const ok = (route: Route, data: unknown, meta: Record<string, unknown> = {}, status = 200) => json(route, { ok: true, data, meta }, status);
const fail = (route: Route, status: number, code: string, message: string, fields: Record<string, string[]> = {}) => json(route, { ok: false, error: { code, message, fields } }, status);

async function handleReconcile(route: Route, server: Server, recorded: Recorded): Promise<void> {
  if (server.gate) await server.gate;
  if (server.abortOnce) { server.abortOnce = false; return route.abort('failed'); }
  if (server.staleOnce) {
    server.staleOnce = false;
    server.movement = 9;
    return fail(route, 409, 'stale_preview', 'Stock changed since you opened this preview.');
  }
  const ids = recorded.body.request_ids as string[];
  const decisions = (recorded.body.decisions ?? []) as Array<{ line_id: number; quantity?: number }>;
  const forms = server.forms.filter((form) => ids.includes(form.id));
  let items = 0;
  for (const line of forms.flatMap(previewLinesFor)) {
    if (line.state !== 'ready') continue;
    const typed = decisions.find((decision) => decision.line_id === line.line_id)?.quantity;
    items += typed ?? line.quantity;
  }
  for (const form of forms) Object.assign(form, { status: 'reconciled', status_label: 'Reconciled', can_reconcile: false, can_reject: false, can_acknowledge: false, reconciled_at: new Date().toISOString(), reconciled_by: 'Alex Rivera' });
  const result: ReconcileResult = {
    batch: 7, reconciled: forms.map((form) => ({ id: form.id, number: form.number })), movement_count: forms.length,
    deducted: [{ supply_item_id: 71, name: 'Gauze 4x4', deduct_base: items }],
  };
  return ok(route, result, {}, 201);
}

async function handleEms(route: Route, server: Server, url: URL): Promise<void> {
  const method = route.request().method();
  const path = url.pathname.replace('/ems/api', '');
  const text = route.request().postData();
  const recorded: Recorded = { method, path, search: url.search, key: route.request().headers()['idempotency-key'] ?? null, body: text ? JSON.parse(text) as Record<string, unknown> : {} };
  if (method === 'GET') server.reads.push(recorded); else server.writes.push(recorded);

  if (path === '/meta') return ok(route, META);
  if (path === '/requests' && method === 'GET') {
    const state = url.searchParams.get('state');
    const legacy = (url.searchParams.get('status') ?? '').split(',').filter(Boolean);
    const q = (url.searchParams.get('q') ?? '').toLowerCase();
    const rows = server.forms.filter((form) => (state === 'active' ? ACTIVE.includes(form.status) : state === 'archived' ? !ACTIVE.includes(form.status) : legacy.length === 0 || legacy.includes(form.status))
      && (!q || `${form.number} ${form.submitter_name} ${form.target_label}`.toLowerCase().includes(q)));
    const perPage = Number(url.searchParams.get('per_page') ?? 25);
    return ok(route, rows.slice(0, perPage).map(summaryOf), { page: 1, per_page: perPage, total: rows.length, last_page: Math.max(1, Math.ceil(rows.length / perPage)) });
  }
  const one = /^\/requests\/([0-9a-f-]{36})(?:\/([a-z-]+))?$/.exec(path);
  if (one) {
    const found = server.forms.find((form) => form.id === one[1]);
    if (!found) return fail(route, 404, 'not_found', 'That form could not be found.');
    if (method === 'GET') return ok(route, found);
    if (one[2] === 'acknowledge') Object.assign(found, ack, { status_label: 'Reviewed' }, { history: [...found.history, event(90, 'reviewed')] });
    if (one[2] === 'reject') {
      Object.assign(found, { status: 'returned', status_label: 'Returned', acknowledged: false, can_acknowledge: false, can_reject: false, can_reconcile: false, return_reason: String(recorded.body.reason), returned_at: new Date().toISOString() });
    }
    return ok(route, found, { replayed: false });
  }
  if (path === '/forms/reconcile/preview') return ok(route, buildPreview(server, (url.searchParams.get('ids') ?? '').split(',').filter(Boolean)));
  if (path === '/forms/reconcile' && method === 'POST') return handleReconcile(route, server, recorded);
  if (path === '/supply/master') return ok(route, { sections: [], items: server.master, inactive: [], draft: null, last_count: null, can_edit: false });
  return fail(route, 404, 'not_found', `Unmocked route ${path}`);
}

async function mockApi(page: Page, server: Server): Promise<void> {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/ems/api/')) return handleEms(route, server, url);
    if (url.pathname === '/api/me/context') {
      return route.fulfill({ json: { version: 1, identity: { user_id: 401, has_personnel_profile: true }, personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' }, offline: { security_version: 1 }, session: { authenticated: true }, authorization: { capabilities: { command_300_access: false } } } });
    }
    if (url.pathname === '/api/captain5/workspace') {
      const duty = { shift: 'B', source: 'bid_holder', resolved: true, name: 'Alex Rivera', rank: 'Capt.', acting_until: null, acting_request_id: null };
      return route.fulfill({
        json: {
          access: { view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)' }, on_duty: { duty_date: '2026-10-30', ...duty }, duty_date: '2026-10-30',
          counts: { open: 0, needs_acknowledgement: 0, ordered: 0, ready_for_pickup: 0, overdue: 0, awaiting_verification: 0, significant: 0, rescue: 0, medical: 0 },
          rescue_dailies: [], issues: [], handoff: { duty_date: '2026-10-30', notes: [] }, acting: { can_approve: false, mine: null, pending: [], choices: { '12h': null, '24h': null } }, generated_at: new Date().toISOString(),
        },
      });
    }
    if (url.pathname === '/api/captain5/communications/summary') return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    if (url.pathname.startsWith('/api/captain5/') || url.pathname.startsWith('/api/command-communications/')) return route.fulfill({ json: { unread_count: 0, data: [], pinned: [], next_before: null, issues: [] } });
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${url.pathname}` } });
  });
}

async function open(page: Page, server: Server, path = '/daily/captain-5?tab=rescue'): Promise<void> {
  await mockApi(page, server);
  await page.goto(path);
  await expect(page.getByTestId('ems-forms-tab')).toBeVisible();
}

/** Set CAPTAIN5_SHOTS_DIR to keep screenshots of the key screens for review. */
async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.CAPTAIN5_SHOTS_DIR;
  if (dir) await page.screenshot({ path: `${dir}/${name}.png`, fullPage: false });
}

const card = (page: Page, text: string): Locator => page.getByTestId('ems-form-card').filter({ hasText: text });
const reconcileWrites = (server: Server) => server.writes.filter((write) => write.path === '/forms/reconcile');
const previewReads = (server: Server) => server.reads.filter((read) => read.path === '/forms/reconcile/preview');

async function noHorizontalOverflow(page: Page): Promise<void> {
  const widths = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function selectReady(page: Page): Promise<void> {
  await page.getByRole('checkbox', { name: /Select Ladder 1 form/ }).check();
  await page.getByRole('checkbox', { name: /Select Fireboat 1 form/ }).check();
}

async function openReconcile(page: Page): Promise<Locator> {
  await selectReady(page);
  await page.getByTestId('ems-reconcile-open').click();
  const sheet = page.getByTestId('ems-reconcile-sheet');
  await expect(sheet.getByTestId('ems-reconcile-body')).toBeVisible();
  return sheet;
}

const gaugeInput = (sheet: Locator, line: number) => sheet.locator(`[data-testid="ems-reconcile-line"][data-line-id="${line}"] input`);

// ---------------------------------------------------------------------------- the list

test.describe('active forms', () => {
  test('lists engine, ladder and fireboat forms as well as rescue, each with member, time, items and acknowledgment', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    const cards = page.getByTestId('ems-form-card');
    await expect(cards).toHaveCount(4);
    for (const unit of ['Engine 1', 'Ladder 1', 'Fireboat 1', 'Rescue 2']) await expect(card(page, unit)).toBeVisible();
    await expect(card(page, 'Engine 1')).toContainText('Paramedic Pat Medic');
    await expect(card(page, 'Engine 1')).toContainText('Submitted Oct 30, 2:05 PM EDT');
    await expect(card(page, 'Engine 1')).toContainText('Gauze 4x4 (5), Tape 1" (2)');
    await expect(card(page, 'Engine 1')).toContainText('7 in all');
    await expect(card(page, 'Engine 1')).toContainText('Waiting for acknowledgment');
    await expect(card(page, 'Ladder 1')).toContainText('Acknowledged by Alex Rivera, Oct 30, 3:00 PM EDT');
    await expect(page.getByRole('button', { name: 'Active', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const list = server.reads.find((read) => read.path === '/requests' && read.search.includes('state=active'));
    expect(list).toBeTruthy();
    // Active has no date filters and no search box.
    await expect(page.getByLabel('From date')).toHaveCount(0);
    await expect(page.getByLabel('Search archived forms')).toHaveCount(0);
  });

  test('an unacknowledged form shows a disabled tick box with the hint, and an acknowledged one can be ticked', async ({ page }) => {
    await open(page, newServer());
    const engine = card(page, 'Engine 1');
    await expect(engine.getByRole('checkbox')).toBeDisabled();
    await expect(engine).toContainText('Acknowledge first');
    await expect(card(page, 'Ladder 1').getByRole('checkbox')).toBeEnabled();
  });

  test('only Acknowledge, Reject and Reconcile exist: no Prepare, Issue, Close, Reopen or Correct', async ({ page }) => {
    await open(page, newServer());
    const tab = page.getByTestId('ems-forms-tab');
    await expect(tab.getByRole('button', { name: /^(Prepare|Issue|Close|Reopen|Correct|Mark not issued)/i })).toHaveCount(0);
    await expect(tab.getByRole('button', { name: /^Acknowledge/ }).first()).toBeVisible();
    await expect(tab.getByRole('button', { name: /^Reject/ }).first()).toBeVisible();
    await page.getByTestId('ems-form-open').first().click();
    const sheet = page.getByTestId('ems-form-sheet');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('button', { name: /^(Prepare|Issue|Reopen|Correct|Mark not issued)/i })).toHaveCount(0);
  });

  test('Acknowledge keeps the form in Active, shows who and when, and sends one keyed POST', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    const engine = card(page, 'Engine 1');
    await engine.getByRole('button', { name: /^Acknowledge/ }).click();
    await expect(engine).toContainText('Acknowledged by Alex Rivera');
    await expect(engine.getByRole('checkbox')).toBeEnabled();
    await expect(engine.getByRole('button', { name: /^Acknowledge/ })).toHaveCount(0);
    await expect(page.getByTestId('ems-form-card')).toHaveCount(4);
    const writes = server.writes.filter((write) => write.path === `/requests/${ENGINE}/acknowledge`);
    expect(writes).toHaveLength(1);
    expect(writes[0].key).toMatch(KEY_PATTERN);
  });

  test('Reject needs a reason, then the form shows as returned with no actions and stays in Active', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await card(page, 'Rescue 2').getByRole('button', { name: /^Reject/ }).click();
    const dialog = page.getByRole('dialog', { name: 'Send this back to Pat Medic to correct' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Send back' }).click();
    await expect(dialog.getByRole('alert').filter({ hasText: 'Say what needs to be fixed.' })).toBeVisible();
    expect(server.writes.filter((write) => write.path.endsWith('/reject'))).toHaveLength(0);
    await dialog.getByLabel(/What needs to be fixed/).fill('ab');
    await dialog.getByRole('button', { name: 'Send back' }).click();
    await expect(dialog.getByText('Write at least 3 characters.')).toBeVisible();
    await dialog.getByLabel(/What needs to be fixed/).fill('Recount the tape');
    await dialog.getByRole('button', { name: 'Send back' }).click();
    await expect(dialog).toHaveCount(0);
    const returned = card(page, 'Rescue 2');
    await expect(returned).toContainText('Returned to Pat Medic: Recount the tape');
    await expect(returned).toHaveAttribute('data-status', 'returned');
    await expect(returned.getByRole('button', { name: /^(Acknowledge|Reject)/ })).toHaveCount(0);
    await expect(returned.getByRole('checkbox')).toHaveCount(0);
    const write = server.writes.find((entry) => entry.path === `/requests/${RESCUE}/reject`);
    expect(write?.body).toEqual({ reason: 'Recount the tape' });
    expect(write?.key).toMatch(KEY_PATTERN);
  });

  test('Select all ready ticks every acknowledged form and the bar counts them', async ({ page }) => {
    await open(page, newServer());
    await expect(page.getByTestId('ems-selection-bar')).toHaveCount(0);
    await page.getByTestId('ems-select-all-ready').click();
    await expect(page.getByTestId('ems-selection-count')).toHaveText('2 forms selected');
    await expect(page.getByRole('button', { name: 'Reconcile with Supply Room Inventory' })).toBeVisible();
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(page.getByTestId('ems-selection-bar')).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------- reconcile

test.describe('reconcile', () => {
  test('two selected forms are confirmed once, deducted, announced, and move to Archived', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    const sheet = await openReconcile(page);
    await expect(sheet.getByTestId('ems-reconcile-forms')).toContainText('Ladder 1');
    await expect(sheet.getByTestId('ems-reconcile-forms')).toContainText('Fireboat 1');
    const gauze = sheet.locator('[data-testid="ems-reconcile-item"][data-item-id="71"]');
    await expect(gauze).toContainText('Gauze 4x4');
    await expect(gauze).toContainText('Shelf A2');
    await expect(gauze.getByTestId('ems-reconcile-take')).toHaveText('Take 8');
    await expect(sheet.locator('[data-testid="ems-reconcile-item"][data-item-id="73"]').getByTestId('ems-reconcile-take')).toHaveText('Take 2');
    await expect(sheet.getByTestId('ems-reconcile-low')).toHaveCount(0);
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toContainText('Reconciled 2 forms. 10 items came off the shelf.');
    await expect(page.getByTestId('ems-forms-announce')).toContainText('Reconciled 2 forms.');
    await expect(sheet).toHaveCount(0);
    await expect(page.getByTestId('ems-form-card')).toHaveCount(2);
    await expect(page.getByTestId('ems-selection-bar')).toHaveCount(0);
    const writes = reconcileWrites(server);
    expect(writes).toHaveLength(1);
    expect(writes[0].key).toMatch(KEY_PATTERN);
    expect((writes[0].body.request_ids as string[]).sort()).toEqual([BOAT, LADDER].sort());
    expect(writes[0].body.expected_last_movement_id).toBe(4);
    expect(writes[0].body.accept_low_stock).toBeUndefined();
    expect(writes[0].body.decisions).toBeUndefined();
  });

  test('a typed quantity of what was actually taken is sent as a decision and changes the total', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    const sheet = await openReconcile(page);
    const field = gaugeInput(sheet, 21);
    await expect(field).toHaveValue('5');
    await field.fill('2');
    await expect(sheet.locator('[data-testid="ems-reconcile-item"][data-item-id="71"]').getByTestId('ems-reconcile-take')).toHaveText('Take 5');
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    expect(reconcileWrites(server)[0].body.decisions).toEqual([{ line_id: 21, quantity: 2 }]);
  });

  test('a double click on Confirm sends one POST', async ({ page }) => {
    let release!: () => void;
    const gate = new Promise<void>((resolve) => { release = resolve; });
    const server = newServer({ gate });
    await open(page, server);
    const sheet = await openReconcile(page);
    await sheet.getByTestId('ems-reconcile-confirm').dblclick();
    await expect.poll(() => reconcileWrites(server).length).toBe(1);
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toBeDisabled();
    await page.waitForTimeout(250);
    expect(reconcileWrites(server)).toHaveLength(1);
    release();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    expect(reconcileWrites(server)).toHaveLength(1);
  });

  test('a lost connection keeps the sheet and Retry sends the same Idempotency-Key', async ({ page }) => {
    const server = newServer({ abortOnce: true });
    await open(page, server);
    const sheet = await openReconcile(page);
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(sheet.getByRole('alert').filter({ hasText: 'could not be reached' })).toBeVisible();
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toHaveText('Retry: Confirm and reconcile');
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    const writes = reconcileWrites(server);
    expect(writes).toHaveLength(2);
    expect(writes[0].key).toBe(writes[1].key);
  });

  test('a line with no supply item blocks Confirm until the captain picks one, and remembers the choice in the POST', async ({ page }) => {
    const server = newServer();
    server.forms[0] = { ...server.forms[0], ...ack };
    await open(page, server);
    await page.getByRole('checkbox', { name: /Select Engine 1 form/ }).check();
    await page.getByTestId('ems-reconcile-open').click();
    const sheet = page.getByTestId('ems-reconcile-sheet');
    const confirm = sheet.getByTestId('ems-reconcile-confirm');
    await expect(sheet.getByRole('heading', { name: 'Needs your answer' })).toBeVisible();
    await expect(confirm).toBeDisabled();
    await expect(confirm).toHaveText('Answer 1 more line to continue');
    await expect(sheet.getByTestId('ems-reconcile-quiet')).toContainText('Narcs Box');
    await expect(sheet.getByTestId('ems-reconcile-quiet')).toContainText('No stock change');

    await sheet.getByLabel(/Which supply room item is/).fill('scissors');
    await expect(sheet.getByTestId('ems-picker-result')).toHaveCount(1);
    await sheet.getByTestId('ems-picker-result').click();
    await expect(sheet.getByTestId('ems-picker-chosen')).toContainText('Using Trauma Shears (Shelf D4)');
    await expect(confirm).toBeEnabled();
    await expect(confirm).toHaveText('Confirm and reconcile');
    await expect(sheet.locator('[data-testid="ems-reconcile-item"][data-item-id="99"]').getByTestId('ems-reconcile-take')).toHaveText('Take 2');

    await sheet.getByRole('button', { name: 'Change' }).click();
    await expect(confirm).toBeDisabled();
    await sheet.getByTestId('ems-picker-none').click();
    await expect(sheet.getByTestId('ems-picker-chosen')).toHaveText('Not a supply room item');
    await expect(confirm).toBeEnabled();
    await confirm.click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    expect(reconcileWrites(server)[0].body.decisions).toEqual([{ line_id: 61, not_stocked: true }]);
    expect(server.reads.filter((read) => read.path === '/supply/master')).toHaveLength(1);
  });

  test('a picked item goes in the POST with its quantity', async ({ page }) => {
    const server = newServer();
    server.forms[0] = { ...server.forms[0], ...ack };
    await open(page, server);
    await page.getByRole('checkbox', { name: /Select Engine 1 form/ }).check();
    await page.getByTestId('ems-reconcile-open').click();
    const sheet = page.getByTestId('ems-reconcile-sheet');
    await sheet.getByLabel(/Which supply room item is/).fill('shears');
    await sheet.getByTestId('ems-picker-result').click();
    await sheet.getByLabel(/Quantity taken for Trauma shears/).fill('1');
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    expect(reconcileWrites(server)[0].body.decisions).toEqual([{ line_id: 61, supply_item_id: 99, quantity: 1 }]);
  });

  test('stale_preview reloads the preview, keeps what was typed, and the next confirm uses the new stock number and a new key', async ({ page }) => {
    const server = newServer({ staleOnce: true });
    await open(page, server);
    const sheet = await openReconcile(page);
    await gaugeInput(sheet, 21).fill('2');
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(sheet.getByTestId('ems-reconcile-note')).toContainText('Stock changed while this was open');
    await expect.poll(() => previewReads(server).length).toBe(2);
    await expect(gaugeInput(sheet, 21)).toHaveValue('2');
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toBeEnabled();
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    const writes = reconcileWrites(server);
    expect(writes).toHaveLength(2);
    expect(writes[0].body.expected_last_movement_id).toBe(4);
    expect(writes[1].body.expected_last_movement_id).toBe(9);
    expect(writes[1].body.decisions).toEqual([{ line_id: 21, quantity: 2 }]);
    expect(writes[1].key).not.toBe(writes[0].key);
  });

  test('stock that would go below zero is shown in red, explained, and accepted by Confirm', async ({ page }) => {
    const server = newServer({ lowStock: true });
    await open(page, server);
    const sheet = await openReconcile(page);
    const low = sheet.getByTestId('ems-reconcile-low');
    await expect(low).toHaveCount(1);
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toBeEnabled();
    await shot(page, 'forms-desktop-reconcile-low');
    await expect(low).toContainText("This leaves -4 on the books. Monday's count will correct it.");
    await sheet.getByTestId('ems-reconcile-confirm').click();
    await expect(page.getByTestId('ems-done-notice')).toBeVisible();
    expect(reconcileWrites(server)[0].body.accept_low_stock).toBe(true);
  });
});

// ---------------------------------------------------------------------------- archived and detail

test.describe('archived forms and the detail sheet', () => {
  test('Archived lists reconciled and older forms, searchable, with no actions', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await page.getByRole('button', { name: 'Archived', exact: true }).click();
    await expect(page.getByTestId('ems-form-card')).toHaveCount(2);
    const done = card(page, 'Rescue 3');
    await expect(done).toContainText('Reconciled by Alex Rivera, Oct 24, 10:00 AM EDT');
    await expect(done.getByRole('button', { name: /^(Acknowledge|Reject)/ })).toHaveCount(0);
    await expect(done.getByRole('checkbox')).toHaveCount(0);
    await page.getByLabel('Search archived forms').fill('engine 2');
    await expect(page.getByTestId('ems-form-card')).toHaveCount(1);
    await expect(card(page, 'Engine 2')).toBeVisible();
    await expect.poll(() => server.reads.filter((read) => read.search.includes('state=archived')).pop()?.search ?? '').toContain('q=engine+2');
    await page.getByLabel('Search archived forms').fill('zzz');
    await expect(page.getByText('No archived forms match this search')).toBeVisible();
  });

  test('an older record issued before this workflow shows no actions anywhere', async ({ page }) => {
    await open(page, newServer());
    await page.getByRole('button', { name: 'Archived', exact: true }).click();
    const older = card(page, 'Engine 2');
    await expect(older).toContainText('Older record: Issued');
    await expect(older.getByRole('button', { name: /^(Acknowledge|Reject)/ })).toHaveCount(0);
    await older.getByTestId('ems-form-open').click();
    const sheet = page.getByTestId('ems-form-sheet');
    await expect(sheet.getByRole('heading', { name: /MIR-000150: Engine 2/ })).toBeVisible();
    await expect(sheet.getByText('Read only')).toBeVisible();
    await expect(sheet.getByRole('button', { name: /^(Acknowledge|Reject|Prepare|Issue|Reopen|Correct)/ })).toHaveCount(0);
    await expect(sheet.getByTestId('ems-form-line')).toContainText('Issued');
  });

  test('a deep link opens the form with its items, history and collapsed messages; Escape closes it', async ({ page }) => {
    await open(page, newServer(), `/daily/captain-5?tab=rescue&request=${LADDER}`);
    const sheet = page.getByTestId('ems-form-sheet');
    await expect(sheet.getByRole('heading', { name: /MIR-000202: Ladder 1/ })).toBeVisible();
    await expect(sheet.getByTestId('ems-form-line')).toContainText('Gauze 4x4');
    await expect(sheet.getByTestId('ems-form-state')).toContainText('Acknowledged by Alex Rivera');
    await expect(sheet.getByTestId('ems-form-history-item').first()).toContainText('Submitted the form.');
    await expect(sheet.getByTestId('ems-form-messages')).not.toHaveAttribute('open', '');
    await sheet.getByText('Messages (0)').click();
    await expect(sheet.getByTestId('ems-thread')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
    await expect(page).not.toHaveURL(/request=/);
  });

  test('Acknowledge from the detail sheet updates the list behind it', async ({ page }) => {
    const server = newServer();
    await open(page, server, `/daily/captain-5?tab=rescue&request=${RESCUE}`);
    const sheet = page.getByTestId('ems-form-sheet');
    await sheet.getByRole('button', { name: /^Acknowledge/ }).click();
    await expect(sheet.getByTestId('ems-form-notice')).toContainText('Acknowledged.');
    await expect(sheet.getByTestId('ems-form-state')).toContainText('Acknowledged by Alex Rivera');
    expect(server.writes.filter((write) => write.path === `/requests/${RESCUE}/acknowledge`)).toHaveLength(1);
  });

  test('a corrected form shows its versions and opens the earlier one read-only', async ({ page }) => {
    const server = newServer({
      forms: [
        makeForm(REV1, 'MIR-000300', 'Engine 3', { status: 'superseded', status_label: 'Replaced', can_acknowledge: false, can_reject: false, revisions: [], return_reason: 'Count was wrong' }),
        makeForm(REV2, 'MIR-000300-R2', 'Engine 3', { revision_number: 2 }),
      ],
    });
    for (const form of server.forms) {
      form.revisions = [
        { id: REV1, number: 'MIR-000300', status: 'superseded', status_label: 'Replaced', submitted_at: '2026-10-29T12:00:00Z', is_current: form.id === REV1 },
        { id: REV2, number: 'MIR-000300-R2', status: 'submitted', status_label: 'Submitted', submitted_at: '2026-10-30T12:00:00Z', is_current: form.id === REV2 },
      ];
    }
    await open(page, server, `/daily/captain-5?tab=rescue&request=${REV2}`);
    await expect(card(page, 'Engine 3')).toContainText('Revision 2');
    const sheet = page.getByTestId('ems-form-sheet');
    await expect(sheet.getByTestId('ems-form-revisions')).toContainText('MIR-000300-R2');
    await sheet.getByRole('button', { name: 'Open MIR-000300', exact: true }).click();
    await expect(sheet.getByRole('heading', { name: /MIR-000300: Engine 3/ })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`request=${REV1}`));
    await expect(sheet.getByRole('button', { name: /^(Acknowledge|Reject)/ })).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------- phone

test.describe('phone layout at 360px', () => {
  test.use({ viewport: { width: 360, height: 800 }, hasTouch: true });

  async function targetsAreBigEnough(scope: Locator): Promise<void> {
    const small = await scope.locator('button:visible, input:visible, summary:visible, select:visible').evaluateAll((elements) => (
      elements
        .map((element) => ({ name: (element.getAttribute('aria-label') ?? element.textContent ?? element.tagName).trim().slice(0, 40), box: element.getBoundingClientRect() }))
        .filter(({ box }) => box.height < 43.5 || box.width < 43.5)
        .map(({ name, box }) => `${name} ${Math.round(box.width)}x${Math.round(box.height)}`)
    ));
    expect(small).toEqual([]);
  }

  test('the list, the selection bar, the reconcile sheet and the detail sheet never scroll sideways and every control is 44px', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await expect(page.getByTestId('ems-form-card')).toHaveCount(4);
    await noHorizontalOverflow(page);
    await shot(page, 'forms-phone-list');
    await targetsAreBigEnough(page.getByTestId('ems-forms-tab'));

    await selectReady(page);
    const bar = page.getByTestId('ems-selection-bar');
    await expect(bar).toBeVisible();
    await shot(page, 'forms-phone-bar');
    await noHorizontalOverflow(page);
    await targetsAreBigEnough(bar);
    const barBox = await bar.boundingBox();
    const viewport = page.viewportSize();
    expect(barBox && viewport && barBox.y + barBox.height <= viewport.height + 1).toBeTruthy();
    // The member navigation is fixed to the bottom on phones: the bar rests on top of it, never under it.
    const nav = page.locator('.hub-member-nav, .hub-member-navigation').first();
    if (await nav.isVisible()) {
      const navBox = await nav.boundingBox();
      expect(barBox && navBox && barBox.y + barBox.height <= navBox.y + 1).toBeTruthy();
    }

    // The last card is reachable: scrolled to the end, the bar sits below it, not over it.
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    const last = await page.getByTestId('ems-form-card').last().boundingBox();
    const dock = await bar.boundingBox();
    expect(last && dock && last.y + last.height <= dock.y + 1).toBeTruthy();

    await page.getByTestId('ems-reconcile-open').click();
    const sheet = page.getByTestId('ems-reconcile-sheet');
    await expect(sheet.getByTestId('ems-reconcile-body')).toBeVisible();
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toBeEnabled();
    await shot(page, 'forms-phone-reconcile');
    await noHorizontalOverflow(page);
    await targetsAreBigEnough(sheet);
    await expect(sheet.getByTestId('ems-reconcile-confirm')).toBeInViewport();
    await page.keyboard.press('Escape');

    await page.getByTestId('ems-form-open').first().click();
    const detail = page.getByTestId('ems-form-sheet');
    await expect(detail).toBeVisible();
    await shot(page, 'forms-phone-detail');
    await noHorizontalOverflow(page);
    await targetsAreBigEnough(detail);
  });

  test('the picker and the Send back sheet fit the phone too', async ({ page }) => {
    const server = newServer();
    server.forms[0] = { ...server.forms[0], ...ack };
    await open(page, server);
    await card(page, 'Rescue 2').getByRole('button', { name: /^Reject/ }).click();
    const dialog = page.getByTestId('ems-reject-sheet');
    await expect(dialog).toBeVisible();
    await noHorizontalOverflow(page);
    await targetsAreBigEnough(dialog);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);

    await page.getByRole('checkbox', { name: /Select Engine 1 form/ }).check();
    await page.getByTestId('ems-reconcile-open').click();
    const sheet = page.getByTestId('ems-reconcile-sheet');
    await sheet.getByLabel(/Which supply room item is/).fill('a');
    await expect(sheet.getByTestId('ems-picker-result').first()).toBeVisible();
    await noHorizontalOverflow(page);
    await targetsAreBigEnough(sheet);
  });
});
