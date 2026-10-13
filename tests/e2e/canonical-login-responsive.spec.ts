import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';

const authStyles = readFileSync(
  new URL('../../resources/views/auth/partials/city-email-style.blade.php', import.meta.url),
  'utf8',
);

// The shared head component carries the viewport meta; render it as the browser would receive it.
const sharedHead = readFileSync(
  new URL('../../resources/views/components/hub/head.blade.php', import.meta.url),
  'utf8',
).replace(/\{\{--[\s\S]*?--\}\}/g, '');

const template = readFileSync(
  new URL('../../resources/views/auth/canonical-login.blade.php', import.meta.url),
  'utf8',
)
  .replace("@include('auth.partials.city-email-style')", authStyles)
  .replace('<x-hub.head />', sharedHead)
  .replace(/{{[^}]*}}/g, '')
  .replace(/@(csrf|error\([^)]*\)|enderror)/g, '');

test('canonical login stays contained, named, touchable, and keyboard focusable', async ({ page }) => {
  await page.setContent(template);

  await expect(page.getByRole('heading', { name: 'MBFD Sign In' })).toBeVisible();
  await expect(page.getByText('Miami Beach Fire Department')).toBeVisible();
  await expect(page.getByLabel('Employee ID or email')).toBeVisible();
  await expect(page.getByLabel('Password')).toBeVisible();

  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    main: (() => {
      const box = document.querySelector('main')?.getBoundingClientRect();
      return box ? { left: box.left, right: box.right } : null;
    })(),
  }));
  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
  expect(dimensions.main).not.toBeNull();
  expect(dimensions.main?.left).toBeGreaterThanOrEqual(0);
  expect(dimensions.main?.right).toBeLessThanOrEqual(dimensions.clientWidth);

  for (const control of [page.getByLabel('Employee ID or email'), page.getByLabel('Password'), page.getByRole('button', { name: 'Sign in' })]) {
    const box = await control.boundingBox();
    expect(box?.height).toBeGreaterThanOrEqual(44);
  }

  const submit = page.getByRole('button', { name: 'Sign in' });
  await submit.focus();
  await expect(submit).toBeFocused();
});
