import OnHandCell from './OnHandCell';
import LevelTag, { LEVEL_ACCENT, LEVEL_ROW } from './StockLevelTag';
import { shelfName } from './logic';
import type { MasterItem } from './types';

interface Props {
  items: readonly MasterItem[];
  caption: string;
  /** Search and filter results show the shelf as a link that jumps to that shelf. */
  shelfLinks?: boolean;
  hrefFor?: (code: string | null) => string;
  highlightId?: number | null;
  onOpen: (item: MasterItem) => void;
  onGoToShelf?: (item: MasterItem) => void;
}

const HEAD = 'px-2 py-2 text-left text-[13px] font-semibold text-hub-n-600 sm:px-3';
const CELL = 'px-2 py-3 align-top sm:px-3';

function ShelfCell({ item, link, href, onGo }: { item: MasterItem; link: boolean; href?: string; onGo?: () => void }) {
  const label = shelfName(item);
  if (!link) return <>{label}</>;
  return (
    <a href={href ?? '#'} data-testid="shelf-link" title={item.location_label ?? undefined}
      className="inline-flex min-h-11 min-w-11 items-center font-semibold text-hub-blue-strong underline underline-offset-2"
      onClick={(event) => { if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey) { event.preventDefault(); onGo?.(); } }}>
      <span className="sr-only">Go to shelf </span>{label}
    </a>
  );
}

/**
 * Item | Shelf | On hand, and nothing else. The table is fixed-layout so it fits the screen at any width: names wrap,
 * and on phones the shelf column folds in under the item name instead of scrolling sideways. The item name opens the
 * item sheet with the expected amount, what to order and the order actions.
 */
export default function MasterTable({ items, caption, shelfLinks = false, hrefFor, highlightId = null, onOpen, onGoToShelf }: Props) {
  return (
    <div className="overflow-hidden rounded-hub-card border border-hub-glass-border bg-hub-well">
      <table className="w-full table-fixed border-collapse" data-testid="master-table">
        <caption className="sr-only">{caption}</caption>
        <colgroup>
          <col />
          <col className="hidden w-24 sm:table-column" />
          <col className="w-[7.5rem] sm:w-44" />
        </colgroup>
        <thead className="border-b border-hub-border bg-hub-n-25">
          <tr>
            <th scope="col" className={HEAD}>Item</th>
            <th scope="col" className={`${HEAD} hidden sm:table-cell`}>Shelf</th>
            <th scope="col" className={HEAD}>On hand</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id} id={`master-row-${item.id}`} data-testid="master-row" data-item-id={item.id} data-state={item.order_state}
              data-highlight={highlightId === item.id ? 'true' : undefined} data-level={item.level ?? 'none'}
              className={`border-b border-hub-border-soft last:border-b-0 ${highlightId === item.id ? 'bg-hub-blue-tint' : item.level ? LEVEL_ROW[item.level] : ''}`}>
              <td className={`${CELL} !py-1.5 [overflow-wrap:anywhere] ${item.level ? LEVEL_ACCENT[item.level] : ''}`}>
                <button type="button" data-testid="open-item" aria-haspopup="dialog" onClick={() => onOpen(item)}
                  className="flex min-h-11 w-full items-center rounded-hub-control text-left font-semibold leading-snug text-hub-ink underline decoration-hub-border decoration-1 underline-offset-4 hover:decoration-hub-ink">
                  <span data-testid="item-name">{item.name}</span>
                </button>
                <LevelTag level={item.level} className="mb-1" />
                <span className="block text-[13px] leading-5 text-hub-n-600 sm:hidden" data-testid="item-shelf-inline">
                  <ShelfCell item={item} link={shelfLinks} href={hrefFor?.(item.location_code)} onGo={() => onGoToShelf?.(item)} />
                </span>
              </td>
              <td className={`${CELL} hidden text-sm text-hub-ink sm:table-cell`} data-testid="item-shelf">
                <ShelfCell item={item} link={shelfLinks} href={hrefFor?.(item.location_code)} onGo={() => onGoToShelf?.(item)} />
              </td>
              <td className={CELL}><OnHandCell item={item} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
