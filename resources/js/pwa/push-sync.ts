/**
 * Gets this device receiving alerts with as little as the platform allows, on every signed-in page.
 *
 * Browsers will not let a site turn on notifications by itself: the first time, the person holding the device must
 * tap and allow. So the module does everything short of that, and makes that one tap easy:
 *
 *  - Subscribed, but the Hub lost track of it (a save that failed, rows removed at sign-out): save it. No prompt.
 *  - Permission already given, no subscription: subscribe on the next tap anywhere on the page. No prompt.
 *  - Permission not asked yet, in an installed app: a small card with one button that does the whole thing in place,
 *    which also covers a new member opening the app for the first time.
 *  - Blocked by the phone, or a browser tab rather than the installed app: nothing here (the settings page explains).
 *
 * Rules it keeps:
 *  - A member who chose "Turn off on this device" stays off, and a device they removed from their list stays removed.
 *  - Only the one button can show the permission prompt, and it asks first thing in the tap, as iOS requires.
 *  - It only registers /sw.js with the same options as the settings block, and never removes a worker or subscription.
 *  - Any failure leaves everything as it was and tries again on a later page.
 */

import {
    ROOT_WORKER_SCOPE,
    ROOT_WORKER_URL,
    TURNED_OFF_KEY,
    arrayBufferToBase64Url,
    canUsePush,
    classifySubscriptions,
    findDeviceSubscriptions,
    preferredContentEncoding,
    readEnvironment,
    sha256Hex,
    urlBase64ToUint8Array,
    whenActive,
    type DeviceEnvironment,
    type RegistrationSource,
} from './push-enrollment.ts';

/** One check per tab per this long. The check itself is one small read. */
const CHECK_INTERVAL_MS = 5 * 60_000;
const CHECKED_KEY = 'mbfd.push.checked-at';

/** "Not now" is respected for this long. */
const SNOOZE_MS = 14 * 24 * 60 * 60_000;
const SNOOZED_KEY = 'mbfd.push.snoozed-at';

type StorageLike = Pick<Storage, 'getItem' | 'setItem'> | null;

export interface PushSyncDeps {
    win: Parameters<typeof readEnvironment>[0];
    registrations: RegistrationSource | null | undefined;
    fetch: typeof fetch;
    now: () => number;
    storage: StorageLike;
    csrfHeaders: () => Record<string, string>;
}

export type PushSyncOutcome = 'skipped' | 'unavailable' | 'no-subscription' | 'in-step' | 'saved' | 'removed' | 'failed';

function recentlyChecked(deps: PushSyncDeps): boolean {
    try {
        const last = Number(deps.storage?.getItem(CHECKED_KEY) ?? 0);

        return last > 0 && deps.now() - last < CHECK_INTERVAL_MS;
    } catch {
        return false;
    }
}

function markChecked(deps: PushSyncDeps): void {
    try {
        deps.storage?.setItem(CHECKED_KEY, String(deps.now()));
    } catch {
        // Private mode or blocked storage: it only means another harmless check later.
    }
}

/** The saved form of a subscription, or null when the browser will not hand over its keys. */
export function subscriptionBody(subscription: PushSubscription, win: PushSyncDeps['win'], sync: boolean): string | null {
    const p256dh = arrayBufferToBase64Url(subscription.getKey('p256dh'));
    const auth = arrayBufferToBase64Url(subscription.getKey('auth'));
    if (p256dh === '' || auth === '') {
        return null;
    }
    const encodings = (win.PushManager as { supportedContentEncodings?: readonly string[] } | undefined)?.supportedContentEncodings;

    return JSON.stringify({
        endpoint: subscription.endpoint,
        keys: { p256dh, auth },
        content_encoding: preferredContentEncoding(encodings),
        ...(sync ? { sync: true } : {}),
    });
}

/** Makes sure the Hub knows about the subscriptions this browser holds. Never prompts and never creates one. */
export async function syncDeviceSubscription(deps: PushSyncDeps): Promise<PushSyncOutcome> {
    const environment = readEnvironment(deps.win);
    if (!canUsePush(environment) || environment.permission !== 'granted') {
        return 'unavailable';
    }
    if (recentlyChecked(deps)) {
        return 'skipped';
    }
    markChecked(deps);

    const subscriptions = await findDeviceSubscriptions(null, deps.registrations);
    if (subscriptions.length === 0) {
        return 'no-subscription';
    }

    try {
        const keyResponse = await deps.fetch('/api/push/vapid-public-key', { headers: { Accept: 'application/json' } });
        const { publicKey } = (await keyResponse.json()) as { publicKey?: string };
        if (!keyResponse.ok || !publicKey) {
            return 'failed';
        }

        let outcome: PushSyncOutcome = 'in-step';
        for (const subscription of classifySubscriptions(subscriptions, urlBase64ToUint8Array(publicKey)).usable) {
            const hash = await sha256Hex(subscription.endpoint);
            const stateResponse = await deps.fetch(`/api/push/device-state?hash=${hash}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!stateResponse.ok) {
                return 'failed';
            }

            const state = (await stateResponse.json()) as { known?: boolean; refresh?: boolean; removed?: boolean };
            if (state.removed === true) {
                outcome = 'removed';
                continue;
            }
            if (state.known === true && state.refresh !== true) {
                continue;
            }

            const body = subscriptionBody(subscription, deps.win, true);
            if (body === null) {
                return 'failed';
            }
            const saved = await deps.fetch('/api/push-subscriptions', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...deps.csrfHeaders() },
                body,
            });
            if (!saved.ok) {
                return saved.status === 409 ? 'removed' : 'failed';
            }
            outcome = 'saved';
        }

        return outcome;
    } catch {
        return 'failed';
    }
}

export type AlertSetup = 'none' | 'on-next-tap' | 'offer';

/**
 * What to do on a device that has no subscription. Only the installed app acts: a browser tab may be a shared computer
 * or someone just looking, and iPhones cannot receive push there at all.
 */
export function alertSetupFor(environment: DeviceEnvironment, outcome: PushSyncOutcome, storage: Pick<Storage, 'getItem'> | null, now: number): AlertSetup {
    if (!environment.standalone || !canUsePush(environment)) {
        return 'none';
    }

    try {
        if (storage?.getItem(TURNED_OFF_KEY) === '1') {
            return 'none';
        }
        if (environment.permission === 'granted') {
            return outcome === 'no-subscription' ? 'on-next-tap' : 'none';
        }
        if (environment.permission !== 'default') {
            return 'none';
        }
        const snoozed = Number(storage?.getItem(SNOOZED_KEY) ?? 0);

        return snoozed > 0 && now - snoozed < SNOOZE_MS ? 'none' : 'offer';
    } catch {
        return 'none';
    }
}

export function snoozeOffer(storage: StorageLike, now: number): void {
    try {
        storage?.setItem(SNOOZED_KEY, String(now));
    } catch {
        // Blocked storage only means the card may appear again on a later page.
    }
}

/* ------------------------------------------------------------------ DOM wiring */

const STYLES = `
.mbfd-push-offer{position:fixed;z-index:60;inset-inline:max(1rem,env(safe-area-inset-left,0px));inset-block-end:calc(env(safe-area-inset-bottom,0px) + 5rem);max-width:26rem;margin-inline:auto;padding:1rem;border-radius:.875rem;border:1px solid #d6dde8;background:#fff;color:#0f1b2d;box-shadow:0 12px 32px rgba(1,39,102,.22);font:500 .9375rem/1.4 'Plus Jakarta Sans',system-ui,sans-serif}
.mbfd-push-offer__title{margin:0;font-weight:700}
.mbfd-push-offer__body{margin:.25rem 0 .75rem;color:#42526b}
.mbfd-push-offer__actions{display:flex;flex-wrap:wrap;gap:.5rem}
.mbfd-push-offer__on,.mbfd-push-offer__later{min-height:2.75rem;padding:0 1rem;border-radius:.5rem;font:inherit;font-weight:700;cursor:pointer}
.mbfd-push-offer__on{border:1px solid #012766;background:#012766;color:#fff}
.mbfd-push-offer__later{border:1px solid #94a3b8;background:#fff;color:#0f1b2d}
.mbfd-push-offer__on:focus-visible,.mbfd-push-offer__later:focus-visible{outline:2px solid #012766;outline-offset:2px}
.mbfd-push-offer__on:disabled{opacity:.7;cursor:progress}
`;

function csrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (meta) {
        return { 'X-CSRF-TOKEN': meta, 'X-Requested-With': 'XMLHttpRequest' };
    }

    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return {
        'X-Requested-With': 'XMLHttpRequest',
        ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {}),
    };
}

function localStore(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

/** Subscribes on the root registration and saves it. Called only from inside a tap. */
async function subscribeAndSave(registration: ServiceWorkerRegistration, publicKey: string): Promise<boolean> {
    const key = urlBase64ToUint8Array(publicKey);
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key.buffer.slice(key.byteOffset, key.byteOffset + key.byteLength) as ArrayBuffer,
    });

    const body = subscriptionBody(subscription, window, false);
    const saved = body === null
        ? null
        : await fetch('/api/push-subscriptions', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
              body,
          });
    if (saved?.ok === true) {
        return true;
    }

    // The Hub could not save it: do not leave a subscription nothing will ever send to.
    await subscription.unsubscribe().catch(() => false);

    return false;
}

function showOffer(registration: ServiceWorkerRegistration, publicKey: string): void {
    const style = document.createElement('style');
    style.textContent = STYLES;
    document.head.appendChild(style);

    const card = document.createElement('section');
    card.className = 'mbfd-push-offer';
    card.setAttribute('aria-label', 'Turn on alerts');
    card.innerHTML = `
        <p class="mbfd-push-offer__title">Get MBFD Hub alerts on this device</p>
        <p class="mbfd-push-offer__body" role="status">Tap below, then choose Allow.</p>
        <div class="mbfd-push-offer__actions">
            <button type="button" class="mbfd-push-offer__on">Turn on alerts</button>
            <button type="button" class="mbfd-push-offer__later">Not now</button>
        </div>`;

    const status = card.querySelector<HTMLElement>('.mbfd-push-offer__body');
    const turnOn = card.querySelector<HTMLButtonElement>('.mbfd-push-offer__on');
    const later = card.querySelector<HTMLButtonElement>('.mbfd-push-offer__later');
    const say = (text: string): void => {
        if (status) {
            status.textContent = text;
        }
    };

    later?.addEventListener('click', () => {
        snoozeOffer(localStore(), Date.now());
        card.remove();
    });

    turnOn?.addEventListener('click', () => {
        if (turnOn) {
            turnOn.disabled = true;
        }
        void (async () => {
            try {
                // First awaited call: iOS only shows the prompt straight from the tap.
                const permission = await Notification.requestPermission();
                if (permission === 'denied') {
                    say('Alerts are blocked. You can allow them for MBFD Hub in this device\'s settings.');
                    turnOn?.remove();
                    if (later) {
                        later.textContent = 'Close';
                    }

                    return;
                }
                if (permission !== 'granted') {
                    say('No problem. You can turn alerts on any time in Notification settings.');
                    snoozeOffer(localStore(), Date.now());
                    setTimeout(() => card.remove(), 4000);

                    return;
                }

                if (await subscribeAndSave(registration, publicKey)) {
                    say('Alerts are on for this device.');
                    turnOn?.remove();
                    later?.remove();
                    setTimeout(() => card.remove(), 4000);

                    return;
                }
                say('Alerts could not be turned on just now. Please try again.');
            } catch {
                say('Alerts could not be turned on just now. Please try again.');
            }
            if (turnOn) {
                turnOn.disabled = false;
            }
        })();
    });

    document.body.appendChild(card);
}

/** Runs once per page after it has loaded: this is housekeeping, not part of showing the page. */
export function startPushSync(): void {
    const target = window as Window & { __mbfdPushSyncStarted?: boolean };
    if (target.__mbfdPushSyncStarted === true
        || document.querySelector('meta[name="mbfd-alerts-feed"]') === null
        || !('serviceWorker' in navigator)
        // The settings page has its own button, and shared screens get nothing personal.
        || document.querySelector('[data-push-enrollment]') !== null
        || new URLSearchParams(window.location.search).get('display') === '1'
        || window.location.pathname.includes('/kiosk')) {
        return;
    }
    target.__mbfdPushSyncStarted = true;

    const run = async (): Promise<void> => {
        let session: Storage | null = null;
        try {
            session = window.sessionStorage;
        } catch {
            session = null;
        }

        const outcome = await syncDeviceSubscription({
            win: window,
            registrations: navigator.serviceWorker as unknown as RegistrationSource,
            fetch: window.fetch.bind(window),
            now: () => Date.now(),
            storage: session,
            csrfHeaders,
        });

        const setup = alertSetupFor(readEnvironment(window), outcome, localStore(), Date.now());
        if (setup === 'none') {
            return;
        }

        const keyResponse = await fetch('/api/push/vapid-public-key', { headers: { Accept: 'application/json' } });
        const { publicKey } = (await keyResponse.json()) as { publicKey?: string };
        if (!keyResponse.ok || !publicKey) {
            return;
        }

        // Ready before any tap, so nothing is awaited between the tap and the permission prompt.
        const registration = await navigator.serviceWorker
            .register(ROOT_WORKER_URL, { scope: ROOT_WORKER_SCOPE, updateViaCache: 'none' })
            .then(whenActive);

        if (setup === 'offer') {
            showOffer(registration, publicKey);

            return;
        }

        document.addEventListener('pointerup', () => {
            void subscribeAndSave(registration, publicKey).catch(() => false);
        }, { once: true, passive: true });
    };

    const later = (): void => {
        setTimeout(() => void run().catch(() => undefined), 2000);
    };
    if (document.readyState === 'complete') {
        later();
    } else {
        window.addEventListener('load', later, { once: true });
    }
}
