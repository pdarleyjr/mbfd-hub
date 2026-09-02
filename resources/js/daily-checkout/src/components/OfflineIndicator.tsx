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
      setToastMessage('You are offline. Changes will be saved locally.');
      setShowToast(true);
    } else if (!isOffline && pendingCount > 0) {
      setToastMessage(`Back online! Syncing ${pendingCount} pending submission${pendingCount > 1 ? 's' : ''}...`);
      setShowToast(true);
      
      // Auto hide after 5 seconds
      setTimeout(() => setShowToast(false), 5000);
    }
  }, [attentionCount, isOffline, pendingCount]);

  if (!showToast && !isOffline && attentionCount === 0) return null;

  return (
    <>
      {/* Offline Banner */}
      {isOffline && attentionCount === 0 && (
        <div className="fixed top-0 left-0 right-0 z-50 bg-yellow-500 text-white px-4 py-2 text-center text-sm font-medium shadow-lg" role="status">
          <span className="inline-block mr-2" aria-hidden="true">⚠️</span>
          Offline Mode - Changes will be saved locally
          {queueCount > 0 && (
            <span className="ml-2 inline-block bg-yellow-600 px-2 py-0.5 rounded-full text-xs">
              {queueCount} pending
            </span>
          )}
        </div>
      )}

      {attentionCount > 0 && (
        <div
          className="fixed top-0 left-0 right-0 z-40 bg-red-700 text-white px-4 py-3 text-center text-sm font-medium shadow-lg"
          role="alert"
        >
          <p>
            {attentionCount} saved submission{attentionCount > 1 ? 's need' : ' needs'} review before it can be sent. The payload remains saved on this device.
          </p>
          <p className="mt-1 text-red-100">{attentionGuidance(attentionErrorCode, attentionError)}</p>
          {isOffline && <p className="mt-1 text-red-100">This device is offline; the saved work will remain on this device.</p>}
        </div>
      )}

      {/* Toast Notification */}
      {showToast && (
        <div 
          className="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:max-w-sm bg-gray-900 text-white px-4 py-3 rounded-lg shadow-lg z-50 animate-slide-up"
          role="alert"
        >
          <div className="flex items-start">
            <span className="mr-2" aria-hidden="true">{isOffline ? '⚠️' : '✓'}</span>
            <p className="flex-1">{toastMessage}</p>
            <button
              onClick={() => setShowToast(false)}
              className="ml-2 inline-flex min-h-[44px] min-w-[44px] items-center justify-center text-gray-400 hover:text-white"
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
