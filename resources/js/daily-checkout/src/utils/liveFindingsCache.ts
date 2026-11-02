import { db } from '../lib/db';
import type { FindingsSnapshot } from '../types/issueHistory.ts';
import { LIVE_FINDINGS_CACHE_PREFIX, liveFindingsCacheKey } from './liveFindings.ts';
import { parseFindingsResponse } from './issueHistoryParse.ts';

/**
 * The last good findings answer, one per apparatus and member, in IndexedDB `cachedData`. It only fills the screen
 * while the first request is out or when offline, and it is read back through the same parser as a live answer.
 * Nothing here ever throws: a missing or unreadable cache is the same as no cache.
 */

export async function readCachedFindings(apparatusId: number, userId: number): Promise<FindingsSnapshot | null> {
  try {
    const row = await db.cachedData.get(liveFindingsCacheKey(apparatusId, userId));

    return row ? parseFindingsResponse(row.data) : null;
  } catch {
    return null;
  }
}

export async function writeCachedFindings(apparatusId: number, userId: number, snapshot: FindingsSnapshot): Promise<void> {
  try {
    await db.cachedData.put({ key: liveFindingsCacheKey(apparatusId, userId), data: snapshot, updatedAt: new Date() });
  } catch {
    // The screen still has the answer; only the offline copy is missing.
  }
}

/**
 * The member signed out or another member signed in: their findings do not stay readable on this device. Only these
 * rows go. Drafts, queued checkouts and the offline identity belong to their owners and are left alone.
 */
export async function purgeCachedFindings(): Promise<void> {
  try {
    await db.cachedData.where('key').startsWith(LIVE_FINDINGS_CACHE_PREFIX).delete();
  } catch {
    // Nothing more to do; the next sign-in check tries again.
  }
}
