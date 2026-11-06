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
    /** Where the item is kept, as printed ("In Handtevy", "Placed in BVM bag"). */
    location_text?: string;
    /** A printed condition ("Detail Airway Bags Only"). */
    qualifier_text?: string;
    /** The printed color or age group ("Grey", "Yellow (2 years)"). */
    color_band?: string;
    /** `monthly_first_tuesday` is the only cadence. */
    cadence?: string;
    /** Extra search words. Search only, never identity. */
    search_terms?: string[];
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
    /** The label exactly as printed (misspellings included); `label` may be a cleaned display name. */
    source_label?: string | null;
    /** Extra search words, when the server sends them next to the flags. */
    search_terms?: string[];
}

export interface EmsFormSection {
    key: string;
    label: string;
    parent_key: string | null;
    /** A printed note shown under the section heading. */
    note?: string | null;
    /** `monthly_first_tuesday` is the only cadence. */
    cadence?: string | null;
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

/** The four forms. GET /form?family=<key> answers for one; `families` lists them all. */
export interface EmsFamilySummary {
    key: string;
    title: string;
    short_title: string;
    /** The deep-link `form=` value. */
    form_key: string;
    description: string;
}

export interface EmsFamily extends EmsFamilySummary {
    bag_keys: string[];
    /** `detail`, `unit:<id>` or null: the unit the form preselects. The member can change it. */
    default_target: string | null;
}

export interface EmsFormDefinition {
    /** Absent on a server from before the four forms: that is ALS Transport. */
    family?: EmsFamily;
    families?: EmsFamilySummary[];
    forms: EmsForm[];
    units: EmsUnit[];
    limits: { max_quantity: number; note_max: number; event_assignment_max: number };
    rules: { blank_is_not_zero: boolean; needs_item_or_note: boolean; controlled_substance_note: string };
}

/** The unsent request as the member builds it. Quantities and answers are keyed by `lineKey()`. */
export interface EmsDraft {
    clientSubmissionId: string;
    /** The form this request is for. A draft without one is ALS Transport. */
    family?: string;
    /** '' (not chosen), 'detail', or `unit:<apparatus id>` */
    targetChoice: string;
    eventAssignment: string;
    note: string;
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
    /** Entries for items the form does not list right now. Saved with the draft, never sent. */
    retired?: EmsRetired;
    /** Item names for the retired entries, so they can be listed by name. */
    labels?: Record<string, string>;
    /** Set while the member is fixing a request Captain 5 sent back: the uuid of the request this one replaces. */
    revises?: string;
    /** The returned request's number, so the banner can name it. Display only. */
    revisesNumber?: string;
    /** Why Captain 5 sent it back, for the banner. Display only. */
    returnReason?: string;
}

export interface EmsRetired {
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
}

/** What is stored on the device for an unfinished draft: quantities, the unit choice and the submission id only. */
export interface StoredDraft {
    v: 1;
    clientSubmissionId: string;
    /** Optional and additive (the stored version stays 1): a row without one is ALS Transport. */
    family?: string;
    targetChoice: string;
    quantities: Record<string, number>;
    answers: Record<string, boolean>;
    retired?: EmsRetired;
    /** Item name per key that has an entry (optional, additive). */
    labels?: Record<string, string>;
    /** Optional and additive (the stored version stays 1): present only on a correction in progress. */
    revises?: string;
    revisesNumber?: string;
    returnReason?: string;
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
    /** The form this request is for. A queued send without one is ALS Transport. */
    family?: string;
    /** The returned request this one corrects (the member's own, status returned). The server supersedes it. */
    revises?: string;
    target: { kind: EmsTargetKind; apparatus_id?: number; event_assignment?: string; label_seen?: string };
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
    /** Present when this is a correction: which returned request it replaces, for the waiting notice and for editing it again. */
    revision?: { number: string; reason: string };
    /** The form's short title, to name it in the waiting notice. Display only; the payload's family is what is sent. */
    familyShort?: string;
}

export type EmsStatus =
    | 'submitted' | 'reviewed' | 'prepared' | 'partially_issued' | 'issued' | 'closed' | 'cancelled'
    | 'returned' | 'reconciled' | 'superseded';

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
    /** The form the request was made on. Absent or null means ALS Transport. */
    form_family?: string | null;
    form_family_title?: string | null;
    form_family_short?: string | null;
    last_activity_at: string | null;
    closed_at: string | null;
    /** True when Captain 5 sent it back and the member can fix and resend it. */
    can_revise?: boolean;
    /** Why Captain 5 sent it back (3 to 500 characters). */
    return_reason?: string | null;
    returned_at?: string | null;
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
    /** The unit the request was for. Lets "Fix and resend" pick the same unit; without it the unit name is matched. */
    apparatus_id?: number | null;
    returned_by?: string | null;
    revision_number?: number | null;
    /** The returned request this one corrects, and the request that replaced this one (uuids). */
    revision_of?: string | null;
    replaced_by?: string | null;
    /** Optional: the numbers of those two, so a link does not need another call. */
    revision_of_number?: string | null;
    replaced_by_number?: string | null;
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
    /** `family`: which of the four forms (ALS Transport when absent). `revises`: the returned request being fixed, so a refresh or a shared link comes back to the correction; its own family wins. */
    | { name: 'form'; family?: string; revises?: string }
    | { name: 'mine' }
    | { name: 'detail'; id: string };

export interface EmsDeepLink {
    view: 'form' | 'mine' | 'detail' | null;
    request: string | null;
    /** A family key or its form key; absent means ALS Transport. */
    family?: string | null;
}
