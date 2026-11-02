import { expect, test, type Locator, type Page, type Route } from '@playwright/test';
import type { EmsMeta } from '../../resources/js/daily-checkout/src/components/captain5/ems/types';
import { itemReport, overview, sparseOverview, unitReport } from '../../resources/js/daily-checkout/src/components/captain5/ems/supplyData/testData';
import type { Overview } from '../../resources/js/daily-checkout/src/components/captain5/ems/supplyData/types';

/**
 * Captain 5 Supply Data tab (?tab=supply-data), every API response mocked.
 * Contract: docs/ems/supply-data-contract.md section 3. The Vitest suite covers behaviour; this one checks the real layout.
 */

const META: EmsMeta = {
  permissions: { view: true, act: true, configure: false },
  inventory: { read: true, edit: true },
  statuses: ['submitted', 'reviewed', 'prepared', 'partially_issued', 'issued', 'closed', 'cancelled'],
  target_kinds: ['rescue_unit', 'other_unit', 'detail_event'],
  units: [],
  close_reasons: [],
};

interface Server { overview: Overview; requests: string[]; meta: EmsMeta }

const newServer = (overrides: Partial<Server> = {}): Server => ({ overview: overview(), requests: [], meta: META, ...overrides });

const ok = (route: Route, data: unknown) => route.fulfill({ status: 200, json: { ok: true, data, meta: {} }, headers: { 'Cache-Control': 'private, no-store' } });

async function handleEms(route: Route, server: Server, url: URL): Promise<void> {
  const path = url.pathname.replace('/ems/api', '');
  server.requests.push(`${path}${url.search}`);
  if (path === '/meta') return ok(route, server.meta);
  if (path === '/requests') return route.fulfill({ status: 200, json: { ok: true, data: [], meta: { page: 1, per_page: 1, total: 0, last_page: 1 } } });
  if (path === '/supply-data') return ok(route, server.overview);
  if (/^\/supply-data\/items\/\d+$/.test(path)) return ok(route, itemReport());
  if (/^\/supply-data\/units\//.test(path)) return ok(route, unitReport());
  return route.fulfill({ status: 404, json: { ok: false, error: { code: 'not_found', message: `Unmocked route ${path}`, fields: {} } } });
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
      return route.fulfill({
        json: {
          access: { view: true, act: true, configure: false, acts_as: 'captain5', authority: 'on_duty_captain5', authority_label: 'Captain 5 (on duty)' },
          on_duty: { duty_date: '2026-11-02', shift: 'B', source: 'bid_holder', resolved: true, name: 'Alex Rivera', rank: 'Capt.', acting_until: null, acting_request_id: null }, duty_date: '2026-11-02',
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

async function open(page: Page, server: Server, path = '/daily/captain-5?tab=supply-data'): Promise<void> {
  await mockApi(page, server);
  await page.goto(path);
  await expect(page.getByTestId('supply-data-tab')).toBeVisible();
}

async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.CAPTAIN5_SHOTS_DIR;
  if (dir) await page.screenshot({ path: `${dir}/supply-data-${name}.png`, fullPage: true });
}

async function noHorizontalOverflow(page: Page, label: string): Promise<void> {
  const widths = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(widths.scroll, label).toBeLessThanOrEqual(widths.client);
}

async function targetsAreBigEnough(scope: Locator): Promise<void> {
  const boxes = await scope.locator('button:visible, a:visible, input:visible').evaluateAll((elements) => elements.map((element) => {
    const rect = element.getBoundingClientRect();
    return { label: (element.getAttribute('aria-label') ?? element.textContent ?? element.tagName).trim().slice(0, 40), height: rect.height };
  }));
  for (const box of boxes) expect(box.height, box.label).toBeGreaterThanOrEqual(43.5);
}

test.describe('Supply Data tab', () => {
  test('sits right after the Master Supply Room Inventory tab and shows what matters now, the trend and Explore', async ({ page }) => {
    await open(page, newServer());
    const tabs = page.getByRole('tablist', { name: 'Captain 5 workspace' }).getByRole('tab');
    await expect(tabs.filter({ hasText: /^Master Supply Room Inventory$/ })).toHaveCount(1);
    const names = (await tabs.allTextContents()).map((text) => text.trim());
    expect(names[names.indexOf('Master Supply Room Inventory') + 1]).toBe('Supply Data');
    await expect(page.getByRole('tab', { name: 'Supply Data' })).toHaveAttribute('aria-selected', 'true');

    await expect(page.getByTestId('attention-below_minimum')).toContainText('3');
    await expect(page.getByTestId('attention-estimated')).toContainText('44');
    await expect(page.getByRole('img', { name: 'Distribution events by week' })).toBeVisible();
    await expect(page.getByTestId('supply-data-label')).toContainText('Distributed from the supply room');
    await expect(page.getByTestId('by-supply-list')).toBeVisible();
    await shot(page, 'overview');
  });

  for (const width of [320, 360, 390, 768, 1280]) {
    test(`does not scroll sideways, and its controls are 44px, at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await open(page, newServer());
      await expect(page.getByTestId('supply-data-attention')).toBeVisible();
      await noHorizontalOverflow(page, 'overview');
      await targetsAreBigEnough(page.getByTestId('supply-data-tab'));

      await page.getByRole('button', { name: 'Show numbers' }).click();
      await page.getByRole('button', { name: 'Custom' }).click();
      await page.getByRole('button', { name: /^Fulfillment/ }).click();
      await noHorizontalOverflow(page, 'overview with numbers, custom range and fulfillment open');
      await shot(page, `overview-open-${width}`);

      await page.getByRole('button', { name: 'Stock', exact: true }).click();
      await expect(page.getByTestId('stock-list')).toBeVisible();
      await noHorizontalOverflow(page, 'stock');
      await shot(page, `stock-${width}`);

      await page.getByTestId('stock-list').getByRole('button', { name: /Gloves, nitrile/ }).click();
      await expect(page.getByTestId('item-detail')).toBeVisible();
      await noHorizontalOverflow(page, 'supply');
      await targetsAreBigEnough(page.getByTestId('supply-data-detail'));
      await shot(page, `item-${width}`);
    });
  }

  test('a drill-down keeps the period in the address and Back returns to the overview with it', async ({ page }) => {
    const server = newServer();
    await open(page, server, '/daily/captain-5?tab=supply-data&period=90d&explore=unit');
    await expect(page.getByRole('button', { name: 'Last 90 days' })).toHaveAttribute('aria-pressed', 'true');
    expect(server.requests).toContain('/supply-data?period=90d');
    await page.getByRole('button', { name: /RESCUE R2/ }).click();
    await expect(page.getByRole('heading', { name: 'RESCUE R2', level: 2 })).toBeVisible();
    await expect(page).toHaveURL(/period=90d/);
    await expect(page).toHaveURL(/unit=unit%3A45/);
    expect(server.requests).toContain('/supply-data/units/unit%3A45?period=90d');
    await page.getByRole('button', { name: 'Back to Supply Data' }).click();
    await expect(page.getByRole('heading', { name: 'Supply Data', level: 2 })).toBeVisible();
    await expect(page).toHaveURL(/period=90d/);
    await expect(page).not.toHaveURL(/unit=/);

    await page.getByRole('button', { name: /RESCUE R2/ }).click();
    await page.getByRole('button', { name: /MIR-000004/ }).click();
    // The request opens over the Submitted Inventory Forms tab, as a sheet.
    await expect(page.getByRole('dialog', { name: 'Submitted inventory form' })).toBeVisible();
    await expect(page).toHaveURL(/tab=rescue&request=6f1f2c1e-1111-4222-8333-444455556666/);
    await page.goBack();
    await expect(page.getByRole('heading', { name: 'RESCUE R2', level: 2 })).toBeVisible();
  });

  test('looks intentional with almost no history: an honest empty trend, no chart, no invented numbers', async ({ page }) => {
    await open(page, newServer({ overview: sparseOverview() }));
    await expect(page.getByText('Nothing has been distributed in this period yet')).toBeVisible();
    await expect(page.getByTestId('headline-change')).toContainText('No earlier activity to compare');
    await expect(page.getByTestId('attention-estimated')).toContainText('44');
    await expect(page.getByRole('img', { name: /Distribution events/ })).toHaveCount(0);
    expect(await page.locator('body').innerText()).not.toMatch(/NaN|undefined|\bconsumed\b/);
    await noHorizontalOverflow(page, 'sparse overview');
    await shot(page, 'sparse');
  });

  test('is absent for an administrator with only the master inventory', async ({ page }) => {
    await mockApi(page, newServer({ meta: { ...META, permissions: { view: false, act: false, configure: false } } }));
    await page.goto('/daily/captain-5?tab=supply');
    await expect(page.getByRole('tab', { name: 'Master Supply Room Inventory' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Supply Data' })).toHaveCount(0);
  });
});
