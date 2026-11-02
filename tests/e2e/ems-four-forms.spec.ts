import { expect, test, type Page } from '@playwright/test';

/*
 * The four medical inventory forms on the Forms page (ALS Transport, ALS Non Transport, Fire Boat 6, BLS Detail), and the phone-first
 * way of filling one in: find a supply, enter a quantity, review, send once.
 * Needs the four source lists imported (the E2E seeder runs the importer) and a built bundle:
 *   npx vite build
 *   npx playwright test -c playwright.operational-forms.config.ts ems-four-forms --project=laptop
 * The widths below set their own viewport, so one project is enough.
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';

interface FormCase { formKey: string; title: string; family: string }
const FORMS: FormCase[] = [
  { formKey: 'als-transport-inventory', title: 'ALS Transport Inventory Form', family: 'als-transport' },
  { formKey: 'als-non-transport-inventory', title: 'ALS Non Transport Inventory Form', family: 'als-non-transport' },
  { formKey: 'fire-boat-6-medical-inventory', title: 'Fire Boat 6 Medical Inventory Form', family: 'fire-boat-6' },
  { formKey: 'bls-detail-medical-inventory', title: 'BLS Detail Medical Inventory Form', family: 'bls-detail' },
];
const ALS = FORMS[0];
const BLS = FORMS[3];

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'laptop', 'These tests set their own phone and tablet widths; the laptop project runs them once.');
});

async function signIn(page: Page, url: string) {
  await page.goto(url);
  await page.getByLabel('Employee ID').fill(employeeId);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
}

const heading = (page: Page, title: string) => page.getByRole('heading', { name: title, level: 1 });

async function openForm(page: Page, form: FormCase) {
  await page.goto(`/employee/forms?form=${form.formKey}`);
  await expect(heading(page, form.title)).toBeVisible({ timeout: 30_000 });
}

async function openFirstTime(page: Page, form: FormCase) {
  await signIn(page, `/employee/forms?form=${form.formKey}`);
  await expect(heading(page, form.title)).toBeVisible({ timeout: 30_000 });
}

async function noSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'the page must not scroll sideways').toBeLessThanOrEqual(1);
}

async function ownerOf(page: Page): Promise<string> {
  return page.evaluate(() => String(JSON.parse(document.getElementById('operational-forms-root')?.getAttribute('data-bootstrap') ?? '{}').employee.id));
}

async function storedDraft(page: Page, key: string): Promise<Record<string, any> | null> {
  return page.evaluate((id) => new Promise((resolve, reject) => {
    const open = indexedDB.open('mbfd-ems-requests');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const request = open.result.transaction('drafts').objectStore('drafts').get(id);
      request.onsuccess = () => { open.result.close(); resolve(request.result ?? null); };
      request.onerror = () => reject(request.error);
    };
  }), key);
}

/** The first quantity row on screen and its "+" button: whatever the form's first item is. */
const firstRow = (page: Page) => page.locator('.ems-item').first();

// ---------------------------------------------------------------- the library and the deep links

test.describe('on a phone, 375px wide', () => {
  test.use({ viewport: { width: 375, height: 812 }, hasTouch: true });

  test('the library offers the four forms by their exact titles, and each opens its own form', async ({ page }) => {
    await signIn(page, '/employee/forms');
    for (const form of FORMS) {
      await expect(page.locator('[data-library-link]', { hasText: form.title }).first()).toBeVisible({ timeout: 30_000 });
    }
    // The member's list is the same for all four, so it is offered once, not on every card.
    await expect(page.getByRole('link', { name: /^My medical (forms|requests)$/ })).toHaveCount(1);
    await noSidewaysScroll(page);

    for (const form of FORMS) {
      await page.goto('/employee/forms');
      await page.locator('[data-library-link]', { hasText: form.title }).first().getByRole('link').first().click();
      await expect(heading(page, form.title)).toBeVisible();
      expect(page.url()).toContain(`form=${form.formKey}`);
    }
  });

  test('every form opens by deep link; the legacy link is ALS Transport; Fire Boat 6 never asks for a vehicle number', async ({ page }) => {
    await signIn(page, '/employee/forms?form=medical-inventory-request');
    await expect(heading(page, ALS.title)).toBeVisible({ timeout: 30_000 });

    for (const form of FORMS) {
      await openForm(page, form);
      await expect(page.locator('.ems-app')).toHaveAttribute('data-family', form.family);
      await noSidewaysScroll(page);
      await expect(page.getByLabel(/vehicle number/i)).toHaveCount(0);
      await expect(page.getByLabel('Search supplies')).toBeVisible();
    }

    // Detail / Special Event is the BLS Detail form's own unit: preselected, named at the top, and the member can change it.
    await openForm(page, BLS);
    await expect(page.locator('.ems-head-unit')).toContainText('Detail / Special Event');
    await page.getByRole('button', { name: 'Change unit' }).click();
    await page.getByRole('radio', { name: /^RESCUE R2$/ }).click();
    await expect(page.locator('.ems-head-unit')).toContainText('RESCUE R2');
  });

  // ---------------------------------------------------------------- finding and entering

  test('search finds a variant however it is typed and entered quantities survive any filter', async ({ page }) => {
    await openFirstTime(page, ALS);
    const search = page.getByLabel('Search supplies');
    expect(await search.evaluate((el) => parseFloat(getComputedStyle(el).fontSize)), 'no zoom on focus').toBeGreaterThanOrEqual(16);

    const vehicleAdenocard = () => page.locator('.ems-results .ems-item', { hasText: 'ALS Transport Vehicle ›' }).filter({ hasText: 'Adenocard 12 mg' }).first();
    for (const query of ['adenocard 12 mg', 'ADENOCARD  12MG', 'adenocard12mg', 'adeno-card 12 mg']) {
      await search.fill(query);
      await expect(vehicleAdenocard(), `"${query}" finds Adenocard 12 mg, with its list and section`).toBeVisible();
    }

    const hit = vehicleAdenocard();
    await hit.getByRole('button', { name: /One more Adenocard/ }).click();
    await hit.getByRole('button', { name: /One more Adenocard/ }).click();
    await expect(hit.getByLabel(/number needed/)).toHaveValue('2');

    await search.fill('narcan');
    await expect(page.locator('.ems-results .ems-item').first()).toBeVisible();
    await search.fill('adenocard');
    await expect(vehicleAdenocard().getByLabel(/number needed/)).toHaveValue('2');

    await page.getByRole('button', { name: 'Clear search' }).click();
    await expect(page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ })).toContainText('1 entered');

    // Entered only: just what was typed, in the list on screen.
    await page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ }).click();
    await page.getByRole('button', { name: /^Entered only/ }).click();
    await expect(page.locator('.ems-item')).toHaveCount(1);
    await page.getByRole('button', { name: /^Entered only/ }).click();
    await expect(page.locator('.ems-item').nth(1)).toBeVisible();
  });

  test('the search bar and the review bar stay on screen, targets are 44px, and nothing scrolls sideways', async ({ page }) => {
    await openForm(page, ALS);
    await page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ }).click();
    await page.getByRole('button', { name: 'Open all sections' }).click();
    await page.evaluate(() => window.scrollTo(0, 2000));
    await expect(page.getByLabel('Search supplies')).toBeInViewport();
    await expect(page.getByRole('button', { name: 'Review and send' })).toBeInViewport();
    await noSidewaysScroll(page);

    const row = firstRow(page);
    await row.scrollIntoViewIfNeeded();
    for (const control of await row.locator('button, input').all()) {
      const box = await control.boundingBox();
      expect(box?.width ?? 0, 'touch target width').toBeGreaterThanOrEqual(43.5);
      expect(box?.height ?? 0, 'touch target height').toBeGreaterThanOrEqual(43.5);
    }
    const input = row.locator('input.ems-qty-input');
    expect(await input.getAttribute('inputmode')).toBe('numeric');
    expect(await input.evaluate((el) => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);

    // Clearing returns to blank, never 0.
    await row.getByRole('button', { name: /One more/ }).click();
    await expect(input).toHaveValue('1');
    await input.fill('');
    await expect(input).toHaveValue('');
    await input.fill('0');
    await expect(input).toHaveValue('');
  });

  // ---------------------------------------------------------------- drafts

  test('a draft survives a reload in each form, and one form never shows another form\'s entries', async ({ page }) => {
    await openFirstTime(page, ALS);
    const owner = await ownerOf(page);
    await page.getByRole('radio', { name: /^RESCUE R2$/ }).click();
    await firstRow(page).getByRole('button', { name: /One more/ }).click();
    await page.waitForTimeout(700);

    await openForm(page, BLS);
    await expect(page.getByText('Your unfinished request was restored')).toHaveCount(0);
    await expect(firstRow(page).getByLabel(/number needed/)).toHaveValue('');
    await firstRow(page).getByRole('button', { name: /One more/ }).click();
    await firstRow(page).getByRole('button', { name: /One more/ }).click();
    await page.waitForTimeout(700);

    // ALS Transport keeps the legacy row (the employee id); the other forms have a row of their own; the version stays 1.
    const als = await storedDraft(page, owner);
    const bls = await storedDraft(page, `${owner}|${BLS.family}`);
    expect(als?.v).toBe(1);
    expect(bls?.v).toBe(1);
    expect(bls?.family).toBe(BLS.family);
    expect(Object.keys(als?.quantities ?? {}).every((key) => !key.startsWith('bls-detail-bag|'))).toBe(true);
    expect(Object.keys(bls?.quantities ?? {}).every((key) => key.startsWith('bls-detail-bag|'))).toBe(true);

    await page.reload();
    await expect(heading(page, BLS.title)).toBeVisible();
    await expect(firstRow(page).getByLabel(/number needed/)).toHaveValue('2');
    await openForm(page, ALS);
    await expect(page.getByRole('radio', { name: /^RESCUE R2$/ })).toBeChecked();
    await expect(firstRow(page).getByLabel(/number needed/)).toHaveValue('1');
    await expect(page.getByText('Your unfinished request was restored')).toBeVisible();
  });

  // ---------------------------------------------------------------- sending

  test('an offline send waits on the device, replays once with its family, and a double tap cannot send twice', async ({ page }) => {
    await openFirstTime(page, BLS);
    const attempts: { family?: string; id: string; ok: boolean }[] = [];
    page.on('response', async (response) => {
      if (response.request().method() !== 'POST' || !response.url().endsWith('/employee/forms/api/ems/requests')) return;
      const body = response.request().postDataJSON();
      attempts.push({ id: body.client_submission_id, family: body.family, ok: response.ok() });
    });
    await page.route('**/employee/forms/api/ems/requests', (route) => (route.request().method() === 'POST' ? route.abort('internetdisconnected') : route.continue()));

    await firstRow(page).getByRole('button', { name: /One more/ }).click();
    await page.getByRole('button', { name: 'Review and send' }).click();
    await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();
    await expect(page.getByRole('heading', { name: 'Saved on this device' })).toBeVisible({ timeout: 30_000 });

    await page.unroute('**/employee/forms/api/ems/requests');
    await page.getByRole('button', { name: 'Try sending now' }).click();
    await expect.poll(() => attempts.filter((attempt) => attempt.ok).length, { timeout: 30_000 }).toBe(1);
    await page.waitForTimeout(1500);
    const ok = attempts.filter((attempt) => attempt.ok);
    expect(ok).toHaveLength(1);
    expect(ok[0].family).toBe('bls-detail');
    expect(new Set(attempts.map((attempt) => attempt.id)).size, 'one submission id for every try').toBe(1);
  });

  test('review lists only what was entered, by list and section, and Edit goes back to that exact item', async ({ page }) => {
    await openFirstTime(page, ALS);
    await page.getByRole('radio', { name: /^RESCUE R2$/ }).click();
    const gloves = page.locator('.ems-item', { hasText: 'Gloves' }).first();
    await gloves.getByRole('button', { name: /One more/ }).click();
    await gloves.getByRole('button', { name: /One more/ }).click();
    await page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /ALS Transport Vehicle/ }).click();
    const adenocard = page.locator('.ems-item', { hasText: 'Adenocard 12 mg' }).first();
    await adenocard.getByRole('button', { name: /One more/ }).click();

    await page.getByRole('button', { name: 'Review and send' }).click();
    const review = page.getByRole('dialog', { name: 'Review your request' });
    await expect(review).toContainText('ALS Transport');
    await expect(review).toContainText('RESCUE R2');
    await expect(review.getByRole('region', { name: 'Airway Bag' })).toContainText('Gloves');
    await expect(review.getByRole('region', { name: 'Airway Bag' })).toContainText('2 needed');
    await expect(review.getByRole('region', { name: 'ALS Transport Vehicle' })).toContainText('Adenocard 12 mg');
    await expect(review.locator('.ems-review-list li')).toHaveCount(2);
    await noSidewaysScroll(page);

    await review.getByRole('button', { name: 'Edit Gloves' }).click();
    await expect(review).toHaveCount(0);
    await expect(page.getByLabel('Gloves, number needed').first()).toBeFocused();
    await expect(page.getByRole('navigation', { name: 'Choose a list' }).getByRole('button', { name: /Airway Bag/ })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('button', { name: 'Review and send' }).click();
    await page.getByRole('dialog', { name: 'Review your request' }).getByRole('button', { name: /^Send/ }).click();
    await expect(page.getByRole('heading', { name: 'Request sent' })).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.ems-result-number')).toContainText(/^MIR-\d+/);
    await expect(page.locator('.ems-result')).toContainText('ALS Transport');
    await expect(page.getByRole('button', { name: 'My medical requests' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Files copy in Forms' })).toBeVisible();

    // My requests names the form each request was made on.
    await page.getByRole('button', { name: 'My medical requests' }).click();
    await expect(page.locator('.ems-request-item').first().locator('.ems-family-tag')).toHaveText('ALS Transport');
  });
});

// ---------------------------------------------------------------- a tablet

test.describe('on a tablet, 768px wide', () => {
  test.use({ viewport: { width: 768, height: 1024 }, hasTouch: true });

  test('every form fits without sideways scrolling and the lists stay one tap apart', async ({ page }) => {
    await signIn(page, `/employee/forms?form=${ALS.formKey}`);
    await expect(heading(page, ALS.title)).toBeVisible({ timeout: 30_000 });
    for (const form of FORMS) {
      await openForm(page, form);
      await noSidewaysScroll(page);
    }
    await openForm(page, ALS);
    const chooser = page.getByRole('navigation', { name: 'Choose a list' });
    expect(await chooser.getByRole('button').count()).toBeGreaterThanOrEqual(3);
    for (const button of await chooser.getByRole('button').all()) {
      expect((await button.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    }
    await expect(page.getByLabel('Search supplies')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Review and send' })).toBeVisible();
  });
});
