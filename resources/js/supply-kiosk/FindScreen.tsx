// Find items: search (results on every keystroke) or the entire supply room.
import { useDeferredValue, useMemo } from 'react';
import type { RefObject } from 'react';
import { BrowseList } from './BrowseList.tsx';
import { ItemRow } from './ItemRow.tsx';
import { searchItems } from './search.ts';
import type { SearchIndex } from './search.ts';
import type { Location, SupplyItem } from './types.ts';

export type FindMode = 'search' | 'browse';

interface Props {
    mode: FindMode;
    query: string;
    inputRef: RefObject<HTMLInputElement | null>;
    index: SearchIndex<SupplyItem>;
    items: SupplyItem[];
    mostTaken: SupplyItem[];
    locations: Location[];
    cartCounts: ReadonlyMap<number, number>;
    onMode: (mode: FindMode) => void;
    onQuery: (query: string) => void;
    onPick: (item: SupplyItem, fromSearch: boolean) => void;
    onNote: () => void;
}

const RESULT_LIMIT = 40;

export function FindScreen(props: Props) {
    const { mode, query, inputRef, index, items, mostTaken, locations, cartCounts, onMode, onQuery, onPick, onNote } = props;
    const deferred = useDeferredValue(query);
    const results = useMemo(() => searchItems(index, deferred, RESULT_LIMIT), [index, deferred]);
    const typing = query.trim() !== '';
    const pickSearch = (item: SupplyItem) => onPick(item, true);
    const pickOther = (item: SupplyItem) => onPick(item, false);

    return (
        <section className="k-screen k-find" aria-label="Find items">
            <div className="k-segment" role="group" aria-label="How to find items">
                <button type="button" aria-pressed={mode === 'search'} onClick={() => onMode('search')}>Search</button>
                <button type="button" aria-pressed={mode === 'browse'} onClick={() => onMode('browse')}>Entire supply room</button>
            </div>

            {mode === 'search' ? (
                <>
                    <form className="k-search" role="search" onSubmit={event => { event.preventDefault(); if (typing && results[0]) pickSearch(results[0]); }}>
                        <label htmlFor="k-search-input" className="k-search-label">Search for an item</label>
                        <div className="k-search-box">
                            <input id="k-search-input" ref={inputRef} type="search" inputMode="search" enterKeyHint="search"
                                autoCapitalize="off" autoCorrect="off" autoComplete="off" spellCheck={false}
                                placeholder="Name, size or what it is for" value={query} onChange={event => onQuery(event.target.value)} />
                            {query !== '' ? (
                                <button type="button" className="k-search-clear" aria-label="Clear search" onClick={() => { onQuery(''); inputRef.current?.focus(); }}>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" /></svg>
                                </button>
                            ) : null}
                        </div>
                    </form>
                    {typing ? (
                        results.length > 0 ? (
                            <ul className="k-list" aria-label="Search results">
                                {results.map(item => <ItemRow key={item.id} item={item} inCart={cartCounts.get(item.id) ?? 0} onPick={pickSearch} />)}
                            </ul>
                        ) : (
                            <div className="k-empty">
                                <p>Nothing matches &ldquo;{query.trim()}&rdquo;.</p>
                                <p>Try another word, or look through the entire supply room.</p>
                                <button type="button" className="k-secondary" onClick={() => onMode('browse')}>View entire supply room</button>
                            </div>
                        )
                    ) : mostTaken.length > 0 ? (
                        <>
                            <h2 className="k-heading">Most taken</h2>
                            <ul className="k-list" aria-label="Most taken">
                                {mostTaken.map(item => <ItemRow key={item.id} item={item} inCart={cartCounts.get(item.id) ?? 0} onPick={pickOther} />)}
                            </ul>
                        </>
                    ) : null}
                </>
            ) : (
                <BrowseList items={items} locations={locations} cartCounts={cartCounts} onPick={pickOther} />
            )}

            <button type="button" className="k-note-link" onClick={onNote}>Can&rsquo;t find it? Tell Captain 5 what you needed</button>
        </section>
    );
}
