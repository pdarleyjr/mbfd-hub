import type { Page } from '@playwright/test';
import type { DailyCheckoutDraft } from '../../../resources/js/daily-checkout/src/lib/db';

export async function readDrafts(page: Page): Promise<DailyCheckoutDraft[]> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    try {
      if (!database.objectStoreNames.contains('dailyCheckoutDrafts')) return [];
      return await new Promise<DailyCheckoutDraft[]>((resolve, reject) => {
        const request = database.transaction('dailyCheckoutDrafts').objectStore('dailyCheckoutDrafts').getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
      });
    } finally {
      database.close();
    }
  });
}

export async function replaceDrafts(page: Page, drafts: DailyCheckoutDraft[]): Promise<void> {
  await page.evaluate(async records => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    try {
      await new Promise<void>((resolve, reject) => {
        const transaction = database.transaction('dailyCheckoutDrafts', 'readwrite');
        const store = transaction.objectStore('dailyCheckoutDrafts');
        store.clear();
        for (const record of records) store.put(record);
        transaction.oncomplete = () => resolve();
        transaction.onabort = () => reject(transaction.error);
        transaction.onerror = () => reject(transaction.error);
      });
    } finally {
      database.close();
    }
  }, drafts);
}