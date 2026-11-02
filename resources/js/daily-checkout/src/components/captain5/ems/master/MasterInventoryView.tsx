import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ClipboardList, PackageSearch, Plus } from 'lucide-react';
import { EmptyState, LoadError, SkeletonRows } from '../../../ui/StateViews';
import { BTN_PRIMARY, BTN_SECONDARY, LiveNote } from '../shared';
import AddItemSheet from './AddItemSheet';
import EditItemSheet from './EditItemSheet';
import InventorySearch from './InventorySearch';
import MasterTable from './MasterTable';
import ShelfNav from './ShelfNav';
import { buildSearchIndex, groupBySection, lastCountText, normalizeText, searchItems, sectionParam } from './logic';
import type { MasterItem } from './types';
import { useMasterData } from './useMasterData';
import { useShelfParam } from './useShelfParam';

interface Props {
  canStart: boolean;
  onCounting: (open: boolean) => void;
  onForbidden: () => void;
}

const RESULT_LIMIT = 200;

/** The browsing view: one shelf at a time, or search results from every shelf. */
export default function MasterInventoryView({ canStart, onCounting, onForbidden }: Props) {
  const [editing, setEditing] = useState<MasterItem | null>(null);
  const [adding, setAdding] = useState(false);
  const [query, setQuery] = useState('');
  const [highlightId, setHighlightId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const pendingJump = useRef<number | null>(null);
  const master = useMasterData({ paused: editing !== null || adding, onForbidden });
  const { data } = master;

  const items = data?.items ?? [];
  const sections = useMemo(() => data?.sections ?? [], [data?.sections]);
  const shelf = useShelfParam(sections);
  const searchIndex = useMemo(() => buildSearchIndex(items), [items]);
  const groups = useMemo(() => groupBySection(items, sections), [items, sections]);
  const searching = normalizeText(query) !== '';
  const results = useMemo(() => searchItems(searchIndex, query, RESULT_LIMIT), [searchIndex, query]);
  const current = sections[shelf.index];
  const shelfItems = current ? groups.get(sectionParam(current)) ?? [] : [];
  const canEdit = data?.can_edit === true;

  const jumpTo = useCallback((item: MasterItem) => {
    const target = sections.findIndex((section) => section.code === item.location_code);
    setQuery('');
    setHighlightId(item.id);
    if (target >= 0) shelf.setIndex(target);
  }, [sections, shelf]);

  // A newly added item may sit on a shelf that only exists after the refresh.
  useEffect(() => {
    const wanted = pendingJump.current;
    const found = wanted === null ? undefined : items.find((entry) => entry.id === wanted);
    if (found) { pendingJump.current = null; jumpTo(found); }
  }, [items, jumpTo]);

  useEffect(() => {
    if (highlightId === null || searching) return undefined;
    const frame = window.requestAnimationFrame(() => document.getElementById(`master-row-${highlightId}`)?.scrollIntoView({ block: 'center' }));
    const timer = window.setTimeout(() => setHighlightId(null), 8000);
    return () => { window.cancelAnimationFrame(frame); window.clearTimeout(timer); };
  }, [highlightId, searching, shelf.index]);

  const afterWrite = (message: string) => { setNotice(message); void master.reload(true); };
  const last = lastCountText(data?.last_count ?? null);
  const draft = data?.draft ?? null;

  return (
    <div className="space-y-4" data-testid="master-inventory">
      <div className="space-y-1">
        <h2 className="font-heading text-lg font-bold text-hub-ink">Master Supply Room Inventory</h2>
        {last && <p className="text-[13px] leading-5 text-hub-n-600" data-testid="last-count">{last}</p>}
      </div>

      {canStart && data && (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <button type="button" className={`${BTN_PRIMARY} !min-h-12 w-full gap-2 text-base sm:w-auto`} data-testid="start-count" onClick={() => onCounting(true)}>
            <ClipboardList className="h-5 w-5 flex-none" aria-hidden="true" />
            <span>{draft ? `Resume count (${draft.counted} entered)` : 'Count Supply Room'}</span>
          </button>
          {canEdit && (
            <button type="button" className={`${BTN_SECONDARY} w-full gap-2 sm:w-auto`} data-testid="add-item" onClick={() => { setNotice(null); setAdding(true); }}>
              <Plus className="h-4 w-4 flex-none" aria-hidden="true" />
              <span>Add item</span>
            </button>
          )}
        </div>
      )}

      <div role="status" aria-live="polite" data-testid="master-notice">{notice && <p className="text-sm font-semibold text-hub-success">{notice}</p>}</div>

      {master.error && !data && <LoadError title="The supply room could not be loaded" message={master.error} onRetry={() => void master.reload(false)} />}
      {master.loading && !data && !master.error && <SkeletonRows rows={5} />}
      {master.error && data && <LiveNote tone="warning">The list could not be refreshed: {master.error} What you see may be out of date.</LiveNote>}

      {data && (
        <>
          <InventorySearch value={query} onChange={(value) => { setQuery(value); setHighlightId(null); }}
            resultNote={searching ? `${results.length} ${results.length === 1 ? 'item matches' : 'items match'}` : ''} />

          {searching ? (
            results.length === 0 ? (
              <EmptyState icon={<PackageSearch className="hub-empty__icon" aria-hidden="true" />} title="Nothing matches that"
                body="Search looks at item names, other names and shelves. Try fewer words or a different spelling."
                action={<button type="button" className={BTN_SECONDARY} onClick={() => setQuery('')}>Clear search</button>} />
            ) : (
              <section aria-label="Search results" className="space-y-2" data-testid="search-results">
                <p className="text-[13px] text-hub-n-600" data-testid="search-count">{results.length} {results.length === 1 ? 'item' : 'items'} on all shelves{results.length >= RESULT_LIMIT ? '. Narrow the search to see the rest.' : ''}</p>
                <MasterTable items={results} caption="Search results from all shelves" canEdit={canEdit} shelfLinks hrefFor={shelf.hrefFor} onEdit={setEditing} onGoToShelf={jumpTo} />
              </section>
            )
          ) : sections.length === 0 ? (
            <EmptyState title="No shelves yet" body={canEdit ? 'Add an item to start the list.' : 'Nothing has been set up in the supply room yet.'} />
          ) : (
            <section aria-label={current ? `Shelf ${current.label}` : 'Shelf'} className="space-y-3" data-testid="shelf-view">
              <ShelfNav sections={sections} index={shelf.index} onIndex={(next) => { setHighlightId(null); shelf.setIndex(next); }} placement="top" picker />
              {shelfItems.length === 0
                ? <EmptyState title="Nothing on this shelf" body="Use the arrows to see the next shelf." />
                : <MasterTable items={shelfItems} caption={`Items on ${current?.label ?? 'this shelf'}`} canEdit={canEdit} highlightId={highlightId} onEdit={setEditing} />}
              <ShelfNav sections={sections} index={shelf.index} onIndex={(next) => { setHighlightId(null); shelf.setIndex(next); window.scrollTo({ top: 0 }); }} placement="bottom" />
            </section>
          )}
        </>
      )}

      <EditItemSheet item={editing} sections={sections} onClose={() => setEditing(null)}
        onSaved={(item) => { master.replaceItem(item); afterWrite(`${item.name} saved.`); }}
        onRemoved={(item) => { master.removeItem(item.id); afterWrite(`${item.name} was removed from the inventory.`); }} />
      <AddItemSheet open={adding} sections={sections} inactive={data?.inactive ?? []} defaultShelf={current?.code ?? null} onClose={() => setAdding(false)}
        onAdded={(item) => { master.replaceItem(item); pendingJump.current = item.id; afterWrite(`${item.name} was added.`); }} />
    </div>
  );
}
