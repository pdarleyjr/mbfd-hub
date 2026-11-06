import { memo, type ReactNode } from 'react';
import { AlertTriangle, Check, CloudOff, Info, LoaderCircle } from 'lucide-react';
import { YES_NO, YES_NO_NA, characterCount, cleanNumber, displayValue, isBlank, isFieldVisible, saveStatusLabel, toggleListValue } from './personnelLogic.js';
import type { SaveStatus } from './personnelTypes';

/** One field of an official form, as personnelLogic.js describes it. */
export interface FieldDef {
    key: string;
    label: string;
    kind: 'text' | 'textarea' | 'date' | 'time' | 'tel' | 'number' | 'yes_no' | 'yes_no_na' | 'causes' | 'actions';
    max?: number;
    required?: boolean;
    hint?: string;
    when?: { key: string; includes?: string; equals?: string };
    group?: string;
    wide?: boolean;
}

export interface StepDef {
    id: string;
    title: string;
    intro?: string;
    fields: FieldDef[];
    widget?: 'subject' | 'vehicle' | 'supervisor' | 'driver-summary' | 'review';
    requiredMeta?: string[];
}

export function SaveIndicator({ status }: { status: SaveStatus }) {
    const label = saveStatusLabel(status);
    const icon = status === 'saving' ? <LoaderCircle className="spin" size={15} aria-hidden="true" />
        : status === 'saved' ? <Check size={15} aria-hidden="true" />
            : status === 'offline' ? <CloudOff size={15} aria-hidden="true" />
                : status === 'conflict' || status === 'error' ? <AlertTriangle size={15} aria-hidden="true" /> : null;
    return <span className={`pf-save is-${status}`} role="status" aria-live="polite">{label ? <>{icon}{label}</> : null}</span>;
}

export function Notice({ tone = 'info', title, children }: { tone?: 'info' | 'warning' | 'important'; title?: string; children: ReactNode }) {
    return <div className={`pf-notice is-${tone}`}>
        {tone === 'info' ? <Info size={18} aria-hidden="true" /> : <AlertTriangle size={18} aria-hidden="true" />}
        <div>{title && <strong>{title}</strong>}<div className="pf-notice-body">{children}</div></div>
    </div>;
}

export function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) return null;
    return <p className="pf-error" id={id}><AlertTriangle size={15} aria-hidden="true" />{message}</p>;
}

function Label({ htmlFor, field }: { htmlFor?: string; field: FieldDef }) {
    const content = <>{field.label}{field.required ? <span className="pf-required">Required</span> : <span className="pf-optional">Optional</span>}</>;
    return htmlFor ? <label className="pf-label" htmlFor={htmlFor}>{content}</label> : <span className="pf-label">{content}</span>;
}

export interface FieldControlProps {
    field: FieldDef;
    value: unknown;
    error?: string;
    choices?: Record<string, string>;
    maxDate?: string;
    onChange: (key: string, value: unknown) => void;
}

/** Renders one field with its official label, hint, limit and error. */
export const FieldControl = memo(function FieldControl({ field, value, error, choices, maxDate, onChange }: FieldControlProps) {
    const id = `pf-${field.key}`;
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [field.hint ? hintId : '', error ? errorId : ''].filter(Boolean).join(' ') || undefined;
    const text = typeof value === 'string' ? value : value === null || value === undefined ? '' : String(value);
    const className = `pf-field${field.wide || field.kind === 'textarea' || field.kind === 'causes' || field.kind === 'actions' ? ' is-wide' : ''}`;
    const hint = field.hint ? <p className="pf-hint" id={hintId}>{field.hint}</p> : null;

    if (field.kind === 'yes_no' || field.kind === 'yes_no_na') {
        const options = field.kind === 'yes_no_na' ? YES_NO_NA : YES_NO;
        return <fieldset className={`${className} pf-question`} aria-describedby={describedBy} aria-invalid={error ? true : undefined}>
            <legend className="pf-label">{field.label}{field.required ? <span className="pf-required">Required</span> : <span className="pf-optional">Optional</span>}</legend>
            {hint}
            <div className="pf-segmented">
                {options.map((option) => <label key={option.value} className={`pf-segment${value === option.value ? ' is-on' : ''}`}>
                    <input type="radio" name={id} value={option.value} checked={value === option.value} onChange={() => onChange(field.key, option.value)} />
                    <span>{option.label}</span>
                </label>)}
                {!field.required && !isBlank(value) && <button type="button" className="pf-link pf-clear-answer" onClick={() => onChange(field.key, null)}>Clear answer</button>}
            </div>
            <FieldError id={errorId} message={error} />
        </fieldset>;
    }

    if (field.kind === 'causes' || field.kind === 'actions') {
        const order = Object.keys(choices ?? {});
        const selected = Array.isArray(value) ? value as string[] : [];
        return <fieldset className={`${className} pf-checks`} aria-describedby={describedBy}>
            <legend className="pf-label">{field.label}</legend>
            {hint}
            <div className="pf-check-grid">
                {order.map((key) => <label key={key} className={`pf-check${selected.includes(key) ? ' is-on' : ''}`}>
                    <input type="checkbox" checked={selected.includes(key)} onChange={(event) => onChange(field.key, toggleListValue(selected, key, event.target.checked, order))} />
                    <span>{choices?.[key]}</span>
                </label>)}
            </div>
            <FieldError id={errorId} message={error} />
        </fieldset>;
    }

    if (field.kind === 'textarea') {
        const count = field.max ? characterCount(text, field.max) : null;
        const countId = `${id}-count`;
        const rows = (field.max ?? 0) >= 2000 ? 8 : 3;
        return <div className={className}>
            <Label htmlFor={id} field={field} />
            {hint}
            <textarea
                id={id} className="pf-input pf-textarea" rows={rows} maxLength={field.max} value={text}
                aria-describedby={[describedBy, count ? countId : ''].filter(Boolean).join(' ') || undefined} aria-invalid={error ? true : undefined} aria-required={field.required || undefined}
                onChange={(event) => onChange(field.key, event.target.value)}
            />
            {count && (field.max ?? 0) >= 500 && <p className={`pf-count${count.near ? ' is-near' : ''}`} id={countId}>{count.text}</p>}
            <FieldError id={errorId} message={error} />
        </div>;
    }

    const inputType = field.kind === 'date' ? 'date' : field.kind === 'time' ? 'time' : field.kind === 'tel' ? 'tel' : 'text';
    return <div className={className}>
        <Label htmlFor={id} field={field} />
        {hint}
        <input
            id={id} className="pf-input" type={inputType} value={text} autoComplete="off"
            maxLength={field.kind === 'date' || field.kind === 'time' ? undefined : field.max}
            max={field.kind === 'date' ? maxDate : undefined} min={field.kind === 'date' ? '1990-01-02' : undefined}
            inputMode={field.kind === 'number' ? 'decimal' : field.kind === 'tel' ? 'tel' : undefined}
            aria-describedby={describedBy} aria-invalid={error ? true : undefined} aria-required={field.required || undefined}
            onChange={(event) => onChange(field.key, field.kind === 'number' ? cleanNumber(event.target.value, field.max ?? 20) : event.target.value)}
        />
        <FieldError id={errorId} message={error} />
    </div>;
});

/** The visible fields of a step, in form order, with a heading for each group that has one. */
export function StepFields({ step, values, errors, choices, maxDate, groupTitles, onChange, hiddenGroups = [] }: {
    step: StepDef; values: Record<string, unknown>; errors: Record<string, string>; choices?: Record<string, Record<string, string>>;
    maxDate?: string; groupTitles?: Record<string, string>; onChange: (key: string, value: unknown) => void; hiddenGroups?: string[];
}) {
    const out: ReactNode[] = [];
    let currentGroup: string | undefined;
    for (const field of step.fields) {
        if (!isFieldVisible(field, values)) continue;
        if (field.group && hiddenGroups.includes(field.group)) continue;
        if (field.group !== currentGroup) {
            currentGroup = field.group;
            if (field.group && groupTitles?.[field.group]) out.push(<h3 className="pf-group-title" key={`group-${field.group}`}>{groupTitles[field.group]}</h3>);
        }
        out.push(<FieldControl
            key={field.key} field={field} value={values[field.key]} error={errors[field.key]} maxDate={maxDate}
            choices={field.kind === 'causes' ? choices?.causes : field.kind === 'actions' ? choices?.actions : undefined} onChange={onChange}
        />);
    }
    return <div className="pf-fields">{out}</div>;
}

/** A read-only list of answers, for the review step and a submitted report. */
export function AnswerList({ fields, values, choices }: { fields: FieldDef[]; values: Record<string, unknown>; choices?: Record<string, Record<string, string>> }) {
    const rows = fields.filter((field) => isFieldVisible(field, values));
    return <dl className="pf-answers">
        {rows.map((field) => {
            const shown = displayValue(field, values[field.key], field.kind === 'causes' ? choices?.causes : field.kind === 'actions' ? choices?.actions : undefined);
            return <div key={field.key} className={field.kind === 'textarea' ? 'is-long' : undefined}>
                <dt>{field.label}</dt>
                <dd className={shown ? undefined : 'is-empty'}>{shown || 'Not entered'}</dd>
            </div>;
        })}
    </dl>;
}
