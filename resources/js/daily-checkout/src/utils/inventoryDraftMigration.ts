import type { ChecklistData, ChecklistItem, Compartment, InspectionData } from '../types';

export interface InventoryDraftMerge {
  compartments: Compartment[];
  /** Items the member must look at again: new items and previously answered items whose name or quantity changed. */
  needsLook: number;
}

export interface DraftActor {
  userId: number;
  securityVersion: number;
}

const nonEmpty = (value: unknown): value is string => typeof value === 'string' && value.trim() !== '';

/**
 * A draft written against another checklist version can still be carried over when only the inventory overlay
 * changed: same baseline template, same member and security context, schema v1 (Fire Boat sessions are never
 * migrated).
 */
export function canMigrateInventoryDraft(saved: InspectionData, current: ChecklistData, actor: DraftActor): boolean {
  const savedBase = saved.checklistSnapshot?.checklist_base_version;

  return saved.checklist_version !== current.checklist_version
    && saved.actorUserId === actor.userId
    && saved.actorSecurityVersion === actor.securityVersion
    && current.schema_version !== 2
    && saved.checklistSnapshot?.schema_version !== 2
    && nonEmpty(savedBase)
    && savedBase === current.checklist_base_version;
}

const itemChanged = (current: ChecklistItem, saved: ChecklistItem): boolean => current.name !== saved.name || current.expectedQuantity !== saved.expectedQuantity;

/**
 * Carry saved answers into the new checklist by item id, wherever the item sat in the saved draft. An item whose
 * name or expected quantity changed keeps its notes, photo and typed value but loses its observation, and an item
 * the draft never had starts unanswered. Pure: neither argument is modified.
 */
export function mergeDraftAnswers(current: Compartment[], saved: Compartment[]): InventoryDraftMerge {
  const savedById = new Map<string, ChecklistItem>();
  for (const compartment of saved) {
    for (const item of compartment.items) if (!savedById.has(item.id)) savedById.set(item.id, item);
  }
  let needsLook = 0;
  const compartments = current.map(compartment => ({
    ...compartment,
    items: compartment.items.map((item): ChecklistItem => {
      const before = savedById.get(item.id);
      if (!before) {
        needsLook += 1;
        return item;
      }
      const kept = {
        ...(before.notes !== undefined ? { notes: before.notes } : {}),
        ...(before.photo !== undefined ? { photo: before.photo } : {}),
        ...(before.value !== undefined ? { value: before.value } : {}),
      };
      if (itemChanged(item, before)) {
        if (before.observed === true) needsLook += 1;
        return { ...item, ...kept };
      }
      return {
        ...item,
        ...kept,
        status: before.status,
        ...(before.observed !== undefined ? { observed: before.observed } : {}),
        ...(before.significant !== undefined ? { significant: before.significant } : {}),
      };
    }),
  }));

  return { compartments, needsLook };
}

export function inventoryMigrationMessage(needsLook: number): string {
  const base = 'This apparatus\'s inventory was updated while you were working. Your answers were kept';

  return needsLook > 0 ? `${base}; ${needsLook} ${needsLook === 1 ? 'item needs' : 'items need'} a look.` : `${base}.`;
}
