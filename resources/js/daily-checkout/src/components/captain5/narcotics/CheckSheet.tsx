import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { CheckCircle2, XCircle, ZoomIn } from 'lucide-react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../../utils/api';
import { EmsApiError, failureMessage, isForbidden } from '../../../utils/emsApi';
import { approveCheck, fetchCheck, rejectCheck } from '../../../utils/narcoticsApi';
import { LoadError } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { Fact, Sheet } from '../ui';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, ErrorText, FIELD, HINT, LABEL, LiveNote, useWrite } from '../ems/shared';
import PhotoZoom from './PhotoZoom';
import { REJECT_NOTE_MAX, STATUS_TONE, decisionText, etDateTime, memberText, rejectError, rescueUnitText, sealNumberText } from './logic';
import type { NarcoticsCheckDetail } from './types';

interface Props {
  uuid: string | null;
  onClose: () => void;
  /** A decision was made (or found already made): refresh the lists behind the sheet and the tab badge. */
  onChanged: () => void;
  /** Open another check (the earlier rejected one, or the new photo that answers it). */
  onOpen: (uuid: string) => void;
  onForbidden: () => void;
}

const ACTION_TEXT: Record<string, string> = {
  submitted: 'Sent by the member', viewed: 'Opened', approved: 'Approved and archived', rejected: 'Rejected and archived', images_purged: 'Photo removed (retention)',
};

function RejectForm({ onCancel, onSubmit, write }: { onCancel: () => void; onSubmit: (explanation: string) => void; write: ReturnType<typeof useWrite> }) {
  const [explanation, setExplanation] = useState('');
  const field = useRef<HTMLTextAreaElement>(null);
  useEffect(() => { field.current?.focus(); field.current?.scrollIntoView?.({ block: 'nearest' }); }, []);
  const problem = rejectError(explanation);
  const submit = (event: FormEvent) => { event.preventDefault(); if (!problem) onSubmit(explanation); };

  return (
    <form onSubmit={submit} noValidate aria-label="Reject this narcotics check" data-testid="narcotics-reject-form" className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4">
      <div>
        <label className={LABEL} htmlFor="narcotics-reject-note">Why is it being rejected? <span className="font-normal text-hub-n-600">(optional)</span></label>
        <textarea
          id="narcotics-reject-note"
          ref={field}
          className={`${FIELD} min-h-24`}
          rows={3}
          value={explanation}
          disabled={write.busy}
          onChange={(event) => setExplanation(event.target.value)}
          aria-invalid={problem ? true : undefined}
          aria-describedby="narcotics-reject-hint"
          placeholder="For example: the seal number is not readable"
        />
        <p id="narcotics-reject-hint" className={`${HINT} mt-1`}>The member sees this and can send a new photo. {explanation.length} / {REJECT_NOTE_MAX}</p>
        <ErrorText>{problem}</ErrorText>
      </div>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={BTN_PRIMARY} disabled={write.busy || problem !== null} aria-busy={write.busy}>
          {write.busy ? 'Saving…' : write.retry ? 'Retry: Reject check' : 'Reject check'}
        </button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={write.busy} onClick={onCancel}>Go back</button>
      </div>
    </form>
  );
}

/** One narcotics check: the sharp photo with zoom, the member's note, and one-action Approve & Archive or Reject. */
export default function CheckSheet({ uuid, onClose, onChanged, onOpen, onForbidden }: Props) {
  const [check, setCheck] = useState<NarcoticsCheckDetail | null>(null);
  const [error, setError] = useState<EmsApiError | null>(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'info' | 'warning'; text: string } | null>(null);
  const [rejecting, setRejecting] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const approve = useWrite();
  const reject = useWrite();
  const latest = useRef(0);

  const load = useCallback(async (id: string, silent = false) => {
    const mine = ++latest.current;
    if (!silent) setLoading(true);
    try {
      const detail = await fetchCheck(id);
      if (mine !== latest.current) return null;
      setCheck(detail);
      setError(null);
      return detail;
    } catch (failure) {
      if (mine !== latest.current) return null;
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return null; }
      if (isForbidden(failure)) { onForbidden(); return null; }
      setError(failure instanceof EmsApiError ? failure : new EmsApiError(failureMessage(failure, 'The narcotics check could not be loaded.'), 0));
      return null;
    } finally {
      if (mine === latest.current) setLoading(false);
    }
  }, [onForbidden]);

  useEffect(() => {
    setCheck(null); setError(null); setNotice(null); setRejecting(false); setZoomed(false);
    approve.clear(); reject.clear();
    if (uuid !== null) void load(uuid);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [uuid, load]);

  /** Someone else decided first: show the latest, say so, keep the list honest. */
  const conflict = (failure: EmsApiError) => {
    setNotice({ tone: 'warning', text: `${failure.message} This is the latest version.` });
    setRejecting(false);
    if (uuid !== null) void load(uuid, true);
    onChanged();
  };

  const saved = (result: { check: NarcoticsCheckDetail; changed: boolean }, text: string) => {
    setCheck(result.check);
    setRejecting(false);
    setNotice({ tone: 'info', text: result.changed ? text : 'Another Captain 5 already made this decision. This is what was recorded.' });
    onChanged();
  };

  const doApprove = () => {
    if (uuid === null) return;
    void approve.run('approve', () => approveCheck(uuid), { onDone: (result) => saved(result, 'Approved and archived. The member was told.'), onConflict: conflict });
  };

  const doReject = (explanation: string) => {
    if (uuid === null) return;
    void reject.run(`reject:${explanation}`, () => rejectCheck(uuid, explanation), { onDone: (result) => saved(result, 'Rejected and archived. The member was told.'), onConflict: conflict });
  };

  const unavailable = error !== null && (error.status === 403 || error.status === 404);
  const pending = check?.status === 'pending';
  const decision = check ? decisionText(check) : null;

  return (
    <Sheet
      open={uuid !== null}
      onClose={onClose}
      testId="narcotics-sheet"
      title={check ? `Narcotics check: ${check.submitter_name}` : 'Narcotics check'}
      description={check ? `${memberText(check)} · Sent ${etDateTime(check.submitted_at)}` : undefined}
    >
      {loading && !check && <div role="status" aria-label="Loading narcotics check" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-64 w-full" /></div>}

      {unavailable && (
        <div className="hub-callout p-4" role="alert" data-testid="narcotics-unavailable">
          <p className="font-semibold text-hub-ink">This narcotics check is not available to you</p>
          <p className="text-sm text-hub-n-600">It may have been removed, or your access does not include it.</p>
          <button type="button" className={`${BTN_SECONDARY} mt-3`} onClick={onClose}>Back to the list</button>
        </div>
      )}
      {error && !unavailable && !check && uuid !== null && (
        <LoadError title="The narcotics check could not be loaded" message={error.message} onRetry={() => void load(uuid)}>
          <button type="button" className={BTN_SECONDARY} onClick={onClose}>Close</button>
        </LoadError>
      )}

      {check && (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-1.5">
            <Tag tone={STATUS_TONE[check.status]}>{check.status_label}</Tag>
            {check.archived && <Tag>Archived</Tag>}
            {check.resubmission_of && <Tag tone="warning">Sent again after a rejection</Tag>}
          </div>

          <div aria-live="polite" data-testid="narcotics-notice">
            {notice && <p role={notice.tone === 'warning' ? 'alert' : 'status'} className={`hub-callout p-3 text-sm font-semibold ${notice.tone === 'warning' ? 'hub-callout--warning' : ''}`}>{notice.text}</p>}
          </div>
          {error && <LiveNote tone="warning">{error.message} What you see may be out of date.</LiveNote>}

          {check.image.available && check.image.preview_url ? (
            <section aria-label="Photo" className="space-y-2">
              <button
                type="button"
                className="group relative block w-full cursor-zoom-in overflow-hidden rounded-hub-control border border-hub-border bg-hub-n-900"
                onClick={() => setZoomed(true)}
                aria-label="Zoom in on the photo"
                data-testid="narcotics-photo"
              >
                <img src={check.image.preview_url} alt={`Narcotics check photo from ${check.submitter_name}, sent ${etDateTime(check.submitted_at)}`} className="mx-auto block max-h-[70vh] w-full object-contain" />
                <span className="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full border border-hub-border bg-hub-n-0 px-3 py-1 text-xs font-semibold text-hub-ink"><ZoomIn className="h-4 w-4" aria-hidden="true" /> Zoom</span>
              </button>
              <p className={HINT}>{check.image.width} × {check.image.height} pixels. Tap the photo to zoom in and look closely.</p>
            </section>
          ) : (
            <p className="hub-callout p-3 text-sm" data-testid="narcotics-photo-removed">The photo file was removed under the retention policy. The record and the decision are kept.</p>
          )}

          <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="narcotics-summary">
            <Fact label="Member">{memberText(check)}</Fact>
            <Fact label="Rescue unit">{rescueUnitText(check)}</Fact>
            <Fact label="Seal number">{sealNumberText(check)}</Fact>
            <Fact label="Sent">{etDateTime(check.submitted_at)}</Fact>
            <Fact label="Member's note">{check.member_note}</Fact>
            <Fact label="Decision">{decision}</Fact>
            <Fact label="Explanation given">{check.status === 'rejected' ? (check.decision_note ?? 'None was given.') : null}</Fact>
          </dl>

          {(check.resubmission_of || check.resubmitted_as) && (
            <div className="flex flex-wrap gap-2">
              {check.resubmission_of && <button type="button" className={BTN_SECONDARY} onClick={() => onOpen(check.resubmission_of as string)}>See the rejected check this answers</button>}
              {check.resubmitted_as && <button type="button" className={BTN_SECONDARY} onClick={() => onOpen(check.resubmitted_as as string)}>See the new photo that answers this</button>}
            </div>
          )}

          {pending && (
            <section aria-label="Decision" className="space-y-3" data-testid="narcotics-actions">
              {!rejecting && (
                <>
                  <LiveNote tone="warning">{approve.error}</LiveNote>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" className={`${BTN_PRIMARY} gap-2`} onClick={doApprove} disabled={approve.busy} aria-busy={approve.busy} data-testid="narcotics-approve">
                      <CheckCircle2 className="h-5 w-5" aria-hidden="true" />{approve.busy ? 'Saving…' : approve.retry ? 'Retry: Approve & Archive' : 'Approve & Archive'}
                    </button>
                    <button type="button" className={`${BTN_SECONDARY} gap-2`} onClick={() => { approve.clear(); setRejecting(true); }} disabled={approve.busy} data-testid="narcotics-reject">
                      <XCircle className="h-5 w-5" aria-hidden="true" />Reject
                    </button>
                  </div>
                  <p className={HINT}>Approving moves the check to the archive and tells the member. Nothing else is needed.</p>
                </>
              )}
              {rejecting && <RejectForm write={reject} onSubmit={doReject} onCancel={() => { reject.clear(); setRejecting(false); }} />}
            </section>
          )}

          <section aria-labelledby="narcotics-history-heading" className="space-y-2">
            <h3 id="narcotics-history-heading" className="font-heading text-base font-bold text-hub-ink">History</h3>
            <ol className="space-y-1.5" data-testid="narcotics-history">
              {check.history.map((entry, index) => (
                <li key={`${entry.action}-${index}`} className="text-sm text-hub-ink">
                  <span className="font-semibold">{ACTION_TEXT[entry.action] ?? entry.action}</span>
                  <span className={HINT}> · {[entry.actor_rank, entry.actor_name].filter(Boolean).join(' ') || 'System'} · {etDateTime(entry.at)}</span>
                </li>
              ))}
            </ol>
          </section>
        </div>
      )}

      {zoomed && check?.image.full_url && <PhotoZoom src={check.image.full_url} alt={`Narcotics check photo from ${check.submitter_name}`} onClose={() => setZoomed(false)} />}
    </Sheet>
  );
}
