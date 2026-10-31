import { useId, useRef, useState } from 'react';
import { CheckCircle2, ChevronDown, ShieldCheck } from 'lucide-react';
import { CHANGEOUT_NOTE_MAX, controlledLines, effectiveChoice, equipmentCounts, isAttentionLine, lineStateLabel, matchesSearch, standardLines } from './changeoutLogic';
import EquipmentLineRow from './EquipmentLineRow';
import type { ChangeoutContext, EquipmentLine, LineChoice, ResolvedTruck } from './types';

interface Props {
  context: ChangeoutContext;
  truck: ResolvedTruck | null;
  exceptions: Record<string, LineChoice>;
  note: string;
  /** Shown when the list could not be loaded and the member tried to continue without a note. */
  noteMissing: boolean;
  onLine: (line: EquipmentLine, choice: LineChoice) => void;
  onNote: (note: string) => void;
}

type Origin = 'exceptions' | 'compartment';

const plural = (value: number, word: string): string => `${value} ${word}${value === 1 ? '' : 's'}`;

export default function EquipmentStep({ context, truck, exceptions, note, noteMissing, onLine, onNote }: Props) {
  const prefix = useId();
  const [query, setQuery] = useState('');
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set());
  const [open, setOpen] = useState<{ key: string; origin: Origin } | null>(null);
  const [announcement, setAnnouncement] = useState('');
  const headingRef = useRef<HTMLHeadingElement>(null);

  // On a handoff the unit has already moved: an item's issue belongs to the truck it was on.
  const fromVehicle = (context.mode === 'handoff_only' ? context.alert?.vehicle_number : null) ?? context.unit.vehicle_number;

  if (!context.equipment.usable) {
    const noteId = `${prefix}-unusable-note`;

    return (
      <div className="space-y-4">
        <p className="hub-callout hub-callout--warning p-3 text-sm">
          The equipment list for {context.unit.designation} could not be loaded. Fleet will see this. You can still continue: say what you moved in a short note.
        </p>
        <div>
          <label htmlFor={noteId} className="text-sm font-semibold text-hub-ink">What moved, and what stayed?</label>
          <textarea
            id={noteId}
            value={note}
            rows={4}
            maxLength={CHANGEOUT_NOTE_MAX}
            aria-invalid={noteMissing ? true : undefined}
            aria-describedby={noteMissing ? `${noteId}-error` : undefined}
            onChange={(event) => onNote(event.target.value)}
            className="mt-1 block w-full rounded-hub-control border border-hub-control-border bg-hub-well p-3 text-hub-ink"
          />
          {noteMissing && <p id={`${noteId}-error`} role="alert" className="mt-1 text-sm font-semibold text-hub-danger">Add a short note so Fleet knows what moved.</p>}
        </div>
      </div>
    );
  }

  const lines = standardLines(context);
  const controlled = controlledLines(context);
  const counts = equipmentCounts(context, exceptions);
  const attention = lines.filter((line) => isAttentionLine(line, exceptions));
  // A line being edited in its compartment joins Exceptions when the member finishes, so nothing jumps under their thumb.
  const exceptionRows = lines.filter((line) => (
    open?.key === line.item_key
      ? open.origin === 'exceptions'
      : isAttentionLine(line, exceptions)
  ));
  const searching = query.trim() !== '';
  const compartments = context.equipment.compartments
    .map((compartment) => ({
      ...compartment,
      items: compartment.items.filter((line) => !line.controlled && (!searching || matchesSearch(line, query))),
    }))
    .filter((compartment) => compartment.items.length > 0);

  const rowId = (origin: Origin, key: string): string => `${prefix}-${origin}-${key}`;

  const close = (line: EquipmentLine, origin: Origin) => {
    setOpen(null);
    setAnnouncement(isAttentionLine(line, exceptions) ? `${line.name}: ${lineStateLabel(line, effectiveChoice(line, exceptions))}. Listed under Exceptions.` : `${line.name} moves with the unit.`);
    window.requestAnimationFrame(() => {
      const row = document.getElementById(rowId(origin, line.item_key));
      if (row) row.focus();
      else headingRef.current?.focus();
    });
  };

  const row = (line: EquipmentLine, origin: Origin) => {
    const isOpen = open?.key === line.item_key && open.origin === origin;

    return (
      <EquipmentLineRow
        key={line.item_key}
        rowId={rowId(origin, line.item_key)}
        line={line}
        choice={effectiveChoice(line, exceptions)}
        exceptions={exceptions}
        open={isOpen}
        fromVehicle={fromVehicle}
        onToggle={() => (isOpen ? close(line, origin) : setOpen({ key: line.item_key, origin }))}
        onChange={(choice) => onLine(line, choice)}
        onClose={() => close(line, origin)}
      />
    );
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
      <div data-testid="changeout-transfer-all" className="hub-well flex items-start gap-3 !border-hub-blue !bg-hub-blue-tint p-4">
        <CheckCircle2 className="mt-0.5 h-6 w-6 flex-none text-hub-blue" aria-hidden="true" />
        <div className="min-w-0">
          <p className="font-semibold text-hub-ink">Transfer all equipment</p>
          <p className="mt-0.5 text-sm tabular-nums text-hub-n-800">
            {counts.moving} of {plural(counts.total, 'item')} moving{truck ? ` to truck ${truck.vehicleNumber}` : ''}.
            {attention.length > 0 ? ` ${plural(attention.length, 'exception')}.` : ' Only change what is different.'}
          </p>
        </div>
      </div>

      {controlled.length > 0 && (
        <section aria-labelledby={`${prefix}-controlled`} className="hub-well p-4">
          <h3 id={`${prefix}-controlled`} className="flex items-center gap-2 font-semibold text-hub-ink">
            <ShieldCheck className="h-5 w-5 flex-none text-hub-n-600" aria-hidden="true" />
            These need their own sign-off
          </h3>
          <ul className="mt-2 space-y-1 text-sm font-medium text-hub-n-800">
            {controlled.map((line) => <li key={line.item_key}>{line.name}</li>)}
          </ul>
          <p className="mt-2 text-sm text-hub-n-600">They will show as waiting for verification, so they cannot be marked as moved here.</p>
        </section>
      )}

      {exceptionRows.length > 0 && (
        <section aria-labelledby={`${prefix}-exceptions`}>
          <h3 id={`${prefix}-exceptions`} ref={headingRef} tabIndex={-1} className="mb-2 font-heading text-lg font-semibold text-hub-ink">Exceptions</h3>
          <ul data-testid="changeout-exceptions" className="hub-well divide-y divide-hub-n-100 overflow-hidden">
            {exceptionRows.map((line) => row(line, 'exceptions'))}
          </ul>
        </section>
      )}

      <div>
        <label htmlFor={`${prefix}-search`} className="text-sm font-semibold text-hub-ink">Search equipment</label>
        <input
          id={`${prefix}-search`}
          type="search"
          value={query}
          autoComplete="off"
          onChange={(event) => setQuery(event.target.value)}
          className="mt-1 block min-h-11 w-full rounded-hub-control border border-hub-control-border bg-hub-well px-3 py-2.5 text-hub-ink"
        />
      </div>

      {compartments.length === 0 && <p className="text-sm text-hub-n-600">{searching ? 'No equipment matches that search.' : 'No equipment is listed for this unit.'}</p>}

      <div className="space-y-3">
        {compartments.map((compartment) => {
          const isExpanded = searching || expanded.has(compartment.id);
          const changedCount = compartment.items.filter((line) => isAttentionLine(line, exceptions)).length;
          const panelId = `${prefix}-compartment-${compartment.id}`;

          return (
            <section key={compartment.id} className="hub-well overflow-hidden">
              <button
                type="button"
                aria-expanded={isExpanded}
                aria-controls={panelId}
                onClick={() => toggleCompartment(compartment.id)}
                className="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-hub-n-50"
              >
                <span className="min-w-0 font-semibold text-hub-ink">{compartment.name}</span>
                <span className="flex flex-none items-center gap-2 text-[13px] tabular-nums text-hub-n-600">
                  {plural(compartment.items.length, 'item')}{changedCount > 0 ? `, ${changedCount} changed` : ''}
                  <ChevronDown className={`h-5 w-5 text-hub-n-500 ${isExpanded ? 'rotate-180' : ''}`} aria-hidden="true" />
                </span>
              </button>
              {isExpanded && (
                <ul id={panelId} className="divide-y divide-hub-n-100 border-t border-hub-n-100">
                  {compartment.items.map((line) => row(line, 'compartment'))}
                </ul>
              )}
            </section>
          );
        })}
      </div>

      <p role="status" className="sr-only">{announcement}</p>
    </div>
  );
}
