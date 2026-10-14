import { useCallback, useEffect, useId, useState } from 'react';
import { useOffline } from '../hooks/useOffline';
import {
  DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT,
  discardDailyCheckoutAttentionRecord,
  getDailyCheckoutAttentionRecords,
  getDailyCheckoutQueueSummary,
  onDailyCheckoutQueueChanged,
  type DailyCheckoutAttentionRecord,
} from '../utils/dailyCheckoutSubmissionQueue';
import { db } from '../lib/db';
import { identityForQueueCapture } from '../lib/offlineIdentity';
import { redirectToLoginAfterSessionExpiry } from '../utils/api';

/** Safety net only: queue changes normally arrive as events. */
const FALLBACK_POLL_MS = 20_000;

const attentionGuidance = (errorCode?: string, error?: string): string => {
  switch (errorCode) {
    case 'DAILY_CHECKOUT_CHECKLIST_VERSION_REVIEW_REQUIRED':
      return 'The checklist changed after this inspection was saved. An officer must reconcile it with the current checklist before a new submission is created.';
    case 'OFFLINE_QUEUE_OWNER_MISMATCH':
      return 'This saved work belongs to a different signed-in member and was not submitted. Sign in as the original member or ask an officer for help.';
    case 'OFFLINE_QUEUE_SECURITY_VERSION_MISMATCH':
      return 'The account security context changed after this work was saved. An officer must review it before it can be submitted.';
    case 'OFFLINE_QUEUE_OWNER_LEGACY':
      return 'This saved work predates account-bound offline queues. An officer must review it before it can be submitted.';
    default:
      return error ?? 'An officer must review the saved work before it can be submitted.';
  }
};

/**
 * Generic form queue counts from the `status` index, so stored payloads
 * (photos) are not read. Only the first record needing attention is loaded.
 */
const genericQueueSummary = async () => {
  const [total, requiresAttention, firstAttention] = await Promise.all([
    db.pendingSubmissions.count(),
    db.pendingSubmissions.where('status').equals('requires_attention').count(),
    db.pendingSubmissions.where('status').equals('requires_attention').first(),
  ]);

  return {
    total,
    pending: total - requiresAttention,
    requiresAttention,
    firstAttentionError: firstAttention?.lastError,
    firstAttentionErrorCode: firstAttention?.lastErrorCode,
  };
};

const savedAt = (value: Date): string => new Intl.DateTimeFormat('en-US', {
  month: 'short',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
}).format(value);

interface AttentionReviewProps {
  onChanged: () => void;
}

function AttentionReview({ onChanged }: AttentionReviewProps) {
  const [records, setRecords] = useState<DailyCheckoutAttentionRecord[] | null>(null);
  const [userId, setUserId] = useState<number | undefined>();
  const [confirmingId, setConfirmingId] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(async () => {
    let currentUserId: number | undefined;
    try {
      currentUserId = (await identityForQueueCapture({ redirectOnExpiry: false })).userId;
    } catch {
      currentUserId = undefined;
    }
    setUserId(currentUserId);
    setRecords(await getDailyCheckoutAttentionRecords(currentUserId));
  }, []);

  useEffect(() => {
    void load().catch(() => setMessage('Saved inspections could not be read on this device.'));

    return onDailyCheckoutQueueChanged(() => { void load().catch(() => undefined); });
  }, [load]);

  const discard = async (id: string) => {
    if (userId === undefined) return;
    setMessage(null);
    try {
      const removed = await discardDailyCheckoutAttentionRecord(id, userId);
      setMessage(removed ? 'The saved inspection was discarded from this device.' : 'That saved inspection could not be discarded. It is still on this device.');
    } catch {
      setMessage('That saved inspection could not be discarded. It is still on this device.');
    } finally {
      setConfirmingId(null);
      onChanged();
    }
  };

  if (records === null) {
    return <p className="mt-2 text-hub-ink-secondary">Loading saved inspections…</p>;
  }

  return (
    <div className="mt-2 space-y-2 text-hub-ink">
      {message && <p role="status" className="text-hub-ink-secondary">{message}</p>}
      {records.length === 0 && !message && (
        <p className="text-hub-ink-secondary">No saved Daily Checkout needs review. Other saved forms are listed by the form that created them.</p>
      )}
      <ul className="space-y-2" aria-label="Saved inspections that need review">
        {records.map((record) => (
          <li key={record.id} className="rounded-lg border border-hub-border bg-hub-surface p-3">
            <p className="font-semibold">
              Daily Checkout{record.unitNumber ? ` · Vehicle ${record.unitNumber}` : ''} · saved {savedAt(record.createdAt)}
            </p>
            <p className="mt-1 text-hub-ink-secondary">{attentionGuidance(record.lastErrorCode, record.lastError)}</p>
            {record.canDiscard && confirmingId !== record.id && (
              <button
                type="button"
                className="mt-2 inline-flex min-h-11 items-center rounded-lg border border-hub-border px-3 font-semibold text-hub-danger hover:bg-hub-danger/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
                onClick={() => setConfirmingId(record.id)}
              >
                Discard this saved inspection…
              </button>
            )}
            {record.canDiscard && confirmingId === record.id && (
              <div className="mt-2 rounded-lg border border-hub-danger/40 bg-hub-danger/5 p-3" role="group" aria-label="Confirm discard">
                <p className="font-semibold text-hub-danger">Discard permanently?</p>
                <p className="mt-1 text-hub-ink-secondary">The server did not accept this inspection. Discarding deletes it from this device and it cannot be recovered. Start a new inspection afterwards if one is still required.</p>
                <div className="mt-2 flex flex-wrap gap-2">
                  <button
                    type="button"
                    className="inline-flex min-h-11 items-center rounded-lg border border-hub-border bg-hub-surface px-3 font-semibold text-hub-ink hover:bg-hub-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
                    onClick={() => setConfirmingId(null)}
                  >
                    Keep it
                  </button>
                  <button
                    type="button"
                    className="inline-flex min-h-11 items-center rounded-lg bg-hub-danger px-3 font-semibold text-white hover:bg-hub-danger/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
                    onClick={() => { void discard(record.id); }}
                  >
                    Discard permanently
                  </button>
                </div>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function OfflineIndicator() {
  const isOffline = useOffline();
  const reviewPanelId = useId();
  const [queueCount, setQueueCount] = useState(0);
  const [pendingCount, setPendingCount] = useState(0);
  const [attentionCount, setAttentionCount] = useState(0);
  const [dailyAttentionCount, setDailyAttentionCount] = useState(0);
  const [attentionError, setAttentionError] = useState<string | undefined>();
  const [attentionErrorCode, setAttentionErrorCode] = useState<string | undefined>();
  const [showToast, setShowToast] = useState(false);
  const [toastMessage, setToastMessage] = useState('');
  const [reviewOpen, setReviewOpen] = useState(false);
  const [refreshRevision, setRefreshRevision] = useState(0);
  const [signInRequired, setSignInRequired] = useState(false);

  useEffect(() => {
    const onSignInRequired = (event: Event) => setSignInRequired((event as CustomEvent<boolean>).detail === true);
    window.addEventListener(DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT, onSignInRequired);

    return () => window.removeEventListener(DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT, onSignInRequired);
  }, []);

  useEffect(() => {
    let mounted = true;
    const updateQueue = async () => {
      try {
        const [dailySummary, formSummary] = await Promise.all([
          getDailyCheckoutQueueSummary(),
          genericQueueSummary(),
        ]);
        if (!mounted) {
          return;
        }

        setQueueCount(dailySummary.total + formSummary.total);
        setPendingCount(dailySummary.pending + formSummary.pending);
        setAttentionCount(dailySummary.requiresAttention + formSummary.requiresAttention);
        setDailyAttentionCount(dailySummary.requiresAttention);
        setAttentionError(formSummary.firstAttentionError ?? dailySummary.firstAttentionError);
        setAttentionErrorCode(formSummary.firstAttentionErrorCode ?? dailySummary.firstAttentionErrorCode);
      } catch (error) {
        console.error('Failed to read the saved submission queues:', error);
      }
    };
    const onVisible = () => {
      if (document.visibilityState === 'visible') void updateQueue();
    };

    void updateQueue();
    const unsubscribe = onDailyCheckoutQueueChanged(() => {
      void updateQueue();
    });
    document.addEventListener('visibilitychange', onVisible);
    const interval = setInterval(updateQueue, FALLBACK_POLL_MS);

    return () => {
      mounted = false;
      unsubscribe();
      document.removeEventListener('visibilitychange', onVisible);
      clearInterval(interval);
    };
  }, [refreshRevision]);

  useEffect(() => {
    if (attentionCount > 0) {
      setShowToast(false);
    } else if (isOffline) {
      setToastMessage('Offline. Changes are saved on this device.');
      setShowToast(true);
    } else if (!isOffline && pendingCount > 0) {
      setToastMessage(`Connected. ${pendingCount} saved submission${pendingCount > 1 ? 's are' : ' is'} waiting to sync.`);
      setShowToast(true);

      // Auto hide after 5 seconds
      const timer = setTimeout(() => setShowToast(false), 5000);
      return () => clearTimeout(timer);
    }

    return undefined;
  }, [attentionCount, isOffline, pendingCount]);

  useEffect(() => {
    if (dailyAttentionCount === 0) setReviewOpen(false);
  }, [dailyAttentionCount]);

  const state = attentionCount > 0 ? 'attention' : isOffline ? 'offline' : pendingCount > 0 ? 'pending' : 'connected';
  const label = attentionCount > 0 ? 'Needs review' : isOffline ? 'Offline' : pendingCount > 0 ? 'Pending sync' : 'Connected';

  return (
    <>
      <div className="hub-connection-bar" data-state={state} role="status" aria-live="polite">
        <span className="hub-connection-state">{label}</span>
        <span className="hub-connection-detail">{queueCount > 0 ? `${queueCount} saved on this device` : isOffline ? 'Work stays on this device' : 'No submissions waiting to sync'}</span>
      </div>
      {signInRequired && !isOffline && (
        <div className="hub-sync-notice" role="status" data-testid="sign-in-required-notice">
          <p>Your sign-in expired. Saved work stays on this device and is sent after you sign in again.</p>
          <button
            type="button"
            className="mt-1 inline-flex min-h-11 items-center font-semibold text-hub-ink underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
            onClick={() => redirectToLoginAfterSessionExpiry()}
          >
            Sign in again
          </button>
        </div>
      )}
      {/* Offline Banner */}
      {isOffline && attentionCount === 0 && (
        <div className="hub-sync-notice" role="status">
          Offline Mode · Submissions wait for a connection. Changes are saved on this device.
        </div>
      )}

      {attentionCount > 0 && (
        <div className="hub-sync-notice hub-sync-notice--attention">
          <div role="alert">
            <p>
              {attentionCount} saved submission{attentionCount > 1 ? 's need' : ' needs'} review before it can be sent. Your work remains saved on this device.
            </p>
            <p>{attentionGuidance(attentionErrorCode, attentionError)}</p>
            {isOffline && <p>This device is offline; the saved work will remain on this device.</p>}
          </div>
          {dailyAttentionCount > 0 && (
            <button
              type="button"
              className="mt-1 inline-flex min-h-11 items-center font-semibold text-hub-ink underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hub-focus"
              aria-expanded={reviewOpen}
              aria-controls={reviewPanelId}
              onClick={() => setReviewOpen((open) => !open)}
            >
              {reviewOpen ? 'Hide saved inspections' : 'Review saved inspections'}
            </button>
          )}
          <div id={reviewPanelId} hidden={!reviewOpen}>
            {reviewOpen && <AttentionReview onChanged={() => setRefreshRevision((revision) => revision + 1)} />}
          </div>
        </div>
      )}

      {/* Toast Notification */}
      {showToast && !isOffline && (
        <div
          className="hub-sync-notice"
          role="status"
        >
          <div className="hub-sync-toast">
            <p>{toastMessage}</p>
            <button
              onClick={() => setShowToast(false)}
              className="inline-flex items-center justify-center"
              aria-label="Close notification"
            >
              ✕
            </button>
          </div>
        </div>
      )}
    </>
  );
}
