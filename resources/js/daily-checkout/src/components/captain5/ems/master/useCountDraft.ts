import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../../utils/api';
import { EmsApiError, failureMessage, isForbidden, isRetryable } from '../../../../utils/emsApi';
import { setUnsavedWork } from '../../../../pwa/draftBusy';
import { newKey } from '../../format';
import { saveCountEntries, startCountForm } from './api';
import { clearLocalDraft, readLocalDraft, writeLocalDraft } from './countStorage';
import {
  buildBatch, dirtyCount, mergeEntries, retryDelayMs, savedMatches, settleDirty, withDirty, withEntry, type Dirty,
} from './logic';
import type { CountFormCount, EntryMap, MasterItem, MasterSection, SaveStatus } from './types';

export const DEBOUNCE_MS = 800;
const BUSY_TOKEN = 'master-count-form';

interface Snapshot { entries: EntryMap; dirty: Dirty }

/** The server answered, but not with the numbers that were sent: not a save. */
class UnconfirmedSave extends Error {
  constructor() { super('The server did not confirm the numbers. Trying again.'); }
}

export interface CountDraft {
  phase: 'loading' | 'ready' | 'error';
  loadError: string | null;
  count: CountFormCount | null;
  sections: MasterSection[];
  items: MasterItem[];
  entries: EntryMap;
  /** Numbers typed here that the server has not confirmed. */
  unsent: number;
  status: SaveStatus;
  saveError: string | null;
  setEntry: (itemId: number, value: number | null) => void;
  /** Sends what is waiting and says whether the server now has all of it. */
  flushNow: () => Promise<boolean>;
  retrySave: () => void;
  reopen: () => void;
  /** The count ended (submitted or discarded): forget the copy on this device. */
  finish: () => void;
}

/**
 * The Monday count's numbers: React state, a copy on this device, and a debounced, retrying save to the server.
 * A number leaves the "unsent" set only after the server answers with it. While any are unsent the shared busy
 * registry holds the page still, so an app update never reloads over them.
 */
export function useCountDraft({ onForbidden }: { onForbidden: () => void }): CountDraft {
  const [phase, setPhase] = useState<CountDraft['phase']>('loading');
  const [loadError, setLoadError] = useState<string | null>(null);
  const [count, setCount] = useState<CountFormCount | null>(null);
  const [sections, setSections] = useState<MasterSection[]>([]);
  const [items, setItems] = useState<MasterItem[]>([]);
  const [snapshot, setSnapshot] = useState<Snapshot>({ entries: {}, dirty: {} });
  const [status, setStatus] = useState<SaveStatus>('saved');
  const [saveError, setSaveError] = useState<string | null>(null);

  const live = useRef<Snapshot>(snapshot);
  const countId = useRef<string | null>(null);
  const flushing = useRef<Promise<boolean> | null>(null);
  const attempt = useRef(0);
  const timer = useRef<number | null>(null);
  const forbidden = useRef(onForbidden);
  forbidden.current = onForbidden;

  const apply = useCallback((next: Snapshot) => { live.current = next; setSnapshot(next); }, []);
  const cancelTimer = useCallback(() => {
    if (timer.current !== null) { window.clearTimeout(timer.current); timer.current = null; }
  }, []);

  const flushRef = useRef<() => Promise<boolean>>(() => Promise.resolve(true));
  const schedule = useCallback((ms: number) => {
    cancelTimer();
    timer.current = window.setTimeout(() => { timer.current = null; void flushRef.current(); }, ms);
  }, [cancelTimer]);

  const failed = useCallback((failure: unknown): false => {
    if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return false; }
    if (isForbidden(failure)) { forbidden.current(); return false; }
    const offline = failure instanceof EmsApiError && failure.status === 0;
    setStatus(offline ? 'offline' : 'error');
    setSaveError(failureMessage(failure, 'The numbers could not be saved.'));
    if (failure instanceof UnconfirmedSave || isRetryable(failure)) {
      schedule(retryDelayMs(attempt.current));
      attempt.current += 1;
    }
    return false;
  }, [schedule]);

  const doFlush = useCallback(async (): Promise<boolean> => {
    const id = countId.current;
    if (id === null) return false;
    cancelTimer();
    while (dirtyCount(live.current.dirty) > 0) {
      const batch = buildBatch(live.current.entries, live.current.dirty);
      setStatus('saving');
      let confirmed: EntryMap;
      try {
        confirmed = (await saveCountEntries(id, batch, newKey())).entries;
      } catch (failure) {
        return failed(failure);
      }
      if (!savedMatches(batch, confirmed)) return failed(new UnconfirmedSave());
      apply({ entries: live.current.entries, dirty: settleDirty(live.current.dirty, batch, live.current.entries) });
      writeLocalDraft(id, live.current.entries, live.current.dirty);
      attempt.current = 0;
    }
    setUnsavedWork(BUSY_TOKEN, false);
    setStatus('saved');
    setSaveError(null);
    return true;
  }, [apply, cancelTimer, failed]);

  const flush = useCallback((): Promise<boolean> => {
    if (flushing.current) return flushing.current;
    const run: Promise<boolean> = doFlush().finally(() => { if (flushing.current === run) flushing.current = null; });
    flushing.current = run;
    return run;
  }, [doFlush]);
  flushRef.current = flush;

  const setEntry = useCallback((itemId: number, value: number | null) => {
    const id = countId.current;
    if (id === null) return;
    const next = { entries: withEntry(live.current.entries, itemId, value), dirty: withDirty(live.current.dirty, itemId) };
    apply(next);
    writeLocalDraft(id, next.entries, next.dirty);
    setUnsavedWork(BUSY_TOKEN, true);
    setStatus(typeof navigator !== 'undefined' && navigator.onLine === false ? 'offline' : 'saving');
    schedule(DEBOUNCE_MS);
  }, [apply, schedule]);

  const open = useCallback(async () => {
    setPhase('loading');
    setLoadError(null);
    try {
      const started = await startCountForm(newKey());
      const local = readLocalDraft(started.count.id);
      const dirty: Dirty = local?.dirty ?? {};
      countId.current = started.count.id;
      apply({ entries: local ? mergeEntries(started.count.entries, local.entries, dirty) : started.count.entries, dirty });
      setCount(started.count);
      setSections(started.sections);
      setItems(started.items);
      setPhase('ready');
      if (dirtyCount(dirty) > 0) { setUnsavedWork(BUSY_TOKEN, true); setStatus('saving'); void flushRef.current(); } else setStatus('saved');
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      if (isForbidden(failure)) { forbidden.current(); return; }
      setLoadError(failureMessage(failure, 'The count could not be opened.'));
      setPhase('error');
    }
  }, [apply]);

  useEffect(() => { void open(); }, [open]);

  // Send what is waiting when the page is hidden or closed, and when the connection comes back.
  useEffect(() => {
    const sendNow = () => {
      if (dirtyCount(live.current.dirty) === 0 || countId.current === null) return;
      attempt.current = 0;
      void flushRef.current();
    };
    const onVisibility = () => sendNow();
    window.addEventListener('pagehide', sendNow);
    window.addEventListener('online', sendNow);
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      window.removeEventListener('pagehide', sendNow);
      window.removeEventListener('online', sendNow);
      document.removeEventListener('visibilitychange', onVisibility);
      cancelTimer();
      // Leaving the screen: make one last attempt. The copy on this device keeps anything that does not get through.
      if (dirtyCount(live.current.dirty) > 0 && countId.current !== null) void flushRef.current().finally(() => setUnsavedWork(BUSY_TOKEN, false));
      else setUnsavedWork(BUSY_TOKEN, false);
    };
  }, [cancelTimer]);

  const finish = useCallback(() => {
    cancelTimer();
    if (countId.current !== null) clearLocalDraft(countId.current);
    apply({ entries: {}, dirty: {} });
    setUnsavedWork(BUSY_TOKEN, false);
    setStatus('saved');
    setSaveError(null);
  }, [apply, cancelTimer]);

  const retrySave = useCallback(() => { attempt.current = 0; void flush(); }, [flush]);

  return {
    phase, loadError, count, sections, items, entries: snapshot.entries, unsent: dirtyCount(snapshot.dirty), status, saveError,
    setEntry, flushNow: flush, retrySave, reopen: () => void open(), finish,
  };
}
