import { useEffect } from 'react';
import {
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  synchronizeDailyCheckoutQueue,
} from '../utils/dailyCheckoutSubmissionQueue';
import { processPendingSubmissions } from '../lib/sync';
import { refreshOfflineIdentity } from '../lib/offlineIdentity';

/**
 * Remains mounted on every Daily route, including the queued success page.
 * That makes reconnect replay independent of the inspection wizard lifecycle.
 */
export default function DailyCheckoutQueueProcessor() {
  useEffect(() => {
    const synchronize = () => {
      if (!navigator.onLine) {
        return;
      }

      void refreshOfflineIdentity().then(async (identity) => {
        const result = await synchronizeDailyCheckoutQueue(identity);
        await processPendingSubmissions('/api/public', undefined, identity);
        if (result.submitted > 0) {
          window.dispatchEvent(new CustomEvent(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, {
            detail: result,
          }));
        }

        if (result.submitted > 0 && 'vibrate' in navigator) {
          navigator.vibrate(200);
        }
      }).catch(() => {
        // Authentication or connectivity failures leave every record untouched.
      });
    };

    synchronize();
    window.addEventListener('online', synchronize);

    return () => {
      window.removeEventListener('online', synchronize);
    };
  }, []);

  return null;
}
