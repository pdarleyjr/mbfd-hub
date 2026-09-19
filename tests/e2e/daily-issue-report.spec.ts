import { expect, test } from '@playwright/test';

test('opens in place, retains the report on failure, and confirms a retry', async ({ page }) => {
  let attempts = 0;
  const submissions: string[] = [];
  await page.route('**/support/issues', async (route) => {
    attempts++;
    submissions.push(route.request().postData() ?? '');
    await route.fulfill({
      status: attempts === 1 ? 500 : 201,
      contentType: 'application/json',
      body: attempts === 1 ? '{"message":"Unavailable"}' : JSON.stringify({
        message: 'Thanks — we got it.',
        report: { reference: 'HUB-2026-000001', status: 'Received' },
      }),
    });
  });

  await page.goto('/daily/stations');
  const originalUrl = page.url();
  const trigger = page.getByRole('link', { name: 'Report an Issue' });
  await expect(trigger).toBeVisible();
  const box = await trigger.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.y).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
  expect(box!.y + box!.height).toBeLessThanOrEqual(page.viewportSize()!.height);
  await trigger.click();

  const dialog = page.getByRole('dialog', { name: 'Report an Issue' });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('textarea[required]')).toHaveCount(1);
  await expect(dialog.getByLabel('What went wrong?')).toBeVisible();
  await expect(dialog.getByLabel('+ Add screenshot or file')).toHaveCount(1);
  expect(page.url()).toBe(originalUrl);

  await dialog.getByLabel('What went wrong?').fill('I tapped Submit but nothing happened.');
  await dialog.getByRole('button', { name: 'Send', exact: true }).click();
  await expect(dialog.getByRole('alert')).toContainText('Your report is still here');
  await expect(dialog.getByLabel('What went wrong?')).toHaveValue('I tapped Submit but nothing happened.');
  await dialog.getByRole('button', { name: 'Try Again' }).click();
  await expect(dialog).toContainText('HUB-2026-000001');
  await expect(dialog.getByRole('status')).toBeFocused();
  expect(attempts).toBe(2);
  for (const submitted of submissions) {
    expect(submitted).toContain('I tapped Submit but nothing happened.');
    expect(submitted).toContain('page_path');
    expect(submitted).toContain('/daily/stations');
  }
  const submissionIds = submissions.map((submitted) =>
    submitted.match(/name="client_submission_id"\r\n\r\n([^\r]+)/)?.[1]
  );
  expect(submissionIds[0]).toMatch(/^[0-9a-f-]{36}$/);
  expect(submissionIds[1]).toBe(submissionIds[0]);
  expect(page.url()).toBe(originalUrl);

  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await trigger.click();
  await expect(dialog.getByLabel('What went wrong?')).toBeVisible();
  await expect(dialog.getByRole('status')).toHaveCount(0);
});
