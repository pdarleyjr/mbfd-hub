import { useState, type FormEvent } from 'react';
import { updateSupplyItem } from '../../../utils/emsApi';
import { parseWhole, reasonError } from './logic';
import { BTN, BTN_PRIMARY, ErrorText, FIELD, Field, HINT, LiveNote, useWrite } from './shared';
import type { StorageLocation, SupplyItem } from './types';

const PACKAGE_UNITS = ['each', 'box', 'case', 'pack', 'bag'];
const text = (value: number | null): string => (value === null ? '' : String(value));

interface Props {
  item: SupplyItem;
  locations: StorageLocation[];
  onSaved: (item: SupplyItem) => void;
  onCancel: () => void;
}

/** Threshold, packaging and location for one item. Shown only when the API says this person can configure. Every change needs a reason. */
export default function ItemSettings({ item, locations, onSaved, onCancel }: Props) {
  const [threshold, setThreshold] = useState(text(item.threshold_qty_base));
  const [perPackage, setPerPackage] = useState(text(item.units_per_package));
  const [perCase, setPerCase] = useState(text(item.packages_per_case));
  const [packageUnit, setPackageUnit] = useState(item.package_unit ?? '');
  const [orderQty, setOrderQty] = useState(text(item.order_qty_packages));
  const [location, setLocation] = useState(item.location_code ?? '');
  const [resolve, setResolve] = useState(false);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const write = useWrite();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const found: Record<string, string> = {};
    const changes: Record<string, unknown> = {};
    const numeric = (name: string, value: string, current: number | null, label: string, min: number) => {
      const parsed = parseWhole(value, 0, 10_000_000);
      if (Number.isNaN(parsed) || (parsed !== null && parsed < min)) { found[name] = `${label} must be a whole number${min > 0 ? ` of at least ${min}` : ''}, or blank.`; return; }
      if (parsed !== current) changes[name] = parsed;
    };
    numeric('threshold_qty_base', threshold, item.threshold_qty_base, 'Threshold', 0);
    numeric('units_per_package', perPackage, item.units_per_package, 'Units per package', 1);
    numeric('packages_per_case', perCase, item.packages_per_case, 'Packages per case', 1);
    numeric('order_qty_packages', orderQty, item.order_qty_packages, 'Order amount', 1);
    if ((packageUnit || null) !== item.package_unit) changes.package_unit = packageUnit || null;
    if ((location || null) !== item.location_code) changes.location_code = location || null;
    if (resolve) changes.resolve_review = true;
    if (Object.keys(changes).length === 0 && Object.keys(found).length === 0) found.form = 'Change at least one setting.';
    const why = reasonError(reason, 'for this change so the audit trail makes sense');
    if (why) found.reason = why;
    setErrors(found);
    if (Object.keys(found).length > 0) return;
    const body = { ...changes, reason: reason.trim() };
    void write.run(JSON.stringify(body), (key) => updateSupplyItem(item.id, body, key), { onDone: onSaved });
  };

  const number = (name: string, label: string, value: string, set: (value: string) => void, hint?: string) => (
    <Field label={label} hint={hint} error={errors[name] ?? write.fields[name]}>
      {(aria) => <input {...aria} type="text" inputMode="numeric" className={FIELD} value={value} disabled={write.busy} onChange={(event) => set(event.target.value.replace(/[^\d]/g, '').slice(0, 8))} />}
    </Field>
  );

  return (
    <form onSubmit={submit} noValidate aria-label={`Settings for ${item.name}`} data-testid="ems-item-settings" className="space-y-3 rounded-hub-control border border-hub-border bg-hub-n-25 p-3 sm:p-4">
      <p className="text-sm text-hub-ink">Change the reorder threshold, how the item is packaged, or where it is kept. Counts and reorder advice use these numbers.</p>
      <div className="grid gap-3 sm:grid-cols-2">
        {number('threshold_qty_base', 'Reorder threshold (single units)', threshold, setThreshold, item.threshold_source_text ? `The order sheet says ${item.threshold_source_text}.` : undefined)}
        <Field label="Package type" error={errors.package_unit ?? write.fields.package_unit}>
          {(aria) => (
            <select {...aria} className={FIELD} value={packageUnit} disabled={write.busy} onChange={(event) => setPackageUnit(event.target.value)}>
              <option value="">Not stated</option>
              {PACKAGE_UNITS.map((unit) => <option key={unit} value={unit}>{unit.charAt(0).toUpperCase() + unit.slice(1)}</option>)}
            </select>
          )}
        </Field>
        {number('units_per_package', 'Single units in one package', perPackage, setPerPackage)}
        {number('packages_per_case', 'Packages in one case', perCase, setPerCase)}
        {number('order_qty_packages', 'Usual order amount (packages)', orderQty, setOrderQty, item.order_amount_text ? `The order sheet says ${item.order_amount_text}.` : undefined)}
        <Field label="Storage location" error={errors.location_code ?? write.fields.location_code}>
          {(aria) => (
            <select {...aria} className={FIELD} value={location} disabled={write.busy} onChange={(event) => setLocation(event.target.value)}>
              <option value="">No location</option>
              {locations.filter((entry) => entry.active).map((entry) => <option key={entry.code} value={entry.code}>{entry.code}{entry.label && entry.label !== entry.code ? ` (${entry.label})` : ''}</option>)}
            </select>
          )}
        </Field>
      </div>
      {item.needs_review && (
        <label className="flex min-h-11 items-start gap-3 text-sm text-hub-ink">
          <input type="checkbox" className="mt-0.5 h-5 w-5 flex-none" checked={resolve} disabled={write.busy} onChange={(event) => setResolve(event.target.checked)} />
          <span>These numbers are now right. Clear the "needs review" flag.<span className={`${HINT} block`}>Flagged because: {item.review_reasons.join(', ') || 'unclear source text'}.</span></span>
        </label>
      )}
      <Field label="Why you are changing this" required error={errors.reason ?? write.fields.reason}>
        {(aria) => <input {...aria} className={FIELD} value={reason} maxLength={500} disabled={write.busy} onChange={(event) => setReason(event.target.value)} />}
      </Field>
      <ErrorText>{errors.form}</ErrorText>
      <LiveNote tone="warning">{write.error}</LiveNote>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy}>{write.busy ? 'Saving…' : write.retry ? 'Retry: Save settings' : 'Save settings'}</button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={write.busy} onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}
