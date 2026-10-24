import { useEffect } from 'react';
import { db } from '../lib/db';
import {
  DAILY_CHECKOUT_QUEUE_SYNC_EVENT,
  getDailyCheckoutQueueSummary,
  onDailyCheckoutQueueChanged,
} from '../utils/dailyCheckoutSubmissionQueue';
import { startFormEditWatcher } from './formEditBusy.ts';
import { startQueueBusyWatcher } from './queueBusy.ts';

/** Generic form records that will still be replayed (not parked for attention, not out of retries). */
const genericReplayableCount = (): Promise<number> => db.pendingSubmissions
  .where('status').anyOf('pending', 'processing').count();

/**
 * Connects Daily Checkout's offline work to the shared update registrar
 * (resources/js/pwa). Renders nothing. It keeps two "busy" reasons truthful so an
 * update never reloads the page underneath a member:
 *
 *  - 'queued-submission' while the offline queue still has records to send;
 *  - 'unsaved-draft' while an in-memory form holds edits (the inspection autosaver
 *    and the command composer report their own drafts from where they are saved).
 *
 * Mounted once, for the whole Daily app, next to the queue processor.
 */
export default function DailyPwaBridge() {
  useEffect(() => {
    const stopQueue = startQueueBusyWatcher({
      countPending: async () => {
        const [daily, generic] = await Promise.all([getDailyCheckoutQueueSummary(), genericReplayableCount()]);

        return daily.pending + generic;
      },
      subscribe: (listener) => {
        const unsubscribeQueue = onDailyCheckoutQueueChanged(listener);
        window.addEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, listener);

        return () => {
          unsubscribeQueue();
          window.removeEventListener(DAILY_CHECKOUT_QUEUE_SYNC_EVENT, listener);
        };
      },
    });
    const stopForms = startFormEditWatcher();

    return () => {
      stopQueue();
      stopForms();
    };
  }, []);

  return null;
}
