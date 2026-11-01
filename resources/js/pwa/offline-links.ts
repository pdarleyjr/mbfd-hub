/**
 * Offline navigation guard for Blade and Filament pages.
 *
 * Outside Daily there is no offline page, so following a link without a connection ends on the browser's own
 * error page, and an installed app has no Back button to return from it. While the browser reports it is offline
 * a same-origin link tap is held on the current page and the existing offline strip says so instead.
 * `navigator.onLine === false` is reliable and `true` is not, so a link is never blocked while it says online.
 * Daily keeps its own offline shell (React's HubShell applies the same rule to its menus), so /daily/ is left alone.
 */

export const OFFLINE_LINK_MESSAGE = "You're offline. This page needs a connection.";

const STRIP_SELECTOR = '[data-hub-offline-strip]';
const ORIGINAL_TEXT = 'hubOriginalText';

export interface OfflineLinkEvent {
  defaultPrevented: boolean;
  button: number;
  metaKey: boolean;
  ctrlKey: boolean;
  shiftKey: boolean;
  altKey: boolean;
}

export interface OfflineLinkElement {
  getAttribute(name: string): string | null;
  hasAttribute(name: string): boolean;
  href: string;
}

/** Whether a tap on this link should be held because the page it opens cannot load. Pure, so it is unit tested. */
export function isBlockedOfflineLink(
  event: OfflineLinkEvent,
  link: OfflineLinkElement | null,
  origin: string,
  offline: boolean,
): boolean {
  if (!offline || !link || event.defaultPrevented) return false;
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
  if (link.hasAttribute('download') || link.hasAttribute('data-hub-viewer')) return false;
  const target = (link.getAttribute('target') ?? '').toLowerCase();
  if (target !== '' && target !== '_self') return false;
  let url: URL;
  try {
    url = new URL(link.href);
  } catch {
    return false;
  }
  if (url.origin !== origin || (url.protocol !== 'http:' && url.protocol !== 'https:')) return false;
  if (url.pathname === '/daily' || url.pathname.startsWith('/daily/')) return false;
  return true;
}

/** True when the link only moves within the page the member is already on, which needs no network. */
export function isSamePageAnchor(href: string, current: { pathname: string; search: string }): boolean {
  const url = new URL(href);
  return url.hash !== '' && url.pathname === current.pathname && url.search === current.search;
}

function announce(strip: HTMLElement): void {
  strip.hidden = false;
  strip.dataset[ORIGINAL_TEXT] ??= strip.textContent ?? '';
  // Clearing first makes a repeated tap announce again to screen readers.
  strip.textContent = '';
  window.setTimeout(() => { strip.textContent = OFFLINE_LINK_MESSAGE; }, 60);
}

function restore(): void {
  document.querySelectorAll<HTMLElement>(STRIP_SELECTOR).forEach(strip => {
    const original = strip.dataset[ORIGINAL_TEXT];
    if (original !== undefined) {
      strip.textContent = original;
      delete strip.dataset[ORIGINAL_TEXT];
    }
  });
}

export function startOfflineLinks(): void {
  document.addEventListener('click', event => {
    const element = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null;
    if (!isBlockedOfflineLink(event, element, window.location.origin, navigator.onLine === false)) return;
    // A bare "#" resolves to an empty hash, so the written attribute is checked as well.
    if (element && (element.getAttribute('href')?.startsWith('#') || isSamePageAnchor(element.href, window.location))) return;
    // Without the strip there is nowhere to say why, so leave the browser's own behaviour in place.
    const strip = document.querySelector<HTMLElement>(STRIP_SELECTOR);
    if (!strip) return;
    event.preventDefault();
    announce(strip);
  });
  window.addEventListener('online', restore);
}
