import Dexie, { type EntityTable } from 'dexie';
import type { AccidentLocalDraft, MemoLocalDraft, ReportType } from './personnelTypes';

/**
 * Device storage for the accident reports and the memorandum, in its own database (the ICS/F-ROC recovery store in
 * draftsDb.ts and the medical request store are untouched).
 *
 *  - `accidentDrafts`: entries the server has not confirmed yet, so a refresh, a closed tab or a dropped connection
 *    never loses them. Removed as soon as the server saves them.
 *  - `memoDrafts`: the memorandum being written. A memo to someone outside the Hub is never stored on the server, so
 *    this copy is the only one; it is cleared after download, email or sending, or when the member discards it.
 *
 * Rows are keyed by the signed-in employee's numeric id, so a shared station computer never shows one member's
 * entries to another.
 */
const db = new Dexie('mbfd-personnel-forms') as Dexie & {
    accidentDrafts: EntityTable<AccidentLocalDraft, 'key'>;
    memoDrafts: EntityTable<MemoLocalDraft, 'owner'>;
};

db.version(1).stores({ accidentDrafts: '&key, owner, savedAt', memoDrafts: '&owner' });

/** Storage can be blocked (private windows, cleared site data). The forms still work; they just cannot keep a copy. */
async function safely<T>(label: string, work: () => Promise<T>, fallback: T): Promise<T> {
    try {
        return await work();
    } catch (error) {
        console.warn(`Personnel forms storage unavailable (${label}).`, error);
        return fallback;
    }
}

export function accidentDraftKey(owner: string, reportId: string | null, type: ReportType): string {
    return reportId ? `${owner}:${reportId}` : `${owner}:new:${type}`;
}

export const accidentDrafts = {
    get: (key: string) => safely('read report copy', async () => (await db.accidentDrafts.get(key)) ?? null, null as AccidentLocalDraft | null),
    put: (draft: AccidentLocalDraft) => safely('save report copy', () => db.accidentDrafts.put(draft).then(() => true), false),
    remove: (key: string) => safely('clear report copy', () => db.accidentDrafts.delete(key).then(() => true), false),
};

export const memoDrafts = {
    get: (owner: string) => safely('read memorandum', async () => (await db.memoDrafts.get(owner)) ?? null, null as MemoLocalDraft | null),
    put: (draft: MemoLocalDraft) => safely('save memorandum', () => db.memoDrafts.put(draft).then(() => true), false),
    remove: (owner: string) => safely('clear memorandum', () => db.memoDrafts.delete(owner).then(() => true), false),
};
