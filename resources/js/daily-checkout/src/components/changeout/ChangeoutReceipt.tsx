import { useCallback, useEffect, useId, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { setUnsavedWork } from '../../pwa/draftBusy.ts';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { getChangeout, verifyChangeoutLine } from '../../utils/changeoutApi';
import DailyPageNav from '../DailyPageNav';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import Tag from '../ui/Tag';
import { humanize, type Tone } from '../ui/status';
import { dailyRouterPath, stationReturnPath } from './changeoutLogic';
import type { ChangeoutLine, ChangeoutSummary } from './types';

const STATUS: Record<string, { label: string; tone: Tone }> = {
  completed: { label: 'Completed', tone: 'success' },
  pending_fleet: { label: 'Waiting on Fleet', tone: 'warning' },
  rejected: { label: 'Not approved', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
};

const headline = (summary: ChangeoutSummary): string => {
  const truck = summary.to_vehicle_number ?? 'not recorded';
  switch (summary.status) {
    case 'completed':
      return summary.message ?? `${summary.unit_designation} is now on truck ${truck}.`;
    case 'pending_fleet':
      return `${summary.unit_designation} to truck ${truck} is waiting on Fleet.`;
    case 'rejected':
      return `Fleet did not approve moving ${summary.unit_designation} to truck ${truck}.`;
    case 'cancelled':
      return `The move of ${summary.unit_designation} to truck ${truck} was cancelled.`;
    default:
      return `${summary.unit_designation} to truck ${truck}.`;
  }
};

const lineDetail = (line: ChangeoutLine): string => [
  line.quantity_moved !== null && line.expected_quantity !== null ? `${line.quantity_moved} of ${line.expected_quantity}` : null,
  line.condition && line.condition !== 'ok' ? humanize(line.condition) : null,
  line.compartment,
].filter(Boolean).join(' · ');

function VerifyControl({ changeoutId, line, onVerified }: { changeoutId: number; line: ChangeoutLine; onVerified: () => void }) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // A typed note is unsaved work: an app update must not reload the page under it.
  useEffect(() => {
    setUnsavedWork(id, note.trim() !== '' || saving);

    return () => setUnsavedWork(id, false);
  }, [id, note, saving]);

  if (!open) {
    return <button type="button" onClick={() => setOpen(true)} className="hub-btn hub-btn--secondary mt-2 !min-h-11">Verify</button>;
  }

  const verify = async () => {
    setSaving(true);
    setError(null);
    try {
      await verifyChangeoutLine(changeoutId, line.id, note.trim() === '' ? null : note.trim());
      setNote('');
      onVerified();
    } catch (failure) {
      if (isApiAuthenticationError(failure)) {
        redirectToLoginAfterSessionExpiry();
        return;
      }
      setError(failure instanceof ApiRequestError ? failure.message : 'Not sent. Your note is still here. Try again when you have signal.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mt-2 space-y-2">
      <label htmlFor={`${id}-note`} className="text-sm font-semibold text-hub-ink">Verification note (optional)</label>
      <input
        id={`${id}-note`}
        type="text"
        value={note}
        maxLength={500}
        autoComplete="off"
        onChange={(event) => setNote(event.target.value)}
        className="block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-hub-ink"
      />
      {error && <p role="alert" className="text-sm font-semibold text-hub-danger">{error}</p>}
      <div className="flex flex-wrap gap-2">
        <button type="button" disabled={saving} aria-busy={saving} onClick={() => void verify()} className="hub-btn hub-btn--primary !min-h-11">{saving ? 'Saving…' : 'Record verification'}</button>
        <button type="button" disabled={saving} onClick={() => setOpen(false)} className="hub-btn hub-btn--tertiary !min-h-11">Not now</button>
      </div>
    </div>
  );
}

function LineList({ changeoutId, lines, onVerified }: { changeoutId: number; lines: ChangeoutLine[]; onVerified: () => void }) {
  return (
    <ul className="hub-well divide-y divide-hub-n-100">
      {lines.map((line) => {
        const detail = lineDetail(line);

        return (
          <li key={line.id} className="px-4 py-3 text-sm">
            <p className="font-semibold text-hub-ink">{line.name}</p>
            {detail !== '' && <p className="tabular-nums text-hub-n-600">{detail}</p>}
            {line.note && <p className="text-hub-n-800">{line.note}</p>}
            {line.verified_at && (
              <p className="text-hub-n-800">
                Verified {formatHubDate(line.verified_at)}{line.verified_by_name ? ` by ${line.verified_by_name}` : ''}{line.verification_note ? `: ${line.verification_note}` : ''}
              </p>
            )}
            {line.can_verify && !line.verified_at && <VerifyControl changeoutId={changeoutId} line={line} onVerified={onVerified} />}
          </li>
        );
      })}
    </ul>
  );
}

function ReceiptBody({ summary, stationPath, onVerified }: { summary: ChangeoutSummary; stationPath: string; onVerified: () => void }) {
  const status = STATUS[summary.status] ?? { label: humanize(summary.status), tone: 'neutral' as Tone };
  const waiting = summary.lines.filter((line) => line.custody_status === 'pending_verification' || line.custody_status === 'needs_review');
  const left = summary.lines.filter((line) => line.custody_status === 'left_behind');
  const moved = summary.lines.filter((line) => line.custody_status === 'moved');
  const verified = summary.lines.filter((line) => line.custody_status === 'verified');
  const nextCheckout = summary.status === 'completed' ? dailyRouterPath(summary.next_daily_checkout_path) : null;
  // Going back starts from the truck the unit is on now. The server decides whether that truck is still eligible.
  const returnPath = summary.status === 'completed' && summary.to_apparatus_id !== null && summary.from_vehicle_number
    ? `/changeouts/new/${summary.to_apparatus_id}?return_to=${encodeURIComponent(stationPath)}`
    : null;

  return (
    <>
      <section className="hub-panel space-y-3 p-4 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <h1 className="min-w-0 font-heading text-xl font-bold text-hub-ink sm:text-2xl">{headline(summary)}</h1>
          <Tag tone={status.tone}>{status.label}</Tag>
        </div>
        <dl className="hub-well divide-y divide-hub-n-100 text-sm">
          <div className="flex justify-between gap-4 px-4 py-3"><dt className="text-hub-n-600">From truck</dt><dd className="font-medium tabular-nums text-hub-ink">{summary.from_vehicle_number ?? 'Not recorded'}</dd></div>
          <div className="flex justify-between gap-4 px-4 py-3"><dt className="text-hub-n-600">To truck</dt><dd className="font-medium tabular-nums text-hub-ink">{summary.to_vehicle_number ?? 'Not recorded'}</dd></div>
          {summary.effective_at && <div className="flex justify-between gap-4 px-4 py-3"><dt className="text-hub-n-600">Effective</dt><dd className="font-medium tabular-nums text-hub-ink">{formatHubDate(summary.effective_at)}</dd></div>}
          {summary.submitted_by_name && <div className="flex justify-between gap-4 px-4 py-3"><dt className="text-hub-n-600">Submitted by</dt><dd className="text-right font-medium text-hub-ink">{summary.submitted_by_name}</dd></div>}
          <div className="flex justify-between gap-4 px-4 py-3"><dt className="text-hub-n-600">Equipment</dt><dd className="text-right font-medium tabular-nums text-hub-ink">{summary.moved} moved, {summary.left_behind} left, {summary.open} open</dd></div>
        </dl>
        {summary.note && <p className="text-sm text-hub-n-800"><span className="font-semibold">Note:</span> {summary.note}</p>}
        {summary.decision_note && <p className="text-sm text-hub-n-800"><span className="font-semibold">Fleet:</span> {summary.decision_note}</p>}
        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
          {nextCheckout && <Link to={nextCheckout} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11">Start Daily Checkout on truck {summary.to_vehicle_number ?? 'not recorded'}</Link>}
          <Link to={stationPath} className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11">Back to station</Link>
          {returnPath && <Link to={returnPath} className="hub-btn hub-btn--tertiary hub-btn--wrap !min-h-11">Return to truck {summary.from_vehicle_number}</Link>}
        </div>
      </section>

      {waiting.length > 0 && (
        <section aria-labelledby="changeout-waiting" className="hub-panel p-4 sm:p-6">
          <h2 id="changeout-waiting" className="mb-3 font-heading text-lg font-semibold text-hub-ink">Waiting for verification</h2>
          <LineList changeoutId={summary.id} lines={waiting} onVerified={onVerified} />
        </section>
      )}
      {left.length > 0 && (
        <section aria-labelledby="changeout-left" className="hub-panel p-4 sm:p-6">
          <h2 id="changeout-left" className="mb-3 font-heading text-lg font-semibold text-hub-ink">Left on truck {summary.from_vehicle_number ?? 'not recorded'}</h2>
          <LineList changeoutId={summary.id} lines={left} onVerified={onVerified} />
        </section>
      )}
      {verified.length > 0 && (
        <section aria-labelledby="changeout-verified" className="hub-panel p-4 sm:p-6">
          <h2 id="changeout-verified" className="mb-3 font-heading text-lg font-semibold text-hub-ink">Verified</h2>
          <LineList changeoutId={summary.id} lines={verified} onVerified={onVerified} />
        </section>
      )}
      {moved.length > 0 && (
        <details className="hub-panel p-4 sm:p-6">
          <summary className="flex min-h-11 cursor-pointer items-center font-heading text-lg font-semibold text-hub-ink">Moved ({moved.length})</summary>
          <div className="mt-3"><LineList changeoutId={summary.id} lines={moved} onVerified={onVerified} /></div>
        </details>
      )}
    </>
  );
}

/** Route for /changeouts/:id: what happened, what moved, and what is still waiting. */
export default function ChangeoutReceipt() {
  const { id } = useParams<{ id: string }>();
  const [search] = useSearchParams();
  const changeoutId = /^\d+$/.test(id ?? '') ? Number(id) : null;
  const [summary, setSummary] = useState<ChangeoutSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const returnTo = search.get('return_to');

  const refresh = useCallback(() => setAttempt((current) => current + 1), []);

  useEffect(() => {
    if (changeoutId === null) {
      setError('This link does not point to a changeout.');
      return;
    }
    let active = true;
    setError(null);
    getChangeout(changeoutId)
      .then((next) => { if (active) setSummary(next); })
      .catch((failure: unknown) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) {
          redirectToLoginAfterSessionExpiry();
          return;
        }
        setError(failure instanceof ApiRequestError ? failure.message : 'The connection dropped before this record loaded.');
      });

    return () => { active = false; };
  }, [attempt, changeoutId]);

  const stationPath = stationReturnPath(returnTo, summary?.station_id ?? null);

  return (
    <div data-hub-ui="2" className="mx-auto max-w-2xl space-y-4 font-hub">
      <DailyPageNav fallback={stationPath} className="!mb-0" />
      {summary
        ? <ReceiptBody summary={summary} stationPath={stationPath} onVerified={refresh} />
        : error
          ? <LoadError title="This changeout could not be loaded" message={error} onRetry={refresh} />
          : <div className="hub-panel p-4 sm:p-6"><SkeletonRows /></div>}
    </div>
  );
}
