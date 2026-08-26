import { expect, test, type Page } from '@playwright/test';

const apparatus = {
  id: 101,
  name: 'Engine 1',
  designation: 'E1',
  type: 'engine',
  vehicle_number: 'E1',
  slug: 'engine-1',
  status: 'In Service',
  daily_checkout_requirement: 'required',
  current_engine_hours: 100,
  current_miles: 1_000,
};

const checklist = {
  checklist: {
    compartments: [
      {
        id: 'cab',
        name: 'Cab',
        items: [
          { id: 'portable-radio', name: 'Portable Radio', status: 'Present', notes: '' },
        ],
      },
    ],
  },
};

interface QueuedInspection {
  id: string;
  apparatusId: number;
  status: 'pending' | 'requires_attention';
  retryCount: number;
  lastError?: string;
  lastErrorStatus?: number;
  retentionExpiresAt?: string;
  data: {
    client_submission_id?: unknown;
    operator_name?: unknown;
    compartments?: unknown;
  };
}

interface InspectionApiMock {
  readonly submissions: Array<Record<string, unknown>>;
}

async function mockInspectionApi(
  page: Page,
  options: { readonly submitStatus?: number; readonly abortFirstSubmit?: boolean } = {},
): Promise<InspectionApiMock> {
  const submissions: Array<Record<string, unknown>> = [];
  const submitStatus = options.submitStatus ?? 201;
  let abortNextSubmit = options.abortFirstSubmit ?? false;

  await page.route('**/images/mbfd_logo_new.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/public/apparatuses') {
      return route.fulfill({ json: [apparatus] });
    }

    if (path === '/api/public/employees/list') {
      return route.fulfill({ json: [{ id: 41, name: 'Captain Browser', rank: 'Captain' }] });
    }

    if (path === `/api/public/apparatuses/${apparatus.id}/checklist`) {
      return route.fulfill({ json: checklist });
    }

    if (path === `/api/public/apparatuses/${apparatus.id}/service-notices`) {
      return route.fulfill({ json: { data: [] } });
    }

    if (path === `/api/public/apparatuses/${apparatus.id}/inspections` && request.method() === 'POST') {
      submissions.push(request.postDataJSON() as Record<string, unknown>);

      if (abortNextSubmit) {
        abortNextSubmit = false;

        return route.abort('connectionreset');
      }

      return route.fulfill({
        status: submitStatus,
        json: submitStatus >= 400
          ? { message: 'Retry later.' }
          : { success: true, message: 'Inspection recorded.' },
      });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  return { submissions };
}

async function completeInspection(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Engine 1' })).toBeVisible();

  const fullName = page.getByLabel('Full Name');
  if (await fullName.isVisible().catch(() => false)) {
    await fullName.fill('Captain Browser');
    await page.getByText('Captain Browser', { exact: true }).click();
    await expect(page.getByText('Selected: Captain Browser')).toBeVisible();
    await page.getByRole('button', { name: 'Continue to Inspection' }).click();
  }

  await expect(page.getByRole('heading', { name: 'Meter Readings' })).toBeVisible();
  await page.getByRole('button', { name: 'Continue', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Compartment Inspection' })).toBeVisible();
  await expect(page.getByText('Portable Radio', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Mark all items in this compartment as present' }).click();
  await page.getByRole('button', { name: 'Review & Submit' }).click();

  await expect(page.getByRole('heading', { name: 'Review & Submit Inspection' })).toBeVisible();
  await drawInspectionSignature(page);
}

async function drawInspectionSignature(page: Page): Promise<void> {
  const canvas = page.locator('canvas');
  await expect(canvas).toHaveCount(1);

  const box = await canvas.boundingBox();
  if (!box) {
    throw new Error('The inspection signature canvas is not visible.');
  }

  const startX = box.x + 24;
  const startY = box.y + box.height / 2;
  await page.mouse.move(startX, startY);
  await page.mouse.down();
  for (let step = 1; step <= 8; step += 1) {
    await page.mouse.move(startX + ((box.width - 48) * step) / 8, startY + step);
  }
  await page.mouse.up();
}

async function queuedInspections(page: Page): Promise<QueuedInspection[]> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });

    if (!database.objectStoreNames.contains('dailyCheckoutSubmissions')) {
      database.close();
      return [];
    }

    const rows = await new Promise<QueuedInspection[]>((resolve, reject) => {
      const transaction = database.transaction('dailyCheckoutSubmissions', 'readonly');
      const request = transaction.objectStore('dailyCheckoutSubmissions').getAll();
      request.onsuccess = () => resolve(request.result as QueuedInspection[]);
      request.onerror = () => reject(request.error);
    });

    database.close();

    return rows;
  });
}

test('non-empty checklist permits a complete inspection and sends its submission', async ({ page }) => {
  const api = await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Submitted!' })).toBeVisible();
  expect(api.submissions).toHaveLength(1);
  expect(api.submissions[0]).toMatchObject({
    client_submission_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
    operator_name: 'Captain Browser',
    compartments: [
      {
        id: 'cab',
        items: [
          { id: 'portable-radio', status: 'Present' },
        ],
      },
    ],
  });
});

test('queued inspection syncs after reconnect while the queued success page remains mounted', async ({ page, context }) => {
  const api = await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  const queuedBeforeRetry = await queuedInspections(page);
  expect(queuedBeforeRetry).toHaveLength(1);
  expect(queuedBeforeRetry[0]).toMatchObject({
    apparatusId: apparatus.id,
    data: {
      client_submission_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
      operator_name: 'Captain Browser',
      compartments: expect.any(Array),
    },
  });

  const clientSubmissionId = queuedBeforeRetry[0].data.client_submission_id;
  expect(typeof clientSubmissionId).toBe('string');

  await context.setOffline(false);
  await expect.poll(() => api.submissions.length).toBe(1);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);

  expect(api.submissions.map((submission) => submission.client_submission_id)).toEqual([
    clientSubmissionId,
  ]);
});

test('reload replays an ambiguous submission with its original durable client id', async ({ page }) => {
  const api = await mockInspectionApi(page, { abortFirstSubmit: true });

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);
  const queuedBeforeReload = await queuedInspections(page);
  const clientSubmissionId = queuedBeforeReload[0].data.client_submission_id;
  expect(typeof clientSubmissionId).toBe('string');

  await page.reload();
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Engine 1' })).toBeVisible();
  await expect.poll(() => api.submissions.length).toBe(2);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);

  expect(api.submissions.map((submission) => submission.client_submission_id)).toEqual([
    clientSubmissionId,
    clientSubmissionId,
  ]);
});

test('a validation 422 retains the payload and durable client id for review', async ({ page }) => {
  const api = await mockInspectionApi(page, { submitStatus: 422 });

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);
  await expect.poll(() => api.submissions.length).toBe(1);
  await expect.poll(async () => (await queuedInspections(page))[0]?.status).toBe('requires_attention');
  const retainedSubmissionAlert = page.getByRole('alert').filter({ hasText: 'remains saved on this device' });
  await expect(retainedSubmissionAlert).toContainText('Retry later.');
  const queuedAfterValidationFailure = await queuedInspections(page);

  expect(api.submissions).toHaveLength(1);
  expect(queuedAfterValidationFailure[0]).toMatchObject({
    apparatusId: apparatus.id,
    status: 'requires_attention',
    retryCount: 1,
    lastErrorStatus: 422,
    lastError: 'Retry later.',
    data: {
      client_submission_id: api.submissions[0].client_submission_id,
      operator_name: 'Captain Browser',
      compartments: expect.any(Array),
    },
  });
  expect(queuedAfterValidationFailure[0].retentionExpiresAt).toBeTruthy();
});

test('migrates a legacy localStorage inspection only after IndexedDB retains it', async ({ page }) => {
  const legacyInspection = {
    id: 'legacy-inspection-1',
    apparatusId: apparatus.id,
    data: {
      client_submission_id: 'f14a1b91-2aa1-43ba-bc4d-80e610b45d7e',
      operator_name: 'Captain Browser',
      rank: 'Captain',
      shift: 'A',
      unit_number: 'E1',
      defects: [],
      compartments: [],
      officer_signature: null,
    },
    timestamp: Date.now(),
  };

  await page.addInitScript((submission) => {
    window.localStorage.setItem('mbfd_submission_queue', JSON.stringify([submission]));
  }, legacyInspection);
  await mockInspectionApi(page, { abortFirstSubmit: true });
  await page.goto('/daily/apparatus/engine-1');

  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);
  const queuedAfterMigration = await queuedInspections(page);

  expect(queuedAfterMigration[0]).toMatchObject({
    id: legacyInspection.id,
    apparatusId: apparatus.id,
    data: {
      client_submission_id: legacyInspection.data.client_submission_id,
    },
  });
  await expect.poll(() => page.evaluate(() => window.localStorage.getItem('mbfd_submission_queue'))).toBeNull();
});
