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

const stationDetail = {
  id: 1,
  name: 'Station 1',
  station_number: 1,
  address: '1 Test Street',
  city: 'Miami Beach',
  state: 'FL',
  zip_code: '33139',
  phone: '',
  is_active: true,
  apparatuses: [
    apparatus,
    { ...apparatus, id: 102, name: 'Engine 2', designation: 'E2', vehicle_number: 'E2', slug: 'engine-2' },
    { ...apparatus, id: 103, name: 'Rescue 1', designation: 'R1', vehicle_number: 'R1', slug: 'rescue-1' },
    { ...apparatus, id: 104, name: 'Ladder 1', designation: 'L1', vehicle_number: 'L1', slug: 'ladder-1' },
    { ...apparatus, id: 105, name: 'Engine 5', designation: 'E5', vehicle_number: 'E5', slug: 'engine-5', status: 'Out of Service' },
  ],
  daily_checkout: {
    required_total: 4,
    checked: 1,
    attention: 1,
    review_pending: 1,
    not_checked: 1,
    completed: 2,
    out_of_service: 1,
    exempt: 0,
    classification_required: 0,
    completion_percent: 50,
    completion_available: true,
    matrix: [
      {
        apparatus_id: 101,
        state: 'checked',
        daily_checkout_requirement: 'required',
        out_of_service: false,
        classification_required: false,
        included_in_required_total: true,
        included_in_completed: true,
        has_pending_submission: false,
        return_checkout_required: false,
        return_checkout_verified: false,
      },
      {
        apparatus_id: 102,
        state: 'attention',
        daily_checkout_requirement: 'required',
        out_of_service: false,
        classification_required: false,
        included_in_required_total: true,
        included_in_completed: true,
        has_pending_submission: false,
        return_checkout_required: false,
        return_checkout_verified: false,
      },
      {
        apparatus_id: 103,
        state: 'review_pending',
        daily_checkout_requirement: 'required',
        out_of_service: false,
        classification_required: false,
        included_in_required_total: true,
        included_in_completed: false,
        has_pending_submission: true,
        return_checkout_required: false,
        return_checkout_verified: false,
      },
      {
        apparatus_id: 104,
        state: 'not_checked',
        daily_checkout_requirement: 'required',
        out_of_service: false,
        classification_required: false,
        included_in_required_total: true,
        included_in_completed: false,
        has_pending_submission: false,
        return_checkout_required: false,
        return_checkout_verified: false,
      },
      {
        apparatus_id: 105,
        state: 'out_of_service',
        daily_checkout_requirement: 'required',
        out_of_service: true,
        classification_required: false,
        included_in_required_total: false,
        included_in_completed: false,
        has_pending_submission: false,
        return_checkout_required: false,
        return_checkout_verified: false,
      },
    ],
  },
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

const fireBoatApparatus = {
  id: 106,
  name: 'Fire Boat 6',
  designation: 'FB6',
  type: 'fireboat',
  vehicle_number: 'FB6',
  slug: 'fire-boat-6',
  status: 'In Service',
  daily_checkout_requirement: 'required',
};

const fireBoatChecklist = {
  inspection_date: '2026-08-31',
  checklist_version: 'c'.repeat(64),
  due_tasks: [
    { id: 'fb6-monday-fuel-tank-hold', name: 'Fuel Tank Hold', recurrence: { type: 'weekday', weekday: 'monday' } },
  ],
  checklist: {
    schema_version: 2,
    template_id: 'fire_boat_6_daily',
    template_version: '2026-07',
    inspectionDateFieldId: 'inspection_date',
    fields: [
      { id: 'inspection_date', name: 'Date', inputType: 'date', required: true },
      { id: 'fb6-high-low-tide', name: 'High Low Tide', inputType: 'text', required: true },
      { id: 'fb6-port-engine-hours', name: 'Port Engine Hours', inputType: 'number', required: true },
    ],
    recurringTasks: [
      { id: 'fb6-monday-fuel-tank-hold', name: 'Fuel Tank Hold', recurrence: { type: 'weekday', weekday: 'monday' } },
      { id: 'fb6-monthly-first-day', name: 'First Day of Each Month', recurrence: { type: 'monthly_day', day: 1 } },
    ],
    compartments: [
      {
        id: 'fb6-cubby',
        name: 'Cubby',
        items: [
          { id: 'fb6-cubby-flashlights', name: 'Flashlights', inputType: 'checkbox', expectedQuantity: 3 },
        ],
      },
    ],
  },
};

const fireBoatInspectionSession = {
  id: '11111111-2222-4333-8444-555555555555',
  token: 'd'.repeat(64),
  issued_at: '2026-08-31T09:00:00-04:00',
  expires_at: '2026-08-31T21:00:00-04:00',
  duty_date: fireBoatChecklist.inspection_date,
  checklist_template_id: fireBoatChecklist.checklist.template_id,
  checklist_template_version: fireBoatChecklist.checklist.template_version,
  checklist_hash: fireBoatChecklist.checklist_version,
  due_tasks: fireBoatChecklist.due_tasks,
  due_tasks_hash: 'e'.repeat(64),
  replay_key: '66666666-7777-4888-8999-aaaaaaaaaaaa',
};

const fireBoatPriorDayChecklist = {
  ...fireBoatChecklist,
  inspection_date: '2026-08-30',
  due_tasks: [],
};

const fireBoatPriorDayInspectionSession = {
  ...fireBoatInspectionSession,
  id: '22222222-3333-4444-8555-666666666666',
  issued_at: '2026-08-30T23:55:00-04:00',
  expires_at: '2026-08-31T11:55:00-04:00',
  duty_date: fireBoatPriorDayChecklist.inspection_date,
  due_tasks: fireBoatPriorDayChecklist.due_tasks,
  replay_key: '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
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
  ownerUserId?: number;
  ownerSecurityVersion?: number;
  ownershipState?: 'owned' | 'legacy_unclaimed' | 'identity_mismatch' | 'security_mismatch';
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

interface GenericQueuedSubmission {
  id?: number;
  type: string;
  data: Record<string, unknown>;
  createdAt: string;
  status: 'pending' | 'processing' | 'failed' | 'requires_attention';
  retryCount: number;
  ownerUserId?: number;
  ownerSecurityVersion?: number;
  ownershipState?: 'owned' | 'legacy_unclaimed' | 'identity_mismatch' | 'security_mismatch';
  lastError?: string;
  lastErrorCode?: string;
}

interface InspectionApiMock {
  readonly submissions: Array<Record<string, unknown>>;
  readonly genericSubmissions: Array<Record<string, unknown>>;
  readonly sessionStarts: Array<Record<string, unknown>>;
  readonly abandonments: Array<Record<string, unknown>>;
  setIdentity(userId: number, securityVersion: number): void;
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
    readonly stationDailyCheckout?: 'canonical' | 'unavailable';
    readonly stationNumber?: string;
  } = {},
): Promise<InspectionApiMock> {
  const submissions: Array<Record<string, unknown>> = [];
  const genericSubmissions: Array<Record<string, unknown>> = [];
  const sessionStarts: Array<Record<string, unknown>> = [];
  const abandonments: Array<Record<string, unknown>> = [];
  const submitStatus = options.submitStatus ?? 201;
  let abortNextSubmit = options.abortFirstSubmit ?? false;
  let currentIdentity = { userId: 101, securityVersion: 1 };

  await page.route('**/images/mbfd_logo_new.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: currentIdentity.userId, has_personnel_profile: true },
          personnel: { employee_profile_id: currentIdentity.userId + 1_000, employee_number: `E${currentIdentity.userId}`, name: 'Captain Browser', rank: 'Captain' },
          offline: { security_version: currentIdentity.securityVersion },
          session: { authenticated: true },
        },
      });
    }

    if (path === '/api/public/apparatuses') {
      return route.fulfill({ json: [apparatus] });
    }

    if (path === '/api/public/station_request' && request.method() === 'POST') {
      genericSubmissions.push(request.postDataJSON() as Record<string, unknown>);

      return route.fulfill({ status: 201, json: { success: true } });
    }

    if (path === '/api/public/stations/1') {
      const stationWithApiNumber = {
        ...stationDetail,
        station_number: options.stationNumber ?? stationDetail.station_number,
      };

      if (options.stationDailyCheckout === 'unavailable') {
        const { daily_checkout: _dailyCheckout, ...stationWithoutDailyCheckout } = stationWithApiNumber;

        return route.fulfill({ json: stationWithoutDailyCheckout });
      }

      return route.fulfill({ json: stationWithApiNumber });
    }

    if (path === '/api/public/stations/1/requests') {
      return route.fulfill({ json: { data: [] } });
    }

    if (path === '/api/public/stations/1/service-tickets') {
      return route.fulfill({ json: { data: [], meta: { total: 0 } } });
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

  return {
    submissions,
    genericSubmissions,
    sessionStarts,
    abandonments,
    setIdentity(userId: number, securityVersion: number) {
      currentIdentity = { userId, securityVersion };
    },
  };
}

async function mockFireBoatInspectionApi(
  page: Page,
  options: {
    readonly sessionStatus?: number;
    readonly abortFirstSessionStart?: boolean;
    readonly recoverPriorDayContract?: boolean;
  } = {},
): Promise<InspectionApiMock> {
  const submissions: Array<Record<string, unknown>> = [];
  const genericSubmissions: Array<Record<string, unknown>> = [];
  const sessionStarts: Array<Record<string, unknown>> = [];
  const abandonments: Array<Record<string, unknown>> = [];
  const sessionStatus = options.sessionStatus ?? 201;
  let abortNextSessionStart = options.abortFirstSessionStart ?? false;
  let priorDayContractWasAbandoned = false;
  let currentIdentity = { userId: 101, securityVersion: 1 };

  await page.route('**/images/mbfd_logo_new.png', (route) => route.fulfill({ path: 'public/images/mbfd_logo_new.png' }));
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if (path === '/api/me/context') {
      return route.fulfill({
        json: {
          version: 1,
          identity: { user_id: currentIdentity.userId, has_personnel_profile: true },
          personnel: { employee_profile_id: currentIdentity.userId + 1_000, employee_number: `E${currentIdentity.userId}`, name: 'Captain Browser', rank: 'Captain' },
          offline: { security_version: currentIdentity.securityVersion },
          session: { authenticated: true },
        },
      });
    }

    if (path === '/api/public/apparatuses') {
      return route.fulfill({ json: [fireBoatApparatus] });
    }

    if (path === '/api/public/employees/list') {
      return route.fulfill({ json: [{ id: 41, name: 'Captain Browser', rank: 'Captain' }] });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/checklist`) {
      return route.fulfill({ json: fireBoatChecklist });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/inspection-sessions/${fireBoatPriorDayInspectionSession.id}/abandon` && request.method() === 'POST') {
      abandonments.push(request.postDataJSON() as Record<string, unknown>);
      priorDayContractWasAbandoned = true;

      return route.fulfill({
        status: 201,
        json: {
          ...fireBoatChecklist,
          inspection_session: fireBoatInspectionSession,
        },
      });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/inspection-sessions` && request.method() === 'POST') {
      sessionStarts.push(request.postDataJSON() as Record<string, unknown>);
      if (abortNextSessionStart) {
        abortNextSessionStart = false;

        return route.abort('connectionreset');
      }
      if (sessionStatus >= 400) {
        return route.fulfill({
          status: sessionStatus,
          json: {
            message: 'A server-issued Fire Boat inspection session is required before this checkout can be submitted.',
            code: 'DAILY_CHECKOUT_INSPECTION_SESSION_REQUIRED',
          },
        });
      }

      const recoversPriorDayContract = options.recoverPriorDayContract === true && !priorDayContractWasAbandoned;

      return route.fulfill({
        status: recoversPriorDayContract ? 200 : 201,
        json: {
          ...(recoversPriorDayContract ? fireBoatPriorDayChecklist : fireBoatChecklist),
          inspection_session: recoversPriorDayContract ? fireBoatPriorDayInspectionSession : fireBoatInspectionSession,
        },
      });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/service-notices`) {
      return route.fulfill({ json: { data: [] } });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/inspections` && request.method() === 'POST') {
      submissions.push(request.postDataJSON() as Record<string, unknown>);

      return route.fulfill({
        status: 201,
        json: {
          success: true,
          review_status: 'pending_review',
        },
      });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  return {
    submissions,
    genericSubmissions,
    sessionStarts,
    abandonments,
    setIdentity(userId: number, securityVersion: number) {
      currentIdentity = { userId, securityVersion };
    },
  };
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

test('station inspection exposes labeled controls, keyboard state buttons, and inline submission errors', async ({ page }) => {
  await mockInspectionApi(page);
  await page.goto('/daily/forms-hub/station-inspection');

  const station = page.getByLabel('Station');
  const inspectionDate = page.getByLabel('Inspection Date');
  await expect(station).toBeVisible();
  await expect(inspectionDate).toBeVisible();
  expect((await station.boundingBox())?.height).toBeGreaterThanOrEqual(44);
  expect((await inspectionDate.boundingBox())?.height).toBeGreaterThanOrEqual(44);

  await station.selectOption('Station 1');
  const next = page.getByRole('button', { name: 'Next' });
  await next.focus();
  await page.keyboard.press('Enter');

  const apparatusDoors = page.getByRole('group', { name: 'Apparatus Doors status' });
  await expect(apparatusDoors).toBeVisible();
  const pass = apparatusDoors.getByRole('button', { name: 'Apparatus Doors: pass' });
  await pass.focus();
  await page.keyboard.press('Enter');
  await expect(pass).toHaveAttribute('aria-pressed', 'true');

  for (const passAll of await page.getByRole('button', { name: 'Pass All' }).all()) {
    await passAll.click();
  }
  await page.getByRole('button', { name: 'Next' }).click();

  await expect(page.getByRole('img', { name: 'Inspector signature' })).toBeVisible();
  await drawInspectionSignature(page);
  await page.getByRole('checkbox', { name: /Saturday SOG Mandate/ }).check();
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('alert')).toContainText('Unmocked API route: /api/public/station_inspection');
});

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

async function addGenericQueuedSubmission(page: Page, record: GenericQueuedSubmission): Promise<void> {
  await page.evaluate(async (queuedRecord) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const transaction = database.transaction('pendingSubmissions', 'readwrite');
    const request = transaction.objectStore('pendingSubmissions').add({
      ...queuedRecord,
      createdAt: new Date(queuedRecord.createdAt),
    });
    await new Promise<void>((resolve, reject) => {
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
    database.close();
  }, record);
}

async function genericQueuedSubmissions(page: Page): Promise<GenericQueuedSubmission[]> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const transaction = database.transaction('pendingSubmissions', 'readonly');
    const request = transaction.objectStore('pendingSubmissions').getAll();
    const rows = await new Promise<GenericQueuedSubmission[]>((resolve, reject) => {
      request.onsuccess = () => resolve(request.result as GenericQueuedSubmission[]);
      request.onerror = () => reject(request.error);
    });
    database.close();

    return rows;
  });
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

test('station Daily Checkout renders the canonical server result without estimating readiness from inspection rows', async ({ page }) => {
  await mockInspectionApi(page);

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('heading', { name: 'Daily Checkout' })).toBeVisible();
  await expect(page.getByText('2 / 4 required inspections completed', { exact: true })).toBeVisible();
  await expect(page.getByText('50%', { exact: true })).toBeVisible();
  await expect(page.getByText('Review pending', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('Out of service', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('A submission is pending review.', { exact: true })).toBeVisible();
});

test('station Daily Checkout is explicitly unavailable when the canonical server result is absent', async ({ page }) => {
  await mockInspectionApi(page, { stationDailyCheckout: 'unavailable' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('heading', { name: 'Daily Checkout' })).toBeVisible();
  await expect(page.getByText('Unavailable', { exact: true })).toBeVisible();
  await expect(page.getByText('The authoritative Daily Checkout result is unavailable. Readiness is not estimated from inspection records.', { exact: true })).toBeVisible();
});

test('a string Station 1 API value renders its conference link', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '1' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('link', { name: 'Morning Lineup Video Conference', exact: true }))
    .toHaveAttribute('href', '/video-conferencing/stations/1');
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

test('a string Station 2 API value renders both authorized conference links', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '2' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('link', { name: 'Morning Lineup Video Conference — Station 2', exact: true }))
    .toHaveAttribute('href', '/video-conferencing/stations/2');
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true }))
    .toHaveAttribute('href', '/employee/video-conferencing/command');
});

test('a string Station 3 API value renders its conference link', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '3' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('link', { name: 'Morning Lineup Video Conference', exact: true }))
    .toHaveAttribute('href', '/video-conferencing/stations/3');
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

test('a string Station 4 API value renders its conference link', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '4' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('link', { name: 'Morning Lineup Video Conference', exact: true }))
    .toHaveAttribute('href', '/video-conferencing/stations/4');
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

test('a string Station 6 API value renders its conference link', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '6' });

  await page.goto('/daily/stations/1');

  await expect(page.getByRole('link', { name: 'Morning Lineup Video Conference', exact: true }))
    .toHaveAttribute('href', '/video-conferencing/stations/6');
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

test('an unsupported Station API value does not render conference links', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: '5' });

  await page.goto('/daily/stations/1');

  await expect(page.locator('a[href^="/video-conferencing/stations/"]')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

test('a malformed Station API value fails closed without conference links', async ({ page }) => {
  await mockInspectionApi(page, { stationNumber: 'not-a-station' });

  await page.goto('/daily/stations/1');

  await expect(page.locator('a[href^="/video-conferencing/stations/"]')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Morning Lineup — 300 Command', exact: true })).toHaveCount(0);
});

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

test('Fire Boat v2 preserves typed field values and submits only server-due recurring duties', async ({ page }) => {
  const api = await mockFireBoatInspectionApi(page);

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  expect(api.sessionStarts).toEqual([
    expect.objectContaining({
      inspection_session_start_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
    }),
  ]);

  await page.getByLabel('Full Name').fill('Captain Browser');
  await page.getByText('Captain Browser', { exact: true }).click();
  await page.getByRole('button', { name: 'Continue to Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Checklist Details' })).toBeVisible();
  await expect(page.getByLabel('Date')).toHaveValue('2026-08-31');
  await page.getByLabel('High Low Tide').fill('High 10:00 / Low 16:30');
  await page.getByLabel('Port Engine Hours').fill('45.5');
  await expect(page.getByText('Fuel Tank Hold', { exact: true })).toBeVisible();
  await expect(page.getByText('First Day of Each Month', { exact: true })).not.toBeVisible();
  await page.getByRole('button', { name: 'Mark all due duties as present' }).click();
  await page.getByRole('button', { name: 'Continue to Compartment Inspection' }).click();

  await expect(page.getByText('Expected quantity: 3', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Mark all items in this compartment as present' }).click();
  await page.getByRole('button', { name: 'Review & Submit' }).click();
  await drawInspectionSignature(page);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Submitted for Review!' })).toBeVisible();
  expect(api.submissions).toHaveLength(1);
  expect(api.submissions[0]).toMatchObject({
    checklist_version: fireBoatChecklist.checklist_version,
    field_values: [
      { id: 'inspection_date', value: '2026-08-31' },
      { id: 'fb6-high-low-tide', value: 'High 10:00 / Low 16:30' },
      { id: 'fb6-port-engine-hours', value: 45.5 },
    ],
    scheduled_tasks: [
      { id: 'fb6-monday-fuel-tank-hold', status: 'Present' },
    ],
    inspection_session_id: fireBoatInspectionSession.id,
    inspection_session_token: fireBoatInspectionSession.token,
    inspection_session_replay_key: fireBoatInspectionSession.replay_key,
    compartments: [
      {
        id: 'fb6-cubby',
        items: [{ id: 'fb6-cubby-flashlights', status: 'Present' }],
      },
    ],
  });
});

test('Fire Boat blocks an unbound offline checkout before inspection entry', async ({ page }) => {
  const api = await mockFireBoatInspectionApi(page, { sessionStatus: 503 });

  await page.goto('/daily/apparatus/fire-boat-6');

  await expect(page.getByText('Inspection Data Unavailable', { exact: true })).toBeVisible();
  await expect(page.getByText('A server-issued Fire Boat inspection session is required before this checkout can be submitted.', { exact: true })).toBeVisible();
  expect(api.submissions).toHaveLength(0);
});

test('Fire Boat retries a lost session-start response with its same local issuance key', async ({ page }) => {
  const api = await mockFireBoatInspectionApi(page, { abortFirstSessionStart: true });

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect(page.getByText('Inspection Data Unavailable', { exact: true })).toBeVisible();
  expect(api.sessionStarts).toHaveLength(1);
  const issuanceKey = api.sessionStarts[0]?.inspection_session_start_key;
  expect(issuanceKey).toEqual(expect.stringMatching(/^[0-9a-f-]{36}$/));

  await page.reload();

  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  expect(api.sessionStarts).toHaveLength(2);
  expect(api.sessionStarts[1]?.inspection_session_start_key).toBe(issuanceKey);
});

test('Fire Boat recovers a valid prior-day contract after local storage loss and starts today only by explicit abandonment', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-08-30T23:55:00-04:00') });
  const api = await mockFireBoatInspectionApi(page, { recoverPriorDayContract: true });
  const autosaveKey = `mbfd_autosave_inspection_fire-boat-6_${fireBoatChecklist.checklist_version}`;

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect.poll(() => page.evaluate((key) => {
    const saved = window.localStorage.getItem(key);

    return saved === null ? null : JSON.parse(saved).inspectionSession?.id;
  }, autosaveKey)).toBe(fireBoatPriorDayInspectionSession.id);

  // Model loss of the local PWA/autosave state at midnight. The mocked start
  // endpoint represents recovery through the still-valid HTTP-only binding.
  await page.evaluate(() => window.localStorage.clear());
  await page.clock.setFixedTime(new Date('2026-08-31T00:05:00-04:00'));
  await page.reload();

  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('A valid Fire Boat inspection from the prior duty date is still active.');
  await expect(page.getByRole('button', { name: 'Abandon Prior Inspection / Start Today’s Inspection' })).toBeVisible();
  expect(api.sessionStarts).toHaveLength(2);

  await page.getByRole('button', { name: 'Abandon Prior Inspection / Start Today’s Inspection' }).click();
  await expect.poll(() => api.abandonments.length).toBe(1);
  expect(api.sessionStarts).toHaveLength(2);

  expect(api.abandonments[0]).toMatchObject({
    inspection_session_token: fireBoatPriorDayInspectionSession.token,
    inspection_session_replay_key: fireBoatPriorDayInspectionSession.replay_key,
    inspection_session_transition_key: expect.stringMatching(/^[0-9a-f-]{36}$/),
  });
  await expect(page.getByRole('button', { name: 'Abandon Prior Inspection / Start Today’s Inspection' })).not.toBeVisible();
});

test('Fire Boat v2 reload restores same-version typed fields, due-duty status, and compartment status', async ({ page }) => {
  const api = await mockFireBoatInspectionApi(page);

  await page.goto('/daily/apparatus/fire-boat-6');
  await page.getByLabel('Full Name').fill('Captain Browser');
  await page.getByText('Captain Browser', { exact: true }).click();
  await page.getByRole('button', { name: 'Continue to Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Checklist Details' })).toBeVisible();
  await page.getByLabel('High Low Tide').fill('High 10:00 / Low 16:30');
  await page.getByLabel('Port Engine Hours').fill('45.5');
  await page.getByRole('group', { name: 'Status for Fuel Tank Hold' })
    .getByRole('button', { name: 'Missing', exact: true })
    .click();
  await page.getByRole('button', { name: 'Continue to Compartment Inspection' }).click();

  await page.getByRole('group', { name: 'Status for Flashlights' })
    .getByRole('button', { name: /Damaged/ })
    .click();
  await page.getByRole('button', { name: 'Review & Submit' }).click();

  await page.reload();

  expect(api.sessionStarts).toHaveLength(1);

  await expect(page.getByText(/Restored from autosave/)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Compartment Inspection' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Status for Flashlights' })
    .getByRole('button', { name: /Damaged/ }))
    .toHaveAttribute('aria-pressed', 'true');

  await page.getByRole('button', { name: 'Back to Checklist Details' }).click();
  await expect(page.getByRole('heading', { name: 'Checklist Details' })).toBeVisible();
  await expect(page.getByLabel('High Low Tide')).toHaveValue('High 10:00 / Low 16:30');
  await expect(page.getByLabel('Port Engine Hours')).toHaveValue('45.5');
  await expect(page.getByRole('group', { name: 'Status for Fuel Tank Hold' })
    .getByRole('button', { name: 'Missing', exact: true }))
    .toHaveAttribute('aria-pressed', 'true');
});

test('Fire Boat v2 reload restores an issued session when the checklist API is offline', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-08-31T09:00:00-04:00') });
  const api = await mockFireBoatInspectionApi(page);

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  await expect.poll(() => page.evaluate((checklistVersion) => {
    const saved = window.localStorage.getItem(`mbfd_autosave_inspection_fire-boat-6_${checklistVersion}`);

    return saved === null ? null : JSON.parse(saved).inspectionSession?.id;
  }, fireBoatChecklist.checklist_version)).toBe(fireBoatInspectionSession.id);

  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
  });
  await page.unroute('**/api/**');
  const offlineApiPaths: string[] = [];
  await page.route('**/api/**', async (route) => {
    offlineApiPaths.push(new URL(route.request().url()).pathname);

    return route.fulfill({ status: 503, json: { message: 'Offline' } });
  });

  await page.reload();

  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  await expect(page.getByText(/Restored from autosave/)).toBeVisible();
  expect(offlineApiPaths).not.toContain('/api/public/apparatuses');
  expect(offlineApiPaths).not.toContain(`/api/public/apparatuses/${fireBoatApparatus.id}/checklist`);
  expect(offlineApiPaths).not.toContain(`/api/public/apparatuses/${fireBoatApparatus.id}/inspection-sessions`);
  expect(api.sessionStarts).toHaveLength(1);
});

test('Fire Boat online reload uses current apparatus and service notices over its local contract snapshot', async ({ page }) => {
  await page.clock.install({ time: new Date('2026-08-31T09:00:00-04:00') });
  await mockFireBoatInspectionApi(page);

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();

  await page.unroute('**/api/**');
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;

    if (path === '/api/public/apparatuses') {
      return route.fulfill({ json: [{ ...fireBoatApparatus, vehicle_number: 'FB6-LIVE', status: 'Out of Service' }] });
    }

    if (path === `/api/public/apparatuses/${fireBoatApparatus.id}/service-notices`) {
      return route.fulfill({
        json: {
          data: [{ id: 901, ticket_number: 'FB6-LIVE-901', title: 'Live Fleet notice', status: 'open' }],
        },
      });
    }

    return route.fulfill({ status: 404, json: { message: `Unmocked API route: ${path}` } });
  });

  await page.reload();

  await expect(page.getByText('Unit: FB6-LIVE', { exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Unit out of service' })).toBeVisible();
  await expect(page.getByText('FB6-LIVE-901 · Live Fleet notice', { exact: true })).toBeVisible();
});

test('Fire Boat v2 reload does not reuse an expired saved session', async ({ page }) => {
  const api = await mockFireBoatInspectionApi(page);
  const autosaveKey = `mbfd_autosave_inspection_fire-boat-6_${fireBoatChecklist.checklist_version}`;

  await page.goto('/daily/apparatus/fire-boat-6');
  await expect.poll(() => page.evaluate((key) => {
    const saved = window.localStorage.getItem(key);

    return saved === null ? null : JSON.parse(saved).inspectionSession?.id;
  }, autosaveKey)).toBe(fireBoatInspectionSession.id);
  await page.evaluate((key) => {
    const saved = JSON.parse(window.localStorage.getItem(key) ?? 'null');
    if (!saved?.inspectionSession) {
      throw new Error('Expected a persisted Fire Boat inspection session.');
    }

    saved.inspectionSession.id = '99999999-9999-4999-8999-999999999999';
    saved.inspectionSession.expires_at = '2000-01-01T00:00:00-05:00';
    saved.fieldValues = saved.fieldValues.map((field: { id: string; value: unknown }) => ({
      ...field,
      value: field.id === 'inspection_date' ? '1999-01-01' : field.id === 'fb6-high-low-tide' ? 'old tide data' : field.value,
    }));
    saved.scheduledTasks = saved.scheduledTasks.map((task: { id: string; status: string }) => ({
      ...task,
      status: 'Missing',
    }));
    window.localStorage.setItem(key, JSON.stringify(saved));
  }, autosaveKey);

  await page.reload();

  await expect(page.getByRole('heading', { name: 'Daily Inspection: Fire Boat 6' })).toBeVisible();
  expect(api.sessionStarts).toHaveLength(2);
  await expect(page.getByText(/prior Fire Boat session expired/i)).toBeVisible();
  await page.getByLabel('Full Name').fill('Captain Browser');
  await page.getByText('Captain Browser', { exact: true }).click();
  await page.getByRole('button', { name: 'Continue to Inspection' }).click();
  await expect(page.getByLabel('Date')).toHaveValue('2026-08-31');
  await expect(page.getByLabel('High Low Tide')).toHaveValue('');
  await expect(page.getByRole('group', { name: 'Status for Fuel Tank Hold' })
    .getByRole('button', { name: 'Present', exact: true }))
    .toHaveAttribute('aria-pressed', 'true');
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

test('upgrades a pre-E01 queue without assigning it to the current user', async ({ page }) => {
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
    ownershipState: 'legacy_unclaimed',
    status: 'requires_attention',
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_LEGACY',
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
  await page.getByRole('button', { name: 'Close notification' }).click();
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  const queuedBeforeRetry = await queuedInspections(page);
  expect(queuedBeforeRetry).toHaveLength(1);
  expect(queuedBeforeRetry[0]).toMatchObject({
    apparatusId: apparatus.id,
    ownerUserId: 101,
    ownerSecurityVersion: 1,
    ownershipState: 'owned',
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

test('a different canonical user cannot replay the prior user offline queue', async ({ page, context }) => {
  const api = await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();

  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  await expect.poll(async () => (await queuedInspections(page))[0]?.ownerUserId).toBe(101);

  api.setIdentity(202, 1);
  await context.setOffline(false);

  await expect.poll(async () => (await queuedInspections(page))[0]?.status).toBe('requires_attention');
  const quarantined = (await queuedInspections(page))[0];
  expect(quarantined).toMatchObject({
    ownerUserId: 101,
    ownerSecurityVersion: 1,
    ownershipState: 'identity_mismatch',
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_MISMATCH',
  });
  expect(api.submissions).toHaveLength(0);
  await expect(page.getByRole('alert')).toContainText('belongs to a different signed-in member');
  await expect(page.getByRole('alert')).not.toContainText('101');
  await expect(page.getByRole('alert')).not.toContainText('202');
});

test('the same canonical user may safely replay after logging in again', async ({ page, context }) => {
  const api = await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);

  api.setIdentity(101, 1);
  await context.setOffline(false);

  await expect.poll(() => api.submissions.length).toBe(1);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);
});

test('a security-version change quarantines captured work without discarding it', async ({ page, context }) => {
  const api = await mockInspectionApi(page);

  await page.goto('/daily/apparatus/engine-1');
  await completeInspection(page);
  await context.setOffline(true);
  await page.getByRole('button', { name: 'Submit Inspection' }).click();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);

  api.setIdentity(101, 2);
  await context.setOffline(false);

  await expect.poll(async () => (await queuedInspections(page))[0]?.ownershipState).toBe('security_mismatch');
  expect((await queuedInspections(page))[0]).toMatchObject({
    ownerUserId: 101,
    ownerSecurityVersion: 1,
    status: 'requires_attention',
    lastErrorCode: 'OFFLINE_QUEUE_SECURITY_VERSION_MISMATCH',
  });
  expect(api.submissions).toHaveLength(0);
});

test('generic offline records enforce ownership per record on a shared device', async ({ page }) => {
  const api = await mockInspectionApi(page);
  await page.goto('/daily/stations');

  await addGenericQueuedSubmission(page, {
    type: 'station_request',
    data: { client_submission_id: 'aaaaaaaa-0000-4000-8000-000000000001', title: 'User A record' },
    createdAt: new Date().toISOString(),
    status: 'pending',
    retryCount: 0,
    ownerUserId: 101,
    ownerSecurityVersion: 1,
    ownershipState: 'owned',
  });
  await addGenericQueuedSubmission(page, {
    type: 'station_request',
    data: { client_submission_id: 'bbbbbbbb-0000-4000-8000-000000000002', title: 'User B record' },
    createdAt: new Date().toISOString(),
    status: 'pending',
    retryCount: 0,
    ownerUserId: 202,
    ownerSecurityVersion: 1,
    ownershipState: 'owned',
  });

  api.setIdentity(202, 1);
  await page.reload();

  await expect.poll(() => api.genericSubmissions.length).toBe(1);
  await expect.poll(async () => (await genericQueuedSubmissions(page)).length).toBe(1);
  expect(api.genericSubmissions[0]).toMatchObject({ title: 'User B record' });
  expect((await genericQueuedSubmissions(page))[0]).toMatchObject({
    ownerUserId: 101,
    ownershipState: 'identity_mismatch',
    status: 'requires_attention',
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_MISMATCH',
  });
  const ownershipAlert = page.getByRole('alert').filter({ hasText: 'belongs to a different signed-in member' });
  await expect(ownershipAlert).toBeVisible();
  await expect(ownershipAlert).not.toContainText('101');
  await expect(ownershipAlert).not.toContainText('202');
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
    ownerUserId: 101,
    ownerSecurityVersion: 1,
    ownershipState: 'owned',
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
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_LEGACY',
  });
  await expect.poll(() => page.evaluate(() => window.localStorage.getItem('mbfd_submission_queue'))).toBeNull();
});
