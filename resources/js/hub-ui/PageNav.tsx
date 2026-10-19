import type { ReactNode } from 'react';

interface PageNavProps {
  /** Long Back label ("Back to stations"); the phone layout always shows "Back". Omit when there is no Back target. */
  backLabel?: string;
  onBack?: () => void;
  /** Hub Home is a full navigation to the Hub root. */
  homeHref?: string;
  className?: string;
  children?: ReactNode;
}

const ARROW = 'M10 19l-7-7m0 0l7-7m-7 7h18';
const HOME = 'M3 10.5 12 3l9 7.5M5 9v12h5v-7h4v7h5V9';

function NavIcon({ path }: { path: string }) {
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={path} /></svg>;
}

/**
 * Shared Back + Hub Home group for React pages. Same labels, 44px targets and
 * tokens as the Filament partial (resources/views/filament/partials/page-nav.blade.php);
 * styling lives in resources/css/mbfd-theme.css (.hub-page-nav, .hub-page-nav__link).
 * Router-agnostic: the host decides what Back does.
 */
export function PageNav({ backLabel = 'Back', onBack, homeHref = '/', className = '', children }: PageNavProps) {
  return <div className={`hub-page-nav ${className}`.trim()} role="navigation" aria-label="Page navigation" data-hub-page-nav>
    {onBack && <button type="button" className="hub-page-nav__link" data-hub-back aria-label={backLabel} onClick={onBack}>
      <NavIcon path={ARROW} />
      <span className="hub-back-short" aria-hidden="true">Back</span>
      <span className="hub-back-label" aria-hidden="true">{backLabel}</span>
    </button>}
    <a href={homeHref} className="hub-page-nav__link" data-hub-home aria-label="Hub Home">
      <NavIcon path={HOME} />
      <span>Hub Home</span>
    </a>
    {children}
  </div>;
}
