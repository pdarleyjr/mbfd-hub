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

// Autosave functionality
export const saveInspectionProgress = (apparatusSlug: string, data: InspectionData) => {
  try {
    const saveData = {
      ...data,
      apparatusSlug,
      timestamp: Date.now(),
    };
    localStorage.setItem(`${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}`, JSON.stringify(saveData));
  } catch (error) {
    console.error('Failed to autosave inspection:', error);
  }
};

export const loadInspectionProgress = (apparatusSlug: string): InspectionData | null => {
  try {
    const saved = localStorage.getItem(`${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}`);
    if (!saved) return null;
    
    const data = JSON.parse(saved);
    
    // Check if autosave is less than 24 hours old
    const hoursSinceAutosave = (Date.now() - data.timestamp) / (1000 * 60 * 60);
    if (hoursSinceAutosave > 24) {
      clearInspectionProgress(apparatusSlug);
      return null;
    }
    
    return data;
  } catch (error) {
    console.error('Failed to load autosaved inspection:', error);
    return null;
  }
};

export const clearInspectionProgress = (apparatusSlug: string) => {
  try {
    localStorage.removeItem(`${STORAGE_KEYS.AUTOSAVE}_${apparatusSlug}`);
  } catch (error) {
    console.error('Failed to clear autosaved inspection:', error);
  }
};
