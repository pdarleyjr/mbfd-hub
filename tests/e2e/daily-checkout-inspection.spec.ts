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
  checklist_version: 'a'.repeat(64),
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
  checklistVersion?: string;
  createdAt?: string;
  updatedAt?: string;
  status: 'pending' | 'requires_attention';
  retryCount: number;
  lastError?: string;
  lastErrorStatus?: number;
  lastErrorCode?: string;
  retentionExpiresAt?: string;
  data: {
    client_submission_id?: unknown;
    checklist_version?: unknown;
    operator_name?: unknown;
    compartments?: unknown;
  };
}

type SeededQueuedInspection = QueuedInspection & {
  createdAt: string;
  updatedAt: string;
};

interface InspectionApiMock {
  readonly submissions: Array<Record<string, unknown>>;
}

async function mockInspectionApi(
  page: Page,
  options: {
    readonly submitStatus?: number;
    readonly abortFirstSubmit?: boolean;
    readonly checklistVersionMismatchOnSubmit?: boolean;
    readonly omitChecklistVersion?: boolean;
    readonly checklistVersion?: string;
    readonly reviewPendingOnSubmit?: boolean;
  } = {},
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
      return route.fulfill({
        json: options.omitChecklistVersion
          ? { checklist: checklist.checklist }
          : { ...checklist, checklist_version: options.checklistVersion ?? checklist.checklist_version },
      });
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
        status: options.checklistVersionMismatchOnSubmit ? 409 : submitStatus,
        json: options.checklistVersionMismatchOnSubmit
          ? {
              message: 'The checklist changed after this inspection was saved. Officer review is required.',
              code: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
              current_checklist_version: 'b'.repeat(64),
            }
          : submitStatus >= 400
          ? { message: 'Retry later.' }
          : {
              success: true,
              message: 'Inspection recorded.',
              review_status: options.reviewPendingOnSubmit ? 'pending_review' : 'approved',
            },
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

async function addQueuedInspection(page: Page, record: SeededQueuedInspection): Promise<void> {
  await page.evaluate(async (queuedRecord) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });

    const transaction = database.transaction('dailyCheckoutSubmissions', 'readwrite');
    const request = transaction.objectStore('dailyCheckoutSubmissions').add({
      ...queuedRecord,
      createdAt: new Date(queuedRecord.createdAt),
      updatedAt: new Date(queuedRecord.updatedAt),
    });
    await new Promise<void>((resolve, reject) => {
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
    database.close();
  }, record);
}

async function createVersionThreeQueue(page: Page, record: Omit<SeededQueuedInspection, 'checklistVersion'>): Promise<void> {
  await page.goto('/images/mbfd_logo_new.png');
  await page.evaluate(async (queuedRecord) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      // Dexie stores semantic schema version 3 as native IndexedDB version 30.
      const request = indexedDB.open('mbfd-daily-checkout', 30);
      request.onupgradeneeded = () => {
        const legacyDatabase = request.result;
        const pendingSubmissions = legacyDatabase.createObjectStore('pendingSubmissions', { keyPath: 'id', autoIncrement: true });
        pendingSubmissions.createIndex('type', 'type');
        pendingSubmissions.createIndex('status', 'status');
        pendingSubmissions.createIndex('createdAt', 'createdAt');
        pendingSubmissions.createIndex('retryCount', 'retryCount');

        const cachedData = legacyDatabase.createObjectStore('cachedData', { keyPath: 'key' });
        cachedData.createIndex('updatedAt', 'updatedAt');

        const trtCatalog = legacyDatabase.createObjectStore('trtCatalog', { keyPath: 'id' });
        trtCatalog.createIndex('category', 'category');
        trtCatalog.createIndex('sort_order', 'sort_order');

        const dailyQueue = legacyDatabase.createObjectStore('dailyCheckoutSubmissions', { keyPath: 'id' });
        dailyQueue.createIndex('apparatusId', 'apparatusId', { unique: true });
        dailyQueue.createIndex('status', 'status');
        dailyQueue.createIndex('createdAt', 'createdAt');
        dailyQueue.createIndex('updatedAt', 'updatedAt');
        dailyQueue.createIndex('retentionExpiresAt', 'retentionExpiresAt');
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });

    const transaction = database.transaction('dailyCheckoutSubmissions', 'readwrite');
    transaction.objectStore('dailyCheckoutSubmissions').add({
      ...queuedRecord,
      createdAt: new Date(queuedRecord.createdAt),
      updatedAt: new Date(queuedRecord.updatedAt),
    });
    await new Promise<void>((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onabort = () => reject(transaction.error);
      transaction.onerror = () => reject(transaction.error);
    });
    database.close();
  }, record);
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
    checklist_version: checklist.checklist_version,
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

test('a pending-review receipt tells the operator that readiness is not yet changed', async ({ page }) => {
  await mockInspectionApi(page, { reviewPendingOnSubmit: true });

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Submitted for Review!' })).toBeVisible();
  await expect(page.getByText('before it changes readiness, defects, or meter records.')).toBeVisible();
});

test('a checklist without an immutable version fails closed before inspection entry', async ({ page }) => {
  const api = await mockInspectionApi(page, { omitChecklistVersion: true });

  await page.goto('/daily/apparatus/engine-1');

  await expect(page.getByText('Inspection Data Unavailable', { exact: true })).toBeVisible();
  await expect(page.getByText('The Daily Checkout checklist version is unavailable. Contact an officer before continuing.')).toBeVisible();
  expect(api.submissions).toHaveLength(0);
});

test('upgrades a version-three queue record without losing its immutable checklist version', async ({ page }) => {
  const legacyVersion = 'a'.repeat(64);
  await createVersionThreeQueue(page, {
    id: 'daily-legacy-version-three',
    apparatusId: apparatus.id,
    status: 'requires_attention',
    retryCount: 1,
    lastError: 'The checklist changed after this inspection was saved. Officer review is required.',
    lastErrorStatus: 409,
    lastErrorCode: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
    data: {
      client_submission_id: 'abababab-abab-4bab-8bab-abababababab',
      checklist_version: legacyVersion,
      operator_name: 'Captain Previous',
      compartments: [],
    },
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });
  expect(await queuedInspections(page)).toHaveLength(1);
  await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');

  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);
  const saved = await queuedInspections(page);
  expect(saved).toHaveLength(1);
  expect(saved[0]).toMatchObject({
    checklistVersion: legacyVersion,
    data: { checklist_version: legacyVersion },
  });
  await expect(page.getByText('A saved Daily Checkout using this checklist version needs officer review.')).toBeVisible();
});

test('a changed checklist preserves the older autosave while a current-version draft is created separately', async ({ page }) => {
  const staleAutosave = {
    checklist_version: 'b'.repeat(64),
    officer: { name: 'Captain Previous', rank: 'Captain', shift: 'A', unitNumber: 'E1' },
    meter: { engine_hours: 99, miles: 999 },
    compartments: [{ id: 'cab', name: 'Cab', items: [{ id: 'portable-radio', name: 'Portable Radio', status: 'Missing' }] }],
    timestamp: Date.now(),
  };
  const legacyAutosaveKey = 'mbfd_autosave_inspection_engine-1';
  await page.addInitScript(({ key, data }) => {
    window.localStorage.setItem(key, JSON.stringify(data));
  }, { key: legacyAutosaveKey, data: staleAutosave });
  await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await expect(page.getByRole('alert')).toContainText('A previously saved inspection uses a different checklist version');
  await completeInspection(page);

  const persisted = await page.evaluate(({ legacyKey, currentKey }) => ({
    legacy: JSON.parse(window.localStorage.getItem(legacyKey) ?? 'null'),
    current: JSON.parse(window.localStorage.getItem(currentKey) ?? 'null'),
  }), {
    legacyKey: legacyAutosaveKey,
    currentKey: `${legacyAutosaveKey}_${checklist.checklist_version}`,
  });

  expect(persisted.legacy).toMatchObject({
    checklist_version: staleAutosave.checklist_version,
    officer: { name: 'Captain Previous' },
    compartments: [{ items: [{ status: 'Missing' }] }],
  });
  expect(persisted.current).toMatchObject({
    checklist_version: checklist.checklist_version,
    officer: { name: 'Captain Browser' },
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
      checklist_version: checklist.checklist_version,
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

test('queued inspection reports pending review after reconnect without retaining a duplicate queue item', async ({ page, context }) => {
  const api = await mockInspectionApi(page, { reviewPendingOnSubmit: true });

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);

  await context.setOffline(false);
  await expect.poll(() => api.submissions.length).toBe(1);
  await expect(page.getByRole('heading', { name: 'Inspection Submitted for Review!' })).toBeVisible();
  await expect(page.getByText('before it changes readiness, defects, or meter records.')).toBeVisible();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);
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

test('an offline checklist version is retained for review when the server changes to a new version', async ({ page, context }) => {
  const api = await mockInspectionApi(page, { checklistVersionMismatchOnSubmit: true });

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  const queuedBeforeReconnect = await queuedInspections(page);
  expect(queuedBeforeReconnect).toHaveLength(1);
  const savedClientSubmissionId = queuedBeforeReconnect[0].data.client_submission_id;
  expect(queuedBeforeReconnect[0].data.checklist_version).toBe(checklist.checklist_version);

  await context.setOffline(false);
  await expect.poll(() => api.submissions.length).toBe(1);
  await expect.poll(async () => (await queuedInspections(page))[0]?.status).toBe('requires_attention');
  const retainedSubmission = (await queuedInspections(page))[0];

  expect(retainedSubmission).toMatchObject({
    apparatusId: apparatus.id,
    status: 'requires_attention',
    retryCount: 1,
    lastErrorStatus: 409,
    lastErrorCode: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
    data: {
      client_submission_id: savedClientSubmissionId,
      checklist_version: checklist.checklist_version,
    },
  });
  await expect(page.getByRole('alert')).toContainText('The checklist changed after this inspection was saved');
  expect(api.submissions[0]).toMatchObject({
    client_submission_id: savedClientSubmissionId,
    checklist_version: checklist.checklist_version,
  });
});

test('a current checklist submission is queued separately while an older version remains retained for officer review', async ({ page, context }) => {
  const olderVersion = 'a'.repeat(64);
  const currentVersion = 'b'.repeat(64);
  const olderClientSubmissionId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  const api = await mockInspectionApi(page, { checklistVersion: currentVersion });

  await page.goto('/daily/apparatus/engine-1');
  await addQueuedInspection(page, {
    id: `daily_${olderClientSubmissionId}`,
    apparatusId: apparatus.id,
    checklistVersion: olderVersion,
    status: 'requires_attention',
    retryCount: 1,
    lastError: 'The checklist changed after this inspection was saved. Officer review is required.',
    lastErrorStatus: 409,
    lastErrorCode: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
    data: {
      client_submission_id: olderClientSubmissionId,
      checklist_version: olderVersion,
      operator_name: 'Captain Previous',
      compartments: [],
    },
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });

  await context.setOffline(true);
  await completeInspection(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  const saved = await queuedInspections(page);
  expect(saved).toHaveLength(2);

  const older = saved.find((submission) => submission.checklistVersion === olderVersion);
  const current = saved.find((submission) => submission.checklistVersion === currentVersion);
  expect(older).toMatchObject({
    status: 'requires_attention',
    data: { client_submission_id: olderClientSubmissionId, checklist_version: olderVersion },
  });
  expect(current).toMatchObject({
    status: 'pending',
    apparatusId: apparatus.id,
    data: {
      client_submission_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
      checklist_version: currentVersion,
      operator_name: 'Captain Browser',
    },
  });
  expect(current?.data.client_submission_id).not.toBe(olderClientSubmissionId);
  expect(api.submissions).toHaveLength(0);
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
    status: 'requires_attention',
    lastErrorCode: 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED',
  });
  await expect.poll(() => page.evaluate(() => window.localStorage.getItem('mbfd_submission_queue'))).toBeNull();
});
