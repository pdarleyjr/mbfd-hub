/**
 * Page-side release watcher for the Hub PWA.
 *
 * What it does, in one place, for every Hub surface that loads it:
 *  1. Knows which release the page was rendered by (<meta name="mbfd-release">,
 *     else the first /app-release answer).
 *  2. Re-checks /app-release (no-store) when the page becomes visible, when the
 *     browser comes back online, and every ~30 minutes while visible.
 *  3. On a changed release: asks the service-worker registration for its own
 *     scope to update(), then offers or performs ONE reload, never while the
 *     page is busy (see ./busy).
 *  4. Recovers from lazy-chunk failures after a deploy (`vite:preloadError`).
 *  5. Handles `MBFD_NAVIGATE` messages from the notification-click worker code:
 *     navigates when safe, otherwise shows an "Open notification" link.
 *
 * Hard rules (ERROR-037 in 2.AI_AGENT_ERRORS.md):
 *  - It never registers a worker unless a caller passes `scriptUrl` AND no
 *    registration exists for that scope yet. No `?v=` on worker URLs, ever.
 *  - It never reloads from a `controllerchange` event. The only reloads are the
 *    ones decided here, at most once per release (sessionStorage guard
 *    `mbfd-reloaded-for:<release>`), so a reload loop is structurally impossible.
 *  - The first call wins: later `startUpdateRegistrar` calls from other bundles
 *    only add their scope to the same instance (shared via a window global).
 */
import { isUnsafeForAutomaticReload, isUnsafeToRefresh, onBusyChange, startFieldEditTracking } from './busy';
import { chunkUrlFromPreloadError, decideChunkRecovery, isMissingStatus } from './chunk-recovery';

export interface UpdateReadyInfo {
    /** The release the server is now running. */
    release: string;
    /** The scope of the registration that was asked to update. */
    scope: string;
}

export interface UpdateRegistrarOptions {
    /** Service-worker scope this page cares about, for example '/' or '/admin/'. */
    scope: string;
    /**
     * Worker script URL. Only used when NO registration exists for `scope` yet;
     * pages that already register their worker must not pass it.
     */
    scriptUrl?: string;
    /** Called once per detected release, after the registration was asked to update. */
    onUpdateReady?: (info: UpdateReadyInfo) => void;
}

export interface UpdateRegistrar {
    /** Check /app-release right now (rate limited unless `force`). */
    checkNow(force?: boolean): Promise<void>;
    /** Reload to apply a ready update. No-op while busy. */
    refreshNow(): void;
    /** Release the page was rendered by, if known. */
    currentRelease(): string | null;
    /** Newer release detected but not yet applied, if any. */
    pendingRelease(): string | null;
}

const RELEASE_ENDPOINT = '/app-release';
const RELEASE_META = 'meta[name="mbfd-release"]';
const GUARD_PREFIX = 'mbfd-reloaded-for:';
const CHECK_INTERVAL_MS = 30 * 60_000;
const MIN_CHECK_GAP_MS = 60_000;
const WORKER_SETTLE_TIMEOUT_MS = 8_000;
const ACTIVATE_TIMEOUT_MS = 1_500;
const RELEASE_PATTERN = /^[0-9a-f]{12}$/;
const MESSAGE_BUSY = 'An update is ready. It will apply when you finish.';
const MESSAGE_READY = 'An update is ready.';
const MESSAGE_CHUNK = 'Part of this page could not load. Refresh to continue.';
const MESSAGE_OFFLINE = "You're offline. Some screens will load when you're back online.";
const MESSAGE_NAVIGATE = 'A notification is waiting.';
const STYLE_ID = 'mbfd-pwa-banner-style';
const BANNER_ID = 'mbfd-pwa-banner';

interface RegistrarState {
    version: 1;
    api: UpdateRegistrar;
    addScope: (options: UpdateRegistrarOptions) => void;
}

type RegistrarWindow = Window & { __mbfdUpdateRegistrar?: RegistrarState };

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function normalizeRelease(value: unknown): string | null {
    return typeof value === 'string' && RELEASE_PATTERN.test(value.trim().toLowerCase()) ? value.trim().toLowerCase() : null;
}

function readMetaRelease(): string | null {
    return normalizeRelease(document.querySelector(RELEASE_META)?.getAttribute('content'));
}

async function fetchRelease(): Promise<string | null> {
    try {
        const response = await fetch(RELEASE_ENDPOINT, {
            cache: 'no-store',
            credentials: 'omit',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok || !(response.headers.get('content-type') ?? '').includes('json')) return null;
        const body = (await response.json()) as { release?: unknown };

        return normalizeRelease(body.release);
    } catch {
        return null;
    }
}

/** Same-origin absolute URL for a navigation request, or null when it is not safe. */
function sameOriginHref(value: unknown): string | null {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin && (url.protocol === 'https:' || url.protocol === 'http:') ? url.href : null;
    } catch {
        return null;
    }
}

function isCurrentLocation(href: string): boolean {
    const target = new URL(href);

    return target.pathname === window.location.pathname && target.search === window.location.search;
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

// ---------------------------------------------------------------------------
// Reload guard: one automatic reload per release, provably.
// ---------------------------------------------------------------------------

const memoryGuard = new Set<string>();
let probedStorage: Storage | null | undefined;

function guardStorage(): Storage | null {
    if (probedStorage !== undefined) return probedStorage;
    try {
        const probe = '__mbfd_guard_probe__';
        window.sessionStorage.setItem(probe, '1');
        window.sessionStorage.removeItem(probe);
        probedStorage = window.sessionStorage;
    } catch {
        probedStorage = null;
    }

    return probedStorage;
}

function hasReloadedFor(release: string): boolean {
    if (memoryGuard.has(release)) return true;
    try {
        return guardStorage()?.getItem(GUARD_PREFIX + release) != null;
    } catch {
        return false;
    }
}

function markReloadedFor(release: string): void {
    memoryGuard.add(release);
    try {
        guardStorage()?.setItem(GUARD_PREFIX + release, String(Date.now()));
    } catch {
        // canGuardReloads() keeps automatic reloads off when storage is unusable.
    }
}

/** Undo markReloadedFor when the reload it announced was abandoned before it happened. */
function unmarkReloadedFor(release: string): void {
    memoryGuard.delete(release);
    try {
        guardStorage()?.removeItem(GUARD_PREFIX + release);
    } catch {
        // Nothing to undo when storage is unusable.
    }
}

/** Without a working sessionStorage a reload could repeat, so automatic reloads are off. */
function canGuardReloads(): boolean {
    return guardStorage() !== null;
}

// ---------------------------------------------------------------------------
// Banner (accessible, non-blocking, 44px targets)
// ---------------------------------------------------------------------------

function ensureStyle(): void {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
.mbfd-pwa-banner { position: fixed; z-index: var(--hub-z-toast, 80); left: 12px; right: 12px; bottom: calc(12px + env(safe-area-inset-bottom, 0px)); margin-inline: auto; max-width: 520px; display: flex; flex-direction: column; gap: 8px; pointer-events: none; font-family: var(--hub-font-sans, system-ui, -apple-system, 'Segoe UI', sans-serif); }
.mbfd-pwa-banner__row { pointer-events: auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px 12px; padding: 8px 8px 8px 16px; border: 1px solid rgb(var(--hub-border, 216 226 236)); border-radius: var(--hub-radius-lg, 8px); background: rgb(var(--hub-surface, 255 255 255)); color: rgb(var(--hub-ink, 15 23 42)); box-shadow: var(--hub-shadow-elevated, 0 8px 24px -8px rgb(1 39 102 / .18)); font-size: 14px; line-height: 1.45; }
.mbfd-pwa-banner__text { flex: 1 1 200px; min-width: 0; }
.mbfd-pwa-banner__actions { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.mbfd-pwa-banner :is(button, a) { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 0 14px; border: 0; border-radius: var(--hub-radius-md, 6px); background: transparent; color: rgb(var(--hub-blue, 30 78 140)); font: inherit; font-weight: 650; text-decoration: none; cursor: pointer; }
.mbfd-pwa-banner [data-mbfd-primary] { background: rgb(var(--hub-blue, 30 78 140)); color: rgb(var(--hub-n-0, 255 255 255)); }
.mbfd-pwa-banner [aria-disabled="true"] { opacity: .55; cursor: not-allowed; }
.mbfd-pwa-banner :is(button, a):focus-visible { outline: 2px solid rgb(var(--hub-focus, 29 78 216)); outline-offset: 2px; }
@media (max-width: 639.98px) { body:has(.hub-member-nav) .mbfd-pwa-banner { bottom: calc(var(--hub-member-nav-height, 66px) + 12px + env(safe-area-inset-bottom, 0px)); } }
@media (min-width: 1280px) and (pointer: fine) { body:has([data-admin-status-bar]) .mbfd-pwa-banner { bottom: 56px; } }
@media print { .mbfd-pwa-banner { display: none; } }
`;
    document.head.appendChild(style);
}

function bannerRoot(): HTMLElement {
    let root = document.getElementById(BANNER_ID);
    if (!root) {
        ensureStyle();
        root = document.createElement('div');
        root.id = BANNER_ID;
        root.className = 'mbfd-pwa-banner';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        root.setAttribute('aria-atomic', 'false');
        document.body.appendChild(root);
    }

    return root;
}

function removeRow(name: string): void {
    document.querySelector(`#${BANNER_ID} [data-mbfd-row="${name}"]`)?.remove();
    const root = document.getElementById(BANNER_ID);
    if (root && root.childElementCount === 0) root.remove();
}

interface RowAction {
    label: string;
    primary?: boolean;
    disabled?: boolean;
    href?: string;
    onActivate?: () => void;
}

function renderRow(name: string, message: string, actions: RowAction[]): void {
    const root = bannerRoot();
    let row = root.querySelector<HTMLElement>(`[data-mbfd-row="${name}"]`);
    if (!row) {
        row = document.createElement('div');
        row.className = 'mbfd-pwa-banner__row';
        row.dataset.mbfdRow = name;
        root.appendChild(row);
    }
    row.replaceChildren();

    const text = document.createElement('span');
    text.className = 'mbfd-pwa-banner__text';
    text.textContent = message;
    row.appendChild(text);

    const group = document.createElement('span');
    group.className = 'mbfd-pwa-banner__actions';
    for (const action of actions) {
        const control = document.createElement(action.href ? 'a' : 'button');
        control.textContent = action.label;
        if (action.href) {
            (control as HTMLAnchorElement).href = action.href;
        } else {
            (control as HTMLButtonElement).type = 'button';
        }
        if (action.primary) control.setAttribute('data-mbfd-primary', '');
        if (action.disabled) {
            control.setAttribute('aria-disabled', 'true');
        }
        control.addEventListener('click', (event) => {
            if (control.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();

                return;
            }
            action.onActivate?.();
        });
        group.appendChild(control);
    }
    row.appendChild(group);
}

// ---------------------------------------------------------------------------
// Service-worker registration helpers
// ---------------------------------------------------------------------------

async function findRegistration(scope: string): Promise<ServiceWorkerRegistration | undefined> {
    if (!('serviceWorker' in navigator)) return undefined;
    try {
        const registration = await navigator.serviceWorker.getRegistration(scope);

        return registration && new URL(registration.scope).pathname === scope ? registration : undefined;
    } catch {
        return undefined;
    }
}

/**
 * Wait for the installing worker to finish installing (or give up after a while).
 * Resolves false only when the install failed (the worker became redundant, for
 * example because a precache download failed), so the caller can try again later.
 */
function waitForWorkerToSettle(registration: ServiceWorkerRegistration): Promise<boolean> {
    const worker = registration.installing ?? registration.waiting;
    if (!worker || worker.state === 'activated') return Promise.resolve(true);
    if (worker.state === 'redundant') return Promise.resolve(false);

    return new Promise((resolve) => {
        const finish = () => {
            worker.removeEventListener('statechange', onChange);
            resolve(worker.state !== 'redundant');
        };
        const onChange = () => {
            if (worker.state === 'installed' || worker.state === 'activated' || worker.state === 'redundant') finish();
        };
        worker.addEventListener('statechange', onChange);
        window.setTimeout(finish, WORKER_SETTLE_TIMEOUT_MS);
    });
}

/** Ask a waiting worker to take over (it already did if the worker calls skipWaiting itself). */
async function activateWaitingWorkers(scopes: string[]): Promise<void> {
    for (const scope of scopes) {
        const waiting = (await findRegistration(scope))?.waiting;
        if (!waiting) continue;
        const changed = new Promise<void>((resolve) => {
            navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true });
        });
        waiting.postMessage({ type: 'SKIP_WAITING' });
        await Promise.race([changed, delay(ACTIVATE_TIMEOUT_MS)]);
    }
}

// ---------------------------------------------------------------------------
// The registrar
// ---------------------------------------------------------------------------

function createRegistrar(first: UpdateRegistrarOptions): RegistrarState {
    const scopes = new Map<string, UpdateRegistrarOptions>();
    let baseline: string | null = readMetaRelease();
    let pending: string | null = null;
    let pendingReason: 'release' | 'chunk' = 'release';
    let lastCheckAt = 0;
    let timer: number | undefined;
    let dismissedFor: string | null = null;
    let waitingNavigation: string | null = null;
    // A lazy chunk failed while the release probe could not be reached: say so, never reload.
    let offlineNotice = false;
    // The last registration.update() for the pending release did not complete, so the next
    // check (a return to the app, the network coming back) must try it again.
    let updateFailed = false;
    let updateGeneration = 0;
    // Safety state the update banner was last drawn with (null: not drawn).
    let renderedUnsafe: boolean | null = null;

    // -- applying ----------------------------------------------------------

    async function applyAndReload(release: string, manual = false): Promise<void> {
        markReloadedFor(release);
        try {
            await activateWaitingWorkers(Array.from(scopes.keys()));
        } catch {
            // Reload anyway: the new worker also takes over on its own.
        }
        // Activating a worker can take a moment (up to ACTIVATE_TIMEOUT_MS). If a draft, a
        // queued submission or a call started meanwhile, the page must not be reloaded
        // underneath it: give the reload back (the guard is only for reloads that happen).
        if (manual ? isUnsafeToRefresh() : isUnsafeForAutomaticReload()) {
            unmarkReloadedFor(release);
            renderUpdateBanner();

            return;
        }
        window.location.reload();
    }

    function refreshNow(): void {
        if (pending === null) return;
        // Click-time recheck: the member may have started typing since the banner last rendered.
        if (isUnsafeToRefresh()) {
            renderUpdateBanner();

            return;
        }
        void applyAndReload(pending, true);
    }

    function automaticReloadAllowed(release: string): boolean {
        return !isUnsafeForAutomaticReload() && !hasReloadedFor(release) && canGuardReloads();
    }

    // -- banner state ------------------------------------------------------

    function renderUpdateBanner(): void {
        if (pending === null || dismissedFor === pending) {
            removeRow('update');
            renderedUnsafe = null;

            return;
        }
        const busy = isUnsafeToRefresh();
        renderedUnsafe = busy;
        const message = pendingReason === 'chunk' ? MESSAGE_CHUNK : busy ? MESSAGE_BUSY : MESSAGE_READY;
        renderRow('update', message, [
            { label: 'Refresh now', primary: true, disabled: busy, onActivate: refreshNow },
            {
                label: 'Later',
                onActivate: () => {
                    dismissedFor = pending;
                    renderUpdateBanner();
                },
            },
        ]);
    }

    function renderNavigationBanner(): void {
        if (waitingNavigation === null) {
            removeRow('navigate');

            return;
        }
        renderRow('navigate', MESSAGE_NAVIGATE, [
            { label: 'Open notification', primary: true, href: waitingNavigation },
            {
                label: 'Dismiss',
                onActivate: () => {
                    waitingNavigation = null;
                    renderNavigationBanner();
                },
            },
        ]);
    }

    /** Decide what the current state calls for: banner, automatic reload, or nothing. */
    function evaluate(): void {
        renderUpdateBanner();
        if (pending !== null && pendingReason === 'release' && document.hidden && automaticReloadAllowed(pending)) {
            void applyAndReload(pending);
        }
    }

    // -- release detection -------------------------------------------------

    /**
     * Existing registration for the scope, or (only when the caller supplied a
     * scriptUrl and nothing is registered yet) one new registration with the
     * same URL, scope and updateViaCache every other caller uses.
     */
    async function registrationFor(options: UpdateRegistrarOptions): Promise<ServiceWorkerRegistration | undefined> {
        const existing = await findRegistration(options.scope);
        if (existing || !options.scriptUrl || !('serviceWorker' in navigator)) return existing;
        try {
            return await navigator.serviceWorker.register(options.scriptUrl, { scope: options.scope, updateViaCache: 'none' });
        } catch {
            return undefined;
        }
    }

    /** Resolves false when the worker update could not run (offline, script fetch or install failed). */
    async function updateScope(options: UpdateRegistrarOptions): Promise<boolean> {
        const registration = await registrationFor(options);
        if (!registration) return true;
        try {
            await registration.update();

            return await waitForWorkerToSettle(registration);
        } catch {
            // Offline or a transient failure; the page-level banner still works and the
            // update is tried again on the next check (see updateFailed).
            return false;
        }
    }

    async function updateAllScopes(): Promise<void> {
        const mine = ++updateGeneration;
        const results = await Promise.all(Array.from(scopes.values()).map((options) => updateScope(options)));
        // Overlapping runs: only the newest one may decide whether a retry is still owed.
        if (mine === updateGeneration) updateFailed = results.includes(false);
    }

    async function onNewRelease(release: string): Promise<void> {
        pending = release;
        pendingReason = 'release';
        dismissedFor = null;
        await updateAllScopes();
        for (const options of scopes.values()) {
            try {
                options.onUpdateReady?.({ release, scope: options.scope });
            } catch {
                // A faulty callback must not stop the update flow.
            }
        }
        evaluate();
    }

    async function checkNow(force = false): Promise<void> {
        const now = Date.now();
        if (!force && now - lastCheckAt < MIN_CHECK_GAP_MS) return;
        lastCheckAt = now;

        const latest = await fetchRelease();
        if (latest === null) {
            // The probe did not answer (offline, a blip). That is not a check, so the next
            // trigger (the network coming back, returning to the app) must not be rate limited.
            lastCheckAt = 0;

            return;
        }
        // The server answered, so the "you're offline" notice from a failed chunk is out of date.
        clearOfflineNotice();
        if (baseline === null) {
            baseline = latest;

            return;
        }
        if (latest === baseline) {
            if (pending !== null && pendingReason === 'release') {
                pending = null;
                updateFailed = false;
                evaluate();
            }

            return;
        }
        if (latest !== pending) {
            await onNewRelease(latest);
        } else if (updateFailed) {
            await updateAllScopes();
            evaluate();
        }
    }

    function scheduleNextCheck(): void {
        window.clearTimeout(timer);
        const jitter = 0.85 + Math.random() * 0.3;
        timer = window.setTimeout(() => {
            if (!document.hidden) void checkNow(true);
            scheduleNextCheck();
        }, CHECK_INTERVAL_MS * jitter);
    }

    // -- chunk-load recovery -----------------------------------------------

    /**
     * Reload for a failed lazy chunk only on positive evidence the network is up (the release probe
     * answered) AND a reason a reload will help (a different release is live, or the failed file
     * really answers 404). Offline or a failed probe never reloads: the browser's own error page
     * would replace a page that still works. See ./chunk-recovery for the rules.
     */
    async function recoverFromChunkError(event: Event): Promise<void> {
        const latest = await fetchRelease();
        const releaseChanged = latest !== null && baseline !== null && latest !== baseline;
        if (releaseChanged) {
            // Keep the workers current too; the reload below would otherwise race their update check.
            await updateAllScopes();
        }
        const target = latest ?? pending ?? baseline ?? 'unknown';
        const chunkMissing = latest !== null && !releaseChanged && (await isChunkMissing(event));
        const action = decideChunkRecovery({
            latestRelease: latest,
            baselineRelease: baseline,
            chunkMissing,
            mayReload: !isUnsafeForAutomaticReload() && !hasReloadedFor(target) && canGuardReloads(),
        });

        if (action === 'offline') {
            offlineNotice = true;
            renderOfflineNotice();

            return;
        }
        clearOfflineNotice();
        pending = target;
        pendingReason = 'chunk';
        dismissedFor = null;
        if (action === 'reload') {
            await applyAndReload(target);

            return;
        }
        renderUpdateBanner();
    }

    async function isChunkMissing(event: Event): Promise<boolean> {
        const href = chunkUrlFromPreloadError(event, window.location.origin);
        if (href === null) return false;
        try {
            const response = await fetch(href, { method: 'HEAD', cache: 'no-store', credentials: 'omit' });

            return isMissingStatus(response.status);
        } catch {
            return false;
        }
    }

    function renderOfflineNotice(): void {
        if (!offlineNotice) {
            removeRow('offline');

            return;
        }
        renderRow('offline', MESSAGE_OFFLINE, [
            {
                label: 'Dismiss',
                onActivate: () => {
                    offlineNotice = false;
                    renderOfflineNotice();
                },
            },
        ]);
    }

    function clearOfflineNotice(): void {
        if (!offlineNotice) return;
        offlineNotice = false;
        renderOfflineNotice();
    }

    // -- notification navigation -------------------------------------------

    function onWorkerMessage(event: MessageEvent): void {
        const data = event.data as { type?: unknown; url?: unknown } | null;
        if (!data || data.type !== 'MBFD_NAVIGATE') return;

        const href = sameOriginHref(data.url);
        const deferred = href !== null && !isCurrentLocation(href) && isUnsafeForAutomaticReload();
        // Acknowledge so the worker knows a page owns this click and opens no second window.
        try {
            event.ports?.[0]?.postMessage({ type: 'MBFD_NAVIGATE_ACK', deferred });
        } catch {
            // The worker stopped listening; nothing to do.
        }
        if (href === null || isCurrentLocation(href)) return;

        if (deferred) {
            waitingNavigation = href;
            renderNavigationBanner();

            return;
        }
        window.location.assign(href);
    }

    // -- wiring --------------------------------------------------------------

    function addScope(options: UpdateRegistrarOptions): void {
        if (scopes.has(options.scope)) return;
        scopes.set(options.scope, options);
        if (options.scriptUrl) void registrationFor(options);
    }

    scopes.set(first.scope, first);

    // Rich-text editors and file pickers hold work the DOM comparison cannot see.
    startFieldEditTracking();

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) evaluate();
        else void checkNow();
    });
    // Network regained is the strongest hint that a release may be waiting (a device that was
    // offline for days), so it is never rate limited.
    window.addEventListener('online', () => void checkNow(true));
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) void checkNow();
    });
    window.addEventListener('vite:preloadError', (event) => {
        event.preventDefault();
        void recoverFromChunkError(event);
    });
    onBusyChange(() => {
        evaluate();
        renderNavigationBanner();
    });
    // Typing (or clearing a field) changes whether a refresh is safe without any busy event:
    // keep the banner honest. Cheap, and only while an update is waiting.
    let inputRenderQueued = false;
    const onFieldInput = (): void => {
        if (pending === null || inputRenderQueued) return;
        inputRenderQueued = true;
        window.requestAnimationFrame(() => {
            inputRenderQueued = false;
            if (pending !== null && renderedUnsafe !== null && renderedUnsafe !== isUnsafeToRefresh()) renderUpdateBanner();
        });
    };
    document.addEventListener('input', onFieldInput, true);
    document.addEventListener('change', onFieldInput, true);
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', onWorkerMessage);
        (navigator.serviceWorker as ServiceWorkerContainer & { startMessages?: () => void }).startMessages?.();
    }

    scheduleNextCheck();
    // The first check also captures the baseline when the page carries no meta tag.
    void checkNow(true);
    if (first.scriptUrl) void registrationFor(first);

    return {
        version: 1,
        addScope,
        api: {
            checkNow,
            refreshNow,
            currentRelease: () => baseline,
            pendingRelease: () => pending,
        },
    };
}

/**
 * Start (or join) the page's single update registrar. Safe to call from several
 * bundles on the same page: only the first call creates listeners and timers.
 */
export function startUpdateRegistrar(options: UpdateRegistrarOptions): UpdateRegistrar {
    const target = window as RegistrarWindow;

    if (target.__mbfdUpdateRegistrar) {
        target.__mbfdUpdateRegistrar.addScope(options);

        return target.__mbfdUpdateRegistrar.api;
    }

    // Embedded frames (previews, kiosks) must not show banners or reload their host's content.
    if (window.top !== window.self) {
        return { checkNow: async () => {}, refreshNow: () => {}, currentRelease: () => null, pendingRelease: () => null };
    }

    const state = createRegistrar(options);
    target.__mbfdUpdateRegistrar = state;

    return state.api;
}
