import { Pencil } from 'lucide-react';
import OnHandCell from './OnHandCell';
import { shelfName } from './logic';
import type { MasterItem } from './types';

interface Props {
  items: readonly MasterItem[];
  caption: string;
  canEdit: boolean;
  /** Search results show the shelf as a link that jumps to that shelf. */
  shelfLinks?: boolean;
  hrefFor?: (code: string | null) => string;
  highlightId?: number | null;
  onEdit: (item: MasterItem) => void;
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
 * Item | Shelf | On hand | Edit, and nothing else. The table is fixed-layout so it fits the screen at any width:
 * names wrap, and on phones the shelf column folds in under the item name instead of scrolling sideways.
 */
export default function MasterTable({ items, caption, canEdit, shelfLinks = false, hrefFor, highlightId = null, onEdit, onGoToShelf }: Props) {
  return (
    <div className="overflow-hidden rounded-hub-card border border-hub-glass-border bg-hub-well">
      <table className="w-full table-fixed border-collapse" data-testid="master-table">
        <caption className="sr-only">{caption}</caption>
        <colgroup>
          <col />
          <col className="hidden w-24 sm:table-column" />
          <col className="w-[6.75rem] sm:w-36" />
          {canEdit && <col className="w-[3.25rem] sm:w-24" />}
        </colgroup>
        <thead className="border-b border-hub-border bg-hub-n-25">
          <tr>
            <th scope="col" className={HEAD}>Item</th>
            <th scope="col" className={`${HEAD} hidden sm:table-cell`}>Shelf</th>
            <th scope="col" className={HEAD}>On hand</th>
            {canEdit && <th scope="col" className={HEAD}>Edit</th>}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id} id={`master-row-${item.id}`} data-testid="master-row" data-item-id={item.id} data-highlight={highlightId === item.id ? 'true' : undefined}
              className={`border-b border-hub-border-soft last:border-b-0 ${highlightId === item.id ? 'bg-hub-blue-tint' : ''}`}>
              <td className={`${CELL} [overflow-wrap:anywhere]`}>
                <span className="block font-semibold leading-snug text-hub-ink" data-testid="item-name">{item.name}</span>
                <span className="block text-[13px] leading-5 text-hub-n-600 sm:hidden" data-testid="item-shelf-inline">
                  <ShelfCell item={item} link={shelfLinks} href={hrefFor?.(item.location_code)} onGo={() => onGoToShelf?.(item)} />
                </span>
              </td>
              <td className={`${CELL} hidden text-sm text-hub-ink sm:table-cell`} data-testid="item-shelf">
                <ShelfCell item={item} link={shelfLinks} href={hrefFor?.(item.location_code)} onGo={() => onGoToShelf?.(item)} />
              </td>
              <td className={CELL}><OnHandCell item={item} /></td>
              {canEdit && (
                <td className={`${CELL} !px-1 sm:!px-3`}>
                  <button type="button" className="hub-btn hub-btn--secondary !min-h-11 !min-w-11 gap-1.5 !px-2 sm:!px-3" aria-label={`Edit ${item.name}`}
                    data-testid="edit-item" onClick={() => onEdit(item)}>
                    <Pencil className="h-4 w-4 flex-none" aria-hidden="true" />
                    <span className="hidden sm:inline">Edit</span>
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
