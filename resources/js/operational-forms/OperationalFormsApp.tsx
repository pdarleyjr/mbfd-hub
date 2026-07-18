import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle,
    Field, FluentProvider, Input, MessageBar, MessageBarBody, Spinner, Textarea, webLightTheme,
} from '@fluentui/react-components';
import {
    AlertTriangle, ArrowLeft, Check, ChevronRight, ClipboardList, Clock3, Cloud, Copy, Download,
    FileCheck2, FilePenLine, FilePlus2, Home, Library, LoaderCircle, Plus, Printer, RefreshCw, Save,
    ShieldCheck, Trash2, UsersRound, WifiOff,
} from 'lucide-react';
import { ApiError, createApi } from './api';
import { recoveryDrafts, type RecoveryDraft } from './draftsDb';
import type { BootstrapData, FormDefinition, FormDocument, FormRecord, FormType } from './types';

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
function typeLabel(type: FormType) { return type === 'ics_214' ? 'ICS 214' : 'F-ROC Daily Activity Report'; }

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

    const createRecord = async (formType: FormType) => {
        setBusy(true);
        try {
            const now = new Date();
            const created = await api.create(formType, `${typeLabel(formType)} — ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(now)}`);
            setRecords((items) => [created, ...items]); currentRef.current = created; setCurrent(created); dirtyRef.current = false; setSaveState('saved');
        } catch (error) { setMessage({ intent: 'error', text: error instanceof Error ? error.message : 'The record could not be created.' }); }
        finally { setBusy(false); }
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
        setBusy(true); setMessage({ intent: 'info', text: 'Generating and validating the controlled PDF…' });
        try {
            const document = await api.generate(record.id);
            const refreshed = await api.show(record.id);
            setCurrent(refreshed); setRecords((items) => items.map((item) => item.id === refreshed.id ? refreshed : item));
            setMessage({ intent: 'success', text: `PDF version ${document.version_number} generated and stored privately.` }); setPreview(document);
        } catch (error) {
            const text = error instanceof ApiError && error.problem.errors ? Object.values(error.problem.errors).flat().join(' ') : (error instanceof Error ? error.message : 'PDF generation failed.');
            setMessage({ intent: 'error', text });
        } finally { setBusy(false); }
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
                    <div className="of-employee"><span>{bootstrap.employee.name}</span><small>{bootstrap.employee.rank || 'Employee'} · ID {bootstrap.employee.employee_id}</small></div>
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
                            <LibraryView definitions={definitions} records={records} busy={busy} createRecord={createRecord} openRecord={openRecord} refresh={refreshLibrary} />
                        ) : (
                            <EditorView
                                record={current} definition={definitions.find((item) => item.form_type === current.form_type)}
                                saveState={saveState} activeSection={activeSection} setActiveSection={setActiveSection}
                                update={updateCurrent} save={() => void saveNow()} generate={() => void generate()} remove={() => void removeCurrent()}
                                back={() => { void flushSaves().then((saved) => { if (saved) setCurrent(null); }); }} preview={setPreview}
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

function LibraryView({ definitions, records, busy, createRecord, openRecord, refresh }: {
    definitions: FormDefinition[]; records: FormRecord[]; busy: boolean;
    createRecord: (type: FormType) => void; openRecord: (record: FormRecord) => void; refresh: () => void;
}) {
    return <div className="of-library">
        <div className="of-page-heading"><div><p className="of-eyebrow">Controlled records workspace</p><h1>Operational Forms</h1><p>Start, resume, and generate official incident and reimbursement records.</p></div><Button icon={<RefreshCw size={16} />} onClick={refresh} disabled={busy}>Refresh</Button></div>
        <section aria-labelledby="start-form"><h2 id="start-form">Start a form</h2><div className="of-form-cards">
            {definitions.map((definition) => <article className="of-form-card" key={definition.form_type}><div className="of-form-card-icon">{definition.form_type === 'ics_214' ? <ClipboardList /> : <FileCheck2 />}</div><div><Badge appearance="outline">Version {definition.form_version}</Badge><h3>{definition.display_name}</h3><p>{definition.form_type === 'ics_214' ? 'Document unit resources and chronological incident activity.' : 'Capture firefighter labor, equipment, mileage, materials, and certification.'}</p></div><Button appearance="primary" icon={<FilePlus2 size={17} />} onClick={() => createRecord(definition.form_type)}>Create form</Button></article>)}
        </div></section>
        <section aria-labelledby="recent-records"><div className="of-section-heading"><h2 id="recent-records">Recent records</h2><span>{records.length} record{records.length === 1 ? '' : 's'}</span></div>
            <div className="of-table-wrap"><table className="of-record-table"><thead><tr><th>Record</th><th>Status</th><th>Last saved</th><th>PDF</th><th><span className="sr-only">Open</span></th></tr></thead><tbody>
                {records.length === 0 && <tr><td colSpan={5} className="of-empty">No records yet. Start with one of the controlled forms above.</td></tr>}
                {records.map((record) => <tr key={record.id} onDoubleClick={() => openRecord(record)}><td data-label="Record"><strong>{record.title}</strong><small>{typeLabel(record.form_type)} · rev {record.revision}</small></td><td data-label="Status"><StatusBadge status={record.status} /></td><td data-label="Last saved">{stamp(record.last_autosaved_at)}</td><td data-label="PDF">{record.latest_pdf_version ? `Version ${record.latest_pdf_version}` : 'Not generated'}</td><td data-label="Open"><Button appearance="subtle" icon={<ChevronRight size={17} />} aria-label={`Open ${record.title}`} onClick={() => openRecord(record)} /></td></tr>)}
            </tbody></table></div>
        </section>
    </div>;
}

function EditorView({ record, definition, saveState, activeSection, setActiveSection, update, save, generate, remove, back, preview }: {
    record: FormRecord; definition?: FormDefinition; saveState: SaveState; activeSection: string; setActiveSection: (value: string) => void;
    update: (mutator: (data: Record<string, any>) => void) => void; save: () => void; generate: () => void; remove: () => void; back: () => void; preview: (doc: FormDocument) => void;
}) {
    const sections = record.form_type === 'ics_214' ? [['overview', 'Operational period'], ['resources', 'Resources'], ['activities', 'Activity log'], ['prepared', 'Prepared by'], ['documents', 'PDF versions']] : [['overview', 'General information'], ['team', 'Team members'], ['labor', 'Labor'], ['equipment', 'Equipment'], ['mileage', 'Mileage'], ['materials', 'Materials'], ['certification', 'Certification'], ['documents', 'PDF versions']];
    return <div className="of-editor">
        <div className="of-commandbar"><Button appearance="subtle" icon={<ArrowLeft size={17} />} aria-label="Forms library" onClick={back}>Forms library</Button><span className="of-command-divider" /><SaveIndicator state={saveState} savedAt={record.last_autosaved_at} /><span className="of-toolbar-spacer" /><Button icon={<Save size={17} />} aria-label="Save now" onClick={save}>Save now</Button><Button appearance="primary" icon={<FileCheck2 size={17} />} aria-label="Generate PDF" onClick={generate}>Generate PDF</Button>{!record.latest_pdf_version && <Button appearance="subtle" icon={<Trash2 size={17} />} aria-label="Delete draft" onClick={remove}>Delete draft</Button>}</div>
        <div className="of-record-spine"><div><Badge appearance="outline">{typeLabel(record.form_type)} · v{record.form_version}</Badge><h1>{record.title}</h1><p>Record {record.id.slice(-8).toUpperCase()} · revision {record.revision}</p></div><div className="of-spine-status"><StatusBadge status={record.status} />{record.latest_pdf_version && <Badge color={record.has_changes_since_latest_pdf ? 'warning' : 'success'}>{record.has_changes_since_latest_pdf ? 'Changed since PDF' : `PDF v${record.latest_pdf_version} current`}</Badge>}</div></div>
        <div className="of-editor-grid"><aside className="of-context-nav" aria-label="Form sections">{sections.map(([id, label]) => <button key={id} className={activeSection === id ? 'active' : ''} onClick={() => setActiveSection(id)}>{label}</button>)}</aside><div className="of-form-canvas" onBlur={save}>
            {record.form_type === 'ics_214' ? <IcsEditor record={record} definition={definition} section={activeSection} update={update} /> : <FrocEditor record={record} definition={definition} section={activeSection} update={update} />}
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

function FrocEditor({ record, definition, section, update }: EditorProps) {
    const data = record.data; const cap = definition?.capacities || {};
    if (section === 'overview') return <FormSection number="1" title="General information"><FieldGrid><TextField label="Event ID" value={data.general_information?.event_id} set={(v) => update((d) => setPath(d, ['general_information', 'event_id'], v))} /><TextField label="Applicant name" value={data.general_information?.applicant_name} set={(v) => update((d) => setPath(d, ['general_information', 'applicant_name'], v))} /><TextField label="Department" value={data.general_information?.department} set={(v) => update((d) => setPath(d, ['general_information', 'department'], v))} /><TextField type="date" label="Report date" value={data.general_information?.date} set={(v) => update((d) => setPath(d, ['general_information', 'date'], v))} /></FieldGrid><Totals data={data} /></FormSection>;
    if (section === 'team') return <RepeatingTable title="Team members" rows={data.team_members || []} fields={[['employee_id', 'Employee ID'], ['employee_name', 'Employee name']]} capacity={cap.team_members || 14} onChange={(rows) => update((d) => { d.team_members = rows; })} rowType="team_members" />;
    if (section === 'labor') return <RepeatingTable title="Labor activity" rows={data.labor || []} fields={[['category', 'Cat.'], ['work_performed', 'Work performed'], ['location_gps', 'Location / GPS'], ['start', 'Start', 'time'], ['end', 'End', 'time'], ['manual_override_hours', 'Override hours'], ['override_reason', 'Override reason'], ['event_related', 'Event?', 'checkbox']]} capacity={cap.labor || 13} onChange={(rows) => update((d) => { d.labor = rows; })} rowType="labor" wide="work_performed" />;
    if (section === 'equipment') return <RepeatingTable title="Equipment hours" rows={data.equipment_hours || []} fields={[['category', 'Cat.'], ['equipment_id', 'Equipment ID'], ['operator', 'Operator'], ['description', 'Description'], ['location', 'Location'], ['hours', 'Hours'], ['event_related', 'Event?', 'checkbox']]} capacity={cap.equipment_hours || 6} onChange={(rows) => update((d) => { d.equipment_hours = rows; })} rowType="equipment_hours" />;
    if (section === 'mileage') return <RepeatingTable title="Vehicle mileage" rows={data.vehicle_mileage || []} fields={[['category', 'Cat.'], ['equipment_id', 'Vehicle ID'], ['operator', 'Operator'], ['destination', 'Destination'], ['start_odometer', 'Start odo.'], ['end_odometer', 'End odo.'], ['manual_miles', 'Corrected miles'], ['correction_reason', 'Correction reason'], ['event_related', 'Event?', 'checkbox']]} capacity={cap.vehicle_mileage || 2} onChange={(rows) => update((d) => { d.vehicle_mileage = rows; })} rowType="vehicle_mileage" />;
    if (section === 'materials') return <RepeatingTable title="Materials and supplies" rows={data.materials || []} fields={[['category', 'Cat.'], ['item', 'Item'], ['quantity', 'Quantity'], ['cost', 'Cost'], ['justification', 'Justification'], ['receipt_reference', 'Receipt ref.'], ['from_stock', 'Stock?', 'checkbox']]} capacity={cap.materials || 7} onChange={(rows) => update((d) => { d.materials = rows; })} rowType="materials" />;
    if (section === 'certification') return <><FormSection number="Certification" title="Employee and reviewer certification" help="All six signature/date fields and confirmation are required before PDF generation."><FieldGrid><TextField label="Page 2 employee signature" value={data.certification?.page2_employee_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'page2_employee_signature_text'], v))} /><TextField label="Page 2 reviewer signature" value={data.certification?.page2_reviewer_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'page2_reviewer_signature_text'], v))} /><TextField label="Final employee signature" value={data.certification?.final_employee_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'final_employee_signature_text'], v))} /><TextField type="date" label="Employee signature date" value={data.certification?.final_employee_signature_date} set={(v) => update((d) => setPath(d, ['certification', 'final_employee_signature_date'], v))} /><TextField label="Final reviewer signature" value={data.certification?.final_reviewer_signature_text} set={(v) => update((d) => setPath(d, ['certification', 'final_reviewer_signature_text'], v))} /><TextField type="date" label="Reviewer signature date" value={data.certification?.final_reviewer_signature_date} set={(v) => update((d) => setPath(d, ['certification', 'final_reviewer_signature_date'], v))} /></FieldGrid><label className="of-confirm"><input type="checkbox" checked={Boolean(data.certification?.confirmed)} onChange={(e) => update((d) => setPath(d, ['certification', 'confirmed'], e.target.checked))} /><span>I certify that this report is complete and accurate.</span></label></FormSection><NotesEditor values={data.additional_notes || []} capacity={cap.additional_notes || 28} onChange={(values) => update((d) => { d.additional_notes = values; })} /></>;
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

function RepeatingTable({ title, rows, fields, capacity, onChange, rowType, wide }: { title: string; rows: Record<string, any>[]; fields: [string, string, (InputType | 'checkbox')?][]; capacity: number; onChange: (rows: Record<string, any>[]) => void; rowType: string; wide?: string }) {
    const add = () => rows.length < capacity && onChange([...rows, clone(EMPTY_ROWS[rowType])]);
    const edit = (index: number, key: string, value: any) => { const next = clone(rows); next[index][key] = value; onChange(next); };
    return <section className="of-form-section"><div className="of-section-heading"><div><h2>{title}</h2><p>{rows.length} of {capacity} controlled rows used</p></div><Button icon={<Plus size={16} />} onClick={add} disabled={rows.length >= capacity}>Add row</Button></div><div className="of-table-wrap"><table className="of-edit-table"><thead><tr><th>#</th>{fields.map(([key, label]) => <th key={key} className={key === wide ? 'wide' : ''}>{label}</th>)}<th /></tr></thead><tbody>{rows.length === 0 && <tr><td colSpan={fields.length + 2} className="of-empty">No entries. Add a row when activity begins.</td></tr>}{rows.map((row, index) => <tr key={index}><td data-label="Row">{index + 1}</td>{fields.map(([key, label, type]) => <td key={key} data-label={label}>{type === 'checkbox' ? <input type="checkbox" checked={Boolean(row[key])} aria-label={`${label} row ${index + 1}`} onChange={(e) => edit(index, key, e.target.checked)} /> : key.includes('performed') || key.includes('reason') || key === 'justification' ? <Textarea aria-label={`${label} row ${index + 1}`} value={row[key] || ''} onChange={(_, data) => edit(index, key, data.value)} resize="vertical" /> : <Input aria-label={`${label} row ${index + 1}`} type={type || 'text'} value={row[key] ?? ''} onChange={(_, data) => edit(index, key, data.value)} />}</td>)}<td data-label="Actions"><Button appearance="subtle" icon={<Trash2 size={15} />} aria-label={`Remove row ${index + 1}`} onClick={() => onChange(rows.filter((_, i) => i !== index))} /></td></tr>)}</tbody></table></div></section>;
}

function NotesEditor({ values, capacity, onChange }: { values: string[]; capacity: number; onChange: (values: string[]) => void }) { return <section className="of-form-section"><div className="of-section-heading"><div><h2>Additional notes</h2><p>{values.length} of {capacity} lines used</p></div><Button icon={<Plus size={16} />} disabled={values.length >= capacity} onClick={() => onChange([...values, ''])}>Add note</Button></div>{values.map((value, index) => <div className="of-note" key={index}><span>{index + 1}</span><Textarea value={value} onChange={(_, d) => { const next = [...values]; next[index] = d.value; onChange(next); }} /><Button appearance="subtle" icon={<Trash2 size={15} />} onClick={() => onChange(values.filter((_, i) => i !== index))} /></div>)}</section>; }

function Totals({ data }: { data: Record<string, any> }) {
    const sum = (rows: any[], key: string, event: boolean) => rows.filter((r) => Boolean(r.event_related) === event).reduce((total, row) => total + Number(row[key] || 0), 0);
    const miles = (event: boolean) => (data.vehicle_mileage || []).filter((r: any) => Boolean(r.event_related) === event).reduce((total: number, row: any) => total + Number(row.manual_miles || (Number(row.end_odometer || 0) - Number(row.start_odometer || 0))), 0);
    const values = data.calculated_totals || {};
    return <div className="of-totals" aria-label="Authoritative calculated totals"><div><small>Event labor</small><strong>{values.p2_total_event_hours ?? '0.00'} h</strong></div><div><small>Non-event labor</small><strong>{values.p2_total_non_event_hours ?? '0.00'} h</strong></div><div><small>Event equipment</small><strong>{values.p3_equipment_hours_total_event ?? sum(data.equipment_hours || [], 'hours', true).toFixed(2)} h</strong></div><div><small>Non-event equipment</small><strong>{values.p3_equipment_hours_total_non_event ?? sum(data.equipment_hours || [], 'hours', false).toFixed(2)} h</strong></div><div><small>Event mileage</small><strong>{values.p3_mileage_total_event ?? miles(true).toFixed(2)} mi</strong></div><div><small>Non-event mileage</small><strong>{values.p3_mileage_total_non_event ?? miles(false).toFixed(2)} mi</strong></div></div>;
}

function Documents({ record, preview }: { record: FormRecord; preview: (doc: FormDocument) => void }) { return <section className="of-form-section"><div className="of-section-title"><span><FileCheck2 size={18} /></span><div><h2>Generated PDF versions</h2><p>Immutable private files created from frozen source revisions.</p></div></div><div className="of-document-list">{record.documents.length === 0 && <div className="of-empty-panel"><FilePlus2 /><p>No PDF has been generated from this record.</p></div>}{[...record.documents].sort((a, b) => b.version_number - a.version_number).map((doc) => <article key={doc.id}><div><strong>Version {doc.version_number}</strong><span>{doc.display_name}</span><small>Source revision {doc.source_revision} · {stamp(doc.created_at)}</small></div><Button icon={<Printer size={16} />} onClick={() => preview(doc)}>Preview / print</Button><Button as="a" href={doc.download_url} icon={<Download size={16} />}>Download</Button></article>)}</div></section>; }
function StatusBadge({ status }: { status: string }) { return <Badge color={status === 'completed' ? 'success' : 'informative'} icon={status === 'completed' ? <Check size={12} /> : <FilePenLine size={12} />}>{status === 'completed' ? 'Completed' : 'Draft'}</Badge>; }
function SaveIndicator({ state, savedAt }: { state: SaveState; savedAt: string | null }) { const map = { idle: [Cloud, 'Changes pending'], saving: [LoaderCircle, 'Saving…'], saved: [Check, `Saved ${stamp(savedAt)}`], offline: [WifiOff, 'Offline — recovery copy stored'], error: [AlertTriangle, 'Save needs attention'] } as const; const [Icon, label] = map[state]; return <div className={`of-save-state ${state}`}><Icon size={16} className={state === 'saving' ? 'spin' : ''} /><span>{label}</span></div>; }
