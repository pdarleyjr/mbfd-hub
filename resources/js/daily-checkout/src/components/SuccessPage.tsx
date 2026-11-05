import { Link, useLocation, useSearchParams } from 'react-router';
import { useEffect } from 'react';
import { Check, Clock } from 'lucide-react';
import {
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  receiptOutcomeForSync,
  type DailyCheckoutQueueSyncResult,
  type InspectionReceiptOutcome,
  type InspectionReceiptState,
} from '../utils/dailyCheckoutSubmissionQueue';
import { contextualBackLabel, safeContextualPath } from '../utils/contextualBack';
import { isReportedIssues, type ReportedIssues } from '../utils/receiptIssues';

const RECEIPT_OUTCOMES: readonly InspectionReceiptOutcome[] = ['submitted', 'pending_review', 'accepted_with_exception', 'queued'];

/** What the member reported on this checkout, when the receipt knows it (an immediate online send). */
type ReceiptState = Partial<InspectionReceiptState> & { issues?: ReportedIssues };

const readReceiptState = (state: unknown): ReceiptState => {
  if (!state || typeof state !== 'object') return {};
  const candidate = state as Record<string, unknown>;

  return {
    outcome: RECEIPT_OUTCOMES.includes(candidate.outcome as InspectionReceiptOutcome) ? candidate.outcome as InspectionReceiptOutcome : undefined,
    queuedSubmissionId: typeof candidate.queuedSubmissionId === 'string' ? candidate.queuedSubmissionId : undefined,
    returnTo: typeof candidate.returnTo === 'string' ? candidate.returnTo : undefined,
    issues: isReportedIssues(candidate.issues) ? candidate.issues : undefined,
  };
};

/**
 * The receipt says only what the app knows: the inspection was received and recorded. The line about issues comes
 * from what the member reported, never from a reply (Support Services answers, if at all, inside the checkout's
 * own thread), and it never states a count because the server decides what is new.
 */
const issuesLine = (issues: ReportedIssues | undefined, outcome: InspectionReceiptOutcome): string | null => {
  if (issues === 'some') return 'Issues you reported were sent for follow-up.';

  return issues === 'none' && outcome === 'submitted' ? 'No new issues reported.' : null;
};

/** Only an allowlisted in-app Daily route may be used as the way back. */
const inAppPath = (candidate: string | undefined): string | null => {
  const safe = safeContextualPath(candidate ? `/daily${candidate}` : null, window.location.origin);

  return safe?.startsWith('/daily/') ? safe.slice('/daily'.length) : null;
};

export default function SuccessPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const location = useLocation();
  const receipt = readReceiptState(location.state);
  const isQueued = searchParams.get('queued') === 'true';
  // Queued work is never described as sent. The member receipt deliberately
  // uses one plain wording for every accepted outcome (see the owner decision
  // in db42dafa); `outcome` is kept on the page for diagnostics.
  const outcome: InspectionReceiptOutcome = isQueued ? 'queued' : receipt.outcome && receipt.outcome !== 'queued' ? receipt.outcome : 'submitted';
  const receiptIssuesLine = isQueued ? null : issuesLine(receipt.issues, outcome);
  const queuedSubmissionId = receipt.queuedSubmissionId;
  const returnTo = inAppPath(receipt.returnTo) ?? '/stations';
  const returnLabel = returnTo === '/vehicle-inspections' ? 'Back to vehicle inspections' : contextualBackLabel(`/daily${returnTo}`);
  const receiptReturnTo = receipt.returnTo;

  useEffect(() => {
    // Vibrate on success
    if ('vibrate' in navigator && navigator.userActivation?.hasBeenActive !== false) {
      navigator.vibrate(200);
    }
  }, []);

  useEffect(() => {
    if (!isQueued || typeof queuedSubmissionId !== 'string') {
      return;
    }

    const handleQueueSync = (event: Event) => {
      const syncedOutcome = receiptOutcomeForSync((event as CustomEvent<DailyCheckoutQueueSyncResult>).detail, queuedSubmissionId);
      if (!syncedOutcome) {
        return;
      }

      // Only the mounted success page for this exact queue record changes
      // state. A different operator route is never redirected by background
      // synchronization.
      const state: InspectionReceiptState = { outcome: syncedOutcome, queuedSubmissionId, returnTo: receiptReturnTo };
      setSearchParams({}, { replace: true, state });
    };

    window.addEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, handleQueueSync);

    return () => window.removeEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, handleQueueSync);
  }, [isQueued, queuedSubmissionId, receiptReturnTo, setSearchParams]);

  return (
    <section
      className="mx-auto max-w-md rounded-xl border border-hub-border bg-hub-surface p-6 text-center text-hub-ink sm:p-8"
      aria-labelledby="inspection-receipt-heading"
      data-outcome={outcome}
    >
      {isQueued ? (
        <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-hub-surface-muted text-hub-ink-secondary">
          <Clock size={32} aria-hidden="true" />
        </div>
      ) : (
        <div className="animate-checkmark mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-hub-success/10 text-hub-success">
          <Check size={36} strokeWidth={3} aria-hidden="true" />
        </div>
      )}
      <h1 id="inspection-receipt-heading" className="mb-2 text-2xl font-bold">
        {isQueued ? 'Inspection saved on this device' : 'Inspection received'}
      </h1>
      <div role="status" aria-live="polite">
        {isQueued ? (
          <p className="text-hub-ink-secondary">
            It has not been sent yet. It will be sent automatically when this device is connected. Keep this app installed and do not clear its data until it is sent.
          </p>
        ) : (
          <p className="text-hub-ink-secondary">
            Inspection received and recorded.
            {receiptIssuesLine ? ` ${receiptIssuesLine}` : ''}
          </p>
        )}
      </div>

      <div className="mt-6 flex flex-col gap-3">
        <Link
          to={returnTo}
          className="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-hub-blue px-6 font-semibold text-white transition-colors hover:bg-hub-blue-strong focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus touch-manipulation"
        >
          {returnLabel}
        </Link>
        <Link
          to="/vehicle-inspections"
          className="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-hub-border bg-hub-surface px-6 font-semibold text-hub-ink transition-colors hover:bg-hub-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus touch-manipulation"
        >
          Inspect another vehicle
        </Link>
      </div>
    </section>
  );
}
