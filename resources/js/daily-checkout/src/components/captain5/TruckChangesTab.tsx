import { ArrowLeftRight, Truck } from 'lucide-react';
import { EmptyState } from '../ui/StateViews';
import Tag from '../ui/Tag';
import { dateTimeText } from './format';
import type { RescueTruck, RescueTruckChange } from './types';
import { HINT } from './ui';

const IN_SERVICE = 'in service';
const truckLabel = (number: string | null): string => (number ? `truck ${number}` : 'a truck not on record');

function TruckRow({ truck }: { truck: RescueTruck }) {
  return (
    <li data-testid="captain5-truck-row" className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-2">
      <strong className="text-hub-ink">{truck.unit}</strong>
      <span className="tabular-nums text-hub-ink">{truck.vehicle_number ? `Truck ${truck.vehicle_number}` : 'No truck on record'}</span>
      {truck.station && <span className={HINT}>{truck.station}</span>}
      <span className="flex flex-wrap items-center gap-1.5 sm:ml-auto">
        {truck.status.toLowerCase() !== IN_SERVICE && <Tag>{truck.status}</Tag>}
        {truck.changed_recently && <Tag tone="info">Changed {dateTimeText(truck.changed_at)}</Tag>}
      </span>
    </li>
  );
}

function ChangeFacts({ change }: { change: RescueTruckChange }) {
  return (
    <>
      <span className="break-words font-semibold text-hub-ink">{change.unit}: {truckLabel(change.from_vehicle_number)} to {truckLabel(change.to_vehicle_number)}</span>
      <span className={`${HINT} block break-words`}>
        <time dateTime={change.at ?? undefined}>{dateTimeText(change.at)}</time>
        {change.by ? ` · ${change.by}` : ''}
        {change.left_behind.length > 0 ? ` · ${change.left_behind.length} left behind` : ''}
      </span>
    </>
  );
}

function ChangeRow({ change }: { change: RescueTruckChange }) {
  const hasMore = change.left_behind.length > 0 || change.note !== null;
  if (!hasMore) {
    return (
      <li data-testid="captain5-truck-change" className="min-h-11 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-2">
        <ChangeFacts change={change} />
      </li>
    );
  }
  return (
    <li data-testid="captain5-truck-change">
      <details className="rounded-hub-control border border-hub-border bg-hub-well px-3.5">
        <summary className="min-h-11 cursor-pointer py-2"><ChangeFacts change={change} /></summary>
        <div className="space-y-2 pb-3">
          {change.note && <p className="break-words text-sm text-hub-ink">Note: {change.note}</p>}
          {change.left_behind.length > 0 && (
            <div>
              <p className="text-sm font-semibold text-hub-ink">Left behind on {truckLabel(change.from_vehicle_number)}</p>
              <ul className="list-disc space-y-1 pl-5 text-sm text-hub-ink">
                {change.left_behind.map((line, index) => <li key={`${line.name}-${index}`} className="break-words">{line.name}{line.note ? `: ${line.note}` : ''}</li>)}
              </ul>
            </div>
          )}
        </div>
      </details>
    </li>
  );
}

/** Read only: which Rescue unit is in which vehicle number, and what moved lately. */
export default function TruckChangesTab({ trucks, changes }: { trucks: RescueTruck[]; changes: RescueTruckChange[] }) {
  return (
    <div className="space-y-8">
      <section aria-labelledby="c5-trucks-heading" className="space-y-3">
        <h3 id="c5-trucks-heading" className="font-heading text-lg font-bold text-hub-ink">Who is on what truck</h3>
        {trucks.length === 0
          ? <EmptyState icon={<Truck className="hub-empty__icon" aria-hidden="true" />} title="No Rescue units to show" body="Rescue units appear here once Fleet has them on record." />
          : <ul className="grid gap-2 lg:grid-cols-2" aria-label="Rescue units and their trucks" data-testid="captain5-truck-list">{trucks.map((truck) => <TruckRow key={truck.apparatus_id} truck={truck} />)}</ul>}
      </section>
      <section aria-labelledby="c5-truck-changes-heading" className="space-y-3">
        <h3 id="c5-truck-changes-heading" className="font-heading text-lg font-bold text-hub-ink">Recent changes</h3>
        {changes.length === 0
          ? <EmptyState icon={<ArrowLeftRight className="hub-empty__icon" aria-hidden="true" />} title="No Rescue truck changes in the last 30 days" body="A completed truck change for a Rescue shows here, with who made it and anything left behind." />
          : (
            <>
              <p className={HINT}>Last 30 days, newest first. Times are Eastern.</p>
              <ul className="space-y-2" aria-label="Recent Rescue truck changes" data-testid="captain5-truck-changes">{changes.map((change) => <ChangeRow key={change.id} change={change} />)}</ul>
            </>
          )}
      </section>
    </div>
  );
}
