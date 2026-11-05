// Withdrawals that could not reach the Hub wait here, keyed by client_uuid, and are sent again with the same uuid.
// IndexedDB first, localStorage if IndexedDB is unavailable, memory as a last resort. Every storage call is guarded.
// Each entry backs off on its own, so one that keeps failing never holds up the ones after it. An entry the Hub
// refuses, or one that could not be sent for 24 hours, stays here as "refused" (never sent again) until a person
// on the ID screen says Captain 5 was told.
import type { WithdrawalBody } from './types.ts';

export interface QueuedWithdrawal {
    client_uuid: string;
    body: WithdrawalBody;
    queued_at: number;
    member_name?: string;
    attempts?: number;
    next_try_at?: number;
    refused?: { message: string; at: number } | null;
}

export const GIVE_UP_AFTER_MS = 24 * 60 * 60_000;
export const UNREACHABLE_MESSAGE = 'The Hub could not be reached';
const BACKOFF_FIRST_MS = 30_000;
const BACKOFF_MAX_MS = 15 * 60_000;

/** 30 s, 1 min, 2 min ... up to 15 min between tries of one entry. */
export function backoffMs(attempts: number): number {
    return Math.min(BACKOFF_MAX_MS, BACKOFF_FIRST_MS * 2 ** Math.max(0, attempts - 1));
}

const DB_NAME = 'mbfd-supply-kiosk';
const STORE = 'withdrawals';
const LS_KEY = 'mbfd.supplyKiosk.queue';
const memory = new Map<string, QueuedWithdrawal>();
let dbPromise: Promise<IDBDatabase | null> | null = null;

function openDb(): Promise<IDBDatabase | null> {
    dbPromise ??= new Promise<IDBDatabase | null>(resolve => {
        try {
            if (typeof indexedDB === 'undefined') { resolve(null); return; }
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE, { keyPath: 'client_uuid' });
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve(null);
            request.onblocked = () => resolve(null);
        } catch {
            resolve(null);
        }
    });
    return dbPromise;
}

function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    return openDb().then(db => new Promise<T>((resolve, reject) => {
        if (!db) { reject(new Error('IndexedDB unavailable')); return; }
        try {
            const request = action(db.transaction(STORE, mode).objectStore(STORE));
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error ?? new Error('IndexedDB request failed'));
        } catch (error) {
            reject(error);
        }
    }));
}

function readLocal(): Record<string, QueuedWithdrawal> {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(LS_KEY) ?? '{}');
        return parsed && typeof parsed === 'object' ? (parsed as Record<string, QueuedWithdrawal>) : {};
    } catch {
        return {};
    }
}

function writeLocal(entries: Record<string, QueuedWithdrawal>): boolean {
    try {
        if (Object.keys(entries).length === 0) window.localStorage.removeItem(LS_KEY);
        else window.localStorage.setItem(LS_KEY, JSON.stringify(entries));
        return true;
    } catch {
        return false;
    }
}

function dropLocal(uuid: string): void {
    const local = readLocal();
    if (!(uuid in local)) return;
    const rest = { ...local };
    delete rest[uuid];
    writeLocal(rest);
}

export async function queuePut(entry: QueuedWithdrawal): Promise<void> {
    memory.set(entry.client_uuid, entry);
    try {
        await run('readwrite', store => store.put(entry));
        // IndexedDB now holds the newest copy; an older localStorage copy must not win when the two are merged.
        dropLocal(entry.client_uuid);
        return;
    } catch (error) {
        console.warn('Supply kiosk: IndexedDB write failed, using localStorage.', error);
    }
    if (!writeLocal({ ...readLocal(), [entry.client_uuid]: entry })) console.warn('Supply kiosk: localStorage write failed; the withdrawal is kept in memory only.');
}

export async function queueDelete(uuid: string): Promise<void> {
    memory.delete(uuid);
    try {
        await run('readwrite', store => store.delete(uuid));
    } catch (error) {
        console.warn('Supply kiosk: IndexedDB delete failed.', error);
    }
    dropLocal(uuid);
}

export async function queueAll(): Promise<QueuedWithdrawal[]> {
    const found = new Map<string, QueuedWithdrawal>();
    Object.values(readLocal()).forEach(entry => { if (entry?.client_uuid) found.set(entry.client_uuid, entry); });
    try {
        const stored = await run<QueuedWithdrawal[]>('readonly', store => store.getAll() as IDBRequest<QueuedWithdrawal[]>);
        stored.forEach(entry => found.set(entry.client_uuid, entry));
    } catch {
        // IndexedDB is unavailable; localStorage and memory still answer.
    }
    memory.forEach((entry, uuid) => found.set(uuid, entry));
    return [...found.values()].sort((a, b) => a.queued_at - b.queued_at);
}

/** Waiting to be sent (refused entries do not count). */
export async function isQueued(uuid: string): Promise<boolean> {
    return (await queueAll()).some(entry => entry.client_uuid === uuid && !entry.refused);
}

export async function refusedEntries(): Promise<QueuedWithdrawal[]> {
    return (await queueAll()).filter(entry => !!entry.refused);
}

const inFlight = new Map<string, Promise<unknown>>();

export function isInFlight(uuid: string): boolean {
    return inFlight.has(uuid);
}

/** Resolves once any send of `uuid` that is under way has finished, however it ended. */
export async function waitForSend(uuid: string): Promise<void> {
    try {
        await inFlight.get(uuid);
    } catch {
        // The sender handles its own failure; here only the timing matters.
    }
}

/** Run `task` while `uuid` is marked as being sent, so a flush never sends the same withdrawal twice at once. */
export async function whileSending<T>(uuid: string, task: () => Promise<T>): Promise<T> {
    const running = task();
    inFlight.set(uuid, running);
    try {
        return await running;
    } finally {
        if (inFlight.get(uuid) === running) inFlight.delete(uuid);
    }
}

export type SendOutcome = { kind: 'sent' } | { kind: 'retry' } | { kind: 'refused'; message: string };

/**
 * Send every waiting withdrawal, oldest first. An entry that fails waits out its own backoff (`force` skips the
 * wait, for when the connection comes back); the rest keep going. Returns how many were sent.
 */
export async function flushQueue(sendOne: (body: WithdrawalBody) => Promise<SendOutcome>, { force = false } = {}): Promise<number> {
    let sent = 0;
    for (const entry of await queueAll()) {
        const uuid = entry.client_uuid;
        if (entry.refused || inFlight.has(uuid)) continue;
        if (!force && entry.next_try_at !== undefined && entry.next_try_at > Date.now()) continue;
        const outcome = await whileSending(uuid, () => sendOne(entry.body));
        if (outcome.kind === 'sent') {
            await queueDelete(uuid);
            sent++;
        } else if (outcome.kind === 'refused') {
            await queuePut({ ...entry, refused: { message: outcome.message, at: Date.now() } });
        } else if (Date.now() - entry.queued_at >= GIVE_UP_AFTER_MS) {
            await queuePut({ ...entry, refused: { message: UNREACHABLE_MESSAGE, at: Date.now() } });
        } else {
            const attempts = (entry.attempts ?? 0) + 1;
            await queuePut({ ...entry, attempts, next_try_at: Date.now() + backoffMs(attempts) });
        }
    }
    return sent;
}
