import { useEffect, useId, useRef, useState } from 'react';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, KeyboardEvent, ReactNode } from 'react';

export interface HubNavigationItem {
  key: string;
  label: string;
  href: string;
}

export interface HubNavigation {
  applications: HubNavigationItem[];
  memberNavigation: HubNavigationItem[];
  moreNavigation?: HubNavigationItem[];
  account: { name: string; href: string } | null;
}

declare global {
  interface Window {
    __MBFD_HUB_NAVIGATION__?: HubNavigation;
  }
}

// The offline shell retains Daily's existing member destinations.
// Entitled applications and account destinations come from the server bootstrap.
const dailyNavigation: HubNavigation = {
  applications: [
    { key: 'home', label: 'Home', href: '/' },
    { key: 'daily', label: 'Daily Checkout', href: '/daily/stations' },
  ],
  moreNavigation: [{ key: 'support', label: 'Support', href: '/support/issues' }],
  memberNavigation: [
    { key: 'home', label: 'Home', href: '/' },
    { key: 'checkout', label: 'Checkout', href: '/daily/stations' },
    { key: 'employee', label: 'Employee', href: '/employee' },
    { key: 'requests', label: 'Requests', href: '/employee/my-requests' },
  ],
  account: null,
};

export function readHubNavigation(): HubNavigation {
  return window.__MBFD_HUB_NAVIGATION__ ?? dailyNavigation;
}

export type HubLinkRenderer = (props: AnchorHTMLAttributes<HTMLAnchorElement>) => ReactNode;

function HubIcon({ name }: { name: string }) {
  if (name === 'checkout') return <span className="hub-checkout-icon" aria-hidden="true" />;
  const paths: Record<string, string> = {
    home: 'M3 10.5 12 3l9 7.5M5 9v12h5v-7h4v7h5V9',
    employee: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    requests: 'M4 4h16v13H8l-4 4V4m4 4h8m-8 4h5',
    account: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    more: 'M5 11v2m7-2v2m7-2v2',
    apps: 'M3 3h7v7H3V3m11 0h7v7h-7V3M3 14h7v7H3v-7m11 0h7v7h-7v-7',
  };
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name] ?? paths.apps} /></svg>;
}

// Sign out must be a real form post: the server answers with a redirect, possibly to the identity provider's
// own sign-out page, which a background request cannot follow. The page's csrf-token meta carries the token.
function readCsrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function SignOutItem({ token, onOffline }: { token: string; onOffline: () => void }) {
  return <div role="none">
    <form method="post" action="/logout" onSubmit={(event) => {
      if (!navigator.onLine) {
        event.preventDefault();
        onOffline();
      }
    }}>
      <input type="hidden" name="_token" value={token} />
      <button type="submit" role="menuitem" tabIndex={-1} className="hub-shell-menuitem hub-shell-menuitem--danger">Sign out</button>
    </form>
  </div>;
}

function NavigationMenu({ label, items, icon = 'apps', className = '', renderLink, signOut }: {
  label: string;
  items: HubNavigationItem[];
  icon?: string;
  className?: string;
  renderLink?: HubLinkRenderer;
  signOut?: { token: string; onOffline: () => void };
}) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const container = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const initialFocus = useRef<'first' | 'last'>('first');
  // The phone More menu is a bottom sheet: it gets a backdrop and keeps focus inside while open.
  const sheet = className.includes('hub-member-more');

  useEffect(() => {
    if (!open) return;
    const links = menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]');
    links?.[initialFocus.current === 'last' ? links.length - 1 : 0]?.focus();
    function closeOutside(event: PointerEvent) {
      if (!container.current?.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener('pointerdown', closeOutside);
    return () => document.removeEventListener('pointerdown', closeOutside);
  }, [open]);

  function onMenuKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      event.preventDefault();
      setOpen(false);
      trigger.current?.focus();
      return;
    }
    const links = Array.from(menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
    const current = links.indexOf(document.activeElement as HTMLElement);
    if (sheet && event.key === 'Tab' && links.length > 0) {
      const wrapFrom = event.shiftKey ? 0 : links.length - 1;
      if (current === wrapFrom) {
        event.preventDefault();
        links[event.shiftKey ? links.length - 1 : 0]?.focus();
      }
      return;
    }
    let next: number;
    if (event.key === 'ArrowDown') next = (current + 1) % links.length;
    else if (event.key === 'ArrowUp') next = (current - 1 + links.length) % links.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = links.length - 1;
    else return;
    event.preventDefault();
    links[next]?.focus();
  }

  if (items.length === 0) return null;
  return <div ref={container} className={`hub-shell-menu ${className}`} onBlur={(event) => {
    if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget)) setOpen(false);
  }}>
    <button ref={trigger} id={`${id}-trigger`} type="button" className="hub-shell-control" aria-haspopup="menu" aria-expanded={open} aria-controls={open ? `${id}-menu` : undefined}
      onClick={() => { initialFocus.current = 'first'; setOpen(!open); }} onKeyDown={(event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          initialFocus.current = event.key === 'ArrowUp' ? 'last' : 'first';
          setOpen(true);
        }
      }}>
      <HubIcon name={icon} /><span>{label}</span>
    </button>
    {open && sheet && <div className="hub-sheet-backdrop" aria-hidden="true" onClick={() => { setOpen(false); trigger.current?.focus(); }} />}
    {open && <div ref={menu} id={`${id}-menu`} className="hub-shell-popover" role="menu" aria-labelledby={`${id}-trigger`} onKeyDown={onMenuKeyDown}>
      {items.map((item) => {
        const external = /^https?:\/\//.test(item.href);
        const props: AnchorHTMLAttributes<HTMLAnchorElement> = {
          href: item.href, role: 'menuitem', tabIndex: -1,
          target: external ? '_blank' : undefined, rel: external ? 'noopener' : undefined,
          'aria-label': external ? `${item.label} (opens in new tab)` : undefined,
          className: 'hub-shell-menuitem', children: <>{item.label}{external && <span className="hub-shell-external-hint" aria-hidden="true">↗</span>}</>,
          onClick: () => { window.setTimeout(() => setOpen(false), 0); },
        };
        return <div role="none" key={item.key}>{renderLink ? renderLink(props) : <a {...props} />}</div>;
      })}
      {signOut && <SignOutItem {...signOut} />}
    </div>}
  </div>;
}

export function HubShell({ module, navigation, connection, currentSection, renderLink, children, className = '' }: {
  module: string;
  navigation: HubNavigation;
  connection?: ReactNode;
  currentSection: string;
  renderLink?: HubLinkRenderer;
  children: ReactNode;
  className?: string;
}) {
  const [connectionRequired, setConnectionRequired] = useState(false);
  useEffect(() => {
    const clearNotice = () => setConnectionRequired(false);
    window.addEventListener('online', clearNotice);
    return () => window.removeEventListener('online', clearNotice);
  }, []);
  const renderNavigationLink: HubLinkRenderer = ({ href, onClick, ...props }) => {
    const guardedProps: AnchorHTMLAttributes<HTMLAnchorElement> = {
      ...props, href, onClick: (event) => {
        if (!navigator.onLine && !href?.startsWith('/daily/')) {
          event.preventDefault();
          setConnectionRequired(true);
          return;
        }
        setConnectionRequired(false);
        onClick?.(event);
      },
    };
    return renderLink ? renderLink(guardedProps) : <a {...guardedProps} />;
  };
  const accountItems = navigation.account ? [
    { key: 'account', label: 'My account', href: navigation.account.href },
    { key: 'install', label: 'Install MBFD Hub', href: '/install' },
  ] : [];
  // Sign out needs the page's CSRF token; without one the item is left out rather than offered and refused.
  const csrfToken = navigation.account ? readCsrfToken() : '';
  const signOut = csrfToken ? { token: csrfToken, onOffline: () => setConnectionRequired(true) } : undefined;
  // The bottom bar already carries Home and Checkout, so the phone More sheet does not repeat them.
  const moreApplications = navigation.applications.filter((item) => item.key !== 'home' && item.key !== 'daily');
  return <div className={`hub-shell hub-shell--member ${className}`}>
    <a href="#main-content" className="hub-shell-skip">Skip to main content</a>
    <header className="hub-shell-header daily-home-nav" aria-label={`${module} navigation`}>
      {renderNavigationLink({ href: '/', className: 'hub-shell-brand', 'aria-label': 'MBFD Hub home', children: <>
        <img src="/images/mbfd-official-seal-256.png" alt="" width="32" height="32" /><span className="hub-shell-brand__word"><span className="hub-shell-brand__prefix" aria-hidden="true" /><span>Hub</span></span>
      </> })}
      <div className="hub-shell-header-actions">
        <NavigationMenu label={module} className="hub-app-switcher" items={navigation.applications} renderLink={renderNavigationLink} />
        {navigation.account && <NavigationMenu label="Account" icon="account" className="hub-shell-account" signOut={signOut}
          items={[...accountItems, { key: 'issue-reports', label: 'My issue reports', href: '/support/issues' }]} renderLink={renderNavigationLink} />}
      </div>
    </header>
    {connection}
    {connectionRequired && <div className="hub-sync-notice" role="status" data-hub-navigation-notice>Requires connection</div>}
    {children}
    <nav className="hub-member-navigation" aria-label="Hub member navigation">
      {navigation.memberNavigation.map((item) => {
        const props: AnchorHTMLAttributes<HTMLAnchorElement> = {
          href: item.href, className: 'hub-member-navigation-item',
          'aria-current': currentSection === item.key ? 'page' : undefined,
          children: <><HubIcon name={item.key} /><span>{item.label}</span></>,
        };
        return <div key={item.key}>{renderNavigationLink(props)}</div>;
      })}
      <NavigationMenu label="More" icon="more" className="hub-member-more" items={[...(navigation.moreNavigation ?? []), ...moreApplications, ...accountItems]} renderLink={renderNavigationLink} />
    </nav>
  </div>;
}

export function HubPageHeader({ title, eyebrow, description, actions }: { title: string; eyebrow?: string; description?: ReactNode; actions?: ReactNode }) {
  return <div className="hub-page-header">
    <div>{eyebrow && <p className="hub-page-eyebrow">{eyebrow}</p>}<h1>{title}</h1>{description && <p className="hub-page-description">{description}</p>}</div>
    {actions && <div className="hub-page-actions">{actions}</div>}
  </div>;
}

export function HubBack({ children, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement>) {
  return <button {...props} type="button" data-hub-back className={`hub-back ${className}`}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
    {children}
  </button>;
}
