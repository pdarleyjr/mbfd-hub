import { expect, test, type Page } from '@playwright/test';
import {
  SHIFT_BASE, UNSET_DUTY, chiefOnDuty, handleShiftRoute, newShiftServer, rosterChief, rosterUnknown, seatAwaiting,
  type ShiftServer,
} from './support/command300-shift';

/**
 * The shift's 300 (Division Chief) on the 300 page and on the stations-list card, with every API response
 * mocked. Server contract: /api/command-300/shift, see support/command300-shift.ts. The Captain 5 equivalents
 * live in captain5-workspace.spec.ts.
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

const NEUTRAL_TONE = /hub-tag--(success|info|danger|warning)/;
const DASHES = /[–—]/;
const LONG_NAME = 'A Surprisingly Long Name For A Narrow Phone Screen';

const line = (page: Page) => page.getByTestId('command-duty-line');
const tag = (page: Page) => line(page).locator('.hub-tag');

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

  test('the page offers no control to change the name on the line', async ({ page }) => {
    await open(page, newShiftServer());
    await expect(line(page)).toHaveText('A Shift 300: Division Chief Jane Smith');
    await expect(page.getByTestId('command-acting-open')).toHaveCount(0);
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

  test('shows the unset state in a neutral tone', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: UNSET_DUTY }), '/daily/stations');
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

test.describe('where the 300 name comes from', () => {
  const pageNote = (page: Page) => page.getByTestId('command-duty-note');
  const cardNote = (page: Page) => page.getByTestId('daily-command-duty-note');
  const cardDuty = (page: Page) => page.getByTestId('daily-command-duty');

  test('an old answer with none of the new fields shows no note on the page or the card', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: chiefOnDuty('B') }));
    await expect(line(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    await expect(pageNote(page)).toHaveCount(0);

    await page.goto('/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    await expect(cardNote(page)).toHaveCount(0);
  });

  test('the roster keeps the Tag as it is and adds when it was updated, in Miami Beach time', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: rosterChief('B') }));
    await expect(line(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    await expect(tag(page)).toHaveClass(/hub-tag--info/);
    await expect(pageNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');

    await page.goto('/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    await expect(cardNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');
  });

  test('a person not matched to a Hub member says so', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: rosterChief('B', 'SMITH, JANE', 'DC', 'unlinked') }));
    await expect(line(page)).toHaveText('B Shift 300: DC SMITH, JANE');
    await expect(pageNote(page)).toHaveText('TeleStaff roster. Not matched to a Hub member.');
  });

  test('before the roster arrives and once it is late the seat says so', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: seatAwaiting('awaiting') }));
    await expect(line(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    await expect(pageNote(page)).toHaveText("Bid seat. Today's roster has not arrived.");

    const late = await page.context().newPage();
    await open(late, newShiftServer({ onDuty: seatAwaiting('delayed') }));
    await expect(pageNote(late)).toHaveText("Bid seat. Today's roster is late.");
  });

  for (const reason of ['conflict', 'needs_review'] as const) {
    test(`${reason}: the Tag says the roster needs review and only the page links to the Daily Roster`, async ({ page }) => {
      await open(page, newShiftServer({ onDuty: rosterUnknown(reason) }));
      await expect(line(page)).toHaveText('B Shift 300: roster needs review');
      await expect(tag(page)).toHaveClass(/hub-tag--warning/);
      const link = pageNote(page).getByRole('link', { name: 'Check the Daily Roster.' });
      await expect(link).toHaveAttribute('href', '/daily-roster');
      expect((await link.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);

      await page.goto('/daily/stations');
      await expect(cardDuty(page)).toHaveText('B Shift 300: roster needs review');
      await expect(cardNote(page)).toHaveText('Check the Daily Roster.');
      // The card is one big link; a link inside it would be invalid and unsafe to tap.
      await expect(page.getByTestId('daily-command-card').locator('a')).toHaveCount(0);
    });
  }

  test('a seat with nobody working it reads not set and points at the Daily Roster', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: rosterUnknown('no_working_row') }));
    await expect(line(page)).toHaveText('Shift 300 not set');
    await expect(pageNote(page)).toHaveText('Check the Daily Roster.');
  });

  test('the note has no dash characters and fits a narrow card', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: rosterChief('C', LONG_NAME, 'Assistant Division Chief', 'unlinked') }), '/daily/stations');
    await expect(cardNote(page)).toBeVisible();
    await expectNoHorizontalOverflow(page);
    expect(await cardNote(page).innerText()).not.toMatch(DASHES);
  });

  test('the card updates when the page comes back into view, with no loading state', async ({ page }) => {
    const server = newShiftServer({ onDuty: seatAwaiting('awaiting') });
    await open(page, server, '/daily/stations');
    await expect(cardNote(page)).toHaveText("Bid seat. Today's roster has not arrived.");

    server.onDuty = rosterChief('B', 'Pat Doe', 'Battalion Chief');
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(cardDuty(page)).toHaveText('B Shift 300: Battalion Chief Pat Doe');
    await expect(cardNote(page)).toHaveText('TeleStaff roster, updated 9:14 AM');
    await expect(page.getByTestId('daily-command-card').locator('.hub-skeleton')).toHaveCount(0);
  });

  test('the card refreshes every five minutes while visible, and a failed refresh keeps the name', async ({ page }) => {
    await page.clock.install();
    const server = newShiftServer({ onDuty: rosterChief('B') });
    await open(page, server, '/daily/stations');
    await expect(cardDuty(page)).toHaveText('B Shift 300: Division Chief Jane Smith');
    const before = server.shiftCalls;

    await page.clock.fastForward('04:59');
    expect(server.shiftCalls).toBe(before);

    server.onDuty = rosterChief('B', 'Pat Doe', 'Battalion Chief');
    await page.clock.fastForward('00:02');
    await expect(cardDuty(page)).toHaveText('B Shift 300: Battalion Chief Pat Doe');

    server.status = 500;
    await page.clock.fastForward('05:01');
    await expect.poll(() => server.shiftCalls).toBeGreaterThan(before + 1);
    await expect(cardDuty(page)).toHaveText('B Shift 300: Battalion Chief Pat Doe');
  });
});

test.describe('layout and copy', () => {
  test('a long name and its note fit, with no dash characters', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: rosterChief('C', LONG_NAME, 'Assistant Division Chief', 'unlinked') }));
    await expect(line(page)).toBeVisible();
    await expectNoHorizontalOverflow(page);
    expect(await page.getByTestId('command-duty').innerText()).not.toMatch(DASHES);
  });

  test('the stations-list card keeps its shape with a long name', async ({ page }) => {
    await open(page, newShiftServer({ onDuty: chiefOnDuty('C', LONG_NAME, 'Assistant Division Chief') }), '/daily/stations');
    await expect(page.getByTestId('daily-command-duty')).toBeVisible();
    await expectNoHorizontalOverflow(page);
    const card = await page.getByTestId('daily-command-card').boundingBox();
    const duty = await page.getByTestId('daily-command-duty').boundingBox();
    expect(duty && card && duty.x >= card.x - 1 && duty.x + duty.width <= card.x + card.width + 1).toBe(true);
  });
});
