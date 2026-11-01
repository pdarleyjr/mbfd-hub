import { useRef, useState } from 'react';
import { Link } from 'react-router';
import type { FleetVehicle, OfficerInfo, Shift, SwapAnswer, VehicleConfirmation, VehicleIdentification, VehicleIdentificationAnswers } from '../types';
import { activeVehicleIdentification, firstIncompleteField } from '../utils/vehicleIdentification';
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
  /** Open one-time vehicle information questions; nothing is shown or required without them. */
  identification?: VehicleIdentification;
  identificationAnswers?: VehicleIdentificationAnswers;
  onIdentificationChange?: (answers: VehicleIdentificationAnswers) => void;
  /** Show what is missing even before Continue is pressed (a blocked submit sent the member here). */
  showIdentificationErrors?: boolean;
  /** Where to record the swap when the member is standing at a different truck than assigned. */
  changeoutPath?: (truck: string) => string;
}

const NO_ANSWERS: VehicleIdentificationAnswers = {};

const READ_ONLY = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-glass-border bg-hub-n-50 px-3 py-2.5 font-normal text-hub-ink';
const CONTROL = 'mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 font-normal text-hub-ink';
const SWAP_ANSWERS: ReadonlyArray<{ value: SwapAnswer; label: string }> = [
  { value: 'swap_completed', label: 'Yes, swap completed' },
  { value: 'unknown', label: 'Unknown, not sure why' },
];

export default function OfficerStep({
  initialData, onSubmit, onChange, continueLabel, fleetVehicles, assignedDesignation,
  identification, identificationAnswers = NO_ANSWERS, onIdentificationChange, showIdentificationErrors = false, changeoutPath,
}: Props) {
  const sectionRef = useRef<HTMLElement>(null);
  // Hidden, not asked and not required while a different physical vehicle is reported.
  const activeIdentification = activeVehicleIdentification(identification, initialData);
  const [identificationAttempted, setIdentificationAttempted] = useState(false);
  const confirmation: VehicleConfirmation = initialData.vehicleConfirmation ?? defaultVehicleConfirmation('');
  const assigned = confirmation.assignedVehicle;
  const mismatch = isVehicleMismatch(confirmation);
  const reported = reportedVehicle(confirmation);
  const vehicles = dropdownVehicles(fleetVehicles, assigned, assignedDesignation);
  // Any change to the vehicle answer must be confirmed again with Continue.
  const updateVehicle = (patch: Partial<VehicleConfirmation>) => onChange({
    ...initialData,
    vehicleConfirmation: { ...confirmation, ...patch, confirmed: false },
  });

  return <section ref={sectionRef} data-hub-ui="2" className="inspection-panel mx-auto max-w-lg p-4 sm:p-5">
    <h2 className="mb-5 text-xl font-semibold">Member / Vehicle Info</h2>
    <form onSubmit={event => {
      event.preventDefault();
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
        <div>
          <label htmlFor="physical-vehicle" className="text-sm font-semibold">Physical vehicle</label>
          <select
            id="physical-vehicle"
            name="physical_vehicle"
            required
            value={confirmation.choice}
            aria-describedby="physical-vehicle-help"
            onChange={event => updateVehicle({ choice: event.target.value, typedVehicle: '', swapAnswer: '', note: '' })}
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
        </div>
        {confirmation.choice === NOT_LISTED && <label className="text-sm font-semibold">Vehicle ID on the truck
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
        {mismatch && changeoutPath && <div data-vehicle-change-link>
          <Link to={changeoutPath(reported)} className="hub-btn hub-btn--primary hub-btn--wrap !min-h-11 w-full">Record this vehicle change</Link>
          <p className="mt-1 text-sm text-hub-n-600">Takes a few seconds. No approval needed. Your checkout stays saved.</p>
        </div>}
        {mismatch && <fieldset data-vehicle-swap-prompt className="rounded-xl border border-hub-warning/40 bg-hub-warning/5 p-4">
          <legend className="px-1 text-base font-semibold text-hub-ink">Was a truck swap completed?</legend>
          <p className="mb-3 text-sm text-hub-ink">You chose vehicle {reported}, but this unit is assigned {assigned || 'no recorded vehicle'}. You can still submit this checkout. Fleet reviews it before its findings are applied.</p>
          <div className="grid gap-2">
            {SWAP_ANSWERS.map((answer, index) => <label key={answer.value} className="hub-well flex min-h-11 items-center gap-3 px-3 py-2 text-hub-ink">
              <input
                type="radio"
                name="swap_answer"
                value={answer.value}
                required={index === 0}
                checked={confirmation.swapAnswer === answer.value}
                onChange={() => onChange({ ...initialData, vehicleConfirmation: { ...confirmation, swapAnswer: answer.value, confirmed: false } })}
                className="h-5 w-5 border-hub-control-border text-hub-blue focus:ring-hub-focus"
              />
              <span className="font-medium">{answer.label}</span>
            </label>)}
          </div>
          <label className="mt-3 block text-sm font-semibold">Note (optional)
            <textarea
              name="swap_note"
              rows={3}
              maxLength={1000}
              value={confirmation.note}
              onChange={event => onChange({ ...initialData, vehicleConfirmation: { ...confirmation, note: event.target.value, confirmed: false } })}
              className={`${CONTROL} min-h-24`}
            />
          </label>
          <button
            type="button"
            data-vehicle-picked-wrong
            className="hub-btn hub-btn--secondary hub-btn--wrap mt-3 w-full"
            onClick={() => updateVehicle({ choice: assigned, typedVehicle: '', swapAnswer: '', note: '' })}
          >
            I picked wrong
          </button>
        </fieldset>}
        <label className="text-sm font-semibold">Shift<select name="shift" required value={initialData.shift} onChange={event => onChange({ ...initialData, shift: event.target.value as Shift })} className={CONTROL}><option value="">Choose shift</option>{(['A', 'B', 'C'] as const).map(shift => <option key={shift} value={shift}>Shift {shift}</option>)}</select></label>
        {activeIdentification && <VehicleIdentificationCard
          block={activeIdentification}
          answers={identificationAnswers}
          onChange={answers => onIdentificationChange?.(answers)}
          showErrors={identificationAttempted || showIdentificationErrors}
        />}
      </div>
      <p className="my-4 text-sm text-hub-n-600">Recorded under your signed-in member account.</p>
      <button type="submit" className="hub-btn hub-btn--primary hub-btn--wrap inspection-review-button w-full">{continueLabel}</button>
    </form>
  </section>;
}
