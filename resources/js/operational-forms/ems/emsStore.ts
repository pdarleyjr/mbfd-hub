import Dexie, { type EntityTable } from 'dexie';
import type { EmsOutboxEntry, StoredDraft } from './emsTypes';

/**
 * Device storage for the Medical Inventory Request, separate from the ICS/F-ROC recovery store (draftsDb.ts).
 *
 *  - `drafts`: the unfinished request. Quantities, answers, the unit choice and the submission id only.
 *  - `outbox`: a request the member sent that could not reach the server. It keeps the exact payload so it can be
 *    retried with the same client_submission_id (the server dedupes), and is removed once the server confirms it.
 *
 * Rows are keyed by the signed-in employee's numeric id, so a shared station computer never shows one member's
 * draft to another. Nothing here holds a name or any other personnel data.
 */
type DraftRow = StoredDraft & { owner: string };
type OutboxRow = EmsOutboxEntry & { owner: string };

const db = new Dexie('mbfd-ems-requests') as Dexie & {
    drafts: EntityTable<DraftRow, 'owner'>;
    outbox: EntityTable<OutboxRow, 'clientSubmissionId'>;
};

db.version(1).stores({ drafts: '&owner', outbox: '&clientSubmissionId, owner, createdAt' });

/** Storage can be blocked (private windows, cleared site data). The form still works; it just cannot keep a draft. */
async function safely<T>(label: string, work: () => Promise<T>, fallback: T): Promise<T> {
    try {
        return await work();
    } catch (error) {
        console.warn(`Medical request storage unavailable (${label}).`, error);
        return fallback;
    }
}

export const emsDrafts = {
    get: (owner: string) => safely('read draft', async () => {
        const row = await db.drafts.get(owner);
        if (!row) return null;
        const { owner: _owner, ...draft } = row;
        void _owner;
        return draft as StoredDraft;
    }, null as StoredDraft | null),
    put: (owner: string, draft: StoredDraft) => safely('save draft', () => db.drafts.put({ ...draft, owner }).then(() => true), false),
    remove: (owner: string) => safely('clear draft', () => db.drafts.delete(owner).then(() => true), false),
};

export const emsOutbox = {
    list: (owner: string) => safely('read queue', async () => {
        const rows = await db.outbox.where('owner').equals(owner).sortBy('createdAt');
        return rows.map(({ owner: _owner, ...entry }) => { void _owner; return entry as EmsOutboxEntry; });
    }, [] as EmsOutboxEntry[]),
    put: (owner: string, entry: EmsOutboxEntry) => safely('queue request', () => db.outbox.put({ ...entry, owner }).then(() => true), false),
    remove: (clientSubmissionId: string) => safely('clear queue entry', () => db.outbox.delete(clientSubmissionId).then(() => true), false),
};
