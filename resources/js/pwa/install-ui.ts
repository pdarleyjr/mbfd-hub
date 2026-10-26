/**
 * DOM wiring for the MBFD Hub install experience:
 *   - the full /install page   (mountInstallPage)
 *   - the dismissible Home card (mountInstallCards)
 *
 * All install knowledge lives in install-controller.ts. This file only reflects the controller's state
 * into markup produced by install.blade.php and components/hub/install-card.blade.php, so the server
 * markup stays fully readable without JavaScript.
 */
import {
    getInstallController,
    shouldShowPromotion,
    type InstallController,
    type InstallState,
} from './install-controller.ts';

export type InstallGuideKey =
    | 'ios-safari'
    | 'ios-other'
    | 'android-chrome'
    | 'android-samsung'
    | 'android-firefox'
    | 'desktop-chromium'
    | 'mac-safari'
    | 'mac-chromium'
    | 'other';

/** Maps the controller's detected method to the guide shown first. Pure, so it is unit-testable. */
export function guideFor(state: InstallState): InstallGuideKey {
    switch (state.method) {
        case 'native':
        case 'chromium-menu':
            if (state.platform === 'android') return state.browser === 'samsung' ? 'android-samsung' : 'android-chrome';
            if (state.platform === 'macos') return 'mac-chromium';
            return 'desktop-chromium';
        case 'ios-safari':
            return 'ios-safari';
        case 'ios-other':
            return 'ios-other';
        case 'mac-safari':
            return 'mac-safari';
        case 'samsung-menu':
            return 'android-samsung';
        case 'firefox-android':
            return 'android-firefox';
        case 'unsupported':
        default:
            return 'other';
    }
}

/** Which "After installing" tip applies to the detected platform, if any. */
export function afterTipFor(state: InstallState): 'windows' | 'macos' | 'linux' | null {
    if (state.platform === 'windows') return 'windows';
    if (state.platform === 'macos') return 'macos';
    if (state.platform === 'linux' || state.platform === 'chromeos') return 'linux';
    return null;
}

type Unsubscribe = () => void;

function all<T extends Element>(root: ParentNode, selector: string): T[] {
    return Array.from(root.querySelectorAll<T>(selector));
}

function setHidden(element: Element | null, hidden: boolean): void {
    if (element instanceof HTMLElement) element.hidden = hidden;
}

/** Moves focus without scrolling; elements that are not natively focusable get tabindex=-1. */
function focusElement(element: HTMLElement | null): void {
    if (!element) return;
    if (!element.hasAttribute('tabindex') && !/^(a|button|input|select|textarea|summary)$/i.test(element.tagName)) {
        element.setAttribute('tabindex', '-1');
    }
    element.focus({ preventScroll: false });
}

// ---------------------------------------------------------------------------------------------
// Home / Daily Checkout inline card
// ---------------------------------------------------------------------------------------------

function mountCard(card: HTMLElement, controller: InstallController): Unsubscribe {
    const install = card.querySelector<HTMLButtonElement>('[data-install-action="install"]');
    const how = card.querySelector<HTMLElement>('[data-install-action="how"]');
    const dismiss = card.querySelector<HTMLButtonElement>('[data-install-action="dismiss"]');
    let dismissed = false;

    const render = (state: InstallState): void => {
        const show = !dismissed && shouldShowPromotion(state, controller, navigator.onLine);
        card.hidden = !show;
        setHidden(install, !state.canPrompt);
        setHidden(how, state.canPrompt);
    };

    const onInstall = async (): Promise<void> => {
        if (!install) return;
        install.disabled = true;
        try {
            const result = await controller.promptInstall();
            if (result === 'dismissed') {
                // The browser's own sheet was cancelled; treat it like "Not now" so we do not nag.
                controller.snoozePromotion();
                dismissed = true;
            }
        } finally {
            install.disabled = false;
            render(controller.getState());
        }
    };

    const onDismiss = (): void => {
        controller.snoozePromotion();
        dismissed = true;
        const hadFocus = card.contains(document.activeElement);
        card.hidden = true;
        if (hadFocus) {
            const main = document.getElementById('main-content') ?? document.querySelector<HTMLElement>('main');
            focusElement(main);
        }
    };

    install?.addEventListener('click', () => void onInstall());
    dismiss?.addEventListener('click', onDismiss);

    // Never offered offline: the guide needs the network and sync work comes first.
    const refresh = (): void => render(controller.getState());
    window.addEventListener('online', refresh);
    window.addEventListener('offline', refresh);
    refresh();
    const unsubscribe = controller.subscribe(render);
    return () => {
        unsubscribe();
        window.removeEventListener('online', refresh);
        window.removeEventListener('offline', refresh);
    };
}

export function mountInstallCards(root: ParentNode = document): Unsubscribe {
    const cards = all<HTMLElement>(root, '[data-mbfd-install-card]').filter((card) => card.dataset.installMounted !== '1');
    if (cards.length === 0) return () => {};
    const controller = getInstallController();
    const stops = cards.map((card) => {
        card.dataset.installMounted = '1';
        return mountCard(card, controller);
    });
    return () => stops.forEach((stop) => stop());
}

// ---------------------------------------------------------------------------------------------
// /install page
// ---------------------------------------------------------------------------------------------

function statusMessage(state: InstallState): string {
    if (state.installed) return 'MBFD Hub is installed on this device.';
    if (state.installedThisSession) return 'MBFD Hub was added. Open it from its new icon.';
    if (state.canPrompt) return 'Ready to install on this device.';
    if (state.method === 'unsupported') return 'Installing may not be available in this browser. Safari or Chrome works best.';
    return 'Your browser installs from its menu. Follow the steps below.';
}

export function mountInstallPage(root: ParentNode = document): Unsubscribe {
    const page = root.querySelector<HTMLElement>('[data-install-page]');
    if (!page || page.dataset.installMounted === '1') return () => {};
    page.dataset.installMounted = '1';

    const controller = getInstallController();
    const primary = page.querySelector<HTMLButtonElement>('[data-install-primary]');
    const status = page.querySelector<HTMLElement>('[data-install-status]');
    const setup = page.querySelector<HTMLElement>('[data-install-setup]');
    const success = page.querySelector<HTMLElement>('[data-install-success]');
    const successHeading = page.querySelector<HTMLElement>('[data-install-success-heading]');
    const switcher = page.querySelector<HTMLDetailsElement>('[data-install-switcher]');
    const tabs = all<HTMLButtonElement>(page, '[role="tab"][data-guide-tab]');
    const panels = all<HTMLElement>(page, '[data-install-guide]');
    const tips = all<HTMLElement>(page, '[data-after-tip]');

    page.classList.add('is-enhanced');
    for (const panel of panels) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', `tab-${panel.dataset.installGuide}`);
        panel.tabIndex = 0;
    }

    const showGuides = page.querySelector<HTMLButtonElement>('[data-install-show-guides]');
    let selected: InstallGuideKey | null = null;
    let userPicked = false;
    // An installed member can still open the guides to set up another device.
    let guidesRequested = false;
    let lastInstalled = false;
    let moveFocusToSuccess = false;

    const select = (key: InstallGuideKey, focusTab = false): void => {
        selected = key;
        for (const tab of tabs) {
            const on = tab.dataset.guideTab === key;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (on && focusTab) tab.focus();
        }
        for (const panel of panels) panel.hidden = panel.dataset.installGuide !== key;
    };

    const render = (state: InstallState): void => {
        const done = state.installed || state.installedThisSession;
        if (status) status.textContent = statusMessage(state);
        page.dataset.installState = done ? 'installed' : state.canPrompt ? 'ready' : 'manual';

        setHidden(primary, done || !state.canPrompt);
        setHidden(setup, done && !guidesRequested);
        setHidden(success, !done);
        setHidden(showGuides, guidesRequested);

        if (!userPicked) select(guideFor(state));
        else if (selected) select(selected);

        const tip = afterTipFor(state);
        for (const element of tips) element.hidden = element.dataset.afterTip !== tip;
        // With no platform-specific tip (phones), the whole "After installing" block stays hidden.
        setHidden(page.querySelector('[data-install-after]'), tip === null);

        if (done && !lastInstalled && moveFocusToSuccess) focusElement(successHeading);
        lastInstalled = done;
    };

    primary?.addEventListener('click', async () => {
        if (!primary) return;
        primary.disabled = true;
        moveFocusToSuccess = true;
        try {
            const result = await controller.promptInstall();
            if (status) {
                if (result === 'dismissed') status.textContent = 'Install was cancelled. You can still install from your browser menu using the steps below.';
                if (result === 'unavailable') {
                    status.textContent = 'Install is not available right now. Use the steps below.';
                    setHidden(primary, true);
                }
            }
        } finally {
            primary.disabled = false;
            moveFocusToSuccess = false;
        }
    });

    // In-app browsers cannot install; the way out is opening the link in Safari or Chrome.
    const copyLink = page.querySelector<HTMLButtonElement>('[data-install-copy-link]');
    if (copyLink && navigator.clipboard?.writeText) {
        copyLink.hidden = false;
        copyLink.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(new URL('/install', location.origin).href);
                if (status) status.textContent = 'Link copied. Paste it into Safari or Chrome.';
            } catch {
                if (status) status.textContent = `Copy did not work. Open ${location.host}/install in Safari or Chrome.`;
            }
        });
    }

    showGuides?.addEventListener('click', () => {
        guidesRequested = true;
        if (switcher) switcher.open = true;
        render(controller.getState());
        focusElement(switcher?.querySelector<HTMLElement>('summary') ?? null);
    });

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            userPicked = true;
            select(tab.dataset.guideTab as InstallGuideKey);
        });
        tab.addEventListener('keydown', (event) => {
            const keys: Record<string, number> = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
            let next = -1;
            if (event.key in keys) next = (index + keys[event.key] + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            if (next < 0) return;
            event.preventDefault();
            userPicked = true;
            select(tabs[next].dataset.guideTab as InstallGuideKey, true);
        });
    });

    // Keep the switcher closed by default and bring the chosen guide into view when it changes.
    if (switcher) switcher.open = false;

    render(controller.getState());
    return controller.subscribe(render);
}

function autoMount(): void {
    mountInstallCards();
    mountInstallPage();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoMount, { once: true });
    else autoMount();
}
