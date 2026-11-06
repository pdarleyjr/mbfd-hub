import { useEffect, useId, useState } from 'react';
import { Sparkles } from 'lucide-react';
import type { StationCleaningEntry, StationCleaningToday } from '../../types';
import Tag from '../ui/Tag';

const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';
/** A single instruction this long is clamped until the member asks for all of it. */
const LONG_LINE = 140;

/**
 * Today's Station Cleaning: the rotating focus for the day with its first instruction, the rest behind "Show all",
 * any additional tasks, and the every-day duties folded under "Every day". Read-only; admins keep the schedule.
 */
export default function StationCleaningCard({ cleaning }: { cleaning: StationCleaningToday | null | undefined }) {
  const [expanded, setExpanded] = useState(false);
  const instructionsId = useId();
  useEffect(() => { setExpanded(false); }, [cleaning?.date]);
  if (!cleaning) return null;

  const focus = cleaning.focus;
  const lines = focus?.instructions ?? [];
  const canExpand = lines.length > 1 || (lines[0]?.length ?? 0) > LONG_LINE;
  const shown = expanded ? lines : lines.slice(0, 1);

  return (
    <section className="hub-panel p-4 sm:p-5" aria-labelledby="station-cleaning-heading" data-testid="station-cleaning-card">
      <div className="flex items-start gap-3">
        <Sparkles className="mt-0.5 h-5 w-5 flex-none text-hub-blue" aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <h2 id="station-cleaning-heading" className="font-heading text-base font-bold text-hub-ink">Today&apos;s Station Cleaning</h2>
          <p className={SECONDARY}>{cleaning.date_label}</p>

          {focus ? (
            <div className="mt-3" data-testid="station-cleaning-focus">
              <p className="flex flex-wrap items-center gap-2 font-semibold text-hub-ink">
                <span className="break-words">{focus.title}</span>
                {focus.date_exception && <Tag tone="info">Today only</Tag>}
              </p>
              {shown.length > 0 && (
                <ul id={instructionsId} className={`mt-1 space-y-1 text-sm text-hub-n-800 ${expanded ? 'list-disc pl-5' : ''}`}>
                  {shown.map((line, index) => (
                    <li key={index} className={!expanded && canExpand ? 'line-clamp-2' : undefined}>{line}</li>
                  ))}
                </ul>
              )}
              {canExpand && (
                <button
                  type="button"
                  className="hub-link hub-link--standalone min-h-11"
                  aria-expanded={expanded}
                  aria-controls={instructionsId}
                  onClick={() => setExpanded((open) => !open)}
                  data-testid="station-cleaning-toggle"
                >
                  {expanded ? 'Show less' : 'Show all'}
                </button>
              )}
            </div>
          ) : (
            <p className={`mt-3 ${SECONDARY}`} data-testid="station-cleaning-note">{cleaning.note}</p>
          )}

          {cleaning.extras.length > 0 && (
            <ul className="mt-3 space-y-2" data-testid="station-cleaning-extras">
              {cleaning.extras.map((extra, index) => <ExtraTask key={`${extra.title}-${index}`} extra={extra} />)}
            </ul>
          )}

          {cleaning.baseline && cleaning.baseline.instructions.length > 0 && (
            <details className="mt-3 rounded-hub-control border border-hub-border bg-hub-n-25 px-3" data-testid="station-cleaning-every-day">
              <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">Every day</summary>
              <ul className={`list-disc space-y-1 pb-3 pl-5 ${SECONDARY}`}>
                {cleaning.baseline.instructions.map((line, index) => <li key={index}>{line}</li>)}
              </ul>
            </details>
          )}
        </div>
      </div>
    </section>
  );
}

function ExtraTask({ extra }: { extra: StationCleaningEntry }) {
  return (
    <li className="hub-well px-3 py-2">
      <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-hub-ink">
        <span className="break-words">{extra.title}</span>
        {extra.date_exception && <Tag tone="info">Today only</Tag>}
      </p>
      {extra.instructions.length > 0 && <p className={`break-words ${SECONDARY}`}>{extra.instructions.join(' ')}</p>}
    </li>
  );
}
