import { expect, test } from '@playwright/test';

/**
 * The PIN gate runs as a Cloudflare Worker in production. In dev the Next.js
 * server is reached directly, so these tests are SKIPPED unless E2E_PIN_GATE
 * is true. Run against the deployed staging URL.
 */
const PIN_GATE = process.env.E2E_PIN_GATE === 'true';

test.describe('PIN gate', () => {
  test.skip(!PIN_GATE, 'PIN gate tests run only against the deployed worker');

  test('shows the PIN form for an unauthenticated request', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText(/PIN required/i)).toBeVisible();
    await expect(page.locator('input#pin')).toBeVisible();
  });

  test('rejects a wrong PIN', async ({ page }) => {
    await page.goto('/');
    await page.fill('input#pin', '0000');
    await page.click('button[type=submit]');
    await expect(page.getByText(/Incorrect PIN/i)).toBeVisible();
  });
});
