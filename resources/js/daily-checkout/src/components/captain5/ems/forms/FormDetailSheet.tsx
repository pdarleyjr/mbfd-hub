import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../../utils/api';
import { EmsApiError, failureMessage } from '../../../../utils/emsApi';
import { LoadError } from '../../../ui/StateViews';
import Tag from '../../../ui/Tag';
import { Fact, Sheet } from '../../ui';
import { etDateTime, groupLines, memberText, targetText, LINE_TONE, lineFlags, requestedText } from '../logic';
import { RequestMessages } from '../RequestThread';
import { BTN_SECONDARY, HINT, LiveNote } from '../shared';
import { fetchForm } from './api';
import FormActionButtons from './FormActionButtons';
import {
  ackText, archiveText, formEventSentence, FORM_STATUS_TONE, isOlderRecord, isReturned, isWorkingStatus, returnedText, revisionTag,
} from './logic';
import RejectSheet, { type RejectTarget } from './RejectSheet';
import type { FormDetail } from './types';
import type { RequestLine } from '../types';

interface Props {
  uuid: string | null;
  canAct: boolean;
  /** Bumped by the page about once a minute: the open form refreshes quietly. */
  tick: number;
  onOpenForm: (uuid: string) => void;
  onClose: () => void;
  /** The form changed on the server: the list behind the sheet and the tab badge refresh. */
  onChanged: () => void;
  onForbidden: () => void;
}

function LineItem({ line, form }: { line: RequestLine; form: FormDetail }) {
  const older = isOlderRecord(form);
  const taken = form.status === 'reconciled' || line.issued_qty > 0;
  const flags = lineFlags(line);
  return (
    <li data-testid="ems-form-line" className="space-y-1.5 rounded-hub-control border border-hub-border bg-hub-well p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{line.label}{line.variant_label ? ` (${line.variant_label})` : ''}</p>
        <p className="font-semibold tabular-nums text-hub-ink">{requestedText(line)}</p>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        {flags.map((flag) => <Tag key={flag} tone="info">{flag}</Tag>)}
        {older && line.line_status !== 'open' && <Tag tone={LINE_TONE[line.line_status] ?? 'neutral'}>{line.line_status_label}</Tag>}
        {taken && <span className={HINT}>{line.issued_qty > 0 ? `Taken ${line.issued_qty}` : 'Not taken'}</span>}
      </div>
    </li>
  );
}

function StateCallout({ form }: { form: FormDetail }) {
  if (isReturned(form)) {
    return (
      <div className="space-y-1 rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-3" data-testid="ems-form-state">
        <p className="font-semibold text-hub-danger">{returnedText(form)}</p>
        {form.returned_at && <p className={HINT}>{etDateTime(form.returned_at)}. It stays here until the member sends it again.</p>}
      </div>
    );
  }
  const text = isWorkingStatus(form.status) ? ackText(form) : archiveText(form);
  return <p className="text-sm font-semibold text-hub-ink" data-testid="ems-form-state">{text}</p>;
}

/** The full record of one form. Reads for anything Captain 5 can see; acts only while the form is still being worked. */
export default function FormDetailSheet({ uuid, canAct, tick, onOpenForm, onClose, onChanged, onForbidden }: Props) {
  const [form, setForm] = useState<FormDetail | null>(null);
  const [error, setError] = useState<EmsApiError | null>(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<RejectTarget | null>(null);
  const current = useRef<string | null>(null);

  const load = useCallback(async (id: string, silent = false) => {
    if (!silent) setLoading(true);
    try {
      const detail = await fetchForm(id);
      if (current.current !== id) return;
      setForm(detail);
      setError(null);
    } catch (failure) {
      if (current.current !== id) return;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setError(failure instanceof EmsApiError ? failure : new EmsApiError(failureMessage(failure, 'The form could not be loaded.'), 0));
    } finally {
      if (current.current === id) setLoading(false);
    }
  }, []);

  useEffect(() => {
    current.current = uuid;
    setForm(null);
    setError(null);
    setNotice(null);
    setRejecting(null);
    if (uuid !== null) void load(uuid);
  }, [uuid, load]);

  const seenTick = useRef(tick);
  useEffect(() => {
    if (tick === seenTick.current) return;
    seenTick.current = tick;
    if (uuid !== null && form !== null) void load(uuid, true);
  }, [tick, uuid, form, load]);

  useEffect(() => { if (error?.status === 403) onForbidden(); }, [error, onForbidden]);

  const saved = (detail: FormDetail, text: string) => {
    setForm(detail);
    setNotice(text);
    onChanged();
  };
  const conflict = () => {
    setNotice('Someone else changed this form. This is the latest version. Check it, then try again.');
    if (uuid !== null) void load(uuid, true);
    onChanged();
  };

  const unavailable = error !== null && (error.status === 403 || error.status === 404);
  const working = form !== null && isWorkingStatus(form.status);
  const readOnly = form !== null && !working && !isReturned(form);
  const revision = form ? revisionTag(form) : null;

  return (
    <>
      <Sheet
        open={uuid !== null}
        onClose={onClose}
        testId="ems-form-sheet"
        title={form ? `${form.number}: ${targetText(form)}` : 'Submitted inventory form'}
        description={form ? `${memberText(form)} · ${etDateTime(form.submitted_at)}` : undefined}
      >
        {loading && !form && <div role="status" aria-label="Loading form" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-32 w-full" /></div>}
        {unavailable && (
          <div className="hub-callout p-4" role="alert" data-testid="ems-form-unavailable">
            <p className="font-semibold text-hub-ink">This form is not available to you</p>
            <p className="text-sm text-hub-n-600">It may have been removed, or your access does not include it.</p>
            <button type="button" className={`${BTN_SECONDARY} mt-3`} onClick={onClose}>Back to the list</button>
          </div>
        )}
        {error && !unavailable && !form && uuid !== null && (
          <LoadError title="The form could not be loaded" message={error.message} onRetry={() => void load(uuid)}>
            <button type="button" className={BTN_SECONDARY} onClick={onClose}>Close</button>
          </LoadError>
        )}

        {form && (
          <div className="space-y-6">
            <div className="flex flex-wrap items-center gap-1.5">
              <Tag tone={FORM_STATUS_TONE[form.status] ?? 'neutral'}>{form.status_label}</Tag>
              {revision && <Tag tone="info">{revision}</Tag>}
              {readOnly && <Tag>Read only</Tag>}
            </div>

            <div aria-live="polite" data-testid="ems-form-notice">
              {notice && <p role="status" className="hub-callout p-3 text-sm font-semibold">{notice}</p>}
            </div>
            {error && <LiveNote tone="warning">{error.message} What you see may be out of date.</LiveNote>}

            <StateCallout form={form} />

            {working && (
              <FormActionButtons
                form={form}
                canAct={canAct}
                testIdPrefix="ems-sheet"
                onAcknowledged={(detail) => saved(detail, 'Acknowledged. You can now select this form on the list to reconcile it.')}
                onReject={() => setRejecting({ id: form.id, member: form.submitter_name, number: form.number })}
                onConflict={conflict}
              />
            )}

            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="ems-form-summary">
              <Fact label="Unit">{targetText(form)}</Fact>
              <Fact label="Member">{memberText(form)}</Fact>
              <Fact label="Submitted">{etDateTime(form.submitted_at)}</Fact>
              <Fact label="Number">{form.number}</Fact>
              <Fact label="Member's note">{form.member_note}</Fact>
              <Fact label="Reconciled">{form.reconciled_at ? `${etDateTime(form.reconciled_at)}${form.reconciled_by ? ` by ${form.reconciled_by}` : ''}` : null}</Fact>
            </dl>

            <section aria-labelledby="ems-form-lines-heading" className="space-y-4" data-testid="ems-form-lines">
              <h3 id="ems-form-lines-heading" className="font-heading text-base font-bold text-hub-ink">Items on this form</h3>
              {form.lines.length === 0 && <p className={HINT}>This form has no items. Read the member&apos;s note above.</p>}
              {groupLines(form.lines).map((group) => (
                <div key={group.bagKey} className="space-y-3">
                  <h4 className="font-semibold text-hub-ink">{group.bagLabel}</h4>
                  {group.sections.map((section) => (
                    <div key={section.label} className="space-y-2">
                      <p className={`${HINT} font-semibold uppercase tracking-wide`}>{section.label}</p>
                      <ul className="space-y-2" aria-label={`${group.bagLabel}, ${section.label}`}>
                        {section.lines.map((line) => <LineItem key={line.id} line={line} form={form} />)}
                      </ul>
                    </div>
                  ))}
                </div>
              ))}
            </section>

            {form.revisions.length > 1 && (
              <section aria-labelledby="ems-form-revisions-heading" className="space-y-2" data-testid="ems-form-revisions">
                <h3 id="ems-form-revisions-heading" className="font-heading text-base font-bold text-hub-ink">Versions of this form</h3>
                <ul className="space-y-2">
                  {form.revisions.map((entry, index) => (
                    <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2 rounded-hub-control border border-hub-border bg-hub-well p-3">
                      <div className="min-w-0">
                        <p className="break-words font-semibold text-hub-ink">{entry.number}</p>
                        <p className={HINT}>{entry.status_label} · {etDateTime(entry.submitted_at)}{index === form.revisions.length - 1 ? ' · Newest' : ''}</p>
                      </div>
                      {entry.id === form.id
                        ? <Tag tone="info">Showing this one</Tag>
                        : <button type="button" className={BTN_SECONDARY} aria-label={`Open ${entry.number}`} onClick={() => onOpenForm(entry.id)}>Open</button>}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            <section aria-labelledby="ems-form-history-heading" className="space-y-3" data-testid="ems-form-history">
              <h3 id="ems-form-history-heading" className="font-heading text-base font-bold text-hub-ink">History</h3>
              {form.history.length === 0 ? <p className={HINT}>Nothing recorded yet.</p> : (
                <ol className="hub-timeline space-y-3 pl-4" aria-label="Form history">
                  {form.history.map((event) => (
                    <li key={event.id} data-testid="ems-form-history-item" className="space-y-0.5">
                      <p className="text-sm text-hub-ink">{formEventSentence(event)}</p>
                      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] text-hub-n-600">
                        <span>{[event.actor_rank, event.actor_name].filter(Boolean).join(' ') || 'System'} ({event.actor_context_label})</span>
                        <time dateTime={event.created_at ?? undefined}>{etDateTime(event.created_at)}</time>
                      </p>
                    </li>
                  ))}
                </ol>
              )}
            </section>

            <details className="rounded-hub-control border border-hub-border" data-testid="ems-form-messages">
              <summary className="flex min-h-11 cursor-pointer items-center px-3 font-semibold text-hub-ink">Messages ({form.messages.length})</summary>
              <div className="px-3 pb-3">
                <RequestMessages requestId={form.id} messages={form.messages} canPost={canAct && (working || isReturned(form))} onPosted={() => void load(form.id, true)} />
              </div>
            </details>
          </div>
        )}
      </Sheet>
      <RejectSheet
        target={rejecting}
        onClose={() => setRejecting(null)}
        onConflict={conflict}
        onDone={(detail) => { setRejecting(null); saved(detail, `Sent back to ${detail.submitter_name}. It stays in Active as returned.`); }}
      />
    </>
  );
}
