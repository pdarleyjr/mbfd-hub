import type { ApiProblem, BootstrapData, EditableFormType, EmployeeSuggestion, FormDefinition, FormDocument, FormRecord, FrocImportPreview, FrocImportSummary, GenerationJob } from './types';

export class ApiError extends Error {
    constructor(public status: number, public problem: ApiProblem) {
        super(problem.message || 'The request could not be completed.');
    }
}

export function createApi(bootstrap: BootstrapData) {
    async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
        const isFormData = init.body instanceof FormData;
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
                'X-CSRF-TOKEN': bootstrap.csrf_token,
                ...init.headers,
            },
        });

        if (!response.ok) {
            const problem = await response.json().catch(() => ({ message: `Request failed (${response.status}).` }));
            throw new ApiError(response.status, problem);
        }

        return response.status === 204 ? (undefined as T) : response.json();
    }

    async function generate(id: string): Promise<FormDocument> {
        let result = await request<{ job: GenerationJob; document: FormDocument | null }>(`${bootstrap.endpoints.records}/${id}/generate`, {
            method: 'POST', body: '{}',
        });

        for (let attempt = 0; attempt < 90; attempt += 1) {
            if (result.document) return result.document;
            if (result.job.status === 'failed') throw new Error(result.job.error_message || 'PDF generation failed.');
            await new Promise((resolve) => window.setTimeout(resolve, 1_000));
            result = await request<{ job: GenerationJob; document: FormDocument | null }>(result.job.status_url);
        }

        throw new Error('PDF generation is still queued. You can safely return to the record and try again shortly.');
    }

    return {
        definitions: async () => (await request<{ form_types: FormDefinition[] }>(bootstrap.endpoints.form_types)).form_types,
        records: async () => (await request<{ records: FormRecord[] }>(bootstrap.endpoints.records)).records,
        create: async (formType: EditableFormType, title: string) => (await request<{ record: FormRecord }>(bootstrap.endpoints.records, {
            method: 'POST', body: JSON.stringify({ form_type: formType, title }),
        })).record,
        upload: async (name: string, file: File) => {
            const payload = new FormData();
            payload.append('name', name);
            payload.append('file', file);

            return (await request<{ record: FormRecord }>(bootstrap.endpoints.uploads, {
                method: 'POST',
                body: payload,
            })).record;
        },
        show: async (id: string) => (await request<{ record: FormRecord }>(`${bootstrap.endpoints.records}/${id}`)).record,
        save: async (record: FormRecord) => (await request<{ record: FormRecord }>(`${bootstrap.endpoints.records}/${record.id}`, {
            method: 'PATCH', body: JSON.stringify({ revision: record.revision, data: record.data }),
        })).record,
        generate,
        remove: async (id: string) => request<void>(`${bootstrap.endpoints.records}/${id}`, { method: 'DELETE' }),
        searchEmployees: async (query: string) => (await request<{ employees: EmployeeSuggestion[] }>(`${bootstrap.endpoints.records.replace(/\/records$/, '')}/employees/search?q=${encodeURIComponent(query)}`)).employees,
        importFroc: async (payload: FormData) => (await request<{ preview: FrocImportPreview }>(`${bootstrap.endpoints.records.replace(/\/records$/, '')}/froc/import-preview`, {
            method: 'POST', body: payload,
        })).preview,
        applyFrocImport: async (record: FormRecord, payload: FormData) => {
            payload.set('revision', String(record.revision));
            payload.set('merge_mode', 'fill_empty_and_append');
            return request<{ record: FormRecord; import: FrocImportSummary }>(`${bootstrap.endpoints.records}/${record.id}/froc/import`, {
                method: 'POST', body: payload,
            });
        },
        undoFrocImport: async (record: FormRecord, importId: string) => (await request<{ record: FormRecord }>(`${bootstrap.endpoints.records}/${record.id}/froc/import/${importId}/undo`, {
            method: 'POST', body: JSON.stringify({ revision: record.revision }),
        })).record,
    };
}
