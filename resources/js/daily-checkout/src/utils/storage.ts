import { InspectionData, MeterData } from '../types';

const STORAGE_KEYS = {
  AUTOSAVE: 'mbfd_autosave_inspection',
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
export const saveInspectionProgress = (apparatusSlug: string, data: InspectionData) => {
  try {
    const saveData = {
      ...data,
      apparatusSlug,
      timestamp: Date.now(),
    };
    localStorage.setItem(autosaveKey(apparatusSlug, data.checklist_version), JSON.stringify(saveData));
  } catch (error) {
    console.error('Failed to autosave inspection:', error);
  }
};

export const loadInspectionProgress = (
  apparatusSlug: string,
  checklistVersion: string,
): InspectionData | null => {
  try {
    const exact = readInspectionProgress(autosaveKey(apparatusSlug, checklistVersion));
    if (exact) return exact;

    const legacy = readInspectionProgress(autosaveKey(apparatusSlug));
    if (legacy) return legacy;

    const prefix = `${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}_`;
    const versioned = Array.from({ length: localStorage.length }, (_, index) => localStorage.key(index))
      .filter((key): key is string => key !== null && key.startsWith(prefix))
      .map((key) => readInspectionProgress(key))
      .filter((data): data is InspectionData => data !== null)
      .sort((left, right) => {
        const leftTimestamp = Number((left as InspectionData & { timestamp?: unknown }).timestamp) || 0;
        const rightTimestamp = Number((right as InspectionData & { timestamp?: unknown }).timestamp) || 0;

        return rightTimestamp - leftTimestamp;
      });

    return versioned[0] ?? null;
  } catch (error) {
    console.error('Failed to load autosaved inspection:', error);
    return null;
  }
};

export const clearInspectionProgress = (apparatusSlug: string, checklistVersion: string) => {
  try {
    localStorage.removeItem(autosaveKey(apparatusSlug, checklistVersion));
  } catch (error) {
    console.error('Failed to clear autosaved inspection:', error);
  }
};
