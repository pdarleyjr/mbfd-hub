import { Link, useLocation, useSearchParams } from 'react-router';
import { useEffect } from 'react';
import {
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  type DailyCheckoutQueueSyncResult,
} from '../utils/dailyCheckoutSubmissionQueue';

export default function SuccessPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const location = useLocation();
  const isQueued = searchParams.get('queued') === 'true';
  const isPendingReview = searchParams.get('review') === 'pending';
  const queuedSubmissionId = (location.state as { queuedSubmissionId?: unknown } | null)?.queuedSubmissionId;

  useEffect(() => {
    // Vibrate on success
    if ('vibrate' in navigator) {
      navigator.vibrate(200);
    }
  }, []);

  useEffect(() => {
    if (!isQueued || typeof queuedSubmissionId !== 'string') {
      return;
    }

    const handleQueueSync = (event: Event) => {
      const result = (event as CustomEvent<DailyCheckoutQueueSyncResult>).detail;
      if (!result.pendingReviewQueueIds.includes(queuedSubmissionId)) {
        return;
      }

      // Only the mounted success page for this exact queue record changes
      // state. A different operator route is never redirected by background
      // synchronization.
      setSearchParams({ review: 'pending' }, { replace: true });
    };

    window.addEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, handleQueueSync);

    return () => window.removeEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, handleQueueSync);
  }, [isQueued, queuedSubmissionId, setSearchParams]);

  return (
    <div className="max-w-md mx-auto text-center">
      <div className="mb-8">
        <div className="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4 animate-checkmark">
          <svg
            className="w-12 h-12 text-green-600"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={3}
              d="M5 13l4 4L19 7"
            />
          </svg>
        </div>
        <h1 className="text-3xl font-bold text-gray-900 mb-2">
          {isQueued
            ? 'Inspection Queued!'
            : isPendingReview
              ? 'Inspection Submitted for Review!'
              : 'Inspection Submitted!'}
        </h1>
        <p className="text-gray-600">
          {isQueued
            ? 'Your inspection will be submitted automatically when you\'re back online.'
            : isPendingReview
              ? 'Your daily checkout inspection is awaiting officer review before it changes readiness, defects, or meter records.'
              : 'Your daily checkout inspection has been successfully recorded.'
          }
        </p>
      </div>

      {isQueued && (
        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-yellow-800">
            <strong>⚠️ Offline Mode</strong><br />
            Your inspection has been saved locally and will sync when you reconnect to the network.
          </p>
        </div>
      )}

      {!isQueued && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-blue-800">
            <strong>What happens next?</strong><br />
            {isPendingReview
              ? <>• An authorized officer must review this submission<br />• Readiness, defects, and meter records remain unchanged until approval</>
              : <>• Your inspection data has been saved to the system<br />• Any issues found will be tracked for follow-up<br />• Administrators will review the inspection results</>}
          </p>
        </div>
      )}

      <div className="space-y-3">
        <Link
          to="/"
          className="block w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 font-medium transition-colors touch-manipulation"
        >
          Start Another Inspection
        </Link>

        <p className="text-sm text-gray-500">
          Thank you for keeping our equipment ready and safe!
        </p>
      </div>
    </div>
  );
}
