import { expect, test } from '@playwright/test';

test('empty board shows the import CTA', async ({ page }) => {
  await page.goto('/board');
  await expect(page.getByText('No data imported yet')).toBeVisible({ timeout: 10_000 });
  await expect(page.getByRole('link', { name: /Go to Import/i })).toBeVisible();
});

test('top bar links navigate', async ({ page }) => {
  await page.goto('/board');
  await page.getByRole('link', { name: 'Import' }).click();
  await expect(page).toHaveURL(/\/import$/);
  await page.getByRole('link', { name: 'Runs' }).click();
  await expect(page).toHaveURL(/\/import\/runs$/);
});
