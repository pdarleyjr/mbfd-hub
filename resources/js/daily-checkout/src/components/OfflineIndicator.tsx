import { useEffect, useState } from 'react';
import { useOffline } from '../hooks/useOffline';
import { getDailyCheckoutQueueSummary, onDailyCheckoutQueueChanged } from '../utils/dailyCheckoutSubmissionQueue';
import { getPendingSubmissionQueueSummary } from '../lib/sync';

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

export default function OfflineIndicator() {
  const isOffline = useOffline();
  const [queueCount, setQueueCount] = useState(0);
  const [pendingCount, setPendingCount] = useState(0);
  const [attentionCount, setAttentionCount] = useState(0);
  const [attentionError, setAttentionError] = useState<string | undefined>();
  const [attentionErrorCode, setAttentionErrorCode] = useState<string | undefined>();
  const [showToast, setShowToast] = useState(false);
  const [toastMessage, setToastMessage] = useState('');

  useEffect(() => {
    let mounted = true;
    const updateQueue = async () => {
      try {
        const [dailySummary, formSummary] = await Promise.all([
          getDailyCheckoutQueueSummary(),
          getPendingSubmissionQueueSummary(),
        ]);
        if (!mounted) {
          return;
        }

        setQueueCount(dailySummary.total + formSummary.total);
        setPendingCount(dailySummary.pending + formSummary.pending);
        setAttentionCount(dailySummary.requiresAttention + formSummary.requiresAttention);
        setAttentionError(formSummary.firstAttentionError ?? dailySummary.firstAttentionError);
        setAttentionErrorCode(formSummary.firstAttentionErrorCode ?? dailySummary.firstAttentionErrorCode);
      } catch (error) {
        console.error('Failed to read the saved submission queues:', error);
      }
    };

    void updateQueue();
    const unsubscribe = onDailyCheckoutQueueChanged(() => {
      void updateQueue();
    });
    const interval = setInterval(updateQueue, 1000);

    return () => {
      mounted = false;
      unsubscribe();
      clearInterval(interval);
    };
  }, []);

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
      setTimeout(() => setShowToast(false), 5000);
    }
  }, [attentionCount, isOffline, pendingCount]);

  const state = attentionCount > 0 ? 'attention' : isOffline ? 'offline' : pendingCount > 0 ? 'pending' : 'connected';
  const label = attentionCount > 0 ? 'Needs review' : isOffline ? 'Offline' : pendingCount > 0 ? 'Pending sync' : 'Connected';

  return (
    <>
      <div className="hub-connection-bar" data-state={state} role="status" aria-live="polite">
        <span className="hub-connection-state">{label}</span>
        <span className="hub-connection-detail">{queueCount > 0 ? `${queueCount} saved on this device` : isOffline ? 'Work stays on this device' : 'No submissions waiting to sync'}</span>
      </div>
      {/* Offline Banner */}
      {isOffline && attentionCount === 0 && (
        <div className="hub-sync-notice" role="status">
          Offline Mode · Submissions wait for a connection. Changes are saved on this device.
        </div>
      )}

      {attentionCount > 0 && (
        <div
          className="hub-sync-notice hub-sync-notice--attention"
          role="alert"
        >
          <p>
            {attentionCount} saved submission{attentionCount > 1 ? 's need' : ' needs'} review before it can be sent. Your work remains saved on this device.
          </p>
          <p>{attentionGuidance(attentionErrorCode, attentionError)}</p>
          {isOffline && <p>This device is offline; the saved work will remain on this device.</p>}
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
