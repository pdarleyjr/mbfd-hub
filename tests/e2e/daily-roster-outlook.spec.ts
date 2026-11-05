import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { expectNoHorizontalOverflow } from './support/no-horizontal-overflow';

// One roster page across TeleStaff's 7-day outlook. The fixture publishes outlook days one and three
// after the day on screen; day two is missing on purpose.
const PAGE = '/daily-roster';

const strip = (page: Page) => page.getByRole('navigation', { name: 'Roster days', exact: true });
const bottomStrip = (page: Page) => page.getByRole('navigation', { name: 'Roster days, end of roster', exact: true });
const days = (page: Page) => strip(page).locator('[data-roster-day]');

function viewportWidth(testInfo: TestInfo): number {
  return testInfo.project.use.viewport!.width;
}

async function openRoster(page: Page, path = PAGE): Promise<void> {
  await page.goto(path);
  await expect(page.getByRole('heading', { level: 1, name: 'Daily Roster' })).toBeVisible();
}

function hubCalendarDate(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York' }).format(new Date());
}

// The strip opens on the duty day (08:00 to 08:00), so from midnight to 8 AM the day on screen is yesterday's
// date and "Today" sits on the day after it. "Today" is the calendar date, whatever the hour.
async function expectTodayMarked(page: Page): Promise<void> {
  const before = hubCalendarDate();
  await expect(days(page).first()).toHaveAttribute('aria-current', 'date');
  const marked = await days(page).evaluateAll(items => items
    .filter(item => (item.textContent ?? '').trim().startsWith('Today'))
    .map(item => item.getAttribute('data-roster-day')));
  expect(marked).toHaveLength(1);
  // Either side of midnight, in case the page and this check straddle it.
  expect([before, hubCalendarDate()]).toContain(marked[0]);
}

async function expectAccessible(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).include('main').analyze();
  expect(results.violations.map(({ id, impact, nodes }) => ({ id, impact, nodes: nodes.map(node => node.target) }))).toEqual([]);
}

test('the day strip fits, is accessible and marks today', async ({ page }) => {
  await openRoster(page);

  await expect(days(page)).toHaveCount(7);
  await expectTodayMarked(page);
  await expect(strip(page).locator('a[data-roster-day]')).toHaveCount(3);
  await expect(strip(page).locator('.roster-days__day--missing').first()).toContainText('not available yet');
  for (const box of await strip(page).locator('a[data-roster-day]').evaluateAll(links => links.map(link => link.getBoundingClientRect()))) {
    expect(box.height).toBeGreaterThanOrEqual(44);
  }
  await expect(page.locator('[data-roster-outlook]')).toHaveCount(0);
  // The owner wants the strip first, above the last update and the roster.
  const stripBottom = await strip(page).evaluate(element => element.getBoundingClientRect().bottom);
  const updatedTop = await page.locator('.roster-last-updated').evaluate(element => element.getBoundingClientRect().top);
  expect(stripBottom).toBeLessThanOrEqual(updatedTop);
  // And again under the roster, so a member at the bottom can switch days too.
  await expect(bottomStrip(page).locator('[data-roster-day]')).toHaveCount(7);
  const lastSectionBottom = await page.locator('.roster-sections').evaluate(element => element.getBoundingClientRect().bottom);
  const bottomStripTop = await bottomStrip(page).evaluate(element => element.getBoundingClientRect().top);
  expect(bottomStripTop).toBeGreaterThanOrEqual(lastSectionBottom);
  await expectNoHorizontalOverflow(page);
  await expectAccessible(page);
});

test('an outlook day opens as one page, is labelled and the back button returns', async ({ page }) => {
  await openRoster(page);
  const outlookDay = strip(page).locator('a[data-roster-day]').nth(1);
  const date = await outlookDay.getAttribute('data-roster-day');

  await outlookDay.click();

  await expect(page).toHaveURL(new RegExp(`\\?date=${date}$`));
  await expect(page.locator('[data-roster-freshness]')).toHaveText('Outlook');
  await expect(page.locator('[data-roster-outlook]')).toContainText('It may change before the shift.');
  await expect(page.getByText('OUTLOOK, OLA')).toBeVisible();
  await expect(strip(page).locator('[aria-current="date"]')).toHaveAttribute('data-roster-day', date!);
  await expect(page.locator('[data-roster-body]')).toHaveAttribute('data-live', '0');
  await expectNoHorizontalOverflow(page);
  await expectAccessible(page);

  await bottomStrip(page).locator('[aria-current="date"]').waitFor();
  await expect(bottomStrip(page).locator('[aria-current="date"]')).toHaveAttribute('data-roster-day', date!);

  await page.goBack();
  await expect(page).toHaveURL(/\/daily-roster$/);
  await expectTodayMarked(page);
});

test('the strip works from the keyboard with a visible focus ring', async ({ page }, testInfo) => {
  test.skip(![390, 1280].includes(viewportWidth(testInfo)), 'Phone and desktop cover the keyboard path.');
  await openRoster(page);
  const outlookDay = strip(page).locator('a[data-roster-day]').nth(1);
  const date = await outlookDay.getAttribute('data-roster-day');

  // Reach it the way a keyboard does: Tab from the link before it.
  await strip(page).locator('a[data-roster-day]').first().focus();
  await page.keyboard.press('Tab');
  await expect(outlookDay).toBeFocused();
  const outline = await outlookDay.evaluate(element => getComputedStyle(element).outlineStyle);
  expect(outline).not.toBe('none');

  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`\\?date=${date}$`));
  await expect(page.locator('[data-roster-freshness]')).toHaveText('Outlook');
});
