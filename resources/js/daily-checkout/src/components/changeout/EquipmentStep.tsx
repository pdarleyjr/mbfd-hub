import { useId, useLayoutEffect, useRef, useState, type MouseEvent } from 'react';
import { CheckCircle2, ChevronDown } from 'lucide-react';
import { CHANGEOUT_NOTE_MAX, equipmentCounts, leftLines, matchesSearch } from './changeoutLogic';
import EquipmentLineRow from './EquipmentLineRow';
import type { ChangeoutContext, EquipmentCompartment, EquipmentLine, ResolvedTruck } from './types';

interface Props {
  context: ChangeoutContext;
  truck: ResolvedTruck | null;
  /** The truck being left: what a Left item stays on. */
  fromVehicle: string | null;
  left: Record<string, string>;
  note: string;
  /** Shown when the list is not available and the member tried to continue without a note. */
  onToggle: (key: string) => void;
  onLineNote: (key: string, note: string) => void;
  onCompartment: (compartment: EquipmentCompartment, isLeft: boolean) => void;
  onNote: (note: string) => void;
}

const plural = (value: number, word: string): string => `${value} ${word}${value === 1 ? '' : 's'}`;

export default function EquipmentStep({ context, truck, fromVehicle, left, note, onToggle, onLineNote, onCompartment, onNote }: Props) {
  const prefix = useId();
  const [query, setQuery] = useState('');
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set());
  const [announcement, setAnnouncement] = useState('');
  const leftHeadingRef = useRef<HTMLHeadingElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const anchor = useRef<{ element: HTMLElement; top: number } | null>(null);

  // A new line in the Left list above would push the row out from under the thumb. iOS Safari does not hold it in
  // place on its own, so the row just tapped stays where it was.
  useLayoutEffect(() => {
    const held = anchor.current;
    anchor.current = null;
    if (!held || !held.element.isConnected) return;
    const shift = held.element.getBoundingClientRect().top - held.top;
    if (shift !== 0) window.scrollBy(0, shift);
  });

  const hold = (event: MouseEvent<HTMLElement>) => {
    anchor.current = { element: event.currentTarget, top: event.currentTarget.getBoundingClientRect().top };
  };

  if (!context.equipment.usable) {
    const noteId = `${prefix}-unusable-note`;

    return (
      <div className="space-y-4">
        <p className="hub-callout hub-callout--warning p-3 text-sm">
          The equipment list for {context.unit.designation} is not available right now. You can still finish the change.
        </p>
        <div>
          <label htmlFor={noteId} className="text-sm font-semibold text-hub-ink">What did you take, and what did you leave? (optional)</label>
          <textarea
            id={noteId}
            value={note}
            rows={4}
            maxLength={CHANGEOUT_NOTE_MAX}
            onChange={(event) => onNote(event.target.value)}
            className="mt-1 block w-full rounded-hub-control border border-hub-control-border bg-hub-well p-3 text-hub-ink"
          />
        </div>
      </div>
    );
  }

  const counts = equipmentCounts(context, left);
  const lefts = leftLines(context, left);
  const searching = query.trim() !== '';
  const compartments = context.equipment.compartments
    .map((compartment) => ({ whole: compartment, items: searching ? compartment.items.filter((line) => matchesSearch(line, query)) : compartment.items }))
    .filter(({ items }) => items.length > 0);
  const fromTruck = fromVehicle ?? 'not recorded';
  const toTruck = truck ? `to truck ${truck.vehicleNumber}` : 'with you';

  const toggle = (event: MouseEvent<HTMLElement>, line: EquipmentLine) => {
    hold(event);
    onToggle(line.item_key);
    setAnnouncement(line.item_key in left ? `${line.name}: Took.` : `${line.name}: Left on truck ${fromTruck}.`);
  };

  /** The Undo button goes away with its line, so focus lands somewhere the member can carry on from. */
  const undo = (line: EquipmentLine) => {
    onToggle(line.item_key);
    setAnnouncement(`${line.name}: Took.`);
    window.requestAnimationFrame(() => (leftHeadingRef.current ?? searchRef.current)?.focus());
  };

  const toggleCompartment = (id: string) => {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <div className="space-y-5">
      {counts.total > 0 && <div data-testid="changeout-took-all" className="hub-well flex items-start gap-3 !border-hub-blue !bg-hub-blue-tint p-4">
        <CheckCircle2 className="mt-0.5 h-6 w-6 flex-none text-hub-blue" aria-hidden="true" />
        <div className="min-w-0">
          <p className="text-lg font-semibold tabular-nums text-hub-ink">{counts.left === 0 ? `Took all ${plural(counts.total, 'item')}` : `Took ${counts.took} of ${plural(counts.total, 'item')}`}</p>
          <p className="mt-0.5 text-sm text-hub-n-800">Moved {toTruck}. Tap anything you left on truck {fromTruck}.</p>
        </div>
      </div>}

      {lefts.length > 0 && (
        <section aria-labelledby={`${prefix}-left`}>
          <h3 id={`${prefix}-left`} ref={leftHeadingRef} tabIndex={-1} className="mb-2 font-heading text-lg font-semibold tabular-nums text-hub-ink focus:outline-none">Left on truck {fromTruck} ({lefts.length})</h3>
          <ul data-testid="changeout-left-list" className="hub-well divide-y divide-hub-n-100 overflow-hidden">
            {lefts.map((line) => (
              <li key={line.item_key} className="flex items-center justify-between gap-3 px-3 py-1">
                <span className="min-w-0 text-sm">
                  <span className="block font-medium text-hub-ink">{line.name}</span>
                  {left[line.item_key].trim() !== '' && <span className="block text-hub-n-600">{left[line.item_key]}</span>}
                </span>
                <button type="button" aria-label={`Undo ${line.name}`} onClick={() => undo(line)} className="hub-btn hub-btn--tertiary !min-h-11 flex-none">Undo</button>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div>
        <label htmlFor={`${prefix}-search`} className="text-sm font-semibold text-hub-ink">Find an item</label>
        <input
          id={`${prefix}-search`}
          ref={searchRef}
          type="search"
          value={query}
          autoComplete="off"
          onChange={(event) => setQuery(event.target.value)}
          className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-hub-ink"
        />
      </div>

      {compartments.length === 0 && <p className="text-sm text-hub-n-600">{searching ? 'No equipment matches that search.' : 'No equipment is listed for this unit.'}</p>}

      <div className="space-y-3">
        {compartments.map(({ whole, items }) => {
          const isExpanded = searching || expanded.has(whole.id);
          const leftCount = whole.items.filter((line) => line.item_key in left).length;
          const allLeft = leftCount === whole.items.length;
          const panelId = `${prefix}-compartment-${whole.id}`;

          return (
            <section key={whole.id} className="hub-well overflow-hidden">
              <button
                type="button"
                aria-expanded={isExpanded}
                aria-controls={panelId}
                onClick={() => toggleCompartment(whole.id)}
                className="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-hub-n-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-hub-focus"
              >
                <span className="min-w-0 font-semibold text-hub-ink">{whole.name}</span>
                <span className="flex flex-none items-center gap-2 text-[13px] tabular-nums text-hub-n-600">
                  {plural(whole.items.length, 'item')}{leftCount > 0 ? `, ${leftCount} left` : ''}
                  <ChevronDown className={`h-5 w-5 text-hub-n-500 ${isExpanded ? 'rotate-180' : ''}`} aria-hidden="true" />
                </span>
              </button>
              {isExpanded && (
                <div id={panelId} className="border-t border-hub-n-100">
                  {!searching && (
                    <div className="px-3 py-2">
                      <button
                        type="button"
                        onClick={(event) => {
                          hold(event);
                          onCompartment(whole, !allLeft);
                          setAnnouncement(allLeft ? `Took everything in ${whole.name}.` : `Left everything in ${whole.name} on truck ${fromTruck}.`);
                        }}
                        className="hub-btn hub-btn--secondary hub-btn--wrap !min-h-11 w-full"
                      >
                        {allLeft ? 'Took this compartment' : 'Left this compartment'}
                      </button>
                    </div>
                  )}
                  <ul className="divide-y divide-hub-n-100">
                    {items.map((line) => (
                      <EquipmentLineRow
                        key={line.item_key}
                        line={line}
                        leftNote={line.item_key in left ? left[line.item_key] : null}
                        previousTruck={context.previous_changeout?.from_vehicle_number ?? null}
                        onToggle={(event) => toggle(event, line)}
                        onNote={(value) => onLineNote(line.item_key, value)}
                      />
                    ))}
                  </ul>
                </div>
              )}
            </section>
          );
        })}
      </div>

      <p role="status" className="sr-only">{announcement}</p>
    </div>
  );
}
