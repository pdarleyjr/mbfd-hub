import { expect, test, type Page, type TestInfo } from '@playwright/test';
import {
  SHIFT_BASE, UNSET_DUTY, UUID_PATTERN, actingChief, chiefOnDuty, handleShiftRoute, makeRequest300, newShiftServer, noActing300, ymd,
  type ShiftServer,
} from './support/command300-shift';

/**
 * The shift's 300 (Division Chief) on the 300 page and on the stations-list card, and "Acting as 300 today?",
 * with every API response mocked. Server contract: /api/command-300 (shift, acting-requests*), see
 * support/command300-shift.ts. The Captain 5 equivalents live in captain5-workspace.spec.ts.
 */

const stations = [1, 2].map((number) => ({
  id: 40 + number, name: `Station ${number}`, address: '1 Test Street', city: 'Miami Beach', state: 'FL', zip_code: '33139', phone: '305-555-0100',
  station_number: number, is_active: true, created_at: '2026-08-31T00:00:00.000000Z', updated_at: '2026-08-31T00:00:00.000000Z',
}));

const overview = {
  generated_at: new Date().toISOString(),
  total: stations.length,
  stations: stations.map((station) => ({
    ...station, apparatus_count: 0, open_defects: 0, open_service_tickets: 0, last_checkout_at: null,
    daily_checkout: { required_total: 0, completed: 0, not_checked: 0, review_pending: 0, out_of_service: 0, completion_percent: null },
    apparatuses: [],
  })),
};

async function mockApi(page: Page, shift: ShiftServer): Promise<void> {
  await page.route('**/images/**', (route) => route.fulfill({ status: 204 }));
  await page.route('**/favicon*', (route) => route.fulfill({ status: 204 }));
  await page.route('**/api/**', async (route) => {
    const { pathname } = new URL(route.request().url());
    if (pathname === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: 301, has_personnel_profile: true },
          personnel: { employee_profile_id: 1_301, employee_number: 'E301', name: 'Test Member', rank: 'Battalion Chief' },
          offline: { security_version: 1 },
          session: { authenticated: true },
          authorization: { capabilities: { command_300_access: true } },
        },
      });
    }
    if (pathname.startsWith(SHIFT_BASE)) return handleShiftRoute(route, pathname, shift);
    if (pathname === '/api/public/stations') return route.fulfill({ json: { stations } });
    if (pathname === '/api/public/command/overview') return route.fulfill({ json: overview });
    if (pathname === '/api/command-communications/posts') return route.fulfill({ json: { data: [], pinned: [], next_before: null, unread_count: 0 } });
    if (pathname === '/api/command-communications/summary') return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    if (pathname === '/api/command-communications/read') return route.fulfill({ json: { unread_count: 0, last_read_at: new Date().toISOString() } });
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
  });
}

async function open(page: Page, shift: ShiftServer, path = '/daily/command-300'): Promise<void> {
  await mockApi(page, shift);
  await page.goto(path);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client);
}

/** The request flow does not depend on width, so it runs at one phone and one desktop size; layout runs everywhere. */
const FLOW_PROJECTS = ['daily-responsive-phone-320', 'daily-responsive-desktop-1280'];
const flowOnly = (testInfo: TestInfo): void => {
  test.skip(!FLOW_PROJECTS.includes(testInfo.project.name), 'The request flow is width independent.');
};

const NEUTRAL_TONE = /hub-tag--(success|info|danger|warning)/;
const DASHES = /[–—]/;
const LONG_NAME = 'A Surprisingly Long Name For A Narrow Phone Screen';

const line = (page: Page) => page.getByTestId('command-duty-line');
const tag = (page: Page) => line(page).locator('.hub-tag');
const openButton = (page: Page) => page.getByTestId('command-acting-open');
const sheet = (page: Page) => page.getByTestId('command-acting-sheet');
const mine = (page: Page) => page.getByTestId('command-acting-mine');
const sheetError = (page: Page) => sheet(page).getByTestId('command-acting-error');

test.describe('the shift 300 line on the 300 page', () => {
  const shifts = [
    { shift: 'A', tone: 'success' },
    { shift: 'B', tone: 'info' },
    { shift: 'C', tone: 'danger' },
  ] as const;

  for (const { shift, tone } of shifts) {
    test(`${shift} Shift reads with the rank and the name, in the ${tone} tone`, async ({ page }) => {
      await open(page, newShiftServer({ onDuty: chiefOnDuty(shift, 'Jane Smith', 'Division Chief') }));
      await expect(line(page)).toHaveText(`${shift} Shift 300: Division Chief Jane Smith`);
      await expect(tag(page)).toHaveClass(new RegExp(`hub-tag--${tone}`));
    });
  }

  test('without a rank the line shows the name alone', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: chiefOnDuty('B', 'Jane Smith', null) }));
    await expect(line(page)).toHaveText('B Shift 300: Jane Smith');
  });

  test('an approved acting member reads "Acting 300 ... until <time>" in the shift colour', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: actingChief('C', 'Pat Doe') }));
    await expect(line(page)).toHaveText(/^C Shift Acting 300: Battalion Chief Pat Doe until \S+/);
    await expect(tag(page)).toHaveClass(/hub-tag--danger/);
  });

  test('an unresolved shift says "Shift 300 not set" in a neutral tone', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: UNSET_DUTY }));
    await expect(line(page)).toHaveText('Shift 300 not set');
    await expect(tag(page)).not.toHaveClass(NEUTRAL_TONE);
  });

  test('resolved false never shows a name, even if the server sends one', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: { ...chiefOnDuty('A', 'Stray Name'), resolved: false } }));
    await expect(line(page)).toHaveText('Shift 300 not set');
    await expect(page.getByText('Stray Name')).toHaveCount(0);
    await expect(tag(page)).not.toHaveClass(NEUTRAL_TONE);
  });

  test('a failed load shows a retryable error, and a failed refresh keeps the last name with a warning', async ({ page }) => {
    const shift = newShiftServer({ status: 503 });
    await open(page, shift);
    const failure = page.getByTestId('command-duty-error');
    await expect(failure).toContainText('The shift 300 could not be loaded.');
    await expect(failure.getByRole('button', { name: 'Try again' })).toBeVisible();
    await expect(page.getByTestId('command-acting')).toHaveCount(0);
    shift.status = 200;
    await failure.getByRole('button', { name: 'Try again' }).click();
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');

    shift.status = 503;
    await page.getByTestId('command-overview-refresh').click();
    await expect(page.getByTestId('command-duty-stale')).toContainText('may be out of date');
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');
  });
});

test.describe('the shift 300 on the stations-list card', () => {
  const duty = (page: Page) => page.getByTestId('daily-command-duty');

  test('shows the shift, rank and name with the A, B and C colours', async ({ page }) => {
    const server = newShiftServer({ onDuty: chiefOnDuty('A') });
    await open(page, server, '/daily/stations');
    await expect(duty(page)).toHaveText('A Shift 300: Division Chief Jane Smith');
    await expect(duty(page).locator('.hub-tag')).toHaveClass(/hub-tag--success/);

    for (const [shift, tone] of [['B', 'info'], ['C', 'danger']] as const) {
      server.onDuty = chiefOnDuty(shift, 'Pat Doe', 'Battalion Chief');
      await page.reload();
      await expect(duty(page)).toHaveText(`${shift} Shift 300: Battalion Chief Pat Doe`);
      await expect(duty(page).locator('.hub-tag')).toHaveClass(new RegExp(`hub-tag--${tone}`));
    }
  });

  test('shows the acting wording and the unset state, and offers no acting control', async ({ page }) => {
    const server = newShiftServer({ onDuty: actingChief('B', 'Pat Doe') });
    await open(page, server, '/daily/stations');
    await expect(duty(page)).toHaveText(/^B Shift Acting 300: Battalion Chief Pat Doe until \S+/);
    await expect(page.getByTestId('daily-command-card')).not.toContainText('Click here');
    await expect(page.getByTestId('command-acting-open')).toHaveCount(0);

    server.onDuty = UNSET_DUTY;
    await page.reload();
    await expect(duty(page)).toHaveText('Shift 300 not set');
    await expect(duty(page).locator('.hub-tag')).not.toHaveClass(NEUTRAL_TONE);
  });

  test('fails quietly: no line at all when the shift cannot be loaded, and the card still opens', async ({ page }) => {
    await open(page, newShiftServer({ status: 500 }), '/daily/stations');
    await expect(page.getByTestId('daily-command-card')).toBeVisible();
    await expect(duty(page)).toHaveCount(0);
    await expect(page.getByRole('alert')).toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await page.getByTestId('daily-command-card').click();
    await expect(page).toHaveURL(/\/daily\/command-300$/);
  });
});

test.describe('acting as 300', () => {
  test('the control sits under the shift line and opens a card with the plain explanation and three choices', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    await open(page, newShiftServer());
    await expect(openButton(page)).toHaveText('Acting as 300 today? Click here.');
    await openButton(page).click();
    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page).getByRole('heading', { name: 'Acting as 300 today?' })).toBeVisible();
    await expect(sheet(page)).toContainText('An administrator approves this. It only changes the name shown at the top; your work on this page is always recorded under your own name, whether or not you use this.');
    await expect(sheet(page).getByRole('radio', { name: /^12 hours\s+ends / })).toBeChecked();
    await expect(sheet(page).getByRole('radio', { name: /^24 hours\s+ends / })).not.toBeChecked();
    await expect(sheet(page).getByRole('radio', { name: /^Custom end time/ })).not.toBeChecked();
    await expect(sheet(page).getByRole('button', { name: 'End the current acting 300' })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(sheet(page)).toHaveCount(0);
    await expect(openButton(page)).toBeFocused();
  });

  test('the control opens from the keyboard', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    await open(page, newShiftServer());
    await openButton(page).focus();
    await page.keyboard.press('Enter');
    await expect(sheet(page)).toBeVisible();
    await expect(sheet(page).getByRole('button', { name: 'Request to act as 300' })).toBeVisible();
  });

  test('can_request false hides the control, and a plain member sees no approvals', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    await open(page, newShiftServer({ acting: noActing300({ can_request: false, pending: [makeRequest300(7)] }) }));
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');
    await expect(page.getByTestId('command-acting')).toHaveCount(0);
    await expect(page.getByText('Click here')).toHaveCount(0);
    await expect(page.getByTestId('command-acting-approvals')).toHaveCount(0);
    await expect(page.getByText('Waiting for approval')).toHaveCount(0);
  });

  test('12 hours: posts kind act with a request id, closes, shows the pending request, and Withdraw takes it back', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer();
    await open(page, server);
    await openButton(page).click();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(1);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'act', duration: '12h' });
    expect(server.actingBodies[0]).not.toHaveProperty('ends_at');
    expect(String(server.actingBodies[0].client_request_id)).toMatch(UUID_PATTERN);
    await expect(mine(page)).toContainText('Your request to act as 300 until');
    await expect(mine(page)).toContainText('is waiting for approval');
    // Acting is display only: the shown name has not changed.
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');

    await mine(page).getByRole('button', { name: 'Withdraw' }).click();
    await expect(mine(page)).toHaveCount(0);
    expect(server.decisions[0].call).toMatch(/^withdraw:\d+$/);
  });

  test('24 hours posts duration 24h; a custom end time posts the local datetime and needs a time first', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer();
    await open(page, server);
    await openButton(page).click();
    await sheet(page).getByRole('radio', { name: /^24 hours/ }).check();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'act', duration: '24h' });
    expect(server.actingBodies[0]).not.toHaveProperty('ends_at');
    await mine(page).getByRole('button', { name: 'Withdraw' }).click();
    await expect(mine(page)).toHaveCount(0);

    await openButton(page).click();
    await sheet(page).getByRole('radio', { name: /^Custom end time/ }).check();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheetError(page)).toHaveText('Choose when you stop acting as 300.');
    expect(server.actingBodies).toHaveLength(1);
    const end = `${ymd(1)}T09:30`;
    await sheet(page).getByLabel('Stop acting at').fill(end);
    await sheet(page).getByRole('button', { name: /Request to act as 300|Try again/ }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(2);
    expect(server.actingBodies[1]).toMatchObject({ kind: 'act', duration: 'custom', ends_at: end });
  });

  test('while someone is shown as acting, the card explains the takeover and can end the current acting 300', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer({ onDuty: actingChief('B', 'Pat Doe') });
    await open(page, server);
    await openButton(page).click();
    await expect(page.getByTestId('command-acting-takeover')).toContainText('Pat Doe is shown as acting 300 until');
    await expect(page.getByTestId('command-acting-takeover')).toContainText('your name replaces theirs');
    await sheet(page).getByRole('button', { name: 'End the current acting 300' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies[0]).toMatchObject({ kind: 'end' });
    expect(server.actingBodies[0]).not.toHaveProperty('duration');
    expect(String(server.actingBodies[0].client_request_id)).toMatch(UUID_PATTERN);
    await expect(mine(page)).toContainText('Your request to end the current acting 300 is waiting for approval.');

    // Asking to act while someone else is shown is the same act request (a takeover), with a new key.
    await openButton(page).click();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies[1]).toMatchObject({ kind: 'act', duration: '12h' });
    expect(server.actingBodies[1].client_request_id).not.toBe(server.actingBodies[0].client_request_id);
  });

  test('a dropped connection keeps the card open and the retry reuses the SAME client_request_id; a changed request gets a new one', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer();
    const keys: string[] = [];
    let drops = 2;
    server.onActing = async (route, body) => {
      keys.push(String(body.client_request_id));
      if (drops === 0) return false;
      drops -= 1;
      await route.abort('failed');
      return true;
    };
    await open(page, server);
    await openButton(page).click();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheetError(page)).toContainText('nothing is confirmed');
    await sheet(page).getByRole('button', { name: 'Try again' }).click();
    await expect.poll(() => keys.length).toBe(2);
    await expect(sheetError(page)).toContainText('nothing is confirmed');
    expect(keys[1]).toBe(keys[0]);

    // Same form, different choice: this is a different request, so it cannot reuse the key.
    await sheet(page).getByRole('radio', { name: /^24 hours/ }).check();
    await sheet(page).getByRole('button', { name: 'Try again' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(keys).toHaveLength(3);
    expect(keys[2]).not.toBe(keys[0]);
    expect(server.actingBodies[2]).toMatchObject({ kind: 'act', duration: '24h' });
  });

  test('a refused request shows the server reason and the next try is a new request', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer();
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
    await openButton(page).click();
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheetError(page)).toHaveText('Choose an end time between 15 minutes and 72 hours from now.');
    await sheet(page).getByRole('button', { name: 'Try again' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(keys[1]).not.toBe(keys[0]);
  });

  test('offline: nothing is sent and the card says so', async ({ page, context }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer();
    await open(page, server);
    await openButton(page).click();
    await context.setOffline(true);
    await sheet(page).getByRole('button', { name: 'Request to act as 300' }).click();
    await expect(sheetError(page)).toHaveText("You're offline. Nothing was sent. Reconnect, then try again.");
    expect(server.actingBodies).toHaveLength(0);
    await context.setOffline(false);
    await sheet(page).getByRole('button', { name: 'Try again' }).click();
    await expect(sheet(page)).toHaveCount(0);
    expect(server.actingBodies).toHaveLength(1);
  });
});

test.describe('approving acting 300 requests', () => {
  const approvals = (page: Page) => page.getByTestId('command-acting-approvals');

  test('an approver sees the waiting requests; approving shows the new name at the top', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer({
      acting: noActing300({
        can_approve: true,
        pending: [makeRequest300(7), makeRequest300(8, { kind: 'end', name: null, rank: null, duration: null, ends_at: null, requested_by: 'Sam Ortiz' })],
      }),
    });
    await open(page, server);
    await expect(approvals(page).getByRole('heading', { name: 'Waiting for approval' })).toBeVisible();
    const rows = approvals(page).getByTestId('command-acting-pending');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Battalion Chief Pat Doe to act as 300 until');
    await expect(rows.nth(1)).toContainText('End the current acting 300');
    await rows.first().getByRole('button', { name: /^Approve/ }).click();
    await expect(rows).toHaveCount(1);
    expect(server.decisions[0]).toEqual({ call: 'approve:7', body: {} });
    await expect(line(page)).toHaveText(/^B Shift Acting 300: Battalion Chief Pat Doe until /);
  });

  test('"Not approved" needs a note, then sends it', async ({ page }, testInfo) => {
    flowOnly(testInfo);
    const server = newShiftServer({ acting: noActing300({ can_approve: true, pending: [makeRequest300(7)] }) });
    await open(page, server);
    const row = page.getByTestId('command-acting-pending');
    await row.getByRole('button', { name: /^Not approved/ }).click();
    await row.getByRole('button', { name: 'Send decision' }).click();
    await expect(row.getByText('Write a short note so the member knows why.')).toBeVisible();
    expect(server.decisions).toHaveLength(0);
    await row.getByLabel('Why is this not approved?').fill('The 300 is on duty tonight');
    await row.getByRole('button', { name: 'Send decision' }).click();
    await expect(approvals(page)).toHaveCount(0);
    expect(server.decisions[0]).toEqual({ call: 'reject:7', body: { note: 'The 300 is on duty tonight' } });
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');
  });
});

test.describe('layout and copy', () => {
  test('a long acting name and the open card fit, with 44px targets and no dash characters', async ({ page }) => {
    const server = newShiftServer({
      onDuty: actingChief('C', LONG_NAME, 'Assistant Division Chief', new Date(Date.now() + 30 * 3_600_000).toISOString()),
      acting: noActing300({ can_approve: true, pending: [makeRequest300(7, { name: LONG_NAME, rank: 'Assistant Division Chief' })] }),
    });
    await open(page, server);
    await expect(line(page)).toBeVisible();
    await expectNoHorizontalOverflow(page);
    expect((await openButton(page).boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    expect(await page.getByTestId('command-duty').innerText()).not.toMatch(DASHES);

    await openButton(page).click();
    await expect(sheet(page)).toBeVisible();
    const metrics = await sheet(page).evaluate((element) => ({
      client: element.clientWidth,
      scroll: element.scrollWidth,
      heights: [...element.querySelectorAll<HTMLElement>('button, label:has(input[type="radio"])')].map((control) => Math.round(control.getBoundingClientRect().height)),
    }));
    expect(metrics.scroll).toBeLessThanOrEqual(metrics.client);
    for (const height of metrics.heights) expect(height).toBeGreaterThanOrEqual(44);
    expect(await sheet(page).innerText()).not.toMatch(DASHES);
    await page.keyboard.press('Escape');

    expect(await page.getByTestId('command-acting-approvals').innerText()).not.toMatch(DASHES);
    await expectNoHorizontalOverflow(page);
  });

  test('the stations-list card keeps its shape with a long name', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: actingChief('C', LONG_NAME, 'Assistant Division Chief') }), '/daily/stations');
    await expect(page.getByTestId('daily-command-duty')).toBeVisible();
    await expectNoHorizontalOverflow(page);
    const card = await page.getByTestId('daily-command-card').boundingBox();
    const duty = await page.getByTestId('daily-command-duty').boundingBox();
    expect(duty && card && duty.x >= card.x - 1 && duty.x + duty.width <= card.x + card.width + 1).toBe(true);
  });
});
