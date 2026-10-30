/**
 * "This device" block of Notification settings: turn push alerts on or off for the
 * phone or computer in front of the member, and show plainly what is and is not working.
 *
 * Rules this module follows (see the push platform research note):
 *  - Nothing asks for permission or subscribes except the enable button's click handler.
 *  - Permission is the first awaited call in that handler and `pushManager.subscribe`
 *    follows it immediately; the VAPID key is already on the page, so no network is awaited first.
 *  - New subscriptions are bound to the ROOT service worker registration: the same
 *    `/sw.js` URL with scope `/` the Hub already uses. This module never invents a new worker URL.
 *  - An existing subscription on ANY registration (root, admin or Daily) means the device is already
 *    enrolled: it is synced to the server instead of creating a second one, and "Turn off" removes
 *    every subscription on the device.
 *  - iPhone and iPad can only receive push from a Home Screen app, so a Safari tab is shown
 *    plain guidance instead of a button.
 *  - On every load the browser's own subscription is compared with the server's list and repaired.
 *  - In-app alerts are the fallback and always arrive; push is an extra.
 *
 * The pure helpers are exported for tests; the DOM wiring only runs where the block exists.
 */

import { describeTestResult } from './test-push-result.ts';

export type PermissionState = 'default' | 'granted' | 'denied' | 'unsupported';

export interface DeviceEnvironment {
    secureContext: boolean;
    hasServiceWorker: boolean;
    hasPushManager: boolean;
    permission: PermissionState;
    standalone: boolean;
    ios: boolean;
}

export type DeviceView =
    | 'ios-install'
    | 'unsupported'
    | 'blocked'
    | 'ready'
    | 'enabled'
    | 'needs-repair';

export interface DeviceStatus {
    supported: boolean;
    installed: boolean;
    permission: PermissionState;
    subscribed: boolean;
    enabled: boolean;
}

export interface DeviceSnapshot {
    environment: DeviceEnvironment;
    /** The browser holds a push subscription for this site. */
    subscribed: boolean;
    /** The server already lists that subscription for this member. */
    registeredOnServer: boolean;
}

interface NavigatorLike {
    userAgent?: string;
    platform?: string;
    maxTouchPoints?: number;
    standalone?: boolean;
    serviceWorker?: unknown;
}

interface WindowLike {
    navigator: NavigatorLike;
    isSecureContext?: boolean;
    matchMedia?: (query: string) => { matches: boolean };
    PushManager?: unknown;
    Notification?: { permission: string };
}

const DISPLAY_MODES = ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'];

export function readEnvironment(win: WindowLike): DeviceEnvironment {
    const nav = win.navigator;
    const userAgent = nav.userAgent ?? '';
    // iPadOS reports itself as a Mac; touch points tell them apart.
    const ios = /iPad|iPhone|iPod/.test(userAgent) || (nav.platform === 'MacIntel' && (nav.maxTouchPoints ?? 0) > 1);

    let standalone = nav.standalone === true;
    if (!standalone && typeof win.matchMedia === 'function') {
        standalone = DISPLAY_MODES.some((mode) => {
            try {
                return win.matchMedia?.(`(display-mode: ${mode})`).matches === true;
            } catch {
                return false;
            }
        });
    }

    // Notification is undefined in an iOS Safari tab; never touch it unguarded.
    const rawPermission = typeof win.Notification !== 'undefined' ? win.Notification?.permission : undefined;
    const permission: PermissionState =
        rawPermission === 'granted' || rawPermission === 'denied' || rawPermission === 'default' ? rawPermission : 'unsupported';

    return {
        secureContext: win.isSecureContext !== false,
        hasServiceWorker: 'serviceWorker' in nav,
        hasPushManager: typeof win.PushManager !== 'undefined',
        permission,
        standalone,
        ios,
    };
}

/** Can this browser, in its current form, receive push at all? */
export function canUsePush(env: DeviceEnvironment): boolean {
    return env.secureContext && env.hasServiceWorker && env.hasPushManager && env.permission !== 'unsupported';
}

export function deriveView(snapshot: DeviceSnapshot): DeviceView {
    const { environment: env } = snapshot;

    if (env.ios && !env.standalone) {
        return 'ios-install';
    }
    if (!canUsePush(env)) {
        return 'unsupported';
    }
    if (env.permission === 'denied') {
        return 'blocked';
    }
    if (snapshot.subscribed && env.permission === 'granted') {
        return snapshot.registeredOnServer ? 'enabled' : 'needs-repair';
    }

    return 'ready';
}

export function deriveStatus(snapshot: DeviceSnapshot): DeviceStatus {
    const { environment: env } = snapshot;
    const view = deriveView(snapshot);

    return {
        supported: canUsePush(env),
        installed: env.standalone,
        permission: env.permission,
        subscribed: snapshot.subscribed,
        enabled: view === 'enabled',
    };
}

export const MESSAGES: Record<DeviceView, string> = {
    'ios-install': 'Add MBFD Hub to your Home Screen to get alerts on this iPhone. Open the Share menu, choose "Add to Home Screen", then open MBFD Hub from the new icon and come back to this page.',
    unsupported: 'This browser cannot receive push alerts. In-app alerts still arrive in the bell, and you can turn on city email below if it is offered.',
    blocked: 'Alerts are blocked for this device. Allow notifications for MBFD Hub in your browser or phone settings, then reload this page.',
    ready: 'Push alerts are not on for this device yet. Tap "Turn on alerts for this device" and allow notifications when asked. In-app alerts still arrive in the bell.',
    enabled: 'Alerts are on for this device.',
    'needs-repair': 'Alerts were turned on here but the Hub lost track of this device. Turn them on again to repair it.',
};

/** Browsers hand back the VAPID key URL-safe; the subscribe call wants raw bytes. */
export function urlBase64ToUint8Array(value: string): Uint8Array {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let index = 0; index < raw.length; index += 1) {
        output[index] = raw.charCodeAt(index);
    }

    return output;
}

export function arrayBufferToBase64Url(buffer: ArrayBuffer | null): string {
    if (buffer === null) {
        return '';
    }
    let binary = '';
    new Uint8Array(buffer).forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export async function sha256Hex(text: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** The one service worker registration push belongs to: same URL and scope the Hub already uses. */
export const ROOT_WORKER_URL = '/sw.js';
export const ROOT_WORKER_SCOPE = '/';

/** Resolves once the registration has an activated worker (push needs one), without changing registrations. */
export async function whenActive(registration: ServiceWorkerRegistration, timeoutMs = 8000): Promise<ServiceWorkerRegistration> {
    if (registration.active?.state === 'activated') {
        return registration;
    }
    const worker = registration.installing ?? registration.waiting;
    if (worker === null) {
        return registration;
    }

    await new Promise<void>((resolve) => {
        // Never leave the buttons disabled behind a worker that stalls.
        const timer = setTimeout(resolve, timeoutMs);
        worker.addEventListener('statechange', () => {
            if (worker.state === 'activated' || worker.state === 'redundant') {
                clearTimeout(timer);
                resolve();
            }
        });
    });

    return registration;
}

/** True when the browser subscription was made with the server key this page carries. */
export function usesServerKey(subscription: { options?: { applicationServerKey?: ArrayBuffer | null } }, serverKey: Uint8Array): boolean {
    const current = subscription.options?.applicationServerKey;
    if (!current) {
        // Some browsers hide the key; trust the subscription rather than churn it.
        return true;
    }
    const bytes = new Uint8Array(current);

    return bytes.length === serverKey.length && bytes.every((byte, index) => byte === serverKey[index]);
}

/** The slice of a ServiceWorkerRegistration this module reads. */
export interface PushRegistrationLike {
    pushManager?: { getSubscription(): Promise<PushSubscription | null> };
}

export interface RegistrationSource {
    getRegistrations?: () => Promise<readonly PushRegistrationLike[]>;
}

/**
 * Every push subscription this browser holds for the site, across ALL service-worker registrations:
 * the root one (`/sw.js`), the admin one (`/admin/service-worker.js`) and the Daily one
 * (`/daily/sw.js`). A member may have enabled alerts through the admin page or the Daily app long
 * before this settings block existed, and that subscription lives on a different registration. If
 * only the root one were inspected, the block would report "off" for a device that is delivering
 * alerts, offer to create a second subscription, and "turn off" would leave the other one running.
 * Duplicates (the same subscription seen through two registration objects) are collapsed by endpoint.
 */
export async function findDeviceSubscriptions(
    root: PushRegistrationLike | null,
    source: RegistrationSource | null | undefined,
): Promise<PushSubscription[]> {
    const registrations: PushRegistrationLike[] = root === null ? [] : [root];
    try {
        for (const registration of (await source?.getRegistrations?.()) ?? []) {
            if (!registrations.includes(registration)) {
                registrations.push(registration);
            }
        }
    } catch {
        // Listing registrations can be refused; the root registration alone is still checked.
    }

    const byEndpoint = new Map<string, PushSubscription>();
    for (const registration of registrations) {
        try {
            const subscription = await registration.pushManager?.getSubscription();
            if (subscription && !byEndpoint.has(subscription.endpoint)) {
                byEndpoint.set(subscription.endpoint, subscription);
            }
        } catch {
            // One registration without push support must not hide the others.
        }
    }

    return Array.from(byEndpoint.values());
}

/** Split subscriptions into those made with this page's server key (they can receive) and those that cannot. */
export function classifySubscriptions(
    subscriptions: readonly PushSubscription[],
    serverKey: Uint8Array,
): { usable: PushSubscription[]; stale: PushSubscription[] } {
    return {
        usable: subscriptions.filter((subscription) => usesServerKey(subscription, serverKey)),
        stale: subscriptions.filter((subscription) => !usesServerKey(subscription, serverKey)),
    };
}

/** aes128gcm is the standard encoding (and Apple's only one); fall back only for very old browsers. */
export function preferredContentEncoding(supported: readonly string[] | undefined): 'aes128gcm' | 'aesgcm' {
    return supported && !supported.includes('aes128gcm') && supported.includes('aesgcm') ? 'aesgcm' : 'aes128gcm';
}

/* ------------------------------------------------------------------ DOM wiring */

interface Elements {
    root: HTMLElement;
    summary: HTMLElement;
    message: HTMLElement;
    enable: HTMLButtonElement;
    disable: HTMLButtonElement;
    test: HTMLButtonElement;
    values: Record<string, HTMLElement | null>;
}

function findElements(root: HTMLElement): Elements {
    const need = <T extends HTMLElement>(role: string): T => {
        const element = root.querySelector<T>(`[data-role="${role}"]`);
        if (element === null) {
            throw new Error(`push-enrollment: missing [data-role="${role}"]`);
        }

        return element;
    };

    return {
        root,
        summary: need('summary'),
        message: need('message'),
        enable: need<HTMLButtonElement>('enable'),
        disable: need<HTMLButtonElement>('disable'),
        test: need<HTMLButtonElement>('test'),
        values: Object.fromEntries(
            ['supported', 'installed', 'permission', 'subscribed', 'enabled'].map((name) => [name, root.querySelector<HTMLElement>(`[data-state="${name}"]`)]),
        ),
    };
}

const yesNo = (value: boolean): string => (value ? 'Yes' : 'No');

const PERMISSION_TEXT: Record<PermissionState, string> = {
    default: 'Not asked yet',
    granted: 'Allowed',
    denied: 'Blocked',
    unsupported: 'Not available here',
};

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function api(method: 'POST' | 'DELETE', url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
}

function notifyPage(): void {
    const livewire = (window as unknown as { Livewire?: { dispatch?: (name: string) => void } }).Livewire;
    livewire?.dispatch?.('push-devices-changed');
}

class EnrollmentBlock {
    private readonly elements: Elements;

    private readonly vapidKey: string;

    private serverHashes: Set<string>;

    private registration: Promise<ServiceWorkerRegistration | null> | null = null;

    /** Subscriptions seen on the last refresh, from every registration (read again by disable and test). */
    private known: PushSubscription[] = [];

    constructor(root: HTMLElement) {
        this.elements = findElements(root);
        this.vapidKey = root.dataset.vapidKey ?? '';
        this.serverHashes = new Set(parseHashes(root.dataset.endpointHashes));
    }

    start(): void {
        this.elements.enable.addEventListener('click', () => void this.onEnable());
        this.elements.disable.addEventListener('click', () => void this.onDisable());
        this.elements.test.addEventListener('click', () => void this.onTest());

        const environment = readEnvironment(window);
        if (canUsePush(environment)) {
            // Registering a worker needs no gesture; do it now so the click handler never waits on it.
            // The registration returned here is the root one. `navigator.serviceWorker.ready` is not
            // used: on /admin pages it resolves to the narrower /admin/ worker, which must not own this subscription.
            // Same URL, scope and updateViaCache as every other caller (push widget, admin page): a
            // differing option set would run a needless update job and flip the stored cache mode.
            this.registration = navigator.serviceWorker
                .register(ROOT_WORKER_URL, { scope: ROOT_WORKER_SCOPE, updateViaCache: 'none' })
                .then(whenActive)
                .catch(() => null);
        }

        void this.refresh(true);
    }

    /** Re-read the browser, compare with the server, repair a mismatch, repaint. */
    private async refresh(repair: boolean): Promise<void> {
        const environment = readEnvironment(window);
        let subscriptions = await this.allSubscriptions(environment);
        this.known = subscriptions;
        const serverKey = this.serverKey();

        if (repair && subscriptions.length > 0) {
            if (environment.permission !== 'granted') {
                // Permission was withdrawn: the browser will not deliver, so stop pretending.
                for (const subscription of subscriptions) {
                    await this.forget(subscription);
                }
                subscriptions = [];
                this.known = [];
            } else {
                const { usable, stale } = classifySubscriptions(subscriptions, serverKey);
                for (const subscription of stale) {
                    // Made with a different server key: it can never receive, so the server must not count it.
                    this.serverHashes.delete(await sha256Hex(subscription.endpoint));
                }
                // A subscription found on any registration is the device's enrollment: sync it, never create another.
                for (const subscription of usable) {
                    const hash = await sha256Hex(subscription.endpoint);
                    if (!this.serverHashes.has(hash) && (await this.register(subscription))) {
                        this.serverHashes.add(hash);
                        notifyPage();
                    }
                }
            }
        }

        let registeredOnServer = false;
        for (const subscription of classifySubscriptions(subscriptions, serverKey).usable) {
            registeredOnServer = registeredOnServer || this.serverHashes.has(await sha256Hex(subscription.endpoint));
        }
        this.paint({ environment, subscribed: subscriptions.length > 0, registeredOnServer });
    }

    /** The server key as bytes; an unreadable key yields no bytes, so nothing is mistaken for matching it. */
    private serverKey(): Uint8Array {
        try {
            return urlBase64ToUint8Array(this.vapidKey);
        } catch {
            return new Uint8Array(0);
        }
    }

    /** Every subscription on this device, whichever registration holds it (see findDeviceSubscriptions). */
    private async allSubscriptions(environment: DeviceEnvironment): Promise<PushSubscription[]> {
        if (!canUsePush(environment)) {
            return [];
        }
        try {
            const root = this.registration === null ? null : await this.registration;

            return await findDeviceSubscriptions(root, navigator.serviceWorker as unknown as RegistrationSource);
        } catch {
            return [];
        }
    }

    private paint(snapshot: DeviceSnapshot): void {
        const view = deriveView(snapshot);
        const status = deriveStatus(snapshot);
        const { elements } = this;

        elements.root.dataset.view = view;
        elements.summary.textContent = MESSAGES[view];
        const set = (name: string, text: string): void => {
            const target = elements.values[name];
            if (target) {
                target.textContent = text;
            }
        };
        set('supported', yesNo(status.supported));
        set('installed', snapshot.environment.ios || status.installed ? yesNo(status.installed) : 'Not needed on this device');
        set('permission', PERMISSION_TEXT[status.permission]);
        set('subscribed', yesNo(status.subscribed));
        set('enabled', yesNo(status.enabled));

        elements.enable.hidden = !(view === 'ready' || view === 'needs-repair');
        elements.enable.textContent = view === 'needs-repair' ? 'Turn alerts on again' : 'Turn on alerts for this device';
        elements.disable.hidden = !(view === 'enabled' || view === 'needs-repair');
        elements.test.hidden = view !== 'enabled';
    }

    private say(text: string): void {
        this.elements.message.textContent = text;
    }

    private busy(on: boolean): void {
        for (const button of [this.elements.enable, this.elements.disable, this.elements.test]) {
            button.disabled = on;
        }
    }

    /** Click handler: permission and subscribe happen here and nowhere else. */
    private async onEnable(): Promise<void> {
        this.say('');
        const environment = readEnvironment(window);
        if (!canUsePush(environment) || this.registration === null || this.vapidKey === '') {
            this.say(MESSAGES.unsupported);

            return;
        }

        this.busy(true);
        try {
            // First awaited call: the permission prompt, straight from the tap.
            const permission = environment.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            if (permission !== 'granted') {
                this.say(permission === 'denied' ? MESSAGES.blocked : 'No problem. Alerts stay off on this device.');

                return;
            }

            // Then subscribe immediately; the registration was started on page load.
            const registration = await this.registration;
            if (registration === null) {
                this.say('The Hub could not start its notification service on this device. Reload the page and try again.');

                return;
            }
            const key = urlBase64ToUint8Array(this.vapidKey);
            // A subscription may already exist on ANY registration (admin or Daily enrolled earlier).
            // The root one is read live; the others come from the last refresh, so no extra async
            // work sits between the permission prompt and subscribe().
            const rootExisting = await registration.pushManager.getSubscription();
            const { usable, stale } = classifySubscriptions(
                [...(rootExisting === null ? [] : [rootExisting]), ...this.known.filter((item) => item.endpoint !== rootExisting?.endpoint)],
                key,
            );
            const created =
                usable.length > 0
                    ? null
                    : await registration.pushManager.subscribe({
                          userVisibleOnly: true,
                          applicationServerKey: key.buffer.slice(key.byteOffset, key.byteOffset + key.byteLength) as ArrayBuffer,
                      });
            const enrolled = created === null ? usable : [created];

            for (const subscription of stale) {
                // A subscription made with another server key can never receive; remove it everywhere.
                await this.forget(subscription);
            }
            // The device is already enrolled when a usable subscription exists: tell the server about
            // it instead of creating another one.
            let saved = true;
            for (const subscription of enrolled) {
                saved = (await this.register(subscription)) && saved;
                if (saved) {
                    this.serverHashes.add(await sha256Hex(subscription.endpoint));
                }
            }
            if (!saved) {
                if (created !== null) {
                    // The Hub refused this device; do not leave a half-enabled subscription behind.
                    await created.unsubscribe().catch(() => false);
                }
                this.say('The Hub could not save this device. Try again, or ask for help if it keeps happening.');

                return;
            }
            this.say('Alerts are on for this device. Use "Send a test" to check.');
            notifyPage();
        } catch (error) {
            this.say(error instanceof Error && error.name === 'NotAllowedError' ? MESSAGES.blocked : 'Alerts could not be turned on here. In-app alerts still work.');
        } finally {
            this.busy(false);
            await this.refresh(false);
        }
    }

    private async onDisable(): Promise<void> {
        this.say('');
        this.busy(true);
        try {
            // Every subscription on the device goes, whichever registration holds it, so "off" means
            // off: a subscription left on the admin or Daily registration would keep delivering.
            const environment = readEnvironment(window);
            for (const subscription of await this.allSubscriptions(environment)) {
                await this.forget(subscription);
            }
            this.say('Alerts are off on this device. In-app alerts still arrive in the bell.');
            notifyPage();
        } catch {
            this.say('Alerts could not be turned off here. Remove this device from the list below instead.');
        } finally {
            this.busy(false);
            await this.refresh(false);
        }
    }

    private async onTest(): Promise<void> {
        this.say('Sending a test…');
        this.busy(true);
        try {
            const subscriptions = await this.allSubscriptions(readEnvironment(window));
            const subscription = classifySubscriptions(subscriptions, this.serverKey()).usable[0] ?? subscriptions[0] ?? null;
            const response = await api('POST', '/api/push/test', subscription === null ? {} : { endpoint: subscription.endpoint });
            // What the push service said, per device; never "delivered" (see ./test-push-result.ts).
            this.say(describeTestResult(response.status, response.ok ? await response.json().catch(() => null) : null));
            if (response.ok) notifyPage();
        } catch {
            this.say('The test could not be sent. Check your connection and try again.');
        } finally {
            this.busy(false);
        }
    }

    private async register(subscription: PushSubscription): Promise<boolean> {
        const p256dh = arrayBufferToBase64Url(subscription.getKey('p256dh'));
        const auth = arrayBufferToBase64Url(subscription.getKey('auth'));
        if (p256dh === '' || auth === '') {
            return false;
        }
        try {
            const response = await api('POST', '/api/push-subscriptions', {
                endpoint: subscription.endpoint,
                keys: { p256dh, auth },
                content_encoding: preferredContentEncoding((window.PushManager as { supportedContentEncodings?: readonly string[] } | undefined)?.supportedContentEncodings),
            });

            return response.ok;
        } catch {
            return false;
        }
    }

    /** Stop this browser's subscription and tell the server. */
    private async forget(subscription: PushSubscription): Promise<void> {
        const hash = await sha256Hex(subscription.endpoint);
        await subscription.unsubscribe().catch(() => false);
        await api('DELETE', '/api/push-subscriptions', { endpoint: subscription.endpoint }).catch(() => undefined);
        this.serverHashes.delete(hash);
    }
}

export function parseHashes(raw: string | undefined): string[] {
    if (!raw) {
        return [];
    }
    try {
        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

export function mountPushEnrollment(root: ParentNode = document): EnrollmentBlock | null {
    const element = root.querySelector<HTMLElement>('[data-push-enrollment]');
    if (element === null) {
        return null;
    }
    const block = new EnrollmentBlock(element);
    block.start();

    return block;
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => mountPushEnrollment());
    } else {
        mountPushEnrollment();
    }
}
