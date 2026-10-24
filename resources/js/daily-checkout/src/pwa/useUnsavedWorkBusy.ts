import { useEffect, useRef } from 'react';
import { setUnsavedWork } from './draftBusy.ts';

/**
 * Hold the page-wide 'unsaved-draft' busy reason while `dirty` is true and for as
 * long as the calling component stays mounted. The effect that sets the state has
 * no cleanup of its own (a cleanup would flash "clean" on every change and could
 * let an update reload through); only unmounting releases the token.
 */
export function useUnsavedWorkBusy(dirty: boolean): void {
    const token = useRef<symbol>(Symbol('unsaved-work'));

    useEffect(() => {
        setUnsavedWork(token.current, dirty);
    }, [dirty]);

    useEffect(() => {
        const current = token.current;

        return () => setUnsavedWork(current, false);
    }, []);
}
