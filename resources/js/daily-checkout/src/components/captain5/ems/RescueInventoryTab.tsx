import { useEffect, useMemo, useState } from 'react';
import { ChevronRight, ClipboardList } from 'lucide-react';
import { fetchRequests } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import RequestSheet from './RequestSheet';
import UnitStatistics from './UnitStatistics';
import {
  EMPTY_FILTERS, REQUEST_TONE, STATUS_CHIPS, dateRangeError, etDateTime, itemsText, memberText, requestListQuery, targetText,
} from './logic';
import { ChoiceChips, FIELD, HINT, LABEL, LiveNote, Pager, useEmsLoad } from './shared';
import type { EmsMeta, RequestFilters, RequestSummary } from './types';

export type RescueView = 'requests' | 'stats';

interface Props {
  meta: EmsMeta;
  requestId: string | null;
  view: RescueView;
  onView: (view: RescueView) => void;
  onOpenRequest: (uuid: string) => void;
  onCloseRequest: () => void;
  /** Bumped once a minute while the page is visible (and on focus): the list refreshes quietly. */
  tick: number;
  /** A request changed: the tab badge and the list refresh. */
  onChanged: () => void;
  onForbidden: () => void;
}

const DEBOUNCE_MS = 300;

function RequestRow({ request, onOpen }: { request: RequestSummary; onOpen: (uuid: string) => void }) {
  return (
    <li>
      <button
        type="button"
        data-testid="ems-request-row"
        onClick={() => onOpen(request.id)}
        className="flex min-h-14 w-full items-center gap-3 rounded-hub-control border border-hub-border bg-hub-well px-3.5 py-3 text-left transition-colors hover:bg-hub-blue-tint"
      >
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <strong className="text-hub-ink">{request.number}</strong>
            <Tag tone={REQUEST_TONE[request.status] ?? 'neutral'}>{request.status_label}</Tag>
            {request.has_note && <Tag>Has a note</Tag>}
          </span>
          <span className="break-words font-semibold text-hub-ink">{targetText(request)}</span>
          <span className={`${HINT} break-words`}>{memberText(request)} · Submitted {etDateTime(request.submitted_at)}</span>
          <span className={HINT}>{itemsText(request)}</span>
        </span>
        <ChevronRight className="h-5 w-5 flex-none text-hub-n-500" aria-hidden="true" />
      </button>
    </li>
  );
}

function RequestList({ meta, tick, onOpen, onForbidden }: { meta: EmsMeta; tick: number; onOpen: (uuid: string) => void; onForbidden: () => void }) {
  const [filters, setFilters] = useState<RequestFilters>(EMPTY_FILTERS);
  const [search, setSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setFilters((current) => (current.q === search ? current : { ...current, q: search, page: 1 })), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [search]);

  const rangeError = dateRangeError(filters.dateFrom, filters.dateTo);
  const effective: RequestFilters = rangeError ? { ...filters, dateFrom: '', dateTo: '' } : filters;
  const query = requestListQuery(effective);
  const { data, error, loading, reload } = useEmsLoad(() => fetchRequests(query), query, { tick, onForbidden });
  const change = (changes: Partial<RequestFilters>) => setFilters((current) => ({ ...current, page: 1, ...changes }));
  const grouped = useMemo(() => {
    const groups = new Map<string, typeof meta.units>();
    for (const unit of meta.units) groups.set(unit.group, [...(groups.get(unit.group) ?? []), unit]);
    return [...groups.entries()];
  }, [meta.units]);
  const narrowed = filters.unit !== '' || filters.q !== '' || filters.dateFrom !== '' || filters.dateTo !== '' || filters.chip !== 'open';
  const chip = STATUS_CHIPS.find((entry) => entry.id === filters.chip);

  return (
    <div className="space-y-4">
      <ChoiceChips label="Show requests" testId="ems-status-chips" value={filters.chip} options={STATUS_CHIPS.map(({ id, label }) => ({ id, label }))} onChange={(id) => change({ chip: id })} />
      <p className={HINT}>{filters.chip === 'open' ? 'Open means submitted, reviewed, prepared or partly issued.' : filters.chip === 'closed' ? 'Closed includes requests the member cancelled.' : `Showing: ${chip?.label ?? 'All'}.`}</p>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label className={LABEL} htmlFor="ems-filter-unit">Unit</label>
          <select id="ems-filter-unit" className={FIELD} value={filters.unit} onChange={(event) => change({ unit: event.target.value })}>
            <option value="">All units</option>
            {grouped.map(([group, units]) => (
              <optgroup key={group} label={group}>
                {units.map((unit) => <option key={unit.id ?? 'detail'} value={unit.id === null ? 'detail' : String(unit.id)}>{unit.label}</option>)}
              </optgroup>
            ))}
          </select>
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-filter-from">From date</label>
          <input id="ems-filter-from" type="date" className={FIELD} value={filters.dateFrom} aria-invalid={rangeError ? true : undefined} onChange={(event) => change({ dateFrom: event.target.value })} />
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-filter-to">To date</label>
          <input id="ems-filter-to" type="date" className={FIELD} value={filters.dateTo} aria-invalid={rangeError ? true : undefined} onChange={(event) => change({ dateTo: event.target.value })} />
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-filter-search">Search</label>
          <input id="ems-filter-search" type="search" className={FIELD} value={search} placeholder="Number, member or unit" autoComplete="off" maxLength={100} onChange={(event) => setSearch(event.target.value)} />
        </div>
      </div>
      {rangeError && <p role="alert" className="text-[13px] font-semibold text-hub-danger">{rangeError} The dates are ignored until you fix them.</p>}
      <p className={HINT}>Dates are Eastern time.</p>

      {error && data && <LiveNote tone="warning">The list could not be refreshed: {error} What you see may be out of date.</LiveNote>}
      {error && !data && <LoadError title="Requests could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={4} />}

      {data && data.requests.length === 0 && !error && (
        <EmptyState
          icon={<ClipboardList className="hub-empty__icon" aria-hidden="true" />}
          title={narrowed ? 'No requests match these filters' : 'No open requests'}
          body={narrowed ? 'Try All, a wider date range, or clear the search.' : 'When a member sends a Medical Inventory Request it appears here for you to work.'}
        />
      )}
      {data && data.requests.length > 0 && (
        <div className="space-y-3" aria-busy={loading}>
          <ul className="space-y-2" aria-label="Requests" data-testid="ems-request-list">
            {data.requests.map((request) => <RequestRow key={request.id} request={request} onOpen={onOpen} />)}
          </ul>
          <Pager page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} noun={data.meta.total === 1 ? 'request' : 'requests'} busy={loading} onPage={(page) => setFilters((current) => ({ ...current, page }))} />
        </div>
      )}
    </div>
  );
}

/** Rescue Inventory: the Captain 5 view of the same requests Admin works. No second ticket system: it reads and writes /ems/api. */
export default function RescueInventoryTab({ meta, requestId, view, onView, onOpenRequest, onCloseRequest, tick, onChanged, onForbidden }: Props) {
  return (
    <div className="space-y-5" data-testid="ems-rescue-tab">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <ChoiceChips<RescueView> label="Rescue Inventory view" testId="ems-rescue-views" value={view} onChange={onView}
          options={[{ id: 'requests', label: 'Requests' }, { id: 'stats', label: 'Unit statistics' }]} />
      </div>
      {view === 'requests'
        ? <RequestList meta={meta} tick={tick} onOpen={onOpenRequest} onForbidden={onForbidden} />
        : <UnitStatistics meta={meta} onForbidden={onForbidden} />}
      <RequestSheet uuid={requestId} meta={meta} onClose={onCloseRequest} onChanged={onChanged} />
    </div>
  );
}
