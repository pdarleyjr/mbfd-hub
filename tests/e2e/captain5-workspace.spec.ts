import { expect, test, type Page, type Route } from '@playwright/test';
import type {
  ActingRequest, ActingState, Captain5Access, HandoffNote, InspectionView, IssueDetail, OnDuty, RescueDaily, RescueTruck, RescueTruckChange,
} from '../../resources/js/daily-checkout/src/components/captain5/types';
import type { PostedUpdate } from '../../resources/js/daily-checkout/src/components/department-updates/types';

/**
 * Captain 5 EMS Operations Center (member page), every API response mocked.
 * Server contract: /api/captain5/* (summary, workspace, issues, issues/{id}, issues/{id}/actions,
 * issues/{id}/messages, inspections/{id}, handoff, acting-requests*, department-updates*) and
 * /api/captain5/communications/* for the Messages tab.
 */

const KEY_PATTERN = /^[A-Za-z0-9-]{8,48}$/;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();
const inHours = (hours: number): string => new Date(Date.now() + hours * 3_600_000).toISOString();
const ymd = (offsetDays: number): string => {
  const date = new Date(Date.now() + offsetDays * 86_400_000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

/** Everyone who can open the page can do everything on it; `act` is only a hint the page ignores. */
const ACCESS_ACT: Captain5Access = {
  view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)',
};
const ACCESS_ADMIN: Captain5Access = { ...ACCESS_ACT, configure: true, authority: 'administrator', authority_label: 'Administrator' };

type Duty = Omit<OnDuty, 'duty_date'>;
const bidHolder = (shift: OnDuty['shift'] = 'B', name = 'Alex Rivera'): Duty => ({ shift, source: 'bid_holder', resolved: true, name, rank: 'Capt.', acting_until: null, acting_request_id: null });
const acting = (shift: OnDuty['shift'], name: string, until = inHours(10)): Duty => ({ shift, source: 'acting', resolved: true, name, rank: 'Lt.', acting_until: until, acting_request_id: 8 });
const UNSET: Duty = { shift: null, source: 'unknown', resolved: false, name: null, rank: null, acting_until: null, acting_request_id: null };
/** 09:14 ET on a fixed day, so the note's clock text is the same wherever the test runs. */
const ROSTER_AS_OF = '2026-10-30T13:14:15+00:00';
const rosterOfficer = (name = 'Jordan Lee', identity: 'linked' | 'unlinked' = 'linked'): Duty => ({ ...bidHolder('B', name), source: 'telestaff', as_of: ROSTER_AS_OF, freshness: 'current', identity, reason: null });
const seatAwaiting = (freshness: 'awaiting' | 'delayed'): Duty => ({ ...bidHolder('B'), as_of: null, freshness, identity: null, reason: null });
const rosterUnknown = (reason: 'conflict' | 'needs_review' | 'no_working_row'): Duty => ({ ...UNSET, shift: 'B', as_of: ROSTER_AS_OF, freshness: 'current', identity: null, reason });

const noActing = (overrides: Partial<ActingState> = {}): ActingState => ({
  can_approve: false, mine: null, pending: [], choices: { '12h': inHours(12), '24h': inHours(24) }, ...overrides,
});
const makeActing = (id: number, overrides: Partial<ActingRequest> = {}): ActingRequest => ({
  id, kind: 'act', requested_by: 'Jordan Lee', name: 'Jordan Lee', rank: 'Lt.', duration: '12h', shift: 'C', ends_at: inHours(12), requested_at: minutesAgo(5), own: false, ...overrides,
});

const makeUpdate = (id: number, overrides: Partial<PostedUpdate> = {}): PostedUpdate => ({
  id, title: `Update ${id}`, excerpt: 'Body text', status: 'published', posted_at: minutesAgo(30), expires_at: null,
  author: 'Capt. Alex Rivera', author_rank: 'Capt.', author_name: 'Alex Rivera', own: true, has_image: false, has_attachment: false, url: `/updates/${id}`, ...overrides,
});

/** Fields and file names of a multipart body. File bytes are not needed, only that the part was sent. */
function parseMultipart(raw: string): { fields: Record<string, string>; files: Record<string, string> } {
  const fields: Record<string, string> = {};
  const files: Record<string, string> = {};
  const part = /Content-Disposition: form-data; name="([^"]+)"(?:; filename="([^"]*)")?\r\n(?:Content-Type: [^\r\n]+\r\n)?\r\n([\s\S]*?)\r\n--/g;
  for (const match of raw.matchAll(part)) {
    if (match[2] !== undefined) files[match[1]] = match[2]; else fields[match[1]] = match[3];
  }
  return { fields, files };
}

const ACTIONS_REPORTED: IssueDetail['actions'] = ['acknowledge', 'ordered', 'available_for_pickup', 'replaced', 'dismiss'];

/** One apparatus per unit name, so the queue groups the way the server's apparatus ids would. */
const unitIds = new Map<string, number>();
const apparatusIdOf = (unit: string): number => {
  if (!unitIds.has(unit)) unitIds.set(unit, unitIds.size + 1);
  return unitIds.get(unit) as number;
};

function makeIssue(id: number, overrides: Partial<IssueDetail> = {}): IssueDetail {
  return {
    id, kind: 'rescue', apparatus_id: apparatusIdOf(overrides.unit ?? 'Rescue 1'), unit: 'Rescue 1', compartment: 'Medical bag', item: 'Pulse oximeter', issue_type: 'Missing',
    severity: 'routine', action_status: 'reported', action_label: 'Awaiting verification', open: true, details: {},
    expected_delivery: null, overdue: false, awaiting_verification: false, first_reported_at: minutesAgo(180),
    first_reported_by: 'Lt. Reporter', last_observed_at: minutesAgo(60), resolved_at: null, resolution_type: null,
    state_token: `reported:${id}0`, resolution_notes: null,
    observations: [{ inspection_id: 900 + id, observation: 'still_missing', reported_status: 'Missing', notes: 'Not in the bag', by: 'FF Smith', at: minutesAgo(180) }],
    events: [{ id: id * 100, type: 'reported', from: null, to: 'reported', actor: 'FF Smith', at: minutesAgo(180), member_message: null, internal_note: null, details: {}, inspection_id: 900 + id }],
    messages: [], can_post: true, can_post_internal: true, actions: ACTIONS_REPORTED, origin_inspection_id: 900 + id,
    ...overrides,
  };
}

const rescueDailies: RescueDaily[] = [
  { apparatus_id: 1, unit: 'Rescue 1', vehicle_number: '101', station: 'Station 1', status: 'In Service', daily: 'submitted', inspection_id: 901, submitted_at: minutesAgo(90), submitted_by: 'Lt. Alvarez', needs_approval: false, open_issues: 2 },
  { apparatus_id: 2, unit: 'Rescue 2', vehicle_number: '102', station: 'Station 2', status: 'In Service', daily: 'not_submitted', inspection_id: null, submitted_at: null, submitted_by: null, needs_approval: false, open_issues: 0 },
];

const handoffNote: HandoffNote = { id: 51, duty_date: ymd(0), author: 'Capt. Previous', authority: 'on_duty_captain5', body: 'Monitor in Rescue 3 is on order.', at: minutesAgo(600), acknowledged_by: null, acknowledged_at: null };

interface Server {
  summaryStatus: number;
  workspaceStatus: number;
  access: Captain5Access;
  onDuty: Duty;
  acting: ActingState;
  issues: IssueDetail[];
  closed: IssueDetail[];
  /** Left out of the workspace answer when undefined, like a response sent before they existed. */
  trucks?: RescueTruck[];
  truckChanges?: RescueTruckChange[];
  detailStatus: number;
  notes: HandoffNote[];
  updates: PostedUpdate[];
  actionBodies: Array<Record<string, unknown>>;
  messageBodies: Array<Record<string, unknown>>;
  handoffBodies: Array<Record<string, unknown>>;
  actingBodies: Array<Record<string, unknown>>;
  /** 'approve:7', 'reject:7', 'withdraw:7' with the body of each decision. */
  actingDecisions: Array<{ call: string; body: Record<string, unknown> }>;
  updateForms: Array<{ fields: Record<string, string>; files: Record<string, string> }>;
  takeDowns: number[];
  clarifications: Array<Record<string, unknown>>;
  historyQueries: string[];
  requests: string[];
  /** Return true after fulfilling to take over an action request. */
  onAction?: (route: Route, body: Record<string, unknown>, server: Server) => Promise<boolean>;
  onActing?: (route: Route, body: Record<string, unknown>, server: Server) => Promise<boolean>;
  onUpdatePost?: (route: Route, server: Server) => Promise<boolean>;
}

function newServer(overrides: Partial<Server> = {}): Server {
  return {
    summaryStatus: 200, workspaceStatus: 200, access: ACCESS_ACT,
    onDuty: bidHolder(), acting: noActing(),
    issues: [makeIssue(1), makeIssue(2, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine', severity: 'significant' })],
    closed: [], detailStatus: 200, notes: [handoffNote], updates: [],
    actionBodies: [], messageBodies: [], handoffBodies: [], actingBodies: [], actingDecisions: [], updateForms: [], takeDowns: [],
    clarifications: [], historyQueries: [], requests: [],
    ...overrides,
  };
}

const NEXT_STATE: Record<string, { status: string; actions: IssueDetail['actions'] }> = {
  acknowledge: { status: 'acknowledged', actions: ['ordered', 'available_for_pickup', 'replaced', 'dismiss'] },
  ordered: { status: 'action_pending', actions: ['ordered', 'update_delivery', 'available_for_pickup', 'replaced', 'dismiss'] },
  available_for_pickup: { status: 'replacement_available', actions: ['ordered', 'update_delivery', 'replaced', 'dismiss'] },
  replaced: { status: 'resolved', actions: ['reopen'] },
};

function applyAction(issue: IssueDetail, body: Record<string, unknown>): IssueDetail {
  const serial = Number(issue.state_token.split(':')[1]) + 1;
  // The status never changes for these two; only the history (and so the token) moves on.
  const token = `${issue.action_status}:${serial}`;
  if (body.action === 'send_to_logistics') {
    return {
      ...issue, state_token: token, actions: [], clearing_actions: ['bring_back_to_captain5'], captain5_cleared_at: new Date().toISOString(), captain5_cleared_by: 'Alex Rivera',
      events: [...issue.events, { id: serial, type: 'cleared_from_captain5', from: issue.action_status, to: issue.action_status, actor: 'Alex Rivera', at: new Date().toISOString(), member_message: null, internal_note: null, details: {}, inspection_id: null }],
    };
  }
  if (body.action === 'bring_back_to_captain5') {
    return { ...issue, state_token: token, actions: ACTIONS_REPORTED, clearing_actions: ['send_to_logistics'], captain5_cleared_at: null, captain5_cleared_by: null };
  }
  const next = NEXT_STATE[String(body.action)];
  if (!next) return issue;
  return {
    ...issue, action_status: next.status, state_token: `${next.status}:${serial}`, actions: next.actions, open: next.status !== 'resolved',
    details: { ...issue.details, ...(body.vendor_reference ? { vendor_reference: String(body.vendor_reference) } : {}) },
    expected_delivery: typeof body.expected_delivery === 'string' ? body.expected_delivery : issue.expected_delivery,
  };
}

const inQueue = (issue: IssueDetail): boolean => issue.open && !issue.captain5_cleared_at;

const countsOf = (issues: IssueDetail[]) => {
  const open = issues.filter(inQueue);
  return {
    open: open.length,
    needs_acknowledgement: open.filter((i) => i.action_status === 'reported').length,
    ordered: open.filter((i) => i.action_status === 'action_pending').length,
    ready_for_pickup: open.filter((i) => i.action_status === 'replacement_available').length,
    overdue: open.filter((i) => i.overdue).length,
    awaiting_verification: open.filter((i) => i.awaiting_verification).length,
    significant: open.filter((i) => i.severity === 'significant').length,
    rescue: open.filter((i) => i.kind === 'rescue').length,
    medical: open.filter((i) => i.kind === 'medical').length,
  };
};

const stations = [1, 2].map((number) => ({
  id: 40 + number, name: `Station ${number}`, address: '1 Test Street', city: 'Miami Beach', state: 'FL', zip_code: '33139', phone: '305-555-0100',
  station_number: number, is_active: true, created_at: '2026-08-31T00:00:00.000000Z', updated_at: '2026-08-31T00:00:00.000000Z',
}));

const inspection: InspectionView = {
  id: 901, kind: 'rescue', unit: 'Rescue 1', submitted_by: 'Lt. Alvarez', shift: 'B', submitted_at: minutesAgo(90), acceptance: 'accepted', scope_note: null,
  compartments: [{ id: 'c1', name: 'Medical bag', items: [{ id: 'i1', name: 'Pulse oximeter', status: 'Missing', notes: 'Not in the bag' }, { id: 'i2', name: 'Stethoscope', status: 'Present', notes: null }] }],
};

async function readBody(route: Route): Promise<Record<string, unknown>> {
  return JSON.parse(route.request().postData() ?? '{}') as Record<string, unknown>;
}

async function handleIssueAction(route: Route, server: Server, id: number): Promise<void> {
  const body = await readBody(route);
  server.actionBodies.push(body);
  if (server.onAction && await server.onAction(route, body, server)) return;
  const index = server.issues.findIndex((issue) => issue.id === id);
  const updated = applyAction(server.issues[index], body);
  server.issues[index] = updated;
  await route.fulfill({ json: { issue: updated } });
}

const actingPayload = (server: Server) => ({ on_duty: { duty_date: ymd(0), ...server.onDuty }, acting: server.acting });

async function handleActing(route: Route, server: Server, pathname: string): Promise<void> {
  if (pathname === '/api/captain5/acting-requests') {
    const body = await readBody(route);
    server.actingBodies.push(body);
    if (server.onActing && await server.onActing(route, body, server)) return;
    const kind = body.kind === 'end' ? 'end' : 'act';
    const hours = body.duration === '24h' ? 24 : 12;
    const request = makeActing(100 + server.actingBodies.length, {
      kind, requested_by: 'Alex Rivera', name: kind === 'act' ? 'Alex Rivera' : null, rank: kind === 'act' ? 'Capt.' : null,
      duration: kind === 'act' ? (body.duration as ActingRequest['duration']) : null, shift: 'B',
      ends_at: kind === 'act' ? (typeof body.ends_at === 'string' ? new Date(body.ends_at).toISOString() : inHours(hours)) : null, own: true,
    });
    server.acting = { ...server.acting, mine: request };
    return route.fulfill({ status: 201, json: actingPayload(server) });
  }
  const decision = /^\/api\/captain5\/acting-requests\/(\d+)\/(approve|reject|withdraw)$/.exec(pathname);
  if (!decision) return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
  const id = Number(decision[1]);
  const body = await readBody(route);
  server.actingDecisions.push({ call: `${decision[2]}:${id}`, body });
  if (decision[2] === 'reject' && String(body.note ?? '').trim() === '') {
    return route.fulfill({ status: 422, json: { message: 'The note field is required.', errors: { note: ['The note field is required.'] } } });
  }
  const found = [...server.acting.pending, ...(server.acting.mine ? [server.acting.mine] : [])].find((request) => request.id === id);
  if (decision[2] === 'approve' && found) {
    server.onDuty = found.kind === 'act'
      ? { shift: 'B', source: 'acting', resolved: true, name: found.name, rank: found.rank, acting_until: found.ends_at, acting_request_id: found.id }
      : bidHolder();
  }
  server.acting = { ...server.acting, pending: server.acting.pending.filter((request) => request.id !== id), mine: server.acting.mine?.id === id ? null : server.acting.mine };
  return route.fulfill({ json: actingPayload(server) });
}

async function handleUpdates(route: Route, server: Server, pathname: string): Promise<void> {
  const method = route.request().method();
  const base = '/api/captain5/department-updates';
  if (pathname === base && method === 'GET') return route.fulfill({ json: { updates: server.updates } });
  if (pathname === base && method === 'POST') {
    if (server.onUpdatePost && await server.onUpdatePost(route, server)) return;
    const form = parseMultipart(route.request().postData() ?? '');
    server.updateForms.push(form);
    const update = makeUpdate(500 + server.updateForms.length, {
      title: form.fields.title, excerpt: form.fields.body, expires_at: form.fields.expires_at ? `${form.fields.expires_at}T04:00:00.000000Z` : null,
      has_image: 'image' in form.files, has_attachment: 'attachment' in form.files,
    });
    server.updates = [update, ...server.updates];
    return route.fulfill({ status: 201, json: { update } });
  }
  const takeDown = /^\/api\/captain5\/department-updates\/(\d+)\/take-down$/.exec(pathname);
  if (takeDown && method === 'POST') {
    const id = Number(takeDown[1]);
    server.takeDowns.push(id);
    server.updates = server.updates.map((update) => (update.id === id ? { ...update, status: 'taken_down' as const } : update));
    return route.fulfill({ json: { update: server.updates.find((update) => update.id === id) } });
  }
  return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
}

async function handleCaptain5(route: Route, server: Server, pathname: string, search: URLSearchParams): Promise<void> {
  const method = route.request().method();
  const issueMatch = /^\/api\/captain5\/issues\/(\d+)(?:\/(actions|messages))?$/.exec(pathname);
  if (pathname === '/api/captain5/summary') {
    return server.summaryStatus === 200
      ? route.fulfill({ json: { access: server.access, on_duty: { duty_date: ymd(0), ...server.onDuty }, open_issues: server.issues.filter(inQueue).length } })
      : route.fulfill({ status: server.summaryStatus, json: { message: 'Forbidden' } });
  }
  if (pathname === '/api/captain5/workspace') {
    if (server.workspaceStatus !== 200) return route.fulfill({ status: server.workspaceStatus, json: { message: 'Forbidden' } });
    return route.fulfill({
      json: {
        access: server.access, on_duty: { duty_date: ymd(0), ...server.onDuty }, duty_date: ymd(0), counts: countsOf(server.issues),
        rescue_dailies: rescueDailies, issues: server.issues.filter(inQueue), handoff: { duty_date: ymd(0), notes: server.notes },
        cleared_issues: server.issues.filter((i) => i.open && i.captain5_cleared_at),
        ...(server.trucks ? { rescue_trucks: server.trucks } : {}),
        ...(server.truckChanges ? { rescue_truck_changes: server.truckChanges } : {}),
        acting: server.acting, generated_at: new Date().toISOString(),
      },
    });
  }
  if (pathname === '/api/captain5/issues' && method === 'GET') {
    if (search.get('status') === 'closed') server.historyQueries.push(search.get('q') ?? '');
    const list = search.get('status') === 'closed'
      ? server.closed.filter((issue) => !search.get('q') || issue.item.toLowerCase().includes(String(search.get('q')).toLowerCase()))
      : server.issues.filter(inQueue);
    return route.fulfill({ json: { issues: list } });
  }
  if (issueMatch && method === 'GET') {
    const found = [...server.issues, ...server.closed].find((issue) => issue.id === Number(issueMatch[1]));
    if (server.detailStatus !== 200 || !found) return route.fulfill({ status: server.detailStatus === 200 ? 404 : server.detailStatus, json: { message: 'Not available.' } });
    return route.fulfill({ json: { issue: found } });
  }
  if (issueMatch?.[2] === 'actions') return handleIssueAction(route, server, Number(issueMatch[1]));
  if (issueMatch?.[2] === 'messages') {
    const body = await readBody(route);
    server.messageBodies.push(body);
    const index = server.issues.findIndex((issue) => issue.id === Number(issueMatch[1]));
    server.issues[index] = { ...server.issues[index], messages: [...server.issues[index].messages, { id: 700 + server.messageBodies.length, body: String(body.body), visibility: String(body.visibility), author: 'Captain 5 · Alex Rivera', context: 'captain5', own: true, at: new Date().toISOString() }] };
    return route.fulfill({ status: 201, json: { issue: server.issues[index] } });
  }
  const inspectionMatch = /^\/api\/captain5\/inspections\/(\d+)(\/clarification)?$/.exec(pathname);
  if (inspectionMatch?.[2]) {
    server.clarifications.push(await readBody(route));
    return route.fulfill({ status: 201, json: { message_id: 1 } });
  }
  if (inspectionMatch) {
    const medical = Number(inspectionMatch[1]) === 902;
    return route.fulfill({ json: { inspection: { ...inspection, id: Number(inspectionMatch[1]), kind: medical ? 'medical' : 'rescue', unit: medical ? 'Engine 1' : 'Rescue 1', scope_note: medical ? 'Medical equipment only. Fleet manages the rest of this checklist.' : null } } });
  }
  if (pathname === '/api/captain5/handoff' && method === 'POST') {
    const body = await readBody(route);
    server.handoffBodies.push(body);
    server.notes = [{ ...handoffNote, id: 60, author: 'Capt. Alex Rivera', body: String(body.body), at: new Date().toISOString() }, ...server.notes];
    return route.fulfill({ status: 201, json: { handoff: { duty_date: ymd(0), notes: server.notes } } });
  }
  const ack = /^\/api\/captain5\/handoff\/(\d+)\/acknowledge$/.exec(pathname);
  if (ack) {
    server.notes = server.notes.map((note) => (note.id === Number(ack[1]) ? { ...note, acknowledged_by: 'Capt. Alex Rivera', acknowledged_at: new Date().toISOString() } : note));
    return route.fulfill({ json: { handoff: { duty_date: ymd(0), notes: server.notes } } });
  }
  if (pathname.startsWith('/api/captain5/acting-requests')) return handleActing(route, server, pathname);
  if (pathname.startsWith('/api/captain5/department-updates')) return handleUpdates(route, server, pathname);
  if (pathname === '/api/captain5/communications/summary') return route.fulfill({ json: { unread_count: 3, latest_activity_at: null } });
  if (pathname === '/api/captain5/communications/posts') return route.fulfill({ json: { data: [], pinned: [], next_before: null, unread_count: 3 } });
  return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
}

async function mockApi(page: Page, server: Server): Promise<void> {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    server.requests.push(`${route.request().method()} ${url.pathname}`);
    if (url.pathname === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 401, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_401, employee_number: 'E401', name: 'Alex Rivera', rank: 'Captain' },
          offline: { security_version: 1 },
          session: { authenticated: true },
          authorization: { capabilities: { command_300_access: false } },
        },
      });
    }
    if (url.pathname === '/api/public/stations') return route.fulfill({ json: { stations } });
    if (url.pathname.startsWith('/api/captain5/')) return handleCaptain5(route, server, url.pathname, url.searchParams);
    if (url.pathname.startsWith('/api/command-communications/')) {
      return url.pathname.endsWith('/posts') ? route.fulfill({ json: { data: [], pinned: [], next_before: null, unread_count: 0 } }) : route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    }
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${url.pathname}` } });
  });
}

async function open(page: Page, server: Server, path = '/daily/captain-5'): Promise<void> {
  await mockApi(page, server);
  await page.goto(path);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const widths = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

/** Set CAPTAIN5_SHOTS_DIR to keep review screenshots of the responsive run; otherwise this does nothing. */
async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.CAPTAIN5_SHOTS_DIR;
  if (dir) await page.screenshot({ path: `${dir}/${name}.png`, fullPage: false });
}

const sheet = (page: Page) => page.getByRole('dialog');
const actionForm = (page: Page) => page.getByTestId('captain5-action-form');
const lastAction = (server: Server) => server.actionBodies[server.actionBodies.length - 1];
const unitRow = (page: Page, unit: string) => page.getByTestId('captain5-unit-row').filter({ hasText: unit });

/** The queue opens with one row per unit; this opens every unit that is still collapsed. */
async function expandUnits(page: Page): Promise<void> {
  await expect(page.getByTestId('captain5-unit-row').first()).toBeVisible();
  const collapsed = page.locator('[data-testid="captain5-unit-row"][aria-expanded="false"]');
  while (await collapsed.count() > 0) await collapsed.first().click();
}

test.describe('station list card', () => {
  test('appears with the open issue count only when the summary answers 200', async ({ page }) => {
    await open(page, newServer(), '/daily/stations');
    const card = page.getByTestId('daily-captain5-card');
    await expect(card).toBeVisible();
    await expect(card).toContainText('Captain 5');
    await expect(card).toContainText('EMS');
    await expect(page.getByTestId('daily-captain5-open')).toHaveText('2 open issues');
    await expect(card.getByText('Open Captain 5')).toBeVisible();
    await expect(page.getByTestId('daily-captain5-duty')).toHaveText('B Shift Captain 5: Alex Rivera');
    await expect(page.getByTestId('daily-captain5-duty').locator('.hub-tag')).toHaveClass(/hub-tag--info/);
    await expect(card).toHaveAttribute('href', '/daily/captain-5');
  });

  test('is hidden for a 403 and when the summary cannot be reached, without blocking the stations', async ({ page }) => {
    await open(page, newServer({ summaryStatus: 403 }), '/daily/stations');
    await expect(page.getByTestId('daily-station-grid')).toBeVisible();
    await expect(page.getByText('Station 1', { exact: false }).first()).toBeVisible();
    await expect(page.getByTestId('daily-captain5-card')).toHaveCount(0);

    const offline = await page.context().newPage();
    await mockApi(offline, newServer());
    // Registered last, so it takes precedence over the mock above.
    await offline.route('**/api/captain5/summary', (route) => route.abort('failed'));
    await offline.goto('/daily/stations');
    await expect(offline.getByTestId('daily-station-grid')).toBeVisible();
    await expect(offline.getByTestId('daily-captain5-card')).toHaveCount(0);
  });
});

test.describe('where the Captain 5 name comes from', () => {
  const cardDuty = (page: Page) => page.getByTestId('daily-captain5-duty');
  const cardNote = (page: Page) => page.getByTestId('daily-captain5-duty-note');
  const pageLine = (page: Page) => page.getByTestId('captain5-duty-line');
  const pageNote = (page: Page) => page.getByTestId('captain5-duty-note');

  test('an old answer with none of the new fields shows no note on the card or the page', async ({ page }) => {
    await open(page, newServer(), '/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift Captain 5: Alex Rivera');
    await expect(cardNote(page)).toHaveCount(0);

    await page.goto('/daily/captain-5');
    await expect(pageLine(page)).toHaveText('B Shift Captain 5: Alex Rivera');
    await expect(pageNote(page)).toHaveCount(0);
  });

  test('an approved acting member shows no note', async ({ page }) => {
    await open(page, newServer({ onDuty: { ...acting('C', 'Jordan Lee'), as_of: null, freshness: 'not_applicable', identity: null, reason: null } }));
    await expect(pageLine(page)).toHaveText(/^C Shift Acting Captain 5: Jordan Lee until /);
    await expect(pageNote(page)).toHaveCount(0);
  });

  test('the roster keeps the Tag and "Your access" as they are and adds when it was updated', async ({ page }) => {
    await open(page, newServer({ onDuty: rosterOfficer() }));
    await expect(pageLine(page)).toHaveText('B Shift Captain 5: Jordan Lee');
    await expect(pageLine(page).locator('.hub-tag')).toHaveClass(/hub-tag--info/);
    await expect(pageNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');
    await expect(page.getByTestId('captain5-authority')).toHaveText('Your access: Captain 5 (on duty)');

    await page.goto('/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift Captain 5: Jordan Lee');
    await expect(cardNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');
  });

  test('a person not matched to a Hub member says so', async ({ page }) => {
    await open(page, newServer({ onDuty: rosterOfficer('LEE, JORDAN', 'unlinked') }));
    await expect(pageNote(page)).toHaveText('TeleStaff roster. Not matched to a Hub member.');
  });

  test('before the roster arrives and once it is late the seat says so', async ({ page }) => {
    await open(page, newServer({ onDuty: seatAwaiting('awaiting') }));
    await expect(pageLine(page)).toHaveText('B Shift Captain 5: Alex Rivera');
    await expect(pageNote(page)).toHaveText("Bid seat. Today's roster has not arrived.");

    const late = await page.context().newPage();
    await open(late, newServer({ onDuty: seatAwaiting('delayed') }));
    await expect(pageNote(late)).toHaveText("Bid seat. Today's roster is late.");
  });

  test('a conflict: the Tag says the roster needs review and only the page links to the Daily Roster', async ({ page }) => {
    await open(page, newServer({ onDuty: rosterUnknown('conflict') }));
    await expect(pageLine(page)).toHaveText('B Shift Captain 5: roster needs review');
    await expect(pageLine(page).locator('.hub-tag')).toHaveClass(/hub-tag--warning/);
    await expect(pageNote(page).getByRole('link', { name: 'Check the Daily Roster.' })).toHaveAttribute('href', '/daily-roster');

    await page.goto('/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift Captain 5: roster needs review');
    await expect(cardNote(page)).toHaveText('Check the Daily Roster.');
    await expect(page.getByTestId('daily-captain5-card').locator('a')).toHaveCount(0);
  });

  test('the card updates when the page comes back into view and survives a failed refresh', async ({ page }) => {
    const server = newServer({ onDuty: seatAwaiting('awaiting') });
    await open(page, server, '/daily/stations');
    await expect(cardNote(page)).toHaveText("Bid seat. Today's roster has not arrived.");

    server.onDuty = rosterOfficer();
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(cardDuty(page)).toHaveText('B Shift Captain 5: Jordan Lee');
    await expect(cardNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');

    server.summaryStatus = 500;
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(cardDuty(page)).toHaveText('B Shift Captain 5: Jordan Lee');

    // A refusal is different: the server has said this member may not see Captain 5 any more.
    server.summaryStatus = 403;
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(page.getByTestId('daily-captain5-card')).toHaveCount(0);
  });
});

test.describe('workspace shell', () => {
  test('shows the shift line, authority and the tabs, with no Duty tab and no view-only mode', async ({ page }) => {
    await open(page, newServer());
    await expect(page.getByRole('heading', { name: 'Captain 5 · EMS Operations', level: 1 })).toBeVisible();
    await expect(page.getByTestId('captain5-duty-line')).toHaveText('B Shift Captain 5: Alex Rivera');
    await expect(page.getByTestId('captain5-authority')).toContainText('Captain 5 (on duty)');
    const tabs = page.getByRole('tablist', { name: 'Captain 5 workspace' });
    await expect(tabs.getByRole('tab')).toHaveText([/Today/, /Issues\s*2/, /Truck Changes/, /Messages\s*3/, /Department Updates/, /History/]);
    await expect(tabs.getByRole('tab', { name: 'Duty' })).toHaveCount(0);
    await expect(page.getByText('View only')).toHaveCount(0);
  });

  for (const [shift, tone] of [['A', 'success'], ['B', 'info'], ['C', 'danger']] as const) {
    test(`the shift line is coloured by shift and always says the shift: ${shift} is ${tone}`, async ({ page }) => {
      await open(page, newServer({ onDuty: bidHolder(shift, 'Oscar Lima') }));
      const line = page.getByTestId('captain5-duty-line');
      await expect(line).toHaveText(`${shift} Shift Captain 5: Oscar Lima`);
      await expect(line.locator('.hub-tag')).toHaveClass(new RegExp(`hub-tag--${tone}`));
    });
  }

  test('an approved acting member reads "Acting Captain 5 ... until <time>" in the shift colour; unresolved is neutral', async ({ page }) => {
    await open(page, newServer({ onDuty: acting('C', 'Jordan Lee') }));
    const line = page.getByTestId('captain5-duty-line');
    await expect(line).toHaveText(/^C Shift Acting Captain 5: Jordan Lee until \S+/);
    await expect(line.locator('.hub-tag')).toHaveClass(/hub-tag--danger/);

    const unresolved = await page.context().newPage();
    await open(unresolved, newServer({ onDuty: UNSET }));
    const none = unresolved.getByTestId('captain5-duty-line');
    await expect(none).toHaveText('Shift Captain 5 not set');
    await expect(none.locator('.hub-tag')).not.toHaveClass(/hub-tag--(success|info|danger|warning)/);
  });

  test('tabs follow the URL, support the keyboard and a deep link opens the right tab', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=issues');
    await expect(page.getByRole('tab', { name: /Issues/ })).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('tab', { name: /Issues/ }).press('ArrowRight');
    await expect(page.getByRole('tab', { name: /Truck Changes/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(/tab=trucks/);
    await page.getByRole('tab', { name: /Truck Changes/ }).press('ArrowRight');
    await expect(page.getByRole('tab', { name: /Messages/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(/tab=messages/);
    await page.getByRole('tab', { name: 'Today' }).click();
    await expect(page).not.toHaveURL(/tab=/);
  });

  test('the retired Replacements tab is gone and an old link lands on Today', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=replacements');
    await expect(page.getByRole('tab', { name: /Replacements/ })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Today' })).toHaveAttribute('aria-selected', 'true');
  });

  test('a 403 from the workspace shows the access-denied state and nothing protected', async ({ page }) => {
    await open(page, newServer({ workspaceStatus: 403 }));
    await expect(page.getByTestId('captain5-access-denied')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Captain 5 is not available to your account' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to stations' })).toBeVisible();
    await expect(page.getByRole('tablist')).toHaveCount(0);
  });
});

test.describe('Today', () => {
  test('counts open the Issues tab already filtered, and Rescue dailies open the inspection view', async ({ page }) => {
    await open(page, newServer());
    await expect(page.getByTestId('captain5-count-needs_acknowledgement')).toContainText('2');
    await page.getByTestId('captain5-count-significant').click();
    await expect(page).toHaveURL(/tab=issues/);
    await expect(page).toHaveURL(/filter=significant/);
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(1);
    await expect(page.getByTestId('captain5-issue-row')).toContainText('Epinephrine');
    await expect(page.getByRole('group', { name: 'Filter by status' }).getByRole('button', { name: /^Significant damage/ })).toHaveAttribute('aria-pressed', 'true');
  });

  test('Rescue dailies show submitted and not submitted, and a submitted one opens the read-only inspection', async ({ page }) => {
    await open(page, newServer());
    const rows = page.getByTestId('captain5-rescue-row');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('Daily submitted');
    await expect(rows.nth(0)).toContainText('2 open issues');
    await expect(rows.nth(1)).toContainText('Daily not submitted');
    await expect(rows.nth(1)).not.toHaveJSProperty('tagName', 'BUTTON');
    await rows.nth(0).click();
    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page)).toContainText('Pulse oximeter');
    await expect(sheet(page)).toContainText('Missing');
    await expect(page.getByTestId('captain5-clarification')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(sheet(page)).toHaveCount(0);
  });

  test('a clarification request posts the text with a message id; medical inspections show the scope note', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await page.getByTestId('captain5-rescue-row').first().click();
    await page.getByLabel('What do you need to know?').fill('Which compartment was the oximeter in?');
    await page.getByRole('button', { name: 'Send request' }).click();
    await expect(page.getByText('Clarification request sent.')).toBeVisible();
    expect(server.clarifications).toHaveLength(1);
    expect(server.clarifications[0]).toMatchObject({ body: 'Which compartment was the oximeter in?' });
    expect(String(server.clarifications[0].client_message_id)).toMatch(UUID_PATTERN);

    await page.goto('/daily/captain-5?tab=issues&issue=2');
    await sheet(page).getByRole('button', { name: 'View originating inspection' }).click();
    await expect(page.getByTestId('captain5-scope-note')).toHaveText('Medical equipment only. Fleet manages the rest of this checklist.');
    // Engine/Ladder general threads are Fleet's too: Captain 5 messages on the medical issue instead.
    await expect(page.getByTestId('captain5-clarification')).toHaveCount(0);
  });

  test('handoff: a note is written with a request id, and a note from someone else can be acknowledged', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    const handoff = page.getByTestId('captain5-handoff');
    await expect(handoff).toContainText('Monitor in Rescue 3 is on order.');
    await expect(handoff).toContainText('Not yet acknowledged');
    await handoff.getByRole('button', { name: 'Acknowledge handoff' }).click();
    await expect(handoff).toContainText('Acknowledged by Capt. Alex Rivera');

    await handoff.getByLabel('Handoff note for the next Captain 5').fill('Rescue 2 radio swapped.');
    await handoff.getByRole('button', { name: 'Save handoff' }).click();
    await expect(handoff.getByText('Handoff saved.')).toBeVisible();
    expect(server.handoffBodies).toHaveLength(1);
    expect(server.handoffBodies[0]).toMatchObject({ body: 'Rescue 2 radio swapped.' });
    expect(String(server.handoffBodies[0].client_request_id)).toMatch(KEY_PATTERN);
    // Your own note is acknowledged by the next Captain, not by you.
    await expect(handoff.getByTestId('captain5-handoff-note').first().getByRole('button', { name: 'Acknowledge handoff' })).toHaveCount(0);
  });
});

test.describe('Issues and the detail sheet', () => {
  test('rows show unit, item, compartment, status text and markers; filters narrow the queue', async ({ page }) => {
    const server = newServer({
      issues: [
        makeIssue(1),
        makeIssue(2, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine', severity: 'significant' }),
        makeIssue(3, { item: 'Cervical collar', action_status: 'action_pending', expected_delivery: ymd(-3), overdue: true, details: { vendor_reference: 'PO-77' }, state_token: 'action_pending:30' }),
        makeIssue(4, { unit: 'Rescue 3', item: 'Suction unit', awaiting_verification: true, action_status: 'acknowledged', state_token: 'acknowledged:40' }),
      ],
    });
    await open(page, server, '/daily/captain-5?tab=issues');
    await expandUnits(page);
    const rows = page.getByTestId('captain5-issue-row');
    await expect(rows).toHaveCount(4);
    await expect(rows.filter({ hasText: 'Epinephrine' })).toContainText('Significant damage');
    await expect(rows.filter({ hasText: 'Epinephrine' })).toContainText('Medical');
    await expect(rows.filter({ hasText: 'Epinephrine' })).toContainText('Engine 1');
    await expect(rows.filter({ hasText: 'Pulse oximeter' })).toContainText('Needs acknowledgement');
    await expect(rows.filter({ hasText: 'Suction unit' })).toContainText('Awaiting verification');
    // Overdue sorts first and says so in text.
    await expect(rows.first()).toContainText('Overdue');

    await page.getByRole('group', { name: 'Filter by unit type' }).getByRole('button', { name: /^Medical/ }).click();
    await expect(rows).toHaveCount(1);
    await page.getByRole('group', { name: 'Filter by unit type' }).getByRole('button', { name: /^All/ }).click();
    await page.getByRole('group', { name: 'Filter by status' }).getByRole('button', { name: /^Overdue/ }).click();
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Overdue');
    await page.getByRole('group', { name: 'Filter by status' }).getByRole('button', { name: /^Ordered/ }).click();
    await expect(rows).toHaveCount(1);
  });

  test('opening a row opens the detail sheet and puts the issue in the URL; Escape closes it and returns focus', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=issues');
    await unitRow(page, 'Rescue 1').click();
    await page.getByTestId('captain5-issue-row').filter({ hasText: 'Pulse oximeter' }).click();
    await expect(page).toHaveURL(/issue=1/);
    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page).getByRole('heading', { name: 'Rescue 1: Pulse oximeter' })).toBeVisible();
    const summary = page.getByTestId('captain5-issue-summary');
    await expect(summary).toContainText('Medical bag');
    await expect(summary).toContainText('Lt. Reporter');
    await expect(page.getByTestId('captain5-timeline')).toContainText('FF Smith');
    await expect(page.getByTestId('captain5-observations')).toContainText('Not in the bag');
    await page.keyboard.press('Escape');
    await expect(sheet(page)).toHaveCount(0);
    await expect(page).not.toHaveURL(/issue=/);
  });

  test('a deep link to ?issue= opens that issue; an issue outside scope says so and offers a way back', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=issues&issue=2');
    await expect(sheet(page).getByRole('heading', { name: 'Engine 1: Epinephrine' })).toBeVisible();

    server.detailStatus = 403;
    await page.goto('/daily/captain-5?issue=1');
    await expect(page.getByTestId('captain5-issue-unavailable')).toBeVisible();
    await page.getByRole('button', { name: 'Back to the queue' }).click();
    await expect(sheet(page)).toHaveCount(0);
  });

  test('only the allowed actions appear, with the right one emphasised for the state', async ({ page }) => {
    await open(page, newServer({ issues: [makeIssue(1, { action_status: 'action_pending', state_token: 'action_pending:10', actions: ['ordered', 'update_delivery', 'available_for_pickup', 'replaced'] })] }), '/daily/captain-5?issue=1');
    const group = page.getByRole('group', { name: 'Issue actions' });
    await expect(group.getByRole('button')).toHaveText(['Mark available for pickup', 'Mark ordered', 'Update delivery', 'Replaced / returned to service']);
    await expect(group.getByRole('button', { name: 'Acknowledge' })).toHaveCount(0);
    await expect(group.getByRole('button', { name: 'Close — No Action Required' })).toHaveCount(0);
    await expect(group.getByRole('button').first()).toHaveClass(/hub-btn--primary/);
  });

  test('Acknowledge posts the action with expected_state and an idempotency key, then confirms and advances the state', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=issues&issue=1');
    const group = page.getByRole('group', { name: 'Issue actions' });
    await group.getByRole('button', { name: 'Acknowledge' }).click();
    await actionForm(page).getByRole('button', { name: 'Acknowledge' }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Acknowledged.');
    expect(server.actionBodies).toHaveLength(1);
    expect(lastAction(server)).toMatchObject({ action: 'acknowledge', expected_state: 'reported:10' });
    expect(String(lastAction(server).idempotency_key)).toMatch(KEY_PATTERN);
    expect(lastAction(server)).not.toHaveProperty('member_message');
    // The sheet now offers the next step, and the queue behind it refreshed.
    await expect(group.getByRole('button').first()).toHaveText('Mark ordered');
    await expect(group.getByRole('button', { name: 'Acknowledge' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('captain5-issue-row').filter({ hasText: 'Pulse oximeter' })).toContainText('Acknowledged');
  });

  test('Ordered sends the vendor reference, delivery date and an optional crew message', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { action_status: 'acknowledged', state_token: 'acknowledged:12', actions: ['ordered', 'available_for_pickup', 'replaced', 'dismiss'] })] });
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Mark ordered' }).click();
    const form = actionForm(page);
    await form.getByLabel('Vendor reference').fill('PO-1234');
    await form.getByLabel('Expected delivery').fill(ymd(5));
    await form.getByText('Add a message (optional)').click();
    await form.getByLabel('Message to crew').fill('Part ordered, will tell you when it is in.');
    await form.getByLabel('Internal note').fill('Cheaper from the second vendor');
    await form.getByRole('button', { name: 'Save as ordered' }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Saved as ordered.');
    expect(lastAction(server)).toMatchObject({
      action: 'ordered', expected_state: 'acknowledged:12', vendor_reference: 'PO-1234', expected_delivery: ymd(5),
      member_message: 'Part ordered, will tell you when it is in.', internal_note: 'Cheaper from the second vendor',
    });
  });

  test('Replaced needs a description and the verified-in-place confirmation before anything is sent', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { action_status: 'replacement_available', state_token: 'replacement_available:12', actions: ['update_delivery', 'replaced', 'dismiss'] })] });
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Replaced / returned to service' }).click();
    const form = actionForm(page);
    await form.getByRole('button', { name: 'Confirm replaced' }).click();
    await expect(form.getByText('Confirm the replacement is in place on the unit before closing the issue.')).toBeVisible();
    await expect(form.getByText('Describe what was replaced or repaired.')).toBeVisible();
    expect(server.actionBodies).toHaveLength(0);

    await form.getByLabel('What happened').selectOption('spare_issued');
    await form.getByLabel('What was done (required)').fill('Spare oximeter issued and checked on the unit.');
    await form.getByLabel('I verified the replacement is in place on the unit').check();
    await form.getByRole('button', { name: 'Confirm replaced' }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Marked replaced and returned to service.');
    expect(lastAction(server)).toMatchObject({
      action: 'replaced', resolution_type: 'spare_issued', resolution_notes: 'Spare oximeter issued and checked on the unit.', verified_in_place: true,
    });
  });

  test('Dismiss requires a reason', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Close — No Action Required' }).click();
    await actionForm(page).getByRole('button', { name: 'Close issue' }).click();
    await expect(actionForm(page).getByText('Say why no corrective action is necessary.')).toBeVisible();
    expect(server.actionBodies).toHaveLength(0);
  });

  test('a 409 shows the fresh issue and a clear notice, keeps the typed text, and the next try uses the new state', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { action_status: 'acknowledged', state_token: 'acknowledged:12', actions: ['ordered', 'available_for_pickup', 'replaced', 'dismiss'] })] });
    let conflicts = 0;
    server.onAction = async (route, _body, current) => {
      if (conflicts > 0) return false;
      conflicts += 1;
      // Someone else marked it ordered first.
      const fresh = makeIssue(1, { action_status: 'action_pending', state_token: 'action_pending:13', details: { vendor_reference: 'PO-OTHER' }, actions: ['ordered', 'update_delivery', 'available_for_pickup', 'replaced', 'dismiss'] });
      current.issues[0] = fresh;
      await route.fulfill({ status: 409, json: { message: 'This issue was changed by someone else.', issue: fresh } });
      return true;
    };
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Mark ordered' }).click();
    const form = actionForm(page);
    await form.getByLabel('Vendor reference').fill('PO-MINE');
    await form.getByText('Add a message (optional)').click();
    await form.getByLabel('Message to crew').fill('Ordered on my side too.');
    await form.getByRole('button', { name: 'Save as ordered' }).click();

    await expect(page.getByTestId('captain5-issue-notice')).toContainText('Updated by someone else');
    await expect(form.getByTestId('captain5-action-error')).toContainText('updated by someone else');
    await expect(form.getByLabel('Vendor reference')).toHaveValue('PO-MINE');
    await expect(form.getByLabel('Message to crew')).toHaveValue('Ordered on my side too.');
    // The fresh version is on screen.
    await expect(page.getByTestId('captain5-issue-summary')).toContainText('PO-OTHER');
    expect(lastAction(server)).toMatchObject({ expected_state: 'acknowledged:12' });
    const firstKey = String(lastAction(server).idempotency_key);

    await form.getByRole('button', { name: /Save as ordered/ }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Saved as ordered.');
    expect(server.actionBodies).toHaveLength(2);
    expect(lastAction(server)).toMatchObject({ action: 'ordered', expected_state: 'action_pending:13', vendor_reference: 'PO-MINE', member_message: 'Ordered on my side too.' });
    expect(String(lastAction(server).idempotency_key)).toBe(firstKey);
  });

  test('a dropped connection keeps the text and the retry reuses the SAME idempotency key', async ({ page }) => {
    const server = newServer();
    const keys: string[] = [];
    let drop = true;
    server.onAction = async (route, body) => {
      keys.push(String(body.idempotency_key));
      if (!drop) return false;
      drop = false;
      await route.abort('failed');
      return true;
    };
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Acknowledge' }).click();
    const form = actionForm(page);
    await form.getByText('Add a message (optional)').click();
    await form.getByLabel('Message to crew').fill('Seen, working it.');
    await form.getByRole('button', { name: 'Acknowledge' }).click();

    await expect(form.getByTestId('captain5-action-error')).toContainText('your text is kept');
    await expect(form.getByLabel('Message to crew')).toHaveValue('Seen, working it.');
    await form.getByRole('button', { name: /Retry/ }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Acknowledged.');
    expect(keys).toHaveLength(2);
    expect(keys[0]).toMatch(KEY_PATTERN);
    expect(keys[1]).toBe(keys[0]);
    expect(lastAction(server)).toMatchObject({ member_message: 'Seen, working it.' });
  });

  test('offline: nothing is sent, the message says so, the text is kept, and it sends once back online', async ({ page, context }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Acknowledge' }).click();
    const form = actionForm(page);
    await form.getByText('Add a message (optional)').click();
    await form.getByLabel('Message to crew').fill('Will handle at shift change.');
    await context.setOffline(true);
    await form.getByRole('button', { name: 'Acknowledge' }).click();
    await expect(form.getByTestId('captain5-action-error')).toHaveText("You're offline. Nothing was sent; your text is kept.");
    await expect(form.getByLabel('Message to crew')).toHaveValue('Will handle at shift change.');
    expect(server.actionBodies).toHaveLength(0);

    await context.setOffline(false);
    await form.getByRole('button', { name: /Retry/ }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Acknowledged.');
    expect(server.actionBodies).toHaveLength(1);
  });

  test('the issue thread posts to the crew or internally, and keeps the text when sending fails', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?issue=1');
    const thread = page.getByTestId('captain5-thread');
    await thread.getByLabel('Message', { exact: true }).fill('Please check the bag again.');
    await thread.getByRole('button', { name: 'Send message' }).click();
    await expect(thread.getByTestId('captain5-message')).toContainText('Please check the bag again.');
    expect(server.messageBodies[0]).toMatchObject({ body: 'Please check the bag again.', visibility: 'member' });
    expect(String(server.messageBodies[0].client_message_id)).toMatch(UUID_PATTERN);

    await thread.getByLabel('Internal note').check();
    await thread.getByLabel('Message', { exact: true }).fill('Order from the usual vendor.');
    await thread.getByRole('button', { name: 'Send message' }).click();
    await expect(thread.getByTestId('captain5-message').nth(1)).toContainText('Internal, crew cannot see this');
    expect(server.messageBodies[1]).toMatchObject({ visibility: 'internal' });

    await page.route('**/api/captain5/issues/1/messages', (route) => route.abort('failed'));
    await thread.getByLabel('Message', { exact: true }).fill('Third message');
    await thread.getByRole('button', { name: 'Send message' }).click();
    await expect(thread.getByTestId('captain5-thread-error')).toBeVisible();
    await expect(thread.getByLabel('Message', { exact: true })).toHaveValue('Third message');
  });
});

test.describe('one row per unit', () => {
  const threeOnRescue = (): IssueDetail[] => [
    makeIssue(1),
    makeIssue(2, { item: 'Cervical collar', action_status: 'action_pending', expected_delivery: ymd(-3), overdue: true, first_reported_at: minutesAgo(60 * 24 * 3), state_token: 'action_pending:20' }),
    makeIssue(3, { item: 'Suction unit', first_reported_at: minutesAgo(30) }),
    makeIssue(4, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine' }),
  ];

  test('a unit is one collapsed disclosure with its count, worst tag and oldest age; expanding lists its issues', async ({ page }) => {
    await open(page, newServer({ issues: threeOnRescue() }), '/daily/captain-5?tab=issues');
    const rescue = unitRow(page, 'Rescue 1');
    await expect(page.getByTestId('captain5-unit-row')).toHaveCount(2);
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(0);
    await expect(rescue).toHaveAttribute('aria-expanded', 'false');
    await expect(rescue).toContainText('3 issues');
    await expect(rescue).toContainText('Overdue');
    await expect(rescue).toContainText(/Oldest reported 3 ?d/i);
    await expect(unitRow(page, 'Engine 1')).toContainText('1 issue');
    await expect(unitRow(page, 'Engine 1')).toContainText('Needs acknowledgement');
    // The unit with the most urgent issue leads.
    await expect(page.getByTestId('captain5-unit-row').first()).toContainText('Rescue 1');
    await expect(page.getByRole('status').filter({ hasText: 'issues shown' })).toHaveText('4 issues shown on 2 units');

    await rescue.click();
    await expect(rescue).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(3);
    await expect(page.getByTestId('captain5-issue-row').first()).toContainText('Cervical collar');
    await page.getByTestId('captain5-issue-row').filter({ hasText: 'Suction unit' }).click();
    await expect(sheet(page).getByRole('heading', { name: 'Rescue 1: Suction unit' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(rescue).toHaveAttribute('aria-expanded', 'true');

    await rescue.click();
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(0);
    await expect(rescue).toHaveAttribute('aria-expanded', 'false');
  });

  test('filters keep working: a filter that leaves one unit opens it, and the counts are the filtered ones', async ({ page }) => {
    await open(page, newServer({ issues: threeOnRescue() }), '/daily/captain-5?tab=issues');
    await page.getByRole('group', { name: 'Filter by unit type' }).getByRole('button', { name: /^Medical/ }).click();
    await expect(page.getByTestId('captain5-unit-row')).toHaveCount(1);
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(1);
    await page.getByRole('group', { name: 'Filter by unit type' }).getByRole('button', { name: /^All/ }).click();
    await page.getByRole('group', { name: 'Filter by status' }).getByRole('button', { name: /^Needs acknowledgement/ }).click();
    await expect(unitRow(page, 'Rescue 1')).toContainText('2 issues');
    await page.getByRole('group', { name: 'Filter by status' }).getByRole('button', { name: /^Overdue/ }).click();
    await expect(page.getByTestId('captain5-unit-row')).toHaveCount(1);
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(1);
    await expect(unitRow(page, 'Rescue 1')).toContainText('1 issue');
  });

  test('when only one unit has issues it starts open', async ({ page }) => {
    await open(page, newServer({ issues: threeOnRescue().slice(0, 3) }), '/daily/captain-5?tab=issues');
    await expect(unitRow(page, 'Rescue 1')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(3);
  });

  test('a deep link to an issue opens that issue and its unit only', async ({ page }) => {
    await open(page, newServer({ issues: threeOnRescue() }), '/daily/captain-5?tab=issues&issue=4');
    await expect(sheet(page).getByRole('heading', { name: 'Engine 1: Epinephrine' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(unitRow(page, 'Engine 1')).toHaveAttribute('aria-expanded', 'true');
    await expect(unitRow(page, 'Rescue 1')).toHaveAttribute('aria-expanded', 'false');
  });
});

test.describe('Clear for Logistics', () => {
  const twoOnRescue = (): IssueDetail[] => [
    makeIssue(1),
    makeIssue(2, { item: 'Cervical collar', state_token: 'reported:20' }),
    makeIssue(3, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine' }),
  ];

  test('one tap clears with no form, says so, leaves every count, stays reachable, and Undo brings it back', async ({ page }) => {
    const server = newServer({ issues: twoOnRescue() });
    await open(page, server, '/daily/captain-5?tab=issues');
    await unitRow(page, 'Rescue 1').click();
    const row = page.getByRole('button', { name: 'Clear for Logistics: Rescue 1, Pulse oximeter' });
    const box = await row.boundingBox();
    expect(box?.height).toBeGreaterThanOrEqual(44);
    await row.click();

    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByTestId('captain5-clear-status')).toContainText('Cleared from Captain 5. It stays open with Logistics.');
    expect(server.actionBodies).toHaveLength(1);
    expect(lastAction(server)).toMatchObject({ action: 'send_to_logistics', expected_state: 'reported:10' });
    expect(String(lastAction(server).idempotency_key)).toMatch(KEY_PATTERN);
    expect(lastAction(server)).not.toHaveProperty('reason');
    expect(lastAction(server)).not.toHaveProperty('member_message');
    await expect(unitRow(page, 'Rescue 1')).toContainText('1 issue');
    await expect(page.getByRole('tab', { name: /^Issues/ })).toContainText('2');
    await expect(page.getByTestId('captain5-cleared-section')).toContainText('Cleared for Logistics (1)');
    await expectNoHorizontalOverflow(page);
    await shot(page, `cleared-${page.viewportSize()?.width}`);

    await page.getByTestId('captain5-clear-undo').click();
    await expect(page.getByTestId('captain5-clear-status')).toContainText('Brought back to the Captain 5 queue.');
    expect(lastAction(server)).toMatchObject({ action: 'bring_back_to_captain5', expected_state: 'reported:11' });
    await expect(unitRow(page, 'Rescue 1')).toContainText('2 issues');
    await expect(page.getByTestId('captain5-cleared-section')).toHaveCount(0);
  });

  test('the cleared list starts collapsed, opens an issue, and Bring back returns it to the queue', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { captain5_cleared_at: minutesAgo(10), captain5_cleared_by: 'Jordan Lee', actions: [], clearing_actions: ['bring_back_to_captain5'] }), makeIssue(2, { item: 'Cervical collar', state_token: 'reported:20' })] });
    await open(page, server, '/daily/captain-5?tab=issues');
    await expect(page.getByRole('tab', { name: /^Issues/ })).toContainText('1');
    const section = page.getByTestId('captain5-cleared-section');
    await expect(section).toContainText('Cleared for Logistics (1)');
    await expect(page.getByTestId('captain5-cleared-row')).toBeHidden();
    await section.locator('summary').click();
    await expect(page.getByTestId('captain5-cleared-row')).toContainText('by Jordan Lee');

    await page.getByTestId('captain5-cleared-row').click();
    await expect(page.getByRole('group', { name: 'Issue actions' }).getByRole('button')).toHaveText(['Bring back']);
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Bring back: Rescue 1, Pulse oximeter' }).click();
    await expect(page.getByTestId('captain5-clear-status')).toContainText('Brought back to the Captain 5 queue.');
    expect(lastAction(server)).toMatchObject({ action: 'bring_back_to_captain5', expected_state: 'reported:10' });
    await expect(section).toHaveCount(0);
    await expect(page.getByTestId('captain5-issue-row')).toHaveCount(2);
  });

  test('Clear all runs one by one with each state token and its own key, and says plainly what changed', async ({ page }) => {
    const server = newServer({ issues: twoOnRescue() });
    server.onAction = async (route, body, current) => {
      if (body.expected_state !== 'reported:20') return false;
      current.issues = current.issues.map((issue) => (issue.id === 2 ? { ...issue, action_status: 'acknowledged', state_token: 'acknowledged:21' } : issue));
      await route.fulfill({ status: 409, json: { message: 'Updated by someone else.', issue: current.issues.find((issue) => issue.id === 2) } });
      return true;
    };
    await open(page, server, '/daily/captain-5?tab=issues');
    await unitRow(page, 'Rescue 1').click();
    await page.getByRole('button', { name: 'Clear all 2 for Logistics' }).click();

    await expect(page.getByTestId('captain5-clear-status')).toHaveText(/Cleared 1 of 2\. 1 changed and was not cleared\./);
    // In queue order, newest first: the changed one does not stop the next.
    expect(server.actionBodies.map((body) => body.expected_state)).toEqual(['reported:20', 'reported:10']);
    expect(server.actionBodies[0].idempotency_key).not.toBe(server.actionBodies[1].idempotency_key);
    await expect(unitRow(page, 'Rescue 1')).toContainText('1 issue');
    await expect(page.getByTestId('captain5-issue-row').filter({ hasText: 'Cervical collar' })).toContainText('Acknowledged');
    await expect(page.getByTestId('captain5-cleared-section')).toContainText('Cleared for Logistics (1)');

    await page.getByRole('tab', { name: 'Today' }).click();
    await expect(page.getByTestId('captain5-count-all')).toContainText('2');
  });

  test('a clean Clear all reports all of them, and a dropped connection retries with the SAME key', async ({ page }) => {
    const server = newServer({ issues: twoOnRescue() });
    await open(page, server, '/daily/captain-5?tab=issues');
    let dropped = false;
    await page.route('**/api/captain5/issues/1/actions', async (route) => {
      if (dropped) return route.fallback();
      dropped = true;
      server.actionBodies.push(JSON.parse(route.request().postData() ?? '{}') as Record<string, unknown>);
      return route.abort('connectionreset');
    });
    await unitRow(page, 'Rescue 1').click();
    await page.getByRole('button', { name: 'Clear for Logistics: Rescue 1, Pulse oximeter' }).click();
    await expect(page.getByTestId('captain5-clear-status')).toContainText('Cleared 0 of 1. 1 could not be sent. Try again.');
    const firstKey = server.actionBodies[0].idempotency_key;

    await page.getByRole('button', { name: 'Clear all 2 for Logistics' }).click();
    await expect(page.getByTestId('captain5-clear-status')).toContainText('Cleared all 2 from Captain 5. They stay open with Logistics.');
    const sameIssue = server.actionBodies.filter((body) => body.expected_state === 'reported:10');
    expect(sameIssue).toHaveLength(2);
    expect(sameIssue[1]).toMatchObject({ action: 'send_to_logistics', idempotency_key: firstKey });
  });

  test('the sheet offers the same action with a note and no message to the crew, and the timeline names who did it', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { clearing_actions: ['send_to_logistics'] })] });
    await open(page, server, '/daily/captain-5?tab=issues&issue=1');
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Clear, this is for Logistics' }).click();
    const form = actionForm(page);
    await form.getByText('Add a note (optional)').click();
    await expect(form.getByLabel('Message to crew')).toHaveCount(0);
    await form.getByLabel('Internal note').fill('Radio mount belongs to support services.');
    await form.getByRole('button', { name: 'Clear for Logistics' }).click();
    await expect(page.getByTestId('captain5-issue-notice')).toHaveText('Cleared from Captain 5. It stays open with Logistics.');
    expect(lastAction(server)).toMatchObject({ action: 'send_to_logistics', internal_note: 'Radio mount belongs to support services.' });
    await expect(page.getByTestId('captain5-timeline')).toContainText('Cleared from Captain 5 by Alex Rivera');
    await expect(page.getByTestId('captain5-issue-summary')).toContainText('It stays open with Logistics.');
    await expect(page.getByRole('group', { name: 'Issue actions' }).getByRole('button')).toHaveText(['Bring back']);
  });

  test('an action name this build does not know is left out and the sheet still opens', async ({ page }) => {
    const server = newServer({ issues: [makeIssue(1, { actions: ['acknowledge', 'a_future_action' as IssueDetail['actions'][number]] })] });
    await open(page, server, '/daily/captain-5?tab=issues&issue=1');
    await expect(page.getByRole('group', { name: 'Issue actions' }).getByRole('button')).toHaveText(['Acknowledge']);
  });
});

test.describe('Truck Changes', () => {
  const trucks: RescueTruck[] = [
    { apparatus_id: 4, unit: 'R 1', vehicle_number: '2201', station: 'Station 1', status: 'In Service', changed_at: null, changed_recently: false },
    { apparatus_id: 5, unit: 'R 44', vehicle_number: '1033', station: 'Station 4', status: 'Out of Service', changed_at: minutesAgo(90), changed_recently: true },
  ];
  const changes: RescueTruckChange[] = [
    { id: 'changeout:1', unit: 'R 44', from_vehicle_number: '17503', to_vehicle_number: '1033', at: minutesAgo(90), by: 'Lt. Alvarez', recent: true, note: 'Moved after the morning call.', left_behind: [{ name: 'Airway Bag', note: 'Stays on the old truck.' }, { name: 'LifePak 15', note: null }] },
    { id: 'changeout:2', unit: 'R 1', from_vehicle_number: '2200', to_vehicle_number: '2201', at: minutesAgo(60 * 24 * 5), by: 'Sam Fleet', recent: false, note: null, left_behind: [] },
  ];

  test('the tab sits after Issues, shows who is on what truck and what changed, and its badge is the last 24 hours', async ({ page }) => {
    await open(page, newServer({ trucks, truckChanges: changes }));
    const tabs = page.getByRole('tablist', { name: 'Captain 5 workspace' }).getByRole('tab');
    await expect(tabs.nth(1)).toContainText('Issues');
    await expect(tabs.nth(2)).toHaveText(/Truck Changes\s*1/);
    await tabs.nth(2).click();
    await expect(page).toHaveURL(/tab=trucks/);

    const rows = page.getByTestId('captain5-truck-row');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('R 1');
    await expect(rows.nth(0)).toContainText('Truck 2201');
    await expect(rows.nth(0)).toContainText('Station 1');
    await expect(rows.nth(0)).not.toContainText('Changed');
    await expect(rows.nth(1)).toContainText('Truck 1033');
    await expect(rows.nth(1)).toContainText('Out of Service');
    await expect(rows.nth(1)).toContainText('Changed');

    const entries = page.getByTestId('captain5-truck-change');
    await expect(entries).toHaveCount(2);
    await expect(entries.nth(0)).toContainText('R 44: truck 17503 to truck 1033');
    await expect(entries.nth(0)).toContainText('Lt. Alvarez');
    await expect(entries.nth(0)).toContainText('2 left behind');
    await expect(entries.nth(0).getByText('Airway Bag: Stays on the old truck.')).toBeHidden();
    await entries.nth(0).locator('summary').click();
    await expect(entries.nth(0).getByText('Airway Bag: Stays on the old truck.')).toBeVisible();
    await expect(entries.nth(0).getByText('Moved after the morning call.')).toBeVisible();
    await expect(entries.nth(1)).toContainText('Sam Fleet');
    await expect(entries.nth(1)).not.toContainText('Fleet decision');
    await expect(entries.nth(1).locator('summary')).toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await shot(page, `trucks-${page.viewportSize()?.width}`);
    const heights = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('[data-testid="captain5-truck-row"], [data-testid="captain5-truck-change"] summary')].map((el) => Math.round(el.getBoundingClientRect().height)));
    expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);
  });

  test('an answer without the new keys, or with nothing to show, gets a plain empty state and no badge', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=trucks');
    await expect(page.getByRole('tab', { name: /Truck Changes/ })).toHaveText('Truck Changes');
    await expect(page.getByText('No Rescue units to show')).toBeVisible();
    await expect(page.getByText('No Rescue truck changes in the last 30 days')).toBeVisible();
    await expectNoHorizontalOverflow(page);
  });

  test('a malformed answer never breaks the page', async ({ page }) => {
    const server = newServer({ trucks: [{ unit: 7 }] as unknown as RescueTruck[], truckChanges: [{ left_behind: 'nope' }, null] as unknown as RescueTruckChange[] });
    await open(page, server, '/daily/captain-5?tab=trucks');
    await expect(page.getByTestId('captain5-truck-row')).toHaveCount(1);
    await expect(page.getByTestId('captain5-truck-change')).toHaveCount(2);
    await expect(page.getByTestId('captain5-truck-change').first()).toContainText('Unit: a truck not on record to a truck not on record');
  });
});

test.describe('History and Messages', () => {
  test('History searches closed issues through the server', async ({ page }) => {
    const server = newServer({ closed: [makeIssue(8, { item: 'Glucometer', open: false, action_status: 'resolved', resolved_at: minutesAgo(2000), resolution_type: 'replacement_installed', actions: ['reopen'] }), makeIssue(9, { item: 'Bandage shears', open: false, action_status: 'dismissed', closure: 'closed_no_action', closure_reason: 'Duplicate of the report entered on Oct 20', resolved_at: minutesAgo(3000), actions: ['reopen'] })] });
    await open(page, server, '/daily/captain-5?tab=history');
    await expect(page.getByTestId('captain5-history-row')).toHaveCount(2);
    await expect(page.getByTestId('captain5-history-row').filter({ hasText: 'Bandage shears' })).toContainText('Closed — no action required');
    await expect(page.getByTestId('captain5-history-row').filter({ hasText: 'Bandage shears' })).toContainText('Reason: Duplicate of the report entered on Oct 20');
    await expect(page.getByTestId('captain5-history-row').filter({ hasText: 'Glucometer' })).not.toContainText('no action required');
    await page.getByLabel('Search closed issues').fill('gluco');
    await expect(page.getByTestId('captain5-history-row')).toHaveCount(1);
    await expect(page.getByTestId('captain5-history-row')).toContainText('Glucometer');
    expect(server.historyQueries).toContain('gluco');
    await page.getByTestId('captain5-history-row').click();
    await expect(page.getByRole('group', { name: 'Issue actions' }).getByRole('button')).toHaveText(['Reopen']);
    await page.keyboard.press('Escape');
    await page.getByLabel('Search closed issues').fill('zzz');
    await expect(page.getByText('No closed issues match your search')).toBeVisible();
  });

  test('Messages reads the Captain 5 channel of the shared conversation', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=messages');
    await expect(page.getByTestId('command-comms')).toBeVisible();
    expect(server.requests).toContain('GET /api/captain5/communications/posts');
    expect(server.requests).not.toContain('GET /api/command-communications/posts');
  });
});

test.describe('everyone with access can do everything', () => {
  test('a plain officer, even when the server says act:false, gets every control: no view-only mode', async ({ page }) => {
    const server = newServer({ access: { ...ACCESS_ACT, act: false, authority: 'officer', authority_label: 'Officer' } });
    await open(page, server);
    await expect(page.getByText('View only')).toHaveCount(0);
    await expect(page.getByTestId('captain5-authority')).toContainText('Officer');
    await expect(page.getByLabel('Handoff note for the next Captain 5')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Acknowledge handoff' })).toBeVisible();
    await expect(page.getByTestId('captain5-acting-open')).toBeVisible();

    await page.goto('/daily/captain-5?tab=issues&issue=1');
    await expect(sheet(page).getByRole('heading', { name: 'Rescue 1: Pulse oximeter' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Acknowledge' })).toBeVisible();
    await expect(page.getByTestId('captain5-thread').getByRole('button', { name: 'Send message' })).toBeVisible();
    await page.keyboard.press('Escape');

    await page.goto('/daily/captain-5');
    await page.getByTestId('captain5-rescue-row').first().click();
    await expect(page.getByTestId('captain5-clarification')).toBeVisible();
  });
});

test.describe('acting as Captain 5', () => {
  const open_ = (page: Page) => page.getByTestId('captain5-acting-open');
  const acts = (page: Page) => page.getByTestId('captain5-acting-sheet');

  test('the control sits under the shift line and opens a card with the plain explanation and three choices', async ({ page }) => {
    await open(page, newServer());
    await expect(open_(page)).toHaveText('Acting as Captain 5 today? Click here.');
    await open_(page).click();
    await expect(acts(page)).toBeVisible();
    await expect(acts(page)).toContainText('An administrator approves this. It only changes the name shown at the top; your work on this page is always recorded under your own name, whether or not you use this.');
    await expect(acts(page).getByRole('radio', { name: /^12 hours\s+ends / })).toBeChecked();
    await expect(acts(page).getByRole('radio', { name: /^24 hours\s+ends / })).not.toBeChecked();
    await expect(acts(page).getByRole('radio', { name: /^Custom end time/ })).not.toBeChecked();
    await expect(acts(page).getByRole('button', { name: 'End the current acting Captain 5' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(acts(page)).toHaveCount(0);
  });

  test('12 hours: posts kind act with a request id, closes, shows the pending request, and Withdraw takes it back', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await open_(page).click();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(1);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'act', duration: '12h' });
    expect(server.actingBodies[0]).not.toHaveProperty('ends_at');
    expect(String(server.actingBodies[0].client_request_id)).toMatch(UUID_PATTERN);
    const mine = page.getByTestId('captain5-acting-mine');
    await expect(mine).toContainText('Your request to act as Captain 5 until');
    await expect(mine).toContainText('is waiting for approval');
    // Acting is display only: the shown name has not changed.
    await expect(page.getByTestId('captain5-duty-line')).toHaveText('B Shift Captain 5: Alex Rivera');

    await mine.getByRole('button', { name: 'Withdraw' }).click();
    await expect(page.getByTestId('captain5-acting-mine')).toHaveCount(0);
    expect(server.actingDecisions[0].call).toMatch(/^withdraw:\d+$/);
  });

  test('24 hours posts duration 24h; a custom end time posts the local datetime and needs a time first', async ({ page }) => {
    const server = newServer();
    await open(page, server);
    await open_(page).click();
    await acts(page).getByRole('radio', { name: /^24 hours/ }).check();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'act', duration: '24h' });
    expect(server.actingBodies[0]).not.toHaveProperty('ends_at');
    await page.getByTestId('captain5-acting-mine').getByRole('button', { name: 'Withdraw' }).click();

    await open_(page).click();
    await acts(page).getByRole('radio', { name: /^Custom end time/ }).check();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page).getByTestId('captain5-acting-error')).toHaveText('Choose when you stop acting as Captain 5.');
    expect(server.actingBodies).toHaveLength(1);
    const end = `${ymd(1)}T09:30`;
    await acts(page).getByLabel('Stop acting at').fill(end);
    await acts(page).getByRole('button', { name: /Request to act as Captain 5|Try again/ }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(2);
    expect(server.actingBodies[1]).toMatchObject({ kind: 'act', duration: 'custom', ends_at: end });
  });

  test('while someone is shown as acting, the card explains the takeover and can end the current acting Captain 5', async ({ page }) => {
    const server = newServer({ onDuty: acting('B', 'Jordan Lee') });
    await open(page, server);
    await open_(page).click();
    await expect(page.getByTestId('captain5-acting-takeover')).toContainText('Jordan Lee is shown as acting Captain 5 until');
    await expect(page.getByTestId('captain5-acting-takeover')).toContainText('your name replaces theirs');
    await acts(page).getByRole('button', { name: 'End the current acting Captain 5' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'end' });
    expect(server.actingBodies[0]).not.toHaveProperty('duration');
    expect(String(server.actingBodies[0].client_request_id)).toMatch(UUID_PATTERN);
    await expect(page.getByTestId('captain5-acting-mine')).toContainText('Your request to end the current acting Captain 5 is waiting for approval.');

    // Asking to act while someone else is shown is the same act request (a takeover).
    await open_(page).click();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies[1]).toMatchObject({ kind: 'act', duration: '12h' });
    expect(server.actingBodies[1].client_request_id).not.toBe(server.actingBodies[0].client_request_id);
  });

  test('a dropped connection keeps the card open and the retry reuses the SAME client_request_id', async ({ page }) => {
    const server = newServer();
    const keys: string[] = [];
    let drop = true;
    server.onActing = async (route, body) => {
      keys.push(String(body.client_request_id));
      if (!drop) return false;
      drop = false;
      await route.abort('failed');
      return true;
    };
    await open(page, server);
    await open_(page).click();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page).getByTestId('captain5-acting-error')).toContainText('nothing is confirmed');
    await acts(page).getByRole('button', { name: 'Try again' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(keys).toHaveLength(2);
    expect(keys[1]).toBe(keys[0]);
  });

  test('a refused request shows the server reason and the next try is a new request', async ({ page }) => {
    const server = newServer();
    const keys: string[] = [];
    let refuse = true;
    server.onActing = async (route, body) => {
      keys.push(String(body.client_request_id));
      if (!refuse) return false;
      refuse = false;
      await route.fulfill({ status: 422, json: { message: 'Invalid.', errors: { ends_at: ['Choose an end time between 15 minutes and 72 hours from now.'] } } });
      return true;
    };
    await open(page, server);
    await open_(page).click();
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page).getByTestId('captain5-acting-error')).toHaveText('Choose an end time between 15 minutes and 72 hours from now.');
    await acts(page).getByRole('button', { name: 'Try again' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(keys[1]).not.toBe(keys[0]);
  });

  test('offline: nothing is sent and the card says so', async ({ page, context }) => {
    const server = newServer();
    await open(page, server);
    await open_(page).click();
    await context.setOffline(true);
    await acts(page).getByRole('button', { name: 'Request to act as Captain 5' }).click();
    await expect(acts(page).getByTestId('captain5-acting-error')).toHaveText("You're offline. Nothing was sent. Reconnect, then try again.");
    expect(server.actingBodies).toHaveLength(0);
    await context.setOffline(false);
    await acts(page).getByRole('button', { name: 'Try again' }).click();
    await expect(acts(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(1);
  });

  test('members who cannot approve see no approval list', async ({ page }) => {
    await open(page, newServer({ acting: noActing({ pending: [makeActing(7)] }) }));
    await expect(page.getByTestId('captain5-acting-approvals')).toHaveCount(0);
    await expect(page.getByText('Waiting for approval')).toHaveCount(0);
  });

  test('an administrator approves a request: the new name shows at the top', async ({ page }) => {
    const server = newServer({ access: ACCESS_ADMIN, acting: noActing({ can_approve: true, pending: [makeActing(7), makeActing(8, { kind: 'end', name: null, rank: null, duration: null, ends_at: null, requested_by: 'Sam Ortiz' })] }) });
    await open(page, server);
    const list = page.getByTestId('captain5-acting-approvals');
    await expect(list.getByRole('heading', { name: 'Waiting for approval' })).toBeVisible();
    const rows = list.getByTestId('captain5-acting-pending');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Lt. Jordan Lee to act as Captain 5 until');
    await expect(rows.nth(1)).toContainText('End the current acting Captain 5');
    await rows.first().getByRole('button', { name: /^Approve/ }).click();
    await expect(rows).toHaveCount(1);
    expect(server.actingDecisions[0]).toEqual({ call: 'approve:7', body: {} });
    await expect(page.getByTestId('captain5-duty-line')).toHaveText(/^B Shift Acting Captain 5: Jordan Lee until /);
  });

  test('"Not approved" needs a note, then sends it', async ({ page }) => {
    const server = newServer({ access: ACCESS_ADMIN, acting: noActing({ can_approve: true, pending: [makeActing(7)] }) });
    await open(page, server);
    const row = page.getByTestId('captain5-acting-pending');
    await row.getByRole('button', { name: /^Not approved/ }).click();
    await row.getByRole('button', { name: 'Send decision' }).click();
    await expect(row.getByText('Write a short note so the member knows why.')).toBeVisible();
    expect(server.actingDecisions).toHaveLength(0);
    await row.getByLabel('Why is this not approved?').fill('Captain 5 is on duty tonight');
    await row.getByRole('button', { name: 'Send decision' }).click();
    await expect(page.getByTestId('captain5-acting-approvals')).toHaveCount(0);
    expect(server.actingDecisions[0]).toEqual({ call: 'reject:7', body: { note: 'Captain 5 is on duty tonight' } });
  });
});

test.describe('Messages description', () => {
  test('the Captain 5 Messages panel says who can read it and points to Department Updates', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=messages');
    const about = page.getByTestId('command-comms-about');
    await expect(about).toContainText('Post and receive messages, files, etc. Messages can only be received and sent by admin personnel, Capt. 5 and 300.');
    await expect(about).toContainText('For a notice everyone sees on the home page, use Post to Department Updates instead.');
    await about.getByRole('button', { name: 'Post to Department Updates' }).click();
    await expect(page).toHaveURL(/tab=updates/);
    await expect(page.getByTestId('department-updates')).toBeVisible();
  });
});

test.describe('Department Updates', () => {
  const form = (page: Page) => page.getByTestId('department-update-form');
  const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

  test('is its own tab, explains the difference from Messages and says the byline is automatic', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5');
    await page.getByRole('tab', { name: 'Department Updates' }).click();
    await expect(page).toHaveURL(/tab=updates/);
    const panel = page.getByTestId('department-updates');
    await expect(panel.getByRole('heading', { name: 'Post to Department Updates' })).toBeVisible();
    await expect(panel.getByTestId('department-updates-about')).toHaveText('Department Updates appear on the MBFD Hub home page for everyone in the department. For private messages to administrators, Captain 5 and 300, use Messages.');
    await expect(panel.getByTestId('department-updates-byline')).toContainText('Posted by');
    await expect(panel.getByTestId('department-updates-byline')).toContainText('rank and name');
    // Rank and name come from the account; there is no field for them.
    await expect(form(page).getByLabel(/name|rank|author/i)).toHaveCount(0);
    await expect(form(page).getByLabel('Category')).toHaveValue('operations');
    await expect(form(page).getByLabel('Category').locator('option')).toHaveText(['Operations', 'General', 'Training']);
    await expect(form(page).getByLabel('Remove from the home page after')).toBeVisible();
    await expect(panel.getByText('Nothing posted from this page yet')).toBeVisible();
    await panel.getByRole('button', { name: 'Go to Messages' }).click();
    await expect(page.getByTestId('command-comms')).toBeVisible();
  });

  test('posts title, text, picture and file as multipart with a request id, then links to the update', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=updates');
    await form(page).getByLabel('Title').fill('Rescue 3 back in service');
    await form(page).getByLabel('Details').fill('Rescue 3 returns to service at 0800.');
    await form(page).getByLabel('Category').selectOption('training');
    await form(page).getByLabel('Remove from the home page after').fill(ymd(10));
    await form(page).getByLabel('Picture (optional)').setInputFiles({ name: 'rescue3.png', mimeType: 'image/png', buffer: PNG });
    await form(page).getByLabel('File (optional)').setInputFiles({ name: 'schedule.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 test') });
    await form(page).getByRole('button', { name: 'Post to Department Updates' }).click();

    const success = page.getByTestId('department-update-success');
    await expect(success).toContainText('Posted. Everyone in the department can now see it on the home page.');
    await expect(success.getByRole('link', { name: /View the update/ })).toHaveAttribute('href', '/updates/501');
    expect(server.updateForms).toHaveLength(1);
    expect(server.updateForms[0].fields).toMatchObject({ title: 'Rescue 3 back in service', body: 'Rescue 3 returns to service at 0800.', category: 'training', expires_at: ymd(10) });
    expect(server.updateForms[0].fields.client_request_id).toMatch(UUID_PATTERN);
    expect(server.updateForms[0].files).toEqual({ image: 'rescue3.png', attachment: 'schedule.pdf' });
    // Fields are cleared after a confirmed post, and the new post is listed with its byline.
    await expect(form(page).getByLabel('Title')).toHaveValue('');
    const rows = page.getByTestId('department-update-row');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Rescue 3 back in service');
    await expect(rows.first()).toContainText('Posted by Capt. Alex Rivera');
    await expect(rows.first()).toContainText('Published');
  });

  test('lists recent posts, takes down only your own published post after confirming, and never offers it on others', async ({ page }) => {
    const server = newServer({ updates: [makeUpdate(12, { title: 'Mine', own: true }), makeUpdate(11, { title: 'Theirs', own: false, author: 'Lt. Pat Doe', author_rank: 'Lt.', author_name: 'Pat Doe' }), makeUpdate(10, { title: 'Old', status: 'taken_down' })] });
    await open(page, server, '/daily/captain-5?tab=updates');
    const rows = page.getByTestId('department-update-row');
    await expect(rows).toHaveCount(3);
    await expect(rows.filter({ hasText: 'Theirs' })).toContainText('Posted by Lt. Pat Doe');
    await expect(rows.filter({ hasText: 'Theirs' }).getByRole('button', { name: /Take down/ })).toHaveCount(0);
    await expect(rows.filter({ hasText: 'Old' })).toContainText('Taken down');
    await expect(rows.filter({ hasText: 'Old' }).getByRole('button', { name: /Take down/ })).toHaveCount(0);

    const mine = rows.filter({ hasText: 'Mine' });
    await mine.getByRole('button', { name: /Take down/ }).click();
    await expect(mine).toContainText('Take this off the home page?');
    expect(server.takeDowns).toEqual([]);
    await mine.getByRole('button', { name: 'Keep it up' }).click();
    expect(server.takeDowns).toEqual([]);
    await mine.getByRole('button', { name: /Take down/ }).click();
    await mine.getByRole('button', { name: 'Yes, take it down' }).click();
    await expect(mine).toContainText('Taken down');
    await expect(mine.getByRole('button', { name: /Take down/ })).toHaveCount(0);
    expect(server.takeDowns).toEqual([12]);
  });

  test('needs a title and text before anything is sent, and rejects a wrong or oversized file on the spot', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=updates');
    await form(page).getByRole('button', { name: 'Post to Department Updates' }).click();
    await expect(form(page).getByText('Add a title.')).toBeVisible();
    await expect(form(page).getByText('Write the update.')).toBeVisible();
    await form(page).getByLabel('Picture (optional)').setInputFiles({ name: 'huge.png', mimeType: 'image/png', buffer: Buffer.alloc(6 * 1024 * 1024) });
    await expect(form(page).getByText('The picture is larger than 5 MB. Choose a smaller one.')).toBeVisible();
    await form(page).getByLabel('File (optional)').setInputFiles({ name: 'run.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('x') });
    await expect(form(page).getByText('The file must be a PDF, DOCX, XLSX, PPTX, TXT or CSV document.')).toBeVisible();
    expect(server.updateForms).toHaveLength(0);
  });

  test('a 422 from the server shows the field error and keeps the text', async ({ page }) => {
    const server = newServer();
    server.onUpdatePost = async (route) => {
      await route.fulfill({ status: 422, json: { message: 'Invalid.', errors: { expires_at: ['The expires at field must be a date after now.'] } } });
      return true;
    };
    await open(page, server, '/daily/captain-5?tab=updates');
    await form(page).getByLabel('Title').fill('Keep my words');
    await form(page).getByLabel('Details').fill('Body that must survive.');
    await form(page).getByRole('button', { name: 'Post to Department Updates' }).click();
    await expect(form(page).getByText('The expires at field must be a date after now.')).toBeVisible();
    await expect(page.getByTestId('department-update-error')).toContainText('Check the highlighted fields');
    await expect(form(page).getByLabel('Title')).toHaveValue('Keep my words');
    await expect(form(page).getByLabel('Details')).toHaveValue('Body that must survive.');
    await expect(page.getByTestId('department-update-success')).toHaveCount(0);
  });

  test('offline: nothing is posted, the message says so, the text is kept, and it posts once back online', async ({ page, context }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=updates');
    await form(page).getByLabel('Title').fill('Offline draft');
    await form(page).getByLabel('Details').fill('Written without a signal.');
    await context.setOffline(true);
    await expect(form(page).getByText('Requires connection.', { exact: false })).toBeVisible();
    await form(page).getByRole('button', { name: 'Post to Department Updates' }).click();
    await expect(page.getByTestId('department-update-error')).toHaveText("You're offline. Nothing was posted; what you wrote is kept.");
    await expect(form(page).getByLabel('Title')).toHaveValue('Offline draft');
    await expect(form(page).getByLabel('Details')).toHaveValue('Written without a signal.');
    expect(server.updateForms).toHaveLength(0);

    await context.setOffline(false);
    await form(page).getByRole('button', { name: 'Try posting again' }).click();
    await expect(page.getByTestId('department-update-success')).toBeVisible();
    expect(server.updateForms).toHaveLength(1);
  });

  test('a dropped connection keeps the text and the retry reuses the SAME client_request_id', async ({ page }) => {
    const server = newServer();
    const keys: string[] = [];
    let drop = true;
    server.onUpdatePost = async (route) => {
      keys.push(parseMultipart(route.request().postData() ?? '').fields.client_request_id);
      if (!drop) return false;
      drop = false;
      await route.abort('failed');
      return true;
    };
    await open(page, server, '/daily/captain-5?tab=updates');
    await form(page).getByLabel('Title').fill('Retry me');
    await form(page).getByLabel('Details').fill('Same words.');
    await form(page).getByRole('button', { name: 'Post to Department Updates' }).click();
    await expect(page.getByTestId('department-update-error')).toContainText('it will not be posted twice');
    await expect(form(page).getByLabel('Title')).toHaveValue('Retry me');
    await form(page).getByRole('button', { name: 'Try posting again' }).click();
    await expect(page.getByTestId('department-update-success')).toBeVisible();
    expect(keys).toHaveLength(2);
    expect(keys[1]).toBe(keys[0]);
  });

  test('the draft text survives switching tabs', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=updates');
    await form(page).getByLabel('Title').fill('Half written');
    await page.getByRole('tab', { name: 'Today' }).click();
    await page.getByRole('tab', { name: 'Department Updates' }).click();
    await expect(form(page).getByLabel('Title')).toHaveValue('Half written');
  });
});

test.describe('responsive', () => {
  for (const [name, width, height] of [['phone', 390, 844], ['tablet', 768, 1024], ['desktop', 1440, 900], ['phone-narrow', 320, 568]] as const) {
    test(`${name} ${width}px: no horizontal overflow on Today, Issues, the sheet, and 44px touch targets`, async ({ page }) => {
      await page.setViewportSize({ width, height });
      const server = newServer({ issues: [makeIssue(1, { item: 'A very long equipment name that has to wrap instead of overflowing the row on a phone', compartment: 'Left side compartment number three' }), makeIssue(2, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine', severity: 'significant' })] });
      await open(page, server);
      await expect(page.getByTestId('captain5-handoff')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-today`);

      await page.getByRole('tab', { name: /^Issues/ }).click();
      await expect(page.getByTestId('captain5-unit-row')).toHaveCount(2);
      await expandUnits(page);
      await expect(page.getByTestId('captain5-issue-row')).toHaveCount(2);
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-issues`);
      const heights = await page.evaluate(() => {
        const targets = [...document.querySelectorAll<HTMLElement>('[data-testid="captain5-issue-row"], [data-testid="captain5-unit-row"], [data-testid="captain5-clear-issue"], [role="tab"], [aria-pressed]')];
        return targets.map((el) => Math.round(el.getBoundingClientRect().height));
      });
      expect(Math.min(...heights)).toBeGreaterThanOrEqual(44);

      await page.getByTestId('captain5-issue-row').first().click();
      await expect(sheet(page)).toBeVisible();
      await page.getByRole('group', { name: 'Issue actions' }).getByRole('button').first().click();
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-sheet`);
      const dialog = await sheet(page).evaluate((el) => ({ client: el.clientWidth, scroll: el.scrollWidth }));
      expect(dialog.scroll).toBeLessThanOrEqual(dialog.client);
      const buttons = await sheet(page).evaluate((el) => [...el.querySelectorAll<HTMLElement>('button')].map((b) => Math.round(b.getBoundingClientRect().height)));
      expect(Math.min(...buttons)).toBeGreaterThanOrEqual(44);
      await expect(page.getByTestId('captain5-issue-sheet')).toBeInViewport();
    });

    test(`${name} ${width}px: the shift line, the acting card and Department Updates fit with 44px targets`, async ({ page }) => {
      await page.setViewportSize({ width, height });
      const server = newServer({
        onDuty: acting('C', 'A Surprisingly Long Name For A Narrow Phone Screen', inHours(30)),
        updates: [makeUpdate(3, { title: 'A long update title that has to wrap instead of overflowing the row on a phone', excerpt: 'word '.repeat(60) })],
      });
      await open(page, server);
      await expect(page.getByTestId('captain5-duty-line')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-duty`);

      await page.getByTestId('captain5-acting-open').click();
      await page.getByRole('radio', { name: /^Custom end time/ }).check();
      await expect(page.getByTestId('captain5-acting-sheet')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-acting`);
      const sheetBox = await page.getByTestId('captain5-acting-sheet').evaluate((el) => ({ client: el.clientWidth, scroll: el.scrollWidth, buttons: [...el.querySelectorAll<HTMLElement>('button, label:has(input), input:not([type="radio"])')].map((b) => Math.round(b.getBoundingClientRect().height)) }));
      expect(sheetBox.scroll).toBeLessThanOrEqual(sheetBox.client);
      expect(Math.min(...sheetBox.buttons)).toBeGreaterThanOrEqual(44);
      await page.keyboard.press('Escape');

      await page.getByRole('tab', { name: 'Department Updates' }).click();
      await expect(page.getByTestId('department-update-row')).toHaveCount(1);
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-updates`);
      const controls = await page.getByTestId('department-updates').evaluate((el) => [...el.querySelectorAll<HTMLElement>('button, input:not([type="file"]), select, textarea, a')].map((c) => Math.round(c.getBoundingClientRect().height)));
      expect(Math.min(...controls)).toBeGreaterThanOrEqual(44);
    });
  }
});
