import { test, expect } from '@playwright/test';

test('redirects to normal login when the station API reports an expired session', async ({ page }) => {
  await page.route('**/api/me/context', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({}),
    });
  });
  await page.route('**/api/public/stations', async (route) => {
    await route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Unauthenticated.' }),
    });
  });

  await page.goto('/daily/stations');

  await expect.poll(() => new URL(page.url()).pathname).toBe('/login');
  await expect.poll(() => new URL(page.url()).searchParams.get('session_expired')).toBe('1');
});
