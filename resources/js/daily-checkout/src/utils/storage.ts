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

const isClientUuid = (value: string | null): value is string => (
  value !== null && /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value)
);

const readInspectionProgress = (key: string): InspectionData | null => {
  const saved = localStorage.getItem(key);
  if (!saved) return null;

  const data = JSON.parse(saved) as InspectionData & { timestamp?: unknown };
  const timestamp = typeof data.timestamp === 'number' ? data.timestamp : Number.NaN;
  const hoursSinceAutosave = (Date.now() - timestamp) / (1000 * 60 * 60);
  if (!Number.isFinite(hoursSinceAutosave) || hoursSinceAutosave > 24) {
    localStorage.removeItem(key);
    return null;
  }

  return data;
};

// Autosaves are versioned so a checklist update cannot overwrite or clear the
// older payload. The original unversioned key is read as a legacy candidate but
// is never rewritten by a current checklist.
export const saveInspectionProgress = async (apparatusSlug: string, data: InspectionData): Promise<boolean> => {
  try {
    const saveData = {
      ...data,
      apparatusSlug,
      timestamp: Date.now(),
    };
    const owner = data.actorUserId !== undefined && data.actorSecurityVersion !== undefined
      ? `_actor_${data.actorUserId}_${data.actorSecurityVersion}` : '';
    const key = `${autosaveKey(apparatusSlug, data.checklist_version)}${owner}`;
    await db.dailyCheckoutDrafts.put({ key, apparatusSlug, timestamp: saveData.timestamp, data: saveData });
    localStorage.removeItem(key);
    return true;
  } catch (error) {
    console.error('Failed to autosave inspection:', error);
    return false;
  }
};

export const loadInspectionProgress = async (
  apparatusSlug: string,
  checklistVersion: string,
  owner?: { userId: number; securityVersion: number },
): Promise<InspectionData | null> => {
  try {
    const key = `${autosaveKey(apparatusSlug, checklistVersion)}${owner ? `_actor_${owner.userId}_${owner.securityVersion}` : ''}`;
    const belongsToOwner = (data: InspectionData) => !owner || (data.actorUserId === owner.userId && data.actorSecurityVersion === owner.securityVersion);
    const drafts = (await db.dailyCheckoutDrafts.where('apparatusSlug').equals(apparatusSlug).toArray())
      .filter(draft => belongsToOwner(draft.data) && Date.now() - draft.timestamp <= 24 * 60 * 60 * 1000)
      .sort((left, right) => right.timestamp - left.timestamp);
    const exact = drafts.find(draft => draft.key === key);
    if (exact) return exact.data;

    const legacyExact = readInspectionProgress(key);
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
