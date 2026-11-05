import { forwardRef, type ReactNode } from 'react';
import { Ban, Check, ChevronRight, Circle, ClipboardList, Truck, Users } from 'lucide-react';
import type { BoardStation, BoardUnit } from './types';
import Tag from '../ui/Tag';
import { ATTENTION, SECONDARY, crewLine } from './format';

function UnitChip({ unit }: { unit: BoardUnit }) {
  const done = unit.state === 'submitted';
  const oos = unit.state === 'out_of_service';
  const Icon = done ? Check : oos ? Ban : Circle;
  const tone = done ? 'border-hub-success-border bg-hub-success-bg text-hub-ink' : oos ? 'border-hub-n-200 bg-hub-n-100 text-hub-n-600' : 'border-hub-warning-border bg-hub-warning-bg text-hub-ink';
  return (
    <li className={`inline-flex min-h-7 items-center gap-1 rounded-full border px-2 py-0.5 text-[13px] font-medium leading-5 ${tone}`} data-state={unit.state}>
      <Icon className="h-3.5 w-3.5 flex-none" aria-hidden="true" />
      <span>{unit.designation}</span>
      <span className="sr-only">{done ? ', checked out' : oos ? ', out of service' : ', not checked out yet'}</span>
    </li>
  );
}

function Stat({ icon: Icon, label, children }: { icon: typeof Users; label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <p className="flex items-center gap-1.5 text-[13px] font-semibold leading-5 text-hub-n-600"><Icon className="h-3.5 w-3.5 flex-none" aria-hidden="true" />{label}</p>
      <div className="text-sm text-hub-ink">{children}</div>
    </div>
  );
}

interface StationCardProps {
  station: BoardStation;
  onOpen: () => void;
}

/** One station at a glance. The whole card is one button that opens the read-only station detail. */
const StationCard = forwardRef<HTMLButtonElement, StationCardProps>(function StationCard({ station, onOpen }, ref) {
  const { attention, crew, checkouts, requests, vehicle_issues: issues } = station;
  const level = ATTENTION[attention.level];
  const percent = checkouts.expected > 0 ? Math.round((checkouts.done / checkouts.expected) * 100) : 0;
  const border = attention.level === 'attention' ? 'border-hub-danger-border' : attention.level === 'watch' ? 'border-hub-warning-border' : 'border-hub-glass-border';

  return (
    <li>
      <button
        ref={ref}
        type="button"
        onClick={onOpen}
        aria-haspopup="dialog"
        data-testid="command-station-card"
        data-level={attention.level}
        className={`hub-panel group flex min-h-11 w-full flex-col gap-3 border-2 p-4 text-left transition-shadow hover:shadow-[var(--hub-elevation-2)] focus-visible:outline-none ${border}`}
      >
        <span className="flex items-start justify-between gap-3">
          <span className="min-w-0">
            <span className="block font-heading text-lg font-bold text-hub-ink">{station.name}</span>
            <span className={`block ${SECONDARY}`}>{attention.reasons.length > 0 ? attention.reasons.slice(0, 2).join(' · ') : 'Nothing needs attention'}</span>
          </span>
          <Tag tone={level.tone}>{level.label}</Tag>
        </span>

        <span className="grid gap-3 sm:grid-cols-2">
          <Stat icon={Users} label="On shift">
            {crew.available
              ? <><span className="font-semibold tabular-nums">{crew.working_count} working</span>{crew.away_count > 0 && <span className="text-hub-n-600"> · {crew.away_count} away</span>}<span className={`block truncate ${SECONDARY}`}>{crewLine(crew.members) || 'No one listed'}</span></>
              : <span className="text-hub-n-600">Roster not available yet</span>}
          </Stat>
          <Stat icon={ClipboardList} label="Requests">
            <span className="font-semibold tabular-nums">{requests.open} open</span>
            <span className="text-hub-n-600"> · {requests.new_today} new{requests.pending > 0 ? ` · ${requests.pending} waiting` : ''}</span>
            {requests.updated_recently > 0 && <span className={`block ${SECONDARY}`}>{requests.updated_recently} updated in the last day</span>}
          </Stat>
        </span>

        <span className="block">
          <span className="flex items-center justify-between gap-3 text-[13px] font-semibold leading-5 text-hub-n-600">
            <span className="flex items-center gap-1.5"><Truck className="h-3.5 w-3.5" aria-hidden="true" />Truck checkouts</span>
            <span className="tabular-nums text-hub-ink">{checkouts.done} of {checkouts.expected}</span>
          </span>
          <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-hub-n-100" aria-hidden="true">
            <span className={`block h-full rounded-full ${checkouts.missing > 0 ? 'bg-hub-warning' : 'bg-hub-success'}`} style={{ width: `${percent}%` }} />
          </span>
          {checkouts.units.length > 0 && (
            <ul className="mt-2 flex flex-wrap gap-1.5" aria-label={`${station.name} trucks`}>
              {checkouts.units.map((unit) => <UnitChip key={unit.apparatus_id} unit={unit} />)}
            </ul>
          )}
        </span>

        <span className="flex items-end justify-between gap-3">
          <span className={`min-w-0 ${SECONDARY}`}>
            {issues.units_with_issues > 0
              ? <><span className="font-semibold text-hub-ink">{issues.units_with_issues} {issues.units_with_issues === 1 ? 'vehicle' : 'vehicles'} with issues</span>{issues.units[0] ? ` · ${issues.units[0].designation}: ${issues.units[0].top[0]?.label ?? 'open issue'}` : ''}</>
              : 'No open vehicle issues'}
          </span>
          <span className="flex flex-none items-center gap-0.5 text-sm font-semibold text-hub-blue">Details<ChevronRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" /></span>
        </span>
      </button>
    </li>
  );
});

export default StationCard;
