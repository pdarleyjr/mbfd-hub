import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { postRequestAction, type RequestAction } from '../../../utils/emsApi';
import { NO_ACTION_LABEL } from '../actionForms';
import {
  buildCorrectionPayload, buildIssuePayload, buildPreparePayload, currentIssuances, isRequestOpen, lineCanBeMarkedNotIssued, lineCanIssue,
  lineNeedsItemChoice, outstandingQty, reasonError, requestedText, wantedQty, type FieldErrors, type IssueInput,
} from './logic';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ConfirmStep, ErrorText, FIELD, Field, HINT, LiveNote, NumberInput, useWrite } from './shared';
import type { EmsMeta, RequestDetail, RequestLine } from './types';

export type Panel =
  | { kind: 'prepare' }
  | { kind: 'issue' }
  | { kind: 'close' }
  | { kind: 'reopen' }
  | { kind: 'correct' }
  | { kind: 'not-issued'; lineId: number };

export const panelKey = (panel: Panel | null): string => (panel === null ? '' : panel.kind === 'not-issued' ? `not-issued:${panel.lineId}` : panel.kind);

interface FormProps {
  request: RequestDetail;
  onSaved: (detail: RequestDetail, notice: string | null) => void;
  /** The server says the request changed under us: refresh the sheet but keep what was typed. */
  onConflict: () => void;
  onCancel: () => void;
}

function FormShell({ label, intro, onSubmit, children, testId }: { label: string; intro?: ReactNode; onSubmit: (event: FormEvent) => void; children: ReactNode; testId: string }) {
  const ref = useRef<HTMLFormElement>(null);
  useEffect(() => {
    ref.current?.querySelector<HTMLElement>('input:not([type=hidden]),textarea,select')?.focus();
    ref.current?.scrollIntoView?.({ block: 'nearest' });
  }, []);
  return (
    <form ref={ref} onSubmit={onSubmit} noValidate aria-label={label} data-testid={testId} className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4">
      {intro && <p className="text-sm text-hub-ink">{intro}</p>}
      {children}
    </form>
  );
}

function FormButtons({ busy, retry, label, onCancel, disabled }: { busy: boolean; retry: boolean; label: string; onCancel: () => void; disabled?: boolean }) {
  return (
    <div className="flex flex-wrap gap-2">
      <button type="submit" className={BTN_PRIMARY} disabled={busy || disabled} aria-busy={busy}>{busy ? 'Saving…' : retry ? `Retry: ${label}` : label}</button>
      <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={busy} onClick={onCancel}>Cancel</button>
    </div>
  );
}

const asDetail = (request: RequestDetail) => request;
const lineCounts = (line: RequestLine) => `Requested ${requestedText(line)}, prepared ${line.prepared_qty ?? 'not set'}, issued ${line.issued_qty}`;

// ------------------------------------------------------------------------ prepare

function PrepareForm({ request, onSaved, onConflict, onCancel }: FormProps) {
  const lines = request.lines.filter((line) => wantedQty(line) > 0 && line.line_status !== 'not_issued' && line.line_status !== 'issued');
  const [values, setValues] = useState<Record<number, string>>(() => Object.fromEntries(lines.map((line) => [line.id, line.prepared_qty === null ? '' : String(line.prepared_qty)])));
  const [errors, setErrors] = useState<FieldErrors>({});
  const write = useWrite();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const { payload, errors: found } = buildPreparePayload(lines, values);
    setErrors(found);
    if (!payload) return;
    void write.run(JSON.stringify(payload), (key) => postRequestAction(request.id, 'prepare', payload, key), {
      onDone: (detail) => onSaved(detail, 'Prepared quantities saved. Nothing has left the supply room yet.'),
      onConflict,
    });
  };

  return (
    <FormShell label="Prepare quantities" testId="ems-form-prepare" onSubmit={submit}
      intro="Record what you have set aside for each item. Preparing does not take anything out of stock.">
      <button type="button" className={BTN_SECONDARY} onClick={() => setValues(Object.fromEntries(lines.map((line) => [line.id, String(wantedQty(line))])))}>Fill with requested quantities</button>
      <ul className="space-y-3">
        {lines.map((line) => (
          <li key={line.id} className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-hub-ink">{line.label}{line.variant_label ? ` (${line.variant_label})` : ''}</p>
              <p className={HINT}>{lineCounts(line)}</p>
              <ErrorText>{errors[String(line.id)]}</ErrorText>
            </div>
            <NumberInput caption="Prepared" label={`Prepared quantity for ${line.label}`} value={values[line.id] ?? ''} disabled={write.busy}
              onChange={(next) => setValues((current) => ({ ...current, [line.id]: next }))} />
          </li>
        ))}
      </ul>
      <ErrorText>{errors.form}</ErrorText>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <FormButtons busy={write.busy} retry={write.retry} label="Save prepared quantities" onCancel={onCancel} />
    </FormShell>
  );
}

// -------------------------------------------------------------------------- issue

function IssueForm({ request, onSaved, onConflict, onCancel }: FormProps) {
  const lines = request.lines.filter(lineCanIssue);
  const [inputs, setInputs] = useState<Record<number, IssueInput>>({});
  const [errors, setErrors] = useState<FieldErrors>({});
  const write = useWrite();
  const input = (line: RequestLine): IssueInput => inputs[line.id] ?? { quantity: '', supplyItemId: line.supply_candidates?.length === 1 ? String(line.supply_candidates[0].id) : '', reason: '' };
  const patch = (line: RequestLine, changes: Partial<IssueInput>) => setInputs((current) => ({ ...current, [line.id]: { ...input(line), ...current[line.id], ...changes } }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const merged = Object.fromEntries(lines.map((line) => [line.id, input(line)]));
    const { payload, errors: found } = buildIssuePayload(lines, merged);
    setErrors(found);
    if (!payload) return;
    void write.run(JSON.stringify(payload), (key) => postRequestAction(request.id, 'issue', payload as unknown as Record<string, unknown>, key), {
      onDone: (detail) => onSaved(detail, null),
      onConflict,
    });
  };

  return (
    <FormShell label="Issue items" testId="ems-form-issue" onSubmit={submit}
      intro="Enter the quantity you are handing over for each item. Items that match a supply room item take it out of stock; the rest are recorded on the request only.">
      <button type="button" className={BTN_SECONDARY}
        onClick={() => setInputs(Object.fromEntries(lines.filter((line) => outstandingQty(line) > 0).map((line) => [line.id, { ...input(line), quantity: String(outstandingQty(line)) }])))}>
        Fill what is still needed
      </button>
      <ul className="space-y-4">
        {lines.map((line) => {
          const current = input(line);
          const quantity = Number(current.quantity);
          const over = current.quantity !== '' && Number.isFinite(quantity) && line.issued_qty + quantity > wantedQty(line);
          const candidates = line.supply_candidates ?? [];
          return (
            <li key={line.id} className="space-y-2 border-b border-hub-border pb-3 last:border-b-0" data-testid="ems-issue-line">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-hub-ink">{line.label}{line.variant_label ? ` (${line.variant_label})` : ''}</p>
                  <p className={HINT}>{lineCounts(line)}. Still needed: {outstandingQty(line)}.</p>
                </div>
                <NumberInput caption="Issue now" label={`Quantity to issue for ${line.label}`} value={current.quantity} disabled={write.busy} onChange={(next) => patch(line, { quantity: next })} />
              </div>
              {line.controlled_substance || line.response_type === 'yes_no'
                ? <p className={HINT}>Controlled item. This is recorded on the request only. Narcotics are restocked through the department controlled-substance procedure.</p>
                : candidates.length === 0
                  ? <p className={HINT}>No supply room item is linked to this line, so stock will not change. It is recorded on the request only.</p>
                  : candidates.length === 1
                    ? <p className={HINT}>Comes out of stock from {candidates[0].name}{candidates[0].location_code ? ` (${candidates[0].location_code})` : ''}.</p>
                    : (
                      <Field label={`Which supply room item for ${line.label}`} required>
                        {(aria) => (
                          <select {...aria} className={FIELD} value={current.supplyItemId} disabled={write.busy} onChange={(event) => patch(line, { supplyItemId: event.target.value })}>
                            <option value="">Choose one</option>
                            {candidates.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}{candidate.location_code ? ` (${candidate.location_code})` : ''}</option>)}
                            <option value="none">Record only, do not change stock</option>
                          </select>
                        )}
                      </Field>
                    )}
              {over && (
                <Field label="Why more than was requested" required>
                  {(aria) => <input {...aria} className={FIELD} value={current.reason} maxLength={500} disabled={write.busy} onChange={(event) => patch(line, { reason: event.target.value })} />}
                </Field>
              )}
              <ErrorText>{errors[String(line.id)]}</ErrorText>
            </li>
          );
        })}
      </ul>
      <ErrorText>{errors.form}</ErrorText>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <FormButtons busy={write.busy} retry={write.retry} label="Issue these items" onCancel={onCancel} />
    </FormShell>
  );
}

// ------------------------------------------------------------------ not issued

function NotIssuedForm({ request, lineId, onSaved, onConflict, onCancel }: FormProps & { lineId: number }) {
  const line = request.lines.find((entry) => entry.id === lineId);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const write = useWrite();
  if (!line || !lineCanBeMarkedNotIssued(line)) {
    return <LiveNote tone="warning">That item can no longer be marked not issued. Close this form.</LiveNote>;
  }
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const problem = reasonError(reason, 'this item is not being issued');
    setError(problem);
    if (problem) return;
    const body = { line_id: line.id, reason: reason.trim() };
    void write.run(JSON.stringify(body), (key) => postRequestAction(request.id, 'not-issued', body, key), {
      onDone: (detail) => onSaved(detail, `${line.label} marked not issued.`),
      onConflict,
    });
  };
  return (
    <FormShell label={`Mark ${line.label} not issued`} testId="ems-form-not-issued" onSubmit={submit}
      intro={`${line.label} will not be issued on this request. The crew can see why.`}>
      <Field label="Why it is not being issued" required error={error ?? write.fields.reason}>
        {(aria) => <input {...aria} className={FIELD} value={reason} maxLength={500} disabled={write.busy} onChange={(event) => setReason(event.target.value)} placeholder="For example: out of stock" />}
      </Field>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <FormButtons busy={write.busy} retry={write.retry} label="Mark not issued" onCancel={onCancel} />
    </FormShell>
  );
}

// ------------------------------------------------------------------------ close

function CloseForm({ request, meta, onSaved, onConflict, onCancel }: FormProps & { meta: EmsMeta }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const write = useWrite();
  const open = request.lines.filter((line) => wantedQty(line) > 0 && line.line_status !== 'issued' && line.line_status !== 'not_issued').length;
  const label = (pick: string) => (pick.toLowerCase() === 'no action required' ? NO_ACTION_LABEL : `Close: ${pick}`);

  const review = (event: FormEvent) => {
    event.preventDefault();
    const problem = reasonError(reason, 'for closing this request');
    setError(problem);
    if (!problem) setConfirming(true);
  };
  const confirm = () => {
    const body = { reason: reason.trim() };
    void write.run(JSON.stringify(body), (key) => postRequestAction(request.id, 'close', body, key), {
      onDone: (detail) => onSaved(detail, 'Request closed.'),
      onConflict: () => { setConfirming(false); onConflict(); },
    });
  };

  return (
    <FormShell label="Close request" testId="ems-form-close" onSubmit={review}
      intro="Closing ends work on this request. It stays in the list under Closed and you can reopen it.">
      <div role="group" aria-label="Common reasons" className="flex flex-wrap gap-2">
        {meta.close_reasons.map((pick) => (
          <button key={pick} type="button" aria-pressed={reason === pick} className={`${reason === pick ? BTN_PRIMARY : BTN_SECONDARY} !px-3`}
            disabled={write.busy || confirming} onClick={() => { setReason(pick); setError(null); }}>{label(pick)}</button>
        ))}
      </div>
      <Field label="Reason for closing" required error={error ?? write.fields.reason}>
        {(aria) => <input {...aria} className={FIELD} value={reason} maxLength={255} disabled={write.busy || confirming} onChange={(event) => { setReason(event.target.value); setConfirming(false); }} />}
      </Field>
      {confirming ? (
        <ConfirmStep
          testId="ems-confirm-close"
          title="Close this request?"
          body={<><p>Reason: {reason.trim()}</p>{open > 0 && <p className="mt-1 font-semibold">{open} {open === 1 ? 'item has' : 'items have'} not been issued or marked not issued.</p>}</>}
          confirmLabel="Close request"
          busy={write.busy}
          error={write.error}
          retry={write.retry}
          onConfirm={confirm}
          onCancel={() => setConfirming(false)}
        />
      ) : <FormButtons busy={false} retry={false} label="Review and close" onCancel={onCancel} />}
    </FormShell>
  );
}

// ----------------------------------------------------------------------- reopen

function ReopenForm({ request, onSaved, onConflict, onCancel }: FormProps) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const write = useWrite();
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const problem = reasonError(reason, 'for reopening this request');
    setError(problem);
    if (problem) return;
    const body = { reason: reason.trim() };
    void write.run(JSON.stringify(body), (key) => postRequestAction(request.id, 'reopen', body, key), { onDone: (detail) => onSaved(detail, 'Request reopened.'), onConflict });
  };
  return (
    <FormShell label="Reopen request" testId="ems-form-reopen" onSubmit={submit} intro="Reopening puts this request back to work. The close and the reopen both stay in the history.">
      <Field label="Reason for reopening" required error={error ?? write.fields.reason}>
        {(aria) => <input {...aria} className={FIELD} value={reason} maxLength={255} disabled={write.busy} onChange={(event) => setReason(event.target.value)} />}
      </Field>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <FormButtons busy={write.busy} retry={write.retry} label="Reopen request" onCancel={onCancel} />
    </FormShell>
  );
}

// ---------------------------------------------------------------------- correct

function CorrectForm({ request, onSaved, onConflict, onCancel }: FormProps) {
  const issuances = useMemo(() => currentIssuances(request.history), [request.history]);
  const [eventId, setEventId] = useState<number | null>(issuances.length === 1 ? issuances[0].eventId : null);
  const [quantity, setQuantity] = useState(issuances.length === 1 ? String(issuances[0].quantity) : '');
  const [itemId, setItemId] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [confirming, setConfirming] = useState(false);
  const write = useWrite();
  const chosen = issuances.find((issuance) => issuance.eventId === eventId) ?? null;
  const line = chosen ? request.lines.find((entry) => entry.id === chosen.lineId) ?? null : null;
  const candidates = line?.supply_candidates ?? [];
  const groupId = useId();

  const review = (event: FormEvent) => {
    event.preventDefault();
    if (!chosen) { setErrors({ issuance: 'Choose the issuance to correct.' }); return; }
    const { payload, errors: found } = buildCorrectionPayload(chosen, quantity, itemId, reason);
    setErrors(found);
    if (payload) setConfirming(true);
  };
  const confirm = () => {
    if (!chosen) return;
    const { payload } = buildCorrectionPayload(chosen, quantity, itemId, reason);
    if (!payload) return;
    void write.run(JSON.stringify(payload), (key) => postRequestAction(request.id, 'correct', payload as unknown as Record<string, unknown>, key), {
      onDone: (detail) => onSaved(detail, 'Correction recorded. The original entry stays in the history.'),
      onConflict: () => { setConfirming(false); onConflict(); },
    });
  };

  if (issuances.length === 0) return <LiveNote>Nothing has been issued on this request yet, so there is nothing to correct.</LiveNote>;

  return (
    <FormShell label="Correct an issuance" testId="ems-form-correct" onSubmit={review}
      intro="Fix a quantity or an item that was entered wrong. The original entry stays in the history and stock is adjusted by a new entry.">
      <fieldset className="space-y-2">
        <legend className={`${HINT} font-semibold`}>Which issuance</legend>
        {issuances.map((issuance) => (
          <label key={issuance.eventId} htmlFor={`${groupId}-${issuance.eventId}`} className="flex min-h-11 items-start gap-3 rounded-hub-control border border-hub-border bg-hub-well p-2.5 text-sm text-hub-ink">
            <input id={`${groupId}-${issuance.eventId}`} type="radio" name={`${groupId}-issuance`} className="mt-0.5 h-5 w-5 flex-none" checked={eventId === issuance.eventId} disabled={write.busy || confirming}
              onChange={() => { setEventId(issuance.eventId); setQuantity(String(issuance.quantity)); setItemId(''); setConfirming(false); }} />
            <span>{issuance.label}: {issuance.quantity} issued{issuance.corrected ? ` (was ${issuance.originalQuantity})` : ''}{issuance.supplyItemName ? ` from ${issuance.supplyItemName}` : ''}</span>
          </label>
        ))}
        <ErrorText>{errors.issuance}</ErrorText>
      </fieldset>
      {chosen && (
        <>
          <Field label="Correct quantity" required error={errors.new_quantity ?? write.fields.new_quantity}>
            {(aria) => <NumberInput {...aria} value={quantity} disabled={write.busy || confirming} onChange={(next) => { setQuantity(next); setConfirming(false); }} />}
          </Field>
          {candidates.length > 0 && (
            <Field label="Supply room item" hint="Leave as it is unless the wrong item was used.">
              {(aria) => (
                <select {...aria} className={FIELD} value={itemId} disabled={write.busy || confirming} onChange={(event) => { setItemId(event.target.value); setConfirming(false); }}>
                  <option value="">Keep the current item</option>
                  {candidates.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}{candidate.location_code ? ` (${candidate.location_code})` : ''}</option>)}
                </select>
              )}
            </Field>
          )}
          <Field label="Why it is being corrected" required error={errors.reason ?? write.fields.reason}>
            {(aria) => <input {...aria} className={FIELD} value={reason} maxLength={500} disabled={write.busy || confirming} onChange={(event) => { setReason(event.target.value); setConfirming(false); }} />}
          </Field>
        </>
      )}
      {confirming && chosen ? (
        <ConfirmStep
          testId="ems-confirm-correct"
          title="Record this correction?"
          body={<p>{chosen.label}: from {chosen.quantity} to {quantity}. Reason: {reason.trim()}. If this item changes supply room stock, the stock is adjusted to match.</p>}
          confirmLabel="Record correction"
          busy={write.busy}
          error={write.error}
          retry={write.retry}
          onConfirm={confirm}
          onCancel={() => setConfirming(false)}
        />
      ) : <FormButtons busy={false} retry={false} label="Review correction" onCancel={onCancel} disabled={!chosen} />}
    </FormShell>
  );
}

// ------------------------------------------------------------------------ panel

interface Props {
  request: RequestDetail;
  meta: EmsMeta;
  panel: Panel | null;
  onPanel: (panel: Panel | null) => void;
  onSaved: (detail: RequestDetail, notice: string | null) => void;
  onConflict: () => void;
}

export default function RequestActions({ request, meta, panel, onPanel, onSaved, onConflict }: Props) {
  const reviewWrite = useWrite();
  if (!meta.permissions.act) {
    return <p className={HINT} data-testid="ems-read-only">You can read this request. Your access does not include changing it.</p>;
  }
  const open = isRequestOpen(request);
  const hasIssuances = request.history.some((event) => event.action === 'issued');
  const common = { request: asDetail(request), onSaved: (detail: RequestDetail, notice: string | null) => { onPanel(null); onSaved(detail, notice); }, onConflict, onCancel: () => onPanel(null) };
  const toggle = (next: Panel) => onPanel(panelKey(panel) === panelKey(next) ? null : next);
  const act = (kind: RequestAction) => reviewWrite.run(kind, (key) => postRequestAction(request.id, kind, {}, key), { onDone: (detail) => onSaved(detail, 'Marked as reviewed.'), onConflict });

  return (
    <section aria-labelledby="ems-actions-heading" className="space-y-3" data-testid="ems-actions">
      <h3 id="ems-actions-heading" className="font-heading text-base font-bold text-hub-ink">Actions</h3>
      <div role="group" aria-label="Request actions" className="flex flex-wrap gap-2">
        {open && request.status === 'submitted' && (
          <button type="button" className={BTN_PRIMARY} disabled={reviewWrite.busy} aria-busy={reviewWrite.busy} onClick={() => void act('review')}>Mark reviewed</button>
        )}
        {open && <button type="button" className={request.status === 'submitted' ? BTN_SECONDARY : BTN_PRIMARY} aria-pressed={panel?.kind === 'prepare'} onClick={() => toggle({ kind: 'prepare' })}>Prepare</button>}
        {open && <button type="button" className={BTN_SECONDARY} aria-pressed={panel?.kind === 'issue'} onClick={() => toggle({ kind: 'issue' })}>Issue</button>}
        {hasIssuances && <button type="button" className={BTN_SECONDARY} aria-pressed={panel?.kind === 'correct'} onClick={() => toggle({ kind: 'correct' })}>Correct an issuance</button>}
        {open && <button type="button" className={BTN_SECONDARY} aria-pressed={panel?.kind === 'close'} onClick={() => toggle({ kind: 'close' })}>Close request</button>}
        {request.status === 'closed' && <button type="button" className={BTN_PRIMARY} aria-pressed={panel?.kind === 'reopen'} onClick={() => toggle({ kind: 'reopen' })}>Reopen</button>}
        {request.status === 'cancelled' && <p className={HINT}>The member cancelled this request, so it cannot be changed.</p>}
      </div>
      <LiveNote tone="warning">{reviewWrite.error}</LiveNote>

      {panel?.kind === 'prepare' && <PrepareForm key="prepare" {...common} />}
      {panel?.kind === 'issue' && <IssueForm key="issue" {...common} />}
      {panel?.kind === 'not-issued' && <NotIssuedForm key={`ni-${panel.lineId}`} lineId={panel.lineId} {...common} />}
      {panel?.kind === 'close' && <CloseForm key="close" meta={meta} {...common} />}
      {panel?.kind === 'reopen' && <ReopenForm key="reopen" {...common} />}
      {panel?.kind === 'correct' && <CorrectForm key="correct" {...common} />}
    </section>
  );
}
