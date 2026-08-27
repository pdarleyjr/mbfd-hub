import { test as setup, expect } from '@playwright/test';
import { loopbackBaseUrl } from './support/test-environment';

const BASE_URL = loopbackBaseUrl('E2E_BASE_URL', 'http://127.0.0.1:8098', 'PLAYWRIGHT_BASE_URL');
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? '';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? '';
const AUTH_FILE = 'tests/e2e/.auth/admin.json';

if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
  throw new Error(
    'E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD must be set (see .env.testing.example). ' +
      'Do NOT hardcode credentials in this file.'
  );
}

setup('authenticate as admin', async ({ page }) => {
  // Log Livewire responses
  page.on('response', async response => {
    if (response.url().includes('livewire/update')) {
      try {
        const body = await response.text();
        // Check for redirect in Livewire response
        if (body.includes('redirect')) {
          console.log('Livewire redirect found in response');
        }
        // Do not log response bodies: authentication failures may contain sensitive details.
        console.log('Livewire response length:', body.length);
      } catch {}
    }
  });

  await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);

  await page.locator('input[type="email"]').fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  
  // Wait for Livewire to process
  await page.waitForTimeout(3000);
  
  // Server authenticates successfully but Livewire redirect may not fire.
  // Navigate directly to admin - if auth succeeded, we'll land on dashboard
  if (page.url().includes('/admin/login')) {
    await page.goto(`${BASE_URL}/admin`, { waitUntil: 'networkidle' });
  }
  
  // Verify we're on admin (not redirected back to login)
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 15000 });

  await page.context().storageState({ path: AUTH_FILE });
});
