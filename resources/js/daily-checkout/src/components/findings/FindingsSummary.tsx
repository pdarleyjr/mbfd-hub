import { ArrowRight } from 'lucide-react';
import type { OpenFinding } from '../../types';
import type { ConcernNote, IssueSummary, LiveFindingsState } from '../../types/issueHistory.ts';
import { NOT_RECORDED, formatEventDate, issuePresentation, liveStatusLine, openIssueCountText } from '../../utils/issueHistoryFormat.ts';
import { useOffline } from '../../hooks/useOffline';
import ClosedIssues from './ClosedIssues';
import IssueCard from './IssueCard';
import '../inspection-workspace.css';
import './findings.css';

/** One open issue and, in the inspection, the checklist item it belongs to (null when it matches no current item). */
export interface SummaryEntry {
  finding: OpenFinding;
  /** The live record; null for a finding only the checklist knows (offline, or not read yet). */
  issue: IssueSummary | null;
  target: { compartmentId: string; itemId: string } | null;
  /** The live list is current and no longer has it open. It stays visible, marked, and is not counted. */
  noLongerOpen: boolean;
}

interface Props {
  entries: SummaryEntry[];
  notes: ConcernNote[];
  closedCount: number;
  asOf: string | null;
  state: LiveFindingsState;
  apparatusId: number | undefined;
  onRefresh: () => void;
  /** Jump to the compartment and item. Absent on the read-only issues page, which lists full cards instead. */
  onOpenItem?: (compartmentId: string, itemId: string) => void;
}

/** More than this many issues put the rest behind "Show more", so a long list never pushes the checkout off screen. */
const INLINE_ISSUES = 5;

const rowStatus = (entry: SummaryEntry): string | null => (
  entry.issue ? issuePresentation(entry.issue).label : entry.finding.action_label?.trim() || null
);

function IssueRow({ entry, onOpenItem }: { entry: SummaryEntry; onOpenItem: (compartmentId: string, itemId: string) => void }) {
  const status = rowStatus(entry);
  const { target, finding } = entry;
  if (target === null) return null;

  return (
    <li>
      <button type="button" className="findings-row" onClick={() => onOpenItem(target.compartmentId, target.itemId)} data-issue-id={finding.id}>
        <span className="findings-row__text">
          <strong>{finding.item}</strong>
          <small>{finding.compartment}{status ? ` · ${status}` : ''}</small>
        </span>
        <span className="findings-row__go" aria-hidden="true"><span>Go to item</span><ArrowRight size={16} /></span>
      </button>
    </li>
  );
}

function NoteItem({ note }: { note: ConcernNote }) {
  const date = formatEventDate(note.reported_at);

  return (
    <li className="findings-note">
      <p className="findings-note__text">{note.description}</p>
      {note.location && <p className="findings-note__meta">Where: {note.location}</p>}
      <p className="findings-note__meta">Noted by {note.reporter_name ?? NOT_RECORDED}{date ? ` on ${date}` : ''}</p>
      {note.photo_url && <img className="issue-timeline__photo" src={note.photo_url} alt="Photo attached to this note" loading="lazy" />}
    </li>
  );
}

/**
 * Open issues on this apparatus, read live. In the inspection each issue is a button that jumps to its compartment
 * and item, and issues that match no current item are kept under Other recorded findings. On the read-only issues
 * page every issue is a full card. How current the list is always shows, and offline it says so.
 */
export default function FindingsSummary({ entries, notes, closedCount, asOf, state, apparatusId, onRefresh, onOpenItem }: Props) {
  const offline = useOffline();
  const status = liveStatusLine(state, asOf, offline);
  const open = entries.filter(entry => !entry.noLongerOpen);
  const listed = onOpenItem ? open.filter(entry => entry.target !== null) : [];
  const inline = listed.slice(0, INLINE_ISSUES);
  const more = listed.slice(INLINE_ISSUES);
  // In the inspection these stay visible even when the live list no longer has them open.
  const unlocated = onOpenItem ? entries.filter(entry => entry.target === null) : [];
  const cards = onOpenItem ? [] : open;
  const retryable = state === 'stale' || state === 'unavailable';
  const checking = state === 'loading' && entries.length === 0;
  // Nothing to act on: one quiet line, so a clean apparatus does not spend a screen of a phone.
  const quiet = open.length === 0 && unlocated.length === 0 && notes.length === 0 && closedCount === 0 && !retryable;

  return (
    <section data-hub-ui="2" className={`findings-summary${quiet ? ' is-quiet' : ''}`} aria-labelledby="findings-summary-heading" data-live-state={state}>
      <div className="findings-summary__head">
        <h2 id="findings-summary-heading">{checking ? 'Checking for open issues...' : state === 'unavailable' && entries.length === 0 ? 'Open issues could not be checked' : openIssueCountText(open.length)}</h2>
        {retryable && <button type="button" className="hub-btn hub-btn--secondary" onClick={onRefresh}>Try again</button>}
      </div>
      {!checking && <p className={`findings-summary__status is-${status.tone}`} data-live-line>{status.text}</p>}

      {inline.length > 0 && <ul className="findings-list">{inline.map(entry => <IssueRow key={entry.finding.id} entry={entry} onOpenItem={onOpenItem!} />)}</ul>}
      {more.length > 0 && (
        <details className="findings-more">
          <summary>Show {more.length} more</summary>
          <ul className="findings-list">{more.map(entry => <IssueRow key={entry.finding.id} entry={entry} onOpenItem={onOpenItem!} />)}</ul>
        </details>
      )}
      {cards.length > 0 && <ul className="findings-list">{cards.map(entry => <li key={entry.finding.id}>{entry.issue ? <IssueCard issue={entry.issue} /> : <p className="inspection-muted">{entry.finding.item} · {entry.finding.compartment}</p>}</li>)}</ul>}

      {unlocated.length > 0 && (
        <details className="inspection-unlocated-findings">
          <summary>Other recorded findings ({unlocated.length})</summary>
          <p>These findings remain open. Verify the recorded location or duty before acting.</p>
          <ul>
            {unlocated.map(({ finding, issue, noLongerOpen }) => <li key={finding.id}>
              {issue
                ? <IssueCard issue={issue} />
                : <><strong>{finding.item}</strong> · {finding.compartment} · {finding.issue_type}{noLongerOpen && <span className="findings-note__meta"> No longer listed as open. It may have been resolved since this page loaded.</span>}</>}
            </li>)}
          </ul>
        </details>
      )}

      {notes.length > 0 && (
        <details className="findings-notes">
          <summary>Recent notes ({notes.length})</summary>
          <ul className="findings-list">{notes.map(note => <NoteItem key={note.id} note={note} />)}</ul>
        </details>
      )}

      {apparatusId !== undefined && <ClosedIssues key={apparatusId} apparatusId={apparatusId} closedCount={closedCount} />}
    </section>
  );
}
