/**
 * Admin PWA bootstrap.
 *
 * What it does:
 *   1. On admin pages, registers /admin/service-worker.js with scope /admin/
 *      (the one and only registration of that URL) and hands the scope to the
 *      shared update registrar, which owns update prompts on every device.
 *   2. Mounts the shared install experience (resources/js/pwa/install-ui.ts): the
 *      page-wide install controller owns `beforeinstallprompt`, and the Admin
 *      dashboard shows the same install card as the Hub home page.
 *   3. Detects standalone mode and adds a body class for CSS hooks.
 *   4. Kicks off Dexie prefetch in the background.
 */

import { prefetchAdminLookups } from './prefetch';
import { startPushSync } from '../pwa/push-sync';
import { startUpdateRegistrar } from '../pwa/update-registrar';
import { getInstallController } from '../pwa/install-controller';
import { mountInstallCards } from '../pwa/install-ui';

const DESKTOP_MEDIA = window.matchMedia('(min-width: 1280px) and (pointer: fine)');

/** Mark body so CSS can target standalone mode */
function markBodyClasses(): void {
    const install = getInstallController();
    const markInstalled = (): void => {
        const state = install.getState();
        document.body.classList.toggle('mbfd-pwa', state.installed || state.installedThisSession);
    };
    markInstalled();
    install.subscribe(markInstalled);
    if (DESKTOP_MEDIA.matches) document.body.classList.add('mbfd-desktop');
}

/** Register the admin service worker. */
async function registerServiceWorker(): Promise<void> {
    if (!('serviceWorker' in navigator)) return;

    try {
        const reg = await navigator.serviceWorker.register('/admin/service-worker.js', {
            scope: '/admin/',
            updateViaCache: 'none',
        });

        // Check for updates every 30 minutes — admins won't reload often
        setInterval(() => reg.update().catch(() => {}), 30 * 60_000);
    } catch (err) {
        // Silent failure — the app still works without a SW
    }
}

function bootstrap(): void {
    markBodyClasses();
    registerServiceWorker();
    // Release checks, the update banner (all platforms, never while busy) and chunk-load
    // recovery. No scriptUrl: the registration above stays the only one for this worker.
    startUpdateRegistrar({ scope: '/admin/' });
    startPushSync();
    mountInstallCards();
    // Login and password-recovery pages share this bundle but must not call
    // protected lookup APIs before the web guard has authenticated a user.
    if (document.querySelector('meta[name="mbfd-admin-authenticated"]')) {
        prefetchAdminLookups().catch(() => {});
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
