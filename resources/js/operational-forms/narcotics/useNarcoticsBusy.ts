import { useEffect } from 'react';
import { clearBusy, markBusy } from '../../pwa/busy';
import { BUSY_REASON, isUnsafeToRefresh, type FormPhase } from './narcoticsImage.js';

/**
 * Tells the Hub's refresh guard what the narcotics form is holding, so the update banner cannot reload
 * the page over a photo that exists only in this tab:
 *   dirty -> saving -> saved   (safe again) or failed (stays unsafe until sent or discarded).
 * The photo is held in memory only; it is deliberately never written to device storage.
 * Also asks the browser to confirm closing the tab while the photo is unsent.
 */
export function useNarcoticsBusy(phase: FormPhase): void {
    useEffect(() => {
        if (isUnsafeToRefresh(phase)) markBusy(BUSY_REASON);
        else clearBusy(BUSY_REASON);
    }, [phase]);

    useEffect(() => () => clearBusy(BUSY_REASON), []);

    useEffect(() => {
        if (!isUnsafeToRefresh(phase)) return undefined;
        const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [phase]);
}
