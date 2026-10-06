import { expect, test, type Locator, type Page } from '@playwright/test';

async function login(page: Page, retained = false): Promise<void> {
  const password = process.env[retained ? 'PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD' : 'PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD'];
  if (!password) throw new Error('The disposable Bid browser fixture password is required.');
  await page.goto('/employee/dashboard');
  await page.getByLabel('Employee ID').fill(retained ? '99001' : '99002');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/dashboard$/);
  if (page.viewportSize()!.width < 1024) {
    await expect(page.locator('.fi-sidebar')).not.toHaveClass(/fi-sidebar-open/);
  }
  await page.evaluate(() => document.fonts.ready.then(() => undefined));
}

type Scenario = {
  label: string;
  selection: string;
  rank: string;
  seat: string;
  shift: string;
  area: string;
  division: string;
  day: string;
  retained?: boolean;
};

// Independent expected rendered values for the synthetic fixture records.
const scenarios: Scenario[] = [
  { label: 'Captain 5', selection: 'Captain 5', rank: 'Captain', seat: 'Captain', shift: 'B Shift', area: 'Station #2', division: 'Operations', day: 'Group 4' },
  { label: 'Rescue Float Lieutenant', selection: 'Rescue Float', rank: 'Lieutenant', seat: 'Lieutenant Rescue Float', shift: 'B Shift', area: 'Float', division: 'Rescue', day: 'Group 2' },
  { label: 'Rescue Float Firefighter', selection: 'Rescue Float', rank: 'Firefighter', seat: 'Firefighter', shift: 'C Shift', area: 'Float', division: 'Rescue', day: 'Group 1' },
  { label: 'Combat Float Firefighter', selection: 'Combat Float', rank: 'Firefighter', seat: 'Firefighter', shift: 'A Shift', area: 'Float', division: 'Combat', day: 'Group 3' },
  { label: 'Engine 2', selection: 'Engine 2', rank: 'Firefighter', seat: 'Firefighter DE #1', shift: 'B Shift', area: 'Station #2', division: 'Combat', day: 'Group 4' },
  { label: 'Engine 4', selection: 'Engine 4', rank: 'Firefighter', seat: 'Firefighter #2', shift: 'C Shift', area: 'Station #4', division: 'Combat', day: 'Group 2' },
  { label: 'Rescue unit', selection: 'Rescue 1', rank: 'Firefighter', seat: 'Firefighter #2', shift: 'A Shift', area: 'Station #1', division: 'Rescue', day: 'Group 1' },
  { label: 'Fire Boat Operator', selection: 'Fire Boat 6', rank: 'Firefighter', seat: 'Fire Boat Operator', shift: 'B Shift', area: 'Station #6', division: 'Marine', day: 'Group 3' },
  { label: 'Fire Boat Engineer', selection: 'Fire Boat 6', rank: 'Firefighter', seat: 'Fire Boat Engineer', shift: 'C Shift', area: 'Station #6', division: 'Marine', day: 'Group 2' },
  { label: 'Marine Deckhand', selection: 'Fire Boat 6', rank: 'Firefighter', seat: 'Deckhand', shift: 'A Shift', area: 'Station #6', division: 'Marine', day: 'Group 4' },
  { label: 'Marine Float FBO', selection: 'Marine Float', rank: 'Firefighter', seat: 'Marine FBO', shift: 'C Shift', area: 'Float', division: 'Marine', day: 'Group 1' },
  { label: 'competitive Days Monday', selection: 'Special Events', rank: 'Captain', seat: 'Captain', shift: 'D Shift', area: 'Special Events', division: 'Support Services', day: 'Monday' },
  { label: 'competitive Days Friday', selection: 'Public Education', rank: 'Firefighter', seat: 'Firefighter', shift: 'D Shift', area: 'Public Education', division: 'Prevention', day: 'Friday' },
  { label: 'retained Union President', selection: 'Union President', rank: 'Firefighter', seat: 'Union President', shift: 'A Shift', area: 'Union Office', division: 'Administration', day: 'Group 4', retained: true },
  { label: 'normal Combat 1', selection: 'Combat 1', rank: 'Firefighter', seat: 'Firefighter DE #1', shift: 'A Shift', area: 'Station #1', division: 'Combat', day: 'Group 1' },
  { label: 'normal Combat 3', selection: 'Combat 3', rank: 'Firefighter', seat: 'Firefighter #2', shift: 'C Shift', area: 'Station #3', division: 'Combat', day: 'Group 2' },
  { label: 'Air Tech current revision', selection: 'Combat Float', rank: 'Firefighter', seat: 'Air Tech', shift: 'A Shift', area: 'Float', division: 'Support Services', day: 'Group 2' },
  { label: 'Fire Investigator', selection: 'Combat 1', rank: 'Firefighter', seat: 'Fire Investigator', shift: 'B Shift', area: 'Station #1', division: 'Combat', day: 'Group 4' },
];

function fixtureId(projectName: string, index: number): string {
  const code: Record<string, string> = { 'phone-touch': 'P', 'tablet-touch': 'T', desktop: 'D', 'hub-shell-webkit-iphone': 'W' };
  if (!code[projectName]) throw new Error(`No isolated Bid fixture identities for ${projectName}.`);
  return `BID-E2E-${code[projectName]}${String(index + 1).padStart(2, '0')}`;
}

function field(card: Locator, label: string): Locator {
  return card.locator('dt').getByText(label, { exact: true }).locator('..').locator('dd');
}

for (const [index, scenario] of scenarios.entries()) {
  test(`canonical member sees exact ${scenario.label} assignment`, async ({ page }, testInfo) => {
    const employeeId = fixtureId(testInfo.project.name, index);
    const password = process.env.PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD;
    if (!password) throw new Error('The disposable canonical member fixture password is required.');
    await page.goto('/employee/dashboard');
    await page.getByLabel('Employee ID').fill(employeeId);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/employee\/dashboard$/);
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready.then(() => undefined));
    const card = page.locator('[data-bid-assignment]');
    const heading = scenario.retained ? '2026–2027 Assignment' : '2026–2027 Bid Selection';
    await expect(card.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    await expect(field(card, scenario.retained ? 'Assignment' : 'Bid Selection')).toHaveText(scenario.selection);
    await expect(field(card, scenario.retained ? 'Rank' : 'Rank at bid')).toHaveText(scenario.rank);
    await expect(field(card, 'Shift')).toHaveText(scenario.shift);
    await expect(field(card, 'Station / Assignment Area')).toHaveText(scenario.area);
    await expect(field(card, 'Division')).toHaveText(scenario.division);
    await expect(field(card, 'A-Day')).toHaveText(scenario.day);
    if (scenario.seat !== scenario.rank && scenario.seat !== scenario.selection) {
      await expect(field(card, 'Seat / Position')).toHaveText(scenario.seat);
    } else {
      await expect(card.locator('dt').getByText('Seat / Position', { exact: true })).toHaveCount(0);
    }
    await expect(card).toContainText(scenario.retained ? 'Retained assignment' : 'Bid award');
    await expect(card).not.toContainText(scenario.retained ? 'Bid award' : 'Retained assignment');
    if (scenario.retained) await expect(card).not.toContainText('Bid Selection');
    if (/^Combat [13]$/.test(scenario.selection)) await expect(card).not.toContainText(/\b(?:Engine|Ladder)\b/);
    await expect(card).not.toContainText('superseded fixture revision');
    await expect(card).not.toContainText(employeeId);
    await expect(card).not.toContainText('TEST-BROWSER-REAL');
    await expect(card.locator('input, select, textarea, button, a')).toHaveCount(0);
    await expect(page.getByText('Open Bid Console', { exact: true })).toHaveCount(0);
    await expect(page.getByText('My Bid Certifications', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    await field(card, 'A-Day').evaluate(element => element.scrollIntoView({ block: 'center' }));
    await expect(field(card, 'A-Day')).toBeInViewport();
    await card.evaluate(element => element.scrollIntoView({ block: 'center' }));
    await page.screenshot({ path: testInfo.outputPath('exact-assignment.png'), animations: 'disabled' });
    await page.reload();
    await expect(field(card, scenario.retained ? 'Assignment' : 'Bid Selection')).toHaveText(scenario.selection);
    await expect(field(card, 'A-Day')).toHaveText(scenario.day);
    // Naming another canonical fixture in the URL never changes the actor.
    await page.goto(`/employee/dashboard?employee_id=${fixtureId(testInfo.project.name, (index + 1) % scenarios.length)}`);
    await expect(field(card, scenario.retained ? 'Assignment' : 'Bid Selection')).toHaveText(scenario.selection);
    await expect(field(card, scenario.retained ? 'Rank' : 'Rank at bid')).toHaveText(scenario.rank);
  });
}

test('member assignment preserves exact selection and seat across refresh and screen sizes', async ({ page }, testInfo) => {
  await login(page);
  const card = page.locator('[data-bid-assignment]');
  await expect(card.getByRole('heading', { name: '2026–2027 Bid Selection', exact: true })).toBeVisible();
  for (const text of ['Combat Float', 'Firefighter DE #2', 'A Shift', 'Station #1', 'Group 3', 'Bid award']) {
    await expect(card).toContainText(text);
  }
  await expect(field(card, 'Bid Selection')).toHaveText('Combat Float');
  await expect(field(card, 'Rank at bid')).toHaveText('Firefighter');
  await expect(field(card, 'Seat / Position')).toHaveText('Firefighter DE #2');
  await expect(field(card, 'Shift')).toHaveText('A Shift');
  await expect(field(card, 'Station / Assignment Area')).toHaveText('Station #1');
  await expect(field(card, 'A-Day')).toHaveText('Group 3');
  await expect(card).not.toContainText('TEST-BROWSER-REAL');
  await expect(card).not.toContainText('99002');
  await expect(page.getByText('My Bid Certifications', { exact: true })).toHaveCount(0);
  await expect(page.getByText('Open Bid Console', { exact: true })).toHaveCount(0);
  const widths = testInfo.project.name === 'phone-touch' ? [320, 390] : [page.viewportSize()!.width];
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1000 });
    await expect(card).toBeVisible();
    await card.scrollIntoViewIfNeeded();
    await expect(card).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
    await card.screenshot({ path: testInfo.outputPath(`assignment-${width}.png`) });
  }
  await page.reload();
  await expect(card).toContainText('Combat Float');
  const day = card.getByText('Group 3', { exact: true });
  await day.evaluate(element => element.scrollIntoView({ block: 'center' }));
  await expect(day).toBeInViewport();
  await page.screenshot({ path: testInfo.outputPath('assignment-a-day.png') });
  await page.goto('/employee/dashboard?employee_id=99001');
  await expect(card).toContainText('Combat Float');
  await expect(card).not.toContainText('Prevention');
});

test('retained assignment uses retained wording and its weekday', async ({ page }, testInfo) => {
  await login(page, true);
  const card = page.locator('[data-bid-assignment]');
  await expect(card.getByRole('heading', { name: '2026–2027 Assignment', exact: true })).toBeVisible();
  for (const text of ['Prevention', 'Division Chief', 'D Shift', 'Monday', 'Retained assignment']) {
    await expect(card).toContainText(text);
  }
  await expect(field(card, 'Assignment')).toHaveText('Prevention');
  await expect(field(card, 'Rank')).toHaveText('Division Chief');
  await expect(field(card, 'Shift')).toHaveText('D Shift');
  await expect(field(card, 'Station / Assignment Area')).toHaveText('Administration');
  await expect(field(card, 'A-Day')).toHaveText('Monday');
  await expect(card).not.toContainText('Bid award');
  await expect(card).not.toContainText('Bid Selection');
  await card.screenshot({ path: testInfo.outputPath('retained-assignment.png') });
});

test('guest cannot read employee assignment', async ({ page }) => {
  await page.goto('/employee/dashboard');
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('[data-bid-assignment]')).toHaveCount(0);
});
