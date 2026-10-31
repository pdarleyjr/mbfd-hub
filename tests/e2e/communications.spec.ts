import { expect, test, type Page } from '@playwright/test';

test.beforeEach(async ({ page, isMobile }) => {
  await page.goto('/admin');
  await page.getByLabel('Employee ID').fill('99881');
  await page.getByLabel('Password', { exact: true }).fill(process.env.COMMUNICATIONS_E2E_PASSWORD!);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin(?!\/login)/);
  if (isMobile) {
    await page.locator('.fi-sidebar-close-overlay').click({ position: { x: page.viewportSize()!.width - 10, y: 200 } });
  }
});

async function fits(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
}

test('Sent, message details, Reply and Forward preserve message context', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (['warning', 'error'].includes(message.type()) && !/Blocked script execution/.test(message.text())) errors.push(message.text()); });
  // Changed: the old Outbound Email list and message page lead into the Email Center (Sent folder and conversation).
  await page.goto('/admin/outbound-emails');
  await expect(page).toHaveURL(/\/admin\/email\?folder=sent/);
  await expect(page.locator('[data-mail-row]', { hasText: 'Communications browser fixture' })).toBeVisible();
  await expect(page.getByText('private@example.test')).toHaveCount(0);
  await fits(page);
  await page.screenshot({ path: info.outputPath('sent.png'), fullPage: true });
  await page.goto('/admin/outbound-emails/1');
  await expect(page).toHaveURL(/\/admin\/email\?folder=sent&thread=\d+/);
  await expect(page.getByText('A visible original message for browser verification.')).toBeVisible();
  await expect(page.locator('.mail-delivery .mail-badge').filter({ hasText: 'Accepted by receiving server' }).first()).toBeVisible();
  // The sender (this administrator) sees their own Bcc, labelled as private to them; the old page never showed it to anyone.
  await expect(page.getByText('only you can see this')).toHaveCount(0);
  await page.locator('summary', { hasText: 'Sender and recipient details' }).click();
  await expect(page.getByText('only you can see this')).toBeVisible();
  await fits(page);
  await page.screenshot({ path: info.outputPath('outbound-view.png'), fullPage: true });
  await page.locator('[data-mail-reader]').getByRole('button', { name: 'Reply', exact: true }).click();
  await expect(page.getByLabel('Subject', { exact: false })).toHaveValue('Re: Communications browser fixture');
  await expect(page.locator('.mail-chip', { hasText: 'recipient@example.test' })).toBeVisible();
  await fits(page);
  await page.getByRole('button', { name: 'Save and close' }).click();
  await page.goto('/admin/outbound-emails/1');
  await page.locator('[data-mail-reader]').getByRole('button', { name: 'Forward', exact: true }).click();
  await expect(page.getByLabel('Subject', { exact: false })).toHaveValue('Fwd: Communications browser fixture');
  await expect(page.locator('trix-editor')).toContainText('Original message');
  await page.screenshot({ path: info.outputPath('forward.png'), fullPage: true });
  expect(errors).toEqual([]);
});

test('Inbox and Compose are usable and reply all excludes the Hub address', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (['warning', 'error'].includes(message.type()) && !/Blocked script execution/.test(message.text())) errors.push(message.text()); });
  // Changed: Inbound Email and Compose Email lead into the Email Center Inbox and composer.
  await page.goto('/admin/inbound-emails');
  await expect(page).toHaveURL(/\/admin\/email$/);
  await expect(page.locator('[data-mail-row]', { hasText: 'Incoming browser fixture' })).toBeVisible();
  await fits(page);
  await page.goto('/admin/inbound-emails/1');
  await expect(page).toHaveURL(/\/admin\/email\?folder=inbox&thread=\d+/);
  await expect(page.getByText('Incoming message body.', { exact: true })).toBeVisible();
  await fits(page);
  await page.screenshot({ path: info.outputPath('inbound-view.png'), fullPage: true });
  await page.locator('[data-mail-reader]').getByRole('button', { name: 'Reply all', exact: true }).click();
  await expect(page.getByLabel('Subject', { exact: false })).toHaveValue('Re: Incoming browser fixture');
  await expect(page.locator('.mail-chip', { hasText: 'sender@example.test' })).toBeVisible();
  // The Hub's own address is never a recipient of its own reply.
  await expect(page.locator('.mail-chip', { hasText: 'info@mbfdhub.com' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Save and close' }).click();
  await page.goto('/admin/compose-email');
  await expect(page).toHaveURL(/\/admin\/email$/);
  await page.locator('.mail-compose-btn').click();
  await expect(page.locator('[data-mail-send]')).toBeVisible();
  await expect(page.getByLabel('Subject', { exact: false })).toHaveValue('');
  await fits(page);
  await page.screenshot({ path: info.outputPath('compose.png'), fullPage: true });
  expect(errors).toEqual([]);
});

test('selected onboarding preview contains only the selected cohort', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (['warning', 'error'].includes(message.type())) errors.push(message.text()); });
  await page.goto('/admin/employees');
  await page.waitForFunction(() => {
    const table = document.querySelector('.fi-ta');
    const alpine = (window as unknown as { Alpine?: { $data: (element: Element) => { selectedRecords?: unknown[] } } }).Alpine;
    return table && Array.isArray(alpine?.$data(table).selectedRecords);
  });
  const selected = page.getByRole('row').filter({ has: page.getByText('Selected Member', { exact: true }) });
  await selected.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Send onboarding invitations to selected members' }).click();
  const modal = page.getByRole('dialog').filter({ hasText: 'Selected Member' });
  await expect(modal.getByText(/99882/).first()).toBeVisible();
  await expect(modal.getByText(/fixture99882@miamibeachfl.gov/)).toBeVisible();
  await expect(modal.getByText(/1 ready to invite; 0 skipped/)).toBeVisible();
  await expect(modal.getByText(/Unselected Member/)).toHaveCount(0);
  await expect(modal.getByLabel('Your administrator password')).toBeVisible();
  await fits(page);
  await page.screenshot({ path: info.outputPath('selected-invitations.png'), fullPage: true });
  expect(errors).toEqual([]);
});
