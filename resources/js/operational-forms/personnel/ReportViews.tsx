import { useCallback, useEffect, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import { ArrowLeft, CheckCircle2, ChevronRight, Download, Eye, FilePlus2, Hourglass, RefreshCw } from 'lucide-react';
import { PersonnelApiError, type PersonnelApi } from './personnelApi';
import { DRIVER_STEPS, SUPERVISOR_REPORT_STEPS, TYPE_SUPERVISOR, TYPE_VEHICLE, VEHICLE_SUPERVISOR_STEPS, sortReports, statusTone } from './personnelLogic.js';
import { AnswerList, Notice, type StepDef } from './personnelUi';
import type { AccidentDetail, AccidentFormDefinition, AccidentSummary, PersonnelView } from './personnelTypes';
import { HUB_TIMEZONE } from '../../shared/timezone.ts';

const POLL_MS = 4000;
const POLL_LIMIT = 45;

function when(value: string | null | undefined, withTime = true) {
    if (!value) return '';
    return new Intl.DateTimeFormat('en-US', { timeZone: HUB_TIMEZONE, dateStyle: 'medium', ...(withTime ? { timeStyle: 'short' } : {}) }).format(new Date(value));
}

export function StatusChip({ status, label }: { status: string; label: string }) {
    return <span className={`pf-status is-${statusTone(status)}`}>{label}</span>;
}

export function MyReports({ api, navigate, goForms, canSupervise }: { api: PersonnelApi; navigate: (view: PersonnelView) => void; goForms: () => void; canSupervise: boolean }) {
    const [reports, setReports] = useState<AccidentSummary[] | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const load = useCallback(async () => {
        setLoading(true); setError('');
        try { setReports(sortReports(await api.list())); } catch (caught) {
            setError(caught instanceof PersonnelApiError ? caught.message : 'Your accident reports could not be loaded.');
        } finally { setLoading(false); }
    }, [api]);
    useEffect(() => { void load(); }, [load]);

    const awaiting = reports?.filter((report) => report.awaiting_you).length ?? 0;

    return <div className="pf-app">
        <div className="pf-topline"><Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms</Button></div>
        <header className="pf-head">
            <p className="of-eyebrow">Accident reports</p>
            <div className="pf-head-row"><h1>My accident reports</h1><Button icon={<RefreshCw size={16} />} onClick={() => void load()} disabled={loading}>Refresh</Button></div>
            <p>Reports you started, reports about you, and reports waiting for your supervisor's part.</p>
        </header>
        <div className="pf-actions">
            <Button appearance="primary" icon={<FilePlus2 size={17} />} onClick={() => navigate({ name: 'vehicle' })}>New Vehicle Accident Report</Button>
            {canSupervise && <Button icon={<FilePlus2 size={17} />} onClick={() => navigate({ name: 'supervisor-report' })}>New Supervisor's Report of Accident</Button>}
        </div>
        {awaiting > 0 && <Notice tone="warning" title={`Awaiting your supervisor's part (${awaiting})`}>Open each report marked "Awaiting you" to complete and sign the supervisor's part.</Notice>}
        {error && <MessageBar intent="error" role="alert"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        {!reports && !error && <div className="pf-loading"><Spinner label="Loading your accident reports" /></div>}
        {reports && reports.length === 0 && <div className="pf-empty"><p><strong>No accident reports yet.</strong></p><p>Reports you start or that involve you appear here.</p></div>}
        {reports && reports.length > 0 && <ul className="pf-report-list">
            {reports.map((report) => <li key={report.id}>
                <button type="button" className={`pf-report-row${report.awaiting_you ? ' is-awaiting' : ''}`} onClick={() => navigate({ name: 'report', id: report.id })}>
                    <span className="pf-report-main">
                        <span className="pf-report-top">
                            <strong>{report.type_label}</strong>
                            <StatusChip status={report.status} label={report.status_label} />
                            {report.awaiting_you && <span className="pf-badge">Awaiting you</span>}
                        </span>
                        <span className="pf-report-meta">{report.reference} · {report.role}{report.subject_name ? ` · ${report.subject_name}` : ''}</span>
                        <span className="pf-report-meta">{report.occurred_at ? `Accident ${when(report.occurred_at)}` : `Last changed ${when(report.updated_at)}`}{report.vehicle_label ? ` · ${report.vehicle_label}` : ''}</span>
                    </span>
                    <ChevronRight size={18} aria-hidden="true" />
                </button>
            </li>)}
        </ul>}
    </div>;
}

function confirmationTitle(report: AccidentDetail) {
    if (report.type === TYPE_SUPERVISOR) return 'Report submitted · complete';
    if (report.status === 'awaiting_supervisor') return 'Driver report submitted · awaiting supervisor';
    return "Supervisor's part submitted · report complete";
}

/**
 * One report, read-only: after submission, or opened from the list by someone who cannot change it. Polls while
 * the official PDF is being produced and offers it for download once it exists.
 */
export function ReportDetail({ api, initial, form, justSubmitted, openPdf, goForms, navigate }: {
    api: PersonnelApi; initial: AccidentDetail; form: AccidentFormDefinition | null; justSubmitted: boolean;
    openPdf: (url: string, name: string) => void; goForms: () => void; navigate: (view: PersonnelView) => void;
}) {
    const [report, setReport] = useState(initial);
    const [pollError, setPollError] = useState('');
    const [polls, setPolls] = useState(0);
    const waitingForPdf = report.pdf_pending || (report.completed_at !== null && !report.document);

    useEffect(() => {
        if (!waitingForPdf || polls >= POLL_LIMIT) return undefined;
        const timer = window.setTimeout(() => {
            api.show(report.id).then((next) => { setReport(next); setPollError(''); })
                .catch((caught) => setPollError(caught instanceof PersonnelApiError ? caught.message : 'Could not check on the PDF.'))
                .finally(() => setPolls((count) => count + 1));
        }, POLL_MS);
        return () => window.clearTimeout(timer);
    }, [api, report.id, waitingForPdf, polls]);

    const driverSteps = (report.type === TYPE_VEHICLE ? DRIVER_STEPS : SUPERVISOR_REPORT_STEPS) as StepDef[];
    const choices = form ? { causes: form.causes, actions: form.actions } : undefined;
    const hubNotice = form?.notices.hub ?? 'Submitting here alerts MBFD administration and keeps the record in the Hub. It does not send the report to Risk Management: download the finished PDF and deliver it as the form instructs.';

    return <div className="pf-app">
        <div className="pf-topline">
            <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={goForms}>Forms</Button>
            <Button appearance="subtle" onClick={() => navigate({ name: 'mine' })}>My accident reports</Button>
        </div>
        {justSubmitted ? <section className="pf-result" aria-labelledby="pf-result-title">
            <span className={`pf-result-icon ${report.status === 'complete' ? 'is-success' : 'is-progress'}`}>{report.status === 'complete' ? <CheckCircle2 size={34} /> : <Hourglass size={32} />}</span>
            <h1 id="pf-result-title" tabIndex={-1} ref={(node) => node?.focus()}>{confirmationTitle(report)}</h1>
            <p className="pf-result-number">{report.reference}</p>
            {report.status === 'awaiting_supervisor' && report.supervisor && <p>{[report.supervisor.rank, report.supervisor.name].filter(Boolean).join(' ')} has been notified to complete the supervisor's part. MBFD administration has been alerted.</p>}
        </section> : <header className="pf-head">
            <p className="of-eyebrow">Reference {report.reference}</p>
            <div className="pf-head-row"><h1>{report.type_label}</h1><StatusChip status={report.status} label={report.status_label} /></div>
            <p>{report.role}{report.submitted_at ? ` · Submitted ${when(report.submitted_at)}` : ''}{report.completed_at ? ` · Completed ${when(report.completed_at)}` : ''}</p>
        </header>}

        <section className="pf-card" aria-labelledby="pf-doc-title">
            <h2 id="pf-doc-title">Official PDF</h2>
            {report.document ? <div className="pf-actions">
                <Button as="a" appearance="primary" icon={<Download size={17} />} href={report.document.download_url} download>Download PDF</Button>
                <Button icon={<Eye size={17} />} onClick={() => report.document && openPdf(report.document.view_url, `${report.type_label} ${report.reference}`)}>View PDF</Button>
            </div> : waitingForPdf ? <div className="pf-inline-wait">
                {polls < POLL_LIMIT ? <Spinner size="tiny" label="Preparing the official PDF…" /> : <>
                    <span>The PDF is taking longer than usual.</span>
                    <Button icon={<RefreshCw size={16} />} onClick={() => setPolls(0)}>Check again</Button>
                </>}
            </div> : report.status === 'awaiting_supervisor'
                ? <p className="pf-hint">The official PDF is produced after the supervisor completes their part. You are notified in the Hub, and it appears here and in My Documents.</p>
                : <p className="pf-hint">No PDF yet.</p>}
            {pollError && <p className="pf-hint">{pollError}</p>}
            <Notice tone="info" title="Deliver to Risk Management">{hubNotice}</Notice>
        </section>

        <section className="pf-card" aria-labelledby="pf-driver-title">
            <h2 id="pf-driver-title">{report.type === TYPE_VEHICLE ? "Driver's side" : 'Report'}</h2>
            <dl className="pf-answers">
                <div><dt>Employee involved</dt><dd>{report.subject?.name ?? 'Not chosen'}</dd></div>
                {report.type === TYPE_VEHICLE && <div><dt>City vehicle</dt><dd>{report.vehicle?.label ?? 'Not chosen'}</dd></div>}
                {report.type === TYPE_VEHICLE && <div><dt>Supervisor</dt><dd>{report.supervisor ? [report.supervisor.rank, report.supervisor.name].filter(Boolean).join(' ') : 'Not chosen'}</dd></div>}
            </dl>
            {driverSteps.filter((step) => step.fields.length > 0).map((step) => <div key={step.id} className="pf-summary-block">
                <h3 className="pf-group-title">{step.title}</h3>
                <AnswerList fields={step.fields} values={report.driver_data} choices={choices} />
            </div>)}
        </section>

        {report.type === TYPE_VEHICLE && report.supervisor_data && report.supervisor_completed_at && <section className="pf-card" aria-labelledby="pf-sup-title">
            <h2 id="pf-sup-title">Supervisor's part</h2>
            {(VEHICLE_SUPERVISOR_STEPS as StepDef[]).filter((step) => step.fields.length > 0).map((step) => <div key={step.id} className="pf-summary-block">
                <h3 className="pf-group-title">{step.title}</h3>
                <AnswerList fields={step.fields} values={report.supervisor_data ?? {}} />
            </div>)}
        </section>}

        {justSubmitted && <div className="pf-actions is-center">
            <Button onClick={() => navigate({ name: 'mine' })}>My accident reports</Button>
            <Button appearance="subtle" onClick={goForms}>Back to Forms</Button>
        </div>}
    </div>;
}
