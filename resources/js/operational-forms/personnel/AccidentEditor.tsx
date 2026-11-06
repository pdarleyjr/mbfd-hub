import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody,
} from '@fluentui/react-components';
import { ArrowLeft, ArrowRight, Check, ListOrdered, Plus } from 'lucide-react';
import {
    DRIVER_STEPS, META_SUBJECT, META_SUPERVISOR, META_VEHICLE, TYPE_SUPERVISOR, conflictRows, displayValue, formatDate, isBlank, missingItems, stepsFor,
} from './personnelLogic.js';
import type { PersonnelApi } from './personnelApi';
import { EmployeePicker, SupervisorPicker, VehiclePicker } from './Pickers';
import { AnswerList, Notice, SaveIndicator, StepFields, type StepDef } from './personnelUi';
import { ReviewSign } from './ReviewSign';
import type { AccidentDetail, AccidentFormDefinition, ReportType } from './personnelTypes';
import { useAccidentReport } from './useAccidentReport';

/** Fields the server fills from the Hub record once the employee or the vehicle is chosen. */
const IDENTITY_KEYS = ['employee_name', 'dept_div', 'job_classification', 'employee_id', 'division', 'department', 'classification'];
const GROUP_TITLES: Record<string, string> = {
    vehicle: 'City vehicle details', party: 'Other party', 'other-vehicle': 'Other vehicle', witness1: 'Witness', witness2: 'Second witness',
};

export interface AccidentEditorProps {
    api: PersonnelApi;
    owner: string;
    form: AccidentFormDefinition;
    type: ReportType;
    initial: AccidentDetail | null;
    today: string;
    onCreated: (report: AccidentDetail) => void;
    onSubmitted: (report: AccidentDetail) => void;
    onDeleted: () => void;
    openPdf: (url: string, name: string) => void;
    goForms: () => void;
    goMine: () => void;
}

/**
 * The stepped editor for the driver's side of a Vehicle Accident Report, the supervisor's part of one, and a
 * Supervisor's Report of Accident. Which one depends on the report type and the part this viewer may edit.
 */
export function AccidentEditor({ api, owner, form, type, initial, today, onCreated, onSubmitted, onDeleted, openPdf, goForms, goMine }: AccidentEditorProps) {
    const defaultSubject = type === TYPE_SUPERVISOR ? null : form.me;
    const state = useAccidentReport({ api, owner, type, initial, defaultSubject, onCreated });
    const steps = stepsFor(type, state.section) as StepDef[];
    const [stepIndex, setStepIndex] = useState(0);
    const [showSteps, setShowSteps] = useState(false);
    const [showWitness2, setShowWitness2] = useState(false);
    const [previewing, setPreviewing] = useState(false);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const topRef = useRef<HTMLDivElement>(null);
    const firstRender = useRef(true);
    const step = steps[stepIndex];
    const supervisorPart = state.section === 'supervisor';
    const choices = useMemo(() => ({ causes: form.causes, actions: form.actions }), [form.causes, form.actions]);

    // The supervisor's part is dated the day it is completed unless the supervisor changes it.
    useEffect(() => {
        if (supervisorPart && isBlank(state.values.date_of_report)) state.setField('date_of_report', today);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supervisorPart]);

    useEffect(() => {
        if (firstRender.current) { firstRender.current = false; return; }
        topRef.current?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        headingRef.current?.focus({ preventScroll: true });
    }, [stepIndex]);

    const goStep = useCallback((id: string) => {
        const index = steps.findIndex((item) => item.id === id);
        if (index >= 0) { setStepIndex(index); setShowSteps(false); }
    }, [steps]);

    const missing = missingItems(steps, state.values, state.meta);
    const stepDone = (id: string) => !missing.some((item) => item.stepId === id);

    const title = type === TYPE_SUPERVISOR ? "Supervisor's Report of Accident" : supervisorPart ? 'Vehicle Accident Report: supervisor\'s part' : 'Vehicle Accident Report';
    const importantNotice = type === TYPE_SUPERVISOR ? form.notices.supervisor : form.notices.vehicle;
    const subjectId = state.meta[META_SUBJECT] ?? null;
    // Only a supervisor may name someone else as the employee involved; any other member is the driver.
    const canChooseSubject = type === TYPE_SUPERVISOR || form.can_supervise;

    const preview = useCallback(async () => {
        setPreviewing(true);
        try {
            const saved = await state.flush();
            if (saved) openPdf(api.previewUrl(saved.id), `Preview: ${saved.type_label} ${saved.reference}`);
        } finally { setPreviewing(false); }
    }, [state, api, openPdf]);

    const remove = useCallback(async () => {
        if (!window.confirm('Delete this draft? This cannot be undone.')) return;
        try {
            await state.removeDraft();
            onDeleted();
        } catch (error) {
            state.setMessage(error instanceof Error ? error.message : 'The draft could not be deleted.');
        }
    }, [state, onDeleted]);

    const pickerSummary = useMemo(() => {
        const summary: Record<string, { label: string; value: string }[]> = {};
        if (type === TYPE_SUPERVISOR) summary.employee = [{ label: 'Employee involved', value: state.labels.subject ?? '' }];
        else if (!supervisorPart) {
            summary.who = [{ label: 'Employee involved', value: state.labels.subject ?? '' }, { label: 'City vehicle', value: state.labels.vehicle ?? '' }];
            summary.supervisor = [{ label: 'Supervisor', value: state.labels.supervisor ?? '' }];
        }
        return summary;
    }, [type, supervisorPart, state.labels]);

    const renderWidget = () => {
        if (step.widget === 'subject') {
            const hiddenIdentity = !state.report || subjectId === null;
            const hiddenVehicle = state.meta[META_VEHICLE] === null || state.meta[META_VEHICLE] === undefined;
            const identityStep: StepDef = { ...step, fields: step.fields.filter((field) => field.group !== 'vehicle' && !(hiddenIdentity && IDENTITY_KEYS.includes(field.key))) };
            const vehicleStep: StepDef = { ...step, fields: step.fields.filter((field) => field.group === 'vehicle') };
            return <>
                <section className="pf-card">
                    {canChooseSubject ? <EmployeePicker
                        api={api} label="Employee involved" chosenId={subjectId} chosenLabel={state.labels.subject} me={form.me} error={state.fieldErrors[META_SUBJECT]}
                        onPick={(option) => state.setMetaValue(META_SUBJECT, option.id, option.title.replace(/^Me \((.*)\)$/, '$1'))}
                    /> : <div className="pf-picker">
                        <span className="pf-label">Employee involved (driver)</span>
                        <div className="pf-chosen"><Check size={18} aria-hidden="true" /><strong>{state.labels.subject ?? form.me.name ?? 'You'}</strong></div>
                        <p className="pf-hint">You complete this side as the driver. If someone else was driving, they or a supervisor must start the report.</p>
                        {state.fieldErrors[META_SUBJECT] && <p className="pf-error" role="alert">{state.fieldErrors[META_SUBJECT]}</p>}
                    </div>}
                    {hiddenIdentity && <p className="pf-hint">The name and details fill in from the Hub record {type === TYPE_SUPERVISOR ? 'when you choose the employee' : 'as soon as the report starts'}.</p>}
                    {identityStep.fields.length > 0 && <>
                        {!hiddenIdentity && <h3 className="pf-group-title">From the Hub record. Correct anything that is wrong.</h3>}
                        <StepFields step={identityStep} values={state.values} errors={state.fieldErrors} onChange={state.setField} />
                    </>}
                </section>
                {type !== TYPE_SUPERVISOR && <section className="pf-card">
                    <VehiclePicker
                        vehicles={form.vehicles} chosenId={state.meta[META_VEHICLE] ?? null} chosenLabel={state.labels.vehicle} error={state.fieldErrors[META_VEHICLE]}
                        onPick={(option) => state.setMetaValue(META_VEHICLE, option.id, option.title)}
                    />
                    {!hiddenVehicle && <StepFields step={vehicleStep} values={state.values} errors={state.fieldErrors} groupTitles={GROUP_TITLES} onChange={state.setField} />}
                </section>}
            </>;
        }
        if (step.widget === 'supervisor') {
            return <section className="pf-card">
                <SupervisorPicker
                    supervisors={form.supervisors} chosenId={state.meta[META_SUPERVISOR] ?? null} chosenLabel={state.labels.supervisor} excludeId={subjectId}
                    error={state.fieldErrors[META_SUPERVISOR]} onPick={(option) => state.setMetaValue(META_SUPERVISOR, option.id, option.title)}
                />
                <p className="pf-hint">The employee involved cannot complete the supervisor's part of their own accident, so they are not listed.</p>
            </section>;
        }
        if (step.widget === 'driver-summary' && state.report) {
            const report = state.report;
            return <section className="pf-card">
                <dl className="pf-answers">
                    <div><dt>Reference</dt><dd>{report.reference}</dd></div>
                    <div><dt>Employee involved</dt><dd>{report.subject?.name ?? 'Not chosen'}</dd></div>
                    <div><dt>City vehicle</dt><dd>{report.vehicle?.label ?? 'Not chosen'}</dd></div>
                </dl>
                {DRIVER_STEPS.filter((item) => item.fields.length > 0).map((item) => <div key={item.id} className="pf-summary-block">
                    <h3 className="pf-group-title">{item.title}</h3>
                    <AnswerList fields={item.fields as StepDef['fields']} values={report.driver_data} />
                </div>)}
            </section>;
        }
        if (step.widget === 'review') {
            return <ReviewSign
                steps={steps} state={state} choices={choices} noticeImportant={importantNotice} noticeHub={form.notices.hub}
                submitLabel={type === TYPE_SUPERVISOR ? 'Submit report' : supervisorPart ? "Submit supervisor's part" : 'Submit driver report'}
                signatureLabel={type === TYPE_SUPERVISOR || supervisorPart ? "Supervisor's signature" : 'Signature of Employee'}
                pickerSummary={pickerSummary} goStep={goStep} preview={() => void preview()} previewing={previewing} onSubmitted={onSubmitted}
                onDelete={supervisorPart ? undefined : () => void remove()}
            />;
        }
        return null;
    };

    const witness2Filled = ['witness2_name', 'witness2_address', 'witness2_phone'].some((key) => !isBlank(state.values[key]));
    const hiddenGroups = step.id === 'police' && !showWitness2 && !witness2Filled ? ['witness2'] : [];
    const conflictList = state.conflict
        ? conflictRows(((state.section === 'supervisor' ? state.conflict.supervisor_data : state.conflict.driver_data) ?? {}), state.values, state.dirtyKeys(), steps.flatMap((item) => item.fields))
        : [];

    return <div className="pf-app" ref={topRef}>
        <div className="pf-topline">
            <Button appearance="subtle" icon={<ArrowLeft size={17} />} onClick={() => void state.flush().finally(goForms)}>Forms</Button>
            <Button appearance="subtle" onClick={() => void state.flush().finally(goMine)}>My accident reports</Button>
        </div>
        <header className="pf-head">
            <p className="of-eyebrow">{state.report ? `Reference ${state.report.reference}` : 'New report'}</p>
            <div className="pf-head-row"><h1>{title}</h1><SaveIndicator status={state.status} /></div>
            {supervisorPart && state.report && <p>Submitted by the driver{state.report.submitted_at ? ` on ${formatDate(state.report.submitted_at.slice(0, 10))}` : ''}. Complete the supervisor's part and sign.</p>}
        </header>

        {state.restored && <MessageBar intent="info"><MessageBarBody>
            Your unsaved entries from this device were put back{state.report ? ' and are being saved.' : '.'}
            {!state.report && <> <Button appearance="transparent" onClick={() => void state.startOver()}>Start over</Button></>}
            <Button appearance="transparent" onClick={state.dismissRestored}>Dismiss</Button>
        </MessageBarBody></MessageBar>}
        {state.status === 'offline' && <MessageBar intent="warning"><MessageBarBody>You are offline. Your entries are saved on this device and are sent when the connection returns.</MessageBarBody></MessageBar>}
        {state.message && step.widget !== 'review' && <MessageBar intent="error" role="alert"><MessageBarBody>{state.message}</MessageBarBody></MessageBar>}

        {stepIndex === 0 && <>
            <Notice tone="important" title="IMPORTANT">{importantNotice}</Notice>
            <Notice tone="info">{form.notices.hub}</Notice>
        </>}

        <nav className={`pf-stepper${showSteps ? ' is-open' : ''}`} aria-label="Report steps">
            <div className="pf-stepper-bar">
                <span className="pf-stepper-count">Step {stepIndex + 1} of {steps.length}</span>
                <progress max={steps.length} value={stepIndex + 1} aria-hidden="true" />
                <Button className="pf-stepper-toggle" appearance="secondary" icon={<ListOrdered size={17} />} aria-expanded={showSteps} onClick={() => setShowSteps((open) => !open)}>All steps</Button>
            </div>
            <ol>{steps.map((item, index) => <li key={item.id}>
                <button type="button" aria-current={index === stepIndex ? 'step' : undefined} className={stepDone(item.id) && item.widget !== 'review' ? 'is-done' : undefined} onClick={() => goStep(item.id)}>
                    <span className="pf-step-number" aria-hidden="true">{stepDone(item.id) && item.widget !== 'review' ? <Check size={14} /> : index + 1}</span>
                    <span>{item.title}{stepDone(item.id) && item.widget !== 'review' ? <span className="sr-only"> (complete)</span> : null}</span>
                </button>
            </li>)}</ol>
        </nav>

        <section className="pf-step" aria-labelledby="pf-step-title">
            <h2 id="pf-step-title" ref={headingRef} tabIndex={-1}>{step.title}</h2>
            {step.intro && <p className="pf-hint">{step.intro}</p>}
            {step.widget ? renderWidget() : <section className="pf-card">
                <StepFields step={step} values={state.values} errors={state.fieldErrors} choices={choices} maxDate={today} groupTitles={GROUP_TITLES} hiddenGroups={hiddenGroups} onChange={state.setField} />
                {step.id === 'police' && hiddenGroups.includes('witness2') && <div><Button icon={<Plus size={16} />} onClick={() => setShowWitness2(true)}>Add a second witness</Button></div>}
            </section>}
        </section>

        <div className="pf-step-nav">
            <Button size="large" icon={<ArrowLeft size={18} />} disabled={stepIndex === 0} onClick={() => setStepIndex((index) => Math.max(0, index - 1))}>Back</Button>
            {stepIndex < steps.length - 1 && <Button size="large" appearance="primary" icon={<ArrowRight size={18} />} iconPosition="after" onClick={() => setStepIndex((index) => Math.min(steps.length - 1, index + 1))}>
                Next: {steps[stepIndex + 1].title}
            </Button>}
        </div>

        <Dialog open={Boolean(state.recovery)} modalType="alert">
            <DialogSurface><DialogBody>
                <DialogTitle>Unsaved entries on this device</DialogTitle>
                <DialogContent>This device has entries for this report that never reached the server, and the report has changed since. Put your entries back on top of the current report, or discard them.</DialogContent>
                <DialogActions>
                    <Button onClick={state.discardRecovery}>Discard my entries</Button>
                    <Button appearance="primary" onClick={state.restoreRecovery}>Put my entries back</Button>
                </DialogActions>
            </DialogBody></DialogSurface>
        </Dialog>

        <Dialog open={Boolean(state.conflict)} modalType="alert">
            <DialogSurface><DialogBody>
                <DialogTitle>Changed elsewhere</DialogTitle>
                <DialogContent>
                    <p>This report was changed on another screen. Your latest entries were not saved. Choose which version to keep.</p>
                    {conflictList.length > 0 && <table className="pf-conflict">
                        <thead><tr><th scope="col">Field</th><th scope="col">Saved version</th><th scope="col">Your entry</th></tr></thead>
                        <tbody>{conflictList.map((row) => <tr key={row.key}><th scope="row">{row.label}</th><td>{row.theirs || 'Blank'}</td><td>{row.mine || 'Blank'}</td></tr>)}</tbody>
                    </table>}
                </DialogContent>
                <DialogActions>
                    <Button onClick={state.keepTheirs}>Keep the saved version</Button>
                    <Button appearance="primary" onClick={state.reapplyMine}>Use my entries</Button>
                </DialogActions>
            </DialogBody></DialogSurface>
        </Dialog>
    </div>;
}

/** A short line for lists and confirmations, for example "11/05/2026 2:35 PM". */
export function occurredText(report: { driver_data?: Record<string, unknown> }) {
    const date = report.driver_data?.accident_date;
    const time = report.driver_data?.accident_time;
    return [displayValue({ key: 'd', label: '', kind: 'date' }, date), displayValue({ key: 't', label: '', kind: 'time' }, time)].filter(Boolean).join(' ');
}
