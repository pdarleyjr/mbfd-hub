// Undos that could not reach the Hub. Each is retried on every flush (after the withdrawal queue, never before the
// withdrawal it cancels) until the Hub answers 200 (put back), 404 (never arrived) or 409 (too late). A 409, or a
// day without an answer, keeps the entry as "failed" so the ID screen can tell people to tell Captain 5.
import { backoffMs, GIVE_UP_AFTER_MS, UNREACHABLE_MESSAGE } from './queue.ts';

export interface PendingUndo {
    uuid: string;
    member_name: string;
    taken_at: number;
    attempts: number;
    next_try_at: number;
    failed: { message: string; at: number } | null;
}

const KEY = 'mbfd.supplyKiosk.undoLater';
let memory: PendingUndo[] | null = null;

export function pendingUndos(): PendingUndo[] {
    if (memory) return [...memory];
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
        return Array.isArray(parsed) ? (parsed as PendingUndo[]).filter(entry => typeof entry?.uuid === 'string') : [];
    } catch {
        return [];
    }
}

function save(entries: PendingUndo[]): void {
    try {
        if (entries.length === 0) window.localStorage.removeItem(KEY);
        else window.localStorage.setItem(KEY, JSON.stringify(entries));
        memory = null;
    } catch (error) {
        console.warn('Supply kiosk: could not save the undo list; keeping it in memory.', error);
        memory = entries;
    }
}

export function addPendingUndo(uuid: string, memberName: string, takenAt: number): void {
    const entry: PendingUndo = { uuid, member_name: memberName, taken_at: takenAt, attempts: 0, next_try_at: 0, failed: null };
    save([...pendingUndos().filter(existing => existing.uuid !== uuid), entry]);
}

export function removePendingUndo(uuid: string): void {
    save(pendingUndos().filter(entry => entry.uuid !== uuid));
}

export type UndoOutcome = { kind: 'done' } | { kind: 'retry' } | { kind: 'failed'; message: string };

/**
 * Retry each waiting undo. `blocked(uuid)` is true while the withdrawal itself is still waiting to be sent or is being
 * sent; that undo waits so it can never reach the Hub first.
 */
export async function flushUndos(sendUndo: (uuid: string) => Promise<UndoOutcome>, blocked: (uuid: string) => Promise<boolean>, { force = false } = {}): Promise<void> {
    for (const entry of pendingUndos()) {
        if (entry.failed || (!force && entry.next_try_at > Date.now()) || await blocked(entry.uuid)) continue;
        const outcome = await sendUndo(entry.uuid);
        const current = pendingUndos().filter(other => other.uuid !== entry.uuid);
        if (outcome.kind === 'done') save(current);
        else if (outcome.kind === 'failed') save([...current, { ...entry, failed: { message: outcome.message, at: Date.now() } }]);
        else if (Date.now() - entry.taken_at >= GIVE_UP_AFTER_MS) save([...current, { ...entry, failed: { message: UNREACHABLE_MESSAGE, at: Date.now() } }]);
        else save([...current, { ...entry, attempts: entry.attempts + 1, next_try_at: Date.now() + backoffMs(entry.attempts + 1) }]);
    }
}
