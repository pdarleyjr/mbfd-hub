import { countStorageKey, parseDraft, serializeDraft, type Dirty } from './logic';
import type { EntryMap } from './types';

/**
 * The copy of the count that lives on this device. Every access can fail (private windows, blocked or full storage),
 * so each one is wrapped and the count carries on without the copy.
 */
export function readLocalDraft(countId: string): { entries: EntryMap; dirty: Dirty } | null {
  try {
    return parseDraft(window.localStorage.getItem(countStorageKey(countId)));
  } catch {
    return null;
  }
}

export function writeLocalDraft(countId: string, entries: Readonly<EntryMap>, dirty: Dirty): void {
  try {
    window.localStorage.setItem(countStorageKey(countId), serializeDraft(entries, dirty));
  } catch {
    // Storage is unavailable or full; the server copy still gets the numbers.
  }
}

export function clearLocalDraft(countId: string): void {
  try {
    window.localStorage.removeItem(countStorageKey(countId));
  } catch {
    // Nothing to clear.
  }
}
