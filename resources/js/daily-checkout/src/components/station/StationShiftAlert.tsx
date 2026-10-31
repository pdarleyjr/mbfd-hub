import { Link } from 'react-router';
import { TriangleAlert } from 'lucide-react';
import type { StationOverview } from '../../types';

/** From noon until the 7 AM reset: the frontline units at this station still without a vehicle inspection this shift. */
export default function StationShiftAlert({ overview, stationId }: { overview: StationOverview | null; stationId: number }) {
  if (!overview?.alert.active || overview.alert.units.length === 0) return null;
  const units = overview.alert.units;
  const names = units.map((unit) => unit.designation).join(', ');

  return (
    <section role="alert" data-testid="station-shift-alert" className="rounded-hub-control border border-hub-danger-border bg-hub-danger-bg p-4">
      <div className="flex items-start gap-3">
        <TriangleAlert className="mt-0.5 h-5 w-5 flex-none text-hub-danger" aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <h2 className="font-heading text-base font-bold text-hub-danger">{units.length === 1 ? 'Vehicle inspection not turned in' : `${units.length} vehicle inspections not turned in`}</h2>
          <p className="mt-0.5 text-sm text-hub-n-800">No inspection this shift for {names}.</p>
          <ul className="mt-2 flex flex-wrap gap-2">
            {units.filter((unit) => unit.slug).map((unit) => (
              <li key={unit.apparatus_id}>
                <Link to={`/vehicle-inspections/${unit.slug}?return_to=${encodeURIComponent(`/stations/${stationId}`)}`} className="hub-btn hub-btn--secondary !min-h-11">Start {unit.designation}</Link>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
