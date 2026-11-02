import { Field, FIELD, HINT } from '../shared';
import { MAX_NAME, MAX_SHELF_LABEL, NEW_SHELF, capitalize, levelText, wholeText, type ItemForm } from './editLogic';
import { DEFAULT_UNITS, unitOption, unitPlural } from './orderLogic';
import type { CountUnit, MasterSection, UnitOption } from './types';

interface BaseProps {
  form: ItemForm;
  onChange: (next: ItemForm) => void;
  errors: Record<string, string>;
  disabled: boolean;
}

/** The shelf choices shared by Add item and Edit mode: no shelf, every named shelf, and a new one. */
export function ShelfOptions({ sections }: { sections: readonly MasterSection[] }) {
  return (
    <>
      <option value="">No shelf assigned</option>
      {sections.filter((section) => section.code !== null).map((section) => <option key={section.code} value={section.code ?? ''}>{section.label}</option>)}
      <option value={NEW_SHELF}>New shelf…</option>
    </>
  );
}

/** "Single units (each)", "Box", "Case" and so on, in the server's order. */
export function UnitOptions({ units }: { units: readonly UnitOption[] }) {
  return <>{(units.length > 0 ? units : DEFAULT_UNITS).map((unit) => <option key={unit.value} value={unit.value}>{unit.value === 'each' ? 'Single units (each)' : capitalize(unit.label)}</option>)}</>;
}

/** Name and shelf. A shelf that does not exist yet is typed in under "New shelf". */
export function NameAndShelf({ form, onChange, errors, disabled, sections }: BaseProps & { sections: readonly MasterSection[] }) {
  return (
    <>
      <Field label="Name" error={errors.name}>
        {(aria) => <input {...aria} className={FIELD} value={form.name} maxLength={MAX_NAME} disabled={disabled} autoComplete="off" data-testid="field-name"
          onChange={(event) => onChange({ ...form, name: event.target.value })} />}
      </Field>
      <Field label="Shelf" error={errors.shelf ?? errors.location_code}>
        {(aria) => (
          <select {...aria} className={FIELD} value={form.shelf} disabled={disabled} data-testid="field-shelf" onChange={(event) => onChange({ ...form, shelf: event.target.value })}>
            <ShelfOptions sections={sections} />
          </select>
        )}
      </Field>
      {form.shelf === NEW_SHELF && (
        <Field label="New shelf name" error={errors.new_location_label}>
          {(aria) => <input {...aria} className={FIELD} value={form.newShelf} maxLength={MAX_SHELF_LABEL} disabled={disabled} autoComplete="off" data-testid="field-new-shelf"
            onChange={(event) => onChange({ ...form, newShelf: event.target.value })} />}
        </Field>
      )}
    </>
  );
}

/** A typed whole number for a physical count, in the unit the item is counted in. */
export function OnHandField({ form, onChange, errors, disabled, label, hint, unit }: BaseProps & { label: string; hint?: string; unit: string }) {
  return (
    <Field label={label} hint={hint} error={errors.onHand ?? errors.on_hand}>
      {(aria) => (
        <span className="flex items-center gap-2">
          <input {...aria} type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" className={`${FIELD} !w-32 tabular-nums`} value={form.onHand} disabled={disabled}
            data-testid="field-on-hand" onChange={(event) => onChange({ ...form, onHand: wholeText(event.target.value) })} />
          {unit !== '' && <span className={HINT}>{unit}</span>}
        </span>
      )}
    </Field>
  );
}

/** How the item is counted, how many single units are in one, and how many should be on the shelf. */
export function CountingFields({ form, onChange, errors, disabled, units }: BaseProps & { units: readonly UnitOption[] }) {
  const unit = form.countUnit;
  const word = unitOption(units, unit).label;
  return (
    <>
      <Field label="Count by" error={errors.countUnit ?? errors.count_unit}>
        {(aria) => (
          <select {...aria} className={FIELD} value={unit} disabled={disabled} data-testid="field-count-unit"
            onChange={(event) => onChange({ ...form, countUnit: event.target.value as CountUnit })}>
            <UnitOptions units={units} />
          </select>
        )}
      </Field>
      {unit !== 'each' && (
        <Field label={`Single units in one ${word}`} error={errors.perPackage ?? errors.units_per_package}>
          {(aria) => <input {...aria} type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" className={`${FIELD} !w-32 tabular-nums`} value={form.perPackage}
            disabled={disabled} data-testid="field-per-package" onChange={(event) => onChange({ ...form, perPackage: wholeText(event.target.value) })} />}
        </Field>
      )}
      <Field label="Expected on hand" hint={`In ${unitPlural(units, unit)}. What to order is worked out from this. Leave blank if there is no set amount.`} error={errors.threshold}>
        {(aria) => <input {...aria} type="text" inputMode="decimal" autoComplete="off" className={`${FIELD} !w-32 tabular-nums`} value={form.threshold}
          disabled={disabled} data-testid="field-threshold" onChange={(event) => onChange({ ...form, threshold: levelText(event.target.value) })} />}
      </Field>
    </>
  );
}
