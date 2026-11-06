import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  EXPIRED_MESSAGE,
  MAX_SERVER_ERROR_ATTEMPTS,
  PENDING_RETENTION_MS,
  ROUTE_MISSING_RETRY_MS,
  SERVER_GAVE_UP_MESSAGE,
  photoIdsOf,
  photoRecordFate,
  photoSendOutcome,
  photoSummaryLine,
  summarizePhotos,
} from './photoUploadQueue';
import { PhotoUploadNetworkError, isRetryablePhotoStatus, photoRetryDelayMs, retryAfterMs } from '../../../shared/photo-upload.ts';
import { PHOTO_MAX_EDGE, isLikelyPhoto, preparePhoto, targetSize } from '../../../shared/photo-compression.ts';
import { queuedPhotoStatus } from '../components/QueuedPhotoPreview';
import { photoProblem } from './photoFile';

describe('photo compression', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('bounds the long edge to 2048 px without ever enlarging', () => {
    expect(targetSize(4032, 3024)).toEqual({ width: 2048, height: 1536 });
    expect(targetSize(3024, 4032)).toEqual({ width: 1536, height: 2048 });
    expect(targetSize(8064, 6048)).toEqual({ width: 2048, height: 1536 });
    expect(targetSize(1200, 900)).toEqual({ width: 1200, height: 900 });
    expect(targetSize(0, 900)).toEqual({ width: 0, height: 0 });
    expect(PHOTO_MAX_EDGE).toBe(2048);
  });

  it('accepts iPhone HEIC files even when the phone reports no type', () => {
    expect(isLikelyPhoto({ type: '', name: 'IMG_0412.HEIC' })).toBe(true);
    expect(isLikelyPhoto({ type: 'image/heic', name: 'photo' })).toBe(true);
    expect(isLikelyPhoto({ type: 'application/pdf', name: 'manual.pdf' })).toBe(false);
    expect(photoProblem({ type: '', name: 'IMG_0412.heic', size: 4_000_000 })).toBeNull();
    expect(photoProblem({ type: 'image/jpeg', name: 'big.jpg', size: 9_000_000 })).toBeNull();
    expect(photoProblem({ type: 'image/jpeg', name: 'huge.jpg', size: 30 * 1024 * 1024 })).toMatch(/25 MB/);
  });

  it('re-encodes a decoded photo to a bounded JPEG', async () => {
    const close = vi.fn();
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 4032, height: 3024, close }));
    const drawImage = vi.fn();
    const getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ fillRect: vi.fn(), drawImage, fillStyle: '', imageSmoothingQuality: 'low' } as unknown as CanvasRenderingContext2D);
    const toBlob = vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback: BlobCallback, type?: string) => callback(new Blob(['jpeg-bytes'], { type })));

    const prepared = await preparePhoto(new File([new Uint8Array(5_000_000)], 'IMG_0412.HEIC', { type: 'image/heic' }));

    expect(prepared.compressed).toBe(true);
    expect(prepared.filename).toBe('IMG_0412.jpg');
    expect(prepared.blob.type).toBe('image/jpeg');
    expect([prepared.width, prepared.height]).toEqual([2048, 1536]);
    expect(drawImage).toHaveBeenCalledWith(expect.anything(), 0, 0, 2048, 1536);
    expect(toBlob).toHaveBeenCalledWith(expect.any(Function), 'image/jpeg', 0.8);
    expect(close).toHaveBeenCalled();
    getContext.mockRestore();
    toBlob.mockRestore();
  });

  it('sends the original when the browser cannot convert it', async () => {
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 4032, height: 3024, close: vi.fn() }));
    const getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
    const original = new File([new Uint8Array(10)], 'IMG_0001.HEIC', { type: 'image/heic' });

    const prepared = await preparePhoto(original);

    expect(prepared.compressed).toBe(false);
    expect(prepared.blob).toBe(original);
    getContext.mockRestore();
  });
});

describe('photo upload retries', () => {
  it('waits longer after each failure, never under the server Retry-After', () => {
    expect(photoRetryDelayMs(0)).toBe(2_000);
    expect(photoRetryDelayMs(1)).toBe(5_000);
    expect(photoRetryDelayMs(99)).toBe(300_000);
    expect(photoRetryDelayMs(0, 30_000)).toBe(30_000);
    expect(retryAfterMs('12')).toBe(12_000);
    expect(retryAfterMs('99999')).toBe(15 * 60 * 1000);
    expect(isRetryablePhotoStatus(503)).toBe(true);
    expect(isRetryablePhotoStatus(429)).toBe(true);
    expect(isRetryablePhotoStatus(422)).toBe(false);
  });

  it('turns each answer into one next step', () => {
    expect(photoSendOutcome({ status: 201, json: {} }, 0)).toEqual({ kind: 'sent' });
    expect(photoSendOutcome({ status: 200, json: {} }, 3)).toEqual({ kind: 'sent' });
    expect(photoSendOutcome({ status: 419, json: {} }, 0)).toEqual({ kind: 'sign_in' });
    expect(photoSendOutcome({ status: 401, json: {} }, 0)).toEqual({ kind: 'sign_in' });
    expect(photoSendOutcome({ status: 503, json: {}, retryAfterMs: 60_000 }, 0)).toMatchObject({ kind: 'retry', delayMs: 60_000 });
    expect(photoSendOutcome(new PhotoUploadNetworkError('timeout'), 2)).toMatchObject({ kind: 'retry', delayMs: 15_000 });
    expect(photoSendOutcome({ status: 422, json: { errors: { photo: ['That file is not a photo.'] } } }, 0))
      .toEqual({ kind: 'rejected', message: 'That file is not a photo.' });
    expect(photoSendOutcome({ status: 413, json: {} }, 0)).toMatchObject({ kind: 'rejected' });
    expect(photoSendOutcome({ status: 415, json: {} }, 0)).toMatchObject({ kind: 'rejected' });
    expect(photoSendOutcome({ status: 409, json: {} }, 0)).toMatchObject({ kind: 'rejected' });
  });

  it('keeps a photo for sign-in on 401, 403 and 419', () => {
    for (const status of [401, 403, 419]) {
      expect(photoSendOutcome({ status, json: { message: 'Forbidden.' } }, 4)).toEqual({ kind: 'sign_in' });
    }
  });

  it('waits a long time, but never gives up, when the upload route is missing during a release', () => {
    for (const status of [404, 405]) {
      const outcome = photoSendOutcome({ status, json: {} }, 0, MAX_SERVER_ERROR_ATTEMPTS + 5);
      expect(outcome).toMatchObject({ kind: 'retry', delayMs: ROUTE_MISSING_RETRY_MS });
      expect(outcome).not.toHaveProperty('serverError', true);
    }
    expect(photoSendOutcome({ status: 404, json: {}, retryAfterMs: 60_000 }, 99)).toMatchObject({ delayMs: ROUTE_MISSING_RETRY_MS });
  });

  it('counts server errors and gives a photo up after the cap', () => {
    expect(photoSendOutcome({ status: 500, json: {} }, 3, 0)).toMatchObject({ kind: 'retry', serverError: true });
    expect(photoSendOutcome({ status: 429, json: {} }, 3, MAX_SERVER_ERROR_ATTEMPTS)).toMatchObject({ kind: 'retry', serverError: false });
    expect(photoSendOutcome({ status: 502, json: {} }, 40, MAX_SERVER_ERROR_ATTEMPTS - 2)).toMatchObject({ kind: 'retry' });
    expect(photoSendOutcome({ status: 502, json: {} }, 40, MAX_SERVER_ERROR_ATTEMPTS - 1)).toEqual({ kind: 'failed', message: SERVER_GAVE_UP_MESSAGE });
    // A lost connection never counts toward the cap, including the service worker's offline answer.
    expect(photoSendOutcome({ status: 503, json: { offline: true } }, 40, MAX_SERVER_ERROR_ATTEMPTS)).toMatchObject({ kind: 'retry' });
    expect(photoSendOutcome({ status: 503, json: { offline: true } }, 0, 0)).not.toHaveProperty('serverError', true);
    expect(photoSendOutcome(new PhotoUploadNetworkError('network'), 40, MAX_SERVER_ERROR_ATTEMPTS)).toMatchObject({ kind: 'retry' });
  });
});

describe('photo store housekeeping', () => {
  const now = Date.UTC(2026, 10, 5, 12);
  const day = 24 * 60 * 60 * 1000;

  it('gives up on a photo nobody sent within 14 days, whoever owns it', () => {
    expect(photoRecordFate({ status: 'pending', createdAt: now - PENDING_RETENTION_MS - 1 }, now)).toBe('expire');
    expect(photoRecordFate({ status: 'pending', createdAt: now - 13 * day }, now)).toBe('keep');
    expect(EXPIRED_MESSAGE).toMatch(/not sent within 14 days/);
  });

  it('keeps a given-up photo visible for 14 days after it was given up, then removes it', () => {
    expect(photoRecordFate({ status: 'failed', createdAt: now - 20 * day, failedAt: now - day }, now)).toBe('keep');
    expect(photoRecordFate({ status: 'failed', createdAt: now - 40 * day, failedAt: now - 15 * day }, now)).toBe('delete');
  });

  it('removes sent and rejected photos after their retention', () => {
    expect(photoRecordFate({ status: 'sent', createdAt: now - 10 * day, sentAt: now - day }, now)).toBe('keep');
    expect(photoRecordFate({ status: 'sent', createdAt: now - 10 * day, sentAt: now - 4 * day }, now)).toBe('delete');
    expect(photoRecordFate({ status: 'rejected', createdAt: now - 15 * day }, now)).toBe('delete');
    expect(photoRecordFate({ status: 'rejected', createdAt: now - 2 * day }, now)).toBe('keep');
  });
});

describe('photo status for the member', () => {
  it('collects every photo id a checkout names, once', () => {
    expect(photoIdsOf({
      compartments: [{ items: [{ photo_id: 'a' }, {}, { photo_id: 'b' }] }],
      defects: [{ photo_id: 'a' }],
      concerns: [{ photo_id: 'c' }, {}],
    })).toEqual(['a', 'b', 'c']);
  });

  it('says how many photos arrived and what happens to the rest', () => {
    const summary = summarizePhotos([
      { id: 'a', status: 'sent' }, { id: 'b', status: 'pending' }, undefined, { id: 'd', status: 'sent' },
    ], ['a', 'b', 'c', 'd']);

    // A photo no longer on this device was sent and cleaned up.
    expect(summary).toEqual({ total: 4, sent: 3, pending: 1, rejected: 0, failed: 0 });
    expect(photoSummaryLine(summary, true)).toBe('3 of 4 photos sent. The other one is sending now and stays saved on this device until it arrives.');
    expect(photoSummaryLine(summary, false)).toBe('3 of 4 photos sent. The other photo will send when this device is back online.');
    expect(photoSummaryLine({ total: 2, sent: 2, pending: 0, rejected: 0, failed: 0 }, true)).toBeNull();
    expect(photoSummaryLine({ total: 3, sent: 1, pending: 1, rejected: 1, failed: 0 }, true)).toContain('1 could not be used by the Hub; the inspection was still sent.');
    expect(photoSummaryLine({ total: 2, sent: 1, pending: 0, rejected: 0, failed: 1 }, true)).toBe('1 of 2 photos sent. 1 could not be sent; the inspection was still sent.');
    expect(summarizePhotos([{ id: 'a', status: 'failed' }], ['a'])).toMatchObject({ failed: 1, sent: 0 });
  });

  it('tells the member a photo is safe while it waits for a signal', () => {
    expect(queuedPhotoStatus({ url: null, status: 'pending' }, true)).toMatch(/sends when you are back online/);
    expect(queuedPhotoStatus({ url: null, status: 'pending', progress: 42 }, false)).toBe('Sending photo… 42%');
    expect(queuedPhotoStatus({ url: null, status: 'sent' }, false)).toBe('Photo sent.');
    expect(queuedPhotoStatus({ url: null, status: 'rejected', lastError: 'Too large.' }, false)).toBe('Too large.');
    expect(queuedPhotoStatus({ url: null, status: 'failed', lastError: EXPIRED_MESSAGE }, false)).toBe(EXPIRED_MESSAGE);
    expect(queuedPhotoStatus({ url: null, status: 'failed' }, true)).toMatch(/could not be sent/);
  });
});
