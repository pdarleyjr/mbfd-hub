/**
 * Unit Status: the pure rules behind the in-place refresh. No DOM, so they run under node --test.
 */
export const POLL_INTERVAL_MS = 30_000;
export const MAX_BACKOFF_MS = 300_000;

/** The wait before the next check: the normal interval, doubling after each failure up to five minutes. */
export function nextDelay(failures) {
    if (!Number.isFinite(failures) || failures <= 0) {
        return POLL_INTERVAL_MS;
    }
    return Math.min(POLL_INTERVAL_MS * 2 ** failures, MAX_BACKOFF_MS);
}

/** These answers mean this session may no longer read the page: stop asking. */
export function isAuthStop(status) {
    return status === 401 || status === 403 || status === 419;
}

/** If-None-Match wants the quoted form; a bare token from data-etag is quoted here, a header value is kept. */
export function toEntityTag(value) {
    if (typeof value !== 'string' || value === '') {
        return null;
    }
    return value.startsWith('"') || value.startsWith('W/') ? value : `"${value}"`;
}

/**
 * What a member should hear after a refresh. Only a unit whose status or assignment really changed counts;
 * a new "Updated" time alone says nothing. Snapshots are [{code, status, summary}].
 */
export function describeChanges(previous, next, limit = 3) {
    if (!Array.isArray(previous) || previous.length === 0) {
        return '';
    }
    const before = new Map(previous.map((unit) => [unit.code, unit]));
    const changed = next.filter((unit) => {
        const old = before.get(unit.code);
        return old && (old.status !== unit.status || old.summary !== unit.summary);
    });
    if (changed.length === 0) {
        return '';
    }
    const named = changed.slice(0, limit).map((unit) => `${unit.code}: ${unit.status}`);
    const rest = changed.length - named.length;
    return `Unit status updated. ${named.join('. ')}${rest > 0 ? `. And ${rest} more.` : '.'}`;
}

/** Which of the previously expanded units still exist after a refresh. */
export function survivingIds(expandedIds, availableIds) {
    const available = new Set(availableIds);
    return expandedIds.filter((id) => available.has(id));
}

export function failureNote(updatedLabel) {
    return updatedLabel
        ? `Couldn't refresh — showing data from ${updatedLabel}`
        : "Couldn't refresh — showing the data already on this page";
}

export const SIGN_IN_NOTE = 'Sign in again to keep this page updated';

/** The server calls the feed fresh for 150 seconds; a page not confirmed for longer must not show green. */
export const STALE_AFTER_MS = 150_000;
export const STALE_CHECK_MS = 15_000;

/**
 * Whether the board on screen is too old to trust. A 304 confirms the board as of that moment, so the
 * freshest of the server's generated time and our last confirmation counts.
 */
export function isStale(generatedAtIso, confirmedAtMs, nowMs) {
    const generated = Date.parse(generatedAtIso ?? '');
    const confirmed = Number.isFinite(confirmedAtMs) ? confirmedAtMs : Number.NEGATIVE_INFINITY;
    const latest = Math.max(Number.isFinite(generated) ? generated : Number.NEGATIVE_INFINITY, confirmed);
    if (latest === Number.NEGATIVE_INFINITY) {
        return true;
    }
    return nowMs - latest > STALE_AFTER_MS;
}

export function staleNote(updatedLabel) {
    return updatedLabel
        ? `Not updated since ${updatedLabel} — availability not confirmed`
        : 'Not updated recently — availability not confirmed';
}

/**
 * What a board response means. A redirect (to a login page), 401, 403 or 419 means the session or route is gone.
 * Returns 'auth' | 'not_modified' | 'ok' | 'error'.
 */
export function classifyResponse({ status, type = 'basic', redirected = false, ok = false }) {
    if (type === 'opaqueredirect' || redirected || isAuthStop(status)) {
        return 'auth';
    }
    if (status === 304) {
        return 'not_modified';
    }
    return ok ? 'ok' : 'error';
}

/** A 200 that is not the board (a login page, an error page) is the same as being signed out. */
export function bodyHasBoard(html) {
    return typeof html === 'string' && html.includes('data-us-board');
}

/** Re-fill the open profile only when its markup really changed. */
export function profileChanged(currentMarkup, nextMarkup) {
    return currentMarkup !== nextMarkup;
}

/** The element that carries a unit's code, status and summary: the row button, not its list item. */
export const ROW_SELECTOR = 'button[data-us-open]';

/** Reads the announcement snapshot from row elements (anything with a dataset). */
export function snapshotRows(rows) {
    return [...rows].map((row) => ({
        code: row.dataset.unitCode,
        status: row.dataset.status,
        summary: row.dataset.summary,
    }));
}

/** A page left open across a deploy gets new markup with old styles; only a known, different build counts. */
export function buildMismatch(pageBuild, nextBuild) {
    return Boolean(pageBuild) && Boolean(nextBuild) && pageBuild !== nextBuild;
}

export const RELOAD_NOTE = 'The page was updated \u2014 reload to see the new layout';
