import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useOffline } from '../../hooks/useOffline';
import { ApiRequestError, isApiAuthenticationError, redirectToLoginAfterSessionExpiry } from '../../utils/api';
import { alertActionPath } from '../changeout/changeoutLogic';
import type { OperationalAlert } from '../changeout/types';
import Tag from '../ui/Tag';
import type { Tone } from '../ui/status';
import { getStationOperationalAlerts } from '../../utils/changeoutApi';

const WORKFLOW_TONES: Record<string, Tone> = {
  planned: 'info',
  vehicle_changed: 'success',
  awaiting_fleet: 'warning',
  awaiting_verification: 'warning',
};

function AlertCard({ alert, stationId }: { alert: OperationalAlert; stationId: number }) {
  const truck = alert.current_vehicle_number ?? alert.vehicle_number;
  const facts = [truck ? `Truck ${truck}` : null, alert.schedule_label].filter(Boolean).join(' · ');
  const showReason = alert.reason !== null && !(alert.message ?? '').toLocaleLowerCase().includes(alert.reason.toLocaleLowerCase());
  const actionPath = alertActionPath(alert.action, stationId);

  return (
    <article data-testid="unit-alert" aria-labelledby={`unit-alert-${alert.id}`} className="hub-panel p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <h2 id={`unit-alert-${alert.id}`} className="min-w-0 font-heading text-lg font-semibold text-hub-ink">{alert.title}</h2>
        <div className="flex flex-wrap gap-2">
          {alert.unit_designation !== '' && <Tag>{alert.unit_designation}</Tag>}
          {alert.workflow && <Tag tone={WORKFLOW_TONES[alert.workflow.state] ?? 'info'}>{alert.workflow.label}</Tag>}
        </div>
      </div>
      {facts !== '' && <p className="mt-1 text-sm font-semibold tabular-nums text-hub-n-800">{facts}</p>}
      {alert.date_passed && <p className="mt-1 text-sm font-semibold text-hub-warning-strong">Planned date has passed</p>}
      {alert.message && <p className="mt-2 text-sm text-hub-n-800">{alert.message}</p>}
      {showReason && <p className="mt-2 text-sm text-hub-n-800"><span className="font-semibold">Reason:</span> {alert.reason}</p>}
      {alert.instructions && <p className="mt-2 text-sm text-hub-n-800"><span className="font-semibold">Instructions:</span> {alert.instructions}</p>}
      {actionPath && alert.action && (
        <Link to={actionPath} className="hub-btn hub-btn--primary hub-btn--wrap mt-3 !min-h-11 w-full sm:w-auto">{alert.action.label || 'Open'}</Link>
      )}
    </article>
  );
}

/** An answer that might be hiding a recall: no answer at all, too many requests, or a server failure. A 404 is an answer. */
const mightHideAnAlert = (failure: unknown): boolean => (
  failure instanceof ApiRequestError ? failure.status === 429 || failure.status >= 500 : true
);

/**
 * Operational alerts for the units at this station. Nothing shows while loading or when there is nothing to say, and
 * it never interrupts the station page. A failed check while online gets one quiet line, because the alert it could
 * not fetch may be a recall.
 */
export default function UnitAlertsBanner({ stationId }: { stationId: number }) {
  const isOffline = useOffline();
  const [alerts, setAlerts] = useState<OperationalAlert[]>([]);
  const [failed, setFailed] = useState(false);
  const [checking, setChecking] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => { setAlerts([]); }, [stationId]);

  useEffect(() => {
    setFailed(false);
    // Offline stays silent, and coming back online checks again.
    if (isOffline) return;
    let active = true;
    setChecking(true);
    getStationOperationalAlerts(stationId)
      .then((next) => { if (active) setAlerts(next); })
      .catch((failure: unknown) => {
        if (!active) return;
        if (isApiAuthenticationError(failure)) redirectToLoginAfterSessionExpiry();
        else setFailed(mightHideAnAlert(failure));
      })
      .finally(() => { if (active) setChecking(false); });

    return () => { active = false; };
  }, [attempt, isOffline, stationId]);

  if (alerts.length === 0 && !failed) return null;

  return (
    <section data-testid="unit-alerts" aria-label="Unit alerts" className="space-y-3">
      {alerts.map((alert) => <AlertCard key={alert.id} alert={alert} stationId={stationId} />)}
      {failed && (
        <p role="status" className="flex flex-wrap items-center gap-x-3 text-sm text-hub-n-600">
          Could not check for unit alerts.
          <button type="button" disabled={checking} onClick={() => setAttempt((current) => current + 1)} className="hub-link hub-link--standalone min-h-11">Try again</button>
        </p>
      )}
    </section>
  );
}
