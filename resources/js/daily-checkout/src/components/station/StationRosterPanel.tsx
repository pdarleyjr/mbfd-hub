import { CalendarDays, Users } from 'lucide-react';
import type { StationRosterRow, StationRosterSection } from '../../types';
import { EmptyState, LoadError, SkeletonRows } from '../ui/StateViews';
import { useStationRoster } from './useStationRoster';

/** Outside Daily, so a plain link. It opens on the next shift once TeleStaff has posted one. */
const DAILY_ROSTER_HREF = '/daily-roster';
const SECONDARY = 'text-[13px] leading-5 text-hub-n-600';

interface StationRosterPanelProps {
  stationId: number;
  stationNumber: string | number;
}

/** The Today's Roster tab: this station's units from the roster on duty right now, not the next one TeleStaff has posted. */
export default function StationRosterPanel({ stationId, stationNumber }: StationRosterPanelProps) {
  const { roster, error, loading, refreshFailed, retry } = useStationRoster(stationId);

  if (loading) return <SkeletonRows />;
  if (error || !roster) return <LoadError title="Today's roster could not be loaded" message={error ?? 'Try again in a moment.'} onRetry={retry} />;
  if (!roster.available) {
    return (
      <EmptyState
        icon={<CalendarDays className="hub-empty__icon" aria-hidden="true" />}
        title="No roster for today yet"
        body={roster.message ?? "Today's roster has not arrived yet."}
        action={<a href={DAILY_ROSTER_HREF} className="hub-btn hub-btn--secondary">Open the Daily Roster</a>}
      />
    );
  }

  return (
    <div className="space-y-4" data-testid="station-roster">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <h3 className="font-heading text-lg font-semibold text-hub-ink">{roster.shift} · {roster.date_label}</h3>
          <p className={`tabular-nums ${SECONDARY}`}>{roster.window} · Updated {roster.updated}</p>
        </div>
        {roster.tag && <span className={`hub-tag ${roster.tag.class}`}>{roster.tag.label}</span>}
      </div>
      {refreshFailed && <p role="status" className="hub-well p-3 text-sm text-hub-ink">This roster could not be refreshed and may be out of date. Check your connection.</p>}
      {roster.notice && <p role="status" className="hub-well p-3 text-sm text-hub-ink">{roster.notice}</p>}
      {roster.next_shift_posted && (
        <p className="hub-well p-3 text-sm text-hub-ink">
          TeleStaff has posted the next shift. This tab stays on the crew working until 8:00 AM. <a href={DAILY_ROSTER_HREF} className="hub-link">See the next shift</a>
        </p>
      )}
      {roster.sections.length > 0 ? (
        <div className="grid gap-3 lg:grid-cols-2">
          {roster.sections.map((section) => <RosterSection key={section.key} section={section} />)}
        </div>
      ) : (
        <EmptyState
          icon={<Users className="hub-empty__icon" aria-hidden="true" />}
          title={`Nothing listed for Station ${stationNumber}`}
          body="Today's roster has no units under this station."
        />
      )}
      <a href={DAILY_ROSTER_HREF} className="hub-link hub-link--standalone">Open the full Daily Roster</a>
    </div>
  );
}

function RosterSection({ section }: { section: StationRosterSection }) {
  const headingId = `station-roster-${section.key.replace(/\W+/g, '-').toLowerCase()}`;

  return (
    <section className="hub-well min-w-0 p-4" aria-labelledby={headingId}>
      <div className="flex items-center gap-3">
        <span className="flex h-9 w-20 flex-none items-center" aria-hidden="true">
          {section.image
            ? <img src={section.image.src} srcSet={section.image.srcset} alt="" width={section.image.width} height={section.image.height} loading="lazy" decoding="async" className="h-auto max-h-9 w-auto max-w-full" />
            : <Users className="h-6 w-6 text-hub-n-600" />}
        </span>
        <h4 id={headingId} className="font-heading text-base font-semibold text-hub-ink">{section.unit}</h4>
      </div>
      <ul className="mt-2 divide-y divide-hub-glass-border">
        {/* Seats have no identity of their own, and one member can fill two rows of a seat; the list is replaced whole on refresh. */}
        {section.seats.map((seat, seatIndex) => (
          <li key={seatIndex} className="py-2.5 last:pb-0">
            <ul className="space-y-2.5">
              {seat.map((row, rowIndex) => <li key={rowIndex}><RosterRow row={row} /></li>)}
            </ul>
          </li>
        ))}
      </ul>
    </section>
  );
}

function RosterRow({ row }: { row: StationRosterRow }) {
  return (
    <div className={`min-w-0 ${row.is_first_in_seat ? '' : 'ml-3 border-l-2 border-hub-glass-border pl-3'}`}>
      <div className="flex items-start justify-between gap-3">
        <p className={`min-w-0 font-semibold ${row.is_vacant ? 'text-hub-n-600' : 'text-hub-ink'}`}>{row.name}</p>
        {/* A code the Hub does not know prints as TeleStaff sent it, so the pill must be able to wrap. */}
        <span className={`hub-tag roster-pill roster-pill--${row.status.tone} max-w-[55%] flex-none !whitespace-normal [overflow-wrap:anywhere]`} title={row.status.title}>{row.status.label}</span>
        {row.status.screen_reader_text && <span className="sr-only">{row.status.screen_reader_text}</span>}
      </div>
      <p className={`tabular-nums ${SECONDARY}`}>
        {row.rank}
        {row.rank && row.employee_id && ' · '}
        {row.employee_id && `ID ${row.employee_id}`}
        {!row.is_first_in_seat && <span className="sr-only">, same seat</span>}
      </p>
      {row.source_marker && <p className={SECONDARY}>TeleStaff shows {row.source_marker}</p>}
      {row.certifications.length > 0 && <p className={`break-words ${SECONDARY}`}>{row.certifications.join(' / ')}</p>}
      {row.from && row.through && <p className={`tabular-nums ${SECONDARY}`}>{row.from} to {row.through} · {row.hours} hours</p>}
    </div>
  );
}
