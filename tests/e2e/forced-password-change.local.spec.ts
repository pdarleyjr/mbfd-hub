import { expect, test } from '@playwright/test';

const baseUrl = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8098';
const email = process.env.FORCED_PASSWORD_E2E_EMAIL ?? '';
const currentPassword = process.env.FORCED_PASSWORD_E2E_CURRENT_PASSWORD ?? '';
const newPassword = process.env.FORCED_PASSWORD_E2E_NEW_PASSWORD ?? '';

test.beforeEach(() => {
  if (!/^http:\/\/(127\.0\.0\.1|localhost)(?::\d+)?$/.test(baseUrl)) {
    throw new Error('Forced-password browser acceptance is restricted to a local HTTP server.');
  }

  if (!email || !currentPassword || !newPassword) {
    throw new Error('Set the disposable local forced-password E2E credentials before running this test.');
  }
});

test('flagged admin changes the password and remains authenticated in the panel', async ({ page }) => {
  await page.goto('/admin/login');
  await page.getByLabel(/email/i).fill(email);
  await page.getByLabel('Password').fill(currentPassword);
  await page.getByRole('button', { name: /sign in/i }).click();

  await expect(page).toHaveURL(/\/admin\/set-password$/);
  await expect(page.getByRole('heading', { name: 'Password Change Required' })).toBeVisible();

  await page.locator('[id="data.current_password"]').fill(currentPassword);
  await page.locator('[id="data.password"]').fill(newPassword);
  await page.locator('[id="data.password_confirmation"]').fill(newPassword);
  await page.getByRole('button', { name: 'Set Password & Continue' }).click();

  await expect(page).toHaveURL(/\/admin$/);

  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin$/);
});
