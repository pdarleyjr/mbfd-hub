import { expect, test, type Page } from '@playwright/test';

declare global {
  interface Window {
    __pwaIdentity: { userId: number; securityVersion: number };
    __pwaSubmissions: Array<Record<string, unknown>>;
  }
}

const queuedId = '11111111-2222-4333-8444-555555555555';
const checklistVersion = 'a'.repeat(64);

async function installApiFixture(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__pwaIdentity = { userId: 101, securityVersion: 1 };
    window.__pwaSubmissions = [];
    const nativeFetch = window.fetch.bind(window);

    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const request = new Request(input, init);
      const url = new URL(request.url, window.location.origin);
      if (!url.pathname.startsWith('/api/')) {
        return nativeFetch(input, init);
      }
      if (!navigator.onLine) {
        throw new TypeError('Offline');
      }
      if (url.pathname === '/api/me/context') {
        const { userId, securityVersion } = window.__pwaIdentity;
        return Response.json({
          version: 1,
          identity: { user_id: userId, has_personnel_profile: true },
          personnel: { employee_profile_id: userId + 1_000, employee_number: `E${userId}`, name: 'PWA Member', rank: 'Firefighter' },
          offline: { security_version: securityVersion },
          session: { authenticated: true },
        });
      }
      if (url.pathname === '/api/public/stations') {
        return Response.json({ stations: [] });
      }
      if (url.pathname === '/api/public/apparatuses/101/inspections' && request.method === 'POST') {
        window.__pwaSubmissions.push(await request.json() as Record<string, unknown>);
        return Response.json({ success: true, review_status: 'approved' }, { status: 201 });
      }

      return Response.json({ data: [], meta: { total: 0 } });
    };
  });
}

async function waitForControlledWorker(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(async () => {
    await navigator.serviceWorker.ready;
    return navigator.serviceWorker.controller?.scriptURL.endsWith('/daily/sw.js') ?? false;
  })).toBe(true);
}

async function addQueuedInspection(page: Page, ownerUserId = 101, ownershipState = 'owned'): Promise<void> {
  await page.evaluate(async ({ id, version, owner, state }) => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const transaction = database.transaction('dailyCheckoutSubmissions', 'readwrite');
    const request = transaction.objectStore('dailyCheckoutSubmissions').add({
      id,
      apparatusId: 101,
      checklistVersion: version,
      createdAt: new Date(),
      updatedAt: new Date(),
      status: 'pending',
      retryCount: 0,
      ownerUserId: owner,
      ownerSecurityVersion: 1,
      ownershipState: state,
      data: {
        client_submission_id: id,
        checklist_version: version,
        operator_name: 'PWA Member',
        compartments: [],
      },
    });
    await new Promise<void>((resolve, reject) => {
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
    database.close();
  }, { id: queuedId, version: checklistVersion, owner: ownerUserId, state: ownershipState });
}

async function queuedInspections(page: Page): Promise<Array<Record<string, unknown>>> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    const transaction = database.transaction('dailyCheckoutSubmissions', 'readonly');
    const request = transaction.objectStore('dailyCheckoutSubmissions').getAll();
    const rows = await new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
      request.onsuccess = () => resolve(request.result as Array<Record<string, unknown>>);
      request.onerror = () => reject(request.error);
    });
    database.close();
    return rows;
  });
}

test('installed Daily worker caches the shell and an offline queue survives reload then submits exactly once', async ({ page, context }) => {
  await installApiFixture(page);
  await page.goto('/daily/');
  await waitForControlledWorker(page);

  const cacheEvidence = await page.evaluate(async () => ({
    names: await caches.keys(),
    shell: Boolean(await (await caches.open('mbfd-checkout-v6')).match('/daily/index.html')),
  }));
  expect(cacheEvidence.names).toContain('mbfd-checkout-v6');
  expect(cacheEvidence.shell).toBe(true);

  await context.setOffline(true);
  await addQueuedInspection(page);
  await page.reload();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);

  await context.setOffline(false);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);
  await expect.poll(() => page.evaluate(() => window.__pwaSubmissions.length)).toBe(1);
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => window.__pwaSubmissions.length)).toBe(1);
});

test('an active worker preserves and quarantines another account owner queue without submitting it', async ({ page, context }) => {
  await installApiFixture(page);
  await page.goto('/daily/');
  await waitForControlledWorker(page);

  await context.setOffline(true);
  await addQueuedInspection(page);
  await page.reload();
  await page.evaluate(() => { window.__pwaIdentity = { userId: 202, securityVersion: 1 }; });
  await context.setOffline(false);

  await expect.poll(async () => (await queuedInspections(page))[0]?.ownershipState).toBe('identity_mismatch');
  expect((await queuedInspections(page))[0]).toMatchObject({
    status: 'requires_attention',
    lastErrorCode: 'OFFLINE_QUEUE_OWNER_MISMATCH',
  });
  expect(await page.evaluate(() => window.__pwaSubmissions.length)).toBe(0);
});

test('a worker update check does not erase unresolved legacy work', async ({ page }) => {
  await installApiFixture(page);
  await page.goto('/daily/');
  await waitForControlledWorker(page);
  await addQueuedInspection(page, 101, 'legacy_unclaimed');

  await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    await registration.update();
  });

  expect(await queuedInspections(page)).toHaveLength(1);
});
