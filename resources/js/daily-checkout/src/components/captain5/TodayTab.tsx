import HandoffPanel from './HandoffPanel';
import RescueDailies from './RescueDailies';
import { QUEUE_FILTERS, type QueueFilter } from './filters';
import type { Handoff, Workspace } from './types';

interface Props {
  workspace: Workspace;
  onFilter: (queue: QueueFilter) => void;
  onOpenInspection: (id: number) => void;
  onHandoff: (handoff: Handoff) => void;
}

export default function TodayTab({ workspace, onFilter, onOpenInspection, onHandoff }: Props) {
  const { counts } = workspace;
  return (
    <div className="space-y-8">
      <section aria-labelledby="c5-counts-heading" className="space-y-3">
        <h3 id="c5-counts-heading" className="font-heading text-lg font-bold text-hub-ink">Open issues</h3>
        <ul className="flex flex-wrap gap-2" aria-label="Open issue counts">
          {QUEUE_FILTERS.map((filter) => {
            const value = counts[filter.count];
            return (
              <li key={filter.id}>
                <button
                  type="button"
                  data-testid={`captain5-count-${filter.id}`}
                  onClick={() => onFilter(filter.id)}
                  aria-label={`${value} ${filter.label}. Show in Issues`}
                  className="inline-flex min-h-11 items-center gap-2 rounded-hub-control border border-hub-border bg-hub-well px-3.5 text-sm font-semibold text-hub-ink transition-colors hover:bg-hub-blue-tint"
                >
                  <span className={`tabular-nums ${value > 0 && filter.id !== 'all' ? 'text-hub-blue' : ''}`}>{value}</span>
                  <span aria-hidden="true">{filter.label}</span>
                </button>
              </li>
            );
          })}
        </ul>
      </section>
      <RescueDailies dailies={workspace.rescue_dailies} onOpenInspection={onOpenInspection} />
      <HandoffPanel handoff={workspace.handoff} onChange={onHandoff} />
    </div>
  );
}
