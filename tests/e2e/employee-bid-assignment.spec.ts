import { expect, test, type Page } from '@playwright/test';

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

test('member assignment preserves exact selection and seat across refresh and screen sizes', async ({ page }, testInfo) => {
  await login(page);
  const card = page.locator('[data-bid-assignment]');
  await expect(card.getByRole('heading', { name: '2026–2027 Bid Selection', exact: true })).toBeVisible();
  for (const text of ['Combat Float', 'Firefighter DE #2', 'A Shift', 'Station #1', 'Group 3', 'Bid award']) {
    await expect(card).toContainText(text);
  }
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
  await expect(card).not.toContainText('Bid award');
  await expect(card).not.toContainText('Bid Selection');
  await card.screenshot({ path: testInfo.outputPath('retained-assignment.png') });
});

test('guest cannot read employee assignment', async ({ page }) => {
  await page.goto('/employee/dashboard');
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('[data-bid-assignment]')).toHaveCount(0);
});
