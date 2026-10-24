/**
 * Per-member browser caches on a shared device.
 *
 * The Daily worker keeps authenticated station and apparatus read models in
 * Cache Storage so the checkout works offline. Those responses belong to
 * whoever was signed in, and station tablets are shared, so the next member must
 * never be able to read them. This module is the page side of that rule:
 *
 *  - noteAuthenticatedUser() remembers WHO was last signed in on this device,
 *    as a salted digest (the id, a name or any other personal data is never stored;
 *    the digest is not a secret, it only keeps the id out of storage in the clear),
 *    and purges the caches when a different member shows up. The very first sighting only
 *    writes the marker (nothing to compare with, and a purge would wipe what the member's
 *    first online visit just stored). After a purge that left a signed-in member, the worker
 *    fetches the offline read models again (see purgeUserCaches in the Daily worker).
 *  - purgeOnSessionEnd() purges when the server says the session ended.
 *  - purgeDailyUserCaches() asks the worker to purge ({type: 'PURGE_USER_CACHES'})
 *    and falls back to removing the API caches through the page's own CacheStorage
 *    handle when no worker answers.
 *
 * Hard rule: only Cache Storage is ever touched. IndexedDB (offline drafts and
 * queued submissions, Dexie "mbfd-daily-checkout") and localStorage drafts are
 * owner-bound by their own records and must survive a purge untouched.
 *
 * Deliberately has no imports so it can be unit tested in plain Node.
 */

export const MARKER_STORAGE_KEY = 'mbfd-daily-device-marker';
export const API_CACHE_PREFIX = 'mbfd-api-cache-';
export const DAILY_SCOPE = '/daily/';

const WORKER_ANSWER_TIMEOUT_MS = 4_000;

export type UserChange = 'first' | 'same' | 'switched';

/** Everything the module needs from the browser, injectable for tests. */
export interface UserCacheEnvironment {
    storage: Pick<Storage, 'getItem' | 'setItem'> | null;
    serviceWorker: ServiceWorkerContainer | null;
    caches: CacheStorage | null;
    randomHex: (bytes: number) => string;
    digest: (text: string) => Promise<string>;
    /** How long to wait for the worker to confirm a purge before using the page's own handle. */
    workerAnswerTimeoutMs?: number;
}

// ---------------------------------------------------------------------------
// Opaque marker
// ---------------------------------------------------------------------------

function fnv1a(text: string): string {
    let hash = 0x811c9dc5;
    for (let index = 0; index < text.length; index += 1) {
        hash ^= text.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return hash.toString(16).padStart(8, '0');
}

async function defaultDigest(text: string): Promise<string> {
    const subtle = typeof crypto !== 'undefined' ? crypto.subtle : undefined;
    if (!subtle) {
        // Insecure context (an http kiosk): a non-cryptographic hash still keeps the id out of storage.
        return fnv1a(text);
    }
    const bytes = new Uint8Array(await subtle.digest('SHA-256', new TextEncoder().encode(text)));

    return Array.from(bytes.slice(0, 16), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function defaultRandomHex(bytes: number): string {
    const values = new Uint8Array(bytes);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(values);
    } else {
        values.forEach((_, index) => { values[index] = Math.floor(Math.random() * 256); });
    }

    return Array.from(values, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function defaultStorage(): UserCacheEnvironment['storage'] {
    try {
        return typeof localStorage === 'undefined' ? null : localStorage;
    } catch {
        return null;
    }
}

export function browserEnvironment(): UserCacheEnvironment {
    return {
        storage: defaultStorage(),
        serviceWorker: typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : null,
        caches: typeof caches !== 'undefined' ? caches : null,
        randomHex: defaultRandomHex,
        digest: defaultDigest,
    };
}

function readMarker(environment: UserCacheEnvironment): { salt: string; digest: string } | null {
    try {
        const raw = environment.storage?.getItem(MARKER_STORAGE_KEY) ?? null;
        const match = raw === null ? null : /^([0-9a-f]{8,64})\.([0-9a-f]{8,64})$/.exec(raw);

        return match ? { salt: match[1], digest: match[2] } : null;
    } catch {
        return null;
    }
}

function writeMarker(environment: UserCacheEnvironment, salt: string, digest: string): boolean {
    try {
        environment.storage?.setItem(MARKER_STORAGE_KEY, `${salt}.${digest}`);

        return environment.storage !== null;
    } catch {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Purge
// ---------------------------------------------------------------------------

function askWorker(worker: ServiceWorker, timeoutMs: number): Promise<boolean> {
    return new Promise((resolve) => {
        let settled = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const finish = (value: boolean) => {
            if (settled) return;
            settled = true;
            if (timer !== undefined) clearTimeout(timer);
            resolve(value);
        };
        try {
            const channel = new MessageChannel();
            channel.port1.onmessage = (event: MessageEvent) => {
                channel.port1.close();
                finish(event.data?.type === 'USER_CACHES_PURGED' && event.data?.success === true);
            };
            worker.postMessage({ type: 'PURGE_USER_CACHES' }, [channel.port2]);
            timer = setTimeout(() => {
                channel.port1.close();
                finish(false);
            }, timeoutMs);
        } catch {
            finish(false);
        }
    });
}

/**
 * Cache Storage is shared between pages and workers, so the page can remove the API caches
 * itself. Used when no worker answers (none installed yet, or an older worker that does not
 * know the message). It deliberately leaves the Daily shell alone: replacing it needs the
 * network and a decision about the session, which only the worker's purge makes (an older
 * worker's shell cache is replaced when the new worker activates).
 */
async function purgeFromPage(storage: CacheStorage): Promise<boolean> {
    try {
        const names = await storage.keys();
        await Promise.all(names.filter((name) => name.startsWith(API_CACHE_PREFIX)).map((name) => storage.delete(name)));

        return true;
    } catch {
        return false;
    }
}

/**
 * Remove every cached response that belongs to one member. Resolves true when it
 * is done. Never throws, never touches IndexedDB. The worker message is posted
 * synchronously when a controller exists, so it still goes out when the caller
 * navigates away right after calling this.
 */
export async function purgeDailyUserCaches(environment: UserCacheEnvironment = browserEnvironment()): Promise<boolean> {
    try {
        const container = environment.serviceWorker;
        let worker: ServiceWorker | null | undefined = container?.controller;
        if (!worker && container) {
            worker = (await container.getRegistration(DAILY_SCOPE))?.active;
        }
        if (worker && await askWorker(worker, environment.workerAnswerTimeoutMs ?? WORKER_ANSWER_TIMEOUT_MS)) return true;
    } catch {
        // Fall through to the page-side purge.
    }

    return environment.caches === null ? true : purgeFromPage(environment.caches);
}

let purgedForSessionEnd = false;

/**
 * The server said the session is gone (401). Purge once per signed-out episode, so a
 * replay timer that keeps hitting 401 does not purge every few minutes.
 */
export function purgeOnSessionEnd(environment?: UserCacheEnvironment): Promise<boolean> {
    if (purgedForSessionEnd) return Promise.resolve(true);
    purgedForSessionEnd = true;

    return purgeDailyUserCaches(environment).then((done) => {
        if (!done) purgedForSessionEnd = false;

        return done;
    });
}

// ---------------------------------------------------------------------------
// User switch
// ---------------------------------------------------------------------------

let pending: Promise<unknown> = Promise.resolve();

async function check(userId: number, environment: UserCacheEnvironment): Promise<UserChange> {
    purgedForSessionEnd = false;
    const known = readMarker(environment);
    const salt = known?.salt ?? environment.randomHex(8);
    const digest = await environment.digest(`${salt}:${userId}`);

    if (!known) {
        // Nothing to compare with: remember this member and do NOT purge. Every device meets this
        // branch exactly once (the code is new), and it happens right after the member opened Daily
        // online and the page stored the apparatus list and checklists; purging now would delete
        // what that visit just stored and leave the offline checkout empty. Nothing needs protecting
        // either: the previous worker's API cache was deleted when the current worker activated.
        // A purge happens only when a stored marker names a different member, or the session ends.
        writeMarker(environment, salt, digest);

        return 'first';
    }
    if (known.digest === digest) return 'same';

    // Only remember the new member once the previous one's caches are really gone,
    // so a failed purge is retried on the next identity check.
    if (await purgeDailyUserCaches(environment)) writeMarker(environment, salt, digest);

    return 'switched';
}

/**
 * Call with the authenticated member's id whenever the server confirmed it
 * (`/api/me/context`). Calls are serialized, so two quick checks never purge twice.
 */
export function noteAuthenticatedUser(userId: number, environment: UserCacheEnvironment = browserEnvironment()): Promise<UserChange> {
    const run = pending.then(() => check(userId, environment));
    pending = run.catch(() => undefined);

    return run;
}

/** Test helper: forget in-memory state. */
export function resetUserCacheState(): void {
    purgedForSessionEnd = false;
    pending = Promise.resolve();
}
