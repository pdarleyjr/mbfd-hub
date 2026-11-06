import { useCallback, useEffect, useState } from 'react';
import { Button, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components';
import './personnel.css';
import { PersonnelApiError, type PersonnelApi } from './personnelApi';
import { TYPE_SUPERVISOR, TYPE_VEHICLE, searchForView, todayIn } from './personnelLogic.js';
import { AccidentEditor } from './AccidentEditor';
import { Memorandum } from './Memorandum';
import { MyReports, ReportDetail } from './ReportViews';
import type { AccidentDetail, AccidentFormDefinition, AccidentSummary, PersonnelView, ReportType } from './personnelTypes';
import { HUB_TIMEZONE } from '../../shared/timezone.ts';

export interface PersonnelAppProps {
    api: PersonnelApi;
    owner: string;
    view: PersonnelView;
    canSupervise: boolean;
    navigate: (view: PersonnelView) => void;
    goForms: () => void;
    openPdf: (url: string, name: string) => void;
}

function Problem({ text, retry, goForms }: { text: string; retry?: () => void; goForms: () => void }) {
    return <div className="pf-app">
        <MessageBar intent="error" role="alert"><MessageBarBody>{text}</MessageBarBody></MessageBar>
        <div className="pf-actions">{retry && <Button appearance="primary" onClick={retry}>Try again</Button>}<Button appearance="subtle" onClick={goForms}>Back to Forms</Button></div>
    </div>;
}

function Loading({ label }: { label: string }) {
    return <div className="pf-app"><div className="pf-loading"><Spinner label={label} /></div></div>;
}

/** The address bar follows a new report once it exists, so a refresh reopens the same draft. */
function rememberReport(id: string) {
    const url = `${window.location.pathname}${searchForView({ name: 'report', id })}`;
    window.history.replaceState(window.history.state, '', url);
}

/** A new report: the editor starts empty and the draft is created on the first change. */
function NewReport({ type, form, props }: { type: ReportType; form: AccidentFormDefinition; props: PersonnelAppProps }) {
    const { api, owner, navigate, goForms, openPdf } = props;
    const [submitted, setSubmitted] = useState<AccidentDetail | null>(null);
    const [existing, setExisting] = useState<AccidentSummary | null>(null);
    const [created, setCreated] = useState(false);

    useEffect(() => {
        let active = true;
        api.list().then((reports) => {
            if (!active) return;
            setExisting(reports.find((report) => report.type === type && report.status === 'draft' && report.role !== 'Supervisor') ?? null);
        }).catch(() => { /* the offer to continue a draft is optional; the list view shows the same drafts */ });
        return () => { active = false; };
    }, [api, type]);

    const onCreated = useCallback((report: AccidentDetail) => { setCreated(true); rememberReport(report.id); }, []);

    if (submitted) return <ReportDetail api={api} initial={submitted} form={form} justSubmitted openPdf={openPdf} goForms={goForms} navigate={navigate} />;

    return <>
        {existing && !created && <div className="pf-app pf-app-banner"><MessageBar intent="info"><MessageBarBody>
            You have an unfinished {existing.type_label} ({existing.reference}).{' '}
            <Button appearance="transparent" onClick={() => navigate({ name: 'report', id: existing.id })}>Continue it</Button>
        </MessageBarBody></MessageBar></div>}
        <AccidentEditor
            api={api} owner={owner} form={form} type={type} initial={null} today={todayIn(new Date(), HUB_TIMEZONE)}
            onCreated={onCreated} onSubmitted={setSubmitted} onDeleted={() => navigate({ name: 'mine' })}
            openPdf={openPdf} goForms={goForms} goMine={() => navigate({ name: 'mine' })}
        />
    </>;
}

/** One report from a link or the list: the editor when this viewer may change a part of it, otherwise read-only. */
function ReportScreen({ id, form, props }: { id: string; form: AccidentFormDefinition; props: PersonnelAppProps }) {
    const { api, owner, navigate, goForms, openPdf } = props;
    const [report, setReport] = useState<AccidentDetail | null>(null);
    const [submitted, setSubmitted] = useState<AccidentDetail | null>(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setError('');
        try { setReport(await api.show(id)); } catch (caught) {
            setError(caught instanceof PersonnelApiError ? caught.message : 'The report could not be opened.');
        }
    }, [api, id]);
    useEffect(() => { void load(); }, [load]);

    if (error) return <Problem text={error} retry={() => void load()} goForms={goForms} />;
    if (!report) return <Loading label="Opening the report" />;
    if (submitted) return <ReportDetail api={api} initial={submitted} form={form} justSubmitted openPdf={openPdf} goForms={goForms} navigate={navigate} />;
    if (report.editable_section) {
        return <AccidentEditor
            api={api} owner={owner} form={form} type={report.type} initial={report} today={todayIn(new Date(), HUB_TIMEZONE)}
            onCreated={() => undefined} onSubmitted={setSubmitted} onDeleted={() => navigate({ name: 'mine' })}
            openPdf={openPdf} goForms={goForms} goMine={() => navigate({ name: 'mine' })}
        />;
    }
    return <ReportDetail api={api} initial={report} form={form} justSubmitted={false} openPdf={openPdf} goForms={goForms} navigate={navigate} />;
}

/** The accident reports and the Interoffice Memorandum inside the Forms app. */
export default function PersonnelApp(props: PersonnelAppProps) {
    const { api, owner, view, canSupervise, navigate, goForms, openPdf } = props;
    const [form, setForm] = useState<AccidentFormDefinition | null>(null);
    const [formError, setFormError] = useState('');
    const needsForm = view.name === 'vehicle' || view.name === 'supervisor-report' || view.name === 'report';

    const loadForm = useCallback(async () => {
        setFormError('');
        try { setForm(await api.form()); } catch (caught) {
            setFormError(caught instanceof PersonnelApiError ? caught.message : 'The accident report form could not be loaded.');
        }
    }, [api]);
    useEffect(() => { if (needsForm && !form) void loadForm(); }, [needsForm, form, loadForm]);

    let content;
    if (view.name === 'memo') {
        content = <Memorandum api={api} owner={owner} goForms={goForms} openPdf={openPdf} />;
    } else if (view.name === 'mine') {
        content = <MyReports api={api} navigate={navigate} goForms={goForms} canSupervise={canSupervise} />;
    } else if (formError) {
        content = <Problem text={formError} retry={() => void loadForm()} goForms={goForms} />;
    } else if (!form) {
        content = <Loading label="Opening the accident report" />;
    } else if (view.name === 'supervisor-report' && !form.can_supervise) {
        content = <Problem text="The Supervisor's Report of Accident is completed by a supervisor. Ask your officer to complete it, or start a Vehicle Accident Report if you were driving." goForms={goForms} />;
    } else if (view.name === 'report') {
        content = <ReportScreen key={view.id} id={view.id} form={form} props={props} />;
    } else {
        const type = view.name === 'supervisor-report' ? TYPE_SUPERVISOR : TYPE_VEHICLE;
        content = <NewReport key={type} type={type} form={form} props={props} />;
    }

    return <div className="pf">{content}</div>;
}
