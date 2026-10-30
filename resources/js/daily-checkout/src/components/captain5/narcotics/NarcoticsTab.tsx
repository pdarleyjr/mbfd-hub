import { useEffect, useState } from 'react';
import { ChevronRight, ShieldAlert } from 'lucide-react';
import { fetchChecks } from '../../../utils/narcoticsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import { ChoiceChips, FIELD, HINT, LABEL, LiveNote, Pager, useEmsLoad } from '../ems/shared';
import CheckSheet from './CheckSheet';
import { EMPTY_FILTERS, STATUS_TONE, checksQuery, dateRangeError, etDateTime, memberText, rowNote } from './logic';
import type { NarcoticsCheck, NarcoticsFilters, NarcoticsOutcome, NarcoticsScope } from './types';

interface Props {
  checkId: string | null;
  onOpenCheck: (uuid: string) => void;
  onCloseCheck: () => void;
  /** Bumped once a minute while the page is visible (and on focus): the list refreshes quietly. */
  tick: number;
  /** A decision was made: the tab badge and the list refresh. */
  onChanged: () => void;
  onForbidden: () => void;
}

const DEBOUNCE_MS = 300;

function CheckRow({ check, onOpen }: { check: NarcoticsCheck; onOpen: (uuid: string) => void }) {
  const note = rowNote(check);
  return (
    <li>
      <button
        type="button"
        data-testid="narcotics-row"
        onClick={() => onOpen(check.id)}
        className="flex min-h-[4.5rem] w-full items-center gap-3 rounded-hub-control border border-hub-border bg-hub-well px-3 py-2.5 text-left transition-colors hover:bg-hub-blue-tint"
      >
        {check.image.thumb_url
          ? <img src={check.image.thumb_url} alt="" width={64} height={64} loading="lazy" className="h-16 w-16 flex-none rounded-hub-control bg-hub-n-100 object-cover" />
          : <span className="grid h-16 w-16 flex-none place-items-center rounded-hub-control bg-hub-n-100 text-center text-[11px] text-hub-n-600" aria-hidden="true">Photo removed</span>}
        <span className="flex min-w-0 flex-1 flex-col gap-1">
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <strong className="break-words text-hub-ink">{memberText(check)}</strong>
            <Tag tone={STATUS_TONE[check.status]}>{check.status_label}</Tag>
            {check.resubmission_of && <Tag tone="warning">Sent again</Tag>}
          </span>
          <span className={HINT}>Sent {etDateTime(check.submitted_at)}</span>
          {note && <span className="line-clamp-2 break-words text-sm text-hub-ink">{note}</span>}
        </span>
        <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
      </button>
    </li>
  );
}

function CheckList({ tick, onOpen, onForbidden }: { tick: number; onOpen: (uuid: string) => void; onForbidden: () => void }) {
  const [filters, setFilters] = useState<NarcoticsFilters>(EMPTY_FILTERS);
  const [search, setSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setFilters((current) => (current.q === search ? current : { ...current, q: search, page: 1 })), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const archive = filters.scope === 'archive';
  const rangeError = dateRangeError(filters.dateFrom, filters.dateTo);
  const effective: NarcoticsFilters = rangeError ? { ...filters, dateFrom: '', dateTo: '' } : filters;
  const query = checksQuery(effective);
  const { data, error, loading, reload } = useEmsLoad(() => fetchChecks(query), query, { tick, onForbidden });
  const change = (changes: Partial<NarcoticsFilters>) => setFilters((current) => ({ ...current, page: 1, ...changes }));
  const narrowed = filters.q !== '' || filters.dateFrom !== '' || filters.dateTo !== '' || filters.outcome !== 'all';
  const pending = data?.meta.pending_count ?? 0;

  return (
    <div className="space-y-4">
      <ChoiceChips<NarcoticsScope>
        label="Narcotics checks"
        testId="narcotics-scope"
        value={filters.scope}
        options={[{ id: 'inbox', label: `Inbox${pending > 0 ? ` (${pending})` : ''}` }, { id: 'archive', label: 'Archive' }]}
        onChange={(scope) => change({ scope, outcome: 'all' })}
      />
      <p className={HINT}>{archive ? 'Decided checks are kept here with who decided and when. Search by member, note or date.' : 'Checks waiting for you, oldest first. Open one to look at the photo and decide.'}</p>

      {archive && (
        <>
          <ChoiceChips<NarcoticsOutcome>
            label="Show decisions"
            testId="narcotics-outcome"
            value={filters.outcome}
            options={[{ id: 'all', label: 'All' }, { id: 'approved', label: 'Approved' }, { id: 'rejected', label: 'Rejected' }]}
            onChange={(outcome) => change({ outcome })}
          />
          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <label className={LABEL} htmlFor="narcotics-filter-search">Search</label>
              <input id="narcotics-filter-search" type="search" className={FIELD} value={search} placeholder="Member or note" autoComplete="off" maxLength={100} onChange={(event) => setSearch(event.target.value)} />
            </div>
            <div>
              <label className={LABEL} htmlFor="narcotics-filter-from">From date</label>
              <input id="narcotics-filter-from" type="date" className={FIELD} value={filters.dateFrom} aria-invalid={rangeError ? true : undefined} onChange={(event) => change({ dateFrom: event.target.value })} />
            </div>
            <div>
              <label className={LABEL} htmlFor="narcotics-filter-to">To date</label>
              <input id="narcotics-filter-to" type="date" className={FIELD} value={filters.dateTo} aria-invalid={rangeError ? true : undefined} onChange={(event) => change({ dateTo: event.target.value })} />
            </div>
          </div>
          {rangeError && <p role="alert" className="text-[13px] font-semibold text-hub-danger">{rangeError} The dates are ignored until you fix them.</p>}
          <p className={HINT}>Dates are Eastern time.</p>
        </>
      )}

      {error && data && <LiveNote tone="warning">The list could not be refreshed: {error} What you see may be out of date.</LiveNote>}
      {error && !data && <LoadError title="Narcotics checks could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={3} className="!h-20" />}

      {data && data.checks.length === 0 && !error && (
        <EmptyState
          icon={<ShieldAlert className="hub-empty__icon" aria-hidden="true" />}
          title={archive ? (narrowed ? 'No archived checks match' : 'Nothing archived yet') : 'No narcotics checks waiting'}
          body={archive
            ? (narrowed ? 'Try All, a wider date range, or clear the search.' : 'Approved and rejected checks are kept here.')
            : 'When a member sends a narcotics check it appears here for you to review.'}
        />
      )}
      {data && data.checks.length > 0 && (
        <div className="space-y-3" aria-busy={loading}>
          <ul className="space-y-2" aria-label={archive ? 'Archived narcotics checks' : 'Narcotics checks to review'} data-testid="narcotics-list">
            {data.checks.map((check) => <CheckRow key={check.id} check={check} onOpen={onOpen} />)}
          </ul>
          <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} noun={data.meta.total === 1 ? 'check' : 'checks'} busy={loading} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </div>
      )}
    </div>
  );
}

/** Narcotics Checks: members' photos waiting for Captain 5, and the searchable archive. Reads and writes /api/narcotics-checks/staff. */
export default function NarcoticsTab({ checkId, onOpenCheck, onCloseCheck, tick, onChanged, onForbidden }: Props) {
  return (
    <div className="space-y-5" data-testid="narcotics-tab">
      <CheckList tick={tick} onOpen={onOpenCheck} onForbidden={onForbidden} />
      <CheckSheet uuid={checkId} onClose={onCloseCheck} onChanged={onChanged} onOpen={onOpenCheck} onForbidden={onForbidden} />
    </div>
  );
}
