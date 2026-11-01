/**
 * Keeps an installed window on its own host. The Hub answers on www and on the apex, and a member's install belongs
 * to one of them for good. A link written with the other host (stored notifications, an update's call to action,
 * a pasted address) would leave the app's scope and open Safari or Chrome, so it is followed on this host instead.
 * Both hosts serve the same pages to the same session; see App\Support\Host\HubHosts.
 *
 * Only installed windows are touched. A browser tab follows the link as written, and a failure here leaves the link
 * working exactly as before.
 */

export const isInstalledWindow = (view: Window = window): boolean => {
    const navigatorWithStandalone = view.navigator as Navigator & { standalone?: boolean };
    if (navigatorWithStandalone.standalone === true) return true;

    return ['standalone', 'window-controls-overlay', 'minimal-ui'].some((mode) => view.matchMedia?.(`(display-mode: ${mode})`).matches);
};

export const siblingHostOf = (hostname: string): string | null => {
    if (!hostname.includes('.') || /^[\d.]+$/.test(hostname) || hostname.includes(':')) return null;

    return hostname.startsWith('www.') ? hostname.slice(4) : `www.${hostname}`;
};

/** The same-host address for a link on the sibling host, or null when the link should be left alone. */
export const ownHostHref = (href: string, current: { protocol: string; hostname: string; port: string; origin: string }): string | null => {
    let url: URL;
    try {
        url = new URL(href, current.origin);
    } catch {
        return null;
    }
    if (url.hostname !== siblingHostOf(current.hostname) || url.protocol !== current.protocol || url.port !== current.port) return null;

    return `${current.origin}${url.pathname}${url.search}${url.hash}`;
};

export const startSiblingLinks = (view: Window = window): void => {
    const marker = view as Window & { __mbfdSiblingLinksV1?: boolean };
    if (marker.__mbfdSiblingLinksV1 || !isInstalledWindow(view)) return;
    marker.__mbfdSiblingLinksV1 = true;

    view.document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
        // data-hub-keep-host: this link means the other host on purpose (saved work that only exists there).
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-hub-keep-host')) return;
        const destination = ownHostHref(link.href, view.location);
        if (destination === null) return;
        event.preventDefault();
        view.location.assign(destination);
    });
};
