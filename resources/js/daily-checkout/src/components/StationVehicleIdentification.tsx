import { useId, useState } from 'react';
import type { VehicleIdentification, VehicleIdentificationUnit } from '../types';
import VehicleIdentificationCard from './VehicleIdentificationCard';
import VehicleIdentificationPanel from './VehicleIdentificationPanel';

interface Props {
  units: VehicleIdentificationUnit[];
  /** The refreshed block for one unit after a save; undefined when that unit has nothing left to answer. */
  onUnitSaved: (apparatusId: number, next: VehicleIdentification | undefined) => void;
}

const NO_ANSWERS = {};
const NOOP = () => undefined;

/**
 * "One-time vehicle information" for units that have neither a Daily Checkout nor a Captain 5 page, shown
 * on their station page. It uses the same card and save panel as the Daily Checkout and Captain 5 page.
 * Renders nothing when no unit has an open question.
 */
export default function StationVehicleIdentification({ units, onUnitSaved }: Props) {
  const headingId = useId();
  const [notice, setNotice] = useState<string | null>(null);
  if (units.length === 0 && notice === null) return null;

  const saved = (unit: VehicleIdentificationUnit, next: VehicleIdentification | undefined) => {
    if (!next) setNotice(`Vehicle information saved for ${unit.designation}. Thank you.`);
    onUnitSaved(unit.apparatus_id, next);
  };

  return <section data-testid="station-vehicle-identification" aria-labelledby={headingId} className="hub-panel space-y-5 p-4 sm:p-6">
    <h2 id={headingId} className="font-heading text-lg font-semibold text-hub-ink">Vehicle information needed</h2>
    {notice && <p role="status" data-testid="station-vehicle-identification-saved" className="hub-callout p-3 text-sm">{notice}</p>}
    {units.map((unit) => <article
      key={unit.apparatus_id}
      data-testid="station-vehicle-identification-unit"
      data-apparatus-id={unit.apparatus_id}
      aria-label={`${unit.designation} one-time vehicle information`}
      className="space-y-3"
    >
      <p className="text-base font-bold text-hub-ink">{unit.designation}</p>
      {unit.vehicle_identification.can_answer
        ? <VehicleIdentificationPanel
          apparatusId={unit.apparatus_id}
          block={unit.vehicle_identification}
          onSaved={(next) => saved(unit, next)}
          testId={`station-vehicle-identification-panel-${unit.apparatus_id}`}
        />
        : <VehicleIdentificationCard block={unit.vehicle_identification} answers={NO_ANSWERS} onChange={NOOP} readOnly />}
    </article>)}
  </section>;
}
