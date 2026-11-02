import { useMemo, useState, type FormEvent } from 'react';
import { Sheet } from '../../ui';
import { BTN, BTN_PRIMARY, ConfirmStep, HINT, LiveNote, useWrite } from '../shared';
import { countMasterItem, updateMasterItem } from './api';
import { initialForm, planEdit, unitChoices, type ItemForm } from './editLogic';
import { CountingFields, NameAndShelf, OnHandField } from './ItemFields';
import { humanReason, unitWord } from './logic';
import type { MasterItem, MasterSection } from './types';
import { useUnsavedWork } from './useUnsavedWork';

interface Props {
  item: MasterItem | null;
  sections: readonly MasterSection[];
  onClose: () => void;
  /** The item as the server now holds it. */
  onSaved: (item: MasterItem) => void;
  onRemoved: (item: MasterItem) => void;
}

const sameForm = (a: ItemForm, b: ItemForm): boolean => JSON.stringify(a) === JSON.stringify(b);

function EditForm({ item, sections, onClose, onSaved, onRemoved }: Props & { item: MasterItem }) {
  const start = useMemo(() => initialForm(item), [item]);
  const [form, setForm] = useState<ItemForm>(start);
  const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
  const [confirmRemove, setConfirmRemove] = useState(false);
  const save = useWrite();
  const remove = useWrite();
  useUnsavedWork(!sameForm(form, start));

  const errors = { ...save.fields, ...localErrors };
  const busy = save.busy || remove.busy;
  const problems = [...item.review_reasons.map(humanReason), ...(item.config_problem ? [humanReason(item.config_problem)] : [])];
  const word = unitWord(item, 2);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const plan = planEdit(item, form);
    setLocalErrors(plan.errors);
    if (Object.keys(plan.errors).length > 0) return;
    if (plan.nothingChanged) { onClose(); return; }
    void save.run(JSON.stringify({ id: item.id, changes: plan.changes, count: plan.count }), async (key) => {
      let latest = item;
      if (Object.keys(plan.changes).length > 0) latest = await updateMasterItem(item.id, plan.changes, `${key}:fields`);
      if (plan.count !== null) latest = await countMasterItem(item.id, plan.count, `${key}:count`);
      return latest;
    }, { onDone: (latest) => { onSaved(latest); onClose(); } });
  };

  const doRemove = () => {
    void remove.run(`remove:${item.id}`, (key) => updateMasterItem(item.id, { active: false }, key), { onDone: () => { onRemoved(item); onClose(); } });
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-4" data-testid="edit-item-form" aria-label={`Edit ${item.name}`}>
      {problems.length > 0 && (
        <p className="hub-callout hub-callout--warning p-3 text-sm" data-testid="edit-item-problems">Check this item: {problems.join('. ')}.</p>
      )}
      <NameAndShelf form={form} onChange={setForm} errors={errors} disabled={busy} sections={sections} />
      <OnHandField form={form} onChange={setForm} errors={errors} disabled={busy} label="On hand" unit={word}
        hint="Saving a new number counts as a physical count and clears the red number." />

      <details className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3" data-testid="edit-more">
        <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">More</summary>
        <div className="space-y-4 pb-3">
          <CountingFields form={form} onChange={setForm} errors={errors} disabled={busy} units={unitChoices(item)} />
          {!confirmRemove && (
            <button type="button" className={`${BTN} hub-btn--tertiary !text-hub-danger`} disabled={busy} onClick={() => setConfirmRemove(true)}>Remove from inventory</button>
          )}
          {confirmRemove && (
            <ConfirmStep title={`Remove ${item.name}?`} confirmLabel="Remove from inventory" busy={remove.busy} error={remove.error} retry={remove.retry}
              body={<p>It leaves the shelf list and the count. You can put it back later from Add item.</p>} onConfirm={doRemove} onCancel={() => setConfirmRemove(false)} />
          )}
        </div>
      </details>

      <LiveNote tone="warning">{save.error}</LiveNote>
      <div className="flex flex-wrap items-center gap-2 border-t border-hub-border pt-3">
        <button type="submit" className={BTN_PRIMARY} disabled={busy} aria-busy={save.busy} data-testid="edit-item-save">
          {save.busy ? 'Saving…' : save.retry ? 'Try again' : 'Save'}
        </button>
        <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={busy} onClick={onClose}>Cancel</button>
        <p className={`${HINT} basis-full`}>Only what you changed is saved.</p>
      </div>
    </form>
  );
}

/** Bottom sheet on phones, side sheet on wider screens. The sheet traps focus and Escape closes it. */
export default function EditItemSheet(props: Props) {
  const { item, onClose } = props;
  return (
    <Sheet open={item !== null} onClose={onClose} title={item ? `Edit ${item.name}` : 'Edit item'} testId="edit-item-sheet"
      description={item?.location_label ? `Shelf ${item.location_label}` : undefined}>
      {item && <EditForm key={item.id} {...props} item={item} />}
    </Sheet>
  );
}
