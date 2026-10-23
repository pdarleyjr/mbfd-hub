import type { PendingInventoryCorrection } from '../types';
import { db } from '../lib/db';
import { submitOrQueueWithResponse, type SubmissionOutcome } from '../lib/sync';

export type ItemCorrectionReason = 'no_longer_carried' | 'moved' | 'replaced' | 'wrong_quantity' | 'wrong_description' | 'other';
export type CorrectionReason = ItemCorrectionReason | 'new_item';
export type CorrectionMode = 'item' | 'new_item';

export const ITEM_REASONS: ReadonlyArray<{ value: ItemCorrectionReason; label: string }> = [
  { value: 'no_longer_carried', label: 'No longer carried on this apparatus' },
  { value: 'moved', label: 'Moved to a different compartment' },
  { value: 'replaced', label: 'Replaced with a different model or type' },
  { value: 'wrong_quantity', label: 'Quantity is wrong' },
  { value: 'wrong_description', label: 'Name or description is wrong' },
  { value: 'other', label: 'Something else' },
];

/** Select value for "Somewhere else", which asks for free text instead of a compartment. */
export const ELSEWHERE = '__elsewhere__';
export const NOTE_MAX = 1000;
export const QUANTITY_MAX = 999;
export const PHOTO_MAX_BYTES = 5_000_000;

export const SENT_TEXT = 'Sent to Logistics for review';
export const QUEUED_TEXT = 'Saved on this device. It will be sent when you\'re back online.';

/** Everything the member has typed for one report, kept in the workspace so closing the panel never loses it. */
export interface CorrectionForm {
  open: boolean;
  reason: ItemCorrectionReason | '';
  toCompartmentId: string;
  toLocationText: string;
  name: string;
  manufacturer: string;
  model: string;
  description: string;
  quantity: string;
  note: string;
  photo?: string;
  /** Bumped to clear the file picker so the same file can be chosen again. */
  photoKey: number;
  showErrors: boolean;
  sending: boolean;
  error: string | null;
  result: SubmissionOutcome | null;
  /** The id of the last send of this exact payload; a retry of the same payload reuses it so the server can ignore a duplicate. */
  attempt?: { id: string; fingerprint: string };
}

export function emptyCorrectionForm(expectedQuantity?: number): CorrectionForm {
  return {
    open: false, reason: '', toCompartmentId: '', toLocationText: '', name: '', manufacturer: '', model: '', description: '',
    quantity: expectedQuantity === undefined ? '' : String(expectedQuantity), note: '', photo: undefined, photoKey: 0, showErrors: false, sending: false, error: null, result: null, attempt: undefined,
  };
}

export type CorrectionErrors = Partial<Record<'reason' | 'toCompartmentId' | 'toLocationText' | 'name' | 'quantity' | 'note', string>>;

const validQuantity = (value: string): boolean => /^\d{1,3}$/.test(value.trim()) && Number(value) >= 1 && Number(value) <= QUANTITY_MAX;

export function validateCorrection(mode: CorrectionMode, form: CorrectionForm): CorrectionErrors {
  const errors: CorrectionErrors = {};
  const quantityMessage = `Enter a whole number from 1 to ${QUANTITY_MAX}.`;
  if (mode === 'new_item') {
    if (form.name.trim() === '') errors.name = 'Enter the name of the item.';
    if (form.quantity.trim() !== '' && !validQuantity(form.quantity)) errors.quantity = quantityMessage;
    return errors;
  }
  if (form.reason === '') {
    errors.reason = 'Choose what is wrong.';
    return errors;
  }
  if (form.reason === 'moved') {
    if (form.toCompartmentId === '') errors.toCompartmentId = 'Choose where it is carried now.';
    else if (form.toCompartmentId === ELSEWHERE && form.toLocationText.trim() === '') errors.toLocationText = 'Say where it is carried now.';
  }
  if (form.reason === 'wrong_quantity' && !validQuantity(form.quantity)) errors.quantity = quantityMessage;
  if (form.reason === 'wrong_description' && form.name.trim() === '' && form.description.trim() === '' && form.note.trim() === '') {
    errors.name = 'Enter the correct name or description.';
  }
  if (form.reason === 'other' && form.note.trim() === '') errors.note = 'Tell Logistics what is wrong.';
  return errors;
}

export interface CorrectionBodyArgs {
  mode: CorrectionMode;
  form: CorrectionForm;
  checklistVersion: string;
  compartmentId: string;
  itemId?: string;
  draftId: string | null;
}

const trimmedFields = (form: CorrectionForm, keys: ReadonlyArray<'name' | 'manufacturer' | 'model' | 'description'>): Record<string, string> => Object.fromEntries(
  keys.map(key => [key, form[key].trim()] as const).filter(([, value]) => value !== ''),
);

/** The request body without its idempotency id. Only fields relevant to the chosen reason are sent. */
export function buildCorrectionBody({ mode, form, checklistVersion, compartmentId, itemId, draftId }: CorrectionBodyArgs): Record<string, unknown> {
  const reason: CorrectionReason = mode === 'new_item' ? 'new_item' : (form.reason as ItemCorrectionReason);
  const proposal: Record<string, unknown> = reason === 'moved'
    ? (form.toCompartmentId === ELSEWHERE ? { to_location_text: form.toLocationText.trim() } : { to_compartment_id: form.toCompartmentId })
    : reason === 'replaced' ? trimmedFields(form, ['manufacturer', 'model', 'description'])
    : reason === 'wrong_quantity' ? { quantity: Number(form.quantity) }
    : reason === 'wrong_description' ? trimmedFields(form, ['name', 'description'])
    : reason === 'new_item' ? { ...trimmedFields(form, ['name', 'manufacturer', 'model', 'description']), ...(form.quantity.trim() !== '' ? { quantity: Number(form.quantity) } : {}) }
    : {};
  const note = form.note.trim();

  return {
    checklist_version: checklistVersion,
    reason,
    compartment_id: compartmentId,
    ...(mode === 'item' && itemId ? { item_id: itemId } : {}),
    ...(Object.keys(proposal).length > 0 ? { proposal } : {}),
    ...(note !== '' ? { note } : {}),
    ...((reason === 'replaced' || reason === 'new_item') && form.photo ? { photo: form.photo } : {}),
    ...(draftId ? { inspection_draft_id: draftId } : {}),
  };
}

export const correctionQueueType = (apparatusId: number): string => `apparatuses/${apparatusId}/inventory-corrections`;

/**
 * Sends the report, or saves it in the owner-bound offline queue when the device is offline or the server is
 * unavailable. A 4xx throws (the server will never accept this payload); nothing the member typed is touched.
 */
export async function sendInventoryCorrection(apparatusId: number, payload: Record<string, unknown>): Promise<SubmissionOutcome> {
  return (await submitOrQueueWithResponse(correctionQueueType(apparatusId), payload, '/api/public')).outcome;
}

/** Plain-language message for a failed send; the queue reports server rejections as "HTTP 422: detail". */
export function correctionErrorMessage(error: unknown): string {
  const text = error instanceof Error ? error.message : '';
  const match = /^HTTP (\d{3}):\s*(.*)$/s.exec(text);
  if (!match) return 'This report could not be saved on this device. What you typed is still here. Try again.';
  const status = Number(match[1]);
  const detail = match[2].trim();
  if (status === 401 || status === 419) return 'Your sign-in expired. What you typed is still here. Sign in again, then send it.';
  const reason = detail !== '' && !/^(unprocessable|bad request|conflict|forbidden|not found)/i.test(detail) ? ` ${detail.replace(/\.?$/, '.')}` : '';
  return `Logistics could not accept this report.${reason} What you typed is still here, so you can change it and try again.`;
}

/** A report that is waiting on Logistics, for the marker on its item or compartment. */
export interface CorrectionMarker {
  compartmentId: string | null;
  /** Null for an unlisted item. */
  itemId: string | null;
  kind: 'pending' | 'question';
  /** The server's id, when known, so a question can link straight to its conversation. */
  correctionId?: number;
}

/** What CompartmentStep needs to file a report and show markers. */
export interface InventoryCorrectionContext {
  apparatusId: number;
  checklistVersion: string;
  draftId: string | null;
  markers: CorrectionMarker[];
  onRecorded: (marker: CorrectionMarker) => void;
}

export function serverMarkers(pending: PendingInventoryCorrection[] | undefined): CorrectionMarker[] {
  return (pending ?? []).map(entry => ({
    compartmentId: entry.compartment_id ?? null,
    itemId: entry.item_id ?? null,
    kind: entry.status === 'clarification_requested' && entry.mine ? 'question' : 'pending',
    correctionId: typeof entry.id === 'number' ? entry.id : undefined,
  }));
}

/** Reports saved on this device for this member that have not been sent yet. */
export async function readQueuedCorrectionMarkers(apparatusId: number, ownerUserId: number): Promise<CorrectionMarker[]> {
  try {
    const rows = await db.pendingSubmissions.where('type').equals(correctionQueueType(apparatusId)).toArray();
    return rows
      .filter(row => row.ownerUserId === ownerUserId && (row.status === 'pending' || row.status === 'processing'))
      .map(row => ({
        compartmentId: typeof row.data.compartment_id === 'string' ? row.data.compartment_id : null,
        itemId: typeof row.data.item_id === 'string' ? row.data.item_id : null,
        kind: 'pending' as const,
      }));
  } catch {
    // Markers are a convenience; the queue itself is untouched.
    return [];
  }
}

/** Where a member answers Logistics: the correction's own page when known, else the list of their corrections. */
export const correctionLink = (marker?: CorrectionMarker): string => (marker?.correctionId !== undefined
  ? `/employee/my-inventory-corrections/${marker.correctionId}`
  : '/employee/my-vehicle-inspections#inventory-corrections');

/** The question marker for one checklist item, if Logistics asked this member about it. */
export const questionFor = (markers: CorrectionMarker[], compartmentId: string, itemId: string | null): CorrectionMarker | undefined => (
  markers.find(marker => marker.compartmentId === compartmentId && marker.itemId === itemId && marker.kind === 'question')
);

/** The marker for one checklist item; a question outranks a plain pending report. */
export function markerForItem(markers: CorrectionMarker[], compartmentId: string, itemId: string): CorrectionMarker['kind'] | null {
  const own = markers.filter(marker => marker.compartmentId === compartmentId && marker.itemId === itemId);
  return own.some(marker => marker.kind === 'question') ? 'question' : own.length > 0 ? 'pending' : null;
}

/** Pending reports about unlisted items in one compartment. */
export function unlistedMarkers(markers: CorrectionMarker[], compartmentId: string): { pending: number; question: boolean } {
  const own = markers.filter(marker => marker.compartmentId === compartmentId && marker.itemId === null);
  return { pending: own.length, question: own.some(marker => marker.kind === 'question') };
}
