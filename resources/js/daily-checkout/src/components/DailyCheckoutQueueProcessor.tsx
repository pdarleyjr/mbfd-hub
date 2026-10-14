import { useEffect } from 'react';
import {
  announceSubmissionQueuesChanged,
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT,
  getDailyCheckoutReplaySchedule,
  onDailyCheckoutQueueChanged,
  synchronizeDailyCheckoutQueue,
  withDeadline,
} from '../utils/dailyCheckoutSubmissionQueue';
import { READ_TIMEOUT_MS } from '../utils/api';
import { processPendingSubmissions } from '../lib/sync';
import { db } from '../lib/db';
import { OfflineIdentitySignInRequiredError, refreshOfflineIdentity } from '../lib/offlineIdentity';

/**
 * Automatic replay delays while Daily Checkout records stay pending. Each
 * failed pass moves one step further; reconnecting or a successful pass
 * starts again from the first step. Returning to the app does not reset it.
 */
const DAILY_REPLAY_BACKOFF_MS = [15_000, 30_000, 60_000, 120_000, 300_000] as const;

type ReplayReason = 'mount' | 'online' | 'visible' | 'timer';

const announceSignInRequired = (required: boolean): void => {
  window.dispatchEvent(new CustomEvent(DAILY_CHECKOUT_SIGN_IN_REQUIRED_EVENT, { detail: required }));
};

/** Generic form records still eligible for replay, counted from the index. */
const genericPendingCount = (): Promise<number> => db.pendingSubmissions
  .where('status').anyOf('pending', 'failed').count();

/**
 * Remains mounted on every Daily route, including the queued success page.
 * That makes replay independent of the inspection wizard lifecycle. Only the
 * start-up check may send a signed-out member to sign in; later background
 * replays never navigate away from open work.
 */
export default function DailyCheckoutQueueProcessor() {
  useEffect(() => {
    let disposed = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let scheduleGeneration = 0;
    let failedPasses = 0;

    const currentBackoff = () => DAILY_REPLAY_BACKOFF_MS[Math.min(failedPasses, DAILY_REPLAY_BACKOFF_MS.length - 1)];

    const scheduleNextReplay = async () => {
      // Only the newest scheduling request may set the timer.
      const generation = ++scheduleGeneration;
      if (timer !== undefined) clearTimeout(timer);
      timer = undefined;
      try {
        const schedule = await getDailyCheckoutReplaySchedule();
        if (disposed || generation !== scheduleGeneration) return;
        if (schedule.pending === 0) {
          failedPasses = 0;
          return;
        }
        const serverDelay = schedule.nextAttemptAt !== undefined ? schedule.nextAttemptAt - Date.now() : 0;
        timer = setTimeout(() => synchronize('timer'), Math.max(currentBackoff(), serverDelay));
      } catch (error) {
        console.error('Unable to schedule Daily Checkout replay:', error);
      }
    };

    const runReplay = async (reason: ReplayReason) => {
      // Start-up and reconnect keep their original behaviour (verify the
      // member, then replay both queues). Returning to the app and the
      // backoff timer only act when Daily Checkout work is waiting.
      const includeGenericQueue = reason === 'mount' || reason === 'online';
      const schedule = await getDailyCheckoutReplaySchedule();
      if (!includeGenericQueue && schedule.pending === 0) {
        failedPasses = 0;
        return;
      }
      if (reason === 'visible') {
        // An app switch is not a reason to resend multi-MB payloads during
        // an outage: respect Retry-After and the current backoff window.
        const now = Date.now();
        if (schedule.pending === 0
          || (schedule.nextAttemptAt !== undefined && schedule.nextAttemptAt > now)
          || (schedule.lastAttemptAt !== undefined && now - schedule.lastAttemptAt < currentBackoff())) {
          return;
        }
      }

      let identity;
      try {
        identity = await withDeadline(
          // App start keeps the canonical session-expiry redirect (nothing has
          // been edited yet). Reconnect, app switch and timer replays happen
          // mid-work, so they show a sign-in notice instead of navigating.
          refreshOfflineIdentity({ redirectOnExpiry: reason === 'mount' }),
          READ_TIMEOUT_MS,
          'The member context check timed out.',
        );
      } catch (error) {
        if (error instanceof OfflineIdentitySignInRequiredError) announceSignInRequired(true);
        throw error;
      }
      announceSignInRequired(false);

      const result = await synchronizeDailyCheckoutQueue(identity);
      // The generic form queue has its own retry budget; keep it on its
      // original triggers (start-up and reconnect) so the backoff timer and
      // tab switches cannot spend that budget.
      if (includeGenericQueue && await genericPendingCount() > 0) {
        const generic = await processPendingSubmissions('/api/public', undefined, identity);
        if (generic.processed > 0 || generic.failed > 0) announceSubmissionQueuesChanged();
      }
      if (result.submitted > 0) {
        failedPasses = 0;
        window.dispatchEvent(new CustomEvent(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, {
          detail: result,
        }));
        if ('vibrate' in navigator) navigator.vibrate(200);
      } else if (reason === 'timer' && result.remaining > 0) {
        failedPasses += 1;
      }
    };

    const synchronize = (reason: ReplayReason) => {
      if (!navigator.onLine) {
        return;
      }
      if (reason === 'mount' || reason === 'online') failedPasses = 0;

      void runReplay(reason).catch(() => {
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
      scheduleGeneration += 1;
      if (timer !== undefined) clearTimeout(timer);
      window.removeEventListener('online', onOnline);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      unsubscribe();
    };
  }, []);

  return null;
}
