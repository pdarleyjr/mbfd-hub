import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router';
import type { Apparatus } from '../../types';
import { useLiveFindings } from '../../hooks/useLiveFindings';
import { identityForQueueCapture } from '../../lib/offlineIdentity';
import { ApiClient } from '../../utils/api';
import { mergeKnownFindings } from '../../utils/liveFindings.ts';
import PreviousPageButton from '../PreviousPageButton';
import { LoadError, SkeletonRows } from '../ui/StateViews';
import FindingsSummary, { type SummaryEntry } from './FindingsSummary';
import './findings.css';

/**
 * Read-only: every open issue on one apparatus with its history, the notes members left, and earlier closed issues.
 * Opened from the station page. Nothing here changes an inspection or a draft.
 */
export default function ApparatusIssuesPage() {
  const { slug } = useParams<{ slug: string }>();
  const [apparatus, setApparatus] = useState<Apparatus | null>(null);
  const [userId, setUserId] = useState<number | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const live = useLiveFindings(apparatus?.id, userId);

  useEffect(() => {
    let cancelled = false;
    setError(null);
    Promise.all([ApiClient.getApparatuses(), identityForQueueCapture()]).then(([apparatuses, identity]) => {
      const found = apparatuses.find(entry => entry.slug === slug);
      if (!found) throw new Error('This apparatus was not found.');
      if (cancelled) return;
      setApparatus(found);
      setUserId(identity.userId);
    }).catch((failure: unknown) => {
      if (!cancelled) setError(failure instanceof Error && failure.message ? failure.message : 'The apparatus could not be loaded.');
    });

    return () => { cancelled = true; };
  }, [slug, attempt]);

  const entries = useMemo((): SummaryEntry[] => {
    const { findings, liveById } = mergeKnownFindings(undefined, live.open, live.state === 'live');

    return findings.map(finding => ({ finding, issue: liveById.get(finding.id) ?? null, target: null, noLongerOpen: false }));
  }, [live.open, live.state]);

  if (error) {
    return (
      <div data-hub-ui="2" className="issues-page font-hub">
        <LoadError title="Issues could not be loaded" message={error} onRetry={() => setAttempt(count => count + 1)}>
          <PreviousPageButton contextual fallback="/vehicle-inspections" className="hub-btn hub-btn--secondary" />
        </LoadError>
      </div>
    );
  }

  return (
    <div data-hub-ui="2" className="issues-page font-hub">
      <div className="issues-page__head">
        <PreviousPageButton contextual fallback="/vehicle-inspections" className="hub-btn hub-btn--tertiary" />
        <h1>Issues & history</h1>
        <p className="inspection-muted">{apparatus ? apparatus.name : 'Loading the apparatus...'}</p>
      </div>
      {apparatus === null
        ? <SkeletonRows rows={2} />
        : <FindingsSummary entries={entries} notes={live.notes} closedCount={live.closedCount} asOf={live.asOf} state={live.state} apparatusId={apparatus.id} onRefresh={live.refresh} />}
    </div>
  );
}
