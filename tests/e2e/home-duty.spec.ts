import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';

// Home greeting block (App\Services\DailyRoster\HomeDutyStatus). The harness seeds a synthetic roster for the
// current duty day, so the shift is the rotation's and the 300 is the seeded roster's; every name is made up.

const block = (page: Page) => page.locator('[data-home-duty]');

async function openHome(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.locator('#home-greeting')).toBeVisible();
}

test('the greeting block shows the greeting, the date, the verified shift and the 300 without overflow', async ({ page }) => {
  await openHome(page);

  await expect(page.locator('#home-greeting')).toHaveText(/^Good (morning|afternoon|evening)/);
  await expect(page.locator('[data-home-date]')).toContainText(/Today is \w+, \w+ \d{1,2}, \d{4}/);
  await expect(page.locator('[data-home-shift]')).toHaveText(/^[ABC] Shift/);
  await expect(page.locator('[data-home-shift]')).not.toContainText('scheduled');
  await expect(page.locator('[data-home-command]')).toHaveText('300 is SAMPLE, BRETT');
  await expect(page.locator('[data-home-as-of]')).toHaveText(/^As of \d{1,2}:\d{2} (AM|PM)/);
  await expect(page.locator('[data-home-roster-link]')).toHaveAttribute('href', '/daily-roster');

  // The rest of the page is untouched.
  await expect(page.locator('[data-home-section="department-updates"]')).toBeVisible();
  await expect(page.locator('[data-home-section="quick-access"]')).toBeVisible();
  await expectNoHorizontalOverflow(page);
});

test('very long names and lines wrap inside the column', async ({ page }) => {
  await openHome(page);
  await block(page).evaluate((element) => {
    const longName = 'VANDERHOOGENBAND-MONTGOMERY-WASHINGTON'.repeat(3);
    element.querySelector('[data-home-greeting]')!.textContent = `Good afternoon, ${longName}`;
    element.querySelector('[data-home-command]')!.textContent = `Today's roster is delayed · Scheduled 300: ${longName}`;
    element.querySelector('[data-home-shift]')!.textContent = 'A Shift (scheduled, on duty since Nov 1, 8:00 AM)';
  });

  await expectNoHorizontalOverflow(page);
});

test('a page left open takes a new shift and 300 without a reload or losing focus', async ({ page }) => {
  await page.clock.install();
  const background: string[] = [];
  await page.route('**/home/duty', async (route) => {
    background.push(route.request().headers()['x-hub-background-refresh'] ?? '');
    await route.fulfill({
      status: 200,
      headers: { 'cache-control': 'no-store, private' },
      json: {
        greeting: 'Good evening, Viewer',
        date: { label: 'Monday, November 2, 2026', iso: '2026-11-02' },
        shift: { text: 'B Shift (scheduled)', state: 'scheduled' },
        command: { text: 'Scheduled 300: BID SEAT, CHIEF · not yet verified', state: 'scheduled' },
        as_of: null,
        roster_url: '/daily-roster',
        next_change_at: '2099-01-01T00:00:00+00:00',
        version: 'changed-while-open',
      },
    });
  });
  await openHome(page);
  const vehicleCheckout = page.locator('.home-today a[data-important-target]').first();
  await vehicleCheckout.focus();
  await page.evaluate(() => { (window as unknown as { __kept: string }).__kept = 'same document'; });

  await page.clock.fastForward(61_000);

  await expect(page.locator('#home-greeting')).toHaveText('Good evening, Viewer');
  await expect(page.locator('[data-home-shift]')).toHaveText('B Shift (scheduled)');
  await expect(page.locator('[data-home-command]')).toHaveText('Scheduled 300: BID SEAT, CHIEF · not yet verified');
  await expect(page.locator('[data-home-as-of]')).toBeHidden();
  expect(await page.evaluate(() => (window as unknown as { __kept: string }).__kept)).toBe('same document');
  expect(background).toContain('1');
  if (await vehicleCheckout.count()) await expect(vehicleCheckout).toBeFocused();
  await expectNoHorizontalOverflow(page);
});

test('an ended session takes the duty lines off the screen', async ({ page }) => {
  await page.clock.install();
  await page.route('**/home/duty', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
  await openHome(page);
  await expect(page.locator('[data-home-command]')).toBeVisible();

  await page.clock.fastForward(61_000);

  await expect(page.locator('[data-home-command]')).toBeHidden();
  await expect(page.locator('[data-home-shift-wrap]')).toBeHidden();
  await expect(page.locator('[data-home-as-of]')).toBeHidden();
  await expect(page.locator('#home-greeting')).toBeVisible();
});
