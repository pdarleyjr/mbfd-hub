import { useId, useMemo, useState } from 'react';
import { BTN_SECONDARY, FIELD, HINT, LiveNote } from '../shared';
import { searchItems, shelfText } from './logic';
import type { MasterItem } from './types';

interface Props {
  /** What the line is called, for the search box label. */
  lineLabel: string;
  /** null while the list is loading. */
  items: readonly MasterItem[] | null;
  loadError: string | null;
  onRetryLoad: () => void;
  chosen: MasterItem | null;
  notStocked: boolean;
  onPick: (item: MasterItem) => void;
  onNotStocked: () => void;
  onChange: () => void;
}

/** Search the supply room by name, nickname or shelf, or say the line is not a supply room item. */
export default function ItemPicker({ lineLabel, items, loadError, onRetryLoad, chosen, notStocked, onPick, onNotStocked, onChange }: Props) {
  const id = useId();
  const [query, setQuery] = useState('');
  const results = useMemo(() => searchItems(items ?? [], query, 8), [items, query]);

  if (chosen || notStocked) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="min-w-0 break-words text-sm font-semibold text-hub-ink" data-testid="ems-picker-chosen">
          {notStocked ? 'Not a supply room item' : `Using ${chosen?.name} (${shelfText(chosen?.location_code ?? null)})`}
        </p>
        <button type="button" className={BTN_SECONDARY} onClick={onChange}>Change</button>
      </div>
    );
  }

  return (
    <div className="space-y-2" data-testid="ems-item-picker">
      <label className="block text-sm font-semibold text-hub-ink" htmlFor={`${id}-search`}>Which supply room item is {lineLabel}?</label>
      {loadError && (
        <div className="space-y-2">
          <LiveNote tone="warning">The supply room list could not be loaded. {loadError}</LiveNote>
          <button type="button" className={BTN_SECONDARY} onClick={onRetryLoad}>Try again</button>
        </div>
      )}
      {!loadError && items === null && <p className={HINT} role="status">Loading the supply room list…</p>}
      {!loadError && items !== null && (
        <>
          <input id={`${id}-search`} type="search" className={FIELD} value={query} autoComplete="off" maxLength={80}
            placeholder="Type a name, nickname or shelf" aria-describedby={`${id}-count`} onChange={(event) => setQuery(event.target.value)} />
          <p id={`${id}-count`} className={HINT} role="status">
            {query.trim() === '' ? 'Type to search.' : results.length === 0 ? 'No supply room item matches.' : `${results.length} ${results.length === 1 ? 'match' : 'matches'}.`}
          </p>
          {results.length > 0 && (
            <ul className="space-y-1.5" aria-label={`Supply room items for ${lineLabel}`}>
              {results.map((item) => (
                <li key={item.id}>
                  <button type="button" data-testid="ems-picker-result" onClick={() => onPick(item)}
                    className="flex min-h-11 w-full flex-col items-start rounded-hub-control border border-hub-border bg-hub-n-0 px-3 py-2 text-left hover:bg-hub-blue-tint">
                    <span className="break-words font-semibold text-hub-ink">{item.name}</span>
                    <span className={HINT}>{shelfText(item.location_code)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
      <button type="button" data-testid="ems-picker-none" className={BTN_SECONDARY} onClick={onNotStocked}>Not a supply room item</button>
    </div>
  );
}
