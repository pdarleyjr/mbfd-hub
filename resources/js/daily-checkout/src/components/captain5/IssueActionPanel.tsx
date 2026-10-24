import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { Captain5ApiError, isRetryable, performIssueAction } from '../../utils/captain5Api';
import {
  ACTIONS, EMPTY_VALUES, REASON_LABELS, RESOLUTION_TYPES, primaryAction, toFields, validate,
  type FieldKey, type FormValues,
} from './actionForms';
import { newKey, todayYmd } from './format';
import type { IssueActionName, IssueDetail } from './types';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL } from './ui';

interface Props {
  issue: IssueDetail;
  /** The server accepted the action: this is the issue now. */
  onResult: (issue: IssueDetail, confirmation: string) => void;
  /** Someone else changed the issue first: this is the issue now. Typed text stays in the open form. */
  onConflict: (issue: IssueDetail | undefined) => void;
}

type Errors = Partial<Record<FieldKey, string>>;

interface FieldsProps {
  action: IssueActionName;
  values: FormValues;
  errors: Errors;
  disabled: boolean;
  canInternal: boolean;
  set: <K extends keyof FormValues>(key: K, value: FormValues[K]) => void;
}

function ActionFields({ action, values, errors, disabled, canInternal, set }: FieldsProps) {
  const id = useId();
  const has = (key: FieldKey) => ACTIONS[action].fields.includes(key);
  const err = (key: FieldKey) => errors[key] && <p id={`${id}-${key}-error`} role="alert" className="mt-1 text-[13px] font-semibold text-hub-danger">{errors[key]}</p>;
  const aria = (key: FieldKey) => ({ 'aria-invalid': errors[key] ? true : undefined, 'aria-describedby': errors[key] ? `${id}-${key}-error` : undefined });
  const text = (key: 'vendor_reference' | 'parts_ordered', label: string) => has(key) && (
    <div>
      <label className={LABEL} htmlFor={`${id}-${key}`}>{label}</label>
      <input id={`${id}-${key}`} className={FIELD} value={values[key]} maxLength={255} disabled={disabled} onChange={(e) => set(key, e.target.value)} />
    </div>
  );
  const area = (key: 'pickup_instructions' | 'resolution_notes' | 'reason', label: string, required = false) => has(key) && (
    <div>
      <label className={LABEL} htmlFor={`${id}-${key}`}>{label}{required && <span className="text-hub-danger"> (required)</span>}</label>
      <textarea id={`${id}-${key}`} rows={3} className={FIELD} value={values[key]} disabled={disabled} onChange={(e) => set(key, e.target.value)} {...aria(key)} />
      {err(key)}
    </div>
  );

  return (
    <>
      {text('parts_ordered', 'What was ordered')}
      {text('vendor_reference', 'Vendor reference')}
      {has('expected_delivery') && (
        <div>
          <label className={LABEL} htmlFor={`${id}-expected_delivery`}>Expected delivery</label>
          <input id={`${id}-expected_delivery`} type="date" min={todayYmd()} className={FIELD} value={values.expected_delivery} disabled={disabled} onChange={(e) => set('expected_delivery', e.target.value)} />
        </div>
      )}
      {area('pickup_instructions', 'Pickup instructions')}
      {has('resolution_type') && (
        <div>
          <label className={LABEL} htmlFor={`${id}-resolution_type`}>What happened</label>
          <select id={`${id}-resolution_type`} className={FIELD} value={values.resolution_type} disabled={disabled} onChange={(e) => set('resolution_type', e.target.value as FormValues['resolution_type'])}>
            {RESOLUTION_TYPES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </div>
      )}
      {area('resolution_notes', action === 'verify' ? 'Notes' : 'What was done', action === 'replaced')}
      {has('verified_in_place') && (
        <div>
          <label className="flex min-h-11 items-start gap-3 text-sm text-hub-ink">
            <input type="checkbox" className="mt-0.5 h-5 w-5 flex-none" checked={values.verified_in_place} disabled={disabled} onChange={(e) => set('verified_in_place', e.target.checked)} {...aria('verified_in_place')} />
            <span>I verified the replacement is in place on the unit</span>
          </label>
          {err('verified_in_place')}
        </div>
      )}
      {area('reason', REASON_LABELS[action] ?? 'Reason', true)}
      <details className="rounded-hub-control border border-hub-border bg-hub-well px-3 py-2">
        <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">Add a message (optional)</summary>
        <div className="space-y-3 pb-2">
          <div>
            <label className={LABEL} htmlFor={`${id}-member_message`}>Message to crew</label>
            <textarea id={`${id}-member_message`} rows={2} className={FIELD} value={values.member_message} disabled={disabled} onChange={(e) => set('member_message', e.target.value)} />
            <p className={HINT}>The crew who reported this will see it.</p>
          </div>
          {canInternal && (
            <div>
              <label className={LABEL} htmlFor={`${id}-internal_note`}>Internal note</label>
              <textarea id={`${id}-internal_note`} rows={2} className={FIELD} value={values.internal_note} disabled={disabled} onChange={(e) => set('internal_note', e.target.value)} />
              <p className={HINT}>Only Captain 5 and Logistics see this.</p>
            </div>
          )}
        </div>
      </details>
    </>
  );
}

export default function IssueActionPanel({ issue, onResult, onConflict }: Props) {
  const [open, setOpen] = useState<IssueActionName | null>(null);
  const [values, setValues] = useState<FormValues>(EMPTY_VALUES);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState(false);
  // One key per open form: a retry of the same form is a no-op on the server if the first try landed.
  const keyRef = useRef(newKey());
  const formRef = useRef<HTMLFormElement>(null);

  const primary = primaryAction(issue);
  const stillAllowed = open === null || issue.actions.includes(open);

  useEffect(() => { if (open) formRef.current?.querySelector<HTMLElement>('input:not([type=checkbox]),textarea,select,[type=checkbox]')?.focus(); }, [open]);

  if (issue.actions.length === 0) return null;

  const begin = (action: IssueActionName) => {
    if (action === open) return;
    keyRef.current = newKey();
    setOpen(action);
    setValues(EMPTY_VALUES);
    setErrors({});
    setMessage(null);
    setRetry(false);
  };

  const close = () => { setOpen(null); setErrors({}); setMessage(null); setRetry(false); };
  const set: FieldsProps['set'] = (key, value) => setValues((current) => ({ ...current, [key]: value }));

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!open || busy || !stillAllowed) return;
    const found = validate(open, values);
    setErrors(found);
    if (Object.keys(found).length > 0) { setMessage('Fix the highlighted field and try again.'); return; }
    setBusy(true);
    setMessage(null);
    setRetry(false);
    try {
      const updated = await performIssueAction(issue.id, {
        action: open,
        expected_state: issue.state_token,
        idempotency_key: keyRef.current,
        fields: toFields(open, values, issue.can_post_internal),
      });
      const confirmation = ACTIONS[open].done;
      close();
      setValues(EMPTY_VALUES);
      keyRef.current = newKey();
      onResult(updated, confirmation);
    } catch (error) {
      if (isApiAuthenticationError(error)) { redirectToLoginAfterSessionExpiry(); return; }
      if (error instanceof Captain5ApiError && error.isConflict) {
        setMessage('This issue was updated by someone else. The latest version is shown; check it, then confirm again. Your text is kept.');
        onConflict(error.issue);
      } else if (error instanceof Captain5ApiError && error.status === 422) {
        const mapped: Errors = {};
        for (const [field, text] of Object.entries(error.fieldErrors)) mapped[field as FieldKey] = text;
        setErrors(mapped);
        setMessage(error.message);
      } else {
        setRetry(isRetryable(error));
        setMessage(error instanceof Error ? error.message : 'The action could not be saved. Try again.');
      }
    } finally {
      setBusy(false);
    }
  };

  const others = issue.actions.filter((action) => action !== primary);

  return (
    <section aria-labelledby="c5-actions-heading" className="space-y-3" data-testid="captain5-actions">
      <h3 id="c5-actions-heading" className="font-heading text-base font-bold text-hub-ink">Actions</h3>
      <div role="group" aria-label="Issue actions" className="flex flex-wrap gap-2">
        {primary && (
          <button type="button" className={BTN_PRIMARY} aria-pressed={open === primary} onClick={() => begin(primary)}>{ACTIONS[primary].label}</button>
        )}
        {others.map((action) => (
          <button key={action} type="button" className={BTN_SECONDARY} aria-pressed={open === action} onClick={() => begin(action)}>{ACTIONS[action].label}</button>
        ))}
      </div>

      {open && (
        <form ref={formRef} onSubmit={submit} noValidate aria-label={ACTIONS[open].label} className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4" data-testid="captain5-action-form">
          <p className="text-sm text-hub-ink">{ACTIONS[open].intro}</p>
          {!stillAllowed && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">That action is no longer available for this issue. Pick another action or close this form.</p>}
          <ActionFields action={open} values={values} errors={errors} disabled={busy} canInternal={issue.can_post_internal} set={set} />
          {message && <p role="alert" data-testid="captain5-action-error" className="hub-callout hub-callout--warning p-3 text-sm">{message}</p>}
          <div className="flex flex-wrap gap-2">
            <button type="submit" className={BTN_PRIMARY} disabled={busy || !stillAllowed} aria-busy={busy}>{busy ? 'Saving…' : retry ? `Retry: ${ACTIONS[open].confirm}` : ACTIONS[open].confirm}</button>
            <button type="button" className={`${BTN} hub-btn--tertiary`} onClick={close} disabled={busy}>Cancel</button>
          </div>
        </form>
      )}
    </section>
  );
}
