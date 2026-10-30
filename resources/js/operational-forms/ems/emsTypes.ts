/** Shapes of the Medical Inventory Request JSON API (routes/ems.php, docs/ems/ems-inventory-contract.md section 9). */

export type EmsBagKey = 'airway-bag' | 'als-med-bag' | 'als-transport-vehicle' | string;
export type EmsResponseType = 'quantity' | 'yes_no';
export type EmsTargetKind = 'rescue_unit' | 'other_unit' | 'detail_event';

export interface EmsFlags {
    detail_airway_bag_only?: boolean;
    rescue_only?: boolean;
    refrigerated?: boolean;
    controlled_substance?: boolean;
    /** The transport vehicle sheet's "Fill if <" level, as printed. min_text is its AMOUNT (par). */
    fill_if_text?: string;
    fill_if_qty?: number | null;
}

export interface EmsFormItem {
    item_key: string;
    label: string;
    min_text: string | null;
    min_qty: number | null;
    min_unit: string | null;
    variant_group: string | null;
    variant_label: string | null;
    response_type: EmsResponseType;
    flags: EmsFlags;
    aliases?: string[];
}

export interface EmsFormSection {
    key: string;
    label: string;
    parent_key: string | null;
    items: EmsFormItem[];
}

export interface EmsForm {
    key: EmsBagKey;
    title: string;
    version_label: string | null;
    printed_note?: string | null;
    sections: EmsFormSection[];
}

export interface EmsUnit {
    id: number | null;
    kind: EmsTargetKind;
    label: string;
    designation: string | null;
    status: string | null;
    group: string;
}

export interface EmsFormDefinition {
    forms: EmsForm[];
    units: EmsUnit[];
    limits: { max_quantity: number; note_max: number; event_assignment_max: number };
    rules: { blank_is_not_zero: boolean; needs_item_or_note: boolean; controlled_substance_note: string };
}

/** The unsent request as the member builds it. Quantities and answers are keyed by `lineKey()`. */
export interface EmsDraft {
    clientSubmissionId: string;
    /** '' (not chosen), 'detail', or `unit:<apparatus id>` */
    targetChoice: string;
    eventAssignment: string;
    note: string;
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
    /** Entries for items the form does not list right now. Saved with the draft, never sent. */
    carried?: EmsCarried;
}

export interface EmsCarried {
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
}

/** What is stored on the device for an unfinished draft: quantities, the unit choice and the submission id only. */
export interface StoredDraft {
    v: 1;
    clientSubmissionId: string;
    targetChoice: string;
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
    carried?: EmsCarried;
    savedAt: string;
}

export interface EmsSubmitLine {
    form: EmsBagKey;
    item_key: string;
    quantity?: number;
    flag?: boolean;
}

export interface EmsSubmitPayload {
    client_submission_id: string;
    target: { kind: EmsTargetKind; apparatus_id?: number; event_assignment?: string };
    member_note?: string;
    lines: EmsSubmitLine[];
}

export interface EmsOutboxEntry {
    clientSubmissionId: string;
    payload: EmsSubmitPayload;
    createdAt: string;
    state: 'pending' | 'rejected';
    message?: string;
    /** The unit and item count to show while the request waits to be sent. */
    unitLabel: string;
    itemCount: number;
}

export type EmsStatus = 'submitted' | 'reviewed' | 'prepared' | 'partially_issued' | 'issued' | 'closed' | 'cancelled';

export interface EmsRequestSummary {
    id: string;
    number: string;
    status: EmsStatus;
    status_label: string;
    submitted_at: string | null;
    target_kind: EmsTargetKind;
    target_label: string;
    event_assignment: string | null;
    line_count: number;
    /** Present when the server reports it; the list shows "n of m issued" only when it is. */
    issued_line_count?: number;
    has_note: boolean;
    last_activity_at: string | null;
    closed_at: string | null;
}

export interface EmsRequestLine {
    id: number;
    bag_key: string | null;
    item_key: string;
    label: string;
    section_label: string | null;
    variant_label: string | null;
    min_text: string | null;
    unit: string | null;
    response_type: EmsResponseType;
    requested_qty: number | null;
    requested_flag: boolean | null;
    prepared_qty: number | null;
    issued_qty: number;
    line_status: string;
    line_status_label: string;
    not_issued_reason: string | null;
    refrigerated: boolean;
    controlled_substance: boolean;
}

export interface EmsHistoryEvent {
    id: number;
    action: string;
    actor_name: string | null;
    actor_rank: string | null;
    actor_context: string;
    actor_context_label: string;
    request_line_id: number | null;
    from_status: string | null;
    to_status: string | null;
    payload: { quantity?: number; reason?: string; label?: string };
    created_at: string | null;
}

export interface EmsMessage {
    id: number;
    author_name: string | null;
    author_rank: string | null;
    author_context: string;
    author_context_label: string;
    body: string;
    created_at: string | null;
}

export interface EmsFilesCopy {
    status: 'pending' | 'ready';
    record_id?: string | null;
    document_id?: string | null;
    display_name?: string;
    preview_url?: string;
    download_url?: string;
}

export interface EmsRequestDetail extends EmsRequestSummary {
    member_note: string | null;
    lines: EmsRequestLine[];
    history: EmsHistoryEvent[];
    messages: EmsMessage[];
    close_reason: string | null;
    cancel_reason: string | null;
    files_copy: EmsFilesCopy;
    can_cancel: boolean;
}

export interface EmsEndpoints {
    form: string;
    requests: string;
    request: string;
    messages: string;
    cancel: string;
    placeholder: string;
}

export type EmsView =
    | { name: 'form' }
    | { name: 'mine' }
    | { name: 'detail'; id: string };

export interface EmsDeepLink {
    view: 'form' | 'mine' | 'detail' | null;
    request: string | null;
}
