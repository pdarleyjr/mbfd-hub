import { useCallback, useEffect, useMemo, useState } from 'react';
import { EmsApiError } from '../../../../utils/emsApi';
import { useWrite } from '../shared';
import { saveMasterBatch } from './api';
import { planBatch, placeServerErrors, startDraft, type RowDraft, type RowErrors } from './batchLogic';
import type { BatchChange, MasterItem } from './types';
import { useUnsavedWork } from './useUnsavedWork';

/**
 * Unsaved Edit mode work outlives the screen. This app's router (declarative BrowserRouter) cannot block navigation, so
 * instead of losing the drafts when the list unmounts (browser Back, a link, a notification that changes `?tab=`), they
 * stay here for as long as the page is loaded, and the list reopens in Edit mode with them. Leaving the page itself
 * (reload, close, a link outside the app) is covered by the browser's own "leave this page?" prompt.
 */
let retained: Record<string, RowDraft> | null = null;

/** True when an earlier visit left Edit mode changes that were neither saved nor cancelled. */
export const hasRetainedEdits = (): boolean => retained !== null && Object.keys(retained).length > 0;

interface Options {
  items: readonly MasterItem[];
  onSaved: (items: MasterItem[]) => void;
}

const NO_ERRORS: RowErrors = Object.freeze({});

export const FIX_FIRST = 'Some boxes need fixing before this can be saved. They are marked in red.';

/**
 * Edit mode state: one draft per row someone touched, kept while they move between shelves, search and filters, and
 * while they visit another page in the app. Save sends every changed row in one all-or-nothing PATCH; a 422 puts each
 * message on its own row and box. A failed save, including a 403, keeps every draft and shows the reason in the bar.
 */
export function useBatchEdit({ items, onSaved }: Options) {
  const [drafts, setDrafts] = useState<Record<string, RowDraft>>(() => retained ?? {});
  const [serverErrors, setServerErrors] = useState<Record<string, RowErrors>>({});
  const [general, setGeneral] = useState<string[]>([]);
  const [attempted, setAttempted] = useState(false);
  const write = useWrite();
  const plan = useMemo(() => planBatch(items, drafts), [items, drafts]);
  const dirty = plan.dirtyIds.length > 0;
  useUnsavedWork(dirty);

  // Keep the drafts for a later visit, but only while they hold a real change.
  useEffect(() => { retained = dirty ? drafts : null; }, [dirty, drafts]);

  useEffect(() => {
    if (!dirty) return undefined;
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  // Untouched rows get the same starting draft object every render, so their memoized row does not redraw on each keystroke.
  const starts = useMemo(() => new WeakMap<MasterItem, RowDraft>(), []);
  const draftFor = useCallback((item: MasterItem): RowDraft => {
    const own = drafts[String(item.id)];
    if (own) return own;
    const cached = starts.get(item);
    if (cached) return cached;
    const fresh = startDraft(item);
    starts.set(item, fresh);
    return fresh;
  }, [drafts, starts]);

  const clearServerErrors = (id: string) => setServerErrors((current) => {
    if (!(id in current)) return current;
    const { [id]: _cleared, ...rest } = current;
    void _cleared;
    return rest;
  });

  const change = useCallback((item: MasterItem, next: RowDraft) => {
    const id = String(item.id);
    setDrafts((current) => ({ ...current, [id]: next }));
    clearServerErrors(id);
  }, []);

  /** Puts one row back the way the server has it. */
  const undo = useCallback((item: MasterItem) => {
    const id = String(item.id);
    setDrafts((current) => {
      const { [id]: _dropped, ...rest } = current;
      void _dropped;
      return rest;
    });
    clearServerErrors(id);
  }, []);

  /** Client problems show once Save has been tried; server problems show until that row is changed. */
  const errorsFor = useCallback((item: MasterItem): RowErrors => {
    const client = attempted ? plan.errors[String(item.id)] : undefined;
    const server = serverErrors[String(item.id)];
    return client || server ? { ...client, ...server } : NO_ERRORS;
  }, [attempted, plan.errors, serverErrors]);

  const { clear, run } = write;
  const reset = useCallback(() => {
    setDrafts({});
    retained = null;
    setServerErrors({});
    setGeneral([]);
    setAttempted(false);
    clear();
  }, [clear]);

  const save = useCallback(async (): Promise<number | null> => {
    setAttempted(true);
    setGeneral([]);
    if (Object.keys(plan.errors).length > 0) { setGeneral([FIX_FIRST]); return null; }
    const sent: BatchChange[] = plan.changes;
    if (sent.length === 0) return null;
    let savedCount: number | null = null;
    await run(`batch:${JSON.stringify(sent)}`, async (key) => {
      try {
        return await saveMasterBatch(sent, key);
      } catch (failure) {
        if (failure instanceof EmsApiError && failure.status === 422) {
          const placed = placeServerErrors(failure.fields, sent);
          setServerErrors(placed.rows);
          setGeneral(Object.keys(placed.rows).length > 0 ? [FIX_FIRST, ...placed.general] : placed.general);
        }
        throw failure;
      }
    }, {
      onDone: (saved) => {
        onSaved(saved);
        setDrafts({});
        retained = null;
        setServerErrors({});
        setAttempted(false);
        savedCount = sent.length;
      },
    });
    return savedCount;
  }, [onSaved, plan.changes, plan.errors, run]);

  /** Rows that cannot be saved as they are, so the bar can list them even when they sit on another shelf. */
  const problemIds = useMemo(() => {
    const ids = new Set<string>([...(attempted ? Object.keys(plan.errors) : []), ...Object.keys(serverErrors)]);
    return items.filter((item) => ids.has(String(item.id))).map((item) => item.id);
  }, [attempted, items, plan.errors, serverErrors]);

  return {
    draftFor,
    change,
    undo,
    errorsFor,
    reset,
    save,
    dirtyCount: plan.dirtyIds.length,
    problemIds,
    general,
    busy: write.busy,
    retry: write.retry,
    /** A failure that is not about one box (no access, no connection, server trouble). */
    error: general.length > 0 ? null : write.error,
  };
}

export type BatchEdit = ReturnType<typeof useBatchEdit>;
