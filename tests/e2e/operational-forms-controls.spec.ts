import { expect, test } from '@playwright/test';
import { PRIMARY_BUTTON, readButton } from './support/control-contract';

/*
 * Operational Forms (Fluent) primary button against the shared Hub control contract.
 *   npx playwright test -c playwright.operational-forms.config.ts operational-forms-controls --project=laptop
 * The Blade and Filament halves live in surface-system.spec.ts and assert the same constants.
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';

test('primary button computes the shared contract (colour, radius, height)', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'laptop', 'The contract values are for a fine pointer.');
  await page.goto('/employee/forms');
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/employee\/forms$/, { timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Operational Forms' })).toBeVisible();

  const create = page.locator('.of-form-card').first().getByRole('button', { name: 'Create form' });
  await expect(create).toBeVisible();
  const fluent = await create.evaluate(readButton);
  await testInfo.attach('operational-forms-primary.json', { body: Buffer.from(JSON.stringify({ fluent, contract: PRIMARY_BUTTON }, null, 2)), contentType: 'application/json' });

  expect(fluent).toMatchObject({ background: PRIMARY_BUTTON.background, color: PRIMARY_BUTTON.color, borderTopLeftRadius: PRIMARY_BUTTON.borderTopLeftRadius });
  expect(Math.abs(fluent.height - PRIMARY_BUTTON.height)).toBeLessThanOrEqual(0.5);
});
