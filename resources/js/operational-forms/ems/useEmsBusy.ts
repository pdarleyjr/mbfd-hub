import { useEffect } from 'react';
import { clearBusy, markBusy } from '../../pwa/busy';

export const EMS_DRAFT_BUSY = 'ems-draft';
export const EMS_OUTBOX_BUSY = 'ems-outbox';

/**
 * Tells the Hub's refresh guard what the Medical Inventory Request is holding, so the update banner never
 * reloads the page over work a member would have to redo:
 *   - an unfinished request (quantities, unit choice, note, event text, or entries carried for retired items)
 *   - a send in flight
 * Clears when the request is sent, cleared or the form closes. Also asks the browser to confirm closing the
 * tab while the note or event text (the only parts not kept on the device) would be lost.
 */
export function useEmsDraftBusy({ unsaved, sending, textAtRisk }: { unsaved: boolean; sending: boolean; textAtRisk: boolean }): void {
    const busy = unsaved || sending;
    useEffect(() => {
        if (busy) markBusy(EMS_DRAFT_BUSY);
        else clearBusy(EMS_DRAFT_BUSY);
    }, [busy]);

    useEffect(() => () => clearBusy(EMS_DRAFT_BUSY), []);

    useEffect(() => {
        if (!textAtRisk) return undefined;
        const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [textAtRisk]);
}

/** Requests waiting on this device to be sent (offline, signed out, or a server hiccup) block a refresh until they are sent. */
export function useEmsOutboxBusy(pending: number): void {
    const busy = pending > 0;
    useEffect(() => {
        if (busy) markBusy(EMS_OUTBOX_BUSY);
        else clearBusy(EMS_OUTBOX_BUSY);
    }, [busy]);

    useEffect(() => () => clearBusy(EMS_OUTBOX_BUSY), []);
}
