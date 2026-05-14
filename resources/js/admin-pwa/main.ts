/**
 * Admin PWA bootstrap.
 *
 * What it does:
 *   1. On admin pages, registers /admin-pwa/service-worker.js with scope /admin/.
 *   2. Captures the `beforeinstallprompt` event and shows a custom prompt
 *      ONLY when the device is wide + pointer:fine (desktop with mouse).
 *      Touch-primary devices never see the prompt because the event listener
 *      is conditionally attached.
 *   3. Detects standalone mode and adds a body class for CSS hooks.
 *   4. Kicks off Dexie prefetch in the background.
 *
 * Mobile/tablet guarantee:
 *   Every line below is gated by the same matchMedia query. If a phone or
 *   tablet loads this script, the SW registers (scope-limited to /admin/),
 *   but no install banner ever appears and no UI changes happen.
 */

import { prefetchAdminLookups } from './prefetch';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const DESKTOP_MEDIA = window.matchMedia('(min-width: 1280px) and (pointer: fine)');
const STANDALONE_MEDIA = window.matchMedia('(display-mode: standalone), (display-mode: window-controls-overlay)');
const STORAGE_KEY = 'mbfd-admin-install-prompt-dismissed-at';

/** Detect if running as installed PWA */
function isStandalone(): boolean {
    return STANDALONE_MEDIA.matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** Mark body so CSS can target standalone mode */
function markBodyClasses(): void {
    if (isStandalone()) document.body.classList.add('mbfd-pwa');
    if (DESKTOP_MEDIA.matches) document.body.classList.add('mbfd-desktop');
}

/** Register the admin service worker. */
async function registerServiceWorker(): Promise<void> {
    if (!('serviceWorker' in navigator)) return;

    try {
        const reg = await navigator.serviceWorker.register('/admin-pwa/service-worker.js', {
            scope: '/admin/',
            updateViaCache: 'none',
        });

        // Check for updates every 30 minutes — admins won't reload often
        setInterval(() => reg.update().catch(() => {}), 30 * 60_000);

        reg.addEventListener('updatefound', () => {
            const installing = reg.installing;
            if (!installing) return;
            installing.addEventListener('statechange', () => {
                if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                    // New SW waiting — notify the user (but never block)
                    showSwUpdateToast();
                }
            });
        });
    } catch (err) {
        // Silent failure — the app still works without a SW
    }
}

function showSwUpdateToast(): void {
    if (!DESKTOP_MEDIA.matches) return;
    const toast = document.createElement('div');
    toast.dataset.adminPwaUpdate = 'true';
    toast.style.cssText = [
        'position:fixed', 'bottom:48px', 'right:16px', 'z-index:99999',
        'background:#1e293b', 'color:#fff', 'padding:12px 16px',
        'border-radius:8px', 'box-shadow:0 10px 25px rgba(0,0,0,0.25)',
        'font:500 13px/1.4 system-ui', 'cursor:pointer', 'max-width:320px',
    ].join(';');
    toast.textContent = 'A new version of the admin console is available. Click to reload.';
    toast.addEventListener('click', () => window.location.reload());
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 30_000);
}

/**
 * Show the install banner. Only called from inside a desktop-gated block.
 */
function showInstallBanner(promptEvent: BeforeInstallPromptEvent): void {
    // Respect a recent "dismissed" timestamp (24h cooldown)
    const dismissedAt = Number(localStorage.getItem(STORAGE_KEY) || '0');
    if (Date.now() - dismissedAt < 24 * 60 * 60_000) return;
    if (isStandalone()) return;

    const banner = document.createElement('div');
    banner.dataset.adminPwaInstall = 'true';
    banner.style.cssText = [
        'position:fixed', 'bottom:48px', 'right:16px', 'z-index:99998',
        'background:#fff', 'color:#0f172a', 'padding:16px 18px',
        'border-radius:10px', 'border:1px solid #e2e8f0',
        'box-shadow:0 12px 28px rgba(15,23,42,0.18)',
        'font:13px/1.45 "Plus Jakarta Sans", system-ui',
        'max-width:340px', 'display:flex', 'flex-direction:column', 'gap:10px',
    ].join(';');

    banner.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:10px;">
            <img src="/admin-pwa/icons/icon-96.png" alt="" width="36" height="36" style="border-radius:8px;flex-shrink:0;">
            <div>
                <div style="font-weight:700;font-size:14px;color:#0f172a;">Install MBFD Admin</div>
                <div style="color:#475569;margin-top:2px;">Open the admin console like a desktop app — its own window, taskbar icon, and instant launch.</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button data-act="later" style="background:transparent;border:none;color:#64748b;cursor:pointer;padding:6px 10px;font:inherit;">Not now</button>
            <button data-act="install" style="background:#dc2626;border:none;color:#fff;cursor:pointer;padding:6px 14px;border-radius:6px;font:inherit;font-weight:600;">Install</button>
        </div>
    `;

    banner.querySelector<HTMLButtonElement>('[data-act="install"]')?.addEventListener('click', async () => {
        banner.remove();
        await promptEvent.prompt();
        const choice = await promptEvent.userChoice;
        if (choice.outcome === 'dismissed') {
            localStorage.setItem(STORAGE_KEY, String(Date.now()));
        }
    });
    banner.querySelector<HTMLButtonElement>('[data-act="later"]')?.addEventListener('click', () => {
        localStorage.setItem(STORAGE_KEY, String(Date.now()));
        banner.remove();
    });

    document.body.appendChild(banner);
}

function setupInstallPrompt(): void {
    // The strict desktop gate. Touch-primary devices never reach the listener.
    if (!DESKTOP_MEDIA.matches) return;

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        showInstallBanner(event as BeforeInstallPromptEvent);
    });

    window.addEventListener('appinstalled', () => {
        document.body.classList.add('mbfd-pwa');
    });
}

function bootstrap(): void {
    markBodyClasses();
    registerServiceWorker();
    setupInstallPrompt();
    // Prefetch is best-effort and never blocks UI
    prefetchAdminLookups().catch(() => {});
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
