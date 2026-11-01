import { useRef, useState } from 'react';
import { Link } from 'react-router';
import type { FleetVehicle, OfficerInfo, Shift, VehicleConfirmation, VehicleIdentification, VehicleIdentificationAnswers } from '../types';
import { activeVehicleIdentification, firstIncompleteField, optionalIdentification } from '../utils/vehicleIdentification';
import VehicleIdentificationCard, { focusIdentificationField } from './VehicleIdentificationCard';
import {
  MAX_VEHICLE_ID_LENGTH,
  NOT_LISTED,
  defaultVehicleConfirmation,
  dropdownVehicles,
  fleetVehicleLabel,
  isVehicleMismatch,
  reportedVehicle,
} from '../utils/vehicleConfirmation';

interface Props {
  initialData: OfficerInfo;
  onSubmit: (data: OfficerInfo) => void;
  onChange: (data: OfficerInfo) => void;
  continueLabel: string;
  /** Every fleet vehicle ID, frontline and reserve. May be empty offline. */
  fleetVehicles: FleetVehicle[];
  /** The unit designation (for example E2) shown beside its assigned vehicle. */
  assignedDesignation?: string;
  /** False for a unit with no fleet vehicle ID at all: nothing about a physical vehicle is asked or required. */
  usesVehicleId?: boolean;
  /** Open one-time vehicle information questions; nothing is shown or required without them. */
  identification?: VehicleIdentification;
  identificationAnswers?: VehicleIdentificationAnswers;
  onIdentificationChange?: (answers: VehicleIdentificationAnswers) => void;
  /** Show what is missing even before Continue is pressed (a blocked submit sent the member here). */
  showIdentificationErrors?: boolean;
  /** Where to record the swap when the member is standing at a different truck than assigned. */
  changeoutPath: (truck: string) => string;
}

const NO_ANSWERS: VehicleIdentificationAnswers = {};

const READ_ONLY = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-glass-border bg-hub-n-50 px-3 py-2.5 font-normal text-hub-ink';
const CONTROL = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 font-normal text-hub-ink';

export default function OfficerStep({
  initialData, onSubmit, onChange, continueLabel, fleetVehicles, assignedDesignation, usesVehicleId = true,
  identification, identificationAnswers = NO_ANSWERS, onIdentificationChange, showIdentificationErrors = false, changeoutPath,
}: Props) {
  const sectionRef = useRef<HTMLElement>(null);
  // Hidden, not asked and not required while the member says they are at a different truck.
  const activeIdentification = usesVehicleId ? activeVehicleIdentification(identification, initialData) : undefined;
  const boatDetails = usesVehicleId ? undefined : optionalIdentification(identification);
  const [identificationAttempted, setIdentificationAttempted] = useState(false);
  const confirmation: VehicleConfirmation = initialData.vehicleConfirmation ?? defaultVehicleConfirmation('');
  const assigned = confirmation.assignedVehicle;
  const mismatch = usesVehicleId && isVehicleMismatch(confirmation);
  const vehicles = dropdownVehicles(fleetVehicles, assigned, assignedDesignation);
  // Any change to the vehicle answer must be confirmed again with Continue.
  const updateVehicle = (patch: Partial<VehicleConfirmation>) => onChange({
    ...initialData,
    vehicleConfirmation: { ...confirmation, ...patch, confirmed: false },
  });

  return <section ref={sectionRef} data-hub-ui="2" className="inspection-panel mx-auto max-w-lg p-4 sm:p-5">
    <h2 className="mb-5 text-xl font-semibold">{usesVehicleId ? 'Member / Vehicle Info' : 'Member Info'}</h2>
    <form onSubmit={event => {
      event.preventDefault();
      if (mismatch) return;
      // Every open one-time question needs an answer, or "not available" with its reason.
      const unanswered = firstIncompleteField(activeIdentification, identificationAnswers);
      if (unanswered) {
        setIdentificationAttempted(true);
        focusIdentificationField(sectionRef.current, unanswered);
        return;
      }
      const data = new FormData(event.currentTarget);
      onSubmit({ ...initialData, shift: data.get('shift') as Shift, vehicleConfirmation: { ...confirmation, confirmed: true } });
    }}>
      <div className="grid gap-4">
        <label className="text-sm font-semibold">Member<input className={READ_ONLY} value={initialData.name} readOnly /></label>
        <label className="text-sm font-semibold">Rank<input className={READ_ONLY} value={initialData.rank} readOnly /></label>
        {usesVehicleId ? <div>
          <label htmlFor="physical-vehicle" className="text-sm font-semibold">Physical vehicle</label>
          <select
            id="physical-vehicle"
            name="physical_vehicle"
            required
            value={confirmation.choice}
            aria-describedby="physical-vehicle-help"
            onChange={event => updateVehicle({ choice: event.target.value, typedVehicle: '' })}
            className={`${CONTROL} tabular-nums`}
          >
            <option value="">Choose the vehicle ID</option>
            {vehicles.map(vehicle => <option key={vehicle.vehicle_number} value={vehicle.vehicle_number}>{fleetVehicleLabel(vehicle)}</option>)}
            <option value={NOT_LISTED}>Not listed</option>
          </select>
          <p id="physical-vehicle-help" className="mt-1 text-sm text-hub-n-600">
            {assigned
              ? `${assignedDesignation ?? 'This unit'} is assigned vehicle ${assigned}. Confirm it is the truck you are checking.`
              : 'No vehicle ID is recorded for this unit. Choose the truck you are checking.'}
          </p>
        </div> : <label className="text-sm font-semibold">Unit<input className={READ_ONLY} value={assignedDesignation ?? ''} readOnly /></label>}
        {/* A unit with no number on record has no other way to name its vehicle. Anyone else records a change instead. */}
        {usesVehicleId && !assigned && confirmation.choice === NOT_LISTED && <label className="text-sm font-semibold">Vehicle ID on the truck
          <input
            name="typed_vehicle"
            required
            maxLength={MAX_VEHICLE_ID_LENGTH}
            pattern="[A-Za-z0-9][A-Za-z0-9 .\/#\-]{0,31}"
            title="Letters, numbers, spaces and . / # - only"
            autoComplete="off"
            value={confirmation.typedVehicle}
            onChange={event => updateVehicle({ typedVehicle: event.target.value })}
            className={`${CONTROL} tabular-nums`}
          />
        </label>}
        {mismatch && <div data-vehicle-change-link>
          <Link to={changeoutPath(reportedVehicle(confirmation))} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11 w-full">Record this vehicle change</Link>
          <p className="mt-1 text-sm text-hub-n-600">You can&apos;t continue on a different truck until the change is recorded. It takes a few seconds and no approval is needed. Your checkout stays saved.</p>
        </div>}
        <label className="text-sm font-semibold">Shift<select name="shift" required value={initialData.shift} onChange={event => onChange({ ...initialData, shift: event.target.value as Shift })} className={CONTROL}><option value="">Choose shift</option>{(['A', 'B', 'C'] as const).map(shift => <option key={shift} value={shift}>Shift {shift}</option>)}</select></label>
        {activeIdentification && <VehicleIdentificationCard
          block={activeIdentification}
          answers={identificationAnswers}
          onChange={answers => onIdentificationChange?.(answers)}
          showErrors={identificationAttempted || showIdentificationErrors}
        />}
        {boatDetails && <details className="rounded-xl border border-hub-glass-border bg-hub-n-50 p-4">
          <summary className="flex min-h-11 cursor-pointer items-center font-semibold">Boat details · Optional</summary>
          <p className="mb-4 text-sm text-hub-n-600">Add these if you have them handy. You can finish the inspection without them.</p>
          <VehicleIdentificationCard bare block={boatDetails} answers={identificationAnswers} onChange={answers => onIdentificationChange?.(answers)} />
        </details>}
      </div>
      <p className="my-4 text-sm text-hub-n-600">Recorded under your signed-in member account.</p>
      {!mismatch && <button type="submit" className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button w-full">{continueLabel}</button>}
    </form>
  </section>;
}
