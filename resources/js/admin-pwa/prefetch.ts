/**
 * Admin prefetch — Dexie/IndexedDB cache of frequently-loaded lookups.
 *
 * Cached entities (read-only, TTL 6h):
 *   - Stations (small, near-static, used in every dropdown)
 *   - Apparatus (medium, hot table)
 *   - Personnel (medium, hot lookup)
 *
 * This is purely a perceived-latency optimization. Filament still fetches
 * fresh data on every table render — the cache only powers client-side
 * filtering / typeahead within React islands or future admin widgets.
 *
 * Mobile safety: only runs in desktop standalone-friendly mode.
 */

import Dexie, { type EntityTable } from 'dexie';

interface CachedRecord {
    id: string;
    payload: unknown;
    fetchedAt: number;
}

type AdminDb = Dexie & {
    stations: EntityTable<CachedRecord, 'id'>;
    apparatus: EntityTable<CachedRecord, 'id'>;
    personnel: EntityTable<CachedRecord, 'id'>;
};

const TTL_MS = 6 * 60 * 60_000;

let db: AdminDb | null = null;

function getDb(): AdminDb {
    if (db) return db;
    const instance = new Dexie('mbfd-admin-cache') as AdminDb;
    instance.version(1).stores({
        stations: 'id, fetchedAt',
        apparatus: 'id, fetchedAt',
        personnel: 'id, fetchedAt',
    });
    db = instance;
    return instance;
}

async function fetchJsonSafely<T>(url: string): Promise<T | null> {
    try {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return (await res.json()) as T;
    } catch {
        return null;
    }
}

interface LookupRecord {
    readonly id: string | number;
    readonly [key: string]: unknown;
}

async function refreshTable(
    table: EntityTable<CachedRecord, 'id'>,
    endpoint: string,
): Promise<void> {
    const data = await fetchJsonSafely<{ data?: LookupRecord[] } | LookupRecord[]>(endpoint);
    if (!data) return;
    const rows = Array.isArray(data) ? data : data.data;
    if (!rows || rows.length === 0) return;
    const now = Date.now();
    const records: CachedRecord[] = rows.map((row) => ({
        id: String(row.id),
        payload: row,
        fetchedAt: now,
    }));
    await table.clear();
    await table.bulkPut(records);
}

/**
 * Public entry point. Runs in the background after the admin shell loads.
 * Each fetch is independent; one failure does not cancel the others.
 */
export async function prefetchAdminLookups(): Promise<void> {
    // Only prefetch on desktop — phones don't need extra IDB chatter
    if (!window.matchMedia('(min-width: 1280px) and (pointer: fine)').matches) return;

    const database = getDb();

    // If recently refreshed, skip
    const recent = await database.stations.where('fetchedAt').above(Date.now() - TTL_MS).count();
    if (recent > 0) return;

    await Promise.allSettled([
        refreshTable(database.stations, '/api/admin/lookups/stations'),
        refreshTable(database.apparatus, '/api/admin/lookups/apparatus'),
        refreshTable(database.personnel, '/api/admin/lookups/personnel'),
    ]);
}

export async function getCachedStations(): Promise<CachedRecord[]> {
    return getDb().stations.toArray();
}

export async function getCachedApparatus(): Promise<CachedRecord[]> {
    return getDb().apparatus.toArray();
}

export async function getCachedPersonnel(): Promise<CachedRecord[]> {
    return getDb().personnel.toArray();
}
