import type { TimelineEntry } from '../../types/issueHistory.ts';
import { NOT_RECORDED, formatEventDateTime } from '../../utils/issueHistoryFormat.ts';
import './findings.css';

/** Oldest first, as the server sends it. Who and when always show; a missing actor reads "Not recorded". */
export default function IssueTimeline({ entries }: { entries: TimelineEntry[] }) {
  if (entries.length === 0) return <p className="inspection-muted">No history recorded yet.</p>;

  return (
    <ol className="issue-timeline" data-testid="issue-timeline">
      {entries.map(entry => {
        const when = formatEventDateTime(entry.at);

        return (
          <li key={entry.id} data-entry-kind={entry.kind}>
            <p className="issue-timeline__label">{entry.label}</p>
            <p className="issue-timeline__meta">
              {entry.actor_name ?? NOT_RECORDED}
              {' · '}
              {when ? <time dateTime={entry.at ?? undefined}>{when}</time> : NOT_RECORDED}
            </p>
            {entry.lines.map((line, index) => <p key={`${entry.id}-line-${index}`} className="issue-timeline__line">{line}</p>)}
            {entry.text && <p className="issue-timeline__text">{entry.text}</p>}
            {entry.photo_url && <img className="issue-timeline__photo" src={entry.photo_url} alt="Photo attached to this update" loading="lazy" />}
          </li>
        );
      })}
    </ol>
  );
}
