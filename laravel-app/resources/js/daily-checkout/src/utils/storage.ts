import { InspectionData, InspectionSubmission } from '../types';

const STORAGE_KEYS = {
  AUTOSAVE: 'mbfd_autosave_inspection',
  QUEUE: 'mbfd_submission_queue',
} as const;

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

// Offline submission queue
interface QueuedSubmission {
  id: string;
  apparatusId: number;
  data: InspectionSubmission;
  timestamp: number;
}

export const queueSubmission = (apparatusId: number, data: InspectionSubmission): string => {
  try {
    const queue = getSubmissionQueue();
    const id = `sub_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    
    queue.push({
      id,
      apparatusId,
      data,
      timestamp: Date.now(),
    });
    
    localStorage.setItem(STORAGE_KEYS.QUEUE, JSON.stringify(queue));
    return id;
  } catch (error) {
    console.error('Failed to queue submission:', error);
    throw error;
  }
};

export const getSubmissionQueue = (): QueuedSubmission[] => {
  try {
    const queue = localStorage.getItem(STORAGE_KEYS.QUEUE);
    return queue ? JSON.parse(queue) : [];
  } catch (error) {
    console.error('Failed to get submission queue:', error);
    return [];
  }
};

export const removeFromQueue = (id: string) => {
  try {
    const queue = getSubmissionQueue().filter(item => item.id !== id);
    localStorage.setItem(STORAGE_KEYS.QUEUE, JSON.stringify(queue));
  } catch (error) {
    console.error('Failed to remove from queue:', error);
  }
};

export const clearQueue = () => {
  try {
    localStorage.removeItem(STORAGE_KEYS.QUEUE);
  } catch (error) {
    console.error('Failed to clear queue:', error);
  }
};
