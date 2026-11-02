import { expect, test, type Page, type Route } from '@playwright/test';

/*
 * "Captain 5 rejected my medical inventory form, let me fix it and resend": the member side.
 * The page, the sign-in and the form definition are real; the request list, the request detail and the send are mocked,
 * so the checks do not depend on the staff side of the workflow.
 *   npx vite build
 *   npx playwright test -c playwright.operational-forms.config.ts ems-member-revision --project=laptop --project=phone
 * (the config's testMatch has to list ems-member-revision)
 */

const employeeId = process.env.OPERATIONAL_FORMS_E2E_EMPLOYEE_ID ?? 'E214';
const password = process.env.OPERATIONAL_FORMS_E2E_PASSWORD ?? 'OperationalForms!1';
const FORM_URL = '/employee/forms?form=medical-inventory-request';
const API = '/employee/forms/api/ems';

const GLOVES = 'airway-bag|airway.top.gloves';
const ASHERMAN = 'als-med-bag|als.trauma.asherman-chest-seal';
const RETIRED = 'retired-list|old.item';

const RETURNED_ID = '0c7c1f64-3b0e-4d61-9a4a-6f1d2b9e8a10';
const SUBMITTED_ID = '5a4d8e7c-2b1f-4e6a-8c3d-9f0e1a2b3c4d';
const SUPERSEDED_ID = '7b2e9d1a-4c3f-4a5b-9d6e-1f2a3b4c5d6e';
const CORRECTION_ID = '9d4f1b3c-6e5a-4c7d-8f9a-3b4c5d6e7f80';
const REASON = 'The gauze count is too high for one shift. Please check it and resend.';
const NOTE = 'Short after the Tuesday call.';

test.beforeEach(({}, testInfo) => {
  test.skip(!['laptop', 'phone'].includes(testInfo.project.name), 'The correction flow is checked on one pointer and one touch device.');
});

// ---------------------------------------------------------------- the real page

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

async function storedDraft(page: Page, key: string): Promise<Record<string, any> | null> {
  return page.evaluate((rowKey) => new Promise((resolve, reject) => {
    const open = indexedDB.open('mbfd-ems-requests');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const request = open.result.transaction('drafts').objectStore('drafts').get(rowKey);
      request.onsuccess = () => { open.result.close(); resolve(request.result ?? null); };
      request.onerror = () => reject(request.error);
    };
  }), key);
}

/** The id of the RESCUE R2 unit as the real form offers it. */
async function rescueTwoId(page: Page): Promise<number> {
  const choice = await page.getByRole('radio', { name: 'RESCUE R2', exact: true }).getAttribute('data-choice');
  expect(choice, 'the seeded RESCUE R2 unit is a choice').toMatch(/^unit:\d+$/);
  return Number((choice as string).slice(5));
}

// ---------------------------------------------------------------- the mocked requests API

type SendMode = 'ok' | 'offline' | 'cannot_revise' | 'replayed';
type Json = Record<string, any>;

function line(id: number, bagKey: string, itemKey: string, label: string, quantity: number): Json {
  return {
    id, bag_key: bagKey, item_key: itemKey, label, section_label: null, variant_label: null, min_text: null, unit: null, response_type: 'quantity',
    requested_qty: quantity, requested_flag: null, prepared_qty: null, issued_qty: 0, line_status: 'requested', line_status_label: 'Requested',
    not_issued_reason: null, refrigerated: false, controlled_substance: false,
  };
}

function detail(base: Json): Json {
  return {
    status_label: base.status, submitted_at: '2026-10-30T14:00:00Z', target_kind: 'rescue_unit', event_assignment: null, line_count: (base.lines ?? []).length,
    has_note: false, last_activity_at: null, closed_at: null, can_revise: false, return_reason: null, returned_at: null, returned_by: null,
    revision_number: 1, revision_of: null, replaced_by: null, member_note: null, lines: [], history: [], messages: [], close_reason: null,
    cancel_reason: null, files_copy: { status: 'pending' }, can_cancel: false, ...base,
  };
}

function summary(full: Json): Json {
  const { lines, history, messages, files_copy, ...rest } = full;
  void lines; void history; void messages; void files_copy;
  return rest;
}

/** A small stand-in for the member API: a returned request, an ordinary one, a replaced one and its correction. */
function mockRequests(page: Page, rescueTwo: number) {
  let mode: SendMode = 'ok';
  const posts: Json[] = [];
  const succeeded: Json[] = [];
  const created = new Map<string, Json>();
  const requests = new Map<string, Json>();

  const add = (full: Json) => requests.set(full.id, full);
  add(detail({
    id: RETURNED_ID, number: 'MIR-000004', status: 'returned', status_label: 'Returned', apparatus_id: rescueTwo, target_label: 'RESCUE R2',
    can_revise: true, can_cancel: true, return_reason: REASON, returned_at: '2026-10-31T13:00:00Z', returned_by: 'Captain Rivera', has_note: true, member_note: NOTE,
    lines: [line(1, 'airway-bag', 'airway.top.gloves', 'Gloves', 3), line(2, 'als-med-bag', 'als.trauma.asherman-chest-seal', 'Asherman Chest Seal', 2), line(3, 'retired-list', 'old.item', 'Old Item', 4)],
    history: [
      { id: 1, action: 'submitted', actor_name: null, actor_rank: null, actor_context: 'member', actor_context_label: 'Member', request_line_id: null, from_status: null, to_status: 'submitted', payload: {}, created_at: '2026-10-30T14:00:00Z' },
      { id: 2, action: 'rejected', actor_name: 'Captain Rivera', actor_rank: null, actor_context: 'captain_5', actor_context_label: 'Captain 5', request_line_id: null, from_status: 'submitted', to_status: 'returned', payload: { reason: REASON }, created_at: '2026-10-31T13:00:00Z' },
    ],
  }));
  add(detail({ id: SUBMITTED_ID, number: 'MIR-000003', status: 'submitted', status_label: 'Submitted', apparatus_id: rescueTwo, target_label: 'RESCUE R2', lines: [line(4, 'airway-bag', 'airway.top.gloves', 'Gloves', 1)] }));
  add(detail({
    id: SUPERSEDED_ID, number: 'MIR-000002', status: 'superseded', status_label: 'Replaced by a correction', apparatus_id: rescueTwo, target_label: 'RESCUE R2',
    replaced_by: CORRECTION_ID, lines: [line(5, 'airway-bag', 'airway.top.gloves', 'Gloves', 2)],
  }));
  add(detail({
    id: CORRECTION_ID, number: 'MIR-000002-R2', status: 'submitted', status_label: 'Submitted', apparatus_id: rescueTwo, target_label: 'RESCUE R2',
    revision_of: SUPERSEDED_ID, revision_number: 2, lines: [line(6, 'airway-bag', 'airway.top.gloves', 'Gloves', 2)],
  }));

  const respond = (route: Route, status: number, body: Json) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

  page.route(/\/employee\/forms\/api\/ems\/requests(\?.*)?$/, async (route) => {
    const request = route.request();
    if (request.method() === 'GET') {
      const rows = [...requests.values()].map(summary);
      return respond(route, 200, { ok: true, data: rows, meta: { total: rows.length, page: 1, per_page: 25, last_page: 1 } });
    }
    const payload = request.postDataJSON() as Json;
    posts.push(payload);
    if (mode === 'offline') return route.abort('internetdisconnected');
    if (mode === 'cannot_revise') {
      return respond(route, 409, { ok: false, error: { code: 'cannot_revise', message: 'This request cannot be revised.', fields: {} } });
    }
    const replay = created.get(payload.client_submission_id);
    if (replay) return respond(route, 200, { ok: true, data: replay, meta: { replayed: true } });
    const full = detail({
      id: `aaaaaaaa-0000-4000-8000-${String(created.size + 1).padStart(12, '0')}`, number: 'MIR-000004-R2', status: 'submitted', status_label: 'Submitted',
      apparatus_id: payload.target.apparatus_id, target_label: 'RESCUE R2', revision_of: payload.revises ?? null, revision_number: 2, member_note: payload.member_note ?? null,
      lines: payload.lines.map((entry: Json, index: number) => line(100 + index, entry.form, entry.item_key, entry.item_key, entry.quantity ?? 0)),
    });
    created.set(payload.client_submission_id, full);
    succeeded.push(payload);
    if (payload.revises && requests.has(payload.revises)) {
      requests.set(payload.revises, { ...requests.get(payload.revises), status: 'superseded', status_label: 'Replaced by a correction', can_revise: false, replaced_by: full.id });
    }
    add(full);
    return respond(route, mode === 'replayed' ? 200 : 201, { ok: true, data: full, meta: mode === 'replayed' ? { replayed: true } : {} });
  });

  page.route(/\/employee\/forms\/api\/ems\/requests\/([0-9a-f-]{36})$/, async (route) => {
    const id = route.request().url().split('/').pop() as string;
    const found = requests.get(id);
    if (!found) return respond(route, 404, { ok: false, error: { code: 'not_found', message: 'That could not be found.' } });
    return respond(route, 200, { ok: true, data: found, meta: {} });
  });

  return {
    posts, succeeded,
    setMode: (next: SendMode) => { mode = next; },
  };
}

// ---------------------------------------------------------------- steps

async function openMine(page: Page) {
  await page.getByRole('button', { name: 'My medical requests' }).first().click();
  await expect(page.getByRole('heading', { name: 'My medical requests', level: 1 })).toBeVisible();
}

async function fixFromDetail(page: Page) {
  await openMine(page);
  await page.getByRole('button', { name: /MIR-000004, Returned/ }).click();
  await expect(page.getByRole('heading', { name: 'Needs your correction', level: 2 })).toBeVisible();
  await page.getByRole('button', { name: 'Fix and resend' }).click();
  await expect(page.locator('.ems-revision-banner')).toBeVisible();
}

async function review(page: Page) {
  await page.getByRole('button', { name: 'Review and send' }).click();
  return page.getByRole('dialog', { name: 'Review your request' });
}

async function glovesInput(page: Page) {
  return page.locator('.ems-item', { hasText: 'Gloves' }).first().getByLabel(/number needed/);
}

async function startOnFormWithMocks(page: Page) {
  await signIn(page);
  const unit = await rescueTwoId(page);
  const mock = mockRequests(page, unit);
  return { mock, owner: await ownerOf(page), unit };
}

// ---------------------------------------------------------------- tests

test('a returned request says what Captain 5 said, who and when, and has one Fix and resend button; the others keep working', async ({ page }) => {
  await startOnFormWithMocks(page);
  await openMine(page);

  const returned = page.locator('.ems-request-item', { hasText: 'MIR-000004' });
  await expect(returned).toContainText('Needs your correction');
  await expect(returned).toContainText(REASON);
  await expect(returned.getByRole('button', { name: 'Fix and resend' })).toHaveCount(1);
  await expect(page.locator('.ems-request-item', { hasText: 'MIR-000003' }).getByRole('button', { name: 'Fix and resend' })).toHaveCount(0);
  await expect(page.locator('.ems-request-item', { hasText: 'MIR-000002' }).first()).toContainText('Replaced by a correction');

  await returned.getByRole('button', { name: /MIR-000004/ }).first().click();
  const card = page.getByRole('region', { name: 'Needs your correction' });
  await expect(card).toContainText('Captain Rivera');
  await expect(card).toContainText('Oct 31, 2026');
  await expect(card).toContainText(REASON);
  await expect(card.getByRole('button', { name: 'Fix and resend' })).toHaveCount(1);
  // A returned request can still be cancelled when the server says so.
  await expect(page.getByRole('button', { name: 'Cancel request' })).toBeVisible();

  // A replaced request points at its correction, and the correction points back.
  await openMine(page);
  await page.getByRole('button', { name: /MIR-000002, Replaced by a correction/ }).click();
  await expect(page.locator('.ems-request-link')).toContainText('Replaced by MIR-000002-R2');
  await page.locator('.ems-request-link .ems-link').click();
  await expect(page.locator('h1.ems-detail-title')).toContainText('MIR-000002-R2');
  await expect(page.locator('.ems-request-link')).toContainText('Corrects MIR-000002');
});

test('Fix and resend opens with the exact earlier values; editing and sending posts revises with a new submission id', async ({ page }) => {
  const { mock } = await startOnFormWithMocks(page);
  await fixFromDetail(page);

  await expect(page.locator('.ems-revision-banner')).toContainText('Correcting MIR-000004.');
  await expect(page.locator('.ems-revision-banner')).toContainText(`Captain 5 said: ${REASON}`);
  await expect(page.getByRole('radio', { name: 'RESCUE R2', exact: true })).toBeChecked();
  await expect(await glovesInput(page)).toHaveValue('3');
  await expect(page.locator('#ems-note')).toHaveValue(NOTE);
  const chooser = page.getByRole('navigation', { name: 'Choose a list' });
  await expect(chooser.getByRole('button', { name: /ALS Med Bag/ })).toContainText('1 needed');

  // The item that is no longer on the form is kept, named, and not part of what is sent.
  await expect(page.getByText('1 saved entry is for items that are no longer on this form')).toBeVisible();

  await (await glovesInput(page)).fill('4');
  const sheet = await review(page);
  await expect(sheet).toContainText('This corrected form replaces MIR-000004');
  await expect(sheet.getByRole('region', { name: 'Saved entries for items no longer on the form' })).toContainText('Old Item');
  await expect(sheet.locator('.ems-review-list li', { hasText: 'Gloves' })).toContainText('4 needed');
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();

  await expect(page.getByRole('heading', { name: 'Corrected form sent' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('This replaces MIR-000004.')).toBeVisible();
  expect(mock.posts).toHaveLength(1);
  const sent = mock.posts[0];
  expect(sent.revises).toBe(RETURNED_ID);
  expect(sent.client_submission_id).toMatch(/^[0-9a-f-]{36}$/);
  expect(sent.client_submission_id).not.toBe(RETURNED_ID);
  expect(sent.target.kind).toBe('rescue_unit');
  expect(sent.member_note).toBe(NOTE);
  expect(sent.lines).toEqual(expect.arrayContaining([
    { form: 'airway-bag', item_key: 'airway.top.gloves', quantity: 4 },
    { form: 'als-med-bag', item_key: 'als.trauma.asherman-chest-seal', quantity: 2 },
  ]));
  expect(JSON.stringify(sent.lines)).not.toContain('old.item');
});

test('the member\'s own saved request is untouched while fixing, and is still there afterwards', async ({ page }) => {
  const { mock, owner } = await startOnFormWithMocks(page);

  // A half-typed fresh request, saved on this device.
  await (await glovesInput(page)).fill('6');
  await page.getByRole('radio', { name: 'RESCUE R2', exact: true }).click();
  await page.waitForTimeout(700);
  const before = await storedDraft(page, owner);
  expect(before?.quantities?.[GLOVES]).toBe(6);

  await fixFromDetail(page);
  await expect(await glovesInput(page)).toHaveValue('3'); // the correction, not the fresh request
  await (await glovesInput(page)).fill('5');
  await page.waitForTimeout(700);

  const normal = await storedDraft(page, owner);
  expect(normal?.quantities?.[GLOVES], 'the normal draft is not overwritten').toBe(6);
  expect(normal?.revises).toBeUndefined();
  const correction = await storedDraft(page, `${owner}:revise:${RETURNED_ID}`);
  expect(correction?.v).toBe(1);
  expect(correction?.revises).toBe(RETURNED_ID);
  expect(correction?.quantities?.[GLOVES]).toBe(5);
  expect(correction?.quantities?.[ASHERMAN]).toBe(2);
  expect(correction?.retired?.quantities?.[RETIRED]).toBe(4);

  const sheet = await review(page);
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();
  await expect(page.getByRole('heading', { name: 'Corrected form sent' })).toBeVisible({ timeout: 30_000 });
  expect(mock.posts).toHaveLength(1);
  expect(await storedDraft(page, `${owner}:revise:${RETURNED_ID}`)).toBeNull();

  // A new request opens the member's own saved request again.
  await page.getByRole('button', { name: 'New request' }).click();
  await expect(page.getByRole('heading', { name: 'Medical Inventory Request', level: 1 })).toBeVisible();
  await expect(page.locator('.ems-revision-banner')).toHaveCount(0);
  await expect(page.getByText('Your unfinished request was restored')).toBeVisible();
  await expect(await glovesInput(page)).toHaveValue('6');
  expect((await storedDraft(page, owner))?.quantities?.[GLOVES]).toBe(6);
});

test('a refresh in the middle of a fix restores the correction, with the same submission id, not the sent copy', async ({ page }) => {
  const { mock, owner } = await startOnFormWithMocks(page);
  await fixFromDetail(page);
  await (await glovesInput(page)).fill('7');
  await page.waitForTimeout(700);
  const key = `${owner}:revise:${RETURNED_ID}`;
  const before = await storedDraft(page, key);
  expect(before?.quantities?.[GLOVES]).toBe(7);

  await page.reload();
  await expect(page.locator('.ems-revision-banner')).toContainText('Correcting MIR-000004.', { timeout: 30_000 });
  await expect(page.getByText('Your correction was restored from this device')).toBeVisible();
  await expect(await glovesInput(page)).toHaveValue('7');
  await expect(page.getByRole('radio', { name: 'RESCUE R2', exact: true })).toBeChecked();

  const sheet = await review(page);
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();
  await expect(page.getByRole('heading', { name: 'Corrected form sent' })).toBeVisible({ timeout: 30_000 });
  expect(mock.posts[0].client_submission_id).toBe(before?.clientSubmissionId);
  expect(mock.posts[0].lines).toContainEqual({ form: 'airway-bag', item_key: 'airway.top.gloves', quantity: 7 });
});

test('sent offline, the correction waits on the device and goes out exactly once when the connection returns', async ({ page }) => {
  const { mock, owner } = await startOnFormWithMocks(page);
  await fixFromDetail(page);
  mock.setMode('offline');

  const sheet = await review(page);
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();
  await expect(page.getByRole('heading', { name: 'Saved on this device' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('When it goes through, it replaces MIR-000004.')).toBeVisible();
  await expect(page.getByText('Waiting to send the corrected form')).toBeVisible();
  expect(await storedDraft(page, `${owner}:revise:${RETURNED_ID}`), 'it now lives in the queue, not in the working copy').toBeNull();
  const queuedId = mock.posts[0].client_submission_id;
  expect(mock.posts[0].revises).toBe(RETURNED_ID);

  mock.setMode('ok');
  await page.getByRole('button', { name: 'Try sending now' }).click();
  await expect(page.getByText('MIR-000004-R2 was sent')).toBeVisible({ timeout: 30_000 });
  await page.waitForTimeout(1500);
  expect(mock.succeeded).toHaveLength(1);
  expect(mock.succeeded[0].client_submission_id).toBe(queuedId);
  expect(mock.succeeded[0].revises).toBe(RETURNED_ID);
});

test('a send that comes back already replayed counts as sent', async ({ page }) => {
  const { mock } = await startOnFormWithMocks(page);
  await fixFromDetail(page);
  mock.setMode('replayed');
  const sheet = await review(page);
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();
  await expect(page.getByRole('heading', { name: 'Corrected form sent' })).toBeVisible({ timeout: 30_000 });
});

test('if the form was already corrected or cancelled the member is told, keeps what they typed, and can go to My requests', async ({ page }) => {
  const { mock, owner } = await startOnFormWithMocks(page);
  await fixFromDetail(page);
  await (await glovesInput(page)).fill('8');
  await page.locator('#ems-note').fill('keep this note');
  mock.setMode('cannot_revise');

  const sheet = await review(page);
  await sheet.getByRole('button', { name: 'Send corrected form' }).click();
  const alert = page.locator('.ems-revision-stale');
  await expect(alert).toContainText('This form can no longer be corrected');
  await expect(alert).toContainText('already corrected or cancelled');
  await expect(page.getByRole('dialog', { name: 'Review your request' })).toHaveCount(0);
  await expect(await glovesInput(page)).toHaveValue('8');
  await expect(page.locator('#ems-note')).toHaveValue('keep this note');
  await page.waitForTimeout(700);
  expect((await storedDraft(page, `${owner}:revise:${RETURNED_ID}`))?.quantities?.[GLOVES], 'still saved on the device').toBe(8);

  await alert.getByRole('button', { name: 'Go to My medical requests' }).click();
  await expect(page.getByRole('heading', { name: 'My medical requests', level: 1 })).toBeVisible();
});

test('discarding a correction removes only the correction; the returned form can be fixed again from the sent copy', async ({ page }) => {
  const { owner } = await startOnFormWithMocks(page);
  await (await glovesInput(page)).fill('2');
  await page.waitForTimeout(700);
  await fixFromDetail(page);
  await (await glovesInput(page)).fill('9');
  await page.waitForTimeout(700);

  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByRole('button', { name: 'Discard my changes' }).click();
  await expect(page.getByRole('heading', { name: 'My medical requests', level: 1 })).toBeVisible();
  expect(await storedDraft(page, `${owner}:revise:${RETURNED_ID}`)).toBeNull();
  expect((await storedDraft(page, owner))?.quantities?.[GLOVES]).toBe(2);

  await page.getByRole('button', { name: /MIR-000004, Returned/ }).locator('xpath=..').getByRole('button', { name: 'Fix and resend' }).click();
  await expect(await glovesInput(page)).toHaveValue('3');
});
