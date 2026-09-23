import { expect, test, type Page } from '@playwright/test';
import { readdirSync } from 'node:fs';
import { readDrafts } from './support/daily-checkout-drafts';

declare global {
  interface Window {
    __pwaIdentity: { userId: number; securityVersion: number };
    __pwaSubmissions: Array<Record<string, unknown>>;
    __pwaNativeFetch: typeof fetch;
  }
}

const queuedId = '11111111-2222-4333-8444-555555555555';
const checklistVersion = 'a'.repeat(64);

async function installApiFixture(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__pwaIdentity = { userId: 101, securityVersion: 1 };
    window.__pwaSubmissions = [];
    const nativeFetch = window.fetch.bind(window);
    window.__pwaNativeFetch = nativeFetch;

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
      if (url.pathname === '/api/public/apparatuses') {
        return Response.json([{ id: 101, name: 'Offline Fixture Apparatus', slug: 'offline-fixture', designation: 'TEST', type: 'rescue', vehicle_number: 'TEST-101', status: 'In Service' }]);
      }
      if (url.pathname === '/api/public/apparatuses/101/checklist') {
        return Response.json({ checklist_version: 'a'.repeat(64), checklist: { officerChecklist: [], compartments: [{ id: 'cab', title: 'Cab', items: [{ id: 'radio', name: 'Test radio' }, { id: 'bag', name: 'Test bag' }] }] } });
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
  const privateApiPaths = ['/api/me/context', '/api/public/inspection-revisions'];
  for (const endpoint of privateApiPaths) await context.route(`**${endpoint}`, route => route.fulfill({ json: { private_fixture: endpoint } }));
  // Seed the prior worker's private responses on a same-origin page that does not register a worker.
  await page.goto('/daily/manifest.json');
  await page.evaluate(async () => {
    await (await caches.open('mbfd-checkout-v6')).put('/api/me/context', Response.json({ previous_member: 'fixture' }));
    await (await caches.open('mbfd-api-cache-v6')).put('/api/public/inspection-revisions', Response.json({ previous_member: 'fixture' }));
  });
  await installApiFixture(page);
  await page.goto('/daily/');
  await waitForControlledWorker(page);

  const cacheEvidence = await page.evaluate(async () => ({
    names: await caches.keys(),
    shell: Boolean(await (await caches.open('mbfd-checkout-v7')).match('/daily/index.html')),
    assets: (await (await caches.open('mbfd-checkout-v7')).keys()).map(request => new URL(request.url).pathname),
  }));
  expect(cacheEvidence.names).toContain('mbfd-checkout-v7');
  expect(cacheEvidence.names).not.toContain('mbfd-checkout-v6');
  expect(cacheEvidence.names).not.toContain('mbfd-api-cache-v6');
  expect(cacheEvidence.shell).toBe(true);
  const emittedAssets = readdirSync('test-results/daily-checkout-e2e-build/assets').filter(file => /\.(js|css)$/.test(file)).map(file => `/daily/assets/${file}`);
  expect(emittedAssets.length).toBeGreaterThan(1);
  expect(cacheEvidence.assets).toEqual(expect.arrayContaining(emittedAssets));
  // Bypass the page-level API fixture so these requests traverse the real worker.
  await page.evaluate(async endpoints => {
    for (const endpoint of endpoints) {
      const response = await window.__pwaNativeFetch(endpoint);
      if (!response.ok) throw new Error(`Private API fixture failed: ${endpoint}`);
      await response.json();
    }
  }, privateApiPaths);

  await context.setOffline(true);
  await addQueuedInspection(page);
  await page.reload();
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(1);

  await context.setOffline(false);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);
  await expect.poll(() => page.evaluate(() => window.__pwaSubmissions.length)).toBe(1);
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => window.__pwaSubmissions.length)).toBe(1);
  const cachedPrivateRequests = await page.evaluate(async endpoints => {
    const requests = (await Promise.all((await caches.keys()).map(async name => (await caches.open(name)).keys()))).flat();
    return requests.map(request => new URL(request.url).pathname).filter(path => endpoints.includes(path));
  }, privateApiPaths);
  expect(cachedPrivateRequests).toEqual([]);
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

test('multiple camera-sized photos survive a real offline reload and queue replay exactly once', async ({ page, context }, testInfo) => {
  await installApiFixture(page);
  await page.goto('/daily/vehicle-inspections/offline-fixture');
  await waitForControlledWorker(page);
  await expect(page.getByRole('heading', { name: 'Offline Fixture Apparatus', exact: true })).toBeVisible();
  const photo = await page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = 1100;
    canvas.height = 1100;
    const drawing = canvas.getContext('2d')!;
    const pixels = drawing.createImageData(canvas.width, canvas.height);
    for (let offset = 0; offset < pixels.data.length; offset += 65536) crypto.getRandomValues(pixels.data.subarray(offset, Math.min(offset + 65536, pixels.data.length)));
    for (let offset = 3; offset < pixels.data.length; offset += 4) pixels.data[offset] = 255;
    drawing.putImageData(pixels, 0, 0);
    return canvas.toDataURL();
  });
  const buffer = Buffer.from(photo.split(',')[1], 'base64');
  expect(buffer.length).toBeGreaterThan(3_000_000);
  expect(buffer.length).toBeLessThan(5_000_000);
  const rows = page.locator('.equipment-row');
  for (const index of [0, 1]) {
    await rows.nth(index).locator('.equipment-expand').click();
    await rows.nth(index).getByRole('button', { name: 'Damaged', exact: true }).click();
    await rows.nth(index).getByLabel('Photo (optional)').setInputFiles({ name: `test-evidence-${index}.png`, mimeType: 'image/png', buffer });
  }
  await expect.poll(async () => (await readDrafts(page))[0]?.data.compartments[0]?.items.filter(item => item.photo === photo).length).toBe(2);
  expect(await page.evaluate(() => Object.values(localStorage).some(value => value.includes('data:image/')))).toBe(false);
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByText(/Restored from autosave/)).toBeVisible();
  for (const index of [0, 1]) {
    await rows.nth(index).locator('.equipment-expand').click();
    await expect(rows.nth(index).locator('img')).toHaveAttribute('src', photo);
  }
  await rows.last().getByLabel('Notes (optional)').fill('Verified offline after reload.');
  await page.getByRole('button', { name: 'Continue: Shift', exact: true }).click();
  await page.getByRole('combobox', { name: 'Shift', exact: true }).selectOption('B');
  await page.getByRole('button', { name: 'Review & Sign', exact: true }).click();
  const canvas = page.locator('canvas');
  await canvas.scrollIntoViewIfNeeded();
  const box = await canvas.boundingBox();
  if (!box) throw new Error('Signature canvas missing');
  await page.mouse.move(box.x + 20, box.y + 40);
  await page.mouse.down();
  await page.mouse.move(box.x + 140, box.y + 90, { steps: 8 });
  await page.mouse.up();
  const close = page.getByRole('button', { name: 'Close notification' });
  if (await close.isVisible()) await close.click();
  await page.getByRole('button', { name: 'Submit Inspection', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Inspection Queued!' })).toBeVisible();
  expect(await readDrafts(page)).toHaveLength(1);
  const queued = await queuedInspections(page);
  expect(queued).toHaveLength(1);
  expect(queued[0]).toMatchObject({ ownerUserId: 101, ownerSecurityVersion: 1, data: { operator_name: 'PWA Member', employee_id: 1101, shift: 'B', unit_number: 'TEST-101' } });
  await page.reload();
  expect(await queuedInspections(page)).toHaveLength(1);
  await context.setOffline(false);
  await expect.poll(async () => (await queuedInspections(page)).length).toBe(0);
  await expect.poll(async () => (await readDrafts(page)).length).toBe(0);
  const submissions = await page.evaluate(() => window.__pwaSubmissions);
  expect(submissions).toHaveLength(1);
  expect(submissions[0]).toEqual(queued[0].data);
  await page.evaluate(() => { window.dispatchEvent(new Event('online')); window.dispatchEvent(new Event('online')); });
  await expect(page.getByText('Inspection recorded', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => window.__pwaSubmissions.length)).toBe(1);
  await page.screenshot({ path: testInfo.outputPath('large-photo-offline-replay.png') });
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
