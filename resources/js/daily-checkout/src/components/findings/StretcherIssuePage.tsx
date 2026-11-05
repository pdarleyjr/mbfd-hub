import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router';
import type { Apparatus } from '../../types';
import { ApiClient } from '../../utils/api';
import PreviousPageButton from '../PreviousPageButton';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import StretcherIssueForm from './StretcherIssueForm';
import './findings.css';
import './memberActions.css';

interface PickerProps {
  apparatuses: Apparatus[];
  notice: string | null;
  /** Set when a unit is already chosen, so the member can go back to it. */
  current: Apparatus | null;
  onPick: (apparatus: Apparatus) => void;
  onKeep: () => void;
}

function matches(apparatus: Apparatus, query: string): boolean {
  if (query === '') return true;
  const haystack = [apparatus.name, apparatus.slug, apparatus.unit_id, apparatus.designation, apparatus.vehicle_number].filter(Boolean).join(' ').toLowerCase();

  return query.split(/\s+/).every(part => haystack.includes(part));
}

/** A searchable list of units. */
function UnitPicker({ apparatuses, notice, current, onPick, onKeep }: PickerProps) {
  const [query, setQuery] = useState('');
  const visible = useMemo(
    () => apparatuses.filter(apparatus => matches(apparatus, query.trim().toLowerCase())).sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true })),
    [apparatuses, query],
  );

  return (
    <section className="stretcher-picker" aria-label="Choose the unit">
      {notice && <p role="status" className="stretcher-banner is-warn">{notice}</p>}
      <label htmlFor="stretcher-unit-search" className="stretcher-picker__label"><strong>Which unit is the stretcher on?</strong></label>
      <input
        id="stretcher-unit-search"
        type="search"
        value={query}
        autoComplete="off"
        placeholder="Search by unit name"
        onChange={event => setQuery(event.target.value)}
      />
      <p className="stretcher-hint" role="status">{visible.length === 1 ? '1 unit' : `${visible.length} units`}</p>
      <ul>
        {visible.map(apparatus => (
          <li key={apparatus.id}>
            <button type="button" onClick={() => onPick(apparatus)}>{apparatus.name}</button>
          </li>
        ))}
      </ul>
      {current && <button type="button" className="hub-btn hub-btn--secondary" onClick={onKeep}>{`Keep ${current.name}`}</button>}
    </section>
  );
}

/**
 * Report a stretcher problem. With a unit in the address (/vehicle-inspections/:slug/stretcher-issue) the form opens
 * on that unit; from the Forms Hub the member picks the unit first. Changing the unit keeps what was typed.
 */
export default function StretcherIssuePage() {
  const { slug } = useParams<{ slug?: string }>();
  const [apparatuses, setApparatuses] = useState<Apparatus[] | null>(null);
  const [selected, setSelected] = useState<Apparatus | null>(null);
  const [choosing, setChoosing] = useState(slug === undefined);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setError(null);
    ApiClient.getApparatuses().then((list) => {
      if (cancelled) return;
      setApparatuses(list);
      const found = slug === undefined ? undefined : list.find(entry => entry.slug === slug);
      if (found) {
        setSelected(found);
        setChoosing(false);
        setNotice(null);
      } else if (slug !== undefined) {
        setChoosing(true);
        setNotice('That unit was not found. Choose it from the list.');
      }
    }).catch((failure: unknown) => {
      if (!cancelled) setError(failure instanceof Error && failure.message ? failure.message : 'The units could not be loaded.');
    });

    return () => { cancelled = true; };
  }, [slug, attempt]);

  const backFallback = slug ? `/vehicle-inspections/${slug}/issues` : '/forms-hub';

  return (
    <div data-hub-ui="2" className="stretcher-page font-hub">
      <div className="stretcher-page__head">
        <PreviousPageButton contextual fallback={backFallback} className="hub-btn hub-btn--tertiary" />
        <h1>Report stretcher issue</h1>
        <p className="stretcher-page__lead">Tell Captain 5 about a problem with a stretcher.</p>
      </div>
      {error ? (
        <LoadError title="Units could not be loaded" message={error} onRetry={() => setAttempt(count => count + 1)} />
      ) : apparatuses === null ? (
        <SkeletonRows rows={3} />
      ) : (
        <>
          {/* Kept mounted behind the list, so changing the unit never throws away a half-written report. */}
          {selected && (
            <div hidden={choosing}>
              <StretcherIssueForm apparatus={selected} onChangeUnit={() => setChoosing(true)} />
            </div>
          )}
          {(choosing || selected === null) && (
            <UnitPicker
              apparatuses={apparatuses}
              notice={notice}
              current={selected}
              onPick={(apparatus) => { setSelected(apparatus); setChoosing(false); setNotice(null); }}
              onKeep={() => setChoosing(false)}
            />
          )}
        </>
      )}
    </div>
  );
}
