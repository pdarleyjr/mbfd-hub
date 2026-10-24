import { useMemo, useState } from 'react';
import { ChevronDown, ChevronUp, PackageSearch } from 'lucide-react';
import { fetchAllSupplyItems, fetchLocations, fetchSupplyItem } from '../../../utils/emsApi';
import { EmptyState, LoadError, SkeletonRows } from '../../ui/StateViews';
import Tag from '../../ui/Tag';
import ItemSettings from './ItemSettings';
import { etDateTime, groupByLocation, packageText, splitControlled, stockState, stockText, thresholdText } from './logic';
import { BTN_SECONDARY, ChoiceChips, FIELD, HINT, LABEL, LiveNote, useEmsLoad } from './shared';
import type { EmsMeta, SupplyItem } from './types';

type StockFilter = 'all' | 'below' | 'review';

const STATE_STYLE: Record<string, string> = {
  below: 'border-hub-danger-border bg-hub-danger-bg',
  review: 'border-hub-warning-border bg-hub-warning-bg',
  ok: 'border-hub-border bg-hub-well',
  uncounted: 'border-hub-border bg-hub-well',
};

function ItemDetails({ item, meta, onChanged, onForbidden }: { item: SupplyItem; meta: EmsMeta; onChanged: () => void; onForbidden: () => void }) {
  const [editing, setEditing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const detail = useEmsLoad(() => fetchSupplyItem(item.id), `item-${item.id}`, { onForbidden });
  const locations = useEmsLoad(() => (meta.permissions.configure ? fetchLocations() : Promise.resolve([])), 'locations', { onForbidden });
  const shown = detail.data ?? item;

  return (
    <div className="space-y-3 border-t border-hub-border pt-3" data-testid="ems-item-details">
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div><dt className={HINT}>Package</dt><dd className="text-hub-ink">{packageText(shown)}{shown.qty_per_package_text ? ` (${shown.qty_per_package_text})` : ''}</dd></div>
        <div><dt className={HINT}>Usual order amount</dt><dd className="text-hub-ink">{shown.order_amount_text ?? 'Not stated'}</dd></div>
        {shown.suppliers.length > 0 && (
          <div className="sm:col-span-2">
            <dt className={HINT}>Suppliers (historical, unverified)</dt>
            <dd className="text-hub-ink">{shown.suppliers.map((supplier) => `${String(supplier.code ?? supplier.raw ?? '')}`).filter(Boolean).join(', ') || 'None listed'}{shown.item_numbers.length > 0 ? `. Item numbers: ${shown.item_numbers.join(', ')}` : ''}</dd>
            <dd className={HINT}>{shown.suppliers_note}</dd>
          </div>
        )}
        {shown.needs_review && <div className="sm:col-span-2"><dt className={HINT}>Why it needs review</dt><dd className="text-hub-ink">{shown.review_reasons.join(', ') || 'The source text was unclear.'}</dd></div>}
        {shown.current_basis_at && <div><dt className={HINT}>Basis date</dt><dd className="text-hub-ink">{etDateTime(shown.current_basis_at)}</dd></div>}
      </dl>
      {detail.error && <LiveNote tone="warning">{detail.error}</LiveNote>}
      {detail.data?.audit && detail.data.audit.length > 0 && (
        <details className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3 py-2">
          <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">Changes to this item</summary>
          <ul className="space-y-1 pb-2 text-[13px] text-hub-n-600">
            {detail.data.audit.map((entry, index) => (
              <li key={`${entry.created_at}-${index}`}>{entry.field.replace(/_/g, ' ')}: {String(entry.before ?? 'none')} to {String(entry.after ?? 'none')}. {entry.reason ?? ''} ({entry.actor_name ?? 'System'}, {etDateTime(entry.created_at)})</li>
            ))}
          </ul>
        </details>
      )}
      <div aria-live="polite">{notice && <p className="text-sm font-semibold text-hub-success">{notice}</p>}</div>
      {meta.permissions.configure && !editing && <button type="button" className={BTN_SECONDARY} onClick={() => { setEditing(true); setNotice(null); }}>Edit settings</button>}
      {meta.permissions.configure && editing && locations.data && (
        <ItemSettings item={shown} locations={locations.data} onCancel={() => setEditing(false)}
          onSaved={(updated) => { detail.setData(updated); setEditing(false); setNotice('Settings saved.'); onChanged(); }} />
      )}
    </div>
  );
}

function ItemCard({ item, meta, onChanged, onForbidden }: { item: SupplyItem; meta: EmsMeta; onChanged: () => void; onForbidden: () => void }) {
  const [open, setOpen] = useState(false);
  const state = stockState(item);
  return (
    <li className={`space-y-2 rounded-hub-control border p-3 ${STATE_STYLE[state] ?? STATE_STYLE.ok}`} data-testid="ems-stock-item" data-state={state}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="min-w-0 break-words font-semibold text-hub-ink">{item.name}</p>
        <span className="flex flex-wrap gap-1.5">
          {state === 'below' && <Tag tone="danger">Below threshold</Tag>}
          {item.needs_review && <Tag tone="warning">Needs review</Tag>}
          {item.refrigerated && <Tag tone="info">Refrigerated</Tag>}
        </span>
      </div>
      <p className="text-sm text-hub-ink">
        <span className="text-base font-bold tabular-nums">{stockText(item)}</span>
        <span className={`${HINT} ml-2`}>{item.current_basis_label ?? 'Not counted yet'}</span>
      </p>
      <p className={HINT}>{thresholdText(item)}. {packageText(item)}.</p>
      <button type="button" className={`${BTN_SECONDARY} !min-h-11 gap-1.5`} aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        {open ? <ChevronUp className="h-4 w-4" aria-hidden="true" /> : <ChevronDown className="h-4 w-4" aria-hidden="true" />}
        <span>{open ? 'Hide details' : 'Details'}</span>
        <span className="sr-only"> for {item.name}</span>
      </button>
      {open && <ItemDetails item={item} meta={meta} onChanged={onChanged} onForbidden={onForbidden} />}
    </li>
  );
}

/** Current stock by location. Controlled substances are listed apart, with no actions: they are not tracked here. */
export default function SupplyStock({ meta, onForbidden }: { meta: EmsMeta; onForbidden: () => void }) {
  const { data, error, loading, reload } = useEmsLoad(() => fetchAllSupplyItems(), 'stock', { onForbidden });
  const [filter, setFilter] = useState<StockFilter>('all');
  const [location, setLocation] = useState('');
  const [search, setSearch] = useState('');

  const { tracked, controlled } = useMemo(() => splitControlled(data ?? []), [data]);
  const locations = useMemo(() => [...new Set(tracked.map((item) => item.location_code).filter((code): code is string => code !== null))].sort(), [tracked]);
  const counts = useMemo(() => ({ below: tracked.filter((item) => item.below_threshold === true).length, review: tracked.filter((item) => item.needs_review).length }), [tracked]);
  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return tracked.filter((item) => (filter === 'all' || (filter === 'below' ? item.below_threshold === true : item.needs_review))
      && (location === '' || item.location_code === location)
      && (needle === '' || item.name.toLowerCase().includes(needle) || (item.bag_alias_text ?? '').toLowerCase().includes(needle)));
  }, [tracked, filter, location, search]);
  const groups = groupByLocation(visible);

  return (
    <div className="space-y-4" data-testid="ems-supply-stock">
      <ChoiceChips<StockFilter> label="Show items" value={filter} onChange={setFilter}
        options={[{ id: 'all', label: 'All items' }, { id: 'below', label: `Below threshold (${counts.below})` }, { id: 'review', label: `Needs review (${counts.review})` }]} />
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className={LABEL} htmlFor="ems-stock-location">Location</label>
          <select id="ems-stock-location" className={FIELD} value={location} onChange={(event) => setLocation(event.target.value)}>
            <option value="">All locations</option>
            {locations.map((code) => <option key={code} value={code}>{code}</option>)}
          </select>
        </div>
        <div>
          <label className={LABEL} htmlFor="ems-stock-search">Search</label>
          <input id="ems-stock-search" type="search" className={FIELD} value={search} autoComplete="off" maxLength={100} placeholder="Item name" onChange={(event) => setSearch(event.target.value)} />
        </div>
      </div>
      <p className={HINT}>Each number says how it was found: a physical count, or calculated from the last count and what was issued or received since.</p>

      {error && data && <LiveNote tone="warning">The stock list could not be refreshed: {error} What you see may be out of date.</LiveNote>}
      {error && !data && <LoadError title="The supply room could not be loaded" message={error} onRetry={() => void reload(false)} />}
      {!error && loading && !data && <SkeletonRows rows={4} />}

      {data && visible.length === 0 && <EmptyState icon={<PackageSearch className="hub-empty__icon" aria-hidden="true" />} title="No items match" body="Change the filters or the search." />}
      {groups.map((group) => (
        <section key={group.code} className="space-y-2" aria-label={`Location ${group.code}`}>
          <h3 className="font-heading text-base font-bold text-hub-ink">{group.code} <span className={`${HINT} font-normal`}>({group.items.length})</span></h3>
          <ul className="space-y-2">{group.items.map((item) => <ItemCard key={item.id} item={item} meta={meta} onChanged={() => void reload(true)} onForbidden={onForbidden} />)}</ul>
        </section>
      ))}

      {controlled.length > 0 && (
        <section className="space-y-2 rounded-hub-control border border-hub-border bg-hub-n-25 p-3" aria-label="Controlled substances" data-testid="ems-controlled">
          <h3 className="font-heading text-base font-bold text-hub-ink">Controlled substances</h3>
          <p className={HINT}>These are tracked through the department controlled-substance procedure, not here. They are not counted, reconciled or reordered in this screen.</p>
          <ul className="list-disc space-y-0.5 pl-5 text-sm text-hub-ink">{controlled.map((item) => <li key={item.id} data-testid="ems-controlled-item">{item.name}{item.location_code ? ` (${item.location_code})` : ''}</li>)}</ul>
        </section>
      )}
    </div>
  );
}
