import { test, expect, type Page } from '@playwright/test';

const contextPayload = JSON.stringify({
  identity: { user_id: 18157 },
  offline: { security_version: 1 },
});

async function installCanonicalOrigin(page: Page) {
  await page.addInitScript((origin) => {
    const install = () => {
      const meta = document.createElement('meta');
      meta.name = 'mbfd-test-canonical-origin';
      meta.content = origin;
      document.head.appendChild(meta);
      (window as Window & { __MBFD_CANONICAL_ORIGIN__?: string }).__MBFD_CANONICAL_ORIGIN__ = origin;
    };

    if (document.head) {
      install();
    } else {
      document.addEventListener('DOMContentLoaded', install, { once: true });
    }
  }, 'http://localhost:4176');
}

async function mockContext(page: Page, status = 200) {
  await page.route('**/api/me/context', async (route) => {
    await route.fulfill({
      status,
      contentType: 'application/json',
      body: status === 200 ? contextPayload : JSON.stringify({ message: 'Unauthenticated.' }),
    });
  });
}

test('redirects to canonical login when the station API reports an expired session', async ({ page }) => {
  await installCanonicalOrigin(page);
  await mockContext(page);
  await page.route('**/api/public/stations', async (route) => {
    await route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Unauthenticated.' }),
    });
  });

  await page.goto('/daily/stations');

  await expect.poll(() => new URL(page.url()).origin).toBe('http://localhost:4176');
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login');
  await expect.poll(() => new URL(page.url()).searchParams.get('session_expired')).toBe('1');
});

test('keeps server failures and network failures on the Daily failure surface', async ({ page }) => {
  await mockContext(page);
  await page.route('**/api/public/stations', async (route) => {
    await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable.' }) });
  });
  await page.goto('/daily/stations');
  await expect(page.getByText('Failed to load stations')).toBeVisible();
  expect(new URL(page.url()).pathname).toBe('/daily/stations');

  await page.reload();
  await page.unroute('**/api/public/stations');
  await page.route('**/api/public/stations', (route) => route.abort('failed'));
  await expect(page.getByText('Failed to load stations')).toBeVisible();
  expect(new URL(page.url()).pathname).toBe('/daily/stations');
});

test('redirects station detail 401 responses through canonical login', async ({ page }) => {
  await installCanonicalOrigin(page);
  await mockContext(page);
  await page.route('**/api/public/stations/1', async (route) => {
    await route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Unauthenticated.' }) });
  });
  await page.goto('/daily/stations/1');
  await expect.poll(() => new URL(page.url()).origin).toBe('http://localhost:4176');
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login');
  await expect.poll(() => new URL(page.url()).searchParams.get('session_expired')).toBe('1');
});

test('redirects an explicit online member-context 401 without touching queued data', async ({ page }) => {
  await installCanonicalOrigin(page);
  await mockContext(page, 401);
  await page.route('**/api/public/stations', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ stations: [] }) });
  });
  await page.goto('/daily/stations');
  await expect.poll(() => new URL(page.url()).origin).toBe('http://localhost:4176');
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login');
  await expect.poll(() => new URL(page.url()).searchParams.get('session_expired')).toBe('1');
});
