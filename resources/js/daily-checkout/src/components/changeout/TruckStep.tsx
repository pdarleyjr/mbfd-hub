import { useId, useState } from 'react';
import { formatHubDate } from '../../../../shared/timezone.ts';
import { MAX_VEHICLE_ID_LENGTH } from '../../utils/vehicleConfirmation';
import Tag from '../ui/Tag';
import { humanize } from '../ui/status';
import { resolveTruck, truckNeedsFleet } from './changeoutLogic';
import type { ChangeoutContext, ChangeoutDraft, OtherVehicle, PreviousChangeout, TruckChoice } from './types';

interface Props {
  context: ChangeoutContext;
  draft: ChangeoutDraft;
  /** The current problem, shown after the member tried to continue. */
  problem: string | null;
  onChoose: (choice: TruckChoice) => void;
  onTyped: (value: string) => void;
}

const FLEET_NOTE = 'Fleet will confirm this truck. Your work is saved either way.';

const CARD = 'hub-well flex min-h-11 cursor-pointer items-start gap-3 p-4 transition-colors motion-reduce:transition-none';
const CARD_SELECTED = '!border-hub-blue !bg-hub-blue-tint ring-2 ring-hub-blue';
const RADIO = 'mt-0.5 h-5 w-5 flex-none border-hub-control-border text-hub-blue focus:ring-hub-focus';

interface TruckCardProps {
  name: string;
  vehicle: OtherVehicle;
  checked: boolean;
  isReturn?: boolean;
  onChoose: () => void;
}

function TruckCard({ name, vehicle, checked, isReturn = false, onChoose }: TruckCardProps) {
  const details = [vehicle.label, vehicle.location, vehicle.status].filter(Boolean).join(' · ');

  return (
    <label className={`${CARD} ${checked ? CARD_SELECTED : ''}`}>
      <input type="radio" name={name} checked={checked} onChange={onChoose} className={RADIO} />
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-2">
          <span className="text-xl font-bold tabular-nums text-hub-ink">{vehicle.vehicle_number}</span>
          {isReturn && <Tag tone="info">Back to its own truck</Tag>}
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
        {previous.submitted_by_name ? ` by ${previous.submitted_by_name}` : ''}. {previous.moved} moved.
      </p>
      {previous.left_behind.length > 0 && (
        <div className="mt-3">
          <p className="text-sm font-semibold text-hub-ink">Left behind</p>
          <ul className="mt-1 space-y-1 text-sm text-hub-n-800">
            {previous.left_behind.map((line) => <li key={line.name}>{line.name}{line.note ? `: ${line.note}` : ''}</li>)}
          </ul>
        </div>
      )}
      {previous.open.length > 0 && (
        <div className="mt-3">
          <p className="text-sm font-semibold text-hub-ink">Still open</p>
          <ul className="mt-1 space-y-1 text-sm text-hub-n-800">
            {previous.open.map((line) => <li key={line.name}>{line.name}{line.status ? ` (${humanize(line.status).toLocaleLowerCase()})` : ''}</li>)}
          </ul>
        </div>
      )}
    </section>
  );
}

export default function TruckStep({ context, draft, problem, onChoose, onTyped }: Props) {
  const name = useId();
  const startsOpen = draft.choice?.kind === 'typed'
    || (draft.choice?.kind === 'listed' && context.other_vehicles.some((vehicle) => draft.choice?.kind === 'listed' && vehicle.apparatus_id === draft.choice.apparatusId))
    || context.candidates.length === 0;
  const [showOthers, setShowOthers] = useState(startsOpen);
  const { unit } = context;

  if (context.mode === 'handoff_only') {
    return (
      <div className="space-y-4">
        <p className="hub-callout p-3 text-sm">{unit.designation} is already on truck {unit.vehicle_number ?? 'not recorded'}. Record what moved with it.</p>
        {context.previous_changeout && <PreviousChangeoutSummary previous={context.previous_changeout} />}
      </div>
    );
  }

  const truck = resolveTruck(draft, context);
  const selectedId = draft.choice?.kind === 'listed' ? draft.choice.apparatusId : null;
  const typedSelected = draft.choice?.kind === 'typed';
  const typedFieldId = `${name}-typed`;
  const errorId = `${name}-error`;

  return (
    <div className="space-y-4">
      <div role="radiogroup" aria-labelledby="changeout-step-heading" aria-describedby={problem ? errorId : undefined} className="space-y-3">
        {context.candidates.length === 0 && <p className="text-sm text-hub-n-600">No replacement truck is ready right now. Fleet can confirm one you name below.</p>}
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

        <button type="button" aria-expanded={showOthers} onClick={() => setShowOthers((open) => !open)} className="hub-link hub-link--standalone min-h-11">
          Truck not listed?
        </button>

        {showOthers && (
          <div className="space-y-3">
            {context.other_vehicles.map((vehicle) => (
              <TruckCard
                key={vehicle.apparatus_id}
                name={name}
                vehicle={vehicle}
                checked={selectedId === vehicle.apparatus_id}
                onChoose={() => onChoose({ kind: 'listed', apparatusId: vehicle.apparatus_id, vehicleNumber: vehicle.vehicle_number })}
              />
            ))}
            <label className={`${CARD} ${typedSelected ? CARD_SELECTED : ''}`}>
              <input type="radio" name={name} checked={typedSelected} onChange={() => onChoose({ kind: 'typed' })} className={RADIO} />
              <span className="font-semibold text-hub-ink">Enter the truck number</span>
            </label>
            {typedSelected && (
              <div>
                <label htmlFor={typedFieldId} className="text-sm font-semibold text-hub-ink">Number on the truck</label>
                <input
                  id={typedFieldId}
                  type="text"
                  value={draft.typedVehicle}
                  maxLength={MAX_VEHICLE_ID_LENGTH}
                  autoCapitalize="characters"
                  autoComplete="off"
                  autoCorrect="off"
                  spellCheck={false}
                  aria-invalid={problem ? true : undefined}
                  aria-describedby={problem ? errorId : undefined}
                  onChange={(event) => onTyped(event.target.value)}
                  className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 tabular-nums text-hub-ink"
                />
              </div>
            )}
          </div>
        )}
      </div>

      {problem && <p id={errorId} role="alert" className="text-sm font-semibold text-hub-danger">{problem}</p>}
      {truck && truckNeedsFleet(truck) && <p role="status" className="hub-callout p-3 text-sm">{FLEET_NOTE}</p>}
      {context.previous_changeout && <PreviousChangeoutSummary previous={context.previous_changeout} />}
    </div>
  );
}
