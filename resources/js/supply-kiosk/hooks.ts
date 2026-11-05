// Small timer hooks for a screen that never stops running.
import { useEffect, useRef, useState } from 'react';
import type { MutableRefObject } from 'react';

/** setInterval that always calls the latest callback. `null` pauses it. */
export function useInterval(callback: () => void, ms: number | null): void {
    const saved = useRef(callback);
    useEffect(() => { saved.current = callback; }, [callback]);
    useEffect(() => {
        if (ms === null) return undefined;
        const id = window.setInterval(() => saved.current(), ms);
        return () => window.clearInterval(id);
    }, [ms]);
}

/** Re-renders every second and returns the current time, for countdowns. */
export function useNow(active: boolean): number {
    const [now, setNow] = useState(() => Date.now());
    useInterval(() => setNow(Date.now()), active ? 1000 : null);
    return now;
}

/** The time of the last touch, key or typing anywhere on the page. */
export function useLastActivity(onActivity?: () => void): MutableRefObject<number> {
    const last = useRef(Date.now());
    const handler = useRef(onActivity);
    useEffect(() => { handler.current = onActivity; }, [onActivity]);
    useEffect(() => {
        const mark = () => { last.current = Date.now(); handler.current?.(); };
        const events = ['pointerdown', 'keydown', 'input', 'wheel'] as const;
        events.forEach(name => window.addEventListener(name, mark, { capture: true, passive: true }));
        return () => events.forEach(name => window.removeEventListener(name, mark, { capture: true }));
    }, []);
    return last;
}
