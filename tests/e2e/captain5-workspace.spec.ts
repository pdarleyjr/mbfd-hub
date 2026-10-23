import { expect, test, type Page, type Route } from '@playwright/test';
import type {
  Captain5Access, DutyAssignment, HandoffNote, InspectionView, IssueDetail, RescueDaily,
} from '../../resources/js/daily-checkout/src/components/captain5/types';

/**
 * Captain 5 EMS Operations Center (member page), every API response mocked.
 * Server contract: /api/captain5/* (summary, workspace, issues, issues/{id}, issues/{id}/actions,
 * issues/{id}/messages, inspections/{id}, handoff, assignments, people) and
 * /api/captain5/communications/* for the Messages tab.
 */

const KEY_PATTERN = /^[A-Za-z0-9-]{8,48}$/;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const minutesAgo = (minutes: number): string => new Date(Date.now() - minutes * 60_000).toISOString();
const ymd = (offsetDays: number): string => {
  const date = new Date(Date.now() + offsetDays * 86_400_000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const ACCESS_ACT: Captain5Access = {
  view: true, act: true, on_duty: true, dismiss: true, manage_assignments: false, configure: false,
  acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)',
};
const ACCESS_VIEW: Captain5Access = { ...ACCESS_ACT, act: false, on_duty: false, dismiss: false, authority: 'view_only', authority_label: 'EMS Division (view)' };
const ACCESS_MANAGER: Captain5Access = { ...ACCESS_ACT, manage_assignments: true, authority_label: 'EMS Captain' };

const ACTIONS_REPORTED: IssueDetail['actions'] = ['acknowledge', 'ordered', 'available_for_pickup', 'replaced', 'dismiss'];

function makeIssue(id: number, overrides: Partial<IssueDetail> = {}): IssueDetail {
  return {
    id, kind: 'rescue', apparatus_id: 1, unit: 'Rescue 1', compartment: 'Medical bag', item: 'Pulse oximeter', issue_type: 'Missing',
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
  onDuty: { resolved: boolean; name: string | null; rank: string | null; shift: string | null; source: string };
  issues: IssueDetail[];
  closed: IssueDetail[];
  detailStatus: number;
  notes: HandoffNote[];
  assignments: DutyAssignment[];
  actionBodies: Array<Record<string, unknown>>;
  messageBodies: Array<Record<string, unknown>>;
  handoffBodies: Array<Record<string, unknown>>;
  assignBodies: Array<Record<string, unknown>>;
  revokeBodies: Array<Record<string, unknown>>;
  clarifications: Array<Record<string, unknown>>;
  historyQueries: string[];
  requests: string[];
  /** Return true after fulfilling to take over an action request. */
  onAction?: (route: Route, body: Record<string, unknown>, server: Server) => Promise<boolean>;
}

function newServer(overrides: Partial<Server> = {}): Server {
  return {
    summaryStatus: 200, workspaceStatus: 200, access: ACCESS_ACT,
    onDuty: { resolved: true, name: 'Alex Rivera', rank: 'Capt.', shift: 'B', source: 'bid_holder' },
    issues: [makeIssue(1), makeIssue(2, { kind: 'medical', unit: 'Engine 1', item: 'Epinephrine', severity: 'significant' })],
    closed: [], detailStatus: 200, notes: [handoffNote], assignments: [],
    actionBodies: [], messageBodies: [], handoffBodies: [], assignBodies: [], revokeBodies: [], clarifications: [], historyQueries: [], requests: [],
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
  const next = NEXT_STATE[String(body.action)];
  if (!next) return issue;
  const serial = Number(issue.state_token.split(':')[1]) + 1;
  return {
    ...issue, action_status: next.status, state_token: `${next.status}:${serial}`, actions: next.actions, open: next.status !== 'resolved',
    details: { ...issue.details, ...(body.vendor_reference ? { vendor_reference: String(body.vendor_reference) } : {}) },
    expected_delivery: typeof body.expected_delivery === 'string' ? body.expected_delivery : issue.expected_delivery,
  };
}

const countsOf = (issues: IssueDetail[]) => {
  const open = issues.filter((issue) => issue.open);
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

async function handleCaptain5(route: Route, server: Server, pathname: string, search: URLSearchParams): Promise<void> {
  const method = route.request().method();
  const issueMatch = /^\/api\/captain5\/issues\/(\d+)(?:\/(actions|messages))?$/.exec(pathname);
  if (pathname === '/api/captain5/summary') {
    return server.summaryStatus === 200
      ? route.fulfill({ json: { access: server.access, on_duty: { duty_date: ymd(0), assignment_id: null, ...server.onDuty }, open_issues: server.issues.filter((i) => i.open).length } })
      : route.fulfill({ status: server.summaryStatus, json: { message: 'Forbidden' } });
  }
  if (pathname === '/api/captain5/workspace') {
    if (server.workspaceStatus !== 200) return route.fulfill({ status: server.workspaceStatus, json: { message: 'Forbidden' } });
    return route.fulfill({
      json: {
        access: server.access, on_duty: { duty_date: ymd(0), assignment_id: null, ...server.onDuty }, duty_date: ymd(0), counts: countsOf(server.issues),
        rescue_dailies: rescueDailies, issues: server.issues.filter((i) => i.open), handoff: { duty_date: ymd(0), notes: server.notes },
        assignments: server.assignments, generated_at: new Date().toISOString(),
      },
    });
  }
  if (pathname === '/api/captain5/issues' && method === 'GET') {
    if (search.get('status') === 'closed') server.historyQueries.push(search.get('q') ?? '');
    const list = search.get('status') === 'closed'
      ? server.closed.filter((issue) => !search.get('q') || issue.item.toLowerCase().includes(String(search.get('q')).toLowerCase()))
      : server.issues.filter((issue) => issue.open);
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
  if (pathname === '/api/captain5/people') {
    return route.fulfill({ json: { people: [{ id: 77, name: 'Jordan Lee', rank: 'Lt.', station: 'Station 2' }] } });
  }
  if (pathname === '/api/captain5/assignments' && method === 'POST') {
    const body = await readBody(route);
    server.assignBodies.push(body);
    server.assignments = [{ id: 9, duty_date: String(body.duty_date), shift: 'C', name: 'Jordan Lee', rank: 'Lt.', reason: String(body.reason), assigned_by: 'EMS Captain', starts_at: null, ends_at: null }];
    return route.fulfill({ status: 201, json: { assignments: server.assignments } });
  }
  const revoke = /^\/api\/captain5\/assignments\/(\d+)\/revoke$/.exec(pathname);
  if (revoke) {
    server.revokeBodies.push(await readBody(route));
    server.assignments = [];
    return route.fulfill({ json: { assignments: [] } });
  }
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

test.describe('station list card', () => {
  test('appears with the open issue count only when the summary answers 200', async ({ page }) => {
    await open(page, newServer(), '/daily/stations');
    const card = page.getByTestId('daily-captain5-card');
    await expect(card).toBeVisible();
    await expect(card).toContainText('Captain 5');
    await expect(card).toContainText('EMS');
    await expect(page.getByTestId('daily-captain5-open')).toHaveText('2 open issues');
    await expect(card.getByText('Open Captain 5')).toBeVisible();
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

test.describe('workspace shell', () => {
  test('shows the duty line, authority and the tabs, with Duty hidden from non-managers', async ({ page }) => {
    await open(page, newServer());
    await expect(page.getByRole('heading', { name: 'Captain 5 · EMS Operations', level: 1 })).toBeVisible();
    await expect(page.getByTestId('captain5-duty-line')).toHaveText('On duty: Capt. Alex Rivera (B Shift)');
    await expect(page.getByTestId('captain5-authority')).toContainText('Captain 5 (on duty)');
    const tabs = page.getByRole('tablist', { name: 'Captain 5 workspace' });
    await expect(tabs.getByRole('tab')).toHaveText([/Today/, /Issues\s*2/, /Replacements/, /Messages\s*3/, /History/]);
    await expect(tabs.getByRole('tab', { name: 'Duty' })).toHaveCount(0);
    await expect(page.getByText('View only')).toHaveCount(0);
  });

  test('shows Acting wording, an unresolved duty warning and the Duty tab for managers', async ({ page }) => {
    await open(page, newServer({ access: ACCESS_MANAGER, onDuty: { resolved: true, name: 'Jordan Lee', rank: 'Lt.', shift: 'C', source: 'acting_assignment' } }));
    await expect(page.getByTestId('captain5-duty-line')).toHaveText('Acting: Lt. Jordan Lee (C Shift)');
    await expect(page.getByRole('tab', { name: 'Duty' })).toBeVisible();

    const unresolved = await page.context().newPage();
    await open(unresolved, newServer({ onDuty: { resolved: false, name: null, rank: null, shift: null, source: 'unknown' } }));
    await expect(unresolved.getByTestId('captain5-duty-line')).toHaveText('On-duty Captain 5 not resolved');
  });

  test('tabs follow the URL, support the keyboard and a deep link opens the right tab', async ({ page }) => {
    await open(page, newServer(), '/daily/captain-5?tab=replacements');
    await expect(page.getByRole('tab', { name: /Replacements/ })).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('tab', { name: /Replacements/ }).press('ArrowRight');
    await expect(page.getByRole('tab', { name: /Messages/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(/tab=messages/);
    await page.getByRole('tab', { name: 'Today' }).click();
    await expect(page).not.toHaveURL(/tab=/);
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
    await expect(group.getByRole('button', { name: 'Close: no action required' })).toHaveCount(0);
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
    await page.getByRole('group', { name: 'Issue actions' }).getByRole('button', { name: 'Close: no action required' }).click();
    await actionForm(page).getByRole('button', { name: 'Close with no action' }).click();
    await expect(actionForm(page).getByText('Give a reason.')).toBeVisible();
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

test.describe('Replacements, History and Duty', () => {
  test('Replacements lists ordered and ready items, overdue first, with delivery and vendor reference', async ({ page }) => {
    const server = newServer({
      issues: [
        makeIssue(1),
        makeIssue(2, { item: 'Backboard straps', action_status: 'action_pending', expected_delivery: ymd(6), details: { vendor_reference: 'PO-LATE' }, state_token: 'action_pending:20' }),
        makeIssue(3, { item: 'AED pads', action_status: 'replacement_available', state_token: 'replacement_available:30' }),
        makeIssue(4, { item: 'Suction tubing', action_status: 'action_pending', expected_delivery: ymd(-2), overdue: true, state_token: 'action_pending:40' }),
      ],
    });
    await open(page, server, '/daily/captain-5?tab=replacements');
    const rows = page.getByTestId('captain5-issue-row');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('Suction tubing');
    await expect(rows.nth(0)).toContainText('Overdue');
    await expect(rows.nth(1)).toContainText('AED pads');
    await expect(rows.nth(1)).toContainText('Ready for pickup');
    await expect(rows.nth(2)).toContainText('Backboard straps');
    await expect(rows.nth(2)).toContainText('PO-LATE');
    await rows.nth(2).click();
    await expect(sheet(page)).toContainText('Backboard straps');
  });

  test('History searches closed issues through the server', async ({ page }) => {
    const server = newServer({ closed: [makeIssue(8, { item: 'Glucometer', open: false, action_status: 'resolved', resolved_at: minutesAgo(2000), resolution_type: 'replacement_installed', actions: ['reopen'] }), makeIssue(9, { item: 'Bandage shears', open: false, action_status: 'dismissed', resolved_at: minutesAgo(3000), actions: ['reopen'] })] });
    await open(page, server, '/daily/captain-5?tab=history');
    await expect(page.getByTestId('captain5-history-row')).toHaveCount(2);
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

  test('Duty (managers only): search a member, assign for a date with a reason, then end the assignment with a reason', async ({ page }) => {
    const server = newServer({ access: ACCESS_MANAGER });
    await open(page, server, '/daily/captain-5?tab=duty');
    const form = page.getByTestId('captain5-assign-form');
    await form.getByLabel('Member').fill('jor');
    await form.getByRole('button', { name: /Jordan Lee/ }).click();
    await form.getByLabel('Duty date').fill(ymd(2));
    await form.getByRole('button', { name: 'Confirm assignment' }).click();
    await expect(form.getByText('Give a reason for this assignment.')).toBeVisible();
    await form.getByLabel('Reason').fill('Captain out sick');
    await form.getByRole('button', { name: 'Confirm assignment' }).click();
    await expect(page.getByTestId('captain5-assignment')).toContainText('Lt. Jordan Lee');
    expect(server.assignBodies[0]).toMatchObject({ employee_profile_id: 77, duty_date: ymd(2), reason: 'Captain out sick' });
    expect(String(server.assignBodies[0].client_request_id)).toMatch(KEY_PATTERN);

    const row = page.getByTestId('captain5-assignment');
    await row.getByRole('button', { name: /End assignment/ }).click();
    await row.getByRole('button', { name: 'End assignment' }).click();
    await expect(row.getByText('Give a reason for ending this assignment.')).toBeVisible();
    await row.getByLabel('Why is this assignment ending?').fill('Captain returned');
    await row.getByRole('button', { name: 'End assignment' }).click();
    await expect(page.getByTestId('captain5-assignment')).toHaveCount(0);
    expect(server.revokeBodies[0]).toEqual({ reason: 'Captain returned' });
  });

  test('Messages reads the Captain 5 channel of the shared conversation', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=messages');
    await expect(page.getByTestId('command-comms')).toBeVisible();
    expect(server.requests).toContain('GET /api/captain5/communications/posts');
    expect(server.requests).not.toContain('GET /api/command-communications/posts');
  });
});

test.describe('view-only access', () => {
  test('everything is readable but no action, handoff or message controls exist; "View only" is said once', async ({ page }) => {
    const server = newServer({ access: ACCESS_VIEW });
    await open(page, server);
    await expect(page.getByText('View only')).toHaveCount(1);
    await expect(page.getByTestId('captain5-authority')).toContainText('EMS Division (view)');
    await expect(page.getByTestId('captain5-handoff')).toContainText('Monitor in Rescue 3 is on order.');
    await expect(page.getByRole('button', { name: 'Acknowledge handoff' })).toHaveCount(0);
    await expect(page.getByLabel('Handoff note for the next Captain 5')).toHaveCount(0);

    await page.goto('/daily/captain-5?tab=issues&issue=1');
    await expect(sheet(page).getByRole('heading', { name: 'Rescue 1: Pulse oximeter' })).toBeVisible();
    await expect(page.getByTestId('captain5-actions')).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Issue actions' })).toHaveCount(0);
    await expect(page.getByTestId('captain5-thread')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send message' })).toHaveCount(0);
    await expect(page.getByTestId('captain5-timeline')).toBeVisible();
    expect(server.actionBodies).toHaveLength(0);
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
      await expect(page.getByTestId('captain5-issue-row')).toHaveCount(2);
      await expectNoHorizontalOverflow(page);
      await shot(page, `${name}-issues`);
      const heights = await page.evaluate(() => {
        const targets = [...document.querySelectorAll<HTMLElement>('[data-testid="captain5-issue-row"], [role="tab"], [aria-pressed]')];
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
  }
});
