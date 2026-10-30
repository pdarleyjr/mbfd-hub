/** Narcotics check: what the Forms app and the JSON API (routes/narcotics.php) say to each other. */

export type CheckStatus = 'pending' | 'approved' | 'rejected';

/** Which Narcotics check screen is open inside the Forms app. */
export type NarcoticsView = { name: 'form' } | { name: 'mine' } | { name: 'detail'; id: string };

export interface NarcoticsEndpoints {
    form: string;
    mine: string;
    /** Contains the placeholder, which is replaced with the check id. */
    check: string;
    store: string;
    placeholder: string;
}

export interface NarcoticsDeepLink {
    view: 'form' | 'mine' | 'detail' | null;
    check: string | null;
}

export interface NarcoticsFormDefinition {
    note_max_length: number;
    /** The one server list of rescue units, in display order, "Other" last. Optional so an older answer still opens the form. */
    rescue_units?: string[];
    /** The option that asks for typed text (the last in the list). */
    rescue_unit_other_option?: string;
    rescue_unit_other_max_length?: number;
    /** The longest seal number the server accepts. Optional so an older answer still opens the form. */
    seal_number_max_length?: number;
    accepted_mime_types: string[];
    max_upload_bytes: number;
    max_edge: number;
    min_edge: number;
}

export interface CheckImage {
    available: boolean;
    thumb_url: string | null;
    preview_url: string | null;
    full_url: string | null;
    width: number;
    height: number;
    bytes: number;
    mime: string;
}

export interface CheckHistoryEntry {
    action: string;
    actor_name: string | null;
    actor_rank: string | null;
    from_status: string | null;
    to_status: string | null;
    at: string | null;
}

export interface NarcoticsCheckSummary {
    id: string;
    status: CheckStatus;
    status_label: string;
    archived: boolean;
    archived_at: string | null;
    submitted_at: string;
    decided_at: string | null;
    submitter_name: string;
    submitter_rank: string | null;
    has_note: boolean;
    member_note: string | null;
    /** "R22", or the typed text for Other; null on a check sent before units were asked for. */
    rescue_unit: string | null;
    /** The stored option and typed text, used to prefill the answer to a rejected check. */
    rescue_unit_choice: string | null;
    rescue_unit_other: string | null;
    /** As typed (trimmed); null on a check sent before seal numbers were asked for. Also prefills the answer to a rejected check. */
    seal_number: string | null;
    decision_note: string | null;
    decided_by_name: string | null;
    decided_by_rank: string | null;
    resubmission_of: string | null;
    resubmitted_as: string | null;
    can_resubmit: boolean;
    image: CheckImage;
}

export interface NarcoticsCheckDetail extends NarcoticsCheckSummary {
    history: CheckHistoryEntry[];
}

/** A photo that has been prepared in this browser and is waiting to be sent. Held in memory only: never written to device storage. */
export interface PreparedPhoto {
    blob: Blob;
    type: string;
    width: number;
    height: number;
    /** false when the browser could not process it and the original file is sent as is (the server re-encodes it). */
    processed: boolean;
    name: string;
}

export interface SendInput {
    photo: PreparedPhoto;
    /** The option chosen, and the typed text only when that option is Other. */
    rescueUnit: string;
    rescueUnitOther: string | null;
    sealNumber: string;
    note: string;
    clientSubmissionId: string;
    resubmissionOf: string | null;
}
