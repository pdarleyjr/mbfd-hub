import { test, expect } from '@playwright/test';

const BASE_URL = 'https://mbfdhub.com';
const ADMIN_EMAIL = 'MiguelAnchia@miamibeachfl.gov';
const ADMIN_PASSWORD = 'Penco1';

test('Debug admin page HTML', async ({ page }) => {
  await page.goto(`${BASE_URL}/admin/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('input[type="email"]', ADMIN_EMAIL);
  await page.fill('input[type="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  try {
    await page.waitForURL(/\/admin/, { timeout: 15000 });
  } catch {}
  await page.waitForTimeout(3000);
  const url = page.url();
  console.log('Current URL:', url);
  const content = await page.content();
  console.log('Page HTML (first 5000 chars):', content.substring(0, 5000));
  console.log('Contains Fleet Management:', content.includes('Fleet Management'));
  console.log('Contains navigation:', content.includes('navigation'));
  console.log('Contains fi-sidebar:', content.includes('fi-sidebar'));
  console.log('Contains apparatuses:', content.toLowerCase().includes('apparatuses'));
  const navContent = content.match(/fi-sidebar[^>]*>([\s\S]{0,2000})/);
  if (navContent) {
    console.log('Sidebar content:', navContent[0].substring(0, 1000));
  }
});
