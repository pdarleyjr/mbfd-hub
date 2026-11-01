import { useId, useState } from 'react';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { MAX_VEHICLE_ID_LENGTH } from '../../utils/vehicleConfirmation';
import Tag from '../ui/Tag';
import { OLD_TRUCK_STATUS_AFTER, realUnitLabel } from './changeoutLogic';
import type { ChangeoutContext, ChangeoutDraft, PreviousChangeout, ResolvedTruck, TruckChoice } from './types';

interface Props {
  context: ChangeoutContext;
  draft: ChangeoutDraft;
  truck: ResolvedTruck | null;
  /** The current problem, shown after the member tried to continue. */
  problem: string | null;
  onChoose: (choice: TruckChoice) => void;
  onTyped: (value: string) => void;
}

const CARD = 'hub-well flex min-h-[4.5rem] cursor-pointer items-center gap-3 p-4 transition-colors motion-reduce:transition-none';
const CARD_SELECTED = '!border-hub-blue !bg-hub-blue-tint ring-2 ring-hub-blue';
const RADIO = 'h-6 w-6 flex-none border-hub-control-border text-hub-blue focus:ring-hub-focus';

interface TruckCardProps {
  name: string;
  vehicle: { vehicle_number: string; location: string | null; status: string | null };
  checked: boolean;
  isReturn?: boolean;
  listedAs?: string | null;
  onChoose: () => void;
}

function TruckCard({ name, vehicle, checked, isReturn = false, listedAs = null, onChoose }: TruckCardProps) {
  const details = [vehicle.location, vehicle.status].filter(Boolean).join(' · ');

  return (
    <label className={`${CARD} ${checked ? CARD_SELECTED : ''}`}>
      <input type="radio" name={name} checked={checked} onChange={onChoose} className={RADIO} />
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-2">
          <span className="text-2xl font-bold tabular-nums text-hub-ink">{vehicle.vehicle_number}</span>
          {isReturn && <Tag tone="info">Back to its own truck</Tag>}
          {listedAs && <Tag>Listed as {listedAs}</Tag>}
        </span>
        {details !== '' && <span className="mt-0.5 block text-sm text-hub-n-600">{details}</span>}
      </span>
    </label>
  );
}

function PreviousChangeoutSummary({ previous }: { previous: PreviousChangeout }) {
  return (
    <section aria-labelledby="changeout-previous-heading" className="hub-well p-4">
      <h3 id="changeout-previous-heading" className="font-semibold text-hub-ink">Previous changeout</h3>
      <p className="mt-1 text-sm tabular-nums text-hub-n-600">
        Truck {previous.from_vehicle_number ?? 'not recorded'} to {previous.to_vehicle_number ?? 'not recorded'}
        {previous.effective_at ? ` on ${formatHubDate(previous.effective_at)}` : ''}
        {previous.submitted_by_name ? ` by ${previous.submitted_by_name}` : ''}. Took {previous.moved}.
      </p>
      {previous.left_behind.length > 0 && (
        <div className="mt-3">
          <p className="text-sm font-semibold text-hub-ink">Left on truck {previous.from_vehicle_number ?? 'not recorded'}</p>
          <ul className="mt-1 space-y-1 text-sm text-hub-n-800">
            {previous.left_behind.map((line, index) => <li key={`${line.name}-${index}`}>{line.name}{line.note ? `: ${line.note}` : ''}</li>)}
          </ul>
        </div>
      )}
    </section>
  );
}

/** In plain words, before anything is sent. */
function WhatHappens({ context, truck }: { context: ChangeoutContext; truck: ResolvedTruck | null }) {
  const from = context.unit.vehicle_number;
  if (from === null && truck === null) return null;

  return (
    <div role="status" data-testid="changeout-what-happens" className="hub-callout space-y-1 p-3 text-sm">
      {from !== null && <p>Truck {from} will be marked {OLD_TRUCK_STATUS_AFTER}.</p>}
      {truck?.listedAs && <p>{truck.listedAs} is listed on truck {truck.vehicleNumber}. {truck.listedAs} moves to truck {from ?? 'not recorded'}.</p>}
      {truck?.source === 'typed' && <p>Truck {truck.vehicleNumber} is not on the list yet. It will be added, and Fleet will add the truck's details.</p>}
    </div>
  );
}

export default function TruckStep({ context, draft, truck, problem, onChoose, onTyped }: Props) {
  const name = useId();
  const startsOpen = truck?.source === 'other' || draft.choice?.kind === 'typed' || context.candidates.length === 0;
  const [showOthers, setShowOthers] = useState(startsOpen);
  const { unit } = context;

  if (context.mode === 'handoff_only') {
    return (
      <div className="space-y-4">
        <p className="hub-callout p-3 text-sm">{unit.designation} is already on truck {unit.vehicle_number ?? 'not recorded'}. Record what you took and left.</p>
        {context.previous_changeout && <PreviousChangeoutSummary previous={context.previous_changeout} />}
      </div>
    );
  }

  const selectedId = draft.choice?.kind === 'listed' ? draft.choice.apparatusId : null;
  const typedFieldId = `${name}-typed`;
  const errorId = `${name}-error`;
  const typedValue = draft.choice?.kind === 'typed' ? draft.typedVehicle : '';
  const hasListedUnit = context.other_vehicles.some((vehicle) => realUnitLabel(vehicle.listed_as) !== null);

  return (
    <div className="space-y-4">
      <div role="radiogroup" aria-labelledby="changeout-step-heading" aria-describedby={problem ? errorId : undefined} className="space-y-3">
        {context.candidates.map((candidate) => (
          <TruckCard
            key={candidate.apparatus_id}
            name={name}
            vehicle={candidate}
            checked={selectedId === candidate.apparatus_id}
            isReturn={candidate.is_return}
            onChoose={() => onChoose({ kind: 'listed', apparatusId: candidate.apparatus_id, vehicleNumber: candidate.vehicle_number })}
          />
        ))}

        <button type="button" aria-expanded={showOthers} onClick={() => setShowOthers((open) => !open)} className="hub-btn hub-btn--secondary !min-h-11 w-full">
          Different truck
        </button>

        {showOthers && (
          <div className="space-y-3">
            {hasListedUnit && <p className="text-sm text-hub-n-800">If you take a truck listed as another unit, that unit moves to truck {unit.vehicle_number ?? 'not recorded'}.</p>}
            {context.other_vehicles.map((vehicle) => (
              <TruckCard
                key={vehicle.apparatus_id}
                name={name}
                vehicle={vehicle}
                checked={selectedId === vehicle.apparatus_id}
                listedAs={realUnitLabel(vehicle.listed_as)}
                onChoose={() => onChoose({ kind: 'listed', apparatusId: vehicle.apparatus_id, vehicleNumber: vehicle.vehicle_number })}
              />
            ))}
            <div>
              <label htmlFor={typedFieldId} className="text-sm font-semibold text-hub-ink">Or type the number on the truck</label>
              <input
                id={typedFieldId}
                type="text"
                value={typedValue}
                maxLength={MAX_VEHICLE_ID_LENGTH}
                autoCapitalize="characters"
                autoComplete="off"
                autoCorrect="off"
                spellCheck={false}
                aria-invalid={problem && draft.choice?.kind === 'typed' ? true : undefined}
                aria-describedby={problem ? errorId : undefined}
                onChange={(event) => onTyped(event.target.value)}
                className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 tabular-nums text-hub-ink"
              />
            </div>
          </div>
        )}
      </div>

      {problem && <p id={errorId} role="alert" className="text-sm font-semibold text-hub-danger">{problem}</p>}
      <WhatHappens context={context} truck={truck} />
      {context.previous_changeout && <PreviousChangeoutSummary previous={context.previous_changeout} />}
    </div>
  );
}
