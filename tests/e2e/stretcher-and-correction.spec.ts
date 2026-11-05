import { expect, test, type Page } from '@playwright/test';

/**
 * Mark fixed on an issue card and the stretcher report, against a mocked API (no Laravel, no database).
 * Runs on a 390px phone and a 1440px desktop. Contracts: docs/unified-activity-and-ownership.md sections 3 and 4.
 */

const rescueOne = { id: 7, name: 'Rescue 1', slug: 'rescue-1', type: 'Rescue', vehicle_number: null };
const engineOne = { id: 3, name: 'Engine 1', slug: 'engine-1', type: 'Engine', vehicle_number: null };

const memberContext = {
  version: 1,
  identity: { user_id: 101, has_personnel_profile: true },
  personnel: { employee_profile_id: 41, employee_number: 'E41', name: 'Firefighter Browser Test', rank: 'Firefighter' },
  offline: { security_version: 1 },
  session: { authenticated: true },
};

function issueRow(pending: boolean) {
  return {
    id: 42,
    compartment: 'Driver side compartment',
    item: 'Flashlight',
    issue_type: 'missing',
    severity: 'routine',
    description: 'Not in its holder at the start of shift.',
    reporter_name: 'Pat Lee',
    first_reported_at: '2026-11-01T12:00:00Z',
    action_status: 'open',
    action_label: 'Open',
    history_count: 0,
    owner: 'logistics',
    member_correction: { eligible: true, closes_immediately: false, pending: pending ? { at: '2026-11-04T14:00:00Z' } : null },
  };
}

interface Recorded {
  corrections: Array<Record<string, unknown>>;
  stretcherBodies: string[];
}

async function mockApi(page: Page): Promise<Recorded> {
  const recorded: Recorded = { corrections: [], stretcherBodies: [] };
  let pending = false;

  await page.route('**/images/mbfd_logo_new.png', route => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/me/context') return route.fulfill({ json: memberContext });
    if (path === '/api/public/apparatuses') return route.fulfill({ json: [rescueOne, engineOne] });
    if (path === '/api/public/apparatuses/7/findings') return route.fulfill({ json: { as_of: '2026-11-04T15:00:00Z', open: [issueRow(pending)], notes: [], closed_count: 0 } });
    if (path === '/api/public/apparatuses/7/findings/closed') return route.fulfill({ json: { data: [], next_cursor: null } });
    if (path === '/api/public/apparatus-issues/42/history') return route.fulfill({ json: { issue: issueRow(pending), timeline: [] } });
    if (path === '/api/public/apparatus-issues/42/member-correction' && request.method() === 'POST') {
      recorded.corrections.push(request.postDataJSON() as Record<string, unknown>);
      pending = true;
      return route.fulfill({
        json: { data: { result: 'awaiting_verification', status_label: 'Correction reported, awaiting verification', message: 'Thanks. Support Services will verify this before it is closed.' } },
      });
    }
    if (path === '/api/public/apparatuses/7/stretcher-issues' && request.method() === 'POST') {
      recorded.stretcherBodies.push(request.postDataBuffer()?.toString('latin1') ?? '');
      return route.fulfill({ status: 201, json: { data: { issue_id: 90, attached: false, message: 'Sent to Captain 5. The EMS Captain was told because the stretcher cannot be used.' } } });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  return recorded;
}

const hasNoSidewaysScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

test('Mark fixed: one tap opens the sheet, the report is sent once and the card shows it is waiting', async ({ page }) => {
  const recorded = await mockApi(page);
  await page.goto('/daily/vehicle-inspections/rescue-1/issues');

  await expect(page.getByText('Handled by Support Services')).toBeVisible();
  const markFixed = page.getByRole('button', { name: 'Mark fixed' });
  await expect(markFixed).toBeVisible();
  expect((await markFixed.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  await markFixed.click();
  const sheet = page.getByTestId('member-correction-sheet');
  await expect(sheet).toBeVisible();
  await expect(sheet.getByTestId('member-correction-verify')).toHaveText('Support Services will verify this before it is closed.');
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  await sheet.getByRole('radio', { name: 'Replaced' }).check();
  await sheet.getByLabel('Note (optional)').fill('New one from the supply room');
  await sheet.getByRole('button', { name: 'Report fixed' }).click();

  await expect(sheet.getByTestId('member-correction-done')).toHaveText('Thanks. Support Services will verify this before it is closed.');
  expect(recorded.corrections).toHaveLength(1);
  expect(recorded.corrections[0]).toMatchObject({ outcome: 'replaced', note: 'New one from the supply room' });
  expect(String(recorded.corrections[0].client_action_id)).toMatch(/^[0-9a-f-]{36}$/);

  await sheet.getByRole('button', { name: 'Done' }).click();
  await expect(sheet).toBeHidden();
  // The list is read again: the button gives way to the amber waiting line, and the server's words stay on the page.
  await expect(page.getByText('Correction reported, awaiting verification')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mark fixed' })).toHaveCount(0);
  await expect(page.getByTestId('issues-notice')).toContainText('Support Services will verify this');
  expect(await hasNoSidewaysScroll(page)).toBe(true);
});

test('Stretcher report from a unit: chips, photo, switch, one multipart send and a success screen', async ({ page }) => {
  const recorded = await mockApi(page);
  await page.goto('/daily/vehicle-inspections/rescue-1/issues');

  await page.getByRole('link', { name: 'Report stretcher issue' }).click();
  await expect(page).toHaveURL(/\/daily\/vehicle-inspections\/rescue-1\/stretcher-issue$/);
  await expect(page.getByRole('heading', { name: 'Report stretcher issue' })).toBeVisible();
  await expect(page.getByTestId('stretcher-unit')).toHaveText('Rescue 1');
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  // Tapping send with nothing filled in asks for the two required answers and sends nothing.
  await page.getByRole('button', { name: 'Send to Captain 5' }).click();
  await expect(page.getByText('Choose the kind of problem.')).toBeVisible();
  expect(recorded.stretcherBodies).toHaveLength(0);

  const battery = page.getByRole('radio', { name: 'Battery' });
  await battery.check();
  expect((await page.locator('.stretcher-chip span', { hasText: 'Battery' }).boundingBox())?.height).toBeGreaterThanOrEqual(44);
  await page.getByLabel(/What is wrong\?/).fill('Battery will not hold a charge');
  await page.getByRole('switch', { name: "The stretcher can't be used right now" }).check();
  await page.getByLabel('Photo (optional)').setInputFiles('public/images/mbfd_logo_new.png');
  await expect(page.getByRole('button', { name: 'Remove photo' })).toBeVisible();
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  const send = page.getByRole('button', { name: 'Send to Captain 5' });
  expect((await send.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  await send.click();

  await expect(page.getByTestId('stretcher-sent')).toContainText('Sent to Captain 5. The EMS Captain was told');
  await expect(page.getByRole('link', { name: 'Back to unit issues' })).toHaveAttribute('href', '/daily/vehicle-inspections/rescue-1/issues');
  await expect(page.getByRole('button', { name: 'Report another' })).toBeVisible();
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  expect(recorded.stretcherBodies).toHaveLength(1);
  const body = recorded.stretcherBodies[0];
  expect(body).toMatch(/name="client_submission_id"\r\n\r\n[0-9a-f-]{36}/);
  expect(body).toMatch(/name="problem"\r\n\r\nbattery/);
  expect(body).toMatch(/name="description"\r\n\r\nBattery will not hold a charge/);
  expect(body).toMatch(/name="unusable"\r\n\r\n1/);
  expect(body).toMatch(/name="photo"; filename="mbfd_logo_new.png"/);
});

test('Stretcher report from the Forms Hub asks for the unit first', async ({ page }) => {
  const recorded = await mockApi(page);
  await page.goto('/daily/forms-hub');

  await page.getByRole('link', { name: /Report stretcher issue/ }).click();
  await expect(page).toHaveURL(/\/daily\/forms-hub\/stretcher-issue$/);
  const search = page.getByRole('searchbox');
  await expect(search).toBeVisible();
  await search.fill('rescue');
  await expect(page.getByRole('button', { name: 'Engine 1' })).toHaveCount(0);
  expect(await hasNoSidewaysScroll(page)).toBe(true);

  await page.getByRole('button', { name: 'Rescue 1' }).click();
  await expect(page.getByTestId('stretcher-unit')).toHaveText('Rescue 1');

  await page.getByRole('radio', { name: 'Other' }).check();
  await page.getByLabel(/What is wrong\?/).fill('Foot-end latch sticks');
  await page.getByRole('button', { name: 'Send to Captain 5' }).click();

  await expect(page.getByTestId('stretcher-sent')).toBeVisible();
  expect(recorded.stretcherBodies).toHaveLength(1);
  expect(recorded.stretcherBodies[0]).toMatch(/name="unusable"\r\n\r\n0/);
});
