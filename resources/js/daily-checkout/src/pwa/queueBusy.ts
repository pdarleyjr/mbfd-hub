import { clearBusy, markBusy } from '../../../pwa/busy.ts';
import { QUEUED_SUBMISSION } from './draftBusy.ts';

/** While anything is waiting, re-count now and then in case a change raised no event. */
const SAFETY_RECOUNT_MS = 20_000;
/** A count that cannot be read is retried quickly: until it is known, the page must not be reloaded. */
const UNKNOWN_RETRY_MS = 5_000;
/** A count that never answers (a blocked IndexedDB upgrade) counts as a failure. */
const COUNT_TIMEOUT_MS = 10_000;
/**
 * After this many failures in a row IndexedDB is evidently unavailable, so there is no queue
 * to protect and the page is no longer held (otherwise it could never be refreshed).
 */
const MAX_UNKNOWN_FAILURES = 3;

export interface QueueBusySources {
    /** Records that will still be sent (pending, not parked for attention). */
    countPending: () => Promise<number>;
    /** Subscribe to "the queues changed"; returns the unsubscribe function. */
    subscribe: (listener: () => void) => () => void;
}

function withTimeout<T>(promise: Promise<T>, timeoutMs: number): Promise<T> {
    return new Promise<T>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('The queue could not be counted in time.')), timeoutMs);
        promise.then(
            (value) => { clearTimeout(timer); resolve(value); },
            (error: unknown) => { clearTimeout(timer); reject(error); },
        );
    });
}

/**
 * Hold the page-wide 'queued-submission' busy reason while the offline queue has
 * records that still have to be sent, and release it the moment it drains.
 *
 * Records that need a person's decision (requires_attention) are NOT counted: they
 * never send by themselves, so counting them would pin the page busy forever and
 * keep an update from ever applying. A count that cannot be read keeps the page
 * busy (the safe answer) and is retried; only a run of failures releases it.
 */
export function startQueueBusyWatcher({ countPending, subscribe }: QueueBusySources): () => void {
    let disposed = false;
    let generation = 0;
    let failures = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const schedule = (delayMs: number | undefined) => {
        if (timer !== undefined) clearTimeout(timer);
        timer = delayMs === undefined ? undefined : setTimeout(() => { void refresh(); }, delayMs);
    };

    const refresh = async (): Promise<void> => {
        const mine = ++generation;
        let pending: number;
        try {
            pending = await withTimeout(countPending(), COUNT_TIMEOUT_MS);
        } catch {
            if (disposed || mine !== generation) return;
            failures += 1;
            if (failures <= MAX_UNKNOWN_FAILURES) {
                markBusy(QUEUED_SUBMISSION);
                schedule(UNKNOWN_RETRY_MS);
            } else {
                clearBusy(QUEUED_SUBMISSION);
                schedule(SAFETY_RECOUNT_MS);
            }

            return;
        }
        // A newer count started while this one ran; only the newest may decide.
        if (disposed || mine !== generation) return;
        failures = 0;

        if (pending > 0) markBusy(QUEUED_SUBMISSION);
        else clearBusy(QUEUED_SUBMISSION);
        schedule(pending > 0 ? SAFETY_RECOUNT_MS : undefined);
    };

    const trigger = () => { void refresh(); };
    const onVisibility = () => {
        if (document.visibilityState === 'visible') trigger();
    };

    const unsubscribe = subscribe(trigger);
    window.addEventListener('online', trigger);
    document.addEventListener('visibilitychange', onVisibility);
    trigger();

    return () => {
        disposed = true;
        schedule(undefined);
        unsubscribe();
        window.removeEventListener('online', trigger);
        document.removeEventListener('visibilitychange', onVisibility);
        clearBusy(QUEUED_SUBMISSION);
    };
}
