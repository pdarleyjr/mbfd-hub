import { memo, useId, type ReactNode } from 'react';
import Tag from '../../../ui/Tag';
import { BTN, ErrorText, FIELD, HINT } from '../shared';
import {
  linePreset, liveLevel, liveToOrder, rowChanged, shownExpected, shownLine, shownOnHand, withCounting, withLine, withNumber,
  type LineField, type RowDraft, type RowErrors,
} from './batchLogic';
import { MAX_NAME, MAX_SHELF_LABEL, NEW_SHELF, levelText, wholeText } from './editLogic';
import { ShelfOptions, UnitOptions } from './ItemFields';
import LevelTag, { LEVEL_ACCENT, LEVEL_ROW } from './StockLevelTag';
import { orderBadge, stateShort, unitOption, unitPlural } from './orderLogic';
import type { CountUnit, MasterItem, MasterSection, UnitOption } from './types';

interface RowProps {
  item: MasterItem;
  draft: RowDraft;
  errors: RowErrors;
  sections: readonly MasterSection[];
  units: readonly UnitOption[];
  disabled: boolean;
  onChange: (item: MasterItem, next: RowDraft) => void;
  onUndo: (item: MasterItem) => void;
  onOpen: (item: MasterItem) => void;
}

/** Shown when what to order cannot be worked out yet. */
export const NO_TO_ORDER = 'No expected amount or count yet';

/** Grid columns at wide widths. Below that each row is a stacked card. */
const GRID = 'xl:grid-cols-[minmax(9rem,1fr)_9rem_10.5rem_6.25rem_6.25rem_8.75rem_6.5rem_8.5rem]';
const LABEL = 'mb-1 block text-[13px] font-semibold leading-5 text-hub-n-600 xl:sr-only';

function Cell({ label, error, className = '', children }: { label: string; error?: string; className?: string; children: (props: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string }) => ReactNode }) {
  const id = useId();
  return (
    <div className={`min-w-0 ${className}`}>
      <label htmlFor={id} className={LABEL}>{label}</label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': error ? `${id}-error` : undefined })}
      <ErrorText id={`${id}-error`}>{error}</ErrorText>
    </div>
  );
}

const NUMBER = `${FIELD} tabular-nums`;

const LINE_LABEL: Readonly<Record<LineField, string>> = { reorder: 'Yellow at', critical: 'Red at' };

/**
 * "Yellow at" and "Red at", side by side. A blank box is the preset (half and a quarter of the expected amount): the
 * preset shows as the placeholder with "Auto" under it, and clearing a box puts the line back to Auto.
 */
function LineBoxes({ item, draft, errors, plural, disabled, onChange }: Pick<RowProps, 'item' | 'draft' | 'errors' | 'disabled' | 'onChange'> & { plural: string }) {
  return (
    <div className="col-span-2 grid grid-cols-2 gap-x-3 xl:col-span-1 xl:gap-x-1.5">
      {(['reorder', 'critical'] as const).map((field) => {
        const shown = shownLine(item, draft, field);
        const preset = linePreset(item, draft, field);
        return (
          <Cell key={field} label={`${LINE_LABEL[field]} (${plural})`} error={errors[field]}>
            {(aria) => (
              <>
                <input {...aria} type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" className={`${NUMBER} !px-2`} value={shown} disabled={disabled}
                  placeholder={preset === null ? '' : String(preset)} data-testid={`edit-${field}`}
                  onChange={(event) => onChange(item, withLine(draft, field, wholeText(event.target.value)))} />
                {shown.trim() === '' && <p className="mt-0.5 text-[12px] leading-4 text-hub-n-600" data-testid={`edit-${field}-auto`}>{preset === null ? 'Auto' : `Auto: ${preset}`}</p>}
              </>
            )}
          </Cell>
        );
      })}
    </div>
  );
}

/** One item in Edit mode: every field editable, with what to order worked out live from the boxes. */
export const EditRow = memo(function EditRow({ item, draft, errors, sections, units, disabled, onChange, onUndo, onOpen }: RowProps) {
  const set = (patch: Partial<RowDraft>) => onChange(item, { ...draft, ...patch });
  // A unit or size change clears numbers typed in the old unit; they are never carried into the new one.
  const setCounting = (patch: { countUnit?: CountUnit; perPackage?: string }) => onChange(item, withCounting(draft, patch));
  const plural = unitPlural(units, draft.countUnit);
  const word = unitOption(units, draft.countUnit).label;
  const toOrder = liveToOrder(item, draft);
  const toOrderText = toOrder === null ? NO_TO_ORDER : toOrder === 0 ? 'Nothing' : `${toOrder} ${draft.countUnit === 'each' ? '' : toOrder === 1 ? word : plural}`.trim();
  const badge = orderBadge(item);
  const flagged = Object.keys(errors).length > 0;
  const changed = rowChanged(item, draft);
  const savedOnHand = shownOnHand(item, { ...draft, onHandTouched: false });
  const savedExpected = shownExpected(item, { ...draft, expectedTouched: false });
  // Recoloured live from the boxes: the on hand, expected amount and lines typed in this row.
  const level = liveLevel(item, draft);
  const tint = flagged ? 'bg-hub-danger-bg/40' : level ? LEVEL_ROW[level] : '';

  return (
    <li data-testid="edit-row" data-item-id={item.id} data-invalid={flagged ? 'true' : undefined} data-level={level ?? 'none'}
      className={`border-b border-hub-border-soft last:border-b-0 ${tint} ${level ? LEVEL_ACCENT[level] : ''}`}>
      <div role="group" aria-label={`Edit ${item.name}`} className={`grid grid-cols-2 gap-x-3 gap-y-3 p-3 ${GRID} xl:items-start xl:gap-x-2 xl:gap-y-0 xl:px-3 xl:py-2`}>
        <Cell label="Name" error={errors.name} className="col-span-2 xl:col-span-1">
          {(aria) => (
            <>
              <input {...aria} className={FIELD} value={draft.name} maxLength={MAX_NAME} disabled={disabled} autoComplete="off" data-testid="edit-name"
                onChange={(event) => set({ name: event.target.value })} />
              <LevelTag level={level} testId="edit-level" className="mt-1" />
            </>
          )}
        </Cell>

        <div className="col-span-2 space-y-2 sm:col-span-1 xl:col-span-1">
          <Cell label="Shelf" error={errors.shelf}>
            {(aria) => (
              <select {...aria} className={FIELD} value={draft.shelf} disabled={disabled} data-testid="edit-shelf" onChange={(event) => set({ shelf: event.target.value })}>
                <ShelfOptions sections={sections} />
              </select>
            )}
          </Cell>
          {draft.shelf === NEW_SHELF && (
            <Cell label="New shelf name">
              {(aria) => <input {...aria} className={FIELD} value={draft.newShelf} maxLength={MAX_SHELF_LABEL} disabled={disabled} autoComplete="off" data-testid="edit-new-shelf"
                onChange={(event) => set({ newShelf: event.target.value })} />}
            </Cell>
          )}
        </div>

        <div className="col-span-2 space-y-2 sm:col-span-1 xl:col-span-1">
          <Cell label="Count by" error={errors.countUnit}>
            {(aria) => (
              <select {...aria} className={FIELD} value={draft.countUnit} disabled={disabled} data-testid="edit-unit"
                onChange={(event) => setCounting({ countUnit: event.target.value as CountUnit })}>
                <UnitOptions units={units} />
              </select>
            )}
          </Cell>
          {draft.countUnit !== 'each' && (
            <Cell label={`Single units in one ${word}`} error={errors.perPackage}>
              {(aria) => <input {...aria} type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" className={NUMBER} value={draft.perPackage} disabled={disabled}
                data-testid="edit-size" onChange={(event) => setCounting({ perPackage: wholeText(event.target.value) })} />}
            </Cell>
          )}
        </div>

        <Cell label={`On hand (${plural})`} error={errors.onHand}>
          {(aria) => <input {...aria} type="text" inputMode="numeric" autoComplete="off" className={NUMBER} value={shownOnHand(item, draft)} disabled={disabled}
            placeholder={savedOnHand === '' ? 'Not counted' : savedOnHand} data-testid="edit-on-hand"
            onChange={(event) => onChange(item, withNumber(draft, 'onHand', levelText(event.target.value)))} />}
        </Cell>

        <Cell label={`Expected (${plural})`} error={errors.expected}>
          {(aria) => <input {...aria} type="text" inputMode="numeric" autoComplete="off" className={NUMBER} value={shownExpected(item, draft)} disabled={disabled}
            placeholder={savedExpected === '' ? 'Not set' : savedExpected} data-testid="edit-expected"
            onChange={(event) => onChange(item, withNumber(draft, 'expected', levelText(event.target.value)))} />}
        </Cell>

        <LineBoxes item={item} draft={draft} errors={errors} plural={plural} disabled={disabled} onChange={onChange} />

        <div className="min-w-0">
          <p className={LABEL}>To order</p>
          <p className={`flex min-h-11 items-center text-sm font-semibold ${toOrder ? 'text-hub-danger' : 'text-hub-n-600'}`} data-testid="edit-to-order">{toOrderText}</p>
        </div>

        <div className="min-w-0">
          <p className={LABEL}>Order</p>
          <button type="button" className="inline-flex min-h-11 max-w-full items-center text-left disabled:opacity-60" disabled={disabled} data-testid="edit-state" aria-haspopup="dialog"
            aria-label={`Order details for ${item.name}: ${stateShort(item)}`} onClick={() => onOpen(item)}>
            <Tag tone={badge?.tone ?? 'neutral'} className="!whitespace-normal max-w-full underline decoration-1 underline-offset-2 [overflow-wrap:anywhere]">{stateShort(item)}</Tag>
          </button>
        </div>
        {errors.row && <p role="alert" className="col-span-2 text-[13px] font-semibold text-hub-danger xl:col-span-8">{errors.row}</p>}
        {(changed || draft.numbersReset) && (
          <div className="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 xl:col-span-8 xl:pt-2">
            {draft.numbersReset && (
              <p className={`${HINT} min-w-0 flex-1`} data-testid="edit-numbers-reset">
                The count unit changed, so the numbers you typed were cleared. Type them again in {plural} if you counted it.
              </p>
            )}
            {changed && (
              <button type="button" className={`${BTN} hub-btn--tertiary ml-auto`} disabled={disabled} data-testid="edit-undo" onClick={() => onUndo(item)}>
                Undo<span className="sr-only"> changes to {item.name}</span>
              </button>
            )}
          </div>
        )}
      </div>
    </li>
  );
});

interface ListProps extends Omit<RowProps, 'item' | 'draft' | 'errors'> {
  items: readonly MasterItem[];
  caption: string;
  draftFor: (item: MasterItem) => RowDraft;
  errorsFor: (item: MasterItem) => RowErrors;
}

const HEAD = 'px-0 text-[13px] font-semibold text-hub-n-600';

/** The rows in Edit mode, with column headings at wide widths. */
export function EditList({ items, caption, draftFor, errorsFor, ...rest }: ListProps) {
  return (
    <div className="overflow-hidden rounded-hub-card border border-hub-glass-border bg-hub-well" data-testid="edit-list">
      <div aria-hidden="true" className={`hidden border-b border-hub-border bg-hub-n-25 px-3 py-2 xl:grid xl:gap-x-2 ${GRID}`}>
        {['Item', 'Shelf', 'Count by', 'On hand', 'Expected', 'Yellow at · Red at', 'To order', 'Order'].map((label) => <span key={label} className={HEAD}>{label}</span>)}
      </div>
      <ul aria-label={caption}>
        {items.map((item) => <EditRow key={item.id} item={item} draft={draftFor(item)} errors={errorsFor(item)} {...rest} />)}
      </ul>
    </div>
  );
}
