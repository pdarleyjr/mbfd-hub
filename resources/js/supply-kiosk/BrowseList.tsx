// The entire supply room: one tab per location (bootstrap order), A to Z inside, a letter strip to jump.
import { useMemo, useState } from 'react';
import { ItemRow } from './ItemRow.tsx';
import type { Location, SupplyItem } from './types.ts';

interface Props {
    items: SupplyItem[];
    locations: Location[];
    cartCounts: ReadonlyMap<number, number>;
    onPick: (item: SupplyItem) => void;
}

interface Shelf { code: string; label: string; items: SupplyItem[] }

const OTHER = '__other__';
const letterOf = (name: string) => {
    const first = name.trim().charAt(0).toUpperCase();
    return /[A-Z]/.test(first) ? first : '#';
};

function shelves(items: SupplyItem[], locations: Location[]): Shelf[] {
    const ordered = [...locations].sort((a, b) => a.sort - b.sort);
    const known = new Set(ordered.map(location => location.code));
    const byName = (a: SupplyItem, b: SupplyItem) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
    const list: Shelf[] = ordered
        .map(location => ({ code: location.code, label: location.label, items: items.filter(item => item.location_code === location.code).sort(byName) }))
        .filter(shelf => shelf.items.length > 0);
    const rest = items.filter(item => !item.location_code || !known.has(item.location_code)).sort(byName);
    if (rest.length > 0) list.push({ code: OTHER, label: 'Other', items: rest });
    return list;
}

export function BrowseList({ items, locations, cartCounts, onPick }: Props) {
    const all = useMemo(() => shelves(items, locations), [items, locations]);
    const [chosen, setChosen] = useState<string | null>(null);
    const shelf = all.find(candidate => candidate.code === chosen) ?? all[0];
    if (!shelf) return <p className="k-empty">The supply room list is empty. Tell Captain 5.</p>;

    const firstOf = new Map<string, number>();
    shelf.items.forEach(item => { const letter = letterOf(item.name); if (!firstOf.has(letter)) firstOf.set(letter, item.id); });
    const anchor = (letter: string) => `k-at-${shelf.code}-${letter}`.replace(/[^A-Za-z0-9_-]/g, '_');
    const jump = (letter: string) => {
        document.getElementById(anchor(letter))?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    };

    return (
        <div className="k-browse">
            <div className="k-tabs" role="group" aria-label="Location">
                {all.map(candidate => (
                    <button key={candidate.code} type="button" className="k-tab" aria-pressed={candidate.code === shelf.code}
                        onClick={() => { setChosen(candidate.code); window.scrollTo({ top: 0 }); }}>
                        {candidate.label}
                    </button>
                ))}
            </div>
            {firstOf.size > 1 ? (
                <nav className="k-letters" aria-label="Jump to letter">
                    {[...firstOf.keys()].map(letter => (
                        <button key={letter} type="button" className="k-letter" onClick={() => jump(letter)} aria-label={`Jump to ${letter}`}>{letter}</button>
                    ))}
                </nav>
            ) : null}
            <ul className="k-list" aria-label={`Items on ${shelf.label}`}>
                {shelf.items.map(item => (
                    <ItemRow key={item.id} item={item} inCart={cartCounts.get(item.id) ?? 0} onPick={onPick}
                        anchorId={firstOf.get(letterOf(item.name)) === item.id ? anchor(letterOf(item.name)) : undefined} />
                ))}
            </ul>
        </div>
    );
}
