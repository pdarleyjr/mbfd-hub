import { InspectionData, InspectionSubmission } from '../types';

const STORAGE_KEYS = {
  AUTOSAVE: 'mbfd_autosave_inspection',
  QUEUE: 'mbfd_submission_queue',
} as const;

const DB_NAME = 'mbfd_checkout_db';
const DB_VERSION = 1;
const QUEUE_STORE = 'submission_queue';

// IndexedDB setup for robust offline storage
let dbInstance: IDBDatabase | null = null;

const openDB = (): Promise<IDBDatabase> => {
  return new Promise((resolve, reject) => {
    if (dbInstance) {
      resolve(dbInstance);
      return;
    }

    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      dbInstance = request.result;
      resolve(dbInstance);
    };

    request.onupgradeneeded = (event) => {
      const db = (event.target as IDBOpenDBRequest).result;
      if (!db.objectStoreNames.contains(QUEUE_STORE)) {
        db.createObjectStore(QUEUE_STORE, { keyPath: 'id' });
      }
    };
  });
};

// Autosave functionality (using localStorage for simplicity)
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

// Offline submission queue (using IndexedDB for larger storage)
interface QueuedSubmission {
  id: string;
  apparatusId: number;
  data: InspectionSubmission;
  timestamp: number;
}

export const queueSubmission = async (apparatusId: number, data: InspectionSubmission): Promise<string> => {
  try {
    const db = await openDB();
    const id = `sub_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    
    const submission: QueuedSubmission = {
      id,
      apparatusId,
      data,
      timestamp: Date.now(),
    };

    return new Promise((resolve, reject) => {
      const transaction = db.transaction([QUEUE_STORE], 'readwrite');
      const store = transaction.objectStore(QUEUE_STORE);
      const request = store.add(submission);

      request.onsuccess = () => resolve(id);
      request.onerror = () => reject(request.error);
    });
  } catch (error) {
    console.error('Failed to queue submission:', error);
    // Fallback to localStorage
    try {
      const queue = getSubmissionQueueSync();
      const id = `sub_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
      queue.push({
        id,
        apparatusId,
        data,
        timestamp: Date.now(),
      });
      localStorage.setItem(STORAGE_KEYS.QUEUE, JSON.stringify(queue));
      return id;
    } catch (fallbackError) {
      throw fallbackError;
    }
  }
};

export const getSubmissionQueue = async (): Promise<QueuedSubmission[]> => {
  try {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction([QUEUE_STORE], 'readonly');
      const store = transaction.objectStore(QUEUE_STORE);
      const request = store.getAll();

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  } catch (error) {
    console.error('Failed to get submission queue from IndexedDB, falling back to localStorage:', error);
    return getSubmissionQueueSync();
  }
};

// Synchronous fallback for localStorage
const getSubmissionQueueSync = (): QueuedSubmission[] => {
  try {
    const queue = localStorage.getItem(STORAGE_KEYS.QUEUE);
    return queue ? JSON.parse(queue) : [];
  } catch (error) {
    console.error('Failed to get submission queue:', error);
    return [];
  }
};

export const removeFromQueue = async (id: string): Promise<void> => {
  try {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction([QUEUE_STORE], 'readwrite');
      const store = transaction.objectStore(QUEUE_STORE);
      const request = store.delete(id);

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  } catch (error) {
    console.error('Failed to remove from queue, trying localStorage fallback:', error);
    // Fallback to localStorage
    try {
      const queue = getSubmissionQueueSync().filter(item => item.id !== id);
      localStorage.setItem(STORAGE_KEYS.QUEUE, JSON.stringify(queue));
    } catch (fallbackError) {
      console.error('Failed to remove from localStorage queue:', fallbackError);
    }
  }
};

export const clearQueue = async (): Promise<void> => {
  try {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const transaction = db.transaction([QUEUE_STORE], 'readwrite');
      const store = transaction.objectStore(QUEUE_STORE);
      const request = store.clear();

      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  } catch (error) {
    console.error('Failed to clear queue:', error);
    // Fallback to localStorage
    try {
      localStorage.removeItem(STORAGE_KEYS.QUEUE);
    } catch (fallbackError) {
      console.error('Failed to clear localStorage queue:', fallbackError);
    }
  }
};
