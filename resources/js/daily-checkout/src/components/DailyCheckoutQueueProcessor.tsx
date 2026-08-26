import { useEffect } from 'react';
import { synchronizeDailyCheckoutQueue } from '../utils/dailyCheckoutSubmissionQueue';

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

      void synchronizeDailyCheckoutQueue().then(({ submitted }) => {
        if (submitted > 0 && 'vibrate' in navigator) {
          navigator.vibrate(200);
        }
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
