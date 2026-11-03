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
