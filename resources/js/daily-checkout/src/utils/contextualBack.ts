const DAILY_PATH = /^\/(?:stations|forms-hub|vehicle-inspections|apparatuses|apparatus|success)(?:\/|$)/;
const HUB_PATH = /^\/(?:$|(?:admin|employee|training|workgroups|daily|updates|account|security-standards)(?:\/|$)|support\/issues(?:\/|$))/;

export function safeContextualPath(candidate: string | null, origin: string): string | null {
  if (!candidate || /[\x00-\x20\x7f\\]/.test(candidate) || candidate.startsWith('//')) return null;
  try {
    if (/[\x00-\x1f\x7f\\]/.test(decodeURIComponent(candidate))) return null;
    // Validate before URL normalizes dot segments or encoded separators.
    const rawPath = candidate.replace(/^https?:\/\/[^/]+/i, '').split(/[?#]/)[0] || '/';
    if (!rawPath.startsWith('/') || rawPath.includes('%') || /(?:^|\/)\.{1,2}(?:\/|$)/.test(rawPath)) return null;
    const url = new URL(candidate, origin);
    if (url.origin !== origin || url.username || url.password) return null;
    if (DAILY_PATH.test(url.pathname)) url.pathname = `/daily${url.pathname}`;
    if (!HUB_PATH.test(url.pathname) || /\/(?:login|logout|auth|reset-password|forgot-password)(?:\/|$)/.test(url.pathname)) return null;
    return url.pathname + url.search + url.hash;
  } catch {
    return null;
  }
}

export function contextualBackPath(search: string, fallback: string, origin: string, currentPath: string): string {
  for (const candidate of [new URLSearchParams(search).get('return_to'), fallback, '/daily/stations', '/']) {
    const safe = safeContextualPath(candidate, origin);
    if (safe && new URL(safe, origin).pathname !== currentPath) return safe;
  }
  return '/';
}

export function contextualBackLabel(path: string): string {
  const pathname = path.split(/[?#]/)[0];
  if (pathname === '/') return 'Back to Hub home';
  if (/^\/daily\/stations\/\d+\/rooms\/\d+$/.test(pathname)) return 'Back to room';
  if (/^\/daily\/stations\/\d+$/.test(pathname)) return 'Back to station';
  if (pathname === '/daily/stations') return 'Back to stations';
  if (pathname === '/daily/forms-hub') return 'Back to Forms Hub';
  if (pathname.startsWith('/admin')) return 'Back to Administration';
  if (pathname.startsWith('/employee')) return 'Back to Employee portal';
  if (pathname.startsWith('/training')) return 'Back to Training';
  if (pathname.startsWith('/workgroups')) return 'Back to Workgroups';
  if (pathname.startsWith('/support/issues')) return 'Back to reports';
  if (pathname.startsWith('/updates')) return 'Back to updates';
  if (pathname.startsWith('/account')) return 'Back to account';
  return 'Back to previous page';
}
