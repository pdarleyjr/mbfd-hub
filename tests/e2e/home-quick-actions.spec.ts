import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/*
 * Member Home: the Rescue Forms card (a native disclosure of three direct links) and the Command Board button.
 * Member 99002 is a Firefighter (no Command Board); officer 99001 is a Captain (Command 300 Access by rank).
 *
 *   HOME_NAV_LABEL, HOME_NAV_SHOTS_DIR   screenshot name and folder (see employee-menu-button.spec.ts)
 */

const label = process.env.HOME_NAV_LABEL ?? 'after';
const authDir = process.env.EMPLOYEE_LAYOUT_AUTH_DIR ?? join(tmpdir(), 'mbfd-employee-layout-auth');
const shotsDir = process.env.HOME_NAV_SHOTS_DIR ?? join('test-results', 'home-nav-shots');

async function openHome(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Vehicle Checkout', exact: true })).toBeVisible();
}

test.describe('member', () => {
  test.use({ storageState: join(authDir, 'member.json') });

  test('Rescue Forms expands with the keyboard to the four inventory forms and two checks, and there is no Command Board', async ({ page }, testInfo) => {
    await openHome(page);
    const card = page.locator('details[data-rescue-forms]');
    await expect(card).toBeVisible();
    await expect(card).not.toHaveAttribute('open', '');
    const summary = card.locator('summary');
    await expect(summary).toContainText('Rescue Forms');
    await expect(card.locator('a[data-rescue-form-link]')).toHaveCount(6);
    await expect(card.getByRole('link', { name: 'ALS Transport Inventory Form' })).toBeHidden();

    await summary.focus();
    await page.keyboard.press('Enter');
    await expect(card).toHaveAttribute('open', '');
    const links = card.getByRole('link');
    await expect(links).toHaveText([
      /ALS Transport Inventory Form/, /ALS Non Transport Inventory Form/, /Fire Boat 6 Medical Inventory Form/,
      /BLS Detail Medical Inventory Form/, /Narcotics Check/, /Vehicle Inspection/,
    ]);
    await expect(links.nth(0)).toHaveAttribute('href', /\/employee\/forms\?form=als-transport-inventory$/);
    await expect(links.nth(1)).toHaveAttribute('href', /\/employee\/forms\?form=als-non-transport-inventory$/);
    await expect(links.nth(2)).toHaveAttribute('href', /\/employee\/forms\?form=fire-boat-6-medical-inventory$/);
    await expect(links.nth(3)).toHaveAttribute('href', /\/employee\/forms\?form=bls-detail-medical-inventory$/);
    await expect(links.nth(4)).toHaveAttribute('href', /\/employee\/forms\?form=narcotics-check$/);
    await expect(links.nth(5)).toHaveAttribute('href', /\/daily\/vehicle-inspections$/);
    for (const box of await links.evaluateAll(items => items.map(item => item.getBoundingClientRect().height))) expect(box).toBeGreaterThanOrEqual(44);

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expect(page.locator('[data-home-command-board]')).toHaveCount(0);
    mkdirSync(shotsDir, { recursive: true });
    await page.screenshot({ path: join(shotsDir, `home-member-${label}-${testInfo.project.name}.png`), fullPage: true });
  });

  test('a link opens the real destination', async ({ page }) => {
    await openHome(page);
    await page.locator('details[data-rescue-forms] summary').click();
    await page.locator('details[data-rescue-forms]').getByRole('link', { name: 'Fire Boat 6 Medical Inventory Form' }).click();
    await expect(page).toHaveURL(/\/employee\/forms\?form=fire-boat-6-medical-inventory/);
  });
});

test.describe('officer (Captain)', () => {
  test.use({ storageState: join(authDir, 'officer.json') });

  test('Command Board sits beside Vehicle Checkout and Vehicle Checkout is unchanged', async ({ page }, testInfo) => {
    await openHome(page);
    const checkout = page.getByRole('link', { name: 'Vehicle Checkout', exact: true });
    await expect(checkout).toHaveAttribute('href', /\/daily\/vehicle-inspections$/);
    const board = page.locator('[data-home-command-board]');
    await expect(board).toHaveCount(1);
    await expect(board).toBeVisible();
    await expect(board).toHaveAttribute('href', /^https:\/\/[^/]+\/?$/);
    await expect(board).toHaveAttribute('target', '_blank');
    await expect(board).toHaveAttribute('rel', /noopener/);
    expect((await board.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    mkdirSync(shotsDir, { recursive: true });
    await page.screenshot({ path: join(shotsDir, `home-officer-${label}-${testInfo.project.name}.png`) });
  });
});
