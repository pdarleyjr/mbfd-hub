import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { useOffline } from '../hooks/useOffline';
import {
  getInstallController,
  shouldShowPromotion,
  type InstallState,
} from '../../../pwa/install-controller';

/**
 * Compact inline "Install MBFD Hub" card for Daily Checkout. Same copy and behavior as the Blade card on
 * Home (components/hub/install-card.blade.php). Renders nothing unless the visitor is eligible: not
 * installed, not snoozed, and the browser can install. "Install" appears only when the browser offers
 * a native prompt; otherwise "Show me how" opens the /install guide.
 */
function useInstallState(): InstallState {
  const controller = getInstallController();
  const cache = useRef<InstallState>(controller.getState());
  return useSyncExternalStore(
    (notify) => controller.subscribe((next) => {
      cache.current = next;
      notify();
    }),
    () => {
      const next = controller.getState();
      // Keep the snapshot referentially stable while nothing changed.
      if (JSON.stringify(next) !== JSON.stringify(cache.current)) cache.current = next;
      return cache.current;
    },
  );
}

export function InstallCard() {
  const controller = getInstallController();
  const state = useInstallState();
  const [dismissed, setDismissed] = useState(false);
  const [busy, setBusy] = useState(false);
  const isOffline = useOffline();
  const cardRef = useRef<HTMLElement>(null);

  useEffect(() => {
    if (!dismissed) return;
    const main = document.getElementById('main-content');
    if (main && !main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
    main?.focus({ preventScroll: true });
  }, [dismissed]);

  // Never offered offline: the guide needs the network and queued inspections sync first.
  if (dismissed || !shouldShowPromotion(state, controller, !isOffline)) return null;

  const dismiss = () => {
    controller.snoozePromotion();
    setDismissed(true);
  };

  const install = async () => {
    setBusy(true);
    try {
      const result = await controller.promptInstall();
      if (result === 'dismissed') dismiss();
    } finally {
      setBusy(false);
    }
  };

  return (
    <section ref={cardRef} data-mbfd-install-card aria-labelledby="mbfd-install-card-title" className="hub-install-card hub-install-card--daily">
      <img className="hub-install-card__icon" src="/images/mbfd_app_icon_96.png" width={48} height={48} alt="" />
      <div className="hub-install-card__body">
        <h2 id="mbfd-install-card-title" className="hub-install-card__title">Install MBFD Hub</h2>
        <p className="hub-install-card__text">
          Open MBFD Hub directly from your Home Screen or desktop for faster access to inspections, forms, notifications, and department tools.
        </p>
        <div className="hub-install-card__actions">
          {state.canPrompt ? (
            <button type="button" className="hub-install-card__btn hub-install-card__btn--primary" onClick={() => void install()} disabled={busy}>Install</button>
          ) : (
            <a className="hub-install-card__btn hub-install-card__btn--primary" href="/install">Show me how</a>
          )}
          <button type="button" className="hub-install-card__btn" onClick={dismiss}>Not now</button>
        </div>
      </div>
    </section>
  );
}

export default InstallCard;
