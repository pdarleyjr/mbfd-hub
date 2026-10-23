import type { IssueActionFields, IssueActionName, IssueDetail } from './types';

export type FieldKey = 'vendor_reference' | 'expected_delivery' | 'parts_ordered' | 'pickup_instructions'
  | 'resolution_type' | 'resolution_notes' | 'verified_in_place' | 'reason';

export interface FormValues {
  vendor_reference: string;
  expected_delivery: string;
  parts_ordered: string;
  pickup_instructions: string;
  resolution_type: NonNullable<IssueActionFields['resolution_type']>;
  resolution_notes: string;
  verified_in_place: boolean;
  reason: string;
  member_message: string;
  internal_note: string;
}

export const EMPTY_VALUES: FormValues = {
  vendor_reference: '', expected_delivery: '', parts_ordered: '', pickup_instructions: '',
  resolution_type: 'replacement_installed', resolution_notes: '', verified_in_place: false,
  reason: '', member_message: '', internal_note: '',
};

interface ActionMeta {
  label: string;
  confirm: string;
  /** Confirmation shown after the server accepts it. */
  done: string;
  intro: string;
  fields: FieldKey[];
  required: FieldKey[];
}

export const ACTIONS: Record<IssueActionName, ActionMeta> = {
  acknowledge: {
    label: 'Acknowledge', confirm: 'Acknowledge', done: 'Acknowledged.',
    intro: 'Confirms you have seen this issue. It stays open until it is replaced or dismissed.', fields: [], required: [],
  },
  ordered: {
    label: 'Mark ordered', confirm: 'Save as ordered', done: 'Saved as ordered.',
    intro: 'Record the order so the crew and Logistics can see when to expect it.', fields: ['parts_ordered', 'vendor_reference', 'expected_delivery'], required: [],
  },
  update_delivery: {
    label: 'Update delivery', confirm: 'Save delivery details', done: 'Delivery details saved.',
    intro: 'Change the vendor reference, expected delivery date or pickup instructions.', fields: ['vendor_reference', 'expected_delivery', 'pickup_instructions'], required: [],
  },
  available_for_pickup: {
    label: 'Mark available for pickup', confirm: 'Mark available for pickup', done: 'Marked available for pickup.',
    intro: 'The replacement is in hand and waiting. This does not close the issue.', fields: ['pickup_instructions'], required: [],
  },
  replaced: {
    label: 'Replaced / returned to service', confirm: 'Confirm replaced', done: 'Marked replaced and returned to service.',
    intro: 'Closes the issue. Confirm the replacement is on the unit, not just ordered or ready for pickup.',
    fields: ['resolution_type', 'resolution_notes', 'verified_in_place'], required: ['resolution_notes', 'verified_in_place'],
  },
  verify: {
    label: 'Verify correction', confirm: 'Verify correction', done: 'Correction verified.',
    intro: 'A crew member reported this as corrected. Confirm you checked it.', fields: ['resolution_notes'], required: [],
  },
  dismiss: {
    label: 'Close: no action required', confirm: 'Close with no action', done: 'Closed with no action required.',
    intro: 'Closes the issue with no further action. The reason is kept in its history.', fields: ['reason'], required: ['reason'],
  },
  reopen: {
    label: 'Reopen', confirm: 'Reopen issue', done: 'Issue reopened.',
    intro: 'Puts a closed issue back in the queue.', fields: ['reason'], required: ['reason'],
  },
};

export const REQUIRED_MESSAGES: Partial<Record<FieldKey, string>> = {
  resolution_notes: 'Describe what was replaced or repaired.',
  verified_in_place: 'Confirm the replacement is in place on the unit before closing the issue.',
  reason: 'Give a reason.',
};

/** The one action to emphasise for this state; the rest are offered quietly. */
export function primaryAction(issue: IssueDetail): IssueActionName | null {
  const allowed = issue.actions;
  const wanted: IssueActionName | undefined = !issue.open ? 'reopen'
    : issue.awaiting_verification ? 'verify'
      : ({ reported: 'acknowledge', acknowledged: 'ordered', repair_scheduled: 'ordered', action_pending: 'available_for_pickup', replacement_available: 'replaced' } as Record<string, IssueActionName>)[issue.action_status];
  return wanted && allowed.includes(wanted) ? wanted : (allowed[0] ?? null);
}

export function validate(action: IssueActionName, values: FormValues): Partial<Record<FieldKey, string>> {
  const errors: Partial<Record<FieldKey, string>> = {};
  for (const key of ACTIONS[action].required) {
    const value = values[key];
    if (key === 'verified_in_place' ? value !== true : String(value).trim() === '') errors[key] = REQUIRED_MESSAGES[key] ?? 'This is required.';
  }
  return errors;
}

/** Only what the member filled in; blank optional fields are not sent. */
export function toFields(action: IssueActionName, values: FormValues, canInternal: boolean): IssueActionFields {
  const fields: Record<string, unknown> = {};
  for (const key of ACTIONS[action].fields) {
    const value = values[key];
    if (key === 'verified_in_place') fields[key] = value === true;
    else if (key === 'resolution_type') fields[key] = value;
    else if (String(value).trim() !== '') fields[key] = String(value).trim();
  }
  if (values.member_message.trim() !== '') fields.member_message = values.member_message.trim();
  if (canInternal && values.internal_note.trim() !== '') fields.internal_note = values.internal_note.trim();
  return fields as IssueActionFields;
}

export const RESOLUTION_TYPES: Array<{ value: FormValues['resolution_type']; label: string }> = [
  { value: 'replacement_installed', label: 'Replacement installed' },
  { value: 'spare_issued', label: 'Spare issued' },
  { value: 'repair_completed', label: 'Repair completed' },
];
