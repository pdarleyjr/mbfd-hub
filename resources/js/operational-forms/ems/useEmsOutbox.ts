import { useCallback, useEffect, useRef, useState } from 'react';
import { EmsApiError, type EmsApi } from './emsApi';
import { classifyFailure } from './emsLogic.js';
import { emsOutbox } from './emsStore';
import type { EmsOutboxEntry } from './emsTypes';

export interface SentNotice {
    id: string;
    number: string;
    unitLabel: string;
}

/**
 * Requests that could not reach the server. They stay on this device, are retried with the same
 * client_submission_id when the connection returns (the `online` event) and whenever the Forms app opens,
 * and are removed only after the server confirms them. The server dedupes on that id, so a retry can never
 * create a second request.
 */
export function useEmsOutbox(api: EmsApi | null, owner: string, onSent: () => void) {
    const [entries, setEntries] = useState<EmsOutboxEntry[]>([]);
    const [sent, setSent] = useState<SentNotice[]>([]);
    const [flushing, setFlushing] = useState(false);
    const busy = useRef(false);
    const onSentRef = useRef(onSent);
    useEffect(() => { onSentRef.current = onSent; }, [onSent]);

    const reload = useCallback(async () => { setEntries(await emsOutbox.list(owner)); }, [owner]);

    const flush = useCallback(async () => {
        if (!api || busy.current) return;
        busy.current = true;
        setFlushing(true);
        try {
            const pending = (await emsOutbox.list(owner)).filter((entry) => entry.state === 'pending');
            for (const entry of pending) {
                try {
                    const result = await api.submit(entry.payload);
                    await emsOutbox.remove(entry.clientSubmissionId);
                    setSent((list) => [...list.filter((item) => item.id !== result.request.id), { id: result.request.id, number: result.request.number, unitLabel: result.request.target_label }]);
                    onSentRef.current();
                } catch (error) {
                    if (error instanceof EmsApiError && classifyFailure({ status: error.status, code: error.code, network: error.network }) === 'message') {
                        // The server refused it for good (for example an item left the form). Keep it so the member can fix it.
                        await emsOutbox.put(owner, { ...entry, state: 'rejected', message: error.message });
                        continue;
                    }
                    if (!(error instanceof EmsApiError)) console.error('A queued medical request could not be sent.', error);
                    break; // still offline, signed out or busy: try again later
                }
            }
        } finally {
            busy.current = false;
            setFlushing(false);
            await reload();
        }
    }, [api, owner, reload]);

    useEffect(() => {
        void reload().then(() => { if (navigator.onLine) void flush(); });
        const online = () => void flush();
        const visible = () => { if (document.visibilityState === 'visible' && navigator.onLine) void flush(); };
        window.addEventListener('online', online);
        document.addEventListener('visibilitychange', visible);
        return () => { window.removeEventListener('online', online); document.removeEventListener('visibilitychange', visible); };
    }, [reload, flush]);

    // A server hiccup or a throttle leaves a request waiting with the connection up: look again every minute while the page is in view.
    const hasPending = entries.some((entry) => entry.state === 'pending');
    useEffect(() => {
        if (!hasPending) return;
        const timer = window.setInterval(() => { if (document.visibilityState === 'visible' && navigator.onLine) void flush(); }, 60_000);
        return () => window.clearInterval(timer);
    }, [hasPending, flush]);

    /** Returns false when this browser cannot keep it (storage blocked), so the caller can say so. */
    const enqueue = useCallback(async (entry: EmsOutboxEntry) => {
        const saved = await emsOutbox.put(owner, entry);
        await reload();
        return saved;
    }, [owner, reload]);

    const discard = useCallback(async (clientSubmissionId: string) => {
        await emsOutbox.remove(clientSubmissionId);
        await reload();
    }, [reload]);

    const dismissSent = useCallback((id: string) => setSent((list) => list.filter((item) => item.id !== id)), []);

    return { entries, sent, flushing, flush, enqueue, discard, dismissSent };
}

export type EmsOutbox = ReturnType<typeof useEmsOutbox>;
