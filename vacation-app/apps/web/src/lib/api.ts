import type {
  BoardResponse,
  ColumnMapping,
  DecisionRequest,
  DecisionResult,
  StaffingRules,
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

  searchMembers: (q: string, limit = 20) =>
    jsonFetch<{
      matches: Array<{
        id: string;
        employeeId: string;
        lastName: string;
        firstName: string;
        shift: string | null;
        station: string | null;
        rank: { code: string; label: string } | null;
      }>;
    }>(`/api/members/search?q=${encodeURIComponent(q)}&limit=${limit}`),

  memberProfile: (id: string, year?: number) =>
    jsonFetch<MemberProfile>(
      `/api/members/${id}/profile${year ? `?year=${year}` : ''}`,
    ),

  updateMember: (
    id: string,
    patch: { station?: string | null; certifications?: string[] },
  ) =>
    jsonFetch<{ id: string; station: string | null; certifications: string[] }>(
      `/api/members/${id}`,
      {
        method: 'PATCH',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(patch),
      } as RequestInit,
    ),

  getStaffingRules: () =>
    jsonFetch<{ rules: StaffingRules; updatedAt: string | null }>(
      '/api/staffing-rules',
    ),

  putStaffingRules: (rules: StaffingRules) =>
    jsonFetch<{ rules: StaffingRules; updatedAt: string }>(
      '/api/staffing-rules',
      {
        method: 'PUT',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(rules),
      } as RequestInit,
    ),

  staffingDecision: (req: DecisionRequest) =>
    jsonFetch<DecisionResult>('/api/staffing-decision', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(req),
    } as RequestInit),
};

export type MemberProfile = {
  member: {
    id: string;
    employeeId: string;
    lastName: string;
    firstName: string;
    shift: string | null;
    station: string | null;
    certifications: string[];
    isProbationary: boolean;
    isActive: boolean;
    rank: { id: string; code: string; label: string } | null;
    aDayGroup: { id: string; code: string; label: string } | null;
  };
  year: number;
  balances: Array<{
    leaveCodeId: string;
    code: string;
    label: string;
    uiColor: string;
    entries: number;
    hours: number;
  }>;
  entries: Array<{
    id: string;
    dayDate: string;
    blockIndex: number;
    hours: number | null;
    assignment: string | null;
    leaveCode: { code: string; label: string; uiColor: string };
    sourceImportRunId: string;
  }>;
};
