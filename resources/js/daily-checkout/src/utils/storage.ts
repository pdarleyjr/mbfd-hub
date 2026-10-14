import { InspectionData } from '../types';
import { db } from '../lib/db';

const STORAGE_KEYS = {
  AUTOSAVE: 'mbfd_autosave_inspection',
  SESSION_START: 'mbfd_inspection_session_start',
  SESSION_ABANDON: 'mbfd_inspection_session_abandon',
} as const;

export const createClientSubmissionId = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    const value = character === 'x' ? random : (random & 0x3) | 0x8;

    return value.toString(16);
  });
};

const autosaveKey = (apparatusSlug: string, checklistVersion?: string): string => (
  checklistVersion && checklistVersion.trim() !== ''
    ? `${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}_${checklistVersion}`
    : `${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}`
);

const sessionStartKey = (apparatusSlug: string, checklistVersion: string): string => (
  `${STORAGE_KEYS.SESSION_START}_${apparatusSlug}_${checklistVersion}`
);

const sessionAbandonKey = (apparatusSlug: string, sessionId: string): string => (
  `${STORAGE_KEYS.SESSION_ABANDON}_${apparatusSlug}_${sessionId}`
);

/** Drafts older than this are never restored. */
export const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

/**
 * Delete drafts that are too old to be restored. They were never submitted and
 * are already ignored on load; keeping them only holds photos and signatures
 * on the device. Failure is logged and never blocks loading a draft.
 */
export const pruneExpiredInspectionDrafts = async (now = Date.now()): Promise<number> => {
  try {
    return await db.dailyCheckoutDrafts.where('timestamp').below(now - DRAFT_MAX_AGE_MS).delete();
  } catch (error) {
    console.warn('Expired inspection drafts could not be removed:', error);
    return 0;
  }
};

const isClientUuid = (value: string | null): value is string => (
  value !== null && /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value)
);

const readInspectionProgress = (key: string): InspectionData | null => {
  const saved = localStorage.getItem(key);
  if (!saved) return null;

  const data = JSON.parse(saved) as InspectionData & { timestamp?: unknown };
  const timestamp = typeof data.timestamp === 'number' ? data.timestamp : Number.NaN;
  const hoursSinceAutosave = (Date.now() - timestamp) / (1000 * 60 * 60);
  if (!Number.isFinite(hoursSinceAutosave) || hoursSinceAutosave * 60 * 60 * 1000 > DRAFT_MAX_AGE_MS) {
    localStorage.removeItem(key);
    return null;
  }

  return data;
};

// Autosaves are versioned so a checklist update cannot overwrite or clear the
// older payload. The original unversioned key is read as a legacy candidate but
// is never rewritten by a current checklist.
const draftKey = (apparatusSlug: string, data: InspectionData): string => {
  const owner = data.actorUserId !== undefined && data.actorSecurityVersion !== undefined
    ? `_actor_${data.actorUserId}_${data.actorSecurityVersion}` : '';

  return `${autosaveKey(apparatusSlug, data.checklist_version)}${owner}`;
};

/**
 * Synchronous last-chance copy for page hide/close, when an IndexedDB write may
 * not commit before the document is torn down. It uses the draft's own
 * versioned key, so the loader restores it when it is newer than the
 * IndexedDB draft, and the next successful IndexedDB write removes it. A draft
 * too large for localStorage (several photos) is skipped; the IndexedDB write
 * started at the same moment remains the primary copy.
 */
export const saveInspectionProgressSnapshot = (apparatusSlug: string, data: InspectionData): boolean => {
  try {
    localStorage.setItem(draftKey(apparatusSlug, data), JSON.stringify({ ...data, apparatusSlug, timestamp: Date.now() }));
    return true;
  } catch {
    return false;
  }
};

export const saveInspectionProgress = async (apparatusSlug: string, data: InspectionData): Promise<boolean> => {
  try {
    const saveData = {
      ...data,
      apparatusSlug,
      timestamp: Date.now(),
    };
    const key = draftKey(apparatusSlug, data);
    await db.dailyCheckoutDrafts.put({ key, apparatusSlug, timestamp: saveData.timestamp, data: saveData });
    localStorage.removeItem(key);
    return true;
  } catch (error) {
    console.error('Failed to autosave inspection:', error);
    return false;
  }
};

/** Quiet period before an edit is written to IndexedDB. */
export const AUTOSAVE_DEBOUNCE_MS = 600;

export interface DraftAutosaveRequest {
  apparatusSlug: string;
  data: InspectionData;
  /** Clear this checklist version's session-start key once the draft is durable. */
  clearSessionStartKeyFor?: string;
}

export interface DraftAutosaver {
  /** Remember the latest draft; write it after a pause, or now when `immediate`. */
  schedule(request: DraftAutosaveRequest, immediate?: boolean): void;
  /** Write any remembered draft now; resolves after every queued write settles. */
  flush(): Promise<void>;
  /** Forget an unwritten draft (it was superseded by a durable submission). */
  cancel(): void;
  /**
   * Page hide/close: synchronously keep a copy of any draft not yet confirmed
   * in IndexedDB, then start writing it.
   */
  persistBeforeUnload(): void;
}

/**
 * Debounced, serialized draft writes. A draft with photos and a signature can
 * be several MB, so it is written after a short pause instead of on every
 * keystroke, and writes never overlap or land out of order. Callers flush on
 * page hide, navigation and before submitting so nothing is lost.
 */
export const createDraftAutosaver = (
  onResult: (succeeded: boolean) => void,
  delayMs = AUTOSAVE_DEBOUNCE_MS,
): DraftAutosaver => {
  let pending: DraftAutosaveRequest | null = null;
  let unconfirmed: DraftAutosaveRequest | null = null;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let writes: Promise<void> = Promise.resolve();

  const clearTimer = () => {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  };

  const flush = (): Promise<void> => {
    clearTimer();
    const request = pending;
    pending = null;
    if (request) {
      writes = writes.then(async () => {
        const succeeded = await saveInspectionProgress(request.apparatusSlug, request.data);
        if (succeeded && unconfirmed === request) unconfirmed = null;
        if (succeeded && request.clearSessionStartKeyFor) {
          clearInspectionSessionStartKey(request.apparatusSlug, request.clearSessionStartKeyFor);
        }
        onResult(succeeded);
      });
    }

    return writes;
  };

  return {
    schedule(request, immediate = false) {
      pending = request;
      unconfirmed = request;
      clearTimer();
      if (immediate) {
        void flush();
      } else {
        timer = setTimeout(() => { void flush(); }, delayMs);
      }
    },
    flush,
    cancel() {
      clearTimer();
      pending = null;
      unconfirmed = null;
    },
    persistBeforeUnload() {
      if (unconfirmed) saveInspectionProgressSnapshot(unconfirmed.apparatusSlug, unconfirmed.data);
      void flush();
    },
  };
};

export const loadInspectionProgress = async (
  apparatusSlug: string,
  checklistVersion: string,
  owner?: { userId: number; securityVersion: number },
): Promise<InspectionData | null> => {
  try {
    const key = `${autosaveKey(apparatusSlug, checklistVersion)}${owner ? `_actor_${owner.userId}_${owner.securityVersion}` : ''}`;
    const belongsToOwner = (data: InspectionData) => !owner || (data.actorUserId === owner.userId && data.actorSecurityVersion === owner.securityVersion);
    await pruneExpiredInspectionDrafts();
    const drafts = (await db.dailyCheckoutDrafts.where('apparatusSlug').equals(apparatusSlug).toArray())
      .filter(draft => belongsToOwner(draft.data) && Date.now() - draft.timestamp <= DRAFT_MAX_AGE_MS)
      .sort((left, right) => right.timestamp - left.timestamp);
    const exact = drafts.find(draft => draft.key === key);
    // localStorage under the same key holds either a page-hide snapshot that
    // may be newer than IndexedDB, or an old pre-IndexedDB autosave.
    const legacyExact = readInspectionProgress(key);
    const legacyTimestamp = Number((legacyExact as (InspectionData & { timestamp?: unknown }) | null)?.timestamp) || 0;
    if (exact && (!legacyExact || !belongsToOwner(legacyExact) || exact.timestamp >= legacyTimestamp)) {
      if (legacyExact && belongsToOwner(legacyExact)) localStorage.removeItem(key);
      return exact.data;
    }

    if (legacyExact && belongsToOwner(legacyExact)) {
      if (await saveInspectionProgress(apparatusSlug, legacyExact)) localStorage.removeItem(key);
      return legacyExact;
    }
    if (drafts[0]) return drafts[0].data;

    const prefix = `${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}_`;
    const versioned = Array.from({ length: localStorage.length }, (_, index) => localStorage.key(index))
      .filter((key): key is string => key !== null && key.startsWith(prefix))
      .map((key) => ({ key, data: readInspectionProgress(key) }))
      .filter((entry): entry is { key: string; data: InspectionData } => entry.data !== null)
      .filter(entry => belongsToOwner(entry.data))
      .sort((left, right) => {
        const leftTimestamp = Number((left.data as InspectionData & { timestamp?: unknown }).timestamp) || 0;
        const rightTimestamp = Number((right.data as InspectionData & { timestamp?: unknown }).timestamp) || 0;

        return rightTimestamp - leftTimestamp;
      });

    const legacy = versioned[0] ?? { key: autosaveKey(apparatusSlug), data: readInspectionProgress(autosaveKey(apparatusSlug)) };
    if (!legacy.data || !belongsToOwner(legacy.data)) return null;
    if (await saveInspectionProgress(apparatusSlug, legacy.data)) localStorage.removeItem(legacy.key);
    return legacy.data;
  } catch (error) {
    console.error('Failed to load autosaved inspection:', error);
    throw new Error('Saved inspection data could not be read. Reload this page before continuing; existing saved work has not been replaced.');
  }
};

export const clearInspectionProgress = async (apparatusSlug: string, checklistVersion: string, owner?: { userId: number; securityVersion: number } | null): Promise<void> => {
  try {
    const key = `${autosaveKey(apparatusSlug, checklistVersion)}${owner ? `_actor_${owner.userId}_${owner.securityVersion}` : ''}`;
    await db.dailyCheckoutDrafts.delete(key);
    localStorage.removeItem(key);
    localStorage.removeItem(sessionStartKey(apparatusSlug, checklistVersion));
  } catch (error) {
    console.error('Failed to clear autosaved inspection:', error);
  }
};

// This local key only makes the start request idempotent when a connection
// fails after the server persists a contract but before the browser receives
// its response. It is cleared after the issued contract is autosaved.
export const getOrCreateInspectionSessionStartKey = (apparatusSlug: string, checklistVersion: string): string => {
  try {
    const key = sessionStartKey(apparatusSlug, checklistVersion);
    const existing = localStorage.getItem(key);
    if (isClientUuid(existing)) {
      return existing;
    }

    const next = createClientSubmissionId();
    localStorage.setItem(key, next);

    return next;
  } catch (error) {
    console.error('Failed to persist Daily Checkout session-start key:', error);

    return createClientSubmissionId();
  }
};

export const clearInspectionSessionStartKey = (apparatusSlug: string, checklistVersion: string) => {
  try {
    localStorage.removeItem(sessionStartKey(apparatusSlug, checklistVersion));
  } catch (error) {
    console.error('Failed to clear Daily Checkout session-start key:', error);
  }
};

// The transition key is persisted only to make a lost abandonment response
// replay safely. It is never an authorization credential; the server still
// requires the issued contract token, replay key, and browser binding.
export const getOrCreateInspectionSessionAbandonKey = (apparatusSlug: string, sessionId: string): string => {
  try {
    const key = sessionAbandonKey(apparatusSlug, sessionId);
    const existing = localStorage.getItem(key);
    if (isClientUuid(existing)) {
      return existing;
    }

    const next = createClientSubmissionId();
    localStorage.setItem(key, next);

    return next;
  } catch (error) {
    console.error('Failed to persist Daily Checkout session-abandon key:', error);

    return createClientSubmissionId();
  }
};

export const clearInspectionSessionAbandonKey = (apparatusSlug: string, sessionId: string) => {
  try {
    localStorage.removeItem(sessionAbandonKey(apparatusSlug, sessionId));
  } catch (error) {
    console.error('Failed to clear Daily Checkout session-abandon key:', error);
  }
};
