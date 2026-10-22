import type { Compartment, ReviewFlag, ReviewFlagDraft, ReviewFlagItemRef } from '../types';

export const REVIEW_FLAG_MAX_EXPLANATION = 1000;

export const REVIEW_FLAG_LABEL = 'Flag this inspection for further review';

export const REVIEW_FLAG_HELP = 'Select this only for significant or newly discovered problems that require special attention from Logistics or Fleet, such as major vehicle damage, critical missing equipment, or another unusual safety or operational concern. Do not select this for equipment already reported as missing or damaged, known vehicle damage, or routine issues already being addressed.';

export const EMPTY_REVIEW_FLAG: ReviewFlagDraft = { checked: false, explanation: '', items: [] };

export const reviewFlagItemKey = (ref: ReviewFlagItemRef) => `${ref.compartment_id}\u0000${ref.item_id}`;

/** A draft saved by this or an older build; anything malformed becomes an unchecked flag. */
export function normalizeReviewFlagDraft(value: unknown): ReviewFlagDraft {
  if (!value || typeof value !== 'object') return EMPTY_REVIEW_FLAG;
  const draft = value as Partial<Record<keyof ReviewFlagDraft, unknown>>;
  if (draft.checked !== true) return EMPTY_REVIEW_FLAG;
  const items = Array.isArray(draft.items)
    ? draft.items.filter((entry): entry is ReviewFlagItemRef => Boolean(entry) && typeof entry === 'object'
      && typeof (entry as ReviewFlagItemRef).compartment_id === 'string' && typeof (entry as ReviewFlagItemRef).item_id === 'string')
      .map(entry => ({ compartment_id: entry.compartment_id, item_id: entry.item_id }))
    : [];
  return { checked: true, explanation: typeof draft.explanation === 'string' ? draft.explanation.slice(0, REVIEW_FLAG_MAX_EXPLANATION) : '', items };
}

export const reviewFlagExplanationMissing = (draft: ReviewFlagDraft) => draft.checked && draft.explanation.trim() === '';

/**
 * The payload block, built once at submit. Absent unless the flag is checked; the explanation is trimmed and
 * only items that exist in the issued checklist are referenced (the server rejects unknown references).
 */
export function buildReviewFlag(draft: ReviewFlagDraft, compartments: Compartment[]): ReviewFlag | undefined {
  if (!draft.checked) return undefined;
  const known = new Set(compartments.flatMap(compartment => compartment.items.map(item => reviewFlagItemKey({ compartment_id: compartment.id, item_id: item.id }))));
  const seen = new Set<string>();
  const items = draft.items.filter(entry => {
    const key = reviewFlagItemKey(entry);
    if (!known.has(key) || seen.has(key)) return false;
    seen.add(key);
    return true;
  }).map(entry => ({ compartment_id: entry.compartment_id, item_id: entry.item_id }));
  return { explanation: draft.explanation.trim(), items };
}
