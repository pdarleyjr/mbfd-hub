/**
 * Decision rules for recovering from a lazy-chunk load failure (`vite:preloadError`).
 *
 * A failed dynamic import has two very different causes:
 *  - the page is older than the server (a deploy replaced the hashed files): a reload fixes it;
 *  - the device has no connection right now: a reload would replace a degraded-but-working page
 *    with the browser's own "no internet" error page and throw away in-page state.
 *
 * So an automatic reload needs POSITIVE evidence that the network is up (the release probe
 * answered) AND a reason the reload will help (the release changed, or the chunk is really gone).
 * Anything else shows the banner and never reloads.
 *
 * Deliberately has no imports so it can be unit tested in plain Node.
 */

export type ChunkRecoveryAction = 'reload' | 'banner' | 'offline';

export interface ChunkRecoveryFacts {
    /** What /app-release answered, or null when the probe did not succeed (offline, a blip, a 5xx). */
    latestRelease: string | null;
    /** The release this page was rendered by, when known. */
    baselineRelease: string | null;
    /** The failed chunk was fetched again and the server answered 404/410. */
    chunkMissing: boolean;
    /** Not busy, no unsaved edits, not already reloaded for this release, and reload guarding works. */
    mayReload: boolean;
}

export function decideChunkRecovery(facts: ChunkRecoveryFacts): ChunkRecoveryAction {
    if (facts.latestRelease === null) {
        return 'offline';
    }

    const releaseChanged = facts.baselineRelease !== null && facts.latestRelease !== facts.baselineRelease;

    return (releaseChanged || facts.chunkMissing) && facts.mayReload ? 'reload' : 'banner';
}

/** Whether the chunk was fetched again and the server said it no longer exists. */
export function isMissingStatus(status: number): boolean {
    return status === 404 || status === 410;
}

const URL_IN_MESSAGE = /(https?:\/\/[^\s"'()<>]+|\/[^\s"'()<>]+\.(?:m?js|css)(?:\?[^\s"'()<>]*)?)/;

/**
 * The same-origin URL of the file that failed, read from the error Vite attaches to the event
 * (`Failed to fetch dynamically imported module: <url>`, `error loading dynamically imported module: <url>`,
 * `Unable to preload CSS for <path>`). Null when the browser does not say (Safari) or it is cross-origin.
 */
export function chunkUrlFromPreloadError(event: unknown, origin: string): string | null {
    const payload = (event as { payload?: unknown } | null)?.payload;
    const message = typeof payload === 'string' ? payload : (payload as { message?: unknown } | null)?.message;
    if (typeof message !== 'string') {
        return null;
    }

    const found = URL_IN_MESSAGE.exec(message)?.[1];
    if (found === undefined) {
        return null;
    }

    try {
        const url = new URL(found, origin);

        return url.origin === origin && (url.protocol === 'https:' || url.protocol === 'http:') ? url.href : null;
    } catch {
        return null;
    }
}
