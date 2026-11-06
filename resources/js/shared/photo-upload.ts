/*
 * One photo per request, with progress, a deadline and a retry schedule
 * (Daily Checkout and Take Home inspections). The caller sends a photo id it
 * keeps for every attempt, so a retry after a lost response never stores a
 * second copy on the server.
 */

/** Below Cloudflare's ~100 s limit, so the browser decides before the proxy does. */
export const PHOTO_UPLOAD_TIMEOUT_MS = 90_000;

/** Waits between automatic attempts: quick at first, then at most every five minutes. */
export const PHOTO_RETRY_DELAYS_MS = [2_000, 5_000, 15_000, 30_000, 60_000, 120_000, 300_000] as const;

export function photoRetryDelayMs(attempt: number, retryAfterMs?: number): number {
  const step = PHOTO_RETRY_DELAYS_MS[Math.max(0, Math.min(attempt, PHOTO_RETRY_DELAYS_MS.length - 1))];

  return Math.max(step, retryAfterMs ?? 0);
}

/** Worth trying again: a timeout, rate limit or server problem. 4xx validation answers are final. */
export function isRetryablePhotoStatus(status: number): boolean {
  return status === 0 || status === 408 || status === 425 || status === 429 || status >= 500;
}

export interface PhotoUploadResponse {
  status: number;
  json: Record<string, unknown>;
  retryAfterMs?: number;
}

export class PhotoUploadNetworkError extends Error {
  constructor(readonly kind: 'timeout' | 'network') {
    super(kind === 'timeout' ? 'The upload timed out.' : 'The connection dropped during the upload.');
    this.name = 'PhotoUploadNetworkError';
  }
}

export interface SendPhotoOptions {
  headers?: Record<string, string>;
  timeoutMs?: number;
  onProgress?: (percent: number) => void;
}

/** Parse `Retry-After` (delta seconds or HTTP date), capped at 15 minutes. */
export function retryAfterMs(value: string | null, now = Date.now()): number | undefined {
  if (!value) return undefined;
  const trimmed = value.trim();
  const milliseconds = /^\d+$/.test(trimmed) ? Number(trimmed) * 1000 : Date.parse(trimmed) - now;

  return Number.isFinite(milliseconds) ? Math.min(Math.max(milliseconds, 0), 15 * 60 * 1000) : undefined;
}

/**
 * POST one multipart form with upload progress. Resolves with any HTTP answer;
 * rejects with PhotoUploadNetworkError when no answer arrived.
 */
export function sendPhoto(url: string, form: FormData, options: SendPhotoOptions = {}): Promise<PhotoUploadResponse> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.timeout = options.timeoutMs ?? PHOTO_UPLOAD_TIMEOUT_MS;
    Object.entries({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers })
      .forEach(([name, value]) => xhr.setRequestHeader(name, value));
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) options.onProgress?.(Math.min(99, Math.round((event.loaded / event.total) * 100)));
    };
    xhr.onload = () => {
      let json: Record<string, unknown> = {};
      try {
        const parsed: unknown = JSON.parse(xhr.responseText || '{}');
        if (parsed && typeof parsed === 'object') json = parsed as Record<string, unknown>;
      } catch {
        json = {};
      }
      if (xhr.status >= 200 && xhr.status < 300) options.onProgress?.(100);
      resolve({ status: xhr.status, json, retryAfterMs: retryAfterMs(xhr.getResponseHeader('Retry-After')) });
    };
    xhr.onerror = () => reject(new PhotoUploadNetworkError('network'));
    xhr.ontimeout = () => reject(new PhotoUploadNetworkError('timeout'));
    xhr.send(form);
  });
}

/** The first validation message in a Laravel error answer. */
export function firstErrorMessage(json: Record<string, unknown>): string | null {
  const errors = json.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors as Record<string, unknown>)[0];
    if (Array.isArray(first) && typeof first[0] === 'string') return first[0];
  }

  return typeof json.message === 'string' ? json.message : null;
}
