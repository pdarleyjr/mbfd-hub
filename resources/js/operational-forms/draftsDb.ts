import Dexie, { type EntityTable } from 'dexie';

export interface RecoveryDraft {
    recordId: string;
    revision: number;
    data: Record<string, any>;
    savedAt: string;
}

const db = new Dexie('mbfd-operational-forms') as Dexie & {
    recoveryDrafts: EntityTable<RecoveryDraft, 'recordId'>;
};

db.version(1).stores({ recoveryDrafts: '&recordId, revision, savedAt' });

export const recoveryDrafts = {
    put: (draft: RecoveryDraft) => db.recoveryDrafts.put(draft),
    get: (recordId: string) => db.recoveryDrafts.get(recordId),
    remove: (recordId: string) => db.recoveryDrafts.delete(recordId),
};
