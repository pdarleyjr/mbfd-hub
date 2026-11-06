import { expect, type Locator, type Page, type Request } from '@playwright/test';

/** The Daily Checkout photo upload route: one photo per request, keyed by the phone's photo id. */
export const PHOTO_UPLOAD_PATH = '/api/public/inspection-photos';

/** The `client_photo_id` field of one multipart photo upload. */
export function uploadedPhotoId(request: Request): string {
  const body = request.postDataBuffer()?.toString('latin1') ?? '';
  const match = /name="client_photo_id"\r\n\r\n([0-9a-f-]{36})\r\n/i.exec(body);
  if (!match) throw new Error('A photo upload arrived without a client_photo_id.');

  return match[1];
}

/** The server's answer to an accepted photo upload. */
export const photoUploadAnswer = (photoId: string) => ({ status: 201, json: { photo_id: photoId, status: 'stored', bytes: 1 } });

export interface QueuedPhotoRecord {
  id: string;
  ownerUserId: number;
  status: string;
  bytes: number;
  type: string;
}

/** Photos saved on this device (IndexedDB `photoUploads`), without their bytes. */
export async function readQueuedPhotos(page: Page): Promise<QueuedPhotoRecord[]> {
  return page.evaluate(async () => {
    const database = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('mbfd-daily-checkout');
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    if (!database.objectStoreNames.contains('photoUploads')) {
      database.close();
      return [];
    }
    const rows = await new Promise<Array<{ id: string; ownerUserId: number; status: string; data: ArrayBuffer; type: string }>>((resolve, reject) => {
      const request = database.transaction('photoUploads').objectStore('photoUploads').getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
    database.close();

    return rows.map(row => ({ id: row.id, ownerUserId: row.ownerUserId, status: row.status, bytes: row.data.byteLength, type: row.type }));
  });
}

/** SHA-256 of the bytes a photo preview shows, once it has loaded (previews come from the device copy). */
export async function previewDigest(preview: Locator): Promise<string> {
  await expect(preview).toHaveAttribute('src', /^blob:/);
  await expect.poll(() => preview.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);

  return preview.evaluate(async (image: HTMLImageElement) => {
    const bytes = await (await fetch(image.src)).arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
  });
}
