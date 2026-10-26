/**
 * The one install coordinator for every MBFD Hub page.
 *
 * Browsers fire `beforeinstallprompt` once per page and the event can be prompted only once, so
 * a page must have exactly one owner of it. The shared head (<x-hub.head />) captures the event
 * inline, before any deferred bundle loads, onto `window.__mbfdDeferredInstall`; this module
 * adopts it (or captures it itself on pages without that head) and is the only code that calls
 * `prompt()`. The controller is a page-wide singleton kept on `window`, so the Hub shell, Admin
 * and Daily Checkout bundles all share one instance when they load on the same page.
 *
 * It never registers, unregisters or updates a service worker, never touches the manifest and
 * never asks for notification permission: installing and notification enrollment stay separate.
 *
 * Detection is pure (`detectEnvironment`, `resolveMethod`) so it is unit tested in
 * tests/Node/pwa-install-controller.test.mjs without a browser.
 */

export type InstallPlatform = 'ios' | 'ipados' | 'android' | 'windows' | 'macos' | 'linux' | 'chromeos' | 'unknown';
export type InstallBrowser = 'safari' | 'chrome' | 'edge' | 'firefox' | 'samsung' | 'opera' | 'other';
export type InstallMethod =
    | 'native'
    | 'ios-safari'
    | 'ios-other'
    | 'mac-safari'
    | 'chromium-menu'
    | 'samsung-menu'
    | 'firefox-android'
    | 'unsupported';

export interface InstallState {
    /** Running as the installed app right now (standalone, window-controls-overlay or minimal-ui). */
    installed: boolean;
    /** `appinstalled` fired, or the member accepted the native prompt, on this page. */
    installedThisSession: boolean;
    /** The browser's own install dialog can be opened right now. */
    canPrompt: boolean;
    platform: InstallPlatform;
    browser: InstallBrowser;
    method: InstallMethod;
}

export type PromptOutcome = 'accepted' | 'dismissed' | 'unavailable';

export interface InstallController {
    getState(): InstallState;
    subscribe(listener: (state: InstallState) => void): () => void;
    promptInstall(): Promise<PromptOutcome>;
    isPromotionSnoozed(): boolean;
    snoozePromotion(): void;
    /**
     * Whether this page may show the install card. One page per browser session shows it; after
     * three sessions without an answer it snoozes like "Not now". Repeated calls on the page agree.
     */
    claimPromotion(): boolean;
}

export interface DeferredInstallPrompt extends Event {
    prompt(): Promise<void> | void;
    readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform?: string }>;
}

export interface InstallEnvironment {
    platform: InstallPlatform;
    browser: InstallBrowser;
    /** Facebook, Instagram, Gmail, LinkedIn and other embedded views cannot install a web app. */
    inAppBrowser: boolean;
    /** Major version of Safari when the browser is Safari, else null. */
    safariVersion: number | null;
}

type InstallWindow = Window & {
    __mbfdDeferredInstall?: DeferredInstallPrompt | null;
    __mbfdAppInstalled?: boolean;
    __mbfdInstallControllerV1?: InstallController;
    MBFDInstall?: InstallController;
};

/** "Not now" keeps automatic promotion away for 30 days; the menu link and /install stay available. */
export const SNOOZE_STORAGE_KEY = 'mbfd-install-promotion-snoozed-until';
export const SNOOZE_DAYS = 30;
/** Sessions in which the card was shown and neither accepted nor dismissed. */
export const IMPRESSIONS_STORAGE_KEY = 'mbfd-install-promotion-impressions';
export const MAX_UNANSWERED_IMPRESSIONS = 3;
/** The page (controller instance) that showed the card in this browser session. */
export const SESSION_STORAGE_KEY = 'mbfd-install-promotion-page';
const DAY_MS = 24 * 60 * 60 * 1000;

/** Display modes that mean "this page is the installed app". `fullscreen` is excluded: F11 matches it. */
export const INSTALLED_DISPLAY_MODES = ['standalone', 'window-controls-overlay', 'minimal-ui'] as const;

const IN_APP_BROWSER = /FBAN|FBAV|FB_IAB|Instagram|LinkedInApp|Line\/|GSA\/|Snapchat|Twitter|MicroMessenger|TikTok|BytedanceWebview|musical_ly|Pinterest|Teams\/|; wv\)/i;

/**
 * Methods worth promoting with a card. On Chromium, no `beforeinstallprompt` usually means the app
 * is already installed in this profile (or not yet eligible), so a menu-only path is never pushed;
 * it stays one tap away on /install. Safari never fires the event, so its paths are promoted.
 */
export const PROMOTED_METHODS: readonly InstallMethod[] = ['native', 'ios-safari', 'ios-other', 'mac-safari'];

/** Bumped when the controller's shape changes, so a stale cached bundle never shares an incompatible one. */
const CONTROLLER_KEY = '__mbfdInstallControllerV1';

export function detectPlatform(userAgent: string, maxTouchPoints = 0): InstallPlatform {
    if (/iPhone|iPod/.test(userAgent)) return 'ios';
    if (/iPad/.test(userAgent)) return 'ipados';
    // iPadOS Safari reports a Mac user agent by default; a Mac has no multi-touch screen.
    if (/Macintosh/.test(userAgent) && maxTouchPoints > 1) return 'ipados';
    if (/Android/.test(userAgent)) return 'android';
    if (/CrOS/.test(userAgent)) return 'chromeos';
    if (/Windows/.test(userAgent)) return 'windows';
    if (/Macintosh|Mac OS X/.test(userAgent)) return 'macos';
    if (/Linux|X11/.test(userAgent)) return 'linux';
    return 'unknown';
}

export function detectBrowser(userAgent: string): InstallBrowser {
    // iOS browsers all use WebKit but name themselves with their own tokens.
    if (/CriOS\//.test(userAgent)) return 'chrome';
    if (/EdgiOS\//.test(userAgent)) return 'edge';
    if (/FxiOS\//.test(userAgent)) return 'firefox';
    if (/OPiOS\/|OPT\//.test(userAgent)) return 'opera';
    if (/SamsungBrowser\//.test(userAgent)) return 'samsung';
    if (/Edg(A)?\//.test(userAgent)) return 'edge';
    if (/OPR\/|Opera/.test(userAgent)) return 'opera';
    if (/Firefox\//.test(userAgent)) return 'firefox';
    if (/Chrome\/|Chromium\//.test(userAgent)) return 'chrome';
    if (/Safari\//.test(userAgent) && /Version\//.test(userAgent)) return 'safari';
    return 'other';
}

export function detectEnvironment(userAgent: string, maxTouchPoints = 0): InstallEnvironment {
    const browser = detectBrowser(userAgent);
    const version = browser === 'safari' ? /Version\/(\d+)/.exec(userAgent) : null;
    const platform = detectPlatform(userAgent, maxTouchPoints);
    // An iOS app's embedded web view (Outlook, Teams, TikTok...) carries no Safari/ token.
    const iosWebView = (platform === 'ios' || platform === 'ipados') && /AppleWebKit/.test(userAgent) && !/Safari\//.test(userAgent);
    return {
        platform,
        browser,
        inAppBrowser: iosWebView || IN_APP_BROWSER.test(userAgent),
        safariVersion: version ? Number(version[1]) : null,
    };
}

/**
 * How this member installs on this device. `native` only when the browser handed us a prompt;
 * everything else is a manual path we can describe accurately, or `unsupported`.
 */
export function resolveMethod(environment: InstallEnvironment, canPrompt: boolean): InstallMethod {
    if (canPrompt) return 'native';
    const { platform, browser, inAppBrowser, safariVersion } = environment;
    if (inAppBrowser) return 'unsupported';
    if (platform === 'ios' || platform === 'ipados') {
        if (browser === 'safari') return 'ios-safari';
        // Since iOS 16.4 other iOS browsers offer Add to Home Screen from their share sheet.
        return browser === 'other' ? 'unsupported' : 'ios-other';
    }
    if (platform === 'android') {
        if (browser === 'samsung') return 'samsung-menu';
        if (browser === 'firefox') return 'firefox-android';
        if (browser === 'chrome' || browser === 'edge' || browser === 'opera') return 'chromium-menu';
        return 'unsupported';
    }
    if (platform === 'macos' && browser === 'safari') {
        // Add to Dock arrived in Safari 17 (macOS Sonoma).
        return safariVersion !== null && safariVersion >= 17 ? 'mac-safari' : 'unsupported';
    }
    if (platform === 'macos' || platform === 'windows' || platform === 'linux' || platform === 'chromeos') {
        return browser === 'chrome' || browser === 'edge' ? 'chromium-menu' : 'unsupported';
    }
    return 'unsupported';
}

export function isInstalledDisplay(win: Pick<Window, 'matchMedia' | 'navigator'>): boolean {
    if ((win.navigator as Navigator & { standalone?: boolean }).standalone === true) return true;
    return INSTALLED_DISPLAY_MODES.some((mode) => win.matchMedia(`(display-mode: ${mode})`).matches);
}

/** Automatic promotion (cards) only; the menu link and /install are always available. */
export function isPromotionEligible(state: InstallState, controller: Pick<InstallController, 'isPromotionSnoozed'>): boolean {
    return !state.installed && !state.installedThisSession && PROMOTED_METHODS.includes(state.method) && !controller.isPromotionSnoozed();
}

/**
 * Whether a card on this page should be visible now: eligible, online (the guide needs the network
 * and the card must never compete with offline sync), and this page holds the session's slot.
 * The slot is claimed only once everything else allows it, so ineligible pages never spend it.
 */
export function shouldShowPromotion(state: InstallState, controller: Pick<InstallController, 'isPromotionSnoozed' | 'claimPromotion'>, online: boolean): boolean {
    return online && isPromotionEligible(state, controller) && controller.claimPromotion();
}

function readSnoozedUntil(storage: Storage | null): number {
    try {
        return Number(storage?.getItem(SNOOZE_STORAGE_KEY) ?? 0) || 0;
    } catch {
        return 0;
    }
}

function safeStorage(win: Window, kind: 'localStorage' | 'sessionStorage'): Storage | null {
    try {
        return win[kind];
    } catch {
        return null;
    }
}

export function createInstallController(win: InstallWindow, now: () => number = Date.now): InstallController {
    const environment = detectEnvironment(win.navigator.userAgent, win.navigator.maxTouchPoints ?? 0);
    const storage = safeStorage(win, 'localStorage');
    const session = safeStorage(win, 'sessionStorage');
    const pageToken = `${now()}-${Math.random().toString(36).slice(2)}`;
    let promotionClaim: boolean | null = null;
    const listeners = new Set<(state: InstallState) => void>();
    let deferred: DeferredInstallPrompt | null = win.__mbfdDeferredInstall ?? null;
    let installedThisSession = win.__mbfdAppInstalled === true;
    let inFlight: Promise<PromptOutcome> | null = null;

    const getState = (): InstallState => {
        const installed = isInstalledDisplay(win);
        const canPrompt = deferred !== null && !installed;
        return {
            installed,
            installedThisSession,
            canPrompt,
            platform: environment.platform,
            browser: environment.browser,
            method: resolveMethod(environment, canPrompt),
        };
    };

    const notify = (): void => {
        const state = getState();
        listeners.forEach((listener) => {
            try {
                listener(state);
            } catch (error) {
                console.error('[install] listener failed', error);
            }
        });
    };

    win.addEventListener('beforeinstallprompt', (event) => {
        // Keep the browser's own mini-infobar away: one install surface per page.
        event.preventDefault();
        deferred = event as DeferredInstallPrompt;
        win.__mbfdDeferredInstall = deferred;
        notify();
    });

    win.addEventListener('appinstalled', () => {
        installedThisSession = true;
        deferred = null;
        win.__mbfdDeferredInstall = null;
        notify();
    });

    INSTALLED_DISPLAY_MODES.forEach((mode) => {
        win.matchMedia(`(display-mode: ${mode})`).addEventListener?.('change', notify);
    });

    const promptInstall = (): Promise<PromptOutcome> => {
        if (inFlight) return inFlight;
        const event = deferred;
        if (!event) return Promise.resolve('unavailable');
        // A deferred prompt works once. Drop it before prompting so nothing can reuse it.
        deferred = null;
        win.__mbfdDeferredInstall = null;
        inFlight = (async (): Promise<PromptOutcome> => {
            try {
                await event.prompt();
                const choice = await event.userChoice;
                if (choice.outcome === 'accepted') installedThisSession = true;
                return choice.outcome;
            } catch (error) {
                console.warn('[install] the browser refused the install prompt', error);
                return 'unavailable';
            } finally {
                inFlight = null;
                notify();
            }
        })();
        notify();
        return inFlight;
    };

    const snoozePromotion = (): void => {
        promotionClaim = false;
        try {
            storage?.setItem(SNOOZE_STORAGE_KEY, String(now() + SNOOZE_DAYS * DAY_MS));
            storage?.removeItem(IMPRESSIONS_STORAGE_KEY);
        } catch {
            // Private mode or full storage: the card simply returns next visit.
        }
        notify();
    };

    const claimPromotion = (): boolean => {
        if (promotionClaim !== null) return promotionClaim;
        try {
            const owner = session?.getItem(SESSION_STORAGE_KEY);
            if (owner && owner !== pageToken) return (promotionClaim = false);
            session?.setItem(SESSION_STORAGE_KEY, pageToken);
            const shown = (Number(storage?.getItem(IMPRESSIONS_STORAGE_KEY) ?? 0) || 0) + 1;
            if (shown >= MAX_UNANSWERED_IMPRESSIONS) {
                // Shown now for the last time; later sessions stay quiet for the snooze period.
                storage?.setItem(SNOOZE_STORAGE_KEY, String(now() + SNOOZE_DAYS * DAY_MS));
                storage?.removeItem(IMPRESSIONS_STORAGE_KEY);
            } else {
                storage?.setItem(IMPRESSIONS_STORAGE_KEY, String(shown));
            }
        } catch {
            // Without storage the card shows on this page and nowhere is it counted.
        }
        return (promotionClaim = true);
    };

    return {
        getState,
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        promptInstall,
        // A claim made on this page keeps the card here even after the snooze it may have started.
        isPromotionSnoozed: () => promotionClaim !== true && readSnoozedUntil(storage) > now(),
        snoozePromotion,
        claimPromotion,
    };
}

/** The page-wide controller, shared by every bundle loaded on the page. */
export function getInstallController(): InstallController {
    const win = window as InstallWindow;
    if (!win[CONTROLLER_KEY]) {
        win[CONTROLLER_KEY] = createInstallController(win);
        win.MBFDInstall = win[CONTROLLER_KEY];
    }
    return win[CONTROLLER_KEY];
}
