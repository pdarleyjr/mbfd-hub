import { useEffect, useRef, useState, type MouseEvent } from 'react';
import { Link } from 'react-router';
import { Check, ChevronRight, ClipboardCheck, ClipboardList, Truck } from 'lucide-react';
import type { StationCheckoutRow, StationOverview, StationOverviewUnit, StationRequestSummary } from '../../types';
import { formatHubDate } from '../../../../shared/timezone.ts';
import Tag, { StatusTag } from '../ui/Tag';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import CheckoutSheet from './CheckoutSheet';
import { clockTime, dayAndTime, unitLabel } from './format';
import StationRequestSheet from './StationRequestSheet';

const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';
const ROW = 'hub-well flex min-h-14 w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:border-hub-blue/40 sm:px-4';

interface StationOverviewPanelProps {
  stationId: number;
  overview: StationOverview | null;
  loading: boolean;
  error: string | null;
  onRetry: () => void;
  onViewAllRequests: () => void;
}

export default function StationOverviewPanel({ stationId, overview, loading, error, onRetry, onViewAllRequests }: StationOverviewPanelProps) {
  const [openCheckout, setOpenCheckout] = useState<StationCheckoutRow | null>(null);
  const [openRequest, setOpenRequest] = useState<StationRequestSummary | null>(null);
  const opener = useRef<HTMLElement | null>(null);
  useEffect(() => {
    setOpenCheckout(null);
    setOpenRequest(null);
  }, [stationId]);
  const remember = (event: MouseEvent<HTMLElement>) => { opener.current = event.currentTarget; };

  if (overview === null) {
    if (error) return <LoadError title="The station overview could not be loaded" message={error} onRetry={onRetry} />;
    if (loading) return <SkeletonRows rows={4} />;
    return <LoadError title="The station overview could not be loaded" onRetry={onRetry} />;
  }

  const checkoutById = new Map(overview.checkouts.map((checkout) => [checkout.id, checkout]));

  return (
    <div className="space-y-6" data-testid="station-overview">
      <UnitStatus overview={overview} stationId={stationId} onOpen={(id, event) => { remember(event); setOpenCheckout(checkoutById.get(id) ?? null); }} />
      <div className="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="station-checkouts-heading">
          <SectionHeading id="station-checkouts-heading" title="Vehicle checkouts" count={overview.checkouts.length} detail="Submitted this shift. Select one to see the whole checkout." />
          {overview.checkouts.length > 0 ? (
            <ul className="space-y-2">
              {overview.checkouts.map((checkout) => (
                <li key={checkout.id}>
                  <button type="button" className={ROW} onClick={(event) => { remember(event); setOpenCheckout(checkout); }} data-testid="station-checkout-row">
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold text-hub-ink">
                        {checkout.designation}
                        {unitLabel(checkout) && <span className={`ml-2 font-normal ${SECONDARY}`}>{unitLabel(checkout)}</span>}
                      </span>
                      <span className={`block break-words ${SECONDARY}`}>{checkout.submitted_by ?? 'Submitter not recorded'}{checkout.shift ? ` · ${checkout.shift} Shift` : ''}</span>
                    </span>
                    <span className="flex flex-none flex-col items-end gap-1">
                      <span className="text-[13px] font-medium leading-5 tabular-nums text-hub-n-800">{checkout.submitted_at ? clockTime(checkout.submitted_at) : ''}</span>
                      {checkout.needs_attention && <Tag tone="warning">{checkout.status}</Tag>}
                    </span>
                    <ChevronRight className="h-4 w-4 flex-none text-hub-n-500" aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ul>
          ) : <EmptyState icon={<ClipboardCheck className="hub-empty__icon" aria-hidden="true" />} title="No checkouts yet this shift" body="Each vehicle checkout appears here as soon as it is submitted." />}
        </section>
        <section aria-labelledby="station-open-requests-heading">
          <SectionHeading id="station-open-requests-heading" title="Station requests" count={overview.requests_total} detail="All open requests, plus any submitted in the last 24 hours." />
          {overview.requests.length > 0 ? (
            <ul className="space-y-2">
              {overview.requests.map((request) => (
                <li key={request.id}>
                  <button type="button" className={ROW} onClick={(event) => { remember(event); setOpenRequest(request); }} data-testid="station-request-row">
                    <span className="min-w-0 flex-1">
                      <span className="block break-words font-semibold text-hub-ink">{request.title}</span>
                      <span className={`block ${SECONDARY}`}>{request.room?.name || request.room_name_snapshot || 'Station-wide'} · {formatHubDate(request.created_at)}</span>
                    </span>
                    <span className="flex flex-none flex-col items-end gap-1">
                      <StatusTag status={request.status} />
                    </span>
                    <ChevronRight className="h-4 w-4 flex-none text-hub-n-500" aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ul>
          ) : <EmptyState icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />} title="No open or recent requests" body="Nothing is open, and nothing was submitted in the last 24 hours." />}
          {overview.requests_total > overview.requests.length && (
            <button type="button" className="hub-link hub-link--standalone mt-2 min-h-11" onClick={onViewAllRequests}>
              Showing {overview.requests.length} of {overview.requests_total}, open requests first. View all requests
            </button>
          )}
        </section>
      </div>
      <CheckoutSheet stationId={stationId} row={openCheckout} onClose={() => setOpenCheckout(null)} returnFocusTo={() => opener.current} />
      <StationRequestSheet request={openRequest} onClose={() => setOpenRequest(null)} returnFocusTo={() => opener.current} />
    </div>
  );
}

function SectionHeading({ id, title, count, detail }: { id: string; title: string; count: number; detail: string }) {
  return (
    <div className="mb-3">
      <h3 id={id} className="font-heading text-lg font-semibold text-hub-ink">
        {title} <span className="ml-1 text-sm font-semibold tabular-nums text-hub-n-600">{count}</span>
      </h3>
      <p className={SECONDARY}>{detail}</p>
    </div>
  );
}

type OpenCheckout = (inspectionId: number, event: MouseEvent<HTMLElement>) => void;

function UnitStatus({ overview, stationId, onOpen }: { overview: StationOverview; stationId: number; onOpen: OpenCheckout }) {
  const owed = overview.units.filter((unit) => unit.frontline && unit.state !== 'out_of_service');
  const turnedIn = owed.filter((unit) => unit.state === 'submitted').length;

  return (
    <section aria-labelledby="station-unit-status-heading">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div>
          <h3 id="station-unit-status-heading" className="font-heading text-lg font-semibold text-hub-ink">Vehicle inspections this shift</h3>
          <p className={`tabular-nums ${SECONDARY}`} data-testid="station-shift-window">{dayAndTime(overview.shift_window.starts_at)} to {dayAndTime(overview.shift_window.ends_at)}</p>
        </div>
        {owed.length > 0 && <p className="text-sm font-semibold tabular-nums text-hub-ink" data-testid="station-unit-count">{turnedIn} of {owed.length} turned in</p>}
      </div>
      {overview.units.length > 0 ? (
        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
          {overview.units.map((unit) => <li key={unit.apparatus_id}><UnitTile unit={unit} stationId={stationId} onOpen={onOpen} /></li>)}
        </ul>
      ) : <EmptyState icon={<Truck className="hub-empty__icon" aria-hidden="true" />} title="No units owe a daily inspection here" />}
    </section>
  );
}

function UnitTile({ unit, stationId, onOpen }: { unit: StationOverviewUnit; stationId: number; onOpen: OpenCheckout }) {
  const shell = 'hub-well flex h-full min-h-[4.75rem] w-full flex-col items-start gap-1 p-3 text-left';
  const interactive = `${shell} transition-colors hover:border-hub-blue/40`;
  const name = <span className="font-semibold leading-5 text-hub-ink">{unit.designation}</span>;

  if (unit.state === 'submitted' && unit.inspection_id) {
    const inspectionId = unit.inspection_id;
    return (
      <button type="button" data-testid="station-unit-tile" className={interactive} onClick={(event) => onOpen(inspectionId, event)}>
        {name}
        <Tag tone="success"><Check className="h-3.5 w-3.5" aria-hidden="true" />{unit.submitted_at ? `Turned in ${clockTime(unit.submitted_at)}` : 'Turned in'}</Tag>
      </button>
    );
  }
  if (unit.state === 'out_of_service') {
    return <div data-testid="station-unit-tile" className={shell}>{name}<Tag>Out of service</Tag></div>;
  }

  const notTurnedIn = <Tag tone="warning">Not turned in</Tag>;
  return unit.slug ? (
    <Link data-testid="station-unit-tile" className={interactive} to={`/vehicle-inspections/${unit.slug}?return_to=${encodeURIComponent(`/stations/${stationId}`)}`} aria-label={`${unit.designation}: not turned in. Start inspection`}>
      {name}
      {notTurnedIn}
    </Link>
  ) : <div data-testid="station-unit-tile" className={shell}>{name}{notTurnedIn}</div>;
}
