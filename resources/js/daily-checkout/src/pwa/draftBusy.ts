import { clearBusy, markBusy } from '../../../pwa/busy.ts';

/**
 * Daily Checkout's side of the shared "busy" registry (resources/js/pwa/busy.ts).
 *
 * Several independent sources can hold unsaved work at once (an inspection
 * autosaver, a command message being typed, an ordinary form). Each one owns a
 * token; the page-wide 'unsaved-draft' reason is held while ANY token is dirty, so
 * one source finishing never clears the reason for another.
 */
export const UNSAVED_DRAFT = 'unsaved-draft';
export const QUEUED_SUBMISSION = 'queued-submission';

const dirtyTokens = new Set<symbol | string>();

/** Mark one source dirty or clean. Idempotent. */
export function setUnsavedWork(token: symbol | string, dirty: boolean): void {
    if (dirty) dirtyTokens.add(token);
    else dirtyTokens.delete(token);

    if (dirtyTokens.size > 0) markBusy(UNSAVED_DRAFT);
    else clearBusy(UNSAVED_DRAFT);
}

export function hasUnsavedWork(): boolean {
    return dirtyTokens.size > 0;
}

/** Test helper. */
export function resetUnsavedWork(): void {
    dirtyTokens.clear();
    clearBusy(UNSAVED_DRAFT);
}
