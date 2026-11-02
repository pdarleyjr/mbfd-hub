import { useMemo, useState } from 'react';
import InventorySearch from './InventorySearch';
import { buildSearchIndex, normalizeText, searchItems, shelfName } from './logic';
import type { EntryMap, MasterItem } from './types';

interface Props {
  items: readonly MasterItem[];
  entries: Readonly<EntryMap>;
  onPick: (item: MasterItem) => void;
}

const SHOWN = 8;

/** Find any item on any shelf: picking one jumps to its shelf and puts the cursor in its number box. */
export default function CountSearch({ items, entries, onPick }: Props) {
  const [query, setQuery] = useState('');
  const index = useMemo(() => buildSearchIndex(items), [items]);
  const results = useMemo(() => searchItems(index, query, SHOWN + 1), [index, query]);
  const searching = normalizeText(query) !== '';

  return (
    <div className="space-y-2 rounded-hub-control border border-hub-border bg-hub-well p-3" data-testid="count-search">
      <InventorySearch id="count-search-input" label="Find an item on any shelf" value={query} onChange={setQuery}
        resultNote={searching ? `${results.length} ${results.length === 1 ? 'item matches' : 'items match'}` : ''} />
      {searching && results.length === 0 && <p className="text-sm text-hub-n-600">Nothing matches that. Try fewer words.</p>}
      {results.length > 0 && (
        <ul className="divide-y divide-hub-border-soft">
          {results.slice(0, SHOWN).map((item) => (
            <li key={item.id}>
              <button type="button" className="flex min-h-11 w-full items-center justify-between gap-3 py-1.5 text-left" data-testid="count-search-result"
                onClick={() => { onPick(item); setQuery(''); }}>
                <span className="min-w-0 font-semibold text-hub-ink [overflow-wrap:anywhere]">{item.name}</span>
                <span className="flex-none text-[13px] text-hub-n-600">{shelfName(item)}{String(item.id) in entries ? `, entered ${entries[String(item.id)]}` : ''}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {results.length > SHOWN && <p className="text-[13px] text-hub-n-600">More items match. Type more to narrow it down.</p>}
    </div>
  );
}
