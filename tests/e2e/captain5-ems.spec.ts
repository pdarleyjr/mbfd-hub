import { expect, test, type Page, type Route } from '@playwright/test';
import type {
  Advisories, CountItem, EmsMeta, HistoryEvent, Movement, ReconcilePreview, RequestDetail, RequestLine, SnapshotDetail, SnapshotSummary,
  StatisticsReport, SupplyItem, SupplyOrder,
} from '../../resources/js/daily-checkout/src/components/captain5/ems/types';

/**
 * Captain 5 Rescue Inventory and Supply Room tabs, every API response mocked.
 * Contract: /ems/api/* (docs/ems/ems-inventory-contract.md section 9.3) beside the existing /api/captain5/* shell calls.
 */

const KEY_PATTERN = /^[A-Za-z0-9._:-]{8,100}$/;
const R1 = '11111111-1111-4111-8111-111111111111';
const R2 = '22222222-2222-4222-8222-222222222222';
const R3 = '33333333-3333-4333-8333-333333333333';
const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();

const PERMS_ACT = { view: true, act: true, configure: false };
const PERMS_ADMIN = { view: true, act: true, configure: true };
const PERMS_VIEW = { view: true, act: false, configure: false };

const UNITS: EmsMeta['units'] = [
  { id: 2, kind: 'rescue_unit', label: 'Rescue 2', designation: 'R2', status: 'In Service', group: 'Rescue units' },
  { id: 3, kind: 'rescue_unit', label: 'Rescue 3', designation: 'R3', status: 'In Service', group: 'Rescue units' },
  { id: 9, kind: 'other_unit', label: 'Engine 1', designation: 'E1', status: 'In Service', group: 'Other units' },
  { id: null, kind: 'detail_event', label: 'Detail / Special Event', designation: null, status: null, group: 'Details' },
];

const metaFor = (permissions: EmsMeta['permissions']): EmsMeta => ({
  permissions,
  statuses: ['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'],
  target_kinds: ['rescue_unit', 'other_unit', 'detail_event'],
  units: UNITS,
  close_reasons: ['Issued in full', 'No action required', 'Not stocked', 'Duplicate of another request'],
});

const STATUS_LABEL: Record<string, string> = { submitted: 'Submitted', reviewed: 'Reviewed', prepared: 'Prepared', partially_issued: 'Partly issued', issued: 'Issued', closed: 'Closed', cancelled: 'Cancelled' };
const LINE_LABEL: Record<string, string> = { open: 'Waiting', prepared: 'Prepared', partially_issued: 'Partly issued', issued: 'Issued', not_issued: 'Not issued' };

function makeLine(id: number, overrides: Partial<RequestLine> = {}): RequestLine {
  return {
    id, bag_key: 'als-med-bag', item_key: `item.${id}`, label: `Item ${id}`, section_label: 'Trauma', variant_label: null, min_text: null, unit: null,
    response_type: 'quantity', requested_qty: 4, requested_flag: null, prepared_qty: null, issued_qty: 0, line_status: 'open', line_status_label: 'Waiting',
    not_issued_reason: null, refrigerated: false, controlled_substance: false, catalog_item_id: id, supply_candidates: [], ...overrides,
  };
}

const gauzeLine = makeLine(1, {
  label: 'Gauze 4x4', min_text: '10 EACH', requested_qty: 10,
  supply_candidates: [
    { id: 71, name: 'Gauze 4x4', location_code: 'A2', package_unit: 'box', units_per_package: 200 },
    { id: 72, name: 'Gauze 4x4 Sterile', location_code: 'A3', package_unit: 'box', units_per_package: 50 },
  ],
});
const tapeLine = makeLine(2, { label: 'Tape 1"', requested_qty: 2, supply_candidates: [{ id: 73, name: 'Tape 1 inch', location_code: 'A2', package_unit: 'box', units_per_package: 24 }] });
const stethLine = makeLine(3, { label: 'Stethoscope', bag_key: 'airway-bag', section_label: 'Top Compartment', requested_qty: 1, supply_candidates: [] });
const narcsLine = makeLine(4, { label: 'Narcs Box', section_label: 'Rescue Only', response_type: 'yes_no', requested_qty: null, requested_flag: true, controlled_substance: true });
const cardizemLine = makeLine(5, { label: 'Cardizem', section_label: 'Rescue Only', requested_qty: 1, refrigerated: true });

function event(id: number, action: string, payload: Record<string, unknown> = {}, extra: Partial<HistoryEvent> = {}): HistoryEvent {
  return {
    id, action, payload, actor_name: 'Alex Rivera', actor_rank: 'Capt.', actor_context: 'captain5', actor_context_label: 'Captain 5', request_line_id: null,
    from_status: null, to_status: null, created_at: minutesAgo(30), ...extra,
  };
}

function makeRequest(uuid: string, number: string, overrides: Partial<RequestDetail> = {}): RequestDetail {
  return {
    id: uuid, number, status: 'submitted', status_label: 'Submitted', submitted_at: '2026-10-23T18:05:00Z', submitter_name: 'Pat Medic', submitter_rank: 'Paramedic',
    target_kind: 'rescue_unit', target_label: 'Rescue 2', apparatus_id: 2, event_assignment: null, line_count: 5, issued_line_count: 0, has_note: true,
    last_activity_at: minutesAgo(5), closed_at: null, member_note: 'Short on the airway bag.',
    lines: [gauzeLine, tapeLine, stethLine, narcsLine, cardizemLine].map((line) => ({ ...line, supply_candidates: line.supply_candidates?.map((c) => ({ ...c })) })),
    history: [event(1, 'submitted', {}, { actor_name: 'Pat Medic', actor_rank: 'Paramedic', actor_context: 'member', actor_context_label: 'Member', to_status: 'submitted' })],
    messages: [], close_reason: null, cancel_reason: null, ...overrides,
  };
}

const supplyItem = (id: number, overrides: Partial<SupplyItem> = {}): SupplyItem => ({
  id, catalog_item_id: id, name: `Supply ${id}`, bag_alias_text: null, location_code: 'A2', section_key: 'supplies', base_unit: 'each', package_unit: 'box', units_per_package: 24,
  packages_per_case: null, qty_per_package_text: null, threshold_qty_base: 12, threshold_source_text: '< 12U', order_amount_text: '2BX = 48U', order_qty_packages: 2,
  suppliers: [{ code: 'BT', raw: 'BT 1.00EA 6/22' }], item_numbers: ['100-A'], suppliers_note: 'Supplier names, prices and price dates are historical (2021 to 2023) and unverified.',
  refrigerated: false, controlled_substance: false, has_expiration: null, needs_review: false, review_reasons: [], active: true, tracks_stock: true, controlled_note: null,
  current_quantity: 30, current_basis: 'counted', current_basis_label: 'Counted Oct 20', current_basis_at: '2026-10-20T14:00:00Z', movements_since_basis: 0, below_threshold: false, ...overrides,
});

const STOCK: SupplyItem[] = [
  supplyItem(73, { name: 'Tape 1 inch' }),
  supplyItem(71, { name: 'Gauze 4x4', current_quantity: 8, threshold_qty_base: 50, threshold_source_text: '< 50U', below_threshold: true, current_basis: 'calculated', current_basis_label: 'Calculated since count Oct 20' }),
  supplyItem(74, { name: 'Gloves Medium', location_code: 'C1', package_unit: 'case', units_per_package: null, needs_review: true, review_reasons: ['unknown_conversion'], threshold_qty_base: null, current_quantity: null, current_basis: 'none', current_basis_label: 'Not counted yet', below_threshold: null }),
  supplyItem(75, { name: 'Cardizem 25mg', location_code: 'D1', refrigerated: true }),
  supplyItem(76, { name: 'Fentanyl (Narcotics Locker)', location_code: 'LOCK', controlled_substance: true, tracks_stock: false, current_quantity: null, current_basis: null, current_basis_label: null, below_threshold: null, controlled_note: 'Narcotics are restocked through the department controlled-substance procedure and are not tracked here.' }),
];

const countItems = (): CountItem[] => [
  { id: 73, name: 'Tape 1 inch', location_code: 'A2', package_unit: 'box', units_per_package: 24, packages_per_case: null, entry: null, quantity_base: null, warnings: [] },
  { id: 74, name: 'Gloves Medium', location_code: 'A2', package_unit: 'case', units_per_package: null, packages_per_case: null, entry: null, quantity_base: null, warnings: [] },
];

const snapshotSummary = (id: string, overrides: Partial<SnapshotSummary> = {}): SnapshotSummary => ({
  id, kind: 'physical_count', kind_label: 'Physical count', status: 'final', version: 3, scope_location_code: null, started_at: '2026-10-20T13:00:00Z', started_by: 'Alex Rivera',
  finalized_at: '2026-10-20T14:00:00Z', finalized_name: 'Alex Rivera', cutoff_at: '2026-10-20T14:00:00Z', through_movement_id: 4, previous_snapshot_id: null, notes: null,
  line_count: 5, counted_count: 4, ...overrides,
});

const preview = (lastMovement: number): ReconcilePreview => ({
  previous_snapshot: snapshotSummary('s-prev'),
  last_movement_id: lastMovement,
  idempotency_key: `recon:${lastMovement}`,
  nothing_to_reconcile: false,
  summary: { items: 2, without_previous_count: 1, negative_results: 0, movements_included: 3 },
  unmatched_issuances: [{ event_id: 20, request_id: R1, request_number: 'MIR-000101', label: 'Stethoscope', quantity: 1, note: 'No supply room item is linked to this line, so stock was not changed.', issued_at: minutesAgo(90) }],
  rows: [
    { supply_item_id: 71, name: 'Gauze 4x4', location_code: 'A2', previous_quantity: 40, previous_basis_label: 'Oct 20, 2026', issued: 32, received: 0, adjusted: 0, movement_count: 2, calculated_quantity: 8, threshold_qty_base: 50, threshold_source_text: '< 50U', below_threshold: true, warnings: ['unmatched_issuances'] },
    { supply_item_id: 74, name: 'Gloves Medium', location_code: 'C1', previous_quantity: null, previous_basis_label: 'Not counted yet', issued: 0, received: 0, adjusted: 0, movement_count: 0, calculated_quantity: null, threshold_qty_base: null, threshold_source_text: '< 4Pks', below_threshold: null, warnings: ['no_previous_count'] },
  ],
});

const advisories: Advisories = {
  advisories: [
    {
      supply_item_id: 71, name: 'Gauze 4x4', location_code: 'A2', threshold_qty_base: 50, threshold_source_text: '< 50U', needs_review: false, review_reasons: [], current_quantity: 8,
      current_basis: 'calculated', current_basis_label: 'Calculated since count Oct 20', shortfall_base: 42, suggested_packages: 1, suggested_basis: 'source_order_amount', package_unit: 'box',
      units_per_package: 200, order_amount_text: '1BX = 200U', supplier_code: 'BT', item_number: '100-A', details_unverified: true, open_orders: [],
    },
    {
      supply_item_id: 73, name: 'Tape 1 inch', location_code: 'A2', threshold_qty_base: 12, threshold_source_text: '< 12U', needs_review: false, review_reasons: [], current_quantity: 3,
      current_basis: 'counted', current_basis_label: 'Counted Oct 20', shortfall_base: 9, suggested_packages: 2, suggested_basis: 'source_order_amount', package_unit: 'box',
      units_per_package: 24, order_amount_text: '2BX = 48U', supplier_code: 'BT', item_number: '100-B', details_unverified: true, open_orders: [{ id: 5, packages: 2, placed_at: minutesAgo(600) }],
    },
  ],
  unknown_threshold: [{ supply_item_id: 74, name: 'Gloves Medium', location_code: 'C1', threshold_qty_base: null, threshold_source_text: '< 4Pks', needs_review: true, review_reasons: ['unknown_conversion'] }],
  needs_count: [],
  note: 'A recommendation is not an order. Supplier details are historical and unverified. Record an order only after it has been placed.',
};

const placedOrder: SupplyOrder = { id: 5, supply_item_id: 73, item_name: 'Tape 1 inch', status: 'placed', packages: 2, quantity_base: 48, supplier_code: 'BT', item_number: '100-B', notes: null, placed_by_name: 'Alex Rivera', placed_at: minutesAgo(600), received_at: null, cancelled_at: null };

const movements: Movement[] = [
  { id: 4, uuid: 'm4', supply_item_id: 71, item_name: 'Gauze 4x4', movement_type: 'issued', quantity_base: -16, occurred_at: minutesAgo(90), actor_name: 'Alex Rivera', reason: 'Issued for MIR-000101', request_id: 1, supply_order_id: null, corrects_movement_id: null },
  { id: 3, uuid: 'm3', supply_item_id: 73, item_name: 'Tape 1 inch', movement_type: 'received', quantity_base: 48, occurred_at: minutesAgo(900), actor_name: 'Jordan Lee', reason: 'Order received', request_id: null, supply_order_id: 5, corrects_movement_id: null },
];

const statsReport = (counted: number): StatisticsReport => ({
  label: 'Requests, not consumption',
  filters: { apparatus_id: null, target_kind: null, date_from: '2026-07-26', date_to: '2026-10-23' },
  totals: { requests_submitted: counted + 1, requests_cancelled: 1, requests_counted: counted, lines_requested: counted * 3, qty_requested: counted * 12, yes_answers: counted, qty_issued: counted * 5, qty_outstanding: counted * 2, weeks_in_range: 12.86, requests_per_week: 0.5 },
  top_requested_items: counted === 0 ? [] : [{ bag_key: 'als-med-bag', item_key: 'als.trauma.gauze', label: 'Gauze 4x4', qty_requested: 40, qty_issued: 30, requests: 4 }],
  monthly_trend: counted === 0 ? [] : [{ month: '2026-09', requests: 2, qty_requested: 20, qty_issued: 14 }, { month: '2026-10', requests: 0, qty_requested: 0, qty_issued: 0 }],
  detail_special_event: { requests: counted === 0 ? 0 : 2, without_event_text: 1, events: counted === 0 ? [] : [{ event_assignment: 'Ultra Music Fest', requests: 1 }] },
  by_unit: counted === 0 ? [] : [{ apparatus_id: 2, target_kind: 'rescue_unit', label: 'Rescue 2', requests: counted, qty_requested: 36, qty_issued: 15 }],
  definitions: {
    requests_counted: 'Requests submitted in the range that were not cancelled. Every other figure below uses only these requests.',
    qty_issued: 'The sum of the quantities issued against counted requests, after corrections.',
    qty_outstanding: 'Quantity still owed right now: requested minus issued for items not marked "not issued".',
    requests_per_week: 'Counted requests divided by the weeks in the range.',
    top_requested_items: 'The ten items with the largest requested quantity.',
    monthly_trend: 'Counted requests, requested quantity and issued quantity by calendar month of submission (Eastern time).',
    by_unit: 'The same requested and issued figures grouped by the unit the request was for.',
    detail_special_event: 'Counted requests for Detail / Special Event, and how often each typed event appears.',
  },
});

interface Recorded { method: string; path: string; search: string; key: string | null; body: Record<string, unknown> }

interface Server {
  permissions: EmsMeta['permissions'];
  metaStatus: number;
  metaGate: Promise<void> | null;
  requests: RequestDetail[];
  stock: SupplyItem[];
  orders: SupplyOrder[];
  statsCounted: number;
  writes: Recorded[];
  reads: Recorded[];
  /** Return true after fulfilling to take over a write. */
  onWrite?: (route: Route, recorded: Recorded, server: Server) => Promise<boolean>;
  previewMovement: number;
  staleOnce: boolean;
  draft: SnapshotDetail | null;
  snapshots: SnapshotSummary[];
  nextEvent: number;
}

function newServer(overrides: Partial<Server> = {}): Server {
  return {
    permissions: PERMS_ACT, metaStatus: 200, metaGate: null,
    requests: [
      makeRequest(R1, 'MIR-000101'),
      makeRequest(R2, 'MIR-000102', { status: 'reviewed', status_label: 'Reviewed', target_label: 'Rescue 3', apparatus_id: 3, submitter_name: 'Sam Hill', has_note: false, lines: [{ ...tapeLine }], line_count: 1 }),
      makeRequest(R3, 'MIR-000103', { status: 'issued', status_label: 'Issued', target_kind: 'detail_event', target_label: 'Detail / Special Event', apparatus_id: null, event_assignment: 'Ultra Music Fest', lines: [{ ...tapeLine, issued_qty: 2, line_status: 'issued', line_status_label: 'Issued' }], line_count: 1, issued_line_count: 1 }),
    ],
    stock: STOCK.map((item) => ({ ...item })), orders: [placedOrder], statsCounted: 4, writes: [], reads: [], previewMovement: 4, staleOnce: false,
    draft: null, snapshots: [snapshotSummary('s-prev'), snapshotSummary('s-recon', { kind: 'reconciliation', kind_label: 'Reconciliation (calculated)', version: 4 })], nextEvent: 100,
    ...overrides,
  };
}

const OPEN = ['submitted', 'reviewed', 'prepared', 'partially_issued'];
const summaryOf = (request: RequestDetail) => {
  const { lines: _l, history: _h, messages: _m, member_note: _n, close_reason: _c, cancel_reason: _x, ...summary } = request;
  void _l; void _h; void _m; void _n; void _c; void _x;
  return summary;
};

async function json(route: Route, body: unknown, status = 200): Promise<void> {
  await route.fulfill({ status, json: body, headers: { 'Cache-Control': 'private, no-store' } });
}
const ok = (route: Route, data: unknown, meta: Record<string, unknown> = {}, status = 200) => json(route, { ok: true, data, meta }, status);
const fail = (route: Route, status: number, code: string, message: string, fields: Record<string, string[]> = {}) => json(route, { ok: false, error: { code, message, fields } }, status);
const page2 = (rows: unknown[], pageNo = 1, perPage = 25) => ({ page: pageNo, per_page: perPage, total: rows.length, last_page: Math.max(1, Math.ceil(rows.length / perPage)) });

function applyIssue(server: Server, request: RequestDetail, issuances: Array<{ line_id: number; quantity: number; supply_item_id?: number; reason?: string }>): void {
  for (const row of issuances) {
    const line = request.lines.find((entry) => entry.id === row.line_id) as RequestLine;
    const wanted = line.response_type === 'yes_no' ? 1 : (line.requested_qty ?? 0);
    const candidates = line.supply_candidates ?? [];
    const chosen = row.supply_item_id ? candidates.find((c) => c.id === row.supply_item_id) : candidates.length === 1 ? candidates[0] : undefined;
    line.issued_qty += row.quantity;
    line.line_status = line.issued_qty >= wanted ? 'issued' : 'partially_issued';
    line.line_status_label = LINE_LABEL[line.line_status];
    request.history.push(event(server.nextEvent++, 'issued', {
      label: line.label, quantity: row.quantity, supply_item_id: chosen?.id ?? null, supply_item_name: chosen?.name ?? null,
      stock_effect: line.controlled_substance ? 'none' : chosen ? 'applied' : 'unmatched', over_issue: line.issued_qty > wanted, reason: row.reason ?? null,
    }, { request_line_id: line.id }));
  }
  request.status = request.lines.every((line) => ['issued', 'not_issued'].includes(line.line_status)) ? 'issued' : 'partially_issued';
  request.status_label = STATUS_LABEL[request.status];
  request.issued_line_count = request.lines.filter((line) => line.issued_qty > 0).length;
}

async function handleWrite(route: Route, server: Server, recorded: Recorded, uuid: string | undefined, action: string): Promise<void> {
  if (server.onWrite && await server.onWrite(route, recorded, server)) return;
  const request = server.requests.find((entry) => entry.id === uuid) as RequestDetail;
  const body = recorded.body;
  if (action === 'review') { request.status = 'reviewed'; request.status_label = 'Reviewed'; request.history.push(event(server.nextEvent++, 'reviewed')); }
  if (action === 'prepare') { for (const row of body.lines as Array<{ line_id: number; prepared_qty: number | null }>) { const line = request.lines.find((l) => l.id === row.line_id) as RequestLine; line.prepared_qty = row.prepared_qty; } request.status = 'prepared'; request.status_label = 'Prepared'; }
  if (action === 'issue') applyIssue(server, request, body.issuances as never);
  if (action === 'not-issued') { const line = request.lines.find((l) => l.id === body.line_id) as RequestLine; line.line_status = 'not_issued'; line.line_status_label = 'Not issued'; line.not_issued_reason = String(body.reason); }
  if (action === 'close') { request.status = 'closed'; request.status_label = 'Closed'; request.close_reason = String(body.reason); request.closed_at = new Date().toISOString(); request.history.push(event(server.nextEvent++, 'closed', { reason: body.reason })); }
  if (action === 'reopen') { request.status = 'reviewed'; request.status_label = 'Reviewed'; request.close_reason = null; request.closed_at = null; request.history.push(event(server.nextEvent++, 'reopened', { reason: body.reason })); }
  if (action === 'correct') {
    const original = request.history.find((e) => e.id === body.event_id) as HistoryEvent;
    request.history.push(event(server.nextEvent++, 'issue_corrected', {
      label: original.payload.label, corrects_event_id: body.event_id, previous_quantity: original.payload.quantity, new_quantity: body.new_quantity, supply_item_id: body.supply_item_id ?? original.payload.supply_item_id,
      stock_effect: original.payload.stock_effect, reason: body.reason,
    }, { request_line_id: original.request_line_id }));
    const line = request.lines.find((l) => l.id === original.request_line_id) as RequestLine;
    line.issued_qty = Number(body.new_quantity);
  }
  if (action === 'messages') {
    const message = { id: server.nextEvent++, author_name: 'Alex Rivera', author_rank: 'Capt.', author_context: 'captain5', author_context_label: 'Captain 5', body: String(body.body), created_at: new Date().toISOString() };
    request.messages.push(message);
    return ok(route, { ...message, request_id: request.id }, { replayed: false }, 201);
  }
  return ok(route, request, { replayed: false });
}

async function handleEms(route: Route, server: Server, url: URL): Promise<void> {
  const method = route.request().method();
  const path = url.pathname.replace('/ems/api', '');
  const text = route.request().postData();
  const recorded: Recorded = { method, path, search: url.search, key: route.request().headers()['idempotency-key'] ?? null, body: text ? JSON.parse(text) as Record<string, unknown> : {} };
  if (method === 'GET') server.reads.push(recorded); else server.writes.push(recorded);

  if (path === '/meta') {
    if (server.metaGate) await server.metaGate;
    return server.metaStatus === 200 ? ok(route, metaFor(server.permissions)) : fail(route, server.metaStatus, 'forbidden', 'You do not have access to do that.');
  }
  if (path === '/requests' && method === 'GET') {
    const statuses = (url.searchParams.get('status') ?? '').split(',').filter(Boolean);
    const unit = url.searchParams.get('unit');
    const q = (url.searchParams.get('q') ?? '').toLowerCase();
    const rows = server.requests.filter((r) => (statuses.length === 0 || statuses.includes(r.status))
      && (!unit || (unit === 'detail' ? r.target_kind === 'detail_event' : String(r.apparatus_id) === unit))
      && (!q || `${r.number} ${r.submitter_name} ${r.target_label}`.toLowerCase().includes(q)));
    const perPage = Number(url.searchParams.get('per_page') ?? 25);
    return ok(route, rows.slice(0, perPage).map(summaryOf), page2(rows, 1, perPage));
  }
  const requestMatch = /^\/requests\/([0-9a-f-]{36})(?:\/([a-z-]+))?$/.exec(path);
  if (requestMatch) {
    const found = server.requests.find((r) => r.id === requestMatch[1]);
    if (!found) return fail(route, 404, 'not_found', 'That request could not be found.');
    if (method === 'GET') return ok(route, found);
    return handleWrite(route, server, recorded, requestMatch[1], requestMatch[2] ?? '');
  }
  if (path === '/statistics') return ok(route, statsReport(server.statsCounted));

  if (path === '/supply/items' && method === 'GET') return ok(route, server.stock, page2(server.stock, 1, 200));
  const itemMatch = /^\/supply\/items\/(\d+)$/.exec(path);
  if (itemMatch && method === 'GET') return ok(route, { ...server.stock.find((i) => i.id === Number(itemMatch[1])), audit: [] });
  if (itemMatch && method === 'PATCH') {
    if (server.onWrite && await server.onWrite(route, recorded, server)) return;
    const item = server.stock.find((i) => i.id === Number(itemMatch[1])) as SupplyItem;
    Object.assign(item, recorded.body, { needs_review: recorded.body.resolve_review ? false : item.needs_review });
    return ok(route, { ...item, audit: [] });
  }
  if (path === '/supply/locations') return ok(route, [{ id: 1, code: 'A2', label: 'A2', active: true, item_count: 2 }, { id: 2, code: 'C1', label: 'C1', active: true, item_count: 1 }]);
  if (path === '/supply/counts' && method === 'GET') return ok(route, server.draft ? [snapshotSummary(server.draft.id, { status: 'draft', version: null, finalized_at: null, finalized_name: null }), ...server.snapshots] : server.snapshots);
  if (path === '/supply/counts' && method === 'POST') {
    server.draft = { ...snapshotSummary('d-1111', { status: 'draft', version: null, scope_location_code: (recorded.body.location_code as string | null) ?? null, line_count: 2, counted_count: 0 }), lines: [], items: countItems() };
    return ok(route, server.draft, { replayed: false }, 201);
  }
  const lines = /^\/supply\/counts\/([^/]+)\/lines$/.exec(path);
  if (lines) {
    if (server.onWrite && await server.onWrite(route, recorded, server)) return;
    const draft = server.draft as SnapshotDetail;
    for (const row of recorded.body.lines as Array<Record<string, number | null>>) {
      const target = (draft.items as CountItem[]).find((i) => i.id === row.supply_item_id) as CountItem;
      const entry = Object.fromEntries((['cases', 'boxes', 'each'] as const).filter((k) => row[k] !== null && row[k] !== undefined).map((k) => [k, row[k]]));
      target.entry = Object.keys(entry).length ? entry : null;
    }
    return ok(route, draft, { replayed: false });
  }
  const finalize = /^\/supply\/counts\/([^/]+)\/finalize$/.exec(path);
  if (finalize) {
    if (server.onWrite && await server.onWrite(route, recorded, server)) return;
    const done = { ...(server.draft as SnapshotDetail), id: 'f-2222', status: 'final' as const, version: 5, finalized_name: 'Alex Rivera', finalized_at: new Date().toISOString() };
    server.draft = null;
    server.snapshots = [snapshotSummary('f-2222', { version: 5 }), ...server.snapshots];
    return ok(route, done, { replayed: false });
  }
  const discard = /^\/supply\/counts\/([^/]+)$/.exec(path);
  if (discard && method === 'DELETE') { server.draft = null; return ok(route, { discarded: true }); }
  if (path === '/supply/reconciliation' && method === 'GET') return ok(route, preview(server.previewMovement));
  if (path === '/supply/reconciliation' && method === 'POST') {
    if (server.staleOnce) {
      server.staleOnce = false;
      server.previewMovement = 9;
      return fail(route, 409, 'stale_preview', 'New stock changes were recorded since you opened this preview. Review the updated numbers.');
    }
    return ok(route, { ...snapshotSummary('r-3333', { kind: 'reconciliation', kind_label: 'Reconciliation (calculated)', version: 6 }), lines: [], unchanged: false }, { replayed: false }, 201);
  }
  if (path === '/supply/advisories') return ok(route, advisories);
  if (path === '/supply/orders' && method === 'GET') return ok(route, server.orders);
  if (path === '/supply/orders' && method === 'POST') {
    const order: SupplyOrder = { ...placedOrder, id: 6, supply_item_id: Number(recorded.body.supply_item_id), item_name: 'Gauze 4x4', packages: Number(recorded.body.packages) };
    server.orders = [order, ...server.orders];
    return ok(route, order, { replayed: false }, 201);
  }
  const orderAction = /^\/supply\/orders\/(\d+)\/(receive|cancel)$/.exec(path);
  if (orderAction) {
    const order = server.orders.find((o) => o.id === Number(orderAction[1])) as SupplyOrder;
    order.status = orderAction[2] === 'receive' ? 'received' : 'cancelled';
    return ok(route, order, { replayed: false });
  }
  if (path === '/supply/movements') return ok(route, movements, page2(movements, 1, 25));
  if (path === '/supply/snapshots') return ok(route, server.snapshots);
  const snapshot = /^\/supply\/snapshots\/([^/]+)$/.exec(path);
  if (snapshot && server.draft && snapshot[1] === server.draft.id) return ok(route, server.draft);
  if (snapshot) {
    const summary = server.snapshots.find((s) => s.id === snapshot[1]) ?? snapshotSummary(snapshot[1]);
    return ok(route, {
      ...summary,
      lines: [
        { supply_item_id: 73, item_name: 'Tape 1 inch', location_code: 'A2', quantity_base: 30, entry: { each: 30 }, value_source: 'counted', warnings: [] },
        { supply_item_id: 74, item_name: 'Gloves Medium', location_code: 'C1', quantity_base: null, entry: {}, value_source: 'not_counted', warnings: ['unknown_conversion'] },
      ],
    });
  }
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
          on_duty: { duty_date: '2026-10-23', ...duty }, duty_date: '2026-10-23',
          counts: { open: 0, needs_acknowledgement: 0, ordered: 0, ready_for_pickup: 0, overdue: 0, awaiting_verification: 0, significant: 0, rescue: 0, medical: 0 },
          rescue_dailies: [], issues: [], handoff: { duty_date: '2026-10-23', notes: [] },
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
  if (dir) await page.screenshot({ path: `${dir}/${name}.png`, fullPage: false });
}

async function noHorizontalOverflow(page: Page): Promise<void> {
  const widths = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

const sheet = (page: Page) => page.getByTestId('ems-request-sheet');
const lastWrite = (server: Server) => server.writes[server.writes.length - 1];

// ---------------------------------------------------------------------------- gating

test.describe('tabs and access', () => {
  test('the tabs appear for people the EMS API admits, with the open request count as the badge', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5');
    const tabs = page.getByRole('tablist', { name: 'Captain 5 workspace' });
    await expect(tabs.getByRole('tab', { name: /Rescue Inventory/ })).toContainText('2');
    await expect(tabs.getByRole('tab', { name: /Rescue Inventory/ })).toContainText('open requests');
    await expect(tabs.getByRole('tab', { name: 'Supply Room' })).toBeVisible();
    const countRead = server.reads.find((read) => read.path === '/requests' && read.search.includes('per_page=1'));
    expect(decodeURIComponent(countRead?.search ?? '')).toContain('status=submitted,reviewed,prepared,partially_issued');
  });

  test('a person without EMS view never sees the tabs, and a deep link falls back to Today', async ({ page }) => {
    await open(page, newServer({ metaStatus: 403 }), '/daily/captain-5?tab=rescue&request=' + R1);
    await expect(page.getByTestId('captain5-workspace')).toBeVisible();
    await expect(page.getByRole('tab', { name: /Rescue Inventory/ })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Supply Room' })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Today' })).toHaveAttribute('aria-selected', 'true');
    await expect(sheet(page)).toHaveCount(0);
  });

  test('a deep link keeps its tab while access is being checked, so Today never flashes', async ({ page }) => {
    let release!: () => void;
    const gate = new Promise<void>((resolve) => { release = resolve; });
    await open(page, newServer({ metaGate: gate }), '/daily/captain-5?tab=supply');
    const supply = page.getByRole('tab', { name: 'Supply Room' });
    await expect(supply).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tab', { name: 'Today' })).toHaveAttribute('aria-selected', 'false');
    release();
    await expect(page.getByTestId('ems-supply-stock')).toBeVisible();
    await expect(supply).toHaveAttribute('aria-selected', 'true');
  });
});

// ---------------------------------------------------------------------- rescue inventory

test.describe('rescue inventory list', () => {
  test('shows the open requests by default with number, unit, member, Eastern time and items', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=rescue');
    const rows = page.getByTestId('ems-request-row');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('MIR-000101');
    await expect(rows.first()).toContainText('Submitted');
    await expect(rows.first()).toContainText('Rescue 2');
    await expect(rows.first()).toContainText('Paramedic Pat Medic');
    await expect(rows.first()).toContainText('Oct 23, 2:05 PM EDT');
    await expect(rows.first()).toContainText('5 items requested, 0 issued');
    await expect(page.getByRole('button', { name: 'Open', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const list = server.reads.filter((read) => read.path === '/requests' && read.search.includes('per_page=25'));
    expect(decodeURIComponent(list[0].search)).toContain('status=submitted,reviewed,prepared,partially_issued');
  });

  test('filter chips, unit, search and the date range change the query; Detail / Special Event reads with its event', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=rescue');
    await expect(page.getByTestId('ems-request-row')).toHaveCount(2);

    await page.getByRole('button', { name: 'Issued', exact: true }).click();
    const issued = page.getByTestId('ems-request-row');
    await expect(issued).toHaveCount(1);
    await expect(issued.first()).toContainText('Detail / Special Event: Ultra Music Fest');

    await page.getByRole('button', { name: 'All', exact: true }).click();
    await expect(page.getByTestId('ems-request-row')).toHaveCount(3);
    await page.getByLabel('Unit').selectOption('3');
    await expect(page.getByTestId('ems-request-row')).toHaveCount(1);
    await expect(page.getByTestId('ems-request-row').first()).toContainText('Rescue 3');
    await page.getByLabel('Unit').selectOption('');

    await page.getByLabel('Search').fill('sam');
    await expect(page.getByTestId('ems-request-row')).toHaveCount(1);
    await expect(page.getByTestId('ems-request-row').first()).toContainText('MIR-000102');

    await page.getByLabel('From date').fill('2026-10-23');
    await page.getByLabel('To date').fill('2026-10-01');
    await expect(page.getByRole('alert').filter({ hasText: 'The end date cannot be before the start date.' })).toBeVisible();
    await page.getByLabel('To date').fill('2026-10-30');
    await expect.poll(() => server.reads.filter((read) => read.path === '/requests' && read.search.includes('per_page=25')).pop()?.search ?? '').toContain('date_to=2026-10-30');
    expect(server.reads.filter((read) => read.path === '/requests' && read.search.includes('per_page=25')).pop()?.search).toContain('date_from=2026-10-23');
  });

  test('an empty filter explains itself', async ({ page }) => {
    await open(page, newServer({ requests: [] }), '/daily/captain-5?tab=rescue');
    await expect(page.getByText('No open requests')).toBeVisible();
    await page.getByLabel('Search').fill('zzz');
    await expect(page.getByText('No requests match these filters')).toBeVisible();
  });
});

test.describe('request sheet', () => {
  test('a deep link opens the request with its lines grouped by form and section, and its history', async ({ page }) => {
    await open(page, newServer(), `/daily/captain-5?tab=rescue&request=${R1}`);
    const panel = sheet(page);
    await expect(panel).toBeVisible();
    await expect(panel.getByRole('heading', { name: /MIR-000101: Rescue 2/ })).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'ALS Med Bag or Box', level: 4 })).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'Airway Bag', level: 4 })).toBeVisible();
    const gauze = panel.getByTestId('ems-line').filter({ hasText: 'Gauze 4x4' });
    await expect(gauze).toContainText('Requested');
    await expect(gauze).toContainText('10');
    await expect(gauze).toContainText('Bag minimum: 10 EACH');
    await expect(panel.getByTestId('ems-line').filter({ hasText: 'Cardizem' })).toContainText('Refrigerated');
    await expect(panel.getByTestId('ems-line').filter({ hasText: 'Narcs Box' })).toContainText('Controlled item');
    await expect(panel.getByTestId('ems-line').filter({ hasText: 'Narcs Box' })).toContainText('Yes');
    await expect(panel.getByTestId('ems-history-item').first()).toContainText('Submitted the request.');
    await expect(panel.getByTestId('ems-history-item').first()).toContainText('Pat Medic (Member)');
  });

  test('closing the sheet returns to the list and clears the link', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=rescue');
    await page.getByTestId('ems-request-row').first().click();
    await expect(sheet(page)).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`request=${R1}`));
    await page.keyboard.press('Escape');
    await expect(sheet(page)).toHaveCount(0);
    await expect(page).not.toHaveURL(/request=/);
  });

  test('a request that is not there says so', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=rescue&request=99999999-9999-4999-8999-999999999999');
    await expect(page.getByTestId('ems-request-unavailable')).toContainText('not available to you');
  });

  test('issuing sends the lines with a fresh idempotency key and tells which went to stock and which were recorded only', async ({ page }) => {
    const server = newServer();
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R1}`);
    await sheet(page).getByRole('button', { name: 'Issue', exact: true }).click();
    const form = page.getByTestId('ems-form-issue');
    await expect(form).toBeVisible();

    await form.getByLabel('Quantity to issue for Gauze 4x4').fill('6');
    await form.getByLabel('Quantity to issue for Tape 1"').fill('2');
    await form.getByLabel('Quantity to issue for Stethoscope').fill('1');
    await form.getByRole('button', { name: 'Issue these items' }).click();
    // Gauze has two candidate supply items, so a choice is required before anything is sent.
    await expect(form.getByText(/choose which supply room item it comes from/)).toBeVisible();
    expect(server.writes).toHaveLength(0);

    await form.getByLabel('Which supply room item for Gauze 4x4').selectOption('71');
    await form.getByRole('button', { name: 'Issue these items' }).click();

    await expect(page.getByTestId('ems-issue-outcome')).toHaveCount(3);
    await expect(page.getByTestId('ems-issue-outcome').nth(0)).toHaveText('Stock updated: Gauze 4x4 (6) from Gauze 4x4');
    await expect(page.getByTestId('ems-issue-outcome').nth(1)).toHaveText('Stock updated: Tape 1" (2) from Tape 1 inch');
    await expect(page.getByTestId('ems-issue-outcome').nth(2)).toHaveText('Recorded only: Stethoscope (1)');

    const write = lastWrite(server);
    expect(write.path).toBe(`/requests/${R1}/issue`);
    expect(write.key).toMatch(KEY_PATTERN);
    expect(write.body).toEqual({ issuances: [
      { line_id: 1, quantity: 6, supply_item_id: 71 },
      { line_id: 2, quantity: 2, supply_item_id: 73 },
      { line_id: 3, quantity: 1 },
    ] });
    await expect(sheet(page).getByTestId('ems-line').filter({ hasText: 'Gauze 4x4' })).toContainText('Partly issued');
  });

  test('issuing more than was requested needs a reason before anything is sent', async ({ page }) => {
    const server = newServer();
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R2}`);
    await sheet(page).getByRole('button', { name: 'Issue', exact: true }).click();
    const form = page.getByTestId('ems-form-issue');
    await form.getByLabel('Quantity to issue for Tape 1"').fill('5');
    await expect(form.getByLabel('Why more than was requested')).toBeVisible();
    await form.getByRole('button', { name: 'Issue these items' }).click();
    await expect(form.getByText(/more than was requested. Give a reason to issue extra/)).toBeVisible();
    expect(server.writes).toHaveLength(0);
    await form.getByLabel('Why more than was requested').fill('Crew short on a call');
    await form.getByRole('button', { name: 'Issue these items' }).click();
    await expect(page.getByTestId('ems-issue-outcome')).toHaveCount(1);
    expect(lastWrite(server).body).toEqual({ issuances: [{ line_id: 2, quantity: 5, supply_item_id: 73, reason: 'Crew short on a call' }] });
  });

  test('a dropped connection keeps what was typed, and the retry sends the same idempotency key', async ({ page }) => {
    const server = newServer();
    let attempts = 0;
    server.onWrite = async (route, recorded) => {
      if (!recorded.path.endsWith('/issue')) return false;
      attempts += 1;
      if (attempts === 1) { await route.abort('failed'); return true; }
      return false;
    };
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R2}`);
    await sheet(page).getByRole('button', { name: 'Issue', exact: true }).click();
    const form = page.getByTestId('ems-form-issue');
    await form.getByLabel('Quantity to issue for Tape 1"').fill('2');
    await form.getByRole('button', { name: 'Issue these items' }).click();
    await expect(form.getByText(/nothing is confirmed/)).toBeVisible();
    await expect(form.getByLabel('Quantity to issue for Tape 1"')).toHaveValue('2');
    await form.getByRole('button', { name: /Retry: Issue these items/ }).click();
    await expect(page.getByTestId('ems-issue-outcome')).toHaveCount(1);
    const issues = server.writes.filter((write) => write.path.endsWith('/issue'));
    expect(issues).toHaveLength(2);
    expect(issues[0].key).toBe(issues[1].key);
  });

  test('a conflict refreshes the sheet, says so, and keeps the typed text', async ({ page }) => {
    const server = newServer();
    server.onWrite = async (route, recorded, state) => {
      if (!recorded.path.endsWith('/close')) return false;
      const request = state.requests.find((r) => r.id === R2) as RequestDetail;
      request.status = 'closed'; request.status_label = 'Closed'; request.close_reason = 'Closed by someone else';
      await fail(route, 409, 'request_closed', 'This request is closed. Reopen it first.');
      return true;
    };
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R2}`);
    await sheet(page).getByRole('button', { name: 'Close request', exact: true }).click();
    const form = page.getByTestId('ems-form-close');
    await form.getByLabel('Reason for closing').fill('All done here');
    await form.getByRole('button', { name: 'Review and close' }).click();
    await page.getByTestId('ems-confirm-close').getByRole('button', { name: 'Close request' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Someone else changed this request');
    await expect(sheet(page).getByText('Closed', { exact: true }).first()).toBeVisible();
    await expect(sheet(page).getByRole('button', { name: 'Reopen' })).toBeVisible();
  });

  test('closing uses a quick pick from the API, asks once, and sends the reason', async ({ page }) => {
    const server = newServer();
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R2}`);
    await sheet(page).getByTestId('ems-actions').getByRole('button', { name: 'Close request', exact: true }).click();
    const form = page.getByTestId('ems-form-close');
    await expect(form.getByRole('button', { name: 'Close: Issued in full' })).toBeVisible();
    await expect(form.getByRole('button', { name: 'Close — No Action Required' })).toBeVisible();
    await form.getByRole('button', { name: 'Close — No Action Required' }).click();
    await expect(form.getByLabel('Reason for closing')).toHaveValue('No action required');
    await form.getByRole('button', { name: 'Review and close' }).click();
    const confirm = page.getByTestId('ems-confirm-close');
    await expect(confirm).toContainText('1 item has not been issued or marked not issued.');
    expect(server.writes).toHaveLength(0);
    await confirm.getByRole('button', { name: 'Close request' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Request closed.');
    expect(lastWrite(server).path).toBe(`/requests/${R2}/close`);
    expect(lastWrite(server).body).toEqual({ reason: 'No action required' });
    expect(lastWrite(server).key).toMatch(KEY_PATTERN);
    await sheet(page).getByRole('button', { name: 'Reopen' }).click();
    await page.getByLabel('Reason for reopening').fill('Member called back');
    await page.getByRole('button', { name: 'Reopen request' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Request reopened.');
  });

  test('correcting an issuance starts from the quantity it stands at now (after earlier corrections)', async ({ page }) => {
    const server = newServer();
    const request = server.requests[2];
    request.history.push(
      event(30, 'issued', { label: 'Tape 1"', quantity: 4, supply_item_id: 73, supply_item_name: 'Tape 1 inch', stock_effect: 'applied' }, { request_line_id: 2 }),
      event(31, 'issue_corrected', { label: 'Tape 1"', corrects_event_id: 30, previous_quantity: 4, new_quantity: 3, supply_item_id: 73, stock_effect: 'applied', reason: 'Miscount' }, { request_line_id: 2 }),
    );
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R3}`);
    await sheet(page).getByRole('button', { name: 'Correct an issuance' }).click();
    const form = page.getByTestId('ems-form-correct');
    await expect(form).toContainText('Tape 1": 3 issued (was 4) from Tape 1 inch');
    await form.getByRole('radio').check();
    await expect(form.getByLabel('Correct quantity')).toHaveValue('3');
    await form.getByLabel('Correct quantity').fill('2');
    await form.getByLabel('Why it is being corrected').fill('Crew returned one');
    await form.getByRole('button', { name: 'Review correction' }).click();
    expect(server.writes).toHaveLength(0);
    await page.getByTestId('ems-confirm-correct').getByRole('button', { name: 'Record correction' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Correction recorded.');
    expect(lastWrite(server).body).toEqual({ event_id: 30, new_quantity: 2, reason: 'Crew returned one' });
    await expect(page.getByTestId('ems-history-item').filter({ hasText: 'Corrected Tape 1" from 4 to 2' })).toBeVisible();
  });

  test('marking a line not issued needs a reason; preparing and messaging send their payloads', async ({ page }) => {
    const server = newServer();
    await open(page, server, `/daily/captain-5?tab=rescue&request=${R1}`);
    await sheet(page).getByRole('button', { name: 'Mark Cardizem not issued' }).click();
    const form = page.getByTestId('ems-form-not-issued');
    await form.getByRole('button', { name: 'Mark not issued' }).click();
    await expect(form.getByText('Give a reason this item is not being issued.')).toBeVisible();
    await form.getByLabel('Why it is not being issued').fill('Out of stock');
    await form.getByRole('button', { name: 'Mark not issued' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Cardizem marked not issued.');
    expect(lastWrite(server).body).toEqual({ line_id: 5, reason: 'Out of stock' });

    await sheet(page).getByRole('button', { name: 'Prepare', exact: true }).click();
    await page.getByLabel('Prepared quantity for Tape 1"').fill('2');
    await page.getByRole('button', { name: 'Save prepared quantities' }).click();
    await expect(page.getByTestId('ems-sheet-notice')).toContainText('Prepared quantities saved');
    expect(lastWrite(server).body).toEqual({ lines: [{ line_id: 2, prepared_qty: 2 }] });

    await sheet(page).getByRole('textbox', { name: 'Message to the member' }).fill('Your tape is ready at the supply room.');
    await sheet(page).getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByTestId('ems-message')).toContainText('Your tape is ready at the supply room.');
    expect(lastWrite(server).body).toEqual({ body: 'Your tape is ready at the supply room.' });
    expect(lastWrite(server).key).toMatch(KEY_PATTERN);
  });

  test('a view-only person can read the request and has no actions or message box', async ({ page }) => {
    await open(page, newServer({ permissions: PERMS_VIEW }), `/daily/captain-5?tab=rescue&request=${R1}`);
    await expect(sheet(page).getByTestId('ems-read-only')).toBeVisible();
    await expect(sheet(page).getByRole('button', { name: 'Issue', exact: true })).toHaveCount(0);
    await expect(sheet(page).getByRole('button', { name: 'Close request' })).toHaveCount(0);
    await expect(sheet(page).getByRole('button', { name: /not issued/ })).toHaveCount(0);
    await expect(sheet(page).getByRole('textbox', { name: 'Message to the member' })).toHaveCount(0);
    await expect(sheet(page).getByTestId('ems-line').first()).toBeVisible();
  });
});

// ------------------------------------------------------------------------- statistics

test.describe('unit statistics', () => {
  test('default to the last 90 days, carry the footnote, and print the definition under each figure', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=rescue&view=stats');
    await expect(page.getByTestId('ems-stats-footnote')).toHaveText('These figures count requests and issuances, not supplies used on calls.');
    const totals = page.getByTestId('ems-stats-totals');
    await expect(totals).toContainText('Quantity issued (fulfilled)');
    await expect(totals).toContainText('Outstanding right now');
    await expect(totals).toContainText('Request frequency');
    await expect(totals).toContainText('0.5 per week');
    await expect(totals).toContainText('The sum of the quantities issued against counted requests, after corrections.');
    await expect(page.getByTestId('ems-stats-top')).toContainText('Gauze 4x4');
    await expect(page.getByTestId('ems-stats-monthly')).toContainText('Sep 2026');
    await expect(page.getByTestId('ems-stats-events')).toContainText('Ultra Music Fest');
    const read = server.reads.find((entry) => entry.path === '/statistics');
    const query = new URLSearchParams(read?.search ?? '');
    const from = new Date(`${query.get('date_from')}T00:00:00Z`).getTime();
    const to = new Date(`${query.get('date_to')}T00:00:00Z`).getTime();
    expect(Math.round((to - from) / 86_400_000)).toBe(89);

    await page.getByLabel('Unit', { exact: true }).selectOption('2');
    await page.getByLabel('Kind of request').selectOption('rescue_unit');
    await expect.poll(() => server.reads.filter((entry) => entry.path === '/statistics').pop()?.search ?? '').toMatch(/apparatus_id=2.*target_kind=rescue_unit|target_kind=rescue_unit.*apparatus_id=2/);
  });

  test('an empty range explains why', async ({ page }) => {
    await open(page, newServer({ statsCounted: 0 }), '/daily/captain-5?tab=rescue&view=stats');
    await expect(page.getByText('No requests in this range')).toBeVisible();
    await expect(page.getByTestId('ems-stats-footnote')).toBeVisible();
  });
});

// ----------------------------------------------------------------------------- supply

test.describe('supply room stock', () => {
  test('groups stock by location with its basis, highlights low and review items, and lists controlled items apart with no actions', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=supply');
    const items = page.getByTestId('ems-stock-item');
    await expect(items).toHaveCount(4);
    await expect(page.getByRole('region', { name: 'Location A2' })).toBeVisible();
    const gauze = items.filter({ hasText: 'Gauze 4x4' });
    await expect(gauze).toHaveAttribute('data-state', 'below');
    await expect(gauze).toContainText('Below threshold');
    await expect(gauze).toContainText('8 each');
    await expect(gauze).toContainText('Calculated since count Oct 20');
    await expect(gauze).toContainText('Reorder below 50 (source says < 50U)');
    await expect(items.filter({ hasText: 'Tape 1 inch' })).toContainText('Counted Oct 20');
    const gloves = items.filter({ hasText: 'Gloves Medium' });
    await expect(gloves).toHaveAttribute('data-state', 'review');
    await expect(gloves).toContainText('Not counted yet');
    await expect(gloves).toContainText('Case, size not stated');
    await expect(items.filter({ hasText: 'Cardizem' })).toContainText('Refrigerated');

    const controlled = page.getByTestId('ems-controlled');
    await expect(controlled).toContainText('tracked through the department controlled-substance procedure');
    await expect(controlled.getByTestId('ems-controlled-item')).toHaveText(['Fentanyl (Narcotics Locker) (LOCK)']);
    await expect(controlled.getByRole('button')).toHaveCount(0);
    await expect(items.filter({ hasText: 'Fentanyl' })).toHaveCount(0);

    await page.getByRole('button', { name: /Below threshold \(1\)/ }).click();
    await expect(page.getByTestId('ems-stock-item')).toHaveCount(1);
  });

  test('item settings appear only for someone who can configure, and need a reason', async ({ page }) => {
    const server = newServer({ permissions: PERMS_ADMIN });
    await open(page, server, '/daily/captain-5?tab=supply');
    await page.getByTestId('ems-stock-item').filter({ hasText: 'Gloves Medium' }).getByRole('button', { name: /Details/ }).click();
    await page.getByRole('button', { name: 'Edit settings' }).click();
    const form = page.getByTestId('ems-item-settings');
    await form.getByLabel('Reorder threshold (single units)').fill('40');
    await form.getByRole('button', { name: 'Save settings' }).click();
    await expect(form.getByText(/Give a reason for this change/)).toBeVisible();
    expect(server.writes).toHaveLength(0);
    await form.getByLabel('Why you are changing this').fill('Confirmed with the supply officer');
    await form.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
    expect(lastWrite(server).path).toBe('/supply/items/74');
    expect(lastWrite(server).body).toEqual({ threshold_qty_base: 40, reason: 'Confirmed with the supply officer' });

    const plain = await page.context().newPage();
    await open(plain, newServer({ permissions: PERMS_ACT }), '/daily/captain-5?tab=supply');
    await plain.getByTestId('ems-stock-item').first().getByRole('button', { name: /Details/ }).click();
    await expect(plain.getByTestId('ems-item-details')).toBeVisible();
    await expect(plain.getByRole('button', { name: 'Edit settings' })).toHaveCount(0);
  });
});

test.describe('counts', () => {
  test('start, type, autosave, finalize with confirmation, then download links', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=supply&section=counts');
    await page.getByLabel('Location').selectOption('A2');
    await page.getByRole('button', { name: 'Start count' }).click();
    await expect(page.getByTestId('ems-count-session')).toContainText('Counting location A2');
    expect(lastWrite(server).body).toEqual({ location_code: 'A2' });
    expect(lastWrite(server).key).toMatch(KEY_PATTERN);

    await expect(page.getByLabel('Boxes counted for Tape 1 inch')).toBeVisible();
    await expect(page.getByLabel('Cases counted for Tape 1 inch')).toHaveCount(0);
    await expect(page.getByLabel('Boxes counted for Gloves Medium')).toHaveCount(0);
    await expect(page.getByText('No package size is stated for this item, so count single units.')).toBeVisible();
    await page.getByLabel('Boxes counted for Tape 1 inch').fill('2');
    await page.getByLabel('Each counted for Tape 1 inch').fill('5');
    await expect(page.getByText('= 53 single units')).toBeVisible();
    await expect(page.getByTestId('ems-autosave')).toContainText(/All changes saved/);
    const save = server.writes.find((write) => write.path.endsWith('/lines'));
    expect(save?.body).toEqual({ lines: [{ supply_item_id: 73, cases: null, boxes: 2, each: 5 }] });
    expect(save?.key).toMatch(KEY_PATTERN);

    await page.getByRole('button', { name: 'Finalize count' }).click();
    const confirm = page.getByTestId('ems-confirm-finalize');
    await expect(confirm).toContainText('can never be changed afterward');
    expect(server.writes.some((write) => write.path.endsWith('/finalize'))).toBe(false);
    await confirm.getByRole('button', { name: 'Finalize count' }).click();
    await expect(page.getByTestId('ems-count-final')).toContainText('Count finalized as version 5 by Alex Rivera. It cannot be changed.');
    await expect(page.getByTestId('ems-count-final').getByRole('link', { name: 'Download Excel' })).toHaveAttribute('href', '/ems/api/supply/snapshots/f-2222/export/xlsx');
    await expect(page.getByTestId('ems-count-final').getByRole('link', { name: 'Download PDF' })).toHaveAttribute('href', '/ems/api/supply/snapshots/f-2222/export/pdf');
    expect(server.writes.find((write) => write.path.endsWith('/finalize'))?.key).toMatch(KEY_PATTERN);
  });

  test('a failed autosave says so and the retry sends the same key', async ({ page }) => {
    const server = newServer();
    let attempts = 0;
    server.onWrite = async (route, recorded) => {
      if (!recorded.path.endsWith('/lines')) return false;
      attempts += 1;
      if (attempts === 1) { await route.abort('failed'); return true; }
      return false;
    };
    await open(page, server, '/daily/captain-5?tab=supply&section=counts');
    await page.getByRole('button', { name: 'Start count' }).click();
    await page.getByLabel('Each counted for Tape 1 inch').fill('7');
    await expect(page.getByTestId('ems-autosave')).toContainText('Not saved');
    await expect(page.getByLabel('Each counted for Tape 1 inch')).toHaveValue('7');
    await page.getByRole('button', { name: /Retry saving/ }).click();
    await expect(page.getByTestId('ems-autosave')).toContainText(/All changes saved/);
    const saves = server.writes.filter((write) => write.path.endsWith('/lines'));
    expect(saves).toHaveLength(2);
    expect(saves[0].key).toBe(saves[1].key);
  });

  test('a draft can be resumed from the list', async ({ page }) => {
    const server = newServer();
    server.draft = { ...snapshotSummary('d-1111', { status: 'draft', version: null, finalized_at: null, finalized_name: null }), lines: [], items: countItems() } as SnapshotDetail;
    server.snapshots = [...server.snapshots];
    await open(page, server, '/daily/captain-5?tab=supply&section=counts');
    await expect(page.getByTestId('ems-count-draft')).toContainText('Draft');
    await page.getByRole('button', { name: 'Resume' }).click();
    await expect(page.getByTestId('ems-count-session')).toBeVisible();
  });
});

test.describe('reconcile', () => {
  test('previews the calculated stock and confirms exactly what was previewed', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=supply&section=reconcile');
    const rows = page.getByTestId('ems-reconcile-row');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Gauze 4x4');
    await expect(rows.first()).toContainText('issued 32, received 0');
    await expect(rows.first()).toContainText('Issued with no matching item');
    await expect(rows.nth(1)).toContainText('No earlier count');
    await expect(page.getByTestId('ems-unmatched')).toContainText('MIR-000101: 1 of Stethoscope');
    await page.getByRole('button', { name: 'Confirm reconciliation' }).click();
    expect(server.writes).toHaveLength(0);
    await page.getByTestId('ems-confirm-reconcile').getByRole('button', { name: 'Save reconciliation' }).click();
    await expect(page.getByTestId('ems-reconcile-notice')).toContainText('Reconciliation saved as version 6. It is calculated, not a physical count.');
    expect(lastWrite(server).path).toBe('/supply/reconciliation');
    expect(lastWrite(server).body).toEqual({ expected_last_movement_id: 4 });
    expect(lastWrite(server).key).toMatch(KEY_PATTERN);
  });

  test('a stale preview shows the message, reloads the preview, and the next confirm uses the new movement id', async ({ page }) => {
    const server = newServer({ staleOnce: true });
    await open(page, server, '/daily/captain-5?tab=supply&section=reconcile');
    await page.getByRole('button', { name: 'Confirm reconciliation' }).click();
    await page.getByTestId('ems-confirm-reconcile').getByRole('button', { name: 'Save reconciliation' }).click();
    await expect(page.getByTestId('ems-reconcile-notice')).toContainText('New stock changes were recorded since you opened this preview.');
    await expect(page.getByTestId('ems-reconcile-notice')).toContainText('Review it, then confirm again.');
    await expect(page.getByTestId('ems-confirm-reconcile')).toHaveCount(0);
    expect(lastWrite(server).body).toEqual({ expected_last_movement_id: 4 });
    await expect.poll(() => server.reads.filter((read) => read.path === '/supply/reconciliation').length).toBeGreaterThanOrEqual(2);

    await page.getByRole('button', { name: 'Confirm reconciliation' }).click();
    await page.getByTestId('ems-confirm-reconcile').getByRole('button', { name: 'Save reconciliation' }).click();
    await expect(page.getByTestId('ems-reconcile-notice')).toContainText('Reconciliation saved');
    expect(lastWrite(server).body).toEqual({ expected_last_movement_id: 9 });
    const confirms = server.writes.filter((write) => write.path === '/supply/reconciliation');
    expect(confirms[0].key).not.toBe(confirms[1].key);
  });
});

test.describe('reorder, movements and snapshots', () => {
  test('"Reorder recommended" is not "Order placed"; recording an order sends the packages and the unverified supplier details', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=supply&section=reorder');
    const cards = page.getByTestId('ems-advisory');
    await expect(cards).toHaveCount(2);
    await expect(cards.first()).toContainText('Reorder recommended');
    await expect(cards.first()).not.toContainText('Order placed');
    await expect(cards.first()).toContainText('Historical, unverified');
    await expect(cards.first()).toContainText("1 box (the order sheet's amount: 1BX = 200U)");
    await expect(cards.nth(1)).toContainText('Reorder recommended');
    await expect(cards.nth(1)).toContainText('Order placed');
    await expect(page.getByTestId('ems-advice-note')).toContainText('A recommendation is not an order.');

    await cards.first().getByRole('button', { name: 'Record order placed for Gauze 4x4' }).click();
    const form = page.getByTestId('ems-order-form');
    await expect(form.getByLabel(/Packages ordered/)).toHaveValue('1');
    await form.getByLabel(/Packages ordered/).fill('2');
    await form.getByRole('button', { name: 'Record order placed' }).click();
    await expect.poll(() => lastWrite(server)?.path).toBe('/supply/orders');
    expect(lastWrite(server).body).toEqual({ supply_item_id: 71, packages: 2, supplier_code: 'BT', item_number: '100-A' });
    expect(lastWrite(server).key).toMatch(KEY_PATTERN);

    await page.getByRole('button', { name: 'Mark order for Tape 1 inch received' }).click();
    await page.getByRole('button', { name: 'Mark received' }).last().click();
    await expect.poll(() => lastWrite(server)?.path).toBe('/supply/orders/5/receive');
  });

  test('movements are listed newest first, and snapshots open with Excel and PDF links', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=supply&section=movements');
    const rows = page.getByTestId('ems-movement');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Gauze 4x4');
    await expect(rows.first()).toContainText('Issued -16');
    await expect(rows.nth(1)).toContainText('Received +48');

    await page.getByRole('button', { name: 'Snapshots' }).click();
    await expect(page.getByTestId('ems-snapshot')).toHaveCount(2);
    await page.getByRole('button', { name: 'View Physical count version 3' }).click();
    const detail = page.getByTestId('ems-snapshot-detail');
    await expect(detail).toContainText('Physical count, version 3');
    await expect(detail.getByTestId('ems-snapshot-line').first()).toContainText('Counted');
    await expect(detail.getByTestId('ems-snapshot-line').nth(1)).toContainText('Not counted');
    await expect(detail.getByRole('link', { name: 'Download Excel' })).toHaveAttribute('href', '/ems/api/supply/snapshots/s-prev/export/xlsx');
    await expect(detail.getByRole('link', { name: 'Download PDF' })).toHaveAttribute('href', '/ems/api/supply/snapshots/s-prev/export/pdf');
  });
});

// ---------------------------------------------------------------------------- polling

test('a visible page refreshes the count and the list once a minute, not more', async ({ page }) => {
  const server = newServer();
  await page.clock.install();
  await open(page, server, '/daily/captain-5?tab=rescue');
  await expect(page.getByTestId('ems-request-row')).toHaveCount(2);
  const before = server.reads.filter((read) => read.path === '/requests').length;
  await page.clock.fastForward(61_000);
  await expect.poll(() => server.reads.filter((read) => read.path === '/requests').length).toBe(before + 2);
  await page.clock.fastForward(5_000);
  await page.waitForTimeout(300);
  expect(server.reads.filter((read) => read.path === '/requests').length).toBe(before + 2);
});

// ---------------------------------------------------------------------------- phone

test.describe('phone layout', () => {
  test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

  test('the lists and the request sheet fit the screen and the touch targets are at least 44px', async ({ page }) => {
    await open(page, newServer({ permissions: PERMS_ADMIN }), `/daily/captain-5?tab=rescue&request=${R1}`);
    await expect(sheet(page)).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, 'ems-phone-request-sheet');
    for (const name of ['Issue', 'Prepare', 'Close request']) {
      const box = await sheet(page).getByRole('button', { name, exact: true }).boundingBox();
      expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
    }
    await sheet(page).getByRole('button', { name: 'Issue', exact: true }).click();
    await noHorizontalOverflow(page);
    await shot(page, 'ems-phone-issue-form');
    await page.keyboard.press('Escape');
    await shot(page, 'ems-phone-rescue-list');
    await noHorizontalOverflow(page);

    await page.getByRole('tab', { name: 'Supply Room' }).click();
    await expect(page.getByTestId('ems-supply-stock')).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, 'ems-phone-supply-stock');
    await page.getByRole('button', { name: 'Reconcile', exact: true }).click();
    await expect(page.getByTestId('ems-reconcile-row').first()).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, 'ems-phone-reconcile');
    await page.getByRole('tab', { name: /Rescue Inventory/ }).click();
    await page.getByRole('button', { name: 'Unit statistics' }).click();
    await expect(page.getByTestId('ems-statistics')).toBeVisible();
    await noHorizontalOverflow(page);
    await shot(page, 'ems-phone-statistics');
  });
});
