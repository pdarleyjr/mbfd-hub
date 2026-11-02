import { useState, type FormEvent } from 'react';
import { Sheet } from '../../ui';
import { BTN, BTN_PRIMARY, BTN_SECONDARY, FIELD, Field, HINT, LiveNote, useWrite } from '../shared';
import { createMasterItem, updateMasterItem } from './api';
import { ALL_UNITS, EMPTY_NEW_ITEM, planNewItem, type ItemForm } from './editLogic';
import { CountingFields, NameAndShelf, OnHandField } from './ItemFields';
import type { InactiveItem, MasterItem, MasterSection } from './types';
import { useUnsavedWork } from './useUnsavedWork';

interface Props {
  open: boolean;
  sections: readonly MasterSection[];
  inactive: readonly InactiveItem[];
  defaultShelf: string | null;
  onClose: () => void;
  onAdded: (item: MasterItem) => void;
}

/** Bring back an item that was removed, instead of typing it in again. */
function PutBack({ inactive, onAdded, onClose }: Pick<Props, 'inactive' | 'onAdded' | 'onClose'>) {
  const [choice, setChoice] = useState('');
  const write = useWrite();
  if (inactive.length === 0) return null;
  const restore = () => {
    if (choice === '') return;
    void write.run(`restore:${choice}`, (key) => updateMasterItem(Number(choice), { active: true }, key), { onDone: (item) => { onAdded(item); onClose(); } });
  };
  return (
    <details className="rounded-hub-control border border-hub-border bg-hub-n-25 px-3" data-testid="put-back">
      <summary className="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-hub-ink">Put back an item that was removed</summary>
      <div className="space-y-3 pb-3">
        <Field label="Removed items">
          {(aria) => (
            <select {...aria} className={FIELD} value={choice} disabled={write.busy} data-testid="put-back-select" onChange={(event) => setChoice(event.target.value)}>
              <option value="">Choose an item</option>
              {inactive.map((entry) => <option key={entry.id} value={entry.id}>{entry.name}{entry.location_code ? ` (${entry.location_code})` : ''}</option>)}
            </select>
          )}
        </Field>
        <LiveNote tone="warning">{write.error}</LiveNote>
        <button type="button" className={BTN_SECONDARY} disabled={choice === '' || write.busy} aria-busy={write.busy} onClick={restore}>
          {write.busy ? 'Putting back…' : 'Put back'}
        </button>
      </div>
    </details>
  );
}

function AddForm({ sections, inactive, defaultShelf, onClose, onAdded }: Omit<Props, 'open'>) {
  const [form, setForm] = useState<ItemForm>({ ...EMPTY_NEW_ITEM, shelf: defaultShelf ?? '' });
  const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
  const write = useWrite();
  useUnsavedWork(form.name.trim() !== '' || form.onHand !== '' || form.threshold !== '');
  const errors = { ...write.fields, ...localErrors };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const plan = planNewItem(form);
    setLocalErrors(plan.errors);
    if (!plan.input) return;
    const input = plan.input;
    void write.run(JSON.stringify(input), (key) => createMasterItem(input, key), { onDone: (item) => { onAdded(item); onClose(); } });
  };

  return (
    <div className="space-y-4">
      <PutBack inactive={inactive} onAdded={onAdded} onClose={onClose} />
      <form onSubmit={submit} noValidate className="space-y-4" data-testid="add-item-form" aria-label="Add item">
        <NameAndShelf form={form} onChange={setForm} errors={errors} disabled={write.busy} sections={sections} />
        <CountingFields form={form} onChange={setForm} errors={errors} disabled={write.busy} units={ALL_UNITS} />
        <OnHandField form={form} onChange={setForm} errors={errors} disabled={write.busy} label="On hand (optional)" unit=""
          hint="Fill this in if you have just counted it. Leave it blank to count it later." />
        <LiveNote tone="warning">{write.error}</LiveNote>
        <div className="flex flex-wrap items-center gap-2 border-t border-hub-border pt-3">
          <button type="submit" className={BTN_PRIMARY} disabled={write.busy} aria-busy={write.busy} data-testid="add-item-save">
            {write.busy ? 'Adding…' : write.retry ? 'Try again' : 'Add item'}
          </button>
          <button type="button" className={`${BTN} hub-btn--tertiary`} disabled={write.busy} onClick={onClose}>Cancel</button>
          <p className={`${HINT} basis-full`}>The item appears on its shelf as soon as it is added.</p>
        </div>
      </form>
    </div>
  );
}

export default function AddItemSheet({ open, ...rest }: Props) {
  return (
    <Sheet open={open} onClose={rest.onClose} title="Add item" testId="add-item-sheet">
      {open && <AddForm {...rest} />}
    </Sheet>
  );
}
