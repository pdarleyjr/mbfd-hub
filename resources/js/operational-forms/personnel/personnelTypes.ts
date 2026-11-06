/** Accident reports and the Interoffice Memorandum: what the Forms app and routes/personnel-forms.php say to each other. */

export type ReportType = 'vehicle_accident' | 'supervisor_accident';
export type ReportStatus = 'draft' | 'awaiting_supervisor' | 'complete';
export type EditableSection = 'driver' | 'supervisor';

/** Which screen is open inside the Forms app. */
export type PersonnelView =
    | { name: 'vehicle' }
    | { name: 'supervisor-report' }
    | { name: 'report'; id: string }
    | { name: 'mine' }
    | { name: 'memo' };

export interface PersonnelEndpoints {
    accidents_form: string;
    /** GET lists the member's reports; POST starts a draft. */
    accidents: string;
    /** The per-report URLs contain the placeholder, which is replaced with the report id. */
    accident: string;
    accident_submit: string;
    accident_supervisor: string;
    accident_preview: string;
    memorandum_form: string;
    memorandum_pdf: string;
    memorandum_support_services: string;
    memorandum_email: string;
    /** Returns employee record ids (results: [{ id, name, employee_id }]). */
    employee_search: string;
    placeholder: string;
}

export interface PersonnelDeepLink {
    view: 'vehicle' | 'report' | 'mine' | 'supervisor-report' | 'memo' | null;
    report: string | null;
}

export interface PersonnelBootstrap {
    can_supervise: boolean;
    /** Vehicle Accident Reports routed to this member that wait for the supervisor's part. */
    awaiting_supervisor: number;
}

export interface VehicleOption { id: number; label: string; detail: string }
export interface SupervisorOption { id: number; name: string; rank: string | null; station: string | null }
export interface EmployeeOption { id: number; name: string; employee_id: string | null }

export interface AccidentFormDefinition {
    me: { id: number | null; name: string | null; rank: string | null; employee_id: string | null };
    can_supervise: boolean;
    vehicles: VehicleOption[];
    supervisors: SupervisorOption[];
    causes: Record<string, string>;
    actions: Record<string, string>;
    notices: { vehicle: string; vehicle_distribution: string; supervisor: string; supervisor_distribution: string; hub: string };
}

export interface AccidentSummary {
    id: string;
    reference: string;
    type: ReportType;
    type_label: string;
    status: ReportStatus;
    status_label: string;
    role: string;
    subject_name: string | null;
    vehicle_label: string | null;
    occurred_at: string | null;
    updated_at: string | null;
    awaiting_you: boolean;
}

export interface AccidentDocument { view_url: string; download_url: string; version: number }

export interface AccidentDetail extends AccidentSummary {
    revision: number;
    editable_section: EditableSection | null;
    driver_data: Record<string, unknown>;
    /** Null when this viewer may not see the supervisor's assessment. */
    supervisor_data: Record<string, unknown> | null;
    subject: { id: number; name: string; rank: string | null } | null;
    supervisor: { id: number; name: string; rank: string | null } | null;
    vehicle: { id: number; label: string } | null;
    station_label: string | null;
    submitted_at: string | null;
    supervisor_completed_at: string | null;
    completed_at: string | null;
    last_autosaved_at: string | null;
    document: AccidentDocument | null;
    pdf_pending: boolean;
    can_delete: boolean;
}

export interface MemoFormDefinition {
    from: string;
    date: string;
    support_services_label: string;
    email_copy_available: boolean;
    email_address_hint: string | null;
}

export type MemoDestination = 'support' | 'other';

export interface MemoFields { to: string; from: string; date: string; subject: string; body: string }

/** Display labels for the employee, vehicle and supervisor chosen on this device before the server confirms them. */
export interface MetaLabels { subject?: string; vehicle?: string; supervisor?: string }

/** A report's unsaved entries kept on this device until the server confirms them. */
export interface AccidentLocalDraft {
    key: string;
    owner: string;
    type: ReportType;
    clientKey: string;
    reportId: string | null;
    baseRevision: number | null;
    section: EditableSection;
    values: Record<string, unknown>;
    meta: Record<string, number | null>;
    labels: MetaLabels;
    savedAt: string;
}

export interface MemoLocalDraft extends MemoFields {
    owner: string;
    destination: MemoDestination | null;
    clientKey: string;
    savedAt: string;
}

/** "Offline" means the entries are kept on this device and sent when the connection returns. */
export type SaveStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'offline' | 'conflict' | 'error';
