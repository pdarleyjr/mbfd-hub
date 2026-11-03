import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ClipboardList, PackageSearch, Pencil, Plus } from 'lucide-react';
import { EmptyState, LoadError, SkeletonRows } from '../../../ui/StateViews';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, HINT, LiveNote } from '../shared';
import AddItemSheet from './AddItemSheet';
import EditBar from './EditBar';
import { EditList } from './EditRows';
import InventorySearch from './InventorySearch';
import ItemSheet from './ItemSheet';
import LowStockCard from './LowStockCard';
import MasterTable from './MasterTable';
import ShelfNav from './ShelfNav';
import SummaryStrip from './SummaryStrip';
import { buildSearchIndex, groupBySection, lastCountText, normalizeText, searchItems, sectionParam } from './logic';
import { applyFilter, filterHeading, summarize, type OrderFilter } from './orderLogic';
import type { MasterItem } from './types';
import { hasRetainedEdits, useBatchEdit } from './useBatchEdit';
import { useMasterData } from './useMasterData';
import { useShelfParam } from './useShelfParam';

interface Props {
  canStart: boolean;
  onCounting: (open: boolean) => void;
  onForbidden: () => void;
  /** The standalone page has its own page heading, so the tab heading is left out there. */
  showTitle?: boolean;
}

const RESULT_LIMIT = 200;
const NO_ITEMS: MasterItem[] = [];

/**
 * The browsing view: one shelf at a time, or results from every shelf (search, or an order filter from the summary).
 * Edit turns the same list into editable rows; the item name (or the order badge in Edit mode) opens the item sheet.
 */
export default function MasterInventoryView({ canStart, onCounting, onForbidden, showTitle = true }: Props) {
  const [openId, setOpenId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
  // Changes left unsaved on an earlier visit (Back, a link, another tab) bring Edit mode straight back with them.
  const [resumed] = useState(hasRetainedEdits);
  const [editing, setEditing] = useState(resumed);
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<OrderFilter | null>(null);
  const [highlightId, setHighlightId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [savedNote, setSavedNote] = useState<string | null>(null);
  const pendingJump = useRef<number | null>(null);
  const lastOpened = useRef<number | null>(null);
  const openedFromCard = useRef(false);
  const master = useMasterData({ paused: openId !== null || adding || editing, onForbidden });
  const { data, replaceItems } = master;

  const items = useMemo(() => data?.items ?? NO_ITEMS, [data?.items]);
  const sections = useMemo(() => data?.sections ?? [], [data?.sections]);
  const units = useMemo(() => data?.units ?? [], [data?.units]);
  const summary = useMemo(() => summarize(items), [items]);
  const shelf = useShelfParam(sections);
  const searchIndex = useMemo(() => buildSearchIndex(items), [items]);
  const groups = useMemo(() => groupBySection(items, sections), [items, sections]);
  const searching = normalizeText(query) !== '';
  const results = useMemo(() => searchItems(searchIndex, query, RESULT_LIMIT), [searchIndex, query]);
  const listing = searching || filter !== null;
  const listed = useMemo(() => applyFilter(searching ? results : items, filter), [searching, results, items, filter]);
  const current = sections[shelf.index];
  const shelfItems = current ? groups.get(sectionParam(current)) ?? [] : [];
  const canEdit = data?.can_edit === true;
  const openItem = openId === null ? null : items.find((entry) => entry.id === openId) ?? null;

  const batch = useBatchEdit({ items, onSaved: replaceItems });

  // Stable handlers, so a memoized Edit mode row only redraws when its own item, draft or errors change.
  const openSheet = useCallback((item: MasterItem) => { openedFromCard.current = false; setOpenId(item.id); }, []);
  const openFromCard = useCallback((item: MasterItem) => { openedFromCard.current = true; setOpenId(item.id); }, []);
  const closeSheet = useCallback(() => setOpenId(null), []);

  /**
   * Focus after the sheet closes: the control that opened it (the Low or out card's item, the name in view mode, the order
   * badge in Edit mode). If that row is gone, for example it left the filter, focus goes to the pressed summary button,
   * then the heading.
   */
  const returnFocus = useCallback((): HTMLElement | null => {
    const id = lastOpened.current;
    const cardOpener = id !== null && openedFromCard.current
      ? document.querySelector<HTMLElement>(`[data-testid="low-stock-item"][data-item-id="${id}"]`)
      : null;
    const opener = cardOpener ?? (id === null ? null : document.querySelector<HTMLElement>(
      `#master-row-${id} [data-testid="open-item"], [data-testid="edit-row"][data-item-id="${id}"] [data-testid="edit-state"]`,
    ));
    return opener
      ?? document.querySelector<HTMLElement>('[data-testid="order-summary"] [aria-pressed="true"]')
      ?? document.getElementById('master-inventory-heading')
      ?? document.getElementById('master-search');
  }, []);

  const jumpTo = useCallback((item: MasterItem) => {
    const target = sections.findIndex((section) => section.code === item.location_code);
    setQuery('');
    setFilter(null);
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
    if (highlightId === null || listing) return undefined;
    const frame = window.requestAnimationFrame(() => document.getElementById(`master-row-${highlightId}`)?.scrollIntoView({ block: 'center' }));
    const timer = window.setTimeout(() => setHighlightId(null), 8000);
    return () => { window.cancelAnimationFrame(frame); window.clearTimeout(timer); };
  }, [highlightId, listing, shelf.index]);

  const afterWrite = (message: string) => { setNotice(message); void master.reload(true); };
  const startEditing = () => { setNotice(null); setSavedNote(null); setOpenId(null); setEditing(true); };
  useEffect(() => { if (resumed) setNotice('Your unsaved changes from earlier are still here. Save them or press Cancel.'); }, [resumed]);
  useEffect(() => { if (openId !== null) lastOpened.current = openId; }, [openId]);
  const stopEditing = () => { batch.reset(); setConfirmLeave(false); setSavedNote(null); setEditing(false); void master.reload(true); };
  const askToStop = () => { if (batch.dirtyCount > 0) setConfirmLeave(true); else stopEditing(); };
  const saveAll = async () => {
    const saved = await batch.save();
    if (saved) setSavedNote(`Saved ${saved} ${saved === 1 ? 'item' : 'items'}.`);
  };
  const changeFilter = (next: OrderFilter | null) => { setFilter(next); setHighlightId(null); };
  const last = lastCountText(data?.last_count ?? null);
  const draft = data?.draft ?? null;

  const renderList = (list: readonly MasterItem[], caption: string, links: boolean) => (editing
    ? <EditList items={list} caption={caption} draftFor={batch.draftFor} errorsFor={batch.errorsFor} sections={sections} units={units} disabled={batch.busy}
        onChange={batch.change} onUndo={batch.undo} onOpen={openSheet} />
    : <MasterTable items={list} caption={caption} shelfLinks={links} hrefFor={shelf.hrefFor} highlightId={links ? null : highlightId}
        onOpen={openSheet} onGoToShelf={jumpTo} />);

  const listingCount = searching
    ? `${listed.length} ${listed.length === 1 ? 'item' : 'items'} on all shelves${results.length >= RESULT_LIMIT ? '. Narrow the search to see the rest.' : ''}`
    : filter ? `${filterHeading(filter, listed.length)}, on all shelves` : '';

  return (
    <div className="space-y-4" data-testid="master-inventory" data-mode={editing ? 'edit' : 'view'}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          {showTitle && <h2 id="master-inventory-heading" tabIndex={-1} className="font-heading text-lg font-bold text-hub-ink">Master Supply Room Inventory</h2>}
          {last && <p className="text-[13px] leading-5 text-hub-n-600" data-testid="last-count">{last}</p>}
        </div>
        {canEdit && (
          <button type="button" className={`${editing ? BTN_PRIMARY : BTN_SECONDARY} flex-none gap-2`} aria-pressed={editing} data-testid="edit-mode"
            disabled={batch.busy} aria-busy={batch.busy} onClick={editing ? askToStop : startEditing}>
            {!editing && <Pencil className="h-4 w-4 flex-none" aria-hidden="true" />}
            <span>{editing ? 'Done' : 'Edit'}</span>
          </button>
        )}
      </div>

      {editing && <p className={HINT} data-testid="edit-intro">Change any box below. Your changes stay while you move between shelves or search, and nothing is saved until you press Save.</p>}

      {canStart && data && !editing && (
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
          <LowStockCard items={items} onOpen={openFromCard} headingLevel={showTitle ? 3 : 2} />
          <InventorySearch value={query} onChange={(value) => { setQuery(value); setHighlightId(null); }}
            resultNote={searching ? `${listed.length} ${listed.length === 1 ? 'item matches' : 'items match'}` : ''} />
          <SummaryStrip summary={summary} active={filter} onChange={changeFilter} />

          {listing ? (
            listed.length === 0 ? (
              filter && !searching
                ? <EmptyState title="Nothing here now" body="No items are in this state any more."
                    action={<button type="button" className={BTN_SECONDARY} onClick={() => changeFilter(null)}>Show all shelves</button>} />
                : <EmptyState icon={<PackageSearch className="hub-empty__icon" aria-hidden="true" />} title="Nothing matches that"
                    body="Search looks at item names, other names and shelves. Try fewer words or a different spelling."
                    action={<button type="button" className={BTN_SECONDARY} onClick={() => { setQuery(''); changeFilter(null); }}>Clear search</button>} />
            ) : (
              <section aria-label={searching ? 'Search results' : 'Filtered items'} className="space-y-2" data-testid={searching ? 'search-results' : 'filter-results'}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-[13px] text-hub-n-600" data-testid={searching ? 'search-count' : 'filter-count'}>{listingCount}</p>
                  {filter && <button type="button" className={`${BTN} hub-btn--tertiary`} data-testid="filter-clear" onClick={() => changeFilter(null)}>Show all shelves</button>}
                </div>
                {renderList(listed, searching ? 'Search results from all shelves' : 'Filtered items from all shelves', true)}
              </section>
            )
          ) : sections.length === 0 ? (
            <EmptyState title="No shelves yet" body={canEdit ? 'Add an item to start the list.' : 'Nothing has been set up in the supply room yet.'} />
          ) : (
            <section aria-label={current ? `Shelf ${current.label}` : 'Shelf'} className="space-y-3" data-testid="shelf-view">
              <ShelfNav sections={sections} index={shelf.index} onIndex={(next) => { setHighlightId(null); shelf.setIndex(next); }} placement="top" picker />
              {shelfItems.length === 0
                ? <EmptyState title="Nothing on this shelf" body="Use the arrows to see the next shelf." />
                : renderList(shelfItems, `Items on ${current?.label ?? 'this shelf'}`, false)}
              <ShelfNav sections={sections} index={shelf.index} onIndex={(next) => { setHighlightId(null); shelf.setIndex(next); window.scrollTo({ top: 0 }); }} placement="bottom" />
            </section>
          )}

          {editing && (
            <EditBar dirtyCount={batch.dirtyCount} busy={batch.busy} retry={batch.retry} error={batch.error} general={batch.general} note={savedNote}
              problems={items.filter((item) => batch.problemIds.includes(item.id))} confirming={confirmLeave} onConfirming={setConfirmLeave}
              onSave={() => { setSavedNote(null); void saveAll(); }} onDiscard={stopEditing} onDone={stopEditing} onJump={jumpTo} />
          )}
        </>
      )}

      <ItemSheet item={openItem} canEdit={canEdit} editing={editing} onClose={closeSheet} returnFocusTo={returnFocus} onStale={() => void master.reload(true)}
        onSaved={(item) => master.replaceItem(item)}
        onRemoved={(item) => { master.removeItem(item.id); afterWrite(`${item.name} was removed from the inventory.`); }} />
      <AddItemSheet open={adding} sections={sections} inactive={data?.inactive ?? []} units={units} defaultShelf={current?.code ?? null} onClose={() => setAdding(false)}
        onAdded={(item) => { master.replaceItem(item); pendingJump.current = item.id; afterWrite(`${item.name} was added.`); }} />
    </div>
  );
}
