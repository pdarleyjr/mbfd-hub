import { useCallback, useRef, useState } from 'react';
import { ArrowLeft } from 'lucide-react';
import { EmptyState, LoadError, SkeletonRows } from '../../../ui/StateViews';
import { BTN_PRIMARY, BTN_SECONDARY, HINT } from '../shared';
import CountDone from './CountDone';
import CountHeader from './CountHeader';
import CountReview from './CountReview';
import CountRow from './CountRow';
import CountSearch from './CountSearch';
import DiscardCount from './DiscardCount';
import KeyboardBar from './KeyboardBar';
import ShelfNav from './ShelfNav';
import { enteredTotal } from './logic';
import type { MasterItem, ReviewMissing, SubmitResult } from './types';
import { useCountDraft } from './useCountDraft';
import { useCountNavigation } from './useCountNavigation';
import { useShelfParam } from './useShelfParam';

interface Props {
  onExit: () => void;
  onForbidden: () => void;
}

type View = 'count' | 'review' | 'done';

const TITLE = 'Captain 5 Medical Supply Room Inventory';

/** The Monday count: one shelf at a time, one big number box per item, saved as you type. */
export default function CountSupplyRoom({ onExit, onForbidden }: Props) {
  const draft = useCountDraft({ onForbidden });
  const [view, setView] = useState<View>('count');
  const [result, setResult] = useState<SubmitResult | null>(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const listTop = useRef<HTMLDivElement>(null);
  const { sections, items } = draft;
  const shelf = useShelfParam(sections);
  const setShelf = useCallback((index: number) => shelf.setIndex(index, false), [shelf]);
  const nav = useCountNavigation({ sections, items, shelfIndex: shelf.index, setShelf, view });

  const current = sections[shelf.index];
  const rows: readonly MasterItem[] = nav.shelves[shelf.index] ?? [];
  const entered = enteredTotal(items, draft.entries);

  const fixMissing = (item: ReviewMissing) => {
    setView('count');
    nav.goToItem(item.id);
  };
  const bottomShelf = (index: number) => {
    setShelf(index);
    window.requestAnimationFrame(() => listTop.current?.scrollIntoView({ block: 'start' }));
  };

  if (draft.phase === 'loading') return <div data-testid="count-supply-room"><SkeletonRows rows={5} /></div>;
  if (draft.phase === 'error') {
    return (
      <div data-testid="count-supply-room">
        <LoadError title="The count could not be opened" message={draft.loadError} onRetry={draft.reopen}>
          <button type="button" className={BTN_SECONDARY} onClick={onExit}>Back to inventory</button>
        </LoadError>
      </div>
    );
  }
  if (view === 'done' && result) return <div data-testid="count-supply-room"><CountDone result={result} onExit={onExit} /></div>;

  const title = (
    <div className="flex items-start gap-2">
      <button type="button" className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 flex-none !px-2.5" aria-label={view === 'review' ? 'Back to counting' : 'Back to inventory'} data-testid="count-back" onClick={view === 'review' ? () => setView('count') : onExit}>
        <ArrowLeft className="h-5 w-5" aria-hidden="true" />
      </button>
      <div className="min-w-0">
        <h2 className="font-heading text-base font-bold leading-snug text-hub-ink sm:text-lg" data-testid="count-title">{TITLE}</h2>
        <p className={HINT}>{view === 'review' ? 'Check the count, then submit it.' : 'Type what is on the shelf. Leave an item blank if you do not count it. Numbers save as you go.'}</p>
      </div>
    </div>
  );

  if (view === 'review') {
    return (
      <div className="space-y-4" data-testid="count-supply-room">
        {title}
        <CountReview draft={draft} onFix={fixMissing} onBack={() => setView('count')}
          onSubmitted={(submitted) => { setResult(submitted); setView('done'); }} onDiscarded={onExit} />
      </div>
    );
  }

  return (
    <div className="space-y-3" data-testid="count-supply-room">
      {title}
      <CountHeader sections={sections} shelfIndex={shelf.index} onShelf={setShelf} entered={entered} total={items.length} status={draft.status}
        searchOpen={searchOpen} onToggleSearch={() => setSearchOpen((open) => !open)} onReview={() => setView('review')} />

      {searchOpen && <CountSearch items={items} entries={draft.entries} onPick={(item) => { setSearchOpen(false); nav.goToItem(item.id); }} />}

      {(draft.status === 'offline' || draft.status === 'error') && (
        <div role="alert" className="hub-callout hub-callout--warning space-y-2 p-3 text-sm" data-testid="save-banner">
          <p>{draft.status === 'offline'
            ? 'You are offline. Your numbers are kept on this device and will be sent when the connection is back.'
            : `${draft.saveError ?? 'The numbers could not be saved.'} Your numbers are kept on this device.`}</p>
          <button type="button" className={BTN_SECONDARY} onClick={draft.retrySave} data-testid="save-retry">Try saving again</button>
        </div>
      )}

      <div ref={listTop} className="scroll-mt-40" />
      {rows.length === 0 ? (
        <EmptyState title="No items on this shelf" body="Use the arrows to go to the next shelf." />
      ) : (
        <ul aria-label={`Items on ${current?.label ?? 'this shelf'}`} className="overflow-hidden rounded-hub-card border border-hub-glass-border bg-hub-well" data-testid="count-list">
          {rows.map((item) => (
            <CountRow key={item.id} item={item} value={draft.entries[String(item.id)] ?? null} onChange={draft.setEntry} onEnter={nav.onEnter}
              onFocusItem={nav.onFocusItem} register={nav.register} />
          ))}
        </ul>
      )}

      <ShelfNav sections={sections} index={shelf.index} onIndex={bottomShelf} placement="bottom" />

      <button ref={nav.reviewButton} type="button" className={`${BTN_PRIMARY} !min-h-12 w-full text-base`} data-testid="review-bottom" onClick={() => setView('review')}>
        Review and submit
      </button>
      {draft.count && <DiscardCount countId={draft.count.id} onDiscarded={() => { draft.finish(); onExit(); }} />}
      <KeyboardBar onPrevious={() => nav.stepFocused(-1)} onNext={() => nav.stepFocused(1)} />
    </div>
  );
}
