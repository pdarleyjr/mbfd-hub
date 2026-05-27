import type {
  BoardResponse,
  ColumnMapping,
  WorkCodeDecision,
} from '@mbfd-vacation/shared';

/**
 * Browser-side fetch helpers. All requests go to /api/* which Next.js
 * rewrites to the Hono API container.
 */

type FetchOpts = Omit<RequestInit, 'body'>;

async function jsonFetch<T>(path: string, init?: FetchOpts): Promise<T> {
  const res = await fetch(path, {
    credentials: 'same-origin',
    headers: { accept: 'application/json' },
    ...init,
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`HTTP ${res.status} ${res.statusText}: ${body}`);
  }
  return (await res.json()) as T;
}

export type ImportRunSummary = {
  id: string;
  fileName: string;
  fileSize: number;
  /** Only on per-run detail responses, not the list. */
  fileSha256?: string;
  uploadedAt: string;
  status: string;
  parseStats: unknown;
  errorMessage: string | null;
  finishedAt: string | null;
};

export const api = {
  health: () => jsonFetch<{ ok: boolean }>('/api/health'),

  board: (params: URLSearchParams) =>
    jsonFetch<BoardResponse>(`/api/board?${params.toString()}`),

  uploadImport: (file: File, onProgress?: (loaded: number) => void): Promise<{ runId: string; wasDuplicate: boolean }> => {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('file', file);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/imports');
      xhr.upload.addEventListener('progress', (ev) => {
        if (onProgress) onProgress(ev.loaded);
      });
      xhr.onerror = () => reject(new Error('network error'));
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            resolve(JSON.parse(xhr.responseText));
          } catch (err) {
            reject(err as Error);
          }
        } else {
          reject(new Error(`HTTP ${xhr.status}: ${xhr.responseText}`));
        }
      };
      xhr.send(fd);
    });
  },

  commitImport: (runId: string, payload: {
    columnMapping: ColumnMapping;
    workCodeDecisions: WorkCodeDecision[];
  }) =>
    jsonFetch<{ queued: boolean }>(`/api/imports/${runId}/commit`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    } as RequestInit),

  rollbackImport: (runId: string) =>
    jsonFetch<{ rolledBack: boolean; restoredCount: number; removedCount: number }>(
      `/api/imports/${runId}/rollback`,
      { method: 'POST' } as RequestInit,
    ),

  listRuns: (limit = 25, offset = 0) =>
    jsonFetch<{ runs: ImportRunSummary[]; limit: number; offset: number }>(
      `/api/imports/runs?limit=${limit}&offset=${offset}`,
    ),

  getRun: (id: string) => jsonFetch<ImportRunSummary>(`/api/imports/runs/${id}`),

  listLeaveCodes: () =>
    jsonFetch<{
      leaveCodes: Array<{
        id: string;
        code: string;
        label: string;
        uiColor: string;
        isADayMarker: boolean;
      }>;
    }>('/api/leave-codes'),
};
