import { Search } from 'lucide-react';
import ShelfNav from './ShelfNav';
import type { MasterSection, SaveStatus } from './types';

const STATUS_TEXT: Record<SaveStatus, string> = {
  saved: 'Saved',
  saving: 'Saving…',
  offline: 'Offline, saved on this device',
  error: 'Not saved yet, kept on this device',
};

interface Props {
  sections: readonly MasterSection[];
  shelfIndex: number;
  onShelf: (index: number) => void;
  entered: number;
  total: number;
  status: SaveStatus;
  searchOpen: boolean;
  onToggleSearch: () => void;
  onReview: () => void;
}

/**
 * The part of the count form that stays on screen: shelf arrows, progress, save status, search and Review.
 * It is kept to two short rows so a phone keeps room for the list with the keyboard open.
 */
export default function CountHeader({ sections, shelfIndex, onShelf, entered, total, status, searchOpen, onToggleSearch, onReview }: Props) {
  const percent = total === 0 ? 0 : Math.round((entered / total) * 100);
  return (
    <div data-testid="count-header"
      className="sticky top-[calc(var(--hub-shell-bar-height,56px)+env(safe-area-inset-top,0px))] z-20 space-y-1.5 rounded-b-hub-card border border-hub-border bg-hub-n-0 px-2 pb-2 pt-2 shadow-sm sm:px-3">
      <ShelfNav sections={sections} index={shelfIndex} onIndex={onShelf} placement="top" />
      <div className="flex items-center gap-2">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-semibold leading-5 text-hub-ink" data-testid="count-progress">{entered} of {total} items entered</p>
          <p role="status" aria-live="polite" className={`text-[13px] leading-4 ${status === 'saved' ? 'text-hub-n-600' : status === 'saving' ? 'text-hub-n-600' : 'font-semibold text-hub-warning-strong'}`} data-testid="save-status" data-status={status}>
            {STATUS_TEXT[status]}
          </p>
        </div>
        <button type="button" className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 flex-none !px-2.5" aria-expanded={searchOpen} aria-label="Find an item on any shelf" data-testid="count-search-toggle" onClick={onToggleSearch}>
          <Search className="h-5 w-5" aria-hidden="true" />
        </button>
        <button type="button" className="hub-btn hub-btn--primary !min-h-11 flex-none !px-3" aria-label="Review and submit" data-testid="review-top" onClick={onReview}>Review</button>
      </div>
      <div role="progressbar" aria-label="Items entered" aria-valuemin={0} aria-valuemax={total} aria-valuenow={entered} className="h-1.5 overflow-hidden rounded-full bg-hub-n-100">
        <div className="h-full rounded-full bg-hub-blue transition-[width] duration-200 motion-reduce:transition-none" style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}
