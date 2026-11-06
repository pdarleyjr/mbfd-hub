import { useCallback, useRef, useState } from 'react';
import { Button, MessageBar, MessageBarBody } from '@fluentui/react-components';
import { Eye, FilePenLine, LoaderCircle, Send, Trash2 } from 'lucide-react';
import { ATTESTATION_TEXT, SUPERVISOR_ATTESTATION_TEXT, missingItems, stepForKey } from './personnelLogic.js';
import { AnswerList, Notice, StepFields, type StepDef } from './personnelUi';
import { SignaturePad, type SignaturePadHandle } from './SignaturePad';
import type { AccidentDetail } from './personnelTypes';
import type { AccidentReportState } from './useAccidentReport';

export interface ReviewSignProps {
    steps: StepDef[];
    state: AccidentReportState;
    choices: Record<string, Record<string, string>>;
    noticeImportant: string;
    noticeHub: string;
    submitLabel: string;
    signatureLabel: string;
    /** Extra rows for the summary of the picker steps (employee, vehicle, supervisor). */
    pickerSummary: Record<string, { label: string; value: string }[]>;
    goStep: (id: string) => void;
    preview: () => void;
    previewing: boolean;
    onSubmitted: (report: AccidentDetail) => void;
    onDelete?: () => void;
}

/** The last step: everything entered, what is still needed, the official PDF preview, attestation, signature, submit. */
export function ReviewSign({ steps, state, choices, noticeImportant, noticeHub, submitLabel, signatureLabel, pickerSummary, goStep, preview, previewing, onSubmitted, onDelete }: ReviewSignProps) {
    const review = steps[steps.length - 1];
    const [attested, setAttested] = useState(false);
    const [signed, setSigned] = useState(false);
    const [tried, setTried] = useState(false);
    const signature = useRef<SignaturePadHandle>(null);
    const missing = missingItems(steps, state.values, state.meta);
    const serverErrors = Object.entries(state.fieldErrors);
    const supervisorPart = state.section === 'supervisor';

    const submit = useCallback(async () => {
        setTried(true);
        if (missing.length > 0 || !attested || !signed || state.submitting) return;
        const image = signature.current?.toDataUrl();
        if (!image) { setSigned(false); return; }
        const done = await state.submit(image);
        if (done) onSubmitted(done);
    }, [missing.length, attested, signed, state, onSubmitted]);

    const blockers = [
        ...(missing.length > 0 ? [`${missing.length} item${missing.length === 1 ? '' : 's'} still needed`] : []),
        ...(!attested ? ['Check the certification'] : []),
        ...(!signed ? ['Sign in the box'] : []),
    ];

    return <div className="pf-review">
        <Notice tone="important" title="IMPORTANT">{noticeImportant}</Notice>
        <Notice tone="info" title="Risk Management still needs the paper copy">{noticeHub}</Notice>

        {missing.length > 0 && <section className="pf-missing" aria-labelledby="pf-missing-title">
            <h2 id="pf-missing-title">Still needed before you can submit</h2>
            <ul>{missing.map((item) => <li key={item.key}>
                <button type="button" className="pf-link" onClick={() => goStep(item.stepId)}>{item.label}</button>
                <span className="pf-missing-step">{steps.find((step) => step.id === item.stepId)?.title}</span>
            </li>)}</ul>
        </section>}

        {serverErrors.length > 0 && <MessageBar intent="error" role="alert"><MessageBarBody>
            <strong>The report was not submitted.</strong>
            <ul className="pf-server-errors">{serverErrors.map(([key, text]) => <li key={key}>
                <button type="button" className="pf-link" onClick={() => goStep(stepForKey(steps, key))}>{text}</button>
            </li>)}</ul>
        </MessageBarBody></MessageBar>}

        <section className="pf-summary" aria-label="Your entries">
            {steps.filter((step) => step.id !== review.id).map((step) => {
                const extra = pickerSummary[step.id] ?? [];
                if (step.fields.length === 0 && extra.length === 0) return null;
                return <article key={step.id} className="pf-summary-step">
                    <header><h2>{step.title}</h2>{step.widget !== 'driver-summary' && <Button appearance="subtle" icon={<FilePenLine size={16} />} onClick={() => goStep(step.id)} aria-label={`Edit ${step.title}`}>Edit</Button>}</header>
                    {extra.length > 0 && <dl className="pf-answers">{extra.map((row) => <div key={row.label}><dt>{row.label}</dt><dd className={row.value ? undefined : 'is-empty'}>{row.value || 'Not chosen'}</dd></div>)}</dl>}
                    {step.fields.length > 0 && <AnswerList fields={step.fields} values={state.values} choices={choices} />}
                </article>;
            })}
        </section>

        {review.fields.length > 0 && <section className="pf-card">
            <StepFields step={review} values={state.values} errors={state.fieldErrors} onChange={state.setField} />
        </section>}

        <section className="pf-card pf-sign" aria-labelledby="pf-sign-title">
            <h2 id="pf-sign-title">Preview, certify and sign</h2>
            <p className="pf-hint">Check the official form before you sign. The preview shows your current entries; nothing is sent until you submit.</p>
            <div><Button icon={previewing ? <LoaderCircle className="spin" size={17} /> : <Eye size={17} />} disabled={!state.report || previewing} onClick={preview}>Preview official PDF</Button>
                {!state.report && <p className="pf-hint">The preview is available once the report has been saved.</p>}</div>
            <label className={`pf-attest${tried && !attested ? ' is-invalid' : ''}`}>
                <input type="checkbox" checked={attested} onChange={(event) => setAttested(event.target.checked)} />
                <span>{supervisorPart ? SUPERVISOR_ATTESTATION_TEXT : ATTESTATION_TEXT}</span>
            </label>
            <span className="pf-label" id="pf-signature-label">{signatureLabel}</span>
            <SignaturePad ref={signature} label={signatureLabel} disabled={state.submitting} onChange={setSigned} describedBy="pf-signature-label" />
            {state.message && <MessageBar intent="error" role="alert"><MessageBarBody>{state.message}</MessageBarBody></MessageBar>}
            {tried && blockers.length > 0 && <p className="pf-error" role="alert">Before submitting: {blockers.join('; ')}.</p>}
            <div className="pf-submit-row">
                <Button
                    appearance="primary" size="large" icon={state.submitting ? <LoaderCircle className="spin" size={18} /> : <Send size={18} />}
                    disabled={state.submitting || state.status === 'conflict'} aria-disabled={blockers.length > 0 || undefined} onClick={() => void submit()}
                >{state.submitting ? 'Submitting…' : submitLabel}</Button>
            </div>
        </section>

        {onDelete && state.report?.can_delete && <div className="pf-delete-row">
            <Button appearance="subtle" icon={<Trash2 size={16} />} onClick={onDelete} disabled={state.submitting}>Delete this draft</Button>
        </div>}
    </div>;
}
