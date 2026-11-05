import { expect, test, type Page } from '@playwright/test';
import { BOARD_BASE, handleBoardRoute, newBoardServer, type BoardServer } from './support/command300-board';
import { handleShiftRoute, newShiftServer, SHIFT_BASE } from './support/command300-shift';

/**
 * The 300 station dashboard: summary strip, one card per station, and the read-only station / checkout / request
 * drill-ins, with every API response mocked. Contract: /api/public/command/stations (support/command300-board.ts).
 * Set COMMAND300_SHOTS_DIR to also write screenshots of the page.
 */

async function mockApi(page: Page, board: BoardServer): Promise<void> {
  const shift = newShiftServer();
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
    if (pathname.startsWith(BOARD_BASE)) return void await handleBoardRoute(route, pathname, board);
    if (pathname.startsWith(SHIFT_BASE)) return handleShiftRoute(route, pathname, shift);
    if (pathname === '/api/command-communications/posts') return route.fulfill({ json: { data: [], pinned: [], next_before: null, unread_count: 0 } });
    if (pathname === '/api/command-communications/summary') return route.fulfill({ json: { unread_count: 0, latest_activity_at: null } });
    if (pathname === '/api/command-communications/read') return route.fulfill({ json: { unread_count: 0, last_read_at: new Date().toISOString() } });
    return route.fulfill({ status: 404, json: { message: `Unmocked route ${pathname}` } });
  });
}

async function open(page: Page, board: BoardServer = newBoardServer(), path = '/daily/command-300'): Promise<void> {
  await mockApi(page, board);
  await page.goto(path);
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client);
}

async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.COMMAND300_SHOTS_DIR;
  if (!dir) return;
  const viewport = page.viewportSize();
  await page.screenshot({ path: `${dir}/${name}-${viewport?.width}x${viewport?.height}.png`, fullPage: name === 'dashboard' });
}

const cards = (page: Page) => page.getByTestId('command-station-card');
const sheet = (page: Page) => page.getByTestId('command-station-sheet');

test('the dashboard summarizes the department and shows one card per station', async ({ page }) => {
  await open(page);
  await expect(cards(page)).toHaveCount(3);
  await expect(page.getByTestId('summary-checkouts')).toContainText('4 of 5');
  await expect(page.getByTestId('summary-requests')).toContainText('2');
  await expect(page.getByTestId('summary-vehicles')).toContainText('2');
  await expect(page.getByTestId('summary-attention')).toContainText('1 of 3');

  const first = cards(page).first();
  await expect(first).toContainText('Station 1');
  await expect(first).toContainText('3 working');
  await expect(first).toContainText('Jane Smith, John Doe, Sam Lee');
  await expect(first).toContainText('2 of 3');
  await expect(first.getByText('R1').locator('xpath=ancestor::li[1]')).toHaveAttribute('data-state', 'not_submitted');
  await expect(first).toContainText('2 open');
  await expect(first).toContainText('1 vehicle with issues');
  await expect(cards(page).nth(1)).toHaveAttribute('data-level', 'attention');
  await expect(page.getByTestId('command-board-status')).toContainText('refreshes every minute');
  await expect(page.getByRole('tab', { name: 'Communications' })).toHaveAttribute('aria-selected', 'true');
  await expectNoHorizontalOverflow(page);
  await shot(page, 'dashboard');
});

test('a station card opens a read-only detail with on shift, checkouts, requests and vehicle issues', async ({ page }) => {
  await open(page);
  await cards(page).first().click();
  await expect(page).toHaveURL(/station=41$/);
  await expect(sheet(page)).toBeVisible();
  for (const heading of ['On shift', 'Truck checkouts', 'Station requests', 'Vehicle issues']) {
    await expect(sheet(page).getByRole('heading', { name: heading })).toBeVisible();
  }
  await expect(sheet(page).getByTestId('command-crew-member')).toHaveCount(4);
  await expect(sheet(page).getByTestId('command-checkout-row')).toHaveCount(3);
  await expect(sheet(page).getByTestId('command-checkout-row').filter({ hasText: 'R1' })).toContainText('Not submitted yet');
  await expect(sheet(page).getByTestId('command-request-row')).toHaveCount(2);
  await expect(sheet(page).getByTestId('command-issue-group')).toContainText('Cracked mirror');
  // Read only: nothing to resolve, edit or save.
  await expect(sheet(page).getByRole('button', { name: /^(mark resolved|resolve|edit|save|delete|assign)/i })).toHaveCount(0);
  await expectNoHorizontalOverflow(page);
  await shot(page, 'station-detail');

  await page.keyboard.press('Escape');
  await expect(sheet(page)).toHaveCount(0);
  await expect(page).not.toHaveURL(/station=/);
  await expect(cards(page).first()).toBeFocused();
});

test('a submitted checkout opens with that vehicle\'s issues, and a request opens with its update history', async ({ page }) => {
  const board = newBoardServer();
  await open(page, board, '/daily/command-300?station=41');
  await expect(sheet(page)).toBeVisible();

  await sheet(page).getByTestId('command-checkout-row').filter({ hasText: 'E1' }).click();
  const checkout = page.getByTestId('station-checkout-sheet');
  await expect(checkout).toContainText('Captain Sam Lee');
  await expect(checkout.getByTestId('command-checkout-issues')).toContainText('Cracked mirror');
  await expect(checkout.getByTestId('command-checkout-issues')).toContainText('Reported in this checkout');
  await expect(checkout.getByTestId('station-checkout-detail')).toContainText('Cab');
  expect(board.requests).toContain('GET /api/public/command/stations/41/checkouts/901');
  await shot(page, 'checkout');
  await page.keyboard.press('Escape');
  await expect(checkout).toHaveCount(0);
  await expect(sheet(page)).toBeVisible();

  await sheet(page).getByTestId('command-request-row').filter({ hasText: 'Leaky faucet' }).click();
  const request = page.getByTestId('station-request-sheet');
  await expect(request).toContainText('Seen by Support Services');
  await expect(request).toContainText('A plumber is booked for Friday.');
  await expect(request.getByTestId('station-request-mark-resolved')).toHaveCount(0);
  await shot(page, 'request');
});

test('a roster that has not arrived is said plainly on the card and in the detail', async ({ page }) => {
  const board = newBoardServer();
  const stations = board.stations.map((station, index) => (index === 0
    ? { ...station, crew: { available: false, message: "Today's roster has not arrived yet.", working_count: 0, away_count: 0, members: [] } }
    : station));
  board.stations = stations;
  board.details[41] = { ...board.details[41], crew: { available: false, message: "Today's roster has not arrived yet.", working_count: 0, away_count: 0, members: [], units: [], date_label: null, window: null } };
  await open(page, board);
  await expect(cards(page).first()).toContainText('Roster not available yet');
  await cards(page).first().click();
  await expect(sheet(page).getByTestId('command-detail-crew')).toContainText('Roster not available yet');
});

test('a failed board shows a retryable error and keeps Messages usable', async ({ page }) => {
  const board = newBoardServer({ status: 503 });
  await open(page, board);
  await expect(page.getByRole('alert').filter({ hasText: 'The station board is unavailable' })).toBeVisible();
  board.status = 200;
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(cards(page)).toHaveCount(3);
  await expect(page.getByTestId('command-comms')).toBeVisible();
});

test('touch targets on the board and sheet are at least 44px', async ({ page }) => {
  await open(page);
  for (const box of await Promise.all([cards(page).first().boundingBox(), page.getByTestId('command-overview-refresh').boundingBox(), page.getByTestId('command-jump-messages').boundingBox()])) {
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
  }
  await cards(page).first().click();
  const row = await sheet(page).getByTestId('command-request-row').first().boundingBox();
  expect(row?.height ?? 0).toBeGreaterThanOrEqual(44);
});
