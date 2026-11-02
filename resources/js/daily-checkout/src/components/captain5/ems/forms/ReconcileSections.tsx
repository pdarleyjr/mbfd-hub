import { TriangleAlert } from 'lucide-react';
import { HINT, NumberInput } from '../shared';
import ItemPicker from './ItemPicker';
import { onBooksText, takeText, type ItemTake, type LineInput, type Resolution } from './logic';
import type { MasterItem, PreviewForm } from './types';

export type InputPatch = (lineId: number, patch: Partial<LineInput>) => void;

const QTY_DIGITS = 6;

function lineName(resolution: Resolution): string {
  const { line } = resolution;
  return `${line.label}${line.variant_label ? ` (${line.variant_label})` : ''}`;
}

function requestedText(resolution: Resolution): string {
  const { line } = resolution;
  const unit = line.unit && line.unit.trim().toLowerCase() !== 'each' ? ` ${line.unit}` : '';
  return `${line.target_label} · asked for ${line.quantity}${unit}`;
}

const flagClass = (flagged: boolean) => (flagged ? 'border-hub-danger bg-hub-danger-bg' : 'border-hub-border bg-hub-well');

export function FormsList({ forms }: { forms: readonly PreviewForm[] }) {
  return (
    <ul className="space-y-1" aria-label="Forms being reconciled" data-testid="ems-reconcile-forms">
      {forms.map((form) => (
        <li key={form.id} className="break-words text-sm text-hub-ink">
          <strong>{form.target_label}</strong> <span className={HINT}>{form.submitter_name} · {form.number}</span>
        </li>
      ))}
    </ul>
  );
}

export function BlockedNotice({ forms, onLeaveOut, busy }: { forms: readonly PreviewForm[]; onLeaveOut: () => void; busy: boolean }) {
  const blocked = forms.filter((form) => !form.eligible);
  if (blocked.length === 0) return null;
  return (
    <div role="alert" data-testid="ems-reconcile-blocked" className="space-y-2 rounded-hub-control border border-hub-warning-border bg-hub-warning-bg p-3">
      <p className="font-semibold text-hub-ink">{blocked.length === 1 ? 'One form cannot be reconciled' : `${blocked.length} forms cannot be reconciled`}</p>
      <ul className="space-y-1 text-sm text-hub-ink">
        {blocked.map((form) => <li key={form.id}>{form.target_label} {form.number}: {form.ineligible_reason ?? 'It is not ready to reconcile.'}</li>)}
      </ul>
      <button type="button" className="hub-btn hub-btn--secondary !min-h-11" disabled={busy} onClick={onLeaveOut}>Leave {blocked.length === 1 ? 'it' : 'them'} out</button>
    </div>
  );
}

// ------------------------------------------------------------------ decisions

interface DecisionProps {
  resolution: Resolution;
  input: LineInput;
  master: readonly MasterItem[] | null;
  masterError: string | null;
  onRetryMaster: () => void;
  onInput: InputPatch;
  flagged: boolean;
  disabled: boolean;
}

function UnitsQuestion({ resolution, input, onInput, disabled, itemName }: { resolution: Resolution; input: LineInput; onInput: InputPatch; disabled: boolean; itemName: string | null }) {
  const { line } = resolution;
  return (
    <div className="space-y-1.5">
      <p className="text-sm text-hub-ink">This form counts in {line.unit ?? 'another unit'}{itemName ? `, and ${itemName} is counted differently` : ''}. How many units come off the shelf?</p>
      <NumberInput caption="Units off the shelf" label={`Units off the shelf for ${lineName(resolution)}, ${line.target_label}`} maxLength={QTY_DIGITS} disabled={disabled}
        value={input.units ?? ''} aria-invalid={resolution.problem ? true : undefined} onChange={(units) => onInput(line.line_id, { units })} />
      {resolution.problem && <p className="text-[13px] font-semibold text-hub-danger">{resolution.problem}</p>}
    </div>
  );
}

/** A line the server could not resolve alone: pick the item, or type the shelf units. */
export function DecisionRow({ resolution, input, master, masterError, onRetryMaster, onInput, flagged, disabled }: DecisionProps) {
  const { line } = resolution;
  const chosen = input.itemId != null ? (master ?? []).find((item) => item.id === input.itemId) ?? null : null;
  const needsItem = line.state === 'needs_item';
  return (
    <li data-testid="ems-reconcile-decision" data-line-id={line.line_id} data-resolved={resolution.resolved} className={`space-y-3 rounded-hub-control border p-3 ${flagClass(flagged)}`}>
      <div>
        <p className="break-words font-semibold text-hub-ink">{lineName(resolution)}</p>
        <p className={HINT}>{requestedText(resolution)}</p>
        {flagged && <p className="text-[13px] font-semibold text-hub-danger">Check this line.</p>}
      </div>
      {needsItem && (
        <ItemPicker lineLabel={lineName(resolution)} items={master} loadError={masterError} onRetryLoad={onRetryMaster} chosen={chosen} notStocked={input.notStocked === true}
          onPick={(item) => onInput(line.line_id, { itemId: item.id, notStocked: false })}
          onNotStocked={() => onInput(line.line_id, { itemId: null, notStocked: true })}
          onChange={() => onInput(line.line_id, { itemId: null, notStocked: false, units: undefined })} />
      )}
      {!needsItem && (
        <p className={HINT}>{line.item ? `${line.item.name} (${line.item.location_code ? `Shelf ${line.item.location_code}` : 'No shelf assigned'})` : ''}</p>
      )}
      {needsItem && chosen && !resolution.needsUnits && (
        <NumberInput caption="Taking" label={`Quantity taken for ${lineName(resolution)}, ${line.target_label}`} maxLength={QTY_DIGITS} disabled={disabled}
          value={resolution.quantityText} aria-invalid={resolution.problem ? true : undefined} onChange={(quantity) => onInput(line.line_id, { quantity })} />
      )}
      {resolution.needsUnits && <UnitsQuestion resolution={resolution} input={input} onInput={onInput} disabled={disabled} itemName={chosen?.name ?? null} />}
      {!resolution.needsUnits && resolution.problem && <p className="text-[13px] font-semibold text-hub-danger">{resolution.problem}</p>}
    </li>
  );
}

// ------------------------------------------------------------------ shelf items

function ShelfRow({ resolution, onInput, flagged, disabled }: { resolution: Resolution; onInput: InputPatch; flagged: boolean; disabled: boolean }) {
  const { line } = resolution;
  return (
    <li data-testid="ems-reconcile-line" data-line-id={line.line_id} className={`flex flex-wrap items-end justify-between gap-3 rounded-hub-control border p-2.5 ${flagClass(flagged)}`}>
      <div className="min-w-0 flex-1 basis-40">
        <p className="break-words text-sm font-semibold text-hub-ink">{lineName(resolution)}</p>
        <p className={HINT}>{requestedText(resolution)}</p>
        {resolution.problem && <p className="text-[13px] font-semibold text-hub-danger">{resolution.problem}</p>}
      </div>
      <NumberInput caption="Taking" label={`Quantity taken for ${lineName(resolution)}, ${line.target_label}`} maxLength={QTY_DIGITS} disabled={disabled}
        value={resolution.quantityText} aria-invalid={resolution.problem ? true : undefined} onChange={(quantity) => onInput(line.line_id, { quantity })} />
    </li>
  );
}

export function ItemCard({ take, rows, onInput, flaggedIds, disabled }: { take: ItemTake; rows: readonly Resolution[]; onInput: InputPatch; flaggedIds: ReadonlySet<number>; disabled: boolean }) {
  return (
    <li data-testid="ems-reconcile-item" data-item-id={take.itemId} className="space-y-2 rounded-hub-control border border-hub-border bg-hub-n-0 p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <div className="min-w-0">
          <p className="break-words font-semibold text-hub-ink">{take.name}</p>
          <p className={HINT}>{take.shelf ? `Shelf ${take.shelf}` : 'No shelf assigned'}</p>
        </div>
        <p className="font-heading text-lg font-bold tabular-nums text-hub-ink" data-testid="ems-reconcile-take">{takeText(take)}</p>
      </div>
      {take.low && take.afterBase !== null && take.beforeBase !== null && (
        <p className="flex items-start gap-1.5 text-sm font-semibold text-hub-danger" data-testid="ems-reconcile-low">
          <TriangleAlert className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" />
          <span>Now {onBooksText(take.beforeBase, take)}. This leaves {onBooksText(take.afterBase, take)} on the books. Monday&apos;s count will correct it.</span>
        </p>
      )}
      {take.notCounted && <p className={HINT}>Not counted yet. The next count sets this number.</p>}
      {rows.length > 0 && (
        <ul className="space-y-2" aria-label={`Lines for ${take.name}`}>
          {rows.map((row) => <ShelfRow key={row.line.line_id} resolution={row} onInput={onInput} flagged={flaggedIds.has(row.line.line_id)} disabled={disabled} />)}
        </ul>
      )}
    </li>
  );
}

export function QuietList({ rows }: { rows: readonly Resolution[] }) {
  if (rows.length === 0) return null;
  return (
    <section aria-labelledby="ems-quiet-heading" className="space-y-1.5" data-testid="ems-reconcile-quiet">
      <h3 id="ems-quiet-heading" className="font-heading text-base font-bold text-hub-ink">No stock change</h3>
      <ul className="space-y-1">
        {rows.map((row) => <li key={row.line.line_id} className="break-words text-sm text-hub-ink">{lineName(row)} <span className={HINT}>{row.line.target_label} · No stock change</span></li>)}
      </ul>
    </section>
  );
}
