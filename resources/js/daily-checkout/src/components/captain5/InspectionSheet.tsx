import { useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { Captain5ApiError, fetchInspection, isRetryable, postClarification } from '../../utils/captain5Api';
import Tag from '../ui/Tag';
import { LoadError } from '../ui/StateViews';
import type { Tone } from '../ui/status';
import { dateTimeText, kindLabel, newKey } from './format';
import type { InspectionView } from './types';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, HINT, LABEL, Sheet } from './ui';

interface Props {
  inspectionId: number | null;
  onClose: () => void;
}

const ITEM_TONE: Record<string, Tone> = { present: 'success', missing: 'danger', damaged: 'danger' };

function Clarification({ inspection }: { inspection: InspectionView }) {
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [retry, setRetry] = useState(false);
  const keyRef = useRef(newKey());
  const attempted = useRef<string | null>(null);

  const send = async (event: FormEvent) => {
    event.preventDefault();
    const text = body.trim();
    if (busy) return;
    if (text === '') { setError('Write what you need clarified.'); return; }
    if (attempted.current !== null && attempted.current !== text) keyRef.current = newKey();
    attempted.current = text;
    setBusy(true);
    setError(null);
    setRetry(false);
    try {
      await postClarification(inspection.id, text, keyRef.current);
      keyRef.current = newKey();
      attempted.current = null;
      setBody('');
      setSent(true);
    } catch (failure) {
      if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
      setRetry(isRetryable(failure));
      setError(failure instanceof Error ? failure.message : 'The request could not be sent. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={send} noValidate className="space-y-2 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4" aria-label="Request clarification" data-testid="captain5-clarification">
      <h3 className="font-heading text-base font-bold text-hub-ink">Request clarification</h3>
      <p className={HINT}>Sends a message to the crew who submitted this checkout.</p>
      <div>
        <label className={LABEL} htmlFor="c5-clarification-body">What do you need to know?</label>
        <textarea id="c5-clarification-body" rows={3} className={FIELD} value={body} disabled={busy} maxLength={4000}
          onChange={(event) => { setBody(event.target.value); setSent(false); }} />
      </div>
      {error && <p role="alert" className="hub-callout hub-callout--warning p-3 text-sm">{error}</p>}
      {sent && !error && <p role="status" className="text-sm font-semibold text-hub-success">Clarification request sent.</p>}
      <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={busy}>{busy ? 'Sending…' : retry ? 'Retry send' : 'Send request'}</button>
    </form>
  );
}

export default function InspectionSheet({ inspectionId, onClose }: Props) {
  const [inspection, setInspection] = useState<InspectionView | null>(null);
  const [error, setError] = useState<Captain5ApiError | null>(null);
  const [loading, setLoading] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    setInspection(null);
    setError(null);
    if (inspectionId === null) return undefined;
    let active = true;
    setLoading(true);
    fetchInspection(inspectionId)
      .then((loaded) => { if (active) setInspection(loaded); })
      .catch((failure) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) { redirectToLoginAfterSessionExpiry(); return; }
        setError(failure instanceof Captain5ApiError ? failure : new Captain5ApiError('The inspection could not be loaded.', 0));
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [inspectionId, attempt]);

  const unavailable = error !== null && (error.status === 403 || error.status === 404);

  return (
    <Sheet
      open={inspectionId !== null}
      onClose={onClose}
      layer={1}
      testId="captain5-inspection-sheet"
      title={inspection ? `${inspection.unit} checkout` : 'Inspection'}
      description={inspection ? `${kindLabel(inspection.kind)} inspection #${inspection.id}, read only` : undefined}
    >
      {loading && <div role="status" aria-label="Loading inspection" className="space-y-3"><span className="hub-skeleton block !h-8 w-2/3" /><span className="hub-skeleton block !h-40 w-full" /></div>}
      {unavailable && (
        <div className="hub-callout p-4" role="alert">
          <p className="font-semibold text-hub-ink">This inspection is not available to you</p>
          <p className="text-sm text-hub-n-600">It is outside Captain 5&apos;s scope or no longer exists.</p>
          <button type="button" className={`${BTN_SECONDARY} mt-3`} onClick={onClose}>Close</button>
        </div>
      )}
      {error && !unavailable && <LoadError title="The inspection could not be loaded" message={error.message} onRetry={() => setAttempt((n) => n + 1)} />}

      {inspection && (
        <div className="space-y-5" data-testid="captain5-inspection">
          <p className="text-sm text-hub-n-600">
            Submitted {dateTimeText(inspection.submitted_at)}{inspection.submitted_by ? ` by ${inspection.submitted_by}` : ''}
            {inspection.shift ? ` · ${inspection.shift} Shift` : ''}
          </p>
          {inspection.scope_note && <p className="hub-callout p-3 text-sm" data-testid="captain5-scope-note">{inspection.scope_note}</p>}
          {inspection.compartments.length === 0 && <p className={HINT}>No items from this checkout are in Captain 5&apos;s scope.</p>}
          {inspection.compartments.map((compartment) => (
            <section key={`${compartment.id}-${compartment.name}`} aria-label={compartment.name}>
              <h3 className="font-heading text-base font-bold text-hub-ink">{compartment.name || 'Compartment'}</h3>
              <ul className="mt-2 divide-y divide-hub-border rounded-hub-control border border-hub-border bg-hub-well">
                {compartment.items.map((item) => (
                  <li key={`${item.id}-${item.name}`} className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 px-3 py-2.5">
                    <div className="min-w-0">
                      <p className="break-words text-sm font-semibold text-hub-ink">{item.name}</p>
                      {item.notes && <p className="whitespace-pre-wrap break-words text-[13px] text-hub-n-600">{item.notes}</p>}
                    </div>
                    <Tag tone={ITEM_TONE[item.status.toLowerCase()] ?? 'neutral'}>{item.status || 'Not recorded'}</Tag>
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {inspection.kind === 'rescue' && <Clarification key={inspection.id} inspection={inspection} />}
        </div>
      )}
    </Sheet>
  );
}
