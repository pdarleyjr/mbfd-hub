import { useEffect, useState } from 'react';
import { countSavedWorkElsewhere, otherPrimaryOrigin } from './savedWorkElsewhere';

const DISMISSED_KEY = 'mbfd-daily-saved-work-dismissed';

const wasDismissed = (): boolean => {
  try {
    return sessionStorage.getItem(DISMISSED_KEY) === '1';
  } catch {
    return false;
  }
};

/** Shown only on the rare device that still has unsent Daily work on the other Hub host. */
export default function SavedWorkElsewhereNotice() {
  const [primary] = useState(() => otherPrimaryOrigin());
  const [pending, setPending] = useState(0);
  const [dismissed, setDismissed] = useState(wasDismissed);

  useEffect(() => {
    if (primary === null || dismissed || !navigator.onLine) return undefined;
    let active = true;
    void countSavedWorkElsewhere(primary).then((count) => {
      if (active) setPending(count);
    });

    return () => {
      active = false;
    };
  }, [primary, dismissed]);

  if (primary === null || dismissed || pending === 0) return null;

  const dismiss = () => {
    try {
      sessionStorage.setItem(DISMISSED_KEY, '1');
    } catch {
      // The notice simply returns on the next page load.
    }
    setDismissed(true);
  };

  return (
    <div role="status" data-testid="daily-saved-work-elsewhere" className="hub-callout hub-callout--warning mb-4 flex flex-wrap items-center gap-3 p-3 text-sm">
      <p className="m-0 min-w-0 flex-1">
        You have {pending === 1 ? '1 saved Daily Checkout item' : `${pending} saved Daily Checkout items`} from before the last update. Your work is safe. Open it to finish and send.
      </p>
      <a className="hub-btn hub-btn--primary" href={`${primary}/daily/stations`} data-hub-keep-host>Open saved work</a>
      <button type="button" className="hub-btn hub-btn--secondary" onClick={dismiss}>Not now</button>
    </div>
  );
}
