export type FormType = 'ics_214' | 'froc_log_001_ff';

export interface FormDefinition {
    form_type: FormType;
    form_version: string;
    display_name: string;
    capacities: Record<string, number>;
    field_options?: {
        categories: string[];
        descriptions_by_category: Record<string, string[]>;
        description_allows_custom: boolean;
    } | null;
}

export interface EmployeeSuggestion {
    employee_id: string;
    name: string;
    rank?: string | null;
}

export interface FrocImportLaborRow {
    category: string;
    work_performed: string;
    location_gps: string;
    start: string;
    end: string;
    manual_override_hours: string;
    override_reason: string;
    event_related: boolean;
    source_excerpt: string;
    source_timestamp: string;
    confidence: 'high' | 'review';
    end_estimated: boolean;
}

export interface FrocImportPreview {
    event_name: string;
    unit_designation: string;
    report_date: string;
    vehicle_mileage: Record<string, any>[];
    labor: FrocImportLaborRow[];
    engine: string;
    warning: string;
    source_type: string;
    source_sha256: string;
    matched_message_count: number;
}

export interface FormDocument {
    id: string;
    version_number: number;
    source_revision: number;
    display_name: string;
    page_count: number;
    pdf_sha256?: string;
    preview_url: string;
    download_url: string;
    created_at: string;
}

export interface GenerationJob {
    id: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    source_revision: number;
    status_url: string;
    error_message?: string | null;
}

export interface FormRecord {
    id: string;
    form_type: FormType;
    form_version: string;
    title: string;
    status: 'draft' | 'completed';
    data: Record<string, any>;
    revision: number;
    latest_pdf_version: number | null;
    last_autosaved_at: string | null;
    completed_at: string | null;
    updated_at: string;
    has_changes_since_latest_pdf: boolean;
    documents: FormDocument[];
}

export interface BootstrapData {
    employee: { id: number; employee_id: string; name: string; rank?: string | null };
    endpoints: { form_types: string; records: string };
    csrf_token: string;
    build: string;
}

export interface ApiProblem {
    message: string;
    errors?: Record<string, string[]>;
    code?: string;
    server_revision?: number;
    server_data?: Record<string, any>;
    server_saved_at?: string;
}
