import { useEffect } from 'react';
import {
  announceSubmissionQueuesChanged,
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  getDailyCheckoutReplaySchedule,
  onDailyCheckoutQueueChanged,
  synchronizeDailyCheckoutQueue,
  withDeadline,
} from '../utils/dailyCheckoutSubmissionQueue';
import { READ_TIMEOUT_MS } from '../utils/api';
import { processPendingSubmissions } from '../lib/sync';
import { refreshOfflineIdentity } from '../lib/offlineIdentity';

/**
 * Automatic replay delays while Daily Checkout records stay pending. Each
 * failed pass moves one step further; reconnecting, returning to the app or a
 * successful pass starts again from the first step.
 */
const DAILY_REPLAY_BACKOFF_MS = [15_000, 30_000, 60_000, 120_000, 300_000] as const;

type ReplayReason = 'mount' | 'online' | 'visible' | 'timer';

/**
 * Remains mounted on every Daily route, including the queued success page.
 * That makes replay independent of the inspection wizard lifecycle.
 */
export default function DailyCheckoutQueueProcessor() {
  useEffect(() => {
    let disposed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let failedPasses = 0;

    const scheduleNextReplay = async () => {
      if (timer !== undefined) clearTimeout(timer);
      timer = undefined;
      try {
        const schedule = await getDailyCheckoutReplaySchedule();
        if (disposed || schedule.pending === 0) {
          if (schedule.pending === 0) failedPasses = 0;
          return;
        }
        const backoff = DAILY_REPLAY_BACKOFF_MS[Math.min(failedPasses, DAILY_REPLAY_BACKOFF_MS.length - 1)];
        const serverDelay = schedule.nextAttemptAt !== undefined ? schedule.nextAttemptAt - Date.now() : 0;
        timer = setTimeout(() => synchronize('timer'), Math.max(backoff, serverDelay));
      } catch (error) {
        console.error('Unable to schedule Daily Checkout replay:', error);
      }
    };

    const synchronize = (reason: ReplayReason) => {
      if (!navigator.onLine) {
        return;
      }
      if (reason !== 'timer') failedPasses = 0;

      void withDeadline(refreshOfflineIdentity(), READ_TIMEOUT_MS, 'The member context check timed out.').then(async (identity) => {
        const result = await synchronizeDailyCheckoutQueue(identity);
        // The generic form queue has its own retry budget; keep it on its
        // original triggers (start-up and reconnect) so the backoff timer and
        // tab switches cannot spend that budget.
        if (reason === 'mount' || reason === 'online') {
          const generic = await processPendingSubmissions('/api/public', undefined, identity);
          if (generic.processed > 0 || generic.failed > 0) announceSubmissionQueuesChanged();
        }
        if (result.submitted > 0) {
          failedPasses = 0;
          window.dispatchEvent(new CustomEvent(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, {
            detail: result,
          }));
        } else if (reason === 'timer') {
          failedPasses += 1;
        }

        if (result.submitted > 0 && 'vibrate' in navigator) {
          navigator.vibrate(200);
        }
      }).catch(() => {
        // Authentication or connectivity failures leave every record untouched.
        if (reason === 'timer') failedPasses += 1;
      }).finally(() => {
        if (!disposed) void scheduleNextReplay();
      });
    };

    const onOnline = () => synchronize('online');
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') synchronize('visible');
    };

    synchronize('mount');
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibilityChange);
    // A record queued by the wizard (for example after a failed online send)
    // gets a replay timer without waiting for a reconnect.
    const unsubscribe = onDailyCheckoutQueueChanged(() => {
      if (timer === undefined) void scheduleNextReplay();
    });

    return () => {
      disposed = true;
      if (timer !== undefined) clearTimeout(timer);
      window.removeEventListener('online', onOnline);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      unsubscribe();
    };
  }, []);

  return null;
}
