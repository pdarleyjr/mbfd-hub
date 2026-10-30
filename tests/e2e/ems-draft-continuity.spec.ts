import { expect, test, type BrowserContext, type Page } from '@playwright/test';

/*
 * Release continuity for the Medical Inventory Request: members who are partway through a request when a
 * release ships must not lose or redo any work.
 *   npx vite build
 *   npx playwright test -c playwright.operational-forms.config.ts ems-draft-continuity --project=laptop --project=phone
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const FORM_URL = '/employee/forms?form=medical-inventory-request';
const GLOVES = 'airway-bag|airway.top.gloves';
const ASHERMAN = 'als-med-bag|als.trauma.asherman-chest-seal';
const RETIRED = 'retired-list|old.item';

test.beforeEach(({}, testInfo) => {
  test.skip(!['laptop', 'phone'].includes(testInfo.project.name), 'Continuity is checked on one pointer and one touch device.');
});

async function signIn(page: Page) {
  await page.goto(FORM_URL);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('heading', { name: 'Medical Inventory Request', level: 1 })).toBeVisible({ timeout: 30_000 });
}

async function ownerOf(page: Page): Promise<string> {
  return page.evaluate(() => String(JSON.parse(document.getElementById('operational-forms-root')?.getAttribute('data-bootstrap') ?? '{}').employee.id));
}

/** Writes a draft row the way a release-old bundle left it (stored version 1). Run from a page that is not showing the form. */
async function seedStoredDraft(page: Page, row: Record<string, unknown>) {
  await page.evaluate((value) => new Promise<void>((resolve, reject) => {
    const open = indexedDB.open('mbfd-ems-requests');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const tx = open.result.transaction('drafts', 'readwrite');
      tx.objectStore('drafts').put(value);
      tx.oncomplete = () => { open.result.close(); resolve(); };
      tx.onerror = () => reject(tx.error);
    };
  }), row);
}

async function storedDraft(page: Page, owner: string): Promise<Record<string, any> | null> {
  return page.evaluate((key) => new Promise((resolve, reject) => {
    const open = indexedDB.open('mbfd-ems-requests');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const request = open.result.transaction('drafts').objectStore('drafts').get(key);
      request.onsuccess = () => { open.result.close(); resolve(request.result ?? null); };
      request.onerror = () => reject(request.error);
    };
  }), owner);
}

/** Signs in, notes who the member is, and closes the page so nothing is left to overwrite a seeded draft. */
async function prepareMember(context: BrowserContext): Promise<{ owner: string; unit: string }> {
  const first = await context.newPage();
  await signIn(first);
  const owner = await ownerOf(first);
  const unit = await first.locator('#ems-target option', { hasText: 'Rescue 2' }).getAttribute('value');
  await first.close();
  expect(unit, 'the seeded Rescue 2 unit is a choice').toBeTruthy();
  return { owner, unit: unit as string };
}

async function openSeeded(context: BrowserContext, row: Record<string, unknown>): Promise<Page> {
  const quiet = await context.newPage();
  await quiet.goto('/employee/forms/api/ems/form'); // same origin, signed in, no form app on the page
  await seedStoredDraft(quiet, row);
  await quiet.close();
  const page = await context.newPage();
  await page.goto(FORM_URL);
  await expect(page.getByRole('heading', { name: 'Medical Inventory Request', level: 1 })).toBeVisible({ timeout: 30_000 });
  return page;
}

test('a draft saved before the upgrade comes back whole, the new list is empty and usable, and the page will not refresh over it', async ({ context }) => {
  const { owner, unit } = await prepareMember(context);
  const page = await openSeeded(context, {
    owner, v: 1, clientSubmissionId: '6f1e6b8a-1d2f-4c3a-9b1e-0d8c5a7e2f10', targetChoice: unit, savedAt: '2026-10-30T12:00:00.000Z',
    // The old bundle's shape, plus an entry for an item this definition does not list.
    quantities: { [GLOVES]: 3, [ASHERMAN]: 2, [RETIRED]: 4 }, answers: {},
  });

  await expect(page.getByText('Your unfinished request was restored')).toBeVisible();
  await expect(page.locator('#ems-target')).toHaveValue(unit);
  await expect(page.locator('.ems-item', { hasText: 'Gloves' }).first().getByLabel(/number needed/)).toHaveValue('3');
  await expect(page.getByText('1 saved entry is for items that are not on this form right now')).toBeVisible();

  const chooser = page.getByRole('navigation', { name: 'Choose a list' });
  await expect(chooser.getByRole('button', { name: /Airway Bag/ })).toContainText('1 needed');
  await expect(chooser.getByRole('button', { name: /ALS Med Bag/ })).toContainText('1 needed');
  await expect(chooser.getByRole('button', { name: /ALS Transport Vehicle/ })).not.toContainText('needed');

  await chooser.getByRole('button', { name: /ALS Transport Vehicle/ }).click();
  const narcan = page.locator('.ems-form .ems-item', { hasText: 'Narcan' });
  await expect(narcan.getByLabel('Narcan, number needed')).toHaveValue('');
  await narcan.getByRole('button', { name: 'One more Narcan' }).click();
  await page.locator('#ems-note').fill('typed before the update');

  // The refresh guard sees the work: the window never reloads on its own and the banner only asks.
  expect(await page.evaluate(() => (window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy?.has('ems-draft'))).toBe(true);
  await expect(page.locator('[data-mbfd-unsaved-work-guard]').first()).toBeAttached();

  await page.getByRole('button', { name: 'Review and send' }).click();
  const review = page.getByRole('dialog', { name: 'Review your request' });
  await expect(review).toContainText('kept on this device and not sent');
  await review.getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });

  // The retired entry was not sent, and it is still saved for the member.
  const left = await storedDraft(page, owner);
  expect(left?.carried?.quantities).toEqual({ [RETIRED]: 4 });
  expect(left?.quantities).toEqual({});
  expect(await page.evaluate(() => (window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy?.has('ems-draft'))).toBe(false);
});

test('a page loaded before the third list existed can still send once the server has it', async ({ page }) => {
  await page.route('**/employee/forms/api/ems/form', async (route) => {
    const response = await route.fetch();
    const json = await response.json();
    json.data.forms = json.data.forms.filter((form: { key: string }) => form.key !== 'als-transport-vehicle');
    await route.fulfill({ response, json });
  });
  await signIn(page);
  await expect(page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button')).toHaveCount(2);

  await page.unroute('**/employee/forms/api/ems/form'); // the server now has three lists; this page still has two
  const gloves = page.locator('.ems-item', { hasText: 'Gloves' }).first();
  await gloves.getByRole('button', { name: /One more/ }).click();
  await page.locator('#ems-target').selectOption({ label: 'Rescue 2' });
  await page.getByRole('button', { name: 'Review and send' }).click();
  await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });
});

test('a request queued on the device before the upgrade is sent after it, once, with the same id', async ({ page }) => {
  const attempts: { id: string; ok: boolean }[] = [];
  page.on('response', async (response) => {
    if (response.request().method() !== 'POST' || !response.url().endsWith('/employee/forms/api/ems/requests')) return;
    attempts.push({ id: response.request().postDataJSON().client_submission_id, ok: response.ok() });
  });
  await signIn(page);
  let dropped: string | null = null;
  await page.route('**/employee/forms/api/ems/requests', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    dropped = route.request().postDataJSON().client_submission_id;
    return route.abort('internetdisconnected');
  });
  await page.locator('.ems-item', { hasText: 'Gloves' }).first().getByRole('button', { name: /One more/ }).click();
  await page.locator('#ems-target').selectOption({ label: 'Rescue 2' });
  await page.getByRole('button', { name: 'Review and send' }).click();
  await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();
  await expect(page.getByRole('heading', { name: 'Saved on this device' })).toBeVisible({ timeout: 30_000 });
  expect(dropped).not.toBeNull();
  expect(await page.evaluate(() => (window as unknown as { __mbfdBusy?: Set<string> }).__mbfdBusy?.has('ems-outbox'))).toBe(true);

  // The release lands: the page loads again, the connection is back, the queue replays.
  await page.unroute('**/employee/forms/api/ems/requests');
  await page.goto(FORM_URL);
  await expect.poll(() => attempts.filter((attempt) => attempt.ok).length, { timeout: 30_000 }).toBe(1);
  expect(attempts.filter((attempt) => attempt.ok)[0].id).toBe(dropped);
  await page.waitForTimeout(1500);
  expect(attempts.filter((attempt) => attempt.ok)).toHaveLength(1);
});

test('a refused send (unknown item) keeps the quantities, the note and the saved draft', async ({ page }) => {
  await signIn(page);
  await page.route('**/employee/forms/api/ems/requests', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'unknown_item', message: 'One of the items is no longer on the form. Reload the page and try again.', fields: { lines: ['Unknown item'] } } }) });
  });
  await page.locator('.ems-item', { hasText: 'Gloves' }).first().getByRole('button', { name: /One more/ }).click();
  await page.locator('#ems-note').fill('do not lose me');
  await page.locator('#ems-target').selectOption({ label: 'Rescue 2' });
  await page.getByRole('button', { name: 'Review and send' }).click();
  const review = page.getByRole('dialog', { name: 'Review your request' });
  await review.getByRole('button', { name: /^Send/ }).click();
  await expect(review).toContainText('no longer on the form');
  await review.getByRole('button', { name: 'Back to edit' }).click();

  await expect(page.locator('#ems-note')).toHaveValue('do not lose me');
  await expect(page.locator('.ems-item', { hasText: 'Gloves' }).first().getByLabel(/number needed/)).toHaveValue('1');
  await page.waitForTimeout(600);
  const stored = await storedDraft(page, await ownerOf(page));
  expect(stored?.quantities?.[GLOVES]).toBe(1);
});
