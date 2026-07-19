import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle,
    Field, FluentProvider, Input, MessageBar, MessageBarBody, Spinner, Textarea, webLightTheme,
} from '@fluentui/react-components';
import {
    AlertTriangle, ArrowLeft, BookOpen, Check, ChevronRight, ClipboardList, Clock3, Cloud, Copy, Download,
    Bot, Eye, FileArchive, FileCheck2, FilePenLine, FilePlus2, FileUp, Home, Library, LoaderCircle, Plus, Printer, RefreshCw, Save,
    ShieldCheck, Sparkles, Trash2, UsersRound, WifiOff,
} from 'lucide-react';
import { ApiError, createApi } from './api';
import { recoveryDrafts, type RecoveryDraft } from './draftsDb';
import type { BootstrapData, EditableFormType, EmployeeSuggestion, FormDefinition, FormDocument, FormRecord, FormType, FrocImportSummary } from './types';

const PdfPreview = lazy(() => import('./PdfPreview').then((module) => ({ default: module.PdfPreview })));

type SaveState = 'idle' | 'saving' | 'saved' | 'offline' | 'error';
type Conflict = { serverRevision: number; serverData: Record<string, any>; serverSavedAt?: string };

const EMPTY_ROWS: Record<string, Record<string, any>> = {
    resources: { name: '', ics_position: '', home_agency_unit: '' },
    activities: { date: '', time: '', notable_activity: '' },
    team_members: { employee_id: '', employee_name: '' },
    labor: { category: '', work_performed: '', location_gps: '', start: '', end: '', manual_override_hours: '', override_reason: '', event_related: true },
    equipment_hours: { category: '', equipment_id: '', operator: '', description: '', location: '', hours: '', event_related: true },
    vehicle_mileage: { category: '', equipment_id: '', operator: '', destination: '', start_odometer: '', end_odometer: '', manual_miles: '', correction_reason: '', event_related: true },
    materials: { category: '', item: '', quantity: '', cost: '', justification: '', receipt_reference: '', from_stock: false },
    additional_notes: { value: '' },
};

function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)); }
function stamp(value?: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Not yet saved'; }
function typeLabel(type: FormType) {
    if (type === 'ics_214') return 'ICS 214';
    if (type === 'uploaded_file') return 'Submitted file';
    return 'F-ROC Daily Activity Report';
}
function latestDocument(record: FormRecord) { return [...record.documents].sort((a, b) => b.version_number - a.version_number)[0] || null; }

export function OperationalFormsApp({ bootstrap }: { bootstrap: BootstrapData }) {
    const api = useMemo(() => createApi(bootstrap), [bootstrap]);
    const [definitions, setDefinitions] = useState<FormDefinition[]>([]);
    const [records, setRecords] = useState<FormRecord[]>([]);
    const [current, setCurrent] = useState<FormRecord | null>(null);
    const currentRef = useRef<FormRecord | null>(null);
    const dirtyRef = useRef(false);
    const changeCounterRef = useRef(0);
    const savePromiseRef = useRef<Promise<FormRecord | null> | null>(null);
    const [saveState, setSaveState] = useState<SaveState>('idle');
    const [busy, setBusy] = useState(true);
    const [message, setMessage] = useState<{ intent: 'error' | 'warning' | 'success' | 'info'; text: string } | null>(null);
    const [conflict, setConflict] = useState<Conflict | null>(null);
    const [recovery, setRecovery] = useState<RecoveryDraft | null>(null);
    const [preview, setPreview] = useState<FormDocument | null>(null);
    const [activeSection, setActiveSection] = useState('overview');
    const [isGenerating, setIsGenerating] = useState(false);

    useEffect(() => { currentRef.current = current; }, [current]);

    const refreshLibrary = useCallback(async () => {
        setBusy(true);
        try {
            const [nextDefinitions, nextRecords] = await Promise.all([api.definitions(), api.records()]);
            setDefinitions(nextDefinitions); setRecords(nextRecords); setMessage(null);
        } catch (error) {
            setMessage({ intent: 'error', text: error instanceof Error ? error.message : 'The forms library could not be loaded.' });
        } finally { setBusy(false); }
    }, [api]);

    useEffect(() => { void refreshLibrary(); }, [refreshLibrary]);

    const openRecord = useCallback(async (record: FormRecord) => {
        setBusy(true);
        try {
            const loaded = await api.show(record.id);
            const cached = await recoveryDrafts.get(record.id);
            currentRef.current = loaded; setCurrent(loaded); dirtyRef.current = false; setSaveState('idle'); setActiveSection('overview');
            if (cached && cached.revision >= loaded.revision && JSON.stringify(cached.data) !== JSON.stringify(loaded.data)) setRecovery(cached);
        } catch (error) {
            setMessage({ intent: 'error', text: error instanceof Error ? error.message : 'The record could not be opened.' });
        } finally { setBusy(false); }
    }, [api]);

    const createRecord = async (formType: EditableFormType) => {
        setBusy(true);
        try {
            const now = new Date();
            const title = `${typeLabel(formType)} — ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(now)}`;
            const created = await api.create(formType, title);
            setRecords((items) => [created, ...items.filter((item) => item.id !== created.id)]); currentRef.current = created; setCurrent(created); dirtyRef.current = false; setSaveState('saved'); setActiveSection('overview');
        } catch (error) { setMessage({ intent: 'error', text: error instanceof Error ? error.message : 'The record could not be created.' }); }
        finally { setBusy(false); }
    };

    const uploadFile = async (name: string, file: File): Promise<void> => {
        const created = await api.upload(name, file);
        setRecords((items) => [created, ...items.filter((item) => item.id !== created.id)]);
        setMessage({ intent: 'success', text: `“${created.title}” was submitted as a completed form and is ready for administrators.` });
    };

    const openDocument = (document: FormDocument) => {
        if (document.mime_type === 'application/pdf') {
            setPreview(document);
            return;
        }

        window.open(document.preview_url, '_blank', 'noopener,noreferrer');
    };

    const applyFrocImport = async (payload: FormData): Promise<FrocImportSummary> => {
        let record = currentRef.current;
        if (!record || record.form_type !== 'froc_log_001_ff') throw new Error('Open a F-ROC draft before importing activity notes.');
        if (dirtyRef.current) record = await flushSaves();
        if (!record) throw new Error('Save the current draft before importing activity notes.');
        const result = await api.applyFrocImport(record, payload);
        currentRef.current = result.record; setCurrent(result.record); dirtyRef.current = false; setSaveState('saved');
        setRecords((items) => items.map((item) => item.id === result.record.id ? result.record : item));
        await recoveryDrafts.remove(result.record.id);
        return result.import;
    };

    const undoFrocImport = async (importId: string): Promise<void> => {
        const record = currentRef.current;
        if (!record) return;
        const restored = await api.undoFrocImport(record, importId);
        currentRef.current = restored; setCurrent(restored); dirtyRef.current = false; setSaveState('saved');
        setRecords((items) => items.map((item) => item.id === restored.id ? restored : item));
    };

    const updateCurrent = useCallback((mutator: (data: Record<string, any>) => void) => {
        const record = currentRef.current;
        if (!record) return;
        const data = clone(record.data); mutator(data); dirtyRef.current = true; setSaveState(navigator.onLine ? 'idle' : 'offline');
        changeCounterRef.current += 1;
        const next = { ...record, data, has_changes_since_latest_pdf: record.latest_pdf_version !== null };
        currentRef.current = next;
        setCurrent(next);
        void recoveryDrafts.put({ recordId: record.id, revision: record.revision, data, savedAt: new Date().toISOString() });
    }, []);

    const saveNow = useCallback(async (): Promise<FormRecord | null> => {
        const record = currentRef.current;
        if (!record || !dirtyRef.current) return record;
        if (savePromiseRef.current) return savePromiseRef.current;
        setSaveState('saving');
        const changeCounterAtStart = changeCounterRef.current;
        const promise = api.save(record).then(async (saved) => {
            const changedDuringRequest = changeCounterRef.current !== changeCounterAtStart;
            if (changedDuringRequest) {
                const local = currentRef.current;
                const merged = local ? { ...saved, data: clone(local.data), has_changes_since_latest_pdf: local.has_changes_since_latest_pdf } : saved;
                dirtyRef.current = true;
                currentRef.current = merged;
                setCurrent(merged);
                setRecords((items) => items.map((item) => item.id === saved.id ? saved : item));
                setSaveState('idle');
                setMessage(null); return merged;
            } else {
                dirtyRef.current = false; currentRef.current = saved; setCurrent(saved); setRecords((items) => items.map((item) => item.id === saved.id ? saved : item));
                setSaveState('saved'); await recoveryDrafts.remove(saved.id);
                setMessage(null); return saved;
            }
        }).catch((error) => {
            if (error instanceof ApiError && error.status === 409) {
                setConflict({ serverRevision: error.problem.server_revision || record.revision, serverData: error.problem.server_data || {}, serverSavedAt: error.problem.server_saved_at });
                setSaveState('error'); return null;
            }
            setSaveState(navigator.onLine ? 'error' : 'offline');
            setMessage({ intent: 'error', text: error instanceof ApiError && error.problem.errors ? Object.values(error.problem.errors).flat().join(' ') : (error instanceof Error ? error.message : 'Autosave failed.') });
            return null;
        }).finally(() => { savePromiseRef.current = null; });
        savePromiseRef.current = promise; return promise;
    }, [api]);

    const flushSaves = useCallback(async (): Promise<FormRecord | null> => {
        let saved = currentRef.current;
        do {
            saved = await saveNow();
            if (!saved) return null;
        } while (dirtyRef.current);

        return saved;
    }, [saveNow]);

    useEffect(() => {
        if (!current || !dirtyRef.current) return;
        const timer = window.setTimeout(() => void saveNow(), 1000);
        return () => window.clearTimeout(timer);
    }, [current?.data, saveNow]);

    useEffect(() => {
        const online = () => { if (dirtyRef.current) void saveNow(); };
        const visibility = () => { if (document.visibilityState === 'hidden' && dirtyRef.current) void saveNow(); };
        const pagehide = () => { if (dirtyRef.current) void saveNow(); };
        window.addEventListener('online', online); document.addEventListener('visibilitychange', visibility); window.addEventListener('pagehide', pagehide);
        return () => { window.removeEventListener('online', online); document.removeEventListener('visibilitychange', visibility); window.removeEventListener('pagehide', pagehide); };
    }, [saveNow]);

    const generate = async () => {
        let record = currentRef.current;
        if (!record) return;
        if (dirtyRef.current) record = await flushSaves();
        if (!record) return;
        setBusy(true); setIsGenerating(true); setMessage({ intent: 'info', text: 'Generating and validating the controlled PDF…' });
        try {
            const document = await api.generate(record.id);
            const refreshed = await api.show(record.id);
            currentRef.current = refreshed; dirtyRef.current = false; setCurrent(refreshed); setRecords((items) => items.map((item) => item.id === refreshed.id ? refreshed : item));
            setActiveSection('documents'); setMessage({ intent: 'success', text: `PDF version ${document.version_number} is ready to view, print, or download.` }); setPreview(document);
        } catch (error) {
            if (error instanceof ApiError && error.problem.errors) {
                const firstField = Object.keys(error.problem.errors)[0] || '';
                if (firstField.startsWith('vehicle_mileage')) setActiveSection('mileage');
                else if (firstField.startsWith('labor')) setActiveSection('labor');
                else if (firstField.startsWith('certification')) setActiveSection('certification');
                else if (firstField.startsWith('equipment')) setActiveSection('equipment');
                else if (firstField.startsWith('materials')) setActiveSection('materials');
                else if (firstField.startsWith('team_members')) setActiveSection('team');
                else setActiveSection('overview');
            }
            const text = error instanceof ApiError && error.problem.errors ? Object.values(error.problem.errors).flat().join(' ') : (error instanceof Error ? error.message : 'PDF generation failed.');
            setMessage({ intent: 'error', text });
        } finally { setBusy(false); setIsGenerating(false); }
    };

    const removeCurrent = async () => {
        const record = currentRef.current;
        if (!record || record.latest_pdf_version || !window.confirm(`Delete draft “${record.title}”? This cannot be undone.`)) return;
        await api.remove(record.id); await recoveryDrafts.remove(record.id); setCurrent(null); await refreshLibrary();
    };

    const applyRecovery = () => {
        if (!recovery || !current) return;
        const restored = { ...current, data: recovery.data };
        currentRef.current = restored; setCurrent(restored); dirtyRef.current = true; setRecovery(null); setSaveState('idle');
    };

    const loadServerConflict = async () => {
        if (!current) return;
        const server = await api.show(current.id); currentRef.current = server; setCurrent(server); dirtyRef.current = false; setConflict(null); setSaveState('saved'); await recoveryDrafts.remove(current.id);
    };

    if (busy && definitions.length === 0) return <FluentProvider theme={webLightTheme}><div className="of-loading"><Spinner size="large" label="Loading Operational Forms…" /></div></FluentProvider>;

    return (
        <FluentProvider theme={{ ...webLightTheme, fontFamilyBase: 'Segoe UI, system-ui, sans-serif' }}>
            <div className="of-app">
                <header className="of-topbar">
                    <div className="of-brand"><ShieldCheck size={22} /><span>MBFD</span><span className="of-brand-divider" />Operational Forms</div>
                    <div className="of-topbar-actions"><a className="of-hub-home" href="/" aria-label="MBFD Hub home" title="Return to MBFD Hub home"><Home size={19} /><span>Hub home</span></a><div className="of-employee"><span>{bootstrap.employee.name}</span><small>{bootstrap.employee.rank || 'Employee'} · ID {bootstrap.employee.employee_id}</small></div></div>
                </header>
                <div className="of-layout">
                    <nav className="of-rail" aria-label="Forms navigation">
                        <button className={!current ? 'active' : ''} onClick={() => setCurrent(null)}><Library size={19} /><span>Library</span></button>
                        {current && <button className="active"><FilePenLine size={19} /><span>Editor</span></button>}
                        <a href="/employee/dashboard"><Home size={19} /><span>Portal</span></a>
                    </nav>
                    <main className="of-main">
                        {message && <MessageBar intent={message.intent}><MessageBarBody>{message.text}</MessageBarBody></MessageBar>}
                        {!current ? (
                            <LibraryView definitions={definitions} records={records} busy={busy} createRecord={createRecord} uploadFile={uploadFile} openRecord={openRecord} refresh={refreshLibrary} openDocument={openDocument} guideUrl={bootstrap.endpoints.guide} />
                        ) : (
                            <EditorView
                                record={current} definition={definitions.find((item) => item.form_type === current.form_type)}
                                 saveState={saveState} activeSection={activeSection} setActiveSection={setActiveSection} isGenerating={isGenerating}
                                 update={updateCurrent} save={() => void saveNow()} generate={() => void generate()} remove={() => void removeCurrent()}
                                 back={() => { void flushSaves().then((saved) => { if (saved) setCurrent(null); }); }} preview={setPreview} searchEmployees={api.searchEmployees}
                                 applyFrocImport={applyFrocImport} undoFrocImport={undoFrocImport}
                            />
                        )}
                    </main>
                </div>
            </div>
            {preview && <Suspense fallback={<div className="of-preview-loading"><Spinner label="Loading PDF viewer…" /></div>}><PdfPreview url={preview.preview_url} name={preview.display_name} onClose={() => setPreview(null)} /></Suspense>}
            <Dialog open={Boolean(recovery)}><DialogSurface><DialogBody><DialogTitle>Unsynced local recovery copy found</DialogTitle><DialogContent>A browser recovery copy from {stamp(recovery?.savedAt)} differs from the server. Restore it into the editor or keep the server copy.</DialogContent><DialogActions><Button onClick={() => { if (current) void recoveryDrafts.remove(current.id); setRecovery(null); }}>Keep server copy</Button><Button appearance="primary" onClick={applyRecovery}>Restore local copy</Button></DialogActions></DialogBody></DialogSurface></Dialog>
            <Dialog open={Boolean(conflict)}><DialogSurface><DialogBody><DialogTitle>Newer server revision detected</DialogTitle><DialogContent><p>The server saved revision {conflict?.serverRevision} at {stamp(conflict?.serverSavedAt)}. Your local edits have not been overwritten.</p><p className="of-diff-summary">Local fields: {current ? Object.keys(current.data).join(', ') : '—'}<br />Server fields: {conflict ? Object.keys(conflict.serverData).join(', ') : '—'}</p></DialogContent><DialogActions><Button icon={<Copy size={16} />} onClick={() => navigator.clipboard.writeText(JSON.stringify(current?.data || {}, null, 2))}>Copy local JSON</Button><Button appearance="primary" onClick={() => void loadServerConflict()}>Load server copy</Button></DialogActions></DialogBody></DialogSurface></Dialog>
        </FluentProvider>
    );
}

function LibraryView({ definitions, records, busy, createRecord, uploadFile, openRecord, refresh, openDocument, guideUrl }: {
    definitions: FormDefinition[]; records: FormRecord[]; busy: boolean;
    createRecord: (type: EditableFormType) => Promise<void>;
    uploadFile: (name: string, file: File) => Promise<void>;
    openRecord: (record: FormRecord) => void; refresh: () => void; openDocument: (doc: FormDocument) => void; guideUrl: string;
}) {
    return <div className="of-library">
        <div className="of-page-heading"><div><p className="of-eyebrow">Controlled records workspace</p><h1>Operational Forms</h1><p>Start, resume, generate, or submit incident and reimbursement records.</p></div><div className="of-heading-actions"><Button as="a" href={guideUrl} target="_blank" icon={<BookOpen size={16} />}>User guide</Button><Button icon={<RefreshCw size={16} />} onClick={refresh} disabled={busy}>Refresh</Button></div></div>
        <section aria-labelledby="start-form"><h2 id="start-form">Start a form</h2><div className="of-form-cards">
            {definitions.map((definition) => <article className="of-form-card" key={definition.form_type}><div className="of-form-card-icon">{definition.form_type === 'ics_214' ? <ClipboardList /> : <FileCheck2 />}</div><div><Badge appearance="outline">Version {definition.form_version}</Badge><h3>{definition.display_name}</h3><p>{definition.form_type === 'ics_214' ? 'Document unit resources and chronological incident activity.' : 'Capture firefighter labor, equipment, mileage, materials, and certification—with optional AI-assisted note import.'}</p></div><Button appearance="primary" icon={<FilePlus2 size={17} />} onClick={() => void createRecord(definition.form_type)}>Create form</Button></article>)}
        </div></section>
        <FileSubmissionCard upload={uploadFile} />
        <section aria-labelledby="recent-records"><div className="of-section-heading"><h2 id="recent-records">Recent records</h2><span>{records.length} record{records.length === 1 ? '' : 's'}</span></div>
            <div className="of-table-wrap"><table className="of-record-table"><thead><tr><th>Record</th><th>Status</th><th>Last saved</th><th>Document</th><th><span className="sr-only">Open</span></th></tr></thead><tbody>
                {records.length === 0 && <tr><td colSpan={5} className="of-empty">No records yet. Start with one of the controlled forms above.</td></tr>}
                {records.map((record) => {
                    const document = latestDocument(record);
                    const open = () => record.form_type === 'uploaded_file' && document ? openDocument(document) : openRecord(record);
                    return <tr key={record.id} onDoubleClick={open}><td data-label="Record"><strong>{record.title}</strong><small>{typeLabel(record.form_type)} · rev {record.revision}</small></td><td data-label="Status"><StatusBadge status={record.status} /></td><td data-label="Last saved">{stamp(record.last_autosaved_at)}</td><td data-label="Document">{document ? <Button appearance="subtle" icon={<Eye size={16} />} aria-label={`Open document for ${record.title}`} onClick={() => openDocument(document)}>{record.form_type === 'uploaded_file' ? 'Open file' : `PDF v${document.version_number}`}</Button> : 'Not generated'}</td><td data-label="Open"><Button appearance="subtle" icon={<ChevronRight size={17} />} aria-label={`Open ${record.title}`} onClick={open} /></td></tr>;
                })}
            </tbody></table></div>
        </section>
    </div>;
}

function FileSubmissionCard({ upload }: { upload: (name: string, file: File) => Promise<void> }) {
    const [name, setName] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [working, setWorking] = useState(false);
    const [error, setError] = useState('');
    const input = useRef<HTMLInputElement | null>(null);

    const submit = async () => {
        if (!file || !name.trim()) return;
        setWorking(true); setError('');
        try {
            await upload(name.trim(), file);
            setName(''); setFile(null);
            if (input.current) input.current.value = '';
        } catch (caught) {
            setError(caught instanceof ApiError && caught.problem.errors
                ? Object.values(caught.problem.errors).flat().join(' ')
                : (caught instanceof Error ? caught.message : 'The file could not be submitted.'));
        } finally {
            setWorking(false);
        }
    };

    return <section aria-labelledby="submit-file">
        <h2 id="submit-file">Submit an existing file</h2>
        <article className="of-upload-card">
            <div className="of-upload-icon"><FileUp size={22} /></div>
            <div className="of-upload-copy"><h3>Send a completed file to Forms administration</h3><p>Name and upload any file type. It is stored privately and appears in Recent records and the Admin Forms page as completed.</p></div>
            <div className="of-upload-controls">
                <Field label="File name"><Input value={name} maxLength={200} placeholder="Example: CMD1 event notes" onChange={(_, data) => setName(data.value)} /></Field>
                <Field label="File" hint="Any file type, up to 50 MB."><label className="of-file-picker of-upload-picker"><FileArchive size={18} /><span>{file ? file.name : 'Choose file'}</span><input ref={input} type="file" onChange={(event) => setFile(event.target.files?.[0] || null)} /></label></Field>
                <Button appearance="primary" icon={working ? <LoaderCircle className="spin" size={17} /> : <FileUp size={17} />} disabled={working || !file || !name.trim()} onClick={() => void submit()}>{working ? 'Submitting…' : 'Submit completed file'}</Button>
            </div>
            {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        </article>
    </section>;
}

function FrocImportPanel({ apply, undo, review }: {
    apply: (payload: FormData) => Promise<FrocImportSummary>;
    undo: (importId: string) => Promise<void>;
    review: (summary: FrocImportSummary) => void;
}) {
    const [unitId, setUnitId] = useState('');
    const [notes, setNotes] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [summary, setSummary] = useState<FrocImportSummary | null>(null);
    const [working, setWorking] = useState(false);
    const [error, setError] = useState('');
    const idempotencyKey = useRef(crypto.randomUUID());

    const run = async () => {
        setWorking(true); setError('');
        try {
            const payload = new FormData(); payload.append('unit_id', unitId.trim());
            if (notes.trim()) payload.append('notes', notes);
            if (file) payload.append('notes_file', file);
            payload.append('idempotency_key', idempotencyKey.current);
            setSummary(await apply(payload));
        } catch (caught) {
            setError(caught instanceof ApiError && caught.problem.errors ? Object.values(caught.problem.errors).flat().join(' ') : (caught instanceof Error ? caught.message : 'The notes could not be analyzed.'));
        } finally { setWorking(false); }
    };

    const reset = () => {
        setUnitId(''); setNotes(''); setFile(null); setError(''); setSummary(null);
        idempotencyKey.current = crypto.randomUUID();
    };

    return <details className="of-import">
        <summary><Bot size={19} /><span><strong>Optional: Import activity notes with AI</strong><small>Upload a WhatsApp text export or paste unit notes. The assistant will add supported details to this draft. All fields remain editable.</small></span></summary>
        <div className="of-import-body">
            <div className="of-import-fields">
                <Field label="Unit designation" hint="Required—for example R6, JHAT, Gator 1, or Detail Medic 2."><Input value={unitId} onChange={(_, data) => setUnitId(data.value)} /></Field>
                <Field label="WhatsApp export or text file" hint="Maximum 2 MB upload; extracted text is limited to 512 KB and is not stored."><div className="of-file-row"><label className="of-file-picker"><FileArchive size={18} /><span>{file ? file.name : 'Choose .zip or .txt file'}</span><input type="file" accept=".zip,.txt,text/plain,application/zip" onChange={(event) => setFile(event.target.files?.[0] || null)} /></label>{file && <Button appearance="subtle" onClick={() => setFile(null)}>Remove file</Button>}</div>{file && <small className="of-source-size">{(file.size / 1024).toFixed(1)} KB selected</small>}</Field>
                <Field className="of-import-notes" label="Paste activity notes" hint="Paste copied chat messages, field notes, or unit logs. Source content is processed transiently and never saved with the form."><Textarea value={notes} onChange={(_, data) => setNotes(data.value)} resize="vertical" /></Field>
            </div>
            {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
            <div className="of-import-actions"><Button onClick={reset} disabled={working}>Cancel / reset</Button><Button appearance="primary" icon={working ? <LoaderCircle className="spin" size={17} /> : <Sparkles size={17} />} disabled={working || !unitId.trim() || (!notes.trim() && !file)} onClick={() => void run()}>{working ? 'Analyzing and adding…' : 'Analyze and add to form'}</Button></div>
            {summary && <div className="of-import-success" role="status">
                <Check size={20} /><div><strong>Activity notes added</strong><p>Added {summary.applied_fields.length} field{summary.applied_fields.length === 1 ? '' : 's'}, {summary.updated_mileage_rows.length} mileage row{summary.updated_mileage_rows.length === 1 ? '' : 's'}, and {summary.appended_labor_rows.length} labor activit{summary.appended_labor_rows.length === 1 ? 'y' : 'ies'}.{summary.estimated_fields.length > 0 ? ` ${summary.estimated_fields.length} end time${summary.estimated_fields.length === 1 ? ' was' : 's were'} estimated.` : ''}</p>{summary.fallback_used && <small>Rules-based extraction was used because the configured AI service was unavailable.</small>}{summary.skipped_conflicts.length > 0 && <small>{summary.skipped_conflicts.length} existing value{summary.skipped_conflicts.length === 1 ? ' was' : 's were'} preserved.</small>}</div>
                <div className="of-import-success-actions"><Button onClick={() => review(summary)}>Review imported fields</Button><Button onClick={() => void undo(summary.id).then(() => setSummary(null))}>Undo this import</Button><Button appearance="subtle" onClick={() => setSummary(null)}>Dismiss</Button></div>
            </div>}
        </div>
    </details>;
}

function EditorView({ record, definition, saveState, activeSection, setActiveSection, isGenerating, update, save, generate, remove, back, preview, searchEmployees, applyFrocImport, undoFrocImport }: {
    record: FormRecord; definition?: FormDefinition; saveState: SaveState; activeSection: string; setActiveSection: (value: string) => void; isGenerating: boolean;
    update: (mutator: (data: Record<string, any>) => void) => void; save: () => void; generate: () => void; remove: () => void; back: () => void; preview: (doc: FormDocument) => void; searchEmployees: (query: string) => Promise<EmployeeSuggestion[]>;
    applyFrocImport: (payload: FormData) => Promise<FrocImportSummary>; undoFrocImport: (importId: string) => Promise<void>;
}) {
    const document = latestDocument(record);
    const sections = record.form_type === 'ics_214' ? [['overview', 'Operational period'], ['resources', 'Resources'], ['activities', 'Activity log'], ['prepared', 'Prepared by'], ['documents', 'PDF versions']] : [['overview', 'General information'], ['team', 'Team members'], ['labor', 'Labor'], ['equipment', 'Equipment'], ['mileage', 'Mileage'], ['materials', 'Materials'], ['certification', 'Certification'], ['documents', 'PDF versions']];
    return <div className="of-editor">
        <div className="of-commandbar"><Button appearance="subtle" icon={<ArrowLeft size={17} />} aria-label="Forms library" onClick={back}>Forms library</Button><span className="of-command-divider" /><SaveIndicator state={saveState} savedAt={record.last_autosaved_at} /><span className="of-toolbar-spacer" />{document && <Button icon={<Eye size={17} />} aria-label="View latest PDF" onClick={() => preview(document)}>View PDF</Button>}<Button icon={<Save size={17} />} aria-label="Save now" onClick={save} disabled={isGenerating}>Save now</Button><Button appearance="primary" icon={isGenerating ? <LoaderCircle className="spin" size={17} /> : <FileCheck2 size={17} />} aria-label="Generate PDF" onClick={generate} disabled={isGenerating}>{isGenerating ? 'Generating…' : 'Generate PDF'}</Button>{!record.latest_pdf_version && <Button appearance="subtle" icon={<Trash2 size={17} />} aria-label="Delete draft" onClick={remove} disabled={isGenerating}>Delete draft</Button>}</div>
        <div className="of-record-spine"><div><Badge appearance="outline">{typeLabel(record.form_type)} · v{record.form_version}</Badge><h1>{record.title}</h1><p>Record {record.id.slice(-8).toUpperCase()} · revision {record.revision}</p></div><div className="of-spine-status"><StatusBadge status={record.status} />{record.latest_pdf_version && <Badge color={record.has_changes_since_latest_pdf ? 'warning' : 'success'}>{record.has_changes_since_latest_pdf ? 'Changed since PDF' : `PDF v${record.latest_pdf_version} current`}</Badge>}</div></div>
        {document && <div className="of-document-ready" role="status"><div className="of-document-ready-icon"><FileCheck2 size={20} /></div><div><strong>Latest controlled PDF</strong><span>Version {document.version_number} · {document.display_name}</span><small>Generated {stamp(document.created_at)} from revision {document.source_revision}</small></div><div className="of-document-ready-actions"><Button appearance="primary" icon={<Eye size={16} />} aria-label="View latest PDF" onClick={() => preview(document)}>View / print</Button><Button as="a" href={document.download_url} icon={<Download size={16} />} aria-label="Download latest PDF">Download</Button></div></div>}
        <div className="of-editor-grid"><aside className="of-context-nav" aria-label="Form sections">{sections.map(([id, label]) => <button key={id} className={activeSection === id ? 'active' : ''} onClick={() => setActiveSection(id)}>{label}</button>)}</aside><div className="of-form-canvas" onBlur={save}>
            {record.form_type === 'ics_214' ? <IcsEditor record={record} definition={definition} section={activeSection} update={update} /> : <FrocEditor record={record} definition={definition} section={activeSection} update={update} searchEmployees={searchEmployees} applyImport={applyFrocImport} undoImport={undoFrocImport} reviewImport={(summary) => setActiveSection(summary.updated_mileage_rows.length > 0 ? 'mileage' : summary.appended_labor_rows.length > 0 ? 'labor' : 'overview')} />}
            {activeSection === 'documents' && <Documents record={record} preview={preview} />}
        </div></div>
    </div>;
}

function IcsEditor({ record, definition, section, update }: EditorProps) {
    const data = record.data;
    if (section === 'overview') return <FormSection number="1–2" title="Incident and operational period" help="Use local date and 24-hour time."><FieldGrid><TextField label="Incident name" value={data.incident?.name} set={(v) => update((d) => setPath(d, ['incident', 'name'], v))} /><TextField label="Unit name / designators" value={data.unit?.name} set={(v) => update((d) => setPath(d, ['unit', 'name'], v))} /><TextField type="date" label="From date" value={data.incident?.date_from} set={(v) => update((d) => setPath(d, ['incident', 'date_from'], v))} /><TextField type="time" label="From time" value={data.incident?.time_from} set={(v) => update((d) => setPath(d, ['incident', 'time_from'], v))} /><TextField type="date" label="To date" value={data.incident?.date_to} set={(v) => update((d) => setPath(d, ['incident', 'date_to'], v))} /><TextField type="time" label="To time" value={data.incident?.time_to} set={(v) => update((d) => setPath(d, ['incident', 'time_to'], v))} /><TextField label="ICS position" value={data.unit?.ics_position} set={(v) => update((d) => setPath(d, ['unit', 'ics_position'], v))} /><TextField label="Home agency / unit" value={data.unit?.home_agency_unit} set={(v) => update((d) => setPath(d, ['unit', 'home_agency_unit'], v))} /></FieldGrid></FormSection>;
    if (section === 'resources') return <RepeatingTable title="3. Resources assigned" rows={data.resources || []} fields={[['name', 'Name'], ['ics_position', 'ICS position'], ['home_agency_unit', 'Home agency / unit']]} capacity={definition?.capacities.resources || 8} onChange={(rows) => update((d) => { d.resources = rows; })} rowType="resources" />;
    if (section === 'activities') return <RepeatingTable title="4. Activity log" rows={data.activities || []} fields={[['date', 'Date', 'date'], ['time', 'Time', 'time'], ['notable_activity', 'Notable activities']]} capacity={definition?.capacities.activities || 24} onChange={(rows) => update((d) => { d.activities = rows; })} rowType="activities" wide="notable_activity" />;
    if (section === 'prepared') return <FormSection number="5" title="Prepared by"><FieldGrid><TextField label="Name" value={data.prepared_by?.name} set={(v) => update((d) => setPath(d, ['prepared_by', 'name'], v))} /><TextField label="Position / title" value={data.prepared_by?.position_title} set={(v) => update((d) => setPath(d, ['prepared_by', 'position_title'], v))} /><TextField label="Typed signature" value={data.prepared_by?.signature_text} set={(v) => update((d) => setPath(d, ['prepared_by', 'signature_text'], v))} /><TextField type="date" label="Date" value={data.prepared_by?.date} set={(v) => update((d) => setPath(d, ['prepared_by', 'date'], v))} /><TextField type="time" label="Time" value={data.prepared_by?.time} set={(v) => update((d) => setPath(d, ['prepared_by', 'time'], v))} /></FieldGrid></FormSection>;
    return null;
}

function FrocEditor({ record, definition, section, update, searchEmployees, applyImport, undoImport, reviewImport }: EditorProps & {
    searchEmployees: (query: string) => Promise<EmployeeSuggestion[]>;
    applyImport: (payload: FormData) => Promise<FrocImportSummary>;
    undoImport: (importId: string) => Promise<void>;
    reviewImport: (summary: FrocImportSummary) => void;
}) {
    const data = record.data; const cap = definition?.capacities || {};
    const options = definition?.field_options;
    if (section === 'overview') return <FormSection number="1" title="General information" help="Enter the event and reporting details directly. Importing activity notes is optional."><FieldGrid><TextField label="Event ID / event name" value={data.general_information?.event_id} set={(v) => update((d) => setPath(d, ['general_information', 'event_id'], v))} /><TextField label="Applicant name" value={data.general_information?.applicant_name} set={(v) => update((d) => setPath(d, ['general_information', 'applicant_name'], v))} /><TextField label="Department" value={data.general_information?.department} set={(v) => update((d) => setPath(d, ['general_information', 'department'], v))} /><TextField type="date" label="Report date" value={data.general_information?.date} set={(v) => update((d) => setPath(d, ['general_information', 'date'], v))} /></FieldGrid><FrocImportPanel apply={applyImport} undo={undoImport} review={reviewImport} /><Totals data={data} /></FormSection>;
    if (section === 'team') return <TeamMembersEditor rows={data.team_members || []} capacity={cap.team_members || 14} onChange={(rows) => update((d) => { d.team_members = rows; })} search={searchEmployees} />;
    if (section === 'labor') return <LaborEditor rows={data.labor || []} capacity={cap.labor || 13} onChange={(rows) => update((d) => { d.labor = rows; })} options={options} estimatedFields={record.import_metadata?.estimated_fields || []} />;
    if (section === 'equipment') return <RepeatingTable title="Equipment hours" rows={data.equipment_hours || []} fields={[['category', 'Cat.'], ['equipment_id', 'Equipment ID'], ['operator', 'Operator'], ['description', 'Description'], ['location', 'Location'], ['hours', 'Hours'], ['event_related', 'Event?', 'checkbox']]} capacity={cap.equipment_hours || 6} onChange={(rows) => update((d) => { d.equipment_hours = rows; })} rowType="equipment_hours" categories={options?.categories} />;
    if (section === 'mileage') return <RepeatingTable title="Vehicle mileage" rows={data.vehicle_mileage || []} fields={[['category', 'Cat.'], ['equipment_id', 'Vehicle ID'], ['operator', 'Operator'], ['destination', 'Destination'], ['start_odometer', 'Start odo.'], ['end_odometer', 'End odo.'], ['manual_miles', 'Corrected miles'], ['correction_reason', 'Correction reason'], ['event_related', 'Event?', 'checkbox']]} capacity={cap.vehicle_mileage || 2} onChange={(rows) => update((d) => { d.vehicle_mileage = rows; })} rowType="vehicle_mileage" categories={options?.categories} />;
    if (section === 'materials') return <RepeatingTable title="Materials and supplies" rows={data.materials || []} fields={[['category', 'Cat.'], ['item', 'Item'], ['quantity', 'Quantity'], ['cost', 'Cost'], ['justification', 'Justification'], ['receipt_reference', 'Receipt ref.'], ['from_stock', 'Stock?', 'checkbox']]} capacity={cap.materials || 7} onChange={(rows) => update((d) => { d.materials = rows; })} rowType="materials" categories={options?.categories} />;
    if (section === 'certification') return <><FormSection number="Certification" title="Employee and reviewer certification" help="The final employee signature, date, and confirmation are required. Page 2 and reviewer lines may remain blank when they do not apply."><FieldGrid><TextField label="Page 2 employee signature (optional)" value={data.certification?.page2_employee_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'page2_employee_signature_text'], v))} /><TextField label="Page 2 reviewer signature (optional)" value={data.certification?.page2_reviewer_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'page2_reviewer_signature_text'], v))} /><TextField label="Final employee signature" value={data.certification?.final_employee_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'final_employee_signature_text'], v))} /><TextField type="date" label="Employee signature date" value={data.certification?.final_employee_signature_date} set={(v) => update((d) => setPath(d, ['certification', 'final_employee_signature_date'], v))} /><TextField type="time" label="Employee signature time (optional)" value={data.certification?.final_employee_signature_time} set={(v) => update((d) => setPath(d, ['certification', 'final_employee_signature_time'], v))} /><TextField label="Final reviewer signature (optional)" value={data.certification?.final_reviewer_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'final_reviewer_signature_text'], v))} /><TextField type="date" label="Reviewer signature date (optional)" value={data.certification?.final_reviewer_signature_date} set={(v) => update((d) => setPath(d, ['certification', 'final_reviewer_signature_date'], v))} /><TextField type="time" label="Reviewer signature time (optional)" value={data.certification?.final_reviewer_signature_time} set={(v) => update((d) => setPath(d, ['certification', 'final_reviewer_signature_time'], v))} /></FieldGrid><label className="of-confirm"><input type="checkbox" checked={Boolean(data.certification?.confirmed)} onChange={(e) => update((d) => setPath(d, ['certification', 'confirmed'], e.target.checked))} /><span>I certify that this report is complete and accurate.</span></label></FormSection><NotesEditor values={data.additional_notes || []} capacity={cap.additional_notes || 28} onChange={(values) => update((d) => { d.additional_notes = values; })} /></>;
    return null;
}

interface EditorProps { record: FormRecord; definition?: FormDefinition; section: string; update: (mutator: (data: Record<string, any>) => void) => void }
function setPath(target: Record<string, any>, path: string[], value: any) {
    let cursor = target;
    path.slice(0, -1).forEach((key) => {
        if (!cursor[key] || Array.isArray(cursor[key])) cursor[key] = {};
        cursor = cursor[key];
    });
    cursor[path.at(-1)!] = value;
}
type InputType = 'text' | 'date' | 'time' | 'number' | 'email' | 'tel' | 'url';
function TextField({ label, value, set, type = 'text' }: { label: string; value?: any; set: (value: string) => void; type?: InputType }) { return <Field label={label}><Input type={type} value={value ?? ''} onChange={(_, data) => set(data.value)} /></Field>; }
function FieldGrid({ children }: { children: React.ReactNode }) { return <div className="of-field-grid">{children}</div>; }
function FormSection({ number, title, help, children }: { number: string; title: string; help?: string; children: React.ReactNode }) { return <section className="of-form-section"><div className="of-section-title"><span>{number}</span><div><h2>{title}</h2>{help && <p>{help}</p>}</div></div>{children}</section>; }

function CategorySelect({ value, set, label, options = ['A', 'B', 'N/A'] }: { value?: string; set: (value: string) => void; label: string; options?: string[] }) {
    return <select className="of-select" aria-label={label} value={value || ''} onChange={(event) => set(event.target.value)}><option value="">Select</option>{options.map((option) => <option key={option} value={option}>{option}</option>)}</select>;
}

function TeamMembersEditor({ rows, capacity, onChange, search }: { rows: Record<string, any>[]; capacity: number; onChange: (rows: Record<string, any>[]) => void; search: (query: string) => Promise<EmployeeSuggestion[]> }) {
    const edit = (index: number, key: string, value: string) => { const next = clone(rows); next[index][key] = value; onChange(next); };
    const select = (index: number, employee: EmployeeSuggestion) => { const next = clone(rows); next[index] = { ...next[index], employee_id: employee.employee_id, employee_name: employee.name }; onChange(next); };
    return <section className="of-form-section"><div className="of-section-heading"><div><h2>Team members</h2><p>Search the MBFD employee directory or type an outside member manually.</p></div><Button icon={<Plus size={16} />} onClick={() => rows.length < capacity && onChange([...rows, clone(EMPTY_ROWS.team_members)])} disabled={rows.length >= capacity}>Add member</Button></div>
        <div className="of-member-list">{rows.length === 0 && <div className="of-empty-panel"><UsersRound /><p>No team members entered.</p></div>}{rows.map((row, index) => <article className="of-member-row" key={index}><span className="of-row-number">{index + 1}</span><EmployeeLookup value={row.employee_id || ''} search={search} set={(value) => edit(index, 'employee_id', value)} choose={(employee) => select(index, employee)} /><Field label="Employee name" hint="Editable for manual or outside members."><Input value={row.employee_name || ''} onChange={(_, data) => edit(index, 'employee_name', data.value)} /></Field><Button appearance="subtle" icon={<Trash2 size={15} />} aria-label={`Remove team member ${index + 1}`} onClick={() => onChange(rows.filter((_, i) => i !== index))} /></article>)}</div>
    </section>;
}

function EmployeeLookup({ value, set, choose, search }: { value: string; set: (value: string) => void; choose: (employee: EmployeeSuggestion) => void; search: (query: string) => Promise<EmployeeSuggestion[]> }) {
    const [matches, setMatches] = useState<EmployeeSuggestion[]>([]);
    const [open, setOpen] = useState(false);
    useEffect(() => {
        if (value.trim().length < 2) { setMatches([]); return; }
        let active = true;
        const timer = window.setTimeout(() => { void search(value.trim()).then((results) => { if (active) { setMatches(results); setOpen(true); } }).catch(() => { if (active) setMatches([]); }); }, 220);
        return () => { active = false; window.clearTimeout(timer); };
    }, [value, search]);
    return <Field label="Employee ID or name" hint="Choose a match to auto-fill; free text remains allowed."><div className="of-lookup"><Input value={value} autoComplete="off" onFocus={() => setOpen(matches.length > 0)} onChange={(_, data) => set(data.value)} onBlur={() => window.setTimeout(() => setOpen(false), 120)} />{open && matches.length > 0 && <div className="of-lookup-menu" role="listbox">{matches.map((employee) => <button type="button" role="option" key={employee.employee_id} onMouseDown={(event) => event.preventDefault()} onClick={() => { choose(employee); setOpen(false); }}><strong>{employee.employee_id}</strong><span>{employee.name}</span><small>{employee.rank || 'Member'}</small></button>)}</div>}</div></Field>;
}

function LaborEditor({ rows, capacity, onChange, options, estimatedFields }: { rows: Record<string, any>[]; capacity: number; onChange: (rows: Record<string, any>[]) => void; options?: FormDefinition['field_options']; estimatedFields: string[] }) {
    const edit = (index: number, key: string, value: any) => { const next = clone(rows); next[index][key] = value; if (key === 'category') next[index].work_performed = ''; onChange(next); };
    const descriptions = (category: string) => options?.descriptions_by_category?.[category] || [];
    return <section className="of-form-section"><div className="of-section-heading"><div><h2>Labor activity</h2><p>{rows.length} of {capacity} controlled rows used · descriptions remain editable</p></div><Button icon={<Plus size={16} />} onClick={() => rows.length < capacity && onChange([...rows, clone(EMPTY_ROWS.labor)])} disabled={rows.length >= capacity}>Add activity</Button></div>
        <div className="of-labor-list">{rows.length === 0 && <div className="of-empty-panel"><Clock3 /><p>No labor activity entered. Add a row when activity begins.</p></div>}{rows.map((row, index) => { const listId = `froc-description-${index}`; const estimated = estimatedFields.includes(`labor.${index}.end`); return <article className="of-labor-row" key={index}><div className="of-labor-row-head"><span>Activity {index + 1}</span><label className="of-event-check"><input type="checkbox" checked={Boolean(row.event_related)} onChange={(event) => edit(index, 'event_related', event.target.checked)} /> Event related</label><Button appearance="subtle" icon={<Trash2 size={15} />} aria-label={`Remove labor activity ${index + 1}`} onClick={() => onChange(rows.filter((_, i) => i !== index))} /></div><div className="of-labor-fields"><Field label="Category"><CategorySelect value={row.category} set={(value) => edit(index, 'category', value)} label={`Category activity ${index + 1}`} options={options?.categories} /></Field><Field className="of-labor-description" label="Description of work performed" hint={row.category ? 'Choose a controlled option or enter a more specific professional description.' : 'Select a category first.'}><Input list={listId} disabled={!row.category} value={row.work_performed || ''} onChange={(_, data) => edit(index, 'work_performed', data.value)} /><datalist id={listId}>{descriptions(row.category).map((description) => <option value={description} key={description} />)}</datalist></Field><Field label="Work location / GPS"><Input value={row.location_gps || ''} onChange={(_, data) => edit(index, 'location_gps', data.value)} /></Field><Field label="Start"><Input type="time" value={row.start || ''} onChange={(_, data) => edit(index, 'start', data.value)} /></Field><Field label={estimated ? 'End · AI estimate' : 'End'} hint={estimated ? 'Estimated from the source timeline. Confirm or edit this time.' : undefined}><Input type="time" value={row.end || ''} onChange={(_, data) => edit(index, 'end', data.value)} /></Field></div><details className="of-correction"><summary>Calculated-hours correction (only if needed)</summary><div><Field label="Override hours"><Input inputMode="decimal" value={row.manual_override_hours || ''} onChange={(_, data) => edit(index, 'manual_override_hours', data.value)} /></Field><Field label="Required correction reason"><Textarea value={row.override_reason || ''} onChange={(_, data) => edit(index, 'override_reason', data.value)} /></Field></div></details></article>; })}</div>
    </section>;
}

function RepeatingTable({ title, rows, fields, capacity, onChange, rowType, wide, categories }: { title: string; rows: Record<string, any>[]; fields: [string, string, (InputType | 'checkbox')?][]; capacity: number; onChange: (rows: Record<string, any>[]) => void; rowType: string; wide?: string; categories?: string[] }) {
    const add = () => rows.length < capacity && onChange([...rows, clone(EMPTY_ROWS[rowType])]);
    const edit = (index: number, key: string, value: any) => { const next = clone(rows); next[index][key] = value; onChange(next); };
    const cell = (row: Record<string, any>, index: number, key: string, label: string, type?: InputType | 'checkbox') => {
        if (type === 'checkbox') return <input type="checkbox" checked={Boolean(row[key])} aria-label={`${label} row ${index + 1}`} onChange={(e) => edit(index, key, e.target.checked)} />;
        if (key === 'category') return <CategorySelect value={row[key]} set={(value) => edit(index, key, value)} label={`${label} row ${index + 1}`} options={categories} />;
        if (key.includes('performed') || key.includes('reason') || key === 'justification') return <Textarea aria-label={`${label} row ${index + 1}`} value={row[key] || ''} onChange={(_, data) => edit(index, key, data.value)} resize="vertical" />;
        const incompleteMileage = rowType === 'vehicle_mileage'
            && ((key === 'end_odometer' && row.start_odometer && !row.end_odometer) || (key === 'start_odometer' && row.end_odometer && !row.start_odometer));
        return <><Input aria-label={`${label} row ${index + 1}`} type={type || 'text'} value={row[key] ?? ''} onChange={(_, data) => edit(index, key, data.value)} />{incompleteMileage && <small className="of-field-note">Pending — complete the odometer pair before PDF generation.</small>}</>;
    };
    return <section className="of-form-section"><div className="of-section-heading"><div><h2>{title}</h2><p>{rows.length} of {capacity} controlled rows used</p></div><Button icon={<Plus size={16} />} onClick={add} disabled={rows.length >= capacity}>Add row</Button></div><div className="of-table-wrap"><table className="of-edit-table"><thead><tr><th>#</th>{fields.map(([key, label]) => <th key={key} className={key === wide ? 'wide' : ''}>{label}</th>)}<th /></tr></thead><tbody>{rows.length === 0 && <tr><td colSpan={fields.length + 2} className="of-empty">No entries. Add a row when activity begins.</td></tr>}{rows.map((row, index) => <tr key={index}><td data-label="Row">{index + 1}</td>{fields.map(([key, label, type]) => <td key={key} data-label={label}>{cell(row, index, key, label, type)}</td>)}<td data-label="Actions"><Button appearance="subtle" icon={<Trash2 size={15} />} aria-label={`Remove row ${index + 1}`} onClick={() => onChange(rows.filter((_, i) => i !== index))} /></td></tr>)}</tbody></table></div></section>;
}

function NotesEditor({ values, capacity, onChange }: { values: string[]; capacity: number; onChange: (values: string[]) => void }) { return <section className="of-form-section"><div className="of-section-heading"><div><h2>Additional notes</h2><p>{values.length} of {capacity} lines used</p></div><Button icon={<Plus size={16} />} disabled={values.length >= capacity} onClick={() => onChange([...values, ''])}>Add note</Button></div>{values.map((value, index) => <div className="of-note" key={index}><span>{index + 1}</span><Textarea value={value} onChange={(_, d) => { const next = [...values]; next[index] = d.value; onChange(next); }} /><Button appearance="subtle" icon={<Trash2 size={15} />} onClick={() => onChange(values.filter((_, i) => i !== index))} /></div>)}</section>; }

function Totals({ data }: { data: Record<string, any> }) {
    const sum = (rows: any[], key: string, event: boolean) => rows.filter((r) => Boolean(r.event_related) === event).reduce((total, row) => total + Number(row[key] || 0), 0);
    const mileage = (event: boolean) => {
        const rows = (data.vehicle_mileage || []).filter((row: any) => Boolean(row.event_related) === event);
        let total = 0; let pending = false;
        rows.forEach((row: any) => {
            if (row.manual_miles !== '' && row.manual_miles != null) { total += Math.max(0, Number(row.manual_miles)); return; }
            if (row.start_odometer === '' || row.start_odometer == null || row.end_odometer === '' || row.end_odometer == null) { if (row.start_odometer || row.end_odometer) pending = true; return; }
            const difference = Number(row.end_odometer) - Number(row.start_odometer);
            if (difference >= 0) total += difference; else pending = true;
        });
        return pending ? (total > 0 ? `${total.toFixed(2)} mi + pending` : 'Pending') : `${total.toFixed(2)} mi`;
    };
    const values = data.calculated_totals || {};
    return <div className="of-totals" aria-label="Authoritative calculated totals"><div><small>Event labor</small><strong>{values.p2_total_event_hours ?? '0.00'} h</strong></div><div><small>Non-event labor</small><strong>{values.p2_total_non_event_hours ?? '0.00'} h</strong></div><div><small>Event equipment</small><strong>{values.p3_equipment_hours_total_event ?? sum(data.equipment_hours || [], 'hours', true).toFixed(2)} h</strong></div><div><small>Non-event equipment</small><strong>{values.p3_equipment_hours_total_non_event ?? sum(data.equipment_hours || [], 'hours', false).toFixed(2)} h</strong></div><div><small>Event mileage</small><strong>{mileage(true)}</strong></div><div><small>Non-event mileage</small><strong>{mileage(false)}</strong></div></div>;
}

function Documents({ record, preview }: { record: FormRecord; preview: (doc: FormDocument) => void }) { return <section className="of-form-section"><div className="of-section-title"><span><FileCheck2 size={18} /></span><div><h2>Generated PDF versions</h2><p>Immutable private files created from frozen source revisions.</p></div></div><div className="of-document-list">{record.documents.length === 0 && <div className="of-empty-panel"><FilePlus2 /><p>No PDF has been generated from this record.</p></div>}{[...record.documents].sort((a, b) => b.version_number - a.version_number).map((doc) => <article key={doc.id}><div><strong>Version {doc.version_number}</strong><span>{doc.display_name}</span><small>Source revision {doc.source_revision} · {stamp(doc.created_at)}</small></div><Button icon={<Printer size={16} />} onClick={() => preview(doc)}>Preview / print</Button><Button as="a" href={doc.download_url} icon={<Download size={16} />}>Download</Button></article>)}</div></section>; }
function StatusBadge({ status }: { status: string }) { return <Badge color={status === 'completed' ? 'success' : 'informative'} icon={status === 'completed' ? <Check size={12} /> : <FilePenLine size={12} />}>{status === 'completed' ? 'Completed' : 'Draft'}</Badge>; }
function SaveIndicator({ state, savedAt }: { state: SaveState; savedAt: string | null }) { const map = { idle: [Cloud, 'Changes pending'], saving: [LoaderCircle, 'Saving…'], saved: [Check, `Saved ${stamp(savedAt)}`], offline: [WifiOff, 'Offline — recovery copy stored'], error: [AlertTriangle, 'Save needs attention'] } as const; const [Icon, label] = map[state]; return <div className={`of-save-state ${state}`}><Icon size={16} className={state === 'saving' ? 'spin' : ''} /><span>{label}</span></div>; }
