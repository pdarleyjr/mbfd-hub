/**
 * Foreground alerts: a short pop-up (and, if the member chose it, a soft chime) when a NEW
 * in-app alert reaches a Hub page that is open and on screen.
 *
 * Where new alerts come from (no new transport where one exists):
 *  - Filament panels: the bell's own Livewire poll. The bell view carries a snapshot of its first
 *    page on `data-mbfd-alert-feed` (resources/views/vendor/filament-notifications/
 *    database-notifications.blade.php); this module only watches that attribute change.
 *  - Pages with no bell (Hub home, Daily Checkout): ONE poller per device for the member alert feed
 *    (<meta name="mbfd-alerts-feed"> or the `feedUrl` option). Only the tab holding a short
 *    localStorage lease polls, only while visible, every 60 s or more, with back-off on errors; it
 *    shares each answer with the other tabs over BroadcastChannel `mbfd-alerts`.
 *
 * What counts as new: an unread alert whose id this page has not seen, created at or after the
 * page's first snapshot (server clock) and within the last 15 minutes. Whatever is already in the
 * bell when a page opens is never announced.
 *
 * One tab announces each alert: tabs that see the same new id claim it over `mbfd-alerts`; the
 * focused (else visible) tab wins, ties by tab id, and every tab records it in localStorage
 * `mbfd-alerts-shown`, so a reload or a late tab stays quiet. Hidden tabs never announce; they hold
 * a new alert until they are shown again.
 *
 * The pop-up never marks anything read, never moves focus, and is the only thing added to the
 * page. The bell remains the record, with its own count.
 */
import { installGestureUnlock, playAlertChime } from './alert-sound.ts';

export interface ForegroundAlert {
    id: string;
    title: string;
    body: string;
    url: string | null;
    urgent: boolean;
    unread: boolean;
    /** Server time, ms. */
    created: number;
}

export interface AlertSnapshot {
    /** Server time the snapshot was taken, ms. */
    now: number;
    /** Newest first. */
    alerts: ForegroundAlert[];
}

export interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

export interface ChannelLike {
    postMessage(message: unknown): void;
    addEventListener(type: 'message', listener: (event: { data: unknown }) => void): void;
    close?(): void;
}

export const ALERT_CHANNEL = 'mbfd-alerts';
export const SHOWN_KEY = 'mbfd-alerts-shown';
export const LEADER_KEY = 'mbfd-alerts-leader';
export const FEED_META = 'meta[name="mbfd-alerts-feed"]';
export const FEED_ATTRIBUTE = 'data-mbfd-alert-feed';

/** An alert older than this when first seen is left to the bell. */
export const MAX_AGE_MS = 15 * 60_000;
/** Covers an alert written between the page's query and its render. */
export const BASELINE_GRACE_MS = 5_000;
export const TOAST_MS = 8_000;
export const URGENT_TOAST_MS = 12_000;
export const MAX_TOASTS = 3;
export const POLL_INTERVAL_MS = 60_000;
export const MIN_POLL_INTERVAL_MS = 30_000;
export const MAX_POLL_BACKOFF_MS = 15 * 60_000;
export const CLAIM_WINDOW_MS = 250;
export const CLAIM_FALLBACK_MS = 1_500;
const SHOWN_TTL_MS = 24 * 60 * 60_000;
const SHOWN_MAX = 200;
const SEEN_MAX = 1_000;

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

function text(value: unknown, limit: number): string {
    if (typeof value !== 'string') return '';
    const clean = value.replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim();

    return clean.length > limit ? `${clean.slice(0, limit - 1)}…` : clean;
}

/** Validates a snapshot from the bell or the feed; malformed entries are dropped, never guessed. */
export function parseSnapshot(raw: unknown): AlertSnapshot | null {
    if (typeof raw !== 'object' || raw === null) return null;
    const { now, alerts } = raw as { now?: unknown; alerts?: unknown };
    if (typeof now !== 'number' || !Number.isFinite(now) || !Array.isArray(alerts)) return null;

    const parsed: ForegroundAlert[] = [];
    for (const item of alerts) {
        if (typeof item !== 'object' || item === null) continue;
        const a = item as Record<string, unknown>;
        if (typeof a.id !== 'string' || a.id === '' || typeof a.created !== 'number' || !Number.isFinite(a.created)) continue;
        parsed.push({
            id: a.id,
            title: text(a.title, 120) || 'New alert',
            body: text(a.body, 160),
            url: typeof a.url === 'string' ? a.url : null,
            urgent: a.urgent === true,
            unread: a.unread !== false,
            created: a.created,
        });
    }

    return { now, alerts: parsed };
}

/** A same-origin destination as path+query+hash, or null (the "Open" control is then omitted). */
export function safeSameOriginUrl(url: string | null | undefined, origin: string): string | null {
    if (typeof url !== 'string' || url.trim() === '' || /[\u0000-\u001F\u007F\\]/.test(url)) return null;
    try {
        const resolved = new URL(url.trim(), origin);
        if (resolved.origin !== origin || !/^https?:$/.test(resolved.protocol) || resolved.username !== '' || resolved.password !== '') {
            return null;
        }

        return `${resolved.pathname}${resolved.search}${resolved.hash}`;
    } catch {
        return null;
    }
}

export interface AlertTracker {
    /** Returns the alerts that are genuinely new since the page's first snapshot, oldest first. */
    ingest(snapshot: AlertSnapshot): ForegroundAlert[];
    readonly ready: boolean;
}

/**
 * The first snapshot is the baseline: everything in it is "seen" and nothing is announced. Later
 * snapshots announce unread ids not seen before, created at/after the baseline and recently.
 */
export function createAlertTracker({ maxAgeMs = MAX_AGE_MS, graceMs = BASELINE_GRACE_MS } = {}): AlertTracker {
    const seen = new Set<string>();
    let threshold: number | null = null;

    const remember = (id: string): void => {
        seen.delete(id);
        seen.add(id);
        if (seen.size > SEEN_MAX) {
            const oldest = seen.values().next().value;
            if (oldest !== undefined) seen.delete(oldest);
        }
    };

    return {
        get ready() {
            return threshold !== null;
        },
        ingest(snapshot) {
            if (threshold === null) {
                const newest = snapshot.alerts.reduce((max, alert) => Math.max(max, alert.created), Number.NEGATIVE_INFINITY);
                // Stored times are whole seconds, so "at or after the newest" (>=) with the seen set,
                // or a few seconds of grace when the bell was empty.
                threshold = Number.isFinite(newest) ? newest : snapshot.now - graceMs;
                snapshot.alerts.forEach((alert) => remember(alert.id));

                return [];
            }
            const fresh = snapshot.alerts.filter(
                (alert) => !seen.has(alert.id) && alert.unread && alert.created >= (threshold as number) && snapshot.now - alert.created <= maxAgeMs,
            );
            snapshot.alerts.forEach((alert) => remember(alert.id));

            return fresh.sort((a, b) => a.created - b.created);
        },
    };
}

export interface ShownRegistry {
    has(id: string): boolean;
    add(id: string): void;
}

/** Alerts already announced in any tab of this browser (localStorage), else this tab only. */
export function createShownRegistry(storage: StorageLike | null, clock: () => number = Date.now): ShownRegistry {
    const memory = new Map<string, number>();
    const read = (): Map<string, number> => {
        if (storage === null) return memory;
        try {
            const raw = JSON.parse(storage.getItem(SHOWN_KEY) ?? '{}') as unknown;
            const map = new Map<string, number>();
            if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) {
                for (const [id, at] of Object.entries(raw as Record<string, unknown>)) {
                    if (typeof at === 'number' && clock() - at < SHOWN_TTL_MS) map.set(id, at);
                }
            }

            return map;
        } catch {
            return memory;
        }
    };

    return {
        has: (id) => memory.has(id) || read().has(id),
        add: (id) => {
            memory.set(id, clock());
            if (storage === null) return;
            try {
                const map = read();
                map.set(id, clock());
                const newest = [...map.entries()].sort((a, b) => b[1] - a[1]).slice(0, SHOWN_MAX);
                storage.setItem(SHOWN_KEY, JSON.stringify(Object.fromEntries(newest)));
            } catch {
                // Storage full or blocked: this tab still remembers.
            }
        },
    };
}

export interface Claim {
    tab: string;
    /** 2 focused, 1 visible. Hidden tabs never claim. */
    rank: number;
}

/** Highest rank wins; ties go to the smallest tab id, so every tab computes the same winner. */
export function pickClaimWinner(claims: readonly Claim[]): string | null {
    let winner: Claim | null = null;
    for (const claim of claims) {
        if (winner === null || claim.rank > winner.rank || (claim.rank === winner.rank && claim.tab < winner.tab)) winner = claim;
    }

    return winner?.tab ?? null;
}

export interface LeaderLease {
    tryAcquire(): boolean;
    release(): void;
}

/** One polling tab per browser. Without usable storage every visible tab polls for itself. */
export function createLeaderLease(storage: StorageLike | null, tab: string, ttlMs: number, clock: () => number = Date.now): LeaderLease {
    const read = (): { tab: string; until: number } | null => {
        try {
            const value = JSON.parse(storage?.getItem(LEADER_KEY) ?? 'null') as { tab?: unknown; until?: unknown } | null;

            return value && typeof value.tab === 'string' && typeof value.until === 'number' ? { tab: value.tab, until: value.until } : null;
        } catch {
            return null;
        }
    };

    return {
        tryAcquire() {
            if (storage === null) return true;
            try {
                const current = read();
                if (current !== null && current.tab !== tab && current.until > clock()) return false;
                storage.setItem(LEADER_KEY, JSON.stringify({ tab, until: clock() + ttlMs }));

                return read()?.tab === tab;
            } catch {
                return true;
            }
        },
        release() {
            try {
                if (storage !== null && read()?.tab === tab) storage.removeItem(LEADER_KEY);
            } catch {
                // Nothing to release.
            }
        },
    };
}

export type FeedOutcome = 'ok' | 'stop' | 'backoff';

/** 401/403: not signed in here. 404/410: this server has no feed. Both stop polling for the page. */
export function classifyFeedResponse(status: number): FeedOutcome {
    if (status >= 200 && status < 300) return 'ok';
    if (status === 401 || status === 403 || status === 404 || status === 410) return 'stop';

    return 'backoff';
}

/** Next poll: the interval (never under 30 s) with ±10% jitter, doubling per failure up to 15 minutes. */
export function nextPollDelay({
    baseMs = POLL_INTERVAL_MS,
    failures = 0,
    retryAfterMs = null as number | null,
    random = Math.random,
} = {}): number {
    const base = Math.max(MIN_POLL_INTERVAL_MS, baseMs);
    const backoff = Math.min(MAX_POLL_BACKOFF_MS, base * 2 ** Math.min(Math.max(0, failures), 8));
    const wanted = retryAfterMs !== null && retryAfterMs > backoff ? Math.min(MAX_POLL_BACKOFF_MS, retryAfterMs) : backoff;

    return Math.round(wanted * (0.9 + random() * 0.2));
}

/** What adding one toast does to the stack: a duplicate only refreshes; otherwise the oldest go. */
export function planToastStack(visibleIds: readonly string[], incomingId: string, max: number = MAX_TOASTS): { duplicate: boolean; evict: string[] } {
    if (visibleIds.includes(incomingId)) return { duplicate: true, evict: [] };
    const overflow = visibleIds.length + 1 - max;

    return { duplicate: false, evict: overflow > 0 ? visibleIds.slice(0, overflow) : [] };
}

// ---------------------------------------------------------------------------
// Cross-tab claim
// ---------------------------------------------------------------------------

type ChannelMessage =
    | { type: 'claim'; id: string; tab: string; rank: number }
    | { type: 'shown'; id: string; tab: string }
    | { type: 'snapshot'; tab: string; snapshot: unknown };

export interface ClaimCoordinator {
    /** Resolves true when this tab should announce `id`. */
    claim(id: string, rank: number): Promise<boolean>;
    receive(message: unknown): void;
}

export function createClaimCoordinator({
    channel,
    registry,
    tab,
    windowMs = CLAIM_WINDOW_MS,
    fallbackMs = CLAIM_FALLBACK_MS,
    clock = Date.now,
}: {
    channel: ChannelLike | null;
    registry: ShownRegistry;
    tab: string;
    windowMs?: number;
    fallbackMs?: number;
    clock?: () => number;
}): ClaimCoordinator {
    const claims = new Map<string, Array<Claim & { at: number }>>();
    const shownElsewhere = new Set<string>();
    const waiters = new Map<string, Set<() => void>>();

    const record = (id: string, claim: Claim): void => {
        const fresh = (claims.get(id) ?? []).filter((c) => clock() - c.at < 10_000 && c.tab !== claim.tab);
        claims.set(id, [...fresh, { ...claim, at: clock() }]);
    };
    const wake = (id: string): void => {
        waiters.get(id)?.forEach((resolve) => resolve());
        waiters.delete(id);
    };
    /** Waits up to `ms`, or until another tab says it showed `id`. */
    const waitFor = (id: string, ms: number): Promise<void> =>
        new Promise((resolve) => {
            const done = (): void => {
                clearTimeout(timer);
                waiters.get(id)?.delete(done);
                resolve();
            };
            const timer = setTimeout(done, ms);
            if (!waiters.has(id)) waiters.set(id, new Set());
            waiters.get(id)?.add(done);
        });
    const take = (id: string): boolean => {
        if (registry.has(id) || shownElsewhere.has(id)) return false;
        registry.add(id);
        channel?.postMessage({ type: 'shown', id, tab } satisfies ChannelMessage);

        return true;
    };

    return {
        receive(message) {
            if (typeof message !== 'object' || message === null) return;
            const m = message as { type?: unknown; id?: unknown; tab?: unknown; rank?: unknown };
            if (typeof m.id !== 'string' || typeof m.tab !== 'string' || m.tab === tab) return;
            if (m.type === 'claim' && typeof m.rank === 'number') {
                record(m.id, { tab: m.tab, rank: m.rank });
            } else if (m.type === 'shown') {
                shownElsewhere.add(m.id);
                wake(m.id);
            }
        },
        async claim(id, rank) {
            if (registry.has(id) || shownElsewhere.has(id)) return false;
            if (channel === null) return take(id);

            record(id, { tab, rank });
            channel.postMessage({ type: 'claim', id, tab, rank } satisfies ChannelMessage);
            await waitFor(id, windowMs);
            if (shownElsewhere.has(id) || registry.has(id)) return false;

            if (pickClaimWinner(claims.get(id) ?? []) === tab) return take(id);

            // Another tab should show it. If it went away mid-claim, show it here after all.
            await waitFor(id, fallbackMs);

            return take(id);
        },
    };
}

// ---------------------------------------------------------------------------
// Toasts (DOM)
// ---------------------------------------------------------------------------

const ROOT_ID = 'mbfd-alerts';
const STYLE_ID = 'mbfd-alerts-style';

/** Entrance motion lives only inside `prefers-reduced-motion: no-preference`; removal is instant. */
export const TOAST_STYLE = `
.mbfd-alerts { position: fixed; z-index: var(--hub-z-toast, 80); left: 12px; right: 12px; top: calc(72px + env(safe-area-inset-top, 0px)); margin-inline: auto; max-width: 420px; display: flex; flex-direction: column; gap: 8px; pointer-events: none; font-family: var(--hub-font-sans, system-ui, -apple-system, 'Segoe UI', sans-serif); }
.mbfd-alerts__stack { display: flex; flex-direction: column; gap: 8px; }
.mbfd-alerts__stack:empty { display: none; }
.mbfd-alert { pointer-events: auto; display: flex; align-items: flex-start; gap: 4px 8px; padding: 10px 4px 10px 14px; border: 1px solid rgb(var(--hub-border, 216 226 236)); border-inline-start: 4px solid rgb(var(--hub-blue, 30 78 140)); border-radius: var(--hub-radius-lg, 8px); background: rgb(var(--hub-surface, 255 255 255)); color: rgb(var(--hub-ink, 15 23 42)); box-shadow: var(--hub-shadow-elevated, 0 8px 24px -8px rgb(1 39 102 / .25)); font-size: 14px; line-height: 1.45; }
.mbfd-alert[data-urgent] { border-inline-start-color: rgb(var(--hub-danger, 185 28 28)); }
.mbfd-alert__text { flex: 1 1 auto; min-width: 0; padding-top: 2px; overflow-wrap: anywhere; }
.mbfd-alert__label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: rgb(var(--hub-danger, 185 28 28)); }
.mbfd-alert__title { display: block; margin: 0; font-weight: 700; }
.mbfd-alert__body { display: block; margin: 2px 0 0; color: rgb(var(--hub-ink-secondary, 51 65 85)); }
.mbfd-alert__actions { display: flex; align-items: center; flex: none; }
.mbfd-alert :is(a, button) { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 0 12px; border: 0; border-radius: var(--hub-radius-md, 6px); background: transparent; color: rgb(var(--hub-blue, 30 78 140)); font: inherit; font-weight: 650; text-decoration: none; cursor: pointer; }
.mbfd-alert button { padding: 0; color: rgb(var(--hub-muted, 86 101 121)); font-size: 22px; line-height: 1; }
.mbfd-alert :is(a, button):hover { background: rgb(var(--hub-surface-muted, 241 245 249)); }
.mbfd-alert :is(a, button):focus-visible { outline: 2px solid rgb(var(--hub-focus, 29 78 216)); outline-offset: 2px; }
.mbfd-alerts .mbfd-sr { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
@media (min-width: 640px) { .mbfd-alerts { left: auto; right: 16px; top: auto; bottom: calc(16px + env(safe-area-inset-bottom, 0px)); width: 380px; margin: 0; } body:has(#mbfd-pwa-banner) .mbfd-alerts { bottom: calc(96px + env(safe-area-inset-bottom, 0px)); } }
@media (min-width: 1280px) and (pointer: fine) { body:has([data-admin-status-bar]) .mbfd-alerts { bottom: 56px; } body:has([data-admin-status-bar]):has(#mbfd-pwa-banner) .mbfd-alerts { bottom: 136px; } }
@media (prefers-reduced-motion: no-preference) { .mbfd-alert { animation: mbfd-alert-in 200ms ease-out; } @keyframes mbfd-alert-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } } }
@media print { .mbfd-alerts { display: none; } }
`;

interface ToastHandle {
    element: HTMLElement;
    remove(): void;
    refresh(): void;
}

class ToastLayer {
    private readonly toasts = new Map<string, ToastHandle>();
    private root: HTMLElement | null = null;

    /** Created before the first toast so the live regions exist when content arrives. */
    mount(): void {
        if (this.root !== null || typeof document === 'undefined') return;
        if (!document.getElementById(STYLE_ID)) {
            const style = document.createElement('style');
            style.id = STYLE_ID;
            style.textContent = TOAST_STYLE;
            document.head.appendChild(style);
        }
        // A plain container (not a landmark), so an empty one adds nothing for screen readers.
        const root = document.createElement('div');
        root.id = ROOT_ID;
        root.className = 'mbfd-alerts';
        const urgent = document.createElement('div');
        urgent.className = 'mbfd-alerts__stack';
        urgent.dataset.stack = 'urgent';
        urgent.setAttribute('role', 'alert');
        const normal = document.createElement('div');
        normal.className = 'mbfd-alerts__stack';
        normal.dataset.stack = 'normal';
        normal.setAttribute('role', 'status');
        normal.setAttribute('aria-live', 'polite');
        root.append(urgent, normal);
        document.body.appendChild(root);
        this.root = root;
    }

    ids(): string[] {
        return [...this.toasts.keys()];
    }

    show(alert: ForegroundAlert): void {
        this.mount();
        if (this.root === null) return;
        const plan = planToastStack(this.ids(), alert.id);
        if (plan.duplicate) {
            this.toasts.get(alert.id)?.refresh();

            return;
        }
        plan.evict.forEach((id) => this.toasts.get(id)?.remove());
        const handle = this.build(alert);
        this.toasts.set(alert.id, handle);
        this.root.querySelector(`[data-stack="${alert.urgent ? 'urgent' : 'normal'}"]`)?.prepend(handle.element);
    }

    private build(alert: ForegroundAlert): ToastHandle {
        const element = document.createElement('div');
        element.className = 'mbfd-alert';
        element.dataset.alertId = alert.id;
        if (alert.urgent) element.dataset.urgent = '';

        const content = document.createElement('div');
        content.className = 'mbfd-alert__text';
        const intro = document.createElement('span');
        intro.className = 'mbfd-sr';
        intro.textContent = 'New alert: ';
        content.appendChild(intro);
        if (alert.urgent) {
            const label = document.createElement('span');
            label.className = 'mbfd-alert__label';
            label.textContent = 'Urgent';
            content.appendChild(label);
        }
        const title = document.createElement('span');
        title.className = 'mbfd-alert__title';
        title.textContent = alert.title;
        content.appendChild(title);
        if (alert.body !== '') {
            const body = document.createElement('span');
            body.className = 'mbfd-alert__body';
            body.textContent = alert.body;
            content.appendChild(body);
        }

        const actions = document.createElement('div');
        actions.className = 'mbfd-alert__actions';
        const href = safeSameOriginUrl(alert.url, location.origin);
        if (href !== null) {
            const open = document.createElement('a');
            open.href = href;
            open.textContent = 'Open';
            open.setAttribute('aria-label', `Open: ${alert.title}`);
            open.addEventListener('click', () => remove());
            actions.appendChild(open);
        }
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', `Dismiss alert: ${alert.title}`);
        close.addEventListener('click', () => remove());
        actions.appendChild(close);
        element.append(content, actions);

        // Auto-dismiss, paused while pointed at, focused, or while the page is hidden.
        const duration = alert.urgent ? URGENT_TOAST_MS : TOAST_MS;
        let remaining = duration;
        let startedAt = 0;
        let timer: ReturnType<typeof setTimeout> | null = null;
        let hovered = false;
        let focused = false;
        const pause = (): void => {
            if (timer === null) return;
            clearTimeout(timer);
            timer = null;
            remaining = Math.max(1_000, remaining - (Date.now() - startedAt));
        };
        const resume = (): void => {
            if (timer !== null || hovered || focused || document.visibilityState !== 'visible') return;
            startedAt = Date.now();
            timer = setTimeout(remove, remaining);
        };
        const onVisibility = (): void => (document.visibilityState === 'visible' ? resume() : pause());
        element.addEventListener('pointerenter', () => { hovered = true; pause(); });
        element.addEventListener('pointerleave', () => { hovered = false; resume(); });
        element.addEventListener('focusin', () => { focused = true; pause(); });
        element.addEventListener('focusout', (event) => {
            if (event.relatedTarget instanceof Node && element.contains(event.relatedTarget)) return;
            focused = false;
            resume();
        });
        document.addEventListener('visibilitychange', onVisibility);

        const remove = (): void => {
            if (timer !== null) clearTimeout(timer);
            timer = null;
            document.removeEventListener('visibilitychange', onVisibility);
            element.remove();
            if (this.toasts.get(alert.id)?.element === element) this.toasts.delete(alert.id);
        };
        const refresh = (): void => {
            pause();
            remaining = duration;
            resume();
        };
        resume();

        return { element, remove, refresh };
    }
}

// ---------------------------------------------------------------------------
// Feed poller (pages without a bell)
// ---------------------------------------------------------------------------

interface PollerDeps {
    url: string;
    intervalMs: number;
    lease: LeaderLease;
    onSnapshot(snapshot: AlertSnapshot, raw: unknown): void;
    fetchImpl?: typeof fetch;
}

class FeedPoller {
    private timer: ReturnType<typeof setTimeout> | null = null;
    private failures = 0;
    private stopped = false;
    private inFlight = false;
    private lastPollAt = 0;
    private readonly deps: PollerDeps;

    constructor(deps: PollerDeps) {
        this.deps = deps;
    }

    start(): void {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.wake();
            else this.sleep();
        });
        window.addEventListener('pagehide', () => this.sleep());
        window.addEventListener('online', () => this.wake());
        if (document.visibilityState === 'visible') this.schedule(0);
    }

    private sleep(): void {
        this.clear();
        this.deps.lease.release();
    }

    private wake(): void {
        if (this.stopped || document.visibilityState !== 'visible') return;
        const since = Date.now() - this.lastPollAt;
        // Coming back: catch up soon, but never faster than once every 5 seconds.
        this.schedule(since >= this.deps.intervalMs ? 1_000 + Math.random() * 2_000 : Math.max(5_000, this.deps.intervalMs - since));
    }

    private clear(): void {
        if (this.timer !== null) clearTimeout(this.timer);
        this.timer = null;
    }

    private schedule(ms: number): void {
        this.clear();
        if (this.stopped) return;
        this.timer = setTimeout(() => void this.tick(), ms);
    }

    private async tick(): Promise<void> {
        this.timer = null;
        if (this.stopped || this.inFlight) return;
        if (document.visibilityState !== 'visible') {
            this.sleep();

            return;
        }
        if (!this.deps.lease.tryAcquire()) {
            // Another tab polls and shares its answer; check again later in case it went away.
            this.schedule(nextPollDelay({ baseMs: this.deps.intervalMs }));

            return;
        }

        this.inFlight = true;
        this.lastPollAt = Date.now();
        let retryAfterMs: number | null = null;
        try {
            const response = await (this.deps.fetchImpl ?? fetch)(this.deps.url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const outcome = classifyFeedResponse(response.status);
            if (outcome === 'stop') {
                this.stopped = true;
                this.deps.lease.release();

                return;
            }
            if (outcome === 'ok') {
                const raw: unknown = await response.json();
                const snapshot = parseSnapshot(raw);
                if (snapshot === null) throw new Error('Malformed alert feed');
                this.failures = 0;
                this.deps.onSnapshot(snapshot, raw);
            } else {
                const header = Number(response.headers.get('Retry-After'));
                retryAfterMs = Number.isFinite(header) && header > 0 ? header * 1_000 : null;
                this.failures += 1;
            }
        } catch {
            this.failures += 1;
        } finally {
            this.inFlight = false;
        }
        this.schedule(nextPollDelay({ baseMs: this.deps.intervalMs, failures: this.failures, retryAfterMs }));
    }
}

// ---------------------------------------------------------------------------
// Page wiring
// ---------------------------------------------------------------------------

export interface ForegroundAlertsOptions {
    /** Feed URL for pages without a Filament bell. Defaults to <meta name="mbfd-alerts-feed">. */
    feedUrl?: string | null;
    /** Poll interval for the feed; never under 30 s. */
    intervalMs?: number;
}

export interface ForegroundAlertsHandle {
    readonly source: 'bell' | 'feed' | 'none';
    /** For tests and the bell watcher: hand a snapshot in directly. */
    ingest(snapshot: AlertSnapshot): void;
}

type AlertsWindow = Window & { __mbfdForegroundAlertsV1?: ForegroundAlertsHandle };

function safeStorage(): StorageLike | null {
    try {
        const storage = window.localStorage;
        const probe = '__mbfd-alerts-probe__';
        storage.setItem(probe, '1');
        storage.removeItem(probe);

        return storage;
    } catch {
        return null;
    }
}

function openChannel(): ChannelLike | null {
    try {
        return typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(ALERT_CHANNEL);
    } catch {
        return null;
    }
}

function tabId(): string {
    try {
        return crypto.randomUUID();
    } catch {
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    }
}

function rank(): number {
    if (document.visibilityState !== 'visible') return 0;

    return document.hasFocus() ? 2 : 1;
}

function watchBell(onRaw: (raw: string) => void): void {
    let last = '';
    let pending: ReturnType<typeof setTimeout> | null = null;
    const check = (): void => {
        pending = null;
        const raw = document.querySelector(`[${FEED_ATTRIBUTE}]`)?.getAttribute(FEED_ATTRIBUTE) ?? '';
        if (raw === '' || raw === last) return;
        last = raw;
        onRaw(raw);
    };
    // The bell is lazy-loaded and re-rendered by its poll; one cheap, debounced read per DOM burst.
    new MutationObserver(() => {
        pending ??= setTimeout(check, 150);
    }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: [FEED_ATTRIBUTE] });
    check();
}

/**
 * Starts foreground alerts once per page (later calls return the same handle). Filament pages use
 * the bell; other pages use the feed when one is offered; otherwise nothing runs.
 */
export function startForegroundAlerts(options: ForegroundAlertsOptions = {}): ForegroundAlertsHandle | null {
    if (typeof window === 'undefined' || typeof document === 'undefined' || !document.body) return null;
    const host = window as AlertsWindow;
    if (host.__mbfdForegroundAlertsV1) return host.__mbfdForegroundAlertsV1;

    const isFilamentPanel = document.body.classList.contains('fi-body');
    const feedUrl = options.feedUrl ?? document.querySelector<HTMLMetaElement>(FEED_META)?.content ?? null;
    const source: ForegroundAlertsHandle['source'] = isFilamentPanel ? 'bell' : feedUrl ? 'feed' : 'none';

    const tracker = createAlertTracker();
    const storage = safeStorage();
    const channel = openChannel();
    const tab = tabId();
    const coordinator = createClaimCoordinator({ channel, registry: createShownRegistry(storage), tab });
    const layer = new ToastLayer();
    const waiting = new Map<string, { alert: ForegroundAlert; seenAt: number }>();

    const announce = async (alert: ForegroundAlert): Promise<void> => {
        if (!(await coordinator.claim(alert.id, rank()))) return;
        layer.show(alert);
        playAlertChime();
    };
    const offer = (alerts: ForegroundAlert[]): void => {
        for (const alert of alerts) {
            if (document.visibilityState === 'visible') void announce(alert);
            else waiting.set(alert.id, { alert, seenAt: Date.now() });
        }
    };
    const ingest = (snapshot: AlertSnapshot): void => offer(tracker.ingest(snapshot));

    const handle: ForegroundAlertsHandle = { source, ingest };
    host.__mbfdForegroundAlertsV1 = handle;
    if (source === 'none') return handle;

    layer.mount();
    installGestureUnlock();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        const due = [...waiting.values()].filter((entry) => Date.now() - entry.seenAt <= MAX_AGE_MS).map((entry) => entry.alert);
        waiting.clear();
        due.forEach((alert) => void announce(alert));
    });

    channel?.addEventListener('message', (event) => {
        coordinator.receive(event.data);
        const message = event.data as Partial<ChannelMessage> | null;
        // Tabs without a bell take the polling tab's answer instead of polling themselves.
        if (source === 'feed' && message?.type === 'snapshot' && message.tab !== tab) {
            const snapshot = parseSnapshot(message.snapshot);
            if (snapshot !== null) ingest(snapshot);
        }
    });

    if (source === 'bell') {
        watchBell((raw) => {
            try {
                const snapshot = parseSnapshot(JSON.parse(raw));
                if (snapshot !== null) ingest(snapshot);
            } catch {
                // A malformed snapshot is ignored; the bell itself is unaffected.
            }
        });
    } else if (feedUrl) {
        const intervalMs = Math.max(MIN_POLL_INTERVAL_MS, options.intervalMs ?? POLL_INTERVAL_MS);
        new FeedPoller({
            url: feedUrl,
            intervalMs,
            lease: createLeaderLease(storage, tab, intervalMs * 2),
            onSnapshot: (snapshot, raw) => {
                ingest(snapshot);
                channel?.postMessage({ type: 'snapshot', tab, snapshot: raw } satisfies ChannelMessage);
            },
        }).start();
    }

    return handle;
}
