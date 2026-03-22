import Dexie, { type EntityTable } from 'dexie';
import type { EquipmentPlacement } from '../types';

// ===== Offline Draft Schema =====

export interface DraftRecord {
  id?: number;
  apparatusId: string;
  placements: EquipmentPlacement[];
  updatedAt: string;
}

// ===== Dexie Database =====

class LayoutDraftDb extends Dexie {
  drafts!: EntityTable<DraftRecord, 'id'>;

  constructor() {
    super('ApparatusLayoutDrafts');
    this.version(1).stores({
      drafts: '++id, apparatusId, updatedAt',
    });
  }
}

export const db = new LayoutDraftDb();

// ===== Auto-save helpers =====

export async function saveDraft(apparatusId: string, placements: EquipmentPlacement[]): Promise<void> {
  const existing = await db.drafts.where('apparatusId').equals(apparatusId).first();
  const record: Omit<DraftRecord, 'id'> = {
    apparatusId,
    placements,
    updatedAt: new Date().toISOString(),
  };

  if (existing?.id) {
    await db.drafts.update(existing.id, record);
  } else {
    await db.drafts.add(record as DraftRecord);
  }
}

export async function loadDraft(apparatusId: string): Promise<DraftRecord | undefined> {
  return db.drafts.where('apparatusId').equals(apparatusId).first();
}

export async function deleteDraft(apparatusId: string): Promise<void> {
  await db.drafts.where('apparatusId').equals(apparatusId).delete();
}
