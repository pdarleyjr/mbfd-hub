import { call, send } from '../../../../utils/emsApi';
import type { FormDetail, FormSummary, FormsPage, FormsPreview, FormView, MasterItem, ReconcileRequest, ReconcileResult } from './types.ts';

/**
 * Calls for the Submitted Inventory Forms tab. They ride the shared `call`/`send` in utils/emsApi.ts, so errors are the
 * shared EmsApiError and the same retry and message rules apply (status 0 = no answer: keep what was typed and resend
 * with the SAME Idempotency-Key).
 */
const enc = encodeURIComponent;
const asRecord = (value: unknown): Record<string, unknown> => (value && typeof value === 'object' ? value as Record<string, unknown> : {});
const post = (body?: unknown): RequestInit => send('POST', body);

const normalizeForm = (raw: FormDetail): FormDetail => ({
  ...raw,
  lines: Array.isArray(raw.lines) ? raw.lines : [],
  history: Array.isArray(raw.history) ? raw.history.map((event) => ({ ...event, payload: asRecord(event.payload) })) : [],
  messages: Array.isArray(raw.messages) ? raw.messages : [],
  revisions: Array.isArray(raw.revisions) ? raw.revisions : [],
  items_preview: Array.isArray(raw.items_preview) ? raw.items_preview : [],
});

export async function fetchForms(view: FormView, params: { q?: string; page?: number; perPage?: number }): Promise<FormsPage> {
  const query = new URLSearchParams({ state: view, page: String(params.page ?? 1), per_page: String(params.perPage ?? 25) });
  const q = (params.q ?? '').trim();
  if (q !== '') query.set('q', q);
  const result = await call<FormSummary[]>(`/requests?${query.toString()}`);
  const meta = result.meta as Partial<FormsPage['meta']>;
  const rows = Array.isArray(result.data) ? result.data : [];
  return {
    forms: rows.map((row) => ({ ...row, items_preview: Array.isArray(row.items_preview) ? row.items_preview : [] })),
    meta: { page: meta.page ?? 1, per_page: meta.per_page ?? 25, total: meta.total ?? rows.length, last_page: meta.last_page ?? 1 },
  };
}

export const fetchForm = async (uuid: string): Promise<FormDetail> => normalizeForm((await call<FormDetail>(`/requests/${enc(uuid)}`)).data);

export const acknowledgeForm = async (uuid: string, key: string): Promise<FormDetail> => (
  normalizeForm((await call<FormDetail>(`/requests/${enc(uuid)}/acknowledge`, post(), key)).data)
);

export const rejectForm = async (uuid: string, reason: string, key: string): Promise<FormDetail> => (
  normalizeForm((await call<FormDetail>(`/requests/${enc(uuid)}/reject`, post({ reason }), key)).data)
);

export async function fetchReconcilePreview(ids: readonly string[]): Promise<FormsPreview> {
  const data = (await call<FormsPreview>(`/forms/reconcile/preview?ids=${enc(ids.join(','))}`)).data;
  return {
    ...data,
    forms: Array.isArray(data.forms) ? data.forms : [],
    lines: Array.isArray(data.lines) ? data.lines : [],
    totals: Array.isArray(data.totals) ? data.totals : [],
  };
}

export const reconcileForms = async (body: ReconcileRequest, key: string): Promise<ReconcileResult> => (
  (await call<ReconcileResult>('/forms/reconcile', post(body), key)).data
);

/** The supply room items the picker searches. Controlled items are not in this list. */
export async function fetchMasterItems(): Promise<MasterItem[]> {
  const data = (await call<{ items?: MasterItem[] }>('/supply/master')).data;
  return (Array.isArray(data?.items) ? data.items : []).map((item) => ({ ...item, aliases: Array.isArray(item.aliases) ? item.aliases : [] }));
}
