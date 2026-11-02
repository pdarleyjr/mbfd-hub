import type { RefObject } from 'react';
import { AlertTriangle, Search, X } from 'lucide-react';

/**
 * "Search supplies": stays at the top of the screen while the member scrolls, with a clear button, the result count,
 * and an "Entered only" switch that shows just what they have entered so far.
 */
export function ItemSearch({ inputRef, query, onQuery, searching, shownQuery, matches, enteredOnly, onEnteredOnly, enteredCount, error }: {
    inputRef: RefObject<HTMLInputElement | null>;
    query: string;
    onQuery: (value: string) => void;
    /** True once the (deferred) query has words, so the count describes what is on screen. */
    searching: boolean;
    shownQuery: string;
    matches: number;
    enteredOnly: boolean;
    onEnteredOnly: (value: boolean) => void;
    enteredCount: number;
    error?: string;
}) {
    let status = '';
    if (searching) status = matches === 0 ? `No items match "${shownQuery.trim()}". Clear the search to see the full list.` : `${matches} matching ${matches === 1 ? 'item' : 'items'}${enteredOnly ? ' entered' : ''}`;
    else if (enteredOnly) status = enteredCount === 0 ? 'Nothing entered yet.' : `${enteredCount} ${enteredCount === 1 ? 'item' : 'items'} entered`;

    return <section className="ems-card ems-searchbar" aria-label="Find an item">
        <label htmlFor="ems-search" className="sr-only">Search supplies</label>
        <div className="ems-search-line">
            <div className="ems-search">
                <Search size={18} aria-hidden="true" />
                <input
                    id="ems-search" ref={inputRef} type="search" className="ems-search-input" autoComplete="off" autoCapitalize="off" spellCheck={false}
                    enterKeyHint="search" placeholder={enteredCount > 0 || enteredOnly ? 'Search' : 'Search supplies'} value={query} onChange={(event) => onQuery(event.target.value)}
                />
                {query && <button type="button" className="ems-search-clear" aria-label="Clear search" onClick={() => { onQuery(''); inputRef.current?.focus(); }}><X size={17} aria-hidden="true" /></button>}
            </div>
            {(enteredCount > 0 || enteredOnly) && <button type="button" className="ems-toggle" aria-pressed={enteredOnly} onClick={() => onEnteredOnly(!enteredOnly)}>
                Entered only <span className="ems-toggle-count">{enteredCount}</span>
            </button>}
        </div>
        <p className="ems-search-count" role="status" aria-live="polite">{status}</p>
        {error && <p className="ems-field-error" role="alert"><AlertTriangle size={15} aria-hidden="true" /> {error}</p>}
    </section>;
}
